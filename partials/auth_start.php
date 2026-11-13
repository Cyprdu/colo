<?php
/**
 * partials/auth_start.php : ouverture des pages d'entrée (connexion, inscription, mot de passe oublié).
 * Écran partagé : à gauche la scène animée « colonie de vacances », à droite le formulaire.
 *
 * Variables attendues (avant l'include) :
 *   $auth_title   titre de l'onglet
 *   $scene_kicker petite ligne au-dessus du titre        $scene_title  grand titre
 *   $scene_text   phrase d'accroche                       $scene_points liste de puces (facultatif)
 *   $auth_alt     ['text' => 'Pas encore de compte ?', 'label' => 'Créer un compte', 'href' => 'register.php']
 * Pas d'en-tête de site ni de publicité sur ces pages : l'entrée dans l'aventure reste immersive.
 */
$scene_points = $scene_points ?? [];
$auth_alt = $auth_alt ?? null;

/** Un pin stylisé (3 étages) ; la classe .sway le balance doucement */
if (!function_exists('auth_pine')) { function auth_pine(float $x, float $base, float $h, string $fill, float $delay): void {
    $w = $h * 0.36; $top = $base - $h; $y1 = $top + 0.38 * $h; $y2 = $top + 0.68 * $h;
    $pts = [[$x, $top], [$x + .55 * $w, $y1], [$x + .28 * $w, $y1], [$x + .82 * $w, $y2], [$x + .45 * $w, $y2], [$x + $w, $base],
            [$x - $w, $base], [$x - .45 * $w, $y2], [$x - .82 * $w, $y2], [$x - .28 * $w, $y1], [$x - .55 * $w, $y1]];
    $p = implode(' ', array_map(function ($q) { return round($q[0], 1) . ',' . round($q[1], 1); }, $pts));
    echo '<polygon class="sway" style="animation-delay:-' . $delay . 's;transform-origin:' . $x . 'px ' . $base . 'px" fill="' . $fill . '" points="' . $p . '"/>';
} }
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($auth_title ?? 'ColoMap') ?></title>
    <meta name="robots" content="noindex, follow">
    <link rel="icon" type="image/png" href="/favico.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#26343b">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ds.css?v=1">
    <style>
        body.auth-body { margin: 0; font-family: 'Montserrat', system-ui, sans-serif; background: #faf8f1; color: #26343b; -webkit-font-smoothing: antialiased; }
        .auth-wrap { min-height: 100vh; display: grid; grid-template-columns: 1fr; }
        @media (min-width: 1024px) { .auth-wrap { grid-template-columns: minmax(420px, 1.05fr) minmax(480px, 1fr); } }

        /* ---------- scène ---------- */
        .auth-scene { position: relative; overflow: hidden; background: #141d22; min-height: 250px; }
        @media (min-width: 1024px) { .auth-scene { position: sticky; top: 0; height: 100vh; min-height: 640px; } }
        .auth-scene svg.scene { position: absolute; inset: 0; width: 100%; height: 100%; }
        .scene-copy { position: absolute; z-index: 5; left: 0; right: 0; top: 0; padding: 28px 28px 0; color: #fff; pointer-events: none; }
        @media (min-width: 1024px) { .scene-copy { padding: 44px 48px 0; } }
        .scene-copy a { pointer-events: auto; }
        .scene-logo { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; font-size: 22px; color: #fff; text-decoration: none; }
        .scene-kicker { margin-top: 28px; font-size: 12px; font-weight: 700; letter-spacing: .18em; text-transform: uppercase; color: #e9e3d0; opacity: .85; }
        .scene-title { margin-top: 10px; font-size: clamp(26px, 4.2vw, 46px); font-weight: 800; line-height: 1.08; letter-spacing: -.02em; max-width: 460px; text-shadow: 0 2px 24px rgba(0,0,0,.35); }
        .scene-text { margin-top: 14px; font-size: 16px; line-height: 1.55; color: #d6dde2; max-width: 400px; }
        .scene-points { margin-top: 20px; display: grid; gap: 10px; font-size: 14.5px; font-weight: 600; color: #eef1f3; }
        .scene-points li { display: flex; align-items: center; gap: 12px; }
        .scene-points i { width: 28px; height: 28px; flex: none; border-radius: 9999px; background: rgba(255,255,255,.12); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; font-size: 12px; color: #f3e9c9; }
        @media (max-width: 1023px) { .scene-text, .scene-points, .scene-kicker { display: none; } .scene-title { font-size: 24px; margin-top: 14px; } .scene-copy { padding: 20px 22px 0; } }

        /* ---------- animations de la scène ---------- */
        @keyframes twinkle { 0%, 100% { opacity: .2; } 50% { opacity: 1; } }
        @keyframes drift   { from { transform: translateX(-260px); } to { transform: translateX(1060px); } }
        @keyframes sway    { 0%, 100% { transform: rotate(-1.4deg); } 50% { transform: rotate(1.6deg); } }
        @keyframes flame   { 0%, 100% { transform: scale(1, 1) skewX(0deg); } 25% { transform: scale(.94, 1.1) skewX(-3deg); } 50% { transform: scale(1.05, .92) skewX(2deg); } 75% { transform: scale(.97, 1.07) skewX(-2deg); } }
        @keyframes glow    { 0%, 100% { opacity: .55; } 50% { opacity: .9; } }
        @keyframes spark   { 0% { transform: translate(0, 0) scale(1); opacity: 0; } 12% { opacity: 1; } 100% { transform: translate(var(--dx), -150px) scale(.2); opacity: 0; } }
        @keyframes firefly { 0% { transform: translate(0, 0); opacity: 0; } 15% { opacity: 1; } 50% { transform: translate(26px, -22px); opacity: .5; } 85% { opacity: 1; } 100% { transform: translate(-14px, 12px); opacity: 0; } }
        @keyframes moonFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        @keyframes riseIn  { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }
        .twinkle { animation: twinkle 3.4s ease-in-out infinite; }
        .cloud   { animation: drift 90s linear infinite; }
        .sway    { animation: sway 5.5s ease-in-out infinite; }
        .flame   { transform-box: fill-box; transform-origin: 50% 100%; animation: flame 1.1s ease-in-out infinite; }
        .fire-glow { animation: glow 1.6s ease-in-out infinite; }
        .spark   { animation: spark 2.8s ease-out infinite; }
        .firefly { animation: firefly 7s ease-in-out infinite; }
        .moon    { animation: moonFloat 9s ease-in-out infinite; }
        .layer   { transition: transform .5s cubic-bezier(.2, .7, .2, 1); will-change: transform; }

        /* ---------- panneau formulaire ---------- */
        .auth-panel { display: flex; flex-direction: column; justify-content: center; min-height: 100%; padding: 28px 20px 48px; position: relative; }
        @media (min-width: 640px) { .auth-panel { padding: 40px 48px 56px; } }
        .auth-inner { width: 100%; max-width: 440px; margin: 0 auto; }
        .auth-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 14px; font-weight: 600; margin-bottom: 28px; color: #6b7780; }
        .auth-top a { color: #26343b; font-weight: 700; }
        .auth-top a:hover { color: #3d6a8e; }
        .auth-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 28px; padding: 32px 28px; box-shadow: 0 24px 60px rgba(38, 52, 59, .10); animation: riseIn .6s cubic-bezier(.2, .7, .2, 1) both; }
        @media (min-width: 640px) { .auth-card { padding: 40px 38px; } }
        .rise > * { animation: riseIn .55s cubic-bezier(.2, .7, .2, 1) both; }
        .rise > *:nth-child(1) { animation-delay: .10s; } .rise > *:nth-child(2) { animation-delay: .17s; } .rise > *:nth-child(3) { animation-delay: .24s; }
        .rise > *:nth-child(4) { animation-delay: .31s; } .rise > *:nth-child(5) { animation-delay: .38s; } .rise > *:nth-child(6) { animation-delay: .45s; }
        .rise > *:nth-child(7) { animation-delay: .52s; } .rise > *:nth-child(8) { animation-delay: .59s; }
        .auth-title { font-size: 28px; font-weight: 800; line-height: 1.15; letter-spacing: -.01em; }
        .auth-sub { margin-top: 8px; color: #6b7780; font-size: 15px; line-height: 1.5; }
        .field { position: relative; }
        .field > i.ico { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #9aa3ab; pointer-events: none; transition: color .2s ease; }
        .field .ds-input { padding-left: 44px; height: 52px; }
        .field:focus-within > i.ico { color: #2f3d45; }
        .auth-btn { position: relative; width: 100%; height: 54px; border-radius: 14px; background: #2f3d45; color: #fff; font-weight: 700; font-size: 16px; display: flex; align-items: center; justify-content: center; gap: 10px; overflow: hidden; transition: background .2s ease, transform .15s ease, box-shadow .2s ease; box-shadow: 0 10px 24px rgba(47, 61, 69, .25); cursor: pointer; border: 0; }
        .auth-btn:hover { background: #1f2a30; transform: translateY(-1px); box-shadow: 0 14px 30px rgba(47, 61, 69, .30); }
        .auth-btn:active { transform: translateY(0); }
        .auth-btn .arrow { transition: transform .25s ease; }
        .auth-btn:hover .arrow { transform: translateX(5px); }
        .auth-btn.is-loading { pointer-events: none; opacity: .85; }
        .auth-btn.is-loading .arrow { display: none; }
        .auth-btn .spin { display: none; width: 18px; height: 18px; border-radius: 9999px; border: 2.5px solid rgba(255,255,255,.35); border-top-color: #fff; animation: spin .7s linear infinite; }
        .auth-btn.is-loading .spin { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .auth-or { display: flex; align-items: center; gap: 14px; margin: 24px 0; color: #9aa3ab; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .auth-or::before, .auth-or::after { content: ''; flex: 1; height: 1px; background: #e5e7eb; }
        .google-btn { width: 100%; height: 52px; border-radius: 14px; border: 1.5px solid #e5e7eb; background: #fff; display: flex; align-items: center; justify-content: center; gap: 12px; font-weight: 700; font-size: 15px; color: #26343b; transition: background .15s ease, border-color .15s ease, transform .15s ease; }
        .google-btn:hover { background: #f6f8fa; border-color: #a9b8c6; transform: translateY(-1px); }
        .role-card { position: relative; display: block; cursor: pointer; }
        .role-card input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
        .role-card .box { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; padding: 18px 12px; border: 1.5px solid #e5e7eb; border-radius: 18px; transition: all .2s ease; background: #fff; }
        .role-card .box i { font-size: 22px; color: #6b7780; transition: color .2s ease, transform .2s ease; }
        .role-card .box b { font-size: 15px; }
        .role-card .box span { font-size: 12.5px; color: #6b7780; line-height: 1.35; }
        .role-card input:checked + .box { border-color: #2f3d45; background: #f1f3f5; box-shadow: 0 0 0 3px rgba(47, 61, 69, .12); }
        .role-card input:checked + .box i { color: #2f3d45; transform: scale(1.12); }
        .role-card input:focus-visible + .box { outline: 2px solid #3d6a8e; outline-offset: 2px; }
        .meter { height: 6px; border-radius: 9999px; background: #eceef1; overflow: hidden; }
        .meter > i { display: block; height: 100%; width: 0; border-radius: 9999px; background: #2f3d45; transition: width .3s ease, background .3s ease; }
        .check-pop { animation: pop .5s cubic-bezier(.2, 1.4, .4, 1) both; }
        @keyframes pop { from { transform: scale(.3); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .twinkle, .cloud, .sway, .flame, .fire-glow, .spark, .firefly, .moon, .auth-card, .rise > *, .check-pop { animation: none !important; } .layer { transition: none; } }
    </style>
</head>
<body class="auth-body">
<div class="auth-wrap">

    <!-- ===== Scène : l'entrée de la colonie ===== -->
    <aside class="auth-scene" aria-label="Illustration : un campement au pied des montagnes">
        <svg class="scene" viewBox="0 0 800 1000" preserveAspectRatio="xMidYMax slice" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#0f171b"/><stop offset=".5" stop-color="#22303a"/><stop offset=".78" stop-color="#3d4e5a"/><stop offset="1" stop-color="#6d7d88"/>
                </linearGradient>
                <radialGradient id="horizon" cx="50%" cy="100%" r="70%"><stop offset="0" stop-color="#f3e2b0" stop-opacity=".55"/><stop offset=".6" stop-color="#f3e2b0" stop-opacity=".08"/><stop offset="1" stop-color="#f3e2b0" stop-opacity="0"/></radialGradient>
                <radialGradient id="moonHalo"><stop offset="0" stop-color="#f5efd8" stop-opacity=".45"/><stop offset="1" stop-color="#f5efd8" stop-opacity="0"/></radialGradient>
                <radialGradient id="fireHalo"><stop offset="0" stop-color="#f7c873" stop-opacity=".75"/><stop offset="1" stop-color="#f7c873" stop-opacity="0"/></radialGradient>
                <linearGradient id="flameG" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#e2762f"/><stop offset=".55" stop-color="#f3b64e"/><stop offset="1" stop-color="#fbe7a6"/></linearGradient>
                <linearGradient id="tentG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e4ddc7"/><stop offset="1" stop-color="#b9b29b"/></linearGradient>
            </defs>

            <rect width="800" height="1000" fill="url(#sky)"/>
            <rect width="800" height="1000" fill="url(#horizon)"/>

            <!-- étoiles -->
            <g class="layer" data-depth="3">
                <?php mt_srand(11); for ($i = 0; $i < 46; $i++):
                    $sx = mt_rand(10, 790); $sy = mt_rand(8, 470); $sr = mt_rand(6, 17) / 10; $sd = mt_rand(0, 40) / 10; $sdu = mt_rand(24, 52) / 10; ?>
                    <circle class="twinkle" cx="<?= $sx ?>" cy="<?= $sy ?>" r="<?= $sr ?>" fill="#fff" style="animation-delay:-<?= $sd ?>s;animation-duration:<?= $sdu ?>s"/>
                <?php endfor; ?>
            </g>

            <!-- lune -->
            <g class="layer" data-depth="5">
                <g class="moon"><circle cx="618" cy="196" r="96" fill="url(#moonHalo)"/><circle cx="618" cy="196" r="40" fill="#f5efd8"/><circle cx="604" cy="184" r="7" fill="#e6dec0" opacity=".7"/><circle cx="632" cy="208" r="5" fill="#e6dec0" opacity=".6"/></g>
            </g>

            <!-- nuages -->
            <g class="layer" data-depth="7">
                <g class="cloud" style="animation-duration:95s;animation-delay:-30s" opacity=".14" fill="#dfe6eb"><ellipse cx="0" cy="300" rx="120" ry="16"/><ellipse cx="60" cy="288" rx="70" ry="14"/></g>
                <g class="cloud" style="animation-duration:130s;animation-delay:-80s" opacity=".10" fill="#dfe6eb"><ellipse cx="0" cy="410" rx="150" ry="18"/><ellipse cx="-50" cy="398" rx="80" ry="13"/></g>
                <g class="cloud" style="animation-duration:110s;animation-delay:-10s" opacity=".08" fill="#dfe6eb"><ellipse cx="0" cy="240" rx="100" ry="12"/></g>
            </g>

            <!-- montagnes lointaines -->
            <g class="layer" data-depth="9">
                <path d="M-20 700 L90 548 L150 600 L250 430 L345 575 L420 500 L520 372 L640 560 L720 480 L830 620 L830 760 L-20 760 Z" fill="#364651"/>
                <path d="M250 430 L222 478 L246 470 L262 492 L282 468 L300 476 Z" fill="#e9e3d0" opacity=".85"/>
                <path d="M520 372 L488 428 L512 420 L530 442 L552 418 L574 430 Z" fill="#e9e3d0" opacity=".85"/>
            </g>
            <!-- montagnes proches -->
            <g class="layer" data-depth="14">
                <path d="M-20 790 L70 640 L160 720 L260 590 L370 730 L470 640 L590 760 L690 650 L830 780 L830 830 L-20 830 Z" fill="#2a3943"/>
                <path d="M260 590 L238 626 L258 620 L272 636 L290 616 L304 624 Z" fill="#cfd6dc" opacity=".7"/>
            </g>

            <!-- forêt : arrière-plan -->
            <g class="layer" data-depth="20">
                <?php mt_srand(5); for ($i = 0; $i < 13; $i++) auth_pine(20 + $i * 62 + mt_rand(-14, 14), 842, mt_rand(110, 160), '#1c2931', mt_rand(0, 55) / 10); ?>
            </g>

            <!-- sol -->
            <g class="layer" data-depth="26">
                <path d="M-20 850 C150 818 300 842 440 836 C600 828 700 812 830 836 L830 1010 L-20 1010 Z" fill="#141c20"/>
            </g>

            <!-- forêt : premier plan -->
            <g class="layer" data-depth="34">
                <?php auth_pine(40, 900, 230, '#0f171b', 1.2); auth_pine(120, 930, 170, '#111a1f', 3.1); auth_pine(738, 905, 250, '#0f171b', 2.2); auth_pine(668, 940, 180, '#111a1f', 4.0); ?>
            </g>

            <!-- campement -->
            <g class="layer" data-depth="40">
                <!-- tente -->
                <path d="M118 934 L222 818 L326 934 Z" fill="url(#tentG)"/>
                <path d="M222 818 L190 934 L254 934 Z" fill="#1c1812"/>
                <path d="M222 818 L190 934 L254 934 Z" fill="#f7c873" opacity=".22" class="fire-glow"/>
                <path d="M222 818 L118 934 M222 818 L326 934" stroke="#8e876f" stroke-width="2" fill="none" opacity=".6"/>
                <rect x="219" y="800" width="6" height="22" fill="#8e876f"/>
                <path d="M225 802 L252 810 L225 818 Z" fill="#c9d2d9"/>
                <!-- feu de camp -->
                <circle cx="468" cy="920" r="120" fill="url(#fireHalo)" class="fire-glow"/>
                <rect x="436" y="925" width="66" height="10" rx="5" fill="#3b2a1d" transform="rotate(-10 469 930)"/>
                <rect x="436" y="925" width="66" height="10" rx="5" fill="#2e2016" transform="rotate(10 469 930)"/>
                <path class="flame" d="M469 928 C440 920 446 890 462 872 C458 892 470 898 474 880 C486 896 502 918 469 928 Z" fill="url(#flameG)"/>
                <path class="flame" style="animation-delay:-.4s" d="M469 926 C452 920 456 904 466 892 C466 904 474 906 478 896 C486 906 488 920 469 926 Z" fill="#fbe7a6" opacity=".9"/>
                <?php foreach ([[460, -18, 0], [472, 14, .9], [466, -6, 1.7], [478, 24, 2.3], [456, -26, 1.2]] as [$px, $dx, $dl]): ?>
                    <circle class="spark" cx="<?= $px ?>" cy="880" r="2.2" fill="#fbd98a" style="--dx:<?= $dx ?>px;animation-delay:-<?= $dl ?>s"/>
                <?php endforeach; ?>
            </g>

            <!-- lucioles -->
            <g class="layer" data-depth="46">
                <?php mt_srand(23); for ($i = 0; $i < 9; $i++): $fx = mt_rand(60, 740); $fy = mt_rand(700, 900); ?>
                    <circle class="firefly" cx="<?= $fx ?>" cy="<?= $fy ?>" r="2.4" fill="#f6e7a8" style="animation-duration:<?= mt_rand(55, 95) / 10 ?>s;animation-delay:-<?= mt_rand(0, 70) / 10 ?>s;filter:drop-shadow(0 0 5px #f6e7a8)"/>
                <?php endfor; ?>
            </g>
        </svg>

        <div class="scene-copy">
            <a href="/" class="scene-logo" aria-label="ColoMap, retour à l'accueil">
                <svg width="30" height="32" viewBox="0 0 26 28" fill="none" aria-hidden="true"><path d="M10 1.5L5.2 9h2.8L3.6 15.4h3.2L2 22.5h16L13.2 15.4h3.2L12 9h2.8L10 1.5Z" fill="#f3e9c9"/><rect x="9" y="22.5" width="2" height="3.2" fill="#f3e9c9"/><path d="M17.5 26.5L22 17l4 9.5h-8.5Z" fill="#fff"/></svg>
                ColoMap
            </a>
            <?php if (!empty($scene_kicker)): ?><p class="scene-kicker"><?= htmlspecialchars($scene_kicker) ?></p><?php endif; ?>
            <h2 class="scene-title"><?= htmlspecialchars($scene_title ?? '') ?></h2>
            <?php if (!empty($scene_text)): ?><p class="scene-text"><?= htmlspecialchars($scene_text) ?></p><?php endif; ?>
            <?php if ($scene_points): ?>
                <ul class="scene-points">
                    <?php foreach ($scene_points as [$icon, $label]): ?><li><i class="fa-solid <?= $icon ?>"></i><?= htmlspecialchars($label) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>

    <!-- ===== Formulaire ===== -->
    <main class="auth-panel">
        <div class="auth-inner">
            <div class="auth-top">
                <a href="/"><i class="fa-solid fa-arrow-left" style="margin-right:6px"></i>Retour au site</a>
                <?php if ($auth_alt): ?><span><?= htmlspecialchars($auth_alt['text']) ?> <a href="<?= htmlspecialchars($auth_alt['href']) ?>"><?= htmlspecialchars($auth_alt['label']) ?></a></span><?php endif; ?>
            </div>
            <div class="auth-card">
