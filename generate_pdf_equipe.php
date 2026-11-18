<?php
// generate_pdf_equipe.php?t=<jeton d'inscription> : reçu d'inscription d'un animateur (équipe d'animation, option B).
// Accès : l'animateur concerné, le directeur du séjour, un admin. Montants issus de la base, jamais du navigateur.
require_once 'api/config.php';
require_once __DIR__ . '/api/adm_core.php';
require_once 'api/animateurs.php';
require_once 'api/equipe.php';
require 'libs/fpdf/fpdf.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$uid = (int)($_SESSION['user']['id'] ?? 0);
if (!$uid) { header('Location: login.php?redirect=' . urlencode('reservations')); exit; }
if (sec_blocked($pdo, 'equipe_pdf', (string)$uid, 30, 10)) { http_response_code(429); exit('Trop de demandes. Réessayez dans quelques minutes.'); }
sec_hit($pdo, 'equipe_pdf', (string)$uid);
$acc = equipe_acces($pdo, (string)($_GET['t'] ?? ''), $uid, adm_can('camps.read'));
if (!$acc) { http_response_code(404); exit('Inscription introuvable.'); }
[$e, $camp] = $acc;
if ($e['statut'] === 'Annulé' || !in_array($e['statut_paiement'], ['PAYE', 'GRATUIT'], true)) { http_response_code(409); exit('Aucun reçu : inscription non confirmée.'); }
sec_trace($pdo, $uid, 'equipe_recu', (int)$e['id']);

function enc($s): string { return (string)iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', html_entity_decode((string)$s)); }
function eur(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }
$ref = 'EQ-' . str_pad((string)$e['id'], 6, '0', STR_PAD_LEFT);

class RecuEquipe extends FPDF {
    public $ref = '';
    function Header() {
        $this->SetFillColor(38, 52, 59); $this->Rect(0, 0, 210, 30, 'F');
        $this->SetTextColor(255, 255, 255); $this->SetFont('Arial', 'B', 20); $this->SetXY(15, 9); $this->Cell(80, 10, 'moncamp.fr', 0, 0, 'L');
        $this->SetFont('Arial', '', 10); $this->SetXY(95, 11); $this->Cell(100, 8, enc('Réf. ' . $this->ref), 0, 0, 'R');
        $this->SetTextColor(38, 52, 59); $this->SetY(40);
    }
    function Footer() {
        $this->SetY(-14); $this->SetFont('Arial', 'I', 8); $this->SetTextColor(120, 130, 138);
        $this->Cell(0, 8, enc('moncamp.fr · moncamp.fr · Page ' . $this->PageNo()), 0, 0, 'C');
    }
    function Section(string $t) {
        $this->Ln(4); $this->SetFont('Arial', 'B', 11); $this->SetTextColor(107, 119, 128); $this->Cell(0, 7, enc(mb_strtoupper($t)), 0, 1);
        $this->SetDrawColor(229, 231, 235); $this->Line(15, $this->GetY(), 195, $this->GetY()); $this->Ln(3); $this->SetTextColor(38, 52, 59);
    }
    function Row(string $l, string $v) {
        $this->SetFont('Arial', '', 10.5); $this->SetTextColor(107, 119, 128); $this->Cell(55, 7, enc($l), 0, 0);
        $this->SetFont('Arial', 'B', 10.5); $this->SetTextColor(38, 52, 59); $this->MultiCell(0, 7, enc($v), 0, 'L');
    }
}

$lieu = trim(implode(', ', array_filter([trim((string)$camp['adresse']), trim($camp['code_postal'] . ' ' . $camp['ville'])])));
$pdf = new RecuEquipe('P', 'mm', 'A4'); $pdf->ref = $ref;
$pdf->SetMargins(15, 15, 15); $pdf->SetAutoPageBreak(true, 20);
$pdf->SetTitle(enc('Reçu d\'inscription animateur ' . $ref)); $pdf->AddPage();
$pdf->SetFont('Arial', 'B', 18); $pdf->Cell(0, 10, enc('Reçu d\'inscription · équipe d\'animation'), 0, 1);
$pdf->SetFont('Arial', '', 10.5); $pdf->SetTextColor(107, 119, 128);
$pdf->Cell(0, 6, enc('Émis le ' . date('d/m/Y') . ' · Inscription du ' . date('d/m/Y', strtotime($e['created_at']))), 0, 1);
$pdf->SetTextColor(38, 52, 59);

$pdf->Section('Animateur');
$pdf->Row('Nom', $e['prenom'] . ' ' . $e['nom']);
$pdf->Row('E-mail', $e['email']);

$pdf->Section('Le séjour');
$pdf->Row('Séjour', $camp['nom']);
$pdf->Row('Dates', 'Du ' . date('d/m/Y', strtotime($camp['date_debut'])) . ' au ' . date('d/m/Y', strtotime($camp['date_fin'])));
if ($lieu !== '') $pdf->Row('Lieu', $lieu);
$pdf->Row('Organisateur', (string)$camp['orga_nom']);

$pdf->Section('Participation');
$pdf->Row('Tarif', $e['tarif_nom'] ?: 'Participation gratuite');
$pdf->Row($e['statut_paiement'] === 'PAYE' ? 'Montant réglé' : 'Montant', (float)$e['prix_final'] > 0 ? eur((float)$e['prix_final']) : 'Gratuit');
if ($e['statut_paiement'] === 'PAYE') $pdf->Row('Moyen de paiement', 'Carte bancaire');
$pdf->Ln(6); $pdf->SetFont('Arial', 'I', 9); $pdf->SetTextColor(107, 119, 128);
$pdf->MultiCell(0, 5, enc('Ce document atteste de votre inscription à l\'équipe d\'animation du séjour. Le paiement est encaissé pour le compte de l\'organisateur par moncamp.fr.'), 0, 'L');

$pdf->Output('I', 'recu-animateur-' . $ref . '.pdf');
