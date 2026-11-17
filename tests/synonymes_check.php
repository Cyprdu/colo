<?php
// Contrôle de forme de la liste de synonymes SQ_SYN (api/search_text.php) :  php tests/synonymes_check.php
// À lancer après chaque modification de la liste (voir docs/consigne-synonymes.md). Code de sortie 1 s'il y a une erreur.
require __DIR__ . '/../api/search_text.php';

$erreurs = []; $vus = [];
foreach (SQ_SYN as $i => $g) {
    $n = count($g);
    if ($n < 2 || $n > 14) $erreurs[] = "groupe $i : $n mots (entre 2 et 14 attendus)";
    foreach ($g as $w) {
        if (!is_string($w) || $w !== sq_norm($w) || strpos($w, ' ') !== false) { $erreurs[] = "groupe $i : « $w » doit être un seul mot, minuscules, sans accents ni ponctuation"; continue; }
        if (strlen($w) < 3) $erreurs[] = "groupe $i : « $w » trop court";
        if (in_array($w, SQ_STOP, true) || in_array(sq_stem($w), array_map('sq_stem', SQ_GENERIC), true)) $erreurs[] = "groupe $i : « $w » est un mot passe-partout (SQ_STOP / SQ_GENERIC)";
        if (sq_stem($w) !== $w && in_array(sq_stem($w), $g, true)) $erreurs[] = "groupe $i : « $w » et sa racine « " . sq_stem($w) . " » sont tous deux listés : garder la forme au singulier";
        $vus[sq_stem($w)][] = $i;
    }
    if (count(array_unique(array_map('sq_stem', $g))) !== $n) $erreurs[] = "groupe $i : doublon (même racine)";
}
foreach ($vus as $w => $gs) if (count(array_unique($gs)) > 2) $erreurs[] = "« $w » apparaît dans " . count(array_unique($gs)) . " groupes (2 au plus : sinon les groupes se fondent en un seul)";
if ($erreurs) { fwrite(STDERR, implode("\n", $erreurs) . "\n"); exit(1); }
echo count(SQ_SYN) . " groupes, " . array_sum(array_map('count', SQ_SYN)) . " mots : forme correcte.\n";
