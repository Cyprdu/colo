<?php
// php tests/clics_check.php : liens suivis /go (paramètres, refus), appareil, page d'origine, estimation.
// Partie base seulement sur la base JETABLE (api/config.php de test pointant sur moncamp_adm_test) : enregistrement, robots exclus, jours d'un changement d'heure.
if (is_file(__DIR__ . '/../api/config.php')) require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/clics.php';
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }

$u = clics_url('https://ex.fr/a?ref=1&utm_source=x&UTM_MEDIUM=y#dates', 'tokS', 'c1');
ok($u === 'https://ex.fr/a?ref=1&utm_source=moncamp.fr&utm_medium=referral&utm_campaign=tokS&mc_click=c1#dates', 'paramètres ajoutés, anciens utm remplacés, fragment gardé');
ok(clics_url('www.ex.fr/inscription', 't', 'c') === 'https://www.ex.fr/inscription?utm_source=moncamp.fr&utm_medium=referral&utm_campaign=t&mc_click=c', 'adresse sans schéma : https ajouté');
foreach (['javascript:alert(1)', 'ftp://ex.fr/x', "https://ex.fr/\r\nSet-Cookie: a=b", 'https://u:p@ex.fr/', '', 'https:///x'] as $bad) ok(clics_url($bad, 't', 'c') === null, 'refusé : ' . json_encode($bad));
ok(clics_appareil('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148') === 'mobile', 'iPhone = mobile');
ok(clics_appareil('Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 Chrome/120 Safari/537.36') === 'tablette', 'Android sans Mobile = tablette');
ok(clics_appareil('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120') === 'ordinateur', 'Windows = ordinateur');
ok(clics_referent('https://MonCamp.fr/camp_details?t=secret#x') === 'moncamp.fr/camp_details' && clics_referent('') === null, 'page d\'origine sans paramètres');
ok(clics_estimation(100, 25, 5.0, 640.0, 2.0) === 2500 + 6400 && clics_estimation(10, null, null, 500.0, 2.0) === 0, 'estimation : clics × €/clic + clics × taux × prix × %');

if (!defined('DB_NAME') || DB_NAME !== 'moncamp_adm_test') exit("base : ignorée (base non jetable)\n");
date_default_timezone_set('Europe/Paris');
$pdo->exec("DROP TABLE IF EXISTS clics_sortants");
foreach (['camps', 'organisateurs'] as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(100), public_id VARCHAR(32))");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, token VARCHAR(64), nom VARCHAR(100), date_debut DATE, date_fin DATE, prix DECIMAL(8,2), organisateur_id INT)");
$pdo->exec("INSERT INTO organisateurs VALUES (1, 'Colo A', 'pubA'), (2, 'Colo B', 'pubB')");
$pdo->exec("INSERT INTO camps VALUES (1, 'tokA', 'Mer', '2027-07-01', '2027-07-10', 600, 1), (2, 'tokB', 'Neige', '2027-02-01', '2027-02-08', 400, 2)");
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) Mobile'; $_SERVER['REMOTE_ADDR'] = '203.0.113.7'; $_SERVER['HTTP_REFERER'] = 'https://moncamp.fr/camp_details?t=tokA';
$j1 = clics_enregistrer($pdo, 1, 1); clics_enregistrer($pdo, 1, 1);
$_SERVER['HTTP_USER_AGENT'] = 'curl/8.0'; clics_enregistrer($pdo, 1, 1);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0)'; clics_enregistrer($pdo, 2, 2);
$r = $pdo->query("SELECT * FROM clics_sortants WHERE jeton = " . $pdo->quote($j1))->fetch();
ok(strlen($j1) === 20 && $r['appareil'] === 'mobile' && $r['referent'] === 'moncamp.fr/camp_details' && strlen($r['ip_hash']) === 64 && !str_contains(json_encode($r), '203.0.113'), 'clic enregistré, IP hachée');
$pdo->exec("UPDATE organisateurs SET partenaire_clic_cents = 20, partenaire_pct = 5 WHERE id = 1");
$s = clics_stats($pdo, date('Y-m-d'), date('Y-m-d'));
ok($s['total'] === 3 && $s['robots'] === 1 && count($s['orgas']) === 2, 'robots exclus des compteurs');
ok($s['sejours'][0]['n'] === 2 && $s['sejours'][0]['uniques'] === 1 && $s['sejours'][0]['mobile'] === 2, 'par séjour : clics, visiteurs, mobile');
ok($s['orgas'][0]['est'] === clics_estimation(2, 20, 5.0, 600.0, clics_conv_pct($pdo)), 'estimation par organisme');
ok(clics_stats($pdo, date('Y-m-d'), date('Y-m-d'), 2)['total'] === 1, 'filtre organisme');
$pdo->exec("UPDATE clics_sortants SET created_at = '2026-10-26 10:00:00'");
$s = clics_stats($pdo, '2026-10-20', '2026-10-26');
ok(count($s['jours']) === 7 && $s['jours']['2026-10-26'] === 3, 'changement d\'heure : le dernier jour est compté');
$pdo->exec("DROP TABLE clics_sortants");
echo "tout est ok\n";
