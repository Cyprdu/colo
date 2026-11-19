<?php
// marketing.php : Marketing & visibilité. L'argumentaire, l'offre de boosts, le solde de points et les séjours à booster.
require_once 'api/config.php';
require_once 'api/marketing_lib.php';
require_once 'api/finance_lib.php';
require_once 'api/gains_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

$userId = (int)$_SESSION['user']['id'];
if (isset($_GET['id'])) fin_orga_request($pdo, 'id'); // ancienne URL numérique : 404 (admin : redirection vers le jeton)
$orgaId = !empty($_GET['o']) ? fin_orga_from_tok($pdo, (string)$_GET['o'], $userId) : 0;
mk_ensure($pdo); fin_ensure($pdo);

$st = $pdo->prepare("SELECT id, nom, solde_points, portefeuille, logo_url FROM organisateurs WHERE user_id = ? ORDER BY nom ASC");
$st->execute([$userId]);
$orgas = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$orgaId && count($orgas) === 1) { header('Location: marketing.php?o=' . fin_orga_tok((int)$orgas[0]['id'])); exit; }

$orga = null;
foreach ($orgas as $o) if ((int)$o['id'] === $orgaId) $orga = $o;
if ((!empty($_GET['o']) || $orgaId) && !$orga) { header('Location: marketing.php'); exit; }

$camps = []; $tot = ['impressions' => 0, 'clics' => 0, 'inscriptions' => 0, 'points' => 0]; $avgPrice = 0.0; $walletCents = 0;
if ($orga) {
    $camps = mk_camp_stats($pdo, $orgaId, 30);
    foreach ($camps as $c) { $tot['impressions'] += $c['impressions']; $tot['clics'] += $c['clics']; $tot['inscriptions'] += $c['inscriptions']; $tot['points'] += $c['points_depenses']; }
    $q = $pdo->prepare("SELECT AVG(i.montant_paye) FROM inscriptions i JOIN camps c ON c.id = i.camp_id WHERE c.organisateur_id = ? AND i.statut_paiement = 'PAYE' AND i.montant_paye > 0");
    $q->execute([$orgaId]);
    $avgPrice = (float)$q->fetchColumn();
    if ($avgPrice <= 0) { $q = $pdo->prepare("SELECT AVG(prix) FROM camps WHERE organisateur_id = ? AND prix > 0"); $q->execute([$orgaId]); $avgPrice = (float)$q->fetchColumn(); }
    $walletCents = fin_points_budget_cents($pdo, $orgaId);
}
$proof = mk_platform_proof($pdo);
$boosts = mk_boosts();
$net = $avgPrice - fin_commission_cents((int)round($avgPrice * 100)) / 100;
function mk_money($v) { return number_format($v, 2, ',', ' ') . ' €'; }

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
?>
<title>Marketing et visibilité - moncamp.fr</title>
<link rel="stylesheet" href="assets/mk.css?v=1">

