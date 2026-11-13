<?php
// generate_pdf_certificat.php?r=<jeton> : reçu de paiement et confirmation d'inscription d'une COMMANDE
// (un séjour + une session, un ou plusieurs enfants). Toutes les valeurs viennent de la base ; la commande
// doit appartenir au parent connecté. Aucun identifiant numérique dans l'adresse.

require_once 'api/config.php';
require_once 'api/orders.php';
require_once 'api/finance_lib.php';
require 'libs/fpdf/fpdf.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user']['id'])) { header('Location: login.php?redirect=' . urlencode('reservations')); exit; }
$userId = (int)$_SESSION['user']['id'];

// Anciens liens (?inscription_id=N) : on retrouve le jeton de l'inscription si elle appartient au parent
$r = (string)($_GET['r'] ?? '');
if ($r === '' && isset($_GET['inscription_id'])) {
    $st = $pdo->prepare("SELECT i.reservation_token FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id WHERE i.id = ? AND e.parent_id = ?");
    $st->execute([(int)$_GET['inscription_id'], $userId]);
    $r = (string)$st->fetchColumn();
}
$members = [];
try { $members = loadOrderByToken($pdo, $r, $userId); } catch (Throwable $e) { error_log('pdf: ' . $e->getMessage()); }
if (!$members) { http_response_code(404); exit('Réservation introuvable.'); }

$first = $members[0];
$status = orderStatus($members);
$tot = orderTotals($members);
$ref = orderRef($members);
$bd = orderBreakdown($members);
$live = array_values(array_filter($members, function ($m) { return !isCancelled($m); }));
if (!$live) $live = $members;
$isPaid = $status[0] === 'paid';
$lieu = trim(implode(', ', array_filter([trim((string)$first['adresse']), trim($first['code_postal'] . ' ' . $first['ville'])])));
$mode = ['CARTE' => 'Carte bancaire', 'GRATUIT' => 'Gratuit'][$first['mode_paiement']] ?? 'Non précisé';

