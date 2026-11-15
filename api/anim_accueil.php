<?php
// api/anim_accueil.php : données de l'accueil animateur (GET) : tâches utiles + suggestions classées par score de correspondance.
require_once 'config.php';
require_once 'animateurs.php';
require_once 'anim_match.php';

$uid = anim_require_animateur();
anim_ensure($pdo); anim_match_ensure($pdo);

try {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $st->execute([$uid]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $profil = anim_profil($pdo, $uid);
    $prefs = anim_prefs($pdo, $uid);

    // Complétude du profil : chaque élément manquant est expliqué
    $manque = [];
    $items = [
        ['presentation', trim((string)$profil['presentation']) !== '', 'Ajouter une présentation'],
        ['diplome', $profil['bafa_statut'] !== 'aucun' ? trim((string)$profil['bafa_numero'] . (string)$profil['formation']) !== '' : false, 'Indiquer votre BAFA ou votre formation'],
        ['experiences', trim((string)$profil['experiences']) !== '', 'Décrire vos expériences'],
        ['disponibilites', trim((string)$profil['disponibilites']) !== '', 'Préciser vos disponibilités'],
        ['cv', !empty($profil['cv_fichier']), 'Joindre votre CV'],
        ['tel', trim((string)($user['tel'] ?? $user['telephone'] ?? '')) !== '', 'Renseigner un numéro de téléphone'],
        ['photo', !empty($user['photo_url']), 'Ajouter une photo'],
    ];
    $ok = 0;
    foreach ($items as [$k, $done, $label]) { if ($done) $ok++; else $manque[] = ['cle' => $k, 'label' => $label]; }

    $c = $pdo->prepare("SELECT statut, COUNT(*) n FROM candidatures WHERE user_id = ? AND statut <> 'Retirée' GROUP BY statut"); $c->execute([$uid]);
    $cand = $c->fetchAll(PDO::FETCH_KEY_PAIR);
    $nonLus = 0;
    try {
        $m = $pdo->prepare("SELECT COUNT(*) FROM messages m JOIN conversations cv ON cv.id = m.conversation_id WHERE (cv.user_1_id = ? OR cv.user_2_id = ?) AND m.sender_id <> ? AND m.is_read = 0");
        $m->execute([$uid, $uid, $uid]); $nonLus = (int)$m->fetchColumn();
    } catch (Throwable $e) {}
    $n = $pdo->prepare("SELECT COUNT(*) FROM animateur_notifs WHERE user_id = ? AND lu = 0"); $n->execute([$uid]);

    // Suggestions
    $quals = anim_qualifs_effectives($prefs, $profil); $age = anim_age($user);
    $open = anim_camps_ouverts($pdo, $uid);
    $scored = [];
    foreach ($open as $camp) { $m = anim_score($camp, $prefs, $quals, $age); if ($m) $scored[] = [$camp, $m]; }
    usort($scored, fn($x, $y) => $y[1]['score'] <=> $x[1]['score'] ?: $y[0]['id'] <=> $x[0]['id']);
    $card = fn($pair) => anim_carte($pair[0], $pair[1]);

    $pres = array_values(array_filter($scored, fn($p) => (bool)array_filter($p[1]['raisons'], fn($r) => strpos($r, 'Dans votre') === 0)));
    $paient = array_values(array_filter($scored, fn($p) => $p[0]['anim_remuneration'] !== null && (float)$p[0]['anim_remuneration'] > 0));
    usort($paient, fn($x, $y) => (float)$y[0]['anim_remuneration'] <=> (float)$x[0]['anim_remuneration']);
    $recents = array_slice($open, 0, 6);

    anim_json([
        'prenom' => $user['prenom'] ?? '',
        'profil' => ['pct' => (int)round(100 * $ok / count($items)), 'manque' => $manque],
        'taches' => ['candidatures_attente' => (int)(($cand['En attente'] ?? 0) + ($cand["Liste d'attente"] ?? 0)), 'acceptees' => (int)($cand['Accepté'] ?? 0),
                     'messages' => $nonLus, 'notifs' => (int)$n->fetchColumn(), 'prefs_configurees' => $prefs['configure']],
        'pour_vous' => array_map($card, array_slice($scored, 0, 6)),
        'pres' => array_map($card, array_slice($pres, 0, 6)),
        'recents' => array_map(fn($c) => anim_carte($c), $recents),
        'paient' => array_map($card, array_slice($paient, 0, 6)),
        'ouverts' => count($open),
    ]);
} catch (Throwable $e) {
    error_log('anim_accueil: ' . $e->getMessage());
    anim_json(['error' => 'Service momentanément indisponible.'], 500);
}
