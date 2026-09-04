<?php
/**
 * API: 加入房间（支持自定义昵称与头像）
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Core\Session;
use App\Models\Room;
use App\Models\User;
use App\Services\AvatarService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

requireCsrf();

$roomCode = trim($_POST['room_code'] ?? '');
$password = trim($_POST['password'] ?? '');
$restoreToken = trim($_POST['restore_token'] ?? '');
$nicknameInput = trim($_POST['nickname'] ?? '');
$avatarSvg = trim($_POST['avatar_svg'] ?? '');
$avatarLetter = !empty($_POST['avatar_letter']);
$avatarColor = trim($_POST['avatar_color'] ?? '');

if (!Security::validateRoomCode($roomCode)) {
    Security::jsonResponse(['success' => false, 'error' => '房间号格式不正确（4-12位数字）']);
}

$room = Room::findByCode($roomCode);
if (!$room) {
    Security::jsonResponse(['success' => false, 'error' => '房间不存在，请确认房间号或向发起人索取邀请链接']);
}

if ($room['is_banned']) {
    Security::jsonResponse(['success' => false, 'error' => '该房间已被封禁']);
}

if (!Room::verifyPassword($room, $password)) {
    Security::jsonResponse(['success' => false, 'error' => '房间密码错误', 'need_password' => true]);
}

$config = require ROOT_PATH . '/config/app.php';
$roomId = (int) $room['id'];
$maxUsers = (int) $config['max_users_per_room'];

$existingUserId = Session::get('user_id');
$existingRoomId = Session::get('room_id');

if (!empty($room['ended_at'])) {
    if (!$existingUserId || $existingRoomId !== $roomId) {
        if (Room::getOnlineCount($roomId) > 0) {
            Security::jsonResponse(['success' => false, 'error' => '房间已关闭，请稍后再试']);
        }
        Room::resetForNewChat($roomId);
        $room = Room::findById($roomId);
    }
}

/**
 * 登录成功响应
 */
$loginResponse = static function (array $user, bool $restored = false) use ($roomCode): void {
    User::setOnline((int) $user['id'], (int) $user['room_id'], $user['session_token']);

    Session::set('user_id', (int) $user['id']);
    Session::set('room_id', (int) $user['room_id']);
    Session::set('room_code', $roomCode);
    Session::set('nickname', $user['nickname']);
    Session::set('session_token', $user['session_token']);
    Session::remove('chat_paused');
    Session::commit();

    Security::jsonResponse([
        'success' => true,
        'redirect' => '/chat.php',
        'nickname' => $user['nickname'],
        'avatar' => AvatarService::formatForClient($user['avatar'] ?? null, $user['nickname']),
        'session_token' => $user['session_token'],
        'restored' => $restored,
    ]);
};

// 已有会话且同一房间
if ($existingUserId && $existingRoomId === $roomId) {
    $user = User::findById((int) $existingUserId);
    if ($user) {
        $loginResponse($user);
    }
}

// 用本地 token 恢复身份
if ($restoreToken !== '') {
    $restored = User::findInRoomByToken($roomId, $restoreToken);
    if ($restored) {
        $loginResponse($restored, true);
    }
}

$onlineCount = Room::getOnlineCount($roomId);
$userCount = count(User::getUsersInRoom($roomId));
if ($onlineCount >= $maxUsers || $userCount >= $maxUsers) {
    Security::jsonResponse([
        'success' => false,
        'error' => '房间人数已满（最多 ' . $maxUsers . ' 人）。若您曾在此房间聊天，请使用相同设备加入以恢复身份',
    ]);
}

// 新用户需设置昵称
$nickname = $nicknameInput !== '' ? $nicknameInput : User::suggestNickname($roomId);

if (!Security::validateNickname($nickname)) {
    Security::jsonResponse([
        'success' => false,
        'error' => '昵称须为 ' . ($config['nickname_min_length'] ?? 2) . '-' . ($config['nickname_max_length'] ?? 20) . ' 个字符，支持中文、字母、数字',
        'need_profile' => true,
    ]);
}

if (!User::isNicknameAvailable($roomId, $nickname)) {
    Security::jsonResponse([
        'success' => false,
        'error' => '该昵称已被使用，请换一个',
        'need_profile' => true,
    ]);
}

$avatarValue = AvatarService::normalizeLetter();
if ($avatarSvg !== '') {
    $preset = AvatarService::normalizePreset($avatarSvg);
    if ($preset === '') {
        Security::jsonResponse(['success' => false, 'error' => '头像选择无效', 'need_profile' => true]);
    }
    $avatarValue = $preset;
} elseif ($avatarColor !== '') {
    $colorVal = AvatarService::normalizeColor($avatarColor);
    if ($colorVal === '') {
        Security::jsonResponse(['success' => false, 'error' => '头像颜色无效', 'need_profile' => true]);
    }
    $avatarValue = $colorVal;
}

$user = User::create($roomId, $nickname, $avatarValue);
if (!$user) {
    Security::jsonResponse(['success' => false, 'error' => '加入房间失败']);
}

// 可选上传头像图片
if (!empty($_FILES['avatar']) && is_array($_FILES['avatar'])) {
    $path = AvatarService::handleUpload($_FILES['avatar'], (int) $user['id']);
    if ($path !== null) {
        User::updateProfile((int) $user['id'], $nickname, $path);
        $user = User::findById((int) $user['id']) ?? $user;
    }
}

$loginResponse($user);
