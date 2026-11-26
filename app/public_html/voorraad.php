<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$groep = clean_text($_GET['groep'] ?? '');
$filter = (string)($_GET['f'] ?? '');
$zoek = clean_text($_GET['q'] ?? '');

$where = ['actief = 1']; $params = [];
if ($groep !== '') { $where[] = 'groep = ?'; $params[] = $groep; }
if ($filter === 'op') $where[] = 'voorraad > 0';
if ($filter === 'laag') $where[] = 'minimum IS NOT NULL AND voorraad < minimum';
if ($filter === 'neg') $where[] = 'voorraad < 0';
if ($filter === 'geenprijs') $where[] = 'prijs IS NULL';
if ($zoek !== '') { $where[] = '(naam LIKE ? OR code LIKE ? OR ean LIKE ? OR leverancier_code LIKE ?)'; array_push($params, "%$zoek%", "%$zoek%", "%$zoek%", "%$zoek%"); }

$rows = q('SELECT pr.*, COALESCE((SELECT SUM(m.gereserveerd) FROM materiaal m JOIN projecten pj ON pj.id = m.project_id WHERE m.product_id = pr.id AND pj.afgerond = 0), 0) AS gereserveerd
            FROM producten pr WHERE ' . implode(' AND ', $where) . ' ORDER BY groep, naam', $params)->fetchAll();
$groepen = q("SELECT groep, COUNT(*) n, SUM(CASE WHEN voorraad > 0 THEN voorraad * COALESCE(prijs,0) ELSE 0 END) w
              FROM producten WHERE actief = 1 GROUP BY groep ORDER BY groep")->fetchAll();
$totaal = array_sum(array_map(fn($r) => max(0, (float)$r['voorraad']) * (float)$r['prijs'], $rows));

// Export in hetzelfde format als het tabblad "Bewerkt"
$export = array_map(fn($r) => [
    'Product_omschrijving' => $r['naam'], 'Productgroep' => $r['groep'], 'EAN nummer' => $r['ean'] ?? '',
    'Aantal stuks' => (float)$r['voorraad'], 'Klaargezet' => (float)$r['gereserveerd'], 'Rexel-code' => $r['leverancier_code'] ?? '', 'Waarde per stuk' => $r['prijs'] !== null ? (float)$r['prijs'] : null,
    'Totale waarde' => round((float)$r['voorraad'] * (float)$r['prijs'], 2),
    'Code' => $r['code'], 'Eenheid' => $r['eenheid'], 'Locatie' => $r['locatie'], 'Minimum' => $r['minimum'] !== null ? (float)$r['minimum'] : null,
], $rows);
if (!is_admin()) $export = array_map(function ($r) { unset($r['Waarde per stuk'], $r['Totale waarde']); return $r; }, $export);

page_head('Voorraad', 'voorraad');
?>
<section class="card">
  <div class="row between wrap">
    <h1>Voorraad</h1>
    <div class="row">
      <a class="btn" href="product_bewerk.php">+ Product</a>
      <button class="btn" id="exportKnop" type="button">⬇ Excel</button>
    </div>
  </div>
  <form class="row wrap filters" method="get">
    <input name="q" value="<?= e($zoek) ?>" placeholder="Zoek…" class="grow">
    <select name="groep" onchange="this.form.submit()">
      <option value="">Alle groepen</option>
      <?php foreach ($groepen as $g): ?><option value="<?= e($g['groep']) ?>" <?= $g['groep'] === $groep ? 'selected' : '' ?>><?= e($g['groep'] ?: '(geen groep)') ?> (<?= (int)$g['n'] ?>)</option><?php endforeach; ?>
    </select>
    <select name="f" onchange="this.form.submit()">
      <?php foreach (['' => 'Alles', 'op' => 'Op voorraad', 'laag' => 'Onder minimum', 'neg' => 'Negatief', 'geenprijs' => 'Zonder prijs'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $k === $filter ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Filter</button>
  </form>
  <p class="muted"><?= count($rows) ?> producten<?= is_admin() ? ' · waarde <b>' . fmt_eur($totaal) . '</b>' : '' ?></p>
  <div class="tablewrap"><table class="stocktable">
    <thead><tr><th>Product</th><th>Groep</th><th class="r">Voorraad</th><th class="r">Klaargezet</th><?php if (is_admin()): ?><th class="r">Prijs</th><th class="r">Waarde</th><?php endif; ?><th>Locatie</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['voorraad'] < 0 ? 'neg' : ($r['voorraad'] == 0 ? 'nul' : '') ?>">
        <td><a href="product.php?id=<?= (int)$r['id'] ?>"><?= e($r['naam']) ?></a><br><span class="muted small"><?= e($r['code']) ?><?= $r['ean'] ? ' · ' . e($r['ean']) : '' ?></span></td>
        <td><?= e($r['groep']) ?></td>
        <td class="r"><b><?= fmt_n($r['voorraad']) ?></b><?= $r['eenheid'] !== 'stuks' ? ' <span class="small muted">' . e($r['eenheid']) . '</span>' : '' ?></td>
        <td class="r"><?= $r['gereserveerd'] > 0 ? fmt_n($r['gereserveerd']) . '<br><span class="small muted">vrij ' . fmt_n((float)$r['voorraad'] - (float)$r['gereserveerd']) . '</span>' : '' ?></td>
        <?php if (is_admin()): ?><td class="r"><?= $r['prijs'] !== null ? fmt_eur($r['prijs']) : '–' ?></td>
        <td class="r"><?= $r['prijs'] !== null ? fmt_eur(max(0, (float)$r['voorraad']) * (float)$r['prijs']) : '–' ?></td><?php endif; ?>
        <td><?= e($r['locatie']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php if (is_admin()): ?>
<section class="card">
  <h2>Waarde per groep</h2>
  <div class="tablewrap"><table>
    <thead><tr><th>Groep</th><th class="r">Producten</th><th class="r">Waarde</th></tr></thead>
    <tbody>
      <?php foreach ($groepen as $g): ?><tr><td><a href="?groep=<?= e(rawurlencode($g['groep'])) ?>"><?= e($g['groep'] ?: '(geen groep)') ?></a></td><td class="r"><?= (int)$g['n'] ?></td><td class="r"><?= fmt_eur($g['w']) ?></td></tr><?php endforeach; ?>
      <tr class="tot"><td>Totaal</td><td class="r"><?= array_sum(array_column($groepen, 'n')) ?></td><td class="r"><?= fmt_eur(array_sum(array_column($groepen, 'w'))) ?></td></tr>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<script id="exportData" type="application/json"><?= json_encode($export, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php page_foot(['https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js']);
