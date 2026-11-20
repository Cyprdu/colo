<?php
// api/factures_lib.php : factures de commission mensuelles de moncamp.fr aux organismes, tirées de fin_ledger.
// Une facture (ou un avoir si les commissions rendues dépassent les commissions perçues) par organisme et par mois.
// Numérotation continue MC-000001 (factures et avoirs dans la même série), attribuée sous verrou.
// Table en ajout seulement : aucune suppression, seule la date d'envoi peut changer (déclencheurs). Conservation 10 ans (jamais purgée).
// Annulation : avoir intégral lié à la facture, puis nouvelle facture possible pour le même mois.
require_once __DIR__ . '/societe.php';
require_once __DIR__ . '/pdf_lib.php';

function fac_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_factures (
        id INT AUTO_INCREMENT PRIMARY KEY, seq INT NOT NULL, numero VARCHAR(20) NOT NULL, type VARCHAR(7) NOT NULL, cle VARCHAR(60) NOT NULL, token CHAR(32) NOT NULL,
        organisateur_id INT NOT NULL, mois CHAR(7) NOT NULL, origine_id INT NULL, emise_le DATE NOT NULL,
        ht_cents INT NOT NULL, tva_cents INT NOT NULL, ttc_cents INT NOT NULL, dette_cents INT NOT NULL DEFAULT 0, tva_taux DECIMAL(4,2) NOT NULL DEFAULT 0,
        client TEXT NOT NULL, lignes TEXT NOT NULL, pdf MEDIUMBLOB NOT NULL, sha256 CHAR(64) NOT NULL, envoye_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_seq (seq), UNIQUE KEY uq_num (numero), UNIQUE KEY uq_cle (cle), UNIQUE KEY uq_tok (token), KEY idx_orga (organisateur_id, mois), KEY idx_mois (mois)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $trg = [
        'fin_factures_no_del' => "BEFORE DELETE ON fin_factures FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Facture inalterable'",
        'fin_factures_no_upd' => "BEFORE UPDATE ON fin_factures FOR EACH ROW IF NOT (NEW.seq <=> OLD.seq AND NEW.numero <=> OLD.numero AND NEW.type <=> OLD.type AND NEW.cle <=> OLD.cle
            AND NEW.organisateur_id <=> OLD.organisateur_id AND NEW.mois <=> OLD.mois AND NEW.origine_id <=> OLD.origine_id AND NEW.emise_le <=> OLD.emise_le AND NEW.ht_cents <=> OLD.ht_cents
            AND NEW.tva_cents <=> OLD.tva_cents AND NEW.ttc_cents <=> OLD.ttc_cents AND NEW.dette_cents <=> OLD.dette_cents AND NEW.client <=> OLD.client AND NEW.lignes <=> OLD.lignes
            AND NEW.pdf <=> OLD.pdf AND NEW.sha256 <=> OLD.sha256 AND NEW.token <=> OLD.token) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Facture inalterable'; END IF",
    ];
    foreach ($trg as $n => $sql) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?");
        $st->execute([$n]);
        if (!(int)$st->fetchColumn()) { try { $pdo->exec("CREATE TRIGGER $n $sql"); } catch (Throwable $e) { error_log('fin_factures trigger: ' . $e->getMessage()); } }
    }
    try { $pdo->exec("ALTER TABLE organisateurs ADD COLUMN IF NOT EXISTS fact_adresse VARCHAR(255) NULL, ADD COLUMN IF NOT EXISTS fact_siret VARCHAR(20) NULL"); }
    catch (Throwable $e) { error_log('fac_ensure organisateurs: ' . $e->getMessage()); }
}

function fac_tva_taux(): float { return max(0.0, (float)(SOCIETE['tva_taux'] ?? 0)); }
function fac_eur(int $c): string { return number_format($c / 100, 2, ',', ' ') . ' €'; }
function fac_mois_lib(string $mois): string {
    $m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $m[(int)substr($mois, 5, 2) - 1] . ' ' . substr($mois, 0, 4);
}

