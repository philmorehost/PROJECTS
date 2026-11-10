const CACHE_NAME = 'blockpayne-shell-v1';
const STATIC_ASSETS = [
  './public/assets/app.css',
  './public/assets/app.js',
  './favicon.svg'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.pathname.includes('/api/') || url.pathname.includes('/pay/') || url.pathname.includes('/install')) {
    return;
  }
  if (url.pathname.includes('/public/assets/')) {
    event.respondWith(
      caches.match(req).then((cached) => cached || fetch(req))
    );
  }
});
