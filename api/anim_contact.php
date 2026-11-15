<?php
// api/anim_contact.php : ouvre (ou retrouve) la conversation animateur <-> directeur, à propos d'un séjour. POST JSON + CSRF.
//   animateur : {camp: <jeton du séjour>}  -> écrit au directeur du séjour (le destinataire vient de la base, jamais du navigateur)
//   directeur : {c: <jeton de candidature>} -> écrit au candidat d'une candidature reçue sur SON séjour
// Réponse : {url: "messagerie?conv_id=..."} (identifiant de conversation, déjà utilisé par la messagerie existante).
require_once 'config.php';
require_once 'animateurs.php';

$me = anim_user_id();
if (!$me) anim_json(['error' => 'Connectez-vous.'], 403);
anim_ensure($pdo);
$in = anim_body();

try {
    if (!empty($in['c'])) {
        anim_require_directeur();
        $cd = anim_candidature_du_directeur($pdo, (string)$in['c'], $me);
        if (!$cd) anim_json(['error' => 'Candidature introuvable.'], 404);
        $other = (int)$cd['user_id']; $campId = (int)$cd['camp_id'];
    } else {
        anim_require_animateur();
        if (!preg_match('/^[a-f0-9]{16,64}$/i', (string)($in['camp'] ?? ''))) anim_json(['error' => 'Séjour introuvable.'], 404);
        // Uniquement un séjour auquel l'animateur a candidaté
        $st = $pdo->prepare("SELECT c.id, o.user_id FROM candidatures cd JOIN camps c ON c.id = cd.camp_id JOIN organisateurs o ON o.id = c.organisateur_id WHERE c.token = ? AND cd.user_id = ?");
        $st->execute([$in['camp'], $me]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) anim_json(['error' => 'Candidature introuvable.'], 404);
        $other = (int)$r['user_id']; $campId = (int)$r['id'];
    }
    if ($other === $me) anim_json(['error' => 'Conversation impossible.'], 400);

    $st = $pdo->prepare("SELECT id FROM conversations WHERE camp_id = ? AND ((user_1_id = ? AND user_2_id = ?) OR (user_1_id = ? AND user_2_id = ?))");
    $st->execute([$campId, $me, $other, $other, $me]);
    $id = (int)$st->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO conversations (user_1_id, user_2_id, camp_id, last_message_at) VALUES (?, ?, ?, NOW())")->execute([$me, $other, $campId]);
        $id = (int)$pdo->lastInsertId();
    }
    anim_json(['url' => 'messagerie?conv_id=' . $id]);
} catch (Throwable $e) {
    error_log('anim_contact: ' . $e->getMessage());
    anim_json(['error' => "Impossible d'ouvrir la conversation."], 500);
}
