<?php
// Fichier: /partials/header.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../api/i18n.php';
ob_start('mc_i18n_out');   // les pages peuvent encore rediriger (connexion requise) après l'inclusion de l'en-tête ; traduction de l'interface (api/i18n.php)

// Journal des erreurs (aussi chargé par api/config.php quand la ligne y a été ajoutée)
if (is_file(__DIR__ . '/../api/errors.php')) require_once __DIR__ . '/../api/errors.php';
$body_class = $body_class ?? 'bg-gray-100';
// Publicité : à activer page par page ($ads_enabled = true avant l'inclusion), jamais sur les pages de compte, paiement ou messagerie
$ads_enabled = $ads_enabled ?? false;
require_once __DIR__ . '/ads.php';
require_once __DIR__ . '/logo.php';

function clean_url($url) {
    return str_replace(".php", "", $url);
}

$is_logged_in = isset($_SESSION['user']);
$is_director = $is_logged_in && ($_SESSION['user']['is_directeur'] ?? false);
$is_admin = $is_logged_in && ($_SESSION['user']['is_admin'] ?? false);
$is_animateur = $is_logged_in && ($_SESSION['user']['is_animateur'] ?? false);
$adm_todo = []; $adm_unread = 0; $adm_links = [];
if ($is_logged_in) {
    try {
        require_once __DIR__ . '/../api/config.php';
        require_once __DIR__ . '/../api/adm_core.php';
        global $pdo;
        if (isset($pdo) && time() - (int)($_SESSION['bloque_chk'] ?? 0) > 300) {
            $_SESSION['bloque_chk'] = time();
            $q = $pdo->prepare("SELECT COALESCE(bloque, 0) b, is_directeur, is_admin FROM users WHERE id = ?");
            $q->execute([(int)$_SESSION['user']['id']]);
            $fr = $q->fetch(PDO::FETCH_ASSOC) ?: ['b' => 1];
            if ((int)$fr['b'] === 1) { $_SESSION = []; session_destroy(); header('Location: /login.php?suspendu=1'); exit; }
            $_SESSION['user']['is_directeur'] = (int)$fr['is_directeur']; $_SESSION['user']['is_admin'] = (int)$fr['is_admin'];
            $is_director = (bool)$fr['is_directeur']; $is_admin = (bool)$fr['is_admin'];
        }
        if ($is_admin && isset($pdo)) {
            $is_admin = adm_is_staff();
            if ($is_admin) {
                $adm_todo = adm_todo_counts();
                $adm_unread = adm_notif_unread();
                foreach (adm_todo_types() as $k => [$l, $perm, $href]) if (adm_can($perm)) $adm_links[] = [$l, '/' . $href, (int)$adm_todo[$k]];
            }
        }
    } catch (Throwable $e) { error_log('header admin: ' . $e->getMessage()); }
}
$adm_total = array_sum($adm_todo);

// Compte animateur : expérience dédiée (onglets Missions, Candidatures, Profil, Préférences). Un compte animateur qui est AUSSI parent
// (a au moins une fiche enfant) choisit son mode dans le menu du compte ; le choix est gardé en session (api/anim_mode.php).
$anim_ui = false; $anim_parent_too = false;
if ($is_animateur) {
    if (!isset($_SESSION['anim_parent_too'])) {
        $_SESSION['anim_parent_too'] = false;
        try {
            require_once __DIR__ . '/../api/config.php';
            global $pdo;
            if (isset($pdo)) { $q = $pdo->prepare("SELECT 1 FROM enfants WHERE parent_id = ? LIMIT 1"); $q->execute([(int)$_SESSION['user']['id']]); $_SESSION['anim_parent_too'] = (bool)$q->fetchColumn(); }
        } catch (Throwable $e) {}
    }
    $anim_parent_too = (bool)$_SESSION['anim_parent_too'];
    $anim_ui = !($anim_parent_too && ($_SESSION['mode_compte'] ?? 'animateur') === 'parent');
}
$home_url = $anim_ui ? 'animateur' : clean_url('../');
$csrf_value = '';
if ($is_logged_in) { require_once __DIR__ . '/../api/security.php'; $csrf_value = csrf_token(); }


