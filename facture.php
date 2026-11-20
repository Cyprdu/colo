<?php
// facture.php?f=<jeton> : PDF d'une facture de commission, pour le propriétaire de l'organisme ou un comptable de l'équipe.
require_once 'api/config.php';
require_once 'api/adm_core.php';
require_once 'api/factures_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$tok = (string)($_GET['f'] ?? '');
$f = null;
if ($uid > 0 && preg_match('/^[a-f0-9]{32}$/', $tok)) {
    fac_ensure($pdo);
    $st = $pdo->prepare("SELECT f.numero, f.pdf, f.sha256, o.user_id FROM fin_factures f LEFT JOIN organisateurs o ON o.id = f.organisateur_id WHERE f.token = ?");
    $st->execute([$tok]);
    $f = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($f && (int)$f['user_id'] !== $uid && !adm_can('compta.read')) $f = null;
}
if (!$f || !hash_equals($f['sha256'], hash('sha256', $f['pdf']))) { http_response_code(404); exit('Facture introuvable.'); }
header('Content-Type: application/pdf');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $f['numero'] . '.pdf"');
echo $f['pdf'];
