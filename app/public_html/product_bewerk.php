<?php
// Product aanmaken (iedereen) of wijzigen (beheerder; monteur alleen locatie/EAN aanvullen).
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p = $id ? q('SELECT * FROM producten WHERE id = ?', [$id])->fetch() : null;
if ($id && !$p) { flash('Product niet gevonden.', 'err'); redirect('index.php'); }
$nieuwProduct = !$p;
$volledig = $nieuwProduct || is_admin();

$fout = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $d = [
        'naam' => clean_text($_POST['naam'] ?? ''),
        'groep' => clean_text($_POST['groep'] ?? ''),
        'ean' => clean_ean($_POST['ean'] ?? ''),
        'eenheid' => clean_text($_POST['eenheid'] ?? 'stuks') ?: 'stuks',
        'prijs' => num($_POST['prijs'] ?? null),
        'minimum' => num($_POST['minimum'] ?? null),
        'locatie' => clean_text($_POST['locatie'] ?? ''),
        'leverancier' => clean_text($_POST['leverancier'] ?? ''),
        'leverancier_code' => strtoupper(preg_replace('/\s+/', '', (string)($_POST['leverancier_code'] ?? ''))) ?: null,
        'actief' => isset($_POST['actief']) ? 1 : 0,
    ];
    if ($volledig && $d['naam'] === '') $fout = 'Naam is verplicht.';
    if (!$fout) {
        $ean = $d['ean'] === '' ? null : $d['ean'];
        if ($nieuwProduct) {
            $pdo = db();
            $pdo->beginTransaction();
            q('INSERT INTO producten (naam, groep, ean, eenheid, prijs, minimum, locatie, leverancier, leverancier_code, voorraad) VALUES (?,?,?,?,?,?,?,?,?,0)',
                [$d['naam'], $d['groep'], $ean, $d['eenheid'], $d['prijs'], $d['minimum'], $d['locatie'], $d['leverancier'], $d['leverancier_code']]);
            $id = (int)$pdo->lastInsertId();
            q('UPDATE producten SET code = ? WHERE id = ?', [sprintf('WW%04d', $id), $id]);
            $start = num($_POST['voorraad'] ?? null) ?? 0;
            if ($start != 0) {
                q('UPDATE producten SET voorraad = ? WHERE id = ?', [$start, $id]);
                q('INSERT INTO mutaties (product_id, gebruiker_id, type, aantal, voorraad_na, prijs, opmerking) VALUES (?,?,?,?,?,?,?)',
                    [$id, $u['id'], 'ontvangst', $start, $start, $d['prijs'], 'Startvoorraad bij aanmaken']);
            }
            $pdo->commit();
            flash('Product aangemaakt.');
        } elseif (is_admin()) {
            q('UPDATE producten SET naam=?, groep=?, ean=?, eenheid=?, prijs=?, minimum=?, locatie=?, actief=?, leverancier=?, leverancier_code=? WHERE id=?',
                [$d['naam'], $d['groep'], $ean, $d['eenheid'], $d['prijs'], $d['minimum'], $d['locatie'], $d['actief'], $d['leverancier'], $d['leverancier_code'], $id]);
            flash('Opgeslagen.');
        } else {
            // Monteur: mag locatie en (lege) EAN aanvullen
            q('UPDATE producten SET locatie = ?, ean = COALESCE(NULLIF(ean, \'\'), ?) WHERE id = ?', [$d['locatie'], $ean, $id]);
            flash('Opgeslagen.');
        }
        redirect('product.php?id=' . $id);
    }
}

$v = $p ?: [
    'naam' => clean_text($_GET['naam'] ?? ''), 'groep' => '', 'ean' => clean_ean($_GET['ean'] ?? ''),
    'eenheid' => 'stuks', 'prijs' => null, 'minimum' => null, 'locatie' => '', 'actief' => 1, 'leverancier' => '', 'leverancier_code' => '',
];
$groepen = q("SELECT DISTINCT groep FROM producten WHERE groep <> '' ORDER BY groep")->fetchAll(PDO::FETCH_COLUMN);
$dis = $volledig ? '' : 'disabled';

page_head($nieuwProduct ? 'Nieuw product' : 'Product wijzigen', 'voorraad');
?>
<section class="card narrow">
  <h1><?= $nieuwProduct ? 'Nieuw product' : 'Wijzig ' . e($p['code']) ?></h1>
  <?php if (!$nieuwProduct): ?><p class="muted small">QR-code en labelcode <b><?= e($p['code']) ?></b> blijven altijd hetzelfde. Naam, groep, EAN, locatie en de rest kun je wijzigen; geprinte labels blijven werken.</p><?php endif; ?>
  <?php if (!$volledig): ?><p class="muted small">Je kunt de locatie aanpassen en een ontbrekende EAN invullen. Andere gegevens wijzigt kantoor.</p><?php endif; ?>
  <?php if ($fout): ?><div class="flash err"><?= e($fout) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <label>Naam <input name="naam" value="<?= e($v['naam']) ?>" required <?= $dis ?>></label>
    <label>Productgroep <input name="groep" list="groepen" value="<?= e($v['groep']) ?>" <?= $dis ?>>
      <datalist id="groepen"><?php foreach ($groepen as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist></label>
    <label>EAN / barcode
      <div class="row"><input class="grow" name="ean" id="eanVeld" inputmode="numeric" value="<?= e($v['ean']) ?>" <?= ($volledig || !$v['ean']) ? '' : 'disabled' ?>>
      <button type="button" class="btn" id="scanEan">📷</button></div></label>
    <div id="reader" hidden></div>
    <div class="row">
      <label class="grow">Eenheid
        <select name="eenheid" <?= $dis ?>>
          <?php foreach (['stuks', 'meter', 'rol', 'doos', 'set'] as $o): ?><option <?= $v['eenheid'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
        </select></label>
      <label class="grow">Prijs per eenheid (€) <input name="prijs" inputmode="decimal" value="<?= $v['prijs'] !== null ? e(str_replace('.', ',', (string)$v['prijs'])) : '' ?>" <?= $dis ?>></label>
    </div>
    <div class="row">
      <label class="grow">Leverancier <input name="leverancier" value="<?= e($v['leverancier'] ?? '') ?>" placeholder="Rexel" <?= $dis ?>></label>
      <label class="grow">Artikelnummer leverancier <input name="leverancier_code" value="<?= e($v['leverancier_code'] ?? '') ?>" <?= $dis ?>></label>
    </div>
    <div class="row">
      <label class="grow">Locatie (stelling/bak) <input name="locatie" value="<?= e($v['locatie']) ?>"></label>
      <label class="grow">Minimumvoorraad <input name="minimum" inputmode="decimal" value="<?= $v['minimum'] !== null ? e(fmt_n($v['minimum'])) : '' ?>" <?= $dis ?>></label>
    </div>
    <?php if ($nieuwProduct): ?>
      <label>Huidige voorraad <input name="voorraad" inputmode="decimal" value="0"></label>
    <?php elseif (is_admin()): ?>
      <label class="check"><input type="checkbox" name="actief" <?= $v['actief'] ? 'checked' : '' ?>> Actief (uitvinken = verbergen uit lijsten)</label>
    <?php endif; ?>
    <button class="btn primary big">Opslaan</button>
  </form>
</section>
<?php page_foot(['https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js']);
