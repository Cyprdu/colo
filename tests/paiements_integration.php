<?php
// Test d'intégration des paiements « destination charges » : journal fin_ledger, checkout, webhook, versements bancaires, remboursements.
// Base MySQL/MariaDB JETABLE (le script crée et vide ses tables) :
//   FIN_TEST_DSN="mysql:host=127.0.0.1;dbname=moncamp_test_jetable" php tests/paiements_integration.php
// Stripe est remplacé par tests/stripe_stub.php (aucun appel réseau) : les réponses sont simulées dans $GLOBALS['stripe_resp'].
$dsn = getenv('FIN_TEST_DSN');
if (!$dsn) { echo "FIN_TEST_DSN non défini : test ignoré.\n"; exit(0); }
define('STRIPE_SECRET_KEY', 'sk_test_stub');
require __DIR__ . '/stripe_stub.php';
require __DIR__ . '/../api/finance_lib.php';

function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
$pdo = new PDO($dsn, getenv('FIN_TEST_USER') ?: 'root', getenv('FIN_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo;
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), user_id INT, portefeuille DECIMAL(12,2) NOT NULL DEFAULT 0, solde_points INT DEFAULT 0, stripe_account_id VARCHAR(64) NULL, email VARCHAR(80) NULL, web VARCHAR(80) NULL)");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), date_debut DATE, organisateur_id INT, token VARCHAR(40) NULL)");
$pdo->exec("CREATE TABLE inscriptions (id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT, enfant_id INT, tarif_id INT NULL, statut VARCHAR(20), prix_final DECIMAL(10,2), statut_paiement VARCHAR(20), reservation_token VARCHAR(64), mode_paiement VARCHAR(20), montant_paye DECIMAL(10,2) DEFAULT 0, stripe_payment_intent_id VARCHAR(80) NULL, date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO organisateurs (id, nom, user_id) VALUES (1, 'Legacy', 7), (2, 'Destination', 8)");
$pdo->exec("INSERT INTO camps (id, nom, date_debut, organisateur_id) VALUES (1, 'Été', DATE_ADD(CURDATE(), INTERVAL 40 DAY), 1), (2, 'Hiver', DATE_ADD(CURDATE(), INTERVAL 40 DAY), 2), (3, 'Commencé', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 2)");

// ---------------------------------------------------------------- 2. journal : rattrapage des paiements existants, écriture en direct, idempotence
$pdo->exec("CREATE TABLE fin_paiements (id INT AUTO_INCREMENT PRIMARY KEY, inscription_id INT NOT NULL, organisateur_id INT NOT NULL, camp_id INT NOT NULL, payment_intent VARCHAR(80) NULL, session_id VARCHAR(80) NULL,
    brut_cents INT NOT NULL, commission_cents INT NOT NULL, net_cents INT NOT NULL, commission_pct DECIMAL(5,2) NOT NULL, rembourse_cents INT NOT NULL DEFAULT 0, net_rembourse_cents INT NOT NULL DEFAULT 0,
    date_deblocage DATETIME NOT NULL, statut VARCHAR(12) NOT NULL DEFAULT 'attente', transfert_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_inscription (inscription_id))");
$pdo->exec("INSERT INTO fin_paiements (inscription_id, organisateur_id, camp_id, payment_intent, brut_cents, commission_cents, net_cents, commission_pct, rembourse_cents, net_rembourse_cents, date_deblocage, created_at)
            VALUES (900, 1, 1, 'pi_old', 10000, 280, 9720, 2.5, 5000, 4860, NOW(), '2026-01-15 10:00:00')");
fin_ensure($pdo);
$l = $pdo->query("SELECT type, montant_cents, created_at FROM fin_ledger ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok(array_column($l, 'montant_cents', 'type') === ['paiement' => 10000, 'commission' => 140, 'remboursement' => -5000], 'rattrapage : paiement, commission (−280 puis +140 rendue), remboursement');
ok($l[0]['created_at'] === '2026-01-15 10:00:00', 'rattrapage : date d\'origine conservée');
ok(fin_ledger_backfill($pdo, true) === 0, 'rattrapage rejoué : aucune ligne en double');
$t = fin_ledger_totaux($pdo, 1);
ok($t['pour_vous'] === 10000 - 280 + 140 - 5000 && $t['en_attente'] === $t['pour_vous'], 'cascade : pour vous = encaissé − commission − remboursé');
try { $pdo->exec("UPDATE fin_ledger SET montant_cents = 0"); $locked = false; } catch (Throwable $e) { $locked = true; }
ok($locked, 'journal en ajout seulement (déclencheur)');

$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (1, 1, 1, 'En attente', 25.00, 'EN_ATTENTE', 'tokA')");
ok(fin_record_checkout($pdo, ['tokA'], 'pi_A', 'cs_A', 2500) === 1, 'paiement legacy enregistré');
fin_record_checkout($pdo, ['tokA'], 'pi_A', 'cs_A', 2500);
ok((int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE reference = 'pay:pi_A:1'")->fetchColumn() === 2, 'webhook rejoué : brut + commission écrits une seule fois');

echo "Tout est bon.\n";
