<?php
// QR-labels printen voor producten (stelling/bak) en voor projecten.
require __DIR__ . '/inc/bootstrap.php';
require_admin();

$in = $_POST ?: $_GET;
$projectNrs = array_values(array_filter(array_map('project_nummer', explode("\n", (string)($in['projecten'] ?? '')))));
$ids = array_map('intval', (array)($in['id'] ?? []));

// Labelformaten op A4: naam => [omschrijving, kolommen, rijen, breedte mm, hoogte mm, QR mm, bovenmarge mm]
const FORMATEN = [
    'klein'  => ['Klein · 24 per vel (3 × 8, 70 × 37 mm, stickervel)', 3, 8, 70, 37, 30, 0.5],
    'middel' => ['Middel · 8 per vel (2 × 4, 105 × 74 mm)', 2, 4, 105, 74, 46, 0.5],
    'groot'  => ['Groot · 4 per vel (2 × 2, 105 × 148 mm)', 2, 2, 105, 148, 85, 0.5],
    'a5'     => ['Extra groot · 2 per vel (A5, 210 × 148 mm)', 1, 2, 210, 148, 110, 0.5],
    'a4'     => ['Heel A4 · 1 per vel', 1, 1, 210, 296, 160, 0.5],
];
$formaat = isset(FORMATEN[$in['formaat'] ?? '']) ? $in['formaat'] : 'klein';
[$fNaam, $fCols, $fRows, $fW, $fH, $fQr, $fTop] = FORMATEN[$formaat];

$producten = q('SELECT id, code, naam, groep, ean, locatie, voorraad FROM producten WHERE actief = 1 ORDER BY groep, naam')->fetchAll();
$perGroep = [];
foreach ($producten as $p) $perGroep[$p['groep'] !== '' ? $p['groep'] : '(geen groep)'][] = $p;

$labels = [];
if (isset($in['print'])) {
    foreach ($producten as $p) {
        if (!in_array((int)$p['id'], $ids, true)) continue;
        $labels[] = ['qr' => lookup_url($p['code']), 'titel' => $p['naam'], 'sub' => $p['code'] . ($p['locatie'] ? ' · ' . $p['locatie'] : '')];
    }
    foreach ($projectNrs as $nr) {
        $labels[] = ['qr' => lookup_url('PROJECT:' . $nr), 'titel' => 'Project ' . $nr, 'sub' => 'Scan om dit project actief te zetten', 'project' => true];
    }
}

