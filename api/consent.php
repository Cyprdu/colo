<?php
// api/consent.php (POST JSON {ref, a, p}) : preuve du choix cookies (assets/consent.js) dans la table consentements, une ligne par finalité.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
header('Content-Type: application/json; charset=utf-8');

$d = json_decode((string)file_get_contents('php://input'), true);
$ref = is_array($d) ? (string)($d['ref'] ?? '') : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !preg_match('/^[a-f0-9]{16}$/D', $ref)) { http_response_code(400); echo '{"error":"Requête invalide."}'; exit; }
if (sec_blocked($pdo, 'consent', sec_ip(), 30, 10)) { http_response_code(429); echo '{"error":"Trop de requêtes."}'; exit; }
sec_hit($pdo, 'consent', sec_ip());

$uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
consent_log($pdo, $uid, 'cookies_audience', CGU_VERSION, !empty($d['a']), $ref);
consent_log($pdo, $uid, 'cookies_publicite', CGU_VERSION, !empty($d['p']), $ref);
echo '{"ok":true}';
