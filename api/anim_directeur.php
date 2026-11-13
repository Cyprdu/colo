<?php
// api/anim_directeur.php : tout ce que fait un DIRECTEUR pour ses animateurs. Chaque action vérifie que le séjour / la candidature
// appartient à un organisateur du directeur connecté. Aucun identifiant numérique en entrée ni en sortie (jetons uniquement).
//   GET  ?a=sejours                          ses séjours + mode + compteurs
//   POST ?a=mode {camp, mode, remuneration}  mode : recrute | propres | aucun
//   GET  ?a=candidatures&camp=<jeton>        candidatures (camp facultatif = tous)
//   GET  ?a=dossier&c=<jeton candidature>    dossier complet de l'animateur
//   POST ?a=decider {c, statut, note}        Accepté | Refusé | Liste d'attente | En attente
//   GET  ?a=liens&camp=<jeton>               liens d'inscription
//   POST ?a=lien_creer {camp, type, libelle, max_uses, expire}   POST ?a=lien_revoquer {l}
//   GET  ?a=equipe&camp=<jeton>              animateurs acceptés
require_once 'config.php';
require_once 'animateurs.php';

$uid = anim_require_directeur();
anim_ensure($pdo);
$a = $_GET['a'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

function anim_mon_camp(PDO $pdo, string $tok, int $uid): array {
    $c = anim_camp_du_directeur($pdo, $tok, $uid);
    if (!$c) anim_json(['error' => 'Séjour introuvable.'], 404);
    return $c;
}

try {
    if ($a === 'sejours' && !$post) {
        $st = $pdo->prepare("SELECT c.token, c.nom, c.ville, c.date_debut, c.date_fin, c.anim_mode, c.anim_remuneration, c.quota_max_anim,
                (SELECT COUNT(*) FROM candidatures WHERE camp_id = c.id) AS total,
                (SELECT COUNT(*) FROM candidatures WHERE camp_id = c.id AND statut = 'En attente') AS en_attente,
                (SELECT COUNT(*) FROM candidatures WHERE camp_id = c.id AND statut = 'Accepté') AS acceptes
            FROM camps c JOIN organisateurs o ON o.id = c.organisateur_id
            WHERE o.user_id = ? AND c.supprime = 0 AND c.refuse = 0 ORDER BY c.date_debut DESC");
        $st->execute([$uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['nom'] = htmlspecialchars_decode((string)$r['nom']); $r['total'] = (int)$r['total']; $r['en_attente'] = (int)$r['en_attente']; $r['acceptes'] = (int)$r['acceptes']; }
        anim_json($rows);
    }

    if ($a === 'mode' && $post) {
        $in = anim_body();
        $camp = anim_mon_camp($pdo, (string)($in['camp'] ?? ''), $uid);
        $mode = $in['mode'] ?? '';
        if (!in_array($mode, ['recrute', 'propres', 'aucun'], true)) anim_json(['error' => 'Mode invalide.'], 400);
        $rem = null;
        if ($mode === 'recrute' && isset($in['remuneration']) && $in['remuneration'] !== '' && $in['remuneration'] !== null) {
            if (!is_numeric($in['remuneration']) || (float)$in['remuneration'] < 0 || (float)$in['remuneration'] > 100000) anim_json(['error' => 'Rémunération invalide.'], 400);
            $rem = round((float)$in['remuneration'], 2);
        }
        $pdo->prepare("UPDATE camps SET anim_mode = ?, recrute_animateurs = ?, anim_remuneration = ? WHERE id = ?")
            ->execute([$mode, $mode === 'recrute' ? 1 : 0, $rem, $camp['id']]);
        anim_json(['success' => true]);
    }

    if ($a === 'candidatures' && !$post) {
        $sql = "SELECT cd.token, cd.statut, cd.created_at, cd.motivation, cd.lien_id IS NOT NULL AS via_lien, c.token AS camp_token, c.nom AS camp_nom,
                    u.prenom, u.nom, p.bafa_statut, p.cv_fichier IS NOT NULL AS has_cv
                FROM candidatures cd JOIN camps c ON c.id = cd.camp_id JOIN organisateurs o ON o.id = c.organisateur_id
                JOIN users u ON u.id = cd.user_id LEFT JOIN animateur_profils p ON p.user_id = cd.user_id
                WHERE o.user_id = ?";
        $args = [$uid];
        if (!empty($_GET['camp'])) { $camp = anim_mon_camp($pdo, (string)$_GET['camp'], $uid); $sql .= " AND c.id = ?"; $args[] = $camp['id']; }
        $st = $pdo->prepare($sql . " ORDER BY FIELD(cd.statut, 'En attente', 'Liste d''attente', 'Accepté', 'Refusé'), cd.created_at DESC");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['camp_nom'] = htmlspecialchars_decode((string)$r['camp_nom']); $r['via_lien'] = (bool)$r['via_lien']; $r['has_cv'] = (bool)$r['has_cv']; $r['bafa_statut'] = $r['bafa_statut'] ?? 'aucun'; }
        anim_json($rows);
    }

    if ($a === 'dossier' && !$post) {
        $cd = anim_candidature_du_directeur($pdo, (string)($_GET['c'] ?? ''), $uid);
        if (!$cd) anim_json(['error' => 'Candidature introuvable.'], 404);
        $u = $pdo->prepare("SELECT prenom, nom, email, tel, date_naissance, sexe FROM users WHERE id = ?");
        $u->execute([$cd['user_id']]);
        $user = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        $age = !empty($user['date_naissance']) ? (new DateTime($user['date_naissance']))->diff(new DateTime())->y : null;
        $p = anim_profil($pdo, (int)$cd['user_id']);
        // Autres séjours du même directeur auxquels cet animateur a déjà été accepté (utile pour la vue équipe)
        anim_json([
            'token' => $cd['token'], 'statut' => $cd['statut'], 'date' => $cd['created_at'], 'message' => $cd['motivation'], 'note' => $cd['note_directeur'],
            'camp' => ['token' => $cd['camp_token'], 'nom' => htmlspecialchars_decode((string)$cd['camp_nom'])], 'via_lien' => $cd['lien_id'] !== null,
            'animateur' => [
                'prenom' => $user['prenom'] ?? '', 'nom' => $user['nom'] ?? '', 'email' => $user['email'] ?? '', 'tel' => $user['tel'] ?? '',
                'age' => $age, 'sexe' => $user['sexe'] ?? null,
            ],
            'profil' => [
                'presentation' => $p['presentation'], 'bafa_statut' => $p['bafa_statut'], 'bafa_numero' => $p['bafa_numero'], 'formation' => $p['formation'],
                'experiences' => $p['experiences'], 'disponibilites' => $p['disponibilites'], 'cv_nom' => $p['cv_nom'], 'has_cv' => !empty($p['cv_fichier']),
            ],
        ]);
    }

    if ($a === 'decider' && $post) {
        $in = anim_body();
        $cd = anim_candidature_du_directeur($pdo, (string)($in['c'] ?? ''), $uid);
        if (!$cd) anim_json(['error' => 'Candidature introuvable.'], 404);
        $statut = $in['statut'] ?? '';
        if (!in_array($statut, ANIM_STATUTS, true)) anim_json(['error' => 'Statut invalide.'], 400);
        $note = anim_txt($in['note'] ?? '', 500);
        $pdo->prepare("UPDATE candidatures SET statut = ?, note_directeur = ?, decided_at = NOW() WHERE id = ?")->execute([$statut, $note ?: null, $cd['id']]);
        $nom = htmlspecialchars_decode((string)$cd['camp_nom']);
        $txt = ['Accepté' => "Bonne nouvelle : votre candidature pour « $nom » a été acceptée.",
                'Refusé' => "Votre candidature pour « $nom » n'a pas été retenue.",
                "Liste d'attente" => "Votre candidature pour « $nom » est placée en liste d'attente.",
                'En attente' => null][$statut];
        if ($txt && $statut !== $cd['statut']) anim_notifier($pdo, (int)$cd['user_id'], $txt . ($note ? " Message du directeur : $note" : ''));
        anim_json(['success' => true]);
    }

    if ($a === 'liens' && !$post) {
        $camp = anim_mon_camp($pdo, (string)($_GET['camp'] ?? ''), $uid);
        $st = $pdo->prepare("SELECT l.token, l.type, l.libelle, l.max_uses, l.expire_at, l.actif, l.created_at, (SELECT COUNT(*) FROM candidatures WHERE lien_id = l.id) AS used
            FROM animateur_liens l WHERE l.camp_id = ? ORDER BY l.id DESC");
        $st->execute([$camp['id']]);
        anim_json($st->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($a === 'lien_creer' && $post) {
        $in = anim_body();
        $camp = anim_mon_camp($pdo, (string)($in['camp'] ?? ''), $uid);
        if ($camp['anim_mode'] !== 'propres') anim_json(['error' => "Choisissez d'abord le mode « Mes propres animateurs »."], 409);
        $type = ($in['type'] ?? 'prive') === 'public' ? 'public' : 'prive';
        $max = isset($in['max_uses']) && is_numeric($in['max_uses']) && (int)$in['max_uses'] > 0 ? min((int)$in['max_uses'], 1000) : null;
        $exp = null;
        if (!empty($in['expire'])) { $d = DateTime::createFromFormat('Y-m-d', (string)$in['expire']); if ($d) $exp = $d->format('Y-m-d') . ' 23:59:59'; }
        $tok = bin2hex(random_bytes(20));
        $pdo->prepare("INSERT INTO animateur_liens (camp_id, token, type, libelle, max_uses, expire_at) VALUES (?,?,?,?,?,?)")
            ->execute([$camp['id'], $tok, $type, anim_txt($in['libelle'] ?? '', 120) ?: null, $max, $exp]);
        anim_json(['success' => true, 'token' => $tok], 201);
    }

    if ($a === 'lien_revoquer' && $post) {
        $t = (string)(anim_body()['l'] ?? '');
        $pdo->prepare("UPDATE animateur_liens l JOIN camps c ON c.id = l.camp_id JOIN organisateurs o ON o.id = c.organisateur_id SET l.actif = 0 WHERE l.token = ? AND o.user_id = ?")->execute([$t, $uid]);
        anim_json(['success' => true]);
    }

    if ($a === 'equipe' && !$post) {
        $camp = anim_mon_camp($pdo, (string)($_GET['camp'] ?? ''), $uid);
        $st = $pdo->prepare("SELECT cd.token, u.prenom, u.nom, u.email, u.tel, p.bafa_statut FROM candidatures cd JOIN users u ON u.id = cd.user_id
            LEFT JOIN animateur_profils p ON p.user_id = cd.user_id WHERE cd.camp_id = ? AND cd.statut = 'Accepté' ORDER BY u.prenom");
        $st->execute([$camp['id']]);
        anim_json(['quota' => (int)$camp['quota_max_anim'] ?: null, 'animateurs' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    anim_json(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    error_log('anim_directeur: ' . $e->getMessage());
    anim_json(['error' => 'Service momentanément indisponible.'], 500);
}
