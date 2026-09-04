<?php
/**
 * 会话管理
 */

declare(strict_types=1);

namespace App\Core;

class Session
{
    /**
     * 初始化会话
     */
    public static function init(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($config['session_name']);
        session_set_cookie_params([
            'lifetime' => $config['session_lifetime'],
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isHttps(),
        ]);
        session_start();
    }

    /**
     * 检测是否为 HTTPS（兼容 Cloudflare / 反向代理）
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            if (is_array($visitor) && ($visitor['scheme'] ?? '') === 'https') {
                return true;
            }
        }
        return false;
    }

    /**
     * 写入会话并立即持久化（API 响应前调用）
     */
    public static function commit(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * 设置会话值
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * 获取会话值
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * 删除会话值
     */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * 清除聊天用户会话
     */
    public static function clearChatUser(): void
    {
        self::remove('user_id');
        self::remove('room_id');
        self::remove('room_code');
        self::remove('nickname');
        self::remove('session_token');
    }

    /**
     * 检查是否已登录（聊天用户）
     */
    public static function isChatUser(): bool
    {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['room_id']);
    }

    /**
     * 检查是否已登录（管理员）
     */
    public static function isAdmin(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    /**
     * 销毁会话
     */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}
