<?php
// api/compta_lib.php : requêtes de la section « Comptabilité » de l'administration (lecture seule sur fin_paiements / fin_transferts)
// et génération des exports CSV (séparateur « ; », UTF-8 avec BOM, virgule décimale : s'ouvrent directement dans Excel en français).
// Tous les montants sont en centimes d'euro (entiers) ; la conversion en euros ne se fait qu'à l'affichage / à l'export.
require_once __DIR__ . '/finance_lib.php';

/** Période demandée : [du 'Y-m-d H:i:s', au 'Y-m-d H:i:s', libellé, clé]. Paramètres : p (mois|trimestre|annee|tout|perso), du, au (Y-m-d). */
function compta_period(array $q): array {
    $p = (string)($q['p'] ?? 'annee');
    $today = date('Y-m-d');
    switch ($p) {
        case 'mois': $d = date('Y-m-01'); $a = $today; $l = 'Ce mois-ci'; break;
        case 'trimestre': $m = (int)date('n'); $d = date('Y-') . sprintf('%02d', 3 * intdiv($m - 1, 3) + 1) . '-01'; $a = $today; $l = 'Ce trimestre'; break;
        case 'tout': $d = '2000-01-01'; $a = $today; $l = 'Depuis le début'; break;
        case 'perso':
            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['du'] ?? '')) ? $q['du'] : date('Y-01-01');
            $a = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['au'] ?? '')) ? $q['au'] : $today;
            if (!strtotime($d) || !strtotime($a)) { $d = date('Y-01-01'); $a = $today; }
            if ($d > $a) [$d, $a] = [$a, $d];
            $l = 'Du ' . date('d/m/Y', strtotime($d)) . ' au ' . date('d/m/Y', strtotime($a)); break;
        default: $p = 'annee'; $d = date('Y-01-01'); $a = $today; $l = 'Cette année';
    }
    return [$d . ' 00:00:00', $a . ' 23:59:59', $l, $p];
}

/** Synthèse de la période (et d'un organisme si $orgaId) */
function compta_summary(PDO $pdo, string $from, string $to, int $orgaId = 0): array {
    $com = fin_sql_com_nette('p'); $marge = fin_sql_marge('p');
    $sql = "SELECT COUNT(*) nb, COALESCE(SUM(p.brut_cents), 0) brut, COALESCE(SUM(p.rembourse_cents), 0) rembourse,
                COALESCE(SUM($com), 0) commission,
                COALESCE(SUM(p.frais_stripe_cents), 0) frais, COALESCE(SUM(p.frais_stripe_cents IS NULL), 0) frais_inconnus,
                COALESCE(SUM(p.frais_litige_cents), 0) litige,
                COALESCE(SUM(p.net_cents - p.net_rembourse_cents), 0) du_total,
                COALESCE(SUM(p.verse_cents), 0) verse,
                COALESCE(SUM(CASE WHEN p.statut IN ('attente','bloque','litige','en_cours') THEN GREATEST(0, p.net_cents - p.net_rembourse_cents - p.verse_cents) END), 0) attente,
                COALESCE(SUM(CASE WHEN p.frais_stripe_cents IS NOT NULL THEN $marge END), 0) marge,
                COALESCE(SUM(CASE WHEN p.frais_stripe_cents IS NOT NULL AND $marge < 0 THEN 1 END), 0) nb_negatives
            FROM fin_paiements p WHERE p.created_at BETWEEN ? AND ?" . ($orgaId ? " AND p.organisateur_id = ?" : "");
    $st = $pdo->prepare($sql);
    $st->execute($orgaId ? [$from, $to, $orgaId] : [$from, $to]);
    $r = array_map('intval', $st->fetch(PDO::FETCH_ASSOC));
    $r['ca'] = $r['brut'] - $r['rembourse']; // chiffre d'affaires encaissé, net des remboursements
    return $r;
}

