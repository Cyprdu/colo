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
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), date_debut DATE, date_fin DATE NULL, organisateur_id INT, token VARCHAR(40) NULL, type VARCHAR(30) NULL, groupe_id INT NULL, quota_global INT DEFAULT 20, supprime TINYINT DEFAULT 0)");
$pdo->exec("CREATE TABLE enfants (id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT, nom VARCHAR(50), prenom VARCHAR(50))");
$pdo->exec("INSERT INTO enfants (id, parent_id, nom, prenom) VALUES (1, 9, 'Martin', 'Léa'), (2, 9, 'Durand', 'Tom'), (3, 9, 'Durand', 'Zoé'), (4, 9, 'Petit', 'Noé'), (5, 9, 'Petit', 'Lou'), (6, 9, 'Bernard', 'Jade')");
$pdo->exec("CREATE TABLE inscriptions (id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT, enfant_id INT, tarif_id INT NULL, statut VARCHAR(20), prix_final DECIMAL(10,2), statut_paiement VARCHAR(20), reservation_token VARCHAR(64), mode_paiement VARCHAR(20), montant_paye DECIMAL(10,2) DEFAULT 0, stripe_payment_intent_id VARCHAR(80) NULL, date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO organisateurs (id, nom, user_id) VALUES (1, 'Legacy', 7), (2, 'Destination', 8)");
$pdo->exec("ALTER TABLE organisateurs ADD logo_url VARCHAR(200) NULL");
$pdo->exec("INSERT INTO camps (id, nom, date_debut, organisateur_id, token, type) VALUES (1, 'Été', DATE_ADD(CURDATE(), INTERVAL 40 DAY), 1, 't1', 'Colonie'), (2, 'Hiver', DATE_ADD(CURDATE(), INTERVAL 40 DAY), 2, 't2', 'Ski'), (3, 'Commencé', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 2, 't3', 'Colonie')");

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

// ---------------------------------------------------------------- 3. session Checkout : direct charges, repli destination puis legacy
ok(fin_checkout_params($pdo, 2, [2500]) === [[], []], 'compte non prêt : repli legacy (aucun paramètre)');
$pdo->exec("UPDATE organisateurs SET stripe_account_id = 'acct_D' WHERE id = 2");
$pdo->exec("INSERT INTO stripe_comptes (organisateur_id, account_id, transfers_actif, payouts_enabled, details_submitted, charges_enabled) VALUES (2, 'acct_D', 1, 1, 1, 0)");
[$d, $o] = fin_checkout_params($pdo, 2, [2500, 10000]);
ok($d['payment_intent_data']['application_fee_amount'] === 93 + 280 + 213 && $d['metadata']['frais_estimes'] === '213' && $d['payment_intent_data']['transfer_data']['destination'] === 'acct_D' && $o === [], 'cartes pas encore activées : destination charges, commission par inscription (0,93 + 2,80) + frais Stripe estimés (1,5 % + 0,25 € = 2,13) payés par l\'organisme');
$pdo->exec("UPDATE stripe_comptes SET charges_enabled = 1 WHERE organisateur_id = 2");
$pdo->exec("UPDATE organisateurs SET portefeuille = -5.00 WHERE id = 2"); // commission hors carte due
[$d, $o] = fin_checkout_params($pdo, 2, [2500, 10000]);
ok($o === ['stripe_account' => 'acct_D'] && !isset($d['payment_intent_data']['transfer_data']) && $d['metadata']['mode'] === 'direct' && $d['payment_method_types'] === ['card'], 'compte complet : direct charges sur le compte de l\'organisme, moyens de paiement fixés');
$pdo->exec("UPDATE stripe_comptes SET exigences = '[\"individual.verification.document\"]' WHERE organisateur_id = 2");
ok(fin_checkout_params($pdo, 2, [2500])[1] === [], 'pièce d\'identité demandée par Stripe : repli destination (pas de page Checkout sur le compte connecté)');
$pdo->exec("UPDATE stripe_comptes SET exigences = '[]' WHERE organisateur_id = 2");
fin_put_reglage($pdo, 'direct_charges', '0', 'test'); fin_reglages(true);
ok(fin_checkout_params($pdo, 2, [2500])[1] === [], 'interrupteur admin : repli destination pour tous');
fin_put_reglage($pdo, 'direct_charges', '1', 'test'); fin_reglages(true);
ok($d['metadata']['compensation'] === '500' && $d['payment_intent_data']['application_fee_amount'] === 873, 'dette de 5 EUR ajoutée à la commission prélevée');

