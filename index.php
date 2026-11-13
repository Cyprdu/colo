<?php
/**
 * Fichier : index.php  (page d'accueil)
 * ---------------------------------------------------------------
 *   - Hero plein écran (photo de montagne), sélecteur "Rechercher pour : {enfant}",
 *     recherche à 3 champs (Catégorie / Mot-clé / Localisation).
 *   - Carrousels par période de vacances, alimentés par api/get_recommendations.php
 *     (classement personnalisé : âge des enfants, favoris, historique, région).
 *   - Cartes à dimensions fixes (la taille de l'image source n'a aucune influence).
 *   - Icônes en SVG inline : aucune dépendance à une police d'icônes pour l'affichage.
 */

require_once 'partials/header3.php';

$is_logged_in   = isset($_SESSION['user']);
$is_animateur   = $is_logged_in && ($_SESSION['user']['is_animateur'] ?? false);
$prenom         = $is_logged_in ? ($_SESSION['user']['prenom'] ?? '') : '';
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
// On ne garde que les périodes pas encore terminées : la page reste pertinente toute seule.
$holiday_periods = array_values(array_filter($holiday_periods, function ($p) use ($today) {
    return $p['end'] >= $today;
}));
$far_future = date('Y-m-d', strtotime('+10 years'));

// Icône flèche réutilisée par les carrousels
$arrow_svg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 12h16M14 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>

