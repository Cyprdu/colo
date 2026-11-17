<?php
// Fichier: api/get_child_full_details.php
require_once 'config.php';

if (!isset($_SESSION['user']['id']) || !$_SESSION['user']['is_directeur']) {
    sendJson(['error' => 'Accès interdit'], 403);
}

$resToken = (string)($_GET['r'] ?? '');
$inscriptionId = (int)($_GET['id'] ?? 0);

try {
    // On récupère TOUT via l'ID de l'inscription pour vérifier le lien avec le camp du directeur
    $stmt = $pdo->prepare("
        SELECT 
            e.*, 
            c.nom as camp_nom,
            i.id AS inscription_id,
            i.date_inscription,
            i.montant_paye,
            i.statut_paiement
        FROM inscriptions i
        JOIN enfants e ON i.enfant_id = e.id
        JOIN camps c ON i.camp_id = c.id
        JOIN organisateurs o ON c.organisateur_id = o.id
        WHERE " . ($resToken !== '' ? 'i.reservation_token = ?' : 'i.id = ?') . " AND o.user_id = ?
    ");
    
    $stmt->execute([$resToken !== '' ? $resToken : $inscriptionId, $_SESSION['user']['id']]);
    $data = $stmt->fetch();

    if (!$data) {
        sendJson(['error' => 'Dossier introuvable ou accès refusé.'], 404);
    }

    require_once __DIR__ . '/champs.php';
    $data['reponses'] = champs_reponses($pdo, (int)$data['inscription_id']);   // réponses aux questions du directeur

    sendJson($data);

} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>