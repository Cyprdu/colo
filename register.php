<?php
// register.php : création de compte (parent ou animateur)
$auth_title    = 'Inscription - ColoMap';
$scene_kicker  = 'Rejoignez la communauté';
$scene_title   = 'Votre colo commence ici.';
$scene_text    = 'Créez votre compte en une minute : réservez, suivez chaque séjour et échangez avec les organisateurs.';
$scene_points  = [['fa-bolt', 'Réservation en quelques clics'], ['fa-lock', 'Paiement sécurisé par Stripe'], ['fa-child-reaching', 'Une fiche par enfant, réutilisable'], ['fa-comments', 'Messagerie avec les organisateurs']];
$auth_alt      = ['text' => 'Déjà un compte ?', 'label' => 'Se connecter', 'href' => 'login.php'];
require_once 'partials/auth_start.php';
?>
<div class="rise">
    <div>
        <h1 class="auth-title">Créer un compte</h1>
        <p class="auth-sub">Quelques informations et vous êtes dans l'aventure.</p>
    </div>

    <div id="message-area" class="mt-5" aria-live="polite"></div>

    <form id="register-form" class="mt-6 space-y-5">
        <div>
            <span class="ds-label">Je m'inscris en tant que</span>
            <div class="grid grid-cols-2 gap-3">
                <label class="role-card"><input type="radio" name="role" value="parent" checked><span class="box"><i class="fa-solid fa-people-roof"></i><b>Parent</b><span>Réserver des séjours pour mes enfants</span></span></label>
                <label class="role-card"><input type="radio" name="role" value="animateur"><span class="box"><i class="fa-solid fa-bullhorn"></i><b>Animateur</b><span>Trouver des missions d'animation</span></span></label>
            </div>
            <label id="bafa-row" class="mt-3 hidden items-center gap-2 text-[14px] text-[#3b4a54] cursor-pointer select-none">
                <input type="checkbox" id="bafa" class="h-4 w-4 rounded border-gray-300 accent-[#2f3d45]"> Je possède le BAFA
            </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="ds-label" for="prenom">Prénom</label><div class="field"><i class="fa-regular fa-user ico"></i><input class="ds-input" id="prenom" type="text" autocomplete="given-name" required></div></div>
            <div><label class="ds-label" for="nom">Nom</label><div class="field"><i class="fa-regular fa-user ico"></i><input class="ds-input" id="nom" type="text" autocomplete="family-name" required></div></div>
        </div>
        <div><label class="ds-label" for="mail">Email</label><div class="field"><i class="fa-regular fa-envelope ico"></i><input class="ds-input" id="mail" type="email" autocomplete="email" required placeholder="vous@exemple.fr"></div></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="ds-label" for="naissance">Date de naissance</label><div class="field"><i class="fa-regular fa-calendar ico"></i><input class="ds-input" id="naissance" type="date" autocomplete="bday" required></div></div>
            <div><label class="ds-label" for="sexe">Sexe</label><div class="field"><i class="fa-solid fa-venus-mars ico"></i><select id="sexe" class="ds-select" style="padding-left:44px;height:52px"><option>Homme</option><option>Femme</option></select></div></div>
        </div>
        <div>
            <label class="ds-label" for="password">Mot de passe</label>
            <div class="field">
                <i class="fa-solid fa-lock ico"></i>
                <input class="ds-input" id="password" type="password" autocomplete="new-password" required minlength="8" placeholder="8 caractères minimum" style="padding-right:48px">
                <button type="button" id="toggle-pw" class="absolute inset-y-0 right-0 pr-4 flex items-center text-[#9aa3ab] hover:text-[#26343b]" aria-label="Afficher ou masquer le mot de passe"><i class="fa-solid fa-eye"></i></button>
            </div>
            <div class="meter mt-3" aria-hidden="true"><i id="pw-bar"></i></div>
            <p id="pw-hint" class="mt-1.5 text-[12.5px] text-[#6b7780]">Mélangez lettres, chiffres et symboles pour un mot de passe solide.</p>
        </div>

        <button type="submit" class="auth-btn" id="register-btn"><span>Rejoindre l'aventure</span><i class="fa-solid fa-arrow-right arrow"></i><span class="spin"></span></button>
    <?php require_once __DIR__ . "/api/security.php"; echo sec_turnstile_widget(); ?>
    </form>
