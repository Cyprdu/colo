<?php
// api/stripe_webhook.php : webhook Stripe (signature vérifiée, idempotent, journalisé).
//
// Gère : inscriptions payées (legacy ou destination charges), achat de points, expiration de session, remboursements, litiges, état des comptes Connect,
// versements bancaires des organismes (payout.paid / payout.failed). Chaque mouvement d'argent est aussi écrit dans fin_ledger.
// Idempotence : chaque événement Stripe (event.id) n'est traité qu'une fois (table stripe_events) ; le tout est dans UNE transaction :
// en cas d'erreur, rien n'est enregistré et on répond 500 pour que Stripe réessaie.
// Deux endpoints peuvent pointer ici : « Votre compte » (STRIPE_WEBHOOK_SECRET) et « Comptes connectés » (STRIPE_CONNECT_WEBHOOK_SECRET).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/finance_lib.php';
require_once __DIR__ . '/marketing_lib.php';
require_once __DIR__ . '/stripe_webhook_lib.php';

$secrets = array_values(array_filter([
    defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '',
    defined('STRIPE_CONNECT_WEBHOOK_SECRET') ? STRIPE_CONNECT_WEBHOOK_SECRET : '',
]));
if (!$secrets) { error_log('Stripe webhook : aucun secret de signature défini'); http_response_code(500); exit; }

$payload = (string)@file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;
foreach ($secrets as $secret) {
    try { $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret); break; }
    catch (\Throwable $e) { $event = null; }
}
if (!$event) { http_response_code(400); exit; }

global $pdo;
$r = stripe_webhook_process($pdo, $event);
http_response_code($r[0]);
echo $r[1];
stripe_webhook_after($pdo, $r[2]);
