<?php
/**
 * API: 标记消息已读
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\Message;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

requireCsrf();
$user = requireChatUser();

// 标记对方所有未读消息为已读
Message::markAsRead($user['room_id'], $user['user_id']);

Security::jsonResponse(['success' => true]);
