<?php
// api/marketing_lib.php : marketing / boosts (points).
//  - tables créées automatiquement (idempotent) : camp_stats_jour, boost_achats, points_mouvements
//  - journalisation des impressions et des clics (compteurs agrégés par jour : légers, sans donnée personnelle)
//  - score de classement partagé par la recherche et l'accueil
//  - statistiques par séjour (impressions, clics, inscriptions, coût par inscription)
//  - toute opération sur les points passe par ici (un seul endroit, transactions, idempotence)

const MK_POINT_EUR = 0.10; // valeur d'un point à l'achat de base (pack Découverte : 100 pts = 10 €)

/** Packs de points : la source unique des prix (jamais un montant venu du navigateur) */
function mk_packs(): array {
    return [
        'decouverte' => ['name' => 'Pack Découverte', 'points' => 100,  'cents' => 1000],
        'standard'   => ['name' => 'Pack Standard',   'points' => 600,  'cents' => 5000],
        'agence'     => ['name' => 'Pack Agence',     'points' => 1500, 'cents' => 10000],
    ];
}

/** Types de boost : coût, durée (jours, 0 = instantané), libellé */
function mk_boosts(): array {
    return [
        'bump'    => ['label' => 'Remontée immédiate', 'cost' => 10,  'days' => 0],
        'vedette' => ['label' => 'Mise à la une',      'cost' => 100, 'days' => 7],
        'urgence' => ['label' => 'Dernière minute',    'cost' => 50,  'days' => 3],
    ];
}

