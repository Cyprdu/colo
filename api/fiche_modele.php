<?php
/**
 * api/fiche_modele.php : branchement de la fiche sanitaire de liaison OFFICIELLE (CERFA n°10008*02, 1 page A4 paysage).
 *
 * Le fond de page est libs/modele/cerfa-10008-02.jpg (rendu 200 dpi du PDF officiel libs/modele/cerfa-10008-02.pdf) ; le générateur
 * (api/sante_pdf.php) y écrit les valeurs aux coordonnées ci-dessous, puis ajoute la signature manuscrite du parent.
 * Pour changer de modèle : remplacer l'image et ajuster fiche_champs(). Sans image, un gabarit provisoire est dessiné.
 *
 * Coordonnées en millimètres depuis le coin HAUT-GAUCHE de la page (299 x 210 mm).
 *   texte        : 'cle' => [1, x, y_ligne_de_base, largeur_max, taille_pt]        (réduit si trop long)
 *   multi-lignes : 'cle' => [1, x, y_première_ligne, largeur, taille_pt, interligne_mm, nb_lignes]
 *   case « X »   : 'case_*' => [1, x_centre, y_centre]
 */
const FICHE_MODELE_IMG = __DIR__ . '/../libs/modele/cerfa-10008-02.jpg';
const FICHE_PAGE_MM = [299.0, 210.0];

function fiche_champs(): array {
    return [
        // 1re colonne : identité, vaccinations, renseignements
        'enfant_nom' => [1, 40, 44.3, 90, 11], 'enfant_prenom' => [1, 40, 51.6, 90, 11],
        'naiss_j' => [1, 41, 58.9, 10, 11], 'naiss_m' => [1, 58, 58.9, 10, 11], 'naiss_a' => [1, 70, 58.9, 20, 11],
        'case_garcon' => [1, 39.7, 64.9], 'case_fille' => [1, 61.2, 65.1],
        'case_dip_oui' => [1, 42.3, 103.7], 'case_tet_oui' => [1, 42.3, 110.7], 'case_pol_oui' => [1, 42.3, 119.7],
        'vacc_dip' => [1, 57, 104.8, 27, 8], 'vacc_tet' => [1, 57, 111.8, 27, 8], 'vacc_pol' => [1, 57, 120.8, 27, 8],
        'vacc_bcg' => [1, 109, 144.0, 26, 8], 'vacc_autre' => [1, 109, 151.2, 26, 7],
        'poids' => [1, 21, 178.2, 14, 10], 'taille' => [1, 55, 178.2, 9, 10],
        'case_trait_oui' => [1, 76.9, 184.0], 'case_trait_non' => [1, 90.7, 184.0],
        // 2e colonne : allergies, santé, recommandations
        'case_all_alim_oui' => [1, 235.3, 13.4], 'case_all_alim_non' => [1, 249.1, 13.4],
        'case_all_med_oui' => [1, 235.3, 17.1], 'case_all_med_non' => [1, 249.1, 17.1],
        'case_all_aut_oui' => [1, 235.3, 20.8], 'case_all_aut_non' => [1, 249.1, 20.8],
        'allergies' => [1, 195, 24.9, 90, 8.5],
        'case_sante_oui' => [1, 250.5, 41.1], 'case_sante_non' => [1, 263.0, 41.1],
        'sante_detail' => [1, 166, 45.0, 119, 8.5, 3.7, 3],
        'reco' => [1, 166, 75.0, 119, 8.5, 3.7, 4],
        'resp1_nom' => [1, 199, 99.5, 22, 9], 'resp1_prenom' => [1, 236, 99.5, 48, 9],
        'resp1_adresse' => [1, 180, 103.2, 105, 8.5, 3.7, 2], 'resp1_tel' => [1, 188, 114.3, 45, 9],
        'resp2_nom' => [1, 201, 121.7, 23, 9], 'resp2_prenom' => [1, 236, 121.7, 48, 9],
        'resp2_tel' => [1, 188, 136.5, 45, 9],
        'medecin' => [1, 218, 147.3, 68, 9],
        'signataire' => [1, 187, 160.7, 55, 9], 'date' => [1, 174, 179.9, 30, 10],
        'signature' => [1, 240, 172.0, 48, 22],   // image : x, y, largeur, hauteur
    ];
}

/** Donnée « aucune » (aucune, non, rien, néant, RAS…) : le parent a répondu par la négative */
function fiche_est_negatif(string $v): bool { return (bool)preg_match('/^\s*(aucune?s?|non|rien|neant|néant|ras|pas de)\b/iu', $v); }

