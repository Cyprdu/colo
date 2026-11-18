<?php
// api/cron_virements.php : job des versements (toutes les heures, via api/cron_tout.php) : transferts legacy, versements bancaires des organismes
// (Payout N jours après le début du séjour), reprises après litige perdu, frais Stripe réels.
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
    $rep = fin_release_due($pdo, 'cron'); // anciens paiements (legacy) : plus de jour de versement, chaque heure
    $rep['bancaire'] = fin_payouts_run($pdo, 'cron');
    $rep['reprises'] = fin_reprises_run($pdo);
    [$rep['frais_releves'], $rep['frais_restants']] = fin_backfill_fees($pdo); // frais Stripe réels non encore relevés
    fin_audit($pdo, 'cron_deblocage', 'job', date('c'), $rep, 'cron');
    echo json_encode($rep), "\n";
} catch (Throwable $e) {
    error_log('cron_virements: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erreur' => 'echec']), "\n";
}
