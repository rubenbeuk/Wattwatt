<?php
// Overleg Ruben en Berend: bespreekpunten verzamelen (door kantoor en het brein), bespreken en het besluit vastleggen.
// Vervangt het Word-document "Update" in OneDrive (afspraak Ruben 19-11-2026).
require __DIR__ . '/inc/bootstrap.php';
$u = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($actie === 'nieuw') { bespreekpunt_opslaan(['titel' => $_POST['titel'] ?? '', 'toelichting' => $_POST['toelichting'] ?? '', 'project' => $_POST['project'] ?? '', 'prioriteit' => $_POST['prioriteit'] ?? 'normaal', 'bron' => 'app'], $u['naam']); flash('Bespreekpunt toegevoegd.'); }
        elseif ($actie === 'besproken') {
            q("UPDATE bespreekpunten SET status = 'besproken', besluit = ?, besproken_op = UTC_TIMESTAMP() WHERE id = ?", [trim((string)($_POST['besluit'] ?? '')) ?: null, $id]);
            // Volgt er actie uit het besluit, dan meteen een taak
            if (($_POST['taak_voor'] ?? '') !== '' && trim((string)($_POST['besluit'] ?? '')) !== '') {
                $bp = q('SELECT * FROM bespreekpunten WHERE id = ?', [$id])->fetch();
                $tid = taak_maak(['titel' => mb_substr($bp['titel'], 0, 200), 'toelichting' => 'Besluit overleg ' . date('d-m') . ': ' . $_POST['besluit'], 'project_id' => $bp['project_id'],
                    'eigenaar_id' => gebruiker_zoek((string)$_POST['taak_voor']), 'bron' => 'Overleg', 'van_id' => (int)$u['id']], (int)$u['id'], $u['naam']);
                flash('Besproken en taak #' . $tid . ' gemaakt.');
            } else flash('Besproken.');
        }
        elseif ($actie === 'vervallen') { q("UPDATE bespreekpunten SET status = 'vervallen', besproken_op = UTC_TIMESTAMP() WHERE id = ?", [$id]); flash('Punt vervallen.'); }
        elseif ($actie === 'heropen') { q("UPDATE bespreekpunten SET status = 'open', besproken_op = NULL WHERE id = ?", [$id]); flash('Punt staat weer open.'); }
    } catch (InvalidArgumentException $e) { flash('Niet opgeslagen: ' . e($e->getMessage()), 'err'); }
    redirect('overleg.php');
}

$open = q("SELECT b.*, p.nummer FROM bespreekpunten b LEFT JOIN projecten p ON p.id = b.project_id WHERE b.status = 'open' ORDER BY FIELD(b.prioriteit, 'hoog', 'normaal', 'laag'), b.id")->fetchAll();
$langLaat = taken_lang_te_laat();
$recent = q("SELECT b.*, p.nummer FROM bespreekpunten b LEFT JOIN projecten p ON p.id = b.project_id WHERE b.status <> 'open' AND b.besproken_op >= UTC_TIMESTAMP() - INTERVAL 21 DAY ORDER BY b.besproken_op DESC")->fetchAll();
$kantoor = mensen_kantoor();

page_head('Overleg', 'overleg');
?>
<section class="card">
  <div class="row between wrap">
    <div><h1>Overleg</h1><p class="muted small"><?= count($open) ?> open <?= count($open) === 1 ? 'punt' : 'punten' ?>. Claude zet hier ook punten bij uit mail en berichten.</p></div>
    <button type="button" class="btn primary" onclick="document.getElementById('nieuwPunt').hidden = !document.getElementById('nieuwPunt').hidden">Punt toevoegen</button>
  </div>
  <form method="post" class="stack" id="nieuwPunt" hidden>
    <?= csrf_field() ?><input type="hidden" name="actie" value="nieuw">
    <label>Punt <input name="titel" required placeholder="bijv. Joey na 30-11: wie vervangt hem op Oostendorp?"></label>
    <label>Toelichting <textarea name="toelichting" rows="2"></textarea></label>
    <div class="row wrap">
      <label class="grow">Project (mag leeg) <input name="project" placeholder="PRJ-nummer"></label>
      <label class="grow">Belang <select name="prioriteit"><option value="hoog">Hoog</option><option value="normaal" selected>Normaal</option><option value="laag">Laag</option></select></label>
    </div>
    <button class="btn primary">Toevoegen</button>
  </form>
</section>

<section class="card">
  <h2>Te bespreken</h2>
  <?php if (!$open): ?><p class="muted small">Niets open.</p><?php endif; ?>
  <?php foreach ($open as $b): ?>
    <details class="bp" style="border-bottom:1px solid var(--line);padding:.5rem 0">
      <summary><?= $b['prioriteit'] === 'hoog' ? '<span class="tag rood">Hoog</span> ' : '' ?><b><?= e($b['titel']) ?></b>
        <span class="small muted"><?= $b['nummer'] ? ' · ' . e($b['nummer']) : '' ?> · <?= e($b['ingebracht_door']) ?> <?= e(date('d-m', strtotime($b['aangemaakt']))) ?></span></summary>
      <?php if ($b['toelichting']): ?><p class="small" style="white-space:pre-line"><?= e($b['toelichting']) ?></p><?php endif; ?>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <label>Besluit <textarea name="besluit" rows="2" placeholder="wat is afgesproken"></textarea></label>
        <div class="row wrap">
          <label class="grow">Taak maken voor (optioneel) <select name="taak_voor"><option value="">Geen taak</option><?php foreach ($kantoor as $m): ?><option value="<?= e($m['gebruikersnaam']) ?>"><?= e($m['naam']) ?></option><?php endforeach; ?></select></label>
          <button class="btn primary" name="actie" value="besproken">Besproken</button>
          <button class="btn ghost" name="actie" value="vervallen">Vervalt</button>
        </div>
      </form>
    </details>
  <?php endforeach; ?>
</section>

<?php if ($langLaat): ?>
<section class="card">
  <div class="row between wrap"><h2>Taken die al lang te laat zijn (<?= count($langLaat) ?>)</h2><a class="small" href="opschonen.php?bak=laat">Opschonen</a></div>
  <p class="muted small">Meer dan 3 werkdagen over de deadline. Bespreek of ze nog nodig zijn, wie ze oppakt, of dat de deadline verschuift.</p>
  <ul class="ptaken"><?php foreach ($langLaat as $t): ?>
    <li><a href="taak.php?id=<?= (int)$t['id'] ?>"><?= e($t['titel']) ?></a> <span class="small muted"><?= e($t['eigenaar'] ? explode(' ', $t['eigenaar'])[0] : 'zonder eigenaar') ?> · uiterlijk <?= e(datum_kort($t['deadline'])) ?><?= $t['nummer'] ? ' · ' . e($t['nummer']) : '' ?></span></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>

<?php if ($recent): ?>
<section class="card">
  <h2>Besproken (laatste 3 weken)</h2>
  <?php foreach ($recent as $b): ?>
    <div class="row between" style="border-bottom:1px solid var(--line);padding:.4rem 0">
      <div class="small"><b><?= e($b['titel']) ?></b> <span class="muted">· <?= e(date('d-m', strtotime($b['besproken_op'] . ' UTC'))) ?><?= $b['status'] === 'vervallen' ? ' · vervallen' : '' ?></span><?= $b['besluit'] ? '<br>Besluit: ' . e($b['besluit']) : '' ?></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn small ghost" name="actie" value="heropen">Heropen</button></form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php page_foot();
