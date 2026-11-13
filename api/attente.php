<?php
// api/attente.php : réservation « en attente de paiement » et délai limite, réglés par le directeur de chaque séjour.
//
//  - attente_paiement = 1 : le parent peut régler plus tard ; sa place est gardée « attente_jours » jours
//  - attente_paiement = 0 : la place n'est gardée que le temps du paiement (30 min), puis libérée
// Colonnes ajoutées automatiquement à la première utilisation (idempotent).

const ATTENTE_DEFAUT_JOURS = 7;
const ATTENTE_MAX_JOURS = 60;

function attente_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM camps")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('attente_paiement', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN attente_paiement TINYINT(1) NOT NULL DEFAULT 1");
        if (!in_array('attente_jours', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN attente_jours INT NOT NULL DEFAULT " . ATTENTE_DEFAUT_JOURS);
    } catch (Throwable $e) {
        error_log('attente_ensure: ' . $e->getMessage());
    }
    $done = true;
}

/** Durée pendant laquelle une inscription non payée garde sa place (en minutes) */
function attente_minutes(array $camp): int {
    if (isset($camp['attente_paiement']) && (int)$camp['attente_paiement'] === 0) return 30;
    $j = (int)($camp['attente_jours'] ?? ATTENTE_DEFAUT_JOURS);
    return max(1, min(ATTENTE_MAX_JOURS, $j ?: ATTENTE_DEFAUT_JOURS)) * 1440;
}

/** Condition SQL : inscription « active » (qui occupe une place) pour CE séjour */
function attente_clause(array $camp): string {
    $m = attente_minutes($camp);
    return "(COALESCE(statut, '') NOT IN ('Annulé', 'annule', 'Annule') AND (statut_paiement <> 'EN_ATTENTE' OR date_inscription > (NOW() - INTERVAL $m MINUTE)))";
}

/** Date limite de paiement d'une ligne d'inscription (null si elle n'est pas en attente) */
function attente_limite(array $row): ?DateTime {
    if (($row['statut_paiement'] ?? '') !== 'EN_ATTENTE' || empty($row['date_inscription'])) return null;
    try { return (new DateTime($row['date_inscription']))->modify('+' . attente_minutes($row) . ' minutes'); }
    catch (Throwable $e) { return null; }
}

/** Libère (supprime) les inscriptions en attente de ces jetons, SAUF si le directeur autorise l'attente. Retourne le nombre conservé. */
function attente_liberer(PDO $pdo, array $tokens): int {
    if (!$tokens) return 0;
    attente_ensure($pdo);
    $in = implode(',', array_fill(0, count($tokens), '?'));
    $pdo->prepare("DELETE i FROM inscriptions i JOIN camps c ON c.id = i.camp_id
                   WHERE i.reservation_token IN ($in) AND i.statut_paiement = 'EN_ATTENTE' AND c.attente_paiement = 0")
        ->execute(array_values($tokens));
    $st = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE reservation_token IN ($in) AND statut_paiement = 'EN_ATTENTE'");
    $st->execute(array_values($tokens));
    return (int)$st->fetchColumn();
}
