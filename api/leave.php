<?php
/**
 * API: 离开房间 / 心跳 / 退出房间
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Core\Session;
use App\Models\Room;
use App\Models\User;

$action = $_GET['action'] ?? $_POST['action'] ?? 'leave';

if ($action === 'heartbeat') {
    $userId = (int) Session::get('user_id');
    $roomId = (int) Session::get('room_id');

    if ($userId && $roomId && Room::isEnded($roomId)) {
        Security::jsonResponse(['success' => true, 'room_ended' => true]);
    }

    if ($userId) {
        User::heartbeat($userId);
    }

    $onlineMembers = ($userId && $roomId)
        ? User::getOnlineMembers($roomId, $userId)
        : [];

    Security::jsonResponse([
        'success' => true,
        'members' => ($userId && $roomId) ? User::getRoomMembersWithPresence($roomId, $userId) : [],
        'online_members' => $onlineMembers,
        'partner_online' => count($onlineMembers) > 0,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$userId = (int) Session::get('user_id');
$roomId = (int) Session::get('room_id');
$endRoom = !empty($_POST['end_room']);

// 彻底离开（聊天已结束后退出，清除会话）
$abandon = !empty($_POST['abandon']);

if ($abandon && $userId) {
    User::setOffline($userId);
    \App\Models\PushSubscription::deleteByUser($userId);
    Session::clearChatUser();
    Session::remove('chat_paused');
    Session::commit();
    Security::jsonResponse(['success' => true, 'redirect' => '/']);
}

// 主动退出房间：清空聊天记录并通知对方
if ($endRoom && $userId && $roomId) {
    requireCsrf();

    if (!Room::isEnded($roomId)) {
        Room::endChat($roomId, $userId);
    } else {
        User::setOffline($userId);
    }

    \App\Models\PushSubscription::deleteByUser($userId);
    Session::clearChatUser();
    Session::remove('chat_paused');
    Session::commit();

    Security::jsonResponse(['success' => true, 'redirect' => '/']);
}

// 暂时离开：仅离线，保留会话，可回到网站继续聊
$pauseLeave = !empty($_POST['pause']);

if ($pauseLeave && $userId && $roomId) {
    User::setOffline($userId);
    Session::set('chat_paused', true);
    Session::commit();

    Security::jsonResponse(['success' => true, 'redirect' => '/?paused=1']);
}

// 普通离开（页面关闭）：仅离线，保留会话以便再次打开 chat.php
if ($userId) {
    User::setOffline($userId);
}

Session::set('chat_paused', true);
Session::commit();

Security::jsonResponse(['success' => true, 'redirect' => '/?paused=1']);
