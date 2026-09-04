<?php
/**
 * API: 导出聊天记录 ZIP（退出前保存）
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Core\Session;
use App\Models\Room;
use App\Services\ChatExportService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

requireCsrf();

$userId = Session::get('user_id');
$roomId = (int) Session::get('room_id');
$roomCode = Session::get('room_code', '');

if (!$userId || !$roomId || $roomCode === '') {
    http_response_code(401);
    exit('未登录');
}

if (Room::isEnded($roomId)) {
    http_response_code(400);
    exit('聊天已结束，无法导出');
}

@set_time_limit(600);
@ini_set('memory_limit', '512M');

$zipPath = ChatExportService::exportToZip($roomId, $roomCode);

if ($zipPath === null || !is_file($zipPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '导出失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filename = 'chat_' . $roomCode . '_' . date('Ymd_His') . '.zip';
$fileSize = filesize($zipPath);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($zipPath);
unlink($zipPath);
exit;
