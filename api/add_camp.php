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
    options_ensure($pdo); attente_ensure($pdo); etapes_ensure($pdo);
    [$data, $tarifIds, $err] = camp_lire_formulaire($pdo, (int)$_SESSION['user']['id'], $_POST);
    if ($err) fin(['success' => false, 'message' => $err], 422);

    $image = camp_enregistrer_image();
    if (!$image) fin(['success' => false, 'message' => 'Ajoutez une image de couverture (JPG, PNG ou WebP, 8 Mo maximum).'], 422);

    $data['image_url'] = $image;
    $data['token'] = bin2hex(random_bytes(16));
    $cols = array_keys($data);
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO camps (" . implode(',', $cols) . ", valide, date_creation, date_bump) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ", 1, NOW(), NOW())")
        ->execute(array_values($data));
    $campId = (int)$pdo->lastInsertId();
    camp_enregistrer_tarifs($pdo, $campId, $tarifIds);
    camp_enregistrer_attente($pdo, $campId, $_POST);
    options_enregistrer($pdo, $campId, $_POST);
    etapes_enregistrer($pdo, $campId, !empty($data['itinerant']), $_POST['etapes'] ?? '[]');
    $pdo->commit();

    fin(['success' => true, 'token' => $data['token'], 'camp_id' => $campId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('add_camp: ' . $e->getMessage());
    fin(['success' => false, 'message' => "Enregistrement impossible, veuillez réessayer."], 500);
}
