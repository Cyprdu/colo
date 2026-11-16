<?php
// api/search_text.php : le texte de la recherche (fonctions pures, sans base de données, testables en ligne de commande).
//   sq_norm      : minuscules, sans accents, sans ponctuation   « École d'été » -> « ecole d ete »
//   sq_stem      : pluriel simple                               « chevaux » -> « cheval », « camps » -> « camp »
//   sq_tokens    : mots utiles (sans petits mots)               « le camp des chevaux » -> [camp, cheval]
//   sq_dl        : distance d'édition avec transposition        « chavel » -> « cheval » = 1
//   sq_syn_of    : synonymes d'un mot                           « poney » -> [cheval, equitation, ...]
//   sq_parse_query : reconnaît âge, budget et période dans la phrase tapée, et garde les mots restants

/** Minuscules, sans accents, tout ce qui n'est pas lettre ou chiffre devient un espace. */
function sq_norm(string $s): string {
    static $acc = ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss'];
    $s = strtr(mb_strtolower($s, 'UTF-8'), $acc);                       // le cas courant (français) : une seule passe rapide
    if (class_exists('Normalizer') && preg_match('/[^\x00-\x7F]/', $s)) {   // accents rares (polonais, turc...) : décomposition Unicode
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if ($n !== false) $s = preg_replace('/\p{Mn}+/u', '', $n);
    }
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** Petits mots ignorés partout */
const SQ_STOP = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'un', 'une', 'et', 'ou', 'en', 'au', 'aux', 'a', 'sur', 'pour', 'dans', 'avec', 'par', 'qui', 'que', 'ans', 'an', 'euro', 'euros', 'eur', 'ce', 'cet', 'cette', 'ses', 'son', 'sa', 'ma', 'mon', 'mes', 'je', 'il', 'elle', 's', 'n', 'j', 'c', 'y'];
/** Mots qui décrivent tout le site : tapés seuls ils ne disent rien, on ne les exige donc pas dans les séjours */
const SQ_GENERIC = ['colo', 'colonie', 'camp', 'sejour', 'vacance', 'stage', 'enfant', 'ado', 'adolescent', 'fils', 'fille', 'garcon', 'cherche', 'recherche', 'trouver', 'voudrais', 'veux', 'propose', 'organise'];

/** Pluriel simple, appliqué des deux côtés (recherche et séjours) : peu importe qu'il soit imparfait, il est le même. */
function sq_stem(string $w): string {
    $n = strlen($w);
    if ($n > 4 && substr($w, -3) === 'aux') return substr($w, 0, -3) . 'al';
    if ($n > 3 && ($w[$n - 1] === 's' || $w[$n - 1] === 'x')) return substr($w, 0, -1);
    return $w;
}

/** Mots utiles d'un texte déjà normalisé (racines, sans petits mots, sans mots d'une seule lettre). $sansGeneriques : retire aussi « colo », « camp »... */
function sq_tokens(string $norm, bool $sansGeneriques = false): array {
    static $gen = null, $stop = null;
    if ($gen === null) { $gen = array_fill_keys(array_map('sq_stem', SQ_GENERIC), true); $stop = array_flip(SQ_STOP); }
    $out = [];
    foreach (explode(' ', $norm) as $w) {
        if (strlen($w) < 2 || isset($stop[$w])) continue;
        $w = sq_stem($w);
        if ($sansGeneriques && isset($gen[$w])) continue;
        $out[$w] = true;
    }
    return array_keys($out);
}

/** Distance d'édition (Damerau-Levenshtein simplifiée) bornée : renvoie $max + 1 dès que la distance dépasse $max. Chaînes ASCII. */
function sq_dl(string $a, string $b, int $max = 2): int {
    $la = strlen($a); $lb = strlen($b);
    if (abs($la - $lb) > $max) return $max + 1;
    if ($la === 0) return $lb;
    if ($lb === 0) return $la;
    $prev2 = []; $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $cur = [$i]; $min = $i;
        for ($j = 1; $j <= $lb; $j++) {
            $v = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1));
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) $v = min($v, $prev2[$j - 2] + 1);
            $cur[$j] = $v;
            if ($v < $min) $min = $v;
        }
        if ($min > $max) return $max + 1;
        $prev2 = $prev; $prev = $cur;
    }
    return $prev[$lb];
}