/** Texte UTF-8 -> Windows-1252 (accents, œ et € corrects dans les polices FPDF) */
function enc($s): string { return (string)iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', (string)$s); }
function eur(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }   // UTF-8 : passe par enc() avant d'être écrit

class RecuPDF extends FPDF {
    public $ref = '';
    function Header() {
        $this->SetFillColor(38, 52, 59);
        $this->Rect(0, 0, 210, 30, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 20);
        $this->SetXY(15, 9);
        $this->Cell(80, 10, 'ColoMap', 0, 0, 'L');
        $this->SetFont('Arial', '', 10);
        $this->SetXY(95, 11);
        $this->Cell(100, 8, enc('Réf. ' . $this->ref), 0, 0, 'R');
        $this->SetTextColor(38, 52, 59);
        $this->SetY(40);
    }
    function Footer() {
        $this->SetY(-14);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(120, 130, 138);
        $this->Cell(0, 8, enc('ColoMap · moncamp.fr · Page ' . $this->PageNo()), 0, 0, 'C');
    }
    function Section(string $title) {
        $this->Ln(4);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(107, 119, 128);
        $this->Cell(0, 7, enc(mb_strtoupper($title)), 0, 1, 'L');
        $this->SetDrawColor(229, 231, 235);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(3);
        $this->SetTextColor(38, 52, 59);
    }
    function Row(string $label, string $value, bool $bold = true) {
        $this->SetFont('Arial', '', 10.5);
        $this->SetTextColor(107, 119, 128);
        $this->Cell(48, 7, enc($label), 0, 0);
        $this->SetFont('Arial', $bold ? 'B' : '', 10.5);
        $this->SetTextColor(38, 52, 59);
        $this->MultiCell(0, 7, enc($value), 0, 'L');
    }
}

$pdf = new RecuPDF('P', 'mm', 'A4');
$pdf->ref = $ref;
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetTitle(enc(($isPaid ? 'Reçu de paiement ' : 'Confirmation d\'inscription ') . $ref));
$pdf->AddPage();

// Titre
$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(0, 10, enc($isPaid ? 'Reçu de paiement' : 'Confirmation d\'inscription'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10.5);
$pdf->SetTextColor(107, 119, 128);
$pdf->Cell(0, 6, enc('Émis le ' . date('d/m/Y') . ' · Réservation du ' . (new DateTime($first['date_inscription']))->format('d/m/Y')), 0, 1, 'L');
$pdf->SetTextColor(38, 52, 59);

// Séjour
$pdf->Section('Le séjour');
$pdf->Row('Séjour', $first['camp_nom']);
$pdf->Row('Dates', 'Du ' . $first['_start']->format('d/m/Y') . ' au ' . $first['_end']->format('d/m/Y') . ' (' . ($first['_nights'] + 1) . ' jours, ' . $first['_nights'] . ' nuit' . ($first['_nights'] > 1 ? 's' : '') . ')');
if ($lieu !== '') $pdf->Row('Lieu', $lieu);
$pdf->Row('Organisateur', (string)$first['orga_nom']);
if (!empty($first['orga_email'])) $pdf->Row('Contact', $first['orga_email'] . (!empty($first['orga_tel']) ? ' · ' . $first['orga_tel'] : ''), false);

// Participants et formules
$pdf->Section('Participants');
$pdf->SetFillColor(241, 243, 245);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(70, 8, enc('Participant'), 0, 0, 'L', true);
$pdf->Cell(65, 8, enc('Tarif'), 0, 0, 'L', true);
$pdf->Cell(45, 8, enc('Prix'), 0, 1, 'R', true);
$pdf->SetFont('Arial', '', 10.5);
foreach ($live as $m) {
    $ln = $bd['lines'][$m['inscription_id']] ?? ['label' => 'Prix du séjour', 'base' => (float)$m['prix_final'], 'remise' => 0, 'pct' => 0];
    $pdf->Cell(70, 9, enc($m['enfant_prenom'] . ' ' . $m['enfant_nom']), 'B', 0, 'L');
    $pdf->Cell(65, 9, enc($ln['label']), 'B', 0, 'L');
    $pdf->Cell(45, 9, enc(eur((float)$ln['base'])), 'B', 1, 'R');
    if ($ln['remise'] > 0.005) {   // la réduction est visible, sur sa propre ligne
        $pdf->SetFont('Arial', 'I', 9.5);
        $pdf->SetTextColor(90, 102, 110);
        $pdf->Cell(70, 7, '', 'B', 0, 'L');
        $pdf->Cell(65, 7, enc('Remise fratrie -' . $ln['pct'] . ' %'), 'B', 0, 'L');
        $pdf->Cell(45, 7, enc('- ' . eur((float)$ln['remise'])), 'B', 1, 'R');
        $pdf->SetFont('Arial', '', 10.5);
        $pdf->SetTextColor(38, 52, 59);
    }
}

// Paiement
$pdf->Section('Paiement');
if ($bd['fratrie'] > 0.005) {
    $pdf->Row('Sous-total', eur($bd['subtotal']), false);
    $pdf->Row('Remise fratrie', '- ' . eur($bd['fratrie']), false);
}
$pdf->Row('Total de la commande', eur($tot['due']));
if ($bd['promo'] > 0.005) $pdf->Row('Code promo', '- ' . eur($bd['promo']), false);
$pdf->Row($isPaid ? 'Montant réglé' : 'Déjà réglé', eur($tot['paid']));
if (!$isPaid) $pdf->Row('Reste à payer', eur(max(0, $tot['due'] - $tot['paid'])));
$pdf->Row('Moyen de paiement', $mode, false);
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 12);
if ($isPaid) $pdf->SetFillColor(229, 233, 236); else $pdf->SetFillColor(248, 245, 234);   // ardoise clair / crème : couleurs du site
$pdf->Cell(0, 11, enc('  Statut : ' . ($isPaid ? 'PAYÉ' : mb_strtoupper($status[1]))), 0, 1, 'L', true);

// Détail du prix payé (information : rien n'est ajouté au montant réglé)
$bk = $isPaid ? fin_order_breakdown($pdo, array_column($live, 'inscription_id')) : null;
if ($bk) {
    $c = function (int $cents): string { return eur($cents / 100); };
    $pdf->Section('Détail du prix payé');
    $pdf->SetFont('Arial', 'I', 9.5);
    $pdf->SetTextColor(90, 102, 110);
    $pdf->MultiCell(0, 5, enc("Vous avez payé " . $c($bk['paye']) . " TTC, aucun frais supplémentaire. Voici ce que contient ce prix :"), 0, 'L');
    $pdf->SetTextColor(38, 52, 59);
    $pdf->Ln(2);
    $pdf->Row('Prix payé (TTC)', $c($bk['paye']));
    $pdf->Row('dont commission ColoMap', $c($bk['commission']) . ' (' . number_format($bk['pct'], 1, ',', ' ') . ' %' . ($bk['fixe'] > 0 ? ' + ' . $c($bk['fixe']) . ' par inscription' : '') . ', frais de paiement par carte et de gestion inclus)', false);
    $pdf->Row('dont TVA', $bk['taux'] ? $c($bk['tva']) . ' (TVA ' . number_format($bk['taux'], 1, ',', ' ') . ' % incluse dans le prix)' : 'Non applicable (organisateur non assujetti)', false);
    $pdf->Row('Part de l\'organisateur', $c($bk['organisateur']) . ' (reversée à ' . $first['orga_nom'] . ')', false);
}

// Mention
$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetTextColor(120, 130, 138);
$pdf->MultiCell(0, 5, enc("Ce document a été généré automatiquement par ColoMap et fait foi de l'inscription des participants ci-dessus"
    . ($isPaid ? ' et du paiement enregistré.' : '. Le paiement n\'est pas encore finalisé.')
    . " Pour toute question, contactez l'organisateur du séjour ou ColoMap."), 0, 'L');

$pdf->Output('I', 'Recu_' . $ref . '.pdf');
