<?php
/**
 * 消息模型
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\ChatStorage;
use App\Core\Logger;

class Message
{
    public const VIDEO_PENDING = '__pending__';
    public const VIDEO_FAILED = '__failed__';

    public static function isVideoPending(?string $content): bool
    {
        return $content === self::VIDEO_PENDING;
    }

    public static function isVideoFailed(?string $content): bool
    {
        return $content === self::VIDEO_FAILED;
    }

    /**
     * 发送消息
     */
    public static function create(array $data): ?array
    {
        Database::beginTransaction();

        try {
            $replyToId = isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null;
            if ($replyToId !== null && $replyToId <= 0) {
                $replyToId = null;
            }

            try {
                Database::execute(
                    'INSERT INTO messages (room_id, user_id, sender, type, content, file_name, file_size, status, reply_to_id) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $data['room_id'],
                        $data['user_id'],
                        $data['sender'],
                        $data['type'],
                        $data['content'],
                        $data['file_name'] ?? null,
                        $data['file_size'] ?? null,
                        'sent',
                        $replyToId,
                    ]
                );
            } catch (\Throwable $e) {
                Database::execute(
                    'INSERT INTO messages (room_id, user_id, sender, type, content, file_name, file_size, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $data['room_id'],
                        $data['user_id'],
                        $data['sender'],
                        $data['type'],
                        $data['content'],
                        $data['file_name'] ?? null,
                        $data['file_size'] ?? null,
                        'sent',
                    ]
                );
            }

            $messageId = (int) Database::lastInsertId();
            $message = self::findById($messageId);

            // 同步到 JSON 文件
            $room = Room::findById($data['room_id']);
            if ($room) {
                $storage = new ChatStorage();
                $syncData = array_merge($message, [
                    'thumb_path' => $data['thumb_path'] ?? null,
                ]);
                if (!$storage->addMessage($room['room_code'], $syncData)) {
                    Logger::warning('JSON sync failed for message', ['id' => $messageId]);
                }
            }

            Database::commit();
            return $message;
        } catch (\Throwable $e) {
            Database::rollBack();
            Logger::error('Message create failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 视频后台处理完成后更新消息
     */
    public static function updateVideoContent(int $messageId, string $objectKey, int $fileSize, ?string $thumbPath): ?array
    {
        Database::beginTransaction();

        try {
            Database::execute(
                'UPDATE messages SET content = ?, file_size = ? WHERE id = ?',
                [$objectKey, $fileSize, $messageId]
            );

            $message = self::findById($messageId);
            if (!$message) {
                Database::rollBack();
                return null;
            }

            $room = Room::findById((int) $message['room_id']);
            if ($room) {
                $storage = new ChatStorage();
                $storage->updateMessageMedia(
                    $room['room_code'],
                    $messageId,
                    $objectKey,
                    $fileSize,
                    $thumbPath
                );
            }

            Database::commit();
            return $message;
        } catch (\Throwable $e) {
            Database::rollBack();
            Logger::error('updateVideoContent failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function markVideoFailed(int $messageId): void
    {
        try {
            Database::execute(
                'UPDATE messages SET content = ? WHERE id = ?',
                [self::VIDEO_FAILED, $messageId]
            );
        } catch (\Throwable $e) {
            Logger::error('markVideoFailed: ' . $e->getMessage());
        }
    }

    /**
     * 格式化为 API / 前端消息项
     */
    public static function formatForClient(array $msg, int $myUserId): array
    {
        $senderName = (string) ($msg['sender'] ?: ($msg['nickname'] ?? '用户'));
        $userId = (int) $msg['user_id'];
        $avatarRaw = $msg['avatar'] ?? null;
        if ($avatarRaw === null && $userId > 0) {
            $author = self::findAuthor($userId);
            $avatarRaw = $author['avatar'] ?? null;
            if ($senderName === '用户' && !empty($author['nickname'])) {
                $senderName = $author['nickname'];
            }
        }

        $item = [
            'id' => (int) $msg['id'],
            'user_id' => $userId,
            'sender' => $senderName,
            'sender_avatar' => \App\Services\AvatarService::formatForClient($avatarRaw, $senderName),
            'type' => $msg['type'],
            'content' => $msg['content'],
            'status' => $msg['status'],
            'time' => \App\Core\TimeFormat::toIso($msg['created_at']),
            'is_mine' => $userId === $myUserId,
        ];

        if ($msg['file_name']) {
            $item['file_name'] = $msg['file_name'];
            $item['file_size'] = (int) $msg['file_size'];
            $item['file_size_text'] = Upload::formatSize((int) $msg['file_size']);
        }

        if (in_array($msg['type'], ['image', 'video', 'flash'], true)) {
            $upload = Upload::findByMessageId((int) $msg['id']);
            if ($upload && $upload['thumb_path']) {
                $item['thumb_path'] = $upload['thumb_path'];
            }
        }

        if ($msg['type'] === 'flash') {
            $item['is_flash'] = true;
        }

        if (!empty($msg['reply_to_id'])) {
            $reply = self::findById((int) $msg['reply_to_id']);
            $preview = self::buildReplyPreview($reply);
            if ($preview) {
                $item['reply_to'] = $preview;
            }
        }

        return \App\Core\MediaUrl::enrichMessage($item);
    }

    /**
     * 构建引用预览
     */
    public static function buildReplyPreview(?array $msg): ?array
    {
        if (!$msg) {
            return null;
        }

        $preview = match ($msg['type']) {
            'text' => mb_substr((string) $msg['content'], 0, 80),
            'image' => '[图片]',
            'flash' => '[闪图]',
            'video' => '[视频]',
            'file' => '[文件] ' . ($msg['file_name'] ?? ''),
            default => '[消息]',
        };

        return [
            'id' => (int) $msg['id'],
            'sender' => $msg['sender'],
            'type' => $msg['type'],
            'preview' => $preview,
        ];
    }

    /**
     * 根据 ID 查找消息
     */
    public static function findById(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM messages WHERE id = ? AND is_deleted = 0',
            [$id]
        );
    }

    /**
     * 查找消息作者（头像等）
     */
    private static function findAuthor(int $userId): ?array
    {
        return Database::fetchOne(
            'SELECT nickname, avatar FROM users WHERE id = ?',
            [$userId]
        );
    }

    /**
     * 获取房间全部消息（导出用）
     */
    public static function getAllByRoom(int $roomId): array
    {
        return Database::fetchAll(
            'SELECT m.*, u.nickname, u.avatar 
             FROM messages m 
             JOIN users u ON u.id = m.user_id
             WHERE m.room_id = ? AND m.is_deleted = 0 
             ORDER BY m.created_at ASC',
            [$roomId]
        );
    }

    /**
     * 获取房间历史消息
     */
    public static function getByRoom(int $roomId, int $limit = 50, int $offset = 0): array
    {
        return Database::fetchAll(
            'SELECT m.*, u.nickname, u.avatar 
             FROM messages m 
             JOIN users u ON u.id = m.user_id
             WHERE m.room_id = ? AND m.is_deleted = 0 
             AND (m.type != \'flash\' OR m.flash_destroyed_at IS NULL)
             ORDER BY m.created_at ASC 
             LIMIT ? OFFSET ?',
            [$roomId, $limit, $offset]
        );
    }

    /**
     * 获取增量消息（ID 大于 lastId）
     */
    public static function getUpdates(int $roomId, int $lastId): array
    {
        return Database::fetchAll(
            'SELECT m.*, u.nickname, u.avatar 
             FROM messages m 
             JOIN users u ON u.id = m.user_id
             WHERE m.room_id = ? AND m.id > ? AND m.is_deleted = 0 
             AND (m.type != \'flash\' OR m.flash_destroyed_at IS NULL)
             ORDER BY m.id ASC',
            [$roomId, $lastId]
        );
    }

    /**
     * 房间内已销毁的闪图 ID（用于轮询同步移除）
     */
    public static function getDestroyedFlashIds(int $roomId): array
    {
        $rows = Database::fetchAll(
            'SELECT id FROM messages 
             WHERE room_id = ? AND type = \'flash\' AND flash_destroyed_at IS NOT NULL',
            [$roomId]
        );

        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /**
     * 销毁闪图（聊天内不可见，后台仍可查）
     */
    public static function destroyFlash(int $messageId, int $roomId): bool
    {
        $msg = Database::fetchOne(
            'SELECT id, type, flash_destroyed_at FROM messages 
             WHERE id = ? AND room_id = ? AND is_deleted = 0',
            [$messageId, $roomId]
        );

        if (!$msg || $msg['type'] !== 'flash' || $msg['flash_destroyed_at'] !== null) {
            return false;
        }

        return Database::execute(
            'UPDATE messages SET flash_destroyed_at = NOW() WHERE id = ?',
            [$messageId]
        ) > 0;
    }

    /**
     * 更新消息状态
     */
    public static function updateStatus(int $messageId, string $status): bool
    {
        return Database::execute(
            'UPDATE messages SET status = ? WHERE id = ?',
            [$status, $messageId]
        ) > 0;
    }

    /**
     * 标记对方消息为已读
     */
    public static function markAsRead(int $roomId, int $userId): void
    {
        Database::execute(
            "UPDATE messages SET status = 'read' 
             WHERE room_id = ? AND user_id != ? AND status != 'read' AND is_deleted = 0",
            [$roomId, $userId]
        );
    }

    /**
     * 删除消息
     */
    public static function delete(int $messageId): bool
    {
        $message = Database::fetchOne(
            'SELECT m.*, r.room_code FROM messages m 
             JOIN rooms r ON r.id = m.room_id 
             WHERE m.id = ?',
            [$messageId]
        );

        if (!$message) {
            return false;
        }

        Database::execute(
            'UPDATE messages SET is_deleted = 1 WHERE id = ?',
            [$messageId]
        );

        // 同步删除 JSON
        $storage = new ChatStorage();
        $storage->deleteMessage($message['room_code'], $messageId);

        return true;
    }

    /**
     * 搜索消息（管理后台）
     */
    public static function search(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where = ['m.is_deleted = 0'];
        $params = [];

        if (!empty($filters['room_code'])) {
            $where[] = 'r.room_code = ?';
            $params[] = $filters['room_code'];
        }

        if (!empty($filters['keyword'])) {
            $where[] = 'm.content LIKE ?';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        if (!empty($filters['type'])) {
            $where[] = 'm.type = ?';
            $params[] = $filters['type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'm.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'm.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $messages = Database::fetchAll(
            "SELECT m.*, r.room_code, u.nickname 
             FROM messages m 
             JOIN rooms r ON r.id = m.room_id 
             JOIN users u ON u.id = m.user_id
             WHERE {$whereClause}
             ORDER BY m.created_at DESC 
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        $totalResult = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM messages m 
             JOIN rooms r ON r.id = m.room_id 
             WHERE {$whereClause}",
            $params
        );

        return [
            'messages' => $messages,
            'total' => (int) ($totalResult['cnt'] ?? 0),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 今日消息数
     */
    public static function todayCount(): int
    {
        $result = Database::fetchOne(
            'SELECT COUNT(*) as cnt FROM messages 
             WHERE DATE(created_at) = CURDATE() AND is_deleted = 0'
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * 消息总数
     */
    public static function count(): int
    {
        $result = Database::fetchOne(
            'SELECT COUNT(*) as cnt FROM messages WHERE is_deleted = 0'
        );
        return (int) ($result['cnt'] ?? 0);
    }
}
