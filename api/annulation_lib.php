<?php
// api/annulation_lib.php : annulation d'une inscription payée + « garantie annulation » (service facultatif de ColoMap).
//
// MENTION JURIDIQUE : tant que ColoMap n'est pas intermédiaire d'assurance (ORIAS) ou n'a pas de contrat avec un assureur,
// la garantie est un SERVICE payant de ColoMap (frais), jamais une « assurance ». Le réglage garantie_partenaire prévoit
// le nom de l'assureur partenaire le jour où il existe ; ne l'afficher comme assurance qu'à ce moment-là, avec les documents d'information requis.
//
// Flux d'argent : la garantie est un supplément en % du total, ligne Stripe séparée, colonne fin_paiements.garantie_cents :
// JAMAIS dans brut / commission / net de l'organisme. Remboursement : un seul refund Stripe sur le PaymentIntent ; le solde de l'organisme
// est ajusté par le webhook charge.refunded (fin_apply_refund), jamais ici.
require_once __DIR__ . '/finance_lib.php';

const FIN_GARANTIE_MOTIFS = ['maladie' => 'Maladie ou accident de l\'enfant', 'deces' => 'Décès d\'un proche', 'imprevu' => 'Imprévu familial', 'autre' => 'Autre motif'];
const FIN_GARANTIE_COUVERTS = ['maladie', 'deces']; // motifs pris en charge par la garantie

function fin_garantie_active(): bool { return fin_reglages()['garantie_active'] === 1; }

/** Supplément « garantie annulation » (centimes) sur un total payé HORS garantie : 0 si total nul ou taux nul */
function fin_garantie_cents(int $totalCents, ?float $pct = null): int {
    $pct = $pct ?? fin_reglages()['garantie_pct'];
    return $totalCents > 0 && $pct > 0 ? (int)round($totalCents * $pct / 100) : 0;
}

/** Enregistre le réglage de la garantie (borné) et le journalise */
function fin_set_garantie(PDO $pdo, bool $active, float $pct, string $partenaire, string $acteur): void {
    fin_ensure($pdo);
    $pct = round(max(0.0, min(10.0, $pct)), 2); $partenaire = mb_substr(trim($partenaire), 0, 90);
    $old = fin_reglages(true);
    $st = $pdo->prepare("INSERT INTO fin_reglages (cle, valeur, par) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), par = VALUES(par), maj = NOW()");
    $par = substr($acteur, 0, 40);
    $st->execute(['garantie_active', $active ? '1' : '0', $par]);
    $st->execute(['garantie_pct', (string)$pct, $par]);
    $st->execute(['garantie_partenaire', $partenaire, $par]);
    fin_audit($pdo, 'garantie_modifiee', 'reglage', 'garantie', ['avant' => $old, 'apres' => ['active' => $active, 'pct' => $pct, 'partenaire' => $partenaire]], $acteur);
    fin_reglages(true);
}

/** Part remboursée selon les conditions du séjour : 100 % jusqu'à $joursTotal jours avant le début, 50 % jusqu'à $joursMoitie, ensuite 0 */
function fin_remboursable_pct(int $joursAvant, int $joursTotal, int $joursMoitie): int {
    if ($joursAvant >= $joursTotal) return 100;
    if ($joursAvant >= $joursMoitie) return 50;
    return 0;
}

/**
 * Calcul serveur d'une annulation : [à la charge de l'organisme, pris en charge par la garantie (ColoMap), total remboursé à la famille].
 * $brutRestant = brut payé non encore remboursé. Avec garantie ET motif couvert, la famille récupère tout le brut :
 * l'organisme ne rembourse que sa part selon ses conditions, ColoMap complète. Sinon seulement la part de l'organisme.
 */
function fin_annul_montants(int $brutRestant, int $joursAvant, int $joursTotal, int $joursMoitie, bool $garantie, string $motif): array {
    $brutRestant = max(0, $brutRestant);
    $orga = (int)round($brutRestant * fin_remboursable_pct($joursAvant, $joursTotal, $joursMoitie) / 100);
    $pec = ($garantie && in_array($motif, FIN_GARANTIE_COUVERTS, true)) ? $brutRestant - $orga : 0;
    return [$orga, $pec, $orga + $pec];
}

