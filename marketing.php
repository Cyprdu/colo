<?php
// marketing.php : Marketing & visibilité. L'argumentaire, l'offre de boosts, le solde de points et les séjours à booster.
require_once 'api/config.php';
require_once 'api/marketing_lib.php';
require_once 'api/finance_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) { header('Location: index.php'); exit; }

$userId = (int)$_SESSION['user']['id'];
$orgaId = (int)($_GET['id'] ?? 0);
mk_ensure($pdo); fin_ensure($pdo);

$st = $pdo->prepare("SELECT id, nom, solde_points, portefeuille, logo_url FROM organisateurs WHERE user_id = ? ORDER BY nom ASC");
$st->execute([$userId]);
$orgas = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$orgaId && count($orgas) === 1) { header('Location: marketing.php?id=' . (int)$orgas[0]['id']); exit; }

$orga = null;
foreach ($orgas as $o) if ((int)$o['id'] === $orgaId) $orga = $o;
if ($orgaId && !$orga) { header('Location: marketing.php'); exit; }

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
$net = $avgPrice * (1 - fin_commission_pct() / 100);
function mk_money($v) { return number_format($v, 2, ',', ' ') . ' €'; }

$body_class = 'bg-white';
require_once 'partials/header.php';
require_once 'partials/mk_icons.php';
?>
<title>Marketing et visibilité - ColoMap</title>
<link rel="stylesheet" href="assets/mk.css?v=1">

