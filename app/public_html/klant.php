<?php
// Klantkaart: gegevens, installatie (wat er staat) en alle projecten, tickets en afspraken van deze klant.
require __DIR__ . '/inc/bootstrap.php';
$u = require_admin();
$k = q('SELECT * FROM klanten WHERE id = ?', [(int)($_GET['id'] ?? $_POST['id'] ?? 0)])->fetch();
if (!$k) { flash('Klant niet gevonden.', 'err'); redirect('klanten.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['actie'] ?? '') === 'opslaan') {
        $tel = mb_substr(clean_text($_POST['telefoon'] ?? ''), 0, 50);
        q('UPDATE klanten SET naam = ?, bedrijf = ?, telefoon = ?, telefoon_kaal = ?, email = ?, adres = ?, installatie = ?, notitie = ? WHERE id = ?', [
            mb_substr(clean_text($_POST['naam'] ?? ''), 0, 150) ?: $k['naam'], mb_substr(clean_text($_POST['bedrijf'] ?? ''), 0, 150), $tel, telefoon_kaal($tel),
            mb_substr(mb_strtolower(clean_text($_POST['email'] ?? '')), 0, 150), mb_substr(clean_text($_POST['adres'] ?? ''), 0, 255),
            trim((string)($_POST['installatie'] ?? '')) ?: null, trim((string)($_POST['notitie'] ?? '')) ?: null, $k['id']]);
        flash('Klant opgeslagen.');
    } elseif (($_POST['actie'] ?? '') === 'samenvoegen') {
        // Dubbele klant: alles van de andere kaart naar deze, daarna de andere weg
        $ander = (int)($_POST['ander_id'] ?? 0);
        if ($ander && $ander !== (int)$k['id'] && q('SELECT 1 FROM klanten WHERE id = ?', [$ander])->fetch()) {
            q('UPDATE projecten SET klant_id = ? WHERE klant_id = ?', [$k['id'], $ander]);
            q('DELETE FROM klanten WHERE id = ?', [$ander]);
            flash('Klanten samengevoegd.');
        }
    }
    redirect('klant.php?id=' . (int)$k['id']);
}

$projecten = q("SELECT p.*, g.naam AS eigenaar FROM projecten p LEFT JOIN gebruikers g ON g.id = p.leider_id WHERE p.klant_id = ? ORDER BY p.afgerond, p.id DESC", [$k['id']])->fetchAll();
$ids = array_map(fn($p) => (int)$p['id'], $projecten) ?: [0];
$in = implode(',', $ids);
$afspraken = q("SELECT a.*, g.naam FROM afspraken a LEFT JOIN gebruikers g ON g.id = a.gebruiker_id WHERE a.project_id IN ($in) AND a.geannuleerd = 0 AND a.start >= CURDATE() ORDER BY a.start LIMIT 20")->fetchAll();
$berichten = q("SELECT b.*, p.nummer FROM project_berichten b JOIN projecten p ON p.id = b.project_id WHERE b.project_id IN ($in) ORDER BY b.datum DESC LIMIT 15")->fetchAll();
$vergelijkbaar = q("SELECT id, naam, telefoon, adres FROM klanten WHERE id <> ? AND (LOWER(naam) LIKE LOWER(?) OR (telefoon_kaal <> '' AND telefoon_kaal = ?)) LIMIT 5",
    [$k['id'], '%' . explode(' ', trim($k['naam']))[count(explode(' ', trim($k['naam']))) - 1] . '%', $k['telefoon_kaal']])->fetchAll();

