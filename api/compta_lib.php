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
                COALESCE(SUM(IF(" . fin_sql_frais_orga('p') . ", 0, p.frais_stripe_cents)), 0) frais, COALESCE(SUM(p.frais_stripe_cents IS NULL), 0) frais_inconnus,
                COALESCE(SUM(IF(" . fin_sql_frais_orga('p') . ", 0, p.frais_litige_cents)), 0) litige,
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
                COALESCE(SUM(IF(" . fin_sql_frais_orga('p') . ", 0, p.frais_stripe_cents)), 0) frais, COALESCE(SUM(IF(" . fin_sql_frais_orga('p') . ", 0, p.frais_litige_cents)), 0) litige,
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
                COALESCE(SUM(IF(" . fin_sql_frais_orga('p') . ", 0, p.frais_stripe_cents)), 0) frais, COALESCE(SUM(IF(" . fin_sql_frais_orga('p') . ", 0, p.frais_litige_cents)), 0) litige,
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
 * Rapprochement : ce que moncamp.fr DOIT avoir chez Stripe d'après la base, comparé au solde Stripe + virements déjà faits vers la banque.
 * $stripe = ['disponible' => cents, 'en_attente' => cents] ou null si Stripe est injoignable.
 */
/**
 * Encart « Vos commissions » : solde Stripe de la plateforme (disponible / à venir, EUR) et virements vers le compte bancaire de moncamp.fr
 * (dernier arrivé, prochain annoncé). Lecture seule, aucune règle de commission. null si Stripe est injoignable.
 */
function compta_plateforme_solde(): ?array {
    try {
        $s = fin_stripe();
        $b = $s->balance->retrieve();
        $eur = function ($l) { $t = 0; foreach ($l ?? [] as $x) if (strtolower((string)$x->currency) === 'eur') $t += (int)$x->amount; return $t; };
        $r = ['disponible' => $eur($b->available ?? []), 'a_venir' => $eur($b->pending ?? []), 'dernier' => null, 'prochain' => null, 'historique' => []];
        foreach ($s->payouts->all(['limit' => 20])->data as $po) {
            if (strtolower((string)$po->currency) !== 'eur') continue;
            $p = ['m' => (int)$po->amount, 'arrivee' => $po->arrival_date ? date('Y-m-d', (int)$po->arrival_date) : null, 'statut' => (string)$po->status];
            $r['historique'][] = $p + ['cree' => !empty($po->created) ? date('Y-m-d', (int)$po->created) : null];
            if ($po->status === 'paid' && !$r['dernier']) $r['dernier'] = $p;
            if (in_array($po->status, ['pending', 'in_transit'], true) && (!$r['prochain'] || $p['arrivee'] < $r['prochain']['arrivee'])) $r['prochain'] = $p;
        }
        return $r;
    } catch (Throwable $e) { error_log('compta_plateforme_solde: ' . $e->getMessage()); return null; }
}

/**
 * « Mes revenus » (admin) : ce que gagne moncamp.fr, lu dans le journal fin_ledger (ajout seulement).
 * Commission encaissée = − lignes « commission » (cartes et hors carte, nettes des commissions rendues) ; frais Stripe payés par la plateforme
 * = − lignes « frais » (paiements legacy / repli destination ; en direct charges l'organisme paie ses frais) ; marge nette = commission − frais.
 * Dettes d'organismes : lignes « dette » (avance > 0, recouvrement < 0). $g : j (jour), s (semaine), m (mois).
 */
