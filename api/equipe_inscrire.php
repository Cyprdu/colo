<?php
// api/equipe_inscrire.php : inscription d'un membre de l'équipe d'animation (option B) via un lien du directeur. Compte connecté requis.
//   GET  ?a=info&l=<jeton du lien>      séjour, tarifs, questions, places, pré-remplissage
//   POST ?a=inscrire {lien, ...}        crée l'inscription ; renvoie {url} (paiement Stripe) ou {gratuit: true}
//   POST ?a=payer {t}                   relance le paiement d'une de MES inscriptions en attente
// Tout est recalculé ici depuis la base : le navigateur n'envoie que des choix (tarif, montant libre, réponses).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/animateurs.php';
require_once __DIR__ . '/equipe.php';

$uid = anim_user_id();
if (!$uid) anim_json(['error' => 'Connectez-vous pour vous inscrire.', 'login' => true], 401);
anim_ensure($pdo); equipe_ensure($pdo);
$a = $_GET['a'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

function eq_stripe(PDO $pdo, array $camp, array $row, int $uid): string {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!defined('STRIPE_SECRET_KEY')) throw new RuntimeException('Stripe non configuré');
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');
    $cents = (int)round((float)$row['prix_final'] * 100);
    require_once __DIR__ . '/finance_lib.php';
    $params = [
        'payment_method_types' => ['card'], 'mode' => 'payment', 'locale' => 'fr', 'allow_promotion_codes' => true,
        'expires_at' => time() + EQUIPE_ATTENTE_MIN * 60 + 60,
        'client_reference_id' => (string)$uid,
        'customer_email' => $row['email'],
        'line_items' => [[
            'price_data' => ['currency' => 'eur', 'unit_amount' => $cents, 'product_data' => [
                'name' => mb_substr('Équipe d\'animation : ' . $row['prenom'] . ' ' . $row['nom'], 0, 250),
                'description' => mb_substr($camp['nom'] . ($row['tarif_nom'] ? ' · ' . $row['tarif_nom'] : ''), 0, 500)]],
            'quantity' => 1]],
        'success_url' => $site . '/reservations?equipe=ok', 'cancel_url' => $site . '/reservations?equipe=annule',
        'metadata' => ['reservation_token' => $row['token'], 'camp_id' => (string)$camp['id'], 'user_id' => (string)$uid, 'type' => 'equipe_inscription'],
        'payment_intent_data' => ['description' => 'moncamp.fr · Équipe d\'animation · ' . mb_substr($camp['nom'], 0, 120), 'metadata' => ['reservation_token' => $row['token'], 'camp_id' => (string)$camp['id']]],
    ];
    $co = \Stripe\Checkout\Session::create(array_replace_recursive($params, fin_destination_params($pdo, (int)$camp['organisateur_id'], [$cents])));
    $pdo->prepare("UPDATE equipe_inscriptions SET stripe_session_id = ? WHERE id = ?")->execute([$co->id, $row['id']]);
    return $co->url;
}

