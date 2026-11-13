<?php
// forgot_password.php : demande d'un lien de réinitialisation du mot de passe
$auth_title   = 'Mot de passe oublié - ColoMap';
$scene_kicker = 'Pas de panique';
$scene_title  = 'On retrouve le chemin ensemble.';
$scene_text   = "Indiquez votre adresse email : nous vous envoyons un lien pour choisir un nouveau mot de passe, et vous repartez à l'aventure.";
$auth_alt     = ['text' => 'Vous vous en souvenez ?', 'label' => 'Se connecter', 'href' => 'login.php'];
require_once 'partials/auth_start.php';
?>
<div class="rise">
    <div>
        <h1 class="auth-title">Mot de passe oublié ?</h1>
        <p class="auth-sub">Entrez votre email pour recevoir un lien de réinitialisation.</p>
    </div>

    <div id="message-area" class="mt-6" aria-live="polite"></div>

    <form id="forgot-form" class="mt-7 space-y-5" novalidate>
        <div>
            <label for="mail" class="ds-label">Adresse email</label>
            <div class="field">
                <i class="fa-regular fa-envelope ico"></i>
                <input id="mail" type="email" autocomplete="email" required placeholder="vous@exemple.fr" class="ds-input">
            </div>
        </div>
        <button type="submit" class="auth-btn" id="forgot-btn"><span>Envoyer le lien</span><i class="fa-solid fa-paper-plane arrow"></i><span class="spin"></span></button>
    </form>

    <p class="mt-6 text-center text-[14px] text-[#6b7780]">Pensez à vérifier vos courriers indésirables.</p>
</div>

<script>
document.getElementById('forgot-form').addEventListener('submit', async function (event) {
    event.preventDefault();
    const mailInput = document.getElementById('mail'), mail = mailInput.value.trim();
    const area = document.getElementById('message-area'), btn = document.getElementById('forgot-btn');
    if (!mailInput.checkValidity() || !mail) { area.innerHTML = '<div class="ds-alert-err flex items-start gap-3" role="alert"><i class="fa-solid fa-circle-exclamation mt-0.5"></i><p class="text-[14px] font-semibold">Saisissez une adresse email valide.</p></div>'; mailInput.focus(); return; }
    btn.classList.add('is-loading'); area.innerHTML = '';
    try {
        const response = await fetch('api/request_password_reset.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mail }) });
        const result = await response.json();
        if (result.success) {
            area.innerHTML = '<div class="ds-alert-ok flex items-start gap-3" role="status"><i class="fa-solid fa-circle-check mt-0.5 check-pop"></i><p class="text-[14px] font-semibold"></p></div>';
            area.querySelector('p').textContent = result.success;
            document.getElementById('forgot-form').reset();
        } else {
            area.innerHTML = '<div class="ds-alert-err flex items-start gap-3" role="alert"><i class="fa-solid fa-circle-exclamation mt-0.5"></i><p class="text-[14px] font-semibold"></p></div>';
            area.querySelector('p').textContent = result.error || 'Une erreur est survenue.';
        }
    } catch (error) {
        area.innerHTML = '<div class="ds-alert-err flex items-start gap-3" role="alert"><i class="fa-solid fa-circle-exclamation mt-0.5"></i><p class="text-[14px] font-semibold">Une erreur est survenue. Réessayez dans un instant.</p></div>';
    } finally { btn.classList.remove('is-loading'); }
});
</script>
<?php require_once 'partials/auth_end.php'; ?>
