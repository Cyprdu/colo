<?php
// api/sante.php : fiche enfant, documents de santé et photo : stockage chiffré, validation des fichiers, complétude.
//
// SÉCURITÉ DES FICHIERS
//  - Les fichiers vivent dans uploads/{sante,sanitaire,signatures,photos}/ sous un nom aléatoire (64 hex + extension).
//    Ces dossiers sont fermés au web (.htaccess racine + .htaccess local) : le SEUL chemin de lecture est api/secure_file.php
//    (parent propriétaire, directeur d'un séjour où l'enfant est inscrit, administrateur ; chaque ouverture est journalisée).
//  - Chiffrement au repos AES-256-GCM si la constante HEALTH_KEY est définie dans api/config.php.
//      Générer la clé (une seule fois, à garder à part des sauvegardes de fichiers) :
//          php -r "echo bin2hex(random_bytes(32));"
//      puis ajouter dans api/config.php :   define('HEALTH_KEY', '<les 64 caractères>');
//    Sans clé, les fichiers sont stockés en clair (repli) ; ceux déjà chiffrés restent illisibles tant que la clé manque.
//    ATTENTION : perdre la clé = perdre les documents chiffrés. Ne jamais la changer sans les ré-encoder.
//  - Type réel vérifié (finfo), taille limitée, images ré-encodées (métadonnées EXIF supprimées), PDF sans script.

const SANTE_MAX_DOC = 8 * 1024 * 1024;
const SANTE_MAGIC = 'CMENC1';
const SANTE_DIRS = ['sante' => 'carnet_sante_token', 'sanitaire' => 'fiche_sanitaire_token', 'signatures' => 'signature_token', 'photos' => 'photo_token'];

function sante_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM enfants")->fetchAll(PDO::FETCH_COLUMN);
        $add = ['photo_token' => 'VARCHAR(80) NULL', 'traitements' => 'TEXT NULL', 'urgence_nom' => 'VARCHAR(120) NULL', 'urgence_tel' => 'VARCHAR(30) NULL',
                'urgence_lien' => 'VARCHAR(60) NULL', 'sante_consent_at' => 'DATETIME NULL', 'fiche_origine' => 'VARCHAR(10) NULL',
                'allergies' => 'TEXT NULL', 'poids' => 'VARCHAR(20) NULL', 'taille' => 'VARCHAR(20) NULL'];
        foreach ($add as $c => $def) if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE enfants ADD COLUMN $c $def");
    } catch (Throwable $e) { error_log('sante_ensure: ' . $e->getMessage()); }
}

// ---------------- chiffrement ----------------
function sante_key(): ?string {
    if (!defined('HEALTH_KEY') || HEALTH_KEY === '') return null;
    $k = HEALTH_KEY;
    if (preg_match('/^[a-f0-9]{64}$/i', $k)) return hex2bin($k);
    $raw = base64_decode($k, true);
    return ($raw !== false && strlen($raw) === 32) ? $raw : hash('sha256', $k, true);
}
function sante_encrypt(string $plain): string {
    $key = sante_key();
    if ($key === null || !function_exists('openssl_encrypt')) return $plain;   // repli : clair
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) throw new Exception("Chiffrement impossible.");
    return SANTE_MAGIC . $iv . $tag . $ct;
}
function sante_decrypt(string $data): string {
    if (strncmp($data, SANTE_MAGIC, strlen(SANTE_MAGIC)) !== 0) return $data;   // fichier ancien, non chiffré
    $key = sante_key();
    if ($key === null) throw new Exception("Clé de chiffrement absente.");
    $o = strlen(SANTE_MAGIC);
    $plain = openssl_decrypt(substr($data, $o + 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($data, $o, 12), substr($data, $o + 12, 16));
    if ($plain === false) throw new Exception("Déchiffrement impossible.");
    return $plain;
}

// ---------------- fichiers ----------------
function sante_dir(string $dir): string {
    if (!isset(SANTE_DIRS[$dir])) throw new Exception('Dossier inconnu.');
    $p = dirname(__DIR__) . '/uploads/' . $dir . '/';
    if (!is_dir($p) && !mkdir($p, 0750, true)) throw new Exception("Dossier de stockage indisponible.");
    if (!is_file($p . '.htaccess')) @file_put_contents($p . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n");
    if (!is_file($p . 'index.html')) @file_put_contents($p . 'index.html', '');
    return $p;
}
function sante_valid_name(?string $n): bool { return (bool)preg_match('/^[a-f0-9]{64}\.(pdf|jpg|png)$/', (string)$n); }

/** Écrit (chiffré) et retourne le nom aléatoire */
function sante_store(string $dir, string $bytes, string $ext): string {
    $p = sante_dir($dir);
    $name = bin2hex(random_bytes(32)) . '.' . $ext;
    if (file_put_contents($p . $name, sante_encrypt($bytes), LOCK_EX) === false) throw new Exception("Impossible d'enregistrer le fichier.");
    @chmod($p . $name, 0640);
    return $name;
}
function sante_read(string $dir, string $name): string {
    if (!sante_valid_name($name) && !preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $name)) throw new Exception('Nom invalide.');
    return sante_decrypt((string)file_get_contents(sante_dir($dir) . $name));
}
function sante_unlink(string $dir, ?string $name): void {
    if ($name && isset(SANTE_DIRS[$dir]) && preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $name)) @unlink(dirname(__DIR__) . '/uploads/' . $dir . '/' . $name);
}

