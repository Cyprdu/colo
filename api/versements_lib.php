<?php
// api/versements_lib.php : calendrier des versements aux organismes (inclus par finance_lib.php).
// ANCIEN MODÈLE (fin_paiements.mode = 'legacy') seulement, conservé tant qu'il reste des paiements legacy. Les paiements « destination »
// sont versés automatiquement par Stripe (versements quotidiens, relevés par fin_payout_record). Le jour de versement hebdomadaire n'est plus utilisé (cron horaire, tous les organismes).
//
// MODÈLE
//  - Un jour de versement HEBDOMADAIRE fixe par organisme (lundi à samedi, jamais dimanche), attribué automatiquement au jour le moins chargé
//    pour étaler les sorties d'argent ; seul un administrateur peut le changer. Le cron (quotidien) ne traite que les organismes dont c'est le jour
//    (et rattrape les jours manqués si le cron est resté arrêté).
//  - Une inscription payée devient versable après un délai de sécurité (défaut 7 jours après le paiement, réglable), SANS attendre le séjour.
//  - Réserve de remboursement : tant qu'une annulation avec remboursement est encore possible (séjour pas commencé et fenêtre « 50 % » ouverte),
//    un pourcentage du net (défaut 20 %, réglable) reste retenu. Elle est libérée dès que plus aucun remboursement n'est possible.
//  - Remboursement / litige perdu APRÈS versement : « reversement » Stripe (Transfer Reversal) sur le compte de l'organisme ; si son solde Stripe est
//    insuffisant, dette (solde négatif) compensée automatiquement sur ses prochains versements, signalée à l'administration.
// Toutes les opérations Stripe ont une clé d'idempotence et une ligne en base ; aucun montant ne vient du navigateur.

const FIN_JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi'];
const FIN_DEFAULT_DELAI = 7;
const FIN_DEFAULT_RESERVE = 20.0;

function fin_release_delay_days(): int { return fin_reglages()['delai_securite_jours']; }
function fin_reserve_pct(): float { return fin_reglages()['reserve_pct']; }
/** Date à partir de laquelle un paiement est versable : paiement + délai de sécurité (laisse le temps aux litiges et remboursements immédiats) */
function fin_release_date(?string $dateDebut = null): string {
    return date('Y-m-d H:i:s', strtotime('+' . fin_release_delay_days() . ' day'));
}

/** Écrit un réglage (valeur déjà validée par l'appelant) */
function fin_put_reglage(PDO $pdo, string $cle, string $valeur, string $par): void {
    $pdo->prepare("INSERT INTO fin_reglages (cle, valeur, par) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), par = VALUES(par), maj = NOW()")
        ->execute([$cle, $valeur, substr($par, 0, 40)]);
}
/** Délai de sécurité (jours) et réserve (%) : bornés, journalisés */
function fin_set_versements(PDO $pdo, int $delai, float $reservePct, string $acteur): void {
    fin_ensure($pdo);
    $delai = max(0, min(60, $delai)); $reservePct = round(max(0.0, min(100.0, $reservePct)), 1);
    $old = fin_reglages(true);
    fin_put_reglage($pdo, 'delai_securite_jours', (string)$delai, $acteur);
    fin_put_reglage($pdo, 'reserve_pct', (string)$reservePct, $acteur);
    fin_audit($pdo, 'versements_reglages', 'reglage', 'versements', ['avant' => ['delai' => $old['delai_securite_jours'], 'reserve' => $old['reserve_pct']], 'apres' => ['delai' => $delai, 'reserve' => $reservePct]], $acteur);
    fin_reglages(true);
}

// ---------------------------------------------------------------------------------------------
// Jour de versement
// ---------------------------------------------------------------------------------------------
/**
 * Attribue un jour (1 = lundi ... 6 = samedi) aux organismes qui n'en ont pas : le jour comptant le moins d'organismes, puis le moins de volume.
 * Idempotent, appelé par fin_ensure (migration des organismes existants et organismes créés depuis).
 */
