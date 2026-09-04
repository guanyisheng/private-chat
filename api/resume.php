<?php
/**
 * API: 继续聊天（从暂时离开恢复）
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Core\Session;
use App\Models\Room;
use App\Models\User;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$userId = (int) Session::get('user_id');
$roomId = (int) Session::get('room_id');
$token = Session::get('session_token', '');

if (!$userId || !$roomId) {
    Security::jsonResponse(['success' => false, 'error' => '会话已失效，请重新加入房间'], 401);
}

if (Room::isEnded($roomId)) {
    Session::clearChatUser();
    Session::commit();
    Security::jsonResponse(['success' => false, 'error' => '聊天已结束', 'redirect' => '/']);
}

$user = User::findById($userId);
if (!$user || (int) $user['room_id'] !== $roomId) {
    Session::clearChatUser();
    Session::commit();
    Security::jsonResponse(['success' => false, 'error' => '会话已失效，请重新加入房间'], 401);
}

User::setOnline($userId, $roomId, $token ?: $user['session_token']);
Session::remove('chat_paused');
Session::commit();

Security::jsonResponse([
    'success' => true,
    'redirect' => '/chat.php',
]);
