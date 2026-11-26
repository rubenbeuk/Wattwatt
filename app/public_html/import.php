<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/import.php';
$u = require_admin();

$plan = null; $json = ''; $bestand = '';
$aantallen = true; $nulZetten = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $json = (string)($_POST['regels'] ?? '');
    $bestand = clean_text($_POST['bestand'] ?? '');
    $aantallen = isset($_POST['aantallen']);
    $nulZetten = isset($_POST['nulzetten']);
    $regels = json_decode($json, true);
    if (!is_array($regels) || !$regels) {
        flash('Geen regels gevonden in het bestand.', 'err');
        redirect('import.php');
    }
    $plan = import_plan($regels, $aantallen, $nulZetten);
    if (($_POST['stap'] ?? '') === 'uitvoeren') {
        $n = import_uitvoeren($plan, $u['id'], $bestand);
        flash("Import klaar: {$n['nieuw']} nieuw, {$n['gewijzigd']} bijgewerkt" . ($n['nul'] ? ", {$n['nul']} op 0 gezet" : '') . '.');
        redirect('voorraad.php');
    }
}

function toon($v): string { return $v === null || $v === '' ? '–' : (is_numeric($v) ? fmt_n($v) : e($v)); }

page_head('Import', 'import');
?>
<section class="card">
  <h1>Excel importeren</h1>
  <?php if (!$plan): ?>
  <p>Kies het voorraadbestand. Standaard wordt het tabblad <b>Bewerkt</b> gelezen. De kolommen worden herkend aan de kopjes:
     <i>Product_omschrijving, Productgroep, EAN nummer, Aantal stuks, Waarde per stuk</i> (optioneel: <i>Locatie</i>).</p>
  <p class="muted small">Je ziet eerst wat er verandert; pas daarna wordt er iets opgeslagen.</p>
  <form method="post" id="importForm" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="stap" value="preview">
    <input type="hidden" name="regels" id="regels">
    <input type="hidden" name="bestand" id="bestand">
    <label>Bestand <input type="file" id="bestandInput" accept=".xlsx,.xls,.csv" required></label>
    <label id="sheetKeuze" hidden>Tabblad <select id="sheetSelect"></select></label>
    <label class="check"><input type="checkbox" name="aantallen" checked> Voorraadaantallen overnemen uit Excel (telling)</label>
    <label class="check"><input type="checkbox" name="nulzetten"> Producten die niet in de Excel staan op 0 zetten</label>
    <div id="importInfo" class="muted"></div>
    <button class="btn primary big" id="importKnop" disabled>Bekijk wijzigingen</button>
  </form>
  <?php else: ?>
  <p>Bestand: <b><?= e($bestand) ?></b></p>
  <div class="stats">
    <div><b><?= $plan['nieuw'] ?></b><span>nieuw</span></div>
    <div><b><?= $plan['gewijzigd'] ?></b><span>bijgewerkt</span></div>
    <div><b><?= $plan['gelijk'] ?></b><span>ongewijzigd</span></div>
    <?php if ($nulZetten): ?><div><b><?= $plan['nul'] ?></b><span>naar 0</span></div><?php endif; ?>
  </div>
  <form method="post" class="row wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="stap" value="uitvoeren">
    <input type="hidden" name="bestand" value="<?= e($bestand) ?>">
    <textarea name="regels" hidden><?= e($json) ?></textarea>
    <?php if ($aantallen): ?><input type="hidden" name="aantallen" value="1"><?php endif; ?>
    <?php if ($nulZetten): ?><input type="hidden" name="nulzetten" value="1"><?php endif; ?>
    <button class="btn primary big" <?= ($plan['nieuw'] + $plan['gewijzigd'] + $plan['nul']) ? '' : 'disabled' ?>>Import uitvoeren</button>
    <a class="btn ghost" href="import.php">Annuleren</a>
  </form>
  <?php endif; ?>
</section>

<?php if ($plan): ?>
<section class="card">
  <h2>Wijzigingen</h2>
  <div class="tablewrap"><table>
    <thead><tr><th>Actie</th><th>Product</th><th>Groep</th><th>Wat verandert</th></tr></thead>
    <tbody>
    <?php foreach ($plan['rows'] as $it): if ($it['actie'] === 'gelijk') continue; ?>
      <tr>
        <td><span class="tag <?= $it['actie'] === 'nieuw' ? 't-ontvangst' : 't-telling' ?>"><?= $it['actie'] === 'nieuw' ? 'Nieuw' : 'Bijwerken' ?></span></td>
        <td><?= e($it['naam']) ?><?= $it['product'] ? '<br><span class="small muted">' . e($it['product']['code']) . '</span>' : '' ?></td>
        <td><?= e($it['groep']) ?></td>
        <td class="small">
          <?php if ($it['actie'] === 'nieuw'): ?>
            voorraad <?= toon($it['aantal'] ?? 0) ?><?= $it['prijs'] !== null ? ' · ' . fmt_eur($it['prijs']) : '' ?><?= $it['ean'] ? ' · EAN ' . e($it['ean']) : '' ?>
          <?php else: foreach ($it['wijzig'] as $veld => [$oud, $nieuw]): ?>
            <?= e($veld) ?>: <s><?= toon($oud) ?></s> → <b><?= toon($nieuw) ?></b><br>
          <?php endforeach; endif; ?>
          <?= $it['notities'] ? '<i>' . e(implode(', ', $it['notities'])) . '</i>' : '' ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($plan['ontbreekt'] as $p): ?>
      <tr><td><span class="tag t-uit">Naar 0</span></td><td><?= e($p['naam']) ?></td><td><?= e($p['groep']) ?></td><td class="small">voorraad <s><?= fmt_n($p['voorraad']) ?></s> → <b>0</b></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php page_foot($plan ? [] : ['https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js']);
