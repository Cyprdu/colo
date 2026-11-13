<?php
// admin_audit.php : journal d'audit (lecture seule). Les écritures du journal ne passent que par adm_audit().
require_once __DIR__ . '/admin_lib.php';
adm_guard();

$per = 40;
$page = max(1, (int)($_GET['page'] ?? 1));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$act = (string)($_GET['a'] ?? '');
$where = '1=1'; $p = [];
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where .= ' AND (email LIKE ? OR cible LIKE ? OR detail LIKE ? OR ip LIKE ?)'; $p = [$like, $like, $like, $like]; }
if ($act !== '') { $where .= ' AND action = ?'; $p[] = $act; }
$total = (int)adm_one("SELECT COUNT(*) FROM admin_audit WHERE $where", $p);
$rows = adm_rows("SELECT * FROM admin_audit WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$actions = array_column(adm_rows("SELECT DISTINCT action FROM admin_audit ORDER BY action"), 'action');
$pages = max(1, (int)ceil($total / $per));
$qs = fn(array $o) => 'admin_audit.php?' . http_build_query(array_filter(array_merge(['q' => $q, 'a' => $act, 'page' => 1], $o), fn($v) => $v !== '' && $v !== null));
$bad = ['acces_refuse', 'csrf_refuse', 'reauth_echec', 'reauth_bloque', 'session_invalide', 'sql_echec'];

adm_head("Journal d'audit", 'admin_audit.php');
?>
<form class="bar" method="get" action="admin_audit.php">
  <label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($q) ?>" placeholder="Utilisateur, cible, IP, détail…" maxlength="80"></label>
  <select class="in sel" name="a" aria-label="Type d'action"><option value="">Toutes les actions</option><?php foreach ($actions as $x): ?><option value="<?= h($x) ?>"<?= $x === $act ? ' selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select>
  <button class="btn" type="submit"><?= ic('search') ?> Filtrer</button>
  <span class="count"><?= number_format($total, 0, ',', ' ') ?> entrée<?= $total > 1 ? 's' : '' ?></span>
</form>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th>Date</th><th>Administrateur</th><th>Action</th><th>Cible</th><th>Détail</th><th>IP</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty"><?= ic('shield', 'ic ic-xl') ?><p>Aucune entrée.</p></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td data-l="Date"><?= h(adm_date($r['created_at'])) ?></td>
      <td data-l="Administrateur"><?= h($r['email']) ?></td>
      <td data-l="Action"><span class="pill <?= in_array($r['action'], $bad, true) ? 'bad' : '' ?>"><?= h($r['action']) ?></span></td>
      <td data-l="Cible"><?= h($r['cible']) ?></td>
      <td data-l="Détail" class="mono small wrap"><?= h($r['detail']) ?></td>
      <td data-l="IP" class="muted small"><?= h($r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($pages > 1): ?>
<nav class="pager">
  <?php if ($page > 1): ?><a class="btn ghost" href="<?= h($qs(['page' => $page - 1])) ?>"><?= ic('back') ?> Précédent</a><?php endif; ?>
  <span>Page <?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn ghost" href="<?= h($qs(['page' => $page + 1])) ?>">Suivant <?= ic('next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php adm_foot(['page' => 'audit']); ?>
