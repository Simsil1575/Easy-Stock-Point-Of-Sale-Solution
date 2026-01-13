// Admin Service Worker for Fast Navigation and Sidebar Consistency
const CACHE_NAME = 'admin-sidebar-v1';
const SIDEBAR_CACHE = 'sidebar-cache-v1';

// Admin pages to precache for fast navigation
const adminPagesToCache = [
  '/admin/sidebar.php',
  '/admin/home',
  '/admin/sales',
  '/admin/inventory',
  '/admin/reports',
  '/admin/expenses',
  '/admin/credit-book',
  '/admin/credit-tabs',
  '/admin/cash',
  '/admin/users',
  '/admin/settings',
  '/admin/inbox',
  '/admin/logs'
];

// Static assets that rarely change
const staticAssets = [
  '/logo.png',
  '/admin/lucide.js',
  '/admin/master.js'
];

// Install event - precache critical resources
self.addEventListener('install', (event) => {
  console.log('[Admin SW] Installing...');
  event.waitUntil(
    Promise.all([
      caches.open(CACHE_NAME).then((cache) => {
        return Promise.allSettled(
          [...adminPagesToCache, ...staticAssets].map(url =>
            cache.add(url).catch(err => {
              console.warn(`[Admin SW] Failed to cache ${url}:`, err);
              return null;
            })
          )
        );
      }),
      caches.open(SIDEBAR_CACHE).then((cache) => {
        return cache.add('/admin/sidebar.php').catch(() => null);
      })
    ]).then(() => {
      console.log('[Admin SW] Installation complete');
    })
  );
  self.skipWaiting();
});

// Activate event - clean up and claim clients
self.addEventListener('activate', (event) => {
  console.log('[Admin SW] Activating...');
  event.waitUntil(
    Promise.all([
      caches.keys().then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== CACHE_NAME && cacheName !== SIDEBAR_CACHE) {
              console.log('[Admin SW] Deleting old cache:', cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      }),
      self.clients.claim()
    ]).then(() => {
      console.log('[Admin SW] Activation complete');
      return self.clients.matchAll().then(clients => {
        clients.forEach(client => {
          client.postMessage({ type: 'ADMIN_SW_READY' });
        });
      });
    })
  );
});

// Fetch event - stale-while-revalidate for admin pages
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  if (!event.request.url.startsWith('http')) return;

  const url = new URL(event.request.url);
  const isAdminPage = url.pathname.startsWith('/admin/');
  const isNavigationRequest = event.request.mode === 'navigate';
  const isSidebarRequest = url.pathname.includes('sidebar.php');

  // For sidebar requests - use cache-first for instant loading
  if (isSidebarRequest) {
    event.respondWith(
      caches.match(event.request).then((cachedResponse) => {
        const fetchPromise = fetch(event.request).then((networkResponse) => {
          if (networkResponse.ok) {
            caches.open(SIDEBAR_CACHE).then((cache) => {
              cache.put(event.request, networkResponse.clone());
            });
          }
          return networkResponse;
        }).catch(() => cachedResponse);

        return cachedResponse || fetchPromise;
      })
    );
    return;
  }

  // For admin navigation - stale-while-revalidate
  if (isAdminPage && isNavigationRequest) {
    event.respondWith(
      caches.match(event.request).then((cachedResponse) => {
        const fetchPromise = fetch(event.request).then((networkResponse) => {
          if (networkResponse.ok) {
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(event.request, networkResponse.clone());
            });
          }
          return networkResponse;
        });

        // Return cached immediately, update in background
        return cachedResponse || fetchPromise;
      })
    );
    return;
  }

  // Default: network first
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        if (response.ok && isAdminPage) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseClone);
          });
        }
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});

// Message handling for cache updates
self.addEventListener('message', (event) => {
  if (event.data) {
    switch (event.data.type) {
      case 'SKIP_WAITING':
        self.skipWaiting();
        break;
      case 'PRECACHE_PAGE':
        if (event.data.url) {
          caches.open(CACHE_NAME).then((cache) => {
            cache.add(event.data.url).catch(() => {});
          });
        }
        break;
      case 'UPDATE_SIDEBAR_CACHE':
        caches.open(SIDEBAR_CACHE).then((cache) => {
          cache.delete('/admin/sidebar.php').then(() => {
            cache.add('/admin/sidebar.php').catch(() => {});
          });
        });
        break;
    }
  }
});

