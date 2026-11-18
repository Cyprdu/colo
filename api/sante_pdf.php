<?php
// api/sante_pdf.php : génère la fiche sanitaire de liaison (PDF, contenu binaire) à partir de la fiche enfant.
// Fond officiel CERFA 10008*02 (libs/modele/cerfa-10008-02.jpg) + valeurs + signature manuscrite ; gabarit provisoire si le fond manque.
require_once __DIR__ . '/../libs/fpdf/fpdf.php';
require_once __DIR__ . '/fiche_modele.php';

function fiche_modele_actif(): bool { return is_file(FICHE_MODELE_IMG) && fiche_champs(); }

/** $signature : octets JPEG du tracé manuscrit du parent (ou null) */
function sante_pdf_fiche(array $e, string $signataire, ?string $signature = null): string {
    $val = fiche_valeurs($e, $signataire);
    $t = fn($s) => iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', (string)$s);
    return fiche_modele_actif() ? fiche_pdf_officielle($val, $t, $signature) : fiche_pdf_gabarit($val, $t);
}

/** Écrit les valeurs sur la fiche officielle */
function fiche_pdf_officielle(array $val, callable $t, ?string $signature): string {
    [$W, $H] = FICHE_PAGE_MM;
    $pdf = new FPDF('L', 'mm', [$H, $W]);
    $pdf->SetAutoPageBreak(false); $pdf->SetMargins(0, 0, 0); $pdf->AddPage();
    $pdf->Image(FICHE_MODELE_IMG, 0, 0, $W, $H);
    $pdf->SetTextColor(20, 30, 90);   // encre bleue, lisible sur la fiche
    foreach (fiche_champs() as $cle => $c) {
        if ($cle === 'signature') continue;
        $v = (string)($val[$cle] ?? '');
        if ($v === '') continue;
        if (strpos($cle, 'case_') === 0) {   // coche
            $pdf->SetFont('Arial', 'B', 9); $pdf->SetXY($c[1] - 2.5, $c[2] - 2.5); $pdf->Cell(5, 5, 'X', 0, 0, 'C');
            continue;
        }
        [, $x, $y, $w, $size] = $c; $txt = $t($v);
        if (isset($c[6])) {   // multi-lignes : retour à la ligne par mots, dernière ligne tronquée
            $pdf->SetFont('Arial', '', $size);
            $lignes = []; $cur = '';
            foreach (preg_split('/\s+/', $txt) as $mot) {
                $try = $cur === '' ? $mot : $cur . ' ' . $mot;
                if ($pdf->GetStringWidth($try) <= $w || $cur === '') $cur = $try; else { $lignes[] = $cur; $cur = $mot; }
            }
            if ($cur !== '') $lignes[] = $cur;
            foreach (array_slice($lignes, 0, $c[6]) as $i => $l) {
                if ($i === $c[6] - 1 && count($lignes) > $c[6]) $l = rtrim($l, ' .,') . '...';
                $pdf->SetXY($x, $y + $i * $c[5] - $size * 0.3528 + 1.5); $pdf->Cell($w, $size * 0.3528, $l, 0, 0, 'L');
            }
            continue;
        }
        while ($size > 5.5) { $pdf->SetFont('Arial', '', $size); if ($pdf->GetStringWidth($txt) <= $w) break; $size -= 0.5; }   // réduit pour tenir
        $pdf->SetXY($x, $y - $size * 0.3528 * 0.8); $pdf->Cell($w, $size * 0.3528, $txt, 0, 0, 'L');
    }
    if ($signature !== null && isset(fiche_champs()['signature'])) {
        [, $x, $y, $w, $h] = fiche_champs()['signature'];
        $tmp = tempnam(sys_get_temp_dir(), 'sig');   // FPDF lit un fichier ; supprimé aussitôt
        try { file_put_contents($tmp, $signature); $pdf->Image($tmp, $x, $y, $w, $h, 'JPG'); } finally { @unlink($tmp); }
    }
    $pdf->SetFont('Arial', 'I', 6); $pdf->SetTextColor(120, 120, 120); $pdf->SetXY(165, $H - 6);
    $pdf->Cell(125, 3, $t('Signée en ligne sur moncamp.fr le ' . $val['date'] . ' par ' . $val['signataire']), 0, 0, 'R');
    return $pdf->Output('S');
}

/** GABARIT PROVISOIRE (utilisé seulement si le fond officiel est absent) */
function fiche_pdf_gabarit(array $v, callable $t): string {
    $pdf = new FPDF('P', 'mm', 'A4'); $pdf->SetAutoPageBreak(true, 15); $pdf->AddPage();
    $ligne = function ($k, $x) use ($pdf, $t) { $pdf->SetFont('Arial', 'B', 9.5); $pdf->Cell(52, 6, $t($k), 0, 0); $pdf->SetFont('Arial', '', 9.5); $pdf->MultiCell(0, 6, $t($x !== '' ? $x : '-'), 0, 'L'); };
    $pdf->SetFont('Arial', 'B', 15); $pdf->Cell(0, 9, $t('FICHE SANITAIRE DE LIAISON (modèle provisoire)'), 0, 1, 'C');
    $ligne('Nom et prénom', trim($v['enfant_nom'] . ' ' . $v['enfant_prenom'])); $ligne('Naissance', $v['naiss_j'] . '/' . $v['naiss_m'] . '/' . $v['naiss_a']);
    $ligne('Vaccins DTP / BCG / autres', trim($v['vacc_dip'] . ' / ' . $v['vacc_bcg'] . ' / ' . $v['vacc_autre']));
    $ligne('Allergies', $v['allergies']); $ligne('Problèmes de santé', $v['sante_detail']); $ligne('Traitement', $v['_traitements']);
    $ligne('Responsable 1', trim($v['resp1_prenom'] . ' ' . $v['resp1_nom'] . '  ' . $v['resp1_tel'] . '  ' . $v['_email'])); $ligne('Adresse', $v['resp1_adresse']);
    $ligne('Médecin', $v['medecin']); $ligne('Personne à prévenir', $v['_urgence']);
    $ligne('Signataire', $v['signataire'] . ' le ' . $v['date']);
    return $pdf->Output('S');
}
