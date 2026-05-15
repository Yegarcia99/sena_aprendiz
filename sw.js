// sw.js — Service Worker SENA App
const CACHE = 'sena-v1';
const OFFLINE_URL = '/sena_aprendices/index.php';

// Recursos a cachear para uso offline
const ASSETS = [
    '/sena_aprendices/assets/css/main.css',
    '/sena_aprendices/assets/js/main.js',
    '/sena_aprendices/image/logoSena.png',
    'https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800&display=swap'
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', e => {
    // Solo interceptar GET
    if (e.request.method !== 'GET') return;

    // Para páginas PHP: network first (siempre datos frescos), fallback a cache
    if (e.request.url.includes('.php')) {
        e.respondWith(
            fetch(e.request)
                .then(r => {
                    const clone = r.clone();
                    caches.open(CACHE).then(c => c.put(e.request, clone));
                    return r;
                })
                .catch(() => caches.match(e.request))
        );
        return;
    }

    // Para assets estáticos: cache first
    e.respondWith(
        caches.match(e.request).then(cached => cached || fetch(e.request))
    );
});
