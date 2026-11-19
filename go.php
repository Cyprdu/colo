<?php
// go.php : /go/<jeton du séjour> → site de l'organisme (« Réserver ma place » externe), avec utm_source=moncamp.fr, utm_medium=referral,
// utm_campaign=<jeton du séjour> et mc_click=<identifiant unique du clic>. Le clic est enregistré (api/clics.php) avant la redirection.
require_once 'api/config.php';
require_once 'api/clics.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$t = (string)($_GET['t'] ?? '');
$camp = null;
if (preg_match('/^[A-Za-z0-9]{6,64}$/', $t)) {
    try {
        $st = $pdo->prepare("SELECT id, token, organisateur_id, lien_externe FROM camps WHERE token = ? AND COALESCE(supprime, 0) = 0");
        $st->execute([$t]);
        $camp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('go: ' . $e->getMessage()); }
}
$ok = $camp && clics_url((string)$camp['lien_externe'], (string)$camp['token'], 'x') !== null;
if (!$ok) {
    header('Location: ' . ($camp ? '/camp_details?t=' . rawurlencode((string)$camp['token']) : '/recherche'), true, 302);
    exit;
}
header('Location: ' . clics_url((string)$camp['lien_externe'], (string)$camp['token'], clics_enregistrer($pdo, (int)$camp['id'], (int)$camp['organisateur_id'])), true, 302);
