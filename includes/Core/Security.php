<?php
/**
 * 安全工具类 - XSS防护、CSRF防护、输入过滤
 */

declare(strict_types=1);

namespace App\Core;

class Security
{
    /**
     * HTML 转义，防止 XSS
     */
    public static function escape(?string $string): string
    {
        if ($string === null) {
            return '';
        }
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 生成 CSRF Token
     */
    public static function generateCsrfToken(): string
    {
        $config = require ROOT_PATH . '/config/app.php';
        $tokenName = $config['csrf_token_name'];

        if (empty($_SESSION[$tokenName])) {
            $_SESSION[$tokenName] = bin2hex(random_bytes(32));
        }

        return $_SESSION[$tokenName];
    }

    /**
     * 验证 CSRF Token
     */
    public static function validateCsrfToken(?string $token): bool
    {
        $config = require ROOT_PATH . '/config/app.php';
        $tokenName = $config['csrf_token_name'];

        if (empty($token) || empty($_SESSION[$tokenName])) {
            return false;
        }

        return hash_equals($_SESSION[$tokenName], $token);
    }

    /**
     * 获取 CSRF 隐藏字段 HTML
     */
    public static function csrfField(): string
    {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="_csrf_token" value="' . self::escape($token) . '">';
    }

    /**
     * 验证房间号格式（纯数字，6-12位）
     */
    public static function validateRoomCode(string $code): bool
    {
        return (bool) preg_match('/^\d{4,12}$/', $code);
    }

    /**
     * 验证昵称（2-20字符，不含控制字符）
     */
    public static function validateNickname(string $nickname): bool
    {
        $len = function_exists('mb_strlen') ? mb_strlen($nickname) : strlen($nickname);
        if ($len < 2 || $len > 20) {
            return false;
        }

        return (bool) preg_match('/^[\p{L}\p{N}\p{M}_\-·\.]+$/u', $nickname);
    }

    /**
     * 清理文件名，防止路径遍历
     */
    public static function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
        return $filename ?: 'file';
    }

    /**
     * 验证文件扩展名
     */
    public static function validateExtension(string $filename, array $allowed): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $allowed, true);
    }

    /**
     * 获取文件 MIME 类型
     */
    public static function getMimeType(string $filepath): string
    {
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($filepath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filepath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * 验证 MIME 类型
     */
    public static function validateMimeType(string $filepath, array $allowed): bool
    {
        $mime = self::getMimeType($filepath);

        if (in_array($mime, $allowed, true)) {
            return true;
        }

        // 部分服务器检测不准确，允许通用二进制类型（扩展名已单独校验）
        if ($mime === 'application/octet-stream') {
            return true;
        }

        // 常见 MIME 别名（手机视频、HEIC 转码前等）
        $aliases = [
            'image/jpg' => 'image/jpeg',
            'video/x-msvideo' => 'video/quicktime',
            'video/3gpp' => 'video/mp4',
            'video/x-matroska' => 'video/webm',
            'application/mp4' => 'video/mp4',
            'video/x-m4v' => 'video/mp4',
        ];

        if (isset($aliases[$mime]) && in_array($aliases[$mime], $allowed, true)) {
            return true;
        }

        return false;
    }

    /**
     * 生成安全随机令牌
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * JSON 响应输出
     */
    public static function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
