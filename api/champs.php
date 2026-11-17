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

/**
 * Enregistre la liste de questions d'UNE session (remplace l'existant : mise à jour par identifiant, ajout, suppression).
 * Les réponses déjà données gardent leur libellé. À appeler dans la transaction de l'appelant (tables créées avant : champs_ensure).
 * @throws InvalidArgumentException message destiné au directeur
 */
function champs_enregistrer(PDO $pdo, int $campId, array $champs): void {
    if (count($champs) > 30) throw new InvalidArgumentException('30 questions maximum par session.');
    $clean = [];
    foreach (array_values($champs) as $i => $c) {
        $libelle = mb_substr(trim((string)($c['libelle'] ?? '')), 0, 255);
        $type = (string)($c['type'] ?? 'texte');
        if ($libelle === '') continue;
        if (!isset(CHAMPS_TYPES[$type])) throw new InvalidArgumentException('Type de question inconnu.');
        $options = null;
        if ($type === 'liste') {
            $lignes = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($c['options'] ?? ''))))));
            if (count($lignes) < 2) throw new InvalidArgumentException("La liste « $libelle » doit proposer au moins 2 choix.");
            $options = implode("\n", array_map(fn($l) => mb_substr($l, 0, 120), $lignes));
        }
        $clean[] = [(int)($c['id'] ?? 0), $libelle, $type, $options, !empty($c['obligatoire']) ? 1 : 0, $i];
    }
    $ex = $pdo->prepare("SELECT id FROM camp_champs WHERE camp_id = ?");
    $ex->execute([$campId]);
    $existingIds = array_map('intval', $ex->fetchAll(PDO::FETCH_COLUMN));
    $keep = [];
    $ins = $pdo->prepare("INSERT INTO camp_champs (camp_id, libelle, type, options, obligatoire, position) VALUES (?, ?, ?, ?, ?, ?)");
    $upd = $pdo->prepare("UPDATE camp_champs SET libelle = ?, type = ?, options = ?, obligatoire = ?, position = ? WHERE id = ? AND camp_id = ?");
    foreach ($clean as [$id, $libelle, $type, $options, $oblig, $pos]) {
        if ($id && in_array($id, $existingIds, true)) { $upd->execute([$libelle, $type, $options, $oblig, $pos, $id, $campId]); $keep[] = $id; }
        else { $ins->execute([$campId, $libelle, $type, $options, $oblig, $pos]); $keep[] = (int)$pdo->lastInsertId(); }
    }
    $del = array_diff($existingIds, $keep);
    if ($del) {
        $q = implode(',', array_fill(0, count($del), '?'));
        $pdo->prepare("DELETE FROM camp_champs WHERE camp_id = ? AND id IN ($q)")->execute(array_merge([$campId], array_values($del)));
    }
}
