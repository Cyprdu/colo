<?php
// organisme.php : page publique d'un organisme (adresse : /organisme/<nom-de-l-organisme>)
// Affiche l'organisme et tous ses séjours à venir. Aucune coordonnée privée n'est montrée.
require_once 'api/config.php';
require_once 'api/organismes.php';
require_once 'api/sessions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

orga_ensure($pdo);
$slug = strtolower((string)($_GET['slug'] ?? ''));
$orga = null; $camps = []; $passes = 0;
if (preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
    try {
        $st = $pdo->prepare("SELECT * FROM organisateurs WHERE slug = ?");
        $st->execute([$slug]);
        $orga = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($orga) {
            $priceExpr = "COALESCE((SELECT MIN(t.prix) FROM camps_tarifs ct JOIN tarifs t ON ct.tarif_id = t.id WHERE ct.camp_id = c.id), c.prix)";
            $base = "FROM camps c WHERE c.organisateur_id = ? AND c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0 AND COALESCE(c.supprime, 0) = 0 AND COALESCE(c.prive, 0) = 0";
            $st = $pdo->prepare("SELECT c.id, c.token, c.nom, c.ville, c.image_url, c.date_debut, c.date_fin, c.age_min, c.age_max, c.groupe_id, $priceExpr AS prix_affiche
                                 $base AND COALESCE(c.date_fin, c.date_debut) >= CURDATE() ORDER BY c.date_debut ASC");
            $st->execute([(int)$orga['id']]);
            $camps = collapseSessions($st->fetchAll(PDO::FETCH_ASSOC));   // un séjour à plusieurs sessions = une seule carte
            $st = $pdo->prepare("SELECT COUNT(*) $base AND COALESCE(c.date_fin, c.date_debut) < CURDATE()");
            $st->execute([(int)$orga['id']]);
            $passes = (int)$st->fetchColumn();
        }
    } catch (Throwable $e) { error_log('organisme: ' . $e->getMessage()); $orga = null; }
}
if (!$orga) http_response_code(404);

function og_media($u, $dir = '') {
    $u = trim((string)$u); if ($u === '') return '';
    $u = str_replace('\\', '/', $u);
    return (preg_match('#^(https?:)?//#', $u) || strpos($u, 'uploads/') === 0) ? $u : $dir . ltrim($u, '/');
}
function og_dates($a, $b): string {
    [$d1, $d2] = normalizeSessionDates($a, $b);
    $m = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $f = function (DateTime $d) use ($m) { return $d->format('j') . ' ' . $m[(int)$d->format('n') - 1]; };
    return $f($d1) . ' – ' . $f($d2) . ' ' . $d2->format('Y');
}

$nom = $orga ? (string)$orga['nom'] : 'Organisme introuvable';
$logo = $orga ? og_media($orga['logo_url']) : '';
$web = $orga ? trim((string)($orga['web'] ?? '')) : '';
if ($web !== '' && !preg_match('#^https?://#i', $web)) $web = 'https://' . $web;
$webOk = $web !== '' && filter_var($web, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $web);
$desc = $orga ? trim((string)($orga['description'] ?? '')) : '';

$ads_enabled = false;
$body_class = 'bg-white';
$base_href = '/';   // la page est servie sous /organisme/… : les liens relatifs de l'en-tête restent valides
require_once 'partials/header.php';
?>
<title><?= htmlspecialchars($nom) ?> : séjours et colonies de vacances - ColoMap</title>
<?php if ($orga): ?>
<meta name="description" content="<?= htmlspecialchars('Tous les séjours de ' . $nom . ' sur ColoMap : dates, tarifs et réservation en ligne.') ?>">
<link rel="canonical" href="https://moncamp.fr/organisme/<?= htmlspecialchars($orga['slug']) ?>">
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $nom,
    'url' => 'https://moncamp.fr/organisme/' . $orga['slug']] + ($logo ? ['logo' => (preg_match('#^https?://#', $logo) ? $logo : 'https://moncamp.fr/' . $logo)] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php else: ?><meta name="robots" content="noindex"><?php endif; ?>

<style>
    .og-card { display:flex; flex-direction:column; min-width:0; }
    .og-media { position:relative; width:100%; aspect-ratio:1/1; border-radius:18px; overflow:hidden; background:#e5e7eb; display:block; }
    .og-media img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
    .og-card:hover .og-media img { transform:scale(1.04); }
    .og-age { position:absolute; bottom:10px; left:10px; background:rgba(38,52,59,.82); color:#fff; font-size:12px; font-weight:600; padding:5px 10px; border-radius:9999px; line-height:1; }
    .og-title { margin-top:12px; font-size:15.5px; font-weight:600; line-height:1.3; color:#26343b; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .og-meta { font-size:13px; color:#6b7780; line-height:19px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .og-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:26px 16px; }
    @media (min-width:768px) { .og-grid { grid-template-columns:repeat(3, minmax(0,1fr)); gap:30px 20px; } }
    @media (min-width:1100px) { .og-grid { grid-template-columns:repeat(4, minmax(0,1fr)); } }
    @media (prefers-reduced-motion: reduce) { .og-media img { transition:none; } }
</style>

<main class="max-w-[1100px] mx-auto px-5 sm:px-8 py-10">
<?php if (!$orga): ?>
    <div class="text-center py-24">
        <h1 class="text-[28px] font-extrabold text-[#26343b]">Organisme introuvable</h1>
        <p class="mt-2 text-[#6b7780]">Cette adresse ne correspond à aucun organisme.</p>
        <a href="recherche" class="mt-6 inline-block px-6 py-3 rounded-xl bg-[#2f3d45] text-white font-bold hover:bg-[#1f2a30] transition">Rechercher un séjour</a>
    </div>
<?php else: ?>
    <nav class="text-[13px] text-[#6b7780]" aria-label="Fil d'Ariane"><a class="hover:underline" href="./">Accueil</a> <span aria-hidden="true">/</span> <a class="hover:underline" href="recherche">Séjours</a> <span aria-hidden="true">/</span> <span class="text-[#26343b] font-semibold"><?= htmlspecialchars($nom) ?></span></nav>

    <header class="mt-6 flex flex-col sm:flex-row sm:items-center gap-5">
        <span class="w-24 h-24 flex-none rounded-3xl bg-[#eef2f4] border border-[#e5e7eb] overflow-hidden flex items-center justify-center text-[#3a4852] font-extrabold text-[36px]">
            <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="Logo de <?= htmlspecialchars($nom) ?>" class="w-full h-full object-cover" onerror="this.remove()"><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr($nom, 0, 1))) ?><?php endif; ?>
        </span>
        <div class="min-w-0">
            <p class="text-[12px] font-bold uppercase tracking-widest text-[#3d6a8e]">Organisme</p>
            <h1 class="text-[30px] md:text-[38px] font-extrabold text-[#26343b] leading-tight"><?= htmlspecialchars($nom) ?></h1>
            <p class="mt-1 text-[15px] text-[#4b5964]"><?= count($camps) ?> séjour<?= count($camps) > 1 ? 's' : '' ?> à venir<?= $passes ? ' · ' . $passes . ' séjour' . ($passes > 1 ? 's' : '') . ' passé' . ($passes > 1 ? 's' : '') : '' ?></p>
            <?php if ($webOk): ?><a href="<?= htmlspecialchars($web) ?>" target="_blank" rel="noopener nofollow" class="mt-2 inline-flex items-center gap-2 text-[14px] font-semibold text-[#3d6a8e] hover:underline">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18" stroke="currentColor" stroke-width="2"/></svg>Site de l'organisme</a><?php endif; ?>
        </div>
    </header>
    <?php if ($desc !== ''): ?><p class="mt-6 max-w-3xl text-[15.5px] leading-relaxed text-[#3b4a54]"><?= nl2br(htmlspecialchars($desc)) ?></p><?php endif; ?>

    <h2 class="mt-10 text-[22px] font-extrabold text-[#26343b]">Les séjours de <?= htmlspecialchars($nom) ?></h2>
    <?php if (!$camps): ?>
        <div class="mt-6 rounded-3xl bg-[#f8f5ea] px-6 py-12 text-center">
            <p class="text-[18px] font-extrabold text-[#26343b]">Aucun séjour à venir pour le moment</p>
            <p class="mt-1 text-[#6b7780]">Revenez bientôt, ou découvrez d'autres séjours.</p>
            <a href="recherche" class="mt-5 inline-block px-6 py-3 rounded-xl bg-[#2f3d45] text-white font-bold hover:bg-[#1f2a30] transition">Voir tous les séjours</a>
        </div>
    <?php else: ?>
        <div class="og-grid mt-6">
        <?php foreach ($camps as $c):
            $img = og_media($c['image_url'], 'uploads/camps/');
            $n = (int)($c['sessions_count'] ?? 1);
            $dates = $n > 1 ? $n . ' sessions' : og_dates($c['date_debut'], $c['date_fin']);
            $age = ($c['age_min'] !== null && $c['age_max'] !== null) ? (int)$c['age_min'] . '–' . (int)$c['age_max'] . ' ans' : '';
            $url = 'camp_details?t=' . urlencode($c['token']);
        ?>
            <article class="og-card">
                <a class="og-media" href="<?= htmlspecialchars($url) ?>" aria-label="<?= htmlspecialchars($c['nom']) ?>">
                    <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy" decoding="async" onerror="this.remove()"><?php endif; ?>
                    <?php if ($age): ?><span class="og-age"><?= htmlspecialchars($age) ?></span><?php endif; ?>
                </a>
                <a href="<?= htmlspecialchars($url) ?>" class="block">
                    <h3 class="og-title" title="<?= htmlspecialchars($c['nom']) ?>"><?= htmlspecialchars($c['nom']) ?></h3>
                    <?php if ((float)$c['prix_affiche'] > 0): ?><p class="mt-1 text-[16px] text-[#26343b]">Dès <b class="font-extrabold"><?= rtrim(rtrim(number_format((float)$c['prix_affiche'], 2, ',', ''), '0'), ',') ?> €</b></p><?php endif; ?>
                    <p class="og-meta"><?= htmlspecialchars((string)$c['ville']) ?></p>
                    <p class="og-meta"><?= htmlspecialchars($dates) ?></p>
                </a>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</main>
<?php require_once 'partials/footer.php'; ?>