/** Image -> JPEG propre (EXIF supprimé). $carre > 0 : recadrage carré centré à cette taille. */
function sante_image_jpeg(string $bytes, int $max = 2000, int $carre = 0): string {
    if (!function_exists('imagecreatefromstring')) throw new Exception("Traitement d'image indisponible.");
    $src = @imagecreatefromstring($bytes);
    if (!$src) throw new Exception("Image illisible.");
    // Photos de téléphone : applique l'orientation EXIF avant de supprimer les métadonnées
    if (function_exists('exif_read_data') && strncmp($bytes, "ÿØ", 2) === 0) {
        $o = (int)(@exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes))['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg && ($rot = imagerotate($src, $deg, 0))) { imagedestroy($src); $src = $rot; }
    }
    $w = imagesx($src); $h = imagesy($src);
    if ($carre > 0) { $s = min($w, $h); $dst = imagecreatetruecolor($carre, $carre); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, (int)(($w - $s) / 2), (int)(($h - $s) / 2), $carre, $carre, $s, $s); }
    else { $r = min(1, $max / max($w, $h)); $nw = max(1, (int)($w * $r)); $nh = max(1, (int)($h * $r));
        $dst = imagecreatetruecolor($nw, $nh); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h); }
    ob_start(); imagejpeg($dst, null, 85); $out = (string)ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return $out;
}

/**
 * Valide et enregistre un fichier envoyé. $mode : 'photo' (image seule, carré 400) | 'doc' (PDF ou image) | 'pdf' (PDF seul).
 * Retourne le nom stocké. Lève une Exception avec un message affichable.
 */
function sante_save_upload(array $f, string $dir, string $mode): string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new Exception("L'envoi du fichier a échoué (taille ou format).");
    $max = $mode === 'photo' ? 5 * 1024 * 1024 : SANTE_MAX_DOC;
    if ($f['size'] > $max || $f['size'] < 100) throw new Exception("Fichier trop lourd (" . (int)($max / 1048576) . " Mo maximum) ou vide.");
    $bytes = (string)file_get_contents($f['tmp_name']);
    $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_buffer($fi, $bytes); finfo_close($fi);
    if ($mime === 'application/pdf' && $mode !== 'photo') {
        if (strncmp($bytes, '%PDF-', 5) !== 0 || preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b#', $bytes)) throw new Exception("Ce PDF n'est pas accepté (contenu actif).");
        return sante_store($dir, $bytes, 'pdf');
    }
    if ($mode !== 'pdf' && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        if (@getimagesizefromstring($bytes) === false) throw new Exception("Image illisible.");
        return sante_store($dir, sante_image_jpeg($bytes, 2000, $mode === 'photo' ? 400 : 0), 'jpg');
    }
    throw new Exception($mode === 'photo' ? "Photo : JPEG, PNG ou WebP uniquement." : ($mode === 'pdf' ? "PDF uniquement." : "Format non accepté : PDF, JPG ou PNG."));
}

