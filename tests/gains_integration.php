<?php
// Test d'intégration « Payer avec mes gains » : débit unique, rejeu, double soumission simultanée, solde insuffisant, journal en ajout seulement.
// Nécessite une base MySQL/MariaDB JETABLE (le script crée et vide ses tables) :
//   FIN_TEST_DSN="mysql:host=127.0.0.1;port=3398;dbname=t" php tests/gains_integration.php
// (le mode « worker » ci-dessous est lancé par le test lui-même pour simuler deux requêtes simultanées)
$dsn = getenv('FIN_TEST_DSN');
if (!$dsn) { echo "FIN_TEST_DSN non défini : test ignoré.\n"; exit(0); }
define('STRIPE_SECRET_KEY', 'sk_test_stub');
require __DIR__ . '/stripe_stub.php';
require __DIR__ . '/../api/gains_lib.php';
$pdo = new PDO($dsn, getenv('FIN_TEST_USER') ?: 'root', getenv('FIN_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if (($argv[1] ?? '') === 'worker') { // requête concurrente : même jeton, même pack
    try { $r = gains_buy_points($pdo, 7, 1, 'decouverte', $argv[2], '203.0.113.9'); echo json_encode(['ok' => $r['ok'], 'replay' => $r['replay']]); }
    catch (Throwable $e) { echo json_encode(['err' => $e->getMessage()]); }
    exit(0);
}

function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
foreach (['fin_gains_journal', 'points_mouvements', 'fin_audit', 'organisateurs', 'camps', 'inscriptions'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), user_id INT, portefeuille DECIMAL(12,2) NOT NULL DEFAULT 0, solde_points INT DEFAULT 0, stripe_account_id VARCHAR(64) NULL, adresse_complete VARCHAR(80) NULL, code_postal_orga VARCHAR(10) NULL, ville_orga VARCHAR(50) NULL, email VARCHAR(80) NULL)");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), date_debut DATE, organisateur_id INT)");
$pdo->exec("CREATE TABLE inscriptions (id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT, enfant_id INT, tarif_id INT NULL, statut VARCHAR(20), prix_final DECIMAL(10,2), statut_paiement VARCHAR(20), reservation_token VARCHAR(64), mode_paiement VARCHAR(20), montant_paye DECIMAL(10,2) DEFAULT 0)");
$pdo->exec("INSERT INTO organisateurs (id, nom, user_id, portefeuille) VALUES (1, 'A', 7, 25.00), (2, 'B', 8, 500.00)");
gains_ensure($pdo);
$idem = function () { return bin2hex(random_bytes(16)); };
$solde = function (int $id) use ($pdo) { return $pdo->query("SELECT portefeuille, solde_points FROM organisateurs WHERE id = $id")->fetch(PDO::FETCH_NUM); };

// 1) achat nominal : Pack Découverte (10 EUR / 100 pts) sur 25 EUR
$k1 = $idem();
$r = gains_buy_points($pdo, 7, 1, 'decouverte', $k1, '203.0.113.9');
ok($r['ok'] && !$r['replay'] && $r['solde_avant_cents'] === 2500 && $r['solde_apres_cents'] === 1500, 'achat nominal : solde 25 EUR -> 15 EUR');
ok($solde(1) == ['15.00', 100], 'portefeuille débité de 10 EUR, 100 points crédités');

// 2) rejeu du même jeton (double clic, rechargement, réseau) : aucun second débit, résultat d'origine renvoyé
$r2 = gains_buy_points($pdo, 7, 1, 'decouverte', $k1, '203.0.113.9');
ok($r2['ok'] && $r2['replay'] && $r2['journal_id'] === $r['journal_id'], 'rejeu : même résultat, marqué rejeu');
ok($solde(1) == ['15.00', 100], 'rejeu : aucun second débit');
ok((int)$pdo->query("SELECT COUNT(*) FROM fin_gains_journal WHERE organisateur_id = 1")->fetchColumn() === 1, 'rejeu : une seule ligne de journal');

