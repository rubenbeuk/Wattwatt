(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  // ---------- Camera-scanner (QR + barcodes) ----------
  let scanner = null;
  function startScanner(onCode) {
    const box = $('#reader');
    if (!box || typeof Html5Qrcode === 'undefined') { alert('Scanner kon niet laden. Typ de code in.'); return; }
    box.hidden = false;
    const F = Html5QrcodeSupportedFormats;
    scanner = new Html5Qrcode('reader', {
      verbose: false,
      formatsToSupport: [F.QR_CODE, F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.CODE_128, F.CODE_39],
      experimentalFeatures: { useBarCodeDetectorIfSupported: true },
    });
    let klaar = false;
    scanner.start({ facingMode: 'environment' }, { fps: 12, qrbox: (w, h) => ({ width: Math.min(w, 320) * 0.9, height: Math.min(h, 320) * 0.6 }) },
      (text) => {
        if (klaar) return;
        klaar = true;
        if (navigator.vibrate) navigator.vibrate(80);
        stopScanner().then(() => onCode(text.trim()));
      }, () => {}
    ).catch((err) => {
      box.hidden = true;
      alert('Camera niet beschikbaar: ' + err + '\nGeef de browser toestemming voor de camera, of typ de code in.');
    });
  }
  function stopScanner() {
    const box = $('#reader');
    if (box) box.hidden = true;
    const s = $('#stopScan'); if (s) s.hidden = true;
    const k = $('#scanKnop'); if (k) k.hidden = false;
    if (!scanner) return Promise.resolve();
    const sc = scanner; scanner = null;
    return sc.stop().catch(() => {}).then(() => sc.clear());
  }

  const scanKnop = $('#scanKnop');
  if (scanKnop) {
    scanKnop.addEventListener('click', () => {
      scanKnop.hidden = true;
      $('#stopScan').hidden = false;
      startScanner((code) => { location.href = 'lookup.php?q=' + encodeURIComponent(code); });
    });
    $('#stopScan').addEventListener('click', stopScanner);
  }
  const scanEan = $('#scanEan');
  if (scanEan) {
    scanEan.addEventListener('click', () => startScanner((code) => { $('#eanVeld').value = code.replace(/\D/g, ''); }));
  }

  // ---------- Productpagina: tabs en aantal ----------
  const labels = { uit: 'Pakken', retour: 'Terugbrengen', ontvangst: 'Ontvangen', telling: 'Telling opslaan' };
  const aantalLabels = { uit: 'Aantal', retour: 'Aantal', ontvangst: 'Aantal ontvangen', telling: 'Geteld aantal (totaal op voorraad)' };
  $$('.tab').forEach((t) => t.addEventListener('click', () => {
    $$('.tab').forEach((x) => x.classList.toggle('on', x === t));
    const type = t.dataset.tab;
    $('#typeVeld').value = type;
    $('#boekKnop').textContent = labels[type];
    $('#aantalLabel').textContent = aantalLabels[type];
    $('#projectVeld').hidden = (type === 'ontvangst' || type === 'telling');
    $('#projectInput').required = (type === 'uit');
  }));
  if ($('#projectInput')) $('#projectInput').required = true;
  $$('.qty [data-step]').forEach((b) => b.addEventListener('click', () => {
    const inp = $('#aantal');
    const v = parseFloat((inp.value || '0').replace(',', '.')) || 0;
    inp.value = Math.max(0, v + parseInt(b.dataset.step, 10));
  }));

  // ---------- Export naar Excel ----------
  const exportKnop = $('#exportKnop');
  if (exportKnop) exportKnop.addEventListener('click', () => {
    if (typeof XLSX === 'undefined') { alert('Excel-export kon niet laden.'); return; }
    const data = JSON.parse($('#exportData').textContent);
    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Bewerkt');
    const d = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, (exportKnop.dataset.naam || 'voorraad') + '-' + d + '.xlsx');
  });

  // ---------- Export-knoppen met data-export="<id van JSON-script>" ----------
  $$('[data-export]').forEach((knop) => knop.addEventListener('click', () => {
    if (typeof XLSX === 'undefined') { alert('Excel-export kon niet laden.'); return; }
    const data = JSON.parse(document.getElementById(knop.dataset.export).textContent);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(data), 'Lijst');
    XLSX.writeFile(wb, (knop.dataset.naam || 'export') + '-' + new Date().toISOString().slice(0, 10) + '.xlsx');
  }));

  // Kopvinkje dat alle vinkjes in dezelfde tabel aan/uit zet
  $$('[data-alles]').forEach((c) => c.addEventListener('change', () => {
    $$('tbody input[type=checkbox]', c.closest('table')).forEach((x) => { x.checked = c.checked; });
  }));

  // ---------- Materiaallijst importeren op de projectpagina ----------
  const matIn = $('#matBestand');
  if (matIn) {
    let mwb = null;
    const mvelden = [
      ['skip', /totaal|bedrag|prijs|waarde/i],
      ['ean', /ean|barcode|gtin/i],
      ['code', /artikelnummer|artikelnr|art\.?\s?nr|rexel|bestelcode|bestelnummer|^code$|artikelcode/i],
      ['eenheid', /eenheid|^eh$|unit/i],
      ['aantal', /aantal|hoeveelheid|qty|stuks|^st\.?$|^aant/i],
      ['omschrijving', /omschrijving|benaming|artikel|product|materiaal|naam|description/i],
    ];
    const mlees = (sheetName) => {
      const rows = XLSX.utils.sheet_to_json(mwb.Sheets[sheetName], { header: 1, raw: true, defval: null });
      let hi = -1, map = {};
      for (let i = 0; i < Math.min(rows.length, 40); i++) {
        const m = {};
        (rows[i] || []).forEach((c, j) => {
          if (typeof c !== 'string') return;
          for (const [veld, re] of mvelden) { if (re.test(c.trim())) { if (veld !== 'skip' && !(veld in m)) m[veld] = j; break; } }
        });
        if ('omschrijving' in m && 'aantal' in m) { hi = i; map = m; break; }
      }
      const info = $('#matInfo');
      if (hi < 0) { info.textContent = 'Geen kopregel gevonden met een omschrijving- en aantalkolom.'; $('#matKnop').disabled = true; return; }
      const regels = [];
      for (let i = hi + 1; i < rows.length; i++) {
        const r = rows[i] || [];
        const oms = r[map.omschrijving];
        if (oms == null || String(oms).trim() === '') continue;
        const val = (k) => (k in map ? r[map[k]] : null);
        const txt = (v) => (v == null ? '' : (typeof v === 'number' ? v.toLocaleString('fullwide', { useGrouping: false }) : String(v).trim()));
        regels.push({ omschrijving: String(oms).trim(), ean: txt(val('ean')), code: txt(val('code')), aantal: val('aantal'), eenheid: txt(val('eenheid')) });
      }
      $('#matRegels').value = JSON.stringify(regels);
      info.textContent = regels.length + ' regels gevonden op "' + sheetName + '". Kolommen: ' + Object.keys(map).map((k) => k + ' = "' + rows[hi][map[k]] + '"').join(', ') + '.';
      $('#matKnop').disabled = regels.length === 0;
    };
    matIn.addEventListener('change', () => {
      const f = matIn.files[0];
      if (!f) return;
      if (typeof XLSX === 'undefined') { alert('Excel-lezer kon niet laden.'); return; }
      $('#matBestandNaam').value = f.name;
      const rd = new FileReader();
      rd.onload = (ev) => {
        mwb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array' });
        const sel = $('#matSheet');
        sel.innerHTML = '';
        mwb.SheetNames.forEach((n) => { const o = document.createElement('option'); o.textContent = n; sel.appendChild(o); });
        $('#matSheetKeuze').hidden = mwb.SheetNames.length < 2;
        sel.onchange = () => mlees(sel.value);
        mlees(mwb.SheetNames[0]);
      };
      rd.readAsArrayBuffer(f);
    });
  }

  // ---------- Import: Excel lezen in de browser ----------
  const fileIn = $('#bestandInput');
  if (fileIn) {
    let wb = null;
    const velden = [
      ['skip', /totale|som van|rijlabel/i],
      ['code', /^\s*(ww[- ]?)?code\s*$/i],
      ['prijs', /waarde per|prijs|per stuk|stukprijs/i],
      ['ean', /ean|barcode/i],
      ['groep', /groep|categorie/i],
      ['locatie', /locatie|plaats|stelling/i],
      ['aantal', /aantal|voorraad|stuks/i],
      ['naam', /omschrijving|product|artikel|naam/i],
    ];
    function lees(sheetName) {
      const rows = XLSX.utils.sheet_to_json(wb.Sheets[sheetName], { header: 1, raw: true, defval: null });
      let hi = -1, map = {};
      for (let i = 0; i < Math.min(rows.length, 30); i++) {
        const m = {};
        (rows[i] || []).forEach((c, j) => {
          if (typeof c !== 'string') return;
          for (const [veld, re] of velden) {
            if (re.test(c)) { if (veld !== 'skip' && !(veld in m)) m[veld] = j; break; }
          }
        });
        if ('naam' in m && ('aantal' in m || 'ean' in m || 'prijs' in m)) { hi = i; map = m; break; }
      }
      const info = $('#importInfo');
      if (hi < 0) { info.textContent = 'Geen kopregel gevonden met een productkolom en aantal/EAN/prijs.'; $('#importKnop').disabled = true; return; }
      const regels = [];
      for (let i = hi + 1; i < rows.length; i++) {
        const r = rows[i] || [];
        const naam = r[map.naam];
        if (naam === null || naam === undefined || String(naam).trim() === '') continue;
        const val = (k) => (k in map ? r[map[k]] : null);
        let ean = val('ean');
        if (typeof ean === 'number') ean = ean.toLocaleString('fullwide', { useGrouping: false });
        regels.push({
          naam: String(naam).trim(), groep: val('groep') == null ? '' : String(val('groep')).trim(),
          ean: ean == null ? '' : String(ean), aantal: val('aantal'), prijs: val('prijs'),
          locatie: val('locatie') == null ? '' : String(val('locatie')).trim(),
          code: val('code') == null ? '' : String(val('code')).trim(),
        });
      }
      $('#regels').value = JSON.stringify(regels);
      const gevonden = Object.keys(map).map((k) => k + ' = "' + rows[hi][map[k]] + '"').join(', ');
      info.textContent = regels.length + ' productregels gevonden op tabblad "' + sheetName + '". Kolommen: ' + gevonden + '.';
      $('#importKnop').disabled = regels.length === 0;
    }
    fileIn.addEventListener('change', () => {
      const f = fileIn.files[0];
      if (!f) return;
      if (typeof XLSX === 'undefined') { alert('Excel-lezer kon niet laden.'); return; }
      $('#bestand').value = f.name;
      const rd = new FileReader();
      rd.onload = (ev) => {
        wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array' });
        const sel = $('#sheetSelect');
        sel.innerHTML = '';
        wb.SheetNames.forEach((n) => { const o = document.createElement('option'); o.textContent = n; sel.appendChild(o); });
        const start = wb.SheetNames.find((n) => /^bewerkt$/i.test(n)) || wb.SheetNames[0];
        sel.value = start;
        $('#sheetKeuze').hidden = wb.SheetNames.length < 2;
        sel.onchange = () => lees(sel.value);
        lees(start);
      };
      rd.readAsArrayBuffer(f);
    });
  }

  // ---------- Labels ----------
  $$('[data-qr]').forEach((el) => {
    if (typeof QRCode === 'undefined') return;
    const px = parseInt(el.dataset.px || '180', 10);
    new QRCode(el, { text: el.dataset.qr, width: px, height: px, correctLevel: QRCode.CorrectLevel.M });
  });
  // Poster: invoervelden live doorzetten naar de poster
  $$('[data-bind]').forEach((inp) => {
    const sync = () => {
      const k = inp.dataset.bind;
      $$('[data-out="' + k + '"]').forEach((o) => { o.textContent = inp.value; });
      $$('[data-show="' + k + '"]').forEach((o) => { o.hidden = inp.value.trim() === ''; });
    };
    inp.addEventListener('input', sync);
    sync();
  });

  // Labels: hele groepen kiezen, filteren zonder selectie te verliezen, teller
  const lblForm = $('#labelForm');
  if (lblForm) {
    const groepVan = (el) => el.closest('[data-groep]');
    const zichtbaar = (rij) => !rij.hidden;
    const bijwerken = () => {
      $$('.lblgroep', lblForm).forEach((grp) => {
        const cs = $$('.pcheck', grp);
        const aan = cs.filter((c) => c.checked).length;
        $('[data-telling]', grp).textContent = aan + ' van ' + cs.length;
        const chip = lblForm.querySelector('[data-groepkies="' + CSS.escape(grp.dataset.groep) + '"]');
        if (chip) { chip.checked = aan > 0 && aan === cs.length; chip.indeterminate = aan > 0 && aan < cs.length; }
      });
      const n = $$('.pcheck', lblForm).filter((c) => c.checked).length;
      const p = ($('textarea[name=projecten]', lblForm).value.split('\n').filter((x) => x.trim()).length);
      $('#lblTeller').textContent = (n + p) + ' labels geselecteerd' + (p ? ' (waarvan ' + p + ' project)' : '');
    };
    $$('[data-groepkies]', lblForm).forEach((chip) => chip.addEventListener('change', () => {
      const grp = lblForm.querySelector('.lblgroep[data-groep="' + CSS.escape(chip.dataset.groepkies) + '"]');
      $$('.lblrij', grp).filter(zichtbaar).forEach((rij) => { $('.pcheck', rij).checked = chip.checked; });
      if (chip.checked) grp.open = true;
      bijwerken();
    }));
    const filter = () => {
      const q = $('#lblZoek').value.trim().toLowerCase();
      const f = $('#lblFilter').value;
      $$('.lblrij', lblForm).forEach((rij) => {
        rij.hidden = (q && !rij.dataset.tekst.includes(q)) || (f === 'op' && rij.dataset.op !== '1') || (f === 'zonderean' && rij.dataset.ean !== '0');
      });
      $$('.lblgroep', lblForm).forEach((grp) => {
        const zicht = $$('.lblrij', grp).filter(zichtbaar).length;
        grp.hidden = zicht === 0;
        if (q) grp.open = zicht > 0;
      });
    };
    $('#lblZoek').addEventListener('input', filter);
    $('#lblFilter').addEventListener('change', filter);
    $('#lblGeen').addEventListener('click', () => { $$('.pcheck', lblForm).forEach((c) => { c.checked = false; }); bijwerken(); });
    lblForm.addEventListener('change', (e) => { if (e.target.classList.contains('pcheck')) bijwerken(); });
    $('textarea[name=projecten]', lblForm).addEventListener('input', bijwerken);
    lblForm.addEventListener('submit', (e) => {
      if ($('#lblTeller').textContent.startsWith('0 ')) { e.preventDefault(); alert('Kies eerst een groep, product of projectnummer.'); }
    });
    // Na "Terug" vanuit de printweergave herstelt de browser de vinkjes: teller en groepen bijwerken
    window.addEventListener('pageshow', () => { bijwerken(); $$('.lblgroep', lblForm).forEach((g) => { if ($$('.pcheck:checked', g).length) g.open = true; }); });
    bijwerken();
  }

  // ---------- Planning: middag gelijk aan de ochtend ----------
  const zelfde = $('#zelfde');
  if (zelfde) zelfde.addEventListener('change', () => { $('#middagvelden').hidden = zelfde.checked; });

  // ---------- Foto's: verkleinen op de telefoon en één voor één versturen ----------
  const fotoInvoer = $('#fotoInvoer');
  if (fotoInvoer) {
    const form = $('#fotoForm'), status = $('#fotoStatus');
    const verklein = (file) => new Promise((klaar) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        const max = 1800, f = Math.min(1, max / Math.max(img.width, img.height));
        const c = document.createElement('canvas');
        c.width = Math.round(img.width * f); c.height = Math.round(img.height * f);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob((b) => klaar(b || file), 'image/jpeg', 0.82);
      };
      img.onerror = () => { URL.revokeObjectURL(url); klaar(file); };
      img.src = url;
    });
    fotoInvoer.addEventListener('change', async () => {
      const files = Array.from(fotoInvoer.files || []);
      if (!files.length) return;
      let goed = 0; const fouten = [];
      for (let i = 0; i < files.length; i++) {
        status.textContent = 'Foto ' + (i + 1) + ' van ' + files.length + ' wordt verstuurd...';
        try {
          const blob = await verklein(files[i]);
          const fd = new FormData(form);
          fd.delete('foto');
          fd.append('foto', blob, 'foto.jpg');
          fd.append('via', 'js');
          const r = await fetch(form.getAttribute('action') || location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
          const j = await r.json();
          if (j.ok) goed++; else fouten.push(j.fout || 'onbekende fout');
        } catch (err) { fouten.push('geen verbinding'); }
      }
      if (fouten.length) { status.textContent = goed + ' gelukt, ' + fouten.length + ' mislukt: ' + fouten[0]; fotoInvoer.value = ''; if (!goed) return; }
      else status.textContent = goed + (goed === 1 ? ' foto' : " foto's") + ' toegevoegd.';
      setTimeout(() => location.reload(), fouten.length ? 2500 : 500);
    });
  }

  // ---------- Seintjes: elke minuut kijken of er iets nieuws is, ook als je op een ander scherm zit ----------
  const teller = $('#seintjeTeller');
  if (teller) {
    let bekend = parseInt(teller.dataset.n || '0', 10);
    const toon = (tekst) => {
      let t = $('#seintjeToast');
      if (!t) { t = document.createElement('div'); t.id = 'seintjeToast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
      t.innerHTML = '';
      const p = document.createElement('div'); p.textContent = tekst; t.appendChild(p);
      const a = document.createElement('a'); a.href = 'dag.php#seintjes'; a.textContent = 'Bekijk'; t.appendChild(a);
      const b = document.createElement('button'); b.className = 'btn small'; b.textContent = 'Sluiten'; b.style.marginLeft = '.8rem'; b.onclick = () => t.remove(); t.appendChild(b);
    };
    const kijk = () => fetch('seintjes.php', { credentials: 'same-origin', cache: 'no-store' }).then((r) => r.json()).then((j) => {
      teller.textContent = j.n ? j.n + ' nieuw' : 'Seintjes';
      teller.classList.toggle('aan', j.n > 0);
      document.title = (j.n ? '(' + j.n + ') ' : '') + document.title.replace(/^\(\d+\) /, '');
      if (j.n > bekend && j.nieuw.length) toon(j.nieuw[0].tekst);
      bekend = j.n;
    }).catch(() => {});
    setInterval(kijk, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) kijk(); });
  }
})();

