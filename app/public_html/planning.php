<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$vandaag = vandaag();
$week = maandag_van(geldige_datum($_GET['w'] ?? $_POST['w'] ?? null) ?? $vandaag);
$hier = 'planning.php?w=' . $week;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) { http_response_code(403); exit('Alleen kantoor past de planning aan.'); }
    $actie = $_POST['actie'] ?? '';

    if ($actie === 'weg') {
        // Verwijderen vanuit de balk: één monteur (dagdeel of hele dag) of het hele project op die dag (JSON)
        header('Content-Type: application/json');
        $datum = geldige_datum($_POST['datum'] ?? null);
        if (!$datum) exit(json_encode(['fout' => 'Datum ontbreekt.']));
        $slots = [];
        if (!empty($_POST['project_id'])) {
            $pid = (int)$_POST['project_id'];
            foreach (q('SELECT gebruiker_id, dagdeel FROM planning WHERE datum = ? AND project_id = ?', [$datum, $pid])->fetchAll() as $r) $slots[] = [$datum, $r['dagdeel'], (int)$r['gebruiker_id']];
            $pr = q('SELECT nummer, omschrijving FROM projecten WHERE id = ?', [$pid])->fetch();
            $titel = 'Planning: ' . ($pr ? project_naam($pr) : 'project') . ' op ' . datum_kort($datum) . ' verwijderen';
        } else {
            $gid = (int)($_POST['gid'] ?? 0); $dd = $_POST['dagdeel'] ?? 'dag';
            foreach ($dd === 'dag' ? ['ochtend', 'middag'] : [$dd] as $x) if (isset(DAGDELEN[$x])) $slots[] = [$datum, $x, $gid];
            $wie = q('SELECT naam FROM gebruikers WHERE id = ?', [$gid])->fetch()['naam'] ?? '';
            $titel = 'Planning: ' . explode(' ', $wie)[0] . ' op ' . datum_kort($datum) . ($dd !== 'dag' ? " ($dd)" : '') . ' verwijderen';
        }
        $uit = plan_verwijder($slots, $u, $titel);
        if (!empty($uit['ok'])) flash(e($uit['bericht']));
        exit(json_encode($uit));
    }

    if (in_array($actie, ['sleep', 'project', 'dag'], true)) {
        // Verplaatsen vanuit het rooster (JSON). Eerst botsingen melden; met uitvoeren=1 wordt het gedaan.
        header('Content-Type: application/json');
        $datum = geldige_datum($_POST['datum'] ?? null);
        $naar = geldige_datum($_POST['naar'] ?? null);
        if (!$datum || !$naar) exit(json_encode(['fout' => 'Datum ontbreekt.']));
        $moves = []; $titel = '';
        if ($actie === 'sleep') {
            $gid = (int)($_POST['gid'] ?? 0); $ngid = (int)($_POST['naar_gid'] ?? 0) ?: $gid;
            $dd = $_POST['dagdeel'] ?? 'dag';
            foreach ($dd === 'dag' ? ['ochtend', 'middag'] : [$dd] as $x) if (isset(DAGDELEN[$x])) $moves[] = [$datum, $x, $gid, $naar, $ngid];
            $wie = q('SELECT naam FROM gebruikers WHERE id = ?', [$ngid])->fetch()['naam'] ?? '';
            $titel = 'Planning: verplaatsen naar ' . explode(' ', $wie)[0] . ' ' . datum_kort($naar);
        } elseif ($actie === 'project') {
            $pid = (int)($_POST['project_id'] ?? 0);
            foreach (q('SELECT gebruiker_id, dagdeel FROM planning WHERE datum = ? AND project_id = ?', [$datum, $pid])->fetchAll() as $r)
                $moves[] = [$datum, $r['dagdeel'], (int)$r['gebruiker_id'], $naar, (int)$r['gebruiker_id']];
            $pr = q('SELECT nummer, omschrijving FROM projecten WHERE id = ?', [$pid])->fetch();
            $titel = 'Planning: ' . ($pr ? project_naam($pr) : 'project') . ' van ' . datum_kort($datum) . ' naar ' . datum_kort($naar);
        } else {
            foreach (q('SELECT gebruiker_id, dagdeel FROM planning WHERE datum = ?', [$datum])->fetchAll() as $r)
                $moves[] = [$datum, $r['dagdeel'], (int)$r['gebruiker_id'], $naar, (int)$r['gebruiker_id']];
            $titel = 'Planning: hele ' . datum_kort($datum) . ' naar ' . datum_kort($naar);
        }
        $pids = array_unique(array_filter(array_column(q('SELECT project_id FROM planning WHERE datum = ?', [$datum])->fetchAll(), 'project_id')));
        $uit = plan_verplaats($moves, !empty($_POST['uitvoeren']), $u, $titel);
        if (!empty($uit['ok']) && is_beslisser() && $actie !== 'sleep') {
            foreach ($actie === 'project' ? [(int)$_POST['project_id']] : $pids as $pid) dagopdracht_mee($datum, $naar, (int)$pid);
        }
        if (!empty($uit['ok'])) flash(e($uit['bericht']));
        exit(json_encode($uit));
    }

    if ($actie === 'zet' || $actie === 'leeg') {
        $datum = geldige_datum($_POST['datum'] ?? null);
        $gids = array_unique(array_filter(array_map('intval', array_merge([$_POST['gid'] ?? 0], (array)($_POST['ook'] ?? [])))));
        if (!$datum || !$gids) { flash('Datum of persoon ontbreekt.', 'err'); redirect($hier); }
        $slot = function (string $dd) use ($actie): array {
            if ($actie === 'leeg') return [null, ''];
            $nr = project_nummer($_POST["project_$dd"] ?? '');
            return [$nr !== '' ? project_id($nr) : null, (string)($_POST["tekst_$dd"] ?? '')];
        };
        $ochtend = $slot('ochtend');
        $middag = isset($_POST['zelfde']) ? $ochtend : $slot('middag');
        $voorbehoud = isset($_POST['voorbehoud']);
        $tm = geldige_datum($_POST['tm'] ?? null);
        if (!$tm || $tm < $datum) $tm = $datum;
        if ($tm > dag_plus($datum, 31)) $tm = dag_plus($datum, 31);
        // Alleen Ruben en Berend wijzigen de planning direct; van een ander wordt het een voorstel
        $direct = is_beslisser();
        $namen = [];
        foreach (q('SELECT id, naam, gebruikersnaam FROM gebruikers WHERE id IN (' . implode(',', $gids) . ')')->fetchAll() as $g) $namen[(int)$g['id']] = $g;
        $nrVan = fn(?int $pid) => $pid ? (string)q('SELECT nummer FROM projecten WHERE id = ?', [$pid])->fetch()['nummer'] : '';
        $n = 0; $wijzigingen = [];
        for ($d = $datum; $d <= $tm; $d = dag_plus($d, 1)) {
            if ($d !== $datum && (int)date('N', strtotime($d)) >= 6) continue; // herhalen slaat het weekend over
            foreach ($gids as $gid) {
                if (!isset($namen[$gid])) continue;
                if ($direct) {
                    plan_zet($d, 'ochtend', $gid, $ochtend[0], $ochtend[1], $voorbehoud, $u['id']);
                    plan_zet($d, 'middag', $gid, $middag[0], $middag[1], $voorbehoud, $u['id']);
                } elseif ($ochtend === $middag) {
                    $wijzigingen[] = ['datum' => $d, 'dagdeel' => 'dag', 'wie' => $namen[$gid]['gebruikersnaam'], 'project' => $nrVan($ochtend[0]), 'tekst' => clean_text($ochtend[1]), 'voorbehoud' => $voorbehoud];
                } else {
                    $wijzigingen[] = ['datum' => $d, 'dagdeel' => 'ochtend', 'wie' => $namen[$gid]['gebruikersnaam'], 'project' => $nrVan($ochtend[0]), 'tekst' => clean_text($ochtend[1]), 'voorbehoud' => $voorbehoud];
                    $wijzigingen[] = ['datum' => $d, 'dagdeel' => 'middag', 'wie' => $namen[$gid]['gebruikersnaam'], 'project' => $nrVan($middag[0]), 'tekst' => clean_text($middag[1]), 'voorbehoud' => $voorbehoud];
                }
                $n++;
            }
        }
        if ($direct) {
            flash($actie === 'leeg' ? 'Leeggemaakt.' : "Planning opgeslagen ($n " . ($n === 1 ? 'dag' : 'dagen') . ').');
        } elseif ($wijzigingen) {
            $wie = implode(', ', array_map(fn($g) => explode(' ', $g['naam'])[0], $namen));
            q('INSERT INTO voorstellen (titel, toelichting, wijzigingen, bron) VALUES (?,?,?,?)', [
                mb_substr('Planning ' . datum_kort($datum) . ($tm > $datum ? ' t/m ' . datum_kort($tm) : '') . ': ' . $wie, 0, 255),
                trim((string)($_POST['waarom'] ?? '')) ?: null, json_encode($wijzigingen, JSON_UNESCAPED_UNICODE), 'Voorstel van ' . $u['naam']]);
            foreach (beslissers() as $b) seintje($b, explode(' ', $u['naam'])[0] . ' stelt een wijziging in de planning voor: ' . datum_kort($datum) . ', ' . $wie, 'planning.php#voorstellen');
            flash('Je wijziging staat klaar als voorstel. Ruben of Berend geeft akkoord; daarna staat het in de planning.');
        }
    } elseif ($actie === 'voorstel') {
        $id = (int)($_POST['id'] ?? 0);
        if (!is_beslisser()) { http_response_code(403); exit('Alleen Ruben of Berend geeft akkoord op de planning.'); }
        if (($_POST['besluit'] ?? '') === 'akkoord') {
            try { $n = voorstel_toepassen($id, $u['id']); flash("Akkoord. $n dagdelen in de planning gezet."); }
            catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); flash('Het voorstel kon niet worden toegepast: ' . e($e->getMessage()), 'err'); }
        } else {
            q("UPDATE voorstellen SET status = 'afgewezen', besloten_door = ?, besloten_op = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'", [$u['id'], $id]);
            flash('Voorstel afgewezen. De planning is niet veranderd.');
        }
    }
    redirect($hier);
}

