<?php
require_once 'config.php';
require_once __DIR__ . '/adm_core.php';
if (!adm_can('demandes.read')) { sendJson(['count' => 0]); }

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE demande_en_cours = 1");
    sendJson(['count' => $stmt->fetchColumn()]);
} catch (Exception $e) {
    sendJson(['count' => 0]);
}
?>