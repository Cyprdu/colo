<?php
/**
 * Fichier : index.php  (page d'accueil)
 * ---------------------------------------------------------------
 * Réplique fidèle de la maquette fournie par le client :
 *   - Hero plein écran (photo de montagne), sélecteur "Rechercher
 *     pour : {enfant}", recherche à 3 champs (Catégorie / Mot-clé /
 *     Localisation) avec bouton icône sombre.
 *   - Sections horizontales groupées par période de vacances
 *     scolaires ("Vacances de la Toussaint", "de Noël", "au Ski"),
 *     chacune alimentée par le moteur de recommandations
 *     PERSONNALISÉES (api/get_recommendations.php) restreint à la
 *     fenêtre de dates correspondante : on obtient donc à la fois
 *     le regroupement demandé par la maquette ET une vraie
 *     pertinence par utilisateur (âge de l'enfant, favoris...).
 *   - Bannière centrale crème + icônes "sapin" en SVG.
 *   - Toutes les icônes sont du SVG inline : plus aucune dépendance
 *     à une police d'icônes externe (source des bugs d'affichage
 *     constatés précédemment si le CDN ne charge pas).
 */

require_once 'partials/header3.php';

$is_logged_in   = isset($_SESSION['user']);
$is_animateur   = $is_logged_in && ($_SESSION['user']['is_animateur'] ?? false);
$prenom         = $is_logged_in ? ($_SESSION['user']['prenom'] ?? '') : '';
$user_favorites = $_SESSION['user']['favorites'] ?? [];

// ------------------------------------------------------------------
// Fenêtres de vacances scolaires françaises (zones A/B/C confondues,
// pour couvrir un maximum de séjours). À ajuster chaque année scolaire
// — volontairement isolé ici en tête de fichier pour rester facile à
// maintenir sans toucher au reste de la page.
// ------------------------------------------------------------------
$holiday_periods = [
    ['label' => 'Vacances de la Toussaint', 'start' => '2026-10-17', 'end' => '2026-11-02'],
    ['label' => 'Vacances de Noël',         'start' => '2026-12-19', 'end' => '2027-01-04'],
    ['label' => "Vacances d'Hiver (Ski)",   'start' => '2027-02-06', 'end' => '2027-03-09'],
];
$today = date('Y-m-d');
// On ne garde que les périodes pas encore terminées pour que la page
// reste pertinente automatiquement au fil du temps, sans intervention.
$holiday_periods = array_values(array_filter($holiday_periods, function ($p) use ($today) {
    return $p['end'] >= $today;
}));
// Filet de sécurité si toutes les périodes ci-dessus sont dépassées un jour :
if (empty($holiday_periods)) {
    $holiday_periods = [
        ['label' => 'Séjours à venir', 'start' => $today, 'end' => date('Y-m-d', strtotime('+6 months'))],
    ];
}
?>

