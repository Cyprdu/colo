<?php
// Base JETABLE obligatoire (api/config.php de test pointant sur moncamp_adm_test) : php tests/admin_pages_integration.php
$root = dirname(__DIR__);
chdir($root);
require $root . '/api/config.php';
if (DB_NAME !== 'moncamp_adm_test') { fwrite(STDERR, "Base non jetable : arrêt.\n"); exit(1); }
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
$php = PHP_BINARY;
$errlog = sys_get_temp_dir() . '/adm_err_' . getmypid() . '.log';
putenv('ADM_ERRLOG=' . $errlog);

foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
foreach ($pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC) as $t) $pdo->exec("DROP TRIGGER IF EXISTS `{$t['Trigger']}`");
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), prenom VARCHAR(50), email VARCHAR(190), tel VARCHAR(30), password VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  is_admin TINYINT DEFAULT 0, is_directeur TINYINT DEFAULT 0, is_animateur TINYINT DEFAULT 0, is_verified TINYINT DEFAULT 1, is_active TINYINT DEFAULT 1, demande_en_cours TINYINT DEFAULT 0, is_refused TINYINT DEFAULT 0,
  remember_token VARCHAR(255), google_id VARCHAR(64), photo_url VARCHAR(255), url_token VARCHAR(64), reset_token VARCHAR(64), verification_token VARCHAR(64))");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(100), email VARCHAR(190), tel VARCHAR(30), web VARCHAR(255), user_id INT, portefeuille DECIMAL(10,2) DEFAULT 0, solde_points INT DEFAULT 0, stripe_account_id VARCHAR(64))");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(100), ville VARCHAR(100), code_postal VARCHAR(10), prix DECIMAL(10,2), date_debut DATE, date_fin DATE, valide TINYINT DEFAULT 0, en_attente TINYINT DEFAULT 1,
  refuse TINYINT DEFAULT 0, prive TINYINT DEFAULT 0, supprime TINYINT DEFAULT 0, token VARCHAR(64), organisateur_id INT, date_bump DATETIME, boost_vedette_fin DATETIME, boost_urgence_fin DATETIME)");
$pdo->exec("CREATE TABLE enfants (id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT, prenom VARCHAR(50), nom VARCHAR(50))");
$pdo->exec("CREATE TABLE inscriptions (id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT, enfant_id INT, statut VARCHAR(30), statut_paiement VARCHAR(30), prix_final DECIMAL(10,2), montant_paye DECIMAL(10,2), mode_paiement VARCHAR(20), token VARCHAR(64))");
$pdo->exec("CREATE TABLE contact_messages (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, nom VARCHAR(100), prenom VARCHAR(100), email VARCHAR(190), motif VARCHAR(255), message TEXT, token VARCHAR(64), statut VARCHAR(20) DEFAULT 'En attente', reponse TEXT, replied_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE virements (id INT AUTO_INCREMENT PRIMARY KEY, token VARCHAR(64), montant_total DECIMAL(10,2), commission_rate DECIMAL(5,2), montant_apres_commission DECIMAL(10,2), nom_organisme VARCHAR(100), email_organisme VARCHAR(190),
  date_demande DATETIME, date_virement_estime DATETIME, date_virement_effectue DATETIME, effectue TINYINT DEFAULT 0, iban VARCHAR(40), bic_swift VARCHAR(20))");
