<?php
// api/add_camp.php : création d'un séjour (JSON). Toute la validation est faite ici, côté serveur.
require_once 'config.php';
require_once 'camp_save.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function fin(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) fin(['success' => false, 'message' => 'Non autorisé'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fin(['success' => false, 'message' => 'Méthode non autorisée'], 405);

try {
    options_ensure($pdo); attente_ensure($pdo); etapes_ensure($pdo); champs_ensure($pdo);
    [$data, $tarifIds, $err, $classement] = camp_lire_formulaire($pdo, (int)$_SESSION['user']['id'], $_POST);
    if ($err) fin(['success' => false, 'message' => $err], 422);

    // Séjour qui a lieu plusieurs fois : sessions supplémentaires validées avant toute écriture
    $sessions = [];
    if (!empty($_POST['plusieurs_fois'])) {
        [$sessions, $errS] = camp_lire_sessions($pdo, (int)$_SESSION['user']['id'], $_POST, $tarifIds);
        if ($errS) fin(['success' => false, 'message' => $errS], 422);
    }

    $image = camp_enregistrer_image();
    if (!$image) fin(['success' => false, 'message' => 'Ajoutez une image de couverture (JPG, PNG ou WebP, 8 Mo maximum).'], 422);

    $data['image_url'] = $image;
    // Circuit de validation : le séjour est créé EN ATTENTE ; il devient public quand l'équipe l'approuve (admin_camp_requests)
    $data += ['valide' => 0, 'en_attente' => 1, 'refuse' => 0, 'supprime' => 0];
    $data = camp_filtrer_colonnes($pdo, $data);
    $data['token'] = bin2hex(random_bytes(16));
    $cols = array_keys($data);
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO camps (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ", date_creation, date_bump) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ", NOW(), NOW())")
        ->execute(array_values($data));
    $campId = (int)$pdo->lastInsertId();
    camp_enregistrer_tarifs($pdo, $campId, $tarifIds);
    camp_enregistrer_attente($pdo, $campId, $_POST);
    options_enregistrer($pdo, $campId, $_POST);
    etapes_enregistrer($pdo, $campId, !empty($data['itinerant']), $_POST['etapes'] ?? '[]');
    camp_enregistrer_classement($pdo, $campId, $classement);
    if ($sessions) camp_enregistrer_sessions($pdo, (int)$_SESSION['user']['id'], ['id' => $campId, 'groupe_id' => null, 'valide' => 0, 'en_attente' => 1, 'refuse' => 0], $data, $_POST, $sessions);
    $pdo->commit();
    require_once __DIR__ . '/security.php'; sec_trace($pdo, (int)$_SESSION['user']['id'], 'sejour_cree', $campId);

    require_once 'geocode.php';
    $q = $pdo->prepare("SELECT id FROM camps WHERE id = ? OR groupe_id = ?"); $q->execute([$campId, $campId]);
    geo_actualiser($pdo, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));

    fin(['success' => true, 'token' => $data['token'], 'camp_id' => $campId, 'motscles_refuses' => $classement['motscles']['refus'] ?? []]);
} catch (CampException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fin(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('add_camp: ' . $e->getMessage());
    fin(['success' => false, 'message' => "Enregistrement impossible, veuillez réessayer."], 500);
}
