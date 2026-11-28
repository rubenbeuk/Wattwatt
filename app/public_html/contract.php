<?php
// Onderhoudscontract (ronde 3, 28-11-2026): interval voor periodiek onderhoud, reactietijd bij storingen en de prijs per jaar.
// De app maakt het onderhoudsticket zelf een maand van tevoren; nieuwe tickets op de locatie krijgen de reactietijd mee.
require __DIR__ . '/inc/bootstrap.php';
$u = require_rol(['admin', 'verkoop']);
$magBewerken = is_admin();

$c = !empty($_GET['id'] ?? $_POST['id'] ?? '') ? q('SELECT * FROM contracten WHERE id = ?', [(int)($_GET['id'] ?? $_POST['id'])])->fetch() : null;
$kid = (int)($c['klant_id'] ?? ($_GET['klant'] ?? $_POST['klant_id'] ?? 0));
$k = $kid ? q('SELECT * FROM klanten WHERE id = ?', [$kid])->fetch() : null;
if (!$k) { flash('Kies eerst een klant.', 'err'); redirect('klanten.php'); }
$terug = 'klant.php?id=' . (int)$k['id'] . '#contracten';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$magBewerken) { http_response_code(403); exit('Alleen kantoor past contracten aan.'); }
    $d = array_intersect_key($_POST, array_flip(CONTRACT_VELDEN));
    $d['klant_id'] = $k['id'];
    if ($c) $d['id'] = $c['id'];
    $r = contract_opslaan($d, $u['naam']);
    if (isset($r['fout'])) { flash(ucfirst($r['fout']) . '.', 'err'); redirect($_SERVER['REQUEST_URI']); }
    $maak = contracten_onderhoud_maken($u['naam']);
    flash(($r['nieuw'] ? 'Contract toegevoegd.' : 'Contract opgeslagen.') . ($maak ? ' Onderhoudsticket aangemaakt: ' . implode(', ', $maak) . '.' : ''));
    redirect($terug);
}

$v = $c ?: ['soort' => 'onderhoud', 'status' => 'actief', 'interval_maanden' => 12, 'startdatum' => vandaag(), 'locatie_id' => (int)($_GET['locatie'] ?? 0) ?: null];
$locaties = q('SELECT * FROM locaties WHERE klant_id = ? ORDER BY id', [$k['id']])->fetchAll();
$installaties = q("SELECT i.* FROM installaties i JOIN locaties l ON l.id = i.locatie_id WHERE l.klant_id = ? AND i.status = 'actief' ORDER BY i.locatie_id, i.id", [$k['id']])->fetchAll();
$tickets = $c ? q("SELECT nummer, omschrijving, fase, soort, startdatum, einddatum, labels, reactie_voor, gereageerd_op FROM projecten WHERE contract_id = ? ORDER BY id DESC LIMIT 30", [$c['id']])->fetchAll() : [];

