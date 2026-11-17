<?php
// api/cron_tout.php : UN seul cron (toutes les heures) qui lance les trois tâches planifiées, quand l'hébergement limite le nombre de crons.
//   HTTP : https://moncamp.fr/api/cron_tout.php?key=CRON_SECRET   (même clé que les autres crons)
// Chaque tâche sait déjà ne rien faire quand ce n'est pas son heure (virements : jour de versement ; alertes : fréquence de chaque animateur ;
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
    try { include __DIR__ . '/' . $file; } catch (Throwable $e) { error_log('cron_tout ' . $file . ': ' . $e->getMessage()); echo '{"erreur":"echec"}'; }
    return trim((string)ob_get_clean());
}

$out = [];
foreach (['cron_virements.php', 'cron_rgpd.php', 'cron_alertes_animateur.php'] as $f) $out[$f] = cron_tout_run($f);
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
