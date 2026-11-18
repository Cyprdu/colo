<?php
// api/camp_groupe.php : un séjour = un groupe de sessions (une ligne `camps` par session, regroupées par groupe_id).
//
// Seuls le NOM, l'ORGANISME et les CATÉGORIES sont communs au séjour. Tout le reste est propre à chaque session
// (dates, âges, lieu, description, tarifs, places, paiements, questions, animateurs, visuels…), avec un HÉRITAGE
// optionnel par section : une session peut « reprendre » une section de la session principale (colonne `heritage`).
// Le navigateur envoie les valeurs EFFECTIVES de chaque session (déjà recopiées depuis la principale pour les sections
// héritées) ; le serveur les valide, puis écrit toutes les sessions dans UNE transaction.
require_once __DIR__ . '/camp_save.php';

/** Sections d'une session qui peuvent être reprises de la session principale */
const CAMP_HERITAGE_CLES = ['description', 'lieu', 'public', 'inscription', 'paiement', 'fiche', 'questions', 'anim', 'infos', 'visuels'];

/** Sections héritées par une ligne (la session principale n'hérite de rien). Ancien format (colonne vide) : description et lieu non personnalisés. */
function camp_heritage_lire(array $row, bool $principal): array {
    if ($principal) return [];
    if (isset($row['heritage']) && $row['heritage'] !== null && $row['heritage'] !== '') {
        $d = json_decode((string)$row['heritage'], true);
        return is_array($d) ? array_values(array_intersect(array_keys(array_filter($d)), CAMP_HERITAGE_CLES)) : [];
    }
    $h = [];
    if (empty($row['perso_description'])) $h[] = 'description';
    if (empty($row['perso_lieu'])) $h[] = 'lieu';
    return $h;
}

