<?php
// admin_lib.php : socle du back-office (sécurité, audit, CSRF, gabarit, icônes, entités).
// Inclus par admin.php, admin_*.php et admin_api.php. Jamais appelé directement.
//
// Garanties, appliquées à CHAQUE page et API :
//  - l'accès est vérifié côté serveur dans la base (pas seulement le drapeau de session) ;
//  - session liée au navigateur, expiration d'inactivité ;
//  - jeton CSRF sur tout POST ; en-têtes de sécurité (CSP stricte, pas de script en ligne) ;
//  - ré-authentification par mot de passe avant les actions sensibles (limitation de tentatives) ;
//  - journal d'audit en base (table créée automatiquement, idempotent).

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'admin_lib.php') { http_response_code(404); exit; }
define('ADMIN_LIB', 1);

const ADM_IDLE_MAX   = 1800;  // 30 min d'inactivité : session admin close
const ADM_REAUTH_TTL = 600;   // 10 min de validité après saisie du mot de passe
const ADM_PER_PAGE   = 25;

require_once __DIR__ . '/api/config.php';

/* ------------------------------------------------------------------ en-têtes */
function adm_headers(): void {
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');
}

/* --------------------------------------------------------------- base / audit */
function adm_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        email VARCHAR(190) NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        action VARCHAR(60) NOT NULL,
        cible VARCHAR(190) NULL,
        detail TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_date (created_at),
        INDEX idx_audit_action (action)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_attempts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(30) NOT NULL,
        who VARCHAR(80) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_att (kind, who, created_at)
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function adm_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/** Journal d'audit. N'échoue jamais bruyamment (un journal cassé ne doit pas casser l'action, mais on le trace). */
function adm_audit(string $action, ?string $cible = null, ?string $detail = null): void {
    global $pdo;
    try {
        adm_ensure($pdo);
        $u = $_SESSION['user'] ?? [];
        $pdo->prepare("INSERT INTO admin_audit (user_id, email, ip, action, cible, detail) VALUES (?,?,?,?,?,?)")
            ->execute([$u['id'] ?? null, isset($u['email']) ? mb_substr((string)$u['email'], 0, 190) : null, adm_ip(),
                       mb_substr($action, 0, 60), $cible !== null ? mb_substr($cible, 0, 190) : null,
                       $detail !== null ? mb_substr($detail, 0, 4000) : null]);
    } catch (Throwable $e) { error_log('adm_audit: ' . $e->getMessage()); }
}

/** Limitation de tentatives : $max échecs de type $kind en $minutes minutes pour $who. */
function adm_limited(string $kind, string $who, int $max, int $minutes): bool {
    global $pdo;
    try {
        adm_ensure($pdo);
        $st = $pdo->prepare("SELECT COUNT(*) FROM admin_attempts WHERE kind = ? AND who = ? AND created_at > (NOW() - INTERVAL $minutes MINUTE)");
        $st->execute([$kind, $who]);
        return (int)$st->fetchColumn() >= $max;
    } catch (Throwable $e) { error_log('adm_limited: ' . $e->getMessage()); return true; }  // dans le doute, on bloque
}
function adm_hit(string $kind, string $who): void {
    global $pdo;
    try {
        adm_ensure($pdo);
        $pdo->prepare("INSERT INTO admin_attempts (kind, who) VALUES (?, ?)")->execute([$kind, $who]);
        if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM admin_attempts WHERE created_at < (NOW() - INTERVAL 2 DAY)");
    } catch (Throwable $e) { error_log('adm_hit: ' . $e->getMessage()); }
}

