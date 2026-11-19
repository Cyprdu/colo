<?php
// api/options.php : options personnalisables d'un séjour (créées à la première utilisation, idempotent).
//
//  - reserve_sexe   : NULL (mixte) | 'Homme' (garçons) | 'Femme' (filles)
//  - champs_requis  : critères OBLIGATOIRES (inscription impossible sans) ; champs_demandes : critères DEMANDÉS aux parents (obligatoires inclus, JSON)
//  - moyens_paiement: moyens acceptés par l'organisme (liste de codes de options_paiements(), séparés par des virgules)
//  - hebergement, transport, encadrement, inclus, a_prevoir, instructions_paiement : informations pratiques
// Les quotas filles / garçons existent déjà (quota_fille / quota_garcon) : 0 ou vide = pas de quota.

const SEXE_GARCON = 'Homme';
const SEXE_FILLE = 'Femme';

/** Critères de la fiche enfant : clé => [libellé, colonnes de la table enfants, type] */
const ENFANT_CRITERES = [
    'civilite'        => ['Civilité', ['civilite'], 'text'],
    'email'           => ["Email de l'enfant", ['email_enfant'], 'text'],
    'mobile'          => ["Mobile de l'enfant", ['tel_mobile_enfant'], 'text'],
    'adresse'         => ['Adresse complète', ['adresse', 'code_postal', 'ville'], 'text'],
    'taille_poids'    => ['Taille et poids', ['taille', 'poids'], 'text'],
    'medecin'         => ['Médecin traitant', ['medecin_nom', 'medecin_tel'], 'text'],
    'resp2'           => ['Second responsable légal', ['resp2_nom', 'resp2_tel'], 'text'],
    'accord_parental' => ['Accord parental', ['accord_parental'], 'bool'],
    'droit_image'     => ["Droit à l'image", ['droit_image'], 'bool'],
    'carnet_sante'    => ['Carnet de santé', ['carnet_sante_token'], 'text'],
    'fiche_sanitaire' => ['Fiche sanitaire de liaison', ['fiche_sanitaire_token'], 'text'],
];

const HEBERGEMENTS = ['' => 'Non précisé', 'tente' => 'Camping sous tente', 'dur' => 'Centre en dur', 'gite' => 'Gîte / auberge', 'famille' => "Famille d'accueil", 'sans' => 'Sans hébergement (journée)', 'autre' => 'Autre'];

function options_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM camps")->fetchAll(PDO::FETCH_COLUMN);
        $add = [
            'reserve_sexe' => "VARCHAR(10) NULL DEFAULT NULL",
            'champs_requis' => "TEXT NULL",
            'champs_demandes' => "TEXT NULL",
            'moyens_paiement' => "VARCHAR(255) NULL",
            'hebergement' => "VARCHAR(20) NULL",
            'transport' => "VARCHAR(255) NULL",
            'encadrement' => "VARCHAR(255) NULL",
            'inclus' => "TEXT NULL",
            'a_prevoir' => "TEXT NULL",
            'instructions_paiement' => "TEXT NULL",
            'paiement_autre' => "VARCHAR(80) NULL", 'paiement_autre_logo' => "VARCHAR(120) NULL",   // moyen « Autre » : libellé et logo choisis par l'organisme
            // colonnes que le formulaire de séjour écrit : créées si une ancienne base ne les a pas
            // description / lieu propres à une session (0 = hérite de la session principale du séjour)
            'heritage' => "TEXT NULL",   // sections que la session reprend de la session principale (JSON), null = hérite seulement description / lieu non personnalisés
            'perso_description' => "TINYINT(1) NOT NULL DEFAULT 0", 'perso_lieu' => "TINYINT(1) NOT NULL DEFAULT 0",
            'itinerant' => "TINYINT(1) NOT NULL DEFAULT 0", 'theme' => "VARCHAR(100) NULL", 'type' => "VARCHAR(500) NULL",
            'pays' => "VARCHAR(80) NULL", 'video_url' => "VARCHAR(500) NULL",
        ];
        foreach ($add as $c => $def) if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE camps ADD COLUMN $c $def");
    } catch (Throwable $e) { error_log('options_ensure: ' . $e->getMessage()); }
    $done = true;
}

/** Logo d'un moyen de paiement (assets/img/paiement, origines et licences dans SOURCES.md), cadre blanc 48 × 30 */
function options_logo(string $f, string $alt = ''): string {
    return '<img src="/assets/img/paiement/' . $f . '.svg" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" width="48" height="30" loading="lazy" decoding="async"'
         . ' style="display:block;box-sizing:border-box;width:100%;max-width:48px;height:auto;aspect-ratio:8/5;padding:4px 5px;object-fit:contain;background:#fff;border:1px solid #d3dbe0;border-radius:5px">';
}