/** Efface données de santé + documents d'un enfant (à la demande du parent, ou purge automatique) */
function sante_purge_enfant(PDO $pdo, int $enfantId): void {
    $st = $pdo->prepare("SELECT carnet_sante_token, fiche_sanitaire_token, signature_token FROM enfants WHERE id = ?");
    $st->execute([$enfantId]);
    $e = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $cols = $pdo->query("SHOW COLUMNS FROM enfants")->fetchAll(PDO::FETCH_COLUMN);
    $clean = ['infos_sante' => '', 'allergies' => '', 'traitements' => '', 'regime_alimentaire' => '', 'medecin_nom' => '', 'medecin_tel' => '', 'urgence_nom' => '', 'urgence_tel' => '',
              'urgence_lien' => '', 'vaccins_data' => null, 'carnet_sante_token' => null, 'fiche_sanitaire_token' => null, 'signature_token' => null, 'fiche_origine' => null, 'sante_consent_at' => null];
    $set = []; $vals = [];
    foreach ($clean as $c => $v) if (in_array($c, $cols, true)) { $set[] = "`$c` = ?"; $vals[] = $v; }
    $vals[] = $enfantId;
    $pdo->prepare("UPDATE enfants SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
    foreach (['sante' => 'carnet_sante_token', 'sanitaire' => 'fiche_sanitaire_token', 'signatures' => 'signature_token'] as $d => $c) sante_unlink($d, $e[$c] ?? null);
}

// ---------------- complétude ----------------
const FICHE_RUBRIQUES = ['parents' => 'Informations parents', 'enfant' => "Informations de l'enfant", 'sante' => 'Santé', 'autorisations' => "Autorisations et droit à l'image", 'newsletter' => 'Newsletter et communications', 'documents' => 'Documents'];

/** [pourcentage, manques [[libellé, rubrique]], état par rubrique [rubrique => bool complète]] */
function fiche_completude(array $e): array {
    $ok = fn($k) => trim((string)($e[$k] ?? '')) !== '';
    $items = [
        ['Responsable légal : nom et prénom', 'parents', $ok('resp1_nom') && $ok('resp1_prenom')],
        ['Responsable légal : e-mail et téléphone', 'parents', $ok('resp1_email') && $ok('resp1_tel')],
        ['Prénom, nom et date de naissance', 'enfant', $ok('prenom') && $ok('nom') && $ok('date_naissance')],
        ['Adresse', 'enfant', $ok('adresse') && $ok('code_postal') && $ok('ville')],
        ['Médecin traitant', 'sante', $ok('medecin_nom') && $ok('medecin_tel')],
        ["Personne à prévenir en urgence", 'sante', $ok('urgence_nom') && $ok('urgence_tel')],
        ['Taille et poids', 'sante', $ok('taille') && $ok('poids')],
        ['Accord parental', 'autorisations', !empty($e['accord_parental'])],
        ['Conditions générales acceptées', 'autorisations', !empty($e['cgv_accepte'])],
        ['Fiche sanitaire de liaison', 'documents', $ok('fiche_sanitaire_token')],
        ['Copie du carnet de santé', 'documents', $ok('carnet_sante_token')],
    ];
    $manque = []; $rub = array_fill_keys(array_keys(FICHE_RUBRIQUES), true); $done = 0;
    foreach ($items as [$label, $r, $good]) { if ($good) $done++; else { $manque[] = [$label, $r]; $rub[$r] = false; } }
    return [(int)round(100 * $done / count($items)), $manque, $rub];
}

/** Rubrique où compléter un critère du directeur (libellé de ENFANT_CRITERES) */
function fiche_rubrique_critere(string $label): string {
    static $map = ['Civilité' => 'enfant', "Email de l'enfant" => 'enfant', "Mobile de l'enfant" => 'enfant', 'Adresse complète' => 'enfant', 'Taille et poids' => 'sante',
        'Médecin traitant' => 'sante', 'Second responsable légal' => 'parents', 'Accord parental' => 'autorisations', "Droit à l'image" => 'autorisations',
        'Carnet de santé' => 'documents', 'Fiche sanitaire de liaison' => 'documents'];
    return $map[$label] ?? 'enfant';
}
