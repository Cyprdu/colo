<?php
// admin.php : tableau de bord du back-office
require_once __DIR__ . '/admin_lib.php';
adm_guard();

$k = [
    'users'      => adm_one("SELECT COUNT(*) FROM users"),
    'users30'    => adm_one("SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 30 DAY)"),
    'orgas'      => adm_one("SELECT COUNT(*) FROM organisateurs"),
    'camps'      => adm_one("SELECT COUNT(*) FROM camps WHERE supprime = 0"),
    'campsok'    => adm_one("SELECT COUNT(*) FROM camps WHERE valide = 1 AND supprime = 0"),
    'insc'       => adm_one("SELECT COUNT(*) FROM inscriptions"),
    'inscpay'    => adm_one("SELECT COUNT(*) FROM inscriptions WHERE statut_paiement = 'PAYE' AND montant_paye > 0"),
    'ca'         => adm_one("SELECT COALESCE(SUM(montant_paye), 0) FROM inscriptions WHERE statut_paiement = 'PAYE'"),
    'ca30'       => adm_one("SELECT COALESCE(SUM(montant_paye), 0) FROM inscriptions WHERE statut_paiement = 'PAYE' AND id IN (SELECT id FROM inscriptions)"),
    'commission' => adm_one("SELECT COALESCE(SUM(montant_total - montant_apres_commission), 0) FROM virements"),
    'wallets'    => adm_one("SELECT COALESCE(SUM(portefeuille), 0) FROM organisateurs"),
];
$b = adm_badges();
$pending = [
    ['Demandes directeur', $b['users'], 'admin_list.php?e=users&f=demandes', 'users'],
    ['Séjours à valider', $b['camps'], 'admin_list.php?e=camps&f=attente', 'tent'],
    ['Virements à traiter', $b['virements'], 'admin_list.php?e=virements&f=attente', 'bank'],
    ['Messages sans réponse', $b['contacts'], 'admin_list.php?e=contacts&f=attente', 'mail'],
];
$months = adm_rows("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n FROM users WHERE created_at >= (NOW() - INTERVAL 6 MONTH) GROUP BY m ORDER BY m");
$maxm = max(1, ...array_map('intval', array_column($months, 'n') ?: [1]));
$recent = adm_rows("SELECT i.id, i.statut, i.statut_paiement, i.prix_final, c.nom AS camp, CONCAT_WS(' ', e.prenom, e.nom) AS enfant
                    FROM inscriptions i LEFT JOIN camps c ON c.id = i.camp_id LEFT JOIN enfants e ON e.id = i.enfant_id ORDER BY i.id DESC LIMIT 6");
$audit = adm_rows("SELECT action, cible, email, created_at FROM admin_audit ORDER BY id DESC LIMIT 6");
$n = fn($v) => number_format((int)$v, 0, ',', ' ');

adm_head('Tableau de bord', 'admin.php');
?>
<section class="kpis">
  <div class="kpi"><span class="ico"><?= ic('users') ?></span><div><b><?= $n($k['users']) ?></b><span>Utilisateurs</span><small>+<?= $n($k['users30']) ?> ces 30 jours</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('building') ?></span><div><b><?= $n($k['orgas']) ?></b><span>Organismes</span><small>Portefeuilles : <?= adm_money($k['wallets']) ?></small></div></div>
  <div class="kpi"><span class="ico"><?= ic('tent') ?></span><div><b><?= $n($k['camps']) ?></b><span>Séjours</span><small><?= $n($k['campsok']) ?> validés</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('ticket') ?></span><div><b><?= $n($k['insc']) ?></b><span>Inscriptions</span><small><?= $n($k['inscpay']) ?> payées</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('euro') ?></span><div><b><?= adm_money($k['ca']) ?></b><span>Chiffre d'affaires</span><small>Paiements encaissés</small></div></div>
  <div class="kpi"><span class="ico"><?= ic('chart') ?></span><div><b><?= adm_money($k['commission']) ?></b><span>Commissions</span><small>Sur les virements demandés</small></div></div>
</section>

<h2 class="sec"><?= ic('clock') ?> À traiter</h2>
<section class="todo">
<?php foreach ($pending as [$label, $count, $href, $icon]): ?>
  <a class="todo-i<?= $count ? ' hot' : '' ?>" href="<?= h($href) ?>"><?= ic($icon) ?><span><?= h($label) ?></span><b><?= (int)$count ?></b><?= ic('next', 'ic ic-sm') ?></a>
<?php endforeach; ?>
</section>

<div class="cols">
  <section class="card">
    <h2 class="sec"><?= ic('chart') ?> Nouveaux comptes (6 mois)</h2>
    <?php if (!$months): ?><p class="muted">Pas de données.</p><?php else: ?>
    <div class="bars"><?php foreach ($months as $m): ?>
      <div class="bar"><i style="height:<?= max(6, round(100 * (int)$m['n'] / $maxm)) ?>%"></i><b><?= (int)$m['n'] ?></b><span><?= h(substr($m['m'], 5, 2) . '/' . substr($m['m'], 2, 2)) ?></span></div>
    <?php endforeach; ?></div><?php endif; ?>
  </section>
  <section class="card">
    <h2 class="sec"><?= ic('ticket') ?> Dernières inscriptions</h2>
    <?php if (!$recent): ?><p class="muted">Aucune inscription.</p><?php else: ?>
    <ul class="feed"><?php foreach ($recent as $r): ?>
      <li><span class="dot"></span><div><b><?= h(trim($r['enfant']) ?: 'Participant') ?></b> · <?= h($r['camp'] ?: 'Séjour') ?><small><?= h($r['statut']) ?> · <?= h($r['statut_paiement']) ?> · <?= adm_money($r['prix_final']) ?></small></div></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <a class="more" href="admin_list.php?e=inscriptions">Toutes les inscriptions <?= ic('next', 'ic ic-sm') ?></a>
  </section>
</div>

<div class="cols">
  <section class="card">
    <h2 class="sec"><?= ic('shield') ?> Dernières actions d'administration</h2>
    <?php if (!$audit): ?><p class="muted">Aucune action enregistrée.</p><?php else: ?>
    <ul class="feed"><?php foreach ($audit as $r): ?>
      <li><span class="dot"></span><div><b><?= h($r['action']) ?></b> <?= h($r['cible']) ?><small><?= h($r['email']) ?> · <?= h(adm_date($r['created_at'])) ?></small></div></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <a class="more" href="admin_audit.php">Journal complet <?= ic('next', 'ic ic-sm') ?></a>
  </section>
  <section class="card">
    <h2 class="sec"><?= ic('database') ?> Outils</h2>
    <div class="tools">
      <a href="admin_db.php"><?= ic('database') ?><span>Éditeur de base de données</span></a>
      <a href="admin_sql.php"><?= ic('terminal') ?><span>Console SQL</span></a>
      <a href="admin_add_camp.php"><?= ic('plus') ?><span>Ajouter un séjour</span></a>
      <a href="admin_sessions.php"><?= ic('refresh') ?><span>Migration sessions</span></a>
    </div>
    <p class="muted small">L'éditeur de base de données et la console SQL demandent votre mot de passe. Toutes les écritures sont journalisées.</p>
  </section>
</div>
<?php adm_foot(); ?>
