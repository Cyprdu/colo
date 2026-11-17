<?php
// gestion_camp.php : tableau de bord d'un séjour (inscrits, remplissage, parité). Adresse à jeton ; les données viennent de api/get_camp_stats.php (propriétaire uniquement).
require_once 'api/config.php';
require_once 'api/finance_lib.php';
require_once 'api/options.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }
$token = (string)($_GET['t'] ?? '');
if ($token === '') { header('Location: mes_camps.php'); exit; }

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Gestion du séjour - ColoMap</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<main class="ds-page" style="max-width:1200px">
    <div id="state" class="ds-lead py-20 text-center" role="status">Chargement du séjour…</div>

    <div id="content" class="hidden">
        <a href="mes_camps.php" class="text-sm font-semibold text-[#6b7780] hover:text-[#26343b]">&larr; Mes séjours</a>
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mt-2 mb-8">
            <div class="min-w-0"><h1 class="ds-h1" id="title"></h1><p class="ds-lead" id="dates"></p><div id="chips" class="flex flex-wrap gap-2 mt-3"></div></div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="ds-btn ds-btn-light" id="btnCsv">Exporter en CSV</button>
                <button type="button" class="ds-btn ds-btn-light" id="btnShare">Partager</button>
                <a id="btnEdit" href="#" class="ds-btn">Modifier le séjour</a>
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="ds-card p-5"><p class="ds-eyebrow">Chiffre d'affaires</p><p class="text-2xl font-extrabold text-[#26343b] mt-2" id="kCa">0 €</p></div>
            <div class="ds-card p-5"><p class="ds-eyebrow">Places occupées</p><p class="text-2xl font-extrabold text-[#26343b] mt-2"><span id="kIns">0</span> <span class="text-base font-semibold text-[#6b7780]" id="kQuota"></span></p>
                <div class="h-2 bg-[#f1f3f5] rounded-full mt-3 overflow-hidden"><div id="kBar" class="h-2 bg-[#2f3d45] rounded-full" style="width:0%"></div></div></div>
            <div class="ds-card p-5"><p class="ds-eyebrow">Âge moyen</p><p class="text-2xl font-extrabold text-[#26343b] mt-2" id="kAge">0 ans</p></div>
            <div class="ds-card p-5"><p class="ds-eyebrow">Filles / garçons</p><p class="text-2xl font-extrabold text-[#26343b] mt-2"><span id="kF">0</span> <span class="text-[#a9b8c6]">/</span> <span id="kG">0</span></p>
                <p class="text-xs text-[#6b7780] mt-2" id="kQuotaSexe"></p></div>
        </div>

        <div class="ds-card p-6 mb-6"><h2 class="text-lg font-extrabold text-[#26343b] mb-4">Évolution des inscriptions</h2><div class="relative h-56"><canvas id="chart" aria-label="Inscriptions par jour" role="img"></canvas></div></div>

        <div class="ds-card overflow-hidden">
            <div class="p-5 border-b border-[#e5e7eb] flex flex-wrap items-center gap-3">
                <h2 class="text-lg font-extrabold text-[#26343b] mr-auto">Enfants inscrits <span class="text-[#6b7780] font-semibold" id="count"></span></h2>
                <div class="flex gap-2" role="group" aria-label="Filtrer par statut">
                    <button type="button" class="ds-chip !py-2 !px-3 !text-[13px]" data-s="all" aria-pressed="true">Tous</button>
                    <button type="button" class="ds-chip !py-2 !px-3 !text-[13px]" data-s="paid" aria-pressed="false">Payés</button>
                    <button type="button" class="ds-chip !py-2 !px-3 !text-[13px]" data-s="wait" aria-pressed="false">En attente</button>
                </div>
                <input id="search" type="search" class="ds-input !w-52 !py-2" placeholder="Rechercher" aria-label="Rechercher un inscrit">
            </div>
            <div id="rows" class="divide-y divide-[#f1f3f5]"></div>
        </div>
    </div>
</main>

<script>
const TOKEN = <?= json_encode($token) ?>;
const CSRF = <?= json_encode(fin_csrf()) ?>;
const MOYENS = <?= json_encode(array_map(fn($m) => $m[0], options_paiements()), JSON_UNESCAPED_UNICODE) ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
const eur = n => (Number(n) || 0).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
let DATA = null, filt = 'all';

