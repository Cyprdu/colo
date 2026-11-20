<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/orga_lib.php';
require_once __DIR__ . '/api/finance_lib.php';
adm_guard(false, 'orgas.read');
fin_ensure($pdo);
require_once __DIR__ . '/api/revendication_lib.php';
rev_ensure($pdo);
$tok = (string)($_GET['t'] ?? '');
$oid = $tok !== '' ? fin_orga_from_tok($pdo, $tok) : 0;
$o = $oid ? adm_rows("SELECT o.*, u.prenom, u.nom AS u_nom, u.email AS u_email FROM organisateurs o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?", [$oid])[0] ?? null : null;
if (!$o) { http_response_code(404); adm_head('Organisme introuvable', 'admin_list.php?e=organismes'); echo adm_empty('building', 'Cet organisme n’existe pas ou le lien est invalide.'); adm_foot(); exit; }
$tok = fin_orga_tok($oid);
$acc = adm_rows("SELECT * FROM stripe_comptes WHERE organisateur_id = ?", [$oid])[0] ?? null;
$tot = fin_ledger_totaux($pdo, $oid);
$camps = adm_rows("SELECT c.nom, c.ville, c.date_debut, c.valide, c.en_attente, c.refuse, c.prive, c.supprime, c.token,
    (SELECT COUNT(*) FROM inscriptions i WHERE i.camp_id = c.id) n FROM camps c WHERE c.organisateur_id = ? ORDER BY c.id DESC LIMIT 50", [$oid]);
$pays = adm_can('paiements.read') ? adm_rows("SELECT id, created_at, brut_cents, statut, mode FROM fin_paiements WHERE organisateur_id = ? ORDER BY id DESC LIMIT 10", [$oid]) : [];
$trs = adm_can('versements.read') ? adm_rows("SELECT created_at, montant_cents, statut, type, erreur FROM fin_transferts WHERE organisateur_id = ? ORDER BY id DESC LIMIT 10", [$oid]) : [];
$dettes = adm_rows("SELECT created_at, montant_cents, recupere_cents FROM fin_reversals WHERE organisateur_id = ? AND statut = 'dette'", [$oid]);
$e = fn($c) => h(fin_eur((int)$c));
$yn = fn($v, $y, $n) => (int)$v ? '<span class="chk-ok">✓ ' . h($y) . '</span>' : '<span class="chk-ko">✗ ' . h($n) . '</span>';

adm_head((string)$o['nom'], 'admin_list.php?e=organismes', [['Catalogue', ''], ['Organismes', 'admin_list.php?e=organismes']]);
?>
<div class="row-l wrap-row">
  <span class="grow muted">Identifiant public <span class="mono small"><?= h($tok) ?></span></span>
  <?php if (adm_can('orgas.write')): ?><button class="btn ghost sm" type="button" data-api="orga.revendique" data-o="<?= h($tok) ?>"><?= ic('flag') ?> <?= (int)$o['non_revendique'] ? 'Fiche non revendiquée : marquer gérée' : 'Marquer non revendiquée' ?></button><?php endif; ?>
  <?php if (adm_can('compta.read')): ?><a class="btn ghost sm" href="admin_compta.php?v=grandlivre&amp;p=tout&amp;o=<?= h($tok) ?>"><?= ic('list') ?> Grand livre</a><?php endif; ?>
</div>
<div class="kpis compact mt">
  <div class="kpi"><div><b><?= $e($tot['encaisse']) ?></b><span>Encaissé</span></div></div>
  <div class="kpi"><div><b><?= $e($tot['commission']) ?></b><span>Commissions</span></div></div>
  <div class="kpi"><div><b><?= $e($tot['rembourse']) ?></b><span>Remboursé</span></div></div>
  <div class="kpi"><div><b><?= $e($tot['verse']) ?></b><span>Versé</span></div></div>
  <div class="kpi"><div><b><?= $e($tot['en_attente']) ?></b><span>En attente <?= adm_help('Ce que l’organisme a gagné et qui ne lui a pas encore été versé.') ?></span></div></div>
  <div class="kpi<?= (float)$o['portefeuille'] < -0.004 ? ' bad' : '' ?>"><div><b><?= h(adm_money($o['portefeuille'])) ?></b><span>Solde moncamp.fr <?= adm_help('Négatif : dette de l’organisme, compensée sur ses prochains paiements.') ?></span></div></div>
</div>
<div class="grid2">
  <section class="card">
    <h2 class="sec"><?= ic('building') ?> Organisme</h2>
    <dl class="dl">
      <dt>Responsable</dt><dd><?php if ($o['user_id'] && adm_can('users.read')): ?><a class="lnk-q" href="admin_user.php?t=<?= h(adm_tok('u', (int)$o['user_id'])) ?>"><?= h(trim($o['prenom'] . ' ' . $o['u_nom'])) ?></a><?php else: ?><?= h(trim($o['prenom'] . ' ' . $o['u_nom'])) ?><?php endif; ?> · <?= h($o['u_email']) ?></dd>
      <dt>E-mail</dt><dd><?= h($o['email'] ?: '—') ?></dd>
      <dt>Téléphone</dt><dd><?= h($o['tel'] ?: '—') ?></dd>
      <dt>Site</dt><dd><?= $o['web'] ? '<a class="lnk-q" href="' . h(preg_match('~^https?://~i', (string)$o['web']) ? $o['web'] : 'https://' . $o['web']) . '" target="_blank" rel="noopener noreferrer nofollow">' . h($o['web']) . '</a>' : '—' ?></dd>
      <dt>Points</dt><dd><?= (int)$o['solde_points'] ?></dd>
    </dl>
  </section>
  <section class="card">
    <h2 class="sec"><?= ic('card') ?> Compte Stripe <?= adm_help('Les paiements par carte vont sur ce compte (direct charges) quand il est complet ; sinon moncamp.fr encaisse et transfère.') ?></h2>
    <?php if (!$acc): ?><p class="muted">Pas encore de compte Stripe : l'organisme doit lancer l'activation depuis son espace Finances.</p>
    <?php else: ?>
    <dl class="dl">
      <dt>Compte</dt><dd class="mono small"><?= h($acc['account_id']) ?> · <?= h((string)$acc['type']) ?></dd>
      <dt>Dossier</dt><dd><?= $yn($acc['details_submitted'], 'complet', 'incomplet') ?></dd>
      <dt>Paiements</dt><dd><?= $yn($acc['charges_enabled'], 'acceptés', 'bloqués') ?></dd>
      <dt>Virements</dt><dd><?= $yn($acc['payouts_enabled'], 'actifs', 'bloqués') ?></dd>
      <dt>Transferts</dt><dd><?= $yn($acc['transfers_actif'], 'actifs', 'inactifs') ?></dd>
      <?php if ($acc['exigences'] && $acc['exigences'] !== '[]'): ?><dt>Demandé par Stripe</dt><dd class="small wrap"><?= h(mb_substr((string)$acc['exigences'], 0, 400)) ?></dd><?php endif; ?>
      <dt>Mis à jour</dt><dd><?= h(adm_date($acc['maj'])) ?></dd>
    </dl>
    <div class="row-l wrap-row mt"><a class="btn ghost sm" href="https://dashboard.stripe.com/connect/accounts/<?= h(rawurlencode((string)$acc['account_id'])) ?>" target="_blank" rel="noopener noreferrer"><?= ic('external') ?> Stripe</a>
      <?php if (adm_can('orgas.write')): ?><button class="btn ghost sm" type="button" data-api="orga.sync" data-o="<?= h($tok) ?>"><?= ic('refresh') ?> Actualiser</button><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($dettes): ?><h3 class="sub">Reversements impossibles</h3><?php foreach ($dettes as $d): ?><p class="neg small"><?= h(adm_date($d['created_at'])) ?> · reste <?= $e((int)$d['montant_cents'] - (int)$d['recupere_cents']) ?></p><?php endforeach; ?><?php endif; ?>
  </section>
</div>

<?php if (adm_can('partenaires.read')): require_once __DIR__ . '/api/clics.php'; clics_ensure($pdo);
  $ck = clics_stats($pdo, date('Y-m-d', strtotime('-29 days')), date('Y-m-d'), $oid);
  $pc = adm_rows("SELECT partenaire_clic_cents, partenaire_pct FROM organisateurs WHERE id = ?", [$oid])[0];
  $cpc = $pc['partenaire_clic_cents'] === null ? '' : rtrim(rtrim(number_format($pc['partenaire_clic_cents'] / 100, 2, ',', ''), '0'), ',');
  $pct = $pc['partenaire_pct'] === null ? '' : rtrim(rtrim(number_format((float)$pc['partenaire_pct'], 2, ',', ''), '0'), ','); ?>
<section class="card mt">
  <h2 class="sec"><?= ic('external') ?> Partenariat <?= adm_help('Commission négociée avec l’organisme pour les clics vers son site (« Réserver ma place » externe) : montant par clic et/ou pourcentage du prix des inscriptions qu’ils génèrent. Sert à estimer les revenus, rien n’est facturé automatiquement.') ?></h2>
  <p class="row-l wrap-row">30 derniers jours : <b><?= (int)$ck['total'] ?> clic<?= $ck['total'] > 1 ? 's' : '' ?></b> vers son site · revenu estimé <b><?= h(adm_money($ck['est'] / 100)) ?></b> <a class="lnk-q" href="admin_clics.php?o=<?= h(rawurlencode($tok)) ?>">Détail</a></p>
  <?php if (adm_can('partenaires.write')): ?>
  <form class="row-l wrap-row" data-api="orga.partenaire" data-ok="Commission partenaire enregistrée."><input type="hidden" name="o" value="<?= h($tok) ?>">
    <label class="fld-inline">€ par clic <input class="in dt" name="cpc" inputmode="decimal" value="<?= h($cpc) ?>" placeholder="0,20"></label>
    <label class="fld-inline">% du prix <input class="in dt" name="pct" inputmode="decimal" value="<?= h($pct) ?>" placeholder="5"></label>
    <button class="btn sm" type="submit">Enregistrer</button></form>
  <?php else: ?><p class="muted">Commission : <?= $cpc === '' ? '—' : h($cpc) . ' € par clic' ?> · <?= $pct === '' ? '—' : h($pct) . ' % du prix' ?></p><?php endif; ?>
</section>
<?php endif; ?>

<h2 class="sec"><?= ic('tent') ?> Séjours <span class="count-pill"><?= count($camps) ?></span></h2>
<?php if (!$camps): ?><p class="muted">Aucun séjour.</p><?php else: ?>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Séjour</th><th>Ville</th><th>Début</th><th class="num">Inscriptions</th><th>État</th></tr></thead><tbody>
<?php foreach ($camps as $c): ?><tr><td data-l="Séjour"><a class="lnk-q" href="camp_details.php?t=<?= h(rawurlencode((string)$c['token'])) ?>" target="_blank" rel="noopener"><?= h($c['nom']) ?></a></td><td data-l="Ville"><?= h($c['ville']) ?></td><td data-l="Début"><?= $c['date_debut'] ? h(date('d/m/Y', strtotime((string)$c['date_debut']))) : '' ?></td><td class="num" data-l="Inscriptions"><?= (int)$c['n'] ?></td>
  <td data-l="État"><?= $c['supprime'] ? '<span class="pill">Supprimé</span>' : ($c['valide'] ? '<span class="pill ok">Validé</span>' : ($c['refuse'] ? '<span class="pill bad">Refusé</span>' : '<span class="pill warn">En attente</span>')) ?><?= $c['prive'] && !$c['supprime'] ? ' <span class="pill">Masqué</span>' : '' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>

<div class="grid2 mt">
<?php if ($pays): ?><section class="card"><h2 class="sec"><?= ic('card') ?> Derniers paiements</h2><ul class="feed"><?php foreach ($pays as $p): ?><li><span class="dot"></span><div><a class="lnk-q" href="admin_paiement.php?t=<?= h(adm_tok('pay', (int)$p['id'])) ?>"><?= $e($p['brut_cents']) ?></a> · <?= h($p['statut']) ?> · <?= h($p['mode']) ?><small><?= h(adm_date($p['created_at'])) ?></small></div></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php if ($trs): ?><section class="card"><h2 class="sec"><?= ic('bank') ?> Transferts et versements</h2><ul class="feed"><?php foreach ($trs as $t): ?><li><span class="dot"></span><div><b><?= $e($t['montant_cents']) ?></b> · <?= h($t['type']) ?> · <?= h($t['statut']) ?><?= $t['erreur'] ? '<div class="neg small">' . h(mb_substr((string)$t['erreur'], 0, 160)) . '</div>' : '' ?><small><?= h(adm_date($t['created_at'])) ?></small></div></li><?php endforeach; ?></ul></section><?php endif; ?>
</div>
<?= adm_notes_box('orga', $tok, adm_notes('orga', $tok)) ?>
<?php adm_foot(); ?>