<main class="mk-wrap">
    <a href="profile.php" class="mk-back"><?= mk_icon('back', 16) ?> Tableau de bord</a>

    <section class="mk-hero">
        <p class="ds-eyebrow" style="color:rgba(255,255,255,.75)">Marketing ColoMap</p>
        <h1>Remplissez vos séjours avec les familles qui les cherchent</h1>
        <p>Votre séjour est déjà visible gratuitement. Les boosts le placent devant les familles au moment où elles comparent, et vous voyez précisément ce que chaque point investi rapporte : impressions, clics, inscriptions et coût par inscription.</p>
        <div class="mk-actions">
            <?php if ($orga): ?>
                <button type="button" class="ds-btn mk-btn-inv" onclick="mkOpen()"><?= mk_icon('wallet', 18) ?> Acheter des points</button>
                <a href="marketing_dashboard.php?id=<?= (int)$orga['id'] ?>" class="ds-btn mk-btn-ghost"><?= mk_icon('chart', 18) ?> Voir mes statistiques</a>
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
                <a class="ds-tile" href="marketing.php?id=<?= (int)$o['id'] ?>">
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
        <h2>Trois boosts, trois objectifs</h2>
        <p class="lead">Chaque boost augmente le poids de votre séjour dans le classement. Il ne remplace pas la pertinence : un séjour qui ne correspond pas à la recherche reste derrière.</p>
        <div class="mk-grid mk-g3" style="margin-top:22px">
            <div class="mk-offer">
                <div class="ico"><?= mk_icon('up') ?></div>
                <h3><?= $boosts['bump']['label'] ?></h3>
                <p>Un coup de projecteur immédiat, pour passer devant les séjours similaires aux heures de pointe.</p>
                <ul><li>Poids du séjour +50 % à l'instant T</li><li>L'effet s'estompe progressivement sur 3 jours</li><li>Cumulable avec les autres boosts</li></ul>
                <div class="foot"><div class="mk-price"><?= $boosts['bump']['cost'] ?> pts<small><?= mk_money($boosts['bump']['cost'] * MK_POINT_EUR) ?></small></div></div>
            </div>
            <div class="mk-offer rec">
                <span class="tag">Le plus efficace</span>
                <div class="ico"><?= mk_icon('star') ?></div>
                <h3><?= $boosts['vedette']['label'] ?></h3>
                <p>Votre séjour est mis en avant 7 jours dans la recherche et sur l'accueil, avec la mention « Sponsorisé ».</p>
                <ul><li>Poids du séjour +55 % pendant 7 jours</li><li>Présent dans la rubrique « À la une » de l'accueil</li><li>Reconductible : les jours s'ajoutent</li></ul>
                <div class="foot"><div class="mk-price"><?= $boosts['vedette']['cost'] ?> pts<small><?= mk_money($boosts['vedette']['cost'] * MK_POINT_EUR) ?> pour 7 jours</small></div></div>
            </div>
            <div class="mk-offer">
                <div class="ico"><?= mk_icon('clock') ?></div>
                <h3><?= $boosts['urgence']['label'] ?></h3>
                <p>Pour combler les dernières places d'un séjour qui part bientôt : il ressort quand les familles cherchent un départ proche.</p>
                <ul><li>Poids du séjour +20 % pendant 3 jours</li><li>Réservé aux séjours qui partent dans moins de 45 jours</li><li>Badge « Dernière minute »</li></ul>
                <div class="foot"><div class="mk-price"><?= $boosts['urgence']['cost'] ?> pts<small><?= mk_money($boosts['urgence']['cost'] * MK_POINT_EUR) ?> pour 3 jours</small></div></div>
            </div>
        </div>
    </section>

    <section class="mk-section">
        <h2>Ce que ça peut rapporter</h2>
        <div class="mk-grid mk-g3" style="margin-top:18px">
            <div class="mk-stat">
                <div class="k">Rentabilité</div>
                <?php if ($orga && $net > 0): $cost = $boosts['vedette']['cost'] * MK_POINT_EUR; ?>
                    <div class="v">x<?= number_format($net / $cost, 0, ',', ' ') ?></div>
                    <div class="s">Prix moyen d'une inscription chez vous : <b><?= mk_money($avgPrice) ?></b>. Une seule inscription de plus rembourse <?= number_format($net / $cost, 1, ',', ' ') ?> fois une Mise à la une (<?= mk_money($cost) ?>).</div>
                <?php else: ?>
                    <div class="v">1 inscription</div>
                    <div class="s">Une Mise à la une coûte <?= mk_money($boosts['vedette']['cost'] * MK_POINT_EUR) ?> : une seule inscription supplémentaire à 250 € la rembourse largement.</div>
                <?php endif; ?>
            </div>
            <div class="mk-stat">
                <div class="k">Effet mesuré sur ColoMap</div>
                <?php if ($proof): ?>
                    <div class="v">x<?= number_format($proof['multiplicateur'], 1, ',', ' ') ?></div>
                    <div class="s">Les séjours boostés reçoivent <?= number_format($proof['multiplicateur'], 1, ',', ' ') ?> fois plus de visites par affichage que les autres (30 derniers jours, <?= number_format($proof['boost_impressions'], 0, ',', ' ') ?> impressions observées).</div>
                <?php else: ?>
                    <div class="v">100 % mesuré</div>
                    <div class="s">Vous voyez l'effet réel de chaque boost sur vos séjours : impressions, visites, inscriptions. Pas de promesse, des chiffres.</div>
                <?php endif; ?>
            </div>
            <div class="mk-stat">
                <div class="k">Sans risque</div>
                <div class="v">0 abonnement</div>
                <div class="s">Vous n'achetez que les points dont vous avez besoin, et vous pouvez les payer avec les gains de vos inscriptions sans sortir un euro de votre poche.</div>
            </div>
        </div>
    </section>

    <?php if ($orga): ?>
    <section class="mk-section">
        <div style="display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap">
            <div><h2>Vos séjours</h2><p class="lead">Choisissez le séjour à mettre en avant.</p></div>
            <a class="ds-btn ds-btn-light mk-btn-sm" href="marketing_dashboard.php?id=<?= (int)$orga['id'] ?>"><?= mk_icon('chart', 16) ?> Statistiques détaillées</a>
        </div>
        <?php if (!$camps): ?>
            <p class="mk-note" style="margin-top:14px">Aucun séjour pour le moment. <a class="underline" href="create_camp.php">Publier un séjour</a></p>
        <?php else: ?>
        <div class="mk-table-wrap" style="margin-top:16px">
            <table class="mk-table">
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

    <section class="mk-section" id="classement">
        <h2>Comment fonctionne le classement</h2>
        <p class="lead">En toute transparence, pour vous comme pour les familles.</p>
        <div class="mk-grid mk-g2" style="margin-top:18px">
            <div class="mk-step"><span class="n">1</span><div><b class="text-[#26343b]">La pertinence d'abord</b><p class="mk-note" style="margin-top:4px;font-size:14px">Recherche, âge, dates, prix et lieu filtrent les séjours. Un boost n'ajoute jamais un séjour hors sujet dans les résultats.</p></div></div>
            <div class="mk-step"><span class="n">2</span><div><b class="text-[#26343b]">Un score par séjour</b><p class="mk-note" style="margin-top:4px;font-size:14px">Pertinence, places restantes, proximité du départ, taux de visite réel et nouveauté. Un séjour qui plaît aux familles monte seul.</p></div></div>
            <div class="mk-step"><span class="n">3</span><div><b class="text-[#26343b]">Le boost multiplie ce score</b><p class="mk-note" style="margin-top:4px;font-size:14px">+55 % pour la Mise à la une, +20 % en dernière minute, jusqu'à +50 % qui s'estompe pour la Remontée. Une légère rotation évite que les mêmes séjours occupent toujours le haut de page.</p></div></div>
            <div class="mk-step"><span class="n">4</span><div><b class="text-[#26343b]">Toujours signalé</b><p class="mk-note" style="margin-top:4px;font-size:14px">Les séjours mis à la une portent la mention « Sponsorisé », conformément à la réglementation sur la transparence du classement.</p></div></div>
        </div>
    </section>

    <section class="mk-section mk-faq">
        <h2>Questions fréquentes</h2>
        <div style="margin-top:10px">
            <details><summary>Les points expirent-ils ?</summary><p>Non. Ils restent sur le solde de l'organisme et servent pour tous ses séjours.</p></details>
            <details><summary>Comment payer avec mes gains ?</summary><p>Si des inscriptions ont été payées en ligne, leur montant net de commission constitue votre solde à recevoir. Vous pouvez l'utiliser pour acheter des points : le montant est déduit du prochain versement, sans paiement par carte.</p></details>
            <details><summary>Que se passe-t-il si un séjour est complet ?</summary><p>Dans la recherche, un séjour complet est automatiquement rétrogradé, même boosté. Vous ne payez pas pour des places que vous n'avez plus.</p></details>
            <details><summary>Puis-je être remboursé d'un boost ?</summary><p>Un boost activé ne peut pas être annulé. Les points non dépensés restent disponibles. Pour toute question, <a class="underline" href="contact.php">contactez-nous</a>.</p></details>
        </div>
    </section>
</main>

<?php if ($orga) { $mkOrgaId = (int)$orga['id']; $mkWalletCents = $walletCents; require 'partials/mk_shop.php'; } ?>
<?php require_once 'partials/footer.php'; ?>
