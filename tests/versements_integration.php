<?php
// Test d'intégration du calendrier des versements : réserve, versements partiels, reversement après remboursement, dette, jours de versement.
// Nécessite une base MySQL/MariaDB JETABLE (le script crée et vide ses tables) :
//   FIN_TEST_DSN="mysql:host=127.0.0.1;port=3399;dbname=t" php tests/versements_integration.php
// Stripe est remplacé par tests/stripe_stub.php (aucun appel réseau).
$dsn = getenv('FIN_TEST_DSN');
if (!$dsn) { echo "FIN_TEST_DSN non défini : test ignoré.\n"; exit(0); }
define('STRIPE_SECRET_KEY', 'sk_test_stub');
require __DIR__ . '/stripe_stub.php';
require __DIR__ . '/../api/finance_lib.php';

function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
$pdo = new PDO($dsn, getenv('FIN_TEST_USER') ?: 'root', getenv('FIN_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['fin_paiements', 'fin_transferts', 'fin_transfert_lignes', 'fin_reversals', 'fin_reversal_ops', 'fin_reglages', 'fin_audit', 'stripe_comptes', 'camps', 'organisateurs', 'inscriptions'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), user_id INT, portefeuille DECIMAL(12,2) NOT NULL DEFAULT 0, solde_points INT DEFAULT 0, stripe_account_id VARCHAR(64) NULL, adresse_complete VARCHAR(80) NULL, code_postal_orga VARCHAR(10) NULL, ville_orga VARCHAR(50) NULL, email VARCHAR(80) NULL)");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), date_debut DATE, organisateur_id INT)");
$pdo->exec("CREATE TABLE inscriptions (id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT, enfant_id INT, tarif_id INT NULL, statut VARCHAR(20), prix_final DECIMAL(10,2), statut_paiement VARCHAR(20), reservation_token VARCHAR(64), mode_paiement VARCHAR(20), montant_paye DECIMAL(10,2) DEFAULT 0)");
$pdo->exec("INSERT INTO organisateurs (nom) VALUES ('A'), ('B'), ('C'), ('D'), ('E'), ('F'), ('G')");
fin_ensure($pdo);

// --- jours de versement : 7 organismes -> jours 1..6 tous utilisés, jamais le dimanche, écart max 1
$jours = $pdo->query("SELECT jour_versement j, COUNT(*) n FROM organisateurs GROUP BY j")->fetchAll(PDO::FETCH_KEY_PAIR);
ok(count($jours) === 6 && min(array_keys($jours)) >= 1 && max(array_keys($jours)) <= 6, 'jours de versement : lundi à samedi, jamais dimanche');
ok(max($jours) - min($jours) <= 1, 'jours de versement : charge équilibrée (7 organismes sur 6 jours)');
fin_ensure($pdo); // idempotent
ok((int)$pdo->query("SELECT COUNT(*) FROM organisateurs WHERE jour_versement IS NULL")->fetchColumn() === 0, 'attribution idempotente');
ok(fin_next_payout_date(5, strtotime('2026-11-16')) === '2026-11-20', 'prochain vendredi depuis un lundi');
ok(fin_next_payout_date(1, strtotime('2026-11-16')) === '2026-11-16', 'le jour même est inclus');

// --- données : organisme 1 (compte Stripe prêt), séjour A dans 60 jours (réserve), séjour B commencé hier (pas de réserve)
$pdo->exec("UPDATE organisateurs SET portefeuille = 170.00, stripe_account_id = 'acct_1' WHERE id = 1");
$pdo->exec("INSERT INTO stripe_comptes (organisateur_id, account_id, transfers_actif, payouts_enabled, details_submitted, charges_enabled) VALUES (1, 'acct_1', 1, 1, 1, 1)");
$pdo->exec("INSERT INTO camps (id, nom, date_debut, organisateur_id) VALUES (1, 'A', DATE_ADD(CURDATE(), INTERVAL 60 DAY), 1), (2, 'B', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 1)");
$ins = $pdo->prepare("INSERT INTO fin_paiements (inscription_id, organisateur_id, camp_id, payment_intent, brut_cents, commission_cents, net_cents, commission_pct, date_deblocage) VALUES (?, 1, ?, ?, ?, ?, ?, 2.5, ?)");
$ins->execute([101, 1, 'pi1', 9500, 500, 9000, date('Y-m-d H:i:s', strtotime('-1 hour'))]);  // A : versable, réserve 20 %
$ins->execute([102, 2, 'pi2', 5300, 300, 5000, date('Y-m-d H:i:s', strtotime('-1 hour'))]);  // B : séjour commencé, pas de réserve
$ins->execute([103, 1, 'pi3', 3200, 200, 3000, date('Y-m-d H:i:s', strtotime('+3 day'))]);   // A : encore en délai de sécurité

$ov = fin_overview($pdo, 1);
ok($ov['reserve_cents'] === 1800, 'réserve retenue : 20 % de 90 EUR = 18 EUR');
ok($ov['delai_cents'] === 3000, 'en délai de sécurité : 30 EUR');
ok($ov['deblocable_cents'] === 12200, 'versable : 72 + 50 = 122 EUR');
ok($ov['a_recevoir_cents'] === 17000, 'à recevoir au total : 170 EUR');

// --- 1er versement : réserve gardée, ligne partielle
$tid = fin_prepare_transfer($pdo, 1, 'acct_1', 'sejour');
ok($tid > 0, 'transfert préparé');
$t = $pdo->query("SELECT * FROM fin_transferts WHERE id = $tid")->fetch(PDO::FETCH_ASSOC);
ok((int)$t['montant_cents'] === 12200, 'montant du transfert : 122 EUR (réserve de 18 EUR retenue)');
ok(fin_execute_transfer($pdo, $tid) === 'envoye', 'transfert envoyé (Stripe simulé)');
$p = $pdo->query("SELECT inscription_id i, statut s, verse_cents v FROM fin_paiements ORDER BY inscription_id")->fetchAll(PDO::FETCH_ASSOC);
ok($p[0]['s'] === 'attente' && (int)$p[0]['v'] === 7200, 'ligne A : reste en attente (réserve) après 72 EUR versés');
ok($p[1]['s'] === 'verse' && (int)$p[1]['v'] === 5000, 'ligne B : soldée');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - 48.00) < 0.001, 'portefeuille : 170 - 122 = 48 EUR');
ok(fin_prepare_transfer($pdo, 1, 'acct_1', 'sejour') === null, 'deuxième passage : rien à verser (réserve conservée, délai non écoulé)');

