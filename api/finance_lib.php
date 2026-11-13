<?php
// api/finance_lib.php : flux d'argent entre les familles, ColoMap et les organismes (Stripe Connect).
//
// ARCHITECTURE : « separate charges and transfers » avec comptes Connect EXPRESS
//   1. la famille paie ColoMap (Checkout) ; le webhook enregistre UNE ligne fin_paiements par inscription
//      (brut, commission calculée ICI côté serveur, net, date de déblocage = début du séjour + délai) ;
//   2. à la date de déblocage, un job (api/cron_virements.php) transfère le net de l'organisme vers son compte Connect
//      (Transfer + clé d'idempotence) ; Stripe verse ensuite sur le compte bancaire de l'organisme (IBAN saisi chez Stripe) ;
//   3. remboursements / litiges : ajustent le solde ; un solde négatif bloque les transferts suivants (compensation).
// organisateurs.portefeuille = solde « à recevoir » (net de commission, avant transfert).
// Aucune donnée bancaire ni pièce d'identité ne transite plus par ColoMap : tout est collecté par Stripe.
//
// COMMISSION « TOUT COMPRIS » : la commission ColoMap (pourcentage + part fixe en centimes, réglable dans Administration > Comptabilité)
// inclut les frais de paiement Stripe. Stripe facture ColoMap (≈ 1,5 % + 0,25 € par carte européenne standard) ; l'organisme ne voit
// qu'une seule commission. Les frais Stripe RÉELS de chaque paiement sont relevés (balance_transaction) : marge ColoMap = commission - frais.
// Réglages facultatifs (api/config.php, valeurs par défaut si rien en base) :
//   PLATFORM_COMMISSION_PERCENT (défaut 2.5)   PLATFORM_COMMISSION_FIXED_CENTS (défaut 30)   (calendrier des versements : api/versements_lib.php, réglages dans Administration > Comptabilité > Versements)
//   STRIPE_CONNECT_WEBHOOK_SECRET (endpoint « événements des comptes connectés »)   CRON_SECRET
// Les valeurs saisies dans l'administration (table fin_reglages) l'emportent sur api/config.php.

const FIN_DEFAULT_PCT = 2.5;
const FIN_DEFAULT_FIXE_CENTS = 30;

/** Réglages de commission : base (admin) > constantes api/config.php > défauts. Mis en cache pour la requête. */
function fin_reglages(bool $refresh = false): array {
    static $c = null;
    if ($c !== null && !$refresh) return $c;
    $c = [
        'commission_pct' => defined('PLATFORM_COMMISSION_PERCENT') ? (float)PLATFORM_COMMISSION_PERCENT : FIN_DEFAULT_PCT,
        'commission_fixe_cents' => defined('PLATFORM_COMMISSION_FIXED_CENTS') ? (int)PLATFORM_COMMISSION_FIXED_CENTS : FIN_DEFAULT_FIXE_CENTS,
        'delai_securite_jours' => FIN_DEFAULT_DELAI, 'reserve_pct' => FIN_DEFAULT_RESERVE,
    ];
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            fin_ensure($pdo);
            foreach ($pdo->query("SELECT cle, valeur FROM fin_reglages")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                if ($k === 'commission_pct') $c[$k] = (float)$v;
                elseif ($k === 'commission_fixe_cents' || $k === 'delai_securite_jours') $c[$k] = (int)$v;
                elseif ($k === 'reserve_pct') $c[$k] = (float)$v;
            }
        } catch (Throwable $e) { error_log('fin_reglages: ' . $e->getMessage()); }
    }
    $c['commission_pct'] = max(0.0, min(30.0, $c['commission_pct']));
    $c['commission_fixe_cents'] = max(0, min(500, $c['commission_fixe_cents']));
    $c['delai_securite_jours'] = max(0, min(60, $c['delai_securite_jours']));
    $c['reserve_pct'] = max(0.0, min(100.0, $c['reserve_pct']));
    return $c;
}
function fin_commission_pct(): float { return fin_reglages()['commission_pct']; }
function fin_commission_fixe_cents(): int { return fin_reglages()['commission_fixe_cents']; }

/** Enregistre un réglage de commission (valeurs bornées) et le journalise. */
function fin_set_commission(PDO $pdo, float $pct, int $fixeCents, string $acteur): void {
    fin_ensure($pdo);
    $pct = round(max(0.0, min(30.0, $pct)), 2); $fixeCents = max(0, min(500, $fixeCents));
    $old = fin_reglages(true);
    $st = $pdo->prepare("INSERT INTO fin_reglages (cle, valeur, par) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), par = VALUES(par), maj = NOW()");
    $st->execute(['commission_pct', (string)$pct, substr($acteur, 0, 40)]);
    $st->execute(['commission_fixe_cents', (string)$fixeCents, substr($acteur, 0, 40)]);
    fin_audit($pdo, 'commission_modifiee', 'reglage', 'commission', ['avant' => $old, 'apres' => ['pct' => $pct, 'fixe_cents' => $fixeCents]], $acteur);
    fin_reglages(true);
}

/**
 * Commission « tout compris » sur un montant payé (centimes) : [total, part proportionnelle, part fixe].
 * Toujours calculée côté serveur, arrondie au centime, jamais supérieure au montant (rien n'est prélevé sur un montant nul).
 */
