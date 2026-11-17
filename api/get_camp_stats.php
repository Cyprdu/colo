<?php
// Fichier: /api/get_camp_stats.php
require_once 'config.php';
require_once 'options.php';
require_once 'attente.php';
require_once 'champs.php';
require_once 'finance_lib.php';

// 1. Vérification session
if (!isset($_SESSION['user']['id']) || !$_SESSION['user']['is_directeur']) {
    sendJson(['error' => 'Accès interdit'], 403);
}

$token = $_GET['t'] ?? '';

if (empty($token)) {
    sendJson(['error' => 'Token manquant'], 400);
}

try {
    $userId = $_SESSION['user']['id'];

    // 2. SÉCURITÉ : On récupère le camp ET on vérifie le propriétaire en une seule requête
    // On joint 'organisateurs' pour vérifier que 'user_id' correspond à celui de la session
    $stmtCheck = $pdo->prepare("
        SELECT c.*, o.user_id as owner_id
        FROM camps c 
        JOIN organisateurs o ON c.organisateur_id = o.id 
        WHERE c.token = ?
    ");
    $stmtCheck->execute([$token]);
    $camp = $stmtCheck->fetch();

    // 3. Vérifications strictes
    if (!$camp) {
        sendJson(['error' => 'Camp introuvable'], 404);
    }

    if ($camp['owner_id'] != $userId) {
        // Tentative d'accès à un camp qui n'est pas le sien -> 403 Forbidden
        sendJson(['error' => 'Vous n\'êtes pas le propriétaire de ce camp.'], 403);
    }

    options_ensure($pdo); attente_ensure($pdo); fin_ensure($pdo);
    // --- À partir d'ici, on a le feu vert, on charge les données sensibles ---

    // 4. Récupérer la liste des inscrits
    // Note: On utilise 'infos_sante' et 'regime_alimentaire' qui doivent exister en BDD maintenant
    $stmtInscrits = $pdo->prepare("
        SELECT 
            i.id as inscription_id,
            i.reservation_token,
            i.statut,
            i.tarif_id,
            i.date_inscription,
            i.montant_paye,
            i.statut_paiement, i.moyen_paiement, i.mode_paiement,
            e.prenom, e.nom, e.date_naissance, e.sexe, e.infos_sante, e.allergies, e.regime_alimentaire,
            u.email as parent_email, u.tel as parent_tel, u.nom as parent_nom_famille, u.prenom as parent_prenom,
            t.nom as tarif_nom
        FROM inscriptions i
        JOIN enfants e ON i.enfant_id = e.id
        JOIN users u ON e.parent_id = u.id
        LEFT JOIN tarifs t ON i.tarif_id = t.id
        WHERE i.camp_id = ? AND COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule')
        ORDER BY i.date_inscription DESC
    ");
    $stmtInscrits->execute([$camp['id']]);
    $inscrits = $stmtInscrits->fetchAll();

    // 5. Calculs KPI (inchangés)
    $totalRecettes = 0;
    $totalFilles = 0;
    $totalGarcons = 0;
    $ages = [];
    $inscriptionsParJour = [];

    $occupees = 0; $occF = 0; $occG = 0;
    foreach ($inscrits as &$inscrit) {
        $lim = attente_limite($inscrit + ['attente_paiement' => $camp['attente_paiement'] ?? 1, 'attente_jours' => $camp['attente_jours'] ?? 7]);
        $inscrit['expire'] = $lim && $lim < new DateTime();
        $inscrit['limite_paiement'] = $lim ? $lim->format('Y-m-d H:i') : null;
        if (!$inscrit['expire']) { $occupees++; if ($inscrit['sexe'] === SEXE_FILLE) $occF++; else $occG++; }
    }
    unset($inscrit);
    foreach ($inscrits as $inscrit) {
        $totalRecettes += $inscrit['montant_paye'];
        
        if ($inscrit['sexe'] === 'Femme') $totalFilles++;
        else $totalGarcons++;

        if ($inscrit['date_naissance']) {
            $ages[] = (new DateTime($inscrit['date_naissance']))->diff(new DateTime())->y;
        }

        $dateKey = date('Y-m-d', strtotime($inscrit['date_inscription']));
        if (!isset($inscriptionsParJour[$dateKey])) $inscriptionsParJour[$dateKey] = 0;
        $inscriptionsParJour[$dateKey]++;
    }

    $ageMoyen = count($ages) > 0 ? round(array_sum($ages) / count($ages), 1) : 0;
    $nbInscrits = $occupees;
    $tauxRemplissage = $camp['quota_global'] > 0 ? round(($nbInscrits / $camp['quota_global']) * 100, 1) : 0;

    ksort($inscriptionsParJour);

    sendJson([
        'camp' => [
            'nom' => $camp['nom'],
            'dates' => date('d/m/Y', strtotime($camp['date_debut'])) . ' au ' . date('d/m/Y', strtotime($camp['date_fin'])),
            'quota' => $camp['quota_global'],
            'quota_fille' => (int)$camp['quota_fille'], 'quota_garcon' => (int)$camp['quota_garcon'],
            'reserve_sexe' => $camp['reserve_sexe'] ?? null,
            'en_ligne' => !empty($camp['inscription_en_ligne']),
            'token' => $camp['token'],
            'criteres' => array_map(fn($k) => ENFANT_CRITERES[$k][0], options_criteres_valides($camp['champs_requis'] ?? '[]')),
            'questions' => count(champs_du_camp($pdo, (int)$camp['id'])),
        ],
        'kpi' => [
            'chiffre_affaires' => $totalRecettes,
            'nombre_inscrits' => $nbInscrits,
            'taux_remplissage' => $tauxRemplissage,
            'age_moyen' => $ageMoyen,
            'parite' => ['filles' => $occF, 'garcons' => $occG]
        ],
        'liste_inscrits' => $inscrits,
        'chart' => [
            'labels' => array_keys($inscriptionsParJour),
            'data' => array_values($inscriptionsParJour)
        ]
    ]);

} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage()); sendJson(['error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
}
?>