// --- la réserve est libérée quand le séjour A commence
$pdo->exec("UPDATE camps SET date_debut = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = 1");
$tid2 = fin_prepare_transfer($pdo, 1, 'acct_1', 'sejour');
ok($tid2 > 0 && (int)$pdo->query("SELECT montant_cents FROM fin_transferts WHERE id = $tid2")->fetchColumn() === 1800, 'réserve libérée : 18 EUR versés');
ok(fin_execute_transfer($pdo, $tid2) === 'envoye', 'second transfert envoyé');
ok($pdo->query("SELECT statut FROM fin_paiements WHERE inscription_id = 101")->fetchColumn() === 'verse', 'ligne A soldée');
// justificatif : lignes du transfert partiel
$rc = fin_receipt($pdo, $t['token'], 0, true);
ok(count($rc['lignes']) === 2 && (int)$rc['lignes'][0]['montant_ligne'] + (int)$rc['lignes'][1]['montant_ligne'] === 12200, 'justificatif : détail par inscription du versement');

// --- remboursement total de B APRÈS versement : reversement Stripe
$wBefore = (float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn();  // 48 - 18 = 30 EUR
$pdo->beginTransaction(); fin_apply_refund($pdo, 'pi2', 5300, 5300); $pdo->commit();
$rv = $pdo->query("SELECT * FROM fin_reversals")->fetchAll(PDO::FETCH_ASSOC);
ok(count($rv) === 1 && (int)$rv[0]['montant_cents'] === 5000 && $rv[0]['statut'] === 'a_faire', 'reversement demandé : 50 EUR déjà versés');
$rep = ['reversements' => 0];
fin_process_reversals($pdo, $rep);
$rv = $pdo->query("SELECT * FROM fin_reversals")->fetch(PDO::FETCH_ASSOC);
ok($rv['statut'] === 'fait' && (int)$rv['recupere_cents'] === 5000, 'reversement effectué : 50 EUR récupérés');
ok((int)$pdo->query("SELECT reverse_cents FROM fin_transferts WHERE id = $tid")->fetchColumn() === 5000, 'transfert d\'origine : 50 EUR reversés');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - $wBefore) < 0.001, 'solde de l\'organisme inchangé (argent revenu)');
$calls = count($GLOBALS['fake_transfers']->calls);
fin_process_reversals($pdo, $rep);
ok(count($GLOBALS['fake_transfers']->calls) === $calls, 'idempotence : aucun second reversement');
ok($GLOBALS['fake_transfers']->calls[$calls - 1][3]['idempotency_key'] === 'colomap-rev-1', 'clé d\'idempotence Stripe par opération');

