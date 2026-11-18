<?php
// api/buy_points_wallet.php : achat d'un pack de points directement avec l'argent que l'organisme a gagné sur moncamp.fr
// (solde « à recevoir », net de commission). Aucun paiement par carte. Opération atomique et verrouillée :
// débit du solde + crédit des points + écriture dans le journal d'audit.
require_once 'config.php';
require_once 'marketing_lib.php';
require_once 'finance_lib.php';
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Session expirée']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mk_same_origin()) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Requête refusée']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$packs = mk_packs();
$packKey = (string)($in['pack'] ?? '');
$orgaId = (int)($in['organisateur_id'] ?? 0);
if (!isset($packs[$packKey]) || !$orgaId) { echo json_encode(['success' => false, 'message' => 'Données invalides']); exit; }
$pack = $packs[$packKey];

try {
    fin_ensure($pdo);
    mk_ensure($pdo);
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT id, portefeuille FROM organisateurs WHERE id = ? AND user_id = ? FOR UPDATE");
    $st->execute([$orgaId, $_SESSION['user']['id']]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new Exception('Organisme introuvable.');
    $dispo = fin_points_budget_cents($pdo, $orgaId);
    if ($dispo < $pack['cents']) throw new Exception('Votre solde utilisable (' . number_format($dispo / 100, 2, ',', ' ') . ' €) est insuffisant pour ce pack.');

    $ref = 'wallet-' . $orgaId . '-' . bin2hex(random_bytes(8));
    $pdo->prepare("UPDATE organisateurs SET portefeuille = portefeuille - ? WHERE id = ?")->execute([$pack['cents'] / 100, $orgaId]);
    mk_credit_points($pdo, $orgaId, $pack['points'], 'portefeuille', $pack['cents'], $ref);
    fin_audit($pdo, 'points_sur_ca', 'organisateur', $orgaId, ['pack' => $packKey, 'points' => $pack['points'], 'cents' => $pack['cents'], 'ref' => $ref], 'user:' . $_SESSION['user']['id']);
    $pdo->commit();

    $st = $pdo->prepare("SELECT portefeuille, solde_points FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'solde_points' => (int)$n['solde_points'], 'portefeuille' => (float)$n['portefeuille']]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
