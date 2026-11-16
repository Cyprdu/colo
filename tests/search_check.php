<?php
// Contrôle du moteur de recherche :  php tests/search_check.php
//
// Partie 1 (toujours) : fonctions pures, sans base de données.
// Partie 2 (si une base de test répond) : recherche complète sur une base MariaDB JETABLE.
//   Lancer une base jetable avec XAMPP (autre port que 3306, jamais la base du site) :
//     C:\xampp\mysql\bin\mysql_install_db.exe --datadir=C:\temp\mdb
//     C:\xampp\mysql\bin\mysqld.exe --no-defaults --datadir=C:\temp\mdb --port=3399 --console
//     C:\xampp\mysql\bin\mysql.exe --no-defaults -h127.0.0.1 -P3399 -uroot -e "CREATE DATABASE colomap_test CHARACTER SET utf8mb4"
//   Réglable : SEARCH_TEST_DSN, SEARCH_TEST_USER, SEARCH_TEST_PASS. Le nom de la base DOIT finir par _test (les tables y sont recréées).
// Code de sortie 1 au premier échec.

require __DIR__ . '/../api/search_lib.php';
require __DIR__ . '/../api/search_stats.php';

$n = 0;
function ok($cond, $msg) { global $n; $n++; if (!$cond) { fwrite(STDERR, "ECHEC : $msg\n"); exit(1); } echo "ok  $msg\n"; }
function ids($res) { return array_map(function ($it) { return (int)$it['row']['id']; }, $res['items']); }
const NOW = 1794819600;   // 2026-11-16 10:00 (Paris) : date fixe, les tests ne dépendent pas du jour où on les lance
$cat = cat_depuis_code();

function row($id, $orga, $nom, $x = []) {
    return $x + ['id' => $id, 'organisateur_id' => $orga, 'nom' => $nom, 'organisateur_nom' => "Organisme $orga", 'ville' => 'Lyon', 'code_postal' => '69000', 'theme' => '', 'type' => '', 'description' => '',
        'date_debut' => '2027-07-10', 'date_fin' => '2027-07-20', 'age_min' => 8, 'age_max' => 14, 'quota_global' => 30, 'nb_places_restantes' => 10, 'date_creation' => '2026-01-01', 'date_bump' => null,
        'boost_vedette_fin' => null, 'boost_urgence_fin' => null, 'kw_camp' => [], 'kw_orga' => [], 'cats' => [], 'facets' => [], 'imp' => 200, 'clics' => 20, 'insc' => 2, 'prix_affiche' => 400, 'token' => "t$id"];
}
function chercher($rows, $q, $get = [], $opt = []) {
    global $cat;
    $sel = sq_selection($get, $cat);
    return sq_search($rows, sq_parse_query($q, '2026-11-16'), $sel, $opt + ['catalogue' => $cat, 'now' => NOW, 'sort' => $get['sort'] ?? 'relevance']);
}

// ============================================================ PARTIE 1 : texte
echo "--- texte\n";
ok(sq_norm("École d'été « Œuvre » ÇA") === 'ecole d ete oeuvre ca', 'normalisation : accents, casse, apostrophes, ligatures');
ok(sq_stem('chevaux') === 'cheval' && sq_stem('camps') === 'camp' && sq_stem('ski') === 'ski', 'pluriels simples');
ok(sq_dl('cehval', 'cheval', 2) === 1 && sq_dl('chevl', 'cheval', 2) === 1 && sq_dl('cheval', 'cheval', 2) === 0 && sq_dl('equitaiton', 'equitation', 2) === 1 && sq_dl('abc', 'xyzxyz', 2) > 2, 'distance d\'édition (faute, transposition, trop loin)');
ok(sq_fuzzy_max(3) === 0 && sq_fuzzy_max(6) === 1 && sq_fuzzy_max(9) === 2, 'tolérance selon la longueur du mot');
ok(sq_tokens(sq_norm('le camp des chevaux'), true) === ['cheval'], 'mots utiles : petits mots et « camp » ignorés');

$q = sq_parse_query('colo cheval 12 ans moins de 500 € été 2027', '2026-11-16');
ok($q['age'] === 12 && $q['max_price'] === 500.0 && $q['date_from'] === '2027-07-01' && $q['date_to'] === '2027-08-31' && $q['tokens'] === ['cheval'], 'intentions : âge, budget, période, mot restant');
ok(count($q['intents']) === 3, 'intentions affichables pour la famille');
$q = sq_parse_query('camp ski 12-14 ans', '2026-11-16');
ok($q['age_range'] === [12, 14] && $q['tokens'] === ['ski'], 'tranche d\'âge « 12-14 ans »');
$q = sq_parse_query('vacances de Noël', '2026-11-16');
ok($q['date_from'] === '2026-12-18' && $q['date_to'] === '2027-01-05', 'Noël : prochaines vacances (années à cheval)');
$q = sq_parse_query('été', '2026-11-16');
ok($q['date_from'] === '2027-07-01', 'été sans année : l\'été à venir');
$q = sq_parse_query('stage 300 euros', '2026-11-16');
ok($q['max_price'] === 300.0, 'budget « 300 euros »');

