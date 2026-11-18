<?php
if (!defined('ADMIN_LIB')) { http_response_code(404); exit; }

$types = ['paiement' => 'Paiement', 'commission' => 'Commission', 'frais_paiement' => 'Frais Stripe (organisme)', 'remboursement' => 'Remboursement', 'ajustement' => 'Ajustement', 'versement' => 'Versement', 'frais' => 'Frais Stripe (moncamp.fr)', 'dette' => 'Dette', 'transfert' => 'Transfert'];
$soldeTypes = ['paiement', 'commission', 'frais_paiement', 'remboursement', 'ajustement', 'versement'];
$sortLink = function (string $col, string $label, string $cur, string $dir) {
    $nd = $cur === $col && $dir === 'desc' ? 'asc' : 'desc';
    return '<a class="sort' . ($cur === $col ? ' on' : '') . '" href="' . h(cu(['tri' => $col, 'sens' => $nd, 'page' => ''])) . '">' . h($label) . ($cur === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '') . '</a>';
};
$payJoin = "LEFT JOIN fin_paiements p ON p.inscription_id = IF(l.objet_type = 'equipe', -CAST(l.objet_id AS SIGNED), CAST(l.objet_id AS SIGNED)) AND l.objet_type IN ('inscription', 'equipe')";
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;
$qq = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$pagerUrl = fn($n) => cu(['page' => $n]);
$exportQs = fn(array $o) => 'admin_compta_export.php?' . http_build_query(array_filter($o + ['p' => $pk] + ($pk === 'perso' ? ['du' => substr($from, 0, 10), 'au' => substr($to, 0, 10)] : []) + ($orgaTok ? ['o' => $orgaTok] : []), fn($x) => $x !== '' && $x !== null));
$expBtns = function (string $t, array $extra = []) use ($exportQs) {
    if (!adm_can('compta.export')) return '';
    return '<span class="exp">' . adm_help('Exports de la sélection affichée (période, organisme et filtres). Ils demandent votre mot de passe.') . ' <a class="btn ghost sm" href="' . h($exportQs(['t' => $t, 'f' => 'csv'] + $extra)) . '">' . ic('download') . ' CSV</a> <a class="btn ghost sm" href="' . h($exportQs(['t' => $t, 'f' => 'xlsx'] + $extra)) . '">' . ic('download') . ' Excel</a></span>';
};

if ($v === 'journal'):
    $ty = isset($types[$_GET['ty'] ?? '']) ? (string)$_GET['ty'] : '';
    $mo = in_array($_GET['mo'] ?? '', ['direct', 'destination', 'legacy', 'hors'], true) ? (string)$_GET['mo'] : '';
    $min = is_numeric(str_replace(',', '.', (string)($_GET['min'] ?? ''))) ? (int)round(100 * (float)str_replace(',', '.', (string)$_GET['min'])) : null;
    $max = is_numeric(str_replace(',', '.', (string)($_GET['max'] ?? ''))) ? (int)round(100 * (float)str_replace(',', '.', (string)$_GET['max'])) : null;
    $sorts = ['date' => 'l.created_at', 'montant' => 'l.montant_cents', 'type' => 'l.type', 'orga' => 'o.nom'];
    $tri = isset($sorts[$_GET['tri'] ?? '']) ? (string)$_GET['tri'] : 'date';
    $sens = ($_GET['sens'] ?? '') === 'asc' ? 'asc' : 'desc';
    $w = 'l.created_at BETWEEN ? AND ?'; $p = [$from, $to];
    if ($orgaId) { $w .= ' AND l.organisateur_id = ?'; $p[] = $orgaId; }
    if ($ty !== '') { $w .= ' AND l.type = ?'; $p[] = $ty; }
    if ($mo === 'hors') $w .= " AND l.reference LIKE 'hors:%'";
    elseif ($mo !== '') { $w .= ' AND l.mode = ?'; $p[] = $mo; }
    if ($min !== null) { $w .= ' AND ABS(l.montant_cents) >= ?'; $p[] = abs($min); }
    if ($max !== null) { $w .= ' AND ABS(l.montant_cents) <= ?'; $p[] = abs($max); }
    if ($qq !== '') { $lk = '%' . addcslashes($qq, '%_\\') . '%'; $w .= ' AND (l.reference LIKE ? OR l.libelle LIKE ? OR l.acteur LIKE ? OR o.nom LIKE ?)'; array_push($p, $lk, $lk, $lk, $lk); }
    $total = (int)adm_one("SELECT COUNT(*) FROM fin_ledger l LEFT JOIN organisateurs o ON o.id = l.organisateur_id WHERE $w", $p);
    $sums = adm_rows("SELECT l.type, COUNT(*) n, SUM(l.montant_cents) s FROM fin_ledger l LEFT JOIN organisateurs o ON o.id = l.organisateur_id WHERE $w GROUP BY l.type ORDER BY l.type", $p);
    $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $rows = adm_rows("SELECT l.*, o.nom orga, p.id pid FROM fin_ledger l LEFT JOIN organisateurs o ON o.id = l.organisateur_id $payJoin WHERE $w ORDER BY {$sorts[$tri]} $sens, l.id $sens LIMIT $per OFFSET " . (($page - 1) * $per), $p);
