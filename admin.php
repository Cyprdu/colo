<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/compta_lib.php';
adm_guard();

$n = fn($v) => number_format((int)$v, 0, ',', ' ');
$kpi = [];
if (adm_can('users.read')) $kpi[] = ['users', $n(adm_one("SELECT COUNT(*) FROM users")), 'Utilisateurs', '+' . $n(adm_one("SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 30 DAY)")) . ' ces 30 jours', 'admin_list.php?e=users'];
if (adm_can('orgas.read')) $kpi[] = ['building', $n(adm_one("SELECT COUNT(*) FROM organisateurs")), 'Organismes', '', 'admin_list.php?e=organismes'];
if (adm_can('camps.read')) $kpi[] = ['tent', $n(adm_one("SELECT COUNT(*) FROM camps WHERE supprime = 0")), 'Séjours', $n(adm_one("SELECT COUNT(*) FROM camps WHERE valide = 1 AND supprime = 0")) . ' validés', 'admin_list.php?e=camps'];
if (adm_can('inscriptions.read')) $kpi[] = ['ticket', $n(adm_one("SELECT COUNT(*) FROM inscriptions")), 'Inscriptions', $n(adm_one("SELECT COUNT(*) FROM inscriptions WHERE statut_paiement = 'PAYE' AND montant_paye > 0")) . ' payées', 'admin_list.php?e=inscriptions'];
if (adm_can('compta.read')) {
    $kpi[] = ['euro', adm_money(adm_one("SELECT COALESCE(SUM(brut_cents - rembourse_cents), 0) / 100 FROM fin_paiements WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")), 'Encaissé ce mois', 'Paiements carte, nets des remboursements', 'admin_compta.php?v=journal'];
    $kpi[] = ['chart', adm_money(adm_one("SELECT COALESCE(SUM(commission_cents), 0) / 100 FROM fin_paiements WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")), 'Commissions ce mois', 'Brutes, avant frais Stripe', 'admin_compta.php?v=revenus'];
}
$todo = adm_todo_counts();
$recent = adm_can('inscriptions.read') ? adm_rows("SELECT i.statut, i.statut_paiement, i.prix_final, c.nom AS camp, CONCAT_WS(' ', e.prenom, e.nom) AS enfant
    FROM inscriptions i LEFT JOIN camps c ON c.id = i.camp_id LEFT JOIN enfants e ON e.id = i.enfant_id ORDER BY i.id DESC LIMIT 6") : [];
$audit = adm_can('audit.read') ? adm_rows("SELECT action, cible, email, created_at FROM admin_audit ORDER BY id DESC LIMIT 6") : [];
$notifs = [];
[$w, $p] = adm_notif_where();
$notifs = adm_rows("SELECT n.titre, n.lien, n.type, n.created_at FROM adm_notifs n LEFT JOIN adm_notif_lus l ON l.notif_id = n.id AND l.user_id = ? WHERE l.notif_id IS NULL AND $w ORDER BY n.id DESC LIMIT 6", array_merge([(int)$_SESSION['user']['id']], $p));

adm_head('Tableau de bord');
?>
<?php if ($kpi): ?>
<section class="kpis">
<?php foreach ($kpi as [$ico, $val, $lab, $sub, $href]): ?>
  <a class="kpi" href="<?= h($href) ?>"><span class="ico"><?= ic($ico) ?></span><div><b><?= h($val) ?></b><span><?= h($lab) ?></span><?php if ($sub): ?><small><?= h($sub) ?></small><?php endif; ?></div></a>
<?php endforeach; ?>
</section>
<?php endif; ?>

<h2 class="sec"><?= ic('clock') ?> À traiter</h2>
<section class="todo">
<?php $any = false; foreach (adm_todo_types() as $k => [$label, $perm, $href, $icon]): if (!adm_can($perm)) continue; $any = true; ?>
  <a class="todo-i<?= $todo[$k] ? ' hot' : '' ?>" href="<?= h($href) ?>"><?= ic($icon) ?><span><?= h($label) ?></span><b><?= (int)$todo[$k] ?></b><?= ic('next', 'ic ic-sm') ?></a>
<?php endforeach; ?>
<?php if (!$any): ?><p class="muted">Votre rôle ne comporte pas de file de traitement.</p><?php endif; ?>
</section>

<div class="cols">
  <section class="card">
    <h2 class="sec"><?= ic('bell') ?> Notifications non lues</h2>
    <?php if (!$notifs): ?><p class="muted">Tout est lu.</p><?php else: ?>
    <ul class="feed"><?php foreach ($notifs as $r): ?>
      <li><span class="dot"></span><div><a href="<?= h($r['lien']) ?>"><b><?= h($r['titre']) ?></b></a><small><?= h(adm_date($r['created_at'])) ?></small></div></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <a class="more" href="admin_notifs.php">Centre de notifications <?= ic('next', 'ic ic-sm') ?></a>
  </section>
  <?php if (adm_can('inscriptions.read')): ?>
  <section class="card">
    <h2 class="sec"><?= ic('ticket') ?> Dernières inscriptions</h2>
    <?php if (!$recent): ?><p class="muted">Aucune inscription.</p><?php else: ?>
    <ul class="feed"><?php foreach ($recent as $r): ?>
      <li><span class="dot"></span><div><b><?= h(trim((string)$r['enfant']) ?: 'Participant') ?></b> · <?= h($r['camp'] ?: 'Séjour') ?><small><?= h($r['statut']) ?> · <?= h($r['statut_paiement']) ?> · <?= adm_money($r['prix_final']) ?></small></div></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <a class="more" href="admin_list.php?e=inscriptions">Toutes les inscriptions <?= ic('next', 'ic ic-sm') ?></a>
  </section>
  <?php endif; ?>
  <?php if (adm_can('audit.read')): ?>
  <section class="card">
    <h2 class="sec"><?= ic('shield') ?> Dernières actions</h2>
    <?php if (!$audit): ?><p class="muted">Aucune action enregistrée.</p><?php else: ?>
    <ul class="feed"><?php foreach ($audit as $r): ?>
      <li><span class="dot"></span><div><b><?= h($r['action']) ?></b> <?= h($r['cible']) ?><small><?= h($r['email']) ?> · <?= h(adm_date($r['created_at'])) ?></small></div></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <a class="more" href="admin_audit.php">Journal complet <?= ic('next', 'ic ic-sm') ?></a>
  </section>
  <?php endif; ?>
</div>
<?php adm_foot(); ?>
