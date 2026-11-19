<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/orga_lib.php';
require_once __DIR__ . '/api/clics.php';
adm_guard(false, 'partenaires.read');
clics_ensure($pdo);
$PER = ['7' => '7 jours', '30' => '30 jours', '90' => '90 jours', '365' => '12 mois', 'tout' => 'Depuis le début'];
$per = isset($PER[$_GET['p'] ?? '']) ? (string)$_GET['p'] : '30';
$dv = fn($v) => ($d = DateTime::createFromFormat('Y-m-d', (string)$v)) && $d->format('Y-m-d') === $v ? $v : null;
$perso = $dv($_GET['du'] ?? '') && $dv($_GET['au'] ?? '');
$au = $perso ? $_GET['au'] : date('Y-m-d');
$du = $perso ? $_GET['du'] : ($per === 'tout' ? (string)(adm_q("SELECT DATE(MIN(created_at)) FROM clics_sortants") ?: $au) : date('Y-m-d', strtotime($au . ' -' . ((int)$per - 1) . ' days')));
if ($du > $au) [$du, $au] = [$au, $du];
if (strtotime($au) - strtotime($du) > 6 * 366 * 86400) $du = date('Y-m-d', strtotime($au . ' -6 years'));
$oTok = (string)($_GET['o'] ?? '');
$oid = $oTok !== '' ? fin_orga_from_tok($pdo, $oTok) : 0;
$oNom = $oid ? (string)adm_q("SELECT nom FROM organisateurs WHERE id = ?", [$oid]) : '';
$q = array_filter(['p' => $perso ? '' : $per, 'du' => $perso ? $du : '', 'au' => $perso ? $au : '', 'o' => $oid ? $oTok : ''], fn($v) => $v !== '');
$url = fn(array $o = []) => 'admin_clics.php?' . http_build_query(array_filter(array_merge($q, $o), fn($v) => $v !== '' && $v !== null));
if (($_GET['export'] ?? '') === 'csv') { adm_audit('clics_export', $oid ? "organisateurs#$oid" : null, "$du → $au"); clics_csv($pdo, $du, $au, $oid ?: null); }

$S = clics_stats($pdo, $du, $au, $oid ?: null);
$eur = fn(int $c) => adm_money($c / 100);
$fr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '';

$mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
$g = count($S['jours']) <= 45 ? 'j' : (count($S['jours']) <= 200 ? 's' : 'm');
$B = [];
foreach ($S['jours'] as $d => $n) {
    $t = strtotime($d);
    $k = $g === 'j' ? $d : ($g === 's' ? date('o-W', $t) : date('Y-m', $t));
    $B[$k] ??= [$g === 'm' ? $mois[date('n', $t) - 1] . ' ' . date('y', $t) : ($g === 's' ? 'sem. ' : '') . date('d/m', $g === 's' ? strtotime('monday this week', $t) : $t), 0];
    $B[$k][1] += $n;
}
$B = array_values($B);
$W = 800; $H = 190; $pb = 22; $pt = 16; $n = max(1, count($B)); $max = max([1, ...array_column($B, 1)]);
$bw = $W / $n; $gap = min(4, $bw * .2); $step = (int)ceil($n / 10);
$y = fn($v) => round($H - $pb - $v / $max * ($H - $pt - $pb), 1);

adm_head('Clics vers les organismes');
?>
<div class="toolbar">
  <div class="seg"><?php foreach ($PER as $k => $l): ?><a class="<?= !$perso && $k === $per ? 'on' : '' ?>" href="<?= h($url(['p' => $k, 'du' => null, 'au' => null])) ?>"><?= h($l) ?></a><?php endforeach; ?></div>
  <form class="bar inl-bar" method="get" action="admin_clics.php"><?php if ($oid): ?><input type="hidden" name="o" value="<?= h($oTok) ?>"><?php endif; ?>
    <label class="fld-inline">Du <input class="in dt" type="date" name="du" value="<?= h($du) ?>" required></label>
    <label class="fld-inline">au <input class="in dt" type="date" name="au" value="<?= h($au) ?>" required></label>
    <button class="btn ghost sm" type="submit">Afficher</button></form>
  <?= adm_help('Clics sur « Réserver ma place » des séjours à réservation externe (redirection /go/…, robots exclus). Chaque lien porte utm_source=moncamp.fr, utm_medium=referral, utm_campaign=<jeton du séjour> et mc_click=<identifiant du clic>.') ?>
  <a class="btn ghost sm" href="<?= h($url(['export' => 'csv'])) ?>"><?= ic('download') ?> CSV</a>
