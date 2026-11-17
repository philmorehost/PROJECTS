'use strict';
// Only a generic offline screen and public artwork are cached. Never cache financial HTML/API data.
const BASE=new URL('./',self.location.href);
const PREFIX='remit-public-offline:'+BASE.pathname+':';
const CACHE=PREFIX+'0.9.0';
const PUBLIC=['offline.html','public/assets/offline.css','public/assets/remit-192.png','public/assets/remit-512.png','public/assets/remit-maskable-512.png'].map(path=>new URL(path,BASE).href);
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(PUBLIC)).then(()=>self.skipWaiting()));});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith(PREFIX)&&key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim()));});
self.addEventListener('fetch',event=>{
 const request=event.request;
 if(request.method!=='GET')return; // No replay or offline submission of payments, login or forms.
 const url=new URL(request.url);
 if(url.origin!==BASE.origin || !url.pathname.startsWith(BASE.pathname))return;
 if(request.mode==='navigate'){
  event.respondWith(fetch(request).catch(()=>caches.match(new URL('offline.html',BASE).href)));
 }else if(PUBLIC.includes(url.href))event.respondWith(caches.match(request).then(cached=>cached||fetch(request)));
});
