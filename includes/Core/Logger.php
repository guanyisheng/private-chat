<?php
/**
 * 日志记录器
 */

declare(strict_types=1);

namespace App\Core;

class Logger
{
    /**
     * 写入日志到文件和数据库
     */
    public static function log(string $level, string $message, ?array $context = null): void
    {
        $config = require ROOT_PATH . '/config/app.php';
        $logDir = $config['paths']['logs'];

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        // 写入文件
        $logFile = $logDir . '/error.log';
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        // 写入数据库（仅 warning 和 error）
        if (in_array($level, ['warning', 'error'], true)) {
            try {
                Database::execute(
                    'INSERT INTO logs (level, message, context) VALUES (?, ?, ?)',
                    [$level, $message, $context ? json_encode($context) : null]
                );
            } catch (\Exception $e) {
                // 数据库写入失败时仅记录文件
            }
        }
    }

    public static function info(string $message, ?array $context = null): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, ?array $context = null): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, ?array $context = null): void
    {
        self::log('error', $message, $context);
    }
}
