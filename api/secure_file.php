<?php
// api/secure_file.php?type=sante|sanitaire|signatures&file=<nom> : SEUL chemin d'accès aux documents de santé des enfants.
// Autorisé : le parent propriétaire de l'enfant, le directeur d'un séjour auquel l'enfant est inscrit, un administrateur.
// Chaque ouverture est enregistrée (table acces_sante). Les dossiers uploads/sante|sanitaire|signatures sont fermés au web (.htaccess).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/adm_core.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/sante.php';

function sf_stop(int $code): void { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $code === 403 ? 'Accès interdit.' : 'Introuvable.'; exit; }

if (empty($_SESSION['user']['id'])) sf_stop(403);
$uid = (int)$_SESSION['user']['id'];

$types = SANTE_DIRS;   // sante, sanitaire, signatures, photos
$type = (string)($_GET['type'] ?? 'sante');
$name = (string)($_GET['file'] ?? '');
if (!isset($types[$type]) || !preg_match('/^[a-f0-9]{64}\.(pdf|jpe?g|png|webp|heic)$/i', $name)) sf_stop(404);
$col = $types[$type];

try {
    // À qui appartient ce document ?
    $st = $pdo->prepare("SELECT id, parent_id FROM enfants WHERE $col = ? LIMIT 1");
    $st->execute([$name]);
    $enfant = $st->fetch(PDO::FETCH_ASSOC);
    if (!$enfant) sf_stop(404);

    $ok = ((int)$enfant['parent_id'] === $uid) || adm_can('inscriptions.read');
    if (!$ok && !empty($_SESSION['user']['is_directeur'])) {
        // directeur d'un séjour où l'enfant est inscrit (inscription non annulée)
        $st = $pdo->prepare("SELECT 1 FROM inscriptions i JOIN camps c ON c.id = i.camp_id JOIN organisateurs o ON o.id = c.organisateur_id
                             WHERE i.enfant_id = ? AND o.user_id = ? AND COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule') LIMIT 1");
        $st->execute([(int)$enfant['id'], $uid]);
        $ok = (bool)$st->fetchColumn();
    }
    if (!$ok) sf_stop(403);
} catch (Throwable $e) {
    error_log('secure_file: ' . $e->getMessage());
    sf_stop(404);
}

$path = __DIR__ . '/../uploads/' . $type . '/' . $name;
if (!is_file($path)) sf_stop(404);

try { $data = sante_decrypt((string)file_get_contents($path)); }   // fichier chiffré au repos : déchiffré à la volée, jamais écrit en clair
catch (Throwable $e) { error_log('secure_file: ' . $e->getMessage()); sf_stop(404); }

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = (string)finfo_buffer($finfo, $data);
finfo_close($finfo);
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'], true)) sf_stop(403);   // jamais autre chose qu'un PDF ou une image

if ($type !== 'photos' || (int)$enfant['parent_id'] !== $uid) sante_log($pdo, $uid, (int)$enfant['id'], $type);   // journal : qui a ouvert quel document (hors photo vue par son parent)

if (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="document.' . ($mime === 'application/pdf' ? 'pdf' : 'jpg') . '"');
header('Content-Length: ' . strlen($data));
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
header('X-Robots-Tag: noindex, noarchive');
echo $data;
exit;