</div>

<script>
(function () {
    const $ = id => document.getElementById(id);

    // BAFA : seulement pour les animateurs
    function syncRole() {
        const anim = document.querySelector('input[name="role"]:checked').value === 'animateur';
        const row = $('bafa-row');
        row.classList.toggle('hidden', !anim); row.classList.toggle('flex', anim);
        if (!anim) $('bafa').checked = false;
    }
    document.querySelectorAll('input[name="role"]').forEach(r => r.addEventListener('change', syncRole));

    // mot de passe : œil + jauge de robustesse
    $('toggle-pw').addEventListener('click', function () {
        const f = $('password'), show = f.type === 'password';
        f.type = show ? 'text' : 'password';
        this.querySelector('i').className = 'fa-solid ' + (show ? 'fa-eye-slash' : 'fa-eye');
    });
    $('password').addEventListener('input', function () {
        const v = this.value; let score = 0;
        if (v.length >= 8) score++; if (v.length >= 12) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++; if (/\d/.test(v)) score++; if (/[^A-Za-z0-9]/.test(v)) score++;
        const pct = v ? Math.max(12, score * 20) : 0;
        const bar = $('pw-bar'); bar.style.width = pct + '%';
        bar.style.background = score <= 2 ? '#a9b8c6' : (score === 3 ? '#5b7a91' : '#2f3d45');
        $('pw-hint').textContent = !v ? 'Mélangez lettres, chiffres et symboles pour un mot de passe solide.' : (score <= 2 ? 'Encore un effort : ajoutez des chiffres ou des majuscules.' : (score === 3 ? 'Bien. Un symbole le rendrait excellent.' : 'Excellent mot de passe.'));
    });

    const alertBox = (type, text) => {
        const ok = type === 'ok';
        $('message-area').innerHTML = '<div class="' + (ok ? 'ds-alert-ok' : 'ds-alert-err') + ' flex items-start gap-3" role="' + (ok ? 'status' : 'alert') + '"><i class="fa-solid ' + (ok ? 'fa-circle-check check-pop' : 'fa-circle-exclamation') + ' mt-0.5"></i><p class="text-[14px] font-semibold"></p></div>';
        $('message-area').querySelector('p').textContent = text;
    };

    $('register-form').addEventListener('submit', async function (event) {
        event.preventDefault();
        const btn = $('register-btn'); btn.classList.add('is-loading'); $('message-area').innerHTML = '';
        const formData = {
            nom: $('nom').value, prenom: $('prenom').value, mail: $('mail').value, naissance: $('naissance').value,
            sexe: $('sexe').value, password: $('password').value,
            role: document.querySelector('input[name="role"]:checked').value, bafa: $('bafa').checked,
            'cf-turnstile-response': (document.querySelector('[name="cf-turnstile-response"]') || {}).value || ''
        };
        try {
            const response = await fetch('api/user_register.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(formData) });
            const result = await response.json();
            if (result.success) {
                alertBox('ok', result.success);
                // Redirection vers validation.php avec le jeton reçu
                setTimeout(() => { window.location.href = result.redirect_token ? 'validation.php?t=' + result.redirect_token : 'login.php'; }, 1500);
            } else {
                alertBox('err', result.error || 'Une erreur est survenue.');
                btn.classList.remove('is-loading');
                $('message-area').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } catch (error) {
            console.error(error);
            alertBox('err', 'Une erreur est survenue. Réessayez dans un instant.');
            btn.classList.remove('is-loading');
        }
    });
})();
</script>
<?php require_once 'partials/auth_end.php'; ?>
