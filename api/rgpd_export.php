<?php
// api/rgpd_export.php : télécharge toutes ses données personnelles (JSON). Connecté, avec jeton CSRF.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/rgpd_lib.php';

if (empty($_SESSION['user']['id'])) { http_response_code(403); exit('Connexion requise.'); }
if (!csrf_check()) { http_response_code(400); exit('Requête invalide. Rechargez la page « Mes données » et réessayez.'); }
$uid = (int)$_SESSION['user']['id'];
if (sec_blocked($pdo, 'export', (string)$uid, 5, 60)) { http_response_code(429); exit('Trop de demandes. Réessayez dans une heure.'); }
sec_hit($pdo, 'export', (string)$uid);

try {
    $data = rgpd_export($pdo, $uid);
} catch (Throwable $e) {
    error_log('rgpd_export: ' . $e->getMessage());
    http_response_code(500); exit('Export momentanément indisponible.');
}
consent_log($pdo, $uid, 'export_donnees', null, true);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="mes-donnees-colomap-' . date('Y-m-d') . '.json"');
header('Cache-Control: private, no-store');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
