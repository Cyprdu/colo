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
// Annulation par la famille : paliers 100 % / 50 % / 0 % (défaut 30 j / 15 j)
ok(fin_remboursable_pct(45, 30, 15) === 100 && fin_remboursable_pct(30, 30, 15) === 100, 'à J-30 et plus : 100 %');
ok(fin_remboursable_pct(29, 30, 15) === 50 && fin_remboursable_pct(15, 30, 15) === 50, 'de J-29 à J-15 : 50 %');
ok(fin_remboursable_pct(14, 30, 15) === 0 && fin_remboursable_pct(0, 30, 15) === 0, 'moins de 15 jours : 0 %');
// Remboursement = part remboursable - commission conservée : [remboursé, retenue, pct]. Paiement de 100 EUR, commission 2,80 EUR
ok(fin_annul_montants(10000, 280, 40, 30, 15) === [9720, 280, 100], '100 % : 97,20 EUR rendus, 2,80 EUR de commission conservés');
ok(fin_annul_montants(10000, 280, 20, 30, 15) === [4720, 280, 50], '50 % : 50 - 2,80 = 47,20 EUR');
ok(fin_annul_montants(10000, 280, 5, 30, 15) === [0, 0, 0], '0 % : rien à rendre, rien à retenir');
ok(fin_annul_montants(300, 280, 20, 30, 15) === [0, 150, 50], 'commission supérieure à la part remboursable : remboursement nul, jamais négatif');
[$r, $k] = fin_annul_montants(2500, 93, 40, 30, 15);
ok($r + $k === 2500 && $r === 2407, 'commande de 25 EUR (commission 0,93) : 24,07 EUR = le net de l\'organisme');
// L'organisme n'est débité que du remboursé : son net restant = net - remboursé = ce qu'il garde selon ses conditions (brut - part remboursable)
[$r] = fin_annul_montants(10000, 280, 20, 30, 15);
ok((10000 - 280) - $r === 10000 - 5000, 'organisme : net 97,20 - remboursé 47,20 = 50,00 = 50 % du brut');
echo "Tout est bon.\n";
