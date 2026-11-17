<?php
// gestion_camp.php?t=<jeton d'une session> : page de gestion d'un séjour (aperçu des sessions, inscrits enfants et animateurs, liens, équipe, actions).
// Réservée au propriétaire de l'organisme et à l'administrateur : les données viennent de api/gestion_donnees.php, qui vérifie l'accès.
require_once 'api/config.php';
require_once 'api/finance_lib.php';
require_once 'api/options.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id']) || (empty($_SESSION['user']['is_directeur']) && empty($_SESSION['user']['is_admin']))) { header('Location: index.php'); exit; }
$token = (string)($_GET['t'] ?? '');
if ($token === '') { header('Location: mes_camps.php'); exit; }

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Gestion du séjour - ColoMap</title>
<meta name="robots" content="noindex, nofollow">
<style>
.gm-tab { display: flex; align-items: center; gap: 10px; width: 100%; padding: 11px 14px; border-radius: 12px; font-size: 14.5px; font-weight: 700; color: #3b4a54; text-align: left; transition: background .15s ease; white-space: nowrap; }
.gm-tab:hover { background: var(--ds-soft); }
.gm-tab[aria-selected="true"] { background: var(--ds-btn); color: #fff; }
.gm-tab:focus-visible { outline: 2px solid var(--ds-accent); outline-offset: 2px; }
.gm-tab svg { width: 18px; height: 18px; flex: none; }
.gm-help { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; border: 1.5px solid #b8c2ca; color: #6b7780; font-size: 12px; font-weight: 800; vertical-align: middle; margin-left: 6px; cursor: pointer; }
.gm-help:hover { background: var(--ds-soft); color: var(--ds-ink); }
.gm-tr { cursor: pointer; transition: background .12s ease; }
.gm-tr:hover, .gm-tr:focus-visible { background: #f6f8f9; outline: none; }
.gm-row { display: grid; gap: 12px; align-items: center; padding: 16px 18px; border-top: 1px solid #f1f3f5; }
@media (min-width: 768px) { .gm-row { grid-template-columns: 1.3fr 1.5fr auto; } }
</style>
<main class="ds-page" style="max-width:1240px">
    <div id="state" class="ds-lead py-20 text-center" role="status">Chargement du séjour…</div>
    <div id="content" class="hidden">
        <a href="mes_camps.php" class="text-sm font-semibold text-[#6b7780] hover:text-[#26343b]">&larr; Mes séjours</a>
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mt-2 mb-7">
            <div class="min-w-0"><p class="ds-eyebrow" id="orgaLine"></p><h1 class="ds-h1" id="title"></h1><p class="ds-lead" id="sub"></p></div>
            <div class="flex flex-wrap gap-2">
                <a id="btnEdit" href="#" class="ds-btn">Modifier le séjour</a>
                <a id="btnPublic" href="#" target="_blank" rel="noopener" class="ds-btn ds-btn-light">Page publique</a>
            </div>
        </div>
        <div class="grid lg:grid-cols-[220px_1fr] gap-7 items-start">
            <nav id="tabs" class="flex lg:flex-col gap-1 overflow-x-auto lg:sticky lg:top-24 pb-1" role="tablist" aria-label="Sections de gestion"></nav>
            <div id="panel" class="min-w-0" role="tabpanel"></div>
        </div>
    </div>
</main>

<div id="dosScrim" class="ds-scrim"></div>
<aside id="dosDrawer" class="ds-drawer" style="width:min(560px,100%)" aria-label="Dossier de l'inscrit">
    <div class="flex items-start justify-between gap-3 p-5 border-b border-[#e5e7eb]"><div id="dosHead" class="min-w-0"></div><button type="button" id="dosClose" class="ds-btn ds-btn-light ds-btn-sm flex-none">Fermer</button></div>
    <div id="dosBody" class="flex-1 overflow-y-auto p-5 bg-[#f6f8f9]"></div>
    <div class="p-4 border-t border-[#e5e7eb]"><a id="dosFull" href="#" class="ds-btn w-full hidden">Ouvrir le dossier complet</a></div>
</aside>

<div id="dupModal" class="ds-modal" role="dialog" aria-modal="true" aria-labelledby="dupTitle">
    <div class="ds-modal-box">
        <h3 id="dupTitle" class="text-xl font-extrabold text-[#26343b]">Dupliquer cette session</h3>
        <p class="text-sm text-[#6b7780] mt-1 mb-5">Tout est copié (description, tarifs, places, questions, options…). Choisissez seulement la nouvelle date.</p>
        <form id="dupForm" class="space-y-4" novalidate>
            <p class="text-sm"><span class="text-[#6b7780]">Session copiée :</span> <b id="dupSrc"></b></p>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="ds-label" for="dupDebut">Nouvelle date de début *</label><input id="dupDebut" type="date" class="ds-input" required></div>
                <div><label class="ds-label" for="dupFin">Fin</label><input id="dupFin" type="date" class="ds-input"></div>
            </div>
            <label class="flex items-center gap-2 text-sm font-semibold cursor-pointer"><input type="checkbox" id="dupLieuOn" class="w-4 h-4 accent-[#2f3d45]"> Autre lieu pour cette session</label>
            <div id="dupLieu" class="hidden grid grid-cols-3 gap-3">
                <div class="col-span-3"><label class="ds-label" for="dupAdr">Adresse</label><input id="dupAdr" class="ds-input"></div>
                <div class="col-span-2"><label class="ds-label" for="dupVille">Ville</label><input id="dupVille" class="ds-input"></div>
                <div><label class="ds-label" for="dupCp">Code postal</label><input id="dupCp" class="ds-input"></div>
            </div>
            <p id="dupErr" class="text-sm font-semibold text-[#5b4a1c]" role="alert"></p>
            <div class="flex gap-3"><button type="button" class="ds-btn ds-btn-light flex-1" id="dupCancel">Annuler</button><button type="submit" class="ds-btn flex-1" id="dupOk">Dupliquer</button></div>
        </form>
    </div>
</div>

<script src="assets/js/dossier.js"></script>
<script>
const TOKEN = <?= json_encode($token) ?>;
const CSRF = <?= json_encode(fin_csrf()) ?>;
window.DOSSIER_MOYENS = <?= json_encode(array_map(fn($m) => $m[0], options_paiements()), JSON_UNESCAPED_UNICODE) ?>;
const MOYENS = <?= json_encode(array_map(fn($m) => $m[0], options_paiements()), JSON_UNESCAPED_UNICODE) ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
const eur = n => (Number(n) || 0).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 });
const age = d => { if (!d) return null; const b = new Date(d), n = new Date(); let a = n.getFullYear() - b.getFullYear(); if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) a--; return a; };
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const IC = {
    home: '<path d="M4 11l8-7 8 7v9H4z"/><path d="M10 20v-6h4v6"/>', users: '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 5.2a3 3 0 010 5.6M18 14.3c1.8.8 3 2.6 3 4.7"/>',
    link: '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>', whistle: '<circle cx="9" cy="14" r="5"/><path d="M13 11l8-4v4l-5 2M9 12v2"/>',
    more: '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>'
};
const ico = n => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${IC[n]}</svg>`;
const HELP = {
    sessions: "Chaque session est une édition du séjour avec ses propres dates, âges, places et tarifs. Les familles les voient sur une seule page. Le bouton « Modifier » ouvre le formulaire, avec un onglet par session.",
    attente: "Réservations faites mais pas encore payées : la place est gardée jusqu'à la date limite que vous avez réglée, puis libérée automatiquement.",
    lienAnim: "Ce lien mène à la page du séjour avec le tarif « Animateur ». Selon vos réglages, il est privé (à envoyer à votre équipe) ou affiché sur la page publique.",
    visible: "Désactivée, la session n'apparaît plus dans la recherche : elle reste accessible par son lien."
};
let D = null, I = null, tab = 'apercu', filt = { type: 'enfants', session: '', statut: 'all', q: '' };
const TABS = [['apercu', 'Aperçu', 'home'], ['inscrits', 'Inscrits', 'users'], ['liens', 'Liens et partage', 'link'], ['equipe', 'Équipe', 'whistle'], ['plus', 'Plus', 'more']];
const help = k => `<button type="button" class="gm-help" data-help="${k}" aria-label="Aide">?</button>`;

async function get(a) {
    const r = await fetch('api/gestion_donnees.php?a=' + a + '&t=' + encodeURIComponent(TOKEN)), d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Impossible de charger ces informations.');
    return d;
}
function drawTabs() {
    $('tabs').innerHTML = TABS.map(([k, l, i]) => `<button type="button" role="tab" class="gm-tab" aria-selected="${k === tab}" data-tab="${k}">${ico(i)}${l}</button>`).join('');
}
async function go(k) {
    tab = k; drawTabs();
    if (k === 'inscrits' && !I) { $('panel').innerHTML = '<p class="ds-lead py-10">Chargement…</p>'; try { I = await get('inscrits'); } catch (e) { $('panel').innerHTML = `<p class="ds-alert-err">${esc(ColoUI.friendly(e))}</p>`; return; } }
    $('panel').innerHTML = ({ apercu, inscrits, liens, equipe, plus })[k]();
    if (k === 'inscrits') drawList();
    history.replaceState(null, '', '#' + k);
}
function moyen(i) { return i.moyen_paiement ? (MOYENS[i.moyen_paiement] || i.moyen_paiement) : (i.mode_paiement === 'CARTE' ? 'Carte bancaire' : (i.statut_paiement === 'GRATUIT' ? 'Gratuit' : '')); }
async function marquerRecu(btn) {
    btn.disabled = true;
    try {
        const r = await fetch('api/marquer_recu.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ t: btn.dataset.recu, csrf: CSRF }) });
        const d = await r.json();
        if (!r.ok || d.error) throw new Error(d.error || 'Erreur');
        await ColoUI.alert(d.message); location.reload();
    } catch (e) { btn.disabled = false; ColoUI.alert(e.message); }
}

// ---------------------------------------------------------------- Aperçu
function kpi(l, v, s = '') { return `<div class="ds-card p-5"><p class="ds-eyebrow">${l}</p><p class="text-2xl font-extrabold text-[#26343b] mt-2">${v}</p>${s ? `<p class="text-xs text-[#6b7780] mt-1">${s}</p>` : ''}</div>`; }
function apercu() {
    const S = D.sessions, ins = S.reduce((a, s) => a + s.inscrits, 0), q = S.reduce((a, s) => a + Math.max(0, s.quota), 0), ca = S.reduce((a, s) => a + s.ca, 0), att = S.reduce((a, s) => a + s.attente, 0), an = S.reduce((a, s) => a + s.animateurs, 0);
    const byMonth = {};
    S.forEach(s => { const d = new Date(s.debut + 'T12:00:00'), k = d.getFullYear() * 100 + d.getMonth(); (byMonth[k] = byMonth[k] || []).push(s); });
    const months = Object.keys(byMonth).sort().map(k => {
        const y = Math.floor(k / 100), m = k % 100;
        return `<div><p class="px-5 pt-4 pb-2 text-xs font-extrabold uppercase tracking-wider text-[#6b7780]">${MOIS[m]} ${y}</p>${byMonth[k].map(row).join('')}</div>`;
    }).join('');
    return `<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">${kpi("Chiffre d'affaires", eur(ca))}${kpi('Places occupées', ins + (q ? ' / ' + q : ''), att ? att + ' en attente de paiement' : '')}${kpi('Animateurs inscrits', an)}${kpi('Sessions', S.length)}</div>
    <div class="ds-card overflow-hidden"><div class="flex items-center justify-between gap-3 px-5 py-4"><h2 class="text-lg font-extrabold text-[#26343b]">Sessions${help('sessions')}</h2>
        <button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-dup="${esc(D.camp.principal)}">+ Nouvelle session</button></div>${months}</div>`;
}
function row(s) {
    const pct = s.quota > 0 ? Math.min(100, Math.round(s.inscrits / s.quota * 100)) : 0;
    const etat = !s.valide ? (s.refuse ? 'Refusé' : 'En validation') : (s.fini ? 'Terminé' : '');
    return `<div class="gm-row">
      <div class="min-w-0"><p class="font-bold text-[#26343b]">${esc(s.label)}${s.principal ? ' <span class="ds-badge ml-1">principale</span>' : ''}${etat ? ` <span class="ds-badge ml-1">${etat}</span>` : ''}</p>
        <p class="text-xs text-[#6b7780] mt-0.5">${esc(s.ville)} · ${eur(s.prix)}${s.animateurs ? ' · ' + s.animateurs + ' animateur' + (s.animateurs > 1 ? 's' : '') : ''}</p></div>
      <div><div class="flex justify-between text-xs font-semibold mb-1"><span class="text-[#6b7780]">Inscrits${s.attente ? ' · ' + s.attente + ' en attente' : ''}</span><span>${s.inscrits}${s.quota ? ' / ' + s.quota : ''}</span></div>
        <div class="h-2 bg-[#f1f3f5] rounded-full overflow-hidden"><div class="h-2 bg-[#2f3d45] rounded-full" style="width:${pct}%"></div></div></div>
      <div class="flex flex-wrap items-center gap-2 md:justify-end">
        <label class="ds-switch" title="Visible dans la recherche"><input type="checkbox" ${s.prive ? '' : 'checked'} data-vis="${s.token}" aria-label="Session visible dans la recherche"><span></span></label>
        <a class="ds-btn ds-btn-light ds-btn-sm" href="edit_camp.php?t=${encodeURIComponent(s.token)}">Modifier</a>
        <button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-ins="${s.token}">Inscrits</button>
        <button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-dup="${s.token}">Dupliquer</button></div></div>`;
}

// ---------------------------------------------------------------- Inscrits (enfants et animateurs)
function inscrits() {
    const opt = Object.entries(I.sessions).map(([t, l]) => `<option value="${t}" ${filt.session === t ? 'selected' : ''}>${esc(l)}</option>`).join('');
    return `<div class="ds-card overflow-hidden"><div class="p-5 border-b border-[#e5e7eb] flex flex-wrap items-center gap-3">
      <div class="flex gap-2" role="group" aria-label="Type d'inscrits"><button type="button" class="ds-chip" data-type="enfants" aria-pressed="${filt.type === 'enfants'}">Enfants (${I.enfants.length})</button><button type="button" class="ds-chip" data-type="animateurs" aria-pressed="${filt.type === 'animateurs'}">Animateurs (${I.animateurs.length})</button></div>
      <select id="fSess" class="ds-select !w-auto !py-2" aria-label="Session"><option value="">Toutes les sessions</option>${opt}</select>
      <select id="fStat" class="ds-select !w-auto !py-2 ${filt.type === 'enfants' ? '' : 'hidden'}" aria-label="Statut"><option value="all">Tous les statuts</option><option value="paid" ${filt.statut === 'paid' ? 'selected' : ''}>Payés</option><option value="wait" ${filt.statut === 'wait' ? 'selected' : ''}>En attente de paiement</option></select>
      <input id="fQ" type="search" class="ds-input !w-48 !py-2" placeholder="Rechercher" aria-label="Rechercher" value="${esc(filt.q)}">
      <button type="button" class="ds-btn ds-btn-light ds-btn-sm ml-auto" id="csv">Exporter en CSV</button></div>
      <p id="listCount" class="px-5 pt-3 text-xs font-bold text-[#6b7780]"></p><div id="list"></div></div>`;
}
function stEnfant(i) {
    if (i.expire) return ['Délai dépassé', 'bg-[#fbeceb] text-[#a4372f]', 'expired'];
    if (i.statut_paiement === 'EN_ATTENTE') return ['En attente de paiement' + (i.limite_paiement ? ' · avant le ' + new Date(i.limite_paiement.replace(' ', 'T')).toLocaleDateString('fr-FR') : ''), 'bg-[#f8f5ea] text-[#5b4a1c]', 'wait'];
    if (i.statut_paiement === 'A_REGLER') return ['À encaisser', 'bg-[#f8f5ea] text-[#5b4a1c]', 'wait'];
    return [i.statut_paiement === 'GRATUIT' ? 'Gratuit' : 'Payé', 'bg-[#eef2f4] text-[#26343b]', 'paid'];
}
function stAnim(a) { const p = Number(a.prix_final) > 0 ? (a.statut_paiement === 'PAYE' ? 'Payé' : 'À payer ' + eur(a.prix_final)) : 'Gratuit'; return [a.statut || '—', p]; }
function filtered() {
    const q = filt.q.toLowerCase(), sess = filt.session;
    if (filt.type === 'enfants') return I.enfants.filter(i => (!sess || i.session_token === sess) && (filt.statut === 'all' || (filt.statut === 'paid' ? stEnfant(i)[2] === 'paid' : ['wait', 'expired'].includes(stEnfant(i)[2]))) && (!q || (i.prenom + ' ' + i.nom + ' ' + i.parent_prenom + ' ' + i.parent_nom_famille + ' ' + i.parent_email).toLowerCase().includes(q)));
    return I.animateurs.filter(a => (!sess || a.session_token === sess) && (!q || (a.prenom + ' ' + a.nom + ' ' + a.email).toLowerCase().includes(q)));
}
function drawList() {
    const L = filtered(); $('listCount').textContent = L.length + (filt.type === 'enfants' ? ' enfant' : ' animateur') + (L.length > 1 ? 's' : '');
    if (!L.length) { $('list').innerHTML = '<p class="p-10 text-center ds-lead">Aucun inscrit pour ce filtre.</p>'; return; }
    const th = (l, c = '') => `<th scope="col" class="px-4 py-2.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-[#6b7780] whitespace-nowrap ${c}">${l}</th>`;
    const pill = (t, c) => `<span class="inline-block text-xs font-bold px-2.5 py-1 rounded-full whitespace-nowrap ${c}">${esc(t)}</span>`;
    const td = (h, c = '') => `<td class="px-4 py-3 align-middle ${c}">${h}</td>`;
    const open = (k, v, l) => `data-open="${esc(k)}:${esc(v)}" tabindex="0" role="button" aria-label="Ouvrir le dossier de ${esc(l)}"`;
    if (filt.type === 'enfants') {
        $('list').innerHTML = `<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-[#f6f8f9] border-y border-[#e5e7eb]"><tr>${th('Enfant')}${th('Âge')}${th('Parent', 'hidden md:table-cell')}${th('Session', 'hidden lg:table-cell')}${th('Tarif', 'hidden lg:table-cell')}${th('Paiement')}${th('Statut')}</tr></thead><tbody class="divide-y divide-[#f1f3f5]">${L.map(i => {
            const st = stEnfant(i), a = age(i.date_naissance), sante = [i.infos_sante, i.allergies].some(Boolean), m = moyen(i);
            return `<tr class="gm-tr ${i.expire ? 'opacity-60' : ''}" ${open('r', i.reservation_token, i.prenom + ' ' + i.nom)}>
              ${td(`<span class="font-bold text-[#26343b]">${esc(i.prenom)} ${esc(i.nom)}</span>${sante ? ` <span class="inline-block align-middle text-[11px] font-bold px-2 py-0.5 rounded-full bg-[#f8f5ea] text-[#5b4a1c] border border-[#ebe3c8]" title="À consulter dans le dossier : ${i.allergies ? 'allergies' : 'informations de santé'} renseignées par les parents">${i.allergies ? 'Allergie' : 'Santé'}</span>` : ''}`)}
              ${td(a !== null ? a + ' ans' : '—', 'whitespace-nowrap')}
              ${td(`<span class="block truncate max-w-[200px]">${esc(i.parent_prenom)} ${esc(i.parent_nom_famille)}</span>`, 'hidden md:table-cell')}
              ${td(`<span class="block text-xs text-[#6b7780] max-w-[170px]">${esc(I.sessions[i.session_token] || '')}</span>`, 'hidden lg:table-cell')}
              ${td(esc(i.tarif_nom || '—'), 'hidden lg:table-cell')}
              ${td(`${esc(m || '—')}${i.montant_paye > 0 ? `<span class="block text-xs text-[#6b7780]">${eur(i.montant_paye)}</span>` : ''}`)}
              ${td(`<span class="flex items-center gap-3 flex-wrap">${pill(st[0].split(' · ')[0], st[1])}${i.statut_paiement === 'A_REGLER' ? `<button type="button" class="text-xs font-bold text-[#3d6a8e] underline hover:text-[#26343b]" data-recu="${esc(i.reservation_token)}">Marquer reçu</button>` : ''}</span>`)}</tr>`;
        }).join('')}</tbody></table></div>`;
    } else {
        $('list').innerHTML = `<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-[#f6f8f9] border-y border-[#e5e7eb]"><tr>${th('Animateur')}${th('Âge')}${th('Contact', 'hidden md:table-cell')}${th('BAFA', 'hidden md:table-cell')}${th('Session', 'hidden lg:table-cell')}${th('Tarif', 'hidden lg:table-cell')}${th('Paiement')}${th('Statut')}</tr></thead><tbody class="divide-y divide-[#f1f3f5]">${L.map(a => {
            const [s, p] = stAnim(a);
            return `<tr class="gm-tr" ${open('a', a.token, a.prenom + ' ' + a.nom)}>
              ${td(`<span class="font-bold text-[#26343b]">${esc(a.prenom)} ${esc(a.nom)}</span>`)}
              ${td(a.date_naissance ? age(a.date_naissance) + ' ans' : '—', 'whitespace-nowrap')}
              ${td(`<span class="block truncate max-w-[220px] text-xs">${esc(a.email)}</span>`, 'hidden md:table-cell')}
              ${td(esc(a.bafa_statut || 'aucun'), 'hidden md:table-cell')}
              ${td(`<span class="block text-xs text-[#6b7780] max-w-[170px]">${esc(I.sessions[a.session_token] || '')}</span>`, 'hidden lg:table-cell')}
              ${td(esc(a.tarif_nom || '—'), 'hidden lg:table-cell')}
              ${td(esc(p))}${td(pill(s, 'bg-[#eef2f4] text-[#26343b]'))}</tr>`;
        }).join('')}</tbody></table></div>`;
    }
}
// Panneau latéral : dossier complet (api/dossier_inscrit.php, rendu par assets/js/dossier.js)
async function openDossier(kind, tok) {
    $('dosBody').innerHTML = '<p class="ds-lead py-10 text-center">Chargement…</p>'; $('dosHead').innerHTML = ''; $('dosFull').classList.add('hidden');
    $('dosDrawer').classList.add('open'); $('dosScrim').classList.add('open'); $('dosClose').focus();
    try {
        const r = await fetch('api/dossier_inscrit.php?' + kind + '=' + encodeURIComponent(tok)), d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Dossier indisponible.');
        $('dosHead').innerHTML = Dossier.head(d); $('dosBody').innerHTML = Dossier.stack(d, { recu: true });
        if (d.type === 'enfant') { $('dosFull').href = 'child_details.php?r=' + encodeURIComponent(d.reservation) + '&t=' + encodeURIComponent(d.session_token); $('dosFull').classList.remove('hidden'); }
    } catch (e) { $('dosBody').innerHTML = `<p class="ds-alert-err">${esc(ColoUI.friendly(e))}</p>`; }
}
function closeDossier() { $('dosDrawer').classList.remove('open'); $('dosScrim').classList.remove('open'); }
function csvCell(v) { v = String(v ?? ''); if (/^[=+\-@\t\r]/.test(v)) v = "'" + v; return '"' + v.replace(/"/g, '""') + '"'; }
function exportCsv() {
    const L = filtered(); if (!L.length) { ColoUI.alert('Aucun inscrit à exporter pour ce filtre.'); return; }
    let head, rows;
    if (filt.type === 'enfants') {
        head = ['Session', 'Date', 'Prénom enfant', 'Nom enfant', 'Sexe', 'Naissance', 'Infos santé', 'Allergies', 'Régime', 'Parent', 'Email', 'Téléphone', 'Tarif', 'Montant payé', 'Moyen de paiement', 'Statut'];
        rows = L.map(r => [I.sessions[r.session_token], r.date_inscription, r.prenom, r.nom, r.sexe === 'Femme' ? 'Fille' : 'Garçon', r.date_naissance, r.infos_sante, r.allergies, r.regime_alimentaire, (r.parent_prenom || '') + ' ' + (r.parent_nom_famille || ''), r.parent_email, r.parent_tel, r.tarif_nom, r.montant_paye, moyen(r), stEnfant(r)[0]]);
    } else {
        const libs = [...new Set(L.flatMap(a => (a.reponses || []).map(x => x.libelle)))];
        head = ['Session', 'Inscription', 'Prénom', 'Nom', 'Naissance', 'Email', 'Téléphone', 'BAFA', 'Diplôme', 'Allergies', 'Régime', 'Contact urgence', 'Tel urgence', 'Tarif', 'Prix', 'Payé', 'Statut', 'Paiement', ...libs];
        rows = L.map(a => [I.sessions[a.session_token], a.created_at, a.prenom, a.nom, a.date_naissance, a.email, a.tel, a.bafa_statut, a.diplome, a.allergies, a.regime, a.urgence_nom, a.urgence_tel, a.tarif_nom, a.prix_final, a.montant_paye, a.statut, a.statut_paiement, ...libs.map(l => ((a.reponses || []).find(x => x.libelle === l) || {}).valeur || '')]);
    }
    const csv = '﻿' + [head, ...rows].map(l => l.map(csvCell).join(';')).join('\r\n');
    const el = document.createElement('a'); el.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); el.download = (filt.type === 'enfants' ? 'inscrits-enfants' : 'inscrits-animateurs') + '.csv'; el.click(); URL.revokeObjectURL(el.href);
}

// ---------------------------------------------------------------- Liens, équipe, plus
function lienBloc(titre, url, extra = '') {
    return `<div class="ds-card p-5"><p class="font-bold text-[#26343b]">${titre}</p><div class="flex flex-wrap gap-2 mt-3"><input class="ds-input flex-1 min-w-[220px] !py-2 text-sm" readonly value="${esc(url)}" aria-label="${esc(titre)}" onfocus="this.select()"><button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-copy="${esc(url)}">Copier</button><button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-share="${esc(url)}">Partager</button></div>${extra}</div>`;
}
function liens() {
    const pub = location.origin + '/camp_details.php?t=' + D.camp.principal;
    const eq = D.equipe ? location.origin + '/camp_details.php?t=' + D.equipe.token + '&l=' + D.equipe.lien : null;
    return `<div class="space-y-4">${lienBloc('Page publique du séjour', pub, '<p class="ds-hint">Un seul lien pour toutes les sessions : les familles choisissent leur session sur la page.</p>')}
      ${eq ? lienBloc('Lien animateurs' + help('lienAnim'), eq, `<p class="ds-hint">${D.equipe.public ? 'Lien public : le tarif animateur est aussi affiché sur la page du séjour.' : 'Lien privé : à envoyer uniquement à votre équipe.'}</p>`)
           : `<div class="ds-card p-5"><p class="font-bold text-[#26343b]">Lien animateurs${help('lienAnim')}</p><p class="text-sm text-[#6b7780] mt-1">L'inscription de votre équipe n'est pas activée.</p><a class="ds-btn ds-btn-light ds-btn-sm mt-3" href="edit_camp.php?t=${encodeURIComponent(D.camp.principal)}#s-anim">Activer dans « Animateurs »</a></div>`}
      <div class="ds-card p-5"><p class="font-bold text-[#26343b]">Une session précise</p><div class="mt-3 space-y-2">${D.sessions.map(s => `<div class="flex flex-wrap items-center justify-between gap-2 text-sm"><span>${esc(s.label)}</span><span class="flex gap-2"><button type="button" class="ds-btn ds-btn-light ds-btn-sm" data-copy="${esc(location.origin + '/camp_details.php?t=' + s.token)}">Copier le lien</button></span></div>`).join('')}</div></div></div>`;
}
function equipe() {
    const an = D.sessions.reduce((a, s) => a + s.animateurs, 0);
    return `<div class="grid sm:grid-cols-2 gap-4">
      <div class="ds-card p-5"><p class="ds-eyebrow">Animateurs inscrits</p><p class="text-2xl font-extrabold text-[#26343b] mt-2">${an}</p><button type="button" class="ds-btn ds-btn-light ds-btn-sm mt-3" data-goto="inscrits-anim">Voir la liste</button></div>
      <div class="ds-card p-5"><p class="font-bold text-[#26343b]">Infos pour mon équipe</p><p class="text-sm text-[#6b7780] mt-1">Message, programme, documents, date de versement.</p><a class="ds-btn ds-btn-light ds-btn-sm mt-3" href="equipe_gestion.php?t=${encodeURIComponent(D.camp.principal)}">Ouvrir</a></div>
      <div class="ds-card p-5"><p class="font-bold text-[#26343b]">Réglages animateurs</p><p class="text-sm text-[#6b7780] mt-1">Recrutement via ColoMap, tarifs et questions de l'équipe, lien privé ou public.</p><a class="ds-btn ds-btn-light ds-btn-sm mt-3" href="edit_camp.php?t=${encodeURIComponent(D.camp.principal)}#s-anim">Modifier</a></div>
      <div class="ds-card p-5"><p class="font-bold text-[#26343b]">Candidatures</p><p class="text-sm text-[#6b7780] mt-1">Animateurs qui postulent via ColoMap.</p><a class="ds-btn ds-btn-light ds-btn-sm mt-3" href="demandes-animation.php">Voir les candidatures</a></div></div>`;
}
function plus() {
    const card = (t, d, href, l) => `<div class="ds-card p-5"><p class="font-bold text-[#26343b]">${t}</p><p class="text-sm text-[#6b7780] mt-1">${d}</p><a class="ds-btn ds-btn-light ds-btn-sm mt-3" href="${href}">${l}</a></div>`;
    return `<div class="grid sm:grid-cols-2 gap-4">
      ${card('Booster le séjour', "Passez en tête de la recherche ou affichez « dernière minute ».", 'boost.php?t=' + encodeURIComponent(D.camp.principal), 'Booster')}
      ${card('Messages', 'Répondez aux familles qui vous ont écrit.', 'messagerie.php', 'Ouvrir la messagerie')}
      ${card('Modifier le séjour', "Toutes les informations, session par session.", 'edit_camp.php?t=' + encodeURIComponent(D.camp.principal), 'Modifier')}
      <div class="ds-card p-5"><p class="font-bold text-[#26343b]">Liste d'attente${help('attente')}</p><p class="text-sm text-[#6b7780] mt-1">${D.sessions.reduce((a, s) => a + s.attente, 0)} réservation(s) en attente de paiement.</p><button type="button" class="ds-btn ds-btn-light ds-btn-sm mt-3" data-goto="inscrits-wait">Voir</button></div></div>
      <div class="ds-card p-5 mt-4 border-[#f0d6d3]"><p class="font-bold text-[#a4372f]">Zone sensible</p><p class="text-sm text-[#6b7780] mt-1">La suppression retire la session du site. Les familles inscrites doivent être remboursées.</p>
        <div class="mt-3 space-y-2">${D.sessions.map(s => `<div class="flex flex-wrap items-center justify-between gap-2 text-sm"><span>${esc(s.label)} · ${s.inscrits} inscrit${s.inscrits > 1 ? 's' : ''}</span><button type="button" class="ds-btn ds-btn-light ds-btn-sm" style="color:#a4372f" data-del="${s.token}">Supprimer</button></div>`).join('')}</div></div>`;
}

// ---------------------------------------------------------------- actions
let dupSrc = null;
function openDup(tok) {
    const s = D.sessions.find(x => x.token === tok); if (!s) return; dupSrc = s;
    $('dupSrc').textContent = s.label;
    const last = D.sessions.map(x => x.debut).sort().pop(), d = new Date(last + 'T12:00:00'); d.setDate(d.getDate() + 7);
    const nights = Math.round((new Date(s.fin) - new Date(s.debut)) / 86400000); $('dupDebut').value = d.toISOString().slice(0, 10);
    const e = new Date(d); e.setDate(e.getDate() + nights); $('dupFin').value = e.toISOString().slice(0, 10);
    $('dupDebut').dataset.n = nights; $('dupLieuOn').checked = false; $('dupLieu').classList.add('hidden'); $('dupAdr').value = $('dupVille').value = $('dupCp').value = ''; $('dupErr').textContent = '';
    $('dupModal').classList.add('open'); setTimeout(() => $('dupDebut').focus(), 60);
}
async function post(url, body) { const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }), d = await r.json().catch(() => ({})); if (!r.ok || d.success === false) throw new Error(d.error || d.message || 'Une erreur est survenue.'); return d; }
async function reload() { D = await get('apercu'); I = null; go(tab); }
async function supprimer(tok) {
    const s = D.sessions.find(x => x.token === tok);
    const msg = 'Supprimer la session « ' + s.label + ' » ?' + (s.inscrits ? '\n\n' + s.inscrits + ' famille(s) y sont inscrites : en confirmant, vous vous engagez à les rembourser intégralement.' : '');
    if (!(await ColoUI.confirm(msg, { danger: true, confirmLabel: 'Supprimer', title: 'Supprimer la session' }))) return;
    const fd = new URLSearchParams({ token: tok });
    try {
        const r = await fetch('api/delete_camp.php', { method: 'POST', body: fd }), d = await r.json();
        if (!d.success) throw new Error(d.message);
        if (D.sessions.length === 1) { location.href = 'mes_camps.php'; return; }
        ColoUI.toast('Session supprimée.', { tone: 'ok' }); await reload();
    } catch (e) { ColoUI.error(e); }
}
document.addEventListener('click', async e => {
    const t = e.target.closest('button, a');
    if (!t) { const row = e.target.closest('[data-open]'); if (row) { const [k, v] = row.dataset.open.split(':'); openDossier(k, v); } return; }
    if (t.id === 'dosClose') return closeDossier();
    if (t.dataset.tab) go(t.dataset.tab);
    else if (t.dataset.recu) { if (await ColoUI.confirm('Confirmer que vous avez bien reçu ce règlement ? ColoMap retiendra sa commission sur vos prochains versements par carte.')) marquerRecu(t); }
    else if (t.dataset.help) ColoUI.alert(HELP[t.dataset.help], { title: 'Aide' });
    else if (t.dataset.dup) openDup(t.dataset.dup);
    else if (t.dataset.ins) { filt.session = t.dataset.ins; filt.type = 'enfants'; go('inscrits'); }
    else if (t.dataset.copy) ColoUI.copy(t.dataset.copy);
    else if (t.dataset.share) { try { if (navigator.share) { await navigator.share({ url: t.dataset.share }); return; } } catch (x) { if (x.name === 'AbortError') return; } ColoUI.copy(t.dataset.share); }
    else if (t.dataset.del) supprimer(t.dataset.del);
    else if (t.dataset.goto) { if (t.dataset.goto === 'inscrits-anim') filt.type = 'animateurs'; else { filt.type = 'enfants'; filt.statut = 'wait'; } go('inscrits'); }
    else if (t.dataset.type) { filt.type = t.dataset.type; go('inscrits'); }
    else if (t.id === 'csv') exportCsv();
});
document.addEventListener('change', async e => {
    const t = e.target;
    if (t.dataset.vis) {
        const on = t.checked;
        try { const r = await fetch('api/update_camp_visibility.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'token=' + encodeURIComponent(t.dataset.vis) + '&prive=' + (on ? 0 : 1) }), d = await r.json(); if (!d.success) throw 0; ColoUI.toast(on ? 'Session visible dans la recherche.' : 'Session masquée de la recherche.', { tone: 'ok' }); D.sessions.find(s => s.token === t.dataset.vis).prive = !on; }
        catch (x) { t.checked = !on; ColoUI.error('Mise à jour impossible.'); }
    }
    else if (t.id === 'fSess') { filt.session = t.value; drawList(); }
    else if (t.id === 'fStat') { filt.statut = t.value; drawList(); }
});
document.addEventListener('input', e => { if (e.target.id === 'fQ') { filt.q = e.target.value; drawList(); } });
$('dupDebut').addEventListener('change', () => { const n = +$('dupDebut').dataset.n || 0, d = new Date($('dupDebut').value + 'T12:00:00'); if (!isNaN(d)) { d.setDate(d.getDate() + n); $('dupFin').value = d.toISOString().slice(0, 10); } });
$('dupLieuOn').addEventListener('change', () => $('dupLieu').classList.toggle('hidden', !$('dupLieuOn').checked));
$('dupCancel').addEventListener('click', () => $('dupModal').classList.remove('open'));
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { $('dupModal').classList.remove('open'); closeDossier(); }
    if (e.key === 'Enter' && e.target.dataset && e.target.dataset.open) { const [k, v] = e.target.dataset.open.split(':'); openDossier(k, v); }
});
$('dosScrim').addEventListener('click', closeDossier);
$('dupForm').addEventListener('submit', async e => {
    e.preventDefault(); $('dupErr').textContent = ''; $('dupOk').disabled = true;
    try {
        if (!$('dupDebut').value) throw new Error('Choisissez la date de début.');
        const b = { token: dupSrc.token, date_debut: $('dupDebut').value, date_fin: $('dupFin').value || $('dupDebut').value };
        if ($('dupLieuOn').checked) { b.adresse = $('dupAdr').value; b.ville = $('dupVille').value; b.code_postal = $('dupCp').value; }
        await post('api/add_session.php', b);
        $('dupModal').classList.remove('open'); ColoUI.toast('Session dupliquée.', { tone: 'ok' }); await reload();
    } catch (x) { $('dupErr').textContent = ColoUI.friendly(x); }
    $('dupOk').disabled = false;
});

(async function () {
    try {
        D = await get('apercu');
        $('title').textContent = D.camp.nom; $('sub').textContent = D.camp.ville + ' · ' + D.sessions.length + ' session' + (D.sessions.length > 1 ? 's' : '');
        $('orgaLine').textContent = D.acces === 'admin' ? 'Vue administrateur' : 'Gestion du séjour';
        $('btnEdit').href = 'edit_camp.php?t=' + encodeURIComponent(TOKEN); $('btnPublic').href = 'camp_details.php?t=' + encodeURIComponent(D.camp.principal);
        filt.session = ''; $('state').classList.add('hidden'); $('content').classList.remove('hidden');
        const h = location.hash.slice(1); tab = TABS.some(x => x[0] === h) ? h : 'apercu';
        // arrivée depuis « Inscrits » d'une session précise : ?s=<jeton de session>
        const sp = new URLSearchParams(location.search).get('s'); if (sp) { filt.session = sp; tab = 'inscrits'; }
        go(tab);
    } catch (e) { $('state').textContent = ColoUI.friendly(e, 'Impossible de charger ce séjour.'); }
})();
</script>
<?php require_once 'partials/footer.php'; ?>
