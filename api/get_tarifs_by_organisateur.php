<?php
require_once 'config.php';

if (!isset($_SESSION['user']['id'])) { sendJson(['error' => 'Non connecté'], 403); }

$organisateurId = $_GET['organisateur_id'] ?? null;

try {
    require_once 'tarifs.php';
    tarifs_ensure($pdo);
    // Si un ID est fourni, on filtre directement en SQL (plus performant)
    if ($organisateurId) {
        // seulement les tarifs d'un organisme de l'utilisateur connecté
        $sql = "SELECT t.* FROM tarifs t JOIN organisateurs o ON t.organisateur_id = o.id WHERE t.organisateur_id = ? AND o.user_id = ? ORDER BY t.nom";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$organisateurId, $_SESSION['user']['id']]);
    } else {
        // Sinon (pour compatibilité) on charge les tarifs des orgas de l'user
        $sql = "SELECT t.* FROM tarifs t
                JOIN organisateurs o ON t.organisateur_id = o.id
                WHERE o.user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_SESSION['user']['id']]);
    }
    
    $tarifs = $stmt->fetchAll();

    // On renvoie les données brutes. Le JS mis à jour saura les lire.
    sendJson($tarifs);

} catch (Exception $e) {
    sendJson(['error' => $e->getMessage()], 500);
}
?>