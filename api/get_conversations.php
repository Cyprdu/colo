<?php
// api/get_conversations.php : mes conversations (la plus récente en premier), avec le nombre de messages non lus.
require_once 'config.php';

if (!isset($_SESSION['user']['id'])) { sendJson([], 403); }
$myId = (int)$_SESSION['user']['id'];

try {
    $sql = "SELECT c.id AS conversation_id, c.last_message_at, c.camp_id,
            cmp.nom AS camp_nom, cmp.token AS camp_token, cmp.image_url AS camp_image,
            IF(c.user_1_id = :me1, u2.nom, u1.nom) AS contact_nom,
            IF(c.user_1_id = :me2, u2.prenom, u1.prenom) AS contact_prenom,
            IF(c.user_1_id = :me3, u2.id, u1.id) AS contact_id,
            (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_message,
            (SELECT sender_id FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_sender_id,
            (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_id <> :me4 AND is_read = 0) AS unread_count
            FROM conversations c
            JOIN users u1 ON c.user_1_id = u1.id
            JOIN users u2 ON c.user_2_id = u2.id
            LEFT JOIN camps cmp ON c.camp_id = cmp.id
            WHERE c.user_1_id = :me5 OR c.user_2_id = :me6
            ORDER BY c.last_message_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':me1' => $myId, ':me2' => $myId, ':me3' => $myId, ':me4' => $myId, ':me5' => $myId, ':me6' => $myId]);
    sendJson($stmt->fetchAll());
} catch (Exception $e) {
    error_log('get_conversations: ' . $e->getMessage());
    sendJson(['error' => 'Chargement impossible.'], 500);
}
