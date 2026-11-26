<?php
// Agenda: afspraken met een vast tijdstip (bellen, schouw, keuring, overleg). Vervangt de activiteiten in Pipedrive.
// Elke medewerker kan de eigen agenda (of die van iedereen) als abonnement in Outlook of op de iPhone zetten.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    try {
        if ($actie === 'nieuw' || $actie === 'wijzig') {
            $datum = geldige_datum($_POST['datum'] ?? '');
            $heleDag = isset($_POST['hele_dag']) || trim((string)($_POST['van'] ?? '')) === '';
            $wie = is_admin() ? ($_POST['wie'] ?? '') : $u['gebruikersnaam'];
            afspraak_opslaan([
                'id' => $actie === 'wijzig' ? (int)($_POST['id'] ?? 0) : 0,
                'titel' => $_POST['titel'] ?? '', 'wie' => $wie, 'project' => $_POST['project'] ?? '',
                'start' => $datum . ($heleDag ? '' : ' ' . ($_POST['van'] ?? '')), 'hele_dag' => $heleDag,
                'eind' => !$heleDag && ($_POST['tot'] ?? '') !== '' ? $datum . ' ' . $_POST['tot'] : null,
                'locatie' => $_POST['locatie'] ?? '', 'notitie' => $_POST['notitie'] ?? '', 'bron' => 'app',
            ], $u['naam']);
            flash('Afspraak opgeslagen.');
        } elseif ($actie === 'annuleer') {
            $a = q('SELECT * FROM afspraken WHERE id = ?', [(int)($_POST['id'] ?? 0)])->fetch();
            if ($a && (is_admin() || (int)$a['gebruiker_id'] === (int)$u['id'])) { q('UPDATE afspraken SET geannuleerd = 1 WHERE id = ?', [$a['id']]); flash('Afspraak afgezegd.'); }
        } elseif ($actie === 'nieuwe_link') {
            agenda_token((int)$u['id'], true);
            flash('Nieuwe agenda-link gemaakt. De oude werkt niet meer; zet de nieuwe in Outlook of op je telefoon.');
        }
    } catch (InvalidArgumentException $e) { flash('Niet opgeslagen: ' . e($e->getMessage()), 'err'); }
    redirect('agenda.php' . (isset($_GET['alle']) ? '?alle' : ''));
}

$alle = is_admin() && isset($_GET['alle']);
$van = date('Y-m-d 00:00:00', strtotime('-1 day'));
$rows = q("SELECT a.*, g.naam, p.nummer, p.omschrijving AS pnaam FROM afspraken a
           LEFT JOIN gebruikers g ON g.id = a.gebruiker_id LEFT JOIN projecten p ON p.id = a.project_id
           WHERE a.geannuleerd = 0 AND COALESCE(a.eind, a.start) >= ?" . ($alle ? '' : ' AND (a.gebruiker_id = ? OR a.gebruiker_id IS NULL)') . '
           ORDER BY a.start LIMIT 300', $alle ? [$van] : [$van, $u['id']])->fetchAll();
$perDag = [];
foreach ($rows as $r) $perDag[substr($r['start'], 0, 10)][] = $r;
$token = agenda_token((int)$u['id']);
$basis = (APP_URL ?: ('https://' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''))) . '/agenda_ics.php?t=' . $token;
$projecten = is_admin() ? q("SELECT nummer, omschrijving FROM projecten WHERE afgerond = 0 ORDER BY id DESC LIMIT 300")->fetchAll() : [];

