<?php
// api/tarifs.php : montant minimum des tarifs « montant libre » (réglé par le directeur).
// La colonne est ajoutée automatiquement à la première utilisation (idempotent).

function tarifs_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM tarifs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('montant_min', $cols, true)) $pdo->exec("ALTER TABLE tarifs ADD COLUMN montant_min DECIMAL(10,2) NULL DEFAULT NULL");
    } catch (Throwable $e) { error_log('tarifs_ensure: ' . $e->getMessage()); }
    $done = true;
}

/** Plus petit montant accepté pour un tarif libre : le minimum du directeur, sinon le prix du tarif */
function tarif_plancher(array $t): float {
    $min = (float)($t['montant_min'] ?? 0);
    return $min > 0 ? $min : (float)($t['prix'] ?? 0);
}
