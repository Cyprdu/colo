<?php
// api/connect_dashboard.php?organisateur_id=N : lien d'accès au tableau de bord Stripe Express de l'organisme
// (virements bancaires reçus, IBAN, relevés). Réservé à son directeur.
require_once 'config.php';
require_once 'finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: ' . $site . '/login.php'); exit; }
$orgaId = (int)($_GET['organisateur_id'] ?? 0);
$back = $site . '/dashboard_organisme.php?organisateur_id=' . $orgaId;
try {
    $st = $pdo->prepare("SELECT stripe_account_id FROM organisateurs WHERE id = ? AND user_id = ?");
    $st->execute([$orgaId, $_SESSION['user']['id']]);
    $acct = $st->fetchColumn();
    if (!$acct) throw new Exception('Aucun compte.');
    $link = fin_stripe()->accounts->createLoginLink($acct);
    header('Location: ' . $link->url, true, 303);
} catch (Throwable $e) {
    header('Location: ' . $back . '&error=' . urlencode("Cet espace n'est pas encore disponible pour cet organisme."));
}
exit;