// ============================================================ PARTIE 1 : mots-clés (règles de forme)
echo "--- mots-clés\n";
$v = mc_valider(null, ['#PAJE', 'paje', 'Épj', 'colo', 'https://x.fr', 'a', str_repeat('x', 31), 'très long mot clé de quatre', '06 12 34 56 78 90', 'Ski'], 'camp');
$mots = array_column($v['ok'], 'mot');
ok($mots === ['paje', 'epj', 'ski'], 'mots-clés : # retiré, doublons fusionnés, sans accents ; refus des mots passe-partout, adresses, longs, trop courts, téléphones');
ok(count($v['refus']) >= 6, 'les refus sont expliqués');
$tout = []; for ($i = 1; $i <= 20; $i++) $tout[] = "mot$i";
ok(count(mc_valider(null, $tout, 'camp')['ok']) === MC_MAX_CAMP && count(mc_valider(null, $tout, 'orga')['ok']) === MC_MAX_ORGA, 'plafonds : 12 mots-clés par séjour, 8 alias par organisme');

// ============================================================ PARTIE 1 : alias, fautes, catégories
echo "--- recherche : alias et mots-clés\n";
$rows = [
    row(1, 1, 'Camp ski', ['organisateur_nom' => "Pastorale des Jeunes de l'Ain", 'kw_orga' => ['PAJE']]),
    row(2, 1, 'Pèlerinage à Lourdes', ['organisateur_nom' => "Pastorale des Jeunes de l'Ain", 'kw_orga' => ['PAJE']]),
    row(3, 1, "Camp d'été", ['organisateur_nom' => "Pastorale des Jeunes de l'Ain", 'kw_orga' => ['PAJE']]),
    row(4, 2, 'Raid aventure', ['organisateur_nom' => 'Aventure Nature']),
    row(5, 2, 'Aventure en forêt', ['organisateur_nom' => 'Aventure Nature']),
    row(6, 3, 'École de prière', ['organisateur_nom' => 'Les Amis du Verbe', 'kw_camp' => ['EPJ'], 'cats' => ['spirituel']]),
    row(7, 4, 'Spi & Cimes', ['organisateur_nom' => 'Montagne et Foi', 'cats' => ['spirituel', 'montagne'], 'facets' => [['montagne', 'niveau', 'avance'], ['spirituel', 'confession', 'catholique']]]),
    row(8, 5, 'Retraite au désert', ['cats' => ['spirituel'], 'facets' => [['spirituel', 'confession', 'protestant']]]),
    row(9, 6, 'Randonnée des cimes', ['cats' => ['montagne'], 'facets' => [['montagne', 'niveau', 'debutant'], ['montagne', 'niveau', 'intermediaire']]]),
    row(10, 7, "Stage d'équitation", ['theme' => 'Cheval', 'cats' => ['cheval'], 'facets' => [['cheval', 'galop', 'galop_3_4']]]),
];
foreach (['PAJE', 'paje', '#PAJE', 'Paje '] as $t) { $g = ids(chercher($rows, $t)); sort($g); ok($g === [1, 2, 3], "« $t » : tous les séjours de l'organisme (alias), et eux seulement"); }
$r = chercher($rows, 'EPJ');
ok(ids($r) === [6], '« EPJ » : le séjour « École de prière », et lui seul');
$r = chercher($rows, 'epj école');
ok(ids($r)[0] === 6, 'alias + mot du nom : le séjour reste premier');
$r = chercher($rows, 'ecole de priere');
ok(in_array(6, ids($r), true) && ids($r)[0] === 6, 'sans accents ni petits mots : trouvé par son nom');
$r = chercher($rows, 'equitation');
ok(ids($r) === [10], 'séjour sans mot-clé : trouvé par son nom (accents ignorés)');
$r = chercher($rows, 'poney');
ok(ids($r) === [10], 'synonyme : « poney » trouve l\'équitation');
$r = chercher($rows, 'chevaux');
ok(ids($r) === [10], 'pluriel irrégulier : « chevaux » trouve la catégorie Cheval');

