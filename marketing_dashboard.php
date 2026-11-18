<?php
// marketing_dashboard.php?id=<organisme>&jours=30 : statistiques marketing par séjour
// (impressions, visites, inscriptions, coût par inscription) + évolution quotidienne + conseils.
require_once 'api/config.php';
require_once 'api/marketing_lib.php';
require_once 'api/search_stats.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: login.php'); exit; }

$orgaId = (int)($_GET['id'] ?? 0);
$jours = in_array((int)($_GET['jours'] ?? 30), [7, 30, 90], true) ? (int)$_GET['jours'] : 30;
mk_ensure($pdo);
$st = $pdo->prepare("SELECT id, nom, solde_points FROM organisateurs WHERE id = ? AND user_id = ?");
$st->execute([$orgaId, $_SESSION['user']['id']]);
$orga = $st->fetch(PDO::FETCH_ASSOC);
if (!$orga) { header('Location: marketing.php'); exit; }

$camps = mk_camp_stats($pdo, $orgaId, $jours);
$termes = sq_termes_organisme($pdo, $orgaId, $jours);          // termes tapés par les familles qui ont mené à vos séjours (anonymes, 3 recherches minimum)
$sansResultat = sq_termes_sans_resultat($pdo, $jours, SQ_TERME_MIN, 6);
$ids = array_map(function ($c) { return (int)$c['id']; }, $camps);

// Évolution quotidienne (tous séjours confondus)
$daily = [];
for ($i = $jours - 1; $i >= 0; $i--) $daily[date('Y-m-d', strtotime("-$i day"))] = ['i' => 0, 'c' => 0];
if ($ids) {
    $in = implode(',', $ids);
    $q = $pdo->query("SELECT jour, SUM(impressions) i, SUM(clics) c FROM camp_stats_jour WHERE camp_id IN ($in) AND jour >= DATE_SUB(CURDATE(), INTERVAL " . ($jours - 1) . " DAY) GROUP BY jour");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($daily[$r['jour']])) $daily[$r['jour']] = ['i' => (int)$r['i'], 'c' => (int)$r['c']];
}
$tot = ['impressions' => 0, 'clics' => 0, 'inscriptions' => 0, 'inscriptions_boost' => 0, 'points' => 0];
foreach ($camps as $c) { $tot['impressions'] += $c['impressions']; $tot['clics'] += $c['clics']; $tot['inscriptions'] += $c['inscriptions']; $tot['inscriptions_boost'] += $c['inscriptions_boost']; $tot['points'] += $c['points_depenses']; }
$coutIns = ($tot['points'] > 0 && $tot['inscriptions_boost'] > 0) ? $tot['points'] * MK_POINT_EUR / $tot['inscriptions_boost'] : null;

// Conseils générés à partir des chiffres de chaque séjour (jamais d'invention : seulement des règles simples)
$tips = [];
foreach ($camps as $c) {
    if (empty($c['valide']) || ($c['date_debut'] && strtotime($c['date_debut']) < strtotime('today'))) continue;
    $n = htmlspecialchars($c['nom']);
    if ($c['impressions'] >= 100 && $c['ctr'] !== null && $c['ctr'] < 0.02) $tips[] = "<b>$n</b> est affiché mais peu ouvert (" . number_format($c['ctr'] * 100, 1, ',', ' ') . " %) : changez la photo de couverture ou le titre.";
    elseif ($c['clics'] >= 20 && $c['inscriptions'] == 0) $tips[] = "<b>$n</b> reçoit des visites mais aucune inscription : vérifiez le prix, le programme et que l'inscription en ligne est ouverte.";
    elseif ($c['impressions'] < 50 && !$c['vedette_actif']) $tips[] = "<b>$n</b> est très peu vu (" . $c['impressions'] . " affichages) : une <a class=\"underline\" href=\"boost.php?t=" . urlencode($c['token']) . "\">Mise à la une</a> le placerait devant les familles.";
}
$tips = array_slice($tips, 0, 4);

