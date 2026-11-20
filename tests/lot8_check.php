<?php
// Base JETABLE obligatoire (api/config.php de test pointant sur moncamp_lot8_test) : php tests/lot8_check.php
$root = dirname(__DIR__);
chdir($root);
require $root . '/api/config.php';
if (DB_NAME !== 'moncamp_lot8_test') { fwrite(STDERR, "Base non jetable : arrêt.\n"); exit(1); }
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
function boom(callable $f): bool { try { $f(); return false; } catch (Throwable $e) { return true; } }

foreach ($pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC) as $t) $pdo->exec("DROP TRIGGER IF EXISTS `{$t['Trigger']}`");
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), prenom VARCHAR(50), email VARCHAR(190), password VARCHAR(255), is_admin TINYINT DEFAULT 0, is_directeur TINYINT DEFAULT 0, bloque TINYINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(100), email VARCHAR(190), tel VARCHAR(30), web VARCHAR(255), user_id INT, portefeuille DECIMAL(10,2) DEFAULT 0, logo_url VARCHAR(255), slug VARCHAR(120), stripe_account_id VARCHAR(64), description TEXT)");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, token CHAR(32), nom VARCHAR(200), description TEXT, ville VARCHAR(120), date_debut DATE, date_fin DATE, organisateur_id INT, valide TINYINT DEFAULT 1, en_attente TINYINT DEFAULT 0, refuse TINYINT DEFAULT 0, supprime TINYINT DEFAULT 0, prive TINYINT DEFAULT 0, prix DECIMAL(8,2) DEFAULT 0)");
$pdo->exec("CREATE TABLE app_erreurs (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, type VARCHAR(30) NOT NULL, message TEXT NOT NULL, fichier VARCHAR(255) NULL, ligne INT NULL, url VARCHAR(300) NULL, methode VARCHAR(8) NULL, user_id INT NULL)");
$pdo->exec("CREATE TABLE adm_kv (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL)");
$pdo->exec("INSERT INTO users (id, nom, prenom, email, is_admin, is_directeur) VALUES (1, 'Owner', 'Ana', 'owner@test', 1, 1), (2, 'Dir', 'Bob', 'dir@test', 0, 1)");
$pdo->exec("INSERT INTO organisateurs (id, nom, email, user_id, slug) VALUES (1, 'Colo Soleil', 'colo@test', 2, 'colo-soleil'), (2, 'Import Afocal', 'owner@test', 1, 'import-afocal')");
require_once $root . '/api/ledger_lib.php';
fin_ledger_ensure($pdo);

// ---- 1. Factures
require_once $root . '/api/factures_lib.php';
$m = date('Y-m', strtotime('first day of last month')); $d = $m . '-10 10:00:00';
fin_ledger_add($pdo, 'paiement', 1, 50000, 'pay:a', '', '', '', 'test', null, $d);
fin_ledger_add($pdo, 'commission', 1, -2500, 'pay:a', '', '', '', 'test', null, $d);
fin_ledger_add($pdo, 'commission', 1, -1200, 'pay:b', '', '', '', 'test', null, $d);
fin_ledger_add($pdo, 'commission', 1, 300, 'rem:b', '', '', '', 'test', null, $d);
fin_ledger_add($pdo, 'commission', 1, -400, 'hors:9', '', '', '', 'test', null, $d);
fin_ledger_add($pdo, 'dette', 1, -700, 'comp:a', '', '', '', 'test', null, $d);
fin_ledger_add($pdo, 'commission', 1, -999, 'pay:c', '', '', '', 'test', null, date('Y-m-d H:i:s'));
fin_ledger_add($pdo, 'commission', 2, 500, 'rem:z', '', '', '', 'test', null, $d);
$r = fac_cron($pdo);
ok($r['generees'] === 2, 'deux documents générés pour le mois écoulé');
$f1 = $pdo->query("SELECT * FROM fin_factures WHERE organisateur_id = 1")->fetch();
ok($f1['type'] === 'facture' && (int)$f1['ttc_cents'] === 3800 && (int)$f1['dette_cents'] === 700, 'facture : 25 + 12 - 3 + 4 = 38 € TTC, dette compensée 7 € à part');
ok((int)$f1['ht_cents'] === 3800 && (int)$f1['tva_cents'] === 0, 'franchise de TVA : HT = TTC');
ok(str_starts_with($f1['pdf'], '%PDF') && hash('sha256', $f1['pdf']) === $f1['sha256'], 'PDF valide et empreinte');
$f2 = $pdo->query("SELECT * FROM fin_factures WHERE organisateur_id = 2")->fetch();
ok($f2['type'] === 'avoir' && (int)$f2['ttc_cents'] === -500, 'commission rendue seule : avoir de 5 €');
ok($pdo->query("SELECT GROUP_CONCAT(numero ORDER BY seq) FROM fin_factures")->fetchColumn() === 'MC-000001,MC-000002', 'numérotation continue');
ok(fac_cron($pdo)['generees'] === 0 && fac_cron($pdo, true)['generees'] === 0 && (int)$pdo->query("SELECT COUNT(*) FROM fin_factures")->fetchColumn() === 2, 'idempotent (cron rejoué ou forcé)');
ok(boom(fn() => $pdo->exec("UPDATE fin_factures SET ttc_cents = 1 WHERE id = {$f1['id']}")), 'montant inaltérable');
ok(boom(fn() => $pdo->exec("DELETE FROM fin_factures WHERE id = {$f1['id']}")), 'suppression impossible');
$pdo->exec("UPDATE fin_factures SET envoye_at = NOW() WHERE id = {$f1['id']}");
ok($pdo->query("SELECT envoye_at IS NOT NULL FROM fin_factures WHERE id = {$f1['id']}")->fetchColumn() == 1, "seule la date d'envoi se modifie");
$a = fac_annuler($pdo, $f1);
ok($a['type'] === 'avoir' && $a['ttc_cents'] === -3800 && $a['numero'] === 'MC-000003' && (int)$a['origine_id'] === (int)$f1['id'], 'avoir intégral lié');
ok(boom(fn() => fac_annuler($pdo, $f1)), 'pas de double annulation');
$n = fac_generer($pdo, 1, $m);
ok($n['numero'] === 'MC-000004' && (int)$n['ttc_cents'] === 3800, 'nouvelle facture après annulation');
ok(fac_generer($pdo, 1, $m)['numero'] === 'MC-000004', 'puis idempotente');

