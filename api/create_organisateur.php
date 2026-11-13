<?php
require_once 'config.php';

// Sécurité
if (!isset($_SESSION['user']['id']) || !$_SESSION['user']['is_directeur']) {
    sendJson(['error' => 'Accès interdit'], 403);
}

$input = json_decode(file_get_contents('php://input'), true);

// Supporte 'email' ou 'mail' venant du JS
$email = $input['email'] ?? $input['mail'] ?? '';

if (empty($input['nom']) || empty($email)) {
    sendJson(['error' => 'Le nom et l\'email sont obligatoires.'], 400);
}

try {
    $stmt = $pdo->prepare("INSERT INTO organisateurs (nom, tel, email, web, user_id, portefeuille) VALUES (?, ?, ?, ?, ?, 0)");
    $stmt->execute([
        $input['nom'],
        $input['tel'] ?? '',
        $email,
        $input['web'] ?? '',
        $_SESSION['user']['id']
    ]);
    
    $newId = $pdo->lastInsertId();
    try { require_once __DIR__ . '/finance_lib.php'; fin_ensure($pdo); } catch (Throwable $e) { error_log('create_organisateur (jour de versement): ' . $e->getMessage()); } // attribue le jour de versement hebdomadaire

    // RÉPONSE PROPRE (SQL style)
    sendJson([
        'id' => $newId,
        'nom' => $input['nom'],
        'email' => $email
    ], 201);

} catch (Exception $e) {
    sendJson(['error' => $e->getMessage()], 500);
}
?>