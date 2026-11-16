<?php
// api/motscles.php : mots-clés et alias (« hashtags ») d'un séjour ou d'un organisme.
//
// Ce sont les mots que les familles tapent : « PAJE » pour la Pastorale des Jeunes de l'Ain, « EPJ » pour un séjour « École de prière ».
// Tables : camp_motscles (camp_id, mot, label) et organisme_motscles (organisateur_id, mot, label), créées automatiquement.
//   mot   = forme normalisée (minuscules, sans accents) : c'est ce qui est indexé et comparé
//   label = ce que le directeur a tapé (« PAJE »), affiché tel quel
// Limites et anti-abus (côté serveur, le navigateur ne fait que les rappeler) :
//   12 mots-clés par séjour, 8 alias par organisme, 30 caractères, 3 mots au plus, pas d'adresse web, d'e-mail ni de numéro de téléphone,
//   pas de mot « passe-partout » (colo, séjour...), pas le nom ni l'alias d'un AUTRE organisme (usurpation),
//   et un alias d'organisme ne peut pas être un thème (« ski », « cheval ») sauf si ce mot est dans le nom de l'organisme.
// Le plafond de poids (le meilleur mot-clé compte, pas la somme) et la confiance accordée à chaque mot sont dans search_lib.php.

require_once __DIR__ . '/search_text.php';

const MC_MAX_CAMP = 12;
const MC_MAX_ORGA = 8;
const MC_LONGUEUR = 30;
const MC_MOTS = 3;

function mc_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS camp_motscles (
            camp_id INT NOT NULL, mot VARCHAR(40) NOT NULL, label VARCHAR(40) NOT NULL,
            PRIMARY KEY (camp_id, mot), KEY idx_mot (mot)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS organisme_motscles (
            organisateur_id INT NOT NULL, mot VARCHAR(40) NOT NULL, label VARCHAR(40) NOT NULL,
            PRIMARY KEY (organisateur_id, mot), KEY idx_mot (mot)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('mc_ensure: ' . $e->getMessage()); }
}

/** Forme comparée d'un mot-clé : « #PAJE » et « Paje » sont le même mot. */
function mc_norm(string $label): string { return sq_norm(ltrim(trim($label), '#')); }

/** Texte affichable : sans #, espaces simples, 30 caractères au plus. */
function mc_label(string $label): string {
    return mb_substr(trim(preg_replace('/\s+/u', ' ', ltrim(trim($label), '#'))), 0, MC_LONGUEUR);
}

/** Liste de mots-clés reçue du formulaire : tableau, JSON ou texte séparé par des virgules. 40 entrées au plus (protection). */
function mc_liste($raw): array {
    if (is_string($raw)) {
        $j = json_decode($raw, true);
        $raw = is_array($j) ? $j : preg_split('/[,;\n]+/u', $raw);
    }
    $out = [];
    foreach ((array)$raw as $v) { if (is_string($v) && trim($v) !== '') $out[] = trim($v); if (count($out) >= 40) break; }
    return $out;
}

/** Noms des organismes (normalisés) pour repérer l'usurpation : id => nom normalisé. */
function mc_noms_organismes(PDO $pdo): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach ($pdo->query("SELECT id, nom FROM organisateurs")->fetchAll(PDO::FETCH_ASSOC) as $r) $cache[(int)$r['id']] = sq_norm((string)$r['nom']); } catch (Throwable $e) {}
    }
    return $cache;
}

/**
 * Contrôle une liste de mots-clés. $niveau : 'camp' ou 'orga'. $orgaId : l'organisme concerné (0 = organisme en création). $orgaNom : son nom (pour l'exception des thèmes).
 * $pdo null : seulement les règles de forme (migration des anciens mots-clés).
 * Retourne ['ok' => [['mot' => ..., 'label' => ...]], 'refus' => [['label' => ..., 'raison' => ...]]].
 */
