/**
 * FoxDesk Service Worker
 * Cache-first for versioned static assets (?v=X.Y.Z), network-first for HTML.
 * Static assets use ?v=VERSION for cache busting, so serving from cache is safe.
 */

const CACHE_NAME = 'foxdesk-v2';

self.addEventListener('install', function(e) {
    self.skipWaiting();
});

self.addEventListener('activate', function(e) {
    e.waitUntil(
        caches.keys().then(function(names) {
            return Promise.all(
                names.filter(function(n) { return n !== CACHE_NAME; })
                     .map(function(n) { return caches.delete(n); })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function(e) {
    var url = new URL(e.request.url);

    // Skip non-GET, API calls, and external resources
    if (e.request.method !== 'GET' ||
        url.search.indexOf('page=api') !== -1 ||
        url.origin !== self.location.origin) {
        return;
    }

    var isStatic = /\.(css|js|woff2?|ttf|png|jpg|jpeg|svg|webp|ico)(\?|$)/.test(url.pathname);

    if (isStatic) {
        // Cache-first for static assets — ?v=VERSION handles cache busting
        e.respondWith(
            caches.match(e.request).then(function(cached) {
                if (cached) return cached;
                return fetch(e.request).then(function(response) {
                    var clone = response.clone();
                    caches.open(CACHE_NAME).then(function(cache) {
                        cache.put(e.request, clone);
                    });
                    return response;
                });
            })
        );
    }
    // HTML pages go straight to network (no caching)
});
