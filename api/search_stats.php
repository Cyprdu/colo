<?php
// api/search_stats.php : journal ANONYME des recherches, pour montrer aux directeurs quels termes amènent des familles vers leurs séjours.
//
// Ce qui est enregistré : le terme tapé (normalisé, 60 caractères au plus), le jour, un compteur. Jamais d'adresse IP, de compte, de cookie, d'identifiant de session.
//   recherche_termes        (jour, terme) -> recherches, sans_resultat
//   recherche_termes_camps  (jour, terme, camp_id) -> affichages (le séjour était dans les premiers résultats), visites (la famille l'a ouvert depuis cette recherche)
// Garde-fous de vie privée :
//   - un terme qui ressemble à un e-mail, un numéro de téléphone ou un identifiant (5 chiffres de suite) n'est jamais enregistré ;
//   - une même visite compte une fois par terme et par jour (la page de résultats peut être rechargée) ;
//   - un directeur ne voit un terme que s'il a été tapé par au moins 3 recherches différentes (SQ_TERME_MIN) ;
//   - tout est effacé au bout de 400 jours.

require_once __DIR__ . '/search_text.php';

const SQ_TERME_MIN = 3;

function sq_stats_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS recherche_termes (
            jour DATE NOT NULL, terme VARCHAR(60) NOT NULL, recherches INT NOT NULL DEFAULT 0, sans_resultat INT NOT NULL DEFAULT 0,
            PRIMARY KEY (jour, terme), KEY idx_terme (terme, jour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS recherche_termes_camps (
            jour DATE NOT NULL, terme VARCHAR(60) NOT NULL, camp_id INT NOT NULL, affichages INT NOT NULL DEFAULT 0, visites INT NOT NULL DEFAULT 0,
            PRIMARY KEY (jour, terme, camp_id), KEY idx_camp (camp_id, jour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (mt_rand(1, 200) === 1) {
            $pdo->exec("DELETE FROM recherche_termes WHERE jour < DATE_SUB(CURDATE(), INTERVAL 400 DAY)");
            $pdo->exec("DELETE FROM recherche_termes_camps WHERE jour < DATE_SUB(CURDATE(), INTERVAL 400 DAY)");
        }
    } catch (Throwable $e) { error_log('sq_stats_ensure: ' . $e->getMessage()); }
}

/** Forme normalisée d'un terme enregistrable, ou null (trop court, trop long, e-mail, numéro). */
function sq_terme_loggable(string $raw): ?string {
    if (strpos($raw, '@') !== false) return null;
    $n = sq_norm($raw);
    if (strlen($n) < 2 || strlen($n) > 60 || preg_match('/\d{5,}/', str_replace(' ', '', $n)) || preg_match('/\d{2} \d{2} \d{2} \d{2}/', $n)) return null;
    return $n;
}

/**
 * Enregistre une recherche. $ids : les séjours montrés en tête de résultats. $vus : mémoire de la session (passée par référence : $_SESSION['sq_vus']),
 * pour qu'un rechargement ne compte pas deux fois. Retourne true si la recherche a été comptée.
 */
function sq_journaliser(PDO $pdo, string $terme, int $nbResultats, array $ids, array &$vus): bool {
    $t = sq_terme_loggable($terme);
    if ($t === null) return false;
    $jour = date('Y-m-d');
    foreach (array_keys($vus) as $k) if (strpos((string)$k, $jour) !== 0) unset($vus[$k]);   // la mémoire ne garde que le jour courant
    $cle = $jour . '|' . $t;
    if (isset($vus[$cle])) return false;
    $vus[$cle] = 1;
    try {
        sq_stats_ensure($pdo);
        $pdo->prepare("INSERT INTO recherche_termes (jour, terme, recherches, sans_resultat) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE recherches = recherches + 1, sans_resultat = sans_resultat + VALUES(sans_resultat)")
            ->execute([$jour, $t, $nbResultats > 0 ? 0 : 1]);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 12);
        if ($ids) {
            $sql = "INSERT INTO recherche_termes_camps (jour, terme, camp_id, affichages) VALUES " . implode(',', array_fill(0, count($ids), '(?, ?, ?, 1)')) . " ON DUPLICATE KEY UPDATE affichages = affichages + 1";
            $p = []; foreach ($ids as $id) { $p[] = $jour; $p[] = $t; $p[] = $id; }
            $pdo->prepare($sql)->execute($p);
        }
        return true;
    } catch (Throwable $e) { error_log('sq_journaliser: ' . $e->getMessage()); return false; }
}

/** Une famille ouvre un séjour depuis une recherche : $dernier = ['term', 'ids', 'jour'] gardé en session au moment de la recherche. */
function sq_clic_terme(PDO $pdo, int $campId, ?array $dernier): void {
    if (!$dernier || empty($dernier['term']) || !in_array($campId, array_map('intval', (array)($dernier['ids'] ?? [])), true)) return;
    try {
        sq_stats_ensure($pdo);
        $pdo->prepare("INSERT INTO recherche_termes_camps (jour, terme, camp_id, visites) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE visites = visites + 1")
            ->execute([(string)($dernier['jour'] ?? date('Y-m-d')), (string)$dernier['term'], $campId]);
    } catch (Throwable $e) { error_log('sq_clic_terme: ' . $e->getMessage()); }
}

/** Termes qui ont amené des visiteurs vers les séjours d'un organisme (anonymes, seuil de 3 recherches). */
function sq_termes_organisme(PDO $pdo, int $orgaId, int $jours = 30, int $limite = 15): array {
    try {
        sq_stats_ensure($pdo);
        $jours = max(1, min(365, $jours));
        $st = $pdo->prepare("SELECT r.terme, SUM(r.affichages) AS affichages, SUM(r.visites) AS visites
            FROM recherche_termes_camps r JOIN camps c ON c.id = r.camp_id
            WHERE c.organisateur_id = ? AND r.jour >= DATE_SUB(CURDATE(), INTERVAL $jours DAY)
              AND r.terme IN (SELECT terme FROM recherche_termes WHERE jour >= DATE_SUB(CURDATE(), INTERVAL $jours DAY) GROUP BY terme HAVING SUM(recherches) >= " . SQ_TERME_MIN . ")
            GROUP BY r.terme ORDER BY visites DESC, affichages DESC, r.terme LIMIT " . (int)$limite);
        $st->execute([$orgaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('sq_termes_organisme: ' . $e->getMessage()); return []; }
}

/** Termes tapés plusieurs fois et qui ne donnent rien : des idées de mots-clés pour les directeurs. */
function sq_termes_sans_resultat(PDO $pdo, int $jours = 30, int $min = SQ_TERME_MIN, int $limite = 10): array {
    try {
        sq_stats_ensure($pdo);
        $jours = max(1, min(365, $jours));
        $st = $pdo->query("SELECT terme, SUM(recherches) AS recherches FROM recherche_termes WHERE jour >= DATE_SUB(CURDATE(), INTERVAL $jours DAY)
                           GROUP BY terme HAVING SUM(sans_resultat) > 0 AND SUM(recherches) >= " . max(1, $min) . " ORDER BY recherches DESC, terme LIMIT " . (int)$limite);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
