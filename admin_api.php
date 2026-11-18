<?php
// admin_api.php : toutes les actions du back-office (JSON, POST uniquement).
// Chaque appel passe par adm_guard() : admin vérifié en base, session liée, jeton CSRF.
// Les actions sensibles exigent en plus une ré-authentification récente (mot de passe).
require_once __DIR__ . '/admin_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
adm_guard(true);

class AdmUserError extends Exception {}   // message montrable à l'écran

const DB_PROTECTED = ['admin_audit', 'admin_attempts'];   // lecture seule, jamais modifiables depuis l'interface

$raw = file_get_contents('php://input');
if (strlen((string)$raw) > 300000) adm_json(['error' => 'Requête trop volumineuse.'], 413);
$in = json_decode((string)$raw, true);
if (!is_array($in)) $in = [];
$uid = (int)$_SESSION['user']['id'];

/** Message d'erreur sûr : jamais le texte SQL brut (il est journalisé côté serveur). */
function adm_err(Throwable $e): string {
    if ($e instanceof AdmUserError) return $e->getMessage();
    error_log('admin_api: ' . $e->getMessage());
    if ($e instanceof PDOException) {
        $c = (int)($e->errorInfo[1] ?? 0);
        $m = [1064 => 'Erreur de syntaxe SQL.', 1146 => 'Table inconnue.', 1054 => 'Colonne inconnue.', 1062 => 'Valeur en double (contrainte d\'unicité).',
              1451 => 'Suppression impossible : des données y sont liées.', 1452 => 'Référence invalide (donnée liée introuvable).', 1048 => 'Un champ obligatoire est vide.',
              1265 => 'Valeur invalide pour ce champ.', 1366 => 'Valeur invalide pour ce champ.', 1292 => 'Valeur invalide pour ce champ.', 1136 => 'Nombre de valeurs incorrect.',
              1205 => 'Délai dépassé (verrou).', 3024 => 'Requête trop longue, interrompue.', 1969 => 'Requête trop longue, interrompue.'];
        return $m[$c] ?? ('Erreur de base de données (code ' . $c . ').');
    }
    return 'Opération impossible.';
}
function need_id(array $in, string $table): int { $id = adm_untok('t:' . $table, (string)($in['id'] ?? '')); if ($id <= 0) throw new AdmUserError('Élément introuvable.'); return $id; }
function ok(array $extra = []) { adm_json(['ok' => true] + $extra); }

/* -------------------------------------------------------- base de données */
function db_tables(): array { global $pdo; return $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN); }
function db_table(string $t): string {
    if (!in_array($t, db_tables(), true)) throw new AdmUserError('Table inconnue.');
    return '`' . str_replace('`', '', $t) . '`';
}
function db_cols(string $t): array {
    global $pdo;
    return $pdo->query("SHOW FULL COLUMNS FROM " . db_table($t))->fetchAll(PDO::FETCH_ASSOC);
}
function db_pk(array $cols): ?string {
    $p = array_values(array_filter($cols, fn($c) => $c['Key'] === 'PRI'));
    return count($p) === 1 ? $p[0]['Field'] : null;
}
function db_mask(array $row): array {
    foreach ($row as $k => $v) if ($v !== null && adm_sensitive((string)$k)) $row[$k] = '••••••';
    return $row;
}
function db_writable(string $t): void {
    if (in_array($t, DB_PROTECTED, true)) throw new AdmUserError('Cette table est protégée (journal de sécurité) : lecture seule.');
}