// 3) jeton réutilisé par un autre utilisateur ou pour un autre pack : refusé
try { gains_buy_points($pdo, 8, 2, 'decouverte', $k1, '198.51.100.1'); ok(false, 'jeton d\'un autre compte refusé'); } catch (Exception $e) { ok(true, 'jeton d\'un autre compte refusé'); }
try { gains_buy_points($pdo, 7, 1, 'standard', $k1, '203.0.113.9'); ok(false, 'même jeton, autre pack refusé'); } catch (Exception $e) { ok(true, 'même jeton, autre pack refusé'); }
try { gains_buy_points($pdo, 7, 1, 'decouverte', 'pas-un-jeton', '203.0.113.9'); ok(false, 'jeton mal formé refusé'); } catch (Exception $e) { ok(true, 'jeton mal formé refusé'); }
try { gains_buy_points($pdo, 7, 2, 'decouverte', $idem(), '203.0.113.9'); ok(false, 'organisme d\'un autre compte refusé'); } catch (Exception $e) { ok(true, 'organisme d\'un autre compte refusé'); }
ok($solde(2) == ['500.00', 0], 'les tentatives invalides ne touchent aucun solde');

// 4) solde insuffisant (15 EUR < Pack Standard 50 EUR) : rien n'est débité, le refus est tracé, et son rejeu aussi
$k2 = $idem();
$r = gains_buy_points($pdo, 7, 1, 'standard', $k2, '203.0.113.9');
ok(!$r['ok'] && $r['code'] === 'solde' && $r['solde_avant_cents'] === 1500, 'solde insuffisant : refusé');
ok($solde(1) == ['15.00', 100], 'solde insuffisant : aucun débit, aucun point');
$r = gains_buy_points($pdo, 7, 1, 'standard', $k2, '203.0.113.9');
ok(!$r['ok'] && $r['replay'], 'rejeu d\'un refus : toujours refusé');
ok((int)$pdo->query("SELECT COUNT(*) FROM fin_gains_journal WHERE statut = 'refuse_solde'")->fetchColumn() === 1, 'le refus est inscrit au journal (une seule fois)');

// 5) double soumission SIMULTANÉE (deux processus, même jeton) : un seul débit
$k3 = $idem();
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . $k3;
$env = ['FIN_TEST_DSN' => $dsn, 'FIN_TEST_USER' => getenv('FIN_TEST_USER') ?: 'root', 'FIN_TEST_PASS' => getenv('FIN_TEST_PASS') ?: ''] + getenv();
$ps = []; for ($i = 0; $i < 2; $i++) { $ps[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, $env); }
$out = []; foreach ($ps as $i => $p) { $out[$i] = json_decode(stream_get_contents($pipes[$i][1]), true); proc_close($p); }
ok(count(array_filter($out, fn($o) => !empty($o['ok']) && empty($o['replay']))) === 1 && count(array_filter($out, fn($o) => !empty($o['replay']))) === 1, 'double soumission simultanée : une exécution, un rejeu (' . json_encode($out) . ')');
ok($solde(1) == ['5.00', 200], 'double soumission simultanée : un seul débit (15 -> 5 EUR, 200 points)');

// 6) solde épuisé : 5 EUR < 10 EUR, jamais de solde négatif
$r = gains_buy_points($pdo, 7, 1, 'decouverte', $idem(), '203.0.113.9');
ok(!$r['ok'] && (float)$solde(1)[0] === 5.0, 'le solde ne devient jamais négatif');

// 7) journal : chaîne valide, ajout seulement
$v = gains_verify_chain($pdo);
ok($v['ok'] && $v['n'] === 4, 'chaîne d\'empreintes valide (4 lignes)');
$row = $pdo->query("SELECT * FROM fin_gains_journal WHERE statut = 'ok' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($row['ip'] === '203.0.113.9' && (int)$row['user_id'] === 7 && $row['objet'] === 'pack:decouverte' && (int)$row['solde_avant_cents'] === 2500 && (int)$row['solde_apres_cents'] === 1500, 'journal : qui, IP, objet, solde avant/après');
foreach (["UPDATE fin_gains_journal SET montant_cents = 1 WHERE id = {$row['id']}", "DELETE FROM fin_gains_journal WHERE id = {$row['id']}"] as $sql) {
    try { $pdo->exec($sql); ok(false, 'journal en ajout seulement : ' . substr($sql, 0, 6) . ' interdit'); } catch (PDOException $e) { ok(true, 'journal en ajout seulement : ' . substr($sql, 0, 6) . ' interdit'); }
}
// falsification contournant les déclencheurs (accès administrateur) : détectée par la chaîne
$pdo->exec("DROP TRIGGER fin_gains_no_upd"); $pdo->exec("UPDATE fin_gains_journal SET montant_cents = 1 WHERE id = {$row['id']}");
$v = gains_verify_chain($pdo);
ok(!$v['ok'] && $v['rupture'] === (int)$row['id'], 'falsification détectée par la chaîne d\'empreintes');
echo "TOUT EST OK\n";
