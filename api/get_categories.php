<?php
// api/get_categories.php : le catalogue (catégories, précisions, réponses possibles) et la correspondance anciens thèmes -> catégories.
// Public, identique pour tout le monde : mis en cache par le navigateur 10 minutes.
require_once 'config.php';
require_once 'catalogue.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
try {
    $cat = cat_load($pdo);
    $legacy = [];
    try { foreach ($pdo->query("SELECT name FROM camp_types")->fetchAll(PDO::FETCH_COLUMN) as $name) { $g = cat_legacy_guess($cat, (string)$name, ''); if ($g) $legacy[$name] = $g[0]; } } catch (Throwable $e) {}
    foreach ($cat as $c) { $g = cat_legacy_guess($cat, (string)$c['theme'], ''); if ($g && !isset($legacy[$c['theme']])) $legacy[$c['theme']] = $g[0]; }
    $out = cat_pour_js($cat);
    echo json_encode(['categories' => $out, 'legacy' => $legacy, 'max' => CAT_MAX_PAR_SEJOUR], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_categories: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['categories' => [], 'legacy' => []]);
}