// --- remboursement partiel de A (litige perdu) avec solde Stripe insuffisant : dette, solde négatif
$GLOBALS['stripe_fail'] = 'reversal';
$pdo->beginTransaction(); fin_apply_refund($pdo, 'pi1', 9500, 9500); $pdo->commit();
fin_process_reversals($pdo, $rep);
$rv = $pdo->query("SELECT * FROM fin_reversals ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($rv['statut'] === 'dette' && (int)$rv['montant_cents'] === 9000, 'solde Stripe insuffisant : dette de 90 EUR enregistrée');
ok((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() < 0, 'solde négatif : compensé sur les prochains versements');
ok((int)$pdo->query("SELECT reverse_cents FROM fin_transferts WHERE id = $tid2")->fetchColumn() === 0, 'capacité de reversement restituée après l\'échec');
$GLOBALS['stripe_fail'] = null;
$n = count($GLOBALS['fake_transfers']->calls); fin_process_reversals($pdo, $rep);
ok(count($GLOBALS['fake_transfers']->calls) === $n, 'une dette n\'est pas rejouée automatiquement');
ok(fin_reversal_retenter($pdo, (int)$rv['id'], 'test') === true, 'un administrateur peut retenter');
fin_process_reversals($pdo, $rep);
ok($pdo->query("SELECT statut FROM fin_reversals WHERE id = " . (int)$rv['id'])->fetchColumn() === 'fait', 'reversement retenté : dette soldée');

// --- échec définitif du transfert : lignes remises en attente, solde rendu, rien de versé
$ins->execute([104, 2, 'pi4', 2150, 150, 2000, date('Y-m-d H:i:s', strtotime('-1 hour'))]);
$pdo->exec("UPDATE organisateurs SET portefeuille = portefeuille + 20.00 WHERE id = 1");
$w0 = round((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn(), 2);
$GLOBALS['stripe_fail'] = 'transfer';
$tid3 = fin_prepare_transfer($pdo, 1, 'acct_1', 'sejour');
ok($tid3 > 0 && fin_execute_transfer($pdo, $tid3) === 'echec', 'transfert refusé par Stripe : échec définitif');
$l = $pdo->query("SELECT statut, verse_cents FROM fin_paiements WHERE inscription_id = 104")->fetch(PDO::FETCH_ASSOC);
ok($l['statut'] === 'attente' && (int)$l['verse_cents'] === 0, 'ligne remise en attente, rien de versé');
ok(abs((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn() - $w0) < 0.001, 'solde de organisme rendu');
$GLOBALS['stripe_fail'] = null;

// --- paiement hors Stripe : l'organisateur marque « reçu », commission 1 % déduite de la cagnotte carte
$pdo->exec("UPDATE organisateurs SET user_id = 7 WHERE id = 1");
$tok = str_repeat('a', 32);
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, prix_final, statut_paiement, reservation_token, mode_paiement, moyen_paiement) VALUES (301, 1, 9, 'Confirmé', 100.00, 'A_REGLER', '$tok', 'CHEQUE', 'cheque')");
$w1 = round((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn(), 2);
ok(fin_marquer_recu($pdo, $tok, 99)[0] === false, 'un autre utilisateur ne peut pas marquer reçu');
ok(fin_marquer_recu($pdo, str_repeat('b', 32), 7)[0] === false, 'jeton inconnu refusé');
[$okr, $msg] = fin_marquer_recu($pdo, $tok, 7);
ok($okr === true, 'organisateur : règlement marqué reçu');
ok($pdo->query("SELECT statut_paiement FROM inscriptions WHERE id = 301")->fetchColumn() === 'PAYE', 'inscription passée à payée');
ok((int)$pdo->query("SELECT commission_cents FROM fin_commissions_hors WHERE inscription_id = 301")->fetchColumn() === 100, 'commission hors Stripe : 1 % de 100 EUR = 1 EUR');
ok(abs(round((float)$pdo->query("SELECT portefeuille FROM organisateurs WHERE id = 1")->fetchColumn(), 2) - ($w1 - 1.00)) < 0.001, 'commission déduite de la cagnotte carte (solde)');
ok(fin_marquer_recu($pdo, $tok, 7)[0] === false && (int)$pdo->query("SELECT COUNT(*) FROM fin_commissions_hors")->fetchColumn() === 1, 'idempotent : pas de seconde commission');
$ms = fin_moyens_stats($pdo, 1);
ok(isset($ms['cheque']) && $ms['cheque']['nb'] === 1 && $ms['cheque']['commission_cents'] === 100, 'statistiques par moyen de paiement');
require __DIR__ . '/../api/compta_lib.php';
ok(compta_hors_stripe($pdo, '2000-01-01', '2100-01-01')['commission'] === 100, 'comptabilité : commissions hors Stripe');
ok(fin_moyens_du_camp(['moyens_paiement' => '']) === ['carte'] && fin_moyens_du_camp(['moyens_paiement' => 'cheque,carte,xx']) === ['cheque', 'carte'], 'moyens du séjour : carte par défaut, codes inconnus ignorés');

// --- calcul pur
ok(fin_reserve_needed(date('Y-m-d', strtotime('+20 day')), 15, date('Y-m-d')) === true, 'réserve : fenêtre 50 % ouverte');
ok(fin_reserve_needed(date('Y-m-d', strtotime('+10 day')), 15, date('Y-m-d')) === false, 'plus de réserve : fenêtre de remboursement fermée');
echo "Tout est bon.\n";
