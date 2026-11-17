<?php
// api/save_champs.php : le directeur enregistre les champs d'inscription de son séjour (JSON).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/champs.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) out(['error' => 'Accès interdit.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Méthode non autorisée.'], 405);

$in = json_decode(file_get_contents('php://input'), true);
$campId = (int)($in['camp_id'] ?? 0);
$campToken = (string)($in['token'] ?? '');   // l'adresse à jeton est préférée à l'identifiant numérique
$champs = $in['champs'] ?? null;
if ((!$campId && $campToken === '') || !is_array($champs)) out(['error' => 'Données invalides.'], 400);
if (count($champs) > 30) out(['error' => '30 champs maximum.'], 400);

// Le séjour doit appartenir à un organisateur de l'utilisateur
$st = $pdo->prepare("SELECT c.* FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id WHERE " . ($campToken !== '' ? "c.token = ?" : "c.id = ?") . " AND o.user_id = ?");
$st->execute([$campToken !== '' ? $campToken : $campId, $_SESSION['user']['id']]);
$campRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$campRow) out(['error' => "Ce séjour n'a pas été trouvé dans votre compte."], 404);
$campId = (int)$campRow['id'];

try {
    champs_ensure($pdo);
    $pdo->beginTransaction();
    champs_enregistrer($pdo, $campId, $champs);
    $pdo->commit();
    out(['ok' => true, 'champs' => champs_du_camp($pdo, $campId)]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('save_champs: ' . $e->getMessage());
    out(['error' => "Enregistrement impossible."], 500);
}
