<?php
// api/geocode.php : position géographique exacte de chaque session (pour la carte de la fiche séjour).
//
// Géocodage par OpenStreetMap (Nominatim), côté SERVEUR : le visiteur n'envoie rien à un service tiers pour trouver l'adresse,
// seule la carte elle-même (tuiles OpenStreetMap, sans cookie) est chargée par son navigateur.
// Colonnes ajoutées à `camps` à la première utilisation : lat, lng, geo_hash (empreinte de l'adresse géocodée), geo_precis (1 = adresse exacte, 0 = ville).
// Une adresse n'est géocodée qu'une fois : tant qu'elle ne change pas, la position est relue en base.

function geo_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM camps")->fetchAll(PDO::FETCH_COLUMN);
        $add = ['lat' => 'DECIMAL(9,6) NULL', 'lng' => 'DECIMAL(9,6) NULL', 'geo_hash' => 'VARCHAR(40) NULL', 'geo_precis' => 'TINYINT(1) NOT NULL DEFAULT 0'];
        foreach ($add as $c => $def) if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN $c $def");
    } catch (Throwable $e) { error_log('geo_ensure: ' . $e->getMessage()); }
    $done = true;
}

/** Empreinte de l'adresse d'une ligne camps */
function geo_hash(array $c): string {
    return md5(mb_strtolower(trim(($c['adresse'] ?? '') . '|' . ($c['code_postal'] ?? '') . '|' . ($c['ville'] ?? '') . '|' . ($c['pays'] ?? ''))));
}

/** Une requête Nominatim. Retourne [lat, lng, précis] ou null. */
function geo_chercher(string $q, int $timeout = 4): ?array {
    static $cache = [];
    if (isset($cache[$q])) return $cache[$q] ?: null;
    // Nominatim : une requête par seconde au maximum, tous visiteurs confondus (verrou léger dans le dossier temporaire)
    $lock = sys_get_temp_dir() . '/colomap_nominatim.t';
    $wait = 1.1 - (microtime(true) - (float)@file_get_contents($lock));
    if ($wait > 0 && $wait < 1.2) usleep((int)($wait * 1000000));
    @file_put_contents($lock, (string)microtime(true));
    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&addressdetails=0&q=' . rawurlencode($q);
    $ua = 'ColoMap/1.0 (+https://moncamp.fr)';   // l'usage de Nominatim exige un User-Agent ; aucune donnée personnelle
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept-Language: fr']]);
        $body = curl_exec($ch); curl_close($ch);
    } else {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: $ua\r\nAccept-Language: fr\r\n"]]));
    }
    $r = $body ? json_decode($body, true) : null;
    if (!is_array($r) || empty($r[0]['lat']) || empty($r[0]['lon'])) { $cache[$q] = false; return null; }
    $type = (string)($r[0]['addresstype'] ?? $r[0]['type'] ?? '');
    $ville = ['city', 'town', 'village', 'municipality', 'administrative', 'postcode', 'county', 'state', 'suburb', 'hamlet', 'region'];
    $out = [round((float)$r[0]['lat'], 6), round((float)$r[0]['lon'], 6), !in_array($type, $ville, true)];
    $cache[$q] = $out;
    return $out;
}

/** Position d'une ligne camps : adresse exacte d'abord, puis la ville. [lat, lng, précis] ou null. */
function geo_position(array $c): ?array {
    $pays = trim((string)($c['pays'] ?? '')) ?: 'France';
    $ville = trim(($c['code_postal'] ?? '') . ' ' . ($c['ville'] ?? ''));
    $adr = trim((string)($c['adresse'] ?? ''));
    if ($adr !== '') {
        $p = geo_chercher($adr . ', ' . $ville . ', ' . $pays);
        if ($p) return $p;
    }
    if (trim($ville) === '') return null;
    $p = geo_chercher($ville . ', ' . $pays);
    return $p ? [$p[0], $p[1], false] : null;
}

/**
 * Géocode les sessions dont l'adresse a changé, dans la limite de $budget secondes (le reste sera fait à la première visite de la fiche).
 * À appeler APRÈS la transaction d'enregistrement.
 */
function geo_actualiser(PDO $pdo, array $ids, float $budget = 6.0): void {
    if (!$ids) return;
    try {
        geo_ensure($pdo);
        $t0 = microtime(true);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, adresse, ville, code_postal, pays, geo_hash FROM camps WHERE id IN ($in)");
        $st->execute(array_values($ids));
        $up = $pdo->prepare("UPDATE camps SET lat = ?, lng = ?, geo_hash = ?, geo_precis = ? WHERE id = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $h = geo_hash($c);
            if (($c['geo_hash'] ?? '') === $h) continue;
            if (microtime(true) - $t0 > $budget) break;
            $p = geo_position($c);
            $up->execute([$p ? $p[0] : null, $p ? $p[1] : null, $h, $p && $p[2] ? 1 : 0, $c['id']]);
        }
    } catch (Throwable $e) { error_log('geo_actualiser: ' . $e->getMessage()); }
}
