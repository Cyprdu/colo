<?php
// Contrôle rapide des calculs (sans base ni Stripe) :  php tests/finance_check.php
require __DIR__ . '/../api/marketing_lib.php';
require __DIR__ . '/../api/finance_lib.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ECHEC : $msg\n"); exit(1); } echo "ok  $msg\n"; }

// Commission : arrondie au centime, jamais supérieure au brut
ok(fin_commission_cents(10000) === 100, 'commission 1 % de 100 EUR = 1 EUR');
ok(fin_commission_cents(333) === 3, 'commission arrondie (3,33 c -> 3)');
ok(fin_commission_cents(0) === 0, 'commission de 0');
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
