<?php
require_once 'config.php';

if (!isset($_SESSION['user']['id']) || !$_SESSION['user']['is_directeur']) {
    sendJson(['error' => 'Accès interdit'], 403);
}
require_once __DIR__ . '/security.php';
if (!csrf_check()) sendJson(['error' => 'Session expirée : rechargez la page puis réessayez.'], 403);

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['nom']) || empty($input['organisateur_id'])) {
    sendJson(['error' => 'Données incomplètes.'], 400);
}

try {
    require_once 'tarifs.php';
    require_once 'orga_lib.php';
    $orgaTok = (string)$input['organisateur_id'];
    $input['organisateur_id'] = fin_orga_from_tok($pdo, $orgaTok, (int)$_SESSION['user']['id']); // jeton public -> id
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
        'organisateur_id' => $orgaTok
    ], 201);

} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>