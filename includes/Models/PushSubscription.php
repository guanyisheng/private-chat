<?php
/**
 * 推送订阅模型
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;

class PushSubscription
{
    public static function upsert(int $userId, int $roomId, array $sub, ?string $userAgent = null): bool
    {
        $endpoint = $sub['endpoint'] ?? '';
        $keys = $sub['keys'] ?? [];
        $p256dh = $keys['p256dh'] ?? '';
        $auth = $keys['auth'] ?? '';

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return false;
        }

        Database::execute(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint != ?',
            [$userId, $endpoint]
        );

        Database::execute(
            'INSERT INTO push_subscriptions (user_id, room_id, endpoint, p256dh, auth, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                room_id = VALUES(room_id),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                user_agent = VALUES(user_agent)',
            [$userId, $roomId, $endpoint, $p256dh, $auth, $userAgent]
        );

        return true;
    }

    public static function deleteByUser(int $userId): void
    {
        Database::execute('DELETE FROM push_subscriptions WHERE user_id = ?', [$userId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));

            return Database::fetchAll(
                "SELECT * FROM push_subscriptions WHERE user_id IN ({$placeholders})",
                $userIds
            );
        } catch (\Throwable $e) {
            Logger::warning('Push subscriptions unavailable: ' . $e->getMessage());
            return [];
        }
    }
}
