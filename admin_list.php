<?php
// admin_list.php?e=<entité> : liste générique (recherche, filtres, pagination, actions) pour tous les objets métier.
require_once __DIR__ . '/admin_lib.php';
adm_guard();

$ents = adm_entities();
$key = (string)($_GET['e'] ?? 'users');
if (!isset($ents[$key])) { http_response_code(404); $key = 'users'; }
adm_need($ents[$key]['perm'], false);
$E = $ents[$key];
$fk = (string)($_GET['f'] ?? '');
if (!isset($E['filters'][$fk])) $fk = array_key_first($E['filters']);
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 80) $q = mb_substr($q, 0, 80);
$page = max(1, (int)($_GET['page'] ?? 1));

$where = '(' . $E['filters'][$fk][1] . ')'; $p = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $E['search'])) . ')';
    $p = array_fill(0, count($E['search']), $like);
}
$rows = []; $total = 0; $fail = false;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM {$E['from']} WHERE $where"); $st->execute($p);
    $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT {$E['select']} FROM {$E['from']} WHERE $where ORDER BY {$E['order']} LIMIT " . ADM_PER_PAGE . " OFFSET " . (($page - 1) * ADM_PER_PAGE));
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('admin_list ' . $key . ': ' . $e->getMessage()); $fail = true; }
$pages = max(1, (int)ceil($total / ADM_PER_PAGE));
$qs = fn(array $o) => 'admin_list.php?' . http_build_query(array_filter(array_merge(['e' => $key, 'f' => $fk, 'q' => $q, 'page' => 1], $o), fn($v) => $v !== '' && $v !== null));

function cell(string $col, string $type, array $r): string {
    $v = $r[$col] ?? null;
    switch ($type) {
        case 'id': return '<span class="muted">#' . (int)$v . '</span>';
        case 'name': $s = trim((string)$v . ' ' . ($r['prenom'] ?? '')); return '<b>' . h($s !== '' ? $s : '—') . '</b>';
        case 'camp': return '<b>' . h($v) . '</b>' . (!empty($r['token']) ? ' <a class="ext" href="camp_details.php?t=' . h(rawurlencode((string)$r['token'])) . '" target="_blank" rel="noopener noreferrer" title="Voir la page publique">' . ic('external', 'ic ic-sm') . '</a>' : '');
        case 'money': return adm_money($v);
        case 'int': return (string)(int)$v;
        case 'date': return h(adm_date($v));
        case 'date0': return $v ? h(date('d/m/Y', strtotime((string)$v))) : '';
        case 'future': return ($v && strtotime((string)$v) > time()) ? '<span class="pill ok">' . h(adm_date($v)) . '</span>' : '<span class="muted">—</span>';
        case 'long': $t = (string)$v; return h(mb_strlen($t) > 90 ? mb_substr($t, 0, 90) . '…' : $t);
        case 'bool': return ((int)$v === 1) ? '<span class="pill ok">' . ic('check', 'ic ic-sm') . ' Oui</span>' : '<span class="pill">Non</span>';
        case 'done': return ((int)$v === 1) ? '<span class="pill ok">Effectué</span><div class="muted small">' . h(adm_date($r['date_virement_effectue'] ?? '')) . '</div>' : '<span class="pill warn">À traiter</span>';
        case 'status':
            $s = (string)$v; $c = in_array($s, ['Confirmé', 'PAYE', 'Traité'], true) ? 'ok' : (in_array($s, ['En attente', 'EN_ATTENTE', 'NON_DEFINI'], true) ? 'warn' : '');
            return '<span class="pill ' . $c . '">' . h($s) . '</span>';
        case 'role':
            $o = '';
            if (!empty($r['is_admin'])) $o .= '<span class="pill dark">Admin</span> ';
            if (!empty($r['is_directeur'])) $o .= '<span class="pill ok">Directeur</span> ';
            if (!empty($r['is_animateur'])) $o .= '<span class="pill">Animateur</span> ';
            if (!empty($r['demande_en_cours']) && empty($r['is_directeur'])) $o .= '<span class="pill warn">Demande directeur</span> ';
            return $o !== '' ? $o : '<span class="muted">Parent</span>';
        case 'campstate':
            if (!empty($r['supprime'])) return '<span class="pill">Supprimé</span>';
            $o = !empty($r['valide']) ? '<span class="pill ok">Validé</span>' : (!empty($r['refuse']) ? '<span class="pill bad">Refusé</span>' : '<span class="pill warn">En attente</span>');
            return $o . (!empty($r['prive']) ? ' <span class="pill">Masqué</span>' : '');
        default: return h($v);
    }
}

