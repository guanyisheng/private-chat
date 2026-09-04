<?php
/**
 * API: 查询视频异步处理状态
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\Message;
use App\Models\VideoJob;
use App\Services\VideoProcessService;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = requireChatUser();

$idsParam = trim($_GET['ids'] ?? '');
if ($idsParam === '') {
    Security::jsonResponse(['success' => true, 'updates' => []]);
}

$messageIds = array_values(array_filter(array_map('intval', explode(',', $idsParam))));
$messageIds = array_slice(array_unique($messageIds), 0, 20);

$updates = [];
foreach ($messageIds as $messageId) {
    if ($messageId <= 0) {
        continue;
    }

    $message = Message::findById($messageId);
    if (!$message || (int) $message['room_id'] !== $user['room_id'] || $message['type'] !== 'video') {
        continue;
    }

    $job = VideoJob::findByMessageId($messageId);
    $formatted = VideoProcessService::formatVideoMessage($message, $job);
    $formatted['is_mine'] = $message['sender'] === $user['sender'];

    $status = 'ready';
    if (!empty($formatted['processing'])) {
        $status = $job['status'] ?? VideoJob::STATUS_PENDING;
    } elseif (!empty($formatted['failed'])) {
        $status = VideoJob::STATUS_FAILED;
    }

    $updates[] = [
        'id' => $messageId,
        'status' => $status,
        'message' => $formatted,
    ];
}

Security::jsonResponse(['success' => true, 'updates' => $updates]);