function mc_valider(?PDO $pdo, array $labels, string $niveau, int $orgaId = 0, string $orgaNom = ''): array {
    $max = $niveau === 'orga' ? MC_MAX_ORGA : MC_MAX_CAMP;
    $ok = []; $refus = []; $vus = [];
    $nomsOrgas = $pdo ? mc_noms_organismes($pdo) : [];
    $nomOrga = sq_norm($orgaNom) ?: ($orgaId && isset($nomsOrgas[$orgaId]) ? $nomsOrgas[$orgaId] : '');
    $tokNomOrga = sq_tokens($nomOrga);
    $r = function (string $label, string $raison) use (&$refus) { $refus[] = ['label' => mb_substr($label, 0, 60), 'raison' => $raison]; };
    foreach ($labels as $brut) {
        $brut = (string)$brut;
        $label = mc_label($brut);
        $mot = mc_norm($label);
        if ($mot === '') continue;
        if (isset($vus[$mot])) continue;
        if (mb_strlen(trim(ltrim(trim($brut), '#'))) > MC_LONGUEUR) { $r($brut, 'Trop long (' . MC_LONGUEUR . ' caractères au maximum).'); continue; }
        if (strlen($mot) < 2) { $r($label, 'Trop court.'); continue; }
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} \'’\-.]*$/u', $label) || preg_match('/@|https?|www\.|\.(com|fr|org|net)\b/iu', $label)) { $r($label, "Seulement des lettres et des chiffres : pas d'adresse web ni d'e-mail."); continue; }
        if (preg_match('/\d{5,}/', $mot)) { $r($label, 'Pas de numéro de téléphone.'); continue; }
        $tok = sq_tokens($mot);
        if (count($tok) > MC_MOTS) { $r($label, MC_MOTS . ' mots au maximum par mot-clé.'); continue; }
        if (!sq_tokens($mot, true)) { $r($label, 'Trop général : tout le monde le tape, il n\'apporte rien (colo, séjour, camp...).'); continue; }
        if ($pdo) {
            foreach ($nomsOrgas as $oid => $nom) if ($oid !== $orgaId && $nom !== '' && $nom === $mot) { $r($label, "C'est le nom d'un autre organisme."); continue 2; }
            try {
                $st = $pdo->prepare("SELECT 1 FROM organisme_motscles WHERE mot = ? AND organisateur_id <> ? LIMIT 1");
                $st->execute([$mot, $orgaId]);
                if ($st->fetchColumn()) { $r($label, "Ce mot est l'alias d'un autre organisme."); continue; }
            } catch (Throwable $e) {}
        }
        if ($niveau === 'orga') {   // un alias d'organisme est un nom, pas un thème (sinon « ski » ferait remonter tous ses séjours sur n'importe quelle recherche de ski)
            foreach ($tok as $t) if (sq_syn_of($t) && !in_array($t, $tokNomOrga, true)) { $r($label, "C'est un thème, pas un nom : choisissez-le plutôt dans les catégories de vos séjours."); continue 2; }
        }
        if (count($ok) >= $max) { $r($label, 'Limite atteinte : ' . $max . ' mots-clés au maximum.'); continue; }
        $vus[$mot] = true;
        $ok[] = ['mot' => mb_substr($mot, 0, 40), 'label' => $label];
    }
    return ['ok' => $ok, 'refus' => $refus];
}

/** Remplace les mots-clés d'un séjour ('camp') ou d'un organisme ('orga'). */
function mc_enregistrer(PDO $pdo, string $niveau, int $id, array $ok): void {
    [$table, $col] = $niveau === 'orga' ? ['organisme_motscles', 'organisateur_id'] : ['camp_motscles', 'camp_id'];
    $pdo->prepare("DELETE FROM $table WHERE $col = ?")->execute([$id]);
    $ins = $pdo->prepare("INSERT IGNORE INTO $table ($col, mot, label) VALUES (?, ?, ?)");
    foreach ($ok as $m) $ins->execute([$id, $m['mot'], $m['label']]);
    if (function_exists('sq_vocab_invalider')) sq_vocab_invalider();
}

/** Mots-clés (étiquettes) de plusieurs séjours / organismes : [id => [label, ...]]. */
function mc_lire(PDO $pdo, string $niveau, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    [$table, $col] = $niveau === 'orga' ? ['organisme_motscles', 'organisateur_id'] : ['camp_motscles', 'camp_id'];
    $out = [];
    try {
        foreach ($pdo->query("SELECT $col AS id, label FROM $table WHERE $col IN (" . implode(',', $ids) . ") ORDER BY label")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']][] = $r['label'];
    } catch (Throwable $e) { error_log('mc_lire: ' . $e->getMessage()); }
    return $out;
}

/** Anciens mots-clés (camps.type : « Surf, Plage ») -> table des mots-clés, une seule fois par séjour. */
function mc_depuis_legacy(PDO $pdo, int $campId, string $type): void {
    if (trim($type) === '') return;
    $st = $pdo->prepare("SELECT 1 FROM camp_motscles WHERE camp_id = ? LIMIT 1");
    $st->execute([$campId]);
    if ($st->fetchColumn()) return;
    $v = mc_valider(null, mc_liste($type), 'camp');
    $ins = $pdo->prepare("INSERT IGNORE INTO camp_motscles (camp_id, mot, label) VALUES (?, ?, ?)");
    foreach ($v['ok'] as $m) $ins->execute([$campId, $m['mot'], $m['label']]);
}

/** Alias d'un organisme reçus d'un formulaire : contrôlés puis enregistrés. Retourne ['motscles' => [étiquettes acceptées], 'motscles_refuses' => [['label', 'raison']]]. */
function mc_sauver_orga(PDO $pdo, int $orgaId, string $nom, $raw): array {
    mc_ensure($pdo);
    $v = mc_valider($pdo, mc_liste($raw), 'orga', $orgaId, $nom);
    mc_enregistrer($pdo, 'orga', $orgaId, $v['ok']);
    return ['motscles' => array_column($v['ok'], 'label'), 'motscles_refuses' => $v['refus']];
}
