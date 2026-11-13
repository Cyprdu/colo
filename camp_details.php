<?php
// camp_details.php : fiche d'un séjour (design de l'accueil + choix de dates/formule SANS rechargement)
// Le séjour est identifié UNIQUEMENT par son jeton (?t=). L'ancien ?id=N (numérique, devinable) redirige vers
// l'adresse à jeton quand le séjour est public, et n'est servi à personne d'autre.
require_once 'api/config.php';
require_once 'api/options.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$is_logged_in = isset($_SESSION['user']);
$user_favorites = $_SESSION['user']['favorites'] ?? [];
$camp_token = null;

try {
    $t = $_GET['t'] ?? null;
    $id_param = $_GET['id'] ?? null;
    if ($t !== null && preg_match('/^[A-Za-z0-9]{6,64}$/', (string)$t)) {
        $stmt = $pdo->prepare("SELECT token FROM camps WHERE token = ?");
        $stmt->execute([$t]);
        $camp_token = $stmt->fetchColumn() ?: null;
    } elseif ($id_param !== null && ctype_digit((string)$id_param)) {
        $stmt = $pdo->prepare("SELECT token, valide, refuse, supprime, prive FROM camps WHERE id = ?");
        $stmt->execute([(int)$id_param]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isAdmin = !empty($_SESSION['user']['is_admin']);
        $public = $row && !empty($row['token']) && !empty($row['valide']) && empty($row['refuse']) && empty($row['supprime']) && empty($row['prive']);
        if ($row && !empty($row['token']) && ($public || $isAdmin)) {
            header('Location: camp_details?t=' . urlencode($row['token']), true, 301);
            exit;
        }
    }
} catch (Exception $e) {}

$ads_enabled = true;
require_once 'partials/header3.php';
?>

<style>
    :root { --ink:#26343b; --accent:#3d6a8e; --accent-dark:#2d5170; --cream:#f8f5ea; --muted:#6b7780; --line:#e5e7eb; }
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

    /* Choix des dates / formules : boutons, aucun changement de page */
    .opt { display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; padding:11px 13px; border:1.5px solid var(--line); border-radius:14px; background:#fff; text-align:left; transition:border-color .15s ease, background .15s ease, box-shadow .15s ease; cursor:pointer; }
    .opt:hover { border-color:#a9b8c6; background:#f6f8fa; }
    .opt.is-selected { border-color:var(--accent); background:#f1f5f9; box-shadow:0 0 0 3px rgba(61,106,142,.15); }
    .opt.is-full { opacity:.55; }
    .opt-title { display:block; font-size:14px; font-weight:700; color:var(--ink); line-height:1.25; }
    .opt-sub { display:block; margin-top:2px; font-size:12.5px; color:var(--muted); }
    .dot { width:18px; height:18px; border-radius:9999px; border:2px solid #cbd2d9; flex:none; display:flex; align-items:center; justify-content:center; }
    .opt.is-selected .dot { border-color:var(--accent); background:var(--accent); }
    .opt.is-selected .dot::after { content:''; width:6px; height:6px; border-radius:9999px; background:#fff; }
    .is-switching { opacity:.55; pointer-events:none; transition:opacity .15s ease; }
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
                    <button id="btn-see-more" type="button" class="mt-3 text-[#3d6a8e] font-bold hover:text-[#2d5170] inline-flex items-center gap-1 transition-colors">Voir plus</button>
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
                    <div id="route" class="mt-4 rounded-2xl bg-[#f8f5ea] p-5 hidden"><p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780] mb-4">Parcours itinérant</p><ol id="route-list"></ol></div>
                    <div id="info-extra" class="mt-4 space-y-4 hidden"></div>
                    <div class="mt-4 rounded-3xl overflow-hidden border border-[#e5e7eb]">
                        <iframe id="map-frame" title="Carte" class="w-full block" style="height:300px;border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </section>
            </div>

            <!-- Colonne réservation -->
            <aside class="lg:col-span-1 min-w-0">
                <div class="lg:sticky lg:top-[92px] space-y-6">

                    <div id="booking-card" class="cd-card p-6">
                        <p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780]">Tarif</p>
                        <p class="mt-1 flex items-baseline gap-2"><span id="card-price" class="text-[40px] leading-none font-extrabold text-[#26343b]"></span><span class="text-[14px] text-[#6b7780]">par enfant</span></p>

                        <!-- Dates puis formules : rien ne recharge la page -->
                        <div id="sessions-box" class="hidden mt-6">
                            <p id="dates-title" class="text-[14px] font-bold text-[#26343b] mb-3">Choisissez vos dates</p>
                            <div id="sessions-dates" class="grid grid-cols-1 gap-2 max-h-[280px] overflow-y-auto pr-1" role="radiogroup" aria-labelledby="dates-title"></div>
                            <div id="formulas-box" class="hidden mt-5">
                                <p id="formulas-title" class="text-[14px] font-bold text-[#26343b] mb-3">Choisissez votre formule</p>
                                <div id="formulas-list" class="grid grid-cols-1 gap-2" role="radiogroup" aria-labelledby="formulas-title"></div>
                            </div>
                        </div>

                        <div id="progress-container" class="hidden mt-6 bg-[#f8f5ea] p-4 rounded-2xl">
                            <div class="flex justify-between text-[13px] font-bold mb-2">
                                <span class="text-[#3b4a54]">Remplissage</span>
                                <span id="progress-text" class="text-[#26343b]"></span>
                            </div>
                            <div class="w-full bg-white rounded-full h-2.5 overflow-hidden">
                                <div id="progress-bar" class="bg-[#26343b] h-2.5 rounded-full transition-all duration-700 ease-out" style="width:0%"></div>
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
                            <div class="w-14 h-14 rounded-full bg-[#3a4852] text-white flex items-center justify-center text-[22px] font-bold flex-none"><span id="orga-initial">?</span></div>
                            <p id="orga-name" class="font-bold text-[#26343b] text-[17px] leading-snug"></p>
                        </div>
                        <div id="contact-container" class="space-y-3"></div>
                        <div id="website-container"></div>
                    </div>

                    <?php ad_slot('detail-1', ADS_SLOTS['detail'], ['minh' => 280]); ?>
                </div>
            </aside>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', async function () {
    const isLoggedIn = <?= json_encode($is_logged_in); ?>;
    const initialToken = <?= json_encode($camp_token); ?>;
    let userFavorites = <?= json_encode(array_map('intval', (array)$user_favorites)); ?>;

    const loader = document.getElementById('loader');
    const content = document.getElementById('camp-content');
    const $ = id => document.getElementById(id);
    const PAY = <?= json_encode(array_map(fn($p) => ['label' => $p[0], 'logo' => $p[2]], options_paiements()), JSON_UNESCAPED_UNICODE) ?>;
    const HEB = <?= json_encode(HEBERGEMENTS, JSON_UNESCAPED_UNICODE) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const MONTHS = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
    const fmtDay = d => `${d.getDate()} ${MONTHS[d.getMonth()]}`;
    const fmtPrice = p => { const n = Number(p); return Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ','); };

    // Dates corrigées : certains séjours importés ont un début situé après la fin (année du début erronée)
    function normRange(debut, fin) {
        let a = new Date(debut), b = new Date(fin);
        if (isNaN(a)) return null;
        if (isNaN(b)) b = a;
        if (a > b) { const c = new Date(a); c.setFullYear(b.getFullYear()); if (c > b) c.setFullYear(b.getFullYear() - 1); a = c; }
        return { a, b, nights: Math.max(0, Math.round((b - a) / 86400000)) };
    }
    const fmtRange = r => !r ? '' : (r.a.getFullYear() === r.b.getFullYear() ? `${fmtDay(r.a)} – ${fmtDay(r.b)} ${r.b.getFullYear()}` : `${fmtDay(r.a)} ${r.a.getFullYear()} – ${fmtDay(r.b)} ${r.b.getFullYear()}`);
    const ymd = d => d.toISOString().split('T')[0];
    const nightsLabel = n => n > 0 ? `${n} nuit${n > 1 ? 's' : ''}` : '1 journée';

    // Bouton retour : historique si on vient du site, sinon la recherche
    $('back-link')?.addEventListener('click', e => {
        if (document.referrer && new URL(document.referrer).host === location.host && history.length > 1) { e.preventDefault(); history.back(); }
    });

    if (!initialToken) {
        loader.innerHTML = '<div class="text-center py-20"><h1 class="text-2xl font-bold text-[#26343b]">Séjour introuvable</h1><a href="./" class="inline-block mt-5 bg-[#2f3d45] hover:bg-[#1f2a30] text-white font-semibold px-8 py-3 rounded-lg transition-colors">Retour à l\'accueil</a></div>';
        return;
    }

    // ------------------------------------------------------------
    // Données : une requête par session, mises en cache (changer de session est instantané)
    // ------------------------------------------------------------
    const cache = new Map();          // jeton -> séjour
    const inflight = new Map();       // jeton -> promesse
    function fetchCamp(tok) {
        if (cache.has(tok)) return Promise.resolve(cache.get(tok));
        if (inflight.has(tok)) return inflight.get(tok);
        const p = fetch(`api/get_camp_details?t=${encodeURIComponent(tok)}`)
            .then(r => { if (!r.ok) throw new Error('Séjour indisponible'); return r.json(); })
            .then(c => { cache.set(tok, c); inflight.delete(tok); return c; })
            .catch(e => { inflight.delete(tok); throw e; });
        inflight.set(tok, p);
        return p;
    }

    let current = null;               // session affichée
    let descReady = false;

    // ------------------------------------------------------------
    // Rendu de la session affichée (appelé au chargement ET à chaque changement de session)
    // ------------------------------------------------------------
    function render(camp) {
        current = camp;
        const r = normRange(camp.date_debut, camp.date_fin);
        const dateStr = fmtRange(r);
        document.title = `${camp.nom} - ColoMap`;

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

        const heroImg = $('hero-image'), src = camp.image_url ? String(camp.image_url).replace(/\\/g, '/') : '';
        heroImg.style.display = src ? '' : 'none';
        if (src && heroImg.getAttribute('src') !== src) heroImg.src = src;
        heroImg.onerror = () => { heroImg.style.display = 'none'; };

        // Description
        const descEl = $('camp-desc'), descBox = $('camp-desc-container'), btnMore = $('btn-see-more'), grad = $('desc-gradient');
        descEl.innerHTML = camp.description ? esc(camp.description).replace(/\n/g, '<br>') : 'Aucune description.';
        descBox.classList.add('max-h-44'); grad.classList.remove('hidden'); btnMore.classList.remove('hidden'); btnMore.textContent = 'Voir plus';
        requestAnimationFrame(() => {
            if (descEl.scrollHeight <= 180) { btnMore.classList.add('hidden'); grad.classList.add('hidden'); descBox.classList.remove('max-h-44'); }
        });
        if (!descReady) {
            descReady = true;
            btnMore.addEventListener('click', () => {
                const collapsed = descBox.classList.toggle('max-h-44');
                grad.classList.toggle('hidden', !collapsed);
                btnMore.textContent = collapsed ? 'Voir plus' : 'Voir moins';
            });
        }

        // Détails pratiques
        $('info-organizer').textContent = camp.orga_nom || 'Non spécifié';
        $('info-duration').textContent = r ? `${r.nights + 1} jour${r.nights + 1 > 1 ? 's' : ''} / ${nightsLabel(r.nights)}` : 'À confirmer';
        $('info-age').textContent = `${camp.age_min} à ${camp.age_max} ans`;
        $('info-place').textContent = [camp.adresse, [camp.code_postal, camp.ville].filter(Boolean).join(' ')].filter(Boolean).join(', ') || (camp.ville || '');
        // Parcours d'un séjour itinérant
        if (Array.isArray(camp.etapes) && camp.etapes.length) {
            $('route-list').innerHTML = camp.etapes.map(e => `<li class="relative pl-10 pb-5 last:pb-0"><span class="absolute left-0 top-0 w-7 h-7 rounded-full bg-[#2f3d45] text-white text-[12px] font-bold flex items-center justify-center">${esc(e.jour)}</span><span class="absolute left-[13px] top-7 bottom-0 w-px bg-[#d3dbe0]"></span><p class="font-bold text-[#26343b]">Jour ${esc(e.jour)} · ${esc(e.lieu)}</p>${e.description ? `<p class="text-[14px] text-[#3b4a54] mt-0.5 whitespace-pre-line">${esc(e.description)}</p>` : ''}</li>`).join('');
            $('route').classList.remove('hidden');
        } else { $('route').classList.add('hidden'); }
        // Options du directeur : public, hébergement, inclus, moyens de paiement acceptés
        (function () {
            const box = [], cell = (t, v) => v ? `<div class="rounded-2xl bg-[#f8f5ea] p-5"><p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780]">${esc(t)}</p><p class="mt-1 text-[15px] font-semibold text-[#26343b] whitespace-pre-line">${esc(v)}</p></div>` : '';
            const grid = [
                cell('Public', camp.reserve_sexe ? 'Séjour réservé aux ' + (camp.reserve_sexe === 'Femme' ? 'filles' : 'garçons') : ''),
                cell('Hébergement', camp.hebergement ? HEB[camp.hebergement] : ''), cell('Encadrement', camp.encadrement), cell('Transport', camp.transport),
                cell('Ce qui est inclus', camp.inclus), cell('À prévoir', camp.a_prevoir)].join('');
            if (grid) box.push(`<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">${grid}</div>`);
            const pays = String(camp.moyens_paiement || '').split(',').filter(c => PAY[c]);
            if (pays.length) box.push(`<div class="rounded-2xl bg-[#f8f5ea] p-5"><p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780] mb-3">Moyens de paiement acceptés</p><div class="flex flex-wrap gap-3">${pays.map(c => `<span class="inline-flex items-center gap-2 bg-white rounded-xl border border-[#e5e7eb] pr-3"><span class="w-12 block">${PAY[c].logo}</span><span class="text-[13px] font-semibold text-[#26343b]">${esc(PAY[c].label)}</span></span>`).join('')}</div>${camp.instructions_paiement ? `<p class="mt-3 text-[14px] text-[#3b4a54] whitespace-pre-line">${esc(camp.instructions_paiement)}</p>` : ''}</div>`);
            $('info-extra').innerHTML = box.join(''); $('info-extra').classList.toggle('hidden', !box.length);
        })();
        if (camp.video_url) {
            let embedUrl = camp.video_url;
            if (embedUrl.includes('youtube.com/watch')) embedUrl = embedUrl.replace('watch?v=', 'embed/');
            else if (embedUrl.includes('youtu.be/')) embedUrl = embedUrl.replace('youtu.be/', 'youtube.com/embed/');
            if ($('video-frame').getAttribute('src') !== embedUrl) $('video-frame').src = embedUrl;
            $('video-wrapper').classList.remove('hidden');
        } else { $('video-wrapper').classList.add('hidden'); }
        const mapSrc = `https://maps.google.com/maps?q=${encodeURIComponent(`${camp.ville || ''} ${camp.code_postal || ''}`)}&t=&z=13&ie=UTF8&iwloc=&output=embed`;
        if ($('map-frame').getAttribute('src') !== mapSrc) $('map-frame').src = mapSrc;

        // Carte de réservation
        $('card-price').textContent = `${fmtPrice(camp.prix)} €`;
        $('card-age').textContent = `${camp.age_min} – ${camp.age_max} ans`;
        $('card-dates').textContent = dateStr;
        renderSessionPicker(camp);

        // Remplissage (inscription en ligne uniquement)
        const prog = $('progress-container');
        if (camp.inscription_en_ligne == 1) {
            const pct = camp.percent_filled || 0;
            prog.classList.remove('hidden');
            $('progress-bar').style.width = `${pct}%`;
            $('progress-text').textContent = `${pct}% rempli`;
        } else { prog.classList.add('hidden'); }

        // Bouton de réservation
        const places = camp.places_restantes || 0;
        let cta = '';
        if (camp.lien_externe) {
            cta = `<a href="${esc(camp.lien_externe)}" target="_blank" rel="noopener" class="cd-btn">Réserver ma place
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>`;
        } else if (camp.inscription_en_ligne == 1 && places > 0) {
            cta = isLoggedIn
                ? `<a href="inscription?camp_t=${encodeURIComponent(camp.token)}" class="cd-btn">Réserver via ColoMap</a>`
                : `<a href="login.php?redirect=${encodeURIComponent(location.origin + '/camp_details?t=' + camp.token)}" class="cd-btn">Se connecter pour réserver</a>`;
        } else if (camp.inscription_en_ligne == 1 && places === 0) {
            cta = `<button type="button" disabled class="cd-btn" style="background:#eceef1;color:#9aa3ab;cursor:not-allowed">Inscriptions closes</button>`;
        }
        $('cta-container').innerHTML = cta;

        // Organisateur
        $('orga-name').textContent = camp.orga_nom || '';
        $('orga-initial').textContent = camp.orga_nom ? camp.orga_nom.charAt(0).toUpperCase() : '?';
        const contact = $('contact-container');
        contact.innerHTML = '';
        if (camp.organisateur_user_id) {
            if (isLoggedIn) {
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'cd-btn'; b.style.fontSize = '15px'; b.style.padding = '13px 18px';
                b.textContent = "Contacter l'organisateur";
                b.onclick = () => contactOrganisateur(camp.organisateur_user_id, camp.id);
                contact.appendChild(b);
            } else {
                contact.innerHTML = `<a href="login.php?redirect=${encodeURIComponent(location.origin + '/camp_details?t=' + camp.token)}" class="text-[14px] text-[#3d6a8e] hover:text-[#2d5170] font-semibold underline block text-center">Connectez-vous pour contacter l'organisateur</a>`;
            }
        }
        $('website-container').innerHTML = camp.orga_website
            ? `<a href="${esc(camp.orga_website)}" target="_blank" rel="noopener" class="cd-btn-light mt-3">Voir le site de l'organisme</a>` : '';

        // Favori (par session)
        const btnFav = $('btn-favorite');
        if (btnFav) { const on = userFavorites.includes(Number(camp.id)); btnFav.classList.toggle('is-fav', on); btnFav.setAttribute('aria-pressed', on); }
    }

    // ------------------------------------------------------------
    // Sélecteur : DATES (une ligne par date de départ) puis FORMULES (durée / prix de cette date)
    // ------------------------------------------------------------
    function renderSessionPicker(camp) {
        const raw = Array.isArray(camp.sessions) ? camp.sessions : [];
        const box = $('sessions-box');
        if (raw.length < 2) { box.classList.add('hidden'); return; }
        const sessions = raw.map(s => ({ ...s, r: normRange(s.date_debut, s.date_fin) })).filter(s => s.r)
            .sort((x, y) => x.r.a - y.r.a || x.prix - y.prix);
        // regroupement par date de départ
        const groups = [];
        sessions.forEach(s => {
            const key = ymd(s.r.a);
            let g = groups.find(x => x.key === key);
            if (!g) { g = { key, start: s.r.a, items: [] }; groups.push(g); }
            g.items.push(s);
        });
        const curGroup = groups.find(g => g.items.some(s => s.id === camp.id)) || groups[0];
        const curItem = curGroup.items.find(s => s.id === camp.id) || curGroup.items[0];

        $('dates-title').textContent = groups.length > 1 ? 'Choisissez vos dates' : 'Dates du séjour';
        $('sessions-dates').innerHTML = groups.map(g => {
            const sel = g === curGroup;
            const allFull = g.items.every(s => s.places_restantes === 0);
            const sub = g.items.length > 1 ? `${g.items.length} formules` : nightsLabel(g.items[0].r.nights);
            const min = Math.min(...g.items.map(s => Number(s.prix)));
            return `<button type="button" role="radio" aria-checked="${sel}" data-token="${g.items[0].token}" data-key="${g.key}" data-nights="${curItem.r.nights}"
                        class="opt${sel ? ' is-selected' : ''}${allFull ? ' is-full' : ''}">
                        <span class="flex items-center gap-3 min-w-0"><span class="dot"></span>
                            <span class="min-w-0"><span class="opt-title">${esc(fmtDay(g.start))} ${g.start.getFullYear()}</span>
                            <span class="opt-sub">${esc(sub)}${allFull ? ' · Complet' : ''}</span></span></span>
                        <span class="text-[14px] font-bold text-[#26343b] flex-none">${g.items.length > 1 ? 'dès ' : ''}${fmtPrice(min)} €</span>
                    </button>`;
        }).join('');

        // formules de la date choisie
        const fBox = $('formulas-box');
        if (curGroup.items.length > 1) {
            fBox.classList.remove('hidden');
            $('formulas-list').innerHTML = curGroup.items.map(s => {
                const sel = s.id === camp.id, full = s.places_restantes === 0;
                const places = s.places_restantes === null ? '' : (full ? 'Complet' : `${s.places_restantes} place${s.places_restantes > 1 ? 's' : ''}`);
                return `<button type="button" role="radio" aria-checked="${sel}" data-token="${s.token}" class="opt${sel ? ' is-selected' : ''}${full ? ' is-full' : ''}">
                        <span class="flex items-center gap-3 min-w-0"><span class="dot"></span>
                            <span class="min-w-0"><span class="opt-title">${esc(nightsLabel(s.r.nights))}</span>
                            <span class="opt-sub">${esc(fmtRange(s.r))}${places ? ' · ' + esc(places) : ''}</span></span></span>
                        <span class="text-[14px] font-bold text-[#26343b] flex-none">${fmtPrice(s.prix)} €</span>
                    </button>`;
            }).join('');
        } else { fBox.classList.add('hidden'); $('formulas-list').innerHTML = ''; }

        box.classList.remove('hidden');
        box._groups = groups;   // utilisé pour choisir la formule quand on clique sur une date
    }

    // Clic sur une date ou une formule : on change de session SANS recharger la page
    $('sessions-box').addEventListener('click', e => {
        const btn = e.target.closest('button[data-token]');
        if (!btn) return;
        let tok = btn.dataset.token;
        if (btn.dataset.key) {   // clic sur une date : formule de même durée sinon la moins chère
            const g = ($('sessions-box')._groups || []).find(x => x.key === btn.dataset.key);
            if (g) {
                const nights = Number(btn.dataset.nights);
                const same = g.items.find(s => s.r.nights === nights);
                tok = (same || g.items[0]).token;
            }
        }
        if (tok !== current.token) switchTo(tok);
    });
    // Préchargement au survol : le changement est alors immédiat
    $('sessions-box').addEventListener('pointerover', e => {
        const btn = e.target.closest('button[data-token]');
        if (btn) fetchCamp(btn.dataset.token).catch(() => {});
    });

    async function switchTo(tok, push = true) {
        const card = $('booking-card');
        if (!cache.has(tok)) card.classList.add('is-switching');
        try {
            const camp = await fetchCamp(tok);
            render(camp);
            if (push) history.pushState({ tok }, '', `camp_details?t=${encodeURIComponent(camp.token)}`);
        } catch (e) {
            $('sessions-box').insertAdjacentHTML('beforeend', '<p class="text-sm text-red-600 mt-3">Cette session est momentanément indisponible.</p>');
        } finally { card.classList.remove('is-switching'); }
    }
    // Boutons précédent / suivant du navigateur
    window.addEventListener('popstate', e => {
        const tok = e.state && e.state.tok ? e.state.tok : initialToken;
        if (current && tok !== current.token) switchTo(tok, false);
    });

    // ------------------------------------------------------------
    // Actions (liées une seule fois, elles utilisent toujours la session affichée)
    // ------------------------------------------------------------
    $('btn-favorite')?.addEventListener('click', () => {
        const btn = $('btn-favorite'), camp = current;
        const was = userFavorites.includes(Number(camp.id));
        const apply = on => {
            userFavorites = on ? [...new Set([...userFavorites, Number(camp.id)])] : userFavorites.filter(i => i !== Number(camp.id));
            btn.classList.toggle('is-fav', on); btn.setAttribute('aria-pressed', on);
        };
        apply(!was);
        fetch('api/toggle_favorite.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ campId: camp.id }) })
            .then(r => { if (!r.ok) throw new Error(); })
            .catch(() => apply(was));
    });

    $('btn-share').addEventListener('click', async () => {
        const data = { title: current.nom, text: `Séjour ${current.nom} à ${current.ville}`, url: location.href };
        try {
            if (navigator.share) { await navigator.share(data); return; }
            await navigator.clipboard.writeText(data.url);
            $('share-label').textContent = 'Lien copié !';
            setTimeout(() => { $('share-label').textContent = 'Partager'; }, 2000);
        } catch (e) { /* partage annulé */ }
    });

    $('btn-calendar').addEventListener('click', () => {
        const camp = current, r = normRange(camp.date_debut, camp.date_fin);
        if (!r) return;
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent || '') && !window.MSStream;
        const title = `Séjour ColoMap: ${camp.nom}`;
        const details = `Séjour à ${camp.ville}. Plus d'infos: ${location.href}`;
        const where = [camp.adresse, camp.ville].filter(Boolean).join(', ');
        const compact = d => ymd(d).replace(/-/g, '');
        const end = new Date(r.b); end.setDate(end.getDate() + 1);   // fin exclusive dans les agendas
        if (isIOS) {
            const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ColoMap//Sejour//FR', 'BEGIN:VEVENT',
                `UID:${Date.now()}@colomap.fr`, `DTSTAMP:${new Date().toISOString().replace(/[-:]/g, '').split('.')[0]}Z`,
                `DTSTART;VALUE=DATE:${compact(r.a)}`, `DTEND;VALUE=DATE:${compact(end)}`, `SUMMARY:${title}`, `DESCRIPTION:${details}`, `LOCATION:${where}`,
                'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar;charset=utf-8' }));
            link.download = 'sejour_colomap.ics';
            document.body.appendChild(link); link.click(); link.remove();
        } else {
            window.open(`https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(title)}&dates=${compact(r.a)}/${compact(end)}&details=${encodeURIComponent(details)}&location=${encodeURIComponent(where)}&sf=true&output=xml`, '_blank');
        }
    });

    // ------------------------------------------------------------
    // Démarrage
    // ------------------------------------------------------------
    try {
        const camp = await fetchCamp(initialToken);
        history.replaceState({ tok: initialToken }, '');
        render(camp);
        loader.classList.add('hidden');
        content.classList.remove('hidden');
        // précharge les autres sessions en arrière-plan (sans bloquer l'affichage)
        (camp.sessions || []).slice(0, 12).filter(s => s.token !== initialToken).forEach(s => setTimeout(() => fetchCamp(s.token).catch(() => {}), 800));
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
