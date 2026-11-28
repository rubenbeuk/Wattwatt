<?php
// Takenlijst opschonen (28-11-2026): voorstellen van Claude goedkeuren, en zelf snel taken verdelen, afvinken of laten vervallen.
require __DIR__ . '/inc/bootstrap.php';
$u = require_admin();
$bak = in_array($_GET['bak'] ?? '', ['voorstellen', 'eigenaar', 'laat', 'pipedrive', 'project'], true) ? $_GET['bak'] : 'voorstellen';
$hier = 'opschonen.php?bak=' . $bak;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    if ($actie === 'akkoord' || $actie === 'afwijzen') {
        flash(e(taak_voorstel_besluit((int)($_POST['id'] ?? 0), $actie === 'akkoord', $u)));
    } elseif ($actie === 'alle_akkoord') {
        $n = 0;
        foreach (array_map('intval', (array)($_POST['ids'] ?? [])) as $vid) { if (taak_voorstel_besluit($vid, true, $u) === 'Uitgevoerd.') $n++; }
        flash($n . ' voorstellen uitgevoerd.');
    } else {
        $t = q("SELECT * FROM taken WHERE id = ? AND status <> 'klaar'", [(int)($_POST['taak'] ?? 0)])->fetch();
        if (!$t) flash('Taak niet gevonden of al klaar.', 'err');
        elseif ($actie === 'eigenaar' && ($g = (int)($_POST['eigenaar'] ?? 0))) {
            q('UPDATE taken SET eigenaar_id = ? WHERE id = ?', [$g, $t['id']]);
            $nm = q('SELECT naam FROM gebruikers WHERE id = ?', [$g])->fetchColumn();
            taak_log((int)$t['id'], 'Eigenaar: ' . $nm . ' (opschonen)', $u['id'], $u['naam']);
            if ($g !== (int)$u['id']) seintje($g, 'Taak voor jou: ' . mb_substr($t['titel'], 0, 150), 'taak.php?id=' . (int)$t['id']);
            flash('Taak gegeven aan ' . e($nm) . '.');
        } elseif ($actie === 'klaar') {
            taak_zet_status((int)$t['id'], 'klaar', 'Afgevinkt bij opschonen', $u['id'], $u['naam']); flash('Afgevinkt.');
        } elseif ($actie === 'vervalt') {
            taak_zet_status((int)$t['id'], 'klaar', 'Vervallen bij opschonen (niet meer nodig)' . (clean_text($_POST['reden'] ?? '') !== '' ? ': ' . clean_text($_POST['reden']) : ''), $u['id'], $u['naam']); flash('Vervallen.');
        } elseif ($actie === 'deadline') {
            $dl = geldige_datum($_POST['deadline'] ?? '');
            q('UPDATE taken SET deadline = ? WHERE id = ?', [$dl, $t['id']]);
            taak_log((int)$t['id'], $dl ? 'Nieuwe deadline ' . datum_kort($dl) . ' (opschonen)' : 'Deadline weggehaald (opschonen)', $u['id'], $u['naam']); flash('Deadline bijgewerkt.');
        }
    }
    redirect($hier . '#t' . (int)($_POST['taak'] ?? 0));
}

$c = taken_cijfers();
$nVoorstel = (int)q("SELECT COUNT(*) FROM taak_voorstellen v JOIN taken t ON t.id = v.taak_id WHERE v.status = 'open' AND t.status <> 'klaar'")->fetchColumn();
$nPd = (int)q("SELECT COUNT(*) FROM taken WHERE status <> 'klaar' AND extern_id LIKE 'pd:%'")->fetchColumn();
$basis = "SELECT t.*, g.naam AS eigenaar, pr.nummer, pr.omschrijving AS pnaam, v.id AS vid, v.actie AS vactie, v.reden AS vreden, ve.naam AS veigenaar, vi.titel AS vin, v.deadline AS vdeadline, vp.nummer AS vproject
          FROM taken t LEFT JOIN gebruikers g ON g.id = t.eigenaar_id LEFT JOIN projecten pr ON pr.id = t.project_id
          LEFT JOIN taak_voorstellen v ON v.taak_id = t.id AND v.status = 'open' LEFT JOIN gebruikers ve ON ve.id = v.eigenaar_id LEFT JOIN taken vi ON vi.id = v.in_taak_id
          LEFT JOIN projecten vp ON vp.id = v.project_id WHERE t.status <> 'klaar' AND ";
$waar = ['voorstellen' => 'v.id IS NOT NULL', 'eigenaar' => 't.eigenaar_id IS NULL', 'laat' => 't.deadline < CURDATE()', 'pipedrive' => "t.extern_id LIKE 'pd:%'", 'project' => 't.project_id IS NULL'][$bak];
$taken = q($basis . $waar . ' ORDER BY COALESCE(t.deadline, t.datum, \'2999-12-31\'), t.id LIMIT 200')->fetchAll();
$mensen = mensen_kantoor();
$vtekst = fn(array $t) => match ($t['vactie']) {
    'klaar' => 'Afvinken', 'vervalt' => 'Laten vervallen', 'samenvoegen' => 'Samenvoegen met "' . mb_substr((string)$t['vin'], 0, 80) . '"',
    'eigenaar' => 'Geven aan ' . $t['veigenaar'], 'deadline' => 'Nieuwe deadline ' . ($t['vdeadline'] ? datum_kort($t['vdeadline']) : ''), 'project' => 'Koppelen aan ' . $t['vproject'], default => '',
};

