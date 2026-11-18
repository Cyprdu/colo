<?php
// api/cron_virements.php : job des versements (toutes les heures, via api/cron_tout.php) : transferts immédiats des paiements reçus avant
// l'activation du compte (legacy), reprises après litige perdu, frais Stripe réels. Les versements bancaires sont AUTOMATIQUES chez Stripe.
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
    $rep = fin_release_due($pdo, 'cron', null, false, true); // paiements reçus avant l'activation : tout le net dès que le compte est prêt
    $rep['reprises'] = fin_reprises_run($pdo);
    $rep['virements'] = fin_virements_quotidiens($pdo);
    $rep['dettes_sepa'] = fin_sepa_dettes_run($pdo);
    require_once __DIR__ . '/moyens_paiement_lib.php';
    $rep['commissions_hors'] = fin_hors_commissions_rattrapage($pdo);
    $rep['frais_rattrapes'] = fin_frais_rattrapage($pdo); // une fois : frais Stripe déjà payés par moncamp.fr → dette de l'organisme
    [$rep['frais_releves'], $rep['frais_restants']] = fin_backfill_fees($pdo); // frais Stripe réels non encore relevés
    fin_audit($pdo, 'cron_deblocage', 'job', date('c'), $rep, 'cron');
    echo json_encode($rep), "\n";
} catch (Throwable $e) {
    error_log('cron_virements: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erreur' => 'echec']), "\n";
}
