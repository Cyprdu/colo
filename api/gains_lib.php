<?php
// api/gains_lib.php : paiements « avec mes gains » (solde à recevoir de l'organisme -> points marketing).
// Garanties :
//  - une transaction SQL qui verrouille la ligne de l'organisme (SELECT ... FOR UPDATE) : pas de double dépense
//  - jeton d'idempotence unique : un rejeu de la même requête ne débite jamais deux fois et renvoie le résultat d'origine
//  - débit refusé si solde insuffisant (le refus est lui aussi journalisé)
//  - journal fin_gains_journal en AJOUT SEULEMENT (déclencheurs SQL interdisant UPDATE/DELETE) et chaîné par empreinte SHA-256
//    (qui, quand, IP, montant, solde avant/après, objet, statut) : toute altération est détectable par gains_verify_chain()
require_once __DIR__ . '/marketing_lib.php';
require_once __DIR__ . '/finance_lib.php';

function gains_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_gains_journal (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL,
        user_id INT NOT NULL, organisateur_id INT NOT NULL, ip VARCHAR(45) NOT NULL,
        action VARCHAR(24) NOT NULL, objet VARCHAR(60) NOT NULL, libelle VARCHAR(120) NOT NULL, points INT NOT NULL,
        montant_cents INT NOT NULL, solde_avant_cents INT NOT NULL, solde_apres_cents INT NOT NULL,
        statut VARCHAR(16) NOT NULL, idem_key CHAR(32) NOT NULL, ref_points VARCHAR(80) NULL,
        prev_hash CHAR(64) NOT NULL, hash CHAR(64) NOT NULL,
        UNIQUE KEY uq_idem (idem_key), KEY idx_orga (organisateur_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Ajout seulement : aucune ligne ne peut être modifiée ni supprimée par l'application (ni par une faille SQL de l'application).
    foreach (['UPDATE' => 'upd', 'DELETE' => 'del'] as $op => $s) {
        $name = 'fin_gains_no_' . $s;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?");
        $st->execute([$name]);
        if (!(int)$st->fetchColumn()) {
            try { $pdo->exec("CREATE TRIGGER $name BEFORE $op ON fin_gains_journal FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal en ajout seulement'"); }
            catch (Throwable $e) { error_log('gains_ensure trigger: ' . $e->getMessage()); } // droit TRIGGER absent : l'empreinte chaînée reste la preuve
        }
    }
    $done = true;
}

function gains_hash(string $prev, array $r): string {
    return hash('sha256', $prev . '|' . implode('|', [$r['created_at'], $r['user_id'], $r['organisateur_id'], $r['ip'], $r['action'], $r['objet'], $r['points'], $r['montant_cents'], $r['solde_avant_cents'], $r['solde_apres_cents'], $r['statut'], $r['idem_key']]));
}