function fin_assign_jours(PDO $pdo): void {
    if (!(int)$pdo->query("SELECT COUNT(*) FROM organisateurs WHERE jour_versement IS NULL")->fetchColumn()) return;
    if (!(int)$pdo->query("SELECT GET_LOCK('colomap_fin_jours', 0)")->fetchColumn()) return;
    try {
        $vol = $pdo->query("SELECT organisateur_id, SUM(brut_cents) v FROM fin_paiements GROUP BY organisateur_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $load = array_fill(1, 6, ['n' => 0, 'v' => 0]);
        foreach ($pdo->query("SELECT id, jour_versement FROM organisateurs WHERE jour_versement BETWEEN 1 AND 6")->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $load[(int)$o['jour_versement']]['n']++; $load[(int)$o['jour_versement']]['v'] += (int)($vol[$o['id']] ?? 0);
        }
        $ids = $pdo->query("SELECT id FROM organisateurs WHERE jour_versement IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        usort($ids, fn($a, $b) => (int)($vol[$b] ?? 0) <=> (int)($vol[$a] ?? 0) ?: (int)$a <=> (int)$b); // les plus gros d'abord : meilleur équilibre
        $up = $pdo->prepare("UPDATE organisateurs SET jour_versement = ? WHERE id = ? AND jour_versement IS NULL");
        foreach ($ids as $id) {
            $best = 1;
            for ($d = 2; $d <= 6; $d++) if ([$load[$d]['n'], $load[$d]['v']] < [$load[$best]['n'], $load[$best]['v']]) $best = $d;
            $up->execute([$best, $id]);
            $load[$best]['n']++; $load[$best]['v'] += (int)($vol[$id] ?? 0);
        }
    } finally { $pdo->query("SELECT RELEASE_LOCK('colomap_fin_jours')"); }
}
/** Un administrateur change le jour d'un organisme (1 à 6) */
function fin_set_jour(PDO $pdo, int $orgaId, int $jour, string $acteur): bool {
    if (!isset(FIN_JOURS[$jour])) return false;
    $st = $pdo->prepare("UPDATE organisateurs SET jour_versement = ? WHERE id = ?");
    $st->execute([$jour, $orgaId]);
    fin_audit($pdo, 'jour_versement_modifie', 'organisateur', $orgaId, ['jour' => FIN_JOURS[$jour]], $acteur);
    return true;
}
/** Prochaine date (Y-m-d) tombant un jour ISO donné, aujourd'hui compris */
function fin_next_payout_date(int $jour, ?int $now = null): string {
    $now = $now ?? time();
    for ($i = 0; $i < 7; $i++) { $t = strtotime("+$i day", $now); if ((int)date('N', $t) === $jour) return date('Y-m-d', $t); }
    return date('Y-m-d', $now);
}
/** Jours ISO à traiter par le cron : aujourd'hui + les jours écoulés depuis le dernier passage (rattrapage, 7 jours max) */
function fin_days_due(PDO $pdo): array {
    $st = $pdo->prepare("SELECT valeur FROM fin_reglages WHERE cle = 'cron_dernier_jour'");
    $st->execute();
    $last = (string)$st->fetchColumn();
    $days = [(int)date('N')];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last)) {
        for ($i = 1; $i <= 7; $i++) {
            $t = strtotime("-$i day");
            if (date('Y-m-d', $t) <= $last) break;
            $days[] = (int)date('N', $t);
        }
    }
    return array_values(array_unique($days));
}

// ---------------------------------------------------------------------------------------------
// Réserve et éligibilité d'une ligne de paiement
// ---------------------------------------------------------------------------------------------
/** Une annulation avec remboursement est-elle encore possible ? (séjour pas commencé et fenêtre « 50 % » ouverte) */
function fin_reserve_needed(?string $dateDebut, int $joursMoitie, string $asOfDay): bool {
    return $dateDebut && strtotime($dateDebut) >= strtotime($asOfDay . ' +' . max(0, $joursMoitie) . ' day');
}
/** Date (Y-m-d) à partir de laquelle la réserve d'un séjour est libérée : plus aucun remboursement possible */
function fin_reserve_release_day(?string $dateDebut, int $joursMoitie): ?string {
    return $dateDebut ? date('Y-m-d', strtotime($dateDebut . ' -' . max(0, $joursMoitie) . ' day +1 day')) : null;
}
/**
 * Ventilation d'une ligne `attente` à la date $asOf ('Y-m-d H:i:s') : restant dû, versable ('ok'), retenu en réserve ('held'), en délai de sécurité ('wait').
 * Ligne attendue : net_cents, net_rembourse_cents, verse_cents, date_deblocage, date_debut, jm.
 */
function fin_row_parts(array $r, float $reservePct, string $asOf): array {
    $due = max(0, (int)$r['net_cents'] - (int)$r['net_rembourse_cents']);
    $left = max(0, $due - (int)$r['verse_cents']);
    if ($left <= 0) return ['left' => 0, 'ok' => 0, 'held' => 0, 'wait' => 0];
    if (strtotime($r['date_deblocage']) > strtotime($asOf)) return ['left' => $left, 'ok' => 0, 'held' => 0, 'wait' => $left];
    $held = fin_reserve_needed($r['date_debut'] ?? null, (int)($r['jm'] ?? 15), substr($asOf, 0, 10)) ? (int)round($due * $reservePct / 100) : 0;
    $ok = max(0, $due - $held - (int)$r['verse_cents']);
    return ['left' => $left, 'ok' => $ok, 'held' => $left - $ok, 'wait' => 0];
}

// ---------------------------------------------------------------------------------------------
// Versements
// ---------------------------------------------------------------------------------------------
/**
 * Verse aux organismes ce qui est versable. $respectDay = true (cron) : seuls les organismes dont c'est le jour (ou un jour manqué) sont traités ;
 * false (administrateur) : tous / l'organisme demandé, quel que soit le jour. Verrou global : un seul passage à la fois.
 */
/**
 * Compte devenu prêt (webhook account.updated, ouverture du tableau de bord, cron) : tout le net des paiements reçus par moncamp.fr avant
 * l'activation (mode legacy) part tout de suite sur le compte Stripe de l'organisme, sans délai ni réserve. Transfer idempotent, journalisé
 * (fin_ledger « transfert »), description lisible recopiée sur le paiement côté organisme. Sans effet s'il n'y a rien en attente.
 */
function fin_release_pending_now(PDO $pdo, int $orgaId): array {
    $st = $pdo->prepare("SELECT COUNT(*) FROM fin_paiements WHERE organisateur_id = ? AND mode = 'legacy' AND statut = 'attente'");
    $st->execute([$orgaId]);
    if (!(int)$st->fetchColumn() || $pdo->inTransaction()) return ['transferts' => 0, 'montant_cents' => 0];
    return fin_release_due($pdo, 'systeme', $orgaId, false, true);
}