page_head('Takenlijst opschonen', 'opschonen');
?>
<section class="card">
  <h1>Takenlijst opschonen</h1>
  <p class="muted">Een lijst die klopt, wordt gebruikt. Claude zet hier voorstellen klaar (afvinken, laten vervallen, samenvoegen, eigenaar); jij keurt ze goed of af. Je kunt ook zelf snel opruimen.</p>
  <div class="stats">
    <a href="opschonen.php?bak=voorstellen"><b><?= $nVoorstel ?></b><span>voorstellen van Claude</span></a>
    <a href="opschonen.php?bak=eigenaar"<?= $c['zonder_eigenaar'] ? ' class="rood"' : '' ?>><b><?= (int)$c['zonder_eigenaar'] ?></b><span>zonder eigenaar</span></a>
    <a href="opschonen.php?bak=laat"<?= $c['te_laat'] ? ' class="rood"' : '' ?>><b><?= (int)$c['te_laat'] ?></b><span>over de deadline</span></a>
    <a href="opschonen.php?bak=pipedrive"><b><?= $nPd ?></b><span>nog uit Pipedrive</span></a>
    <a href="opschonen.php?bak=project"><b><?= (int)$c['zonder_project'] ?></b><span>zonder project</span></a>
    <div><b><?= (int)$c['open'] ?></b><span>open in totaal</span></div>
  </div>
</section>

<section class="card">
  <div class="row between wrap">
    <h2><?= e(['voorstellen' => 'Voorstellen van Claude', 'eigenaar' => 'Zonder eigenaar', 'laat' => 'Over de deadline', 'pipedrive' => 'Nog uit Pipedrive', 'project' => 'Zonder project'][$bak]) ?> (<?= count($taken) ?>)</h2>
    <?php if ($bak === 'voorstellen' && $taken): ?>
      <form method="post" onsubmit="return confirm('Alle <?= count($taken) ?> voorstellen hieronder uitvoeren?')"><?= csrf_field() ?><input type="hidden" name="actie" value="alle_akkoord">
        <?php foreach ($taken as $t): ?><input type="hidden" name="ids[]" value="<?= (int)$t['vid'] ?>"><?php endforeach; ?>
        <button class="btn primary small">Alle voorstellen akkoord</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$taken): ?><p class="muted"><?= $bak === 'voorstellen' ? 'Geen open voorstellen. Claude zet ze klaar bij de grote run.' : 'Niets meer in deze lijst.' ?></p><?php endif; ?>
  <div class="tablewrap"><table class="opschoon">
    <?php if ($taken): ?><thead><tr><th>Taak</th><th>Eigenaar</th><th>Deadline</th><th></th></tr></thead><?php endif; ?>
    <tbody>
    <?php foreach ($taken as $t): ?>
      <tr id="t<?= (int)$t['id'] ?>">
        <td><a href="taak.php?id=<?= (int)$t['id'] ?>"><?= e($t['titel']) ?></a>
          <br><span class="small muted"><?= $t['nummer'] ? e($t['nummer']) . ' · ' : '' ?><?= e($t['bron']) ?><?= $t['extern_id'] ? ' · ' . e($t['extern_id']) : '' ?> · sinds <?= e(substr(fmt_dt($t['aangemaakt']), 0, 10)) ?></span>
          <?php if ($t['vid']): ?><div class="voorstel small"><b>Claude: <?= e($vtekst($t)) ?></b><br><?= e($t['vreden']) ?>
            <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['vid'] ?>"><input type="hidden" name="taak" value="<?= (int)$t['id'] ?>">
              <button class="btn small primary" name="actie" value="akkoord">Akkoord</button><button class="btn small ghost" name="actie" value="afwijzen">Afwijzen</button></form></div><?php endif; ?>
        </td>
        <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="taak" value="<?= (int)$t['id'] ?>"><input type="hidden" name="actie" value="eigenaar">
          <select name="eigenaar" onchange="this.form.submit()" aria-label="Eigenaar"><option value=""><?= e($t['eigenaar'] ?: 'Kies…') ?></option>
          <?php foreach ($mensen as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['naam']) ?></option><?php endforeach; ?></select></form></td>
        <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="taak" value="<?= (int)$t['id'] ?>"><input type="hidden" name="actie" value="deadline">
          <input type="date" name="deadline" value="<?= e($t['deadline'] ?? '') ?>" onchange="this.form.submit()" aria-label="Deadline" class="<?= taak_te_laat($t) ? 'rood' : '' ?>"></form></td>
        <td class="actions"><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="taak" value="<?= (int)$t['id'] ?>">
          <button class="btn small" name="actie" value="klaar" title="Is gedaan">Klaar</button>
          <button class="btn small ghost" name="actie" value="vervalt" title="Niet meer nodig">Vervalt</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php page_foot();