// Graphique SVG : barres d'impressions + courbe de visites
$W = 760; $H = 180; $pad = 28; $n = count($daily);
$maxI = max(1, max(array_column($daily, 'i')));
$maxC = max(1, max(array_column($daily, 'c')));
$bw = ($W - 2 * $pad) / max(1, $n);
$bars = ''; $pts = []; $k = 0;
foreach ($daily as $d => $v) {
    $x = $pad + $k * $bw;
    $h = ($H - 2 * $pad) * $v['i'] / $maxI;
    $bars .= '<rect x="' . round($x + 1, 1) . '" y="' . round($H - $pad - $h, 1) . '" width="' . round(max(1, $bw - 2), 1) . '" height="' . round($h, 1) . '" rx="2" fill="#cfd9e0"><title>' . date('d/m', strtotime($d)) . ' : ' . $v['i'] . ' impressions, ' . $v['c'] . ' visites</title></rect>';
    $pts[] = round($x + $bw / 2, 1) . ',' . round($H - $pad - ($H - 2 * $pad) * $v['c'] / $maxC, 1);
    $k++;
}

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
function pct($v) { return $v === null ? '-' : number_format($v * 100, 1, ',', ' ') . ' %'; }
?>
<title>Statistiques marketing - <?= htmlspecialchars($orga['nom']) ?></title>
<link rel="stylesheet" href="assets/mk.css?v=1">

