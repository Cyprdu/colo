<?php
// api/ledger_lib.php : journal comptable unique fin_ledger (inclus par finance_lib.php), en AJOUT SEULEMENT.
//
// Une ligne = un mouvement d'argent vu par l'organisme, montant signé en centimes :
//   paiement +brut payé par la famille | commission −commission moncamp.fr (+ part rendue lors d'un remboursement)
//   remboursement −brut rendu à la famille | ajustement (points payés avec le solde...) | versement −virement vers sa banque
//   frais : frais Stripe réels, à la charge de moncamp.fr (exclus des totaux de l'organisme, servent à la marge).
// Pour vous = paiement + commission + remboursement + ajustement ; en attente = pour vous − versé (négatif : à compenser).
// Idempotence : (type, reference) unique ; la référence part de l'objet Stripe (pi_, po_, tr_...) : un webhook rejoué n'écrit rien.
// Les reversements ne sont PAS des lignes : le remboursement a déjà réduit « pour vous ».

const FIN_LEDGER_TYPES = ['paiement', 'commission', 'remboursement', 'ajustement', 'versement', 'frais'];

function fin_ledger_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_ledger (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        type VARCHAR(14) NOT NULL, organisateur_id INT NOT NULL, objet_type VARCHAR(16) NOT NULL DEFAULT '', objet_id VARCHAR(40) NOT NULL DEFAULT '',
        montant_cents INT NOT NULL, devise CHAR(3) NOT NULL DEFAULT 'EUR', reference VARCHAR(120) NOT NULL, mode VARCHAR(12) NULL,
        libelle VARCHAR(120) NULL, acteur VARCHAR(40) NOT NULL DEFAULT 'systeme', ip VARCHAR(45) NULL,
        UNIQUE KEY uq_ref (type, reference), KEY idx_orga (organisateur_id, created_at), KEY idx_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['UPDATE' => 'upd', 'DELETE' => 'del'] as $op => $s) { // même protection que fin_gains_journal (droit TRIGGER parfois absent)
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?");
        $st->execute(['fin_ledger_no_' . $s]);
        if (!(int)$st->fetchColumn()) {
            try { $pdo->exec("CREATE TRIGGER fin_ledger_no_$s BEFORE $op ON fin_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal en ajout seulement'"); }
            catch (Throwable $e) { error_log('fin_ledger trigger: ' . $e->getMessage()); }
        }
    }
}

/** Écriture unique au journal. Retourne false si la ligne existait déjà (rejeu) ou si le montant est nul. */
function fin_ledger_add(PDO $pdo, string $type, int $orgaId, int $cents, string $ref, string $objType = '', $objId = '', string $libelle = '', string $acteur = 'systeme', ?string $mode = null, ?string $date = null): bool {
    if ($cents === 0 || !$orgaId || !in_array($type, FIN_LEDGER_TYPES, true)) return false;
    $st = $pdo->prepare("INSERT IGNORE INTO fin_ledger (created_at, type, organisateur_id, objet_type, objet_id, montant_cents, reference, mode, libelle, acteur, ip)
                         VALUES (COALESCE(?, NOW()), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$date, $type, $orgaId, $objType, (string)$objId, $cents, mb_substr($ref, 0, 120), $mode, $libelle !== '' ? mb_substr($libelle, 0, 120) : null,
                  mb_substr($acteur, 0, 40), $_SERVER['REMOTE_ADDR'] ?? null]);
    return $st->rowCount() === 1;
}

/** Référence d'une ligne de paiement (même format en direct et au rattrapage) */
function fin_ledger_ref_pay(array $p): string { return 'pay:' . ($p['payment_intent'] ?: $p['session_id']) . ':' . $p['inscription_id']; }

/** Paiement encaissé : brut et commission d'une ligne fin_paiements */
function fin_ledger_paiement(PDO $pdo, array $p, ?string $date = null): void {
    $ref = fin_ledger_ref_pay($p); $obj = (int)$p['inscription_id'] < 0 ? 'equipe' : 'inscription';
    fin_ledger_add($pdo, 'paiement', (int)$p['organisateur_id'], (int)$p['brut_cents'], $ref, $obj, abs((int)$p['inscription_id']), 'Paiement de la famille', 'webhook', $p['mode'] ?? 'legacy', $date);
    fin_ledger_add($pdo, 'commission', (int)$p['organisateur_id'], -(int)$p['commission_cents'], $ref, $obj, abs((int)$p['inscription_id']), 'Commission moncamp.fr', 'webhook', $p['mode'] ?? 'legacy', $date);
}

