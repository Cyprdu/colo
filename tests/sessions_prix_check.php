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
echo "ok\n";
