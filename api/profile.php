<?php
/**
 * API: 更新个人资料（昵称 / 头像）
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Core\Session;
use App\Models\User;
use App\Services\AvatarService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

requireCsrf();
$userCtx = requireChatUser();
$config = require ROOT_PATH . '/config/app.php';

$userId = $userCtx['user_id'];
$roomId = $userCtx['room_id'];
$user = User::findById($userId);

if (!$user) {
    Security::jsonResponse(['success' => false, 'error' => '用户不存在'], 404);
}

$nickname = trim($_POST['nickname'] ?? $user['nickname']);
$avatarSvg = trim($_POST['avatar_svg'] ?? '');
$avatarLetter = !empty($_POST['avatar_letter']);

if (!Security::validateNickname($nickname)) {
    Security::jsonResponse([
        'success' => false,
        'error' => '昵称须为 ' . ($config['nickname_min_length'] ?? 2) . '-' . ($config['nickname_max_length'] ?? 20) . ' 个字符',
    ]);
}

if (!User::isNicknameAvailable($roomId, $nickname, $userId)) {
    Security::jsonResponse(['success' => false, 'error' => '该昵称已被使用']);
}

$newAvatar = null;
$hasAvatarUpload = !empty($_FILES['avatar']) && is_array($_FILES['avatar'])
    && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

if ($hasAvatarUpload) {
    $path = AvatarService::handleUpload($_FILES['avatar'], $userId);
    if ($path === null) {
        Security::jsonResponse(['success' => false, 'error' => '头像上传失败，请使用 JPG/PNG/WebP，不超过 2MB']);
    }
    AvatarService::deleteLocalFile($user['avatar'] ?? null);
    $newAvatar = $path;
} elseif ($avatarSvg !== '') {
    $preset = AvatarService::normalizePreset($avatarSvg);
    if ($preset === '') {
        Security::jsonResponse(['success' => false, 'error' => '头像选择无效']);
    }
    AvatarService::deleteLocalFile($user['avatar'] ?? null);
    $newAvatar = $preset;
} elseif ($avatarLetter) {
    AvatarService::deleteLocalFile($user['avatar'] ?? null);
    $newAvatar = AvatarService::normalizeLetter();
}

if ($newAvatar !== null) {
    User::updateProfile($userId, $nickname, $newAvatar);
} else {
    User::updateProfile($userId, $nickname);
}

Session::set('nickname', $nickname);
Session::commit();

$updated = User::findById($userId);

Security::jsonResponse([
    'success' => true,
    'nickname' => $updated['nickname'] ?? $nickname,
    'avatar' => AvatarService::formatForClient($updated['avatar'] ?? null, $updated['nickname'] ?? $nickname),
]);