/* --------------------------------------------------------------- console SQL */
function sql_strip(string $sql): string {   // retire les littéraux pour analyser la structure
    return (string)preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`[^`]*`/s', "''", $sql);
}
/** [kind, sql nettoyé]. kind : read | write | ddl. Lève AdmUserError si refusé. */
function sql_classify(string $sql): array {
    $sql = trim($sql); $sql = rtrim($sql, "; \t\r\n");
    if ($sql === '') throw new AdmUserError('Requête vide.');
    $s = sql_strip($sql);
    if (preg_match('/;|--|#|\/\*/', $s)) throw new AdmUserError('Une seule requête à la fois, sans commentaires.');
    if (!preg_match('/^\s*([A-Za-z]+)/', $s, $m)) throw new AdmUserError('Requête non reconnue.');
    $kw = strtoupper($m[1]);
    if (preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|LOAD_FILE|LOAD\s+DATA|SLEEP\s*\(|BENCHMARK\s*\(|GET_LOCK|RELEASE_LOCK)/i', $s)) throw new AdmUserError('Cette instruction est interdite.');
    if (in_array($kw, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
        if ($kw === 'EXPLAIN' && !preg_match('/^\s*EXPLAIN\s+SELECT\b/i', $s)) throw new AdmUserError('EXPLAIN SELECT uniquement.');
        return ['read', $sql];
    }
    $prot = implode('|', DB_PROTECTED);
    if (preg_match('/\b(' . $prot . ')\b/i', $sql)) throw new AdmUserError('Le journal de sécurité ne peut pas être modifié.');
    if (in_array($kw, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)) return ['write', $sql];
    if (in_array($kw, ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'], true)) return ['ddl', $sql];
    throw new AdmUserError('Instruction non autorisée (SELECT, SHOW, INSERT, UPDATE, DELETE, REPLACE, CREATE, ALTER, DROP, TRUNCATE, RENAME).');
}
/** Écriture sans filtre : UPDATE/DELETE sans WHERE. */
function sql_unfiltered(string $sql): bool {
    return (bool)preg_match('/^\s*(UPDATE|DELETE)\b/i', $sql) && !preg_match('/\bWHERE\b/i', sql_strip($sql));
}

try {
    $a = (string)($in['a'] ?? '');

    /* ------------------------------------------------------ ré-authentification */
    if ($a === 'reauth') {
        [$okk, $msg, $code] = adm_reauth((string)($in['password'] ?? ''));
        if (!$okk) adm_json(['error' => $msg], $code);
        ok(['ttl' => ADM_REAUTH_TTL, 'csrf' => adm_csrf()]);
    }

    /* ----------------------------------------------------- actions sur les entités */
    if ($a === 'act') {
        $act = (string)($in['act'] ?? '');
        $ents = adm_entities();
        $need = ['user.accept' => 'demandes.write', 'user.deny' => 'demandes.write', 'user.revoke' => 'demandes.write', 'user.verify' => 'users.write',
                 'user.block' => 'users.write', 'virement.done' => 'versements.write', 'virement.iban' => 'versements.write', 'contact.reply' => 'contacts.write'];
        $perm = $need[$act] ?? (preg_match('/^(camp|boost)\./', $act) ? 'camps.write' : '');
        if ($act === 'row.delete') $perm = ['users' => 'users.rgpd', 'organismes' => 'orgas.write', 'inscriptions' => 'inscriptions.write', 'contacts' => 'contacts.write'][(string)($in['e'] ?? '')] ?? '';
        if ($perm === '') adm_json(['error' => 'Action inconnue.'], 400);
        adm_need($perm);
        // Actions sensibles : réauthentification obligatoire
        if (in_array($act, ['user.block', 'row.delete', 'camp.delete', 'virement.done', 'virement.iban'], true)) adm_need_reauth_api();
        if (adm_limited('write', 'u' . $uid, 90, 1)) adm_json(['error' => 'Trop d\'actions en peu de temps. Patientez une minute.'], 429);
        adm_hit('write', 'u' . $uid);
        $tbl = ['user' => 'users', 'camp' => 'camps', 'boost' => 'camps', 'virement' => 'virements', 'contact' => 'contact_messages'][explode('.', $act)[0]] ?? (($ents[(string)($in['e'] ?? '')] ?? [])['table'] ?? '');
        $id = need_id($in, $tbl);
        $snapT = ['user' => ['users', ['is_directeur', 'demande_en_cours', 'is_refused', 'is_verified']], 'camp' => ['camps', ['valide', 'en_attente', 'refuse', 'prive', 'supprime']],
                  'boost' => ['camps', ['boost_vedette_fin', 'boost_urgence_fin', 'date_bump']], 'virement' => ['virements', ['effectue', 'date_virement_effectue']]][explode('.', $act)[0]] ?? null;
        $snapBefore = $snapT ? adm_snap($snapT[0], 'id', $id, $snapT[1]) : null;

        switch ($act) {
        case 'user.accept': $pdo->prepare("UPDATE users SET is_directeur = 1, demande_en_cours = 0, is_refused = 0 WHERE id = ?")->execute([$id]); break;
        case 'user.deny':   $pdo->prepare("UPDATE users SET is_directeur = 0, demande_en_cours = 0, is_refused = 1 WHERE id = ?")->execute([$id]); break;
        case 'user.revoke': $pdo->prepare("UPDATE users SET is_directeur = 0, demande_en_cours = 0 WHERE id = ?")->execute([$id]); break;
        case 'user.verify': $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$id]); break;
        case 'user.block':
            if ($id === $uid) throw new AdmUserError('Vous ne pouvez pas vous bloquer vous-même.');
            if (adm_is_supreme($id)) throw new AdmUserError('Un administrateur suprême ne peut pas être bloqué.');
            $b = adm_snap('users', 'id', $id, ['bloque']);
            if (!$b) throw new AdmUserError('Utilisateur introuvable.');
            $pdo->prepare("UPDATE users SET bloque = 1 - bloque, remember_token = NULL WHERE id = ?")->execute([$id]);
            adm_audit('user.block', "users#$id", null, $b, adm_snap('users', 'id', $id, ['bloque']));
            ok();
        case 'camp.approve': $pdo->prepare("UPDATE camps SET valide = 1, en_attente = 0, refuse = 0 WHERE id = ?")->execute([$id]); break;
        case 'camp.deny':    $pdo->prepare("UPDATE camps SET valide = 0, en_attente = 0, refuse = 1 WHERE id = ?")->execute([$id]); break;
        case 'camp.requeue': $pdo->prepare("UPDATE camps SET valide = 0, refuse = 0, en_attente = 1 WHERE id = ?")->execute([$id]); break;
        case 'camp.hide':    $pdo->prepare("UPDATE camps SET prive = 1 - COALESCE(prive, 0) WHERE id = ?")->execute([$id]); break;
        case 'camp.restore': $pdo->prepare("UPDATE camps SET supprime = 0 WHERE id = ?")->execute([$id]); break;
        case 'camp.delete':  $pdo->prepare("UPDATE camps SET supprime = 1, prive = 1 WHERE id = ?")->execute([$id]); break;
        case 'boost.vedette': $pdo->prepare("UPDATE camps SET boost_vedette_fin = DATE_ADD(GREATEST(NOW(), COALESCE(boost_vedette_fin, NOW())), INTERVAL 7 DAY) WHERE id = ?")->execute([$id]); break;
        case 'boost.urgence': $pdo->prepare("UPDATE camps SET boost_urgence_fin = DATE_ADD(GREATEST(NOW(), COALESCE(boost_urgence_fin, NOW())), INTERVAL 3 DAY) WHERE id = ?")->execute([$id]); break;
        case 'boost.bump':    $pdo->prepare("UPDATE camps SET date_bump = NOW() WHERE id = ?")->execute([$id]); break;
        case 'boost.clear':   $pdo->prepare("UPDATE camps SET boost_vedette_fin = NULL, boost_urgence_fin = NULL WHERE id = ?")->execute([$id]); break;
        case 'virement.done':
            $st = $pdo->prepare("UPDATE virements SET effectue = 1, date_virement_effectue = NOW() WHERE id = ? AND effectue = 0");
            $st->execute([$id]);
            if (!$st->rowCount()) throw new AdmUserError('Ce virement est déjà marqué comme effectué.');
            break;
        case 'virement.iban':
            $st = $pdo->prepare("SELECT nom_organisme, iban, bic_swift, montant_apres_commission FROM virements WHERE id = ?");
            $st->execute([$id]);
            $v = $st->fetch(PDO::FETCH_ASSOC);
            if (!$v) throw new AdmUserError('Virement introuvable.');
            adm_audit('virement_coordonnees_vues', "virements#$id");
            ok(['iban' => $v]);
        case 'contact.reply':
            $text = trim((string)($in['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 5000) throw new AdmUserError('La réponse doit faire entre 1 et 5000 caractères.');
            $st = $pdo->prepare("UPDATE contact_messages SET reponse = ?, statut = 'Traité', replied_at = NOW() WHERE id = ? AND statut = 'En attente'");
            $st->execute([nl2br(h($text)), $id]);
            if (!$st->rowCount()) throw new AdmUserError('Ce message a déjà été traité.');
            $m = $pdo->prepare("SELECT email, prenom, message, token FROM contact_messages WHERE id = ?");
            $m->execute([$id]);
            $md = $m->fetch(PDO::FETCH_ASSOC);
            $sent = $md ? adm_mail_reply($md) : false;
            adm_audit('contact_reponse', "contact_messages#$id", $sent ? 'notification envoyée' : 'notification non envoyée');
            ok(['mail' => $sent]);
        case 'row.delete':
            $e = $ents[(string)($in['e'] ?? '')] ?? null;
            if (!$e) throw new AdmUserError('Entité inconnue.');
            $allowed = false; foreach ($e['actions'] as $ac) if ($ac[0] === 'row.delete') $allowed = true;
            if (!$allowed) throw new AdmUserError('Suppression non autorisée ici.');
            if ($e['table'] === 'users') {
                if ($id === $uid) throw new AdmUserError('Vous ne pouvez pas supprimer votre propre compte.');
                if ((int)adm_one("SELECT is_admin FROM users WHERE id = ?", [$id]) === 1) throw new AdmUserError('Retirez d\'abord les droits administrateur.');
                if ((int)adm_one("SELECT COUNT(*) FROM organisateurs WHERE user_id = ?", [$id]) > 0) throw new AdmUserError('Ce compte possède un organisme : supprimez-le d\'abord.');
            }
            $st = $pdo->prepare("DELETE FROM `" . $e['table'] . "` WHERE `" . $e['pk'] . "` = ? LIMIT 1");
            $st->execute([$id]);
            if (!$st->rowCount()) throw new AdmUserError('Élément introuvable.');
            adm_audit('suppression', $e['table'] . "#$id");
            ok();
        default: throw new AdmUserError('Action inconnue.');
        }
        adm_audit($act, ($snapT[0] ?? '') . "#$id", null, $snapBefore, $snapT ? adm_snap($snapT[0], 'id', $id, $snapT[1]) : null);
        ok();
    }

    /* ---------------------------------------- éditeur de base de données (réauth) */
    if (strpos($a, 'db.') === 0 || strpos($a, 'sql.') === 0) {
        adm_need(strpos($a, 'db.') === 0 ? 'tech.db' : 'tech.sql');
        adm_need_reauth_api();

        if ($a === 'db.tables') {
            $out = [];
            foreach (db_tables() as $t) {
                $n = null;
                try { $n = (int)$pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '', $t) . "`")->fetchColumn(); } catch (Throwable $e) { error_log('db.tables: ' . $e->getMessage()); }
                $out[] = ['name' => $t, 'rows' => $n, 'protected' => in_array($t, DB_PROTECTED, true)];
            }
            ok(['tables' => $out]);
        }

        if ($a === 'db.browse') {
            $t = (string)($in['t'] ?? '');
            $cols = db_cols($t); $names = array_column($cols, 'Field'); $tq = db_table($t); $pk = db_pk($cols);
            $page = max(1, (int)($in['page'] ?? 1)); $per = 25;
            $sort = in_array($in['sort'] ?? '', $names, true) ? $in['sort'] : ($pk ?? $names[0]);
            $dir = (($in['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
            $where = ''; $p = [];
            $q = trim((string)($in['q'] ?? ''));
            if ($q !== '') {
                $like = '%' . addcslashes($q, '%_\\') . '%'; $parts = [];
                foreach ($names as $n) { if (adm_sensitive($n)) continue; $parts[] = "CAST(`" . str_replace('`', '', $n) . "` AS CHAR) LIKE ?"; $p[] = $like; }
                if ($parts) $where = ' WHERE ' . implode(' OR ', $parts);
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM $tq$where"); $st->execute($p);
            $total = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT * FROM $tq$where ORDER BY `" . str_replace('`', '', $sort) . "` $dir LIMIT $per OFFSET " . (($page - 1) * $per));
            $st->execute($p);
            $rows = array_map('db_mask', $st->fetchAll(PDO::FETCH_ASSOC));
            ok(['cols' => array_map(fn($c) => ['name' => $c['Field'], 'type' => $c['Type'], 'null' => $c['Null'] === 'YES', 'key' => $c['Key'], 'auto' => stripos($c['Extra'], 'auto_increment') !== false, 'sensitive' => adm_sensitive($c['Field'])], $cols),
                'pk' => $pk, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'protected' => in_array($t, DB_PROTECTED, true), 'sort' => $sort, 'dir' => strtolower($dir)]);
        }

        if ($a === 'db.get') {
            $t = (string)($in['t'] ?? ''); $cols = db_cols($t); $pk = db_pk($cols);
            if ($pk === null) throw new AdmUserError('Cette table n a pas de clé primaire simple.');
            $st = $pdo->prepare("SELECT * FROM " . db_table($t) . " WHERE `$pk` = ? LIMIT 1"); $st->execute([$in['pk'] ?? null]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new AdmUserError('Ligne introuvable.');
            ok(['row' => db_mask($row), 'pk' => $pk, 'protected' => in_array($t, DB_PROTECTED, true),
                'cols' => array_map(fn($c) => ['name' => $c['Field'], 'type' => $c['Type'], 'null' => $c['Null'] === 'YES', 'key' => $c['Key'], 'auto' => stripos($c['Extra'], 'auto_increment') !== false, 'sensitive' => adm_sensitive($c['Field'])], $cols)]);
        }

        if (in_array($a, ['db.update', 'db.insert', 'db.delete'], true)) {
            if (adm_limited('write', 'u' . $uid, 90, 1)) adm_json(['error' => 'Trop d\'actions en peu de temps. Patientez une minute.'], 429);
            $t = (string)($in['t'] ?? ''); db_writable($t);
            $cols = db_cols($t); $pk = db_pk($cols); $tq = db_table($t);
            $byName = []; foreach ($cols as $c) $byName[$c['Field']] = $c;
            if ($a !== 'db.insert' && $pk === null) throw new AdmUserError('Cette table n\'a pas de clé primaire simple : modification impossible ici (utilisez la console SQL).');
            $pkv = $in['pk'] ?? null;
            if ($a === 'db.delete') {
                $st = $pdo->prepare("DELETE FROM $tq WHERE `$pk` = ? LIMIT 1"); $st->execute([$pkv]);
                if (!$st->rowCount()) throw new AdmUserError('Ligne introuvable.');
                adm_hit('write', 'u' . $uid); adm_audit('db_suppression', "$t#$pkv"); ok();
            }
            $vals = is_array($in['values'] ?? null) ? $in['values'] : [];
            $set = []; $p = []; $changed = [];
            foreach ($vals as $k => $v) {
                if (!isset($byName[$k]) || $k === $pk && $a === 'db.update') continue;
                $c = $byName[$k];
                if ($v !== null && !is_scalar($v)) continue;
                if (adm_sensitive($k)) {                              // champ sensible : vide = inchangé ; mot de passe = haché
                    if ($v === null || $v === '') continue;
                    if (preg_match('/pass/i', $k)) $v = password_hash((string)$v, PASSWORD_DEFAULT);
                } elseif ($v === '' && $c['Null'] === 'YES' && !preg_match('/char|text|enum/i', $c['Type'])) {
                    $v = null;                                        // nombre/date vide -> NULL
                }
                $set[] = "`" . str_replace('`', '', $k) . "` = ?"; $p[] = $v; $changed[] = $k;
            }
            if (!$set) throw new AdmUserError('Rien à enregistrer.');
            if ($a === 'db.update') {
                $p[] = $pkv;
                $st = $pdo->prepare("UPDATE $tq SET " . implode(', ', $set) . " WHERE `$pk` = ? LIMIT 1"); $st->execute($p);
                adm_hit('write', 'u' . $uid); adm_audit('db_modification', "$t#$pkv", 'colonnes : ' . implode(', ', $changed)); ok(['changed' => $st->rowCount()]);
            }
            $st = $pdo->prepare("INSERT INTO $tq SET " . implode(', ', $set)); $st->execute($p);
            adm_hit('write', 'u' . $uid); adm_audit('db_insertion', $t . '#' . $pdo->lastInsertId(), 'colonnes : ' . implode(', ', $changed)); ok(['id' => $pdo->lastInsertId()]);
        }

        if ($a === 'sql.run') {
            $sql = (string)($in['sql'] ?? '');
            $params = is_array($in['params'] ?? null) ? array_values($in['params']) : [];
            foreach ($params as $v) if (!is_scalar($v) && $v !== null) throw new AdmUserError('Paramètres invalides.');
            [$kind, $sql] = sql_classify($sql);
            if (substr_count(sql_strip($sql), '?') !== count($params)) throw new AdmUserError('Le nombre de paramètres (?) ne correspond pas.');
            if (adm_limited('sql', 'u' . $uid, 60, 1)) adm_json(['error' => 'Trop de requêtes. Patientez une minute.'], 429);
            adm_hit('sql', 'u' . $uid);
            $fp = hash('sha256', $sql . '|' . json_encode($params));

            if ($kind !== 'read') {
                $risky = $kind === 'ddl' || sql_unfiltered($sql);
                $c = $_SESSION['adm_sqlc'] ?? null;
                $good = is_array($c) && hash_equals((string)$c['fp'], $fp) && hash_equals((string)$c['tok'], (string)($in['confirm'] ?? '')) && time() < (int)$c['exp'];
                if (!$good) {   // étape 1 : on décrit ce qui va se passer et on émet un jeton à usage unique
                    $tok = bin2hex(random_bytes(16));
                    $_SESSION['adm_sqlc'] = ['fp' => $fp, 'tok' => $tok, 'exp' => time() + 300];
                    adm_json(['needs_confirm' => true, 'kind' => $kind, 'risky' => $risky, 'token' => $tok,
                        'message' => $kind === 'ddl' ? 'Modification de la structure de la base : irréversible.' : (sql_unfiltered($sql) ? 'Aucune clause WHERE : TOUTES les lignes seront touchées.' : 'Cette requête va modifier des données.')]);
                }
                if ($risky && ($in['typed'] ?? '') !== 'CONFIRMER') throw new AdmUserError('Tapez CONFIRMER pour valider.');
                unset($_SESSION['adm_sqlc']);
            }

            try { $pdo->exec("SET SESSION max_statement_time = 5"); } catch (Throwable $e) {}
            try { $pdo->exec("SET SESSION max_execution_time = 5000"); } catch (Throwable $e) {}
            $t0 = microtime(true);
            if ($kind === 'read') {
                $st = $pdo->prepare($sql); $st->execute($params);
                $rows = []; $trunc = false;
                while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) { if (count($rows) >= 500) { $trunc = true; break; } $rows[] = db_mask($r); }
                $st->closeCursor();
                adm_audit('sql_lecture', null, mb_substr($sql, 0, 1500));
                ok(['kind' => 'read', 'cols' => $rows ? array_keys($rows[0]) : [], 'rows' => $rows, 'truncated' => $trunc, 'ms' => (int)((microtime(true) - $t0) * 1000)]);
            }
            $started = false;
            if ($kind === 'write') { $pdo->beginTransaction(); $started = true; }
            try {
                $st = $pdo->prepare($sql); $st->execute($params); $n = $st->rowCount();
                if ($started) $pdo->commit();
            } catch (Throwable $e) { if ($started && $pdo->inTransaction()) $pdo->rollBack(); adm_audit('sql_echec', null, mb_substr($sql, 0, 1500)); throw $e; }
            adm_audit($kind === 'ddl' ? 'sql_structure' : 'sql_ecriture', null, mb_substr($sql, 0, 1500) . ' | lignes : ' . $n);
            ok(['kind' => $kind, 'affected' => $n, 'ms' => (int)((microtime(true) - $t0) * 1000)]);
        }
    }

    $H = require __DIR__ . '/admin_actions.php';
    if (isset($H[$a])) {
        [$perm, $sens, $fn] = $H[$a];
        if ($perm === 'supreme' ? !adm_is_supreme() : ($perm !== '' && !adm_can($perm))) adm_forbidden(true, $perm);
        if ($sens) adm_need_reauth_api();
        if (adm_limited('write', 'u' . $uid, 120, 1)) adm_json(['error' => 'Trop d’actions en peu de temps. Patientez une minute.'], 429);
        adm_hit('write', 'u' . $uid);
        ok($fn($in) ?: []);
    }

    adm_json(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    adm_json(['error' => adm_err($e)], $e instanceof AdmUserError ? 422 : 500);
}

/** Notification de réponse (lien vers la page publique, aucun contenu de la réponse dans le mail). */
function adm_mail_reply(array $m): bool {
    if (!filter_var($m['email'], FILTER_VALIDATE_EMAIL)) return false;
    $link = rtrim(BASE_URL, '/') . '/view_reply.php?t=' . rawurlencode((string)$m['token']);
    $body = '<p>Bonjour ' . h($m['prenom']) . ',</p><p>Notre équipe a répondu à votre demande : « ' . h(mb_substr((string)$m['message'], 0, 150)) . ' ».</p>'
          . '<p><a href="' . h($link) . '">Consulter la réponse</a></p><p>L\'équipe moncamp.fr</p>';
    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: moncamp.fr Support <auth@moncamp.fr>\r\nReply-To: auth@moncamp.fr\r\n";
    return @mail($m['email'], 'Une réponse vous attend - moncamp.fr', $body, $headers);
}