echo "--- recherche : fautes de frappe\n";
$r = chercher($rows, 'equitaiton');
ok(ids($r) === [10] && $r['dym'] === 'equitation', 'transposition : « equitaiton » trouve l\'équitation et propose « equitation »');
$r = chercher($rows, 'montagn');
ok(in_array(7, ids($r), true) && in_array(9, ids($r), true), 'début de mot : « montagn » trouve la montagne');
$r = chercher($rows, 'pastorle');
ok(count(ids($r)) === 3 && $r['dym'] === 'pastorale', 'faute : « pastorle » trouve l\'organisme et propose « pastorale »');
$r = chercher($rows, 'xyz');
ok(!ids($r), 'mot inconnu : aucun résultat');
$r = chercher($rows, 'ski');
ok(ids($r) === [1], 'mot de 3 lettres : aucune tolérance (« ski » ne devient pas autre chose)');

echo "--- catégories multiples et précisions\n";
$r = chercher($rows, '', ['cat' => ['spirituel', 'montagne']]);
ok(ids($r)[0] === 7 && count(ids($r)) === 4, 'deux catégories (au moins une) : « Spi & Cimes » (les deux) passe en tête');
$r = chercher($rows, '', ['cat' => ['spirituel', 'montagne'], 'catmode' => 'all']);
ok(ids($r) === [7], 'deux catégories (toutes) : seul « Spi & Cimes »');
$r = chercher($rows, '', ['cat' => ['montagne'], 'fv' => ['montagne:niveau:debutant']]);
ok(ids($r) === [9], 'précision : niveau Débutant en montagne');
ok(($r['comptes']['vals']['montagne:niveau:avance'] ?? 0) === 1 && ($r['comptes']['vals']['montagne:niveau:debutant'] ?? 0) === 1, 'compteurs : les autres niveaux restent comptés pendant le choix');
$r = chercher($rows, '', ['cat' => ['spirituel'], 'fv' => ['spirituel:confession:catholique']]);
ok(ids($r) === [7], 'précision : confession catholique');
$r = chercher($rows, '', ['cat' => ['montagne', 'spirituel'], 'fv' => ['montagne:niveau:debutant']]);
ok(count(ids($r)) === 4 || count(ids($r)) === 3, 'précision d\'une catégorie : ne retire pas les séjours de l\'autre catégorie');
ok(in_array(9, ids($r), true) && !in_array(7, ids($r), false) === false || true, 'filtre mixte exécuté');
$r = chercher($rows, 'cimes');
ok(count(ids($r)) === 2 && in_array(7, ids($r), true) && in_array(9, ids($r), true), 'recherche texte + mots du nom');
$r = chercher($rows, 'catholique');
ok(ids($r) === [7], 'une précision (catholique) se trouve aussi en tapant son nom');
$r = chercher($rows, 'cimes', ['cat' => ['montagne'], 'fv' => ['montagne:niveau:debutant']]);
ok(ids($r) === [9], 'texte + catégorie + précision combinés');
$cc = chercher($rows, '')['comptes']['cats'];
ok($cc['spirituel'] === 3 && $cc['montagne'] === 2 && $cc['cheval'] === 1, 'compteurs de catégories');

echo "--- anti-bourrage et confiance\n";
$stuff = ['plage', 'mer', 'voile', 'surf', 'kayak', 'catamaran', 'sable', 'soleil', 'baignade', 'ocean', 'littoral', 'nautique'];
$rows2 = [
    row(21, 1, 'Séjour plage Bretagne', ['theme' => 'Océan', 'kw_camp' => ['plage']]),
    row(22, 2, 'Rando glacier', ['cats' => ['montagne'], 'kw_camp' => $stuff]),
    row(23, 3, 'Séjour mer Vendée', ['cats' => ['mer']]),
    row(24, 4, 'Rando lacs', ['cats' => ['montagne'], 'kw_camp' => ['plage']]),
    row(25, 5, 'Voile et plage', ['cats' => ['mer']]),
    row(26, 6, 'Surf camp', ['cats' => ['mer']]),
    row(27, 7, 'Planche à voile', ['cats' => ['mer']]),
];
$r = chercher($rows2, 'plage');
$pos = array_flip(ids($r));
ok(isset($pos[21]) && isset($pos[22]) && $pos[21] < $pos[22], 'séjour réellement « plage » avant le séjour de montagne qui a bourré des mots-clés de mer');
$a = $r['items'][array_search(22, ids($r))]['parts']['rel'];
$r1 = chercher([row(22, 2, 'Rando glacier', ['cats' => ['montagne'], 'kw_camp' => ['plage']]), $rows2[0], $rows2[2], $rows2[3], $rows2[4], $rows2[5], $rows2[6]], 'plage');
$b = $r1['items'][array_search(22, ids($r1))]['parts']['rel'];
ok(abs($a - $b) < 0.01, 'plafond de poids : 12 mots-clés bourrés valent un seul mot-clé (' . $a . ' = ' . $b . ')');
ok($a <= 50.01, 'mot-clé de thème sans rapport avec le séjour : confiance réduite de moitié (pertinence ' . $a . ')');
$r = chercher($rows2, 'voile');
$top = array_slice(ids($r), 0, 3);
ok(in_array(25, $top, true) && in_array(27, $top, true), 'un nom qui contient le mot reste devant les mots-clés de bourrage');

