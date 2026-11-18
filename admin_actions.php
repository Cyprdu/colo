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

function act_toks(array $in, string $kind): array {
    $v = $in['ids'] ?? [];
    $v = is_array($v) ? $v : explode(',', (string)$v);
    $ids = array_values(array_unique(array_filter(array_map(fn($t) => adm_untok($kind, trim((string)$t)), array_slice($v, 0, 500)))));
    if (!$ids) throw new AdmUserError('Aucun élément sélectionné.');
    return $ids;
}

function act_contact(array $in): array {
    $id = adm_untok('cm', (string)($in['m'] ?? ''));
    $m = $id ? adm_rows("SELECT * FROM contact_messages WHERE id = ?", [$id])[0] ?? null : null;
    if (!$m) throw new AdmUserError('Message introuvable.');
    return $m;
}
function act_me(): string { $u = $_SESSION['user']; return trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')) ?: (string)$u['email']; }

function act_demande(array $in): array {
    require_once __DIR__ . '/api/demande_lib.php';
    $t = (string)($in['d'] ?? '');
    $d = preg_match('/^[a-f0-9]{32}$/', $t) ? adm_rows("SELECT * FROM demandes_organisateur WHERE token = ?", [$t])[0] ?? null : null;
    if (!$d) throw new AdmUserError('Demande introuvable.');
    return $d;
}
function act_demande_decide(array $d, string $statut, ?string $motif): void {
    global $pdo;
    if (!in_array($d['statut'], ['attente', 'complements'], true)) throw new AdmUserError('Cette demande est déjà traitée.');
    $uid = (int)$_SESSION['user']['id'];
    $before = adm_snap('users', 'id', (int)$d['user_id'], ['is_directeur', 'demande_en_cours', 'is_refused']);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE demandes_organisateur SET statut = ?, motif = ?, decide_par = ?, decide_at = NOW() WHERE id = ?")->execute([$statut, $motif, $uid, $d['id']]);
        if ($statut === 'validee') $pdo->prepare("UPDATE users SET is_directeur = 1, demande_en_cours = 0, is_refused = 0 WHERE id = ?")->execute([$d['user_id']]);
        if ($statut === 'refusee') $pdo->prepare("UPDATE users SET demande_en_cours = 0, is_refused = 1 WHERE id = ?")->execute([$d['user_id']]);
        demande_event($pdo, (int)$d['id'], $statut, $motif, $uid, act_me());
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    adm_audit('demande_' . $statut, 'demandes_organisateur#' . $d['id'], $d['structure'], $before + ['statut' => $d['statut']], adm_snap('users', 'id', (int)$d['user_id'], ['is_directeur', 'demande_en_cours', 'is_refused']) + ['statut' => $statut]);
    $site = demande_site_url();
    $h = fn($v) => htmlspecialchars((string)$v);
    $mail = [
        'validee' => ['Votre accès organisateur est activé', '<p>Bonne nouvelle : votre demande pour <b>' . $h($d['structure']) . '</b> est acceptée. Vous pouvez créer votre organisme et publier vos séjours.</p><p><a href="' . $h($site . '/organisateurs') . '">Ouvrir l’espace organisateur</a></p>'],
        'refusee' => ['Votre demande organisateur', '<p>Votre demande pour <b>' . $h($d['structure']) . '</b> n’a pas été acceptée.</p><p>' . nl2br($h($motif)) . '</p><p>Vous pouvez répondre à cet e-mail ou envoyer une nouvelle demande depuis votre profil.</p>'],
        'complements' => ['Votre demande organisateur : compléments nécessaires', '<p>Pour étudier votre demande pour <b>' . $h($d['structure']) . '</b>, nous avons besoin de quelques précisions :</p><p>' . nl2br($h($motif)) . '</p><p><a href="' . $h($site . '/devenir-organisateur') . '">Compléter ma demande</a></p>'],
    ][$statut];
    demande_mail_user($pdo, $d, $mail[0], $mail[1]);
}

function act_mois(array $in): string {
    $m = (string)($in['mois'] ?? '');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) throw new AdmUserError('Mois invalide.');
    return $m;
}

