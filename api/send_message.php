<?php
// api/send_message.php : envoie un message dans une conversation dont on est participant.
require_once 'config.php';

if (!isset($_SESSION['user']['id'])) { sendJson(['error' => 'Connectez-vous.'], 403); }
$myId = (int)$_SESSION['user']['id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$convId = (int)($input['conv_id'] ?? 0);
$content = trim((string)($input['content'] ?? ''));
if (!$convId || $content === '') { sendJson(['error' => 'Message vide.'], 400); }
if (mb_strlen($content) > 4000) { sendJson(['error' => 'Message trop long (4000 caractères maximum).'], 400); }

try {
    // Il faut faire partie de la conversation
    $st = $pdo->prepare("SELECT id FROM conversations WHERE id = ? AND (user_1_id = ? OR user_2_id = ?)");
    $st->execute([$convId, $myId, $myId]);
    if (!$st->fetch()) { sendJson(['error' => 'Conversation introuvable.'], 403); }

    $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content) VALUES (?, ?, ?)")->execute([$convId, $myId, $content]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE conversations SET last_message_at = NOW() WHERE id = ?")->execute([$convId]);

    $row = $pdo->prepare("SELECT id, content, sender_id, created_at, is_read FROM messages WHERE id = ?");
    $row->execute([$id]);
    sendJson(['success' => true, 'message' => $row->fetch()]);
} catch (Exception $e) {
    error_log('send_message: ' . $e->getMessage());
    sendJson(['error' => "Envoi impossible pour le moment."], 500);
}
