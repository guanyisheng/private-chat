<?php
/**
 * 媒体 URL 解析 - 兼容本地路径与 R2 完整 URL
 */

declare(strict_types=1);

namespace App\Core;

use App\Services\R2StorageService;

class MediaUrl
{
    /**
     * 解析媒体访问地址
     */
    public static function resolve(?string $path, ?string $displayName = null): string
    {
        if ($path === null || $path === ''
            || $path === \App\Models\Message::VIDEO_PENDING
            || $path === \App\Models\Message::VIDEO_FAILED) {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/api/media.php')) {
            return $path;
        }

        $r2 = new R2StorageService();
        if ($r2->isEnabled() && $r2->isR2Key($path)) {
            return $r2->getPublicUrl($path, $displayName);
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * 带下载参数的 URL（正确文件名与后缀）
     */
    public static function downloadUrl(string $url, ?string $filename = null): string
    {
        if ($url === '') {
            return '';
        }

        if (!str_contains($url, '/api/media.php')) {
            return $url;
        }

        $suffix = 'dl=1';
        if ($filename !== null && $filename !== '' && !preg_match('/[?&]f=/', $url)) {
            $suffix .= '&f=' . rawurlencode($filename);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $suffix;
    }

    /**
     * 批量解析消息中的媒体字段
     */
    public static function enrichMessage(array $message): array
    {
        $displayName = $message['file_name'] ?? null;

        if (!empty($message['content']) && in_array($message['type'] ?? '', ['image', 'video', 'file', 'flash'], true)) {
            $isPending = \App\Models\Message::isVideoPending($message['content']);
            $isFailed = \App\Models\Message::isVideoFailed($message['content']);
            if (!$isPending && !$isFailed) {
                $raw = $message['content'];
                $message['content'] = self::resolve($raw, $displayName);
                $message['download_url'] = self::downloadUrl($message['content'], $displayName);
            } else {
                $message['content'] = '';
                $message['processing'] = $isPending;
                $message['failed'] = $isFailed;
            }
        }

        if (!empty($message['thumb_path'])) {
            $message['thumb_path'] = self::resolve($message['thumb_path']);
        }

        return $message;
    }
}