adm_head($E['label']);
?>
<form class="bar" method="get" action="admin_list.php">
  <input type="hidden" name="e" value="<?= h($key) ?>">
  <label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher…" maxlength="80"></label>
  <select class="in sel" name="f" aria-label="Filtre"><?php foreach ($E['filters'] as $fkey => $f): ?><option value="<?= h($fkey) ?>"<?= $fkey === $fk ? ' selected' : '' ?>><?= h($f[0]) ?></option><?php endforeach; ?></select>
  <button class="btn" type="submit"><?= ic('search') ?> Filtrer</button>
  <?php if ($q !== ''): ?><a class="btn ghost" href="<?= h($qs(['q' => ''])) ?>"><?= ic('x') ?> Effacer</a><?php endif; ?>
  <span class="count"><?= number_format($total, 0, ',', ' ') ?> résultat<?= $total > 1 ? 's' : '' ?></span>
</form>

<?php if ($fail): ?><div class="note err">Impossible de charger cette liste (structure de table inattendue). Le détail est dans le journal du serveur.</div><?php endif; ?>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><?php foreach ($E['cols'] as $c): ?><th><?= h($c[0]) ?></th><?php endforeach; ?><th class="act-h">Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows && !$fail): ?><tr><td colspan="<?= count($E['cols']) + 1 ?>" class="empty"><?= ic($E['icon'], 'ic ic-xl') ?><p>Rien à afficher pour ce filtre.</p></td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      if ($key === 'virements') $r['commission'] = (float)$r['montant_total'] - (float)$r['montant_apres_commission'];
      $id = (int)$r['id'];
  ?>
    <tr>
      <?php foreach ($E['cols'] as $col => [$label, $type]): ?><td data-l="<?= h($label) ?>"><?= cell($col, $type, $r) ?></td><?php endforeach; ?>
      <td class="acts">
        <?php if (!empty($E['edit'])): ?><a class="ib" href="admin_db.php?t=<?= h(rawurlencode($E['table'])) ?>&amp;pk=<?= $id ?>" title="Éditer la fiche" aria-label="Éditer"><?= ic('edit') ?></a><?php endif; ?>
        <?php foreach ($E['actions'] as [$act, $label, $icon, $tone, $cond, $sens, $confirm]):
            if (!$cond($r)) continue; ?>
          <button type="button" class="ib <?= h($tone) ?>" data-act="<?= h($act) ?>" data-id="<?= $id ?>" data-e="<?= h($key) ?>" title="<?= h($label) ?>" aria-label="<?= h($label) ?>"
            <?= $confirm !== '' ? 'data-confirm="' . h($confirm) . '"' : '' ?>
            <?= $act === 'contact.reply' ? 'data-from="' . h(trim($r['prenom'] . ' ' . $r['nom']) . ' <' . $r['email'] . '>') . '" data-msg="' . h($r['message']) . '"' : '' ?>
            <?= $act === 'virement.iban' ? 'data-name="' . h($r['nom_organisme']) . '"' : '' ?>><?= ic($icon) ?></button>
        <?php endforeach; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pagination">
  <?php if ($page > 1): ?><a class="btn ghost" href="<?= h($qs(['page' => $page - 1])) ?>"><?= ic('back') ?> Précédent</a><?php endif; ?>
  <span>Page <?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn ghost" href="<?= h($qs(['page' => $page + 1])) ?>">Suivant <?= ic('next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php adm_foot(['page' => 'list']); ?>
