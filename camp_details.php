<?php
// camp_details.php : fiche d'un séjour (design de l'accueil + choix de dates/formule SANS rechargement)
// Le séjour est identifié UNIQUEMENT par son jeton (?t=). L'ancien ?id=N (numérique, devinable) redirige vers
// l'adresse à jeton quand le séjour est public, et n'est servi à personne d'autre.
require_once 'api/config.php';
require_once __DIR__ . '/api/adm_core.php';
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
        $stmt = $pdo->prepare("SELECT c.token, c.valide, c.refuse, c.supprime, c.prive, o.user_id AS owner_id FROM camps c LEFT JOIN organisateurs o ON o.id = c.organisateur_id WHERE c.id = ?");
        $stmt->execute([(int)$id_param]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isAdmin = adm_can('camps.read') || ($row && !empty($_SESSION['user']['id']) && (int)$row['owner_id'] === (int)$_SESSION['user']['id']);
        $public = $row && !empty($row['token']) && !empty($row['valide']) && empty($row['refuse']) && empty($row['supprime']) && empty($row['prive']);
        if ($row && !empty($row['token']) && ($public || $isAdmin)) {
            header('Location: camp_details?t=' . urlencode($row['token']), true, 301);
            exit;
        }
    }
} catch (Exception $e) {}

// Inscription des animateurs, intégrée au parcours de réservation (date puis « mon enfant » / « animateur »), par session :
//   mode « public » = choix visible pour tous ; mode « privé » = seulement avec ?l=<jeton du lien animateurs> d'une session du séjour.
$eqMap = [];   // jeton de session => ['lien', 'complet', 'tarifs' => [[nom, texte]]]
if ($camp_token) {
    try {
        require_once 'api/animateurs.php';
        require_once 'api/equipe.php';
        equipe_ensure($pdo);
        $cond = "valide = 1 AND refuse = 0 AND supprime = 0 AND equipe_active = 1 AND (date_fin IS NULL OR date_fin >= CURDATE())";
        try {
            $q = $pdo->prepare("SELECT * FROM camps WHERE $cond AND (token = ? OR (groupe_id IS NOT NULL AND groupe_id = (SELECT g.groupe_id FROM camps g WHERE g.token = ?)))");
            $q->execute([$camp_token, $camp_token]);
        } catch (Throwable $e) { $q = $pdo->prepare("SELECT * FROM camps WHERE $cond AND token = ?"); $q->execute([$camp_token]); }
        $sess = $q->fetchAll(PDO::FETCH_ASSOC);
        $granted = false;
        $lp = (string)($_GET['l'] ?? '');
        if ($lp !== '' && $sess && ($v = anim_lien_valide($pdo, $lp))) foreach ($sess as $r) if ((int)$r['id'] === (int)$v[1]['id']) $granted = true;
        $money = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', ''), '0'), ',') . ' €';
        foreach ($sess as $r) {
            $pub = ($r['equipe_visibilite'] ?? '') === 'public';
            if (!$pub && !$granted) continue;
            $ok = $pdo->prepare("SELECT 1 FROM animateur_liens WHERE token = ? AND actif = 1"); $ok->execute([(string)$r['equipe_lien']]);
            if (!$ok->fetchColumn()) continue;
            $tarifs = array_map(fn($t) => [$t['nom'], !empty($t['montant_libre']) ? 'dès ' . $money(equipe_plancher($t)) : ((float)$t['prix'] > 0 ? $money($t['prix']) : 'Gratuit')], equipe_tarifs($pdo, (int)$r['id']));
            $eqMap[$r['token']] = ['lien' => 'equipe-inscription.php?l=' . $r['equipe_lien'], 'tarifs' => $tarifs ?: [['Animateur', 'Gratuit']],
                'complet' => (int)$r['equipe_quota'] > 0 && equipe_places_prises($pdo, (int)$r['id']) >= (int)$r['equipe_quota']];
        }
        if ($granted) header('X-Robots-Tag: noindex, nofollow');
    } catch (Throwable $e) { error_log('camp_details equipe: ' . $e->getMessage()); }
}