?>
<form class="bar" method="get" action="admin_compta.php">
  <?php foreach (['v' => 'journal', 'p' => $pk, 'o' => $orgaTok, 'du' => $pk === 'perso' ? substr($from, 0, 10) : '', 'au' => $pk === 'perso' ? substr($to, 0, 10) : ''] as $k => $x) if ($x !== '') echo '<input type="hidden" name="' . h($k) . '" value="' . h($x) . '">'; ?>
  <label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($qq) ?>" placeholder="Référence, libellé, organisme…" maxlength="80"></label>
  <select class="in sel" name="ty" aria-label="Type"><option value="">Tous les types</option><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"<?= $k === $ty ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <select class="in sel" name="mo" aria-label="Mode"><option value="">Tous les modes</option><?php foreach (['direct' => 'Direct', 'destination' => 'Destination', 'legacy' => 'Ancien', 'hors' => 'Hors carte'] as $k => $l): ?><option value="<?= $k ?>"<?= $k === $mo ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <label class="fld-inline">Montant <input class="in dt num-in" name="min" inputmode="decimal" placeholder="min" value="<?= h((string)($_GET['min'] ?? '')) ?>"> – <input class="in dt num-in" name="max" inputmode="decimal" placeholder="max" value="<?= h((string)($_GET['max'] ?? '')) ?>"></label>
  <button class="btn" type="submit"><?= ic('filter') ?> Filtrer</button>
  <?= $expBtns('ledger', ['ty' => $ty, 'mo' => $mo, 'q' => $qq, 'min' => $_GET['min'] ?? '', 'max' => $_GET['max'] ?? '']) ?>
</form>
<div class="kpis compact">
<?php foreach ($sums as $s): ?><div class="kpi"><div><b class="<?= (int)$s['s'] < 0 ? 'neg' : '' ?>"><?= $e($s['s']) ?></b><span><?= h($types[$s['type']] ?? $s['type']) ?> · <?= (int)$s['n'] ?> ligne<?= $s['n'] > 1 ? 's' : '' ?></span></div></div><?php endforeach; ?>
</div>
<?php if (!$rows): ?><?= adm_empty('list', 'Aucune écriture pour ces critères.') ?><?php else: ?>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th><?= $sortLink('date', 'Date', $tri, $sens) ?></th><th><?= $sortLink('orga', 'Organisme', $tri, $sens) ?></th><th><?= $sortLink('type', 'Type', $tri, $sens) ?></th><th>Libellé</th><th>Référence</th><th>Mode</th><th class="num"><?= $sortLink('montant', 'Montant', $tri, $sens) ?></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td>
      <td data-l="Organisme"><?php if ($r['orga']): ?><a class="lnk-q" href="<?= h(cu(['v' => 'grandlivre', 'o' => fin_orga_tok((int)$r['organisateur_id']), 'page' => ''])) ?>"><?= h($r['orga']) ?></a><?php else: ?>—<?php endif; ?></td>
      <td data-l="Type"><span class="pill"><?= h($types[$r['type']] ?? $r['type']) ?></span></td>
      <td data-l="Libellé"><?= h($r['libelle']) ?><div class="muted small"><?= h($r['acteur']) ?></div></td>
      <td data-l="Référence" class="mono small wrap"><?= $r['pid'] && adm_can('paiements.read') ? '<a class="lnk-q" href="admin_paiement.php?t=' . h(adm_tok('pay', (int)$r['pid'])) . '">' . h($r['reference']) . '</a>' : h($r['reference']) ?></td>
      <td data-l="Mode" class="small"><?= h((string)$r['mode']) ?></td>
      <td data-l="Montant" class="num<?= (int)$r['montant_cents'] < 0 ? ' neg' : '' ?>"><b><?= $e($r['montant_cents']) ?></b></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<p class="muted small"><?= number_format($total, 0, ',', ' ') ?> écriture(s). Montants vus par l'organisme : négatif = sort de son solde. <?= adm_help('Le journal est en ajout seulement : une écriture n’est jamais modifiée ni supprimée. Une correction passe par un ajustement.') ?></p>
<?= adm_pager($page, $pages, $pagerUrl) ?>
<?php endif; ?>

<?php elseif ($v === 'grandlivre'):
    if (!$orgaId): ?>
