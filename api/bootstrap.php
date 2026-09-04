<?php
/**
 * API 基础入口 - 统一加载和错误处理
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Security;
use App\Core\Logger;

// 设置 JSON 响应头
header('Content-Type: application/json; charset=utf-8');

/**
 * 获取 HTTP 方法（兼容部分反向代理）
 */
function apiMethod(): string
{
    $method = $_SERVER['REQUEST_METHOD']
        ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
        ?? 'GET';

    return strtoupper(trim((string) $method));
}

/**
 * 处理 CORS 预检 OPTIONS
 */
function handleApiOptions(array $allowedMethods = ['POST']): void
{
    if (apiMethod() !== 'OPTIONS') {
        return;
    }

    $methods = array_unique(array_merge($allowedMethods, ['OPTIONS']));
    header('Allow: ' . implode(', ', $methods));
    header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

/**
 * 要求 POST 请求
 */
function requirePost(): void
{
    if (apiMethod() === 'POST') {
        return;
    }

    Security::jsonResponse([
        'success' => false,
        'error' => '请使用 POST 上传文件',
        'method' => apiMethod(),
    ], 405);
}

// CORS（如需跨域可取消注释）
// header('Access-Control-Allow-Origin: *');

/**
 * 验证 CSRF Token（POST 请求）
 */
function requireCsrf(): void
{
    $token = $_POST['_csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!Security::validateCsrfToken($token)) {
        $hint = empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])
            ? '（可能是上传过大超过 post_max_size，或会话已过期，请刷新页面重试）'
            : '（会话可能已过期，请刷新页面后重试）';
        Security::jsonResponse(['success' => false, 'error' => 'CSRF 验证失败' . $hint], 403);
    }
}

/**
 * 要求聊天用户已登录
 */
function requireChatUser(): array
{
    $userId = (int) \App\Core\Session::get('user_id');
    $roomId = (int) \App\Core\Session::get('room_id');

    if (!$userId || !$roomId) {
        Security::jsonResponse(['success' => false, 'error' => '未登录'], 401);
    }

    $user = \App\Models\User::findById($userId);
    if (!$user || (int) $user['room_id'] !== $roomId) {
        Security::jsonResponse(['success' => false, 'error' => '会话无效'], 401);
    }

    return [
        'user_id' => $userId,
        'room_id' => $roomId,
        'sender' => $user['nickname'],
        'nickname' => $user['nickname'],
        'avatar' => $user['avatar'] ?? null,
        'room_code' => \App\Core\Session::get('room_code', ''),
    ];
}

/**
 * 全局异常处理
 */
set_exception_handler(function (\Throwable $e) {
    Logger::error('API Exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    $appConfig = require ROOT_PATH . '/config/app.php';
    $errorMsg = '服务器内部错误';
    if (!empty($appConfig['debug'])) {
        $errorMsg = $e->getMessage();
    }

    Security::jsonResponse(['success' => false, 'error' => $errorMsg], 500);
});