function age(d) { if (!d) return null; const b = new Date(d), n = new Date(); let a = n.getFullYear() - b.getFullYear(); if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) a--; return a; }
function status(i) {
    if (i.expire) return { k: 'expired', t: 'Délai dépassé', cls: 'bg-[#fbeceb] text-[#a4372f]' };
    if (i.statut_paiement === 'EN_ATTENTE') return { k: 'wait', t: 'En attente de paiement' + (i.limite_paiement ? ' · avant le ' + new Date(i.limite_paiement.replace(' ', 'T')).toLocaleDateString('fr-FR') : ''), cls: 'bg-[#f8f5ea] text-[#5b4a1c]' };
    if (i.statut_paiement === 'A_REGLER') return { k: 'wait', t: 'À encaisser', cls: 'bg-[#f8f5ea] text-[#5b4a1c]' };
    return { k: 'paid', t: i.statut_paiement === 'GRATUIT' ? 'Gratuit' : 'Payé', cls: 'bg-[#eef2f4] text-[#26343b]' };
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

function render() {
    if (!DATA) return;
    const q = $('search').value.trim().toLowerCase();
    const list = DATA.liste_inscrits.filter(i => {
        const s = status(i).k;
        if (filt === 'paid' && s !== 'paid') return false;
        if (filt === 'wait' && s !== 'wait' && s !== 'expired') return false;
        return !q || (i.prenom + ' ' + i.nom + ' ' + i.parent_prenom + ' ' + i.parent_nom_famille + ' ' + i.parent_email).toLowerCase().includes(q);
    });
    $('count').textContent = '(' + list.length + ')';
    if (!list.length) { $('rows').innerHTML = '<p class="p-10 text-center ds-lead">Aucun inscrit pour ce filtre.</p>'; return; }
    $('rows').innerHTML = list.map(i => {
        const st = status(i), a = age(i.date_naissance), sante = [i.infos_sante, i.allergies].filter(Boolean).length;
        const ref = i.reservation_token ? 'r=' + encodeURIComponent(i.reservation_token) : 'id=' + encodeURIComponent(i.inscription_id);
        return `<div class="p-5 grid md:grid-cols-[1.2fr_1.4fr_auto_auto] gap-4 items-center ${i.expire ? 'opacity-60' : ''}">
          <div class="flex items-center gap-3 min-w-0"><div class="w-10 h-10 rounded-full bg-[#2f3d45] text-white flex items-center justify-center font-bold flex-none">${esc((i.prenom || '?').charAt(0).toUpperCase())}</div>
            <div class="min-w-0"><p class="font-bold text-[#26343b] truncate">${esc(i.prenom)} ${esc(i.nom)}</p><p class="text-xs text-[#6b7780]">${a !== null ? a + ' ans · ' : ''}${i.sexe === 'Femme' ? 'Fille' : 'Garçon'}${i.tarif_nom ? ' · ' + esc(i.tarif_nom) : ''}</p></div></div>
          <div class="min-w-0 text-sm"><p class="font-semibold text-[#26343b] truncate">${esc(i.parent_prenom)} ${esc(i.parent_nom_famille)}</p>
            <p class="text-xs truncate"><a class="text-[#3d6a8e] hover:underline" href="mailto:${esc(i.parent_email)}">${esc(i.parent_email)}</a>${i.parent_tel ? ' · ' + esc(i.parent_tel) : ''}</p></div>
          <div class="flex flex-wrap gap-2"><span class="text-xs font-bold px-3 py-1 rounded-full ${st.cls}">${esc(st.t)}</span>${moyen(i) ? '<span class="text-xs font-bold px-3 py-1 rounded-full bg-[#eef2f4] text-[#26343b]">' + esc(moyen(i)) + '</span>' : ''}${sante ? '<span class="text-xs font-bold px-3 py-1 rounded-full bg-[#f8f5ea] text-[#5b4a1c]">Santé</span>' : ''}${i.statut_paiement === 'A_REGLER' ? '<button type="button" class="ds-btn ds-btn-sm" data-recu="' + esc(i.reservation_token) + '">Marquer reçu</button>' : ''}</div>
          <a class="ds-btn ds-btn-light ds-btn-sm justify-self-start" href="child_details.php?${ref}&t=${encodeURIComponent(TOKEN)}">Voir le dossier</a></div>`;
    }).join('');
}

function csvCell(v) { v = String(v ?? ''); if (/^[=+\-@\t\r]/.test(v)) v = "'" + v; return '"' + v.replace(/"/g, '""') + '"'; }
$('btnCsv').addEventListener('click', () => {
    if (!DATA.liste_inscrits.length) return ColoUI.alert('Aucun inscrit à exporter pour le moment.');
    const head = ['Date', 'Prénom enfant', 'Nom enfant', 'Sexe', 'Naissance', 'Infos santé', 'Allergies', 'Régime', 'Parent', 'Email', 'Téléphone', 'Tarif', 'Montant payé', 'Moyen de paiement', 'Statut'];
    const rows = DATA.liste_inscrits.map(r => [r.date_inscription, r.prenom, r.nom, r.sexe === 'Femme' ? 'Fille' : 'Garçon', r.date_naissance, r.infos_sante, r.allergies, r.regime_alimentaire,
        (r.parent_prenom || '') + ' ' + (r.parent_nom_famille || ''), r.parent_email, r.parent_tel, r.tarif_nom, r.montant_paye, moyen(r), status(r).t]);
    const csv = '﻿' + [head, ...rows].map(l => l.map(csvCell).join(';')).join('\r\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); a.download = 'inscrits.csv'; a.click(); URL.revokeObjectURL(a.href);
});
$('btnShare').addEventListener('click', async () => {
    const url = location.origin + '/camp_details.php?t=' + DATA.camp.token;
    try { if (navigator.share) { await navigator.share({ title: DATA.camp.nom, url }); return; } } catch (e) { if (e.name === 'AbortError') return; }
    ColoUI.copy(url);
});
document.querySelectorAll('[data-s]').forEach(b => b.addEventListener('click', () => {
    filt = b.dataset.s;
    document.querySelectorAll('[data-s]').forEach(x => { const on = x === b; x.setAttribute('aria-pressed', on); x.style.background = on ? 'var(--ds-btn)' : ''; x.style.color = on ? '#fff' : ''; x.style.borderColor = on ? 'var(--ds-btn)' : ''; });
    render();
}));
document.querySelector('[data-s="all"]').click();
$('search').addEventListener('input', render);
$('rows').addEventListener('click', e => { const b = e.target.closest('[data-recu]'); if (b && confirm('Confirmer que vous avez bien reçu ce règlement ? ColoMap retiendra sa commission sur vos prochains versements par carte.')) marquerRecu(b); });

(async function () {
    try {
        const r = await fetch('api/get_camp_stats.php?t=' + encodeURIComponent(TOKEN));
        if (r.status === 403 || r.status === 404) { location.href = 'mes_camps.php'; return; }
        const d = await r.json(); if (d.error) throw new Error(d.error);
        DATA = d; const c = d.camp, k = d.kpi;
        $('title').textContent = c.nom; $('dates').textContent = c.dates;
        $('btnEdit').href = 'edit_camp.php?t=' + encodeURIComponent(c.token);
        $('kCa').textContent = eur(k.chiffre_affaires);
        $('kIns').textContent = k.nombre_inscrits; $('kQuota').textContent = c.quota > 0 ? '/ ' + c.quota : '';
        $('kBar').style.width = Math.min(100, k.taux_remplissage) + '%';
        $('kAge').textContent = k.age_moyen + ' ans';
        $('kF').textContent = k.parite.filles; $('kG').textContent = k.parite.garcons;
        const qs = []; if (c.quota_fille > 0) qs.push('Quota filles : ' + c.quota_fille); if (c.quota_garcon > 0) qs.push('quota garçons : ' + c.quota_garcon);
        $('kQuotaSexe').textContent = qs.length ? qs.join(' · ') : 'Pas de quota par sexe';
        const chips = [];
        if (c.reserve_sexe) chips.push('Réservé aux ' + (c.reserve_sexe === 'Femme' ? 'filles' : 'garçons'));
        chips.push(c.en_ligne ? 'Inscriptions en ligne' : 'Inscriptions externes');
        if (c.criteres.length) chips.push('Fiche exigée : ' + c.criteres.join(', '));
        if (c.questions) chips.push(c.questions + ' question' + (c.questions > 1 ? 's' : '') + ' personnalisée' + (c.questions > 1 ? 's' : ''));
        $('chips').innerHTML = chips.map(t => `<span class="ds-badge">${esc(t)}</span>`).join('');
        if (window.Chart) new Chart($('chart').getContext('2d'), { type: 'line', data: { labels: d.chart.labels, datasets: [{ label: 'Inscriptions', data: d.chart.data, borderColor: '#2f3d45', backgroundColor: 'rgba(47,61,69,.08)', fill: true, tension: .3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
        render();
        $('state').classList.add('hidden'); $('content').classList.remove('hidden');
    } catch (e) { $('state').textContent = 'Impossible de charger ce séjour : ' + e.message; }
})();
</script>
<?php require_once 'partials/footer.php'; ?>
