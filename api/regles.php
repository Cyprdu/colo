<?php
// api/regles.php : règles d'admission d'un séjour (colonnes et tables créées à la première utilisation, idempotent).
//  - camps.age_mode : 'age' (défaut, historique) | 'tranche' | 'les2' ; camps.tranches : codes de TRANCHES séparés par des virgules.
//  - inscriptions.tranche : tranche déclarée par la famille pour l'enfant inscrit.

/** code => [libellé, âge minimum indicatif, âge maximum indicatif] (les bornes servent à la recherche par âge et à pré-remplir le choix) */
const TRANCHES = [
    'maternelle' => ['Maternelle', 3, 6], 'primaire' => ['École primaire', 6, 11], 'college' => ['Collège', 11, 15], 'lycee' => ['Lycée', 14, 19],
    'etudes' => ['Études supérieures', 17, 30], 'jeune_pro' => ['Jeune actif', 18, 35], 'adulte' => ['Adulte', 18, 99],
];
const AGE_MODES = ['age' => 'Âge exact', 'tranche' => 'Tranches (collège, lycée…)', 'les2' => 'Les deux'];

function regles_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM camps")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('age_mode', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN age_mode VARCHAR(8) NULL");
        if (!in_array('tranches', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN tranches VARCHAR(120) NULL");
        $cols = $pdo->query("SHOW COLUMNS FROM inscriptions")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tranche', $cols, true)) $pdo->exec("ALTER TABLE inscriptions ADD COLUMN tranche VARCHAR(12) NULL");
    } catch (Throwable $e) { error_log('regles_ensure: ' . $e->getMessage()); }
    $done = true;
}

function tranches_valides($v): array {
    $l = is_array($v) ? $v : explode(',', (string)$v);
    return array_values(array_intersect(array_keys(TRANCHES), array_map('trim', $l)));
}

function age_mode(array $camp): string {
    return isset(AGE_MODES[$camp['age_mode'] ?? '']) ? $camp['age_mode'] : 'age';
}

/** Tranche la plus probable pour un âge (pré-remplissage seulement : la famille choisit) */
function tranche_par_age(?int $age): string {
    if ($age === null) return '';
    foreach (['maternelle' => 5, 'primaire' => 10, 'college' => 14, 'lycee' => 17, 'etudes' => 22] as $k => $max) if ($age <= $max) return $k;
    return 'adulte';
}

function age_au(?string $naissance, ?string $ref): ?int {
    if (!$naissance) return null;
    try { return (new DateTime($naissance))->diff(new DateTime($ref ?: 'now'))->y; } catch (Throwable $e) { return null; }
}

function tranches_libelles(array $codes): string {
    return implode(', ', array_map(fn($k) => TRANCHES[$k][0], tranches_valides($codes)));
}

/**
 * Lecture du critère d'âge du formulaire de séjour : [mode, tranches, âge min, âge max, erreur].
 * En mode « tranches », les âges sont déduits des tranches (la recherche par âge continue de fonctionner).
 */
function regles_age_lire(array $in): array {
    $mode = isset(AGE_MODES[$in['age_mode'] ?? '']) ? $in['age_mode'] : 'age';
    $tr = $mode === 'age' ? [] : tranches_valides($in['tranches'] ?? []);
    $min = max(0, (int)($in['age_min'] ?? 0)); $max = max(0, (int)($in['age_max'] ?? 0));
    if ($mode !== 'age' && !$tr) return [$mode, [], $min, $max, 'Cochez au moins une tranche (collège, lycée…).'];
    if ($mode === 'tranche') { $min = min(array_map(fn($k) => TRANCHES[$k][1], $tr)); $max = max(array_map(fn($k) => TRANCHES[$k][2], $tr)); }
    if ($max < $min) return [$mode, $tr, $min, $max, "L'âge maximum doit être supérieur ou égal à l'âge minimum."];
    return [$mode, $tr, $min, $max, null];
}

/**
 * Critère d'admission ['mode' => age|tranche|les2, 'age_min', 'age_max', 'tranches'] appliqué à une personne : message, ou null si elle est admise.
 * $age : âge au premier jour de la session ; $qui : prénom (pour le message).
 */
function admission_probleme(array $crit, ?int $age, ?string $tranche, string $qui, string $quoi = 'Cette session'): ?string {
    $mode = $crit['mode'] ?? 'age';
    if ($mode !== 'tranche' && $age !== null && isset($crit['age_min'], $crit['age_max']) && $crit['age_max'] !== null && ($age < (int)$crit['age_min'] || $age > (int)$crit['age_max'])) {
        return $quoi . ' accueille les ' . (int)$crit['age_min'] . ' à ' . (int)$crit['age_max'] . ' ans (' . $qui . ' aura ' . $age . ' an' . ($age > 1 ? 's' : '') . ' au départ).';
    }
    $tr = tranches_valides($crit['tranches'] ?? []);
    if ($mode !== 'age' && $tr && !in_array((string)$tranche, $tr, true)) {
        return $quoi . ' accueille : ' . tranches_libelles($tr) . ($tranche ? ' (' . $qui . ' : ' . TRANCHES[$tranche][0] . ').' : '. Indiquez la tranche de ' . $qui . '.');
    }
    return null;
}

/** Critère d'âge d'une session de séjour */
function regles_crit_camp(array $camp): array {
    return ['mode' => age_mode($camp), 'age_min' => $camp['age_min'] ?? null, 'age_max' => $camp['age_max'] ?? null, 'tranches' => tranches_valides($camp['tranches'] ?? '')];
}

/** La session demande-t-elle la tranche de chaque enfant ? */
function regles_besoin_tranche(array $camp): bool {
    return age_mode($camp) !== 'age';
}
