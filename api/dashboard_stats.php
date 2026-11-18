<?php
// api/dashboard_stats.php?o=<jeton organisme>&du=Y-m-d&au=Y-m-d : données du tableau de bord financier (dashboard_organisme.php).
// Réservé au propriétaire de l'organisme (ou admin). Montants en centimes. Sources : fin_ledger (journal en ajout seulement), fin_paiements,
// fin_transferts / fin_transfert_lignes (versements), inscriptions (hors carte, inscriptions, moyens). Aucune donnée de santé.
// Export (GET ou POST) : &export=tx|mois&fmt=csv|xlsx[&keys=p12,h34] (keys : lignes choisies dans le tableau ; vide = toute la période).
require_once 'config.php';
require_once 'options.php';
require_once 'compta_lib.php';
require_once 'finance_lib.php';
require_once 'orga_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store');

function ds_fin(array $d, int $code = 200): void { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user']['id']) || (empty($_SESSION['user']['is_directeur']) && empty($_SESSION['user']['is_admin']))) ds_fin(['error' => 'Accès réservé aux organisateurs.'], 403);
$orgaId = fin_orga_request($pdo);
$P = $_POST + $_GET;

try {
    fin_ensure($pdo);
    $dv = function ($v) { $d = DateTime::createFromFormat('Y-m-d', (string)$v); return $d && $d->format('Y-m-d') === $v ? $v : null; };
    $au = $dv($P['au'] ?? '') ?? date('Y-m-d');
    $du = $dv($P['du'] ?? '') ?? date('Y-m-d', strtotime($au . ' -364 days'));
    if ($du > $au) [$du, $au] = [$au, $du];
    if (strtotime($au) - strtotime($du) > 6 * 366 * 86400) $du = date('Y-m-d', strtotime($au . ' -6 years'));
    $len = (int)round((strtotime($au) - strtotime($du)) / 86400) + 1;
    $pau = date('Y-m-d', strtotime($du . ' -1 day')); $pdu = date('Y-m-d', strtotime($pau . ' -' . ($len - 1) . ' days'));
    $dt = [$du . ' 00:00:00', $au . ' 23:59:59'];
    $lib = array_map(fn($m) => $m[0], options_paiements()); $lib['gratuit'] = 'Gratuit'; $lib['inconnu'] = 'Non précisé';
    $tvaOrga = $pdo->query("SELECT tva_taux FROM organisateurs WHERE id = " . (int)$orgaId)->fetchColumn();
    $txt = fn($s) => html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $jl = fn($d) => (int)date('j', strtotime($d)) . ' ' . $mois[(int)date('n', strtotime($d)) - 1];

    // ---- journal : totaux d'une fenêtre (mêmes formules que fin_ledger_totaux)
    $tot = function (string $a, string $b) use ($pdo, $orgaId): array {
        $st = $pdo->prepare("SELECT type, COALESCE(SUM(montant_cents), 0) s, COALESCE(SUM(LEAST(montant_cents, 0)), 0) neg FROM fin_ledger WHERE organisateur_id = ? AND created_at BETWEEN ? AND ? GROUP BY type");
        $st->execute([$orgaId, $a . ' 00:00:00', $b . ' 23:59:59']);
        $s = array_fill_keys(FIN_LEDGER_TYPES, 0); $neg = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $s[$r['type']] = (int)$r['s']; if ($r['type'] === 'dette') $neg = (int)$r['neg']; }
        $net = $s['paiement'] + $s['commission'] + $s['frais_paiement'] + $s['remboursement'] + $s['ajustement'];
        return ['brut' => $s['paiement'], 'com' => -$s['commission'], 'frais' => -$s['frais_paiement'], 'remb' => -$s['remboursement'], 'ajust' => $s['ajustement'],
                'net' => $net, 'verse' => -$s['versement'], 'compense' => -$neg];
    };
    $all = fin_ledger_totaux($pdo, $orgaId);
    $dette = max(0, -(int)round((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = " . (int)$orgaId)->fetchColumn() * 100), -$all['en_attente']);

    // ---- prochains versements (prévisions) et à encaisser hors carte
    $st = $pdo->prepare("SELECT GREATEST(DATE(p.date_deblocage), CURDATE()) d, SUM(GREATEST(0, p.net_cents - p.net_rembourse_cents - p.verse_cents)) m, COUNT(*) n, GROUP_CONCAT(DISTINCT c.nom ORDER BY c.nom SEPARATOR ', ') sejours
                         FROM fin_paiements p JOIN camps c ON c.id = p.camp_id WHERE p.organisateur_id = ? AND p.statut = 'attente' GROUP BY d HAVING m > 0 ORDER BY d LIMIT 12");
    $st->execute([$orgaId]);
    $prochains = array_map(fn($r) => ['d' => $r['d'], 'm' => (int)$r['m'], 'n' => (int)$r['n'], 'sejours' => $txt($r['sejours'])], $st->fetchAll(PDO::FETCH_ASSOC));

    // ---- séries quotidiennes (journal + inscriptions)
    $days = [];
    for ($t = strtotime($du); $t <= strtotime($au); $t += 86400) $days[date('Y-m-d', $t)] = ['brut' => 0, 'net' => 0, 'ins' => 0];
    $st = $pdo->prepare("SELECT DATE(created_at) d, type, SUM(montant_cents) s FROM fin_ledger WHERE organisateur_id = ? AND created_at BETWEEN ? AND ?
                           AND type IN ('paiement', 'commission', 'frais_paiement', 'remboursement', 'ajustement') GROUP BY d, type");
    $st->execute([$orgaId, $dt[0], $dt[1]]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($days[$r['d']])) { $days[$r['d']]['net'] += (int)$r['s']; if ($r['type'] === 'paiement') $days[$r['d']]['brut'] += (int)$r['s']; }
    $CANC = "COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule')";
    $OK = "i.statut_paiement IN ('PAYE', 'GRATUIT', 'A_REGLER')";
    $MOY = "CASE WHEN i.statut_paiement = 'GRATUIT' THEN 'gratuit' ELSE COALESCE(NULLIF(i.moyen_paiement, ''), IF(i.mode_paiement = 'CARTE', 'carte', 'inconnu')) END";
    $st = $pdo->prepare("SELECT DATE(i.date_inscription) d, COUNT(*) n FROM inscriptions i JOIN camps c ON c.id = i.camp_id
                         WHERE c.organisateur_id = ? AND $CANC AND $OK AND i.date_inscription BETWEEN ? AND ? GROUP BY d");
    $st->execute([$orgaId, $dt[0], $dt[1]]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $d => $n) if (isset($days[$d])) $days[$d]['ins'] = (int)$n;
    $insPrev = $pdo->prepare("SELECT COUNT(*) FROM inscriptions i JOIN camps c ON c.id = i.camp_id WHERE c.organisateur_id = ? AND $CANC AND $OK AND i.date_inscription BETWEEN ? AND ?");
    $insPrev->execute([$orgaId, $pdu . ' 00:00:00', $pau . ' 23:59:59']);

    // ---- moyens de paiement (période)
    $st = $pdo->prepare("SELECT ($MOY) m, COUNT(*) n, COALESCE(SUM(CASE WHEN i.statut_paiement = 'PAYE' THEN i.montant_paye END), 0) montant
                         FROM inscriptions i JOIN camps c ON c.id = i.camp_id WHERE c.organisateur_id = ? AND $CANC AND $OK AND i.date_inscription BETWEEN ? AND ? GROUP BY m ORDER BY n DESC");
    $st->execute([$orgaId, $dt[0], $dt[1]]);
    $moyens = array_map(fn($r) => ['k' => $r['m'], 'l' => $lib[$r['m']] ?? 'Non précisé', 'n' => (int)$r['n'], 'montant' => (int)round($r['montant'] * 100)], $st->fetchAll(PDO::FETCH_ASSOC));

    // ---- séjours / sessions : filtres et remplissage (sessions en cours ou à venir, et celles de la période)
    $st = $pdo->prepare("SELECT c.id, c.token, c.nom, c.groupe_id, c.date_debut, c.date_fin, c.quota_global,
                                (SELECT COUNT(*) FROM inscriptions i WHERE i.camp_id = c.id AND $CANC AND $OK) ins
                         FROM camps c WHERE c.organisateur_id = ? AND COALESCE(c.supprime, 0) = 0 ORDER BY c.date_debut, c.id");
    $st->execute([$orgaId]);
    $camps = $st->fetchAll(PDO::FETCH_ASSOC);
    $grp = []; $sessions = []; $remp = []; $sessLab = [];
    foreach ($camps as $c) {
        $gid = (int)$c['groupe_id'] ?: (int)$c['id'];
        if (!isset($grp[$gid]) || (int)$c['id'] === $gid) $grp[$gid] = ['t' => $c['token'], 'nom' => $txt($c['nom'])];
        $sessLab[(int)$c['id']] = $jl($c['date_debut']) . ' → ' . $jl($c['date_fin']) . ' ' . date('Y', strtotime($c['date_fin']));
    }
    foreach ($camps as $c) {
        $gid = (int)$c['groupe_id'] ?: (int)$c['id'];
        $sessions[] = ['t' => $c['token'], 's' => $grp[$gid]['t'], 'l' => $sessLab[(int)$c['id']]];
        if ($c['date_fin'] >= date('Y-m-d', strtotime('-30 days')) || ($c['date_debut'] <= $au && $c['date_fin'] >= $du)) {
            $q = (int)$c['quota_global'];
            $remp[] = ['nom' => $txt($c['nom']), 'session' => $sessLab[(int)$c['id']], 'ins' => (int)$c['ins'], 'quota' => $q, 'pct' => $q > 0 ? min(100, (int)round($c['ins'] / $q * 100)) : null];
        }
    }
    $campGrp = []; foreach ($camps as $c) $campGrp[(int)$c['id']] = [$grp[(int)$c['groupe_id'] ?: (int)$c['id']]['t'], $c['token']];

    // ---- transactions de la période : carte (fin_paiements) et hors carte (inscriptions)
    $fam = "COALESCE(NULLIF(e.nom, ''), 'Équipe d''animation')";
    $st = $pdo->prepare("SELECT p.id, p.created_at d, $fam fam, e.prenom enf, u.prenom pp, u.nom pn, c.id camp_id, c.nom camp, p.brut_cents brut, p.rembourse_cents remb,
                                p.net_cents - p.net_rembourse_cents pv, p.statut st, p.date_deblocage dd, p.mode, p.payment_intent pi, i.reservation_token tok, p.annul_com ann,
                                IF(p.mode = 'direct', COALESCE(p.frais_stripe_cents, 0) + p.frais_litige_cents, 0) frais, p.compensation_cents comp, p.verse_cents verse, p.tva_taux_pct tva,
                                (SELECT t.token FROM fin_transfert_lignes l JOIN fin_transferts t ON t.id = l.transfert_id WHERE l.paiement_id = p.id ORDER BY t.id DESC LIMIT 1) vtok
                         FROM fin_paiements p JOIN camps c ON c.id = p.camp_id LEFT JOIN inscriptions i ON i.id = p.inscription_id
                         LEFT JOIN enfants e ON e.id = i.enfant_id LEFT JOIN users u ON u.id = e.parent_id
                         WHERE p.organisateur_id = ? AND p.created_at BETWEEN ? AND ? ORDER BY p.created_at DESC LIMIT 5000");
    $st->execute([$orgaId, $dt[0], $dt[1]]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT i.id, i.date_inscription d, $fam fam, e.prenom enf, u.prenom pp, u.nom pn, c.id camp_id, c.nom camp, ROUND(i.prix_final * 100) brut, 0 remb,
                                ROUND(i.prix_final * 100) - COALESCE(h.commission_cents, 0) pv, i.statut_paiement st, NULL dd, 'hors' mode, NULL pi, i.reservation_token tok, 0 ann, 0 frais,
                                0 comp, 0 verse, NULL tva, NULL vtok, i.moyen_paiement moy
                         FROM inscriptions i JOIN camps c ON c.id = i.camp_id LEFT JOIN enfants e ON e.id = i.enfant_id LEFT JOIN users u ON u.id = e.parent_id
                         LEFT JOIN fin_commissions_hors h ON h.inscription_id = i.id
                         WHERE c.organisateur_id = ? AND $CANC AND i.statut_paiement IN ('PAYE', 'A_REGLER') AND COALESCE(i.moyen_paiement, '') NOT IN ('', 'carte')
                           AND i.date_inscription BETWEEN ? AND ? ORDER BY i.date_inscription DESC LIMIT 5000");
    $st->execute([$orgaId, $dt[0], $dt[1]]);
    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
    usort($rows, fn($a, $b) => strcmp($b['d'], $a['d']));
    $etat = function (array $r): array {
        if ((int)$r['ann'] === 1) return ['annule', 'Annulé par la famille'];
        if ((int)$r['remb'] > 0 && (int)$r['remb'] >= (int)$r['brut']) return ['rembourse', 'Remboursé'];
        $k = $r['st']; $dd = $r['dd'] ? date('d/m/Y', strtotime($r['dd'])) : '';
        $l = ['attente' => 'Versement prévu le ' . $dd, 'en_cours' => 'Versement en cours', 'verse' => 'Versé', 'litige' => 'Litige', 'bloque' => 'Suspendu',
              'rembourse' => 'Remboursé', 'PAYE' => 'Reçu (hors carte)', 'A_REGLER' => 'À encaisser'][$k] ?? $k;
        if ((int)$r['remb'] > 0) return [$k === 'rembourse' ? 'rembourse' : 'partiel', $l . ' · remboursé en partie'];
        return [$k, $l];
    };
    $tx = [];
    foreach ($rows as $r) {
        [$sk, $sl] = $etat($r); $card = $r['mode'] !== 'hors'; $moy = $card ? 'carte' : (string)$r['moy'];
        $tx[] = ['k' => ($card ? 'p' : 'h') . $r['id'], 'd' => $r['d'], 'fam' => $txt($r['fam']), 'enf' => $txt($r['enf']), 'parent' => trim($txt($r['pp']) . ' ' . $txt($r['pn'])),
            'camp' => $txt($r['camp']), 'sej' => $campGrp[(int)$r['camp_id']][0] ?? '', 'ses' => $campGrp[(int)$r['camp_id']][1] ?? '', 'sesl' => $sessLab[(int)$r['camp_id']] ?? '',
            'moy' => $moy, 'moyl' => $lib[$moy] ?? 'Non précisé', 'brut' => (int)$r['brut'], 'remb' => (int)$r['remb'], 'frais' => (int)$r['frais'], 'pv' => (int)$r['pv'],
            'com' => (int)$r['brut'] - (int)$r['remb'] - (int)$r['pv'] - (int)$r['frais'], 'comp' => (int)$r['comp'], 'verse' => (int)$r['verse'],
            'st' => $sk, 'stl' => $sl, 'dd' => $r['dd'] ? substr($r['dd'], 0, 10) : null, 'mode' => $r['mode'], 'pi' => $r['pi'] ? (string)$r['pi'] : null,
            'tok' => (string)$r['tok'], 'vtok' => $r['vtok'] ? (string)$r['vtok'] : null,
            'tva' => fin_tva_incluse((int)$r['brut'] - (int)$r['remb'], $card ? $r['tva'] : $tvaOrga)];
    }

    // ---- rapport mensuel (journal + TVA incluse des transactions)
    $rap = [];
    $st = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') m, type, SUM(montant_cents) s, SUM(LEAST(montant_cents, 0)) neg FROM fin_ledger WHERE organisateur_id = ? AND created_at BETWEEN ? AND ? GROUP BY m, type ORDER BY m");
    $st->execute([$orgaId, $dt[0], $dt[1]]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = $r['m']; $rap[$m] = $rap[$m] ?? ['m' => $m, 'brut' => 0, 'com' => 0, 'frais' => 0, 'remb' => 0, 'ajust' => 0, 'net' => 0, 'verse' => 0, 'compense' => 0, 'hors' => 0, 'tva' => 0];
        $s = (int)$r['s'];
        switch ($r['type']) {
            case 'paiement': $rap[$m]['brut'] += $s; $rap[$m]['net'] += $s; break;
            case 'commission': $rap[$m]['com'] -= $s; $rap[$m]['net'] += $s; break;
            case 'frais_paiement': $rap[$m]['frais'] -= $s; $rap[$m]['net'] += $s; break;
            case 'remboursement': $rap[$m]['remb'] -= $s; $rap[$m]['net'] += $s; break;
            case 'ajustement': $rap[$m]['ajust'] += $s; $rap[$m]['net'] += $s; break;
            case 'versement': $rap[$m]['verse'] -= $s; break;
            case 'dette': $rap[$m]['compense'] -= (int)$r['neg']; break;
        }
    }
    foreach ($tx as $t) {
        $m = substr($t['d'], 0, 7); if (!isset($rap[$m])) $rap[$m] = ['m' => $m, 'brut' => 0, 'com' => 0, 'frais' => 0, 'remb' => 0, 'ajust' => 0, 'net' => 0, 'verse' => 0, 'compense' => 0, 'hors' => 0, 'tva' => 0];
        if ($t['mode'] === 'hors' && $t['st'] === 'PAYE') $rap[$m]['hors'] += $t['brut'];
        if ($t['mode'] !== 'hors' || $t['st'] === 'PAYE') $rap[$m]['tva'] += $t['tva'];
    }
    ksort($rap); $rap = array_values($rap);

    // ---- exports
    $export = (string)($P['export'] ?? '');
    if ($export !== '') {
        $fmt = ($P['fmt'] ?? '') === 'xlsx' ? 'xlsx' : 'csv';
        $e = fn(int $c) => $fmt === 'xlsx' ? round($c / 100, 2) : compta_eur($c);
        if ($export === 'mois') {
            $h = ['Mois', 'Encaissé carte', 'Commission moncamp.fr', 'Frais Stripe', 'Remboursé', 'Ajustements', 'Net organisme', 'Versé banque', 'Dette compensée', 'Reçu hors carte', 'TVA incluse'];
            $g = (function () use ($rap, $e) { foreach ($rap as $r) yield [$r['m'], $e($r['brut']), $e($r['com']), $e($r['frais']), $e($r['remb']), $e($r['ajust']), $e($r['net']), $e($r['verse']), $e($r['compense']), $e($r['hors']), $e($r['tva'])]; })();
            $name = 'rapport-mensuel-' . $du . '-' . $au;
        } else {
            $keys = array_flip(array_filter(explode(',', (string)($P['keys'] ?? ''))));
            $sel = $keys ? array_values(array_filter($tx, fn($t) => isset($keys[$t['k']]))) : $tx;
            $h = ['Date', 'Famille', 'Enfant', 'Séjour', 'Session', 'Moyen', 'Payé', 'Remboursé', 'Commission', 'Frais Stripe', 'Net organisme', 'TVA incluse', 'Statut', 'Référence Stripe'];
            $g = (function () use ($sel, $e) { foreach ($sel as $t) yield [date('d/m/Y H:i', strtotime($t['d'])), $t['fam'], $t['enf'], $t['camp'], $t['sesl'], $t['moyl'], $e($t['brut']), $e($t['remb']), $e($t['com']), $e($t['frais']), $e($t['pv']), $e($t['tva']), $t['stl'], (string)$t['pi']]; })();
            $name = 'transactions-' . $du . '-' . $au;
        }
        if ($fmt === 'xlsx') compta_xlsx($name . '.xlsx', $h, $g);
        compta_csv($name . '.csv', $h, $g);
    }

    // ---- versements (historique, indépendant de la période) avec les transactions incluses
    $st = $pdo->prepare("SELECT id, token, type, statut, montant_cents, erreur, nb_paiements, created_at, updated_at FROM fin_transferts WHERE organisateur_id = ? ORDER BY created_at DESC LIMIT 60");
    $st->execute([$orgaId]);
    $vers = $st->fetchAll(PDO::FETCH_ASSOC); $lignes = [];
    if ($vers) {
        $in = implode(',', array_map(fn($v) => (int)$v['id'], $vers));
        foreach ($pdo->query("SELECT l.transfert_id, l.montant_cents, p.created_at, c.nom camp, $fam fam FROM fin_transfert_lignes l JOIN fin_paiements p ON p.id = l.paiement_id
                              JOIN camps c ON c.id = p.camp_id LEFT JOIN inscriptions i ON i.id = p.inscription_id LEFT JOIN enfants e ON e.id = i.enfant_id
                              WHERE l.transfert_id IN ($in) ORDER BY p.created_at")->fetchAll(PDO::FETCH_ASSOC) as $l)
            $lignes[(int)$l['transfert_id']][] = ['d' => $l['created_at'], 'fam' => $txt($l['fam']), 'camp' => $txt($l['camp']), 'm' => (int)$l['montant_cents']];
    }
    $versements = array_map(function ($v) use ($lignes) {
        $po = $v['type'] === 'payout';
        $s = $po ? (['en_cours' => 'prepa', 'envoye' => 'route', 'paye' => 'arrive', 'echec' => 'echec'][$v['statut']] ?? $v['statut'])
                 : (['envoye' => 'stripe', 'en_cours' => 'prepa', 'echec' => 'echec'][$v['statut']] ?? $v['statut']);
        $arr = $v['statut'] === 'paye' ? substr($v['updated_at'], 0, 10) : ($po && $v['statut'] === 'envoye' ? date('Y-m-d', strtotime($v['created_at'] . ' +2 weekdays')) : null);
        return ['tok' => $v['token'], 'd' => $v['created_at'], 'm' => (int)$v['montant_cents'], 'st' => $s, 'payout' => $po, 'arr' => $arr, 'arr_est' => $v['statut'] !== 'paye',
                'err' => $v['statut'] === 'echec' ? (string)$v['erreur'] : null, 'n' => (int)$v['nb_paiements'], 'lignes' => $lignes[(int)$v['id']] ?? []];
    }, $vers);
    try {
        $st = $pdo->prepare("SELECT token, montant_apres_commission m, date_demande d, effectue FROM virements WHERE organisateur_id = ? ORDER BY date_demande DESC LIMIT 30");
        $st->execute([$orgaId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) $versements[] = ['tok' => $v['token'], 'd' => $v['d'], 'm' => (int)round($v['m'] * 100), 'st' => $v['effectue'] ? 'arrive' : 'prepa', 'payout' => true, 'arr' => null, 'arr_est' => false, 'err' => null, 'n' => 0, 'lignes' => [], 'ancien' => true];
    } catch (Throwable $e) { /* pas d'ancien système */ }

    // ---- dette / compensations (tout l'historique) et annulations des familles
    $st = $pdo->prepare("SELECT created_at d, montant_cents m, libelle FROM fin_ledger WHERE organisateur_id = ? AND type = 'dette' ORDER BY id DESC LIMIT 100");
    $st->execute([$orgaId]);
    $dettes = array_map(fn($r) => ['d' => $r['d'], 'm' => (int)$r['m'], 'l' => (string)$r['libelle']], $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare("SELECT a.created_at d, a.motif, a.pct, a.rembourse_cents r, a.commission_retenue_cents c, $fam fam, c.nom camp FROM fin_annulations a
                         JOIN inscriptions i ON i.id = a.inscription_id JOIN camps c ON c.id = i.camp_id LEFT JOIN enfants e ON e.id = i.enfant_id
                         WHERE a.organisateur_id = ? ORDER BY a.id DESC LIMIT 100");
    $st->execute([$orgaId]);
    $annul = array_map(fn($r) => ['d' => $r['d'], 'motif' => (string)$r['motif'], 'pct' => (int)$r['pct'], 'r' => (int)$r['r'], 'c' => (int)$r['c'], 'fam' => $txt($r['fam']), 'camp' => $txt($r['camp'])], $st->fetchAll(PDO::FETCH_ASSOC));

    // ---- hors carte à encaisser (toutes périodes)
    $st = $pdo->prepare("SELECT i.reservation_token tok, i.date_inscription d, $fam fam, e.prenom enf, c.nom camp, c.date_debut, ROUND(i.prix_final * 100) m, i.moyen_paiement moy
                         FROM inscriptions i JOIN camps c ON c.id = i.camp_id LEFT JOIN enfants e ON e.id = i.enfant_id
                         WHERE c.organisateur_id = ? AND $CANC AND i.statut_paiement = 'A_REGLER' AND COALESCE(i.moyen_paiement, '') NOT IN ('', 'carte') ORDER BY c.date_debut, i.date_inscription LIMIT 500");
    $st->execute([$orgaId]);
    $hors = array_map(fn($r) => ['tok' => (string)$r['tok'], 'd' => $r['d'], 'fam' => $txt($r['fam']), 'enf' => $txt($r['enf']), 'camp' => $txt($r['camp']), 'debut' => $r['date_debut'], 'm' => (int)$r['m'], 'moyl' => $lib[$r['moy']] ?? 'Non précisé'], $st->fetchAll(PDO::FETCH_ASSOC));

    $cur = $tot($du, $au); $prev = $tot($pdu, $pau);
    $cur['ins'] = array_sum(array_column($days, 'ins')); $prev['ins'] = (int)$insPrev->fetchColumn();
    ds_fin([
        'periode' => ['du' => $du, 'au' => $au, 'prev_du' => $pdu, 'prev_au' => $pau],
        'kpi' => ['cur' => $cur, 'prev' => $prev, 'en_attente' => max(0, $all['en_attente']), 'dette' => $dette, 'verse_total' => $all['verse'],
                  'prochain' => $prochains ? ['d' => $prochains[0]['d'], 'm' => min($prochains[0]['m'], max(0, $all['en_attente']))] : null,
                  'a_encaisser' => array_sum(array_column($hors, 'm')), 'nb_a_encaisser' => count($hors)],
        'jours' => array_map(fn($d, $v) => ['d' => $d] + $v, array_keys($days), array_values($days)),
        'moyens' => $moyens, 'remplissage' => $remp,
        'filtres' => ['sejours' => array_values($grp), 'sessions' => $sessions, 'moyens' => array_map(fn($k, $l) => ['k' => $k, 'l' => $l], array_keys($lib), array_values($lib))],
        'tx' => $tx, 'tx_limite' => count($tx) >= 5000, 'prochains' => $prochains, 'versements' => $versements, 'dettes' => $dettes, 'annulations' => $annul, 'hors' => $hors, 'mois' => $rap,
        'tva' => $tvaOrga !== null && (float)$tvaOrga > 0,
    ]);
} catch (Throwable $e) {
    error_log('dashboard_stats: ' . $e->getMessage());
    ds_fin(['error' => 'Impossible de charger les données.'], 500);
}
