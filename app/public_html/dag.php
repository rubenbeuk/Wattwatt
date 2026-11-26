<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/materiaal.php';
$u = require_login();
$vandaag = vandaag();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? 'melding';
    $pid = (int)($_POST['project_id'] ?? 0) ?: null;
    if (!$pid && ($nr = project_nummer($_POST['project'] ?? '')) !== '') $pid = (int)(q('SELECT id FROM projecten WHERE nummer = ?', [$nr])->fetch()['id'] ?? 0) ?: null;
    $pr = $pid ? q('SELECT * FROM projecten WHERE id = ?', [$pid])->fetch() : null;
    $tekst = mb_substr(trim((string)($_POST['tekst'] ?? '')), 0, 2000);
    $voornaam = explode(' ', $u['naam'])[0];

    if ($actie === 'gezien') {
        // Seintje(s) wegklikken
        if (isset($_POST['alles'])) q('UPDATE seintjes SET gezien_op = UTC_TIMESTAMP() WHERE gebruiker_id = ? AND gezien_op IS NULL', [$u['id']]);
        else q('UPDATE seintjes SET gezien_op = UTC_TIMESTAMP() WHERE id = ? AND gebruiker_id = ?', [(int)($_POST['id'] ?? 0), $u['id']]);
    } elseif ($actie === 'notitie') {
        // Notitie: iets doorgeven. Claude verwerkt het bij de volgende ronde; "ook een taak" staat er direct.
        if ($tekst === '') flash('Schrijf eerst je notitie.', 'err');
        else {
            q('INSERT INTO notities (gebruiker_id, project_id, tekst) VALUES (?,?,?)', [$u['id'], $pr ? $pr['id'] : null, $tekst]);
            $extra = '';
            if (isset($_POST['ook_taak'])) {
                taak_maak(['titel' => mb_substr(clean_text($tekst), 0, 200), 'toelichting' => $tekst, 'project_id' => $pr ? (int)$pr['id'] : null, 'eigenaar_id' => $u['id'],
                    'van_id' => $u['id'], 'datum' => $vandaag, 'bron' => 'Notitie van ' . $voornaam], $u['id'], $u['naam']);
                $extra = ' Er staat ook een taak voor jou.';
            }
            flash('Notitie opgeslagen.' . $extra . ' Claude verwerkt hem bij de volgende ronde.');
        }
    } elseif ($actie === 'vraag') {
        // Vraag van de ploeg aan kantoor: wordt een taak, zodat iedereen ziet waar op gewacht wordt
        if ($tekst === '') flash('Schrijf op wat je nodig hebt.', 'err');
        else {
            $voor = (int)($_POST['voor'] ?? 0) ?: null;
            if ($voor && !q("SELECT id FROM gebruikers WHERE id = ? AND rol = 'admin' AND actief = 1", [$voor])->fetch()) $voor = null;
            $id = taak_maak(['titel' => ($pr ? project_naam($pr) . ': ' : '') . mb_substr(clean_text($tekst), 0, 180), 'toelichting' => $tekst, 'project_id' => $pr ? (int)$pr['id'] : null,
                'eigenaar_id' => $voor, 'van_id' => $u['id'], 'datum' => $vandaag, 'bron' => 'Vraag van ' . $voornaam], $u['id'], $u['naam']);
            foreach ($voor ? [$voor] : beslissers() as $wie) if ($wie !== $u['id']) seintje($wie, $voornaam . ' vraagt: ' . mb_substr(clean_text($tekst), 0, 200), 'taak.php?id=' . $id);
            flash('Je vraag staat bij kantoor.');
        }
    } else {
        // Melding van de monteur: werk klaar, of een probleem (dat wordt een taak voor kantoor)
        $soort = ($_POST['soort'] ?? '') === 'probleem' ? 'probleem' : 'klaar';
        if ($soort === 'probleem' && $tekst === '') {
            flash('Schrijf kort op wat het probleem is.', 'err');
        } else {
            $taakId = null;
            $naam = $pr ? project_naam($pr) : '';
            if ($soort === 'probleem') {
                $taakId = taak_maak([
                    'titel' => 'Probleem' . ($pr ? ' ' . $naam : '') . ': ' . mb_substr(clean_text($tekst), 0, 150),
                    'toelichting' => $tekst, 'project_id' => $pr ? (int)$pr['id'] : null, 'eigenaar_id' => null, 'van_id' => $u['id'],
                    'datum' => $vandaag, 'prioriteit' => 'hoog', 'bron' => 'Melding van ' . $voornaam,
                ], $u['id'], $u['naam']);
            }
            q('INSERT INTO meldingen (datum, project_id, gebruiker_id, soort, tekst, taak_id) VALUES (?,?,?,?,?,?)', [$vandaag, $pr ? $pr['id'] : null, $u['id'], $soort, mb_substr($tekst, 0, 1000), $taakId]);
            // Seintje naar de projectleider (anders naar Ruben en Berend), ook op de laptop
            seintje_project($pr ? (int)$pr['id'] : null,
                $soort === 'probleem' ? "$voornaam meldt een probleem" . ($naam !== '' ? " op $naam" : '') . ': ' . mb_substr(clean_text($tekst), 0, 200)
                                      : "$voornaam meldt: werk klaar" . ($naam !== '' ? " op $naam" : '') . ($tekst !== '' ? '. ' . mb_substr(clean_text($tekst), 0, 200) : ''),
                $taakId ? 'taak.php?id=' . $taakId : ($pr ? 'project.php?nr=' . rawurlencode($pr['nummer']) : 'dag.php'), $u['id']);
            flash($soort === 'probleem' ? 'Gemeld. Kantoor ziet het direct.' : 'Klaar gemeld. Dank je.');
        }
    }
    redirect('dag.php');
}

