<?php
// Inkoop: wat moet er nog besteld worden, en welke bestellingen lopen er.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/materiaal.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) { http_response_code(403); exit('Alleen voor beheerders.'); }
    $actie = $_POST['actie'] ?? '';
    try {
        if ($actie === 'besteld') {
            // Geselecteerde regels van de bestellijst als bestelling vastleggen
            $ids = array_map('intval', (array)($_POST['m'] ?? []));
            $regels = [];
            foreach (bestellijst() as $r) {
                if (in_array($r['materiaal_id'], $ids, true) && $r['project'] === project_nummer($_POST['project'] ?? '')) {
                    $regels[] = ['omschrijving' => $r['omschrijving'], 'ean' => $r['ean'], 'code' => $r['rexel_code'], 'aantal' => $r['aantal']];
                }
            }
            if (!$regels) throw new InvalidArgumentException('Kies minstens één regel.');
            $res = bestelling_opslaan([
                'leverancier' => $_POST['leverancier'] ?? 'Rexel', 'ordernummer' => $_POST['ordernummer'] ?? '', 'project' => $_POST['project'] ?? '',
                'besteldatum' => date('Y-m-d'), 'leverdatum' => $_POST['leverdatum'] ?? null, 'levering' => $_POST['levering'] ?? null,
                'status' => 'besteld', 'besteld_door' => $u['naam'], 'bron' => 'Handmatig in app', 'regels' => $regels,
            ]);
            flash(count($regels) . ' regels vastgelegd als bestelling ' . e($_POST['ordernummer'] ?? '') . '.');
            redirect('inkoop.php?b=' . $res['bestelling_id']);
        }
        $bid = (int)($_POST['bestelling_id'] ?? 0);
        if ($actie === 'geleverd') {
            $r = bestelling_geleverd($bid, $_POST['waar'] ?? null, $u['naam']);
            flash($r['waar'] === 'magazijn'
                ? "Levering verwerkt: {$r['regels']} regels in de voorraad en klaargezet voor het project."
                : "Levering verwerkt: {$r['regels']} regels als geleverd op het project.");
        } elseif ($actie === 'wijzig') {
            $st = $_POST['status'] ?? '';
            q('UPDATE bestellingen SET leverdatum = ?, levering = ?, status = IF(? IN (\'besteld\',\'bevestigd\',\'verzonden\') AND status NOT IN (\'geleverd\',\'gefactureerd\'), ?, status), project_id = ? WHERE id = ?', [
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['leverdatum'] ?? '') ? $_POST['leverdatum'] : null,
                in_array($_POST['levering'] ?? '', ['magazijn', 'project', 'onbekend'], true) ? $_POST['levering'] : 'onbekend',
                $st, $st, project_id($_POST['project'] ?? '') , $bid]);
            flash('Bestelling bijgewerkt.');
        }
        redirect('inkoop.php?b=' . $bid);
    } catch (InvalidArgumentException $e) {
        flash(e($e->getMessage()), 'err');
        redirect('inkoop.php');
    }
}

