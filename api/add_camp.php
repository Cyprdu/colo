<?php
// api/add_camp.php : création d'un séjour et de toutes ses sessions (une seule transaction, voir camp_groupe.php).
// POST multipart : payload (JSON : common + sessions) et un fichier image_<clé> par session ayant sa propre image.
require_once 'config.php';
require_once 'camp_groupe.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function fin(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) fin(['success' => false, 'message' => 'Non autorisé'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fin(['success' => false, 'message' => 'Méthode non autorisée'], 405);
require_once 'security.php';
if (!csrf_check()) fin(['success' => false, 'message' => 'Session expirée : rechargez la page puis réessayez.'], 403);

try {
    $p = json_decode((string)($_POST['payload'] ?? ''), true);
    if (!is_array($p)) fin(['success' => false, 'message' => "Le formulaire n'a pas pu être lu. Rechargez la page puis réessayez."], 400);
    // Circuit de validation : un séjour créé est EN ATTENTE ; il devient public quand l'équipe l'approuve (admin_camp_requests)
    $r = camp_sauver_groupe($pdo, (int)$_SESSION['user']['id'], '', $p);
    fin(['success' => true, 'token' => $r['principal'], 'tokens' => $r['tokens'], 'motscles_refuses' => $r['motscles_refuses']]);
} catch (CampException $e) {
    fin(['success' => false, 'message' => $e->getMessage(), 'confirm' => $e->confirm], $e->confirm ? 409 : 422);
} catch (Throwable $e) {
    error_log('add_camp: ' . $e->getMessage());
    fin(['success' => false, 'message' => "Enregistrement impossible, veuillez réessayer."], 500);
}