/* -------------------------------------------------------------------- garde */
function adm_json(array $data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * À appeler en tête de chaque page et API admin.
 * $api = true : réponses JSON (403/401) au lieu d'une redirection.
 */
function adm_guard(bool $api = false): void {
    global $pdo;
    adm_headers();
    $deny = function (int $code, string $msg) use ($api) {
        if ($api) adm_json(['error' => $msg], $code);
        header('Location: index.php'); exit;
    };

    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) {
        // Anonyme : on ne révèle rien, mais on limite le bruit
        if (adm_limited('acces', adm_ip(), 20, 10)) { http_response_code(429); exit; }
        adm_hit('acces', adm_ip());
        $deny(403, 'Accès refusé.');
    }

    // Vérification côté serveur dans la base : le drapeau de session seul ne suffit pas (compte rétrogradé, supprimé).
    try {
        $st = $pdo->prepare("SELECT id, email, is_admin FROM users WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('adm_guard: ' . $e->getMessage()); $deny(500, 'Erreur serveur.'); }

    if (!$u || (int)$u['is_admin'] !== 1) {
        if (adm_limited('acces', adm_ip(), 10, 10)) { http_response_code(429); exit; }
        adm_hit('acces', adm_ip());
        adm_audit('acces_refuse', basename($_SERVER['SCRIPT_NAME'] ?? ''), 'compte non administrateur');
        unset($_SESSION['adm_reauth']);
        $deny(403, 'Accès refusé.');
    }
    $_SESSION['user']['is_admin'] = 1;

    // Session liée au navigateur + expiration d'inactivité
    $fp = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (!isset($_SESSION['adm_fp'])) $_SESSION['adm_fp'] = $fp;
    if (!hash_equals($_SESSION['adm_fp'], $fp)) {
        adm_audit('session_invalide', null, 'empreinte navigateur différente');
        $_SESSION = []; session_destroy();
        $deny(403, 'Session invalide.');
    }
    $now = time();
    if (isset($_SESSION['adm_last']) && $now - (int)$_SESSION['adm_last'] > ADM_IDLE_MAX) {
        unset($_SESSION['adm_reauth']);
        adm_audit('session_expiree', null, 'inactivité');
        $_SESSION['adm_last'] = $now;
        if ($api) adm_json(['error' => 'Session expirée, rechargez la page.', 'expired' => true], 401);
        header('Location: login.php'); exit;
    }
    $_SESSION['adm_last'] = $now;

    adm_ensure($pdo);
    if (empty($_SESSION['adm_csrf'])) $_SESSION['adm_csrf'] = bin2hex(random_bytes(32));

    // Aucune requête ne doit pouvoir faire fuir une erreur SQL brute
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
        if (!is_string($tok) || !hash_equals($_SESSION['adm_csrf'], $tok)) {
            adm_audit('csrf_refuse', basename($_SERVER['SCRIPT_NAME'] ?? ''));
            $deny(419, 'Jeton de sécurité invalide, rechargez la page.');
        }
    }
}

function adm_csrf(): string { return (string)($_SESSION['adm_csrf'] ?? ''); }
function adm_reauth_ok(): bool { return isset($_SESSION['adm_reauth']) && time() - (int)$_SESSION['adm_reauth'] < ADM_REAUTH_TTL; }

/** Vérifie le mot de passe de l'admin connecté. Limité à 5 échecs / 15 min (par compte et par IP). */
function adm_reauth(string $password): array {
    global $pdo;
    $uid = (int)$_SESSION['user']['id'];
    $who = "u$uid|" . adm_ip();
    if (adm_limited('reauth', $who, 5, 15) || adm_limited('reauth', 'u' . $uid, 10, 15)) {
        adm_audit('reauth_bloque', null, 'trop de tentatives');
        return [false, 'Trop de tentatives. Réessayez dans 15 minutes.', 429];
    }
    $st = $pdo->prepare("SELECT password FROM users WHERE id = ? AND is_admin = 1");
    $st->execute([$uid]);
    $hash = (string)$st->fetchColumn();
    if ($hash !== '' && $password !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['adm_reauth'] = time();
        adm_audit('reauth_ok');
        return [true, '', 200];
    }
    adm_hit('reauth', $who); adm_hit('reauth', 'u' . $uid);
    adm_audit('reauth_echec');
    return [false, 'Mot de passe incorrect.', 403];
}

/** API : exige une ré-authentification récente (sinon 401 + reauth:true, le JS ouvre la fenêtre de mot de passe). */
function adm_need_reauth_api(): void {
    if (!adm_reauth_ok()) adm_json(['error' => 'Confirmez votre mot de passe.', 'reauth' => true], 401);
    $_SESSION['adm_reauth'] = time();   // fenêtre glissante
}

/* --------------------------------------------------------------- utilitaires */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function adm_money($v): string { return number_format((float)$v, 2, ',', ' ') . ' €'; }
function adm_date($v): string { if (!$v || strpos((string)$v, '0000') === 0) return ''; $t = strtotime((string)$v); return $t ? date('d/m/Y H:i', $t) : h($v); }
function adm_one(string $sql, array $p = []) {
    global $pdo;
    try { $st = $pdo->prepare($sql); $st->execute($p); $v = $st->fetchColumn(); return $v === false ? null : $v; }
    catch (Throwable $e) { error_log('adm_one: ' . $e->getMessage()); return null; }
}
function adm_rows(string $sql, array $p = []): array {
    global $pdo;
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('adm_rows: ' . $e->getMessage()); return []; }
}
/** Colonnes dont la valeur ne doit jamais partir dans une réponse (mot de passe, jetons, IBAN...). */
function adm_sensitive(string $col): bool { return (bool)preg_match('/pass|token|secret|iban|bic|signature|cle_|api_key/i', $col); }