page_head('Klant ' . $k['naam'], 'klanten');
?>
<div class="playout">
<aside class="pzij">
  <section class="card">
    <div class="small muted"><a href="klanten.php">Klanten</a></div>
    <h1><?= e($k['naam']) ?></h1>
    <?php if ($k['bedrijf'] !== ''): ?><p class="muted"><?= e($k['bedrijf']) ?></p><?php endif; ?>
    <dl class="pdetails">
      <dt>Telefoon</dt><dd><?= $k['telefoon'] !== '' ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $k['telefoon'])) . '">' . e($k['telefoon']) . '</a>' : '–' ?></dd>
      <dt>E-mail</dt><dd><?= $k['email'] !== '' ? '<a href="mailto:' . e($k['email']) . '">' . e($k['email']) . '</a>' : '–' ?></dd>
      <dt>Adres</dt><dd><?= $k['adres'] !== '' ? e($k['adres']) . '<br><a href="' . e(route_url($k['adres'])) . '" target="_blank" rel="noopener">Route</a>' : '–' ?></dd>
      <?php if ($k['pipedrive_person_id']): ?><dt>Pipedrive</dt><dd><a href="https://wattwatt.pipedrive.com/person/<?= (int)$k['pipedrive_person_id'] ?>" target="_blank" rel="noopener">Contact openen</a></dd><?php endif; ?>
    </dl>
    <details class="mt"><summary>Bewerken</summary>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="actie" value="opslaan">
        <label>Naam <input name="naam" value="<?= e($k['naam']) ?>"></label>
        <label>Bedrijf <input name="bedrijf" value="<?= e($k['bedrijf']) ?>"></label>
        <label>Telefoon <input name="telefoon" value="<?= e($k['telefoon']) ?>"></label>
        <label>E-mail <input type="email" name="email" value="<?= e($k['email']) ?>"></label>
        <label>Adres <input name="adres" value="<?= e($k['adres']) ?>"></label>
        <label>Installatie (wat staat er: merk, type, serienummers, EMS, monitoring) <textarea name="installatie" rows="5"><?= e($k['installatie'] ?? '') ?></textarea></label>
        <label>Notitie <textarea name="notitie" rows="3"><?= e($k['notitie'] ?? '') ?></textarea></label>
        <button class="btn primary">Opslaan</button>
      </form>
    </details>
  </section>
  <?php if ($vergelijkbaar): ?>
  <section class="card">
    <h2>Misschien dezelfde klant</h2>
    <?php foreach ($vergelijkbaar as $v): ?>
      <form method="post" class="row between" style="margin-bottom:.4rem"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="actie" value="samenvoegen"><input type="hidden" name="ander_id" value="<?= (int)$v['id'] ?>">
        <span class="small"><a href="klant.php?id=<?= (int)$v['id'] ?>"><?= e($v['naam']) ?></a><br><span class="muted"><?= e(trim($v['telefoon'] . ' ' . $v['adres'])) ?></span></span>
        <button class="btn small ghost">Samenvoegen hierin</button></form>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</aside>
<div>
  <section class="card">
    <h2>Installatie</h2>
    <?= $k['installatie'] ? '<p style="white-space:pre-line">' . e($k['installatie']) . '</p>' : '<p class="muted small">Nog niet ingevuld. Zet hier wat er staat (merk, type, serienummers, EMS), dan heeft de servicedesk het bij elke melding bij de hand.</p>' ?>
    <?php if ($k['notitie']): ?><p class="small muted" style="white-space:pre-line"><?= e($k['notitie']) ?></p><?php endif; ?>
  </section>
  <section class="card">
    <h2>Projecten en tickets</h2>
    <?php if (!$projecten): ?><p class="muted small">Nog niets gekoppeld.</p><?php endif; ?>
    <ul style="list-style:none;padding:0;margin:0">
      <?php foreach ($projecten as $p): ?>
        <li style="padding:.45rem 0;border-bottom:1px solid var(--line)">
          <a href="project.php?nr=<?= e(rawurlencode($p['nummer'])) ?>"><b><?= e($p['nummer']) ?></b></a>
          <span class="tag"><?= e(SOORTEN[$p['soort']] ?? '') ?></span>
          <?= e($p['omschrijving']) ?>
          <span class="small <?= $p['afgerond'] ? 'muted' : '' ?>"> · <?= e(fase_naam($p['fase'], $p['soort'])) ?><?= $p['startdatum'] ? ' · ' . e(datum_kort($p['startdatum'])) : '' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php if ($afspraken): ?>
  <section class="card"><h2>Komende afspraken</h2>
    <?php foreach ($afspraken as $a): ?><p class="small"><b><?= e(datum_kort(substr($a['start'], 0, 10))) ?><?= $a['hele_dag'] ? '' : ' ' . e(substr($a['start'], 11, 5)) ?></b> <?= e($a['titel']) ?> <span class="muted">· <?= e(explode(' ', (string)$a['naam'])[0]) ?></span></p><?php endforeach; ?>
  </section>
  <?php endif; ?>
  <?php if ($berichten): ?>
  <section class="card"><h2>Laatste berichten</h2>
    <?php foreach ($berichten as $b): ?><p class="small"><b><?= e(date('d-m', strtotime($b['datum']))) ?></b> <span class="tag"><?= e($b['bron']) ?></span> <?= e($b['nummer']) ?> · <?= e(mb_strimwidth($b['onderwerp'] . ': ' . strtok((string)$b['tekst'], "\n"), 0, 160, '…')) ?></p><?php endforeach; ?>
  </section>
  <?php endif; ?>
</div>
</div>
<?php page_foot();