/** Fautes tolérées selon la longueur du mot : aucune jusqu'à 3 lettres (« mer », « ski »), 1 jusqu'à 7, 2 au-delà. */
function sq_fuzzy_max(int $len): int { return $len <= 3 ? 0 : ($len <= 7 ? 1 : 2); }

/** Groupes de mots qui veulent dire à peu près la même chose pour une famille. À compléter au fil des recherches sans résultat. */
const SQ_SYN = [
    ['cheval', 'equitation', 'poney', 'equestre', 'galop'],
    ['ski', 'neige', 'snowboard', 'glisse', 'luge', 'raquette'],
    ['mer', 'plage', 'littoral', 'ocean', 'voile', 'nautique', 'catamaran', 'surf', 'paddle'],
    ['montagne', 'alpe', 'alpin', 'alpinisme', 'rando', 'randonnee', 'sommet', 'cime', 'trek'],
    ['spirituel', 'priere', 'spiritualite', 'religieux', 'foi', 'messe', 'chretien', 'retraite'],
    ['sport', 'sportif', 'multisport'],
    ['musique', 'musical', 'chant', 'chorale', 'instrument'],
    ['theatre', 'comedie', 'spectacle', 'scene', 'improvisation'],
    ['danse', 'dance', 'hiphop'],
    ['science', 'scientifique', 'technologie', 'robotique', 'numerique', 'code', 'programmation', 'informatique'],
    ['langue', 'linguistique', 'anglais', 'english', 'espagnol', 'allemand', 'immersion'],
    ['nature', 'foret', 'campagne', 'ferme', 'survie', 'bushcraft'],
    ['cirque', 'jonglage', 'clown'],
    ['cuisine', 'patisserie', 'gastronomie'],
    ['art', 'artistique', 'dessin', 'peinture', 'creatif', 'creation'],
    ['voyage', 'itinerant', 'itinerance', 'circuit'],
    ['scout', 'scoutisme'],
    ['video', 'cinema', 'film', 'tournage', 'audiovisuel'],
    ['jeu', 'ludique', 'escape'],
    ['velo', 'vtt', 'cyclo', 'cyclisme'],
    ['yoga', 'meditation', 'relaxation', 'zen', 'sophrologie'],
    ['solidaire', 'humanitaire', 'benevolat', 'chantier', 'solidarite'],
];

/** Synonymes d'une racine (hors elle-même). */
function sq_syn_of(string $stem): array {
    static $idx = null;
    if ($idx === null) {
        $idx = [];
        foreach (SQ_SYN as $g) {
            $g = array_map('sq_stem', $g);
            foreach ($g as $w) $idx[$w] = array_merge($idx[$w] ?? [], $g);
        }
    }
    return isset($idx[$stem]) ? array_values(array_diff(array_unique($idx[$stem]), [$stem])) : [];
}

// ---------------------------------------------------------------------------------------------
// Intentions dans la phrase tapée : âge, budget, période
// ---------------------------------------------------------------------------------------------
/** Période nommée -> [du, au] pour une année donnée de départ (null = prochaine occurrence à partir d'aujourd'hui). */
function sq_periode(string $nom, ?int $annee, string $today): ?array {
    $mois = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12];
    $nommees = ['ete' => [[7, 1], [8, 31]], 'toussaint' => [[10, 18], [11, 3]], 'noel' => [[12, 18], [1, 5]], 'paques' => [[4, 1], [5, 5]], 'printemps' => [[4, 1], [5, 5]], 'hiver' => [[12, 15], [3, 15]]];
    if (isset($nommees[$nom])) { [$a, $b] = $nommees[$nom]; }
    elseif (isset($mois[$nom])) { $m = $mois[$nom]; $a = [$m, 1]; $b = [$m, (int)date('t', mktime(0, 0, 0, $m, 1, 2001))]; if ($m === 2) $b = [2, 28]; }
    else return null;
    $y = $annee ?? (int)substr($today, 0, 4);
    for ($i = 0; $i < 3; $i++, $y++) {
        $from = sprintf('%04d-%02d-%02d', $y, $a[0], $a[1]);
        $to = sprintf('%04d-%02d-%02d', $b[0] < $a[0] ? $y + 1 : $y, $b[0], $b[1]);
        if ($annee !== null || $to >= $today) return [$from, $to];
    }
    return null;
}

