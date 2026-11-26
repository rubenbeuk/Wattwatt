<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$type = (string)($_GET['type'] ?? '');
$where = ['1=1']; $params = [];
if (isset(TYPES[$type])) { $where[] = 'm.type = ?'; $params[] = $type; }
if (!empty($_GET['gebruiker'])) { $where[] = 'm.gebruiker_id = ?'; $params[] = (int)$_GET['gebruiker']; }

$rows = q('SELECT m.*, p.naam, p.code, g.naam wie, pr.nummer FROM mutaties m
           JOIN producten p ON p.id = m.product_id
           LEFT JOIN gebruikers g ON g.id = m.gebruiker_id
           LEFT JOIN projecten pr ON pr.id = m.project_id
           WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT 300', $params)->fetchAll();
$gebruikers = q('SELECT id, naam FROM gebruikers ORDER BY naam')->fetchAll();

page_head('Historie', 'mutaties');
?>
<section class="card">
  <h1>Historie</h1>
  <form class="row wrap filters" method="get">
    <select name="type" onchange="this.form.submit()"><option value="">Alle soorten</option>
      <?php foreach (TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $k === $type ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select name="gebruiker" onchange="this.form.submit()"><option value="">Iedereen</option>
      <?php foreach ($gebruikers as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)($_GET['gebruiker'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['naam']) ?></option><?php endforeach; ?></select>
  </form>
  <p class="muted small">Laatste 300 boekingen.</p>
  <div class="tablewrap"><table>
    <thead><tr><th>Wanneer</th><th>Wat</th><th>Product</th><th class="r">Aantal</th><th class="r">Na</th><th>Project</th><th>Wie</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): ?>
      <tr>
        <td><?= e(fmt_dt($m['aangemaakt'])) ?></td>
        <td><span class="tag t-<?= e($m['type']) ?>"><?= e(TYPES[$m['type']]) ?></span></td>
        <td><a href="product.php?id=<?= (int)$m['product_id'] ?>"><?= e($m['naam']) ?></a><?= $m['opmerking'] ? '<br><span class="small muted">' . e($m['opmerking']) . '</span>' : '' ?></td>
        <td class="r"><?= ($m['aantal'] > 0 ? '+' : '') . fmt_n($m['aantal']) ?></td>
        <td class="r"><?= fmt_n($m['voorraad_na']) ?></td>
        <td><?= $m['nummer'] ? '<a href="project.php?nr=' . e(rawurlencode($m['nummer'])) . '">' . e($m['nummer']) . '</a>' : '' ?></td>
        <td><?= e($m['wie'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php page_foot();
