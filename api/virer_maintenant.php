<?php
// api/virer_maintenant.php (POST : csrf, o = jeton de l'organisme) : vire tout de suite le disponible sur la banque de l'organisme.
require_once 'config.php';
require_once 'finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: ' . $site . '/login.php'); exit; }
$orgaId = fin_orga_from_tok($pdo, (string)($_POST['o'] ?? ''), (int)$_SESSION['user']['id']);
if (!$orgaId) fin_orga_404();
$back = $site . '/dashboard_organisme.php?o=' . fin_orga_tok($orgaId);
try {
    fin_ensure($pdo);
    if (!fin_csrf_ok()) throw new Exception('Session expirée, rechargez la page.');
    $r = fin_virer_maintenant($pdo, $orgaId, 'user:' . (int)$_SESSION['user']['id']);
    $msg = $r ? 'Virement de ' . fin_eur($r['m']) . ' lancé' . ($r['arrivee'] ? ', arrivée prévue le ' . date('d/m/Y', strtotime($r['arrivee'])) : '') . '.' : 'Aucun montant disponible à virer pour le moment (minimum 1 €).';
    header('Location: ' . $back . '&success=' . urlencode($msg) . '#versements', true, 303);
} catch (Throwable $e) {
    error_log('virer_maintenant: ' . $e->getMessage());
    $msg = $e instanceof \Stripe\Exception\ApiErrorException ? 'Le virement est momentanément indisponible, réessayez dans quelques minutes.' : $e->getMessage();
    header('Location: ' . $back . '&error=' . urlencode($msg) . '#versements', true, 303);
}
exit;
