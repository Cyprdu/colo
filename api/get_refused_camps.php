<?php
require_once 'config.php';
require_once __DIR__ . '/adm_core.php';
if (!adm_can('camps.read')) { sendJson(['error' => 'Interdit'], 403); }

try {
    $sql = "SELECT c.id, c.nom, c.ville, o.nom as organisateur_nom 
            FROM camps c 
            LEFT JOIN organisateurs o ON c.organisateur_id = o.id
            WHERE c.refuse = 1";
    $stmt = $pdo->query($sql);
    sendJson($stmt->fetchAll());
} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>