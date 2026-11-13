<?php
// api/update_camp.php : modification d'un séjour (JSON). Le séjour doit appartenir à un organisme du directeur connecté.
require_once 'config.php';
require_once 'camp_save.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function fin(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) fin(['success' => false, 'message' => 'Accès interdit.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fin(['success' => false, 'message' => 'Méthode non autorisée.'], 405);

$uid = (int)$_SESSION['user']['id'];
try {
    options_ensure($pdo); attente_ensure($pdo);
    // Le séjour est désigné par son jeton secret (jamais par un identifiant numérique)
    $st = $pdo->prepare("SELECT c.id, c.image_url FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id WHERE c.token = ? AND o.user_id = ?");
    $st->execute([(string)($_POST['token'] ?? ''), $uid]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) fin(['success' => false, 'message' => "Ce séjour n'existe pas ou ne vous appartient pas."], 404);
    $campId = (int)$cur['id'];

    [$data, $tarifIds, $err] = camp_lire_formulaire($pdo, $uid, $_POST);
    if ($err) fin(['success' => false, 'message' => $err], 422);

    $data['image_url'] = camp_enregistrer_image() ?: $cur['image_url'];
    // Le contenu a changé : le séjour repasse en validation (comportement existant)
    $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE camps SET $sets, valide = 0, en_attente = 1, refuse = 0 WHERE id = ?")->execute([...array_values($data), $campId]);
    camp_enregistrer_tarifs($pdo, $campId, $tarifIds);
    camp_enregistrer_attente($pdo, $campId, $_POST);
    options_enregistrer($pdo, $campId, $_POST);
    $pdo->commit();

    fin(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_camp: ' . $e->getMessage());
    fin(['success' => false, 'message' => 'Mise à jour impossible, veuillez réessayer.'], 500);
}