/** Sessions du séjour de $row (même propriétaire), par date. Chaque ligne reçoit nb_inscrits (inscriptions non annulées). */
function camp_groupe_lignes(PDO $pdo, int $uid, array $row): array {
    $g = (int)($row['groupe_id'] ?? 0);
    $st = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM inscriptions i WHERE i.camp_id = c.id AND COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule')) AS nb_inscrits
                         FROM camps c JOIN organisateurs o ON o.id = c.organisateur_id
                         WHERE (" . ($g ? 'c.groupe_id = ? OR ' : '') . "c.id = ?) AND COALESCE(c.supprime, 0) = 0 AND o.user_id = ? ORDER BY c.date_debut, c.id");
    $st->execute($g ? [$g, $row['id'], $uid] : [$row['id'], $uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** La session principale d'un groupe : celle dont l'identifiant est le groupe_id, sinon la plus ancienne */
function camp_principal(array $rows): ?array {
    $best = null;
    foreach ($rows as $r) {
        if ((int)$r['id'] === (int)($r['groupe_id'] ?? 0)) return $r;
        if (!$best || (int)$r['id'] < (int)$best['id']) $best = $r;
    }
    return $best;
}

/** Une ligne par session pour la barre d'onglets du formulaire */
function camp_sessions_meta(array $rows, ?array $principal): array {
    $out = [];
    foreach ($rows as $r) {
        $isP = $principal && (int)$r['id'] === (int)$principal['id'];
        $out[] = ['token' => $r['token'], 'date_debut' => $r['date_debut'], 'date_fin' => $r['date_fin'], 'inscrits' => (int)$r['nb_inscrits'],
                  'principal' => $isP, 'heritage' => camp_heritage_lire($r, $isP), 'prix' => (float)$r['prix']];
    }
    return $out;
}

/** Configuration complète d'une session, au format du formulaire (noms de champs, avec [] pour les listes). */
function camp_snapshot(PDO $pdo, array $c): array {
    $id = (int)$c['id'];
    $online = !empty($c['inscription_en_ligne']);
    $b = fn($k) => !empty($c[$k]);
    $f = [
        'age_min' => (string)($c['age_min'] ?? ''), 'age_max' => (string)($c['age_max'] ?? ''), 'age_mode' => age_mode($c), 'tranches[]' => tranches_valides($c['tranches'] ?? ''),
        'reserve_sexe' => (string)($c['reserve_sexe'] ?? ''),
        'quotas_sexe_actifs' => (int)($c['quota_fille'] ?? 0) > 0 || (int)($c['quota_garcon'] ?? 0) > 0,
        'quota_fille' => (string)($c['quota_fille'] ?? ''), 'quota_garcon' => (string)($c['quota_garcon'] ?? ''),
        'adresse' => (string)$c['adresse'], 'ville' => (string)$c['ville'], 'cp' => (string)($c['code_postal'] ?? ''), 'pays' => (string)($c['pays'] ?: 'France'), 'itinerant' => $b('itinerant'),
        'date_debut' => (string)$c['date_debut'], 'date_fin' => (string)$c['date_fin'], 'date_limite_inscription' => (string)($c['date_limite_inscription'] ?? ''),
        'inscription_en_ligne' => $online, 'prix_simple' => $online ? '' : (string)($c['prix'] ?? ''),
        'lien_externe' => (string)($c['lien_externe'] ?? ''), 'adresse_retour_dossier' => (string)($c['adresse_retour_dossier'] ?? ''),
        'quota_global' => (string)($c['quota_global'] ?? ''), 'remise_fratrie' => (string)($c['remise_fratrie'] ?? 0),
        'attente_paiement' => !isset($c['attente_paiement']) || !empty($c['attente_paiement']), 'attente_jours' => (string)($c['attente_jours'] ?? ATTENTE_DEFAUT_JOURS),
        'moyens_paiement[]' => options_paiements_valides($c['moyens_paiement'] ?? ''), 'instructions_paiement' => (string)($c['instructions_paiement'] ?? ''),
        'champs_requis[]' => options_criteres_valides($c['champs_requis'] ?? '[]'), 'champs_demandes[]' => options_criteres_valides($c['champs_demandes'] ?? '[]'),
        'gestion_animateur' => $b('gestion_animateur'), 'quota_max_anim' => (string)($c['quota_max_anim'] ?? ''), 'quota_anim_fille' => (string)($c['quota_anim_fille'] ?? ''), 'quota_anim_garcon' => (string)($c['quota_anim_garcon'] ?? ''),
        'anim_plus_18' => $b('anim_plus_18'), 'bafa_obligatoire' => $b('bafa_obligatoire'), 'remuneration_anim' => $b('remuneration_anim'), 'anim_doit_payer' => $b('anim_doit_payer'), 'prix_anim' => (string)($c['prix_anim'] ?? ''),
        'hebergement' => (string)($c['hebergement'] ?? ''), 'transport' => (string)($c['transport'] ?? ''), 'encadrement' => (string)($c['encadrement'] ?? ''), 'inclus' => (string)($c['inclus'] ?? ''), 'a_prevoir' => (string)($c['a_prevoir'] ?? ''),
        'video_url' => (string)($c['video_url'] ?? ''), 'prive' => $b('prive'), 'description' => (string)($c['description'] ?? ''),
    ];
    mc_ensure($pdo);
    $tags = mc_lire($pdo, 'camp', [$id])[$id] ?? [];
    if (!$tags) $tags = array_values(array_filter(array_map('trim', explode(',', (string)($c['type'] ?? '')))));
    $tar = $pdo->prepare("SELECT tarif_id FROM camps_tarifs WHERE camp_id = ?");
    $tar->execute([$id]);
    $anim = null;
    try {
        require_once __DIR__ . '/animateurs.php'; require_once __DIR__ . '/equipe.php';
        anim_ensure($pdo);
        $anim = ['recrute' => !empty($c['recrute_animateurs']), 'remuneration' => ($c['anim_remuneration'] ?? null) !== null ? (float)$c['anim_remuneration'] : null, 'equipe' => equipe_config($pdo, $c), 'campToken' => $c['token']];
    } catch (Throwable $e) { error_log('camp_snapshot anim: ' . $e->getMessage()); }
    return [
        'f' => $f, 'tags' => $tags, 'tarifs' => array_map('intval', $tar->fetchAll(PDO::FETCH_COLUMN)),
        'champs' => array_map(fn($x) => ['id' => $x['id'], 'libelle' => $x['libelle'], 'type' => $x['type'], 'options' => (string)$x['options'], 'obligatoire' => $x['obligatoire']], champs_du_camp($pdo, $id)),
        'etapes' => array_map(fn($e) => ['jour' => (int)$e['jour'], 'lieu' => $e['lieu'], 'description' => (string)$e['description']], etapes_du_camp($pdo, $id)),
        'anim' => $anim, 'image_url' => (string)($c['image_url'] ?? ''),
    ];
}

/** Données d'une session au format « POST du formulaire » attendu par camp_lire_formulaire() & co */
function camp_in_depuis_session(array $common, array $s): array {
    $in = $common;
    foreach ((array)($s['f'] ?? []) as $k => $v) {
        $k = preg_replace('/\[\]$/', '', (string)$k);
        $in[$k] = is_bool($v) ? ($v ? '1' : '') : $v;
    }
    $tags = array_values(array_filter(array_map(fn($t) => trim((string)$t), (array)($s['tags'] ?? []))));
    $in['type'] = implode(', ', $tags);
    $in['motscles'] = json_encode($tags, JSON_UNESCAPED_UNICODE);
    $in['tarifs'] = json_encode(array_values(array_map('intval', (array)($s['tarifs'] ?? []))));
    $in['etapes'] = json_encode(array_values((array)($s['etapes'] ?? [])), JSON_UNESCAPED_UNICODE);
    return $in;
}

function camp_libelle_session(array $data): string {
    $m = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $d = DateTime::createFromFormat('Y-m-d', (string)($data['date_debut'] ?? ''));
    return $d ? 'du ' . $d->format('j') . ' ' . $m[(int)$d->format('n') - 1] . ' ' . $d->format('Y') : '(dates à renseigner)';
}

/**
 * Crée ou modifie un séjour et TOUTES ses sessions, dans une seule transaction.
 *  - $openToken : jeton d'une session du séjour à modifier ('' pour une création).
 *  - $p = ['common' => ['nom', 'organisateur_id', 'categories'], 'sessions' => [...], 'confirm_inscrits' => bool].
 * Retourne ['principal' => jeton, 'tokens' => [clé => jeton], 'motscles_refuses' => [...], 'ids' => [...]].
 * @throws CampException message destiné au directeur ($e->confirm = true : à confirmer puis renvoyer avec confirm_inscrits)
 */
function camp_sauver_groupe(PDO $pdo, int $uid, string $openToken, array $p): array {
    options_ensure($pdo); attente_ensure($pdo); etapes_ensure($pdo); champs_ensure($pdo);
    require_once __DIR__ . '/geocode.php'; geo_ensure($pdo);
    require_once __DIR__ . '/animateurs.php'; require_once __DIR__ . '/equipe.php'; anim_ensure($pdo); equipe_ensure($pdo); regles_ensure($pdo);

    $common = (array)($p['common'] ?? []);
    $sessions = array_values((array)($p['sessions'] ?? []));
    if (!$sessions) throw new CampException('Le séjour doit avoir au moins une session.');
    if (count($sessions) > 40) throw new CampException('40 sessions au maximum par séjour.');

    // Séjour existant : propriété vérifiée, sessions actuelles chargées
    $rows = []; $byToken = []; $principalRow = null; $groupe = 0;
    if ($openToken !== '') {
        $st = $pdo->prepare("SELECT c.* FROM camps c JOIN organisateurs o ON c.organisateur_id = o.id WHERE c.token = ? AND o.user_id = ? AND COALESCE(c.supprime, 0) = 0");
        $st->execute([$openToken, $uid]);
        $open = $st->fetch(PDO::FETCH_ASSOC);
        if (!$open) throw new CampException("Ce séjour n'existe pas ou ne vous appartient pas.");
        $rows = camp_groupe_lignes($pdo, $uid, $open);
        foreach ($rows as $r) $byToken[$r['token']] = $r;
        $principalRow = camp_principal($rows);
        $groupe = (int)($principalRow['groupe_id'] ?: $principalRow['id']);
    }

    // 1. Validation de chaque session (rien n'est écrit avant que tout soit valide)
    $prep = []; $keys = []; $principalKey = null; $refus = [];
    foreach ($sessions as $n => $s) {
        $key = (string)($s['key'] ?? $n);
        if (isset($keys[$key])) throw new CampException('Session en double.');
        $keys[$key] = true;
        $tok = (string)($s['token'] ?? '');
        $row = $tok !== '' ? ($byToken[$tok] ?? null) : null;
        if ($tok !== '' && !$row) throw new CampException("Une session ne fait pas partie de ce séjour. Rechargez la page.");
        if (!empty($s['principal'])) $principalKey = $key;
        $in = camp_in_depuis_session($common, $s);
        [$data, $tarifIds, $err, $classement] = camp_lire_formulaire($pdo, $uid, $in);
        if ($err) throw new CampException(count($sessions) > 1 ? 'Session ' . camp_libelle_session(['date_debut' => $in['date_debut'] ?? '']) . ' : ' . $err : $err);
        try { camp_valider_champs($s['champs'] ?? []); } catch (InvalidArgumentException $e) { throw new CampException('Session ' . camp_libelle_session($data) . ' : ' . $e->getMessage()); }
        $anim = is_array($s['anim'] ?? null) ? equipe_preparer($s['anim']) : null;
        if (is_string($anim)) throw new CampException('Animateurs, session ' . camp_libelle_session($data) . ' : ' . $anim);
        $prep[$key] = ['s' => $s, 'in' => $in, 'data' => $data, 'tarifs' => $tarifIds, 'classement' => $classement, 'row' => $row, 'key' => $key, 'anim' => $anim];
        if (!empty($classement['motscles']['refus'])) $refus = array_merge($refus, $classement['motscles']['refus']);
    }
    if ($principalKey === null) $principalKey = array_key_first($prep);

    // 2. Sessions qui disparaissent / dont les dates ou le prix changent alors que des familles sont inscrites
    $gardes = [];
    foreach ($prep as $x) if ($x['row']) $gardes[(int)$x['row']['id']] = true;
    $avert = [];
    foreach ($rows as $r) {
        if (isset($gardes[(int)$r['id']])) continue;
        if ((int)$r['nb_inscrits'] > 0) throw new CampException('La session ' . camp_libelle_session($r) . " a déjà des inscrits : elle ne peut pas être supprimée. Annulez d'abord leurs inscriptions.");
    }
    foreach ($prep as $x) {
        $r = $x['row'];
        if (!$r || (int)$r['nb_inscrits'] < 1) continue;
        $d = $x['data'];
        if ($d['date_debut'] !== $r['date_debut'] || $d['date_fin'] !== $r['date_fin'] || abs((float)$d['prix'] - (float)$r['prix']) > 0.001) {
            $avert[] = camp_libelle_session($r) . ' (' . (int)$r['nb_inscrits'] . ' inscrit' . ((int)$r['nb_inscrits'] > 1 ? 's' : '') . ')';
        }
    }
    if ($avert && empty($p['confirm_inscrits'])) {
        $e = new CampException("Des familles sont déjà inscrites à : " . implode(', ', $avert) . ". Changer les dates ou le prix de ces sessions les concerne directement (elles ne sont pas prévenues automatiquement). Confirmer les modifications ?");
        $e->confirm = true;
        throw $e;
    }

    // 3. Images (déposées avant la transaction ; une image orpheline est sans conséquence)
    $images = [];
    $order = array_merge([$principalKey], array_diff(array_keys($prep), [$principalKey]));
    foreach ($order as $key) {
        $x = $prep[$key]; $mode = (string)($x['s']['image'] ?? 'keep');
        $url = null;
        if ($mode === 'file') {
            $url = camp_enregistrer_image('image_' . $key);
            if (!$url) throw new CampException('Image refusée pour la session ' . camp_libelle_session($x['data']) . ' (JPG, PNG ou WebP, 8 Mo maximum).');
        } elseif ($mode === 'inherit' && $key !== $principalKey) {
            $url = $images[$principalKey] ?? null;
        }
        if (!$url && $x['row'] && !empty($x['row']['image_url'])) $url = $x['row']['image_url'];
        if (!$url && !$x['row']) { $u = (string)($x['s']['image_url'] ?? ''); if (preg_match('#^uploads/camps/camp_[A-Za-z0-9._]+\.(jpe?g|png|webp|gif)$#', $u)) $url = $u; }
        if (!$url && $key !== $principalKey) $url = $images[$principalKey] ?? null;
        if (!$url) throw new CampException('Ajoutez une image de couverture pour la session ' . camp_libelle_session($x['data']) . ' (JPG, PNG ou WebP, 8 Mo maximum).');
        $images[$key] = $url;
    }

    // 4. Écriture : tout ou rien
    $ids = []; $tokens = [];
    $pdo->beginTransaction();
    try {
        foreach ($order as $key) {
            $x = $prep[$key]; $row = $x['row']; $isP = ($key === $principalKey);
            $herit = $isP ? [] : array_values(array_intersect(array_keys(array_filter((array)($x['s']['herit'] ?? []))), CAMP_HERITAGE_CLES));
            $data = $x['data'];
            $data['image_url'] = $images[$key];
            $data['heritage'] = json_encode(array_fill_keys($herit, true) ?: new stdClass());
            $data['perso_description'] = in_array('description', $herit, true) ? 0 : 1;   // compatibilité avec l'ancien format
            $data['perso_lieu'] = in_array('lieu', $herit, true) ? 0 : 1;
            if ($row) {
                $sets = camp_filtrer_colonnes($pdo, $data);
                $statut = !empty($row['valide']) ? '' : ', valide = 0, en_attente = 1, refuse = 0';   // un séjour validé reste en ligne quand on le modifie
                $pdo->prepare('UPDATE camps SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($sets))) . $statut . ' WHERE id = ?')->execute([...array_values($sets), $row['id']]);
                $id = (int)$row['id'];
            } else {
                $pr = $principalRow;   // nouvelle session d'un séjour existant : même statut de validation que la principale
                $data += ['valide' => $pr ? (int)$pr['valide'] : 0, 'en_attente' => $pr ? (int)$pr['en_attente'] : 1, 'refuse' => $pr ? (int)$pr['refuse'] : 0, 'supprime' => 0, 'token' => bin2hex(random_bytes(16))];
                if ($groupe) $data['groupe_id'] = $groupe;
                $data = camp_filtrer_colonnes($pdo, $data);
                $cols = array_keys($data);
                $pdo->prepare('INSERT INTO camps (' . implode(',', array_map(fn($c) => "`$c`", $cols)) . ', date_creation, date_bump) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', NOW(), NOW())')->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
                if (!$groupe && campsHaveGroupColumn($pdo)) { $groupe = $id; $pdo->prepare('UPDATE camps SET groupe_id = ? WHERE id = ?')->execute([$id, $id]); }
                if ($isP && !$principalRow) $principalRow = ['id' => $id, 'valide' => 0, 'en_attente' => 1, 'refuse' => 0, 'groupe_id' => $groupe];
            }
            // un séjour jusque-là sans groupe qui reçoit des sessions : sa ligne ouverte rejoint le groupe
            if ($groupe && $row && empty($row['groupe_id']) && campsHaveGroupColumn($pdo)) $pdo->prepare('UPDATE camps SET groupe_id = ? WHERE id = ?')->execute([$groupe, $id]);
            camp_enregistrer_tarifs($pdo, $id, $x['tarifs']);
            camp_enregistrer_attente($pdo, $id, $x['in']);
            options_enregistrer($pdo, $id, $x['in']);
            etapes_enregistrer($pdo, $id, !empty($data['itinerant']), $x['in']['etapes'] ?? '[]');
            camp_enregistrer_classement($pdo, $id, $x['classement']);
            champs_enregistrer($pdo, $id, (array)($x['s']['champs'] ?? []));
            if ($x['anim']) equipe_ecrire($pdo, $id, $x['anim']);
            $ids[] = $id;
            $tokens[$key] = $row ? $row['token'] : $data['token'];
        }
        foreach ($rows as $r) {
            if (!isset($gardes[(int)$r['id']])) $pdo->prepare('UPDATE camps SET supprime = 1 WHERE id = ?')->execute([$r['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof InvalidArgumentException) throw new CampException($e->getMessage());
        throw $e;
    }
    require_once __DIR__ . '/security.php';
    sec_trace($pdo, $uid, $openToken === '' ? 'sejour_cree' : 'sejour_modifie', $ids[0] ?? 0);
    geo_actualiser($pdo, $ids);
    return ['principal' => $tokens[$principalKey] ?? null, 'tokens' => $tokens, 'motscles_refuses' => $refus, 'ids' => $ids];
}

/** Validation des questions d'une session AVANT la transaction (même règles que champs_enregistrer) */
function camp_valider_champs($champs): void {
    foreach ((array)$champs as $c) {
        $type = (string)($c['type'] ?? 'texte');
        if (trim((string)($c['libelle'] ?? '')) === '') continue;
        if (!isset(CHAMPS_TYPES[$type])) throw new InvalidArgumentException('Type de question inconnu.');
        if ($type === 'liste') {
            $l = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($c['options'] ?? ''))))));
            if (count($l) < 2) throw new InvalidArgumentException('La liste « ' . trim((string)$c['libelle']) . ' » doit proposer au moins 2 choix.');
        }
    }
    if (count((array)$champs) > 30) throw new InvalidArgumentException('30 questions maximum par session.');
}
