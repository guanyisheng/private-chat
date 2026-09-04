<?php
/**
 * 文件上传模型
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;

class Upload
{
    /**
     * 创建上传记录
     */
    public static function create(array $data): ?array
    {
        try {
            Database::execute(
                'INSERT INTO uploads (message_id, room_id, original_name, stored_name, file_path, thumb_path, mime_type, file_size, file_type) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $data['message_id'],
                    $data['room_id'],
                    $data['original_name'],
                    $data['stored_name'],
                    $data['file_path'],
                    $data['thumb_path'] ?? null,
                    $data['mime_type'],
                    $data['file_size'],
                    $data['file_type'],
                ]
            );

            $id = (int) Database::lastInsertId();
            return Database::fetchOne('SELECT * FROM uploads WHERE id = ?', [$id]);
        } catch (\Throwable $e) {
            Logger::error('Upload record create failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 根据消息 ID 查找上传
     */
    public static function findByMessageId(int $messageId): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM uploads WHERE message_id = ?',
            [$messageId]
        );
    }

    /**
     * 格式化文件大小
     */
    public static function formatSize(int $bytes): string
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
}
