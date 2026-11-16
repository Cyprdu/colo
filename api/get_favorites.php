<?php
// Fichier: /api/get_favorites.php
require_once 'config.php';
require_once 'organismes.php';
orga_ensure($pdo);

if (!isset($_SESSION['user']['id'])) {
    sendJson(['error' => 'Non connecté'], 403);
}

try {
    $sql = "SELECT c.*, o.nom AS organisateur_nom, o.logo_url AS organisateur_logo, o.slug AS organisateur_slug FROM camps c
            JOIN favoris f ON c.id = f.camp_id
            LEFT JOIN organisateurs o ON o.id = c.organisateur_id
            WHERE f.user_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$_SESSION['user']['id']]);
    $camps = $stmt->fetchAll();

    $formatted = array_map(function($camp) {
        return [
            'id' => $camp['id'],
            'token' => $camp['token'], // AJOUT INDISPENSABLE
            'nom' => $camp['nom'],
            'ville' => $camp['ville'],
            'prix' => $camp['prix'],
            'age_min' => $camp['age_min'],
            'age_max' => $camp['age_max'],
            'date_debut' => $camp['date_debut'],
            'date_fin' => $camp['date_fin'],
            'organisateur_slug' => $camp['organisateur_slug'] ?? null,
            'organisateur_nom' => $camp['organisateur_nom'],
            'organisateur_logo' => $camp['organisateur_logo'],
            'image_url' => $camp['image_url']
        ];
    }, $camps);

    sendJson($formatted);

} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>