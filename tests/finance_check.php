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

// Garantie annulation : supplément en % du total HORS garantie (arrondi au centime), jamais sur un total nul
ok(fin_garantie_cents(10000, 2.0) === 200, 'garantie 2 % sur 100 EUR = 2,00 EUR');
ok(fin_garantie_cents(2575, 2.0) === 52, 'garantie arrondie au centime (51,5 -> 52)');
ok(fin_garantie_cents(0, 2.0) === 0 && fin_garantie_cents(10000, 0.0) === 0, 'pas de garantie sur 0 EUR ni à 0 %');
// Conditions d'annulation (défaut 30 j / 14 j) : 100 % / 50 % / rien
ok(fin_remboursable_pct(45, 30, 14) === 100 && fin_remboursable_pct(30, 30, 14) === 100, 'plus de 30 jours : 100 %');
ok(fin_remboursable_pct(29, 30, 14) === 50 && fin_remboursable_pct(14, 30, 14) === 50, 'entre 14 et 29 jours : 50 %');
ok(fin_remboursable_pct(13, 30, 14) === 0 && fin_remboursable_pct(0, 30, 14) === 0, 'moins de 14 jours : rien');
// Remboursable : [organisme, prise en charge garantie, total famille]
ok(fin_annul_montants(10000, 20, 30, 14, false, 'maladie') === [5000, 0, 5000], 'sans garantie : seulement la part de l\'organisme (50 %)');
ok(fin_annul_montants(10000, 20, 30, 14, true, 'maladie') === [5000, 5000, 10000], 'garantie + maladie : la famille récupère tout, ColoMap complète');
ok(fin_annul_montants(10000, 20, 30, 14, true, 'autre') === [5000, 0, 5000], 'garantie mais motif non couvert : part organisme seule');
ok(fin_annul_montants(10000, 5, 30, 14, true, 'deces') === [0, 10000, 10000], 'moins de 14 jours + décès + garantie : 100 % pris en charge par ColoMap');
ok(fin_annul_montants(3333, 20, 30, 14, true, 'maladie')[2] === 3333, 'somme exacte au centime près');
ok(fin_annul_montants(-5, 40, 30, 14, true, 'maladie') === [0, 0, 0], 'jamais de montant négatif');
echo "Tout est bon.\n";
