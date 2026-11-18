<?php
// api/gestion_donnees.php : données de la page de gestion d'un séjour (gestion_camp.php). GET ?a=apercu|inscrits&t=<jeton d'une session>
// Accès : propriétaire de l'organisme du séjour, ou administrateur. Lecture seule ; les écritures passent par les API existantes.
require_once 'config.php';
require_once 'gestion_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function fin(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id'])) fin(['error' => 'Connectez-vous pour continuer.'], 401);
$uid = (int)$_SESSION['user']['id'];
$admin = !empty($_SESSION['user']['is_admin']);
if (!$admin && empty($_SESSION['user']['is_directeur'])) fin(['error' => 'Accès réservé aux organisateurs.'], 403);

try {
    $ctx = gestion_ouvrir($pdo, (string)($_GET['t'] ?? ''), $uid, $admin);
    if (!$ctx) fin(['error' => "Ce séjour n'existe pas ou ne vous appartient pas."], 404);
    ['open' => $open, 'rows' => $rows, 'principal' => $principal, 'ids' => $ids, 'in' => $in] = $ctx;
    $lab = fn($r) => gestion_label($r);
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
        $d = gestion_inscrits($pdo, $ctx);
        foreach ($d['enfants'] as &$e) { $e['reste'] = gestion_reste($e); unset($e['_enfant_id'], $e['_public_id']); }   // jamais d'identifiant côté navigateur
        unset($e);
        fin($d);
    }
    fin(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    error_log('gestion_donnees: ' . $e->getMessage());
    fin(['error' => 'Impossible de charger ces informations pour le moment.'], 500);
}
