<?php
require_once 'config.php';
require_once __DIR__ . '/adm_core.php';
if (!adm_can('demandes.read')) { sendJson(['error' => 'Interdit'], 403); }

try {
    $stmt = $pdo->query("SELECT id, nom, prenom, email as mail, tel FROM users WHERE demande_en_cours = 1");
    sendJson($stmt->fetchAll());
} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>