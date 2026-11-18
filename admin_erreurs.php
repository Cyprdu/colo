<?php
require_once __DIR__ . '/admin_lib.php';
adm_guard(false, 'tech.logs');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$grp = ($_GET['vue'] ?? 'groupes') !== 'liste';
$jours = in_array((int)($_GET['j'] ?? 7), [1, 7, 30, 365], true) ? (int)($_GET['j'] ?? 7) : 7;
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 40;
$w = "created_at > (NOW() - INTERVAL $jours DAY)"; $p = [];
if ($q !== '') { $lk = '%' . addcslashes($q, '%_\\') . '%'; $w .= ' AND (message LIKE ? OR fichier LIKE ? OR url LIKE ? OR type LIKE ?)'; $p = [$lk, $lk, $lk, $lk]; }
$url = fn(array $o = []) => 'admin_erreurs.php?' . http_build_query(array_filter(array_merge(['q' => $q, 'vue' => $grp ? '' : 'liste', 'j' => $jours === 7 ? '' : $jours, 'page' => ''], $o), fn($v) => $v !== '' && $v !== null));
if ($grp) {
    $total = (int)adm_one("SELECT COUNT(*) FROM (SELECT 1 FROM app_erreurs WHERE $w GROUP BY type, fichier, ligne, LEFT(message, 160)) x", $p);
    $rows = adm_rows("SELECT type, fichier, ligne, MAX(LEFT(message, 400)) message, COUNT(*) n, MAX(created_at) dernier, MIN(created_at) premier, MAX(url) url
        FROM app_erreurs WHERE $w GROUP BY type, fichier, ligne, LEFT(message, 160) ORDER BY dernier DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
} else {
    $total = (int)adm_one("SELECT COUNT(*) FROM app_erreurs WHERE $w", $p);
    $rows = adm_rows("SELECT created_at, type, message, fichier, ligne, url, methode FROM app_erreurs WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
}
$pages = max(1, (int)ceil($total / $per));
$pdo->prepare("INSERT IGNORE INTO adm_notif_lus (notif_id, user_id) SELECT id, ? FROM adm_notifs WHERE type = 'erreur'")->execute([(int)$_SESSION['user']['id']]);

adm_head('Erreurs applicatives');
?>
<div class="toolbar">
  <div class="seg"><a class="<?= $grp ? 'on' : '' ?>" href="<?= h($url(['vue' => ''])) ?>">Regroupées</a><a class="<?= $grp ? '' : 'on' ?>" href="<?= h($url(['vue' => 'liste'])) ?>">Chronologique</a></div>
  <div class="seg"><?php foreach ([1 => '24 h', 7 => '7 jours', 30 => '30 jours', 365 => '1 an'] as $k => $l): ?><a class="<?= $k === $jours ? 'on' : '' ?>" href="<?= h($url(['j' => $k === 7 ? '' : $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
  <form class="bar inl-bar" method="get" action="admin_erreurs.php"><?php if (!$grp): ?><input type="hidden" name="vue" value="liste"><?php endif; ?><?php if ($jours !== 7): ?><input type="hidden" name="j" value="<?= $jours ?>"><?php endif; ?>
    <label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($q) ?>" placeholder="Message, fichier, adresse…" maxlength="80"></label></form>
  <?= adm_help('Chaque erreur fatale ou exception non rattrapée (le fameux « HTTP 500 ») est enregistrée avec le fichier et la ligne. Les jetons des adresses sont masqués.') ?>
  <button class="btn ghost sm" type="button" data-api="err.purge" data-confirm="Supprimer les erreurs de plus de 30 jours ?"><?= ic('trash') ?> Purger &gt; 30 j</button>
</div>
<?php if (!$rows): ?><?= adm_empty('bug', 'Aucune erreur sur cette période.') ?><?php elseif ($grp): ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th class="num">Fois</th><th>Erreur</th><th>Fichier</th><th>Dernière</th><th>Première</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="num" data-l="Fois"><b><?= (int)$r['n'] ?></b></td><td data-l="Erreur"><span class="pill bad"><?= h($r['type']) ?></span><div class="mono small wrap"><?= h($r['message']) ?></div><?php if ($r['url']): ?><div class="muted small wrap"><?= h($r['url']) ?></div><?php endif; ?></td>
  <td data-l="Fichier" class="mono small"><?= h($r['fichier']) ?>:<?= (int)$r['ligne'] ?></td><td data-l="Dernière" class="small"><?= h(adm_date($r['dernier'])) ?></td><td data-l="Première" class="small muted"><?= h(adm_date($r['premier'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Erreur</th><th>Fichier</th><th>Adresse</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Erreur"><span class="pill bad"><?= h($r['type']) ?></span><div class="mono small wrap"><?= h($r['message']) ?></div></td><td data-l="Fichier" class="mono small"><?= h($r['fichier']) ?>:<?= (int)$r['ligne'] ?></td><td data-l="Adresse" class="small wrap"><?= h($r['methode']) ?> <?= h($r['url']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?= adm_pager($page, $pages, fn($n) => $url(['page' => $n])) ?>
<?php adm_foot(); ?>
