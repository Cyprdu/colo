<?php
// api/clics.php : clics vers le site d'un organisme (« Réserver ma place » externe, redirection /go/<jeton du séjour>, go.php).
// Une ligne par clic : séjour, organisme, date, page d'origine (sans paramètres), appareil, IP hachée avec un sel du jour, robot (exclu des compteurs).
// Commission partenaire par organisme (€ par clic et/ou % du prix) et taux de transformation estimé (adm_kv 'clics_conv_pct') : estimation des revenus.

function clics_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS clics_sortants (id BIGINT AUTO_INCREMENT PRIMARY KEY, jeton CHAR(20) NOT NULL, camp_id INT NOT NULL, organisateur_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, referent VARCHAR(255) NULL, appareil VARCHAR(10) NOT NULL, ip_hash CHAR(64) NULL, bot TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_jeton (jeton), KEY idx_orga (organisateur_id, created_at), KEY idx_camp (camp_id, created_at), KEY idx_date (created_at)) DEFAULT CHARSET=utf8mb4");
        $cols = $pdo->query("SHOW COLUMNS FROM organisateurs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('partenaire_clic_cents', $cols, true)) $pdo->exec("ALTER TABLE organisateurs ADD COLUMN partenaire_clic_cents INT NULL, ADD COLUMN partenaire_pct DECIMAL(5,2) NULL");
    } catch (Throwable $e) { error_log('clics_ensure: ' . $e->getMessage()); }
}

/** Adresse de l'organisme avec les paramètres de suivi (ceux déjà présents sont remplacés), ou null si l'adresse n'est pas un lien web valable */
function clics_url(string $lien, string $campagne, string $clic): ?string {
    $lien = trim($lien);
    if ($lien === '' || preg_match('/[\s<>"\\\\]/', $lien)) return null;
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $lien)) $lien = 'https://' . ltrim($lien, '/');
    $p = parse_url($lien);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return null;
    [$base, $frag] = array_pad(explode('#', $lien, 2), 2, null);
    [$base, $q] = array_pad(explode('?', $base, 2), 2, '');
    $q = implode('&', array_filter(explode('&', $q), fn($kv) => $kv !== '' && !preg_match('/^(utm_source|utm_medium|utm_campaign|mc_click)(=|$)/i', $kv)));
    $q .= ($q === '' ? '' : '&') . http_build_query(['utm_source' => 'moncamp.fr', 'utm_medium' => 'referral', 'utm_campaign' => $campagne, 'mc_click' => $clic]);
    return $base . '?' . $q . ($frag === null ? '' : '#' . $frag);
}

function clics_appareil(string $ua): string {
    if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|Android(?!.*Mobile)/i', $ua)) return 'tablette';
    if (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua)) return 'mobile';
    return 'ordinateur';
}

/** Page d'origine sans paramètres ni fragment (aucun jeton ou donnée personnelle conservé) */
function clics_referent(string $ref): ?string {
    $p = parse_url($ref);
    return empty($p['host']) ? null : mb_substr(strtolower($p['host']) . ($p['path'] ?? ''), 0, 255);
}

