<?php
/**
 * 管理员模型
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

class Admin
{
    /**
     * 管理员登录验证
     */
    public static function authenticate(string $username, string $password): ?array
    {
        $admin = Database::fetchOne(
            'SELECT * FROM admins WHERE username = ?',
            [$username]
        );

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return null;
        }

        return $admin;
    }

    /**
     * 根据 ID 查找管理员
     */
    public static function findById(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM admins WHERE id = ?', [$id]);
    }

    /**
     * 修改密码
     */
    public static function changePassword(int $id, string $newPassword): bool
    {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        return Database::execute(
            'UPDATE admins SET password_hash = ? WHERE id = ?',
            [$hash, $id]
        ) > 0;
    }
}
