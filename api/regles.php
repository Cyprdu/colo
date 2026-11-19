<?php
// api/regles.php : règles d'admission d'un séjour (colonnes et tables créées à la première utilisation, idempotent).
//  - camps.age_mode : 'age' (défaut, historique) | 'tranche' | 'les2' ; camps.tranches : codes de TRANCHES séparés par des virgules.
//  - inscriptions.tranche : tranche déclarée par la famille pour l'enfant inscrit.
//  - camps_tarifs.regles : règles d'un tarif pour une session (JSON, voir regles_tarif_lire ; NULL = aucune).
//  - inscription_participants : participants d'une inscription à un tarif « à plusieurs » (rôles), le payeur inscrit compris ;
//    une inscription sans ligne compte un seul participant (son enfant). Le prix reste porté par l'inscription.

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
        $cols = $pdo->query("SHOW COLUMNS FROM camps_tarifs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('regles', $cols, true)) $pdo->exec("ALTER TABLE camps_tarifs ADD COLUMN regles TEXT NULL");
        $cols = $pdo->query("SHOW COLUMNS FROM inscriptions")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tranche', $cols, true)) $pdo->exec("ALTER TABLE inscriptions ADD COLUMN tranche VARCHAR(12) NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS inscription_participants (
            id INT AUTO_INCREMENT PRIMARY KEY, inscription_id INT NOT NULL, enfant_id INT NOT NULL, role VARCHAR(40) NOT NULL, tranche VARCHAR(12) NULL,
            position INT NOT NULL DEFAULT 0, INDEX idx_ip_ins (inscription_id), INDEX idx_ip_enf (enfant_id)
        ) DEFAULT CHARSET=utf8mb4");
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

/** La session (ou l'un de ses tarifs) demande-t-elle la tranche de chaque enfant ? */
function regles_besoin_tranche(array $camp, array $reglesTarifs = []): bool {
    if (age_mode($camp) !== 'age') return true;
    foreach ($reglesTarifs as $r) {
        if (!empty($r['tranches'])) return true;
        foreach ($r['roles'] ?? [] as $x) if (!empty($x['tranches'])) return true;
    }
    return false;
}

/**
 * Règles d'un tarif pour une session (camps_tarifs.regles, JSON ; vide = aucune règle) :
 * quota (places à ce tarif), quota_f / quota_g (dont filles / garçons), age_min / age_max, tranches.
 * Retourne les règles nettoyées, ou un message d'erreur.
 */
function regles_tarif_lire($raw, int $qGlobal, string $nom): array|string {
    $r = is_array($raw) ? $raw : [];
    $n = fn($k, $max) => isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null && is_numeric($r[$k]) ? max(0, min($max, (int)$r[$k])) : null;
    $out = [];
    foreach (['quota', 'quota_f', 'quota_g'] as $k) if ($n($k, 5000)) $out[$k] = $n($k, 5000);
    $lim = $out['quota'] ?? $qGlobal;
    if ($lim > 0 && (($out['quota_f'] ?? 0) > $lim || ($out['quota_g'] ?? 0) > $lim)) return "Tarif « $nom » : les places filles ou garçons dépassent ses places.";
    if (($out['quota'] ?? 0) > 0 && $qGlobal > 0 && $out['quota'] > $qGlobal) return "Tarif « $nom » : plus de places que le séjour.";
    $amin = $n('age_min', 99); $amax = $n('age_max', 99);
    if ($amin !== null || $amax !== null) {
        $out['age_min'] = $amin ?? 0; $out['age_max'] = $amax ?? 99;
        if ($out['age_max'] < $out['age_min']) return "Tarif « $nom » : l'âge maximum doit suivre l'âge minimum.";
    }
    if ($tr = tranches_valides($r['tranches'] ?? [])) $out['tranches'] = $tr;
    // Inscription à plusieurs (père + fils, prix pour deux…) : un rôle par participant, chacun avec ses conditions facultatives
    $roles = [];
    foreach (array_slice(is_array($r['roles'] ?? null) ? $r['roles'] : [], 0, 6) as $x) {
        $nomR = mb_substr(trim((string)($x['nom'] ?? '')), 0, 40);
        if ($nomR === '' || !is_array($x)) continue;
        $c = regles_tarif_lire(array_intersect_key($x, array_flip(['age_min', 'age_max', 'tranches'])), 0, "$nom » / « $nomR");
        if (is_string($c)) return $c;
        $roles[] = ['nom' => $nomR] + $c + (in_array($x['sexe'] ?? '', ['Homme', 'Femme'], true) ? ['sexe' => $x['sexe']] : []);
    }
    if (count($roles) === 1) return "Tarif « $nom » : une inscription à plusieurs demande au moins 2 rôles.";
    if ($roles) $out['roles'] = $roles;
    return $out;
}

