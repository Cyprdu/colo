<?php
// api/camp_save.php : lecture / validation du formulaire de séjour, partagée par add_camp.php et update_camp.php.
require_once __DIR__ . '/options.php';
require_once __DIR__ . '/attente.php';
require_once __DIR__ . '/etapes.php';
require_once __DIR__ . '/catalogue.php';
require_once __DIR__ . '/motscles.php';
require_once __DIR__ . '/sessions.php';
require_once __DIR__ . '/champs.php';
require_once __DIR__ . '/regles.php';

/**
 * Valide $in (le POST du formulaire) pour le directeur $uid.
 * Retourne [colonnes camps => valeurs, liste d'identifiants de tarifs, message d'erreur|null, classement] où classement =
 * ['cats' => sélection de catégories|null, 'motscles' => ['ok' => [...], 'refus' => [...]]|null] (null = le formulaire ne les envoyait pas : on ne touche à rien).
 * Catégories et mots-clés sont enregistrés par camp_enregistrer_classement(), dans la transaction du séjour.
 */
function camp_lire_formulaire(PDO $pdo, int $uid, array $in): array {
    $s = fn($k, $n = 255) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
    $i = fn($k) => max(0, (int)($in[$k] ?? 0));
    $day = function ($v) { $d = DateTime::createFromFormat('Y-m-d', (string)$v); return ($d && $d->format('Y-m-d') === $v) ? $v : null; };

    // L'organisme doit appartenir au directeur
    require_once __DIR__ . '/orga_lib.php';
    $st = $pdo->prepare("SELECT id, nom FROM organisateurs WHERE id = ? AND user_id = ?");
    $st->execute([fin_orga_from_tok($pdo, (string)($in['organisateur_id'] ?? ''), $uid), $uid]); // jeton public de l'organisme
    $orga = $st->fetch(PDO::FETCH_ASSOC);
    $orgaId = (int)($orga['id'] ?? 0);
    if (!$orgaId) return [[], [], 'Choisissez un organisme.', null];

    // Catégories (avec leurs précisions) et mots-clés : tables créées ici, AVANT toute transaction (un CREATE TABLE valide la transaction en cours)
    cat_ensure($pdo); mc_ensure($pdo);
    $catalogue = cat_load($pdo);
    $sel = cat_valider_selection($catalogue, $in['categories'] ?? '');
    $motsPostes = array_key_exists('motscles', $in) || array_key_exists('type', $in);
    $mc = $motsPostes ? mc_valider($pdo, mc_liste($in['motscles'] ?? ($in['type'] ?? '')), 'camp', $orgaId, (string)$orga['nom']) : null;
    $classement = ['cats' => array_key_exists('categories', $in) ? $sel : null, 'motscles' => $mc];

    $nom = $s('nom', 200);
    $debut = $day($s('date_debut')); $fin = $day($s('date_fin'));
    if ($nom === '') return [[], [], 'Le nom du séjour est obligatoire.', null];
    if (!$debut || !$fin) return [[], [], 'Les dates du séjour sont obligatoires.', null];
    if ($fin < $debut) return [[], [], 'La date de fin doit suivre la date de début.', null];
    [$ageMode, $tranches, $ageMin, $ageMax, $errAge] = regles_age_lire($in);
    if ($errAge) return [[], [], $errAge, null];
    $limite = $day($s('date_limite_inscription'));
    if ($limite && $limite > $debut) return [[], [], "La date limite d'inscription doit précéder le début du séjour.", null];

    $enLigne = !empty($in['inscription_en_ligne']) ? 1 : 0;
    $tarifIds = []; $prix = 0.0;
    if ($enLigne) {
        $raw = json_decode((string)($in['tarifs'] ?? '[]'), true);
        $ids = array_values(array_unique(array_filter(array_map(fn($t) => (int)(is_array($t) ? ($t['id'] ?? 0) : $t), is_array($raw) ? $raw : []))));
        if ($ids) {
            // Prix lu en base, uniquement pour les tarifs des organismes du directeur
            $q = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT t.id, t.prix, t.montant_libre FROM tarifs t JOIN organisateurs o ON o.id = t.organisateur_id WHERE o.user_id = ? AND t.id IN ($q)");
            $st->execute(array_merge([$uid], $ids));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $tarifIds = array_map(fn($r) => (int)$r['id'], $rows);
            $fixes = array_filter(array_map(fn($r) => (float)$r['prix'], array_filter($rows, fn($r) => empty($r['montant_libre']) && (float)$r['prix'] > 0)));
            $tous = array_map(fn($r) => (float)$r['prix'], $rows);
            $prix = $fixes ? min($fixes) : ($tous ? min($tous) : 0.0);
        }
        if (!$tarifIds) return [[], [], 'Ajoutez au moins un tarif pour ouvrir les inscriptions en ligne.', null];
        if ($i('quota_global') < 1) return [[], [], 'Indiquez le nombre total de places.', null];
    } else {
        $prix = max(0.0, (float)str_replace(',', '.', (string)($in['prix_simple'] ?? 0)));
    }

    // Quotas filles / garçons : seulement si activés ; jamais plus que le total
    $qGlobal = $i('quota_global'); $qF = 0; $qG = 0;
    if (!empty($in['quotas_sexe_actifs'])) {
        $qF = $i('quota_fille'); $qG = $i('quota_garcon');
        if ($qGlobal > 0 && ($qF > $qGlobal || $qG > $qGlobal)) return [[], [], 'Un quota filles ou garçons ne peut pas dépasser le nombre total de places.', null];
    }
    $reserve = in_array($in['reserve_sexe'] ?? '', [SEXE_GARCON, SEXE_FILLE], true) ? $in['reserve_sexe'] : '';
    if ($reserve === SEXE_GARCON) $qF = 0;
    if ($reserve === SEXE_FILLE) $qG = 0;

    $anim = !empty($in['gestion_animateur']) ? 1 : 0;
    $lien = $s('lien_externe', 500);
    if ($lien !== '' && !preg_match('#^https?://#i', $lien)) $lien = 'https://' . $lien;
    $video = $s('video_url', 500);
    if ($video !== '' && !preg_match('#^https?://#i', $video)) return [[], [], "Le lien de la vidéo doit commencer par http(s)://", null];

    $data = [
        'organisateur_id' => $orgaId, 'nom' => $nom, 'description' => $s('description', 10000),
        'theme' => cat_theme_pour($catalogue, $sel, $s('theme', 100)),
        'type' => $mc !== null ? mb_substr(implode(', ', array_column($mc['ok'], 'label')), 0, 500) : $s('type', 500), 'pays' => $s('pays', 80) ?: 'France',
        'itinerant' => !empty($in['itinerant']) ? 1 : 0, 'video_url' => $video,
        'ville' => $s('ville', 120), 'adresse' => $s('adresse', 255), 'code_postal' => $s('cp', 20),
        'age_min' => $ageMin, 'age_max' => $ageMax, 'age_mode' => $ageMode, 'tranches' => implode(',', $tranches) ?: null, 'date_debut' => $debut, 'date_fin' => $fin,
        'prive' => !empty($in['prive']) ? 1 : 0,
        'inscription_en_ligne' => $enLigne, 'inscription_hors_ligne' => $enLigne ? 0 : 1, 'prix' => $prix,
        'lien_externe' => $lien, 'adresse_retour_dossier' => $s('adresse_retour_dossier', 500),
        'date_limite_inscription' => $limite, 'remise_fratrie' => min(100, $i('remise_fratrie')),
        'quota_global' => $qGlobal, 'quota_fille' => $qF, 'quota_garcon' => $qG,
        'gestion_animateur' => $anim, 'quota_max_anim' => $anim ? $i('quota_max_anim') : 0,
        'quota_anim_fille' => $anim ? $i('quota_anim_fille') : 0, 'quota_anim_garcon' => $anim ? $i('quota_anim_garcon') : 0,
        'anim_plus_18' => $anim && !empty($in['anim_plus_18']) ? 1 : 0, 'bafa_obligatoire' => $anim && !empty($in['bafa_obligatoire']) ? 1 : 0,
        'remuneration_anim' => $anim && !empty($in['remuneration_anim']) ? 1 : 0, 'anim_doit_payer' => $anim && !empty($in['anim_doit_payer']) ? 1 : 0,
        'prix_anim' => $anim ? max(0, (float)($in['prix_anim'] ?? 0)) : 0,
    ];
    if ($data['theme'] === '') return [[], [], 'Choisissez au moins une catégorie pour votre séjour.', null];
    if ($data['ville'] === '' || $data['adresse'] === '') return [[], [], "L'adresse et la ville sont obligatoires.", null];
    return [$data, $tarifIds, null, $classement];
}

