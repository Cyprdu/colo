<?php
/**
 * Fichier : index.php  (page d'accueil)
 * ---------------------------------------------------------------
 *   - Hero + recherche à 3 champs (menu catégories à deux colonnes, sélecteur d'enfant).
 *   - Fil de suggestions personnalisées : UNE seule requête (api/get_recommendations.php)
 *     renvoie toutes les sections (vacances, coups de cœur, favoris, âge, région, prix, thèmes).
 *   - Cartes à dimensions fixes : la taille de l'image source n'a aucune influence.
 *   - Icônes en SVG inline.
 */

$ads_enabled = true;
require_once 'partials/header3.php';

$is_logged_in = isset($_SESSION['user']);
$is_animateur = $is_logged_in && ($_SESSION['user']['is_animateur'] ?? false);
$prenom       = $is_logged_in ? ($_SESSION['user']['prenom'] ?? '') : '';
$user_favorites = $_SESSION['user']['favorites'] ?? [];

// ------------------------------------------------------------------
// Fenêtres de vacances scolaires françaises (zones A/B/C confondues).
// À ajuster chaque année scolaire : volontairement isolé ici, en tête de fichier.
// ------------------------------------------------------------------
$holiday_periods = [
    ['label' => 'Vacances de la Toussaint', 'start' => '2026-10-17', 'end' => '2026-11-02'],
    ['label' => 'Vacances de Noël',         'start' => '2026-12-19', 'end' => '2027-01-04'],
    ['label' => 'Vacances au Ski',          'start' => '2027-02-06', 'end' => '2027-03-09'],
];
$today = date('Y-m-d');
$holiday_periods = array_values(array_filter($holiday_periods, function ($p) use ($today) {
    return $p['end'] >= $today;
}));
?>

