<?php
// api/animateurs.php : bibliothèque de la gestion des animateurs (aucun point d'entrée HTTP).
//
// Deux modes par séjour, choisis par le directeur (colonne camps.anim_mode) :
//   'recrute' : « Je recherche des animateurs » -> le séjour apparaît dans le catalogue réservé aux animateurs
//               (camps.recrute_animateurs = 1, rémunération prévue dans camps.anim_remuneration, NULL = non rémunéré)
//   'propres' : « Mes propres animateurs » -> pas dans le catalogue ; le directeur génère des liens d'inscription
//               (table animateur_liens : jeton aléatoire, privé ou public, révocable)
//   'aucun'   : par défaut.
// Tables/colonnes créées automatiquement au premier usage (idempotent), comme api/champs.php.
// Aucun identifiant numérique n'est exposé : séjours = camps.token, candidatures = candidatures.token, liens = jeton.

const ANIM_STATUTS = ['En attente', 'Accepté', 'Refusé', "Liste d'attente"];
const ANIM_CV_MAX = 5 * 1024 * 1024;

function anim_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM camps")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('anim_mode', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN anim_mode VARCHAR(10) NOT NULL DEFAULT 'aucun'");
        if (!in_array('recrute_animateurs', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN recrute_animateurs TINYINT(1) NOT NULL DEFAULT 0");
        if (!in_array('anim_remuneration', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN anim_remuneration DECIMAL(8,2) NULL");

        $pdo->exec("CREATE TABLE IF NOT EXISTS animateur_profils (
            user_id INT PRIMARY KEY,
            presentation TEXT NULL,
            bafa_statut VARCHAR(12) NOT NULL DEFAULT 'aucun',
            bafa_numero VARCHAR(40) NULL,
            formation VARCHAR(255) NULL,
            experiences TEXT NULL,
            disponibilites TEXT NULL,
            cv_fichier VARCHAR(64) NULL,
            cv_nom VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS animateur_liens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            token CHAR(40) NOT NULL,
            type VARCHAR(8) NOT NULL DEFAULT 'prive',
            libelle VARCHAR(120) NULL,
            max_uses INT NULL,
            expire_at DATETIME NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lien_token (token),
            INDEX idx_lien_camp (camp_id)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS animateur_notifs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            texte VARCHAR(300) NOT NULL,
            lu TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_user (user_id, lu)
        ) DEFAULT CHARSET=utf8mb4");

        $cc = $pdo->query("SHOW COLUMNS FROM candidatures")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('token', $cc, true)) $pdo->exec("ALTER TABLE candidatures ADD COLUMN token CHAR(32) NULL");
        if (!in_array('lien_id', $cc, true)) $pdo->exec("ALTER TABLE candidatures ADD COLUMN lien_id INT NULL");
        if (!in_array('decided_at', $cc, true)) $pdo->exec("ALTER TABLE candidatures ADD COLUMN decided_at DATETIME NULL");
        if (!in_array('note_directeur', $cc, true)) $pdo->exec("ALTER TABLE candidatures ADD COLUMN note_directeur VARCHAR(500) NULL");
        $st = $pdo->query("SHOW COLUMNS FROM candidatures LIKE 'statut'")->fetch(PDO::FETCH_ASSOC);
        if ($st && stripos((string)$st['Type'], 'enum') === 0) $pdo->exec("ALTER TABLE candidatures MODIFY statut VARCHAR(20) NOT NULL DEFAULT 'En attente'");
        // anciennes candidatures sans jeton
        $old = $pdo->query("SELECT id FROM candidatures WHERE token IS NULL OR token = ''")->fetchAll(PDO::FETCH_COLUMN);
        $up = $pdo->prepare("UPDATE candidatures SET token = ? WHERE id = ?");
        foreach ($old as $id) $up->execute([bin2hex(random_bytes(16)), $id]);
    } catch (Throwable $e) {
        error_log('anim_ensure: ' . $e->getMessage());
    }
    $done = true;
}

function anim_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function anim_user_id(): int { return (int)($_SESSION['user']['id'] ?? 0); }
function anim_is_animateur(): bool { return !empty($_SESSION['user']['is_animateur']); }
function anim_is_directeur(): bool { return !empty($_SESSION['user']['is_directeur']); }

/** Exige un animateur connecté (JSON 403 sinon) */
function anim_require_animateur(): int {
    if (!anim_user_id() || !anim_is_animateur()) anim_json(['error' => 'Réservé aux comptes animateurs.'], 403);
    return anim_user_id();
}
function anim_require_directeur(): int {
    if (!anim_user_id() || !anim_is_directeur()) anim_json(['error' => 'Accès interdit.'], 403);
    return anim_user_id();
}

function anim_body(): array {
    $in = json_decode(file_get_contents('php://input'), true);
    return is_array($in) ? $in : [];
}

/** Séjour du directeur connecté, par jeton. null si absent OU s'il appartient à un autre directeur. */
function anim_camp_du_directeur(PDO $pdo, string $campToken, int $userId): ?array {
    if (!preg_match('/^[a-f0-9]{16,64}$/i', $campToken)) return null;
    $st = $pdo->prepare("SELECT c.* FROM camps c JOIN organisateurs o ON o.id = c.organisateur_id WHERE c.token = ? AND o.user_id = ? AND c.supprime = 0");
    $st->execute([$campToken, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Candidature (par jeton) d'un séjour du directeur connecté */
function anim_candidature_du_directeur(PDO $pdo, string $token, int $userId): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/i', $token)) return null;
    $st = $pdo->prepare("SELECT cd.*, c.token AS camp_token, c.nom AS camp_nom FROM candidatures cd
        JOIN camps c ON c.id = cd.camp_id JOIN organisateurs o ON o.id = c.organisateur_id
        WHERE cd.token = ? AND o.user_id = ?");
    $st->execute([$token, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function anim_notifier(PDO $pdo, int $userId, string $texte): void {
    try { $pdo->prepare("INSERT INTO animateur_notifs (user_id, texte) VALUES (?, ?)")->execute([$userId, mb_substr($texte, 0, 300)]); }
    catch (Throwable $e) { error_log('anim_notifier: ' . $e->getMessage()); }
}

function anim_profil(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("SELECT * FROM animateur_profils WHERE user_id = ?");
    $st->execute([$userId]);
    $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return $p + ['presentation' => '', 'bafa_statut' => 'aucun', 'bafa_numero' => '', 'formation' => '', 'experiences' => '', 'disponibilites' => '', 'cv_fichier' => null, 'cv_nom' => null];
}

/** Profil « postulable » : présentation + (BAFA/équivalent/en cours avec numéro ou formation, ou « aucun » assumé) */
function anim_profil_complet(array $p): bool {
    return trim((string)$p['presentation']) !== ''
        && ($p['bafa_statut'] === 'aucun' || trim((string)$p['bafa_numero'] . (string)$p['formation']) !== '');
}

function anim_txt($v, int $max): string {
    return mb_substr(trim(strip_tags((string)$v)), 0, $max);
}

/** Lien utilisable ? Renvoie [lien, séjour] ou null (inconnu, révoqué, expiré, quota atteint, séjour plus en mode « propres »). */
function anim_lien_valide(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) return null;
    $st = $pdo->prepare("SELECT l.*, (SELECT COUNT(*) FROM candidatures WHERE lien_id = l.id) AS used FROM animateur_liens l WHERE l.token = ?");
    $st->execute([$token]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l || !$l['actif']) return null;
    if ($l['expire_at'] && strtotime($l['expire_at']) < time()) return null;
    if ($l['max_uses'] !== null && (int)$l['used'] >= (int)$l['max_uses']) return null;
    $c = $pdo->prepare("SELECT * FROM camps WHERE id = ? AND supprime = 0");
    $c->execute([$l['camp_id']]);
    $camp = $c->fetch(PDO::FETCH_ASSOC);
    return ($camp && $camp['anim_mode'] === 'propres') ? [$l, $camp] : null;
}
