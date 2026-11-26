<?php
// Uren: iedereen schrijft per dag op welk project of ticket hij werkte. Kantoor ziet het overzicht per week en per project en kan het downloaden.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$week = maandag_van(geldige_datum($_GET['week'] ?? '') ?? vandaag());
$wieId = is_admin() && !empty($_GET['wie']) ? (int)$_GET['wie'] : (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['actie'] ?? '') === 'boek') {
        $datum = geldige_datum($_POST['datum'] ?? '') ?? vandaag();
        $n = (float)str_replace(',', '.', (string)($_POST['uren'] ?? '0'));
        $pid = ($_POST['project'] ?? '') !== '' ? (q('SELECT id FROM projecten WHERE nummer = ?', [project_nummer((string)$_POST['project'])])->fetch()['id'] ?? null) : null;
        $voor = is_admin() && !empty($_POST['wie']) ? (int)$_POST['wie'] : (int)$u['id'];
        if ($n <= 0 || $n > 16) flash('Vul tussen 0 en 16 uur in.', 'err');
        elseif ($datum > vandaag()) flash('Uren schrijf je achteraf.', 'err');
        else {
            q('INSERT INTO uren (gebruiker_id, project_id, datum, uren, soort, omschrijving) VALUES (?,?,?,?,?,?)',
                [$voor, $pid, $datum, round($n * 4) / 4, in_array($_POST['soort'] ?? '', ['werk', 'reis', 'overig'], true) ? $_POST['soort'] : 'werk', mb_substr(clean_text($_POST['omschrijving'] ?? ''), 0, 255)]);
            flash('Uren opgeslagen.');
        }
    } elseif (($_POST['actie'] ?? '') === 'weg') {
        q('DELETE FROM uren WHERE id = ?' . (is_admin() ? '' : ' AND gebruiker_id = ' . (int)$u['id']), [(int)($_POST['id'] ?? 0)]);
        flash('Regel verwijderd.');
    }
    redirect('uren.php?week=' . $week . ($wieId !== (int)$u['id'] ? '&wie=' . $wieId : ''));
}

$eind = dag_plus($week, 6);
if (isset($_GET['csv']) && is_admin()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uren-' . $week . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['datum', 'medewerker', 'project', 'projectnaam', 'soort', 'uren', 'omschrijving'], ';');
    foreach (q('SELECT u.*, g.naam, p.nummer, p.omschrijving pnaam FROM uren u JOIN gebruikers g ON g.id = u.gebruiker_id LEFT JOIN projecten p ON p.id = u.project_id WHERE u.datum BETWEEN ? AND ? ORDER BY u.datum, g.naam', [$week, $eind])->fetchAll() as $r)
        fputcsv($f, [$r['datum'], $r['naam'], $r['nummer'], $r['pnaam'], $r['soort'], str_replace('.', ',', $r['uren']), $r['omschrijving']], ';');
    exit;
}

$regels = q('SELECT u.*, p.nummer, p.omschrijving pnaam FROM uren u LEFT JOIN projecten p ON p.id = u.project_id WHERE u.gebruiker_id = ? AND u.datum BETWEEN ? AND ? ORDER BY u.datum, u.id', [$wieId, $week, $eind])->fetchAll();
$totaal = array_sum(array_column($regels, 'uren'));
// Voorinvullen: het project uit de planning van vandaag
$vandaagPlan = q('SELECT p.nummer FROM planning pl JOIN projecten p ON p.id = pl.project_id WHERE pl.gebruiker_id = ? AND pl.datum = ? ORDER BY pl.dagdeel LIMIT 1', [$u['id'], vandaag()])->fetch()['nummer'] ?? '';
$perProject = is_admin() ? q('SELECT p.nummer, p.omschrijving, SUM(u.uren) uren, COUNT(DISTINCT u.gebruiker_id) mensen FROM uren u LEFT JOIN projecten p ON p.id = u.project_id WHERE u.datum BETWEEN ? AND ? GROUP BY u.project_id ORDER BY uren DESC', [$week, $eind])->fetchAll() : [];
$perMens = is_admin() ? q('SELECT g.id, g.naam, SUM(u.uren) uren FROM uren u JOIN gebruikers g ON g.id = u.gebruiker_id WHERE u.datum BETWEEN ? AND ? GROUP BY g.id ORDER BY g.naam', [$week, $eind])->fetchAll() : [];
$projecten = q("SELECT nummer, omschrijving FROM projecten WHERE afgerond = 0 ORDER BY id DESC LIMIT 300")->fetchAll();
$wieNaam = q('SELECT naam FROM gebruikers WHERE id = ?', [$wieId])->fetch()['naam'] ?? '';