<!-- ================================================================ -->
<!-- HERO PLEIN LARGEUR                                                -->
<!-- ================================================================ -->
<section class="relative mb-12 overflow-hidden">
    <div class="absolute inset-0">
        <img src="https://img3.wallspic.com/crops/1/6/1/1/8/181161/181161-weisshorn-les_reliefs_montagneux-gamme_de_montagne-colline-hiver-3840x2160.jpg"
             alt="" class="w-full h-full object-cover" loading="eager">
        <div class="absolute inset-0 bg-gradient-to-t from-black/65 via-black/25 to-black/10"></div>
    </div>

    <div class="relative z-10 container mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-6 pb-20 min-h-[440px] flex flex-col">

        <?php if ($is_logged_in && !$is_animateur): ?>
        <!-- Sélecteur "Rechercher pour : {enfant}" -->
        <div class="flex justify-end mb-8">
            <div class="bg-white/95 backdrop-blur rounded-full shadow-md pl-4 pr-1.5 py-1.5 flex items-center gap-2 text-sm">
                <span class="text-gray-500 whitespace-nowrap">Rechercher pour&nbsp;:</span>
                <select id="child-context-select" class="bg-transparent font-bold text-gray-800 outline-none cursor-pointer pr-1 max-w-[120px] truncate">
                    <option value="">Toute la famille</option>
                </select>
                <span id="child-context-avatar" class="w-7 h-7 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z" fill="currentColor"/></svg>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-auto text-center">
            <h1 class="text-4xl md:text-5xl font-extrabold text-white drop-shadow mb-8">
                <?php echo $is_logged_in ? 'Bonjour ' . htmlspecialchars($prenom) . ',' : 'Trouvez la colonie idéale'; ?>
            </h1>

            <!-- Barre de recherche flottante -->
            <form id="hero-search-form" action="recherche.php" method="GET"
                  class="w-full max-w-4xl mx-auto text-left bg-white rounded-2xl shadow-2xl p-1.5 flex flex-col md:flex-row items-stretch divide-y md:divide-y-0 md:divide-x divide-gray-100">

                <input type="hidden" name="age" id="hero-age-hidden" value="">

                <div class="flex-1 min-w-0 px-5 py-3">
                    <label class="flex items-center gap-1.5 text-xs font-bold text-gray-700 mb-1">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-400" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5" stroke="currentColor" stroke-width="2"/></svg>
                        Catégorie
                    </label>
                    <select name="themes[]" id="hero-category" class="w-full border-0 p-0 text-sm text-gray-800 focus:ring-0 outline-none bg-transparent cursor-pointer">
                        <option value="">Toutes catégories</option>
                    </select>
                </div>

                <div class="flex-[1.4] min-w-0 px-5 py-3">
                    <label class="flex items-center gap-1.5 text-xs font-bold text-gray-700 mb-1">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-400" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        Que recherchez-vous ?
                    </label>
                    <input type="text" name="name" placeholder="Ex : Weekend-Ski"
                           class="w-full border-0 p-0 text-sm text-gray-800 placeholder-gray-400 focus:ring-0 outline-none bg-transparent">
                </div>

                <div class="flex-1 min-w-0 px-5 py-3">
                    <label class="flex items-center gap-1.5 text-xs font-bold text-gray-700 mb-1">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-400" aria-hidden="true"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="9.5" r="2.3" stroke="currentColor" stroke-width="2"/></svg>
                        Localisation
                    </label>
                    <input type="text" name="city" placeholder="Partout"
                           class="w-full border-0 p-0 text-sm text-gray-800 placeholder-gray-400 focus:ring-0 outline-none bg-transparent">
                </div>

                <div class="p-1.5 flex items-stretch">
                    <button type="submit" aria-label="Rechercher"
                            class="w-12 md:w-14 flex items-center justify-center bg-gray-900 hover:bg-black rounded-xl transition-colors">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="7" stroke="#fff" stroke-width="2.2"/><path d="M21 21l-4.3-4.3" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
                    </button>
                </div>
            </form>

            <p class="mt-4 text-sm text-white/90">
                Vous êtes directeur de camp&nbsp;? <a href="create_camp.php" class="underline font-semibold hover:text-white transition-colors">Publier une annonce</a>
            </p>
        </div>
    </div>
</section>

