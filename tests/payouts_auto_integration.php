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
echo "Tout est bon.\n";