<style>
    /* ---------- Hero ---------- */
    .hero-bg { background: linear-gradient(135deg, #3b4a66 0%, #c98b8b 55%, #f0b48c 100%); }

    /* ---------- Carrousels ---------- */
    .camp-track {
        display: flex; gap: 16px; overflow-x: auto; scroll-behavior: smooth;
        scroll-snap-type: x proximity; padding: 2px 84px 6px 2px;
        scrollbar-width: none; -ms-overflow-style: none;
    }
    .camp-track::-webkit-scrollbar { display: none; }

    /* ---------- Carte à dimensions fixes ---------- */
    .camp-card {
        flex: 0 0 196px; width: 196px; min-width: 196px; max-width: 196px;
        scroll-snap-align: start;
        background: #f5f5f5; border-radius: 28px; padding: 12px 12px 16px;
        display: flex; flex-direction: column;
        transition: box-shadow .2s ease, transform .2s ease;
    }
    .camp-card:hover { box-shadow: 0 8px 24px rgba(15, 23, 42, .10); transform: translateY(-2px); }
    .camp-org { height: 28px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .camp-org-name { font-size: 11px; font-weight: 700; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .camp-avatar { width: 26px; height: 26px; border-radius: 9999px; flex: none; overflow: hidden; background: #e5e7eb; display: flex; align-items: center; justify-content: center; }
    .camp-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .camp-media { position: relative; width: 100%; aspect-ratio: 1 / 1; border-radius: 20px; overflow: hidden; background: #e2e8f0; display: block; }
    .camp-media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease; }
    .camp-card:hover .camp-media img { transform: scale(1.05); }
    .camp-title { margin-top: 12px; font-size: 13px; font-weight: 700; line-height: 1.25; color: #111827; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.5em; }
    .camp-price { margin-top: 6px; font-size: 13px; color: #1f2937; height: 18px; }
    .camp-city  { margin-top: 4px; font-size: 11px; color: #4b5563; height: 16px; display: flex; align-items: center; gap: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .camp-date  { margin-top: 2px; font-size: 10px; font-weight: 700; color: #1f2937; height: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .fav-btn { width: 24px; height: 24px; border-radius: 9999px; background: #1f2933; color: #fff; display: flex; align-items: center; justify-content: center; flex: none; transition: background .2s ease, transform .15s ease; }
    .fav-btn:hover { transform: scale(1.1); }
    .fav-btn:active { transform: scale(.92); }
    .fav-btn.is-fav { background: #e5484d; }

    .skeleton { background: linear-gradient(90deg,#ececec 25%,#f6f6f6 37%,#ececec 63%); background-size: 400% 100%; animation: sk 1.4s ease infinite; }
    @keyframes sk { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
    .fade-in-anim { animation: fadeIn .4s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

    /* ---------- Flèches ---------- */
    .car-btn {
        position: absolute; top: 50%; transform: translateY(-50%); z-index: 10;
        width: 48px; height: 48px; border-radius: 9999px; background: #fff; color: #111827;
        border: 1.5px solid #111827; display: flex; align-items: center; justify-content: center;
        transition: background .2s ease, color .2s ease, opacity .2s ease;
    }
    .car-btn:hover { background: #111827; color: #fff; }
    .car-next { right: 0; }
    .car-prev { left: 0; }
    .car-btn[hidden] { display: none; }
    @media (max-width: 767px) { .car-btn { display: none; } .camp-track { padding-right: 16px; } }

    /* ---------- Grille animateur ---------- */
    #anim-list .camp-card { flex: none; width: 100%; max-width: none; min-width: 0; }
</style>

<!-- ================================================================ -->
<!-- HERO                                                              -->
<!-- ================================================================ -->
<section class="relative isolate overflow-hidden hero-bg">
    <img src="https://img3.wallspic.com/crops/1/6/1/1/8/181161/181161-weisshorn-les_reliefs_montagneux-gamme_de_montagne-colline-hiver-3840x2160.jpg"
         alt="" class="absolute inset-0 -z-10 w-full h-full object-cover" loading="eager" fetchpriority="high"
         onerror="this.remove()">
    <div class="absolute inset-0 -z-10 bg-gradient-to-t from-black/45 via-black/10 to-black/5"></div>

    <div class="w-full max-w-[1320px] mx-auto px-5 sm:px-8 pt-5 pb-8 min-h-[300px] md:min-h-[276px] flex flex-col">

        <?php if ($is_logged_in && !$is_animateur): ?>
        <!-- Sélecteur "Rechercher pour : {enfant}" -->
        <div class="flex justify-end items-center gap-3">
            <label for="child-context-select" class="text-[13px] font-bold text-white" style="text-shadow:0 1px 3px rgba(0,0,0,.45)">Rechercher pour&nbsp;:</label>
            <div class="bg-white rounded-xl shadow-md pl-4 pr-3 h-11 flex items-center gap-2">
                <select id="child-context-select" class="appearance-none bg-transparent font-bold text-[13px] text-gray-900 outline-none cursor-pointer max-w-[130px] truncate">
                    <option value="">Toute la famille</option>
                </select>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true" class="text-gray-700 -ml-1"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" class="text-gray-800"><path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z" fill="currentColor"/></svg>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex-1 flex flex-col justify-center text-center pt-2">
            <h1 class="text-[34px] md:text-[40px] leading-tight font-extrabold text-white tracking-tight mb-7" style="text-shadow:0 2px 12px rgba(0,0,0,.35)">
                <?php echo $is_logged_in ? 'Bonjour ' . htmlspecialchars($prenom) . ',' : 'Trouvez la colonie idéale'; ?>
            </h1>

            <!-- Barre de recherche -->
            <form id="hero-search-form" action="recherche" method="GET"
                  class="w-full max-w-[720px] mx-auto text-left bg-white rounded-[28px] md:rounded-full shadow-[0_10px_40px_rgba(0,0,0,.25)] p-2 flex flex-col md:flex-row md:items-center">

                <input type="hidden" name="age" id="hero-age-hidden" value="">

                <div class="flex-[1.05] min-w-0 px-5 py-2 md:py-1.5">
                    <label for="hero-category" class="block h-5 text-[13px] font-bold text-gray-900 leading-5">Catégorie</label>
                    <div class="relative">
                        <select name="themes[]" id="hero-category" class="w-full appearance-none border-0 p-0 pr-5 h-6 text-[14px] text-gray-700 focus:ring-0 outline-none bg-transparent cursor-pointer">
                            <option value="">Toutes catégories</option>
                        </select>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true" class="absolute right-0 top-1/2 -translate-y-1/2 text-gray-800 pointer-events-none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                </div>

                <div class="hidden md:block w-px h-9 bg-gray-200"></div>
                <div class="md:hidden h-px bg-gray-100 mx-5"></div>

                <div class="flex-[1.15] min-w-0 px-5 py-2 md:py-1.5">
                    <label for="hero-name" class="flex items-center gap-1.5 h-5 text-[13px] font-bold text-gray-900 leading-5">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.6"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>
                        Que recherchez-vous ?
                    </label>
                    <input type="text" id="hero-name" name="name" placeholder="Ex : Weekend-Ski" autocomplete="off"
                           class="w-full border-0 p-0 h-6 text-[14px] text-gray-700 placeholder-gray-300 focus:ring-0 outline-none bg-transparent">
                </div>

                <div class="hidden md:block w-px h-9 bg-gray-200"></div>
                <div class="md:hidden h-px bg-gray-100 mx-5"></div>

                <div class="flex-1 min-w-0 px-5 py-2 md:py-1.5">
                    <label for="hero-city" class="flex items-center gap-1.5 h-5 text-[13px] font-bold text-gray-900 leading-5">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z" fill="currentColor"/><circle cx="12" cy="9.5" r="2.4" fill="#fff"/></svg>
                        Localisation
                    </label>
                    <input type="text" id="hero-city" name="city" placeholder="Partout" autocomplete="off"
                           class="w-full border-0 p-0 h-6 text-[14px] text-gray-700 placeholder-gray-600 focus:ring-0 outline-none bg-transparent">
                </div>

                <button type="submit" aria-label="Rechercher"
                        class="m-1 md:ml-2 h-[54px] md:w-[54px] flex-none flex items-center justify-center gap-2 bg-[#2f3d45] hover:bg-[#1f2a30] text-white rounded-[18px] transition-colors">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5" stroke="#fff" stroke-width="2.2"/><path d="M20.5 20.5l-4.6-4.6" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
                    <span class="md:hidden font-bold text-sm">Rechercher</span>
                </button>
            </form>

            <p class="mt-5 text-[13px] text-white" style="text-shadow:0 1px 3px rgba(0,0,0,.45)">
                Vous êtes directeur de camp&nbsp;? <a href="create_camp" class="underline underline-offset-2 hover:text-white/80 transition-colors">Publier une annonce</a>
            </p>
        </div>
    </div>
</section>

<main class="flex-1 w-full max-w-[1320px] mx-auto px-5 sm:px-8 pb-6">

    <?php if (!$is_animateur): ?>
        <?php foreach ($holiday_periods as $i => $period): ?>
        <!-- ======================= <?php echo htmlspecialchars($period['label']); ?> ======================= -->
        <section class="mt-9" aria-labelledby="h-<?php echo $i; ?>">
            <h2 id="h-<?php echo $i; ?>" class="text-[20px] md:text-[22px] font-bold text-gray-900 mb-5"><?php echo htmlspecialchars($period['label']); ?> :</h2>
            <div class="relative">
                <div id="track-<?php echo $i; ?>" class="camp-track"
                     data-start="<?php echo $period['start']; ?>" data-end="<?php echo $period['end']; ?>"></div>
                <button type="button" class="car-btn car-prev" data-target="track-<?php echo $i; ?>" aria-label="Précédent" hidden>
                    <span class="rotate-180 flex"><?php echo $arrow_svg; ?></span>
                </button>
                <button type="button" class="car-btn car-next" data-target="track-<?php echo $i; ?>" aria-label="Suivant"><?php echo $arrow_svg; ?></button>
            </div>
        </section>

        <?php if ($i === 0): ?>
        <!-- ======================= BANNIÈRE ======================= -->
        <section class="mt-12" aria-label="Rechercher une colonie">
            <div class="relative bg-[#f8f5ea] rounded-2xl px-6 py-10 md:py-11 text-center overflow-hidden">
                <svg class="hidden sm:block absolute left-[8%] bottom-3 text-[#26343b]" width="84" height="76" viewBox="0 0 84 76" fill="none" aria-hidden="true">
                    <path d="M26 3L12 24h8L7 44h10L3 64h46L35 44h10L32 24h8L26 3Z" fill="currentColor"/>
                    <rect x="24" y="64" width="4" height="9" fill="currentColor"/>
                    <path d="M50 70L66 32l17 38H50Z" fill="currentColor"/><path d="M66 32v38" stroke="#f8f5ea" stroke-width="2"/>
                    <path d="M2 74h80" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <svg class="hidden sm:block absolute right-[8%] bottom-3 text-[#26343b]" width="84" height="76" viewBox="0 0 84 76" fill="none" aria-hidden="true">
                    <path d="M26 3L12 24h8L7 44h10L3 64h46L35 44h10L32 24h8L26 3Z" fill="currentColor"/>
                    <rect x="24" y="64" width="4" height="9" fill="currentColor"/>
                    <path d="M50 70L66 32l17 38H50Z" fill="currentColor"/><path d="M66 32v38" stroke="#f8f5ea" stroke-width="2"/>
                    <path d="M2 74h80" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>

                <p class="text-[28px] font-extrabold text-gray-900 tracking-tight">ColoMap</p>
                <p class="mt-1 text-[15px] text-gray-600 max-w-md mx-auto">Recherchez une colonie de vacances adaptée pour votre enfant.</p>
                <a href="recherche" class="mt-5 inline-block bg-[#3a4852] hover:bg-[#26323a] text-white text-[16px] font-bold px-16 py-2.5 rounded-xl transition-colors">Rechercher</a>
            </div>
        </section>
        <?php endif; ?>
        <?php endforeach; ?>

        <!-- Secours : affiché seulement si aucune offre ne tombe dans les périodes de vacances -->
        <section id="fallback-section" class="mt-9" hidden aria-labelledby="h-fb">
            <h2 id="h-fb" class="text-[20px] md:text-[22px] font-bold text-gray-900 mb-5"><?php echo $is_logged_in ? 'Sélection pour vous :' : 'Séjours à venir :'; ?></h2>
            <div class="relative">
                <div id="track-fallback" class="camp-track" data-lazy="1"
                     data-start="<?php echo $today; ?>" data-end="<?php echo $far_future; ?>"></div>
                <button type="button" class="car-btn car-prev" data-target="track-fallback" aria-label="Précédent" hidden>
                    <span class="rotate-180 flex"><?php echo $arrow_svg; ?></span>
                </button>
                <button type="button" class="car-btn car-next" data-target="track-fallback" aria-label="Suivant"><?php echo $arrow_svg; ?></button>
            </div>
        </section>
    <?php else: ?>
        <!-- Variante Animateur : missions d'animation éligibles -->
        <section class="mt-9">
            <h2 class="text-[20px] md:text-[22px] font-bold text-gray-900 mb-5">Missions ouvertes pour vous :</h2>
            <div id="anim-list" class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-4"></div>
            <div id="anim-empty" class="hidden text-center py-10 text-gray-400">Aucune mission éligible pour le moment. Essayez d'affiner votre recherche ci-dessus.</div>
        </section>
    <?php endif; ?>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const isLoggedIn  = <?php echo json_encode($is_logged_in); ?>;
    const isAnimateur = <?php echo json_encode($is_animateur); ?>;
    let userFavorites = <?php echo json_encode(array_map('intval', (array)$user_favorites)); ?>;

    // ------------------------------------------------------------
    // Icônes / visuels (SVG inline, aucune dépendance externe)
    // ------------------------------------------------------------
    const ICON_PIN   = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z" fill="#4b5563"/><circle cx="12" cy="9.5" r="2.3" fill="#f5f5f5"/></svg>';
    const ICON_HEART = '<svg width="12" height="12" viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="M12 21l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.18L12 21z"/></svg>';
    const ICON_ORG   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z" fill="#9ca3af"/></svg>';
    window.CAMP_PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e8eef5"/><stop offset="1" stop-color="#cfdae7"/></linearGradient></defs>' +
        '<rect width="200" height="200" fill="url(#g)"/><g fill="#8aa0b6"><path d="M84 52L66 80h10L60 104h12L54 130h60L96 104h12L92 80h10L84 52z"/><rect x="82" y="130" width="5" height="12"/><path d="M116 140l22-50 22 50z"/></g></svg>');

    const MONTHS = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function safeImg(url) { return url ? String(url).replace(/\\/g, '/') : ''; }
    function formatDateRange(start, end) {
        const s = start ? new Date(start) : null, e = end ? new Date(end) : null;
        if (!s || isNaN(s)) return '';
        if (!e || isNaN(e)) return `Dès le ${s.getDate()} ${MONTHS[s.getMonth()]} ${s.getFullYear()}`;
        if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear())
            return `Du ${s.getDate()} au ${e.getDate()} ${MONTHS[e.getMonth()]} ${e.getFullYear()}`;
        return `Du ${s.getDate()} ${MONTHS[s.getMonth()]} au ${e.getDate()} ${MONTHS[e.getMonth()]} ${e.getFullYear()}`;
    }

    // Une image qui ne charge pas est remplacée par le visuel ColoMap (taille inchangée)
    document.addEventListener('error', function (ev) {
        const img = ev.target;
        if (!(img instanceof HTMLImageElement)) return;
        if (img.closest('.camp-media')) { if (img.src !== window.CAMP_PLACEHOLDER) img.src = window.CAMP_PLACEHOLDER; }
        else if (img.closest('.camp-avatar')) { img.remove(); }
    }, true);

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
        const price = (camp.prix !== null && camp.prix !== undefined && !isNaN(camp.prix)) ? `Dès <b>${Number(camp.prix).toFixed(2)}</b>€` : '';

        return `
        <article class="camp-card fade-in-anim">
            <div class="camp-org">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="camp-avatar">${logo}</span>
                    <span class="camp-org-name">${esc(camp.organisateur_nom || 'ColoMap')}</span>
                </div>
                ${(!isAnimateur && isLoggedIn) ? `<button type="button" class="fav-btn favorite-button${isFav ? ' is-fav' : ''}" data-camp-id="${esc(camp.id)}" aria-label="Ajouter aux favoris" aria-pressed="${isFav}">${ICON_HEART}</button>` : ''}
            </div>
            <a href="${href}" class="camp-media" aria-label="${esc(camp.nom)}">
                <img src="${esc(img)}" alt="${esc(camp.nom)}" loading="lazy" decoding="async">
            </a>
            <a href="${href}" class="block">
                <h3 class="camp-title" title="${esc(camp.nom)}">${esc(camp.nom)}</h3>
                <p class="camp-price">${price}</p>
                <p class="camp-city">${camp.ville ? ICON_PIN + '<span class="truncate">' + esc(camp.ville) + '</span>' : ''}</p>
                <p class="camp-date">${esc(formatDateRange(camp.date_debut, camp.date_fin))}</p>
            </a>
        </article>`;
    }

    function skeletonCard() {
        return `<div class="camp-card" aria-hidden="true">
            <div class="camp-org"><div class="flex items-center gap-1.5"><div class="skeleton w-[26px] h-[26px] rounded-full"></div><div class="skeleton h-3 w-20 rounded"></div></div></div>
            <div class="skeleton camp-media"></div>
            <div class="skeleton h-3 w-4/5 rounded mt-3"></div>
            <div class="skeleton h-3 w-1/2 rounded mt-2"></div>
            <div class="skeleton h-3 w-2/3 rounded mt-2"></div>
        </div>`;
    }

    // ------------------------------------------------------------
    // Favoris (mise à jour immédiate, retour arrière si le réseau échoue)
    // ------------------------------------------------------------
    async function toggleFavorite(button) {
        const campId = parseInt(button.dataset.campId, 10);
        const wasFav = button.classList.contains('is-fav');
        const setFav = (on) => {
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
    document.body.addEventListener('click', function (e) {
        const btn = e.target.closest('.favorite-button');
        if (btn) { e.preventDefault(); e.stopPropagation(); toggleFavorite(btn); }
    });

    // ------------------------------------------------------------
    // Carrousels : flèches précédent / suivant selon la position
    // ------------------------------------------------------------
    function bindCarousel(track) {
        const wrap = track.parentElement;
        const prev = wrap.querySelector('.car-prev'), next = wrap.querySelector('.car-next');
        const update = () => {
            const max = track.scrollWidth - track.clientWidth - 4;
            if (prev) prev.hidden = track.scrollLeft <= 4;
            if (next) next.hidden = track.scrollLeft >= max;
        };
        const step = () => Math.max(track.clientWidth * 0.85, 212);
        if (next) next.addEventListener('click', () => track.scrollBy({ left: step() }));
        if (prev) prev.addEventListener('click', () => track.scrollBy({ left: -step() }));
        track.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        track._updateArrows = update;
        update();
    }
    document.querySelectorAll('.camp-track').forEach(bindCarousel);

    // ------------------------------------------------------------
    // Catégories (hero) : depuis la base
    // ------------------------------------------------------------
    fetch('api/get_themes.php').then(r => r.json()).then(themes => {
        const sel = document.getElementById('hero-category');
        if (!sel || !Array.isArray(themes)) return;
        [...themes].sort((a, b) => a.name.localeCompare(b.name)).forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.name; opt.textContent = t.name;
            sel.appendChild(opt);
        });
    }).catch(() => {});

    // ------------------------------------------------------------
    // Sélecteur "Rechercher pour : {enfant}"
    // ------------------------------------------------------------
    let currentChildAge = '';
    if (isLoggedIn && !isAnimateur) {
        fetch('api/get_children.php').then(r => r.json()).then(children => {
            const sel = document.getElementById('child-context-select');
            if (!sel || !Array.isArray(children) || children.length === 0) return;
            children.forEach(c => {
                if (c.age === null || c.age === undefined) return;
                const opt = document.createElement('option');
                opt.value = c.age; opt.textContent = `${c.prenom} (${c.age} ans)`;
                sel.appendChild(opt);
            });
            sel.addEventListener('change', () => {
                currentChildAge = sel.value;
                document.getElementById('hero-age-hidden').value = currentChildAge;
                loadAllSections();
            });
        }).catch(() => {});
    }

    // ------------------------------------------------------------
    // Sections "Vacances de ..." : personnalisées + datées
    // ------------------------------------------------------------
    function loadSection(track) {
        const section = track.closest('section');
        section.hidden = false;
        track.innerHTML = Array(7).fill(skeletonCard()).join('');
        track.scrollLeft = 0;
        const params = new URLSearchParams({ limit: 14, date_start: track.dataset.start, date_end: track.dataset.end });
        if (currentChildAge !== '') params.set('child_age', currentChildAge);

        return fetch(`api/get_recommendations.php?${params}`)
            .then(r => r.json())
            .then(data => {
                if (data && Array.isArray(data.favorites)) userFavorites = data.favorites.map(Number);
                const camps = (data && data.camps) || [];
                if (camps.length === 0) { section.hidden = true; return false; }
                track.innerHTML = camps.map(campCard).join('');
                requestAnimationFrame(() => track._updateArrows && track._updateArrows());
                return true;
            })
            .catch(() => { section.hidden = true; return false; });
    }

    function loadAllSections() {
        const periodTracks = [...document.querySelectorAll('.camp-track:not([data-lazy])')];
        const fb = document.getElementById('track-fallback');
        Promise.all(periodTracks.map(loadSection)).then(results => {
            // Aucune offre dans les périodes de vacances : on propose les prochains séjours
            if (fb && !results.some(Boolean)) loadSection(fb);
            else if (fb) fb.closest('section').hidden = true;
        });
    }

    if (!isAnimateur) {
        loadAllSections();
    } else {
        // Variante animateur : missions éligibles
        const animList = document.getElementById('anim-list');
        const animEmpty = document.getElementById('anim-empty');
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
