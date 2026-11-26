<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$pr = q('SELECT * FROM projecten WHERE nummer = ?', [project_nummer($_GET['nr'] ?? $_POST['nr'] ?? '')])->fetch();
if (!$pr) { flash('Project niet gevonden.', 'err'); redirect('projecten.php'); }
$pid = (int)$pr['id'];
$hier = 'fotos.php?nr=' . rawurlencode($pr['nummer']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? 'upload';
    if ($actie === 'weg') {
        $f = q('SELECT * FROM fotos WHERE id = ? AND project_id = ?', [(int)($_POST['id'] ?? 0), $pid])->fetch();
        if ($f && (is_admin() || (int)$f['gebruiker_id'] === $u['id'])) {
            @unlink(foto_map() . '/' . $pid . '/' . basename($f['bestand']));
            q('DELETE FROM fotos WHERE id = ?', [$f['id']]);
            flash('Foto verwijderd.');
        }
        redirect($hier);
    }
    // Upload: één foto per verzoek (de app verkleint en stuurt ze één voor één)
    $fout = isset($_FILES['foto']) ? foto_opslaan($pid, $_FILES['foto'], (string)($_POST['soort'] ?? ''), (string)($_POST['omschrijving'] ?? ''), $u['id']) : 'Geen foto ontvangen.';
    if (($_POST['via'] ?? '') === 'js') {
        header('Content-Type: application/json');
        echo json_encode($fout ? ['fout' => $fout] : ['ok' => true]);
        exit;
    }
    flash($fout ?? 'Foto toegevoegd.', $fout ? 'err' : 'ok');
    redirect($hier);
}

$fotos = q('SELECT f.*, g.naam FROM fotos f LEFT JOIN gebruikers g ON g.id = f.gebruiker_id WHERE f.project_id = ? ORDER BY f.id DESC', [$pid])->fetchAll();
$perSoort = [];
foreach ($fotos as $f) $perSoort[$f['soort']][] = $f;

page_head('Foto\'s ' . $pr['nummer'], 'projecten');
?>
<section class="card">
  <div class="row between wrap">
    <h1>Foto's <?= e(project_naam($pr)) ?></h1>
    <a class="btn ghost" href="project.php?nr=<?= e(rawurlencode($pr['nummer'])) ?>">Naar het project</a>
  </div>
  <form method="post" enctype="multipart/form-data" id="fotoForm" class="stack">
    <?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>">
    <div class="row wrap">
      <label class="grow">Soort foto
        <select name="soort"><?php foreach (FOTO_SOORT as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Wat staat erop (mag leeg) <input name="omschrijving" placeholder="bijv. verdeler na aansluiten"></label>
    </div>
    <label class="btn primary big breed fotoknop">Foto maken of kiezen
      <input type="file" name="foto" id="fotoInvoer" accept="image/*" multiple hidden></label>
    <div id="fotoStatus" class="small muted" aria-live="polite"></div>
    <noscript><button class="btn">Uploaden</button></noscript>
  </form>
</section>

<?php if (!$fotos): ?>
<section class="card"><p class="muted">Nog geen foto's bij dit project.</p></section>
<?php endif; ?>
<?php foreach (FOTO_SOORT as $k => $label): if (empty($perSoort[$k])) continue; ?>
<section class="card">
  <h2><?= e($label) ?> <span class="muted">(<?= count($perSoort[$k]) ?>)</span></h2>
  <div class="fotogrid">
    <?php foreach ($perSoort[$k] as $f): ?>
      <figure>
        <a href="foto.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><img src="foto.php?id=<?= (int)$f['id'] ?>" alt="<?= e($f['omschrijving'] ?: $label) ?>" loading="lazy"></a>
        <figcaption class="small"><?= $f['omschrijving'] !== '' ? e($f['omschrijving']) . '<br>' : '' ?><span class="muted"><?= e($f['naam'] ?? '') ?> · <?= e(fmt_dt($f['aangemaakt'])) ?></span>
          <?php if (is_admin() || (int)$f['gebruiker_id'] === $u['id']): ?>
            <form method="post" class="inline" onsubmit="return confirm('Foto verwijderen?')"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="linkbtn muted" name="actie" value="weg">verwijderen</button></form>
          <?php endif; ?></figcaption>
      </figure>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<?php page_foot();
