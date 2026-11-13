<?php
// api/etapes.php : parcours d'un séjour itinérant (une étape par jour). Table créée à la première utilisation (idempotent).

function etapes_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS camp_etapes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        camp_id INT NOT NULL,
        jour INT NOT NULL DEFAULT 1,
        lieu VARCHAR(160) NOT NULL,
        description TEXT NULL,
        ordre INT NOT NULL DEFAULT 0,
        INDEX idx_etapes_camp (camp_id)
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function etapes_du_camp(PDO $pdo, int $campId): array {
    try {
        etapes_ensure($pdo);
        $st = $pdo->prepare("SELECT jour, lieu, description FROM camp_etapes WHERE camp_id = ? ORDER BY ordre, jour, id");
        $st->execute([$campId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Remplace les étapes du séjour (aucune si le séjour n'est pas itinérant). $json = JSON [{jour,lieu,description}] */
function etapes_enregistrer(PDO $pdo, int $campId, bool $itinerant, $json): void {
    etapes_ensure($pdo);
    $pdo->prepare("DELETE FROM camp_etapes WHERE camp_id = ?")->execute([$campId]);
    if (!$itinerant) return;
    $list = json_decode((string)$json, true);
    if (!is_array($list)) return;
    $ins = $pdo->prepare("INSERT INTO camp_etapes (camp_id, jour, lieu, description, ordre) VALUES (?, ?, ?, ?, ?)");
    foreach (array_slice($list, 0, 60) as $i => $e) {
        $lieu = mb_substr(trim((string)($e['lieu'] ?? '')), 0, 160);
        if ($lieu === '') continue;
        $desc = trim((string)($e['description'] ?? ''));
        $ins->execute([$campId, max(1, min(365, (int)($e['jour'] ?? $i + 1))), $lieu, $desc === '' ? null : mb_substr($desc, 0, 1000), $i]);
    }
}