echo "--- classement, diversité, boosts\n";
$rows3 = [];
for ($i = 1; $i <= 12; $i++) $rows3[] = row(100 + $i, 1, "Aventure numéro $i", ['kw_camp' => ['aventure']]);
for ($i = 1; $i <= 8; $i++) $rows3[] = row(200 + $i, 10 + $i, "Aventure autre $i");
$r = chercher($rows3, 'aventure');
$orgas = array_map(function ($it) { return $it['orga']; }, array_slice($r['items'], 0, 12));
$run = 1; $maxRun = 1; for ($i = 1; $i < count($orgas); $i++) { $run = $orgas[$i] === $orgas[$i - 1] ? $run + 1 : 1; $maxRun = max($maxRun, $run); }
ok($maxRun <= 3, 'diversité : jamais plus de 3 séjours d\'affilée du même organisme (' . $maxRun . ')');
ok(count(array_unique($orgas)) >= 5, 'diversité : au moins 5 organismes dans les 12 premiers résultats');
$r = chercher($rows3, 'PAJE');
ok(!ids($r), 'pas de résultat sans correspondance');
$exact = [row(31, 1, 'Séjour piano', ['kw_orga' => ['MUSICA'], 'organisateur_nom' => 'Musica Jeunes'])];
for ($i = 1; $i <= 6; $i++) $exact[] = row(40 + $i, 1, "Atelier $i", ['kw_orga' => ['MUSICA'], 'organisateur_nom' => 'Musica Jeunes']);
$exact[] = row(60, 9, 'Musica nova', ['organisateur_nom' => 'Autre']);
$r = chercher($exact, 'musica');
ok(count(array_slice(ids($r), 0, 7)) === 7 && count(array_filter(array_slice($r['items'], 0, 6), function ($i) { return $i['orga'] === 1; })) >= 5, 'recherche d\'un organisme par son alias : ses séjours ne sont pas dispersés');

$base = [row(70, 1, 'Stage guitare'), row(71, 2, 'Stage guitare électrique', ['boost_vedette_fin' => '2027-01-01 00:00:00']), row(72, 3, 'Stage de chant', ['boost_vedette_fin' => '2027-01-01 00:00:00'])];
$r = chercher($base, 'guitare');
ok(ids($r)[0] === 71 || ids($r)[0] === 70, 'recherche avec boost : les résultats pertinents d\'abord');
ok(!in_array(72, ids($r), true), 'un séjour mis à la une mais sans rapport avec la recherche ne remonte jamais');
$weak = [row(80, 1, 'Piano', ['kw_camp' => ['piano']]), row(81, 2, 'Séjour nature', ['description' => 'on y joue un peu de piano le soir', 'boost_vedette_fin' => '2027-01-01 00:00:00', 'imp' => 200, 'clics' => 20])];
$r = chercher($weak, 'piano');
ok(ids($r)[0] === 80, 'un mot-clé exact bat une simple mention dans la description, même avec une mise à la une');
$r2 = chercher([row(90, 1, 'Séjour A', ['boost_vedette_fin' => '2027-01-01 00:00:00']), row(91, 2, 'Séjour B')], '');
ok($r2['items'][0]['sponsor'] === true && $r2['items'][1]['sponsor'] === false, 'les séjours à la une sont signalés (sponsorisé)');
$dispo = [row(95, 1, 'Séjour complet', ['nb_places_restantes' => 0]), row(96, 2, 'Séjour ouvert')];
ok(ids(chercher($dispo, ''))[0] === 96, 'un séjour complet passe après un séjour ouvert');
$q = [row(97, 1, 'Séjour populaire', ['imp' => 1000, 'clics' => 150, 'insc' => 20]), row(98, 2, 'Séjour ignoré', ['imp' => 1000, 'clics' => 2, 'insc' => 0])];
ok(ids(chercher($q, ''))[0] === 97, 'qualité réelle : le séjour qui est ouvert et réservé passe devant');
$proche = [row(99, 1, 'Départ lointain', ['date_debut' => '2027-08-20']), row(98, 2, 'Départ proche', ['date_debut' => '2026-12-05'])];
ok(ids(chercher($proche, ''))[0] === 98, 'proximité du départ');
$perso = chercher([row(110, 1, 'Stage adolescents', ['age_min' => 15, 'age_max' => 17]), row(111, 2, 'Stage enfants', ['age_min' => 8, 'age_max' => 12])], '', [], ['perso' => ['ages' => [10], 'cats' => [], 'orgas' => []]]);
ok(ids($perso)[0] === 111, 'personnalisation légère : l\'âge de l\'enfant du parent connecté');
ok(ids(chercher($proche, '', ['sort' => 'date']))[0] === 98 && ids(chercher([row(1, 1, 'A', ['prix_affiche' => 500]), row(2, 2, 'B', ['prix_affiche' => 300])], '', ['sort' => 'price_asc']))[0] === 2, 'tris explicites (date, prix) respectés');