/** Jours entiers entre aujourd'hui et le début du séjour (négatif si commencé) */
function fin_jours_avant(string $dateDebut): int {
    return (int)floor((strtotime($dateDebut) - strtotime(date('Y-m-d'))) / 86400);
}

/** Inscription payée annulable par ce parent (jeton de réservation) : paiement, séjour, demande en cours. null si introuvable. */
function fin_annul_context(PDO $pdo, string $resaToken, int $parentId): ?array {
    fin_ensure($pdo);
    if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $resaToken)) return null;
    $st = $pdo->prepare("SELECT i.id inscription_id, i.statut, e.prenom, e.nom, c.nom camp, c.date_debut, c.annul_jours_total jt, c.annul_jours_moitie jm, c.organisateur_id,
                                p.id paiement_id, p.brut_cents, p.rembourse_cents, p.garantie_cents
                         FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id JOIN camps c ON c.id = i.camp_id
                         JOIN fin_paiements p ON p.inscription_id = i.id
                         WHERE i.reservation_token = ? AND e.parent_id = ?");
    $st->execute([$resaToken, $parentId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['jours'] = fin_jours_avant((string)$r['date_debut']);
    $r['restant'] = (int)$r['brut_cents'] - (int)$r['rembourse_cents'];
    $r['annule'] = in_array($r['statut'], ['Annulé', 'annule', 'Annule'], true);
    $st = $pdo->prepare("SELECT * FROM fin_annulations WHERE inscription_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$r['inscription_id']]);
    $r['demande'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $r['annulable'] = !$r['annule'] && $r['restant'] > 0 && $r['jours'] >= 0 && (!$r['demande'] || $r['demande']['statut'] === 'refusee');
    return $r;
}

/** Crée la demande d'annulation (montants figés côté serveur au moment de la demande). Retourne [ok, message]. */
function fin_annul_demander(PDO $pdo, string $resaToken, int $parentId, string $motif, string $commentaire): array {
    $c = fin_annul_context($pdo, $resaToken, $parentId);
    if (!$c || !$c['annulable']) return [false, 'Cette inscription ne peut plus faire l\'objet d\'une demande d\'annulation.'];
    if (!isset(FIN_GARANTIE_MOTIFS[$motif])) return [false, 'Choisissez un motif.'];
    [$orga, $pec] = fin_annul_montants($c['restant'], $c['jours'], (int)$c['jt'], (int)$c['jm'], (int)$c['garantie_cents'] > 0, $motif);
    $pdo->prepare("INSERT INTO fin_annulations (token, inscription_id, organisateur_id, parent_id, motif, commentaire, garantie, organisme_cents, garantie_pec_cents)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([bin2hex(random_bytes(16)), $c['inscription_id'], $c['organisateur_id'], $parentId, $motif, mb_substr(trim($commentaire), 0, 500) ?: null, (int)$c['garantie_cents'] > 0 ? 1 : 0, $orga, $pec]);
    fin_audit($pdo, 'annulation_demandee', 'inscription', $c['inscription_id'], ['motif' => $motif, 'organisme' => $orga, 'garantie_pec' => $pec], 'parent:' . $parentId);
    return [true, 'Votre demande a été transmise à l\'organisme.'];
}

/**
 * Traite une demande côté organisme (refus, ou acceptation = remboursement Stripe sur le PaymentIntent, clé d'idempotence = jeton de la demande).
 * Le solde de l'organisme (même négatif si le net a déjà été versé : compensé sur ses versements suivants) est ajusté par le webhook, pas ici.
 * Retourne [ok, message].
 */
function fin_annul_traiter(PDO $pdo, string $annulToken, int $orgaId, bool $accepte, string $reponse, string $acteur): array {
    fin_ensure($pdo);
    $reponse = mb_substr(trim($reponse), 0, 500);
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT * FROM fin_annulations WHERE token = ? AND organisateur_id = ? AND statut = 'demande' FOR UPDATE");
    $st->execute([$annulToken, $orgaId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) { $pdo->rollBack(); return [false, 'Demande introuvable ou déjà traitée.']; }
    if (!$accepte) {
        $pdo->prepare("UPDATE fin_annulations SET statut = 'refusee', reponse = ?, traite_at = NOW() WHERE id = ?")->execute([$reponse ?: null, $a['id']]);
        fin_audit($pdo, 'annulation_refusee', 'inscription', $a['inscription_id'], [], $acteur);
        $pdo->commit();
        return [true, 'Demande refusée.'];
    }
    $st = $pdo->prepare("SELECT * FROM fin_paiements WHERE inscription_id = ? FOR UPDATE");
    $st->execute([$a['inscription_id']]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    $total = (int)$a['organisme_cents'] + (int)$a['garantie_pec_cents'];
    if (!$p || !$p['payment_intent'] || (int)$p['rembourse_cents'] > 0 || $total > (int)$p['brut_cents']) {
        $pdo->rollBack();
        return [false, 'Remboursement automatique impossible (paiement introuvable ou déjà remboursé) : contactez l\'assistance.'];
    }
    // Part ColoMap et ciblage enregistrés AVANT l'appel Stripe : le webhook les lit à réception de charge.refunded
    $pdo->prepare("UPDATE fin_paiements SET garantie_pec_cents = garantie_pec_cents + ?, annul_cible = ? WHERE id = ?")->execute([(int)$a['garantie_pec_cents'], $total > 0 ? 1 : 0, $p['id']]);
    $pdo->prepare("UPDATE fin_annulations SET statut = 'acceptee', reponse = ?, traite_at = NOW() WHERE id = ?")->execute([$reponse ?: null, $a['id']]);
    $pdo->prepare("UPDATE inscriptions SET statut = 'Annulé' WHERE id = ?")->execute([$a['inscription_id']]);
    $pdo->commit();
    try {
        $refundId = null;
        if ($total > 0) { // total = 0 : conditions à 0 %, l'inscription est annulée et l'organisme garde la somme
            $r = fin_stripe()->refunds->create(
                ['payment_intent' => $p['payment_intent'], 'amount' => $total, 'metadata' => ['annulation' => $a['token'], 'inscription' => (string)$a['inscription_id']]],
                ['idempotency_key' => 'annul-' . $a['token']]
            );
            $refundId = $r->id;
        }
        $pdo->prepare("UPDATE fin_annulations SET refund_id = ? WHERE id = ?")->execute([$refundId, $a['id']]);
        fin_audit($pdo, 'annulation_acceptee', 'inscription', $a['inscription_id'], ['total' => $total, 'organisme' => (int)$a['organisme_cents'], 'garantie_pec' => (int)$a['garantie_pec_cents'], 'refund' => $refundId], $acteur);
        return [true, $total > 0 ? 'Annulation acceptée : ' . fin_eur($total) . ' remboursés à la famille.' : 'Annulation acceptée (aucun remboursement selon les conditions du séjour).'];
    } catch (Throwable $e) { // Stripe a refusé : la demande redevient « en attente », rien n'est remboursé
        error_log('fin_annul_traiter: ' . $e->getMessage());
        $pdo->prepare("UPDATE fin_paiements SET garantie_pec_cents = GREATEST(0, garantie_pec_cents - ?), annul_cible = 0 WHERE id = ?")->execute([(int)$a['garantie_pec_cents'], $p['id']]);
        $pdo->prepare("UPDATE fin_annulations SET statut = 'demande', reponse = NULL, traite_at = NULL WHERE id = ?")->execute([$a['id']]);
        $pdo->prepare("UPDATE inscriptions SET statut = 'Confirmé' WHERE id = ?")->execute([$a['inscription_id']]);
        fin_audit($pdo, 'annulation_echec_stripe', 'inscription', $a['inscription_id'], ['erreur' => mb_substr($e->getMessage(), 0, 200)], $acteur);
        return [false, 'Le remboursement Stripe a échoué : la demande reste en attente, réessayez plus tard.'];
    }
}

/** Demandes d'annulation d'un organisme (en attente d'abord, puis les plus récentes) */
function fin_annul_liste(PDO $pdo, int $orgaId, int $limit = 100): array {
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT a.*, e.prenom, e.nom, c.nom camp, c.date_debut, p.brut_cents
                         FROM fin_annulations a JOIN inscriptions i ON i.id = a.inscription_id JOIN enfants e ON e.id = i.enfant_id
                         JOIN camps c ON c.id = i.camp_id LEFT JOIN fin_paiements p ON p.inscription_id = a.inscription_id
                         WHERE a.organisateur_id = ? ORDER BY (a.statut = 'demande') DESC, a.id DESC LIMIT " . (int)$limit);
    $st->execute([$orgaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