// Export voor Brother P-touch (databasekoppeling): één rij per label
if (($in['export'] ?? '') === 'ptouch') {
    $rijen = [];
    foreach ($producten as $p) {
        if (!in_array((int)$p['id'], $ids, true)) continue;
        $rijen[] = [$p['code'], $p['naam'], mb_strimwidth($p['naam'], 0, 30, '…'), $p['locatie'], $p['groep'], $p['code'], lookup_url($p['code'])];
    }
    foreach ($projectNrs as $nr) {
        $rijen[] = [$nr, 'Project ' . $nr, 'Project ' . $nr, '', 'Project', 'PROJECT:' . $nr, lookup_url('PROJECT:' . $nr)];
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="labels-ptouch-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, zodat P-touch en Excel de tekens goed lezen
    // QR_kort = alleen de code (voor 12 mm tape, leest de app-scanner); QR_link = volledige link (vanaf 18-24 mm, ook gewone camera)
    fputcsv($out, ['Code', 'Naam', 'Naam_kort', 'Locatie', 'Groep', 'QR_kort', 'QR_link'], ';');
    foreach ($rijen as $r) fputcsv($out, $r, ';');
    fclose($out);
    exit;
}

// Rechtstreeks printen op de Brother PT-P710BT (USB-kabel, Chrome of Edge). De QR bevat alleen de code:
// die scant het best op smalle tape en de app-scanner herkent hem.
if (!empty($in['labelprinter'])) {
    $lp = [];
    foreach ($producten as $p) {
        if (!in_array((int)$p['id'], $ids, true)) continue;
        $lp[] = ['qr' => $p['code'], 'titel' => $p['naam'], 'sub' => $p['code'] . ($p['groep'] !== '' ? ' · ' . $p['groep'] : '')];
    }
    foreach ($projectNrs as $nr) {
        $lp[] = ['qr' => 'PROJECT:' . $nr, 'titel' => 'Project ' . $nr, 'sub' => 'Scan om dit project actief te zetten'];
    }
    page_head('Labelprinter', 'labels');
    ?>
<style>
  .ptrij { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid rgba(128,128,128,.2); flex-wrap: wrap; }
  .ptvoorbeeld { image-rendering: pixelated; border: 1px solid rgba(128,128,128,.4); background: #fff; max-width: 100%; height: auto; }
  .ptinfo { display: flex; align-items: center; gap: 10px; }
  .ptmelding { margin: 8px 0 0; font-weight: 600; }
  .ptmelding.ok { color: #1a7f37; } .ptmelding.fout { color: #c62828; } .ptmelding.waarsch { color: #b26a00; }
</style>
<section class="card" id="ptouch">
  <div class="row between wrap"><h1>Labelprinter (PT-P710BT)</h1><button class="btn ghost" type="button" onclick="history.back()">Terug</button></div>
  <p class="muted small">Sluit de printer met een USB-kabel aan op deze computer, zet hem aan en gebruik Chrome of Edge. De eerste keer klik je op <b>Verbind printer</b> en kies je de PT-P710BT. De tapebreedte wordt automatisch herkend.</p>
  <div class="row wrap">
    <button class="btn" type="button" id="ptVerbind">🔌 Verbind printer</button>
    <label>Tape <select id="ptTape"><option value="9">9 mm</option><option value="12" selected>12 mm</option><option value="18">18 mm</option><option value="24">24 mm</option></select></label>
    <label>Aantal per label <input type="number" id="ptAantal" value="1" min="1" max="50" style="width:5em"></label>
    <button class="btn primary" type="button" id="ptAlles">🏷 Alles printen</button>
    <button class="btn ghost" type="button" id="ptStop">Stop</button>
  </div>
  <p id="ptMelding" class="ptmelding"></p>
  <p id="ptTotaal" class="small muted"></p>
  <div id="ptLijst"></div>
</section>
<script type="application/json" id="ptouchData"><?= json_encode($lp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
    page_foot(['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', 'assets/ptouch.js?v=1']);
    exit;
}

page_head('Labels', 'labels');
?>
<?php if ($labels): ?>
<section class="card noprint">
  <div class="row between wrap">
    <p><b><?= count($labels) ?></b> labels · <?= e($fNaam) ?> · <?= (int)ceil(count($labels) / ($fCols * $fRows)) ?> vel(len) A4.<br>
      <span class="small muted">Print op 100% (geen "aanpassen aan pagina") en zet kop- en voetteksten uit.</span></p>
    <div class="row"><button class="btn ghost" type="button" onclick="history.back()">Terug</button><button class="btn primary" onclick="window.print()">🖨 Printen</button></div>
  </div>
</section>
<style>
  @media print { @page { size: A4; margin: <?= $fTop ?>mm 0 0 0; } }
</style>
<?php foreach (array_chunk($labels, $fCols * $fRows) as $vel): ?>
<div class="labelsheet f-<?= e($formaat) ?>" style="--cols:<?= $fCols ?>;--w:<?= $fW ?>mm;--h:<?= $fH ?>mm;--qr:<?= $fQr ?>mm">
  <?php foreach ($vel as $l): ?>
    <div class="label <?= !empty($l['project']) ? 'proj' : '' ?>">
      <div class="qr" data-qr="<?= e($l['qr']) ?>" data-px="<?= (int)round($fQr * 6) ?>"></div>
      <div class="txt"><b><?= e($l['titel']) ?></b><span><?= e($l['sub']) ?></span></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php page_foot(['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js']); exit; endif; ?>

<section class="card">
  <div class="row between wrap"><h1>QR-labels</h1><a class="btn" href="poster.php">📄 A4-poster voor het magazijn</a></div>
  <p class="muted">Plak een label op de bak of stelling. Monteurs scannen het met de app of gewoon met de camera van hun telefoon. Producten met een fabrieksbarcode (EAN) kun je ook direct op de verpakking scannen.</p>
</section>

<form method="post" action="labels.php" class="card" id="labelForm">
  <input type="hidden" name="print" value="1">
  <h2>Formaat</h2>
  <div class="formaten">
    <?php foreach (FORMATEN as $k => $fm): ?>
      <label class="check"><input type="radio" name="formaat" value="<?= e($k) ?>" <?= $k === $formaat ? 'checked' : '' ?>> <?= e($fm[0]) ?></label>
    <?php endforeach; ?>
  </div>

  <h2>Producten</h2>
  <p class="small muted">Vink hele groepen aan (bijvoorbeeld Halyester én EMS/Meters/Data) of losse producten. De filters verbergen alleen; wat je al aanvinkte blijft aangevinkt.</p>
  <div class="row wrap filters">
    <input type="search" id="lblZoek" class="grow" placeholder="Zoek in producten…" autocomplete="off">
    <select id="lblFilter">
      <option value="">Alle producten</option>
      <option value="op">Alleen op voorraad</option>
      <option value="zonderean">Alleen zonder EAN</option>
    </select>
    <button type="button" class="btn small ghost" id="lblGeen">Niets selecteren</button>
  </div>

  <div class="groepchips">
    <?php foreach ($perGroep as $g => $rows): ?>
      <label class="chip"><input type="checkbox" data-groepkies="<?= e($g) ?>"> <?= e($g) ?> <span class="muted">(<?= count($rows) ?>)</span></label>
    <?php endforeach; ?>
  </div>

  <div class="checklist groepen">
    <?php foreach ($perGroep as $g => $rows): ?>
      <details class="lblgroep" data-groep="<?= e($g) ?>">
        <summary><b><?= e($g) ?></b> <span class="muted small" data-telling>0 van <?= count($rows) ?></span></summary>
        <?php foreach ($rows as $p): ?>
          <label class="check lblrij" data-op="<?= (float)$p['voorraad'] > 0 ? 1 : 0 ?>" data-ean="<?= $p['ean'] ? 1 : 0 ?>" data-tekst="<?= e(mb_strtolower($p['naam'] . ' ' . $p['code'] . ' ' . $p['ean'])) ?>">
            <input type="checkbox" name="id[]" value="<?= (int)$p['id'] ?>" class="pcheck"> <?= e($p['naam']) ?> <span class="muted small"><?= e($p['code']) ?></span></label>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
  </div>

  <h2>Projectlabels</h2>
  <label>Projectnummers (één per regel) <textarea name="projecten" rows="3" placeholder="PRJ-296"></textarea></label>
  <div class="row between wrap mt">
    <span id="lblTeller" class="muted">0 labels geselecteerd</span>
    <div class="row wrap">
      <button class="btn big" name="export" value="ptouch" title="Lijst voor Brother P-touch Editor (databasekoppeling)">⬇ Voor Brother P-touch</button>
      <button class="btn big" name="labelprinter" value="1" title="Rechtstreeks printen op de Brother PT-P710BT via USB (Chrome of Edge)">🏷 Labelprinter</button>
      <button class="btn primary big">Labels maken (A4)</button>
    </div>
  </div>
</form>
<?php page_foot();