// ---------- Detail van één bestelling ----------
if (!empty($_GET['b'])) {
    $b = q('SELECT b.*, p.nummer FROM bestellingen b LEFT JOIN projecten p ON p.id = b.project_id WHERE b.id = ?', [(int)$_GET['b']])->fetch();
    if (!$b) { flash('Bestelling niet gevonden.', 'err'); redirect('inkoop.php'); }
    $regels = q('SELECT br.*, p.code, p.naam FROM bestelregels br LEFT JOIN producten p ON p.id = br.product_id WHERE br.bestelling_id = ? ORDER BY br.id', [$b['id']])->fetchAll();
    $open = array_sum(array_map(fn($r) => (float)$r['aantal'] - (float)$r['geleverd'], $regels)) > 0;
    page_head('Bestelling ' . $b['ordernummer'], 'inkoop');
    ?>
<section class="card">
  <div class="row between wrap">
    <h1><?= e($b['leverancier']) ?> · <?= e($b['ordernummer']) ?></h1>
    <a class="btn ghost" href="inkoop.php#bestellingen">Terug</a>
  </div>
  <p><span class="tag s-<?= e($b['status']) ?>"><?= e(BESTEL_STATUS[$b['status']]) ?></span>
    <?= $b['nummer'] ? ' · Project <a href="project.php?nr=' . e(rawurlencode($b['nummer'])) . '"><b>' . e($b['nummer']) . '</b></a>' : ' · <b class="warn-ink">geen project</b>' ?>
    <?= $b['besteldatum'] ? ' · besteld ' . e(date('d-m-Y', strtotime($b['besteldatum']))) : '' ?><?= $b['besteld_door'] ? ' door ' . e($b['besteld_door']) : '' ?>
    <?= $b['leverdatum'] ? ' · levering <b>' . e(date('d-m-Y', strtotime($b['leverdatum']))) . '</b>' : '' ?>
    <?= $b['afleveradres'] ? '<br><span class="small muted">Afleveradres: ' . e($b['afleveradres']) . '</span>' : '' ?>
    <?= $b['factuurnummer'] ? '<br><span class="small">Factuur ' . e($b['factuurnummer']) . ($b['factuurbedrag'] !== null && is_admin() ? ' · ' . fmt_eur($b['factuurbedrag']) : '') . '</span>' : '' ?>
    <?= $b['bron'] ? '<br><span class="small muted">Bron: ' . e($b['bron']) . '</span>' : '' ?></p>

  <?php if (is_admin() && $open): ?>
  <div class="row wrap">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="bestelling_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="actie" value="geleverd"><input type="hidden" name="waar" value="magazijn">
      <button class="btn primary">📦 Geleverd in magazijn</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="bestelling_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="actie" value="geleverd"><input type="hidden" name="waar" value="project">
      <button class="btn">🚚 Geleverd op het project</button></form>
  </div>
  <p class="small muted">Magazijn: komt in de voorraad en wordt klaargezet voor het project. Op het project: telt meteen als materiaal op het project.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Regels</h2>
  <div class="tablewrap"><table>
    <thead><tr><th>Omschrijving</th><th>EAN / Rexel</th><th class="r">Aantal</th><th class="r">Geleverd</th><th class="r">Prijs</th></tr></thead>
    <tbody>
    <?php foreach ($regels as $r): ?>
      <tr><td><?= $r['product_id'] ? '<a href="product.php?id=' . (int)$r['product_id'] . '">' . e($r['naam']) . '</a><br><span class="small muted">' . e($r['code']) . '</span>' : e($r['omschrijving']) . ' <span class="tag">nieuw</span>' ?></td>
        <td class="small"><?= e($r['ean'] ?? '') ?><?= $r['leverancier_code'] ? '<br>Rexel ' . e($r['leverancier_code']) : '' ?></td>
        <td class="r"><?= fmt_n($r['aantal']) ?></td><td class="r"><?= fmt_n($r['geleverd']) ?></td>
        <td class="r"><?= $r['prijs'] !== null && is_admin() ? fmt_eur($r['prijs']) : '–' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$regels): ?><tr><td colspan="5" class="muted">Geen regels bekend (alleen orderbevestiging zonder specificatie).</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<?php if (is_admin()): ?>
<section class="card narrow">
  <h2>Wijzigen</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="bestelling_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="actie" value="wijzig">
    <label>Project <input name="project" value="<?= e($b['nummer'] ?? '') ?>" placeholder="PRJ-289"></label>
    <div class="row">
      <label class="grow">Leverdatum <input type="date" name="leverdatum" value="<?= e($b['leverdatum'] ?? '') ?>"></label>
      <label class="grow">Levering <select name="levering"><?php foreach (['onbekend', 'magazijn', 'project'] as $o): ?><option <?= $b['levering'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
    </div>
    <label>Status <select name="status"><?php foreach (['besteld', 'bevestigd', 'verzonden'] as $o): ?><option value="<?= $o ?>" <?= $b['status'] === $o ? 'selected' : '' ?>><?= BESTEL_STATUS[$o] ?></option><?php endforeach; ?></select></label>
    <button class="btn">Opslaan</button>
  </form>
</section>
<?php endif; ?>
<?php page_foot(); exit; }

