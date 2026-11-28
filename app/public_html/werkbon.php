<?php
// Digitale werkbon per bezoek (28-11-2026): wat er gedaan is, materiaal, uren en de handtekening van de klant op het scherm.
// Vooraf ingevuld uit planning, instructie, boekingen en uren. Na tekenen staat hij vast en is hij als PDF te downloaden.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$wb = !empty($_GET['id'] ?? $_POST['id'] ?? '') ? q('SELECT * FROM werkbonnen WHERE id = ?', [(int)($_GET['id'] ?? $_POST['id'])])->fetch() : null;
$pr = $wb ? q('SELECT * FROM projecten WHERE id = ?', [$wb['project_id']])->fetch() : q('SELECT * FROM projecten WHERE nummer = ?', [project_nummer($_GET['nr'] ?? '')])->fetch();
if (!$pr) { flash('Werkbon of project niet gevonden.', 'err'); redirect('dag.php'); }
if (is_onderaannemer() && !onderaannemer_mag_project((int)$pr['id'], (int)$u['id'])) { http_response_code(403); exit('Geen toegang.'); }
$magSchrijven = !is_verkoop();

// Nieuwe werkbon (of de bestaande concept-werkbon van die dag)
if (!$wb) {
    $datum = geldige_datum($_GET['datum'] ?? '') ?? vandaag();
    $bestaand = q("SELECT id FROM werkbonnen WHERE project_id = ? AND datum = ? AND status = 'concept' ORDER BY id DESC LIMIT 1", [$pr['id'], $datum])->fetchColumn();
    if (!$magSchrijven) { flash('Geen werkbon gevonden.', 'err'); redirect('project.php?nr=' . rawurlencode($pr['nummer'])); }
    redirect('werkbon.php?id=' . ($bestaand ?: werkbon_nieuw((int)$pr['id'], $datum, $u)));
}
$getekend = $wb['status'] === 'getekend';

if (isset($_GET['pdf'])) {
    $pdf = werkbon_pdf($wb, $pr);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['bekijk']) ? 'inline' : 'attachment') . '; filename="werkbon-' . $pr['nummer'] . '-' . $wb['datum'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    if (!$magSchrijven) { http_response_code(403); exit('Geen toegang.'); }
    if ($getekend && $actie !== 'heropen') { flash('Deze werkbon is al getekend en staat vast.', 'err'); redirect('werkbon.php?id=' . (int)$wb['id']); }
    if ($actie === 'opslaan' || $actie === 'tekenen') {
        $uren = num($_POST['uren'] ?? null);
        q('UPDATE werkbonnen SET datum = ?, monteurs = ?, werk = ?, materiaal = ?, uren = ?, opmerkingen = ?, concept_door = ?, bijgewerkt_door = ? WHERE id = ?', [
            geldige_datum($_POST['datum'] ?? '') ?? $wb['datum'], mb_substr(clean_text($_POST['monteurs'] ?? ''), 0, 255),
            trim((string)($_POST['werk'] ?? '')) ?: null, trim((string)($_POST['materiaal'] ?? '')) ?: null, $uren !== null && $uren >= 0 && $uren < 1000 ? $uren : null,
            trim((string)($_POST['opmerkingen'] ?? '')) ?: null, '', $u['naam'], $wb['id']]);
        if ($actie === 'tekenen') {
            $naam = mb_substr(clean_text($_POST['klant_naam'] ?? ''), 0, 150);
            $hand = (string)($_POST['handtekening'] ?? '');
            if ($naam === '' || !handtekening_jpeg($hand)) { flash('Laat de klant zijn naam invullen en tekenen in het vak.', 'err'); redirect('werkbon.php?id=' . (int)$wb['id'] . '#tekenen'); }
            if (trim((string)($_POST['werk'] ?? '')) === '') { flash('Vul eerst in wat er gedaan is.', 'err'); redirect('werkbon.php?id=' . (int)$wb['id']); }
            q("UPDATE werkbonnen SET klant_naam = ?, handtekening = ?, getekend_op = UTC_TIMESTAMP(), status = 'getekend' WHERE id = ?", [$naam, $hand, $wb['id']]);
            q("INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst) VALUES (?, ?, 'app', ?, ?, ?)",
                [$pr['id'], date('Y-m-d H:i:s'), $u['naam'], 'Werkbon getekend door ' . $naam, mb_substr(trim((string)($_POST['werk'] ?? '')), 0, 2000)]);
            seintje_project((int)$pr['id'], 'Werkbon getekend: ' . $pr['nummer'] . ' (' . $naam . ')', 'werkbon.php?id=' . (int)$wb['id'], (int)$u['id']);
            flash('Werkbon getekend. Download de PDF voor de klant of het dossier.');
        } else flash('Werkbon opgeslagen.');
    } elseif ($actie === 'heropen' && is_admin()) {
        q("UPDATE werkbonnen SET status = 'concept', handtekening = NULL, getekend_op = NULL WHERE id = ?", [$wb['id']]);
        flash('Werkbon heropend; de handtekening is weggehaald.');
    } elseif ($actie === 'weg' && is_admin()) {
        q("DELETE FROM werkbonnen WHERE id = ? AND status = 'concept'", [$wb['id']]);
        flash('Werkbon verwijderd.');
        redirect('project.php?nr=' . rawurlencode($pr['nummer']) . '#werkbonnen');
    }
    redirect('werkbon.php?id=' . (int)$wb['id']);
}

