<?php
// api/start_conversation.php : ouvre (ou retrouve) la conversation entre le parent et le directeur d'un séjour.
require_once 'config.php';

if (!isset($_SESSION['user']['id'])) { sendJson(['error' => 'Connectez-vous pour contacter l\'organisateur.'], 403); }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$campId = (int)($input['campId'] ?? 0);
if (!$campId) { sendJson(['error' => 'Données manquantes'], 400); }

try {
    $myId = (int)$_SESSION['user']['id'];

    // Le destinataire est TOUJOURS le propriétaire du séjour (on ne fait pas confiance à l'identifiant envoyé)
    $stmtInfos = $pdo->prepare("
        SELECT o.user_id AS dir_id, u.prenom AS dir_prenom, u.nom AS dir_nom, c.nom AS camp_nom, o.email AS org_email
        FROM camps c
        JOIN organisateurs o ON c.organisateur_id = o.id
        JOIN users u ON o.user_id = u.id
        WHERE c.id = ?");
    $stmtInfos->execute([$campId]);
    $infos = $stmtInfos->fetch();
    if (!$infos) { sendJson(['error' => 'Séjour introuvable.'], 404); }
    $targetUserId = (int)$infos['dir_id'];
    if ($targetUserId === $myId) { sendJson(['error' => 'Vous êtes le directeur de ce séjour.'], 400); }

    $stmtCheck = $pdo->prepare("SELECT id FROM conversations WHERE camp_id = ?
                 AND ((user_1_id = ? AND user_2_id = ?) OR (user_1_id = ? AND user_2_id = ?))");
    $stmtCheck->execute([$campId, $myId, $targetUserId, $targetUserId, $myId]);
    $existing = $stmtCheck->fetch();
    if ($existing) { sendJson(['conversationId' => (int)$existing['id']]); }

    $pdo->prepare("INSERT INTO conversations (user_1_id, user_2_id, camp_id, last_message_at) VALUES (?, ?, ?, NOW())")
        ->execute([$myId, $targetUserId, $campId]);
    $newConvId = (int)$pdo->lastInsertId();

    // Message d'accueil automatique, au nom du directeur (lu d'office : il ne déclenche pas de notification)
    $mail = trim((string)$infos['org_email']);
    $auto = "Bonjour, vous contactez " . trim($infos['dir_prenom'] . ' ' . $infos['dir_nom']) . ", directeur du séjour « " . $infos['camp_nom'] . " ».\n\nPosez votre question ici, la réponse arrivera dans cette conversation."
          . ($mail !== '' ? "\nVous pouvez aussi écrire à l'organisme : $mail." : '');
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content, is_read, created_at) VALUES (?, ?, ?, 1, NOW())")
        ->execute([$newConvId, $targetUserId, $auto]);

    sendJson(['conversationId' => $newConvId]);
} catch (Exception $e) {
    error_log('start_conversation: ' . $e->getMessage());
    sendJson(['error' => 'Impossible d\'ouvrir la conversation.'], 500);
}
