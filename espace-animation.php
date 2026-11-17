<?php
// espace-animation.php : « Mes candidatures » : suivi (envoyée, vue, acceptée, refusée, liste d'attente, retirée), détail, retrait, messagerie avec le directeur.
$body_class = 'bg-white';
require_once 'partials/header.php';
if (empty($_SESSION['user']['is_animateur'])) { header('Location: login.php?redirect=' . urlencode('espace-animation.php')); exit; }
?>
<title>Mes candidatures - Espace animateur - ColoMap</title>
<main class="ds-page" style="max-width:860px">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="ds-eyebrow">Espace animateur</p><h1 class="ds-h1">Mes candidatures</h1></div>
        <a href="missions" class="ds-btn">Trouver une mission</a>
    </div>
    <div id="rappel" class="mt-6 hidden"></div>
    <section id="notifs" class="mt-6 hidden"></section>
    <div class="mt-6 flex flex-wrap gap-2" id="filtres" role="tablist" aria-label="Filtrer"></div>
    <section class="mt-6"><div id="list" class="space-y-4"><p class="text-[#6b7780]">Chargement…</p></div></section>
</main>
<script src="assets/js/anim.js?v=3"></script>
<script>
(function () {
    var A = window.ANIM, E = A.esc, $ = function (i) { return document.getElementById(i); }, DATA = [], filtre = 'actives';
    var NOTE = { 'Accepté': 'Vous faites partie de l’équipe. Le directeur vous contactera pour la suite.', 'Refusé': 'Cette candidature n’a pas été retenue.', "Liste d'attente": 'Le directeur garde votre candidature en réserve.', 'En attente': 'Le directeur n’a pas encore répondu.', 'Retirée': 'Vous avez retiré cette candidature.' };
    var FILTRES = { actives: 'En cours', 'Accepté': 'Acceptées', 'Refusé': 'Refusées', 'Retirée': 'Retirées', tout: 'Toutes' };
    function dans(d) { var n = Math.ceil((new Date(d + 'T00:00:00') - new Date()) / 864e5); return n > 1 ? 'Début dans ' + n + ' jours' : n === 1 ? 'Début demain' : n === 0 ? 'Début aujourd’hui' : 'Séjour commencé'; }
    function visible(c) { return filtre === 'tout' || (filtre === 'actives' ? c.statut === 'En attente' || c.statut === "Liste d'attente" || c.statut === 'Accepté' : c.statut === filtre); }
    function card(c) {
        var img = c.image_url ? '<img src="' + E(c.image_url) + '" alt="" class="w-full h-full object-cover">' : '';
        var retire = (c.statut === 'En attente' || c.statut === "Liste d'attente") ? '<button class="ds-btn ds-btn-light ds-btn-sm retire" data-c="' + E(c.token) + '">Retirer</button>' : '';
        return '<article class="ds-card p-4 ds-fade"><div class="flex gap-4"><div class="flex-none overflow-hidden" style="width:88px;height:88px;border-radius:16px;background:#e5eaef">' + img + '</div>' +
            '<div class="min-w-0 flex-1"><div class="flex flex-wrap justify-between gap-2"><h2 class="font-extrabold text-[17px] text-[#26343b]"><a href="info-camp-animateur?t=' + encodeURIComponent(c.camp_token) + '">' + E(c.nom) + '</a></h2>' + A.statut(c.statut, c.vue_at) + '</div>' +
            '<p class="text-[13px] text-[#6b7780] mt-1">' + E(c.organisateur || '') + ' · ' + E(c.ville) + ' · ' + E(A.range(c.date_debut, c.date_fin)) + '</p>' +
            '<p class="text-[14px] text-[#3b4a54] mt-2">' + NOTE[c.statut] + '</p>' +
            (c.note_directeur ? '<p class="mt-2 text-[14px] ds-alert-ok" style="padding:10px 14px">' + E(c.note_directeur) + '</p>' : '') + '</div></div>' +
            '<div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-[12px] text-[#6b7780]"><span>Envoyée le ' + E(A.day(c.created_at)) + (c.statut !== 'Retirée' && c.statut !== 'Refusé' ? ' · ' + dans(c.date_debut) : '') + '</span>' +
            '<span class="flex gap-2"><details class="text-[13px]"><summary class="cursor-pointer font-semibold text-[#3d6a8e]">Détail</summary><div class="mt-2 text-[#3b4a54]"><p class="ds-eyebrow">Mon message</p><p class="whitespace-pre-line">' + E(c.motivation || 'Aucun message joint.') + '</p>' +
            (c.decided_at ? '<p class="mt-2 text-[#6b7780]">Décision le ' + E(A.day(c.decided_at)) + '</p>' : '') + '</div></details>' +
            (c.statut !== 'Retirée' ? '<button class="ds-btn ds-btn-light ds-btn-sm ecrire" data-t="' + E(c.camp_token) + '">Écrire au directeur</button>' : '') + retire + '</span></div></article>';
    }
    function draw() {
        $('filtres').innerHTML = Object.keys(FILTRES).map(function (k) {
            var n = DATA.filter(function (c) { var f = filtre; filtre = k; var v = visible(c); filtre = f; return v; }).length;
            return '<button type="button" role="tab" aria-selected="' + (k === filtre) + '" data-f="' + k + '" class="ds-chip" style="' + (k === filtre ? 'background:#26343b;color:#fff;border-color:#26343b' : '') + '">' + FILTRES[k] + ' (' + n + ')</button>';
        }).join('');
        var l = DATA.filter(visible);
        $('list').innerHTML = l.length ? l.map(card).join('') : (DATA.length ? '<p class="text-[#6b7780] py-6 text-center">Aucune candidature dans cette catégorie.</p>' :
            '<div class="ds-card p-10 text-center"><p class="font-extrabold text-[18px] text-[#26343b]">Aucune candidature pour le moment</p><p class="mt-2 text-[#6b7780]">Parcourez les missions et postulez en quelques clics.</p><a href="missions" class="ds-btn mt-5">Voir les missions</a></div>');
    }
    async function load() {
        try {
            var d = await A.api('api/anim_animateur.php?a=mes'); DATA = d.candidatures; draw();
            var nn = d.notifs.filter(function (n) { return !Number(n.lu); });
            if (nn.length) {
                $('notifs').classList.remove('hidden');
                $('notifs').innerHTML = '<div class="ds-card p-5"><div class="flex items-center justify-between"><h2 class="font-extrabold text-[#26343b] flex items-center gap-2">' + A.icon('bell', 'w-5 h-5') + 'Nouveautés</h2><button id="lues" class="text-[13px] underline text-[#6b7780]">Tout marquer comme lu</button></div><ul class="mt-3 space-y-2 text-[14px] text-[#3b4a54]">' +
                    nn.map(function (n) { return '<li>' + E(n.texte) + ' <span class="text-[12px] text-[#9aa3ab]">' + E(A.day(n.created_at)) + '</span></li>'; }).join('') + '</ul></div>';
                $('lues').addEventListener('click', async function () { await A.api('api/anim_animateur.php?a=notifs_lues', { json: {} }); $('notifs').classList.add('hidden'); });
            }
        } catch (e) { $('list').innerHTML = '<div class="ds-alert-err">' + E(e.message) + '</div>'; }
    }
    $('filtres').addEventListener('click', function (e) { var b = e.target.closest('[data-f]'); if (b) { filtre = b.dataset.f; draw(); } });
    $('list').addEventListener('click', async function (e) {
        var r = e.target.closest('.retire'), w = e.target.closest('.ecrire');
        try {
            if (r) { if (!(await ColoUI.confirm('Retirer cette candidature ? Vous pourrez postuler de nouveau plus tard.', { confirmLabel: 'Retirer' }))) return; await A.api('api/anim_animateur.php?a=retirer', { json: { c: r.dataset.c } }); A.toast('Candidature retirée'); load(); }
            if (w) { var d = await A.api('api/anim_contact.php', { json: { camp: w.dataset.t } }); location.href = d.url; }
        } catch (er) { A.toast(er.message, true); }
    });
    // Rappel : un profil incomplet pénalise les candidatures
    A.api('api/anim_animateur.php?a=profil').then(function (p) {
        if (!p.complet) { $('rappel').classList.remove('hidden'); $('rappel').innerHTML = '<div class="ds-alert-err">Votre profil est incomplet : les directeurs le consultent avant de répondre. <a class="underline font-semibold" href="profil-animateur">Le compléter</a></div>'; }
    }).catch(function () { });
    load();
})();
</script>
<?php require_once 'partials/footer.php'; ?>
