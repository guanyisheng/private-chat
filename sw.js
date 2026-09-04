/**
 * Service Worker - Web Push 与通知点击
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let data = { title: '新消息', body: '您有一条新消息', url: '/chat.php' };

    try {
        if (event.data) {
            data = { ...data, ...event.data.json() };
        }
    } catch (e) { /* ignore */ }

    event.waitUntil(
        self.registration.showNotification(data.title || '新消息', {
            body: data.body || '',
            icon: '/assets/icons/icon-192.svg',
            badge: '/assets/icons/icon-192.svg',
            tag: data.tag || 'pcr-push',
            renotify: true,
            data: { url: data.url || '/chat.php' },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/chat.php';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