page_head('Werkbon ' . $pr['nummer'], $pr['soort'] === 'service' ? 'service' : 'projecten');
?>
<section class="card narrow">
  <div class="small muted"><a href="project.php?nr=<?= e(rawurlencode($pr['nummer'])) ?>#werkbonnen"><?= e($pr['nummer']) ?> <?= e(project_naam($pr)) ?></a></div>
  <div class="row between wrap">
    <h1>Werkbon <?= e(datum_lang($wb['datum'])) ?></h1>
    <a class="btn" href="werkbon.php?id=<?= (int)$wb['id'] ?>&amp;pdf=1">PDF</a>
  </div>
  <?php if ($wb['concept_door'] !== '' && !$getekend): ?><div class="flash warn">Concept van <?= e($wb['concept_door']) ?>. Kijk het na en pas aan waar nodig.</div><?php endif; ?>
  <p class="muted small"><?= e($pr['klant']) ?><?= $pr['adres'] !== '' ? ' · ' . e($pr['adres']) : '' ?></p>

  <?php if ($getekend): ?>
    <dl class="pdetails">
      <dt>Monteurs</dt><dd><?= e($wb['monteurs'] ?: '–') ?></dd>
      <?php if ($wb['uren'] !== null): ?><dt>Uren</dt><dd><?= str_replace('.', ',', (string)(float)$wb['uren']) ?></dd><?php endif; ?>
    </dl>
    <?php foreach (['werk' => 'Uitgevoerd werk', 'materiaal' => 'Gebruikt materiaal', 'opmerkingen' => 'Opmerkingen en afspraken'] as $k => $kop): if (trim((string)$wb[$k]) === '') continue; ?>
      <h2><?= $kop ?></h2><p style="white-space:pre-line"><?= e($wb[$k]) ?></p>
    <?php endforeach; ?>
    <h2>Voor akkoord</h2>
    <p><?= e($wb['klant_naam']) ?>, <?= e(fmt_dt((string)$wb['getekend_op'])) ?></p>
    <?php if ($wb['handtekening']): ?><img class="handtekening" src="<?= e($wb['handtekening']) ?>" alt="Handtekening van <?= e($wb['klant_naam']) ?>"><?php endif; ?>
    <?php if (is_admin()): ?><form method="post" onsubmit="return confirm('Werkbon heropenen? De handtekening gaat weg.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$wb['id'] ?>"><button class="linkbtn small" name="actie" value="heropen">Heropenen</button></form><?php endif; ?>
  <?php elseif ($magSchrijven): ?>
  <form method="post" class="stack" id="wbForm">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$wb['id'] ?>">
    <div class="row wrap">
      <label class="grow">Datum <input type="date" name="datum" value="<?= e($wb['datum']) ?>"></label>
      <label>Uren (totaal ploeg) <input name="uren" inputmode="decimal" value="<?= $wb['uren'] !== null ? e(str_replace('.', ',', (string)(float)$wb['uren'])) : '' ?>" style="max-width:7rem"></label>
    </div>
    <label>Monteurs <input name="monteurs" value="<?= e($wb['monteurs']) ?>"></label>
    <label>Wat is er gedaan <textarea name="werk" rows="5" required><?= e($wb['werk'] ?? '') ?></textarea></label>
    <label>Gebruikt materiaal <textarea name="materiaal" rows="4"><?= e($wb['materiaal'] ?? '') ?></textarea></label>
    <label>Opmerkingen en afspraken met de klant <textarea name="opmerkingen" rows="3"><?= e($wb['opmerkingen'] ?? '') ?></textarea></label>
    <button class="btn" name="actie" value="opslaan" formnovalidate>Opslaan</button>

    <h2 id="tekenen">Klant tekent voor akkoord</h2>
    <label>Naam klant <input name="klant_naam" value="<?= e($wb['klant_naam']) ?>" autocomplete="off"></label>
    <div class="tekenvak"><canvas id="teken" width="600" height="220" aria-label="Tekenvak voor de handtekening"></canvas></div>
    <div class="row between"><span class="small muted">Teken met je vinger in het vak.</span><button type="button" class="btn small ghost" id="wis">Wissen</button></div>
    <input type="hidden" name="handtekening" id="handtekening">
    <button class="btn primary big" name="actie" value="tekenen">Tekenen en afronden</button>
  </form>
  <?php if (is_admin()): ?><form method="post" onsubmit="return confirm('Deze werkbon verwijderen?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$wb['id'] ?>"><button class="linkbtn small" name="actie" value="weg">Werkbon verwijderen</button></form><?php endif; ?>
  <?php endif; ?>
</section>
<script>
(function () {
  var c = document.getElementById('teken'); if (!c) return;
  var x = c.getContext('2d'), getekend = false, bezig = false;
  function leeg() { x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.strokeStyle = '#111'; x.lineWidth = 3; x.lineCap = 'round'; x.lineJoin = 'round'; getekend = false; }
  function punt(e) { var r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; }
  leeg();
  c.addEventListener('pointerdown', function (e) { bezig = true; c.setPointerCapture(e.pointerId); var p = punt(e); x.beginPath(); x.moveTo(p[0], p[1]); e.preventDefault(); });
  c.addEventListener('pointermove', function (e) { if (!bezig) return; var p = punt(e); x.lineTo(p[0], p[1]); x.stroke(); getekend = true; e.preventDefault(); });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { c.addEventListener(t, function () { bezig = false; }); });
  document.getElementById('wis').addEventListener('click', leeg);
  document.getElementById('wbForm').addEventListener('submit', function (e) {
    var knop = e.submitter ? e.submitter.value : '';
    document.getElementById('handtekening').value = getekend ? c.toDataURL('image/jpeg', 0.85) : '';
    if (knop === 'tekenen' && !getekend) { e.preventDefault(); alert('Laat de klant eerst tekenen in het vak.'); }
  });
})();
</script>
<?php page_foot();
