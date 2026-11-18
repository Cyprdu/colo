<?php
// api/gestion_lib.php : accès à un séjour (toutes ses sessions) et lecture des inscrits, partagés par gestion_donnees, gestion_export et gestion_actions.
// Accès : propriétaire de l'organisme du séjour, ou administrateur.
require_once __DIR__ . '/camp_groupe.php';
require_once __DIR__ . '/attente.php';
require_once __DIR__ . '/sante.php';
require_once __DIR__ . '/enfants.php';

/** Contexte du séjour ouvert par le jeton d'une de ses sessions, ou null si inexistant / pas à l'utilisateur */
function gestion_ouvrir(PDO $pdo, string $token, int $uid, bool $admin): ?array {
    options_ensure($pdo); attente_ensure($pdo); sante_ensure($pdo);
    $st = $pdo->prepare("SELECT c.*, o.user_id AS owner_id, o.nom AS orga_nom FROM camps c JOIN organisateurs o ON o.id = c.organisateur_id WHERE c.token = ? AND COALESCE(c.supprime, 0) = 0");
    $st->execute([$token]);
    $open = $st->fetch(PDO::FETCH_ASSOC);
    if (!$open || (!$admin && (int)$open['owner_id'] !== $uid)) return null;
    $rows = camp_groupe_lignes($pdo, (int)$open['owner_id'], $open);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    return ['open' => $open, 'rows' => $rows, 'principal' => camp_principal($rows), 'ids' => $ids, 'in' => implode(',', array_fill(0, count($ids), '?'))];
}

function gestion_label(array $r): string {
    static $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    [$a, $b] = normalizeSessionDates($r['date_debut'], $r['date_fin']);
    return $a->format('j') . ' ' . $mois[(int)$a->format('n') - 1] . ' → ' . $b->format('j') . ' ' . $mois[(int)$b->format('n') - 1] . ' ' . $b->format('Y');
}

/**
 * Inscrits non annulés des sessions du contexte. Les champs préfixés « _ » (identifiants, jeton public de l'enfant) restent côté serveur.
 * Chaque enfant porte sa complétude (pct, manque : critères du séjour + fiche moncamp.fr).
 */
