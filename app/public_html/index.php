<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

// Actief project kiezen / wissen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nr = project_nummer($_POST['project'] ?? '');
    if ($nr === '') { unset($_SESSION['project']); }
    else { project_id($nr); $_SESSION['project'] = $nr; }
    redirect('index.php');
}

$zoek = clean_text($_GET['q'] ?? '');
$resultaten = [];
if ($zoek !== '') {
    $woorden = array_filter(explode(' ', $zoek));
    $where = []; $params = [];
    foreach ($woorden as $w) {
        $where[] = '(naam LIKE ? OR groep LIKE ? OR code LIKE ? OR ean LIKE ? OR locatie LIKE ?)';
        array_push($params, "%$w%", "%$w%", "%$w%", "%$w%", "%$w%");
    }
    $resultaten = q('SELECT * FROM producten WHERE actief = 1 AND ' . implode(' AND ', $where) . ' ORDER BY groep, naam LIMIT 100', $params)->fetchAll();
}

$recent = q("SELECT m.*, p.naam, p.eenheid, pr.nummer FROM mutaties m JOIN producten p ON p.id = m.product_id
             LEFT JOIN projecten pr ON pr.id = m.project_id
             WHERE m.gebruiker_id = ? ORDER BY m.id DESC LIMIT 8", [$u['id']])->fetchAll();
$projecten = q('SELECT nummer, omschrijving FROM projecten WHERE afgerond = 0 ORDER BY id DESC LIMIT 200')->fetchAll();
$actief = $_SESSION['project'] ?? '';
$klaar = [];
if ($actief !== '') {
    $klaar = q("SELECT m.product_id, p.naam, p.code, p.locatie, m.gereserveerd FROM materiaal m JOIN projecten pj ON pj.id = m.project_id
                JOIN producten p ON p.id = m.product_id WHERE pj.nummer = ? AND m.gereserveerd > 0 ORDER BY p.locatie, p.naam", [$actief])->fetchAll();
}

page_head('Scan', 'scan');
?>
<section class="card project-bar">
  <form method="post" class="row">
    <?= csrf_field() ?>
    <label class="grow">Ik werk aan project
      <input name="project" list="projectlijst" value="<?= e($actief) ?>" placeholder="Projectnummer (of scan projectlabel)">
    </label>
    <button class="btn"><?= $actief ? 'Wijzig' : 'Zet' ?></button>
    <?php if ($actief): ?><button class="btn ghost" name="project" value="">✕</button><?php endif; ?>
  </form>
  <datalist id="projectlijst">
    <?php foreach ($projecten as $p): ?><option value="<?= e($p['nummer']) ?>"><?= e($p['omschrijving']) ?></option><?php endforeach; ?>
  </datalist>
</section>

<section class="card scan-card">
  <button class="btn primary huge" id="scanKnop" type="button">📷 Scan barcode of QR</button>
  <div id="reader" hidden></div>
  <button class="btn ghost" id="stopScan" type="button" hidden>Stop camera</button>
  <form action="lookup.php" method="get" class="row" id="zoekForm">
    <input class="grow" name="q" value="<?= e($zoek) ?>" placeholder="Of typ naam, code of EAN…" autocomplete="off" enterkeyhint="search">
    <button class="btn">Zoek</button>
  </form>
</section>

<?php if ($klaar): ?>
<section class="card">
  <h2>Klaargezet voor <?= e($actief) ?> · nog pakken</h2>
  <ul class="list compact picklist">
    <?php foreach ($klaar as $k): ?>
      <li><a href="product.php?id=<?= (int)$k['product_id'] ?>">
        <span class="grow"><?= e($k['naam']) ?><br><span class="muted small"><?= e($k['code']) ?><?= $k['locatie'] ? ' · ' . e($k['locatie']) : '' ?></span></span>
        <span class="stock"><?= fmt_n($k['gereserveerd']) ?></span>
      </a></li>
    <?php endforeach; ?>
  </ul>
  <p class="small muted"><a href="project.php?nr=<?= e(rawurlencode($actief)) ?>">Hele materiaallijst bekijken</a></p>
</section>
<?php endif; ?>

<?php if ($zoek !== ''): ?>
<section class="card">
  <h2><?= count($resultaten) ?> <?= count($resultaten) === 1 ? 'resultaat' : 'resultaten' ?> voor “<?= e($zoek) ?>”</h2>
  <?php if (!$resultaten): ?>
    <p>Niets gevonden.</p>
    <a class="btn" href="product_bewerk.php?<?= ctype_digit($zoek) && strlen($zoek) >= 8 ? 'ean=' : 'naam=' ?><?= e(rawurlencode($zoek)) ?>">+ Nieuw product aanmaken</a>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($resultaten as $p): ?>
        <li><a href="product.php?id=<?= (int)$p['id'] ?>">
          <span class="grow"><strong><?= e($p['naam']) ?></strong><br><span class="muted small"><?= e($p['groep']) ?> · <?= e($p['code']) ?><?= $p['locatie'] ? ' · ' . e($p['locatie']) : '' ?></span></span>
          <span class="stock <?= $p['voorraad'] <= 0 ? 'zero' : '' ?>"><?= fmt_n($p['voorraad']) ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($recent): ?>
<section class="card">
  <h2>Mijn laatste boekingen</h2>
  <ul class="list compact">
    <?php foreach ($recent as $m): ?>
      <li><a href="product.php?id=<?= (int)$m['product_id'] ?>">
        <span class="tag t-<?= e($m['type']) ?>"><?= e(TYPES[$m['type']]) ?></span>
        <span class="grow"><?= fmt_n(abs((float)$m['aantal'])) ?> × <?= e($m['naam']) ?><?= $m['nummer'] ? ' → <b>' . e($m['nummer']) . '</b>' : '' ?></span>
        <span class="muted small"><?= e(fmt_dt($m['aangemaakt'])) ?></span>
      </a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php page_foot(['https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js']);
