/*
 * Pixelforge Studio service worker.
 * - Opening or reloading a page while offline shows /offline.html instead of the browser error.
 * - Built JS/CSS (content-hashed, never change) are kept, so the app shell loads fast and offline.
 * - Data requests (Inertia, JSON, webhooks, form posts) are never cached: balances and charges stay live.
 */
const CACHE = 'pixelforge-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL, '/favicon.svg']))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Full page loads: always the network (fresh data); the offline page only when there is none.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    // Hashed build assets: cache first, they never change under the same name.
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(request, copy));
                        }
                        return response;
                    }),
            ),
        );
    }
});
