<?php
/**
 * 获取应用根 URL
 */

declare(strict_types=1);

namespace App\Core;

class AppUrl
{
    public static function base(): string
    {
        $config = require ROOT_PATH . '/config/app.php';
        if (!empty($config['app_url'])) {
            return rtrim($config['app_url'], '/');
        }

        $scheme = Session::isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    /**
     * 生成房间邀请链接
     */
    public static function roomInviteLink(string $roomCode): string
    {
        return self::base() . '/?room=' . rawurlencode($roomCode);
    }
}
