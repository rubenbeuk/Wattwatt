<?php
// Facturen (28-11-2026): factuurvoorstellen per project of ticket. De factuur zelf maak je in het boekhoudpakket;
// de app levert het voorstel (termijn uit de offerte, of uren en materiaal bij service), houdt de status bij en zet de fase mee.
require __DIR__ . '/inc/bootstrap.php';
$u = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    $fid = (int)($_POST['id'] ?? 0);
    if ($actie === 'tarieven') {
        $t = ['uurtarief' => num($_POST['uurtarief'] ?? 0) ?? 0, 'reistarief' => num($_POST['reistarief'] ?? 0) ?? 0, 'opslag' => num($_POST['opslag'] ?? 0) ?? 0];
        q("INSERT INTO brein_stand (sleutel, waarde) VALUES ('tarieven', ?) ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)", [json_encode($t)]);
        flash('Tarieven opgeslagen.');
        redirect('facturen.php');
    } elseif ($actie === 'regels') {
        $f = q("SELECT * FROM facturen WHERE id = ? AND status = 'concept'", [$fid])->fetch();
        if ($f) {
            q('DELETE FROM factuurregels WHERE factuur_id = ?', [$fid]);
            foreach ((array)($_POST['r_oms'] ?? []) as $n => $oms) {
                $oms = mb_substr(clean_text($oms), 0, 255);
                if ($oms === '') continue;
                q('INSERT INTO factuurregels (factuur_id, omschrijving, aantal, eenheid, prijs, bron) VALUES (?,?,?,?,?,?)',
                    [$fid, $oms, num($_POST['r_aantal'][$n] ?? 1) ?? 1, mb_substr(clean_text($_POST['r_eenheid'][$n] ?? ''), 0, 20) ?: 'stuks', num($_POST['r_prijs'][$n] ?? 0) ?? 0, mb_substr(clean_text($_POST['r_bron'][$n] ?? 'hand'), 0, 100)]);
            }
            $btw = num($_POST['btw'] ?? 21);
            q('UPDATE facturen SET omschrijving = ?, btw = ?, concept_door = ? WHERE id = ?', [mb_substr(clean_text($_POST['omschrijving'] ?? ''), 0, 255) ?: $f['omschrijving'], in_array($btw, [0.0, 9.0, 21.0], true) ? $btw : 21, '', $fid]);
            flash('Factuurvoorstel opgeslagen.');
        }
    } elseif (in_array($actie, ['akkoord', 'geboekt', 'betaald', 'vervallen', 'concept'], true)) {
        if ($actie === 'geboekt' && clean_text($_POST['nummer'] ?? '') === '') flash('Vul het factuurnummer uit de boekhouding in.', 'err');
        else flash(e(factuur_zet_status($fid, $actie, $u, (string)($_POST['nummer'] ?? ''))));
    } elseif ($actie === 'termijn') {
        $r = factuur_voorstel_termijn((int)($_POST['termijn_id'] ?? 0), $u['naam']);
        if (isset($r['fout'])) flash(ucfirst($r['fout']) . '.', 'err'); else redirect('facturen.php?id=' . $r['factuur']);
    }
    redirect('facturen.php' . ($fid ? '?id=' . $fid : ''));
}

