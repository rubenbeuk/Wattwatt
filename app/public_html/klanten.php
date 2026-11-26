<?php
// Klanten: één kaart per klant met contactgegevens, installatie en alle projecten en tickets.
require __DIR__ . '/inc/bootstrap.php';
require_admin();

$zoek = clean_text($_GET['q'] ?? '');
$par = [];
$w = '';
if ($zoek !== '') {
    $w = 'WHERE k.naam LIKE ? OR k.bedrijf LIKE ? OR k.email LIKE ? OR k.adres LIKE ? OR k.telefoon_kaal LIKE ?';
    $par = array_fill(0, 4, '%' . $zoek . '%'); $par[] = '%' . (telefoon_kaal($zoek) ?: $zoek) . '%';
}
$rows = q("SELECT k.*, COUNT(p.id) n, SUM(p.soort = 'service' AND p.fase <> 'opgelost') open_tickets, SUM(p.soort = 'project') projecten, MAX(p.aangemaakt) laatst
           FROM klanten k LEFT JOIN projecten p ON p.klant_id = k.id $w GROUP BY k.id ORDER BY MAX(p.aangemaakt) IS NULL, MAX(p.aangemaakt) DESC, k.naam LIMIT 300", $par)->fetchAll();

page_head('Klanten', 'klanten');
?>
<section class="card">
  <div class="row between wrap">
    <h1>Klanten</h1>
    <form class="row" method="get"><input type="search" name="q" value="<?= e($zoek) ?>" placeholder="Zoek op naam, telefoon, e-mail of adres"><button class="btn">Zoek</button></form>
  </div>
  <table>
    <thead><tr><th>Klant</th><th>Contact</th><th>Adres</th><th>Projecten</th><th>Open tickets</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $k): ?>
      <tr>
        <td><a href="klant.php?id=<?= (int)$k['id'] ?>"><b><?= e($k['naam']) ?></b></a><?= $k['bedrijf'] !== '' ? '<br><span class="small muted">' . e($k['bedrijf']) . '</span>' : '' ?></td>
        <td class="small"><?= $k['telefoon'] !== '' ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $k['telefoon'])) . '">' . e($k['telefoon']) . '</a><br>' : '' ?><?= e($k['email']) ?></td>
        <td class="small"><?= e($k['adres']) ?></td>
        <td><?= (int)$k['projecten'] ?: '' ?></td>
        <td class="<?= $k['open_tickets'] ? 'oranje' : '' ?>"><?= (int)$k['open_tickets'] ?: '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Geen klanten gevonden.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php page_foot();
