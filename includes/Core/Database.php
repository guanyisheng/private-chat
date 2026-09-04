<?php
/**
 * PDO 数据库连接单例
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * 获取 PDO 连接实例
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = require ROOT_PATH . '/config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['dbname'],
                $config['charset']
            );

            try {
                self::$instance = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            } catch (PDOException $e) {
                Logger::error('Database connection failed: ' . $e->getMessage());
                throw new PDOException('数据库连接失败，请检查配置');
            }

            try {
                $appConfig = require ROOT_PATH . '/config/app.php';
                $tzName = $appConfig['timezone'] ?? 'Asia/Shanghai';
                $tz = new \DateTimeZone($tzName);
                $offset = (new \DateTimeImmutable('now', $tz))->format('P');
                self::$instance->exec("SET time_zone = '{$offset}'");
            } catch (\Throwable $e) {
                Logger::warning('SET time_zone skipped: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * 执行查询并返回所有结果
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * 执行查询并返回单行
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * 执行 INSERT/UPDATE/DELETE
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 获取最后插入 ID
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    /**
     * 开始事务
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }

    /**
     * 提交事务
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }

    /**
     * 回滚事务
     */
    public static function rollBack(): bool
    {
        return self::getInstance()->rollBack();
    }
}
