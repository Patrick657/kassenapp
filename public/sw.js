// Minimal app-shell cache so the PWA opens instantly and survives a flaky festival wifi.
// Network-first for everything cached here — this app ships updates often via CI, and a
// cache-first strategy would risk a register getting stuck on stale JS with no obvious way
// to notice. /api/* is never intercepted: sales, stock, reports must always hit the network.
// app.js/styles.css are loaded with ?v=<content hash> (see index.php), so a deploy changes their
// URLs and no stale copy is ever served. Bumping CACHE_NAME purges entries from older versions.
const CACHE_NAME = 'festkasse-shell-v2';
const SHELL_ASSETS = ['/', '/app.js', '/styles.css', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.pathname.startsWith('/api/')) {
    return;
  }
  event.respondWith(
    fetch(event.request, { cache: 'no-cache' })
      .then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
        return res;
      })
      // Offline: fall back to any cached version of the file, whatever its ?v= hash.
      .catch(() => caches.match(event.request, { ignoreSearch: true }))
  );
});
