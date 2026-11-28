<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/materiaal.php';
require_login();

$nr = project_nummer($_GET['nr'] ?? $_POST['nr'] ?? '');
$pr = q('SELECT * FROM projecten WHERE nummer = ?', [$nr])->fetch();
if (!$pr) { flash('Project niet gevonden.', 'err'); redirect('projecten.php'); }
$terug = 'project.php?nr=' . rawurlencode($nr);
$service = ($pr['soort'] ?? 'project') === 'service';
$FASES = fases($pr['soort'] ?? 'project');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? 'opslaan';
    if ($actie === 'opslaan') {
        if (!is_admin()) { http_response_code(403); exit('Alleen kantoor past projectgegevens aan.'); }
        $fase = isset($FASES[$_POST['fase'] ?? '']) ? $_POST['fase'] : $pr['fase'];
        $kwh = num($_POST['kwh'] ?? null);
        if ($service) {
            q('UPDATE projecten SET telefoon = ?, email = ?, apparaat = ?, urgentie = ?, afhandeling = ?, foutcode = ?, beschrijving = ?, einddatum = ? WHERE id = ?',
                [mb_substr(clean_text($_POST['telefoon'] ?? ''), 0, 50), mb_substr(clean_text($_POST['email'] ?? ''), 0, 150),
                 isset(APPARATEN[$_POST['apparaat'] ?? '']) ? $_POST['apparaat'] : '', isset(URGENTIES[$_POST['urgentie'] ?? '']) ? $_POST['urgentie'] : '',
                 isset(AFHANDELINGEN[$_POST['afhandeling'] ?? '']) ? $_POST['afhandeling'] : '', mb_substr(clean_text($_POST['foutcode'] ?? ''), 0, 100),
                 trim((string)($_POST['beschrijving'] ?? '')) ?: null, geldige_datum($_POST['einddatum'] ?? null), $pr['id']]);
        }
        q('UPDATE projecten SET omschrijving = ?, klant = ?, adres = ?, contact = ?, leider_id = ?, afgerond = ?, fase = ?, opleverdatum = ?, kwh = ?, systeem = ? WHERE id = ?',
            [mb_substr(clean_text($_POST['omschrijving'] ?? ''), 0, 255), mb_substr(clean_text($_POST['klant'] ?? ''), 0, 150), mb_substr(clean_text($_POST['adres'] ?? ''), 0, 255),
             mb_substr(clean_text($_POST['contact'] ?? ''), 0, 255), (int)($_POST['leider_id'] ?? 0) ?: null, isset($_POST['afgerond']) ? 1 : 0,
             $fase, geldige_datum($_POST['opleverdatum'] ?? null), $kwh !== null && $kwh > 0 ? (int)round($kwh) : null, mb_substr(clean_text($_POST['systeem'] ?? ''), 0, 150), $pr['id']]);
        if ($service) ticket_fase((int)$pr['id'], $fase);
        flash($service ? 'Ticket opgeslagen.' : 'Project opgeslagen.');
    } elseif ($actie === 'fase' && is_admin()) {
        $fase = $_POST['fase'] ?? '';
        if (isset($FASES[$fase])) {
            if ($service) ticket_fase((int)$pr['id'], $fase); else q('UPDATE projecten SET fase = ? WHERE id = ?', [$fase, $pr['id']]);
            flash('Fase: ' . e($FASES[$fase][0]) . '.');
        }
    } elseif ($actie === 'mijlpaal' && is_admin()) {
        $titel = mb_substr(clean_text($_POST['titel'] ?? ''), 0, 200);
        if ($titel === '') flash('Vul in wat de mijlpaal is.', 'err');
        else {
            q('INSERT INTO project_mijlpalen (project_id, datum, titel, soort, zeker, toelichting, bron, door) VALUES (?,?,?,?,?,?,?,?)',
                [$pr['id'], geldige_datum($_POST['datum'] ?? null), $titel, isset(MIJLPAAL_SOORT[$_POST['soort'] ?? '']) ? $_POST['soort'] : 'overig',
                 isset($_POST['verwacht']) ? 0 : 1, mb_substr(clean_text($_POST['toelichting'] ?? ''), 0, 500), 'hand', user()['naam'] ?? '']);
            flash('Mijlpaal toegevoegd.');
        }
    } elseif ($actie === 'mijlpaal_gedaan' && is_admin()) {
        q('UPDATE project_mijlpalen SET gedaan = 1 - gedaan, gedaan_op = IF(gedaan = 1, UTC_TIMESTAMP(), NULL) WHERE id = ? AND project_id = ?', [(int)($_POST['mijlpaal_id'] ?? 0), $pr['id']]);
    } elseif ($actie === 'mijlpaal_wijzig' && is_admin()) {
        $titel = mb_substr(clean_text($_POST['titel'] ?? ''), 0, 200);
        if ($titel !== '') q('UPDATE project_mijlpalen SET datum = ?, titel = ?, zeker = ? WHERE id = ? AND project_id = ?',
            [geldige_datum($_POST['datum'] ?? null), $titel, isset($_POST['verwacht']) ? 0 : 1, (int)($_POST['mijlpaal_id'] ?? 0), $pr['id']]);
        flash('Mijlpaal aangepast.');
    } elseif ($actie === 'mijlpaal_weg' && is_admin()) {
        q('DELETE FROM project_mijlpalen WHERE id = ? AND project_id = ?', [(int)($_POST['mijlpaal_id'] ?? 0), $pr['id']]);
        flash('Mijlpaal verwijderd.');
    } elseif ($actie === 'notitie') {
        $tekst = mb_substr(trim((string)($_POST['tekst'] ?? '')), 0, 2000);
        if ($tekst === '') flash('Schrijf eerst je notitie.', 'err');
        else { $u = user(); q('INSERT INTO notities (gebruiker_id, project_id, tekst) VALUES (?,?,?)', [$u['id'], $pr['id'], $tekst]); flash('Notitie opgeslagen. Iedereen op kantoor ziet hem; Claude verwerkt hem bij de volgende ronde.'); }
        redirect($terug . (($_POST['terug_tab'] ?? '') === 'overzicht' ? '' : '#tijdlijn'));
    } elseif ($actie === 'vraag' && !is_onderaannemer()) {
        // "Vraag Claude": de lichte run (elk uur op werkdagen) beantwoordt hem; het antwoord komt hier en op de tijdlijn
        $tekst = mb_substr(trim((string)($_POST['tekst'] ?? '')), 0, 2000);
        if ($tekst === '') flash('Schrijf eerst je vraag.', 'err');
        else { $u = user(); q("INSERT INTO notities (gebruiker_id, project_id, soort, tekst) VALUES (?,?,'vraag',?)", [$u['id'], $pr['id'], $tekst]); flash('Vraag verstuurd. Claude antwoordt bij de volgende ronde (elk uur op werkdagen); je krijgt een seintje.'); }
        redirect($terug . '#vraag');
    } elseif ($actie === 'installatie_koppel' && is_admin()) {
        $iid = (int)($_POST['installatie_id'] ?? 0) ?: null;
        q('UPDATE projecten SET installatie_id = ? WHERE id = ?', [$iid, $pr['id']]);
        flash($iid ? 'Ticket gekoppeld aan de installatie.' : 'Koppeling met installatie weggehaald.');
        redirect($terug . '#installatie');
    } elseif ($actie === 'begroting' && is_admin()) {
        $regels = [];
        foreach ((array)($_POST['b_oms'] ?? []) as $n => $oms) $regels[] = ['omschrijving' => $oms, 'soort' => $_POST['b_soort'][$n] ?? 'materiaal', 'aantal' => $_POST['b_aantal'][$n] ?? 1, 'eenheid' => $_POST['b_eenheid'][$n] ?? 'stuks', 'prijs' => $_POST['b_prijs'][$n] ?? 0];
        $n = begroting_opslaan((int)$pr['id'], $regels, 'hand: ' . user()['naam'], true);
        flash("Begroting opgeslagen ($n regels).");
        redirect($terug . '#financieel');
    } elseif ($actie === 'termijnen' && is_admin()) {
        $t = [];
        foreach ((array)($_POST['t_oms'] ?? []) as $n => $oms) $t[] = ['omschrijving' => $oms, 'percentage' => $_POST['t_pct'][$n] ?? null, 'bedrag' => $_POST['t_bedrag'][$n] ?? null, 'moment' => $_POST['t_moment'][$n] ?? 'handmatig'];
        $r = termijnen_opslaan((int)$pr['id'], $t);
        flash(isset($r['fout']) ? ucfirst($r['fout']) . '.' : "Termijnen opgeslagen ({$r['termijnen']}).", isset($r['fout']) ? 'err' : 'ok');
        redirect($terug . '#financieel');
    } elseif ($actie === 'termijn_al' && is_admin()) {
        flash(e(termijn_al_gefactureerd((int)($_POST['termijn_id'] ?? 0), (string)($_POST['nummer'] ?? ''), user())));
        redirect($terug . '#financieel');
    } elseif ($actie === 'termijn_nog' && is_admin()) {
        q('UPDATE termijnen SET controleren = 0 WHERE id = ? AND project_id = ?', [(int)($_POST['termijn_id'] ?? 0), $pr['id']]);
        flash('Termijn staat open om te factureren.');
        redirect($terug . '#financieel');
    } elseif ($actie === 'factuur_termijn' && is_admin()) {
        $r = factuur_voorstel_termijn((int)($_POST['termijn_id'] ?? 0), user()['naam']);
        flash(isset($r['fout']) ? ucfirst($r['fout']) . '.' : 'Factuurvoorstel gemaakt: ' . fmt_eur($r['bedrag']) . ' excl. btw.', isset($r['fout']) ? 'err' : 'ok');
        redirect(isset($r['factuur']) && !isset($r['fout']) ? 'facturen.php?id=' . $r['factuur'] : $terug . '#financieel');
    } elseif ($actie === 'factuur_regie' && is_admin()) {
        $r = factuur_voorstel_regie((int)$pr['id'], user()['naam']);
        flash(isset($r['fout']) ? ucfirst($r['fout']) . '.' : 'Factuurvoorstel gemaakt met ' . $r['regels'] . ' regels.' . ($r['zonder_tarief'] ? ' Let op: er is nog geen uurtarief ingesteld (pagina Facturen).' : ''), isset($r['fout']) ? 'err' : 'ok');
        redirect(isset($r['factuur']) ? 'facturen.php?id=' . $r['factuur'] : $terug . '#financieel');
    } elseif ($actie === 'materiaal_import' && is_admin()) {
        $regels = json_decode((string)($_POST['regels'] ?? ''), true);
        if (!is_array($regels) || !$regels) { flash('Geen regels gevonden in het bestand.', 'err'); redirect($terug); }
        $r = materiaallijst_import($pr['nummer'], $regels, 'Excel ' . clean_text($_POST['bestand'] ?? ''), isset($_POST['vervang']));
        flash("Materiaallijst ingelezen: {$r['nieuw']} regels" . ($r['opgehoogd'] ? ", {$r['opgehoogd']} opgeteld bij bestaande" : '')
            . ($r['onbekend'] ? ", {$r['onbekend']} nog niet in de voorraadlijst" : '') . '. ' . fmt_n($r['gereserveerd']) . ' stuks uit voorraad klaargezet.');
    } elseif ($actie === 'klaarzetten') {
        $n = project_reserveer((int)$pr['id']);
        flash($n > 0 ? fmt_n($n) . ' extra klaargezet uit voorraad.' : 'Niets extra beschikbaar om klaar te zetten.');
    } elseif ($actie === 'regel_weg' && is_admin()) {
        $mid = (int)($_POST['materiaal_id'] ?? 0);
        q('UPDATE bestelregels SET materiaal_id = NULL WHERE materiaal_id = ?', [$mid]);
        q('DELETE FROM materiaal WHERE id = ? AND project_id = ?', [$mid, $pr['id']]);
        flash('Regel verwijderd; reservering vrijgegeven.');
    } elseif ($actie === 'nodig' && is_admin()) {
        $aantal = num($_POST['nodig'] ?? null);
        if ($aantal !== null && $aantal >= 0) {
            $mid = (int)($_POST['materiaal_id'] ?? 0);
            q('UPDATE materiaal SET nodig = ?, gereserveerd = LEAST(gereserveerd, ?) WHERE id = ? AND project_id = ?', [$aantal, $aantal, $mid, $pr['id']]);
            materiaal_reserveer($mid);
            flash('Aantal aangepast.');
        }
    }
    redirect($terug);
}

