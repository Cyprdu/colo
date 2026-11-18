<?php
// info-camp-animateur.php : fiche d'un séjour pour un animateur + candidature.
//   ?t=<jeton séjour>  séjour du catalogue « je recherche des animateurs »
$body_class = 'bg-white';
require_once 'partials/header.php';
$t = (string)($_GET['t'] ?? '');
$self = 'info-camp-animateur.php?t=' . urlencode($t);
if (!isset($_SESSION['user'])) { header('Location: login.php?redirect=' . urlencode($self)); exit; }
$is_anim = !empty($_SESSION['user']['is_animateur']);
?>
<title>Séjour - Espace animateur - moncamp.fr</title>
<main class="ds-page" style="max-width:860px">
    <a href="missions" class="text-[14px] font-semibold text-[#6b7780] hover:text-[#26343b]">&larr; Missions</a>
<?php if (!$is_anim): ?>
    <div class="ds-card mt-6 p-8"><h1 class="ds-h1">Compte animateur requis</h1>
        <p class="ds-lead">Ce lien est destiné aux animateurs. Créez un compte animateur pour postuler.</p>
        <a class="ds-btn mt-5" href="register.php">Créer un compte animateur</a></div>
<?php else: ?>
    <div id="box" class="mt-6"><p class="text-[#6b7780]">Chargement…</p></div>
<?php endif; ?>
</main>
<?php if ($is_anim): ?>
<script src="assets/js/anim.js?v=3"></script>
<script>
(function () {
    var A = window.ANIM, T = <?= json_encode($t) ?>, SELF = <?= json_encode($self) ?>, box = document.getElementById('box');
    function row(ic, label, val) { return '<div class="flex items-start gap-3"><span class="ds-tile-ico" style="width:40px;height:40px;font-size:16px">' + A.icon(ic, 'w-5 h-5') + '</span><div><p class="text-[12px] font-bold uppercase tracking-wide text-[#6b7780]">' + label + '</p><p class="text-[15px] text-[#26343b]">' + val + '</p></div></div>'; }
    async function show() {
        try {
            var c = await A.api('api/anim_animateur.php?a=camp&t=' + encodeURIComponent(T));
            var img = c.image_url ? '<img src="' + A.esc(c.image_url) + '" alt="" class="w-full object-cover" style="height:260px;border-radius:20px">' : '';
            var action;
            if (c.ma_candidature) action = '<div class="ds-alert-ok">Votre candidature : ' + A.statut(c.ma_candidature) + ' <a class="underline ml-2" href="espace-animation">Suivre mes candidatures</a></div>';
            else action = '<div><label class="ds-label" for="msg">Un mot au directeur (facultatif)</label><textarea id="msg" rows="4" maxlength="1500" class="ds-textarea" placeholder="Pourquoi ce séjour vous motive…"></textarea>' +
                '<p class="mt-2 text-[13px] text-[#6b7780]">Votre profil de candidature (présentation, BAFA, CV) sera transmis au directeur. <a class="underline" href="profil-animateur?retour=' + encodeURIComponent(SELF) + '">Modifier mon profil</a></p>' +
                '<div id="err" class="ds-alert-err mt-3 hidden"></div><button id="go" class="ds-btn mt-4">Envoyer ma candidature</button></div>';
            box.innerHTML = img + '<div class="mt-6"><p class="ds-eyebrow">' + A.esc(c.organisateur) + '</p><h1 class="ds-h1">' + A.esc(c.nom) + '</h1></div>' +
                '<div class="ds-card mt-6 p-6 grid sm:grid-cols-2 gap-5">' + row('pin', 'Lieu', A.esc(c.ville)) + row('cal', 'Dates', A.esc(A.range(c.date_debut, c.date_fin))) +
                row('user', 'Âge des enfants', c.age_min != null ? A.esc(c.age_min + ' à ' + c.age_max + ' ans') : 'Non précisé') +
                row('check', 'Rémunération', c.remuneration ? A.money(c.remuneration) : 'Non rémunéré') +
                row('file', 'Diplôme', c.bafa_obligatoire ? 'BAFA requis' : 'BAFA non exigé') +
                row('user', 'Animateurs recherchés', (c.quota ? c.quota : 'Non précisé') + (c.majeur ? ' · majeurs uniquement' : '')) + '</div>' +
                (c.description ? '<div class="mt-6"><h2 class="font-extrabold text-[18px] text-[#26343b]">Le séjour</h2><p class="mt-2 text-[15px] leading-relaxed text-[#3b4a54] whitespace-pre-line">' + A.esc(c.description) + '</p></div>' : '') +
                '<div class="ds-card mt-8 p-6">' + action + '</div>';
            var go = document.getElementById('go');
            if (go) go.addEventListener('click', async function () {
                go.disabled = true; var er = document.getElementById('err'); er.classList.add('hidden');
                try { await A.api('api/anim_animateur.php?a=postuler', { json: { camp: T, message: document.getElementById('msg').value } }); A.toast('Candidature envoyée'); show(); }
                catch (e) {
                    er.classList.remove('hidden'); er.innerHTML = A.esc(e.message) + (e.data && e.data.profil ? ' <a class="underline" href="profil-animateur?retour=' + encodeURIComponent(SELF) + '">Compléter mon profil</a>' : '');
                    go.disabled = false;
                }
            });
        } catch (e) { box.innerHTML = '<div class="ds-card p-8"><h1 class="ds-h1">Séjour introuvable</h1><p class="ds-lead">' + A.esc(e.message) + '</p></div>'; }
    }
    show();
})();
</script>
<?php endif; ?>
<?php require_once 'partials/footer.php'; ?>
