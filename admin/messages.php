<?php
/**
 * 管理后台 - 聊天记录查看
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Core\MediaUrl;
use App\Models\Message;
use App\Models\Upload;

$admin = requireAdmin();
$pageTitle = '聊天记录';

// 搜索过滤
$filters = [
    'room_code' => trim($_GET['room_code'] ?? ''),
    'keyword' => trim($_GET['keyword'] ?? ''),
    'type' => $_GET['type'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
];

$page = max(1, (int) ($_GET['page'] ?? 1));
$result = Message::search($filters, $page, 30);

require __DIR__ . '/includes/header.php';
?>

<div class="admin-section">
    <form method="GET" class="search-form">
        <div class="form-row">
            <input type="text" name="room_code" placeholder="房间号" value="<?= Security::escape($filters['room_code']) ?>">
            <input type="text" name="keyword" placeholder="关键词" value="<?= Security::escape($filters['keyword']) ?>">
            <select name="type">
                <option value="">全部类型</option>
                <option value="text" <?= $filters['type'] === 'text' ? 'selected' : '' ?>>文本</option>
                <option value="image" <?= $filters['type'] === 'image' ? 'selected' : '' ?>>图片</option>
                <option value="video" <?= $filters['type'] === 'video' ? 'selected' : '' ?>>视频</option>
                <option value="file" <?= $filters['type'] === 'file' ? 'selected' : '' ?>>文件</option>
                <option value="flash" <?= $filters['type'] === 'flash' ? 'selected' : '' ?>>闪图</option>
            </select>
            <input type="date" name="date_from" value="<?= Security::escape($filters['date_from']) ?>">
            <input type="date" name="date_to" value="<?= Security::escape($filters['date_to']) ?>">
            <button type="submit" class="btn btn-primary">搜索</button>
        </div>
    </form>

    <p class="result-count">共 <?= $result['total'] ?> 条消息</p>

    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>房间</th>
                <th>用户</th>
                <th>类型</th>
                <th>内容</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['messages'])): ?>
                <tr><td colspan="6" class="text-center">暂无消息</td></tr>
            <?php else: ?>
                <?php foreach ($result['messages'] as $msg): ?>
                    <tr>
                        <td><?= (int) $msg['id'] ?></td>
                        <td><code><?= Security::escape($msg['room_code']) ?></code></td>
                        <td><?= Security::escape($msg['sender']) ?></td>
                        <td>
                            <?php
                            $typeLabels = ['text' => '文本', 'image' => '图片', 'video' => '视频', 'file' => '文件', 'flash' => '闪图'];
                            echo $typeLabels[$msg['type']] ?? $msg['type'];
                            ?>
                        </td>
                        <td class="content-cell">
                            <?php if ($msg['type'] === 'text'): ?>
                                <?= Security::escape(mb_substr($msg['content'], 0, 100)) ?>
                                <?= mb_strlen($msg['content']) > 100 ? '...' : '' ?>
                            <?php elseif ($msg['type'] === 'image' || $msg['type'] === 'flash'): ?>
                                <?php $mediaUrl = MediaUrl::resolve($msg['content']); ?>
                                <?php if ($msg['type'] === 'flash'): ?>
                                    <span class="badge-flash-admin">闪图</span>
                                    <?php if (!empty($msg['flash_destroyed_at'])): ?>
                                        <span class="text-muted">(已销毁 <?= Security::escape($msg['flash_destroyed_at']) ?>)</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a href="<?= Security::escape($mediaUrl) ?>" target="_blank">
                                    <img src="<?= Security::escape($mediaUrl) ?>" alt="图片" class="thumb-preview">
                                </a>
                            <?php elseif ($msg['type'] === 'video'): ?>
                                <?php $mediaUrl = MediaUrl::resolve($msg['content']); ?>
                                <a href="<?= Security::escape($mediaUrl) ?>" target="_blank">[视频] <?= Security::escape($msg['file_name'] ?? '') ?></a>
                            <?php else: ?>
                                <?php $mediaUrl = MediaUrl::resolve($msg['content']); ?>
                                <a href="<?= Security::escape($mediaUrl) ?>" target="_blank">
                                    📄 <?= Security::escape($msg['file_name'] ?? '文件') ?>
                                    (<?= Upload::formatSize((int) ($msg['file_size'] ?? 0)) ?>)
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?= Security::escape($msg['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($result['total'] > $result['per_page']): ?>
        <div class="pagination">
            <?php
            $totalPages = ceil($result['total'] / $result['per_page']);
            $queryParams = http_build_query(array_merge($filters, ['page' => '']));
            for ($i = 1; $i <= min($totalPages, 10); $i++):
            ?>
                <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
