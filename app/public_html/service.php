<?php
// Servicebord: storingen en serviceverzoeken (was het bord "Service projecten" in Pipedrive).
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) { http_response_code(403); exit('Alleen kantoor maakt tickets aan.'); }
    if (($_POST['actie'] ?? '') === 'nieuw') {
        try {
            $r = ticket_opslaan([
                'klant' => $_POST['klant'] ?? '', 'titel' => $_POST['titel'] ?? '', 'adres' => $_POST['adres'] ?? '', 'telefoon' => $_POST['telefoon'] ?? '',
                'email' => $_POST['email'] ?? '', 'apparaat' => $_POST['apparaat'] ?? '', 'urgentie' => $_POST['urgentie'] ?? 'normaal',
                'afhandeling' => $_POST['afhandeling'] ?? '', 'foutcode' => $_POST['foutcode'] ?? '', 'beschrijving' => $_POST['beschrijving'] ?? '',
                'eigenaar' => $_POST['eigenaar'] ?? '',
            ], $u['naam']);
            flash('Ticket ' . e($r['nummer']) . ' aangemaakt.');
            redirect('project.php?nr=' . rawurlencode($r['nummer']));
        } catch (InvalidArgumentException $e) { flash('Vul minstens de klant of een korte omschrijving in.', 'err'); }
    } elseif (($_POST['actie'] ?? '') === 'fase') {
        $t = q("SELECT id FROM projecten WHERE nummer = ? AND soort = 'service'", [clean_text($_POST['nr'] ?? '')])->fetch();
        if ($t && isset(SERVICE_FASES[$_POST['fase'] ?? ''])) { ticket_fase((int)$t['id'], $_POST['fase']); flash('Ticket verplaatst naar ' . e(SERVICE_FASES[$_POST['fase']][0]) . '.'); }
    }
    redirect('service.php');
}