function compta_revenus(PDO $pdo, string $from, string $to, string $g = 'm'): array {
    $fmt = ['j' => '%Y-%m-%d', 's' => '%x-S%v', 'm' => '%Y-%m'][$g] ?? '%Y-%m';
    $tot = function (string $a, string $b) use ($pdo): array {
        $st = $pdo->prepare("SELECT -COALESCE(SUM(IF(type = 'commission', montant_cents, 0)), 0) com,
                                    -COALESCE(SUM(IF(type = 'commission' AND reference LIKE 'hors:%', montant_cents, 0)), 0) hors,
                                    -COALESCE(SUM(IF(type = 'frais', montant_cents, 0)), 0) frais,
                                    COALESCE(SUM(IF(type = 'paiement', 1, 0)), 0) nb,
                                    COALESCE(SUM(IF(type = 'dette' AND montant_cents > 0, montant_cents, 0)), 0) avance,
                                    -COALESCE(SUM(IF(type = 'dette' AND montant_cents < 0, montant_cents, 0)), 0) recouvre
                             FROM fin_ledger WHERE created_at BETWEEN ? AND ?");
        $st->execute([$a, $b]);
        $r = array_map('intval', $st->fetch(PDO::FETCH_ASSOC));
        return $r + ['marge' => $r['com'] - $r['frais']];
    };
    $len = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400));
    $pto = date('Y-m-d 23:59:59', strtotime($from . ' -1 day')); $pfrom = date('Y-m-d 00:00:00', strtotime($pto . ' -' . ($len - 1) . ' days'));
    $st = $pdo->prepare("SELECT DATE_FORMAT(created_at, '$fmt') k, -SUM(IF(type = 'commission', montant_cents, 0)) com, -SUM(IF(type = 'frais', montant_cents, 0)) frais
                         FROM fin_ledger WHERE created_at BETWEEN ? AND ? AND type IN ('commission', 'frais') GROUP BY k ORDER BY k");
    $st->execute([$from, $to]);
    $serie = array_map(fn($r) => ['k' => $r['k'], 'com' => (int)$r['com'], 'frais' => (int)$r['frais']], $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare("SELECT o.id, o.nom, -SUM(IF(l.type = 'commission', l.montant_cents, 0)) com, -SUM(IF(l.type = 'frais', l.montant_cents, 0)) frais, SUM(l.type = 'paiement') nb
                         FROM fin_ledger l JOIN organisateurs o ON o.id = l.organisateur_id WHERE l.created_at BETWEEN ? AND ? AND l.type IN ('commission', 'frais', 'paiement')
                         GROUP BY o.id, o.nom HAVING com <> 0 OR frais <> 0 ORDER BY com DESC LIMIT 50");
    $st->execute([$from, $to]);
    $orgas = array_map(fn($r) => ['nom' => html_entity_decode((string)$r['nom'], ENT_QUOTES, 'UTF-8'), 'com' => (int)$r['com'], 'frais' => (int)$r['frais'], 'nb' => (int)$r['nb'], 'marge' => (int)$r['com'] - (int)$r['frais']], $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare("SELECT c.nom camp, o.nom orga, -SUM(IF(l.type = 'commission', l.montant_cents, 0)) com, SUM(l.type = 'paiement') nb
                         FROM fin_ledger l JOIN inscriptions i ON l.objet_type = 'inscription' AND i.id = l.objet_id JOIN camps c ON c.id = i.camp_id JOIN organisateurs o ON o.id = c.organisateur_id
                         WHERE l.created_at BETWEEN ? AND ? AND l.type IN ('commission', 'paiement') GROUP BY c.id, c.nom, o.nom HAVING com <> 0 ORDER BY com DESC LIMIT 20");
    $st->execute([$from, $to]);
    $sejours = array_map(fn($r) => ['camp' => html_entity_decode((string)$r['camp'], ENT_QUOTES, 'UTF-8'), 'orga' => html_entity_decode((string)$r['orga'], ENT_QUOTES, 'UTF-8'), 'com' => (int)$r['com'], 'nb' => (int)$r['nb']], $st->fetchAll(PDO::FETCH_ASSOC));
    $dettes = array_map(fn($r) => ['nom' => html_entity_decode((string)$r['nom'], ENT_QUOTES, 'UTF-8'), 'du' => (int)$r['du'], 'depuis' => $r['depuis']],
        $pdo->query("SELECT o.nom, SUM(l.montant_cents) du, MIN(l.created_at) depuis FROM fin_ledger l JOIN organisateurs o ON o.id = l.organisateur_id
                     WHERE l.type = 'dette' GROUP BY o.id, o.nom HAVING du > 0 ORDER BY du DESC")->fetchAll(PDO::FETCH_ASSOC));
    return ['cur' => $tot($from, $to), 'prev' => $tot($pfrom, $pto), 'prev_du' => $pfrom, 'prev_au' => $pto, 'serie' => $serie, 'orgas' => $orgas, 'sejours' => $sejours, 'dettes' => $dettes];
}

/**
 * « Commission − frais Stripe payés par moncamp.fr = net pour moncamp.fr », puis rapprochement avec le solde Stripe de la plateforme
 * (depuis le début, journal fin_ledger). Solde attendu = commissions par carte − frais + achats de points par carte + argent d'organismes encore
 * détenu (paiements reçus avant l'activation) − dette nette (avances − compensations et règlements) − virements vers la banque de moncamp.fr.
 * Les commissions hors carte n'entrent en caisse que lorsqu'elles sont compensées (lignes « dette » négatives).
 */
function compta_revenus_rapprochement(PDO $pdo, ?array $ps): array {
    $l = $pdo->query("SELECT -COALESCE(SUM(IF(type = 'commission', montant_cents, 0)), 0) com,
                             -COALESCE(SUM(IF(type = 'commission' AND reference LIKE 'hors:%', montant_cents, 0)), 0) hors,
                             -COALESCE(SUM(IF(type = 'frais', montant_cents, 0)), 0) frais,
                             COALESCE(SUM(IF(type = 'dette' AND (montant_cents < 0 OR reference LIKE 'avance:%'), montant_cents, 0)), 0) dette
                      FROM fin_ledger")->fetch(PDO::FETCH_ASSOC);
    $l = array_map('intval', $l);
    $points = 0;
    try { $points = (int)$pdo->query("SELECT COALESCE(SUM(montant_cents), 0) FROM points_mouvements WHERE source = 'carte'")->fetchColumn(); } catch (Throwable $e) {}
    $fraisEcart = (int)$pdo->query("SELECT COALESCE(SUM(frais_estimes_cents - COALESCE(frais_stripe_cents, frais_estimes_cents)), 0) FROM fin_paiements WHERE frais_orga = 1 AND mode = 'destination'")->fetchColumn();
    $detenu = (int)$pdo->query("SELECT COALESCE(SUM(GREATEST(0, net_cents - net_rembourse_cents - verse_cents)), 0) FROM fin_paiements WHERE mode = 'legacy' AND statut IN ('attente', 'bloque', 'litige', 'en_cours')")->fetchColumn();
    $payouts = (int)$pdo->query("SELECT COALESCE(SUM(montant_cents), 0) FROM fin_payouts WHERE statut IN ('paid', 'in_transit', 'pending')")->fetchColumn();
    $r = ['com' => $l['com'], 'frais' => $l['frais'], 'net' => $l['com'] - $l['frais'], 'hors' => $l['hors'], 'points' => $points, 'detenu' => $detenu, 'dette' => $l['dette'], 'payouts' => $payouts];
    $r['frais_ecart'] = $fraisEcart; // destination récente : frais estimés prélevés − frais réels payés par la plateforme (écart en dette ou rendu)
    $r['attendu'] = $r['net'] - $l['hors'] + $points + $detenu - $l['dette'] - $payouts + $fraisEcart;
    $r['solde'] = $ps ? $ps['disponible'] + $ps['a_venir'] : null;
    $r['ecart'] = $ps ? $r['solde'] - $r['attendu'] : null;
    return $r;
}

/** Export comptable mensuel de « Mes revenus » (une ligne par mois) */
function compta_revenus_mois(PDO $pdo, string $from, string $to): array {
    $st = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') m, SUM(type = 'paiement') nb,
                                -SUM(IF(type = 'commission' AND reference NOT LIKE 'hors:%', montant_cents, 0)) com_carte,
                                -SUM(IF(type = 'commission' AND reference LIKE 'hors:%', montant_cents, 0)) com_hors,
                                -SUM(IF(type = 'frais', montant_cents, 0)) frais,
                                SUM(IF(type = 'dette' AND montant_cents > 0, montant_cents, 0)) avance, -SUM(IF(type = 'dette' AND montant_cents < 0, montant_cents, 0)) recouvre
                         FROM fin_ledger WHERE created_at BETWEEN ? AND ? GROUP BY m ORDER BY m");
    $st->execute([$from, $to]);
    return array_map(function ($r) { $r = array_map(fn($v) => is_numeric($v) ? (int)$v : $v, $r); $r['marge'] = $r['com_carte'] + $r['com_hors'] - $r['frais']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

function compta_reconcile(PDO $pdo, ?array $stripe): array {
    $a = $pdo->query("SELECT COALESCE(SUM(brut_cents - rembourse_cents), 0) ca, COALESCE(SUM(frais_stripe_cents), 0) frais, COALESCE(SUM(frais_litige_cents), 0) litige,
                COALESCE(SUM(frais_stripe_cents IS NULL), 0) inconnus,
                COALESCE(SUM(CASE WHEN statut IN ('attente','bloque','litige','en_cours') THEN GREATEST(0, net_cents - net_rembourse_cents - verse_cents) END), 0) du
            FROM fin_paiements")->fetch(PDO::FETCH_ASSOC);
    $transferts = (int)$pdo->query("SELECT COALESCE(SUM(montant_cents - reverse_cents), 0) FROM fin_transferts WHERE statut = 'envoye' AND type <> 'payout'")->fetchColumn(); // net des reversements ; les payouts des comptes connectés ne sortent pas de notre solde
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
        // Ce qui appartient à moncamp.fr dans le solde Stripe = solde - ce qui est dû aux organismes (jamais à virer en banque)
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

/** Classeur Excel .xlsx minimal (une feuille, en-têtes en gras) écrit à la main : ZIP sans compression, aucune extension PHP requise.
 *  Nombres (int/float) en cellules numériques, textes en chaînes en ligne (formules impossibles). */
function compta_xlsx(string $filename, array $headers, iterable $rows): void {
    $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $row = function (array $r, int $n, bool $b) use ($x, $col): string {
        $o = '<row r="' . $n . '">';
        foreach (array_values($r) as $i => $v) {
            $ref = $col($i) . $n;
            $o .= (is_int($v) || is_float($v)) ? '<c r="' . $ref . '"' . ($b ? ' s="1"' : '') . '><v>' . $v . '</v></c>'
                : '<c r="' . $ref . '" t="inlineStr"' . ($b ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
        }
        return $o . '</row>';
    };
    $data = $row($headers, 1, true); $n = 1;
    foreach ($rows as $r) $data .= $row($r, ++$n, false);
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0" xfId="0"/><xf fontId="1" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>' . $data . '</sheetData></worksheet>',
    ];
    $zip = ''; $cd = ''; $t = (int)(((date('Y') - 1980) << 25) | (date('n') << 21) | (date('j') << 16) | (date('G') << 11) | ((int)date('i') << 5) | ((int)date('s') >> 1));
    foreach ($files as $name => $body) {
        $h = pack('vvvVVVVvv', 20, 0, 0, $t, crc32($body), strlen($body), strlen($body), strlen($name), 0);
        $cd .= pack('V', 0x02014b50) . pack('v', 20) . $h . pack('vvvVV', 0, 0, 0, 0, strlen($zip)) . $name;
        $zip .= pack('V', 0x04034b50) . $h . $name . $body;
    }
    $out = $zip . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($cd), strlen($zip), 0);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_.-]/i', '_', $filename) . '"');
    header('Content-Length: ' . strlen($out));
    header('Cache-Control: no-store');
    echo $out;
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
              'Frais Stripe réels (€)', 'Frais de litige (€)', 'Marge nette moncamp.fr (€)', 'Net dû à l\'organisme (€)', 'Net remboursé (€)', 'Statut', 'Date de déblocage'];
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
        $h = ['Date', 'Inscription', 'Organisme', 'Séjour', 'Moyen de paiement', 'Montant réglé (€)', 'Taux (%)', 'Commission moncamp.fr (€)'];
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
    if ($type === 'gains') {
        require_once __DIR__ . '/gains_lib.php';
        gains_ensure($pdo);
        $q = $pdo->prepare("SELECT j.*, o.nom AS orga FROM fin_gains_journal j LEFT JOIN organisateurs o ON o.id = j.organisateur_id WHERE j.created_at BETWEEN ? AND ? ORDER BY j.id");
        $q->execute([$from, $to]);
        $h = ['Réf.', 'Date', 'Organisme', 'Utilisateur', 'IP', 'Achat', 'Montant (€)', 'Solde avant (€)', 'Solde après (€)', 'Statut', 'Empreinte'];
        $gen = (function () use ($q) { foreach ($q as $g) yield ['G-' . $g['id'], date('d/m/Y H:i:s', strtotime($g['created_at'])), $g['orga'], $g['user_id'], $g['ip'], $g['libelle'], compta_eur((int)$g['montant_cents']), compta_eur((int)$g['solde_avant_cents']), compta_eur((int)$g['solde_apres_cents']), $g['statut'], $g['hash']]; })();
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

/** Situation par organisme (destination : à verser / à venir / litige ; anciens paiements legacy), derniers versements bancaires, dettes */
function compta_versements(PDO $pdo): array {
    fin_ensure($pdo);
    $orgs = [];
    foreach ($pdo->query("SELECT id, nom, portefeuille FROM organisateurs ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) as $o)
        $orgs[(int)$o['id']] = $o + ['ok' => 0, 'futur' => 0, 'litige' => 0, 'legacy' => 0];
    $reste = "GREATEST(0, net_cents - net_rembourse_cents - verse_cents)";
    foreach ($pdo->query("SELECT organisateur_id o, mode, SUM(IF(statut = 'attente' AND date_deblocage <= NOW(), $reste, 0)) ok, SUM(IF(statut = 'attente' AND date_deblocage > NOW(), $reste, 0)) futur,
                                 SUM(IF(statut IN ('litige', 'bloque'), $reste, 0)) litige, SUM(IF(statut <> 'en_cours', $reste, 0)) tout
                          FROM fin_paiements WHERE statut IN ('attente', 'bloque', 'litige', 'en_cours') GROUP BY organisateur_id, mode")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['o']; if (!isset($orgs[$id])) continue;
        if ($r['mode'] === 'legacy') { $orgs[$id]['legacy'] += (int)$r['tout']; continue; }
        foreach (['ok', 'futur', 'litige'] as $k) $orgs[$id][$k] += (int)$r[$k];
    }
    $payouts = $pdo->query("SELECT t.created_at, t.montant_cents, t.statut, t.erreur, o.nom orga FROM fin_transferts t JOIN organisateurs o ON o.id = t.organisateur_id
                            WHERE t.type = 'payout' ORDER BY t.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $dettes = $pdo->query("SELECT r.id, r.montant_cents, r.recupere_cents, r.created_at, o.nom orga, p.inscription_id
                           FROM fin_reversals r JOIN organisateurs o ON o.id = r.organisateur_id JOIN fin_paiements p ON p.id = r.paiement_id
                           WHERE r.statut = 'dette' ORDER BY r.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $negatifs = array_values(array_filter($orgs, fn($o) => (float)$o['portefeuille'] < -0.004));
    return ['orgas' => array_values($orgs), 'payouts' => $payouts, 'dettes' => $dettes, 'negatifs' => $negatifs, 'legacy' => array_sum(array_column($orgs, 'legacy'))];
}

// ---------------------------------------------------------------------------------------------
// Onglet « Commission » : lu dans le journal fin_ledger (ajout seulement) ; Stripe sert au rapprochement
// ---------------------------------------------------------------------------------------------
/** Commission (nette des commissions rendues), frais Stripe réels et marge : totaux, par organisme, par mois */
function compta_ledger_commission(PDO $pdo, string $from, string $to, int $orgaId = 0): array {
    $w = "l.created_at BETWEEN ? AND ?" . ($orgaId ? " AND l.organisateur_id = " . (int)$orgaId : '');
    $sel = "-SUM(IF(l.type = 'commission', l.montant_cents, 0)) com, -SUM(IF(l.type = 'frais', l.montant_cents, 0)) frais, SUM(IF(l.type = 'paiement', l.montant_cents, 0)) encaisse";
    $q = function (string $k) use ($pdo, $w, $sel, $from, $to) {
        $st = $pdo->prepare("SELECT $k k, $sel FROM fin_ledger l LEFT JOIN organisateurs o ON o.id = l.organisateur_id WHERE $w GROUP BY k ORDER BY k");
        $st->execute([$from, $to]);
        return array_map(fn($r) => $r + ['marge' => (int)$r['com'] - (int)$r['frais']], $st->fetchAll(PDO::FETCH_ASSOC));
    };
    $tot = $q("'total'")[0] ?? ['com' => 0, 'frais' => 0, 'encaisse' => 0, 'marge' => 0];
    return ['tot' => $tot, 'orgas' => $q('o.nom'), 'mois' => $q("DATE_FORMAT(l.created_at, '%Y-%m')")];
}

/** Marge réelle par paiement (pires d'abord) : commission conservée − frais Stripe − frais de litige */
function compta_marges(PDO $pdo, string $from, string $to, int $orgaId = 0, int $limit = 30): array {
    $st = $pdo->prepare("SELECT p.created_at, p.mode, o.nom orga, p.brut_cents, " . fin_sql_com_nette('p') . " com, p.frais_stripe_cents frais, p.frais_litige_cents litige, " . fin_sql_marge('p') . " marge
                         FROM fin_paiements p JOIN organisateurs o ON o.id = p.organisateur_id
                         WHERE p.created_at BETWEEN ? AND ? AND p.frais_stripe_cents IS NOT NULL" . ($orgaId ? " AND p.organisateur_id = " . (int)$orgaId : '') . " ORDER BY marge ASC, p.id DESC LIMIT " . (int)$limit);
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Dernières lignes du journal (période, organisme) */
function compta_journal(PDO $pdo, string $from, string $to, int $orgaId = 0, int $limit = 50): array {
    $st = $pdo->prepare("SELECT l.*, o.nom orga FROM fin_ledger l LEFT JOIN organisateurs o ON o.id = l.organisateur_id WHERE l.created_at BETWEEN ? AND ?"
                        . ($orgaId ? " AND l.organisateur_id = " . (int)$orgaId : '') . " ORDER BY l.id DESC LIMIT " . (int)$limit);
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Rapprochement des commissions « direct » et « destination » avec Stripe (application fees de la période, remboursements de commission déduits).
 * Attendu = commission + dette compensée − commission rendue des paiements destination créés sur la période. Écart ≠ 0 : à contrôler
 * (un litige perdu en crée un normalement : Stripe garde la commission, nos comptes la rendent car le paiement est perdu).
 */
function compta_fees_reconcile(PDO $pdo, string $from, string $to): array {
    $st = $pdo->prepare("SELECT COALESCE(SUM(commission_cents + compensation_cents - IF(annul_com = 1 OR mode = 'direct', 0, rembourse_cents - net_rembourse_cents)), 0) FROM fin_paiements
                         WHERE mode <> 'legacy' AND created_at BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $attendu = (int)$st->fetchColumn(); $stripe = 0; $n = 0;
    foreach (fin_stripe()->applicationFees->all(['created' => ['gte' => strtotime($from), 'lte' => strtotime($to)], 'limit' => 100])->autoPagingIterator() as $fee) {
        if (strtolower((string)$fee->currency) !== 'eur') continue;
        $stripe += (int)$fee->amount - (int)$fee->amount_refunded; $n++;
    }
    return ['attendu' => $attendu, 'stripe' => $stripe, 'ecart' => $stripe - $attendu, 'n' => $n];
}
