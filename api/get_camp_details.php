<?php
// Fichier: api/get_camp_details.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once 'config.php';
require_once __DIR__ . '/adm_core.php';
require_once 'sessions.php';
require_once 'organismes.php';
orga_ensure($pdo);
require_once 'marketing_lib.php';

$tokenParam = (string)($_GET['t'] ?? '');
$idParam = (int)($_GET['id'] ?? 0);

if ($tokenParam === '' && !$idParam) {
    http_response_code(400);
    echo json_encode(['error' => 'Séjour manquant']);
    exit;
}

try {
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Résolution du séjour. Le JETON (secret) ouvre n'importe quel séjour de son porteur.
    // L'identifiant numérique, lui, est devinable : il ne sert que pour un séjour public,
    // ou pour son propriétaire / un administrateur.
    if ($tokenParam !== '') {
        $stmt = $pdo->prepare("SELECT id FROM camps WHERE token = ?");
        $stmt->execute([$tokenParam]);
        $id = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT c.id, c.valide, c.refuse, c.supprime, c.prive, o.user_id AS owner_id
                               FROM camps c LEFT JOIN organisateurs o ON o.id = c.organisateur_id WHERE c.id = ?");
        $stmt->execute([$idParam]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $uid = $_SESSION['user']['id'] ?? null;
        $isAdmin = adm_can('camps.read');
        $isOwner = $r && $uid && (int)$r['owner_id'] === (int)$uid;
        $public = $r && !empty($r['valide']) && empty($r['refuse']) && empty($r['supprime']) && empty($r['prive']);
        $id = ($r && ($public || $isOwner || $isAdmin)) ? (int)$r['id'] : 0;
    }
    if (!$id) {
        http_response_code(404);
        echo json_encode(['error' => 'Camp introuvable']);
        exit;
    }

    // 1. GESTION VUES
    if (session_status() === PHP_SESSION_NONE) session_start();
    $sessionKey = 'viewed_camp_' . $id;
    if (!isset($_SESSION[$sessionKey])) {
        $pdo->prepare("UPDATE camps SET vues = vues + 1 WHERE id = ?")->execute([$id]);
        $_SESSION[$sessionKey] = true;
        // Clic : une visite = un clic, rattachée à la surface d'où vient le visiteur (recherche, accueil...)
        mk_track($pdo, [$id], 'clic', mk_surface_from_referer());
        // Visite venue d'une recherche : on l'attribue au terme tapé (compteur anonyme, voir api/search_stats.php)
        if (!empty($_SESSION['sq_last']) && !mk_is_bot()) { require_once __DIR__ . '/search_stats.php'; sq_clic_terme($pdo, $id, $_SESSION['sq_last']); }
    }

    // 2. REQUÊTE CORRIGÉE (o.web au lieu de o.site_web)
    $sql = "
        SELECT 
            c.*, 
            o.nom as orga_nom, o.slug as orga_slug, 
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
            $stS = $pdo->prepare("SELECT c.id, c.token, c.date_debut, c.date_fin, c.prix, c.quota_global, c.inscription_en_ligne, c.age_min, c.age_max, c.reserve_sexe,
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
                    'age_min' => $r['age_min'] !== null ? (int)$r['age_min'] : null, 'age_max' => $r['age_max'] !== null ? (int)$r['age_max'] : null, 'reserve_sexe' => $r['reserve_sexe'] ?? null,
                    'places_restantes' => ($q > 0 && (int)$r['inscription_en_ligne'] === 1) ? max(0, $q - (int)$r['nb_inscrits']) : null,
                    'is_current' => (int)$r['id'] === (int)$camp['id'],
                ];
            }
        }
        // Position exacte pour la carte : relue en base, géocodée une seule fois (à l'enregistrement ou à la première visite)
        require_once 'geocode.php';
        if (!array_key_exists('lat', $camp)) { geo_ensure($pdo); $camp['lat'] = null; $camp['lng'] = null; $camp['geo_hash'] = null; $camp['geo_precis'] = 0; }
        if (($camp['geo_hash'] ?? '') !== geo_hash($camp)) {
            $p = geo_position($camp);
            $camp['lat'] = $p ? $p[0] : null; $camp['lng'] = $p ? $p[1] : null; $camp['geo_precis'] = $p && $p[2] ? 1 : 0; $camp['geo_hash'] = geo_hash($camp);
            try { $pdo->prepare('UPDATE camps SET lat = ?, lng = ?, geo_hash = ?, geo_precis = ? WHERE id = ?')->execute([$camp['lat'], $camp['lng'], $camp['geo_hash'], $camp['geo_precis'], $camp['id']]); } catch (Throwable $e) { error_log('geo: ' . $e->getMessage()); }
        }
        unset($camp['geo_hash']);
        require_once 'etapes.php';
        $camp['etapes'] = !empty($camp['itinerant']) ? etapes_du_camp($pdo, (int)$camp['id']) : [];
        unset($camp['organisateur_id']); // jamais d'id numérique d'organisme vers le navigateur
        echo json_encode($camp);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Camp introuvable']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ce séjour est momentanément indisponible.']);
}
?>