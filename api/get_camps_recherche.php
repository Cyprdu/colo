<?php
// api/get_camps_recherche.php : moteur de la page de recherche.
// Filtres : name, city, age, sexe (Homme|Femme), themes[], max_price, date_from, date_to, online, sort, page/limit.
// Ne renvoie que les champs utiles à l'affichage d'une carte (aucune donnée interne du séjour).
require_once 'config.php';
require_once 'sessions.php';
require_once 'organismes.php';
orga_ensure($pdo);
require_once 'options.php';
require_once 'attente.php';
require_once 'marketing_lib.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

function clean($str) { return htmlspecialchars_decode($str ?? ''); }
function media($path, $dir = '') {
    $path = trim((string)$path);
    if ($path === '') return null;
    $path = str_replace('\\', '/', $path);
    if (preg_match('#^(https?:)?//#', $path) || strpos($path, 'uploads/') === 0) return $path;
    return $dir . ltrim($path, '/');
}
function validDay($s) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

try {
    $today = date('Y-m-d');
    $isFallback = false;

    $baseWhere = " WHERE c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND c.supprime = 0 AND c.prive = 0
                   AND COALESCE(c.date_limite_inscription, c.date_debut) >= :today ";
    $params = [':today' => $today];
    // Catalogue réservé aux animateurs (api/anim_catalogue.php) : uniquement les séjours qui recrutent, à venir
    if (defined('ANIM_CATALOGUE')) {
        $baseWhere = " WHERE c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND c.supprime = 0 AND c.recrute_animateurs = 1 AND c.date_debut >= :today ";
    }

    $priceExpr = "COALESCE((SELECT MIN(t.prix) FROM camps_tarifs ct JOIN tarifs t ON ct.tarif_id = t.id WHERE ct.camp_id = c.id), c.prix)";

    $relevanceField = "0 AS relevance_score";
    $term = trim((string)($_GET['name'] ?? ''));
    if ($term !== '') {
        $relevanceField = "((CASE WHEN c.nom LIKE :term THEN 50 ELSE 0 END) + (CASE WHEN c.type LIKE :term THEN 30 ELSE 0 END)
            + (CASE WHEN c.theme LIKE :term THEN 20 ELSE 0 END) + (CASE WHEN c.ville LIKE :term THEN 12 ELSE 0 END)
            + (CASE WHEN c.description LIKE :term THEN 3 ELSE 0 END)) AS relevance_score";
        $params[':term'] = '%' . $term . '%';
    }

    $select = "SELECT c.id, c.token, c.nom, c.ville, c.code_postal, c.image_url, c.date_debut, c.date_fin, c.age_min, c.age_max,
            c.type, c.theme, c.groupe_id, c.inscription_en_ligne, c.date_bump, c.date_creation, c.quota_global,
            c.boost_vedette_fin, c.boost_urgence_fin,
            (c.quota_global - (SELECT COUNT(*) FROM inscriptions i WHERE i.camp_id = c.id AND i.statut NOT IN ('Annulé', 'annule', 'Annule'))) AS nb_places_restantes,
            o.nom AS organisateur_nom, o.logo_url AS organisateur_logo, o.slug AS organisateur_slug,
            $priceExpr AS prix_affiche,
            (c.boost_vedette_fin > NOW()) AS is_vedette, (c.boost_urgence_fin > NOW()) AS is_urgence,
            {REL}
            FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id $baseWhere";

    $sql = str_replace('{REL}', $relevanceField, $select);
    if ($term !== '') $sql .= " AND (c.nom LIKE :term OR c.type LIKE :term OR c.theme LIKE :term OR c.ville LIKE :term OR c.description LIKE :term)";
    $city = trim((string)($_GET['city'] ?? ''));
    if ($city !== '') { $sql .= " AND (c.ville LIKE :city OR c.code_postal LIKE :city)"; $params[':city'] = '%' . $city . '%'; }
    if (isset($_GET['max_price']) && is_numeric($_GET['max_price']) && (float)$_GET['max_price'] > 0) {
        $sql .= " AND $priceExpr <= :max"; $params[':max'] = (float)$_GET['max_price'];
    }
    if (isset($_GET['age']) && $_GET['age'] !== '' && is_numeric($_GET['age'])) {
        $sql .= " AND c.age_min <= :age AND c.age_max >= :age"; $params[':age'] = (int)$_GET['age'];
    }
    // Période : le séjour doit chevaucher l'intervalle demandé
    $from = validDay($_GET['date_from'] ?? '');
    $to = validDay($_GET['date_to'] ?? '');
    if ($from) { $sql .= " AND COALESCE(c.date_fin, c.date_debut) >= :dfrom"; $params[':dfrom'] = $from; }
    if ($to)   { $sql .= " AND c.date_debut <= :dto"; $params[':dto'] = $to; }
    if (!empty($_GET['online'])) $sql .= " AND c.inscription_en_ligne = 1";
    // Sexe de l'enfant (Homme / Femme) : ni séjour réservé à l'autre sexe, ni quota de son sexe plein
    $sexe = options_sexe_param($_GET['sexe'] ?? '');
    $sexeSql = '';
    if ($sexe) { options_ensure($pdo); attente_ensure($pdo); $sexeSql = ' AND ' . options_sexe_sql('s'); $sql .= $sexeSql; $params[':sx_s'] = $sexe; }
    if (!empty($_GET['themes']) && is_array($_GET['themes'])) {
        $parts = [];
        foreach (array_slice(array_values($_GET['themes']), 0, 30) as $k => $v) {
            $parts[] = "(c.theme LIKE :th_$k OR c.type LIKE :th_$k)";
            $params[":th_$k"] = '%' . trim((string)$v) . '%';
        }
        if ($parts) $sql .= " AND (" . implode(' OR ', $parts) . ")";
    }

    // Tri explicite (prix, date) : respecté tel quel. Tri par pertinence : score calculé plus bas (boosts inclus).
    $sort = $_GET['sort'] ?? 'relevance';
    if ($sort === 'price_asc') $sql .= " ORDER BY prix_affiche ASC";
    elseif ($sort === 'price_desc') $sql .= " ORDER BY prix_affiche DESC";
    elseif ($sort === 'date') $sql .= " ORDER BY c.date_debut ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $camps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aucun résultat : on propose les séjours mis en avant (les filtres ne sont pas appliqués)
    if (empty($camps) && !defined('ANIM_CATALOGUE')) {
        $isFallback = true;
        $stmtFb = $pdo->prepare(str_replace('{REL}', '0 AS relevance_score', $select) . $sexeSql . " AND c.boost_vedette_fin > NOW() ORDER BY c.date_bump DESC LIMIT 12");
        $stmtFb->execute($sexe ? [':today' => $today, ':sx_s' => $sexe] : [':today' => $today]);
        $camps = $stmtFb->fetchAll(PDO::FETCH_ASSOC);
    }

    // Classement par pertinence : score organique x effet des boosts (voir mk_score)
    if (!in_array($sort, ['price_asc', 'price_desc', 'date'], true)) {
        $ctr = mk_ctr_map($pdo, array_column($camps, 'id'));
        foreach ($camps as &$cc) {
            $cc['ctr30'] = $ctr[(int)$cc['id']] ?? 0.05;
            $sc = mk_score($cc);
            $cc['_score'] = $sc['score']; $cc['_sponsor'] = $sc['sponsorise'];
        }
        unset($cc);
        usort($camps, function ($a, $b) { return $b['_score'] <=> $a['_score']; });
    }

    // Un camp à plusieurs sessions n'apparaît qu'une fois (sa prochaine session)
    $camps = collapseSessions($camps);
    $total = count($camps);

    $limit = max(1, min(48, (int)($_GET['limit'] ?? 24)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $camps = array_slice($camps, ($page - 1) * $limit, $limit);

    $out = [];
    foreach ($camps as $c) {
        $badge = !empty($c['_sponsor']) || $c['is_vedette'] ? 'Sponsorisé' : ($c['is_urgence'] ? 'Dernière minute' : null);
        $out[] = [
            'id' => (int)$c['id'], 'token' => $c['token'],
            'nom' => clean($c['nom']), 'ville' => clean($c['ville']),
            'image_url' => media($c['image_url'], 'uploads/camps/'),
            'date_debut' => $c['date_debut'], 'date_fin' => $c['date_fin'],
            'age_min' => $c['age_min'] !== null ? (int)$c['age_min'] : null, 'age_max' => $c['age_max'] !== null ? (int)$c['age_max'] : null,
            'type_name' => clean($c['type'] ?? ''), 'theme' => clean($c['theme'] ?? ''),
            'prix' => $c['prix_affiche'] !== null ? (float)$c['prix_affiche'] : null,
            'organisateur_slug' => $c['organisateur_slug'] ?? null, 'organisateur_nom' => clean($c['organisateur_nom']), 'organisateur_logo' => media($c['organisateur_logo']),
            'badge' => $badge, 'sponsorise' => $badge === 'Sponsorisé', 'online' => (bool)$c['inscription_en_ligne'],
            'sessions_count' => (int)($c['sessions_count'] ?? 1),
        ];
    }

    // Impressions : les séjours réellement renvoyés à l'écran (une page de résultats)
    mk_track($pdo, array_column($out, 'id'), 'impression', 'recherche');

    echo json_encode([
        'status' => 'success', 'is_fallback' => $isFallback,
        'total' => $total, 'page' => $page, 'limit' => $limit, 'has_more' => $page * $limit < $total,
        'camps' => $out,
    ]);
} catch (Throwable $e) {
    error_log('get_camps_recherche: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'La recherche est momentanément indisponible.']);
}
