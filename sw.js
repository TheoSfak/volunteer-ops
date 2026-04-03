/**
 * VolunteerOps - Service Worker
 * Handles caching, offline fallback, and push notifications
 */

const CACHE_VERSION = 'vo-v3.62.9';
const STATIC_CACHE = CACHE_VERSION + '-static';
const RUNTIME_CACHE = CACHE_VERSION + '-runtime';

// Static assets to pre-cache on install
const PRECACHE_URLS = [
    './offline.html',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'
];

// ── INSTALL ─────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

// ── ACTIVATE ────────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(key => key.startsWith('vo-') && key !== STATIC_CACHE && key !== RUNTIME_CACHE)
                    .map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

// ── FETCH ───────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') return;

    // Skip API/subscribe endpoints
    if (url.pathname.includes('api-push-subscribe') || url.pathname.includes('api-')) return;

    // CDN assets: cache-first (stale-while-revalidate)
    if (url.hostname !== self.location.hostname) {
        event.respondWith(
            caches.match(request).then(cached => {
                const fetchPromise = fetch(request).then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => cached);
                return cached || fetchPromise;
            })
        );
        return;
    }

    // PHP pages: network-first with offline fallback
    if (url.pathname.endsWith('.php') || url.pathname.endsWith('/')) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    // Cache successful page responses
                    if (response.ok && response.status === 200) {
                        const clone = response.clone();
                        caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() => {
                    return caches.match(request).then(cached => {
                        return cached || caches.match('./offline.html');
                    });
                })
        );
        return;
    }

    // Other static files (images, icons): cache-first
    event.respondWith(
        caches.match(request).then(cached => {
            return cached || fetch(request).then(response => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
                }
                return response;
            }).catch(() => cached);
        })
    );
});

// ── PUSH NOTIFICATION ───────────────────────────────────────────────────────
self.addEventListener('push', event => {
    let data = { title: 'VolunteerOps', body: 'Νέα ειδοποίηση', icon: './assets/icons/icon-192.png' };

    if (event.data) {
        try {
            data = Object.assign(data, event.data.json());
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon || './assets/icons/icon-192.png',
        badge: './assets/icons/icon-72.png',
        tag: data.tag || 'vo-notification',
        renotify: !!data.tag,
        data: {
            url: data.url || './dashboard.php'
        },
        vibrate: [100, 50, 100],
        actions: data.actions || []
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// ── NOTIFICATION CLICK ──────────────────────────────────────────────────────
self.addEventListener('notificationclick', event => {
    event.notification.close();

    const targetUrl = event.notification.data?.url || './dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
            // Focus existing window if open
            for (const client of windowClients) {
                if (client.url.includes(targetUrl) && 'focus' in client) {
                    return client.focus();
                }
            }
            // Otherwise open new window
            return clients.openWindow(targetUrl);
        })
    );
});