function gestion_inscrits(PDO $pdo, array $ctx, string $session = ''): array {
    $ids = $ctx['ids']; $in = $ctx['in'];
    $camps = []; foreach ($ctx['rows'] as $r) $camps[(int)$r['id']] = $r;
    $sel = $pdo->prepare("SELECT e.*, i.id ins_id, i.camp_id, i.reservation_token, i.date_inscription, i.montant_paye, i.statut, i.statut_paiement, i.moyen_paiement, i.mode_paiement, i.prix_final,
                u.email parent_email, u.tel parent_tel, u.nom parent_nom_famille, u.prenom parent_prenom, t.nom tarif_nom, c.token session_token, c.attente_paiement, c.attente_jours
            FROM inscriptions i JOIN enfants e ON i.enfant_id = e.id JOIN users u ON e.parent_id = u.id JOIN camps c ON c.id = i.camp_id
            LEFT JOIN tarifs t ON i.tarif_id = t.id
            WHERE i.camp_id IN ($in) AND COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule') ORDER BY e.nom, e.prenom");
    $sel->execute($ids);
    $garde = ['reservation_token', 'date_inscription', 'montant_paye', 'statut', 'statut_paiement', 'moyen_paiement', 'mode_paiement', 'prix_final', 'parent_email', 'parent_tel',
        'parent_nom_famille', 'parent_prenom', 'tarif_nom', 'session_token', 'prenom', 'nom', 'date_naissance', 'sexe', 'infos_sante', 'allergies', 'traitements', 'regime_alimentaire',
        'medecin_nom', 'medecin_tel', 'urgence_nom', 'urgence_tel', 'urgence_lien', 'resp1_prenom', 'resp1_nom', 'resp1_tel', 'resp1_email', 'resp2_prenom', 'resp2_nom', 'resp2_tel'];
    $enfants = [];
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    $avec = [];   // autres participants d'une inscription à un tarif « à plusieurs »
    if ($rows) {
        try {
            $st = $pdo->prepare("SELECT p.inscription_id, p.role, e.prenom, e.nom FROM inscription_participants p JOIN enfants e ON e.id = p.enfant_id JOIN inscriptions i ON i.id = p.inscription_id
                                 WHERE p.enfant_id <> i.enfant_id AND p.inscription_id IN (" . implode(',', array_fill(0, count($rows), '?')) . ") ORDER BY p.position");
            $st->execute(array_map(fn($r) => (int)$r['ins_id'], $rows));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $avec[(int)$p['inscription_id']][] = $p['prenom'] . ' ' . $p['nom'] . ' (' . $p['role'] . ')';
        } catch (Throwable $ex) {}
    }
    foreach ($rows as $e) {
        if ($session !== '' && $e['session_token'] !== $session) continue;
        $o = array_intersect_key($e, array_flip($garde));
        if (!empty($avec[(int)$e['ins_id']])) $o['tarif_nom'] = trim($o['tarif_nom'] . ' · avec ' . implode(', ', $avec[(int)$e['ins_id']]));
        $lim = attente_limite($e);
        $o['expire'] = $lim && $lim < new DateTime();
        $o['limite_paiement'] = $lim ? $lim->format('Y-m-d H:i') : null;
        [$pct, $manque] = fiche_completude($e);
        $o['manque'] = array_values(array_unique(array_merge(options_enfant_manquants($camps[(int)$e['camp_id']] ?? [], $e), array_column($manque, 0))));
        $o['pct'] = $pct;
        $o['_enfant_id'] = (int)$e['id']; $o['_public_id'] = (string)($e['public_id'] ?? '');
        $enfants[] = $o;
    }
    $anims = [];
    try {
        require_once __DIR__ . '/equipe.php'; equipe_ensure($pdo);
        $q = $pdo->prepare("SELECT q.id, q.token, q.prenom, q.nom, q.date_naissance, q.email, q.tel, q.bafa_statut, q.diplome, q.allergies, q.regime, q.urgence_nom, q.urgence_tel,
                                   CONCAT_WS(' · ', q.categorie_nom, q.tarif_nom) tarif_nom, q.prix_final, q.montant_paye, q.statut, q.statut_paiement, q.created_at, c.token session_token
                            FROM equipe_inscriptions q JOIN camps c ON c.id = q.camp_id WHERE q.camp_id IN ($in) ORDER BY q.nom, q.prenom");
        $q->execute($ids);
        $rep = $pdo->prepare("SELECT libelle, valeur FROM equipe_reponses WHERE equipe_id = ? ORDER BY id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
            if ($session !== '' && $x['session_token'] !== $session) continue;
            $rep->execute([$x['id']]); $x['reponses'] = $rep->fetchAll(PDO::FETCH_ASSOC); unset($x['id']);
            $anims[] = $x;
        }
    } catch (Throwable $e) { error_log('gestion animateurs: ' . $e->getMessage()); }
    $labels = []; foreach ($ctx['rows'] as $r) $labels[$r['token']] = gestion_label($r);
    return ['enfants' => $enfants, 'animateurs' => $anims, 'sessions' => $labels];
}

/** Montant restant à encaisser pour une inscription enfant */
function gestion_reste(array $e): float {
    if (in_array($e['statut_paiement'], ['GRATUIT'], true)) return 0.0;
    return max(0, round((float)$e['prix_final'] - (float)$e['montant_paye'], 2));
}

/** Âge en années à une date (par défaut aujourd'hui) */
function gestion_age(?string $naissance, ?string $a = null): ?int {
    if (!$naissance) return null;
    try { return (new DateTime($naissance))->diff(new DateTime($a ?? 'today'))->y; } catch (Throwable $e) { return null; }
}
