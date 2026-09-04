<?php
/**
 * 管理后台 - 仪表盘
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Models\User;
use App\Models\Room;
use App\Models\Message;

$admin = requireAdmin();
$pageTitle = '仪表盘';

// 统计数据
$stats = [
    'total_users' => User::count(),
    'online_users' => User::onlineCount(),
    'total_rooms' => Room::count(),
    'today_messages' => Message::todayCount(),
    'total_messages' => Message::count(),
];

require __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">👥</div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($stats['total_users']) ?></span>
            <span class="stat-label">总用户数</span>
        </div>
    </div>
    <div class="stat-card highlight">
        <div class="stat-icon">🟢</div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($stats['online_users']) ?></span>
            <span class="stat-label">在线人数</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🏠</div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($stats['total_rooms']) ?></span>
            <span class="stat-label">房间数量</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">💬</div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($stats['today_messages']) ?></span>
            <span class="stat-label">今日消息</span>
        </div>
    </div>
</div>

<div class="admin-section">
    <h3>系统概览</h3>
    <table class="admin-table">
        <tr>
            <td>消息总数</td>
            <td><?= number_format($stats['total_messages']) ?></td>
        </tr>
        <tr>
            <td>平均每房间消息</td>
            <td><?= $stats['total_rooms'] > 0 ? number_format($stats['total_messages'] / $stats['total_rooms'], 1) : 0 ?></td>
        </tr>
        <tr>
            <td>服务器时间</td>
            <td><?= date('Y-m-d H:i:s') ?></td>
        </tr>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
