<?php
/**
 * 管理后台 - 房间管理
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\Room;

$admin = requireAdmin();
$pageTitle = '房间管理';
$appConfig = require ROOT_PATH . '/config/app.php';
$maxUsersPerRoom = (int) ($appConfig['max_users_per_room'] ?? 10);

$message = '';
$error = '';

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = 'CSRF 验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        $roomId = (int) ($_POST['room_id'] ?? 0);

        switch ($action) {
            case 'delete':
                if (Room::delete($roomId)) {
                    $message = '房间已删除';
                } else {
                    $error = '删除失败';
                }
                break;

            case 'clear':
                if (Room::clearMessages($roomId)) {
                    $message = '消息已清空';
                } else {
                    $error = '清空失败';
                }
                break;

            case 'ban':
                if (Room::setBanned($roomId, true)) {
                    $message = '房间已封禁';
                } else {
                    $error = '封禁失败';
                }
                break;

            case 'unban':
                if (Room::setBanned($roomId, false)) {
                    $message = '房间已解封';
                } else {
                    $error = '解封失败';
                }
                break;
        }
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$result = Room::getAll($page, 20);
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
    <div class="section-header">
        <span>共 <?= $result['total'] ?> 个房间</span>
    </div>

    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>房间号</th>
                <th>房间名</th>
                <th>在线人数</th>
                <th>消息数</th>
                <th>状态</th>
                <th>创建时间</th>
                <th>最后访问</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['rooms'])): ?>
                <tr><td colspan="9" class="text-center">暂无房间</td></tr>
            <?php else: ?>
                <?php foreach ($result['rooms'] as $room): ?>
                    <tr>
                        <td><?= (int) $room['id'] ?></td>
                        <td><code><?= Security::escape($room['room_code']) ?></code></td>
                        <td><?= Security::escape($room['room_name']) ?></td>
                        <td><?= (int) $room['online_count'] ?> / <?= $maxUsersPerRoom ?></td>
                        <td><?= (int) $room['message_count'] ?></td>
                        <td>
                            <?php if ($room['is_banned']): ?>
                                <span class="badge badge-danger">已封禁</span>
                            <?php else: ?>
                                <span class="badge badge-success">正常</span>
                            <?php endif; ?>
                        </td>
                        <td><?= Security::escape($room['created_at']) ?></td>
                        <td><?= Security::escape($room['last_accessed_at']) ?></td>
                        <td class="actions">
                            <form method="POST" class="inline-form" onsubmit="return confirm('确定清空该房间所有消息？')">
                                <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
                                <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                                <input type="hidden" name="action" value="clear">
                                <button type="submit" class="btn btn-sm">清空消息</button>
                            </form>
                            <?php if ($room['is_banned']): ?>
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
                                    <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                                    <input type="hidden" name="action" value="unban">
                                    <button type="submit" class="btn btn-sm btn-success">解封</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" class="inline-form" onsubmit="return confirm('确定封禁该房间？')">
                                    <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
                                    <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                                    <input type="hidden" name="action" value="ban">
                                    <button type="submit" class="btn btn-sm btn-warning">封禁</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" class="inline-form" onsubmit="return confirm('确定删除该房间？此操作不可恢复！')">
                                <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
                                <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-sm btn-danger">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($result['total'] > $result['per_page']): ?>
        <div class="pagination">
            <?php
            $totalPages = ceil($result['total'] / $result['per_page']);
            for ($i = 1; $i <= $totalPages; $i++):
            ?>
                <a href="?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
