<?php
require_once 'config.php';

if (!isset($_SESSION['user']['id']) || !$_SESSION['user']['is_directeur']) {
    sendJson(['error' => 'Accès interdit'], 403);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['nom']) || empty($input['organisateur_id'])) {
    sendJson(['error' => 'Données incomplètes.'], 400);
}

try {
    require_once 'tarifs.php';
    tarifs_ensure($pdo);
    // l'organisateur doit appartenir à l'utilisateur connecté
    $own = $pdo->prepare("SELECT id FROM organisateurs WHERE id = ? AND user_id = ?");
    $own->execute([$input['organisateur_id'], $_SESSION['user']['id']]);
    if (!$own->fetch()) sendJson(['error' => 'Organisme introuvable.'], 403);
    $libre = !empty($input['montant_libre']) ? 1 : 0;
    $min = $libre && (float)($input['montant_min'] ?? 0) > 0 ? round((float)$input['montant_min'], 2) : null;
    $stmt = $pdo->prepare("INSERT INTO tarifs (nom, prix, montant_libre, montant_min, organisateur_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        mb_substr(trim((string)$input['nom']), 0, 120),
        max(0, (float)($input['prix'] ?? 0)),
        $libre,
        $min,
        $input['organisateur_id']
    ]);
    
    $newId = $pdo->lastInsertId();

    // RÉPONSE PROPRE
    sendJson([
        'id' => $newId,
        'nom' => $input['nom'],
        'prix' => $input['prix'] ?? 0,
        'montant_libre' => $libre,
        'montant_min' => $min,
        'organisateur_id' => $input['organisateur_id']
    ], 201);

} catch (Exception $e) {
    sendJson(['error' => $e->getMessage()], 500);
}
?>