/**
 * 聊天页 JavaScript - 消息收发、轮询、文件上传
 */

(function () {
    'use strict';

    const config = window.CHAT_CONFIG;
    let lastMessageId = 0;
    let pollTimer = null;
    let heartbeatTimer = null;
    let isPolling = false;
    let roomEnded = config.roomEnded || false;
    let partnerLeftNotified = false;
    let partnerOnline = null;
    let partnerPresenceReady = false;
    let memberMap = {};
    let myAvatar = config.myAvatar || null;
    let profilePicker = null;
    let partnerTyping = false;
    let typingDebounceTimer = null;
    let typingStopTimer = null;
    let isSendingTyping = false;

    /**
     * 解析媒体 URL（兼容本地路径与 R2 完整 URL）
     */
    function resolveMediaUrl(path) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path) || path.startsWith('/api/media.php')) {
            return path;
        }
        return '/' + path.replace(/^\//, '');
    }

    function mediaDownloadUrl(msg) {
        if (msg.download_url) {
            return resolveMediaUrl(msg.download_url);
        }
        const src = resolveMediaUrl(msg.content);
        if (!src || !src.includes('/api/media.php')) {
            return src;
        }
        const fname = msg.file_name ? encodeURIComponent(msg.file_name) : '';
        const sep = src.includes('?') ? '&' : '?';
        return src + sep + 'dl=1' + (fname ? '&f=' + fname : '');
    }

    function renderAvatarHtml(avatar, className = 'avatar-sm') {
        if (typeof AvatarPicker !== 'undefined') {
            return AvatarPicker.render(avatar, className);
        }
        if (!avatar) {
            return '<span class="user-avatar ' + className + ' avatar-letter" style="background:#6c5ce7">?</span>';
        }
        if ((avatar.type === 'svg' || avatar.type === 'image') && avatar.url) {
            return `<img class="user-avatar ${className} avatar-svg" src="${escapeHtml(avatar.url)}" alt="">`;
        }
        const color = escapeHtml(avatar.color || '#6c5ce7');
        const letter = escapeHtml(avatar.letter || '?');
        return `<span class="user-avatar ${className} avatar-letter" style="background:${color}">${letter}</span>`;
    }

    function syncMemberMap(members) {
        if (!Array.isArray(members)) return;
        members.forEach((m) => {
            memberMap[m.user_id] = m;
        });
        renderMembersStrip(members);
        updateOnlineStatusLabel();
    }

    function renderMembersStrip(members) {
        if (!roomMembersStrip || !Array.isArray(members)) return;
        const others = members.filter(m => !m.is_mine);
        if (others.length === 0) {
            roomMembersStrip.innerHTML = '';
            return;
        }
        roomMembersStrip.innerHTML = others.map(m => {
            const onlineClass = m.online ? ' is-online' : '';
            const title = escapeHtml(m.nickname) + (m.online ? ' · 在线' : '');
            return `<span class="member-chip${onlineClass}" title="${title}">${renderAvatarHtml(m.avatar, 'avatar-xs')}</span>`;
        }).join('');
    }

    function getTypingLabel(typingMembers) {
        if (!Array.isArray(typingMembers) || typingMembers.length === 0) return '';
        const names = typingMembers.map(m => m.nickname).slice(0, 2);
        if (typingMembers.length === 1) return `${names[0]} 正在输入…`;
        if (typingMembers.length === 2) return `${names.join('、')} 正在输入…`;
        return `${names.join('、')} 等 ${typingMembers.length} 人正在输入…`;
    }

    function getOnlineLabel(members) {
        if (!Array.isArray(members)) return '';
        const onlineOthers = members.filter(m => !m.is_mine && m.online);
        const totalOnline = members.filter(m => m.online).length;
        if (onlineOthers.length === 0) return '暂无其他成员在线';
        if (onlineOthers.length === 1) return `${onlineOthers[0].nickname} 在线`;
        return `${totalOnline} 人在线`;
    }

    function initProfileModal() {
        if (typeof AvatarPicker === 'undefined' || !profileAvatarPicker) return;

        profilePicker = AvatarPicker.init({
            pickerEl: profileAvatarPicker,
            letterPreviewEl: document.getElementById('profileLetterPreview'),
            nicknameInput: profileNickname,
            presets: config.avatarPresets || [],
            colors: config.avatarColors || [],
        });

        if (myProfileBtn) {
            myProfileBtn.addEventListener('click', () => {
                if (profileNickname) profileNickname.value = config.nickname || '';
                profilePicker.updateLetterPreview();
                if (myAvatar?.type === 'svg' && myAvatar.preset_id) {
                    profilePicker.selectPreset(myAvatar.preset_id);
                } else {
                    profilePicker.selectLetter();
                }
                profileModal.style.display = 'flex';
            });
        }
        profileCancelBtn?.addEventListener('click', closeProfileModal);
        profileModalBackdrop?.addEventListener('click', closeProfileModal);
        profileForm?.addEventListener('submit', saveProfile);
    }

    function closeProfileModal() {
        if (profileModal) profileModal.style.display = 'none';
    }

    async function saveProfile(e) {
        e.preventDefault();
        const btn = document.getElementById('profileSaveBtn');
        if (btn) btn.disabled = true;

        const fd = new FormData();
        fd.append('_csrf_token', config.csrfToken);
        fd.append('nickname', profileNickname?.value?.trim() || '');

        const profileData = profilePicker ? profilePicker.getData() : {};
        if (profileData.avatar_svg) {
            fd.append('avatar_svg', profileData.avatar_svg);
        } else if (profileData.avatar_letter) {
            fd.append('avatar_letter', '1');
        }

        try {
            const res = await fetch('/api/profile.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                config.nickname = data.nickname;
                myAvatar = data.avatar;
                if (myNicknameLabel) myNicknameLabel.textContent = data.nickname;
                if (myProfileBtn) {
                    const av = myProfileBtn.querySelector('.user-avatar');
                    if (av) av.outerHTML = renderAvatarHtml(myAvatar, 'avatar-sm');
                }
                closeProfileModal();
                showSettingToast('资料已更新');
            } else {
                alert(data.error || '保存失败');
            }
        } catch (err) {
            alert('网络错误');
        }

        if (btn) btn.disabled = false;
    }

    function statusTitle(status) {
        switch (status) {
            case 'read': return '已读';
            case 'delivered': return '已送达';
            case 'sent': return '已发送';
            default: return '';
        }
    }

    // DOM 元素
    const messageList = document.getElementById('messageList');
    const messageForm = document.getElementById('messageForm');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const leaveBtn = document.getElementById('leaveBtn');
    const pauseBtn = document.getElementById('pauseBtn');
    const emojiBtn = document.getElementById('emojiBtn');
    const emojiPanel = document.getElementById('emojiPanel');
    const emojiGrid = document.getElementById('emojiGrid');
    const imageInput = document.getElementById('imageInput');
    const flashInput = document.getElementById('flashInput');
    const videoInput = document.getElementById('videoInput');
    const fileInput = document.getElementById('fileInput');
    const uploadProgress = document.getElementById('uploadProgress');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    const onlineStatus = document.getElementById('onlineStatus');
    const mediaViewer = document.getElementById('mediaViewer');
    const mediaViewerBackdrop = document.getElementById('mediaViewerBackdrop');
    const mediaViewerClose = document.getElementById('mediaViewerClose');
    const mediaViewerDownload = document.getElementById('mediaViewerDownload');
    const mediaViewerTitle = document.getElementById('mediaViewerTitle');
    const mediaViewerImage = document.getElementById('mediaViewerImage');
    const mediaViewerVideoWrap = document.getElementById('mediaViewerVideoWrap');
    const mediaViewerVideo = document.getElementById('mediaViewerVideo');
    const mvPlayBtn = document.getElementById('mvPlayBtn');
    const mvProgress = document.getElementById('mvProgress');
    const mvTime = document.getElementById('mvTime');
    const mvMuteBtn = document.getElementById('mvMuteBtn');
    const mvLoading = document.getElementById('mvLoading');
    const mvLoadingText = document.getElementById('mvLoadingText');
    const mvLoadingFill = document.getElementById('mvLoadingFill');
    const mvLoadingSpeed = document.getElementById('mvLoadingSpeed');
    const mvControls = document.getElementById('mvControls');
    const flashViewer = document.getElementById('flashViewer');
    const flashViewerBackdrop = document.getElementById('flashViewerBackdrop');
    const flashViewerClose = document.getElementById('flashViewerClose');
    const flashViewerImage = document.getElementById('flashViewerImage');
    const flashHoldHint = document.getElementById('flashHoldHint');
    const flashCountdown = document.getElementById('flashCountdown');
    const flashCountdownNum = document.getElementById('flashCountdownNum');
    let mvSeeking = false;
    let mvLoadAbort = null;
    let mvBlobUrl = null;
    let flashCountdownTimer = null;
    let flashViewing = false;
    let flashAccumulated = 0;
    let flashTickTimer = null;
    let currentFlashId = null;
    const flashViewSeconds = config.flashViewSeconds || 10;
    const chatInputArea = document.getElementById('chatInputArea');
    const chatEndedBar = document.getElementById('chatEndedBar');
    const exitChatBtn = document.getElementById('exitChatBtn');
    const exitConfirmModal = document.getElementById('exitConfirmModal');
    const exitConfirmBtn = document.getElementById('exitConfirmBtn');
    const exitSaveBtn = document.getElementById('exitSaveBtn');
    const exitCancelBtn = document.getElementById('exitCancelBtn');
    const exitConfirmBackdrop = document.getElementById('exitConfirmBackdrop');
    const exportProgressModal = document.getElementById('exportProgressModal');
    const partnerLeftModal = document.getElementById('partnerLeftModal');
    const partnerLeftOkBtn = document.getElementById('partnerLeftOkBtn');
    const replyPreviewBar = document.getElementById('replyPreviewBar');
    const replyPreviewText = document.getElementById('replyPreviewText');
    const replyPreviewClose = document.getElementById('replyPreviewClose');
    const replyToIdInput = document.getElementById('replyToIdInput');
    const myProfileBtn = document.getElementById('myProfileBtn');
    const myNicknameLabel = document.getElementById('myNicknameLabel');
    const roomMembersStrip = document.getElementById('roomMembersStrip');
    const profileModal = document.getElementById('profileModal');
    const profileForm = document.getElementById('profileForm');
    const profileNickname = document.getElementById('profileNickname');
    const profileAvatarPicker = document.getElementById('profileAvatarPicker');
    const profileCancelBtn = document.getElementById('profileCancelBtn');
    const profileModalBackdrop = document.getElementById('profileModalBackdrop');

    // 常用表情
    const emojis = [
        '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂',
        '🙂', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗',
        '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭',
        '🤫', '🤔', '🤐', '🤨', '😐', '😑', '😶', '😏',
        '😒', '🙄', '😬', '🤥', '😌', '😔', '😪', '🤤',
        '😴', '😷', '🤒', '🤕', '🤢', '🤮', '🤧', '🥵',
        '👍', '👎', '👌', '✌️', '🤞', '🤟', '🤘', '🤙',
        '👋', '🤚', '🖐️', '✋', '🖖', '👏', '🙌', '🤝',
        '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '💔',
        '💯', '💢', '💥', '💫', '💦', '💨', '🎉', '🎊',
    ];

    // 初始化
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        initEmojis();
        loadHistory();
        if (!roomEnded) {
            startPolling();
            startHeartbeat();
        }
        bindEvents();
        initProfileModal();
        if (typeof PcrNotify !== 'undefined') {
            PcrNotify.init({ csrfToken: config.csrfToken });
        }
        if (roomEnded) {
            handleRoomEnded(false);
        }
        if (config.roomCode && config.sessionToken) {
            localStorage.setItem('pcr_token_' + config.roomCode, config.sessionToken);
        }
    }

    function showSettingToast(msg) {
        let el = document.getElementById('settingToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'settingToast';
            el.className = 'setting-toast';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.style.display = 'block';
        clearTimeout(el._timer);
        el._timer = setTimeout(() => { el.style.display = 'none'; }, 2500);
    }

    function appendSystemNotice(text) {
        const loading = messageList.querySelector('.loading-messages');
        if (loading) loading.remove();

        const div = document.createElement('div');
        div.className = 'message-system';
        div.dataset.systemId = 'sys_' + Date.now();
        div.innerHTML = `<span>${escapeHtml(text)}</span>`;
        messageList.appendChild(div);
        scrollToBottom();
    }

    function updateOnlineStatusLabel() {
        if (roomEnded) return;

        const members = Object.values(memberMap);
        const typingMembers = members.filter(m => !m.is_mine && m.typing);
        if (typingMembers.length > 0) {
            onlineStatus.textContent = getTypingLabel(typingMembers);
            onlineStatus.classList.add('connected', 'is-typing');
            return;
        }
        onlineStatus.classList.remove('is-typing');

        if (!partnerPresenceReady && members.length === 0) {
            onlineStatus.textContent = '连接中...';
            onlineStatus.classList.remove('connected');
            return;
        }

        const onlineOthers = members.filter(m => !m.is_mine && m.online);
        if (onlineOthers.length > 0) {
            onlineStatus.textContent = getOnlineLabel(members);
            onlineStatus.classList.add('connected');
        } else if (members.some(m => !m.is_mine)) {
            onlineStatus.textContent = '等待成员上线…';
            onlineStatus.classList.remove('connected');
        } else {
            onlineStatus.textContent = '等待他人加入…';
            onlineStatus.classList.remove('connected');
        }
    }

    function setPartnerTyping(typing) {
        if (typeof typing !== 'boolean') return;
        partnerTyping = typing;
        updateOnlineStatusLabel();
    }

    function applyMembersUpdate(members, typingMembers) {
        if (Array.isArray(members)) {
            syncMemberMap(members);
            partnerPresenceReady = true;
            partnerOnline = members.some(m => !m.is_mine && m.online);
        }
        if (Array.isArray(typingMembers)) {
            Object.keys(memberMap).forEach((id) => {
                memberMap[id].typing = typingMembers.some(t => String(t.user_id) === String(id));
            });
        }
        updateOnlineStatusLabel();
    }

    function handleMemberJoinNotice(members) {
        if (!Array.isArray(members)) return;
        members.filter(m => !m.is_mine && m.online).forEach(m => {
            const key = 'joined_' + m.user_id;
            if (!handleMemberJoinNotice._seen) handleMemberJoinNotice._seen = new Set();
            if (!handleMemberJoinNotice._seen.has(key)) {
                handleMemberJoinNotice._seen.add(key);
            }
        });
    }

    async function sendTypingState(typing) {
        if (roomEnded || isSendingTyping) return;
        isSendingTyping = true;
        try {
            const fd = new FormData();
            fd.append('_csrf_token', config.csrfToken);
            fd.append('typing', typing ? '1' : '0');
            await fetch('/api/typing.php', { method: 'POST', body: fd });
        } catch (e) { /* ignore */ }
        isSendingTyping = false;
    }

    function onMessageInputActivity() {
        if (roomEnded) return;

        sendTypingState(true);
        clearTimeout(typingStopTimer);
        typingStopTimer = setTimeout(() => sendTypingState(false), 3000);

        clearTimeout(typingDebounceTimer);
        typingDebounceTimer = setTimeout(() => {
            if (!messageInput.value.trim()) {
                sendTypingState(false);
            }
        }, 800);
    }

    function setReplyTarget(msg) {
        if (!replyPreviewBar || !replyToIdInput) return;

        const preview = msg.type === 'text'
            ? (msg.content || '').slice(0, 80)
            : (msg.reply_to ? msg.reply_to.preview : null) || replyTypeLabel(msg.type, msg.file_name);

        replyToIdInput.value = String(msg.id);
        replyPreviewText.textContent = `${msg.sender}：${preview}`;
        replyPreviewBar.style.display = 'flex';
        messageInput.focus();
    }

    function clearReplyTarget() {
        if (!replyPreviewBar || !replyToIdInput) return;
        replyToIdInput.value = '';
        replyPreviewText.textContent = '';
        replyPreviewBar.style.display = 'none';
    }

    function replyTypeLabel(type, fileName) {
        switch (type) {
            case 'image': return '[图片]';
            case 'flash': return '[闪图]';
            case 'video': return '[视频]';
            case 'file': return '[文件] ' + (fileName || '');
            default: return '[消息]';
        }
    }

    function buildReplyQuoteHtml(replyTo) {
        if (!replyTo) return '';
        return `<div class="message-reply-quote" data-reply-id="${replyTo.id}" title="定位到原消息">
            <span class="message-reply-sender">${escapeHtml(replyTo.sender)}</span>
            <span class="message-reply-text">${escapeHtml(replyTo.preview || '')}</span>
        </div>`;
    }

    function bindReplyQuote(div) {
        const quote = div.querySelector('.message-reply-quote');
        if (!quote) return;
        quote.addEventListener('click', (e) => {
            e.stopPropagation();
            const targetId = quote.dataset.replyId;
            const target = document.querySelector(`[data-id="${targetId}"]`);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.classList.add('message-highlight');
                setTimeout(() => target.classList.remove('message-highlight'), 1500);
            }
        });
    }

    function bindMessageReplyGesture(bubble, msg) {
        if (!bubble) return;
        let pressTimer = null;

        const start = (e) => {
            if (e.target.closest('a, button, .message-image, .message-video-card, .message-flash-card')) {
                return;
            }
            clearTimeout(pressTimer);
            pressTimer = setTimeout(() => {
                pressTimer = null;
                setReplyTarget(msg);
            }, 500);
        };

        const cancel = () => clearTimeout(pressTimer);

        bubble.addEventListener('mousedown', start);
        bubble.addEventListener('touchstart', start, { passive: true });
        bubble.addEventListener('mouseup', cancel);
        bubble.addEventListener('mouseleave', cancel);
        bubble.addEventListener('touchend', cancel);
        bubble.addEventListener('touchcancel', cancel);
        bubble.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            setReplyTarget(msg);
        });
    }

    function handlePartnerPresence(isOnline) {
        if (roomEnded || typeof isOnline !== 'boolean') return;
        partnerOnline = isOnline;
        partnerPresenceReady = true;
        updateOnlineStatusLabel();
    }

    function bindEvents() {
        // 发送消息
        messageForm.addEventListener('submit', sendMessage);

        // 自动调整输入框高度 + 正在输入
        messageInput.addEventListener('input', () => {
            messageInput.style.height = 'auto';
            messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + 'px';
            onMessageInputActivity();
        });

        // Enter 发送，Shift+Enter 换行
        messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                messageForm.dispatchEvent(new Event('submit'));
            }
        });

        // 退出 / 暂时离开
        leaveBtn.addEventListener('click', showExitConfirm);
        if (pauseBtn) {
            pauseBtn.addEventListener('click', pauseLeaveRoom);
        }
        exitConfirmBtn.addEventListener('click', () => confirmExitRoom(false));
        exitSaveBtn.addEventListener('click', () => confirmExitRoom(true));
        exitCancelBtn.addEventListener('click', hideExitConfirm);
        exitConfirmBackdrop.addEventListener('click', hideExitConfirm);
        exitChatBtn.addEventListener('click', exitChat);
        partnerLeftOkBtn.addEventListener('click', () => {
            partnerLeftModal.style.display = 'none';
        });

        // 表情面板
        emojiBtn.addEventListener('click', () => {
            emojiPanel.style.display = emojiPanel.style.display === 'none' ? 'block' : 'none';
        });

        // 文件上传
        imageInput.addEventListener('change', (e) => handleFileUpload(e.target.files[0], 'image'));
        flashInput.addEventListener('change', (e) => handleFileUpload(e.target.files[0], 'flash'));
        videoInput.addEventListener('change', (e) => handleFileUpload(e.target.files[0], 'video'));
        fileInput.addEventListener('change', (e) => handleFileUpload(e.target.files[0], 'file'));

        if (replyPreviewClose) {
            replyPreviewClose.addEventListener('click', clearReplyTarget);
        }

        // 媒体预览
        mediaViewerBackdrop.addEventListener('click', closeMediaViewer);
        mediaViewerClose.addEventListener('click', closeMediaViewer);
        initMediaViewerControls();
        initFlashViewer();

        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            if (mediaViewer.style.display === 'flex') closeMediaViewer();
            if (flashViewer.style.display === 'flex') closeFlashViewer(false);
        });

        // 页面关闭时暂时离开（保留会话）
        window.addEventListener('beforeunload', () => {
            const fd = new FormData();
            fd.append('_csrf_token', config.csrfToken);
            fd.append('pause', '1');
            navigator.sendBeacon('/api/leave.php', fd);
            localStorage.setItem('pcr_notify_last_' + config.roomCode, String(lastMessageId));
        });

        // 标记已读
        messageList.addEventListener('scroll', debounce(markAsRead, 500));
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) markAsRead();
        });
    }

    // 初始化表情
    function initEmojis() {
        emojis.forEach(emoji => {
            const span = document.createElement('span');
            span.className = 'emoji-item';
            span.textContent = emoji;
            span.addEventListener('click', () => {
                messageInput.value += emoji;
                messageInput.focus();
                emojiPanel.style.display = 'none';
            });
            emojiGrid.appendChild(span);
        });
    }

    // 加载历史消息
    async function loadHistory() {
        try {
            const response = await fetch('/api/history.php');
            const data = await response.json();

            if (data.success) {
                if (data.room_ended) {
                    handleRoomEnded(true);
                    return;
                }
                messageList.innerHTML = '';
                data.messages.forEach(msg => appendMessage(msg, false));
                if (data.messages.length > 0) {
                    lastMessageId = data.messages[data.messages.length - 1].id;
                }
                scrollToBottom();
                markAsRead();

                if (data.members) {
                    applyMembersUpdate(data.members, data.typing_members);
                } else if (typeof data.partner_online === 'boolean') {
                    handlePartnerPresence(data.partner_online);
                }
                if (!data.members && typeof data.partner_typing === 'boolean') {
                    setPartnerTyping(data.partner_typing);
                }
                if (data.flash_destroyed_ids) {
                    removeDestroyedFlashMessages(data.flash_destroyed_ids);
                }
            }
        } catch (err) {
            messageList.innerHTML = '<div class="loading-messages">加载失败，请刷新页面</div>';
        }
    }

    // 发送文本消息
    async function sendMessage(e) {
        e.preventDefault();
        if (roomEnded) return;

        const content = messageInput.value.trim();
        if (!content) return;

        sendBtn.disabled = true;

        try {
            const formData = new FormData(messageForm);
            const response = await fetch('/api/send.php', {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                appendMessage({ ...data.message, is_mine: true }, true);
                lastMessageId = Math.max(lastMessageId, data.message.id);
                messageInput.value = '';
                messageInput.style.height = 'auto';
                clearReplyTarget();
                sendTypingState(false);
                scrollToBottom();
            } else {
                if (data.room_ended) {
                    handleRoomEnded(true);
                    return;
                }
                alert(data.error || '发送失败');
            }
        } catch (err) {
            alert('网络错误');
        }

        sendBtn.disabled = false;
        messageInput.focus();
    }

    // 轮询新消息
    function startPolling() {
        pollTimer = setInterval(pollMessages, config.pollInterval);
        pollMessages();
    }

    async function pollMessages() {
        if (isPolling || roomEnded) return;
        isPolling = true;

        try {
            const response = await fetch(`/api/poll.php?last_id=${lastMessageId}`);
            const data = await response.json();

            if (data.room_ended) {
                partnerOnline = false;
                partnerPresenceReady = true;
                handleRoomEnded(true);
                return;
            }

            if (data.members) {
                applyMembersUpdate(data.members, data.typing_members);
            } else if (typeof data.partner_online === 'boolean') {
                handlePartnerPresence(data.partner_online);
            }

            if (!data.members && typeof data.partner_typing === 'boolean') {
                setPartnerTyping(data.partner_typing);
            }

            if (data.members) {
                applyMembersUpdate(data.members, data.typing_members);
            } else if (typeof data.partner_online === 'boolean') {
                handlePartnerPresence(data.partner_online);
            }

            if (!data.members && typeof data.partner_typing === 'boolean') {
                setPartnerTyping(data.partner_typing);
            }

            if (data.success && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    // 避免重复显示自己刚发的消息
                    if (!msg.is_mine || msg.id > lastMessageId) {
                        appendMessage(msg, true);
                    }
                });
                lastMessageId = data.last_id;
                scrollToBottom();

                // 收到新消息时标记已读
                if (document.visibilityState === 'visible') {
                    markAsRead();
                }
            }

            if (data.flash_destroyed_ids) {
                removeDestroyedFlashMessages(data.flash_destroyed_ids);
            }

            // 后台标签页收到对方消息时弹出通知
            if (data.success && data.messages.length > 0 && document.hidden && typeof PcrNotify !== 'undefined') {
                data.messages.forEach((msg) => {
                    if (!msg.is_mine) {
                        const preview = msg.type === 'text'
                            ? (msg.sender + '：' + (msg.content || '').slice(0, 60))
                            : (msg.type === 'image' ? msg.sender + ' 发来图片' : msg.type === 'video' ? msg.sender + ' 发来视频' : msg.type === 'flash' ? msg.sender + ' 发来闪图' : msg.sender + ' 发来文件');
                        PcrNotify.notify(preview, document.title.split(' - ').pop());
                    }
                });
            }
        } catch (err) {
            if (!roomEnded) {
                onlineStatus.textContent = '连接中断';
                onlineStatus.classList.remove('connected');
            }
        }

        isPolling = false;
    }

    // 心跳
    function startHeartbeat() {
        heartbeatTimer = setInterval(async () => {
            try {
                const res = await fetch('/api/leave.php?action=heartbeat');
                const data = await res.json();

                if (data.room_ended) {
                    partnerOnline = false;
                    partnerPresenceReady = true;
                    handleRoomEnded(true);
                    return;
                }

                if (typeof data.partner_online === 'boolean') {
                    handlePartnerPresence(data.partner_online);
                }
                if (data.members) {
                    applyMembersUpdate(data.members);
                }
            } catch (e) { /* ignore */ }
        }, config.heartbeatInterval);
    }

    // 添加消息到列表
    function appendMessage(msg, animate) {
        // 检查是否已存在
        if (document.querySelector(`[data-id="${msg.id}"]`)) return;

        const div = document.createElement('div');
        div.className = `message ${msg.is_mine ? 'mine' : 'other'}`;
        div.dataset.id = msg.id;

        let content = '';
        const replyQuote = buildReplyQuoteHtml(msg.reply_to);

        switch (msg.type) {
            case 'text':
                content = `${replyQuote}<div class="message-text">${escapeHtml(msg.content)}</div>`;
                break;

            case 'image':
                const imgSrc = resolveMediaUrl(msg.thumb_path || msg.content);
                const fullSrc = resolveMediaUrl(msg.content);
                content = `${replyQuote}<img class="message-image" src="${escapeHtml(imgSrc)}" alt="图片" data-full="${escapeHtml(fullSrc)}" data-download="${escapeHtml(mediaDownloadUrl(msg))}" loading="lazy">`;
                break;

            case 'flash':
                const flashThumb = msg.thumb_path ? resolveMediaUrl(msg.thumb_path) : '';
                const flashFull = resolveMediaUrl(msg.content);
                const flashThumbHtml = flashThumb
                    ? `<img class="message-flash-thumb" src="${escapeHtml(flashThumb)}" alt="" loading="lazy">`
                    : '<div class="message-flash-thumb-placeholder">闪图</div>';
                content = `${replyQuote}<div class="message-flash-card" data-flash-id="${msg.id}" data-flash-src="${escapeHtml(flashFull)}" role="button" tabindex="0" aria-label="长按查看闪图">
                    ${flashThumbHtml}
                    <div class="message-flash-overlay">
                        <span class="message-flash-tag">闪图</span>
                        <span class="message-flash-tip">长按查看</span>
                    </div>
                </div>`;
                break;

            case 'video': {
                const videoSrc = resolveMediaUrl(msg.content);
                const videoThumb = msg.thumb_path ? resolveMediaUrl(msg.thumb_path) : '';
                const videoPoster = videoThumb
                    ? `<img class="message-video-poster" src="${escapeHtml(videoThumb)}" alt="">`
                    : '<div class="message-video-poster-placeholder" aria-hidden="true">▶</div>';
                const videoSizeLabel = msg.file_size_text || '视频';
                content = `${replyQuote}<div class="message-video-card" data-video-src="${escapeHtml(videoSrc)}" data-download="${escapeHtml(mediaDownloadUrl(msg))}" role="button" tabindex="0" aria-label="播放视频">
                    ${videoPoster}
                    <div class="message-video-overlay">
                        <span class="message-video-play-btn" aria-hidden="true">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        </span>
                    </div>
                    <span class="message-video-badge">${escapeHtml(videoSizeLabel)}</span>
                </div>`;
                break;
            }

            case 'file':
                const fileDownload = mediaDownloadUrl(msg);
                content = `${replyQuote}<div class="message-file">
                    <span class="message-file-icon">📄</span>
                    <div class="message-file-info">
                        <div class="message-file-name">${escapeHtml(msg.file_name)}</div>
                        <div class="message-file-size">${msg.file_size_text || ''}</div>
                    </div>
                    <a class="message-file-download" href="${escapeHtml(fileDownload)}" download="${escapeHtml(msg.file_name || 'file')}">下载</a>
                </div>`;
                break;
        }

        const time = formatTime(msg.time);
        const status = msg.status || 'sent';
        const statusHtml = msg.is_mine
            ? `<span class="message-status ${status}" title="${statusTitle(status)}" aria-label="${statusTitle(status)}"></span>`
            : '';

        const senderNameHtml = msg.is_mine ? '' : `<div class="message-sender-name">${escapeHtml(msg.sender)}</div>`;
        const avatarColHtml = msg.is_mine ? '' : `<div class="message-avatar-col">${renderAvatarHtml(msg.sender_avatar, 'avatar-md')}</div>`;

        div.innerHTML = `
            <div class="message-row">
                ${avatarColHtml}
                <div class="message-main">
                    ${senderNameHtml}
                    <div class="message-bubble">
                        ${content}
                        <div class="message-meta">
                            <span class="message-time">${time}</span>
                            ${statusHtml}
                        </div>
                    </div>
                </div>
            </div>
        `;

        const bubble = div.querySelector('.message-bubble');
        bindMessageReplyGesture(bubble, msg);
        bindReplyQuote(div);

        // 图片点击预览
        const img = div.querySelector('.message-image');
        if (img) {
            img.addEventListener('click', () => openMediaViewer('image', img.dataset.full, img.dataset.download));
        }

        const videoCard = div.querySelector('.message-video-card');
        if (videoCard) {
            bindVideoCard(videoCard);
        }

        const flashCard = div.querySelector('.message-flash-card');
        if (flashCard) {
            bindFlashCard(flashCard);
        }

        messageList.appendChild(div);
    }

    function bindFlashCard(card) {
        const open = () => {
            const id = parseInt(card.dataset.flashId, 10);
            const src = card.dataset.flashSrc;
            if (id && src) openFlashViewer(id, src);
        };

        let holdTimer = null;

        const startHold = (e) => {
            e.preventDefault();
            clearTimeout(holdTimer);
            holdTimer = setTimeout(() => {
                holdTimer = null;
                open();
            }, 400);
        };

        const endHold = () => clearTimeout(holdTimer);

        card.addEventListener('mousedown', startHold);
        card.addEventListener('touchstart', startHold, { passive: false });
        card.addEventListener('mouseup', endHold);
        card.addEventListener('mouseleave', endHold);
        card.addEventListener('touchend', endHold);
        card.addEventListener('touchcancel', endHold);
        card.addEventListener('click', (e) => e.preventDefault());
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });
    }

    function removeDestroyedFlashMessages(ids) {
        if (!Array.isArray(ids)) return;
        ids.forEach(id => {
            const el = document.querySelector(`[data-id="${id}"]`);
            if (el) {
                el.remove();
            }
        });
    }

    function bindVideoCard(card) {
        const src = card.dataset.videoSrc;
        if (!src) return;

        const download = card.dataset.download || src;
        const open = () => openMediaViewer('video', src, download);
        card.addEventListener('click', open);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });
    }

    // 文件上传
    async function handleFileUpload(file, type) {
        if (!file || roomEnded) return;

        uploadProgress.style.display = 'block';
        progressFill.style.width = '0%';
        progressText.textContent = '准备上传…';

        let uploadFile = file;
        let clientCompressed = false;
        let originalSize = file.size;

        if (type === 'video' && globalThis.VideoCompress) {
            progressText.textContent = '正在压缩视频…';
            try {
                const result = await VideoCompress.compress(
                    file,
                    config.videoCompress,
                    (pct) => {
                        progressFill.style.width = pct + '%';
                        progressText.textContent = `压缩中 ${pct}%`;
                    }
                );
                uploadFile = result.file;
                clientCompressed = !!result.compressed;
                originalSize = result.originalSize || file.size;
                if (result.error) {
                    console.warn('Video compress fallback:', result.error);
                }
            } catch (e) {
                console.warn('Video compress error', e);
            }
        }

        progressFill.style.width = '0%';
        progressText.textContent = '上传中...';

        const formData = new FormData();
        formData.append('file', uploadFile);
        formData.append('_csrf_token', config.csrfToken);
        if (type === 'flash') {
            formData.append('flash', '1');
        }
        if (clientCompressed) {
            formData.append('client_compressed', '1');
            formData.append('original_size', String(originalSize));
        }

        try {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressFill.style.width = percent + '%';
                    if (type === 'video' && percent >= 100) {
                        progressText.textContent = '上传完成…';
                    } else {
                        progressText.textContent = `上传中 ${percent}%`;
                    }
                }
            });

            xhr.addEventListener('load', () => {
                uploadProgress.style.display = 'none';

                if (xhr.status === 200) {
                    let data;
                    try {
                        data = JSON.parse(xhr.responseText);
                    } catch (e) {
                        alert('上传失败：服务器响应异常');
                        return;
                    }
                    if (data.success) {
                        const msg = { ...data.message, is_mine: true };
                        appendMessage(msg, true);
                        lastMessageId = Math.max(lastMessageId, data.message.id);
                        scrollToBottom();
                        if (type === 'video' && clientCompressed) {
                            const origMb = (originalSize / 1024 / 1024).toFixed(1);
                            const newMb = (uploadFile.size / 1024 / 1024).toFixed(1);
                            showSettingToast(`视频已压缩：${origMb}MB → ${newMb}MB`);
                        }
                    } else {
                        if (data.room_ended) {
                            handleRoomEnded(true);
                            return;
                        }
                        alert(data.error || '上传失败');
                    }
                } else {
                    let err = '上传失败 (HTTP ' + xhr.status + ')';
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.error) err = data.error;
                    } catch (e) { /* ignore */ }
                    if (xhr.status === 403) {
                        err += '\n\n请刷新页面后重试。若视频很大，请检查服务器 post_max_size 是否 ≥ 210M';
                    }
                    alert(err);
                }
            });

            xhr.addEventListener('error', () => {
                uploadProgress.style.display = 'none';
                alert('网络错误，上传中断');
            });

            xhr.open('POST', config.uploadUrl || '/api/upload.php');
            if (type === 'video') {
                xhr.timeout = 900000;
            }
            xhr.send(formData);
        } catch (err) {
            uploadProgress.style.display = 'none';
            alert('上传失败');
        }

        // 清空 input
        if (type === 'image') imageInput.value = '';
        if (type === 'flash') flashInput.value = '';
        if (type === 'video') videoInput.value = '';
        if (type === 'file') fileInput.value = '';
    }

    // 标记已读
    async function markAsRead() {
        try {
            const formData = new FormData();
            formData.append('_csrf_token', config.csrfToken);
            await fetch('/api/read.php', { method: 'POST', body: formData });

            // 更新 UI 状态
            document.querySelectorAll('.message.mine .message-status').forEach(el => {
                el.className = 'message-status read';
            });
        } catch (e) { /* ignore */ }
    }

    // 暂时离开房间
    async function pauseLeaveRoom() {
        if (roomEnded) return;
        if (!confirm('暂时离开？聊天记录保留，回到网站可继续聊天，并会收到新消息通知。')) {
            return;
        }

        clearInterval(pollTimer);
        clearInterval(heartbeatTimer);

        const formData = new FormData();
        formData.append('_csrf_token', config.csrfToken);
        formData.append('pause', '1');

        try {
            await fetch('/api/leave.php', { method: 'POST', body: formData });
        } catch (e) { /* ignore */ }

        localStorage.setItem('pcr_notify_last_' + config.roomCode, String(lastMessageId));
        window.location.replace('/?paused=1');
    }

    // 显示退出确认
    function showExitConfirm() {
        if (roomEnded) {
            exitChat();
            return;
        }
        exitConfirmModal.style.display = 'flex';
    }

    function hideExitConfirm() {
        exitConfirmModal.style.display = 'none';
    }

    // 确认退出房间（可选先导出 ZIP）
    async function confirmExitRoom(saveFirst) {
        hideExitConfirm();

        if (saveFirst) {
            exportProgressModal.style.display = 'flex';
            const exported = await downloadChatExport();
            exportProgressModal.style.display = 'none';

            if (!exported) {
                if (!confirm('导出失败或已取消。是否仍要不保存记录并退出？')) {
                    showExitConfirm();
                    return;
                }
            }
        }

        await finishExitRoom();
    }

    async function downloadChatExport() {
        const progressText = document.getElementById('exportProgressText');
        if (progressText) {
            progressText.textContent = '正在打包聊天记录与文件，请稍候…';
        }

        try {
            const fd = new FormData();
            fd.append('_csrf_token', config.csrfToken);

            const res = await fetch('/api/export_chat.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            });

            const contentType = res.headers.get('Content-Type') || '';

            if (!res.ok || contentType.includes('application/json')) {
                let err = '导出失败';
                try {
                    const data = await res.json();
                    err = data.error || err;
                } catch (e) { /* ignore */ }
                alert(err);
                return false;
            }

            const blob = await res.blob();
            if (blob.size === 0) {
                alert('导出文件为空');
                return false;
            }

            const disposition = res.headers.get('Content-Disposition') || '';
            let filename = `chat_${config.roomCode}_${Date.now()}.zip`;
            const match = disposition.match(/filename="?([^";\n]+)"?/);
            if (match) {
                filename = match[1].trim();
            }

            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            if (progressText) {
                progressText.textContent = '打包完成，正在退出房间…';
            }

            // 留出时间触发浏览器下载
            await new Promise(r => setTimeout(r, 800));
            return true;
        } catch (e) {
            alert('导出失败：网络错误');
            return false;
        }
    }

    async function finishExitRoom() {
        clearInterval(pollTimer);
        clearInterval(heartbeatTimer);

        const formData = new FormData();
        formData.append('_csrf_token', config.csrfToken);
        formData.append('end_room', '1');

        try {
            await fetch('/api/leave.php', { method: 'POST', body: formData });
        } catch (e) { /* ignore */ }

        window.location.replace('/');
    }

    // 对方退出后，剩余用户点击退出聊天
    async function exitChat() {
        clearInterval(pollTimer);
        clearInterval(heartbeatTimer);

        const formData = new FormData();
        formData.append('_csrf_token', config.csrfToken);
        formData.append('abandon', '1');

        try {
            await fetch('/api/leave.php', { method: 'POST', body: formData });
        } catch (e) { /* ignore */ }

        window.location.replace('/');
    }

    // 处理房间已结束（对方退出）
    function handleRoomEnded(showAlert) {
        if (roomEnded) return;
        roomEnded = true;
        partnerOnline = false;
        partnerPresenceReady = true;

        clearInterval(pollTimer);
        clearInterval(heartbeatTimer);

        // 清空消息列表
        messageList.innerHTML = '<div class="loading-messages ended-hint">聊天记录已清除</div>';

        // 切换底部栏
        chatInputArea.style.display = 'none';
        chatEndedBar.style.display = 'flex';

        onlineStatus.textContent = '聊天已结束';
        onlineStatus.classList.remove('connected');

        if (showAlert && !partnerLeftNotified) {
            partnerLeftNotified = true;
            partnerLeftModal.style.display = 'flex';
        }
    }

    // 媒体预览（图片 / 视频）
    function openMediaViewer(type, src, downloadSrc) {
        if (!src) return;

        mediaViewerTitle.textContent = type === 'video' ? '视频预览' : '图片预览';
        const dl = downloadSrc || src;
        mediaViewerDownload.href = dl;
        mediaViewerDownload.setAttribute('download', '');
        mediaViewerDownload.style.display = '';

        mediaViewerImage.style.display = 'none';
        mediaViewerImage.removeAttribute('src');
        mediaViewerVideoWrap.style.display = 'none';
        resetViewerVideo();

        if (type === 'video') {
            mediaViewerVideoWrap.style.display = 'flex';
            mediaViewer.style.display = 'flex';
            mediaViewer.setAttribute('aria-hidden', 'false');
            document.body.classList.add('media-viewer-open');
            startVideoPreload(src);
            return;
        }

        mediaViewerImage.style.display = 'block';
        mediaViewerImage.src = src;
        mediaViewer.style.display = 'flex';
        mediaViewer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('media-viewer-open');
    }

    function startVideoPreload(src) {
        if (mvLoadAbort) {
            mvLoadAbort();
            mvLoadAbort = null;
        }

        const video = mediaViewerVideo;
        showMvLoading(true, 0, '');

        let settled = false;
        let totalBytes = 0;
        const startTime = Date.now();

        const finish = (ok, message) => {
            if (settled) return;
            settled = true;
            mvLoadAbort = null;
            video.removeEventListener('progress', onProgress);
            video.removeEventListener('loadedmetadata', onMeta);
            video.removeEventListener('canplaythrough', onReady);
            video.removeEventListener('error', onError);

            if (ok) {
                showMvLoading(false, 100, '');
                video.style.display = 'block';
                mvControls.style.display = 'flex';
                mvControls.classList.remove('is-disabled');
                mvPlayBtn.disabled = false;
            } else {
                showMvLoading(true, 0, '');
                if (mvLoadingText) mvLoadingText.textContent = message || '加载失败';
                if (mvLoadingSpeed) mvLoadingSpeed.textContent = '';
                mvControls.style.display = 'none';
            }
        };

        const onProgress = () => {
            if (!video.duration || !isFinite(video.duration)) return;
            let bufferedEnd = 0;
            for (let i = 0; i < video.buffered.length; i++) {
                bufferedEnd = Math.max(bufferedEnd, video.buffered.end(i));
            }
            const pct = Math.min(100, Math.round((bufferedEnd / video.duration) * 100));
            const elapsed = (Date.now() - startTime) / 1000;
            let speedText = '';
            if (totalBytes > 0 && elapsed > 0.3) {
                const loaded = totalBytes * (bufferedEnd / video.duration);
                speedText = formatNetworkSpeed(loaded / elapsed);
            }
            showMvLoading(true, pct, speedText);
        };

        const onMeta = () => {
            mvTime.textContent = `0:00 / ${formatDuration(video.duration)}`;
        };

        const onReady = () => finish(true);
        const onError = () => finish(false, '视频加载失败，请稍后重试');

        mvLoadAbort = () => {
            settled = true;
            video.removeAttribute('src');
            video.load();
        };

        video.pause();
        video.removeAttribute('src');
        video.load();
        if (mvBlobUrl) {
            URL.revokeObjectURL(mvBlobUrl);
            mvBlobUrl = null;
        }

        fetch(src, { method: 'HEAD', credentials: 'same-origin' })
            .then((r) => {
                if (r.ok) {
                    totalBytes = parseInt(r.headers.get('Content-Length') || '0', 10) || 0;
                }
            })
            .catch(() => {});

        video.addEventListener('progress', onProgress);
        video.addEventListener('loadedmetadata', onMeta);
        video.addEventListener('canplaythrough', onReady);
        video.addEventListener('error', onError);

        video.src = src;
        video.load();
    }

    function formatNetworkSpeed(bytesPerSec) {
        if (!bytesPerSec || bytesPerSec <= 0) return '';
        if (bytesPerSec >= 1024 * 1024) {
            return (bytesPerSec / 1024 / 1024).toFixed(2) + ' MB/s';
        }
        if (bytesPerSec >= 1024) {
            return (bytesPerSec / 1024).toFixed(1) + ' KB/s';
        }
        return Math.round(bytesPerSec) + ' B/s';
    }

    function showMvLoading(visible, percent, speedText) {
        if (!mvLoading) return;
        mvLoading.style.display = visible ? 'flex' : 'none';
        if (mvLoadingFill) {
            mvLoadingFill.style.width = `${Math.max(0, Math.min(100, percent))}%`;
        }
        if (mvLoadingText && visible) {
            if (percent >= 100) {
                mvLoadingText.textContent = '加载完成，点击播放';
            } else if (percent > 0) {
                mvLoadingText.textContent = `正在加载视频 ${percent}%`;
            } else {
                mvLoadingText.textContent = '正在加载视频…';
            }
        }
        if (mvLoadingSpeed) {
            mvLoadingSpeed.textContent = speedText ? `网速 ${speedText}` : '';
        }
    }

    function closeMediaViewer() {
        if (mvLoadAbort) {
            mvLoadAbort();
            mvLoadAbort = null;
        }

        mediaViewer.style.display = 'none';
        mediaViewer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('media-viewer-open');
        mediaViewerImage.removeAttribute('src');
        mediaViewerImage.style.display = 'none';
        mediaViewerVideoWrap.style.display = 'none';
        resetViewerVideo();
    }

    function resetViewerVideo() {
        if (!mediaViewerVideo) return;
        mediaViewerVideo.pause();
        mediaViewerVideo.removeAttribute('src');
        mediaViewerVideo.load();
        mediaViewerVideo.style.display = 'none';
        if (mvBlobUrl) {
            URL.revokeObjectURL(mvBlobUrl);
            mvBlobUrl = null;
        }
        showMvLoading(false, 0, '');
        if (mvControls) {
            mvControls.style.display = 'none';
            mvControls.classList.add('is-disabled');
        }
        if (mvPlayBtn) mvPlayBtn.disabled = true;
        updateMvPlayIcon(false);
        mvProgress.value = 0;
        mvTime.textContent = '0:00 / 0:00';
        updateMvMuteIcon(false);
    }

    // ---------- 闪图（按住查看，松手隐藏，累计时长后销毁） ----------
    function initFlashViewer() {
        if (!flashViewer) return;

        flashViewerBackdrop.addEventListener('click', () => closeFlashViewer(false));
        flashViewerClose.addEventListener('click', () => closeFlashViewer(false));

        const body = flashViewer.querySelector('.flash-viewer-body');
        if (!body) return;

        let holdTimer = null;

        const startHold = () => {
            if (!currentFlashId) return;
            clearTimeout(holdTimer);
            holdTimer = setTimeout(() => {
                holdTimer = null;
                resumeFlashView();
            }, 200);
        };

        const endHold = () => {
            clearTimeout(holdTimer);
            if (flashViewing) {
                pauseFlashView();
            }
        };

        body.addEventListener('mousedown', startHold);
        body.addEventListener('touchstart', startHold, { passive: true });
        body.addEventListener('mouseup', endHold);
        body.addEventListener('mouseleave', endHold);
        body.addEventListener('touchend', endHold);
        body.addEventListener('touchcancel', endHold);
    }

    function updateFlashCountdownDisplay() {
        const left = Math.ceil(flashViewSeconds - flashAccumulated);
        if (flashCountdownNum) {
            flashCountdownNum.textContent = String(Math.max(0, left));
        }
        if (left <= 0) {
            flashCountdown.style.display = 'none';
        } else if (flashViewing) {
            flashCountdown.style.display = 'flex';
        }
    }

    function resumeFlashView() {
        if (!currentFlashId || flashAccumulated >= flashViewSeconds) return;

        flashViewing = true;
        flashHoldHint.style.display = 'none';
        flashViewerImage.classList.add('is-visible');
        flashCountdown.style.display = 'flex';
        updateFlashCountdownDisplay();

        clearInterval(flashTickTimer);
        flashTickTimer = setInterval(() => {
            flashAccumulated += 0.25;
            updateFlashCountdownDisplay();
            if (flashAccumulated >= flashViewSeconds) {
                clearInterval(flashTickTimer);
                flashTickTimer = null;
                consumeFlashAndClose();
            }
        }, 250);
    }

    function pauseFlashView() {
        flashViewing = false;
        flashViewerImage.classList.remove('is-visible');
        flashHoldHint.style.display = 'flex';
        clearInterval(flashTickTimer);
        flashTickTimer = null;
        updateFlashCountdownDisplay();
    }

    function openFlashViewer(messageId, src) {
        if (roomEnded) return;

        currentFlashId = messageId;
        flashViewing = false;
        flashAccumulated = 0;

        flashViewerImage.src = '';
        flashViewerImage.classList.remove('is-visible');
        flashHoldHint.style.display = 'flex';
        flashCountdown.style.display = 'none';
        if (flashCountdownNum) flashCountdownNum.textContent = String(flashViewSeconds);

        flashViewer.style.display = 'flex';
        flashViewer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('media-viewer-open');

        const img = new Image();
        img.onload = () => {
            flashViewerImage.src = src;
        };
        img.onerror = () => {
            alert('闪图加载失败');
            closeFlashViewer(false);
        };
        img.src = src;
    }

    function beginFlashView() {
        resumeFlashView();
    }

    async function consumeFlashAndClose() {
        const id = currentFlashId;
        closeFlashViewer(true);

        if (!id) return;

        try {
            const fd = new FormData();
            fd.append('_csrf_token', config.csrfToken);
            fd.append('message_id', String(id));
            const res = await fetch('/api/flash_consume.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                removeDestroyedFlashMessages(data.flash_destroyed_ids || [id]);
                appendSystemNotice('闪图已销毁');
            }
        } catch (e) { /* ignore */ }
    }

    function closeFlashViewer(consumed) {
        clearInterval(flashCountdownTimer);
        clearInterval(flashTickTimer);
        flashCountdownTimer = null;
        flashTickTimer = null;
        flashViewing = false;
        flashAccumulated = 0;
        currentFlashId = null;

        flashViewer.style.display = 'none';
        flashViewer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('media-viewer-open');
        flashViewerImage.removeAttribute('src');
        flashViewerImage.classList.remove('is-visible');
        flashHoldHint.style.display = 'flex';
        flashCountdown.style.display = 'none';

        if (!consumed) {
            /* 未看完关闭不销毁 */
        }
    }

    function initMediaViewerControls() {
        const video = mediaViewerVideo;
        if (!video) return;

        mvPlayBtn.addEventListener('click', () => {
            if (mvPlayBtn.disabled || mvControls.classList.contains('is-disabled')) return;
            if (video.paused) {
                video.play().catch(() => alert('播放失败'));
            } else {
                video.pause();
            }
        });

        video.addEventListener('play', () => updateMvPlayIcon(true));
        video.addEventListener('pause', () => updateMvPlayIcon(false));
        video.addEventListener('ended', () => updateMvPlayIcon(false));

        video.addEventListener('loadedmetadata', () => {
            mvTime.textContent = `0:00 / ${formatDuration(video.duration)}`;
        });

        video.addEventListener('timeupdate', () => {
            if (mvSeeking || !video.duration) return;
            mvProgress.value = Math.round((video.currentTime / video.duration) * 1000);
            mvTime.textContent = `${formatDuration(video.currentTime)} / ${formatDuration(video.duration)}`;
        });

        mvProgress.addEventListener('input', () => {
            mvSeeking = true;
            if (video.duration) {
                const t = (mvProgress.value / 1000) * video.duration;
                mvTime.textContent = `${formatDuration(t)} / ${formatDuration(video.duration)}`;
            }
        });

        mvProgress.addEventListener('change', () => {
            if (video.duration) {
                video.currentTime = (mvProgress.value / 1000) * video.duration;
            }
            mvSeeking = false;
        });

        mvMuteBtn.addEventListener('click', () => {
            video.muted = !video.muted;
            updateMvMuteIcon(video.muted);
        });

        video.addEventListener('click', () => {
            if (mvControls.classList.contains('is-disabled')) return;
            if (video.paused) {
                video.play().catch(() => {});
            } else {
                video.pause();
            }
        });
    }

    function updateMvPlayIcon(playing) {
        const playIcon = mvPlayBtn.querySelector('.mv-icon-play');
        const pauseIcon = mvPlayBtn.querySelector('.mv-icon-pause');
        if (playIcon) playIcon.style.display = playing ? 'none' : 'block';
        if (pauseIcon) pauseIcon.style.display = playing ? 'block' : 'none';
    }

    function updateMvMuteIcon(muted) {
        const vol = mvMuteBtn.querySelector('.mv-icon-vol');
        const mutedIcon = mvMuteBtn.querySelector('.mv-icon-muted');
        if (vol) vol.style.display = muted ? 'none' : 'block';
        if (mutedIcon) mutedIcon.style.display = muted ? 'block' : 'none';
    }

    function formatDuration(seconds) {
        if (!seconds || !isFinite(seconds)) return '';
        const s = Math.floor(seconds % 60);
        const m = Math.floor(seconds / 60) % 60;
        const h = Math.floor(seconds / 3600);
        if (h > 0) {
            return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }
        return `${m}:${String(s).padStart(2, '0')}`;
    }

    // 工具函数
    function scrollToBottom() {
        messageList.scrollTop = messageList.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatClockTime(date) {
        const hour = String(date.getHours()).padStart(2, '0');
        const minute = String(date.getMinutes()).padStart(2, '0');
        return hour + '：' + minute;
    }

    function formatTime(datetime) {
        const date = parseMessageTime(datetime);
        if (Number.isNaN(date.getTime())) return '';

        const now = new Date();
        const isToday = date.toDateString() === now.toDateString();
        const time = formatClockTime(date);

        if (isToday) return time;

        return date.toLocaleDateString('zh-CN', { month: 'short', day: 'numeric' }) + ' ' + time;
    }

    function parseMessageTime(datetime) {
        if (!datetime) return new Date(NaN);

        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(datetime)) {
            const tz = config.timezone === 'Asia/Shanghai' ? '+08:00' : '';
            return new Date(datetime.replace(' ', 'T') + tz);
        }

        return new Date(datetime);
    }

    function debounce(fn, delay) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }
})();