<main class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-7xl pb-20">

    <?php if (!$is_animateur): ?>
        <?php foreach ($holiday_periods as $i => $period): ?>
        <!-- ======================= SECTION VACANCES ======================= -->
        <section class="mb-14 relative">
            <h2 class="text-xl md:text-2xl font-bold text-gray-900 mb-5"><?php echo htmlspecialchars($period['label']); ?> :</h2>

            <div class="relative">
                <div id="track-<?php echo $i; ?>" class="camp-track flex gap-5 overflow-x-auto scroll-smooth pb-2"
                     data-start="<?php echo $period['start']; ?>" data-end="<?php echo $period['end']; ?>"></div>

                <button type="button" data-target="track-<?php echo $i; ?>"
                        class="carousel-next hidden md:flex absolute top-1/2 -right-4 -translate-y-1/2 w-10 h-10 rounded-full bg-white shadow-lg border border-gray-100 items-center justify-center hover:bg-gray-50 transition-colors z-10"
                        aria-label="Voir plus">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 5l7 7-7 7" stroke="#111827" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </section>

        <?php if ($i === 0): ?>
        <!-- ======================= BANNIÈRE CENTRALE ======================= -->
        <section class="mb-14">
            <div class="relative bg-[#F6F1E6] rounded-2xl px-6 py-12 text-center overflow-hidden">
                <svg class="absolute left-6 bottom-4 opacity-90 -rotate-6" width="34" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M12 2.5L7.3 9.3H9.6L6.4 13.8H9.2L5.8 18.7H18.2L14.8 13.8H17.6L14.4 9.3H16.7L12 2.5Z" fill="#3E6B4C"/>
                    <rect x="11.1" y="18.7" width="1.8" height="2.8" fill="#6B4423"/>
                </svg>
                <svg class="absolute right-8 bottom-2 opacity-90 rotate-6" width="52" height="60" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M12 2.5L7.3 9.3H9.6L6.4 13.8H9.2L5.8 18.7H18.2L14.8 13.8H17.6L14.4 9.3H16.7L12 2.5Z" fill="#3E6B4C"/>
                    <rect x="11.1" y="18.7" width="1.8" height="2.8" fill="#6B4423"/>
                </svg>

                <h3 class="text-3xl font-extrabold text-gray-900 mb-2">ColoMap</h3>
                <p class="text-gray-500 mb-6 max-w-md mx-auto">Recherchez une colonie de vacances adaptée pour votre enfant.</p>
                <a href="recherche.php" class="inline-block bg-gray-900 hover:bg-black text-white font-semibold px-8 py-3 rounded-full transition-colors">Rechercher</a>
            </div>
        </section>
        <?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- Variante Animateur : missions d'animation éligibles -->
        <section class="mb-14 relative">
            <h2 class="text-xl md:text-2xl font-bold text-gray-900 mb-5">Missions ouvertes pour vous :</h2>
            <div id="anim-list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"></div>
            <div id="anim-empty" class="hidden text-center py-10 text-gray-400">Aucune mission éligible pour le moment. Essayez d'affiner votre recherche ci-dessus.</div>
        </section>
    <?php endif; ?>

</main>

