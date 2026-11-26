// Cloudflare Worker: zet de voorraadapp met HTTPS online op workers.dev
// en stuurt alles door naar de droplet. De droplet accepteert alleen
// verzoeken van Cloudflare-IP's met de header CF-Worker van dit account
// (die zet Cloudflare zelf en kan niet vervalst worden), dus niemand kan de Worker omzeilen.
const ORIGIN = 'http://voorraad.188-166-16-61.sslip.io';

export default {
  async fetch(request) {
    const url = new URL(request.url);
    const headers = new Headers(request.headers);
    headers.delete('host');
    headers.set('X-WW-Proto', 'https'); // X-Forwarded-Proto wordt door Cloudflare overschreven
    headers.set('X-Forwarded-Host', url.host);
    headers.set('X-Client-IP', request.headers.get('CF-Connecting-IP') || '');

    const init = { method: request.method, headers, redirect: 'manual' };
    if (request.method !== 'GET' && request.method !== 'HEAD') init.body = request.body;

    const resp = await fetch(ORIGIN + url.pathname + url.search, init);
    const out = new Response(resp.body, resp);
    const loc = out.headers.get('Location');
    if (loc && loc.startsWith(ORIGIN)) out.headers.set('Location', loc.slice(ORIGIN.length) || '/');
    out.headers.set('Strict-Transport-Security', 'max-age=31536000');
    return out;
  },
};
