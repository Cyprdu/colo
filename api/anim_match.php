<?php
// api/anim_match.php : préférences d'un animateur + moteur de suggestions (score de correspondance avec son profil).
//
// Table animateur_prefs (auto-créée, idempotente). Le moteur ne propose QUE les séjours en mode (A) « recrute » (camps.recrute_animateurs = 1),
// à venir, validés, auxquels l'animateur n'a pas déjà postulé. Chaque suggestion porte un score et des raisons courtes, lisibles.
// ponytail: localisation par département / région (pas de coordonnées GPS en base) ; le « rayon » choisit entre département, région et France entière.
//          Pour un vrai rayon en km, géocoder camps.code_postal et comparer des distances.

require_once __DIR__ . '/animateurs.php';

const ANIM_QUALIFS = [
    'bafa' => 'BAFA complet', 'stagiaire' => 'BAFA stagiaire (en cours)', 'bpjeps' => 'BPJEPS / diplôme professionnel', 'bafd' => 'BAFD (direction)',
    'baignade' => 'Surveillant de baignade', 'psc1' => 'PSC1 / secourisme', 'aucun' => 'Pas de diplôme',
];
const ANIM_PERIODES = ['toussaint' => 'Toussaint', 'noel' => 'Noël', 'hiver' => 'Hiver (ski)', 'printemps' => 'Printemps', 'ete' => 'Été'];
const ANIM_TYPES = ['itinerant' => 'Itinérant', 'sportif' => 'Sportif', 'scout' => 'Scout / pastoral', 'artistique' => 'Artistique', 'nature' => 'Nature / aventure', 'linguistique' => 'Linguistique', 'ado' => 'Adolescents', 'petits' => 'Petits (moins de 8 ans)'];
const ANIM_FREQS = ['jamais' => 'Aucune alerte', 'quotidien' => 'Une fois par jour', 'hebdo' => 'Une fois par semaine'];
const ANIM_RAYONS = ['dept' => 'Mon département', 'region' => 'Ma région', 'france' => 'Toute la France'];

function anim_match_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS animateur_prefs (
            user_id INT PRIMARY KEY,
            qualifs TEXT NULL, code_postal VARCHAR(10) NULL, ville VARCHAR(120) NULL, rayon VARCHAR(8) NOT NULL DEFAULT 'region',
            periodes TEXT NULL, dates_dispo TEXT NULL, themes TEXT NULL, types TEXT NULL,
            age_min INT NULL, age_max INT NULL, remu_min DECIMAL(8,2) NULL, benevole TINYINT(1) NOT NULL DEFAULT 1,
            alerte_email TINYINT(1) NOT NULL DEFAULT 0, alerte_freq VARCHAR(10) NOT NULL DEFAULT 'jamais',
            alerte_dernier_envoi DATETIME NULL, alerte_dernier_camp INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('anim_match_ensure: ' . $e->getMessage()); }
    $done = true;
}

function anim_prefs_defaut(): array {
    return ['qualifs' => [], 'code_postal' => '', 'ville' => '', 'rayon' => 'region', 'periodes' => [], 'dates_dispo' => [], 'themes' => [], 'types' => [],
            'age_min' => null, 'age_max' => null, 'remu_min' => null, 'benevole' => true, 'alerte_email' => false, 'alerte_freq' => 'jamais', 'configure' => false];
}

