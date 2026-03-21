/**
 * service-worker.js
 *
 * Firefly III PWA service worker. This file is processed by vite-plugin-pwa
 * (injectManifest strategy) which injects the precache manifest at build time.
 *
 * It extends the generated Workbox service worker with push notification
 * support so that budget alerts and other server-sent notifications are
 * received and displayed even when the app is in the background.
 */

import {clientsClaim} from 'workbox-core';
import {cleanupOutdatedCaches, precacheAndRoute} from 'workbox-precaching';
import {registerRoute, NavigationRoute} from 'workbox-routing';
import {NetworkFirst, CacheFirst, StaleWhileRevalidate} from 'workbox-strategies';
import {ExpirationPlugin} from 'workbox-expiration';

// ── Service Worker lifecycle ──────────────────────────────────────────────────

self.skipWaiting();
clientsClaim();

// ── Precaching ────────────────────────────────────────────────────────────────

cleanupOutdatedCaches();

// self.__WB_MANIFEST is replaced at build time by vite-plugin-pwa with the
// list of assets to precache.
precacheAndRoute(self.__WB_MANIFEST || []);

// ── Runtime caching ───────────────────────────────────────────────────────────

// API calls – NetworkFirst with 24-hour cache fallback
registerRoute(
    ({url}) => url.pathname.startsWith('/api/v1/'),
    new NetworkFirst({
        cacheName: 'firefly-api-cache',
        plugins: [
            new ExpirationPlugin({maxEntries: 200, maxAgeSeconds: 86400}),
        ],
        networkTimeoutSeconds: 10,
    })
);

// Navigation requests – NetworkFirst so pages refresh when online
registerRoute(
    new NavigationRoute(
        new NetworkFirst({
            cacheName: 'firefly-pages-cache',
            plugins: [
                new ExpirationPlugin({maxEntries: 50, maxAgeSeconds: 86400}),
            ],
            networkTimeoutSeconds: 5,
        })
    )
);

// i18n JSON – StaleWhileRevalidate (show cached, update in background)
registerRoute(
    ({url}) => url.pathname.match(/^\/locales\/.+\.json$/),
    new StaleWhileRevalidate({cacheName: 'firefly-i18n-cache'})
);

// Static fonts / images – CacheFirst, long TTL
registerRoute(
    ({request}) => request.destination === 'font' || request.destination === 'image',
    new CacheFirst({
        cacheName: 'firefly-assets-cache',
        plugins: [
            new ExpirationPlugin({maxEntries: 100, maxAgeSeconds: 60 * 60 * 24 * 30}),
        ],
    })
);

// ── Push notification handler ─────────────────────────────────────────────────

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch {
        payload = {title: 'Firefly III', body: event.data.text()};
    }

    const title   = payload.title || 'Firefly III';
    const options = {
        body:    payload.body || '',
        icon:    '/images/logo/logo-192.png',
        badge:   '/images/logo/logo-192.png',
        data:    payload.data || {},
        actions: [
            {action: 'open', title: 'Open'},
            {action: 'dismiss', title: 'Dismiss'},
        ],
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// ── Notification click handler ────────────────────────────────────────────────

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if (event.action === 'dismiss') {
        return;
    }

    const url = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({type: 'window', includeUncontrolled: true}).then((windowClients) => {
            for (const client of windowClients) {
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
