<?php
// api/destination_lib.php : opérations Stripe des paiements sur compte connecté, « direct » ou « destination » (inclus par finance_lib.php).
// Tous les appels passent par fin_stripe() (remplacé par tests/stripe_stub.php dans les tests) et ont une clé d'idempotence.

/**
 * Rembourse $amount (centimes) d'un paiement ($p : ligne fin_paiements). Aucune commission rendue (refund_application_fee = false).
 * Direct : le remboursement est pris sur le solde Stripe de l'organisme ; s'il ne suffit pas, moncamp.fr avance le manque (transfert vers son compte)
 * et l'organisme a une dette (solde négatif + ligne « dette » au journal), prélevée avec la commission de ses paiements suivants.
 * Destination : reverse_transfer (l'organisme est débité). Legacy : remboursement simple. Retourne l'identifiant du remboursement Stripe.
 */
function fin_refund(PDO $pdo, array $p, int $amount, array $meta, string $idem): string {
    $params = ['payment_intent' => $p['payment_intent'], 'amount' => $amount, 'metadata' => $meta]; $opts = ['idempotency_key' => $idem];
    $mode = $p['mode'] ?? 'legacy';
    if ($mode === 'destination') $params += ['reverse_transfer' => true, 'refund_application_fee' => false];
    if ($mode === 'direct') {
        $st = $pdo->prepare("SELECT stripe_account_id FROM organisateurs WHERE id = ?"); $st->execute([$p['organisateur_id']]);
        $acct = (string)$st->fetchColumn();
        $params['refund_application_fee'] = false; $opts['stripe_account'] = $acct;
        $b = fin_stripe()->balance->retrieve([], ['stripe_account' => $acct]); $solde = 0;
        foreach (array_merge($b->available ?? [], $b->pending ?? []) as $x) if (strtolower((string)$x->currency) === 'eur') $solde += (int)$x->amount;
        $manque = $amount - max(0, $solde);
        if ($manque > 0) { // avance de moncamp.fr : l'organisme nous doit $manque
            $tr = fin_stripe()->transfers->create(['amount' => $manque, 'currency' => 'eur', 'destination' => $acct, 'description' => 'moncamp.fr : avance pour un remboursement (dette)',
                'metadata' => ['organisateur_id' => (string)$p['organisateur_id'], 'raison' => 'avance_remboursement']], ['idempotency_key' => $idem . '-avance']);
            if (fin_ledger_add($pdo, 'dette', (int)$p['organisateur_id'], $manque, 'avance:' . $idem, 'paiement', $p['id'], 'Remboursement avancé par moncamp.fr', 'systeme', 'direct'))
                $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) - ? WHERE id = ?")->execute([$manque / 100, $p['organisateur_id']]);
            fin_audit($pdo, 'dette_avance', 'organisateur', $p['organisateur_id'], ['cents' => $manque, 'solde_stripe' => $solde, 'transfert' => $tr->id ?? null], 'systeme');
        }
    }
    return (string)fin_stripe()->refunds->create($params, $opts)->id;
}

// ---------------------------------------------------------------------------------------------
// Paiement lisible dans le Stripe de l'organisme : description courte + métadonnées (jamais de données de santé)
// ---------------------------------------------------------------------------------------------
/** Période courte : « 12–18 juil. », « 28 juil.–3 août » */
function fin_periode_courte(?string $debut, ?string $fin): string {
    if (!$debut) return '';
    $m = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $d = strtotime($debut); $f = $fin ? strtotime($fin) : $d;
    $fmt = fn($t) => date('j', $t) . ' ' . $m[(int)date('n', $t) - 1];
    if ($f <= $d) return $fmt($d);
    return date('n', $d) === date('n', $f) ? date('j', $d) . '–' . $fmt($f) : $fmt($d) . '–' . $fmt($f);
}

/**
 * Paramètres lisibles d'un Checkout (à fusionner) : payment_intent_data.description « Séjour · 12–18 juil. · Léa D. » et métadonnées
 * Séjour, Session, Enfant(s), Parent, Email parent, Commission moncamp.fr, Inscriptions. Limites Stripe : 50 clés, 500 caractères par valeur.
 * $personnes : [[prénom, nom], ...] ; $libPersonnes : « Enfant(s) » ou « Équipe d'animation ».
 */
