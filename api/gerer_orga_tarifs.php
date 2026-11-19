<?php
// api/gerer_orga_tarifs.php : le directeur modifie / supprime ses organismes et tarifs depuis la page du séjour (JSON).
// Actions : orga_update, tarif_update, tarif_delete. (La création passe par create_organisateur.php et create_tarif.php.)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tarifs.php';
require_once __DIR__ . '/orga_lib.php';
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
function out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) out(['error' => 'Accès interdit.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Méthode non autorisée.'], 405);
require_once __DIR__ . '/security.php';
if (!csrf_check()) out(['error' => 'Session expirée : rechargez la page puis réessayez.'], 403);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$uid = (int)$_SESSION['user']['id'];
$id = (int)($in['id'] ?? 0);
$action = (string)($in['action'] ?? '');

try {
    tarifs_ensure($pdo);
    if ($action === 'orga_update') {
        $id = fin_orga_from_tok($pdo, (string)($in['id'] ?? ''), $uid); // jeton public de l'organisme
        $nom = mb_substr(trim((string)($in['nom'] ?? '')), 0, 150);
        $mail = trim((string)($in['email'] ?? ''));
        if ($nom === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) out(['error' => 'Un nom et un email valide sont obligatoires.'], 400);
        $st = $pdo->prepare("UPDATE organisateurs SET nom = ?, email = ?, tel = ?, web = ? WHERE id = ? AND user_id = ?");
        $st->execute([$nom, $mail, mb_substr(trim((string)($in['tel'] ?? '')), 0, 30), mb_substr(trim((string)($in['web'] ?? '')), 0, 255), $id, $uid]);
        $chk = $pdo->prepare("SELECT id, nom, email, tel, web FROM organisateurs WHERE id = ? AND user_id = ?");
        $chk->execute([$id, $uid]);
        $o = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$o) out(['error' => 'Organisme introuvable.'], 404);
        $alias = ['motscles' => [], 'motscles_refuses' => []];
        require_once __DIR__ . '/motscles.php';
        if (array_key_exists('motscles', $in)) $alias = mc_sauver_orga($pdo, $id, $nom, $in['motscles']);
        else { mc_ensure($pdo); $alias['motscles'] = mc_lire($pdo, 'orga', [$id])[$id] ?? []; }
        $o['id'] = fin_orga_tok((int)$o['id']);
        out(['ok' => true, 'organisme' => $o] + $alias);
    }

    // Un tarif n'est modifiable que s'il appartient à un organisme du directeur
    $st = $pdo->prepare("SELECT t.* FROM tarifs t JOIN organisateurs o ON o.id = t.organisateur_id WHERE t.id = ? AND o.user_id = ?");
    $st->execute([$id, $uid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) out(['error' => 'Tarif introuvable.'], 404);

    if ($action === 'tarif_update') {
        $nom = mb_substr(trim((string)($in['nom'] ?? '')), 0, 120);
        if ($nom === '') out(['error' => 'Donnez un nom au tarif.'], 400);
        $libre = !empty($in['montant_libre']) ? 1 : 0;
        $min = $libre && (float)($in['montant_min'] ?? 0) > 0 ? round((float)$in['montant_min'], 2) : null;
        $prix = max(0, round((float)($in['prix'] ?? 0), 2));
        $pdo->prepare("UPDATE tarifs SET nom = ?, prix = ?, montant_libre = ?, montant_min = ? WHERE id = ?")->execute([$nom, $prix, $libre, $min, $id]);
        out(['ok' => true, 'tarif' => ['id' => $id, 'nom' => $nom, 'prix' => $prix, 'montant_libre' => $libre, 'montant_min' => $min, 'organisateur_id' => fin_orga_tok((int)$t['organisateur_id'])]]);
    }

    if ($action === 'tarif_delete') {
        // Un tarif déjà choisi par une famille garde son historique : on refuse de le supprimer
        $u = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE tarif_id = ?");
        $u->execute([$id]);
        if ((int)$u->fetchColumn() > 0) out(['error' => 'Ce tarif a déjà été choisi par des familles : il ne peut plus être supprimé (retirez-le simplement du séjour).'], 409);
        $pdo->prepare("DELETE FROM camps_tarifs WHERE tarif_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM tarifs WHERE id = ?")->execute([$id]);
        out(['ok' => true]);
    }
    out(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    error_log('gerer_orga_tarifs: ' . $e->getMessage());
    out(['error' => 'Opération impossible.'], 500);
}
