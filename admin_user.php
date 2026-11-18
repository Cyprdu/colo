<?php
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/api/orga_lib.php';
require_once __DIR__ . '/api/rgpd_lib.php';
adm_guard(false, 'users.read');
$tok = (string)($_GET['t'] ?? '');
$id = adm_untok('u', $tok);
$u = $id ? adm_rows("SELECT * FROM users WHERE id = ?", [$id])[0] ?? null : null;
if (!$u) { http_response_code(404); adm_head('Utilisateur introuvable', 'admin_list.php?e=users'); echo adm_empty('users', 'Ce compte n’existe pas ou le lien est invalide.'); adm_foot(); exit; }

if (isset($_GET['export'])) {
    adm_need('users.rgpd', false);
    if (!adm_reauth_ok()) { $_SESSION['adm_flash'] = ['err', 'Confirmez votre mot de passe (bouton « Export RGPD ») puis réessayez.']; header('Location: admin_user.php?t=' . rawurlencode($tok)); exit; }
    adm_audit('rgpd_export_admin', "users#$id", (string)$u['email']);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="donnees-' . $tok . '-' . date('Y-m-d') . '.json"');
    echo json_encode(rgpd_export($pdo, $id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$enfants = adm_rows("SELECT prenom, nom FROM enfants WHERE parent_id = ? ORDER BY prenom", [$id]);
$insc = adm_rows("SELECT i.statut, i.statut_paiement, i.prix_final, c.nom camp, CONCAT_WS(' ', e.prenom, e.nom) enfant, p.id pid FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id
    LEFT JOIN camps c ON c.id = i.camp_id LEFT JOIN fin_paiements p ON p.inscription_id = i.id WHERE e.parent_id = ? ORDER BY i.id DESC LIMIT 30", [$id]);
$orgas = adm_rows("SELECT id, nom FROM organisateurs WHERE user_id = ? ORDER BY nom", [$id]);
$dem = adm_rows("SELECT token, structure, statut, created_at FROM demandes_organisateur WHERE user_id = ? ORDER BY id DESC", [$id]);
$msgs = adm_can('contacts.read') ? adm_rows("SELECT token, motif, etat, created_at FROM contact_messages WHERE user_id = ? OR email = ? ORDER BY id DESC LIMIT 10", [$id, $u['email']]) : [];
$aud = adm_rows("SELECT created_at, email, action, detail FROM admin_audit WHERE cible = ? ORDER BY id DESC LIMIT 20", ["users#$id"]);
$roles = adm_rows("SELECT r.nom FROM adm_user_roles ur JOIN adm_roles r ON r.id = ur.role_id WHERE ur.user_id = ?", [$id]);
$obst = rgpd_obstacle($pdo, $id);
$canW = adm_can('users.write');
$name = trim($u['prenom'] . ' ' . $u['nom']) ?: $u['email'];
$dst = demande_statut_labels();
function demande_statut_labels(): array { return ['attente' => 'En attente', 'complements' => 'Compléments', 'validee' => 'Validée', 'refusee' => 'Refusée']; }

adm_head($name, 'admin_list.php?e=users', [['Clients', ''], ['Utilisateurs', 'admin_list.php?e=users']]);
?>
<div class="row-l wrap-row">
  <div class="grow">
    <?php if ((int)$u['is_admin'] === 1): ?><span class="pill dark">Équipe<?= $roles ? ' · ' . h(implode(', ', array_column($roles, 'nom'))) : '' ?></span><?php endif; ?>
    <?php if ($u['is_directeur']): ?><span class="pill ok">Organisateur</span><?php endif; ?>
    <?php if (!empty($u['is_animateur'])): ?><span class="pill">Animateur</span><?php endif; ?>
    <?php if (!empty($u['bloque'])): ?><span class="pill bad">Bloqué</span><?php endif; ?>
    <span class="pill <?= $u['is_verified'] ? 'ok' : 'warn' ?>"><?= $u['is_verified'] ? 'E-mail vérifié' : 'E-mail non vérifié' ?></span>
    <?php if (!empty($u['google_id'])): ?><span class="pill">Google</span><?php endif; ?>
  </div>
  <?php if ($canW && !$u['is_verified']): ?><button class="btn ghost sm" type="button" data-api="user.verify" data-u="<?= h($tok) ?>"><?= ic('check') ?> Marquer vérifié</button><?php endif; ?>
  <?php if ($canW && (int)$u['is_admin'] !== 1): ?><button class="btn ghost sm" type="button" data-api="user.block" data-u="<?= h($tok) ?>" data-confirm="<?= $u['bloque'] ? 'Débloquer ce compte ?' : 'Bloquer ce compte ? La personne est déconnectée et ne peut plus se connecter.' ?>"><?= ic('lock') ?> <?= $u['bloque'] ? 'Débloquer' : 'Bloquer' ?></button><?php endif; ?>
  <?php if (adm_is_supreme()): ?><a class="btn ghost sm" href="admin_equipe.php"><?= ic('key') ?> Droits d'administration</a><?php endif; ?>
</div>

<div class="grid2 mt">
  <section class="card">
    <h2 class="sec"><?= ic('users') ?> Identité</h2>
    <dl class="dl">
      <dt>Nom</dt><dd><?= h($name) ?></dd>
      <dt>E-mail</dt><dd><a class="lnk-q" href="mailto:<?= h(rawurlencode((string)$u['email'])) ?>"><?= h($u['email']) ?></a></dd>
      <dt>Téléphone</dt><dd><?= h($u['tel'] ?? '' ?: '—') ?></dd>
      <dt>Inscrit le</dt><dd><?= h(adm_date($u['created_at'] ?? '')) ?></dd>
      <dt>Enfants</dt><dd><?= $enfants ? h(implode(', ', array_map(fn($e) => trim($e['prenom'] . ' ' . $e['nom']), $enfants))) : '<span class="muted">Aucun</span>' ?></dd>
      <dt>Organismes</dt><dd><?php foreach ($orgas as $o): ?><?php if (adm_can('orgas.read')): ?><a class="lnk-q" href="admin_orga.php?t=<?= h(fin_orga_tok((int)$o['id'])) ?>"><?= h($o['nom']) ?></a><?php else: ?><?= h($o['nom']) ?><?php endif; ?> <?php endforeach; ?><?= $orgas ? '' : '<span class="muted">Aucun</span>' ?></dd>
      <dt>Demandes</dt><dd><?php foreach ($dem as $d): ?><?php if (adm_can('demandes.read')): ?><a class="lnk-q" href="admin_demandes.php?s=toutes&amp;d=<?= h($d['token']) ?>"><?= h($d['structure']) ?></a><?php else: ?><?= h($d['structure']) ?><?php endif; ?> (<?= h($dst[$d['statut']] ?? $d['statut']) ?>) <?php endforeach; ?><?= $dem ? '' : '<span class="muted">Aucune</span>' ?></dd>
    </dl>
  </section>
  <section class="card">
    <h2 class="sec"><?= ic('shield') ?> RGPD <?= adm_help('Export : toutes les données personnelles du compte en JSON. Suppression : efface le compte, ses enfants et leurs documents ; les écritures comptables restent (obligation légale). Mot de passe exigé, action journalisée.') ?></h2>
    <?php if (adm_can('users.rgpd')): ?>
      <div class="row-l wrap-row"><button class="btn ghost" type="button" data-api="user.rgpd_unlock" data-go="admin_user.php?t=<?= h($tok) ?>&amp;export=1"><?= ic('download') ?> Export RGPD</button>
      <?php if ($obst): ?></div><p class="muted small mt"><?= h($obst) ?></p>
      <?php else: ?><button class="btn ghost danger-t" type="button" data-api="user.rgpd_delete" data-u="<?= h($tok) ?>" data-confirm="Supprimer définitivement le compte <?= h($u['email']) ?>, ses enfants et leurs documents ? Irréversible." data-word="SUPPRIMER" data-go="admin_list.php?e=users"><?= ic('trash') ?> Supprimer le compte</button></div><?php endif; ?>
    <?php else: ?><p class="muted">Réservé au rôle disposant du droit RGPD.</p><?php endif; ?>
    <?php if ($msgs): ?><h3 class="sub">Messages de contact</h3><ul class="feed"><?php foreach ($msgs as $m): ?><li><span class="dot"></span><div><a class="lnk-q" href="admin_contacts.php?d=&amp;m=<?= h($m['token']) ?>"><?= h(html_entity_decode((string)$m['motif'])) ?></a><small><?= h(adm_date($m['created_at'])) ?> · <?= h($m['etat']) ?></small></div></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>

<h2 class="sec"><?= ic('ticket') ?> Inscriptions <span class="count-pill"><?= count($insc) ?></span></h2>
<?php if (!$insc): ?><p class="muted">Aucune inscription.</p><?php else: ?>
<div class="tbl-wrap flat"><table class="tbl"><thead><tr><th>Participant</th><th>Séjour</th><th>Statut</th><th>Paiement</th><th class="num">Prix</th><th></th></tr></thead><tbody>
<?php foreach ($insc as $i): ?><tr><td data-l="Participant"><?= h($i['enfant']) ?></td><td data-l="Séjour"><?= h($i['camp']) ?></td><td data-l="Statut"><?= h($i['statut']) ?></td><td data-l="Paiement"><?= h($i['statut_paiement']) ?></td><td class="num" data-l="Prix"><?= h(adm_money($i['prix_final'])) ?></td>
  <td class="acts"><?php if ($i['pid'] && adm_can('paiements.read')): ?><a class="ib" href="admin_paiement.php?t=<?= h(adm_tok('pay', (int)$i['pid'])) ?>" title="Paiement" aria-label="Paiement"><?= ic('card') ?></a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>

<?php if (adm_can('audit.read')): ?>
<h2 class="sec"><?= ic('list') ?> Actions de l'équipe sur ce compte</h2>
<?php if (!$aud): ?><p class="muted">Aucune.</p><?php else: ?><ul class="feed"><?php foreach ($aud as $a): ?><li><span class="dot"></span><div><b><?= h($a['action']) ?></b> <?= h((string)$a['detail']) ?><small><?= h($a['email']) ?> · <?= h(adm_date($a['created_at'])) ?></small></div></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>
<?= adm_notes_box('user', $tok, adm_notes('user', $tok)) ?>
<?php adm_foot(); ?>