return [
    'compta.close' => ['compta.close', true, function (array $in) {
        global $pdo;
        require_once __DIR__ . '/api/compta_lib.php';
        $m = act_mois($in);
        if ($m >= date('Y-m')) throw new AdmUserError('On ne clôture qu’un mois terminé.');
        $c = adm_rows("SELECT * FROM compta_clotures WHERE mois = ?", [$m])[0] ?? null;
        if ($c && !$c['rouvert_at']) throw new AdmUserError('Ce mois est déjà clôturé.');
        $tot = compta_cloture_totaux($pdo, $m);
        $emp = compta_cloture_empreinte($pdo, $m);
        $nb = array_sum(array_column($tot, 'n'));
        $pdo->prepare("REPLACE INTO compta_clotures (mois, totaux, empreinte, nb, par, par_nom, created_at, rouvert_par, rouvert_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, NULL)")
            ->execute([$m, json_encode($tot), $emp, $nb, (int)$_SESSION['user']['id'], act_me()]);
        adm_audit('compta_cloture', "mois:$m", "$nb écritures", null, ['totaux' => $tot, 'empreinte' => $emp]);
        return ['msg' => "Mois $m clôturé."];
    }],
    'compta.reopen' => ['supreme', true, function (array $in) {
        global $pdo;
        $m = act_mois($in);
        $c = adm_rows("SELECT * FROM compta_clotures WHERE mois = ? AND rouvert_at IS NULL", [$m])[0] ?? null;
        if (!$c) throw new AdmUserError('Ce mois n’est pas clôturé.');
        $pdo->prepare("UPDATE compta_clotures SET rouvert_par = ?, rouvert_at = NOW() WHERE mois = ?")->execute([(int)$_SESSION['user']['id'], $m]);
        adm_audit('compta_reouverture', "mois:$m", null, ['empreinte' => $c['empreinte'], 'totaux' => json_decode($c['totaux'], true)], null);
        return ['msg' => "Mois $m rouvert."];
    }],
    'pay.stripe' => ['paiements.read', false, function (array $in) {
        require_once __DIR__ . '/api/finance_lib.php';
        $id = adm_untok('pay', (string)($in['t'] ?? ''));
        $p = $id ? adm_rows("SELECT p.*, s.account_id FROM fin_paiements p LEFT JOIN stripe_comptes s ON s.organisateur_id = p.organisateur_id WHERE p.id = ?", [$id])[0] ?? null : null;
        if (!$p || !$p['payment_intent']) throw new AdmUserError('Pas de paiement Stripe associé.');
        $opt = $p['mode'] === 'direct' && $p['account_id'] ? ['stripe_account' => $p['account_id']] : [];
        try {
            $pi = fin_stripe()->paymentIntents->retrieve($p['payment_intent'], ['expand' => ['latest_charge.balance_transaction', 'latest_charge.refunds']], $opt);
        } catch (Throwable $e) { error_log('pay.stripe: ' . $e->getMessage()); throw new AdmUserError('Stripe ne répond pas ou ne connaît pas ce paiement.'); }
        $ch = is_object($pi->latest_charge ?? null) ? $pi->latest_charge : null;
        $bt = $ch && is_object($ch->balance_transaction ?? null) ? $ch->balance_transaction : null;
        $eur = fn($c) => number_format(((int)$c) / 100, 2, ',', ' ') . ' €';
        $l = ['Statut' => (string)$pi->status, 'Montant' => $eur($pi->amount), 'Reçu' => $eur($pi->amount_received), 'Charge' => $ch ? (string)$ch->id : '—',
              'Remboursé' => $ch ? $eur($ch->amount_refunded) : '—', 'Litige' => $ch && !empty($ch->disputed) ? 'oui' : 'non',
              'Frais Stripe' => $bt ? $eur($bt->fee) : '—', 'Net Stripe' => $bt ? $eur($bt->net) : '—', 'Commission (application fee)' => isset($pi->application_fee_amount) ? $eur($pi->application_fee_amount) : '—',
              'Carte' => $ch && isset($ch->payment_method_details->card) ? strtoupper((string)$ch->payment_method_details->card->brand) . ' · ' . (string)$ch->payment_method_details->card->country : '—',
              'Créé chez Stripe' => date('d/m/Y H:i', (int)$pi->created)];
        adm_audit('paiement_stripe_consulte', 'fin_paiements#' . $id);
        return ['stripe' => $l];
    }],
    'demande.valider' => ['demandes.write', false, function (array $in) {
        act_demande_decide(act_demande($in), 'validee', null);
        return ['msg' => 'Accès organisateur accordé. Le demandeur est prévenu.'];
    }],
    'demande.refuser' => ['demandes.write', false, function (array $in) {
        $m = trim((string)($in['motif'] ?? ''));
        if (mb_strlen($m) < 10) throw new AdmUserError('Expliquez le motif (10 caractères minimum).');
        act_demande_decide(act_demande($in), 'refusee', mb_substr($m, 0, 2000));
        return ['msg' => 'Demande refusée. Le demandeur est prévenu.'];
    }],
    'demande.complements' => ['demandes.write', false, function (array $in) {
        $m = trim((string)($in['motif'] ?? ''));
        if (mb_strlen($m) < 10) throw new AdmUserError('Précisez ce qu’il manque (10 caractères minimum).');
        act_demande_decide(act_demande($in), 'complements', mb_substr($m, 0, 2000));
        return ['msg' => 'Demande de compléments envoyée.'];
    }],
    'demande.contact' => ['', false, function (array $in) {
        global $pdo;
        if (!adm_can('demandes.write') && !adm_can('contacts.write')) adm_forbidden(true, 'demandes.write');
        $d = act_demande($in);
        $objet = mb_substr(trim((string)($in['objet'] ?? '')), 0, 150);
        $texte = trim((string)($in['texte'] ?? ''));
        if ($objet === '' || $texte === '' || mb_strlen($texte) > 5000) throw new AdmUserError('Objet et message obligatoires.');
        $ok = demande_mail_user($pdo, $d, $objet, '<p>' . nl2br(htmlspecialchars($texte)) . '</p>');
        demande_event($pdo, (int)$d['id'], 'contact', $objet . "\n" . $texte, (int)$_SESSION['user']['id'], act_me());
        adm_audit('demande_contact', 'demandes_organisateur#' . $d['id'], $objet);
        return ['msg' => $ok ? 'E-mail envoyé.' : 'E-mail non parti (serveur mail).'];
    }],
    'demande.check' => ['demandes.read', false, function (array $in) {
        global $pdo;
        $d = act_demande($in);
        $u = demande_site_norm((string)$d['site']);
        $host = (string)parse_url($u, PHP_URL_HOST);
        $ip = $host !== '' ? gethostbyname($host) : '';
        if ($u === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return ['msg' => 'Site injoignable : le nom de domaine ne répond pas.'];
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'moncamp.fr verification', CURLOPT_RESOLVE => [$host . ':443:' . $ip, $host . ':80:' . $ip]]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $msg = $code >= 200 && $code < 400 ? "Site en ligne (HTTP $code)." : ($code ? "Le site répond avec une erreur (HTTP $code)." : 'Site injoignable.');
        demande_event($pdo, (int)$d['id'], 'site', $msg, (int)$_SESSION['user']['id'], act_me());
        return ['msg' => $msg];
    }],
    'note.add' => ['notes.write', false, function (array $in) {
        global $pdo;
        $objet = (string)($in['objet'] ?? '');
        $need = ['contact' => 'contacts.read', 'demande' => 'demandes.read', 'paiement' => 'paiements.read', 'orga' => 'orgas.read', 'user' => 'users.read', 'mois' => 'compta.read'];
        if (!isset($need[$objet]) || !adm_can($need[$objet])) throw new AdmUserError('Objet non autorisé.');
        $ref = (string)($in['ref'] ?? '');
        $texte = trim((string)($in['texte'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{6,80}$/', $ref) || $texte === '' || mb_strlen($texte) > 2000) throw new AdmUserError('Note vide ou trop longue.');
        $pdo->prepare("INSERT INTO adm_notes (objet, ref, user_id, auteur, texte) VALUES (?, ?, ?, ?, ?)")->execute([$objet, $ref, (int)$_SESSION['user']['id'], act_me(), $texte]);
        adm_audit('note_ajout', "$objet:$ref", mb_substr($texte, 0, 300));
        return [];
    }],
    'contact.bulk' => ['contacts.write', false, function (array $in) {
        global $pdo;
        $op = (string)($in['op'] ?? '');
        $sql = ['read' => "lu = 1", 'unread' => "lu = 0", 'archive' => "dossier = 'archive'", 'inbox' => "dossier = 'inbox', spam_raison = NULL", 'spam' => "dossier = 'spam', lu = 1"];
        $v = $in['ids'] ?? ($in['m'] ?? []);
        $ids = act_toks(['ids' => $v], 'cm');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if ($op === 'delete') {
            adm_need_reauth_api();
            $mails = array_column(adm_rows("SELECT email FROM contact_messages WHERE id IN ($ph)", $ids), 'email');
            $pdo->prepare("DELETE FROM contact_reponses WHERE message_id IN ($ph)")->execute($ids);
            $pdo->prepare("DELETE FROM contact_messages WHERE id IN ($ph)")->execute($ids);
            adm_audit('contact_suppression', 'contact_messages', count($ids) . ' message(s)', ['emails' => $mails], null);
            return ['msg' => count($ids) . ' message(s) supprimé(s).'];
        }
        if (!isset($sql[$op])) throw new AdmUserError('Action inconnue.');
        $pdo->prepare("UPDATE contact_messages SET {$sql[$op]}, maj = NOW() WHERE id IN ($ph)")->execute($ids);
        adm_audit('contact_' . $op, 'contact_messages', implode(',', array_map(fn($i) => '#' . $i, $ids)));
        return ['msg' => count($ids) . ' message(s) mis à jour.'];
    }],
    'contact.set' => ['contacts.write', false, function (array $in) {
        global $pdo;
        $m = act_contact($in);
        $etat = in_array($in['etat'] ?? '', ['nouveau', 'en_cours', 'traite'], true) ? $in['etat'] : $m['etat'];
        $as = ($in['assigne'] ?? '') === '' ? null : adm_untok('u', (string)$in['assigne']);
        if ($as !== null && (!$as || !adm_can('contacts.read', $as))) throw new AdmUserError('Ce membre n’a pas accès aux messages.');
        $before = ['etat' => $m['etat'], 'assigne_a' => $m['assigne_a']];
        $pdo->prepare("UPDATE contact_messages SET etat = ?, assigne_a = ?, statut = IF(? = 'traite', 'Traité', statut), maj = NOW() WHERE id = ?")->execute([$etat, $as, $etat, $m['id']]);
        adm_audit('contact_statut', 'contact_messages#' . $m['id'], null, $before, ['etat' => $etat, 'assigne_a' => $as]);
        if ($as && $as !== (int)$_SESSION['user']['id'] && $as !== (int)$m['assigne_a']) adm_notif_add('contact', 'a' . $m['id'] . ':' . $as . ':' . time(), 'Message assigné : ' . html_entity_decode((string)$m['motif']), 'admin_contacts.php?m=' . $m['token'], 'contacts.read');
        return [];
    }],
    'contact.reply' => ['contacts.write', false, function (array $in) {
        global $pdo;
        $m = act_contact($in);
        $texte = trim((string)($in['texte'] ?? ''));
        if ($texte === '' || mb_strlen($texte) > 5000) throw new AdmUserError('La réponse doit faire entre 1 et 5000 caractères.');
        $dec = fn($v) => html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $link = rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/') . '/view_reply.php?t=' . rawurlencode((string)$m['token']);
        $html = '<p>Bonjour ' . h($dec($m['prenom'])) . ',</p><p>' . nl2br(h($texte)) . '</p>'
              . '<p style="color:#6b7780;font-size:13px;border-left:3px solid #e5e7eb;padding-left:10px">Votre message : « ' . h(mb_substr($dec($m['message']), 0, 400)) . ' »</p>'
              . '<p><a href="' . h($link) . '">Retrouver cette réponse sur moncamp.fr</a></p>';
        $sent = adm_mail((string)$m['email'], 'Re : ' . $dec($m['motif']) . ' · moncamp.fr', $html);
        $traite = ($in['traite'] ?? '') === '1';
        $pdo->prepare("INSERT INTO contact_reponses (message_id, user_id, auteur, texte, envoye) VALUES (?, ?, ?, ?, ?)")->execute([$m['id'], (int)$_SESSION['user']['id'], act_me(), $texte, $sent ? 1 : 0]);
        $pdo->prepare("UPDATE contact_messages SET reponse = ?, replied_at = NOW(), lu = 1, maj = NOW(), etat = ?, statut = ? WHERE id = ?")
            ->execute([nl2br(h($texte)), $traite ? 'traite' : 'en_cours', 'Traité', $m['id']]);
        adm_audit('contact_reponse', 'contact_messages#' . $m['id'], $sent ? 'e-mail envoyé' : 'e-mail NON envoyé', ['etat' => $m['etat']], ['etat' => $traite ? 'traite' : 'en_cours']);
        return ['msg' => $sent ? 'Réponse envoyée.' : 'Réponse enregistrée, mais l’e-mail n’a pas pu partir.'];
    }],
    'notif.read' => ['', false, function (array $in) {
        global $pdo;
        $uid = (int)$_SESSION['user']['id'];
        $ids = act_toks($in, 'n');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if (($in['read'] ?? '1') === '1') $pdo->prepare("INSERT IGNORE INTO adm_notif_lus (notif_id, user_id) SELECT id, ? FROM adm_notifs WHERE id IN ($ph)")->execute(array_merge([$uid], $ids));
        else $pdo->prepare("DELETE FROM adm_notif_lus WHERE user_id = ? AND notif_id IN ($ph)")->execute(array_merge([$uid], $ids));
        return [];
    }],
    'notif.allread' => ['', false, function (array $in) {
        global $pdo;
        $uid = (int)$_SESSION['user']['id'];
        [$w, $p] = adm_notif_where();
        $type = (string)($in['type'] ?? '');
        if ($type !== '') { $w .= ' AND n.type = ?'; $p[] = $type; }
        $role = (string)($in['role'] ?? '');
        if ($role !== '') {
            $rp = array_column(adm_rows("SELECT rp.perm FROM adm_role_perms rp JOIN adm_roles r ON r.id = rp.role_id WHERE r.code = ?", [$role]), 'perm');
            $w .= $rp ? ' AND n.perm IN (' . implode(',', array_fill(0, count($rp), '?')) . ')' : ' AND 0=1';
            $p = array_merge($p, $rp);
        }
        $pdo->prepare("INSERT IGNORE INTO adm_notif_lus (notif_id, user_id) SELECT n.id, ? FROM adm_notifs n WHERE $w")->execute(array_merge([$uid], $p));
        return [];
    }],
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
