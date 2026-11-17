<?php
// api/rgpd_lib.php : droits des personnes (RGPD) : export de ses données, suppression de son compte.
//
// Suppression = effacement des données personnelles ET des documents, mais les lignes comptables
// (inscriptions, paiements) sont conservées sous forme anonymisée : une obligation légale impose de garder les pièces
// comptables (10 ans), l'effacement ne s'applique donc pas à elles.

function rgpd_cols(PDO $pdo, string $table): array {
    try { return $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { return []; }
}
function rgpd_table_exists(PDO $pdo, string $table): bool { return (bool)rgpd_cols($pdo, $table); }

/** Toutes les données qu'on détient sur cet utilisateur (structure lisible, prête pour un export JSON) */
function rgpd_export(PDO $pdo, int $uid): array {
    $strip = ['password', 'remember_token', 'verification_token', 'reset_token', 'url_token', 'google_id'];
    $out = ['genere_le' => date('c'), 'service' => 'ColoMap (moncamp.fr)'];

    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($strip as $k) unset($u[$k]);
    $out['compte'] = $u;

    $st = $pdo->prepare("SELECT * FROM enfants WHERE parent_id = ?"); $st->execute([$uid]);
    $enfants = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($enfants as &$e) {
        foreach (['carnet_sante_token' => 'carnet_sante', 'fiche_sanitaire_token' => 'fiche_sanitaire', 'signature_token' => 'signature'] as $k => $label) {
            $e[$label . '_depose'] = !empty($e[$k]); unset($e[$k]);   // on indique la présence du document, pas son adresse interne
        }
    }
    unset($e);
    $out['enfants'] = $enfants;

    $st = $pdo->prepare("SELECT i.id AS inscription, i.camp_id, c.nom AS sejour, i.enfant_id, i.statut, i.statut_paiement, i.prix_final, i.montant_paye, i.date_inscription
                         FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id LEFT JOIN camps c ON c.id = i.camp_id WHERE e.parent_id = ? ORDER BY i.id");
    $st->execute([$uid]);
    $out['inscriptions'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // Espace animateur : profil de candidature, préférences, candidatures, inscriptions à une équipe d'animation (le CV n'est signalé que par son nom)
    try {
        $st = $pdo->prepare("SELECT presentation, bafa_statut, bafa_numero, formation, experiences, disponibilites, cv_nom, updated_at FROM animateur_profils WHERE user_id = ?"); $st->execute([$uid]);
        $out['animateur_profil'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $st = $pdo->prepare("SELECT * FROM animateur_prefs WHERE user_id = ?"); $st->execute([$uid]); $out['animateur_preferences'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $st = $pdo->prepare("SELECT c.nom AS sejour, cd.motivation, cd.statut, cd.created_at, cd.decided_at FROM candidatures cd LEFT JOIN camps c ON c.id = cd.camp_id WHERE cd.user_id = ? ORDER BY cd.id"); $st->execute([$uid]); $out['animateur_candidatures'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("SELECT c.nom AS sejour, e.prenom, e.nom, e.date_naissance, e.email, e.tel, e.bafa_statut, e.diplome, e.allergies, e.regime, e.urgence_nom, e.urgence_tel, e.tarif_nom, e.prix_final, e.statut, e.statut_paiement, e.created_at
                             FROM equipe_inscriptions e LEFT JOIN camps c ON c.id = e.camp_id WHERE e.user_id = ? ORDER BY e.id"); $st->execute([$uid]); $out['equipe_animation_inscriptions'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try { $st = $pdo->prepare("SELECT conversation_id, content, created_at FROM messages WHERE sender_id = ? ORDER BY id"); $st->execute([$uid]); $out['messages_envoyes'] = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $st = $pdo->prepare("SELECT type, version, accepte, created_at FROM consentements WHERE user_id = ? ORDER BY id"); $st->execute([$uid]); $out['consentements'] = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $st = $pdo->prepare("SELECT action, ip, created_at FROM journal_contenus WHERE user_id = ? ORDER BY id DESC LIMIT 500"); $st->execute([$uid]); $out['journal_envois'] = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $st = $pdo->prepare("SELECT enfant_id, type, created_at FROM acces_sante WHERE enfant_id IN (SELECT id FROM enfants WHERE parent_id = ?) ORDER BY id DESC LIMIT 500"); $st->execute([$uid]); $out['acces_aux_documents_de_sante'] = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    return $out;
}

/** Peut-on supprimer ce compte ? Retourne un message d'obstacle, ou null si c'est possible. */
function rgpd_obstacle(PDO $pdo, int $uid): ?string {
    $st = $pdo->prepare("SELECT is_directeur, is_admin FROM users WHERE id = ?"); $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return 'Compte introuvable.';
    if (!empty($u['is_admin']) || !empty($u['is_directeur'])) return "Un compte organisateur ou administrateur ne peut pas être supprimé en libre-service (séjours, versements et obligations comptables en cours). Écrivez-nous via la page Contact : nous le traitons sous 30 jours.";
    $st = $pdo->prepare("SELECT COUNT(*) FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id JOIN camps c ON c.id = i.camp_id
                         WHERE e.parent_id = ? AND COALESCE(i.statut, '') NOT IN ('Annulé', 'annule', 'Annule') AND COALESCE(c.date_fin, c.date_debut) >= CURDATE()");
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() > 0) return "Vous avez encore des réservations à venir ou en cours. Annulez-les d'abord (ou attendez la fin des séjours), puis supprimez votre compte.";
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM equipe_inscriptions e JOIN camps c ON c.id = e.camp_id WHERE e.user_id = ? AND e.statut <> 'Annulé' AND COALESCE(c.date_fin, c.date_debut) >= CURDATE()");
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() > 0) return "Vous êtes encore inscrit comme animateur à un séjour à venir ou en cours. Annulez cette inscription (ou attendez la fin du séjour), puis supprimez votre compte.";
    } catch (Throwable $e) {}
    return null;
}

/** Efface les données personnelles et les documents ; conserve les lignes comptables anonymisées. */
function rgpd_delete_account(PDO $pdo, int $uid): void {
    $root = dirname(__DIR__) . '/uploads/';
    $pdo->beginTransaction();
    try {
        // 1. documents de santé et signatures des enfants
        $st = $pdo->prepare("SELECT id, carnet_sante_token, fiche_sanitaire_token, signature_token FROM enfants WHERE parent_id = ?");
        $st->execute([$uid]);
        $files = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            foreach (['sante' => 'carnet_sante_token', 'sanitaire' => 'fiche_sanitaire_token', 'signatures' => 'signature_token'] as $dir => $col)
                if (!empty($e[$col]) && preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/i', $e[$col])) $files[] = $root . $dir . '/' . $e[$col];
        }

        // 2. enfants : on vide tout ce qui est personnel (la ligne reste, car les inscriptions payées s'y rattachent)
        $blank = ['prenom' => 'Supprimé', 'nom' => '', 'adresse' => '', 'code_postal' => '', 'ville' => '', 'pays' => '', 'tel_mobile_enfant' => '', 'tel_fixe_enfant' => '',
            'email_enfant' => '', 'infos_sante' => '', 'allergies' => '', 'regime_alimentaire' => '', 'poids' => '', 'taille' => '', 'medecin_nom' => '', 'medecin_tel' => '',
            'vaccins_data' => null, 'carnet_sante_token' => null, 'fiche_sanitaire_token' => null, 'signature_token' => null,
            'resp1_nom' => '', 'resp1_prenom' => '', 'resp1_email' => '', 'resp1_tel' => '', 'resp1_profession' => '',
            'resp2_nom' => '', 'resp2_prenom' => '', 'resp2_email' => '', 'resp2_tel' => '', 'resp2_profession' => '', 'commentaires' => ''];
        $have = rgpd_cols($pdo, 'enfants');
        $set = []; $vals = [];
        foreach ($blank as $c => $v) if (in_array($c, $have, true)) { $set[] = "`$c` = ?"; $vals[] = $v; }
        if (in_array('date_naissance', $have, true)) { $set[] = "`date_naissance` = CONCAT(YEAR(`date_naissance`), '-01-01')"; }   // on ne garde que l'année
        if ($set) { $vals[] = $uid; $pdo->prepare("UPDATE enfants SET " . implode(', ', $set) . " WHERE parent_id = ?")->execute($vals); }

        // 3. réponses aux questions personnalisées des séjours
        if (rgpd_table_exists($pdo, 'inscription_reponses'))
            $pdo->prepare("UPDATE inscription_reponses SET valeur = '' WHERE inscription_id IN (SELECT i.id FROM inscriptions i JOIN enfants e ON e.id = i.enfant_id WHERE e.parent_id = ?)")->execute([$uid]);

        // 3 bis. espace animateur : profil, CV (fichier), préférences, candidatures, notifications ; inscriptions d'équipe anonymisées (pièces comptables conservées)
        if (rgpd_table_exists($pdo, 'animateur_profils')) {
            $q = $pdo->prepare("SELECT cv_fichier FROM animateur_profils WHERE user_id = ?"); $q->execute([$uid]);
            $cv = (string)$q->fetchColumn();
            if (preg_match('/^[a-f0-9]{40}\.(pdf|docx?)$/', $cv)) $files[] = $root . 'cv/' . $cv;
            $pdo->prepare("DELETE FROM animateur_profils WHERE user_id = ?")->execute([$uid]);
        }
        foreach (['animateur_prefs', 'animateur_notifs'] as $t) if (rgpd_table_exists($pdo, $t)) $pdo->prepare("DELETE FROM `$t` WHERE user_id = ?")->execute([$uid]);
        if (rgpd_table_exists($pdo, 'candidatures')) $pdo->prepare("DELETE FROM candidatures WHERE user_id = ?")->execute([$uid]);
        if (rgpd_table_exists($pdo, 'equipe_inscriptions')) {
            $pdo->prepare("UPDATE equipe_inscriptions SET prenom = 'Supprimé', nom = '', date_naissance = NULL, email = '', tel = NULL, diplome = NULL, allergies = NULL, regime = NULL, urgence_nom = NULL, urgence_tel = NULL
                           WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("UPDATE equipe_reponses SET valeur = '' WHERE equipe_id IN (SELECT id FROM equipe_inscriptions WHERE user_id = ?)")->execute([$uid]);
        }

        // 4. messages écrits par la personne
        if (rgpd_table_exists($pdo, 'messages')) $pdo->prepare("UPDATE messages SET content = '[message supprimé]' WHERE sender_id = ?")->execute([$uid]);

        // 5. favoris et autres listes
        foreach (['favorites', 'favoris'] as $t) if (rgpd_table_exists($pdo, $t)) { try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = ?")->execute([$uid]); } catch (Throwable $e) {} }

        // 6. le compte lui-même : anonymisé et désactivé
        $uc = rgpd_cols($pdo, 'users');
        $blankU = ['nom' => 'Supprimé', 'prenom' => '', 'email' => 'supprime-' . $uid . '@invalid.local', 'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'telephone' => null, 'tel' => null, 'photo_url' => null, 'date_naissance' => null, 'naissance' => null, 'google_id' => null,
            'verification_token' => null, 'url_token' => null, 'remember_token' => null, 'reset_token' => null, 'reset_expires' => null, 'is_active' => 0];
        $set = []; $vals = [];
        foreach ($blankU as $c => $v) if (in_array($c, $uc, true)) { $set[] = "`$c` = ?"; $vals[] = $v; }
        $vals[] = $uid;
        $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);

        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

    foreach ($files as $f) { if (is_file($f)) @unlink($f); }   // après le commit : une panne de disque ne doit pas annuler l'effacement en base
    if (function_exists('consent_log')) consent_log($pdo, $uid, 'suppression_compte', null, true);
}
