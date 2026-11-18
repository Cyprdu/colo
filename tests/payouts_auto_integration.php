<?php
// Versements automatiques Stripe (relevé des payouts, date d'arrivée, journal) sur une base JETABLE :
//   FIN_TEST_DSN="mysql:host=127.0.0.1;dbname=moncamp_test_jetable" php tests/payouts_auto_integration.php
$dsn = getenv('FIN_TEST_DSN');
if (!$dsn) { echo "FIN_TEST_DSN non défini : test ignoré.\n"; exit(0); }
define('STRIPE_SECRET_KEY', 'sk_test_stub');
require __DIR__ . '/stripe_stub.php';
require __DIR__ . '/../api/finance_lib.php';
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
$pdo = new PDO($dsn, getenv('FIN_TEST_USER') ?: 'root', getenv('FIN_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo;
foreach ($pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TRIGGER `$t`");
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), user_id INT, portefeuille DECIMAL(12,2) NOT NULL DEFAULT 0, solde_points INT DEFAULT 0, stripe_account_id VARCHAR(64) NULL, email VARCHAR(80) NULL, web VARCHAR(80) NULL)");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), date_debut DATE, date_fin DATE NULL, organisateur_id INT, token VARCHAR(40) NULL, type VARCHAR(30) NULL, groupe_id INT NULL, quota_global INT DEFAULT 20, supprime TINYINT DEFAULT 0)");
$pdo->exec("CREATE TABLE enfants (id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT, nom VARCHAR(50), prenom VARCHAR(50))");
$pdo->exec("CREATE TABLE inscriptions (id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT, enfant_id INT, tarif_id INT NULL, statut VARCHAR(20), prix_final DECIMAL(10,2), statut_paiement VARCHAR(20), reservation_token VARCHAR(64), mode_paiement VARCHAR(20), montant_paye DECIMAL(10,2) DEFAULT 0, stripe_payment_intent_id VARCHAR(80) NULL, date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO organisateurs (id, nom, user_id, stripe_account_id) VALUES (1, 'Orga', 7, 'acct_A')");
$pdo->exec("INSERT INTO camps (id, nom, date_debut, organisateur_id, token) VALUES (1, 'Été', DATE_ADD(CURDATE(), INTERVAL 60 DAY), 1, 't1')");
fin_ensure($pdo);
$pdo->exec("INSERT INTO stripe_comptes (organisateur_id, account_id, type, details_submitted, charges_enabled, payouts_enabled, transfers_actif, payout_auto) VALUES (1, 'acct_A', 'express', 1, 1, 1, 1, 1)");

