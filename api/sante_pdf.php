<?php
// api/sante_pdf.php : génère la fiche sanitaire de liaison (PDF, contenu binaire) à partir de la fiche enfant.
// Modèle officiel + FPDI présents et fiche_champs() renseigné -> superposition sur la vraie fiche ; sinon gabarit provisoire (FPDF).
require_once __DIR__ . '/../libs/fpdf/fpdf.php';
require_once __DIR__ . '/fiche_modele.php';

function fiche_fpdi_class(): ?string {
    if (!class_exists('\setasign\Fpdi\Fpdi') && is_file(__DIR__ . '/../libs/fpdi/src/autoload.php')) require_once __DIR__ . '/../libs/fpdi/src/autoload.php';
    return class_exists('\setasign\Fpdi\Fpdi') ? '\setasign\Fpdi\Fpdi' : null;
}
/** true si la fiche officielle sera utilisée (modèle + FPDI + coordonnées) */
function fiche_modele_actif(): bool { return is_file(FICHE_MODELE_PDF) && fiche_champs() && fiche_fpdi_class(); }

function sante_pdf_fiche(array $e, string $signataire): string {
    $val = fiche_valeurs($e, $signataire);
    $t = fn($s) => iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', (string)$s);
    return fiche_modele_actif() ? fiche_pdf_officielle($val, $t) : fiche_pdf_gabarit($val, $t);
}

/** Superposition des valeurs sur le PDF officiel */
function fiche_pdf_officielle(array $val, callable $t): string {
    $cls = fiche_fpdi_class(); $pdf = new $cls('P', 'mm', 'A4');
    $n = $pdf->setSourceFile(FICHE_MODELE_PDF); $champs = fiche_champs();
    for ($p = 1; $p <= $n; $p++) {
        $pdf->AddPage(); $pdf->useTemplate($pdf->importPage($p), 0, 0, null, null, true);
        foreach ($champs as $cle => [$page, $x, $y, $w, $size]) {
            if ($page !== $p || ($val[$cle] ?? '') === '') continue;
            $pdf->SetFont('Arial', '', $size); $pdf->SetXY($x, $y - $size * 0.3528);
            $pdf->Cell($w, $size * 0.3528 * 1.2, $t($val[$cle]), 0, 0, 'L');
        }
    }
    return $pdf->Output('S');
}

/** GABARIT PROVISOIRE : même structure que la fiche officielle, mise en page simple */
function fiche_pdf_gabarit(array $v, callable $t): string {
    $pdf = new FPDF('P', 'mm', 'A4'); $pdf->SetAutoPageBreak(true, 15); $pdf->AddPage();
    $titre = function ($s) use ($pdf, $t) { $pdf->Ln(3); $pdf->SetFillColor(228, 232, 235); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 7, $t(' ' . $s), 0, 1, 'L', true); $pdf->Ln(1); };
    $ligne = function ($k, $x) use ($pdf, $t) { $pdf->SetFont('Arial', 'B', 9.5); $pdf->Cell(52, 6, $t($k), 0, 0); $pdf->SetFont('Arial', '', 9.5); $pdf->MultiCell(0, 6, $t($x !== '' ? $x : '-'), 0, 'L'); };
    $pdf->SetFont('Arial', 'B', 15); $pdf->Cell(0, 9, $t('FICHE SANITAIRE DE LIAISON'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $t("Document confidentiel - suivi sanitaire des mineurs en accueil collectif (modèle provisoire)"), 0, 1, 'C');
    $titre("1. L'enfant");
    $ligne('Nom et prénom', trim($v['enfant_nom'] . ' ' . $v['enfant_prenom'])); $ligne('Date de naissance', $v['enfant_naissance']);
    $ligne('Sexe', $v['case_fille'] ? 'Fille' : ($v['case_garcon'] ? 'Garçon' : '')); $ligne('Taille / poids', trim($v['taille'] . '  ' . $v['poids']));
    $titre('2. Vaccinations');
    $ligne('DTP (diphtérie, tétanos, polio)', $v['vaccin_dtp']); $ligne('BCG', $v['vaccin_bcg']); $ligne('Autres vaccins', $v['vaccin_autre']);
    $titre('3. Renseignements médicaux');
    $ligne('Allergies', $v['allergies']); $ligne('Problèmes de santé', $v['infos_sante']); $ligne('Traitement en cours', $v['traitements']);
    $ligne('Régime alimentaire', $v['regime']); $ligne('Médecin traitant', trim($v['medecin_nom'] . '  ' . $v['medecin_tel']));
    $titre('4. Responsables légaux');
    $ligne('Responsable 1', trim($v['resp1_nom_prenom'] . '  ' . $v['resp1_tel'] . '  ' . $v['resp1_email']));
    if ($v['resp2_nom_prenom'] !== '') $ligne('Responsable 2', trim($v['resp2_nom_prenom'] . '  ' . $v['resp2_tel']));
    $ligne('Adresse', $v['adresse']); $ligne('Personne à prévenir', $v['urgence']);
    $titre('5. Attestation');
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->MultiCell(0, 5.5, $t("Je soussigné(e) {$v['signataire']}, responsable légal de l'enfant, certifie exacts les renseignements portés sur cette fiche et m'engage à les mettre à jour si nécessaire. J'autorise le responsable du séjour à prendre, le cas échéant, toutes mesures (traitement médical, hospitalisation) rendues nécessaires par l'état de l'enfant."));
    $pdf->Ln(3); $pdf->Cell(0, 6, $t("Fait le {$v['date']} - signature électronique de {$v['signataire']} (case « Je certifie » cochée en ligne)"), 0, 1);
    return $pdf->Output('S');
}