// Avatar
$initial = 'U';
if ($is_logged_in && !empty($_SESSION['user']['prenom'])) {
    $initial = strtoupper(substr($_SESSION['user']['prenom'], 0, 1));
}
$placeholder_url_desktop = "https://placehold.co/36x36/e2e8f0/2563eb?text=" . urlencode($initial);
$placeholder_url_mobile = "https://placehold.co/48x48/e2e8f0/2563eb?text=" . urlencode($initial);
?>
<!DOCTYPE html>
<html lang="<?= mc_lang() ?>"<?= $ads_enabled && ads_consent_choisi() ? ' class="ads-on"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <?php if (!empty($base_href)) echo '<base href="' . htmlspecialchars($base_href) . '">'; /* pages dans un sous-dossier d'adresse (/organisme/xxx) */ ?>
    <?php if ($csrf_value !== ''): ?><meta name="csrf" content="<?php echo htmlspecialchars($csrf_value); ?>"><?php endif; ?>

    <?php
    $seo_titre = $page_title ?? 'moncamp.fr - Colonies de vacances et séjours pour enfants et ados';
    $seo_desc = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($page_desc ?? 'Colonies de vacances, camps et séjours pour enfants et ados : comparez les dates, les âges et les prix, et réservez en ligne en toute sécurité sur moncamp.fr.'))), 0, 160);
    $seo_q = array_intersect_key($_GET, ['t' => 1]) + (mc_lang() !== 'fr' ? ['lang' => mc_lang()] : []);
    $seo_path = preg_replace(['#\.php$#', '#^/index$#'], ['', '/'], (string)strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
    $seo_url = $page_canonical ?? 'https://moncamp.fr' . $seo_path . ($seo_q ? '?' . http_build_query($seo_q) : '');
    $seo_img = $page_image ?? 'https://moncamp.fr/assets/img/hero-1280.jpg';
    if (!preg_match('#^https?://#', $seo_img)) $seo_img = 'https://moncamp.fr/' . ltrim($seo_img, '/');
    ?>
    <title><?= htmlspecialchars($seo_titre) ?></title>
    <meta name="description" content="<?= htmlspecialchars($seo_desc) ?>">
    <?php if (!empty($page_noindex) || http_response_code() >= 400): ?><meta name="robots" content="noindex"><?php else: ?><link rel="canonical" href="<?= htmlspecialchars($seo_url) ?>"><?php endif; ?>
    <meta property="og:site_name" content="moncamp.fr"><meta property="og:type" content="<?= htmlspecialchars($page_og_type ?? 'website') ?>"><meta property="og:locale" content="<?= mc_lang() === 'fr' ? 'fr_FR' : 'en_GB' ?>">
    <meta property="og:title" content="<?= htmlspecialchars($seo_titre) ?>"><meta property="og:description" content="<?= htmlspecialchars($seo_desc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($seo_url) ?>"><meta property="og:image" content="<?= htmlspecialchars($seo_img) ?>"><meta name="twitter:card" content="summary_large_image">
    <?php foreach ($page_jsonld ?? [] as $seo_j): ?><script type="application/ld+json"><?= json_encode($seo_j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script><?php endforeach; ?>

    <?php if ($ads_enabled): ?>
    <?php /* Publicité : uniquement sur les pages de contenu. Consentement refusé par défaut (Consent Mode v2) ; assets/ads.js ne charge AdSense qu'après le choix cookies (assets/consent.js). */ ?>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){ dataLayer.push(arguments); }
        gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' });
    </script>
    <script defer src="/assets/consent.js?v=1"></script>
    <script defer src="/assets/ads.js?v=2" onerror="document.documentElement.classList.add('no-ads')"></script>
    <style>
        .ad-wrap { margin: 28px auto; max-width: 100%; text-align: center; }
        .ad-wrap .ad-label { display:block; font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#9aa3ab; margin-bottom:6px; }
        .ad-wrap .ad-box { display:block; width:100%; overflow:hidden; }
        .ad-wrap.ad-empty, .no-ads .ad-wrap, .no-ads .ad-card, html:not(.ads-on) .ad-wrap, html:not(.ads-on) .ad-card { display:none !important; }
    </style>
    <?php endif; ?>

    <link rel="icon" type="image/svg+xml" href="/favicon.svg"><link rel="icon" type="image/png" href="/favico.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        #nav-links > a { position:relative; transition:color .2s ease, transform .2s ease; }
        #nav-links > a::after { content:''; position:absolute; left:10px; right:10px; bottom:3px; height:2px; border-radius:2px; background:#3d6a8e; transform:scaleX(0); transform-origin:left center; transition:transform .25s ease; }
        #nav-links > a:hover { transform:translateY(-1px); }
        #nav-links > a:hover::after { transform:scaleX(1); }
        @media (prefers-reduced-motion: reduce) { #nav-links > a, #nav-links > a::after { transition:none; } #nav-links > a:hover { transform:none; } }
        body { font-family: 'Montserrat', system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #26343b; }
        .font-display { font-family: 'Montserrat', system-ui, sans-serif; font-weight: 800; letter-spacing: -0.01em; }
        .loader { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; } 
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } } 
        .body-no-scroll { overflow: hidden; }
        /* Safe areas : encoche, caméra frontale / Dynamic Island et barre inférieure sur mobile */
        .safe-header { padding-top: env(safe-area-inset-top, 0px); }
        .safe-drawer {
            padding-top: env(safe-area-inset-top, 0px);
            padding-right: env(safe-area-inset-right, 0px);
            padding-bottom: env(safe-area-inset-bottom, 0px);
        }
        /* Animation du dropdown */
        /* Menu du compte : ne se ferme pas quand la souris traverse le petit espace entre le bouton et le menu.
- la zone de passage (padding-top du menu) fait partie du menu, donc le survol est continu
- la fermeture est retardée de 150 ms : un léger écart de trajectoire est pardonné */
        .dropdown-menu { visibility: hidden; opacity: 0; transform: translateY(-6px); transition: opacity .16s ease .15s, transform .16s ease .15s, visibility 0s linear .31s; }
        .nav-dd:hover .dropdown-menu, .nav-dd:focus-within .dropdown-menu { visibility: visible; opacity: 1; transform: none; transition-delay: 0s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        #history-list::-webkit-scrollbar { width: 4px; }
        #history-list::-webkit-scrollbar-track { background: #f1f1f1; }
        #history-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
    <?php include __DIR__ . '/pwa.php'; include __DIR__ . '/i18n_head.php'; ?>
    <?php if (!$ads_enabled): ?><script defer src="/assets/consent.js?v=1"></script><?php endif; ?>
    <link rel="stylesheet" href="/assets/ds.css?v=9">
    <script src="/assets/ui.js?v=4"></script>
</head>
<body class="<?php echo htmlspecialchars($body_class); ?><?php echo $body_class === 'bg-gray-100' ? ' ds-legacy' : ''; ?> text-gray-800 flex flex-col min-h-screen">

    <header class="bg-white border-b border-gray-200 sticky top-0 z-40 safe-header">
        <nav class="w-full px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between lg:justify-start h-[68px] gap-4 lg:gap-6">

                <!-- Logo -->
                <a href="<?php echo $home_url; ?>" class="flex-shrink-0 flex items-center gap-1.5 whitespace-nowrap" aria-label="moncamp.fr, accueil">
                    <?= mc_logo('dark', true, 30) ?>
                </a>

                <!-- Recherche rapide -->
                <div id="search-wrapper" class="hidden lg:block flex-1 max-w-[560px] transition-all duration-500 ease-in-out relative">
                    <form id="desktop-search-form" action="recherche" method="GET" class="relative group h-full flex items-center">
                        <input type="text" id="desktop-search-input" name="name" placeholder="Rechercher une colo..." autocomplete="off"
                               class="w-full h-10 bg-[#f3f1ea] border border-transparent focus:border-[#2f3d45] rounded-full pl-5 pr-11 focus:ring-0 focus:bg-white transition-all duration-300 text-[13px] text-gray-700 placeholder-gray-400 relative z-20 outline-none">
                        <button type="submit" aria-label="Rechercher" class="absolute right-0 top-1/2 -translate-y-1/2 mr-4 text-gray-400 hover:text-gray-700 transition-colors z-20">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.2"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                        </button>
                    </form>

                    <div id="history-dropdown" class="hidden absolute top-12 left-0 w-full bg-white shadow-xl rounded-2xl border border-gray-100 z-10 overflow-hidden">
                        <a href="recherche?autour=1" class="flex items-center gap-3 px-4 py-3 text-sm font-semibold text-[#3d6a8e] hover:bg-gray-50"><i class="fa-solid fa-location-crosshairs w-4 text-center"></i>Autour de moi</a>
                        <div id="history-block">
                        <div class="px-4 py-2 border-t border-gray-100 border-b border-gray-50 flex justify-between items-center bg-gray-50/50">
                            <span class="text-[10px] uppercase text-gray-400 font-bold tracking-wider">Recherches récentes</span>
                            <button id="clear-all-history" type="button" class="text-[10px] text-gray-400 hover:text-red-600 font-medium hover:underline">Tout effacer</button>
                        </div>
                        <div id="history-list" class="max-h-64 overflow-y-auto"></div>
                        </div>
                    </div>
                </div>

                <div class="hidden lg:block flex-1"></div>


                <!-- Navigation -->
                <div id="nav-links" class="hidden lg:flex items-center gap-1 lg:gap-3 flex-shrink-0 transition-all duration-500 ease-in-out overflow-hidden whitespace-nowrap opacity-100">
                    <a href="<?php echo $home_url; ?>" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Accueil</a>

                    <?php if ($anim_ui): ?>
                        <a href="missions" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Missions</a>
                        <a href="espace-animation" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Mes candidatures</a>
                        <a href="profil-animateur" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Mon profil</a>
                        <a href="preferences-animateur" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Mes préférences</a>
                    <?php endif; ?>


                    <?php if ($is_director): ?>
                        <a href="organisateurs" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors"><?php echo $is_admin ? 'Organisateur' : 'Organisateurs'; ?></a>
                    <?php endif; ?>

                    <?php if ($is_logged_in && !$anim_ui): ?>
                        <?php if (!$is_admin): ?><a href="favorites" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Favoris</a><?php endif; ?>
                        <a href="reservations" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Réservations</a>
                    <?php endif; ?>

                    <?php if ($is_admin): ?>
                        <div class="relative nav-dd py-2">
                            <a href="/admin.php" class="inline-flex items-center gap-1.5 text-[#26343b] px-2.5 py-2 text-[14px] font-bold">Administration<?php if ($adm_total): ?><span class="min-w-[20px] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-[#c0392b] text-white text-[11px] font-bold"><?php echo $adm_total; ?></span><?php endif; ?></a>
                            <div class="dropdown-menu absolute right-0 top-full w-72 pt-3 z-50">
                                <div class="bg-white rounded-xl shadow-2xl border border-gray-100 overflow-hidden py-1">
                                    <div class="px-4 py-1 text-xs font-bold text-gray-400 uppercase tracking-wider">À traiter</div>
                                    <?php foreach ($adm_links as [$l, $href, $n]): ?>
                                    <a href="<?php echo htmlspecialchars($href); ?>" class="flex items-center justify-between px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><span><?php echo htmlspecialchars($l); ?></span><span class="min-w-[24px] h-6 px-2 inline-flex items-center justify-center rounded-full text-xs font-bold <?php echo $n ? 'bg-[#faf1dc] text-[#8a5a12]' : 'bg-gray-100 text-gray-500'; ?>"><?php echo $n; ?></span></a>
                                    <?php endforeach; ?>
                                    <div class="border-t border-gray-100 mt-1 pt-1">
                                        <a href="/admin.php" class="block px-4 py-2 text-sm font-bold text-[#26343b] hover:bg-gray-50">Tableau de bord</a>
                                        <a href="/admin_notifs.php" class="block px-4 py-2 text-sm font-bold text-[#26343b] hover:bg-gray-50">Notifications</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                    <a href="contact" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Contact</a>
                    <a href="aide" class="text-[#3b4a54] hover:text-[#26343b] px-2.5 py-2 text-[14px] font-medium transition-colors">Aide</a>
                    <?php endif; ?>
                </div>

                <div class="hidden lg:flex items-center gap-3 flex-shrink-0 transition-all duration-300" id="user-actions">
                    <?php if ($is_admin): ?>
                        <a href="/admin_notifs.php" class="relative text-gray-700 hover:text-gray-900 w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors" title="Notifications d'administration" aria-label="Notifications d'administration<?php echo $adm_unread ? ' (' . $adm_unread . ' non lues)' : ''; ?>">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16V11a6 6 0 0112 0v5l2 2H4l2-2z"/><path d="M10 20a2 2 0 004 0"/></svg>
                            <?php if ($adm_unread): ?><span class="absolute top-0.5 right-0 min-w-[16px] h-4 px-1 flex items-center justify-center bg-red-600 text-white text-[10px] font-bold rounded-full"><?php echo $adm_unread > 99 ? '99+' : $adm_unread; ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($is_logged_in): ?>
                        <a href="messagerie" class="relative text-gray-700 hover:text-gray-900 w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors" title="Messagerie" aria-label="Messagerie">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="5" width="18" height="14" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M4 7.5l8 6 8-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span id="unread-badge-desktop" class="absolute top-0.5 right-0.5 w-4 h-4 flex items-center justify-center bg-red-600 text-white text-[10px] font-bold rounded-full hidden"></span>
                        </a>

                        <div class="relative group nav-dd py-2">
                            <a href="profile" class="relative block" aria-label="Mon profil">
                                <?php if (!empty($_SESSION['user']['photo_url'])): ?>
                                    <img class="h-10 w-10 rounded-full object-cover ring-2 ring-white shadow transition-transform duration-300 transform group-hover:scale-105" src="<?php echo htmlspecialchars($_SESSION['user']['photo_url']); ?>" alt="Profil">
                                <?php else: ?>
                                    <span class="h-10 w-10 rounded-full bg-[#3a4852] text-white text-[17px] font-bold flex items-center justify-center ring-2 ring-white shadow transition-transform duration-300 transform group-hover:scale-105"><?php echo htmlspecialchars($initial); ?></span>
                                <?php endif; ?>
                            </a>

                            <div class="dropdown-menu absolute right-0 top-full w-72 pt-3 z-50">
                            <div class="bg-white rounded-xl shadow-2xl border border-gray-100 overflow-hidden">
                                
                                <div class="px-4 py-3 border-b border-gray-50 bg-gray-50">
                                    <p class="text-sm font-bold text-gray-900 truncate"><?php echo htmlspecialchars($_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom']); ?></p>
                                    <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($_SESSION['user']['email']); ?></p>
                                </div>

                                <div class="py-1">
                                    <a href="edit_profile" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#3d6a8e]">
                                        <i class="fa-solid fa-user-pen w-6 text-center mr-2 text-gray-400"></i> Modifier mes informations
                                    </a>
                                    <?php if (!$anim_ui): ?>
                                    <a href="children" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#3d6a8e]">
                                        <i class="fa-solid fa-children w-6 text-center mr-2 text-gray-400"></i> Gérer mes enfants
                                    </a>
                                    <?php if (!$is_admin): ?>
                                    <a href="favorites" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#3d6a8e]">
                                        <i class="fa-solid fa-heart w-6 text-center mr-2 text-gray-400"></i> Favoris
                                    </a>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="reservations" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#3d6a8e]">
                                        <i class="fa-solid fa-file-invoice w-6 text-center mr-2 text-gray-400"></i> <?php echo $anim_ui ? 'Mes inscriptions (équipe)' : 'Réservations'; ?>
                                    </a>

                                    <a href="messagerie" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#3d6a8e]">
                                        <i class="fa-regular fa-envelope w-6 text-center mr-2 text-gray-400"></i> Messagerie
                                    </a>
                                </div>

                                <?php if ($anim_parent_too): ?>
                                <form action="api/anim_mode.php" method="POST" class="border-t border-gray-100 px-4 py-3" aria-label="Changer de mode">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf_value); ?>">
                                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Mode</p>
                                    <div class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 text-sm font-semibold">
                                        <button type="submit" name="m" value="parent" class="rounded-md py-1.5 <?php echo !$anim_ui ? 'bg-white shadow text-[#26343b]' : 'text-gray-500'; ?>" <?php echo !$anim_ui ? 'aria-current="true"' : ''; ?>>Parent</button>
                                        <button type="submit" name="m" value="animateur" class="rounded-md py-1.5 <?php echo $anim_ui ? 'bg-white shadow text-[#26343b]' : 'text-gray-500'; ?>" <?php echo $anim_ui ? 'aria-current="true"' : ''; ?>>Animateur</button>
                                    </div>
                                </form>
                                <?php endif; ?>

                                <?php if ($is_director): ?>
                                <div class="border-t border-gray-100 py-1">
                                    <div class="px-4 py-1 text-xs font-bold text-gray-400 uppercase tracking-wider">Espace Organisateur</div>
                                    <a href="public_infos" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#26343b]">
                                        <i class="fa-solid fa-building w-6 text-center mr-2 text-[#64748b]"></i> Mes organismes
                                    </a>
                                    <a href="public_infos.php?finances=1" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#26343b]">
                                        <i class="fa-solid fa-euro-sign w-6 text-center mr-2 text-[#64748b]"></i> Finances et versements
                                    </a>
                                    <a href="mes_camps" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#26343b]">
                                        <i class="fa-solid fa-campground w-6 text-center mr-2 text-[#64748b]"></i> Gérer mes camps
                                    </a>
                                    <a href="create_camp" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#26343b]">
                                        <i class="fa-solid fa-plus-circle w-6 text-center mr-2 text-[#64748b]"></i> Créer un camp
                                    </a>
                                    <a href="demandes-animation" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-[#26343b]">
                                        <i class="fa-solid fa-users-gear w-6 text-center mr-2 text-[#64748b]"></i> Gestion des animateurs
                                    </a>
                                </div>
                                <?php endif; ?>

                                <?php if ($is_admin): ?>
                                <div class="border-t border-gray-100 py-1">
                                    <div class="px-4 py-1 text-xs font-bold text-gray-400 uppercase tracking-wider">Administration</div>
                                    <a href="/admin.php" class="flex items-center px-4 py-2 text-sm text-[#26343b] hover:bg-gray-50 font-bold">
                                        <i class="fa-solid fa-gauge-high w-6 text-center mr-2"></i> Tableau de bord
                                    </a>
                                    <a href="/admin_notifs.php" class="flex items-center px-4 py-2 text-sm text-[#26343b] hover:bg-gray-50 font-bold">
                                        <i class="fa-solid fa-bell w-6 text-center mr-2"></i> Notifications<?php if ($adm_unread): ?> <span class="ml-auto text-xs bg-red-600 text-white rounded-full px-2"><?php echo $adm_unread; ?></span><?php endif; ?>
                                    </a>
                                </div>
                                <?php endif; ?>

                                <div class="border-t border-gray-100 py-1">
                                    <a href="api/logout" class="flex items-center px-4 py-2 text-sm text-[#26343b] hover:bg-red-50 hover:text-red-600 font-bold transition-colors">
                                        <i class="fa-solid fa-arrow-right-from-bracket w-6 text-center mr-2"></i> Se déconnecter
                                    </a>
                                </div>
                            </div>
                        </div>
                        </div>

                    <?php else: ?>
                        <a href="login" class="bg-[#2f3d45] hover:bg-[#1f2a30] text-white font-bold py-2 px-4 rounded-lg transition duration-300 text-sm whitespace-nowrap">Connexion</a>
                    <?php endif; ?>
                </div>

                <!-- Actions mobiles alignées à droite, même DA que desktop -->
                <div class="lg:hidden flex items-center gap-1 ml-auto flex-shrink-0">
                    <button id="mobile-search-trigger" type="button" aria-label="Rechercher" class="w-10 h-10 flex items-center justify-center rounded-full text-[#3b4a54] hover:text-[#26343b] hover:bg-gray-100 transition-colors">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.1"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></svg>
                    </button>
                    <button id="open-menu-button" type="button" aria-label="Ouvrir le menu" aria-controls="mobile-menu" aria-expanded="false" class="w-10 h-10 inline-flex items-center justify-center rounded-full text-[#3b4a54] hover:text-[#26343b] hover:bg-gray-100 focus:outline-none transition-colors">
                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                </div>
            </div>
        </nav>
    </header>

    <div id="mobile-menu-overlay" class="fixed inset-0 bg-[#26343b]/40 backdrop-blur-[2px] z-40 hidden transition-opacity"></div>
    <div class="fixed inset-0 z-50 overflow-hidden pointer-events-none">
    <aside id="mobile-menu" aria-label="Menu mobile" class="pointer-events-auto absolute inset-y-0 right-0 w-[85vw] max-w-sm bg-white z-50 transform translate-x-full transition-transform duration-300 ease-in-out shadow-2xl border-l border-gray-200 safe-drawer flex flex-col">
        <!-- En-tête du tiroir : même hauteur (68px) et bordure que la barre principale -->
        <div class="h-[68px] px-4 sm:px-5 border-b border-gray-200 flex items-center justify-between flex-shrink-0">
            <a href="<?php echo $home_url; ?>" class="flex items-center gap-1.5 whitespace-nowrap" aria-label="moncamp.fr, accueil">
                <?= mc_logo('dark', true, 28) ?>
            </a>
            <button id="close-menu-button" type="button" aria-label="Fermer le menu" class="w-10 h-10 inline-flex items-center justify-center rounded-full text-[#3b4a54] hover:text-[#26343b] hover:bg-gray-100 focus:outline-none transition-colors">
                <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <!-- Corps défilant -->
        <div class="flex-1 overflow-y-auto px-4 py-4">
            <!-- Barre de recherche identique à la DA desktop -->
            <form id="mobile-search-form" action="recherche" method="GET" class="mb-4">
                <div class="relative flex items-center">
                    <input type="text" id="mobile-search-input" name="name" placeholder="Rechercher une colo..." autocomplete="off"
                           class="w-full h-10 bg-[#f3f1ea] border border-transparent focus:border-[#2f3d45] rounded-full pl-5 pr-11 focus:ring-0 focus:bg-white transition-all text-[13px] text-gray-700 placeholder-gray-400 outline-none">
                    <button type="submit" aria-label="Rechercher" class="absolute right-0 top-1/2 -translate-y-1/2 mr-4 text-gray-400 hover:text-[#26343b] transition-colors">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.2"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                    </button>
                </div>
                <a href="recherche?autour=1" class="mt-2.5 flex items-center gap-2.5 px-3 py-2 rounded-xl text-[13px] font-semibold text-[#3d6a8e] hover:bg-gray-50 transition-colors">
                    <i class="fa-solid fa-location-crosshairs w-4 text-center"></i>
                    <span>Autour de moi</span>
                </a>
            </form>

            <!-- Carte compte utilisateur : même style que le dropdown desktop -->
            <?php if ($is_logged_in): ?>
                <a href="profile" class="flex items-center gap-3 p-3 rounded-xl mb-4 bg-gray-50 border border-gray-100 hover:border-gray-200 transition-colors">
                    <?php if (!empty($_SESSION['user']['photo_url'])): ?>
                        <img class="h-10 w-10 rounded-full object-cover ring-2 ring-white shadow flex-shrink-0" src="<?php echo htmlspecialchars($_SESSION['user']['photo_url']); ?>" alt="Profil">
                    <?php else: ?>
                        <span class="h-10 w-10 rounded-full bg-[#3a4852] text-white text-[17px] font-bold flex items-center justify-center ring-2 ring-white shadow flex-shrink-0"><?php echo htmlspecialchars($initial); ?></span>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="text-sm font-bold text-[#26343b] truncate"><?php echo htmlspecialchars($_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom']); ?></div>
                        <div class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($_SESSION['user']['email']); ?></div>
                    </div>
                </a>
            <?php endif; ?>

            <!-- Sélecteur de mode identique au dropdown desktop -->
            <?php if ($anim_parent_too): ?>
                <form action="api/anim_mode.php" method="POST" class="mb-4 px-1" aria-label="Changer de mode">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf_value); ?>">
                    <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1.5 px-2">Mode</p>
                    <div class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 text-sm font-semibold">
                        <button type="submit" name="m" value="parent" class="rounded-md py-1.5 transition-colors <?php echo !$anim_ui ? 'bg-white shadow text-[#26343b]' : 'text-gray-500'; ?>" <?php echo !$anim_ui ? 'aria-current="true"' : ''; ?>>Parent</button>
                        <button type="submit" name="m" value="animateur" class="rounded-md py-1.5 transition-colors <?php echo $anim_ui ? 'bg-white shadow text-[#26343b]' : 'text-gray-500'; ?>" <?php echo $anim_ui ? 'aria-current="true"' : ''; ?>>Animateur</button>
                    </div>
                </form>
            <?php endif; ?>

            <nav class="flex flex-col space-y-0.5">
                <a href="<?php echo $home_url; ?>" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                    <i class="fa-solid fa-house w-6 text-center mr-2 text-gray-400"></i> Accueil
                </a>

                <?php if ($anim_ui): ?>
                    <a href="missions" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-compass w-6 text-center mr-2 text-gray-400"></i> Missions
                    </a>
                    <a href="espace-animation" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-paper-plane w-6 text-center mr-2 text-gray-400"></i> Mes candidatures
                    </a>
                    <a href="profil-animateur" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-id-badge w-6 text-center mr-2 text-gray-400"></i> Mon profil
                    </a>
                    <a href="preferences-animateur" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-sliders w-6 text-center mr-2 text-gray-400"></i> Mes préférences
                    </a>
                    <a href="reservations" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-file-invoice w-6 text-center mr-2 text-gray-400"></i> Mes inscriptions (équipe)
                    </a>
                <?php endif; ?>

                <?php if ($is_logged_in && !$anim_ui): ?>
                    <a href="children" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-children w-6 text-center mr-2 text-gray-400"></i> Gérer mes enfants
                    </a>
                    <?php if (!$is_admin): ?>
                    <a href="favorites" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-heart w-6 text-center mr-2 text-gray-400"></i> Favoris
                    </a>
                    <?php endif; ?>
                    <a href="reservations" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-file-invoice w-6 text-center mr-2 text-gray-400"></i> Réservations
                    </a>
                <?php endif; ?>

                <?php if ($is_logged_in): ?>
                    <a href="messagerie" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <span class="flex items-center"><i class="fa-regular fa-envelope w-6 text-center mr-2 text-gray-400"></i> Messagerie</span>
                        <span id="unread-badge-mobile" class="min-w-[20px] h-5 px-1.5 flex items-center justify-center bg-red-600 text-white text-[11px] font-bold rounded-full hidden"></span>
                    </a>
                    <a href="edit_profile" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                        <i class="fa-solid fa-user-pen w-6 text-center mr-2 text-gray-400"></i> Modifier mes informations
                    </a>
                <?php endif; ?>

                <?php if ($is_director): ?>
                    <div class="pt-3 mt-2 border-t border-gray-100">
                        <div class="px-3 pb-1 text-[11px] font-bold text-gray-400 uppercase tracking-wider">Espace Organisateur</div>
                        <a href="organisateurs" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                            <i class="fa-solid fa-briefcase w-6 text-center mr-2 text-[#64748b]"></i> <?php echo $is_admin ? 'Organisateur' : 'Organisateurs'; ?>
                        </a>
                        <a href="public_infos" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                            <i class="fa-solid fa-building w-6 text-center mr-2 text-[#64748b]"></i> Mes organismes
                        </a>
                        <a href="public_infos.php?finances=1" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                            <i class="fa-solid fa-euro-sign w-6 text-center mr-2 text-[#64748b]"></i> Finances et versements
                        </a>
                        <a href="mes_camps" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                            <i class="fa-solid fa-campground w-6 text-center mr-2 text-[#64748b]"></i> Gérer mes camps
                        </a>
                        <a href="create_camp" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                            <i class="fa-solid fa-plus-circle w-6 text-center mr-2 text-[#64748b]"></i> Créer un camp
                        </a>
                        <a href="demandes-animation" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                            <i class="fa-solid fa-users-gear w-6 text-center mr-2 text-[#64748b]"></i> Gestion des animateurs
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                    <div class="pt-3 mt-2 border-t border-gray-100">
                        <div class="px-3 pb-1 text-[11px] font-bold text-gray-400 uppercase tracking-wider">Administration</div>
                        <a href="/admin.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-[14px] font-bold text-[#26343b] hover:bg-gray-50 transition-colors">
                            <span class="flex items-center"><i class="fa-solid fa-gauge-high w-6 text-center mr-2"></i> Tableau de bord</span>
                            <?php if ($adm_total): ?><span class="min-w-[20px] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-[#c0392b] text-white text-[11px] font-bold"><?php echo $adm_total; ?></span><?php endif; ?>
                        </a>
                        <?php foreach ($adm_links as [$l, $href, $n]): if (!$n) continue; ?>
                        <a href="<?php echo htmlspecialchars($href); ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            <span><?php echo htmlspecialchars($l); ?></span>
                            <span class="min-w-[24px] h-6 px-2 inline-flex items-center justify-center rounded-full text-xs font-bold bg-[#faf1dc] text-[#8a5a12]"><?php echo $n; ?></span>
                        </a>
                        <?php endforeach; ?>
                        <a href="/admin_notifs.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-[14px] font-bold text-[#26343b] hover:bg-gray-50 transition-colors">
                            <span class="flex items-center"><i class="fa-solid fa-bell w-6 text-center mr-2"></i> Notifications</span>
                            <?php if ($adm_unread): ?><span class="min-w-[20px] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-red-600 text-white text-[11px] font-bold"><?php echo $adm_unread > 99 ? '99+' : $adm_unread; ?></span><?php endif; ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!$is_admin): ?>
                    <div class="pt-3 mt-2 border-t border-gray-100">
                        <a href="contact" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                            <i class="fa-regular fa-comment-dots w-6 text-center mr-2 text-gray-400"></i> Contact
                        </a>
                        <a href="aide" class="flex items-center px-3 py-2.5 rounded-xl text-[14px] font-medium text-[#3b4a54] hover:bg-gray-50 hover:text-[#26343b] transition-colors">
                            <i class="fa-regular fa-circle-question w-6 text-center mr-2 text-gray-400"></i> Aide
                        </a>
                    </div>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Pied de tiroir fixe (toujours accessible au-dessus de la barre gestuelle) -->
        <div class="px-4 py-3 border-t border-gray-100 bg-white flex-shrink-0">
            <?php if ($is_logged_in): ?>
                <a href="api/logout" class="flex items-center justify-center gap-2 w-full py-2.5 px-4 rounded-xl text-sm font-bold text-[#26343b] bg-gray-100 hover:bg-red-50 hover:text-red-600 transition-colors">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Se déconnecter
                </a>
            <?php else: ?>
                <a href="login" class="block w-full bg-[#2f3d45] hover:bg-[#1f2a30] text-white font-bold py-2.5 px-4 rounded-lg transition duration-300 text-sm text-center">Connexion / Inscription</a>
            <?php endif; ?>
        </div>
    </aside>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // --- GESTION DE LA RECHERCHE ET HISTORIQUE ---
        const desktopInput = document.getElementById('desktop-search-input');
        const desktopForm = document.getElementById('desktop-search-form');
        const mobileInput = document.getElementById('mobile-search-input');
        const mobileForm = document.getElementById('mobile-search-form');
        
        const historyDropdown = document.getElementById('history-dropdown');
        const historyList = document.getElementById('history-list');
        const clearHistoryBtn = document.getElementById('clear-all-history');
        
        const navLinks = document.getElementById('nav-links');
        const searchWrapper = document.getElementById('search-wrapper');

        // Clé localStorage
        const STORAGE_KEY = 'colomap_search_history';

        // Fonction pour récupérer l'historique
        function getHistory() {
            try {
                return JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
            } catch { return []; }
        }

        // Fonction pour sauvegarder
        function saveSearch(term, count = null) {
            if (!term || term.trim() === '') return;
            term = term.trim();
            
            let history = getHistory();
            // Supprimer l'existant s'il y est déjà pour le remonter en premier
            history = history.filter(item => item.term.toLowerCase() !== term.toLowerCase());
            
            // Ajouter au début
            history.unshift({ term: term, count: count, date: new Date().getTime() });
            
            // Garder max 6
            if (history.length > 6) history = history.slice(0, 6);
            
            localStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        }

        // Fonction pour supprimer un item
        function deleteHistoryItem(term, e) {
            e.preventDefault();
            e.stopPropagation(); // Empêcher le clic sur le lien
            let history = getHistory();
            history = history.filter(item => item.term !== term);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(history));
            renderHistory();
            // Focus input pour ne pas fermer le dropdown
            if(desktopInput) desktopInput.focus();
        }

        // Afficher l'historique
        function renderHistory() {
            const history = getHistory();
            document.getElementById('history-block').hidden = history.length === 0;
            historyDropdown.classList.remove('hidden');
            if (history.length === 0) return;

            let html = '';
            history.forEach(item => {
                html += `
                <div class="relative group">
                    <a href="recherche?name=${encodeURIComponent(item.term)}" class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 transition-colors cursor-pointer">
                        <div class="flex items-center gap-3 overflow-hidden">
                            <i class="fa-solid fa-clock-rotate-left text-gray-300 group-hover:text-[#3d6a8e] transition-colors text-sm"></i>
                            <span class="text-sm text-gray-700 font-medium truncate">${item.term}</span>
                        </div>
                        <button class="delete-history-btn w-6 h-6 flex items-center justify-center rounded-full hover:bg-gray-200 text-gray-300 hover:text-red-500 transition-all z-20" data-term="${item.term.replace(/"/g, '"')}">
                            <i class="fa-solid fa-xmark text-xs"></i>
                        </button>
                    </a>
                </div>`;
            });
            
            historyList.innerHTML = html;
            
            // Attacher événements suppression
            document.querySelectorAll('.delete-history-btn').forEach(btn => {
                btn.addEventListener('click', (e) => deleteHistoryItem(btn.dataset.term, e));
            });

            historyDropdown.classList.remove('hidden');
        }

        // Gestionnaires d'événements Input Desktop
        if (desktopInput) {
            desktopInput.addEventListener('focus', () => {
                // Animation CSS header
                if(navLinks && window.innerWidth < 1100) { navLinks.style.maxWidth = '0px'; navLinks.style.opacity = '0'; navLinks.style.padding = '0'; navLinks.style.margin = '0'; }
                if(searchWrapper && window.innerWidth < 1100) { searchWrapper.classList.remove('max-w-[560px]'); searchWrapper.classList.add('max-w-full', 'w-full'); }
                
                renderHistory();
            });

            desktopInput.addEventListener('input', () => {
                if(desktopInput.value.length > 0) {
                    historyDropdown.classList.add('hidden');
                } else {
                    renderHistory();
                }
            });

            // Delay blur pour permettre le clic sur l'historique
            desktopInput.addEventListener('blur', (e) => {
                // Petit délai pour laisser le temps au clic de se faire
                setTimeout(() => {
                    historyDropdown.classList.add('hidden');
                    // Reset CSS header si vide
                    if(desktopInput.value === '') {
                        if(navLinks) { navLinks.style.maxWidth = '1000px'; navLinks.style.opacity = '1'; navLinks.style.padding = ''; navLinks.style.margin = ''; }
                        if(searchWrapper) { searchWrapper.classList.remove('max-w-full', 'w-full'); searchWrapper.classList.add('max-w-[560px]'); }
                    }
                }, 200);
            });
        }

        // Sauvegarde lors de la soumission (Desktop & Mobile)
        [desktopForm, mobileForm].forEach(form => {
            if(form) {
                form.addEventListener('submit', function() {
                    const input = this.querySelector('input[name="name"]');
                    if(input && input.value.trim() !== "") {
                        // On enregistre avec count null
                        saveSearch(input.value);
                    }
                });
            }
        });

        // Bouton tout effacer
        if(clearHistoryBtn) {
            clearHistoryBtn.addEventListener('mousedown', (e) => { // Mousedown déclenche avant blur
                localStorage.removeItem(STORAGE_KEY);
                renderHistory();
            });
        }

        // Mise à jour du nombre de résultats si on est sur la page recherche
        const urlParams = new URLSearchParams(window.location.search);
        const searchedTerm = urlParams.get('name');
        if (window.location.pathname.includes('recherche') && searchedTerm) {
            setTimeout(() => {
                const hasNoResults = document.body.innerText.includes('Aucun camp trouvé') || document.body.innerText.includes('Aucun résultat') || document.body.innerText.includes('Suggestions affichées');
                const resultCount = hasNoResults ? 0 : (document.querySelectorAll('.camp-card').length || 1); 
                
                if (resultCount > 0) {
                    saveSearch(searchedTerm, resultCount);
                } else {
                    let history = getHistory();
                    history = history.filter(item => item.term.toLowerCase() !== searchedTerm.toLowerCase());
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(history));
                }
            }, 1000); 
        }

        // --- MENU MOBILE ---
        const openMenuButton = document.getElementById('open-menu-button');
        const closeMenuButton = document.getElementById('close-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const overlay = document.getElementById('mobile-menu-overlay');
        const mobileSearchTrigger = document.getElementById('mobile-search-trigger');
        const mobileSearchInput = document.getElementById('mobile-search-input');
        const body = document.body;

        function openMenu(focusSearch = false) {
            mobileMenu.classList.remove('translate-x-full');
            overlay.classList.remove('hidden');
            body.classList.add('body-no-scroll');
            openMenuButton.setAttribute('aria-expanded', 'true');
            if (focusSearch && mobileSearchInput) setTimeout(() => mobileSearchInput.focus(), 300); else closeMenuButton.focus();
        }
        function closeMenu() {
            if (mobileMenu.classList.contains('translate-x-full')) return;
            openMenuButton.setAttribute('aria-expanded', 'false');
            if (mobileMenu.contains(document.activeElement)) openMenuButton.focus();
            mobileMenu.classList.add('translate-x-full');
            overlay.classList.add('hidden');
            body.classList.remove('body-no-scroll');
        }
        if(openMenuButton) openMenuButton.addEventListener('click', () => openMenu(false));
        if(closeMenuButton) closeMenuButton.addEventListener('click', closeMenu);
        if(overlay) overlay.addEventListener('click', closeMenu);
        if(mobileSearchTrigger) mobileSearchTrigger.addEventListener('click', () => openMenu(true));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });

        <?php if ($is_logged_in): ?>
        async function fetchUnreadCount() {
            try {
                const response = await fetch('api/get_unread_messages_count.php'); 
                if (!response.ok) return;
                const data = await response.json();
                const count = data.count || 0;
                const badgeDesktop = document.getElementById('unread-badge-desktop');
                const badgeMobile = document.getElementById('unread-badge-mobile');
                if (count > 0) {
                    if(badgeDesktop) { badgeDesktop.textContent = count; badgeDesktop.classList.remove('hidden'); }
                    if(badgeMobile) { badgeMobile.textContent = count; badgeMobile.classList.remove('hidden'); }
                } else {
                    if(badgeDesktop) badgeDesktop.classList.add('hidden');
                    if(badgeMobile) badgeMobile.classList.add('hidden');
                }
            } catch (error) { console.error(error); }
        }
        fetchUnreadCount();
        setInterval(fetchUnreadCount, 60000);
        <?php endif; ?>
    });
</script>
<script src="/assets/js/suggest.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
