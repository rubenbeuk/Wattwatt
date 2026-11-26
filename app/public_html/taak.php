<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$wie = $u['naam'];

/** Mag deze gebruiker de taak bijwerken? Kantoor alles, een monteur alleen zijn eigen taken. */
function taak_mag(array $t, array $u): bool { return $u['rol'] === 'admin' || (int)$t['eigenaar_id'] === $u['id']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    $terug = terug_veilig($_POST['terug'] ?? '', 'dag.php');

    if ($actie === 'nieuw') {
        if (!is_admin()) { http_response_code(403); exit('Alleen kantoor maakt taken aan.'); }
        $nr = project_nummer($_POST['project'] ?? '');
        try {
            taak_maak([
                'titel' => $_POST['titel'] ?? '',
                'toelichting' => $_POST['toelichting'] ?? '',
                'project_id' => $nr !== '' ? project_id($nr) : null,
                'eigenaar_id' => (int)($_POST['eigenaar_id'] ?? 0) ?: null,
                'datum' => $_POST['datum'] ?? null,
                'deadline' => $_POST['deadline'] ?? null,
                'prioriteit' => $_POST['prioriteit'] ?? 'normaal',
                'bron' => 'Handmatig, ' . $wie,
            ], $u['id'], $wie);
            flash('Taak toegevoegd.');
        } catch (InvalidArgumentException $e) { flash('Vul een titel in.', 'err'); }
        redirect($terug);
    }

    if ($actie === 'bulk') {
        // Meerdere taken in één keer naar een nieuwe dag (bijv. alles wat te laat is)
        $datum = geldige_datum($_POST['datum'] ?? null);
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if (!$datum || !$ids) { flash('Kies een dag en minstens één taak.', 'err'); redirect($terug); }
        $n = 0;
        foreach ($ids as $tid) {
            $tt = q("SELECT * FROM taken WHERE id = ? AND status <> 'klaar'", [$tid])->fetch();
            if ($tt && taak_mag($tt, $u)) { taak_verzet($tid, $datum, null, $u['id'], $wie); $n++; }
        }
        flash("$n " . ($n === 1 ? 'taak' : 'taken') . ' verzet naar ' . e(datum_kort($datum)) . '.');
        redirect($terug);
    }

    if ($actie === 'volgorde') {
        // Volgorde binnen een kolom van het bord (JSON). ids[] van boven naar beneden.
        header('Content-Type: application/json');
        $n = 0;
        foreach (array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))) as $i => $tid) {
            $tt = q('SELECT * FROM taken WHERE id = ?', [$tid])->fetch();
            if ($tt && taak_mag($tt, $u)) { q('UPDATE taken SET volgorde = ? WHERE id = ?', [($i + 1) * 10, $tid]); $n++; }
        }
        exit(json_encode(['ok' => true, 'n' => $n]));
    }

    $t = q('SELECT * FROM taken WHERE id = ?', [(int)($_POST['id'] ?? 0)])->fetch();
    if (!$t) { flash('Taak niet gevonden.', 'err'); redirect($terug); }
    if ($actie === 'verplaats') {
        // Vanuit Mijn dag gesleept: andere dag en/of ander belang. Antwoordt met JSON.
        header('Content-Type: application/json');
        if (!taak_mag($t, $u)) { http_response_code(403); exit(json_encode(['fout' => 'Dit is niet jouw taak.'])); }
        $datum = isset($_POST['datum']) ? geldige_datum($_POST['datum']) : null;
        $prio = isset($_POST['prioriteit']) && isset(TAAK_PRIO[$_POST['prioriteit']]) ? $_POST['prioriteit'] : null;
        taak_verzet((int)$t['id'], $datum, $prio, $u['id'], $wie);
        // Naar of uit de kolom Wacht op
        $st = $_POST['status'] ?? '';
        if (in_array($st, ['open', 'wacht'], true) && ($st !== $t['status'] || ($st === 'wacht' && clean_text($_POST['wacht_op'] ?? '') !== $t['wacht_op']))) {
            taak_zet_status((int)$t['id'], $st, '', $u['id'], $wie, (string)($_POST['wacht_op'] ?? ''));
        }
        $n = q('SELECT * FROM taken WHERE id = ?', [(int)$t['id']])->fetch();
        exit(json_encode(['ok' => true, 'dag' => taak_dag($n), 'prioriteit' => $n['prioriteit'], 'status' => $n['status'], 'wacht_op' => $n['wacht_op'],
            'deadline' => $n['deadline'] ? datum_kort($n['deadline']) : '']));
    }
    if (!taak_mag($t, $u)) { http_response_code(403); exit('Dit is niet jouw taak.'); }
    $id = (int)$t['id'];
    $notitie = mb_substr(clean_text($_POST['notitie'] ?? ''), 0, 400);

    $meldVrager = function (string $wat) use ($t, $u, $id, $notitie) {
        if ($t['van_id'] && (int)$t['van_id'] !== $u['id']) seintje((int)$t['van_id'], explode(' ', $u['naam'])[0] . ' ' . $wat . ': ' . mb_substr($t['titel'], 0, 150) . ($notitie !== '' ? ' (' . $notitie . ')' : ''), 'dag.php');
    };
    if ($actie === 'klaar') {
        taak_zet_status($id, 'klaar', $notitie, $u['id'], $wie);
        $meldVrager('heeft afgerond');
        flash('Afgevinkt: ' . e($t['titel']) . ' <form method="post" action="taak.php" class="inline">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="terug" value="' . e($terug) . '"><button class="linkbtn" name="actie" value="heropen">Ongedaan maken</button></form>');
    } elseif ($actie === 'heropen') {
        taak_zet_status($id, 'open', 'Weer opengezet', $u['id'], $wie);
        flash('De taak staat weer open.');
    } elseif ($actie === 'status') {
        $status = in_array($_POST['status'] ?? '', ['open', 'bezig', 'wacht'], true) ? $_POST['status'] : 'open';
        $wachtOp = clean_text($_POST['wacht_op'] ?? '');
        if ($status === $t['status'] && ($status !== 'wacht' || $wachtOp === $t['wacht_op'])) {
            // Alleen een notitie: status blijft gelijk
            if ($notitie !== '') { taak_log($id, $notitie, $u['id'], $wie); flash('Notitie opgeslagen.'); }
        } else {
            taak_zet_status($id, $status, $notitie, $u['id'], $wie, $wachtOp);
            if ($status !== 'open') $meldVrager($status === 'bezig' ? 'is bezig met' : 'wacht op ' . ($wachtOp ?: 'iets') . ' voor');
            flash($status === 'wacht' ? 'Staat bij Wacht op' . ($wachtOp !== '' ? ': ' . e($wachtOp) : '') . '.' : 'Status opgeslagen.');
        }
    } elseif ($actie === 'morgen') {
        $nieuw = volgende_werkdag(max(vandaag(), (string)$t['datum']));
        taak_verzet($id, $nieuw, null, $u['id'], $wie);
        if ($notitie !== '') taak_log($id, $notitie, $u['id'], $wie);
        flash('Doorgeschoven naar ' . e(datum_kort($nieuw)) . '.');
    } elseif ($actie === 'toewijzen' && is_admin()) {
        $eid = (int)($_POST['eigenaar_id'] ?? 0) ?: null;
        $naam = $eid ? (q('SELECT naam FROM gebruikers WHERE id = ?', [$eid])->fetch()['naam'] ?? null) : null;
        if ($eid && !$naam) { flash('Onbekende persoon.', 'err'); redirect($terug); }
        q('UPDATE taken SET eigenaar_id = ?, datum = COALESCE(datum, ?) WHERE id = ?', [$eid, vandaag(), $id]);
        taak_log($id, $naam ? 'Toegewezen aan ' . $naam : 'Weer bij nog te verdelen', $u['id'], $wie);
        flash($naam ? 'Toegewezen aan ' . e($naam) . '.' : 'Staat weer bij nog te verdelen.');
    } elseif ($actie === 'bewerk' && is_admin()) {
        $nr = project_nummer($_POST['project'] ?? '');
        $titel = mb_substr(clean_text($_POST['titel'] ?? ''), 0, 255);
        if ($titel === '') { flash('Vul een titel in.', 'err'); redirect('taak.php?id=' . $id); }
        q('UPDATE taken SET titel = ?, toelichting = ?, project_id = ?, eigenaar_id = ?, datum = ?, deadline = ?, prioriteit = ? WHERE id = ?', [
            $titel, trim((string)($_POST['toelichting'] ?? '')) ?: null, $nr !== '' ? project_id($nr) : null, (int)($_POST['eigenaar_id'] ?? 0) ?: null,
            geldige_datum($_POST['datum'] ?? null), geldige_datum($_POST['deadline'] ?? null),
            isset(TAAK_PRIO[$_POST['prioriteit'] ?? '']) ? $_POST['prioriteit'] : 'normaal', $id]);
        taak_log($id, 'Taak aangepast', $u['id'], $wie);
        flash('Taak opgeslagen.');
        redirect('taak.php?id=' . $id);
    }
    redirect($terug);
}

