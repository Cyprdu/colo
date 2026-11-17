<?php
// api/session_config.php : configuration complète d'UNE session du séjour (GET ?t=jeton), pour l'onglet de session du formulaire.
// Réservé au propriétaire du séjour.
require_once 'config.php';
require_once 'camp_groupe.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function fin(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) fin(['error' => 'Accès interdit.'], 403);

try {
    options_ensure($pdo); attente_ensure($pdo); etapes_ensure($pdo); champs_ensure($pdo);
    $st = $pdo->prepare("SELECT c.* FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id WHERE c.token = ? AND o.user_id = ? AND COALESCE(c.supprime, 0) = 0");
    $st->execute([(string)($_GET['t'] ?? ''), (int)$_SESSION['user']['id']]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) fin(['error' => "Cette session n'existe pas ou ne vous appartient pas."], 404);
    fin(['ok' => true, 'snap' => camp_snapshot($pdo, $c)]);
} catch (Throwable $e) {
    error_log('session_config: ' . $e->getMessage());
    fin(['error' => 'Impossible de charger cette session pour le moment.'], 500);
}
