<?php
// api/stripe_webhook_lib.php : traitement d'un événement Stripe déjà authentifié (appelé par api/stripe_webhook.php ; testé par tests/paiements_integration.php).
// Retourne [code HTTP, texte, actions à faire après la transaction] ; idempotent (table stripe_events), tout dans UNE transaction.

function stripe_webhook_process(PDO $pdo, $event): array {
    fin_ensure($pdo);
    mk_ensure($pdo);

    $feePI = null; // paiement dont on relève les frais Stripe réels, une fois la transaction validée
    $feeAdj = null;
    $copie = null; // destination : PaymentIntent dont on recopie description et métadonnées sur le paiement côté organisme // destination : [payment_intent, commission prélevée] pour rendre un éventuel excédent (code promo)
    try {
        $pdo->beginTransaction();

        // Idempotence : événement déjà traité ?
        $st = $pdo->prepare("INSERT IGNORE INTO stripe_events (event_id, type) VALUES (?, ?)");
        $st->execute([$event->id, $event->type]);
        if ($st->rowCount() === 0) { $pdo->rollBack(); return [200, 'deja traite', []]; }

        $obj = $event->data->object;

        switch ($event->type) {

            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':   // moyens différés (prélèvement...) : payé plus tard
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

                    // Ventilation brut / commission / net par inscription (+ crédit du solde de l'organisme en legacy)
                    $mode = in_array($meta->mode ?? '', ['direct', 'destination'], true) ? (string)$meta->mode : 'legacy'; // direct : événement du compte connecté
                    $comp = $mode !== 'legacy' ? max(0, (int)($meta->compensation ?? 0)) : 0;
                    $n = fin_record_checkout($pdo, $tokens, $pi, (string)$obj->id, $total, $mode, $comp);
                    if ($pi && $n) $feePI = (string)$pi;
                    if ($pi && $mode !== 'legacy') $feeAdj = [(string)$pi, (int)($meta->commission ?? 0) + $comp];
                    if ($pi && $mode === 'destination') $copie = (string)$pi;
                    fin_audit($pdo, 'commande_payee', 'session', $obj->id, ['tokens' => count($tokens), 'total' => $total, 'lignes' => $n], 'webhook');
                    // Inscription de l'équipe d'animation (adultes, hors inscriptions d'enfants) : mêmes règles de commission
                    if (($meta->type ?? '') === 'equipe_inscription') {
                        require_once __DIR__ . '/equipe.php';
                        $ne = equipe_confirmer($pdo, $tokens, $pi, (string)$obj->id, $total, $ratio, $mode, $comp);
                        if ($pi && $ne) $feePI = (string)$pi;
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
                    if (!$won) { $ch = fin_stripe()->charges->retrieve($obj->charge, [], !empty($event->account) ? ['stripe_account' => $event->account] : []); $amount = (int)$ch->amount; }
                    fin_dispute($pdo, (string)$obj->payment_intent, $won ? 'won' : 'lost', $amount);
                }
                break;

            case 'account.updated': // événement d'un compte connecté (endpoint « Comptes connectés »)
                $st = $pdo->prepare("SELECT id FROM organisateurs WHERE stripe_account_id = ?");
                $st->execute([$obj->id]);
                $orgaId = (int)$st->fetchColumn();
                if ($orgaId) fin_store_account($pdo, $orgaId, $obj);
                break;

            case 'payout.created': // versement automatique Stripe d'un compte connecté : relevé avec sa date d'arrivée
            case 'payout.updated':
                fin_payout_record($pdo, $obj, (string)($event->account ?? ''));
                break;

            case 'payout.paid':   // versement bancaire d'un compte connecté arrivé
            case 'payout.failed': // refusé (IBAN invalide...) : les lignes repassent en attente
                fin_payout_event($pdo, $obj, $event->type === 'payout.paid', (string)($event->account ?? ''));
                break;

            case 'transfer.reversed':
                fin_audit($pdo, 'transfert_annule_stripe', 'transfert', $obj->id, ['montant' => (int)($obj->amount ?? 0)], 'webhook');
                break;
        }

        $pdo->commit();
        return [200, 'ok', ['type' => $event->type, 'feePI' => $feePI, 'feeAdj' => $feeAdj, 'copie' => $copie]];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('WEBHOOK ERROR (' . ($event->type ?? '?') . '): ' . $e->getMessage());
        return [500, '', []]; // Stripe réessaiera
    }
}

/** Après la transaction (un échec n'annule rien : le cron rattrape) : reversements, reprises après litige, commission rendue, frais Stripe réels. */
function stripe_webhook_after(PDO $pdo, array $a): void {
    if (!$a) return;
    if (in_array($a['type'], ['charge.refunded', 'charge.dispute.closed'], true)) { try { $r = []; fin_process_reversals($pdo, $r); fin_reprises_run($pdo); } catch (Throwable $e) { error_log('webhook reversement: ' . $e->getMessage()); } }
    if ($a['feeAdj']) fin_fee_excess_refund($pdo, $a['feeAdj'][0], $a['feeAdj'][1]);
    if (!empty($a['copie'])) fin_copy_to_destination($pdo, $a['copie']);
    if ($a['feePI']) { try { fin_sync_fees($pdo, $a['feePI']); } catch (Throwable $e) { error_log('webhook frais Stripe: ' . $e->getMessage()); } }
}