function fin_commission_split(int $brutCents, ?float $pct = null, ?int $fixeCents = null): array {
    if ($brutCents <= 0) return [0, 0, 0];
    $pct = $pct ?? fin_commission_pct(); $fixeCents = $fixeCents ?? fin_commission_fixe_cents();
    $prop = (int)round($brutCents * $pct / 100);
    $total = min($brutCents, $prop + $fixeCents);
    $prop = min($prop, $total);
    return [$total, $prop, $total - $prop];
}
/** Commission en centimes (voir fin_commission_split). $fixe = 0 pour ne prélever que le pourcentage. */
function fin_commission_cents(int $brutCents, ?int $fixeCents = null): int {
    return fin_commission_split($brutCents, null, $fixeCents)[0];
}
/** Répartit $total (entier) au prorata de $poids : la somme est exacte au centime près (le dernier reçoit le reste). */
function fin_allocate(int $total, array $poids): array {
    $sum = array_sum($poids); $left = $total; $out = []; $n = count($poids); $i = 0;
    foreach ($poids as $k => $w) {
        $i++;
        $part = $i === $n ? $left : ($sum > 0 ? (int)round($total * $w / $sum) : 0);
        $part = max(0, min($part, $left));
        $out[$k] = $part; $left -= $part;
    }
    return $out;
}

/** Ajoute une colonne si elle n'existe pas (idempotent) */
function fin_add_col(PDO $pdo, string $table, string $col, string $ddl): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $col]);
    if (!(int)$st->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
}

