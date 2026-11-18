<?php
require_once __DIR__ . '/adm_core.php';
// api/orga_lib.php : identifiant public opaque des organismes (organisateurs.public_id, 22 caractères base62 aléatoires).
// Aucun id numérique d'organisme ne sort vers le navigateur (URL, formulaire, JSON, attribut) : on y met fin_orga_tok($id),
// et on revient à l'id côté serveur par fin_orga_from_tok() (seule fonction de résolution, avec contrôle propriétaire/admin).

/** Crée la colonne si besoin et remplit les organismes qui n'ont pas encore de jeton */
function fin_orga_tok_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->query("SELECT public_id FROM organisateurs LIMIT 0"); }
    catch (Throwable $e) { $pdo->exec("ALTER TABLE organisateurs ADD COLUMN public_id VARCHAR(32) NULL DEFAULT NULL, ADD UNIQUE KEY uq_orga_public_id (public_id)"); }
    $up = $pdo->prepare("UPDATE organisateurs SET public_id = ? WHERE id = ? AND (public_id IS NULL OR public_id = '')");
    foreach ($pdo->query("SELECT id FROM organisateurs WHERE public_id IS NULL OR public_id = ''")->fetchAll(PDO::FETCH_COLUMN) as $id) $up->execute([fin_orga_tok_new(), $id]);
}

/** 22 caractères base62 tirés sans biais de random_bytes (~131 bits) */
function fin_orga_tok_new(): string {
    $a = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $s = '';
    while (strlen($s) < 22) foreach (str_split(random_bytes(32)) as $b) { $b = ord($b); if ($b < 248 && strlen($s) < 22) $s .= $a[$b % 62]; }
    return $s;
}

/** Jeton public d'un organisme (créé à la volée pour un organisme tout neuf) */
function fin_orga_tok(int $orgaId, ?PDO $pdo = null): string {
    static $map = null;
    $pdo = $pdo ?? $GLOBALS['pdo'];
    if ($map === null || !isset($map[$orgaId])) {
        fin_orga_tok_ensure($pdo);
        if ($map !== null) { $up = $pdo->prepare("UPDATE organisateurs SET public_id = ? WHERE id = ? AND public_id IS NULL"); $up->execute([fin_orga_tok_new(), $orgaId]); }
        $map = array_map('strval', $pdo->query("SELECT id, public_id FROM organisateurs")->fetchAll(PDO::FETCH_KEY_PAIR));
    }
    return $map[$orgaId] ?? '';
}

/**
 * Id d'un organisme à partir de son jeton : parmi ceux de $userId, ou tous si $userId = null (administration). 0 si inconnu.
 * Les anciens jetons HMAC (24 hexadécimaux, liens déjà envoyés) restent acceptés.
 */
function fin_orga_from_tok(PDO $pdo, string $tok, ?int $userId = null): int {
    fin_orga_tok_ensure($pdo);
    $own = $userId === null ? '' : ' AND user_id = ' . (int)$userId;
    if (preg_match('/^[A-Za-z0-9]{22}$/', $tok)) {
        $st = $pdo->prepare("SELECT id FROM organisateurs WHERE public_id = ?$own");
        $st->execute([$tok]);
        return (int)$st->fetchColumn();
    }
    if (!preg_match('/^[a-f0-9]{24}$/', $tok)) return 0;
    $k = (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '') . '|' . (defined('CRON_SECRET') ? CRON_SECRET : '') . '|colomap-orga';
    foreach ($pdo->query("SELECT id FROM organisateurs WHERE 1$own")->fetchAll(PDO::FETCH_COLUMN) as $id)
        if (hash_equals(substr(hash_hmac('sha256', 'orga:' . $id, $k), 0, 24), $tok)) return (int)$id;
    return 0;
}

/**
 * Organisme demandé par la page (?o=jeton, ou champ POST o), réservé à son propriétaire ou à un admin ; 404 sinon.
 * Ancienne URL numérique (?$legacy=13) : 404 pour un non-admin, redirection vers l'URL à jeton pour un admin.
 */
function fin_orga_request(PDO $pdo, string $legacy = 'organisateur_id'): int {
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    $admin = adm_can('orgas.read');
    $num = $_GET[$legacy] ?? null;
    $tok = (string)($_GET['o'] ?? $_POST['o'] ?? '');
    if ($num !== null && ctype_digit((string)$num) && $tok === '') {
        if ($admin && ($t = fin_orga_tok((int)$num, $pdo)) !== '') {
            $q = $_GET; unset($q[$legacy]); $q = ['o' => $t] + $q;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($q), true, 302); exit;
        }
        fin_orga_404();
    }
    $id = $userId ? fin_orga_from_tok($pdo, $tok, $admin ? null : $userId) : 0;
    if (!$id) fin_orga_404();
    return $id;
}

function fin_orga_404(): void {
    http_response_code(404);
    if (is_file(__DIR__ . '/../404.php') && stripos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') === false) { include __DIR__ . '/../404.php'; }
    else { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Organisme introuvable.']); }
    exit;
}
