<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/factures_lib.php';
adm_guard(false, 'compta.read');
fac_ensure($pdo);

$mois = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$w = ['1=1']; $p = [];
if ($mois !== '') { $w[] = 'f.mois = ?'; $p[] = $mois; }
if ($q !== '') { $w[] = '(f.numero LIKE ? OR o.nom LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
$sql = "SELECT f.id, f.token, f.numero, f.type, f.mois, f.emise_le, f.ht_cents, f.tva_cents, f.ttc_cents, f.dette_cents, f.envoye_at, f.client, o.nom,
               (SELECT a.numero FROM fin_factures a WHERE a.origine_id = f.id LIMIT 1) annulee_par, (SELECT g.numero FROM fin_factures g WHERE g.id = f.origine_id) annule
        FROM fin_factures f LEFT JOIN organisateurs o ON o.id = f.organisateur_id WHERE " . implode(' AND ', $w) . " ORDER BY f.seq DESC";

if (isset($_GET['csv'])) {
    adm_need('compta.export', false);
    adm_audit('factures_export', $mois ?: 'toutes');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="factures-' . ($mois ?: 'toutes') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Numéro', 'Type', 'Date', 'Période', 'Client', 'SIRET client', 'HT', 'TVA', 'TTC', 'Dette compensée (hors facture)', 'Annule', 'Annulée par', 'Envoyée le'], ';');
    foreach (adm_rows($sql, $p) as $r) {
        $c = json_decode($r['client'], true) ?: [];
        $e = fn($v) => number_format($v / 100, 2, ',', '');
        fputcsv($out, [$r['numero'], $r['type'], $r['emise_le'], $r['mois'], $c['nom'] ?? '', $c['siret'] ?? '', $e($r['ht_cents']), $e($r['tva_cents']), $e($r['ttc_cents']), $e($r['dette_cents']), $r['annule'], $r['annulee_par'], $r['envoye_at']], ';');
    }
    exit;
}

$rows = adm_rows($sql . " LIMIT 300", $p);
$tot = adm_rows("SELECT COUNT(*) n, COALESCE(SUM(f.ttc_cents), 0) ttc, COALESCE(SUM(f.ht_cents), 0) ht FROM fin_factures f LEFT JOIN organisateurs o ON o.id = f.organisateur_id WHERE " . implode(' AND ', $w), $p)[0];
$moisListe = array_column(adm_rows("SELECT DISTINCT mois FROM fin_factures ORDER BY mois DESC LIMIT 36"), 'mois');
adm_head('Factures de commission');
?>
<p class="muted">Une facture par organisme et par mois, générée le 1er du mois par le cron et envoyée par e-mail. Numérotation continue, documents inaltérables conservés 10 ans. <?= adm_help("Pour corriger une facture : l'annuler par un avoir, puis régénérer le mois. Les montants viennent du journal fin_ledger.") ?></p>
<div class="kpis compact">
  <div class="kpi"><div><b><?= (int)$tot['n'] ?></b><span>Documents</span></div></div>
  <div class="kpi"><div><b><?= adm_money($tot['ht'] / 100) ?></b><span>Total HT</span></div></div>
  <div class="kpi"><div><b><?= adm_money($tot['ttc'] / 100) ?></b><span>Total TTC</span></div></div>
</div>
<form class="row-l wrap-row" method="get">
  <select class="in" name="m" data-autosubmit><option value="">Tous les mois</option><?php foreach ($moisListe as $m): ?><option value="<?= h($m) ?>"<?= $m === $mois ? ' selected' : '' ?>><?= h(fac_mois_lib($m)) ?></option><?php endforeach; ?></select>
  <input class="in grow" name="q" value="<?= h($q) ?>" placeholder="Numéro ou organisme" maxlength="80">
  <button class="btn ghost sm" type="submit"><?= ic('search') ?> Filtrer</button>
  <?php if (adm_can('compta.export')): ?><a class="btn ghost sm" href="admin_factures.php?<?= h(http_build_query(['m' => $mois, 'q' => $q, 'csv' => 1])) ?>"><?= ic('download') ?> Export CSV</a><?php endif; ?>
  <?php if (adm_can('compta.close')): ?><button class="btn sm" type="button" data-api="facture.generer" data-ok="Factures générées." data-confirm="Générer les factures du mois écoulé pour tous les organismes (celles déjà émises ne sont pas refaites) ?"><?= ic('refresh') ?> Générer le mois écoulé</button><?php endif; ?>
</form>
<?php if (!$rows): ?><?= adm_empty('list', 'Aucune facture.') ?><?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Numéro</th><th>Organisme</th><th>Période</th><th class="num">HT</th><th class="num">TTC</th><th class="num">Dette comp.</th><th>Envoi</th><th class="act-h"></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td data-l="Numéro"><b class="mono"><?= h($r['numero']) ?></b> <?= $r['type'] === 'avoir' ? '<span class="pill warn">Avoir</span>' : '' ?><?= $r['annulee_par'] ? ' <span class="pill bad">Annulée (' . h($r['annulee_par']) . ')</span>' : '' ?><div class="small muted"><?= h(date('d/m/Y', strtotime($r['emise_le']))) ?><?= $r['annule'] ? ' · annule ' . h($r['annule']) : '' ?></div></td>
    <td data-l="Organisme"><?= h($r['nom'] ?? (json_decode($r['client'], true)['nom'] ?? '')) ?></td><td data-l="Période"><?= h(fac_mois_lib($r['mois'])) ?></td>
    <td data-l="HT" class="num"><?= adm_money($r['ht_cents'] / 100) ?></td><td data-l="TTC" class="num"><b><?= adm_money($r['ttc_cents'] / 100) ?></b></td><td data-l="Dette comp." class="num"><?= $r['dette_cents'] ? adm_money($r['dette_cents'] / 100) : '' ?></td>
    <td data-l="Envoi" class="small"><?= $r['envoye_at'] ? h(adm_date($r['envoye_at'])) : '<span class="pill warn">Non envoyée</span>' ?></td>
    <td class="acts"><a class="ib" href="facture.php?f=<?= h($r['token']) ?>" target="_blank" rel="noopener" title="PDF" aria-label="PDF"><?= ic('download') ?></a>
      <?php if (adm_can('compta.close')): ?><button type="button" class="ib" data-api="facture.envoyer" data-f="<?= h($r['token']) ?>" data-ok="E-mail envoyé." title="Envoyer par e-mail" aria-label="Envoyer par e-mail"><?= ic('send') ?></button>
      <?php if ($r['type'] === 'facture' && !$r['annulee_par']): ?><button type="button" class="ib danger" data-api="facture.avoir" data-f="<?= h($r['token']) ?>" data-ok="Avoir émis." data-confirm="Émettre un avoir qui annule intégralement la facture <?= h($r['numero']) ?> ? Irréversible." title="Annuler par un avoir" aria-label="Annuler par un avoir"><?= ic('x') ?></button><?php endif; endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php adm_foot(); ?>