/** Lignes du mois lues dans le journal : [lignes facturées (TTC, positif = dû à moncamp.fr), total TTC, dette compensée] */
function fac_calcul(PDO $pdo, int $orga, string $mois): array {
    $d = $mois . '-01';
    $st = $pdo->prepare("SELECT type, reference LIKE 'hors:%' hors, montant_cents < 0 neg, COUNT(*) n, SUM(montant_cents) s FROM fin_ledger
                         WHERE organisateur_id = ? AND created_at >= ? AND created_at < (? + INTERVAL 1 MONTH) AND (type = 'commission' OR (type = 'dette' AND montant_cents < 0))
                         GROUP BY type, hors, neg");
    $st->execute([$orga, $d, $d]);
    $lib = ['0:1' => 'Commission moncamp.fr sur les paiements en ligne', '0:0' => 'Commission rendue sur remboursements', '1:1' => 'Commission sur les paiements hors carte', '1:0' => 'Commission hors carte annulée'];
    $lignes = []; $dette = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['type'] === 'dette') { $dette += -(int)$r['s']; continue; }
        $lignes[] = ['lib' => $lib[(int)$r['hors'] . ':' . (int)$r['neg']], 'n' => (int)$r['n'], 'ttc' => -(int)$r['s']];
    }
    usort($lignes, fn($a, $b) => $b['ttc'] <=> $a['ttc']);
    return [$lignes, array_sum(array_column($lignes, 'ttc')), $dette];
}

