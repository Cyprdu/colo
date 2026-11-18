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
ok($c && $c[0][1][1]['settings']['payouts']['schedule'] === ['interval' => 'manual'] && $c[0][1][1]['settings']['payouts']['debit_negative_balances'] === true
   && (int)$pdo->query("SELECT payout_manuel FROM stripe_comptes")->fetchColumn() === 1, 'compte en virements pilotés par moncamp.fr (quotidiens + bouton), solde négatif prélevé par Stripe');
// ---- C. paiements reçus par moncamp.fr avant l'activation (legacy) : transférés tout de suite quand le compte est prêt
$pdo->exec("INSERT INTO enfants (id, parent_id, nom, prenom) VALUES (1, 9, 'Durand', 'Tom')");
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (201, 1, 1, 'Confirmé', 300, 'PAYE', 'tokL')");
$pdo->exec("INSERT INTO fin_paiements (inscription_id, organisateur_id, camp_id, payment_intent, brut_cents, commission_cents, net_cents, commission_pct, date_deblocage, mode)
            VALUES (201, 1, 1, 'pi_L', 30000, 780, 29220, 2.5, DATE_ADD(NOW(), INTERVAL 90 DAY), 'legacy')");
$pdo->exec("UPDATE organisateurs SET portefeuille = portefeuille + 292.20 WHERE id = 1");
$GLOBALS['fake_transfers'] = null; $GLOBALS['stripe_calls'] = [];
$GLOBALS['stripe_resp']['paymentIntents.retrieve'] = (object)['id' => 'pi_L', 'latest_charge' => 'ch_L'];
$before = fin_ledger_totaux($pdo, 1);
$r = fin_release_pending_now($pdo, 1);
$tr = $GLOBALS['fake_transfers']->calls;
ok($r['transferts'] === 1 && $r['montant_cents'] === 29220 && count($tr) === 1 && $tr[0][2]['idempotency_key'] === 'moncamp-tr-paiement-' . $pdo->query("SELECT id FROM fin_paiements WHERE inscription_id = 201")->fetchColumn() . '-' . $pdo->query("SELECT MAX(id) FROM fin_transferts WHERE type = 'sejour'")->fetchColumn()
   && $tr[0][1]['source_transaction'] === 'ch_L', 'compte prêt : tout le net transféré tout de suite, adossé à la charge (fonds en attente acceptés), clé par paiement');
ok(strpos($tr[0][1]['description'], 'Été · Tom DURAND · 300,00 € − commission moncamp.fr 7,80 € = 292,20 €') !== false && $tr[0][1]['metadata']['type'] === 'paiements_avant_activation', 'description lisible dans le Stripe de l\'organisme : ' . $tr[0][1]['description']);
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
// ---- prochain virement : virement en route (date Stripe), sinon solde du compte connecté (disponible, ou date available_on)
$pdo->exec("UPDATE fin_transferts SET statut = 'paye' WHERE type = 'payout' AND statut = 'envoye'");
$GLOBALS['stripe_resp']['balance.retrieve'] = (object)['available' => [(object)['amount' => 0, 'currency' => 'eur']], 'pending' => [(object)['amount' => 4000, 'currency' => 'eur']]];
$GLOBALS['stripe_resp']['balanceTransactions.all'] = (object)['data' => [
    (object)['status' => 'pending', 'currency' => 'eur', 'net' => 1500, 'available_on' => strtotime('+4 days')],
    (object)['status' => 'pending', 'currency' => 'eur', 'net' => 2500, 'available_on' => strtotime('+2 days')],
    (object)['status' => 'available', 'currency' => 'eur', 'net' => 999, 'available_on' => strtotime('-3 days')]]];
$pv = fin_prochain_virement($pdo, 1);
ok($pv && $pv['m'] === 2500 && $pv['d'] === date('Y-m-d', strtotime('+2 days')) && $pv['etat'] === 'attente', 'prochain virement : 25 EUR le ' . date('d/m', strtotime('+2 days')) . ' (date de disponibilité Stripe)');
$GLOBALS['stripe_resp']['balance.retrieve'] = (object)['available' => [(object)['amount' => 700, 'currency' => 'eur']], 'pending' => []];
ok(fin_prochain_virement($pdo, 1)['etat'] === 'dispo' && fin_prochain_virement($pdo, 1)['m'] === 700, 'fonds disponibles : virement imminent');
fin_payout_record($pdo, (object)['id' => 'po_9', 'amount' => 3300, 'currency' => 'eur', 'status' => 'in_transit', 'arrival_date' => strtotime('+3 days'), 'created' => time()], 'acct_A');
$pv = fin_prochain_virement($pdo, 1);
ok($pv['etat'] === 'route' && $pv['m'] === 3300 && $pv['d'] === date('Y-m-d', strtotime('+3 days')), 'virement en route : montant et date d\'arrivée du payout');
// ---- admin « Mes revenus » : commission − frais Stripe payés par moncamp.fr = net, rapproché du solde Stripe de la plateforme
require_once __DIR__ . '/../api/compta_lib.php';
$b0 = compta_revenus_rapprochement($pdo, null);
fin_ledger_add($pdo, 'commission', 1, -1966, 'test:com-rev', 'paiement', 1, 'Commission moncamp.fr');
fin_ledger_add($pdo, 'frais', 1, -213, 'test:frais-rev', 'paiement', 1, 'Frais Stripe');
$b1 = compta_revenus_rapprochement($pdo, null);
ok($b1['com'] - $b0['com'] === 1966 && $b1['frais'] - $b0['frais'] === 213 && $b1['net'] - $b0['net'] === 1753, 'commission 19,66 − frais 2,13 = net 17,53');
$ok = compta_revenus_rapprochement($pdo, ['disponible' => $b1['attendu'] - 500, 'a_venir' => 500]);
$ko = compta_revenus_rapprochement($pdo, ['disponible' => $b1['attendu'] + 213, 'a_venir' => 0]);
ok($ok['ecart'] === 0 && $ko['ecart'] === 213, 'rapprochement : égal au solde Stripe (disponible + à venir), sinon écart affiché');
// ---- Règle : moncamp.fr ne paie JAMAIS les frais Stripe d'un organisme (paiement reçu avant l'activation, repli destination)
$GLOBALS['stripe_resp'] = []; $GLOBALS['fake_transfers'] = null;
$plat0 = compta_revenus_rapprochement($pdo, null)['frais'];
$pdo->exec("UPDATE organisateurs SET portefeuille = 0 WHERE id = 1");
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (202, 1, 1, 'Confirmé', 100, 'PAYE', 'tokN')");
$ligne = [['id' => 202, 'prix_final' => 100, 'camp_id' => 1, 'date_debut' => date('Y-m-d', strtotime('+60 days')), 'organisateur_id' => 1, 'tva' => null]];
ok(fin_record_lines($pdo, $ligne, 'pi_N', 'cs_N', 10000, 'legacy') === 1, 'paiement reçu avant l\'activation (legacy) enregistré');
$n = $pdo->query("SELECT * FROM fin_paiements WHERE inscription_id = 202")->fetch(PDO::FETCH_ASSOC);
ok((int)$n['frais_orga'] === 1 && (int)$n['net_cents'] === 10000 - 280, 'frais à la charge de l\'organisme, net provisoire = brut − commission');
$GLOBALS['stripe_resp']['paymentIntents.retrieve'] = (object)['id' => 'pi_N', 'latest_charge' => (object)['id' => 'ch_N']]; // frais pas encore connus chez Stripe
$r = fin_release_pending_now($pdo, 1);
ok($r['transferts'] === 0 && !($GLOBALS['fake_transfers']->calls ?? []) && $pdo->query("SELECT statut FROM fin_paiements WHERE id = " . (int)$n['id'])->fetchColumn() === 'attente', 'frais réels inconnus : aucun transfert (la ligne attend)');
$GLOBALS['stripe_resp']['paymentIntents.retrieve'] = (object)['id' => 'pi_N', 'latest_charge' => (object)['id' => 'ch_N', 'balance_transaction' => (object)['currency' => 'eur', 'fee' => 175]]];
$r = fin_release_pending_now($pdo, 1);
$tr = $GLOBALS['fake_transfers']->calls[0][1] ?? [];
ok($r['transferts'] === 1 && $tr['amount'] === 10000 - 280 - 175 && $tr['source_transaction'] === 'ch_N', 'transfert = brut − commission − frais Stripe RÉELS (97,20 − 1,75 = 95,45 EUR), adossé à la charge');
ok(strpos($tr['description'], 'commission moncamp.fr 2,80 € − frais de paiement Stripe 1,75 € = 95,45 €') !== false, 'description Stripe : commission et frais Stripe sur deux postes');
ok((int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE type = 'frais_paiement' AND reference = 'frais:" . (int)$n['id'] . "'")->fetchColumn() === -175
   && (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE type = 'frais' AND reference = 'frais:" . (int)$n['id'] . "'")->fetchColumn() === 0
   && abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn()) < 0.001, 'journal : frais à la charge de l\'organisme, solde soldé');

// destination : frais estimés prélevés avec la commission, puis ajustés aux frais réels
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (203, 1, 1, 'Confirmé', 100, 'PAYE', 'tokO'), (204, 1, 1, 'Confirmé', 100, 'PAYE', 'tokP')");
fin_record_lines($pdo, [['id' => 203] + $ligne[0]], 'pi_O', 'cs_O', 10000, 'destination');
fin_record_lines($pdo, [['id' => 204] + $ligne[0]], 'pi_P', 'cs_P', 10000, 'destination');
$o = $pdo->query("SELECT * FROM fin_paiements WHERE inscription_id = 203")->fetch(PDO::FETCH_ASSOC);
ok((int)$o['frais_estimes_cents'] === 175 && (int)$o['net_cents'] === 10000 - 280 - 175, 'destination : frais estimés 1,5 % + 0,25 € déduits du net');
$GLOBALS['stripe_resp']['paymentIntents.retrieve'] = fn($id) => (object)['id' => $id, 'latest_charge' => (object)['id' => 'ch_' . $id, 'application_fee' => 'fee_' . $id,
    'balance_transaction' => (object)['currency' => 'eur', 'fee' => $id === 'pi_O' ? 300 : 100]]];
$pf = (float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn();
fin_sync_fees($pdo, 'pi_O');
ok((int)$pdo->query("SELECT net_cents FROM fin_paiements WHERE inscription_id = 203")->fetchColumn() === 10000 - 280 - 300
   && (int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE type = 'dette' AND reference LIKE 'fraisdette:frais:" . (int)$o['id'] . ":%'")->fetchColumn() === 125
   && abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - ($pf - 1.25)) < 0.001, 'frais réels > estimés : 1,25 EUR de dette, compensée comme les autres');
$GLOBALS['stripe_calls'] = [];
fin_sync_fees($pdo, 'pi_P');
$rf = array_values(array_filter($GLOBALS['stripe_calls'], fn($c) => $c[0] === 'applicationFees.createRefund'));
ok($rf && $rf[0][1][0] === 'fee_pi_P' && $rf[0][1][1]['amount'] === 75 && (int)$pdo->query("SELECT net_cents FROM fin_paiements WHERE inscription_id = 204")->fetchColumn() === 10000 - 280 - 100, 'frais réels < estimés : 0,75 EUR rendus à l\'organisme');
fin_sync_fees($pdo, 'pi_O');
ok((int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE type = 'dette' AND reference LIKE 'fraisdette:frais:" . (int)$o['id'] . ":%'")->fetchColumn() === 1, 'relevé rejoué : ajustement fait une seule fois');
$t = fin_ledger_totaux($pdo, 1);
ok((int)$pdo->query("SELECT SUM(montant_cents) FROM fin_ledger WHERE type = 'frais_paiement' AND objet_id = '" . (int)$o['id'] . "'")->fetchColumn() === -300, 'journal : frais réels de 3 EUR à la charge de l\'organisme (estimation + ajustement)');
ok(compta_revenus_rapprochement($pdo, null)['frais'] === $plat0, 'admin : frais Stripe payés par moncamp.fr inchangés (0 pour les nouveaux paiements)');
// ---- rattrapage : frais déjà payés par moncamp.fr (ancien paiement transféré avant activation, cas SJMV) → dette de l'organisme, une seule fois
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token) VALUES (205, 1, 1, 'Confirmé', 125, 'PAYE', 'tokQ')");
$pdo->exec("INSERT INTO fin_paiements (inscription_id, organisateur_id, camp_id, payment_intent, brut_cents, commission_cents, net_cents, commission_pct, date_deblocage, mode, statut, verse_cents, frais_stripe_cents, created_at)
            VALUES (205, 1, 1, 'pi_Q', 12500, 343, 12157, 2.5, NOW(), 'legacy', 'verse', 12157, 213, '2026-11-03 10:00:00')");
$qid = (int)$pdo->query("SELECT id FROM fin_paiements WHERE inscription_id = 205")->fetchColumn();
$pdo->exec("INSERT INTO fin_ledger (type, organisateur_id, montant_cents, reference, objet_type, objet_id, libelle) VALUES ('frais', 1, -213, 'frais:$qid', 'paiement', '$qid', 'Frais Stripe')");
$pdo->exec("DELETE FROM fin_reglages WHERE cle = 'rattrapage_frais_v1'");
$pdo->exec("UPDATE fin_paiements SET frais_stripe_cents = 0 WHERE frais_orga = 0 AND frais_stripe_cents IS NULL AND inscription_id <> 205"); // autres anciens paiements : frais relevés (sinon le rattrapage les attend)
$pf = (float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn();
$rap0 = compta_revenus_rapprochement($pdo, null);
$n1 = fin_frais_rattrapage($pdo);
$l = $pdo->query("SELECT montant_cents, libelle FROM fin_ledger WHERE type = 'dette' AND reference = 'fraisrattrapage:$qid'")->fetch(PDO::FETCH_ASSOC);
ok($n1 >= 1 && (int)$l['montant_cents'] === 213 && $l['libelle'] === 'Frais Stripe du paiement du 03/11 (Été · Tom DURAND)', 'rattrapage : dette de 2,13 EUR « ' . ($l['libelle'] ?? '') . ' »');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - ($pf - (float)$n1 * 0 - (int)$pdo->query("SELECT SUM(montant_cents) FROM fin_ledger WHERE reference LIKE 'fraisrattrapage:%'")->fetchColumn() / 100)) < 0.001, 'solde de l\'organisme débité (compensé sur ses paiements suivants)');
ok(fin_frais_rattrapage($pdo) === 0 && (int)$pdo->query("SELECT COUNT(*) FROM fin_ledger WHERE reference = 'fraisrattrapage:$qid'")->fetchColumn() === 1
   && (int)$pdo->query("SELECT COUNT(*) FROM fin_reglages WHERE cle = 'rattrapage_frais_v1'")->fetchColumn() === 1, 'rattrapage : fait une seule fois, puis ne refait rien');
ok(compta_revenus_rapprochement($pdo, null)['attendu'] === $rap0['attendu'], 'rapprochement : une dette à recevoir n\'est pas de l\'argent sorti');

// ---- ajustement manuel (admin) : dette et crédit, motif obligatoire, journalisé
$pf = (float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn();
$ref = fin_ajustement_manuel($pdo, 1, 'dette', 1000, 'Frais de chèque impayé', 'admin:1');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - ($pf - 10)) < 0.001
   && (int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE type = 'dette' AND reference = '$ref'")->fetchColumn() === 1000
   && (int)$pdo->query("SELECT COUNT(*) FROM fin_audit WHERE action = 'ajustement_manuel'")->fetchColumn() === 1, 'ajustement : dette de 10 EUR (solde, journal, audit)');
fin_ajustement_manuel($pdo, 1, 'credit', 500, 'Geste commercial', 'admin:1');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - ($pf - 5)) < 0.001, 'ajustement : crédit de 5 EUR');
try { fin_ajustement_manuel($pdo, 1, 'dette', 100, '  ', 'admin:1'); $e = ''; } catch (InvalidArgumentException $x) { $e = 'refus'; }
ok($e === 'refus', 'ajustement : motif obligatoire');
// ---- date de disponibilité (virement prévu) relevée avec les frais
$GLOBALS['stripe_resp']['paymentIntents.retrieve'] = fn($id) => (object)['id' => $id, 'latest_charge' => (object)['id' => 'ch_' . $id, 'balance_transaction' => (object)['currency' => 'eur', 'fee' => 100, 'available_on' => strtotime('2026-11-25 12:00')]]];
fin_sync_fees($pdo, 'pi_P');
ok($pdo->query("SELECT dispo_le FROM fin_paiements WHERE inscription_id = 204")->fetchColumn() === '2026-11-25', 'date de disponibilité (available_on) enregistrée pour la date de virement prévue');
// ---- « Virer mon argent maintenant » et virement quotidien du disponible
$pdo->exec("UPDATE stripe_comptes SET payouts_enabled = 1, details_submitted = 1, charges_enabled = 1, transfers_actif = 1, payout_manuel = 1 WHERE organisateur_id = 1");
$GLOBALS['stripe_calls'] = [];
$GLOBALS['stripe_resp']['balance.retrieve'] = (object)['available' => [(object)['amount' => 4200, 'currency' => 'eur']], 'pending' => []];
$GLOBALS['stripe_resp']['payouts.create'] = fn($p, $o) => (object)['id' => 'po_btn_' . $p['amount'], 'amount' => $p['amount'], 'currency' => 'eur', 'status' => 'pending', 'arrival_date' => strtotime('+2 days'), 'created' => time()];
$v = fin_virer_maintenant($pdo, 1, 'user:7');
$pc = array_values(array_filter($GLOBALS['stripe_calls'], fn($c) => $c[0] === 'payouts.create'))[0][1];
ok($v['m'] === 4200 && $pc[1]['stripe_account'] === 'acct_A' && str_starts_with($pc[1]['idempotency_key'], 'moncamp-po-1-4200-')
   && $pdo->query("SELECT statut FROM fin_transferts WHERE stripe_transfer_id = 'po_btn_4200'")->fetchColumn() === 'envoye', 'bouton : tout le disponible viré (42 EUR), relevé et journalisé');
ok(fin_virer_maintenant($pdo, 1, 'cron', true) === null, 'quotidien : déjà un virement aujourd\'hui, rien de plus');
$GLOBALS['stripe_resp']['balance.retrieve'] = (object)['available' => [(object)['amount' => 50, 'currency' => 'eur']], 'pending' => []];
ok(fin_virer_maintenant($pdo, 1, 'user:7') === null, 'moins de 1 EUR disponible : aucun virement');
// ---- D. mandat SEPA, prélèvement automatique d'une dette impayée 15 jours, points par prélèvement
$ev2 = fn(string $id, string $type, array $o, string $acct = '') => json_decode(json_encode(['id' => $id, 'type' => $type, 'account' => $acct, 'data' => ['object' => $o]]));
$pdo->exec("CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY, prenom VARCHAR(50), nom VARCHAR(50), email VARCHAR(80))");
$tok1 = fin_orga_tok(1, $pdo);
$r = stripe_webhook_process($pdo, $ev2('evt_m1', 'checkout.session.completed', ['id' => 'cs_m1', 'mode' => 'setup', 'payment_status' => 'no_payment_required', 'setup_intent' => 'seti_1', 'metadata' => ['type' => 'mandat_sepa', 'organisateur' => $tok1]]));
$GLOBALS['stripe_resp']['setupIntents.retrieve'] = (object)['id' => 'seti_1', 'status' => 'succeeded', 'payment_method' => (object)['id' => 'pm_sepa1', 'sepa_debit' => (object)['last4' => '4321']]];
$GLOBALS['stripe_resp']['customers.create'] = (object)['id' => 'cus_o1'];
stripe_webhook_after($pdo, $r[2]);
$si = fin_sepa_info($pdo, 1);
ok($r[2]['mandat'] === [1, 'seti_1'] && $si['sepa_pm'] === 'pm_sepa1' && $si['sepa_last4'] === '4321' && $si['stripe_customer_id'] === 'cus_o1', 'mandat SEPA enregistré (moyen de paiement du client plateforme de l\'organisme)');
$pdo->exec("UPDATE organisateurs SET portefeuille = -20.00, dette_depuis = NOW() - INTERVAL 16 DAY, sepa_dette_pi = NULL WHERE id = 1");
$GLOBALS['stripe_calls'] = []; $GLOBALS['stripe_resp']['paymentIntents.create'] = (object)['id' => 'pi_sepa_d1', 'status' => 'processing'];
$rep = fin_sepa_dettes_run($pdo);
$pc = array_values(array_filter($GLOBALS['stripe_calls'], fn($c) => $c[0] === 'paymentIntents.create'))[0][1] ?? [];
ok($rep['prelevements'] === 1 && $pc[0]['amount'] === 2000 && $pc[0]['off_session'] === true && $pc[0]['payment_method'] === 'pm_sepa1' && $pc[0]['customer'] === 'cus_o1' && !isset($pc[1]['stripe_account'])
   && fin_sepa_info($pdo, 1)['sepa_dette_pi'] === 'pi_sepa_d1', 'dette impayée depuis 15 jours : prélèvement SEPA de 20 EUR sur la plateforme (hors session)');
ok(fin_sepa_dettes_run($pdo)['prelevements'] === 0, 'un seul prélèvement en cours à la fois');
$r = stripe_webhook_process($pdo, $ev2('evt_s1', 'payment_intent.succeeded', ['id' => 'pi_sepa_d1', 'amount' => 2000, 'amount_received' => 2000, 'metadata' => ['type' => 'dette_sepa', 'sepa' => '1', 'organisateur' => $tok1]]));
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn()) < 0.001 && (int)$pdo->query("SELECT montant_cents FROM fin_ledger WHERE reference = 'dettesepa:pi_sepa_d1'")->fetchColumn() === -2000
   && fin_sepa_info($pdo, 1)['sepa_dette_pi'] === null && !empty($r[2]['notif']), 'prélèvement reçu : dette soldée, journalisée, notification');