$t = q('SELECT t.*, pr.nummer, pr.omschrijving, g.naam eigenaar, v.naam van FROM taken t LEFT JOIN projecten pr ON pr.id = t.project_id
        LEFT JOIN gebruikers g ON g.id = t.eigenaar_id LEFT JOIN gebruikers v ON v.id = t.van_id WHERE t.id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$t) { flash('Taak niet gevonden.', 'err'); redirect('dag.php'); }
if (!taak_mag($t, $u)) { http_response_code(403); exit('Dit is niet jouw taak.'); }
$log = q('SELECT l.*, g.naam FROM taak_log l LEFT JOIN gebruikers g ON g.id = l.gebruiker_id WHERE l.taak_id = ? ORDER BY l.id DESC', [$t['id']])->fetchAll();
$mensen = mensen_kantoor();
$projecten = q('SELECT nummer, omschrijving FROM projecten WHERE afgerond = 0 ORDER BY id DESC LIMIT 300')->fetchAll();

page_head('Taak', is_admin() ? 'team' : 'dag');
?>
<section class="card">
  <div class="row between wrap">
    <h1><?= e($t['titel']) ?></h1>
    <a class="btn ghost" href="<?= is_admin() ? 'team.php' : 'dag.php' ?>">Terug</a>
  </div>
  <p class="muted"><?= e($t['eigenaar'] ?? 'Nog te verdelen') ?> · <?= e(TAAK_STATUS[$t['status']]) ?><?= $t['bron'] !== '' ? ' · bron: ' . e($t['bron']) : '' ?><?= !empty($t['van']) && (int)$t['van_id'] !== (int)$t['eigenaar_id'] ? ' · gevraagd door ' . e($t['van']) : '' ?></p>
  <?php if ($t['toelichting']): ?><p><?= nl2br(e($t['toelichting'])) ?></p><?php endif; ?>
  <ul class="taken"><?php taak_rij($t, 'taak.php?id=' . (int)$t['id']); ?></ul>
