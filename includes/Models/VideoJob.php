<?php
/**
 * 视频异步处理任务
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;

class VideoJob
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public static function create(array $data): ?array
    {
        try {
            Database::execute(
                'INSERT INTO video_jobs (message_id, room_id, user_id, local_path, stored_name, original_name, mime_type, original_size, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $data['message_id'],
                    $data['room_id'],
                    $data['user_id'],
                    $data['local_path'],
                    $data['stored_name'],
                    $data['original_name'],
                    $data['mime_type'],
                    $data['original_size'],
                    self::STATUS_PENDING,
                ]
            );

            $id = (int) Database::lastInsertId();
            return self::findById($id);
        } catch (\Throwable $e) {
            Logger::error('VideoJob create failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function findById(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM video_jobs WHERE id = ?', [$id]);
    }

    public static function findByMessageId(int $messageId): ?array
    {
        return Database::fetchOne('SELECT * FROM video_jobs WHERE message_id = ?', [$messageId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findPending(int $limit = 10): array
    {
        try {
            return Database::fetchAll(
                'SELECT * FROM video_jobs WHERE status = ? ORDER BY id ASC LIMIT ?',
                [self::STATUS_PENDING, $limit]
            );
        } catch (\Throwable $e) {
            Logger::warning('VideoJob findPending failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function markProcessing(int $id): bool
    {
        return Database::execute(
            'UPDATE video_jobs SET status = ? WHERE id = ? AND status = ?',
            [self::STATUS_PROCESSING, $id, self::STATUS_PENDING]
        ) > 0;
    }

    public static function markDone(int $id, bool $compressed = false): bool
    {
        return Database::execute(
            'UPDATE video_jobs SET status = ?, compressed = ? WHERE id = ?',
            [self::STATUS_DONE, $compressed ? 1 : 0, $id]
        ) > 0;
    }

    public static function markFailed(int $id, string $error): bool
    {
        return Database::execute(
            'UPDATE video_jobs SET status = ?, error_message = ? WHERE id = ?',
            [self::STATUS_FAILED, mb_substr($error, 0, 500), $id]
        ) > 0;
    }

    /**
     * @param int[] $messageIds
     * @return array<int, array<string, mixed>>
     */
    public static function getByMessageIds(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            return Database::fetchAll(
                "SELECT * FROM video_jobs WHERE message_id IN ({$placeholders})",
                $messageIds
            );
        } catch (\Throwable $e) {
            Logger::warning('VideoJob getByMessageIds failed: ' . $e->getMessage());
            return [];
        }
    }
}
