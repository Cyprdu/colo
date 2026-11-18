<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'adm_core.php') { http_response_code(404); exit; }
if (defined('ADM_CORE')) return;
define('ADM_CORE', 1);
const ADM_SCHEMA = 5;

function adm_perm_catalog(): array {
    return [
        'Comptabilité' => [
            'compta.read' => 'Consulter la comptabilité et les revenus',
            'compta.export' => 'Exporter (CSV, Excel, FEC)',
            'compta.close' => 'Clôturer un mois',
            'compta.settings' => 'Régler commission et règles de versement',
            'notes.write' => 'Écrire des annotations internes',
        ],
        'Paiements' => [
            'paiements.read' => 'Voir paiements, remboursements, litiges',
            'paiements.write' => 'Remboursements, dettes, ajustements',
            'versements.read' => 'Voir les versements aux organismes',
            'versements.write' => 'Déclencher / retenter des versements',
        ],
        'Support' => [
            'contacts.read' => 'Lire les messages de contact',
            'contacts.write' => 'Répondre, classer, supprimer',
            'messagerie.read' => 'Consulter la messagerie',
            'users.read' => 'Voir les utilisateurs',
            'users.write' => 'Modifier, bloquer un utilisateur',
            'users.rgpd' => 'Export et suppression RGPD',
        ],
        'Modération' => [
            'demandes.read' => 'Voir les demandes organisateur',
            'demandes.write' => 'Valider / refuser les demandes',
            'camps.read' => 'Voir les séjours',
            'camps.write' => 'Valider, masquer, supprimer des séjours',
            'orgas.read' => 'Voir les organismes',
            'orgas.write' => 'Modifier les organismes',
            'inscriptions.read' => 'Voir les inscriptions',
            'inscriptions.write' => 'Modifier les inscriptions',
        ],
        'Technique' => [
            'tech.db' => 'Éditeur de base de données',
            'tech.sql' => 'Console SQL',
            'tech.logs' => 'Erreurs applicatives et journaux',
            'tech.crons' => 'Tâches planifiées',
            'tech.security' => 'Sécurité et tableau de contrôle',
            'tech.backup' => 'Sauvegardes',
            'audit.read' => "Journal d'audit",
            'audit.export' => "Exporter le journal d'audit",
        ],
    ];
}

function adm_perm_list(): array {
    $o = [];
    foreach (adm_perm_catalog() as $g) $o = array_merge($o, array_keys($g));
    return $o;
}

function adm_role_presets(): array {
    $read = array_values(array_filter(adm_perm_list(), fn($p) => str_ends_with($p, '.read') && !str_starts_with($p, 'tech.')));
    return [
        'supreme' => ['Administrateur suprême', 'Tous les droits, gère les rôles.', ['*']],
        'comptable' => ['Comptable', 'Comptabilité, revenus, exports, versements en lecture.', ['compta.read', 'compta.export', 'compta.close', 'notes.write', 'paiements.read', 'versements.read', 'orgas.read', 'inscriptions.read']],
        'support' => ['Support / messages', 'Messages de contact, messagerie, utilisateurs en lecture.', ['contacts.read', 'contacts.write', 'messagerie.read', 'users.read', 'demandes.read', 'inscriptions.read', 'notes.write']],
        'paiements' => ['Paiements et virements', 'Remboursements, versements, dettes, ajustements.', ['paiements.read', 'paiements.write', 'versements.read', 'versements.write', 'compta.read', 'orgas.read', 'inscriptions.read', 'notes.write']],
        'moderation' => ['Modération', 'Séjours, organismes, demandes organisateur.', ['camps.read', 'camps.write', 'orgas.read', 'orgas.write', 'demandes.read', 'demandes.write', 'users.read', 'notes.write']],
        'technique' => ['Technique', 'Base, console SQL, journaux, erreurs, crons, sécurité.', ['tech.db', 'tech.sql', 'tech.logs', 'tech.crons', 'tech.security', 'tech.backup', 'audit.read', 'audit.export']],
        'lecture' => ['Lecture seule', 'Consulte tout, ne modifie rien.', array_merge($read, ['audit.read'])],
    ];
}