$toonOpgelost = isset($_GET['opgelost']);
$v = vandaag();
$tickets = q("SELECT p.*, g.naam AS eigenaar,
                (SELECT COUNT(*) FROM taken t WHERE t.project_id = p.id AND t.status <> 'klaar') taken,
                (SELECT MIN(pl.datum) FROM planning pl WHERE pl.project_id = p.id AND pl.datum >= ?) volgende
              FROM projecten p LEFT JOIN gebruikers g ON g.id = p.leider_id
              WHERE p.soort = 'service' AND (p.fase <> 'opgelost'" . ($toonOpgelost ? ' OR p.opgelost_op >= UTC_TIMESTAMP() - INTERVAL 30 DAY' : '') . ")
              ORDER BY FIELD(p.urgentie, 'acuut', 'spoed', 'normaal', 'laag', ''), p.startdatum, p.id", [$v])->fetchAll();
$per = [];
foreach ($tickets as $t) $per[$t['fase']][] = $t;
$open = count(array_filter($tickets, fn($t) => $t['fase'] !== 'opgelost'));
$spoed = count(array_filter($tickets, fn($t) => $t['fase'] !== 'opgelost' && in_array($t['urgentie'], ['acuut', 'spoed'], true)));

function dagen_open(?string $start, string $v): string
{
    if (!$start) return '';
    $n = (int)round((strtotime($v) - strtotime($start)) / 86400);
    return $n <= 0 ? 'vandaag' : ($n === 1 ? '1 dag' : "$n dagen");
}

page_head('Service', 'service');
?>
<section class="card">
  <div class="row between wrap">
    <div>
      <h1>Service</h1>
      <p class="muted small"><?= $open ?> open <?= $open === 1 ? 'ticket' : 'tickets' ?><?= $spoed ? ' · <span class="rood">' . $spoed . ' met spoed</span>' : '' ?>. Tickets van het webformulier komen hier vanzelf binnen.</p>
    </div>
    <div class="row wrap">
      <a class="btn ghost" href="service.php<?= $toonOpgelost ? '' : '?opgelost' ?>"><?= $toonOpgelost ? 'Verberg opgelost' : 'Toon opgelost (30 dagen)' ?></a>
      <?php if (is_admin()): ?><button type="button" class="btn primary" onclick="document.getElementById('nieuwTicket').hidden = !document.getElementById('nieuwTicket').hidden">Nieuw ticket</button><?php endif; ?>
    </div>
  </div>

  <?php if (is_admin()): ?>
  <form method="post" class="stack" id="nieuwTicket" hidden>
    <?= csrf_field() ?><input type="hidden" name="actie" value="nieuw">
    <div class="row wrap">
      <label class="grow">Klant <input name="klant" required placeholder="naam van de klant"></label>
      <label class="grow">Wat is er aan de hand (kort) <input name="titel" placeholder="bijv. batterij laadt niet meer"></label>
    </div>
    <div class="row wrap">
      <label class="grow">Adres <input name="adres" placeholder="Straat 1, 1234 AB Plaats"></label>
      <label class="grow">Telefoon <input name="telefoon" inputmode="tel"></label>
      <label class="grow">E-mail <input type="email" name="email"></label>
    </div>
    <div class="row wrap">
      <label class="grow">Apparaat <select name="apparaat"><option value="">–</option><?php foreach (APPARATEN as $k => $n): ?><option value="<?= $k ?>"><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Urgentie <select name="urgentie"><?php foreach (URGENTIES as $k => $n): ?><option value="<?= $k ?>" <?= $k === 'normaal' ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Afhandeling <select name="afhandeling"><?php foreach (AFHANDELINGEN as $k => $n): ?><option value="<?= $k ?>" <?= $k === 'onbekend' ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label class="grow">Foutcode <input name="foutcode"></label>
      <label class="grow">Eigenaar <select name="eigenaar"><option value="">Nog niemand</option><?php foreach (mensen_kantoor() as $m): ?><option value="<?= e($m['gebruikersnaam']) ?>"><?= e($m['naam']) ?></option><?php endforeach; ?></select></label>
    </div>
    <label>Beschrijving <textarea name="beschrijving" rows="3" placeholder="wat de klant merkt, sinds wanneer, wat al geprobeerd is"></textarea></label>
    <button class="btn primary">Ticket aanmaken</button>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <div class="pbord sbord">
    <?php foreach (SERVICE_FASES as $f => [$fnaam]): if ($f === 'opgelost' && !$toonOpgelost) continue; $in = $per[$f] ?? []; ?>
    <div class="pkol">
      <h3><?= e($fnaam) ?> <span class="muted small"><?= count($in) ?></span></h3>
      <?php foreach ($in as $t): $sp = in_array($t['urgentie'], ['acuut', 'spoed'], true); ?>
      <div class="pkaart<?= $sp ? ' spoed' : '' ?>">
        <a href="project.php?nr=<?= e(rawurlencode($t['nummer'])) ?>" class="pk-kop" style="text-decoration:none;color:inherit"><b><?= e($t['klant'] ?: $t['omschrijving']) ?></b></a>
        <?php if ($t['klant'] && $t['omschrijving'] !== $t['klant']): ?><span class="small"><?= e(mb_strimwidth($t['omschrijving'], 0, 90, '…')) ?></span><?php endif; ?>
        <span class="small muted"><?= e($t['nummer']) ?><?= $t['apparaat'] ? ' · ' . e(APPARATEN[$t['apparaat']] ?? '') : '' ?></span>
        <span class="small">
          <?php if ($t['urgentie']): ?><span class="tag<?= $sp ? ' rood' : '' ?>"><?= e(URGENTIES[$t['urgentie']] ?? $t['urgentie']) ?></span><?php endif; ?>
          <?php if ($t['afhandeling']): ?><span class="tag"><?= e(AFHANDELINGEN[$t['afhandeling']] ?? '') ?></span><?php endif; ?>
        </span>
        <span class="pk-onder small">
          <span class="muted"><?= e($t['eigenaar'] ? explode(' ', $t['eigenaar'])[0] : 'geen eigenaar') ?><?= $t['taken'] ? ' · ' . (int)$t['taken'] . ' ' . ((int)$t['taken'] === 1 ? 'taak' : 'taken') : '' ?></span>
          <span class="<?= $t['einddatum'] && $t['einddatum'] < $v && $f !== 'opgelost' ? 'rood' : 'muted' ?>"><?= e(dagen_open($t['startdatum'], $v)) ?></span>
        </span>
        <?php if ($t['telefoon'] !== ''): ?><a class="small" href="tel:<?= e(preg_replace('/[^\d+]/', '', $t['telefoon'])) ?>"><?= e($t['telefoon']) ?></a><?php endif; ?>
        <?php if ($t['volgende']): ?><span class="small muted">Ingepland: <?= e(datum_kort($t['volgende'])) ?></span><?php endif; ?>
        <?php if (is_admin()): ?>
        <form method="post" class="row" style="gap:.3rem;margin-top:.3rem">
          <?= csrf_field() ?><input type="hidden" name="actie" value="fase"><input type="hidden" name="nr" value="<?= e($t['nummer']) ?>">
          <select name="fase" aria-label="Verplaats naar" onchange="this.form.submit()" style="min-height:34px;padding:.2rem .4rem;font-size:.85rem">
            <?php foreach (SERVICE_FASES as $k => [$n]): ?><option value="<?= $k ?>" <?= $k === $f ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$in): ?><p class="muted small">Niets</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php page_foot();
