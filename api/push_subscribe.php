<?php
/**
 * API: 注册 Web Push 订阅
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\PushSubscription;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

requireCsrf();
$user = requireChatUser();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data) || empty($data['endpoint'])) {
    Security::jsonResponse(['success' => false, 'error' => '订阅数据无效']);
}

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

if (!PushSubscription::upsert($user['user_id'], $user['room_id'], $data, $ua)) {
    Security::jsonResponse(['success' => false, 'error' => '保存订阅失败']);
}

Security::jsonResponse(['success' => true]);
