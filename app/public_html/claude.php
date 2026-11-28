<?php
// Vandaag door Claude: alles wat het brein in de app veranderde, per run, met een knop Ongedaan (28-11-2026).
require __DIR__ . '/inc/bootstrap.php';
$u = require_admin();
$dag = geldige_datum($_GET['dag'] ?? '') ?? vandaag();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try { flash(e(cl_ongedaan((int)($_POST['id'] ?? 0), (int)$u['id']))); }
    catch (Throwable $e) { flash('Niet ongedaan gemaakt: ' . e($e->getMessage()), 'err'); }
    redirect('claude.php?dag=' . $dag . (isset($_POST['alles']) ? '&alles=1' : '') . '#r' . (int)($_POST['id'] ?? 0));
}

// Tijden in de database zijn UTC; de dag in Nederlandse tijd
$van = gmdate('Y-m-d H:i:s', strtotime("$dag 00:00:00")); $tot = gmdate('Y-m-d H:i:s', strtotime("$dag 23:59:59"));
$alles = !empty($_GET['alles']);
$rijen = q('SELECT l.*, g.naam AS ongedaan_naam FROM claude_log l LEFT JOIN gebruikers g ON g.id = l.ongedaan_door
            WHERE l.aangemaakt BETWEEN ? AND ?' . ($alles ? '' : " AND l.opdracht NOT IN ('notitie_verwerkt')") . ' ORDER BY l.id DESC', [$van, $tot])->fetchAll();
// Groeperen per run: een nieuwe groep als er meer dan 20 minuten tussen zit of de run wisselt
$groepen = []; $vorige = null;
foreach ($rijen as $r) {
    $t = strtotime($r['aangemaakt'] . ' UTC');
    if (!$vorige || $vorige['run'] !== $r['run'] || $vorige['t'] - $t > 1200) $groepen[] = ['run' => $r['run'], 'rijen' => []];
    $groepen[count($groepen) - 1]['rijen'][] = $r;
    $vorige = ['run' => $r['run'], 't' => $t];
}
$tel = ['nieuw' => 0, 'afgevinkt' => 0, 'anders' => 0, 'ongedaan' => 0];
foreach ($rijen as $r) {
    if ($r['ongedaan_op']) $tel['ongedaan']++;
    elseif (str_contains($r['wat'], ' nieuw:')) $tel['nieuw']++;
    elseif (str_contains($r['wat'], 'afgevinkt')) $tel['afgevinkt']++;
    else $tel['anders']++;
}
$runNaam = fn(string $r) => ['mac' => 'Grote run (Mac)', 'cloud-licht' => 'Lichte run (cloud)', 'cloud-groot' => 'Grote run (cloud)'][$r] ?? ($r !== '' ? $r : 'Claude, los');

page_head('Door Claude', 'claude');
?>
<section class="card">
  <div class="row between wrap">
    <h1>Door Claude op <?= e(datum_lang($dag)) ?></h1>
    <form class="row"><input type="date" name="dag" value="<?= e($dag) ?>" onchange="this.form.submit()" aria-label="Dag"><?php if ($alles): ?><input type="hidden" name="alles" value="1"><?php endif; ?></form>
  </div>
  <p class="muted">Alles wat het brein vandaag in de app zette of veranderde. Klopt iets niet, klik op <b>Ongedaan</b>: de oude stand komt terug. Dat kan niet meer als iemand het daarna zelf veranderde.</p>
  <div class="stats">
    <div><b><?= $tel['nieuw'] ?></b><span>nieuw</span></div>
    <div><b><?= $tel['afgevinkt'] ?></b><span>afgevinkt</span></div>
    <div><b><?= $tel['anders'] ?></b><span>bijgewerkt</span></div>
    <div><b><?= $tel['ongedaan'] ?></b><span>ongedaan</span></div>
  </div>
  <p class="small"><a href="claude.php?dag=<?= e(dag_plus($dag, -1)) ?><?= $alles ? '&amp;alles=1' : '' ?>">← Vorige dag</a>
    <?php if ($dag < vandaag()): ?> · <a href="claude.php?dag=<?= e(dag_plus($dag, 1)) ?><?= $alles ? '&amp;alles=1' : '' ?>">Volgende dag →</a><?php endif; ?>
    · <a href="claude.php?dag=<?= e($dag) ?><?= $alles ? '' : '&amp;alles=1' ?>"><?= $alles ? 'Verwerkte notities verbergen' : 'Ook verwerkte notities tonen' ?></a></p>
</section>

<?php if (!$groepen): ?><section class="card"><p class="muted">Claude veranderde op deze dag niets in de app.</p></section><?php endif; ?>
<?php foreach ($groepen as $g): $eerste = end($g['rijen']); $laatste = $g['rijen'][0]; ?>
<section class="card">
  <h2><?= e($runNaam($g['run'])) ?> <span class="small muted"><?= e(substr(fmt_dt($eerste['aangemaakt']), 11)) ?><?= $eerste !== $laatste ? '–' . e(substr(fmt_dt($laatste['aangemaakt']), 11)) : '' ?> · <?= count($g['rijen']) ?> wijzigingen</span></h2>
  <ul class="claudelog">
  <?php foreach ($g['rijen'] as $r): $link = cl_link($r); ?>
    <li id="r<?= (int)$r['id'] ?>" class="<?= $r['ongedaan_op'] ? 'muted' : '' ?>">
      <span class="small muted"><?= e(substr(fmt_dt($r['aangemaakt']), 11)) ?></span>
      <?= $link ? '<a href="' . e($link) . '">' . e($r['wat']) . '</a>' : e($r['wat']) ?>
      <?php if ($r['ongedaan_op']): ?><span class="tag">ongedaan door <?= e(explode(' ', (string)$r['ongedaan_naam'])[0]) ?></span>
      <?php else: ?>
        <form method="post" class="inline" onsubmit="return confirm('Deze actie van Claude ongedaan maken?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?php if ($alles): ?><input type="hidden" name="alles" value="1"><?php endif; ?>
          <button class="btn small ghost">Ongedaan</button></form>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php page_foot();
