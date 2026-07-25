/**
 * VolunteerOps - Service Worker
 * Handles caching, offline fallback, and push notifications
 */

// Bump this whenever a precached file changes — offline.html is served from
// the cache, so without a version change an installed client keeps the old
// copy forever. (It had drifted to v3.71.3 while the app was at v3.124.)
const CACHE_VERSION = 'vo-v3.125.0';
const STATIC_CACHE = CACHE_VERSION + '-static';
const RUNTIME_CACHE = CACHE_VERSION + '-runtime';

// Map tiles get their own cache, deliberately NOT keyed by CACHE_VERSION:
// a tile for a given z/x/y never changes, and a volunteer who panned their
// mission area into cache should not lose it to an unrelated app release —
// that cache is the difference between a usable map and a grey grid when the
// signal drops. It is capped instead, because a long shift spent panning
// could otherwise grow it without limit on a phone.
const TILE_CACHE = 'vo-tiles-v1';
const TILE_CACHE_MAX = 800;
const TILE_HOST_RE = /(^|\.)tile\.openstreetmap\.org$/;

// Keep install resilient: only precache local assets that are required offline.
const PRECACHE_URLS = [
    './offline.html'
];

// ── INSTALL ─────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => Promise.all(
                // {cache: 'reload'} is load-bearing, not belt-and-braces: a
                // plain cache.add() is served by the HTTP cache, so bumping
                // CACHE_VERSION alone can populate a brand-new cache with the
                // OLD bytes and every client keeps the stale offline page
                // forever. Observed exactly that while testing this release —
                // new cache key, old offline.html inside it.
                PRECACHE_URLS.map(url =>
                    cache.add(new Request(url, {cache: 'reload'})).catch(() => null)
                )
            ))
            .then(() => self.skipWaiting())
    );
});

// ── ACTIVATE ────────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(key => key.startsWith('vo-')
                        && key !== STATIC_CACHE && key !== RUNTIME_CACHE && key !== TILE_CACHE)
                    .map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

// Cache.keys() resolves in insertion order, so dropping from the front is a
// plain FIFO eviction. Not a true LRU — a re-viewed tile doesn't get promoted
// — but it costs one cheap pass and the failure mode is only "re-fetch a tile
// you'd already seen", which is exactly what happens today anyway.
let tileTrimInFlight = false;
function trimTileCache(cache) {
    if (tileTrimInFlight) return Promise.resolve();
    tileTrimInFlight = true;
    return cache.keys().then(keys => {
        if (keys.length <= TILE_CACHE_MAX) return;
        // Trim well past the limit so this runs rarely, not on every tile
        // once the cache is full.
        return Promise.all(keys.slice(0, keys.length - TILE_CACHE_MAX + 100).map(k => cache.delete(k)));
    }).catch(() => {}).then(() => { tileTrimInFlight = false; });
}

// ── FETCH ───────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') return;

    // Skip API/subscribe endpoints
    if (url.pathname.includes('api-push-subscribe') || url.pathname.includes('api-')) return;

    // Map tiles: cache-first, no revalidation (a tile never changes), capped.
    // Whatever the volunteer has already looked at stays on the map when the
    // connection goes — which is most of what an offline map needs to be.
    if (TILE_HOST_RE.test(url.hostname)) {
        event.respondWith(
            caches.open(TILE_CACHE).then(cache =>
                cache.match(request).then(cached => cached || fetch(request).then(response => {
                    if (response.ok || response.type === 'opaque') {
                        cache.put(request, response.clone()).then(() => trimTileCache(cache)).catch(() => {});
                    }
                    return response;
                }))
            )
        );
        return;
    }

    // CDN assets: cache-first (stale-while-revalidate).
    //
    // This branch used to store nothing at all. A classic <script src> or
    // <link rel=stylesheet> to another origin is fetched in no-cors mode, so
    // its response is always `type: 'opaque'` — and the old condition
    // explicitly skipped exactly those, meaning Bootstrap, Leaflet, jQuery et
    // al were re-downloaded on every single page load and were never
    // available offline. Opaque responses cannot be inspected (status is
    // always 0), which is why they were skipped, but they replay perfectly
    // well for scripts and styles, which is all this cache is for.
    //
    // The stale-entry risk that opacity brings — a one-off CDN error page
    // getting cached and served forever — is bounded two ways: the background
    // revalidation below overwrites the entry on the very next load, and the
    // whole runtime cache is keyed by CACHE_VERSION, so a release drops it.
    if (url.hostname !== self.location.hostname) {
        event.respondWith(
            caches.match(request).then(cached => {
                const fetchPromise = fetch(request).then(response => {
                    if (response.ok || response.type === 'opaque') {
                        const clone = response.clone();
                        caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone)).catch(() => {});
                    }
                    return response;
                }).catch(() => cached);
                return cached || fetchPromise;
            })
        );
        return;
    }

    // PHP pages contain authenticated/private content. Never cache them.
    if (url.pathname.endsWith('.php') || url.pathname.endsWith('/')) {
        event.respondWith(
            fetch(request)
                .catch(() => caches.match('./offline.html'))
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
        vibrate: data.vibrate || [100, 50, 100],
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