page_head('Uren', 'uren');
?>
<section class="card">
  <div class="row between wrap">
    <h1>Uren · week <?= weeknr($week) ?><?= $wieId !== (int)$u['id'] ? ' · ' . e($wieNaam) : '' ?></h1>
    <div class="row wrap">
      <a class="btn ghost" href="uren.php?week=<?= dag_plus($week, -7) ?><?= $wieId !== (int)$u['id'] ? '&wie=' . $wieId : '' ?>">← vorige</a>
      <a class="btn ghost" href="uren.php?week=<?= dag_plus($week, 7) ?><?= $wieId !== (int)$u['id'] ? '&wie=' . $wieId : '' ?>">volgende →</a>
      <?php if (is_admin()): ?><a class="btn ghost" href="uren.php?week=<?= $week ?>&csv=1">Download (Excel)</a><?php endif; ?>
    </div>
  </div>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="actie" value="boek">
    <div class="row wrap">
      <label class="grow">Datum <input type="date" name="datum" value="<?= e(vandaag()) ?>" max="<?= e(vandaag()) ?>"></label>
      <label class="grow">Project of ticket <input name="project" list="projectlijst" value="<?= e($vandaagPlan) ?>" placeholder="PRJ- of WW-nummer"></label>
      <label>Uren <input name="uren" inputmode="decimal" required placeholder="8" style="max-width:6rem"></label>
      <label>Soort <select name="soort"><option value="werk">Werk</option><option value="reis">Reistijd</option><option value="overig">Overig</option></select></label>
      <?php if (is_admin()): ?><label>Voor <select name="wie"><?php foreach (q('SELECT id, naam FROM gebruikers WHERE actief = 1 ORDER BY volgorde, naam')->fetchAll() as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $wieId ? 'selected' : '' ?>><?= e($m['naam']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    </div>
    <label>Wat (kort) <input name="omschrijving" placeholder="bijv. kabels getrokken, inbedrijfstelling"></label>
    <button class="btn primary">Opslaan</button>
    <datalist id="projectlijst"><?php foreach ($projecten as $p): ?><option value="<?= e($p['nummer']) ?>"><?= e($p['omschrijving']) ?></option><?php endforeach; ?></datalist>
  </form>
</section>

<section class="card">
  <h2><?= $wieId === (int)$u['id'] ? 'Mijn uren' : e($wieNaam) ?> deze week: <?= str_replace('.', ',', (string)(float)$totaal) ?> uur</h2>
  <table><tbody>
  <?php foreach ($regels as $r): ?>
    <tr><td><?= e(datum_kort($r['datum'])) ?></td><td><?= $r['nummer'] ? '<a href="project.php?nr=' . e(rawurlencode($r['nummer'])) . '">' . e($r['nummer']) . '</a> ' . e(mb_strimwidth((string)$r['pnaam'], 0, 40, '…')) : '<span class="muted">geen project</span>' ?></td>
      <td><?= str_replace('.', ',', (string)(float)$r['uren']) ?> u<?= $r['soort'] !== 'werk' ? ' <span class="tag">' . e($r['soort']) . '</span>' : '' ?></td><td class="small muted"><?= e($r['omschrijving']) ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="actie" value="weg"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn small ghost">×</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$regels): ?><tr><td class="muted">Nog niets geschreven deze week.</td></tr><?php endif; ?>
  </tbody></table>
</section>

<?php if (is_admin()): ?>
<section class="card">
  <h2>Iedereen deze week</h2>
  <div class="row wrap" style="align-items:flex-start;gap:2rem">
    <table style="width:auto"><thead><tr><th>Medewerker</th><th>Uren</th></tr></thead><tbody>
      <?php foreach ($perMens as $m): ?><tr><td><a href="uren.php?week=<?= $week ?>&wie=<?= (int)$m['id'] ?>"><?= e($m['naam']) ?></a></td><td><?= str_replace('.', ',', (string)(float)$m['uren']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <table style="width:auto"><thead><tr><th>Project</th><th>Uren</th><th>Mensen</th></tr></thead><tbody>
      <?php foreach ($perProject as $p): ?><tr><td><?= $p['nummer'] ? e($p['nummer'] . ' ' . mb_strimwidth((string)$p['omschrijving'], 0, 40, '…')) : 'geen project' ?></td><td><?= str_replace('.', ',', (string)(float)$p['uren']) ?></td><td><?= (int)$p['mensen'] ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
</section>
<?php endif; ?>
<?php page_foot();