/**
 * Analyse la phrase tapée par la famille.
 * Retourne : raw, norm, text (ce qui reste après les intentions), tokens (racines utiles), age, age_range, max_price, date_from, date_to,
 * intents (pour l'affichage : « Nous avons compris : 12 ans, moins de 500 € »).
 */
function sq_parse_query(string $raw, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $raw = mb_substr($raw, 0, 200);
    $s = str_replace(['€', '£'], ' euros ', mb_strtolower($raw, 'UTF-8'));
    $s = preg_replace('/[<≤]/u', ' moins de ', $s);
    $n = sq_norm($s);
    $q = ['raw' => $raw, 'norm' => $n, 'text' => $n, 'tokens' => [], 'age' => null, 'age_range' => null, 'max_price' => null, 'date_from' => null, 'date_to' => null, 'intents' => []];
    $text = ' ' . $n . ' ';

    // Âge : « 12 ans », « 12 à 14 ans », « moins de 12 ans »
    if (preg_match('/ (\d{1,2}) (?:a |et )?(\d{1,2}) ans? /', $text, $m) && (int)$m[1] < (int)$m[2] && (int)$m[2] <= 25) {
        $q['age_range'] = [max(3, (int)$m[1]), (int)$m[2]];
        $q['intents'][] = ['type' => 'age', 'label' => (int)$m[1] . ' à ' . (int)$m[2] . ' ans'];
        $text = str_replace($m[0], ' ', $text);
    } elseif (preg_match('/ (?:(moins de|plus de|a partir de) )?(\d{1,2}) ans? /', $text, $m) && (int)$m[2] >= 3 && (int)$m[2] <= 25) {
        $a = (int)$m[2] + ($m[1] === 'moins de' ? -1 : ($m[1] === 'plus de' ? 1 : 0));
        $q['age'] = $a;
        $q['intents'][] = ['type' => 'age', 'label' => $a . ' ans'];
        $text = str_replace($m[0], ' ', $text);
    }
    // Budget : « moins de 500 euros », « max 300 », « 400 € »
    if (preg_match('/ (?:moins de|max|maximum|jusqu a|inferieur a|budget(?: de| max)?|pas plus de) (\d{2,5})(?: euros?| eur)? /', $text, $m) || preg_match('/ (\d{2,5}) (?:euros?|eur) /', $text, $m)) {
        $q['max_price'] = (float)$m[1];
        $q['intents'][] = ['type' => 'prix', 'label' => 'moins de ' . (int)$m[1] . ' €'];
        $text = str_replace($m[0], ' ', $text);
    }
    // Période : « été 2027 », « vacances de Noël », « juillet »
    if (preg_match('/ (?:vacances (?:de |d |du |des )?)?(?:l )?(ete|juillet|aout|noel|toussaint|paques|printemps|hiver|janvier|fevrier|mars|avril|mai|juin|septembre|octobre|novembre|decembre)(?: (20\d\d))? /', $text, $m)) {
        $p = sq_periode($m[1], !empty($m[2]) ? (int)$m[2] : null, $today);
        if ($p) {
            [$q['date_from'], $q['date_to']] = $p;
            $label = ['ete' => 'été', 'aout' => 'août', 'noel' => 'Noël', 'paques' => 'Pâques', 'fevrier' => 'février', 'decembre' => 'décembre'][$m[1]] ?? $m[1];
            $q['intents'][] = ['type' => 'periode', 'label' => $label . ' ' . substr($p[0], 0, 4)];
            $text = str_replace($m[0], ' ', $text);
        }
    }
    $q['text'] = trim(preg_replace('/\s+/', ' ', $text));
    $q['tokens'] = sq_tokens($q['text'], true);
    return $q;
}
