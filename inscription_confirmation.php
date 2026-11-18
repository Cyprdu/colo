<?php
// Fichier : inscription_confirmation.php
// Page de retour de Stripe. Elle n'accepte ni identifiant numérique de séjour : uniquement le jeton public
// du séjour (camp_t) et, en cas d'annulation, les jetons de réservation (secrets) à libérer.
require_once 'api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$success   = isset($_GET['success']) && $_GET['success'] == '1';
$cancel    = isset($_GET['cancel']) && $_GET['cancel'] == '1';
$tokens    = (string)($_GET['tokens'] ?? '');
$camp_t    = preg_match('/^[A-Za-z0-9]{6,40}$/', (string)($_GET['camp_t'] ?? '')) ? $_GET['camp_t'] : '';

// Annulation : on libère les places réservées pour CETTE commande (jetons secrets, uniquement « en attente »)
if ($cancel && $tokens !== '') {
    try {
        $tokenArray = array_values(array_filter(array_map('trim', explode(',', $tokens)), function ($t) { return preg_match('/^[a-f0-9]{16,64}$/i', $t); }));
        if ($tokenArray) {
            require_once 'api/attente.php';
            // Si le directeur autorise l'attente, la réservation est conservée (à régler depuis « Mes réservations »)
            $kept = attente_liberer($pdo, $tokenArray) > 0;
        }
    } catch (Exception $e) {
        error_log("Erreur suppression inscription annulée : " . $e->getMessage());
    }
}

// Destinations en chemins absolus (jamais « // » dans l'adresse), avec jeton et non identifiant
$backUrl = !empty($kept) ? '/reservations' : ($camp_t !== '' ? '/camp_details?t=' . $camp_t : '/recherche');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $success ? 'Paiement confirmé' : 'Paiement annulé' ?> - moncamp.fr</title>
    <link rel="icon" type="image/png" href="/favico.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@dotlottie/player-component@latest/dist/dotlottie-player.mjs" type="module"></script>
    <style>html, body { margin:0; padding:0; width:100%; height:100%; overflow:hidden; }</style>
</head>
<body class="flex items-center justify-center w-screen h-screen bg-white">

<?php if ($success): ?>
    <dotlottie-player src="/chek.json" background="transparent" speed="1" autoplay style="width: min(900px, 90vw); height: min(900px, 90vh);"></dotlottie-player>
    <script>setTimeout(() => { window.location.href = '/reservations'; }, 3500);</script>
<?php else: ?>
    <dotlottie-player src="/fail.json" background="transparent" speed="1" autoplay style="width: min(900px, 90vw); height: min(900px, 90vh);"></dotlottie-player>
    <script>setTimeout(() => { window.location.href = <?= json_encode($backUrl) ?>; }, 3500);</script>
<?php endif; ?>

</body>
</html>