/** Ajoute une ligne au journal (dans la transaction appelante, qui détient déjà le verrou de l'organisme). */
function gains_append(PDO $pdo, array $r): int {
    $prev = (string)$pdo->query("SELECT hash FROM fin_gains_journal ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn(); // ponytail: sérialise les écritures du journal (chaîne unique) ; opérations rares
    $prev = $prev !== '' ? $prev : str_repeat('0', 64);
    $r['created_at'] = date('Y-m-d H:i:s');
    $h = gains_hash($prev, $r);
    $pdo->prepare("INSERT INTO fin_gains_journal (created_at, user_id, organisateur_id, ip, action, objet, libelle, points, montant_cents, solde_avant_cents, solde_apres_cents, statut, idem_key, ref_points, prev_hash, hash)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$r['created_at'], $r['user_id'], $r['organisateur_id'], $r['ip'], $r['action'], $r['objet'], $r['libelle'], $r['points'], $r['montant_cents'], $r['solde_avant_cents'], $r['solde_apres_cents'], $r['statut'], $r['idem_key'], $r['ref_points'] ?? null, $prev, $h]);
    return (int)$pdo->lastInsertId();
}

/**
 * Achète un pack de points avec les gains. $idem : jeton d'idempotence (32 hex) généré par le navigateur pour CETTE confirmation.
 * Retourne ['ok' => bool, 'code' => 'ok'|'solde'|..., 'replay' => bool, 'montant_cents', 'solde_avant_cents', 'solde_apres_cents', 'points', 'libelle', 'journal_id']
 * Lève une Exception (message affichable) pour toute requête invalide.
 */
function gains_buy_points(PDO $pdo, int $userId, int $orgaId, string $packKey, string $idem, string $ip): array {
    $packs = mk_packs();
    if (!isset($packs[$packKey])) throw new Exception('Pack inconnu.');
    if (!preg_match('/^[a-f0-9]{32}$/', $idem)) throw new Exception('Requête invalide.');
    $pack = $packs[$packKey];
    gains_ensure($pdo); mk_ensure($pdo); fin_ensure($pdo);
    $objet = 'pack:' . $packKey;
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, portefeuille FROM organisateurs WHERE id = ? AND user_id = ? FOR UPDATE");
        $st->execute([$orgaId, $userId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) throw new Exception('Organisme introuvable.');

        // Rejeu : même jeton déjà traité -> on renvoie le résultat d'origine, sans rien débiter
        $q = $pdo->prepare("SELECT * FROM fin_gains_journal WHERE idem_key = ?");
        $q->execute([$idem]);
        if ($prior = $q->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$prior['user_id'] !== $userId || (int)$prior['organisateur_id'] !== $orgaId || $prior['objet'] !== $objet) throw new Exception('Requête invalide.');
            $pdo->rollBack();
            return ['ok' => $prior['statut'] === 'ok', 'code' => $prior['statut'] === 'ok' ? 'ok' : 'solde', 'replay' => true, 'montant_cents' => (int)$prior['montant_cents'], 'solde_avant_cents' => (int)$prior['solde_avant_cents'],
                    'solde_apres_cents' => (int)$prior['solde_apres_cents'], 'points' => (int)$prior['points'], 'libelle' => $prior['libelle'], 'journal_id' => (int)$prior['id']];
        }

        $avant = max(0, (int)round((float)$o['portefeuille'] * 100));
        $row = ['user_id' => $userId, 'organisateur_id' => $orgaId, 'ip' => $ip, 'action' => 'achat_points', 'objet' => $objet, 'libelle' => $pack['name'] . ' (' . $pack['points'] . ' pts)',
                'points' => (int)$pack['points'], 'montant_cents' => (int)$pack['cents'], 'solde_avant_cents' => $avant, 'solde_apres_cents' => $avant, 'statut' => 'refuse_solde', 'idem_key' => $idem];
        if ($avant < $pack['cents']) { // solde insuffisant : rien n'est débité, le refus est tracé
            $id = gains_append($pdo, $row);
            $pdo->commit();
            return ['ok' => false, 'code' => 'solde', 'replay' => false, 'montant_cents' => $row['montant_cents'], 'solde_avant_cents' => $avant, 'solde_apres_cents' => $avant, 'points' => $row['points'], 'libelle' => $row['libelle'], 'journal_id' => $id];
        }

        $apres = $avant - (int)$pack['cents'];
        $ref = 'wallet-' . $idem; // unique : le crédit de points ne peut non plus être rejoué
        $pdo->prepare("UPDATE organisateurs SET portefeuille = ROUND(portefeuille - ?, 2) WHERE id = ?")->execute([number_format($pack['cents'] / 100, 2, '.', ''), $orgaId]);
        if (!mk_credit_points($pdo, $orgaId, (int)$pack['points'], 'portefeuille', (int)$pack['cents'], $ref)) throw new Exception('Opération déjà enregistrée.');
        $row['statut'] = 'ok'; $row['solde_apres_cents'] = $apres; $row['ref_points'] = $ref;
        $id = gains_append($pdo, $row);
        fin_ledger_add($pdo, 'ajustement', $orgaId, -(int)$pack['cents'], 'gains:' . $id, 'points', $id, 'Points payés avec le solde', 'user:' . $userId);
        fin_audit($pdo, 'points_sur_ca', 'organisateur', $orgaId, ['pack' => $packKey, 'points' => $pack['points'], 'cents' => $pack['cents'], 'ref' => $ref, 'journal' => $id, 'avant' => $avant, 'apres' => $apres], 'user:' . $userId);
        $pdo->commit();
        return ['ok' => true, 'code' => 'ok', 'replay' => false, 'montant_cents' => $row['montant_cents'], 'solde_avant_cents' => $avant, 'solde_apres_cents' => $apres, 'points' => $row['points'], 'libelle' => $row['libelle'], 'journal_id' => $id];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Vérifie la chaîne d'empreintes du journal : ['ok' => bool, 'n' => lignes, 'rupture' => id|null] */
function gains_verify_chain(PDO $pdo): array {
    gains_ensure($pdo);
    $prev = str_repeat('0', 64); $n = 0;
    foreach ($pdo->query("SELECT * FROM fin_gains_journal ORDER BY id") as $r) {
        $n++;
        if ($r['prev_hash'] !== $prev || !hash_equals(gains_hash($prev, $r), $r['hash'])) return ['ok' => false, 'n' => $n, 'rupture' => (int)$r['id']];
        $prev = $r['hash'];
    }
    return ['ok' => true, 'n' => $n, 'rupture' => null];
}

/** Dernières lignes du journal (un organisme, ou tous si $orgaId = 0) */
function gains_history(PDO $pdo, int $orgaId = 0, int $limit = 10): array {
    try {
        gains_ensure($pdo);
        $sql = "SELECT j.*, o.nom AS orga_nom FROM fin_gains_journal j LEFT JOIN organisateurs o ON o.id = j.organisateur_id" . ($orgaId ? " WHERE j.organisateur_id = ?" : "") . " ORDER BY j.id DESC LIMIT " . max(1, min(500, $limit));
        $st = $pdo->prepare($sql); $st->execute($orgaId ? [$orgaId] : []);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Reçu par e-mail (après validation de la transaction ; un échec d'envoi n'annule rien) */
function gains_send_receipt(PDO $pdo, int $userId, array $r): void {
    try {
        if (empty($r['ok']) || !empty($r['replay']) || !function_exists('sendMailGmail')) return;
        $st = $pdo->prepare("SELECT email, prenom FROM users WHERE id = ?"); $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || empty($u['email'])) return;
        $e = function (int $c): string { return number_format($c / 100, 2, ',', ' ') . ' €'; };
        $body = '<div style="font-family:Arial,sans-serif;color:#26343b;max-width:520px">'
            . '<p>Bonjour ' . htmlspecialchars((string)$u['prenom']) . ',</p><p>Votre paiement avec vos gains est confirmé.</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
            . '<tr><td style="padding:6px 0;color:#6b7780">Achat</td><td style="text-align:right"><b>' . htmlspecialchars($r['libelle']) . '</b></td></tr>'
            . '<tr><td style="padding:6px 0;color:#6b7780">Montant débité</td><td style="text-align:right"><b>' . $e($r['montant_cents']) . '</b></td></tr>'
            . '<tr><td style="padding:6px 0;color:#6b7780">Solde avant</td><td style="text-align:right">' . $e($r['solde_avant_cents']) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#6b7780">Solde après</td><td style="text-align:right">' . $e($r['solde_apres_cents']) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#6b7780">Référence</td><td style="text-align:right">G-' . (int)$r['journal_id'] . '</td></tr></table>'
            . '<p style="color:#6b7780;font-size:12px">Si vous n\'êtes pas à l\'origine de cet achat, contactez-nous sans attendre : moncamp.fr/contact</p></div>';
        sendMailGmail($u['email'], (string)$u['prenom'], 'Reçu moncamp.fr : ' . $r['libelle'], $body);
    } catch (Throwable $e) { error_log('gains_send_receipt: ' . $e->getMessage()); }
}