function adm_core_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $v = (int)$pdo->query("SELECT v FROM adm_kv WHERE k = 'schema'")->fetchColumn(); } catch (Throwable $e) { $v = 0; }
    if ($v >= ADM_SCHEMA) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_kv (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_roles (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL UNIQUE, nom VARCHAR(80) NOT NULL, description VARCHAR(255) NULL, systeme TINYINT(1) NOT NULL DEFAULT 0) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_role_perms (role_id INT NOT NULL, perm VARCHAR(40) NOT NULL, PRIMARY KEY (role_id, perm)) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_user_roles (user_id INT NOT NULL, role_id INT NOT NULL, par INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, role_id), KEY idx_role (role_id)) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_user_perms (user_id INT NOT NULL, perm VARCHAR(40) NOT NULL, par INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, perm)) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_notifs (id BIGINT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL, ref VARCHAR(80) NOT NULL, titre VARCHAR(190) NOT NULL, lien VARCHAR(255) NOT NULL, perm VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_ref (type, ref), KEY idx_date (created_at)) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_notif_lus (notif_id BIGINT NOT NULL, user_id INT NOT NULL, lu_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, notif_id)) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adm_notes (id BIGINT AUTO_INCREMENT PRIMARY KEY, objet VARCHAR(20) NOT NULL, ref VARCHAR(80) NOT NULL, user_id INT NOT NULL, auteur VARCHAR(190) NOT NULL, texte TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_obj (objet, ref)) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, email VARCHAR(190) NULL, ip VARCHAR(45) NOT NULL DEFAULT '', action VARCHAR(60) NOT NULL, cible VARCHAR(190) NULL, detail TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_audit_date (created_at), INDEX idx_audit_action (action)) DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE admin_audit ADD COLUMN IF NOT EXISTS avant TEXT NULL, ADD COLUMN IF NOT EXISTS apres TEXT NULL"); } catch (Throwable $e) { error_log('adm_core audit cols: ' . $e->getMessage()); }
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS bloque TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { error_log('adm_core users.bloque: ' . $e->getMessage()); }
    try {
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS lu TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS dossier VARCHAR(10) NOT NULL DEFAULT 'inbox',
            ADD COLUMN IF NOT EXISTS etat VARCHAR(10) NOT NULL DEFAULT 'nouveau', ADD COLUMN IF NOT EXISTS assigne_a INT NULL, ADD COLUMN IF NOT EXISTS ip VARCHAR(45) NULL,
            ADD COLUMN IF NOT EXISTS spam_raison VARCHAR(120) NULL, ADD COLUMN IF NOT EXISTS maj DATETIME NULL");
        if ($v < 4) $pdo->exec("UPDATE contact_messages SET etat = 'traite', lu = 1 WHERE statut = 'Traité'");
        $pdo->exec("CREATE TABLE IF NOT EXISTS contact_reponses (id INT AUTO_INCREMENT PRIMARY KEY, message_id INT NOT NULL, user_id INT NOT NULL, auteur VARCHAR(190) NOT NULL,
            texte TEXT NOT NULL, envoye TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_msg (message_id)) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('adm_core contact: ' . $e->getMessage()); }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS demandes_organisateur (id INT AUTO_INCREMENT PRIMARY KEY, token CHAR(32) NOT NULL UNIQUE, user_id INT NOT NULL,
            structure VARCHAR(160) NOT NULL, type_structure VARCHAR(30) NOT NULL, siret VARCHAR(20) NULL, site VARCHAR(255) NULL, tel VARCHAR(30) NULL,
            ville VARCHAR(120) NULL, sejours_an VARCHAR(20) NULL, agrement VARCHAR(120) NULL, message TEXT NULL,
            statut VARCHAR(12) NOT NULL DEFAULT 'attente', motif TEXT NULL, decide_par INT NULL, decide_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user (user_id), KEY idx_statut (statut)) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS demandes_organisateur_evts (id INT AUTO_INCREMENT PRIMARY KEY, demande_id INT NOT NULL, par INT NULL, qui VARCHAR(190) NOT NULL,
            action VARCHAR(30) NOT NULL, texte TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_d (demande_id)) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('adm_core demandes: ' . $e->getMessage()); }
    $ins = $pdo->prepare("INSERT INTO adm_roles (code, nom, description, systeme) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE nom = nom");
    $insP = $pdo->prepare("INSERT IGNORE INTO adm_role_perms (role_id, perm) VALUES (?, ?)");
    foreach (adm_role_presets() as $code => [$nom, $desc, $perms]) {
        $ins->execute([$code, $nom, $desc]);
        $rid = (int)$pdo->query("SELECT id FROM adm_roles WHERE code = " . $pdo->quote($code))->fetchColumn();
        if ($v === 0) foreach ($perms as $p) $insP->execute([$rid, $p]);
    }
    $sup = (int)$pdo->query("SELECT id FROM adm_roles WHERE code = 'supreme'")->fetchColumn();
    if (!(int)$pdo->query("SELECT COUNT(*) FROM adm_user_roles WHERE role_id = $sup")->fetchColumn()) {
        $pdo->exec("INSERT IGNORE INTO adm_user_roles (user_id, role_id) SELECT id, $sup FROM users WHERE is_admin = 1");
    }
    $pdo->exec("INSERT INTO adm_kv (k, v) VALUES ('schema', '" . ADM_SCHEMA . "') ON DUPLICATE KEY UPDATE v = VALUES(v)");
}

