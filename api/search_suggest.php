<?php
// api/search_suggest.php?q=pas : autocomplétion légère (alias, organismes, catégories, séjours, villes) + « Vouliez-vous dire ».
// Rapide : aucun calcul de séjour, uniquement le vocabulaire gardé en cache (fichier, 10 minutes) ; réponse mise en cache par le navigateur 2 minutes.
// Limitée : 120 appels par minute et par adresse (api/security.php), au-delà : 429 sans résultat.
require_once 'config.php';
require_once 'security.php';
require_once 'search_lib.php';

header('Content-Type: application/json; charset=utf-8');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
if (mb_strlen($q) < 2) { echo json_encode(['items' => [], 'dym' => null]); exit; }

try {
    if (sec_blocked($pdo, 'suggest', sec_ip(), 120, 1)) {
        http_response_code(429);
        header('Retry-After: 30');
        echo json_encode(['items' => [], 'dym' => null]);
        exit;
    }
    sec_hit($pdo, 'suggest', sec_ip());
    $vocab = sq_vocab($pdo, cat_load($pdo));
    header('Cache-Control: public, max-age=120');
    echo json_encode(sq_suggerer($vocab, $q, 8), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('search_suggest: ' . $e->getMessage());
    echo json_encode(['items' => [], 'dym' => null]);
}
