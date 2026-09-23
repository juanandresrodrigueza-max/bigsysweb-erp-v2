// Service worker de BigSysWeb: la app se instala como PWA y el punto de venta sigue andando sin internet.
// - Assets compilados (/build): cache primero.
// - Páginas del POS (/retail, /minimarket): red primero; si no hay red, la última copia guardada.
// - Todo lo demás: red; si falla, página "sin conexión".
const VERSION = 'bigsys-v1'
const OFFLINE_PAGES = ['/retail', '/minimarket']

self.addEventListener('install', e => { self.skipWaiting() })
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim())) })

self.addEventListener('fetch', e => {
  const req = e.request
  if (req.method !== 'GET') return
  const url = new URL(req.url)
  if (url.origin !== location.origin) return
  if (url.pathname.startsWith('/build/') || url.pathname === '/favicon.svg' || url.pathname === '/manifest.webmanifest') {
    e.respondWith(caches.open(VERSION).then(async c => (await c.match(req)) || fetch(req).then(r => { if (r.ok) c.put(req, r.clone()); return r })))
    return
  }
  const esPos = OFFLINE_PAGES.some(p => url.pathname === p || url.pathname.startsWith(p + '?'))
  if (esPos || req.headers.get('X-Inertia')) {
    e.respondWith(fetch(req).then(r => { if (r.ok && esPos && !req.headers.get('X-Inertia')) caches.open(VERSION).then(c => c.put(url.pathname, r.clone())); return r }).catch(async () => {
      const c = await caches.open(VERSION)
      return (await c.match(url.pathname)) || new Response('<!doctype html><meta charset="utf-8"><title>Sin conexión</title><body style="font-family:sans-serif;padding:40px;text-align:center"><h1>Sin conexión</h1><p>El punto de venta funciona sin internet si lo abriste al menos una vez con conexión. Volvé a intentar.</p><p><a href="/retail">Ir al punto de venta</a></p></body>', { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 200 })
    }))
  }
})
