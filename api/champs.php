<?php
// api/champs.php : champs d'inscription personnalisés (questions posées par le directeur du séjour).
//
// Types : texte (une ligne), paragraphe, case (case à cocher), liste (choix dans une liste), nombre, date.
// Les tables sont créées automatiquement au premier usage (idempotent) : aucune migration à lancer.

const CHAMPS_TYPES = [
    'texte'     => 'Texte court',
    'paragraphe'=> 'Texte long',
    'case'      => 'Case à cocher',
    'liste'     => 'Choix dans une liste',
    'nombre'    => 'Nombre',
    'date'      => 'Date',
];

function champs_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS camp_champs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        camp_id INT NOT NULL,
        libelle VARCHAR(255) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'texte',
        options TEXT NULL,
        obligatoire TINYINT(1) NOT NULL DEFAULT 0,
        position INT NOT NULL DEFAULT 0,
        INDEX idx_champs_camp (camp_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inscription_reponses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inscription_id INT NOT NULL,
        champ_id INT NULL,
        libelle VARCHAR(255) NOT NULL,
        valeur TEXT NULL,
        INDEX idx_rep_inscription (inscription_id)
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Champs d'un séjour, dans l'ordre. [] si rien (ou si les tables ne peuvent pas être créées). */
function champs_du_camp(PDO $pdo, int $campId): array {
    try {
        champs_ensure($pdo);
        $st = $pdo->prepare("SELECT * FROM camp_champs WHERE camp_id = ? ORDER BY position, id");
        $st->execute([$campId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('champs_du_camp: ' . $e->getMessage());
        return [];
    }
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['obligatoire'] = (int)$r['obligatoire'];
        $r['choix'] = $r['type'] === 'liste'
            ? array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$r['options']))))
            : [];
    }
    return $rows;
}

/**
 * Valide les réponses d'un enfant. Retourne [reponses valides, erreur|null].
 * $posted = $_POST['reponses'][id enfant] : [id champ => valeur]
 */
function champs_valider(array $champs, array $posted, string $prenom): array {
    $out = [];
    foreach ($champs as $c) {
        $v = $posted[$c['id']] ?? '';
        $v = is_array($v) ? '' : trim((string)$v);
        if ($c['type'] === 'case') $v = $v !== '' ? 'Oui' : 'Non';   // case obligatoire = doit être cochée
        if ($v === '' || ($c['type'] === 'case' && $v === 'Non' && $c['obligatoire'])) {
            if ($c['obligatoire']) return [[], "Réponse manquante pour « {$c['libelle']} » ({$prenom})."];
            if ($v === '') continue;
        }
        if ($c['type'] === 'liste' && !in_array($v, $c['choix'], true)) return [[], "Choix invalide pour « {$c['libelle']} » ({$prenom})."];
        if ($c['type'] === 'nombre' && !is_numeric(str_replace(',', '.', $v))) return [[], "« {$c['libelle']} » ({$prenom}) doit être un nombre."];
        if ($c['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return [[], "« {$c['libelle']} » ({$prenom}) doit être une date."];
        $out[] = ['champ_id' => $c['id'], 'libelle' => $c['libelle'], 'valeur' => mb_substr($v, 0, 2000)];
    }
    return [$out, null];
}

/** Réponses enregistrées pour une inscription (libellé + valeur). */
function champs_reponses(PDO $pdo, int $inscriptionId): array {
    try {
        champs_ensure($pdo);
        $st = $pdo->prepare("SELECT libelle, valeur FROM inscription_reponses WHERE inscription_id = ? ORDER BY id");
        $st->execute([$inscriptionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