try {
    if ($a === 'info' && !$post) {
        $r = anim_lien_valide($pdo, (string)($_GET['l'] ?? ''));
        if (!$r) anim_json(['error' => "Ce lien n'est plus valide. Demandez un nouveau lien au directeur."], 404);
        [$lien, $camp] = $r;
        $strip = fn($s) => trim(strip_tags(htmlspecialchars_decode((string)$s)));
        $o = $pdo->prepare("SELECT nom FROM organisateurs WHERE id = ?"); $o->execute([$camp['organisateur_id']]);
        $u = $pdo->prepare("SELECT prenom, nom, email, tel, date_naissance FROM users WHERE id = ?"); $u->execute([$uid]);
        $quota = (int)($camp['equipe_quota'] ?? 0);
        $mine = $pdo->prepare("SELECT token, statut, statut_paiement FROM equipe_inscriptions WHERE camp_id = ? AND user_id = ? AND statut <> 'Annulé'");
        $mine->execute([$camp['id'], $uid]);
        anim_json([
            'camp' => ['nom' => $strip($camp['nom']), 'ville' => $strip($camp['ville']), 'date_debut' => $camp['date_debut'], 'date_fin' => $camp['date_fin'], 'organisateur' => $strip($o->fetchColumn())],
            'age_min' => $camp['equipe_age_min'] !== null ? (int)$camp['equipe_age_min'] : null, 'infos' => equipe_infos_niveaux($camp),
            'places_restantes' => $quota > 0 ? max(0, $quota - equipe_places_prises($pdo, (int)$camp['id'])) : null,
            'tarifs' => array_map(fn($t) => ['id' => (int)$t['id'], 'nom' => $t['nom'], 'prix' => (float)$t['prix'], 'libre' => (int)$t['montant_libre'], 'min' => equipe_plancher($t)], equipe_tarifs($pdo, (int)$camp['id'])),
            'champs' => array_map(fn($c) => ['id' => $c['id'], 'libelle' => $c['libelle'], 'type' => $c['type'], 'choix' => $c['choix'], 'obligatoire' => $c['obligatoire']], equipe_champs($pdo, (int)$camp['id'])),
            'moi' => $u->fetch(PDO::FETCH_ASSOC) ?: new stdClass(),
            'deja' => $mine->fetch(PDO::FETCH_ASSOC) ?: null,
        ]);
    }

    if ($a === 'inscrire' && $post) {
        $in = anim_body();
        if (sec_blocked($pdo, 'equipe', sec_ip(), 20, 60) || sec_blocked($pdo, 'equipe_u', (string)$uid, 10, 60)) anim_json(['error' => 'Trop de tentatives. Réessayez dans une heure.'], 429);
        sec_hit($pdo, 'equipe', sec_ip()); sec_hit($pdo, 'equipe_u', (string)$uid);
        $r = anim_lien_valide($pdo, (string)($in['lien'] ?? ''));
        if (!$r) anim_json(['error' => "Ce lien n'est plus valide."], 404);
        [$lien, $camp] = $r; $campId = (int)$camp['id'];
        if ($camp['date_fin'] && $camp['date_fin'] < date('Y-m-d')) anim_json(['error' => 'Ce séjour est terminé.'], 409);

        // Identité et contact
        $prenom = anim_txt($in['prenom'] ?? '', 80); $nom = anim_txt($in['nom'] ?? '', 80);
        $email = trim((string)($in['email'] ?? '')); $tel = anim_txt($in['tel'] ?? '', 30);
        if ($prenom === '' || $nom === '') anim_json(['error' => 'Indiquez votre prénom et votre nom.'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) anim_json(['error' => 'Adresse e-mail invalide.'], 400);
        $dn = DateTime::createFromFormat('Y-m-d', (string)($in['date_naissance'] ?? ''));
        $dn = ($dn && $dn->format('Y-m-d') === ($in['date_naissance'] ?? '')) ? $dn : null;
        if ($camp['equipe_age_min'] !== null) {
            if (!$dn) anim_json(['error' => 'Indiquez votre date de naissance.'], 400);
            $ref = new DateTime($camp['date_debut'] ?: 'today');
            if ($dn->diff($ref)->y < (int)$camp['equipe_age_min']) anim_json(['error' => 'Âge minimum pour rejoindre cette équipe : ' . (int)$camp['equipe_age_min'] . ' ans.'], 403);
        }
        $urgNom = anim_txt($in['urgence_nom'] ?? '', 120); $urgTel = anim_txt($in['urgence_tel'] ?? '', 30);
        $niv = equipe_infos_niveaux($camp);
        if ($niv['urgence'] === 2 && ($urgNom === '' || $urgTel === '')) anim_json(['error' => "Indiquez une personne à prévenir en cas d'urgence."], 400);
        foreach (['allergies', 'regime', 'diplome'] as $k) if ($niv[$k] === 2 && anim_txt($in[$k] ?? '', 1000) === '') anim_json(['error' => 'Information obligatoire : ' . mb_strtolower(EQUIPE_INFOS[$k]) . '.'], 400);
        $bafa = in_array($in['bafa_statut'] ?? '', ['bafa', 'en_cours', 'equivalent', 'aucun'], true) ? $in['bafa_statut'] : 'aucun';

        // Déjà inscrit ? (une inscription non payée et expirée est libérée)
        $pdo->prepare("DELETE FROM equipe_inscriptions WHERE camp_id = ? AND user_id = ? AND statut_paiement = 'EN_ATTENTE' AND created_at <= (NOW() - INTERVAL " . EQUIPE_ATTENTE_MIN . " MINUTE)")->execute([$campId, $uid]);
        $d = $pdo->prepare("SELECT 1 FROM equipe_inscriptions WHERE camp_id = ? AND user_id = ? AND statut <> 'Annulé'"); $d->execute([$campId, $uid]);
        if ($d->fetch()) anim_json(['error' => 'Vous êtes déjà inscrit à ce séjour : retrouvez votre inscription dans « Mes réservations ».'], 409);

        // Places de l'équipe (indépendantes de celles des enfants)
        $quota = (int)($camp['equipe_quota'] ?? 0);
        if ($quota > 0 && equipe_places_prises($pdo, $campId) >= $quota) anim_json(['error' => "L'équipe d'animation est complète."], 409);

        // Questions du directeur
        $champs = equipe_champs($pdo, $campId); $reps = [];
        if ($champs) {
            [$reps, $err] = champs_valider($champs, is_array($in['reponses'] ?? null) ? $in['reponses'] : [], $prenom);
            if ($err) anim_json(['error' => $err], 400);
        }

        // Prix : uniquement depuis la base
        $tarifs = []; foreach (equipe_tarifs($pdo, $campId) as $t) $tarifs[(int)$t['id']] = $t;
        $prix = 0.0; $tarifNom = null;
        if ($tarifs) {
            $t = $tarifs[(int)($in['tarif'] ?? 0)] ?? null;
            if (!$t) anim_json(['error' => 'Choisissez une participation.'], 400);
            $tarifNom = $t['nom']; $prix = (float)$t['prix'];
            if (!empty($t['montant_libre'])) {
                $libre = (float)str_replace(',', '.', (string)($in['montant'] ?? 0));
                $prix = $libre > 0 ? min((float)EQUIPE_MAX_PRIX, max($libre, equipe_plancher($t))) : max($prix, equipe_plancher($t));
            }
        }
        $prix = round($prix, 2);
        if ($prix > 0 && $prix < 0.5) anim_json(['error' => 'Le montant minimum de paiement par carte est de 0,50 €.'], 400);

        $tok = bin2hex(random_bytes(16));
        $free = $prix <= 0;
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO equipe_inscriptions (camp_id, user_id, lien_id, token, prenom, nom, date_naissance, email, tel, bafa_statut, diplome, allergies, regime,
                urgence_nom, urgence_tel, tarif_nom, prix_final, montant_paye, statut, statut_paiement) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$campId, $uid, (int)$lien['id'], $tok, $prenom, $nom, $dn ? $dn->format('Y-m-d') : null, $email, $tel ?: null, $bafa, anim_txt($in['diplome'] ?? '', 255) ?: null,
                       anim_txt($in['allergies'] ?? '', 1000) ?: null, anim_txt($in['regime'] ?? '', 255) ?: null, $urgNom, $urgTel, $tarifNom, $prix, 0,
                       $free ? 'Confirmé' : 'En attente', $free ? 'GRATUIT' : 'EN_ATTENTE']);
        $eid = (int)$pdo->lastInsertId();
        foreach ($reps as $x) $pdo->prepare("INSERT INTO equipe_reponses (equipe_id, champ_id, libelle, valeur) VALUES (?,?,?,?)")->execute([$eid, $x['champ_id'], $x['libelle'], $x['valeur']]);
        $pdo->commit();
        sec_trace($pdo, $uid, 'equipe_inscription', $eid);
        if ($free) anim_json(['success' => true, 'gratuit' => true], 201);

        try { $row = ['id' => $eid, 'token' => $tok, 'prenom' => $prenom, 'nom' => $nom, 'email' => $email, 'tarif_nom' => $tarifNom, 'prix_final' => $prix];
              anim_json(['success' => true, 'url' => eq_stripe($pdo, $camp, $row, $uid)], 201); }
        catch (Throwable $e) {
            error_log('equipe stripe: ' . $e->getMessage());
            $pdo->prepare("DELETE FROM equipe_inscriptions WHERE id = ? AND statut_paiement = 'EN_ATTENTE'")->execute([$eid]);
            anim_json(['error' => "Le paiement n'a pas pu démarrer. Rien n'a été débité, réessayez."], 500);
        }
    }

    if ($a === 'payer' && $post) {
        $t = (string)(anim_body()['t'] ?? '');
        $st = $pdo->prepare("SELECT * FROM equipe_inscriptions WHERE token = ? AND user_id = ? AND statut_paiement = 'EN_ATTENTE' AND statut <> 'Annulé'");
        $st->execute([$t, $uid]); $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || strtotime($row['created_at']) < time() - EQUIPE_ATTENTE_MIN * 60) anim_json(['error' => 'Cette inscription a expiré : refaites-la depuis le lien du directeur.'], 409);
        $c = $pdo->prepare("SELECT * FROM camps WHERE id = ? AND equipe_active = 1 AND supprime = 0"); $c->execute([$row['camp_id']]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) anim_json(['error' => "Ce séjour n'est plus disponible."], 404);
        anim_json(['url' => eq_stripe($pdo, $camp, $row, $uid)]);
    }

    anim_json(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('equipe_inscrire: ' . $e->getMessage());
    anim_json(['error' => 'Service momentanément indisponible.'], 500);
}
