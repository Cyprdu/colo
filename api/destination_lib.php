<?php
// api/destination_lib.php : opérations Stripe propres aux « destination charges » (inclus par finance_lib.php).
// Tous les appels passent par fin_stripe() (remplacé par tests/stripe_stub.php dans les tests) et ont une clé d'idempotence.

/** Charge d'un PaymentIntent (objet), avec son transfert et sa commission (application_fee) */
function fin_charge_of(string $paymentIntent) {
    $ch = fin_stripe()->paymentIntents->retrieve($paymentIntent, ['expand' => ['latest_charge']])->latest_charge ?? null;
    return is_object($ch) ? $ch : null;
}

/**
 * Commission prélevée en trop (code promo, dette déjà soldée par un autre paiement) : rendue à l'organisme (remboursement partiel de l'application fee).
 * $preleve = application_fee_amount demandé à la création de la session. Hors transaction ; un échec est journalisé et visible dans le rapprochement admin.
 */
function fin_fee_excess_refund(PDO $pdo, string $paymentIntent, int $preleve): int {
    $st = $pdo->prepare("SELECT COALESCE(SUM(commission_cents + compensation_cents), 0), MIN(organisateur_id) FROM fin_paiements WHERE payment_intent = ? AND mode = 'destination'");
    $st->execute([$paymentIntent]);
    [$du, $orga] = $st->fetch(PDO::FETCH_NUM);
    $excess = $preleve - (int)$du;
    if ($excess <= 0) return 0;
    try {
        $fee = fin_charge_of($paymentIntent)->application_fee ?? null;
        if (!$fee) throw new Exception('commission Stripe introuvable');
        fin_stripe()->applicationFees->createRefund(is_object($fee) ? $fee->id : (string)$fee, ['amount' => $excess, 'metadata' => ['raison' => 'ajustement commission']], ['idempotency_key' => 'moncamp-fee-' . $paymentIntent]);
        fin_audit($pdo, 'commission_ajustee', 'payment_intent', $paymentIntent, ['rendu' => $excess, 'organisateur' => (int)$orga], 'webhook');
        return $excess;
    } catch (Throwable $e) {
        fin_audit($pdo, 'commission_ajustement_echec', 'payment_intent', $paymentIntent, ['rendu' => $excess, 'erreur' => mb_substr($e->getMessage(), 0, 200)], 'webhook');
        return 0;
    }
}

/**
 * Litige perdu sur un paiement « destination » : Stripe débite moncamp.fr ; on reprend le net à l'organisme (reversement du transfert de la charge).
 * Rejoué par le cron tant que a_reprendre_cents > 0. Retourne le nombre de reprises effectuées.
 */
function fin_reprises_run(PDO $pdo): int {
    $n = 0;
    foreach ($pdo->query("SELECT payment_intent, SUM(a_reprendre_cents) m FROM fin_paiements WHERE mode = 'destination' AND a_reprendre_cents > 0 GROUP BY payment_intent")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try {
            $tr = fin_charge_of((string)$r['payment_intent'])->transfer ?? null;
            if (!$tr) continue;
            $rev = fin_stripe()->transfers->createReversal(is_object($tr) ? $tr->id : (string)$tr, ['amount' => (int)$r['m'], 'metadata' => ['raison' => 'litige perdu']],
                ['idempotency_key' => 'moncamp-reprise-' . $r['payment_intent'] . '-' . $r['m']]);
            $pdo->prepare("UPDATE fin_paiements SET a_reprendre_cents = 0 WHERE payment_intent = ? AND mode = 'destination'")->execute([$r['payment_intent']]);
            fin_audit($pdo, 'reprise_litige', 'payment_intent', $r['payment_intent'], ['cents' => (int)$r['m'], 'stripe' => $rev->id ?? null], 'systeme');
            $n++;
        } catch (Throwable $e) { error_log('fin_reprises_run: ' . $e->getMessage()); }
    }
    return $n;
}

/** payout.paid / payout.failed d'un compte connecté : statut du versement ; un échec remet les lignes en attente (versées au prochain passage). Dans la transaction du webhook. */
function fin_payout_event(PDO $pdo, $po, bool $paid, string $account): void {
    if ($account === '') return; // virements de moncamp.fr vers SA banque : voir compta_sync_payouts
    $st = $pdo->prepare("SELECT * FROM fin_transferts WHERE stripe_transfer_id = ? AND type = 'payout' FOR UPDATE");
    $st->execute([(string)$po->id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) { fin_audit($pdo, $paid ? 'payout_hors_moncamp' : 'payout_echec', 'compte', $account, ['payout' => $po->id, 'montant' => (int)($po->amount ?? 0), 'raison' => $po->failure_message ?? ''], 'webhook'); return; }
    if ($paid) {
        $pdo->prepare("UPDATE fin_transferts SET statut = 'paye' WHERE id = ? AND statut = 'envoye'")->execute([$t['id']]);
        fin_audit($pdo, 'payout_paye', 'transfert', $t['id'], ['payout' => $po->id], 'webhook');
        return;
    }
    if ($t['statut'] === 'echec') return;
    $raison = mb_substr((string)($po->failure_message ?? $po->failure_code ?? 'refusé par la banque'), 0, 450);
    $pdo->prepare("UPDATE fin_transferts SET statut = 'echec', erreur = ? WHERE id = ?")->execute([$raison, $t['id']]);
    $pdo->prepare("UPDATE fin_paiements p JOIN fin_transfert_lignes l ON l.paiement_id = p.id SET p.verse_cents = p.verse_cents - l.montant_cents, p.statut = IF(p.statut = 'verse', 'attente', p.statut)
                   WHERE l.transfert_id = ?")->execute([$t['id']]);
    fin_ledger_add($pdo, 'versement', (int)$t['organisateur_id'], (int)$t['montant_cents'], $po->id . ':echec', 'transfert', $t['id'], 'Versement refusé par la banque', 'webhook', 'destination');
    fin_audit($pdo, 'payout_echec', 'transfert', $t['id'], ['payout' => $po->id, 'raison' => $raison], 'webhook');
}