/* -------------------------------------------------------------------- icônes */
function ic(string $n, string $cls = 'ic'): string { return '<svg class="' . h($cls) . '" aria-hidden="true"><use href="#i-' . h($n) . '"/></svg>'; }
function adm_sprite(): string {
    $p = [
     'home'=>'<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
     'users'=>'<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 010 6.8"/><path d="M18 14.4c2 .7 3.5 2.4 3.5 5.6"/>',
     'building'=>'<path d="M4 21V5l8-3 8 3v16"/><path d="M9 21v-5h6v5"/><path d="M9 9h.01M12 9h.01M15 9h.01M9 12.5h.01M12 12.5h.01M15 12.5h.01"/>',
     'tent'=>'<path d="M3 20L12 4l9 16H3z"/><path d="M12 4v16"/><path d="M9 20l3-6 3 6"/>',
     'ticket'=>'<path d="M3 8a2 2 0 002-2h14a2 2 0 002 2v2a2 2 0 000 4v2a2 2 0 00-2 2H5a2 2 0 00-2-2v-2a2 2 0 000-4V8z"/><path d="M14 6v12" stroke-dasharray="2 2"/>',
     'card'=>'<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>',
     'bolt'=>'<path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/>',
     'bank'=>'<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18"/>',
     'mail'=>'<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 7l8.5 6 8.5-6"/>',
     'database'=>'<ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5v13c0 1.7 3.6 3 8 3s8-1.3 8-3v-13"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
     'terminal'=>'<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M7 9l3 3-3 3M13 15h4"/>',
     'shield'=>'<path d="M12 3l8 3v6c0 5-3.4 8.2-8 9.5C7.4 20.200 4 17 4 12V6l8-3z"/><path d="M9 12l2.200 2.200L15.500 10"/>',
     'lock'=>'<rect x="4.5" y="10.500" width="15" height="10" rx="2.500"/><path d="M8 10.500V8a4 4 0 018 0v2.500"/>',
     'check'=>'<path d="M5 12.500l4.500 4.500L19 7.500"/>',
     'x'=>'<path d="M6 6l12 12M18 6L6 18"/>',
     'edit'=>'<path d="M4 20h4L19 9a2.800 2.800 0 00-4-4L4 16v4z"/><path d="M13.500 6.500l4 4"/>',
     'trash'=>'<path d="M4 7h16M9 7V4h6v3M6.500 7l1 13h9l1-13M10 11v6M14 11v6"/>',
     'search'=>'<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.300-4.300"/>',
     'eye'=>'<path d="M2 12s3.500-7 10-7 10 7 10 7-3.500 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
     'refresh'=>'<path d="M20 11A8 8 0 006 7.500L4 10M4 13a8 8 0 0014 3.500l2-2.500M4 4v6h6M20 20v-6h-6"/>',
     'plus'=>'<path d="M12 5v14M5 12h14"/>',
     'back'=>'<path d="M15 5l-7 7 7 7"/>',
     'next'=>'<path d="M9 5l7 7-7 7"/>',
     'menu'=>'<path d="M4 7h16M4 12h16M4 17h16"/>',
     'out'=>'<path d="M10 4H5a1 1 0 00-1 1v14a1 1 0 001 1h5M15 8l4 4-4 4M19 12H9"/>',
     'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
     'euro'=>'<path d="M18 6.500A6.500 6.500 0 007 9.500v5A6.500 6.500 0 0018 17.500M4 10.500h9M4 13.500h9"/>',
     'chart'=>'<path d="M4 20V4M4 20h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
     'star'=>'<path d="M12 3l2.700 5.600 6.100.8-4.500 4.200 1.100 6.100L12 16.800 6.600 19.700l1.100-6.100L3.200 9.400l6.100-.8L12 3z"/>',
     'alert'=>'<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h.01"/>',
     'key'=>'<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
     'list'=>'<path d="M8 6h13M8 12h13M8 18h13M3.500 6h.01M3.500 12h.01M3.500 18h.01"/>',
     'play'=>'<path d="M7 4l13 8-13 8V4z"/>',
     'external'=>'<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/>',
     'user-check'=>'<circle cx="9" cy="8" r="3.500"/><path d="M2.500 20c0-3.600 2.900-6 6.500-6 1.500 0 2.900.4 4 1.200M15 18l2 2 4-4"/>',
    ];
    $o = '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>';
    foreach ($p as $k => $v) $o .= '<symbol id="i-' . $k . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round">' . $v . '</symbol>';
    return $o . '</defs></svg>';
}

