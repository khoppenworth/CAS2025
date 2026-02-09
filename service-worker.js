const CACHE_NAME = 'my-performance-cache-v4';
const BASE_SCOPE = (self.registration && self.registration.scope) ? self.registration.scope.replace(/\/+$/, '') : '';
const OFFLINE_URL = withBase('offline.html');

function withBase(path) {
  if (!path.startsWith('/')) {
    path = '/' + path;
  }
  return `${BASE_SCOPE}${path}`;
}

const APP_SHELL_URLS = [
  withBase('my_performance.php'),
  withBase('submit_assessment.php'),
  withBase('profile.php'),
  withBase('dashboard.php'),
  withBase('offline.html'),
];

const CORE_ASSETS = [
  withBase(''),
  withBase('index.php'),
  withBase('login.php'),
  OFFLINE_URL,
  withBase('assets/css/material.css'),
  withBase('assets/css/styles.css'),
  withBase('assets/css/questionnaire-builder.css'),
  withBase('assets/js/app.js'),
  withBase('assets/js/phone-input.js'),
  withBase('assets/js/questionnaire-builder.js'),
  withBase('logo.php')
];

const PRECACHE_URLS = Array.from(new Set([...CORE_ASSETS, ...APP_SHELL_URLS]));

function isSameOrigin(url) {
  try {
    const parsed = typeof url === 'string' ? new URL(url, self.location.href) : url;
    return parsed.origin === self.location.origin;
  } catch (err) {
    return false;
  }
}

function shouldCacheResponse(request, response) {
  if (!response || request.method !== 'GET') {
    return false;
  }
  if (!isSameOrigin(request.url)) {
    return false;
  }
  if (!response.ok || response.status >= 400) {
    return false;
  }
  if (response.type !== 'basic' && response.type !== 'cors') {
    return false;
  }
  return true;
}

async function putInCache(request, response) {
  if (!shouldCacheResponse(request, response)) {
    return;
  }
  const cache = await caches.open(CACHE_NAME);
  try {
    await cache.put(request, response);
  } catch (err) {
    // Ignore storage errors (quota exceeded, opaque responses, etc.)
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  if (cached) {
    return cached;
  }
  const response = await fetch(request);
  await putInCache(request, response.clone());
  return response;
}

async function staleWhileRevalidate(event) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(event.request);
  const fetchPromise = fetch(event.request)
    .then(async (response) => {
      await putInCache(event.request, response.clone());
      return response;
    })
    .catch(() => null);

  if (cached) {
    event.waitUntil(fetchPromise.then(() => undefined));
    return cached;
  }

  const networkResponse = await fetchPromise;
  if (networkResponse) {
    return networkResponse;
  }
  if (event.request.mode === 'navigate') {
    const fallback = await cache.match(OFFLINE_URL);
    if (fallback) {
      return fallback;
    }
  }
  throw new Error('Network request failed and no cache entry available.');
}

async function networkFirst(event) {
  try {
    const response = await fetch(event.request);
    await putInCache(event.request, response.clone());
    return response;
  } catch (err) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(event.request);
    if (cached) {
      return cached;
    }
    throw err;
  }
}

async function getOfflineResponse(cache) {
  const offline = await cache.match(OFFLINE_URL);
  if (offline) {
    return offline;
  }
  return new Response('<h1>Offline</h1><p>The application is unavailable while offline.</p>', {
    status: 503,
    headers: { 'Content-Type': 'text/html; charset=utf-8' }
  });
}

async function handleNavigationRequest(event) {
  const cache = await caches.open(CACHE_NAME);
  try {
    if (event.preloadResponse) {
      const preload = await event.preloadResponse;
      if (preload) {
        event.waitUntil(putInCache(event.request, preload.clone()));
        return preload;
      }
    }
    const response = await fetch(event.request);
    await putInCache(event.request, response.clone());
    return response;
  } catch (err) {
    const cached = await cache.match(event.request);
    if (cached) {
      return cached;
    }
    const rootShell = await cache.match(withBase(''));
    if (rootShell) {
      return rootShell;
    }
    const fallback = await cache.match(withBase('index.php'));
    if (fallback) {
      return fallback;
    }
    return getOfflineResponse(cache);
  }
}

async function precacheStaticAssets() {
  const cache = await caches.open(CACHE_NAME);
  await Promise.all(
    PRECACHE_URLS.map(async (url) => {
      try {
        const response = await fetch(url, { cache: 'no-store' });
        if (response && response.ok) {
          await cache.put(url, response.clone());
        }
      } catch (err) {
        // Ignore failures so one protected resource does not break install
      }
    })
  );
}

async function warmDynamicContent(urls) {
  if (!Array.isArray(urls) || urls.length === 0) {
    return;
  }
  const cache = await caches.open(CACHE_NAME);
  await Promise.all(
    urls.map(async (url) => {
      if (typeof url !== 'string' || url.trim() === '') {
        return;
      }
      let absoluteURL;
      try {
        absoluteURL = new URL(url, self.location.origin);
      } catch (err) {
        return;
      }
      if (!isSameOrigin(absoluteURL)) {
        return;
      }
      const request = new Request(absoluteURL.toString(), { credentials: 'include' });
      try {
        const response = await fetch(request, { cache: 'no-store', credentials: 'include' });
        if (shouldCacheResponse(request, response)) {
          await cache.put(request, response.clone());
        }
      } catch (err) {
        // Ignore network failures during warmup
      }
    })
  );
}

self.addEventListener('install', (event) => {
  event.waitUntil(precacheStaticAssets());
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)));
    if (self.registration.navigationPreload) {
      try {
        await self.registration.navigationPreload.enable();
      } catch (err) {
        // Ignore navigation preload failures.
      }
    }
  })());
  self.clients.claim();
});

self.addEventListener('message', (event) => {
  const data = event.data;
  if (!data || typeof data !== 'object') {
    return;
  }
  if (data.type === 'WARM_ROUTE_CACHE' && Array.isArray(data.urls) && data.urls.length > 0) {
    event.waitUntil(warmDynamicContent(data.urls));
  }
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') {
    return;
  }

  const requestURL = new URL(request.url);
  if (requestURL.origin !== self.location.origin) {
    return;
  }

  if (!requestURL.href.startsWith(BASE_SCOPE)) {
    return;
  }

  if (requestURL.pathname.endsWith('/admin/questionnaire_manage.php')) {
    const action = requestURL.searchParams.get('action');
    if (action === 'fetch' || action === 'export') {
      event.respondWith(fetch(request, { cache: 'no-store' }));
      return;
    }
  }

  const acceptHeader = request.headers.get('accept') || '';
  if (
    request.mode === 'navigate'
    || ((request.destination === '' || request.destination === 'document') && acceptHeader.includes('text/html'))
  ) {
    event.respondWith(handleNavigationRequest(event));
    return;
  }

  const destination = request.destination;
  if (['style', 'script', 'font'].includes(destination) || /\.(?:css|js|woff2?|ttf|eot)$/i.test(requestURL.pathname)) {
    event.respondWith(staleWhileRevalidate(event));
    return;
  }

  if (destination === 'image' || /\.(?:png|jpe?g|gif|svg|webp|ico)$/i.test(requestURL.pathname)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  if (acceptHeader.includes('application/json')) {
    event.respondWith(networkFirst(event));
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(request).then(async (response) => {
        await putInCache(request, response.clone());
        return response;
      });
    })
  );
});
