<?php
/**
 * api/fiche_modele.php : LE SEUL FICHIER À ADAPTER pour brancher la fiche sanitaire de liaison OFFICIELLE (CERFA n°10008*02).
 *
 * Tant que le PDF officiel n'est pas déposé, api/sante_pdf.php dessine un GABARIT PROVISOIRE (même structure, mise en page simple).
 * Pour passer à la vraie fiche :
 *   1. Déposer le PDF officiel (vierge) ici :        libs/modele/fiche_sanitaire_officielle.pdf
 *   2. Déposer la bibliothèque FPDI (setasign/fpdi 2.x, avec son dossier src/) ici : libs/fpdi/   (ou `composer require setasign/fpdi`)
 *   3. Remplir fiche_champs() ci-dessous : pour chaque clé, [page, x_mm, y_mm, largeur_mm, taille_pt]
 *      (x et y mesurés depuis le coin HAUT-GAUCHE de la page, en millimètres ; y = ligne de base du texte).
 *      Les clés « case_* » reçoivent un « X » (cases à cocher). Une clé absente de la liste est simplement ignorée.
 * Aucun autre fichier à modifier.
 */
const FICHE_MODELE_PDF = __DIR__ . '/../libs/modele/fiche_sanitaire_officielle.pdf';

/** [clé => [page, x, y, largeur, taille]] : À REMPLIR avec le PDF officiel sous les yeux. Vide = gabarit provisoire. */
function fiche_champs(): array {
    return [
        // exemple : 'enfant_nom' => [1, 32, 41, 70, 10],
    ];
}

/** Données de la fiche (clé => texte), tirées de la fiche enfant. Les clés sont celles de fiche_champs(). */
function fiche_valeurs(array $e, string $signataire): array {
    $v = json_decode((string)($e['vaccins_data'] ?? ''), true) ?: [];
    $fr = fn($k) => trim((string)($e[$k] ?? ''));
    $dn = $fr('date_naissance') !== '' ? date('d/m/Y', strtotime($fr('date_naissance'))) : '';
    $sexe = $fr('sexe');
    $unite = fn($k, $u) => $fr($k) !== '' ? $fr($k) . ' ' . $u : '';
    $lien = $fr('urgence_lien') !== '' ? ' (' . $fr('urgence_lien') . ')' : '';
    return [
        'enfant_nom' => mb_strtoupper($fr('nom')),
        'enfant_prenom' => $fr('prenom'),
        'enfant_naissance' => $dn,
        'case_garcon' => $sexe === 'Homme' ? 'X' : '',
        'case_fille' => $sexe === 'Femme' ? 'X' : '',
        'taille' => $unite('taille', 'cm'),
        'poids' => $unite('poids', 'kg'),
        'vaccin_dtp' => (string)($v['dtp'] ?? ''),
        'vaccin_bcg' => (string)($v['bcg'] ?? ''),
        'vaccin_autre' => (string)($v['autre'] ?? ''),
        'allergies' => $fr('allergies'),
        'infos_sante' => $fr('infos_sante'),
        'traitements' => $fr('traitements'),
        'regime' => $fr('regime_alimentaire'),
        'medecin_nom' => $fr('medecin_nom'),
        'medecin_tel' => $fr('medecin_tel'),
        'resp1_nom_prenom' => trim($fr('resp1_prenom') . ' ' . mb_strtoupper($fr('resp1_nom'))),
        'resp1_statut' => $fr('resp1_statut'),
        'resp1_tel' => $fr('resp1_tel'),
        'resp1_email' => $fr('resp1_email'),
        'resp2_nom_prenom' => trim($fr('resp2_prenom') . ' ' . mb_strtoupper($fr('resp2_nom'))),
        'resp2_tel' => $fr('resp2_tel'),
        'adresse' => trim($fr('adresse') . ' ' . $fr('code_postal') . ' ' . $fr('ville')),
        'urgence' => trim($fr('urgence_nom') . $lien . '  ' . $fr('urgence_tel')),
        'signataire' => $signataire,
        'date' => date('d/m/Y'),
    ];
}
