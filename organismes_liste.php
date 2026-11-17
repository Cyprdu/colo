<?php
// organismes_liste.php : annuaire public des organismes (adresse : /organisme).
// Ne liste que les organismes ayant au moins un séjour public à venir. Aucune coordonnée privée n'est exposée.
require_once 'api/config.php';
require_once 'api/organismes.php';
if (session_status() === PHP_SESSION_NONE) session_start();
orga_ensure($pdo);

$items = [];
try {
    $rows = $pdo->query("SELECT o.id, o.nom, o.slug, o.logo_url, c.ville, c.type, c.theme
        FROM organisateurs o JOIN camps c ON c.organisateur_id = o.id
        WHERE o.slug IS NOT NULL AND o.slug <> '' AND c.valide = 1 AND c.en_attente = 0 AND c.refuse = 0
          AND COALESCE(c.supprime, 0) = 0 AND COALESCE(c.prive, 0) = 0 AND COALESCE(c.date_fin, c.date_debut) >= CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $k = (int)$r['id'];
        if (!isset($items[$k])) $items[$k] = ['nom' => $r['nom'], 'slug' => $r['slug'], 'logo' => $r['logo_url'], 'n' => 0, 'villes' => [], 'cats' => []];
        $items[$k]['n']++;
        $v = trim((string)$r['ville']); if ($v !== '') $items[$k]['villes'][$v] = ($items[$k]['villes'][$v] ?? 0) + 1;
        $c = trim((string)($r['type'] ?: $r['theme'])); if ($c !== '') $items[$k]['cats'][$c] = ($items[$k]['cats'][$c] ?? 0) + 1;
    }
    usort($items, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcasecmp($a['nom'], $b['nom']); });
} catch (Throwable $e) { error_log('organismes_liste: ' . $e->getMessage()); }

function ol_top(array $m, int $n): string { arsort($m); return implode(', ', array_slice(array_keys($m), 0, $n)); }
function ol_logo($u) { return str_replace(chr(92), '/', trim((string)$u)); }

$ads_enabled = false;
$body_class = 'bg-white';
$page_title = 'Organismes de colonies de vacances et séjours pour enfants - ColoMap';
$base_href = '/';
require_once 'partials/header.php';
?>
<meta name="description" content="Annuaire des organismes qui proposent des colonies de vacances, camps et stages pour enfants et adolescents sur ColoMap : séjours, villes et thématiques.">
<link rel="canonical" href="https://moncamp.fr/organisme">
<main class="max-w-[1100px] mx-auto px-5 sm:px-8 py-10">
    <p class="text-[12px] font-bold uppercase tracking-widest text-[#3d6a8e]">Annuaire</p>
    <h1 class="text-[30px] md:text-[38px] font-extrabold text-[#26343b] leading-tight">Les organismes de séjours</h1>
    <p class="mt-2 text-[#4b5964]"><?= count($items) ?> organisme<?= count($items) > 1 ? 's' : '' ?> avec des séjours à venir.</p>

    <div class="mt-6 flex flex-col sm:flex-row gap-3">
        <input id="ol-q" type="search" placeholder="Rechercher un organisme, une ville, une thématique" aria-label="Rechercher un organisme" class="ds-input flex-1">
        <select id="ol-sort" aria-label="Trier" class="ds-select sm:w-56"><option value="n">Le plus de séjours</option><option value="nom">Ordre alphabétique</option></select>
    </div>

    <div id="ol-grid" class="mt-6 grid gap-4" style="grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">
    <?php foreach ($items as $o): $logo = ol_logo($o['logo']); ?>
        <a href="organisme/<?= htmlspecialchars($o['slug']) ?>" class="ol-card ds-tile" style="align-items:flex-start;flex-direction:column;gap:10px"
           data-nom="<?= htmlspecialchars(mb_strtolower($o['nom'])) ?>" data-n="<?= (int)$o['n'] ?>"
           data-txt="<?= htmlspecialchars(mb_strtolower($o['nom'] . ' ' . implode(' ', array_keys($o['villes'])) . ' ' . implode(' ', array_keys($o['cats'])))) ?>">
            <span style="display:flex;align-items:center;gap:12px;width:100%">
                <span class="ds-tile-ico" style="overflow:hidden"><?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover" onerror="this.remove()"><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr($o['nom'], 0, 1))) ?><?php endif; ?></span>
                <b class="text-[#26343b]" style="min-width:0;overflow-wrap:anywhere"><?= htmlspecialchars($o['nom']) ?></b>
            </span>
            <span class="ds-badge"><?= (int)$o['n'] ?> séjour<?= $o['n'] > 1 ? 's' : '' ?> à venir</span>
            <?php if ($o['villes']): ?><span class="text-[13px] text-[#6b7780]">Villes : <?= htmlspecialchars(ol_top($o['villes'], 3)) ?></span><?php endif; ?>
            <?php if ($o['cats']): ?><span class="text-[13px] text-[#6b7780]">Catégories : <?= htmlspecialchars(ol_top($o['cats'], 3)) ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
    </div>
    <p id="ol-none" class="mt-8 text-center text-[#6b7780]" <?= $items ? 'hidden' : '' ?>>Aucun organisme à afficher pour le moment.</p>
</main>
<script>
(function () {
    var grid = document.getElementById('ol-grid'), q = document.getElementById('ol-q'), s = document.getElementById('ol-sort'), none = document.getElementById('ol-none');
    function run() {
        var t = q.value.trim().toLowerCase(), cards = [].slice.call(grid.children), shown = 0;
        cards.sort(function (a, b) { return s.value === 'nom' ? a.dataset.nom.localeCompare(b.dataset.nom, 'fr') : (b.dataset.n - a.dataset.n) || a.dataset.nom.localeCompare(b.dataset.nom, 'fr'); });
        cards.forEach(function (c) { var ok = !t || c.dataset.txt.indexOf(t) !== -1; c.hidden = !ok; if (ok) shown++; grid.appendChild(c); });
        none.hidden = shown > 0;
    }
    q.addEventListener('input', run); s.addEventListener('change', run);
})();
</script>
<?php require_once 'partials/footer.php'; ?>
