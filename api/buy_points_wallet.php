<?php
// api/buy_points_wallet.php : achat d'un pack de points avec les gains de l'organisme (solde « à recevoir », net de commission).
// Sécurité : session directeur + même origine + jeton CSRF + jeton d'idempotence ; toute la logique d'argent est dans gains_lib.php
// (transaction, verrou de ligne, journal en ajout seulement, refus si solde insuffisant, rejeu impossible).
require_once 'config.php';
require_once 'security.php';
require_once 'gains_lib.php';
require_once 'orga_lib.php';
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

function bpw_out(int $code, array $d): void { http_response_code($code); echo json_encode($d); exit; }

if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) bpw_out(401, ['success' => false, 'message' => 'Session expirée']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mk_same_origin() || !csrf_check()) bpw_out(403, ['success' => false, 'message' => 'Requête refusée. Rechargez la page et réessayez.']);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$userId = (int)$_SESSION['user']['id'];
$orgaId = fin_orga_from_tok($pdo, (string)($in['o'] ?? ''), $userId);

try {
    $r = gains_buy_points($pdo, $userId, $orgaId, (string)($in['pack'] ?? ''), (string)($in['idem'] ?? ''), sec_ip());
} catch (Throwable $e) {
    bpw_out(200, ['success' => false, 'message' => $e->getMessage()]);
}
if (!$r['ok']) {
    bpw_out(200, ['success' => false, 'message' => 'Solde insuffisant : ' . number_format($r['solde_avant_cents'] / 100, 2, ',', ' ') . ' € disponibles.']);
}
$st = $pdo->prepare("SELECT portefeuille, solde_points FROM organisateurs WHERE id = ?");
$st->execute([$orgaId]);
$n = $st->fetch(PDO::FETCH_ASSOC);
if (!$r['replay']) { @require_once 'mail_config.php'; gains_send_receipt($pdo, $userId, $r); }
echo json_encode(['success' => true, 'replay' => $r['replay'], 'ref' => 'G-' . $r['journal_id'], 'solde_points' => (int)$n['solde_points'], 'portefeuille' => (float)$n['portefeuille']]);
