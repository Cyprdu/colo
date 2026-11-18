<?php
// demandes-animation.php : tableau de bord directeur de la gestion des animateurs.
// Par séjour : (A) recrutement via moncamp.fr (candidatures + dossier complet) et/ou (B) inscription de l'équipe d'animation (liste, export, liens), réglages.
// Point d'accroche pour edit_camp.php : un lien vers  demandes-animation.php?camp=<jeton du séjour>  (le séjour s'ouvre directement).
$body_class = 'bg-white';
require_once 'partials/header.php';
if (empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }
?>
<title>Gestion des animateurs - moncamp.fr</title>
<main class="ds-page">
    <a href="organisateurs" class="text-[14px] font-semibold text-[#6b7780] hover:text-[#26343b]">&larr; Espace organisateur</a>
    <h1 class="ds-h1 mt-3">Gestion des animateurs</h1>
    <p class="ds-lead">Pour chaque séjour, recrutez via moncamp.fr et/ou ouvrez l'inscription de votre propre équipe d'animation.</p>

    <div class="mt-8 grid gap-6 lg:grid-cols-[300px_1fr] items-start">
        <aside><div id="sejours" class="space-y-2" role="list"></div></aside>
        <section id="detail" class="min-w-0"><div class="ds-card p-10 text-center text-[#6b7780]">Chargement…</div></section>
    </div>
</main>

<div id="am-holder" class="hidden"><?php $animLegacy = false; $camp = null; include 'partials/anim_modes.php'; ?></div>

<div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background:rgba(38,52,59,.5)" role="dialog" aria-modal="true" aria-label="Dossier de l'animateur">
    <div class="ds-card w-full overflow-y-auto" style="max-width:640px;max-height:90vh"><div id="modal-body" class="p-6 sm:p-8"></div></div>
</div>

