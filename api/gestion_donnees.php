<?php
// api/gestion_donnees.php : données de la page de gestion d'un séjour (gestion_camp.php). GET ?a=apercu|inscrits&t=<jeton d'une session>
// Accès : propriétaire de l'organisme du séjour, ou administrateur. Lecture seule ; les écritures passent par les API existantes.
require_once 'config.php';
require_once 'camp_groupe.php';
require_once 'attente.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function fin(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id'])) fin(['error' => 'Connectez-vous pour continuer.'], 401);
$uid = (int)$_SESSION['user']['id'];
$admin = !empty($_SESSION['user']['is_admin']);
if (!$admin && empty($_SESSION['user']['is_directeur'])) fin(['error' => 'Accès réservé aux organisateurs.'], 403);

try {
    options_ensure($pdo); attente_ensure($pdo);
    $st = $pdo->prepare("SELECT c.*, o.user_id AS owner_id FROM camps c JOIN organisateurs o ON o.id = c.organisateur_id WHERE c.token = ? AND COALESCE(c.supprime, 0) = 0");
    $st->execute([(string)($_GET['t'] ?? '')]);
    $open = $st->fetch(PDO::FETCH_ASSOC);
    if (!$open || (!$admin && (int)$open['owner_id'] !== $uid)) fin(['error' => "Ce séjour n'existe pas ou ne vous appartient pas."], 404);

    // Toutes les sessions du séjour (le propriétaire réel, même si c'est un administrateur qui consulte)
    $rows = camp_groupe_lignes($pdo, (int)$open['owner_id'], $open);
    $principal = camp_principal($rows);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $lab = function ($r) use ($mois) {
        [$a, $b] = normalizeSessionDates($r['date_debut'], $r['date_fin']);
        return $a->format('j') . ' ' . $mois[(int)$a->format('n') - 1] . ' → ' . $b->format('j') . ' ' . $mois[(int)$b->format('n') - 1] . ' ' . $b->format('Y');
    };
    $a = (string)($_GET['a'] ?? 'apercu');

    if ($a === 'apercu') {
        $stats = $pdo->prepare("SELECT i.camp_id, COUNT(*) n, SUM(i.statut_paiement = 'EN_ATTENTE') attente, COALESCE(SUM(i.montant_paye), 0) ca,
                                       SUM(e.sexe = 'Femme') filles, SUM(e.sexe <> 'Femme' OR e.sexe IS NULL) garcons
                                FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id
                                WHERE i.camp_id IN ($in) AND COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule') GROUP BY i.camp_id");
        $stats->execute($ids);
        $S = []; foreach ($stats->fetchAll(PDO::FETCH_ASSOC) as $s) $S[(int)$s['camp_id']] = $s;
        $nbEq = []; $eq = null;
        try {
            require_once __DIR__ . '/equipe.php'; equipe_ensure($pdo);
            $q = $pdo->prepare("SELECT camp_id, COUNT(*) n FROM equipe_inscriptions WHERE camp_id IN ($in) AND statut <> 'Annulé' GROUP BY camp_id");
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $nbEq[(int)$r['camp_id']] = (int)$r['n'];
        } catch (Throwable $e) { error_log('gestion equipe: ' . $e->getMessage()); }
        $today = date('Y-m-d');
        $sessions = [];
        foreach ($rows as $r) {
            $s = $S[(int)$r['id']] ?? [];
            if (!$eq && !empty($r['equipe_active']) && !empty($r['equipe_lien'])) $eq = ['token' => $r['token'], 'lien' => $r['equipe_lien'], 'public' => ($r['equipe_visibilite'] ?? '') === 'public'];
            $sessions[] = [
                'token' => $r['token'], 'label' => $lab($r), 'debut' => $r['date_debut'], 'fin' => $r['date_fin'], 'principal' => (int)$r['id'] === (int)$principal['id'],
                'ville' => $r['ville'], 'prix' => (float)$r['prix'], 'quota' => (int)$r['quota_global'], 'inscrits' => (int)($s['n'] ?? 0), 'attente' => (int)($s['attente'] ?? 0),
                'ca' => (float)($s['ca'] ?? 0), 'filles' => (int)($s['filles'] ?? 0), 'garcons' => (int)($s['garcons'] ?? 0), 'animateurs' => $nbEq[(int)$r['id']] ?? 0,
                'prive' => !empty($r['prive']), 'valide' => !empty($r['valide']), 'refuse' => !empty($r['refuse']), 'fini' => $r['date_fin'] < $today, 'en_ligne' => !empty($r['inscription_en_ligne']),
            ];
        }
        fin(['camp' => ['nom' => $principal['nom'], 'ville' => $principal['ville'], 'image' => $principal['image_url'] ?? null, 'principal' => $principal['token']],
             'sessions' => $sessions, 'equipe' => $eq, 'acces' => (int)$open['owner_id'] === $uid ? 'proprietaire' : 'admin']);
    }

    if ($a === 'inscrits') {
        $sel = $pdo->prepare("SELECT i.id inscription_id, i.camp_id, i.reservation_token, i.date_inscription, i.montant_paye, i.statut, i.statut_paiement, i.moyen_paiement, i.mode_paiement, i.tarif_id,
                    e.prenom, e.nom, e.date_naissance, e.sexe, e.infos_sante, e.allergies, e.regime_alimentaire,
                    u.email parent_email, u.tel parent_tel, u.nom parent_nom_famille, u.prenom parent_prenom, t.nom tarif_nom,
                    c.token session_token, c.attente_paiement, c.attente_jours
                FROM inscriptions i JOIN enfants e ON i.enfant_id = e.id JOIN users u ON e.parent_id = u.id JOIN camps c ON c.id = i.camp_id
                LEFT JOIN tarifs t ON i.tarif_id = t.id
                WHERE i.camp_id IN ($in) AND COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule') ORDER BY i.date_inscription DESC");
        $sel->execute($ids);
        $enfants = $sel->fetchAll(PDO::FETCH_ASSOC);
        foreach ($enfants as &$e) {
            $lim = attente_limite($e);
            $e['expire'] = $lim && $lim < new DateTime();
            $e['limite_paiement'] = $lim ? $lim->format('Y-m-d H:i') : null;
            unset($e['inscription_id'], $e['camp_id'], $e['attente_paiement'], $e['attente_jours'], $e['tarif_id']);   // jamais d'identifiant numérique côté navigateur
        }
        unset($e);
        $anims = [];
        try {
            require_once __DIR__ . '/equipe.php'; equipe_ensure($pdo);
            $q = $pdo->prepare("SELECT q.id, q.token, q.prenom, q.nom, q.date_naissance, q.email, q.tel, q.bafa_statut, q.diplome, q.allergies, q.regime, q.urgence_nom, q.urgence_tel,
                                       q.tarif_nom, q.prix_final, q.montant_paye, q.statut, q.statut_paiement, q.created_at, c.token session_token
                                FROM equipe_inscriptions q JOIN camps c ON c.id = q.camp_id WHERE q.camp_id IN ($in) ORDER BY q.created_at DESC");
            $q->execute($ids);
            $anims = $q->fetchAll(PDO::FETCH_ASSOC);
            $rep = $pdo->prepare("SELECT libelle, valeur FROM equipe_reponses WHERE equipe_id = ? ORDER BY id");
            foreach ($anims as &$x) { $rep->execute([$x['id']]); $x['reponses'] = $rep->fetchAll(PDO::FETCH_ASSOC); unset($x['id']); }
            unset($x);
        } catch (Throwable $e) { error_log('gestion animateurs: ' . $e->getMessage()); }
        $labels = []; foreach ($rows as $r) $labels[$r['token']] = $lab($r);
        fin(['enfants' => $enfants, 'animateurs' => $anims, 'sessions' => $labels]);
    }
    fin(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    error_log('gestion_donnees: ' . $e->getMessage());
    fin(['error' => 'Impossible de charger ces informations pour le moment.'], 500);
}
