<?php
// api/search_lib.php : le moteur de recherche et de classement des séjours.
// La page « Comment fonctionne le classement » (classement.php) lit les poids ci-dessous : une seule source de vérité.
//
// DÉROULÉ D'UNE RECHERCHE  (sq_run)
//   1. La phrase tapée est analysée : âge, budget, période reconnus ; les autres mots deviennent des « racines » (sq_parse_query).
//   2. Une requête SQL ramène les séjours visibles qui respectent les filtres durs (dates, âge, prix, sexe...). Le texte n'est PAS filtré en SQL.
//   3. Chaque séjour reçoit un score de PERTINENCE 0..100 (sq_pertinence) : chaque mot tapé est cherché dans les champs du séjour,
//      avec un poids par champ (alias > nom > catégories > ville > description), des synonymes, des préfixes et une tolérance aux fautes.
//   4. Les filtres de catégories et de précisions s'appliquent, les compteurs sont calculés (sq_comptes).
//   5. Score final = (pertinence, proximité du départ, disponibilité, qualité réelle, nouveauté, personnalisation) x effet des boosts payants,
//      puis ré-ordonnancement qui évite les rafales d'un même organisme (sq_diversifier).
// Les fonctions sq_* sans accès base (sq_search, sq_pertinence, sq_expand...) sont testées sans base : tests/search_check.php.

require_once __DIR__ . '/search_text.php';
require_once __DIR__ . '/catalogue.php';
require_once __DIR__ . '/motscles.php';
require_once __DIR__ . '/marketing_lib.php';

/** Poids des champs : le meilleur champ qui contient le mot gagne (jamais une somme : le bourrage ne sert à rien). */
const SQ_FIELD_W = ['kw_camp' => 90, 'kw_orga' => 80, 'nom' => 60, 'nom_orga' => 55, 'theme' => 40, 'cat' => 40, 'facet' => 30, 'ville' => 25, 'desc' => 6];
const SQ_MAXW = 90;            // un mot-clé exact et de confiance = pertinence 100
const SQ_REL_MIN = 5.0;        // en dessous, le séjour n'est pas un résultat (ex. mot trouvé seulement par ressemblance dans la description)
/** Poids du score organique (somme = 1) : avec un texte tapé, et en parcourant simplement le catalogue. */
const SQ_W_QUERY  = ['rel' => 0.50, 'prox' => 0.12, 'avail' => 0.10, 'quality' => 0.14, 'fresh' => 0.04, 'perso' => 0.10];
const SQ_W_BROWSE = ['rel' => 0.00, 'prox' => 0.22, 'avail' => 0.15, 'quality' => 0.30, 'fresh' => 0.08, 'perso' => 0.25];
const SQ_DIVERSITE = 0.75;     // chaque séjour déjà placé du même organisme multiplie le score du suivant par 0,75
const SQ_DIVERSITE_TOP = 96;   // le ré-ordonnancement porte sur les 96 premières places (4 pages)

// ---------------------------------------------------------------------------------------------
// Vocabulaire : mots connus du site (autocomplétion, « vouliez-vous dire », tolérance aux fautes)
// ---------------------------------------------------------------------------------------------
function sq_vocab_fichier(): string { return sys_get_temp_dir() . '/colomap_vocab_' . md5(__DIR__) . '.json'; }
function sq_vocab_invalider(): void { @unlink(sq_vocab_fichier()); unset($GLOBALS['sq_vocab_memo']); }

function sq_vocab_ajouter(array &$v, string $label, string $kind, ?string $slug = null, int $n = 1): void {
    $label = trim($label);
    $norm = sq_norm($label);
    if (strlen($norm) < 2) return;
    $base = ['alias' => 600, 'mot-clé' => 500, 'organisme' => 400, 'catégorie' => 300, 'séjour' => 200, 'ville' => 100, 'précision' => 50][$kind] ?? 0;
    if (isset($v['terms'][$norm])) { $v['terms'][$norm][2] += $n; return; }
    $v['terms'][$norm] = [$label, $kind, $base + $n, $slug];
    foreach (sq_tokens($norm) as $t) $v['tokens'][$t] = ($v['tokens'][$t] ?? 0) + 1;
}

/** Vocabulaire construit depuis la base (séjours visibles et à venir). */
function sq_vocab_construire(PDO $pdo, array $catalogue): array {
    $v = ['terms' => [], 'tokens' => []];
    foreach ($catalogue as $c) {
        sq_vocab_ajouter($v, $c['nom'], 'catégorie', $c['slug'], 5);
        foreach ($c['facettes'] as $f) foreach ($f['valeurs'] as $label) sq_vocab_ajouter($v, $label, 'précision');
    }
    $vis = "c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND COALESCE(c.supprime, 0) = 0 AND COALESCE(c.prive, 0) = 0 AND COALESCE(c.date_limite_inscription, c.date_debut) >= CURDATE()";
    try {
        foreach ($pdo->query("SELECT c.nom, c.ville, c.theme, o.nom AS orga, o.slug FROM camps c JOIN organisateurs o ON o.id = c.organisateur_id WHERE $vis")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            sq_vocab_ajouter($v, (string)$r['nom'], 'séjour');
            sq_vocab_ajouter($v, (string)$r['ville'], 'ville');
            sq_vocab_ajouter($v, (string)$r['orga'], 'organisme', (string)$r['slug']);
        }
        foreach ($pdo->query("SELECT MIN(m.label) AS label, COUNT(*) AS n FROM camp_motscles m JOIN camps c ON c.id = m.camp_id WHERE $vis GROUP BY m.mot")->fetchAll(PDO::FETCH_ASSOC) as $r)
            sq_vocab_ajouter($v, (string)$r['label'], 'mot-clé', null, (int)$r['n']);
        foreach ($pdo->query("SELECT MIN(m.label) AS label, o.slug, COUNT(DISTINCT c.id) AS n FROM organisme_motscles m JOIN organisateurs o ON o.id = m.organisateur_id JOIN camps c ON c.organisateur_id = o.id WHERE $vis GROUP BY m.mot, o.id, o.slug")->fetchAll(PDO::FETCH_ASSOC) as $r)
            sq_vocab_ajouter($v, (string)$r['label'], 'alias', (string)$r['slug'], (int)$r['n']);
    } catch (Throwable $e) { error_log('sq_vocab_construire: ' . $e->getMessage()); }
    return $v;
}