// ---- B. aucune retenue : un paiement direct est versable tout de suite (séjour dans 60 jours)
ok(abs(strtotime(fin_payout_date(date('Y-m-d', strtotime('+60 days')))) - time()) < 5, 'date de versement = maintenant (plus de « 3 jours après le début du séjour »)');
foreach ([[101, 10000, 9720], [102, 5000, 4845]] as $i => [$ins, $brut, $net])
    $pdo->exec("INSERT INTO fin_paiements (inscription_id, organisateur_id, camp_id, payment_intent, brut_cents, commission_cents, net_cents, commission_pct, date_deblocage, mode, created_at)
                VALUES ($ins, 1, 1, 'pi_$ins', $brut, " . ($brut - $net) . ", $net, 2.5, NOW(), 'direct', NOW() - INTERVAL " . (5 - $i) . " DAY)");
foreach ($pdo->query("SELECT * FROM fin_paiements")->fetchAll(PDO::FETCH_ASSOC) as $p) fin_ledger_paiement($pdo, $p);

// ---- B. payout automatique relevé (webhook payout.created) : date d'arrivée Stripe, réparti du plus ancien au plus récent, journalisé
$arr = strtotime('+2 weekdays 12:00');
$po = (object)['id' => 'po_1', 'amount' => 12000, 'currency' => 'eur', 'status' => 'in_transit', 'arrival_date' => $arr, 'created' => time()];
fin_payout_record($pdo, $po, 'acct_A');
fin_payout_record($pdo, $po, 'acct_A'); // rejeu
$t = $pdo->query("SELECT * FROM fin_transferts WHERE stripe_transfer_id = 'po_1'")->fetchAll(PDO::FETCH_ASSOC);
ok(count($t) === 1 && $t[0]['statut'] === 'envoye' && $t[0]['arrivee'] === date('Y-m-d', $arr), 'versement relevé une seule fois, date d\'arrivée de Stripe');
$v = $pdo->query("SELECT inscription_id, verse_cents, statut FROM fin_paiements ORDER BY inscription_id")->fetchAll(PDO::FETCH_ASSOC);
ok((int)$v[0]['verse_cents'] === 9720 && $v[0]['statut'] === 'verse' && (int)$v[1]['verse_cents'] === 2280 && $v[1]['statut'] === 'attente', 'réparti sur le plus ancien d\'abord (9720 + 2280)');
ok((int)$pdo->query("SELECT SUM(montant_cents) FROM fin_ledger WHERE type = 'versement'")->fetchColumn() === -12000, 'journal : versement de 120 EUR');
$tot = fin_ledger_totaux($pdo, 1);
ok($tot['en_attente'] === 9720 + 4845 - 12000, 'en attente = net − versé');

// ---- payout.paid puis échec d'un autre versement : statut, lignes remises en attente, journal compensé
fin_payout_event($pdo, (object)['id' => 'po_1', 'arrival_date' => $arr], true, 'acct_A');
ok($pdo->query("SELECT statut FROM fin_transferts WHERE stripe_transfer_id = 'po_1'")->fetchColumn() === 'paye', 'payout.paid : arrivé');
fin_payout_record($pdo, (object)['id' => 'po_2', 'amount' => 2565, 'currency' => 'eur', 'status' => 'pending', 'arrival_date' => $arr, 'created' => time()], 'acct_A');
ok($pdo->query("SELECT statut FROM fin_paiements WHERE inscription_id = 102")->fetchColumn() === 'verse', 'second versement : le reste est versé');
fin_payout_event($pdo, (object)['id' => 'po_2', 'failure_message' => 'IBAN invalide'], false, 'acct_A');
ok($pdo->query("SELECT statut FROM fin_paiements WHERE inscription_id = 102")->fetchColumn() === 'attente' && fin_ledger_totaux($pdo, 1)['en_attente'] === 2565, 'échec bancaire : la ligne repasse en attente, journal compensé');
fin_payout_record($pdo, (object)['id' => 'po_x', 'amount' => 500, 'currency' => 'eur', 'status' => 'paid', 'created' => time()], 'acct_inconnu');
ok((int)$pdo->query("SELECT COUNT(*) FROM fin_transferts WHERE stripe_transfer_id = 'po_x'")->fetchColumn() === 0, 'compte inconnu : ignoré');

// ---- compte passé en versements automatiques quotidiens (délai minimal)
$GLOBALS['stripe_calls'] = [];
$pdo->exec("UPDATE stripe_comptes SET payout_auto = 0");
fin_account_setup($pdo, 1, 'acct_A');
$c = array_values(array_filter($GLOBALS['stripe_calls'], fn($x) => $x[0] === 'accounts.update'));
ok($c && $c[0][1][1]['settings']['payouts']['schedule'] === ['interval' => 'daily', 'delay_days' => 'minimum'] && (int)$pdo->query("SELECT payout_auto FROM stripe_comptes")->fetchColumn() === 1, 'compte en versements automatiques quotidiens, délai minimal');
// ---- C. paiements reçus par moncamp.fr avant l'activation (legacy) : transférés tout de suite quand le compte est prêt
$pdo->exec("INSERT INTO enfants (id, parent_id, nom, prenom) VALUES (1, 9, 'Durand', 'Tom')");
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (201, 1, 1, 'Confirmé', 300, 'PAYE', 'tokL')");
$pdo->exec("INSERT INTO fin_paiements (inscription_id, organisateur_id, camp_id, payment_intent, brut_cents, commission_cents, net_cents, commission_pct, date_deblocage, mode)
            VALUES (201, 1, 1, 'pi_L', 30000, 780, 29220, 2.5, DATE_ADD(NOW(), INTERVAL 90 DAY), 'legacy')");
$pdo->exec("UPDATE organisateurs SET portefeuille = portefeuille + 292.20 WHERE id = 1");
$GLOBALS['fake_transfers'] = null; $GLOBALS['stripe_calls'] = [];
$before = fin_ledger_totaux($pdo, 1);
$r = fin_release_pending_now($pdo, 1);
$tr = $GLOBALS['fake_transfers']->calls;
ok($r['transferts'] === 1 && $r['montant_cents'] === 29220 && count($tr) === 1 && $tr[0][2]['idempotency_key'] === 'colomap-transfer-' . $pdo->query("SELECT MAX(id) FROM fin_transferts WHERE type = 'sejour'")->fetchColumn(), 'compte prêt : tout le net transféré tout de suite (séjour dans 90 jours), transfert idempotent');
ok(strpos($tr[0][1]['description'], 'Été (Durand)') !== false && $tr[0][1]['metadata']['type'] === 'paiements_avant_activation', 'description lisible dans le Stripe de l\'organisme : ' . $tr[0][1]['description']);
ok($pdo->query("SELECT statut FROM fin_paiements WHERE inscription_id = 201")->fetchColumn() === 'verse', 'ligne transférée');
ok((int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE type = 'transfert'")->fetchColumn() === 29220 && fin_ledger_totaux($pdo, 1)['verse'] === $before['verse'], 'journal : transfert tracé, pas compté comme versement bancaire (le payout automatique le sera)');
ok(fin_release_pending_now($pdo, 1)['transferts'] === 0 && count($GLOBALS['fake_transfers']->calls) === 1, 'rejeu : aucun second transfert');
// ---- F. « Payer ma dette » : Checkout sur le compte plateforme, montant serveur, surplus crédité, idempotent
require __DIR__ . '/../api/marketing_lib.php';
require __DIR__ . '/../api/stripe_webhook_lib.php';
$pdo->exec("UPDATE organisateurs SET portefeuille = -20.00 WHERE id = 1");
ok(fin_dette_due($pdo, 1) === 2000, 'dette en cours = solde négatif (20 EUR)');
$GLOBALS['stripe_calls'] = []; $GLOBALS['stripe_resp']['checkout.sessions.create'] = (object)['id' => 'cs_dette1', 'url' => 'https://checkout.stripe.test/cs_dette1'];
$url = fin_dette_checkout($pdo, 1, 7);
$c = array_values(array_filter($GLOBALS['stripe_calls'], fn($x) => $x[0] === 'checkout.sessions.create'))[0][1];
ok($url === 'https://checkout.stripe.test/cs_dette1' && $c[0]['line_items'][0]['price_data']['unit_amount'] === 2000 && $c[0]['line_items'][0]['quantity'] === 1
   && !isset($c[0]['payment_intent_data']['application_fee_amount']) && !isset($c[1]['stripe_account']) && $c[0]['metadata']['type'] === 'dette'
   && $c[0]['metadata']['organisateur'] === fin_orga_tok(1, $pdo), 'Checkout plateforme (pas de Stripe-Account ni de commission), montant fixe de la dette, jeton d\'organisme');
$pdo->exec("UPDATE organisateurs SET portefeuille = -15.00 WHERE id = 1"); // une compensation a eu lieu entre le clic et le paiement
$ev = fn(string $id, array $o) => json_decode(json_encode(['id' => $id, 'type' => 'checkout.session.completed', 'account' => '', 'data' => ['object' => $o]]));
$sess = ['id' => 'cs_dette1', 'payment_status' => 'paid', 'currency' => 'eur', 'amount_total' => 2000, 'metadata' => ['type' => 'dette', 'organisateur' => fin_orga_tok(1, $pdo), 'montant' => '2000']];
$r = stripe_webhook_process($pdo, $ev('evt_dette1', $sess));
ok($r[0] === 200 && $r[2]['dette']['solde'] === 1500 && $r[2]['dette']['surplus'] === 500, 'webhook : 15 EUR de dette soldés, 5 EUR de surplus');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - 5.00) < 0.001, 'portefeuille : surplus crédité au solde (+5 EUR)');
ok((int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE reference = 'dettecb:cs_dette1'")->fetchColumn() === -1500
   && (int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE reference = 'dettecb:cs_dette1:surplus' AND type = 'ajustement'")->fetchColumn() === 500, 'journal : « dette réglée par carte » et excédent');
stripe_webhook_process($pdo, $ev('evt_dette1b', $sess)); // même session, autre événement
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - 5.00) < 0.001, 'idempotent : rien de compté deux fois');
$GLOBALS['stripe_calls'] = [];
try { fin_dette_checkout($pdo, 1, 7); $err = ''; } catch (Exception $e) { $err = $e->getMessage(); }
ok($err !== '' && !$GLOBALS['stripe_calls'], 'plus de dette : pas de session de paiement');
echo "Tout est bon.\n";
