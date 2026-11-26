<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_admin();
$vandaag = vandaag();

$mensen = mensen_kantoor();
$alle = taken_open();
$perMens = [];
foreach ($alle as $t) $perMens[(int)$t['eigenaar_id']][] = $t;
$verdelen = $perMens[0] ?? [];
$begin = gmdate('Y-m-d H:i:s', strtotime($vandaag . ' 00:00:00'));
$log = q('SELECT l.*, COALESCE(g.naam, l.wie) door, t.titel, t.id tid FROM taak_log l JOIN taken t ON t.id = l.taak_id LEFT JOIN gebruikers g ON g.id = l.gebruiker_id
          WHERE l.aangemaakt >= ? AND l.tekst NOT LIKE \'Aangemaakt%\' ORDER BY l.id DESC LIMIT 60', [$begin])->fetchAll();
$totaal = max(1, count($alle));
$toon = (int)($_GET['wie'] ?? 0);
$projecten = q('SELECT nummer, omschrijving FROM projecten WHERE afgerond = 0 ORDER BY id DESC LIMIT 300')->fetchAll();

page_head('Team', 'team');
?>
<section class="card">
  <h1>Wie heeft wat open</h1>
  <div class="teamgrid">
    <?php foreach ($mensen as $m): $lijst = $perMens[(int)$m['id']] ?? []; $laat = count(array_filter($lijst, 'taak_te_laat')); ?>
      <a class="teamkaart<?= $toon === (int)$m['id'] ? ' on' : '' ?>" href="team.php?wie=<?= (int)$m['id'] ?>#lijst">
        <div class="row between"><b><?= e($m['naam']) ?></b><span><b class="groot"><?= count($lijst) ?></b> open<?= $laat ? ', <span class="rood-ink">' . $laat . ' te laat</span>' : '' ?></span></div>
        <div class="balk"><div style="width: <?= round(100 * count($lijst) / $totaal) ?>%"></div></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<div class="cols2">
<div class="kolom">
  <section class="card" id="verdelen">
    <h2>Nog te verdelen <span class="muted">(<?= count($verdelen) ?>)</span></h2>
    <?php if (!$verdelen): ?><p class="muted">Alles heeft een eigenaar.</p><?php endif; ?>
    <ul class="taken">
      <?php foreach ($verdelen as $t): ?>
      <li class="taak">
        <div class="taak-hoofd">
          <div class="grow">
            <a class="taak-titel" href="taak.php?id=<?= (int)$t['id'] ?>"><?= e($t['titel']) ?></a>
            <div class="small muted"><?= $t['nummer'] ? e(project_naam($t)) . ' · ' : '' ?><?= e($t['bron']) ?></div>
            <div class="taak-tags"><?php if (($t['van_rol'] ?? '') === 'monteur'): ?><span class="tag st-bezig">Vraag van <?= e(explode(' ', (string)$t['van'])[0]) ?></span><?php endif; ?><?php if (in_array($t['prioriteit'], ['kritiek', 'hoog'], true)): ?><span class="tag p-<?= e($t['prioriteit']) ?>"><?= e(TAAK_PRIO[$t['prioriteit']]) ?></span><?php endif; ?></div>
          </div>
          <form method="post" action="taak.php" class="row wrap verdeelknoppen">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="terug" value="team.php#verdelen"><input type="hidden" name="actie" value="toewijzen">
            <?php foreach ($mensen as $m): ?><button class="btn small" name="eigenaar_id" value="<?= (int)$m['id'] ?>"><?= e(explode(' ', $m['naam'])[0]) ?></button><?php endforeach; ?>
          </form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="card" id="lijst">
    <?php foreach ($mensen as $m): if ($toon && $toon !== (int)$m['id']) continue; $lijst = $perMens[(int)$m['id']] ?? []; ?>
      <h2><?= e($m['naam']) ?> <span class="muted">(<?= count($lijst) ?>)</span></h2>
      <?php if (!$lijst): ?><p class="muted">Geen open taken.</p><?php endif; ?>
      <ul class="taken"><?php foreach ($lijst as $t) taak_rij($t, 'team.php' . ($toon ? '?wie=' . $toon : '')); ?></ul>
    <?php endforeach; ?>
    <?php if ($toon): ?><p><a href="team.php#lijst">Toon iedereen</a></p><?php endif; ?>
  </section>
</div>

<aside class="kolom">
  <section class="card">
    <h2>Vandaag gebeurd</h2>
    <?php if (!$log): ?><p class="muted">Nog niets bijgewerkt vandaag.</p><?php endif; ?>
    <ul class="logboek">
      <?php foreach ($log as $l): ?>
        <li><span class="muted small"><?= e(substr(fmt_dt($l['aangemaakt']), 11)) ?> · <?= e($l['door']) ?></span><br>
          <a href="taak.php?id=<?= (int)$l['tid'] ?>"><?= e($l['titel']) ?></a><br><b><?= e($l['tekst']) ?></b></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="card">
    <h2>Taak toevoegen</h2>
    <form method="post" action="taak.php" class="stack">
      <?= csrf_field() ?><input type="hidden" name="actie" value="nieuw"><input type="hidden" name="terug" value="team.php">
      <label>Wat moet er gebeuren <input name="titel" required></label>
      <label>Voor wie
        <select name="eigenaar_id"><option value="">Nog te verdelen</option><?php foreach ($mensen as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['naam']) ?></option><?php endforeach; ?></select></label>
      <label>Project <input name="project" list="projectlijst" placeholder="PRJ-nummer"></label>
      <div class="row wrap">
        <label class="grow">Doen op <input type="date" name="datum" value="<?= e($vandaag) ?>"></label>
        <label class="grow">Uiterlijk klaar <input type="date" name="deadline"></label>
      </div>
      <label>Belang <select name="prioriteit"><?php foreach (TAAK_PRIO as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'normaal' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <button class="btn primary">Toevoegen</button>
    </form>
    <datalist id="projectlijst"><?php foreach ($projecten as $p): ?><option value="<?= e($p['nummer']) ?>"><?= e($p['omschrijving']) ?></option><?php endforeach; ?></datalist>
  </section>
</aside>
</div>
<?php page_foot();
