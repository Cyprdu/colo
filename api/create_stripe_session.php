<?php
// api/create_stripe_session.php : « Payer » une réservation déjà créée et encore à régler.
// POST r=<jeton de réservation> : règle TOUTES les inscriptions en attente de la même commande.
// Les montants sont ceux enregistrés en base (prix_final) : jamais un montant venu du navigateur.

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/orders.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');

if (!isset($_SESSION['user']['id'])) { header('Location: ' . $site . '/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $site . '/reservations'); exit; }
if (!defined('STRIPE_SECRET_KEY')) { http_response_code(500); exit('Paiement indisponible : configuration manquante.'); }
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$userId = (int)$_SESSION['user']['id'];
$r = (string)($_POST['r'] ?? '');

try {
    // Ancien formulaire (inscription_id) : on retrouve le jeton si l'inscription appartient au parent
    if ($r === '' && isset($_POST['inscription_id'])) {
        $st = $pdo->prepare("SELECT i.reservation_token FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id WHERE i.id = ? AND e.parent_id = ?");
        $st->execute([(int)$_POST['inscription_id'], $userId]);
        $r = (string)$st->fetchColumn();
    }
    $members = loadOrderByToken($pdo, $r, $userId);
    $back = $site . '/reservation?r=' . urlencode($r);
    if (!$members) { header('Location: ' . $site . '/reservations'); exit; }

    $first = $members[0];
    if (empty($first['valide']) || !empty($first['refuse']) || !empty($first['supprime']) || empty($first['inscription_en_ligne'])) { header('Location: ' . $back . '&err=closed'); exit; }

    $pending = array_values(array_filter($members, function ($m) { return !isCancelled($m) && $m['statut_paiement'] === 'EN_ATTENTE'; }));
    if (!$pending) { header('Location: ' . $back); exit; }
    // Délai d'attente fixé par le directeur dépassé : la place n'est plus réservée
    $now = new DateTime();
    $pending = array_values(array_filter($pending, function ($m) use ($now) { $l = attente_limite($m); return $l && $l > $now; }));
    if (!$pending) { header('Location: ' . $back . '&err=expired'); exit; }

    // Les places sont-elles toujours disponibles ? (on ne compte pas les inscriptions qu'on règle)
    $quota = (int)($pdo->query("SELECT quota_global FROM camps WHERE id = " . (int)$first['camp_id'])->fetchColumn());
    if ($quota > 0) {
        $ids = array_map(function ($m) { return (int)$m['inscription_id']; }, $pending);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $active = attente_clause($first);
        $st = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE camp_id = ? AND id NOT IN ($in) AND $active");
        $st->execute(array_merge([$first['camp_id']], $ids));
        if ((int)$st->fetchColumn() + count($pending) > $quota) { header('Location: ' . $back . '&err=full'); exit; }
    }

    $label = 'Du ' . $first['_start']->format('d/m') . ' au ' . $first['_end']->format('d/m/Y');
    $lineItems = []; $tokens = [];
    foreach ($pending as $m) {
        $cents = (int)round((float)$m['prix_final'] * 100);
        if ($cents < 1) continue;
        $tokens[] = $m['reservation_token'];
        $lineItems[] = [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name' => mb_substr('Inscription : ' . $m['enfant_prenom'] . ' ' . $m['enfant_nom'], 0, 250),
                    'description' => mb_substr($m['camp_nom'] . ' · ' . $label . ' · ' . ($m['tarif_nom'] ?: 'Tarif standard'), 0, 500),
                ],
                'unit_amount' => $cents,
            ],
            'quantity' => 1,
        ];
    }
    $total = array_sum(array_map(function ($li) { return $li['price_data']['unit_amount']; }, $lineItems));
    if (!$lineItems || $total < 50) { header('Location: ' . $back . '&err=amount'); exit; }

    $tokensString = implode(',', $tokens);
    $params = [
        'payment_method_types' => ['card'],
        'mode' => 'payment',
        'locale' => 'fr',
        'allow_promotion_codes' => true,
        'expires_at' => time() + 1800 + 60,
        'client_reference_id' => (string)$userId,
        'line_items' => $lineItems,
        'success_url' => $site . '/inscription_confirmation.php?success=1&camp_t=' . urlencode($first['camp_token']),
        'cancel_url'  => $back,   // annulation : la réservation reste en l'état, on revient dessus
        'metadata' => ['reservation_token' => $tokensString, 'camp_id' => (string)$first['camp_id'], 'user_id' => (string)$userId, 'type' => 'multi_inscription'],
        'payment_intent_data' => ['description' => 'ColoMap · ' . mb_substr($first['camp_nom'], 0, 120) . ' · ' . $label, 'metadata' => ['reservation_token' => $tokensString]],
    ];
    if (!empty($_SESSION['user']['email'])) $params['customer_email'] = $_SESSION['user']['email'];

    $checkout = \Stripe\Checkout\Session::create($params);

    $in = implode(',', array_fill(0, count($tokens), '?'));
    $pdo->prepare("UPDATE inscriptions SET stripe_session_id = ?, date_inscription = NOW() WHERE reservation_token IN ($in)")
        ->execute(array_merge([$checkout->id], $tokens));

    header('Location: ' . $checkout->url, true, 303);
    exit;
} catch (Throwable $e) {
    error_log('create_stripe_session: ' . $e->getMessage());
    header('Location: ' . $site . '/reservations');
    exit;
}