$pdo->exec("CREATE TABLE app_erreurs (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, type VARCHAR(30) NOT NULL, message TEXT NOT NULL, fichier VARCHAR(255) NULL, ligne INT NULL, url VARCHAR(300) NULL, methode VARCHAR(8) NULL, user_id INT NULL)");
$hash = password_hash('secret', PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO users (id, nom, prenom, email, password, is_admin, is_directeur) VALUES (1, 'Supreme', 'Ana', 'owner@test', '$hash', 1, 0), (2, 'Compta', 'Bea', 'compta@test', '$hash', 1, 0),
  (3, 'Parent', 'Cleo', 'parent@test', '$hash', 0, 0), (4, 'Direct', 'Dan', 'dir@test', '$hash', 0, 1), (5, 'Support', 'Eve', 'support@test', '$hash', 1, 0)");
$pdo->exec("INSERT INTO organisateurs (id, nom, email, user_id, portefeuille) VALUES (1, 'Colo Soleil', 'colo@test', 4, -12.50)");
$pdo->exec("INSERT INTO camps (id, nom, ville, prix, date_debut, organisateur_id, token, valide, en_attente) VALUES (1, 'Séjour mer', 'Brest', 500, '2027-07-01', 1, 'tokcamp1', 1, 0), (2, 'Séjour neige', 'Annecy', 600, '2027-02-01', 1, 'tokcamp2', 0, 1)");
$pdo->exec("INSERT INTO enfants (id, parent_id, prenom, nom) VALUES (1, 3, 'Lou', 'Parent')");
$pdo->exec("INSERT INTO inscriptions (id, camp_id, enfant_id, statut, statut_paiement, prix_final, montant_paye, mode_paiement, token) VALUES (1, 1, 1, 'Confirmé', 'PAYE', 500, 500, 'CARTE', 'insc1tok')");
$pdo->exec("INSERT INTO contact_messages (id, nom, prenom, email, motif, message, token) VALUES (1, 'Martin', 'Julie', 'julie@test', 'Réservation', 'Bonjour, une question &amp; une autre.', '" . str_repeat('a', 64) . "')");
$pdo->exec("INSERT INTO app_erreurs (type, message, fichier, ligne, url) VALUES ('Exception', 'Boom', 'x.php', 3, '/x'), ('Exception', 'Boom', 'x.php', 3, '/x')");
$pdo->exec("UPDATE users SET demande_en_cours = 1 WHERE id = 3");

require_once $root . '/api/finance_lib.php';
fin_ensure($pdo);
require_once $root . '/api/adm_core.php';
adm_core_ensure($pdo);
ok((int)$pdo->query("SELECT COUNT(*) FROM demandes_organisateur WHERE user_id = 3 AND statut = 'attente'")->fetchColumn() === 1, 'ancienne demande reprise dans la file');
$pdo->exec("DELETE FROM adm_user_roles WHERE user_id IN (2, 5)");
$pdo->exec("INSERT INTO adm_user_roles (user_id, role_id) SELECT 2, id FROM adm_roles WHERE code = 'comptable'");
$pdo->exec("INSERT INTO adm_user_roles (user_id, role_id) SELECT 5, id FROM adm_roles WHERE code = 'support'");
$pdo->exec("INSERT INTO fin_paiements (id, inscription_id, organisateur_id, camp_id, payment_intent, session_id, brut_cents, commission_cents, net_cents, commission_pct, date_deblocage, statut, mode)
            VALUES (1, 1, 1, 1, 'pi_test123', 'cs_test1', 50000, 2500, 47500, 5, NOW(), 'litige', 'direct')");
require_once $root . '/api/ledger_lib.php';
fin_ledger_ensure($pdo);
fin_ledger_add($pdo, 'paiement', 1, 50000, 'pay:pi_test123:1', 'inscription', 1, 'Paiement de la famille', 'webhook', 'direct', date('Y-m-d H:i:s', strtotime('-40 days')));
fin_ledger_add($pdo, 'commission', 1, -2500, 'pay:pi_test123:1', 'inscription', 1, 'Commission moncamp.fr', 'webhook', 'direct', date('Y-m-d H:i:s', strtotime('-40 days')));
fin_ledger_add($pdo, 'remboursement', 1, -10000, 'rem:pi_test123:1:10000', 'inscription', 1, 'Remboursement à la famille', 'webhook', 'direct');
fin_ledger_add($pdo, 'dette', 1, 1250, 'dette:test', 'organisateur', 1, 'Remboursement avancé', 'webhook', 'direct');

$camp = $pdo->query("SELECT token FROM contact_messages WHERE id = 1")->fetchColumn();
$dtok = $pdo->query("SELECT token FROM demandes_organisateur WHERE user_id = 3")->fetchColumn();
require_once $root . '/api/orga_lib.php';
$otok = fin_orga_tok(1, $pdo);
$pages = [
    ['admin.php', ''], ['admin_notifs.php', ''], ['admin_notifs.php', 's=toutes&t=erreur'], ['admin_contacts.php', ''], ['admin_contacts.php', 'm=' . $camp], ['admin_contacts.php', 'd=spam'],
    ['admin_demandes.php', ''], ['admin_demandes.php', 'd=' . $dtok], ['admin_equipe.php', ''], ['admin_audit.php', ''], ['admin_audit.php', 's=1&du=2020-01-01'],
    ['admin_erreurs.php', ''], ['admin_erreurs.php', 'vue=liste&j=30'], ['admin_tech.php', 'v=crons'], ['admin_tech.php', 'v=securite'], ['admin_tech.php', 'v=sauvegardes'],
    ['admin_db.php', ''], ['admin_sql.php', ''], ['tableau_de_controle.php', ''],
    ['admin_user.php', 't=' . adm_tok('u', 3)], ['admin_user.php', 't=' . adm_tok('u', 4)], ['admin_orga.php', 't=' . $otok], ['admin_paiement.php', 't=' . adm_tok('pay', 1)], ['admin_paiement.php', 't=nimportequoi'],
];
foreach (['users', 'organismes', 'camps', 'inscriptions', 'paiements', 'virements', 'boosts', 'contacts'] as $e) $pages[] = ['admin_list.php', "e=$e"];
foreach (['synthese', 'revenus', 'journal', 'grandlivre', 'paiements', 'remboursements', 'dettes', 'versements', 'tva', 'cloture', 'commission', 'organismes', 'export', 'reglages'] as $v) $pages[] = ['admin_compta.php', "v=$v&p=tout"];
$pages[] = ['admin_compta.php', "v=grandlivre&p=tout&o=$otok"];
$pages[] = ['admin_compta.php', 'v=journal&p=tout&ty=commission&tri=montant&sens=asc&q=pi_'];
$pages[] = ['admin_compta.php', 'v=paiements&f=litige'];
$pages[] = ['admin_compta.php', 'v=recherche&q=pi_test'];

$render = function (string $page, int $uid, string $qs) use ($php, $root, $errlog) {
    @unlink($errlog);
    $out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tests/admin_render.php') . ' ' . escapeshellarg($page) . ' ' . $uid . ' ' . escapeshellarg($qs) . ' 2>&1');
    $log = is_file($errlog) ? (string)file_get_contents($errlog) : '';
    return [(string)$out, $log];
};
foreach ($pages as [$page, $qs]) {
    [$out, $log] = $render($page, 1, $qs);
    $bad = preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught|Parse error)/', $out, $mm);
    $sqlErr = trim(preg_replace('/^.*(adm_core contact|PHP Deprecated|Clé Stripe non configurée).*$/m', '', $log));
    ok(!$bad && str_contains($out, '</html>') && $sqlErr === '' && !str_contains($out, 'Accès refusé'), "suprême : $page?$qs" . ($bad ? ' → ' . $mm[0] . ' ' . substr($out, max(0, strpos($out, $mm[0]) - 80), 400) : '') . ($sqlErr !== '' ? ' → journal : ' . substr($sqlErr, 0, 400) : ''));
}
[$out] = $render('admin_paiement.php', 1, 't=' . adm_tok('pay', 1));
ok(str_contains($out, 'pi_test123') && !preg_match('/[?&](id|pk)=\d/', $out), 'détail paiement : référence Stripe, aucun identifiant numérique en URL');
[$out] = $render('admin_list.php', 1, 'e=users');
ok(!preg_match('/data-id="\d+"/', $out) && !str_contains($out, '#1<'), 'liste utilisateurs : pas d’identifiant numérique');

foreach ([['admin_sql.php', ''], ['admin_contacts.php', ''], ['admin_equipe.php', ''], ['admin_db.php', ''], ['admin_tech.php', 'v=sauvegardes'], ['admin_demandes.php', '']] as [$page, $qs]) {
    [$out] = $render($page, 2, $qs);
    ok(str_contains($out, 'Accès refusé'), "comptable refusé : $page");
}
foreach ([['admin_compta.php', 'v=journal&p=tout'], ['admin_compta.php', 'v=cloture'], ['admin_paiement.php', 't=' . adm_tok('pay', 1)]] as [$page, $qs]) {
    [$out] = $render($page, 2, $qs);
    ok(!str_contains($out, 'Accès refusé') && str_contains($out, '</html>'), "comptable autorisé : $page?$qs");
}
[$out] = $render('admin_compta.php', 2, 'v=reglages');
ok(str_contains($out, 'Accès refusé'), 'comptable : réglages de commission refusés');
[$out] = $render('admin.php', 2, '');
ok(!str_contains($out, 'admin_sql.php') && !str_contains($out, 'admin_contacts.php') && str_contains($out, 'admin_compta.php'), 'barre latérale du comptable filtrée');
[$out] = $render('admin_contacts.php', 5, '');
ok(!str_contains($out, 'Accès refusé') && str_contains($out, 'Julie'), 'support : boîte de réception');
[$out] = $render('admin_compta.php', 5, '');
ok(str_contains($out, 'Accès refusé'), 'support : comptabilité refusée');
[$out] = $render('admin.php', 3, '');
ok(!str_contains($out, '</html>') || !str_contains($out, 'Tableau de bord'), 'parent : pas d’accès admin');
[$out] = $render('admin_search.php', 2, 'q=Parent');
$j = json_decode(substr($out, 0, (int)strpos($out, '@@END@@')), true);
ok(is_array($j) && !in_array('Utilisateurs', array_column($j['groups'], 'label'), true), 'recherche globale filtrée par droits (comptable)');
[$out] = $render('admin_search.php', 1, 'q=pi_test123');
$j = json_decode(substr($out, 0, (int)strpos($out, '@@END@@')), true);
ok(is_array($j) && in_array('Paiements', array_column($j['groups'], 'label'), true), 'recherche globale : paiement par pi_');
[$out, $log] = $render('devenir-organisateur.php', 3, '');
ok(str_contains($out, 'cours d’examen') && !preg_match('/(Fatal error|Warning|Notice)/', $out), 'devenir-organisateur : demande en attente affichée');
[$out] = $render('contact.php', 0, '');
ok(str_contains($out, 'name="site_web"') && str_contains($out, 'name="ts"'), 'contact.php : pot de miel et jeton de délai');
@unlink($errlog);
$_SERVER += ['REQUEST_METHOD' => 'GET', 'HTTP_USER_AGENT' => 'test', 'REMOTE_ADDR' => '127.0.0.1', 'SCRIPT_NAME' => '/admin_api.php', 'SCRIPT_FILENAME' => $root . '/admin_api.php'];
$_SESSION['user'] = $pdo->query("SELECT * FROM users WHERE id = 1")->fetch();
$_SESSION['adm_reauth'] = time();
class AdmUserError extends Exception {}
require_once $root . '/admin_lib.php';
require_once $root . '/api/compta_lib.php';
$H = require $root . '/admin_actions.php';
$call = function (string $a, array $in) use ($H) { try { return [true, $H[$a][2]($in)]; } catch (AdmUserError $e) { return [false, $e->getMessage()]; } };
$cm = adm_tok('cm', 1);
$call('contact.bulk', ['ids' => [$cm], 'op' => 'spam']);
ok($pdo->query("SELECT dossier FROM contact_messages WHERE id = 1")->fetchColumn() === 'spam', 'contact : déplacé en spam');
$call('contact.bulk', ['ids' => $cm, 'op' => 'inbox']);
[$r] = $call('contact.reply', ['m' => $cm, 'texte' => 'Bonjour, voici la réponse.', 'traite' => '1']);
$m = $pdo->query("SELECT dossier, etat, statut, lu FROM contact_messages WHERE id = 1")->fetch();
ok($r && $m['dossier'] === 'inbox' && $m['etat'] === 'traite' && $m['statut'] === 'Traité' && (int)$pdo->query("SELECT COUNT(*) FROM contact_reponses")->fetchColumn() === 1, 'contact : réponse enregistrée, statut traité');
[$r] = $call('contact.set', ['m' => $cm, 'etat' => 'en_cours', 'assigne' => adm_tok('u', 2)]);
ok(!$r, 'contact : assignation refusée à un membre sans accès aux messages');
[$r] = $call('contact.set', ['m' => $cm, 'etat' => 'en_cours', 'assigne' => adm_tok('u', 5)]);
ok($r && (int)$pdo->query("SELECT assigne_a FROM contact_messages WHERE id = 1")->fetchColumn() === 5, 'contact : assigné au support');
[$r] = $call('demande.refuser', ['d' => $dtok, 'motif' => 'court']);
ok(!$r, 'demande : refus sans motif suffisant bloqué');
$call('demande.valider', ['d' => $dtok]);
ok((int)$pdo->query("SELECT is_directeur FROM users WHERE id = 3")->fetchColumn() === 1 && $pdo->query("SELECT statut FROM demandes_organisateur WHERE token = " . $pdo->quote($dtok))->fetchColumn() === 'validee', 'demande : validée, accès organisateur accordé');
[$r] = $call('demande.valider', ['d' => $dtok]);
ok(!$r, 'demande : double validation refusée');
ok((int)$pdo->query("SELECT COUNT(*) FROM admin_audit WHERE action = 'demande_validee' AND avant IS NOT NULL AND apres IS NOT NULL")->fetchColumn() === 1, 'audit : avant / après enregistrés');
$mois = date('Y-m', strtotime('-40 days'));
[$r] = $call('compta.close', ['mois' => $mois]);
ok($r && $pdo->query("SELECT empreinte FROM compta_clotures WHERE mois = " . $pdo->quote($mois))->fetchColumn() === compta_cloture_empreinte($pdo, $mois), "clôture de $mois");
[$r] = $call('compta.close', ['mois' => date('Y-m')]);
ok(!$r, 'clôture du mois en cours refusée');
fin_ledger_add($pdo, 'ajustement', 1, 100, 'tardif', 'organisateur', 1, 'Écriture tardive', 'test', null, $mois . '-15 12:00:00');
ok($pdo->query("SELECT empreinte FROM compta_clotures WHERE mois = " . $pdo->quote($mois))->fetchColumn() !== compta_cloture_empreinte($pdo, $mois), 'écriture tardive détectée sur un mois clôturé');
[$r] = $call('team.save', ['u' => adm_tok('u', 1), 'roles' => ['comptable']]);
ok(!$r, 'équipe : le dernier suprême ne peut pas perdre son rôle');
[$r] = $call('team.save', ['u' => adm_tok('u', 2), 'roles' => ['comptable'], 'perms' => ['tech.sql', 'nimporte']]);
$GLOBALS['ADM_PC'] = [];
ok($r && adm_can('tech.sql', 2) && !in_array('nimporte', adm_perms(2), true), 'équipe : droit fin ajouté, droit inconnu ignoré');
$nid = (int)$pdo->query("SELECT id FROM adm_notifs LIMIT 1")->fetchColumn();
$call('notif.read', ['ids' => [adm_tok('n', $nid)], 'read' => '1']);
ok($nid > 0 && (int)$pdo->query("SELECT COUNT(*) FROM adm_notif_lus WHERE user_id = 1 AND notif_id = $nid")->fetchColumn() === 1, 'notification marquée lue');
[$r] = $call('note.add', ['objet' => 'paiement', 'ref' => adm_tok('pay', 1), 'texte' => 'Vérifié avec la banque.']);
ok($r && (int)$pdo->query("SELECT COUNT(*) FROM adm_notes")->fetchColumn() === 1, 'annotation interne');
[$out] = $render('admin_compta.php', 1, 'v=cloture');
ok(str_contains($out, 'Modifié après clôture'), 'page Clôtures : mois modifié signalé');
@unlink($errlog);
echo "Tous les contrôles passent.\n";
