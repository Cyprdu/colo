<?php
// pdf_export_virements_history.php?o=<jeton organisme> : historique PDF des versements d'un organisme.
require_once 'api/config.php';
require_once 'api/finance_lib.php';
require_once 'libs/fpdf/fpdf.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user']['is_directeur'])) { http_response_code(403); exit; }

$orgaId = fin_orga_request($pdo);
try {
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT nom FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    $orga = $st->fetch(PDO::FETCH_ASSOC);
    if (!$orga) { http_response_code(404); exit('Organisme introuvable.'); }
    $st = $pdo->prepare("SELECT created_at AS d, montant_cents AS cents, statut, token, nb_paiements FROM fin_transferts WHERE organisateur_id = ? AND statut = 'envoye' ORDER BY created_at DESC");
    $st->execute([$orgaId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    try {
        $st = $pdo->prepare("SELECT COALESCE(date_virement_effectue, date_demande) AS d, ROUND(montant_apres_commission * 100) AS cents, IF(effectue, 'envoye', 'attente') AS statut, token, 0 AS nb_paiements FROM virements WHERE organisateur_id = ?");
        $st->execute([$orgaId]);
        $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { /* pas d'ancien système */ }
    usort($rows, function ($a, $b) { return strcmp($b['d'], $a['d']); });
} catch (Throwable $e) { http_response_code(500); exit('Erreur.'); }

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 9, fin_pdf_txt('Historique des versements'), 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, fin_pdf_txt($orga['nom'] . ' - édité le ' . date('d/m/Y')), 0, 1);
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(230, 233, 236);
$pdf->Cell(32, 7, 'Date', 1, 0, 'L', true); $pdf->Cell(60, 7, fin_pdf_txt('Référence'), 1, 0, 'L', true);
$pdf->Cell(24, 7, 'Inscr.', 1, 0, 'R', true); $pdf->Cell(36, 7, 'Montant', 1, 0, 'R', true); $pdf->Cell(28, 7, 'Statut', 1, 1, 'L', true);
$pdf->SetFont('Arial', '', 9);
$total = 0;
foreach ($rows as $r) {
    $pdf->Cell(32, 6, date('d/m/Y', strtotime($r['d'])), 1);
    $pdf->Cell(60, 6, substr($r['token'], 0, 16), 1);
    $pdf->Cell(24, 6, $r['nb_paiements'] ?: '-', 1, 0, 'R');
    $pdf->Cell(36, 6, number_format($r['cents'] / 100, 2, ',', ' ') . ' EUR', 1, 0, 'R');
    $pdf->Cell(28, 6, fin_pdf_txt($r['statut'] === 'envoye' ? 'Versé' : 'En attente'), 1, 1);
    if ($r['statut'] === 'envoye') $total += (int)$r['cents'];
}
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, fin_pdf_txt('Total versé : ' . number_format($total / 100, 2, ',', ' ') . ' EUR'), 0, 1);
$pdf->Output('I', 'Historique_versements.pdf');
exit;
