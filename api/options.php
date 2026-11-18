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

/** Moyens de paiement : code => [libellé, précision, logo SVG]. La carte est gérée par le site, les autres par l'organisme. */
function options_paiements(): array {
    $f = 'font-family="Montserrat,Arial,sans-serif"';
    $box = '<rect width="48" height="30" rx="5" fill="#fff" stroke="#d3dbe0"/>';
    return [
        'carte' => ['Carte bancaire', 'Paiement en ligne sécurisé', '<svg viewBox="0 0 48 30" aria-hidden="true">' . $box . '<rect x="6" y="7" width="36" height="16" rx="3" fill="#2f3d45"/><rect x="6" y="11" width="36" height="3" fill="#8fa2b0"/><rect x="9" y="18" width="10" height="2.5" rx="1" fill="#fff"/></svg>'],
        'cheque' => ['Chèque', "À l'ordre de l'organisme", '<svg viewBox="0 0 48 30" aria-hidden="true">' . $box . '<rect x="6" y="6" width="36" height="18" rx="2" fill="#f8f5ea" stroke="#c9bf94"/><path d="M10 13h18M10 17h12" stroke="#9a8f5c" stroke-width="1.4" stroke-linecap="round"/><path d="M30 20c2-5 4-5 6 0" fill="none" stroke="#3d6a8e" stroke-width="1.4" stroke-linecap="round"/></svg>'],
        'virement' => ['Virement', 'Sur le compte de l\'organisme', '<svg viewBox="0 0 48 30" aria-hidden="true">' . $box . '<path d="M11 13h26M11 13l5-4M11 13l5 4M37 19H11M37 19l-5-4M37 19l-5 4" fill="none" stroke="#3d6a8e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'],
        'especes' => ['Espèces', 'Remises sur place ou à l\'accueil', '<svg viewBox="0 0 48 30" aria-hidden="true">' . $box . '<rect x="7" y="8" width="34" height="14" rx="2" fill="#e4ece8" stroke="#8fae9f"/><circle cx="24" cy="15" r="4.2" fill="none" stroke="#5f8a74" stroke-width="1.6"/><circle cx="11.5" cy="15" r="1.2" fill="#5f8a74"/><circle cx="36.5" cy="15" r="1.2" fill="#5f8a74"/></svg>'],
        'ancv' => ['Chèques-Vacances ANCV', 'Papier ou e-Chèques-Vacances', '<svg viewBox="0 0 48 30" aria-hidden="true"><rect width="48" height="30" rx="5" fill="#fff" stroke="#d3dbe0"/><path d="M8 9h10v3H12v2h5v3h-5v2h6v3H8z" fill="#e2001a" transform="translate(0 -3)"/><text x="22" y="14" ' . $f . ' font-size="7.5" font-weight="800" fill="#0a3a82">ANCV</text><text x="22" y="22" ' . $f . ' font-size="4.6" font-weight="700" fill="#e2001a">CHÈQUE-VACANCES</text></svg>'],
        'caf' => ['Aides CAF', 'Aide aux vacances, bons CAF', '<svg viewBox="0 0 48 30" aria-hidden="true"><rect width="48" height="30" rx="5" fill="#fff" stroke="#d3dbe0"/><circle cx="14" cy="15" r="7" fill="#00a0df"/><circle cx="14" cy="15" r="3" fill="#fff"/><text x="25" y="19" ' . $f . ' font-size="9.5" font-weight="800" fill="#0a5ea8">CAF</text></svg>'],
        'msa' => ['Aides MSA', 'Mutualité sociale agricole', '<svg viewBox="0 0 48 30" aria-hidden="true"><rect width="48" height="30" rx="5" fill="#fff" stroke="#d3dbe0"/><path d="M8 20c4-9 8-9 12 0M14 20c4-9 8-9 12 0" fill="none" stroke="#5a9a2f" stroke-width="2.2" stroke-linecap="round"/><text x="28" y="19" ' . $f . ' font-size="9" font-weight="800" fill="#3c7a1c">MSA</text></svg>'],
        'ce' => ["Comité d'entreprise / CSE", 'Participation employeur', '<svg viewBox="0 0 48 30" aria-hidden="true">' . $box . '<rect x="12" y="9" width="24" height="14" rx="2" fill="#eef2f4" stroke="#a9b8c6"/><path d="M17 9V7h14v2" fill="none" stroke="#a9b8c6" stroke-width="1.6"/><path d="M12 15h24" stroke="#a9b8c6" stroke-width="1.4"/></svg>'],
        'pass' => ['Pass / coupons sport & loisirs', 'Dispositifs locaux et municipaux', '<svg viewBox="0 0 48 30" aria-hidden="true">' . $box . '<path d="M8 11h32v3a2.5 2.5 0 000 5v3H8v-3a2.5 2.5 0 000-5z" fill="#f3e9d2" stroke="#c9b27a"/><path d="M30 11v11" stroke="#c9b27a" stroke-dasharray="1.6 1.6"/></svg>'],
    ];
}

function options_paiements_valides($v): array {
    $ok = array_keys(options_paiements());
    $list = is_array($v) ? $v : explode(',', (string)$v);
    return array_values(array_unique(array_filter(array_map('trim', $list), fn($c) => in_array($c, $ok, true))));
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
    $pdo->prepare("UPDATE camps SET reserve_sexe = ?, champs_requis = ?, champs_demandes = ?, moyens_paiement = ?, hebergement = ?, transport = ?, encadrement = ?, inclus = ?, a_prevoir = ?, instructions_paiement = ? WHERE id = ?")
        ->execute([
            $sexe,
            json_encode($req = options_criteres_valides($in['champs_requis'] ?? [])),
            json_encode(array_values(array_unique(array_merge($req, options_criteres_valides($in['champs_demandes'] ?? []))))),
            implode(',', options_paiements_valides($in['moyens_paiement'] ?? [])),
            isset(HEBERGEMENTS[$heb]) && $heb !== '' ? $heb : null,
            $txt('transport', 255), $txt('encadrement', 255), $txt('inclus', 2000), $txt('a_prevoir', 2000), $txt('instructions_paiement', 1000),
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
