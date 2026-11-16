<?php
require_once 'config.php';
if (!isset($_SESSION['user']['is_admin']) || !$_SESSION['user']['is_admin']) { sendJson(['error' => 'Interdit'], 403); }

try {
    $stmt = $pdo->query("SELECT id, nom, prenom, email as mail FROM users WHERE is_directeur = 1");
    sendJson($stmt->fetchAll());
} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>