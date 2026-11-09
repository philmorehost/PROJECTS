const CACHE_NAME = 'eflex-cache-v1';
const urlsToCache = [
  '/',
  '/index.php',
  '/css/bootstrap.min.css',
  '/css/custom_style.css',
  '/js/bootstrap.bundle.min.js',
  '/js/main.js'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Opened cache');
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        if (response) {
          return response; // Serve from cache
        }
        return fetch(event.request); // Fetch from network
      }
    )
  );
});
