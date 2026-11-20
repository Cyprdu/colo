<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/revendication_lib.php';
adm_guard(false, 'orgas.read');
rev_ensure($pdo);

if (isset($_GET['doc'])) {
    $d = adm_rows("SELECT fichier, fichier_type FROM orga_revendications WHERE token = ?", [(string)$_GET['doc']])[0] ?? null;
    $p = $d && $d['fichier'] && preg_match('/^[a-f0-9]{48}\.(pdf|jpg|png)$/', $d['fichier']) ? REV_DIR . $d['fichier'] : '';
    if ($p === '' || !is_file($p)) { http_response_code(404); exit('Fichier introuvable.'); }
    adm_audit('revendication_piece_vue', (string)$_GET['doc']);
    header('Content-Type: ' . $d['fichier_type']);
    header('Content-Disposition: inline; filename="justificatif.' . pathinfo($p, PATHINFO_EXTENSION) . '"');
    readfile($p);
    exit;
}

$f = in_array($_GET['f'] ?? '', ['attente', 'validee', 'refusee'], true) ? $_GET['f'] : 'attente';
$rows = adm_rows("SELECT r.*, o.nom orga, o.slug, o.web, o.non_revendique, u.email compte, (SELECT COUNT(*) FROM camps c WHERE c.organisateur_id = r.organisateur_id) nb
                  FROM orga_revendications r JOIN organisateurs o ON o.id = r.organisateur_id LEFT JOIN users u ON u.id = r.user_id WHERE r.statut = ? ORDER BY r.id DESC LIMIT 200", [$f]);
$nonRev = (int)adm_one("SELECT COUNT(*) FROM organisateurs WHERE non_revendique = 1");
adm_head('Revendications de fiches');
?>
<p class="muted"><?= $nonRev ?> fiche(s) importée(s) non revendiquée(s). Valider transfère l'organisme et tous ses séjours au compte du demandeur. <?= adm_help("Vérifiez la preuve : e-mail confirmé au domaine du site officiel, ou pièce justificative (Kbis, statuts, récépissé d'association) au nom de l'organisme et cohérente avec l'identité déclarée.") ?></p>
<nav class="row-l wrap-row"><?php foreach (['attente' => 'En attente', 'validee' => 'Validées', 'refusee' => 'Refusées'] as $k => $l): ?><a class="btn <?= $k === $f ? '' : 'ghost ' ?>sm" href="?f=<?= $k ?>"><?= h($l) ?></a><?php endforeach; ?></nav>
<?php if (!$rows): ?><?= adm_empty('user-check', 'Aucune demande.') ?><?php else: foreach ($rows as $r): ?>
<section class="card mt">
  <h2 class="sec"><?= ic('building') ?> <?= h($r['orga']) ?> <span class="muted small">· <?= (int)$r['nb'] ?> séjour(s)</span></h2>
  <dl class="dl">
    <dt>Demandeur</dt><dd><b><?= h($r['prenom'] . ' ' . $r['nom']) ?></b>, <?= h($r['fonction']) ?> · compte <?= h($r['compte']) ?><?= $r['tel'] ? ' · ' . h($r['tel']) : '' ?></dd>
    <dt>Preuve</dt><dd><?php if ($r['email_verifie']): ?><span class="pill ok">E-mail vérifié</span> <?= h($r['email_pro']) ?><?php elseif ($r['fichier']): ?><a class="btn ghost sm" href="?doc=<?= h($r['token']) ?>" target="_blank" rel="noopener"><?= ic('eye') ?> Voir la pièce justificative</a><?php else: ?><span class="pill bad">Aucune</span><?php endif; ?></dd>
    <dt>Site officiel</dt><dd><?= $r['web'] ? '<a href="' . h(preg_match('#^https?://#i', $r['web']) ? $r['web'] : 'https://' . $r['web']) . '" target="_blank" rel="noopener noreferrer">' . h($r['web']) . '</a>' : '—' ?></dd>
    <?php if ($r['message']): ?><dt>Message</dt><dd class="wrap"><?= nl2br(h($r['message'])) ?></dd><?php endif; ?>
    <dt>Reçue le</dt><dd><?= h(adm_date($r['created_at'])) ?><?= $r['decide_at'] ? ' · décidée le ' . h(adm_date($r['decide_at'])) : '' ?></dd>
    <?php if ($r['motif']): ?><dt>Motif</dt><dd><?= h($r['motif']) ?></dd><?php endif; ?>
  </dl>
  <?php if ($r['statut'] === 'attente' && adm_can('orgas.write')): ?>
  <div class="row-l wrap-row mt">
    <button class="btn sm" type="button" data-api="revendication.valider" data-r="<?= h($r['token']) ?>" data-ok="Fiche transférée." data-confirm="Transférer « <?= h($r['orga']) ?> » et ses <?= (int)$r['nb'] ?> séjour(s) au compte <?= h($r['compte']) ?> ?"><?= ic('check') ?> Valider et transférer</button>
    <form class="row-l grow" data-api="revendication.refuser" data-ok="Demande refusée."><input type="hidden" name="r" value="<?= h($r['token']) ?>"><input class="in grow" name="motif" required maxlength="500" placeholder="Motif du refus (envoyé au demandeur)"><button class="btn ghost sm" type="submit"><?= ic('x') ?> Refuser</button></form>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; endif; ?>
<?php adm_foot(); ?>