function fin_lisible_params(string $camp, ?string $debut, ?string $fin, array $personnes, string $parent, string $email, int $comCents, array $tokens, string $libPersonnes = 'Enfant(s)'): array {
    $camp = html_entity_decode($camp, ENT_QUOTES, 'UTF-8');
    $court = implode(', ', array_map(fn($p) => trim($p[0] . ' ' . mb_substr((string)$p[1], 0, 1) . '.'), $personnes));
    $v = fn($s) => mb_substr(trim((string)$s), 0, 500);
    $meta = array_filter([
        'Séjour' => $v($camp),
        'Session' => $v($debut ? 'du ' . date('d/m/Y', strtotime($debut)) . ($fin ? ' au ' . date('d/m/Y', strtotime($fin)) : '') : ''),
        $libPersonnes => $v(implode(', ', array_map(fn($p) => trim($p[0] . ' ' . $p[1]), $personnes))),
        'Parent' => $v($parent),
        'Email parent' => $v($email),
        'Commission moncamp.fr' => $v(number_format($comCents / 100, 2, ',', ' ') . ' €'),
        'Inscriptions' => $v(implode(',', $tokens)),
    ], fn($x) => $x !== '');
    return ['payment_intent_data' => ['description' => mb_substr(implode(' · ', array_filter([$camp, fin_periode_courte($debut, $fin), $court])), 0, 500), 'metadata' => $meta]];
}

