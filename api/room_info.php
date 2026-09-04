<?php
/**
 * API: 查询房间信息（加入前）
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\Room;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$roomCode = trim($_GET['room'] ?? $_GET['room_code'] ?? '');

if (!Security::validateRoomCode($roomCode)) {
    Security::jsonResponse(['success' => false, 'error' => '房间号格式不正确', 'exists' => false]);
}

$room = Room::findByCode($roomCode);

if (!$room) {
    Security::jsonResponse([
        'success' => true,
        'exists' => false,
        'has_password' => false,
    ]);
}

Security::jsonResponse([
    'success' => true,
    'exists' => true,
    'has_password' => Room::hasPassword($room),
    'is_banned' => !empty($room['is_banned']),
    'is_ended' => !empty($room['ended_at']),
    'room_name' => $room['room_name'],
]);