// ===== Versie 19-11: menu, takenbord, planning verplaatsen, projecten zoeken =====
(function () {
  'use strict';
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const csrf = () => { const i = $('input[name=csrf]'); return i ? i.value : ''; };
  async function post(url, data) {
    const fd = new FormData(); fd.append('csrf', csrf());
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
    if (!r.ok) throw new Error('De server gaf een fout (' + r.status + '). Ververs de pagina en probeer opnieuw.');
    return r.json();
  }

  // Uitklapmenu's sluiten bij klikken ernaast
  document.addEventListener('click', (e) => {
    $$('details.menu[open]').forEach((d) => { if (!d.contains(e.target)) d.open = false; });
  });

  // ---------- Takenbord ----------
  const bord = $('.bord');
  if (bord) {
    const tabs = $$('.bord-dag', bord);
    const kaarten = () => $$('.kaart', bord);
    const bulk = $('.bord-bulk', bord);
    const hoort = (tab, dag, k) => {
      if (k && k.dataset.wacht === '1') return true;   // Wacht op: altijd zichtbaar, telt niet mee per dag
      if (dag === 'laat') return tab.dataset.laat === '1';
      if (dag === 'geen') return tab.dataset.geen === '1';
      return tab.dataset.van !== '' && dag >= tab.dataset.van && dag <= tab.dataset.tot;
    };
    // Na afvinken of een ander formulier herlaadt de pagina: dan dezelfde dag weer openen (zelfde pagina, binnen 2 minuten)
    let terugDag = null;
    try {
      const b = JSON.parse(sessionStorage.getItem('bord-dag') || 'null');
      sessionStorage.removeItem('bord-dag');
      if (b && b.pad === location.pathname + location.search && Date.now() - b.t < 120000) terugDag = b.k;
    } catch (e) { /* geen opslag */ }
    let actief = (location.hash.match(/^#dag=(\w+)/) || [])[1] || terugDag || 'vandaag';
    bord.addEventListener('submit', (e) => {
      if (e.target.classList.contains('bord-bulk')) return;
      try { sessionStorage.setItem('bord-dag', JSON.stringify({ k: actief, pad: location.pathname + location.search, t: Date.now() })); } catch (err) { /* geen opslag */ }
    });
    function toon() {
      const tab = tabs.find((t) => t.dataset.k === actief) || tabs[1];
      tabs.forEach((t) => {
        const n = kaarten().filter((k) => k.dataset.wacht !== '1' && hoort(t, k.dataset.dag)).length;
        $('.n', t).textContent = n;
        t.classList.toggle('on', t === tab);
        t.classList.toggle('leeg', n === 0 && t !== tab);
      });
      kaarten().forEach((k) => { k.hidden = !hoort(tab, k.dataset.dag, k); });
      $$('.bord-kol', bord).forEach((kol) => {
        const n = $$('.kaart', kol).filter((k) => !k.hidden).length;
        $('.n', kol).textContent = n || '';
        $('.bord-leeg', kol).hidden = n > 0;
      });
      if (bulk) bulk.hidden = !['laat'].includes(tab.dataset.k);
    }
    // ✎: status, wacht op en notitie direct op de kaart
    bord.addEventListener('click', (e) => {
      const open = e.target.closest('.kaart-meer-knop'), dicht = e.target.closest('.kaart-meer-dicht');
      if (!open && !dicht) return;
      const k = e.target.closest('.kaart'), f = $('.kaart-meer', k);
      f.hidden = !!dicht || !f.hidden; k.draggable = f.hidden;
      if (!f.hidden) { toonWacht(f); const i = $('input[name=notitie]', f); if (i) i.focus(); }
    });
    // ↑ ↓ in het ✎-paneel (voor de telefoon): een plek hoger of lager tussen de zichtbare kaarten
    bord.addEventListener('click', (e) => {
      const b = e.target.closest('[data-schuif]'); if (!b) return;
      const k = b.closest('.kaart'), kol = k.parentElement;
      const zicht = $$('.kaart', kol).filter((x) => !x.hidden), i = zicht.indexOf(k), j = i + Number(b.dataset.schuif);
      if (j < 0 || j >= zicht.length) return;
      if (j < i) kol.insertBefore(k, zicht[j]); else kol.insertBefore(k, zicht[j].nextElementSibling);
      k.scrollIntoView({ block: 'nearest' }); bewaarVolgorde(kol);
    });
    function toonWacht(f) { const w = $('.kaart-wachtop', f), r = $('input[name=status]:checked', f); w.hidden = !r || r.value !== 'wacht'; }
    bord.addEventListener('change', (e) => {
      if (e.target.name !== 'status') return;
      const f = e.target.closest('.kaart-meer'); toonWacht(f);
      if (e.target.value === 'wacht') $('.kaart-wachtop', f).focus();
    });
    tabs.forEach((t) => t.addEventListener('click', () => { actief = t.dataset.k; history.replaceState(null, '', '#dag=' + actief); toon(); }));

    let sleep = null;
    bord.addEventListener('dragstart', (e) => { const k = e.target.closest('.kaart'); if (!k) return; sleep = k; k.classList.add('sleept'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', k.dataset.id); });
    bord.addEventListener('dragend', () => { if (sleep) sleep.classList.remove('sleept'); sleep = null; $$('.over, .ervoor, .erna', bord).forEach((x) => x.classList.remove('over', 'ervoor', 'erna')); });
    // Volgorde van een kolom opslaan (alle kaarten in de kolom, van boven naar beneden)
    async function bewaarVolgorde(kol) {
      const ids = $$('.kaart', kol).map((k) => k.dataset.id);
      const fd = new FormData(); fd.append('csrf', csrf()); fd.append('actie', 'volgorde'); ids.forEach((i) => fd.append('ids[]', i));
      try { const r = await fetch('taak.php', { method: 'POST', body: fd, credentials: 'same-origin' }); if (!r.ok) throw new Error(); }
      catch (err) { alert('De volgorde kon niet worden opgeslagen. Ververs de pagina.'); }
    }
    const weg = (x, ...c) => x.classList.toggle(c[0], false) || c.slice(1).forEach((k) => x.classList.toggle(k, false));
    let plek = null; // kaart waarvoor de gesleepte kaart komt (null = onderaan)
    bord.addEventListener('dragover', (e) => {
      const doel = e.target.closest('.bord-kol, .bord-dag[data-doel]');
      if (!sleep || !doel) return;
      e.preventDefault();
      $$('.over, .ervoor, .erna', bord).forEach((x) => { if (x !== doel) weg(x, 'over', 'ervoor', 'erna'); });
      doel.classList.add('over');
      plek = null;
      const kaart = e.target.closest('.kaart');
      if (kaart && kaart !== sleep && doel.classList.contains('bord-kol')) {
        weg(kaart, 'ervoor', 'erna');
        const r = kaart.getBoundingClientRect(), onder = e.clientY > r.top + r.height / 2;
        kaart.classList.add(onder ? 'erna' : 'ervoor');
        plek = onder ? kaart.nextElementSibling : kaart;
        if (plek && !plek.classList.contains('kaart')) plek = null;
      }
    });
    bord.addEventListener('drop', async (e) => {
      const doel = e.target.closest('.bord-kol, .bord-dag[data-doel]');
      if (!sleep || !doel) return;
      e.preventDefault();
      const k = sleep; doel.classList.remove('over');
      const data = { actie: 'verplaats', id: k.dataset.id };
      let herladen = false;
      $$('.ervoor, .erna', bord).forEach((x) => weg(x, 'ervoor', 'erna'));
      if (doel.classList.contains('bord-kol') && k.parentElement === doel) {
        // Zelfde kolom: alleen de volgorde
        doel.insertBefore(k, plek || $('.bord-leeg', doel));
        bewaarVolgorde(doel); return;
      }
      if (doel.classList.contains('bord-kol')) {
        if (doel.dataset.p === 'wacht') {
          const w = prompt('Waar wacht je op? Bijvoorbeeld: Robert Geerlings (Spuigroep), antwoord klant, levering Rexel');
          if (w === null) return;
          data.status = 'wacht'; data.wacht_op = w; herladen = true;
        } else {
          data.prioriteit = doel.dataset.p;
          if (k.dataset.wacht === '1') { data.status = 'open'; herladen = true; }
        }
      } else data.datum = doel.dataset.doel;
      try {
        const r = await post('taak.php', data);
        if (!r.ok) throw new Error(r.fout || 'Niet gelukt.');
        if (herladen) { location.reload(); return; }
        k.dataset.dag = r.dag;
        k.classList.toggle('laat', r.dag === 'laat');
        const dl = $('.kaart-dl', k);
        if (dl && r.deadline) { dl.textContent = ' · uiterlijk ' + r.deadline; dl.classList.toggle('rood', r.dag === 'laat'); }
        const kol = $('.bord-kol[data-p="' + r.prioriteit + '"]', bord);
        if (kol && k.parentElement !== kol) { kol.insertBefore(k, (plek && plek.parentElement === kol) ? plek : $('.bord-leeg', kol)); bewaarVolgorde(kol); }
        toon();
      } catch (err) { alert(err.message); }
    });
    toon();
  }

  // ---------- Planning: blok kiezen, verplaatsen, slepen ----------
  const balk = $('#planbalk');
  if (balk) {
    const werkdagen = JSON.parse($('#werkdagen').textContent);
    const label = (d) => (werkdagen.find((w) => w[0] === d) || [d, d.split('-').reverse().slice(0, 2).join('-')])[1];
    let keuze = null;
    const melding = $('#pbMelding');
    function sluit() { balk.hidden = true; keuze = null; $$('.slot.blok.on, .dagknop.on').forEach((x) => x.classList.remove('on')); }
    function openBalk(k) {
      keuze = k; melding.textContent = ''; melding.className = 'small';
      $('#pbTitel').textContent = k.titel;
      const wat = $('#pbWat'); wat.innerHTML = '';
      if (k.opties) {
        k.opties.forEach((o, i) => {
          const l = document.createElement('label');
          l.innerHTML = '<input type="radio" name="pbwat" value="' + o[0] + '"' + (i === 0 ? ' checked' : '') + '> ';
          l.appendChild(document.createTextNode(o[1])); wat.appendChild(l);
        });
      }
      const dagen = $('#pbDagen'); dagen.innerHTML = '<span class="small muted">Verplaatsen naar</span>';
      werkdagen.filter((w) => w[0] !== k.datum && w[0] >= balk.dataset.vandaag).slice(0, 8).forEach((w) => {
        const b = document.createElement('button'); b.type = 'button'; b.className = 'btn'; b.textContent = w[1];
        b.addEventListener('click', () => verplaats(w[0])); dagen.appendChild(b);
      });
      $('#pbDatum').value = '';
      const bw = $('#pbBewerk'); bw.hidden = !k.bewerk; if (k.bewerk) bw.href = k.bewerk;
      $('#pbWeg').hidden = k.soort === 'dag';
      balk.hidden = false;
    }
    async function verplaats(naar, naarGid) {
      if (!keuze) return;
      const soort = (($('input[name=pbwat]:checked') || {}).value) || keuze.soort;
      const data = { actie: soort, datum: keuze.datum, naar: naar };
      if (soort === 'sleep') { data.gid = keuze.gid; data.dagdeel = keuze.dagdeel; data.naar_gid = naarGid || keuze.gid; }
      if (soort === 'project') data.project_id = keuze.pid;
      melding.textContent = 'Bezig…';
      try {
        let r = await post('planning.php', data);
        if (r.conflicten) {
          if (!confirm('Op de nieuwe plek staat al iets:\n\n' + r.conflicten.join('\n') + '\n\nOverschrijven?')) { melding.textContent = 'Niets veranderd.'; return; }
          data.uitvoeren = 1; r = await post('planning.php', data);
        }
        if (!r.ok) throw new Error(r.fout || 'Niet gelukt.');
        location.reload();
      } catch (err) { melding.textContent = err.message; melding.className = 'small fout'; }
    }
    $('#pbDatum').addEventListener('change', (e) => { if (e.target.value) verplaats(e.target.value); });
    $('#pbWeg').addEventListener('click', async () => {
      if (!keuze) return;
      const soort = (($('input[name=pbwat]:checked') || {}).value) || keuze.soort;
      const alles = soort === 'project';
      const vraag = alles ? 'Het hele project op ' + label(keuze.datum) + ' uit de planning halen (alle monteurs)?'
        : keuze.naam + ' op ' + label(keuze.datum) + (keuze.dagdeel !== 'dag' ? ' (' + keuze.dagdeel + ')' : '') + ' uit de planning halen?';
      if (!confirm(vraag)) return;
      const data = { actie: 'weg', datum: keuze.datum };
      if (alles) data.project_id = keuze.pid; else { data.gid = keuze.gid; data.dagdeel = keuze.dagdeel; }
      melding.textContent = 'Bezig…';
      try { const r = await post('planning.php', data); if (!r.ok) throw new Error(r.fout || 'Niet gelukt.'); location.reload(); }
      catch (err) { melding.textContent = err.message; melding.className = 'small fout'; }
    });
    $('#pbSluit').addEventListener('click', sluit);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') sluit(); });

    function blokKeuze(b) {
      const cel = b.closest('.cel');
      const d = cel.dataset.datum, gid = cel.dataset.gid, pid = b.dataset.pid;
      const naam = b.dataset.naam;
      const zelfde = pid !== '0' ? $$('.rooster-breed .cel[data-datum="' + d + '"] .slot.blok[data-pid="' + pid + '"]').map((x) => x.closest('.cel').dataset.gid) : [];
      const man = new Set(zelfde).size;
      const opties = [];
      opties.push(['sleep', 'Alleen ' + naam + (b.dataset.dagdeel !== 'dag' ? ' (' + b.dataset.dagdeel + ')' : '')]);
      if (man > 1) opties.push(['project', 'Alle ' + man + ' monteurs op dit project']);
      return { soort: opties[0][0], opties: opties.length > 1 ? opties : null, datum: d, gid: gid, pid: pid, dagdeel: b.dataset.dagdeel, naam: naam,
        titel: b.dataset.titel + ' · ' + naam + ' · ' + label(d), bewerk: cel.getAttribute('href') };
    }
    document.addEventListener('click', (e) => {
      const b = e.target.closest('.slot.blok');
      if (b && b.closest('a.cel[data-datum]')) {
        e.preventDefault();
        $$('.slot.blok.on, .dagknop.on').forEach((x) => x.classList.remove('on'));
        b.classList.add('on'); openBalk(blokKeuze(b)); return;
      }
      const dk = e.target.closest('.dagknop');
      if (dk) {
        $$('.slot.blok.on, .dagknop.on').forEach((x) => x.classList.remove('on'));
        dk.classList.add('on');
        openBalk({ soort: 'dag', datum: dk.dataset.dag, titel: 'Hele ' + dk.dataset.label + ' verplaatsen (bijvoorbeeld bij regen)', bewerk: '' });
      }
    });

    let sleep = null;
    document.addEventListener('dragstart', (e) => {
      const b = e.target.closest('.slot.blok'); if (!b || !b.closest('a.cel[data-datum]')) return;
      sleep = b; b.classList.add('sleept'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'blok');
    });
    document.addEventListener('dragend', () => { if (sleep) sleep.classList.remove('sleept'); sleep = null; $$('.cel.over').forEach((x) => x.classList.remove('over')); });
    document.addEventListener('dragover', (e) => {
      const c = e.target.closest('a.cel[data-datum]'); if (!sleep || !c) return;
      e.preventDefault(); $$('.cel.over').forEach((x) => x !== c && x.classList.remove('over')); c.classList.add('over');
    });
    document.addEventListener('drop', (e) => {
      const c = e.target.closest('a.cel[data-datum]'); if (!sleep || !c) return;
      e.preventDefault(); c.classList.remove('over');
      const k = blokKeuze(sleep);
      if (c.dataset.datum === k.datum && c.dataset.gid === k.gid) return;
      keuze = Object.assign(k, { soort: 'sleep' }); $('#pbWat').innerHTML = '';
      verplaats(c.dataset.datum, c.dataset.gid);
    });
  }
  const overlay = $('#bewerkOverlay');
  if (overlay) overlay.addEventListener('click', (e) => { if (e.target === overlay) { const s = $('.bewerk a.btn.ghost', overlay); if (s) location.href = s.href; } });

  // ---------- Projectpagina: tabbladen, tijdlijn-filter, bewerken ----------
  const tabs = $$('.ptabs [data-tab]');
  if (tabs.length) {
    const kies = (t) => {
      if (!tabs.some((a) => a.dataset.tab === t)) t = 'overzicht';
      tabs.forEach((a) => a.classList.toggle('on', a.dataset.tab === t));
      $$('.ptab').forEach((p) => { p.hidden = p.dataset.tab !== t; });
    };
    tabs.forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); history.replaceState(null, '', '#' + a.dataset.tab); kies(a.dataset.tab); }));
    $$('[data-tab-link]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); history.replaceState(null, '', '#' + a.dataset.tabLink); kies(a.dataset.tabLink); window.scrollTo({ top: 0 }); }));
    kies((location.hash || '#overzicht').slice(1));
    window.addEventListener('hashchange', () => kies((location.hash || '#overzicht').slice(1)));
    $$('[data-feed]').forEach((b) => b.addEventListener('click', () => {
      $$('[data-feed]').forEach((x) => x.classList.toggle('on', x === b));
      $$('.ptab[data-tab=tijdlijn] .fr').forEach((r) => { r.hidden = b.dataset.feed !== 'alles' && r.dataset.groep !== b.dataset.feed; });
    }));
    document.addEventListener('click', (e) => { const m = e.target.closest('.fr-meer'); if (m) { m.previousElementSibling.classList.remove('kort'); m.hidden = true; } });
    const bk = $('#bewerkKnop'), bf = $('#bewerkForm');
    if (bk && bf) bk.addEventListener('click', () => { bf.hidden = !bf.hidden; $('.pdetails').hidden = !bf.hidden; bk.textContent = bf.hidden ? 'Bewerken' : 'Sluiten'; });
  }

  // ---------- Projecten: weergave (tijdlijn, bord, lijst) en zoeken ----------
  const zoek = $('#prZoek');
  if (zoek) {
    const filter = $('#prFilter'), knoppen = $$('.weergave [data-w]'), panelen = $$('.pw');
    const smal = () => window.matchMedia('(max-width: 760px)').matches;
    let w = null;
    try { w = localStorage.getItem('projecten-weergave'); } catch (e) { /* geen opslag */ }
    if (!w || (w === 'tijdlijn' && smal())) w = smal() ? 'bord' : 'tijdlijn';
    function kies(nw) {
      w = nw;
      knoppen.forEach((b) => b.classList.toggle('on', b.dataset.w === w));
      panelen.forEach((pn) => { pn.hidden = pn.dataset.w !== w; });
      try { localStorage.setItem('projecten-weergave', w); } catch (e) { /* geen opslag */ }
      zoekNu();
    }
    function zoekNu() {
      const q = zoek.value.toLowerCase().trim().replace(/^prj[-\s]*/, '');
      const f = filter.value; let n = 0;
      const pn = panelen.find((x) => x.dataset.w === w);
      $$('.pr-item', pn).forEach((r) => {
        const ok = (!q || q.split(/\s+/).every((x) => r.dataset.zoek.includes(x)))
          && (f !== 'plan' || r.dataset.plan === '1') && (f !== 'geenplan' || r.dataset.plan === '0')
          && (f !== 'geeneinde' || r.dataset.einde === '0') && (f !== 'geenadres' || r.dataset.adres === '0');
        r.hidden = !ok; if (ok) n++;
      });
      $('#prTeller').textContent = n; $('#prGeen').hidden = n > 0;
    }
    knoppen.forEach((b) => b.addEventListener('click', () => kies(b.dataset.w)));
    zoek.addEventListener('input', zoekNu); filter.addEventListener('change', zoekNu);
    document.addEventListener('keydown', (e) => { if (e.key === '/' && document.activeElement !== zoek) { e.preventDefault(); zoek.focus(); } });
    kies(w);
  }
})();

// ---------- Service worker: pushmeldingen en Mijn dag offline (ronde 3) ----------
(function () {
  if (!('serviceWorker' in navigator)) return;
  // Uitgelogd: bewaarde Mijn dag weggooien, zodat de volgende gebruiker die niet ziet
  if (/login\.php$/.test(location.pathname) && window.caches) { caches.keys().then((ks) => ks.forEach((k) => caches.delete(k))).catch(() => {}); }
  navigator.serviceWorker.register('sw.js').catch(() => {});

  const knop = document.getElementById('pushKnop');
  if (!knop) return;
  const status = document.getElementById('pushStatus'), test = document.getElementById('pushTest');
  const csrf = knop.dataset.csrf;
  const zeg = (t) => { if (status) status.textContent = t; };
  if (!('PushManager' in window) || !('Notification' in window)) {
    knop.disabled = true;
    zeg('Dit apparaat of deze browser ondersteunt geen meldingen. Op een iPhone: zet de app eerst op je beginscherm (Delen > Zet op beginscherm) en open hem daarvandaan.');
    return;
  }
  const post = (data) => fetch('api_push.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(Object.assign({ csrf }, data)) }).then((r) => r.json());
  const b64 = (s) => { const p = '='.repeat((4 - (s.length % 4)) % 4); const r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(r, (c) => c.charCodeAt(0)); };
  async function stand() {
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    knop.textContent = sub ? 'Meldingen uitzetten' : 'Meldingen aanzetten';
    knop.dataset.aan = sub ? '1' : '0';
    if (test) test.hidden = !sub;
    zeg(sub ? 'Meldingen staan aan op dit apparaat.' : (Notification.permission === 'denied' ? 'Meldingen zijn geblokkeerd in de instellingen van je telefoon of browser.' : 'Meldingen staan uit op dit apparaat.'));
    return { reg, sub };
  }
  knop.addEventListener('click', async () => {
    knop.disabled = true;
    try {
      const { reg, sub } = await stand();
      if (sub) { await post({ actie: 'uit', endpoint: sub.endpoint }); await sub.unsubscribe(); }
      else {
        if ((await Notification.requestPermission()) !== 'granted') { zeg('Geen toestemming gekregen voor meldingen.'); return; }
        const k = await fetch('api_push.php?sleutel', { credentials: 'same-origin' }).then((r) => r.json());
        const nieuw = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(k.sleutel) });
        await post({ actie: 'aan', endpoint: nieuw.endpoint });
      }
    } catch (e) { zeg('Het lukte niet: ' + e.message); }
    finally { knop.disabled = false; stand().catch(() => {}); }
  });
  if (test) test.addEventListener('click', () => post({ actie: 'test' }).then(() => zeg('Testmelding verstuurd; hij komt binnen een paar seconden.')));
  stand().catch(() => zeg(''));
})();
