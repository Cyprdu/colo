<?php
// camp_details.php : fiche d'un séjour (design de l'accueil + choix de session)
require_once 'api/config.php';
require_once 'partials/header3.php';

$is_logged_in = isset($_SESSION['user']);
$user_favorites = $_SESSION['user']['favorites'] ?? [];
$token = $_GET['t'] ?? null;
$id_param = $_GET['id'] ?? null;
$camp_id = null;

try {
    if ($token) {
        $stmt = $pdo->prepare("SELECT id FROM camps WHERE token = ?");
        $stmt->execute([$token]);
        $res = $stmt->fetch();
        if ($res) $camp_id = $res['id'];
    } elseif ($id_param) {
        $stmt = $pdo->prepare("SELECT id, prive FROM camps WHERE id = ?");
        $stmt->execute([$id_param]);
        $res = $stmt->fetch();
        if ($res) {
            // Camp privé : réservé à l'administrateur
            if ($res['prive'] == 1 && (!isset($_SESSION['user']['is_admin']) || !$_SESSION['user']['is_admin'])) {
                echo "<script>window.location.href='./';</script>";
                exit;
            }
            $camp_id = $res['id'];
        }
    }
} catch (Exception $e) {}
?>

<style>
    :root { --ink:#26343b; --accent:#1478d4; --accent-dark:#0f62ae; --cream:#f8f5ea; --muted:#6b7780; --line:#e5e7eb; }
    .cd-h2 { font-size:20px; font-weight:700; color:var(--ink); margin-bottom:14px; }
    .cd-chip { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:9999px; background:#f1f3f5; color:#3b4a54; font-size:13.5px; font-weight:600; }
    .cd-chip svg { flex:none; color:#64748b; }
    .cd-icon-btn { display:inline-flex; align-items:center; gap:8px; height:44px; padding:0 16px; border-radius:9999px; border:1.5px solid var(--line); background:#fff; color:var(--ink); font-size:14px; font-weight:600; transition:background .15s ease, border-color .15s ease, color .15s ease; }
    .cd-icon-btn:hover { background:#f1f3f5; border-color:#cfd5db; }
    .cd-icon-btn.is-fav { color:#e5484d; border-color:#f3c5c7; background:#fff5f5; }
    .cd-icon-btn.is-fav svg { fill:#e5484d; }
    .cd-card { background:#fff; border:1px solid var(--line); border-radius:24px; box-shadow:0 8px 30px rgba(38,52,59,.07); }
    .cd-btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:15px 20px; border-radius:14px; background:#2f3d45; color:#fff; font-weight:700; font-size:16px; transition:background .2s ease, transform .15s ease; text-align:center; }
    .cd-btn:hover { background:#1f2a30; transform:translateY(-1px); }
    .cd-btn-light { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:13px 18px; border-radius:14px; background:#fff; border:1.5px solid var(--line); color:var(--ink); font-weight:700; font-size:15px; transition:background .15s ease; }
    .cd-btn-light:hover { background:#f1f3f5; }
    .session { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border:1.5px solid var(--line); border-radius:14px; background:#fff; transition:border-color .15s ease, background .15s ease; }
    a.session:hover { border-color:#9db9d6; background:#f7fafd; }
    .session.is-current { border-color:var(--accent); background:#f1f7fd; }
    .session.is-full { opacity:.6; }
    .skeleton { background:linear-gradient(90deg,#eceef1 25%,#f6f7f9 37%,#eceef1 63%); background-size:400% 100%; animation:sk 1.4s ease infinite; }
    @keyframes sk { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }
    .fade-in-anim { animation:fadeIn .4s ease-out; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
</style>

<main class="flex-1 w-full max-w-[1320px] mx-auto px-5 sm:px-8 pt-6 pb-16">

    <!-- Chargement -->
    <div id="loader" class="py-10" aria-live="polite">
        <div class="skeleton h-8 w-2/3 rounded-lg mb-4"></div>
        <div class="skeleton h-5 w-1/3 rounded mb-8"></div>
        <div class="skeleton rounded-3xl w-full" style="height:380px"></div>
    </div>

    <div id="camp-content" class="hidden fade-in-anim">

        <!-- Barre du haut -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <a href="recherche" id="back-link" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Retour
            </a>
            <div class="flex flex-wrap items-center gap-2">
                <button id="btn-share" type="button" class="cd-icon-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m0-12L8 7m4-4l4 4M5 12v6a2 2 0 002 2h10a2 2 0 002-2v-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span id="share-label">Partager</span>
                </button>
                <?php if ($is_logged_in): ?>
                <button id="btn-favorite" type="button" class="cd-icon-btn" aria-pressed="false">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20.5l-1.35-1.22C5.9 15.02 3 12.4 3 9.1 3 6.5 5 4.5 7.6 4.5c1.5 0 2.9.7 3.8 1.8l.6.8.6-.8c.9-1.1 2.3-1.8 3.8-1.8 2.6 0 4.6 2 4.6 4.6 0 3.3-2.9 5.9-7.65 10.18L12 20.5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                    Favori
                </button>
                <?php endif; ?>
                <button id="btn-calendar" type="button" class="cd-icon-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 3v4m8-4v4M4 9h16M6 5h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Ajouter au calendrier
                </button>
            </div>
        </div>

        <!-- Titre -->
        <div class="mb-6">
            <div id="hero-badges" class="flex flex-wrap gap-2 mb-3"></div>
            <h1 id="hero-title" class="text-[28px] md:text-[38px] leading-tight font-extrabold text-[#26343b]"></h1>
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <span class="cd-chip"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="9.5" r="2.4" stroke="currentColor" stroke-width="2"/></svg><span id="hero-location"></span></span>
                <span class="cd-chip"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 3v4m8-4v4M4 9h16M6 5h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span id="hero-dates"></span></span>
                <span class="cd-chip"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span id="hero-age-tag"></span></span>
                <span class="cd-chip"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" stroke="currentColor" stroke-width="2"/></svg><span id="stats-views">0</span> vues</span>
                <span class="cd-chip"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20.5l-1.35-1.22C5.9 15.02 3 12.4 3 9.1 3 6.5 5 4.5 7.6 4.5c1.5 0 2.9.7 3.8 1.8l.6.8.6-.8c.9-1.1 2.3-1.8 3.8-1.8 2.6 0 4.6 2 4.6 4.6 0 3.3-2.9 5.9-7.65 10.18L12 20.5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg><span id="stats-likes">0</span> favoris</span>
            </div>
        </div>

        <!-- Image -->
        <div class="relative rounded-3xl overflow-hidden bg-[#e5e7eb]" style="width:100%; height:clamp(220px, 34vw, 460px)">
            <img id="hero-image" src="" alt="Couverture du séjour" class="absolute inset-0 w-full h-full object-cover">
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 mt-10 relative">

            <!-- Colonne principale -->
            <div class="lg:col-span-2 space-y-10 min-w-0">

                <section aria-labelledby="h-about">
                    <h2 id="h-about" class="cd-h2">À propos du séjour</h2>
                    <div class="relative">
                        <div id="camp-desc-container" class="text-[16px] leading-relaxed text-[#3b4a54] max-h-44 overflow-hidden transition-all duration-500 ease-in-out">
                            <div id="camp-desc"></div>
                        </div>
                        <div id="desc-gradient" class="absolute bottom-0 left-0 w-full h-16 bg-gradient-to-t from-white to-transparent pointer-events-none"></div>
                    </div>
                    <button id="btn-see-more" type="button" class="mt-3 text-[#1478d4] font-bold hover:text-[#0f62ae] inline-flex items-center gap-1 transition-colors">Voir plus</button>
                </section>

                <section id="video-wrapper" class="hidden" aria-labelledby="h-video">
                    <h2 id="h-video" class="cd-h2">Vidéo de présentation</h2>
                    <div class="bg-black rounded-3xl overflow-hidden aspect-video">
                        <iframe id="video-frame" class="w-full h-full" src="" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                    </div>
                </section>

                <section aria-labelledby="h-practical">
                    <h2 id="h-practical" class="cd-h2">Détails pratiques</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="rounded-2xl bg-[#f8f5ea] p-5">
                            <p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780]">Organisme</p>
                            <p id="info-organizer" class="mt-1 text-[16px] font-semibold text-[#26343b]"></p>
                        </div>
                        <div class="rounded-2xl bg-[#f8f5ea] p-5">
                            <p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780]">Durée</p>
                            <p id="info-duration" class="mt-1 text-[16px] font-semibold text-[#26343b]"></p>
                        </div>
                        <div class="rounded-2xl bg-[#f8f5ea] p-5">
                            <p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780]">Âge</p>
                            <p id="info-age" class="mt-1 text-[16px] font-semibold text-[#26343b]"></p>
                        </div>
                        <div class="rounded-2xl bg-[#f8f5ea] p-5">
                            <p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780]">Lieu</p>
                            <p id="info-place" class="mt-1 text-[16px] font-semibold text-[#26343b]"></p>
                        </div>
                    </div>
                    <div class="mt-4 rounded-3xl overflow-hidden border border-[#e5e7eb]">
                        <iframe id="map-frame" title="Carte" class="w-full block" style="height:300px;border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </section>
            </div>

            <!-- Colonne réservation -->
            <aside class="lg:col-span-1 min-w-0">
                <div class="lg:sticky lg:top-[92px] space-y-6">

                    <div id="booking-card" class="cd-card p-6">
                        <p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780]" id="price-label">À partir de</p>
                        <p class="mt-1 flex items-baseline gap-2"><span id="card-price" class="text-[40px] leading-none font-extrabold text-[#26343b]"></span><span class="text-[14px] text-[#6b7780]">par enfant</span></p>

                        <div id="sessions-box" class="hidden mt-6">
                            <p class="text-[14px] font-bold text-[#26343b] mb-3"><span id="sessions-count"></span> sessions : choisissez vos dates</p>
                            <div id="sessions-list" class="space-y-2 max-h-[300px] overflow-y-auto pr-1"></div>
                        </div>

                        <div id="progress-container" class="hidden mt-6 bg-[#f8f5ea] p-4 rounded-2xl">
                            <div class="flex justify-between text-[13px] font-bold mb-2">
                                <span class="text-[#3b4a54]">Remplissage</span>
                                <span id="progress-text" class="text-[#26343b]"></span>
                            </div>
                            <div class="w-full bg-white rounded-full h-2.5 overflow-hidden">
                                <div id="progress-bar" class="bg-[#26343b] h-2.5 rounded-full transition-all duration-1000 ease-out" style="width:0%"></div>
                            </div>
                        </div>

                        <dl class="mt-6 space-y-3 text-[14px]">
                            <div class="flex items-center justify-between border-b border-[#e5e7eb] pb-3"><dt class="text-[#6b7780]">Âge</dt><dd id="card-age" class="font-bold text-[#26343b]"></dd></div>
                            <div class="flex items-center justify-between gap-4 pb-1"><dt class="text-[#6b7780]">Dates</dt><dd id="card-dates" class="font-bold text-[#26343b] text-right"></dd></div>
                        </dl>

                        <div id="cta-container" class="mt-6"></div>
                    </div>

                    <div id="orga-card" class="cd-card p-6">
                        <p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780] mb-4">Organisé par</p>
                        <div class="flex items-center gap-4 mb-5">
                            <div class="w-14 h-14 rounded-full bg-[#1478d4] text-white flex items-center justify-center text-[22px] font-bold flex-none"><span id="orga-initial">?</span></div>
                            <p id="orga-name" class="font-bold text-[#26343b] text-[17px] leading-snug"></p>
                        </div>
                        <div id="contact-container" class="space-y-3"></div>
                        <div id="website-container"></div>
                    </div>

                    <div class="bg-[#f8f5ea] rounded-2xl p-2 text-center min-h-[250px] relative overflow-hidden">
                        <span class="absolute top-1 right-2 text-[8px] text-gray-400 uppercase bg-white/60 px-1 rounded z-10">Publicité</span>
                        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3659884670016121" crossorigin="anonymous"></script>
                        <ins class="adsbygoogle" style="display:block" data-ad-client="ca-pub-3659884670016121" data-ad-slot="7384171500" data-ad-format="auto" data-full-width-responsive="true"></ins>
                        <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', async function () {
    const isLoggedIn = <?= json_encode($is_logged_in); ?>;
    const campId = <?= json_encode($camp_id); ?>;
    const userFavorites = <?= json_encode(array_map('intval', (array)$user_favorites)); ?>;

    const loader = document.getElementById('loader');
    const content = document.getElementById('camp-content');
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const MONTHS = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
    const fmtDay = d => `${d.getDate()} ${MONTHS[d.getMonth()]}`;
    const fmtRange = (a, b) => {
        if (!a || isNaN(a)) return '';
        if (!b || isNaN(b) || b < a) return `Dès le ${fmtDay(a)} ${a.getFullYear()}`;
        return `${fmtDay(a)} – ${fmtDay(b)} ${b.getFullYear()}`;
    };
    const fmtPrice = p => { const n = Number(p); return Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ','); };

    // Bouton retour : historique si on vient du site, sinon la recherche
    $('back-link')?.addEventListener('click', e => {
        if (document.referrer && new URL(document.referrer).host === location.host && history.length > 1) { e.preventDefault(); history.back(); }
    });

    if (!campId) {
        loader.innerHTML = '<div class="text-center py-20"><h1 class="text-2xl font-bold text-[#26343b]">Séjour introuvable</h1><a href="./" class="inline-block mt-5 bg-[#2f3d45] hover:bg-[#1f2a30] text-white font-semibold px-8 py-3 rounded-lg transition-colors">Retour à l\'accueil</a></div>';
        return;
    }

    try {
        const response = await fetch(`api/get_camp_details?id=${campId}`);
        if (!response.ok) throw new Error('Séjour indisponible');
        const camp = await response.json();

        const d1 = new Date(camp.date_debut), d2 = new Date(camp.date_fin);
        const validRange = !isNaN(d1) && !isNaN(d2) && d2 >= d1;
        const dateStr = fmtRange(d1, d2);
        document.title = `${camp.nom} - ColoMap`;

        // TITRE, ÉTIQUETTES, IMAGE
        $('hero-title').textContent = camp.nom;
        $('hero-location').textContent = camp.ville || '';
        $('hero-dates').textContent = dateStr;
        $('hero-age-tag').textContent = `${camp.age_min} – ${camp.age_max} ans`;
        $('stats-views').textContent = camp.vues || 0;
        $('stats-likes').textContent = camp.total_likes || 0;
        const now = Date.now(), badges = [];
        if (camp.boost_vedette_fin && new Date(camp.boost_vedette_fin) > now) badges.push('Coup de cœur');
        if (camp.boost_urgence_fin && new Date(camp.boost_urgence_fin) > now) badges.push('Dernière minute');
        $('hero-badges').innerHTML = badges.map(b => `<span class="inline-block bg-[#f8f5ea] text-[#26343b] text-[12px] font-bold px-3 py-1.5 rounded-full">${esc(b)}</span>`).join('');
        const heroImg = $('hero-image');
        heroImg.onerror = () => { heroImg.onerror = null; heroImg.remove(); };
        if (camp.image_url) heroImg.src = String(camp.image_url).replace(/\\/g, '/');
        else heroImg.remove();

        // DESCRIPTION
        const descEl = $('camp-desc'), descBox = $('camp-desc-container'), btnMore = $('btn-see-more'), grad = $('desc-gradient');
        descEl.innerHTML = camp.description ? esc(camp.description).replace(/\n/g, '<br>') : 'Aucune description.';
        const fitDescription = () => {   // appelé une fois le contenu visible (sinon la hauteur mesurée est nulle)
            if (descEl.scrollHeight <= 180) { btnMore.classList.add('hidden'); grad.classList.add('hidden'); descBox.classList.remove('max-h-44'); }
        };
        btnMore.addEventListener('click', () => {
            const collapsed = descBox.classList.toggle('max-h-44');
            grad.classList.toggle('hidden', !collapsed);
            btnMore.textContent = collapsed ? 'Voir plus' : 'Voir moins';
        });

        // DÉTAILS PRATIQUES
        $('info-organizer').textContent = camp.orga_nom || 'Non spécifié';
        const days = validRange ? Math.round((d2 - d1) / 86400000) + 1 : null;
        $('info-duration').textContent = days ? `${days} jour${days > 1 ? 's' : ''} / ${days - 1} nuit${days - 1 > 1 ? 's' : ''}` : 'À confirmer';
        $('info-age').textContent = `${camp.age_min} à ${camp.age_max} ans`;
        $('info-place').textContent = [camp.adresse, [camp.code_postal, camp.ville].filter(Boolean).join(' ')].filter(Boolean).join(', ') || (camp.ville || '');
        if (camp.video_url) {
            let embedUrl = camp.video_url;
            if (embedUrl.includes('youtube.com/watch')) embedUrl = embedUrl.replace('watch?v=', 'embed/');
            else if (embedUrl.includes('youtu.be/')) embedUrl = embedUrl.replace('youtu.be/', 'youtube.com/embed/');
            $('video-frame').src = embedUrl;
            $('video-wrapper').classList.remove('hidden');
        }
        $('map-frame').src = `https://maps.google.com/maps?q=${encodeURIComponent(`${camp.ville || ''} ${camp.code_postal || ''}`)}&t=&z=13&ie=UTF8&iwloc=&output=embed`;

        // CARTE DE RÉSERVATION
        $('card-price').textContent = `${fmtPrice(camp.prix)} €`;
        $('card-age').textContent = `${camp.age_min} – ${camp.age_max} ans`;
        $('card-dates').textContent = dateStr;

        // SESSIONS (plusieurs sessions pour un même camp)
        const sessions = Array.isArray(camp.sessions) ? camp.sessions : [];
        if (sessions.length > 1) {
            $('sessions-count').textContent = sessions.length;
            $('sessions-box').classList.remove('hidden');
            const prices = sessions.map(s => Number(s.prix)).filter(p => p > 0);
            if (prices.length && Math.min(...prices) !== Math.max(...prices)) {
                $('card-price').textContent = `${fmtPrice(camp.prix)} €`;
            }
            $('sessions-list').innerHTML = sessions.map(s => {
                const a = new Date(s.date_debut), b = new Date(s.date_fin);
                const full = s.places_restantes === 0;
                const places = s.places_restantes === null ? '' : (full ? 'Complet' : `${s.places_restantes} place${s.places_restantes > 1 ? 's' : ''}`);
                const inner = `
                    <span class="min-w-0">
                        <span class="block text-[14px] font-bold text-[#26343b]">${esc(fmtRange(a, b))}</span>
                        <span class="block text-[12.5px] text-[#6b7780] mt-0.5">${esc(places)}</span>
                    </span>
                    <span class="flex items-center gap-2 flex-none">
                        <span class="text-[14px] font-bold text-[#26343b]">${fmtPrice(s.prix)} €</span>
                        ${s.is_current ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-label="Session affichée"><circle cx="12" cy="12" r="10" fill="#1478d4"/><path d="M7.5 12.5l3 3 6-6.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>' : ''}
                    </span>`;
                return s.is_current
                    ? `<div class="session is-current${full ? ' is-full' : ''}" aria-current="true">${inner}</div>`
                    : `<a href="camp_details?t=${encodeURIComponent(s.token)}" class="session${full ? ' is-full' : ''}">${inner}</a>`;
            }).join('');
        }

        // Remplissage (inscription en ligne uniquement)
        if (camp.inscription_en_ligne == 1) {
            const pct = camp.percent_filled || 0;
            $('progress-container').classList.remove('hidden');
            setTimeout(() => { $('progress-bar').style.width = `${pct}%`; }, 300);
            $('progress-text').textContent = `${pct}% rempli`;
        }

        // BOUTON DE RÉSERVATION
        const places = camp.places_restantes || 0;
        let cta = '';
        if (camp.lien_externe) {
            cta = `<a href="${esc(camp.lien_externe)}" target="_blank" rel="noopener" class="cd-btn">Réserver ma place
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>`;
        } else if (camp.inscription_en_ligne == 1 && places > 0) {
            cta = isLoggedIn
                ? `<a href="inscription?camp_t=${encodeURIComponent(camp.token)}" class="cd-btn">Réserver via ColoMap</a>`
                : `<a href="login.php?redirect=${encodeURIComponent(window.location.href)}" class="cd-btn">Se connecter pour réserver</a>`;
        } else if (camp.inscription_en_ligne == 1 && places === 0) {
            cta = `<button type="button" disabled class="cd-btn" style="background:#eceef1;color:#9aa3ab;cursor:not-allowed">Inscriptions closes</button>`;
        }
        $('cta-container').innerHTML = cta;

        // ORGANISATEUR
        $('orga-name').textContent = camp.orga_nom || '';
        $('orga-initial').textContent = camp.orga_nom ? camp.orga_nom.charAt(0).toUpperCase() : '?';
        const contact = $('contact-container');
        if (camp.organisateur_user_id) {
            if (isLoggedIn) {
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'cd-btn';
                b.style.fontSize = '15px'; b.style.padding = '13px 18px';
                b.textContent = "Contacter l'organisateur";
                b.onclick = () => contactOrganisateur(camp.organisateur_user_id, camp.id);
                contact.appendChild(b);
            } else {
                contact.innerHTML = `<a href="login.php?redirect=${encodeURIComponent(window.location.href)}" class="text-[14px] text-[#1478d4] hover:text-[#0f62ae] font-semibold underline block text-center">Connectez-vous pour contacter l'organisateur</a>`;
            }
        }
        if (camp.orga_website) {
            $('website-container').innerHTML = `<a href="${esc(camp.orga_website)}" target="_blank" rel="noopener" class="cd-btn-light mt-3">Voir le site de l'organisme</a>`;
        }

        // FAVORIS
        if (isLoggedIn) {
            const btnFav = $('btn-favorite');
            let isFav = userFavorites.includes(Number(camp.id));
            const render = on => { btnFav.classList.toggle('is-fav', on); btnFav.setAttribute('aria-pressed', on); };
            render(isFav);
            btnFav.addEventListener('click', () => {
                const old = isFav; isFav = !isFav; render(isFav);
                fetch('api/toggle_favorite.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ campId: camp.id }) })
                    .then(r => { if (!r.ok) throw new Error(); })
                    .catch(() => { isFav = old; render(isFav); });
            });
        }

        // PARTAGE
        $('btn-share').addEventListener('click', async () => {
            const data = { title: camp.nom, text: `Séjour ${camp.nom} à ${camp.ville}`, url: window.location.href };
            try {
                if (navigator.share) { await navigator.share(data); return; }
                await navigator.clipboard.writeText(data.url);
                $('share-label').textContent = 'Lien copié !';
                setTimeout(() => { $('share-label').textContent = 'Partager'; }, 2000);
            } catch (e) { /* partage annulé */ }
        });

        // CALENDRIER (Google Agenda / fichier .ics sur iOS)
        $('btn-calendar').addEventListener('click', () => {
            if (!validRange) return;
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent || '') && !window.MSStream;
            const title = `Séjour ColoMap: ${camp.nom}`;
            const details = `Séjour à ${camp.ville}. Plus d'infos: ${window.location.href}`;
            const locationTxt = [camp.adresse, camp.ville].filter(Boolean).join(', ');
            const ymd = d => d.toISOString().split('T')[0].replace(/-/g, '');
            const end = new Date(d2); end.setDate(end.getDate() + 1);   // fin exclusive dans les agendas
            if (isIOS) {
                const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ColoMap//Sejour//FR', 'BEGIN:VEVENT',
                    `UID:${Date.now()}@colomap.fr`, `DTSTAMP:${new Date().toISOString().replace(/[-:]/g, '').split('.')[0]}Z`,
                    `DTSTART;VALUE=DATE:${ymd(d1)}`, `DTEND;VALUE=DATE:${ymd(end)}`, `SUMMARY:${title}`, `DESCRIPTION:${details}`, `LOCATION:${locationTxt}`,
                    'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
                const link = document.createElement('a');
                link.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar;charset=utf-8' }));
                link.download = 'sejour_colomap.ics';
                document.body.appendChild(link); link.click(); link.remove();
            } else {
                window.open(`https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(title)}&dates=${ymd(d1)}/${ymd(end)}&details=${encodeURIComponent(details)}&location=${encodeURIComponent(locationTxt)}&sf=true&output=xml`, '_blank');
            }
        });

        loader.classList.add('hidden');
        content.classList.remove('hidden');
        fitDescription();

    } catch (e) {
        console.error(e);
        loader.innerHTML = `<div class="text-center py-20"><p class="text-xl font-bold text-[#26343b] mb-2">Ce séjour n'est pas disponible</p><p class="text-[#6b7780] mb-6">${esc(e.message)}</p><a href="recherche" class="inline-block bg-[#2f3d45] hover:bg-[#1f2a30] text-white font-semibold px-8 py-3 rounded-lg transition-colors">Voir les autres séjours</a></div>`;
    }
});

async function contactOrganisateur(targetUserId, campId) {
    if (!targetUserId || !campId) return alert('Erreur données.');
    document.body.style.cursor = 'wait';
    try {
        const res = await fetch('api/start_conversation.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ organisateurId: targetUserId, campId: campId }) });
        const data = await res.json();
        document.body.style.cursor = 'default';
        if (data.conversationId) window.location.href = `messagerie?conv_id=${data.conversationId}`;
        else alert(data.error || 'Erreur');
    } catch (e) {
        document.body.style.cursor = 'default';
        alert('Erreur réseau');
    }
}
</script>

<?php require_once 'partials/footer3.php'; ?>
