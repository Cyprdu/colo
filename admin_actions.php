<?php
if (!defined('ADMIN_LIB')) { http_response_code(404); exit; }

function act_user(array $in, string $k = 'u'): array {
    $id = adm_untok('u', (string)($in[$k] ?? ''));
    $u = $id ? adm_rows("SELECT id, nom, prenom, email, is_admin, is_directeur, bloque FROM users WHERE id = ?", [$id])[0] ?? null : null;
    if (!$u) throw new AdmUserError('Utilisateur introuvable.');
    return $u;
}
function act_list($v): array { return is_array($v) ? array_values(array_filter(array_map('strval', $v), fn($x) => $x !== '')) : []; }
function act_staff_state(int $id): array {
    return ['roles' => array_column(adm_rows("SELECT r.code FROM adm_user_roles ur JOIN adm_roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.code", [$id]), 'code'),
            'perms' => array_column(adm_rows("SELECT perm FROM adm_user_perms WHERE user_id = ? ORDER BY perm", [$id]), 'perm'),
            'is_admin' => (int)adm_one("SELECT is_admin FROM users WHERE id = ?", [$id])];
}
function act_supremes(): int { return (int)adm_one("SELECT COUNT(*) FROM adm_user_roles ur JOIN adm_roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id WHERE r.code = 'supreme' AND u.is_admin = 1"); }
function act_set_staff(int $id, array $roles, array $perms): void {
    global $pdo;
    $codes = array_keys(adm_role_presets());
    $roles = array_values(array_intersect($roles, $codes));
    $perms = array_values(array_intersect($perms, adm_perm_list()));
    $me = (int)$_SESSION['user']['id'];
    $wasSup = adm_is_supreme($id);
    if ($wasSup && !in_array('supreme', $roles, true) && act_supremes() <= 1) throw new AdmUserError('Il doit rester au moins un administrateur suprême.');
    if ($id === $me && $wasSup && !in_array('supreme', $roles, true)) throw new AdmUserError('Vous ne pouvez pas retirer votre propre rôle suprême.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET is_admin = 1 WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM adm_user_roles WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM adm_user_perms WHERE user_id = ?")->execute([$id]);
        $ir = $pdo->prepare("INSERT INTO adm_user_roles (user_id, role_id, par) SELECT ?, id, ? FROM adm_roles WHERE code = ?");
        foreach ($roles as $r) $ir->execute([$id, $me, $r]);
        $ip = $pdo->prepare("INSERT INTO adm_user_perms (user_id, perm, par) VALUES (?, ?, ?)");
        foreach ($perms as $p) $ip->execute([$id, $p, $me]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

return [
    'team.add' => ['supreme', true, function (array $in) {
        $email = trim((string)($in['email'] ?? ''));
        $id = (int)adm_one("SELECT id FROM users WHERE email = ?", [$email]);
        if (!$id) throw new AdmUserError("Aucun compte avec cet e-mail : la personne doit d'abord créer un compte sur le site.");
        if ((int)adm_one("SELECT is_admin FROM users WHERE id = ?", [$id]) === 1) throw new AdmUserError('Ce compte fait déjà partie de l’équipe.');
        $roles = act_list($in['roles'] ?? []);
        if (!$roles) throw new AdmUserError('Choisissez au moins un rôle.');
        $before = act_staff_state($id);
        act_set_staff($id, $roles, act_list($in['perms'] ?? []));
        adm_audit('equipe_ajout', "users#$id", $email, $before, act_staff_state($id));
        return ['msg' => 'Membre ajouté.'];
    }],
    'team.save' => ['supreme', true, function (array $in) {
        $u = act_user($in);
        $before = act_staff_state((int)$u['id']);
        act_set_staff((int)$u['id'], act_list($in['roles'] ?? []), act_list($in['perms'] ?? []));
        adm_audit('equipe_droits', 'users#' . $u['id'], $u['email'], $before, act_staff_state((int)$u['id']));
        return ['msg' => 'Droits enregistrés.'];
    }],
    'team.remove' => ['supreme', true, function (array $in) {
        global $pdo;
        $u = act_user($in);
        $id = (int)$u['id'];
        if ($id === (int)$_SESSION['user']['id']) throw new AdmUserError('Vous ne pouvez pas vous retirer vous-même.');
        if (adm_is_supreme($id) && act_supremes() <= 1) throw new AdmUserError('Il doit rester au moins un administrateur suprême.');
        $before = act_staff_state($id);
        $pdo->prepare("DELETE FROM adm_user_roles WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM adm_user_perms WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE users SET is_admin = 0 WHERE id = ?")->execute([$id]);
        adm_audit('equipe_retrait', "users#$id", $u['email'], $before, act_staff_state($id));
        return ['msg' => 'Accès retiré.'];
    }],
    'role.save' => ['supreme', true, function (array $in) {
        global $pdo;
        $code = (string)($in['role'] ?? '');
        if ($code === 'supreme' || !isset(adm_role_presets()[$code])) throw new AdmUserError('Rôle non modifiable.');
        $rid = (int)adm_one("SELECT id FROM adm_roles WHERE code = ?", [$code]);
        $perms = array_values(array_intersect(act_list($in['perms'] ?? []), adm_perm_list()));
        $before = array_column(adm_rows("SELECT perm FROM adm_role_perms WHERE role_id = ? ORDER BY perm", [$rid]), 'perm');
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM adm_role_perms WHERE role_id = ?")->execute([$rid]);
        $ip = $pdo->prepare("INSERT INTO adm_role_perms (role_id, perm) VALUES (?, ?)");
        foreach ($perms as $p) $ip->execute([$rid, $p]);
        $pdo->commit();
        adm_audit('role_modifie', "adm_roles#$code", null, $before, $perms);
        return ['msg' => 'Rôle enregistré.'];
    }],
];