// ---------- Mijn planning: vandaag en de komende werkdagen ----------
$tot = dag_plus($vandaag, 9);
$plan = planning_tussen($vandaag, $tot, $u['id']);
$dagen = [];
for ($d = $vandaag; $d <= $tot; $d = dag_plus($d, 1)) {
    $o = $plan[$d][$u['id']]['ochtend'] ?? null;
    $m = $plan[$d][$u['id']]['middag'] ?? null;
    if (!$o && !$m) continue;
    $dagen[$d] = plan_gelijk($o, $m) ? [['Hele dag', $o]] : array_values(array_filter([$o ? ['Ochtend', $o] : null, $m ? ['Middag', $m] : null]));
    if (count($dagen) >= 5) break;
}
$vandaagNr = '';
foreach ($dagen[$vandaag] ?? [] as [$l, $r]) if ($r['project_id']) { $vandaagNr = (string)$r['nummer']; break; }
$gemeld = [];
foreach (q('SELECT project_id, soort FROM meldingen WHERE gebruiker_id = ? AND datum = ?', [$u['id'], $vandaag])->fetchAll() as $r) $gemeld[(int)$r['project_id']][$r['soort']] = true;

function materiaal_kort(int $pid): ?array
{
    $rijen = materiaal_rijen($pid);
    if (!$rijen) return null;
    $open = 0;
    foreach ($rijen as $r) if ($r['nog_bestellen'] > 0 || $r['besteld_open'] > 0) $open++;
    return ['totaal' => count($rijen), 'open' => $open];
}

