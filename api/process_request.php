<?php
require_once 'config.php';
require_once __DIR__ . '/adm_core.php';
if (!adm_can('demandes.write')) { sendJson(['error' => 'Interdit'], 403); }

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['userId'] ?? 0;
$action = $input['action'] ?? '';

try {
    if ($action === 'accept') {
        $sql = "UPDATE users SET is_directeur = 1, demande_en_cours = 0, is_refused = 0 WHERE id = ?";
    } else {
        $sql = "UPDATE users SET is_directeur = 0, demande_en_cours = 0, is_refused = 1 WHERE id = ?";
    }
    $pdo->prepare($sql)->execute([$userId]);
    sendJson(['success' => true]);
} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>