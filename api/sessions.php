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
    $count = []; $min = []; $max = []; $prixMin = [];
    foreach ($universe as $r) {
        $k = sessionGroupKey($r);
        $count[$k] = ($count[$k] ?? 0) + 1;
        // « Dès X € » = le plus petit prix (tarifs rattachés compris) de TOUTES les sessions du séjour, pas celui de la session affichée
        $p = $r['prix_affiche'] ?? $r['prix'] ?? null;
        if ($p !== null && (float)$p > 0 && (!isset($prixMin[$k]) || (float)$p < $prixMin[$k])) $prixMin[$k] = (float)$p;
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
        $own = $r['prix_affiche'] ?? $r['prix'] ?? null;
        $r['prix_min'] = $prixMin[$k] ?? ($own !== null ? (float)$own : null);
        $r['date_debut_min'] = $min[$k] ?? ($r['date_debut'] ?? null);
        $r['date_fin_max']   = $max[$k] ?? ($r['date_fin'] ?? null);
        $out[] = $r;
    }
    return $out;
}

/**
 * Toutes les sessions publiques à venir (une ligne par session) : sert d'« univers » à collapseSessions pour que le nombre de sessions,
 * les dates et le prix « dès » d'une carte portent sur TOUTES les sessions du séjour, même quand une recherche n'en retient qu'une.
 */
function publicSessionsUniverse(PDO $pdo): array {
    $price = "COALESCE((SELECT MIN(t.prix) FROM camps_tarifs ct JOIN tarifs t ON ct.tarif_id = t.id WHERE ct.camp_id = c.id), c.prix)";
    $g = campsHaveGroupColumn($pdo) ? 'c.groupe_id' : 'NULL';
    try {
        return $pdo->query("SELECT c.id, $g AS groupe_id, c.date_debut, c.date_fin, $price AS prix_affiche FROM camps c
            WHERE c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND COALESCE(c.supprime, 0) = 0 AND COALESCE(c.prive, 0) = 0
              AND COALESCE(c.date_limite_inscription, c.date_debut) >= CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('publicSessionsUniverse: ' . $e->getMessage()); return []; }
}

/**
 * Dates d'une session, corrigées pour l'affichage : certains séjours importés ont un début situé
 * APRÈS la fin (année du début erronée). Dans ce cas le début prend l'année de la fin (ou la précédente).
 * @return DateTime[] [début, fin]
 */
function normalizeSessionDates($debut, $fin): array {
    try {
        $d1 = new DateTime($debut ?: 'now');
        $d2 = new DateTime($fin ?: ($debut ?: 'now'));
    } catch (Throwable $e) { $d1 = new DateTime(); $d2 = new DateTime(); }
    if ($d1 <= $d2) return [$d1, $d2];
    $c = DateTime::createFromFormat('!Y-n-j', $d2->format('Y') . '-' . $d1->format('n') . '-' . $d1->format('j'));
    if (!$c) $c = clone $d1;
    if ($c > $d2) $c->modify('-1 year');
    return [$c, $d2];
}