/** Enregistre le clic (ne bloque jamais la redirection) et renvoie son identifiant (mc_click) */
function clics_enregistrer(PDO $pdo, int $campId, int $orgaId): string {
    $jeton = bin2hex(random_bytes(10));
    try {
        clics_ensure($pdo);
        require_once __DIR__ . '/marketing_lib.php';
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $sel = (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : __FILE__) . '|clics|' . date('Y-m-d');
        $pdo->prepare("INSERT INTO clics_sortants (jeton, camp_id, organisateur_id, referent, appareil, ip_hash, bot) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$jeton, $campId, $orgaId, clics_referent((string)($_SERVER['HTTP_REFERER'] ?? '')), clics_appareil($ua), $ip === '' ? null : hash_hmac('sha256', $ip, $sel), mk_is_bot() ? 1 : 0]);
    } catch (Throwable $e) { error_log('clics_enregistrer: ' . $e->getMessage()); }
    return $jeton;
}

function clics_conv_pct(PDO $pdo): float {
    try { $v = $pdo->query("SELECT v FROM adm_kv WHERE k = 'clics_conv_pct'")->fetchColumn(); } catch (Throwable $e) { $v = false; }
    return $v === false ? 2.0 : (float)$v;
}

/** Revenu estimé (centimes) : clics × €/clic + clics × taux de transformation × prix × % */
function clics_estimation(int $n, ?int $cpc, ?float $pct, float $prix, float $conv): int {
    return (int)round($n * (int)$cpc + $n * $conv / 100 * $prix * 100 * (float)$pct / 100);
}

/**
 * Clics hors robots du $du au $au (Y-m-d) : série par jour, lignes par session, par organisme, total, robots écartés.
 * $orgaId = null : tous les organismes (administration).
 */
function clics_stats(PDO $pdo, string $du, string $au, ?int $orgaId = null): array {
    clics_ensure($pdo);
    $f = $orgaId ? ' AND k.organisateur_id = ' . (int)$orgaId : '';
    $p = [$du . ' 00:00:00', $au . ' 23:59:59'];
    $jours = [];
    for ($t = strtotime($du); $t <= strtotime($au); $t = strtotime("+1 day", $t)) $jours[date('Y-m-d', $t)] = 0;
    $st = $pdo->prepare("SELECT DATE(k.created_at) d, COUNT(*) n FROM clics_sortants k WHERE k.bot = 0 AND k.created_at BETWEEN ? AND ?$f GROUP BY d");
    $st->execute($p);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $d => $n) if (isset($jours[$d])) $jours[$d] = (int)$n;
    $st = $pdo->prepare("SELECT c.token, c.nom, c.date_debut, c.date_fin, c.prix, o.nom orga, o.public_id, o.partenaire_clic_cents cpc, o.partenaire_pct pct,
            COUNT(*) n, SUM(k.appareil = 'mobile') mobile, COUNT(DISTINCT k.ip_hash) uniques, MAX(k.created_at) dernier
        FROM clics_sortants k JOIN camps c ON c.id = k.camp_id JOIN organisateurs o ON o.id = k.organisateur_id
        WHERE k.bot = 0 AND k.created_at BETWEEN ? AND ?$f GROUP BY k.camp_id ORDER BY n DESC, dernier DESC");
    $st->execute($p);
    $conv = clics_conv_pct($pdo);
    $sejours = []; $orgas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n = (int)$r['n'];
        $est = clics_estimation($n, $r['cpc'] === null ? null : (int)$r['cpc'], $r['pct'] === null ? null : (float)$r['pct'], (float)$r['prix'], $conv);
        $sejours[] = ['t' => $r['token'], 'nom' => html_entity_decode((string)$r['nom'], ENT_QUOTES, 'UTF-8'), 'debut' => $r['date_debut'], 'fin' => $r['date_fin'], 'prix' => (float)$r['prix'],
                      'orga' => (string)$r['orga'], 'o' => (string)$r['public_id'], 'n' => $n, 'uniques' => (int)$r['uniques'], 'mobile' => (int)$r['mobile'], 'dernier' => $r['dernier'], 'est' => $est];
        $o = &$orgas[$r['public_id']];
        $o = ['o' => (string)$r['public_id'], 'nom' => (string)$r['orga'], 'cpc' => $r['cpc'] === null ? null : (int)$r['cpc'], 'pct' => $r['pct'] === null ? null : (float)$r['pct'],
              'n' => ($o['n'] ?? 0) + $n, 'sejours' => ($o['sejours'] ?? 0) + 1, 'est' => ($o['est'] ?? 0) + $est];
        unset($o);
    }
    usort($orgas, fn($a, $b) => $b['n'] <=> $a['n']);
    $st = $pdo->prepare("SELECT COUNT(*) FROM clics_sortants k WHERE k.bot = 1 AND k.created_at BETWEEN ? AND ?$f");
    $st->execute($p);
    return ['jours' => $jours, 'sejours' => $sejours, 'orgas' => array_values($orgas), 'total' => array_sum($jours), 'robots' => (int)$st->fetchColumn(), 'conv' => $conv,
            'est' => array_sum(array_column($sejours, 'est'))];
}

/** Export CSV : une ligne par clic (identifiant mc_click pour rapprocher les réservations côté organisme) */
function clics_csv(PDO $pdo, string $du, string $au, ?int $orgaId = null): void {
    require_once __DIR__ . '/compta_lib.php';
    clics_ensure($pdo);
    $st = $pdo->prepare("SELECT k.created_at, k.jeton, c.nom, c.date_debut, c.token, o.nom orga, k.appareil, k.referent FROM clics_sortants k
        JOIN camps c ON c.id = k.camp_id JOIN organisateurs o ON o.id = k.organisateur_id
        WHERE k.bot = 0 AND k.created_at BETWEEN ? AND ?" . ($orgaId ? ' AND k.organisateur_id = ' . (int)$orgaId : '') . " ORDER BY k.created_at");
    $st->execute([$du . ' 00:00:00', $au . ' 23:59:59']);
    $g = (function () use ($st) { while ($r = $st->fetch(PDO::FETCH_ASSOC)) yield [date('d/m/Y H:i', strtotime($r['created_at'])), $r['jeton'], html_entity_decode((string)$r['nom'], ENT_QUOTES, 'UTF-8'),
        $r['date_debut'] ? date('d/m/Y', strtotime($r['date_debut'])) : '', $r['token'], $r['orga'], $r['appareil'], (string)$r['referent']]; })();
    compta_csv('clics-' . $du . '-' . $au . '.csv', ['Date', 'mc_click', 'Séjour', 'Début de session', 'utm_campaign', 'Organisme', 'Appareil', "Page d'origine"], $g);
}
