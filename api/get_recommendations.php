<?php
// api/get_recommendations.php
// Camps à afficher sur l'accueil, optionnellement restreints à une fenêtre de dates,
// classés selon le profil de l'utilisateur connecté (âge des enfants, favoris, historique, région).
//
// GET : limit, date_start, date_end (Y-m-d), child_age (si un enfant précis est choisi)
// Retour : { camps: [...], favorites: [ids], personalized: bool }

require_once 'config.php';

function normalizeMedia($path, $defaultDir = '') {
    $path = trim((string)$path);
    if ($path === '') return null;
    $path = str_replace('\\', '/', $path);
    if (preg_match('#^(https?:)?//#', $path) || strpos($path, 'uploads/') === 0) return $path;
    return $defaultDir . ltrim($path, '/');
}

function validDate($s, $fallback) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : $fallback;
}

try {
    $today = date('Y-m-d');
    $limit = min(max((int)($_GET['limit'] ?? 12), 1), 40);
    $start = max(validDate($_GET['date_start'] ?? '', $today), $today);
    $end   = validDate($_GET['date_end'] ?? '', date('Y-m-d', strtotime('+10 years')));
    $explicitAge = (isset($_GET['child_age']) && is_numeric($_GET['child_age'])) ? (int)$_GET['child_age'] : null;

    $userId = $_SESSION['user']['id'] ?? null;

    // ------------------------------------------------------------------
    // 1. Profil de l'utilisateur (uniquement ses propres données)
    // ------------------------------------------------------------------
    $ages = [];            // âges de ses enfants
    $depts = [];           // départements de ses enfants
    $favIds = [];          // camps en favoris
    $registered = [];      // camps où un de ses enfants est déjà inscrit
    $prefOrgs = [];        // organisateurs déjà favoris / réservés
    $prefThemes = [];      // thèmes déjà favoris / réservés

    if ($userId) {
        $stmt = $pdo->prepare("SELECT id, date_naissance, code_postal FROM enfants WHERE parent_id = ?");
        $stmt->execute([$userId]);
        $childIds = [];
        $now = new DateTime();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ch) {
            $childIds[] = (int)$ch['id'];
            if (!empty($ch['date_naissance'])) {
                $ages[] = (new DateTime($ch['date_naissance']))->diff($now)->y;
            }
            if (!empty($ch['code_postal'])) $depts[substr($ch['code_postal'], 0, 2)] = true;
        }

        $stmt = $pdo->prepare("SELECT camp_id FROM favoris WHERE user_id = ?");
        $stmt->execute([$userId]);
        $favIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($childIds) {
            $in = implode(',', array_fill(0, count($childIds), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT camp_id FROM inscriptions WHERE enfant_id IN ($in)");
            $stmt->execute($childIds);
            $registered = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $seen = array_unique(array_merge($favIds, $registered));
        if ($seen) {
            $in = implode(',', array_fill(0, count($seen), '?'));
            $stmt = $pdo->prepare("SELECT organisateur_id, theme FROM camps WHERE id IN ($in)");
            $stmt->execute(array_values($seen));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['organisateur_id'])) $prefOrgs[(int)$r['organisateur_id']] = true;
                if (!empty($r['theme'])) $prefThemes[mb_strtolower(trim($r['theme']))] = true;
            }
        }
    }
    if ($explicitAge !== null) $ages = [$explicitAge];

    // ------------------------------------------------------------------
    // 2. Camps candidats (visibles, à venir, dans la fenêtre de dates)
    // ------------------------------------------------------------------
    $sql = "SELECT c.id, c.token, c.nom, c.prix, c.ville, c.code_postal, c.image_url,
                   c.date_debut, c.date_fin, c.age_min, c.age_max, c.organisateur_id, c.theme,
                   c.boost_points, c.boost_vedette_fin, c.boost_urgence_fin,
                   o.nom AS organisateur_nom, o.logo_url AS organisateur_logo
            FROM camps c
            LEFT JOIN organisateurs o ON o.id = c.organisateur_id
            WHERE c.valide = 1 AND c.refuse = 0
              AND COALESCE(c.supprime, 0) = 0
              AND COALESCE(c.prive, 0) = 0
              AND COALESCE(c.is_private, 0) = 0
              AND (c.date_limite_inscription IS NULL OR c.date_limite_inscription >= CURDATE())
              AND c.date_debut >= :today
              AND c.date_debut <= :end
              AND COALESCE(c.date_fin, c.date_debut) >= :start
            ORDER BY c.date_debut ASC
            LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':today' => $today, ':end' => $end, ':start' => $start]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ------------------------------------------------------------------
    // 3. Filtrage + score de pertinence
    // ------------------------------------------------------------------
    $nowDt = date('Y-m-d H:i:s');
    $scored = [];
    foreach ($rows as $c) {
        $id = (int)$c['id'];
        if (in_array($id, $registered, true)) continue; // déjà inscrit : inutile de le proposer

        $score = 0;
        $fits = false;
        foreach ($ages as $a) {
            $minOk = ($c['age_min'] === null || $a >= (int)$c['age_min']);
            $maxOk = ($c['age_max'] === null || $a <= (int)$c['age_max']);
            if ($minOk && $maxOk) { $fits = true; break; }
        }
        if ($explicitAge !== null && !$fits) continue; // enfant choisi : on filtre strictement
        if ($ages) $score += $fits ? 5 : -5;

        if (!empty($c['organisateur_id']) && isset($prefOrgs[(int)$c['organisateur_id']])) $score += 3;
        if (!empty($c['theme']) && isset($prefThemes[mb_strtolower(trim($c['theme']))])) $score += 2;
        if (!empty($c['code_postal']) && isset($depts[substr($c['code_postal'], 0, 2)])) $score += 2;
        if (in_array($id, $favIds, true)) $score += 1;
        if (!empty($c['boost_vedette_fin']) && $c['boost_vedette_fin'] > $nowDt) $score += 3;
        if (!empty($c['boost_urgence_fin']) && $c['boost_urgence_fin'] > $nowDt) $score += 1;
        $score += min(3, (int)$c['boost_points'] / 100);

        $scored[] = ['score' => $score, 'row' => $c];
    }

    usort($scored, function ($a, $b) {
        return ($b['score'] <=> $a['score']) ?: strcmp($a['row']['date_debut'], $b['row']['date_debut']);
    });

    $camps = [];
    foreach (array_slice($scored, 0, $limit) as $s) {
        $c = $s['row'];
        $camps[] = [
            'id'               => (int)$c['id'],
            'token'            => $c['token'],
            'nom'              => $c['nom'],
            'prix'             => (float)$c['prix'],
            'ville'            => $c['ville'],
            'image_url'        => normalizeMedia($c['image_url'], 'uploads/camps/'),
            'date_debut'       => $c['date_debut'],
            'date_fin'         => $c['date_fin'],
            'organisateur_nom' => $c['organisateur_nom'],
            'organisateur_logo'=> normalizeMedia($c['organisateur_logo']),
        ];
    }

    sendJson(['camps' => $camps, 'favorites' => $favIds, 'personalized' => (bool)$userId]);

} catch (Throwable $e) {
    error_log('get_recommendations: ' . $e->getMessage());
    sendJson(['camps' => [], 'error' => 'Erreur serveur'], 500);
}