// Download voor het boekhoudpakket: alle regels van facturen met status akkoord
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="factuurvoorstellen-' . vandaag() . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['voorstel', 'project', 'klant', 'klant_email', 'adres', 'omschrijving_factuur', 'regel', 'aantal', 'eenheid', 'prijs_excl', 'totaal_excl', 'btw_pct'], ';');
    foreach (q("SELECT f.id, f.omschrijving fo, f.btw, p.nummer, p.klant, p.adres, COALESCE(k.email, p.email) email, r.omschrijving, r.aantal, r.eenheid, r.prijs
                FROM facturen f JOIN projecten p ON p.id = f.project_id LEFT JOIN klanten k ON k.id = p.klant_id JOIN factuurregels r ON r.factuur_id = f.id
                WHERE f.status = 'akkoord' ORDER BY f.id, r.id")->fetchAll() as $r)
        fputcsv($out, [$r['id'], $r['nummer'], $r['klant'], $r['email'], $r['adres'], $r['fo'], $r['omschrijving'], str_replace('.', ',', (string)(float)$r['aantal']), $r['eenheid'],
            number_format((float)$r['prijs'], 2, ',', ''), number_format((float)$r['aantal'] * (float)$r['prijs'], 2, ',', ''), str_replace('.', ',', (string)(float)$r['btw'])], ';');
    exit;
}

$tar = tarieven();
$f = !empty($_GET['id']) ? q('SELECT f.*, p.nummer, p.omschrijving pnaam, p.klant, p.adres, p.soort psoort FROM facturen f JOIN projecten p ON p.id = f.project_id WHERE f.id = ?', [(int)$_GET['id']])->fetch() : null;

page_head($f ? 'Factuurvoorstel' : 'Facturen', 'facturen');
if ($f):
    $regels = q('SELECT * FROM factuurregels WHERE factuur_id = ? ORDER BY id', [$f['id']])->fetchAll();
    $tot = factuur_totaal((int)$f['id']);
    $concept = $f['status'] === 'concept';
?>
<section class="card">
  <div class="small muted"><a href="facturen.php">Facturen</a> · <a href="project.php?nr=<?= e(rawurlencode($f['nummer'])) ?>#financieel"><?= e($f['nummer']) ?> <?= e($f['pnaam']) ?></a></div>
  <div class="row between wrap"><h1><?= e($f['omschrijving']) ?></h1><span class="tag"><?= e(FACTUUR_STATUS[$f['status']]) ?></span></div>
  <p class="muted"><?= e($f['klant']) ?><?= $f['adres'] !== '' ? ' · ' . e($f['adres']) : '' ?></p>
  <?php if ($f['concept_door'] !== '' && $concept): ?><div class="flash warn">Voorstel van <?= e($f['concept_door']) ?>. Controleer aantallen en prijzen.</div><?php endif; ?>
  <?php if ($f['psoort'] === 'service' && $tar['uurtarief'] <= 0): ?><div class="flash warn">Er is nog geen uurtarief ingesteld; uren staan op € 0. Stel het onderaan de pagina Facturen in.</div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="actie" value="regels">
    <?php if ($concept): ?><label>Omschrijving <input name="omschrijving" value="<?= e($f['omschrijving']) ?>"></label><?php endif; ?>
    <div class="tablewrap"><table class="invoer"><thead><tr><th>Omschrijving</th><th>Aantal</th><th>Eenheid</th><th>Prijs excl.</th><th class="r">Totaal</th></tr></thead><tbody>
    <?php foreach ($concept ? array_merge($regels, [null, null]) : $regels as $r): ?>
      <tr><?php if ($concept): ?>
        <td><input name="r_oms[]" value="<?= e($r['omschrijving'] ?? '') ?>"><input type="hidden" name="r_bron[]" value="<?= e($r['bron'] ?? 'hand') ?>"></td>
        <td><input name="r_aantal[]" inputmode="decimal" value="<?= $r ? e(str_replace('.', ',', (string)(float)$r['aantal'])) : '' ?>" style="max-width:5rem"></td>
        <td><input name="r_eenheid[]" value="<?= e($r['eenheid'] ?? 'stuks') ?>" style="max-width:6rem"></td>
        <td><input name="r_prijs[]" inputmode="decimal" value="<?= $r ? e(number_format((float)$r['prijs'], 2, ',', '')) : '' ?>" style="max-width:8rem"></td>
      <?php else: ?>
        <td><?= e($r['omschrijving']) ?></td><td><?= e(str_replace('.', ',', (string)(float)$r['aantal'])) ?></td><td><?= e($r['eenheid']) ?></td><td><?= fmt_eur($r['prijs']) ?></td>
      <?php endif; ?>
        <td class="r"><?= $r ? fmt_eur((float)$r['aantal'] * (float)$r['prijs']) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4" class="r">Totaal excl. btw</td><td class="r"><b><?= fmt_eur($tot) ?></b></td></tr>
      <tr><td colspan="4" class="r">Btw <?php if ($concept): ?><select name="btw"><?php foreach ([21, 9, 0] as $b): ?><option value="<?= $b ?>" <?= (float)$f['btw'] === (float)$b ? 'selected' : '' ?>><?= $b ?>%</option><?php endforeach; ?></select><?php else: ?><?= (float)$f['btw'] ?>%<?php endif; ?></td><td class="r"><?= fmt_eur($tot * (float)$f['btw'] / 100) ?></td></tr>
      <tr><td colspan="4" class="r"><b>Totaal incl. btw</b></td><td class="r"><b><?= fmt_eur($tot * (1 + (float)$f['btw'] / 100)) ?></b></td></tr></tfoot>
    </table></div>
    <?php if ($concept): ?><button class="btn small">Opslaan</button><?php endif; ?>
  </form>
</section>
<section class="card">
  <h2>Volgende stap</h2>
  <div class="row wrap">
  <?php if ($concept): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn primary" name="actie" value="akkoord">Akkoord, klaar voor de boekhouding</button></form>
  <?php endif; ?>
  <?php if (in_array($f['status'], ['concept', 'akkoord'], true)): ?>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input name="nummer" placeholder="Factuurnummer uit de boekhouding" aria-label="Factuurnummer"><button class="btn" name="actie" value="geboekt">Staat in de boekhouding</button></form>
  <?php endif; ?>
  <?php if ($f['status'] === 'geboekt'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn primary" name="actie" value="betaald">Betaald</button></form>
  <?php endif; ?>
  <?php if ($f['status'] === 'akkoord'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn ghost" name="actie" value="concept">Terug naar concept</button></form>
  <?php endif; ?>
  <?php if (in_array($f['status'], ['concept', 'akkoord'], true)): ?>
    <form method="post" onsubmit="return confirm('Dit voorstel laten vervallen?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn ghost" name="actie" value="vervallen">Laten vervallen</button></form>
  <?php endif; ?>
  </div>
  <p class="small muted"><?= $f['akkoord_door'] ? 'Akkoord: ' . e($f['akkoord_door']) . ($f['akkoord_op'] ? ', ' . e(fmt_dt($f['akkoord_op'])) : '') . '. ' : '' ?><?= $f['boekhouding_nummer'] ? 'Factuur ' . e($f['boekhouding_nummer']) . '. ' : '' ?><?= $f['betaald_op'] ? 'Betaald op ' . e(date('d-m-Y', strtotime($f['betaald_op']))) . '.' : '' ?></p>
</section>
<?php else:
    $lijst = q("SELECT f.*, p.nummer, p.omschrijving pnaam, p.klant, (SELECT COALESCE(SUM(aantal * prijs), 0) FROM factuurregels r WHERE r.factuur_id = f.id) totaal
                FROM facturen f JOIN projecten p ON p.id = f.project_id WHERE f.status IN ('concept','akkoord','geboekt') OR f.aangemaakt > DATE_SUB(NOW(), INTERVAL 60 DAY)
                ORDER BY FIELD(f.status, 'concept', 'akkoord', 'geboekt', 'betaald', 'vervallen'), f.id DESC")->fetchAll();
    $klaarAlle = termijnen_te_factureren();
    $klaar = array_values(array_filter($klaarAlle, fn($t) => !$t['controleren']));
    $controleren = q("SELECT t.*, p.nummer, p.omschrijving pnaam FROM termijnen t JOIN projecten p ON p.id = t.project_id WHERE t.controleren = 1 ORDER BY p.nummer, t.volgorde")->fetchAll();
    $tickets = q("SELECT p.nummer, p.omschrijving, p.klant, (SELECT SUM(uren) FROM uren u WHERE u.project_id = p.id AND u.aangemaakt > COALESCE((SELECT MAX(aangemaakt) FROM facturen f WHERE f.project_id = p.id AND f.soort = 'regie' AND f.status <> 'vervallen'), '2000-01-01')) uren
                  FROM projecten p WHERE p.soort = 'service' AND p.fase = 'opgelost' AND p.opgelost_op > DATE_SUB(NOW(), INTERVAL 60 DAY) HAVING uren > 0 ORDER BY p.opgelost_op DESC LIMIT 30")->fetchAll();
    $perStatus = array_count_values(array_column($lijst, 'status'));
?>
<section class="card">
  <div class="row between wrap"><h1>Facturen</h1>
    <?php if ($perStatus['akkoord'] ?? 0): ?><a class="btn" href="facturen.php?csv=1">Download akkoord (<?= (int)$perStatus['akkoord'] ?>) voor de boekhouding</a><?php endif; ?></div>
  <p class="muted">Voorstellen per project of ticket. Maak de factuur in het boekhoudpakket en zet daarna hier het factuurnummer; de fase van het project gaat vanzelf mee.</p>
  <div class="stats">
    <?php foreach (['concept', 'akkoord', 'geboekt'] as $st): ?><div><b><?= (int)($perStatus[$st] ?? 0) ?></b><span><?= e(strtolower(FACTUUR_STATUS[$st])) ?></span></div><?php endforeach; ?>
    <div><b class="<?= $klaar ? 'oranje' : '' ?>"><?= count($klaar) ?></b><span>termijnen klaar om te factureren</span></div>
  </div>
</section>
<?php if ($klaar): ?>
<section class="card"><h2>Klaar om te factureren</h2>
  <ul class="plijst"><?php foreach ($klaar as $t): ?>
    <li><a href="project.php?nr=<?= e(rawurlencode($t['nummer'])) ?>#financieel"><b><?= e($t['nummer']) ?></b></a> <?= e($t['pnaam']) ?> · termijn <?= (int)$t['volgorde'] ?>: <?= e($t['omschrijving']) ?> <span class="small muted">(<?= e(TERMIJN_MOMENTEN[$t['moment']]) ?>)</span>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="actie" value="termijn"><input type="hidden" name="termijn_id" value="<?= (int)$t['id'] ?>"><button class="btn small">Factuurvoorstel</button></form></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if ($controleren): ?>
<section class="card"><h2>Eerst controleren: al gefactureerd?</h2>
  <p class="small muted">Termijnen van projecten die al liepen voordat ze in de app stonden. Geef per project aan wat al gefactureerd is; daarvoor komt geen voorstel.</p>
  <ul class="plijst"><?php foreach ($controleren as $t): ?>
    <li><a href="project.php?nr=<?= e(rawurlencode($t['nummer'])) ?>#financieel"><b><?= e($t['nummer']) ?></b></a> <?= e($t['pnaam']) ?> · termijn <?= (int)$t['volgorde'] ?>: <?= e($t['omschrijving']) ?></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if ($tickets): ?>
<section class="card"><h2>Opgeloste tickets met uren</h2>
  <ul class="plijst"><?php foreach ($tickets as $t): ?><li><a href="project.php?nr=<?= e(rawurlencode($t['nummer'])) ?>#financieel"><b><?= e($t['nummer']) ?></b></a> <?= e($t['omschrijving']) ?> · <?= str_replace('.', ',', (string)(float)$t['uren']) ?> uur nog niet in een voorstel</li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<section class="card">
  <h2>Voorstellen</h2>
  <?php if (!$lijst): ?><p class="muted small">Nog geen factuurvoorstellen.</p><?php endif; ?>
  <div class="tablewrap"><table>
    <?php if ($lijst): ?><thead><tr><th>Project</th><th>Omschrijving</th><th class="r">Excl. btw</th><th>Status</th></tr></thead><?php endif; ?>
    <tbody><?php foreach ($lijst as $r): ?>
      <tr><td><a href="project.php?nr=<?= e(rawurlencode($r['nummer'])) ?>#financieel"><?= e($r['nummer']) ?></a><br><span class="small muted"><?= e($r['klant']) ?></span></td>
        <td><a href="facturen.php?id=<?= (int)$r['id'] ?>"><?= e($r['omschrijving']) ?></a><?= $r['concept_door'] !== '' && $r['status'] === 'concept' ? ' <span class="tag">van ' . e($r['concept_door']) . '</span>' : '' ?></td>
        <td class="r"><?= fmt_eur($r['totaal']) ?></td><td><span class="tag"><?= e(FACTUUR_STATUS[$r['status']]) ?></span><?= $r['boekhouding_nummer'] ? '<br><span class="small muted">' . e($r['boekhouding_nummer']) . '</span>' : '' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<section class="card">
  <details><summary><b>Tarieven voor service (regie)</b> <span class="small muted">uur <?= fmt_eur($tar['uurtarief']) ?> · reis <?= fmt_eur($tar['reistarief'] ?: $tar['uurtarief']) ?> · opslag materiaal <?= (float)$tar['opslag'] ?>%</span></summary>
    <form method="post" class="row wrap mt"><?= csrf_field() ?><input type="hidden" name="actie" value="tarieven">
      <label>Uurtarief (excl. btw) <input name="uurtarief" inputmode="decimal" value="<?= e(str_replace('.', ',', (string)$tar['uurtarief'])) ?>"></label>
      <label>Reistarief per uur <input name="reistarief" inputmode="decimal" value="<?= e(str_replace('.', ',', (string)$tar['reistarief'])) ?>" placeholder="leeg = uurtarief"></label>
      <label>Opslag op inkoop materiaal (%) <input name="opslag" inputmode="decimal" value="<?= e(str_replace('.', ',', (string)$tar['opslag'])) ?>"></label>
      <button class="btn small">Opslaan</button></form>
  </details>
</section>
<?php endif;
page_foot();
