<?php
/**
 * 管理后台 - 公共头部
 */

declare(strict_types=1);

use App\Core\Security;
use App\Core\Session;

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Security::escape($pageTitle ?? '管理后台') ?> - <?= Security::escape($config['app_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h2>管理后台</h2>
        </div>
        <nav class="sidebar-nav">
            <a href="/admin/dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <span>📊</span> 仪表盘
            </a>
            <a href="/admin/rooms.php" class="<?= $currentPage === 'rooms' ? 'active' : '' ?>">
                <span>🏠</span> 房间管理
            </a>
            <a href="/admin/messages.php" class="<?= $currentPage === 'messages' ? 'active' : '' ?>">
                <span>💬</span> 聊天记录
            </a>
            <a href="/admin/moderation.php" class="<?= $currentPage === 'moderation' ? 'active' : '' ?>">
                <span>🛡️</span> 内容审核
            </a>
            <a href="/admin/password.php" class="<?= $currentPage === 'password' ? 'active' : '' ?>">
                <span>🔑</span> 修改密码
            </a>
        </nav>
        <div class="sidebar-footer">
            <span><?= Security::escape(Session::get('admin_username', '')) ?></span>
            <a href="/admin/password.php">改密</a>
            <a href="/admin/logout.php">退出</a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-header">
            <h1><?= Security::escape($pageTitle ?? '') ?></h1>
        </header>
        <div class="admin-content">
