<?php
// api/payer_points_sepa.php (POST JSON : pack, o, idem ; en-tête X-CSRF-Token) : achat d'un pack de points par prélèvement SEPA (mandat enregistré).
// Les points sont crédités à réception du prélèvement (webhook payment_intent.succeeded).
require_once 'config.php';
require_once 'security.php';
require_once 'finance_lib.php';
require_once 'marketing_lib.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
function pps_out(int $c, array $d): void { http_response_code($c); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) pps_out(401, ['error' => 'Session expirée']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mk_same_origin() || !csrf_check()) pps_out(403, ['error' => 'Requête refusée. Rechargez la page.']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$orgaId = fin_orga_from_tok($pdo, (string)($in['o'] ?? ''), (int)$_SESSION['user']['id']);
$packs = mk_packs(); $key = (string)($in['pack'] ?? ''); $idem = preg_replace('/[^a-f0-9]/', '', (string)($in['idem'] ?? ''));
if (!$orgaId || !isset($packs[$key]) || strlen($idem) < 16) pps_out(400, ['error' => 'Données invalides.']);
try {
    $p = $packs[$key];
    $pi = fin_sepa_prelever($pdo, $orgaId, (int)$p['cents'], ['type' => 'buy_points', 'points_amount' => (string)$p['points'], 'pack' => $key], 'moncamp.fr : ' . $p['name'] . ' (' . $p['points'] . ' points)', 'moncamp-points-sepa-' . $orgaId . '-' . $idem);
    fin_audit($pdo, 'points_prelevement_sepa', 'organisateur', $orgaId, ['pack' => $key, 'pi' => $pi->id], 'user:' . (int)$_SESSION['user']['id']);
    pps_out(200, ['ok' => true, 'message' => 'Prélèvement lancé : les points seront crédités à sa réception (quelques jours ouvrés).']);
} catch (Throwable $e) {
    error_log('payer_points_sepa: ' . $e->getMessage());
    pps_out(200, ['error' => $e instanceof \Stripe\Exception\ApiErrorException ? 'Prélèvement momentanément indisponible.' : $e->getMessage()]);
}
