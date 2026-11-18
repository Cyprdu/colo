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
    $st = $pdo->prepare("SELECT COALESCE(SUM(p.commission_cents + p.compensation_cents), 0), MIN(p.organisateur_id), MAX(IF(p.mode = 'direct', o.stripe_account_id, NULL))
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

// ---------------------------------------------------------------------------------------------
// Versements bancaires des organismes (Payout sur leur compte connecté, schedule « manual »)
// ---------------------------------------------------------------------------------------------
/** Passe le compte en versements manuels (pilotés par fin_payouts_run) et demande les paiements par carte (on_behalf_of). Idempotent. */
function fin_account_setup(PDO $pdo, int $orgaId, string $accountId): bool {
    try {
        fin_stripe()->accounts->update($accountId, ['settings' => ['payouts' => ['schedule' => ['interval' => 'manual']]], 'capabilities' => ['card_payments' => ['requested' => true]]]);
        $pdo->prepare("UPDATE stripe_comptes SET payout_manuel = 1 WHERE organisateur_id = ?")->execute([$orgaId]);
        fin_audit($pdo, 'compte_versements_manuels', 'organisateur', $orgaId, ['account' => $accountId], 'systeme');
        return true;
    } catch (Throwable $e) { error_log('fin_account_setup: ' . $e->getMessage()); return false; }
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
