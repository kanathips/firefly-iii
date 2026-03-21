/*
 * vite.pwa.config.js
 * Copyright (c) 2026 james@firefly-iii.org.
 *
 * PWA (Progressive Web App) configuration for the Firefly III v2 frontend.
 * Import this into vite.config.js and add it to the plugins array.
 *
 * Requires: vite-plugin-pwa (add to devDependencies in package.json)
 *   "vite-plugin-pwa": "^0.21.0"
 */

import {VitePWA} from 'vite-plugin-pwa';

/**
 * Returns a VitePWA plugin instance configured for Firefly III:
 *  - Offline-first via workbox NetworkFirst strategy for API calls
 *  - CacheFirst for static assets
 *  - Web App Manifest for installability
 */
export function fireflyPWA() {
    return VitePWA({
        registerType: 'autoUpdate',
        injectRegister: 'script',

        // Write sw.js next to the other built assets inside public/
        outDir: '../../../public',
        filename: 'sw.js',

        manifest: {
            name: 'Firefly III',
            short_name: 'Firefly III',
            description: 'A free and open source personal finance manager',
            theme_color: '#C0392B',
            background_color: '#ffffff',
            display: 'standalone',
            start_url: '/',
            scope: '/',
            icons: [
                {
                    src: '/images/logo/logo-192.png',
                    sizes: '192x192',
                    type: 'image/png',
                },
                {
                    src: '/images/logo/logo-512.png',
                    sizes: '512x512',
                    type: 'image/png',
                    purpose: 'any maskable',
                },
            ],
        },

        workbox: {
            // Cache static assets aggressively
            globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],

            runtimeCaching: [
                // API calls: NetworkFirst – serve fresh data when online,
                // fall back to cache when offline.
                {
                    urlPattern: /\/api\/v1\/.*/i,
                    handler: 'NetworkFirst',
                    options: {
                        cacheName: 'firefly-api-cache',
                        expiration: {
                            maxEntries: 200,
                            maxAgeSeconds: 60 * 60 * 24, // 24 hours
                        },
                        networkTimeoutSeconds: 10,
                    },
                },

                // Navigation requests: NetworkFirst so pages stay fresh.
                {
                    urlPattern: ({request}) => request.mode === 'navigate',
                    handler: 'NetworkFirst',
                    options: {
                        cacheName: 'firefly-pages-cache',
                        expiration: {
                            maxEntries: 50,
                            maxAgeSeconds: 60 * 60 * 24,
                        },
                        networkTimeoutSeconds: 5,
                    },
                },

                // i18n translation JSON files: StaleWhileRevalidate
                {
                    urlPattern: /\/locales\/.+\.json$/i,
                    handler: 'StaleWhileRevalidate',
                    options: {
                        cacheName: 'firefly-i18n-cache',
                    },
                },
            ],
        },

        // Use a custom service worker that extends the generated one
        strategies: 'injectManifest',
        srcDir: 'src',
        filename: 'service-worker.js',
    });
}