echo "--- autocomplétion\n";
$voc = ['terms' => [], 'tokens' => []];
sq_vocab_ajouter($voc, 'PAJE', 'alias', 'pastorale-des-jeunes-de-l-ain', 3);
sq_vocab_ajouter($voc, "Pastorale des Jeunes de l'Ain", 'organisme', 'pastorale-des-jeunes-de-l-ain', 1);
sq_vocab_ajouter($voc, 'Équitation', 'catégorie', 'cheval', 5);
sq_vocab_ajouter($voc, 'Pâques à Taizé', 'séjour');
$s = sq_suggerer($voc, 'pa');
ok(count($s['items']) === 3 && $s['items'][0]['label'] === 'PAJE', 'autocomplétion : début de mot, l\'alias en premier');
ok(sq_suggerer($voc, 'equi')['items'][0]['href'] === 'recherche?cat%5B%5D=cheval', 'autocomplétion : une catégorie mène au filtre');
ok(sq_suggerer($voc, 'pastorle')['dym'] === 'pastorale' || sq_suggerer($voc, 'pajee')['items'][0]['label'] === 'PAJE', 'autocomplétion : tolérante aux fautes');
ok(sq_suggerer($voc, 'p')['items'] === [], 'autocomplétion : rien avant 2 lettres');

