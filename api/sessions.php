<?php
// api/sessions.php : regroupement des séjours en « camps à sessions multiples ».
//
// Une session = une ligne de la table `camps` (ses propres dates, prix, places, réservations).
// Les sessions d'un même camp partagent la même valeur `groupe_id` (= id de la première session).
// Un camp sans `groupe_id` forme son propre groupe (une seule session).
//
// Tout est écrit pour fonctionner AVANT la migration (colonne absente) : dans ce cas,
// chaque camp reste simplement son propre groupe.

/** La colonne groupe_id existe-t-elle ? (résultat mis en cache pour la requête) */
function campsHaveGroupColumn(PDO $pdo): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)$pdo->query("SHOW COLUMNS FROM camps LIKE 'groupe_id'")->fetch(); }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** Clé de groupe d'une ligne camp */
function sessionGroupKey(array $row): string {
    return !empty($row['groupe_id']) ? 'g' . (int)$row['groupe_id'] : 'c' . (int)$row['id'];
}

/** Nom normalisé (sans accents, minuscules, espaces simples) : sert à repérer les doublons à regrouper */
function normalizeCampName(?string $s): string {
    $s = mb_strtolower(trim((string)$s));
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_D);
    $s = preg_replace('/\p{Mn}+/u', '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * Réduit une liste de camps à UNE ligne par groupe (la première rencontrée dans $list).
 * Ajoute à chaque ligne retenue : sessions_count (nombre de sessions dans $universe),
 * date_debut_min et date_fin_max.
 *
 * @param array      $list      lignes triées comme on veut les afficher
 * @param array|null $universe  lignes servant à compter les sessions (par défaut : $list)
 */
function collapseSessions(array $list, ?array $universe = null): array {
    $universe = $universe ?? $list;
    $count = []; $min = []; $max = [];
    foreach ($universe as $r) {
        $k = sessionGroupKey($r);
        $count[$k] = ($count[$k] ?? 0) + 1;
        $d1 = $r['date_debut'] ?? null; $d2 = $r['date_fin'] ?? $d1;
        if ($d1 && (!isset($min[$k]) || $d1 < $min[$k])) $min[$k] = $d1;
        if ($d2 && (!isset($max[$k]) || $d2 > $max[$k])) $max[$k] = $d2;
    }
    $seen = []; $out = [];
    foreach ($list as $r) {
        $k = sessionGroupKey($r);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $r['sessions_count'] = $count[$k] ?? 1;
        $r['date_debut_min'] = $min[$k] ?? ($r['date_debut'] ?? null);
        $r['date_fin_max']   = $max[$k] ?? ($r['date_fin'] ?? null);
        $out[] = $r;
    }
    return $out;
}
