<?php
/**
 * 管理后台 - 登录页
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Core\Session;
use App\Models\Admin;

// 已登录则跳转
if (Session::isAdmin()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['_csrf_token'] ?? '';

    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'CSRF 验证失败';
    } elseif ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } else {
        $admin = Admin::authenticate($username, $password);
        if ($admin) {
            Session::set('admin_id', (int) $admin['id']);
            Session::set('admin_username', $admin['username']);
            Session::commit();
            header('Location: /admin/dashboard.php');
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    }
}

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - <?= Security::escape($config['app_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-login-page">
    <div class="container">
        <div class="admin-login-card">
            <h1>管理后台</h1>
            <p class="subtitle"><?= Security::escape($config['app_name']) ?></p>

            <?php if ($error): ?>
                <div class="error-msg"><?= Security::escape($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="admin-form">
                <?= Security::csrfField() ?>
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">登录</button>
            </form>

            <p class="login-hint">默认账号: admin / admin123</p>
        </div>
    </div>
</body>
</html>