<script src="assets/js/anim.js?v=3"></script>
<script>
(function () {
    var A = window.ANIM, E = A.esc, $ = function (i) { return document.getElementById(i); };
    var API = 'api/anim_directeur.php', sejours = [], cur = null, tab = 'candidatures';

    function side() {
        $('sejours').innerHTML = sejours.length ? sejours.map(function (s) {
            var on = cur && cur.token === s.token;
            return '<button role="listitem" type="button" data-t="' + E(s.token) + '" class="ds-tile w-full text-left" style="padding:14px;display:block;' + (on ? 'border-color:#26343b' : '') + '">' +
                '<span class="block font-extrabold text-[15px] text-[#26343b]">' + E(s.nom) + '</span>' +
                '<span class="block text-[12px] text-[#6b7780] mt-1">' + E(A.range(s.date_debut, s.date_fin)) + '</span>' +
                '<span class="flex flex-wrap gap-2 mt-2">' + (Number(s.recrute_animateurs) ? '<span class="ds-badge">Recrute via moncamp.fr</span>' : '') + (Number(s.equipe_active) ? '<span class="ds-badge">Équipe : ' + s.equipe_inscrits + ' inscrit(s)</span>' : '') + (!Number(s.recrute_animateurs) && !Number(s.equipe_active) ? '<span class="ds-badge">Non défini</span>' : '') +
                (s.en_attente ? '<span class="ds-badge" style="background:#26343b;color:#fff">' + s.en_attente + ' à traiter</span>' : '') + '</span></button>';
        }).join('') : '<div class="ds-card p-5 text-[14px] text-[#6b7780]">Aucun séjour pour le moment.</div>';
        [].forEach.call($('sejours').querySelectorAll('button'), function (b) { b.addEventListener('click', function () { open(b.dataset.t); }); });
    }

    async function init() {
        try {
            sejours = await A.api(API + '?a=sejours');
            var want = new URLSearchParams(location.search).get('camp');
            var first = sejours.filter(function (s) { return s.token === want; })[0] || sejours[0];
            if (first) await open(first.token); else { side(); $('detail').innerHTML = '<div class="ds-card p-10 text-center text-[#6b7780]">Créez un séjour pour gérer ses animateurs.</div>'; }
        } catch (e) { $('detail').innerHTML = '<div class="ds-alert-err">' + E(e.message) + '</div>'; }
    }
    async function refreshList() { sejours = await A.api(API + '?a=sejours'); cur = sejours.filter(function (s) { return cur && s.token === cur.token; })[0] || cur; side(); }

    async function open(t) { cur = sejours.filter(function (s) { return s.token === t; })[0]; side(); render(); }

    function render() {
        if (!cur) return;
        var tabs = [['candidatures', 'Candidatures' + (cur.en_attente ? ' (' + cur.en_attente + ')' : '')], ['equipe', 'Équipe (' + (cur.acceptes + cur.equipe_inscrits) + ')']];
        if (Number(cur.equipe_active)) tabs.push(['liens', 'Liens d\u2019inscription']);
        tabs.push(['mode', 'Réglages']);
        if (!tabs.some(function (x) { return x[0] === tab; })) tab = 'candidatures';
        if ($('am-root') && !$('am-holder').contains($('am-root'))) $('am-holder').appendChild($('am-root'));
        $('detail').innerHTML = '<div class="ds-card p-5 sm:p-6"><h2 class="font-extrabold text-[20px] text-[#26343b]">' + E(cur.nom) + '</h2>' +
            '<p class="text-[13px] text-[#6b7780]">' + E(cur.ville || '') + ' · ' + E(A.range(cur.date_debut, cur.date_fin)) + '</p>' +
            '<div class="mt-4 flex flex-wrap gap-2" role="tablist">' + tabs.map(function (x) {
                return '<button type="button" role="tab" data-tab="' + x[0] + '" class="ds-chip" style="' + (tab === x[0] ? 'background:#26343b;color:#fff;border-color:#26343b' : '') + '">' + E(x[1]) + '</button>';
            }).join('') + '</div><div id="pane" class="mt-6"></div></div>';
        [].forEach.call($('detail').querySelectorAll('[data-tab]'), function (b) { b.addEventListener('click', function () { tab = b.dataset.tab; render(); }); });
        ({ candidatures: paneCand, equipe: paneEquipe, liens: paneLiens, mode: paneMode })[tab]();
    }

    /* ---- Candidatures ---- */
    async function paneCand() {
        var pane = $('pane'); pane.innerHTML = '<p class="text-[#6b7780]">Chargement…</p>';
        try {
            var rows = await A.api(API + '?a=candidatures&camp=' + encodeURIComponent(cur.token));
            if (!rows.length) {
                pane.innerHTML = '<p class="text-[#6b7780]">' + (Number(cur.recrute_animateurs) ? 'Aucune candidature pour le moment. Votre séjour est visible des animateurs.' : 'Les candidatures arrivent ici si vous activez « Recruter des animateurs via moncamp.fr » dans les réglages. L\u2019équipe inscrite par lien se trouve dans l\u2019onglet Équipe.') + '</p>'; return;
            }
            pane.innerHTML = '<div class="space-y-3">' + rows.map(function (r) {
                return '<div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#e5e7eb] p-4"><div><p class="font-extrabold text-[#26343b]">' + E(r.prenom + ' ' + r.nom) + '</p>' +
                    '<p class="text-[12px] text-[#6b7780] mt-1">' + E(A.BAFA[r.bafa_statut] || '') + (r.has_cv ? ' · CV joint' : '') + (r.via_lien ? ' · via un lien' : '') + ' · ' + E(A.day(r.created_at)) + '</p></div>' +
                    '<div class="flex items-center gap-3">' + A.statut(r.statut) + '<button class="ds-btn ds-btn-light" style="padding:8px 14px" data-dossier="' + E(r.token) + '">Voir le dossier</button></div></div>';
            }).join('') + '</div>';
            [].forEach.call(pane.querySelectorAll('[data-dossier]'), function (b) { b.addEventListener('click', function () { dossier(b.dataset.dossier); }); });
        } catch (e) { pane.innerHTML = '<div class="ds-alert-err">' + E(e.message) + '</div>'; }
    }

    async function dossier(c) {
        var m = $('modal'), body = $('modal-body'); m.classList.remove('hidden'); m.classList.add('flex'); body.innerHTML = '<p class="text-[#6b7780]">Chargement…</p>';
        try {
            var d = await A.api(API + '?a=dossier&c=' + encodeURIComponent(c)), p = d.profil, u = d.animateur;
            var sec = function (t, v) { return v ? '<div class="mt-5"><p class="ds-eyebrow">' + t + '</p><p class="mt-1 text-[15px] leading-relaxed text-[#26343b] whitespace-pre-line">' + E(v) + '</p></div>' : ''; };
            body.innerHTML = '<div class="flex justify-between items-start gap-4"><div><p class="ds-eyebrow">Candidature · ' + E(d.camp.nom) + '</p><h2 class="font-extrabold text-[24px] text-[#26343b]">' + E(u.prenom + ' ' + u.nom) + '</h2>' +
                '<p class="text-[13px] text-[#6b7780] mt-1">' + (u.age != null ? u.age + ' ans · ' : '') + E(A.BAFA[p.bafa_statut] || '') + '</p></div><button id="close" class="text-[#6b7780]" aria-label="Fermer">' + A.icon('x', 'w-6 h-6') + '</button></div>' +
                '<div class="mt-4">' + A.statut(d.statut) + '</div>' +
                '<div class="mt-5 grid sm:grid-cols-2 gap-3 text-[14px]"><p><span class="text-[#6b7780]">E-mail</span><br><a class="underline" href="mailto:' + E(u.email) + '">' + E(u.email) + '</a></p><p><span class="text-[#6b7780]">Téléphone</span><br>' + E(u.tel || 'Non renseigné') + '</p></div>' +
                sec('Présentation', p.presentation) + sec('Numéro de BAFA', p.bafa_numero) + sec('Formation / équivalence', p.formation) + sec('Expériences', p.experiences) + sec('Disponibilités', p.disponibilites) + sec('Message pour ce séjour', d.message) +
                (p.has_cv ? '<p class="mt-5"><a class="ds-btn ds-btn-light" target="_blank" rel="noopener" href="api/anim_cv.php?c=' + encodeURIComponent(c) + '">' + A.icon('file') + ' Ouvrir le CV (' + E(p.cv_nom || 'fichier') + ')</a></p>' : '<p class="mt-5 text-[13px] text-[#6b7780]">Aucun CV joint.</p>') +
                '<p class="mt-5"><button class="ds-btn ds-btn-light" id="ecrire" type="button">Écrire à l\u2019animateur</button></p><div class="mt-6 border-t border-[#e5e7eb] pt-5"><label class="ds-label" for="note">Message à l’animateur (facultatif, joint à la notification)</label><textarea id="note" rows="2" maxlength="500" class="ds-textarea">' + E(d.note || '') + '</textarea>' +
                '<div class="mt-4 flex flex-wrap gap-2"><button class="ds-btn" data-s="Accepté">Accepter</button><button class="ds-btn ds-btn-light" data-s="Liste d\'attente">Liste d’attente</button><button class="ds-btn ds-btn-light" data-s="Refusé">Refuser</button></div></div>';
            $('close').addEventListener('click', closeM);
            $('ecrire').addEventListener('click', async function () { try { var r = await A.api('api/anim_contact.php', { json: { c: c } }); location.href = r.url; } catch (e) { A.toast(e.message, true); } });
            [].forEach.call(body.querySelectorAll('[data-s]'), function (b) {
                b.addEventListener('click', async function () {
                    b.disabled = true;
                    try { await A.api(API + '?a=decider', { json: { c: c, statut: b.dataset.s, note: $('note').value } }); A.toast('Décision enregistrée, l’animateur est prévenu'); closeM(); await refreshList(); render(); }
                    catch (e) { A.toast(e.message, true); b.disabled = false; }
                });
            });
        } catch (e) { body.innerHTML = '<div class="ds-alert-err">' + E(e.message) + '</div><button class="ds-btn mt-4" id="close">Fermer</button>'; $('close').addEventListener('click', closeM); }
    }
    function closeM() { var m = $('modal'); m.classList.add('hidden'); m.classList.remove('flex'); }
    $('modal').addEventListener('click', function (e) { if (e.target === $('modal')) closeM(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeM(); });

    /* ---- Équipe : animateurs recrutés via moncamp.fr + équipe inscrite par lien ---- */
    async function paneEquipe() {
        var pane = $('pane');
        try {
            var d = await A.api(API + '?a=equipe&camp=' + encodeURIComponent(cur.token));
            var pay = function (x) { return x.statut_paiement === 'PAYE' ? 'Payé' : x.statut_paiement === 'GRATUIT' ? 'Gratuit' : 'Paiement en attente'; };
            var html = '';
            if (d.equipe_active) {
                html += '<div class="flex flex-wrap items-center justify-between gap-3 mb-3"><h3 class="font-extrabold text-[#26343b]">Équipe d’animation inscrite (' + d.inscrits.length + (d.equipe_quota ? ' / ' + d.equipe_quota : '') + ')</h3>' +
                    '<a class="ds-btn ds-btn-light" style="padding:8px 14px" href="' + API + '?a=equipe_export&camp=' + encodeURIComponent(cur.token) + '">Exporter (CSV)</a></div>' +
                    (d.inscrits.length ? '<div class="space-y-2">' + d.inscrits.map(function (x) {
                        return '<div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-[#e5e7eb] p-4"><div><p class="font-bold text-[#26343b]">' + E(x.prenom + ' ' + x.nom) + '</p><p class="text-[12px] text-[#6b7780]">' + E(A.BAFA[x.bafa_statut] || '') + (x.tarif_nom ? ' · ' + E(x.tarif_nom) : '') + ' · ' + pay(x) + (Number(x.prix_final) > 0 ? ' (' + A.money(x.prix_final) + ')' : '') + '</p></div>' +
                            '<div class="text-[13px] text-[#3b4a54] text-right"><a class="underline" href="mailto:' + E(x.email) + '">' + E(x.email) + '</a><br>' + E(x.tel || '') + '</div></div>';
                    }).join('') + '</div>' : '<p class="text-[#6b7780]">Personne ne s’est encore inscrit. Créez un lien dans l’onglet « Liens d’inscription ».</p>');
            }
            html += '<h3 class="font-extrabold text-[#26343b] mt-6 mb-3">Animateurs recrutés via moncamp.fr (' + d.recrutes.length + (d.quota ? ' / ' + d.quota : '') + ')</h3>' +
                (d.recrutes.length ? '<div class="space-y-2">' + d.recrutes.map(function (x) {
                    return '<div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-[#e5e7eb] p-4"><div><p class="font-bold text-[#26343b]">' + E(x.prenom + ' ' + x.nom) + '</p><p class="text-[12px] text-[#6b7780]">' + E(A.BAFA[x.bafa_statut || 'aucun']) + '</p></div>' +
                        '<div class="text-[13px] text-[#3b4a54] text-right"><a class="underline" href="mailto:' + E(x.email) + '">' + E(x.email) + '</a><br>' + E(x.tel || '') + '</div></div>';
                }).join('') + '</div>' : '<p class="text-[#6b7780]">Personne n’a encore été accepté.</p>');
            pane.innerHTML = html;
        } catch (e) { pane.innerHTML = '<div class="ds-alert-err">' + E(e.message) + '</div>'; }
    }

    /* ---- Liens d'inscription ---- */
    async function paneLiens() {
        var pane = $('pane');
        try {
            var ls = await A.api(API + '?a=liens&camp=' + encodeURIComponent(cur.token));
            var url = function (t) { return location.origin + location.pathname.replace(/[^\/]*$/, '') + 'equipe-inscription?l=' + t; };
            pane.innerHTML = '<form id="lf" class="grid gap-3 sm:grid-cols-[1fr_130px_120px_auto] items-end rounded-2xl p-4" style="background:#f1f3f5">' +
                '<div><label class="ds-label" for="ll">Libellé</label><input id="ll" class="ds-input" maxlength="120" placeholder="Ex. Équipe été"></div>' +
                '<div><label class="ds-label" for="lt">Type</label><select id="lt" class="ds-select"><option value="prive">Privé</option><option value="public">Public</option></select></div>' +
                '<div><label class="ds-label" for="lm">Max. inscrits</label><input id="lm" type="number" min="1" class="ds-input" placeholder="Illimité"></div>' +
                '<button class="ds-btn" type="submit">Créer un lien</button>' +
                '<div class="sm:col-span-4"><label class="ds-label" for="le">Expire le (facultatif)</label><input id="le" type="date" class="ds-input" style="max-width:220px"></div></form>' +
                '<p class="mt-3 text-[12px] text-[#6b7780]">Privé : jamais répertorié, accessible uniquement à qui reçoit le lien. Public : à partager librement. Dans les deux cas, vous pouvez le révoquer à tout moment et vous validez chaque candidature.</p>' +
                '<div class="mt-5 space-y-2">' + (ls.length ? ls.map(function (l) {
                    var live = Number(l.actif) && !(l.expire_at && new Date(l.expire_at.replace(' ', 'T')) < new Date());
                    return '<div class="rounded-2xl border border-[#e5e7eb] p-4"><div class="flex flex-wrap items-center justify-between gap-2"><p class="font-bold text-[#26343b] flex items-center gap-2">' + A.icon('link') + E(l.libelle || 'Lien sans nom') +
                        ' <span class="ds-badge">' + (l.type === 'public' ? 'Public' : 'Privé') + '</span>' + (live ? '' : ' <span class="ds-badge" style="background:#f3e6e6;color:#7a2b2b">Inactif</span>') + '</p>' +
                        '<span class="text-[12px] text-[#6b7780]">' + l.used + (l.max_uses ? ' / ' + l.max_uses : '') + ' inscrit(s)' + (l.expire_at ? ' · expire le ' + E(A.day(l.expire_at)) : '') + '</span></div>' +
                        (live ? '<div class="mt-3 flex gap-2"><input readonly class="ds-input" style="padding:8px 12px;font-size:13px" value="' + E(url(l.token)) + '"><button class="ds-btn ds-btn-light" style="padding:8px 14px" data-copy="' + E(url(l.token)) + '">Copier</button><button class="ds-btn ds-btn-light" style="padding:8px 14px" data-rev="' + E(l.token) + '">Révoquer</button></div>' : '') + '</div>';
                }).join('') : '<p class="text-[#6b7780]">Aucun lien pour ce séjour.</p>') + '</div>';
            $('lf').addEventListener('submit', async function (e) {
                e.preventDefault();
                try { await A.api(API + '?a=lien_creer', { json: { camp: cur.token, libelle: $('ll').value, type: $('lt').value, max_uses: $('lm').value, expire: $('le').value } }); paneLiens(); }
                catch (er) { A.toast(er.message, true); }
            });
            [].forEach.call(pane.querySelectorAll('[data-copy]'), function (b) { b.addEventListener('click', function () { navigator.clipboard && navigator.clipboard.writeText(b.dataset.copy).then(function () { A.toast('Lien copié'); }); }); });
            [].forEach.call(pane.querySelectorAll('[data-rev]'), function (b) {
                b.addEventListener('click', async function () { if (!(await ColoUI.confirm('Révoquer ce lien ? Il ne fonctionnera plus.', { danger: true, confirmLabel: 'Révoquer' }))) return; await A.api(API + '?a=lien_revoquer', { json: { l: b.dataset.rev } }); paneLiens(); });
            });
        } catch (e) { pane.innerHTML = '<div class="ds-alert-err">' + E(e.message) + '</div>'; }
    }

    /* ---- Réglages : blocs A et B (partials/anim_modes.php) ---- */
    async function paneMode() {
        var pane = $('pane');
        try {
            var cfg = await A.api(API + '?a=config&camp=' + encodeURIComponent(cur.token));
            var root = $('am-root'); $('am-recrute').checked = false;
            pane.innerHTML = '<div id="am-slot"></div><div class="mt-5"><button class="ds-btn" id="am-save" type="button">Enregistrer</button></div>';
            $('am-slot').appendChild(root); AnimModes.load(cfg);
            $('am-save').addEventListener('click', async function () {
                try { await AnimModes.save(cur.token); A.toast('Réglages enregistrés'); await refreshList(); if (!Number(cur.equipe_active) && tab === 'liens') tab = 'mode'; render(); }
                catch (er) { A.toast(er.message, true); }
            });
        } catch (e) { pane.innerHTML = '<div class="ds-alert-err">' + E(e.message) + '</div>'; }
    }
    init();
})();
</script>
<?php require_once 'partials/footer.php'; ?>
