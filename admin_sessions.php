<?php
// admin_sessions.php : migration « sessions multiples » (administrateur uniquement, idempotent)
//   1. ajoute la colonne camps.groupe_id si elle n'existe pas
//   2. regroupe en un seul camp les séjours qui ont le même organisateur, le même nom et la même ville
// Un aperçu est affiché d'abord ; rien n'est modifié tant qu'on n'a pas cliqué sur « Appliquer ».

require_once 'api/config.php';
require_once 'api/sessions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user']['is_admin'])) { http_response_code(403); exit('Accès réservé aux administrateurs.'); }
if (empty($_SESSION['csrf_sessions'])) $_SESSION['csrf_sessions'] = bin2hex(random_bytes(16));

$apply = ($_SERVER['REQUEST_METHOD'] === 'POST')
      && hash_equals($_SESSION['csrf_sessions'], (string)($_POST['csrf'] ?? ''));
$log = [];

try {
    // 1. Colonne
    if (!campsHaveGroupColumn($pdo)) {
        if ($apply) {
            $pdo->exec("ALTER TABLE camps ADD COLUMN groupe_id INT NULL DEFAULT NULL, ADD INDEX idx_camps_groupe (groupe_id)");
            $log[] = "Colonne camps.groupe_id ajoutée.";
        } else {
            $log[] = "À faire : ajouter la colonne camps.groupe_id.";
        }
    } else {
        $log[] = "Colonne camps.groupe_id déjà présente.";
    }

    // 2. Regroupement
    $hasCol = $apply ? (bool)$pdo->query("SHOW COLUMNS FROM camps LIKE 'groupe_id'")->fetch() : campsHaveGroupColumn($pdo);
    $select = $hasCol ? "id, organisateur_id, nom, ville, date_debut, groupe_id" : "id, organisateur_id, nom, ville, date_debut, NULL AS groupe_id";
    $rows = $pdo->query("SELECT $select FROM camps WHERE COALESCE(supprime, 0) = 0 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $r) {
        $key = $r['organisateur_id'] . '|' . normalizeCampName($r['nom']) . '|' . normalizeCampName($r['ville']);
        $groups[$key][] = $r;
    }
    $toLink = 0; $nbGroups = 0; $preview = [];
    foreach ($groups as $key => $members) {
        if (count($members) < 2) continue;
        // groupe cible : celui déjà utilisé par un membre, sinon le plus petit id
        $target = null;
        foreach ($members as $m) if (!empty($m['groupe_id'])) { $target = (int)$m['groupe_id']; break; }
        if ($target === null) $target = (int)$members[0]['id'];
        $ids = [];
        foreach ($members as $m) if ((int)($m['groupe_id'] ?? 0) !== $target) $ids[] = (int)$m['id'];
        if (!$ids) continue;
        $nbGroups++; $toLink += count($ids);
        if (count($preview) < 25) $preview[] = [$members[0]['nom'], $members[0]['ville'], count($members)];
        if ($apply && $hasCol) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE camps SET groupe_id = ? WHERE id IN ($in)")->execute(array_merge([$target], $ids));
        }
    }
    $log[] = $apply ? "Regroupement effectué : $toLink séjours rattachés à $nbGroups camps."
                    : "À faire : rattacher $toLink séjours à $nbGroups camps (même organisateur, même nom, même ville).";
} catch (Throwable $e) {
    $log[] = 'Erreur : ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sessions multiples - migration</title>
<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;color:#26343b}
button{background:#2f3d45;color:#fff;border:0;border-radius:8px;padding:12px 22px;font-weight:700;cursor:pointer}
li{margin:6px 0}table{border-collapse:collapse;width:100%;margin-top:16px}td,th{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left}</style></head>
<body>
<h1>Sessions multiples</h1>
<ul><?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
<?php if (!$apply): ?>
    <?php if ($preview): ?>
        <h3>Aperçu des camps regroupés (25 premiers)</h3>
        <table><tr><th>Camp</th><th>Ville</th><th>Sessions</th></tr>
        <?php foreach ($preview as [$n, $v, $c]) echo '<tr><td>' . htmlspecialchars($n) . '</td><td>' . htmlspecialchars((string)$v) . '</td><td>' . (int)$c . '</td></tr>'; ?>
        </table>
    <?php endif; ?>
    <form method="post" style="margin-top:24px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_sessions']) ?>">
        <button type="submit">Appliquer</button></form>
<?php else: ?>
    <p>Terminé. Vous pouvez relancer cette page sans risque : elle ne modifie que ce qui reste à faire.</p>
<?php endif; ?>
</body></html>
