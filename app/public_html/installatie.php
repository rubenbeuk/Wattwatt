<?php
// Installatieregister (28-11-2026): wat staat er op een locatie (batterij, omvormer, EMS, meter, laadpaal), met serienummer, firmware, garantie en monitoring.
// Monteurs vullen het op locatie aan; tickets en alarmen hangen aan een installatie. Wachtwoorden horen hier niet: die gaan in de kluis.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$magBewerken = is_admin() || rol() === 'monteur';

$i = !empty($_GET['id'] ?? $_POST['id'] ?? '') ? q('SELECT * FROM installaties WHERE id = ?', [(int)($_GET['id'] ?? $_POST['id'])])->fetch() : null;
$nr = project_nummer($_GET['nr'] ?? $_POST['nr'] ?? '');
$pr = $nr !== '' ? q('SELECT * FROM projecten WHERE nummer = ?', [$nr])->fetch() : ($i && $i['project_id'] ? q('SELECT * FROM projecten WHERE id = ?', [$i['project_id']])->fetch() : null);
$locId = (int)($i['locatie_id'] ?? ($_GET['locatie'] ?? $_POST['locatie'] ?? 0)) ?: ($pr ? project_koppel_locatie((int)$pr['id']) : null);
$loc = $locId ? q('SELECT l.*, k.naam klantnaam FROM locaties l LEFT JOIN klanten k ON k.id = l.klant_id WHERE l.id = ?', [$locId])->fetch() : null;
if (!$i && !$pr && !$loc) { flash('Kies eerst een project of een locatie.', 'err'); redirect('projecten.php'); }
if (is_onderaannemer()) { http_response_code(403); exit('Geen toegang.'); }
$terug = $pr ? 'project.php?nr=' . rawurlencode($pr['nummer']) . '#installatie' : ($loc && $loc['klant_id'] && (is_admin() || is_verkoop()) ? 'klant.php?id=' . (int)$loc['klant_id'] : 'projecten.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$magBewerken) { http_response_code(403); exit('Alleen kantoor en monteurs passen installaties aan.'); }
    $d = array_intersect_key($_POST, array_flip(INSTALLATIE_VELDEN));
    if ($i) $d['id'] = $i['id'];
    if ($pr) $d['project'] = $pr['nummer'];
    if ($locId) $d['locatie_id'] = $locId;
    if (preg_match('/\b(wachtwoord|password|pass|ww)\s*[:=]\s*\S+/i', (string)($d['notitie'] ?? ''))) { flash('Zet geen wachtwoorden in het register; die gaan in de wachtwoordkluis.', 'err'); redirect($_SERVER['REQUEST_URI']); }
    $r = installatie_opslaan($d, $u['naam']);
    if (isset($r['fout'])) { flash(ucfirst($r['fout']) . '.', 'err'); redirect($_SERVER['REQUEST_URI']); }
    if ($pr && $r['nieuw']) q("INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst) VALUES (?, ?, 'app', ?, ?, NULL)",
        [$pr['id'], date('Y-m-d H:i:s'), $u['naam'], mb_substr('Installatie toegevoegd: ' . clean_text(($d['merk'] ?? '') . ' ' . ($d['type'] ?? '') . ' ' . ($d['serienummer'] ?? '')), 0, 255)]);
    flash($r['nieuw'] ? 'Installatie toegevoegd.' : 'Installatie opgeslagen.');
    redirect(isset($_POST['nog_een']) ? 'installatie.php?' . ($pr ? 'nr=' . rawurlencode($pr['nummer']) : 'locatie=' . (int)$locId) : $terug);
}

$tickets = $i ? q("SELECT nummer, omschrijving, fase, soort, startdatum FROM projecten WHERE installatie_id = ? ORDER BY id DESC LIMIT 20", [$i['id']])->fetchAll() : [];
$v = $i ?: ['soort' => $_GET['soort'] ?? 'batterij', 'status' => 'actief', 'geplaatst_op' => $pr['opleverdatum'] ?? null];
$merken = array_column(q("SELECT DISTINCT merk FROM installaties WHERE merk <> '' ORDER BY merk LIMIT 100")->fetchAll(), 'merk');
$monitoring = array_column(q("SELECT DISTINCT monitoring FROM installaties WHERE monitoring <> '' ORDER BY monitoring LIMIT 50")->fetchAll(), 'monitoring');