<form class="bar" method="get" action="admin_compta.php"><input type="hidden" name="v" value="grandlivre"><input type="hidden" name="p" value="<?= h($pk) ?>">
  <select class="in sel" name="o" required aria-label="Organisme"><option value="">Choisir un organisme…</option><?php foreach ($pdo->query("SELECT id, nom FROM organisateurs ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) as $o): ?><option value="<?= h(fin_orga_tok((int)$o['id'])) ?>"><?= h($o['nom']) ?></option><?php endforeach; ?></select>
  <button class="btn" type="submit">Ouvrir le grand livre</button></form>
<?= adm_empty('building', 'Choisissez un organisme pour afficher son grand livre.') ?>
<?php else:
    $in = "'" . implode("','", $soldeTypes) . "'";
    $ouv = (int)adm_one("SELECT COALESCE(SUM(montant_cents), 0) FROM fin_ledger WHERE organisateur_id = ? AND created_at < ? AND type IN ($in)", [$orgaId, $from]);
    $tot = (int)adm_one("SELECT COUNT(*) FROM fin_ledger WHERE organisateur_id = ? AND created_at BETWEEN ? AND ?", [$orgaId, $from, $to]);
    $pages = max(1, (int)ceil($tot / $per)); $page = min($page, $pages);
    $avant = (int)adm_one("SELECT COALESCE(SUM(montant_cents), 0) FROM (SELECT montant_cents, type FROM fin_ledger WHERE organisateur_id = ? AND created_at BETWEEN ? AND ? ORDER BY created_at, id LIMIT " . (($page - 1) * $per) . ") x WHERE type IN ($in)", [$orgaId, $from, $to]);
    $rows = adm_rows("SELECT l.*, p.id pid FROM fin_ledger l $payJoin WHERE l.organisateur_id = ? AND l.created_at BETWEEN ? AND ? ORDER BY l.created_at, l.id LIMIT $per OFFSET " . (($page - 1) * $per), [$orgaId, $from, $to]);
    $byType = adm_rows("SELECT type, SUM(montant_cents) s FROM fin_ledger WHERE organisateur_id = ? AND created_at BETWEEN ? AND ? GROUP BY type", [$orgaId, $from, $to]);
    $fin = $ouv + array_sum(array_map(fn($r) => in_array($r['type'], $soldeTypes, true) ? (int)$r['s'] : 0, $byType));
    $orgNom = (string)adm_one("SELECT nom FROM organisateurs WHERE id = ?", [$orgaId]);
    $solde = $ouv + $avant;
?>
<div class="row-l wrap-row"><h2 class="h3 grow"><?= h($orgNom) ?> <?= adm_help('Solde = ce que l’organisme a gagné moins ce qui lui a été versé (paiements, commissions, frais à sa charge, remboursements, ajustements, versements). Les lignes grisées sont informatives et hors solde.') ?></h2>
  <?php if (adm_can('orgas.read')): ?><a class="btn ghost sm" href="admin_orga.php?t=<?= h($orgaTok) ?>"><?= ic('building') ?> Fiche organisme</a><?php endif; ?>
  <?= $expBtns('grandlivre') ?></div>
<div class="kpis compact">
  <div class="kpi"><div><b><?= $e($ouv) ?></b><span>Solde d'ouverture</span></div></div>
  <?php foreach ($byType as $b): ?><div class="kpi"><div><b class="<?= (int)$b['s'] < 0 ? 'neg' : '' ?>"><?= $e($b['s']) ?></b><span><?= h($types[$b['type']] ?? $b['type']) ?><?= in_array($b['type'], $soldeTypes, true) ? '' : ' (hors solde)' ?></span></div></div><?php endforeach; ?>
  <div class="kpi"><div><b><?= $e($fin) ?></b><span>Solde de clôture</span></div></div>
</div>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th>Date</th><th>Type</th><th>Libellé</th><th>Référence</th><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Solde</th></tr></thead>
  <tbody>
  <?php if ($page === 1): ?><tr><td colspan="6"><b>Report au <?= h(date('d/m/Y', strtotime($from))) ?></b></td><td class="num"><b><?= $e($ouv) ?></b></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $m = (int)$r['montant_cents']; $inS = in_array($r['type'], $soldeTypes, true); if ($inS) $solde += $m; ?>
    <tr class="<?= $inS ? '' : 'dim' ?>"><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Type"><?= h($types[$r['type']] ?? $r['type']) ?></td><td data-l="Libellé"><?= h($r['libelle']) ?></td>
      <td data-l="Référence" class="mono small wrap"><?= $r['pid'] && adm_can('paiements.read') ? '<a class="lnk-q" href="admin_paiement.php?t=' . h(adm_tok('pay', (int)$r['pid'])) . '">' . h($r['reference']) . '</a>' : h($r['reference']) ?></td>
      <td data-l="Débit" class="num"><?= $m < 0 ? $e(-$m) : '' ?></td><td data-l="Crédit" class="num"><?= $m > 0 ? $e($m) : '' ?></td><td data-l="Solde" class="num"><?= $inS ? $e($solde) : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty"><p>Aucun mouvement sur la période.</p></td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= adm_pager($page, $pages, $pagerUrl) ?>
<?= adm_notes_box('orga', $orgaTok, adm_notes('orga', $orgaTok)) ?>
<?php endif; ?>

<?php elseif ($v === 'paiements'):
    $sts = ['attente' => 'En attente', 'bloque' => 'Bloqué', 'litige' => 'Litige', 'en_cours' => 'En cours', 'verse' => 'Versé', 'rembourse' => 'Remboursé'];
    $f = isset($sts[$_GET['f'] ?? '']) ? (string)$_GET['f'] : '';
    $mo = in_array($_GET['mo'] ?? '', ['direct', 'destination', 'legacy'], true) ? (string)$_GET['mo'] : '';
    $sorts = ['date' => 'p.created_at', 'brut' => 'p.brut_cents', 'orga' => 'o.nom', 'marge' => 'marge'];
    $tri = isset($sorts[$_GET['tri'] ?? '']) ? (string)$_GET['tri'] : 'date';
    $sens = ($_GET['sens'] ?? '') === 'asc' ? 'asc' : 'desc';
    $w = 'p.created_at BETWEEN ? AND ?'; $p = [$from, $to];
    if ($f === 'litige') { $w = '1=1'; $p = []; }
    if ($orgaId) { $w .= ' AND p.organisateur_id = ?'; $p[] = $orgaId; }
    if ($f !== '') { $w .= ' AND p.statut = ?'; $p[] = $f; }
    if ($mo !== '') { $w .= ' AND p.mode = ?'; $p[] = $mo; }
    if ($qq !== '') { $lk = '%' . addcslashes($qq, '%_\\') . '%'; $w .= ' AND (p.payment_intent LIKE ? OR p.session_id LIKE ? OR o.nom LIKE ? OR c.nom LIKE ?)'; array_push($p, $lk, $lk, $lk, $lk); }
    $from_ = "FROM fin_paiements p LEFT JOIN organisateurs o ON o.id = p.organisateur_id LEFT JOIN camps c ON c.id = p.camp_id WHERE $w";
    $agg = adm_rows("SELECT COUNT(*) n, COALESCE(SUM(p.brut_cents), 0) brut, COALESCE(SUM(p.commission_cents), 0) com, COALESCE(SUM(p.rembourse_cents), 0) remb $from_", $p)[0] ?? ['n' => 0, 'brut' => 0, 'com' => 0, 'remb' => 0];
    $pages = max(1, (int)ceil($agg['n'] / $per)); $page = min($page, $pages);
    $rows = adm_rows("SELECT p.id, p.created_at, p.brut_cents, p.commission_cents, p.frais_stripe_cents, p.rembourse_cents, p.statut, p.mode, p.payment_intent, o.nom orga, c.nom camp, " . fin_sql_marge('p') . " marge $from_ ORDER BY {$sorts[$tri]} $sens, p.id $sens LIMIT $per OFFSET " . (($page - 1) * $per), $p);
?>
<form class="bar" method="get" action="admin_compta.php">
  <?php foreach (['v' => 'paiements', 'p' => $pk, 'o' => $orgaTok, 'du' => $pk === 'perso' ? substr($from, 0, 10) : '', 'au' => $pk === 'perso' ? substr($to, 0, 10) : ''] as $k => $x) if ($x !== '') echo '<input type="hidden" name="' . h($k) . '" value="' . h($x) . '">'; ?>
  <label class="search"><?= ic('search') ?><input class="in" type="search" name="q" value="<?= h($qq) ?>" placeholder="pi_…, cs_…, organisme, séjour" maxlength="80"></label>
  <select class="in sel" name="f" aria-label="Statut"><option value="">Tous les statuts</option><?php foreach ($sts as $k => $l): ?><option value="<?= $k ?>"<?= $k === $f ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <select class="in sel" name="mo" aria-label="Mode"><option value="">Tous les modes</option><?php foreach (['direct' => 'Direct', 'destination' => 'Destination', 'legacy' => 'Ancien'] as $k => $l): ?><option value="<?= $k ?>"<?= $k === $mo ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <button class="btn" type="submit"><?= ic('filter') ?> Filtrer</button>
  <?= $expBtns('paiements') ?>
</form>
<?php if ($f === 'litige'): ?><div class="note warn"><?= ic('alert') ?> Litiges en cours, toutes périodes confondues. Répondez aux contestations dans le tableau de bord Stripe avant l'échéance.</div><?php endif; ?>
<div class="kpis compact">
  <div class="kpi"><div><b><?= number_format((int)$agg['n'], 0, ',', ' ') ?></b><span>Paiements</span></div></div>
  <div class="kpi"><div><b><?= $e($agg['brut']) ?></b><span>Encaissé brut</span></div></div>
  <div class="kpi"><div><b><?= $e($agg['com']) ?></b><span>Commissions</span></div></div>
  <div class="kpi"><div><b class="<?= $agg['remb'] ? 'neg' : '' ?>"><?= $e($agg['remb']) ?></b><span>Remboursé</span></div></div>
</div>
<?php if (!$rows): ?><?= adm_empty('card', 'Aucun paiement pour ces critères.') ?><?php else: ?>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th><?= $sortLink('date', 'Date', $tri, $sens) ?></th><th><?= $sortLink('orga', 'Organisme', $tri, $sens) ?></th><th>Séjour</th><th class="num"><?= $sortLink('brut', 'Brut', $tri, $sens) ?></th><th class="num">Commission</th><th class="num">Frais</th><th class="num">Remboursé</th><th class="num"><?= $sortLink('marge', 'Marge', $tri, $sens) ?></th><th>Statut</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Organisme"><?= h($r['orga']) ?></td><td data-l="Séjour" class="small"><?= h($r['camp']) ?></td>
      <td class="num" data-l="Brut"><b><?= $e($r['brut_cents']) ?></b></td><td class="num" data-l="Commission"><?= $e($r['commission_cents']) ?></td>
      <td class="num" data-l="Frais"><?= $r['frais_stripe_cents'] === null ? '<span class="muted">—</span>' : $e($r['frais_stripe_cents']) ?></td>
      <td class="num<?= $r['rembourse_cents'] ? ' neg' : '' ?>" data-l="Remboursé"><?= $r['rembourse_cents'] ? $e($r['rembourse_cents']) : '' ?></td>
      <td class="num<?= (int)$r['marge'] < 0 ? ' neg' : '' ?>" data-l="Marge"><?= $e($r['marge']) ?></td>
      <td data-l="Statut"><span class="pill <?= ['litige' => 'bad', 'rembourse' => 'warn', 'verse' => 'ok'][$r['statut']] ?? '' ?>"><?= h($sts[$r['statut']] ?? $r['statut']) ?></span> <span class="muted small"><?= h($r['mode']) ?></span></td>
      <td class="acts"><a class="ib" href="admin_paiement.php?t=<?= h(adm_tok('pay', (int)$r['id'])) ?>" title="Détail" aria-label="Détail"><?= ic('eye') ?></a></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= adm_pager($page, $pages, $pagerUrl) ?>
<?php endif; ?>

<?php elseif ($v === 'remboursements'):
    $w = "l.type = 'remboursement' AND l.created_at BETWEEN ? AND ?" . ($orgaId ? ' AND l.organisateur_id = ' . (int)$orgaId : '');
    $agg = adm_rows("SELECT COUNT(*) n, COALESCE(-SUM(l.montant_cents), 0) s FROM fin_ledger l WHERE $w", [$from, $to])[0];
    $comR = (int)adm_one("SELECT COALESCE(SUM(l.montant_cents), 0) FROM fin_ledger l WHERE l.type = 'commission' AND l.montant_cents > 0 AND l.reference LIKE 'rem:%' AND l.created_at BETWEEN ? AND ?" . ($orgaId ? ' AND l.organisateur_id = ' . (int)$orgaId : ''), [$from, $to]);
    $pages = max(1, (int)ceil($agg['n'] / $per)); $page = min($page, $pages);
    $rows = adm_rows("SELECT l.created_at, l.montant_cents, l.acteur, l.reference, o.nom orga, p.id pid, p.brut_cents, a.motif, a.pct FROM fin_ledger l LEFT JOIN organisateurs o ON o.id = l.organisateur_id $payJoin
        LEFT JOIN fin_annulations a ON a.inscription_id = p.inscription_id WHERE $w ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), [$from, $to]);
?>
<div class="kpis compact">
  <div class="kpi"><div><b><?= (int)$agg['n'] ?></b><span>Remboursements</span></div></div>
  <div class="kpi"><div><b class="neg"><?= $e($agg['s']) ?></b><span>Rendu aux familles</span></div></div>
  <div class="kpi"><div><b><?= $e($comR) ?></b><span>Commission rendue <?= adm_help('Part de commission moncamp.fr rendue lors des remboursements.') ?></span></div></div>
</div>
<p class="muted small">Les remboursements se déclenchent depuis l'annulation côté famille ou organisme, ou dans Stripe ; ils sont enregistrés ici automatiquement par le webhook.</p>
<?php if (!$rows): ?><?= adm_empty('refresh', 'Aucun remboursement sur la période.') ?><?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Organisme</th><th class="num">Payé</th><th class="num">Remboursé</th><th>Origine</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Organisme"><?= h($r['orga']) ?></td><td class="num" data-l="Payé"><?= $r['brut_cents'] !== null ? $e($r['brut_cents']) : '' ?></td>
    <td class="num neg" data-l="Remboursé"><b><?= $e(-(int)$r['montant_cents']) ?></b></td>
    <td data-l="Origine" class="small"><?= $r['motif'] ? 'Annulation (' . h($r['motif']) . ', ' . (int)$r['pct'] . ' %)' : h($r['acteur']) ?></td>
    <td class="acts"><?php if ($r['pid']): ?><a class="ib" href="admin_paiement.php?t=<?= h(adm_tok('pay', (int)$r['pid'])) ?>" title="Détail" aria-label="Détail"><?= ic('eye') ?></a><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?= adm_pager($page, $pages, $pagerUrl) ?>
<?php endif; ?>

<?php elseif ($v === 'dettes'):
    $vs = compta_versements($pdo);
    $neg = array_filter($vs['orgas'], fn($o) => (float)$o['portefeuille'] < -0.004);
    $hist = adm_rows("SELECT l.created_at, l.montant_cents, l.libelle, o.nom orga FROM fin_ledger l LEFT JOIN organisateurs o ON o.id = l.organisateur_id WHERE l.type = 'dette' ORDER BY l.id DESC LIMIT 30");
?>
<div class="kpis compact">
  <div class="kpi<?= $neg ? ' bad' : '' ?>"><div><b><?= h(adm_money(-array_sum(array_map(fn($o) => (float)$o['portefeuille'], $neg)))) ?></b><span>Dettes en cours</span></div></div>
  <div class="kpi"><div><b><?= count($neg) ?></b><span>Organismes débiteurs</span></div></div>
  <div class="kpi"><div><b><?= count($vs['dettes']) ?></b><span>Reversements impossibles</span></div></div>
</div>
<h2 class="sec"><?= ic('building') ?> Organismes débiteurs <?= adm_help('Solde moncamp.fr négatif : l’organisme doit cette somme. Elle est prélevée automatiquement sur ses prochains paiements par carte.') ?></h2>
<?php if (!$neg): ?><p class="muted">Aucune dette en cours.</p><?php else: ?>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Organisme</th><th class="num">Dette</th><th></th></tr></thead><tbody>
<?php foreach ($neg as $o): ?><tr><td data-l="Organisme"><b><?= h($o['nom']) ?></b></td><td class="num neg" data-l="Dette"><b><?= h(adm_money(-(float)$o['portefeuille'])) ?></b></td>
  <td class="acts"><a class="btn ghost sm" href="<?= h(cu(['v' => 'grandlivre', 'o' => fin_orga_tok((int)$o['id']), 'p' => 'tout'])) ?>">Grand livre</a></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<h2 class="sec"><?= ic('alert') ?> Reversements impossibles</h2>
<?php if (!$vs['dettes']): ?><p class="muted">Aucun.</p><?php else: ?>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Date</th><th>Organisme</th><th class="num">Reste dû</th><th></th></tr></thead><tbody>
<?php foreach ($vs['dettes'] as $d): ?><tr><td data-l="Date"><?= h(adm_date($d['created_at'])) ?></td><td data-l="Organisme"><?= h($d['orga']) ?></td><td class="num neg" data-l="Reste dû"><?= $e((int)$d['montant_cents'] - (int)$d['recupere_cents']) ?></td>
  <td class="acts"><?php if (adm_can('versements.write')): ?><form class="inl" method="post" action="admin_compta.php?v=dettes"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="retenter"><input type="hidden" name="rev" value="<?= h(adm_tok('rev', (int)$d['id'])) ?>"><button class="btn ghost sm" type="submit">Retenter</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<h2 class="sec"><?= ic('list') ?> Historique des dettes</h2>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Date</th><th>Organisme</th><th>Libellé</th><th class="num">Montant</th></tr></thead><tbody>
<?php foreach ($hist as $r): ?><tr><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Organisme"><?= h($r['orga']) ?></td><td data-l="Libellé"><?= h($r['libelle']) ?></td><td class="num" data-l="Montant"><?= $e($r['montant_cents']) ?></td></tr><?php endforeach; ?>
<?php if (!$hist): ?><tr><td colspan="4" class="empty"><p>Aucune écriture de dette.</p></td></tr><?php endif; ?>
</tbody></table></div>
<?php if (adm_can('paiements.write')): ?><p class="mt"><a class="btn ghost" href="<?= h(cu(['v' => 'versements'])) ?>#ajustement"><?= ic('edit') ?> Ajouter un ajustement ou une dette</a></p><?php endif; ?>

<?php elseif ($v === 'tva'):
    $taux = (float)str_replace(',', '.', (string)(adm_one("SELECT valeur FROM fin_reglages WHERE cle = 'tva_commission_pct'") ?: '20'));
    $mois = adm_rows("SELECT DATE_FORMAT(created_at, '%Y-%m') m, -SUM(IF(type = 'commission', montant_cents, 0)) com, -SUM(IF(type = 'commission' AND reference LIKE 'hors:%', montant_cents, 0)) hors, -SUM(IF(type = 'frais', montant_cents, 0)) frais
        FROM fin_ledger WHERE created_at BETWEEN ? AND ? GROUP BY m ORDER BY m DESC", [$from, $to]);
    $tva = fn(int $ttc) => (int)round($ttc * $taux / (100 + $taux));
    $T = ['com' => 0, 'tva' => 0];
?>
<div class="note"><?= ic('alert') ?><div>Commissions considérées <b>TTC</b>, taux <b><?= h(number_format($taux, 2, ',', ' ')) ?> %</b>. TVA collectée = TTC × taux / (100 + taux). À faire valider par votre expert-comptable (régime, franchise en base, autoliquidation).</div></div>
<?php if (!$mois): ?><?= adm_empty('euro', 'Aucune commission sur la période.') ?><?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Mois</th><th class="num">Commissions TTC</th><th class="num">Dont hors carte</th><th class="num">Base HT</th><th class="num">TVA collectée</th><th class="num">Frais Stripe <?= adm_help('Frais payés par moncamp.fr (anciens paiements). Les frais de paiement bancaires sont en général exonérés de TVA.') ?></th></tr></thead><tbody>
<?php foreach ($mois as $r): $c = (int)$r['com']; $t = $tva($c); $T['com'] += $c; $T['tva'] += $t; ?>
  <tr><td data-l="Mois"><b><?= h($r['m']) ?></b></td><td class="num" data-l="TTC"><?= $e($c) ?></td><td class="num" data-l="Hors carte"><?= $e($r['hors']) ?></td><td class="num" data-l="HT"><?= $e($c - $t) ?></td><td class="num" data-l="TVA"><b><?= $e($t) ?></b></td><td class="num" data-l="Frais"><?= $e($r['frais']) ?></td></tr>
<?php endforeach; ?>
  <tr class="tot"><td>Total</td><td class="num"><?= $e($T['com']) ?></td><td></td><td class="num"><?= $e($T['com'] - $T['tva']) ?></td><td class="num"><?= $e($T['tva']) ?></td><td></td></tr>
</tbody></table></div><?php endif; ?>
<?php if (adm_can('compta.settings')): ?>
<section class="card mt"><h2 class="sec"><?= ic('edit') ?> Paramètres TVA et FEC</h2>
  <form method="post" action="admin_compta.php?v=tva" class="row-l wrap-row"><input type="hidden" name="_csrf" value="<?= h(adm_csrf()) ?>"><input type="hidden" name="act" value="tva">
    <label class="fld-inline">Taux de TVA sur les commissions (%) <input class="in dt num-in" name="taux" inputmode="decimal" value="<?= h(number_format($taux, 2, ',', '')) ?>" required></label>
    <label class="fld-inline">SIREN de moncamp.fr <input class="in dt" name="siren" inputmode="numeric" maxlength="9" pattern="[0-9]{9}" value="<?= h((string)adm_one("SELECT valeur FROM fin_reglages WHERE cle = 'siren_plateforme'")) ?>"></label>
    <button class="btn ghost" type="submit"><?= ic('check') ?> Enregistrer</button></form></section>
<?php endif; ?>

<?php elseif ($v === 'cloture'):
    $mois = adm_rows("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n, -SUM(IF(type = 'commission', montant_cents, 0)) com, SUM(IF(type = 'paiement', montant_cents, 0)) enc, -SUM(IF(type = 'remboursement', montant_cents, 0)) remb
        FROM fin_ledger WHERE created_at >= (DATE_FORMAT(NOW(), '%Y-%m-01') - INTERVAL 17 MONTH) GROUP BY m ORDER BY m DESC");
    $cl = [];
    foreach (adm_rows("SELECT * FROM compta_clotures") as $c) $cl[$c['mois']] = $c;
    $cur = date('Y-m');
?>
<p class="muted">Clôturer un mois fige ses totaux et une empreinte de ses écritures. Si une écriture datée de ce mois arrive ensuite (webhook tardif, rattrapage), le mois passe en « modifié après clôture » pour que vous le contrôliez. <?= adm_help('Le journal étant en ajout seulement, rien n’est jamais effacé : la clôture sert de point de contrôle pour la comptabilité et les déclarations.') ?></p>
<?php if (!$mois): ?><?= adm_empty('lock', 'Aucune écriture sur les 18 derniers mois.') ?><?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Mois</th><th class="num">Écritures</th><th class="num">Encaissé</th><th class="num">Remboursé</th><th class="num">Commissions</th><th>État</th><th></th></tr></thead><tbody>
<?php foreach ($mois as $r): $c = $cl[$r['m']] ?? null; $drift = $c && !$c['rouvert_at'] && compta_cloture_empreinte($pdo, $r['m']) !== $c['empreinte']; $closed = $c && !$c['rouvert_at']; ?>
  <tr><td data-l="Mois"><b><?= h($r['m']) ?></b></td><td class="num" data-l="Écritures"><?= (int)$r['n'] ?></td><td class="num" data-l="Encaissé"><?= $e($r['enc']) ?></td><td class="num" data-l="Remboursé"><?= $e($r['remb']) ?></td><td class="num" data-l="Commissions"><?= $e($r['com']) ?></td>
    <td data-l="État"><?php if ($drift): ?><span class="pill bad">Modifié après clôture</span><?php elseif ($closed): ?><span class="pill ok"><?= ic('lock', 'ic ic-sm') ?> Clôturé</span><div class="muted small"><?= h($c['par_nom']) ?> · <?= h(adm_date($c['created_at'])) ?></div><?php elseif ($r['m'] === $cur): ?><span class="pill">En cours</span><?php else: ?><span class="pill warn">À clôturer</span><?php endif; ?></td>
    <td class="acts">
      <?php if (!$closed && $r['m'] < $cur && adm_can('compta.close')): ?><button class="btn sm" type="button" data-api="compta.close" data-mois="<?= h($r['m']) ?>" data-confirm="Clôturer <?= h($r['m']) ?> ? Les totaux seront figés.">Clôturer</button><?php endif; ?>
      <?php if ($closed && adm_is_supreme()): ?><button class="btn ghost sm" type="button" data-api="compta.reopen" data-mois="<?= h($r['m']) ?>" data-confirm="Rouvrir <?= h($r['m']) ?> ?"><?= $drift ? 'Rouvrir pour recontrôler' : 'Rouvrir' ?></button><?php endif; ?>
      <a class="btn ghost sm" href="<?= h(cu(['v' => 'journal', 'p' => 'perso', 'du' => $r['m'] . '-01', 'au' => date('Y-m-t', strtotime($r['m'] . '-01')), 'page' => ''])) ?>">Journal</a></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>

<?php elseif ($v === 'recherche'):
    $res = [];
    if (mb_strlen($qq) >= 3) {
        $lk = '%' . addcslashes($qq, '%_\\') . '%';
        $amt = preg_match('/^\d+([.,]\d{1,2})?$/', $qq) ? (int)round(100 * (float)str_replace(',', '.', $qq)) : -1;
        $res = adm_rows("SELECT DISTINCT p.id, p.created_at, p.brut_cents, p.statut, p.payment_intent, p.session_id, o.nom orga, c.nom camp
            FROM fin_paiements p LEFT JOIN organisateurs o ON o.id = p.organisateur_id LEFT JOIN camps c ON c.id = p.camp_id LEFT JOIN inscriptions i ON i.id = p.inscription_id
            LEFT JOIN enfants en ON en.id = i.enfant_id LEFT JOIN users u ON u.id = en.parent_id
            WHERE p.payment_intent LIKE ? OR p.session_id LIKE ? OR i.token = ? OR u.email LIKE ? OR p.brut_cents = ? OR o.nom LIKE ?
               OR EXISTS (SELECT 1 FROM fin_ledger l WHERE l.reference LIKE ? AND l.objet_id = CAST(ABS(p.inscription_id) AS CHAR))
            ORDER BY p.id DESC LIMIT 60", [$lk, $lk, $qq, $lk, $amt, $lk, $lk]);
        $trs = adm_rows("SELECT t.created_at, t.montant_cents, t.statut, t.stripe_transfer_id, t.type, o.nom orga FROM fin_transferts t LEFT JOIN organisateurs o ON o.id = t.organisateur_id
            WHERE t.stripe_transfer_id LIKE ? OR t.stripe_account_id LIKE ? OR t.montant_cents = ? ORDER BY t.id DESC LIMIT 20", [$lk, $lk, $amt]);
    }
?>
<?php if (mb_strlen($qq) < 3): ?><?= adm_empty('search', 'Tapez au moins 3 caractères : pi_…, cs_…, tr_…, e-mail de la famille, jeton d’inscription, organisme ou montant (ex. 245,50).') ?>
<?php else: ?>
<h2 class="sec"><?= ic('card') ?> Paiements <span class="count-pill"><?= count($res) ?></span></h2>
<?php if (!$res): ?><p class="muted">Aucun paiement.</p><?php else: ?>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Date</th><th>Organisme</th><th>Séjour</th><th class="num">Montant</th><th>Statut</th><th>Référence Stripe</th><th></th></tr></thead><tbody>
<?php foreach ($res as $r): ?><tr><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Organisme"><?= h($r['orga']) ?></td><td data-l="Séjour" class="small"><?= h($r['camp']) ?></td><td class="num" data-l="Montant"><b><?= $e($r['brut_cents']) ?></b></td><td data-l="Statut"><?= h($r['statut']) ?></td><td class="mono small wrap" data-l="Stripe"><?= h($r['payment_intent'] ?: $r['session_id']) ?></td>
  <td class="acts"><a class="ib" href="admin_paiement.php?t=<?= h(adm_tok('pay', (int)$r['id'])) ?>" title="Détail" aria-label="Détail"><?= ic('eye') ?></a></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php if (!empty($trs)): ?><h2 class="sec"><?= ic('bank') ?> Transferts et versements</h2>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Date</th><th>Organisme</th><th>Type</th><th class="num">Montant</th><th>Statut</th><th>Référence</th></tr></thead><tbody>
<?php foreach ($trs as $r): ?><tr><td data-l="Date" class="small"><?= h(adm_date($r['created_at'])) ?></td><td data-l="Organisme"><?= h($r['orga']) ?></td><td data-l="Type"><?= h($r['type']) ?></td><td class="num" data-l="Montant"><?= $e($r['montant_cents']) ?></td><td data-l="Statut"><?= h($r['statut']) ?></td><td class="mono small" data-l="Référence"><?= h($r['stripe_transfer_id']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
