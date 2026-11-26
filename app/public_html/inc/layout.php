<?php
function page_head(string $title, string $active = ''): void
{
    $u = user();
    $f = flash();
    $nSeintjes = $u ? (int)q('SELECT COUNT(*) c FROM seintjes WHERE gebruiker_id = ? AND gezien_op IS NULL', [$u['id']])->fetch()['c'] : 0;
    // Hoofdmenu: weinig knoppen. Voorraad-pagina's zitten onder "Voorraad", beheer onder je naam.
    $hoofd = ['dag' => ['dag.php', 'Mijn dag'], 'planning' => ['planning.php', is_admin() ? 'Planning' : 'Ploeg']];
    if (is_admin()) $hoofd['team'] = ['team.php', 'Team'];
    $hoofd['projecten'] = ['projecten.php', 'Projecten'];
    $hoofd['service'] = ['service.php', 'Service'];
    $hoofd['agenda'] = ['agenda.php', 'Agenda'];
    $voorraad = ['scan' => ['index.php', 'Scan'], 'voorraad' => ['voorraad.php', 'Voorraad'], 'inkoop' => ['inkoop.php', 'Inkoop'], 'mutaties' => ['mutaties.php', 'Historie']];
    if (is_admin()) { $voorraad['import'] = ['import.php', 'Import']; $voorraad['labels'] = ['labels.php', 'Labels']; }
    $ik = ['account' => ['account.php', 'Mijn account']];
    if (is_admin()) $ik['gebruikers'] = ['gebruikers.php', 'Gebruikers'];
    $onder = ['dag' => ['dag.php', 'Mijn dag', '☀'], 'planning' => ['planning.php', is_admin() ? 'Planning' : 'Ploeg', '▦'],
              'projecten' => ['projecten.php', 'Projecten', '◧'], 'scan' => ['index.php', 'Scan', '⌗']];
    $inVoorraad = isset($voorraad[$active]);
    ?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#14532d">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon.svg">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css?v=23">
</head>
<body>
<?php if ($u): ?>
<header class="top">
  <a class="brand" href="dag.php"><span class="bolt">⚡</span> Watt Watt</a>
  <nav class="hoofdnav">
    <?php foreach ($hoofd as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <details class="menu<?= $inVoorraad ? ' on' : '' ?>"><summary>Voorraad ▾</summary>
      <div class="menu-lijst"><?php foreach ($voorraad as $key => [$href, $label]): ?><a href="<?= e($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?></div>
    </details>
  </nav>
  <div class="topr">
    <a href="dag.php#seintjes" id="seintjeTeller" class="seintjeknop<?= $nSeintjes ? ' aan' : '' ?>" data-n="<?= $nSeintjes ?>" title="Seintjes"><?= $nSeintjes ? $nSeintjes . ' nieuw' : 'Seintjes' ?></a>
    <details class="menu rechts"><summary><span class="ik-naam"><?= e(explode(' ', $u['naam'])[0]) ?></span><span class="ik-menu">Menu</span> ▾</summary>
      <div class="menu-lijst">
        <div class="alleen-mobiel">
          <?php foreach ($hoofd + $voorraad as $key => [$href, $label]): ?><a href="<?= e($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
          <hr>
        </div>
        <?php foreach ($ik as $key => [$href, $label]): ?><a href="<?= e($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
        <form method="post" action="logout.php"><?= csrf_field() ?><button class="menu-knop">Uitloggen</button></form>
      </div>
    </details>
  </div>
</header>
<nav class="onderbalk">
  <?php foreach ($onder as $key => [$href, $label, $ico]): ?><a href="<?= e($href) ?>" class="<?= $key === $active || ($key === 'scan' && $inVoorraad) ? 'on' : '' ?>"><span aria-hidden="true"><?= $ico ?></span><?= e($label) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<main>
<?php if ($f): ?><div class="flash <?= e($f['type']) ?>"><?= $f['msg'] /* bevat bewust HTML, altijd ge-escaped bij opbouw */ ?></div><?php endif; ?>
<?php
}

function page_foot(array $scripts = []): void
{
    ?>
</main>
<?php foreach ($scripts as $s): ?><script src="<?= e($s) ?>"></script>
<?php endforeach; ?>
<script src="assets/app.js?v=20"></script>
</body>
</html>
<?php
}

// ---------- Bouwstenen voor de operatie-schermen ----------
/** Alleen terug naar een eigen pagina van de app. */
function terug_veilig(?string $t, string $standaard = 'dag.php'): string
{
    $t = (string)$t;
    return preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&%\-\.]*)?(\#[A-Za-z0-9_\-]*)?$#', $t) ? $t : $standaard;
}

