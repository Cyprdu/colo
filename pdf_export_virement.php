<?php
// pdf_export_virement.php?t=<jeton> : justificatif PDF d'un versement.
require_once 'api/config.php';
require_once 'api/finance_lib.php';
require_once 'libs/fpdf/fpdf.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']) || (empty($_SESSION['user']['is_directeur']) && empty($_SESSION['user']['is_admin']))) { http_response_code(403); exit; }

try { $rec = fin_receipt($pdo, (string)($_GET['t'] ?? ''), (int)$_SESSION['user']['id'], !empty($_SESSION['user']['is_admin'])); }
catch (Throwable $e) { $rec = null; }
if (!$rec) { http_response_code(404); exit('Versement introuvable.'); }
$v = $rec['v']; $connect = $rec['type'] === 'connect';

function eur($cents) { return number_format($cents / 100, 2, ',', ' ') . ' EUR'; }

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 9, fin_pdf_txt('Justificatif de versement'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 5, fin_pdf_txt('ColoMap - moncamp.fr'), 0, 1, 'L');
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, fin_pdf_txt($v['orga_nom']), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$adr = trim(($v['adresse_complete'] ?? '') . ' ' . ($v['code_postal_orga'] ?? '') . ' ' . ($v['ville_orga'] ?? ''));
if ($adr !== '') $pdf->MultiCell(0, 5, fin_pdf_txt($adr), 0, 'L');
$pdf->Cell(0, 5, fin_pdf_txt('Référence : ' . $v['token']), 0, 1, 'L');
$pdf->Cell(0, 5, fin_pdf_txt('Date : ' . date('d/m/Y H:i', strtotime($connect ? $v['created_at'] : $v['date_demande']))), 0, 1, 'L');
if ($connect) $pdf->Cell(0, 5, fin_pdf_txt('Identifiant Stripe : ' . ($v['stripe_transfer_id'] ?: 'en attente')), 0, 1, 'L');
$pdf->Ln(5);

if ($connect) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(230, 233, 236);
    $w = [62, 22, 16, 26, 26, 28];
    foreach (['Séjour', 'Départ', 'Inscr.', 'Brut', 'Commission', 'Net'] as $i => $h) $pdf->Cell($w[$i], 7, fin_pdf_txt($h), 1, 0, $i > 2 ? 'R' : 'L', true);
    $pdf->Ln();
    $pdf->SetFont('Arial', '', 9);
    $tb = $tc = $tn = 0;
    foreach ($rec['lignes'] as $l) {
        $net = $l['net_cents'] - $l['net_rembourse_cents']; $brut = $l['brut_cents'] - $l['rembourse_cents'];
        $com = $brut - $net;
        $tb += $brut; $tc += $com; $tn += $net;
        $pdf->Cell($w[0], 6, fin_pdf_txt(mb_substr($l['camp_nom'], 0, 34)), 1);
        $pdf->Cell($w[1], 6, date('d/m/Y', strtotime($l['date_debut'])), 1);
        $pdf->Cell($w[2], 6, '#' . $l['inscription_id'], 1);
        $pdf->Cell($w[3], 6, eur($brut), 1, 0, 'R');
        $pdf->Cell($w[4], 6, '- ' . eur($com), 1, 0, 'R');
        $pdf->Cell($w[5], 6, eur($net), 1, 1, 'R');
    }
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($w[0] + $w[1] + $w[2], 7, 'Total', 1);
    $pdf->Cell($w[3], 7, eur($tb), 1, 0, 'R');
    $pdf->Cell($w[4], 7, '- ' . eur($tc), 1, 0, 'R');
    $pdf->Cell($w[5], 7, eur($tn), 1, 1, 'R');
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, fin_pdf_txt('Montant versé à l\'organisme : ' . eur((int)$v['montant_cents'])), 0, 1, 'L');
    $pdf->SetFont('Arial', 'I', 9);
    if ((int)$v['montant_cents'] !== $tn) $pdf->MultiCell(0, 5, fin_pdf_txt('Le montant versé diffère du net des inscriptions : des points marketing ou des compensations de remboursements ont été déduits du solde.'), 0, 'L');
    $pdf->MultiCell(0, 5, fin_pdf_txt('Le versement est effectué vers le compte Stripe de l\'organisme, qui le verse ensuite sur son compte bancaire. Commission "tout compris" (frais de paiement inclus) : ' . number_format(fin_commission_pct(), 2, ',', ' ') . ' % + ' . fin_eur(fin_commission_fixe_cents()) . ' par inscription.'), 0, 'L');
} else {
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(80, 7, 'Montant brut', 1); $pdf->Cell(0, 7, eur((int)round($v['montant_total'] * 100)), 1, 1, 'R');
    $pdf->Cell(80, 7, fin_pdf_txt('Commission (' . number_format($v['commission_rate'], 2, ',', ' ') . ' %)'), 1); $pdf->Cell(0, 7, '- ' . eur((int)round(($v['montant_total'] - $v['montant_apres_commission']) * 100)), 1, 1, 'R');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(80, 8, 'Net', 1); $pdf->Cell(0, 8, eur((int)round($v['montant_apres_commission'] * 100)), 1, 1, 'R');
    $pdf->Ln(3);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, fin_pdf_txt('Statut : ' . ($v['effectue'] ? 'Effectué le ' . date('d/m/Y', strtotime($v['date_virement_effectue'])) : 'En attente') . ' (ancien système)'), 0, 1);
}
$pdf->Ln(6);
$pdf->SetFont('Arial', 'I', 8);
$pdf->MultiCell(0, 4, fin_pdf_txt('Document généré automatiquement par ColoMap. Il détaille les sommes encaissées pour le compte de l\'organisme et la commission de service prélevée.'), 0, 'L');
$pdf->Output('I', 'Justificatif_versement_' . substr($v['token'], 0, 8) . '.pdf');
exit;
