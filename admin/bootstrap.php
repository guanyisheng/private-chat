<?php
/**
 * 管理后台公共引导
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Security;
use App\Core\Session;

/**
 * 要求管理员登录
 */
function requireAdmin(): array
{
    if (!Session::isAdmin()) {
        header('Location: /admin/');
        exit;
    }

    $adminId = Session::get('admin_id');
    $admin = \App\Models\Admin::findById((int) $adminId);

    if (!$admin) {
        Session::destroy();
        header('Location: /admin/');
        exit;
    }

    return $admin;
}

$config = require ROOT_PATH . '/config/app.php';
