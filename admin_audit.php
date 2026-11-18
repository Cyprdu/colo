<?php
require_once __DIR__ . '/admin_lib.php';
adm_guard(false, 'audit.read');
$per = 40;
$page = max(1, (int)($_GET['page'] ?? 1));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$act = (string)($_GET['a'] ?? '');
$qui = (string)($_GET['u'] ?? '');
$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['du'] ?? '')) ? (string)$_GET['du'] : '';
$au = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['au'] ?? '')) ? (string)$_GET['au'] : '';
$sec = ($_GET['s'] ?? '') === '1';
$bad = ['acces_refuse', 'csrf_refuse', 'reauth_echec', 'reauth_bloque', 'session_invalide', 'sql_echec'];
$where = '1=1'; $p = [];
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where .= ' AND (email LIKE ? OR cible LIKE ? OR detail LIKE ? OR ip LIKE ? OR avant LIKE ? OR apres LIKE ?)'; array_push($p, $like, $like, $like, $like, $like, $like); }
if ($act !== '') { $where .= ' AND action = ?'; $p[] = $act; }
if ($qui !== '') { $where .= ' AND email = ?'; $p[] = $qui; }
if ($du !== '') { $where .= ' AND created_at >= ?'; $p[] = $du . ' 00:00:00'; }
if ($au !== '') { $where .= ' AND created_at <= ?'; $p[] = $au . ' 23:59:59'; }
if ($sec) $where .= " AND action IN ('" . implode("','", $bad) . "')";

if (isset($_GET['export'])) {
    adm_need('audit.export', false);
    if (!adm_reauth_ok()) { $_SESSION['adm_flash'] = ['err', 'Confirmez votre mot de passe puis relancez l’export.']; header('Location: admin_audit.php'); exit; }
    require_once __DIR__ . '/api/compta_lib.php';
    adm_audit('audit_export', 'admin_audit', $where === '1=1' ? 'tout' : 'filtré');
    $st = $pdo->prepare("SELECT created_at, email, ip, action, cible, detail, avant, apres FROM admin_audit WHERE $where ORDER BY id DESC LIMIT 100000");
    $st->execute($p);
    compta_csv('journal-audit-' . date('Y-m-d') . '.csv', ['Date', 'Membre', 'IP', 'Action', 'Cible', 'Détail', 'Avant', 'Après'], (function () use ($st) { foreach ($st as $r) yield array_values($r); })());
}

$total = (int)adm_one("SELECT COUNT(*) FROM admin_audit WHERE $where", $p);
$pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$rows = adm_rows("SELECT * FROM admin_audit WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$actions = array_column(adm_rows("SELECT DISTINCT action FROM admin_audit ORDER BY action"), 'action');
$membres = array_column(adm_rows("SELECT DISTINCT email FROM admin_audit WHERE email IS NOT NULL ORDER BY email"), 'email');
$qsArr = array_filter(['q' => $q, 'a' => $act, 'u' => $qui, 'du' => $du, 'au' => $au, 's' => $sec ? '1' : ''], fn($v) => $v !== '');
$qs = fn(array $o) => 'admin_audit.php?' . http_build_query(array_filter(array_merge($qsArr, $o), fn($v) => $v !== '' && $v !== null));
adm_head("Journal d'audit");
?>
<form class="bar" method="get" action="admin_audit.php">
  <label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($q) ?>" placeholder="Cible, IP, détail, valeurs…" maxlength="80"></label>
  <select class="in sel" name="u" aria-label="Membre"><option value="">Tous les membres</option><?php foreach ($membres as $x): ?><option value="<?= h($x) ?>"<?= $x === $qui ? ' selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select>
  <select class="in sel" name="a" aria-label="Action"><option value="">Toutes les actions</option><?php foreach ($actions as $x): ?><option value="<?= h($x) ?>"<?= $x === $act ? ' selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select>
  <label class="fld-inline">Du <input class="in dt" type="date" name="du" value="<?= h($du) ?>"></label><label class="fld-inline">au <input class="in dt" type="date" name="au" value="<?= h($au) ?>"></label>
  <label class="chk"><input type="checkbox" name="s" value="1"<?= $sec ? ' checked' : '' ?>> Sécurité seulement</label>
  <button class="btn" type="submit"><?= ic('filter') ?> Filtrer</button>
  <?php if (adm_can('audit.export')): ?><button class="btn ghost" type="button" data-api="audit.unlock" data-go="<?= h($qs(['export' => '1'])) ?>"><?= ic('download') ?> CSV</button><?php endif; ?>
  <?= adm_help('Chaque action de l’équipe est enregistrée : qui, quoi, sur quel élément, depuis quelle IP, avec les valeurs avant et après. Le journal ne peut pas être modifié depuis l’administration.') ?>
  <span class="count"><?= number_format($total, 0, ',', ' ') ?> entrée<?= $total > 1 ? 's' : '' ?></span>
</form>
<?php if (!$rows): ?><?= adm_empty('shield', 'Aucune entrée.') ?><?php else: ?>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th>Date</th><th>Membre</th><th>Action</th><th>Cible</th><th>Détail</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td>
      <td data-l="Membre" class="small"><?= h($r['email']) ?></td>
      <td data-l="Action"><span class="pill <?= in_array($r['action'], $bad, true) ? 'bad' : '' ?>"><?= h($r['action']) ?></span></td>
      <td data-l="Cible" class="small"><?= h($r['cible']) ?></td>
      <td data-l="Détail" class="small wrap"><?= h($r['detail']) ?>
        <?php if (!empty($r['avant']) || !empty($r['apres'])): ?><details class="diff"><summary class="lnk-q">avant / après</summary>
          <div class="grid2"><pre class="mono small"><?= h(json_encode(json_decode((string)$r['avant'], true) ?? $r['avant'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><pre class="mono small"><?= h(json_encode(json_decode((string)$r['apres'], true) ?? $r['apres'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></div></details><?php endif; ?></td>
      <td data-l="IP" class="muted small"><?= h($r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?= adm_pager($page, $pages, fn($n) => $qs(['page' => $n])) ?>
<?php adm_foot(); ?>
