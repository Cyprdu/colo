<?php
// php tests/regles_check.php : règles d'admission (âge / tranches, conditions des tarifs, rôles, places).
require __DIR__ . '/../api/regles.php';
function ok($c, $m) { if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); } echo "ok  $m\n"; }

[$mode, $tr, $min, $max, $err] = regles_age_lire(['age_mode' => 'tranche', 'tranches' => ['college', 'lycee', 'zzz'], 'age_min' => 1, 'age_max' => 2]);
ok($mode === 'tranche' && $tr === ['college', 'lycee'] && [$min, $max] === [11, 19] && !$err, 'mode tranches : âges déduits, code inconnu ignoré');
ok(regles_age_lire(['age_mode' => 'les2', 'age_min' => 8, 'age_max' => 12])[4] !== null, 'mode « les deux » sans tranche refusé');
ok(regles_age_lire(['age_min' => 8, 'age_max' => 12])[0] === 'age', 'ancien formulaire : âge exact');

$camp = ['age_mode' => 'les2', 'age_min' => 11, 'age_max' => 15, 'tranches' => 'college'];
ok(admission_probleme(regles_crit_camp($camp), 13, 'college', 'Léa') === null, 'âge et tranche conformes');
ok(admission_probleme(regles_crit_camp($camp), 16, 'college', 'Léa') !== null, 'les deux : âge hors bornes refusé');
ok(admission_probleme(regles_crit_camp($camp), 13, 'lycee', 'Léa') !== null, 'les deux : tranche hors liste refusée');
ok(admission_probleme(regles_crit_camp(['age_mode' => 'tranche', 'age_min' => 11, 'age_max' => 15, 'tranches' => 'college']), 16, 'college', 'Léa') === null, 'tranches seules : un collégien de 16 ans passe');
ok(admission_probleme(regles_crit_camp(['age_min' => 8, 'age_max' => 12]), 13, null, 'Tom') !== null, 'ancien séjour : âge contrôlé comme avant');

ok(is_string(regles_tarif_lire(['quota' => 2, 'quota_f' => 3], 10, 'T')), 'filles > places du tarif refusé');
ok(is_string(regles_tarif_lire(['quota' => 20], 10, 'T')), 'tarif > places du séjour refusé');
ok(regles_tarif_lire(['age_min' => '16', 'tranches' => ['etudes']], 10, 'T') === ['age_min' => 16, 'age_max' => 99, 'tranches' => ['etudes']], 'âge minimum seul : maximum 99');
ok(regles_tarif_lire([], 10, 'T') === [], 'aucune règle');
ok(is_string(regles_tarif_lire(['roles' => [['nom' => 'Parent']]], 10, 'T')), 'un seul rôle refusé');
$r = regles_tarif_lire(['roles' => [['nom' => 'Parent', 'age_min' => 18, 'sexe' => 'X'], ['nom' => 'Enfant', 'age_max' => 12], ['nom' => '']]], 10, 'T');
ok(count($r['roles']) === 2 && !isset($r['roles'][0]['sexe']) && $r['roles'][1]['age_min'] === 0, 'rôles nettoyés');
ok(regles_crit_role(['nom' => 'Ado'], $camp)['mode'] === 'les2' && regles_crit_role(['nom' => 'Ado'], $camp)['tranches'] === ['college'], 'rôle sans condition : celles de la session');

$camp = ['quota_global' => 5, 'quota_fille' => 2, 'quota_garcon' => 0];
$regles = [1 => ['quota' => 2, 'quota_g' => 1], 2 => []];
$occ = [['tarif_id' => 1, 'sexe' => 'Homme'], ['tarif_id' => 2, 'sexe' => 'Femme']];
ok(places_restantes($camp, $regles, $occ) === ['total' => 3, 'f' => 1, 'g' => null], 'places restantes de la session');
ok(places_restantes($camp, $regles, $occ, 1) === ['total' => 1, 'f' => null, 'g' => 0], 'places restantes du tarif');
ok(places_probleme($camp, $regles, $occ, [['tarif_id' => 1, 'sexe' => 'Femme']]) === null, 'une fille au tarif 1 : accepté');
ok(str_contains((string)places_probleme($camp, $regles, $occ, [['tarif_id' => 1, 'sexe' => 'Homme']], [1 => 'Normal']), 'garçons'), 'garçon au tarif 1 : quota garçons du tarif atteint');
ok(str_contains((string)places_probleme($camp, $regles, $occ, [['tarif_id' => 2, 'sexe' => 'Femme'], ['tarif_id' => 2, 'sexe' => 'Femme']]), 'filles'), 'deux filles : quota filles de la session');
ok(places_probleme($camp, $regles, $occ, array_fill(0, 4, ['tarif_id' => 2, 'sexe' => 'Homme'])) === 'Il ne reste que 3 places pour ce séjour.', 'session : 3 places restantes');
echo "Tous les contrôles passent.\n";
