<?php
/**
 * 管理后台 - 内容审核
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Core\MediaUrl;
use App\Models\Message;
use App\Models\Room;

$admin = requireAdmin();
$pageTitle = '内容审核';

$message = '';
$error = '';

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = 'CSRF 验证失败';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'delete_message':
                $msgId = (int) ($_POST['message_id'] ?? 0);
                if (Message::delete($msgId)) {
                    $message = '消息已删除';
                } else {
                    $error = '删除失败';
                }
                break;

            case 'ban_room':
                $roomId = (int) ($_POST['room_id'] ?? 0);
                if (Room::setBanned($roomId, true)) {
                    $message = '房间已封禁';
                } else {
                    $error = '封禁失败';
                }
                break;
        }
    }
}

// 获取最近消息用于审核
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = Message::search([], $page, 20);
$csrfToken = Security::generateCsrfToken();

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= Security::escape($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= Security::escape($error) ?></div>
<?php endif; ?>

<div class="admin-section">
    <h3>最近消息审核</h3>
    <p class="hint">删除违规消息或封禁违规房间</p>

    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>房间</th>
                <th>用户</th>
                <th>类型</th>
                <th>内容预览</th>
                <th>时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['messages'])): ?>
                <tr><td colspan="7" class="text-center">暂无消息</td></tr>
            <?php else: ?>
                <?php foreach ($result['messages'] as $msg): ?>
                    <?php $room = Room::findById((int) $msg['room_id']); ?>
                    <tr>
                        <td><?= (int) $msg['id'] ?></td>
                        <td>
                            <code><?= Security::escape($msg['room_code']) ?></code>
                            <?php if ($room && $room['is_banned']): ?>
                                <span class="badge badge-danger">已封禁</span>
                            <?php endif; ?>
                        </td>
                        <td><?= Security::escape($msg['sender']) ?></td>
                        <td><?= Security::escape($msg['type']) ?></td>
                        <td class="content-cell">
                            <?php if ($msg['type'] === 'text'): ?>
                                <?= Security::escape(mb_substr($msg['content'], 0, 80)) ?>
                            <?php elseif ($msg['type'] === 'image'): ?>
                                <?php $mediaUrl = MediaUrl::resolve($msg['content']); ?>
                                <img src="<?= Security::escape($mediaUrl) ?>" alt="" class="thumb-preview">
                            <?php else: ?>
                                <?= Security::escape($msg['file_name'] ?? $msg['type']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= Security::escape($msg['created_at']) ?></td>
                        <td class="actions">
                            <form method="POST" class="inline-form" onsubmit="return confirm('确定删除此消息？')">
                                <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_message">
                                <input type="hidden" name="message_id" value="<?= (int) $msg['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">删除</button>
                            </form>
                            <?php if ($room && !$room['is_banned']): ?>
                                <form method="POST" class="inline-form" onsubmit="return confirm('确定封禁该房间？')">
                                    <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="ban_room">
                                    <input type="hidden" name="room_id" value="<?= (int) $msg['room_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning">封禁房间</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
