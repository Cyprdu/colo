<?php
// api/moyens_paiement_lib.php : paiements HORS Stripe (chèque, espèces, virement, ANCV, aides...) choisis par la famille à l'inscription (inclus par finance_lib.php).
//
// FLUX
//  - la famille choisit un moyen parmi ceux acceptés par le séjour (camps.moyens_paiement). Carte = Stripe Checkout (inchangé).
//  - autre moyen : l'inscription est enregistrée (place réservée), statut_paiement = 'A_REGLER', moyen_paiement = code ; AUCUN appel à Stripe.
//  - l'organisateur marque « reçu » quand il a encaissé (seulement pour SES inscriptions) : fin_marquer_recu().
//    moncamp.fr retient alors une commission (défaut 1 %, réglable) : écriture dans fin_commissions_hors + débit du portefeuille de l'organisme,
//    donc déduite de ses prochains versements par carte ; si la cagnotte carte est insuffisante le solde reste négatif (suivi, compensé plus tard).

const FIN_DEFAULT_HORS_PCT = 1.0;

function fin_hors_stripe_pct(): float { return fin_reglages()['commission_hors_pct']; }

/** Moyens acceptés par un séjour (codes), la carte par défaut si le directeur n'en a coché aucun */
function fin_moyens_du_camp(array $camp): array {
    require_once __DIR__ . '/options.php';
    $m = options_paiements_valides($camp['moyens_paiement'] ?? '');
    return $m ?: ['carte'];
}

/** Consigne simple affichée à la famille pour un moyen hors carte */
function fin_instruction_moyen(string $code, ?string $consigneOrga): string {
    $base = [
        'cheque' => 'Envoyez ou remettez votre chèque à l\'organisateur, à l\'ordre indiqué ci-dessous.',
        'virement' => 'Faites un virement sur le compte de l\'organisateur (coordonnées communiquées par l\'organisateur) en indiquant le nom de l\'enfant.',
        'especes' => 'Remettez la somme en espèces à l\'organisateur (sur place ou à l\'accueil) et demandez un reçu.',
        'ancv' => 'Remettez vos Chèques-Vacances ANCV (papier ou e-Chèques-Vacances) à l\'organisateur.',
        'caf' => 'Transmettez à l\'organisateur votre attestation ou bon d\'aide CAF.',
        'msa' => 'Transmettez à l\'organisateur votre attestation ou bon d\'aide MSA.',
        'ce' => 'Transmettez à l\'organisateur l\'attestation de participation de votre employeur / CSE.',
        'pass' => 'Remettez à l\'organisateur votre pass ou coupon.',
    ][$code] ?? 'Réglez directement auprès de l\'organisateur.';
    return $base . ($consigneOrga ? ' ' . trim($consigneOrga) : '');
}

/**
 * L'organisateur confirme avoir REÇU le règlement hors carte d'une inscription (jeton de réservation).
 * Contrôles : l'inscription est dans un séjour d'un organisme appartenant à $userId, en attente de règlement hors Stripe.
 * Retourne [ok, message].
 */
function fin_marquer_recu(PDO $pdo, string $resaToken, int $userId): array {
    fin_ensure($pdo);
    if (!preg_match('/^[a-f0-9]{16,64}$/i', $resaToken)) return [false, 'Inscription introuvable.'];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT i.id, i.prix_final, i.moyen_paiement, i.statut_paiement, i.camp_id, c.organisateur_id
                             FROM inscriptions i JOIN camps c ON c.id = i.camp_id JOIN organisateurs o ON o.id = c.organisateur_id
                             WHERE i.reservation_token = ? AND o.user_id = ? FOR UPDATE");
        $st->execute([$resaToken, $userId]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i) { $pdo->rollBack(); return [false, 'Inscription introuvable.']; }
        if ($i['statut_paiement'] !== 'A_REGLER' || !$i['moyen_paiement'] || $i['moyen_paiement'] === 'carte') { $pdo->rollBack(); return [false, 'Cette inscription n\'est pas en attente d\'un règlement hors carte.']; }
        $brut = (int)round((float)$i['prix_final'] * 100);
        $pct = fin_hors_stripe_pct();
        $com = min($brut, (int)round($brut * $pct / 100));
        $pdo->prepare("UPDATE inscriptions SET statut_paiement = 'PAYE', statut = 'Confirmé', montant_paye = prix_final WHERE id = ? AND statut_paiement = 'A_REGLER'")->execute([$i['id']]);
        $ins = $pdo->prepare("INSERT IGNORE INTO fin_commissions_hors (inscription_id, organisateur_id, camp_id, moyen, brut_cents, commission_cents, pct) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$i['id'], $i['organisateur_id'], $i['camp_id'], $i['moyen_paiement'], $brut, $com, $pct]);
        if ($ins->rowCount() === 1 && $com > 0) // déduite des prochains versements par carte : le solde peut devenir négatif (compensé par les paiements suivants)
            $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) - ? WHERE id = ?")->execute([$com / 100, $i['organisateur_id']]);
        fin_audit($pdo, 'paiement_hors_stripe_recu', 'inscription', $i['id'], ['moyen' => $i['moyen_paiement'], 'brut' => $brut, 'commission' => $com, 'pct' => $pct], 'user:' . $userId);
        $pdo->commit();
        return [true, 'Règlement enregistré. Commission moncamp.fr de ' . fin_eur($com) . ' déduite de vos prochains versements par carte.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('fin_marquer_recu: ' . $e->getMessage());
        return [false, 'Une erreur est survenue, réessayez.'];
    }
}

/**
 * Statistiques par moyen de paiement d'un organisme (pour son tableau de bord) :
 * [code => ['libelle', 'nb', 'montant_cents', 'a_regler_nb', 'a_regler_cents', 'commission_cents']].
 * Partial prêt à l'emploi : partials/moyens_paiement_stats.php.
 */
function fin_moyens_stats(PDO $pdo, int $orgaId): array {
    fin_ensure($pdo);
    require_once __DIR__ . '/options.php';
    $lib = options_paiements();
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(i.moyen_paiement, ''), IF(i.mode_paiement = 'CARTE', 'carte', 'inconnu')) m,
            SUM(i.statut_paiement = 'PAYE' AND i.montant_paye > 0) nb, COALESCE(SUM(CASE WHEN i.statut_paiement = 'PAYE' THEN i.montant_paye END), 0) montant,
            SUM(i.statut_paiement = 'A_REGLER') nb_ar, COALESCE(SUM(CASE WHEN i.statut_paiement = 'A_REGLER' THEN i.prix_final END), 0) montant_ar
         FROM inscriptions i JOIN camps c ON c.id = i.camp_id
         WHERE c.organisateur_id = ? AND COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule') GROUP BY m");
    $st->execute([$orgaId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['m']] = ['libelle' => $lib[$r['m']][0] ?? 'Non précisé', 'nb' => (int)$r['nb'], 'montant_cents' => (int)round((float)$r['montant'] * 100),
                         'a_regler_nb' => (int)$r['nb_ar'], 'a_regler_cents' => (int)round((float)$r['montant_ar'] * 100), 'commission_cents' => 0];
    }
    $st = $pdo->prepare("SELECT moyen, SUM(commission_cents) FROM fin_commissions_hors WHERE organisateur_id = ? GROUP BY moyen");
    $st->execute([$orgaId]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $m => $c) if (isset($out[$m])) $out[$m]['commission_cents'] = (int)$c;
    return $out;
}