/* ------------------------------------------------------------------ gabarit */
function adm_badges(): array {
    return [
        'users'    => (int)adm_one("SELECT COUNT(*) FROM users WHERE is_directeur = 0 AND demande_en_cours = 1"),
        'camps'    => (int)adm_one("SELECT COUNT(*) FROM camps WHERE en_attente = 1 AND valide = 0 AND refuse = 0"),
        'virements'=> (int)adm_one("SELECT COUNT(*) FROM virements WHERE effectue = 0"),
        'contacts' => (int)adm_one("SELECT COUNT(*) FROM contact_messages WHERE statut = 'En attente'"),
    ];
}

function adm_nav(): array {
    return [
        'Pilotage' => [['admin.php', 'home', 'Tableau de bord', '']],
        'Gestion'  => [
            ['admin_list.php?e=users', 'users', 'Utilisateurs', 'users'],
            ['admin_list.php?e=organismes', 'building', 'Organismes', ''],
            ['admin_list.php?e=camps', 'tent', 'Séjours', 'camps'],
            ['admin_list.php?e=inscriptions', 'ticket', 'Inscriptions', ''],
            ['admin_list.php?e=paiements', 'card', 'Paiements', ''],
            ['admin_list.php?e=virements', 'bank', 'Virements', 'virements'],
            ['admin_list.php?e=boosts', 'bolt', 'Boosts', ''],
            ['admin_list.php?e=contacts', 'mail', 'Messages de contact', 'contacts'],
        ],
        'Finances' => [
            ['admin_compta.php', 'euro', 'Comptabilité', ''],
            ['admin_demande_virement.php', 'bank', 'Versements aux organismes', ''],
        ],
        'Données'  => [
            ['admin_db.php', 'database', 'Base de données', ''],
            ['admin_sql.php', 'terminal', 'Console SQL', ''],
        ],
        'Sécurité' => [['admin_audit.php', 'shield', "Journal d'audit", '']],
    ];
}

function adm_head(string $title, string $active = ''): void {
    $b = adm_badges();
    $me = $_SESSION['user'] ?? [];
    $name = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><meta name="csrf" content="' . h(adm_csrf()) . '">'
       . '<title>' . h($title) . ' · Administration ColoMap</title><link rel="icon" type="image/png" href="favico.png">'
       . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
       . '<link rel="stylesheet" href="assets/admin.css?v=2"></head><body>' . adm_sprite()
       . '<div class="adm" id="adm"><aside class="side" id="side"><a class="brand" href="admin.php">' . ic('shield', 'ic ic-lg') . '<span>ColoMap<small>Administration</small></span></a><nav>';
    foreach (adm_nav() as $grp => $items) {
        echo '<div class="grp">' . h($grp) . '</div>';
        foreach ($items as [$href, $icon, $label, $badge]) {
            $on = ($active !== '' && strpos($href, $active) !== false) ? ' on' : '';
            echo '<a class="nav' . $on . '" href="' . h($href) . '">' . ic($icon) . '<span>' . h($label) . '</span>'
               . (($badge !== '' && !empty($b[$badge])) ? '<b class="pill">' . (int)$b[$badge] . '</b>' : '') . '</a>';
        }
    }
    echo '</nav><div class="side-foot"><a class="nav" href="index.php">' . ic('external') . '<span>Voir le site</span></a>'
       . '<a class="nav" href="api/logout.php">' . ic('out') . '<span>Déconnexion</span></a></div></aside>'
       . '<div class="main"><header class="top"><button class="icon-btn menu" id="menu" type="button" aria-label="Menu">' . ic('menu') . '</button>'
       . '<h1>' . h($title) . '</h1><div class="top-r"><span class="lockst" id="lockst" title="Ré-authentification">' . ic('lock') . '<span id="locktxt">Verrouillé</span></span>'
       . '<span class="who">' . ic('user-check') . h($name !== '' ? $name : 'Admin') . '</span></div></header><main class="content">';
    $f = $_SESSION['adm_flash'] ?? null; unset($_SESSION['adm_flash']);
    if ($f) echo '<div class="note ' . ($f[0] === 'ok' ? 'ok' : 'err') . '">' . h($f[1]) . '</div>';
}