/** Enregistre catégories, précisions et mots-clés d'un séjour (dans la transaction du séjour). */
function camp_enregistrer_classement(PDO $pdo, int $campId, ?array $classement): void {
    if (!$classement) return;
    if ($classement['cats'] !== null) cat_enregistrer_camp($pdo, $campId, $classement['cats']);
    if ($classement['motscles'] !== null) mc_enregistrer($pdo, 'camp', $campId, $classement['motscles']['ok']);
}

/**
 * Ne garde que les colonnes qui existent vraiment dans `camps` (une base plus ancienne ou différente ne doit pas faire échouer l'enregistrement).
 * `itinerant` est aussi recopié dans la colonne accentuée `Itinérance` quand elle existe.
 */
function camp_filtrer_colonnes(PDO $pdo, array $data): array {
    $cols = $pdo->query("SHOW COLUMNS FROM camps")->fetchAll(PDO::FETCH_COLUMN);
    if (isset($data['itinerant']) && in_array('Itinérance', $cols, true)) $data['Itinérance'] = $data['itinerant'];
    $out = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $cols, true)) $out[$k] = $v; else error_log("camp_save : colonne absente ignorée : $k");
    }
    return $out;
}

/** Réservations « en attente de paiement » : autorisation et délai (1 à 60 jours) */
function camp_enregistrer_attente(PDO $pdo, int $campId, array $in): void {
    attente_ensure($pdo);
    $pdo->prepare("UPDATE camps SET attente_paiement = ?, attente_jours = ? WHERE id = ?")
        ->execute([!empty($in['attente_paiement']) ? 1 : 0, max(1, min(ATTENTE_MAX_JOURS, (int)($in['attente_jours'] ?? ATTENTE_DEFAUT_JOURS))), $campId]);
}

