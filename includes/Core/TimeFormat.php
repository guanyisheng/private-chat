<?php
/**
 * 时间格式化（统一时区，供 API / 前端使用）
 */

declare(strict_types=1);

namespace App\Core;

class TimeFormat
{
    public static function toIso(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        try {
            $config = require ROOT_PATH . '/config/app.php';
            $tz = new \DateTimeZone($config['timezone'] ?? 'Asia/Shanghai');
            $dt = new \DateTimeImmutable($datetime, $tz);

            return $dt->format('c');
        } catch (\Throwable $e) {
            return $datetime;
        }
    }

    public static function enrichMessage(array $message): array
    {
        if (!empty($message['time'])) {
            $message['time'] = self::toIso((string) $message['time']);
        }
        if (!empty($message['created_at'])) {
            $message['created_at'] = self::toIso((string) $message['created_at']);
        }

        return $message;
    }
}
