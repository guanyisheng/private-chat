<?php
/**
 * 文件上传服务 - 图片/视频存 Cloudflare R2，文档存本地
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\MediaUrl;
use App\Core\Security;
use App\Models\Message;
use App\Models\Upload;

class FileUploadService
{
    private array $config;
    private R2StorageService $r2;

    public function __construct()
    {
        $appConfig = require ROOT_PATH . '/config/app.php';
        $this->config = $appConfig['upload'];
        $this->r2 = new R2StorageService();
    }

    /**
     * 处理文件上传
     */
    public function handle(array $file, int $roomId, int $userId, string $sender, bool $asFlash = false): array
    {
        try {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return $this->error($this->uploadErrorMessage($file['error']));
            }

            $originalName = Security::sanitizeFilename($file['name']);
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            $fileType = $this->detectFileType($ext);
            if ($fileType === null) {
                return $this->error('不支持的文件类型');
            }

            if ($asFlash) {
                if ($fileType !== 'image') {
                    return $this->error('闪图仅支持图片格式');
                }
            }

            $typeConfig = $this->config[$fileType];

            if ($file['size'] > $typeConfig['max_size']) {
                $maxMb = round($typeConfig['max_size'] / 1024 / 1024);
                return $this->error("文件大小超过限制（最大 {$maxMb}MB）");
            }

            if (!Security::validateExtension($originalName, $typeConfig['extensions'])) {
                return $this->error('不允许的文件扩展名');
            }

            if (!Security::validateMimeType($file['tmp_name'], $typeConfig['mime_types'])) {
                return $this->error('文件类型验证失败');
            }

            $storedName = Security::generateToken(16) . '.' . $ext;
            $mimeType = Security::getMimeType($file['tmp_name']);

            if (in_array($fileType, ['image', 'video'], true)) {
                $messageType = $asFlash ? 'flash' : $fileType;
                return $this->handleR2Upload(
                    $file,
                    $roomId,
                    $userId,
                    $sender,
                    $fileType,
                    $storedName,
                    $originalName,
                    $mimeType,
                    $messageType
                );
            }

            return $this->handleLocalUpload($file, $roomId, $userId, $sender, $storedName, $originalName, $mimeType);
        } catch (\Throwable $e) {
            Logger::error('FileUploadService handle error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->error('上传处理失败: ' . $e->getMessage());
        }
    }

    /**
     * 上传图片/视频到 R2（视频压缩由浏览器完成，服务端仅存储）
     */
    private function handleR2Upload(
        array $file,
        int $roomId,
        int $userId,
        string $sender,
        string $fileType,
        string $storedName,
        string $originalName,
        string $mimeType,
        string $messageType = ''
    ): array {
        if ($messageType === '') {
            $messageType = $fileType;
        }

        if (!$this->r2->isEnabled()) {
            $requirementError = $this->r2->checkRequirements();
            return $this->error($requirementError ?? 'R2 存储未配置，请联系管理员');
        }

        $uploadPath = $file['tmp_name'];
        $uploadSize = (int) $file['size'];
        $uploadMime = $mimeType;

        $objectKey = $this->r2->buildObjectKey($storedName);

        if (!$this->r2->uploadFile($uploadPath, $objectKey, $uploadMime)) {
            return $this->error('文件上传到云存储失败，请检查 R2 配置');
        }

        $thumbKey = null;
        try {
            if ($messageType === 'flash') {
                $thumbKey = $this->uploadBlurredThumbnailToR2($file['tmp_name'], $storedName, $mimeType);
            } elseif ($fileType === 'image') {
                $thumbKey = $this->uploadThumbnailToR2($file['tmp_name'], $storedName, $mimeType);
            } elseif ($fileType === 'video') {
                $thumbKey = $this->uploadVideoPosterToR2($uploadPath, $storedName);
            }
        } catch (\Throwable $e) {
            Logger::warning('Thumbnail skipped: ' . $e->getMessage());
        }

        $message = Message::create([
            'room_id' => $roomId,
            'user_id' => $userId,
            'sender' => $sender,
            'type' => $messageType,
            'content' => $objectKey,
            'file_name' => $originalName,
            'file_size' => $uploadSize,
            'thumb_path' => $thumbKey,
        ]);

        if (!$message) {
            $this->r2->deleteObject($objectKey);
            if ($thumbKey) {
                $this->r2->deleteObject($thumbKey);
            }
            return $this->error('消息创建失败（请确认已执行 sql/migration_upgrade_all.sql）');
        }

        Upload::create([
            'message_id' => (int) $message['id'],
            'room_id' => $roomId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_path' => $objectKey,
            'thumb_path' => $thumbKey,
            'mime_type' => $uploadMime,
            'file_size' => $uploadSize,
            'file_type' => $fileType,
        ]);

        $formatted = $this->formatMessage($message, $userId);
        if ($messageType === 'flash') {
            $formatted['is_flash'] = true;
        }

        return [
            'success' => true,
            'message' => $formatted,
        ];
    }

    /**
     * 提取视频封面（需服务器 ffmpeg，失败不影响上传）
     */
    private function uploadVideoPosterToR2(string $sourcePath, string $storedName): ?string
    {
        $videoService = new VideoCompressionService();
        $poster = $videoService->extractPoster($sourcePath);
        if ($poster === null) {
            return null;
        }

        try {
            $thumbName = 'thumb_' . pathinfo($storedName, PATHINFO_FILENAME) . '.jpg';
            $thumbKey = $this->r2->buildObjectKey($thumbName);
            if (!$this->r2->uploadFile($poster['path'], $thumbKey, 'image/jpeg')) {
                return null;
            }
            return $thumbKey;
        } finally {
            VideoCompressionService::cleanupTemp($poster);
        }
    }

    /**
     * 上传文档到本地
     */
    private function handleLocalUpload(
        array $file,
        int $roomId,
        int $userId,
        string $sender,
        string $storedName,
        string $originalName,
        string $mimeType
    ): array {
        $uploadDir = ROOT_PATH . '/uploads/' . date('Y/m');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filePath = $uploadDir . '/' . $storedName;
        $relativePath = 'uploads/' . date('Y/m') . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            Logger::error('File move failed', ['name' => $originalName]);
            return $this->error('文件保存失败');
        }

        $message = Message::create([
            'room_id' => $roomId,
            'user_id' => $userId,
            'sender' => $sender,
            'type' => 'file',
            'content' => $relativePath,
            'file_name' => $originalName,
            'file_size' => $file['size'],
            'thumb_path' => null,
        ]);

        if (!$message) {
            unlink($filePath);
            return $this->error('消息创建失败');
        }

        Upload::create([
            'message_id' => (int) $message['id'],
            'room_id' => $roomId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_path' => $relativePath,
            'thumb_path' => null,
            'mime_type' => $mimeType,
            'file_size' => $file['size'],
            'file_type' => 'file',
        ]);

        return [
            'success' => true,
            'message' => $this->formatMessage($message, $userId),
        ];
    }

    /**
     * 生成缩略图并上传到 R2
     */
    private function uploadThumbnailToR2(string $sourcePath, string $storedName, string $mimeType): ?string
    {
        $thumbData = $this->createThumbnailData($sourcePath, $mimeType);
        if ($thumbData === null) {
            return null;
        }

        $thumbName = 'thumb_' . $storedName;
        $thumbKey = $this->r2->buildObjectKey($thumbName);

        if (!$this->r2->uploadBody($thumbData['body'], $thumbKey, $thumbData['mime'])) {
            return null;
        }

        return $thumbKey;
    }

    /**
     * 闪图模糊缩略图（列表不显示原图）
     */
    private function uploadBlurredThumbnailToR2(string $sourcePath, string $storedName, string $mimeType): ?string
    {
        $thumbData = $this->createBlurredThumbnailData($sourcePath, $mimeType);
        if ($thumbData === null) {
            return $this->uploadThumbnailToR2($sourcePath, $storedName, $mimeType);
        }

        $thumbName = 'thumb_flash_' . $storedName;
        $thumbKey = $this->r2->buildObjectKey($thumbName);

        if (!$this->r2->uploadBody($thumbData['body'], $thumbKey, $thumbData['mime'])) {
            return null;
        }

        return $thumbKey;
    }

    /**
     * 生成强模糊缩略图
     */
    private function createBlurredThumbnailData(string $sourcePath, string $mime): ?array
    {
        $thumbData = $this->createThumbnailData($sourcePath, $mime);
        if ($thumbData === null || !extension_loaded('gd')) {
            return null;
        }

        $img = @imagecreatefromstring($thumbData['body']);
        if ($img === false) {
            return $thumbData;
        }

        for ($i = 0; $i < 12; $i++) {
            @imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
        }

        ob_start();
        imagejpeg($img, null, 60);
        $body = ob_get_clean();
        imagedestroy($img);

        if ($body === false || $body === '') {
            return $thumbData;
        }

        return ['body' => $body, 'mime' => 'image/jpeg'];
    }

    /**
     * 检测文件类型分类
     */
    private function detectFileType(string $ext): ?string
    {
        foreach (['image', 'video', 'file'] as $type) {
            if (in_array($ext, $this->config[$type]['extensions'], true)) {
                return $type;
            }
        }
        return null;
    }

    /**
     * 生成缩略图二进制数据
     */
    private function createThumbnailData(string $sourcePath, string $mime): ?array
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $appConfig = require ROOT_PATH . '/config/app.php';
        $thumbConfig = $appConfig['thumbnail'];

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }

        $srcWidth = (int) $info[0];
        $srcHeight = (int) $info[1];
        if ($srcWidth < 1 || $srcHeight < 1) {
            return null;
        }

        $maxW = max(1, (int) $thumbConfig['max_width']);
        $maxH = max(1, (int) $thumbConfig['max_height']);
        $ratio = min($maxW / $srcWidth, $maxH / $srcHeight, 1);
        $newW = max(1, (int) ($srcWidth * $ratio));
        $newH = max(1, (int) ($srcHeight * $ratio));

        $srcImage = $this->createImageFromFile($sourcePath, $mime);
        if ($srcImage === null) {
            return null;
        }

        $thumbImage = imagecreatetruecolor($newW, $newH);
        if ($thumbImage === false) {
            imagedestroy($srcImage);
            return null;
        }

        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($thumbImage, false);
            imagesavealpha($thumbImage, true);
        }

        imagecopyresampled($thumbImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $srcWidth, $srcHeight);

        ob_start();
        $outputMime = $this->outputThumbnail($thumbImage, $mime, $thumbConfig);
        $body = ob_get_clean();

        imagedestroy($srcImage);
        imagedestroy($thumbImage);

        if ($body === false || $body === '') {
            return null;
        }

        return ['body' => $body, 'mime' => $outputMime];
    }

    /**
     * 从文件创建 GD 图像资源
     */
    private function createImageFromFile(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null,
            'image/gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : null,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default => null,
        };
    }

    /**
     * 输出缩略图到缓冲区
     */
    private function outputThumbnail($thumbImage, string $mime, array $thumbConfig): string
    {
        if ($mime === 'image/png' && function_exists('imagepng')) {
            imagepng($thumbImage, null, 8);
            return 'image/png';
        }
        if ($mime === 'image/gif' && function_exists('imagegif')) {
            imagegif($thumbImage);
            return 'image/gif';
        }
        if ($mime === 'image/webp' && function_exists('imagewebp')) {
            imagewebp($thumbImage, null, $thumbConfig['quality']);
            return 'image/webp';
        }
        imagejpeg($thumbImage, null, $thumbConfig['quality']);
        return 'image/jpeg';
    }

    /**
     * 格式化消息输出（返回可访问 URL）
     */
    private function formatMessage(array $message, int $userId): array
    {
        $item = Message::formatForClient($message, $userId);
        $item['is_mine'] = true;

        return $item;
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件大小超过服务器限制',
            UPLOAD_ERR_PARTIAL => '文件上传不完整',
            UPLOAD_ERR_NO_FILE => '未选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不存在',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            default => '上传失败',
        };
    }

    private function error(string $message): array
    {
        return ['success' => false, 'error' => $message];
    }
}
