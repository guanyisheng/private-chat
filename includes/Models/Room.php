<?php
/**
 * 房间模型
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\ChatStorage;
use App\Core\Logger;
use App\Services\R2StorageService;

class Room
{
    /** @var string[]|null */
    private static ?array $tableColumns = null;

    /**
     * 获取 rooms 表当前列名（兼容旧库缺字段）
     *
     * @return string[]
     */
    public static function getTableColumns(): array
    {
        if (self::$tableColumns !== null) {
            return self::$tableColumns;
        }

        $rows = Database::fetchAll(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms'
             ORDER BY ORDINAL_POSITION"
        );

        self::$tableColumns = array_map(
            static fn (array $row): string => (string) $row['COLUMN_NAME'],
            $rows
        );

        return self::$tableColumns;
    }

    private static function hasColumn(string $column): bool
    {
        return in_array($column, self::getTableColumns(), true);
    }

    /**
     * 根据房间号查找房间
     */
    public static function findByCode(string $roomCode): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM rooms WHERE room_code = ?',
            [$roomCode]
        );
    }

    /**
     * 根据 ID 查找房间
     */
    public static function findById(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM rooms WHERE id = ?',
            [$id]
        );
    }

    /**
     * 生成随机房间号（6位）
     */
    public static function generateRoomCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
            $exists = self::findByCode($code);
        } while ($exists !== null);

        return $code;
    }

    /**
     * 创建房间（带设置，按实际表结构动态写入）
     */
    public static function createWithOptions(string $roomCode, ?string $password = null): ?array
    {
        if (self::findByCode($roomCode) !== null) {
            return null;
        }

        if (!self::hasColumn('room_code') || !self::hasColumn('room_name')) {
            Logger::error('rooms table missing required columns', ['room_code' => $roomCode]);
            return null;
        }

        $roomName = 'Room_' . $roomCode;
        $columns = ['room_code', 'room_name'];
        $values = [$roomCode, $roomName];

        if (self::hasColumn('password_hash') && $password !== null && $password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                Logger::error('password_hash failed', ['room_code' => $roomCode]);
                return null;
            }
            $columns[] = 'password_hash';
            $values[] = $hash;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = sprintf(
            'INSERT INTO rooms (%s) VALUES (%s)',
            implode(', ', $columns),
            $placeholders
        );

        try {
            Database::execute($sql, $values);
        } catch (\Throwable $e) {
            Logger::error('Room create failed: ' . $e->getMessage(), [
                'room_code' => $roomCode,
                'sql' => $sql,
            ]);
            return null;
        }

        $roomId = (int) Database::lastInsertId();
        if ($roomId <= 0) {
            $room = self::findByCode($roomCode);
            return $room ?: null;
        }

        self::initRoomStorage($roomCode);

        return self::findById($roomId);
    }

    /**
     * 初始化房间 JSON 目录（失败不影响创建）
     */
    private static function initRoomStorage(string $roomCode): void
    {
        try {
            $storage = new ChatStorage();
            if (!$storage->initRoom($roomCode)) {
                Logger::warning('ChatStorage initRoom failed', ['room_code' => $roomCode]);
            }
        } catch (\Throwable $e) {
            Logger::warning('ChatStorage initRoom exception: ' . $e->getMessage(), ['room_code' => $roomCode]);
        }
    }

    /**
     * 验证房间密码
     */
    public static function verifyPassword(array $room, string $password): bool
    {
        if (empty($room['password_hash'])) {
            return true;
        }
        return password_verify($password, $room['password_hash']);
    }

    /**
     * 房间是否需要密码
     */
    public static function hasPassword(array $room): bool
    {
        return !empty($room['password_hash']);
    }

    /**
     * 创建房间（简单）
     */
    public static function create(string $roomCode): ?array
    {
        return self::createWithOptions($roomCode, null);
    }

    /**
     * 获取或创建房间
     */
    public static function getOrCreate(string $roomCode): ?array
    {
        $room = self::findByCode($roomCode);
        if ($room) {
            self::updateLastAccessed((int) $room['id']);
            return $room;
        }
        return self::create($roomCode);
    }

    /**
     * 更新最后访问时间
     */
    public static function updateLastAccessed(int $roomId): void
    {
        Database::execute(
            'UPDATE rooms SET last_accessed_at = NOW() WHERE id = ?',
            [$roomId]
        );
    }

    /**
     * 获取房间在线人数
     */
    public static function getOnlineCount(int $roomId): int
    {
        $config = require ROOT_PATH . '/config/app.php';
        $timeout = $config['heartbeat_timeout'];

        $result = Database::fetchOne(
            'SELECT COUNT(*) as cnt FROM online_users 
             WHERE room_id = ? AND last_heartbeat > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$roomId, $timeout]
        );

        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * 获取所有房间（管理后台）
     */
    public static function getAll(int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $config = require ROOT_PATH . '/config/app.php';
        $timeout = $config['heartbeat_timeout'];

        $rooms = Database::fetchAll(
            "SELECT r.*, 
                (SELECT COUNT(*) FROM online_users ou 
                 WHERE ou.room_id = r.id 
                 AND ou.last_heartbeat > DATE_SUB(NOW(), INTERVAL ? SECOND)) as online_count,
                (SELECT COUNT(*) FROM messages m WHERE m.room_id = r.id AND m.is_deleted = 0) as message_count
             FROM rooms r 
             ORDER BY r.created_at DESC 
             LIMIT ? OFFSET ?",
            [$timeout, $perPage, $offset]
        );

        $total = Database::fetchOne('SELECT COUNT(*) as cnt FROM rooms');

        return [
            'rooms' => $rooms,
            'total' => (int) ($total['cnt'] ?? 0),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 删除房间
     */
    public static function delete(int $roomId): bool
    {
        $room = self::findById($roomId);
        if (!$room) {
            return false;
        }

        // 删除 JSON 文件
        $storage = new ChatStorage();
        $storage->deleteRoom($room['room_code']);

        Database::execute('DELETE FROM rooms WHERE id = ?', [$roomId]);
        return true;
    }

    /**
     * 封禁/解封房间
     */
    public static function setBanned(int $roomId, bool $banned): bool
    {
        return Database::execute(
            'UPDATE rooms SET is_banned = ? WHERE id = ?',
            [$banned ? 1 : 0, $roomId]
        ) > 0;
    }

    /**
     * 房间聊天是否已结束
     */
    public static function isEnded(int $roomId): bool
    {
        $room = self::findById($roomId);
        return $room !== null && !empty($room['ended_at']);
    }

    /**
     * 结束房间聊天：清空所有记录与文件，并标记结束
     */
    public static function endChat(int $roomId, int $endedByUserId): bool
    {
        $room = self::findById($roomId);
        if (!$room) {
            return false;
        }

        self::destroyAllChatData($roomId);

        Database::execute(
            'UPDATE rooms SET ended_at = NOW(), ended_by_user_id = ? WHERE id = ?',
            [$endedByUserId, $roomId]
        );

        Database::execute('DELETE FROM online_users WHERE room_id = ?', [$roomId]);

        Logger::info('Room chat ended', [
            'room_id' => $roomId,
            'room_code' => $room['room_code'],
            'ended_by' => $endedByUserId,
        ]);

        return true;
    }

    /**
     * 重置已结束房间，供新用户重新进入
     */
    public static function resetForNewChat(int $roomId): void
    {
        Database::execute(
            'UPDATE rooms SET ended_at = NULL, ended_by_user_id = NULL WHERE id = ?',
            [$roomId]
        );

        $room = self::findById($roomId);
        if ($room) {
            $storage = new ChatStorage();
            $storage->initRoom($room['room_code']);
        }
    }

    /**
     * 销毁房间所有聊天数据和文件
     */
    public static function destroyAllChatData(int $roomId): void
    {
        $room = self::findById($roomId);
        if (!$room) {
            return;
        }

        $uploads = Database::fetchAll(
            'SELECT file_path, thumb_path FROM uploads WHERE room_id = ?',
            [$roomId]
        );

        $r2 = new R2StorageService();
        foreach ($uploads as $upload) {
            self::deleteStoredFile($upload['file_path'], $r2);
            if (!empty($upload['thumb_path'])) {
                self::deleteStoredFile($upload['thumb_path'], $r2);
            }
        }

        Database::execute('DELETE FROM uploads WHERE room_id = ?', [$roomId]);
        Database::execute('DELETE FROM messages WHERE room_id = ?', [$roomId]);

        $storage = new ChatStorage();
        $storage->clearMessages($room['room_code']);
    }

    /**
     * 删除单个存储文件（本地或 R2）
     */
    private static function deleteStoredFile(string $path, R2StorageService $r2): void
    {
        if ($path === '') {
            return;
        }

        if ($r2->isEnabled() && $r2->isR2Key($path)) {
            $r2->deleteObject($path);
            return;
        }

        if (strncmp($path, 'uploads/', 8) === 0) {
            $fullPath = ROOT_PATH . '/' . $path;
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
    }

    /**
     * 清空房间消息
     */
    public static function clearMessages(int $roomId): bool
    {
        $room = self::findById($roomId);
        if (!$room) {
            return false;
        }

        Database::execute(
            'UPDATE messages SET is_deleted = 1 WHERE room_id = ?',
            [$roomId]
        );

        $storage = new ChatStorage();
        return $storage->clearMessages($room['room_code']);
    }

    /**
     * 获取房间总数
     */
    public static function count(): int
    {
        $result = Database::fetchOne('SELECT COUNT(*) as cnt FROM rooms');
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * 清理过期房间（30天无人访问）
     */
    public static function cleanupInactive(): int
    {
        $config = require ROOT_PATH . '/config/app.php';
        $days = $config['room_inactive_days'];

        $rooms = Database::fetchAll(
            'SELECT id, room_code FROM rooms 
             WHERE last_accessed_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );

        $count = 0;
        foreach ($rooms as $room) {
            if (self::delete((int) $room['id'])) {
                $count++;
                Logger::info('Inactive room deleted', ['room_code' => $room['room_code']]);
            }
        }

        return $count;
    }
}