/**
 * Kanbanbord voor Mijn dag: bovenaan de dagen (Te laat, Vandaag, Morgen, ...), daaronder kolommen per belang.
 * Kaarten zijn te slepen naar een andere kolom (belang) of naar een dag (verzetten).
 */
function taken_bord(array $taken, string $terug, bool $toonEigenaar = false): void
{
    $v = vandaag();
    $tabs = [['laat', 'Te laat', '', '', 1, 0, 0]];
    $tabs[] = ['vandaag', 'Vandaag', '0000-00-00', $v, 0, 0, 1];
    $vorige = $v;
    for ($i = 1; $i <= 4; $i++) {
        $d = volgende_werkdag($vorige);
        $tabs[] = ["d$i", $i === 1 ? 'Morgen' : ($i === 2 ? 'Overmorgen' : ucfirst(datum_kort($d))), dag_plus($vorige, 1), $d, 0, 0, 1, $i <= 2 ? datum_kort($d) : ''];
        $vorige = $d;
    }
    $tabs[] = ['later', 'Later', dag_plus($vorige, 1), '9999-12-31', 0, 0, 0];
    $tabs[] = ['geen', 'Zonder datum', '', '', 0, 1, 0];
    $zondag = dag_plus(maandag_van($v), 6);
    $tabs[] = ['week', 'Deze week', '0000-00-00', $zondag, 1, 0, 0];
    $tabs[] = ['volgende', 'Volgende week', dag_plus($zondag, 1), dag_plus($zondag, 7), 0, 0, 0];
    $laat = array_filter($taken, fn($t) => taak_dag($t) === 'laat');
    // Binnen een kolom: eerst de volgorde die iemand zelf koos, dan de datum
    usort($taken, fn($a, $b) => [(int)$a['volgorde'], (string)($a['datum'] ?: $a['deadline'] ?: '9999'), (int)$a['id']] <=> [(int)$b['volgorde'], (string)($b['datum'] ?: $b['deadline'] ?: '9999'), (int)$b['id']]);
    ?>
<div class="bord" data-terug="<?= e($terug) ?>">
  <div class="bord-dagen" role="tablist">
    <?php foreach ($tabs as $t): [$k, $naam, $van, $tot, $isLaat, $isGeen, $doel] = $t; ?>
      <button type="button" class="bord-dag<?= $k === 'laat' ? ' laat' : '' ?>" data-k="<?= $k ?>" data-van="<?= e($van) ?>" data-tot="<?= e($tot) ?>"
        data-laat="<?= $isLaat ?>" data-geen="<?= $isGeen ?>" <?= $doel ? 'data-doel="' . e($tot) . '"' : '' ?>>
        <b><?= e($naam) ?></b><span><span class="n">0</span> taken<?= !empty($t[7]) ? ' · ' . e($t[7]) : '' ?></span></button>
    <?php endforeach; ?>
  </div>
  <?php if ($laat): ?>
  <form method="post" action="taak.php" class="bord-bulk row wrap" hidden>
    <?= csrf_field() ?><input type="hidden" name="actie" value="bulk"><input type="hidden" name="terug" value="<?= e($terug) ?>">
    <?php foreach ($laat as $t): ?><input type="hidden" name="ids[]" value="<?= (int)$t['id'] ?>"><?php endforeach; ?>
    <span>Alle <?= count($laat) ?> taken die te laat zijn verzetten naar</span>
    <input type="date" name="datum" value="<?= e(volgende_werkdag($v)) ?>" min="<?= e($v) ?>" required>
    <button class="btn small primary">Verzetten</button>
    <span class="small muted">De uiterlijke datum schuift mee.</span>
  </form>
  <?php endif; ?>
  <div class="bord-kolommen">
    <?php foreach (TAAK_PRIO as $p => $pnaam): ?>
      <div class="bord-kol" data-p="<?= $p ?>">
        <h3><?= e($pnaam) ?> <span class="muted n"></span></h3>
        <?php foreach ($taken as $t) if ($t['prioriteit'] === $p && $t['status'] !== 'wacht') taak_kaart($t, $terug, $toonEigenaar); ?>
        <p class="bord-leeg muted small">Niets</p>
      </div>
    <?php endforeach; ?>
    <div class="bord-kol wacht" data-p="wacht">
      <h3><span>Wacht op <span class="muted small">(alle dagen)</span></span> <span class="muted n"></span></h3>
      <?php foreach ($taken as $t) if ($t['status'] === 'wacht') taak_kaart($t, $terug, $toonEigenaar); ?>
      <p class="bord-leeg muted small">Nergens op aan het wachten</p>
    </div>
  </div>
  <p class="small muted bord-uitleg">Sleep een kaart naar een andere kolom om het belang te wijzigen, naar <b>Wacht op</b> als je op iemand wacht, of naar een dag bovenaan om hem te verzetten. Sleep binnen een kolom om de volgorde te bepalen. Met ✎ zet je een status of notitie (en ↑ ↓ op de telefoon). Met +1 schuift hij naar de volgende werkdag.</p>
</div>
<?php
}

