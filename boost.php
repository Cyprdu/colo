<?php
// boost.php?t=<jeton du séjour> : booster un séjour (points), avec les résultats déjà obtenus.
require_once 'api/config.php';
require_once 'api/marketing_lib.php';
require_once 'api/finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

$token = (string)($_GET['t'] ?? '');
if ($token === '') { header('Location: marketing.php'); exit; }
mk_ensure($pdo); fin_ensure($pdo);

$st = $pdo->prepare("SELECT c.id, c.token, c.nom, c.ville, c.date_debut, c.date_fin, c.valide, c.refuse, c.supprime, c.boost_vedette_fin, c.boost_urgence_fin, c.date_bump,
                            o.id AS orga_id, o.nom AS orga_nom, o.solde_points
                     FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id WHERE c.token = ? AND o.user_id = ?");
$st->execute([$token, $_SESSION['user']['id']]);
$camp = $st->fetch(PDO::FETCH_ASSOC);
if (!$camp) { header('Location: marketing.php'); exit; }

$stats = null;
foreach (mk_camp_stats($pdo, (int)$camp['orga_id'], 30) as $s) if ((int)$s['id'] === (int)$camp['id']) $stats = $s;
$solde = (int)$camp['solde_points'];
$walletCents = fin_points_budget_cents($pdo, (int)$camp['orga_id']);
$boosts = mk_boosts();
$now = time();
$published = !empty($camp['valide']) && empty($camp['refuse']) && empty($camp['supprime']);
$started = $camp['date_debut'] && strtotime($camp['date_debut']) < strtotime('today');
$daysToStart = $camp['date_debut'] ? (strtotime($camp['date_debut']) - $now) / 86400 : 999;
$vedetteOn = !empty($camp['boost_vedette_fin']) && strtotime($camp['boost_vedette_fin']) > $now;
$urgenceOn = !empty($camp['boost_urgence_fin']) && strtotime($camp['boost_urgence_fin']) > $now;
$bumpOn = !empty($camp['date_bump']) && strtotime($camp['date_bump']) > $now - 72 * 3600;
$blocked = !$published ? "Ce séjour n'est pas publié." : ($started ? 'Ce séjour a déjà commencé.' : null);

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
?>
<title>Booster - <?= htmlspecialchars($camp['nom']) ?></title>
<link rel="stylesheet" href="assets/mk.css?v=1">

<main class="mk-wrap">
    <a href="marketing.php?id=<?= (int)$camp['orga_id'] ?>" class="mk-back"><?= mk_icon('back', 16) ?> Marketing</a>

    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap">
        <div>
            <p class="ds-eyebrow">Mettre en avant</p>
            <h1 class="ds-h1"><?= htmlspecialchars($camp['nom']) ?></h1>
            <p class="ds-lead"><?= htmlspecialchars($camp['ville'] ?? '') ?><?= $camp['date_debut'] ? ' · départ le ' . date('d/m/Y', strtotime($camp['date_debut'])) : '' ?></p>
        </div>
        <div class="mk-stat dark" style="min-width:230px">
            <div class="k">Solde de points · <?= htmlspecialchars($camp['orga_nom']) ?></div>
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px">
                <div class="v" id="soldeVal"><?= number_format($solde, 0, ',', ' ') ?> <span style="font-size:14px;font-weight:600">pts</span></div>
                <button type="button" class="ds-btn mk-btn-inv mk-btn-sm" onclick="mkOpen()">Recharger</button>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?><div class="ds-alert-ok" style="margin-top:18px"><b>Paiement reçu.</b> Vos points arrivent dans quelques instants ; rechargez la page si le solde n'a pas bougé.</div><?php endif; ?>
    <?php if ($blocked): ?><div class="ds-alert-err" style="margin-top:18px"><?= htmlspecialchars($blocked) ?> Il ne peut pas être boosté.</div><?php endif; ?>

    <?php if ($stats): ?>
    <section class="mk-section" style="margin-top:28px">
        <h2 class="text-[18px] font-extrabold text-[#26343b]">Résultats des 30 derniers jours</h2>
        <div class="mk-grid mk-g4" style="margin-top:12px">
            <div class="mk-stat"><div class="k">Impressions</div><div class="v"><?= number_format($stats['impressions'], 0, ',', ' ') ?></div></div>
            <div class="mk-stat"><div class="k">Visites</div><div class="v"><?= number_format($stats['clics'], 0, ',', ' ') ?></div><div class="s"><?= $stats['ctr'] !== null ? number_format($stats['ctr'] * 100, 1, ',', ' ') . ' % des impressions' : '-' ?></div></div>
            <div class="mk-stat"><div class="k">Inscriptions</div><div class="v"><?= $stats['inscriptions'] ?></div><div class="s"><?= $stats['inscriptions_boost'] ?> pendant un boost</div></div>
            <div class="mk-stat"><div class="k">Coût par inscription</div><div class="v"><?= $stats['cout_par_inscription'] !== null ? number_format($stats['cout_par_inscription'], 2, ',', ' ') . ' €' : '-' ?></div><div class="s"><?= $stats['points_depenses'] ?> pts investis</div></div>
        </div>
    </section>
    <?php endif; ?>

    <section class="mk-section" style="margin-top:28px">
        <div id="boostFeedback" class="ds-alert-err" style="display:none;margin-bottom:16px" role="alert"></div>
        <?php $mkCamp = ['blocked' => $blocked, 'bumpOn' => $bumpOn, 'vedetteOn' => $vedetteOn, 'urgenceOn' => $urgenceOn, 'vedetteFin' => $camp['boost_vedette_fin'], 'urgenceFin' => $camp['boost_urgence_fin'], 'urgOk' => $daysToStart <= 45]; require 'partials/mk_offers.php'; ?>
        <div style="margin-top:28px"><p class="ds-eyebrow" style="margin-bottom:10px">Ce que verront les familles</p><?php $mkPreviewName = $camp['nom']; require 'partials/mk_preview.php'; ?></div>
        <p class="mk-note" style="margin-top:14px">Le boost augmente le poids du séjour dans le classement sans remplacer la pertinence. Un séjour complet est rétrogradé. Les séjours à la une portent la mention « Sponsorisé ». <a class="underline" href="marketing.php?id=<?= (int)$camp['orga_id'] ?>#classement">En savoir plus</a></p>
    </section>
</main>

<script>
async function mkBoost(type, cost, btn) {
    var solde = <?= $solde ?>;
    if (solde < cost) { mkToast('Solde insuffisant : rechargez vos points.', true); mkOpen(); return; }
    if (!(await ColoUI.confirm('Dépenser ' + cost + ' points (' + (cost / 10).toFixed(2).replace('.', ',') + ' €) pour ce boost ?', { confirmLabel: 'Dépenser' }))) return;
    btn.disabled = true;
    mkPost('api/apply_boost.php', { token: <?= json_encode($token) ?>, type: type }).then(function (d) {
        if (d.success) { mkToast('Boost activé.'); setTimeout(function () { location.reload(); }, 800); }
        else { var f = document.getElementById('boostFeedback'); f.textContent = d.message || 'Une erreur est survenue.'; f.style.display = 'block'; btn.disabled = false; }
    }).catch(function () { mkToast('Erreur de connexion.', true); btn.disabled = false; });
}
</script>
<?php $mkOrgaId = (int)$camp['orga_id']; $mkCampToken = $token; $mkWalletCents = $walletCents; require 'partials/mk_shop.php'; ?>
<?php require_once 'partials/footer.php'; ?>