/** Commissions retenues sur les paiements HORS Stripe (chèque, espèces...) de la période : [nb, brut, commission, par moyen] */
function compta_hors_stripe(PDO $pdo, string $from, string $to, int $orgaId = 0): array {
    $st = $pdo->prepare("SELECT moyen, COUNT(*) nb, COALESCE(SUM(brut_cents), 0) brut, COALESCE(SUM(commission_cents), 0) com FROM fin_commissions_hors
                         WHERE created_at BETWEEN ? AND ?" . ($orgaId ? " AND organisateur_id = ?" : "") . " GROUP BY moyen ORDER BY com DESC");
    $st->execute($orgaId ? [$from, $to, $orgaId] : [$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ['nb' => (int)array_sum(array_column($rows, 'nb')), 'brut' => (int)array_sum(array_column($rows, 'brut')), 'commission' => (int)array_sum(array_column($rows, 'com')), 'moyens' => $rows];
}

/** Une ligne par organisme (période) */
function compta_by_orga(PDO $pdo, string $from, string $to): array {
    $com = fin_sql_com_nette('p'); $marge = fin_sql_marge('p');
    $st = $pdo->prepare("SELECT o.id, o.nom, COUNT(*) nb, SUM(p.brut_cents - p.rembourse_cents) ca, SUM($com) commission,
                COALESCE(SUM(p.frais_stripe_cents), 0) frais, COALESCE(SUM(p.frais_litige_cents), 0) litige,
                COALESCE(SUM(CASE WHEN p.frais_stripe_cents IS NOT NULL THEN $marge END), 0) marge,
                SUM(p.net_cents - p.net_rembourse_cents) du,
                COALESCE(SUM(p.verse_cents), 0) verse,
                COALESCE(SUM(CASE WHEN p.statut IN ('attente','bloque','litige','en_cours') THEN GREATEST(0, p.net_cents - p.net_rembourse_cents - p.verse_cents) END), 0) attente
            FROM fin_paiements p JOIN organisateurs o ON o.id = p.organisateur_id
            WHERE p.created_at BETWEEN ? AND ? GROUP BY o.id, o.nom ORDER BY ca DESC");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Une ligne par mois (période) */
function compta_by_month(PDO $pdo, string $from, string $to, int $orgaId = 0): array {
    $com = fin_sql_com_nette('p'); $marge = fin_sql_marge('p');
    $st = $pdo->prepare("SELECT DATE_FORMAT(p.created_at, '%Y-%m') m, COUNT(*) nb, SUM(p.brut_cents - p.rembourse_cents) ca, SUM($com) commission,
                COALESCE(SUM(p.frais_stripe_cents), 0) frais, COALESCE(SUM(p.frais_litige_cents), 0) litige,
                COALESCE(SUM(CASE WHEN p.frais_stripe_cents IS NOT NULL THEN $marge END), 0) marge
            FROM fin_paiements p WHERE p.created_at BETWEEN ? AND ?" . ($orgaId ? " AND p.organisateur_id = ?" : "") . " GROUP BY m ORDER BY m DESC");
    $st->execute($orgaId ? [$from, $to, $orgaId] : [$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Paiements dont la marge est négative (la commission ne couvre pas les frais Stripe), tous temps confondus */
function compta_negative(PDO $pdo, int $limit = 50): array {
    $marge = fin_sql_marge('p'); $com = fin_sql_com_nette('p');
    return $pdo->query("SELECT p.inscription_id, p.created_at, p.brut_cents, p.rembourse_cents, p.statut, $com commission, p.frais_stripe_cents frais, p.frais_litige_cents litige,
                $marge marge, c.nom camp, o.nom orga
            FROM fin_paiements p JOIN camps c ON c.id = p.camp_id JOIN organisateurs o ON o.id = p.organisateur_id
            WHERE p.frais_stripe_cents IS NOT NULL AND $marge < 0 ORDER BY p.created_at DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
}
function compta_negative_count(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM fin_paiements p WHERE p.frais_stripe_cents IS NOT NULL AND " . fin_sql_marge('p') . " < 0")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Copie locale des virements Stripe de la plateforme vers sa banque (lecture seule côté Stripe) */
function compta_sync_payouts(PDO $pdo): int {
    fin_ensure($pdo);
    $n = 0;
    $up = $pdo->prepare("INSERT INTO fin_payouts (payout_id, montant_cents, statut, date_arrivee, date_creation, banque) VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE montant_cents = VALUES(montant_cents), statut = VALUES(statut), date_arrivee = VALUES(date_arrivee), banque = VALUES(banque)");
    foreach (fin_stripe()->payouts->all(['limit' => 100])->autoPagingIterator() as $po) {
        if (strtolower((string)$po->currency) !== 'eur') continue;
        $up->execute([$po->id, (int)$po->amount, (string)$po->status, $po->arrival_date ? date('Y-m-d H:i:s', (int)$po->arrival_date) : null, date('Y-m-d H:i:s', (int)$po->created), null]);
        if (++$n >= 500) break;
    }
    return $n;
}

/**
 * Rapprochement : ce que ColoMap DOIT avoir chez Stripe d'après la base, comparé au solde Stripe + virements déjà faits vers la banque.
 * $stripe = ['disponible' => cents, 'en_attente' => cents] ou null si Stripe est injoignable.
 */
function compta_reconcile(PDO $pdo, ?array $stripe): array {
    $a = $pdo->query("SELECT COALESCE(SUM(brut_cents - rembourse_cents), 0) ca, COALESCE(SUM(frais_stripe_cents), 0) frais, COALESCE(SUM(frais_litige_cents), 0) litige,
                COALESCE(SUM(frais_stripe_cents IS NULL), 0) inconnus,
                COALESCE(SUM(CASE WHEN statut IN ('attente','bloque','litige','en_cours') THEN GREATEST(0, net_cents - net_rembourse_cents - verse_cents) END), 0) du
            FROM fin_paiements")->fetch(PDO::FETCH_ASSOC);
    $transferts = (int)$pdo->query("SELECT COALESCE(SUM(montant_cents - reverse_cents), 0) FROM fin_transferts WHERE statut = 'envoye'")->fetchColumn(); // net des reversements
    $points = 0;
    try { $points = (int)$pdo->query("SELECT COALESCE(SUM(montant_cents), 0) FROM points_mouvements WHERE source = 'carte'")->fetchColumn(); } catch (Throwable $e) {}
    $payouts = (int)$pdo->query("SELECT COALESCE(SUM(montant_cents), 0) FROM fin_payouts WHERE statut IN ('paid','in_transit','pending')")->fetchColumn();
    $nbPayouts = (int)$pdo->query("SELECT COUNT(*) FROM fin_payouts")->fetchColumn();
    $theorique = (int)$a['ca'] + $points - (int)$a['frais'] - (int)$a['litige'] - $transferts; // fonds censés rester chez Stripe ou être partis en banque
    $hors = (int)$pdo->query("SELECT COALESCE(SUM(commission_cents), 0) FROM fin_commissions_hors")->fetchColumn();
    $r = ['hors' => $hors, 'ca' => (int)$a['ca'], 'points' => $points, 'frais' => (int)$a['frais'], 'litige' => (int)$a['litige'], 'frais_inconnus' => (int)$a['inconnus'],
          'transferts' => $transferts, 'du_orgas' => (int)$a['du'], 'theorique' => $theorique, 'payouts' => $payouts, 'nb_payouts' => $nbPayouts, 'stripe' => $stripe];
    if ($stripe) {
        $solde = $stripe['disponible'] + $stripe['en_attente'];
        $r['solde_stripe'] = $solde;
        $r['reel'] = $solde + $payouts;
        $r['ecart'] = $r['reel'] - $theorique;
        // Ce qui appartient à ColoMap dans le solde Stripe = solde - ce qui est dû aux organismes (jamais à virer en banque)
        $r['versable'] = max(0, $stripe['disponible'] - (int)$a['du']);
    }
    return $r;
}

// ---------------------------------------------------------------------------------------------
// Exports CSV
// ---------------------------------------------------------------------------------------------
function compta_eur(int $c): string { return number_format($c / 100, 2, ',', ''); }

/** Cellule CSV : neutralise les formules Excel (= + - @) dans les textes saisis par des utilisateurs (nom d'organisme, de séjour...) */
function compta_cell($v): string {
    $v = (string)$v;
    if ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^-?\d+(,\d+)?$/', $v)) $v = "'" . $v;
    return '"' . str_replace('"', '""', $v) . '"';
}
/** Envoie un CSV (BOM UTF-8, « ; », CRLF) puis termine */
function compta_csv(string $filename, array $headers, iterable $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_.-]/i', '_', $filename) . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . implode(';', array_map('compta_cell', $headers)) . "\r\n";
    foreach ($rows as $r) echo implode(';', array_map('compta_cell', $r)) . "\r\n";
    exit;
}

/** Jeu de données d'un export : [en-têtes, lignes (générateur)] */
function compta_export(PDO $pdo, string $type, string $from, string $to, int $orgaId = 0): ?array {
    $dt = fn($v) => $v ? date('d/m/Y H:i', strtotime($v)) : '';
    if ($type === 'paiements') {
        $q = $pdo->prepare("SELECT p.*, c.nom camp, o.nom orga, " . fin_sql_com_nette('p') . " com_nette
                FROM fin_paiements p JOIN camps c ON c.id = p.camp_id JOIN organisateurs o ON o.id = p.organisateur_id
                WHERE p.created_at BETWEEN ? AND ?" . ($orgaId ? " AND p.organisateur_id = ?" : "") . " ORDER BY p.created_at, p.id");
        $q->execute($orgaId ? [$from, $to, $orgaId] : [$from, $to]);
        $h = ['Date', 'Inscription', 'Organisme', 'Séjour', 'Référence paiement Stripe', 'Brut payé (€)', 'Remboursé (€)', 'Taux commission (%)', 'Part fixe (€)', 'Commission (€)', 'Commission nette des remboursements (€)',
              'Frais Stripe réels (€)', 'Frais de litige (€)', 'Marge nette ColoMap (€)', 'Net dû à l\'organisme (€)', 'Net remboursé (€)', 'Statut', 'Date de déblocage'];
        $gen = (function () use ($q, $dt) {
            foreach ($q as $p) {
                $known = $p['frais_stripe_cents'] !== null;
                yield [$dt($p['created_at']), $p['inscription_id'], $p['orga'], $p['camp'], $p['payment_intent'], compta_eur((int)$p['brut_cents']), compta_eur((int)$p['rembourse_cents']),
                    number_format((float)$p['commission_pct'], 2, ',', ''), compta_eur((int)$p['commission_fixe_cents']), compta_eur((int)$p['commission_cents']), compta_eur((int)$p['com_nette']),
                    $known ? compta_eur((int)$p['frais_stripe_cents']) : '', compta_eur((int)$p['frais_litige_cents']),
                    $known ? compta_eur((int)$p['com_nette'] - (int)$p['frais_stripe_cents'] - (int)$p['frais_litige_cents']) : '',
                    compta_eur((int)$p['net_cents']), compta_eur((int)$p['net_rembourse_cents']), $p['statut'], $dt($p['date_deblocage'])];
            }
        })();
        return [$h, $gen];
    }
    if ($type === 'transferts') {
        $q = $pdo->prepare("SELECT t.*, o.nom orga FROM fin_transferts t JOIN organisateurs o ON o.id = t.organisateur_id
                WHERE t.created_at BETWEEN ? AND ?" . ($orgaId ? " AND t.organisateur_id = ?" : "") . " ORDER BY t.created_at, t.id");
        $q->execute($orgaId ? [$from, $to, $orgaId] : [$from, $to]);
        $h = ['Date', 'Référence', 'Organisme', 'Type', 'Montant versé (€)', 'Nb inscriptions', 'Statut', 'Transfert Stripe', 'Erreur'];
        $gen = (function () use ($q, $dt) { foreach ($q as $t) yield [$dt($t['created_at']), substr($t['token'], 0, 12), $t['orga'], $t['type'], compta_eur((int)$t['montant_cents']), $t['nb_paiements'], $t['statut'], $t['stripe_transfer_id'], $t['erreur']]; })();
        return [$h, $gen];
    }
    if ($type === 'organismes') {
        $rows = compta_by_orga($pdo, $from, $to);
        $h = ['Organisme', 'Nb paiements', 'Chiffre d\'affaires (€)', 'Commission (€)', 'Frais Stripe (€)', 'Frais de litige (€)', 'Marge nette (€)', 'Dû à l\'organisme (€)', 'Déjà versé (€)', 'En attente (€)'];
        $gen = (function () use ($rows) { foreach ($rows as $r) yield [$r['nom'], $r['nb'], compta_eur((int)$r['ca']), compta_eur((int)$r['commission']), compta_eur((int)$r['frais']), compta_eur((int)$r['litige']), compta_eur((int)$r['marge']), compta_eur((int)$r['du']), compta_eur((int)$r['verse']), compta_eur((int)$r['attente'])]; })();
        return [$h, $gen];
    }
    if ($type === 'hors_stripe') {
        $q = $pdo->prepare("SELECT h.*, o.nom orga, c.nom camp FROM fin_commissions_hors h JOIN organisateurs o ON o.id = h.organisateur_id JOIN camps c ON c.id = h.camp_id
                WHERE h.created_at BETWEEN ? AND ?" . ($orgaId ? " AND h.organisateur_id = ?" : "") . " ORDER BY h.created_at, h.id");
        $q->execute($orgaId ? [$from, $to, $orgaId] : [$from, $to]);
        $h = ['Date', 'Inscription', 'Organisme', 'Séjour', 'Moyen de paiement', 'Montant réglé (€)', 'Taux (%)', 'Commission ColoMap (€)'];
        $gen = (function () use ($q, $dt) { foreach ($q as $r) yield [$dt($r['created_at']), $r['inscription_id'], $r['orga'], $r['camp'], $r['moyen'], compta_eur((int)$r['brut_cents']), number_format((float)$r['pct'], 2, ',', ''), compta_eur((int)$r['commission_cents'])]; })();
        return [$h, $gen];
    }
    if ($type === 'payouts') {
        $q = $pdo->prepare("SELECT * FROM fin_payouts WHERE date_creation BETWEEN ? AND ? ORDER BY date_creation");
        $q->execute([$from, $to]);
        $h = ['Date de création', 'Arrivée en banque', 'Montant (€)', 'Statut', 'Référence Stripe'];
        $gen = (function () use ($q, $dt) { foreach ($q as $p) yield [$dt($p['date_creation']), $dt($p['date_arrivee']), compta_eur((int)$p['montant_cents']), $p['statut'], $p['payout_id']]; })();
        return [$h, $gen];
    }
    if ($type === 'journal') {
        $q = $pdo->prepare("SELECT * FROM fin_audit WHERE created_at BETWEEN ? AND ? ORDER BY id");
        $q->execute([$from, $to]);
        $h = ['Date', 'Acteur', 'Action', 'Objet', 'Détail'];
        $gen = (function () use ($q) { foreach ($q as $a) yield [date('d/m/Y H:i:s', strtotime($a['created_at'])), $a['acteur'], $a['action'], $a['objet_type'] . ' ' . $a['objet_id'], $a['donnees']]; })();
        return [$h, $gen];
    }
    return null;
}

// ---------------------------------------------------------------------------------------------
// Calendrier des versements (onglet « Versements » de la comptabilité)
// ---------------------------------------------------------------------------------------------
function compta_dettes_count(PDO $pdo): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM fin_reversals WHERE statut = 'dette'")->fetchColumn(); } catch (Throwable $e) { return 0; }
}

/** Charge par jour de la semaine, situation par organisme (versable / réserve / délai), dettes et soldes négatifs */
function compta_versements(PDO $pdo): array {
    fin_ensure($pdo);
    $pct = fin_reserve_pct(); $now = date('Y-m-d H:i:s');
    $orgs = [];
    foreach ($pdo->query("SELECT id, nom, jour_versement, portefeuille FROM organisateurs ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) as $o)
        $orgs[(int)$o['id']] = $o + ['ok' => 0, 'held' => 0, 'wait' => 0, 'suspendu' => 0];
    foreach (fin_pending_rows($pdo) as $r) {
        $id = (int)$r['organisateur_id']; if (!isset($orgs[$id]) || $r['statut'] === 'en_cours') continue;
        $left = max(0, (int)$r['net_cents'] - (int)$r['net_rembourse_cents'] - (int)$r['verse_cents']);
        if ($r['statut'] !== 'attente') { $orgs[$id]['suspendu'] += $left; continue; }
        $p = fin_row_parts($r, $pct, $now);
        $orgs[$id]['ok'] += $p['ok']; $orgs[$id]['held'] += $p['held']; $orgs[$id]['wait'] += $p['wait'];
    }
    $jours = [];
    foreach (FIN_JOURS as $n => $nom) $jours[$n] = ['nom' => $nom, 'orgas' => 0, 'ok' => 0, 'held' => 0];
    foreach ($orgs as $o) {
        $j = (int)$o['jour_versement']; if (!isset($jours[$j])) continue;
        $jours[$j]['orgas']++; $jours[$j]['ok'] += max(0, min($o['ok'], (int)round($o['portefeuille'] * 100))); $jours[$j]['held'] += $o['held'];
    }
    $dettes = $pdo->query("SELECT r.id, r.montant_cents, r.recupere_cents, r.created_at, o.nom orga, p.inscription_id
                           FROM fin_reversals r JOIN organisateurs o ON o.id = r.organisateur_id JOIN fin_paiements p ON p.id = r.paiement_id
                           WHERE r.statut = 'dette' ORDER BY r.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $negatifs = array_values(array_filter($orgs, fn($o) => (float)$o['portefeuille'] < -0.004));
    return ['jours' => $jours, 'orgas' => array_values($orgs), 'dettes' => $dettes, 'negatifs' => $negatifs];
}
