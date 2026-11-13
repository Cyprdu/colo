<?php
// api/connect_onboarding.php?o=<jeton> : ouvre (ou reprend) l'inscription Stripe de l'organisme.
// Compte Connect EXPRESS : Stripe collecte lui-même l'identité, les justificatifs et l'IBAN (rien ne transite par ColoMap).
// Sans compte, on passe d'abord par connect_statut.php (choix du statut : particulier, micro, association, société) qui crée le compte pré-rempli.
// Ancien paramètre organisateur_id accepté pour les liens déjà envoyés.
require_once 'config.php';
require_once 'finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: ' . $site . '/login.php'); exit; }
$userId = (int)$_SESSION['user']['id'];
$back = $site . '/public_infos.php';

try {
    fin_ensure($pdo);
    $orgaId = !empty($_GET['o']) ? fin_orga_from_tok($pdo, (string)$_GET['o'], $userId) : (int)($_GET['organisateur_id'] ?? 0);
    $st = $pdo->prepare("SELECT id, nom, email, web, stripe_account_id FROM organisateurs WHERE id = ? AND user_id = ?");
    $st->execute([$orgaId, $userId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new Exception('Organisme introuvable.');
    $tok = fin_orga_tok((int)$o['id']);
    $back = $site . '/dashboard_organisme.php?organisateur_id=' . (int)$o['id'];

    if (empty($o['stripe_account_id'])) { header('Location: ' . $site . '/connect_statut.php?o=' . $tok); exit; }
    $link = fin_stripe()->accountLinks->create([
        'account' => $o['stripe_account_id'], 'type' => 'account_onboarding',
        'refresh_url' => $site . '/api/connect_onboarding.php?o=' . $tok,
        'return_url' => $site . '/dashboard_organisme.php?organisateur_id=' . (int)$o['id'] . '&stripe=retour',
    ]);
    header('Location: ' . $link->url, true, 303);
    exit;
} catch (Throwable $e) {
    error_log('connect_onboarding: ' . $e->getMessage());
    header('Location: ' . $back . (strpos($back, '?') ? '&' : '?') . 'error=' . urlencode("L'activation des versements est momentanément indisponible. Réessayez dans quelques minutes."));
    exit;
}