function adm_foot(array $page = []): void {
    echo '</main></div></div>'
       . '<div class="modal" id="m-reauth" hidden><div class="dlg"><h3>' . ic('lock') . ' Confirmez votre identité</h3>'
       . '<p>Cette action est sensible. Saisissez votre mot de passe administrateur (valable 10 minutes).</p>'
       . '<form id="re-form" method="post" autocomplete="on">'   // vrai formulaire : Chrome associe identifiant + mot de passe ici et plus à un champ de recherche
       . '<input class="sr" type="text" name="username" value="' . h((string)($_SESSION['user']['email'] ?? '')) . '" autocomplete="username" readonly tabindex="-1" aria-hidden="true">'
       . '<input class="in" type="password" id="re-pass" name="password" autocomplete="current-password" placeholder="Mot de passe"><p class="err" id="re-err"></p>'
       . '<div class="row"><button class="btn ghost" type="button" data-close>Annuler</button><button class="btn" type="submit" id="re-go">Confirmer</button></div></form></div></div>'
       . '<div class="modal" id="m-confirm" hidden><div class="dlg"><h3 id="cf-title">Confirmer</h3><p id="cf-text"></p>'
       . '<div id="cf-typed" hidden><p class="hint">Pour confirmer, tapez <b id="cf-word"></b> :</p><input class="in" id="cf-in" autocomplete="off"></div>'
       . '<div class="row"><button class="btn ghost" type="button" data-close>Annuler</button><button class="btn" type="button" id="cf-go">Confirmer</button></div></div></div>'
       . '<div class="toast" id="toast" role="status"></div>'
       . '<script type="application/json" id="adm-data">' . json_encode($page + ['reauth' => adm_reauth_ok() ? max(0, ADM_REAUTH_TTL - (time() - (int)$_SESSION['adm_reauth'])) : 0], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
       . '<script src="assets/admin.js?v=2"></script></body></html>';
}

/* ----------------------------------------------------------------- entités */
// Chaque entité : table, requête de liste, colonnes (alias => [libellé, type]), recherche, filtres, actions.
// Les filtres et les requêtes sont des constantes (jamais construits depuis l'entrée utilisateur).
function adm_entities(): array {
    return [
    'users' => [
        'label' => 'Utilisateurs', 'icon' => 'users', 'table' => 'users', 'pk' => 'id', 'alias' => 'u', 'edit' => true,
        'select' => "u.id, u.nom, u.prenom, u.email, u.tel, u.created_at, u.is_admin, u.is_directeur, u.is_animateur, u.is_verified, u.demande_en_cours",
        'from' => "users u",
        'cols' => ['id' => ['ID', 'id'], 'nom' => ['Nom', 'name'], 'email' => ['Email', 'text'], 'tel' => ['Téléphone', 'text'], 'role' => ['Rôle', 'role'], 'is_verified' => ['Vérifié', 'bool'], 'created_at' => ['Inscrit le', 'date']],
        'search' => ['u.nom', 'u.prenom', 'u.email', 'u.tel'],
        'filters' => ['all' => ['Tous', '1=1'], 'demandes' => ['Demandes directeur', 'u.demande_en_cours = 1 AND u.is_directeur = 0'], 'directeurs' => ['Directeurs', 'u.is_directeur = 1'], 'animateurs' => ['Animateurs', 'u.is_animateur = 1'], 'admins' => ['Administrateurs', 'u.is_admin = 1'], 'nonverifies' => ['Non vérifiés', 'u.is_verified = 0']],
        'order' => 'u.id DESC',
        'actions' => [
            ['user.accept', 'Accepter comme directeur', 'user-check', 'ok', fn($r) => ($r['demande_en_cours'] ?? 0) == 1, false, ''],
            ['user.deny', 'Refuser la demande', 'x', 'warn', fn($r) => ($r['demande_en_cours'] ?? 0) == 1, false, 'Refuser la demande directeur de cet utilisateur ?'],
            ['user.revoke', 'Retirer le rôle directeur', 'x', 'warn', fn($r) => ($r['is_directeur'] ?? 0) == 1 && ($r['demande_en_cours'] ?? 0) != 1, false, 'Retirer le rôle directeur ?'],
            ['user.verify', 'Marquer comme vérifié', 'check', 'ok', fn($r) => ($r['is_verified'] ?? 1) == 0, false, ''],
            ['user.admin', 'Basculer administrateur', 'key', 'warn', fn($r) => true, true, 'Accorder ou retirer les droits administrateur ? Action sensible.'],
            ['row.delete', 'Supprimer', 'trash', 'danger', fn($r) => ($r['is_admin'] ?? 0) != 1, true, 'Supprimer définitivement ce compte ?'],
        ],
    ],
    'organismes' => [
        'label' => 'Organismes', 'icon' => 'building', 'table' => 'organisateurs', 'pk' => 'id', 'alias' => 'o', 'edit' => true,
        'select' => "o.id, o.nom, o.email, o.tel, o.web, o.portefeuille, o.solde_points, CONCAT_WS(' ', u.prenom, u.nom) AS responsable",
        'from' => "organisateurs o LEFT JOIN users u ON u.id = o.user_id",
        'cols' => ['id' => ['ID', 'id'], 'nom' => ['Organisme', 'name'], 'responsable' => ['Responsable', 'text'], 'email' => ['Email', 'text'], 'portefeuille' => ['Portefeuille', 'money'], 'solde_points' => ['Points', 'int']],
        'search' => ['o.nom', 'o.email', 'o.tel', 'u.nom', 'u.prenom'],
        'filters' => ['all' => ['Tous', '1=1'], 'solde' => ['Avec solde', 'o.portefeuille > 0']],
        'order' => 'o.id DESC',
        'actions' => [['row.delete', 'Supprimer', 'trash', 'danger', fn($r) => true, true, 'Supprimer définitivement cet organisme ?']],
    ],
    'camps' => [
        'label' => 'Séjours', 'icon' => 'tent', 'table' => 'camps', 'pk' => 'id', 'alias' => 'c', 'edit' => true,
        'select' => "c.id, c.nom, c.ville, c.code_postal, c.prix, c.date_debut, c.date_fin, c.valide, c.en_attente, c.refuse, c.prive, c.supprime, c.token, o.nom AS organisateur",
        'from' => "camps c LEFT JOIN organisateurs o ON o.id = c.organisateur_id",
        'cols' => ['id' => ['ID', 'id'], 'nom' => ['Séjour', 'camp'], 'organisateur' => ['Organisme', 'text'], 'ville' => ['Ville', 'text'], 'date_debut' => ['Début', 'date0'], 'prix' => ['Prix', 'money'], 'etat' => ['État', 'campstate']],
        'search' => ['c.nom', 'c.ville', 'c.code_postal', 'o.nom'],
        'filters' => ['all' => ['Tous', 'c.supprime = 0'], 'attente' => ['En attente de validation', 'c.en_attente = 1 AND c.valide = 0 AND c.refuse = 0 AND c.supprime = 0'], 'valides' => ['Validés', 'c.valide = 1 AND c.supprime = 0'], 'refuses' => ['Refusés', 'c.refuse = 1 AND c.supprime = 0'], 'prives' => ['Masqués', 'c.prive = 1 AND c.supprime = 0'], 'supprimes' => ['Supprimés', 'c.supprime = 1']],
        'order' => 'c.id DESC',
        'actions' => [
            ['camp.approve', 'Valider', 'check', 'ok', fn($r) => ($r['valide'] ?? 0) != 1 && ($r['supprime'] ?? 0) != 1, false, ''],
            ['camp.deny', 'Refuser', 'x', 'warn', fn($r) => ($r['refuse'] ?? 0) != 1 && ($r['supprime'] ?? 0) != 1, false, 'Refuser ce séjour ?'],
            ['camp.requeue', 'Remettre en attente', 'refresh', '', fn($r) => ($r['refuse'] ?? 0) == 1 || ($r['valide'] ?? 0) == 1, false, ''],
            ['camp.hide', 'Masquer / afficher', 'eye', '', fn($r) => ($r['supprime'] ?? 0) != 1, false, ''],
            ['camp.restore', 'Restaurer', 'refresh', 'ok', fn($r) => ($r['supprime'] ?? 0) == 1, false, ''],
            ['camp.delete', 'Supprimer', 'trash', 'danger', fn($r) => ($r['supprime'] ?? 0) != 1, true, 'Supprimer ce séjour ? Il sera retiré du site (suppression douce, restaurable).'],
        ],
    ],
    'inscriptions' => [
        'label' => 'Inscriptions', 'icon' => 'ticket', 'table' => 'inscriptions', 'pk' => 'id', 'alias' => 'i', 'edit' => true,
        'select' => "i.id, i.statut, i.statut_paiement, i.prix_final, i.montant_paye, i.mode_paiement, c.nom AS camp, CONCAT_WS(' ', e.prenom, e.nom) AS enfant",
        'from' => "inscriptions i LEFT JOIN camps c ON c.id = i.camp_id LEFT JOIN enfants e ON e.id = i.enfant_id",
        'cols' => ['id' => ['ID', 'id'], 'enfant' => ['Participant', 'name'], 'camp' => ['Séjour', 'text'], 'statut' => ['Statut', 'status'], 'statut_paiement' => ['Paiement', 'status'], 'prix_final' => ['Prix', 'money'], 'montant_paye' => ['Payé', 'money']],
        'search' => ['c.nom', 'e.nom', 'e.prenom', 'i.statut', 'i.statut_paiement'],
        'filters' => ['all' => ['Toutes', '1=1'], 'attente' => ['En attente', "i.statut = 'En attente'"], 'confirmees' => ['Confirmées', "i.statut = 'Confirmé'"], 'impayees' => ['Non payées', "i.statut_paiement <> 'PAYE'"]],
        'order' => 'i.id DESC',
        'actions' => [['row.delete', 'Supprimer', 'trash', 'danger', fn($r) => true, true, 'Supprimer définitivement cette inscription ?']],
    ],
    'paiements' => [
        'label' => 'Paiements', 'icon' => 'card', 'table' => 'inscriptions', 'pk' => 'id', 'alias' => 'i', 'edit' => false,
        'select' => "i.id, i.montant_paye, i.mode_paiement, i.statut_paiement, c.nom AS camp, CONCAT_WS(' ', e.prenom, e.nom) AS enfant, o.nom AS organisateur",
        'from' => "inscriptions i LEFT JOIN camps c ON c.id = i.camp_id LEFT JOIN enfants e ON e.id = i.enfant_id LEFT JOIN organisateurs o ON o.id = c.organisateur_id",
        'cols' => ['id' => ['Inscr.', 'id'], 'enfant' => ['Participant', 'name'], 'camp' => ['Séjour', 'text'], 'organisateur' => ['Organisme', 'text'], 'mode_paiement' => ['Mode', 'text'], 'montant_paye' => ['Montant', 'money'], 'statut_paiement' => ['Statut', 'status']],
        'search' => ['c.nom', 'e.nom', 'e.prenom', 'o.nom'],
        'filters' => ['payes' => ['Payés', "i.statut_paiement = 'PAYE' AND i.montant_paye > 0"], 'all' => ['Tous', '1=1'], 'carte' => ['Carte', "i.mode_paiement = 'CARTE'"], 'attente' => ['En attente', "i.statut_paiement = 'EN_ATTENTE'"]],
        'order' => 'i.id DESC', 'actions' => [],
    ],
    'virements' => [
        'label' => 'Demandes de virement', 'icon' => 'bank', 'table' => 'virements', 'pk' => 'id', 'alias' => 'v', 'edit' => false,
        'select' => "v.id, v.token, v.montant_total, v.commission_rate, v.montant_apres_commission, v.nom_organisme, v.email_organisme, v.date_demande, v.date_virement_estime, v.date_virement_effectue, v.effectue",
        'from' => "virements v",
        'cols' => ['id' => ['ID', 'id'], 'nom_organisme' => ['Organisme', 'name'], 'montant_total' => ['Brut', 'money'], 'montant_apres_commission' => ['Net à virer', 'money'], 'commission' => ['Commission', 'money'], 'date_demande' => ['Demandé le', 'date'], 'effectue' => ['Statut', 'done']],
        'search' => ['v.nom_organisme', 'v.email_organisme'],
        'filters' => ['attente' => ['À traiter', 'v.effectue = 0'], 'faits' => ['Effectués', 'v.effectue = 1'], 'all' => ['Tous', '1=1']],
        'order' => 'v.effectue ASC, v.date_demande ASC',
        'actions' => [
            ['virement.iban', 'Afficher les coordonnées bancaires', 'eye', '', fn($r) => ($r['effectue'] ?? 0) == 0, false, ''],
            ['virement.done', 'Marquer comme effectué', 'check', 'ok', fn($r) => ($r['effectue'] ?? 0) == 0, true, 'Confirmer que ce virement a bien été effectué ?'],
        ],
    ],
    'boosts' => [
        'label' => 'Boosts', 'icon' => 'bolt', 'table' => 'camps', 'pk' => 'id', 'alias' => 'c', 'edit' => false,
        'select' => "c.id, c.nom, c.ville, c.date_bump, c.boost_vedette_fin, c.boost_urgence_fin, o.nom AS organisateur, o.solde_points",
        'from' => "camps c LEFT JOIN organisateurs o ON o.id = c.organisateur_id",
        'cols' => ['id' => ['ID', 'id'], 'nom' => ['Séjour', 'name'], 'organisateur' => ['Organisme', 'text'], 'boost_vedette_fin' => ['Vedette jusqu\'au', 'future'], 'boost_urgence_fin' => ['Urgence jusqu\'au', 'future'], 'date_bump' => ['Dernière remontée', 'date']],
        'search' => ['c.nom', 'c.ville', 'o.nom'],
        'filters' => ['actifs' => ['Boosts actifs', 'c.supprime = 0 AND (c.boost_vedette_fin > NOW() OR c.boost_urgence_fin > NOW())'], 'all' => ['Tous les séjours', 'c.supprime = 0']],
        'order' => 'c.id DESC',
        'actions' => [
            ['boost.vedette', '+7 jours en vedette', 'star', 'ok', fn($r) => true, false, ''],
            ['boost.urgence', '+3 jours en urgence', 'alert', 'ok', fn($r) => true, false, ''],
            ['boost.bump', 'Remonter maintenant', 'refresh', '', fn($r) => true, false, ''],
            ['boost.clear', 'Retirer les boosts', 'x', 'warn', fn($r) => true, false, 'Retirer tous les boosts de ce séjour ?'],
        ],
    ],
    'contacts' => [
        'label' => 'Messages de contact', 'icon' => 'mail', 'table' => 'contact_messages', 'pk' => 'id', 'alias' => 'm', 'edit' => false,
        'select' => "m.id, m.nom, m.prenom, m.email, m.motif, m.message, m.statut, m.reponse, m.replied_at",
        'from' => "contact_messages m",
        'cols' => ['id' => ['ID', 'id'], 'nom' => ['De', 'name'], 'motif' => ['Motif', 'text'], 'message' => ['Message', 'long'], 'statut' => ['Statut', 'status']],
        'search' => ['m.nom', 'm.prenom', 'm.email', 'm.motif', 'm.message'],
        'filters' => ['attente' => ['En attente', "m.statut = 'En attente'"], 'traites' => ['Traités', "m.statut = 'Traité'"], 'all' => ['Tous', '1=1']],
        'order' => 'm.id DESC',
        'actions' => [
            ['contact.reply', 'Répondre', 'mail', 'ok', fn($r) => ($r['statut'] ?? '') === 'En attente', false, ''],
            ['row.delete', 'Supprimer', 'trash', 'danger', fn($r) => true, true, 'Supprimer définitivement ce message ?'],
        ],
    ],
    ];
}
