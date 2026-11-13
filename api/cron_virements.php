<?php
// api/cron_virements.php : job de déblocage des fonds (à lancer toutes les heures par un cron / tâche planifiée).
//   CLI :  php api/cron_virements.php
//   HTTP : https://moncamp.fr/api/cron_virements.php?key=CRON_SECRET   (clé définie dans api/config.php)
require_once 'config.php';
require_once 'finance_lib.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $key = (string)($_GET['key'] ?? '');
    if (!defined('CRON_SECRET') || CRON_SECRET === '' || !hash_equals((string)CRON_SECRET, $key)) { http_response_code(403); exit('interdit'); }
    header('Content-Type: application/json');
}
try {
    fin_ensure($pdo);
    $rep = fin_release_due($pdo, 'cron');
    fin_audit($pdo, 'cron_deblocage', 'job', date('c'), $rep, 'cron');
    echo json_encode($rep), "\n";
} catch (Throwable $e) {
    error_log('cron_virements: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erreur' => 'echec']), "\n";
}
