<?php
// api/create_stripe_boost_session.php : achat d'un pack de points par carte (Stripe Checkout).
// Les prix viennent de mk_packs() : jamais du navigateur. Le crédit est fait par le webhook (idempotent).
require_once 'config.php';
require_once 'marketing_lib.php';
require_once __DIR__ . '/../vendor/autoload.php';
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mk_same_origin()) { http_response_code(403); echo json_encode(['error' => 'Requête refusée']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$packKey = (string)($input['pack'] ?? '');
$token = $input['token'] ?? null;
$organisateurId = (int)($input['organisateur_id'] ?? 0);

$packs = mk_packs();
if (!isset($packs[$packKey]) || (!$token && !$organisateurId)) {
    echo json_encode(['error' => 'Données manquantes ou pack invalide']);
    exit;
}
$baseUrl = rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr/', '/');

try {
    if ($token) {
        $stmt = $pdo->prepare("SELECT o.id, o.nom FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id WHERE c.token = ? AND o.user_id = ?");
        $stmt->execute([(string)$token, $_SESSION['user']['id']]);
        $success = $baseUrl . '/boost.php?t=' . urlencode((string)$token) . '&success=true';
        $cancel = $baseUrl . '/boost.php?t=' . urlencode((string)$token);
    } else {
        $stmt = $pdo->prepare("SELECT id, nom FROM organisateurs WHERE id = ? AND user_id = ?");
        $stmt->execute([$organisateurId, $_SESSION['user']['id']]);
        $success = $baseUrl . '/marketing.php?id=' . $organisateurId . '&success=true';
        $cancel = $baseUrl . '/marketing.php?id=' . $organisateurId;
    }
    $orga = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$orga) throw new Exception('Organisme introuvable ou non autorisé');
    $pack = $packs[$packKey];

    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $checkout = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'locale' => 'fr',
        'line_items' => [[
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $pack['cents'],
                'product_data' => ['name' => $pack['name'] . ' (' . $pack['points'] . ' pts)', 'description' => mb_substr('Crédits marketing ColoMap pour : ' . $orga['nom'], 0, 300)],
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => $success,
        'cancel_url' => $cancel,
        'metadata' => ['type' => 'buy_points', 'organisateur_id' => (string)$orga['id'], 'points_amount' => (string)$pack['points'], 'pack' => $packKey],
    ]);
    echo json_encode(['id' => $checkout->id, 'url' => $checkout->url]);
} catch (Throwable $e) {
    error_log('create_stripe_boost_session: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Paiement momentanément indisponible.']);
}
