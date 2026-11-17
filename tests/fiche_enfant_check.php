<?php
// Test de la fiche enfant : chiffrement des documents, accès contrôlé, complétude, PDF, purge RGPD.
// Base MySQL/MariaDB JETABLE obligatoire (le script crée et vide ses tables) et un api/config.php de test (lit T_DSN, T_USER, T_KEY) :
//   T_DSN="mysql:host=127.0.0.1;port=3399;dbname=t" php tests/fiche_enfant_check.php
$dsn = getenv('T_DSN');
if (!$dsn) { echo "T_DSN non défini : test ignoré.\n"; exit(0); }
putenv('T_KEY=' . bin2hex(random_bytes(32)));
define('HEALTH_KEY', getenv('T_KEY'));
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }
$php = PHP_BINARY;
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach (['inscriptions', 'camps', 'organisateurs', 'enfants', 'users', 'acces_sante', 'consentements', 'sec_tentatives', 'journal_contenus'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), email VARCHAR(80))");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT)");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, organisateur_id INT, date_debut DATE, date_fin DATE)");
$pdo->exec("CREATE TABLE enfants (id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT, prenom VARCHAR(50), nom VARCHAR(50), date_naissance DATE, sexe VARCHAR(10), infos_sante TEXT, regime_alimentaire TEXT,
  medecin_nom VARCHAR(80), medecin_tel VARCHAR(30), vaccins_data TEXT, carnet_sante_token VARCHAR(80), fiche_sanitaire_token VARCHAR(80), signature_token VARCHAR(80), date_creation DATETIME,
  adresse VARCHAR(200), code_postal VARCHAR(10), ville VARCHAR(80), resp1_nom VARCHAR(80), resp1_prenom VARCHAR(80), resp1_email VARCHAR(80), resp1_tel VARCHAR(30), accord_parental TINYINT, cgv_accepte TINYINT)");
$pdo->exec("CREATE TABLE inscriptions (id INT AUTO_INCREMENT PRIMARY KEY, enfant_id INT, camp_id INT, statut VARCHAR(20))");
$pdo->exec("INSERT INTO users (nom) VALUES ('parent'), ('autre'), ('dir_ok'), ('dir_ko')");
$pdo->exec("INSERT INTO organisateurs (user_id) VALUES (3), (4)");
$pdo->exec("INSERT INTO camps (organisateur_id, date_debut, date_fin) VALUES (1, '2030-07-01', '2030-07-15')");
$pdo->exec("INSERT INTO enfants (parent_id, prenom, nom, date_naissance, sexe, date_creation) VALUES (1, 'Lou', 'Test', '2016-05-01', 'Femme', NOW()), (1, 'Vieux', 'Test', '2016-05-01', 'Femme', NOW() - INTERVAL 2 YEAR)");
$pdo->exec("INSERT INTO inscriptions (enfant_id, camp_id, statut) VALUES (1, 1, 'Confirmé')");

require __DIR__ . '/../api/enfants.php';
require __DIR__ . '/../api/sante.php';
enfants_ensure($pdo); sante_ensure($pdo); enfants_ensure($pdo); sante_ensure($pdo);
$pid = $pdo->query("SELECT public_id FROM enfants WHERE id = 1")->fetchColumn();
ok(preg_match('/^[a-f0-9]{24}$/', (string)$pid) === 1, 'public_id rempli pour les lignes existantes');
ok(enfant_par_jeton($pdo, $pid, 1)['id'] == 1 && enfant_par_jeton($pdo, $pid, 2) === null && enfant_par_jeton($pdo, '1', 1) === null, 'jeton : propriétaire seulement, id numérique refusé');

// chiffrement
$secret = "%PDF-1.4\nDONNEE-SANTE-CONFIDENTIELLE\n%%EOF";
$name = sante_store('sante', $secret, 'pdf');
$raw = file_get_contents(__DIR__ . '/../uploads/sante/' . $name);
ok(strncmp($raw, SANTE_MAGIC, 6) === 0 && strpos($raw, 'CONFIDENTIELLE') === false, 'fichier chiffré au repos (rien en clair)');
ok(sante_read('sante', $name) === $secret, 'déchiffrement fidèle');
$bad = $raw; $bad[strlen($bad) - 3] = chr(ord($bad[strlen($bad) - 3]) ^ 1); file_put_contents(__DIR__ . '/../uploads/sante/' . $name, $bad);
try { sante_read('sante', $name); ok(false, 'fichier altéré refusé'); } catch (Exception $e) { ok(true, 'fichier altéré refusé (GCM)'); }
file_put_contents(__DIR__ . '/../uploads/sante/' . $name, $raw);
ok(is_file(__DIR__ . '/../uploads/sante/.htaccess'), '.htaccess de fermeture créé');

