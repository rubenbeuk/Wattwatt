<?php
// Opleverdossier per project: serienummers, netwerk, EMS, kabel, gebruikt materiaal, foto's en de afvinklijst.
// Monteurs vullen het op locatie in (telefoon), kantoor ziet in één oogopslag wat er nog ontbreekt.
// Wachtwoorden horen hier NIET in: die gaan in de wachtwoordkluis.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$nr = project_nummer($_GET['nr'] ?? $_POST['nr'] ?? '');
$pr = q('SELECT * FROM projecten WHERE nummer = ?', [$nr])->fetch();
if (!$pr) { flash('Project niet gevonden.', 'err'); redirect('projecten.php'); }

const OPL_CHECKS = ['check_inbedrijf' => 'Inbedrijfstelling gedaan (met fabrikant als dat nodig is)', 'check_monitoring' => 'Monitoring/EMS online en zichtbaar op afstand',
    'check_uitleg' => 'Klant uitleg gegeven', 'check_keuring' => 'Keuring/meting gedaan (indien van toepassing)',
    'check_netbeheer' => 'Aangemeld bij de netbeheerder (energieleveren.nl; batterij en/of PV)'];
const OPL_TEKST = ['serienummers' => ['Serienummers', 'per apparaat: batterij, omvormer, EMS, laadpaal, meter'], 'netwerk' => ['Netwerk', 'IP-adressen, switch, router, hoe ingelogd (geen wachtwoorden: die gaan in de kluis)'],
    'kabel' => ['Kabel', 'soort en meters per tracé'], 'materiaal' => ['Gebruikt materiaal', 'wat er afwijkt van de materiaallijst of extra is gebruikt'], 'opmerkingen' => ['Opmerkingen', 'bijzonderheden, wat nog moet, afspraken met de klant']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = ['ems' => mb_substr(clean_text($_POST['ems'] ?? ''), 0, 255)];
    foreach (OPL_TEKST as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? '')) ?: null;
    foreach (OPL_CHECKS as $k => $_) $v[$k] = isset($_POST[$k]) ? 1 : 0;
    // Waarschuwing als iemand toch een wachtwoord intypt
    $wwVerdacht = preg_match('/\b(wachtwoord|password|pass|ww)\s*[:=]\s*\S+/i', implode("\n", array_filter([$v['netwerk'], $v['opmerkingen']])));
    $v['bijgewerkt_door'] = $u['naam'];
    $v['concept_door'] = '';  // een mens heeft het nagekeken
    $klaar = isset($_POST['afronden']);
    q('INSERT INTO opleveringen (project_id, ' . implode(', ', array_keys($v)) . ') VALUES (?' . str_repeat(', ?', count($v)) . ')
       ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($k) => "$k = VALUES($k)", array_keys($v))), [$pr['id'], ...array_values($v)]);
    if ($klaar) q('UPDATE opleveringen SET afgerond_op = COALESCE(afgerond_op, UTC_TIMESTAMP()) WHERE project_id = ?', [$pr['id']]);
    q("INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst) VALUES (?, ?, 'app', ?, ?, NULL)",
        [$pr['id'], date('Y-m-d H:i:s'), $u['naam'], $klaar ? 'Opleverdossier afgerond' : 'Opleverdossier bijgewerkt']);
    if ($klaar) seintje_project((int)$pr['id'], 'Opleverdossier afgerond: ' . project_naam($pr), 'oplevering.php?nr=' . rawurlencode($pr['nummer']), (int)$u['id']);
    flash($wwVerdacht ? 'Opgeslagen. Let op: er lijkt een wachtwoord in te staan. Zet wachtwoorden in de wachtwoordkluis en haal het hier weg.' : ($klaar ? 'Opleverdossier afgerond.' : 'Opgeslagen.'), $wwVerdacht ? 'err' : 'ok');
    redirect('oplevering.php?nr=' . rawurlencode($pr['nummer']));
}

$o = q('SELECT * FROM opleveringen WHERE project_id = ?', [$pr['id']])->fetch() ?: [];
$fotos = (int)q("SELECT COUNT(*) c FROM fotos WHERE project_id = ? AND soort = 'oplevering'", [$pr['id']])->fetch()['c'];
$ontbreekt = [];
foreach (['serienummers', 'netwerk'] as $k) if (empty($o[$k])) $ontbreekt[] = mb_strtolower(OPL_TEKST[$k][0]);
if (($o['ems'] ?? '') === '') $ontbreekt[] = 'EMS';
if (!$fotos) $ontbreekt[] = "opleverfoto's";
foreach (OPL_CHECKS as $k => $l) if (empty($o[$k])) $ontbreekt[] = mb_strtolower(trim(strtok($l, '(')));

page_head('Oplevering ' . $pr['nummer'], 'projecten');
?>
<section class="card narrow">
  <div class="small muted"><a href="project.php?nr=<?= e(rawurlencode($pr['nummer'])) ?>"><?= e($pr['nummer']) ?> <?= e(project_naam($pr)) ?></a></div>
  <h1>Opleverdossier</h1>
  <?php if (!empty($o['afgerond_op'])): ?><p class="flash ok">Afgerond op <?= e(date('d-m-Y', strtotime($o['afgerond_op'] . ' UTC'))) ?><?= $o['bijgewerkt_door'] ? ' · laatst bijgewerkt door ' . e($o['bijgewerkt_door']) : '' ?></p>
  <?php elseif ($ontbreekt): ?><p class="small oranje">Nog niet compleet: <?= e(implode(', ', $ontbreekt)) ?>.</p><?php endif; ?>
  <?php if (($o['concept_door'] ?? '') !== '' && empty($o['afgerond_op'])): ?><div class="flash warn">Claude heeft dit dossier alvast ingevuld uit de melding, het materiaal en de projectgegevens. Kijk alles na, vul aan wat mist en sla op.</div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>">
    <label>EMS en monitoring <input name="ems" value="<?= e($o['ems'] ?? '') ?>" placeholder="bijv. Eniris, Teleport, Hoymiles S-Miles"></label>
    <?php foreach (OPL_TEKST as $k => [$label, $hint]): ?>
      <label><?= e($label) ?> <textarea name="<?= $k ?>" rows="<?= $k === 'serienummers' ? 5 : 3 ?>" placeholder="<?= e($hint) ?>"><?= e($o[$k] ?? '') ?></textarea></label>
    <?php endforeach; ?>
    <p class="small"><a href="fotos.php?nr=<?= e(rawurlencode($pr['nummer'])) ?>">Opleverfoto's: <?= $fotos ?></a> <span class="muted">(kies bij het uploaden "Oplevering")</span></p>
    <?php foreach (OPL_CHECKS as $k => $l): ?>
      <label class="check"><input type="checkbox" name="<?= $k ?>" <?= !empty($o[$k]) ? 'checked' : '' ?>> <?= e($l) ?></label>
    <?php endforeach; ?>
    <div class="row wrap">
      <button class="btn primary">Opslaan</button>
      <?php if (empty($o['afgerond_op'])): ?><button class="btn" name="afronden" value="1">Opslaan en afronden</button><?php endif; ?>
    </div>
  </form>
</section>
<?php page_foot();