/** Paramètres lisibles d'une commande d'inscriptions d'enfants (jetons de réservation d'un même séjour) */
function fin_lisible(PDO $pdo, array $tokens): array {
    if (!$tokens) return [];
    try {
        $in = implode(',', array_fill(0, count($tokens), '?'));
        $st = $pdo->prepare("SELECT i.prix_final, e.prenom, e.nom, c.nom camp, c.date_debut, c.date_fin, u.prenom up, u.nom un, u.email
                             FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id JOIN camps c ON c.id = i.camp_id LEFT JOIN users u ON u.id = e.parent_id
                             WHERE i.reservation_token IN ($in) ORDER BY i.id");
        $st->execute(array_values($tokens));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('fin_lisible: ' . $e->getMessage()); return []; } // jamais bloquant pour le paiement
    if (!$rows) return [];
    $com = 0; foreach ($rows as $r) $com += fin_commission_split((int)round((float)$r['prix_final'] * 100))[0];
    $r = $rows[0];
    return fin_lisible_params((string)$r['camp'], $r['date_debut'], $r['date_fin'] ?? null, array_map(fn($x) => [(string)$x['prenom'], (string)$x['nom']], $rows),
        trim($r['up'] . ' ' . $r['un']), (string)$r['email'], $com, $tokens);
}

/**
 * Destination charges : le paiement côté organisme (charge.transfer → destination_payment, py_…) n'hérite de rien ; on y recopie la description et les
 * métadonnées du PaymentIntent. Idempotent (mêmes valeurs, clé d'idempotence), hors transaction, un échec est seulement journalisé.
 */
function fin_copy_to_destination(PDO $pdo, string $paymentIntent): bool {
    try {
        $pi = fin_stripe()->paymentIntents->retrieve($paymentIntent, ['expand' => ['latest_charge.transfer']]);
        $tr = $pi->latest_charge->transfer ?? null;
        $py = is_object($tr) ? ($tr->destination_payment ?? null) : null;
        if (!$py || !is_object($tr)) throw new Exception('paiement côté organisme introuvable');
        $meta = json_decode(json_encode($pi->metadata ?? []), true) ?: [];
        fin_stripe()->charges->update(is_object($py) ? $py->id : (string)$py, ['description' => (string)($pi->description ?? ''), 'metadata' => $meta],
            ['stripe_account' => (string)$tr->destination, 'idempotency_key' => 'moncamp-copie-' . $paymentIntent]);
        return true;
    } catch (Throwable $e) {
        fin_audit($pdo, 'copie_metadonnees_echec', 'payment_intent', $paymentIntent, ['erreur' => mb_substr($e->getMessage(), 0, 200)], 'webhook');
        return false;
    }
}

/** Charge d'un PaymentIntent (objet), avec son transfert et sa commission (application_fee) */
function fin_charge_of(string $paymentIntent, ?string $account = null) {
    $ch = fin_stripe()->paymentIntents->retrieve($paymentIntent, ['expand' => ['latest_charge']], $account ? ['stripe_account' => $account] : [])->latest_charge ?? null;
    return is_object($ch) ? $ch : null;
}

/**
 * Commission prélevée en trop (code promo, dette déjà soldée par un autre paiement) : rendue à l'organisme (remboursement partiel de l'application fee).
 * $preleve = application_fee_amount demandé à la création de la session. Hors transaction ; un échec est journalisé et visible dans le rapprochement admin.
 */
function fin_fee_excess_refund(PDO $pdo, string $paymentIntent, int $preleve): int {
    $st = $pdo->prepare("SELECT COALESCE(SUM(p.commission_cents + p.compensation_cents + p.frais_estimes_cents), 0), MIN(p.organisateur_id), MAX(IF(p.mode = 'direct', o.stripe_account_id, NULL))
                         FROM fin_paiements p JOIN organisateurs o ON o.id = p.organisateur_id WHERE p.payment_intent = ? AND p.mode <> 'legacy'");
    $st->execute([$paymentIntent]);
    [$du, $orga, $acct] = $st->fetch(PDO::FETCH_NUM);
    $excess = $preleve - (int)$du;
    if ($excess <= 0) return 0;
    try {
        $fee = fin_charge_of($paymentIntent, $acct ?: null)->application_fee ?? null;
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
    if (!$t) { fin_payout_record($pdo, $po, $account); return; } // versement automatique Stripe
    if ($paid) {
        $pdo->prepare("UPDATE fin_transferts SET statut = 'paye', arrivee = COALESCE(?, arrivee) WHERE id = ? AND statut = 'envoye'")->execute([fin_payout_arrivee($po), $t['id']]);
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

// ---------------------------------------------------------------------------------------------
// Versements bancaires des organismes : AUTOMATIQUES (Stripe, quotidiens, délai minimal). moncamp.fr les relève et les journalise.
// (fin_payouts_run / fin_prepare_payout : ancien pilotage manuel, plus appelé par le cron, gardé pour l'historique)
// ---------------------------------------------------------------------------------------------
/** Passe le compte en versements automatiques quotidiens (délai minimal Stripe) et demande les paiements par carte. Idempotent. */
function fin_account_setup(PDO $pdo, int $orgaId, string $accountId): bool {
    try {
        fin_stripe()->accounts->update($accountId, ['settings' => ['payouts' => ['schedule' => ['interval' => 'daily', 'delay_days' => 'minimum']]], 'capabilities' => ['card_payments' => ['requested' => true]]]);
        $pdo->prepare("UPDATE stripe_comptes SET payout_manuel = 0, payout_auto = 1 WHERE organisateur_id = ?")->execute([$orgaId]);
        fin_audit($pdo, 'compte_versements_auto', 'organisateur', $orgaId, ['account' => $accountId], 'systeme');
        return true;
    } catch (Throwable $e) { error_log('fin_account_setup: ' . $e->getMessage()); return false; }
}

/** Date d'arrivée sur la banque annoncée par Stripe (payout.arrival_date) */
function fin_payout_arrivee($po): ?string { return !empty($po->arrival_date) ? date('Y-m-d', (int)$po->arrival_date) : null; }

/**
 * Relève un Payout automatique d'un compte connecté (webhook payout.* ou fin_payouts_sync). Idempotent : verrou par payout, une ligne fin_transferts,
 * référence unique au journal. Nouveau versement : réparti sur les paiements en attente de l'organisme (les plus anciens d'abord) et journalisé.
 * Échec ou annulation d'un versement déjà relevé : fin_payout_event remet ses lignes en attente.
 */
function fin_payout_record(PDO $pdo, $po, string $account): void {
    if ($account === '' || strtolower((string)($po->currency ?? 'eur')) !== 'eur' || (int)($po->amount ?? 0) <= 0) return;
    $st = $pdo->prepare("SELECT organisateur_id FROM stripe_comptes WHERE account_id = ?");
    $st->execute([$account]);
    $orgaId = (int)$st->fetchColumn();
    if (!$orgaId) return;
    $lock = $pdo->quote('moncamp_po_' . $po->id);
    if (!(int)$pdo->query("SELECT GET_LOCK($lock, 5)")->fetchColumn()) return;
    try {
        $s = (string)($po->status ?? 'pending');
        $statut = $s === 'paid' ? 'paye' : (in_array($s, ['failed', 'canceled'], true) ? 'echec' : 'envoye');
        $st = $pdo->prepare("SELECT * FROM fin_transferts WHERE stripe_transfer_id = ? AND type = 'payout'");
        $st->execute([(string)$po->id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            if ($statut === 'echec' && $t['statut'] !== 'echec') { fin_payout_event($pdo, $po, false, $account); return; }
            if ($t['statut'] !== 'echec') $pdo->prepare("UPDATE fin_transferts SET statut = IF(? = 'paye', 'paye', statut), arrivee = COALESCE(?, arrivee) WHERE id = ?")->execute([$statut, fin_payout_arrivee($po), $t['id']]);
            return;
        }
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $amt = (int)$po->amount;
            $pdo->prepare("INSERT INTO fin_transferts (token, organisateur_id, montant_cents, type, statut, stripe_account_id, stripe_transfer_id, arrivee, erreur, created_at)
                           VALUES (?, ?, ?, 'payout', ?, ?, ?, ?, ?, ?)")
                ->execute([bin2hex(random_bytes(30)), $orgaId, $amt, $statut, $account, (string)$po->id, fin_payout_arrivee($po),
                           $statut === 'echec' ? mb_substr((string)($po->failure_message ?? 'refusé par la banque'), 0, 450) : null, date('Y-m-d H:i:s', (int)($po->created ?? time()))]);
            $tid = (int)$pdo->lastInsertId();
            if ($statut !== 'echec') {
                $st = $pdo->prepare("SELECT id, GREATEST(0, net_cents - net_rembourse_cents - verse_cents) reste FROM fin_paiements
                                     WHERE organisateur_id = ? AND mode <> 'legacy' AND statut = 'attente' ORDER BY created_at, id FOR UPDATE");
                $st->execute([$orgaId]);
                $left = $amt; $alloc = [];
                foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $pid => $r) { if ($left <= 0) break; if ((int)$r > 0) { $alloc[$pid] = min((int)$r, $left); $left -= $alloc[$pid]; } }
                $li = $pdo->prepare("INSERT INTO fin_transfert_lignes (transfert_id, paiement_id, montant_cents) VALUES (?, ?, ?)");
                $up = $pdo->prepare("UPDATE fin_paiements SET verse_cents = verse_cents + ?, transfert_id = ?, statut = IF(net_cents - net_rembourse_cents - verse_cents <= 0, 'verse', statut) WHERE id = ?");
                foreach ($alloc as $pid => $m) { $li->execute([$tid, $pid, $m]); $up->execute([$m, $tid, $pid]); }
                $pdo->prepare("UPDATE fin_transferts SET nb_paiements = ? WHERE id = ?")->execute([count($alloc), $tid]);
                fin_ledger_add($pdo, 'versement', $orgaId, -$amt, (string)$po->id, 'transfert', $tid, 'Versement automatique Stripe', 'webhook', 'destination');
            }
            fin_audit($pdo, 'payout_auto_releve', 'transfert', $tid, ['payout' => $po->id, 'cents' => $amt, 'statut' => $statut, 'arrivee' => fin_payout_arrivee($po)], 'systeme');
            if ($own) $pdo->commit();
        } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK($lock)");
    }
}

/** Relit les derniers versements du compte chez Stripe (ouverture du tableau de bord, au plus toutes les 5 minutes par session) */
function fin_payouts_sync(PDO $pdo, int $orgaId): int {
    $acc = fin_account($pdo, $orgaId);
    if (!$acc || empty($acc['account_id'])) return 0;
    if (session_status() === PHP_SESSION_ACTIVE) { $k = 'po_sync_' . $orgaId; if (($_SESSION[$k] ?? 0) > time() - 300) return 0; $_SESSION[$k] = time(); }
    $n = 0;
    foreach (fin_stripe()->payouts->all(['limit' => 30], ['stripe_account' => $acc['account_id']])->data as $po) { fin_payout_record($pdo, $po, $acc['account_id']); $n++; }
    return $n;
}

// ---------------------------------------------------------------------------------------------
// « Payer ma dette » : l'organisme règle sa dette par carte (Checkout sur le compte PLATEFORME, sans application fee)
// ---------------------------------------------------------------------------------------------
/** Dette en cours (centimes) : solde négatif du portefeuille, celui que les paiements suivants compensent */
function fin_dette_due(PDO $pdo, int $orgaId): int {
    $st = $pdo->prepare("SELECT portefeuille FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    return max(0, -(int)round((float)$st->fetchColumn() * 100));
}

/** Crée la session Checkout du montant de la dette, recalculé ici (jamais celui du navigateur). Retourne l'URL Stripe. */
function fin_dette_checkout(PDO $pdo, int $orgaId, int $userId): string {
    $due = fin_dette_due($pdo, $orgaId);
    if ($due < 50) throw new Exception($due > 0 ? 'Montant trop faible pour un paiement par carte (minimum 0,50 EUR) : il sera compensé sur votre prochain paiement.' : 'Vous n\'avez aucune dette en cours.');
    $st = $pdo->prepare("SELECT nom, email FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    $tok = fin_orga_tok($orgaId, $pdo);
    $site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/');
    $meta = ['type' => 'dette', 'organisateur' => $tok, 'montant' => (string)$due];
    $desc = 'Règlement de la dette moncamp.fr · ' . mb_substr(html_entity_decode((string)$o['nom'], ENT_QUOTES, 'UTF-8'), 0, 80);
    $params = [
        'mode' => 'payment', 'payment_method_types' => ['card'], 'locale' => 'fr',
        'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $due, 'product_data' => ['name' => $desc]]]], // quantité et montant fixes : pas de paiement partiel
        'metadata' => $meta, 'payment_intent_data' => ['description' => $desc, 'metadata' => $meta],
        'success_url' => $site . '/dashboard_organisme.php?o=' . $tok . '&dette=ok#dette',
        'cancel_url' => $site . '/dashboard_organisme.php?o=' . $tok . '#dette',
        'expires_at' => time() + 1800,
    ];
    if (!empty($o['email']) && filter_var($o['email'], FILTER_VALIDATE_EMAIL)) $params['customer_email'] = $o['email'];
    $s = fin_stripe()->checkout->sessions->create($params, ['idempotency_key' => 'moncamp-dette-' . $orgaId . '-' . $due . '-' . date('YmdHi')]); // pas de Stripe-Account : compte plateforme
    fin_audit($pdo, 'dette_checkout', 'organisateur', $orgaId, ['cents' => $due, 'session' => $s->id], 'user:' . $userId);
    return (string)$s->url;
}

/**
 * Webhook checkout.session.completed (type = dette), dans la transaction du webhook. Idempotent (références uniques au journal).
 * La dette du moment est soldée (ligne « dette » négative) ; si elle a baissé entre le clic et le paiement, le surplus est crédité au solde
 * (ligne « ajustement »). Le portefeuille augmente du montant payé. Retourne le détail (pour le reçu et la notification) ou null.
 */
function fin_dette_reglee(PDO $pdo, $session): ?array {
    if (($session->payment_status ?? '') !== 'paid' || strtolower((string)($session->currency ?? 'eur')) !== 'eur') return null;
    $orgaId = fin_orga_from_tok($pdo, (string)($session->metadata->organisateur ?? ''));
    $paid = (int)($session->amount_total ?? 0);
    if (!$orgaId || $paid <= 0) { fin_audit($pdo, 'dette_paiement_inconnu', 'checkout', (string)$session->id, ['cents' => $paid], 'webhook'); return null; }
    $ref = 'dettecb:' . $session->id;
    $st = $pdo->prepare("SELECT COUNT(*) FROM fin_ledger WHERE reference IN (?, ?)");
    $st->execute([$ref, $ref . ':surplus']);
    if ((int)$st->fetchColumn()) return null; // déjà traité
    $st = $pdo->prepare("SELECT portefeuille FROM organisateurs WHERE id = ? FOR UPDATE");
    $st->execute([$orgaId]);
    $due = max(0, -(int)round((float)$st->fetchColumn() * 100));
    $solde = min($paid, $due); $surplus = $paid - $solde;
    $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) + ? WHERE id = ?")->execute([$paid / 100, $orgaId]);
    if ($solde > 0) fin_ledger_add($pdo, 'dette', $orgaId, -$solde, $ref, 'checkout', $session->id, 'Dette réglée par carte', 'webhook', 'plateforme');
    if ($surplus > 0) fin_ledger_add($pdo, 'ajustement', $orgaId, $surplus, $ref . ':surplus', 'checkout', $session->id, 'Excédent du règlement de dette crédité au solde', 'webhook', 'plateforme');
    $r = ['organisateur_id' => $orgaId, 'session' => (string)$session->id, 'paye' => $paid, 'solde' => $solde, 'surplus' => $surplus, 'attendu' => (int)($session->metadata->montant ?? 0)];
    fin_audit($pdo, 'dette_reglee_carte', 'organisateur', $orgaId, $r, 'webhook');
    return $r;
}

/** Reçu par e-mail (après la transaction du webhook ; un échec n'annule rien) */
function fin_dette_notifier(PDO $pdo, array $r): void {
    try {
        if (!function_exists('sendMailGmail')) @include_once __DIR__ . '/mail_config.php';
        if (!function_exists('sendMailGmail')) return;
        $st = $pdo->prepare("SELECT o.nom, COALESCE(NULLIF(o.email, ''), u.email) email FROM organisateurs o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
        $st->execute([$r['organisateur_id']]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o || !filter_var($o['email'], FILTER_VALIDATE_EMAIL)) return;
        $site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/');
        $tok = fin_orga_tok((int)$r['organisateur_id'], $pdo);
        $body = '<p>Bonjour,</p><p>Votre paiement de <b>' . fin_eur($r['paye']) . '</b> a bien été reçu. Dette soldée : <b>' . fin_eur($r['solde']) . '</b>'
            . ($r['surplus'] ? ' ; excédent crédité à votre solde : <b>' . fin_eur($r['surplus']) . '</b>' : '') . '.</p>'
            . '<p><a href="' . htmlspecialchars($site . '/recu_dette.php?o=' . $tok . '&r=' . urlencode($r['session'])) . '">Télécharger le reçu (PDF)</a></p><p>L\'équipe moncamp.fr</p>';
        sendMailGmail($o['email'], html_entity_decode((string)$o['nom'], ENT_QUOTES, 'UTF-8'), 'Reçu : règlement de votre dette moncamp.fr', $body);
    } catch (Throwable $e) { error_log('fin_dette_notifier: ' . $e->getMessage()); }
}

/**
 * Prochain virement vers la banque de l'organisme : un virement déjà en route (payout envoyé : date d'arrivée Stripe), sinon le solde Stripe
 * du compte connecté (API balance : disponible = virement imminent ; à venir = date available_on des mouvements en attente).
 * ['m' => centimes, 'd' => Y-m-d, 'etat' => 'route' | 'dispo' | 'attente'] ou null. Mis en cache 5 minutes dans la session.
 */
function fin_prochain_virement(PDO $pdo, int $orgaId): ?array {
    $st = $pdo->prepare("SELECT montant_cents m, COALESCE(arrivee, DATE(created_at)) d FROM fin_transferts WHERE organisateur_id = ? AND type = 'payout' AND statut = 'envoye' ORDER BY d LIMIT 1");
    $st->execute([$orgaId]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) return ['m' => (int)$r['m'], 'd' => $r['d'], 'etat' => 'route'];
    $acc = fin_account($pdo, $orgaId);
    if (!$acc || empty($acc['account_id'])) return null;
    $k = 'prochain_vir_' . $orgaId;
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$k]) && $_SESSION[$k]['t'] > time() - 300) return $_SESSION[$k]['v'];
    $v = null;
    try {
        $opt = ['stripe_account' => $acc['account_id']];
        $eur = function ($l) { $t = 0; foreach ($l ?? [] as $x) if (strtolower((string)$x->currency) === 'eur') $t += (int)$x->amount; return $t; };
        $b = fin_stripe()->balance->retrieve([], $opt);
        $dispo = $eur($b->available ?? []);
        if ($dispo > 0) $v = ['m' => $dispo, 'd' => date('Y-m-d'), 'etat' => 'dispo'];
        elseif ($eur($b->pending ?? []) > 0) {
            $par = [];
            foreach (fin_stripe()->balanceTransactions->all(['limit' => 100], $opt)->data as $t)
                if (($t->status ?? '') === 'pending' && strtolower((string)$t->currency) === 'eur' && !empty($t->available_on)) { $d = date('Y-m-d', (int)$t->available_on); $par[$d] = ($par[$d] ?? 0) + (int)$t->net; }
            ksort($par);
            foreach ($par as $d => $m) if ($m > 0) { $v = ['m' => $m, 'd' => $d, 'etat' => 'attente']; break; }
        }
    } catch (Throwable $e) { error_log('fin_prochain_virement: ' . $e->getMessage()); return null; }
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION[$k] = ['t' => time(), 'v' => $v];
    return $v;
}

/**
 * Rattrapage (une fois, au déploiement) : frais Stripe réels déjà payés par moncamp.fr pour des paiements legacy ou destination (anciens,
 * frais_orga = 0) et jamais retenus à l'organisme → dette de l'organisme du même montant, compensée comme les autres (onglet Dette).
 * Idempotent : une clé par paiement au journal (fraisrattrapage:<id>). Les paiements dont les frais ne sont pas encore relevés sont repris
 * aux passages suivants ; le rattrapage se marque terminé (réglage rattrapage_frais_v1) quand il n'en reste plus. Retourne le nombre de dettes créées.
 */
function fin_frais_rattrapage(PDO $pdo): int {
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM fin_reglages WHERE cle = 'rattrapage_frais_v1'");
    $st->execute();
    if ((int)$st->fetchColumn()) return 0;
    $rows = $pdo->query("SELECT p.id, p.organisateur_id, p.frais_stripe_cents f, p.created_at, c.nom camp, e.prenom, e.nom
                         FROM fin_paiements p JOIN camps c ON c.id = p.camp_id LEFT JOIN inscriptions i ON i.id = p.inscription_id LEFT JOIN enfants e ON e.id = i.enfant_id
                         WHERE p.frais_orga = 0 AND p.mode IN ('legacy', 'destination') AND p.frais_stripe_cents > 0
                           AND NOT EXISTS (SELECT 1 FROM fin_ledger l WHERE l.type = 'dette' AND l.reference = CONCAT('fraisrattrapage:', p.id))
                         ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC);
    $n = 0; $d = fn($v) => html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    foreach ($rows as $r) {
        $qui = trim($d($r['prenom']) . ' ' . mb_strtoupper($d($r['nom']))) ?: 'Équipe';
        $lib = mb_substr('Frais Stripe du paiement du ' . date('d/m', strtotime($r['created_at'])) . ' (' . $d($r['camp']) . ' · ' . $qui . ')', 0, 120);
        $pdo->beginTransaction();
        try {
            if (fin_ledger_add($pdo, 'dette', (int)$r['organisateur_id'], (int)$r['f'], 'fraisrattrapage:' . $r['id'], 'paiement', $r['id'], $lib, 'rattrapage')) {
                $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) - ? WHERE id = ?")->execute([(int)$r['f'] / 100, $r['organisateur_id']]);
                fin_audit($pdo, 'frais_rattrapes_dette', 'paiement', $r['id'], ['cents' => (int)$r['f'], 'organisateur' => (int)$r['organisateur_id']], 'rattrapage');
                $n++;
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('fin_frais_rattrapage: ' . $e->getMessage()); return $n; }
    }
    $reste = (int)$pdo->query("SELECT COUNT(*) FROM fin_paiements WHERE frais_orga = 0 AND mode IN ('legacy', 'destination') AND frais_stripe_cents IS NULL AND payment_intent IS NOT NULL")->fetchColumn();
    if (!$reste) $pdo->exec("INSERT IGNORE INTO fin_reglages (cle, valeur, par) VALUES ('rattrapage_frais_v1', '1', 'rattrapage')");
    return $n;
}

/**
 * Ajustement manuel (admin) : « dette » (l'organisme doit $cents à moncamp.fr : solde négatif, compensé sur ses paiements suivants) ou « credit »
 * (moncamp.fr lui doit $cents : solde positif). Journalisé (fin_ledger, fin_audit) avec le motif. Retourne la référence.
 */
function fin_ajustement_manuel(PDO $pdo, int $orgaId, string $sens, int $cents, string $motif, string $acteur): string {
    $motif = trim($motif);
    if ($cents <= 0 || $motif === '' || !in_array($sens, ['dette', 'credit'], true)) throw new InvalidArgumentException('Montant positif et motif obligatoires.');
    $ref = 'manuel:' . bin2hex(random_bytes(8));
    $lib = mb_substr(($sens === 'dette' ? 'Dette : ' : 'Crédit : ') . $motif, 0, 120);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) + ? WHERE id = ?")->execute([($sens === 'dette' ? -$cents : $cents) / 100, $orgaId]);
        fin_ledger_add($pdo, 'ajustement', $orgaId, $sens === 'dette' ? -$cents : $cents, $ref, 'admin', '', $lib, $acteur);
        if ($sens === 'dette') fin_ledger_add($pdo, 'dette', $orgaId, $cents, $ref, 'admin', '', $lib, $acteur);
        fin_audit($pdo, 'ajustement_manuel', 'organisateur', $orgaId, ['sens' => $sens, 'cents' => $cents, 'motif' => mb_substr($motif, 0, 300), 'ref' => $ref], $acteur);
        $pdo->commit();
        return $ref;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Délai (jours après le début du séjour) avant le versement bancaire ; recalcule la date des paiements pas encore versés. Journalisé. */
function fin_set_payout_jours(PDO $pdo, int $jours, string $acteur): void {
    fin_ensure($pdo);
    $jours = max(0, min(30, $jours)); $old = fin_reglages(true)['payout_jours'];
    fin_put_reglage($pdo, 'payout_jours', (string)$jours, $acteur);
    $pdo->prepare("UPDATE fin_paiements p JOIN camps c ON c.id = p.camp_id SET p.date_deblocage = DATE_ADD(c.date_debut, INTERVAL ? DAY)
                   WHERE p.mode <> 'legacy' AND p.statut IN ('attente', 'bloque', 'litige')")->execute([$jours]);
    fin_audit($pdo, 'payout_jours', 'reglage', 'versements', ['avant' => $old, 'apres' => $jours], $acteur);
    fin_reglages(true);
}

/**
 * Lance les versements bancaires dus (cron horaire, ou administrateur pour un organisme). Verrou global : un seul passage à la fois.
 * $force (administrateur) : ignore la date de versement (verse tout le disponible sauf les litiges).
 */
function fin_payouts_run(PDO $pdo, string $acteur = 'cron', ?int $onlyOrga = null, bool $force = false): array {
    fin_ensure($pdo);
    $rep = ['payouts' => 0, 'montant_cents' => 0, 'reportes' => 0, 'erreurs' => 0];
    if (!(int)$pdo->query("SELECT GET_LOCK('moncamp_payouts', 0)")->fetchColumn()) return $rep + ['verrou' => true];
    try {
        // organismes avec des séjours éligibles, ou de l'argent legacy transféré récemment (versements manuels : c'est à nous de le verser)
        $ids = $onlyOrga ? [$onlyOrga] : $pdo->query("SELECT organisateur_id FROM fin_paiements WHERE mode <> 'legacy' AND statut = 'attente' AND date_deblocage <= NOW()
            UNION SELECT t.organisateur_id FROM fin_transferts t WHERE t.type <> 'payout' AND t.statut = 'envoye' AND t.created_at > NOW() - INTERVAL 30 DAY
              AND NOT EXISTS (SELECT 1 FROM fin_transferts x WHERE x.organisateur_id = t.organisateur_id AND x.type = 'payout' AND x.statut IN ('envoye', 'paye') AND x.created_at > t.created_at)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $orgaId) {
            $orgaId = (int)$orgaId;
            try {
                $acc = fin_account($pdo, $orgaId);
                if (!fin_account_ready($acc)) { try { $acc = fin_sync_account($pdo, $orgaId); } catch (Throwable $e) { $acc = null; } }
                if (!fin_account_ready($acc)) { $rep['reportes']++; continue; }
                if (empty($acc['payout_manuel']) && !fin_account_setup($pdo, $orgaId, $acc['account_id'])) { $rep['reportes']++; continue; }
                $tid = fin_prepare_payout($pdo, $orgaId, $acc['account_id'], $force);
                if (!$tid) { $rep['reportes']++; continue; }
                $r = fin_execute_transfer($pdo, $tid, $acteur);
                if ($r === 'envoye') { $rep['payouts']++; $rep['montant_cents'] += (int)$pdo->query("SELECT montant_cents FROM fin_transferts WHERE id = " . (int)$tid)->fetchColumn(); }
                elseif ($r === 'echec') $rep['erreurs']++;
            } catch (Throwable $e) { $rep['erreurs']++; error_log("fin_payouts_run orga $orgaId: " . $e->getMessage()); }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('moncamp_payouts')");
    }
    return $rep;
}

/**
 * Étape 1 d'un versement bancaire. Montant = min(disponible Stripe, solde Stripe total − part des séjours pas encore éligibles ou en litige) :
 * un remboursement après versement (solde négatif) et l'argent legacy déjà transféré sont donc pris en compte sans calcul de notre côté.
 * Le montant est réparti sur les lignes éligibles (fin_transfert_lignes) ; retourne l'id du versement ou null.
 */
function fin_prepare_payout(PDO $pdo, int $orgaId, string $accountId, bool $force = false): ?int {
    $en = (int)$pdo->query("SELECT COUNT(*) FROM fin_transferts WHERE type = 'payout' AND statut = 'en_cours' AND organisateur_id = " . $orgaId)->fetchColumn();
    if ($en) return null; // un versement non confirmé est rejoué d'abord (fin_retry_pending)
    $b = fin_stripe()->balance->retrieve([], ['stripe_account' => $accountId]);
    $sum = function ($list) { $t = 0; foreach ($list ?? [] as $x) if (strtolower((string)$x->currency) === 'eur') $t += (int)$x->amount; return $t; };
    $dispo = $sum($b->available ?? []); $total = $dispo + $sum($b->pending ?? []);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, statut, date_deblocage, GREATEST(0, net_cents - net_rembourse_cents - verse_cents) reste FROM fin_paiements
                             WHERE organisateur_id = ? AND mode <> 'legacy' AND statut IN ('attente', 'bloque', 'litige') FOR UPDATE");
        $st->execute([$orgaId]);
        $ok = []; $hold = 0; $now = time();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['statut'] === 'attente' && ($force || strtotime($r['date_deblocage']) <= $now)) { if ((int)$r['reste'] > 0) $ok[(int)$r['id']] = (int)$r['reste']; }
            else $hold += (int)$r['reste'];
        }
        $amt = min($dispo, $total - $hold);
        if ($amt < 100) { $pdo->rollBack(); return null; } // ponytail: minimum 1 EUR, le reste part au passage suivant
        $alloc = $ok ? fin_allocate(min($amt, array_sum($ok)), $ok) : [];
        $pdo->prepare("INSERT INTO fin_transferts (token, organisateur_id, montant_cents, type, statut, stripe_account_id, nb_paiements) VALUES (?, ?, ?, 'payout', 'en_cours', ?, ?)")
            ->execute([bin2hex(random_bytes(30)), $orgaId, $amt, $accountId, count(array_filter($alloc))]);
        $tid = (int)$pdo->lastInsertId();
        $li = $pdo->prepare("INSERT INTO fin_transfert_lignes (transfert_id, paiement_id, montant_cents) VALUES (?, ?, ?)");
        $up = $pdo->prepare("UPDATE fin_paiements SET statut = 'en_cours', transfert_id = ?, verse_cents = verse_cents + ? WHERE id = ?");
        foreach ($alloc as $pid => $m) if ($m > 0) { $li->execute([$tid, $pid, $m]); $up->execute([$tid, $m, $pid]); }
        fin_audit($pdo, 'payout_prepare', 'transfert', $tid, ['organisateur' => $orgaId, 'cents' => $amt, 'disponible' => $dispo, 'total' => $total, 'retenu' => $hold, 'lignes' => count(array_filter($alloc))], 'systeme');
        $pdo->commit();
        return $tid;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
