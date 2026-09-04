<?php
/**
 * API: 轮询新消息
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
        'last_id' => (int) ($_GET['last_id'] ?? 0),
    ]);
}

$lastId = (int) ($_GET['last_id'] ?? 0);

User::heartbeat($user['user_id']);

$messages = Message::getUpdates($user['room_id'], $lastId);

$formatted = [];
foreach ($messages as $msg) {
    $item = Message::formatForClient($msg, $user['user_id']);
    $formatted[] = $item;

    if ((int) $msg['user_id'] !== $user['user_id'] && $msg['status'] === 'sent') {
        Message::updateStatus((int) $msg['id'], 'delivered');
    }
}

$onlineMembers = User::getOnlineMembers($user['room_id'], $user['user_id']);
$typingMembers = User::getTypingMembers($user['room_id'], $user['user_id']);

Security::jsonResponse([
    'success' => true,
    'messages' => $formatted,
    'last_id' => !empty($formatted) ? end($formatted)['id'] : $lastId,
    'members' => User::getRoomMembersWithPresence($user['room_id'], $user['user_id']),
    'online_members' => $onlineMembers,
    'typing_members' => $typingMembers,
    'partner_online' => count($onlineMembers) > 0,
    'partner_typing' => count($typingMembers) > 0,
    'flash_destroyed_ids' => Message::getDestroyedFlashIds($user['room_id']),
]);
