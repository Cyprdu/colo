<?php
// recherche.php : la page de recherche de séjours (rendu des résultats côté navigateur, filtres synchronisés avec l'adresse).
$ads_enabled = true;
$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/holidays.php';

$is_logged_in = isset($_SESSION['user']);
$is_animateur = $is_logged_in && ($_SESSION['user']['is_animateur'] ?? false);
$user_favorites = $_SESSION['user']['favorites'] ?? [];
$q = trim((string)($_GET['name'] ?? ''));
?>
<title>Rechercher une colonie de vacances - ColoMap</title>
<meta name="description" content="Comparez les colonies de vacances, camps et stages pour enfants et adolescents : filtrez par ville, âge, dates, budget et thématique, puis réservez en ligne.">

<style>
    :root { --ink:#26343b; --accent:#3d6a8e; --accent-dark:#2d5170; --cream:#f8f5ea; --muted:#6b7780; --line:#e5e7eb; }
    .rs-hero { background: linear-gradient(135deg, #2f4a57 0%, #6f8f93 55%, #c9d6d3 100%); }
    /* barre de recherche */
    .sbar { background:#fff; border-radius:9999px; box-shadow:0 12px 40px rgba(18,55,42,.22); display:flex; align-items:stretch; }
    .sbar-field { flex:1 1 0; min-width:0; padding:10px 22px; display:flex; flex-direction:column; justify-content:center; border-radius:9999px; transition:background .15s ease; }
    .sbar-field:hover, .sbar-field:focus-within { background:#f1f3f5; }
    .sbar-field + .sbar-field { position:relative; }
    .sbar-field + .sbar-field::before { content:""; position:absolute; left:0; top:22%; bottom:22%; width:1px; background:var(--line); }
    .sbar-field:hover::before, .sbar-field:focus-within::before, .sbar-field:hover + .sbar-field::before, .sbar-field:focus-within + .sbar-field::before { opacity:0; }
    .sbar-label { font-size:12px; font-weight:700; color:var(--ink); letter-spacing:.01em; }
    .sbar-input { width:100%; border:0; outline:0; background:transparent; font-size:15px; color:#4b5964; padding:2px 0 0; }
    .sbar-input::placeholder { color:#9aa3ab; }
    .sbar-go { margin:6px; padding:0 26px; border-radius:9999px; background:var(--ink); color:#fff; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px; transition:background .2s ease; }
    .sbar-go:hover { background:#1f2a30; }
    @media (max-width: 767px) {
        .sbar { flex-direction:column; border-radius:24px; padding:6px; }
        .sbar-field { padding:10px 16px; border-radius:16px; }
        .sbar-field + .sbar-field::before { left:16px; right:16px; top:0; bottom:auto; width:auto; height:1px; }
        .sbar-go { margin:6px 0 0; padding:14px; justify-content:center; }
    }
    /* catégories */
    .catrow { display:flex; gap:10px; overflow-x:auto; scrollbar-width:none; padding:2px 2px 6px; }
    .catrow::-webkit-scrollbar { display:none; }
    .cat { flex:none; padding:9px 16px; border-radius:9999px; border:1.5px solid var(--line); background:#fff; color:#3b4a54; font-size:14px; font-weight:600; white-space:nowrap; transition:all .15s ease; }
    .cat:hover { border-color:#a9b8c6; background:#f6f8fa; }
    .cat.is-on { background:var(--ink); border-color:var(--ink); color:#fff; }
    /* filtres */
    .fsec { padding:20px 0; border-top:1px solid var(--line); }
    .fsec:first-of-type { border-top:0; padding-top:0; }
    .ftitle { font-size:14px; font-weight:800; color:var(--ink); margin-bottom:12px; }
    .finput { width:100%; border:1.5px solid var(--line); border-radius:12px; padding:10px 12px; font-size:14px; background:#fff; color:var(--ink); transition:border-color .15s ease, box-shadow .15s ease; }
    .finput:focus { outline:0; border-color:var(--accent); box-shadow:0 0 0 3px rgba(61,106,142,.15); }
    .pill { padding:8px 14px; border-radius:9999px; border:1.5px solid var(--line); background:#fff; font-size:13px; font-weight:600; color:#3b4a54; transition:all .15s ease; text-align:left; }
    .pill:hover { border-color:#a9b8c6; }
    .pill.is-on { border-color:var(--ink); background:#eef2f4; color:var(--ink); }
    .rng { -webkit-appearance:none; appearance:none; width:100%; height:6px; border-radius:9999px; background:#e5e7eb; outline:0; }
    .rng::-webkit-slider-thumb { -webkit-appearance:none; width:22px; height:22px; border-radius:9999px; background:var(--ink); border:3px solid #fff; box-shadow:0 1px 5px rgba(0,0,0,.3); cursor:pointer; }
    .rng::-moz-range-thumb { width:18px; height:18px; border-radius:9999px; background:var(--ink); border:3px solid #fff; box-shadow:0 1px 5px rgba(0,0,0,.3); cursor:pointer; }
    .chk { display:flex; align-items:center; gap:10px; padding:6px 4px; border-radius:8px; font-size:14px; color:#3b4a54; cursor:pointer; }
    .chk:hover { background:#f6f8fa; }
    .chk input { width:17px; height:17px; accent-color:var(--ink); flex:none; }
    .tlist { max-height:220px; overflow-y:auto; scrollbar-width:thin; }
    /* résultats */
    .rgrid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:22px 16px; }
    @media (min-width:768px) { .rgrid { grid-template-columns:repeat(3, minmax(0,1fr)); gap:28px 20px; } }
    @media (min-width:1280px) { .rgrid { grid-template-columns:repeat(4, minmax(0,1fr)); } }
    .camp-card { display:flex; flex-direction:column; min-width:0; }
    .camp-org { height:28px; display:flex; align-items:center; gap:8px; margin-bottom:8px; }
    .camp-avatar { width:24px; height:24px; border-radius:9999px; flex:none; overflow:hidden; background:#e5e7eb; display:flex; align-items:center; justify-content:center; }
    .camp-avatar img { width:100%; height:100%; object-fit:cover; }
    .camp-org-name { font-size:13px; color:#3b4a54; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .camp-mediawrap { position:relative; width:100%; aspect-ratio:1/1; border-radius:18px; overflow:hidden; background:#e5e7eb; }
    .camp-media { position:absolute; inset:0; display:block; }
    .camp-media img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
    .camp-card:hover .camp-media img { transform:scale(1.04); }
    .camp-badge { position:absolute; top:10px; left:10px; z-index:2; background:#fff; color:var(--ink); font-size:12px; font-weight:600; padding:5px 10px; border-radius:9999px; line-height:1; box-shadow:0 1px 3px rgba(0,0,0,.12); }
    .camp-age { position:absolute; bottom:10px; left:10px; z-index:2; background:rgba(38,52,59,.82); color:#fff; font-size:12px; font-weight:600; padding:5px 10px; border-radius:9999px; line-height:1; backdrop-filter:blur(4px); }
    .fav-btn { position:absolute; top:10px; right:10px; z-index:2; width:34px; height:34px; border-radius:9999px; background:#fff; color:var(--ink); display:flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.15); transition:transform .15s ease; }
    .fav-btn:hover { transform:scale(1.08); } .fav-btn:active { transform:scale(.92); }
    .fav-btn svg path { fill:none; stroke:currentColor; stroke-width:1.8; transition:fill .15s ease, stroke .15s ease; }
    .fav-btn.is-fav { color:#e5484d; } .fav-btn.is-fav svg path { fill:#e5484d; }
    .camp-title { margin-top:12px; font-size:15.5px; font-weight:600; line-height:1.3; color:var(--ink); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:2.6em; }
    .camp-price { margin-top:4px; font-size:16px; color:var(--ink); }
    .camp-price b { font-weight:800; }
    .camp-meta { font-size:13px; color:var(--muted); line-height:19px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .skeleton { background:linear-gradient(90deg,#eceef1 25%,#f8f5ea 37%,#eceef1 63%); background-size:400% 100%; animation:sk 1.4s ease infinite; }
    @keyframes sk { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }
    .fade-in-anim { animation:fadeIn .35s ease-out both; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
    @media (prefers-reduced-motion: reduce) { .fade-in-anim, .skeleton { animation:none; } .camp-media img { transition:none; } }
    .tag { display:inline-flex; align-items:center; gap:6px; padding:6px 8px 6px 12px; border-radius:9999px; background:#eef2f4; color:var(--ink); font-size:13px; font-weight:600; }
    .tag button { width:20px; height:20px; border-radius:9999px; display:flex; align-items:center; justify-content:center; color:#6b7780; }
    .tag button:hover { background:#d3dbe0; color:var(--ink); }
    /* panneau de filtres : colonne à gauche (écran large), volet plein écran sinon */
    #filters { background:#fff; }
    @media (max-width:1023px) {
        #filters { position:fixed; inset:0; z-index:70; transform:translateY(100%); transition:transform .3s ease; display:flex; flex-direction:column; border-radius:24px 24px 0 0; top:8vh; box-shadow:0 -20px 60px rgba(0,0,0,.3); }
        #filters.is-open { transform:none; }
        #filters .fbody { flex:1; overflow-y:auto; padding:0 20px; }
    }
    @media (min-width:1024px) { #filters .fhead, #filters .ffoot { display:none; } }
</style>

<input type="hidden" id="f-name" value="<?= htmlspecialchars($q) ?>">

<main class="max-w-[1280px] mx-auto px-5 sm:px-8 py-8">
    <!-- catégories -->
    <div class="catrow" id="catrow" role="group" aria-label="Catégories"></div>

    <div class="mt-6 flex flex-col lg:flex-row gap-10">
        <!-- ================================ FILTRES ================================ -->
        <aside id="filters" class="lg:w-[280px] lg:flex-none lg:sticky lg:top-24 lg:self-start" aria-label="Filtres">
            <div class="fhead flex items-center justify-between px-5 py-4 border-b border-[#e5e7eb]">
                <h2 class="text-[18px] font-extrabold text-[#26343b]">Filtres</h2>
                <button type="button" id="f-close" class="w-10 h-10 rounded-full hover:bg-[#f1f3f5] flex items-center justify-center" aria-label="Fermer les filtres"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 6l12 12M18 6L6 18" stroke-linecap="round"/></svg></button>
            </div>
            <div class="fbody">
                <div class="fsec">
                    <p class="ftitle">Localisation</p>
                    <input id="f-city" type="text" class="finput" name="city" placeholder="Ville ou code postal" autocomplete="off" aria-label="Ville ou code postal">
                </div>

                <div class="fsec">
                    <p class="ftitle">Âge de l'enfant</p>
                    <?php if ($is_logged_in): ?>
                        <select id="f-age" class="finput" name="age" aria-label="Âge de l'enfant"><option value="">Tous les âges</option></select>
                    <?php else: ?>
                        <input id="f-age" type="number" class="finput" name="age" min="3" max="25" placeholder="Ex : 12 ans" aria-label="Âge de l'enfant">
                    <?php endif; ?>
                </div>

                <div class="fsec">
                    <p class="ftitle">Période</p>
                    <div class="flex flex-col gap-2" id="periods">
                        <?php foreach ($holiday_periods as $p): ?>
                            <button type="button" class="pill" data-from="<?= $p['start'] ?>" data-to="<?= $p['end'] ?>"><?= htmlspecialchars($p['label']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <label class="block text-[12px] font-semibold text-[#6b7780]">Du<input id="f-from" type="date" class="finput mt-1"></label>
                        <label class="block text-[12px] font-semibold text-[#6b7780]">Au<input id="f-to" type="date" class="finput mt-1"></label>
                    </div>
                </div>

                <div class="fsec">
                    <div class="flex items-center justify-between"><p class="ftitle !mb-0">Budget maximum</p><span id="price-val" class="text-[13px] font-bold text-[#3d6a8e]">Tous budgets</span></div>
                    <input id="f-price" type="range" class="rng mt-4" min="100" max="3000" step="50" value="3000" aria-label="Budget maximum">
                    <div class="mt-1 flex justify-between text-[11.5px] text-[#9aa3ab]"><span>100 €</span><span>3 000 €</span></div>
                </div>

                <div class="fsec">
                    <p class="ftitle">Thématiques</p>
                    <input id="theme-q" type="search" class="finput mb-2" placeholder="Filtrer les thématiques" aria-label="Filtrer les thématiques">
                    <div id="themes" class="tlist"><p class="text-[13px] text-[#9aa3ab] py-2">Chargement…</p></div>
                </div>

                <div class="fsec">
                    <p class="ftitle">Réservation</p>
                    <label class="chk"><input id="f-online" type="checkbox"> Inscription en ligne uniquement</label>
                </div>
            </div>
            <div class="ffoot flex gap-3 px-5 py-4 border-t border-[#e5e7eb]">
                <button type="button" id="f-reset-m" class="flex-1 py-3 rounded-xl border-[1.5px] border-[#e5e7eb] font-bold text-[#26343b]">Réinitialiser</button>
                <button type="button" id="f-apply" class="flex-[2] py-3 rounded-xl bg-[#2f3d45] text-white font-bold">Voir les résultats</button>
            </div>
        </aside>
        <div id="f-overlay" class="fixed inset-0 bg-black/45 z-[65] hidden lg:hidden" aria-hidden="true"></div>

        <!-- ================================ RÉSULTATS ================================ -->
        <section class="flex-1 min-w-0" aria-label="Résultats">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 id="r-title" class="text-[22px] md:text-[26px] font-extrabold text-[#26343b] leading-tight">Tous les séjours</h2>
                    <p id="r-count" class="mt-1 text-[14px] text-[#6b7780]" aria-live="polite">Chargement…</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="f-open" class="lg:hidden inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border-[1.5px] border-[#e5e7eb] font-bold text-[14px] text-[#26343b] bg-white">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 6h16M7 12h10M10 18h4" stroke-linecap="round"/></svg>Filtres<span id="f-badge" class="hidden min-w-[20px] h-5 px-1.5 rounded-full bg-[#2f3d45] text-white text-[11px] font-bold items-center justify-center"></span>
                    </button>
                    <label class="sr-only" for="f-sort">Trier par</label>
                    <select id="f-sort" class="finput !w-auto !py-2.5 font-semibold">
                        <option value="relevance">Pertinence</option>
                        <option value="date">Date de départ</option>
                        <option value="price_asc">Prix croissant</option>
                        <option value="price_desc">Prix décroissant</option>
                    </select>
                </div>
            </div>

            <div id="tags" class="mt-4 flex flex-wrap gap-2"></div>

            <div id="fallback" class="hidden mt-6 rounded-2xl bg-[#f8f5ea] px-5 py-4">
                <p class="font-extrabold text-[#26343b]">Aucun séjour ne correspond exactement à votre recherche.</p>
                <p class="text-[14px] text-[#5b4a1c] mt-1">Voici une sélection de séjours qui pourraient vous plaire. <button type="button" id="fb-reset" class="underline font-bold">Modifier les filtres</button></p>
            </div>

            <div id="grid" class="rgrid mt-6" aria-busy="true"></div>

            <div id="more-wrap" class="hidden mt-10 text-center">
                <button type="button" id="more" class="px-8 py-3.5 rounded-xl bg-[#2f3d45] hover:bg-[#1f2a30] text-white font-bold transition">Afficher plus de séjours</button>
            </div>

            <?php ad_slot('recherche-1', ADS_SLOTS['recherche'], ['minh' => 280]); ?>
        </section>
    </div>
</main>

<script>
(function () {
    'use strict';
    var IS_LOGGED = <?= json_encode($is_logged_in) ?>;
    var IS_ANIM = <?= json_encode($is_animateur) ?>;
    var favs = <?= json_encode(array_map('intval', (array)$user_favorites)) ?>;
    var $ = function (id) { return document.getElementById(id); };
    var GRID = $('grid');
    var state = { page: 1, themes: [], category: '' };
    var allThemes = [];
    var reqId = 0;

    var MONTHS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]; }); };
    var ICON_HEART = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5l-1.35-1.22C5.9 15.02 3 12.4 3 9.1 3 6.5 5 4.5 7.6 4.5c1.5 0 2.9.7 3.8 1.8l.6.8.6-.8c.9-1.1 2.3-1.8 3.8-1.8 2.6 0 4.6 2 4.6 4.6 0 3.3-2.9 5.9-7.65 10.18L12 20.5z"/></svg>';
    var ICON_ORG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z" fill="#9aa3ab"/></svg>';
    var PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="#e6ebf0"/><g fill="#9aa8b8"><path d="M84 52L66 80h10L60 104h12L54 130h60L96 104h12L92 80h10L84 52z"/><path d="M116 140l22-50 22 50z"/></g></svg>');

    function normDates(a, b) {
        var s = a ? new Date(a) : null, e = b ? new Date(b) : null;
        if (!s || isNaN(s)) return null;
        if (!e || isNaN(e)) return { s: s, e: null };
        if (s > e) { var c = new Date(s); c.setFullYear(e.getFullYear()); if (c > e) c.setFullYear(e.getFullYear() - 1); s = c; }
        return { s: s, e: e };
    }
    function sh(m) { return MONTHS[m].length > 5 ? MONTHS[m].slice(0, 4) + '.' : MONTHS[m]; }
    function range(a, b) {
        var r = normDates(a, b); if (!r) return '';
        if (!r.e) return 'Dès le ' + r.s.getDate() + ' ' + MONTHS[r.s.getMonth()];
        if (r.s.getMonth() === r.e.getMonth() && r.s.getFullYear() === r.e.getFullYear()) return 'Du ' + r.s.getDate() + ' au ' + r.e.getDate() + ' ' + MONTHS[r.e.getMonth()] + ' ' + r.e.getFullYear();
        return 'Du ' + r.s.getDate() + ' ' + sh(r.s.getMonth()) + ' au ' + r.e.getDate() + ' ' + sh(r.e.getMonth()) + ' ' + r.e.getFullYear();
    }
    function shortDate(a, b) { var r = normDates(a, b); return r ? r.s.getDate() + ' ' + sh(r.s.getMonth()) : ''; }
    function price(p) { var n = Number(p); return Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ','); }
    function niceCity(v) { v = String(v || '').trim(); return v === v.toUpperCase() ? v.toLowerCase().replace(/(^|[\s\-'’])(\p{L})/gu, function (m, a, b) { return a + b.toUpperCase(); }) : v; }
    function fmtDay(s) { var d = new Date(s + 'T00:00:00'); return isNaN(d) ? s : d.getDate() + ' ' + sh(d.getMonth()); }

    // ---------------------------------------------------------------- état <-> formulaire <-> adresse
    function maxPriceActive() { return Number($('f-price').value) < Number($('f-price').max); }
    function collect() {
        var p = new URLSearchParams();
        var v;
        if ((v = $('f-name').value.trim())) p.set('name', v);
        if ((v = $('f-city').value.trim())) p.set('city', v);
        if ((v = $('f-age').value)) p.set('age', v);
        if (maxPriceActive()) p.set('max_price', $('f-price').value);
        if ((v = $('f-from').value)) p.set('date_from', v);
        if ((v = $('f-to').value)) p.set('date_to', v);
        if ($('f-online').checked) p.set('online', '1');
        state.themes.forEach(function (t) { p.append('themes[]', t); });
        if ($('f-sort').value !== 'relevance') p.set('sort', $('f-sort').value);
        return p;
    }
    function applyFromUrl() {
        var u = new URLSearchParams(location.search);
        if (u.get('name')) $('f-name').value = u.get('name');
        if (u.get('city')) $('f-city').value = u.get('city');
        if (u.get('age')) setAge(u.get('age'));
        if (u.get('max_price')) { $('f-price').value = u.get('max_price'); }
        if (u.get('date_from')) $('f-from').value = u.get('date_from');
        if (u.get('date_to')) $('f-to').value = u.get('date_to');
        if (u.get('online')) $('f-online').checked = true;
        if (u.get('sort')) $('f-sort').value = u.get('sort');
        state.themes = u.getAll('themes[]').concat(u.getAll('themes'));
        state.page = 1;
    }
    function setAge(a) {
        var el = $('f-age');
        if (el.tagName === 'SELECT' && ![].some.call(el.options, function (o) { return o.value === String(a); })) el.add(new Option(a + ' ans', a));
        el.value = a;
    }
    function syncUrl() {
        var q = collect().toString();
        history.replaceState(null, '', location.pathname + (q ? '?' + q : ''));
    }

    // ---------------------------------------------------------------- affichage des filtres actifs
    function renderTags() {
        var tags = [];
        var add = function (label, clear) { tags.push({ label: label, clear: clear }); };
        if ($('f-name').value.trim()) add('« ' + $('f-name').value.trim() + ' »', function () { $('f-name').value = ''; });
        if ($('f-city').value.trim()) add($('f-city').value.trim(), function () { $('f-city').value = ''; });
        if ($('f-age').value) add($('f-age').value + ' ans', function () { $('f-age').value = ''; });
        if ($('f-from').value || $('f-to').value) add(($('f-from').value ? 'du ' + fmtDay($('f-from').value) : '') + ($('f-to').value ? ' au ' + fmtDay($('f-to').value) : ''), function () { $('f-from').value = ''; $('f-to').value = ''; syncPeriods(); });
        if (maxPriceActive()) add('≤ ' + $('f-price').value + ' €', function () { $('f-price').value = $('f-price').max; priceLabel(); });
        if ($('f-online').checked) add('Inscription en ligne', function () { $('f-online').checked = false; });
        state.themes.forEach(function (t) { add(t, function () { state.themes = state.themes.filter(function (x) { return x !== t; }); renderThemes(); renderCats(); }); });
        var box = $('tags'); box.textContent = '';
        tags.forEach(function (t) {
            var s = document.createElement('span'); s.className = 'tag'; s.appendChild(document.createTextNode(t.label));
            var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Retirer le filtre ' + t.label); b.innerHTML = '&times;';
            b.addEventListener('click', function () { t.clear(); search(true); });
            s.appendChild(b); box.appendChild(s);
        });
        if (tags.length > 1) {
            var r = document.createElement('button'); r.type = 'button'; r.className = 'text-[13px] font-bold text-[#3d6a8e] underline px-2'; r.textContent = 'Tout effacer';
            r.addEventListener('click', resetAll); box.appendChild(r);
        }
        var n = tags.length, badge = $('f-badge');
        badge.textContent = n; badge.classList.toggle('hidden', !n); badge.classList.toggle('inline-flex', !!n);
    }
    function priceLabel() {
        var el = $('f-price'); $('price-val').textContent = maxPriceActive() ? 'Jusqu’à ' + el.value + ' €' : 'Tous budgets';
        var pc = (el.value - el.min) / (el.max - el.min) * 100;
        el.style.background = 'linear-gradient(90deg,#2f3d45 ' + pc + '%,#e5e7eb ' + pc + '%)';
    }
    function syncPeriods() {
        var f = $('f-from').value, t = $('f-to').value;
        [].forEach.call(document.querySelectorAll('#periods .pill'), function (b) { b.classList.toggle('is-on', b.dataset.from === f && b.dataset.to === t); });
    }

    // ---------------------------------------------------------------- thèmes et catégories
    var CAT_ORDER = ['Aventure', 'Montagne', 'Mer / Littoral', 'Nature', 'Sport', 'Arts plastiques', 'Musique', 'Sciences', 'Spiritualité / Prière'];
    function renderThemes() {
        var q = $('theme-q').value.trim().toLowerCase(), box = $('themes'); box.textContent = '';
        var list = allThemes.filter(function (n) { return !q || n.toLowerCase().indexOf(q) !== -1; });
        if (!list.length) { box.innerHTML = '<p class="text-[13px] text-[#9aa3ab] py-2">Aucune thématique.</p>'; return; }
        list.forEach(function (n, i) {
            var l = document.createElement('label'); l.className = 'chk';
            var c = document.createElement('input'); c.type = 'checkbox'; c.checked = state.themes.indexOf(n) !== -1;
            c.addEventListener('change', function () {
                state.themes = c.checked ? state.themes.concat(n) : state.themes.filter(function (x) { return x !== n; });
                renderCats(); search(true);
            });
            l.appendChild(c); l.appendChild(document.createTextNode(n)); box.appendChild(l);
        });
    }
    function renderCats() {
        var row = $('catrow'); row.textContent = '';
        var mk = function (label, on, fn) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'cat' + (on ? ' is-on' : ''); b.textContent = label; b.setAttribute('aria-pressed', on);
            b.addEventListener('click', fn); row.appendChild(b);
        };
        mk('Tous les séjours', !state.themes.length, function () { state.themes = []; renderThemes(); renderCats(); search(true); });
        CAT_ORDER.filter(function (n) { return allThemes.indexOf(n) !== -1; }).forEach(function (n) {
            var on = state.themes.length === 1 && state.themes[0] === n;
            mk(n, on, function () { state.themes = on ? [] : [n]; renderThemes(); renderCats(); search(true); });
        });
    }

    // ---------------------------------------------------------------- cartes
    function card(c, i) {
        var href = (IS_ANIM ? 'info-camp-animateur?id=' + encodeURIComponent(c.id) : 'camp_details?t=' + encodeURIComponent(c.token));
        var isFav = IS_LOGGED && !IS_ANIM && favs.indexOf(Number(c.id)) !== -1;
        var img = c.image_url ? String(c.image_url).replace(/\\/g, '/') : PLACEHOLDER;
        var logo = c.organisateur_logo ? '<img src="' + esc(c.organisateur_logo) + '" alt="" loading="lazy">' : ICON_ORG;
        var p = (c.prix != null && Number(c.prix) > 0) ? 'Dès <b>' + price(c.prix) + ' €</b>' : '<span style="color:#6b7780">Prix sur demande</span>';
        var n = Number(c.sessions_count || 1);
        var date = n > 1 ? n + ' sessions · dès le ' + shortDate(c.date_debut, c.date_fin) : range(c.date_debut, c.date_fin);
        var age = (c.age_min != null && c.age_max != null) ? c.age_min + '–' + c.age_max + ' ans' : '';
        return '<article class="camp-card fade-in-anim" style="animation-delay:' + Math.min(i, 11) * 25 + 'ms">' +
            (c.organisateur_slug ? '<a href="organisme/' + esc(c.organisateur_slug) + '" class="camp-org" aria-label="Voir les séjours de ' + esc(c.organisateur_nom) + '">' : '<div class="camp-org">') + '<span class="camp-avatar">' + logo + '</span><span class="camp-org-name">' + esc(c.organisateur_nom || 'ColoMap') + '</span>' + (c.organisateur_slug ? '</a>' : '</div>') +
            '<div class="camp-mediawrap"><a href="' + href + '" class="camp-media" aria-label="' + esc(c.nom) + '"><img src="' + esc(img) + '" alt="' + esc(c.nom) + '" loading="lazy" decoding="async"></a>' +
            (c.badge ? '<span class="camp-badge">' + esc(c.badge) + '</span>' : '') +
            (age ? '<span class="camp-age">' + age + '</span>' : '') +
            ((IS_LOGGED && !IS_ANIM) ? '<button type="button" class="fav-btn favorite-button' + (isFav ? ' is-fav' : '') + '" data-camp-id="' + esc(c.id) + '" aria-label="Ajouter aux favoris" aria-pressed="' + isFav + '">' + ICON_HEART + '</button>' : '') +
            '</div><a href="' + href + '" class="block"><h3 class="camp-title" title="' + esc(c.nom) + '">' + esc(c.nom) + '</h3>' +
            '<p class="camp-price">' + p + '</p><p class="camp-meta">' + esc(niceCity(c.ville)) + '</p><p class="camp-meta">' + esc(date) + '</p></a></article>';
    }
    function skeletons(n) {
        var h = ''; for (var i = 0; i < n; i++) h += '<div aria-hidden="true"><div class="skeleton" style="height:24px;width:60%;border-radius:8px"></div><div class="skeleton camp-mediawrap" style="margin-top:8px"></div><div class="skeleton" style="height:14px;width:85%;border-radius:6px;margin-top:14px"></div><div class="skeleton" style="height:14px;width:40%;border-radius:6px;margin-top:10px"></div></div>';
        return h;
    }
    document.addEventListener('error', function (ev) {
        var im = ev.target; if (!(im instanceof HTMLImageElement)) return;
        if (im.closest('.camp-media') && im.src !== PLACEHOLDER) im.src = PLACEHOLDER; else if (im.closest('.camp-avatar')) im.remove();
    }, true);

    // ---------------------------------------------------------------- recherche
    function title(total) {
        var nm = $('f-name').value.trim(), city = $('f-city').value.trim();
        $('r-title').textContent = nm ? 'Résultats pour « ' + nm + ' »' : (state.themes.length === 1 ? state.themes[0] : (city ? 'Séjours autour de ' + city : 'Tous les séjours'));
        document.title = (nm ? nm + ' – ' : '') + 'Rechercher une colonie de vacances - ColoMap';
    }
    async function search(reset) {
        if (reset) state.page = 1;
        var id = ++reqId, append = !reset && state.page > 1;
        renderTags(); syncUrl();
        $('fallback').classList.add('hidden');
        if (!append) { GRID.innerHTML = skeletons(8); $('more-wrap').classList.add('hidden'); $('r-count').textContent = 'Recherche en cours…'; }
        GRID.setAttribute('aria-busy', 'true');
        var p = collect(); p.set('page', state.page); p.set('limit', 24);
        try {
            var res = await fetch('api/get_camps_recherche.php?' + p.toString());
            var d = await res.json();
            if (id !== reqId) return;
            if (!res.ok || d.status === 'error') throw new Error(d.message || 'Erreur');
            if (!append) GRID.textContent = '';
            var base = GRID.children.length;
            GRID.insertAdjacentHTML('beforeend', d.camps.map(function (c, i) { return card(c, base + i); }).join(''));
            title(d.total);
            if (d.is_fallback) {
                $('fallback').classList.remove('hidden');
                $('r-count').textContent = 'Aucun résultat exact';
            } else {
                $('r-count').textContent = d.total + (d.total > 1 ? ' séjours trouvés' : ' séjour trouvé');
            }
            if (!d.camps.length && !append) GRID.innerHTML = '<div class="col-span-full text-center py-16"><p class="text-[20px] font-extrabold text-[#26343b]">Aucun séjour disponible</p><p class="mt-2 text-[#6b7780]">Essayez d’élargir vos dates, votre budget ou vos thématiques.</p><button type="button" class="mt-5 px-6 py-3 rounded-xl bg-[#2f3d45] text-white font-bold" id="empty-reset">Réinitialiser la recherche</button></div>';
            var er = $('empty-reset'); if (er) er.addEventListener('click', resetAll);
            $('more-wrap').classList.toggle('hidden', !d.has_more);
            rememberTerm(d.is_fallback ? 0 : d.total);
        } catch (e) {
            if (id !== reqId) return;
            GRID.innerHTML = '<div class="col-span-full rounded-2xl border border-red-200 bg-red-50 px-6 py-8 text-center"><p class="font-extrabold text-red-700">La recherche est momentanément indisponible.</p><button type="button" class="mt-4 px-5 py-2.5 rounded-xl bg-[#2f3d45] text-white font-bold" id="retry">Réessayer</button></div>';
            $('r-count').textContent = 'Erreur de chargement';
            $('retry').addEventListener('click', function () { search(true); });
        } finally { if (id === reqId) GRID.setAttribute('aria-busy', 'false'); }
    }
    // historique des recherches (utilisé par le menu de l'en-tête)
    function rememberTerm(count) {
        var t = $('f-name').value.trim(); if (!t) return;
        var key = 'colomap_search_history', h = [];
        try { h = JSON.parse(localStorage.getItem(key)) || []; } catch (e) {}
        h = h.filter(function (i) { return i.term.toLowerCase() !== t.toLowerCase(); });
        if (count > 0) { h.unshift({ term: t, count: count, date: Date.now() }); h = h.slice(0, 6); }
        try { localStorage.setItem(key, JSON.stringify(h)); } catch (e) {}
    }
    function resetAll() {
        ['f-name', 'f-city', 'f-from', 'f-to'].forEach(function (i) { $(i).value = ''; });
        $('f-age').value = ''; $('f-price').value = $('f-price').max; $('f-online').checked = false; $('f-sort').value = 'relevance';
        state.themes = []; $('theme-q').value = '';
        priceLabel(); syncPeriods(); renderThemes(); renderCats(); search(true);
    }

    // ---------------------------------------------------------------- évènements
    var timer;
    var later = function () { clearTimeout(timer); timer = setTimeout(function () { search(true); }, 350); };
    ['f-name', 'f-city'].forEach(function (i) { $(i).addEventListener('input', later); });
    $('f-age').addEventListener('change', function () { search(true); });
    $('f-age').addEventListener('input', later);
    $('f-price').addEventListener('input', function () { priceLabel(); later(); });
    $('f-online').addEventListener('change', function () { search(true); });
    $('f-sort').addEventListener('change', function () { search(true); });
    ['f-from', 'f-to'].forEach(function (i) { $(i).addEventListener('change', function () { syncPeriods(); search(true); }); });
    [].forEach.call(document.querySelectorAll('#periods .pill'), function (b) {
        b.addEventListener('click', function () {
            var on = b.classList.contains('is-on');
            $('f-from').value = on ? '' : b.dataset.from; $('f-to').value = on ? '' : b.dataset.to;
            syncPeriods(); search(true);
        });
    });
    $('theme-q').addEventListener('input', renderThemes);
    $('more').addEventListener('click', function () { state.page++; search(false); });
    $('fb-reset').addEventListener('click', resetAll);
    $('f-reset-m').addEventListener('click', resetAll);

    // volet de filtres (mobile)
    function openF(on) {
        $('filters').classList.toggle('is-open', on);
        $('f-overlay').classList.toggle('hidden', !on);
        document.body.style.overflow = on ? 'hidden' : '';
    }
    $('f-open').addEventListener('click', function () { openF(true); });
    $('f-close').addEventListener('click', function () { openF(false); });
    $('f-apply').addEventListener('click', function () { openF(false); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    $('f-overlay').addEventListener('click', function () { openF(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') openF(false); });

    // favoris (mise à jour immédiate, retour arrière si le réseau échoue)
    document.body.addEventListener('click', function (e) {
        var b = e.target.closest('.favorite-button'); if (!b) return;
        e.preventDefault(); e.stopPropagation();
        var id = parseInt(b.dataset.campId, 10), was = b.classList.contains('is-fav');
        var set = function (on) { b.classList.toggle('is-fav', on); b.setAttribute('aria-pressed', on); favs = on ? favs.concat(id) : favs.filter(function (x) { return x !== id; }); };
        set(!was);
        fetch('api/toggle_favorite.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ campId: id }) })
            .then(function (r) { return r.json(); }).then(function (r) { if (!r.success) throw 0; }).catch(function () { set(was); });
    });

    // ---------------------------------------------------------------- démarrage
    applyFromUrl(); priceLabel(); syncPeriods();
    var hs = document.getElementById('desktop-search-input'); if (hs && $('f-name').value) hs.value = $('f-name').value;
    var themesReady = fetch('api/get_themes.php').then(function (r) { return r.json(); }).then(function (t) {
        if (Array.isArray(t)) allThemes = t.map(function (x) { return x.name; });
    }).catch(function () {});
    var childrenReady = Promise.resolve();
    if (IS_LOGGED) childrenReady = fetch('api/get_children.php').then(function (r) { return r.json(); }).then(function (cs) {
        if (!Array.isArray(cs)) return; var s = $('f-age');
        cs.forEach(function (c) { if (c.age != null) s.add(new Option(c.prenom + ' (' + c.age + ' ans)', c.age)); });
        var u = new URLSearchParams(location.search).get('age'); if (u) setAge(u);
    }).catch(function () {});
    Promise.all([themesReady, childrenReady]).then(function () { renderThemes(); renderCats(); search(false); });
})();
</script>

<?php require_once 'partials/footer.php'; ?>