function fin_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_paiements (
            id INT AUTO_INCREMENT PRIMARY KEY, inscription_id INT NOT NULL, organisateur_id INT NOT NULL, camp_id INT NOT NULL,
            payment_intent VARCHAR(80) NULL, session_id VARCHAR(80) NULL,
            brut_cents INT NOT NULL, commission_cents INT NOT NULL, net_cents INT NOT NULL, commission_pct DECIMAL(5,2) NOT NULL,
            rembourse_cents INT NOT NULL DEFAULT 0, net_rembourse_cents INT NOT NULL DEFAULT 0,
            date_deblocage DATETIME NOT NULL,
            statut VARCHAR(12) NOT NULL DEFAULT 'attente', -- attente | bloque | litige | en_cours | verse | rembourse
            transfert_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inscription (inscription_id), KEY idx_release (statut, date_deblocage), KEY idx_orga (organisateur_id), KEY idx_pi (payment_intent)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_transferts (
            id INT AUTO_INCREMENT PRIMARY KEY, token CHAR(60) NOT NULL, organisateur_id INT NOT NULL,
            montant_cents INT NOT NULL, brut_cents INT NULL, type VARCHAR(12) NOT NULL DEFAULT 'sejour', -- sejour | historique (brut_cents : débit du solde si différent du versé)
            statut VARCHAR(10) NOT NULL DEFAULT 'en_cours', -- en_cours | envoye | echec
            stripe_account_id VARCHAR(64) NULL, stripe_transfer_id VARCHAR(64) NULL, nb_paiements INT NOT NULL DEFAULT 0,
            tentatives INT NOT NULL DEFAULT 0, erreur VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_token (token), KEY idx_orga (organisateur_id), KEY idx_statut (statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_audit (
            id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            acteur VARCHAR(40) NOT NULL, action VARCHAR(40) NOT NULL, objet_type VARCHAR(24) NULL, objet_id VARCHAR(80) NULL,
            donnees TEXT NULL, ip VARCHAR(45) NULL, KEY idx_date (created_at), KEY idx_objet (objet_type, objet_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_events (
            event_id VARCHAR(80) NOT NULL PRIMARY KEY, type VARCHAR(60) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_comptes (
            organisateur_id INT NOT NULL PRIMARY KEY, account_id VARCHAR(64) NOT NULL, type VARCHAR(12) NULL,
            details_submitted TINYINT(1) NOT NULL DEFAULT 0, charges_enabled TINYINT(1) NOT NULL DEFAULT 0, payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
            transfers_actif TINYINT(1) NOT NULL DEFAULT 0, exigences TEXT NULL, maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_reglages (
            cle VARCHAR(40) NOT NULL PRIMARY KEY, valeur VARCHAR(100) NOT NULL, par VARCHAR(40) NULL, maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Versements Stripe de la plateforme vers sa banque (copie locale pour le rapprochement bancaire)
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_payouts (
            payout_id VARCHAR(64) NOT NULL PRIMARY KEY, montant_cents INT NOT NULL, statut VARCHAR(16) NOT NULL, date_arrivee DATETIME NULL,
            date_creation DATETIME NOT NULL, banque VARCHAR(40) NULL, maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_date (date_arrivee)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Colonnes ajoutées après coup (idempotent) : taux appliqué + frais Stripe RÉELS (NULL = pas encore relevés)
        fin_add_col($pdo, 'fin_paiements', 'commission_fixe_cents', "INT NOT NULL DEFAULT 0");
        fin_add_col($pdo, 'fin_paiements', 'frais_stripe_cents', "INT NULL");
        fin_add_col($pdo, 'fin_paiements', 'frais_litige_cents', "INT NOT NULL DEFAULT 0");
        fin_add_col($pdo, 'fin_paiements', 'frais_maj', "DATETIME NULL");
        fin_add_col($pdo, 'organisateurs', 'tva_taux', "DECIMAL(4,1) NULL"); // taux de TVA des séjours de l'organisme (NULL = non applicable)
        fin_add_col($pdo, 'fin_paiements', 'tva_taux_pct', "DECIMAL(4,1) NULL"); // taux en vigueur à la vente (pour le reçu de la famille)
        // Annulation par la famille : conditions du séjour (100 % jusqu'à N jours avant le début, 50 % jusqu'à M jours, ensuite rien),
        // remboursement ciblé en attente (annul_attendu) et commission conservée (annul_com : la commission n'est pas rendue au prorata)
        fin_add_col($pdo, 'fin_paiements', 'annul_attendu', "INT NOT NULL DEFAULT 0");
        fin_add_col($pdo, 'fin_paiements', 'annul_com', "TINYINT(1) NOT NULL DEFAULT 0");
        fin_add_col($pdo, 'camps', 'annul_jours_total', "INT NOT NULL DEFAULT 30");
        fin_add_col($pdo, 'camps', 'annul_jours_moitie', "INT NOT NULL DEFAULT 15");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_annulations (
            id INT AUTO_INCREMENT PRIMARY KEY, inscription_id INT NOT NULL, organisateur_id INT NOT NULL, parent_id INT NOT NULL,
            motif VARCHAR(20) NOT NULL, commentaire VARCHAR(500) NULL, pct INT NOT NULL DEFAULT 0,
            rembourse_cents INT NOT NULL DEFAULT 0, commission_retenue_cents INT NOT NULL DEFAULT 0, refund_id VARCHAR(64) NULL,
            lu TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ins (inscription_id), KEY idx_orga (organisateur_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        fin_add_col($pdo, 'stripe_comptes', 'statut', "VARCHAR(16) NULL"); // statut déclaré par l'organisme avant l'inscription Stripe
        // Calendrier des versements : jour fixe par organisme (1 lundi ... 6 samedi), part déjà versée par ligne, détail par transfert, reversements
        fin_add_col($pdo, 'organisateurs', 'jour_versement', "TINYINT NULL");
        fin_add_col($pdo, 'fin_paiements', 'verse_cents', "INT NOT NULL DEFAULT 0");
        fin_add_col($pdo, 'fin_transferts', 'reverse_cents', "INT NOT NULL DEFAULT 0");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_transfert_lignes (
            transfert_id INT NOT NULL, paiement_id INT NOT NULL, montant_cents INT NOT NULL, PRIMARY KEY (transfert_id, paiement_id), KEY idx_pai (paiement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_reversals (
            id INT AUTO_INCREMENT PRIMARY KEY, organisateur_id INT NOT NULL, paiement_id INT NOT NULL, montant_cents INT NOT NULL, recupere_cents INT NOT NULL DEFAULT 0,
            statut VARCHAR(8) NOT NULL DEFAULT 'a_faire', -- a_faire | fait | dette
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_statut (statut), KEY idx_orga (organisateur_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_reversal_ops (
            id INT AUTO_INCREMENT PRIMARY KEY, reversal_id INT NOT NULL, transfert_id INT NOT NULL, montant_cents INT NOT NULL,
            statut VARCHAR(8) NOT NULL DEFAULT 'prevu', -- prevu | ok | echec
            stripe_reversal_id VARCHAR(64) NULL, erreur VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rev (reversal_id), KEY idx_tr (transfert_id), KEY idx_statut (statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Migration unique vers le calendrier hebdomadaire (idempotente) : paiements en attente versables après le délai de sécurité, lignes déjà versées soldées
        $mig = $pdo->prepare("SELECT COUNT(*) FROM fin_reglages WHERE cle = 'migr_versements_v1'");
        $mig->execute();
        if (!(int)$mig->fetchColumn()) {
            $pdo->exec("UPDATE fin_paiements SET verse_cents = GREATEST(0, net_cents - net_rembourse_cents) WHERE statut = 'verse' AND verse_cents = 0");
            $dv = $pdo->prepare("SELECT valeur FROM fin_reglages WHERE cle = 'delai_securite_jours'"); $dv->execute();
            $dval = $dv->fetchColumn();
            $d = $dval !== false ? (int)$dval : FIN_DEFAULT_DELAI;
            $pdo->prepare("UPDATE fin_paiements SET date_deblocage = DATE_ADD(created_at, INTERVAL ? DAY) WHERE statut = 'attente' AND date_deblocage > DATE_ADD(created_at, INTERVAL ? DAY)")->execute([$d, $d]);
            $pdo->exec("INSERT IGNORE INTO fin_reglages (cle, valeur, par) VALUES ('migr_versements_v1', '1', 'migration')");
        }
        fin_assign_jours($pdo);
    } catch (Throwable $e) {
        error_log('fin_ensure: ' . $e->getMessage());
    }
    $done = true;
}

function fin_stripe(): \Stripe\StripeClient {
    if (!class_exists('\Stripe\StripeClient')) require_once __DIR__ . '/../vendor/autoload.php';
    if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') throw new Exception('Clé Stripe non configurée.');
    return new \Stripe\StripeClient(STRIPE_SECRET_KEY);
}

/** Journal d'audit : toute opération financière y laisse une trace (acteur, action, objet, données JSON, IP) */
function fin_audit(PDO $pdo, string $action, string $objType, $objId, array $data = [], string $acteur = 'systeme'): void {
    fin_ensure($pdo);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $pdo->prepare("INSERT INTO fin_audit (acteur, action, objet_type, objet_id, donnees, ip) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([substr($acteur, 0, 40), substr($action, 0, 40), $objType, (string)$objId, json_encode($data, JSON_UNESCAPED_UNICODE), $ip]);
}

/** Part du solde qui peut financer des points (le solde « à recevoir » complet, tant qu'il est positif). Appeler dans la transaction qui verrouille l'organisme. */
function fin_points_budget_cents(PDO $pdo, int $orgaId): int {
    $st = $pdo->prepare("SELECT portefeuille FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    return max(0, (int)round((float)$st->fetchColumn() * 100));
}

// ---------------------------------------------------------------------------------------------
// Entrées d'argent (appelé par le webhook, dans SA transaction)
// ---------------------------------------------------------------------------------------------
/**
 * Enregistre le paiement d'une commande : une ligne par inscription, le montant payé (après code promo) réparti
 * au prorata des prix. Idempotent (une inscription = une ligne). Crédite le solde net de commission.
 */
function fin_record_checkout(PDO $pdo, array $tokens, ?string $paymentIntent, string $sessionId, int $amountTotalCents): int {
    fin_ensure($pdo);
    if ($amountTotalCents <= 0 || !$tokens) return 0;
    $in = implode(',', array_fill(0, count($tokens), '?'));
    $st = $pdo->prepare("SELECT i.id, i.prix_final, i.camp_id, c.date_debut, c.organisateur_id, (SELECT o.tva_taux FROM organisateurs o WHERE o.id = c.organisateur_id) AS tva
                         FROM inscriptions i JOIN camps c ON c.id = i.camp_id
                         WHERE i.reservation_token IN ($in) AND i.prix_final > 0 ORDER BY i.id");
    $st->execute(array_values($tokens));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    $sum = 0.0;
    foreach ($rows as $r) $sum += (float)$r['prix_final'];
    if ($sum <= 0) return 0;

    $left = $amountTotalCents; $n = count($rows); $created = 0;
    $ins = $pdo->prepare("INSERT IGNORE INTO fin_paiements (inscription_id, organisateur_id, camp_id, payment_intent, session_id, brut_cents, commission_cents, net_cents, commission_pct, commission_fixe_cents, tva_taux_pct, date_deblocage)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $k => $r) {
        $brut = ($k === $n - 1) ? $left : (int)round($amountTotalCents * (float)$r['prix_final'] / $sum);
        $brut = max(0, min($brut, $left)); $left -= $brut;
        if ($brut <= 0 || !$r['organisateur_id']) continue;
        [$com, , $fixe] = fin_commission_split($brut); $net = $brut - $com;
        $ins->execute([$r['id'], $r['organisateur_id'], $r['camp_id'], $paymentIntent, $sessionId, $brut, $com, $net, fin_commission_pct(), $fixe, $r['tva'], fin_release_date($r['date_debut'])]);
        if ($ins->rowCount() === 1) {
            $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) + ? WHERE id = ?")->execute([$net / 100, $r['organisateur_id']]);
            fin_audit($pdo, 'paiement_recu', 'inscription', $r['id'], ['brut' => $brut, 'commission' => $com, 'net' => $net, 'pi' => $paymentIntent], 'webhook');
            $created++;
        }
    }
    return $created;
}

/** Remboursement (total ou partiel) d'un paiement : réduit le net dû à l'organisme au prorata (la commission est rendue au prorata aussi). */
function fin_apply_refund(PDO $pdo, string $paymentIntent, int $refundedCents, int $capturedCents): void {
    fin_ensure($pdo);
    if ($capturedCents <= 0 || $refundedCents <= 0) return;
    $st = $pdo->prepare("SELECT * FROM fin_paiements WHERE payment_intent = ? FOR UPDATE");
    $st->execute([$paymentIntent]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // Annulation par la famille (fin_annul_executer) : le remboursement revient à CETTE inscription (montant attendu), pas au prorata des frères et sœurs,
    // et la commission ColoMap est conservée : seul le net de l'organisme est débité, du montant remboursé.
    $reste = $refundedCents - (int)array_sum(array_column($rows, 'rembourse_cents'));
    foreach ($rows as $p) {
        $brut = (int)$p['brut_cents'];
        $att = (int)$p['annul_attendu'];
        if ($att > 0) {
            if ($reste < $att) continue; // le remboursement correspondant n'est pas encore notifié
            $reste -= $att;
            $newRef = min($brut, (int)$p['rembourse_cents'] + $att);
            $newNetRef = min((int)$p['net_cents'], (int)$p['net_rembourse_cents'] + $att);
            $pdo->prepare("UPDATE fin_paiements SET annul_attendu = 0, annul_com = 1 WHERE id = ?")->execute([$p['id']]);
        } else {
            if ((int)$p['annul_com'] === 1) continue; // inscription déjà annulée par la famille : le prorata ne la concerne plus
            $newRef = $refundedCents >= $capturedCents ? $brut : (int)round($brut * $refundedCents / $capturedCents);
            $newNetRef = null;
        }
        $newRef = min($brut, max((int)$p['rembourse_cents'], $newRef));
        if ($newRef <= (int)$p['rembourse_cents']) continue;
        if ($newNetRef === null) $newNetRef = min((int)$p['net_cents'], (int)round((int)$p['net_cents'] * $newRef / $brut));
        $delta = $newNetRef - (int)$p['net_rembourse_cents'];
        $full = $newRef >= $brut && (int)$p['annul_com'] === 0 && $att === 0;
        $statut = ($full && in_array($p['statut'], ['attente', 'bloque', 'litige'], true)) ? 'rembourse' : $p['statut'];
        $pdo->prepare("UPDATE fin_paiements SET rembourse_cents = ?, net_rembourse_cents = ?, statut = ? WHERE id = ?")->execute([$newRef, $newNetRef, $statut, $p['id']]);
        fin_reversal_demander($pdo, $p, $newNetRef); // part déjà versée à l'organisme : à récupérer par reversement Stripe, sinon dette
        // Solde de l'organisme : même si le net a déjà été transféré, le solde devient négatif et sera compensé sur ses prochains séjours
        $pdo->prepare("UPDATE organisateurs SET portefeuille = COALESCE(portefeuille, 0) - ? WHERE id = ?")->execute([$delta / 100, $p['organisateur_id']]);
        fin_audit($pdo, 'remboursement', 'inscription', $p['inscription_id'], ['rembourse_cents' => $newRef, 'net_retire' => $delta, 'deja_verse' => $p['statut'] === 'verse'], 'webhook');
        if ($full) {
            // inscription_id négatif = inscription de l'équipe d'animation (table equipe_inscriptions)
            try { $pdo->prepare(((int)$p['inscription_id'] < 0 ? "UPDATE equipe_inscriptions SET statut = 'Annulé' WHERE id = ?" : "UPDATE inscriptions SET statut = 'Annulé' WHERE id = ?"))->execute([abs((int)$p['inscription_id'])]); }
            catch (Throwable $e) { error_log('fin_apply_refund (statut inscription): ' . $e->getMessage()); }
        }
    }
}

/** Litige (chargeback) ouvert : les fonds en attente sont gelés ; gagné : dégelés ; perdu : traité comme un remboursement total. */
function fin_dispute(PDO $pdo, string $paymentIntent, string $phase, ?int $capturedCents = null, int $feeCents = 0): void {
    fin_ensure($pdo);
    if ($phase === 'created') {
        $pdo->prepare("UPDATE fin_paiements SET statut = 'litige' WHERE payment_intent = ? AND statut IN ('attente', 'bloque')")->execute([$paymentIntent]);
        if ($feeCents > 0) fin_record_dispute_fee($pdo, $paymentIntent, $feeCents); // frais de litige Stripe : jamais remboursés, même si le litige est gagné
    } elseif ($phase === 'won') {
        $pdo->prepare("UPDATE fin_paiements SET statut = 'attente' WHERE payment_intent = ? AND statut = 'litige'")->execute([$paymentIntent]);
    } elseif ($phase === 'lost' && $capturedCents) {
        fin_apply_refund($pdo, $paymentIntent, $capturedCents, $capturedCents);
    }
    fin_audit($pdo, 'litige_' . $phase, 'payment_intent', $paymentIntent, [], 'webhook');
}

/** Frais de litige Stripe (15 € en général) : répartis au prorata sur les inscriptions du paiement, à la charge de ColoMap */
function fin_record_dispute_fee(PDO $pdo, string $paymentIntent, int $feeCents): void {
    $st = $pdo->prepare("SELECT id, brut_cents FROM fin_paiements WHERE payment_intent = ? ORDER BY id");
    $st->execute([$paymentIntent]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$rows) return;
    $up = $pdo->prepare("UPDATE fin_paiements SET frais_litige_cents = ? WHERE id = ?");
    foreach (fin_allocate($feeCents, $rows) as $id => $part) $up->execute([$part, $id]);
}

// ---------------------------------------------------------------------------------------------
// Frais Stripe RÉELS (balance_transaction de la charge) et marge ColoMap
// ---------------------------------------------------------------------------------------------
/** Frais Stripe réels d'un PaymentIntent, en centimes d'euro (null si indisponible : sera retenté par le cron / la page Comptabilité) */
function fin_fetch_stripe_fee(string $paymentIntent): ?int {
    $pi = fin_stripe()->paymentIntents->retrieve($paymentIntent, ['expand' => ['latest_charge.balance_transaction']]);
    $ch = $pi->latest_charge ?? null;
    $bt = (is_object($ch) && isset($ch->balance_transaction) && is_object($ch->balance_transaction)) ? $ch->balance_transaction : null;
    if (!$bt || strtolower((string)$bt->currency) !== 'eur') return null;
    return max(0, (int)$bt->fee);
}
/** Relève et enregistre les frais Stripe d'un paiement (répartis au prorata du brut sur ses inscriptions). Idempotent. */
function fin_sync_fees(PDO $pdo, string $paymentIntent): bool {
    fin_ensure($pdo);
    $fee = fin_fetch_stripe_fee($paymentIntent);
    if ($fee === null) return false;
    $st = $pdo->prepare("SELECT id, brut_cents FROM fin_paiements WHERE payment_intent = ? ORDER BY id");
    $st->execute([$paymentIntent]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$rows) return false;
    $up = $pdo->prepare("UPDATE fin_paiements SET frais_stripe_cents = ?, frais_maj = NOW() WHERE id = ?");
    foreach (fin_allocate($fee, $rows) as $id => $part) $up->execute([$part, $id]);
    return true;
}
/** Rattrape les paiements dont les frais ne sont pas encore connus. Retourne [relevés, restants/échecs]. */
function fin_backfill_fees(PDO $pdo, int $limit = 40): array {
    fin_ensure($pdo);
    $ids = $pdo->query("SELECT DISTINCT payment_intent FROM fin_paiements WHERE frais_stripe_cents IS NULL AND payment_intent IS NOT NULL AND payment_intent <> ''
                        ORDER BY created_at DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0; $ko = 0;
    foreach ($ids as $pi) {
        try { if (fin_sync_fees($pdo, (string)$pi)) $ok++; else $ko++; }
        catch (Throwable $e) { $ko++; error_log('fin_backfill_fees: ' . $e->getMessage()); }
    }
    return [$ok, $ko];
}
/** Commission nette des remboursements (la commission est rendue au prorata d'un remboursement), expression SQL pour l'alias $a */
function fin_sql_com_nette(string $a = 'p'): string {
    return "($a.commission_cents - IF($a.annul_com = 1, 0, ROUND($a.commission_cents * $a.rembourse_cents / $a.brut_cents)))";
}
/** Marge nette ColoMap d'une ligne = commission conservée - frais Stripe - frais de litige (null si les frais ne sont pas connus) */
function fin_sql_marge(string $a = 'p'): string {
    return "(" . fin_sql_com_nette($a) . " - $a.frais_stripe_cents - $a.frais_litige_cents)";
}

// ---------------------------------------------------------------------------------------------
// Compte Connect de l'organisme
// ---------------------------------------------------------------------------------------------
function fin_store_account(PDO $pdo, int $orgaId, $a): array {
    fin_ensure($pdo);
    $cap = ($a->capabilities ?? null) ? $a->capabilities->toArray() : [];
    $due = ($a->requirements ?? null) ? ($a->requirements->currently_due ?? []) : [];
    $row = [
        'account_id' => $a->id, 'type' => $a->type ?? null,
        'details_submitted' => !empty($a->details_submitted) ? 1 : 0,
        'charges_enabled' => !empty($a->charges_enabled) ? 1 : 0,
        'payouts_enabled' => !empty($a->payouts_enabled) ? 1 : 0,
        'transfers_actif' => (($cap['transfers'] ?? '') === 'active') ? 1 : 0,
        'exigences' => json_encode(is_array($due) ? $due : (array)$due),
    ];
    $pdo->prepare("INSERT INTO stripe_comptes (organisateur_id, account_id, type, details_submitted, charges_enabled, payouts_enabled, transfers_actif, exigences, maj)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), type = VALUES(type), details_submitted = VALUES(details_submitted),
                   charges_enabled = VALUES(charges_enabled), payouts_enabled = VALUES(payouts_enabled), transfers_actif = VALUES(transfers_actif),
                   exigences = VALUES(exigences), maj = NOW()")
        ->execute([$orgaId, $row['account_id'], $row['type'], $row['details_submitted'], $row['charges_enabled'], $row['payouts_enabled'], $row['transfers_actif'], $row['exigences']]);
    return $row;
}

/**
 * Statuts proposés à l'organisme avant l'inscription Stripe : clé => [libellé, explication, business_type Stripe].
 * Tous les organismes ne sont pas des entreprises : association, particulier, micro-entrepreneur...
 */
function fin_connect_statuts(): array {
    return [
        'particulier' => ['Particulier', 'Vous organisez des séjours à titre personnel, sans structure juridique ni numéro SIRET.', 'individual'],
        'micro' => ['Entrepreneur individuel / micro-entrepreneur', 'Vous exercez en nom propre avec un numéro SIRET (auto-entrepreneur, EI).', 'individual'],
        'association' => ['Association', 'Association loi 1901 ou structure à but non lucratif (numéro RNA ou SIREN).', 'non_profit'],
        'societe' => ['Société', 'SAS, SARL, EURL, SASU, coopérative... (Kbis, numéro SIREN).', 'company'],
    ];
}

/**
 * Crée le compte Connect EXPRESS d'un organisme avec le profil pré-rempli (statut, site, description, activité) : Stripe ne repose pas ces questions.
 * Idempotent (clé d'idempotence par organisme). Retourne l'identifiant du compte.
 */
function fin_create_connect_account(PDO $pdo, array $orga, string $statut, int $userId): string {
    $statuts = fin_connect_statuts();
    if (!isset($statuts[$statut])) throw new Exception('Statut inconnu.');
    $orgaId = (int)$orga['id'];
    $profile = [
        'mcc' => '7032', // 7032 : camps de loisirs et de sport (colonies, séjours)
        'product_description' => "Organisation de séjours, colonies et camps de vacances pour enfants et adolescents, vendus sur la plateforme ColoMap (moncamp.fr).",
        'name' => mb_substr((string)$orga['nom'], 0, 100),
    ];
    if (!empty($orga['web']) && filter_var($orga['web'], FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $orga['web'])) $profile['url'] = $orga['web'];
    if (!empty($orga['email']) && filter_var($orga['email'], FILTER_VALIDATE_EMAIL)) $profile['support_email'] = $orga['email'];
    $params = [
        'type' => 'express', 'country' => 'FR',
        'business_type' => $statuts[$statut][2],
        'capabilities' => ['transfers' => ['requested' => true]],
        'business_profile' => $profile,
        'metadata' => ['organisateur_id' => (string)$orgaId, 'statut' => $statut],
    ];
    if ($statuts[$statut][2] !== 'individual') $params['company'] = ['name' => mb_substr((string)$orga['nom'], 0, 100)];
    if (!empty($orga['email']) && filter_var($orga['email'], FILTER_VALIDATE_EMAIL)) $params['email'] = $orga['email'];
    $a = fin_stripe()->accounts->create($params, ['idempotency_key' => 'colomap-acct-' . $orgaId]);
    if (($a->type ?? '') !== 'express') throw new Exception('Type de compte inattendu (' . ($a->type ?? '?') . ') : Express requis.');
    $pdo->prepare("UPDATE organisateurs SET stripe_account_id = ? WHERE id = ? AND (stripe_account_id IS NULL OR stripe_account_id = '')")->execute([$a->id, $orgaId]);
    fin_store_account($pdo, $orgaId, $a);
    $pdo->prepare("UPDATE stripe_comptes SET statut = ? WHERE organisateur_id = ?")->execute([$statut, $orgaId]);
    fin_audit($pdo, 'compte_connect_cree', 'organisateur', $orgaId, ['account' => $a->id, 'statut' => $statut], 'user:' . $userId);
    return $a->id;
}

/** Interroge Stripe et met à jour l'état local du compte de l'organisme (retourne la ligne stripe_comptes ou null) */
function fin_sync_account(PDO $pdo, int $orgaId): ?array {
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT stripe_account_id FROM organisateurs WHERE id = ?");
    $st->execute([$orgaId]);
    $acct = $st->fetchColumn();
    if (!$acct) return null;
    fin_store_account($pdo, $orgaId, fin_stripe()->accounts->retrieve($acct));
    return fin_account($pdo, $orgaId);
}

function fin_account(PDO $pdo, int $orgaId): ?array {
    fin_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM stripe_comptes WHERE organisateur_id = ?");
    $st->execute([$orgaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Le compte peut-il recevoir des transferts et être versé en banque ? */
function fin_account_ready(?array $acc): bool {
    return $acc && !empty($acc['transfers_actif']) && !empty($acc['payouts_enabled']);
}





/**
 * Solde « historique » (ancien système : crédité en brut sans suivi par inscription) : versé net de commission sur décision d'un administrateur.
 * Retourne l'id du transfert ou null.
 */
function fin_transfer_legacy(PDO $pdo, int $orgaId, string $acteur): ?int {
    fin_ensure($pdo);
    $acc = fin_account($pdo, $orgaId);
    if (!fin_account_ready($acc)) { $acc = fin_sync_account($pdo, $orgaId); if (!fin_account_ready($acc)) return null; }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT portefeuille FROM organisateurs WHERE id = ? FOR UPDATE");
        $st->execute([$orgaId]);
        $pf = (int)round((float)$st->fetchColumn() * 100);
        $st = $pdo->prepare("SELECT COALESCE(SUM(net_cents - net_rembourse_cents), 0) FROM fin_paiements WHERE organisateur_id = ? AND statut IN ('attente','bloque','litige','en_cours')");
        $st->execute([$orgaId]);
        $hist = $pf - (int)$st->fetchColumn();
        $net = $hist - fin_commission_cents($hist, 0); // ancien solde : pourcentage seul (pas de part fixe par paiement)
        if ($hist <= 0 || $net < 50) { $pdo->rollBack(); return null; }
        $pdo->prepare("INSERT INTO fin_transferts (token, organisateur_id, montant_cents, brut_cents, type, statut, stripe_account_id, nb_paiements) VALUES (?, ?, ?, ?, 'historique', 'en_cours', ?, 0)")
            ->execute([bin2hex(random_bytes(30)), $orgaId, $net, $hist, $acc['account_id']]);
        $tid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE organisateurs SET portefeuille = portefeuille - ? WHERE id = ?")->execute([$hist / 100, $orgaId]);
        fin_audit($pdo, 'transfert_historique_prepare', 'transfert', $tid, ['brut' => $hist, 'net' => $net], $acteur);
        $pdo->commit();
        return $tid;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}


/** Jeton opaque (HMAC) d'un organisme pour les URLs : aucun identifiant numérique exposé */
function fin_orga_tok(int $orgaId): string {
    $k = (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '') . '|' . (defined('CRON_SECRET') ? CRON_SECRET : '') . '|colomap-orga';
    return substr(hash_hmac('sha256', 'orga:' . $orgaId, $k), 0, 24);
}
/** Retrouve l'organisme d'un jeton parmi ceux de $userId (ou tous si $userId = null, usage administration). Retourne l'id ou 0. */
function fin_orga_from_tok(PDO $pdo, string $tok, ?int $userId = null): int {
    if (!preg_match('/^[a-f0-9]{24}$/', $tok)) return 0;
    if ($userId === null) $ids = $pdo->query("SELECT id FROM organisateurs")->fetchAll(PDO::FETCH_COLUMN);
    else { $st = $pdo->prepare("SELECT id FROM organisateurs WHERE user_id = ?"); $st->execute([$userId]); $ids = $st->fetchAll(PDO::FETCH_COLUMN); }
    foreach ($ids as $id) if (hash_equals(fin_orga_tok((int)$id), $tok)) return (int)$id;
    return 0;
}

/** Taux de TVA acceptés pour un organisme (clé '' = non applicable) */
function fin_tva_taux_permis(): array { return ['' => 'Non applicable (pas de TVA)', '5.5' => '5,5 %', '10' => '10 %', '20' => '20 %']; }

/** TVA incluse dans un montant TTC (centimes) ; 0 si le taux est nul ou non applicable */
function fin_tva_incluse(int $ttcCents, $taux): int {
    $t = (float)$taux;
    return $t > 0 ? (int)round($ttcCents - $ttcCents / (1 + $t / 100)) : 0;
}

/**
 * Détail du prix payé par la famille pour des inscriptions (ids) : ce que contient le prix, sans rien ajouter au montant.
 * Part de l'organisateur = payé - commission ColoMap - TVA incluse. Null si aucun paiement suivi (anciennes commandes).
 */
function fin_order_breakdown(PDO $pdo, array $inscriptionIds): ?array {
    $inscriptionIds = array_values(array_filter(array_map('intval', $inscriptionIds)));
    if (!$inscriptionIds) return null;
    fin_ensure($pdo);
    $in = implode(',', array_fill(0, count($inscriptionIds), '?'));
    $st = $pdo->prepare("SELECT brut_cents, commission_cents, tva_taux_pct, commission_pct, commission_fixe_cents FROM fin_paiements WHERE inscription_id IN ($in)");
    $st->execute($inscriptionIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;
    $b = ['paye' => 0, 'commission' => 0, 'tva' => 0, 'taux' => null, 'pct' => (float)$rows[0]['commission_pct'], 'fixe' => (int)$rows[0]['commission_fixe_cents']];
    foreach ($rows as $r) {
        $b['paye'] += (int)$r['brut_cents']; $b['commission'] += (int)$r['commission_cents'];
        $b['tva'] += fin_tva_incluse((int)$r['brut_cents'], $r['tva_taux_pct']);
        if ($r['tva_taux_pct'] !== null && (float)$r['tva_taux_pct'] > 0) $b['taux'] = (float)$r['tva_taux_pct'];
    }
    $b['organisateur'] = $b['paye'] - $b['commission'] - $b['tva'];
    return $b;
}

/** Derniers paiements d'un organisme (pour son tableau de bord) */
function fin_recent_payments(PDO $pdo, int $orgaId, int $limit = 15): array {
    $st = $pdo->prepare("SELECT p.created_at, p.brut_cents, p.commission_cents, p.net_cents, p.rembourse_cents, p.net_rembourse_cents, p.statut, p.date_deblocage, c.nom camp
                         FROM fin_paiements p JOIN camps c ON c.id = p.camp_id WHERE p.organisateur_id = ? ORDER BY p.created_at DESC, p.id DESC LIMIT " . (int)$limit);
    $st->execute([$orgaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fin_eur(int $cents): string { return number_format($cents / 100, 2, ',', ' ') . ' €'; }

// ---------------------------------------------------------------------------------------------
// Protection CSRF des formulaires d'administration
// ---------------------------------------------------------------------------------------------
function fin_csrf(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['fin_csrf'])) $_SESSION['fin_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['fin_csrf'];
}
function fin_csrf_ok(): bool {
    return !empty($_SESSION['fin_csrf']) && hash_equals($_SESSION['fin_csrf'], (string)($_POST['csrf'] ?? ''));
}

// ---------------------------------------------------------------------------------------------
// Justificatifs
// ---------------------------------------------------------------------------------------------
/**
 * Justificatif d'un versement, pour son directeur (ou un administrateur) : ['type' => 'connect'|'ancien', 'v' => ligne, 'orga' => ligne, 'lignes' => [...]]
 * Retourne null si le jeton est inconnu ou n'appartient pas à l'utilisateur.
 */
function fin_receipt(PDO $pdo, string $token, int $userId, bool $isAdmin = false): ?array {
    fin_ensure($pdo);
    if (!preg_match('/^[a-f0-9]{60}$/', $token)) return null;
    $st = $pdo->prepare("SELECT t.*, o.nom AS orga_nom, o.adresse_complete, o.code_postal_orga, o.ville_orga, o.email AS orga_email, o.user_id AS owner
                         FROM fin_transferts t JOIN organisateurs o ON o.id = t.organisateur_id WHERE t.token = ?");
    $st->execute([$token]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if ($v) {
        if (!$isAdmin && (int)$v['owner'] !== $userId) return null;
        $st = $pdo->prepare("SELECT p.inscription_id, p.brut_cents, p.commission_cents, p.net_cents, p.rembourse_cents, p.net_rembourse_cents, p.commission_pct, c.nom AS camp_nom, c.date_debut, l.montant_cents AS montant_ligne
                             FROM fin_transfert_lignes l JOIN fin_paiements p ON p.id = l.paiement_id JOIN camps c ON c.id = p.camp_id WHERE l.transfert_id = ? ORDER BY c.date_debut, p.inscription_id");
        $st->execute([$v['id']]);
        $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$lignes) { // transfert d'avant le calendrier hebdomadaire : tout le net était versé
            $st = $pdo->prepare("SELECT p.inscription_id, p.brut_cents, p.commission_cents, p.net_cents, p.rembourse_cents, p.net_rembourse_cents, p.commission_pct, c.nom AS camp_nom, c.date_debut
                                 FROM fin_paiements p JOIN camps c ON c.id = p.camp_id WHERE p.transfert_id = ? ORDER BY c.date_debut, p.inscription_id");
            $st->execute([$v['id']]);
            $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['type' => 'connect', 'v' => $v, 'orga' => $v, 'lignes' => $lignes];
    }
    try {
        $st = $pdo->prepare("SELECT v.*, o.nom AS orga_nom, o.adresse_complete, o.code_postal_orga, o.ville_orga, o.user_id AS owner
                             FROM virements v JOIN organisateurs o ON o.id = v.organisateur_id WHERE v.token = ?");
        $st->execute([$token]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if (!$v || (!$isAdmin && (int)$v['owner'] !== $userId && (int)$v['user_id'] !== $userId)) return null;
    return ['type' => 'ancien', 'v' => $v, 'orga' => $v, 'lignes' => []];
}

/** Texte compatible FPDF (cp1252) */
function fin_pdf_txt($s): string {
    $r = @iconv('UTF-8', 'windows-1252//TRANSLIT', (string)$s);
    return $r === false ? (string)$s : $r;
}

require_once __DIR__ . '/versements_lib.php';
