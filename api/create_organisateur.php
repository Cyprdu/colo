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
    try { require_once __DIR__ . '/organismes.php'; orga_assign_slug($pdo, (int)$newId); } catch (Throwable $e) { error_log('create_organisateur (adresse): ' . $e->getMessage()); }
    try { require_once __DIR__ . '/finance_lib.php'; fin_ensure($pdo); } catch (Throwable $e) { error_log('create_organisateur (jour de versement): ' . $e->getMessage()); } // attribue le jour de versement hebdomadaire

    // Alias de l'organisme (« PAJE ») : contrôlés et enregistrés à part, un refus n'empêche jamais la création
    $alias = ['motscles' => [], 'motscles_refuses' => []];
    if (array_key_exists('motscles', $input)) {
        try { require_once __DIR__ . '/motscles.php'; $alias = mc_sauver_orga($pdo, (int)$newId, (string)$input['nom'], $input['motscles']); } catch (Throwable $e) { error_log('create_organisateur (alias): ' . $e->getMessage()); }
    }

    // RÉPONSE PROPRE (SQL style)
    sendJson([
        'id' => $newId,
        'nom' => $input['nom'],
        'email' => $email
    ] + $alias, 201);

} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>