// ---------- Overzicht ----------
$lijst = bestellijst();
$perProject = [];
foreach ($lijst as $r) $perProject[$r['project']][] = $r;
$toonAlles = isset($_GET['alle']);
$bestellingen = q("SELECT b.*, p.nummer, (SELECT COUNT(*) FROM bestelregels br WHERE br.bestelling_id = b.id) regels
                   FROM bestellingen b LEFT JOIN projecten p ON p.id = b.project_id
                   " . ($toonAlles ? '' : "WHERE b.status IN ('besteld','bevestigd','verzonden') OR b.project_id IS NULL") . "
                   ORDER BY (b.leverdatum IS NULL), b.leverdatum, b.id DESC LIMIT 300")->fetchAll();
$export = array_map(fn($r) => ['Project' => $r['project'], 'Rexel-code' => $r['rexel_code'], 'EAN' => $r['ean'], 'Omschrijving' => $r['omschrijving'],
    'Aantal' => $r['aantal'], 'Eenheid' => $r['eenheid'], 'WW-code' => $r['wwcode']], $lijst);

page_head('Inkoop', 'inkoop');
?>
<section class="card">
  <div class="row between wrap">
    <h1>Nog bestellen</h1>
    <?php if ($lijst): ?><button class="btn" type="button" data-export="expBestel" data-naam="bestellijst">⬇ Excel (alles)</button><?php endif; ?>
  </div>
  <?php if (!$lijst): ?>
    <p class="muted">Niets te bestellen. Alles op de materiaallijsten ligt klaar of is besteld.</p>
  <?php endif; ?>
  <?php foreach ($perProject as $nr => $rows): ?>
    <form method="post" class="besteltabel">
      <?= csrf_field() ?><input type="hidden" name="actie" value="besteld"><input type="hidden" name="project" value="<?= e($nr) ?>">
      <h2><a href="project.php?nr=<?= e(rawurlencode($nr)) ?>"><?= e($nr) ?></a> <span class="muted small"><?= e($rows[0]['project_omschrijving']) ?></span></h2>
      <div class="tablewrap"><table>
        <thead><tr><?php if (is_admin()): ?><th><input type="checkbox" checked data-alles></th><?php endif; ?><th>Rexel-code</th><th>EAN</th><th>Omschrijving</th><th class="r">Aantal</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><?php if (is_admin()): ?><td><input type="checkbox" name="m[]" value="<?= (int)$r['materiaal_id'] ?>" checked></td><?php endif; ?>
            <td><b><?= e($r['rexel_code']) ?: '<span class="muted">–</span>' ?></b></td><td class="small"><?= e($r['ean']) ?: '–' ?></td>
            <td><?= e($r['omschrijving']) ?></td><td class="r"><b><?= fmt_n($r['aantal']) ?></b> <span class="small muted"><?= $r['eenheid'] !== 'stuks' ? e($r['eenheid']) : '' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (is_admin()): ?>
      <details class="mt"><summary>Besteld? Leg het vast</summary>
        <div class="row wrap mt">
          <label>Leverancier <input name="leverancier" value="Rexel" required></label>
          <label>Ordernummer <input name="ordernummer" required></label>
          <label>Leverdatum <input type="date" name="leverdatum"></label>
          <label>Levering <select name="levering"><option value="">onbekend</option><option value="magazijn">magazijn</option><option value="project">project</option></select></label>
          <button class="btn primary">Vastleggen</button>
        </div>
        <p class="small muted">Meestal doet Claude dit vanzelf zodra de orderbevestiging in de mail staat.</p>
      </details>
      <?php endif; ?>
    </form>
  <?php endforeach; ?>
</section>

<section class="card" id="bestellingen">
  <div class="row between wrap">
    <h2>Bestellingen <?= $toonAlles ? '(alle)' : '(lopend)' ?></h2>
    <a class="btn ghost small" href="?<?= $toonAlles ? '' : 'alle=1' ?>#bestellingen"><?= $toonAlles ? 'Alleen lopende' : 'Ook geleverd en gefactureerd' ?></a>
  </div>
  <?php if (!$bestellingen): ?><p class="muted">Geen lopende bestellingen.</p><?php else: ?>
  <div class="tablewrap"><table>
    <thead><tr><th>Levering</th><th>Order</th><th>Project</th><th>Status</th><th>Factuur</th></tr></thead>
    <tbody>
    <?php foreach ($bestellingen as $b):
        $telaat = $b['leverdatum'] && $b['leverdatum'] < date('Y-m-d') && in_array($b['status'], ['besteld', 'bevestigd', 'verzonden'], true); ?>
      <tr class="<?= $telaat ? 'neg' : '' ?>">
        <td><?= $b['leverdatum'] ? '<b>' . e(date('d-m', strtotime($b['leverdatum']))) . '</b>' : '–' ?><br><span class="small muted"><?= e($b['levering']) ?></span></td>
        <td><a href="?b=<?= (int)$b['id'] ?>"><?= e($b['leverancier']) ?> <?= e($b['ordernummer']) ?></a><br><span class="small muted"><?= (int)$b['regels'] ?> regels<?= $b['besteld_door'] ? ' · ' . e($b['besteld_door']) : '' ?></span></td>
        <td><?= $b['nummer'] ? '<a href="project.php?nr=' . e(rawurlencode($b['nummer'])) . '">' . e($b['nummer']) . '</a>' : '<span class="warn-ink">?</span>' ?></td>
        <td><span class="tag s-<?= e($b['status']) ?>"><?= e(BESTEL_STATUS[$b['status']]) ?></span></td>
        <td class="small"><?= e($b['factuurnummer']) ?: '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<script id="expBestel" type="application/json"><?= json_encode($export, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php page_foot(['https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js']);
