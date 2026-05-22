// sw.js - Service Worker SENA App
const CACHE_NAME = 'sena-cache-v3';

const ASSETS = [
    '/sena_aprendices/assets/css/main.css',
    '/sena_aprendices/assets/js/main.js',
    '/sena_aprendices/image/logoSena.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    // Las paginas PHP son dinamicas y dependen de sesion; siempre van a red.
    if (url.pathname.endsWith('.php') || url.pathname.endsWith('/sena_aprendices/')) {
        event.respondWith(fetch(event.request));
        return;
    }

    event.respondWith(
        caches.match(event.request).then(cached => cached || fetch(event.request))
    );
});
