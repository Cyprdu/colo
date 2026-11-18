<?php
// api/enfants.php : identifiant public (jeton aléatoire) des enfants. Les adresses n'utilisent JAMAIS l'id numérique.
// La colonne public_id est ajoutée et remplie automatiquement (idempotent).

function enfants_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM enfants")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('public_id', $cols, true)) {
            $pdo->exec("ALTER TABLE enfants ADD COLUMN public_id VARCHAR(32) NULL");
            try { $pdo->exec("ALTER TABLE enfants ADD UNIQUE INDEX uq_enfants_public_id (public_id)"); } catch (Throwable $e) {}
        }
        $ids = $pdo->query("SELECT id FROM enfants WHERE public_id IS NULL OR public_id = ''")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $up = $pdo->prepare("UPDATE enfants SET public_id = ? WHERE id = ?");
            foreach ($ids as $id) $up->execute([bin2hex(random_bytes(12)), $id]);
        }
    } catch (Throwable $e) {
        error_log('enfants_ensure: ' . $e->getMessage());
    }
}

/** Nouveau jeton (à utiliser à l'INSERT) */
/** Chemin de retour interne validé (ex. « inscription?camp_t=… ») : un nom de page du site et sa requête, jamais d'hôte ni de schéma ; '' sinon */
function retour_interne($r): string {
    $r = ltrim(trim((string)$r), '/');
    return preg_match('#^[a-z0-9_-]+(\.php)?(\?[A-Za-z0-9_=&%.+-]*)?$#D', $r) ? $r : '';
}
function enfant_new_public_id(): string { return bin2hex(random_bytes(12)); }

/** Enfant du parent connecté, retrouvé par son jeton ; null si inconnu ou pas à lui */
function enfant_par_jeton(PDO $pdo, string $jeton, int $parentId): ?array {
    if (!preg_match('/^[a-f0-9]{24}$/', $jeton)) return null;
    enfants_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM enfants WHERE public_id = ? AND parent_id = ?");
    $st->execute([$jeton, $parentId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Jeton d'un enfant à partir de son id (déjà vérifié côté serveur par l'appelant) */
function enfant_jeton(PDO $pdo, int $enfantId): string {
    enfants_ensure($pdo);
    $st = $pdo->prepare("SELECT public_id FROM enfants WHERE id = ?");
    $st->execute([$enfantId]);
    return (string)$st->fetchColumn();
}
