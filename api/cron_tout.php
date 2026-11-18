<?php
// api/cron_tout.php : UN seul cron (toutes les heures) qui lance les trois tâches planifiées, quand l'hébergement limite le nombre de crons.
//   HTTP : https://moncamp.fr/api/cron_tout.php?key=CRON_SECRET   (même clé que les autres crons)
// Chaque tâche sait déjà ne rien faire quand ce n'est pas son heure (virements : séjours éligibles ; alertes : fréquence de chaque animateur ;
// purge RGPD : seuls les dossiers échus sont touchés), donc les lancer toutes les heures est sans risque.
require_once 'config.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $key = (string)($_GET['key'] ?? '');
    if (!defined('CRON_SECRET') || CRON_SECRET === '' || !hash_equals((string)CRON_SECRET, $key)) { http_response_code(403); exit('interdit'); }
    header('Content-Type: application/json');
}

function cron_tout_run(string $file) {
    global $pdo;                       // les scripts incluses retrouvent $pdo ici (leur require_once 'config.php' est sans effet)
    ob_start();
    $t0 = microtime(true); $ok = true;
    try { include __DIR__ . '/' . $file; } catch (Throwable $e) { $ok = false; error_log('cron_tout ' . $file . ': ' . $e->getMessage()); echo '{"erreur":"echec"}'; }
    $out = trim((string)ob_get_clean());
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS adm_kv (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL) DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO adm_kv (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
            ->execute(['cron:' . basename($file, '.php'), mb_substr(json_encode(['at' => date('Y-m-d H:i:s'), 'ms' => (int)((microtime(true) - $t0) * 1000), 'ok' => $ok && stripos($out, 'erreur') === false, 'out' => mb_substr($out, 0, 140)], JSON_UNESCAPED_UNICODE), 0, 255)]);
    } catch (Throwable $e) { error_log('cron_tout log: ' . $e->getMessage()); }
    return $out;
}

$out = [];
foreach (['cron_virements.php', 'cron_rgpd.php', 'cron_alertes_animateur.php'] as $f) $out[$f] = cron_tout_run($f);
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
