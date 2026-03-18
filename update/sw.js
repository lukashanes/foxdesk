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

// ── Push Notification Handling ──────────────────────────────────────────────

self.addEventListener('push', function(event) {
    // No-payload push: fetch latest notifications from server
    event.waitUntil(
        fetch('index.php?page=api&action=push-notifications', {
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            var notifs = data.notifications || [];
            if (notifs.length === 0) {
                return self.registration.showNotification('FoxDesk', {
                    body: 'You have new notifications',
                    icon: 'pwa-icon.php?s=192',
                    badge: 'pwa-icon.php?s=72',
                    tag: 'foxdesk-generic'
                });
            }
            // Show up to 3 notifications
            return Promise.all(notifs.map(function(n) {
                return self.registration.showNotification(n.title, {
                    body: n.body,
                    icon: 'pwa-icon.php?s=192',
                    badge: 'pwa-icon.php?s=72',
                    tag: n.tag || 'foxdesk-notif',
                    data: { url: n.url },
                    requireInteraction: false
                });
            }));
        })
        .catch(function() {
            return self.registration.showNotification('FoxDesk', {
                body: 'You have new notifications',
                icon: 'pwa-icon.php?s=192',
                tag: 'foxdesk-fallback'
            });
        })
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || 'index.php?page=dashboard';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(function(clients) {
            // Focus existing tab if found
            for (var i = 0; i < clients.length; i++) {
                if (clients[i].url.indexOf(self.location.origin) !== -1) {
                    clients[i].navigate(url);
                    return clients[i].focus();
                }
            }
            // Otherwise open new tab
            return self.clients.openWindow(url);
        })
    );
});
