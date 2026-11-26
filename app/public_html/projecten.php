<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$toonAf = isset($_GET['alle']);
$v = vandaag();
$rows = q("SELECT pr.*,
             (SELECT MIN(pl.datum) FROM planning pl WHERE pl.project_id = pr.id) start,
             (SELECT MAX(pl.datum) FROM planning pl WHERE pl.project_id = pr.id) eind_plan,
             (SELECT MIN(pl.datum) FROM planning pl WHERE pl.project_id = pr.id AND pl.datum >= ?) volgende,
             (SELECT GROUP_CONCAT(DISTINCT pl.datum ORDER BY pl.datum) FROM planning pl WHERE pl.project_id = pr.id AND pl.datum >= ?) dagen,
             (SELECT COUNT(*) FROM taken t WHERE t.project_id = pr.id AND t.status <> 'klaar') taken,
             (SELECT COUNT(*) FROM taken t WHERE t.project_id = pr.id AND t.status <> 'klaar' AND t.deadline < ?) taken_laat
           FROM projecten pr " . ($toonAf ? '' : "WHERE pr.afgerond = 0 AND pr.fase NOT IN ('factuur', 'betaald')"), [$v, dag_plus($v, -60), $v])->fetchAll();

// Open mijlpalen per project (met datum), eerstvolgende eerst
$mpPer = [];
foreach (q('SELECT project_id, datum, titel, zeker FROM project_mijlpalen WHERE gedaan = 0 AND datum IS NOT NULL ORDER BY datum, id')->fetchAll() as $m) $mpPer[(int)$m['project_id']][] = $m;

// Per project: grootte, einde en hoe ver het is
foreach ($rows as &$r) {
    $r['mp'] = $mpPer[(int)$r['id']] ?? [];
    $r['mp1'] = $r['mp'][0] ?? null;
    $r['naam'] = project_naam($r);
    $r['kwh_n'] = $r['kwh'] ?: kwh_uit_tekst($r['systeem'] . ' ' . $r['omschrijving']);
    $r['stap'] = fase_stap($r['fase']);
    $r['groep'] = FASES[$r['fase']][2] ?? 'voorbereiding';
    $klaar = in_array($r['groep'], ['afgerond', 'financieel'], true);
    // Einde: opleverdatum; anders de laatste ploegdag als die nog komt (bij afgeronde projecten ook een dag in het verleden)
    $r['einde'] = $r['opleverdatum'] ?: (($r['eind_plan'] && ($r['eind_plan'] >= $v || $klaar)) ? $r['eind_plan'] : null);
    $r['nog'] = ($r['einde'] && !$klaar) ? (int)round((strtotime($r['einde']) - strtotime($v)) / 86400) : null;
    $r['prj'] = str_starts_with($r['nummer'], 'PRJ-');
    // Gewicht (0-100) bepaalt de hoogte in het overzicht: hoe dichtbij is het werk, hoe groot is het project, loopt er iets achter
    $bezig = $r['groep'] === 'bezig';
    $moment = $r['volgende'] ?: ($bezig ? $v : null);                   // eerstvolgende ploegdag
    if ($r['mp1'] && (!$moment || $r['mp1']['datum'] < $moment)) $moment = $r['mp1']['datum'];   // of een mijlpaal die eerder komt
    $moment = $moment ?: $r['einde'];                                   // anders het einde
    $r['dagen_tot'] = ($moment && !$klaar) ? (int)round((strtotime($moment) - strtotime($v)) / 86400) : null;
    $wTijd = $klaar ? 0 : ($r['dagen_tot'] === null ? 10 : 60 * max(0, 1 - max(0, $r['dagen_tot']) / 56));   // binnen 8 weken telt, vandaag of te laat = 60
    $wGroot = $r['kwh_n'] ? 30 * min(1, sqrt($r['kwh_n'] / 1000)) : 5;                                      // 1 MWh of meer = 30
    $wLaat = min(10, 5 * (int)$r['taken_laat']);                                                            // taken te laat: 5 per stuk, hooguit 10
    $r['gewicht'] = (int)round($wTijd + $wGroot + $wLaat);
    $r['gewicht_uitleg'] = 'Gewicht ' . $r['gewicht'] . ': tijd ' . (int)round($wTijd) . ($r['dagen_tot'] !== null ? ' (' . ($r['dagen_tot'] <= 0 ? 'nu' : 'over ' . $r['dagen_tot'] . ' d') . ')' : ' (niet gepland)')
        . ', grootte ' . (int)round($wGroot) . ', taken te laat ' . $wLaat;
    $r['zoek'] = mb_strtolower($r['nummer'] . ' ' . preg_replace('/\D/', '', $r['nummer']) . ' ' . $r['omschrijving'] . ' ' . $r['klant'] . ' ' . $r['adres'] . ' ' . $r['systeem']);
}
unset($r);
// Zwaarste bovenaan; bij gelijk gewicht wat het eerst af moet
usort($rows, fn($a, $b) => [-$a['gewicht'], $a['einde'] === null, $a['einde'] ?? '', $a['nummer']] <=> [-$b['gewicht'], $b['einde'] === null, $b['einde'] ?? '', $b['nummer']]);
$projecten = array_values(array_filter($rows, fn($r) => $r['prj']));

function taken_tekst(int $n): string { return $n . ($n === 1 ? ' taak' : ' taken'); }
function kwh_tekst(?int $k): string { return $k ? ($k >= 1000 ? str_replace('.', ',', (string)round($k / 1000, 1)) . ' MWh' : $k . ' kWh') : ''; }
function nog_tekst(?int $n): string { return $n === null ? '' : ($n < 0 ? abs($n) . ' d te laat' : ($n === 0 ? 'vandaag' : 'over ' . $n . ' d')); }
function nog_klasse(?int $n): string { return $n === null ? 'muted' : ($n < 0 ? 'rood' : ($n <= 14 ? 'oranje' : 'muted')); }
function gewicht_chip(array $p): string { return '<span class="gewicht g' . ($p['gewicht'] >= 60 ? 'h' : ($p['gewicht'] >= 35 ? 'm' : 'l')) . '" title="' . e($p['gewicht_uitleg']) . '">' . $p['gewicht'] . '</span>'; }
function fasebalk(int $stap): string
{
    $h = '<span class="fasebalk" title="' . e(array_values(FASES)[$stap][0]) . '">';
    for ($i = 0; $i < count(FASES); $i++) $h .= '<i class="' . ($i < $stap ? 'd' : ($i === $stap ? 'n' : '')) . '"></i>';
    return $h . '</span>';
}

// Tijdlijn: van twee weken terug tot tien weken vooruit
$tlVan = maandag_van(dag_plus($v, -14));
$tlTot = dag_plus($tlVan, 12 * 7);
$tlDagen = (strtotime($tlTot) - strtotime($tlVan)) / 86400;
$x = fn(string $d) => max(0, min(100, (strtotime($d) - strtotime($tlVan)) / 86400 / $tlDagen * 100));

page_head('Projecten', 'projecten');
?>
<section class="card projectenkaart">
  <div class="row between wrap">
    <h1>Projecten <span class="muted small" id="prTeller"><?= count($projecten) ?></span></h1>
    <div class="row wrap">
      <div class="weergave" role="tablist">
        <button type="button" class="btn small" data-w="tijdlijn">Tijdlijn</button>
        <button type="button" class="btn small" data-w="bord">Bord</button>
        <button type="button" class="btn small" data-w="lijst">Lijst</button>
      </div>
      <a class="btn ghost small" href="?<?= $toonAf ? '' : 'alle=1' ?>"><?= $toonAf ? 'Alleen operationeel' : 'Ook factuur, betaald en afgevinkt' ?></a>
    </div>
  </div>
  <div class="zoekrij">
    <input type="search" id="prZoek" placeholder="Zoek op naam, PRJ-nummer, klant of adres, bijv. 293 of Hoogkarspel" autocomplete="off">
    <select id="prFilter" aria-label="Filter">
      <option value="">Alle</option><option value="plan">Ingepland</option><option value="geenplan">Niet ingepland</option><option value="geeneinde">Zonder einddatum</option><option value="geenadres">Zonder adres</option>
    </select>
  </div>

  <!-- Tijdlijn -->
  <div class="pw" data-w="tijdlijn">
    <div class="tijdlijn">
      <div class="tl-kop"><span></span><span class="tl-as">
        <?php for ($d = $tlVan; $d < $tlTot; $d = dag_plus($d, 7)): ?><span style="left:<?= $x($d) ?>%">wk <?= weeknr($d) ?></span><?php endfor; ?>
      </span><span class="r">Einde</span></div>
      <?php foreach ($projecten as $p): ?>
      <div class="tl-rij pr-item" data-zoek="<?= e($p['zoek']) ?>" data-plan="<?= $p['volgende'] ? 1 : 0 ?>" data-einde="<?= $p['einde'] ? 1 : 0 ?>" data-adres="<?= $p['adres'] !== '' ? 1 : 0 ?>">
        <a class="tl-naam" href="project.php?nr=<?= e(rawurlencode($p['nummer'])) ?>"><b><?= gewicht_chip($p) ?> <?= e($p['naam']) ?></b>
          <span class="small muted"><?= e($p['nummer']) ?><?= $p['kwh_n'] ? ' · ' . kwh_tekst($p['kwh_n']) : '' ?> · <?= taken_tekst((int)$p['taken']) ?><?= $p['taken_laat'] ? ' <span class="rood">(' . (int)$p['taken_laat'] . ' te laat)</span>' : '' ?></span>
          <?php if ($p['mp1']): ?><span class="small <?= $p['mp1']['datum'] < $v ? 'rood' : 'mp-ink' ?>">◆ <?= e(mijlpaal_tekst($p['mp1'])) ?></span><?php endif; ?></a>
        <div class="tl-baan">
          <?php for ($d = $tlVan; $d <= $tlTot; $d = dag_plus($d, 7)): ?><i class="tl-wk" style="left:<?= $x($d) ?>%"></i><?php endfor; ?>
          <i class="tl-nu" style="left:<?= $x($v) ?>%" title="Vandaag"></i>
          <?php if ($p['start'] && $p['einde'] && $p['einde'] >= $tlVan && $p['start'] < $p['einde']): $b = $x(max($p['start'], $tlVan)); $w = max(1.2, $x($p['einde']) - $b); ?>
            <span class="tl-balk g-<?= e($p['groep']) ?>" style="left:<?= $b ?>%;width:<?= $w ?>%" title="<?= e(FASES[$p['fase']][0]) ?>"><?= $w > 9 ? e(FASES[$p['fase']][0]) : '' ?></span>
            <?php if ($w <= 9): ?><span class="tl-label" style="left:<?= min(88, $b + $w + 0.6) ?>%"><?= e(FASES[$p['fase']][0]) ?></span><?php endif; ?>
          <?php elseif ($p['einde'] && $p['einde'] >= $tlVan): ?>
            <span class="tl-ruit g-<?= e($p['groep']) ?>" style="left:<?= $x($p['einde']) ?>%" title="Einde <?= e(datum_kort($p['einde'])) ?>"></span>
            <span class="tl-label" style="left:<?= min(88, $x($p['einde']) + 1.2) ?>%"><?= e(FASES[$p['fase']][0]) ?></span>
          <?php elseif (!$p['einde']): ?>
            <span class="tl-geen"><?= e(FASES[$p['fase']][0]) ?> · geen einddatum<?= $p['eind_plan'] ? ' (laatste ploegdag ' . e(datum_kort($p['eind_plan'])) . ')' : '' ?></span>
          <?php endif; ?>
          <?php foreach (array_filter(explode(',', (string)$p['dagen'])) as $d): if ($d < $tlVan || $d > $tlTot) continue; ?><i class="tl-dag" style="left:<?= $x($d) ?>%" title="Ploeg op <?= e(datum_kort($d)) ?>"></i><?php endforeach; ?>
          <?php foreach ($p['mp'] as $m): if ($m['datum'] < $tlVan || $m['datum'] > $tlTot) continue; ?><i class="tl-mp<?= $m['zeker'] ? '' : ' verwacht' ?>" style="left:<?= $x($m['datum']) ?>%" title="<?= e(mijlpaal_tekst($m)) ?>"></i><?php endforeach; ?>
          <?php if ($p['opleverdatum'] && $p['opleverdatum'] <= $tlTot): ?><i class="tl-oplever" style="left:<?= $x($p['opleverdatum']) ?>%" title="Opleverdatum <?= e(datum_kort($p['opleverdatum'])) ?>"></i><?php endif; ?>
        </div>
        <span class="tl-einde"><?= $p['einde'] ? e(datum_kort($p['einde'])) . ($p['nog'] !== null ? '<br><span class="small ' . nog_klasse($p['nog']) . '">' . nog_tekst($p['nog']) . '</span>' : '') : '<span class="muted">–</span>' ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="tl-legenda small muted">
      <span><i class="g-voorbereiding"></i>voorbereiding</span><span><i class="g-gepland"></i>gepland</span><span><i class="g-bezig"></i>bezig</span><span><i class="g-afgerond"></i>afgerond</span><?php if ($toonAf): ?><span><i class="g-financieel"></i>factuur en betaling</span><?php endif; ?>
      <span><i class="lg-dag"></i>ploeg op locatie</span><span><i class="lg-mp"></i>mijlpaal</span><span><i class="lg-oplever"></i>opleverdatum</span><span><i class="lg-nu"></i>vandaag</span>
      · Einde = opleverdatum, anders de laatste dag in de planning. · Het getal voor de naam is het gewicht: hoe dichtbij het werk is (tot 60), hoe groot het project is (tot 30) en taken die te laat zijn (tot 10). Zwaarste bovenaan.
    </p>
  </div>

  <!-- Bord per fase -->
  <div class="pw" data-w="bord" hidden>
    <div class="pbord">
      <?php foreach (FASE_GROEPEN as $g => $gnaam): if ($g === 'financieel' && !$toonAf) continue; $in = array_filter($projecten, fn($p) => $p['groep'] === $g); $tot = array_sum(array_column($in, 'kwh_n')); ?>
      <div class="pkol">
        <h3><?= e($gnaam) ?> <span class="muted small"><?= count($in) ?><?= $tot ? ' · ' . kwh_tekst($tot) : '' ?></span></h3>
        <?php foreach ($in as $p): ?>
        <a class="pkaart pr-item" href="project.php?nr=<?= e(rawurlencode($p['nummer'])) ?>" data-zoek="<?= e($p['zoek']) ?>" data-plan="<?= $p['volgende'] ? 1 : 0 ?>" data-einde="<?= $p['einde'] ? 1 : 0 ?>" data-adres="<?= $p['adres'] !== '' ? 1 : 0 ?>">
          <span class="pk-kop"><b><?= gewicht_chip($p) ?> <?= e($p['naam']) ?></b><?php if ($p['kwh_n']): ?><span class="pk-kwh"><?= kwh_tekst($p['kwh_n']) ?></span><?php endif; ?></span>
          <span class="small muted"><?= e($p['nummer']) ?> · <?= e(FASES[$p['fase']][0]) ?></span>
          <?= fasebalk($p['stap']) ?>
          <span class="pk-onder small">
            <span class="<?= $p['taken_laat'] ? 'rood' : 'muted' ?>"><?= taken_tekst((int)$p['taken']) ?><?= $p['taken_laat'] ? ', ' . (int)$p['taken_laat'] . ' te laat' : '' ?></span>
            <span class="<?= nog_klasse($p['nog']) ?>"><?= $p['einde'] ? e(datum_kort($p['einde'])) . ' · ' . nog_tekst($p['nog']) : 'geen einddatum' ?></span>
          </span>
          <?php if ($p['volgende']): ?><span class="small muted">Ploeg: <?= e(datum_kort($p['volgende'])) ?></span><?php endif; ?>
          <?php if ($p['mp1']): ?><span class="small <?= $p['mp1']['datum'] < $v ? 'rood' : 'mp-ink' ?>">◆ <?= e(mijlpaal_tekst($p['mp1'])) ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (!$in): ?><p class="muted small">Niets</p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Lijst (ook wat geen PRJ-nummer heeft) -->
  <div class="pw" data-w="lijst" hidden>
    <ul class="prlijst">
      <?php foreach ($rows as $r): ?>
      <li class="pr-item<?= $r['afgerond'] ? ' muted' : '' ?>" data-zoek="<?= e($r['zoek']) ?>" data-plan="<?= $r['volgende'] ? 1 : 0 ?>" data-einde="<?= $r['einde'] ? 1 : 0 ?>" data-adres="<?= $r['adres'] !== '' ? 1 : 0 ?>">
        <a class="prnr" href="project.php?nr=<?= e(rawurlencode($r['nummer'])) ?>"><?= e($r['nummer']) ?></a>
        <a class="prnaam" href="project.php?nr=<?= e(rawurlencode($r['nummer'])) ?>"><b><?= gewicht_chip($r) ?> <?= e($r['naam']) ?></b><?= $r['klant'] !== '' && $r['klant'] !== $r['naam'] ? ' <span class="muted">· ' . e($r['klant']) . '</span>' : '' ?>
          <span class="small <?= $r['adres'] !== '' ? 'muted' : 'warn-ink' ?>"><?= $r['adres'] !== '' ? e($r['adres']) : 'adres ontbreekt' ?></span></a>
        <span class="small"><?= e(FASES[$r['fase']][0] ?? '') ?><br><?= fasebalk($r['stap']) ?></span>
        <span class="small"><?= $r['einde'] ? '<b>' . e(datum_kort($r['einde'])) . '</b> <span class="' . nog_klasse($r['nog']) . '">' . nog_tekst($r['nog']) . '</span>' : '<span class="muted">geen einde</span>' ?><br><span class="muted"><?= $r['kwh_n'] ? kwh_tekst($r['kwh_n']) : '' ?></span></span>
        <span class="prtaken small muted"><?= (int)$r['taken'] ?> <?= (int)$r['taken'] === 1 ? 'taak' : 'taken' ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <p class="muted" id="prGeen" hidden>Geen project gevonden.</p>
</section>
<?php page_foot();
