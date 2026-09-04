<?php
/**
 * 用户模型
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;
use App\Services\AvatarService;

class User
{
    /**
     * 创建用户并加入房间
     */
    public static function create(int $roomId, string $nickname, ?string $avatar = null): ?array
    {
        $token = Security::generateToken();
        $avatarValue = $avatar ?: AvatarService::normalizeLetter();

        try {
            Database::execute(
                'INSERT INTO users (session_token, nickname, avatar, room_id) VALUES (?, ?, ?, ?)',
                [$token, $nickname, $avatarValue, $roomId]
            );
        } catch (\Throwable $e) {
            Database::execute(
                'INSERT INTO users (session_token, nickname, room_id) VALUES (?, ?, ?)',
                [$token, $nickname, $roomId]
            );
        }

        $userId = (int) Database::lastInsertId();

        return self::findById($userId);
    }

    /**
     * 根据 ID 查找用户
     */
    public static function findById(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * 根据 session token 查找用户
     */
    public static function findByToken(string $token): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM users WHERE session_token = ?',
            [$token]
        );
    }

    /**
     * 根据 token 找回房间内身份
     */
    public static function findInRoomByToken(int $roomId, string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return Database::fetchOne(
            'SELECT * FROM users WHERE room_id = ? AND session_token = ?',
            [$roomId, $token]
        );
    }

    /**
     * 昵称是否可用（房间内唯一）
     */
    public static function isNicknameAvailable(int $roomId, string $nickname, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT COUNT(*) as cnt FROM users WHERE room_id = ? AND nickname = ?';
        $params = [$roomId, $nickname];

        if ($excludeUserId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeUserId;
        }

        $result = Database::fetchOne($sql, $params);

        return (int) ($result['cnt'] ?? 0) === 0;
    }

    /**
     * 自动生成可用昵称
     */
    public static function suggestNickname(int $roomId): string
    {
        for ($i = 0; $i < 20; $i++) {
            $name = '访客' . random_int(1000, 9999);
            if (self::isNicknameAvailable($roomId, $name)) {
                return $name;
            }
        }

        return '访客' . time();
    }

    /**
     * 更新资料
     */
    public static function updateProfile(int $userId, string $nickname, ?string $avatar = null): bool
    {
        if ($avatar !== null) {
            return Database::execute(
                'UPDATE users SET nickname = ?, avatar = ? WHERE id = ?',
                [$nickname, $avatar, $userId]
            ) > 0;
        }

        return Database::execute(
            'UPDATE users SET nickname = ? WHERE id = ?',
            [$nickname, $userId]
        ) > 0;
    }

    /**
     * 设置正在输入
     */
    public static function setTyping(int $userId, bool $typing): void
    {
        try {
            if ($typing) {
                Database::execute(
                    'UPDATE online_users SET typing_at = NOW() WHERE user_id = ?',
                    [$userId]
                );
            } else {
                Database::execute(
                    'UPDATE online_users SET typing_at = NULL WHERE user_id = ?',
                    [$userId]
                );
            }
        } catch (\Throwable $e) {
            // typing_at 列未迁移时忽略
        }
    }

    /**
     * 对方是否正在输入（兼容双人）
     */
    public static function isPartnerTyping(int $roomId, int $myUserId): bool
    {
        $members = self::getTypingMembers($roomId, $myUserId);

        return count($members) > 0;
    }

    /**
     * 正在输入的成员
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getTypingMembers(int $roomId, int $excludeUserId): array
    {
        try {
            $rows = Database::fetchAll(
                'SELECT u.id, u.nickname, u.avatar
                 FROM online_users ou
                 JOIN users u ON u.id = ou.user_id
                 WHERE ou.room_id = ?
                 AND u.id != ?
                 AND ou.typing_at IS NOT NULL
                 AND ou.typing_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)',
                [$roomId, $excludeUserId]
            );

            return self::mapMemberRows($rows, $excludeUserId, false, true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 设置在线状态
     */
    public static function setOnline(int $userId, int $roomId, string $token): void
    {
        Database::execute('DELETE FROM online_users WHERE user_id = ?', [$userId]);

        Database::execute(
            'INSERT INTO online_users (user_id, room_id, session_token, last_heartbeat) 
             VALUES (?, ?, ?, NOW())',
            [$userId, $roomId, $token]
        );
    }

    /**
     * 更新心跳
     */
    public static function heartbeat(int $userId): void
    {
        Database::execute(
            'UPDATE online_users SET last_heartbeat = NOW() WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * 用户是否在线（心跳未超时）
     */
    public static function isOnline(int $userId): bool
    {
        $config = require ROOT_PATH . '/config/app.php';
        $timeout = (int) $config['heartbeat_timeout'];

        $result = Database::fetchOne(
            'SELECT COUNT(*) as cnt FROM online_users
             WHERE user_id = ?
             AND last_heartbeat > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$userId, $timeout]
        );

        return (int) ($result['cnt'] ?? 0) > 0;
    }

    /**
     * 获取房间内所有用户
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getUsersInRoom(int $roomId): array
    {
        return Database::fetchAll(
            'SELECT * FROM users WHERE room_id = ? ORDER BY id ASC',
            [$roomId]
        );
    }

    /**
     * 房间内其他成员是否在线（兼容双人）
     */
    public static function isPartnerOnline(int $roomId, int $myUserId): bool
    {
        $members = self::getOnlineMembers($roomId, $myUserId);

        return count($members) > 0;
    }

    /**
     * 在线成员（不含自己）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getOnlineMembers(int $roomId, int $excludeUserId): array
    {
        $config = require ROOT_PATH . '/config/app.php';
        $timeout = (int) $config['heartbeat_timeout'];

        $rows = Database::fetchAll(
            'SELECT u.id, u.nickname, u.avatar
             FROM online_users ou
             JOIN users u ON u.id = ou.user_id
             WHERE ou.room_id = ?
             AND u.id != ?
             AND ou.last_heartbeat > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$roomId, $excludeUserId, $timeout]
        );

        return self::mapMemberRows($rows, $excludeUserId, true, false);
    }

    /**
     * 房间全部成员及在线/输入状态
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getRoomMembersWithPresence(int $roomId, int $currentUserId): array
    {
        $config = require ROOT_PATH . '/config/app.php';
        $timeout = (int) $config['heartbeat_timeout'];

        $rows = Database::fetchAll(
            'SELECT u.id, u.nickname, u.avatar,
                    (ou.user_id IS NOT NULL AND ou.last_heartbeat > DATE_SUB(NOW(), INTERVAL ? SECOND)) AS is_online,
                    (ou.typing_at IS NOT NULL AND ou.typing_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)) AS is_typing
             FROM users u
             LEFT JOIN online_users ou ON ou.user_id = u.id
             WHERE u.room_id = ?
             ORDER BY u.id ASC',
            [$timeout, $roomId]
        );

        $members = [];
        foreach ($rows as $row) {
            $userId = (int) $row['id'];
            $members[] = [
                'user_id' => $userId,
                'nickname' => $row['nickname'],
                'avatar' => AvatarService::formatForClient($row['avatar'] ?? null, $row['nickname']),
                'is_mine' => $userId === $currentUserId,
                'online' => (bool) ($row['is_online'] ?? false),
                'typing' => (bool) ($row['is_typing'] ?? false),
            ];
        }

        return $members;
    }

    /**
     * 用户离线
     */
    public static function setOffline(int $userId): void
    {
        Database::execute('DELETE FROM online_users WHERE user_id = ?', [$userId]);
    }

    /**
     * 获取总用户数
     */
    public static function count(): int
    {
        $result = Database::fetchOne('SELECT COUNT(*) as cnt FROM users');

        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * 获取在线总人数
     */
    public static function onlineCount(): int
    {
        $config = require ROOT_PATH . '/config/app.php';
        $timeout = $config['heartbeat_timeout'];

        $result = Database::fetchOne(
            'SELECT COUNT(*) as cnt FROM online_users 
             WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$timeout]
        );

        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function mapMemberRows(array $rows, int $excludeUserId, bool $online, bool $typing): array
    {
        $members = [];
        foreach ($rows as $row) {
            $userId = (int) $row['id'];
            if ($userId === $excludeUserId) {
                continue;
            }
            $members[] = [
                'user_id' => $userId,
                'nickname' => $row['nickname'],
                'avatar' => AvatarService::formatForClient($row['avatar'] ?? null, $row['nickname']),
                'online' => $online,
                'typing' => $typing,
            ];
        }

        return $members;
    }
}
