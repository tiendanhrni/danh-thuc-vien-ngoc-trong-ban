// Service Worker — RNI Push Notifications
self.addEventListener('push', (event) => {
  const data = event.data ? event.data.json() : {};
  const title = data.title || 'Học viện RNI';
  const options = {
    body: data.body || 'Bạn có bài học mới đang chờ!',
    icon: data.icon || 'https://thuvien.rni.vn/icon-192.png',
    badge: data.badge || 'https://thuvien.rni.vn/icon-72.png',
    data: { url: data.url || 'https://danh-thuc-vien-ngoc-trong-ban.vercel.app' },
    requireInteraction: false,
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if (client.url === url && 'focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(clients.claim()));
