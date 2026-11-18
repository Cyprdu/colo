<?php
// api/paiement_logo.php (POST multipart « logo », en-tête X-CSRF-Token) : logo du moyen de paiement « Autre » d'un séjour.
// Renvoie le chemin à enregistrer avec le séjour (champ paiement_autre_logo, vérifié par options_logo_valide).
require_once 'config.php';
require_once 'security.php';
header('Content-Type: application/json; charset=utf-8');

function fin(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) fin(['error' => 'Accès interdit.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) fin(['error' => 'Session expirée : rechargez la page puis réessayez.'], 403);

$f = $_FILES['logo'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1024 * 1024) fin(['error' => 'Logo refusé (PNG, JPG ou WebP, 1 Mo maximum).'], 400);
$ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][@getimagesize($f['tmp_name'])['mime'] ?? ''] ?? null;
if (!$ext) fin(['error' => 'Logo refusé (PNG, JPG ou WebP, 1 Mo maximum).'], 400);
$dir = __DIR__ . '/../uploads/paiements/';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$nom = 'pay_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dir . $nom)) fin(['error' => 'Enregistrement du logo impossible.'], 500);
fin(['path' => 'uploads/paiements/' . $nom]);
