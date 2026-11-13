<?php
// api/equipe.php : « Inscription de mon équipe d'animation » (option B, camps.equipe_active = 1).
//
// Le directeur ouvre un formulaire réservé à SES animateurs (jamais listé dans le catalogue des animateurs), accessible par lien
// (table animateur_liens, voir api/animateurs.php). L'inscrit est un adulte avec son compte : aucune fiche enfant, aucune
// vérification de la tranche d'âge des participants (seulement un âge minimum animateur, au choix du directeur).
//
// Tout est SÉPARÉ des inscriptions d'enfants : table equipe_inscriptions, places propres (equipe_quota), tarifs propres
// (equipe_tarifs), questions propres (equipe_champs). Les places et quotas garçons/filles des enfants ne sont donc jamais consommés,
// et la remise fratrie ne s'applique pas. Le paiement passe par Stripe (api/equipe_inscrire.php) et le webhook existant.
// Comptabilité : le paiement alimente fin_paiements avec inscription_id = -id (négatif) pour ne jamais entrer en collision
// avec une inscription d'enfant (clé unique de fin_paiements), le solde de l'organisme et la commission sont calculés comme d'habitude.

require_once __DIR__ . '/champs.php';

const EQUIPE_ATTENTE_MIN = 30;   // un paiement non finalisé garde la place 30 minutes
const EQUIPE_MAX_PRIX = 10000;