/** Critère d'un rôle : ses propres conditions s'il en a, sinon celles de la session */
function regles_crit_role(array $role, array $camp): array {
    return isset($role['age_min']) || !empty($role['tranches']) ? regles_crit($role) : regles_crit_camp($camp);
}

/** [id tarif => règles] d'une session */
function regles_tarifs_du_camp(PDO $pdo, int $campId): array {
    $st = $pdo->prepare("SELECT tarif_id, regles FROM camps_tarifs WHERE camp_id = ?");
    $st->execute([$campId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $j) $out[(int)$id] = is_array($d = json_decode((string)$j, true)) ? $d : [];
    return $out;
}

/** Critère d'âge d'un tarif (ou d'un rôle) : seules les conditions renseignées s'appliquent */
function regles_crit(array $r): array {
    return ['mode' => 'les2', 'age_min' => $r['age_min'] ?? null, 'age_max' => $r['age_max'] ?? null, 'tranches' => $r['tranches'] ?? []];
}

/** Places occupées d'une session : une ligne [tarif_id, enfant_id, sexe] par personne inscrite (inscription active, participants compris). */
function places_occupees(PDO $pdo, array $camp): array {
    require_once __DIR__ . '/attente.php';
    $st = $pdo->prepare("SELECT i.tarif_id, COALESCE(p.enfant_id, i.enfant_id) enfant_id, COALESCE(pe.sexe, e.sexe) sexe
                         FROM (SELECT id, tarif_id, enfant_id FROM inscriptions WHERE camp_id = ? AND " . attente_clause($camp) . ") i
                         LEFT JOIN inscription_participants p ON p.inscription_id = i.id LEFT JOIN enfants pe ON pe.id = p.enfant_id
                         LEFT JOIN enfants e ON e.id = i.enfant_id");
    $st->execute([$camp['id']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Places restantes : ['total' => int|null, 'f' => int|null, 'g' => int|null] (null = pas de limite) pour la session ($tarif null) ou un tarif. */
function places_restantes(array $camp, array $regles, array $occupees, ?int $tarif = null): array {
    $q = $tarif === null ? ['quota' => (int)($camp['quota_global'] ?? 0), 'quota_f' => (int)($camp['quota_fille'] ?? 0), 'quota_g' => (int)($camp['quota_garcon'] ?? 0)] : ($regles[$tarif] ?? []);
    $rows = $tarif === null ? $occupees : array_filter($occupees, fn($o) => (int)$o['tarif_id'] === $tarif);
    $c = fn($sexe) => count(array_filter($rows, fn($o) => $o['sexe'] === $sexe));
    $rest = fn($k, $n) => (int)($q[$k] ?? 0) > 0 ? max(0, (int)$q[$k] - $n) : null;
    return ['total' => $rest('quota', count($rows)), 'f' => $rest('quota_f', $c('Femme')), 'g' => $rest('quota_g', $c('Homme'))];
}

/**
 * Contrôle des places d'une commande, pour la session puis pour chaque tarif (quota et filles / garçons).
 * $demandes : une ligne ['tarif_id' => int|null, 'sexe' => ?string] par personne à inscrire ; $noms : [id tarif => nom].
 */
function places_probleme(array $camp, array $regles, array $occupees, array $demandes, array $noms = []): ?string {
    $plur = fn($n) => $n > 1 ? 's' : '';
    foreach (array_merge([null], array_values(array_unique(array_filter(array_map(fn($d) => $d['tarif_id'] ? (int)$d['tarif_id'] : null, $demandes))))) as $t) {
        $mine = $t === null ? $demandes : array_filter($demandes, fn($d) => (int)$d['tarif_id'] === $t);
        $r = places_restantes($camp, $regles, $occupees, $t);
        $ou = $t === null ? 'ce séjour' : 'le tarif « ' . ($noms[$t] ?? '') . ' »';
        if ($r['total'] !== null && count($mine) > $r['total']) return $r['total'] === 0 ? ($t === null ? 'Ce séjour est complet.' : 'Le tarif « ' . ($noms[$t] ?? '') . ' » est complet.') : 'Il ne reste que ' . $r['total'] . ' place' . $plur($r['total']) . ' pour ' . $ou . '.';
        foreach (['f' => ['Femme', 'les filles'], 'g' => ['Homme', 'les garçons']] as $k => [$sexe, $lib]) {
            $n = count(array_filter($mine, fn($d) => ($d['sexe'] ?? null) === $sexe));
            if ($n > 0 && $r[$k] !== null && $n > $r[$k]) return "Il n'y a plus assez de places pour $lib sur $ou.";
        }
    }
    return null;
}
