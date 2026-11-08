/* RISE 63 service worker.
 *
 * Caching policy, deliberately conservative:
 *   • the app shell (offline page, CSS, JS, icons, manifest) is pre-cached on install,
 *   • navigations are network-first so a signed-in page is never served stale,
 *   • authenticated responses and anything under /api/ are never cached,
 *   • a version bump deletes every older cache.
 * Bump SHELL_VERSION whenever the shell assets change; the client shows an update toast.
 */
'use strict';

var SHELL_VERSION = 'rise63-v1';
var SHELL = [
  './',
  './index.php',
  './manifest.webmanifest',
  './favicon.svg',
  './public/assets/app.css',
  './public/assets/app.js',
  './public/assets/security.js',
  './public/icons/icon-192.png',
  './public/icons/icon-512.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_VERSION).then(function (cache) {
      return cache.addAll(SHELL.map(function (url) {
        return new Request(url, { cache: 'reload' });
      })).catch(function () {
        /* A single missing asset must not block installation. */
        return Promise.all(SHELL.map(function (url) {
          return cache.add(url).catch(function () { return null; });
        }));
      });
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (key) { return key !== SHELL_VERSION; })
        .map(function (key) { return caches.delete(key); }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isPrivate(request, url) {
  if (url.pathname.indexOf('/api/') !== -1) { return true; }
  if (request.method !== 'GET') { return true; }
  if (request.headers.get('X-Requested-With') === 'XMLHttpRequest') { return true; }
  return false;
}

self.addEventListener('fetch', function (event) {
  var request = event.request;
  var url = new URL(request.url);

  if (url.origin !== self.location.origin || isPrivate(request, url)) {
    return; // Let the network handle it; never cached.
  }

  var isDocument = request.mode === 'navigate' ||
    (request.headers.get('Accept') || '').indexOf('text/html') !== -1;

  if (isDocument) {
    event.respondWith(
      fetch(request).then(function (response) {
        var copy = response.clone();
        caches.open(SHELL_VERSION).then(function (cache) {
          if (response.ok && !response.redirected) { cache.put(request, copy); }
        });
        return response;
      }).catch(function () {
        return caches.match(request).then(function (cached) {
          return cached || caches.match('./index.php') || caches.match('./');
        });
      })
    );
    return;
  }

  // Static assets: stale-while-revalidate keeps the shell instant and self-updating.
  event.respondWith(
    caches.match(request).then(function (cached) {
      var network = fetch(request).then(function (response) {
        if (response.ok) {
          var copy = response.clone();
          caches.open(SHELL_VERSION).then(function (cache) { cache.put(request, copy); });
        }
        return response;
      }).catch(function () { return cached; });
      return cached || network;
    })
  );
});
