<?php
const MC_LANGS = ['fr', 'en'];

function mc_lang(): string {
    static $l = null;
    if ($l !== null) return $l;
    $g = $_GET['lang'] ?? '';
    if (in_array($g, MC_LANGS, true)) { mc_lang_save($g); return $l = $g; }
    $c = $_COOKIE['mc_lang'] ?? '';
    if (in_array($c, MC_LANGS, true)) return $l = $c;
    $u = $_SESSION['user']['lang'] ?? '';
    return $l = in_array($u, MC_LANGS, true) ? $u : 'fr';
}

function mc_lang_save(string $l): void {
    if (!headers_sent()) setcookie('mc_lang', $l, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    $_COOKIE['mc_lang'] = $l;
    $id = (int)($_SESSION['user']['id'] ?? 0);
    if (!$id || ($_SESSION['user']['lang'] ?? '') === $l) return;
    try {
        require_once __DIR__ . '/config.php';
        global $pdo;
        if (!isset($pdo)) return;
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'lang'")->fetch()) $pdo->exec("ALTER TABLE users ADD COLUMN lang CHAR(2) NULL");
        $pdo->prepare('UPDATE users SET lang = ? WHERE id = ?')->execute([$l, $id]);
        $_SESSION['user']['lang'] = $l;
    } catch (Throwable $e) { error_log('mc_lang_save: ' . $e->getMessage()); }
}

function mc_key(string $s): string { return preg_replace('/\s+/u', ' ', trim(str_replace('’', "'", $s))); }

function mc_dict(?string $lang = null): array {
    static $d = [];
    $lang = $lang ?? mc_lang();
    if (!isset($d[$lang])) foreach ((array)(require __DIR__ . '/../lang/' . $lang . '.php') as $k => $v) $d[$lang][mc_key($k)] = $v;
    return $d[$lang] ?? [];
}

function t(string $s, array $vars = []): string {
    $k = mc_key($s);
    $r = mc_dict()[$k] ?? mc_dict('fr')[$k] ?? $s;
    foreach ($vars as $k => $v) $r = str_replace('{' . $k . '}', (string)$v, $r);
    return $r;
}

function tj(string $s, array $vars = []): string {
    return json_encode(t($s, $vars), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function mc_locale(): string { return t('locale'); }

function mc_money($v, int $dec = 2): string {
    $v = (float)$v;
    return mc_lang() === 'en' ? '€' . number_format($v, $dec, '.', ',') : number_format($v, $dec, ',', "\u{202F}") . "\u{00A0}€";
}

function mc_date(?string $d, bool $year = true): string {
    if (!$d || !($ts = strtotime($d))) return '';
    $m = explode(',', t('months.short'));
    return (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts) - 1] . ($year ? ' ' . date('Y', $ts) : '');
}

function mc_url_lang(string $l): string {
    $q = $_GET;
    unset($q['lang']);
    if ($l !== '' && $l !== 'fr') $q['lang'] = $l;
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $host = $_SERVER['HTTP_HOST'] ?? 'moncamp.fr';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . $host . $path . ($q ? '?' . http_build_query($q) : '');
}

function mc_switch_url(string $l): string {
    $q = $_GET;
    $q['lang'] = $l;
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?' . http_build_query($q);
}

function mc_hreflang(): string {
    $o = '';
    foreach (MC_LANGS as $l) $o .= '<link rel="alternate" hreflang="' . $l . '" href="' . htmlspecialchars(mc_url_lang($l)) . '">';
    return $o . '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars(mc_url_lang('fr')) . '">';
}

function mc_i18n_fmt(string $s): string {
    $s = preg_replace_callback('/(?<![\d,.])(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})*)(?:,(\d{2}))?[ \x{00A0}\x{202F}]?€/u', fn($m) => '€' . preg_replace('/[ \x{00A0}\x{202F}]/u', ',', $m[1]) . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : ''), $s);
    static $mois = null;
    $mois ??= array_combine(explode(',', mc_dict('fr')['months.re']), explode(',', mc_dict()['months.re']));
    return preg_replace_callback('/(?<![\p{L}\d])(?:(\d{1,2})(?:er)? )?(' . implode('|', array_map(fn($x) => preg_quote($x, '/'), array_keys($mois))) . ')(?=[\s,.)–-]|$)/u', fn($m) => ($m[1] !== '' ? $m[1] . ' ' : '') . $mois[$m[2]], $s);
}

function mc_i18n_pur(string $s): bool {
    $mois = implode('|', array_map(fn($m) => preg_quote($m, '/'), explode(',', mc_dict('fr')['months.re'])));
    return preg_replace(['/' . $mois . '/u', '/[\d\s.,€–→\/:()·h-]/u'], '', $s) === '';
}

function mc_i18n_pattern(string $k): ?string {
    static $p = null;
    if ($p === null) {
        $p = [];
        foreach (mc_dict() as $key => $v) if (str_contains($key, '{')) $p[] = ['/^' . preg_replace_callback('/\\\{(\w+)\\\}/', fn($x) => match ($x[1][0]) { 'n' => '(\d[\d .,\x{00A0}\x{202F}–-]*)', 'd' => '(\d\S*(?: \S+){0,2})', default => '(.{1,40}?)' }, preg_quote($key, '/')) . '$/u', $v, preg_match_all('/\{\w+\}/', $key, $n) ? $n[0] : []];
    }
    foreach ($p as [$re, $v, $noms]) if (preg_match($re, $k, $m)) {
        foreach ($noms as $i => $n) $v = str_replace($n, mc_dict()[mc_key($m[$i + 1])] ?? mc_i18n_fmt($m[$i + 1]), $v);
        return $v;
    }
    return null;
}

function mc_i18n_out(string $html): string {
    if ($html === '' || mc_lang() === 'fr') return $html;
    $d = mc_dict();
    $norm = fn(string $s): string => mc_key(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $html = preg_replace_callback('/>([^<>]*\p{L}[^<>]*)</u', function ($m) use ($d, $norm) {
        $k = $norm($m[1]);
        if (isset($d[$k])) {
            preg_match('/^\s*/u', $m[1], $a);
            preg_match('/\s*$/u', $m[1], $b);
            return '>' . $a[0] . htmlspecialchars($d[$k], ENT_NOQUOTES) . $b[0] . '<';
        }
        if ($r = mc_i18n_pattern($k)) return '>' . htmlspecialchars($r, ENT_NOQUOTES) . '<';
        return mc_i18n_pur($k) ? '>' . mc_i18n_fmt($m[1]) . '<' : $m[0];
    }, $html) ?? $html;
    return preg_replace_callback('/\b(placeholder|title|aria-label|alt|data-tip)="([^"<>]*\p{L}[^"<>]*)"/u', function ($m) use ($d, $norm) {
        $k = $norm($m[2]);
        return isset($d[$k]) ? $m[1] . '="' . htmlspecialchars($d[$k], ENT_QUOTES) . '"' : $m[0];
    }, $html) ?? $html;
}
