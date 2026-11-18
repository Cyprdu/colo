<?php
// recu_dette.php?o=<jeton organisme>&r=<session Stripe> : reçu PDF d'un règlement de dette par carte (propriétaire ou admin).
require_once 'api/config.php';
require_once 'api/finance_lib.php';
require_once 'libs/fpdf/fpdf.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id'])) { header('Location: login.php'); exit; }
$orgaId = fin_orga_request($pdo);
$ref = 'dettecb:' . preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['r'] ?? ''));
$st = $pdo->prepare("SELECT reference, montant_cents, created_at FROM fin_ledger WHERE organisateur_id = ? AND reference IN (?, ?)");
$st->execute([$orgaId, $ref, $ref . ':surplus']);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) fin_orga_404();
$solde = 0; $surplus = 0; $date = $rows[0]['created_at'];
foreach ($rows as $r) { if ($r['reference'] === $ref) $solde = -(int)$r['montant_cents']; else $surplus = (int)$r['montant_cents']; }
$nom = $pdo->query("SELECT nom FROM organisateurs WHERE id = " . (int)$orgaId)->fetchColumn();
$eur = fn(int $c) => number_format($c / 100, 2, ',', ' ') . ' EUR';
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(18, 18, 18);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 9, fin_pdf_txt('Reçu - règlement de dette'), 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, fin_pdf_txt('moncamp.fr · ' . date('d/m/Y H:i', strtotime($date))), 0, 1);
$pdf->Ln(6);
foreach ([['Organisme', html_entity_decode((string)$nom, ENT_QUOTES, 'UTF-8')], ['Montant payé par carte', $eur($solde + $surplus)], ['Dette soldée', $eur($solde)],
          ['Excédent crédité au solde', $eur($surplus)], ['Référence', substr($ref, 8)]] as [$k, $v]) {
    $pdf->SetFont('Arial', '', 11); $pdf->Cell(70, 8, fin_pdf_txt($k), 'B');
    $pdf->SetFont('Arial', 'B', 11); $pdf->Cell(0, 8, fin_pdf_txt($v), 'B', 1, 'R');
}
$pdf->Ln(6);
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 5, fin_pdf_txt("Ce montant règle la somme avancée par moncamp.fr lors d'un remboursement à une famille. Il n'est soumis à aucune commission."));
$pdf->Output('I', 'Recu_dette_moncamp.pdf');
exit;