function fac_client(PDO $pdo, int $orga): array {
    $st = $pdo->prepare("SELECT o.nom, o.email, o.fact_adresse, o.fact_siret, u.email uemail FROM organisateurs o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
    $st->execute([$orga]);
    $o = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['nom' => (string)($o['nom'] ?? 'Organisme'), 'adresse' => (string)($o['fact_adresse'] ?? ''), 'siret' => (string)($o['fact_siret'] ?? ''),
            'email' => (string)(filter_var($o['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $o['email'] : ($o['uemail'] ?? ''))];
}

/** Insère une facture ou un avoir sous verrou (numéro continu). $lignes en TTC signé du point de vue de la facture. */
function fac_inserer(PDO $pdo, string $type, string $cle, int $orga, string $mois, array $lignes, int $dette, array $client, ?array $origine = null): array {
    if (!(int)$pdo->query("SELECT GET_LOCK('mc_factures', 15)")->fetchColumn()) throw new RuntimeException('Numérotation occupée, réessayez.');
    try {
        $st = $pdo->prepare("SELECT * FROM fin_factures WHERE cle = ?");
        $st->execute([$cle]);
        if ($ex = $st->fetch(PDO::FETCH_ASSOC)) return $ex;
        $seq = (int)$pdo->query("SELECT COALESCE(MAX(seq), 0) + 1 FROM fin_factures")->fetchColumn();
        $f = ['seq' => $seq, 'numero' => sprintf('MC-%06d', $seq), 'type' => $type, 'cle' => $cle, 'token' => bin2hex(random_bytes(16)), 'organisateur_id' => $orga,
              'mois' => $mois, 'origine_id' => $origine['id'] ?? null, 'emise_le' => date('Y-m-d'), 'tva_taux' => fac_tva_taux(), 'dette_cents' => $dette,
              'client' => json_encode($client, JSON_UNESCAPED_UNICODE), 'lignes' => json_encode($lignes, JSON_UNESCAPED_UNICODE)];
        $ttc = array_sum(array_column($lignes, 'ttc'));
        $f['ht_cents'] = (int)round($ttc * 100 / (100 + $f['tva_taux']));
        $f['tva_cents'] = $ttc - $f['ht_cents'];
        $f['ttc_cents'] = $ttc;
        $f['pdf'] = fac_pdf($f, $origine);
        $f['sha256'] = hash('sha256', $f['pdf']);
        $cols = array_keys($f);
        $pdo->prepare("INSERT INTO fin_factures (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($f));
        $f['id'] = (int)$pdo->lastInsertId();
        return $f;
    } finally { $pdo->query("SELECT RELEASE_LOCK('mc_factures')"); }
}

/** Facture (ou avoir) du mois pour un organisme. Idempotent ; si la dernière a été annulée par avoir, en émet une nouvelle. null si rien à facturer. */
function fac_generer(PDO $pdo, int $orga, string $mois): ?array {
    fac_ensure($pdo);
    [$lignes, $ttc, $dette] = fac_calcul($pdo, $orga, $mois);
    if (!$lignes && !$dette) return null;
    for ($i = 1; $i < 50; $i++) {
        $cle = "auto:$orga:$mois" . ($i > 1 ? "#$i" : '');
        $st = $pdo->prepare("SELECT f.*, (SELECT COUNT(*) FROM fin_factures a WHERE a.origine_id = f.id) annulee FROM fin_factures f WHERE f.cle = ?");
        $st->execute([$cle]);
        $ex = $st->fetch(PDO::FETCH_ASSOC);
        if ($ex && !(int)$ex['annulee']) return $ex;
        if (!$ex) return fac_inserer($pdo, $ttc < 0 ? 'avoir' : 'facture', $cle, $orga, $mois, $lignes, $dette, fac_client($pdo, $orga));
    }
    return null;
}

/** Avoir intégral annulant une facture (une seule fois). */
function fac_annuler(PDO $pdo, array $f): array {
    fac_ensure($pdo);
    if ($f['type'] !== 'facture') throw new RuntimeException('Seule une facture peut être annulée par un avoir.');
    $st = $pdo->prepare("SELECT COUNT(*) FROM fin_factures WHERE origine_id = ?");
    $st->execute([$f['id']]);
    if ((int)$st->fetchColumn()) throw new RuntimeException('Cette facture est déjà annulée.');
    $lignes = array_map(fn($l) => ['lib' => 'Annulation : ' . $l['lib'], 'n' => $l['n'], 'ttc' => -$l['ttc']], json_decode($f['lignes'], true) ?: []);
    return fac_inserer($pdo, 'avoir', 'annul:' . $f['id'], (int)$f['organisateur_id'], $f['mois'], $lignes, 0, json_decode($f['client'], true) ?: [], $f);
}

function fac_pdf(array $f, ?array $origine): string {
    $S = SOCIETE; $c = json_decode($f['client'], true); $avoir = $f['type'] === 'avoir';
    $pdf = new class(($avoir ? 'Avoir n° ' : 'Facture n° ') . $f['numero'], 'Commissions de ' . fac_mois_lib($f['mois'])) extends MoncampPdf {
        public string $pied = '';
        function Footer() {
            $this->SetY(-14); $this->SetFont('Arial', '', 6.5); $this->SetTextColor(120, 130, 136);
            $this->MultiCell(0, 3, $this->t($this->pied . ' · page ' . $this->PageNo() . '/{nb}'), 0, 'C');
        }
    };
    $pdf->pied = trim($S['raison_sociale'] . ' · ' . $S['forme_juridique'] . (str_contains($S['capital'], 'COMPL') ? '' : ' au capital de ' . $S['capital']) . ' · ' . $S['siege'] . ' · SIRET ' . $S['siret'] . ' · ' . $S['rcs']);
    $pdf->section('Émetteur');
    $pdf->kv(['Société' => $S['raison_sociale'] . ' (' . $S['nom_commercial'] . ')', 'Siège' => $S['siege'], 'SIRET' => $S['siret'], 'RCS' => $S['rcs'],
              'TVA' => fac_tva_taux() > 0 ? $S['tva'] : 'TVA non applicable, art. 293 B du CGI', 'Contact' => $S['email']]);
    $pdf->section('Client');
    $pdf->kv(['Organisme' => $c['nom'] ?? '', 'Adresse' => ($c['adresse'] ?? '') ?: 'non renseignée', 'SIRET' => ($c['siret'] ?? '') ?: null, 'E-mail' => $c['email'] ?? '']);
    $pdf->section('Document');
    $pdf->kv(['Numéro' => $f['numero'], "Date d'émission" => pdf_date($f['emise_le']), 'Période' => fac_mois_lib($f['mois']) . ' (prestation de services)',
              'Référence' => $origine ? 'Annule la facture ' . $origine['numero'] . ' du ' . pdf_date($origine['emise_le']) : null]);
    $pdf->section('Détail');
    $t = $f['tva_taux']; $rows = [];
    foreach (json_decode($f['lignes'], true) as $l) {
        $ht = (int)round($l['ttc'] * 100 / (100 + $t));
        $rows[] = [$l['lib'], (string)$l['n'], fac_eur($ht), $t > 0 ? rtrim(rtrim(number_format($t, 2, ',', ''), '0'), ',') . ' %' : '-', fac_eur($l['ttc'])];
    }
    $pdf->table(['Désignation', 'Opérations', 'Montant HT', 'TVA', 'Montant TTC'], $rows, [90, 22, 28, 16, 28], [1 => 'R', 2 => 'R', 3 => 'R', 4 => 'R']);
    $pdf->kv(['Total HT' => fac_eur($f['ht_cents']), 'TVA' => fac_eur($f['tva_cents']), ($avoir ? 'Total TTC de l\'avoir' : 'Total TTC') => fac_eur($f['ttc_cents'])], 3);
    if ($t <= 0) { $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(0, 5, $pdf->t('TVA non applicable, art. 293 B du CGI.'), 0, 1); }
    if ((int)$f['dette_cents'] > 0) {
        $pdf->section('Pour information (hors facture)');
        $pdf->kv(['Dette compensée' => fac_eur((int)$f['dette_cents']) . ' retenus sur vos encaissements du mois pour solder des sommes avancées par moncamp.fr (remboursements, frais). Débours hors champ de la TVA, non inclus dans le total.'], 1);
    }
    $pdf->section('Conditions');
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->MultiCell(0, 3.8, $pdf->t(($avoir ? 'Montant remboursé ou imputé sur vos prochains encaissements.' : 'Facture acquittée : la commission est prélevée automatiquement sur les paiements encaissés via moncamp.fr, aucun règlement n\'est attendu.')
        . " Pas d'escompte pour paiement anticipé. En cas de retard de paiement : pénalités au taux de trois fois le taux d'intérêt légal et indemnité forfaitaire pour frais de recouvrement de 40 € (art. L441-10 du Code de commerce)."));
    return $pdf->Output('S');
}

function fac_mail(string $to, string $subject, string $html, string $fichier, string $pdf): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $b = 'mc' . bin2hex(random_bytes(8));
    $h = "MIME-Version: 1.0\r\nFrom: moncamp.fr <auth@moncamp.fr>\r\nReply-To: " . SOCIETE['email'] . "\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n";
    $body = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode('<div style="font-family:Arial,sans-serif;font-size:15px;color:#26343b;line-height:1.6">' . $html . '</div>'))
          . "--$b\r\nContent-Type: application/pdf; name=\"$fichier\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fichier\"\r\n\r\n" . chunk_split(base64_encode($pdf)) . "--$b--\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h);
}

function fac_envoyer(PDO $pdo, array $f): bool {
    $c = json_decode($f['client'], true) ?: [];
    $doc = $f['type'] === 'avoir' ? 'Avoir' : 'Facture';
    $html = '<p>Bonjour,</p><p>Vous trouverez ci-joint ' . ($f['type'] === 'avoir' ? "l'avoir" : 'la facture') . ' <b>' . htmlspecialchars($f['numero']) . '</b> des commissions moncamp.fr de '
          . fac_mois_lib($f['mois']) . ' (' . fac_eur((int)$f['ttc_cents']) . ' TTC). ' . ($f['type'] === 'facture' ? 'Elle est déjà acquittée : rien à régler.' : '')
          . '</p><p>Retrouvez toutes vos factures dans votre tableau de bord, onglet Rapports.</p><p style="color:#6b7780;font-size:13px">L\'équipe moncamp.fr</p>';
    if (!fac_mail($c['email'] ?? '', "$doc {$f['numero']} - moncamp.fr", $html, $f['numero'] . '.pdf', $f['pdf'])) return false;
    $pdo->prepare("UPDATE fin_factures SET envoye_at = NOW() WHERE id = ?")->execute([$f['id']]);
    return true;
}

/** Cron horaire : factures du mois écoulé (une fois par mois), puis renvoi des e-mails non partis depuis moins de 3 jours. */
function fac_cron(PDO $pdo, bool $force = false): array {
    fac_ensure($pdo);
    $mois = date('Y-m', strtotime('first day of last month'));
    $avant = (int)$pdo->query("SELECT COUNT(*) FROM fin_factures")->fetchColumn(); $env = 0;
    $fait = (string)$pdo->query("SELECT v FROM adm_kv WHERE k = 'factures:mois'")->fetchColumn();
    if ($force || $fait !== $mois) {
        $st = $pdo->prepare("SELECT DISTINCT organisateur_id FROM fin_ledger WHERE created_at >= ? AND created_at < (? + INTERVAL 1 MONTH) AND (type = 'commission' OR (type = 'dette' AND montant_cents < 0))");
        $st->execute([$mois . '-01', $mois . '-01']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $o) {
            try { fac_generer($pdo, (int)$o, $mois); } catch (Throwable $e) { error_log("fac_cron $o $mois: " . $e->getMessage()); }
        }
        $pdo->prepare("INSERT INTO adm_kv (k, v) VALUES ('factures:mois', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$mois]);
    }
    foreach ($pdo->query("SELECT * FROM fin_factures WHERE envoye_at IS NULL AND created_at > (NOW() - INTERVAL 3 DAY) ORDER BY seq LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) as $f)
        if (fac_envoyer($pdo, $f)) $env++;
    return ['mois' => $mois, 'generees' => (int)$pdo->query("SELECT COUNT(*) FROM fin_factures")->fetchColumn() - $avant, 'envoyees' => $env];
}