function camp_enregistrer_tarifs(PDO $pdo, int $campId, array $tarifIds): void {
    $pdo->prepare("DELETE FROM camps_tarifs WHERE camp_id = ?")->execute([$campId]);
    $ins = $pdo->prepare("INSERT INTO camps_tarifs (camp_id, tarif_id) VALUES (?, ?)");
    foreach ($tarifIds as $t) $ins->execute([$campId, $t]);
}

/** Enregistre la photo de couverture ; retourne le chemin relatif au site, ou null (aucun fichier / refusé) */
function camp_enregistrer_image(string $cle = 'image'): ?string {
    if (empty($_FILES[$cle]) || $_FILES[$cle]['error'] !== UPLOAD_ERR_OK) return null;
    if ($_FILES[$cle]['size'] > 8 * 1024 * 1024) return null;
    $info = @getimagesize($_FILES[$cle]['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
    if (!$ext) return null;
    $dir = __DIR__ . '/../uploads/camps/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = uniqid('camp_') . '.' . $ext;
    return move_uploaded_file($_FILES[$cle]['tmp_name'], $dir . $name) ? 'uploads/camps/' . $name : null;
}

/** Erreur dont le message est destiné au directeur (affiché tel quel). confirm = true : à confirmer, puis à renvoyer avec confirm_inscrits. */
class CampException extends RuntimeException {
    public bool $confirm = false;
}