function anim_prefs(PDO $pdo, int $uid): array {
    anim_match_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM animateur_prefs WHERE user_id = ?");
    $st->execute([$uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $p = anim_prefs_defaut();
    if (!$r) return $p;
    $j = fn($v) => ($a = json_decode((string)$v, true)) && is_array($a) ? $a : [];
    return ['qualifs' => $j($r['qualifs']), 'code_postal' => (string)$r['code_postal'], 'ville' => (string)$r['ville'], 'rayon' => $r['rayon'],
            'periodes' => $j($r['periodes']), 'dates_dispo' => $j($r['dates_dispo']), 'themes' => $j($r['themes']), 'types' => $j($r['types']),
            'age_min' => $r['age_min'] !== null ? (int)$r['age_min'] : null, 'age_max' => $r['age_max'] !== null ? (int)$r['age_max'] : null,
            'remu_min' => $r['remu_min'] !== null ? (float)$r['remu_min'] : null, 'benevole' => (bool)$r['benevole'],
            'alerte_email' => (bool)$r['alerte_email'], 'alerte_freq' => $r['alerte_freq'], 'configure' => true];
}

/** Valide et enregistre les préférences reçues (toute valeur hors liste est ignorée). */
function anim_prefs_save(PDO $pdo, int $uid, array $in): void {
    anim_match_ensure($pdo);
    $pick = fn($v, array $allowed) => array_values(array_intersect(array_keys($allowed), is_array($v) ? $v : []));
    $int = fn($v, $min, $max) => (is_numeric($v) && (int)$v >= $min && (int)$v <= $max) ? (int)$v : null;
    $dates = [];
    foreach (array_slice(is_array($in['dates_dispo'] ?? null) ? $in['dates_dispo'] : [], 0, 12) as $d) {
        $a = DateTime::createFromFormat('Y-m-d', (string)($d['from'] ?? '')); $b = DateTime::createFromFormat('Y-m-d', (string)($d['to'] ?? ''));
        if ($a && $b && $a <= $b) $dates[] = ['from' => $a->format('Y-m-d'), 'to' => $b->format('Y-m-d')];
    }
    $themes = array_values(array_unique(array_map(fn($t) => mb_substr(trim(strip_tags((string)$t)), 0, 60), array_slice(is_array($in['themes'] ?? null) ? $in['themes'] : [], 0, 30))));
    $remu = isset($in['remu_min']) && is_numeric($in['remu_min']) && (float)$in['remu_min'] > 0 ? min(round((float)$in['remu_min'], 2), 100000) : null;
    $freq = isset(ANIM_FREQS[$in['alerte_freq'] ?? '']) ? $in['alerte_freq'] : 'jamais';
    $mail = !empty($in['alerte_email']) && $freq !== 'jamais' ? 1 : 0;
    $cp = preg_replace('/[^0-9AB]/', '', strtoupper((string)($in['code_postal'] ?? '')));
    $maxCamp = $mail ? (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM camps")->fetchColumn() : 0;   // une nouvelle alerte ne rejoue pas l'historique
    $pdo->prepare("INSERT INTO animateur_prefs (user_id, qualifs, code_postal, ville, rayon, periodes, dates_dispo, themes, types, age_min, age_max, remu_min, benevole, alerte_email, alerte_freq, alerte_dernier_envoi, alerte_dernier_camp)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)
        ON DUPLICATE KEY UPDATE qualifs=VALUES(qualifs), code_postal=VALUES(code_postal), ville=VALUES(ville), rayon=VALUES(rayon), periodes=VALUES(periodes), dates_dispo=VALUES(dates_dispo),
            themes=VALUES(themes), types=VALUES(types), age_min=VALUES(age_min), age_max=VALUES(age_max), remu_min=VALUES(remu_min), benevole=VALUES(benevole),
            alerte_dernier_camp = IF(alerte_email = 0 AND VALUES(alerte_email) = 1, VALUES(alerte_dernier_camp), alerte_dernier_camp),
            alerte_email=VALUES(alerte_email), alerte_freq=VALUES(alerte_freq)")
        ->execute([$uid, json_encode($pick($in['qualifs'] ?? [], ANIM_QUALIFS)), mb_substr($cp, 0, 10), mb_substr(trim(strip_tags((string)($in['ville'] ?? ''))), 0, 120),
                   isset(ANIM_RAYONS[$in['rayon'] ?? '']) ? $in['rayon'] : 'region', json_encode($pick($in['periodes'] ?? [], ANIM_PERIODES)), json_encode($dates),
                   json_encode($themes, JSON_UNESCAPED_UNICODE), json_encode($pick($in['types'] ?? [], ANIM_TYPES)),
                   $int($in['age_min'] ?? null, 0, 30), $int($in['age_max'] ?? null, 0, 30), $remu, !empty($in['benevole']) || !isset($in['benevole']) ? 1 : 0, $mail, $freq, $maxCamp]);
}

// ---------------------------------------------------------------------------------------------
// Géographie simplifiée
// ---------------------------------------------------------------------------------------------
function anim_dept(?string $cp): string {
    $cp = strtoupper(trim((string)$cp));
    if ($cp === '') return '';
    if (preg_match('/^(97|98)/', $cp)) return substr($cp, 0, 3);
    if (preg_match('/^2[AB]/', $cp)) return substr($cp, 0, 2);
    return preg_match('/^\d{2}/', $cp) ? substr($cp, 0, 2) : '';
}
function anim_region(string $dept): string {
    static $map = null;
    if ($map === null) {
        $regions = [
            'Auvergne-Rhône-Alpes' => '01 03 07 15 26 38 42 43 63 69 73 74', 'Bourgogne-Franche-Comté' => '21 25 39 58 70 71 89 90', 'Bretagne' => '22 29 35 56',
            'Centre-Val de Loire' => '18 28 36 37 41 45', 'Corse' => '20 2A 2B', 'Grand Est' => '08 10 51 52 54 55 57 67 68 88', 'Hauts-de-France' => '02 59 60 62 80',
            'Île-de-France' => '75 77 78 91 92 93 94 95', 'Normandie' => '14 27 50 61 76', 'Nouvelle-Aquitaine' => '16 17 19 23 24 33 40 47 64 79 86 87',
            'Occitanie' => '09 11 12 30 31 32 34 46 48 65 66 81 82', 'Pays de la Loire' => '44 49 53 72 85', "Provence-Alpes-Côte d'Azur" => '04 05 06 13 83 84',
        ];
        $map = [];
        foreach ($regions as $r => $list) foreach (explode(' ', $list) as $d) $map[$d] = $r;
    }
    if (strlen($dept) === 3) return 'Outre-mer';
    return $map[$dept] ?? '';
}

/** Le séjour [a,b] chevauche-t-il l'une des périodes de vacances choisies (toutes années) ? */
function anim_periode_match(array $periodes, string $a, string $b): ?string {
    $win = ['toussaint' => ['10-17', '11-02'], 'noel' => ['12-19', '01-04'], 'hiver' => ['02-06', '03-09'], 'printemps' => ['04-03', '05-03'], 'ete' => ['07-03', '08-31']];
    $y0 = (int)substr($a, 0, 4);
    foreach ($periodes as $k) {
        if (!isset($win[$k])) continue;
        foreach ([$y0 - 1, $y0, $y0 + 1] as $y) {
            $s = $y . '-' . $win[$k][0]; $e = ($win[$k][1] < $win[$k][0] ? $y + 1 : $y) . '-' . $win[$k][1];
            if ($a <= $e && $b >= $s) return ANIM_PERIODES[$k];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------------------------
// Score
// ---------------------------------------------------------------------------------------------
/** Qualifications effectives : préférences + statut BAFA du profil de candidature */
function anim_qualifs_effectives(array $prefs, array $profil): array {
    $q = $prefs['qualifs'];
    $map = ['bafa' => 'bafa', 'equivalent' => 'bpjeps', 'en_cours' => 'stagiaire'];
    if (isset($map[$profil['bafa_statut'] ?? ''])) $q[] = $map[$profil['bafa_statut']];
    return array_values(array_unique($q));
}

/**
 * Score d'un séjour pour cet animateur. Retourne null si le séjour est incompatible (diplôme exigé, majorité, rémunération),
 * sinon ['score' => int 0..100, 'raisons' => string[]].
 * $age : âge de l'animateur (ou null).
 */
function anim_score(array $camp, array $prefs, array $quals, ?int $age): ?array {
    $raisons = []; $score = 20;   // base : le séjour recrute et est à venir
    $rem = $camp['anim_remuneration'] !== null ? (float)$camp['anim_remuneration'] : null;
    $payant = $rem !== null && $rem > 0;

    // Diplôme : BAFA exigé = BAFA complet, diplôme professionnel ou BAFD
    $fort = (bool)array_intersect($quals, ['bafa', 'bpjeps', 'bafd']);
    if (!empty($camp['bafa_obligatoire'])) {
        if (!$fort) return null;
        $score += 12; $raisons[] = 'Compatible avec votre BAFA';
    } else {
        if (in_array('stagiaire', $quals, true) && !$fort) { $score += 12; $raisons[] = 'Compatible BAFA stagiaire'; }
        elseif (!$fort) { $score += 10; $raisons[] = 'Ouvert sans BAFA'; }
        else { $score += 4; }
    }
    if (!empty($camp['anim_plus_18']) && $age !== null && $age < 18) return null;

    // Rémunération
    if ($payant) {
        if ($prefs['remu_min'] !== null && $rem < $prefs['remu_min']) return null;
        $score += 14; $raisons[] = 'Rémunéré (' . rtrim(rtrim(number_format($rem, 2, ',', ''), '0'), ',') . ' €)';
    } else {
        if (!$prefs['benevole'] || $prefs['remu_min'] !== null) return null;
        $raisons[] = 'Bénévole';
    }

    // Localisation
    $dc = anim_dept($camp['code_postal'] ?? ''); $du = anim_dept($prefs['code_postal']);
    if ($du !== '' && $dc !== '') {
        if ($dc === $du) { $score += 28; $raisons[] = 'Dans votre département'; }
        elseif (anim_region($dc) !== '' && anim_region($dc) === anim_region($du) && $prefs['rayon'] !== 'dept') { $score += 20; $raisons[] = 'Dans votre région'; }
        elseif ($prefs['rayon'] !== 'france') return null;
    }

    // Disponibilités
    $a = (string)$camp['date_debut']; $b = (string)($camp['date_fin'] ?: $camp['date_debut']);
    $dispo = null;
    foreach ($prefs['dates_dispo'] as $d) if ($a >= $d['from'] && $b <= $d['to']) { $dispo = 'Pendant vos disponibilités'; break; }
    $dispo = $dispo ?: (($p = anim_periode_match($prefs['periodes'], $a, $b)) ? 'Pendant les vacances de ' . mb_strtolower($p) : null);
    if ($dispo) { $score += 20; $raisons[] = $dispo; }
    elseif ($prefs['periodes'] || $prefs['dates_dispo']) $score -= 10;

    // Thèmes, types, âges des enfants
    $hay = mb_strtolower(($camp['theme'] ?? '') . ' ' . ($camp['type'] ?? '') . ' ' . ($camp['nom'] ?? ''));
    foreach ($prefs['themes'] as $t) if ($t !== '' && mb_strpos($hay, mb_strtolower($t)) !== false) { $score += 10; $raisons[] = 'Thème : ' . $t; break; }
    $tmap = ['itinerant' => ['itinér', 'itiner'], 'sportif' => ['sport'], 'scout' => ['scout', 'pastoral', 'aumônerie'], 'artistique' => ['art', 'théâtre', 'musique', 'danse', 'cirque'],
             'nature' => ['nature', 'aventure', 'montagne', 'équitation'], 'linguistique' => ['langue', 'anglais', 'linguist']];
    foreach ($prefs['types'] as $t) {
        foreach ($tmap[$t] ?? [] as $kw) if (mb_strpos($hay, $kw) !== false || ($t === 'itinerant' && !empty($camp['itinerant']))) { $score += 6; $raisons[] = ANIM_TYPES[$t]; break 2; }
    }
    if ($prefs['age_min'] !== null || $prefs['age_max'] !== null) {
        $lo = $prefs['age_min'] ?? 0; $hi = $prefs['age_max'] ?? 99;
        if ($camp['age_min'] !== null && $camp['age_max'] !== null && (int)$camp['age_min'] <= $hi && (int)$camp['age_max'] >= $lo) { $score += 6; $raisons[] = 'Tranche d\'âge : ' . (int)$camp['age_min'] . '–' . (int)$camp['age_max'] . ' ans'; }
    }
    return ['score' => max(0, min(100, $score)), 'raisons' => array_slice(array_values(array_unique($raisons)), 0, 4)];
}

/** Séjours qui recrutent, à venir, auxquels $uid n'a pas déjà postulé. */
function anim_camps_ouverts(PDO $pdo, int $uid, int $limit = 300): array {
    $st = $pdo->prepare("SELECT c.id, c.token, c.nom, c.ville, c.code_postal, c.image_url, c.date_debut, c.date_fin, c.age_min, c.age_max, c.theme, c.type, c.itinerant,
            c.bafa_obligatoire, c.anim_plus_18, c.anim_remuneration, c.quota_max_anim, o.nom AS organisateur
        FROM camps c JOIN organisateurs o ON o.id = c.organisateur_id
        WHERE c.recrute_animateurs = 1 AND c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND c.supprime = 0 AND c.date_debut >= CURDATE()
          AND NOT EXISTS (SELECT 1 FROM candidatures cd WHERE cd.camp_id = c.id AND cd.user_id = ? AND cd.statut <> 'Retirée')
        ORDER BY c.id DESC LIMIT " . (int)$limit);
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Carte envoyée au navigateur (aucun identifiant numérique) */
function anim_carte(array $c, ?array $m = null): array {
    $strip = fn($s) => trim(strip_tags(htmlspecialchars_decode((string)$s)));
    $img = trim((string)$c['image_url']);
    if ($img !== '' && !preg_match('#^(https?:)?//#', $img) && strpos($img, 'uploads/') !== 0) $img = 'uploads/camps/' . ltrim($img, '/');
    return ['token' => $c['token'], 'nom' => $strip($c['nom']), 'ville' => $strip($c['ville']), 'image_url' => $img ?: null, 'date_debut' => $c['date_debut'], 'date_fin' => $c['date_fin'],
            'organisateur' => $strip($c['organisateur']), 'remuneration' => $c['anim_remuneration'] !== null ? (float)$c['anim_remuneration'] : null,
            'bafa_obligatoire' => !empty($c['bafa_obligatoire']), 'score' => $m['score'] ?? null, 'raisons' => $m['raisons'] ?? []];
}

/** Âge d'un utilisateur (ou null) */
function anim_age(array $user): ?int {
    $dn = $user['date_naissance'] ?? null;
    return $dn ? (new DateTime($dn))->diff(new DateTime())->y : null;
}
