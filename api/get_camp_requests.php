<?php
// Fichier: /api/get_camp_requests.php
require_once 'config.php';
require_once __DIR__ . '/adm_core.php';

// Vérification Admin
if (!adm_can('camps.read')) { 
    sendJson(['error' => 'Interdit'], 403); 
}

try {
    // MODIFICATION ICI : On ajoute "c.token" dans le SELECT
    $sql = "SELECT c.id, c.nom, c.ville, c.code_postal, c.token, o.nom as organisateur_nom 
            FROM camps c 
            LEFT JOIN organisateurs o ON c.organisateur_id = o.id
            WHERE c.en_attente = 1 AND c.refuse = 0";
            
    $stmt = $pdo->query($sql);
    sendJson($stmt->fetchAll());

} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>