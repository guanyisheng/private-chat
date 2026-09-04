<?php
/**
 * API: 闪图阅后销毁
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\Message;
use App\Models\Room;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

requireCsrf();
$user = requireChatUser();

if (Room::isEnded($user['room_id'])) {
    Security::jsonResponse(['success' => false, 'error' => '聊天已结束', 'room_ended' => true]);
}

$messageId = (int) ($_POST['message_id'] ?? 0);
if ($messageId <= 0) {
    Security::jsonResponse(['success' => false, 'error' => '参数错误']);
}

if (!Message::destroyFlash($messageId, $user['room_id'])) {
    Security::jsonResponse(['success' => false, 'error' => '闪图不存在或已销毁']);
}

Security::jsonResponse([
    'success' => true,
    'message_id' => $messageId,
    'flash_destroyed_ids' => Message::getDestroyedFlashIds($user['room_id']),
]);