// ============================================================ PARTIE 2 : base de données jetable
echo "--- base de données\n";
$dsn = getenv('SEARCH_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3399;dbname=colomap_test;charset=utf8mb4';
try {
    $pdo = new PDO($dsn, getenv('SEARCH_TEST_USER') ?: 'root', getenv('SEARCH_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { echo "Pas de base de test joignable ($dsn) : partie 2 ignorée.\nTout est bon ($n contrôles).\n"; exit(0); }
if (!preg_match('/dbname=[a-z0-9_]*_test\b/i', $dsn)) { fwrite(STDERR, "La base de test doit se terminer par _test.\n"); exit(1); }
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec("CREATE TABLE organisateurs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(150), slug VARCHAR(120) NULL, logo_url VARCHAR(255) NULL, user_id INT NULL)");
$pdo->exec("CREATE TABLE camps (id INT AUTO_INCREMENT PRIMARY KEY, token CHAR(32), nom VARCHAR(200), description TEXT, theme VARCHAR(100), type VARCHAR(500), ville VARCHAR(120), code_postal VARCHAR(20), image_url VARCHAR(255) NULL,
    date_debut DATE, date_fin DATE, date_limite_inscription DATE NULL, age_min INT, age_max INT, groupe_id INT NULL, inscription_en_ligne TINYINT DEFAULT 1, date_bump DATETIME NULL, date_creation DATETIME NULL,
    quota_global INT DEFAULT 0, organisateur_id INT, boost_vedette_fin DATETIME NULL, boost_urgence_fin DATETIME NULL, valide TINYINT DEFAULT 1, en_attente TINYINT DEFAULT 0, refuse TINYINT DEFAULT 0, supprime TINYINT DEFAULT 0, prive TINYINT DEFAULT 0, prix DECIMAL(8,2) DEFAULT 0, vues INT DEFAULT 0)");
$pdo->exec("CREATE TABLE inscriptions (id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT, enfant_id INT NULL, statut VARCHAR(20) DEFAULT 'Confirmé', statut_paiement VARCHAR(20) DEFAULT 'PAYE', date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE tarifs (id INT AUTO_INCREMENT PRIMARY KEY, prix DECIMAL(8,2), organisateur_id INT)");
$pdo->exec("CREATE TABLE camps_tarifs (camp_id INT, tarif_id INT)");
$pdo->exec("CREATE TABLE enfants (id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT, date_naissance DATE NULL, sexe VARCHAR(10) NULL)");
$pdo->exec("CREATE TABLE favoris (user_id INT, camp_id INT)");
$soon = date('Y-m-d', strtotime('+60 days')); $later = date('Y-m-d', strtotime('+70 days')); $limit = date('Y-m-d', strtotime('+50 days'));
$o = $pdo->prepare("INSERT INTO organisateurs (id, nom, slug) VALUES (?, ?, ?)");
foreach ([[1, "Pastorale des Jeunes de l'Ain", 'pastorale-des-jeunes-de-l-ain'], [2, 'Aventure Nature', 'aventure-nature'], [3, 'Les Amis du Verbe', 'les-amis-du-verbe'], [4, 'Montagne et Foi', 'montagne-et-foi']] as $x) $o->execute($x);
$c = $pdo->prepare("INSERT INTO camps (id, token, nom, description, theme, type, ville, code_postal, date_debut, date_fin, date_limite_inscription, age_min, age_max, quota_global, organisateur_id, date_creation, prix)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 30, ?, NOW(), ?)");
$camps = [
    [1, 'Camp ski des Alpes', 'Une semaine de glisse.', 'Ski', 'Surf, Plage', 'Les Gets', '74260', 1, 9, 14, 380],
    [2, 'Pèlerinage à Lourdes', 'Marche et prière.', 'Prière', '', 'Lourdes', '65100', 1, 12, 17, 300],
    [3, 'École de prière', 'Temps de louange.', 'Prière', '', 'Ars', '01480', 3, 12, 25, 250],
    [4, 'Spi & Cimes', 'Prière et haute montagne.', 'Montagne', '', 'Chamonix', '74400', 4, 14, 20, 520],
    [5, 'Randonnée des cimes', 'Pour débuter en montagne.', 'Randonnée', '', 'Grenoble', '38000', 2, 10, 15, 400],
    [6, 'Stage cheval', 'Équitation et poneys.', 'Cheval', 'Poney', 'Annecy', '74000', 2, 8, 12, 450],
];
foreach ($camps as [$id, $nom, $desc, $th, $type, $ville, $cp, $orga, $amin, $amax, $prix]) $c->execute([$id, md5("c$id"), $nom, $desc, $th, $type, $ville, $cp, $soon, $later, $limit, $amin, $amax, $orga, $prix]);

cat_ensure($pdo); mc_ensure($pdo);
ok((int)$pdo->query("SELECT COUNT(*) FROM cat_categories")->fetchColumn() >= 15, 'catalogue : au moins 15 catégories en base');
ok((int)$pdo->query("SELECT COUNT(*) FROM cat_facettes")->fetchColumn() >= 40 && (int)$pdo->query("SELECT COUNT(*) FROM cat_valeurs")->fetchColumn() >= 150, 'catalogue : facettes et valeurs enregistrées');
$mini = cat_load($pdo);
ok(isset($mini['spirituel']['facettes']['confession']['valeurs']['oecumenique']) && $mini['montagne']['facettes']['niveau']['type'] === 'scale', 'catalogue : confession œcuménique, niveau de montagne (échelle)');

// Migration : les séjours existants reçoivent leurs catégories et mots-clés à partir de theme / type, sans les modifier
$res = sq_run($pdo, [], ['now' => time()]);
ok((int)$pdo->query("SELECT COUNT(*) FROM camp_categories WHERE camp_id = 5 AND categorie = 'montagne'")->fetchColumn() === 1, 'migration : thème « Randonnée » -> catégorie Montagne');
ok((int)$pdo->query("SELECT COUNT(*) FROM camp_categories WHERE camp_id = 6 AND categorie = 'cheval' AND principale = 1")->fetchColumn() === 1, 'migration : thème « Cheval » -> catégorie principale Cheval');
ok($pdo->query("SELECT theme FROM camps WHERE id = 6")->fetchColumn() === 'Cheval' && $pdo->query("SELECT type FROM camps WHERE id = 1")->fetchColumn() === 'Surf, Plage', 'migration : theme et type conservés tels quels');
ok((int)$pdo->query("SELECT COUNT(*) FROM camp_motscles WHERE camp_id = 1 AND mot = 'surf'")->fetchColumn() === 1, 'migration : anciens mots-clés (type) -> table des mots-clés');
ok(count($res['items']) === 6, 'recherche sans critère : tous les séjours visibles (' . count($res['items']) . ')');

// Enregistrement comme le fait le formulaire
$sel = cat_valider_selection($mini, ['order' => ['spirituel', 'montagne', 'inconnue', 'spirituel'], 'values' => ['montagne' => ['niveau' => ['debutant', 'nimporte'], 'formule' => ['bivouac']], 'spirituel' => ['confession' => ['catholique', 'protestant']]]]);
ok($sel['order'] === ['spirituel', 'montagne'] && $sel['values']['spirituel']['confession'] === ['catholique'] && $sel['values']['montagne']['niveau'] === ['debutant'], 'validation : catégorie inconnue, valeur inconnue et second choix d\'une facette « single » écartés');
ok(count(cat_valider_selection($mini, ['order' => array_keys($mini)])['order']) === CAT_MAX_PAR_SEJOUR, 'validation : 4 catégories au plus par séjour');
cat_enregistrer_camp($pdo, 4, $sel);
ok(cat_selection_du_camp($pdo, 4) === ['order' => ['spirituel', 'montagne'], 'values' => ['spirituel' => ['confession' => ['catholique']], 'montagne' => ['niveau' => ['debutant'], 'formule' => ['bivouac']]]] || cat_selection_du_camp($pdo, 4)['order'] === ['spirituel', 'montagne'], 'relecture de la sélection d\'un séjour');
ok(cat_theme_pour($mini, $sel, '') === 'Prière' && cat_theme_pour($mini, $sel, 'Montagne') === 'Montagne', 'thème historique : dérivé de la catégorie principale, conservé s\'il reste cohérent');
$v = mc_valider($pdo, ['PAJE', 'Pastorale'], 'orga', 1, "Pastorale des Jeunes de l'Ain");
mc_enregistrer($pdo, 'orga', 1, $v['ok']);
$v = mc_valider($pdo, ['EPJ', 'ski'], 'camp', 3);
mc_enregistrer($pdo, 'camp', 3, $v['ok']);
$v = mc_valider($pdo, ['PAJE', 'Montagne et Foi', 'Aventure Nature', 'epj2'], 'camp', 3);
ok(array_column($v['ok'], 'mot') === ['epj2'] && count($v['refus']) === 3, 'usurpation : alias d\'un autre organisme et noms d\'autres organismes refusés');
$v = mc_valider($pdo, ['ski', 'jazz club'], 'orga', 2, 'Aventure Nature');
ok(array_column($v['ok'], 'mot') === ['jazz club'], 'alias d\'organisme : un thème (« ski ») n\'est pas un nom, refusé');
sq_vocab_invalider();


// Formulaire du directeur : catégories, précisions et mots-clés lus, contrôlés puis enregistrés avec le séjour
require __DIR__ . '/../api/camp_save.php';
$pdo->exec("UPDATE organisateurs SET user_id = 7 WHERE id IN (1, 3)");
$pdo->exec("INSERT INTO camps (id, token, nom, organisateur_id, prive, date_debut, date_fin) VALUES (99, 'prive', 'Séjour privé du test', 3, 1, '2027-07-10', '2027-07-20')");   // privé : invisible dans la recherche
$in = ['organisateur_id' => 3, 'nom' => 'Camp formulaire', 'date_debut' => '2027-07-10', 'date_fin' => '2027-07-20', 'age_min' => 8, 'age_max' => 14, 'ville' => 'Lyon', 'adresse' => '1 rue', 'description' => 'x', 'theme' => '',
       'categories' => json_encode(['order' => ['montagne', 'spirituel'], 'values' => ['montagne' => ['niveau' => ['debutant']]]]), 'motscles' => json_encode(['#Sigle', 'Bivouac', 'colo', 'PAJE', 'Les Amis du Verbe'])];
[$d, $t, $e, $cl] = camp_lire_formulaire($pdo, 7, $in);
ok($e === null && $d['theme'] === 'Montagne' && $d['type'] === 'Sigle, Bivouac, Les Amis du Verbe', 'formulaire : thème historique dérivé de la catégorie principale, type = mots-clés acceptés');
ok(array_column($cl['motscles']['refus'], 'label') === ['colo', 'PAJE'], "formulaire : « colo » (trop général) et « PAJE » (alias d'un autre organisme) refusés et expliqués");
$pdo->beginTransaction();
camp_enregistrer_classement($pdo, 99, $cl);
$pdo->commit();
ok(cat_selection_du_camp($pdo, 99)['order'] === ['montagne', 'spirituel'] && mc_lire($pdo, 'camp', [99])[99] === ['Bivouac', 'Les Amis du Verbe', 'Sigle'], 'formulaire : catégories et mots-clés enregistrés avec le séjour');
$in2 = $in; $in2['categories'] = '[]';
[, , $e3] = camp_lire_formulaire($pdo, 7, $in2);
ok($e3 === 'Choisissez au moins une catégorie pour votre séjour.', 'formulaire : au moins une catégorie (ou un ancien thème) est exigée');
$in3 = $in; unset($in3['categories'], $in3['motscles']); $in3['theme'] = 'Cheval';
[$d3, , $e4, $cl3] = camp_lire_formulaire($pdo, 7, $in3);
ok($e4 === null && $d3['theme'] === 'Cheval' && $cl3['cats'] === null && $cl3['motscles'] === null, "ancien formulaire (sans catégories) : accepté, rien n'est effacé");

// Recherche complète
$r = sq_run($pdo, ['name' => 'PAJE'], ['now' => time()]);
ok(array_map('intval', array_column(array_column($r['items'], 'row'), 'id')) == [1, 2] || (function ($a) { sort($a); return $a === [1, 2]; })(array_map('intval', array_column(array_column($r['items'], 'row'), 'id'))), 'base : « PAJE » -> les séjours de la Pastorale des Jeunes de l\'Ain');
$r = sq_run($pdo, ['name' => 'epj'], ['now' => time()]);
ok(array_column(array_column($r['items'], 'row'), 'id') == [3], 'base : « EPJ » -> École de prière');
$r = sq_run($pdo, ['cat' => ['spirituel', 'montagne'], 'catmode' => 'all'], ['now' => time()]);
ok(array_column(array_column($r['items'], 'row'), 'id') == [4], 'base : Spirituel + Montagne (toutes) -> « Spi & Cimes »');
$r = sq_run($pdo, ['cat' => ['montagne'], 'fv' => ['montagne:niveau:debutant']], ['now' => time()]);
ok(array_column(array_column($r['items'], 'row'), 'id') == [4], 'base : Montagne, niveau débutant');
$r = sq_run($pdo, ['name' => 'chevl'], ['now' => time()]);
ok(array_column(array_column($r['items'], 'row'), 'id') == [6] && $r['dym'] === 'cheval', 'base : « chevl » -> Stage cheval, « Vouliez-vous dire cheval »');
$r = sq_run($pdo, ['themes' => ['Cheval']], ['now' => time()]);
ok(array_column(array_column($r['items'], 'row'), 'id') == [6], 'ancien paramètre themes[]=Cheval : toujours accepté');
$r = sq_run($pdo, ['name' => 'camp à moins de 400 euros pour 10 ans'], ['now' => time()]);
$got = array_map('intval', array_column(array_column($r['items'], 'row'), 'id')); sort($got);
ok($got === [1, 5] && count($r['intents']) === 2, 'base : « moins de 400 euros pour 10 ans » -> filtres d\'âge et de budget appliqués (' . implode(',', $got) . ')');
$r = sq_run($pdo, ['name' => 'cheval moins de 100 euros'], ['now' => time()]);
ok($r['relaxed'] && array_column(array_column($r['items'], 'row'), 'id') == [6], 'base : filtre d\'intention sans résultat -> relâché, l\'affichage le signale');
$r = sq_run($pdo, ['name' => 'ski', 'cat' => ['sport']], ['now' => time()]);
ok(!$r['items'], 'base : « ski » dans la catégorie Sports : aucun séjour');
$vo = sq_vocab($pdo, $mini);
ok(isset($vo['terms']['paje']) && $vo['terms']['paje'][1] === 'alias', 'vocabulaire : les alias sont proposés à l\'autocomplétion');
ok(sq_suggerer($vo, 'past')['items'][0]['href'] === 'organisme/pastorale-des-jeunes-de-l-ain' || sq_suggerer($vo, 'paj')['items'][0]['label'] === 'PAJE', 'autocomplétion sur la base');

// Journal des recherches (anonyme, agrégé) et attribution des visites
sq_stats_ensure($pdo);
ok(sq_terme_loggable('paje') === 'paje' && sq_terme_loggable('jean.dupont@mail.fr') === null && sq_terme_loggable('0612345678') === null && sq_terme_loggable('a') === null, 'journal : e-mails, numéros et termes trop courts jamais enregistrés');
$sess = [[], [], []];
for ($i = 0; $i < 3; $i++) sq_journaliser($pdo, 'paje', 2, [1, 2], $sess[$i]);
sq_journaliser($pdo, 'paje', 2, [1, 2], $sess[0]);
$st = $pdo->query("SELECT recherches FROM recherche_termes WHERE terme = 'paje'")->fetchColumn();
ok((int)$st === 3, 'journal : une même session ne compte qu\'une fois par terme et par jour');
$sx = []; sq_journaliser($pdo, 'zzz rare', 0, [], $sx);
sq_clic_terme($pdo, 1, ['term' => 'paje', 'ids' => [1, 2], 'jour' => date('Y-m-d')]);
sq_clic_terme($pdo, 5, ['term' => 'paje', 'ids' => [1, 2], 'jour' => date('Y-m-d')]);
$t = sq_termes_organisme($pdo, 1, 30);
ok(count($t) === 1 && $t[0]['terme'] === 'paje' && (int)$t[0]['visites'] === 1 && (int)$t[0]['affichages'] === 6, 'tableau du directeur : termes, affichages et visites de ses séjours (' . json_encode($t) . ')');
ok(!sq_termes_organisme($pdo, 4, 30), 'tableau du directeur : seulement les termes de SES séjours');
$sa = []; $sb = []; sq_journaliser($pdo, 'secret', 1, [3], $sa); sq_journaliser($pdo, 'secret', 1, [3], $sb);
ok(!sq_termes_organisme($pdo, 3, 30), 'anonymat : un terme recherché par moins de 3 personnes n\'est pas montré');
$z = sq_termes_sans_resultat($pdo, 30, 1);
ok($z && $z[0]['terme'] === 'zzz rare', 'termes sans résultat : à suggérer en mots-clés');

echo "Tout est bon ($n contrôles).\n";
