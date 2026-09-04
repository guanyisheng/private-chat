<?php
/**
 * API: 文件上传（图片 / 视频 / 文档）
 *
 * 仅接受 POST multipart/form-data，字段 file 必填。
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Logger;
use App\Core\Security;
use App\Models\Room;
use App\Services\FileUploadService;
use App\Services\PushNotificationService;

handleApiOptions(['POST', 'GET']);

// GET：健康检查（浏览器直接访问可确认接口已更新）
if (apiMethod() === 'GET') {
    Security::jsonResponse([
        'success' => true,
        'status' => 'ok',
        'message' => '上传接口正常，请从聊天页选择文件上传',
        'api_version' => 2,
    ]);
}

requirePost();

@set_time_limit(900);
@ini_set('max_execution_time', '900');
@ini_set('memory_limit', '512M');

try {
    requireCsrf();
    $user = requireChatUser();

    if (Room::isEnded($user['room_id'])) {
        Security::jsonResponse([
            'success' => false,
            'error' => '对方已退出房间，聊天已结束',
            'room_ended' => true,
        ]);
    }

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMax = ini_get('post_max_size') ?: 'unknown';
        Security::jsonResponse([
            'success' => false,
            'error' => $contentLength > 0
                ? '文件过大或未完整接收（post_max_size: ' . $postMax . '）'
                : '未收到文件，请重试',
        ]);
    }

    $file = $_FILES['file'];
    if (!isset($file['error'])) {
        Security::jsonResponse(['success' => false, 'error' => '上传数据无效']);
    }

    $asFlash = !empty($_POST['flash']);

    $service = new FileUploadService();
    $result = $service->handle(
        $file,
        $user['room_id'],
        $user['user_id'],
        $user['sender'],
        $asFlash
    );

    if (!$result['success']) {
        Security::jsonResponse(['success' => false, 'error' => $result['error']]);
    }

    $msg = $result['message'];

    try {
        PushNotificationService::notifyPartner(
            $user['room_id'],
            $user['user_id'],
            PushNotificationService::buildPreview(
                $msg['type'] ?? 'file',
                is_string($msg['content'] ?? null) ? $msg['content'] : '',
                $msg['file_name'] ?? null
            )
        );
    } catch (\Throwable $e) {
        Logger::warning('Upload push skipped: ' . $e->getMessage());
    }

    Security::jsonResponse([
        'success' => true,
        'message' => $msg,
    ]);
} catch (\Throwable $e) {
    Logger::error('Upload failed: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    $appConfig = require ROOT_PATH . '/config/app.php';
    $error = '上传失败，请稍后重试';
    if (!empty($appConfig['debug'])) {
        $error = '上传失败: ' . $e->getMessage();
    }

    Security::jsonResponse(['success' => false, 'error' => $error], 500);
}
