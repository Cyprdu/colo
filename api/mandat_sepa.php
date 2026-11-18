<?php
// api/mandat_sepa.php (POST : csrf, o) : enregistrement du mandat de prélèvement SEPA de l'organisme (Checkout « setup » de la plateforme).
require_once 'config.php';
require_once 'finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: ' . $site . '/login.php'); exit; }
$orgaId = fin_orga_from_tok($pdo, (string)($_POST['o'] ?? ''), (int)$_SESSION['user']['id']);
if (!$orgaId) fin_orga_404();
try {
    fin_ensure($pdo);
    if (!fin_csrf_ok()) throw new Exception('Session expirée, rechargez la page.');
    header('Location: ' . fin_sepa_mandat_checkout($pdo, $orgaId, (int)$_SESSION['user']['id']), true, 303);
} catch (Throwable $e) {
    error_log('mandat_sepa: ' . $e->getMessage());
    header('Location: ' . $site . '/dashboard_organisme.php?o=' . fin_orga_tok($orgaId) . '&error=' . urlencode('Le mandat de prélèvement est momentanément indisponible.') . '#dette', true, 303);
}
exit;
