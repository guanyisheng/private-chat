/**
 * 头像选择器 - 文字首字 + 预制 SVG（Xbox 风格）
 */
(function (global) {
    'use strict';

    const DEFAULT_COLORS = ['6c5ce7', '0984e3', '00b894', 'fdcb6e', 'e17055', 'd63031', 'a29bfe', '74b9ff'];

    function firstLetter(nickname) {
        const s = (nickname || '').trim();
        if (!s) return '?';
        return [...s][0] || '?';
    }

    function colorForNickname(nickname, colors) {
        const list = colors && colors.length ? colors : DEFAULT_COLORS;
        let hash = 0;
        const str = nickname || '';
        for (let i = 0; i < str.length; i++) {
            hash = ((hash << 5) - hash) + str.charCodeAt(i);
            hash |= 0;
        }
        return list[Math.abs(hash) % list.length];
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * @param {Object} options
     * @param {HTMLElement} options.pickerEl - 预制 SVG 网格容器
     * @param {HTMLElement} [options.letterPreviewEl] - 文字头像预览
     * @param {HTMLInputElement} [options.nicknameInput] - 昵称输入，实时更新首字
     * @param {string[]} [options.presets] - [{id, url, label}]
     * @param {string[]} [options.colors]
     */
    function initAvatarPicker(options) {
        const pickerEl = options.pickerEl;
        const letterPreviewEl = options.letterPreviewEl;
        const nicknameInput = options.nicknameInput;
        const presets = options.presets || [];
        const colors = options.colors || DEFAULT_COLORS;

        let mode = 'letter'; // letter | svg | upload
        let selectedPreset = '';
        let selectedFile = null;

        function updateLetterPreview() {
            const nick = nicknameInput ? nicknameInput.value.trim() : '';
            const letter = firstLetter(nick);
            const hex = colorForNickname(nick, colors);

            if (letterPreviewEl) {
                letterPreviewEl.innerHTML =
                    `<span class="user-avatar avatar-lg avatar-letter letter-preview-inner" style="background:#${hex}">${escapeHtml(letter)}</span>`;
            }

            const letterSlot = pickerEl?.querySelector('.avatar-letter-slot');
            if (letterSlot) {
                letterSlot.textContent = letter;
                letterSlot.style.background = `#${hex}`;
            }
        }

        function clearSelection() {
            pickerEl?.querySelectorAll('.avatar-preset-btn, .avatar-letter-btn').forEach(b => {
                b.classList.remove('is-selected');
            });
        }

        function selectLetter() {
            mode = 'letter';
            selectedPreset = '';
            selectedFile = null;
            clearSelection();
            pickerEl?.querySelector('.avatar-letter-btn')?.classList.add('is-selected');
            updateLetterPreview();
        }

        function selectPreset(id) {
            mode = 'svg';
            selectedPreset = id;
            selectedFile = null;
            clearSelection();
            pickerEl?.querySelector(`.avatar-preset-btn[data-preset="${id}"]`)?.classList.add('is-selected');
        }

        if (pickerEl) {
            let html = `
                <button type="button" class="avatar-letter-btn is-selected" title="文字头像（昵称首字，如「路南」→「路」）">
                    <span class="avatar-letter-slot">?</span>
                    <span class="avatar-preset-label">文字</span>
                </button>`;

            presets.forEach(p => {
                html += `
                    <button type="button" class="avatar-preset-btn" data-preset="${escapeHtml(p.id)}" title="${escapeHtml(p.label || '')}">
                        <img src="${escapeHtml(p.url)}" alt="" loading="eager" decoding="async">
                    </button>`;
            });

            pickerEl.innerHTML = html;

            const letterSlot = pickerEl.querySelector('.avatar-letter-slot');
            if (letterSlot && letterPreviewEl) {
                letterPreviewEl.classList.add('avatar-letter-live');
            }

            pickerEl.addEventListener('click', (e) => {
                const letterBtn = e.target.closest('.avatar-letter-btn');
                if (letterBtn) {
                    selectLetter();
                    return;
                }
                const presetBtn = e.target.closest('.avatar-preset-btn');
                if (presetBtn) {
                    selectPreset(presetBtn.dataset.preset);
                }
            });
        }

        if (nicknameInput) {
            nicknameInput.addEventListener('input', () => {
                updateLetterPreview();
                if (letterPreviewEl && mode === 'letter') {
                    /* 保持文字模式 */
                }
            });
        }

        updateLetterPreview();
        selectLetter();

        return {
            getData() {
                return {
                    mode,
                    avatar_svg: mode === 'svg' ? selectedPreset : '',
                    avatar_letter: mode === 'letter' ? '1' : '',
                    avatar_file: selectedFile,
                };
            },
            setUploadFile(file) {
                if (!file) return;
                selectedFile = file;
                mode = 'upload';
                selectedPreset = '';
                clearSelection();
            },
            updateLetterPreview,
            selectLetter,
            selectPreset,
        };
    }

    /**
     * 渲染头像 HTML
     */
    function renderAvatarHtml(avatar, className) {
        className = className || 'avatar-sm';
        if (!avatar) {
            return `<span class="user-avatar ${className} avatar-letter" style="background:#6c5ce7">?</span>`;
        }
        if ((avatar.type === 'svg' || avatar.type === 'image') && avatar.url) {
            return `<img class="user-avatar ${className} avatar-svg" src="${escapeHtml(avatar.url)}" alt="">`;
        }
        const color = escapeHtml(avatar.color || '#6c5ce7');
        const letter = escapeHtml(avatar.letter || '?');
        return `<span class="user-avatar ${className} avatar-letter" style="background:${color}">${letter}</span>`;
    }

    global.AvatarPicker = {
        init: initAvatarPicker,
        render: renderAvatarHtml,
        firstLetter,
        colorForNickname,
    };
})(typeof window !== 'undefined' ? window : globalThis);
