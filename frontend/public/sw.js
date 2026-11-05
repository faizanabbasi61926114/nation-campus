/* Nation Campus offline shell. No build tooling, no dependencies.
 *
 * Caching rules, in order of importance:
 *  1. /api/* is never cached. Every response is scoped to one signed-in user and
 *     one campus, so a stale copy would show one family another family's data.
 *  2. Navigations go to the network and fall back to the last saved index.html,
 *     so an app opened on a plane or in a poor-signal corridor still renders.
 *  3. Vite's hashed asset filenames are immutable, so they are served cache-first.
 */
const SHELL = 'nation-campus-shell-v1'
const ASSETS = 'nation-campus-assets-v1'

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL).then((cache) => cache.addAll(['/', '/icons/icon.svg'])).catch(() => undefined),
  )
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const key of await caches.keys()) {
      if (key !== SHELL && key !== ASSETS) await caches.delete(key)
    }
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)

  if (request.method !== 'GET') return
  if (url.pathname.startsWith('/api/')) return

  // Same-origin navigations: network first, shell from cache when offline.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone()
          void caches.open(SHELL).then((cache) => cache.put('/', copy))
          return response
        })
        .catch(() => caches.match('/').then((cached) => cached ?? Response.error())),
    )
    return
  }

  // Hashed bundles, icons and styles never change once published, so a hit is trusted.
  const immutable = url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/')
  if (immutable) {
    event.respondWith(
      caches.match(request).then(
        (cached) =>
          cached ??
          fetch(request).then((response) => {
            if (response.ok) {
              const copy = response.clone()
              void caches.open(ASSETS).then((cache) => cache.put(request, copy))
            }
            return response
          }),
      ),
    )
  }
})
