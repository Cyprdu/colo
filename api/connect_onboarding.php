<?php
// api/connect_onboarding.php?organisateur_id=N : ouvre (ou reprend) l'inscription Stripe de l'organisme.
// Compte Connect EXPRESS : Stripe collecte lui-même l'identité, les justificatifs et l'IBAN (rien ne transite par ColoMap).
require_once 'config.php';
require_once 'finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: ' . $site . '/login.php'); exit; }
$orgaId = (int)($_GET['organisateur_id'] ?? 0);
$back = $site . '/dashboard_organisme.php?organisateur_id=' . $orgaId;

try {
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT id, nom, email, web, stripe_account_id FROM organisateurs WHERE id = ? AND user_id = ?");
    $st->execute([$orgaId, $_SESSION['user']['id']]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new Exception('Organisme introuvable.');

    $stripe = fin_stripe();
    $acct = $o['stripe_account_id'];
    if (!$acct) {
        // Clé d'idempotence : un double clic ne crée pas deux comptes
        $profile = ['name' => mb_substr($o['nom'], 0, 100), 'mcc' => '7032']; // 7032 : camps de loisirs et de sport
        if (!empty($o['web']) && filter_var($o['web'], FILTER_VALIDATE_URL)) $profile['url'] = $o['web'];
        $params = [
            'type' => 'express', 'country' => 'FR',
            'capabilities' => ['transfers' => ['requested' => true]],
            'business_profile' => $profile,
            'metadata' => ['organisateur_id' => (string)$orgaId],
        ];
        if (!empty($o['email']) && filter_var($o['email'], FILTER_VALIDATE_EMAIL)) $params['email'] = $o['email'];
        $a = $stripe->accounts->create($params, ['idempotency_key' => 'colomap-acct-' . $orgaId]);
        $acct = $a->id;
        $pdo->prepare("UPDATE organisateurs SET stripe_account_id = ? WHERE id = ? AND (stripe_account_id IS NULL OR stripe_account_id = '')")->execute([$acct, $orgaId]);
        fin_store_account($pdo, $orgaId, $a);
        fin_audit($pdo, 'compte_connect_cree', 'organisateur', $orgaId, ['account' => $acct], 'user:' . $_SESSION['user']['id']);
    }
    $link = $stripe->accountLinks->create([
        'account' => $acct, 'type' => 'account_onboarding',
        'refresh_url' => $site . '/api/connect_onboarding.php?organisateur_id=' . $orgaId,
        'return_url' => $site . '/dashboard_organisme.php?organisateur_id=' . $orgaId . '&stripe=retour',
    ]);
    header('Location: ' . $link->url, true, 303);
    exit;
} catch (Throwable $e) {
    error_log('connect_onboarding: ' . $e->getMessage());
    header('Location: ' . $back . '&error=' . urlencode("L'activation des versements est momentanément indisponible. Réessayez dans quelques minutes."));
    exit;
}