// ---------- Mijn taken (kantoor kan ook de taken van een ander bekijken: ?wie=<id>) ----------
$wieId = $u['id']; $wieNaam = ''; $wieLijst = [];
if (is_admin()) {
    $wieLijst = q("SELECT g.id, g.naam, (SELECT COUNT(*) FROM taken t WHERE t.eigenaar_id = g.id AND t.status <> 'klaar') n
                   FROM gebruikers g WHERE g.actief = 1 ORDER BY (g.rol <> 'admin'), g.volgorde, g.naam")->fetchAll();
    $gevraagd = (int)($_GET['wie'] ?? 0);
    foreach ($wieLijst as $g) if ((int)$g['id'] === $gevraagd && $gevraagd !== $u['id']) { $wieId = $gevraagd; $wieNaam = explode(' ', $g['naam'])[0]; }
    $wieLijst = array_values(array_filter($wieLijst, fn($g) => (int)$g['n'] > 0 || (int)$g['id'] === $u['id']));
}
$takenTerug = $wieNaam !== '' ? 'dag.php?wie=' . $wieId . '#taken' : 'dag.php';
$bakWie = $wieNaam !== '' ? taken_in_bakken(taken_open($wieId)) : null;
$bak = taken_in_bakken(taken_open($u['id']));
$aantalOpen = count($bak['vandaag']) + count($bak['later']) + count($bak['geen']);
$teLaat = count(array_filter(array_merge($bak['vandaag'], $bak['later'], $bak['geen']), 'taak_te_laat'));
$vandaagKlaar = (int)q("SELECT COUNT(*) c FROM taken WHERE eigenaar_id = ? AND status = 'klaar' AND afgerond_op >= ?", [$u['id'], gmdate('Y-m-d H:i:s', strtotime($vandaag . ' 00:00:00'))])->fetch()['c'];

// ---------- Kantoor: ploeg vandaag, nog te verdelen, meldingen ----------
$ploeg = $verdelen = $meldingen = $voorstellen = [];
if (is_admin()) {
    $alle = planning_tussen($vandaag, $vandaag);
    foreach (mensen_planbaar() as $m) {
        $o = $alle[$vandaag][(int)$m['id']]['ochtend'] ?? null;
        $mi = $alle[$vandaag][(int)$m['id']]['middag'] ?? null;
        $ploeg[] = [explode(' ', $m['naam'])[0], $o, $mi];
    }
    $verdelen = taken_open(0);
    $meldingen = q('SELECT m.*, g.naam, pr.nummer, pr.omschrijving FROM meldingen m JOIN gebruikers g ON g.id = m.gebruiker_id
                    LEFT JOIN projecten pr ON pr.id = m.project_id WHERE m.datum = ? ORDER BY m.id DESC', [$vandaag])->fetchAll();
    $voorstellen = q("SELECT id, titel FROM voorstellen WHERE status = 'open' ORDER BY id")->fetchAll();
}
$mensen = mensen_kantoor();
$seintjes = seintjes_open($u['id']);
$notities = notities_recent(is_admin() ? null : $u['id'], 8);
// De ploeg ziet wat er vanuit de ploeg bij kantoor ligt (waar wachten we op) en elkaars taken; niet de eigen taken van kantoor
$vanPloeg = $ploegTaken = $perKantoor = [];
if (!is_admin()) {
    $vanPloeg = q("SELECT t.id, t.titel, t.status, t.wacht_op, t.afgerond_op, v.naam van, e.naam eigenaar FROM taken t JOIN gebruikers v ON v.id = t.van_id
                   LEFT JOIN gebruikers e ON e.id = t.eigenaar_id
                   WHERE v.rol = 'monteur' AND (t.eigenaar_id IS NULL OR e.rol = 'admin') AND (t.status <> 'klaar' OR t.afgerond_op >= (UTC_TIMESTAMP() - INTERVAL 3 DAY))
                   ORDER BY (t.status = 'klaar'), t.id DESC LIMIT 40")->fetchAll();
    foreach ($vanPloeg as $t) if ($t['status'] !== 'klaar') { $k = $t['eigenaar'] ? explode(' ', $t['eigenaar'])[0] : 'Nog te verdelen'; $perKantoor[$k] = ($perKantoor[$k] ?? 0) + 1; }
    $ploegTaken = q("SELECT t.titel, t.status, t.deadline, e.naam eigenaar FROM taken t JOIN gebruikers e ON e.id = t.eigenaar_id
                     WHERE e.rol = 'monteur' AND t.eigenaar_id <> ? AND t.status <> 'klaar' ORDER BY e.naam, t.id LIMIT 40", [$u['id']])->fetchAll();
}
$projecten = is_admin() ? q('SELECT nummer, omschrijving FROM projecten WHERE afgerond = 0 ORDER BY id DESC LIMIT 300')->fetchAll() : [];

page_head('Mijn dag', 'dag');
?>
<div class="dagkop">
  <h1><?= e(explode(' ', $u['naam'])[0]) ?>, <?= e(datum_lang($vandaag)) ?></h1>
  <?php if ($aantalOpen || $vandaagKlaar || is_admin()): ?>
  <div class="stats">
    <div><b><?= count($bak['vandaag']) ?></b><span>taken vandaag</span></div>
    <?php if ($teLaat): ?><div class="rood"><b><?= $teLaat ?></b><span>te laat</span></div><?php endif; ?>
    <div><b><?= $vandaagKlaar ?></b><span>vandaag gedaan</span></div>
    <?php if (is_admin() && $verdelen): ?><a href="team.php#verdelen"><b><?= count($verdelen) ?></b><span>nog te verdelen</span></a><?php endif; ?>
    <?php if (is_admin() && $voorstellen): ?><a href="planning.php#voorstellen"><b><?= count($voorstellen) ?></b><span>voorstel planning</span></a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($seintjes): ?>
<section class="card seintjes" id="seintjes">
  <div class="row between"><h2>Seintjes <span class="muted">(<?= count($seintjes) ?>)</span></h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="actie" value="gezien"><button class="btn small" name="alles" value="1">Alles gezien</button></form></div>
  <ul class="logboek">
    <?php foreach ($seintjes as $s): ?>
      <li class="row between"><span><span class="muted small"><?= e(fmt_dt($s['aangemaakt'])) ?></span><br>
          <?php if ($s['link'] !== ''): ?><a href="<?= e(terug_veilig($s['link'])) ?>"><b><?= e($s['tekst']) ?></b></a><?php else: ?><b><?= e($s['tekst']) ?></b><?php endif; ?></span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="actie" value="gezien"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn small">Gezien</button></form></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($aantalOpen || is_admin()): ?>
<section class="card" id="taken">
  <div class="row between wrap"><h2><?= $wieNaam !== '' ? 'Taken van ' . e($wieNaam) : 'Mijn taken' ?></h2>
  <?php if (count($wieLijst) > 1): ?>
  <nav class="wiechips">
    <?php foreach ($wieLijst as $g): $isMij = (int)$g['id'] === $u['id']; $aan = (int)$g['id'] === $wieId; ?>
      <a class="chip<?= $aan ? ' aan' : '' ?>" href="<?= $isMij ? 'dag.php#taken' : 'dag.php?wie=' . (int)$g['id'] . '#taken' ?>"><?= $isMij ? 'Ik' : e(explode(' ', $g['naam'])[0]) ?> <span class="muted"><?= (int)$g['n'] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?></div>
  <?php $lijst = $bakWie ? array_merge($bakWie['vandaag'], $bakWie['later'], $bakWie['geen']) : array_merge($bak['vandaag'], $bak['later'], $bak['geen']); ?>
  <?php if (!$lijst): ?><p class="muted">Geen open taken.</p><?php else: taken_bord($lijst, $takenTerug); endif; ?>
</section>
<?php endif; ?>

<div class="cols2">
<div class="kolom">

<?php if ($dagen): ?>
<?php foreach ($dagen as $d => $blokken): $isVandaag = $d === $vandaag; ?>
<section class="card dagkaart<?= $isVandaag ? ' nu' : '' ?>">
  <h2><?= $isVandaag ? 'Vandaag' : e(ucfirst(datum_lang($d))) ?></h2>
  <?php foreach ($blokken as [$label, $r]):
      $opdracht = $r['project_id'] ? dagopdracht($d, (int)$r['project_id']) : null;
      $vrij = dagopdracht_vrij($opdracht);
      $mat = ($r['project_id'] && $isVandaag) ? materiaal_kort((int)$r['project_id']) : null; ?>
  <div class="blok">
    <div class="blok-kop">
      <span class="tag dagdeel"><?= e($label) ?></span>
      <?php if ($r['voorbehoud']): ?><span class="tag st-wacht">Onder voorbehoud</span><?php endif; ?>
    </div>
    <?php if ($r['project_id']): ?>
      <div class="blok-titel"><?= e(project_naam($r)) ?></div>
      <div class="small muted"><?= e($r['nummer']) ?><?= $r['klant'] !== '' ? ' · ' . e($r['klant']) : '' ?></div>
      <?php if ($r['tekst'] !== ''): ?><p class="blok-tekst"><?= e($r['tekst']) ?></p><?php endif; ?>
      <?php if ($r['adres'] !== ''): ?>
        <p class="adres"><?= e($r['adres']) ?></p>
        <?php if ($isVandaag): ?><a class="btn primary breed" href="<?= e(route_url($r['adres'])) ?>" target="_blank" rel="noopener">Route starten</a><?php endif; ?>
      <?php elseif ($isVandaag): ?><p class="small muted">Het adres staat nog niet bij dit project.</p><?php endif; ?>
      <?php if ($r['contact'] !== '' && $isVandaag): ?><p class="small">Contact op locatie: <?= e($r['contact']) ?></p><?php endif; ?>

      <?php if ($vrij): ?>
        <div class="instructie">
          <?php if (trim($opdracht['stroom']) !== ''): ?><div class="stroom">Stroom eraf: <?= e($opdracht['stroom']) ?></div><?php endif; ?>
          <?php if (trim($opdracht['wat']) !== ''): ?><div class="ikop">Wat ga je doen</div><div class="itekst"><?= nl2br(e($opdracht['wat'])) ?></div><?php endif; ?>
          <?php if (trim($opdracht['meenemen']) !== ''): ?><div class="ikop">Meenemen</div><div class="itekst"><?= nl2br(e($opdracht['meenemen'])) ?></div><?php endif; ?>
          <div class="small muted">Akkoord: <?= e(dagopdracht_akkoord_naam($opdracht)) ?></div>
        </div>
      <?php else: ?>
        <div class="flash warn klein">De instructie voor deze dag is nog niet vrijgegeven.<?= is_admin() ? ' <a href="opdracht.php?datum=' . e($d) . '&amp;nr=' . e(rawurlencode($r['nummer'])) . '">Open de instructie</a>' : ' Je ziet hem hier zodra kantoor hem heeft vrijgegeven.' ?></div>
      <?php endif; ?>

      <?php if ($mat): ?>
        <a class="matregel" href="project.php?nr=<?= e(rawurlencode($r['nummer'])) ?>#materiaal">
          <span>Materiaal: <?= $mat['totaal'] ?> regels</span>
          <b class="<?= $mat['open'] ? 'warn-ink' : 'ok-ink' ?>"><?= $mat['open'] ? $mat['open'] . ' nog onderweg of te bestellen' : 'niets meer onderweg' ?></b>
        </a>
      <?php endif; ?>

      <?php if ($isVandaag): ?>
      <div class="knoppen2">
        <a class="btn" href="fotos.php?nr=<?= e(rawurlencode($r['nummer'])) ?>">Foto's</a>
        <a class="btn" href="project.php?nr=<?= e(rawurlencode($r['nummer'])) ?>">Project</a>
      </div>
      <?php if (!empty($gemeld[(int)$r['project_id']]['klaar'])): ?><p class="ok-ink small">Je hebt dit werk vandaag klaar gemeld.</p><?php endif; ?>
      <div class="knoppen2">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="project_id" value="<?= (int)$r['project_id'] ?>">
          <button class="btn klaar breed" name="soort" value="klaar">Werk klaar</button></form>
        <details class="probleem">
          <summary class="btn gevaar breed">Probleem melden</summary>
          <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="project_id" value="<?= (int)$r['project_id'] ?>"><input type="hidden" name="soort" value="probleem">
            <label>Wat is er aan de hand? <textarea name="tekst" rows="3" required placeholder="bijv. kabel is 5 meter te kort"></textarea></label>
            <button class="btn primary">Verstuur naar kantoor</button>
          </form>
        </details>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="blok-titel"><?= e($r['tekst']) ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php elseif (!is_admin()): ?>
<section class="card"><h2>Mijn planning</h2><p class="muted">Je staat de komende dagen nog niet ingepland. Kijk bij <a href="planning.php">Ploeg</a> voor de hele planning.</p></section>
<?php endif; ?>

<?php if (!is_admin()): ?>
<section class="card">
  <h2>Vraag aan kantoor</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="actie" value="vraag">
    <label>Wat heb je nodig? <textarea name="tekst" rows="2" required placeholder="bijv. 10 kabelschoenen 50 mm2 bestellen voor De Schooten"></textarea></label>
    <div class="row wrap">
      <label class="grow">Voor wie
        <select name="voor"><option value="">Weet ik niet</option><?php foreach ($mensen as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e(explode(' ', $m['naam'])[0]) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Project (mag leeg) <input name="project" placeholder="PRJ-nummer" value="<?= e($vandaagNr) ?>"></label>
    </div>
    <button class="btn primary">Verstuur</button>
  </form>
</section>

<section class="card">
  <h2>Waar wacht de ploeg op</h2>
  <?php if ($perKantoor): ?><p><?php foreach ($perKantoor as $k => $n): ?><span class="tag"><?= e($k) ?>: <?= (int)$n ?> open</span> <?php endforeach; ?></p><?php endif; ?>
  <?php if (!$vanPloeg): ?><p class="muted">Er ligt niets van de ploeg bij kantoor.</p><?php endif; ?>
  <ul class="logboek">
    <?php foreach ($vanPloeg as $t): ?>
      <li><span class="tag st-<?= e($t['status']) ?>"><?= e(TAAK_STATUS[$t['status']]) ?><?= $t['status'] === 'wacht' && $t['wacht_op'] !== '' ? ' ' . e($t['wacht_op']) : '' ?></span>
        <b><?= e($t['titel']) ?></b><br><span class="small muted">van <?= e(explode(' ', $t['van'])[0]) ?> · bij <?= e($t['eigenaar'] ? explode(' ', $t['eigenaar'])[0] : 'kantoor (nog niet verdeeld)') ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>

<?php if ($ploegTaken): ?>
<section class="card">
  <h2>Taken van de ploeg</h2>
  <ul class="logboek">
    <?php foreach ($ploegTaken as $t): ?>
      <li><b><?= e(explode(' ', $t['eigenaar'])[0]) ?>:</b> <?= e($t['titel']) ?><?= $t['status'] !== 'open' ? ' <span class="tag st-' . e($t['status']) . '">' . e(TAAK_STATUS[$t['status']]) . '</span>' : '' ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php endif; ?>

<section class="card" id="notitie">
  <h2>Notitie</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="actie" value="notitie">
    <label>Wat wil je doorgeven? <textarea name="tekst" rows="3" required placeholder="bijv. Boris heeft de spullen voor morgen opgehaald. Of: Joey is vanaf 23 november niet beschikbaar."></textarea></label>
    <div class="row wrap">
      <label class="grow">Project (mag leeg) <input name="project" list="projectlijst" placeholder="PRJ-nummer"></label>
      <label class="check"><input type="checkbox" name="ook_taak"> Dit is ook een taak voor mij</label>
    </div>
    <button class="btn primary">Opslaan</button>
    <p class="small muted">Iedereen op kantoor ziet de notitie direct. Claude verwerkt hem bij de volgende ronde in de planning en de taken.</p>
  </form>
  <?php if ($notities): ?>
  <ul class="logboek mt">
    <?php foreach ($notities as $n): ?>
      <li><span class="muted small"><?= e(fmt_dt($n['aangemaakt'])) ?> · <?= e(explode(' ', $n['naam'])[0]) ?><?= $n['nummer'] ? ' · ' . e(project_naam($n)) : '' ?></span>
        <span class="tag <?= $n['status'] === 'verwerkt' ? 'st-klaar' : 'st-wacht' ?>"><?= $n['status'] === 'verwerkt' ? 'Verwerkt' : 'Nog verwerken' ?></span><br>
        <?= nl2br(e($n['tekst'])) ?>
        <?php if ($n['verwerking']): ?><br><span class="small ok-ink">Claude: <?= e($n['verwerking']) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

</div>

<?php if (is_admin()): ?>
<aside class="kolom">
  <section class="card">
    <div class="row between"><h2>Ploeg vandaag</h2><a class="small" href="planning.php">Hele planning</a></div>
    <?php if (!$ploeg): ?><p class="muted small">Nog niemand staat op planbaar. Zet dat aan bij <a href="gebruikers.php">Gebruikers</a>.</p><?php endif; ?>
    <ul class="ploeglijst">
      <?php foreach ($ploeg as [$naam, $o, $mi]): ?>
        <li><b><?= e($naam) ?></b>
          <?php if (!$o && !$mi): ?><span class="muted">niet ingepland</span>
          <?php elseif (plan_gelijk($o, $mi)): ?><span><?= e(plan_tekst($o)) ?></span>
          <?php else: ?><span><?= $o ? e(plan_tekst($o)) : '<i class="muted">ochtend leeg</i>' ?>, daarna <?= $mi ? e(plan_tekst($mi)) : '<i class="muted">middag leeg</i>' ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($meldingen): ?>
  <section class="card">
    <h2>Gemeld door de ploeg</h2>
    <ul class="logboek">
      <?php foreach ($meldingen as $m): ?>
        <li><span class="tag <?= $m['soort'] === 'probleem' ? 't-laat' : 'st-klaar' ?>"><?= $m['soort'] === 'probleem' ? 'Probleem' : 'Klaar' ?></span>
          <b><?= e($m['naam']) ?></b><?= $m['nummer'] ? ' · ' . e(project_naam($m)) : '' ?> <span class="muted small"><?= e(substr(fmt_dt($m['aangemaakt']), 11)) ?></span>
          <?php if ($m['tekst'] !== ''): ?><br><?= e($m['tekst']) ?><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Taak toevoegen</h2>
    <form method="post" action="taak.php" class="stack">
      <?= csrf_field() ?><input type="hidden" name="actie" value="nieuw"><input type="hidden" name="terug" value="dag.php">
      <label>Wat moet er gebeuren <input name="titel" required></label>
      <div class="row wrap">
        <label class="grow">Voor wie
          <select name="eigenaar_id"><?php foreach ($mensen as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $u['id'] ? 'selected' : '' ?>><?= e($m['naam']) ?></option><?php endforeach; ?><option value="">Nog te verdelen</option></select></label>
        <label class="grow">Project <input name="project" list="projectlijst" placeholder="PRJ-nummer"></label>
      </div>
      <div class="row wrap">
        <label class="grow">Doen op <input type="date" name="datum" value="<?= e($vandaag) ?>"></label>
        <label class="grow">Belang <select name="prioriteit"><?php foreach (TAAK_PRIO as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'normaal' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      </div>
      <button class="btn primary">Toevoegen</button>
    </form>
    <datalist id="projectlijst"><?php foreach ($projecten as $p): ?><option value="<?= e($p['nummer']) ?>"><?= e($p['omschrijving']) ?></option><?php endforeach; ?></datalist>
  </section>
</aside>
<?php endif; ?>
</div>
<?php page_foot();
