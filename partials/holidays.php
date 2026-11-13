<?php
// Fenêtres de vacances scolaires françaises (zones A/B/C confondues).
// À ajuster chaque année scolaire : volontairement isolé dans ce fichier (accueil et recherche l'utilisent).
$holiday_periods = [
    ['label' => 'Vacances de la Toussaint', 'start' => '2026-10-17', 'end' => '2026-11-02'],
    ['label' => 'Vacances de Noël',         'start' => '2026-12-19', 'end' => '2027-01-04'],
    ['label' => 'Vacances au Ski',          'start' => '2027-02-06', 'end' => '2027-03-09'],
    ['label' => 'Vacances de Printemps',    'start' => '2027-04-03', 'end' => '2027-05-03'],
    ['label' => "Vacances d'été",           'start' => '2027-07-03', 'end' => '2027-08-31'],
];
$today = date('Y-m-d');
$holiday_periods = array_values(array_filter($holiday_periods, function ($p) use ($today) {
    return $p['end'] >= $today;
}));
