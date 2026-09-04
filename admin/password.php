<?php
/**
 * 管理后台 - 修改密码
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\Admin;

$admin = requireAdmin();
$pageTitle = '修改密码';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = 'CSRF 验证失败，请刷新页面重试';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $error = '请填写所有字段';
        } elseif (strlen($newPassword) < 6) {
            $error = '新密码至少 6 位';
        } elseif ($newPassword !== $confirmPassword) {
            $error = '两次输入的新密码不一致';
        } elseif ($currentPassword === $newPassword) {
            $error = '新密码不能与当前密码相同';
        } elseif (!password_verify($currentPassword, $admin['password_hash'])) {
            $error = '当前密码不正确';
        } elseif (Admin::changePassword((int) $admin['id'], $newPassword)) {
            $message = '密码修改成功';
        } else {
            $error = '密码修改失败，请稍后重试';
        }
    }
}

$csrfToken = Security::generateCsrfToken();

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= Security::escape($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= Security::escape($error) ?></div>
<?php endif; ?>

<div class="admin-section password-section">
    <h3>修改登录密码</h3>
    <p class="hint">当前账号：<?= Security::escape($admin['username']) ?></p>

    <form method="POST" class="admin-form password-form">
        <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">

        <div class="form-group">
            <label for="current_password">当前密码</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>

        <div class="form-group">
            <label for="new_password">新密码</label>
            <input type="password" id="new_password" name="new_password" required minlength="6" autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="confirm_password">确认新密码</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary">保存新密码</button>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
