<?php
// api/dashboard_stats.php?organisateur_id=N[&type=&sejour=&session=&moyen=&du=Y-m-d&au=Y-m-d] : activité d'un organisme pour dashboard_organisme.php.
// Un seul jeu de filtres pilote les cartes et les graphes : type de séjour, séjour (jeton de sa session principale), session (jeton), période d'inscription, moyen de paiement.
// Réservé au propriétaire de l'organisme. Aucune donnée personnelle : uniquement des totaux. Les montants viennent des inscriptions (carte comme chèque, espèces...).
require_once 'config.php';
require_once 'options.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ds_fin(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) ds_fin(['error' => 'Accès réservé aux organisateurs.'], 403);
$orgaId = (int)($_GET['organisateur_id'] ?? 0);
try {
    $o = $pdo->prepare("SELECT id FROM organisateurs WHERE id = ? AND user_id = ?");
    $o->execute([$orgaId, (int)$_SESSION['user']['id']]);
    if (!$o->fetchColumn()) ds_fin(['error' => 'Organisme introuvable.'], 404);

    $CANC = "COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule')";
    $OK = "i.statut_paiement IN ('PAYE', 'GRATUIT', 'A_REGLER')";
    $MOY = "CASE WHEN i.statut_paiement = 'GRATUIT' THEN 'gratuit' ELSE COALESCE(NULLIF(i.moyen_paiement, ''), IF(i.mode_paiement = 'CARTE', 'carte', 'inconnu')) END";
    $lib = array_map(fn($m) => $m[0], options_paiements()); $lib['gratuit'] = 'Gratuit'; $lib['inconnu'] = 'Non précisé';

    // ---- séjours et sessions de l'organisme (listes de filtres)
    $cs = $pdo->prepare("SELECT id, token, nom, type, groupe_id, date_debut, date_fin, quota_global FROM camps WHERE organisateur_id = ? AND COALESCE(supprime, 0) = 0 ORDER BY date_debut, id");
    $cs->execute([$orgaId]);
    $camps = $cs->fetchAll(PDO::FETCH_ASSOC);
    $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $lab = fn($c) => date('j', strtotime($c['date_debut'])) . ' ' . $mois[(int)date('n', strtotime($c['date_debut'])) - 1] . ' ' . date('Y', strtotime($c['date_debut']));
    $groupes = []; $types = [];
    foreach ($camps as $c) {
        $gid = (int)$c['groupe_id'] ?: (int)$c['id'];
        if (!isset($groupes[$gid]) || (int)$c['id'] === $gid) $groupes[$gid] = ['t' => $c['token'], 'nom' => html_entity_decode((string)$c['nom'])];
        if (trim((string)$c['type']) !== '') $types[trim((string)$c['type'])] = true;
    }
    $tokGid = []; foreach ($camps as $c) $tokGid[$c['token']] = (int)$c['groupe_id'] ?: (int)$c['id'];
    $gTok = array_column($groupes, 't'); $gByTok = []; foreach ($groupes as $gid => $g) $gByTok[$g['t']] = $gid;

    // ---- filtres
    $f = ['type' => trim((string)($_GET['type'] ?? '')), 'sejour' => (string)($_GET['sejour'] ?? ''), 'session' => (string)($_GET['session'] ?? ''), 'moyen' => (string)($_GET['moyen'] ?? '')];
    $date = function ($k, $def) { $v = (string)($_GET[$k] ?? ''); $d = DateTime::createFromFormat('Y-m-d', $v); return $d && $d->format('Y-m-d') === $v ? $v : $def; };
    $du = $date('du', date('Y-m-01', strtotime('first day of -11 month'))); $au = $date('au', date('Y-m-d'));
    if ($du > $au) [$du, $au] = [$au, $du];
    if (strtotime($au) - strtotime($du) > 3 * 366 * 86400) $du = date('Y-m-d', strtotime($au . ' -3 years'));

    // séjours retenus par les filtres de camp (type, séjour, session)
    $sel = array_values(array_filter($camps, function ($c) use ($f, $tokGid, $gByTok) {
        if ($f['type'] !== '' && trim((string)$c['type']) !== $f['type']) return false;
        if ($f['sejour'] !== '' && isset($gByTok[$f['sejour']]) && ($tokGid[$c['token']] ?? 0) !== $gByTok[$f['sejour']]) return false;
        if ($f['session'] !== '' && $c['token'] !== $f['session']) return false;
        return true;
    }));
    $ids = array_map(fn($c) => (int)$c['id'], $sel);
    $out = ['filtres' => [
        'types' => array_keys($types),
        'sejours' => array_values($groupes),
        'sessions' => array_map(fn($c) => ['t' => $c['token'], 's' => '', 'l' => $lab($c), 'n' => html_entity_decode((string)$c['nom'])], $camps),
        'moyens' => array_map(fn($k, $l) => ['k' => $k, 'l' => $l], array_keys($lib), array_values($lib)),
    ], 'periode' => ['du' => $du, 'au' => $au]];
    // sessions[].s : jeton du séjour (session principale) auquel appartient la session
    foreach ($out['filtres']['sessions'] as &$s) { $gid = $tokGid[$s['t']] ?? 0; $s['s'] = $groupes[$gid]['t'] ?? ''; } unset($s);

    $empty = ['ca_cents' => 0, 'inscrits' => 0, 'remplissage' => 0, 'places' => 0, 'a_encaisser_cents' => 0];
    if (!$ids) ds_fin($out + ['kpi' => $empty, 'serie' => ['labels' => [], 'ca' => [], 'ins' => []], 'sejours' => [], 'moyens' => []]);
    $in = implode(',', $ids);
    $dt = [$du . ' 00:00:00', $au . ' 23:59:59'];

    // ---- cartes
    $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN i.statut_paiement = 'PAYE' THEN i.montant_paye END), 0) ca, COUNT(*) n,
                               COALESCE(SUM(CASE WHEN i.statut_paiement = 'A_REGLER' THEN i.prix_final END), 0) ar
                        FROM inscriptions i WHERE i.camp_id IN ($in) AND $CANC AND $OK AND i.date_inscription BETWEEN ? AND ?" . ($f['moyen'] !== '' ? " AND ($MOY) = ?" : ''));
    $q->execute($f['moyen'] !== '' ? [$dt[0], $dt[1], $f['moyen']] : $dt);
    $k = $q->fetch(PDO::FETCH_ASSOC);
    // remplissage : inscrits de ces séjours (toutes périodes) sur leurs places
    $q = $pdo->query("SELECT i.camp_id, COUNT(*) n FROM inscriptions i WHERE i.camp_id IN ($in) AND $CANC AND $OK GROUP BY i.camp_id");
    $par = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $ins = array_sum($par); $places = 0; foreach ($sel as $c) $places += max(0, (int)$c['quota_global']);

    // ---- courbes mensuelles
    $serie = []; for ($t = strtotime(date('Y-m-01', strtotime($du))); $t <= strtotime($au) && count($serie) < 37; $t = strtotime('+1 month', $t)) $serie[date('Y-m', $t)] = ['ca' => 0, 'ins' => 0];
    $q = $pdo->prepare("SELECT DATE_FORMAT(i.date_inscription, '%Y-%m') m, COUNT(*) n, COALESCE(SUM(CASE WHEN i.statut_paiement = 'PAYE' THEN i.montant_paye END), 0) ca
                        FROM inscriptions i WHERE i.camp_id IN ($in) AND $CANC AND $OK AND i.date_inscription BETWEEN ? AND ?" . ($f['moyen'] !== '' ? " AND ($MOY) = ?" : '') . " GROUP BY m");
    $q->execute($f['moyen'] !== '' ? [$dt[0], $dt[1], $f['moyen']] : $dt);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($serie[$r['m']])) $serie[$r['m']] = ['ca' => round((float)$r['ca'], 2), 'ins' => (int)$r['n']];
    $labels = array_map(fn($m) => $mois[(int)substr($m, 5, 2) - 1] . (count($serie) > 12 ? ' ' . substr($m, 2, 2) : ''), array_keys($serie));

    // ---- remplissage par séjour (une ligne par session ; date ajoutée si plusieurs sessions du même nom)
    $noms = array_count_values(array_map(fn($c) => $c['nom'], $sel)); $sj = [];
    foreach ($sel as $c) {
        $qt = max(1, (int)$c['quota_global']); $n = (int)($par[$c['id']] ?? 0);
        $sj[] = ['nom' => html_entity_decode((string)$c['nom']) . ($noms[$c['nom']] > 1 ? ' · ' . $lab($c) : ''), 'ins' => $n, 'quota' => $qt, 'pct' => min(100, (int)round($n / $qt * 100))];
    }
    usort($sj, fn($a, $b) => $b['ins'] <=> $a['ins']);

    // ---- moyens de paiement (le filtre « moyen » n'y est pas appliqué : on voit la répartition complète)
    $q = $pdo->prepare("SELECT ($MOY) m, COUNT(*) n, COALESCE(SUM(CASE WHEN i.statut_paiement = 'PAYE' THEN i.montant_paye END), 0) montant,
                               COALESCE(SUM(CASE WHEN i.statut_paiement = 'A_REGLER' THEN i.prix_final END), 0) ar
                        FROM inscriptions i WHERE i.camp_id IN ($in) AND $CANC AND $OK AND i.date_inscription BETWEEN ? AND ? GROUP BY m ORDER BY n DESC");
    $q->execute($dt);
    $moy = array_map(fn($r) => ['k' => $r['m'], 'l' => $lib[$r['m']] ?? 'Non précisé', 'n' => (int)$r['n'], 'montant_cents' => (int)round($r['montant'] * 100), 'a_regler_cents' => (int)round($r['ar'] * 100)], $q->fetchAll(PDO::FETCH_ASSOC));

    ds_fin($out + ['kpi' => ['ca_cents' => (int)round($k['ca'] * 100), 'inscrits' => (int)$k['n'], 'remplissage' => $places ? (int)round($ins / $places * 100) : 0, 'places' => $places, 'a_encaisser_cents' => (int)round($k['ar'] * 100)],
        'serie' => ['labels' => $labels, 'ca' => array_column($serie, 'ca'), 'ins' => array_column($serie, 'ins')], 'sejours' => array_slice($sj, 0, 8), 'moyens' => $moy]);
} catch (Throwable $e) {
    error_log('dashboard_stats: ' . $e->getMessage());
    ds_fin(['error' => 'Impossible de charger les statistiques.'], 500);
}
