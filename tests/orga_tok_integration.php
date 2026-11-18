<?php
// Jeton public des organismes (api/orga_lib.php) sur une base JETABLE :
//   FIN_TEST_DSN="mysql:host=127.0.0.1;dbname=moncamp_test_jetable" php tests/orga_tok_integration.php
$dsn = getenv('FIN_TEST_DSN');
if (!$dsn) { echo "FIN_TEST_DSN non défini : test ignoré.\n"; exit(0); }
require __DIR__ . '/../api/orga_lib.php';
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
$pdo = new PDO($dsn, getenv('FIN_TEST_USER') ?: 'root', getenv('FIN_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo;
$pdo->exec("DROP TABLE IF EXISTS organisateurs");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), user_id INT)");
$pdo->exec("INSERT INTO organisateurs (id, nom, user_id) VALUES (13, 'A', 7), (14, 'B', 8), (15, 'C', 7)");

$t13 = fin_orga_tok(13);
$ids = $pdo->query("SELECT public_id FROM organisateurs")->fetchAll(PDO::FETCH_COLUMN);
ok(count(array_filter($ids, fn($t) => preg_match('/^[A-Za-z0-9]{22}$/', (string)$t))) === 3, 'colonne créée, toutes les lignes existantes reçoivent un jeton');
ok(count(array_unique($ids)) === 3, 'jetons distincts');
ok(fin_orga_tok(13) === $t13, 'jeton stable');
ok(fin_orga_from_tok($pdo, $t13, 7) === 13, 'propriétaire : jeton -> id');
ok(fin_orga_from_tok($pdo, $t13, 8) === 0, 'autre utilisateur : refusé');
ok(fin_orga_from_tok($pdo, $t13) === 13, 'admin (userId null) : accepté');
ok(fin_orga_from_tok($pdo, '13', 7) === 0 && fin_orga_from_tok($pdo, '', 7) === 0, 'id numérique ou vide : refusé');
$pdo->exec("INSERT INTO organisateurs (id, nom, user_id) VALUES (16, 'Nouveau', 9)");
$t16 = fin_orga_tok(16);
ok(preg_match('/^[A-Za-z0-9]{22}$/', $t16) === 1 && fin_orga_from_tok($pdo, $t16, 9) === 16, 'organisme créé après coup : jeton généré à la volée');
try { $pdo->exec("UPDATE organisateurs SET public_id = '$t13' WHERE id = 14"); $dup = false; } catch (PDOException $e) { $dup = true; }
ok($dup, 'UNIQUE sur public_id');
$k = '||colomap-orga';
ok(fin_orga_from_tok($pdo, substr(hash_hmac('sha256', 'orga:15', $k), 0, 24), 7) === 15, 'ancien jeton HMAC encore accepté (liens déjà envoyés)');
$pdo->exec("DROP TABLE organisateurs");
echo "Tous les contrôles passent.\n";