</div>
<?php if ($oid): ?><p class="row-l wrap-row">Organisme : <b><?= h($oNom) ?></b> <a class="lnk-q" href="<?= h($url(['o' => null])) ?>">tous les organismes</a></p><?php endif; ?>

<div class="kpis mt">
  <div class="kpi"><div class="ico"><?= ic('external') ?></div><div><b><?= number_format($S['total'], 0, ',', ' ') ?></b><span>Clics du <?= h($fr($du)) ?> au <?= h($fr($au)) ?></span></div></div>
  <div class="kpi"><div class="ico"><?= ic('euro') ?></div><div><b><?= h($eur($S['est'])) ?></b><span>Revenu estimé <?= adm_help('Par organisme : clics × montant par clic + clics × taux de transformation × prix de la session × pourcentage. Estimation de négociation, rien n’est facturé.') ?></span></div></div>
  <div class="kpi"><div class="ico"><?= ic('building') ?></div><div><b><?= count($S['orgas']) ?></b><span>Organismes · <?= count($S['sejours']) ?> sessions</span></div></div>
  <div class="kpi"><div class="ico"><?= ic('shield') ?></div><div><b><?= number_format($S['robots'], 0, ',', ' ') ?></b><span>Clics de robots écartés</span></div></div>
</div>

<section class="card mt">
  <h2 class="sec"><?= ic('chart') ?> Clics par <?= ['j' => 'jour', 's' => 'semaine', 'm' => 'mois'][$g] ?></h2>
  <?php if (!$S['total']): ?><?= adm_empty('chart', 'Aucun clic sur cette période.') ?><?php else: ?>
  <svg class="chart-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Clics par <?= ['j' => 'jour', 's' => 'semaine', 'm' => 'mois'][$g] ?>">
    <line x1="0" x2="<?= $W ?>" y1="<?= $y($max) ?>" y2="<?= $y($max) ?>" stroke="#eef1f3"/><line x1="0" x2="<?= $W ?>" y1="<?= $y(0) ?>" y2="<?= $y(0) ?>" stroke="#d3dbe0"/>
    <text x="2" y="<?= $pt - 5 ?>" font-size="11" fill="#6b7780"><?= $max ?> clic<?= $max > 1 ? 's' : '' ?></text>
    <?php foreach ($B as $i => [$l, $v]): $x = round($i * $bw + $gap / 2, 1); ?>
      <rect x="<?= $x ?>" y="<?= $y($v) ?>" width="<?= max(1, round($bw - $gap, 1)) ?>" height="<?= round($y(0) - $y($v), 1) ?>" rx="2" fill="#3d6a8e"><title><?= h($l) ?> : <?= $v ?> clic<?= $v > 1 ? 's' : '' ?></title></rect>
      <?php if ($i % $step === ($n - 1) % $step): ?><text x="<?= round($x + ($bw - $gap) / 2, 1) ?>" y="<?= $H - 6 ?>" font-size="11" fill="#6b7780" text-anchor="middle"><?= h($l) ?></text><?php endif; ?>
    <?php endforeach; ?>
  </svg>
  <?php endif; ?>
</section>

