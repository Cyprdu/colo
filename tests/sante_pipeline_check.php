<?php
// Test léger du pipeline documents de santé : php -d extension=gd tests/sante_pipeline_check.php
define('HEALTH_KEY', str_repeat('ab', 32));
require __DIR__ . '/../api/sante.php';
require __DIR__ . '/../api/sante_convert.php';
require __DIR__ . '/../api/sante_pdf.php';
$ko = 0;
$t = function (string $n, bool $c) use (&$ko) { echo ($c ? 'OK  ' : 'KO  ') . $n . "\n"; if (!$c) $ko++; };

$im = imagecreatetruecolor(300, 200); imagefill($im, 0, 0, imagecolorallocate($im, 200, 60, 60));
ob_start(); imagepng($im); $png = ob_get_clean();
$jpg = sante_to_jpeg($png, 'image/png');
$t('PNG -> JPEG', strncmp($jpg, "\xFF\xD8", 2) === 0);
$pdf = sante_jpegs_pdf([$jpg, $jpg]);
$t('images -> PDF 2 pages', strncmp($pdf, '%PDF-1.4', 8) === 0 && substr_count($pdf, '/Type /Page ') === 2 && str_contains($pdf, '%%EOF'));
$t('xref coherent', (int)preg_match('/startxref\n(\d+)/', $pdf, $m) && substr($pdf, (int)$m[1], 4) === 'xref');
$t('chiffrement aller-retour', sante_decrypt(sante_encrypt($pdf)) === $pdf && sante_encrypt($pdf) !== $pdf);
$f = sante_pdf_fiche(['nom' => 'Dupont', 'prenom' => 'Léa', 'sexe' => 'Femme', 'date_naissance' => '2015-04-02', 'allergies' => 'Arachide',
    'vaccins_data' => '{"dtp":"01/2020","bcg":"Oui"}', 'resp1_nom' => 'Dupont', 'resp1_prenom' => 'Anne'], 'Anne Dupont');
$t('fiche generee (gabarit)', strncmp($f, '%PDF', 4) === 0 && !fiche_modele_actif());
$t('fichiers : liste vide', sante_files_list(['error' => UPLOAD_ERR_NO_FILE]) === []);
file_put_contents(sys_get_temp_dir() . '/cm_test.pdf', $pdf); file_put_contents(sys_get_temp_dir() . '/cm_fiche.pdf', $f);
exit($ko ? 1 : 0);
