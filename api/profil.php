<?php
// api/profil.php : colonnes de profil ajoutées automatiquement + photo de profil sécurisée.

function profil_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['date_naissance' => 'DATE NULL', 'telephone' => 'VARCHAR(30) NULL', 'newsletter' => 'TINYINT(1) NOT NULL DEFAULT 0', 'photo_url' => 'VARCHAR(255) NULL'] as $c => $def) {
            if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $c $def");
        }
    } catch (Throwable $e) {
        error_log('profil_ensure: ' . $e->getMessage());
    }
}

/**
 * Enregistre une photo envoyée ($_FILES[...]) : vérifie taille et type RÉEL, ré-encode en JPEG carré 400 px
 * (supprime métadonnées et code éventuel), nom aléatoire. Retourne l'URL publique, ou lève une Exception (message affichable).
 */
function profil_save_photo(array $f): string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new Exception("L'envoi de la photo a échoué.");
    if ($f['size'] > 5 * 1024 * 1024) throw new Exception("Photo trop lourde (5 Mo maximum).");
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new Exception("Format non accepté : JPEG, PNG ou WebP.");
    if ($info[0] * $info[1] > 40000000) throw new Exception("Image trop grande.");
    if (!function_exists('imagecreatefromstring')) throw new Exception("Traitement d'image indisponible sur le serveur.");
    $src = @imagecreatefromstring(file_get_contents($f['tmp_name']));
    if (!$src) throw new Exception("Image illisible.");
    $w = imagesx($src); $h = imagesy($src); $s = min($w, $h);
    $dst = imagecreatetruecolor(400, 400);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $s) / 2), (int)(($h - $s) / 2), 400, 400, $s, $s);
    $dir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new Exception("Dossier d'envoi indisponible.");
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $ok = imagejpeg($dst, $dir . $name, 85);
    imagedestroy($src); imagedestroy($dst);
    if (!$ok) throw new Exception("Impossible d'enregistrer la photo.");
    return '/uploads/avatars/' . $name;
}

/** Supprime l'ancienne photo si elle est hébergée chez nous (jamais une URL externe type Google) */
function profil_delete_photo(?string $url): void {
    if ($url && preg_match('#^/uploads/avatars/[a-f0-9]{32}\.jpg$#', $url)) @unlink(__DIR__ . '/..' . $url);
}