/** Compacte taakkaart voor het bord: titel, waar hij op wacht, de laatste stand en de knoppen. */
function taak_kaart(array $t, string $terug, bool $toonEigenaar = false): void
{
    $laat = taak_te_laat($t);
    $wacht = $t['status'] === 'wacht';
    $laatste = null;
    if (!empty($t['laatste'])) {
        [$op, $wie, $tekst] = array_pad(explode('|', $t['laatste'], 3), 3, '');
        // Bij Wacht op staat de wachtregel al op de kaart: alleen de notitie tonen
        if ($wacht && str_starts_with($tekst, 'Wacht op')) { $tekst = trim((string)(explode(': ', $tekst, 2)[1] ?? '')); }
        if ($tekst !== '') $laatste = substr(fmt_dt($op), 0, 5) . ($wie !== '' ? ' ' . explode(' ', $wie)[0] : '') . ': ' . $tekst;
    }
    ?>
<div class="kaart<?= $laat ? ' laat' : '' ?><?= $wacht ? ' wacht' : '' ?>" draggable="true" data-id="<?= (int)$t['id'] ?>" data-dag="<?= e(taak_dag($t)) ?>" data-wacht="<?= $wacht ? 1 : 0 ?>" data-p="<?= e($t['prioriteit']) ?>">
  <a class="kaart-titel" href="taak.php?id=<?= (int)$t['id'] ?>"><?= e($t['titel']) ?></a>
  <?php if ($wacht): ?><div class="kaart-wacht">⏸ Wacht op <?= e($t['wacht_op'] !== '' ? $t['wacht_op'] : '?') ?></div><?php endif; ?>
  <?php if ($laatste): ?><div class="kaart-stand" title="<?= e($laatste) ?>"><?= e(mb_strimwidth($laatste, 0, 140, '…')) ?></div><?php endif; ?>
  <div class="kaart-onder">
    <span class="kaart-meta">
      <?php if (($t['van_rol'] ?? '') === 'monteur'): ?><span class="tag st-bezig">van <?= e(explode(' ', (string)$t['van'])[0]) ?></span><?php endif; ?>
      <?php if ($t['status'] === 'bezig'): ?><span class="tag st-bezig">Bezig</span><?php endif; ?>
      <?php if ($wacht && $t['prioriteit'] !== 'normaal'): ?><span class="tag p-<?= e($t['prioriteit']) ?>"><?= e(TAAK_PRIO[$t['prioriteit']]) ?></span><?php endif; ?>
      <?php if ($t['nummer']): ?><?= e(project_naam($t)) ?><?php endif; ?>
      <?php if ($toonEigenaar): ?> · <?= e(explode(' ', (string)($t['eigenaar'] ?? 'Nog te verdelen'))[0]) ?><?php endif; ?>
      <?php if ($t['deadline']): ?><span class="kaart-dl<?= $laat ? ' rood' : '' ?>"> · uiterlijk <?= e(datum_kort($t['deadline'])) ?></span><?php endif; ?>
    </span>
    <form method="post" action="taak.php" class="kaart-knoppen">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="terug" value="<?= e($terug) ?>">
      <button type="button" class="kk kaart-meer-knop" title="Status, wacht op of notitie" aria-label="Status of notitie">✎</button>
      <button class="kk" name="actie" value="morgen" title="Naar de volgende werkdag">+1</button>
      <button class="kk ok" name="actie" value="klaar" title="Gedaan" aria-label="Gedaan">✓</button>
    </form>
  </div>
  <form method="post" action="taak.php" class="kaart-meer stack" hidden>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="terug" value="<?= e($terug) ?>"><input type="hidden" name="actie" value="status">
    <?php if (!empty($t['toelichting'])): ?><p class="small muted kaart-toel"><?= e(mb_strimwidth((string)$t['toelichting'], 0, 300, '…')) ?></p><?php endif; ?>
    <div class="kaart-status" role="radiogroup" aria-label="Status">
      <?php foreach (['open' => 'Open', 'bezig' => 'Bezig', 'wacht' => 'Wacht op'] as $k => $v): ?>
        <label><input type="radio" name="status" value="<?= $k ?>" <?= $t['status'] === $k ? 'checked' : '' ?>> <?= e($v) ?></label>
      <?php endforeach; ?>
    </div>
    <input name="wacht_op" value="<?= e($t['wacht_op']) ?>" placeholder="Wacht op wie of wat, bijv. Robert Geerlings (Spuigroep)" class="kaart-wachtop">
    <input name="notitie" placeholder="Stand of notitie, bijv. eerste aanvraag gedaan bij ...">
    <div class="row wrap"><button class="btn small primary">Opslaan</button><button type="button" class="btn small ghost kaart-meer-dicht">Annuleren</button>
      <span class="kaart-schuif"><button type="button" class="btn small" data-schuif="-1" title="Hoger in de kolom">↑</button><button type="button" class="btn small" data-schuif="1" title="Lager in de kolom">↓</button></span></div>
  </form>
</div>
<?php
}

