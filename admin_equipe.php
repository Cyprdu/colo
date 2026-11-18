<?php
require_once __DIR__ . '/admin_lib.php';
adm_guard();
if (!adm_is_supreme()) adm_forbidden(false, 'supreme');

$roles = adm_role_presets();
$cat = adm_perm_catalog();
$staff = adm_rows("SELECT u.id, u.nom, u.prenom, u.email, u.bloque,
    (SELECT MAX(created_at) FROM admin_audit a WHERE a.user_id = u.id) AS vu
    FROM users u WHERE u.is_admin = 1 ORDER BY u.nom, u.prenom");
$ur = [];
foreach (adm_rows("SELECT ur.user_id, r.code FROM adm_user_roles ur JOIN adm_roles r ON r.id = ur.role_id") as $r) $ur[(int)$r['user_id']][] = $r['code'];
$up = [];
foreach (adm_rows("SELECT user_id, perm FROM adm_user_perms") as $r) $up[(int)$r['user_id']][] = $r['perm'];
$rp = [];
foreach (adm_rows("SELECT r.code, rp.perm FROM adm_role_perms rp JOIN adm_roles r ON r.id = rp.role_id") as $r) $rp[$r['code']][] = $r['perm'];
$me = (int)$_SESSION['user']['id'];

function eq_roles(array $roles, array $on): string {
    $o = '<div class="chips">';
    foreach ($roles as $code => [$nom, $desc]) $o .= '<label class="chip-chk" title="' . h($desc) . '"><input type="checkbox" name="roles[]" value="' . h($code) . '"' . (in_array($code, $on, true) ? ' checked' : '') . '><span>' . h($nom) . '</span></label>';
    return $o . '</div>';
}
function eq_perms(array $cat, array $on, string $name = 'perms[]', bool $ro = false): string {
    $o = '<div class="perm-grid">';
    foreach ($cat as $g => $ps) {
        $o .= '<fieldset><legend>' . h($g) . '</legend>';
        foreach ($ps as $p => $l) $o .= '<label class="chk-l"><input type="checkbox" name="' . h($name) . '" value="' . h($p) . '"' . (in_array($p, $on, true) ? ' checked' : '') . ($ro ? ' disabled' : '') . '> ' . h($l) . '</label>';
        $o .= '</fieldset>';
    }
    return $o . '</div>';
}

adm_head('Équipe et rôles', 'admin_equipe.php', [['Réglages', ''], ['Équipe et rôles', '']]);
?>
<section class="card">
  <h2 class="sec"><?= ic('plus') ?> Ajouter un membre <?= adm_help("La personne doit déjà avoir un compte sur moncamp.fr. Elle accède ensuite à l'administration avec son mot de passe habituel.") ?></h2>
  <form data-api="team.add" data-ok="Membre ajouté." class="stack">
    <label class="fld"><span class="fl">E-mail du compte</span><input class="in" type="email" name="email" required maxlength="190" autocomplete="off"></label>
    <span class="fl">Rôles</span><?= eq_roles($roles, []) ?>
    <div class="row-l"><button class="btn" type="submit"><?= ic('check') ?> Ajouter</button></div>
  </form>
</section>

<h2 class="sec"><?= ic('users') ?> Membres <span class="count-pill"><?= count($staff) ?></span></h2>
<div class="tbl-wrap"><table class="tbl">
  <thead><tr><th>Membre</th><th>Rôles</th><th>Droits en plus</th><th>Dernière action</th><th class="act-h"></th></tr></thead>
  <tbody>
  <?php foreach ($staff as $s): $id = (int)$s['id']; $tok = adm_tok('u', $id); $r = $ur[$id] ?? []; $p = $up[$id] ?? []; ?>
    <tr>
      <td data-l="Membre"><b><?= h(trim($s['prenom'] . ' ' . $s['nom'])) ?></b><?= $id === $me ? ' <span class="pill">vous</span>' : '' ?><div class="muted small"><?= h($s['email']) ?></div></td>
      <td data-l="Rôles"><?php if (!$r): ?><span class="pill warn">Aucun</span><?php endif; foreach ($r as $c): ?><span class="pill<?= $c === 'supreme' ? ' dark' : '' ?>"><?= h($roles[$c][0] ?? $c) ?></span> <?php endforeach; ?></td>
      <td data-l="Droits en plus"><?= $p ? count($p) : '<span class="muted">—</span>' ?></td>
      <td data-l="Dernière action" class="muted small"><?= h(adm_date($s['vu'])) ?: '—' ?></td>
      <td class="acts">
        <details class="pop"><summary class="btn ghost sm"><?= ic('edit') ?> Droits</summary>
          <div class="pop-body">
            <form data-api="team.save" data-ok="Droits enregistrés.">
              <input type="hidden" name="u" value="<?= h($tok) ?>">
              <span class="fl">Rôles</span><?= eq_roles($roles, $r) ?>
              <span class="fl">Droits supplémentaires <?= adm_help('Ajoutés aux droits des rôles cochés.') ?></span><?= eq_perms($cat, $p) ?>
              <div class="row"><button class="btn" type="submit"><?= ic('check') ?> Enregistrer</button></div>
            </form>
          </div>
        </details>
        <?php if ($id !== $me): ?><button type="button" class="ib danger" data-api="team.remove" data-u="<?= h($tok) ?>" data-confirm="Retirer l'accès administration de <?= h($s['email']) ?> ?" title="Retirer l'accès" aria-label="Retirer l'accès"><?= ic('x') ?></button><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<h2 class="sec"><?= ic('key') ?> Rôles <?= adm_help("Les droits d'un rôle s'appliquent à tous ses membres. Le rôle suprême a tous les droits et n'est pas modifiable.") ?></h2>
<div class="cols">
<?php foreach ($roles as $code => [$nom, $desc]): ?>
  <section class="card">
    <h3 class="h3"><?= h($nom) ?></h3><p class="muted small"><?= h($desc) ?></p>
    <?php if ($code === 'supreme'): ?><p class="pill dark">Tous les droits</p>
    <?php else: ?>
    <details><summary class="lnk"><?= count($rp[$code] ?? []) ?> droits · modifier</summary>
      <form data-api="role.save" data-ok="Rôle enregistré."><input type="hidden" name="role" value="<?= h($code) ?>"><?= eq_perms($cat, $rp[$code] ?? []) ?>
        <div class="row"><button class="btn" type="submit"><?= ic('check') ?> Enregistrer</button></div></form>
    </details>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
<?php adm_foot(); ?>
