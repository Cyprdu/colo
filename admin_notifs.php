<?php
require_once __DIR__ . '/admin_lib.php';
adm_guard();
$uid = (int)$_SESSION['user']['id'];
$types = adm_todo_types();

if (isset($_GET['go'])) {
    $id = adm_untok('n', (string)$_GET['go']);
    [$w, $p] = adm_notif_where();
    $lien = $id ? (string)adm_one("SELECT n.lien FROM adm_notifs n WHERE n.id = ? AND $w", array_merge([$id], $p)) : '';
    if ($lien !== '') {
        $pdo->prepare("INSERT IGNORE INTO adm_notif_lus (notif_id, user_id) VALUES (?, ?)")->execute([$id, $uid]);
        if (preg_match('/^[a-z_]+\.php(\?[^\s<>"]*)?$/', $lien)) { header('Location: ' . $lien); exit; }
    }
    header('Location: admin_notifs.php'); exit;
}

adm_notif_sync($pdo, true);
$type = isset($types[$_GET['t'] ?? '']) ? (string)$_GET['t'] : '';
$etat = ($_GET['s'] ?? 'nonlues') === 'toutes' ? 'toutes' : 'nonlues';
$roles = adm_role_presets();
$myRoles = adm_is_supreme() ? array_keys($roles) : array_column(adm_rows("SELECT r.code FROM adm_user_roles ur JOIN adm_roles r ON r.id = ur.role_id WHERE ur.user_id = ?", [$uid]), 'code');
$role = in_array($_GET['r'] ?? '', $myRoles, true) && ($_GET['r'] ?? '') !== 'supreme' ? (string)$_GET['r'] : '';
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 30;