$pdo->exec("UPDATE organisateurs SET portefeuille = -5.00, sepa_dette_pi = 'pi_sepa_d2' WHERE id = 1");
$r = stripe_webhook_process($pdo, $ev2('evt_s2', 'payment_intent.payment_failed', ['id' => 'pi_sepa_d2', 'amount' => 500, 'metadata' => ['type' => 'dette_sepa', 'sepa' => '1', 'organisateur' => $tok1]]));
ok(fin_sepa_info($pdo, 1)['sepa_dette_pi'] === null && abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() + 5.00) < 0.001 && $r[2]['notif'][1] === 'Prélèvement de votre dette refusé', 'prélèvement refusé : la dette reste, notification');
$pk = array_key_first(mk_packs()); $pp = mk_packs()[$pk];
$sp0 = (int)$pdo->query("SELECT solde_points FROM organisateurs WHERE id = 1")->fetchColumn();
stripe_webhook_process($pdo, $ev2('evt_s3', 'payment_intent.succeeded', ['id' => 'pi_pts1', 'amount' => $pp['cents'], 'amount_received' => $pp['cents'], 'metadata' => ['type' => 'buy_points', 'sepa' => '1', 'points_amount' => (string)$pp['points'], 'organisateur' => $tok1]]));
stripe_webhook_process($pdo, $ev2('evt_s3b', 'payment_intent.succeeded', ['id' => 'pi_pts1', 'amount' => $pp['cents'], 'amount_received' => $pp['cents'], 'metadata' => ['type' => 'buy_points', 'sepa' => '1', 'points_amount' => (string)$pp['points'], 'organisateur' => $tok1]]));
ok((int)$pdo->query("SELECT solde_points FROM organisateurs WHERE id = 1")->fetchColumn() === $sp0 + $pp['points'], 'points payés par prélèvement : crédités une seule fois à réception');
// ---- G. logo de l'organisme envoyé sur sa page de paiement (une fois par logo, fichier local seulement)
$png = function (int $w, int $h): string {
    $ch = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $raw = str_repeat("\0" . str_repeat("\x80", $w), $h);
    return "\x89PNG\r\n\x1a\n" . $ch('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $ch('IDAT', gzcompress($raw)) . $ch('IEND', '');
};
$pdo->exec("ALTER TABLE organisateurs ADD COLUMN IF NOT EXISTS logo_url VARCHAR(200) NULL");
file_put_contents(__DIR__ . '/tmp_logo_test.png', $png(128, 128));
$pdo->exec("UPDATE organisateurs SET logo_url = 'tests/tmp_logo_test.png', stripe_account_id = 'acct_A', logo_stripe_hash = NULL WHERE id = 1");
$GLOBALS['stripe_calls'] = []; $GLOBALS['stripe_resp']['files.create'] = (object)['id' => 'file_logo1'];
$ok1 = fin_branding_sync($pdo, 1);
$fc = array_values(array_filter($GLOBALS['stripe_calls'], fn($c) => $c[0] === 'files.create'));
$au = array_values(array_filter($GLOBALS['stripe_calls'], fn($c) => $c[0] === 'accounts.update'));
ok($ok1 && $fc && $fc[0][1][0]['purpose'] === 'business_logo' && $fc[0][1][1] === ['stripe_account' => 'acct_A'] && $au[0][1][1]['settings']['branding']['logo'] === 'file_logo1', 'logo envoyé sur le compte de l\'organisme (business_logo, branding)');
$GLOBALS['stripe_calls'] = [];
ok(fin_branding_sync($pdo, 1) && !$GLOBALS['stripe_calls'], 'logo inchangé : rien de renvoyé');
$pdo->exec("UPDATE organisateurs SET logo_url = 'https://exemple.test/logo.png', logo_stripe_hash = NULL WHERE id = 1");
ok(!fin_branding_sync($pdo, 1) && !$GLOBALS['stripe_calls'], 'logo hébergé ailleurs : jamais téléchargé');
@unlink(__DIR__ . '/tmp_logo_test.png');
echo "Tout est bon.\n";