function mk_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS camp_stats_jour (
            camp_id INT NOT NULL, jour DATE NOT NULL, surface VARCHAR(12) NOT NULL,
            impressions INT NOT NULL DEFAULT 0, clics INT NOT NULL DEFAULT 0,
            PRIMARY KEY (camp_id, jour, surface), KEY idx_jour (jour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS boost_achats (
            id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, organisateur_id INT NOT NULL,
            type VARCHAR(12) NOT NULL, points INT NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_camp (camp_id), KEY idx_orga (organisateur_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Journal des points : achats (carte ou chiffre d'affaires), dépenses de boost. ref unique = idempotence des crédits.
        $pdo->exec("CREATE TABLE IF NOT EXISTS points_mouvements (
            id INT AUTO_INCREMENT PRIMARY KEY, organisateur_id INT NOT NULL, delta INT NOT NULL,
            motif VARCHAR(24) NOT NULL, source VARCHAR(14) NULL, montant_cents INT NULL, camp_id INT NULL,
            ref VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ref (ref), KEY idx_orga (organisateur_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('mk_ensure: ' . $e->getMessage());
    }
    $done = true;
}

// ---------------------------------------------------------------------------------------------
// Journalisation
// ---------------------------------------------------------------------------------------------
function mk_is_bot(): bool {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return true;
    return (bool)preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse/', $ua);
}

/** Enregistre une impression ou un clic ($kind = 'impression'|'clic') pour une liste de séjours. Ne lève jamais d'erreur. */
function mk_track(PDO $pdo, array $campIds, string $kind, string $surface): void {
    try {
        if (!in_array($kind, ['impression', 'clic'], true) || mk_is_bot()) return;
        $surface = preg_replace('/[^a-z_]/', '', strtolower($surface)) ?: 'autre';
        $ids = array_values(array_unique(array_filter(array_map('intval', $campIds))));
        if (!$ids) return;
        mk_ensure($pdo);
        $col = $kind === 'clic' ? 'clics' : 'impressions';
        $st = $pdo->prepare("INSERT INTO camp_stats_jour (camp_id, jour, surface, $col) VALUES (?, CURDATE(), ?, 1)
                             ON DUPLICATE KEY UPDATE $col = $col + 1");
        foreach (array_slice($ids, 0, 60) as $id) $st->execute([$id, substr($surface, 0, 12)]);
    } catch (Throwable $e) {
        error_log('mk_track: ' . $e->getMessage());
    }
}

/** Surface d'où vient un visiteur (d'après le Referer) : recherche, accueil ou autre */
function mk_surface_from_referer(): string {
    $path = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $path = strtolower(str_replace('.php', '', $path));
    if (strpos($path, 'recherche') !== false) return 'recherche';
    if ($path === '' || $path === '/' || strpos($path, 'index') !== false) return 'accueil';
    return 'autre';
}

// ---------------------------------------------------------------------------------------------
// Classement : un score, pas un simple tri
// ---------------------------------------------------------------------------------------------
/**
 * Score final d'un séjour = score organique x effet des boosts.
 *   organique : pertinence de la requête, disponibilité, proximité du départ, taux de clic réel, nouveauté
 *   vedette   : x1,55 pendant 7 jours (un coup de pouce, qui ne remplace pas la pertinence)
 *   urgence   : x1,20 si le départ a lieu dans les 45 jours
 *   bump      : x(1 + 0,5 . e^(-heures/24)) : fort tout de suite, s'estompe en ~3 jours (effet mesurable, pas permanent)
 *   rotation  : +-3 % qui change toutes les 3 heures, pour que les mêmes séjours ne monopolisent pas la tête de liste
 * Les boosts n'effacent jamais les filtres : seuls les séjours déjà retenus sont classés.
 *
 * $c : ligne camp avec (facultatif) relevance_score (0-100), nb_places_restantes, ctr30, date_creation, date_bump, boost_*_fin
 * Retourne ['score' => float, 'organique' => float, 'boost_factor' => float, 'sponsorise' => bool]
 */
function mk_score(array $c, ?string $nowDt = null): array {
    $now = $nowDt ? strtotime($nowDt) : time();
    $rel = min(100.0, max(0.0, (float)($c['relevance_score'] ?? 0)));
    $base = 40.0 + $rel * 0.6;

    $days = isset($c['date_debut']) ? (strtotime($c['date_debut']) - $now) / 86400 : 60;
    if ($days >= 0 && $days <= 60) $base += 10 * (1 - $days / 60);          // départ proche : utile aux familles
    if (isset($c['nb_places_restantes'])) {
        $p = (int)$c['nb_places_restantes'];
        if ($p <= 0 && !empty($c['quota_global'])) $base *= 0.35;           // complet : très bas
        elseif ($p > 0 && $p <= 5) $base += 3;
    }
    $base += min(15.0, max(0.0, (float)($c['ctr30'] ?? 0)) * 150);          // 10 % de clic = +15
    if (!empty($c['date_creation']) && strtotime($c['date_creation']) > $now - 21 * 86400) $base += 5;

    $vedette = !empty($c['boost_vedette_fin']) && strtotime($c['boost_vedette_fin']) > $now;
    $urgence = !empty($c['boost_urgence_fin']) && strtotime($c['boost_urgence_fin']) > $now && $days >= 0 && $days <= 45;
    $m = 1.0;
    if ($vedette) $m *= 1.55;
    if ($urgence) $m *= 1.20;
    if (!empty($c['date_bump'])) {
        $h = ($now - strtotime($c['date_bump'])) / 3600;
        if ($h >= 0 && $h <= 72) $m *= 1 + 0.5 * exp(-$h / 24);
    }
    $seed = crc32(((int)($c['id'] ?? 0)) . '|' . date('Ymd', $now) . '|' . intdiv((int)date('G', $now), 3));
    $jitter = 1 + (($seed % 61) - 30) / 1000;                                // -3 % .. +3 %

    return ['score' => $base * $m * $jitter, 'organique' => $base, 'boost_factor' => $m, 'sponsorise' => $vedette];
}

/** Taux de clic (30 jours) par séjour, lissé : (clics + 1) / (impressions + 20). */
function mk_ctr_map(PDO $pdo, array $campIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $campIds))));
    if (!$ids) return [];
    try {
        mk_ensure($pdo);
        $in = implode(',', $ids);
        $rows = $pdo->query("SELECT camp_id, SUM(impressions) i, SUM(clics) c FROM camp_stats_jour
                             WHERE jour >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND camp_id IN ($in) GROUP BY camp_id")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int)$r['camp_id']] = ((float)$r['c'] + 1) / ((float)$r['i'] + 20);
        return $out;
    } catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------------------------------------
// Points : toutes les écritures passent ici
// ---------------------------------------------------------------------------------------------
/** Crédite des points une seule fois par $ref (paiement Stripe, achat sur CA...). Retourne true si crédité maintenant. À appeler dans une transaction ouverte. */
function mk_credit_points(PDO $pdo, int $orgaId, int $points, string $source, ?int $cents, string $ref): bool {
    mk_ensure($pdo);
    $st = $pdo->prepare("INSERT IGNORE INTO points_mouvements (organisateur_id, delta, motif, source, montant_cents, ref) VALUES (?, ?, 'achat', ?, ?, ?)");
    $st->execute([$orgaId, $points, $source, $cents, $ref]);
    if ($st->rowCount() !== 1) return false;
    $pdo->prepare("UPDATE organisateurs SET solde_points = COALESCE(solde_points, 0) + ? WHERE id = ?")->execute([$points, $orgaId]);
    return true;
}

/**
 * Active un boost sur un séjour en débitant les points de l'organisme (solde verrouillé : pas de double dépense).
 * Retourne ['fin' => 'Y-m-d H:i:s'|null]. Lève une Exception avec un message affichable.
 */
function mk_apply_boost(PDO $pdo, int $userId, string $campToken, string $type): array {
    $boosts = mk_boosts();
    if (!isset($boosts[$type])) throw new Exception('Type de boost inconnu.');
    $b = $boosts[$type];
    mk_ensure($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT c.id camp_id, c.valide, c.refuse, c.supprime, c.date_debut, o.id orga_id, o.solde_points
                             FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id
                             WHERE c.token = ? AND o.user_id = ? FOR UPDATE");
        $st->execute([$campToken, $userId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) throw new Exception('Séjour introuvable.');
        if (empty($d['valide']) || !empty($d['refuse']) || !empty($d['supprime'])) throw new Exception("Ce séjour n'est pas publié : il ne peut pas être boosté.");
        if ($d['date_debut'] && strtotime($d['date_debut']) < strtotime('today')) throw new Exception('Ce séjour a déjà commencé.');
        if ((int)$d['solde_points'] < $b['cost']) throw new Exception('Solde insuffisant (' . (int)$d['solde_points'] . ' pts disponibles).');

        $pdo->prepare("UPDATE organisateurs SET solde_points = solde_points - ? WHERE id = ?")->execute([$b['cost'], $d['orga_id']]);
        $fin = null;
        if ($type === 'bump') {
            $pdo->prepare("UPDATE camps SET date_bump = NOW() WHERE id = ?")->execute([$d['camp_id']]);
        } else {
            $col = $type === 'vedette' ? 'boost_vedette_fin' : 'boost_urgence_fin';
            $pdo->prepare("UPDATE camps SET $col = DATE_ADD(GREATEST(NOW(), COALESCE($col, NOW())), INTERVAL ? DAY) WHERE id = ?")->execute([$b['days'], $d['camp_id']]);
            $fin = $pdo->query("SELECT $col FROM camps WHERE id = " . (int)$d['camp_id'])->fetchColumn();
        }
        $duree = $b['days'] > 0 ? $b['days'] * 86400 : 72 * 3600; // un bump pèse ~3 jours (voir mk_score)
        $debut = date('Y-m-d H:i:s');
        $finBoost = $fin ?: date('Y-m-d H:i:s', time() + $duree);
        $pdo->prepare("INSERT INTO boost_achats (camp_id, organisateur_id, type, points, debut, fin) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$d['camp_id'], $d['orga_id'], $type, $b['cost'], $debut, $finBoost]);
        $pdo->prepare("INSERT INTO points_mouvements (organisateur_id, delta, motif, source, camp_id) VALUES (?, ?, ?, 'boost', ?)")
            ->execute([$d['orga_id'], -$b['cost'], 'boost_' . $type, $d['camp_id']]);
        $pdo->commit();
        return ['fin' => $fin];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------------------------
// Statistiques par séjour
// ---------------------------------------------------------------------------------------------
/**
 * Pour chaque séjour d'un organisme, sur $days jours :
 *  impressions, clics, ctr, inscriptions (toutes), inscriptions pendant un boost, points dépensés,
 *  coût par inscription (en €, sur les inscriptions obtenues pendant un boost), boost actifs.
 */
function mk_camp_stats(PDO $pdo, int $orgaId, int $days = 30): array {
    mk_ensure($pdo);
    $days = max(1, min(365, $days));
    $st = $pdo->prepare("SELECT id, token, nom, ville, date_debut, date_fin, quota_global, image_url, date_bump, boost_vedette_fin, boost_urgence_fin, valide, vues
                         FROM camps WHERE organisateur_id = ? AND COALESCE(supprime, 0) = 0 ORDER BY date_debut DESC");
    $st->execute([$orgaId]);
    $camps = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$camps) return [];
    $ids = array_map(function ($c) { return (int)$c['id']; }, $camps);
    $in = implode(',', $ids);

    $imp = []; $q = $pdo->query("SELECT camp_id, SUM(impressions) i, SUM(clics) c FROM camp_stats_jour
                                 WHERE camp_id IN ($in) AND jour >= DATE_SUB(CURDATE(), INTERVAL $days DAY) GROUP BY camp_id");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $imp[(int)$r['camp_id']] = $r;

    $ins = []; $insBoost = [];
    $q = $pdo->query("SELECT i.camp_id, i.date_inscription,
                             (SELECT COUNT(*) FROM boost_achats b WHERE b.camp_id = i.camp_id AND i.date_inscription BETWEEN b.debut AND b.fin) AS en_boost
                      FROM inscriptions i
                      WHERE i.camp_id IN ($in) AND i.statut_paiement = 'PAYE' AND i.statut NOT IN ('Annulé', 'annule', 'Annule')
                        AND i.date_inscription >= DATE_SUB(NOW(), INTERVAL $days DAY)");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = (int)$r['camp_id'];
        $ins[$k] = ($ins[$k] ?? 0) + 1;
        if ((int)$r['en_boost'] > 0) $insBoost[$k] = ($insBoost[$k] ?? 0) + 1;
    }
    $totalIns = []; $q = $pdo->query("SELECT camp_id, COUNT(*) n FROM inscriptions WHERE camp_id IN ($in) AND statut_paiement = 'PAYE' AND statut NOT IN ('Annulé', 'annule', 'Annule') GROUP BY camp_id");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $totalIns[(int)$r['camp_id']] = (int)$r['n'];

    $pts = []; $q = $pdo->query("SELECT camp_id, SUM(points) p FROM boost_achats WHERE camp_id IN ($in) AND debut >= DATE_SUB(NOW(), INTERVAL $days DAY) GROUP BY camp_id");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $pts[(int)$r['camp_id']] = (int)$r['p'];

    $now = time();
    foreach ($camps as &$c) {
        $id = (int)$c['id'];
        $i = (int)($imp[$id]['i'] ?? 0); $cl = (int)($imp[$id]['c'] ?? 0);
        $c['impressions'] = $i; $c['clics'] = $cl;
        $c['ctr'] = $i > 0 ? $cl / $i : null;
        $c['inscriptions'] = $ins[$id] ?? 0;
        $c['inscrits_total'] = $totalIns[$id] ?? 0;
        $c['inscriptions_boost'] = $insBoost[$id] ?? 0;
        $c['points_depenses'] = $pts[$id] ?? 0;
        $c['cout_par_inscription'] = ($c['points_depenses'] > 0 && $c['inscriptions_boost'] > 0) ? $c['points_depenses'] * MK_POINT_EUR / $c['inscriptions_boost'] : null;
        $c['taux_conversion'] = $cl > 0 ? $c['inscriptions'] / $cl : null;
        $c['vedette_actif'] = !empty($c['boost_vedette_fin']) && strtotime($c['boost_vedette_fin']) > $now;
        $c['urgence_actif'] = !empty($c['boost_urgence_fin']) && strtotime($c['boost_urgence_fin']) > $now;
        $c['bump_actif'] = !empty($c['date_bump']) && strtotime($c['date_bump']) > $now - 72 * 3600;
    }
    unset($c);
    return $camps;
}

/**
 * Preuve chiffrée à l'échelle de la plateforme : le taux de clic des séjours en boost comparé aux autres (30 jours).
 * Calculé sur des données réelles ; renvoie null tant que l'échantillon est trop faible pour être honnête.
 */
function mk_platform_proof(PDO $pdo): ?array {
    try {
        mk_ensure($pdo);
        $row = $pdo->query("SELECT
                SUM(CASE WHEN b.camp_id IS NOT NULL THEN s.impressions ELSE 0 END) bi, SUM(CASE WHEN b.camp_id IS NOT NULL THEN s.clics ELSE 0 END) bc,
                SUM(CASE WHEN b.camp_id IS NULL THEN s.impressions ELSE 0 END) ni, SUM(CASE WHEN b.camp_id IS NULL THEN s.clics ELSE 0 END) nc
            FROM camp_stats_jour s
            LEFT JOIN (SELECT DISTINCT camp_id, DATE(debut) d1, DATE(fin) d2 FROM boost_achats) b ON b.camp_id = s.camp_id AND s.jour BETWEEN b.d1 AND b.d2
            WHERE s.jour >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['bi'] < 500 || (int)$row['ni'] < 500) return null;
        $boost = (int)$row['bc'] / (int)$row['bi'];
        $normal = (int)$row['nc'] / (int)$row['ni'];
        if ($normal <= 0) return null;
        return ['boost_impressions' => (int)$row['bi'], 'boost_ctr' => $boost, 'normal_ctr' => $normal, 'multiplicateur' => $boost / $normal];
    } catch (Throwable $e) { return null; }
}

/** Même origine (protection CSRF pour les POST JSON) */
function mk_same_origin(): bool {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o === '') return true; // navigateur sans en-tête Origin : le Content-Type JSON suffit déjà à bloquer un formulaire externe
    return strcasecmp((string)parse_url($o, PHP_URL_HOST), (string)($_SERVER['HTTP_HOST'] ?? '')) === 0;
}
