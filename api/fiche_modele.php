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
 *
 * Données propres à la fiche (vaccins, catégories d'allergies, téléphones…) : JSON de la colonne enfants.vaccins_data.
 */
const FICHE_MODELE_IMG = __DIR__ . '/../libs/modele/cerfa-10008-02.jpg';
const FICHE_PAGE_MM = [299.0, 210.0];

/** Vaccins du CERFA : clé => libellé. Les trois premiers sont obligatoires (cases oui / non). JSON : v_<clé> = oui|non, d_<clé> = AAAA-MM-JJ */
const FICHE_VACCINS = ['dip' => 'Diphtérie', 'tet' => 'Tétanos', 'pol' => 'Poliomyélite', 'coq' => 'Coqueluche', 'hib' => 'Haemophilus',
    'ror' => 'Rubéole-Oreillons-Rougeole', 'hepb' => 'Hépatite B', 'pneumo' => 'Pneumocoque', 'bcg' => 'BCG', 'autre' => 'Autre vaccin'];
const FICHE_VACCINS_OBLIG = ['dip', 'tet', 'pol'];

/** Questions oui / non de la rubrique Santé : clé => [question, valeur enregistrée pour « Non », aide]. « Oui » impose de préciser. */
const FICHE_YN = [
    'allergies' => ['Votre enfant a-t-il des allergies ?', 'Aucune', "Aliment, médicament, pollen, animaux… et la conduite à tenir."],
    'infos_sante' => ['Votre enfant a-t-il un problème de santé ou un handicap ?', 'Aucun', 'Asthme, diabète, épilepsie, handicap… et les précautions à prendre.'],
    'antecedents' => ['A-t-il des antécédents médicaux ?', 'Aucun', 'Maladies, opérations, hospitalisations, accidents.'],
    'traitements' => ['Suit-il un traitement médical pendant le séjour ?', 'Aucun', "Médicament, dose, horaires. L'ordonnance et les médicaments seront à remettre au départ."],
    'regime_alimentaire' => ['Suit-il un régime alimentaire particulier ?', 'Aucun', 'Sans porc, végétarien, sans gluten, PAI…'],
    'reco' => ['Avez-vous des recommandations particulières ?', 'Aucune', 'Lunettes, lentilles, appareil dentaire ou auditif, sommeil, énurésie, comportement…'],
];
/** Clés de FICHE_YN stockées dans le JSON (les autres sont des colonnes) */
const FICHE_YN_JSON = ['antecedents', 'reco'];
/** Autres clés du JSON saisies dans le formulaire */
const FICHE_JSON_PARENTS = ['resp1_tel_dom', 'resp1_tel_trav', 'resp2_adresse', 'resp2_tel_dom', 'resp2_tel_trav'];

function fiche_champs(): array {
    $c = [
        // 1re colonne : identité
        'enfant_nom' => [1, 40, 43.4, 72, 11], 'enfant_prenom' => [1, 28, 50.8, 84, 11],
        'naiss_j' => [1, 45, 58.1, 7, 11], 'naiss_m' => [1, 54.5, 58.1, 7, 11], 'naiss_a' => [1, 70, 58.1, 20, 11],
        'case_garcon' => [1, 39.7, 64.9], 'case_fille' => [1, 61.2, 65.1],
        // renseignements
        'poids' => [1, 20.5, 177.4, 11, 10], 'taille' => [1, 47, 177.4, 12, 10],
        'case_trait_oui' => [1, 76.9, 184.0], 'case_trait_non' => [1, 90.7, 184.0],
        // 2e colonne : allergies, santé, recommandations
        'case_all_alim_oui' => [1, 235.7, 13.4], 'case_all_alim_non' => [1, 249.4, 13.4],
        'case_all_med_oui' => [1, 235.7, 17.1], 'case_all_med_non' => [1, 249.4, 17.1],
        'case_all_aut_oui' => [1, 235.7, 20.8], 'case_all_aut_non' => [1, 249.4, 20.8],
        'allergies' => [1, 195, 24.5, 92, 8],
        'case_sante_oui' => [1, 250.5, 41.1], 'case_sante_non' => [1, 263.0, 41.1],
        'sante_detail' => [1, 166, 44.0, 121, 8.5, 3.7, 3],
        'reco' => [1, 166, 73.9, 121, 8.5, 3.7, 4],
        // responsables
        'resp1_nom' => [1, 200, 98.6, 21, 9], 'resp1_prenom' => [1, 238, 98.6, 49, 9],
        'resp1_adresse' => [1, 180, 101.2, 107, 8.5, 3.7, 2],
        'resp1_tel_dom' => [1, 186, 109.7, 35, 9], 'resp1_tel_trav' => [1, 239, 109.7, 48, 9], 'resp1_tel' => [1, 188, 113.4, 34, 9],
        'resp2_nom' => [1, 201.5, 120.8, 20, 9], 'resp2_prenom' => [1, 238, 120.8, 49, 9],
        'resp2_adresse' => [1, 180, 123.4, 107, 8.5, 3.7, 2],
        'resp2_tel_dom' => [1, 186, 131.9, 35, 9], 'resp2_tel_trav' => [1, 239, 131.9, 48, 9], 'resp2_tel' => [1, 188, 135.6, 34, 9],
        'medecin' => [1, 212, 146.4, 75, 9],
        'signataire' => [1, 187, 159.9, 55, 9], 'date' => [1, 174, 179.9, 30, 10],
        'signature' => [1, 240, 172.0, 48, 22],   // image : x, y, largeur, hauteur
    ];
    // tableau des vaccinations : lignes (centre en mm) ; obligatoires à gauche, recommandés à droite
    $ligne = ['dip' => 103.8, 'tet' => 110.8, 'pol' => 119.7, 'coq' => 103.8, 'hib' => 110.8, 'ror' => 119.7, 'hepb' => 128.4, 'pneumo' => 135.7, 'bcg' => 143.1, 'autre' => 150.2];
    foreach ($ligne as $k => $y) {
        if (in_array($k, FICHE_VACCINS_OBLIG, true)) {
            $c["case_{$k}_oui"] = [1, 42.1, $y]; $c["case_{$k}_non"] = [1, 51.3, $y];
            $c["vacc_$k"] = [1, 57.5, $y + 1.3, 23.5, 9];
        } else $c["vacc_$k"] = [1, 108.3, $y + 1.3, 26, 9];
    }
    return $c;
}

/** Donnée « aucune » (aucune, non, rien, néant, RAS…) : le parent a répondu par la négative */
function fiche_est_negatif(string $v): bool { return (bool)preg_match('/^\s*(aucune?s?|non|rien|neant|néant|ras|pas de)\b/iu', $v); }

/** État d'une question oui / non : 'oui' | 'non' | '' (sans réponse) */
function fiche_yn_etat(string $v): string { return trim($v) === '' ? '' : (fiche_est_negatif($v) ? 'non' : 'oui'); }

/** JSON de la fiche (vaccins_data) */
function fiche_json(array $e): array { return json_decode((string)($e['vaccins_data'] ?? ''), true) ?: []; }

/** Valeur d'une clé de la fiche : colonne, sinon JSON */
function fiche_val(array $e, string $k): string {
    if (array_key_exists($k, $e)) return trim((string)$e[$k]);
    return trim((string)(fiche_json($e)[$k] ?? ''));
}

/** Date AAAA-MM-JJ -> JJ/MM/AAAA (texte libre laissé tel quel) */
function fiche_date_fr(string $d): string { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "$m[3]/$m[2]/$m[1]" : $d; }

/** Données de la fiche (clé => texte ; « X » pour les cases), tirées de la fiche enfant. Les clés sont celles de fiche_champs(). */
function fiche_valeurs(array $e, string $signataire): array {
    $j = fiche_json($e);
    $fr = fn($k) => trim((string)($e[$k] ?? ''));
    $jv = fn($k) => trim((string)($j[$k] ?? ''));
    $oui = fn($k) => fiche_yn_etat($k === 'antecedents' || $k === 'reco' ? $jv($k) : $fr($k)) === 'oui';
    $ts = $fr('date_naissance') !== '' ? strtotime($fr('date_naissance')) : false;
    $sexe = $fr('sexe');
    $unite = fn($k) => $fr($k) !== '' ? preg_replace('/[^0-9.,]/', '', $fr($k)) : '';
    $x = fn($c) => $c ? 'X' : '';

    $val = [];
    // vaccins (anciennes fiches : dtp / bcg / autre en texte libre)
    foreach (FICHE_VACCINS as $k => $lib) {
        $etat = $jv("v_$k"); $date = fiche_date_fr($jv("d_$k"));
        if ($etat === '' && $date === '') {
            $old = in_array($k, FICHE_VACCINS_OBLIG, true) ? $jv('dtp') : ($k === 'bcg' || $k === 'autre' ? $jv($k) : '');
            if ($old !== '') { $etat = fiche_est_negatif($old) ? 'non' : 'oui'; $date = $etat === 'oui' ? $old : ''; }
        }
        if (in_array($k, FICHE_VACCINS_OBLIG, true)) { $val["case_{$k}_oui"] = $x($etat === 'oui'); $val["case_{$k}_non"] = $x($etat === 'non'); }
        $txt = $etat === 'oui' ? ($date !== '' ? $date : 'Oui') : ($etat === 'non' && !in_array($k, FICHE_VACCINS_OBLIG, true) ? 'Non' : '');
        if ($k === 'autre' && $etat === 'oui' && $jv('autre_nom') !== '') $txt = $jv('autre_nom') . ' ' . ($date !== '' ? $date : '');
        $val["vacc_$k"] = trim($txt);
    }

    // allergies : catégories cochées par le parent, sinon déduites du texte
    $all = $fr('allergies'); $allOui = $oui('allergies'); $allRep = $all !== '';
    if (isset($j['all_alim']) || isset($j['all_med']) || isset($j['all_aut'])) {
        $alim = $allOui && !empty($j['all_alim']); $med = $allOui && !empty($j['all_med']); $aut = $allOui && !empty($j['all_aut']);
    } else {
        $alim = $allOui && preg_match('/alim|arachid|cacahu|noix|noisette|lait|œuf|oeuf|gluten|poisson|fruit|soja|s[eé]same|fraise|crustac|moutarde|lupin|c[eé]leri|lactose/iu', $all);
        $med = $allOui && preg_match('/m[eé]dic|p[eé]nicill|amoxi|antibio|aspirine|ibuprof|parac[eé]tamol|vaccin/iu', $all);
        $aut = $allOui && (preg_match('/pollen|animal|chat|chien|acarien|abeille|gu[eê]pe|piq[uû]re|latex|poussi[eè]re|graminée|moisissure|soleil/iu', $all) || (!$alim && !$med));
    }
    $santeRep = $fr('infos_sante') !== '' || $jv('antecedents') !== '';
    $santeOui = $oui('infos_sante') || $oui('antecedents');
    $detail = array_filter([$oui('infos_sante') ? $fr('infos_sante') : '', $oui('antecedents') ? 'Antécédents : ' . $jv('antecedents') : '']);
    $traitOui = $oui('traitements');
    $reco = array_filter([$oui('reco') ? $jv('reco') : '', $oui('regime_alimentaire') ? 'Régime : ' . $fr('regime_alimentaire') . '.' : '',
        $traitOui ? 'Traitement : ' . $fr('traitements') . '.' : '', $fr('commentaires')]);
    $adr = trim($fr('adresse') . ' ' . $fr('code_postal') . ' ' . $fr('ville'));
    $adr2 = $jv('resp2_adresse') !== '' ? $jv('resp2_adresse') : ($fr('resp2_nom') !== '' ? $adr : '');

    return $val + [
        'enfant_nom' => mb_strtoupper($fr('nom')), 'enfant_prenom' => $fr('prenom'),
        'naiss_j' => $ts ? date('d', $ts) : '', 'naiss_m' => $ts ? date('m', $ts) : '', 'naiss_a' => $ts ? date('Y', $ts) : '',
        'case_garcon' => $x($sexe === 'Homme'), 'case_fille' => $x($sexe === 'Femme'),
        'poids' => $unite('poids'), 'taille' => $unite('taille'),
        'case_trait_oui' => $x($traitOui), 'case_trait_non' => $x($fr('traitements') !== '' && !$traitOui),
        'case_all_alim_oui' => $x($alim), 'case_all_alim_non' => $x($allRep && !$alim),
        'case_all_med_oui' => $x($med), 'case_all_med_non' => $x($allRep && !$med),
        'case_all_aut_oui' => $x($aut), 'case_all_aut_non' => $x($allRep && !$aut),
        'allergies' => $allOui ? $all : '',
        'case_sante_oui' => $x($santeOui), 'case_sante_non' => $x($santeRep && !$santeOui),
        'sante_detail' => implode(' / ', $detail),
        'reco' => implode(' ', $reco),
        'resp1_nom' => mb_strtoupper($fr('resp1_nom')), 'resp1_prenom' => $fr('resp1_prenom'), 'resp1_adresse' => $adr,
        'resp1_tel' => $fr('resp1_tel'), 'resp1_tel_dom' => $jv('resp1_tel_dom'), 'resp1_tel_trav' => $jv('resp1_tel_trav'),
        'resp2_nom' => mb_strtoupper($fr('resp2_nom')), 'resp2_prenom' => $fr('resp2_prenom'), 'resp2_adresse' => $adr2,
        'resp2_tel' => $fr('resp2_tel'), 'resp2_tel_dom' => $jv('resp2_tel_dom'), 'resp2_tel_trav' => $jv('resp2_tel_trav'),
        'medecin' => trim($fr('medecin_nom') . '  ' . $fr('medecin_tel')),
        'signataire' => $signataire, 'date' => date('d/m/Y'),
        // utilisés seulement par le gabarit provisoire
        '_urgence' => trim($fr('urgence_nom') . ($fr('urgence_lien') !== '' ? ' (' . $fr('urgence_lien') . ')' : '') . '  ' . $fr('urgence_tel')),
        '_regime' => $fr('regime_alimentaire'), '_traitements' => $fr('traitements'), '_email' => $fr('resp1_email'),
    ];
}