function equipe_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM camps")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('equipe_age_min', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN equipe_age_min INT NULL");
        if (!in_array('equipe_quota', $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN equipe_quota INT NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS equipe_tarifs (
            id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, nom VARCHAR(120) NOT NULL,
            prix DECIMAL(8,2) NOT NULL DEFAULT 0, montant_libre TINYINT(1) NOT NULL DEFAULT 0, montant_min DECIMAL(8,2) NULL,
            position INT NOT NULL DEFAULT 0, INDEX idx_eqt_camp (camp_id)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS equipe_champs (
            id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, libelle VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL DEFAULT 'texte',
            options TEXT NULL, obligatoire TINYINT(1) NOT NULL DEFAULT 0, position INT NOT NULL DEFAULT 0, INDEX idx_eqc_camp (camp_id)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS equipe_inscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, user_id INT NOT NULL, lien_id INT NULL,
            token CHAR(32) NOT NULL,
            prenom VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL, date_naissance DATE NULL, email VARCHAR(190) NOT NULL, tel VARCHAR(30) NULL,
            bafa_statut VARCHAR(12) NOT NULL DEFAULT 'aucun', diplome VARCHAR(255) NULL,
            allergies TEXT NULL, regime VARCHAR(255) NULL, urgence_nom VARCHAR(120) NULL, urgence_tel VARCHAR(30) NULL,
            tarif_nom VARCHAR(120) NULL, prix_final DECIMAL(8,2) NOT NULL DEFAULT 0, montant_paye DECIMAL(8,2) NOT NULL DEFAULT 0,
            statut VARCHAR(12) NOT NULL DEFAULT 'En attente', statut_paiement VARCHAR(12) NOT NULL DEFAULT 'EN_ATTENTE',
            stripe_session_id VARCHAR(120) NULL, stripe_payment_intent_id VARCHAR(120) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_eq_token (token), INDEX idx_eq_camp (camp_id), INDEX idx_eq_user (user_id)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS equipe_reponses (
            id INT AUTO_INCREMENT PRIMARY KEY, equipe_id INT NOT NULL, champ_id INT NULL, libelle VARCHAR(255) NOT NULL, valeur TEXT NULL,
            INDEX idx_eqr (equipe_id)
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('equipe_ensure: ' . $e->getMessage()); }
    $done = true;
}

function equipe_tarifs(PDO $pdo, int $campId): array {
    $st = $pdo->prepare("SELECT id, nom, prix, montant_libre, montant_min FROM equipe_tarifs WHERE camp_id = ? ORDER BY position, id");
    $st->execute([$campId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Plus petit montant accepté d'un tarif libre : le minimum du directeur, sinon le prix du tarif */
function equipe_plancher(array $t): float {
    $m = (float)($t['montant_min'] ?? 0);
    return $m > 0 ? $m : (float)$t['prix'];
}

/** Questions propres à l'équipe, au même format que champs_du_camp() (réutilise champs_valider) */
function equipe_champs(PDO $pdo, int $campId): array {
    $st = $pdo->prepare("SELECT * FROM equipe_champs WHERE camp_id = ? ORDER BY position, id");
    $st->execute([$campId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id']; $r['obligatoire'] = (int)$r['obligatoire'];
        $r['choix'] = $r['type'] === 'liste' ? array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$r['options'])))) : [];
    }
    return $rows;
}

/** Places déjà prises par l'équipe : payées, gratuites confirmées, ou paiement en cours depuis moins de 30 min */
function equipe_places_prises(PDO $pdo, int $campId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM equipe_inscriptions WHERE camp_id = ? AND statut <> 'Annulé'
        AND (statut_paiement <> 'EN_ATTENTE' OR created_at > (NOW() - INTERVAL " . EQUIPE_ATTENTE_MIN . " MINUTE))");
    $st->execute([$campId]);
    return (int)$st->fetchColumn();
}

/** Configuration (B) lisible par le formulaire du séjour et le tableau de bord */
function equipe_config(PDO $pdo, array $camp): array {
    equipe_ensure($pdo);
    return [
        'active' => !empty($camp['equipe_active']), 'age_min' => $camp['equipe_age_min'] ?? null, 'quota' => $camp['equipe_quota'] ?? null,
        'tarifs' => array_map(fn($t) => ['id' => (int)$t['id'], 'nom' => $t['nom'], 'prix' => (float)$t['prix'], 'libre' => (int)$t['montant_libre'], 'min' => $t['montant_min'] !== null ? (float)$t['montant_min'] : null], equipe_tarifs($pdo, (int)$camp['id'])),
        'champs' => array_map(fn($c) => ['id' => $c['id'], 'libelle' => $c['libelle'], 'type' => $c['type'], 'options' => (string)$c['options'], 'obligatoire' => $c['obligatoire']], equipe_champs($pdo, (int)$camp['id'])),
    ];
}

/** Enregistre la configuration (A + B) d'un séjour DÉJÀ vérifié comme appartenant au directeur. Retourne un message d'erreur ou null. */
function equipe_enregistrer(PDO $pdo, array $camp, array $in): ?string {
    equipe_ensure($pdo);
    $campId = (int)$camp['id'];

    // (A) recrutement via ColoMap
    if (array_key_exists('recrute', $in)) {
        $rem = null;
        if (!empty($in['recrute']) && isset($in['remuneration']) && $in['remuneration'] !== '' && $in['remuneration'] !== null) {
            if (!is_numeric($in['remuneration']) || (float)$in['remuneration'] < 0 || (float)$in['remuneration'] > 100000) return 'Rémunération invalide.';
            $rem = round((float)$in['remuneration'], 2);
        }
        $r = !empty($in['recrute']) ? 1 : 0;
        $pdo->prepare("UPDATE camps SET recrute_animateurs = ?, gestion_animateur = ?, anim_mode = ?, anim_remuneration = ? WHERE id = ?")
            ->execute([$r, $r, $r ? 'recrute' : 'aucun', $rem, $campId]);
    }

    // (B) inscription de l'équipe
    $e = $in['equipe'] ?? null;
    if (!is_array($e)) return null;
    $active = !empty($e['active']) ? 1 : 0;
    $ageMin = isset($e['age_min']) && is_numeric($e['age_min']) && (int)$e['age_min'] > 0 ? min((int)$e['age_min'], 99) : null;
    $quota = isset($e['quota']) && is_numeric($e['quota']) && (int)$e['quota'] > 0 ? min((int)$e['quota'], 5000) : null;
    $tarifs = is_array($e['tarifs'] ?? null) ? array_slice($e['tarifs'], 0, 10) : [];
    $champs = is_array($e['champs'] ?? null) ? array_slice($e['champs'], 0, 20) : [];

    $cleanT = [];
    foreach ($tarifs as $i => $t) {
        $nom = mb_substr(trim((string)($t['nom'] ?? '')), 0, 120);
        if ($nom === '') continue;
        $libre = !empty($t['libre']) ? 1 : 0;
        $prix = round(max(0, (float)str_replace(',', '.', (string)($t['prix'] ?? 0))), 2);
        $min = $libre && isset($t['min']) && $t['min'] !== '' ? round(max(0, (float)str_replace(',', '.', (string)$t['min'])), 2) : null;
        if ($prix > EQUIPE_MAX_PRIX || ($min !== null && $min > EQUIPE_MAX_PRIX)) return 'Montant trop élevé.';
        if ($prix > 0 && $prix < 0.5 || ($min !== null && $min > 0 && $min < 0.5)) return 'Le montant minimum de paiement par carte est de 0,50 €.';
        $cleanT[] = [$nom, $prix, $libre, $min, $i];
    }
    $cleanC = [];
    foreach ($champs as $i => $c) {
        $lib = mb_substr(trim((string)($c['libelle'] ?? '')), 0, 255);
        $type = (string)($c['type'] ?? 'texte');
        if ($lib === '') continue;
        if (!isset(CHAMPS_TYPES[$type])) return 'Type de question inconnu.';
        $opts = null;
        if ($type === 'liste') {
            $l = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($c['options'] ?? ''))))));
            if (count($l) < 2) return "La liste « $lib » doit proposer au moins 2 choix.";
            $opts = implode("\n", array_map(fn($x) => mb_substr($x, 0, 120), $l));
        }
        $cleanC[] = [(int)($c['id'] ?? 0), $lib, $type, $opts, !empty($c['obligatoire']) ? 1 : 0, $i];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE camps SET equipe_active = ?, equipe_age_min = ?, equipe_quota = ? WHERE id = ?")->execute([$active, $ageMin, $quota, $campId]);
        // Tarifs : on remplace tout (les inscriptions existantes gardent le nom et le prix payés)
        $pdo->prepare("DELETE FROM equipe_tarifs WHERE camp_id = ?")->execute([$campId]);
        $ins = $pdo->prepare("INSERT INTO equipe_tarifs (camp_id, nom, prix, montant_libre, montant_min, position) VALUES (?,?,?,?,?,?)");
        foreach ($cleanT as [$nom, $prix, $libre, $min, $pos]) $ins->execute([$campId, $nom, $prix, $libre, $min, $pos]);
        // Questions : mise à jour par identifiant (les réponses déjà données gardent leur libellé)
        $st = $pdo->prepare("SELECT id FROM equipe_champs WHERE camp_id = ?"); $st->execute([$campId]);
        $exist = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); $keep = [];
        foreach ($cleanC as [$id, $lib, $type, $opts, $ob, $pos]) {
            if ($id && in_array($id, $exist, true)) {
                $pdo->prepare("UPDATE equipe_champs SET libelle=?, type=?, options=?, obligatoire=?, position=? WHERE id=? AND camp_id=?")->execute([$lib, $type, $opts, $ob, $pos, $id, $campId]);
                $keep[] = $id;
            } else {
                $pdo->prepare("INSERT INTO equipe_champs (camp_id, libelle, type, options, obligatoire, position) VALUES (?,?,?,?,?,?)")->execute([$campId, $lib, $type, $opts, $ob, $pos]);
                $keep[] = (int)$pdo->lastInsertId();
            }
        }
        $del = array_diff($exist, $keep);
        if ($del) $pdo->prepare("DELETE FROM equipe_champs WHERE camp_id = ? AND id IN (" . implode(',', array_fill(0, count($del), '?')) . ")")->execute(array_merge([$campId], array_values($del)));
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('equipe_enregistrer: ' . $ex->getMessage());
        return 'Enregistrement impossible.';
    }
    return null;
}

