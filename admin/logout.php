<?php
/**
 * 管理后台 - 退出登录
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Session;

Session::remove('admin_id');
Session::remove('admin_username');

header('Location: /admin/');
exit;
