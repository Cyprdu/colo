<?php
// api/sante_pdf.php : génère la fiche sanitaire de liaison (PDF) à partir de la fiche enfant. Retourne le contenu binaire.
require_once __DIR__ . '/../libs/fpdf/fpdf.php';

function sante_pdf_fiche(array $e, string $signataire): string {
    $t = fn($s) => iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', (string)$s);
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    $titre = function ($s) use ($pdf, $t) { $pdf->Ln(3); $pdf->SetFillColor(228, 232, 235); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 7, $t(' ' . $s), 0, 1, 'L', true); $pdf->SetFont('Arial', '', 10); $pdf->Ln(1); };
    $ligne = function ($k, $v) use ($pdf, $t) { $pdf->SetFont('Arial', 'B', 9.5); $pdf->Cell(52, 6, $t($k), 0, 0); $pdf->SetFont('Arial', '', 9.5); $pdf->MultiCell(0, 6, $t($v !== '' && $v !== null ? $v : '-'), 0, 'L'); };

    $pdf->SetFont('Arial', 'B', 15); $pdf->Cell(0, 9, $t('FICHE SANITAIRE DE LIAISON'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $t("Document confidentiel - arrêté du 20 février 2003 relatif au suivi sanitaire des mineurs en accueil collectif"), 0, 1, 'C');

    $dn = !empty($e['date_naissance']) ? date('d/m/Y', strtotime($e['date_naissance'])) : '';
    $titre("1. L'enfant");
    $ligne('Nom et prénom', mb_strtoupper((string)$e['nom']) . ' ' . $e['prenom']);
    $ligne('Date de naissance', $dn);
    $ligne('Sexe', ($e['sexe'] ?? '') === 'Femme' ? 'Fille' : (($e['sexe'] ?? '') === 'Homme' ? 'Garçon' : (string)($e['sexe'] ?? '')));
    $ligne('Taille / poids', trim(($e['taille'] ? $e['taille'] . ' cm' : '') . '  ' . ($e['poids'] ? $e['poids'] . ' kg' : '')));

    $v = json_decode((string)($e['vaccins_data'] ?? ''), true) ?: [];
    $titre('2. Vaccinations');
    $ligne('DTP (diphtérie, tétanos, polio)', (string)($v['dtp'] ?? ''));
    $ligne('BCG', (string)($v['bcg'] ?? ''));
    $ligne('Autres vaccins', (string)($v['autre'] ?? ''));

    $titre('3. Renseignements médicaux');
    $ligne('Allergies', (string)($e['allergies'] ?? ''));
    $ligne('Problèmes de santé', (string)($e['infos_sante'] ?? ''));
    $ligne('Traitement en cours', (string)($e['traitements'] ?? ''));
    $ligne('Régime alimentaire', (string)($e['regime_alimentaire'] ?? ''));
    $ligne('Médecin traitant', trim(($e['medecin_nom'] ?? '') . '  ' . ($e['medecin_tel'] ?? '')));

    $titre('4. Responsables légaux');
    $ligne('Responsable 1', trim(($e['resp1_prenom'] ?? '') . ' ' . ($e['resp1_nom'] ?? '')) . '  ' . ($e['resp1_tel'] ?? '') . '  ' . ($e['resp1_email'] ?? ''));
    if (!empty($e['resp2_nom'])) $ligne('Responsable 2', trim(($e['resp2_prenom'] ?? '') . ' ' . $e['resp2_nom']) . '  ' . ($e['resp2_tel'] ?? ''));
    $ligne('Adresse', trim(($e['adresse'] ?? '') . ' ' . ($e['code_postal'] ?? '') . ' ' . ($e['ville'] ?? '')));
    $ligne('Personne à prévenir', trim(($e['urgence_nom'] ?? '') . ' ' . ($e['urgence_lien'] ? '(' . $e['urgence_lien'] . ')' : '') . '  ' . ($e['urgence_tel'] ?? '')));

    $titre('5. Attestation');
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->MultiCell(0, 5.5, $t("Je soussigné(e) $signataire, responsable légal de l'enfant, certifie exacts les renseignements portés sur cette fiche et m'engage à les mettre à jour si nécessaire. J'autorise le responsable du séjour à prendre, le cas échéant, toutes mesures (traitement médical, hospitalisation) rendues nécessaires par l'état de l'enfant."));
    $pdf->Ln(3);
    $pdf->Cell(0, 6, $t('Fait le ' . date('d/m/Y') . ' - signature électronique de ' . $signataire . ' (case « Je certifie » cochée en ligne)'), 0, 1);
    return $pdf->Output('S');
}
