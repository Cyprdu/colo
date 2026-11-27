<?php
// api/totp_lib.php : double authentification de l'administration (TOTP RFC 6238, compatible Google Authenticator) et codes de secours.
// Obligatoire pour l'administrateur suprême et pour tout compte ayant un droit Comptabilité, Paiements, Versements ou Technique.
if (defined('TOTP_LIB')) return;
define('TOTP_LIB', 1);

function tfa_b32(string $bin): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $o = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $g) $o .= $a[bindec(str_pad($g, 5, '0'))];
    return $o;
}
function tfa_unb32(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $o = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $g) if (strlen($g) === 8) $o .= chr(bindec($g));
    return $o;
}
function tfa_code(string $secretB32, int $pas): string {
    $h = hash_hmac('sha1', pack('J', $pas), tfa_unb32($secretB32), true);
    $o = ord($h[19]) & 0xf;
    return str_pad((string)((unpack('N', substr($h, $o, 4))[1] & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT);
}

function tfa_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_2fa (user_id INT NOT NULL PRIMARY KEY, secret VARCHAR(255) NOT NULL, dernier_pas BIGINT NOT NULL DEFAULT 0, secours TEXT NOT NULL,
        active_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, utilise_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
}

function tfa_cle(): string { return hash('sha256', adm_key('2fa') . '|totp', true); }
function tfa_chiffre(string $s): string { $iv = random_bytes(12); $c = openssl_encrypt($s, 'aes-256-gcm', tfa_cle(), OPENSSL_RAW_DATA, $iv, $t); return base64_encode($iv . $t . $c); }
function tfa_dechiffre(string $s): string { $b = base64_decode($s); return (string)openssl_decrypt(substr($b, 28), 'aes-256-gcm', tfa_cle(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16)); }

/** Droits qui imposent la double authentification */
function tfa_requis(array $perms): bool {
    if ($perms === ['*']) return true;
    foreach ($perms as $p) if (preg_match('/^(compta|paiements|versements|tech)\./', $p)) return true;
    return false;
}

function tfa_ok(int $uid): bool {
    static $c = [];
    if ($uid <= 0 || (int)($_SESSION['adm_2fa'] ?? 0) !== $uid) return false;
    return $c[$uid] ??= (bool)tfa_compte($GLOBALS['pdo'], $uid);
}

function tfa_compte(PDO $pdo, int $uid): ?array {
    tfa_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM adm_2fa WHERE user_id = ?");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 10 codes de secours : renvoie les codes en clair (à montrer une fois) et leurs empreintes. */
function tfa_codes_secours(): array {
    $c = []; for ($i = 0; $i < 10; $i++) $c[] = implode('-', str_split(strtolower(tfa_b32(random_bytes(5))), 4));
    return [$c, array_map(fn($x) => hash('sha256', str_replace('-', '', $x)), $c)];
}

function tfa_attente(PDO $pdo, int $uid, bool $neuf = false): string {
    $k = '2fa_attente_' . $uid;
    if (!$neuf) {
        $st = $pdo->prepare("SELECT v FROM adm_kv WHERE k = ?"); $st->execute([$k]);
        $v = (string)$st->fetchColumn();
        if ($v !== '' && ($s = tfa_dechiffre($v)) !== '') return $s;
    }
    $s = tfa_b32(random_bytes(20));
    $pdo->prepare("INSERT INTO adm_kv (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, tfa_chiffre($s)]);
    return $s;
}

function tfa_activer(PDO $pdo, int $uid, string $secret): array {
    tfa_ensure($pdo);
    [$clair, $h] = tfa_codes_secours();
    $pdo->prepare("REPLACE INTO adm_2fa (user_id, secret, dernier_pas, secours, active_at) VALUES (?, ?, ?, ?, NOW())")->execute([$uid, tfa_chiffre($secret), intdiv(time(), 30), json_encode($h)]);
    $pdo->prepare("DELETE FROM adm_kv WHERE k = ?")->execute(['2fa_attente_' . $uid]);
    return $clair;
}

/** Vérifie un code TOTP (±30 s, jamais deux fois le même) ou un code de secours (à usage unique). */
function tfa_verifier(PDO $pdo, int $uid, string $code): bool {
    $c = tfa_compte($pdo, $uid);
    if (!$c) return false;
    $code = strtolower(preg_replace('/[\s-]/', '', $code));
    if (preg_match('/^\d{6}$/', $code)) {
        $s = tfa_dechiffre($c['secret']); $now = intdiv(time(), 30);
        foreach ([-2, -1, 0, 1, 2] as $d) {
            $pas = $now + $d;
            if ($pas > (int)$c['dernier_pas'] && hash_equals(tfa_code($s, $pas), $code)) {
                $st = $pdo->prepare("UPDATE adm_2fa SET dernier_pas = ?, utilise_at = NOW() WHERE user_id = ? AND dernier_pas < ?");
                $st->execute([$pas, $uid, $pas]);
                return $st->rowCount() === 1;
            }
        }
        return false;
    }
    $h = json_decode($c['secours'], true) ?: [];
    $i = array_search(hash('sha256', $code), $h, true);
    if ($i === false) return false;
    unset($h[$i]);
    $pdo->prepare("UPDATE adm_2fa SET secours = ?, utilise_at = NOW() WHERE user_id = ?")->execute([json_encode(array_values($h)), $uid]);
    return true;
}
