<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

requireLogin();

if (!selectedRouterId() && currentRole() !== ROLE_SUPERADMIN) {
    $router = defaultRouterForUser(currentUser() ?? []);
    if ($router) {
        setSelectedRouter((int)$router['router_id'], $router['name']);
    }
}

$routerId = scopedRouterId(selectedRouterId());
if (!$routerId) {
    jsonResponse(false, 'No router selected.');
}

function chatMessages(int $routerId): array {
    $stmt = db()->prepare("
        SELECT g.message_id, g.router_id, g.user_id, g.message, g.created_at, g.unsent_at,
               u.firstname, u.lastname, u.username
        FROM messages g
        LEFT JOIN users u ON u.user_id = g.user_id
        WHERE g.router_id = ?
          AND g.created_at >= ?
        ORDER BY g.created_at DESC, g.message_id DESC
        LIMIT 50
    ");
    $stmt->execute([$routerId, appDayStart()]);
    $rows = array_reverse($stmt->fetchAll());
    $now = time();
    foreach ($rows as &$row) {
        $createdTs = strtotime($row['created_at']) ?: 0;
        $row['sender_name'] = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: ($row['username'] ?? 'User');
        $row['created_label'] = $createdTs ? date('g:i A', $createdTs) : '';
        $row['can_unsend'] = (int)$row['user_id'] === currentUserId()
            && empty($row['unsent_at'])
            && $createdTs
            && ($now - $createdTs) <= 600;
    }
    unset($row);
    return $rows;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'send') {
        $message = trim($_POST['message'] ?? '');
        if ($message === '') {
            jsonResponse(false, 'Message is required.');
        }
        if (mb_strlen($message) > 1000) {
            jsonResponse(false, 'Message is too long.');
        }
        db()->prepare("
            INSERT INTO messages (router_id, user_id, message, created_at)
            VALUES (?, ?, ?, ?)
        ")->execute([$routerId, currentUserId(), $message, appNow()]);
        jsonResponse(true, 'Message sent.', ['messages' => chatMessages($routerId)]);
    }

    if ($action === 'unsend') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        if (!$messageId) {
            jsonResponse(false, 'Message not found.');
        }
        $stmt = db()->prepare("
            SELECT message_id, user_id, router_id, created_at, unsent_at
            FROM messages
            WHERE message_id = ? AND router_id = ?
            LIMIT 1
        ");
        $stmt->execute([$messageId, $routerId]);
        $msg = $stmt->fetch();
        if (!$msg || (int)$msg['user_id'] !== currentUserId()) {
            jsonResponse(false, 'You can only unsend your own messages.');
        }
        if (!empty($msg['unsent_at'])) {
            jsonResponse(false, 'Message is already unsent.');
        }
        $createdTs = strtotime($msg['created_at']) ?: 0;
        if (!$createdTs || (time() - $createdTs) > 600) {
            jsonResponse(false, 'Messages can only be unsent within 10 minutes.');
        }
        db()->prepare("UPDATE messages SET unsent_at = ? WHERE message_id = ?")
            ->execute([appNow(), $messageId]);
        jsonResponse(true, 'Message unsent.', ['messages' => chatMessages($routerId)]);
    }

    jsonResponse(false, 'Unknown chat action.');
}

jsonResponse(true, 'Messages loaded.', ['messages' => chatMessages($routerId)]);