page_head('Agenda', 'agenda');
?>
<section class="card">
  <div class="row between wrap">
    <h1>Agenda<?= $alle ? ' · iedereen' : '' ?></h1>
    <div class="row wrap">
      <?php if (is_admin()): ?><a class="btn ghost" href="agenda.php<?= $alle ? '' : '?alle' ?>"><?= $alle ? 'Alleen mijn afspraken' : 'Iedereen' ?></a><?php endif; ?>
      <button type="button" class="btn primary" onclick="document.getElementById('nieuweAfspraak').hidden = !document.getElementById('nieuweAfspraak').hidden">Nieuwe afspraak</button>
    </div>
  </div>
  <form method="post" class="stack" id="nieuweAfspraak" hidden>
    <?= csrf_field() ?><input type="hidden" name="actie" value="nieuw">
    <label>Wat <input name="titel" required placeholder="bijv. Renze terugbellen over graafwerk"></label>
    <div class="row wrap">
      <label class="grow">Datum <input type="date" name="datum" required value="<?= e(vandaag()) ?>"></label>
      <label class="grow">Van <input type="time" name="van"></label>
      <label class="grow">Tot <input type="time" name="tot"></label>
      <label class="check"><input type="checkbox" name="hele_dag"> Hele dag</label>
    </div>
    <div class="row wrap">
      <?php if (is_admin()): ?>
      <label class="grow">Voor <select name="wie"><?php foreach (q("SELECT naam, gebruikersnaam FROM gebruikers WHERE actief = 1 ORDER BY volgorde, naam")->fetchAll() as $m): ?><option value="<?= e($m['gebruikersnaam']) ?>" <?= $m['gebruikersnaam'] === $u['gebruikersnaam'] ? 'selected' : '' ?>><?= e($m['naam']) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Project of ticket (mag leeg) <input name="project" list="projectlijst" placeholder="PRJ-nummer of WW-nummer"></label>
      <?php endif; ?>
      <label class="grow">Waar <input name="locatie" placeholder="adres, of Teams/telefoon"></label>
    </div>
    <label>Notitie <textarea name="notitie" rows="2"></textarea></label>
    <button class="btn primary">Opslaan</button>
    <datalist id="projectlijst"><?php foreach ($projecten as $p): ?><option value="<?= e($p['nummer']) ?>"><?= e($p['omschrijving']) ?></option><?php endforeach; ?></datalist>
  </form>
</section>

<section class="card">
  <?php if (!$perDag): ?><p class="muted">Geen afspraken gepland.</p><?php endif; ?>
  <?php foreach ($perDag as $dag => $lijst): ?>
    <h2 class="<?= $dag === vandaag() ? '' : 'small' ?>"><?= $dag === vandaag() ? 'Vandaag' : e(ucfirst(datum_lang($dag))) ?></h2>
    <ul class="agendalijst" style="list-style:none;margin:0 0 .8rem;padding:0">
      <?php foreach ($lijst as $a): ?>
      <li class="row between" style="padding:.5rem 0;border-bottom:1px solid var(--line)">
        <div>
          <b><?= $a['hele_dag'] ? 'Hele dag' : e(substr($a['start'], 11, 5) . ($a['eind'] ? '–' . substr($a['eind'], 11, 5) : '')) ?></b>
          <?= e($a['titel']) ?>
          <?php $meta = array_filter([$alle && $a['naam'] ? e(explode(' ', $a['naam'])[0]) : '', $a['nummer'] ? '<a href="project.php?nr=' . e(rawurlencode($a['nummer'])) . '">' . e($a['nummer']) . '</a>' : '', e($a['locatie'])]); ?>
          <?php if ($meta || $a['notitie']): ?><div class="small muted"><?= implode(' · ', $meta) ?><?= $a['notitie'] ? ($meta ? '<br>' : '') . nl2br(e($a['notitie'])) : '' ?></div><?php endif; ?>
        </div>
        <?php if (is_admin() || (int)$a['gebruiker_id'] === (int)$u['id']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="actie" value="annuleer"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn small ghost">Afzeggen</button></form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>In Outlook of op je telefoon</h2>
  <p class="small muted">Zet je afspraken uit de app automatisch in je eigen agenda. Outlook: Agenda toevoegen → Abonneren via internet → plak de link. iPhone: Instellingen → Agenda → Accounts → Voeg account toe → Overig → Voeg agenda-abonnement toe. Outlook ververst ongeveer elke paar uur.</p>
  <label>Jouw agenda-link <input readonly value="<?= e($basis) ?>" onclick="this.select()"></label>
  <?php if (is_admin()): ?><label>Agenda van iedereen <input readonly value="<?= e($basis . '&alle=1') ?>" onclick="this.select()"></label><?php endif; ?>
  <form method="post" class="mt"><?= csrf_field() ?><input type="hidden" name="actie" value="nieuwe_link"><button class="btn small ghost">Nieuwe link maken (oude werkt dan niet meer)</button></form>
</section>
<?php page_foot();
