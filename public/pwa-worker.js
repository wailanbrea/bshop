/* Network only: never cache sessions, business data, or mutation requests. */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || request.mode !== 'navigate' || url.origin !== self.location.origin || (url.pathname !== '/' && url.pathname !== '/app' && !url.pathname.startsWith('/app/'))) return;

    event.respondWith(fetch(request).catch(() => new Response(`<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sin conexión · KeylaShop</title>
<style>body{font:18px system-ui,sans-serif;background:#f7f5fc;color:#17152c;margin:0;padding:24px}main{max-width:36rem;margin:10vh auto;background:white;padding:24px;border-radius:16px}a{display:inline-flex;align-items:center;min-height:48px;padding:0 20px;background:#7044dc;color:white;border-radius:10px;text-decoration:none}</style></head>
<body><main><h1>Sin conexión</h1><p>Conéctate a internet para abrir KeylaShop. No se guardan ni envían operaciones sin conexión.</p><a href="/">Reintentar</a></main></body></html>`, {
        status: 503,
        headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
    })));
});
