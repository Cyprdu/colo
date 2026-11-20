<?php
// api/sante_site.php?key=CRON_SECRET : état du site pour un service de surveillance externe (UptimeRobot…). 200 si tout va bien, 503 sinon.
// (api/sante.php est déjà la bibliothèque des fiches santé des enfants.)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/surveillance_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$key = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? ''));
if (!defined('CRON_SECRET') || CRON_SECRET === '' || !hash_equals((string)CRON_SECRET, $key)) { http_response_code(403); exit('{"erreur":"interdit"}'); }
try {
    $e = sv_etat($pdo);
    $e['alerte'] = sv_alerter($pdo, $e);
} catch (Throwable $ex) {
    error_log('sante_site: ' . $ex->getMessage());
    $e = ['ok' => false, 'db' => ['ok' => false]];
}
http_response_code($e['ok'] ? 200 : 503);
echo json_encode(['statut' => $e['ok'] ? 'OK' : 'PROBLEME', 'date' => date('c')] + $e, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
