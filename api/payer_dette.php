<?php
// api/payer_dette.php (POST : csrf, o = jeton de l'organisme) : « Payer ma dette ». Montant recalculé côté serveur au clic, Checkout sur le compte
// plateforme (voir fin_dette_checkout), redirection 303 vers Stripe. Réservé au propriétaire de l'organisme.
require_once 'config.php';
require_once 'finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: ' . $site . '/login.php'); exit; }
$tok = (string)($_POST['o'] ?? '');
$orgaId = fin_orga_from_tok($pdo, $tok, (int)$_SESSION['user']['id']);
if (!$orgaId) fin_orga_404();
$back = $site . '/dashboard_organisme.php?o=' . fin_orga_tok($orgaId);
try {
    fin_ensure($pdo);
    if (!fin_csrf_ok()) throw new Exception('Session expirée, rechargez la page.');
    header('Location: ' . fin_dette_checkout($pdo, $orgaId, (int)$_SESSION['user']['id']), true, 303);
} catch (Throwable $e) {
    error_log('payer_dette: ' . $e->getMessage());
    $msg = $e instanceof \Stripe\Exception\ApiErrorException ? 'Le paiement est momentanément indisponible, réessayez dans quelques minutes.' : $e->getMessage();
    header('Location: ' . $back . '&error=' . urlencode($msg) . '#dette', true, 303);
}
exit;