</section>

<?php if (is_admin()): ?>
<section class="card">
  <h2>Aanpassen</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="actie" value="bewerk">
    <label>Titel <input name="titel" value="<?= e($t['titel']) ?>" required></label>
    <label>Toelichting <textarea name="toelichting" rows="3"><?= e($t['toelichting'] ?? '') ?></textarea></label>
    <div class="row wrap">
      <label class="grow">Voor wie
        <select name="eigenaar_id"><option value="">Nog te verdelen</option>
          <?php foreach ($mensen as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$t['eigenaar_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['naam']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="grow">Project <input name="project" list="projectlijst" value="<?= e($t['nummer'] ?? '') ?>" placeholder="PRJ-nummer"></label>
      <label class="grow">Belang
        <select name="prioriteit"><?php foreach (TAAK_PRIO as $k => $v): ?><option value="<?= $k ?>" <?= $t['prioriteit'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="row wrap">
      <label class="grow">Doen op <input type="date" name="datum" value="<?= e($t['datum'] ?? '') ?>"></label>
      <label class="grow">Uiterlijk klaar <input type="date" name="deadline" value="<?= e($t['deadline'] ?? '') ?>"></label>
    </div>
    <button class="btn primary">Opslaan</button>
  </form>
  <datalist id="projectlijst"><?php foreach ($projecten as $p): ?><option value="<?= e($p['nummer']) ?>"><?= e($p['omschrijving']) ?></option><?php endforeach; ?></datalist>
</section>
<?php endif; ?>

<section class="card">
  <h2>Wat er gebeurde</h2>
  <ul class="logboek">
    <?php foreach ($log as $l): ?><li><span class="muted small"><?= e(fmt_dt($l['aangemaakt'])) ?> · <?= e($l['naam'] ?? $l['wie']) ?></span><br><?= e($l['tekst']) ?></li><?php endforeach; ?>
  </ul>
</section>
<?php page_foot();