/** Données de la fiche (clé => texte ; « X » pour les cases), tirées de la fiche enfant. Les clés sont celles de fiche_champs(). */
function fiche_valeurs(array $e, string $signataire): array {
    $v = json_decode((string)($e['vaccins_data'] ?? ''), true) ?: [];
    $fr = fn($k) => trim((string)($e[$k] ?? ''));
    $ts = $fr('date_naissance') !== '' ? strtotime($fr('date_naissance')) : false;
    $sexe = $fr('sexe');
    $unite = fn($k) => $fr($k) !== '' ? preg_replace('/[^0-9.,]/', '', $fr($k)) : '';
    $x = fn($c) => $c ? 'X' : '';

    $all = $fr('allergies'); $allOui = $all !== '' && !fiche_est_negatif($all);
    $alim = $allOui && preg_match('/alim|arachid|cacahu|noix|noisette|lait|œuf|oeuf|gluten|poisson|fruit|soja|s[eé]same|fraise|crustac|moutarde|lupin|c[eé]leri|lactose/iu', $all);
    $med = $allOui && preg_match('/m[eé]dic|p[eé]nicill|amoxi|antibio|aspirine|ibuprof|parac[eé]tamol|vaccin/iu', $all);
    $aut = $allOui && (preg_match('/pollen|animal|chat|chien|acarien|abeille|gu[eê]pe|piq[uû]re|latex|poussi[eè]re|graminée|moisissure|soleil/iu', $all) || (!$alim && !$med));
    $sante = $fr('infos_sante'); $santeOui = $sante !== '' && !fiche_est_negatif($sante);
    $trait = $fr('traitements'); $traitOui = $trait !== '' && !fiche_est_negatif($trait);
    $dtp = (string)($v['dtp'] ?? '');
    $reco = array_filter([$fr('regime_alimentaire') !== '' ? 'Régime : ' . $fr('regime_alimentaire') . '.' : '', $traitOui ? 'Traitement : ' . $trait . '.' : '', $fr('commentaires')]);
    $adr = trim($fr('adresse') . ' ' . $fr('code_postal') . ' ' . $fr('ville'));

    return [
        'enfant_nom' => mb_strtoupper($fr('nom')), 'enfant_prenom' => $fr('prenom'),
        'naiss_j' => $ts ? date('d', $ts) : '', 'naiss_m' => $ts ? date('m', $ts) : '', 'naiss_a' => $ts ? date('Y', $ts) : '',
        'case_garcon' => $x($sexe === 'Homme'), 'case_fille' => $x($sexe === 'Femme'),
        'case_dip_oui' => $x($dtp !== ''), 'case_tet_oui' => $x($dtp !== ''), 'case_pol_oui' => $x($dtp !== ''),
        'vacc_dip' => $dtp, 'vacc_tet' => $dtp, 'vacc_pol' => $dtp,
        'vacc_bcg' => (string)($v['bcg'] ?? ''), 'vacc_autre' => (string)($v['autre'] ?? ''),
        'poids' => $unite('poids'), 'taille' => $unite('taille'),
        'case_trait_oui' => $x($traitOui), 'case_trait_non' => $x($trait !== '' && !$traitOui),
        'case_all_alim_oui' => $x($alim), 'case_all_alim_non' => $x($all !== '' && !$alim),
        'case_all_med_oui' => $x($med), 'case_all_med_non' => $x($all !== '' && !$med),
        'case_all_aut_oui' => $x($aut), 'case_all_aut_non' => $x($all !== '' && !$aut),
        'allergies' => $allOui ? $all : '',
        'case_sante_oui' => $x($santeOui), 'case_sante_non' => $x($sante !== '' && !$santeOui),
        'sante_detail' => $santeOui ? $sante : '',
        'reco' => implode(' ', $reco),
        'resp1_nom' => mb_strtoupper($fr('resp1_nom')), 'resp1_prenom' => $fr('resp1_prenom'), 'resp1_adresse' => $adr, 'resp1_tel' => $fr('resp1_tel'),
        'resp2_nom' => mb_strtoupper($fr('resp2_nom')), 'resp2_prenom' => $fr('resp2_prenom'), 'resp2_tel' => $fr('resp2_tel'),
        'medecin' => trim($fr('medecin_nom') . '  ' . $fr('medecin_tel')),
        'signataire' => $signataire, 'date' => date('d/m/Y'),
        // utilisés seulement par le gabarit provisoire
        '_urgence' => trim($fr('urgence_nom') . ($fr('urgence_lien') !== '' ? ' (' . $fr('urgence_lien') . ')' : '') . '  ' . $fr('urgence_tel')),
        '_regime' => $fr('regime_alimentaire'), '_traitements' => $trait, '_email' => $fr('resp1_email'),
    ];
}
