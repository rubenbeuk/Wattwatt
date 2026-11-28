// Service worker van de Watt Watt app (ronde 3, 28-11-2026).
// 1. Pushmeldingen: de server stuurt alleen een tik; wij halen het nieuwste seintje op en tonen dat.
// 2. Mijn dag offline: dag.php wordt bij elke keer openen bewaard; zonder bereik krijg je de laatst bewaarde versie.
const CACHE = 'ww-v1';
const STATISCH = ['assets/app.css', 'assets/app.js', 'assets/icon.svg', 'manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATISCH)).catch(() => {}).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

function isDag(url) { return url.origin === self.location.origin && /\/dag\.php$/.test(url.pathname); }

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (req.mode === 'navigate') {
    // Altijd eerst het netwerk; alleen Mijn dag (zonder andere datum) wordt bewaard voor offline
    e.respondWith(fetch(req).then(res => {
      if (isDag(url) && !url.search && res.ok && !res.redirected) { const kopie = res.clone(); caches.open(CACHE).then(c => c.put('dag.php', kopie)); }
      return res;
    }).catch(() => caches.match('dag.php').then(r => r ? offlineKop(r) : new Response(
      '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Offline</title><body style="font-family:system-ui;padding:2rem"><h1>Geen verbinding</h1><p>Open Mijn dag een keer met bereik, dan is die ook zonder bereik te lezen.</p>',
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } }))));
    return;
  }
  if (/\/assets\//.test(url.pathname) || url.pathname.endsWith('/manifest.json')) {
    // Opmaak en scripts: snel uit de cache, op de achtergrond vernieuwen
    e.respondWith(caches.open(CACHE).then(c => c.match(req, { ignoreSearch: true }).then(hit => {
      const net = fetch(req).then(res => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || net;
    })));
  }
});

// Bewaarde Mijn dag tonen met een balk erboven dat het een offline versie is
function offlineKop(r) {
  return r.text().then(html => new Response(html.replace(/<main>/, '<main><div class="flash warn">Geen verbinding. Dit is Mijn dag zoals hij het laatst geladen is; wijzigingen kunnen pas met bereik.</div>'),
    { headers: { 'Content-Type': 'text/html; charset=utf-8' } }));
}

self.addEventListener('push', e => {
  e.waitUntil(fetch('api_push.php?laatste', { credentials: 'include', cache: 'no-store' }).then(r => r.ok ? r.json() : null).catch(() => null).then(m => {
    m = m || { titel: 'Watt Watt', tekst: 'Er is iets nieuws in de app.', link: 'dag.php#seintjes', tag: 'seintje' };
    return self.registration.showNotification(m.titel, { body: m.tekst, tag: m.tag, icon: 'assets/icon.svg', badge: 'assets/icon.svg', data: { link: m.link } });
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const doel = new URL(e.notification.data && e.notification.data.link || 'dag.php', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(ws => {
    for (const w of ws) if ('focus' in w) { w.navigate(doel); return w.focus(); }
    return self.clients.openWindow(doel);
  }));
});

self.addEventListener('message', e => { if (e.data === 'leeg') caches.delete(CACHE); });
