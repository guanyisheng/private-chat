<?php
/**
 * API: 获取历史消息
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = requireChatUser();

if (Room::isEnded($user['room_id'])) {
    Security::jsonResponse([
        'success' => true,
        'room_ended' => true,
        'messages' => [],
    ]);
}

$messages = Message::getByRoom($user['room_id'], 100);

$formatted = [];
foreach ($messages as $msg) {
    $formatted[] = Message::formatForClient($msg, $user['user_id']);
}

$onlineMembers = User::getOnlineMembers($user['room_id'], $user['user_id']);
$typingMembers = User::getTypingMembers($user['room_id'], $user['user_id']);

Security::jsonResponse([
    'success' => true,
    'messages' => $formatted,
    'members' => User::getRoomMembersWithPresence($user['room_id'], $user['user_id']),
    'online_members' => $onlineMembers,
    'typing_members' => $typingMembers,
    'partner_online' => count($onlineMembers) > 0,
    'partner_typing' => count($typingMembers) > 0,
    'flash_destroyed_ids' => Message::getDestroyedFlashIds($user['room_id']),
]);
