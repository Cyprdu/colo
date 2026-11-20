<?php
// api/sauvegarde_lib.php : sauvegarde SQL quotidienne compressée, hors du dossier web, chiffrée si HEALTH_KEY existe.
// Rotation : 7 quotidiennes + 4 hebdomadaires (copie du dimanche). Procédure de restauration : docs/restauration.md.
// Déchiffrer hors du serveur : php api/sauvegarde_lib.php dechiffrer <fichier.enc> <sortie.sql.gz> <HEALTH_KEY>
const BK_MAGIC = 'MCBK1';
const BK_CHUNK = 1048576;

function bk_dir(): string {
    $d = defined('BACKUP_DIR') ? BACKUP_DIR : dirname(__DIR__, 2) . '/sauvegardes_moncamp';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n");
    return rtrim(str_replace('\\', '/', $d), '/');
}

function bk_cle(string $hex): string { return hash_hmac('sha256', 'sauvegarde', (string)hex2bin($hex), true); }

/** Export SQL par lots via PDO (structure + données), écrit par $w. */
function bk_dump_pdo(PDO $pdo, callable $w): void {
    $w("-- moncamp.fr sauvegarde " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        foreach ($tables as $t) {
            $q = '`' . str_replace('`', '``', $t) . '`';
            $c = $pdo->query("SHOW CREATE TABLE $q"); $create = $c->fetchColumn(1); $c->closeCursor();
            $w("\nDROP TABLE IF EXISTS $q;\n$create;\n");
            $st = $pdo->query("SELECT * FROM $q");
            $buf = [];
            while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
                $buf[] = '(' . implode(',', array_map(fn($x) => $x === null ? 'NULL' : $pdo->quote((string)$x), $r)) . ')';
                if (count($buf) >= 200) { $w("INSERT INTO $q VALUES " . implode(",\n", $buf) . ";\n"); $buf = []; }
            }
            $st->closeCursor();
            if ($buf) $w("INSERT INTO $q VALUES " . implode(",\n", $buf) . ";\n");
        }
    } finally { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered); }
    $w("SET FOREIGN_KEY_CHECKS=1;\n");
}

/** mysqldump (si exécutable) vers le fichier gzip. false si indisponible ou en échec. */
function bk_mysqldump(string $gz): bool {
    $off = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('proc_open') || in_array('proc_open', $off, true) || !defined('DB_NAME')) return false;
    $bin = defined('MYSQLDUMP_BIN') ? MYSQLDUMP_BIN : 'mysqldump';
    $cmd = [$bin, '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4', '-h', DB_HOST, '-u', DB_USER, DB_NAME];
    $env = getenv();
    $env['MYSQL_PWD'] = DB_PASS;
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) return false;
    $z = gzopen($gz, 'wb6'); $n = 0;
    while (!feof($pipes[1])) { $s = fread($pipes[1], BK_CHUNK); if ($s !== false && $s !== '') { $n += strlen($s); gzwrite($z, $s); } }
    gzclose($z);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0 || $n < 100) { error_log('sauvegarde mysqldump: ' . mb_substr((string)$err, 0, 300)); @unlink($gz); return false; }
    return true;
}

function bk_chiffrer(string $src, string $dst, string $hex): void {
    $k = bk_cle($hex); $in = fopen($src, 'rb'); $out = fopen($dst, 'wb');
    fwrite($out, BK_MAGIC);
    while (($s = fread($in, BK_CHUNK)) !== false && $s !== '') {
        $iv = random_bytes(12);
        $c = openssl_encrypt($s, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        fwrite($out, pack('N', strlen($c)) . $iv . $tag . $c);
    }
    fclose($in); fclose($out);
}

function bk_dechiffrer(string $src, string $dst, string $hex): void {
    $k = bk_cle($hex); $in = fopen($src, 'rb'); $out = fopen($dst, 'wb');
    if (fread($in, strlen(BK_MAGIC)) !== BK_MAGIC) throw new RuntimeException('Fichier non reconnu.');
    while (($h = fread($in, 4)) !== false && strlen($h) === 4) {
        $n = unpack('N', $h)[1]; $iv = fread($in, 12); $tag = fread($in, 16);
        $p = openssl_decrypt(fread($in, $n), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        if ($p === false) throw new RuntimeException('Clé incorrecte ou fichier altéré.');
        fwrite($out, $p);
    }
    fclose($in); fclose($out);
}

/** Liste des sauvegardes, plus récentes d'abord : [nom, taille, date] */
function bk_liste(): array {
    $o = [];
    foreach (glob(bk_dir() . '/{quotidien,hebdo}-*.sql.gz*', GLOB_BRACE) ?: [] as $f) $o[] = ['nom' => basename($f), 'taille' => filesize($f), 'date' => filemtime($f)];
    usort($o, fn($a, $b) => $b['date'] <=> $a['date']);
    return $o;
}

function bk_chemin(string $nom): ?string {
    if (!preg_match('/^(quotidien-\d{4}-\d{2}-\d{2}|hebdo-\d{4}-W\d{2})\.sql\.gz(\.enc)?$/', $nom)) return null;
    $p = bk_dir() . '/' . $nom;
    return is_file($p) ? $p : null;
}

/** Sauvegarde du jour + rotation. Retourne le nom du fichier créé. */
function bk_faire(PDO $pdo): array {
    @set_time_limit(900);
    $dir = bk_dir();
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException("Dossier de sauvegarde inaccessible : $dir");
    $gz = $dir . '/quotidien-' . date('Y-m-d') . '.sql.gz';
    $methode = 'mysqldump';
    if (!bk_mysqldump($gz)) {
        $methode = 'pdo';
        $z = gzopen($gz, 'wb6');
        bk_dump_pdo($pdo, fn($s) => gzwrite($z, $s));
        gzclose($z);
    }
    $final = $gz;
    if (defined('HEALTH_KEY') && preg_match('/^[a-f0-9]{64}$/i', (string)HEALTH_KEY)) {
        bk_chiffrer($gz, $gz . '.enc', HEALTH_KEY);
        unlink($gz);
        $final = $gz . '.enc';
    }
    @chmod($final, 0600);
    if (date('N') === '7') copy($final, $dir . '/hebdo-' . date('o-\WW') . substr($final, strpos($final, '.sql')));
    foreach (['quotidien' => 7, 'hebdo' => 4] as $type => $garder) {
        $f = glob("$dir/$type-*.sql.gz*") ?: [];
        rsort($f);
        foreach (array_slice($f, $garder) as $x) @unlink($x);
    }
    return ['fichier' => basename($final), 'taille' => filesize($final), 'methode' => $methode];
}

/** Cron horaire : une sauvegarde par jour, à partir de 3 h. */
function bk_cron(PDO $pdo): ?array {
    require_once __DIR__ . '/surveillance_lib.php';
    if ((int)date('G') < 3 || sv_kv($pdo, 'sauvegarde:jour') === date('Y-m-d')) return null;
    $r = bk_faire($pdo);
    sv_kv($pdo, 'sauvegarde:jour', date('Y-m-d'));
    sv_kv($pdo, 'sauvegarde:derniere', (string)time());
    return $r;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (($argv[1] ?? '') !== 'dechiffrer' || count($argv) !== 5) { fwrite(STDERR, "Usage : php api/sauvegarde_lib.php dechiffrer <fichier.enc> <sortie.sql.gz> <HEALTH_KEY>\n"); exit(1); }
    bk_dechiffrer($argv[2], $argv[3], $argv[4]);
    echo "Déchiffré : {$argv[3]}\n";
}