/** Logos de la carte bancaire (paiement en ligne par Stripe) */
function options_logos_carte(): array {
    return array_map(fn($f, $a) => options_logo($f, $a), ['cb', 'visa', 'mastercard', 'apple-pay', 'google-pay'], ['CB', 'Visa', 'Mastercard', 'Apple Pay', 'Google Pay']);
}

/** Moyens de paiement : code => [libellé, précision, logo HTML]. La carte est gérée par le site, les autres par l'organisme. */
function options_paiements(): array {
    $l = 'options_logo';
    return [
        'carte' => ['Carte bancaire', 'Paiement en ligne sécurisé', $l('cb', 'CB')],
        'cheque' => ['Chèque', "À l'ordre de l'organisme", $l('cheque')],
        'virement' => ['Virement', 'Sur le compte de l\'organisme', $l('virement')],
        'especes' => ['Espèces', 'Remises sur place ou à l\'accueil', $l('especes')],
        'ancv' => ['Chèques-Vacances ANCV', 'Papier ou e-Chèques-Vacances', $l('cheque-vacances')],
        'coupon_sport' => ['Coupons Sport ANCV', 'Papier ou e-Coupon Sport', $l('coupon-sport')],
        'caf' => ['Aides CAF', 'Aide aux vacances, bons CAF', $l('aides-caf')],
        'msa' => ['Aides MSA', 'Mutualité sociale agricole', $l('aides-msa')],
        'ce' => ["Comité d'entreprise / CSE", 'Participation employeur', $l('cse')],
        'autre' => ['Autre', 'Un moyen propre à votre organisme', $l('autre')],
        'pass' => ['Pass / coupons sport & loisirs', 'Dispositifs locaux et municipaux', $l('pass')],
    ];
}

function options_paiements_valides($v): array {
    $ok = array_keys(options_paiements());
    $list = is_array($v) ? $v : explode(',', (string)$v);
    return array_values(array_unique(array_filter(array_map('trim', $list), fn($c) => in_array($c, $ok, true))));
}

/** Chemin d'un logo « Autre » téléversé (api/paiement_logo.php), ou null */
function options_logo_valide($p): ?string {
    $p = (string)$p;
    return preg_match('#^uploads/paiements/pay_[a-f0-9]{16}\.(png|jpg|webp)$#', $p) && is_file(__DIR__ . '/../' . $p) ? $p : null;
}

/** [libellé, logo HTML] d'un moyen de paiement pour un séjour (« Autre » : libellé et logo de l'organisme) */
function options_moyen_affiche(array $camp, string $code): array {
    $m = options_paiements()[$code] ?? [$code, '', ''];
    if ($code !== 'autre') return [$m[0], $m[2]];
    $logo = options_logo_valide($camp['paiement_autre_logo'] ?? '');
    return [trim((string)($camp['paiement_autre'] ?? '')) ?: $m[0], $logo ? '<img src="' . htmlspecialchars($logo) . '" alt="" style="width:100%;height:30px;object-fit:contain">' : $m[2]];
}

function options_criteres_valides($v): array {
    if (is_string($v)) { $d = json_decode($v, true); $v = is_array($d) ? $d : explode(',', $v); }
    return array_values(array_unique(array_filter((array)$v, fn($k) => is_string($k) && isset(ENFANT_CRITERES[$k]))));
}

