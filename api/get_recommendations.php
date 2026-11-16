<?php
// api/get_recommendations.php
// Fil d'accueil personnalisé : UNE requête SQL, puis toutes les sections sont calculées en PHP.
//
// GET : child_age + child_sexe (Homme|Femme) (optionnels : enfant choisi), periods (JSON optionnel : [{id,label,start,end}])
// Retour : { sections: [{id,title,subtitle,more,camps:[...]}], favorites:[ids], personalized:bool }
//
// Personnalisation (utilisateur connecté, uniquement ses propres données) :
//   âge de ses enfants, favoris, réservations passées, région de ses enfants.

require_once 'config.php';
require_once 'sessions.php';
require_once 'organismes.php';
orga_ensure($pdo);
require_once 'options.php';
require_once 'attente.php';
require_once 'marketing_lib.php';

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

function lc($s) { return mb_strtolower(trim((string)$s)); }

try {
    $today  = date('Y-m-d');
    $nowDt  = date('Y-m-d H:i:s');
    $userId = $_SESSION['user']['id'] ?? null;
    $explicitAge = (isset($_GET['child_age']) && is_numeric($_GET['child_age'])) ? (int)$_GET['child_age'] : null;

    // ------------------------------------------------------------------
    // 1. Profil de l'utilisateur
    // ------------------------------------------------------------------
    $ages = []; $sexes = []; $depts = []; $favIds = []; $registered = []; $prefOrgs = []; $prefThemes = [];

    if ($userId) {
        $stmt = $pdo->prepare("SELECT id, date_naissance, code_postal, sexe FROM enfants WHERE parent_id = ?");
        $stmt->execute([$userId]);
        $childIds = [];
        $now = new DateTime();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ch) {
            $childIds[] = (int)$ch['id'];
            if (in_array($ch['sexe'] ?? '', [SEXE_GARCON, SEXE_FILLE], true)) $sexes[$ch['sexe']] = true;
            if (!empty($ch['date_naissance'])) $ages[] = (new DateTime($ch['date_naissance']))->diff($now)->y;
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

        $seen = array_values(array_unique(array_merge($favIds, $registered)));
        if ($seen) {
            $in = implode(',', array_fill(0, count($seen), '?'));
            $stmt = $pdo->prepare("SELECT organisateur_id, theme, type FROM camps WHERE id IN ($in)");
            $stmt->execute($seen);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['organisateur_id'])) $prefOrgs[(int)$r['organisateur_id']] = true;
                foreach (['theme', 'type'] as $k) if (!empty($r[$k])) $prefThemes[lc($r[$k])] = true;
            }
        }
    }
    if ($explicitAge !== null) $ages = [$explicitAge];
    $explicitSexe = options_sexe_param($_GET['child_sexe'] ?? '');
    if ($explicitSexe) $sexes = [$explicitSexe => true];
    $sexes = array_keys($sexes);
    options_ensure($pdo); attente_ensure($pdo);
    $actifSql = options_actif_sql();
    $nbSexe = fn($s) => "(SELECT COUNT(*) FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id WHERE i.camp_id = c.id AND e.sexe = '$s' AND $actifSql)";
    $nbF = $nbSexe(SEXE_FILLE); $nbG = $nbSexe(SEXE_GARCON);
    $hasAges = !empty($ages);

    // ------------------------------------------------------------------
    // 2. Camps candidats : visibles, à venir (une seule requête)
    // ------------------------------------------------------------------
    $groupCol = campsHaveGroupColumn($pdo) ? 'c.groupe_id' : 'NULL';
    $sql = "SELECT c.id, $groupCol AS groupe_id, c.token, c.nom, COALESCE((SELECT MIN(t.prix) FROM camps_tarifs ct JOIN tarifs t ON ct.tarif_id = t.id WHERE ct.camp_id = c.id), c.prix) AS prix, c.ville, c.code_postal, c.image_url,
                   c.date_debut, c.date_fin, c.age_min, c.age_max, c.organisateur_id,
                   c.theme, c.type, c.date_creation,
                   c.boost_points, c.boost_vedette_fin, c.boost_urgence_fin, c.date_bump,
                   c.reserve_sexe, c.quota_fille, c.quota_garcon,
                   $nbF AS inscrits_f, $nbG AS inscrits_g,
                   o.nom AS organisateur_nom, o.logo_url AS organisateur_logo, o.slug AS organisateur_slug
            FROM camps c
            LEFT JOIN organisateurs o ON o.id = c.organisateur_id
            WHERE c.valide = 1 AND c.refuse = 0
              AND COALESCE(c.supprime, 0) = 0
              AND COALESCE(c.prive, 0) = 0
              AND COALESCE(c.is_private, 0) = 0
              AND (c.date_limite_inscription IS NULL OR c.date_limite_inscription >= CURDATE())
              AND c.date_debut >= :today
            ORDER BY c.date_debut ASC
            LIMIT 800";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':today' => $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ------------------------------------------------------------------
    // 3. Score de pertinence + étiquette par camp
    // ------------------------------------------------------------------
    $pool = [];
    foreach ($rows as $c) {
        $id = (int)$c['id'];
        if (in_array($id, $registered, true)) continue; // déjà inscrit

        // Sexe des enfants : jamais de séjour réservé à l'autre sexe ni dont le quota de leur sexe est plein
        if ($sexes) {
            $ouvert = false;
            foreach ($sexes as $sx) {
                $q = (int)($sx === SEXE_FILLE ? $c['quota_fille'] : $c['quota_garcon']);
                $pris = (int)($sx === SEXE_FILLE ? $c['inscrits_f'] : $c['inscrits_g']);
                if (options_sexe_admis($c, $sx) && ($q <= 0 || $pris < $q)) { $ouvert = true; break; }
            }
            if (!$ouvert) continue;
        }

        $fits = false;
        foreach ($ages as $a) {
            if (($c['age_min'] === null || $a >= (int)$c['age_min']) && ($c['age_max'] === null || $a <= (int)$c['age_max'])) { $fits = true; break; }
        }
        if ($explicitAge !== null && !$fits) continue; // enfant choisi : filtre strict

        $isVedette = !empty($c['boost_vedette_fin']) && $c['boost_vedette_fin'] > $nowDt;
        $isUrgent  = !empty($c['boost_urgence_fin']) && $c['boost_urgence_fin'] > $nowDt;
        $isNew     = !empty($c['date_creation']) && strtotime($c['date_creation']) > strtotime('-21 days');
        $sameOrg   = !empty($c['organisateur_id']) && isset($prefOrgs[(int)$c['organisateur_id']]);
        $sameTheme = (!empty($c['theme']) && isset($prefThemes[lc($c['theme'])])) || (!empty($c['type']) && isset($prefThemes[lc($c['type'])]));
        $nearby    = !empty($c['code_postal']) && isset($depts[substr($c['code_postal'], 0, 2)]);

        $score = 0;
        if ($hasAges) $score += $fits ? 5 : -5;
        if ($sameOrg)   $score += 3;
        if ($sameTheme) $score += 2;
        if ($nearby)    $score += 2;
        if (in_array($id, $favIds, true)) $score += 1;
        // Effet mesurable des boosts : multiplicateur (mise à la une, dernière minute, remontée qui s'estompe), jamais un simple tri
        $mk = mk_score($c, $nowDt);
        $score = ($score + 6) * $mk['boost_factor'];

        $c['_score'] = $score; $c['_fits'] = $fits; $c['_vedette'] = $isVedette; $c['_urgent'] = $isUrgent;
        $c['_new'] = $isNew; $c['_sameOrg'] = $sameOrg; $c['_sameTheme'] = $sameTheme; $c['_nearby'] = $nearby;
        $c['_text'] = lc(($c['theme'] ?? '') . ' ' . ($c['type'] ?? '') . ' ' . ($c['nom'] ?? ''));
        $pool[] = $c;
    }

    $byScore = function ($a, $b) {
        return ($b['_score'] <=> $a['_score']) ?: strcmp($a['date_debut'], $b['date_debut']);
    };
    $limit = 14;

    $format = function ($list, $forcedBadge = null) use ($limit) {
        $out = [];
        foreach (array_slice($list, 0, $limit) as $c) {
            $badge = $forcedBadge ?: ($c['_vedette'] ? 'Sponsorisé' : ($c['_urgent'] ? 'Dernière minute' : ($c['_new'] ? 'Nouveau' : null)));
            $out[] = [
                'id'               => (int)$c['id'],
                'token'            => $c['token'],
                'nom'              => $c['nom'],
                'prix'             => ($c['prix_min'] ?? $c['prix']) !== null ? (float)($c['prix_min'] ?? $c['prix']) : null,
                'ville'            => $c['ville'],
                'image_url'        => normalizeMedia($c['image_url'], 'uploads/camps/'),
                'date_debut'       => $c['date_debut'],
                'date_fin'         => $c['date_fin'],
                'organisateur_slug' => $c['organisateur_slug'] ?? null,
                'organisateur_nom' => $c['organisateur_nom'],
                'organisateur_logo'=> normalizeMedia($c['organisateur_logo']),
                'badge'            => $badge,
                'sessions_count'   => (int)($c['sessions_count'] ?? 1),
            ];
        }
        return $out;
    };

    $sections = [];
    $add = function ($id, $title, $subtitle, $more, $list, $badge = null) use (&$sections, $format, $pool) {
        $list = collapseSessions($list, $pool); // une seule carte par camp (ses sessions sont comptées)
        if (count($list) < 3 && $id !== 'prochains') return; // une section presque vide fait pauvre : on la masque
        $sections[] = ['id' => $id, 'title' => $title, 'subtitle' => $subtitle, 'more' => $more, 'camps' => $format($list, $badge)];
    };
    $filter = function ($fn) use ($pool, $byScore) {
        $l = array_values(array_filter($pool, $fn));
        usort($l, $byScore);
        return $l;
    };

    // ---- a) Périodes de vacances (fournies par la page) ----
    $periods = json_decode($_GET['periods'] ?? '[]', true);
    if (is_array($periods)) {
        foreach (array_slice($periods, 0, 6) as $i => $p) {
            $start = validDate($p['start'] ?? '', $today);
            $end   = validDate($p['end'] ?? '', date('Y-m-d', strtotime('+1 year')));
            $label = mb_substr(strip_tags((string)($p['label'] ?? 'Vacances')), 0, 60);
            $list  = $filter(function ($c) use ($start, $end) {
                return $c['date_debut'] <= $end && ($c['date_fin'] ?: $c['date_debut']) >= $start;
            });
            $add('periode-' . $i, $label, 'Sélection adaptée à votre profil', 'recherche', $list);
        }
    }

    // ---- b) Suggestions personnalisées / génériques ----
    $add('a-la-une', 'À la une', 'Séjours sponsorisés par leurs organisateurs', 'recherche',
         $filter(function ($c) { return $c['_vedette']; }), 'Sponsorisé');

    if ($userId) {
        $add('favoris-similaires', 'Dans la lignée de vos favoris', "D'après vos favoris et vos réservations", 'favorites',
             $filter(function ($c) use ($favIds) { return ($c['_sameOrg'] || $c['_sameTheme']) && !in_array((int)$c['id'], $favIds, true); }));
    }
    if ($hasAges) {
        $label = $explicitAge !== null ? "Pour un enfant de $explicitAge ans" : "Adaptés à l'âge de vos enfants";
        $more  = 'recherche?age=' . urlencode((string)($explicitAge ?? $ages[0]));
        $add('pour-votre-enfant', $label, "Les séjours qui correspondent à l'âge indiqué", $more,
             $filter(function ($c) { return $c['_fits']; }));
    }
    if ($depts) {
        $add('pres-de-chez-vous', 'Près de chez vous', 'Dans votre département', 'recherche',
             $filter(function ($c) { return $c['_nearby']; }));
    }

    // Dernière minute : départs dans les 60 prochains jours (les plus proches d'abord)
    $soon = date('Y-m-d', strtotime('+60 days'));
    $lm = array_values(array_filter($pool, function ($c) use ($soon) { return $c['date_debut'] <= $soon; }));
    usort($lm, function ($a, $b) { return strcmp($a['date_debut'], $b['date_debut']); });
    $add('derniere-minute', 'Dernière minute', 'Ils partent bientôt, il reste peut-être de la place', 'recherche', $lm, 'Dernière minute');

    // Petits prix
    $cheap = array_values(array_filter($pool, function ($c) { return $c['prix'] !== null && (float)$c['prix'] > 0 && (float)$c['prix'] <= 300; }));
    usort($cheap, function ($a, $b) { return ((float)$a['prix'] <=> (float)$b['prix']) ?: strcmp($a['date_debut'], $b['date_debut']); });
    $add('petits-prix', 'Petits prix', 'Des séjours à moins de 300 €', 'recherche?max_price=300', $cheap);

    // Thématiques
    $themes = [
        ['montagne', 'Montagne', ['montagne', 'neige', 'ski']],
        ['mer', 'Mer et littoral', ['mer', 'littoral', 'plage', 'voile']],
        ['nature', 'Nature et aventure', ['nature', 'aventure', 'campagne', 'survie', 'bushcraft']],
        ['sport', 'Sport', ['sport']],
    ];
    foreach ($themes as [$slug, $title, $words]) {
        $list = $filter(function ($c) use ($words) {
            foreach ($words as $w) if (mb_strpos($c['_text'], $w) !== false) return true;
            return false;
        });
        $add('theme-' . $slug, $title, 'Une sélection thématique', 'recherche?themes[]=' . urlencode($title === 'Mer et littoral' ? 'Mer / Littoral' : $title), $list);
    }

    // Rien du tout ? on propose simplement les prochains séjours
    if (!$sections) {
        $all = $pool; usort($all, $byScore);
        $add('prochains', 'Séjours à venir', 'Les prochains départs', 'recherche', $all);
    }

    $shown = [];
    foreach ($sections as $sec) foreach ($sec['camps'] as $cc) $shown[] = $cc['id'];
    mk_track($pdo, $shown, 'impression', 'accueil');

    sendJson(['sections' => $sections, 'favorites' => $favIds, 'personalized' => (bool)$userId]);

} catch (Throwable $e) {
    error_log('get_recommendations: ' . $e->getMessage());
    sendJson(['sections' => [], 'error' => 'Erreur serveur'], 500);
}