/** Remboursement : $refAvant / $refApres = brut remboursé cumulé, $netAvant / $netApres = net retiré cumulé. La commission rendue = écart brut − net. */
function fin_ledger_remboursement(PDO $pdo, array $p, int $refAvant, int $refApres, int $netAvant, int $netApres, string $acteur = 'webhook', ?string $date = null): void {
    $db = $refApres - $refAvant; $dn = $netApres - $netAvant;
    if ($db <= 0) return;
    $ref = 'rem:' . ($p['payment_intent'] ?: $p['session_id']) . ':' . $p['inscription_id'] . ':' . $refApres;
    $obj = (int)$p['inscription_id'] < 0 ? 'equipe' : 'inscription'; $m = $p['mode'] ?? 'legacy';
    fin_ledger_add($pdo, 'remboursement', (int)$p['organisateur_id'], -$db, $ref, $obj, abs((int)$p['inscription_id']), 'Remboursement à la famille', $acteur, $m, $date);
    fin_ledger_add($pdo, 'commission', (int)$p['organisateur_id'], $db - $dn, $ref, $obj, abs((int)$p['inscription_id']), 'Commission rendue', $acteur, $m, $date);
}

/**
 * Rattrapage unique, en LECTURE SEULE des tables existantes : paiements, remboursements, frais, commissions hors carte, points payés avec le solde,
 * transferts déjà envoyés (versés automatiquement par Stripe à l'époque). Idempotent (mêmes références qu'en direct).
 */
function fin_ledger_backfill(PDO $pdo, bool $force = false): int {
    $flag = $pdo->query("SELECT COUNT(*) FROM fin_reglages WHERE cle = 'ledger_backfill_v1'")->fetchColumn();
    if ((int)$flag && !$force) return 0;
    $n0 = (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger")->fetchColumn();
    foreach ($pdo->query("SELECT * FROM fin_paiements ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        fin_ledger_paiement($pdo, $p, $p['created_at']);
        if ((int)$p['rembourse_cents'] > 0) fin_ledger_remboursement($pdo, $p, 0, (int)$p['rembourse_cents'], 0, (int)$p['net_rembourse_cents'], 'rattrapage', $p['created_at']);
        if ($p['frais_stripe_cents'] !== null) fin_ledger_add($pdo, 'frais', (int)$p['organisateur_id'], -(int)$p['frais_stripe_cents'], 'frais:' . $p['id'], 'paiement', $p['id'], 'Frais Stripe', 'rattrapage', $p['mode'] ?? 'legacy', $p['frais_maj'] ?? $p['created_at']);
        if ((int)$p['frais_litige_cents'] > 0) fin_ledger_add($pdo, 'frais', (int)$p['organisateur_id'], -(int)$p['frais_litige_cents'], 'litige:' . $p['id'], 'paiement', $p['id'], 'Frais de litige Stripe', 'rattrapage', null, $p['created_at']);
    }
    foreach ($pdo->query("SELECT * FROM fin_commissions_hors WHERE commission_cents > 0")->fetchAll(PDO::FETCH_ASSOC) as $h)
        fin_ledger_add($pdo, 'commission', (int)$h['organisateur_id'], -(int)$h['commission_cents'], 'hors:' . $h['inscription_id'], 'inscription', $h['inscription_id'], 'Commission paiement hors carte', 'rattrapage', null, $h['created_at']);
    foreach ($pdo->query("SELECT * FROM fin_transferts WHERE statut = 'envoye' AND type <> 'payout'")->fetchAll(PDO::FETCH_ASSOC) as $t)
        fin_ledger_add($pdo, 'versement', (int)$t['organisateur_id'], -(int)$t['montant_cents'], $t['stripe_transfer_id'] ?: 'tr:' . $t['id'], 'transfert', $t['id'], 'Versement', 'rattrapage', 'legacy', $t['created_at']);
    try {
        foreach ($pdo->query("SELECT id, organisateur_id, montant_cents, created_at FROM fin_gains_journal WHERE statut = 'ok' AND montant_cents > 0")->fetchAll(PDO::FETCH_ASSOC) as $g)
            fin_ledger_add($pdo, 'ajustement', (int)$g['organisateur_id'], -(int)$g['montant_cents'], 'gains:' . $g['id'], 'points', $g['id'], 'Points payés avec le solde', 'rattrapage', null, $g['created_at']);
    } catch (Throwable $e) { /* journal des gains absent : rien à reprendre */ }
    $pdo->exec("INSERT IGNORE INTO fin_reglages (cle, valeur, par) VALUES ('ledger_backfill_v1', '1', 'migration')");
    return (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger")->fetchColumn() - $n0;
}

/** Totaux de l'organisme (centimes) : la cascade « où va l'argent ». Jamais filtrée par les filtres du tableau de bord. */
function fin_ledger_totaux(PDO $pdo, int $orgaId): array {
    $st = $pdo->prepare("SELECT type, COALESCE(SUM(montant_cents), 0) FROM fin_ledger WHERE organisateur_id = ? GROUP BY type");
    $st->execute([$orgaId]);
    $s = $st->fetchAll(PDO::FETCH_KEY_PAIR) + array_fill_keys(FIN_LEDGER_TYPES, 0);
    $pour = (int)$s['paiement'] + (int)$s['commission'] + (int)$s['remboursement'] + (int)$s['ajustement'];
    $verse = -(int)$s['versement'];
    return ['encaisse' => (int)$s['paiement'], 'commission' => -(int)$s['commission'], 'rembourse' => -(int)$s['remboursement'], 'ajustement' => (int)$s['ajustement'],
            'pour_vous' => $pour, 'verse' => $verse, 'en_attente' => $pour - $verse];
}