/** Eén taak als regel, met de knoppen Gedaan en Status. */
function taak_rij(array $t, string $terug, bool $toonEigenaar = false): void
{
    $laat = taak_te_laat($t);
    ?>
<li class="taak<?= $t['status'] === 'klaar' ? ' af' : '' ?>" id="taak-<?= (int)$t['id'] ?>">
  <div class="taak-hoofd">
    <div class="grow">
      <a class="taak-titel" href="taak.php?id=<?= (int)$t['id'] ?>"><?= e($t['titel']) ?></a>
      <div class="small muted">
        <?php if ($t['nummer']): ?><a href="project.php?nr=<?= e(rawurlencode($t['nummer'])) ?>"><?= e(project_naam($t)) ?></a> · <?php endif; ?>
        <?php if ($toonEigenaar): ?><?= e($t['eigenaar'] ?? 'Nog te verdelen') ?> · <?php endif; ?>
        <?php if ($t['deadline']): ?>uiterlijk <?= e(datum_kort($t['deadline'])) ?> · <?php endif; ?>
        <?= e($t['bron']) ?>
      </div>
      <div class="taak-tags">
        <?php if (($t['van_rol'] ?? '') === 'monteur'): ?><span class="tag st-bezig">Vraag van <?= e(explode(' ', (string)$t['van'])[0]) ?></span><?php endif; ?>
        <?php if ($laat): ?><span class="tag t-laat">Te laat</span><?php endif; ?>
        <?php if (in_array($t['prioriteit'], ['kritiek', 'hoog'], true)): ?><span class="tag p-<?= e($t['prioriteit']) ?>"><?= e(TAAK_PRIO[$t['prioriteit']]) ?></span><?php endif; ?>
        <?php if ($t['status'] !== 'open'): ?><span class="tag st-<?= e($t['status']) ?>"><?= e(TAAK_STATUS[$t['status']]) ?><?= $t['status'] === 'wacht' && $t['wacht_op'] !== '' ? ' ' . e($t['wacht_op']) : '' ?></span><?php endif; ?>
      </div>
    </div>
    <?php if ($t['status'] !== 'klaar'): ?>
    <form method="post" action="taak.php">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="terug" value="<?= e($terug) ?>">
      <button class="btn klaar" name="actie" value="klaar">Gedaan</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if ($t['status'] !== 'klaar'): ?>
  <details class="taak-meer">
    <summary>Status of doorschuiven</summary>
    <form method="post" action="taak.php" class="stack">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="terug" value="<?= e($terug) ?>">
      <div class="row wrap">
        <label class="grow">Status
          <select name="status">
            <?php foreach (['open', 'bezig', 'wacht'] as $s): ?><option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= e(TAAK_STATUS[$s]) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="grow">Wacht op wie of wat <input name="wacht_op" value="<?= e($t['wacht_op']) ?>" placeholder="bijv. antwoord klant"></label>
      </div>
      <label>Korte notitie <input name="notitie" placeholder="bijv. alles besteld, komt donderdag"></label>
      <div class="row wrap">
        <button class="btn primary" name="actie" value="status">Opslaan</button>
        <button class="btn" name="actie" value="morgen">Naar de volgende werkdag</button>
      </div>
    </form>
  </details>
  <?php endif; ?>
</li>
<?php
}
