<?php
// api/annulation_lib.php : annulation d'une inscription payée par la famille elle-même, remboursement automatique.
//
// RÈGLES (annoncées à la famille AVANT le paiement et rappelées sur la page d'annulation) :
//  - chaque organisme fixe, par séjour, ses conditions : 100 % jusqu'à N jours avant le début, 50 % jusqu'à M jours, ensuite rien (défaut 30 / 15) ;
//  - ColoMap GARDE sa commission (frais de dossier et de gestion : Stripe ne rend pas ses frais) ;
//  - remboursement à la famille = part remboursable du montant payé − commission retenue (jamais négatif) ;
//  - l'organisme est débité uniquement de ce montant (sa part nette remboursée) : fin_apply_refund, déclenché par le webhook charge.refunded, ajuste son solde ;
//    s'il a déjà été versé, le solde devient négatif et se compense sur ses versements suivants. Aucune validation manuelle.
// Une commande de plusieurs enfants : chaque inscription a sa propre ligne de paiement, donc son propre montant (prorata naturel).
require_once __DIR__ . '/finance_lib.php';

const FIN_ANNUL_MOTIFS = ['maladie' => 'Maladie ou accident de l\'enfant', 'deces' => 'Décès d\'un proche', 'imprevu' => 'Imprévu familial', 'autre' => 'Autre motif'];
const FIN_ANNUL_DEFAUT = [30, 15]; // jours : 100 % / 50 %

/** Part remboursable selon les conditions du séjour : 100 % jusqu'à $joursTotal jours avant le début, 50 % jusqu'à $joursMoitie, ensuite 0 */
function fin_remboursable_pct(int $joursAvant, int $joursTotal, int $joursMoitie): int {
    if ($joursAvant >= $joursTotal) return 100;
    if ($joursAvant >= $joursMoitie) return 50;
    return 0;
}

/**
 * Calcul serveur : [remboursement à la famille, commission retenue, pourcentage appliqué].
 * Part remboursable = brut × pct ; la commission de la ligne (déjà calculée au paiement) est retenue sur cette part.
 */
function fin_annul_montants(int $brutRestant, int $commissionCents, int $joursAvant, int $joursTotal, int $joursMoitie): array {
    $brutRestant = max(0, $brutRestant);
    $pct = fin_remboursable_pct($joursAvant, $joursTotal, $joursMoitie);
    $part = (int)round($brutRestant * $pct / 100);
    $retenue = min($part, max(0, $commissionCents));
    return [$part - $retenue, $retenue, $pct];
}

/** Jours entiers entre aujourd'hui et le début du séjour (négatif si commencé) */
function fin_jours_avant(string $dateDebut): int {
    return (int)floor((strtotime($dateDebut) - strtotime(date('Y-m-d'))) / 86400);
}

/** Phrase de conditions d'un séjour, pour le parent (avant paiement et page d'annulation) */
function fin_annul_texte(int $jt, int $jm): string {
    return 'Annulation : remboursement à 100 % jusqu\'à ' . $jt . ' jours avant le début du séjour, à 50 % jusqu\'à ' . $jm . ' jours avant, puis aucun remboursement. '
         . 'La commission de service ColoMap (frais de dossier et de gestion : ' . number_format(fin_commission_pct(), 2, ',', ' ') . ' % + ' . fin_eur(fin_commission_fixe_cents())
         . ' par inscription) reste acquise : elle est déduite du remboursement.';
}

