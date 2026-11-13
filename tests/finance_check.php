<?php
// Contrôle rapide des calculs (sans base ni Stripe) :  php tests/finance_check.php
require __DIR__ . '/../api/marketing_lib.php';
require __DIR__ . '/../api/finance_lib.php';
require __DIR__ . '/../api/annulation_lib.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ECHEC : $msg\n"); exit(1); } echo "ok  $msg\n"; }

// Commission « tout compris » (défauts : 2,5 % + 0,30 €) : arrondie au centime, jamais supérieure au brut
ok(fin_commission_split(2500) === [93, 63, 30], 'commande de 25 EUR : 0,63 + 0,30 = 0,93 EUR de commission (net 24,07)');
ok(fin_commission_cents(10000) === 280, 'commission de 100 EUR = 2,80 EUR');
ok(fin_commission_cents(10000, 0) === 250, 'pourcentage seul (solde historique)');
ok(fin_commission_cents(0) === 0, 'rien de prélevé sur 0');
ok(fin_commission_cents(20) === 20, 'la commission ne dépasse jamais le brut');
ok(fin_commission_split(2500, 1.0, 0)[0] === 25, 'taux explicite 1 % : 0,25 EUR (ancien modèle)');
// Répartition des frais : somme exacte
ok(array_sum(fin_allocate(63, [1 => 1000, 2 => 1000, 3 => 1000])) === 63, 'répartition de 63 c sur 3 lignes : somme exacte');
ok(array_sum(fin_allocate(5, [1 => 0, 2 => 0])) === 5, 'répartition avec poids nuls : le reste va à la dernière ligne');
ok(fin_orga_tok(7) !== fin_orga_tok(8) && strlen(fin_orga_tok(7)) === 24, 'jeton organisme opaque');
// CSV : formules neutralisées, nombres négatifs conservés
require __DIR__ . '/../api/compta_lib.php';
ok(compta_cell('=2+2') === "\"'=2+2\"", 'CSV : formule neutralisee');
ok(compta_cell('-12,50') === '"-12,50"', 'CSV : montant négatif intact');
ok(compta_eur(1234) === '12,34', 'CSV : virgule décimale');
// Date de déblocage : après le début du séjour, jamais dans le passé
ok(strtotime(fin_release_date(date('Y-m-d', strtotime('+10 day')))) > strtotime('+10 day'), 'déblocage après le début du séjour');
ok(strtotime(fin_release_date('2020-01-01')) > time(), 'séjour déjà passé : déblocage dans le futur proche');

// Score : un boost augmente le score, une remontée s'estompe, un séjour complet chute
$now = '2026-11-13 12:00:00';
$base = ['id' => 7, 'relevance_score' => 40, 'date_debut' => '2026-12-20', 'nb_places_restantes' => 10, 'ctr30' => 0.05, 'date_creation' => '2026-01-01'];
$s0 = mk_score($base, $now);
$sv = mk_score($base + ['boost_vedette_fin' => '2026-11-20 00:00:00'], $now);
ok($sv['boost_factor'] > 1.5 && $sv['sponsorise'], 'mise à la une : x1,55 et sponsorisé');
ok($sv['score'] > $s0['score'] * 1.4, 'le score boosté dépasse nettement le score organique');
$b1 = mk_score($base + ['date_bump' => '2026-11-13 11:00:00'], $now)['boost_factor'];
$b2 = mk_score($base + ['date_bump' => '2026-11-11 12:00:00'], $now)['boost_factor'];
ok($b1 > $b2 && $b2 > 1, 'la remontée s\'estompe avec le temps');
ok(mk_score($base + ['date_bump' => '2026-11-01 12:00:00'], $now)['boost_factor'] === 1.0, 'remontée expirée après 72 h');
$full = mk_score(['nb_places_restantes' => 0, 'quota_global' => 20] + $base, $now);
ok($full['organique'] < $s0['organique'] * 0.5, 'séjour complet fortement rétrogradé');
$urgLoin = mk_score(['date_debut' => '2027-03-01'] + $base + ['boost_urgence_fin' => '2026-11-20 00:00:00'], $now);
ok($urgLoin['boost_factor'] === 1.0, 'dernière minute sans effet si le départ est à plus de 45 jours');
echo "Tout est bon.\n";
