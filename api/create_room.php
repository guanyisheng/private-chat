<?php
/**
 * API: 创建房间
 *
 * GET  健康检查
 * POST 创建房间（FormData 或 JSON）
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\AppUrl;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Security;
use App\Models\Room;

handleApiOptions(['GET', 'POST']);

$appConfig = require ROOT_PATH . '/config/app.php';
$debug = !empty($appConfig['debug']);

/**
 * 解析 POST 参数（兼容 FormData / JSON）
 */
function parseCreateRoomInput(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            $data = [];
        }
    } else {
        $data = $_POST;
    }

    $useRandom = !empty($data['use_random']);
    $roomCode = trim((string) ($data['room_code'] ?? ''));
    $enablePassword = !empty($data['enable_password']);
    $password = trim((string) ($data['password'] ?? ''));
    $csrfToken = (string) ($data['_csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '');

    return [
        'use_random' => $useRandom,
        'room_code' => $roomCode,
        'enable_password' => $enablePassword,
        'password' => $password,
        'csrf_token' => $csrfToken,
    ];
}

/**
 * GET 健康检查
 */
function respondCreateRoomHealth(bool $debug): void
{
    $dbOk = false;
    $dbError = null;
    $schema = [
        'room_code' => false,
        'room_name' => false,
        'password_hash' => false,
    ];

    try {
        Database::fetchOne('SELECT 1 AS ok');
        $dbOk = true;

        $columns = Room::getTableColumns();
        foreach (array_keys($schema) as $col) {
            $schema[$col] = in_array($col, $columns, true);
        }
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
    }

    $schemaOk = $schema['room_code'] && $schema['room_name'];

    Security::jsonResponse([
        'success' => true,
        'status' => 'ok',
        'api_version' => 3,
        'database' => $dbOk ? 'connected' : 'error',
        'database_error' => $dbError,
        'rooms_schema' => $schema,
        'rooms_schema_ok' => $schemaOk,
        'message' => '创建房间接口正常。请从首页提交表单创建房间。',
    ]);
}

if (apiMethod() === 'GET') {
    respondCreateRoomHealth($debug);
}

if (apiMethod() !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $input = parseCreateRoomInput();

    if (!Security::validateCsrfToken($input['csrf_token'])) {
        Security::jsonResponse([
            'success' => false,
            'error' => 'CSRF 验证失败',
            'hint' => '请刷新首页后重试',
        ], 403);
    }

    if ($input['use_random']) {
        $roomCode = Room::generateRoomCode();
    } else {
        if (!Security::validateRoomCode($input['room_code'])) {
            Security::jsonResponse(['success' => false, 'error' => '房间号须为4-12位数字']);
        }
        if (Room::findByCode($input['room_code']) !== null) {
            Security::jsonResponse(['success' => false, 'error' => '该房间号已被使用，请换一个']);
        }
        $roomCode = $input['room_code'];
    }

    $password = null;
    if ($input['enable_password']) {
        if (strlen($input['password']) < 4) {
            Security::jsonResponse(['success' => false, 'error' => '房间密码至少4位']);
        }
        if (strlen($input['password']) > 32) {
            Security::jsonResponse(['success' => false, 'error' => '房间密码最多32位']);
        }
        $password = $input['password'];
    }

    $room = Room::createWithOptions($roomCode, $password);
    if ($room === null) {
        Security::jsonResponse([
            'success' => false,
            'error' => '房间写入数据库失败',
            'hint' => '请执行 sql/fix_rooms_columns.sql，并确认 includes/Core/Database.php 已上传',
        ]);
    }

    $inviteUrl = AppUrl::roomInviteLink($roomCode);
    $shareText = sprintf(
        "我在 %s 发起了私密聊天，点击链接即可加入：\n%s",
        $appConfig['app_name'] ?? 'Private Chat',
        $inviteUrl
    );

    Security::jsonResponse([
        'success' => true,
        'room_code' => $roomCode,
        'invite_url' => $inviteUrl,
        'share_text' => $shareText,
        'has_password' => Room::hasPassword($room),
        'api_version' => 3,
    ]);
} catch (\Throwable $e) {
    Logger::error('create_room failed: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    $message = $debug ? $e->getMessage() : '房间创建失败，请刷新页面重试';

    Security::jsonResponse([
        'success' => false,
        'error' => $message,
        'hint' => '访问 /api/create_room.php 可检查数据库与表结构',
    ], 500);
}
