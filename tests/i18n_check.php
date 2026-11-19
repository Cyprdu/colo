<?php
$_GET = ['name' => 'ski', 'lang' => 'en'];
$_SERVER['REQUEST_URI'] = '/recherche?name=ski&lang=en';
$_SERVER['HTTP_HOST'] = 'moncamp.fr';
$_SERVER['HTTPS'] = 'on';
ob_start();
require dirname(__DIR__) . '/api/i18n.php';
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }

ok(mc_lang() === 'en', 'langue choisie par ?lang=en');
ok(t('Accueil') === 'Home', 't() traduit');
ok(t('Texte absent du dictionnaire') === 'Texte absent du dictionnaire', 't() rend le français si la clé manque');
ok(t('Notifications d’administration') === t("Notifications d'administration"), 'apostrophes typographiques et droites équivalentes');
ok(mc_money(1460) === '€1,460.00' && mc_money(40, 0) === '€40', 'monnaie en anglais');
ok(mc_date('2026-12-19') === '19 Dec 2026', 'date en anglais');
ok(mc_i18n_fmt('Dès 1 460,50 €') === 'Dès €1,460.50', 'conversion des montants');
ok(mc_i18n_fmt('du 19 déc. au 1er janvier') === 'du 19 Dec au 1 January', 'conversion des mois');
ok(mc_i18n_pattern('69 séjours trouvés') === '69 camps found' && mc_i18n_pattern('Camps garçons 8-12 ans') === null, 'clé à variable, jamais sur un texte libre');
ok(str_contains(mc_hreflang(), 'hreflang="en" href="https://moncamp.fr/recherche?name=ski&amp;lang=en"') && str_contains(mc_hreflang(), 'hreflang="fr" href="https://moncamp.fr/recherche?name=ski"'), 'balises hreflang');
$h = mc_i18n_out('<a title="Rechercher" href="x">  Accueil </a><option value="Homme">Homme</option><input value="Accueil"><p>Une description saisie par un organisme : Accueil chaleureux.</p><script>if (a > 0 && b < 2) x = 1;</script>');
ok(str_contains($h, '<a title="Search" href="x">  Home </a>'), 'filtre : texte et attribut traduits, espaces gardés');
ok(str_contains($h, '<option value="Homme">Male</option>') && str_contains($h, 'value="Accueil"'), 'filtre : valeurs de formulaire intactes');
ok(str_contains($h, 'Accueil chaleureux.') && str_contains($h, 'if (a > 0 && b < 2)'), 'filtre : contenu libre et code intacts');
foreach (mc_dict('en') as $k => $v) if (!str_starts_with($k, 'months.') && preg_match('/[\'"`<>\\\\&]/', $v)) ok(false, "caractère interdit dans la traduction de « $k »");
ok(true, 'traductions sans caractère qui casserait le HTML ou le JS');
