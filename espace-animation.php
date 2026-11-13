<?php
// espace-animation.php : suivi des candidatures de l'animateur + notifications.
$body_class = 'bg-white';
require_once 'partials/header.php';
if (empty($_SESSION['user']['is_animateur'])) { header('Location: login.php?redirect=' . urlencode('espace-animation.php')); exit; }
?>
<title>Mes candidatures - Espace animation - ColoMap</title>
<main class="ds-page" style="max-width:860px">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="ds-eyebrow">Espace animateur</p><h1 class="ds-h1">Mes candidatures</h1></div>
        <div class="flex gap-3"><a href="profil-animateur" class="ds-btn ds-btn-light">Mon profil</a><a href="animateur" class="ds-btn">Trouver un séjour</a></div>
    </div>
    <section id="notifs" class="mt-8 hidden"></section>
    <section class="mt-8"><div id="list" class="space-y-4"><p class="text-[#6b7780]">Chargement…</p></div></section>
</main>
<script src="assets/js/anim.js?v=1"></script>
<script>
(function () {
    var A = window.ANIM, $ = function (i) { return document.getElementById(i); };
    var NOTE = { 'Accepté': 'Vous faites partie de l’équipe. Le directeur vous contactera pour la suite.', 'Refusé': 'Cette candidature n’a pas été retenue.', "Liste d'attente": 'Le directeur garde votre candidature en réserve.', 'En attente': 'Le directeur n’a pas encore répondu.' };
    function card(c) {
        var img = c.image_url ? '<img src="' + A.esc(c.image_url) + '" alt="" class="w-full h-full object-cover">' : '';
        var retire = (c.statut === 'En attente' || c.statut === "Liste d'attente") ? '<button class="text-[13px] font-semibold text-[#6b7780] underline retire" data-c="' + A.esc(c.token) + '">Retirer ma candidature</button>' : '';
        return '<article class="ds-card p-4 flex gap-4 ds-fade"><div class="flex-none overflow-hidden" style="width:96px;height:96px;border-radius:16px;background:#e5eaef">' + img + '</div>' +
            '<div class="min-w-0 flex-1"><div class="flex flex-wrap justify-between gap-2"><h2 class="font-extrabold text-[17px] text-[#26343b]">' + A.esc(c.nom) + '</h2>' + A.statut(c.statut) + '</div>' +
            '<p class="text-[13px] text-[#6b7780] mt-1">' + A.esc(c.ville) + ' · ' + A.esc(A.range(c.date_debut, c.date_fin)) + '</p>' +
            '<p class="text-[14px] text-[#3b4a54] mt-2">' + NOTE[c.statut] + '</p>' +
            (c.note_directeur ? '<p class="mt-2 text-[14px] ds-alert-ok" style="padding:10px 14px">' + A.esc(c.note_directeur) + '</p>' : '') +
            '<div class="mt-2 flex items-center justify-between text-[12px] text-[#6b7780]"><span>Envoyée le ' + A.esc(A.day(c.created_at)) + '</span>' + retire + '</div></div></article>';
    }
    async function load() {
        try {
            var d = await A.api('api/anim_animateur.php?a=mes');
            $('list').innerHTML = d.candidatures.length ? d.candidatures.map(card).join('') :
                '<div class="ds-card p-10 text-center"><p class="font-extrabold text-[18px] text-[#26343b]">Aucune candidature pour le moment</p><p class="mt-2 text-[#6b7780]">Parcourez les séjours qui recrutent et postulez en quelques clics.</p><a href="animateur" class="ds-btn mt-5">Voir les séjours</a></div>';
            var nn = d.notifs.filter(function (n) { return !Number(n.lu); });
            if (nn.length) {
                $('notifs').classList.remove('hidden');
                $('notifs').innerHTML = '<div class="ds-card p-5"><div class="flex items-center justify-between"><h2 class="font-extrabold text-[#26343b] flex items-center gap-2">' + A.icon('bell', 'w-5 h-5') + 'Nouveautés</h2><button id="lues" class="text-[13px] underline text-[#6b7780]">Tout marquer comme lu</button></div><ul class="mt-3 space-y-2 text-[14px] text-[#3b4a54]">' +
                    nn.map(function (n) { return '<li>' + A.esc(n.texte) + ' <span class="text-[12px] text-[#9aa3ab]">' + A.esc(A.day(n.created_at)) + '</span></li>'; }).join('') + '</ul></div>';
                $('lues').addEventListener('click', async function () { await A.api('api/anim_animateur.php?a=notifs_lues', { json: {} }); $('notifs').classList.add('hidden'); });
            }
            [].forEach.call(document.querySelectorAll('.retire'), function (b) {
                b.addEventListener('click', async function () {
                    if (!confirm('Retirer cette candidature ?')) return;
                    await A.api('api/anim_animateur.php?a=retirer', { json: { c: b.dataset.c } }); load();
                });
            });
        } catch (e) { $('list').innerHTML = '<div class="ds-alert-err">' + A.esc(e.message) + '</div>'; }
    }
    load();
})();
</script>
<?php require_once 'partials/footer.php'; ?>