function adm_perms(?int $uid = null): array {
    $cache = &$GLOBALS['ADM_PC'];
    $pdo = $GLOBALS['pdo'] ?? null;
    $uid = $uid ?? (int)($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0 || !$pdo) return [];
    if (isset($cache[$uid])) return $cache[$uid];
    try {
        adm_core_ensure($pdo);
        $st = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() !== 1) return $cache[$uid] = [];
        $st = $pdo->prepare("SELECT rp.perm FROM adm_user_roles ur JOIN adm_role_perms rp ON rp.role_id = ur.role_id WHERE ur.user_id = ?
                             UNION SELECT perm FROM adm_user_perms WHERE user_id = ?");
        $st->execute([$uid, $uid]);
        $p = $st->fetchAll(PDO::FETCH_COLUMN);
        return $cache[$uid] = in_array('*', $p, true) ? ['*'] : array_values(array_unique($p));
    } catch (Throwable $e) { error_log('adm_perms: ' . $e->getMessage()); return []; }
}

function adm_is_supreme(?int $uid = null): bool { return adm_perms($uid) === ['*']; }

function adm_can(string $perm, ?int $uid = null): bool {
    $p = adm_perms($uid);
    return $p === ['*'] || in_array($perm, $p, true);
}

function adm_is_staff(?int $uid = null): bool { return adm_perms($uid) !== [] || adm_staff_noperm($uid); }
function adm_staff_noperm(?int $uid = null): bool {
    $pdo = $GLOBALS['pdo'] ?? null; $uid = $uid ?? (int)($_SESSION['user']['id'] ?? 0);
    if (!$pdo || $uid <= 0) return false;
    try { $st = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?"); $st->execute([$uid]); return (int)$st->fetchColumn() === 1; } catch (Throwable $e) { return false; }
}

function adm_key(string $k): string {
    $s = defined('ADM_TOKEN_KEY') ? ADM_TOKEN_KEY : ((defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|' . (defined('DB_USER') ? DB_USER : ''));
    return substr(hash('sha256', $s . '|adm|' . $k, true), 0, 16);
}
function adm_tok(string $k, int $id): string {
    $c = openssl_encrypt(pack('JJ', $id, 0x6d63616d70), 'aes-128-ecb', adm_key($k), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    return rtrim(strtr(base64_encode($c), '+/', '-_'), '=');
}
function adm_untok(string $k, string $t): int {
    if (!preg_match('/^[A-Za-z0-9_-]{22}$/', $t)) return 0;
    $p = openssl_decrypt(base64_decode(strtr($t, '-_', '+/') . '=='), 'aes-128-ecb', adm_key($k), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    if ($p === false || strlen($p) !== 16) return 0;
    $u = unpack('Jid/Jm', $p);
    return ($u['m'] === 0x6d63616d70 && $u['id'] > 0) ? (int)$u['id'] : 0;
}

function adm_q(string $sql, array $p = []) {
    $pdo = $GLOBALS['pdo'];
    try { $st = $pdo->prepare($sql); $st->execute($p); $v = $st->fetchColumn(); return $v === false ? 0 : $v; }
    catch (Throwable $e) { return 0; }
}

function adm_todo_types(): array {
    return [
        'demande' => ['Demandes organisateur', 'demandes.read', 'admin_demandes.php', 'user-check'],
        'contact' => ['Messages non lus', 'contacts.read', 'admin_contacts.php', 'mail'],
        'sejour' => ['Séjours à valider', 'camps.read', 'admin_list.php?e=camps&f=attente', 'tent'],
        'litige' => ['Litiges', 'paiements.read', 'admin_compta.php?v=paiements&f=litige', 'alert'],
        'versement' => ['Versements échoués', 'versements.read', 'admin_compta.php?v=versements', 'bank'],
        'erreur' => ['Erreurs (24 h)', 'tech.logs', 'admin_erreurs.php', 'bug'],
    ];
}

function adm_todo_counts(?int $uid = null): array {
    static $memo = [];
    $uid = $uid ?? (int)($_SESSION['user']['id'] ?? 0);
    if (isset($memo[$uid])) return $memo[$uid];
    $sql = [
        'demande' => "SELECT COUNT(*) FROM users WHERE demande_en_cours = 1 AND is_directeur = 0",
        'contact' => "SELECT COUNT(*) FROM contact_messages WHERE COALESCE(dossier, 'inbox') = 'inbox' AND COALESCE(lu, 0) = 0",
        'sejour' => "SELECT COUNT(*) FROM camps WHERE en_attente = 1 AND valide = 0 AND refuse = 0 AND supprime = 0",
        'litige' => "SELECT COUNT(*) FROM fin_paiements WHERE statut = 'litige'",
        'versement' => "SELECT (SELECT COUNT(*) FROM fin_transferts WHERE statut = 'echec') + (SELECT COUNT(*) FROM fin_reversals WHERE statut = 'dette')",
        'erreur' => "SELECT COUNT(*) FROM app_erreurs WHERE created_at > (NOW() - INTERVAL 1 DAY)",
    ];
    $o = [];
    foreach (adm_todo_types() as $k => [, $perm]) $o[$k] = adm_can($perm, $uid) ? (int)adm_q($sql[$k]) : 0;
    return $memo[$uid] = $o;
}

function adm_notif_add(string $type, string $ref, string $titre, string $lien, string $perm): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return;
    try {
        adm_core_ensure($pdo);
        $pdo->prepare("INSERT IGNORE INTO adm_notifs (type, ref, titre, lien, perm) VALUES (?, ?, ?, ?, ?)")
            ->execute([$type, mb_substr($ref, 0, 80), mb_substr($titre, 0, 190), mb_substr($lien, 0, 255), $perm]);
    } catch (Throwable $e) { error_log('adm_notif_add: ' . $e->getMessage()); }
}

function adm_notif_sync(PDO $pdo, bool $force = false): void {
    try {
        adm_core_ensure($pdo);
        $last = (int)adm_q("SELECT v FROM adm_kv WHERE k = 'notif_sync'");
        if (!$force && time() - $last < 60) return;
        $pdo->prepare("INSERT INTO adm_kv (k, v) VALUES ('notif_sync', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)time()]);
        $since = $last > 0 ? date('Y-m-d H:i:s', $last - 120) : date('Y-m-d H:i:s', time() - 7 * 86400);
        $add = $pdo->prepare("INSERT IGNORE INTO adm_notifs (type, ref, titre, lien, perm) VALUES (?, ?, ?, ?, ?)");
        $run = function (string $sql, array $p, callable $f) use ($pdo, $add) {
            try { $st = $pdo->prepare($sql); $st->execute($p); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add->execute($f($r)); }
            catch (Throwable $e) { error_log('adm_notif_sync: ' . $e->getMessage()); }
        };
        $run("SELECT id, nom, ville FROM camps WHERE en_attente = 1 AND valide = 0 AND refuse = 0 AND supprime = 0 ORDER BY id DESC LIMIT 50", [],
            fn($r) => ['sejour', 'c' . $r['id'], 'Séjour à valider : ' . $r['nom'] . ($r['ville'] ? ' (' . $r['ville'] . ')' : ''), 'admin_list.php?e=camps&f=attente&q=' . rawurlencode((string)$r['nom']), 'camps.read']);
        $run("SELECT p.id, p.payment_intent, p.brut_cents, o.nom FROM fin_paiements p LEFT JOIN organisateurs o ON o.id = p.organisateur_id WHERE p.statut = 'litige' LIMIT 50", [],
            fn($r) => ['litige', 'p' . $r['id'], 'Litige : ' . number_format($r['brut_cents'] / 100, 2, ',', ' ') . ' € · ' . ($r['nom'] ?? ''), 'admin_paiement.php?t=' . adm_tok('pay', (int)$r['id']), 'paiements.read']);
        $run("SELECT t.id, t.montant_cents, t.updated_at, o.nom FROM fin_transferts t LEFT JOIN organisateurs o ON o.id = t.organisateur_id WHERE t.statut = 'echec' LIMIT 50", [],
            fn($r) => ['versement', 't' . $r['id'] . ':' . $r['updated_at'], 'Versement échoué : ' . number_format($r['montant_cents'] / 100, 2, ',', ' ') . ' € · ' . ($r['nom'] ?? ''), 'admin_compta.php?v=versements', 'versements.read']);
        $run("SELECT MIN(id) id, COUNT(*) n, LEFT(message, 120) m, DATE(created_at) d FROM app_erreurs WHERE created_at > ? GROUP BY LEFT(message, 120), DATE(created_at) ORDER BY id DESC LIMIT 30", [$since],
            fn($r) => ['erreur', 'e' . $r['d'] . ':' . substr(md5($r['m']), 0, 16), 'Erreur' . ($r['n'] > 1 ? ' (×' . $r['n'] . ')' : '') . ' : ' . $r['m'], 'admin_erreurs.php', 'tech.logs']);
        $pdo->exec("DELETE FROM adm_notifs WHERE created_at < (NOW() - INTERVAL 90 DAY)");
    } catch (Throwable $e) { error_log('adm_notif_sync: ' . $e->getMessage()); }
}

function adm_notif_where(?int $uid = null): array {
    $p = adm_perms($uid);
    if ($p === ['*']) return ['1=1', []];
    if (!$p) return ['0=1', []];
    return ['n.perm IN (' . implode(',', array_fill(0, count($p), '?')) . ')', $p];
}

function adm_notif_unread(?int $uid = null): int {
    $uid = $uid ?? (int)($_SESSION['user']['id'] ?? 0);
    [$w, $p] = adm_notif_where($uid);
    return (int)adm_q("SELECT COUNT(*) FROM adm_notifs n LEFT JOIN adm_notif_lus l ON l.notif_id = n.id AND l.user_id = ? WHERE l.notif_id IS NULL AND $w AND n.created_at > (NOW() - INTERVAL 30 DAY)", array_merge([$uid], $p));
}

function adm_mail(string $to, string $subject, string $html, string $replyTo = ''): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = 'moncamp.fr <auth@moncamp.fr>';
    $h = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: $from\r\nReply-To: " . ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : 'auth@moncamp.fr') . "\r\n";
    $body = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#26343b;line-height:1.6">' . $html . '<p style="color:#6b7780;font-size:13px">L\'équipe moncamp.fr</p></div>';
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h);
}

function adm_staff_emails(string $perm): array {
    $pdo = $GLOBALS['pdo'];
    try {
        adm_core_ensure($pdo);
        $st = $pdo->prepare("SELECT DISTINCT u.email FROM users u JOIN adm_user_roles ur ON ur.user_id = u.id JOIN adm_role_perms rp ON rp.role_id = ur.role_id
                             WHERE u.is_admin = 1 AND (rp.perm = '*' OR rp.perm = ?)
                             UNION SELECT u.email FROM users u JOIN adm_user_perms up ON up.user_id = u.id WHERE u.is_admin = 1 AND up.perm = ?");
        $st->execute([$perm, $perm]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { error_log('adm_staff_emails: ' . $e->getMessage()); return []; }
}
