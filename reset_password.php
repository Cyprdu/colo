<?php 
require_once 'api/config.php';
$body_class = 'bg-white';
require_once 'partials/header.php';

$token = $_GET['t'] ?? '';
$isValid = false;
$errorMsg = "";

// Vérification initiale du token PHP (pour ne pas afficher le form si expiré)
if ($token) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    if ($stmt->fetch()) {
        $isValid = true;
    } else {
        $errorMsg = "Ce lien est invalide ou a expiré.";
    }
} else {
    $errorMsg = "Lien invalide.";
}
?>

<title>Nouveau mot de passe - ColoMap</title>

<main class="ds-page flex items-center justify-center" style="max-width:460px">
    <div class="w-full ds-card p-8 ds-fade">
            <?php if (!$isValid): ?>
                <div class="text-center">
                    <h1 class="text-xl font-extrabold text-[#26343b]">Lien invalide</h1>
                    <p class="ds-lead mb-6" role="alert"><?= htmlspecialchars($errorMsg) ?></p>
                    <a href="forgot_password.php" class="ds-btn">Faire une nouvelle demande</a>
                </div>
            <?php else: ?>

                <form id="reset-form" novalidate>
                    <h1 class="ds-h1 !text-[26px] mb-1">Nouveau mot de passe</h1>
                    <p class="ds-lead mb-6">Choisissez un mot de passe d'au moins 8 caractères.</p>
                    <div id="message-area" class="mb-4 text-sm font-semibold" role="status" aria-live="polite"></div>
                    <input type="hidden" id="token" value="<?= htmlspecialchars($token) ?>">
                    <div class="mb-4"><label class="ds-label" for="password">Nouveau mot de passe</label><input class="ds-input" id="password" type="password" autocomplete="new-password" required></div>
                    <div class="mb-6"><label class="ds-label" for="confirm">Confirmer</label><input class="ds-input" id="confirm" type="password" autocomplete="new-password" required></div>
                    <button class="ds-btn w-full" type="submit">Modifier le mot de passe</button>
                </form>

                <script>
                document.getElementById('reset-form').addEventListener('submit', async function(event) {
                    event.preventDefault();
                    const p1 = document.getElementById('password').value;
                    const p2 = document.getElementById('confirm').value;
                    const token = document.getElementById('token').value;
                    const messageArea = document.getElementById('message-area');
                    const say = (t, ok) => { messageArea.textContent = t; messageArea.className = 'mb-4 text-sm font-semibold ' + (ok === false ? 'text-[#8a3b32]' : 'text-[#26343b]'); };

                    if (p1 !== p2) { say('Les mots de passe ne correspondent pas.', false); return; }
                    say('Mise à jour...');
                    try {
                        const response = await fetch('api/process_password_reset.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ token, password: p1 })
                        });
                        const result = await response.json();
                        if (result.success) {
                            say(result.success);
                            setTimeout(() => { window.location.href = 'login.php'; }, 2000);
                        } else {
                            say(result.error || 'Erreur.', false);
                        }
                    } catch (error) {
                        say('Erreur technique.', false);
                    }
                });
                </script>

            <?php endif; ?>
        </div>
</main>
<?php require_once 'partials/footer.php'; ?>