page_head($i ? 'Installatie' : 'Installatie toevoegen', 'projecten');
?>
<section class="card narrow">
  <div class="small muted"><?php if ($pr): ?><a href="<?= e($terug) ?>"><?= e($pr['nummer']) ?> <?= e(project_naam($pr)) ?></a><?php endif; ?><?= $loc ? ($pr ? ' · ' : '') . e(locatie_tekst($loc)) . ($loc['klantnaam'] ? ' · ' . e($loc['klantnaam']) : '') : '' ?></div>
  <h1><?= $i ? e(installatie_naam($i)) : 'Installatie toevoegen' ?></h1>
  <?php if ($magBewerken): ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <?php if ($i): ?><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><?php endif; ?>
    <?php if ($pr): ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><?php endif; ?>
    <?php if ($locId): ?><input type="hidden" name="locatie" value="<?= (int)$locId ?>"><?php endif; ?>
    <div class="row wrap">
      <label class="grow">Soort <select name="soort"><?php foreach (INSTALLATIE_SOORTEN as $k => $n): ?><option value="<?= $k ?>" <?= ($v['soort'] ?? '') === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Status <select name="status"><?php foreach (INSTALLATIE_STATUS as $k => $n): ?><option value="<?= $k ?>" <?= ($v['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="row wrap">
      <label class="grow">Merk <input name="merk" list="merken" value="<?= e($v['merk'] ?? '') ?>" placeholder="bijv. Hoymiles, Dyness, Victron"></label>
      <label class="grow">Type <input name="type" value="<?= e($v['type'] ?? '') ?>" placeholder="bijv. HYS-125LV-EUG1"></label>
    </div>
    <label>Serienummer <input name="serienummer" value="<?= e($v['serienummer'] ?? '') ?>" autocapitalize="characters" autocomplete="off"></label>
    <div class="row wrap">
      <label class="grow">Vermogen of capaciteit <input name="vermogen" value="<?= e($v['vermogen'] ?? '') ?>" placeholder="bijv. 125 kW of 261 kWh"></label>
      <label class="grow">Firmware <input name="firmware" value="<?= e($v['firmware'] ?? '') ?>"></label>
    </div>
    <div class="row wrap">
      <label class="grow">Geplaatst op <input type="date" name="geplaatst_op" value="<?= e($v['geplaatst_op'] ?? '') ?>"></label>
      <label class="grow">Garantie tot <input type="date" name="garantie_tot" value="<?= e($v['garantie_tot'] ?? '') ?>"></label>
    </div>
    <div class="row wrap">
      <label class="grow">Monitoring <input name="monitoring" list="monitoring" value="<?= e($v['monitoring'] ?? '') ?>" placeholder="bijv. Teleport, Eniris, S-Miles"></label>
      <label class="grow">Monitoring-ID <input name="monitoring_id" value="<?= e($v['monitoring_id'] ?? '') ?>" placeholder="site- of apparaat-ID in dat portaal"></label>
    </div>
    <label>Notitie <textarea name="notitie" rows="3" placeholder="bijzonderheden; geen wachtwoorden"><?= e($v['notitie'] ?? '') ?></textarea></label>
    <div class="row wrap"><button class="btn primary">Opslaan</button><?php if (!$i): ?><button class="btn" name="nog_een" value="1">Opslaan en nog een</button><?php endif; ?><a class="btn ghost" href="<?= e($terug) ?>">Terug</a></div>
    <datalist id="merken"><?php foreach ($merken as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
    <datalist id="monitoring"><?php foreach (array_unique(array_merge(['Teleport', 'Eniris', 'S-Miles', 'Victron VRM', 'SolarEdge'], $monitoring)) as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
  </form>
  <?php else: ?>
  <dl class="pdetails">
    <?php foreach (['Merk' => 'merk', 'Type' => 'type', 'Serienummer' => 'serienummer', 'Vermogen' => 'vermogen', 'Firmware' => 'firmware', 'Monitoring' => 'monitoring', 'Monitoring-ID' => 'monitoring_id'] as $l => $k): ?>
      <dt><?= $l ?></dt><dd><?= e(($v[$k] ?? '') ?: '–') ?></dd><?php endforeach; ?>
    <dt>Garantie tot</dt><dd><?= !empty($v['garantie_tot']) ? e(datum_lang($v['garantie_tot'])) : '–' ?></dd>
  </dl>
  <?php endif; ?>
  <?php if ($i): ?><p class="small muted">Laatst bijgewerkt door <?= e($i['bijgewerkt_door'] ?: '–') ?>, <?= e(fmt_dt($i['bijgewerkt'])) ?>.</p><?php endif; ?>
</section>
<?php if ($tickets): ?>
<section class="card narrow"><h2>Tickets op deze installatie</h2>
  <ul class="plijst"><?php foreach ($tickets as $t): ?><li><a href="project.php?nr=<?= e(rawurlencode($t['nummer'])) ?>"><b><?= e($t['nummer']) ?></b></a> <?= e($t['omschrijving']) ?> <span class="small muted">· <?= e(fase_naam($t['fase'], $t['soort'])) ?></span></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php page_foot();