<main class="mk-wrap">
    <a href="profile.php" class="mk-back"><?= mk_icon('back', 16) ?> Tableau de bord</a>

    <section class="mk-hero" style="padding:32px 30px">
        <h1>Remplissez vos séjours plus vite</h1>
        <p>Mettez votre séjour en tête des résultats, à partir de 1 €. Vous voyez ce que chaque euro rapporte.</p>
        <div class="mk-actions">
            <?php if ($orga): ?>
                <a href="#sejours" class="ds-btn mk-btn-inv"><?= mk_icon('star', 18) ?> Booster un séjour</a>
                <button type="button" class="ds-btn mk-btn-ghost" onclick="mkOpen()"><?= mk_icon('wallet', 18) ?> Acheter des points</button>
            <?php else: ?>
                <a href="#organismes" class="ds-btn mk-btn-inv">Choisir mon organisme</a>
            <?php endif; ?>
        </div>
    </section>

    <?php if (isset($_GET['success'])): ?>
        <div class="ds-alert-ok" style="margin-top:18px"><b>Paiement reçu.</b> Vos points apparaissent dans votre solde dans quelques instants (le temps que Stripe nous confirme le paiement).</div>
    <?php endif; ?>

    <?php if (!$orga): ?>
    <section class="mk-section" id="organismes">
        <h2>Pour quel organisme ?</h2>
        <div class="mk-grid mk-g2" style="margin-top:16px">
            <?php foreach ($orgas as $o): ?>
                <a class="ds-tile" href="marketing.php?o=<?= htmlspecialchars(fin_orga_tok((int)$o['id'])) ?>">
                    <span class="ds-tile-ico"><?php if (!empty($o['logo_url'])): ?><img src="<?= htmlspecialchars($o['logo_url']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:14px"><?php else: echo mk_icon('users'); endif; ?></span>
                    <span style="flex:1;min-width:0"><b class="block text-[#26343b]" style="overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($o['nom']) ?></b><span class="mk-note"><?= number_format((int)$o['solde_points'], 0, ',', ' ') ?> points</span></span>
                    <?= mk_icon('arrow', 18) ?>
                </a>
            <?php endforeach; ?>
            <?php if (!$orgas): ?><p class="mk-note">Vous n'avez pas encore d'organisme. <a class="underline" href="create_organisateur.php">Créer un organisme</a></p><?php endif; ?>
        </div>
    </section>
    <?php else: ?>

    <section class="mk-section" style="margin-top:28px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <h2 class="text-[22px] font-extrabold text-[#26343b]"><?= htmlspecialchars($orga['nom']) ?></h2>
            <?php if (count($orgas) > 1): ?><a class="mk-note underline" href="marketing.php">Changer d'organisme</a><?php endif; ?>
        </div>
        <div class="mk-grid mk-g4" style="margin-top:14px">
            <div class="mk-stat dark"><div class="k">Solde de points</div><div class="v"><?= number_format((int)$orga['solde_points'], 0, ',', ' ') ?></div><div class="s">= <?= mk_money((int)$orga['solde_points'] * MK_POINT_EUR) ?> de visibilité</div></div>
            <div class="mk-stat"><div class="k">Impressions (30 j)</div><div class="v"><?= number_format($tot['impressions'], 0, ',', ' ') ?></div><div class="s">fois où vos séjours ont été affichés</div></div>
            <div class="mk-stat"><div class="k">Visites (30 j)</div><div class="v"><?= number_format($tot['clics'], 0, ',', ' ') ?></div><div class="s"><?= $tot['impressions'] > 0 ? number_format($tot['clics'] / $tot['impressions'] * 100, 1, ',', ' ') . ' % des impressions' : 'pas encore de données' ?></div></div>
            <div class="mk-stat"><div class="k">Inscriptions (30 j)</div><div class="v"><?= number_format($tot['inscriptions'], 0, ',', ' ') ?></div><div class="s"><?= $tot['points'] > 0 ? number_format($tot['points'], 0, ',', ' ') . ' pts investis' : 'aucun boost sur la période' ?></div></div>
        </div>
    </section>
    <?php endif; ?>

    <section class="mk-section">
        <h2>Choisissez votre formule</h2>
        <p class="lead">Payez en euros, sans abonnement. Appuyez sur « ? » pour le détail.</p>
        <div style="margin-top:20px"><?php $mkCamp = null; require 'partials/mk_offers.php'; ?></div>
    </section>

    <section class="mk-section">
        <h2>Ce que verront les familles</h2>
        <div style="margin-top:16px"><?php $mkPreviewName = $camps[0]['nom'] ?? null; require 'partials/mk_preview.php'; ?></div>
    </section>

    <section class="mk-section">
        <h2>Un exemple concret</h2>
        <?php $ex = ($orga && $net > 0) ? $net : 400; $exCost = $boosts['vedette']['cost'] * MK_POINT_EUR; ?>
        <div class="mk-sum" style="margin-top:14px">
            <div><b><?= mk_money($exCost) ?></b><span>« À la une » pendant 7 jours</span></div>
            <div><b><?= mk_money($ex) ?></b><span>net par inscription<?= ($orga && $net > 0) ? ' (prix moyen chez vous)' : ' pour un séjour à 450 €' ?></span></div>
            <div><b>1 inscription de plus</b><span>rembourse <?= number_format($ex / $exCost, 0, ',', ' ') ?> fois la mise</span></div>
        </div>
        <p class="mk-note" style="margin-top:8px">Calcul indicatif, pas une promesse : <?= $proof ? 'sur moncamp.fr, les séjours boostés reçoivent ' . number_format($proof['multiplicateur'], 1, ',', ' ') . ' fois plus de visites par affichage (30 jours).' : 'vous suivez l\'effet réel dans vos statistiques.' ?></p>
    </section>

    <?php if ($orga): ?>
    <section class="mk-section" id="sejours">
        <div style="display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap">
            <div><h2>Vos séjours</h2><p class="lead">Choisissez le séjour à mettre en avant.</p></div>
            <a class="ds-btn ds-btn-light mk-btn-sm" href="marketing_dashboard.php?o=<?= htmlspecialchars(fin_orga_tok((int)$orga['id'])) ?>"><?= mk_icon('chart', 16) ?> Statistiques détaillées</a>
        </div>
        <?php if (!$camps): ?>
            <p class="mk-note" style="margin-top:14px">Aucun séjour pour le moment. <a class="underline" href="create_camp.php">Publier un séjour</a></p>
        <?php else: ?>
        <div class="mk-table-wrap" style="margin-top:16px">
            <table class="mk-table ds-cards">
                <thead><tr><th>Séjour</th><th>Départ</th><th class="num">Impr.</th><th class="num">Visites</th><th class="num">Inscr.</th><th>Boosts actifs</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($camps as $c): $past = $c['date_debut'] && strtotime($c['date_debut']) < strtotime('today'); ?>
                    <tr>
                        <td><b><?= htmlspecialchars($c['nom']) ?></b><div class="mk-note"><?= htmlspecialchars($c['ville'] ?? '') ?></div></td>
                        <td><?= $c['date_debut'] ? date('d/m/Y', strtotime($c['date_debut'])) : '-' ?></td>
                        <td class="num"><?= number_format($c['impressions'], 0, ',', ' ') ?></td>
                        <td class="num"><?= number_format($c['clics'], 0, ',', ' ') ?></td>
                        <td class="num"><?= $c['inscriptions'] ?></td>
                        <td>
                            <?php if ($c['vedette_actif']): ?><span class="mk-pill ok">À la une jusqu'au <?= date('d/m', strtotime($c['boost_vedette_fin'])) ?></span> <?php endif; ?>
                            <?php if ($c['urgence_actif']): ?><span class="mk-pill warn">Dernière minute</span> <?php endif; ?>
                            <?php if ($c['bump_actif']): ?><span class="mk-pill info">Remontée</span> <?php endif; ?>
                            <?php if (!$c['vedette_actif'] && !$c['urgence_actif'] && !$c['bump_actif']): ?><span class="mk-note">Aucun</span><?php endif; ?>
                        </td>
                        <td class="num"><?php if (!$past && !empty($c['valide'])): ?><a class="ds-btn mk-btn-sm" href="boost.php?t=<?= urlencode($c['token']) ?>">Booster</a><?php else: ?><span class="mk-note"><?= $past ? 'Terminé' : 'Non publié' ?></span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php $gh = $orga ? gains_history($pdo, $orgaId, 8) : []; if ($gh): ?>
    <section class="mk-section">
        <h2>Anciens paiements avec mes gains</h2>
        <div class="mk-table-wrap" style="margin-top:14px"><table class="mk-table ds-cards" style="min-width:520px">
            <thead><tr><th>Date</th><th>Achat</th><th class="num">Montant</th><th class="num">Solde après</th><th>Réf.</th></tr></thead><tbody>
            <?php foreach ($gh as $g): ?><tr><td><?= date('d/m/Y H:i', strtotime($g['created_at'])) ?></td><td><?= htmlspecialchars($g['libelle']) ?><?= $g['statut'] === 'ok' ? '' : ' <span class="mk-pill bad">Refusé : solde</span>' ?></td><td class="num"><?= $g['statut'] === 'ok' ? '- ' . mk_money($g['montant_cents'] / 100) : '-' ?></td><td class="num"><?= mk_money($g['solde_apres_cents'] / 100) ?></td><td>G-<?= (int)$g['id'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
    </section>
    <?php endif; ?>

    <section class="mk-section mk-faq">
        <h2>Questions fréquentes</h2>
        <div style="margin-top:10px">
            <details><summary>Comment le classement fonctionne-t-il ?</summary><p>La pertinence passe d'abord : recherche, âge, dates, prix et lieu filtrent les séjours, un boost n'en ajoute jamais un hors sujet. Chaque séjour a un score (places restantes, départ proche, taux de visite, nouveauté) que le boost multiplie : +55 % « À la une », +20 % « Dernière minute », jusqu'à +50 % qui s'estompe pour la Remontée. Une légère rotation évite que les mêmes séjours occupent toujours le haut de page. Les séjours à la une portent la mention « Sponsorisé ».</p></details>
            <details><summary>Les points expirent-ils ?</summary><p>Non. Ils restent sur le solde de l'organisme et servent pour tous ses séjours.</p></details>
            <details><summary>Et si le séjour est complet ?</summary><p>Il est automatiquement rétrogradé dans la recherche, même boosté.</p></details>
            <details><summary>Puis-je être remboursé d'un boost ?</summary><p>Un boost activé ne peut pas être annulé. Les points non dépensés restent disponibles. <a class="underline" href="contact.php">Une question ?</a></p></details>
        </div>
    </section>
</main>

<?php if ($orga) { $mkOrgaId = (int)$orga['id']; $mkWalletCents = $walletCents; require 'partials/mk_shop.php'; } ?>
<?php require_once 'partials/footer.php'; ?>
