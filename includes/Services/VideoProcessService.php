<?php
/**
 * 视频后台处理：压缩 → 上传 R2 → 更新消息
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\ChatStorage;
use App\Core\Logger;
use App\Core\MediaUrl;
use App\Models\Message;
use App\Models\Room;
use App\Models\Upload;
use App\Models\VideoJob;

class VideoProcessService
{
    /**
     * 后台启动处理（不阻塞上传响应）
     */
    public static function dispatchBackground(int $jobId): void
    {
        $script = ROOT_PATH . '/tools/process_video.php';
        if (!is_file($script)) {
            Logger::warning('process_video.php missing, processing inline', ['job_id' => $jobId]);
            self::processJob($jobId);
            return;
        }

        if (function_exists('exec')) {
            $php = PHP_BINARY ?? 'php';
            exec(sprintf(
                '%s %s %d > /dev/null 2>&1 &',
                escapeshellcmd($php),
                escapeshellarg($script),
                $jobId
            ));
            return;
        }

        Logger::warning('exec disabled, processing inline', ['job_id' => $jobId]);
        self::processJob($jobId);
    }

    /**
     * 处理单个任务
     */
    public static function processJob(int $jobId): void
    {
        @set_time_limit(900);
        @ini_set('max_execution_time', '900');
        @ini_set('memory_limit', '512M');

        $job = VideoJob::findById($jobId);
        if (!$job || $job['status'] !== VideoJob::STATUS_PENDING) {
            return;
        }

        if (!VideoJob::markProcessing($jobId)) {
            return;
        }

        $localPath = $job['local_path'];
        if (!is_readable($localPath)) {
            self::failJob($job, '本地视频文件不存在');
            return;
        }

        $r2 = new R2StorageService();
        if (!$r2->isEnabled()) {
            self::failJob($job, $r2->checkRequirements() ?? 'R2 未配置');
            return;
        }

        $uploadPath = $localPath;
        $uploadSize = (int) $job['original_size'];
        $uploadMime = $job['mime_type'];
        $storedName = $job['stored_name'];
        $tempFiles = [];
        $compressed = false;
        $poster = null;

        try {
            $videoService = new VideoCompressionService();

            if ($videoService->shouldCompress($uploadSize)) {
                $compressedFile = $videoService->compress($localPath);
                if ($compressedFile !== null) {
                    $tempFiles[] = $compressedFile;
                    $uploadPath = $compressedFile['path'];
                    $uploadSize = $compressedFile['size'];
                    $uploadMime = 'video/mp4';
                    $storedName = pathinfo($storedName, PATHINFO_FILENAME) . '.mp4';
                    $compressed = true;
                }
            }

            $poster = $videoService->extractPoster($uploadPath);
            if ($poster !== null) {
                $tempFiles[] = $poster;
            }
        } catch (\Throwable $e) {
            Logger::warning('Video job compress/poster: ' . $e->getMessage(), ['job_id' => $jobId]);
        }

        $objectKey = $r2->buildObjectKey($storedName);
        if (!$r2->uploadFile($uploadPath, $objectKey, $uploadMime)) {
            foreach ($tempFiles as $temp) {
                VideoCompressionService::cleanupTemp($temp);
            }
            self::failJob($job, '上传到云存储失败');
            return;
        }

        $thumbKey = null;
        if ($poster !== null) {
            $thumbName = 'thumb_' . pathinfo($storedName, PATHINFO_FILENAME) . '.jpg';
            $thumbKey = $r2->buildObjectKey($thumbName);
            if (!$r2->uploadFile($poster['path'], $thumbKey, 'image/jpeg')) {
                $thumbKey = null;
            }
        }

        foreach ($tempFiles as $temp) {
            VideoCompressionService::cleanupTemp($temp);
        }

        $messageId = (int) $job['message_id'];
        $message = Message::updateVideoContent($messageId, $objectKey, $uploadSize, $thumbKey);
        if (!$message) {
            $r2->deleteObject($objectKey);
            if ($thumbKey) {
                $r2->deleteObject($thumbKey);
            }
            self::failJob($job, '消息更新失败');
            return;
        }

        Upload::create([
            'message_id' => $messageId,
            'room_id' => (int) $job['room_id'],
            'original_name' => $job['original_name'],
            'stored_name' => $storedName,
            'file_path' => $objectKey,
            'thumb_path' => $thumbKey,
            'mime_type' => $uploadMime,
            'file_size' => $uploadSize,
            'file_type' => 'video',
        ]);

        VideoJob::markDone($jobId, $compressed);
        @unlink($localPath);

        try {
            PushNotificationService::notifyPartner(
                (int) $job['room_id'],
                (int) $job['user_id'],
                PushNotificationService::buildPreview('video', null, $job['original_name'])
            );
        } catch (\Throwable $e) {
            Logger::warning('Video job push skipped: ' . $e->getMessage());
        }

        Logger::info('Video job done', [
            'job_id' => $jobId,
            'message_id' => $messageId,
            'compressed' => $compressed,
        ]);
    }

    /**
     * 格式化已完成/进行中的视频消息（供 API）
     */
    public static function formatVideoMessage(array $message, ?array $job = null): array
    {
        $item = [
            'id' => (int) $message['id'],
            'sender' => $message['sender'],
            'type' => 'video',
            'status' => $message['status'],
            'time' => $message['created_at'],
            'file_name' => $message['file_name'],
            'file_size' => (int) $message['file_size'],
            'file_size_text' => Upload::formatSize((int) $message['file_size']),
        ];

        if (Message::isVideoPending($message['content'])) {
            $item['content'] = '';
            $item['processing'] = true;
            if ($job) {
                $item['video_status'] = $job['status'];
                if ($job['status'] === VideoJob::STATUS_FAILED) {
                    $item['processing'] = false;
                    $item['failed'] = true;
                    $item['error'] = $job['error_message'] ?? '视频处理失败';
                }
            }
            return $item;
        }

        $upload = Upload::findByMessageId((int) $message['id']);
        $thumbKey = $upload['thumb_path'] ?? null;

        $item['content'] = MediaUrl::resolve($message['content']);
        $item['thumb_path'] = $thumbKey ? MediaUrl::resolve($thumbKey) : null;
        $item['processing'] = false;

        if ($job && !empty($job['compressed'])) {
            $item['compressed'] = true;
            $item['original_size'] = (int) $job['original_size'];
        }

        return $item;
    }

    private static function failJob(array $job, string $error): void
    {
        VideoJob::markFailed((int) $job['id'], $error);
        Message::markVideoFailed((int) $job['message_id']);
        if (!empty($job['local_path']) && is_file($job['local_path'])) {
            @unlink($job['local_path']);
        }
        Logger::error('Video job failed: ' . $error, ['job_id' => $job['id']]);
    }
}
