<?php
// api/sante_convert.php : import de documents de santé (PDF ou images) -> toujours stockés en PDF, chiffrés (sante_store).
// Images acceptées : JPEG, PNG, WebP (GD ou Imagick) et HEIC/HEIF (Imagick avec décodeur HEIC uniquement).
require_once __DIR__ . '/sante.php';

/** Normalise une entrée $_FILES (un fichier ou plusieurs « name[] ») en liste de fichiers simples */
function sante_files_list(array $f): array {
    if (!isset($f['error'])) return [];
    if (!is_array($f['error'])) return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    $out = [];
    foreach ($f['error'] as $i => $err) {
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $err, 'size' => $f['size'][$i]];
    }
    return $out;
}

/** Image (octets) -> JPEG propre (EXIF appliqué puis supprimé), côté max 2000 px. GD, sinon Imagick. */
function sante_to_jpeg(string $bytes, string $mime): string {
    $heic = in_array($mime, ['image/heic', 'image/heif'], true);
    if (!$heic && function_exists('imagecreatefromstring')) return sante_image_jpeg($bytes, 2000, 0);
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick(); $im->readImageBlob($bytes); $im->setIteratorIndex(0);
            if (method_exists($im, 'autoOrient')) $im->autoOrient();
            $im->setImageBackgroundColor('white'); $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $im->thumbnailImage(2000, 2000, true); $im->stripImage();
            $im->setImageFormat('jpeg'); $im->setImageCompressionQuality(85);
            return $im->getImageBlob();
        } catch (Throwable $e) { /* tombe sur le message ci-dessous */ }
    }
    throw new Exception($heic ? "Le format HEIC n'est pas pris en charge ici : exportez la photo en JPEG puis réessayez." : "Traitement d'image indisponible sur le serveur.");
}

/** JPEG(s) RVB -> PDF A4 (une image par page, ajustée avec marges). Sans dépendance. */
function sante_jpegs_pdf(array $jpegs): string {
    $o = []; $kids = []; $n = count($jpegs);
    foreach ($jpegs as $i => $j) {
        [$w, $h] = getimagesizefromstring($j);
        $p = 3 + 3 * $i; $kids[] = "$p 0 R";
        $s = min(535 / $w, 782 / $h); $dw = $w * $s; $dh = $h * $s;
        $stream = sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im0 Do Q", $dw, $dh, (595 - $dw) / 2, (842 - $dh) / 2);
        $o[$p] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 " . ($p + 2) . " 0 R >> >> /Contents " . ($p + 1) . " 0 R >>";
        $o[$p + 1] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream";
        $o[$p + 2] = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($j) . " >>\nstream\n$j\nendstream";
    }
    $o[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $o[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";
    ksort($o);
    $pdf = "%PDF-1.4\n"; $off = [];
    foreach ($o as $id => $body) { $off[$id] = strlen($pdf); $pdf .= "$id 0 obj\n$body\nendobj\n"; }
    $x = strlen($pdf); $max = max(array_keys($o));
    $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $max; $id++) $pdf .= sprintf("%010d 00000 n \n", $off[$id]);
    return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
}

/**
 * Valide les fichiers envoyés (type RÉEL par finfo, 8 Mo chacun, 10 max) et stocke un PDF chiffré.
 * Un PDF seul, OU une ou plusieurs images (une page par image). Retourne le nom stocké.
 */
function sante_save_as_pdf(array $f, string $dir): string {
    $files = sante_files_list($f);
    if (!$files) throw new Exception("Choisissez un fichier.");
    if (count($files) > 10) throw new Exception("10 fichiers maximum.");
    $pdf = null; $jpegs = [];
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    foreach ($files as $x) {
        if ($x['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($x['tmp_name'])) throw new Exception("L'envoi du fichier a échoué (taille ou format).");
        if ($x['size'] > SANTE_MAX_DOC || $x['size'] < 100) throw new Exception("Fichier vide ou trop lourd (" . (int)(SANTE_MAX_DOC / 1048576) . " Mo maximum).");
        $bytes = (string)file_get_contents($x['tmp_name']);
        $mime = (string)finfo_buffer($fi, $bytes);
        if ($mime === 'application/pdf') {
            if ($pdf !== null || $jpegs) throw new Exception("Envoyez un seul PDF, ou plusieurs images.");
            if (strncmp($bytes, '%PDF-', 5) !== 0 || preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b#', $bytes)) throw new Exception("Ce PDF n'est pas accepté (contenu actif).");
            $pdf = $bytes;
        } elseif (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'], true)) {
            if ($pdf !== null) throw new Exception("Envoyez un seul PDF, ou plusieurs images.");
            $jpegs[] = sante_to_jpeg($bytes, $mime);
        } else throw new Exception("Format non accepté : PDF, JPG, PNG, WebP ou HEIC.");
    }
    finfo_close($fi);
    return sante_store($dir, $pdf ?? sante_jpegs_pdf($jpegs), 'pdf');
}
