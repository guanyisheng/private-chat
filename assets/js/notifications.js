/**
 * 消息通知：Web Push + 浏览器通知（Android / iOS / Windows / Mac）
 */
(function (global) {
    'use strict';

    let swRegistration = null;
    let vapidPublicKey = null;
    let pushEnabled = false;

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(base64);
        const arr = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return null;
        try {
            swRegistration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            return swRegistration;
        } catch (e) {
            console.warn('SW register failed', e);
            return null;
        }
    }

    async function loadVapidKey() {
        try {
            const res = await fetch('/api/push_vapid.php');
            const data = await res.json();
            pushEnabled = !!data.enabled && !!data.public_key;
            vapidPublicKey = data.public_key || null;
        } catch (e) {
            pushEnabled = false;
        }
    }

    async function requestPermission() {
        if (!('Notification' in window)) return false;
        if (Notification.permission === 'granted') return true;
        if (Notification.permission === 'denied') return false;
        const result = await Notification.requestPermission();
        return result === 'granted';
    }

    async function subscribePush(csrfToken) {
        if (!pushEnabled || !vapidPublicKey || !swRegistration) return false;
        if (!('PushManager' in window)) return false;

        try {
            let sub = await swRegistration.pushManager.getSubscription();
            if (!sub) {
                sub = await swRegistration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                });
            }

            const res = await fetch('/api/push_subscribe.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify(sub.toJSON()),
                credentials: 'same-origin',
            });

            return (await res.json()).success === true;
        } catch (e) {
            console.warn('Push subscribe failed', e);
            return false;
        }
    }

    function showBrowserNotification(title, body, url) {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        const opts = {
            body: body,
            tag: 'pcr-msg-' + Date.now(),
            renotify: true,
            data: { url: url || '/chat.php' },
        };

        if (swRegistration) {
            swRegistration.showNotification(title, opts);
            return;
        }

        const n = new Notification(title, opts);
        n.onclick = () => {
            window.focus();
            window.location.href = url || '/chat.php';
            n.close();
        };
    }

    async function initNotifications(options) {
        const csrfToken = options.csrfToken || '';
        await registerServiceWorker();
        await loadVapidKey();

        const granted = await requestPermission();
        if (granted && csrfToken) {
            await subscribePush(csrfToken);
        }

        return { granted, pushEnabled };
    }

    function notifyNewMessage(preview, appName) {
        showBrowserNotification(appName || '私密聊天', preview, '/chat.php');
    }

    global.PcrNotify = {
        init: initNotifications,
        notify: notifyNewMessage,
        requestPermission,
        subscribePush,
    };
})(window);
