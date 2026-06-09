/* ============================================================
   Furan — service worker
   App-shell offline + runtime caching, sans build step.

   Stratégies :
     - Navigations (HTML)        → network-first, fallback shell en cache
                                    (hors-ligne, le board se charge quand même)
     - API GET (/api/*)          → network-first, fallback dernière réponse
                                    cachée (derniers départs connus hors-ligne)
     - Assets (css/js/img, fonts → stale-while-revalidate (rapide + MAJ en fond)
       et Leaflet via CDN)

   ⚠️ /api/areas/batch-departures est en POST : la Cache API ne gère pas le POST,
      ces requêtes bypassent donc le SW (les noms d'arrêts proches restent
      disponibles via le GET /api/areas/nearby caché).

   Bumper CACHE_VERSION pour purger les anciens caches (sortie d'une MAJ).
   ============================================================ */

const CACHE_VERSION = 'furan-v2';
const SHELL_CACHE   = `${CACHE_VERSION}-shell`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;
const API_CACHE     = `${CACHE_VERSION}-api`;

// URLs à l'URL stable (sans hash de version d'asset) : précachables à l'install.
const SHELL_URLS = [
    '/',
    '/manifest.webmanifest',
    '/img/board-icon-192.png',
    '/img/board-icon-512.png',
    '/img/board-icon-maskable.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            // addAll est atomique : si une URL 404, tout échoue. On reste tolérant.
            .then((cache) => Promise.allSettled(SHELL_URLS.map((u) => cache.add(u))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => !k.startsWith(CACHE_VERSION)).map((k) => caches.delete(k)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Cache API ne sait pas cacher le POST → on laisse passer (batch-departures).
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // 1. Navigations → network-first, fallback shell.
    if (request.mode === 'navigate') {
        event.respondWith(networkFirstNavigation(request));
        return;
    }

    // 2. API GET même origine → network-first, fallback réponse cachée.
    if (url.origin === self.location.origin && url.pathname.startsWith('/api/')) {
        event.respondWith(networkFirstApi(request));
        return;
    }

    // 3. Reste (assets statiques same-origin + fonts/Leaflet CDN) → SWR.
    event.respondWith(staleWhileRevalidate(request));
});

async function networkFirstNavigation(request) {
    const cache = await caches.open(SHELL_CACHE);
    try {
        const fresh = await fetch(request);
        cache.put(request, fresh.clone());
        return fresh;
    } catch {
        return (await cache.match(request))
            ?? (await cache.match('/'))
            ?? Response.error();
    }
}

async function networkFirstApi(request) {
    const cache = await caches.open(API_CACHE);
    try {
        const fresh = await fetch(request);
        if (fresh.ok) {
            cache.put(request, fresh.clone());
        }
        return fresh;
    } catch (err) {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }
        // Pas de cache : on laisse remonter l'échec pour que le front affiche
        // son état « connexion impossible » plutôt qu'une réponse vide.
        throw err;
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(RUNTIME_CACHE);
    const cached = await cache.match(request);
    const network = fetch(request)
        .then((res) => {
            // Cache les réponses OK same-origin et les opaques (CDN cross-origin).
            if (res && (res.ok || res.type === 'opaque')) {
                cache.put(request, res.clone());
            }
            return res;
        })
        .catch(() => cached);
    return cached ?? network;
}
