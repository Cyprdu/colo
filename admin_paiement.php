<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/compta_lib.php';
adm_guard(false, 'paiements.read');
fin_ensure($pdo);
$tok = (string)($_GET['t'] ?? '');
$id = adm_untok('pay', $tok);
$p = $id ? adm_rows("SELECT p.*, o.nom orga, c.nom camp, c.token camp_tok, s.account_id, " . fin_sql_marge('p') . " marge, " . fin_sql_com_nette('p') . " com_nette
    FROM fin_paiements p LEFT JOIN organisateurs o ON o.id = p.organisateur_id LEFT JOIN camps c ON c.id = p.camp_id LEFT JOIN stripe_comptes s ON s.organisateur_id = p.organisateur_id WHERE p.id = ?", [$id])[0] ?? null : null;
if (!$p) { http_response_code(404); adm_head('Paiement introuvable', 'admin_compta.php?v=paiements'); echo adm_empty('card', 'Ce paiement n’existe pas ou le lien est invalide.'); adm_foot(); exit; }

$iid = (int)$p['inscription_id'];
$ins = $iid > 0 ? adm_rows("SELECT i.statut, i.statut_paiement, i.prix_final, i.montant_paye, i.token, CONCAT_WS(' ', e.prenom, e.nom) enfant, u.id uid, u.email, CONCAT_WS(' ', u.prenom, u.nom) parent
    FROM inscriptions i LEFT JOIN enfants e ON e.id = i.enfant_id LEFT JOIN users u ON u.id = e.parent_id WHERE i.id = ?", [$iid])[0] ?? null : null;
$obj = $iid < 0 ? 'equipe' : 'inscription';
$ledger = adm_rows("SELECT created_at, type, libelle, reference, montant_cents, acteur, mode FROM fin_ledger WHERE objet_type = ? AND objet_id = ? ORDER BY id", [$obj, (string)abs($iid)]);
$tr = $p['transfert_id'] ? adm_rows("SELECT created_at, montant_cents, statut, stripe_transfer_id, erreur FROM fin_transferts WHERE id = ?", [$p['transfert_id']])[0] ?? null : null;
$rev = adm_rows("SELECT created_at, montant_cents, recupere_cents, statut FROM fin_reversals WHERE paiement_id = ? ORDER BY id", [$id]);
$ann = $iid > 0 ? adm_rows("SELECT created_at, motif, commentaire, pct, rembourse_cents, commission_retenue_cents, refund_id FROM fin_annulations WHERE inscription_id = ?", [$iid])[0] ?? null : null;
$aud = adm_rows("SELECT created_at, acteur, action, donnees FROM fin_audit WHERE (objet_type = ? AND objet_id = ?) OR (objet_type = 'payment_intent' AND objet_id = ?) ORDER BY id DESC LIMIT 40", [$obj, (string)abs($iid), (string)$p['payment_intent']]);
$e = fn($c) => h(fin_eur((int)$c));
$sts = ['attente' => 'En attente', 'bloque' => 'Bloqué', 'litige' => 'Litige', 'en_cours' => 'Versement en cours', 'verse' => 'Versé', 'rembourse' => 'Remboursé'];
$dash = 'https://dashboard.stripe.com/' . ($p['mode'] === 'direct' && $p['account_id'] ? 'connect/accounts/' . rawurlencode((string)$p['account_id']) . '/' : '') . 'payments/' . rawurlencode((string)$p['payment_intent']);

adm_head('Paiement ' . fin_eur((int)$p['brut_cents']), 'admin_compta.php?v=paiements', [['Finances', ''], ['Paiements', 'admin_compta.php?v=paiements']]);
?>
<div class="kpis compact">
  <div class="kpi"><div><b><?= $e($p['brut_cents']) ?></b><span>Payé par la famille</span></div></div>
  <div class="kpi"><div><b><?= $e($p['commission_cents']) ?></b><span>Commission (<?= h(number_format((float)$p['commission_pct'], 2, ',', ' ')) ?> %)</span></div></div>
  <div class="kpi"><div><b><?= $p['frais_stripe_cents'] === null ? '—' : $e($p['frais_stripe_cents']) ?></b><span>Frais Stripe <?= adm_help('Frais réels relevés chez Stripe. En direct charges ils sont payés par l’organisme.') ?></span></div></div>
  <div class="kpi<?= (int)$p['marge'] < 0 ? ' bad' : '' ?>"><div><b><?= $e($p['marge']) ?></b><span>Marge moncamp.fr</span></div></div>
  <div class="kpi"><div><b><?= $e($p['net_cents']) ?></b><span>Net organisme</span></div></div>
  <?php if ($p['rembourse_cents']): ?><div class="kpi bad"><div><b><?= $e($p['rembourse_cents']) ?></b><span>Remboursé</span></div></div><?php endif; ?>
</div>

<div class="grid2 mt">
  <section class="card">
    <h2 class="sec"><?= ic('card') ?> Paiement</h2>
    <dl class="dl">
      <dt>Statut</dt><dd><span class="pill <?= ['litige' => 'bad', 'rembourse' => 'warn', 'verse' => 'ok'][$p['statut']] ?? '' ?>"><?= h($sts[$p['statut']] ?? $p['statut']) ?></span></dd>
      <dt>Mode <?= adm_help('direct : payé sur le compte Stripe de l’organisme ; destination : encaissé par moncamp.fr puis transféré ; legacy : ancien circuit.') ?></dt><dd><?= h($p['mode']) ?></dd>
      <dt>Date</dt><dd><?= h(adm_date($p['created_at'])) ?></dd>
      <dt>Organisme</dt><dd><?php if (adm_can('orgas.read')): ?><a class="lnk" href="admin_orga.php?t=<?= h(fin_orga_tok((int)$p['organisateur_id'])) ?>"><?= h($p['orga']) ?></a><?php else: ?><?= h($p['orga']) ?><?php endif; ?></dd>
      <dt>Séjour</dt><dd><?= $p['camp_tok'] ? '<a class="lnk" href="camp_details.php?t=' . h(rawurlencode((string)$p['camp_tok'])) . '" target="_blank" rel="noopener">' . h($p['camp']) . '</a>' : h($p['camp']) ?></dd>
      <dt>PaymentIntent</dt><dd class="mono small"><?= h($p['payment_intent'] ?: '—') ?></dd>
      <dt>Session</dt><dd class="mono small"><?= h($p['session_id'] ?: '—') ?></dd>
      <dt>Déblocage</dt><dd><?= h(adm_date($p['date_deblocage'])) ?></dd>
      <dt>Net remboursé</dt><dd><?= $e($p['net_rembourse_cents']) ?></dd>
    </dl>
    <div class="row-l wrap-row mt">
      <?php if ($p['payment_intent']): ?><a class="btn ghost sm" href="<?= h($dash) ?>" target="_blank" rel="noopener noreferrer"><?= ic('external') ?> Ouvrir dans Stripe</a>
      <button class="btn ghost sm" type="button" data-stripe="<?= h($tok) ?>"><?= ic('refresh') ?> Interroger Stripe</button><?php endif; ?>
      <a class="btn ghost sm" href="admin_compta.php?v=journal&amp;p=tout&amp;q=<?= h(rawurlencode((string)($p['payment_intent'] ?: $p['session_id']))) ?>"><?= ic('list') ?> Dans le journal</a>
    </div>
    <div id="stripe-live" class="mt" hidden></div>
  </section>
  <section class="card">
    <h2 class="sec"><?= ic('ticket') ?> Inscription</h2>
    <?php if (!$ins): ?><p class="muted"><?= $iid < 0 ? 'Paiement d’équipe (animateur).' : 'Inscription introuvable.' ?></p><?php else: ?>
    <dl class="dl">
      <dt>Participant</dt><dd><?= h($ins['enfant'] ?: '—') ?></dd>
      <dt>Famille</dt><dd><?php if ($ins['uid'] && adm_can('users.read')): ?><a class="lnk" href="admin_user.php?t=<?= h(adm_tok('u', (int)$ins['uid'])) ?>"><?= h($ins['parent']) ?></a><?php else: ?><?= h($ins['parent']) ?><?php endif; ?> · <?= h($ins['email']) ?></dd>
      <dt>Statut</dt><dd><?= h($ins['statut']) ?> · <?= h($ins['statut_paiement']) ?></dd>
      <dt>Prix / payé</dt><dd><?= h(adm_money($ins['prix_final'])) ?> / <?= h(adm_money($ins['montant_paye'])) ?></dd>
      <dt>Jeton</dt><dd class="mono small"><?= h($ins['token']) ?></dd>
    </dl><?php endif; ?>
    <?php if ($ann): ?><h3 class="sub">Annulation</h3><dl class="dl"><dt>Le</dt><dd><?= h(adm_date($ann['created_at'])) ?></dd><dt>Motif</dt><dd><?= h($ann['motif']) ?><?= $ann['commentaire'] ? ' · ' . h($ann['commentaire']) : '' ?></dd><dt>Remboursé</dt><dd><?= $e($ann['rembourse_cents']) ?> (<?= (int)$ann['pct'] ?> %)</dd><dt>Commission retenue</dt><dd><?= $e($ann['commission_retenue_cents']) ?></dd><dt>Refund</dt><dd class="mono small"><?= h($ann['refund_id'] ?: '—') ?></dd></dl><?php endif; ?>
    <?php if ($tr || $rev): ?><h3 class="sub">Transfert et reprises</h3><dl class="dl">
      <?php if ($tr): ?><dt>Transfert</dt><dd><?= $e($tr['montant_cents']) ?> · <?= h($tr['statut']) ?> · <span class="mono small"><?= h($tr['stripe_transfer_id']) ?></span><?= $tr['erreur'] ? '<div class="neg small">' . h($tr['erreur']) . '</div>' : '' ?></dd><?php endif; ?>
      <?php foreach ($rev as $r): ?><dt>Reprise</dt><dd><?= $e($r['montant_cents']) ?> · récupéré <?= $e($r['recupere_cents']) ?> · <?= h($r['statut']) ?></dd><?php endforeach; ?>
    </dl><?php endif; ?>
  </section>
</div>

<h2 class="sec"><?= ic('list') ?> Écritures comptables</h2>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Date</th><th>Type</th><th>Libellé</th><th>Référence</th><th>Acteur</th><th class="num">Montant</th></tr></thead><tbody>
<?php foreach ($ledger as $l): ?><tr><td data-l="Date" class="small"><?= h(adm_date($l['created_at'])) ?></td><td data-l="Type"><span class="pill"><?= h($l['type']) ?></span></td><td data-l="Libellé"><?= h($l['libelle']) ?></td><td data-l="Référence" class="mono small wrap"><?= h($l['reference']) ?></td><td data-l="Acteur" class="small"><?= h($l['acteur']) ?></td><td class="num<?= (int)$l['montant_cents'] < 0 ? ' neg' : '' ?>" data-l="Montant"><?= $e($l['montant_cents']) ?></td></tr><?php endforeach; ?>
<?php if (!$ledger): ?><tr><td colspan="6" class="empty"><p>Aucune écriture.</p></td></tr><?php endif; ?>
</tbody></table></div>

<h2 class="sec"><?= ic('clock') ?> Historique technique</h2>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Données</th></tr></thead><tbody>
<?php foreach ($aud as $a): ?><tr><td data-l="Date" class="small"><?= h(adm_date($a['created_at'])) ?></td><td data-l="Acteur" class="small"><?= h($a['acteur']) ?></td><td data-l="Action"><?= h($a['action']) ?></td><td data-l="Données" class="mono small wrap"><?= h(mb_substr((string)$a['donnees'], 0, 400)) ?></td></tr><?php endforeach; ?>
<?php if (!$aud): ?><tr><td colspan="4" class="empty"><p>Aucun événement.</p></td></tr><?php endif; ?>
</tbody></table></div>
<?= adm_notes_box('paiement', $tok, adm_notes('paiement', $tok)) ?>
<?php adm_foot(); ?>
