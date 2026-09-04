<?php
/**
 * 聊天记录导出服务 - 打包 ZIP（文本 + 文件）
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\ChatStorage;
use App\Core\Logger;
use App\Models\Message;
use ZipArchive;

class ChatExportService
{
    /**
     * 导出房间聊天记录为 ZIP，返回临时文件路径
     */
    public static function exportToZip(int $roomId, string $roomCode): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            Logger::error('ZipArchive extension not available');
            return null;
        }

        $messages = Message::getAllByRoom($roomId);
        $tempDir = sys_get_temp_dir() . '/pcr_export_' . uniqid('', true);
        $filesDir = $tempDir . '/files';

        if (!mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            return null;
        }
        mkdir($filesDir, 0755, true);

        try {
            // 生成文本日志
            $logLines = [
                'Private Chat Room - 聊天记录导出',
                '房间号: ' . $roomCode,
                '导出时间: ' . date('Y-m-d H:i:s'),
                str_repeat('=', 50),
                '',
            ];

            $jsonMessages = [];
            $r2 = new R2StorageService();
            $fileIndex = 0;

            foreach ($messages as $msg) {
                $time = $msg['created_at'];
                $sender = $msg['sender'];
                $type = $msg['type'];

                $jsonItem = [
                    'id' => (int) $msg['id'],
                    'time' => $time,
                    'user' => $sender,
                    'type' => $type,
                ];

                switch ($type) {
                    case 'text':
                        $logLines[] = "[{$time}] 用户{$sender}: {$msg['content']}";
                        $jsonItem['content'] = $msg['content'];
                        break;

                    case 'image':
                    case 'video':
                    case 'file':
                        $fileIndex++;
                        $originalName = $msg['file_name'] ?? ('file_' . $msg['id']);
                        $safeName = self::safeFilename($originalName);
                        $zipInnerName = sprintf('%03d_%s', $fileIndex, $safeName);
                        $saved = self::copyMessageFile($msg, $filesDir . '/' . $zipInnerName, $r2);

                        $label = match ($type) {
                            'image' => '图片',
                            'video' => '视频',
                            default => '文件',
                        };
                        $sizeText = $msg['file_size'] ? ' (' . self::formatSize((int) $msg['file_size']) . ')' : '';
                        $logLines[] = "[{$time}] 用户{$sender}: [{$label}] {$originalName}{$sizeText}"
                            . ($saved ? " -> files/{$zipInnerName}" : ' (文件获取失败)');

                        $jsonItem['content'] = $msg['content'];
                        $jsonItem['file_name'] = $originalName;
                        $jsonItem['file_size'] = (int) ($msg['file_size'] ?? 0);
                        $jsonItem['exported_as'] = $saved ? "files/{$zipInnerName}" : null;
                        break;
                }

                $jsonMessages[] = $jsonItem;
            }

            file_put_contents($tempDir . '/chat_log.txt', implode("\n", $logLines) . "\n");

            $chatJson = [
                'room' => $roomCode,
                'exported' => date('Y-m-d H:i:s'),
                'message_count' => count($jsonMessages),
                'messages' => $jsonMessages,
            ];
            file_put_contents(
                $tempDir . '/chat.json',
                json_encode($chatJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            // 若存在 lt 备份也一并打入
            $storage = new ChatStorage();
            $ltData = $storage->getChatLog($roomCode);
            if ($ltData !== null) {
                file_put_contents(
                    $tempDir . '/chat_backup.json',
                    json_encode($ltData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                );
            }

            file_put_contents(
                $tempDir . '/README.txt',
                "本压缩包由 Private Chat Room 导出\n"
                . "房间号: {$roomCode}\n"
                . "导出时间: " . date('Y-m-d H:i:s') . "\n\n"
                . "包含:\n"
                . "- chat_log.txt  可读聊天记录\n"
                . "- chat.json     结构化消息数据\n"
                . "- files/        图片、视频、附件\n"
            );

            $zipPath = sys_get_temp_dir() . '/chat_' . $roomCode . '_' . date('Ymd_His') . '_' . uniqid() . '.zip';
            $zip = new ZipArchive();

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                self::removeDir($tempDir);
                return null;
            }

            self::addDirToZip($zip, $tempDir, '');
            $zip->close();

            self::removeDir($tempDir);

            return $zipPath;
        } catch (\Throwable $e) {
            Logger::error('Chat export failed: ' . $e->getMessage(), ['room' => $roomCode]);
            self::removeDir($tempDir);
            return null;
        }
    }

    private static function copyMessageFile(array $msg, string $destPath, R2StorageService $r2): bool
    {
        $content = $msg['content'] ?? '';
        if ($content === '') {
            return false;
        }

        if ($r2->isEnabled() && $r2->isR2Key($content)) {
            $object = $r2->getObject($content);
            if ($object === null || empty($object['body'])) {
                return false;
            }
            return file_put_contents($destPath, $object['body']) !== false;
        }

        if (strncmp($content, 'uploads/', 8) === 0) {
            $localPath = ROOT_PATH . '/' . $content;
            if (!is_file($localPath)) {
                return false;
            }
            return copy($localPath, $destPath);
        }

        return false;
    }

    private static function safeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\-]/u', '_', $name) ?? 'file';
        return $name !== '' ? $name : 'file';
    }

    private static function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    private static function addDirToZip(ZipArchive $zip, string $dir, string $base): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            $zipPath = $base === '' ? $item : $base . '/' . $item;

            if (is_dir($path)) {
                $zip->addEmptyDir($zipPath);
                self::addDirToZip($zip, $path, $zipPath);
            } else {
                $zip->addFile($path, $zipPath);
            }
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
