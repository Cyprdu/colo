<?php
// api/apply_boost.php : active un boost (débit des points de l'organisme, transaction verrouillée).
require_once 'config.php';
require_once 'marketing_lib.php';
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expirée']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mk_same_origin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Requête refusée']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = (string)($input['token'] ?? '');
$type = (string)($input['type'] ?? '');
if ($token === '' || $type === '') {
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

try {
    $r = mk_apply_boost($pdo, (int)$_SESSION['user']['id'], $token, $type);
    echo json_encode(['success' => true, 'fin' => $r['fin']]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
