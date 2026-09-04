<?php
/**
 * API: 发送文本消息
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Services\PushNotificationService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

requireCsrf();
$user = requireChatUser();

if (Room::isEnded($user['room_id'])) {
    Security::jsonResponse(['success' => false, 'error' => '对方已退出房间，聊天已结束', 'room_ended' => true]);
}

$content = trim($_POST['content'] ?? '');
$replyToId = (int) ($_POST['reply_to_id'] ?? 0);

if ($content === '') {
    Security::jsonResponse(['success' => false, 'error' => '消息不能为空']);
}

if (mb_strlen($content) > 5000) {
    Security::jsonResponse(['success' => false, 'error' => '消息过长（最大5000字符）']);
}

if ($replyToId > 0) {
    $replyMsg = Message::findById($replyToId);
    if (!$replyMsg || (int) $replyMsg['room_id'] !== $user['room_id']) {
        $replyToId = 0;
    }
}

User::setTyping($user['user_id'], false);

$message = Message::create([
    'room_id' => $user['room_id'],
    'user_id' => $user['user_id'],
    'sender' => $user['sender'],
    'type' => 'text',
    'content' => $content,
    'reply_to_id' => $replyToId > 0 ? $replyToId : null,
]);

if (!$message) {
    Security::jsonResponse(['success' => false, 'error' => '发送失败']);
}

try {
    PushNotificationService::notifyPartner(
        $user['room_id'],
        $user['user_id'],
        PushNotificationService::buildPreview('text', $content)
    );
} catch (\Throwable $e) {
    // 推送失败不影响发送
}

$formatted = Message::formatForClient($message, $user['user_id']);

Security::jsonResponse([
    'success' => true,
    'message' => $formatted,
]);
