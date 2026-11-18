<?php
require_once 'config.php';
require_once __DIR__ . '/adm_core.php';
if (!adm_can('camps.read')) { sendJson(['count' => 0]); }

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM camps WHERE en_attente = 1 AND refuse = 0");
    sendJson(['count' => $stmt->fetchColumn()]);
} catch (Exception $e) {
    sendJson(['count' => 0]);
}
?>