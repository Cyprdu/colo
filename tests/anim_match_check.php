<?php
// Contrôle rapide du moteur de suggestions (sans base) :  php tests/anim_match_check.php
require __DIR__ . '/../api/anim_match.php';

function ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "ECHEC : $msg\n"); exit(1); } echo "ok  $msg\n"; }

$camp = ['nom' => 'Aventure sportive', 'theme' => 'Sport', 'type' => '', 'itinerant' => 0, 'code_postal' => '38000', 'date_debut' => '2027-07-10', 'date_fin' => '2027-07-20',
         'age_min' => 8, 'age_max' => 12, 'bafa_obligatoire' => 0, 'anim_plus_18' => 0, 'anim_remuneration' => 300];
$base = anim_prefs_defaut();

ok(anim_dept('38000') === '38' && anim_dept('20190') === '20' && anim_dept('97400') === '974' && anim_region('38') === 'Auvergne-Rhône-Alpes', 'département et région depuis le code postal');
ok(anim_periode_match(['ete'], '2027-07-10', '2027-07-20') === 'Été' && anim_periode_match(['noel'], '2027-07-10', '2027-07-20') === null, 'vacances d\'été reconnues, Noël non');

// BAFA exigé : refusé sans BAFA, accepté avec
$exige = ['bafa_obligatoire' => 1] + $camp;
ok(anim_score($exige, $base, [], 25) === null, 'BAFA exigé, animateur sans BAFA : séjour écarté');
ok(anim_score($exige, $base, ['bafa'], 25) !== null, 'BAFA exigé, animateur avec BAFA : proposé');
ok(in_array('Compatible BAFA stagiaire', anim_score($camp, $base, ['stagiaire'], 20)['raisons'], true), 'stagiaire : « compatible BAFA stagiaire »');

// Localisation : département > région > hors zone
$p = ['code_postal' => '38100', 'rayon' => 'dept'] + $base;
ok(in_array('Dans votre département', anim_score($camp, $p, ['bafa'], 25)['raisons'], true), 'même département');
$p = ['code_postal' => '69001', 'rayon' => 'region'] + $base;
ok(in_array('Dans votre région', anim_score($camp, $p, ['bafa'], 25)['raisons'], true), 'même région');
$p = ['code_postal' => '69001', 'rayon' => 'dept'] + $base;
ok(anim_score($camp, $p, ['bafa'], 25) === null, 'région voisine mais rayon « département » : écarté');
$p = ['code_postal' => '75001', 'rayon' => 'france'] + $base;
ok(anim_score($camp, $p, ['bafa'], 25) !== null, 'toute la France : proposé');

// Rémunération et bénévolat
ok(anim_score($camp, ['remu_min' => 500.0] + $base, ['bafa'], 25) === null, 'rémunération minimale non atteinte : écarté');
$benevole = ['anim_remuneration' => null] + $camp;
ok(anim_score($benevole, ['benevole' => false] + $base, ['bafa'], 25) === null, 'bénévole refusé : écarté');
ok(anim_score($benevole, $base, ['bafa'], 25) !== null, 'bénévole accepté : proposé');
ok(anim_score(['anim_plus_18' => 1] + $camp, $base, ['bafa'], 17) === null, 'majeurs uniquement : un mineur est écarté');

// Disponibilités : un séjour pendant les vacances choisies passe devant les autres
$a = anim_score($camp, ['periodes' => ['ete']] + $base, ['bafa'], 25)['score'];
$b = anim_score($camp, ['periodes' => ['noel']] + $base, ['bafa'], 25)['score'];
ok($a > $b, 'disponible pendant le séjour : score plus élevé');
echo "Tout est bon.\n";
