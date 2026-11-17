<?php
// api/marquer_recu.php (POST) : l'organisateur confirme avoir reçu le règlement hors carte d'une inscription.
// Paramètres : csrf, t = jeton de réservation (jamais d'identifiant numérique). Réponse JSON. Voir fin_marquer_recu() pour les contrôles de propriété.
require_once 'config.php';
require_once 'finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Méthode non autorisée.']); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { http_response_code(403); echo json_encode(['error' => 'Accès interdit.']); exit; }
if (!fin_csrf_ok()) { http_response_code(419); echo json_encode(['error' => 'Session expirée, rechargez la page.']); exit; }

[$ok, $msg] = fin_marquer_recu($pdo, (string)($_POST['t'] ?? ''), (int)$_SESSION['user']['id']);
if (!$ok) http_response_code(400);
echo json_encode($ok ? ['ok' => true, 'message' => $msg] : ['error' => $msg]);
