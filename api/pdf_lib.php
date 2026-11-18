<?php
// api/pdf_lib.php : documents imprimables générés en PDF (FPDF), A4, en-tête moncamp.fr + date d'édition, tableaux compacts.
require_once __DIR__ . '/../libs/fpdf/fpdf.php';

class MoncampPdf extends FPDF {
    public string $titre = '';
    public string $sousTitre = '';

    function __construct(string $titre, string $sousTitre = '', string $orientation = 'P') {
        parent::__construct($orientation, 'mm', 'A4');
        $this->titre = $titre; $this->sousTitre = $sousTitre;
        $this->SetMargins(12, 12, 12); $this->SetAutoPageBreak(true, 14); $this->AliasNbPages(); $this->AddPage();
    }
    /** UTF-8 -> encodage des polices FPDF */
    function t($s): string { return (string)iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', (string)$s); }

    function Header() {
        $this->SetFont('Arial', 'B', 13); $this->SetTextColor(38, 52, 59);
        $this->Cell(60, 6, 'moncamp.fr', 0, 0);
        $this->SetFont('Arial', '', 8); $this->SetTextColor(107, 119, 128);
        $this->Cell(0, 6, $this->t('Édité le ' . date('d/m/Y à H:i')), 0, 1, 'R');
        $this->SetFont('Arial', 'B', 11.5); $this->SetTextColor(38, 52, 59);
        $this->Cell(0, 6, $this->t($this->titre), 0, 1);
        if ($this->sousTitre !== '') { $this->SetFont('Arial', '', 8.5); $this->SetTextColor(107, 119, 128); $this->Cell(0, 4.5, $this->t($this->sousTitre), 0, 1); }
        $this->SetDrawColor(210, 216, 221); $this->Line($this->lMargin, $this->GetY() + 1.5, $this->w - $this->rMargin, $this->GetY() + 1.5);
        $this->Ln(4); $this->SetTextColor(38, 52, 59);
    }
    function Footer() {
        $this->SetY(-10); $this->SetFont('Arial', '', 7); $this->SetTextColor(140, 150, 156);
        $this->Cell(0, 4, $this->t('Document confidentiel · moncamp.fr · page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }

    /** Nombre de lignes qu'occupe $txt dans une cellule de largeur $w (police courante) */
    function nbLignes(float $w, string $txt): int {
        $max = $w - 2 * $this->cMargin; $n = 0;
        foreach (explode("\n", $txt) as $par) {
            $l = 1; $cur = '';
            foreach (explode(' ', $par) as $mot) {
                $try = $cur === '' ? $mot : $cur . ' ' . $mot;
                if ($this->GetStringWidth($try) <= $max || $cur === '') $cur = $try; else { $l++; $cur = $mot; }
                while ($this->GetStringWidth($cur) > $max && strlen($cur) > 1) { $l++; $cur = substr($cur, (int)(strlen($cur) * $max / max(1, $this->GetStringWidth($cur)))); }
            }
            $n += $l;
        }
        return max(1, $n);
    }

    function section(string $titre): void {
        if ($this->GetY() > $this->PageBreakTrigger - 20) $this->AddPage();
        $this->Ln(1.5); $this->SetFont('Arial', 'B', 8.5); $this->SetFillColor(238, 242, 244); $this->SetTextColor(38, 52, 59);
        $this->Cell(0, 5.5, $this->t(mb_strtoupper($titre)), 0, 1, 'L', true); $this->Ln(1);
    }

    /** Paires libellé => valeur sur $cols colonnes */
    function kv(array $pairs, int $cols = 2): void {
        $pairs = array_filter($pairs, fn($v) => $v !== null);
        $W = $this->w - $this->lMargin - $this->rMargin; $cw = $W / $cols; $lw = min(34, $cw * 0.38); $vw = $cw - $lw; $lh = 4;
        foreach (array_chunk($pairs, $cols, true) as $row) {
            $this->SetFont('Arial', '', 8);
            $h = $lh * max(array_map(fn($v) => $this->nbLignes($vw, $this->t(trim((string)$v) === '' ? '-' : $v)), $row));
            if ($this->GetY() + $h > $this->PageBreakTrigger) $this->AddPage();
            $y = $this->GetY(); $x = $this->lMargin;
            foreach ($row as $k => $v) {
                $this->SetXY($x, $y); $this->SetFont('Arial', '', 7.5); $this->SetTextColor(107, 119, 128); $this->MultiCell($lw, $lh, $this->t($k), 0, 'L');
                $this->SetXY($x + $lw, $y); $this->SetFont('Arial', 'B', 8); $this->SetTextColor(38, 52, 59); $this->MultiCell($vw, $lh, $this->t(trim((string)$v) === '' ? '-' : $v), 0, 'L');
                $x += $cw;
            }
            $this->SetY($y + $h + 0.6);
        }
    }

    /** Tableau compact : en-tête répété à chaque page, lignes zébrées, texte replié dans les cellules. $w en mm (complété à la largeur utile). */
    function table(array $entetes, array $lignes, array $w, array $align = []): void {
        $W = $this->w - $this->lMargin - $this->rMargin; $tot = array_sum($w);
        $w = array_map(fn($x) => $x * $W / $tot, $w); $lh = 3.8;
        $head = function () use ($entetes, $w) {
            $this->SetFont('Arial', 'B', 7.5); $this->SetFillColor(47, 61, 69); $this->SetTextColor(255, 255, 255);
            foreach ($entetes as $i => $h) $this->Cell($w[$i], 5.5, $this->t($h), 0, 0, 'L', true);
            $this->Ln(); $this->SetTextColor(38, 52, 59);
        };
        $head();
        foreach ($lignes as $n => $l) {
            $this->SetFont('Arial', '', 7.5);
            $cells = array_map(fn($v) => $this->t((string)$v), array_values($l));
            $h = $lh * max(array_map(fn($i) => $this->nbLignes($w[$i], $cells[$i] ?? ''), array_keys($w))) + 1.2;
            if ($this->GetY() + $h > $this->PageBreakTrigger) { $this->AddPage(); $head(); $this->SetFont('Arial', '', 7.5); }
            $y = $this->GetY(); $x = $this->lMargin;
            if ($n % 2) { $this->SetFillColor(246, 248, 249); $this->Rect($x, $y, $W, $h, 'F'); }
            foreach ($w as $i => $cw) { $this->SetXY($x, $y + 0.6); $this->MultiCell($cw, $lh, $cells[$i] ?? '', 0, $align[$i] ?? 'L'); $x += $cw; }
            $this->SetY($y + $h);
        }
        if (!$lignes) { $this->SetFont('Arial', 'I', 8); $this->Cell(0, 6, $this->t('Aucune ligne.'), 0, 1); }
        $this->Ln(2);
    }

    /** Envoie le PDF au navigateur (inline = ouvrir pour imprimer ; sinon téléchargement) puis termine */
    function envoyer(string $fichier, bool $inline = true): void {
        $fichier = preg_replace('/[^A-Za-z0-9_.-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $fichier)) ?: 'document.pdf';
        header('Content-Type: application/pdf'); header('Cache-Control: no-store');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fichier . '"');
        echo $this->Output('S'); exit;
    }
}

function pdf_date(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function pdf_eur($v): string { return number_format((float)$v, 2, ',', ' ') . ' €'; }
