<?php
// Instructie voor de monteurs: per project per dag. Zichtbaar voor monteurs zodra Ruben of Berend akkoord gaf.
require __DIR__ . '/inc/bootstrap.php';
$u = require_admin();

$datum = geldige_datum($_GET['datum'] ?? $_POST['datum'] ?? null);
$pr = q('SELECT * FROM projecten WHERE nummer = ?', [project_nummer($_GET['nr'] ?? $_POST['nr'] ?? '')])->fetch();
if (!$datum || !$pr) { flash('Instructie niet gevonden.', 'err'); redirect('planning.php'); }
$pid = (int)$pr['id'];
$hier = 'opdracht.php?datum=' . $datum . '&nr=' . rawurlencode($pr['nummer']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    if ($actie === 'opslaan') {
        dagopdracht_opslaan($datum, $pid, (string)($_POST['wat'] ?? ''), (string)($_POST['meenemen'] ?? ''), (string)($_POST['stroom'] ?? ''), $u['id'], $u['naam']);
        flash(is_beslisser() ? 'Opgeslagen. De monteurs zien de instructie nu.' : 'Opgeslagen. Ruben of Berend moet nog akkoord geven; daarna zien de monteurs hem.');
    } elseif ($actie === 'akkoord') {
        $o = dagopdracht($datum, $pid);
        flash($o ? e(dagopdracht_akkoord((int)$o['id'], $u['id'], is_beslisser())) : 'Er is nog geen instructie.', 'ok');
    } elseif ($actie === 'overnemen') {
        $bron = geldige_datum($_POST['van'] ?? null);
        $o = $bron ? dagopdracht($bron, $pid) : null;
        if ($o) { dagopdracht_opslaan($datum, $pid, $o['wat'], $o['meenemen'], $o['stroom'], $u['id'], $u['naam']); flash('Overgenomen van ' . e(datum_kort($bron)) . '. Controleer of het voor deze dag klopt.'); }
    }
    redirect($hier);
}

$o = dagopdracht($datum, $pid);
$vrij = dagopdracht_vrij($o);
$wie = q('SELECT g.naam, pl.dagdeel FROM planning pl JOIN gebruikers g ON g.id = pl.gebruiker_id WHERE pl.datum = ? AND pl.project_id = ? ORDER BY g.volgorde, g.naam, pl.dagdeel', [$datum, $pid])->fetchAll();
$perMens = [];
foreach ($wie as $w) $perMens[$w['naam']][] = $w['dagdeel'];
$eerder = q('SELECT datum FROM dagopdrachten WHERE project_id = ? AND datum < ? AND (wat <> \'\' OR meenemen <> \'\') ORDER BY datum DESC LIMIT 1', [$pid, $datum])->fetch();

page_head('Instructie', 'planning');
?>
<section class="card">
  <div class="row between wrap">
    <h1>Instructie <?= e(project_naam($pr)) ?></h1>
    <a class="btn ghost" href="planning.php?w=<?= e(maandag_van($datum)) ?>">Terug naar planning</a>
  </div>
  <p class="muted"><?= e(ucfirst(datum_lang($datum))) ?> · <?= e($pr['nummer']) ?><?= $pr['adres'] !== '' ? ' · ' . e($pr['adres']) : '' ?></p>
  <p>Op dit project: <?php if (!$perMens): ?><span class="muted">nog niemand ingepland</span><?php endif; ?>
    <?php foreach ($perMens as $naam => $dd): ?><span class="tag"><?= e($naam) ?><?= count($dd) === 1 ? ' (' . e($dd[0]) . ')' : '' ?></span> <?php endforeach; ?></p>

  <?php if ($vrij): ?>
    <div class="flash ok">Vrijgegeven door <?= e(dagopdracht_akkoord_naam($o)) ?>. De monteurs zien dit.</div>
  <?php elseif ($o && !dagopdracht_leeg($o)): ?>
    <div class="flash warn">Nog niet zichtbaar voor de monteurs.
      <?= $o['akkoord1_door'] ? 'Ingevuld of nagekeken door ' . e($o['akkoord1_naam']) . '.' : 'Klaargezet door ' . e($o['bijgewerkt_door']) . '.' ?> Ruben of Berend moet akkoord geven.</div>
  <?php else: ?>
    <div class="flash warn">Er is nog geen instructie voor deze dag.</div>
  <?php endif; ?>

  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="datum" value="<?= e($datum) ?>"><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>">
    <label>Wat gaan ze doen (één regel per stap)
      <textarea name="wat" rows="6" placeholder="Kabel trekken, zo ver mogelijk. De kabels liggen op locatie."><?= e($o['wat'] ?? '') ?></textarea></label>
    <label>Meenemen
      <textarea name="meenemen" rows="3" placeholder="Betonboor, datakabel, MC4-stekkers"><?= e($o['meenemen'] ?? '') ?></textarea></label>
    <label>Stroom eraf (wanneer kan het)
      <input name="stroom" value="<?= e($o['stroom'] ?? '') ?>" placeholder="bijv. van 8:00 tot 12:00"></label>
    <p class="small muted"><?= is_beslisser() ? 'Wat jij opslaat is direct zichtbaar voor de monteurs.' : 'Verander je iets, dan moet Ruben of Berend opnieuw akkoord geven.' ?></p>
    <div class="row wrap">
      <button class="btn primary" name="actie" value="opslaan">Opslaan</button>
      <?php if ($o && !dagopdracht_leeg($o) && !$vrij && (is_beslisser() || !$o['akkoord1_door'])): ?>
        <button class="btn klaar" name="actie" value="akkoord" formnovalidate><?= is_beslisser() ? 'Akkoord: vrijgeven voor de monteurs' : 'Ik heb dit nagekeken' ?></button>
      <?php endif; ?>
    </div>
  </form>
  <?php if ($o && $o['bron'] !== ''): ?><p class="small muted mt">Bron: <?= e($o['bron']) ?></p><?php endif; ?>

  <?php if ($eerder && dagopdracht_leeg($o)): ?>
  <form method="post" class="mt">
    <?= csrf_field() ?><input type="hidden" name="datum" value="<?= e($datum) ?>"><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="van" value="<?= e($eerder['datum']) ?>">
    <button class="btn" name="actie" value="overnemen">Neem de instructie van <?= e(datum_kort($eerder['datum'])) ?> over</button>
  </form>
  <?php endif; ?>
</section>
<?php page_foot();
