<?php
// api/security.php : briques de sécurité partagées.
//  - limitation des tentatives (connexion, inscription, code de validation, réinitialisation) : par IP et par identifiant (haché)
//  - jeton CSRF pour les formulaires qui modifient des données
//  - journal des consentements (RGPD : preuve de ce qui a été accepté, quand, quelle version du texte)
//  - journal des accès aux documents de santé (qui a ouvert quoi)
//  - CAPTCHA Cloudflare Turnstile, activé seulement si TURNSTILE_SITE_KEY et TURNSTILE_SECRET sont définis dans api/config.php
// Les tables sont créées automatiquement (idempotent).

const CGU_VERSION = '2026-11';   // à changer quand les CGU / la politique de confidentialité changent

function sec_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sec_tentatives (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL, cle CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_lookup (type, cle, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS consentements (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, type VARCHAR(30) NOT NULL, version VARCHAR(20) NOT NULL,
            accepte TINYINT(1) NOT NULL DEFAULT 1, ip_hash CHAR(64) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS acces_sante (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, enfant_id INT NULL, type VARCHAR(12) NOT NULL,
            ip_hash CHAR(64) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_enfant (enfant_id), KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (mt_rand(1, 40) === 1) $pdo->exec("DELETE FROM sec_tentatives WHERE created_at < (NOW() - INTERVAL 2 DAY)");
    } catch (Throwable $e) { error_log('sec_ensure: ' . $e->getMessage()); }
    $done = true;
}

function sec_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
/** Haché pour ne pas garder d'adresse IP ni d'e-mail en clair dans ces journaux */
function sec_hash(string $v): string { return hash('sha256', strtolower(trim($v)) . '|colomap'); }

/** Trop de tentatives récentes pour cette clé ? */
function sec_blocked(PDO $pdo, string $type, string $key, int $max, int $minutes): bool {
    try {
        sec_ensure($pdo);
        $st = $pdo->prepare("SELECT COUNT(*) FROM sec_tentatives WHERE type = ? AND cle = ? AND created_at > (NOW() - INTERVAL " . (int)$minutes . " MINUTE)");
        $st->execute([$type, sec_hash($key)]);
        return (int)$st->fetchColumn() >= $max;
    } catch (Throwable $e) { return false; }   // en cas de panne du journal, on ne bloque pas les gens
}
function sec_hit(PDO $pdo, string $type, string $key): void {
    try { sec_ensure($pdo); $pdo->prepare("INSERT INTO sec_tentatives (type, cle) VALUES (?, ?)")->execute([$type, sec_hash($key)]); } catch (Throwable $e) {}
}
function sec_clear(PDO $pdo, string $type, string $key): void {
    try { $pdo->prepare("DELETE FROM sec_tentatives WHERE type = ? AND cle = ?")->execute([$type, sec_hash($key)]); } catch (Throwable $e) {}
}

// ---------------- CSRF ----------------
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token()) . '">'; }
function csrf_check(): bool {
    $sent = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

// ---------------- Consentements et accès santé ----------------
function consent_log(PDO $pdo, ?int $userId, string $type, ?string $version = null, bool $accepte = true): void {
    try {
        sec_ensure($pdo);
        $pdo->prepare("INSERT INTO consentements (user_id, type, version, accepte, ip_hash) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, substr($type, 0, 30), $version ?? CGU_VERSION, $accepte ? 1 : 0, sec_hash(sec_ip())]);
    } catch (Throwable $e) {}
}
function sante_log(PDO $pdo, int $userId, ?int $enfantId, string $type): void {
    try {
        sec_ensure($pdo);
        $pdo->prepare("INSERT INTO acces_sante (user_id, enfant_id, type, ip_hash) VALUES (?, ?, ?, ?)")->execute([$userId, $enfantId, substr($type, 0, 12), sec_hash(sec_ip())]);
    } catch (Throwable $e) {}
}

// ---------------- Cloudflare Turnstile (facultatif) ----------------
function sec_turnstile_enabled(): bool {
    return defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY !== '' && defined('TURNSTILE_SECRET') && TURNSTILE_SECRET !== '';
}
function sec_turnstile_widget(): string {
    if (!sec_turnstile_enabled()) return '';
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
         . '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars(TURNSTILE_SITE_KEY) . '" data-language="fr"></div>';
}
/** Vrai si le CAPTCHA est désactivé (pas de clés) ou si la réponse est valide */
function sec_turnstile_ok(?string $response = null): bool {
    if (!sec_turnstile_enabled()) return true;
    $response = $response ?? (string)($_POST['cf-turnstile-response'] ?? '');
    if ($response === '') return false;
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 6, 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['secret' => TURNSTILE_SECRET, 'response' => $response, 'remoteip' => sec_ip()])]]);
    $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) && !empty($j['success']);
}
