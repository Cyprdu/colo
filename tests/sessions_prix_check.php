<?php
// php tests/sessions_prix_check.php : la carte d'un séjour à plusieurs sessions affiche « dès » le plus petit prix de TOUTES ses sessions.
require __DIR__ . '/../api/sessions.php';
$rows = [
    ['id' => 2, 'groupe_id' => 1, 'date_debut' => '2027-07-10', 'date_fin' => '2027-07-16', 'prix_affiche' => 1189],
    ['id' => 1, 'groupe_id' => 1, 'date_debut' => '2027-07-03', 'date_fin' => '2027-07-09', 'prix_affiche' => 685],
    ['id' => 9, 'groupe_id' => null, 'date_debut' => '2027-08-01', 'date_fin' => '2027-08-05', 'prix_affiche' => 0, 'prix' => 0],
];
$out = collapseSessions([$rows[0], $rows[2]], $rows);   // la recherche ne retient que la session à 1189 €
assert(count($out) === 2);
assert($out[0]['prix_min'] === 685.0 && $out[0]['sessions_count'] === 2 && $out[0]['date_debut_min'] === '2027-07-03');
assert($out[1]['prix_min'] === 0.0);
assert($out[0]['id'] === 2);   // seule session retenue par la recherche : c'est elle que la carte ouvre

// La carte ouvre la prochaine session à venir du groupe, même si une session plus lointaine est mieux classée
$out = collapseSessions([$rows[0] + ['token' => 'loin'], $rows[2], $rows[1] + ['token' => 'proche']], $rows);
assert(count($out) === 2 && $out[0]['id'] === 1 && $out[0]['token'] === 'proche' && $out[0]['date_debut'] === '2027-07-03' && $out[1]['id'] === 9);
$hier = date('Y-m-d', strtotime('-1 day')); $sem = date('Y-m-d', strtotime('+7 days'));
$out = collapseSessions([['id' => 5, 'groupe_id' => 3, 'token' => 'en-cours', 'date_debut' => $hier], ['id' => 6, 'groupe_id' => 3, 'token' => 'a-venir', 'date_debut' => $sem]]);
assert($out[0]['token'] === 'a-venir');   // une session déjà commencée passe après une session à venir
echo "ok\n";