/** Charge Stripe (ch_…) d'un paiement : latest_charge de son PaymentIntent, mise en cache dans fin_paiements.charge_id. null si pas de PaymentIntent. */
function fin_paiement_charge(PDO $pdo, int $paiementId): ?string {
    $st = $pdo->prepare("SELECT charge_id, payment_intent FROM fin_paiements WHERE id = ?");
    $st->execute([$paiementId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    if (!empty($p['charge_id'])) return (string)$p['charge_id'];
    if (empty($p['payment_intent'])) return null;
    $lc = fin_stripe()->paymentIntents->retrieve((string)$p['payment_intent'])->latest_charge ?? null;
    $ch = is_object($lc) ? (string)$lc->id : (string)$lc;
    if ($ch === '') return null;
    $pdo->prepare("UPDATE fin_paiements SET charge_id = ? WHERE payment_intent = ?")->execute([$ch, $p['payment_intent']]); // même charge pour tout le panier
    return $ch;
}

/** Libellé lisible d'un transfert (séjours, familles, nombre d'inscriptions) pour le Stripe de l'organisme */
function fin_transfer_libelle(PDO $pdo, int $tid): array {
    $st = $pdo->prepare("SELECT c.nom camp, e.prenom, e.nom, p.brut_cents, p.commission_cents, p.net_cents FROM fin_transfert_lignes l JOIN fin_paiements p ON p.id = l.paiement_id
                         JOIN camps c ON c.id = p.camp_id LEFT JOIN inscriptions i ON i.id = p.inscription_id LEFT JOIN enfants e ON e.id = i.enfant_id WHERE l.transfert_id = ? ORDER BY c.nom, e.nom");
    $st->execute([$tid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $d = fn($v) => html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    $eur = fn($c) => number_format(((int)$c) / 100, 2, ',', ' ') . ' €';
    // Une ligne par inscription : « Séjour · Prénom NOM · 125,00 € − 3,43 € = 121,57 € »
    $lignes = array_map(fn($r) => $d($r['camp']) . ' · ' . (trim($d($r['prenom']) . ' ' . mb_strtoupper($d($r['nom']))) ?: 'Équipe') . ' · '
        . $eur($r['brut_cents']) . ' − ' . $eur($r['commission_cents']) . ' = ' . $eur($r['net_cents']), $rows);
    $n = count($rows);
    $meta = ['source' => 'moncamp.fr', 'type' => 'paiements_avant_activation', 'inscriptions' => (string)$n];
    foreach (array_slice($lignes, 0, 40) as $k => $l) $meta['inscription_' . ($k + 1)] = mb_substr($l, 0, 480);
    $titre = 'moncamp.fr · ' . ($n ? $n . ' inscription' . ($n > 1 ? 's' : '') . ' payée' . ($n > 1 ? 's' : '') . ' avant l\'activation' : 'reversement des inscriptions');
    return [mb_substr($titre . ($lignes ? ' : ' . implode(' ; ', $lignes) : ''), 0, 350), $meta];
}

function fin_release_due(PDO $pdo, string $acteur = 'cron', ?int $onlyOrga = null, bool $respectDay = false, bool $force = false): array {   // $force : test administrateur, ignore délai de sécurité et réserve
    fin_ensure($pdo);
    $rep = ['transferts' => 0, 'montant_cents' => 0, 'reportes' => 0, 'erreurs' => 0, 'hors_jour' => 0, 'reversements' => 0];
    if (!(int)$pdo->query("SELECT GET_LOCK('colomap_fin_release', 0)")->fetchColumn()) return $rep + ['verrou' => true];
    try {
        fin_retry_pending($pdo, $rep);
        fin_process_reversals($pdo, $rep);
        $days = $respectDay ? fin_days_due($pdo) : null;
        $rows = $pdo->query("SELECT DISTINCT p.organisateur_id, o.jour_versement FROM fin_paiements p JOIN organisateurs o ON o.id = p.organisateur_id
                             WHERE p.mode = 'legacy' AND p.statut = 'attente'" . ($force ? '' : ' AND p.date_deblocage <= NOW()'))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $o) {
            $orgaId = (int)$o['organisateur_id'];
            if ($onlyOrga && $orgaId !== $onlyOrga) continue;
            if ($days !== null && !in_array((int)$o['jour_versement'], $days, true)) { $rep['hors_jour']++; continue; }
            try {
                $acc = fin_account($pdo, $orgaId);
                if (!$acc || !fin_account_ready($acc)) { try { $acc = fin_sync_account($pdo, $orgaId); } catch (Throwable $e) { $acc = null; } }
                if (!fin_account_ready($acc)) { $rep['reportes']++; continue; }
                $tids = fin_prepare_transfer($pdo, $orgaId, $acc['account_id'], 'sejour', $force);
                if (!$tids) { $rep['reportes']++; continue; }
                foreach ($tids as $tid) {
                    $r = fin_execute_transfer($pdo, $tid, $acteur);
                    if ($r === 'envoye') {
                        $rep['transferts']++;
                        $rep['montant_cents'] += (int)$pdo->query("SELECT montant_cents FROM fin_transferts WHERE id = " . (int)$tid)->fetchColumn();
                    } elseif ($r === 'echec') { $rep['erreurs']++; }
                }
            } catch (Throwable $e) {
                $rep['erreurs']++;
                error_log("fin_release_due orga $orgaId: " . $e->getMessage());
            }
        }
        if ($respectDay && !$onlyOrga) fin_put_reglage($pdo, 'cron_dernier_jour', date('Y-m-d'), $acteur);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('colomap_fin_release')");
    }
    return $rep;
}

/**
 * Étape 1 (transaction) : calcule le versable de chaque ligne (après réserve), fige le montant, enregistre une ligne par paiement (fin_transfert_lignes),
 * réserve le solde. Le montant = min(versable, solde du portefeuille) : points achetés, remboursements et dettes déjà déduits du solde sont donc compensés.
 * Retourne l'id du transfert ou null s'il n'y a rien à verser.
 */
function fin_prepare_transfer(PDO $pdo, int $orgaId, string $accountId, string $type, bool $force = false): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT portefeuille FROM organisateurs WHERE id = ? FOR UPDATE");
        $st->execute([$orgaId]);
        $pf = (int)round((float)$st->fetchColumn() * 100);
        $st = $pdo->prepare("SELECT id, camp_id, net_cents, net_rembourse_cents, verse_cents, date_deblocage FROM fin_paiements
                             WHERE organisateur_id = ? AND mode = 'legacy' AND statut = 'attente'" . ($force ? '' : ' AND date_deblocage <= NOW()') . " FOR UPDATE");
        $st->execute([$orgaId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { $pdo->rollBack(); return []; }
        $camps = [];
        $in = implode(',', array_fill(0, count($rows), '?'));
        $cs = $pdo->prepare("SELECT id, date_debut, annul_jours_moitie FROM camps WHERE id IN (SELECT camp_id FROM fin_paiements WHERE id IN ($in))");
        $cs->execute(array_column($rows, 'id'));
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) $camps[(int)$c['id']] = $c;
        $pct = $force ? 0.0 : fin_reserve_pct(); $now = date('Y-m-d H:i:s');
        $w = []; $due = 0; $held = 0;
        foreach ($rows as $r) {
            $c = $camps[(int)$r['camp_id']] ?? [];
            if ($force) $r['date_deblocage'] = $now;
            $p = fin_row_parts($r + ['date_debut' => $c['date_debut'] ?? null, 'jm' => $c['annul_jours_moitie'] ?? 15], $pct, $now);
            if ($p['ok'] > 0) { $w[(int)$r['id']] = $p['ok']; $due += $p['ok']; }
            $held += $p['held'];
        }
        $amt = min($due, $pf);
        if ($amt <= 0 || !$w) { $pdo->rollBack(); return []; }
        $alloc = fin_allocate($amt, $w);

        // Un transfert par paiement (montant ≤ net restant du paiement) : chacun est adossé à la charge de son paiement (fin_execute_transfer)
        $tr = $pdo->prepare("INSERT INTO fin_transferts (token, organisateur_id, montant_cents, type, statut, stripe_account_id, nb_paiements, par_paiement) VALUES (?, ?, ?, ?, 'en_cours', ?, 1, 1)");
        $li = $pdo->prepare("INSERT INTO fin_transfert_lignes (transfert_id, paiement_id, montant_cents) VALUES (?, ?, ?)");
        $up = $pdo->prepare("UPDATE fin_paiements SET statut = 'en_cours', transfert_id = ?, verse_cents = verse_cents + ? WHERE id = ?");
        $tids = [];
        foreach ($alloc as $pid => $m) {
            if ($m <= 0) continue;
            $tr->execute([bin2hex(random_bytes(30)), $orgaId, $m, $type, $accountId]);
            $tid = (int)$pdo->lastInsertId(); $tids[] = $tid;
            $li->execute([$tid, $pid, $m]); $up->execute([$tid, $m, $pid]);
        }
        $pdo->prepare("UPDATE organisateurs SET portefeuille = portefeuille - ? WHERE id = ?")->execute([$amt / 100, $orgaId]);
        fin_audit($pdo, 'transfert_prepare', 'transfert', $tids[0] ?? 0, ['organisateur' => $orgaId, 'cents' => $amt, 'transferts' => $tids, 'versable' => $due, 'reserve' => $held, 'solde' => $pf], 'systeme');
        $pdo->commit();
        return $tids;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Étape 2 : appel Stripe avec clé d'idempotence (rejouable sans risque de double paiement).
 * Retourne 'envoye', 'echec' (erreur définitive : tout est remis en attente) ou 'incertain' (réseau : sera rejoué).
 */
function fin_execute_transfer(PDO $pdo, int $tid, string $acteur = 'systeme'): string {
    $st = $pdo->prepare("SELECT * FROM fin_transferts WHERE id = ?");
    $st->execute([$tid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t || $t['statut'] !== 'en_cours') return $t['statut'] ?? 'echec';
    $pdo->prepare("UPDATE fin_transferts SET tentatives = tentatives + 1 WHERE id = ?")->execute([$tid]);
    $payout = $t['type'] === 'payout';
    [$lib, $libMeta] = $payout ? ['', []] : fin_transfer_libelle($pdo, $tid);
    // Transfert d'un paiement reçu par moncamp.fr : adossé à sa charge (source_transaction), Stripe l'accepte même si les fonds sont encore
    // en attente et les livre à l'organisme dès qu'ils sont disponibles. Clé d'idempotence par paiement.
    $opts = ['idempotency_key' => 'colomap-transfer-' . $tid]; $src = [];
    if (!$payout && !empty($t['par_paiement'])) {
        $pid = (int)$pdo->query("SELECT paiement_id FROM fin_transfert_lignes WHERE transfert_id = " . (int)$tid . " LIMIT 1")->fetchColumn();
        $opts = ['idempotency_key' => 'moncamp-tr-paiement-' . $pid . '-' . $tid];
        try { $ch = fin_paiement_charge($pdo, $pid); }
        catch (Throwable $e) { $pdo->prepare("UPDATE fin_transferts SET erreur = ? WHERE id = ?")->execute([mb_substr('Charge introuvable : ' . $e->getMessage(), 0, 450), $tid]); return 'incertain'; }
        if ($ch) { $src = ['source_transaction' => $ch]; $libMeta['paiement'] = (string)$pid; }
    }
    try {
        $tr = $payout ? fin_stripe()->payouts->create([
            'amount' => (int)$t['montant_cents'], 'currency' => 'eur', 'statement_descriptor' => 'MONCAMP.FR',
            'description' => 'moncamp.fr : versement de vos séjours (réf. ' . substr($t['token'], 0, 10) . ')',
            'metadata' => ['transfert_id' => (string)$tid, 'organisateur_id' => (string)$t['organisateur_id']],
        ], ['stripe_account' => $t['stripe_account_id'], 'idempotency_key' => 'moncamp-payout-' . $tid]) : fin_stripe()->transfers->create([
            'amount' => (int)$t['montant_cents'], 'currency' => 'eur', 'destination' => $t['stripe_account_id'],
            'transfer_group' => 'ORGA' . $t['organisateur_id'],
            'description' => $lib,
            'metadata' => array_merge(['transfert_id' => (string)$tid, 'organisateur_id' => (string)$t['organisateur_id'], 'type' => $t['type']], $libMeta),
        ] + $src, $opts);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE fin_transferts SET statut = 'envoye', stripe_transfer_id = ?, erreur = NULL WHERE id = ?")->execute([$tr->id, $tid]);
        // Lignes partielles : la ligne repasse « attente » s'il reste de la réserve à verser, « verse » sinon
        $pdo->prepare("UPDATE fin_paiements p JOIN fin_transfert_lignes l ON l.paiement_id = p.id
                       SET p.statut = IF(p.net_cents - p.net_rembourse_cents - p.verse_cents <= 0, 'verse', 'attente')
                       WHERE l.transfert_id = ? AND p.statut = 'en_cours'")->execute([$tid]);
        // Transferts d'avant le calendrier hebdomadaire (sans lignes) : tout était versé
        $pdo->prepare("UPDATE fin_paiements SET statut = 'verse', verse_cents = GREATEST(0, net_cents - net_rembourse_cents) WHERE transfert_id = ? AND statut = 'en_cours'")->execute([$tid]);
        fin_audit($pdo, 'transfert_envoye', 'transfert', $tid, ['stripe' => $tr->id, 'cents' => (int)$t['montant_cents']], $acteur);
        // Journal : un payout est un versement vers la banque ; un transfert legacy aussi, sauf si le compte est en versements manuels (le payout comptera)
        $manuel = (int)$pdo->query("SELECT COALESCE(MAX(payout_manuel), 0) FROM stripe_comptes WHERE account_id = " . $pdo->quote((string)$t['stripe_account_id']))->fetchColumn();
        // Versements automatiques : un transfert vers le compte Stripe n'est pas encore un versement bancaire (le payout relevé le sera)
        $manuel = $manuel || (int)$pdo->query("SELECT COALESCE(MAX(payout_auto), 0) FROM stripe_comptes WHERE account_id = " . $pdo->quote((string)$t['stripe_account_id']))->fetchColumn();
        if (!$payout) fin_ledger_add($pdo, 'transfert', (int)$t['organisateur_id'], (int)$t['montant_cents'], $tr->id, 'transfert', $tid, 'Transfert sur votre compte Stripe (paiements reçus avant activation)', $acteur, 'legacy');
        if ($payout || !$manuel) fin_ledger_add($pdo, 'versement', (int)$t['organisateur_id'], -(int)$t['montant_cents'], $tr->id, 'transfert', $tid, 'Versement', $acteur, $payout ? 'destination' : 'legacy');
        $pdo->commit();
        // Le paiement reçu par l'organisme (py_…) n'hérite pas de la description du Transfer : on la recopie (idempotent, un échec est seulement journalisé)
        if (!$payout && !empty($tr->destination_payment)) {
            try { fin_stripe()->charges->update(is_object($tr->destination_payment) ? $tr->destination_payment->id : (string)$tr->destination_payment, ['description' => $lib, 'metadata' => $libMeta],
                    ['stripe_account' => (string)$t['stripe_account_id'], 'idempotency_key' => 'moncamp-libelle-' . $tid]); }
            catch (Throwable $e) { fin_audit($pdo, 'libelle_transfert_echec', 'transfert', $tid, ['erreur' => mb_substr($e->getMessage(), 0, 200)], $acteur); }
        }
        return 'envoye';
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $code = (int)$e->getHttpStatus();
        $definitive = $code >= 400 && $code < 500 && $code !== 429;
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$definitive) {
            $pdo->prepare("UPDATE fin_transferts SET erreur = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 450), $tid]);
            return 'incertain';
        }
        // Erreur définitive (compte non prêt, solde plateforme insuffisant...) : on remet les lignes en attente et on rend le solde
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE fin_transferts SET statut = 'echec', erreur = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 450), $tid]);
        $pdo->prepare("UPDATE fin_paiements p JOIN fin_transfert_lignes l ON l.paiement_id = p.id
                       SET p.verse_cents = p.verse_cents - l.montant_cents, p.statut = 'attente', p.transfert_id = NULL
                       WHERE l.transfert_id = ? AND p.statut = 'en_cours'")->execute([$tid]);
        $pdo->prepare("UPDATE fin_paiements SET statut = 'attente', transfert_id = NULL WHERE transfert_id = ? AND statut = 'en_cours'")->execute([$tid]);
        if (!$payout) $pdo->prepare("UPDATE organisateurs SET portefeuille = portefeuille + ? WHERE id = ?")->execute([(int)($t['brut_cents'] ?: $t['montant_cents']) / 100, $t['organisateur_id']]);
        fin_audit($pdo, 'transfert_echec', 'transfert', $tid, ['erreur' => $e->getMessage()], $acteur);
        $pdo->commit();
        return 'echec';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare("UPDATE fin_transferts SET erreur = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 450), $tid]);
        return 'incertain';
    }
}

/** Rejoue les transferts restés « en_cours » (réponse perdue) : la clé d'idempotence garantit qu'un seul transfert existe côté Stripe */
function fin_retry_pending(PDO $pdo, array &$rep): void {
    $ids = $pdo->query("SELECT id FROM fin_transferts WHERE statut = 'en_cours' AND updated_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND tentatives < 10")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $r = fin_execute_transfer($pdo, (int)$id, 'cron');
        if ($r === 'envoye') $rep['transferts']++;
        elseif ($r === 'echec') $rep['erreurs']++;
    }
}

// ---------------------------------------------------------------------------------------------
// Remboursement / litige après versement : reversement Stripe, sinon dette
// ---------------------------------------------------------------------------------------------
/**
 * À appeler dans la transaction d'un remboursement : si la ligne a déjà reçu plus que son nouveau net, demande la récupération du trop-perçu.
 * $verse = verse_cents, $netAvant / $netApres = net restant dû à l'organisme avant / après le remboursement.
 */
function fin_reversal_demander(PDO $pdo, array $p, int $netRembourseApres): void {
    $verse = (int)$p['verse_cents'];
    $avant = max(0, $verse - ((int)$p['net_cents'] - (int)$p['net_rembourse_cents']));
    $apres = max(0, $verse - ((int)$p['net_cents'] - $netRembourseApres));
    $m = $apres - $avant;
    if ($m <= 0) return;
    $pdo->prepare("INSERT INTO fin_reversals (organisateur_id, paiement_id, montant_cents, statut) VALUES (?, ?, ?, 'a_faire')")->execute([$p['organisateur_id'], $p['id'], $m]);
    fin_audit($pdo, 'reversement_demande', 'inscription', $p['inscription_id'], ['cents' => $m], 'webhook');
}

/** Traite les demandes de reversement en attente ; retourne le nombre de reversements Stripe effectués */
function fin_process_reversals(PDO $pdo, array &$rep = []): int {
    fin_ensure($pdo);
    $done = 0;
    // 1. opérations « prévues » restées sans réponse (réseau) : rejouées avec la MÊME clé d'idempotence
    foreach ($pdo->query("SELECT id FROM fin_reversal_ops WHERE statut = 'prevu'")->fetchAll(PDO::FETCH_COLUMN) as $opId) {
        if (fin_reversal_exec($pdo, (int)$opId) === 'ok') $done++;
    }
    // 2. nouvelles demandes
    foreach ($pdo->query("SELECT * FROM fin_reversals WHERE statut = 'a_faire' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $rv) {
        $done += fin_reversal_traiter($pdo, $rv);
    }
    if (isset($rep['reversements'])) $rep['reversements'] += $done;
    return $done;
}

function fin_reversal_traiter(PDO $pdo, array $rv): int {
    $done = 0; $revId = (int)$rv['id'];
    $sum = function (array $statuts) use ($pdo, $revId): int {
        $in = "'" . implode("','", $statuts) . "'";
        return (int)$pdo->query("SELECT COALESCE(SUM(montant_cents), 0) FROM fin_reversal_ops WHERE reversal_id = $revId AND statut IN ($in)")->fetchColumn();
    };
    $reste = (int)$rv['montant_cents'] - $sum(['ok', 'prevu']);
    if ($reste > 0) {
        $st = $pdo->prepare("SELECT l.transfert_id, l.montant_cents lm, t.montant_cents tm, t.reverse_cents FROM fin_transfert_lignes l JOIN fin_transferts t ON t.id = l.transfert_id
                             WHERE l.paiement_id = ? AND t.statut = 'envoye' AND t.stripe_transfer_id IS NOT NULL ORDER BY t.id DESC");
        $st->execute([$rv['paiement_id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if ($reste <= 0) break;
            $pdo->beginTransaction();
            try {
                $t = $pdo->prepare("SELECT montant_cents, reverse_cents FROM fin_transferts WHERE id = ? FOR UPDATE");
                $t->execute([$l['transfert_id']]);
                $tr = $t->fetch(PDO::FETCH_ASSOC);
                $deja = $pdo->prepare("SELECT COALESCE(SUM(o.montant_cents), 0) FROM fin_reversal_ops o JOIN fin_reversals r ON r.id = o.reversal_id
                                       WHERE r.paiement_id = ? AND o.transfert_id = ? AND o.statut IN ('ok','prevu')");
                $deja->execute([$rv['paiement_id'], $l['transfert_id']]);
                $cap = min((int)$l['lm'] - (int)$deja->fetchColumn(), (int)$tr['montant_cents'] - (int)$tr['reverse_cents']);
                $m = min($reste, $cap);
                if ($m <= 0) { $pdo->rollBack(); continue; }
                $pdo->prepare("INSERT INTO fin_reversal_ops (reversal_id, transfert_id, montant_cents, statut) VALUES (?, ?, ?, 'prevu')")->execute([$revId, $l['transfert_id'], $m]);
                $opId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE fin_transferts SET reverse_cents = reverse_cents + ? WHERE id = ?")->execute([$m, $l['transfert_id']]);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('fin_reversal_traiter: ' . $e->getMessage()); continue; }
            $reste -= $m;
            if (fin_reversal_exec($pdo, $opId) === 'ok') $done++;
        }
    }
    // Bilan de la demande
    $ok = $sum(['ok']); $prevu = $sum(['prevu']); $echec = $sum(['echec']);
    if ($ok >= (int)$rv['montant_cents']) $statut = 'fait';
    elseif ($prevu > 0) $statut = 'a_faire';          // réponse Stripe attendue : on rejouera
    elseif ($echec > 0 || $reste > 0) {
        // Plus rien à reverser (solde Stripe insuffisant, transfert encore en vol, argent non encore transféré) : dette, compensée sur les prochains versements
        $enVol = (int)$pdo->query("SELECT COUNT(*) FROM fin_transfert_lignes l JOIN fin_transferts t ON t.id = l.transfert_id WHERE l.paiement_id = " . (int)$rv['paiement_id'] . " AND t.statut = 'en_cours'")->fetchColumn();
        $statut = ($enVol && !$echec) ? 'a_faire' : 'dette';
    } else $statut = 'a_faire';
    if ($statut !== $rv['statut'] || $ok !== (int)$rv['recupere_cents']) {
        $pdo->prepare("UPDATE fin_reversals SET statut = ?, recupere_cents = ?, maj = NOW() WHERE id = ?")->execute([$statut, $ok, $revId]);
        if ($statut === 'dette') fin_audit($pdo, 'dette_organisme', 'reversement', $revId, ['du' => (int)$rv['montant_cents'] - $ok, 'recupere' => $ok], 'systeme');
    }
    return $done;
}

/** Exécute une opération de reversement : 'ok' | 'echec' (définitif) | 'incertain' (sera rejouée avec la même clé) */
function fin_reversal_exec(PDO $pdo, int $opId): string {
    $st = $pdo->prepare("SELECT o.*, t.stripe_transfer_id, r.organisateur_id, r.paiement_id FROM fin_reversal_ops o JOIN fin_transferts t ON t.id = o.transfert_id
                         JOIN fin_reversals r ON r.id = o.reversal_id WHERE o.id = ?");
    $st->execute([$opId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o || $o['statut'] !== 'prevu') return $o['statut'] ?? 'echec';
    try {
        $rev = fin_stripe()->transfers->createReversal($o['stripe_transfer_id'], [
            'amount' => (int)$o['montant_cents'],
            'description' => 'moncamp.fr : remboursement d\'une inscription après versement',
            'metadata' => ['reversal_op' => (string)$opId, 'organisateur_id' => (string)$o['organisateur_id']],
        ], ['idempotency_key' => 'colomap-rev-' . $opId]);
        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE fin_reversal_ops SET statut = 'ok', stripe_reversal_id = ?, erreur = NULL WHERE id = ? AND statut = 'prevu'");
        $up->execute([$rev->id, $opId]);
        if ($up->rowCount() === 1) { // l'argent est revenu chez moncamp.fr : le solde de l'organisme n'a plus à rester négatif de ce montant
            $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) + ? WHERE id = ?")->execute([(int)$o['montant_cents'] / 100, $o['organisateur_id']]);
            fin_audit($pdo, 'reversement_ok', 'reversement', $opId, ['cents' => (int)$o['montant_cents'], 'stripe' => $rev->id], 'systeme');
        }
        $pdo->commit();
        return 'ok';
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $code = (int)$e->getHttpStatus();
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($code >= 400 && $code < 500 && $code !== 429) { // ex. solde insuffisant sur le compte connecté : dette
            $pdo->beginTransaction();
            $up = $pdo->prepare("UPDATE fin_reversal_ops SET statut = 'echec', erreur = ? WHERE id = ? AND statut = 'prevu'");
            $up->execute([mb_substr($e->getMessage(), 0, 450), $opId]);
            if ($up->rowCount() === 1) $pdo->prepare("UPDATE fin_transferts SET reverse_cents = GREATEST(0, reverse_cents - ?) WHERE id = ?")->execute([(int)$o['montant_cents'], $o['transfert_id']]);
            fin_audit($pdo, 'reversement_echec', 'reversement', $opId, ['erreur' => mb_substr($e->getMessage(), 0, 200)], 'systeme');
            $pdo->commit();
            return 'echec';
        }
        $pdo->prepare("UPDATE fin_reversal_ops SET erreur = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 450), $opId]);
        return 'incertain';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('fin_reversal_exec: ' . $e->getMessage());
        return 'incertain';
    }
}

/** Administrateur : retente une dette (nouvelles opérations, donc nouvelles clés d'idempotence) */
function fin_reversal_retenter(PDO $pdo, int $revId, string $acteur): bool {
    $st = $pdo->prepare("UPDATE fin_reversals SET statut = 'a_faire' WHERE id = ? AND statut = 'dette'");
    $st->execute([$revId]);
    if ($st->rowCount()) fin_audit($pdo, 'reversement_retente', 'reversement', $revId, [], $acteur);
    return (bool)$st->rowCount();
}

// ---------------------------------------------------------------------------------------------
// Vue pour le tableau de bord de l'organisme
// ---------------------------------------------------------------------------------------------
/** Lignes de paiement non soldées d'un organisme (ou de tous si $orgaId = 0), avec les données du séjour */
function fin_pending_rows(PDO $pdo, int $orgaId = 0): array {
    $sql = "SELECT p.id, p.organisateur_id, p.camp_id, p.net_cents, p.net_rembourse_cents, p.verse_cents, p.date_deblocage, p.statut, c.nom camp, c.date_debut, c.annul_jours_moitie jm
            FROM fin_paiements p JOIN camps c ON c.id = p.camp_id WHERE p.mode = 'legacy' AND p.statut IN ('attente','bloque','litige','en_cours')" . ($orgaId ? " AND p.organisateur_id = ?" : "");
    $st = $pdo->prepare($sql);
    $st->execute($orgaId ? [$orgaId] : []);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Situation financière d'un organisme (centimes) */
function fin_overview(PDO $pdo, int $orgaId): array {
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT portefeuille, solde_points, jour_versement FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    $o = $st->fetch(PDO::FETCH_ASSOC) ?: ['portefeuille' => 0, 'solde_points' => 0, 'jour_versement' => null];

    $st = $pdo->prepare("SELECT SUM(brut_cents - rembourse_cents) brut_total, SUM(commission_cents) commission_total,
                COALESCE(SUM(commission_cents - IF(annul_com = 1, 0, ROUND(commission_cents * rembourse_cents / brut_cents))), 0) com_nette, COALESCE(SUM(commission_fixe_cents), 0) fixe
         FROM fin_paiements WHERE organisateur_id = ?");
    $st->execute([$orgaId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT COALESCE(SUM(montant_cents - reverse_cents), 0) FROM fin_transferts WHERE organisateur_id = ? AND statut = 'envoye'");
    $st->execute([$orgaId]);
    $verse = (int)$st->fetchColumn();

    $pct = fin_reserve_pct(); $now = date('Y-m-d H:i:s');
    $jour = $o['jour_versement'] ? (int)$o['jour_versement'] : null;
    $nextDay = $jour ? fin_next_payout_date($jour) : null;
    $asNext = $nextDay ? $nextDay . ' 23:59:59' : $now;
    $t = ['recevoir' => 0, 'deblocable' => 0, 'reserve' => 0, 'delai' => 0, 'suspendu' => 0, 'en_cours' => 0, 'prochain' => 0];
    $camps = [];
    foreach (fin_pending_rows($pdo, $orgaId) as $r) {
        $due = max(0, (int)$r['net_cents'] - (int)$r['net_rembourse_cents']); $left = max(0, $due - (int)$r['verse_cents']);
        $t['recevoir'] += $left;
        if ($r['statut'] === 'bloque' || $r['statut'] === 'litige') { $t['suspendu'] += $left; $p = ['left' => $left, 'ok' => 0, 'held' => 0, 'wait' => 0]; $pn = $p; }
        elseif ($r['statut'] === 'en_cours') { $t['en_cours'] += $left; continue; }
        else { $p = fin_row_parts($r, $pct, $now); $pn = fin_row_parts($r, $pct, $asNext); }
        $t['deblocable'] += $p['ok']; $t['reserve'] += $p['held']; $t['delai'] += $p['wait']; $t['prochain'] += $pn['ok'];
        $k = (int)$r['camp_id'];
        if (!isset($camps[$k])) $camps[$k] = ['nom' => $r['camp'], 'date_debut' => $r['date_debut'], 'n' => 0, 'ok' => 0, 'held' => 0, 'wait' => 0, 'libere' => fin_reserve_release_day($r['date_debut'], (int)$r['jm'])];
        $camps[$k]['n']++; $camps[$k]['ok'] += $p['ok']; $camps[$k]['held'] += $p['held']; $camps[$k]['wait'] += $p['wait'];
    }
    $solde = (int)round((float)$o['portefeuille'] * 100);
    // Le prochain versement ne peut pas dépasser le solde (compensation des remboursements / dettes)
    $prochain = max(0, min($t['prochain'], $solde));
    $dettes = (int)$pdo->query("SELECT COALESCE(SUM(montant_cents - recupere_cents), 0) FROM fin_reversals WHERE statut = 'dette' AND organisateur_id = " . (int)$orgaId)->fetchColumn();
    return [
        'jour' => $jour, 'jour_nom' => $jour ? FIN_JOURS[$jour] : null, 'prochain_date' => $nextDay,
        'prochain' => $nextDay ? ['d' => $nextDay, 'cents' => $prochain] : null,
        'reserve_cents' => $t['reserve'], 'reserve_pct' => $pct, 'delai_cents' => $t['delai'], 'delai_jours' => fin_release_delay_days(),
        'suspendu_cents' => $t['suspendu'], 'en_cours_cents' => $t['en_cours'], 'dette_cents' => max($dettes, max(0, -$solde)),
        'commission_nette_cents' => (int)$s['com_nette'], 'commission_fixe_total_cents' => (int)$s['fixe'],
        'solde_cents' => $solde, 'points' => (int)$o['solde_points'],
        'a_recevoir_cents' => $t['recevoir'], 'deblocable_cents' => min($t['deblocable'], max(0, $solde)),
        'verse_cents' => $verse, 'brut_total_cents' => (int)($s['brut_total'] ?? 0), 'commission_total_cents' => (int)($s['commission_total'] ?? 0),
        // part du solde non rattachée à un paiement suivi (ancien système) : à régler par l'administration
        'historique_cents' => max(0, $solde - $t['recevoir']),
        'prochains' => array_values($camps),
    ];
}
