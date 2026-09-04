<?php
/**
 * API: 暂时离开期间轮询新消息（用于桌面/移动通知）
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\MediaUrl;
use App\Core\Security;
use App\Models\Message;
use App\Models\Room;
use App\Models\Upload;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = requireChatUser();

if (Room::isEnded($user['room_id'])) {
    Security::jsonResponse(['success' => true, 'room_ended' => true, 'messages' => []]);
}

$lastId = (int) ($_GET['last_id'] ?? 0);
$messages = Message::getUpdates($user['room_id'], $lastId);

$formatted = [];
foreach ($messages as $msg) {
    if ($msg['sender'] === $user['sender']) {
        continue;
    }

    $item = [
        'id' => (int) $msg['id'],
        'sender' => $msg['sender'],
        'type' => $msg['type'],
        'content' => $msg['content'],
        'time' => $msg['created_at'],
        'preview' => \App\Services\PushNotificationService::buildPreview(
            $msg['type'],
            $msg['content'],
            $msg['file_name'] ?? null
        ),
    ];

    if ($msg['file_name']) {
        $item['file_name'] = $msg['file_name'];
    }

    $formatted[] = MediaUrl::enrichMessage($item);
}

Security::jsonResponse([
    'success' => true,
    'messages' => $formatted,
    'last_id' => !empty($formatted) ? end($formatted)['id'] : $lastId,
    'room_code' => $user['room_code'],
]);
