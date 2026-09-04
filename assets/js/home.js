/**
 * 首页 - 创建房间 / 加入房间（含昵称与头像）
 */

document.addEventListener('DOMContentLoaded', () => {
    const config = window.HOME_CONFIG || {};

    const joinModal = document.getElementById('joinModal');
    const createModal = document.getElementById('createModal');
    const shareModal = document.getElementById('shareModal');
    const globalError = document.getElementById('globalError');

    const btnCreateRoom = document.getElementById('btnCreateRoom');
    const btnJoinRoom = document.getElementById('btnJoinRoom');

    const joinForm = document.getElementById('joinForm');
    const joinRoomCode = document.getElementById('joinRoomCode');
    const joinPasswordField = document.getElementById('joinPasswordField');
    const joinPassword = document.getElementById('joinPassword');
    const joinSubmitBtn = document.getElementById('joinSubmitBtn');
    const joinNickname = document.getElementById('joinNickname');

    const createForm = document.getElementById('createForm');
    const customCodeField = document.getElementById('customCodeField');
    const customRoomCode = document.getElementById('customRoomCode');
    const enablePasswordCheck = document.getElementById('enablePasswordCheck');
    const createPasswordField = document.getElementById('createPasswordField');

    const shareRoomCode = document.getElementById('shareRoomCode');
    const shareUrl = document.getElementById('shareUrl');
    const shareText = document.getElementById('shareText');
    const copyUrlBtn = document.getElementById('copyUrlBtn');
    const copyTextBtn = document.getElementById('copyTextBtn');
    const enterCreatedRoomBtn = document.getElementById('enterCreatedRoomBtn');
    const shareNickname = document.getElementById('shareNickname');

    let createdRoomCode = '';
    let roomInfoTimer = null;
    let notifyPollTimer = null;
    let notifyLastId = 0;

    const pickerOptions = {
        presets: config.avatarPresets || [],
        colors: config.avatarColors || [],
    };

    const joinProfile = typeof AvatarPicker !== 'undefined'
        ? AvatarPicker.init({
            pickerEl: document.getElementById('joinAvatarPicker'),
            letterPreviewEl: document.getElementById('joinLetterPreview'),
            nicknameInput: joinNickname,
            ...pickerOptions,
        })
        : null;

    const shareProfile = typeof AvatarPicker !== 'undefined'
        ? AvatarPicker.init({
            pickerEl: document.getElementById('shareAvatarPicker'),
            letterPreviewEl: document.getElementById('shareLetterPreview'),
            nicknameInput: shareNickname,
            ...pickerOptions,
        })
        : null;

    const notifyStorageKey = config.resumeRoom
        ? 'pcr_notify_last_' + config.resumeRoom.code
        : null;

    if (notifyStorageKey) {
        notifyLastId = parseInt(localStorage.getItem(notifyStorageKey) || '0', 10) || 0;
    }

    const btnResumeChat = document.getElementById('btnResumeChat');
    if (btnResumeChat) {
        btnResumeChat.addEventListener('click', resumeChat);
        initPausedNotify();
    }

    if (typeof PcrNotify !== 'undefined') {
        PcrNotify.init({ csrfToken: config.csrfToken });
    }
    if (config.inviteRoom) {
        openModal(joinModal);
        checkRoomInfo(config.inviteRoom);
    }

    btnCreateRoom.addEventListener('click', () => openModal(createModal));
    btnJoinRoom.addEventListener('click', () => openModal(joinModal));

    document.querySelectorAll('[data-close]').forEach(el => {
        el.addEventListener('click', () => {
            closeModal(joinModal);
            closeModal(createModal);
            closeModal(shareModal);
        });
    });

    createForm.querySelectorAll('input[name="room_code_mode"]').forEach(radio => {
        radio.addEventListener('change', () => {
            const isCustom = createForm.querySelector('input[name="room_code_mode"]:checked')?.value === 'custom';
            customCodeField.style.display = isCustom ? 'block' : 'none';
        });
    });

    enablePasswordCheck.addEventListener('change', () => {
        createPasswordField.style.display = enablePasswordCheck.checked ? 'block' : 'none';
    });

    joinRoomCode.addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/\D/g, '');
        debounceCheckRoom(e.target.value.trim());
    });

    customRoomCode.addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/\D/g, '');
    });

    joinForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const code = joinRoomCode.value.trim();
        if (!/^\d{4,12}$/.test(code)) {
            showGlobalError('请输入4-12位数字房间号');
            return;
        }
        const profile = joinProfile ? joinProfile.getData() : {};
        await submitJoin(code, joinPassword.value, profile);
    });

    createForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        await submitCreate();
    });

    copyUrlBtn.addEventListener('click', () => copyToClipboard(shareUrl.value, '链接已复制'));
    copyTextBtn.addEventListener('click', () => copyToClipboard(shareText.value, '分享文案已复制'));

    enterCreatedRoomBtn.addEventListener('click', async () => {
        if (!createdRoomCode) return;
        const profile = shareProfile ? shareProfile.getData() : {};
        await submitJoin(createdRoomCode, '', profile);
    });

    function appendProfileFields(fd, profile) {
        if (!profile) return;
        if (profile.avatar_svg) {
            fd.append('avatar_svg', profile.avatar_svg);
        } else if (profile.avatar_letter) {
            fd.append('avatar_letter', '1');
        }
        if (profile.avatar_file) {
            fd.append('avatar', profile.avatar_file);
        }
    }

    function openModal(modal) {
        hideGlobalError();
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.style.display = 'none';
        if (!document.querySelector('.home-modal[style*="flex"]')) {
            document.body.style.overflow = '';
        }
    }

    function debounceCheckRoom(code) {
        clearTimeout(roomInfoTimer);
        if (!/^\d{4,12}$/.test(code)) {
            joinPasswordField.style.display = 'none';
            return;
        }
        roomInfoTimer = setTimeout(() => checkRoomInfo(code), 400);
    }

    async function checkRoomInfo(code) {
        try {
            const res = await fetch(`/api/room_info.php?room=${encodeURIComponent(code)}`);
            const data = await res.json();
            if (data.exists && data.has_password) {
                joinPasswordField.style.display = 'block';
            } else {
                joinPasswordField.style.display = 'none';
                joinPassword.value = '';
            }
            if (data.exists && data.is_ended) {
                showGlobalError('该房间已关闭');
            } else if (data.exists === false) {
                showGlobalError('房间不存在，请确认房间号');
            } else {
                hideGlobalError();
            }
        } catch (e) { /* ignore */ }
    }

    async function submitJoin(roomCode, password, profile = {}) {
        joinSubmitBtn.disabled = true;
        joinSubmitBtn.textContent = '进入中...';
        hideGlobalError();

        const fd = new FormData();
        fd.append('_csrf_token', config.csrfToken);
        fd.append('room_code', roomCode);
        fd.append('password', password);

        const nickname = (joinNickname && joinNickname.value.trim()) || (shareNickname && shareNickname.value.trim()) || '';
        if (nickname) {
            fd.append('nickname', nickname);
        }

        const savedToken = localStorage.getItem('pcr_token_' + roomCode);
        if (savedToken) {
            fd.append('restore_token', savedToken);
        } else {
            appendProfileFields(fd, profile);
        }

        try {
            const res = await fetch('/api/join.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();

            if (data.success) {
                if (data.session_token && roomCode) {
                    localStorage.setItem('pcr_token_' + roomCode, data.session_token);
                }
                window.location.replace(data.redirect || '/chat.php');
                return;
            }

            if (data.need_password) {
                joinPasswordField.style.display = 'block';
            }

            showGlobalError(data.error || '加入失败');
            joinSubmitBtn.disabled = false;
            joinSubmitBtn.textContent = '进入房间';
        } catch (e) {
            showGlobalError('网络错误');
            joinSubmitBtn.disabled = false;
            joinSubmitBtn.textContent = '进入房间';
        }
    }

    async function submitCreate() {
        const createSubmitBtn = document.getElementById('createSubmitBtn');
        const mode = createForm.querySelector('input[name="room_code_mode"]:checked')?.value || 'random';
        const useRandom = mode === 'random';
        if (!useRandom) {
            const code = customRoomCode.value.trim();
            if (!/^\d{4,12}$/.test(code)) {
                showGlobalError('自定义房间号须为4-12位数字');
                return;
            }
        }

        const passwordInput = document.getElementById('createPassword');
        if (enablePasswordCheck.checked) {
            if ((passwordInput?.value || '').length < 4) {
                showGlobalError('房间密码至少4位');
                return;
            }
        }

        createSubmitBtn.disabled = true;
        createSubmitBtn.textContent = '创建中...';
        hideGlobalError();

        const payload = {
            _csrf_token: config.csrfToken || createForm.querySelector('[name="_csrf_token"]')?.value || '',
            use_random: useRandom ? 1 : 0,
            enable_password: enablePasswordCheck.checked ? 1 : 0,
        };

        if (!useRandom) {
            payload.room_code = customRoomCode.value.trim();
        }
        if (enablePasswordCheck.checked) {
            payload.password = passwordInput.value;
        }

        try {
            const res = await fetch('/api/create_room.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': payload._csrf_token,
                },
                body: JSON.stringify(payload),
            });

            const text = await res.text();
            let data = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch (e) {
                showGlobalError('创建失败 (HTTP ' + res.status + ')，服务器返回非 JSON');
                return;
            }

            if (!data) {
                showGlobalError('创建失败 (HTTP ' + res.status + ')，无响应内容');
                return;
            }

            if (data.success) {
                createdRoomCode = data.room_code;
                shareRoomCode.textContent = data.room_code;
                shareUrl.value = data.invite_url;
                shareText.value = data.share_text;

                closeModal(createModal);
                openModal(shareModal);
                return;
            }

            let msg = data.error || ('创建失败 (HTTP ' + res.status + ')');
            if (data.hint) {
                msg += '（' + data.hint + '）';
            }
            showGlobalError(msg);
        } catch (e) {
            showGlobalError('网络错误，请检查网络后重试');
        } finally {
            createSubmitBtn.disabled = false;
            createSubmitBtn.textContent = '创建房间';
        }
    }

    async function copyToClipboard(text, okMsg) {
        try {
            await navigator.clipboard.writeText(text);
            showGlobalError(okMsg, 'success');
        } catch (e) {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showGlobalError(okMsg, 'success');
        }
    }

    function showGlobalError(msg, type = 'error') {
        globalError.textContent = msg;
        globalError.style.display = 'block';
        globalError.className = type === 'success' ? 'success-msg' : 'error-msg';
    }

    function hideGlobalError() {
        globalError.style.display = 'none';
    }

    async function resumeChat() {
        btnResumeChat.disabled = true;
        btnResumeChat.textContent = '进入中...';

        const fd = new FormData();
        fd.append('_csrf_token', config.csrfToken);

        try {
            const res = await fetch('/api/resume.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) {
                window.location.href = data.redirect || '/chat.php';
                return;
            }
            showGlobalError(data.error || '无法继续聊天');
        } catch (e) {
            showGlobalError('网络错误');
        }

        btnResumeChat.disabled = false;
        btnResumeChat.textContent = '继续聊天';
    }

    function initPausedNotify() {
        if (!config.resumeRoom) return;

        const poll = async () => {
            try {
                const res = await fetch(`/api/poll_notify.php?last_id=${notifyLastId}`, { credentials: 'same-origin' });
                const data = await res.json();

                if (data.room_ended) {
                    clearInterval(notifyPollTimer);
                    showGlobalError('对方已退出，聊天已结束');
                    return;
                }

                if (data.success && data.messages && data.messages.length > 0) {
                    data.messages.forEach((msg) => {
                        if (typeof PcrNotify !== 'undefined') {
                            PcrNotify.notify(msg.preview || '您有一条新消息', config.appName);
                        }
                    });
                    notifyLastId = data.last_id;
                    if (notifyStorageKey) {
                        localStorage.setItem(notifyStorageKey, String(notifyLastId));
                    }
                }
            } catch (e) { /* ignore */ }
        };

        poll();
        notifyPollTimer = setInterval(poll, config.pollInterval || 3000);
    }
});
