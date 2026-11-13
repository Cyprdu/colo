<?php
// api/create_stripe_session.php : « Payer maintenant » pour UNE inscription déjà créée et encore à régler.
// Le montant est celui enregistré en base (prix_final) : jamais un montant venu du navigateur.

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/sessions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');

if (!isset($_SESSION['user']['id'])) { header('Location: ' . $site . '/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $site . '/reservations'); exit; }
if (!defined('STRIPE_SECRET_KEY')) { http_response_code(500); exit('Paiement indisponible : configuration manquante.'); }
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$inscriptionId = (int)($_POST['inscription_id'] ?? 0);

try {
    // L'inscription doit appartenir à un enfant du parent connecté
    $stmt = $pdo->prepare("SELECT i.id, i.prix_final, i.statut_paiement, i.reservation_token, i.statut,
                                  e.prenom, e.nom, c.nom AS camp_nom, c.token AS camp_token, c.date_debut, c.date_fin,
                                  c.valide, c.refuse, c.supprime, c.date_limite_inscription
                           FROM inscriptions i
                           JOIN enfants e ON e.id = i.enfant_id
                           JOIN camps c ON c.id = i.camp_id
                           WHERE i.id = ? AND e.parent_id = ?");
    $stmt->execute([$inscriptionId, $_SESSION['user']['id']]);
    $i = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$i || $i['statut_paiement'] !== 'EN_ATTENTE' || in_array($i['statut'], ['Annulé', 'annule', 'Annule'], true)) {
        header('Location: ' . $site . '/reservations'); exit;
    }
    if (empty($i['valide']) || !empty($i['refuse']) || !empty($i['supprime'])) { header('Location: ' . $site . '/reservations'); exit; }
    $cents = (int)round((float)$i['prix_final'] * 100);
    if ($cents < 50) { header('Location: ' . $site . '/reservations'); exit; }

    [$s1, $s2] = normalizeSessionDates($i['date_debut'], $i['date_fin']);
    $label = 'Du ' . $s1->format('d/m') . ' au ' . $s2->format('d/m/Y');
    $token = $i['reservation_token'];

    $params = [
        'payment_method_types' => ['card'],
        'mode' => 'payment',
        'locale' => 'fr',
        'allow_promotion_codes' => true,
        'expires_at' => time() + 1800 + 60,
        'client_reference_id' => (string)$_SESSION['user']['id'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name' => mb_substr('Inscription : ' . $i['prenom'] . ' ' . $i['nom'], 0, 250),
                    'description' => mb_substr($i['camp_nom'] . ' · ' . $label, 0, 500),
                ],
                'unit_amount' => $cents,
            ],
            'quantity' => 1,
        ]],
        'success_url' => $site . '/inscription_confirmation.php?success=1&camp_t=' . urlencode($i['camp_token']),
        'cancel_url'  => $site . '/reservations',
        'metadata' => ['reservation_token' => $token, 'user_id' => (string)$_SESSION['user']['id'], 'type' => 'multi_inscription'],
    ];
    if (!empty($_SESSION['user']['email'])) $params['customer_email'] = $_SESSION['user']['email'];

    $checkout = \Stripe\Checkout\Session::create($params);
    $pdo->prepare("UPDATE inscriptions SET stripe_session_id = ? WHERE id = ?")->execute([$checkout->id, $i['id']]);

    header('Location: ' . $checkout->url, true, 303);
    exit;
} catch (Throwable $e) {
    error_log('create_stripe_session: ' . $e->getMessage());
    header('Location: ' . $site . '/reservations');
    exit;
}
