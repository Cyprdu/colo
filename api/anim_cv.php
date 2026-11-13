<?php
// api/anim_cv.php : téléchargement d'un CV. Accès uniquement :
//   - à l'animateur lui-même (sans paramètre)
//   - au directeur d'un séjour auquel cet animateur a candidaté (?c=<jeton de candidature>)
// Les fichiers sont dans uploads/cv/ (nom aléatoire, accès web direct interdit) et ne sont jamais servis autrement.
require_once 'config.php';
require_once 'animateurs.php';

if (!anim_user_id()) { http_response_code(403); exit('Accès interdit.'); }
anim_ensure($pdo);

$ownerId = 0;
if (!empty($_GET['c'])) {
    if (!anim_is_directeur()) { http_response_code(403); exit('Accès interdit.'); }
    $cd = anim_candidature_du_directeur($pdo, (string)$_GET['c'], anim_user_id());
    if (!$cd) { http_response_code(404); exit('Introuvable.'); }
    $ownerId = (int)$cd['user_id'];
} else {
    $ownerId = anim_user_id();
}
$p = anim_profil($pdo, $ownerId);
$file = (string)$p['cv_fichier'];
$path = __DIR__ . '/../uploads/cv/' . $file;
if (!preg_match('/^[a-f0-9]{40}\.(pdf|docx?)$/', $file) || !is_file($path)) { http_response_code(404); exit('Aucun CV.'); }

$ext = pathinfo($file, PATHINFO_EXTENSION);
$types = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$nom = preg_replace('/[^\w.\-]+/', '_', (string)($p['cv_nom'] ?: ('cv.' . $ext)));
header('Content-Type: ' . $types[$ext]);
header('Content-Disposition: ' . ($ext === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $nom . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if (ob_get_level()) ob_end_clean();
readfile($path);