// PDF + complétude
require __DIR__ . '/../api/sante_pdf.php';
$e = $pdo->query("SELECT * FROM enfants WHERE id = 1")->fetch();
ok(strncmp(sante_pdf_fiche($e + ['allergies' => 'arachide', 'traitements' => '', 'urgence_nom' => 'Tata é'], 'Jean Dupont'), '%PDF', 4) === 0, 'PDF fiche sanitaire généré (accents compris)');
[$pct, $manque] = fiche_completude($e);
ok($pct < 50 && count($manque) > 5, "complétude faible d'une fiche vide ($pct %)");
$full = array_merge($e, ['resp1_nom' => 'a', 'resp1_prenom' => 'b', 'resp1_email' => 'c', 'resp1_tel' => 'd', 'adresse' => 'x', 'code_postal' => '1', 'ville' => 'v', 'medecin_nom' => 'm', 'medecin_tel' => '1', 'urgence_nom' => 'u', 'urgence_tel' => '1', 'taille' => '140', 'poids' => '30', 'accord_parental' => 1, 'cgv_accepte' => 1, 'fiche_sanitaire_token' => 'x', 'carnet_sante_token' => 'y']);
ok(fiche_completude($full)[0] === 100, 'complétude 100 % quand tout est fourni');

// accès : le document est rattaché à l'enfant 1
$pdo->prepare("UPDATE enfants SET carnet_sante_token = ? WHERE id = 1")->execute([$name]);
file_put_contents(sys_get_temp_dir() . '/sf_wrap.php', '<?php $_GET = ["type" => "sante", "file" => getenv("T_FILE")]; require ' . var_export(realpath(__DIR__ . '/../api/secure_file.php'), true) . ';');
function acces(array $user, string $file) { global $php; putenv('T_USER=' . json_encode($user)); putenv('T_FILE=' . $file); return shell_exec('"' . $php . '" "' . sys_get_temp_dir() . '/sf_wrap.php" 2>&1'); }
ok(strpos(acces(['id' => 1], $name), 'CONFIDENTIELLE') !== false, 'parent propriétaire : accès, déchiffré à la volée');
ok(strpos(acces(['id' => 2], $name), 'interdit') !== false, 'autre parent : refusé');
ok(strpos(acces(['id' => 3, 'is_directeur' => 1], $name), 'CONFIDENTIELLE') !== false, "directeur du séjour de l'enfant : accès");
ok(strpos(acces(['id' => 4, 'is_directeur' => 1], $name), 'interdit') !== false, 'directeur sans lien avec l\'enfant : refusé');
ok(strpos(acces(['id' => 9, 'is_admin' => 1], $name), 'CONFIDENTIELLE') !== false, 'admin : accès');
ok((int)$pdo->query("SELECT COUNT(*) FROM acces_sante")->fetchColumn() === 3, 'chaque accès autorisé est journalisé (3)');
ok(strpos(acces(['id' => 1], str_repeat('a', 64) . '.pdf'), 'Introuvable') !== false, 'nom inexistant : 404');
ok(strpos(acces(['id' => 1], '../../config.php'), 'Introuvable') !== false, 'traversée de chemin : 404');

// purge RGPD
$old = sante_store('sante', $secret, 'pdf');
$pdo->prepare("UPDATE enfants SET carnet_sante_token = ?, infos_sante = 'asthme', urgence_nom = 'X' WHERE id = 2")->execute([$old]);
putenv('T_USER');
$out = shell_exec('"' . $php . '" "' . __DIR__ . '/../api/cron_rgpd.php" 2>&1');
ok(strpos((string)$out, '"sante_purgee":1') !== false, 'cron : 1 fiche ancienne purgée (' . trim((string)$out) . ')');
ok(!is_file(__DIR__ . '/../uploads/sante/' . $old) && $pdo->query("SELECT infos_sante FROM enfants WHERE id = 2")->fetchColumn() === '', 'cron : fichier supprimé et données de santé effacées');
ok(is_file(__DIR__ . '/../uploads/sante/' . $name), "cron : l'enfant inscrit à un séjour futur est conservé");

// limitation de débit, CAPTCHA
require __DIR__ . '/../api/security.php';
for ($i = 0; $i < 3; $i++) sec_hit($pdo, 'child_w', '1');
ok(sec_blocked($pdo, 'child_w', '1', 3, 10) && !sec_blocked($pdo, 'child_w', '2', 3, 10), 'limitation de débit par compte');
ok(sec_turnstile_ok() === true, 'Turnstile inactif sans clés : ne bloque pas');
@unlink(__DIR__ . '/../uploads/sante/' . $name);
echo "TOUT OK
";