// ---- 2. Revendication
require_once $root . '/api/revendication_lib.php';
$pdo->exec("UPDATE organisateurs SET web = 'https://www.afocal.fr/bafa' WHERE id = 2");
$pdo->exec("INSERT INTO organisateurs (id, nom, email, user_id, slug, stripe_account_id, web) VALUES (3, 'Mon orga', 'owner@test', 1, 'mon-orga', 'acct_1', 'moncamp.fr')");
$pdo->exec("INSERT INTO camps (token, nom, organisateur_id) VALUES ('t1', 'Séjour A', 2), ('t2', 'Séjour B', 2)");
rev_ensure($pdo);
ok($pdo->query("SELECT GROUP_CONCAT(id ORDER BY id) FROM organisateurs WHERE non_revendique = 1")->fetchColumn() === '2', 'migration : seule la fiche importée est non revendiquée');
ok(rev_domaine('https://www.afocal.fr/bafa') === 'afocal.fr' && rev_domaine('resacolo.com/organisateurs/x') === '' && rev_domaine('') === '', 'domaine du site officiel');
ok(rev_email_ok('jean@afocal.fr', 'afocal.fr') && rev_email_ok('a@idf.afocal.fr', 'afocal.fr') && !rev_email_ok('a@fakeafocal.fr', 'afocal.fr') && !rev_email_ok('a@afocal.fr.evil.com', 'afocal.fr'), 'e-mail au domaine (imitations refusées)');
$pdo->exec("INSERT INTO users (id, nom, prenom, email) VALUES (3, 'Vrai', 'Jean', 'jean@afocal.fr'), (4, 'Autre', 'Max', 'max@test')");
$pdo->exec("INSERT INTO orga_revendications (token, organisateur_id, user_id, prenom, nom, fonction, email_pro, email_verifie) VALUES ('" . str_repeat('a', 32) . "', 2, 3, 'Jean', 'Vrai', 'Directeur', 'jean@afocal.fr', 1), ('" . str_repeat('b', 32) . "', 2, 4, 'Max', 'Autre', 'Animateur', NULL, 0)");
[$av, $ap] = rev_valider($pdo, $pdo->query("SELECT * FROM orga_revendications WHERE user_id = 3")->fetch(), 1);
$o = $pdo->query("SELECT * FROM organisateurs WHERE id = 2")->fetch();
ok((int)$o['user_id'] === 3 && (int)$o['non_revendique'] === 0 && $o['email'] === 'jean@afocal.fr' && $ap['sejours'] === 2 && $av['user_id'] === 1, 'validation : organisme et ses 2 séjours transférés');
ok((int)$pdo->query("SELECT is_directeur FROM users WHERE id = 3")->fetchColumn() === 1, 'le demandeur devient organisateur');
ok($pdo->query("SELECT statut FROM orga_revendications WHERE user_id = 4")->fetchColumn() === 'refusee', 'les autres demandes en attente sont refusées');
$pdo->exec("UPDATE organisateurs SET user_id = 1, non_revendique = 1 WHERE id = 2");

echo "\nTOUT EST OK\n";
