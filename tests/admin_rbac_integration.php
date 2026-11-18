<?php
// FIN_TEST_DSN="mysql:host=127.0.0.1;dbname=moncamp_adm_test" php tests/admin_rbac_integration.php
$dsn = getenv('FIN_TEST_DSN');
if (!$dsn) { echo "FIN_TEST_DSN non défini : test ignoré.\n"; exit(0); }
require __DIR__ . '/../api/adm_core.php';
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
$pdo = new PDO($dsn, getenv('FIN_TEST_USER') ?: 'root', getenv('FIN_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo;
foreach (['adm_kv', 'adm_roles', 'adm_role_perms', 'adm_user_roles', 'adm_user_perms', 'adm_notifs', 'adm_notif_lus', 'adm_notes', 'admin_audit', 'users'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), prenom VARCHAR(50), email VARCHAR(190), is_admin TINYINT DEFAULT 0, is_directeur TINYINT DEFAULT 0, demande_en_cours TINYINT DEFAULT 0)");
$pdo->exec("INSERT INTO users (id, email, is_admin) VALUES (1, 'owner@x', 1), (2, 'compta@x', 1), (3, 'parent@x', 0)");

adm_core_ensure($pdo);
ok(adm_is_supreme(1) && adm_is_supreme(2), 'admins existants : suprêmes à la migration');
ok(adm_perms(3) === [] && !adm_can('compta.read', 3), 'non-admin : aucun droit');
$pdo->exec("DELETE FROM adm_user_roles WHERE user_id = 2");
$pdo->exec("INSERT INTO adm_user_roles (user_id, role_id) SELECT 2, id FROM adm_roles WHERE code = 'comptable'");
$pdo->exec("INSERT INTO users (id, email, is_admin) VALUES (4, 'tech@x', 1)");
$pdo->exec("INSERT INTO adm_user_roles (user_id, role_id) SELECT 4, id FROM adm_roles WHERE code = 'technique'");
$pdo->exec("INSERT INTO adm_user_perms (user_id, perm) VALUES (4, 'contacts.read')");
$GLOBALS['ADM_PC'] = [];
ok(adm_can('compta.export', 2) && !adm_can('tech.sql', 2) && !adm_can('paiements.write', 2), 'comptable : compta oui, SQL et remboursements non');
ok(adm_can('tech.sql', 4) && adm_can('contacts.read', 4) && !adm_can('compta.read', 4), 'technique + droit fin');
$pdo->exec("UPDATE users SET is_admin = 0 WHERE id = 4");
$GLOBALS['ADM_PC'] = [];
ok(!adm_can('tech.sql', 4), 'is_admin retiré : plus aucun droit');
ok(!adm_can('tech.sql', 5) && adm_perms(5) === [], 'compte inconnu : aucun droit');
adm_core_ensure($pdo);
ok((int)$pdo->query("SELECT COUNT(*) FROM adm_roles")->fetchColumn() === 7, '7 rôles, migration idempotente');
$t = adm_tok('u', 42);
ok(strlen($t) === 22 && adm_untok('u', $t) === 42, 'jeton opaque réversible');
ok(adm_untok('pay', $t) === 0 && adm_untok('u', ($t[0] === 'A' ? 'B' : 'A') . substr($t, 1)) === 0 && adm_untok('u', '42') === 0, 'jeton : autre type, altéré ou numérique refusé');
ok(in_array('audit.read', adm_role_presets()['lecture'][2], true) && !in_array('tech.sql', adm_role_presets()['lecture'][2], true), 'lecture seule sans technique');
foreach (['adm_kv', 'adm_roles', 'adm_role_perms', 'adm_user_roles', 'adm_user_perms', 'adm_notifs', 'adm_notif_lus', 'adm_notes', 'admin_audit', 'users'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
echo "Tous les contrôles passent.\n";
