<?php
// php tests/options_check.php : règles d'âge (par session), de sexe et de fiche enfant, sans base de données.
require __DIR__ . '/../api/options.php';
$s1 = ['age_min' => 14, 'age_max' => 16, 'date_debut' => '2027-07-03'];
$s2 = ['age_min' => 12, 'age_max' => 14, 'date_debut' => '2027-07-10'];
$enfant = ['prenom' => 'Léo', 'date_naissance' => '2013-07-05'];   // 13 ans le 3 juillet 2027, 14 ans le 10 juillet
assert(options_age_probleme($s1, $enfant) !== null, 'trop jeune au départ de la session 1 (13 ans)');
assert(options_age_probleme($s2, $enfant) === null, 'accepté sur la session 2 (14 ans)');
assert(options_sexe_admis(['reserve_sexe' => 'Femme'], 'Homme') === false);
assert(options_sexe_admis(['reserve_sexe' => null], 'Homme') === true);
assert(options_enfant_manquants(['champs_requis' => '["email"]'], ['email_enfant' => '']) === ['Email de l\'enfant']);
echo "ok\n";