$mat = materiaal_rijen((int)$pr['id']);
$bestellingen = q('SELECT b.*, (SELECT COUNT(*) FROM bestelregels br WHERE br.bestelling_id = b.id) regels FROM bestellingen b WHERE b.project_id = ? ORDER BY b.id DESC', [$pr['id']])->fetchAll();

// Netto per product: gepakt min teruggebracht (ongedaan-boekingen tellen mee)
$items = q("SELECT p.id, p.naam, p.code, p.groep, p.eenheid,
              SUM(-m.aantal) netto, SUM(-m.aantal * COALESCE(m.prijs, 0)) waarde
            FROM mutaties m JOIN producten p ON p.id = m.product_id
            WHERE m.project_id = ? AND m.type IN ('uit','retour','correctie')
            GROUP BY p.id HAVING netto <> 0 ORDER BY p.groep, p.naam", [$pr['id']])->fetchAll();
$hist = q("SELECT m.*, p.naam, g.naam wie FROM mutaties m JOIN producten p ON p.id = m.product_id
           LEFT JOIN gebruikers g ON g.id = m.gebruiker_id WHERE m.project_id = ? ORDER BY m.id DESC", [$pr['id']])->fetchAll();
$totaal = array_sum(array_column($items, 'waarde'));
$aantalFotos = q('SELECT COUNT(*) c FROM fotos WHERE project_id = ?', [$pr['id']])->fetch()['c'];
$gepland = q("SELECT pl.datum, GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(g.naam, ' ', 1) ORDER BY g.volgorde, g.naam SEPARATOR ', ') namen FROM planning pl JOIN gebruikers g ON g.id = pl.gebruiker_id WHERE pl.project_id = ? AND pl.datum >= ? GROUP BY pl.datum ORDER BY pl.datum LIMIT 10", [$pr['id'], vandaag()])->fetchAll();

$exportGeboekt = array_map(fn($i) => ['Project' => $pr['nummer'], 'Product' => $i['naam'], 'Code' => $i['code'], 'Groep' => $i['groep'],
    'Aantal' => (float)$i['netto'], 'Eenheid' => $i['eenheid'], 'Waarde' => round((float)$i['waarde'], 2)], $items);
$exportMateriaal = array_map(fn($r) => ['Project' => $pr['nummer'], 'Omschrijving' => $r['productnaam'] ?: $r['omschrijving'], 'WW-code' => $r['code'] ?? '',
    'EAN' => $r['ean_toon'], 'Rexel-code' => $r['code_toon'], 'Nodig' => (float)$r['nodig'], 'Gepakt' => $r['gepakt'], 'Klaargezet' => (float)$r['gereserveerd'],
    'Onderweg' => (float)$r['besteld_open'], 'Leverdatum' => $r['leverdatum'] ?? '', 'Op project geleverd' => (float)$r['op_project_geleverd'],
    'Nog bestellen' => $r['nog_bestellen'], 'Eenheid' => $r['eenheid'], 'Locatie' => $r['locatie'] ?? ''], $mat);
if (!is_admin()) $exportGeboekt = array_map(function ($r) { unset($r['Waarde']); return $r; }, $exportGeboekt);
$nogBestellen = array_sum(array_map(fn($r) => $r['nog_bestellen'] > 0 ? 1 : 0, $mat));

// ---------- Overzicht: taken, planning, tijdlijn ----------
$v = vandaag();
$nl = function (?string $utc): string { if (!$utc) return ''; $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone('Europe/Amsterdam')); return $d->format('Y-m-d H:i'); };
$takenOpen = q("SELECT t.*, pr.nummer, pr.omschrijving, g.naam eigenaar, v.naam van, v.rol van_rol FROM taken t LEFT JOIN projecten pr ON pr.id = t.project_id
                LEFT JOIN gebruikers g ON g.id = t.eigenaar_id LEFT JOIN gebruikers v ON v.id = t.van_id
                WHERE t.project_id = ? AND t.status <> 'klaar' ORDER BY FIELD(t.prioriteit,'kritiek','hoog','normaal','laag'), COALESCE(t.deadline, t.datum, '2999-12-31'), t.id", [$pr['id']])->fetchAll();
$takenKlaar = q("SELECT t.titel, t.afgerond_op, g.naam eigenaar FROM taken t LEFT JOIN gebruikers g ON g.id = t.eigenaar_id
                 WHERE t.project_id = ? AND t.status = 'klaar' ORDER BY t.afgerond_op DESC LIMIT 30", [$pr['id']])->fetchAll();
$teLaat = count(array_filter($takenOpen, 'taak_te_laat'));
$perPersoon = [];
foreach ($takenOpen as $t) { $k = $t['eigenaar'] ? explode(' ', $t['eigenaar'])[0] : 'Nog te verdelen'; $perPersoon[$k] = ($perPersoon[$k] ?? 0) + 1; }
$planAlle = q("SELECT pl.datum, GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(g.naam, ' ', 1) ORDER BY g.volgorde, g.naam SEPARATOR ', ') namen,
                 GROUP_CONCAT(DISTINCT NULLIF(pl.tekst, '') SEPARATOR ' · ') tekst
               FROM planning pl JOIN gebruikers g ON g.id = pl.gebruiker_id WHERE pl.project_id = ? GROUP BY pl.datum ORDER BY pl.datum", [$pr['id']])->fetchAll();
$planKomt = array_values(array_filter($planAlle, fn($r) => $r['datum'] >= $v));
$planGeweest = array_reverse(array_values(array_filter($planAlle, fn($r) => $r['datum'] < $v)));
$eind = $pr['opleverdatum'] ?: ($planKomt ? end($planKomt)['datum'] : null);
$nog = $eind ? (int)round((strtotime($eind) - strtotime($v)) / 86400) : null;
$kwh = $pr['kwh'] ?: kwh_uit_tekst($pr['systeem'] . ' ' . $pr['omschrijving']);
$stap = fase_stap($pr['fase'], $pr['soort'] ?? 'project');
$mijlpalen = mijlpalen((int)$pr['id']);
$mpOpen = array_values(array_filter($mijlpalen, fn($m) => !$m['gedaan']));
$mpVolgende = null; foreach ($mpOpen as $m) if ($m['datum']) { $mpVolgende = $m; break; }
$leider = $pr['leider_id'] ? (q('SELECT naam FROM gebruikers WHERE id = ?', [$pr['leider_id']])->fetch()['naam'] ?? '') : '';

// Tijdlijn: berichten (mail, WhatsApp, Teams, Pipedrive), notities, meldingen, taken, bestellingen en foto's door elkaar
$feed = [];
foreach (q('SELECT * FROM project_berichten WHERE project_id = ? ORDER BY datum DESC LIMIT 200', [$pr['id']])->fetchAll() as $r)
    $feed[] = ['t' => substr($r['datum'], 0, 16), 'soort' => $r['bron'], 'van' => $r['van'], 'kop' => $r['onderwerp'], 'tekst' => (string)$r['tekst'], 'link' => $r['link']];
foreach (q('SELECT n.*, g.naam FROM notities n JOIN gebruikers g ON g.id = n.gebruiker_id WHERE n.project_id = ? ORDER BY n.id DESC LIMIT 100', [$pr['id']])->fetchAll() as $r)
    $feed[] = ['t' => $nl($r['aangemaakt']), 'soort' => 'notitie', 'van' => $r['naam'], 'kop' => 'Notitie', 'tekst' => $r['tekst'] . ($r['verwerking'] ? "\nClaude: " . $r['verwerking'] : ''), 'link' => ''];
foreach (q('SELECT m.*, g.naam FROM meldingen m JOIN gebruikers g ON g.id = m.gebruiker_id WHERE m.project_id = ? ORDER BY m.id DESC LIMIT 100', [$pr['id']])->fetchAll() as $r)
    $feed[] = ['t' => $nl($r['aangemaakt']), 'soort' => 'melding', 'van' => $r['naam'], 'kop' => $r['soort'] === 'probleem' ? 'Probleem gemeld' : 'Werk klaar gemeld', 'tekst' => $r['tekst'], 'link' => ''];
foreach (q("SELECT l.*, t.titel, t.id tid FROM taak_log l JOIN taken t ON t.id = l.taak_id WHERE t.project_id = ? AND l.tekst NOT LIKE 'Belang:%' AND l.tekst NOT LIKE 'Verzet naar%' AND l.tekst <> 'Taak aangepast' ORDER BY l.id DESC LIMIT 200", [$pr['id']])->fetchAll() as $r)
    $feed[] = ['t' => $nl($r['aangemaakt']), 'soort' => 'taak', 'van' => $r['wie'], 'kop' => $r['titel'], 'tekst' => $r['tekst'], 'link' => 'taak.php?id=' . (int)$r['tid']];
foreach ($bestellingen as $b)
    $feed[] = ['t' => $nl($b['aangemaakt'] ?? null) ?: (($b['besteldatum'] ?? '') . ' 00:00'), 'soort' => 'bestelling', 'van' => (string)$b['besteld_door'], 'kop' => 'Bestelling ' . $b['leverancier'] . ' ' . $b['ordernummer'],
               'tekst' => BESTEL_STATUS[$b['status']] . ($b['leverdatum'] ? ', levering ' . date('d-m', strtotime($b['leverdatum'])) : ''), 'link' => 'inkoop.php?b=' . (int)$b['id']];
foreach (q("SELECT DATE(f.aangemaakt) d, COUNT(*) n, MAX(f.aangemaakt) laatst, GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(g.naam, ' ', 1)) wie FROM fotos f LEFT JOIN gebruikers g ON g.id = f.gebruiker_id WHERE f.project_id = ? GROUP BY DATE(f.aangemaakt)", [$pr['id']])->fetchAll() as $r)
    $feed[] = ['t' => $nl($r['laatst']), 'soort' => 'foto', 'van' => (string)$r['wie'], 'kop' => $r['n'] . " foto's", 'tekst' => '', 'link' => 'fotos.php?nr=' . rawurlencode($pr['nummer'])];
usort($feed, fn($a, $b) => strcmp($b['t'], $a['t']));
const FEED_SOORT = ['mail' => 'Mail', 'whatsapp' => 'WhatsApp', 'teams' => 'Teams', 'pipedrive' => 'Pipedrive', 'telefoon' => 'Telefoon', 'claude' => 'Claude', 'app' => 'App',
    'notitie' => 'Notitie', 'melding' => 'Melding', 'taak' => 'Taak', 'bestelling' => 'Inkoop', 'foto' => "Foto's"];

// Locatie, installaties en werkbonnen (ronde 2)
if (!$pr['locatie_id']) { project_koppel_locatie((int)$pr['id']); $pr = q('SELECT * FROM projecten WHERE id = ?', [$pr['id']])->fetch(); }
$locatie = $pr['locatie_id'] ? q('SELECT * FROM locaties WHERE id = ?', [$pr['locatie_id']])->fetch() : null;
$installaties = installaties_van_project($pr);
$ticketInstallatie = $pr['installatie_id'] ? q('SELECT * FROM installaties WHERE id = ?', [$pr['installatie_id']])->fetch() : null;
$werkbonnen = q('SELECT w.id, w.datum, w.status, w.monteurs, w.klant_naam, w.concept_door FROM werkbonnen w WHERE w.project_id = ? ORDER BY w.datum DESC, w.id DESC', [$pr['id']])->fetchAll();
$magWerkbon = !is_verkoop();
if (is_admin()) {
    $begroting = begroting_regels((int)$pr['id']);
    $begrotingTotaal = begroting_totaal((int)$pr['id']);
    $termijnen = termijnen_van((int)$pr['id']);
    $facturen = q('SELECT f.*, (SELECT COALESCE(SUM(aantal * prijs), 0) FROM factuurregels r WHERE r.factuur_id = f.id) totaal FROM facturen f WHERE f.project_id = ? ORDER BY f.id DESC', [$pr['id']])->fetchAll();
    $gefactureerd = array_sum(array_map(fn($f) => $f['status'] !== 'vervallen' ? (float)$f['totaal'] : 0, $facturen));
    $nc = nacalculatie((int)$pr['id']);
}

page_head(($service ? 'Ticket ' : 'Project ') . $pr['nummer'], $service ? 'service' : 'projecten');
?>
<section class="card pkop">
  <div class="row between wrap">
    <div>
      <div class="small muted"><?= $service ? '<a href="service.php">Service</a>' : '<a href="projecten.php">Projecten</a>' ?> · <?= e($pr['nummer']) ?><?= $pr['afgerond'] ? ' · <b>afgerond</b>' : '' ?></div>
      <h1><?= e(project_naam($pr)) ?></h1>
      <div class="muted"><?= e($pr['systeem'] ?: $pr['omschrijving']) ?><?= $pr['klant'] !== '' ? ' · ' . e($pr['klant']) : '' ?></div>
    </div>
    <div class="row wrap">
      <a class="btn" href="fotos.php?nr=<?= e(rawurlencode($pr['nummer'])) ?>">Foto's (<?= (int)$aantalFotos ?>)</a>
      <?php if (!$service): ?><a class="btn" href="oplevering.php?nr=<?= e(rawurlencode($pr['nummer'])) ?>">Oplevering</a><?php endif; ?>
      <form method="post" action="index.php"><?= csrf_field() ?><button class="btn" name="project" value="<?= e($pr['nummer']) ?>" title="Scannen boekt dan op dit project">Maak actief</button></form>
    </div>
  </div>
  <form method="post" class="fasestappen" aria-label="Fase">
    <?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="fase">
    <?php $i = 0; foreach ($FASES as $k => $f): ?>
      <button <?= is_admin() ? '' : 'disabled' ?> name="fase" value="<?= $k ?>" class="stap<?= $i < $stap ? ' gedaan' : ($i === $stap ? ' nu' : '') ?>" title="<?= is_admin() ? 'Zet fase op ' . e($f[0]) : e($f[0]) ?>"><?= e($f[0]) ?></button>
    <?php $i++; endforeach; ?>
  </form>
  <div class="pcijfers">
    <?php if (is_admin()): ?><div><b class="<?= $teLaat ? 'rood' : '' ?>"><?= count($takenOpen) ?></b><span>open taken<?= $teLaat ? ", $teLaat te laat" : '' ?></span></div><?php endif; ?>
    <div><b><?= $eind ? e(datum_kort($eind)) : '–' ?></b><span><?= $eind ? ($pr['opleverdatum'] ? 'opleverdatum' : 'laatste ploegdag') . ($nog !== null ? ' · <span class="' . ($nog < 0 ? 'rood' : ($nog <= 14 ? 'oranje' : '')) . '">' . ($nog < 0 ? abs($nog) . ' d te laat' : 'over ' . $nog . ' d') . '</span>' : '') : 'geen einddatum' ?></span></div>
    <div><b><?= $planKomt ? e(datum_kort($planKomt[0]['datum'])) : '–' ?></b><span><?= $planKomt ? 'volgende ploegdag · ' . e($planKomt[0]['namen']) : 'niet ingepland' ?></span></div>
    <div><b class="<?= $mpVolgende && $mpVolgende['datum'] < $v ? 'rood' : '' ?>"><?= $mpVolgende ? e(datum_kort($mpVolgende['datum'])) : '–' ?></b><span><?= $mpVolgende ? 'mijlpaal · ' . e($mpVolgende['titel']) . ($mpVolgende['zeker'] ? '' : ' (verwacht)') : 'geen mijlpaal' ?></span></div>
    <div><b><?= $kwh ? ($kwh >= 1000 ? str_replace('.', ',', (string)round($kwh / 1000, 1)) . ' MWh' : $kwh . ' kWh') : '–' ?></b><span>grootte</span></div>
    <div><b class="<?= $nogBestellen ? 'oranje' : '' ?>"><?= $mat ? ($nogBestellen ? $nogBestellen : '✓') : '–' ?></b><span><?= $mat ? ($nogBestellen ? 'materiaalregels nog bestellen' : 'materiaal geregeld') : 'geen materiaallijst' ?></span></div>
  </div>
</section>

<div class="playout">
<aside class="pzij">
  <section class="card">
    <div class="row between"><h2>Details</h2><?php if (is_admin()): ?><button type="button" class="btn small ghost" id="bewerkKnop">Bewerken</button><?php endif; ?></div>
    <dl class="pdetails">
      <dt>Fase</dt><dd><?= e($FASES[$pr['fase']][0] ?? '') ?></dd>
      <?php if ($service): ?>
      <?php if ($pr['urgentie']): ?><dt>Urgentie</dt><dd class="<?= in_array($pr['urgentie'], ['acuut', 'spoed'], true) ? 'rood' : '' ?>"><?= e(URGENTIES[$pr['urgentie']] ?? $pr['urgentie']) ?></dd><?php endif; ?>
      <dt>Apparaat</dt><dd><?= e(APPARATEN[$pr['apparaat']] ?? '–') ?></dd>
      <dt>Afhandeling</dt><dd><?= e(AFHANDELINGEN[$pr['afhandeling']] ?? '–') ?></dd>
      <?php if ($pr['foutcode'] !== ''): ?><dt>Foutcode</dt><dd><?= e($pr['foutcode']) ?></dd><?php endif; ?>
      <dt>Telefoon</dt><dd><?= $pr['telefoon'] !== '' ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $pr['telefoon'])) . '">' . e($pr['telefoon']) . '</a>' : '–' ?></dd>
      <?php if ($pr['email'] !== ''): ?><dt>E-mail</dt><dd><a href="mailto:<?= e($pr['email']) ?>"><?= e($pr['email']) ?></a></dd><?php endif; ?>
      <dt>Gemeld</dt><dd><?= $pr['startdatum'] ? e(datum_lang($pr['startdatum'])) : '–' ?></dd>
      <dt>Streefdatum</dt><dd><?= $pr['einddatum'] ? e(datum_lang($pr['einddatum'])) : '–' ?></dd>
      <?php endif; ?>
      <?php if ($pr['labels'] ?? ''): ?><dt>Labels</dt><dd><?= e($pr['labels']) ?></dd><?php endif; ?>
      <dt>Systeem</dt><dd><?= e($pr['systeem'] ?: '–') ?></dd>
      <dt>Klant</dt><dd><?= ($pr['klant_id'] ?? null) && (is_admin() || is_verkoop()) ? '<a href="klant.php?id=' . (int)$pr['klant_id'] . '">' . e($pr['klant'] ?: 'klantkaart') . '</a>' : e($pr['klant'] ?: '–') ?></dd>
      <dt>Adres</dt><dd><?= $pr['adres'] !== '' ? e($pr['adres']) . '<br><a href="' . e(route_url($pr['adres'])) . '" target="_blank" rel="noopener">Route</a>' : '<span class="warn-ink">ontbreekt</span>' ?></dd>
      <?php if ($locatie && ($locatie['naam'] !== '' || (int)q('SELECT COUNT(*) FROM projecten WHERE locatie_id = ?', [$locatie['id']])->fetchColumn() > 1)): ?><dt>Locatie</dt><dd><?= e(locatie_tekst($locatie)) ?></dd><?php endif; ?>
      <?php if ($service): ?><dt>Installatie</dt><dd><?= $ticketInstallatie ? '<a href="installatie.php?id=' . (int)$ticketInstallatie['id'] . '">' . e(installatie_naam($ticketInstallatie)) . '</a>' : '<a href="#installatie" data-tab-link="installatie">nog niet gekoppeld</a>' ?></dd><?php endif; ?>
      <dt>Contact</dt><dd><?= e($pr['contact'] ?: '–') ?></dd>
      <dt>Projectleider</dt><dd><?= e($leider ?: 'Ruben en Berend') ?></dd>
      <?php $urenTot = (float)(q('SELECT SUM(uren) s FROM uren WHERE project_id = ?', [$pr['id']])->fetch()['s'] ?? 0); if ($urenTot && is_admin()): ?><dt>Uren</dt><dd><?= str_replace('.', ',', (string)$urenTot) ?> uur geschreven</dd><?php endif; ?>
      <?php if (!$service): ?><dt>Opleverdatum</dt><dd><?= $pr['opleverdatum'] ? e(datum_lang($pr['opleverdatum'])) : '–' ?></dd><?php endif; ?>
      <?php if ($pr['pipedrive_id']): ?><dt>Pipedrive</dt><dd><a href="https://wattwatt.pipedrive.com/projects/<?= (int)$pr['pipedrive_id'] ?>" target="_blank" rel="noopener">Project openen</a></dd><?php endif; ?>
      <?php if ($pr['pipedrive_deal_id'] ?? null): ?><dt>Deal</dt><dd><a href="https://wattwatt.pipedrive.com/deal/<?= (int)$pr['pipedrive_deal_id'] ?>" target="_blank" rel="noopener">Deal in Pipedrive</a></dd><?php endif; ?>
    </dl>
    <?php if (($pr['beschrijving'] ?? '') !== ''): ?><details class="small"><summary>Beschrijving</summary><p style="white-space:pre-line"><?= e($pr['beschrijving']) ?></p></details><?php endif; ?>
    <?php if (is_admin()): ?>
    <form method="post" class="stack" id="bewerkForm" hidden>
      <?= csrf_field() ?>
      <input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>">
      <input type="hidden" name="actie" value="opslaan">
      <label>Naam van het project <input name="omschrijving" value="<?= e($pr['omschrijving']) ?>" placeholder="bijv. Boerderij De Schooten"></label>
      <label>Klant <input name="klant" value="<?= e($pr['klant']) ?>"></label>
      <label>Adres (voor de route van de monteur) <input name="adres" value="<?= e($pr['adres']) ?>" placeholder="Straat 1, 1234 AB Plaats"></label>
      <label>Contact op locatie <input name="contact" value="<?= e($pr['contact']) ?>" placeholder="naam en hoe te bereiken"></label>
      <label>Fase
        <select name="fase"><?php foreach ($FASES as $k => $f): ?><option value="<?= $k ?>" <?= $pr['fase'] === $k ? 'selected' : '' ?>><?= e($f[0]) ?></option><?php endforeach; ?></select></label>
      <?php if ($service): ?>
      <label>Telefoon <input name="telefoon" value="<?= e($pr['telefoon']) ?>"></label>
      <label>E-mail <input type="email" name="email" value="<?= e($pr['email']) ?>"></label>
      <label>Apparaat <select name="apparaat"><option value="">–</option><?php foreach (APPARATEN as $k => $n): ?><option value="<?= $k ?>" <?= $pr['apparaat'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label>Urgentie <select name="urgentie"><option value="">–</option><?php foreach (URGENTIES as $k => $n): ?><option value="<?= $k ?>" <?= $pr['urgentie'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label>Afhandeling <select name="afhandeling"><option value="">–</option><?php foreach (AFHANDELINGEN as $k => $n): ?><option value="<?= $k ?>" <?= $pr['afhandeling'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label>Foutcode <input name="foutcode" value="<?= e($pr['foutcode']) ?>"></label>
      <label>Streefdatum <input type="date" name="einddatum" value="<?= e($pr['einddatum'] ?? '') ?>"></label>
      <label>Beschrijving <textarea name="beschrijving" rows="5"><?= e($pr['beschrijving'] ?? '') ?></textarea></label>
      <?php endif; ?>
      <label<?= $service ? ' hidden' : '' ?>>Opleverdatum (contract of afspraak) <input type="date" name="opleverdatum" value="<?= e($pr['opleverdatum'] ?? '') ?>"></label>
      <label>Systeem <input name="systeem" value="<?= e($pr['systeem']) ?>" placeholder="bijv. 4x Hoymiles 125 kW / 261 kWh + STS"></label>
      <label>Grootte in kWh <input name="kwh" inputmode="numeric" value="<?= e((string)($pr['kwh'] ?? '')) ?>" placeholder="bijv. 1044"></label>
      <label>Projectleider (krijgt het seintje als een monteur iets meldt)
        <select name="leider_id"><option value="">Ruben en Berend</option><?php foreach (mensen_kantoor() as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$pr['leider_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['naam']) ?></option><?php endforeach; ?></select></label>
      <label class="check"><input type="checkbox" name="afgerond" <?= $pr['afgerond'] ? 'checked' : '' ?>> Afgerond</label>
      <button class="btn primary">Opslaan</button>
    </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Planning</h2>
    <?php if (!$planKomt): ?><p class="muted small">Niet ingepland.</p><?php endif; ?>
    <ul class="plijst">
      <?php foreach (array_slice($planKomt, 0, 12) as $g): $o = dagopdracht($g['datum'], (int)$pr['id']); $st = dagopdracht_stand($o); ?>
        <li><b><?= e(datum_kort($g['datum'])) ?></b> <?= e($g['namen']) ?><?= $g['tekst'] ? '<br><span class="small muted">' . e($g['tekst']) . '</span>' : '' ?>
          <?php if (is_admin()): ?><br><a class="small <?= $st === 'vrij' ? 'ok-ink' : 'warn-ink' ?>" href="opdracht.php?datum=<?= e($g['datum']) ?>&amp;nr=<?= e(rawurlencode($pr['nummer'])) ?>">Instructie: <?= ['vrij' => 'vrijgegeven', 'controle' => 'wacht op akkoord', 'concept' => 'concept', 'leeg' => 'nog leeg'][$st] ?></a><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($planGeweest): ?><details class="small"><summary>Geweest (<?= count($planGeweest) ?> dagen)</summary><ul class="plijst">
      <?php foreach ($planGeweest as $g): ?><li><?= e(datum_kort($g['datum'])) ?> · <?= e($g['namen']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  </section>
</aside>

<div class="phoofd">
  <nav class="ptabs" role="tablist">
    <a href="#overzicht" data-tab="overzicht">Overzicht</a>
    <?php if (is_admin()): ?>
    <a href="#taken" data-tab="taken">Taken <span class="n"><?= count($takenOpen) ?></span></a>
    <a href="#tijdlijn" data-tab="tijdlijn">Tijdlijn <span class="n"><?= count($feed) ?></span></a>
    <?php endif; ?>
    <a href="#materiaal" data-tab="materiaal">Materiaal<?= $nogBestellen ? ' <span class="n oranje">' . $nogBestellen . '</span>' : '' ?></a>
    <a href="#boekingen" data-tab="boekingen">Boekingen</a>
    <?php if (!is_onderaannemer()): ?><a href="#installatie" data-tab="installatie">Installatie <span class="n"><?= count($installaties) ?></span></a><?php endif; ?>
    <a href="#werkbonnen" data-tab="werkbonnen">Werkbonnen <span class="n"><?= count($werkbonnen) ?></span></a>
    <?php if (is_admin()): ?><a href="#financieel" data-tab="financieel">Financieel</a><?php endif; ?>
  </nav>

  <div class="ptab" data-tab="overzicht" id="overzicht">
    <?php if (!is_onderaannemer()): $vragen = q("SELECT n.*, g.naam FROM notities n JOIN gebruikers g ON g.id = n.gebruiker_id WHERE n.project_id = ? AND n.soort = 'vraag' ORDER BY n.id DESC LIMIT 5", [$pr['id']])->fetchAll(); ?>
    <section class="card" id="vraag">
      <div class="row between"><h2>Vraag Claude</h2><span class="small muted">Antwoord binnen een uur op werkdagen</span></div>
      <form method="post" class="row wrap mp-form"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="vraag">
        <input name="tekst" class="grow" required placeholder="Bijv. vat de laatste mail van de klant samen, of wat moet er nog voor de oplevering" aria-label="Vraag aan Claude">
        <button class="btn small primary">Vraag</button></form>
      <?php if ($vragen): ?><ul class="feed"><?php foreach ($vragen as $vq): ?>
        <li><span class="small muted"><?= e(fmt_dt($vq['aangemaakt'])) ?> · <?= e(explode(' ', $vq['naam'])[0]) ?></span><br><b><?= e($vq['tekst']) ?></b>
          <br><?= $vq['status'] === 'verwerkt' ? nl2br(e((string)$vq['verwerking'])) : '<span class="small muted">Wacht op antwoord van Claude</span>' ?></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if (!is_admin()): ?>
    <section class="card"><h2>Wat er gepland staat</h2>
      <?php if (!$planKomt): ?><p class="muted">Niet ingepland.</p><?php endif; ?>
      <ul class="plijst"><?php foreach ($planKomt as $g): ?><li><b><?= e(datum_kort($g['datum'])) ?></b> <?= e($g['namen']) ?><?= $g['tekst'] ? '<br><span class="small muted">' . e($g['tekst']) . '</span>' : '' ?></li><?php endforeach; ?></ul>
    </section>
    <?php if ($mpOpen): ?><section class="card"><h2>Mijlpalen</h2>
      <ul class="plijst"><?php foreach ($mpOpen as $m): ?><li><b><?= $m['datum'] ? e(datum_kort($m['datum'])) : 'geen datum' ?></b> <?= e($m['titel']) ?><?= $m['zeker'] ? '' : ' <span class="muted">(verwacht)</span>' ?></li><?php endforeach; ?></ul>
    </section><?php endif; ?>
    <?php else: ?>
    <section class="card" id="mijlpalen">
      <div class="row between"><h2>Mijlpalen</h2><span class="small muted">Vaste momenten: levering, keuring, oplevering. Claude vult ze ook aan uit mail en inkoop.</span></div>
      <?php if (!$mijlpalen): ?><p class="muted">Nog geen mijlpalen.</p><?php endif; ?>
      <ul class="mijlpalen">
      <?php foreach ($mijlpalen as $m): $laat = !$m['gedaan'] && $m['datum'] && $m['datum'] < $v; $dagen = $m['datum'] ? (int)round((strtotime($m['datum']) - strtotime($v)) / 86400) : null; ?>
        <li class="<?= $m['gedaan'] ? 'gedaan' : ($laat ? 'laat' : '') ?>">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="mijlpaal_gedaan"><input type="hidden" name="mijlpaal_id" value="<?= (int)$m['id'] ?>">
            <button class="mp-vink" title="<?= $m['gedaan'] ? 'Toch nog niet gebeurd' : 'Afvinken: is gebeurd' ?>"><?= $m['gedaan'] ? '✓' : '' ?></button></form>
          <span class="mp-datum"><?= $m['datum'] ? e(datum_kort($m['datum'])) : 'geen datum' ?><?php if (!$m['gedaan'] && $dagen !== null): ?><br><span class="small <?= $laat ? 'rood' : ($dagen <= 14 ? 'oranje' : 'muted') ?>"><?= $laat ? abs($dagen) . ' d geleden' : ($dagen === 0 ? 'vandaag' : 'over ' . $dagen . ' d') ?></span><?php endif; ?></span>
          <span class="mp-wat"><b><?= e($m['titel']) ?></b> <span class="tag"><?= e(MIJLPAAL_SOORT[$m['soort']] ?? 'Overig') ?></span><?php if (!$m['zeker']): ?> <span class="tag st-wacht">verwacht</span><?php endif; ?><?php if (MIJLPAAL_BRON[$m['bron']] ?? ''): ?> <span class="tag mp-auto">Claude, <?= e(MIJLPAAL_BRON[$m['bron']]) ?></span><?php endif; ?>
            <?php if ($m['toelichting'] !== ''): ?><br><span class="small muted"><?= e($m['toelichting']) ?></span><?php endif; ?>
            <details class="small"><summary>Aanpassen</summary>
              <form method="post" class="row wrap mp-form"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="mijlpaal_id" value="<?= (int)$m['id'] ?>">
                <input type="date" name="datum" value="<?= e($m['datum'] ?? '') ?>"><input name="titel" value="<?= e($m['titel']) ?>" required class="grow">
                <label class="check"><input type="checkbox" name="verwacht" <?= $m['zeker'] ? '' : 'checked' ?>> nog niet zeker</label>
                <button class="btn small" name="actie" value="mijlpaal_wijzig">Opslaan</button><button class="btn small ghost" name="actie" value="mijlpaal_weg" onclick="return confirm('Mijlpaal verwijderen?')">Verwijderen</button></form>
            </details></span>
        </li>
      <?php endforeach; ?>
      </ul>
      <form method="post" class="row wrap mp-form mp-nieuw"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="mijlpaal">
        <input type="date" name="datum" aria-label="Datum">
        <input name="titel" class="grow" required placeholder="Mijlpaal, bijv. batterij komt binnen" aria-label="Mijlpaal">
        <select name="soort" aria-label="Soort"><?php foreach (MIJLPAAL_SOORT as $k => $n): ?><option value="<?= $k ?>"><?= e($n) ?></option><?php endforeach; ?></select>
        <label class="check"><input type="checkbox" name="verwacht"> nog niet zeker</label>
        <button class="btn primary small">Toevoegen</button>
      </form>
    </section>
    <section class="card">
      <div class="row between"><h2>Stand van zaken</h2><?php if ($pr['samenvatting_op']): ?><span class="small muted">Claude, <?= e(fmt_dt($pr['samenvatting_op'])) ?></span><?php endif; ?></div>
      <?php if ($pr['samenvatting']): ?><div class="psamenvatting"><?php foreach (preg_split('/\R/', (string)$pr['samenvatting']) as $regel): $regel = trim($regel); if ($regel === '') continue; ?>
        <?php if (preg_match('/^[A-Z][A-Z ]{2,}$/', $regel)): ?><h3><?= e(ucfirst(mb_strtolower($regel))) ?></h3><?php elseif (str_starts_with($regel, '- ')): ?><div class="ps-punt"><?= e(substr($regel, 2)) ?></div><?php else: ?><p><?= e($regel) ?></p><?php endif; ?>
      <?php endforeach; ?></div>
      <?php else: ?><p class="muted">Nog geen samenvatting. Claude zet die bij de volgende ronde klaar uit mail, WhatsApp, Teams, Pipedrive en de app.</p><?php endif; ?>
    </section>
    <section class="card">
      <div class="row between"><h2>Open taken</h2><a class="small" href="#taken" data-tab-link="taken">Alle taken</a></div>
      <?php if ($perPersoon): ?><p><?php foreach ($perPersoon as $k => $n): ?><span class="tag"><?= e($k) ?>: <?= (int)$n ?></span> <?php endforeach; ?></p><?php endif; ?>
      <?php if (!$takenOpen): ?><p class="muted">Geen open taken.</p><?php endif; ?>
      <ul class="ptaken"><?php foreach (array_slice($takenOpen, 0, 6) as $t): ?>
        <li><a href="taak.php?id=<?= (int)$t['id'] ?>"><?= e($t['titel']) ?></a>
          <span class="small muted"><?= e($t['eigenaar'] ? explode(' ', $t['eigenaar'])[0] : 'Nog te verdelen') ?><?= $t['deadline'] ? ' · uiterlijk ' . e(datum_kort($t['deadline'])) : '' ?></span>
          <?php if (taak_te_laat($t)): ?><span class="tag t-laat">Te laat</span><?php endif; ?>
          <?php if (in_array($t['prioriteit'], ['kritiek', 'hoog'], true)): ?><span class="tag p-<?= e($t['prioriteit']) ?>"><?= e(TAAK_PRIO[$t['prioriteit']]) ?></span><?php endif; ?>
          <?php if ($t['status'] === 'wacht'): ?><span class="tag st-wacht">Wacht op <?= e($t['wacht_op']) ?></span><?php endif; ?></li>
      <?php endforeach; ?></ul>
    </section>
    <section class="card">
      <div class="row between"><h2>Laatste berichten</h2><a class="small" href="#tijdlijn" data-tab-link="tijdlijn">Hele tijdlijn</a></div>
      <?php if (!$feed): ?><p class="muted">Nog niets vastgelegd.</p><?php endif; ?>
      <form method="post" class="row wrap mp-form">
        <?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="notitie"><input type="hidden" name="terug_tab" value="overzicht">
        <input name="tekst" class="grow" required placeholder="Notitie, bijv. klant belde: graafwerk schuift naar 3-12" aria-label="Notitie">
        <button class="btn small">Notitie opslaan</button>
      </form>
      <ul class="feed"><?php foreach (array_slice(array_values(array_filter($feed, fn($f) => $f['soort'] !== 'taak')), 0, 6) as $f): include __DIR__ . '/inc/feed_regel.php'; endforeach; ?></ul>
    </section>
    <?php endif; ?>
  </div>

  <?php if (is_admin()): ?>
  <div class="ptab" data-tab="taken" id="taken">
    <section class="card">
      <h2>Open taken (<?= count($takenOpen) ?>)</h2>
      <?php if (!$takenOpen): ?><p class="muted">Geen open taken.</p><?php else: ?><ul class="taken"><?php foreach ($takenOpen as $t) taak_rij($t, $terug . '#taken', true); ?></ul><?php endif; ?>
      <?php if (is_admin()): ?>
      <details class="mt"><summary><b>Taak toevoegen</b></summary>
        <form method="post" action="taak.php" class="stack mt">
          <?= csrf_field() ?><input type="hidden" name="actie" value="nieuw"><input type="hidden" name="terug" value="<?= e($terug) ?>#taken"><input type="hidden" name="project" value="<?= e($pr['nummer']) ?>">
          <label>Wat moet er gebeuren <input name="titel" required value="<?= e(project_naam($pr)) ?>: "></label>
          <div class="row wrap">
            <label class="grow">Voor wie <select name="eigenaar_id"><option value="">Nog te verdelen</option><?php foreach (mensen_kantoor() as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['naam']) ?></option><?php endforeach; ?></select></label>
            <label class="grow">Uiterlijk <input type="date" name="deadline"></label>
            <label class="grow">Belang <select name="prioriteit"><?php foreach (TAAK_PRIO as $k => $pv): ?><option value="<?= $k ?>" <?= $k === 'normaal' ? 'selected' : '' ?>><?= e($pv) ?></option><?php endforeach; ?></select></label>
          </div>
          <button class="btn primary">Toevoegen</button>
        </form>
      </details>
      <?php endif; ?>
    </section>
    <?php if ($takenKlaar): ?>
    <section class="card"><details><summary><b>Afgerond (<?= count($takenKlaar) ?>)</b></summary>
      <ul class="logboek mt"><?php foreach ($takenKlaar as $t): ?><li><span class="muted small"><?= e(fmt_dt($t['afgerond_op'])) ?> · <?= e($t['eigenaar'] ? explode(' ', $t['eigenaar'])[0] : '') ?></span><br><?= e($t['titel']) ?></li><?php endforeach; ?></ul>
    </details></section>
    <?php endif; ?>
  </div>

  <div class="ptab" data-tab="tijdlijn" id="tijdlijn">
    <section class="card">
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="notitie">
        <label>Notitie bij dit project <textarea name="tekst" rows="2" placeholder="bijv. klant belde: graafwerk schuift naar 3-12" required></textarea></label>
        <div><button class="btn primary">Notitie opslaan</button> <span class="small muted">Iedereen op kantoor ziet hem; Claude verwerkt hem bij de volgende ronde.</span></div>
      </form>
    </section>
    <section class="card">
      <div class="feedfilter">
        <?php foreach (['alles' => 'Alles', 'bericht' => 'Mail en berichten', 'notitie' => 'Notities', 'taak' => 'Taken', 'overig' => 'Inkoop, meldingen en foto\'s'] as $k => $l): ?>
          <button type="button" class="btn small<?= $k === 'alles' ? ' on' : '' ?>" data-feed="<?= $k ?>"><?= e($l) ?></button>
        <?php endforeach; ?>
      </div>
      <?php if (!$feed): ?><p class="muted">Nog niets vastgelegd.</p><?php endif; ?>
      <ul class="feed"><?php foreach ($feed as $f): include __DIR__ . '/inc/feed_regel.php'; endforeach; ?></ul>
    </section>
  </div>
  <?php endif; ?>

  <div class="ptab" data-tab="materiaal">
<section class="card" id="materiaal">
  <div class="row between wrap">
    <h2>Materiaallijst<?= $mat ? ' · ' . count($mat) . ' regels' . ($nogBestellen ? " · <span class=\"warn-ink\">$nogBestellen nog bestellen</span>" : ' · alles geregeld') : '' ?></h2>
    <div class="row wrap">
      <?php if ($mat): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><button class="btn small" name="actie" value="klaarzetten" title="Zet wat nu beschikbaar is klaar voor dit project">↻ Klaarzetten</button></form>
        <button class="btn small" type="button" data-export="expMateriaal" data-naam="materiaal-<?= e($pr['nummer']) ?>">⬇ Excel</button>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$mat): ?>
    <p class="muted">Nog geen materiaallijst. <?= is_admin() ? 'Importeer een Excel hieronder, of stuur de lijst (ook PDF) naar Claude.' : 'Kantoor kan een lijst importeren.' ?></p>
  <?php else: ?>
  <p class="small muted matuitleg"><b>Klaargezet</b> = ligt in het magazijn en is gereserveerd voor dit project. <b>Onderweg</b> = besteld, nog niet geleverd. <b>Al binnen</b> = al op het project of geregeld (groen in de werkvoorbereiding), houdt geen voorraad vast. Scan bij het pakken met dit project actief, dan loopt het vanzelf bij.</p>
  <ul class="matkaarten">
    <?php foreach ($mat as $r):
        $rest = max(0, (float)$r['nodig'] - (float)$r['gepakt']); ?>
      <li class="<?= $r['nog_bestellen'] > 0 ? 'open' : '' ?>">
        <div class="matkop">
          <div class="grow"><b><?= e($r['product_id'] ? $r['productnaam'] : $r['omschrijving']) ?></b>
            <?php if (!empty($r['locatie'])): ?><div class="small">Ligt op: <b><?= e($r['locatie']) ?></b></div><?php endif; ?></div>
          <div class="matnodig"><b><?= fmt_n($r['nodig']) ?></b><span><?= e($r['eenheid'] !== 'stuks' ? $r['eenheid'] : 'nodig') ?></span></div>
        </div>
        <div class="taak-tags">
          <?php if ($r['gepakt'] > 0): ?><span class="tag st-klaar"><?= fmt_n($r['gepakt']) ?> gepakt</span><?php endif; ?>
          <?php if ($r['gereserveerd'] > 0): ?><span class="tag st-bezig"><?= fmt_n($r['gereserveerd']) ?> ligt klaar</span><?php endif; ?>
          <?php if ($r['op_project_geleverd'] > 0): ?><span class="tag st-klaar"><?= fmt_n($r['op_project_geleverd']) ?> al op het project</span><?php endif; ?>
          <?php if ($r['besteld_open'] > 0): ?><span class="tag st-wacht"><?= fmt_n($r['besteld_open']) ?> onderweg<?= $r['leverdatum'] ? ', ' . e(date('d-m', strtotime($r['leverdatum']))) : '' ?></span><?php endif; ?>
          <?php if ($r['nog_bestellen'] > 0): ?><span class="tag t-laat"><?= fmt_n($r['nog_bestellen']) ?> nog bestellen</span><?php endif; ?>
          <?php if ($r['nog_bestellen'] <= 0 && $r['besteld_open'] <= 0 && $rest <= 0): ?><span class="tag st-klaar">Compleet</span><?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="tablewrap mattabel"><table class="mattable">
    <thead><tr><th>Materiaal</th><th>EAN / Rexel</th><th class="r">Nodig</th><th class="r">Gepakt</th><th class="r">Klaargezet</th><th class="r">Onderweg</th><th class="r">Bestellen</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($mat as $r):
        $klaar = $r['nog_bestellen'] <= 0 && $r['gepakt'] >= (float)$r['nodig']; ?>
      <tr class="<?= $r['nog_bestellen'] > 0 ? 'open' : ($klaar ? 'muted' : '') ?>">
        <td><?= $r['product_id'] ? '<a href="product.php?id=' . (int)$r['product_id'] . '">' . e($r['productnaam']) . '</a>' : e($r['omschrijving']) . ' <span class="tag">nieuw</span>' ?>
          <br><span class="small muted"><?= e($r['code'] ?? '') ?><?= !empty($r['locatie']) ? ' · ' . e($r['locatie']) : '' ?></span></td>
        <td class="small"><?= e($r['ean_toon']) ?><?= $r['code_toon'] ? '<br>Rexel ' . e($r['code_toon']) : '' ?></td>
        <td class="r">
          <?php if (is_admin()): ?>
            <form method="post" class="inline-num"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="nodig"><input type="hidden" name="materiaal_id" value="<?= (int)$r['id'] ?>">
              <input name="nodig" value="<?= e(fmt_n($r['nodig'])) ?>" inputmode="decimal" onchange="this.form.submit()"></form>
          <?php else: ?><?= fmt_n($r['nodig']) ?><?php endif; ?>
          <span class="small muted"><?= $r['eenheid'] !== 'stuks' ? e($r['eenheid']) : '' ?></span></td>
        <td class="r"><?= fmt_n($r['gepakt']) ?></td>
        <td class="r"><?= fmt_n($r['gereserveerd']) ?></td>
        <td class="r"><?= $r['besteld_open'] > 0 ? fmt_n($r['besteld_open']) . ($r['leverdatum'] ? '<br><span class="small muted">' . e(date('d-m', strtotime($r['leverdatum']))) . '</span>' : '') : ($r['op_project_geleverd'] > 0 ? '<span class="small">' . fmt_n($r['op_project_geleverd']) . ' al binnen</span>' : '–') ?></td>
        <td class="r"><?= $r['nog_bestellen'] > 0 ? '<b class="warn-ink">' . fmt_n($r['nog_bestellen']) . '</b>' : '✓' ?></td>
        <?php if (is_admin()): ?><td><form method="post" onsubmit="return confirm('Regel verwijderen?')"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="materiaal_id" value="<?= (int)$r['id'] ?>"><button class="linkbtn small muted" name="actie" value="regel_weg">✕</button></form></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php if (is_admin()): ?>
  <details class="mt" <?= $mat ? '' : 'open' ?>>
    <summary><b>Materiaallijst importeren (Excel)</b></summary>
    <form method="post" id="matImport" class="stack mt">
      <?= csrf_field() ?>
      <input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>">
      <input type="hidden" name="actie" value="materiaal_import">
      <input type="hidden" name="regels" id="matRegels">
      <input type="hidden" name="bestand" id="matBestandNaam">
      <p class="small muted">Kolommen worden herkend aan de kopjes: omschrijving, aantal, en waar mogelijk EAN en artikelnummer (Rexel). Wat op voorraad ligt wordt direct klaargezet; de rest komt op de bestellijst. Een PDF-lijst? Stuur hem naar Claude met het projectnummer.</p>
      <input type="file" id="matBestand" accept=".xlsx,.xls,.csv">
      <label id="matSheetKeuze" hidden>Tabblad <select id="matSheet"></select></label>
      <div id="matInfo" class="small muted"></div>
      <label class="check"><input type="checkbox" name="vervang"> Bestaande materiaallijst vervangen (anders wordt het opgeteld)</label>
      <button class="btn primary" id="matKnop" disabled>Materiaallijst inlezen</button>
    </form>
  </details>
  <?php endif; ?>
</section>

<?php if ($bestellingen): ?>
<section class="card">
  <h2>Bestellingen</h2>
  <div class="tablewrap"><table>
    <thead><tr><th>Leverancier</th><th>Order</th><th>Status</th><th>Levering</th><th>Factuur</th></tr></thead>
    <tbody>
      <?php foreach ($bestellingen as $b): ?>
      <tr><td><?= e($b['leverancier']) ?></td><td><a href="inkoop.php?b=<?= (int)$b['id'] ?>"><?= e($b['ordernummer']) ?></a><br><span class="small muted"><?= (int)$b['regels'] ?> regels<?= $b['besteld_door'] ? ' · ' . e($b['besteld_door']) : '' ?></span></td>
        <td><span class="tag s-<?= e($b['status']) ?>"><?= e(BESTEL_STATUS[$b['status']]) ?></span></td>
        <td><?= $b['leverdatum'] ? e(date('d-m-Y', strtotime($b['leverdatum']))) : '–' ?><br><span class="small muted"><?= e($b['levering']) ?></span></td>
        <td class="small"><?= e($b['factuurnummer']) ?: '–' ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

  </div>
  <?php if (!is_onderaannemer()): ?>
  <div class="ptab" data-tab="installatie" id="installatie">
    <section class="card">
      <div class="row between wrap"><h2>Wat er staat<?= $locatie ? ' · ' . e(locatie_tekst($locatie)) : '' ?></h2>
        <?php if (is_admin() || rol() === 'monteur'): ?><a class="btn small primary" href="installatie.php?nr=<?= e(rawurlencode($pr['nummer'])) ?>">Toevoegen</a><?php endif; ?></div>
      <?php if (!$installaties): ?><p class="muted small">Nog niets in het register. Zet hier per apparaat merk, type, serienummer en monitoring; dan heeft de servicedesk het bij elke melding bij de hand.</p><?php endif; ?>
      <div class="tablewrap"><table>
        <?php if ($installaties): ?><thead><tr><th>Apparaat</th><th>Serienummer</th><th>Monitoring</th><th>Garantie tot</th></tr></thead><?php endif; ?>
        <tbody><?php foreach ($installaties as $in): ?>
          <tr class="<?= $in['status'] !== 'actief' ? 'muted' : '' ?>"><td><a href="installatie.php?id=<?= (int)$in['id'] ?>"><?= e(trim((INSTALLATIE_SOORTEN[$in['soort']] ?? '') . ' ' . $in['merk'] . ' ' . $in['type'])) ?></a><?= $in['vermogen'] !== '' ? ' <span class="small muted">' . e($in['vermogen']) . '</span>' : '' ?><?= $in['status'] !== 'actief' ? ' <span class="tag">' . e(INSTALLATIE_STATUS[$in['status']]) . '</span>' : '' ?></td>
            <td><?= e($in['serienummer'] ?: '–') ?></td><td class="small"><?= e(trim($in['monitoring'] . ' ' . $in['monitoring_id']) ?: '–') ?></td>
            <td class="<?= garantie_klasse($in['garantie_tot']) ?>"><?= $in['garantie_tot'] ? e(date('d-m-Y', strtotime($in['garantie_tot']))) : '–' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php if ($service && is_admin() && $installaties): ?>
      <form method="post" class="row wrap mt"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="installatie_koppel">
        <label class="grow">Dit ticket gaat over <select name="installatie_id"><option value="">– nog niet bekend –</option><?php foreach ($installaties as $in): ?><option value="<?= (int)$in['id'] ?>" <?= (int)$pr['installatie_id'] === (int)$in['id'] ? 'selected' : '' ?>><?= e(installatie_naam($in)) ?></option><?php endforeach; ?></select></label>
        <button class="btn small">Koppelen</button></form>
      <?php endif; ?>
    </section>
  </div>
  <?php endif; ?>
  <div class="ptab" data-tab="werkbonnen" id="werkbonnen">
    <section class="card">
      <div class="row between wrap"><h2>Werkbonnen</h2>
        <?php if ($magWerkbon): ?><a class="btn small primary" href="werkbon.php?nr=<?= e(rawurlencode($pr['nummer'])) ?>">Werkbon van vandaag</a><?php endif; ?></div>
      <?php if (!$werkbonnen): ?><p class="muted small">Nog geen werkbonnen. Maak er aan het eind van elk bezoek een en laat de klant tekenen op het scherm.</p><?php endif; ?>
      <ul class="plijst"><?php foreach ($werkbonnen as $w): ?>
        <li><a href="werkbon.php?id=<?= (int)$w['id'] ?>"><b><?= e(datum_kort($w['datum'])) ?></b></a> <?= e($w['monteurs']) ?>
          <?= $w['status'] === 'getekend' ? '<span class="tag ok">getekend door ' . e($w['klant_naam']) . '</span> <a class="small" href="werkbon.php?id=' . (int)$w['id'] . '&amp;pdf=1">PDF</a>' : '<span class="tag">' . ($w['concept_door'] !== '' ? 'concept van ' . e($w['concept_door']) : 'nog niet getekend') . '</span>' ?></li>
      <?php endforeach; ?></ul>
    </section>
  </div>
  <?php if (is_admin()): ?>
  <div class="ptab" data-tab="financieel" id="financieel">
    <section class="card">
      <h2>Begroting en facturen</h2>
      <div class="pcijfers">
        <div><b><?= fmt_eur($begrotingTotaal) ?></b><span>begroot (excl. btw)</span></div>
        <div><b><?= fmt_eur($gefactureerd) ?></b><span>gefactureerd of voorgesteld</span></div>
        <div><b><?= fmt_eur($nc['materiaal_voorraad'] + $nc['materiaal_direct']) ?></b><span>materiaal (voorraad <?= fmt_eur($nc['materiaal_voorraad']) ?>, direct geleverd <?= fmt_eur($nc['materiaal_direct']) ?>)</span></div>
        <div><b><?= str_replace('.', ',', (string)(float)$nc['uren']) ?></b><span>uren geschreven<?= $nc['zonder_kostprijs'] ? '' : ' · ' . fmt_eur($nc['kosten_uren']) ?></span></div>
        <div><b class="<?= $nc['marge_pct'] !== null && $nc['marge_pct'] < 15 ? 'rood' : '' ?>"><?= $nc['opbrengst'] > 0 ? fmt_eur($nc['marge']) : '–' ?></b><span>marge tot nu toe<?= $nc['marge_pct'] !== null ? ' (' . str_replace('.', ',', (string)$nc['marge_pct']) . '%)' : '' ?><?= $nc['zonder_kostprijs'] ? ' · zonder uren (kostprijs per uur niet ingesteld)' : '' ?></span></div>
      </div>
    </section>
    <section class="card">
      <div class="row between wrap"><h2>Factuurvoorstellen</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><button class="btn small" name="actie" value="factuur_regie" title="Uren en materiaal sinds het vorige voorstel">Voorstel uit uren en materiaal</button></form></div>
      <?php if (!$facturen): ?><p class="muted small">Nog geen factuurvoorstellen.</p><?php endif; ?>
      <ul class="plijst"><?php foreach ($facturen as $f): ?>
        <li><a href="facturen.php?id=<?= (int)$f['id'] ?>"><b><?= fmt_eur($f['totaal']) ?></b> <?= e($f['omschrijving']) ?></a> <span class="tag"><?= e(FACTUUR_STATUS[$f['status']]) ?></span><?= $f['boekhouding_nummer'] !== '' ? ' <span class="small muted">' . e($f['boekhouding_nummer']) . '</span>' : '' ?></li>
      <?php endforeach; ?></ul>
    </section>
    <section class="card">
      <h2>Betaaltermijnen</h2>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="termijnen">
        <div class="tablewrap"><table class="invoer"><thead><tr><th>Omschrijving</th><th>%</th><th>of bedrag</th><th>Moment</th><th></th></tr></thead><tbody>
        <?php foreach (array_merge($termijnen, [null, null]) as $t): $vast = $t && $t['fstatus']; ?>
          <tr><td><input name="t_oms[]" value="<?= e($t['omschrijving'] ?? '') ?>" placeholder="bijv. Bij opdracht" <?= $vast ? 'readonly' : '' ?>></td>
            <td><input name="t_pct[]" inputmode="decimal" value="<?= $t && $t['percentage'] !== null ? e(str_replace('.', ',', (string)(float)$t['percentage'])) : '' ?>" style="max-width:5rem" <?= $vast ? 'readonly' : '' ?>></td>
            <td><input name="t_bedrag[]" inputmode="decimal" value="<?= $t && $t['bedrag'] !== null ? e(str_replace('.', ',', (string)(float)$t['bedrag'])) : '' ?>" style="max-width:8rem" <?= $vast ? 'readonly' : '' ?>></td>
            <td><select name="t_moment[]"><?php foreach (TERMIJN_MOMENTEN as $k => $n): ?><option value="<?= $k ?>" <?= ($t['moment'] ?? 'handmatig') === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></td>
            <td class="small"><?php if ($t): ?><?= fmt_eur(termijn_bedrag($t, $begrotingTotaal)) ?>
              <?= $vast ? '<span class="tag">' . e(FACTUUR_STATUS[$t['fstatus']]) . '</span>' : ($t['controleren'] ? '<span class="tag oranje">al gefactureerd?</span>' : '<button class="btn small" form="ft' . (int)$t['id'] . '">Factuurvoorstel</button>') ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <button class="btn small">Termijnen opslaan</button>
      </form>
      <?php $teControleren = array_filter($termijnen, fn($t) => $t['controleren'] && !$t['fstatus']); if ($teControleren): ?>
        <div class="voorstel small mt"><b>Eerst controleren:</b> deze termijnen waren al aan de beurt voordat ze in de app stonden. Zijn ze al gefactureerd?
        <?php foreach ($teControleren as $t): ?>
          <form method="post" class="row wrap"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="termijn_id" value="<?= (int)$t['id'] ?>">
            <span class="grow">Termijn <?= (int)$t['volgorde'] ?>: <?= e($t['omschrijving']) ?> (<?= fmt_eur(termijn_bedrag($t, $begrotingTotaal)) ?>)</span>
            <input name="nummer" placeholder="factuurnummer" aria-label="Factuurnummer" style="max-width:9rem">
            <button class="btn small" name="actie" value="termijn_al">Al gefactureerd</button><button class="btn small ghost" name="actie" value="termijn_nog">Nog factureren</button></form>
        <?php endforeach; ?></div>
      <?php endif; ?>
      <?php foreach ($termijnen as $t): if ($t['fstatus']) continue; ?><form method="post" id="ft<?= (int)$t['id'] ?>"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="factuur_termijn"><input type="hidden" name="termijn_id" value="<?= (int)$t['id'] ?>"></form><?php endforeach; ?>
    </section>
    <section class="card">
      <details<?= $begroting ? '' : ' open' ?>><summary><b>Begroting (<?= count($begroting) ?> regels, <?= fmt_eur($begrotingTotaal) ?>)</b> <span class="small muted">uit de offerte; Claude vult hem bij een gewonnen deal</span></summary>
      <form method="post" class="stack mt"><?= csrf_field() ?><input type="hidden" name="nr" value="<?= e($pr['nummer']) ?>"><input type="hidden" name="actie" value="begroting">
        <div class="tablewrap"><table class="invoer"><thead><tr><th>Soort</th><th>Omschrijving</th><th>Aantal</th><th>Eenheid</th><th>Prijs</th><th class="r">Totaal</th></tr></thead><tbody>
        <?php foreach (array_merge($begroting, [null, null, null]) as $b): ?>
          <tr><td><select name="b_soort[]"><?php foreach (['materiaal' => 'Materiaal', 'arbeid' => 'Arbeid', 'overig' => 'Overig'] as $k => $n): ?><option value="<?= $k ?>" <?= ($b['soort'] ?? '') === $k ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></td>
            <td><input name="b_oms[]" value="<?= e($b['omschrijving'] ?? '') ?>"></td>
            <td><input name="b_aantal[]" inputmode="decimal" value="<?= $b ? e(str_replace('.', ',', (string)(float)$b['aantal'])) : '' ?>" style="max-width:5rem"></td>
            <td><input name="b_eenheid[]" value="<?= e($b['eenheid'] ?? 'stuks') ?>" style="max-width:6rem"></td>
            <td><input name="b_prijs[]" inputmode="decimal" value="<?= $b ? e(str_replace('.', ',', (string)(float)$b['prijs'])) : '' ?>" style="max-width:8rem"></td>
            <td class="r small"><?= $b ? fmt_eur((float)$b['aantal'] * (float)$b['prijs']) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <button class="btn small">Begroting opslaan</button>
      </form></details>
    </section>
  </div>
  <?php endif; ?>
  <div class="ptab" data-tab="boekingen" id="boekingen">
<section class="card">
  <div class="row between wrap">
    <h2>Geboekt op dit project<?= is_admin() ? ' · ' . fmt_eur($totaal) : '' ?></h2>
    <?php if ($items): ?><button class="btn small" type="button" data-export="expGeboekt" data-naam="project-<?= e($pr['nummer']) ?>">⬇ Excel</button><?php endif; ?>
  </div>
  <?php if (!$items): ?><p class="muted">Nog niets geboekt.</p><?php else: ?>
  <div class="tablewrap"><table>
    <thead><tr><th>Product</th><th>Groep</th><th class="r">Aantal</th><?php if (is_admin()): ?><th class="r">Waarde</th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($items as $i): ?>
      <tr><td><a href="product.php?id=<?= (int)$i['id'] ?>"><?= e($i['naam']) ?></a></td><td><?= e($i['groep']) ?></td>
        <td class="r"><?= fmt_n($i['netto']) ?> <span class="small muted"><?= $i['eenheid'] !== 'stuks' ? e($i['eenheid']) : '' ?></span></td>
        <?php if (is_admin()): ?><td class="r"><?= fmt_eur($i['waarde']) ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($hist): ?>
<section class="card">
  <details><summary><b>Alle boekingen (<?= count($hist) ?>)</b></summary>
  <div class="tablewrap mt"><table>
    <thead><tr><th>Wanneer</th><th>Wat</th><th>Product</th><th class="r">Aantal</th><th>Wie</th></tr></thead>
    <tbody>
      <?php foreach ($hist as $m): ?>
      <tr><td><?= e(fmt_dt($m['aangemaakt'])) ?></td><td><span class="tag t-<?= e($m['type']) ?>"><?= e(TYPES[$m['type']]) ?></span></td>
        <td><?= e($m['naam']) ?></td><td class="r"><?= ($m['aantal'] > 0 ? '+' : '') . fmt_n($m['aantal']) ?></td><td><?= e($m['wie'] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  </details>
</section>
<?php endif; ?>
  </div>
</div>
</div>
<script id="expGeboekt" type="application/json"><?= json_encode($exportGeboekt, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script id="expMateriaal" type="application/json"><?= json_encode($exportMateriaal, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php page_foot(['https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js']);
