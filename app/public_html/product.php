<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$p = q('SELECT * FROM producten WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$p) { flash('Product niet gevonden.', 'err'); redirect('index.php'); }

$hist = q("SELECT m.*, g.naam AS wie, pr.nummer FROM mutaties m
           LEFT JOIN gebruikers g ON g.id = m.gebruiker_id
           LEFT JOIN projecten pr ON pr.id = m.project_id
           WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 25", [$p['id']])->fetchAll();
$projecten = q('SELECT nummer FROM projecten WHERE afgerond = 0 ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
$reserveringen = q('SELECT pj.nummer, SUM(m.gereserveerd) r FROM materiaal m JOIN projecten pj ON pj.id = m.project_id
                    WHERE m.product_id = ? AND pj.afgerond = 0 AND m.gereserveerd > 0 GROUP BY pj.id ORDER BY pj.nummer', [$p['id']])->fetchAll();
$actief = $_SESSION['project'] ?? '';
$stap = (floor((float)$p['voorraad']) == (float)$p['voorraad'] && $p['eenheid'] === 'stuks') ? '1' : '0.01';

page_head($p['naam'], 'scan');
?>
<section class="card product-head">
  <div class="muted small"><?= e($p['groep']) ?> · <?= e($p['code']) ?><?= $p['ean'] ? ' · EAN ' . e($p['ean']) : '' ?><?= !empty($p['leverancier_code']) ? ' · Rexel ' . e($p['leverancier_code']) : '' ?><?= !$p['actief'] ? ' · <b>inactief</b>' : '' ?></div>
  <h1><?= e($p['naam']) ?></h1>
  <div class="bigstock <?= $p['voorraad'] <= 0 ? 'zero' : (($p['minimum'] !== null && $p['voorraad'] < $p['minimum']) ? 'low' : '') ?>">
    <span><?= fmt_n($p['voorraad']) ?></span> <small><?= e($p['eenheid']) ?> op voorraad</small>
  </div>
  <div class="muted small">
    <?= !is_admin() ? '' : ($p['prijs'] !== null ? fmt_eur($p['prijs']) . ' per ' . e(rtrim($p['eenheid'], 's') ?: 'stuk') : 'Geen prijs') ?>
    <?= $p['locatie'] ? ' · Locatie: ' . e($p['locatie']) : '' ?>
    <?= $p['minimum'] !== null ? ' · Minimum: ' . fmt_n($p['minimum']) : '' ?>
  </div>
  <?php if ($reserveringen): ?>
    <div class="small mt">Klaargezet voor: <?php foreach ($reserveringen as $i => $rv): ?><?= $i ? ', ' : '' ?><a href="project.php?nr=<?= e(rawurlencode($rv['nummer'])) ?>"><b><?= e($rv['nummer']) ?></b></a> (<?= fmt_n($rv['r']) ?>)<?php endforeach; ?>
      · vrij: <b><?= fmt_n((float)$p['voorraad'] - array_sum(array_column($reserveringen, 'r'))) ?></b></div>
  <?php endif; ?>
</section>

<section class="card">
  <div class="tabs" role="tablist">
    <button class="tab on" data-tab="uit">Pakken</button>
    <button class="tab" data-tab="retour">Terugbrengen</button>
    <button class="tab" data-tab="ontvangst">Ontvangen</button>
    <?php if (is_admin()): ?><button class="tab" data-tab="telling">Tellen</button><?php endif; ?>
  </div>

  <form method="post" action="boek.php" class="stack boekform">
    <?= csrf_field() ?>
    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
    <input type="hidden" name="type" value="uit" id="typeVeld">

    <label><span id="aantalLabel">Aantal</span>
      <div class="qty">
        <button type="button" class="btn" data-step="-1">−</button>
        <input name="aantal" id="aantal" type="number" inputmode="decimal" step="<?= $stap ?>" min="0" value="1" required>
        <button type="button" class="btn" data-step="1">+</button>
      </div>
    </label>

    <label id="projectVeld">Project
      <input name="project" list="pl" value="<?= e($actief) ?>" placeholder="Projectnummer" id="projectInput">
      <datalist id="pl"><?php foreach ($projecten as $nr): ?><option value="<?= e($nr) ?>"><?php endforeach; ?></datalist>
    </label>

    <label>Opmerking <input name="opmerking" maxlength="255" placeholder="Optioneel"></label>
    <button class="btn primary big" id="boekKnop">Pakken</button>
  </form>
</section>

<section class="card">
  <div class="row between">
    <h2>Historie</h2>
    <a class="btn ghost small" href="product_bewerk.php?id=<?= (int)$p['id'] ?>">Gegevens wijzigen</a>
  </div>
  <?php if (!$hist): ?><p class="muted">Nog geen boekingen.</p><?php else: ?>
  <div class="tablewrap"><table>
    <thead><tr><th>Wanneer</th><th>Wat</th><th class="r">Aantal</th><th class="r">Na</th><th>Project</th><th>Wie</th></tr></thead>
    <tbody>
    <?php foreach ($hist as $m): ?>
      <tr class="<?= $m['ongedaan_van'] ? 'muted' : '' ?>">
        <td><?= e(fmt_dt($m['aangemaakt'])) ?></td>
        <td><span class="tag t-<?= e($m['type']) ?>"><?= e(TYPES[$m['type']]) ?></span><?= $m['opmerking'] ? '<br><span class="small muted">' . e($m['opmerking']) . '</span>' : '' ?></td>
        <td class="r"><?= ($m['aantal'] > 0 ? '+' : '') . fmt_n($m['aantal']) ?></td>
        <td class="r"><?= fmt_n($m['voorraad_na']) ?></td>
        <td><?= $m['nummer'] ? '<a href="project.php?nr=' . e(rawurlencode($m['nummer'])) . '">' . e($m['nummer']) . '</a>' : '' ?></td>
        <td><?= e($m['wie'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php page_foot();
