<?php
/**
 * 首页 - 创建房间 / 加入房间
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Core\Security;
use App\Core\Session;
use App\Core\AppUrl;
use App\Models\Room;
use App\Models\User;
use App\Services\AvatarService;

$resumeRoom = null;
$roomEnded = false;

if (Session::isChatUser()) {
    try {
        $roomId = (int) Session::get('room_id');
        $roomCode = Session::get('room_code', '');
        $room = Room::findById($roomId);
        $paused = Session::get('chat_paused') || isset($_GET['paused']);

        if ($room && !$room['is_banned']) {
            $roomEnded = Room::isEnded($roomId);
            if (!$roomEnded && !$paused) {
                User::setOnline(
                    (int) Session::get('user_id'),
                    $roomId,
                    Session::get('session_token', '')
                );
                Session::remove('chat_paused');
                header('Location: /chat.php');
                exit;
            }
            if (!$roomEnded) {
                $resumeRoom = [
                    'code' => $roomCode,
                    'name' => $room['room_name'],
                ];
            } else {
                Session::clearChatUser();
            }
        } else {
            Session::clearChatUser();
        }
    } catch (\Throwable $e) {
        Session::clearChatUser();
    }
}

$csrfToken = Security::generateCsrfToken();
$config = require __DIR__ . '/config/app.php';
$appName = $config['app_name'];

// URL 参数 ?room=123456 用于邀请链接
$inviteRoom = trim($_GET['room'] ?? '');
if ($inviteRoom !== '' && !Security::validateRoomCode($inviteRoom)) {
    $inviteRoom = '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Security::escape($appName) ?></title>
    <link rel="icon" href="/assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f0f13">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="home-page">
    <div class="container home-container">
        <div class="home-card">
            <div class="logo">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </div>
            <h1><?= Security::escape($appName) ?></h1>
            <p class="subtitle">私密多人聊天，安全沟通</p>

            <?php if (!empty($_GET['error']) && $_GET['error'] === 'db'): ?>
                <div class="error-msg">服务暂时不可用，请稍后重试</div>
            <?php endif; ?>

            <div id="globalError" class="error-msg" style="display:none;"></div>

            <?php if ($resumeRoom): ?>
            <div class="resume-chat-bar" id="resumeChatBar">
                <div class="resume-chat-info">
                    <span class="resume-label">暂时离开中</span>
                    <strong><?= Security::escape($resumeRoom['name']) ?></strong>
                    <span class="resume-code">房间号 <?= Security::escape($resumeRoom['code']) ?></span>
                </div>
                <button type="button" class="btn btn-primary" id="btnResumeChat">继续聊天</button>
            </div>
            <?php endif; ?>

            <!-- 两个入口 -->
            <div class="home-actions">
                <button type="button" class="home-action-card" id="btnCreateRoom">
                    <span class="action-icon">➕</span>
                    <span class="action-title">创建房间</span>
                    <span class="action-desc">设置密码并分享邀请</span>
                </button>
                <button type="button" class="home-action-card" id="btnJoinRoom">
                    <span class="action-icon">🚪</span>
                    <span class="action-title">加入房间</span>
                    <span class="action-desc">输入房间号，快速进入聊天</span>
                </button>
            </div>

            <div class="features">
                <div class="feature"><span class="feature-icon">🔒</span><span>私密安全</span></div>
                <div class="feature"><span class="feature-icon">👥</span><span>多人房间</span></div>
                <div class="feature"><span class="feature-icon">📎</span><span>文件传输</span></div>
            </div>
        </div>
    </div>

    <!-- 加入房间弹窗 -->
    <div class="home-modal" id="joinModal" style="display:none;">
        <div class="modal-backdrop" data-close></div>
        <div class="home-modal-dialog">
            <button type="button" class="modal-close-btn" data-close>&times;</button>
            <h3>加入房间</h3>
            <p class="modal-hint">输入发起人提供的房间号</p>
            <form id="joinForm">
                <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
                <div class="form-field">
                    <label for="joinRoomCode">房间号</label>
                    <input type="text" id="joinRoomCode" name="room_code" placeholder="例如 123456"
                           maxlength="12" inputmode="numeric" required
                           value="<?= Security::escape($inviteRoom) ?>">
                </div>
                <div class="form-field" id="joinPasswordField" style="display:none;">
                    <label for="joinPassword">房间密码</label>
                    <input type="password" id="joinPassword" name="password" placeholder="请输入房间密码" maxlength="32">
                </div>
                <div class="profile-setup" id="joinProfileSection">
                    <div class="form-field">
                        <label for="joinNickname">你的昵称</label>
                        <input type="text" id="joinNickname" name="nickname" placeholder="如：路南（头像显示首字「路」）" maxlength="20" autocomplete="nickname">
                    </div>
                    <div class="form-field">
                        <label>选择头像</label>
                        <div class="avatar-letter-preview" id="joinLetterPreview"></div>
                        <p class="avatar-hint">默认使用昵称第一个字作为头像，也可点选下方预制头像</p>
                        <div class="avatar-picker avatar-picker-grid" id="joinAvatarPicker"></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="joinSubmitBtn">进入房间</button>
            </form>
        </div>
    </div>

    <!-- 创建房间弹窗 -->
    <div class="home-modal" id="createModal" style="display:none;">
        <div class="modal-backdrop" data-close></div>
        <div class="home-modal-dialog">
            <button type="button" class="modal-close-btn" data-close>&times;</button>
            <h3>创建房间</h3>
            <p class="modal-hint">配置房间选项后创建并分享</p>
            <form id="createForm">
                <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">

                <div class="form-section">
                    <span class="section-label">房间号</span>
                    <label class="checkbox-row">
                        <input type="radio" name="room_code_mode" value="random" checked>
                        <span>随机生成（推荐）</span>
                    </label>
                    <label class="checkbox-row">
                        <input type="radio" name="room_code_mode" value="custom">
                        <span>自定义房间号</span>
                    </label>
                    <div class="form-field" id="customCodeField" style="display:none;">
                        <input type="text" id="customRoomCode" name="room_code" placeholder="4-12位数字"
                               maxlength="12" inputmode="numeric">
                    </div>
                </div>

                <div class="form-section">
                    <span class="section-label">可选设置</span>
                    <label class="checkbox-row">
                        <input type="checkbox" name="enable_password" value="1" id="enablePasswordCheck">
                        <span>设置房间密码</span>
                    </label>
                    <div class="form-field" id="createPasswordField" style="display:none;">
                        <input type="password" id="createPassword" name="password" placeholder="至少4位" maxlength="32">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="createSubmitBtn">创建房间</button>
            </form>
        </div>
    </div>

    <!-- 分享弹窗 -->
    <div class="home-modal" id="shareModal" style="display:none;">
        <div class="modal-backdrop" data-close></div>
        <div class="home-modal-dialog share-dialog">
            <button type="button" class="modal-close-btn" data-close>&times;</button>
            <h3>房间创建成功</h3>
            <p class="modal-hint">复制以下内容发送给对方，点击链接可快速加入</p>

            <div class="share-room-code">
                房间号：<strong id="shareRoomCode"></strong>
            </div>

            <div class="form-field">
                <label>邀请链接</label>
                <div class="copy-row">
                    <input type="text" id="shareUrl" readonly>
                    <button type="button" class="btn btn-sm" id="copyUrlBtn">复制</button>
                </div>
            </div>

            <div class="form-field">
                <label>分享文案</label>
                <textarea id="shareText" rows="4" readonly></textarea>
                <button type="button" class="btn btn-secondary btn-block" id="copyTextBtn">复制分享文案</button>
            </div>

            <div class="profile-setup share-profile" id="shareProfileSection">
                <div class="form-field">
                    <label for="shareNickname">你的昵称</label>
                    <input type="text" id="shareNickname" placeholder="如：路南（头像显示首字「路」）" maxlength="20" autocomplete="nickname">
                </div>
                <div class="form-field">
                    <label>选择头像</label>
                    <div class="avatar-letter-preview" id="shareLetterPreview"></div>
                    <p class="avatar-hint">默认使用昵称第一个字，也可点选预制 SVG 头像</p>
                    <div class="avatar-picker avatar-picker-grid" id="shareAvatarPicker"></div>
                </div>
            </div>

            <button type="button" class="btn btn-primary btn-block" id="enterCreatedRoomBtn">设置资料并进入</button>
        </div>
    </div>

    <script>
        window.HOME_CONFIG = {
            csrfToken: <?= json_encode($csrfToken) ?>,
            appName: <?= json_encode($appName) ?>,
            inviteRoom: <?= json_encode($inviteRoom) ?>,
            resumeRoom: <?= json_encode($resumeRoom) ?>,
            pollInterval: <?= (int) $config['poll_interval'] ?>,
            avatarColors: <?= json_encode(AvatarService::COLORS) ?>,
            avatarPresets: <?= json_encode(AvatarService::listPresetsForClient(), JSON_UNESCAPED_UNICODE) ?>,
            maxUsersPerRoom: <?= (int) $config['max_users_per_room'] ?>,
        };
    </script>
    <script src="/assets/js/avatar-picker.js"></script>
    <script src="/assets/js/notifications.js"></script>
    <script src="/assets/js/home.js"></script>
</body>
</html>
