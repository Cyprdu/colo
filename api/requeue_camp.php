<?php
require_once 'config.php';
require_once __DIR__ . '/adm_core.php';
if (!adm_can('camps.write')) { sendJson(['error' => 'Interdit'], 403); }

$input = json_decode(file_get_contents('php://input'), true);
$campId = $input['campId'] ?? null;

try {
    $pdo->prepare("UPDATE camps SET refuse = 0, en_attente = 1 WHERE id = ?")->execute([$campId]);
    sendJson(['success' => true]);
} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>