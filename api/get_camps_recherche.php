<?php
// api/get_camps_recherche.php : moteur de la page de recherche (le détail du calcul est dans api/search_lib.php).
// Paramètres : name (phrase libre : mots, âge « 12 ans », budget « moins de 500 € », période « été 2027 »), city, age, sexe (Homme|Femme), max_price,
//   date_from, date_to, online, sort (relevance|date|price_asc|price_desc), page/limit,
//   cat[] (catégories) + catmode=all (toutes), fv[]=categorie:precision:valeur (précisions), themes[] (anciens thèmes, toujours acceptés), intents=0 (ne pas interpréter la phrase).
// Ne renvoie que les champs utiles à l'affichage d'une carte (aucune donnée interne du séjour).
require_once 'config.php';
require_once 'sessions.php';
require_once 'organismes.php';
orga_ensure($pdo);
require_once 'options.php';
require_once 'attente.php';
require_once 'marketing_lib.php';
require_once 'search_lib.php';
require_once 'search_stats.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function clean($str) { return htmlspecialchars_decode($str ?? ''); }
function media($path, $dir = '') {
    $path = trim((string)$path);
    if ($path === '') return null;
    $path = str_replace('\\', '/', $path);
    if (preg_match('#^(https?:)?//#', $path) || strpos($path, 'uploads/') === 0) return $path;
    return $dir . ltrim($path, '/');
}

try {
    $today = date('Y-m-d');
    $animCatalogue = defined('ANIM_CATALOGUE');
    $sexe = options_sexe_param($_GET['sexe'] ?? '');
    if ($sexe) { options_ensure($pdo); attente_ensure($pdo); }

    $vus = [];
    foreach (array_keys($_SESSION ?? []) as $k) if (strpos((string)$k, 'viewed_camp_') === 0) $vus[] = (int)substr((string)$k, 12);
    $ctx = ['userId' => $animCatalogue ? null : ($_SESSION['user']['id'] ?? null), 'vus' => $vus, 'sexe' => $sexe];
    // Catalogue réservé aux animateurs (api/anim_catalogue.php) : uniquement les séjours qui recrutent, à venir
    if ($animCatalogue) {
        $ctx['base_where'] = " WHERE c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND c.supprime = 0 AND c.recrute_animateurs = 1 AND c.date_debut >= :today ";
        $ctx['base_params'] = [':today' => $today];
    }

    $res = sq_run($pdo, $_GET, $ctx);
    $items = $res['items'];
    $isFallback = false;

    // Aucun résultat : on propose les séjours mis en avant (les filtres ne sont pas appliqués)
    if (!$items && !$animCatalogue) {
        $isFallback = true;
        $rows = sq_candidats($pdo, ['today' => $today, 'sexe' => $sexe]);
        $rows = array_values(array_filter($rows, function ($r) { return !empty($r['is_vedette']); }));
        usort($rows, function ($a, $b) { return strcmp((string)$b['date_bump'], (string)$a['date_bump']); });
        foreach (array_slice($rows, 0, 12) as $r) $items[] = ['row' => $r, 'sponsor' => true, 'parts' => [], 'rel' => null];
    }

    // Un camp à plusieurs sessions n'apparaît qu'une fois (sa prochaine session)
    $liste = [];
    foreach ($items as $it) { $r = $it['row']; $r['_sponsor'] = !empty($it['sponsor']); $r['_parts'] = $it['parts']; $r['_why'] = $it['why'] ?? []; $liste[] = $r; }
    $camps = collapseSessions($liste);
    $total = count($camps);

    $limit = max(1, min(48, (int)($_GET['limit'] ?? 24)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $camps = array_slice($camps, ($page - 1) * $limit, $limit);

    $catalogue = $res['catalogue'] ?? [];
    $catMap = [];
    foreach (($res['docs'] ?? []) as $d) $catMap[$d['id']] = array_keys($d['cat']);
    $catsDe = function ($id) use ($catMap, $catalogue) {
        $noms = [];
        foreach ($catMap[(int)$id] ?? [] as $s) if (isset($catalogue[$s])) $noms[] = $catalogue[$s]['nom'];
        return array_slice($noms, 0, 3);
    };
    $explain = !empty($_GET['explain']) && !empty($_SESSION['user']['is_admin']);
    $out = [];
    foreach ($camps as $c) {
        $badge = !empty($c['_sponsor']) || $c['is_vedette'] ? 'Sponsorisé' : ($c['is_urgence'] ? 'Dernière minute' : null);
        $o = [
            'id' => (int)$c['id'], 'token' => $c['token'],
            'nom' => clean($c['nom']), 'ville' => clean($c['ville']),
            'image_url' => media($c['image_url'], 'uploads/camps/'),
            'date_debut' => $c['date_debut'], 'date_fin' => $c['date_fin'],
            'age_min' => $c['age_min'] !== null ? (int)$c['age_min'] : null, 'age_max' => $c['age_max'] !== null ? (int)$c['age_max'] : null,
            'type_name' => clean($c['type'] ?? ''), 'theme' => clean($c['theme'] ?? ''),
            'categories' => $isFallback ? [] : $catsDe($c['id']),
            'prix' => $c['prix_affiche'] !== null ? (float)$c['prix_affiche'] : null,
            'organisateur_slug' => $c['organisateur_slug'] ?? null, 'organisateur_nom' => clean($c['organisateur_nom']), 'organisateur_logo' => media($c['organisateur_logo']),
            'badge' => $badge, 'sponsorise' => $badge === 'Sponsorisé', 'online' => (bool)$c['inscription_en_ligne'],
            'sessions_count' => (int)($c['sessions_count'] ?? 1),
        ];
        if ($explain) $o['explain'] = ['parts' => $c['_parts'], 'champs' => $c['_why']];
        $out[] = $o;
    }

    // Impressions : les séjours réellement renvoyés à l'écran (une page de résultats)
    mk_track($pdo, array_column($out, 'id'), 'impression', 'recherche');

    // Journal anonyme des recherches (terme normalisé + compteurs, rien de personnel) et mémoire de la recherche pour attribuer la visite suivante
    $term = (!$animCatalogue && $page === 1 && !mk_is_bot()) ? sq_terme_loggable((string)($res['query']['text'] ?? '')) : null;
    if ($term !== null) {
        if (!isset($_SESSION['sq_vus']) || !is_array($_SESSION['sq_vus'])) $_SESSION['sq_vus'] = [];
        $ids = $isFallback ? [] : array_column($out, 'id');
        sq_journaliser($pdo, $term, $isFallback ? 0 : $total, $ids, $_SESSION['sq_vus']);
        $_SESSION['sq_last'] = ['term' => $term, 'ids' => array_slice($ids, 0, 24), 'jour' => $today, 't' => time()];
    }

    $response = [
        'status' => 'success', 'is_fallback' => $isFallback,
        'total' => $total, 'page' => $page, 'limit' => $limit, 'has_more' => $page * $limit < $total,
        'camps' => $out,
        'facets' => sq_facettes_reponse($catalogue, $res['comptes'] ?? ['cats' => [], 'vals' => []], $res['sel'] ?? ['cats' => [], 'fv' => []]),
        'interpretation' => $res['intents'] ?? [], 'relaxed' => !empty($res['relaxed']), 'partiel' => !empty($res['partiel']),
        'did_you_mean' => (!empty($res['dym']) && $res['dym'] !== ($res['query']['text'] ?? '')) ? $res['dym'] : null,
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_camps_recherche: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'La recherche est momentanément indisponible.']);
}