<style>
    :root { --ink:#26343b; --accent:#3d6a8e; --accent-dark:#2d5170; --cream:#f8f5ea; --muted:#6b7780; --line:#e5e7eb; }

    /* ---------- Hero ---------- */
    .hero-bg { background: linear-gradient(135deg, #2f4a57 0%, #6f8f93 55%, #c9d6d3 100%); }
    .hero-field { padding: 12px 22px; min-width: 0; }
    .hero-label { display:flex; align-items:center; gap:8px; height:24px; font-size:15px; font-weight:700; color:var(--ink); line-height:24px; }
    .hero-value { display:flex; align-items:center; justify-content:space-between; width:100%; height:28px; font-size:15px; color:#4b5964; background:transparent; border:0; padding:0; outline:0; text-align:left; }
    .hero-field input.hero-value::placeholder { color:#9aa3ab; }

    /* ---------- Menus déroulants ---------- */
    .dd-panel { position:absolute; z-index:60; background:#fff; border-radius:18px; box-shadow:0 18px 50px rgba(18,55,42,.22); border:1px solid var(--line); }
    .dd-panel[hidden] { display:none; }
    .dd-row { display:flex; align-items:center; gap:12px; width:100%; padding:11px 14px; border-radius:12px; font-size:15px; color:#3b4a54; text-align:left; transition:background .15s ease; }
    .dd-row:hover, .dd-row.is-active { background:#f1f3f5; }
    .dd-row.is-active { color:var(--ink); font-weight:700; }
    .dd-row .ico { width:22px; text-align:center; color:#64748b; flex:none; }
    .dd-row .go { margin-left:auto; color:var(--accent); opacity:0; transition:opacity .15s ease; flex:none; }
    .dd-row:hover .go, .dd-row.is-active .go { opacity:1; }
    .dd-row.is-selected { color:var(--accent); font-weight:700; }
    .dd-col { padding:10px; max-height:420px; overflow-y:auto; }
    .dd-col + .dd-col { border-left:1px solid var(--line); }

    /* ---------- Rangée de catégories ---------- */
    .chips { display:flex; align-items:center; justify-content:center; gap:0; flex-wrap:wrap; }
    .chips a { font-size:15px; font-weight:600; color:var(--ink); padding:4px 0; border-bottom:2px solid transparent; transition:border-color .15s ease, color .15s ease; white-space:nowrap; }
    .chips a:hover { color:var(--accent); border-color:var(--accent); }
    .chips i.dot { width:4px; height:4px; background:var(--ink); border-radius:9999px; margin:0 16px; flex:none; }

    /* ---------- Sections et carrousels ---------- */
    .sec-title { font-size:22px; font-weight:700; color:var(--ink); line-height:1.2; }
    .sec-sub   { margin-top:4px; font-size:14px; color:var(--muted); }
    .sec-more  { font-size:16px; font-weight:600; color:var(--accent); display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
    .sec-more:hover { color:var(--accent-dark); }
    .camp-track { display:flex; gap:20px; overflow-x:auto; scroll-behavior:smooth; scroll-snap-type:x proximity; padding:2px 2px 8px; scrollbar-width:none; -ms-overflow-style:none; }
    .camp-track::-webkit-scrollbar { display:none; }

    /* ---------- Carte à dimensions fixes ---------- */
    .camp-card { flex:0 0 216px; width:216px; min-width:216px; max-width:216px; scroll-snap-align:start; display:flex; flex-direction:column; }
    .camp-org { height:28px; display:flex; align-items:center; gap:8px; margin-bottom:8px; }
    .camp-avatar { width:24px; height:24px; border-radius:9999px; flex:none; overflow:hidden; background:#e5e7eb; display:flex; align-items:center; justify-content:center; }
    .camp-avatar img { width:100%; height:100%; object-fit:cover; }
    .camp-org-name { font-size:13px; color:#3b4a54; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .camp-mediawrap { position:relative; width:100%; aspect-ratio:1/1; border-radius:18px; overflow:hidden; background:#e5e7eb; }
    .camp-media { position:absolute; inset:0; display:block; }
    .camp-media img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
    .camp-card:hover .camp-media img { transform:scale(1.04); }
    .camp-badge { position:absolute; top:10px; left:10px; z-index:2; background:#fff; color:var(--ink); font-size:12px; font-weight:600; padding:5px 10px; border-radius:9999px; line-height:1; box-shadow:0 1px 3px rgba(0,0,0,.12); }
    .fav-btn { position:absolute; top:10px; right:10px; z-index:2; width:34px; height:34px; border-radius:9999px; background:#fff; color:var(--ink); display:flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.15); transition:transform .15s ease; }
    .fav-btn:hover { transform:scale(1.08); }
    .fav-btn:active { transform:scale(.92); }
    .fav-btn svg path { fill:none; stroke:currentColor; stroke-width:1.8; transition:fill .15s ease, stroke .15s ease; }
    .fav-btn.is-fav { color:#e5484d; }
    .fav-btn.is-fav svg path { fill:#e5484d; }
    .camp-title { margin-top:12px; font-size:15px; font-weight:500; line-height:1.3; color:var(--ink); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:2.6em; }
    .camp-price { margin-top:4px; font-size:16px; color:var(--ink); height:24px; line-height:24px; }
    .camp-price b { font-weight:700; }
    .camp-meta  { font-size:12.5px; color:#6b7780; height:17px; line-height:17px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .skeleton { background:linear-gradient(90deg,#eceef1 25%,#f8f5ea 37%,#eceef1 63%); background-size:400% 100%; animation:sk 1.4s ease infinite; }
    @keyframes sk { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }
    .fade-in-anim { animation:fadeIn .4s ease-out; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }

    /* ---------- Flèches ---------- */
    .car-btn { position:absolute; top:136px; transform:translateY(-50%); z-index:10; width:44px; height:44px; border-radius:9999px; background:#fff; color:var(--ink); border:1px solid var(--line); box-shadow:0 4px 14px rgba(18,55,42,.18); display:flex; align-items:center; justify-content:center; transition:background .2s ease, color .2s ease; }
    .car-btn:hover { background:var(--ink); color:#fff; }
    .car-next { right:-12px; } .car-prev { left:-12px; }
    .car-btn[hidden] { display:none; }
    @media (max-width: 767px) { .car-btn { display:none; } .camp-card { flex-basis:172px; width:172px; min-width:172px; max-width:172px; } .camp-track { gap:14px; } .car-btn { top:100px; } }

    #anim-list .camp-card { flex:none; width:100%; max-width:none; min-width:0; }
</style>

<!-- ================================================================ -->
<!-- HERO                                                              -->
<!-- ================================================================ -->
<section class="relative isolate z-30 hero-bg">
    <div class="absolute inset-0 -z-10 overflow-hidden">
        <img src="https://img3.wallspic.com/crops/1/6/1/1/8/181161/181161-weisshorn-les_reliefs_montagneux-gamme_de_montagne-colline-hiver-3840x2160.jpg"
             alt="" class="w-full h-full object-cover" loading="eager" fetchpriority="high" onerror="this.remove()">
        <div class="absolute inset-0 bg-gradient-to-b from-black/20 via-black/10 to-black/30"></div>
    </div>

    <div class="w-full max-w-[1320px] mx-auto px-5 sm:px-8 pt-5 pb-12 min-h-[330px] md:min-h-[350px] flex flex-col">

        <?php if ($is_logged_in && !$is_animateur): ?>
        <!-- Sélecteur « Rechercher pour : {enfant} » -->
        <div class="flex justify-end items-center gap-3">
            <span class="text-[14px] font-semibold text-white" style="text-shadow:0 1px 3px rgba(0,0,0,.5)">Rechercher pour&nbsp;:</span>
            <div class="relative">
                <button type="button" id="child-btn" aria-haspopup="listbox" aria-expanded="false"
                        class="bg-white rounded-xl shadow-md pl-4 pr-3 h-11 flex items-center gap-2 text-[14px] font-bold text-[#26343b]">
                    <span id="child-label" class="max-w-[150px] truncate">Toute la famille</span>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" class="text-[#3d6a8e]"><path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z" fill="currentColor"/></svg>
                </button>
                <div id="child-panel" class="dd-panel right-0 top-[calc(100%+8px)] w-[260px] p-2" role="listbox" hidden></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex-1 flex flex-col justify-center text-center pt-3">
            <h1 class="font-display text-[34px] md:text-[42px] leading-tight text-white mb-8" style="text-shadow:0 2px 14px rgba(0,0,0,.35)">
                <?php echo $is_logged_in ? 'Bonjour ' . htmlspecialchars($prenom) : 'Trouvez la colonie idéale'; ?>
            </h1>

            <!-- Barre de recherche -->
            <form id="hero-search-form" action="recherche" method="GET"
                  class="relative w-full max-w-[1000px] mx-auto text-left bg-white rounded-2xl shadow-[0_14px_44px_rgba(0,0,0,.28)] p-2 flex flex-col md:flex-row md:items-center">
                <input type="hidden" name="age" id="hero-age-hidden" value="">
                <div id="cat-inputs"></div>

                <!-- Catégorie : menu à deux colonnes -->
                <div class="hero-field flex-[1.05]">
                    <button type="button" id="cat-btn" aria-haspopup="true" aria-expanded="false" class="block w-full text-left">
                        <span class="hero-label">Catégorie</span>
                        <span class="hero-value"><span id="cat-label" class="truncate">Toutes catégories</span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" class="flex-none text-[#26343b]"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </button>
                </div>
                <div class="hidden md:block w-px h-11 bg-[#e5e7eb]"></div><div class="md:hidden h-px bg-[#e5e7eb] mx-5"></div>

                <div class="hero-field flex-[1.25]">
                    <label for="hero-name" class="hero-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.2"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                        Que recherchez-vous ?
                    </label>
                    <input type="text" id="hero-name" name="name" placeholder="Ex : Weekend-Ski, voile, théâtre" autocomplete="off" class="hero-value">
                </div>
                <div class="hidden md:block w-px h-11 bg-[#e5e7eb]"></div><div class="md:hidden h-px bg-[#e5e7eb] mx-5"></div>

                <div class="hero-field flex-1">
                    <label for="hero-city" class="hero-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/><circle cx="12" cy="9.5" r="2.4" stroke="currentColor" stroke-width="2.2"/></svg>
                        Localisation
                    </label>
                    <input type="text" id="hero-city" name="city" placeholder="Partout" autocomplete="off" class="hero-value">
                </div>

                <button type="submit" aria-label="Rechercher"
                        class="m-1 md:ml-2 h-[60px] md:w-[64px] flex-none flex items-center justify-center gap-2 bg-[#2f3d45] hover:bg-[#1f2a30] text-white rounded-xl transition-colors">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5" stroke="#fff" stroke-width="2.2"/><path d="M20.5 20.5l-4.6-4.6" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
                    <span class="md:hidden font-bold text-sm">Rechercher</span>
                </button>

                <div id="cat-panel" class="dd-panel left-0 top-[calc(100%+10px)] w-full md:w-[860px] max-w-full flex" hidden>
                    <div id="cat-groups" class="dd-col w-full md:w-1/2"></div>
                    <div id="cat-items" class="dd-col w-1/2 hidden md:block"></div>
                </div>
            </form>

            <?php if (!$is_logged_in || ($_SESSION['user']['is_directeur'] ?? false)): ?>
            <p class="mt-5 text-[14px] text-white" style="text-shadow:0 1px 3px rgba(0,0,0,.5)">
            </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Catégories rapides -->
<nav class="border-b border-[#e5e7eb]" aria-label="Catégories">
    <div id="chips" class="chips w-full max-w-[1320px] mx-auto px-5 sm:px-8 py-5 min-h-[68px]"></div>
</nav>

<main class="flex-1 w-full max-w-[1320px] mx-auto px-5 sm:px-8 pb-6">
    <?php if (!$is_animateur): ?>
        <div id="feed" aria-live="polite"></div>
        <?php ad_slot('accueil-1', ADS_SLOTS['accueil'], ['minh' => 280]); ?>
        <div id="feed-empty" class="hidden text-center py-16">
            <p class="text-xl font-semibold text-[#26343b]">Aucun séjour à venir pour le moment</p>
            <p class="mt-2 text-[#6b7780]">De nouveaux séjours sont publiés régulièrement.</p>
            <a href="recherche" class="mt-6 inline-block bg-[#2f3d45] hover:bg-[#1f2a30] text-white font-semibold px-8 py-3 rounded-lg transition-colors">Explorer tous les séjours</a>
        </div>
    <?php else: ?>
        <section class="mt-10">
            <h2 class="sec-title mb-1">Missions ouvertes pour vous</h2>
            <p class="sec-sub mb-5">Les séjours qui recherchent des animateurs correspondant à votre profil</p>
            <div id="anim-list" class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-5"></div>
            <div id="anim-empty" class="hidden text-center py-10 text-[#6b7780]">Aucune mission éligible pour le moment. Essayez d'affiner votre recherche ci-dessus.</div>
        </section>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const isLoggedIn  = <?php echo json_encode($is_logged_in); ?>;
    const isAnimateur = <?php echo json_encode($is_animateur); ?>;
    const PERIODS     = <?php echo json_encode($holiday_periods, JSON_UNESCAPED_UNICODE); ?>;
    let userFavorites = <?php echo json_encode(array_map('intval', (array)$user_favorites)); ?>;

    // ------------------------------------------------------------
    // Icônes / visuels (SVG inline)
    // ------------------------------------------------------------
    const ICON_PIN   = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="flex:none"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z" fill="#6b7780"/><circle cx="12" cy="9.5" r="2.3" fill="#fff"/></svg>';
    const ICON_HEART = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5l-1.35-1.22C5.9 15.02 3 12.4 3 9.1 3 6.5 5 4.5 7.6 4.5c1.5 0 2.9.7 3.8 1.8l.6.8.6-.8c.9-1.1 2.3-1.8 3.8-1.8 2.6 0 4.6 2 4.6 4.6 0 3.3-2.9 5.9-7.65 10.18L12 20.5z"/></svg>';
    const ICON_ORG   = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z" fill="#9aa3ab"/></svg>';
    const ICON_ARROW = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12h16M14 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    window.CAMP_PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#eef1f5"/><stop offset="1" stop-color="#d3dbe6"/></linearGradient></defs>' +
        '<rect width="200" height="200" fill="url(#g)"/><g fill="#9aa8b8"><path d="M84 52L66 80h10L60 104h12L54 130h60L96 104h12L92 80h10L84 52z"/><rect x="82" y="130" width="5" height="12"/><path d="M116 140l22-50 22 50z"/></g></svg>');

    const MONTHS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    const cap = s => s.charAt(0).toUpperCase() + s.slice(1);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const safeImg = url => url ? String(url).replace(/\\/g, '/') : '';
    // Dates corrigées : certains séjours importés ont un début situé après la fin (année du début erronée)
    function normDates(start, end) {
        let a = start ? new Date(start) : null, b = end ? new Date(end) : null;
        if (!a || isNaN(a)) return null;
        if (!b || isNaN(b)) return { a, b: null };
        if (a > b) { const c = new Date(a); c.setFullYear(b.getFullYear()); if (c > b) c.setFullYear(b.getFullYear() - 1); a = c; }
        return { a, b };
    }
    function formatDateRange(start, end) {
        const r = normDates(start, end);
        if (!r) return '';
        const s = r.a, e = r.b;
        if (!e) return `Dès le ${s.getDate()} ${MONTHS[s.getMonth()]} ${s.getFullYear()}`;
        if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear())
            return `Du ${s.getDate()} au ${e.getDate()} ${MONTHS[e.getMonth()]} ${e.getFullYear()}`;
        const sh = m => MONTHS[m].length > 5 ? MONTHS[m].slice(0, 4) + '.' : MONTHS[m];
        return `Du ${s.getDate()} ${sh(s.getMonth())} au ${e.getDate()} ${sh(e.getMonth())} ${e.getFullYear()}`;
    }
    function shortDate(start, end) {
        const r = normDates(start, end);
        if (!r) return '';
        const m = MONTHS[r.a.getMonth()];
        return `${r.a.getDate()} ${m.length > 5 ? m.slice(0, 4) + '.' : m}`;
    }
    function formatPrice(p) {
        const n = Number(p);
        return Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',');
    }
    // « HAUTELUCE » -> « Hauteluce » (les villes en majuscules de la base)
    function niceCity(v) {
        v = String(v || '').trim();
        return v === v.toUpperCase() ? v.toLowerCase().replace(/(^|[\s\-'’])(\p{L})/gu, (m, a, b) => a + b.toUpperCase()) : v;
    }

    // Une image qui ne charge pas est remplacée par le visuel ColoMap (taille inchangée)
    document.addEventListener('error', function (ev) {
        const img = ev.target;
        if (!(img instanceof HTMLImageElement)) return;
        if (img.closest('.camp-media')) { if (img.src !== window.CAMP_PLACEHOLDER) img.src = window.CAMP_PLACEHOLDER; }
        else if (img.closest('.camp-avatar')) { img.remove(); }
    }, true);

    // ------------------------------------------------------------
    // Menus déroulants
    // ------------------------------------------------------------
    const openers = [];   // {btn, panel}
    function closeAll(except) {
        openers.forEach(o => { if (o.panel !== except) { o.panel.hidden = true; o.btn.setAttribute('aria-expanded', 'false'); } });
    }
    function bindDropdown(btn, panel, onOpen) {
        openers.push({ btn, panel });
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const willOpen = panel.hidden;
            closeAll(willOpen ? panel : null);
            panel.hidden = !willOpen;
            btn.setAttribute('aria-expanded', String(willOpen));
            if (willOpen && onOpen) onOpen();
        });
        panel.addEventListener('click', e => e.stopPropagation());
    }
    document.addEventListener('click', () => closeAll(null));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(null); });

    // ---- Catégories : groupes (colonne gauche) -> sous-catégories (colonne droite)
    const GROUPS = [
        { id: 'sport',   label: 'Sport et aventure',        icon: 'fa-person-hiking',  items: ['Aventure', 'Sport', 'Montagne', 'Mer / Littoral', 'Survie / Bushcraft', 'Voyage / Itinérant'] },
        { id: 'nature',  label: 'Nature et environnement',  icon: 'fa-tree',           items: ['Nature', 'Campagne', 'Écologie'] },
        { id: 'arts',    label: 'Arts et culture',          icon: 'fa-palette',        items: ['Arts plastiques', 'Cinéma', 'Danse', 'Musique', 'Théâtre', 'Découverte culturelle'] },
        { id: 'sciences',label: 'Sciences et numérique',    icon: 'fa-flask',          items: ['Sciences', 'Robotique', 'Numérique / Gaming', 'Langues étrangères'] },
        { id: 'spirit',  label: 'Spiritualité et solidarité', icon: 'fa-hands-praying', items: ['Spiritualité / Prière', 'Pèlerinage', 'Humanitaire / Solidaire'] },
        { id: 'loisirs', label: 'Bien-être et loisirs',     icon: 'fa-spa',            items: ['Bien-être / Relaxation', 'Jeux', 'Gastronomie / Cuisine', 'Autre'] },
    ];
    const catBtn = document.getElementById('cat-btn'), catPanel = document.getElementById('cat-panel');
    const catGroups = document.getElementById('cat-groups'), catItems = document.getElementById('cat-items');
    const catLabel = document.getElementById('cat-label'), catInputs = document.getElementById('cat-inputs');
    let allThemes = [], activeGroup = null, mobileItemsGroup = null;
    const isMobile = () => window.matchMedia('(max-width: 767px)').matches;

    function setCategory(label, names) {
        catLabel.textContent = label;
        catInputs.innerHTML = names.map(n => `<input type="hidden" name="themes[]" value="${esc(n)}">`).join('');
        closeAll(null);
    }
    function groupItems(g) { return g.items.filter(n => allThemes.includes(n)); }
    function renderCat() {
        const known = new Set(GROUPS.flatMap(g => g.items));
        const groups = GROUPS.map(g => ({ ...g, list: groupItems(g) })).filter(g => g.list.length);
        const extra = allThemes.filter(n => !known.has(n));
        if (extra.length) groups.push({ id: 'autres', label: 'Autres', icon: 'fa-ellipsis', list: extra });
        catPanel._groups = groups;

        // mobile : une seule colonne qui bascule groupes <-> sous-catégories
        if (isMobile() && mobileItemsGroup) {
            const g = groups.find(x => x.id === mobileItemsGroup) || groups[0];
            catGroups.innerHTML =
                `<button type="button" class="dd-row" data-back="1"><span class="ico"><i class="fa-solid fa-arrow-left"></i></span>Retour</button>` +
                `<button type="button" class="dd-row is-selected" data-all="${g.id}">Tout : ${esc(g.label)}</button>` +
                g.list.map(n => `<button type="button" class="dd-row" data-item="${esc(n)}">${esc(n)}</button>`).join('');
            catItems.innerHTML = '';
            return;
        }
        if (!activeGroup && groups.length && !isMobile()) activeGroup = groups[0].id;
        catGroups.innerHTML =
            `<button type="button" class="dd-row" data-none="1"><span class="ico"><i class="fa-solid fa-border-all"></i></span>Toutes catégories</button>` +
            groups.map(g => `<button type="button" class="dd-row${g.id === activeGroup ? ' is-active' : ''}" data-group="${g.id}"><span class="ico"><i class="fa-solid ${g.icon}"></i></span>${esc(g.label)}<span class="go">${ICON_ARROW}</span></button>`).join('');
        const cur = groups.find(g => g.id === activeGroup);
        catItems.innerHTML = cur
            ? `<button type="button" class="dd-row is-selected" data-all="${cur.id}"><span class="ico"><i class="fa-solid ${cur.icon}"></i></span>Tout : ${esc(cur.label)}</button>` +
              cur.list.map(n => `<button type="button" class="dd-row" data-item="${esc(n)}">${esc(n)}</button>`).join('')
            : '';
    }
    catPanel.addEventListener('click', e => {
        const b = e.target.closest('button'); if (!b) return;
        const groups = catPanel._groups || [];
        if (b.dataset.none)  return setCategory('Toutes catégories', []);
        if (b.dataset.back)  { mobileItemsGroup = null; return renderCat(); }
        if (b.dataset.item)  return setCategory(b.dataset.item, [b.dataset.item]);
        if (b.dataset.all)   { const g = groups.find(x => x.id === b.dataset.all); return g && setCategory(g.label, g.list); }
        if (b.dataset.group) { if (isMobile()) { mobileItemsGroup = b.dataset.group; } else { activeGroup = b.dataset.group; } renderCat(); }
    });
    catPanel.addEventListener('mouseover', e => {
        const b = e.target.closest('[data-group]');
        if (b && !isMobile() && b.dataset.group !== activeGroup) { activeGroup = b.dataset.group; renderCat(); }
    });
    bindDropdown(catBtn, catPanel, () => { mobileItemsGroup = null; renderCat(); });

    // Catégories : chargement + rangée de raccourcis
    const CHIP_ORDER = ['Aventure', 'Montagne', 'Mer / Littoral', 'Nature', 'Sport', 'Arts plastiques', 'Musique', 'Sciences', 'Spiritualité / Prière'];
    fetch('api/get_themes.php').then(r => r.json()).then(themes => {
        if (!Array.isArray(themes)) return;
        allThemes = themes.map(t => t.name);
        const chips = CHIP_ORDER.filter(n => allThemes.includes(n));
        document.getElementById('chips').innerHTML = chips
            .map(n => `<a href="recherche?themes[]=${encodeURIComponent(n)}">${esc(n)}</a>`)
            .join('<i class="dot"></i>');
    }).catch(() => {});

    // ---- Enfant : liste déroulante + rechargement du fil
    let currentChildAge = '';
    const childBtn = document.getElementById('child-btn'), childPanel = document.getElementById('child-panel');
    if (childBtn && childPanel) {
        const options = [{ label: 'Toute la famille', age: '' }];
        const renderChild = () => {
            childPanel.innerHTML = options.map((o, i) =>
                `<button type="button" role="option" class="dd-row${String(o.age) === String(currentChildAge) ? ' is-selected' : ''}" data-i="${i}">${esc(o.label)}</button>`).join('');
        };
        childPanel.addEventListener('click', e => {
            const b = e.target.closest('button'); if (!b) return;
            const o = options[Number(b.dataset.i)];
            currentChildAge = String(o.age);
            document.getElementById('child-label').textContent = o.label;
            document.getElementById('hero-age-hidden').value = currentChildAge;
            closeAll(null);
            loadFeed();
        });
        bindDropdown(childBtn, childPanel, renderChild);
        fetch('api/get_children.php').then(r => r.json()).then(children => {
            if (!Array.isArray(children)) return;
            children.forEach(c => { if (c.age !== null && c.age !== undefined) options.push({ label: `${c.prenom} (${c.age} ans)`, age: c.age }); });
            renderChild();
        }).catch(() => {});
        renderChild();
    }

    // ------------------------------------------------------------
    // Carte camp : dimensions fixes, quelle que soit l'image source
    // ------------------------------------------------------------
    function campCard(camp) {
        const detailPage = isAnimateur ? 'info-camp-animateur' : 'camp_details';
        const linkParam  = isAnimateur ? `id=${encodeURIComponent(camp.id)}` : `t=${encodeURIComponent(camp.token || '')}`;
        const isFav = !isAnimateur && isLoggedIn && userFavorites.includes(Number(camp.id));
        const href  = `${detailPage}?${linkParam}`;
        const logo  = camp.organisateur_logo ? `<img src="${esc(safeImg(camp.organisateur_logo))}" alt="" loading="lazy">` : ICON_ORG;
        const img   = safeImg(camp.image_url) || window.CAMP_PLACEHOLDER;
        const price = (camp.prix !== null && camp.prix !== undefined && !isNaN(camp.prix) && Number(camp.prix) > 0) ? `Dès <b>${formatPrice(camp.prix)} €</b>` : '';
        const n     = Number(camp.sessions_count || 1);
        const date  = n > 1 ? `${n} sessions · dès le ${shortDate(camp.date_debut, camp.date_fin)}` : formatDateRange(camp.date_debut, camp.date_fin);

        return `
        <article class="camp-card fade-in-anim">
            ${camp.organisateur_slug ? `<a href="organisme/${esc(camp.organisateur_slug)}" class="camp-org" aria-label="Voir les séjours de ${esc(camp.organisateur_nom)}">` : '<div class="camp-org">'}
                <span class="camp-avatar">${logo}</span>
                <span class="camp-org-name">${esc(camp.organisateur_nom || 'ColoMap')}</span>
            ${camp.organisateur_slug ? '</a>' : '</div>'}
            <div class="camp-mediawrap">
                <a href="${href}" class="camp-media" aria-label="${esc(camp.nom)}"><img src="${esc(img)}" alt="${esc(camp.nom)}" loading="lazy" decoding="async"></a>
                ${camp.badge ? `<span class="camp-badge">${esc(camp.badge)}</span>` : ''}
                ${(!isAnimateur && isLoggedIn) ? `<button type="button" class="fav-btn favorite-button${isFav ? ' is-fav' : ''}" data-camp-id="${esc(camp.id)}" aria-label="Ajouter aux favoris" aria-pressed="${isFav}">${ICON_HEART}</button>` : ''}
            </div>
            <a href="${href}" class="block">
                <h3 class="camp-title" title="${esc(camp.nom)}">${esc(camp.nom)}</h3>
                <p class="camp-price">${price}</p>
                <p class="camp-meta">${camp.ville ? esc(niceCity(camp.ville)) : ''}</p>
                <p class="camp-meta">${esc(date)}</p>
            </a>
        </article>`;
    }

    function skeletonCard() {
        return `<div class="camp-card" aria-hidden="true">
            <div class="camp-org"><div class="skeleton w-6 h-6 rounded-full"></div><div class="skeleton h-3 w-24 rounded"></div></div>
            <div class="skeleton camp-mediawrap"></div>
            <div class="skeleton h-3 w-4/5 rounded mt-3"></div>
            <div class="skeleton h-3 w-1/3 rounded mt-3"></div>
            <div class="skeleton h-3 w-2/3 rounded mt-2"></div>
        </div>`;
    }

    // ------------------------------------------------------------
    // Favoris (mise à jour immédiate, retour arrière si le réseau échoue)
    // ------------------------------------------------------------
    async function toggleFavorite(button) {
        const campId = parseInt(button.dataset.campId, 10);
        const wasFav = button.classList.contains('is-fav');
        const setFav = on => {
            document.querySelectorAll(`.favorite-button[data-camp-id="${campId}"]`).forEach(b => { b.classList.toggle('is-fav', on); b.setAttribute('aria-pressed', on); });
            userFavorites = on ? [...new Set([...userFavorites, campId])] : userFavorites.filter(id => id !== campId);
        };
        setFav(!wasFav);
        try {
            const res = await fetch('api/toggle_favorite.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ campId }) });
            const result = await res.json();
            if (!result.success) throw new Error('revert');
        } catch (e) { setFav(wasFav); }
    }
    document.body.addEventListener('click', e => {
        const btn = e.target.closest('.favorite-button');
        if (btn) { e.preventDefault(); e.stopPropagation(); toggleFavorite(btn); }
    });

    // ------------------------------------------------------------
    // Carrousels : flèches selon la position de défilement
    // ------------------------------------------------------------
    function bindCarousel(track) {
        const wrap = track.parentElement;
        const prev = wrap.querySelector('.car-prev'), next = wrap.querySelector('.car-next');
        const update = () => {
            const max = track.scrollWidth - track.clientWidth - 4;
            prev.hidden = track.scrollLeft <= 4;
            next.hidden = track.scrollLeft >= max;
        };
        const step = () => Math.max(track.clientWidth * 0.85, 236);
        next.addEventListener('click', () => track.scrollBy({ left: step() }));
        prev.addEventListener('click', () => track.scrollBy({ left: -step() }));
        track.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        requestAnimationFrame(update);
    }

    function sectionHTML(sec) {
        const more = sec.more ? `<a class="sec-more" href="${esc(sec.more)}">Voir plus ${ICON_ARROW}</a>` : '';
        return `
        <section class="mt-10" aria-labelledby="sec-${esc(sec.id)}">
            <div class="flex items-end justify-between gap-4 mb-5">
                <div class="min-w-0"><h2 id="sec-${esc(sec.id)}" class="sec-title">${esc(sec.title)}</h2>${sec.subtitle ? `<p class="sec-sub">${esc(sec.subtitle)}</p>` : ''}</div>
                ${more}
            </div>
            <div class="relative">
                <div class="camp-track">${sec.camps.map(campCard).join('')}</div>
                <button type="button" class="car-btn car-prev" aria-label="Précédent" hidden><span style="display:flex;transform:rotate(180deg)">${ICON_ARROW}</span></button>
                <button type="button" class="car-btn car-next" aria-label="Suivant">${ICON_ARROW}</button>
            </div>
        </section>`;
    }

    const BANNER = `
        <section class="mt-12" aria-label="Rechercher une colonie">
            <div class="relative bg-[#f8f5ea] rounded-3xl px-6 py-12 text-center overflow-hidden">
                <svg class="pointer-events-none absolute bottom-0 left-0 h-[120px] w-auto opacity-90" viewBox="0 0 200 150" aria-hidden="true" focusable="false"><polygon points="30.0,86.0 40.9,110.3 35.7,110.3 47.0,128.2 38.7,128.2 51.8,150.0 8.2,150.0 21.3,128.2 13.0,128.2 24.3,110.3 19.1,110.3" fill="#dfe3cf"/><rect x="27.8" y="150.0" width="4.4" height="5.8" fill="#dfe3cf"/><polygon points="70.0,58.0 85.6,93.0 78.1,93.0 94.4,118.7 82.5,118.7 101.3,150.0 38.7,150.0 57.5,118.7 45.6,118.7 61.9,93.0 54.4,93.0" fill="#cfd6bd"/><rect x="67.8" y="150.0" width="4.4" height="8.3" fill="#cfd6bd"/><polygon points="112.0,94.0 121.5,115.3 117.0,115.3 126.9,131.0 119.6,131.0 131.0,150.0 93.0,150.0 104.4,131.0 97.1,131.0 107.0,115.3 102.5,115.3" fill="#e4e6d4"/><rect x="109.8" y="150.0" width="4.4" height="5.0" fill="#e4e6d4"/><polygon points="150.0,74.0 162.9,102.9 156.7,102.9 170.2,124.2 160.3,124.2 175.8,150.0 124.2,150.0 139.7,124.2 129.8,124.2 143.3,102.9 137.1,102.9" fill="#d6dcc5"/><rect x="147.8" y="150.0" width="4.4" height="6.8" fill="#d6dcc5"/></svg><svg class="pointer-events-none absolute bottom-0 right-0 h-[120px] w-auto opacity-90" viewBox="0 0 200 150" aria-hidden="true" focusable="false"><polygon points="170.0,86.0 180.9,110.3 175.7,110.3 187.0,128.2 178.7,128.2 191.8,150.0 148.2,150.0 161.3,128.2 153.0,128.2 164.3,110.3 159.1,110.3" fill="#dfe3cf"/><rect x="167.8" y="150.0" width="4.4" height="5.8" fill="#dfe3cf"/><polygon points="130.0,58.0 145.6,93.0 138.1,93.0 154.4,118.7 142.5,118.7 161.3,150.0 98.7,150.0 117.5,118.7 105.6,118.7 121.9,93.0 114.4,93.0" fill="#cfd6bd"/><rect x="127.8" y="150.0" width="4.4" height="8.3" fill="#cfd6bd"/><polygon points="88.0,94.0 97.5,115.3 93.0,115.3 102.9,131.0 95.6,131.0 107.0,150.0 69.0,150.0 80.4,131.0 73.1,131.0 83.0,115.3 78.5,115.3" fill="#e4e6d4"/><rect x="85.8" y="150.0" width="4.4" height="5.0" fill="#e4e6d4"/><polygon points="50.0,74.0 62.9,102.9 56.7,102.9 70.2,124.2 60.3,124.2 75.8,150.0 24.2,150.0 39.7,124.2 29.8,124.2 43.3,102.9 37.1,102.9" fill="#d6dcc5"/><rect x="47.8" y="150.0" width="4.4" height="6.8" fill="#d6dcc5"/></svg>
                <p class="relative font-display text-[30px] text-[#26343b] leading-none">ColoMap</p>
                <p class="relative mt-3 text-[16px] text-[#4b5964] max-w-md mx-auto">Recherchez une colonie de vacances adaptée pour votre enfant.</p>
                <a href="recherche" class="relative mt-6 inline-block bg-[#2f3d45] hover:bg-[#1f2a30] text-white text-[16px] font-semibold px-12 py-3 rounded-lg transition-colors">Rechercher</a>
            </div>
        </section>`;

    // ------------------------------------------------------------
    // Fil de suggestions : une seule requête pour toutes les sections
    // ------------------------------------------------------------
    const feed = document.getElementById('feed'), feedEmpty = document.getElementById('feed-empty');
    let feedToken = 0;
    function loadFeed() {
        if (!feed) return;
        const token = ++feedToken;
        feedEmpty.classList.add('hidden');
        feed.innerHTML = [0, 1].map(() => `<section class="mt-10"><div class="skeleton h-6 w-56 rounded mb-5"></div><div class="camp-track">${Array(7).fill(skeletonCard()).join('')}</div></section>`).join('');

        const params = new URLSearchParams({ periods: JSON.stringify(PERIODS) });
        if (currentChildAge !== '') params.set('child_age', currentChildAge);
        fetch(`api/get_recommendations.php?${params}`)
            .then(r => r.json())
            .then(data => {
                if (token !== feedToken) return; // une requête plus récente a pris le relais
                if (data && Array.isArray(data.favorites)) userFavorites = data.favorites.map(Number);
                const sections = (data && data.sections) || [];
                if (!sections.length) { feed.innerHTML = ''; feedEmpty.classList.remove('hidden'); return; }
                feed.innerHTML = sections.map((s, i) => sectionHTML(s) + (i === 0 ? BANNER : '')).join('');
                feed.querySelectorAll('.camp-track').forEach(bindCarousel);
            })
            .catch(() => { if (token === feedToken) { feed.innerHTML = ''; feedEmpty.classList.remove('hidden'); } });
    }

    if (!isAnimateur) {
        loadFeed();
    } else {
        // Variante animateur : missions éligibles
        const animList = document.getElementById('anim-list'), animEmpty = document.getElementById('anim-empty');
        animList.innerHTML = Array(6).fill(skeletonCard()).join('');
        fetch('api/get_eligible_camps_for_animator.php')
            .then(r => r.json())
            .then(camps => {
                if (!Array.isArray(camps) || camps.length === 0) { animList.innerHTML = ''; animEmpty.classList.remove('hidden'); return; }
                animList.innerHTML = camps.slice(0, 12).map(campCard).join('');
            })
            .catch(() => { animList.innerHTML = ''; animEmpty.classList.remove('hidden'); });
    }
});
</script>

<?php require_once 'partials/footer3.php'; ?>
