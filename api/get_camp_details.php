<?php
// Fichier: api/get_camp_details.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once 'config.php';
require_once 'sessions.php';

$id = $_GET['id'] ?? 0;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID manquant']);
    exit;
}

try {
    // 1. GESTION VUES
    if (session_status() === PHP_SESSION_NONE) session_start();
    $sessionKey = 'viewed_camp_' . $id;
    if (!isset($_SESSION[$sessionKey])) {
        $pdo->prepare("UPDATE camps SET vues = vues + 1 WHERE id = ?")->execute([$id]);
        $_SESSION[$sessionKey] = true;
    }

    // 2. REQUÊTE CORRIGÉE (o.web au lieu de o.site_web)
    $sql = "
        SELECT 
            c.*, 
            o.nom as orga_nom, 
            o.email as orga_email,
            o.web as orga_website,  /* <-- C'est ici que c'était bloqué */
            o.user_id as organisateur_user_id,
            (SELECT COUNT(*) FROM favoris WHERE camp_id = c.id) as total_likes,
            (SELECT COUNT(*) FROM inscriptions WHERE camp_id = c.id) as nb_inscrits_reel
        FROM camps c
        LEFT JOIN organisateurs o ON c.organisateur_id = o.id
        WHERE c.id = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($camp) {
        // CALCULS
        $inscrits = intval($camp['nb_inscrits_reel']);
        $quota = intval($camp['quota_global']);
        if ($quota <= 0) $quota = 1;

        $pourcentage = round(($inscrits / $quota) * 100);
        $camp['percent_filled'] = min(100, $pourcentage);
        $camp['places_restantes'] = max(0, $quota - $inscrits);
        
        $camp['date_debut_fmt'] = date('d/m/Y', strtotime($camp['date_debut']));
        $camp['date_fin_fmt'] = date('d/m/Y', strtotime($camp['date_fin']));

        // Autres sessions du même camp (même groupe), pour le choix de session
        $camp['sessions'] = [];
        if (campsHaveGroupColumn($pdo) && !empty($camp['groupe_id'])) {
            $stS = $pdo->prepare("SELECT c.id, c.token, c.date_debut, c.date_fin, c.prix, c.quota_global, c.inscription_en_ligne,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.camp_id = c.id) AS nb_inscrits
                FROM camps c
                WHERE c.groupe_id = ? AND ((c.valide = 1 AND c.refuse = 0 AND COALESCE(c.supprime,0) = 0 AND COALESCE(c.prive,0) = 0
                      AND (c.date_fin >= CURDATE() OR c.date_debut >= CURDATE())) OR c.id = ?)
                ORDER BY c.date_debut ASC");
            $stS->execute([$camp['groupe_id'], $camp['id']]);
            foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $q = (int)$r['quota_global'];
                $camp['sessions'][] = [
                    'id' => (int)$r['id'], 'token' => $r['token'],
                    'date_debut' => $r['date_debut'], 'date_fin' => $r['date_fin'],
                    'prix' => (float)$r['prix'],
                    'places_restantes' => ($q > 0 && (int)$r['inscription_en_ligne'] === 1) ? max(0, $q - (int)$r['nb_inscrits']) : null,
                    'is_current' => (int)$r['id'] === (int)$camp['id'],
                ];
            }
        }
        echo json_encode($camp);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Camp introuvable']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur : ' . $e->getMessage()]);
}
?>