<main class="mk-wrap">
    <a href="marketing.php?id=<?= $orgaId ?>" class="mk-back"><?= mk_icon('back', 16) ?> Marketing</a>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap">
        <div><p class="ds-eyebrow">Statistiques</p><h1 class="ds-h1"><?= htmlspecialchars($orga['nom']) ?></h1><p class="ds-lead">L'effet réel de votre visibilité, séjour par séjour.</p></div>
        <div class="mk-seg" role="tablist" aria-label="Période">
            <?php foreach ([7, 30, 90] as $j): ?><a href="?id=<?= $orgaId ?>&jours=<?= $j ?>" class="<?= $j === $jours ? 'on' : '' ?>"><?= $j ?> jours</a><?php endforeach; ?>
        </div>
    </div>

    <div class="mk-grid mk-g4" style="margin-top:24px">
        <div class="mk-stat"><div class="k">Impressions</div><div class="v"><?= number_format($tot['impressions'], 0, ',', ' ') ?></div><div class="s">affichages dans la recherche et l'accueil</div></div>
        <div class="mk-stat"><div class="k">Visites</div><div class="v"><?= number_format($tot['clics'], 0, ',', ' ') ?></div><div class="s">taux de visite : <?= $tot['impressions'] ? pct($tot['clics'] / $tot['impressions']) : '-' ?></div></div>
        <div class="mk-stat"><div class="k">Inscriptions</div><div class="v"><?= $tot['inscriptions'] ?></div><div class="s">dont <?= $tot['inscriptions_boost'] ?> pendant un boost</div></div>
        <div class="mk-stat dark"><div class="k">Coût par inscription</div><div class="v"><?= $coutIns !== null ? number_format($coutIns, 2, ',', ' ') . ' €' : '-' ?></div><div class="s"><?= $tot['points'] ?> pts investis (<?= number_format($tot['points'] * MK_POINT_EUR, 2, ',', ' ') ?> €)</div></div>
    </div>

    <section class="mk-section" style="margin-top:28px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2 class="text-[18px] font-extrabold text-[#26343b]">Évolution quotidienne</h2>
            <span class="mk-note"><span style="display:inline-block;width:10px;height:10px;background:#cfd9e0;border-radius:2px"></span> impressions &nbsp; <span style="display:inline-block;width:14px;height:3px;background:#2f3d45;border-radius:2px;vertical-align:middle"></span> visites</span></div>
        <div class="ds-card" style="margin-top:12px;padding:12px 14px">
            <svg class="mk-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" role="img" aria-label="Impressions et visites par jour">
                <line x1="<?= $pad ?>" y1="<?= $H - $pad ?>" x2="<?= $W - $pad ?>" y2="<?= $H - $pad ?>" stroke="#e5e7eb"/>
                <?= $bars ?>
                <?php if (count($pts) > 1): ?><polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="#2f3d45" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/><?php endif; ?>
                <text x="<?= $pad ?>" y="<?= $H - 8 ?>"><?= date('d/m', strtotime(array_key_first($daily))) ?></text>
                <text x="<?= $W - $pad ?>" y="<?= $H - 8 ?>" text-anchor="end"><?= date('d/m', strtotime(array_key_last($daily))) ?></text>
                <text x="<?= $pad ?>" y="12">max <?= $maxI ?> imp. / <?= $maxC ?> visites</text>
            </svg>
        </div>
    </section>

    <?php if ($tips): ?>
    <section class="mk-section" style="margin-top:24px"><h2 class="text-[18px] font-extrabold text-[#26343b]">Conseils</h2>
        <div style="display:grid;gap:10px;margin-top:12px"><?php foreach ($tips as $t): ?><div class="mk-tip"><?= mk_icon('info', 20) ?><div><?= $t ?></div></div><?php endforeach; ?></div>
    </section>
    <?php endif; ?>

    <section class="mk-section" style="margin-top:28px">
        <h2 class="text-[18px] font-extrabold text-[#26343b]">Ce que les familles ont tapé pour vous trouver</h2>
        <div class="mk-table-wrap" style="margin-top:12px">
            <table class="mk-table" style="min-width:420px">
                <thead><tr><th>Terme recherché</th><th class="num">Affichages de vos séjours</th><th class="num">Visites</th><th class="num">Taux</th></tr></thead>
                <tbody>
                <?php foreach ($termes as $t): ?>
                    <tr><td><b><?= htmlspecialchars($t['terme']) ?></b></td><td class="num"><?= (int)$t['affichages'] ?></td><td class="num"><?= (int)$t['visites'] ?></td><td class="num"><?= $t['affichages'] > 0 ? pct($t['visites'] / $t['affichages']) : '-' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$termes): ?><tr><td colspan="4" class="mk-note" style="text-align:center;padding:24px">Pas encore assez de recherches pour afficher des termes (un terme n'est montré que s'il a été tapé par au moins <?= SQ_TERME_MIN ?> recherches).</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="mk-note" style="margin-top:12px">Les termes sont agrégés et anonymes : aucun visiteur n'est identifiable. Un terme fréquent qui ne vous amène personne peut devenir un <b>mot-clé</b> de votre séjour (dans « Modifier le séjour »).
            <?php if ($sansResultat): ?> Termes souvent tapés sur moncamp.fr sans résultat : <?= htmlspecialchars(implode(', ', array_column($sansResultat, 'terme'))) ?>.<?php endif; ?></p>
    </section>

    <section class="mk-section" style="margin-top:28px">
        <h2 class="text-[18px] font-extrabold text-[#26343b]">Par séjour</h2>
        <div class="mk-table-wrap" style="margin-top:12px">
            <table class="mk-table" style="min-width:900px">
                <thead><tr><th>Séjour</th><th class="num">Impressions</th><th class="num">Visites</th><th class="num">Taux</th><th class="num">Inscriptions</th><th class="num">Inscr. en boost</th><th class="num">Points</th><th class="num">Coût / inscr.</th><th>Boosts</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($camps as $c): ?>
                    <tr>
                        <td><b><?= htmlspecialchars($c['nom']) ?></b><div class="mk-note"><?= $c['date_debut'] ? date('d/m/Y', strtotime($c['date_debut'])) : '' ?> · <?= (int)$c['inscrits_total'] ?>/<?= (int)$c['quota_global'] ?> inscrits</div>
                            <?php $qt = max(1, (int)$c['quota_global']); ?><div class="mk-meter" style="margin-top:6px;width:120px"><span style="width:<?= min(100, round($c['inscrits_total'] / $qt * 100)) ?>%"></span></div></td>
                        <td class="num"><?= number_format($c['impressions'], 0, ',', ' ') ?></td>
                        <td class="num"><?= number_format($c['clics'], 0, ',', ' ') ?></td>
                        <td class="num"><?= pct($c['ctr']) ?></td>
                        <td class="num"><?= $c['inscriptions'] ?></td>
                        <td class="num"><?= $c['inscriptions_boost'] ?></td>
                        <td class="num"><?= $c['points_depenses'] ?></td>
                        <td class="num"><?= $c['cout_par_inscription'] !== null ? number_format($c['cout_par_inscription'], 2, ',', ' ') . ' €' : '-' ?></td>
                        <td><?php if ($c['vedette_actif']): ?><span class="mk-pill ok">À la une</span> <?php endif; ?><?php if ($c['urgence_actif']): ?><span class="mk-pill warn">Dernière min.</span> <?php endif; ?><?php if ($c['bump_actif']): ?><span class="mk-pill info">Remontée</span><?php endif; ?></td>
                        <td class="num"><?php if (!empty($c['valide']) && (!$c['date_debut'] || strtotime($c['date_debut']) >= strtotime('today'))): ?><a class="ds-btn ds-btn-light mk-btn-sm" href="boost.php?t=<?= urlencode($c['token']) ?>">Booster</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$camps): ?><tr><td colspan="10" class="mk-note" style="text-align:center;padding:30px">Aucun séjour pour le moment.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="mk-note" style="margin-top:12px">Les impressions et visites sont comptées depuis la mise en service du suivi. « Inscriptions en boost » : inscriptions payées pendant la durée d'un boost ; le coût par inscription rapporte les points dépensés (1 point = <?= number_format(MK_POINT_EUR, 2, ',', ' ') ?> €) à ces inscriptions. Les robots d'indexation ne sont pas comptés.</p>
    </section>
</main>
<?php require_once 'partials/footer.php'; ?>
