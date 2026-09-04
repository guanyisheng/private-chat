<?php
/**
 * 聊天页面
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Core\Security;
use App\Core\Session;
use App\Models\Room;
use App\Models\User;
use App\Services\AvatarService;

// 验证登录
if (!Session::isChatUser()) {
    header('Location: /');
    exit;
}

$userId = (int) Session::get('user_id');
$roomId = (int) Session::get('room_id');
$roomCode = Session::get('room_code');
$nickname = Session::get('nickname');
$sessionToken = Session::get('session_token', '');

try {
    $room = Room::findById((int) $roomId);
} catch (\Throwable $e) {
    Session::clearChatUser();
    header('Location: /?error=db');
    exit;
}

if (!$room || $room['is_banned']) {
    Session::clearChatUser();
    header('Location: /');
    exit;
}

Room::updateLastAccessed((int) $roomId);
User::setOnline($userId, (int) $roomId, $sessionToken);
Session::remove('chat_paused');

$roomEnded = Room::isEnded((int) $roomId);
$csrfToken = Security::generateCsrfToken();
$config = require __DIR__ . '/config/app.php';

$chatUser = User::findById($userId);
$myAvatar = AvatarService::formatForClient($chatUser['avatar'] ?? null, (string) $nickname);

if (($myAvatar['type'] ?? '') === 'svg' && !empty($myAvatar['url'])) {
    $myAvatarHtml = '<img class="user-avatar avatar-sm avatar-svg" src="' . Security::escape($myAvatar['url']) . '" alt="">';
} elseif (($myAvatar['type'] ?? '') === 'image' && !empty($myAvatar['url'])) {
    $myAvatarHtml = '<img class="user-avatar avatar-sm" src="' . Security::escape($myAvatar['url']) . '" alt="">';
} else {
    $myAvatarHtml = '<span class="user-avatar avatar-sm avatar-letter" style="background:' . Security::escape($myAvatar['color'] ?? '#6c5ce7') . '">'
        . Security::escape($myAvatar['letter'] ?? '?') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= Security::escape($room['room_name']) ?> - <?= Security::escape($config['app_name']) ?></title>
    <link rel="icon" href="/assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f0f13">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="chat-page">
    <!-- 聊天头部 -->
    <header class="chat-header">
        <div class="header-left">
            <button class="btn-icon" id="leaveBtn" title="退出房间">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </button>
            <div class="room-info">
                <h2><?= Security::escape($room['room_name']) ?></h2>
                <span class="online-status" id="onlineStatus">连接中...</span>
                <div class="room-members-strip" id="roomMembersStrip"></div>
            </div>
        </div>
        <div class="header-right">
            <button class="btn-icon btn-pause" id="pauseBtn" title="暂时离开（可回来继续聊）">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="6" y="4" width="4" height="16"/>
                    <rect x="14" y="4" width="4" height="16"/>
                </svg>
            </button>
            <button type="button" class="my-profile-btn" id="myProfileBtn" title="编辑资料">
                <?= $myAvatarHtml ?>
                <span class="my-nickname" id="myNicknameLabel"><?= Security::escape($nickname) ?></span>
            </button>
        </div>
    </header>

    <!-- 消息列表 -->
    <main class="chat-messages" id="messageList">
        <div class="loading-messages">加载消息中...</div>
    </main>

    <!-- 输入区域 -->
    <footer class="chat-input-area" id="chatInputArea">
        <!-- 上传进度条 -->
        <div class="upload-progress" id="uploadProgress" style="display:none;">
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <span class="progress-text" id="progressText">上传中...</span>
        </div>

        <div class="input-toolbar">
            <button class="btn-icon" id="emojiBtn" title="表情">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                    <line x1="9" y1="9" x2="9.01" y2="9"/>
                    <line x1="15" y1="9" x2="15.01" y2="9"/>
                </svg>
            </button>
            <label class="btn-icon" title="发送闪图（长按查看，10秒后销毁）">
                <input type="file" id="flashInput" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
                </svg>
            </label>
            <label class="btn-icon" title="发送图片">
                <input type="file" id="imageInput" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
            </label>
            <label class="btn-icon" title="发送视频">
                <input type="file" id="videoInput" accept="video/mp4,video/webm,video/quicktime" hidden>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="23 7 16 12 23 17 23 7"/>
                    <rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                </svg>
            </label>
            <label class="btn-icon" title="发送文件">
                <input type="file" id="fileInput" accept=".zip,.rar,.pdf,.docx,.xlsx,.txt" hidden>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                </svg>
            </label>
        </div>

        <!-- 表情面板 -->
        <div class="emoji-panel" id="emojiPanel" style="display:none;">
            <div class="emoji-grid" id="emojiGrid"></div>
        </div>

        <!-- 引用回复预览 -->
        <div class="reply-preview-bar" id="replyPreviewBar" style="display:none;">
            <div class="reply-preview-inner">
                <span class="reply-preview-label">回复</span>
                <span class="reply-preview-text" id="replyPreviewText"></span>
            </div>
            <button type="button" class="reply-preview-close" id="replyPreviewClose" title="取消引用">&times;</button>
        </div>

        <form id="messageForm" class="message-form">
            <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="reply_to_id" id="replyToIdInput" value="">
            <textarea
                id="messageInput"
                name="content"
                placeholder="输入消息..."
                rows="1"
                maxlength="5000"
            ></textarea>
            <button type="submit" class="btn btn-send" id="sendBtn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </form>
    </footer>

    <!-- 聊天已结束栏 -->
    <footer class="chat-ended-bar" id="chatEndedBar" style="display:none;">
        <p class="ended-text">对方已退出房间，聊天已结束</p>
        <button type="button" class="btn btn-primary btn-exit-chat" id="exitChatBtn">退出聊天</button>
    </footer>

    <!-- 退出房间确认弹窗 -->
    <div class="modal confirm-modal" id="exitConfirmModal" style="display:none;">
        <div class="modal-backdrop" id="exitConfirmBackdrop"></div>
        <div class="modal-dialog">
            <h3>退出房间</h3>
            <p>退出后聊天记录将对双方清除且不可恢复，对方也将无法继续聊天。</p>
            <p class="modal-hint-sm">是否先保存聊天记录？将打包文本与所有文件为 ZIP 下载到本地。</p>
            <div class="modal-actions modal-actions-stack">
                <button type="button" class="btn btn-primary btn-block" id="exitSaveBtn">保存记录并退出</button>
                <button type="button" class="btn btn-danger btn-block" id="exitConfirmBtn">不保存，直接退出</button>
                <button type="button" class="btn btn-secondary btn-block" id="exitCancelBtn">取消</button>
            </div>
        </div>
    </div>

    <!-- 导出进度 -->
    <div class="modal confirm-modal" id="exportProgressModal" style="display:none;">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <h3>正在打包</h3>
            <p id="exportProgressText">正在打包聊天记录与文件，请稍候…</p>
            <div class="export-progress-bar">
                <div class="export-progress-fill"></div>
            </div>
        </div>
    </div>

    <!-- 编辑资料 -->
    <div class="modal confirm-modal" id="profileModal" style="display:none;">
        <div class="modal-backdrop" id="profileModalBackdrop"></div>
        <div class="modal-dialog">
            <h3>编辑资料</h3>
            <form id="profileForm">
                <input type="hidden" name="_csrf_token" value="<?= Security::escape($csrfToken) ?>">
                <div class="form-field">
                    <label for="profileNickname">昵称</label>
                    <input type="text" id="profileNickname" name="nickname" maxlength="20" value="<?= Security::escape($nickname) ?>">
                </div>
                <div class="form-field">
                    <label>头像</label>
                    <div class="avatar-letter-preview" id="profileLetterPreview"></div>
                    <p class="avatar-hint">文字头像取昵称首字；也可点选预制 SVG 头像</p>
                    <div class="avatar-picker avatar-picker-grid" id="profileAvatarPicker"></div>
                </div>
                <div class="modal-actions modal-actions-stack">
                    <button type="submit" class="btn btn-primary btn-block" id="profileSaveBtn">保存</button>
                    <button type="button" class="btn btn-secondary btn-block" id="profileCancelBtn">取消</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 对方已退出提示弹窗 -->
    <div class="modal confirm-modal" id="partnerLeftModal" style="display:none;">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <h3>对方已退出房间</h3>
            <p>对方已退出房间，所有聊天记录已被清除。您无法继续发送消息。</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-primary" id="partnerLeftOkBtn">知道了</button>
            </div>
        </div>
    </div>

    <!-- 图片 / 视频预览 -->
    <div class="media-viewer" id="mediaViewer" style="display:none;" aria-hidden="true">
        <div class="media-viewer-backdrop" id="mediaViewerBackdrop"></div>
        <header class="media-viewer-toolbar">
            <span class="media-viewer-title" id="mediaViewerTitle">预览</span>
            <div class="media-viewer-actions">
                <a href="#" class="media-viewer-btn" id="mediaViewerDownload" download title="下载" target="_blank" rel="noopener">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                </a>
                <button type="button" class="media-viewer-btn" id="mediaViewerClose" title="关闭">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        </header>
        <div class="media-viewer-body">
            <img id="mediaViewerImage" class="media-viewer-image" alt="" style="display:none;">
            <div class="media-viewer-video-wrap" id="mediaViewerVideoWrap" style="display:none;">
                <div class="mv-loading" id="mvLoading">
                    <div class="mv-loading-spinner"></div>
                    <p class="mv-loading-text" id="mvLoadingText">正在加载视频…</p>
                    <div class="mv-loading-bar">
                        <div class="mv-loading-fill" id="mvLoadingFill"></div>
                    </div>
                    <p class="mv-loading-hint">加载完成后可播放</p>
                    <p class="mv-loading-speed" id="mvLoadingSpeed"></p>
                </div>
                <video id="mediaViewerVideo" class="media-viewer-video" playsinline preload="auto" style="display:none;"></video>
                <div class="mv-controls" id="mvControls" style="display:none;">
                    <button type="button" class="mv-btn mv-play" id="mvPlayBtn" title="播放/暂停">
                        <svg class="mv-icon-play" width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        <svg class="mv-icon-pause" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" style="display:none;">
                            <rect x="6" y="4" width="4" height="16"/>
                            <rect x="14" y="4" width="4" height="16"/>
                        </svg>
                    </button>
                    <div class="mv-progress-area">
                        <input type="range" class="mv-progress" id="mvProgress" min="0" max="1000" value="0" step="1">
                    </div>
                    <span class="mv-time" id="mvTime">0:00 / 0:00</span>
                    <button type="button" class="mv-btn mv-mute" id="mvMuteBtn" title="静音">
                        <svg class="mv-icon-vol" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                            <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
                            <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                        </svg>
                        <svg class="mv-icon-muted" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                            <line x1="23" y1="9" x2="17" y2="15"/>
                            <line x1="17" y1="9" x2="23" y2="15"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 闪图查看 -->
    <div class="flash-viewer" id="flashViewer" style="display:none;" aria-hidden="true">
        <div class="flash-viewer-backdrop" id="flashViewerBackdrop"></div>
        <button type="button" class="flash-viewer-close" id="flashViewerClose" title="关闭">&times;</button>
        <div class="flash-viewer-body">
            <div class="flash-hold-hint" id="flashHoldHint">
                <span class="flash-hold-icon">👆</span>
                <p>按住屏幕查看闪图</p>
                <p class="flash-hold-sub">松手即隐藏，累计查看 <?= (int) ($config['flash_view_seconds'] ?? 10) ?> 秒后自动销毁</p>
            </div>
            <img id="flashViewerImage" class="flash-viewer-image" alt="" draggable="false">
            <div class="flash-countdown" id="flashCountdown" style="display:none;">
                <span id="flashCountdownNum"><?= (int) ($config['flash_view_seconds'] ?? 10) ?></span>
            </div>
        </div>
    </div>

    <script>
        window.CHAT_CONFIG = {
            csrfToken: <?= json_encode($csrfToken) ?>,
            uploadUrl: '/api/upload.php',
            nickname: <?= json_encode($nickname) ?>,
            userId: <?= (int) $userId ?>,
            myAvatar: <?= json_encode($myAvatar, JSON_UNESCAPED_UNICODE) ?>,
            avatarColors: <?= json_encode(AvatarService::COLORS) ?>,
            avatarPresets: <?= json_encode(AvatarService::listPresetsForClient(), JSON_UNESCAPED_UNICODE) ?>,
            maxUsersPerRoom: <?= (int) $config['max_users_per_room'] ?>,
            roomCode: <?= json_encode($roomCode) ?>,
            sessionToken: <?= json_encode($sessionToken) ?>,
            pollInterval: <?= (int) $config['poll_interval'] ?>,
            heartbeatInterval: <?= (int) ($config['heartbeat_interval'] * 1000) ?>,
            roomEnded: <?= $roomEnded ? 'true' : 'false' ?>,
            flashViewSeconds: <?= (int) ($config['flash_view_seconds'] ?? 10) ?>,
            timezone: <?= json_encode($config['timezone'] ?? 'Asia/Shanghai') ?>,
            videoCompress: <?= json_encode($config['client_video_compress'] ?? ['enabled' => true], JSON_UNESCAPED_UNICODE) ?>,
        };
    </script>
    <script src="/assets/js/notifications.js"></script>
    <script src="/assets/js/avatar-picker.js"></script>
    <script src="/assets/js/video-compress.js"></script>
    <script src="/assets/js/chat.js"></script>
</body>
</html>