$ads_enabled = true;
require_once 'partials/header3.php';
?>

<style>
    :root { --ink:#26343b; --accent:#3d6a8e; --accent-dark:#2d5170; --cream:#f8f5ea; --muted:#6b7780; --line:#e5e7eb; }
    .cd-h2 { font-size:20px; font-weight:700; color:var(--ink); margin-bottom:14px; }
    .cd-meta { display:flex; flex-wrap:wrap; align-items:center; gap:8px 20px; color:#3b4a54; font-size:15px; font-weight:600; }
    .cd-meta li { display:inline-flex; align-items:center; gap:7px; min-width:0; }
    .cd-meta li[hidden] { display:none; }
    .cd-meta svg { flex:none; color:#64748b; }
    .cd-meta .cd-stat { color:var(--muted); font-weight:500; font-size:14px; }
    .cd-meta .cd-stat svg { color:#94a3b8; }
    .cd-actions { display:flex; gap:8px; flex:none; }
    .cd-act { display:inline-flex; align-items:center; justify-content:center; gap:7px; height:42px; padding:0 15px; border-radius:9999px; border:1.5px solid var(--line); background:#fff; color:var(--ink); font-size:14px; font-weight:600; white-space:nowrap; transition:background .15s ease, border-color .15s ease, color .15s ease; }
    .cd-act:hover { background:#f1f3f5; border-color:#cfd5db; }
    .cd-act:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
    .cd-act svg { flex:none; }
    .cd-act.is-fav { color:#e5484d; border-color:#f3c5c7; background:#fff5f5; }
    .cd-act.is-fav svg { fill:#e5484d; }
    @media (max-width:640px) { .cd-meta { gap:8px 16px; font-size:14px; } .cd-actions { width:100%; } .cd-act { flex:1 1 0; padding:0 10px; min-width:0; } }
    .cd-card { background:#fff; border:1px solid var(--line); border-radius:24px; box-shadow:0 8px 30px rgba(38,52,59,.07); }
    .cd-btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:15px 20px; border-radius:14px; background:#2f3d45; color:#fff; font-weight:700; font-size:16px; transition:background .2s ease, transform .15s ease; text-align:center; }
    .cd-btn:hover { background:#1f2a30; transform:translateY(-1px); }
    .cd-btn-light { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:13px 18px; border-radius:14px; background:#fff; border:1.5px solid var(--line); color:var(--ink); font-weight:700; font-size:15px; transition:background .15s ease; }
    .cd-btn-light:hover { background:#f1f3f5; }

    /* Choix des dates / formules : boutons, aucun changement de page */
    .opt { -webkit-tap-highlight-color:transparent; box-sizing:border-box; overflow:hidden; display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; padding:11px 13px; border:1.5px solid var(--line); border-radius:14px; background:#fff; text-align:left; transition:border-color .15s ease, background .15s ease, box-shadow .15s ease; cursor:pointer; }
    .opt:focus-visible { outline:2px solid var(--accent); outline-offset:-2px; }
    .opt:hover { border-color:#a9b8c6; background:#f6f8fa; }
    .opt.is-selected { border-color:var(--accent); background:#f1f5f9; box-shadow:inset 0 0 0 1px var(--accent); }
    .opt.is-full { opacity:.55; }
    .opt-title { display:block; font-size:14px; font-weight:700; color:var(--ink); line-height:1.25; }
    .opt-sub { display:block; margin-top:2px; font-size:12.5px; color:var(--muted); }
    .dot { width:18px; height:18px; border-radius:9999px; border:2px solid #cbd2d9; flex:none; display:flex; align-items:center; justify-content:center; }
    .opt.is-selected .dot { border-color:var(--accent); background:var(--accent); }
    .opt.is-selected .dot::after { content:''; width:6px; height:6px; border-radius:9999px; background:#fff; }
    .is-switching { opacity:.55; pointer-events:none; transition:opacity .15s ease; }
    .skeleton { background:linear-gradient(90deg,#eceef1 25%,#f6f7f9 37%,#eceef1 63%); background-size:400% 100%; animation:sk 1.4s ease infinite; }
    @keyframes sk { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }
    .cd-map { height:200px; border-radius:20px; overflow:hidden; border:1px solid var(--line); background:#eef1f3; z-index:0; }
    @media (max-width:640px) { .cd-map { height:170px; } }
    .cd-map .leaflet-control-attribution { font-size:10px; line-height:1.4; padding:1px 6px; background:rgba(255,255,255,.75); border-radius:8px 0 0 0; }
    .cd-map .leaflet-control-attribution a { color:#4b5a63; text-decoration:none; }
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

        <header class="mb-6">
            <a href="recherche" id="back-link" class="inline-flex items-center gap-2 mb-4 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Retour
            </a>
            <div id="hero-badges" class="flex flex-wrap gap-2 mb-3 empty:hidden"></div>
            <h1 id="hero-title" class="text-[28px] md:text-[38px] leading-tight font-extrabold text-[#26343b]"></h1>
            <div class="mt-4 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <ul class="cd-meta" aria-label="En bref">
                    <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="9.5" r="2.4" stroke="currentColor" stroke-width="2"/></svg><span class="sr-only">Lieu : </span><span id="hero-location"></span></li>
                    <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 3v4m8-4v4M4 9h16M6 5h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="sr-only">Dates : </span><span id="hero-dates"></span></li>
                    <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span class="sr-only">Âges : </span><span id="hero-age-tag"></span></li>
                    <li class="cd-stat"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" stroke="currentColor" stroke-width="2"/></svg><span><span id="stats-views">0</span> vues</span></li>
                    <li class="cd-stat"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20.5l-1.35-1.22C5.9 15.02 3 12.4 3 9.1 3 6.5 5 4.5 7.6 4.5c1.5 0 2.9.7 3.8 1.8l.6.8.6-.8c.9-1.1 2.3-1.8 3.8-1.8 2.6 0 4.6 2 4.6 4.6 0 3.3-2.9 5.9-7.65 10.18L12 20.5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg><span><span id="stats-likes">0</span> favoris</span></li>
                </ul>
                <div class="cd-actions">
                    <button id="btn-share" type="button" class="cd-act">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m0-12L8 7m4-4l4 4M5 12v6a2 2 0 002 2h10a2 2 0 002-2v-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span id="share-label">Partager</span>
                    </button>
                    <?php if ($is_logged_in): ?>
                    <button id="btn-favorite" type="button" class="cd-act" aria-pressed="false">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20.5l-1.35-1.22C5.9 15.02 3 12.4 3 9.1 3 6.5 5 4.5 7.6 4.5c1.5 0 2.9.7 3.8 1.8l.6.8.6-.8c.9-1.1 2.3-1.8 3.8-1.8 2.6 0 4.6 2 4.6 4.6 0 3.3-2.9 5.9-7.65 10.18L12 20.5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                        <span>Favori</span>
                    </button>
                    <?php endif; ?>
                    <button id="btn-calendar" type="button" class="cd-act">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 3v4m8-4v4M4 9h16M6 5h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2zM12 12v5m-2.5-2.5h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="sm:hidden">Calendrier</span><span class="hidden sm:inline">Ajouter au calendrier</span>
                    </button>
                </div>
            </div>
        </header>

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
                    <div id="map-box" class="mt-4 hidden">
                        <div id="map" class="cd-map" role="region" aria-label="Carte du lieu du séjour"></div>
                        <p class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[13px] text-[#6b7780]"><span id="map-note"></span><a id="map-link" href="#" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#3d6a8e] hover:underline">Agrandir la carte</a></p>
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
                            <div id="orga-avatar" class="w-14 h-14 rounded-2xl bg-[#3a4852] text-white flex items-center justify-center text-[22px] font-bold flex-none overflow-hidden"><img id="orga-logo" alt="" class="w-full h-full object-contain p-1" hidden><span id="orga-initial">?</span></div>
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

<!-- Barre de réservation (mobile) : le tarif et l'accès à la réservation restent visibles tant que la carte n'est pas à l'écran -->
<div id="m-bar" class="lg:hidden fixed inset-x-0 bottom-0 z-40 bg-white/95 backdrop-blur border-t border-[#e5e7eb] px-4 pt-3 flex items-center gap-4 translate-y-full transition-transform duration-200" style="padding-bottom:calc(12px + env(safe-area-inset-bottom))" aria-hidden="true">
    <div class="min-w-0 leading-tight"><span id="m-bar-price" class="text-[20px] font-extrabold text-[#26343b]"></span> <span class="text-[12px] text-[#6b7780]">par enfant</span></div>
    <button type="button" id="m-bar-go" class="ds-btn ml-auto" tabindex="-1">Réserver</button>
</div>
<script>
(function () {
    var bar = document.getElementById('m-bar'), card = document.getElementById('booking-card'), price = document.getElementById('card-price');
    if (!bar || !card || !window.IntersectionObserver) return;
    var vis = false, shown = false;
    function render() {
        var txt = price ? price.textContent.trim() : '';
        var on = !!txt && !vis && window.scrollY > 240;
        document.getElementById('m-bar-price').textContent = txt;
        if (on !== shown) { shown = on; bar.classList.toggle('translate-y-full', !on); bar.setAttribute('aria-hidden', on ? 'false' : 'true'); document.getElementById('m-bar-go').tabIndex = on ? 0 : -1; }
    }
    new IntersectionObserver(function (e) { vis = e[0].isIntersecting; render(); }).observe(card);
    window.addEventListener('scroll', render, { passive: true });
    document.getElementById('m-bar-go').addEventListener('click', function () { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', async function () {
    const isLoggedIn = <?= json_encode($is_logged_in); ?>;
    const EQ = <?= json_encode($eqMap ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>;
    const initialToken = <?= json_encode($camp_token); ?>;
    let userFavorites = <?= json_encode(array_map('intval', (array)$user_favorites)); ?>;

    const loader = document.getElementById('loader');
    const content = document.getElementById('camp-content');
    const $ = id => document.getElementById(id);
    const PAY = <?= json_encode(['carte' => ['label' => 'Carte bancaire', 'logo' => implode('', array_map(fn($i) => '<span class="w-11 block">' . $i . '</span>', options_logos_carte()))]] + array_map(fn($p) => ['label' => $p[0], 'logo' => $p[2]], options_paiements()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
    // Tranche d'âge et public propres à chaque session (« · 14–16 ans · filles »)
    function sessionMeta(s) {
        const a = s.age_min != null && s.age_max != null ? ` · ${s.age_min}–${s.age_max} ans` : '';
        return a + (s.reserve_sexe ? (s.reserve_sexe === 'Femme' ? ' · filles' : ' · garçons') : '');
    }
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

    let leafletP = null, map = null, pin = null;
    function loadLeaflet() {
        if (!leafletP) leafletP = new Promise((ok, ko) => {
            const css = Object.assign(document.createElement('link'), { rel: 'stylesheet', href: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', integrity: 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=', crossOrigin: '' });
            const js = Object.assign(document.createElement('script'), { src: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', integrity: 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=', crossOrigin: '', onload: () => ok(window.L), onerror: ko });
            document.head.append(css, js);
        });
        return leafletP;
    }
    function showMap(la, lo, z) {
        loadLeaflet().then(L => {
            if (!map) {
                map = L.map('map', { scrollWheelZoom: false, zoomControl: true });
                map.attributionControl.setPrefix(false);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap</a>' }).addTo(map);
                pin = L.circleMarker([la, lo], { radius: 9, color: '#fff', weight: 3, fillColor: '#3d6a8e', fillOpacity: 1 }).addTo(map);
            }
            map.invalidateSize();
            map.setView([la, lo], z);
            pin.setLatLng([la, lo]);
        }).catch(() => $('map-box').classList.add('hidden'));
    }

    // ------------------------------------------------------------
    // Rendu de la session affichée (appelé au chargement ET à chaque changement de session)
    // ------------------------------------------------------------
    function render(camp) {
        current = camp;
        const r = normRange(camp.date_debut, camp.date_fin);
        const dateStr = fmtRange(r);
        document.title = `${camp.nom} - moncamp.fr`;

        $('hero-title').textContent = camp.nom;
        $('hero-location').textContent = camp.ville || '';
        $('hero-location').parentNode.hidden = !camp.ville;
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
            const pay = c => c === 'autre' && camp.paiement_autre ? { label: camp.paiement_autre, logo: camp.paiement_autre_logo ? `<img src="${esc(camp.paiement_autre_logo)}" alt="" style="width:100%;height:30px;object-fit:contain">` : PAY.autre.logo } : PAY[c];
            const pays = String(camp.moyens_paiement || '').split(',').filter(c => PAY[c]);
            if (pays.length) box.push(`<div class="rounded-2xl bg-[#f8f5ea] p-5"><p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780] mb-3">Moyens de paiement acceptés</p><ul class="flex flex-wrap gap-2.5">${pays.map(c => `<li class="inline-flex flex-wrap items-center gap-2.5 bg-white rounded-xl border border-[#e5e7eb] p-1.5 pr-3"><span class="flex gap-1">${c === 'carte' ? pay(c).logo : `<span class="w-12 block">${pay(c).logo}</span>`}</span><span class="text-[13px] font-semibold text-[#26343b]">${esc(pay(c).label)}</span></li>`).join('')}</ul>${camp.instructions_paiement ? `<p class="mt-3 text-[14px] text-[#3b4a54] whitespace-pre-line">${esc(camp.instructions_paiement)}</p>` : ''}</div>`);
            $('info-extra').innerHTML = box.join(''); $('info-extra').classList.toggle('hidden', !box.length);
        })();
        if (camp.video_url) {
            let embedUrl = camp.video_url;
            if (embedUrl.includes('youtube.com/watch')) embedUrl = embedUrl.replace('watch?v=', 'embed/');
            else if (embedUrl.includes('youtu.be/')) embedUrl = embedUrl.replace('youtu.be/', 'youtube.com/embed/');
            if ($('video-frame').getAttribute('src') !== embedUrl) $('video-frame').src = embedUrl;
            $('video-wrapper').classList.remove('hidden');
        } else { $('video-wrapper').classList.add('hidden'); }
        // Carte OpenStreetMap centrée sur le lieu exact de la session choisie (repli : la ville ; sinon pas de carte)
        const mapBox = $('map-box');
        if (camp.lat != null && camp.lng != null) {
            const la = Number(camp.lat), lo = Number(camp.lng), z = Number(camp.geo_precis) ? 15 : 12;
            $('map-link').href = `https://www.openstreetmap.org/?mlat=${la}&mlon=${lo}#map=${z + 1}/${la}/${lo}`;
            $('map-note').textContent = Number(camp.geo_precis) ? '' : 'Position approximative (centre de la ville).';
            mapBox.classList.remove('hidden');
            showMap(la, lo, z);
        } else { mapBox.classList.add('hidden'); }

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
            cta = `<a href="go/${encodeURIComponent(camp.token)}" target="_blank" rel="nofollow noopener" class="cd-btn">Réserver ma place
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>`;
        } else if (camp.inscription_en_ligne == 1 && places > 0) {
            cta = isLoggedIn
                ? `<a href="inscription?camp_t=${encodeURIComponent(camp.token)}" class="cd-btn">Réserver via moncamp.fr</a>`
                : `<a href="login.php?redirect=${encodeURIComponent(location.origin + '/camp_details?t=' + camp.token)}" class="cd-btn">Se connecter pour réserver</a>`;
        } else if (camp.inscription_en_ligne == 1 && places === 0) {
            cta = `<button type="button" disabled class="cd-btn" style="background:#eceef1;color:#9aa3ab;cursor:not-allowed">Inscriptions closes</button>`;
        }
        // Parcours unique : « mon enfant » (réservation actuelle) ou « animateur » (tarif et formulaire de CETTE session)
        const eq = EQ[camp.token];
        if (eq) {
            if (cta) cta = cta.replace('Réserver via moncamp.fr', "J’inscris mon enfant").replace('Réserver ma place', "J’inscris mon enfant");
            cta = `<div class="space-y-4">${cta ? `<div><p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780] mb-2">Pour mon enfant</p>${cta}</div>` : ''}
                <div class="${cta ? 'border-t border-[#e5e7eb] pt-4' : ''}"><p class="text-[12px] font-bold uppercase tracking-wider text-[#6b7780] mb-2">Équipe d’animation</p>
                ${eq.tarifs.map(t => `<p class="flex items-baseline justify-between gap-3 mb-2"><span class="font-bold text-[#26343b]">${esc(t[0])}</span><span class="font-extrabold text-[#26343b]">${esc(t[1])}</span></p>`).join('')}
                ${eq.complet ? '<p class="text-[14px] text-[#6b7780]">L’équipe d’animation est complète.</p>'
                    : `<a href="${esc(eq.lien)}" rel="nofollow" class="cd-btn">Je m’inscris en tant qu’animateur</a>`}</div></div>`;
        }
        $('cta-container').innerHTML = cta;

        // Organisateur
        $('orga-name').textContent = camp.orga_nom || '';
        if (camp.orga_slug) {   // le nom de l'organisme mène à sa page publique
            const a = document.createElement('a'); a.href = 'organisme/' + encodeURIComponent(camp.orga_slug); a.className = 'underline-offset-4 hover:underline';
            a.textContent = camp.orga_nom || ''; $('orga-name').textContent = ''; $('orga-name').appendChild(a);
        }
        $('orga-initial').textContent = camp.orga_nom ? camp.orga_nom.charAt(0).toUpperCase() : '?';
        const logo = $('orga-logo'), av = $('orga-avatar'), avatar = on => {
            logo.hidden = !on; $('orga-initial').hidden = on;
            av.classList.toggle('bg-[#3a4852]', !on); av.classList.toggle('bg-white', on); av.classList.toggle('border', on); av.classList.toggle('border-[#e5e7eb]', on);
        };
        logo.onerror = () => avatar(false);
        if (camp.orga_logo) { logo.alt = 'Logo de ' + (camp.orga_nom || "l'organisme"); if (logo.getAttribute('src') !== camp.orga_logo) logo.src = camp.orga_logo; avatar(true); }
        else { logo.removeAttribute('src'); avatar(false); }
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
            const sub = (g.items.length > 1 ? `${g.items.length} formules` : nightsLabel(g.items[0].r.nights)) + sessionMeta(g.items[0]);
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
                            <span class="opt-sub">${esc(fmtRange(s.r))}${esc(sessionMeta(s))}${places ? ' · ' + esc(places) : ''}</span></span></span>
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
        const title = `Séjour moncamp.fr: ${camp.nom}`;
        const details = `Séjour à ${camp.ville}. Plus d'infos: ${location.href}`;
        const where = [camp.adresse, camp.ville].filter(Boolean).join(', ');
        const compact = d => ymd(d).replace(/-/g, '');
        const end = new Date(r.b); end.setDate(end.getDate() + 1);   // fin exclusive dans les agendas
        if (isIOS) {
            const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//moncamp.fr//Sejour//FR', 'BEGIN:VEVENT',
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
    if (!targetUserId || !campId) return ColoUI.error('Les informations de la page sont incomplètes. Rechargez la page puis réessayez.');
    document.body.style.cursor = 'wait';
    try {
        const res = await fetch('api/start_conversation.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ organisateurId: targetUserId, campId: campId }) });
        const data = await res.json();
        document.body.style.cursor = 'default';
        if (data.conversationId) window.location.href = `messagerie?conv_id=${data.conversationId}`;
        else ColoUI.error(data.error);
    } catch (e) {
        document.body.style.cursor = 'default';
        ColoUI.error('Failed to fetch');
    }
}
</script>

<?php require_once 'partials/footer3.php'; ?>