/** Vocabulaire en cache fichier 10 minutes (une requête de recherche ne reconstruit rien). Invalidé à chaque enregistrement de mots-clés. */
function sq_vocab(PDO $pdo, array $catalogue): array {
    if (isset($GLOBALS['sq_vocab_memo'])) return $GLOBALS['sq_vocab_memo'];
    $f = sq_vocab_fichier();
    if (is_file($f) && filemtime($f) > time() - 600) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && isset($j['terms'], $j['tokens'])) return $GLOBALS['sq_vocab_memo'] = $j;
    }
    $v = sq_vocab_construire($pdo, $catalogue);
    @file_put_contents($f, json_encode($v, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $GLOBALS['sq_vocab_memo'] = $v;
}

/** Vocabulaire déduit d'une liste de documents (tests, ou base vide). */
function sq_vocab_des_docs(array $docs): array {
    $v = ['terms' => [], 'tokens' => []];
    foreach ($docs as $d) foreach ($d['f'] as $f => $set) if ($f !== 'desc') foreach ($set as $t => $_) $v['tokens'][(string)$t] = ($v['tokens'][(string)$t] ?? 0) + 1;
    return $v;
}

// ---------------------------------------------------------------------------------------------
// Documents : un séjour sous forme de champs de racines
// ---------------------------------------------------------------------------------------------
/**
 * Confiance accordée à un mot-clé de séjour (multiplie son poids) :
 *   1    le mot parle du séjour (il est dans son nom, ses catégories, sa description... ou l'un de ses synonymes l'est) ou c'est un sigle de son nom
 *   0,5  c'est un mot de thème connu (« plage », « ski »...) qui n'a aucun rapport avec le séjour : emprunt de popularité
 *   0,9  autre mot : alias propre au séjour (« EPJ »)
 */
function sq_kw_confiance(array $toks, array $contenu, array $freq, string $nom, string $orga): float {
    if (!$toks) return 0.0;
    $lie = true;
    foreach ($toks as $t) if (!isset($contenu[$t]) && !array_intersect(sq_syn_of($t), array_keys($contenu))) { $lie = false; break; }
    if ($lie) return 1.0;
    if (count($toks) === 1 && strlen($toks[0]) >= 2 && strlen($toks[0]) <= 6) {
        foreach ([$nom, $orga] as $src) {
            $mots = array_values(array_filter(explode(' ', $src)));
            if (count($mots) < 2) continue;
            $avec = implode('', array_map(function ($w) { return $w[0]; }, $mots));
            $sans = implode('', array_map(function ($w) { return $w[0]; }, array_values(array_filter($mots, function ($w) { return !in_array($w, SQ_STOP, true); }))));
            if ($toks[0] === $avec || $toks[0] === $sans) return 1.0;
        }
    }
    foreach ($toks as $t) if (!isset($contenu[$t]) && (sq_syn_of($t) || ($freq[$t] ?? 0) >= 4)) return 0.5;
    return 0.9;
}

/**
 * Transforme un séjour en document de recherche.
 * $c : id, organisateur_id, nom, organisateur_nom, ville, code_postal, theme, type, description, kw_camp[], kw_orga[], cats[slug], facets[[cat, facette, valeur]]
 */
function sq_doc(array $c, array $catalogue, array $freq = [], bool $avecTexte = true): array {
    $set = function (string $s, bool $generiques = false) { return array_fill_keys(sq_tokens(sq_norm($s), $generiques), 1); };
    if (!$avecTexte) {   // simple parcours du catalogue : seuls les filtres ont besoin du document
        $cats = []; $fv = [];
        foreach ((array)($c['cats'] ?? []) as $i => $slug) if (isset($catalogue[$slug])) $cats[$slug] = $i === 0 ? 1 : 0;
        foreach ((array)($c['facets'] ?? []) as [$cat, $fac, $val]) if (isset($catalogue[$cat]['facettes'][$fac]['valeurs'][$val])) $fv[$cat . ':' . $fac . ':' . $val] = 1;
        return ['id' => (int)$c['id'], 'orga' => (int)($c['organisateur_id'] ?? 0), 'grp' => !empty($c['groupe_id']) ? 'g' . (int)$c['groupe_id'] : 'c' . (int)$c['id'], 'f' => [], 'cat' => $cats, 'fv' => $fv];
    }
    $f = [
        'nom' => $set((string)($c['nom'] ?? '')), 'nom_orga' => $set((string)($c['organisateur_nom'] ?? '')),
        'ville' => $set(trim(($c['ville'] ?? '') . ' ' . ($c['code_postal'] ?? ''))),
        'theme' => $set(trim(($c['theme'] ?? '') . ' ' . str_replace(',', ' ', (string)($c['type'] ?? '')))),
        'desc' => $set(mb_substr((string)($c['description'] ?? ''), 0, 600)),
        'cat' => [], 'facet' => [], 'kw_camp' => [], 'kw_orga' => [],
    ];
    $cats = [];
    foreach ((array)($c['cats'] ?? []) as $i => $slug) {
        if (!isset($catalogue[$slug])) continue;
        $cats[$slug] = $i === 0 ? 1 : 0;
        $f['cat'] += $set($catalogue[$slug]['nom']);
    }
    $fv = [];
    foreach ((array)($c['facets'] ?? []) as [$cat, $fac, $val]) {
        if (!isset($catalogue[$cat]['facettes'][$fac]['valeurs'][$val])) continue;
        $fv[$cat . ':' . $fac . ':' . $val] = 1;
        $f['facet'] += $set($catalogue[$cat]['facettes'][$fac]['valeurs'][$val]);
    }
    $contenu = $f['nom'] + $f['nom_orga'] + $f['ville'] + $f['desc'] + $f['cat'] + array_fill_keys(sq_tokens(sq_norm((string)($c['theme'] ?? ''))), 1);
    $nomN = sq_norm((string)($c['nom'] ?? '')); $orgN = sq_norm((string)($c['organisateur_nom'] ?? ''));
    foreach ((array)($c['kw_camp'] ?? []) as $label) {
        $toks = sq_tokens(mc_norm((string)$label));
        $trust = sq_kw_confiance($toks, $contenu, $freq, $nomN, $orgN);
        foreach ($toks as $t) $f['kw_camp'][$t] = max($f['kw_camp'][$t] ?? 0, $trust);
    }
    foreach ((array)($c['kw_orga'] ?? []) as $label) foreach (sq_tokens(mc_norm((string)$label)) as $t) $f['kw_orga'][$t] = 1.0;
    return ['id' => (int)$c['id'], 'orga' => (int)($c['organisateur_id'] ?? 0), 'grp' => !empty($c['groupe_id']) ? 'g' . (int)$c['groupe_id'] : 'c' . (int)$c['id'], 'f' => $f, 'cat' => $cats, 'fv' => $fv];
}

// ---------------------------------------------------------------------------------------------
// Recherche textuelle
// ---------------------------------------------------------------------------------------------
/**
 * Chaque mot tapé devient une famille de variantes avec une qualité : lui-même 1, synonymes 0,75, début de mot 0,7-0,85 (le dernier mot tapé
 * est souvent incomplet), faute de frappe 0,6 (1 erreur) ou 0,5 (2 erreurs, mots de 8 lettres et plus). Les fautes ne sont cherchées que si le mot
 * est inconnu du site, et il faut la même première lettre.
 * Retourne [['t' => racine, 'v' => [variante => qualité], 'typo' => mot corrigé|null]].
 */
function sq_expand(array $tokens, array $vocab): array {
    $tv = $vocab['tokens'] ?? [];
    $out = []; $last = count($tokens) - 1;
    foreach (array_values($tokens) as $i => $t) {
        $v = [$t => 1.0]; $typo = null;
        foreach (sq_syn_of($t) as $s) $v[$s] = max($v[$s] ?? 0, 0.75);
        if ($tv) {
            $lt = strlen($t);
            if ($lt >= 3) {
                $n = 0;
                foreach ($tv as $w => $_) { $w = (string)$w; if ($w !== $t && strncmp($w, $t, $lt) === 0) { $v[$w] = max($v[$w] ?? 0, $i === $last ? 0.85 : 0.7); if (++$n >= 25) break; } }
            }
            if (!isset($tv[$t]) && $lt >= 4 && !ctype_digit($t)) {
                $max = sq_fuzzy_max($lt); $best = null;
                foreach ($tv as $w => $freq) {
                    $w = (string)$w;
                    if ($w[0] !== $t[0]) continue;
                    $d = sq_dl($t, $w, $max);
                    if ($d < 1 || $d > $max) continue;
                    $v[$w] = max($v[$w] ?? 0, $d === 1 ? 0.6 : 0.5);
                    if ($best === null || $d < $best[0] || ($d === $best[0] && $freq > $best[2])) $best = [$d, $w, $freq];
                }
                if ($best) $typo = $best[1];
            }
        }
        $out[] = ['t' => $t, 'v' => $v, 'typo' => $typo];
    }
    return $out;
}

/**
 * Pertinence 0..100 de chaque document pour les mots tapés.
 * Un mot rare compte plus qu'un mot qui est partout (idf). Il faut que tous les mots soient trouvés (60 % à partir de 3 mots),
 * ou un seul mot en mode $partiel (utilisé quand rien ne contient tous les mots).
 * Retourne [index du document => ['rel' => float, 'org_exact' => bool, 'champs' => [mot => champ]]].
 */
function sq_pertinence(array $docs, array $exp, bool $partiel = false): array {
    $n = count($exp); $N = max(1, count($docs));
    if ($n === 0) return [];
    $best = []; $df = array_fill(0, $n, 0);
    foreach ($docs as $i => $d) {
        foreach ($exp as $k => $e) {
            $b = 0.0; $bf = ''; $bq = 0.0;
            foreach (SQ_FIELD_W as $f => $w) {
                $set = $d['f'][$f] ?? null;
                if (!$set) continue;
                foreach ($e['v'] as $var => $q) {
                    if (isset($set[$var])) { $val = $w * $q * (float)$set[$var]; if ($val > $b) { $b = $val; $bf = $f; $bq = $q; } }
                }
            }
            $best[$i][$k] = [$b, $bf, $bq];
            if ($b > 0) $df[$k]++;
        }
    }
    $idf = []; $sumIdf = 0.0;
    foreach ($df as $k => $c) { $idf[$k] = log(1 + $N / (1 + $c)); $sumIdf += $idf[$k]; }
    $need = $partiel ? 1 : ($n <= 2 ? $n : (int)ceil(0.6 * $n));
    $out = [];
    foreach ($best as $i => $row) {
        $cov = 0; $num = 0.0; $champs = []; $orgExact = true;
        foreach ($row as $k => [$b, $bf, $bq]) {
            if ($b > 0) { $cov++; $num += $idf[$k] * $b; $champs[$exp[$k]['t']] = $bf; }
            if (!($b > 0 && ($bf === 'kw_orga' || $bf === 'nom_orga') && $bq >= 1.0)) $orgExact = false;
        }
        if ($cov < $need) continue;
        $rel = min(100.0, 100.0 * $num / ($sumIdf * SQ_MAXW));
        if ($rel < SQ_REL_MIN) continue;
        $out[$i] = ['rel' => $rel, 'org_exact' => $orgExact, 'champs' => $champs];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Catégories et précisions : filtres et compteurs
// ---------------------------------------------------------------------------------------------
/**
 * Sélection de l'URL : cat[]=montagne&cat[]=spirituel&fv[]=montagne:niveau:debutant&catmode=all
 * Valeurs inconnues du catalogue ignorées. Retourne ['cats' => [], 'fv' => [cat => [facette => [valeurs]]], 'all' => bool].
 */
function sq_selection(array $get, array $catalogue): array {
    $sel = ['cats' => [], 'fv' => [], 'all' => (($get['catmode'] ?? '') === 'all')];
    foreach ((array)($get['cat'] ?? []) as $c) { $c = (string)$c; if (isset($catalogue[$c]) && !in_array($c, $sel['cats'], true) && count($sel['cats']) < 8) $sel['cats'][] = $c; }
    foreach (array_slice((array)($get['fv'] ?? []), 0, 40) as $s) {
        $p = explode(':', (string)$s, 3);
        if (count($p) === 3 && in_array($p[0], $sel['cats'], true) && isset($catalogue[$p[0]]['facettes'][$p[1]]['valeurs'][$p[2]])) $sel['fv'][$p[0]][$p[1]][] = $p[2];
    }
    return $sel;
}

/** Le document passe-t-il le filtre ? $excl = [catégorie, facette] : ignorer ce groupe de réponses (pour les compteurs). */
function sq_passe(array $doc, array $sel, ?array $excl = null): bool {
    if (!$sel['cats']) return true;
    $hits = 0;
    foreach ($sel['cats'] as $c) {
        $ok = isset($doc['cat'][$c]);
        if ($ok) foreach ($sel['fv'][$c] ?? [] as $fac => $vals) {
            if ($excl && $excl[0] === $c && $excl[1] === $fac) continue;
            $have = false;
            foreach ($vals as $v) if (isset($doc['fv'][$c . ':' . $fac . ':' . $v])) { $have = true; break; }
            if (!$have) { $ok = false; break; }
        }
        if ($sel['all'] && !$ok) return false;
        if ($ok) $hits++;
    }
    return $sel['all'] ? true : $hits > 0;
}

/** Compteurs : par catégorie (tous les séjours trouvés) et par réponse des catégories choisies (les autres filtres appliqués). Un camp à plusieurs sessions compte une fois. */
function sq_comptes(array $docs, array $sel): array {
    $cats = []; $vals = []; $seen = [];
    foreach ($docs as $d) {
        foreach ($d['cat'] as $c => $_) { if (isset($seen['c:' . $c][$d['grp']])) continue; $seen['c:' . $c][$d['grp']] = 1; $cats[$c] = ($cats[$c] ?? 0) + 1; }
        if (!$sel['cats']) continue;
        foreach ($d['fv'] as $key => $_) {
            $p = explode(':', $key, 3);
            if (!in_array($p[0], $sel['cats'], true) || isset($seen[$key][$d['grp']])) continue;
            if (sq_passe($d, $sel, [$p[0], $p[1]])) { $seen[$key][$d['grp']] = 1; $vals[$key] = ($vals[$key] ?? 0) + 1; }
        }
    }
    return ['cats' => $cats, 'vals' => $vals];
}

/** Les filtres à afficher : catégories avec leur compteur, et pour chaque catégorie choisie ses précisions avec les compteurs. */
function sq_facettes_reponse(array $catalogue, array $comptes, array $sel): array {
    $cats = [];
    foreach ($catalogue as $slug => $c) {
        $n = (int)($comptes['cats'][$slug] ?? 0);
        if ($n > 0 || in_array($slug, $sel['cats'], true)) $cats[] = ['slug' => $slug, 'nom' => $c['nom'], 'count' => $n, 'on' => in_array($slug, $sel['cats'], true)];
    }
    $groupes = [];
    foreach ($sel['cats'] as $slug) {
        foreach ($catalogue[$slug]['facettes'] as $fk => $fa) {
            $vals = [];
            foreach ($fa['valeurs'] as $vk => $label) {
                $on = in_array((string)$vk, array_map('strval', $sel['fv'][$slug][$fk] ?? []), true);
                $n = (int)($comptes['vals'][$slug . ':' . $fk . ':' . $vk] ?? 0);
                if ($n > 0 || $on) $vals[] = ['cle' => (string)$vk, 'libelle' => $label, 'count' => $n, 'on' => $on];
            }
            if ($vals) $groupes[] = ['cat' => $slug, 'cat_nom' => $catalogue[$slug]['nom'], 'cle' => $fk, 'nom' => $fa['nom'], 'type' => $fa['type'], 'aide' => $fa['aide'], 'valeurs' => $vals];
        }
    }
    return ['categories' => $cats, 'groupes' => $groupes];
}

// ---------------------------------------------------------------------------------------------
// Classement
// ---------------------------------------------------------------------------------------------
/** Qualité réelle 0..100 : taux de clic et taux de conversion lissés (peu de données = valeur moyenne, jamais 0). */
function sq_qualite(float $impressions, float $clics, float $inscriptions): float {
    $ctr = ($clics + 1) / ($impressions + 20);          // même lissage que mk_ctr_map
    $conv = ($inscriptions + 0.5) / ($clics + 10);      // a priori : 5 % des visites aboutissent
    return 100 * (0.6 * min(1.0, $ctr / 0.15) + 0.4 * min(1.0, $conv / 0.10));
}

/** Parties du score, chacune de 0 à 100. */
function sq_parties(array $c, int $now, array $perso, ?array $doc): array {
    $jours = !empty($c['date_debut']) ? (strtotime($c['date_debut']) - $now) / 86400 : 120;
    $prox = $jours < 0 ? 60.0 : 30 + 70 * exp(-$jours / 45);
    $places = $c['nb_places_restantes'] ?? null;
    $quota = (int)($c['quota_global'] ?? 0);
    $avail = ($places === null || $quota <= 0) ? 75.0 : ((int)$places <= 0 ? 0.0 : ((int)$places <= 5 ? 90.0 : 100.0));
    $age = !empty($c['date_creation']) ? max(0, ($now - strtotime($c['date_creation'])) / 86400) : 365;
    $fresh = 40 + 60 * exp(-$age / 21);
    $q = sq_qualite((float)($c['imp'] ?? 0), (float)($c['clics'] ?? 0), (float)($c['insc'] ?? 0));
    $p = 50.0;
    if (!empty($perso['ages'])) {
        $fits = false;
        foreach ($perso['ages'] as $a) if (($c['age_min'] === null || $a >= (int)$c['age_min']) && ($c['age_max'] === null || $a <= (int)$c['age_max'])) { $fits = true; break; }
        $p += $fits ? 25 : -20;
    }
    if ($doc && !empty($perso['cats'])) foreach ($doc['cat'] as $slug => $_) if (isset($perso['cats'][$slug])) { $p += 15; break; }
    if (!empty($perso['orgas'][(int)($c['organisateur_id'] ?? 0)])) $p += 10;
    return ['prox' => $prox, 'avail' => $avail, 'fresh' => $fresh, 'quality' => $q, 'perso' => max(0.0, min(100.0, $p))];
}

/**
 * Ré-ordonnancement qui évite les rafales d'un même organisme : on place le meilleur, puis chaque séjour déjà placé du même organisme
 * multiplie par SQ_DIVERSITE le score des suivants (seuls les SQ_DIVERSITE_TOP premiers sont concernés). $exempt : organismes explicitement
 * recherchés (« PAJE ») : on ne les disperse pas.
 * $items : [['score' => float, 'orga' => int, ...]] déjà triés par score décroissant.
 */
function sq_diversifier(array $items, array $exempt = [], float $decay = SQ_DIVERSITE, int $top = SQ_DIVERSITE_TOP): array {
    $out = []; $cnt = [];
    while ($items && count($out) < $top) {
        $bk = null; $bs = -1.0;
        foreach ($items as $k => $it) {
            $s = $it['score'] * (isset($exempt[$it['orga']]) ? 1.0 : pow($decay, $cnt[$it['orga']] ?? 0));
            if ($s > $bs) { $bs = $s; $bk = $k; }
        }
        $it = $items[$bk]; unset($items[$bk]);
        $cnt[$it['orga']] = ($cnt[$it['orga']] ?? 0) + 1;
        $out[] = $it;
    }
    return array_merge($out, array_values($items));
}

/**
 * Cœur de la recherche, sans base de données.
 * @param array $rows   séjours (lignes de camps + organisateur_nom, kw_camp, kw_orga, cats, facets, imp, clics, insc, nb_places_restantes...)
 * @param array $query  résultat de sq_parse_query
 * @param array $sel    résultat de sq_selection
 * @param array $opt    catalogue, vocab, now (timestamp), perso, sort (relevance|date|price_asc|price_desc), partiel_auto (bool)
 * @return array items (triés, tous), comptes, partiel (bool), dym (string|null), docs
 */
// ponytail: les documents sont reconstruits à chaque recherche (~0,15 ms par séjour avec texte : 500 séjours = 75 ms, 2000 = 0,3 s).
//   Au-delà de ~5000 séjours : garder les documents en cache (fichier ou APCu) invalidé à l'enregistrement d'un séjour.
function sq_search(array $rows, array $query, array $sel, array $opt): array {
    $catalogue = $opt['catalogue'] ?? []; $now = $opt['now'] ?? time(); $perso = $opt['perso'] ?? [];
    $vocab = $opt['vocab'] ?? [];
    $hasText = !empty($query['tokens']);
    $docs = [];
    foreach ($rows as $i => $r) $docs[$i] = sq_doc($r, $catalogue, $vocab['tokens'] ?? [], $hasText);
    if (empty($vocab['tokens']) && $hasText) {   // pas de vocabulaire fourni : on le déduit des séjours eux-mêmes (tests), puis on recalcule la confiance des mots-clés
        $vocab = sq_vocab_des_docs($docs);
        foreach ($rows as $i => $r) $docs[$i] = sq_doc($r, $catalogue, $vocab['tokens']);
    }

    $exp = $hasText ? sq_expand($query['tokens'], $vocab) : [];
    $rel = null; $partiel = false;
    if ($hasText) {
        $rel = sq_pertinence($docs, $exp, false);
        if (!$rel && ($opt['partiel_auto'] ?? true) && count($exp) > 1) { $rel = sq_pertinence($docs, $exp, true); $partiel = (bool)$rel; }
    }
    $dym = null;
    $fix = array_filter(array_column($exp, 'typo', 't'));
    if ($fix) {
        $mots = [];
        foreach (explode(' ', $query['text']) as $w) { $st = sq_stem($w); $mots[] = $fix[$st] ?? $w; }
        $dym = implode(' ', $mots);
    }

    // Séjours retenus par le texte, puis compteurs, puis filtres de catégories
    $texte = [];
    foreach ($docs as $i => $d) { if ($hasText && !isset($rel[$i])) continue; $texte[$i] = $d; }
    $comptes = sq_comptes($texte, $sel);
    $exempt = [];
    $items = [];
    foreach ($texte as $i => $d) {
        if (!sq_passe($d, $sel)) continue;
        $r = $rows[$i];
        $R = $hasText ? $rel[$i]['rel'] : null;
        $w = $hasText ? SQ_W_QUERY : SQ_W_BROWSE;
        $p = sq_parties($r, $now, $perso, $d);
        $organique = $w['rel'] * ($R ?? 0) + $w['prox'] * $p['prox'] + $w['avail'] * $p['avail'] + $w['quality'] * $p['quality'] + $w['fresh'] * $p['fresh'] + $w['perso'] * $p['perso'];
        // Boosts payants : ils amplifient la pertinence, ils ne la remplacent pas (un séjour peu pertinent profite peu du boost)
        $r['ctr30'] = ((float)($r['clics'] ?? 0) + 1) / ((float)($r['imp'] ?? 0) + 20);
        $mk = mk_score($r, date('Y-m-d H:i:s', $now));
        $jitter = $mk['score'] / max(0.0001, $mk['organique'] * $mk['boost_factor']);
        $boost = 1 + ($mk['boost_factor'] - 1) * ($hasText ? 0.4 + 0.6 * $R / 100 : 1);
        if (count($sel['cats']) > 1) $organique += 8 * max(0, count(array_intersect($sel['cats'], array_keys($d['cat']))) - 1);   // « Spi & Cimes » passe devant les séjours qui n'ont qu'une des deux catégories choisies
        $score = $organique * $boost * $jitter;
        if ($r['quota_global'] > 0 && isset($r['nb_places_restantes']) && (int)$r['nb_places_restantes'] <= 0) $score *= 0.35;   // complet : très bas
        if ($hasText && $rel[$i]['org_exact']) $exempt[$d['orga']] = true;
        $items[] = ['i' => $i, 'row' => $r, 'orga' => $d['orga'], 'score' => $score, 'rel' => $R, 'sponsor' => $mk['sponsorise'],
                    'parts' => ['rel' => $R === null ? null : round($R, 1)] + array_map(function ($x) { return round($x, 1); }, $p) + ['boost' => round($boost, 3), 'jitter' => round($jitter, 3)],
                    'why' => $hasText ? $rel[$i]['champs'] : []];
    }

    $sort = $opt['sort'] ?? 'relevance';
    usort($items, function ($a, $b) { return $b['score'] <=> $a['score']; });
    if ($sort === 'date') usort($items, function ($a, $b) { return strcmp((string)$a['row']['date_debut'], (string)$b['row']['date_debut']) ?: ($b['score'] <=> $a['score']); });
    elseif ($sort === 'price_asc') usort($items, function ($a, $b) { return ((float)$a['row']['prix_affiche'] <=> (float)$b['row']['prix_affiche']) ?: ($b['score'] <=> $a['score']); });
    elseif ($sort === 'distance') usort($items, function ($a, $b) { return ((float)($a['row']['distance_km'] ?? 1e9) <=> (float)($b['row']['distance_km'] ?? 1e9)) ?: ($b['score'] <=> $a['score']); });
    elseif ($sort === 'price_desc') usort($items, function ($a, $b) { return ((float)$b['row']['prix_affiche'] <=> (float)$a['row']['prix_affiche']) ?: ($b['score'] <=> $a['score']); });
    else $items = sq_diversifier($items, $exempt);

    return ['items' => $items, 'comptes' => $comptes, 'partiel' => $partiel, 'dym' => $dym, 'docs' => $docs, 'vocab' => $vocab];
}

// ---------------------------------------------------------------------------------------------
// Base de données : candidats, données associées, statistiques, profil
// ---------------------------------------------------------------------------------------------
/** Séjours qui respectent les filtres durs. $f : city, near [lat, lng, km], max_price, age, age_range, date_from, date_to, online, sexe, themes[], base_where, today */
function sq_candidats(PDO $pdo, array $f): array {
    $params = [':today' => $f['today'] ?? date('Y-m-d')];
    $where = $f['base_where'] ?? " WHERE c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND COALESCE(c.supprime, 0) = 0 AND COALESCE(c.prive, 0) = 0 AND COALESCE(c.date_limite_inscription, c.date_debut) >= :today ";
    if (isset($f['base_params'])) $params = $f['base_params'];
    $price = "COALESCE((SELECT MIN(t.prix) FROM camps_tarifs ct JOIN tarifs t ON ct.tarif_id = t.id WHERE ct.camp_id = c.id), c.prix)";
    $grp = (function_exists('campsHaveGroupColumn') && campsHaveGroupColumn($pdo)) ? 'c.groupe_id' : 'NULL';
    $dist = '';
    if (!empty($f['near'])) {
        $dist = ", (6371 * ACOS(LEAST(1, COS(RADIANS(:nla)) * COS(RADIANS(c.lat)) * COS(RADIANS(c.lng) - RADIANS(:nlo)) + SIN(RADIANS(:nla2)) * SIN(RADIANS(c.lat))))) AS distance_km";
        $params += [':nla' => $f['near'][0], ':nlo' => $f['near'][1], ':nla2' => $f['near'][0], ':nkm' => $f['near'][2]];
    }
    $sql = "SELECT c.id, c.token, c.nom, c.ville, c.code_postal, c.image_url, c.date_debut, c.date_fin, c.age_min, c.age_max, c.type, c.theme, $grp AS groupe_id,
            c.inscription_en_ligne, c.date_bump, c.date_creation, c.quota_global, c.organisateur_id, c.boost_vedette_fin, c.boost_urgence_fin,
            SUBSTRING(c.description, 1, 600) AS description,
            (c.quota_global - (SELECT COUNT(*) FROM inscriptions i WHERE i.camp_id = c.id AND i.statut NOT IN ('Annulé', 'annule', 'Annule'))) AS nb_places_restantes,
            o.nom AS organisateur_nom, o.logo_url AS organisateur_logo, o.slug AS organisateur_slug,
            $price AS prix_affiche, (c.boost_vedette_fin > NOW()) AS is_vedette, (c.boost_urgence_fin > NOW()) AS is_urgence$dist
            FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id $where";
    $city = trim((string)($f['city'] ?? ''));
    if ($city !== '') { $sql .= " AND (c.ville LIKE :city OR c.code_postal LIKE :city)"; $params[':city'] = '%' . $city . '%'; }
    if (!empty($f['max_price']) && (float)$f['max_price'] > 0) { $sql .= " AND $price <= :max"; $params[':max'] = (float)$f['max_price']; }
    if (isset($f['age']) && $f['age'] !== null && $f['age'] !== '') { $sql .= " AND c.age_min <= :age AND c.age_max >= :age"; $params[':age'] = (int)$f['age']; }
    elseif (!empty($f['age_range'])) { $sql .= " AND c.age_min <= :ahi AND c.age_max >= :alo"; $params[':alo'] = (int)$f['age_range'][0]; $params[':ahi'] = (int)$f['age_range'][1]; }
    if (!empty($f['date_from'])) { $sql .= " AND COALESCE(c.date_fin, c.date_debut) >= :dfrom"; $params[':dfrom'] = $f['date_from']; }
    if (!empty($f['date_to'])) { $sql .= " AND c.date_debut <= :dto"; $params[':dto'] = $f['date_to']; }
    if (!empty($f['online'])) $sql .= " AND c.inscription_en_ligne = 1";
    if (!empty($f['sexe']) && function_exists('options_sexe_sql')) { $sql .= ' AND ' . options_sexe_sql('s'); $params[':sx_s'] = $f['sexe']; }
    if (!empty($f['themes']) && is_array($f['themes'])) {
        // Anciens thèmes (themes[]=Montagne) : le texte historique OU la catégorie qui lui correspond
        $parts = [];
        foreach (array_slice(array_values($f['themes']), 0, 30) as $k => $v) {
            $p = "(c.theme LIKE :th_$k OR c.type LIKE :th_$k";
            $params[":th_$k"] = '%' . trim((string)$v) . '%';
            $slugs = !empty($f['catalogue']) ? cat_legacy_guess($f['catalogue'], (string)$v, '') : [];
            if ($slugs) {
                $in = [];
                foreach ($slugs as $j => $s) { $in[] = ":thc_{$k}_$j"; $params[":thc_{$k}_$j"] = $s; }
                $p .= " OR EXISTS (SELECT 1 FROM camp_categories cc WHERE cc.camp_id = c.id AND cc.categorie IN (" . implode(',', $in) . "))";
            }
            $parts[] = $p . ")";
        }
        if ($parts) $sql .= " AND (" . implode(' OR ', $parts) . ")";
    }
    if ($dist) $sql .= " AND c.lat IS NOT NULL HAVING distance_km <= :nkm";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Statistiques réelles sur 90 jours : [id => [i => impressions, c => visites]] et [id => inscriptions payées]. */
function sq_stats_camps(PDO $pdo, array $ids): array {
    $imp = []; $ins = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [$imp, $ins];
    $in = implode(',', $ids);
    try {
        foreach ($pdo->query("SELECT camp_id, SUM(impressions) i, SUM(clics) c FROM camp_stats_jour WHERE camp_id IN ($in) AND jour >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY camp_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $imp[(int)$r['camp_id']] = $r;
        foreach ($pdo->query("SELECT camp_id, COUNT(*) n FROM inscriptions WHERE camp_id IN ($in) AND statut_paiement = 'PAYE' AND statut NOT IN ('Annulé', 'annule', 'Annule') AND date_inscription >= DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY camp_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $ins[(int)$r['camp_id']] = (int)$r['n'];
    } catch (Throwable $e) { error_log('sq_stats_camps: ' . $e->getMessage()); }
    return [$imp, $ins];
}

/** Ajoute à chaque séjour ses mots-clés, ceux de l'organisme, ses catégories, ses réponses et ses statistiques (6 requêtes, quel que soit le nombre de séjours). */
function sq_enrichir(PDO $pdo, array $rows): array {
    if (!$rows) return $rows;
    $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
    $orgas = array_values(array_unique(array_map(function ($r) { return (int)$r['organisateur_id']; }, $rows)));
    $in = implode(',', $ids);
    $kc = mc_lire($pdo, 'camp', $ids); $ko = mc_lire($pdo, 'orga', $orgas);
    $cats = []; $facets = []; $imp = []; $ins = [];
    try {
        foreach ($pdo->query("SELECT camp_id, categorie FROM camp_categories WHERE camp_id IN ($in) AND categorie <> '_aucune' ORDER BY principale DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) $cats[(int)$r['camp_id']][] = $r['categorie'];
        foreach ($pdo->query("SELECT camp_id, categorie, facette, valeur FROM camp_facettes WHERE camp_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) $facets[(int)$r['camp_id']][] = [$r['categorie'], $r['facette'], $r['valeur']];
        [$imp, $ins] = sq_stats_camps($pdo, $ids);
    } catch (Throwable $e) { error_log('sq_enrichir: ' . $e->getMessage()); }
    foreach ($rows as &$r) {
        $id = (int)$r['id'];
        $r['kw_camp'] = $kc[$id] ?? []; $r['kw_orga'] = $ko[(int)$r['organisateur_id']] ?? [];
        $r['cats'] = $cats[$id] ?? []; $r['facets'] = $facets[$id] ?? [];
        $r['imp'] = (float)($imp[$id]['i'] ?? 0); $r['clics'] = (float)($imp[$id]['c'] ?? 0); $r['insc'] = (float)($ins[$id] ?? 0);
    }
    unset($r);
    return $rows;
}

/** Personnalisation légère d'un parent connecté : âges de ses enfants, catégories et organismes de ses favoris, réservations et séjours vus. */
function sq_profil(PDO $pdo, ?int $userId, array $vusIds = []): array {
    $p = ['ages' => [], 'cats' => [], 'orgas' => [], 'connu' => false];
    $ids = array_map('intval', $vusIds);
    try {
        if ($userId) {
            $st = $pdo->prepare("SELECT date_naissance FROM enfants WHERE parent_id = ?"); $st->execute([$userId]);
            $now = new DateTime();
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d) $p['ages'][] = (new DateTime($d))->diff($now)->y;
            $st = $pdo->prepare("SELECT camp_id FROM favoris WHERE user_id = ?"); $st->execute([$userId]);
            $ids = array_merge($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
            $st = $pdo->prepare("SELECT DISTINCT i.camp_id FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id WHERE e.parent_id = ?"); $st->execute([$userId]);
            $ids = array_merge($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
            $p['connu'] = true;
        }
        $ids = array_slice(array_values(array_unique(array_filter($ids))), 0, 100);
        if ($ids) {
            $in = implode(',', $ids);
            foreach ($pdo->query("SELECT DISTINCT categorie FROM camp_categories WHERE camp_id IN ($in) AND categorie <> '_aucune'")->fetchAll(PDO::FETCH_COLUMN) as $c) $p['cats'][$c] = 1;
            foreach ($pdo->query("SELECT DISTINCT organisateur_id FROM camps WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $o) $p['orgas'][(int)$o] = 1;
        }
    } catch (Throwable $e) { error_log('sq_profil: ' . $e->getMessage()); }
    return $p;
}

/**
 * Une recherche complète à partir des paramètres de l'adresse ($get). Retourne la réponse prête à être envoyée (sans les suivis ni le journal).
 * $ctx : userId, vus (identifiants de séjours vus), base_where / base_params (catalogue animateurs), now, sans_intentions
 */
function sq_run(PDO $pdo, array $get, array $ctx = []): array {
    cat_ensure($pdo); mc_ensure($pdo);
    if (function_exists('mk_ensure')) mk_ensure($pdo);
    cat_backfill($pdo);
    $catalogue = cat_load($pdo);
    $now = $ctx['now'] ?? time();
    $today = date('Y-m-d', $now);
    $query = sq_parse_query((string)($get['name'] ?? ''), $today);
    $sel = sq_selection($get, $catalogue);
    $day = function ($s) { $d = DateTime::createFromFormat('Y-m-d', (string)$s); return ($d && $d->format('Y-m-d') === $s) ? $s : null; };

    $f = ['today' => $today, 'city' => $get['city'] ?? '', 'max_price' => $get['max_price'] ?? null, 'age' => (isset($get['age']) && $get['age'] !== '' && is_numeric($get['age'])) ? (int)$get['age'] : null,
          'date_from' => $day($get['date_from'] ?? ''), 'date_to' => $day($get['date_to'] ?? ''), 'online' => !empty($get['online']),
          'sexe' => $ctx['sexe'] ?? null, 'themes' => (array)($get['themes'] ?? []), 'catalogue' => $catalogue];
    $la = filter_var($get['lat'] ?? '', FILTER_VALIDATE_FLOAT); $lo = filter_var($get['lng'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($la !== false && $lo !== false && abs($la) <= 90 && abs($lo) <= 180) {
        require_once __DIR__ . '/geocode.php';
        geo_ensure($pdo);
        $f['near'] = [round($la, 2), round($lo, 2), max(5, min(300, (int)($get['rayon'] ?? 50) ?: 50))];
        $f['city'] = '';
    }
    if (isset($ctx['base_where'])) { $f['base_where'] = $ctx['base_where']; $f['base_params'] = $ctx['base_params'] ?? [':today' => $today]; }
    $f0 = $f;
    // Intentions reconnues dans la phrase : appliquées seulement si le formulaire ne dit pas déjà autre chose
    $intents = $query['intents'];
    $useIntents = empty($ctx['sans_intentions']) && !isset($get['intents']);
    if ($useIntents) {
        if ($f['age'] === null && $query['age'] !== null) $f['age'] = $query['age'];
        if ($f['age'] === null && $query['age_range']) $f['age_range'] = $query['age_range'];
        if (empty($f['max_price']) && $query['max_price']) $f['max_price'] = $query['max_price'];
        if (!$f['date_from'] && !$f['date_to'] && $query['date_from']) { $f['date_from'] = $query['date_from']; $f['date_to'] = $query['date_to']; }
    } else $intents = [];
    $relaxed = false;
    $rows = sq_candidats($pdo, $f);
    if (!$rows && $intents) { $rows = sq_candidats($pdo, $f0); $relaxed = (bool)$rows; $intents = []; }
    $rows = sq_enrichir($pdo, $rows);

    $vocab = sq_vocab($pdo, $catalogue);
    $perso = sq_profil($pdo, $ctx['userId'] ?? null, $ctx['vus'] ?? []);
    $res = sq_search($rows, $query, $sel, ['catalogue' => $catalogue, 'vocab' => $vocab, 'now' => $now, 'perso' => $perso, 'sort' => $get['sort'] ?? 'relevance']);
    $res['query'] = $query; $res['sel'] = $sel; $res['intents'] = $intents; $res['relaxed'] = $relaxed; $res['catalogue'] = $catalogue; $res['perso'] = $perso;
    return $res;
}

// ---------------------------------------------------------------------------------------------
// Autocomplétion
// ---------------------------------------------------------------------------------------------
/**
 * Suggestions pour les premières lettres tapées : début de terme d'abord, début de mot ensuite, puis ressemblance (fautes). Sans base : le vocabulaire est en cache.
 * Retourne ['items' => [['label', 'kind', 'href'|null]], 'dym' => string|null].
 */
function sq_suggerer(array $vocab, string $raw, int $limit = 8): array {
    $n = sq_norm($raw);
    if (strlen($n) < 2) return ['items' => [], 'dym' => null];
    $c = [];
    foreach ($vocab['terms'] ?? [] as $norm => $t) {
        $norm = (string)$norm;
        if (strpos($norm, $n) === 0) $s = 10000 + $t[2];
        elseif (strpos(' ' . $norm, ' ' . $n) !== false) $s = 5000 + $t[2];
        else continue;
        $c[] = [$s, $t];
    }
    if (count($c) < 3 && strlen($n) >= 4 && strpos($n, ' ') === false) {
        $max = sq_fuzzy_max(strlen($n));
        foreach ($vocab['terms'] ?? [] as $norm => $t) {
            $norm = (string)$norm;
            if ($norm[0] !== $n[0] || strpos($norm, ' ') !== false) continue;
            $d = sq_dl($n, $norm, $max);
            if ($d >= 1 && $d <= $max) $c[] = [1000 - $d * 100 + $t[2], $t];
        }
    }
    usort($c, function ($a, $b) { return $b[0] <=> $a[0]; });
    $items = []; $seen = [];
    foreach ($c as [$s, $t]) {
        $k = sq_norm($t[0]);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $href = null;
        if (in_array($t[1], ['organisme', 'alias'], true) && $t[3]) $href = 'organisme/' . $t[3];
        elseif ($t[1] === 'catégorie' && $t[3]) $href = 'recherche?cat%5B%5D=' . rawurlencode($t[3]);
        $items[] = ['label' => $t[0], 'kind' => $t[1], 'href' => $href];
        if (count($items) >= $limit) break;
    }
    $dym = null;
    if (!$items || count($items) < 2) {
        $exp = sq_expand(sq_tokens($n, true), $vocab);
        $fix = array_filter(array_column($exp, 'typo', 't'));
        if ($fix) { $mots = []; foreach (explode(' ', $n) as $w) { $st = sq_stem($w); $mots[] = $fix[$st] ?? $w; } $dym = implode(' ', $mots); }
    }
    return ['items' => $items, 'dym' => $dym];
}
