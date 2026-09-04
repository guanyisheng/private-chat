/**
 * 管理后台 JavaScript
 */

document.addEventListener('DOMContentLoaded', () => {
    // 确认对话框增强
    document.querySelectorAll('form[onsubmit]').forEach(form => {
        const originalOnsubmit = form.getAttribute('onsubmit');
        if (originalOnsubmit) {
            form.removeAttribute('onsubmit');
            form.addEventListener('submit', (e) => {
                const match = originalOnsubmit.match(/confirm\('([^']+)'\)/);
                if (match && !confirm(match[1])) {
                    e.preventDefault();
                }
            });
        }
    });
});