<style>
.camp-track::-webkit-scrollbar { height: 0; }
.camp-track { scrollbar-width: none; }
.camp-track .camp-card { flex: 0 0 230px; }
#anim-list .camp-card { width: 100%; }
.skeleton { background: linear-gradient(90deg,#f1f5f9 25%,#f8fafc 37%,#f1f5f9 63%); background-size: 400% 100%; animation: skeleton-loading 1.4s ease infinite; }
@keyframes skeleton-loading { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
.fade-in-anim { animation: fadeIn .4s ease-in-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const isLoggedIn  = <?php echo json_encode($is_logged_in); ?>;
    const isAnimateur = <?php echo json_encode($is_animateur); ?>;
    let userFavorites = <?php echo json_encode($user_favorites); ?>;

    // ------------------------------------------------------------
    // Icônes SVG réutilisées côté client (aucune police d'icônes externe)
    // ------------------------------------------------------------
    const ICON_PIN = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z" stroke="#9CA3AF" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="9.5" r="2.1" stroke="#9CA3AF" stroke-width="2"/></svg>';
    const ICON_HEART_OUTLINE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4.318 6.318a4.5 4.5 0 016.364 0L12 7.5l1.318-1.182a4.5 4.5 0 116.364 6.364L12 21l-7.682-7.682a4.5 4.5 0 010-6.364z" stroke="#6B7280" stroke-width="1.8"/></svg>';
    const ICON_HEART_FILLED  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="#EF4444" xmlns="http://www.w3.org/2000/svg"><path d="M4.318 6.318a4.5 4.5 0 016.364 0L12 7.5l1.318-1.182a4.5 4.5 0 116.364 6.364L12 21l-7.682-7.682a4.5 4.5 0 010-6.364z"/></svg>';
    const ICON_ORG_PLACEHOLDER = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z" fill="#fff"/></svg>';

    function safeImg(url) {
        if (!url) return 'https://placehold.co/500x500/f1f5f9/94a3b8?text=ColoMap';
        return url.replace(/\\/g, '/');
    }
    function formatDateRange(start, end) {
        const opts = { day: 'numeric', month: 'long', year: 'numeric' };
        try {
            const s = start ? new Date(start) : null;
            const e = end ? new Date(end) : null;
            if (s && e) return `Du ${s.toLocaleDateString('fr-FR', {day:'numeric', month:'long'})} au ${e.toLocaleDateString('fr-FR', opts)}`;
            if (s) return `Dès le ${s.toLocaleDateString('fr-FR', opts)}`;
            return '';
        } catch (e) { return ''; }
    }

    // ------------------------------------------------------------
    // Carte camp (style maquette : organisateur au-dessus de l'image)
    // ------------------------------------------------------------
    function campCard(camp) {
        const detailPage = isAnimateur ? 'info-camp-animateur' : 'camp_details';
        const linkParam   = isAnimateur ? `id=${camp.id}` : `t=${camp.token}`;
        const isFavorited = !isAnimateur && isLoggedIn && userFavorites.includes(camp.id);
        const orgLogo = camp.organisateur_logo
            ? `<img src="${safeImg(camp.organisateur_logo)}" class="w-full h-full object-cover rounded-full">`
            : ICON_ORG_PLACEHOLDER;

        return `
        <div class="camp-card fade-in-anim">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="w-6 h-6 rounded-full bg-gray-300 flex items-center justify-center flex-shrink-0 overflow-hidden">${orgLogo}</span>
                    <span class="text-xs font-semibold text-gray-600 truncate">${camp.organisateur_nom || 'ColoMap'}</span>
                </div>
                ${(!isAnimateur && isLoggedIn) ? `
                <button class="favorite-button w-7 h-7 rounded-full bg-white border border-gray-100 shadow-sm flex items-center justify-center flex-shrink-0 hover:scale-105 active:scale-95 transition-transform" data-camp-id="${camp.id}">
                    ${isFavorited ? ICON_HEART_FILLED : ICON_HEART_OUTLINE}
                </button>` : ''}
            </div>

            <a href="${detailPage}?${linkParam}" class="block rounded-xl overflow-hidden bg-gray-100 aspect-square mb-2.5 group">
                <img src="${safeImg(camp.image_url)}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                     onerror="this.src='https://placehold.co/500x500/f1f5f9/94a3b8?text=ColoMap';">
            </a>

            <a href="${detailPage}?${linkParam}" class="block">
                <h3 class="font-bold text-gray-900 text-sm mb-1 truncate">${camp.nom}</h3>
                ${!isAnimateur ? `<p class="text-sm text-gray-800 mb-1">Dès <span class="font-bold">${Math.round(camp.prix)}</span>€</p>` : ''}
                <p class="flex items-center gap-1 text-xs text-gray-500 mb-0.5">${ICON_PIN} ${camp.ville || ''}</p>
                <p class="text-xs text-gray-400">${formatDateRange(camp.date_debut, camp.date_fin)}</p>
            </a>
        </div>`;
    }

    function skeletonCard() {
        return `<div class="camp-card">
            <div class="flex items-center gap-1.5 mb-2"><div class="skeleton w-6 h-6 rounded-full"></div><div class="skeleton h-3 w-20 rounded"></div></div>
            <div class="skeleton aspect-square rounded-xl mb-2.5"></div>
            <div class="skeleton h-3 w-4/5 rounded mb-1.5"></div>
            <div class="skeleton h-3 w-1/2 rounded"></div>
        </div>`;
    }

    // ------------------------------------------------------------
    // Favoris (toggle optimiste avec rollback si échec réseau)
    // ------------------------------------------------------------
    async function toggleFavorite(button) {
        const campId = parseInt(button.dataset.campId, 10);
        const wasFavorited = button.innerHTML.indexOf('#EF4444') !== -1;
        button.innerHTML = wasFavorited ? ICON_HEART_OUTLINE : ICON_HEART_FILLED;
        userFavorites = wasFavorited ? userFavorites.filter(id => id !== campId) : [...userFavorites, campId];

        try {
            const res = await fetch('api/toggle_favorite.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ campId })
            });
            const result = await res.json();
            if (!result.success) throw new Error('revert');
        } catch (e) {
            button.innerHTML = wasFavorited ? ICON_HEART_FILLED : ICON_HEART_OUTLINE;
            userFavorites = wasFavorited ? [...userFavorites, campId] : userFavorites.filter(id => id !== campId);
        }
    }
    document.body.addEventListener('click', function (e) {
        const btn = e.target.closest('.favorite-button');
        if (btn) { e.preventDefault(); e.stopPropagation(); toggleFavorite(btn); }
    });

    // ------------------------------------------------------------
    // Défilement des carrousels via le bouton flèche
    // ------------------------------------------------------------
    document.querySelectorAll('.carousel-next').forEach(btn => {
        btn.addEventListener('click', () => {
            const track = document.getElementById(btn.dataset.target);
            if (track) track.scrollBy({ left: 720, behavior: 'smooth' });
        });
    });

    // ------------------------------------------------------------
    // Catégories (hero) — depuis la BDD
    // ------------------------------------------------------------
    fetch('api/get_themes.php').then(r => r.json()).then(themes => {
        const sel = document.getElementById('hero-category');
        if (!sel || !Array.isArray(themes)) return;
        [...themes].sort((a, b) => a.name.localeCompare(b.name)).forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.name; // comparaison texte côté serveur (colonnes libres theme/type)
            opt.textContent = t.name;
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
                opt.value = c.age;
                opt.dataset.prenom = c.prenom;
                opt.textContent = `${c.prenom} (${c.age} ans)`;
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
    // Chargement des sections "Vacances de ..." (personnalisées + datées)
    // ------------------------------------------------------------
    function loadSection(track) {
        track.innerHTML = Array(6).fill(skeletonCard()).join('');
        const params = new URLSearchParams({
            limit: 12,
            date_start: track.dataset.start,
            date_end: track.dataset.end,
        });
        if (currentChildAge !== '') params.set('child_age', currentChildAge);

        fetch(`api/get_recommendations.php?${params.toString()}`)
            .then(r => r.json())
            .then(data => {
                const camps = (data && data.camps) || [];
                if (camps.length === 0) {
                    track.parentElement.parentElement.classList.add('hidden');
                    return;
                }
                track.innerHTML = camps.map(c => campCard(c)).join('');
            })
            .catch(() => { track.parentElement.parentElement.classList.add('hidden'); });
    }

    function loadAllSections() {
        document.querySelectorAll('.camp-track').forEach(loadSection);
    }

    if (!isAnimateur) {
        loadAllSections();
    } else {
        // ------------------------------------------------------------
        // Variante animateur : missions éligibles (liste courte)
        // ------------------------------------------------------------
        const animList = document.getElementById('anim-list');
        const animEmpty = document.getElementById('anim-empty');
        animList.innerHTML = Array(8).fill(skeletonCard()).join('');
        fetch('api/get_eligible_camps_for_animator.php')
            .then(r => r.json())
            .then(camps => {
                if (!Array.isArray(camps) || camps.length === 0) {
                    animList.innerHTML = '';
                    animEmpty.classList.remove('hidden');
                    return;
                }
                animList.innerHTML = camps.slice(0, 8).map(c => campCard(c)).join('');
            })
            .catch(() => { animList.innerHTML = ''; animEmpty.classList.remove('hidden'); });
    }
});
</script>

<?php require_once 'partials/footer.php'; ?>