/** Enregistre les options d'un séjour à partir de $_POST (la propriété du séjour est vérifiée par l'appelant). */
function options_enregistrer(PDO $pdo, int $campId, array $in): void {
    options_ensure($pdo);
    $sexe = $in['reserve_sexe'] ?? '';
    $sexe = in_array($sexe, [SEXE_GARCON, SEXE_FILLE], true) ? $sexe : null;
    $txt = function ($k, $n) use ($in) { $v = trim((string)($in[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };
    $heb = (string)($in['hebergement'] ?? '');
    $autre = in_array('autre', options_paiements_valides($in['moyens_paiement'] ?? []), true);
    $pdo->prepare("UPDATE camps SET reserve_sexe = ?, champs_requis = ?, champs_demandes = ?, moyens_paiement = ?, hebergement = ?, transport = ?, encadrement = ?, inclus = ?, a_prevoir = ?, instructions_paiement = ?, paiement_autre = ?, paiement_autre_logo = ? WHERE id = ?")
        ->execute([
            $sexe,
            json_encode($req = options_criteres_valides($in['champs_requis'] ?? [])),
            json_encode(array_values(array_unique(array_merge($req, options_criteres_valides($in['champs_demandes'] ?? []))))),
            implode(',', options_paiements_valides($in['moyens_paiement'] ?? [])),
            isset(HEBERGEMENTS[$heb]) && $heb !== '' ? $heb : null,
            $txt('transport', 255), $txt('encadrement', 255), $txt('inclus', 2000), $txt('a_prevoir', 2000), $txt('instructions_paiement', 1000),
            $autre ? $txt('paiement_autre', 80) : null, $autre ? options_logo_valide($in['paiement_autre_logo'] ?? '') : null,
            $campId,
        ]);
}

/** Libellés des critères de la fiche enfant que ce séjour impose et que l'enfant n'a pas remplis. */
function options_enfant_manquants(array $camp, array $enfant): array {
    $req = options_criteres_valides($camp['champs_requis'] ?? '[]');
    $out = [];
    foreach ($req as $k) {
        [$label, $cols, $type] = ENFANT_CRITERES[$k];
        foreach ($cols as $col) {
            $v = $enfant[$col] ?? null;
            $vide = $type === 'bool' ? ((int)$v !== 1) : (trim((string)$v) === '' || trim((string)$v) === '0');
            if ($vide) { $out[] = $label; break; }
        }
    }
    return $out;
}

/** Libellés des critères DEMANDÉS mais non obligatoires que l'enfant n'a pas remplis (simple rappel, jamais bloquant). */
function options_enfant_souhaites(array $camp, array $enfant): array {
    $req = options_criteres_valides($camp['champs_requis'] ?? '[]');
    $out = [];
    foreach (options_criteres_valides($camp['champs_demandes'] ?? '[]') as $k) {
        if (in_array($k, $req, true)) continue;
        [$label, $cols, $type] = ENFANT_CRITERES[$k];
        foreach ($cols as $col) {
            $v = $enfant[$col] ?? null;
            if ($type === 'bool' ? ((int)$v !== 1) : (trim((string)$v) === '' || trim((string)$v) === '0')) { $out[] = $label; break; }
        }
    }
    return $out;
}

/**
 * Âge de l'enfant incompatible avec la tranche d'âge de CETTE session (chaque session du séjour a la sienne) ?
 * L'âge est calculé au premier jour de la session. Retourne un message, ou null si tout va bien.
 */
function options_age_probleme(array $camp, array $enfant): ?string {
    if (empty($enfant['date_naissance']) || !isset($camp['age_min'], $camp['age_max']) || ($camp['age_mode'] ?? '') === 'tranche') return null;
    try {
        $ref = new DateTime(!empty($camp['date_debut']) ? $camp['date_debut'] : 'now');
        $age = (new DateTime($enfant['date_naissance']))->diff($ref)->y;
    } catch (Throwable $e) { return null; }
    if ($age >= (int)$camp['age_min'] && $age <= (int)$camp['age_max']) return null;
    return 'Cette session accueille les ' . (int)$camp['age_min'] . ' à ' . (int)$camp['age_max'] . ' ans (' . $enfant['prenom'] . ' aura ' . $age . ' an' . ($age > 1 ? 's' : '') . ' au départ).';
}

/** Le séjour est-il ouvert à ce sexe ? (réservé aux filles / aux garçons) */
function options_sexe_admis(array $camp, ?string $sexe): bool {
    $r = $camp['reserve_sexe'] ?? null;
    return !$r || $r === $sexe;
}

/** Condition SQL (alias i = inscriptions, c = camps) : l'inscription occupe une place (pas annulée, payée ou encore dans son délai de paiement) */
function options_actif_sql(): string {
    return "COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule') AND (i.statut_paiement <> 'EN_ATTENTE' OR i.date_inscription > (NOW() - INTERVAL IF(c.attente_paiement = 0, 30, GREATEST(1, COALESCE(c.attente_jours, 7)) * 1440) MINUTE))";
}

/**
 * Condition SQL (alias c = camps) : le séjour accepte un enfant de ce sexe ET son quota n'est pas plein.
 * Utilise le paramètre nommé :sx_<suffixe>, à lier par l'appelant (valeur : 'Homme' ou 'Femme').
 * Les colonnes de options_ensure() et attente_ensure() doivent exister.
 */
function options_sexe_sql(string $suffixe = 'x'): string {
    $p = ':sx_' . $suffixe;
    $actif = options_actif_sql();
    return "(c.reserve_sexe IS NULL OR c.reserve_sexe = $p)
        AND (COALESCE(IF($p = 'Femme', c.quota_fille, c.quota_garcon), 0) <= 0
             OR (SELECT COUNT(*) FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id WHERE i.camp_id = c.id AND e.sexe = $p AND $actif) < IF($p = 'Femme', c.quota_fille, c.quota_garcon))";
}

function options_sexe_param($v): ?string {
    $v = is_string($v) ? trim($v) : '';
    $map = ['homme' => SEXE_GARCON, 'garcon' => SEXE_GARCON, 'garçon' => SEXE_GARCON, 'femme' => SEXE_FILLE, 'fille' => SEXE_FILLE];
    return $map[mb_strtolower($v)] ?? null;
}