/** Paiement confirmé (webhook) : marque les inscriptions d'équipe et alimente la comptabilité. Retourne le nombre de lignes comptabilisées. */
function equipe_confirmer(PDO $pdo, array $tokens, ?string $paymentIntent, string $sessionId, int $totalCents, float $ratio): int {
    equipe_ensure($pdo);
    $tokens = array_values(array_filter($tokens, fn($t) => preg_match('/^[a-f0-9]{32}$/', $t)));
    if (!$tokens) return 0;
    $in = implode(',', array_fill(0, count($tokens), '?'));
    $pdo->prepare("UPDATE equipe_inscriptions SET statut = 'Confirmé', statut_paiement = 'PAYE', stripe_payment_intent_id = ?, montant_paye = ROUND(prix_final * ?, 2)
                   WHERE token IN ($in) AND statut_paiement <> 'PAYE'")->execute(array_merge([$paymentIntent, $ratio], $tokens));
    if ($totalCents <= 0) return 0;
    require_once __DIR__ . '/finance_lib.php';
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT e.id, e.prix_final, e.camp_id, c.date_debut, c.organisateur_id FROM equipe_inscriptions e JOIN camps c ON c.id = e.camp_id
                         WHERE e.token IN ($in) AND e.prix_final > 0 ORDER BY e.id");
    $st->execute($tokens);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $sum = 0.0; foreach ($rows as $r) $sum += (float)$r['prix_final'];
    if (!$rows || $sum <= 0) return 0;
    $left = $totalCents; $n = count($rows); $created = 0;
    $ins = $pdo->prepare("INSERT IGNORE INTO fin_paiements (inscription_id, organisateur_id, camp_id, payment_intent, session_id, brut_cents, commission_cents, net_cents, commission_pct, date_deblocage)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $k => $r) {
        $brut = ($k === $n - 1) ? $left : (int)round($totalCents * (float)$r['prix_final'] / $sum);
        $brut = max(0, min($brut, $left)); $left -= $brut;
        if ($brut <= 0 || !$r['organisateur_id']) continue;
        $com = fin_commission_cents($brut); $net = $brut - $com;
        $ins->execute([-(int)$r['id'], $r['organisateur_id'], $r['camp_id'], $paymentIntent, $sessionId, $brut, $com, $net, fin_commission_pct(), fin_release_date($r['date_debut'])]);
        if ($ins->rowCount() === 1) {
            $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) + ? WHERE id = ?")->execute([$net / 100, $r['organisateur_id']]);
            fin_audit($pdo, 'paiement_recu', 'equipe', $r['id'], ['brut' => $brut, 'commission' => $com, 'net' => $net, 'pi' => $paymentIntent], 'webhook');
            $created++;
        }
    }
    return $created;
}

/** Session de paiement expirée / annulée : libère les places d'équipe non payées */
function equipe_liberer(PDO $pdo, array $tokens, ?int $userId = null): void {
    equipe_ensure($pdo);
    $tokens = array_values(array_filter($tokens, fn($t) => preg_match('/^[a-f0-9]{32}$/', $t)));
    if (!$tokens) return;
    $in = implode(',', array_fill(0, count($tokens), '?'));
    $sql = "DELETE FROM equipe_inscriptions WHERE token IN ($in) AND statut_paiement = 'EN_ATTENTE'" . ($userId ? " AND user_id = " . (int)$userId : '');
    $pdo->prepare($sql)->execute($tokens);
}
