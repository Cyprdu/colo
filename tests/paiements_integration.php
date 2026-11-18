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

ok($pdo->query("SELECT mode FROM fin_paiements WHERE inscription_id = 900")->fetchColumn() === 'legacy', 'paiement existant : mode legacy');

// ---------------------------------------------------------------- 3. session Checkout en destination charges
ok(fin_destination_params($pdo, 2, [2500]) === [], 'compte non prêt : repli legacy (aucun paramètre destination)');
$pdo->exec("UPDATE organisateurs SET stripe_account_id = 'acct_D' WHERE id = 2");
$pdo->exec("INSERT INTO stripe_comptes (organisateur_id, account_id, transfers_actif, payouts_enabled, details_submitted, charges_enabled) VALUES (2, 'acct_D', 1, 1, 1, 0)");
$d = fin_destination_params($pdo, 2, [2500, 10000]);
ok($d['payment_intent_data']['application_fee_amount'] === 93 + 280 && $d['payment_intent_data']['transfer_data']['destination'] === 'acct_D', 'commission = somme des commissions par inscription (0,93 + 2,80), net vers le compte de l\'organisme');
ok(!isset($d['payment_intent_data']['on_behalf_of']), 'on_behalf_of seulement si le compte accepte les cartes');
$pdo->exec("UPDATE stripe_comptes SET charges_enabled = 1 WHERE organisateur_id = 2");
$pdo->exec("UPDATE organisateurs SET portefeuille = -5.00 WHERE id = 2"); // commission hors carte due
$d = fin_destination_params($pdo, 2, [2500, 10000]);
ok($d['payment_intent_data']['on_behalf_of'] === 'acct_D' && $d['metadata']['compensation'] === '500' && $d['payment_intent_data']['application_fee_amount'] === 873, 'dette de 5 EUR ajoutée à la commission prélevée');

$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (2, 2, 2, 'En attente', 25.00, 'EN_ATTENTE', 'tokB'), (3, 2, 3, 'En attente', 100.00, 'EN_ATTENTE', 'tokC')");
ok(fin_record_checkout($pdo, ['tokB', 'tokC'], 'pi_D', 'cs_D', 12500, 'destination', 500) === 2, 'paiement destination : 2 lignes');
$r = $pdo->query("SELECT mode, net_cents, compensation_cents, verse_cents, DATE(date_deblocage) d FROM fin_paiements WHERE payment_intent = 'pi_D' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok($r[0]['mode'] === 'destination' && array_sum(array_column($r, 'compensation_cents')) === 500 && $r[0]['d'] === date('Y-m-d', strtotime('+43 day')), 'mode destination, dette répartie, versement = début + 3 jours');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn()) < 0.001, 'solde : net non crédité (il est chez Stripe), dette soldée');
$t = fin_ledger_totaux($pdo, 2);
ok($t['encaisse'] === 12500 && $t['commission'] === 373 && $t['en_attente'] === 12500 - 373, 'cascade destination : 125 EUR encaissés, 3,73 EUR de commission');

echo "Tout est bon.\n";