<h2 class="sec">Par organisme <span class="count-pill"><?= count($S['orgas']) ?></span></h2>
<?php if (!$S['orgas']): ?><p class="muted">Aucun clic sur cette période.</p><?php else: ?>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Organisme</th><th class="num">Sessions</th><th class="num">Clics</th><th class="num">Par clic</th><th class="num">% du prix</th><th class="num">Revenu estimé</th><th></th></tr></thead><tbody>
<?php foreach ($S['orgas'] as $o): ?><tr>
  <td data-l="Organisme"><?= adm_can('orgas.read') ? '<a class="lnk-q" href="admin_orga.php?t=' . h(rawurlencode($o['o'])) . '">' . h($o['nom']) . '</a>' : h($o['nom']) ?></td>
  <td class="num" data-l="Sessions"><?= (int)$o['sejours'] ?></td><td class="num" data-l="Clics"><b><?= (int)$o['n'] ?></b></td>
  <td class="num" data-l="Par clic"><?= $o['cpc'] === null ? '<span class="muted">—</span>' : h($eur($o['cpc'])) ?></td>
  <td class="num" data-l="% du prix"><?= $o['pct'] === null ? '<span class="muted">—</span>' : h(rtrim(rtrim(number_format($o['pct'], 2, ',', ''), '0'), ',')) . ' %' ?></td>
  <td class="num" data-l="Revenu estimé"><?= h($eur($o['est'])) ?></td>
  <td data-l=""><?php if (!$oid): ?><a class="btn ghost sm" href="<?= h($url(['o' => $o['o']])) ?>">Filtrer</a><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>

<h2 class="sec">Par séjour <span class="count-pill"><?= count($S['sejours']) ?></span></h2>
<?php if (!$S['sejours']): ?><p class="muted">Aucun clic sur cette période.</p><?php else: ?>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Séjour</th><th>Organisme</th><th>Session</th><th class="num">Prix</th><th class="num">Clics</th><th class="num">Visiteurs <?= adm_help('Adresses IP différentes (hachées), comptées une fois par jour.') ?></th><th class="num">Mobile</th><th class="num">Revenu estimé</th><th>Dernier clic</th></tr></thead><tbody>
<?php foreach (array_slice($S['sejours'], 0, 300) as $s): ?><tr>
  <td data-l="Séjour"><a class="lnk-q" href="camp_details.php?t=<?= h(rawurlencode($s['t'])) ?>" target="_blank" rel="noopener"><?= h($s['nom']) ?></a></td>
  <td data-l="Organisme"><?= h($s['orga']) ?></td><td data-l="Session" class="small"><?= h($fr($s['debut'])) ?> → <?= h($fr($s['fin'])) ?></td>
  <td class="num" data-l="Prix"><?= h(adm_money($s['prix'])) ?></td><td class="num" data-l="Clics"><b><?= $s['n'] ?></b></td><td class="num" data-l="Visiteurs"><?= $s['uniques'] ?></td><td class="num" data-l="Mobile"><?= $s['mobile'] ?></td>
  <td class="num" data-l="Revenu estimé"><?= h($eur($s['est'])) ?></td><td data-l="Dernier clic" class="small"><?= h(adm_date($s['dernier'])) ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if (count($S['sejours']) > 300): ?><p class="muted small">300 premières sessions affichées : l'export CSV contient tout.</p><?php endif; ?>
<?php endif; ?>

<section class="card mt">
  <h2 class="sec"><?= ic('edit') ?> Hypothèse de transformation <?= adm_help('Part des clics supposée devenir une inscription, pour estimer la commission en pourcentage. La commission de chaque organisme se règle sur sa fiche.') ?></h2>
  <?php if (adm_can('partenaires.write')): ?>
  <form class="row-l wrap-row" data-api="clics.conv" data-ok="Taux de transformation enregistré.">
    <label class="fld-inline">Taux <input class="in dt" name="conv" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format($S['conv'], 2, ',', ''), '0'), ',')) ?>" required> %</label>
    <button class="btn sm" type="submit">Enregistrer</button></form>
  <?php else: ?><p><?= h(rtrim(rtrim(number_format($S['conv'], 2, ',', ''), '0'), ',')) ?> % des clics</p><?php endif; ?>
</section>
<?php adm_foot(); ?>
