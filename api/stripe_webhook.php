<?php
// api/stripe_webhook.php : webhook Stripe (signature vérifiée, idempotent, journalisé).
//
// Gère : inscriptions payées, achat de points, expiration de session, remboursements, litiges, état des comptes Connect.
// Idempotence : chaque événement Stripe (event.id) n'est traité qu'une fois (table stripe_events) ; le tout est dans UNE transaction :
// en cas d'erreur, rien n'est enregistré et on répond 500 pour que Stripe réessaie.
// Deux endpoints peuvent pointer ici : « Votre compte » (STRIPE_WEBHOOK_SECRET) et « Comptes connectés » (STRIPE_CONNECT_WEBHOOK_SECRET).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/finance_lib.php';
require_once __DIR__ . '/marketing_lib.php';

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
fin_ensure($pdo);
mk_ensure($pdo);

$feePI = null; // paiement dont on relève les frais Stripe réels, une fois la transaction validée
try {
    $pdo->beginTransaction();

    // Idempotence : événement déjà traité ?
    $st = $pdo->prepare("INSERT IGNORE INTO stripe_events (event_id, type) VALUES (?, ?)");
    $st->execute([$event->id, $event->type]);
    if ($st->rowCount() === 0) { $pdo->rollBack(); http_response_code(200); echo 'deja traite'; exit; }

    $obj = $event->data->object;

    switch ($event->type) {

        case 'checkout.session.completed':
            if (($obj->payment_status ?? '') !== 'paid') break;
            $meta = $obj->metadata;
            $pi = $obj->payment_intent ?? null;

            // --- Achat de points par carte ---
            if (($meta->type ?? '') === 'buy_points') {
                $orgaId = (int)($meta->organisateur_id ?? 0);
                $points = (int)($meta->points_amount ?? 0);
                $pack = null;
                foreach (mk_packs() as $p) if ($p['points'] === $points) $pack = $p;
                if ($orgaId > 0 && $pack && (int)$obj->amount_total === $pack['cents']) {
                    $done = mk_credit_points($pdo, $orgaId, $points, 'carte', (int)$obj->amount_total, 'cs:' . $obj->id);
                    if ($done) fin_audit($pdo, 'points_achetes_carte', 'organisateur', $orgaId, ['points' => $points, 'cents' => (int)$obj->amount_total, 'session' => $obj->id], 'webhook');
                } else {
                    // montant ou pack incohérent : on ne crédite pas, on laisse une trace pour contrôle manuel
                    fin_audit($pdo, 'anomalie_points', 'session', $obj->id, ['orga' => $orgaId, 'points' => $points, 'montant' => (int)$obj->amount_total], 'webhook');
                }
                break;
            }

            // --- Inscriptions payées ---
            if (!empty($meta->reservation_token)) {
                $tokens = array_values(array_filter(array_map('trim', explode(',', (string)$meta->reservation_token))));
                if (!$tokens) break;
                $total = (int)$obj->amount_total;
                $subtotal = (int)($obj->amount_subtotal ?? 0);
                $ratio = $subtotal > 0 ? $total / $subtotal : 1; // un code promo Stripe réduit le total

                $in = implode(',', array_fill(0, count($tokens), '?'));
                $up = $pdo->prepare("UPDATE inscriptions SET statut = 'Confirmé', statut_paiement = 'PAYE', stripe_payment_intent_id = ?,
                                     mode_paiement = 'CARTE', montant_paye = ROUND(prix_final * ?, 2)
                                     WHERE reservation_token IN ($in) AND statut_paiement <> 'PAYE'");
                $up->execute(array_merge([$pi, $ratio], $tokens));

                // Ventilation brut / commission / net par inscription + crédit du solde de l'organisme
                $n = fin_record_checkout($pdo, $tokens, $pi, (string)$obj->id, $total);
                if ($pi && $n) $feePI = (string)$pi;
                fin_audit($pdo, 'commande_payee', 'session', $obj->id, ['tokens' => count($tokens), 'total' => $total, 'lignes' => $n], 'webhook');
                // Inscription de l'équipe d'animation (adultes, hors inscriptions d'enfants) : mêmes règles de commission
                if (($meta->type ?? '') === 'equipe_inscription') {
                    require_once __DIR__ . '/equipe.php';
                    $ne = equipe_confirmer($pdo, $tokens, $pi, (string)$obj->id, $total, $ratio);
                    fin_audit($pdo, 'equipe_payee', 'session', $obj->id, ['total' => $total, 'lignes' => $ne], 'webhook');
                }
            } else {
                error_log('WEBHOOK: checkout.session.completed sans type reconnu (' . $obj->id . ')');
            }
            break;

        case 'checkout.session.expired':
            $meta = $obj->metadata;
            if (!empty($meta->reservation_token)) {
                $tokens = array_values(array_filter(array_map('trim', explode(',', (string)$meta->reservation_token))));
                if ($tokens) {
                    require_once __DIR__ . '/attente.php'; // le directeur peut autoriser l'attente : la place reste gardée jusqu'à la date limite
                    attente_liberer($pdo, $tokens);
                    require_once __DIR__ . '/equipe.php';
                    equipe_liberer($pdo, $tokens);
                }
            }
            break;

        case 'charge.refunded':
            if (!empty($obj->payment_intent)) fin_apply_refund($pdo, (string)$obj->payment_intent, (int)$obj->amount_refunded, (int)$obj->amount);
            break;

        case 'charge.dispute.created':
            if (!empty($obj->payment_intent)) {
                $fee = 0; // frais de litige : portés par les écritures de solde de la contestation
                foreach (($obj->balance_transactions ?? []) as $bt) $fee += max(0, (int)($bt->fee ?? 0));
                fin_dispute($pdo, (string)$obj->payment_intent, 'created', null, $fee);
            }
            break;

        case 'charge.dispute.closed':
            if (!empty($obj->payment_intent)) {
                $won = ($obj->status ?? '') === 'won';
                $amount = 0;
                if (!$won) { $ch = fin_stripe()->charges->retrieve($obj->charge); $amount = (int)$ch->amount; }
                fin_dispute($pdo, (string)$obj->payment_intent, $won ? 'won' : 'lost', $amount);
            }
            break;

        case 'account.updated': // événement d'un compte connecté (endpoint « Comptes connectés »)
            $st = $pdo->prepare("SELECT id FROM organisateurs WHERE stripe_account_id = ?");
            $st->execute([$obj->id]);
            $orgaId = (int)$st->fetchColumn();
            if ($orgaId) fin_store_account($pdo, $orgaId, $obj);
            break;

        case 'payout.failed': // virement bancaire d'un compte connecté refusé (IBAN invalide...) : à signaler
            fin_audit($pdo, 'payout_echec', 'compte', $event->account ?? '', ['montant' => (int)($obj->amount ?? 0), 'raison' => $obj->failure_message ?? ''], 'webhook');
            break;

        case 'transfer.reversed':
            fin_audit($pdo, 'transfert_annule_stripe', 'transfert', $obj->id, ['montant' => (int)($obj->amount ?? 0)], 'webhook');
            break;
    }

    $pdo->commit();
    http_response_code(200);
    echo 'ok';
    // Frais Stripe réels du paiement (hors transaction : un échec n'annule rien, le cron et la page Comptabilité rattrapent)
    if ($feePI) { try { fin_sync_fees($pdo, $feePI); } catch (Throwable $e) { error_log('webhook frais Stripe: ' . $e->getMessage()); } }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('WEBHOOK ERROR (' . ($event->type ?? '?') . '): ' . $e->getMessage());
    http_response_code(500); // Stripe réessaiera
}