$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (2, 2, 2, 'En attente', 25.00, 'EN_ATTENTE', 'tokB'), (3, 2, 3, 'En attente', 100.00, 'EN_ATTENTE', 'tokC')");
ok(fin_record_checkout($pdo, ['tokB', 'tokC'], 'pi_D', 'cs_D', 12500, 'destination', 500) === 2, 'paiement destination : 2 lignes');
$r = $pdo->query("SELECT mode, net_cents, compensation_cents, verse_cents, DATE(date_deblocage) d FROM fin_paiements WHERE payment_intent = 'pi_D' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok($r[0]['mode'] === 'destination' && array_sum(array_column($r, 'compensation_cents')) === 500 && $r[0]['d'] === date('Y-m-d'), 'mode destination, dette répartie, versable tout de suite (versements automatiques)');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn()) < 0.001, 'solde : net non crédité (il est chez Stripe), dette soldée');
$t = fin_ledger_totaux($pdo, 2);
ok($t['encaisse'] === 12500 && $t['commission'] === 373 && $t['frais'] === 213 && $t['en_attente'] === 12500 - 373 - 213, 'cascade destination : 125 EUR encaissés, 3,73 EUR de commission, 2,13 EUR de frais Stripe estimés (à la charge de l’organisme)');

// ---------------------------------------------------------------- 4. webhook : paiement destination avec code promo, rejeu, remboursement, litige perdu
require __DIR__ . '/../api/marketing_lib.php';
require __DIR__ . '/../api/stripe_webhook_lib.php';
$ev = fn(string $id, string $type, array $o, string $acct = '') => json_decode(json_encode(['id' => $id, 'type' => $type, 'account' => $acct, 'data' => ['object' => $o]]));
$GLOBALS['stripe_resp']['paymentIntents.retrieve'] = fn($id) => (object)['latest_charge' => (object)['id' => 'ch_' . $id, 'application_fee' => 'fee_' . $id, 'transfer' => 'tr_' . $id]];
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (4, 3, 4, 'En attente', 25.00, 'EN_ATTENTE', 'tokE'), (5, 3, 5, 'En attente', 100.00, 'EN_ATTENTE', 'tokF')");
$paid = $ev('evt_1', 'checkout.session.completed', ['id' => 'cs_E', 'payment_status' => 'paid', 'payment_intent' => 'pi_E', 'amount_total' => 11250, 'amount_subtotal' => 12500,
    'metadata' => ['reservation_token' => 'tokE,tokF', 'mode' => 'destination', 'organisateur_id' => '2', 'commission' => '373', 'compensation' => '0', 'frais_estimes' => '213']]);
$r = stripe_webhook_process($pdo, $paid);
ok($r[0] === 200 && $r[2]['feeAdj'] === ['pi_E', 373 + 213], 'webhook : paiement destination enregistré');
ok((int)$pdo->query("SELECT SUM(commission_cents) FROM fin_paiements WHERE payment_intent = 'pi_E'")->fetchColumn() === 86 + 255, 'code promo -10 % : commission recalculée sur le payé (0,86 + 2,55)');
stripe_webhook_after($pdo, $r[2]);
$fee = array_values(array_filter($GLOBALS['stripe_calls'], fn($c) => $c[0] === 'applicationFees.createRefund'));
ok($fee && $fee[0][1][0] === 'fee_pi_E' && $fee[0][1][1]['amount'] === 32 + 19 && $fee[0][1][2]['idempotency_key'] === 'moncamp-fee-pi_E', 'commission et frais estimés prélevés en trop (0,32 + 0,19 EUR, code promo) rendus à l\'organisme, clé d\'idempotence');
ok(stripe_webhook_process($pdo, $paid)[1] === 'deja traite', 'webhook rejoué : ignoré');
$paid->id = 'evt_1bis'; // même paiement notifié deux fois (double clic, async_payment_succeeded...) sous un autre identifiant d'événement
ok(stripe_webhook_process($pdo, $paid)[0] === 200 && (int)$pdo->query("SELECT COUNT(*) FROM fin_paiements WHERE payment_intent = 'pi_E'")->fetchColumn() === 2, 'même paiement reçu deux fois : une ligne par inscription');
ok((int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE reference LIKE 'pay:pi_E:%'")->fetchColumn() === 4, 'webhook rejoué : journal inchangé');
$pf = (float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn();

$r = stripe_webhook_process($pdo, $ev('evt_2', 'charge.refunded', ['id' => 'ch_pi_E', 'payment_intent' => 'pi_E', 'amount_refunded' => 2250, 'amount' => 11250]));
ok($r[0] === 200 && abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn() - $pf) < 0.001, 'remboursement destination : notre solde inchangé (Stripe débite l\'organisme)');
ok((int)$pdo->query("SELECT COUNT(*) FROM fin_reversals")->fetchColumn() === 0, 'remboursement destination : aucun reversement legacy');
$t0 = fin_ledger_totaux($pdo, 2);
ok($t0['rembourse'] === 2250, 'journal : 22,50 EUR remboursés (partiel, au prorata)');

$GLOBALS['stripe_resp']['charges.retrieve'] = (object)['amount' => 11250];
$nrb = (int)$pdo->query("SELECT SUM(net_rembourse_cents) FROM fin_paiements WHERE payment_intent = 'pi_E'")->fetchColumn();
stripe_webhook_process($pdo, $ev('evt_3', 'charge.dispute.created', ['id' => 'dp_1', 'payment_intent' => 'pi_E', 'charge' => 'ch_pi_E', 'balance_transactions' => [['fee' => 1500]]]));
ok($pdo->query("SELECT GROUP_CONCAT(DISTINCT statut) FROM fin_paiements WHERE payment_intent = 'pi_E'")->fetchColumn() === 'litige', 'litige ouvert : lignes gelées');
$r = stripe_webhook_process($pdo, $ev('evt_4', 'charge.dispute.closed', ['id' => 'dp_1', 'payment_intent' => 'pi_E', 'charge' => 'ch_pi_E', 'status' => 'lost']));
$rep = (int)$pdo->query("SELECT SUM(a_reprendre_cents) FROM fin_paiements WHERE payment_intent = 'pi_E'")->fetchColumn();
ok($rep === (int)$pdo->query("SELECT SUM(net_cents) FROM fin_paiements WHERE payment_intent = 'pi_E'")->fetchColumn() - $nrb, 'litige perdu : net restant à reprendre à l\'organisme');
stripe_webhook_after($pdo, $r[2]);
$rev = array_values(array_filter($GLOBALS['fake_transfers']->calls, fn($c) => $c[0] === 'reversal'));
ok($rev && $rev[0][1] === 'tr_pi_E' && $rev[0][2]['amount'] === $rep && (int)$pdo->query("SELECT SUM(a_reprendre_cents) FROM fin_paiements")->fetchColumn() === 0, 'reprise : reversement du transfert de la charge, rien ne reste à reprendre');
ok(fin_ledger_totaux($pdo, 2)['rembourse'] === 11250 && (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE type = 'frais_paiement' AND reference LIKE 'litige:%'")->fetchColumn() === 2
   && (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE type = 'frais' AND reference LIKE 'litige:%'")->fetchColumn() === 0
   && (int)$pdo->query("SELECT SUM(montant_cents) FROM fin_ledger WHERE type = 'dette' AND reference LIKE 'fraisdette:litige:%'")->fetchColumn() === 1500, 'litige perdu : tout est remboursé au journal, frais de litige (15 EUR) à la charge de l\'organisme (dette), jamais de moncamp.fr');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn() + 15.00) < 0.001, 'frais de litige : dette de 15 EUR (solde négatif, compensée sur les paiements suivants)');
$pdo->exec("UPDATE organisateurs SET portefeuille = portefeuille + 15.00 WHERE id = 2"); // dette réglée : la suite du test part d'un solde nul

// ---------------------------------------------------------------- 5. versements bancaires (Payout manuel N jours après le début du séjour)
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (6, 3, 6, 'En attente', 50.00, 'EN_ATTENTE', 'tokG')");
fin_record_checkout($pdo, ['tokG'], 'pi_G', 'cs_G', 5000, 'destination');
$bal = fn(int $a, int $p) => $GLOBALS['stripe_resp']['balance.retrieve'] = (object)['available' => [(object)['amount' => $a, 'currency' => 'eur']], 'pending' => [(object)['amount' => $p, 'currency' => 'eur']]];
$bal(4845, 11627); // G (séjour commencé il y a 5 jours) disponible ; D (séjour dans 40 jours) encore en attente chez Stripe
$po = 0; $GLOBALS['stripe_resp']['payouts.create'] = function () use (&$po) { return (object)['id' => 'po_' . (++$po)]; };
$GLOBALS['stripe_calls'] = [];
$pdo->exec("UPDATE fin_paiements SET date_deblocage = DATE_ADD(NOW(), INTERVAL 40 DAY) WHERE payment_intent = 'pi_D'"); // ancien pilotage manuel (plus utilisé par le cron) : D pas encore éligible
$rep = fin_payouts_run($pdo);
$c = array_values(array_filter($GLOBALS['stripe_calls'], fn($x) => $x[0] === 'payouts.create'));
ok($rep['payouts'] === 1 && $rep['montant_cents'] === 4845, 'versement de 48,45 EUR (séjour commencé), le séjour à venir reste sur le compte Stripe');
ok($c[0][1][1] === ['stripe_account' => 'acct_D', 'idempotency_key' => 'moncamp-payout-' . $pdo->query("SELECT MAX(id) FROM fin_transferts")->fetchColumn()], 'payout sur le compte connecté, clé d\'idempotence');
ok((int)$pdo->query("SELECT payout_auto FROM stripe_comptes WHERE organisateur_id = 2")->fetchColumn() === 1 && in_array('accounts.update', array_column($GLOBALS['stripe_calls'], 0), true), 'compte passé en versements automatiques quotidiens (ancien pilotage manuel encore testable)');
ok($pdo->query("SELECT statut FROM fin_paiements WHERE payment_intent = 'pi_G'")->fetchColumn() === 'verse' && fin_ledger_totaux($pdo, 2)['verse'] === 4845, 'ligne versée, journal : 48,45 EUR versés');
$bal(0, 11627);
ok(fin_payouts_run($pdo)['payouts'] === 0, 'second passage (double clic) : rien de plus');
stripe_webhook_process($pdo, $ev('evt_5', 'payout.failed', ['id' => 'po_1', 'amount' => 4845, 'failure_message' => 'IBAN invalide'], 'acct_D'));
ok($pdo->query("SELECT CONCAT(statut, '|', erreur) FROM fin_transferts WHERE stripe_transfer_id = 'po_1'")->fetchColumn() === 'echec|IBAN invalide' && $pdo->query("SELECT statut FROM fin_paiements WHERE payment_intent = 'pi_G'")->fetchColumn() === 'attente', 'payout refusé : raison affichée, ligne remise en attente');
ok(fin_ledger_totaux($pdo, 2)['verse'] === 0, 'journal : versement annulé');
$bal(4845, 11627);
fin_payouts_run($pdo);
stripe_webhook_process($pdo, $ev('evt_6', 'payout.paid', ['id' => 'po_2', 'amount' => 4845], 'acct_D'));
ok($pdo->query("SELECT statut FROM fin_transferts WHERE stripe_transfer_id = 'po_2'")->fetchColumn() === 'paye', 'nouveau versement, puis payé');
// remboursement après versement : le solde Stripe devient négatif, rien n'est versé tant qu'il n'est pas compensé
stripe_webhook_process($pdo, $ev('evt_7', 'charge.refunded', ['id' => 'ch_G', 'payment_intent' => 'pi_G', 'amount_refunded' => 5000, 'amount' => 5000]));
$t = fin_ledger_totaux($pdo, 2);
ok($t['en_attente'] === (12500 - 373 - 213) - 4845, 'remboursé après versement : en attente = séjour à venir − part déjà versée (à compenser), frais Stripe estimés déduits');
$bal(-4845, 11627);
ok(fin_payouts_run($pdo)['payouts'] === 0, 'solde Stripe négatif : aucun versement');

// ---------------------------------------------------------------- 6. annulation par la famille (destination) : commission conservée, l'organisme est débité
require __DIR__ . '/../api/annulation_lib.php';
$p = $pdo->query("SELECT * FROM fin_paiements WHERE inscription_id = 2")->fetch(PDO::FETCH_ASSOC);
$GLOBALS['stripe_calls'] = [];
fin_refund($pdo, $p, 100, [], 'k-dest');
$rc = array_values(array_filter($GLOBALS['stripe_calls'], fn($c) => $c[0] === 'refunds.create'))[0][1];
ok($rc[0]['reverse_transfer'] === true && $rc[0]['refund_application_fee'] === false && $rc[1] === ['idempotency_key' => 'k-dest'], 'remboursement destination : reverse_transfer, commission non rendue');
[$rb] = fin_annul_montants((int)$p['brut_cents'], (int)$p['commission_cents'], 40, 30, 15);
$pdo->prepare("UPDATE fin_paiements SET annul_attendu = ?, annul_com = 1 WHERE id = ?")->execute([$rb, $p['id']]);
$avant = fin_ledger_totaux($pdo, 2);
stripe_webhook_process($pdo, $ev('evt_8', 'charge.refunded', ['id' => 'ch_D', 'payment_intent' => 'pi_D', 'amount_refunded' => $rb, 'amount' => 12500]));
$apres = fin_ledger_totaux($pdo, 2);
ok($apres['rembourse'] - $avant['rembourse'] === 2407 && $apres['commission'] === $avant['commission'], 'annulation : 24,07 EUR rendus, commission conservée au journal');
ok((int)$pdo->query("SELECT rembourse_cents FROM fin_paiements WHERE inscription_id = 3")->fetchColumn() === 0, 'annulation : le frère ou la sœur n\'est pas touché');

// ---------------------------------------------------------------- 8. admin : commission depuis le journal, rapprochement Stripe
require __DIR__ . '/../api/compta_lib.php';
$from = date('Y-m-d 00:00:00', strtotime('-1 day')); $to = date('Y-m-d 23:59:59');
$lc = compta_ledger_commission($pdo, $from, $to);
$com = -(int)$pdo->query("SELECT SUM(montant_cents) FROM fin_ledger WHERE type = 'commission' AND created_at BETWEEN '$from' AND '$to'")->fetchColumn();
ok((int)$lc['tot']['com'] === $com && count($lc['orgas']) === 2 && (int)$lc['tot']['marge'] === $com - (int)$lc['tot']['frais'], 'commission et marge lues dans le journal, par organisme');
ok(count(compta_journal($pdo, $from, $to, 2)) > 0, 'journal filtré par organisme');
$GLOBALS['stripe_resp']['applicationFees.all'] = new class { function autoPagingIterator() { return [(object)['currency' => 'eur', 'amount' => 373, 'amount_refunded' => 0], (object)['currency' => 'eur', 'amount' => 155, 'amount_refunded' => 0]]; } };
$rc = compta_fees_reconcile($pdo, $from, $to);
ok($rc['n'] === 2 && $rc['ecart'] === $rc['stripe'] - $rc['attendu'], 'rapprochement : commissions Stripe comparées au journal (écart affiché)');

// ---------------------------------------------------------------- 11. direct charges : frais Stripe à l'organisme, remboursement sur son solde, sinon avance + dette compensée
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (7, 3, 1, 'En attente', 80.00, 'EN_ATTENTE', 'tokH'), (8, 3, 2, 'En attente', 60.00, 'EN_ATTENTE', 'tokI')");
$GLOBALS['stripe_resp']['paymentIntents.retrieve'] = function ($id, $p = [], $o = []) { $GLOBALS['pi_opts'][$id] = $o;
    return (object)['latest_charge' => (object)['id' => 'ch_' . $id, 'application_fee' => 'fee_' . $id, 'transfer' => null,
        'balance_transaction' => (object)['currency' => 'eur', 'fee' => 145 + 230, 'fee_details' => [(object)['type' => 'stripe_fee', 'amount' => 145], (object)['type' => 'application_fee', 'amount' => 230]]]]]; };
$r = stripe_webhook_process($pdo, $ev('evt_20', 'checkout.session.completed', ['id' => 'cs_H', 'payment_status' => 'paid', 'payment_intent' => 'pi_H', 'amount_total' => 8000, 'amount_subtotal' => 8000,
    'metadata' => ['reservation_token' => 'tokH', 'mode' => 'direct', 'organisateur_id' => '2', 'commission' => '230', 'compensation' => '0']], 'acct_D'));
stripe_webhook_after($pdo, $r[2]);
$h = $pdo->query("SELECT * FROM fin_paiements WHERE payment_intent = 'pi_H'")->fetch(PDO::FETCH_ASSOC);
ok($h['mode'] === 'direct' && (int)$h['commission_cents'] === 230 && ($GLOBALS['pi_opts']['pi_H'] ?? []) === ['stripe_account' => 'acct_D'], 'paiement direct enregistré, frais lus sur le compte de l\'organisme');
ok((int)$h['frais_stripe_cents'] === 145 && (int)$h['net_cents'] === 8000 - 230 - 145, 'frais Stripe (1,45 EUR, commission exclue) déduits du net de l\'organisme');
fin_sync_fees($pdo, 'pi_H');
ok((int)$pdo->query("SELECT net_cents FROM fin_paiements WHERE payment_intent = 'pi_H'")->fetchColumn() === 7625 && (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE type = 'frais_paiement' AND reference = 'frais:" . (int)$h['id'] . "'")->fetchColumn() === 1, 'frais relevés deux fois : déduits une seule fois');
$t = fin_ledger_totaux($pdo, 2);
ok((int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE type = 'frais_paiement' AND reference = 'frais:" . $h['id'] . "'")->fetchColumn() === -145 && (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE type = 'frais' AND reference = 'frais:" . $h['id'] . "'")->fetchColumn() === 0, 'journal : frais à la charge de l\'organisme, pas de moncamp.fr');
$mg = array_values(array_filter(compta_marges($pdo, '2000-01-01', '2100-01-01', 2), fn($m) => $m['mode'] === 'direct'));
ok((int)$mg[0]['marge'] === 230, 'marge moncamp.fr d\'un paiement direct = sa commission (jamais négative)');

$GLOBALS['stripe_calls'] = []; $bal(10000, 0);
fin_refund($pdo, $h, 3000, ['annulation' => '1'], 'k-ok');
$c = array_column($GLOBALS['stripe_calls'], 1, 0);
ok(!isset($c['transfers.create']) && $c['refunds.create'][1]['stripe_account'] === 'acct_D' && $c['refunds.create'][0]['refund_application_fee'] === false, 'solde suffisant : remboursé sur le compte de l\'organisme, commission non rendue');
$nTr = count($GLOBALS['fake_transfers']->calls); $bal(1000, 0);
$pf0 = (float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn(); // déjà négatif des frais de litige de E (dette)
fin_refund($pdo, $h, 3000, ['annulation' => '2'], 'k-manque');
$tr = array_slice($GLOBALS['fake_transfers']->calls, $nTr);
ok($tr && $tr[0][1]['amount'] === 2000 && $tr[0][1]['destination'] === 'acct_D' && $tr[0][2]['idempotency_key'] === 'k-manque-avance', 'solde insuffisant : moncamp.fr avance les 20 EUR manquants');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn() - ($pf0 - 20.00)) < 0.001 && (int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE type = 'dette' AND reference = 'avance:k-manque'")->fetchColumn() === 2000, 'dette de 20 EUR : solde négatif et journal');
fin_refund($pdo, $h, 3000, ['annulation' => '2'], 'k-manque');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn() + 20.00) < 0.001, 'remboursement rejoué : dette comptée une fois');
$r = stripe_webhook_process($pdo, $ev('evt_21', 'charge.refunded', ['id' => 'ch_pi_H', 'payment_intent' => 'pi_H', 'amount_refunded' => 6000, 'amount' => 8000], 'acct_D'));
$h2 = $pdo->query("SELECT rembourse_cents, net_rembourse_cents FROM fin_paiements WHERE payment_intent = 'pi_H'")->fetch(PDO::FETCH_NUM);
ok(array_map('intval', $h2) === [6000, 6000] && (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE type = 'commission' AND reference LIKE 'rem:pi_H:%'")->fetchColumn() === 0, 'remboursement direct : l\'organisme rend tout, aucune commission rendue');

[$d, $o] = fin_checkout_params($pdo, 2, [6000]);
ok($d['metadata']['compensation'] === '2000' && $d['payment_intent_data']['application_fee_amount'] === fin_commission_split(6000)[0] + 2000, 'paiement suivant : la dette s\'ajoute à la commission prélevée');
$r = stripe_webhook_process($pdo, $ev('evt_22', 'checkout.session.completed', ['id' => 'cs_I', 'payment_status' => 'paid', 'payment_intent' => 'pi_I', 'amount_total' => 6000, 'amount_subtotal' => 6000,
    'metadata' => ['reservation_token' => 'tokI', 'mode' => 'direct', 'organisateur_id' => '2', 'commission' => (string)fin_commission_split(6000)[0], 'compensation' => '2000']], 'acct_D'));
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 2")->fetchColumn()) < 0.001 && (int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE type = 'dette' AND reference = 'comp:pi_I'")->fetchColumn() === -2000, 'dette compensée et journalisée (« compensée le … »)');

// ---------------------------------------------------------------- 12. paiement lisible dans le Stripe de l'organisme
$pdo->exec("CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY, prenom VARCHAR(50), nom VARCHAR(50), email VARCHAR(80))");
$pdo->exec("INSERT INTO users (id, prenom, nom, email) VALUES (9, 'Claire', 'Durand', 'claire@example.test')");
$pdo->exec("UPDATE camps SET date_debut = '2027-07-12', date_fin = '2027-07-18' WHERE id = 2");
$l = fin_lisible($pdo, ['tokB', 'tokC']);
$m = $l['payment_intent_data']['metadata'];
ok($l['payment_intent_data']['description'] === 'Hiver · 12–18 juil. · Tom D., Zoé D.', 'description courte : séjour, dates, enfants');
ok($m['Séjour'] === 'Hiver' && $m['Session'] === 'du 12/07/2027 au 18/07/2027' && $m['Enfant(s)'] === 'Tom Durand, Zoé Durand' && $m['Parent'] === 'Claire Durand'
    && $m['Email parent'] === 'claire@example.test' && $m['Commission moncamp.fr'] === '3,73 €' && $m['Inscriptions'] === 'tokB,tokC' && count($m) <= 50, 'métadonnées lisibles (aucune donnée de santé)');
ok(fin_periode_courte('2027-07-28', '2027-08-03') === '28 juil.–3 août' && mb_strlen(fin_lisible_params(str_repeat('x', 900), null, null, [], '', '', 0, [])['payment_intent_data']['metadata']['Séjour']) === 500, 'dates sur deux mois ; valeurs limitées à 500 caractères');
$GLOBALS['stripe_resp']['paymentIntents.retrieve'] = (object)['description' => 'Hiver · 12–18 juil. · Tom D.', 'metadata' => (object)['Séjour' => 'Hiver'],
    'latest_charge' => (object)['transfer' => (object)['destination' => 'acct_D', 'destination_payment' => 'py_1']]];
$GLOBALS['stripe_calls'] = [];
ok(fin_copy_to_destination($pdo, 'pi_D'), 'destination : copie sur le paiement côté organisme');
$u = array_values(array_filter($GLOBALS['stripe_calls'], fn($c) => $c[0] === 'charges.update'))[0][1];
ok($u[0] === 'py_1' && $u[1]['metadata'] === ['Séjour' => 'Hiver'] && $u[2] === ['stripe_account' => 'acct_D', 'idempotency_key' => 'moncamp-copie-pi_D'], 'mise à jour de py_… avec l\'en-tête stripe_account, idempotente');
$GLOBALS['stripe_resp']['charges.update'] = new \Stripe\Exception\ApiErrorException('réseau');
$nAud = (int)$pdo->query("SELECT COUNT(*) FROM fin_audit WHERE action = 'copie_metadonnees_echec'")->fetchColumn();
ok(!fin_copy_to_destination($pdo, 'pi_D') && (int)$pdo->query("SELECT COUNT(*) FROM fin_audit WHERE action = 'copie_metadonnees_echec'")->fetchColumn() === $nAud + 1, 'échec non bloquant, journalisé');

echo "Tout est bon.\n";
