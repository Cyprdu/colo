<?php
// api/save_champs.php : le directeur enregistre les champs d'inscription de son séjour (JSON).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/champs.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_directeur'])) out(['error' => 'Accès interdit.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Méthode non autorisée.'], 405);

$in = json_decode(file_get_contents('php://input'), true);
$campId = (int)($in['camp_id'] ?? 0);
$campToken = (string)($in['token'] ?? '');   // l'adresse à jeton est préférée à l'identifiant numérique
$champs = $in['champs'] ?? null;
if ((!$campId && $campToken === '') || !is_array($champs)) out(['error' => 'Données invalides.'], 400);
if (count($champs) > 30) out(['error' => '30 champs maximum.'], 400);

// Le séjour doit appartenir à un organisateur de l'utilisateur
$st = $pdo->prepare("SELECT c.* FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id WHERE " . ($campToken !== '' ? "c.token = ?" : "c.id = ?") . " AND o.user_id = ?");
$st->execute([$campToken !== '' ? $campToken : $campId, $_SESSION['user']['id']]);
$campRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$campRow) out(['error' => "Ce séjour n'a pas été trouvé dans votre compte."], 404);
$campId = (int)$campRow['id'];

$clean = [];
foreach ($champs as $i => $c) {
    $libelle = mb_substr(trim((string)($c['libelle'] ?? '')), 0, 255);
    $type = (string)($c['type'] ?? 'texte');
    if ($libelle === '') continue;
    if (!isset(CHAMPS_TYPES[$type])) out(['error' => 'Type de champ inconnu.'], 400);
    $options = null;
    if ($type === 'liste') {
        $lignes = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($c['options'] ?? ''))))));
        if (count($lignes) < 2) out(['error' => "La liste « $libelle » doit proposer au moins 2 choix."], 400);
        $options = implode("\n", array_map(fn($l) => mb_substr($l, 0, 120), $lignes));
    }
    $clean[] = [(int)($c['id'] ?? 0), $libelle, $type, $options, !empty($c['obligatoire']) ? 1 : 0, $i];
}

try {
    champs_ensure($pdo);
    $pdo->beginTransaction();
    $existing = $pdo->prepare("SELECT id FROM camp_champs WHERE camp_id = ?");
    $existing->execute([$campId]);
    $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
    $keep = [];
    $ins = $pdo->prepare("INSERT INTO camp_champs (camp_id, libelle, type, options, obligatoire, position) VALUES (?, ?, ?, ?, ?, ?)");
    $upd = $pdo->prepare("UPDATE camp_champs SET libelle = ?, type = ?, options = ?, obligatoire = ?, position = ? WHERE id = ? AND camp_id = ?");
    foreach ($clean as [$id, $libelle, $type, $options, $oblig, $pos]) {
        if ($id && in_array($id, $existingIds, true)) {
            $upd->execute([$libelle, $type, $options, $oblig, $pos, $id, $campId]);
            $keep[] = $id;
        } else {
            $ins->execute([$campId, $libelle, $type, $options, $oblig, $pos]);
            $keep[] = (int)$pdo->lastInsertId();
        }
    }
    // Champs retirés : supprimés (les réponses déjà données gardent leur libellé)
    $del = array_diff($existingIds, $keep);
    if ($del) {
        $q = implode(',', array_fill(0, count($del), '?'));
        $pdo->prepare("DELETE FROM camp_champs WHERE camp_id = ? AND id IN ($q)")->execute(array_merge([$campId], array_values($del)));
    }
    // Toutes les sessions du séjour posent les mêmes questions : on recopie la liste sur les sessions sœurs
    champs_propager($pdo, $campRow);
    $pdo->commit();
    out(['ok' => true, 'champs' => champs_du_camp($pdo, $campId)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('save_champs: ' . $e->getMessage());
    out(['error' => "Enregistrement impossible."], 500);
}