$dagen = [];
for ($i = 0; $i < 6; $i++) $dagen[] = dag_plus($week, $i);
$mensen = mensen_planbaar();
$plan = planning_tussen($week, $dagen[5]);
// Wie deze week nergens staat, verbergen (behalve met "toon iedereen")
$iedereen = isset($_GET['iedereen']);
$verborgen = [];
if (!$iedereen) {
    $mensen = array_values(array_filter($mensen, function ($p) use ($plan, $dagen, &$verborgen) {
        foreach ($dagen as $d) if (!empty($plan[$d][(int)$p['id']])) return true;
        $verborgen[] = explode(' ', $p['naam'])[0];
        return false;
    }));
}
$zaterdag = !empty($plan[$dagen[5]]);
if (!$zaterdag && !is_admin()) array_pop($dagen);

// Instructies per project per dag (alleen kantoor ziet de status)
$opdrachten = [];
if (is_admin()) {
    foreach (q('SELECT DISTINCT pl.datum, pl.project_id, pr.nummer, pr.omschrijving FROM planning pl JOIN projecten pr ON pr.id = pl.project_id
                WHERE pl.datum BETWEEN ? AND ? ORDER BY pl.datum, pr.nummer', [max($week, $vandaag), $dagen[count($dagen) - 1]])->fetchAll() as $r) {
        $o = dagopdracht($r['datum'], (int)$r['project_id']);
        $r['status'] = dagopdracht_stand($o);
        $r['wie'] = $o ? ($o['akkoord1_naam'] ?? $o['bijgewerkt_door']) : '';
        $opdrachten[] = $r;
    }
}

// Voorstellen van Claude
$voorstellen = [];
if (is_admin()) {
    foreach (q("SELECT * FROM voorstellen WHERE status = 'open' ORDER BY id")->fetchAll() as $v) {
        [$v['regels'], $v['fouten']] = voorstel_regels(json_decode($v['wijzigingen'], true) ?: []);
        foreach ($v['regels'] as &$r) {
            $nu = planning_tussen($r['datum'], $r['datum'], $r['gid'])[$r['datum']][$r['gid']] ?? [];
            $r['was'] = implode(' / ', array_unique(array_filter(array_map(fn($dd) => plan_tekst($nu[$dd] ?? null), $r['dagdelen'])))) ?: 'leeg';
        }
        unset($r);
        $voorstellen[] = $v;
    }
}

// Bewerken van één persoon op één dag
$bewerk = null;
if (is_admin() && ($bd = geldige_datum($_GET['bewerk'] ?? null)) && ($bg = (int)($_GET['gid'] ?? 0))) {
    $mens = q('SELECT id, naam FROM gebruikers WHERE id = ? AND planbaar = 1', [$bg])->fetch();
    if ($mens) {
        $nu = planning_tussen($bd, $bd, $bg)[$bd][$bg] ?? [];
        $bewerk = ['datum' => $bd, 'mens' => $mens, 'o' => $nu['ochtend'] ?? null, 'm' => $nu['middag'] ?? null];
    }
}
$projecten = is_admin() ? q('SELECT nummer, omschrijving FROM projecten WHERE afgerond = 0 ORDER BY id DESC LIMIT 300')->fetchAll() : [];
$alleMensen = mensen_planbaar();

/** Eén cel in het rooster: ochtend en middag, samengevoegd als ze gelijk zijn. */
function cel(?array $o, ?array $m, string $link, string $datum = '', int $gid = 0, string $naam = ''): string
{
    $stuk = function (?array $r, string $label, string $dd) use ($naam, $link) {
        if (!$r) return '<span class="slot leeg"><i>' . $label . '</i></span>';
        $kleur = $r['project_id'] ? ' kleur-' . ((int)$r['project_id'] % 8) : ' vrij';
        if ($link === '') return '<span class="slot' . $kleur . ($r['voorbehoud'] ? ' voorbehoud' : '') . '">' . ($label !== '' ? '<i>' . $label . '</i> ' : '') . e(plan_tekst($r)) . ($r['voorbehoud'] ? ' <em>(voorbehoud)</em>' : '') . '</span>';
        return '<span class="slot blok' . $kleur . ($r['voorbehoud'] ? ' voorbehoud' : '') . '" draggable="true" data-dagdeel="' . $dd . '" data-pid="' . (int)$r['project_id']
            . '" data-titel="' . e(plan_tekst($r)) . '" data-naam="' . e($naam) . '">' . ($label !== '' ? '<i>' . $label . '</i> ' : '') . e(plan_tekst($r)) . ($r['voorbehoud'] ? ' <em>(voorbehoud)</em>' : '') . '</span>';
    };
    if (!$o && !$m) $html = '<span class="slot leeg">&nbsp;</span>';
    elseif (plan_gelijk($o, $m)) $html = $stuk($o, '', 'dag');
    else $html = $stuk($o, 'ochtend', 'ochtend') . $stuk($m, 'middag', 'middag');
    $data = $datum !== '' ? ' data-datum="' . e($datum) . '" data-gid="' . $gid . '"' : '';
    return $link !== '' ? '<a class="cel"' . $data . ' href="' . e($link) . '">' . $html . '</a>' : '<div class="cel">' . $html . '</div>';
}

page_head(is_admin() ? 'Planning' : 'Ploeg', 'planning');
?>
<?php if ($voorstellen): ?>
<section class="card voorstel" id="voorstellen">
  <h2>Voorstel voor de planning</h2>
  <p class="small muted">De planning verandert pas als Ruben of Berend akkoord geeft.</p>
  <?php foreach ($voorstellen as $v): ?>
  <div class="voorstel-blok">
    <h3><?= e($v['titel']) ?></h3>
    <?php if ($v['toelichting']): ?><p><?= nl2br(e($v['toelichting'])) ?></p><?php endif; ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Dag</th><th>Wie</th><th>Wordt</th><th>Staat nu</th></tr></thead>
      <tbody>
      <?php foreach ($v['regels'] as $r): ?>
        <tr><td><?= e(datum_kort($r['datum'])) ?><?= $r['dagdeel'] !== 'dag' ? ' <span class="muted small">' . e($r['dagdeel']) . '</span>' : '' ?></td><td><?= e($r['wie']) ?></td>
          <td><b><?= e(trim($r['project'] . ($r['project'] !== '' && $r['tekst'] !== '' ? ' · ' : '') . $r['tekst']) ?: 'leeg') ?></b><?= $r['voorbehoud'] ? ' (voorbehoud)' : '' ?></td>
          <td class="muted"><?= e($r['was']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($v['fouten']): ?><div class="flash err klein"><?= e(implode('; ', $v['fouten'])) ?></div><?php endif; ?>
    <?php if (is_beslisser()): ?>
    <form method="post" class="row wrap mt">
      <?= csrf_field() ?><input type="hidden" name="actie" value="voorstel"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="w" value="<?= e($week) ?>">
      <button class="btn primary" name="besluit" value="akkoord" <?= $v['fouten'] ? 'disabled' : '' ?>>Akkoord, zet in de planning</button>
      <button class="btn" name="besluit" value="afwijzen">Afwijzen</button>
      <span class="small muted"><?= e($v['bron']) ?></span>
    </form>
    <?php else: ?><p class="small muted mt"><?= e($v['bron']) ?> · wacht op akkoord van Ruben of Berend</p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($bewerk): $o = $bewerk['o']; $m = $bewerk['m']; $zelfde = plan_gelijk($o, $m); ?>
<div class="overlay" id="bewerkOverlay"><section class="card bewerk" id="bewerk">
  <div class="row between wrap">
    <h2><?= e($bewerk['mens']['naam']) ?>, <?= e(datum_lang($bewerk['datum'])) ?></h2>
    <a class="btn ghost small" href="<?= e($hier) ?>">Sluiten</a>
  </div>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="w" value="<?= e($week) ?>"><input type="hidden" name="datum" value="<?= e($bewerk['datum']) ?>"><input type="hidden" name="gid" value="<?= (int)$bewerk['mens']['id'] ?>">
    <div class="row wrap">
      <label class="grow">Ochtend: project <input name="project_ochtend" list="projectlijst" value="<?= e($o['nummer'] ?? '') ?>" placeholder="PRJ-nummer, of leeg"></label>
      <label class="grow">Ochtend: tekst <input name="tekst_ochtend" value="<?= e($o['tekst'] ?? '') ?>" placeholder="bijv. kabel trekken, tandarts, loods"></label>
    </div>
    <label class="check"><input type="checkbox" name="zelfde" id="zelfde" <?= $zelfde ? 'checked' : '' ?>> De middag is hetzelfde als de ochtend</label>
    <div class="row wrap" id="middagvelden" <?= $zelfde ? 'hidden' : '' ?>>
      <label class="grow">Middag: project <input name="project_middag" list="projectlijst" value="<?= e($m['nummer'] ?? '') ?>" placeholder="PRJ-nummer, of leeg"></label>
      <label class="grow">Middag: tekst <input name="tekst_middag" value="<?= e($m['tekst'] ?? '') ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="voorbehoud" <?= ($o['voorbehoud'] ?? $m['voorbehoud'] ?? 0) ? 'checked' : '' ?>> Onder voorbehoud (nog niet bevestigd)</label>
    <div class="row wrap">
      <div class="grow"><span class="small muted">Ook voor</span>
        <div class="groepchips">
          <?php foreach ($alleMensen as $p): if ((int)$p['id'] === (int)$bewerk['mens']['id']) continue; ?>
            <label class="chip"><input type="checkbox" name="ook[]" value="<?= (int)$p['id'] ?>"> <?= e($p['naam']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <label>Herhalen tot en met (weekend wordt overgeslagen) <input type="date" name="tm" min="<?= e($bewerk['datum']) ?>"></label>
    </div>
    <?php if (!is_beslisser()): ?><label>Waarom (voor Ruben of Berend) <input name="waarom" placeholder="bijv. klant belde: kan pas donderdag"></label><?php endif; ?>
    <div class="row wrap">
      <button class="btn primary" name="actie" value="zet"><?= is_beslisser() ? 'Opslaan' : 'Voorstellen' ?></button>
      <button class="btn gevaar" name="actie" value="leeg" formnovalidate><?= is_beslisser() ? 'Verwijderen' : 'Voorstellen: verwijderen' ?></button>
    </div>
    <?php if (!is_beslisser()): ?><p class="small muted">Je wijziging gaat als voorstel naar Ruben en Berend. Na hun akkoord staat het in de planning.</p><?php endif; ?>
  </form>
  <datalist id="projectlijst"><?php foreach ($projecten as $p): ?><option value="<?= e($p['nummer']) ?>"><?= e($p['omschrijving']) ?></option><?php endforeach; ?></datalist>
</section></div>
<?php endif; ?>

<section class="card">
  <div class="row between wrap">
    <h1><?= is_admin() ? 'Planning' : 'Ploeg' ?> week <?= weeknr($week) ?> <span class="muted small"><?= e(date('d-m', strtotime($week))) ?> t/m <?= e(date('d-m', strtotime($dagen[count($dagen) - 1]))) ?></span></h1>
    <div class="row">
      <a class="btn small" href="planning.php?w=<?= e(dag_plus($week, -7)) ?><?= $iedereen ? '&amp;iedereen=1' : '' ?>">Vorige</a>
      <a class="btn small" href="planning.php<?= $iedereen ? '?iedereen=1' : '' ?>">Deze week</a>
      <a class="btn small" href="planning.php?w=<?= e(dag_plus($week, 7)) ?><?= $iedereen ? '&amp;iedereen=1' : '' ?>">Volgende</a>
    </div>
  </div>
  <?php if ($verborgen || $iedereen): ?><p class="small muted"><?php if ($verborgen): ?>Niet ingepland deze week: <?= e(implode(', ', $verborgen)) ?>. <a href="planning.php?w=<?= e($week) ?>&amp;iedereen=1">Toon iedereen</a><?php else: ?><a href="planning.php?w=<?= e($week) ?>">Verberg wie niet ingepland staat</a><?php endif; ?></p><?php endif; ?>
  <?php if (!$alleMensen): ?>
    <p class="muted">Er staat nog niemand op planbaar. <?= is_admin() ? 'Zet dat aan bij <a href="gebruikers.php">Gebruikers</a>.' : '' ?></p>
  <?php elseif ($mensen): ?>
  <?php if (is_admin()): ?><p class="small muted">Klik op een blok om het te verplaatsen (ook het hele project of alleen deze monteur), of sleep het naar een andere plek. Klik op een dag bovenaan om de hele dag te verplaatsen. Een leeg vak klik je aan om het in te vullen.<?= is_beslisser() ? '' : ' Jouw wijzigingen worden een voorstel voor Ruben of Berend.' ?></p><?php endif; ?>

  <div class="tablewrap rooster-breed"><table class="rooster">
    <thead><tr><th></th><?php foreach ($dagen as $d): ?><th class="<?= $d === $vandaag ? 'nu' : '' ?>"><?php if (is_admin()): ?><button type="button" class="dagknop" data-dag="<?= e($d) ?>" data-label="<?= e(datum_kort($d)) ?>"><?= e(datum_kort($d)) ?></button><?php else: ?><?= e(datum_kort($d)) ?><?php endif; ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($mensen as $p): ?>
      <tr><th><?= e($p['naam']) ?></th>
        <?php foreach ($dagen as $d): $c = $plan[$d][(int)$p['id']] ?? []; ?>
          <td class="<?= $d === $vandaag ? 'nu' : '' ?>"><?= cel($c['ochtend'] ?? null, $c['middag'] ?? null, is_admin() ? "planning.php?w=$week&bewerk=$d&gid=" . (int)$p['id'] . '#bewerk' : '', $d, (int)$p['id'], explode(' ', $p['naam'])[0]) ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <div class="rooster-smal">
    <?php foreach ($dagen as $d): ?>
      <div class="roosterdag<?= $d === $vandaag ? ' nu' : '' ?>">
        <h3><?php if (is_admin()): ?><button type="button" class="dagknop" data-dag="<?= e($d) ?>" data-label="<?= e(datum_kort($d)) ?>"><?= e(ucfirst(datum_lang($d))) ?></button><?php else: ?><?= e(ucfirst(datum_lang($d))) ?><?php endif; ?></h3>
        <?php foreach ($mensen as $p): $c = $plan[$d][(int)$p['id']] ?? []; ?>
          <div class="roosterrij"><b><?= e(explode(' ', $p['naam'])[0]) ?></b><?= cel($c['ochtend'] ?? null, $c['middag'] ?? null, is_admin() ? "planning.php?w=$week&bewerk=$d&gid=" . (int)$p['id'] . '#bewerk' : '', $d, (int)$p['id'], explode(' ', $p['naam'])[0]) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php if (is_admin()): ?>
<div class="planbalk" id="planbalk" hidden data-week="<?= e($week) ?>" data-vandaag="<?= e($vandaag) ?>">
  <div class="planbalk-in">
    <div class="row between"><b id="pbTitel"></b><button type="button" class="btn small ghost" id="pbSluit">Sluiten</button></div>
    <div id="pbWat" class="row wrap pbwat"></div>
    <div class="row wrap pbdagen" id="pbDagen"></div>
    <div class="row wrap"><label class="pbander">Andere dag <input type="date" id="pbDatum"></label><a class="btn small" id="pbBewerk" href="#">Bewerken</a><button type="button" class="btn small gevaar" id="pbWeg">Verwijderen</button></div>
    <p class="small" id="pbMelding"></p>
  </div>
</div>
<script type="application/json" id="werkdagen"><?php $wd = []; $x = dag_plus(max($week, $vandaag), -1); for ($i = 0; $i < 15; $i++) { $x = volgende_werkdag($x); $wd[] = [$x, datum_kort($x)]; } echo json_encode($wd); ?></script>
<?= csrf_field() ?>
<?php endif; ?>

<?php if (is_admin() && $opdrachten): ?>
<section class="card">
  <h2>Instructies voor de monteurs</h2>
  <p class="small muted">Een monteur ziet een instructie pas als Ruben of Berend akkoord heeft gegeven. Wat zij zelf invullen is direct zichtbaar.</p>
  <div class="tablewrap"><table>
    <thead><tr><th>Dag</th><th>Project</th><th>Stand</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($opdrachten as $r): ?>
      <tr><td><?= e(datum_kort($r['datum'])) ?></td><td><?= e(project_naam($r)) ?> <span class="muted small"><?= e($r['nummer']) ?></span></td>
        <td><?php if ($r['status'] === 'vrij'): ?><span class="tag st-klaar">Vrijgegeven</span>
            <?php elseif ($r['status'] === 'controle'): ?><span class="tag st-wacht">Wacht op akkoord</span> <span class="small muted">ingevuld door <?= e($r['wie']) ?></span>
            <?php elseif ($r['status'] === 'concept'): ?><span class="tag st-bezig">Klaargezet door <?= e($r['wie']) ?></span> <span class="small muted">wacht op akkoord</span>
            <?php else: ?><span class="tag t-laat">Nog leeg</span><?php endif; ?></td>
        <td><a class="btn small" href="opdracht.php?datum=<?= e($r['datum']) ?>&amp;nr=<?= e(rawurlencode($r['nummer'])) ?>"><?= $r['status'] === 'leeg' ? 'Invullen' : 'Openen' ?></a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php page_foot();
