// Labels rechtstreeks printen op de Brother PT-P710BT via USB (WebUSB: Chrome of Edge).
// Elk label: QR met alleen de code (scant het best op smalle tape) + naam en "code · groep".
(function () {
  'use strict';
  // Tapebreedte (mm) => [printbare dots, eerste pin] volgens de Brother raster-referentie (128 pinnen).
  const TAPE = { 6: [32, 48], 9: [50, 39], 12: [70, 29], 18: [112, 8], 24: [128, 0] };
  const FOUTEN1 = { 0x01: 'geen tape', 0x02: 'tape op', 0x04: 'snijder vast', 0x10: 'batterij leeg', 0x40: 'verkeerde tape' };
  const FOUTEN2 = { 0x01: 'verkeerde tape', 0x04: 'verkeerde opdracht', 0x10: 'klep open', 0x20: 'te warm' };

  // ---------- QR-matrix (via qrcodejs) ----------
  function qrMatrix(tekst) {
    const div = document.createElement('div');
    const q = new QRCode(div, { text: tekst, width: 64, height: 64, correctLevel: QRCode.CorrectLevel.M });
    const o = q._oQRCode, n = o.getModuleCount(), m = [];
    for (let r = 0; r < n; r++) { m.push([]); for (let c = 0; c < n; c++) m[r].push(o.isDark(r, c)); }
    return m;
  }

  function font(px, vet) { return (vet ? 'bold ' : '') + px + 'px Arial, Helvetica, sans-serif'; }

  function afbreken(ctx, tekst, max, regels) {
    const woorden = tekst.split(/\s+/), uit = [];
    let regel = '';
    for (const w of woorden) {
      const probeer = regel ? regel + ' ' + w : w;
      if (ctx.measureText(probeer).width <= max || !regel) regel = probeer;
      else { uit.push(regel); regel = w; }
    }
    if (regel) uit.push(regel);
    if (uit.length > regels) { uit.length = regels; uit[regels - 1] += '…'; }
    return uit;
  }

  // ---------- Label tekenen: canvas van (lengte × printbare hoogte), puur zwart/wit ----------
  function teken(label, tapeMm) {
    const h = (TAPE[tapeMm] || TAPE[12])[0];
    const m = qrMatrix(label.qr), n = m.length;
    const mod = Math.max(1, Math.floor(h / n)), qpx = mod * n, top = Math.floor((h - qpx) / 2);
    const x0 = 4 * mod;                         // witrand van 4 modules
    const tx = x0 + qpx + 2 * mod + 6;
    const drie = h >= 100;                      // 18 en 24 mm: naam mag over twee regels
    const pxNaam = Math.round(h * (drie ? 0.235 : 0.31)), pxSub = Math.round(h * (drie ? 0.2 : 0.27));
    const meet = document.createElement('canvas').getContext('2d');
    meet.font = font(pxNaam, true);
    const naamRegels = drie ? afbreken(meet, label.titel, 400, 2) : [label.titel];
    const regels = naamRegels.map((t) => [t, pxNaam]).concat([[label.sub, pxSub]]);
    let tw = 0;
    for (const [t, px] of regels) { meet.font = font(px, true); tw = Math.max(tw, meet.measureText(t).width); }
    const W = Math.ceil(tx + tw + 6);
    const cv = document.createElement('canvas'); cv.width = W; cv.height = h;
    const ctx = cv.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, h);
    ctx.fillStyle = '#000';
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (m[r][c]) ctx.fillRect(x0 + c * mod, top + r * mod, mod, mod);
    const blok = regels.reduce((s, [, px]) => s + px * 1.15, 0);
    let y = Math.max(0, Math.round((h - blok) / 2));
    ctx.textBaseline = 'top';
    for (const [t, px] of regels) { ctx.font = font(px, true); ctx.fillText(t, tx, y); y += Math.round(px * 1.15); }
    // naar zwart/wit
    const d = ctx.getImageData(0, 0, W, h), p = d.data;
    for (let i = 0; i < p.length; i += 4) { const v = p[i] < 128 ? 0 : 255; p[i] = p[i + 1] = p[i + 2] = v; p[i + 3] = 255; }
    ctx.putImageData(d, 0, 0);
    return cv;
  }

  // ---------- Rasteropdracht ----------
  function opdracht(cv, tapeMm) {
    const [h, pin] = TAPE[tapeMm] || TAPE[12];
    const W = cv.width, px = cv.getContext('2d').getImageData(0, 0, W, h).data;
    const delen = [];
    const zet = (...b) => delen.push(Uint8Array.from(b));
    zet(0x1b, 0x69, 0x61, 0x01);                                     // rastermodus
    zet(0x1b, 0x69, 0x7a, 0x84, 0x00, tapeMm, 0x00, W & 255, (W >> 8) & 255, (W >> 16) & 255, 0, 0, 0);
    zet(0x1b, 0x69, 0x4d, 0x40);                                     // automatisch snijden
    zet(0x1b, 0x69, 0x4b, 0x08);
    zet(0x1b, 0x69, 0x64, 0x0e, 0x00);                               // marge 2 mm
    zet(0x4d, 0x02);                                                 // TIFF
    for (let x = 0; x < W; x++) {
      const lijn = new Uint8Array(16);
      let iets = false;
      for (let y = 0; y < h; y++) {
        if (px[(y * W + x) * 4] === 0) { const b = y + pin; lijn[b >> 3] |= 0x80 >> (b & 7); iets = true; }
      }
      if (!iets) zet(0x5a);
      else { const r = new Uint8Array(20); r.set([0x47, 0x11, 0x00, 0x0f]); r.set(lijn, 4); delen.push(r); }
    }
    zet(0x1a);                                                       // printen
    const tot = delen.reduce((s, a) => s + a.length, 0), uit = new Uint8Array(tot);
    let o = 0; for (const a of delen) { uit.set(a, o); o += a.length; }
    return uit;
  }

  // ---------- USB ----------
  let dev = null, epIn = 0, epOut = 0;

  async function verbind(kiezen) {
    if (!navigator.usb) throw new Error('Deze browser kan niet via USB printen. Gebruik Chrome of Edge.');
    if (!kiezen) dev = (await navigator.usb.getDevices()).find((d) => d.vendorId === 0x04f9) || null;
    if (!dev) dev = await navigator.usb.requestDevice({ filters: [{ vendorId: 0x04f9 }] });
    if (!dev.opened) await dev.open();
    if (!dev.configuration) await dev.selectConfiguration(1);
    const intf = dev.configuration.interfaces[0];
    try { await dev.claimInterface(intf.interfaceNumber); } catch (e) { if (!/already/i.test(e.message)) throw e; }
    for (const e of intf.alternate.endpoints) { if (e.direction === 'in') epIn = e.endpointNumber; else epOut = e.endpointNumber; }
    return status();
  }

  // Eén doorlopende lezer; statusberichten (32 bytes) gaan in een wachtrij.
  let wachtrij = [], lezer = false;
  const slaap = (ms) => new Promise((r) => setTimeout(r, ms));
  function startLezer() {
    if (lezer) return;
    lezer = true;
    (async () => {
      while (dev && dev.opened) {
        try {
          const r = await dev.transferIn(epIn, 64);
          const n = r.data ? r.data.byteLength : 0;
          if (n >= 32) {
            const b = new Uint8Array(r.data.buffer, r.data.byteOffset, n);
            for (let i = 0; i + 32 <= n; i += 32) wachtrij.push(b.slice(i, i + 32));
          } else await slaap(50);
        } catch (e) { break; }
      }
      lezer = false;
    })();
  }

  async function leesStatus(ms) {
    startLezer();
    const eind = Date.now() + ms;
    while (Date.now() < eind) {
      if (wachtrij.length) return wachtrij.shift();
      await slaap(50);
    }
    return null;
  }

  function fout(s) {
    const t = [];
    for (const [b, w] of Object.entries(FOUTEN1)) if (s[8] & b) t.push(w);
    for (const [b, w] of Object.entries(FOUTEN2)) if (s[9] & b) t.push(w);
    return t.join(', ');
  }

  async function status() {
    const init = new Uint8Array(105); init.set([0x1b, 0x40, 0x1b, 0x69, 0x53], 100);
    startLezer(); await slaap(100); wachtrij = [];
    await dev.transferOut(epOut, init);
    const s = await leesStatus(3000);
    if (!s) throw new Error('Printer geeft geen antwoord. Staat hij aan?');
    return { tape: s[10], fout: fout(s) };
  }

  async function print(cv, tapeMm) {
    wachtrij = [];
    await dev.transferOut(epOut, opdracht(cv, tapeMm));
    const eind = Date.now() + 30000;
    while (Date.now() < eind) {
      const s = await leesStatus(2000);
      if (!s) continue;
      if (s[18] === 0x02 || s[8] || s[9]) throw new Error('Printerfout: ' + (fout(s) || 'onbekend'));
      if (s[18] === 0x01) return;                                    // klaar met printen
    }
    throw new Error('Geen bevestiging van de printer.');
  }

  // ---------- Pagina ----------
  const root = document.getElementById('ptouch');
  if (!root) return;
  const labels = JSON.parse(document.getElementById('ptouchData').textContent);
  const $ = (s) => root.querySelector(s);
  const lijst = $('#ptLijst'), melding = $('#ptMelding'), tapeSel = $('#ptTape');
  let tape = 12, stoppen = false;

  function meld(t, soort) { melding.textContent = t; melding.className = 'ptmelding ' + (soort || ''); }

  function tekenAlles() {
    lijst.innerHTML = '';
    labels.forEach((l, i) => {
      const rij = document.createElement('div'); rij.className = 'ptrij';
      const cv = teken(l, tape); l.cv = cv;
      cv.className = 'ptvoorbeeld';
      const info = document.createElement('div'); info.className = 'ptinfo';
      info.innerHTML = '<span class="small muted">' + Math.round((cv.width + 28) / 180 * 25.4) + ' mm</span> <span class="ptstatus" data-i="' + i + '"></span>';
      const knop = document.createElement('button'); knop.type = 'button'; knop.className = 'btn small ghost'; knop.textContent = 'Print';
      knop.onclick = () => printReeks([i]);
      info.appendChild(knop);
      rij.append(cv, info); lijst.appendChild(rij);
    });
    const mm = labels.reduce((s, l) => s + (l.cv.width + 28) / 180 * 25.4, 0);
    $('#ptTotaal').textContent = labels.length + ' labels · ongeveer ' + (mm / 1000).toFixed(1) + ' m tape (zonder doorvoer bij het snijden)';
  }

  function zetStatus(i, t) { const el = root.querySelector('.ptstatus[data-i="' + i + '"]'); if (el) el.textContent = t; }

  async function printReeks(idx) {
    const knoppen = root.querySelectorAll('button:not(#ptStop)');
    knoppen.forEach((b) => (b.disabled = true));
    stoppen = false;
    const kopie = Math.max(1, parseInt($('#ptAantal').value, 10) || 1);
    try {
      const st = await verbind(false);
      if (st.fout) throw new Error('Printerfout: ' + st.fout);
      if (st.tape && st.tape !== tape) {
        if (!TAPE[st.tape]) throw new Error('Tape van ' + st.tape + ' mm wordt niet ondersteund.');
        tape = st.tape; tapeSel.value = String(tape); tekenAlles();
      }
      let n = 0;
      for (const i of idx) {
        for (let k = 0; k < kopie; k++) {
          if (stoppen) { meld('Gestopt na ' + n + ' labels.', 'waarsch'); return; }
          zetStatus(i, '⏳'); meld('Printen… ' + (n + 1) + ' van ' + idx.length * kopie);
          await print(labels[i].cv, tape); n++;
          zetStatus(i, '✓');
        }
      }
      meld(n + ' label' + (n === 1 ? '' : 's') + ' geprint op ' + tape + ' mm tape.', 'ok');
    } catch (e) {
      meld(e.message || String(e), 'fout');
    } finally {
      knoppen.forEach((b) => (b.disabled = false));
    }
  }

  $('#ptVerbind').onclick = async () => {
    try {
      const st = await verbind(true);
      if (st.tape && TAPE[st.tape]) { tape = st.tape; tapeSel.value = String(tape); tekenAlles(); }
      meld('Printer verbonden · ' + st.tape + ' mm tape' + (st.fout ? ' · ' + st.fout : ''), st.fout ? 'fout' : 'ok');
    } catch (e) { meld(e.message || String(e), 'fout'); }
  };
  $('#ptAlles').onclick = () => printReeks(labels.map((_, i) => i));
  $('#ptStop').onclick = () => { stoppen = true; };
  tapeSel.onchange = () => { tape = parseInt(tapeSel.value, 10); tekenAlles(); };
  if (!navigator.usb) meld('Deze browser kan niet via USB printen. Open deze pagina in Chrome of Edge op de computer waar de printer met een USB-kabel aan zit.', 'fout');
  tekenAlles();
  window.__ptouch = { teken, opdracht };                             // voor testen
})();
