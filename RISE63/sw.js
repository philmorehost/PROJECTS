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

/* The release id written by bin/build-assets.php. Absent before a build has been run, which is
   fine — the cache name then differs only by the shell list, and the list changes on any release. */
try { importScripts('./public/assets/sw-assets.js'); } catch (err) { /* not built yet */ }

/* The cache name is derived from the shell list itself, never written by hand.
 *
 * It used to be a literal ('rise63-v2') that had to be bumped manually, and it was not bumped when
 * the assets changed. The consequence was the worst kind: the browser kept serving the *previous*
 * app.js beside the *new* HTML. The new markup rendered, and no new javascript ever ran — a close
 * button drew itself and did nothing, and there was no error to point at.
 *
 * A name computed from the bytes of everything cached cannot be forgotten. Change any shell asset
 * and this worker is byte-different, so the browser installs it, the new cache name wins, and the
 * activate handler deletes the old one. The version is also hashed into the asset URLs by
 * bin/build-assets.php, which is the second line of defence.
 */
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

var SHELL_VERSION = 'rise63-' + SHELL.reduce(function (hash, url) {
  // A small rolling hash over the list. Not cryptographic: it only has to change when the list or
  // the release does, and it must be computable synchronously at worker start.
  for (var i = 0, h = 0; i < url.length; i++) { h = (h * 31 + url.charCodeAt(i)) | 0; }
  return (hash * 31 + h) | 0;
}, 7).toString(36) + '-v' + (self.RISE63_RELEASE || '0');

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