page_head($c ? 'Contract' : 'Contract toevoegen', 'klanten');
?>
<section class="card narrow">
  <div class="small muted"><a href="<?= e($terug) ?>"><?= e($k['naam']) ?></a></div>
  <h1><?= $c ? e(contract_naam($c)) : 'Contract toevoegen' ?></h1>
  <?php if ($magBewerken): ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="klant_id" value="<?= (int)$k['id'] ?>">
    <?php if ($c): ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><?php endif; ?>
    <label>Omschrijving <input name="omschrijving" value="<?= e($v['omschrijving'] ?? '') ?>" placeholder="bijv. Onderhoud batterijsysteem, jaarlijks"></label>
    <div class="row wrap">
      <label class="grow">Soort <select name="soort"><?php foreach (CONTRACT_SOORTEN as $s => $n): ?><option value="<?= $s ?>" <?= ($v['soort'] ?? '') === $s ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Status <select name="status"><?php foreach (CONTRACT_STATUS as $s => $n): ?><option value="<?= $s ?>" <?= ($v['status'] ?? '') === $s ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="row wrap">
      <label class="grow">Locatie <select name="locatie_id"><option value="">Alle locaties van de klant</option><?php foreach ($locaties as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)($v['locatie_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e(locatie_tekst($l)) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Installatie (optioneel) <select name="installatie_id"><option value="">Hele locatie</option><?php foreach ($installaties as $i): ?><option value="<?= (int)$i['id'] ?>" <?= (int)($v['installatie_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>><?= e(installatie_naam($i)) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="row wrap">
      <label class="grow">Ingang <input type="date" name="startdatum" value="<?= e($v['startdatum'] ?? '') ?>"></label>
      <label class="grow">Einde (leeg = doorlopend) <input type="date" name="einddatum" value="<?= e($v['einddatum'] ?? '') ?>"></label>
    </div>
    <div class="row wrap">
      <label class="grow">Onderhoud elke … maanden <input type="number" name="interval_maanden" min="0" max="60" value="<?= (int)($v['interval_maanden'] ?? 12) ?>"><span class="small muted">0 = geen periodiek onderhoud</span></label>
      <label class="grow">Volgende onderhoud <input type="date" name="volgende_onderhoud" value="<?= e($v['volgende_onderhoud'] ?? '') ?>"><span class="small muted">leeg = ingang + interval</span></label>
    </div>
    <div class="row wrap">
      <label class="grow">Reactietijd bij storing (uren) <input type="number" name="reactietijd_uren" min="0" max="2000" value="<?= e((string)($v['reactietijd_uren'] ?? '')) ?>" placeholder="bijv. 4, 24 of 48"></label>
      <label class="grow">Prijs per jaar (excl. btw) <input name="prijs_jaar" inputmode="decimal" value="<?= isset($v['prijs_jaar']) && $v['prijs_jaar'] !== null ? e(str_replace('.', ',', (string)(float)$v['prijs_jaar'])) : '' ?>"></label>
    </div>
    <label>Afspraken <textarea name="notitie" rows="3" placeholder="wat valt eronder, wat niet; wie bellen"><?= e($v['notitie'] ?? '') ?></textarea></label>
    <div class="row wrap"><button class="btn primary">Opslaan</button><a class="btn ghost" href="<?= e($terug) ?>">Terug</a></div>
    <p class="small muted">Een maand voor de onderhoudsdatum zet de app een onderhoudsticket op het servicebord en krijgt kantoor een seintje. Nieuwe storingen op deze locatie krijgen de reactietijd mee.</p>
  </form>
  <?php else: ?>
  <dl class="pdetails">
    <dt>Soort</dt><dd><?= e(CONTRACT_SOORTEN[$v['soort']] ?? $v['soort']) ?> · <?= e(CONTRACT_STATUS[$v['status']] ?? $v['status']) ?></dd>
    <dt>Looptijd</dt><dd><?= $v['startdatum'] ? e(date('d-m-Y', strtotime($v['startdatum']))) : '–' ?> tot <?= $v['einddatum'] ? e(date('d-m-Y', strtotime($v['einddatum']))) : 'doorlopend' ?></dd>
    <dt>Onderhoud</dt><dd><?= (int)$v['interval_maanden'] ? 'elke ' . (int)$v['interval_maanden'] . ' maanden' . ($v['volgende_onderhoud'] ? ', volgende ' . e(datum_lang($v['volgende_onderhoud'])) : '') : 'geen' ?></dd>
    <dt>Reactietijd</dt><dd><?= $v['reactietijd_uren'] ? (int)$v['reactietijd_uren'] . ' uur' : '–' ?></dd>
  </dl>
  <?php endif; ?>
  <?php if ($c): ?><p class="small muted">Laatst bijgewerkt door <?= e($c['bijgewerkt_door'] ?: '–') ?>, <?= e(fmt_dt($c['bijgewerkt'])) ?>.</p><?php endif; ?>
</section>
<?php if ($tickets): ?>
<section class="card narrow"><h2>Tickets onder dit contract</h2>
  <ul class="plijst"><?php foreach ($tickets as $t): $rs = reactie_status($t); ?><li><a href="project.php?nr=<?= e(rawurlencode($t['nummer'])) ?>"><b><?= e($t['nummer']) ?></b></a> <?= e($t['omschrijving']) ?> <span class="small muted">· <?= e(fase_naam($t['fase'], $t['soort'])) ?></span><?= $rs ? ' <span class="small ' . $rs['klasse'] . '">· ' . e($rs['tekst']) . '</span>' : '' ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php page_foot();