/** Inscription payée du parent (jeton de réservation) avec ce qu'il peut encore obtenir. null si introuvable. */
function fin_annul_context(PDO $pdo, string $resaToken, int $parentId): ?array {
    fin_ensure($pdo);
    if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $resaToken)) return null;
    $st = $pdo->prepare("SELECT i.id inscription_id, i.statut, e.prenom, e.nom, c.nom camp, c.date_debut, c.annul_jours_total jt, c.annul_jours_moitie jm, c.organisateur_id,
                                p.brut_cents, p.commission_cents, p.rembourse_cents, p.annul_attendu
                         FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id JOIN camps c ON c.id = i.camp_id
                         JOIN fin_paiements p ON p.inscription_id = i.id
                         WHERE i.reservation_token = ? AND e.parent_id = ?");
    $st->execute([$resaToken, $parentId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['jours'] = fin_jours_avant((string)$r['date_debut']);
    $r['restant'] = (int)$r['brut_cents'] - (int)$r['rembourse_cents'];
    $r['annule'] = in_array($r['statut'], ['Annulé', 'annule', 'Annule'], true);
    [$r['rembourse'], $r['retenue'], $r['pct']] = fin_annul_montants($r['restant'], (int)$r['commission_cents'], $r['jours'], (int)$r['jt'], (int)$r['jm']);
    $r['annulable'] = !$r['annule'] && $r['restant'] > 0 && $r['jours'] >= 0 && (int)$r['annul_attendu'] === 0;
    return $r;
}

/**
 * Annule l'inscription et rembourse automatiquement. Retourne [ok, message].
 * Ordre : (1) en transaction, verrouille la ligne de paiement, enregistre l'annulation + le remboursement attendu + inscription « Annulé » ;
 * (2) Stripe refunds->create (clé d'idempotence) ; en cas d'échec Stripe, tout est annulé (l'inscription reste valide).
 * Le solde de l'organisme n'est PAS touché ici : le webhook charge.refunded (fin_apply_refund) s'en charge.
 */
function fin_annul_executer(PDO $pdo, string $resaToken, int $parentId, string $motif, string $commentaire): array {
    if (!isset(FIN_ANNUL_MOTIFS[$motif])) return [false, 'Choisissez un motif.'];
    $c = fin_annul_context($pdo, $resaToken, $parentId);
    if (!$c || !$c['annulable']) return [false, 'Cette inscription ne peut plus être annulée en ligne.'];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM fin_paiements WHERE inscription_id = ? FOR UPDATE");
        $st->execute([$c['inscription_id']]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)$p['rembourse_cents'] > 0 || (int)$p['annul_attendu'] > 0 || (int)$p['annul_com'] === 1) { $pdo->rollBack(); return [false, 'Cette inscription a déjà été annulée ou remboursée.']; }
        [$rembourse, $retenue, $pct] = fin_annul_montants((int)$p['brut_cents'], (int)$p['commission_cents'], $c['jours'], (int)$c['jt'], (int)$c['jm']);
        $pdo->prepare("INSERT INTO fin_annulations (inscription_id, organisateur_id, parent_id, motif, commentaire, pct, rembourse_cents, commission_retenue_cents) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$c['inscription_id'], $c['organisateur_id'], $parentId, $motif, mb_substr(trim($commentaire), 0, 500) ?: null, $pct, $rembourse, $retenue]);
        $annulId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE fin_paiements SET annul_attendu = ?, annul_com = 1 WHERE id = ?")->execute([$rembourse, $p['id']]);
        $pdo->prepare("UPDATE inscriptions SET statut = 'Annulé' WHERE id = ?")->execute([$c['inscription_id']]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('fin_annul_executer: ' . $e->getMessage()); return [false, 'Une erreur est survenue, réessayez plus tard.']; }

    try {
        $refundId = null;
        if ($rembourse > 0) { // 0 : conditions à 0 % ou commission supérieure à la part remboursable, rien à rendre
            $refundId = fin_stripe()->refunds->create(
                ['payment_intent' => $p['payment_intent'], 'amount' => $rembourse, 'metadata' => ['annulation' => (string)$annulId, 'inscription' => (string)$c['inscription_id']]],
                ['idempotency_key' => 'annul-' . $c['inscription_id'] . '-' . $rembourse]
            )->id;
            $pdo->prepare("UPDATE fin_annulations SET refund_id = ? WHERE id = ?")->execute([$refundId, $annulId]);
        }
        fin_audit($pdo, 'annulation_famille', 'inscription', $c['inscription_id'], ['pct' => $pct, 'rembourse' => $rembourse, 'commission_retenue' => $retenue, 'refund' => $refundId], 'parent:' . $parentId);
        fin_annul_notifier($pdo, $annulId);
        return [true, $rembourse > 0 ? 'Inscription annulée. ' . fin_eur($rembourse) . ' vont vous être remboursés (quelques jours selon votre banque).' : 'Inscription annulée. Aucun remboursement n\'est dû selon les conditions du séjour.'];
    } catch (Throwable $e) { // Stripe a refusé : l'inscription redevient valide, rien n'est remboursé
        error_log('fin_annul_executer (Stripe): ' . $e->getMessage());
        $pdo->prepare("DELETE FROM fin_annulations WHERE id = ?")->execute([$annulId]);
        $pdo->prepare("UPDATE fin_paiements SET annul_attendu = 0, annul_com = 0 WHERE id = ?")->execute([$p['id']]);
        $pdo->prepare("UPDATE inscriptions SET statut = 'Confirmé' WHERE id = ?")->execute([$c['inscription_id']]);
        fin_audit($pdo, 'annulation_echec_stripe', 'inscription', $c['inscription_id'], ['erreur' => mb_substr($e->getMessage(), 0, 200)], 'parent:' . $parentId);
        return [false, 'Le remboursement n\'a pas pu être effectué : votre inscription reste valide, réessayez plus tard.'];
    }
}

/** Prévient l'organisme par e-mail (un échec d'envoi n'annule rien : la liste reste visible sur son tableau de bord) */
function fin_annul_notifier(PDO $pdo, int $annulId): void {
    try {
        $st = $pdo->prepare("SELECT a.*, o.email, o.nom orga, e.prenom, e.nom, c.nom camp FROM fin_annulations a JOIN organisateurs o ON o.id = a.organisateur_id
                             JOIN inscriptions i ON i.id = a.inscription_id JOIN enfants e ON e.id = i.enfant_id JOIN camps c ON c.id = i.camp_id WHERE a.id = ?");
        $st->execute([$annulId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a || !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) return;
        require_once __DIR__ . '/mail_config.php';
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        sendMailGmail($a['email'], $a['orga'], 'Annulation d\'une inscription - ' . $a['camp'],
            '<p>Bonjour,</p><p>L\'inscription de <b>' . $h($a['prenom'] . ' ' . $a['nom']) . '</b> au séjour <b>' . $h($a['camp']) . '</b> a été annulée par la famille (motif : '
            . $h(FIN_ANNUL_MOTIFS[$a['motif']] ?? $a['motif']) . ').</p><p>Remboursement à la famille : ' . $h(fin_eur((int)$a['rembourse_cents']))
            . ', déduit de votre solde (commission ColoMap conservée). La place est libérée.</p><p>ColoMap</p>');
    } catch (Throwable $e) { error_log('fin_annul_notifier: ' . $e->getMessage()); }
}

/** Annulations d'un organisme (les plus récentes d'abord) */
function fin_annul_liste(PDO $pdo, int $orgaId, int $limit = 100): array {
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT a.*, e.prenom, e.nom, c.nom camp, c.date_debut FROM fin_annulations a JOIN inscriptions i ON i.id = a.inscription_id
                         JOIN enfants e ON e.id = i.enfant_id JOIN camps c ON c.id = i.camp_id WHERE a.organisateur_id = ? ORDER BY a.id DESC LIMIT " . (int)$limit);
    $st->execute([$orgaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