[$w, $p] = adm_notif_where();
$where = "$w"; $params = $p;
if ($type !== '') { $where .= ' AND n.type = ?'; $params[] = $type; }
if ($role !== '') {
    $rp = array_column(adm_rows("SELECT rp.perm FROM adm_role_perms rp JOIN adm_roles r ON r.id = rp.role_id WHERE r.code = ?", [$role]), 'perm');
    $where .= $rp ? ' AND n.perm IN (' . implode(',', array_fill(0, count($rp), '?')) . ')' : ' AND 0=1';
    $params = array_merge($params, $rp);
}
$base = "FROM adm_notifs n LEFT JOIN adm_notif_lus l ON l.notif_id = n.id AND l.user_id = ? WHERE $where" . ($etat === 'nonlues' ? ' AND l.notif_id IS NULL' : '');
$bp = array_merge([$uid], $params);
$total = (int)adm_one("SELECT COUNT(*) $base", $bp);
$pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$rows = adm_rows("SELECT n.id, n.type, n.titre, n.created_at, l.notif_id IS NOT NULL AS lu $base ORDER BY n.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $bp);
$counts = [];
foreach (adm_rows("SELECT n.type, COUNT(*) c FROM adm_notifs n LEFT JOIN adm_notif_lus l ON l.notif_id = n.id AND l.user_id = ? WHERE $w AND l.notif_id IS NULL GROUP BY n.type", array_merge([$uid], $p)) as $r) $counts[$r['type']] = (int)$r['c'];
$url = fn(array $o = []) => 'admin_notifs.php?' . http_build_query(array_filter(array_merge(['t' => $type, 's' => $etat === 'toutes' ? 'toutes' : '', 'r' => $role, 'page' => 1], $o), fn($v) => $v !== '' && $v !== 1 && $v !== null));

adm_head('Notifications');
?>
<div class="toolbar">
  <div class="seg">
    <a class="<?= $type === '' ? 'on' : '' ?>" href="<?= h($url(['t' => ''])) ?>">Tout<?php if (array_sum($counts)): ?> <b><?= array_sum($counts) ?></b><?php endif; ?></a>
    <?php foreach ($types as $k => [$l, $perm]): if (!adm_can($perm)) continue; ?>
      <a class="<?= $type === $k ? 'on' : '' ?>" href="<?= h($url(['t' => $k])) ?>"><?= h($l) ?><?php if (!empty($counts[$k])): ?> <b><?= $counts[$k] ?></b><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <div class="seg">
    <a class="<?= $etat === 'nonlues' ? 'on' : '' ?>" href="<?= h($url(['s' => ''])) ?>">Non lues</a>
    <a class="<?= $etat === 'toutes' ? 'on' : '' ?>" href="<?= h($url(['s' => 'toutes'])) ?>">Toutes</a>
  </div>
  <?php if (count($myRoles) > 1): ?>
  <form method="get" action="admin_notifs.php" class="inl">
    <input type="hidden" name="t" value="<?= h($type) ?>"><input type="hidden" name="s" value="<?= h($etat === 'toutes' ? 'toutes' : '') ?>">
    <select class="in sel" name="r" aria-label="Filtrer par rôle" data-autosubmit>
      <option value="">Tous les rôles</option>
      <?php foreach ($myRoles as $c): if ($c === 'supreme') continue; ?><option value="<?= h($c) ?>"<?= $c === $role ? ' selected' : '' ?>><?= h($roles[$c][0]) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
  <?= adm_help('Chacun ne voit que les notifications de son rôle. Cliquer une notification la marque comme lue et ouvre l’élément.') ?>
  <span class="count"><?= number_format($total, 0, ',', ' ') ?> notification<?= $total > 1 ? 's' : '' ?></span>
</div>

<div class="bulk" id="bulk" hidden><b><span data-selcount>0</span> sélectionnée(s)</b>
  <button class="btn ghost sm" type="button" data-api="notif.read" data-sel="1" data-read="1" data-ok="Marquées comme lues.">Marquer lu</button>
  <button class="btn ghost sm" type="button" data-api="notif.read" data-sel="1" data-read="0" data-ok="Marquées comme non lues.">Marquer non lu</button>
</div>

<?php if (!$rows): ?>
  <?= adm_empty('bell', $etat === 'nonlues' ? 'Aucune notification non lue.' : 'Aucune notification.') ?>
<?php else: ?>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th class="ck"><input type="checkbox" data-selall aria-label="Tout sélectionner"></th><th>Notification</th><th>Type</th><th>Date</th><th class="act-h"></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $t = adm_tok('n', (int)$r['id']); ?>
    <tr class="<?= $r['lu'] ? '' : 'unread' ?>">
      <td class="ck"><input type="checkbox" name="sel[]" value="<?= h($t) ?>" aria-label="Sélectionner"></td>
      <td data-l="Notification"><a href="admin_notifs.php?go=<?= h($t) ?>"><?= h($r['titre']) ?></a></td>
      <td data-l="Type"><span class="pill"><?= h($types[$r['type']][0] ?? $r['type']) ?></span></td>
      <td data-l="Date" class="muted small"><?= h(adm_date($r['created_at'])) ?></td>
      <td class="acts"><button class="ib" type="button" data-api="notif.read" data-ids="<?= h($t) ?>" data-read="<?= $r['lu'] ? '0' : '1' ?>" title="<?= $r['lu'] ? 'Marquer non lu' : 'Marquer lu' ?>" aria-label="<?= $r['lu'] ? 'Marquer non lu' : 'Marquer lu' ?>"><?= ic($r['lu'] ? 'mail' : 'check') ?></button></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<div class="row-l">
  <?php if (array_sum($counts)): ?><button class="btn ghost" type="button" data-api="notif.allread" data-type="<?= h($type) ?>" data-role="<?= h($role) ?>" data-ok="Tout est marqué comme lu."><?= ic('check') ?> Tout marquer comme lu</button><?php endif; ?>
</div>
<?= adm_pager($page, $pages, fn($n) => $url(['page' => $n])) ?>
<?php adm_foot(); ?>
