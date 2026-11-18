<?php
// api/sepa_lib.php : prélèvement SEPA de l'organisme sur le compte PLATEFORME (mandat enregistré une fois par Checkout « setup »).
// Sert à payer les boosts par prélèvement et à prélever une dette restée impayée 15 jours (cron). Sans mandat, la dette reste compensée
// sur les paiements suivants et « Payer ma dette » reste disponible.

function fin_sepa_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    fin_add_col($pdo, 'organisateurs', 'stripe_customer_id', "VARCHAR(64) NULL");
    fin_add_col($pdo, 'organisateurs', 'sepa_pm', "VARCHAR(64) NULL");
    fin_add_col($pdo, 'organisateurs', 'sepa_last4', "VARCHAR(4) NULL");
    fin_add_col($pdo, 'organisateurs', 'sepa_mandat_le', "DATETIME NULL");
    fin_add_col($pdo, 'organisateurs', 'dette_depuis', "DATETIME NULL");
    fin_add_col($pdo, 'organisateurs', 'sepa_dette_pi', "VARCHAR(64) NULL");
}

function fin_sepa_info(PDO $pdo, int $orgaId): array {
    fin_sepa_ensure($pdo);
    $st = $pdo->prepare("SELECT stripe_customer_id, sepa_pm, sepa_last4, sepa_mandat_le, dette_depuis, sepa_dette_pi FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Client Stripe de la plateforme lié à l'organisme (créé une fois) */
function fin_sepa_customer(PDO $pdo, int $orgaId): string {
    $i = fin_sepa_info($pdo, $orgaId);
    if (!empty($i['stripe_customer_id'])) return (string)$i['stripe_customer_id'];
    $st = $pdo->prepare("SELECT o.nom, COALESCE(NULLIF(o.email, ''), u.email) email FROM organisateurs o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
    $st->execute([$orgaId]);
    $o = $st->fetch(PDO::FETCH_ASSOC) ?: ['nom' => '', 'email' => ''];
    $p = ['name' => mb_substr(html_entity_decode((string)$o['nom'], ENT_QUOTES, 'UTF-8'), 0, 200), 'metadata' => ['organisateur' => fin_orga_tok($orgaId, $pdo), 'type' => 'organisme']];
    if (filter_var($o['email'], FILTER_VALIDATE_EMAIL)) $p['email'] = $o['email'];
    $c = fin_stripe()->customers->create($p, ['idempotency_key' => 'moncamp-cus-' . $orgaId]);
    $pdo->prepare("UPDATE organisateurs SET stripe_customer_id = ? WHERE id = ? AND stripe_customer_id IS NULL")->execute([$c->id, $orgaId]);
    return (string)$c->id;
}

/** Checkout « setup » : l'organisme signe un mandat de prélèvement SEPA. Retourne l'URL Stripe. */
function fin_sepa_mandat_checkout(PDO $pdo, int $orgaId, int $userId): string {
    $tok = fin_orga_tok($orgaId, $pdo);
    $site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/');
    $s = fin_stripe()->checkout->sessions->create([
        'mode' => 'setup', 'payment_method_types' => ['sepa_debit'], 'currency' => 'eur', 'locale' => 'fr',
        'customer' => fin_sepa_customer($pdo, $orgaId),
        'metadata' => ['type' => 'mandat_sepa', 'organisateur' => $tok],
        'setup_intent_data' => ['metadata' => ['type' => 'mandat_sepa', 'organisateur' => $tok]],
        'success_url' => $site . '/dashboard_organisme.php?o=' . $tok . '&mandat=ok#dette',
        'cancel_url' => $site . '/dashboard_organisme.php?o=' . $tok . '#dette',
    ]);
    fin_audit($pdo, 'mandat_sepa_checkout', 'organisateur', $orgaId, ['session' => $s->id], 'user:' . $userId);
    return (string)$s->url;
}

/** Après le webhook (setup terminé) : moyen de paiement SEPA enregistré comme moyen par défaut du client */
function fin_sepa_mandat_enregistrer(PDO $pdo, int $orgaId, string $setupIntent): bool {
    try {
        $si = fin_stripe()->setupIntents->retrieve($setupIntent, ['expand' => ['payment_method']]);
        $pm = $si->payment_method ?? null;
        $pmId = is_object($pm) ? (string)$pm->id : (string)$pm;
        if ($pmId === '' || ($si->status ?? '') !== 'succeeded') return false;
        $last4 = is_object($pm) ? (string)($pm->sepa_debit->last4 ?? '') : '';
        $cus = fin_sepa_customer($pdo, $orgaId);
        fin_stripe()->customers->update($cus, ['invoice_settings' => ['default_payment_method' => $pmId]]);
        $pdo->prepare("UPDATE organisateurs SET sepa_pm = ?, sepa_last4 = ?, sepa_mandat_le = NOW() WHERE id = ?")->execute([$pmId, $last4 ?: null, $orgaId]);
        fin_audit($pdo, 'mandat_sepa_enregistre', 'organisateur', $orgaId, ['pm' => $pmId], 'webhook');
        return true;
    } catch (Throwable $e) { error_log('fin_sepa_mandat_enregistrer: ' . $e->getMessage()); return false; }
}

/** Prélèvement SEPA hors session sur le compte plateforme (montant fixé par le serveur). Le résultat arrive par webhook (payment_intent.*). */
function fin_sepa_prelever(PDO $pdo, int $orgaId, int $cents, array $meta, string $description, string $idem) {
    $i = fin_sepa_info($pdo, $orgaId);
    if (empty($i['sepa_pm'])) throw new Exception('Aucun mandat de prélèvement enregistré.');
    return fin_stripe()->paymentIntents->create([
        'amount' => $cents, 'currency' => 'eur', 'customer' => fin_sepa_customer($pdo, $orgaId), 'payment_method' => $i['sepa_pm'],
        'payment_method_types' => ['sepa_debit'], 'confirm' => true, 'off_session' => true, 'description' => mb_substr($description, 0, 300),
        'metadata' => $meta + ['organisateur' => fin_orga_tok($orgaId, $pdo), 'sepa' => '1'],
    ], ['idempotency_key' => $idem]);
}

/** Cron : dette restée impayée 15 jours et mandat enregistré → prélèvement automatique du montant dû (un à la fois) */
function fin_sepa_dettes_run(PDO $pdo): array {
    fin_sepa_ensure($pdo);
    $pdo->exec("UPDATE organisateurs SET dette_depuis = NOW() WHERE portefeuille < 0 AND dette_depuis IS NULL");
    $pdo->exec("UPDATE organisateurs SET dette_depuis = NULL WHERE portefeuille >= 0 AND dette_depuis IS NOT NULL");
    $rep = ['prelevements' => 0, 'erreurs' => 0];
    foreach ($pdo->query("SELECT id FROM organisateurs WHERE sepa_pm IS NOT NULL AND sepa_dette_pi IS NULL AND dette_depuis < NOW() - INTERVAL 15 DAY")->fetchAll(PDO::FETCH_COLUMN) as $oid) {
        $oid = (int)$oid;
        $due = fin_dette_due($pdo, $oid);
        if ($due < 100) continue;
        try {
            $pi = fin_sepa_prelever($pdo, $oid, $due, ['type' => 'dette_sepa', 'montant' => (string)$due], 'moncamp.fr : prélèvement de votre dette', 'moncamp-dette-sepa-' . $oid . '-' . date('Ymd') . '-' . $due);
            $pdo->prepare("UPDATE organisateurs SET sepa_dette_pi = ? WHERE id = ?")->execute([$pi->id, $oid]);
            fin_audit($pdo, 'dette_prelevement_sepa', 'organisateur', $oid, ['cents' => $due, 'pi' => $pi->id], 'cron');
            $rep['prelevements']++;
        } catch (Throwable $e) { $rep['erreurs']++; error_log("fin_sepa_dettes_run orga $oid: " . $e->getMessage()); }
    }
    return $rep;
}

/**
 * Webhook payment_intent.succeeded / payment_intent.payment_failed d'un prélèvement SEPA de la plateforme (dans la transaction du webhook).
 * Retourne une notification à envoyer après la transaction, ou null.
 */
function fin_sepa_evenement(PDO $pdo, $pi, bool $ok): ?array {
    $type = (string)($pi->metadata->type ?? '');
    if (($pi->metadata->sepa ?? '') !== '1' || !in_array($type, ['dette_sepa', 'buy_points'], true)) return null;
    $orgaId = fin_orga_from_tok($pdo, (string)($pi->metadata->organisateur ?? ''));
    if (!$orgaId) return null;
    $cents = (int)($pi->amount_received ?? $pi->amount ?? 0);
    if ($type === 'dette_sepa') {
        $pdo->prepare("UPDATE organisateurs SET sepa_dette_pi = NULL WHERE id = ? AND sepa_dette_pi = ?")->execute([$orgaId, $pi->id]);
        if (!$ok) { fin_audit($pdo, 'dette_prelevement_echec', 'organisateur', $orgaId, ['pi' => $pi->id], 'webhook'); return [$orgaId, 'Prélèvement de votre dette refusé', 'Le prélèvement de ' . fin_eur((int)$pi->amount) . ' n\'a pas abouti. Votre dette reste compensée sur vos prochains paiements ; vous pouvez aussi la régler par carte depuis votre tableau de bord.']; }
        $r = fin_dette_reglement($pdo, $orgaId, $cents, 'dettesepa:' . $pi->id, 'Dette réglée par prélèvement SEPA');
        return $r ? [$orgaId, 'Dette réglée par prélèvement', 'Votre prélèvement de ' . fin_eur($cents) . ' est reçu : dette soldée ' . fin_eur($r['solde']) . ($r['surplus'] ? ', excédent crédité ' . fin_eur($r['surplus']) : '') . '.'] : null;
    }
    if (!$ok) { fin_audit($pdo, 'points_sepa_echec', 'organisateur', $orgaId, ['pi' => $pi->id], 'webhook'); return [$orgaId, 'Prélèvement refusé', 'Le prélèvement de ' . fin_eur((int)$pi->amount) . ' pour vos points marketing n\'a pas abouti. Les points n\'ont pas été crédités.']; }
    $points = (int)($pi->metadata->points_amount ?? 0);
    $pack = null; foreach (mk_packs() as $p) if ($p['points'] === $points) $pack = $p;
    if (!$pack || $cents !== (int)$pack['cents']) { fin_audit($pdo, 'points_sepa_incoherent', 'organisateur', $orgaId, ['pi' => $pi->id, 'cents' => $cents, 'points' => $points], 'webhook'); return null; }
    if (mk_credit_points($pdo, $orgaId, $points, 'sepa', $cents, 'pi:' . $pi->id)) {
        fin_audit($pdo, 'points_achetes_sepa', 'organisateur', $orgaId, ['points' => $points, 'cents' => $cents, 'pi' => $pi->id], 'webhook');
        return [$orgaId, 'Points marketing crédités', 'Votre prélèvement de ' . fin_eur($cents) . ' est reçu : ' . $points . ' points ajoutés.'];
    }
    return null;
}

/** E-mail à l'organisme (après la transaction ; un échec n'annule rien) */
function fin_sepa_notifier(PDO $pdo, array $n): void {
    try {
        [$orgaId, $sujet, $texte] = $n;
        if (!function_exists('sendMailGmail')) @include_once __DIR__ . '/mail_config.php';
        if (!function_exists('sendMailGmail')) return;
        $st = $pdo->prepare("SELECT o.nom, COALESCE(NULLIF(o.email, ''), u.email) email FROM organisateurs o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
        $st->execute([$orgaId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o || !filter_var($o['email'], FILTER_VALIDATE_EMAIL)) return;
        sendMailGmail($o['email'], html_entity_decode((string)$o['nom'], ENT_QUOTES, 'UTF-8'), $sujet . ' - moncamp.fr', '<p>Bonjour,</p><p>' . htmlspecialchars($texte) . '</p><p>L\'équipe moncamp.fr</p>');
    } catch (Throwable $e) { error_log('fin_sepa_notifier: ' . $e->getMessage()); }
}
