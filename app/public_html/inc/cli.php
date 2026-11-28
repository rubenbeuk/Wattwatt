<?php
// Opdrachtregel voor Claude (brein-run, facturencontrole, werkvoorbereiding).
// Alleen via SSH op de server:  php /var/www/voorraad/public_html/inc/cli.php <opdracht> < invoer.json
// Uitvoer is altijd JSON. Niet via het web bereikbaar (nginx blokkeert /inc/).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/materiaal.php';
require __DIR__ . '/pipedrive.php';

$opdracht = $argv[1] ?? 'help';
// Invoer (JSON) via stdin; bij opdrachten zonder invoer niet wachten
$invoer = (in_array($opdracht, ['migreer', 'help', 'mensen'], true) || stream_isatty(STDIN)) ? '' : trim((string)stream_get_contents(STDIN));
$d = $invoer !== '' ? json_decode($invoer, true) : [];
if ($invoer !== '' && !is_array($d)) { uit(['fout' => 'ongeldige JSON']); exit(1); }

function uit($x): void { $GLOBALS['CL_UIT'] = $x; echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION), "\n"; }

/**
 * Logboek "Vandaag door Claude": welke rijen raakt een schrijfopdracht? Geeft [[tabel, id], ...].
 * Vóór de opdracht zonder $uit (bestaande rijen), erna met $uit (ook nieuw gemaakte rijen).
 */
function cl_doelen(string $opdracht, array $d, $uit = null): array
{
    $id = fn(string $sql, array $p) => ($r = q($sql, $p)->fetchColumn()) !== false ? (int)$r : null;
    $taak = fn($x) => is_int($x) || ctype_digit((string)$x) ? $id('SELECT id FROM taken WHERE id = ?', [(int)$x]) : (clean_text((string)$x) !== '' ? $id('SELECT id FROM taken WHERE extern_id = ?', [clean_text((string)$x)]) : null);
    $prj = fn($nr) => clean_text((string)$nr) !== '' ? $id('SELECT id FROM projecten WHERE nummer = ?', [project_nummer((string)$nr)]) : null;
    $u = is_array($uit) ? $uit : [];
    $lijst = match ($opdracht) {
        'taak' => [['taken', $taak($d['extern_id'] ?? '') ?? (!empty($d['id']) ? $taak((int)$d['id']) : null) ?? ($u['id'] ?? null)]],
        'taak_klaar', 'taak_status', 'taak_vervalt' => [['taken', !empty($d['id']) ? $taak((int)$d['id']) : $taak((string)($d['extern_id'] ?? ''))]],
        'taak_samenvoegen' => [['taken', $taak($d['dubbel'] ?? '')], ['taken', $taak($d['in'] ?? '')]],
        'plan_voorstel' => [['voorstellen', $u['id'] ?? null]],
        'dagopdracht' => [['dagopdrachten', ($p = $prj($d['project'] ?? '')) && geldige_datum($d['datum'] ?? null) ? $id('SELECT id FROM dagopdrachten WHERE datum = ? AND project_id = ?', [geldige_datum($d['datum']), $p]) : null]],
        'notitie_verwerkt' => [['notities', (int)($d['id'] ?? 0) ?: null]],
        'project_info', 'project_samenvatting' => [['projecten', $prj($d['project'] ?? '')]],
        'project_bericht' => [['project_berichten', clean_text($d['extern_id'] ?? '') !== '' ? $id('SELECT id FROM project_berichten WHERE extern_id = ?', [clean_text($d['extern_id'])]) : ($u['id'] ?? null)]],
        'mijlpaal' => [['project_mijlpalen', clean_text($d['extern_id'] ?? '') !== '' ? $id('SELECT id FROM project_mijlpalen WHERE extern_id = ?', [project_nummer((string)($d['project'] ?? '')) . ':' . clean_text($d['extern_id'])]) : null]],
        'ticket' => [['projecten', (!empty($d['nummer']) ? $prj($d['nummer']) : null) ?? (!empty($d['ref']) ? $id('SELECT id FROM projecten WHERE extern_ref = ?', [clean_text($d['ref'])]) : null) ?? (!empty($u['nummer']) ? $prj($u['nummer']) : null)]],
        'afspraak' => [['afspraken', (clean_text($d['extern_id'] ?? '') !== '' ? $id('SELECT id FROM afspraken WHERE extern_id = ?', [clean_text($d['extern_id'])]) : null) ?? (!empty($d['id']) ? (int)$d['id'] : null) ?? ($u['id'] ?? null)]],
        'bespreekpunt' => [['bespreekpunten', (clean_text($d['extern_id'] ?? '') !== '' ? $id('SELECT id FROM bespreekpunten WHERE extern_id = ?', [clean_text($d['extern_id'])]) : null) ?? ($u['id'] ?? null)]],
        'taak_opschoonvoorstel' => [['taak_voorstellen', $u['id'] ?? null]],
        'oplevering_concept' => [['opleveringen', $prj($d['project'] ?? '')]],
        'vraag_antwoord' => [['notities', (int)($d['id'] ?? 0) ?: null], ['project_berichten', $id('SELECT id FROM project_berichten WHERE extern_id = ?', ['vraag:' . (int)($d['id'] ?? 0)])]],
        'locatie' => [['locaties', (int)($d['id'] ?? 0) ?: ($u['id'] ?? null)]],
        'installatie' => [['installaties', (int)($d['id'] ?? 0) ?: (clean_text($d['serienummer'] ?? '') !== '' ? $id('SELECT id FROM installaties WHERE serienummer = ? LIMIT 1', [clean_text($d['serienummer'])]) : null) ?? ($u['id'] ?? null)]],
        'ticket_installatie' => [['projecten', $prj($d['ticket'] ?? '')]],
        'werkbon_concept' => [['werkbonnen', ($p = $prj($d['project'] ?? '')) ? $id("SELECT id FROM werkbonnen WHERE project_id = ? AND datum = ? AND status = 'concept' ORDER BY id DESC LIMIT 1", [$p, geldige_datum($d['datum'] ?? '') ?? vandaag()]) : null]],
        'factuur_voorstel' => [['facturen', $u['factuur'] ?? null]],
        'contract' => [['contracten', (int)($d['id'] ?? 0) ?: ($u['id'] ?? null)]],
        'klantbericht' => [['klantberichten', (clean_text((string)($d['sleutel'] ?? '')) !== '' ? $id('SELECT id FROM klantberichten WHERE sleutel = ?', [clean_text((string)$d['sleutel'])]) : null) ?? ($u['id'] ?? null)]],
        default => [],
    };
    return array_values(array_filter($lijst, fn($x) => $x[1]));
}
function pid_of_fout(string $nr): int
{
    $r = q('SELECT id FROM projecten WHERE nummer = ?', [project_nummer($nr)])->fetch();
    if (!$r) { uit(['fout' => 'project niet gevonden: ' . project_nummer($nr)]); exit(1); }
    return (int)$r['id'];
}

try {
    // Stand vóór de opdracht, voor het logboek en Ongedaan
    $clVoor = [];
    foreach (cl_doelen($opdracht, $d) as [$t, $i]) $clVoor["$t:$i"] = cl_rij($t, $i);
    switch ($opdracht) {
        case 'migreer':
            uit(['ok' => true, 'wijzigingen' => schema_migreren()]);
            break;

        // {"project":"PRJ-289","omschrijving":"...","bron":"...","vervang":false,"regels":[{"omschrijving":"..","ean":"..","code":"..","aantal":3,"eenheid":"stuks"}]}
        case 'materiaallijst':
            $r = materiaallijst_import((string)($d['project'] ?? ''), $d['regels'] ?? [], (string)($d['bron'] ?? 'Claude'), !empty($d['vervang']), (string)($d['omschrijving'] ?? ''));
            uit(['resultaat' => $r, 'overzicht' => project_overzicht($r['project_id'])]);
            break;

        // {"leverancier":"Rexel","ordernummer":"...","project":"PRJ-289","besteldatum":"2026-11-14","leverdatum":"2026-11-18","levering":"project|magazijn","afleveradres":"..","status":"besteld|bevestigd|verzonden|geleverd","besteld_door":"julian","bron":"mail ...","regels":[...]}
        case 'bestelling':
            uit(bestelling_opslaan($d));
            break;

        // {"leverancier":"Rexel","ordernummer":"...","waar":"magazijn|project"}
        case 'geleverd':
            $b = q('SELECT id FROM bestellingen WHERE leverancier = ? AND ordernummer = ?', [lev_naam($d['leverancier'] ?? ''), clean_text($d['ordernummer'] ?? '')])->fetch();
            if (!$b) { uit(['fout' => 'bestelling niet gevonden']); exit(1); }
            uit(bestelling_geleverd((int)$b['id'], $d['waar'] ?? null, 'Claude'));
            break;

        // {"leverancier":"Rexel","ordernummer":"..."|null,"project":"PRJ-289"|null,"factuurnummer":"...","bedrag":123.45}
        case 'factuur':
            uit(factuur_koppelen($d));
            break;

        // {"project":"PRJ-289"}
        case 'project':
            uit(project_overzicht(pid_of_fout((string)($d['project'] ?? ''))));
            break;

        // {"project":"PRJ-289"} of {} voor alles
        case 'bestellijst':
            uit(bestellijst(!empty($d['project']) ? pid_of_fout((string)$d['project']) : null));
            break;

        // Bekende bestellingen (om dubbel verwerken te voorkomen). {"sinds":"2026-10-01"} optioneel
        case 'bestellingen':
            $sinds = $d['sinds'] ?? '2000-01-01';
            uit(q("SELECT b.leverancier, b.ordernummer, p.nummer AS project, b.status, b.besteldatum, b.leverdatum, b.levering, b.factuurnummer
                   FROM bestellingen b LEFT JOIN projecten p ON p.id = b.project_id WHERE COALESCE(b.besteldatum, DATE(b.aangemaakt)) >= ? ORDER BY b.id", [$sinds])->fetchAll());
            break;

        // {"q":"hager 16A"} producten zoeken
        case 'zoek':
            $w = '%' . clean_text($d['q'] ?? '') . '%';
            uit(q('SELECT code, naam, groep, ean, leverancier_code, voorraad, prijs, locatie FROM producten WHERE actief = 1 AND (naam LIKE ? OR ean LIKE ? OR leverancier_code LIKE ? OR code LIKE ?) LIMIT 25', [$w, $w, $w, $w])->fetchAll());
            break;

        // Artikel aanmaken als het nog niet bestaat. {"naam":"..","code":"563528","ean":"..","leverancier":"Rexel","eenheid":"stuks","groep":"Wartels"}
        // Bestaat het al (zelfde leverancierscode, EAN of exacte naam), dan komt het bestaande artikel terug.
        case 'product':
            $naam = clean_text($d['naam'] ?? '');
            if ($naam === '') { uit(['fout' => 'naam ontbreekt']); exit(1); }
            $id = product_zoek($d['ean'] ?? null, $d['code'] ?? null, $naam);
            $nieuw = false;
            if (!$id) {
                $id = product_maak($naam, $d['ean'] ?? null, $d['code'] ?? null, clean_text($d['leverancier'] ?? ''), null, clean_text($d['eenheid'] ?? '') ?: 'stuks');
                if (clean_text($d['groep'] ?? '') !== '') q('UPDATE producten SET groep = ? WHERE id = ?', [mb_substr(clean_text($d['groep']), 0, 100), $id]);
                $nieuw = true;
            }
            uit(['nieuw' => $nieuw] + q('SELECT code, naam, groep, ean, leverancier, leverancier_code, eenheid, voorraad FROM producten WHERE id = ?', [$id])->fetch());
            break;

        // ================= Operatie: taken, planning, instructies =================
        // Wie er zijn. Gebruik de gebruikersnaam in de andere opdrachten.
        case 'mensen':
            uit(q("SELECT naam, gebruikersnaam, IF(wachtwoord_hash = '', 'geen login', IF(rol = 'admin', 'kantoor', rol)) AS rol, planbaar FROM gebruikers WHERE actief = 1 ORDER BY volgorde, naam")->fetchAll());
            break;

        // Taak aanmaken of bijwerken (zelfde extern_id = zelfde taak; een afgeronde taak gaat nooit vanzelf weer open).
        // {"extern_id":"pd:1264","titel":"..","toelichting":"..","project":"PRJ-293","eigenaar":"julian"|null,"datum":"2026-11-17","deadline":"2026-11-18","prioriteit":"kritiek|hoog|normaal|laag","volgorde":10,"bron":"Mail Ruben 16-11"}
        case 'taak':
            $ext = clean_text($d['extern_id'] ?? '');
            $eig = ($d['eigenaar'] ?? '') !== '' ? gebruiker_zoek((string)$d['eigenaar']) : null;
            if (($d['eigenaar'] ?? '') !== '' && !$eig) { uit(['fout' => 'eigenaar onbekend: ' . $d['eigenaar']]); exit(1); }
            $pid = ($d['project'] ?? '') !== '' ? project_id((string)$d['project']) : null;
            $best = $ext !== '' ? q('SELECT * FROM taken WHERE extern_id = ?', [$ext])->fetch() : null;
            // Bijwerken kan ook op id (voor taken die iemand zelf in de app maakte)
            if (!$best && !empty($d['id'])) $best = q('SELECT * FROM taken WHERE id = ?', [(int)$d['id']])->fetch() ?: null;
            if ($best) {
                $set = []; $par = [];
                foreach (['titel' => fn($v) => mb_substr(clean_text($v), 0, 255), 'toelichting' => fn($v) => trim((string)$v) ?: null, 'datum' => 'geldige_datum', 'deadline' => 'geldige_datum',
                          'prioriteit' => fn($v) => isset(TAAK_PRIO[$v]) ? $v : 'normaal', 'volgorde' => 'intval', 'bron' => fn($v) => mb_substr(clean_text($v), 0, 255)] as $k => $f) {
                    if (array_key_exists($k, $d)) { $set[] = "$k = ?"; $par[] = $f($d[$k]); }
                }
                if (array_key_exists('project', $d)) { $set[] = 'project_id = ?'; $par[] = $pid; }
                // Eigenaar alleen invullen als er nog geen is: wat een mens verdeelde blijft staan
                if ($eig && !$best['eigenaar_id']) { $set[] = 'eigenaar_id = ?'; $par[] = $eig; }
                // Overdracht: de eigenaar gaf de taak zelf door aan een ander. {"overdracht":"Berend mailde het 16-11 door aan Ruben"}
                elseif ($eig && (int)$best['eigenaar_id'] !== $eig && clean_text($d['overdracht'] ?? '') !== '' && $best['status'] !== 'klaar') {
                    $set[] = 'eigenaar_id = ?'; $par[] = $eig;
                    $nm = q('SELECT naam FROM gebruikers WHERE id = ?', [$eig])->fetch()['naam'];
                    taak_log((int)$best['id'], 'Overgedragen aan ' . $nm . ': ' . clean_text($d['overdracht']), null, 'Claude');
                    seintje($eig, 'Taak aan jou overgedragen: ' . mb_substr($best['titel'], 0, 150), 'taak.php?id=' . (int)$best['id']);
                }
                if ($set) { $par[] = $best['id']; q('UPDATE taken SET ' . implode(', ', $set) . ' WHERE id = ?', $par); }
                uit(['id' => (int)$best['id'], 'nieuw' => false, 'status' => $best['status']]);
            } else {
                // Strengere regels (28-11-2026): een nieuwe taak heeft altijd een eigenaar, en eerst bijwerken dan nieuw maken
                if (!$eig) { uit(['fout' => 'eigenaar ontbreekt: een taak zonder eigenaar bestaat niet. Kies ruben, berend of julian, of maak er een bespreekpunt van']); exit(1); }
                if (empty($d['toch_nieuw'])) {
                    $lijkt = taak_lijkt_op((string)($d['titel'] ?? ''), $pid, $eig);
                    if ($lijkt) { uit(['fout' => 'lijkt op een open taak: werk die bij met {"id":..} of stuur "toch_nieuw":true als het echt een andere zaak is', 'bestaand' => $lijkt]); exit(1); }
                }
                $id = taak_maak(['titel' => $d['titel'] ?? '', 'toelichting' => $d['toelichting'] ?? '', 'project_id' => $pid, 'eigenaar_id' => $eig, 'datum' => $d['datum'] ?? null,
                    'deadline' => $d['deadline'] ?? null, 'prioriteit' => $d['prioriteit'] ?? 'normaal', 'volgorde' => $d['volgorde'] ?? 100, 'bron' => $d['bron'] ?? 'Claude', 'extern_id' => $ext, 'van_id' => ($d['van'] ?? '') !== '' ? gebruiker_zoek((string)$d['van']) : null], null, 'Claude');
                uit(['id' => $id, 'nieuw' => true, 'status' => 'open']);
            }
            break;

        // Open taken. {"eigenaar":"julian"} of {} voor alles; {"ook_klaar_sinds":"2026-11-16"} toont ook wat sindsdien is afgerond.
        case 'taken':
            $w = ["(t.status <> 'klaar'" . (!empty($d['ook_klaar_sinds']) ? ' OR t.afgerond_op >= ?' : '') . ')']; $par = [];
            if (!empty($d['ook_klaar_sinds'])) $par[] = geldige_datum($d['ook_klaar_sinds']) ?? '2999-01-01';
            if (($d['eigenaar'] ?? '') !== '') { $w[] = 't.eigenaar_id = ?'; $par[] = gebruiker_zoek((string)$d['eigenaar']) ?? 0; }
            uit(q('SELECT t.id, t.extern_id, t.titel, g.gebruikersnaam AS eigenaar, pr.nummer AS project, t.datum, t.deadline, t.prioriteit, t.status, t.wacht_op, t.bron, t.afgerond_op, LEFT(t.toelichting, 200) AS toelichting
                   FROM taken t LEFT JOIN gebruikers g ON g.id = t.eigenaar_id LEFT JOIN projecten pr ON pr.id = t.project_id
                   WHERE ' . implode(' AND ', $w) . ' ORDER BY g.gebruikersnaam, COALESCE(t.datum, t.deadline, \'2999-12-31\'), t.volgorde, t.id', $par)->fetchAll());
            break;

        // Taak afvinken met bewijs. {"id":12} of {"extern_id":"pd:1264"}, plus "bewijs":"Mail Julian 16-11: alles besteld"
        case 'taak_klaar':
            $t = !empty($d['id']) ? q('SELECT * FROM taken WHERE id = ?', [(int)$d['id']])->fetch() : q('SELECT * FROM taken WHERE extern_id = ?', [clean_text($d['extern_id'] ?? '')])->fetch();
            if (!$t) { uit(['fout' => 'taak niet gevonden']); exit(1); }
            if (clean_text($d['bewijs'] ?? '') === '') { uit(['fout' => 'bewijs ontbreekt']); exit(1); }
            if ($t['status'] !== 'klaar') taak_zet_status((int)$t['id'], 'klaar', 'Bewijs: ' . clean_text($d['bewijs']), null, 'Claude');
            uit(['id' => (int)$t['id'], 'status' => 'klaar']);
            break;

        // Twee taken over dezelfde zaak samenvoegen: "dubbel" vervalt, "in" blijft en krijgt de informatie erbij.
        // {"dubbel":77,"in":29,"reden":"zelfde zaak: Berend mailde het door aan Ruben"}  (id of extern_id)
        case 'taak_samenvoegen':
            $vind = fn($x) => is_int($x) || ctype_digit((string)$x) ? q('SELECT * FROM taken WHERE id = ?', [(int)$x])->fetch() : q('SELECT * FROM taken WHERE extern_id = ?', [clean_text((string)$x)])->fetch();
            $dub = $vind($d['dubbel'] ?? ''); $blijft = $vind($d['in'] ?? '');
            $reden = clean_text($d['reden'] ?? '');
            if (!$dub || !$blijft || (int)$dub['id'] === (int)$blijft['id']) { uit(['fout' => 'taak niet gevonden, of twee keer dezelfde']); exit(1); }
            if ($reden === '') { uit(['fout' => 'reden ontbreekt']); exit(1); }
            if ($dub['status'] !== 'klaar') {
                $extra = "Samengevoegd met: " . $dub['titel'] . ($dub['bron'] !== '' ? ' (' . $dub['bron'] . ')' : '') . ($dub['toelichting'] ? "\n" . $dub['toelichting'] : '');
                $prio = array_search($dub['prioriteit'], array_keys(TAAK_PRIO)) < array_search($blijft['prioriteit'], array_keys(TAAK_PRIO)) ? $dub['prioriteit'] : $blijft['prioriteit'];
                $dl = ($dub['deadline'] && (!$blijft['deadline'] || $dub['deadline'] < $blijft['deadline'])) ? $dub['deadline'] : $blijft['deadline'];
                q('UPDATE taken SET toelichting = ?, prioriteit = ?, deadline = ? WHERE id = ?', [trim(($blijft['toelichting'] ?? '') . "\n\n" . $extra), $prio, $dl, $blijft['id']]);
                taak_log((int)$blijft['id'], 'Taak #' . $dub['id'] . ' hierin samengevoegd: ' . $reden, null, 'Claude');
                taak_zet_status((int)$dub['id'], 'klaar', 'Vervallen, samengevoegd met taak #' . $blijft['id'] . ' (' . mb_substr($blijft['titel'], 0, 120) . '): ' . $reden, null, 'Claude');
            }
            uit(['vervallen' => (int)$dub['id'], 'blijft' => (int)$blijft['id'], 'vervallen_extern_id' => $dub['extern_id']]);
            break;

        // Status of stand van een taak. {"id":90 | "extern_id":"..","status":"open|bezig|wacht","wacht_op":"Robert Geerlings (Spuigroep)","notitie":"Ruben vroeg 19-11 of het kan"}
        // Zonder "status" (of gelijke status) komt alleen de notitie in het logboek; die staat dan als laatste stand op de kaart.
        case 'taak_status':
            $t = !empty($d['id']) ? q('SELECT * FROM taken WHERE id = ?', [(int)$d['id']])->fetch() : q('SELECT * FROM taken WHERE extern_id = ?', [clean_text($d['extern_id'] ?? '')])->fetch();
            if (!$t) { uit(['fout' => 'taak niet gevonden']); exit(1); }
            if ($t['status'] === 'klaar') { uit(['fout' => 'taak is al klaar']); exit(1); }
            $st = $d['status'] ?? $t['status']; $wo = clean_text($d['wacht_op'] ?? $t['wacht_op']); $not = mb_substr(clean_text($d['notitie'] ?? ''), 0, 400);
            if (!in_array($st, ['open', 'bezig', 'wacht'], true)) { uit(['fout' => 'status moet open, bezig of wacht zijn']); exit(1); }
            if ($st !== $t['status'] || ($st === 'wacht' && $wo !== $t['wacht_op'])) taak_zet_status((int)$t['id'], $st, $not, null, 'Claude', $wo);
            elseif ($not !== '') taak_log((int)$t['id'], $not, null, 'Claude');
            uit(q('SELECT id, status, wacht_op FROM taken WHERE id = ?', [(int)$t['id']])->fetch());
            break;

        // Taak laten vervallen zonder dat hij gedaan is (niet meer nodig). {"id":47,"reden":"installatie is afgerond"}
        case 'taak_vervalt':
            $t = !empty($d['id']) ? q('SELECT * FROM taken WHERE id = ?', [(int)$d['id']])->fetch() : q('SELECT * FROM taken WHERE extern_id = ?', [clean_text($d['extern_id'] ?? '')])->fetch();
            if (!$t) { uit(['fout' => 'taak niet gevonden']); exit(1); }
            if (clean_text($d['reden'] ?? '') === '') { uit(['fout' => 'reden ontbreekt']); exit(1); }
            if ($t['status'] !== 'klaar') taak_zet_status((int)$t['id'], 'klaar', 'Vervallen (niet meer nodig): ' . clean_text($d['reden']), null, 'Claude');
            uit(['id' => (int)$t['id'], 'status' => 'klaar', 'extern_id' => $t['extern_id']]);
            break;

        // Wat er op een dag is bijgewerkt, per persoon (voor de dagstatus). {"datum":"2026-11-16"}
        case 'dagstatus':
            $dag = geldige_datum($d['datum'] ?? null) ?? vandaag();
            $van = gmdate('Y-m-d H:i:s', strtotime("$dag 00:00:00")); $tot = gmdate('Y-m-d H:i:s', strtotime("$dag 23:59:59"));
            uit(['datum' => $dag,
                'taken' => q("SELECT COALESCE(g.naam, l.wie) AS door, t.titel, pr.nummer AS project, l.tekst, l.aangemaakt FROM taak_log l JOIN taken t ON t.id = l.taak_id
                              LEFT JOIN gebruikers g ON g.id = l.gebruiker_id LEFT JOIN projecten pr ON pr.id = t.project_id
                              WHERE l.aangemaakt BETWEEN ? AND ? AND l.tekst NOT LIKE 'Aangemaakt%' ORDER BY door, l.id", [$van, $tot])->fetchAll(),
                'meldingen' => q('SELECT g.naam AS door, m.soort, m.tekst, pr.nummer AS project, m.aangemaakt FROM meldingen m JOIN gebruikers g ON g.id = m.gebruiker_id
                                  LEFT JOIN projecten pr ON pr.id = m.project_id WHERE m.datum = ? ORDER BY m.id', [$dag])->fetchAll()]);
            break;

        // De planning lezen. {"van":"2026-11-16","tot":"2026-11-30"}
        case 'planning':
            $van = geldige_datum($d['van'] ?? null) ?? vandaag(); $tot = geldige_datum($d['tot'] ?? null) ?? dag_plus($van, 13);
            uit(q('SELECT pl.datum, pl.dagdeel, g.gebruikersnaam AS wie, pr.nummer AS project, pr.omschrijving AS projectnaam, pl.tekst, pl.voorbehoud
                   FROM planning pl JOIN gebruikers g ON g.id = pl.gebruiker_id LEFT JOIN projecten pr ON pr.id = pl.project_id
                   WHERE pl.datum BETWEEN ? AND ? ORDER BY pl.datum, g.volgorde, g.naam, pl.dagdeel', [$van, $tot])->fetchAll());
            break;

        // Voorstel voor de planning. De planning verandert pas na akkoord van kantoor in de app.
        // {"titel":"..","toelichting":"..","bron":"WhatsApp Joey 12-11","wijzigingen":[{"datum":"2026-12-01","dagdeel":"dag|ochtend|middag","wie":"joey","project":"PRJ-296"|"","tekst":"","voorbehoud":false}]}
        case 'plan_voorstel':
            [$regels, $fouten] = voorstel_regels($d['wijzigingen'] ?? []);
            if ($fouten || !$regels || clean_text($d['titel'] ?? '') === '') { uit(['fout' => $fouten ? implode('; ', $fouten) : 'titel of wijzigingen ontbreken']); exit(1); }
            q('INSERT INTO voorstellen (titel, toelichting, wijzigingen, bron) VALUES (?,?,?,?)', [mb_substr(clean_text($d['titel']), 0, 255), trim((string)($d['toelichting'] ?? '')) ?: null,
                json_encode($d['wijzigingen'], JSON_UNESCAPED_UNICODE), mb_substr(clean_text($d['bron'] ?? 'Claude'), 0, 255)]);
            uit(['id' => (int)db()->lastInsertId(), 'regels' => count($regels), 'status' => 'open']);
            break;

        // Stand van de voorstellen (om te zien wat kantoor besloot). {"sinds":"2026-11-01"}
        case 'voorstellen':
            uit(q('SELECT v.id, v.titel, v.status, g.naam AS besloten_door, v.besloten_op, v.aangemaakt FROM voorstellen v LEFT JOIN gebruikers g ON g.id = v.besloten_door
                   WHERE v.aangemaakt >= ? ORDER BY v.id', [geldige_datum($d['sinds'] ?? null) ?? '2000-01-01'])->fetchAll());
            break;

        // Planning direct zetten. ALLEEN voor de eerste vulling of als Ruben er in de chat om vraagt; anders plan_voorstel.
        // Zelfde invoer als de wijzigingen van plan_voorstel: {"wijzigingen":[...]}
        case 'plan_zet':
            [$regels, $fouten] = voorstel_regels($d['wijzigingen'] ?? []);
            if ($fouten) { uit(['fout' => implode('; ', $fouten)]); exit(1); }
            $n = 0;
            foreach ($regels as $r) foreach ($r['dagdelen'] as $dd) { plan_zet($r['datum'], $dd, $r['gid'], $r['pid'], $r['tekst'], $r['voorbehoud'], null); $n++; }
            uit(['dagdelen' => $n]);
            break;

        // Instructie voor de monteurs klaarzetten (concept). Monteurs zien hem pas na akkoord van twee mensen.
        // {"datum":"2026-11-17","project":"PRJ-284","wat":"regel per stap","meenemen":"..","stroom":"8:00 tot 12:00","bron":"Mail Ruben 16-11"}
        case 'dagopdracht':
            $dag = geldige_datum($d['datum'] ?? null);
            if (!$dag) { uit(['fout' => 'datum ontbreekt']); exit(1); }
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            $oud = dagopdracht($dag, $pid);
            if (dagopdracht_vrij($oud) && empty($d['overschrijf'])) { uit(['fout' => 'deze instructie is al vrijgegeven door Ruben of Berend; alleen met "overschrijf":true (dan vervalt het akkoord)']); exit(1); }
            dagopdracht_opslaan($dag, $pid, (string)($d['wat'] ?? ''), (string)($d['meenemen'] ?? ''), (string)($d['stroom'] ?? ''), null, 'Claude', (string)($d['bron'] ?? ''));
            uit(['ok' => true, 'vrijgegeven' => false]);
            break;

        // Stand van de instructies per ingepland project per dag. {"van":"2026-11-17","tot":"2026-11-20"}
        // stand: leeg | concept (klaargezet, nog niemand keek) | controle (wacht op akkoord van Ruben of Berend) | vrij (monteurs zien hem)
        case 'instructies':
            $van = geldige_datum($d['van'] ?? null) ?? vandaag(); $tot = geldige_datum($d['tot'] ?? null) ?? dag_plus($van, 6);
            $uitvoer = [];
            foreach (q("SELECT pl.datum, pr.id, pr.nummer, pr.omschrijving, GROUP_CONCAT(DISTINCT g.gebruikersnaam ORDER BY g.volgorde SEPARATOR ', ') AS wie
                        FROM planning pl JOIN projecten pr ON pr.id = pl.project_id JOIN gebruikers g ON g.id = pl.gebruiker_id
                        WHERE pl.datum BETWEEN ? AND ? GROUP BY pl.datum, pr.id ORDER BY pl.datum, pr.nummer", [$van, $tot])->fetchAll() as $r) {
                $o = dagopdracht($r['datum'], (int)$r['id']);
                $uitvoer[] = ['datum' => $r['datum'], 'project' => $r['nummer'], 'projectnaam' => project_naam($r), 'wie' => $r['wie'],
                    'stand' => dagopdracht_stand($o),
                    'wat' => $o['wat'] ?? '', 'meenemen' => $o['meenemen'] ?? '', 'stroom' => $o['stroom'] ?? '', 'bijgewerkt_door' => $o['bijgewerkt_door'] ?? ''];
            }
            uit($uitvoer);
            break;

        // Notities die mensen in de app achterlieten. {"status":"nieuw"} (standaard) of {"sinds":"2026-11-16"} voor alles sinds die dag.
        case 'notities':
            if (!empty($d['sinds'])) { $w = 'n.aangemaakt >= ?'; $par = [geldige_datum($d['sinds']) ?? '2000-01-01']; } else { $w = "n.status = 'nieuw'"; $par = []; }
            uit(q("SELECT n.id, n.soort, g.gebruikersnaam AS van, g.beslisser, IF(g.rol = 'admin', 'kantoor', g.rol) AS rol, pr.nummer AS project, n.tekst, n.status, n.verwerking, n.aangemaakt
                   FROM notities n JOIN gebruikers g ON g.id = n.gebruiker_id LEFT JOIN projecten pr ON pr.id = n.project_id WHERE $w ORDER BY n.id", $par)->fetchAll());
            break;

        // Notitie afmelden met wat ermee is gedaan (dat ziet de schrijver in de app). {"id":3,"verwerking":"Voorstel gemaakt: Joey leeg vanaf 23-11"}
        case 'notitie_verwerkt':
            $tekst = trim((string)($d['verwerking'] ?? ''));
            if ($tekst === '') { uit(['fout' => 'verwerking ontbreekt']); exit(1); }
            q("UPDATE notities SET status = 'verwerkt', verwerking = ?, verwerkt_op = UTC_TIMESTAMP() WHERE id = ?", [mb_substr($tekst, 0, 1000), (int)($d['id'] ?? 0)]);
            uit(['id' => (int)($d['id'] ?? 0), 'status' => 'verwerkt']);
            break;

        // Projectgegevens aanvullen (alleen lege velden, tenzij "overschrijf":true). {"project":"PRJ-284","naam":"..","klant":"..","adres":"..","contact":".."}
        // Alle projecten met fase (alleen lezen). {} of {"alle":true} om ook afgevinkte te zien
        case 'projecten':
            uit(q('SELECT nummer, soort, omschrijving AS naam, klant, fase, afgerond, opleverdatum, kwh, aangemaakt FROM projecten' . (empty($d['alle']) ? ' WHERE afgerond = 0' : '') . ' ORDER BY nummer')->fetchAll());
            break;

        // Lege projecten opruimen die per ongeluk zijn aangemaakt: geen naam, klant of adres, fase nieuw, door de CLI gemaakt in de laatste N minuten
        // en nergens aan gekoppeld. {"minuten":120} laat zien wat weg zou gaan; met "uitvoeren":true gaat het weg.
        case 'project_opruimen':
            $min = max(1, min(1440, (int)($d['minuten'] ?? 0)));
            if (empty($d['minuten'])) { uit(['fout' => 'minuten ontbreekt']); exit(1); }
            $kand = q("SELECT id, nummer, aangemaakt FROM projecten WHERE omschrijving = '' AND klant = '' AND adres = '' AND fase = 'nieuw' AND afgerond = 0
                       AND aangemaakt_door IS NULL AND aangemaakt >= NOW() - INTERVAL $min MINUTE ORDER BY nummer")->fetchAll();
            $tabellen = q("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'project_id'")->fetchAll(PDO::FETCH_COLUMN);
            $weg = []; $blijft = [];
            foreach ($kand as $k) {
                $gekoppeld = null;
                foreach ($tabellen as $t) if (q("SELECT 1 FROM `$t` WHERE project_id = ? LIMIT 1", [$k['id']])->fetch()) { $gekoppeld = $t; break; }
                if ($gekoppeld) { $blijft[] = $k['nummer'] . ' (' . $gekoppeld . ')'; continue; }
                if (!empty($d['uitvoeren'])) q('DELETE FROM projecten WHERE id = ?', [$k['id']]);
                $weg[] = $k['nummer'];
            }
            uit(['uitgevoerd' => !empty($d['uitvoeren']), 'aantal' => count($weg), 'weg' => $weg, 'blijft_gekoppeld' => $blijft]);
            break;

        case 'project_info':
            // Alleen een nummer = opvragen: dan nooit een project aanmaken
            $pid = count(array_diff(array_keys($d), ['project'])) ? project_id((string)($d['project'] ?? '')) : pid_of_fout((string)($d['project'] ?? ''));
            if (!$pid) { uit(['fout' => 'project ontbreekt']); exit(1); }
            $nu = q('SELECT * FROM projecten WHERE id = ?', [$pid])->fetch();
            foreach (['naam' => 'omschrijving', 'klant' => 'klant', 'adres' => 'adres', 'contact' => 'contact', 'systeem' => 'systeem'] as $in => $kol) {
                $v = clean_text($d[$in] ?? '');
                if ($v !== '' && ($nu[$kol] === '' || !empty($d['overschrijf']))) q("UPDATE projecten SET $kol = ? WHERE id = ?", [mb_substr($v, 0, 255), $pid]);
            }
            // Fase (sleutel of Pipedrive phase_id), opleverdatum en kWh: altijd bijwerken als ze zijn meegegeven
            if (isset($d['fase'])) {
                $soort = $nu['soort'] ?? 'project';
                $bf = is_numeric($d['fase']) ? fase_van_pipedrive_bord((int)$d['fase']) : null;
                $f = $bf ? ($bf[0] === $soort ? $bf[1] : null) : (isset(fases($soort)[$d['fase']]) ? $d['fase'] : null);
                if (!$f) { uit(['fout' => 'onbekende fase voor ' . $soort . ': ' . $d['fase']]); exit(1); }
                if ($soort === 'service') ticket_fase($pid, $f); else q('UPDATE projecten SET fase = ? WHERE id = ?', [$f, $pid]);
            }
            foreach (['startdatum', 'einddatum'] as $k) if (array_key_exists($k, $d)) q("UPDATE projecten SET $k = ? WHERE id = ?", [geldige_datum($d[$k]), $pid]);
            if (array_key_exists('opleverdatum', $d)) q('UPDATE projecten SET opleverdatum = ? WHERE id = ?', [geldige_datum($d['opleverdatum']), $pid]);
            if (!empty($d['kwh'])) q('UPDATE projecten SET kwh = ? WHERE id = ?', [(int)$d['kwh'], $pid]);
            if (isset($d['afgerond'])) q('UPDATE projecten SET afgerond = ? WHERE id = ?', [$d['afgerond'] ? 1 : 0, $pid]);
            if (!empty($d['pipedrive_id'])) q('UPDATE projecten SET pipedrive_id = ? WHERE id = ?', [(int)$d['pipedrive_id'], $pid]);
            if (!empty($d['deal_id'])) q('UPDATE projecten SET pipedrive_deal_id = ? WHERE id = ?', [(int)$d['deal_id'], $pid]);
            project_koppel_klant($pid);
            uit(q('SELECT nummer, soort, omschrijving AS naam, klant, adres, contact, fase, startdatum, einddatum, opleverdatum, kwh, systeem, afgerond FROM projecten WHERE id = ?', [$pid])->fetch());
            break;

        // Bericht op de tijdlijn van een project (mail, WhatsApp, Teams, Pipedrive-notitie, telefoon, Claude). Zelfde extern_id = bijwerken.
        // {"project":"PRJ-296","extern_id":"mail:<id>","datum":"2026-11-19 14:05","bron":"mail","van":"Cristine Oostendorp","onderwerp":"..","tekst":"korte samenvatting","link":""}
        case 'project_bericht':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            $bron = in_array($d['bron'] ?? '', ['mail', 'whatsapp', 'teams', 'pipedrive', 'telefoon', 'claude', 'app'], true) ? $d['bron'] : 'claude';
            $dt = date('Y-m-d H:i:s', strtotime((string)($d['datum'] ?? 'now')) ?: time());
            $ext = clean_text($d['extern_id'] ?? '') ?: null;
            q('INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst, link, extern_id) VALUES (?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE project_id = VALUES(project_id), datum = VALUES(datum), bron = VALUES(bron), van = VALUES(van), onderwerp = VALUES(onderwerp), tekst = VALUES(tekst), link = VALUES(link)',
                [$pid, $dt, $bron, mb_substr(clean_text($d['van'] ?? ''), 0, 150), mb_substr(clean_text($d['onderwerp'] ?? ''), 0, 255),
                 trim((string)($d['tekst'] ?? '')) ?: null, mb_substr((string)($d['link'] ?? ''), 0, 500), $ext]);
            uit(['ok' => true, 'id' => (int)($ext ? q('SELECT id FROM project_berichten WHERE extern_id = ?', [$ext])->fetchColumn() : db()->lastInsertId()), 'project' => project_nummer((string)$d['project']), 'extern_id' => $ext]);
            break;

        // Mijlpaal bij een project (levering, keuring, oplevering ...). Zelfde extern_id = bijwerken, dus een verschoven datum overschrijft de oude.
        // {"project":"PRJ-251","extern_id":"levering:batterij","datum":"2026-12-06","titel":"Batterij komt binnen","soort":"levering","zeker":false,"bron":"mail","toelichting":"mail Dyness 20-11"}
        // Extra: "gedaan":true om af te vinken, "weg":true om te verwijderen. Een mijlpaal die iemand met de hand heeft afgevinkt blijft afgevinkt.
        case 'mijlpaal':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            $ext = clean_text($d['extern_id'] ?? '');
            if ($ext === '') { uit(['fout' => 'extern_id ontbreekt']); exit(1); }
            $ext = project_nummer((string)$d['project']) . ':' . $ext;
            if (!empty($d['weg'])) { q('DELETE FROM project_mijlpalen WHERE extern_id = ?', [$ext]); uit(['ok' => true, 'weg' => $ext]); break; }
            $titel = mb_substr(clean_text($d['titel'] ?? ''), 0, 200);
            $nu = q('SELECT * FROM project_mijlpalen WHERE extern_id = ?', [$ext])->fetch();
            if (!$nu && $titel === '') { uit(['fout' => 'titel ontbreekt']); exit(1); }
            $soort = isset(MIJLPAAL_SOORT[$d['soort'] ?? '']) ? $d['soort'] : ($nu['soort'] ?? 'overig');
            $bron = isset(MIJLPAAL_BRON[$d['bron'] ?? '']) && ($d['bron'] ?? '') !== 'hand' ? $d['bron'] : ($nu['bron'] ?? 'claude');
            $datum = array_key_exists('datum', $d) ? geldige_datum($d['datum']) : ($nu['datum'] ?? null);
            $zeker = array_key_exists('zeker', $d) ? ($d['zeker'] ? 1 : 0) : (int)($nu['zeker'] ?? 1);
            $toel = array_key_exists('toelichting', $d) ? mb_substr(clean_text($d['toelichting']), 0, 500) : ($nu['toelichting'] ?? '');
            if ($nu) q('UPDATE project_mijlpalen SET datum = ?, titel = ?, soort = ?, zeker = ?, toelichting = ?, bron = ? WHERE id = ?', [$datum, $titel ?: $nu['titel'], $soort, $zeker, $toel, $bron, $nu['id']]);
            else q('INSERT INTO project_mijlpalen (project_id, datum, titel, soort, zeker, toelichting, bron, door, extern_id) VALUES (?,?,?,?,?,?,?,?,?)', [$pid, $datum, $titel, $soort, $zeker, $toel, $bron, 'Claude', $ext]);
            if (array_key_exists('gedaan', $d) && $d['gedaan']) q('UPDATE project_mijlpalen SET gedaan = 1, gedaan_op = COALESCE(gedaan_op, UTC_TIMESTAMP()) WHERE extern_id = ?', [$ext]);
            uit(q('SELECT pm.id, pr.nummer project, pm.datum, pm.titel, pm.soort, pm.zeker, pm.gedaan, pm.bron, pm.extern_id FROM project_mijlpalen pm JOIN projecten pr ON pr.id = pm.project_id WHERE pm.extern_id = ?', [$ext])->fetch());
            break;

        // Mijlpalen opvragen (alleen lezen). {"project":"PRJ-251"} voor één project (ook afgevinkte), {} voor alle open mijlpalen
        case 'mijlpalen':
            if (!empty($d['project'])) uit(q('SELECT id, datum, titel, soort, zeker, gedaan, bron, door, toelichting, extern_id FROM project_mijlpalen WHERE project_id = ? ORDER BY datum IS NULL, datum, id', [pid_of_fout((string)$d['project'])])->fetchAll());
            else uit(q('SELECT pr.nummer project, pr.omschrijving naam, pm.datum, pm.titel, pm.soort, pm.zeker, pm.bron, pm.extern_id FROM project_mijlpalen pm JOIN projecten pr ON pr.id = pm.project_id WHERE pm.gedaan = 0 ORDER BY pm.datum IS NULL, pm.datum, pm.id')->fetchAll());
            break;

        // Samenvatting van een project (stand, wat speelt, wat moet nog), zoals het projectoverzicht in Pipedrive. {"project":"PRJ-296","tekst":"..."}
        case 'project_samenvatting':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            q('UPDATE projecten SET samenvatting = ?, samenvatting_op = UTC_TIMESTAMP() WHERE id = ?', [trim((string)($d['tekst'] ?? '')) ?: null, $pid]);
            uit(['ok' => true]);
            break;

        // Berichten van een project lezen. {"project":"PRJ-296","sinds":"2026-11-01"}
        case 'project_berichten':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            uit(q('SELECT datum, bron, van, onderwerp, LEFT(tekst, 300) tekst, extern_id FROM project_berichten WHERE project_id = ? AND datum >= ? ORDER BY datum DESC LIMIT 100',
                [$pid, geldige_datum($d['sinds'] ?? '') ?? '2000-01-01'])->fetchAll());
            break;

        // Iemand toevoegen die wordt ingepland maar niet inlogt (onderaannemer). {"naam":"Turgay","gebruikersnaam":"turgay"}
        case 'mens':
            $naam = clean_text($d['naam'] ?? ''); $gn = strtolower(clean_text($d['gebruikersnaam'] ?? ''));
            if ($naam === '' || $gn === '') { uit(['fout' => 'naam of gebruikersnaam ontbreekt']); exit(1); }
            if (!q('SELECT id FROM gebruikers WHERE gebruikersnaam = ?', [$gn])->fetch()) q("INSERT INTO gebruikers (naam, gebruikersnaam, wachtwoord_hash, rol, planbaar) VALUES (?,?,'','monteur',1)", [$naam, $gn]);
            uit(q('SELECT naam, gebruikersnaam, planbaar FROM gebruikers WHERE gebruikersnaam = ?', [$gn])->fetch());
            break;

        // Service-ticket aanmaken of bijwerken (zie ticket_opslaan in ops.php voor alle velden). {"klant":"..","titel":"..","urgentie":"spoed",...} of {"nummer":"WW-...","fase":"uitzoeken"}
        case 'ticket':
            uit(ticket_opslaan($d, 'Claude'));
            break;

        // Open service-tickets (alleen lezen). {} of {"alle":true} om ook opgeloste te zien
        case 'tickets':
            uit(q("SELECT p.nummer, p.omschrijving AS naam, p.klant, p.fase, p.urgentie, p.apparaat, p.afhandeling, p.telefoon, p.startdatum, p.einddatum, g.gebruikersnaam AS eigenaar, p.pipedrive_id
                   FROM projecten p LEFT JOIN gebruikers g ON g.id = p.leider_id WHERE p.soort = 'service'" . (empty($d['alle']) ? " AND p.fase <> 'opgelost'" : '') . ' ORDER BY p.startdatum, p.id')->fetchAll());
            break;

        // Overstap van Pipedrive Projects. {"bron":"api"} haalt alles zelf op (pipedrive_token in de config nodig);
        // of geef de gegevens mee: {"projecten":[...zoals de Pipedrive-API...],"notities":{"217":[...]},"taken":{"217":[...]}}.
        // "proef":true laat zien wat er zou gebeuren zonder iets op te slaan. Mag vaker draaien: niets wordt dubbel.
        case 'pipedrive_import':
            $data = ($d['bron'] ?? '') === 'api' ? pd_alles_ophalen() : $d;
            $proef = !empty($d['proef']);
            db()->beginTransaction();
            $res = [];
            foreach ($data['projecten'] ?? [] as $p) {
                $id = (string)$p['id'];
                $res[] = pd_project_importeren($p, $data['notities'][$id] ?? [], $data['taken'][$id] ?? []);
            }
            $proef ? db()->rollBack() : db()->commit();
            $tel = ['nieuw' => 0, 'bijgewerkt' => 0, 'overgeslagen' => 0, 'notities' => 0, 'taken' => 0];
            foreach ($res as $r) {
                if (isset($r['overgeslagen'])) { $tel['overgeslagen']++; continue; }
                $tel[$r['nieuw'] ? 'nieuw' : 'bijgewerkt']++; $tel['notities'] += $r['notities']; $tel['taken'] += $r['taken'];
            }
            uit(['proef' => $proef, 'totaal' => $tel, 'projecten' => $res]);
            break;

        // Afspraak met een vast tijdstip (vervangt Pipedrive-activiteiten). Zelfde extern_id = bijwerken. Zie afspraak_opslaan in ops.php.
        // {"titel":"Renze terugbellen","start":"2026-12-03 14:30","eind":"2026-12-03 14:45","wie":"ruben","project":"PRJ-251","locatie":"","notitie":"","bron":"mail 19-11","extern_id":"mail:<id>"}
        case 'afspraak':
            uit(afspraak_opslaan($d, 'Claude'));
            break;

        // Afspraken lezen. {"wie":"ruben","van":"2026-12-01","tot":"2026-12-14"} (alles optioneel; standaard vandaag tot 14 dagen verder)
        case 'afspraken':
            $van = geldige_datum($d['van'] ?? '') ?? vandaag(); $tot = geldige_datum($d['tot'] ?? '') ?? dag_plus($van, 14);
            $wie = ($d['wie'] ?? '') !== '' ? gebruiker_zoek((string)$d['wie']) : null;
            uit(q("SELECT a.id, a.titel, a.start, a.eind, a.hele_dag, a.locatie, g.gebruikersnaam AS wie, p.nummer AS project, a.extern_id FROM afspraken a
                   LEFT JOIN gebruikers g ON g.id = a.gebruiker_id LEFT JOIN projecten p ON p.id = a.project_id
                   WHERE a.geannuleerd = 0 AND a.start >= ? AND a.start < ? + INTERVAL 1 DAY" . ($wie ? ' AND a.gebruiker_id = ' . (int)$wie : '') . ' ORDER BY a.start', [$van, $tot])->fetchAll());
            break;

        // Klant opzoeken met al zijn projecten en tickets (alleen lezen). {"q":"Roubos"} of {"telefoon":"0651223809"}
        case 'klant':
            $w = []; $par = [];
            if (!empty($d['telefoon'])) { $w[] = 'telefoon_kaal = ?'; $par[] = telefoon_kaal((string)$d['telefoon']); }
            if (!empty($d['q'])) { $w[] = '(naam LIKE ? OR email LIKE ? OR adres LIKE ? OR bedrijf LIKE ?)'; array_push($par, ...array_fill(0, 4, '%' . clean_text($d['q']) . '%')); }
            if (!$w) { uit(['fout' => 'geef q of telefoon']); exit(1); }
            $ks = q('SELECT id, naam, bedrijf, telefoon, email, adres, installatie FROM klanten WHERE ' . implode(' OR ', $w) . ' LIMIT 10', $par)->fetchAll();
            foreach ($ks as &$k) {
                $k['projecten'] = q('SELECT nummer, soort, omschrijving AS naam, fase, afgerond FROM projecten WHERE klant_id = ? ORDER BY id DESC', [$k['id']])->fetchAll();
                $k['locaties'] = q('SELECT id, naam, adres, contact FROM locaties WHERE klant_id = ? ORDER BY id', [$k['id']])->fetchAll();
                $k['installaties'] = q('SELECT i.id, i.locatie_id, i.soort, i.merk, i.type, i.serienummer, i.monitoring, i.monitoring_id, i.garantie_tot, i.status FROM installaties i JOIN locaties l ON l.id = i.locatie_id WHERE l.klant_id = ? ORDER BY i.id', [$k['id']])->fetchAll();
                $k['klant_id'] = $k['id']; unset($k['id']);
            }
            uit($ks);
            break;

        // Opleverdossier lezen (alleen lezen). {"project":"PRJ-296"}
        case 'oplevering':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            $o = q('SELECT serienummers, netwerk, ems, kabel, materiaal, opmerkingen, check_inbedrijf, check_monitoring, check_uitleg, check_keuring, afgerond_op, bijgewerkt_door, bijgewerkt FROM opleveringen WHERE project_id = ?', [$pid])->fetch();
            uit($o ?: ['leeg' => true]);
            break;

        // Bespreekpunt voor het overleg Ruben en Berend. Zie bespreekpunt_opslaan in ops.php.
        case 'bespreekpunt':
            uit(bespreekpunt_opslaan($d, 'Claude'));
            break;

        // Bespreekpunten lezen. {} = open punten; {"besproken_sinds":"2026-11-20"} toont ook wat sindsdien besproken is (met besluit)
        case 'bespreekpunten':
            $sinds = geldige_datum($d['besproken_sinds'] ?? '');
            uit(q("SELECT b.id, b.titel, b.toelichting, p.nummer AS project, b.prioriteit, b.status, b.besluit, b.besproken_op, b.extern_id FROM bespreekpunten b LEFT JOIN projecten p ON p.id = b.project_id
                   WHERE b.status = 'open'" . ($sinds ? ' OR (b.status = \'besproken\' AND b.besproken_op >= ?)' : '') . " ORDER BY FIELD(b.prioriteit, 'hoog', 'normaal', 'laag'), b.id", $sinds ? [$sinds] : [])->fetchAll());
            break;

        // --- WhatsApp ---
        // Van Rubens Mac (whatsapp_naar_app.py): {"chats":[{"jid":"..","naam":"..","proef":"3 korte regels"}], "berichten":[{"bron_id":123,"jid":"..","tijd":"2026-11-26 17:05","van":"..","ik":0,"tekst":".."}]}
        // Chats die nog niet ingedeeld zijn komen als 'nieuw' binnen (alleen met proefregels). Berichten worden alleen bewaard voor chats die zakelijk zijn.
        case 'whatsapp_in':
            $nc = 0; $nb = 0;
            foreach (array_slice((array)($d['chats'] ?? []), 0, 500) as $c) {
                $jid = mb_substr(trim((string)($c['jid'] ?? '')), 0, 100);
                if ($jid === '') continue;
                // "soort" (zakelijk/prive) neemt de oude indeling van de Mac over, alleen voor chats die hier nog 'nieuw' zijn
                $soort = in_array($c['soort'] ?? '', ['zakelijk', 'prive'], true) ? $c['soort'] : 'nieuw';
                $proef = $soort === 'nieuw' ? mb_substr((string)($c['proef'] ?? ''), 0, 1000) : null;
                $nc += q("INSERT INTO wa_chats (jid, naam, soort, proef, ingedeeld_door) VALUES (?, ?, ?, ?, IF(? = 'nieuw', '', 'Mac'))
                          ON DUPLICATE KEY UPDATE naam = VALUES(naam), soort = IF(soort = 'nieuw' AND VALUES(soort) <> 'nieuw', VALUES(soort), soort),
                          proef = IF(soort = 'nieuw', VALUES(proef), NULL)",
                    [$jid, mb_substr(clean_text((string)($c['naam'] ?? '')), 0, 150), $soort, $proef, $soort])->rowCount() === 1 ? 1 : 0;
            }
            $zak = array_flip(q("SELECT jid FROM wa_chats WHERE soort = 'zakelijk'")->fetchAll(PDO::FETCH_COLUMN));
            foreach (array_slice((array)($d['berichten'] ?? []), 0, 5000) as $b) {
                $jid = (string)($b['jid'] ?? ''); $tijd = (string)($b['tijd'] ?? '');
                if (!isset($zak[$jid]) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $tijd) || (int)($b['bron_id'] ?? 0) <= 0) continue;
                $nb += q('INSERT IGNORE INTO wa_berichten (bron_id, jid, tijd, van, ik, tekst) VALUES (?, ?, ?, ?, ?, ?)',
                    [(int)$b['bron_id'], $jid, $tijd, mb_substr((string)($b['van'] ?? ''), 0, 150), !empty($b['ik']) ? 1 : 0, mb_substr((string)($b['tekst'] ?? ''), 0, 2000)])->rowCount();
            }
            q('DELETE FROM wa_berichten WHERE tijd < NOW() - INTERVAL 45 DAY');
            uit(['nieuwe_chats' => $nc, 'nieuwe_berichten' => $nb]);
            break;

        // Indeling van de chats (de Mac haalt deze op om te weten wat hij mag sturen). {} = alles; {"soort":"nieuw"} = alleen nog in te delen, met proefregels
        case 'whatsapp_chats':
            $s = in_array($d['soort'] ?? '', ['nieuw', 'zakelijk', 'prive'], true) ? $d['soort'] : null;
            uit(q('SELECT jid, naam, soort' . ($s === 'nieuw' ? ', proef' : '') . ' FROM wa_chats' . ($s ? ' WHERE soort = ?' : '') . ' ORDER BY naam', $s ? [$s] : [])->fetchAll());
            break;

        // Chat indelen. {"jid":"..","soort":"zakelijk|prive"}. Bij prive gaan de proefregels weg.
        case 'whatsapp_indelen':
            if (!in_array($d['soort'] ?? '', ['zakelijk', 'prive'], true)) { uit(['fout' => 'soort moet zakelijk of prive zijn']); exit(1); }
            $n = q("UPDATE wa_chats SET soort = ?, proef = NULL, ingedeeld_door = 'Claude' WHERE jid = ?", [$d['soort'], (string)($d['jid'] ?? '')])->rowCount();
            if ($d['soort'] === 'prive') q('DELETE FROM wa_berichten WHERE jid = ?', [(string)($d['jid'] ?? '')]);
            uit(['jid' => (string)($d['jid'] ?? ''), 'soort' => $d['soort'], 'gevonden' => $n > 0 || (bool)q('SELECT 1 FROM wa_chats WHERE jid = ?', [(string)($d['jid'] ?? '')])->fetch()]);
            break;

        // Zakelijke berichten lezen, per chat als tekst (zelfde vorm als zakelijk.txt). {"sinds":"2026-11-26 07:00"} (Nederlandse tijd) of {"ontvangen_sinds": ..} voor wat sinds de vorige run binnenkwam.
        // Geeft ook "laatste_ontvangst": wanneer de Mac voor het laatst iets stuurde (staat de Mac uit, dan loopt dit achter).
        case 'whatsapp':
            $kol = !empty($d['ontvangen_sinds']) ? 'b.ontvangen' : 'b.tijd';
            $sinds = (string)($d['ontvangen_sinds'] ?? $d['sinds'] ?? date('Y-m-d H:i', time() - 86400));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $sinds)) { uit(['fout' => 'sinds moet JJJJ-MM-DD UU:MM zijn']); exit(1); }
            $rijen = q("SELECT c.naam, b.tijd, b.van, b.tekst FROM wa_berichten b JOIN wa_chats c ON c.jid = b.jid WHERE c.soort = 'zakelijk' AND $kol >= ? ORDER BY c.naam, b.tijd LIMIT 3000", [$sinds])->fetchAll();
            $per = [];
            foreach ($rijen as $r) $per[$r['naam']][] = date('d-m H:i', strtotime($r['tijd'])) . ' ' . $r['van'] . ': ' . $r['tekst'];
            $tekst = implode("\n\n", array_map(fn($n, $rr) => "=== $n\n" . implode("\n", $rr), array_keys($per), $per));
            uit(['chats' => count($per), 'berichten' => count($rijen), 'laatste_ontvangst' => q('SELECT MAX(ontvangen) FROM wa_berichten')->fetchColumn(),
                'nog_in_te_delen' => (int)q("SELECT COUNT(*) FROM wa_chats WHERE soort = 'nieuw'")->fetchColumn(), 'tekst' => $tekst]);
            break;

        // Voorstel om een taak op te schonen (opschonen.php; kantoor keurt goed of af). Verandert zelf niets aan de taak.
        // {"taak":12,"actie":"klaar|vervalt|samenvoegen|eigenaar|deadline|project","reden":"..","eigenaar":"julian","in":29,"deadline":"2026-12-05","project":"PRJ-296"}
        case 'taak_opschoonvoorstel':
            $t = ctype_digit((string)($d['taak'] ?? '')) ? q('SELECT * FROM taken WHERE id = ?', [(int)$d['taak']])->fetch() : q('SELECT * FROM taken WHERE extern_id = ?', [clean_text((string)($d['taak'] ?? ''))])->fetch();
            if (!$t || $t['status'] === 'klaar') { uit(['fout' => 'taak niet gevonden of al klaar']); exit(1); }
            $actie = (string)($d['actie'] ?? '');
            if (!in_array($actie, ['klaar', 'vervalt', 'samenvoegen', 'eigenaar', 'deadline', 'project'], true)) { uit(['fout' => 'onbekende actie']); exit(1); }
            $reden = mb_substr(clean_text($d['reden'] ?? ''), 0, 500);
            if ($reden === '') { uit(['fout' => 'reden ontbreekt']); exit(1); }
            $eigV = $actie === 'eigenaar' ? gebruiker_zoek((string)($d['eigenaar'] ?? '')) : null;
            $inV = $actie === 'samenvoegen' ? (int)(q('SELECT id FROM taken WHERE id = ? AND status <> \'klaar\'', [(int)($d['in'] ?? 0)])->fetchColumn() ?: 0) : 0;
            $dlV = $actie === 'deadline' ? geldige_datum($d['deadline'] ?? null) : null;
            $prV = $actie === 'project' && ($d['project'] ?? '') !== '' ? (q('SELECT id FROM projecten WHERE nummer = ?', [project_nummer((string)$d['project'])])->fetchColumn() ?: null) : null;
            if (($actie === 'eigenaar' && !$eigV) || ($actie === 'samenvoegen' && (!$inV || $inV === (int)$t['id'])) || ($actie === 'deadline' && !$dlV) || ($actie === 'project' && !$prV)) { uit(['fout' => 'gegevens voor deze actie ontbreken of kloppen niet']); exit(1); }
            // Eén open voorstel per taak: een nieuw voorstel vervangt het oude
            q("DELETE FROM taak_voorstellen WHERE taak_id = ? AND status = 'open'", [$t['id']]);
            q('INSERT INTO taak_voorstellen (taak_id, actie, eigenaar_id, in_taak_id, deadline, project_id, reden) VALUES (?,?,?,?,?,?,?)', [$t['id'], $actie, $eigV, $inV ?: null, $dlV, $prV, $reden]);
            uit(['id' => (int)db()->lastInsertId(), 'taak' => (int)$t['id'], 'actie' => $actie, 'status' => 'open']);
            break;

        // Stand van de opschoonvoorstellen. {} = open; {"sinds":"2026-11-28"} ook wat sindsdien besloten is
        case 'taak_opschoonvoorstellen':
            $sinds = geldige_datum($d['sinds'] ?? '');
            uit(q("SELECT v.id, v.taak_id, t.titel, v.actie, v.reden, v.status, g.naam AS besloten_door, v.besloten_op FROM taak_voorstellen v JOIN taken t ON t.id = v.taak_id LEFT JOIN gebruikers g ON g.id = v.besloten_door
                   WHERE v.status = 'open'" . ($sinds ? ' OR v.besloten_op >= ?' : '') . ' ORDER BY v.id', $sinds ? [$sinds] : [])->fetchAll());
            break;

        // Opleverdossier als concept na "Werk klaar": vult alleen lege velden; de monteur controleert en vinkt zelf af.
        // {"project":"PRJ-296","serienummers":"..","netwerk":"..","ems":"..","kabel":"..","materiaal":"..","opmerkingen":".."}
        case 'oplevering_concept':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            $o = q('SELECT * FROM opleveringen WHERE project_id = ?', [$pid])->fetch();
            if ($o && $o['afgerond_op']) { uit(['fout' => 'opleverdossier is al afgerond']); exit(1); }
            if (!$o) { q('INSERT INTO opleveringen (project_id) VALUES (?)', [$pid]); $o = q('SELECT * FROM opleveringen WHERE project_id = ?', [$pid])->fetch(); }
            $gevuld = [];
            foreach (['serienummers' => 10000, 'netwerk' => 10000, 'ems' => 255, 'kabel' => 10000, 'materiaal' => 10000, 'opmerkingen' => 10000] as $k => $max) {
                $v = trim((string)($d[$k] ?? ''));
                if ($v !== '' && trim((string)($o[$k] ?? '')) === '') { q("UPDATE opleveringen SET `$k` = ? WHERE project_id = ?", [mb_substr($v, 0, $max), $pid]); $gevuld[] = $k; }
            }
            if ($gevuld) {
                q("UPDATE opleveringen SET concept_door = 'Claude', bijgewerkt_door = 'Claude' WHERE project_id = ?", [$pid]);
                $pr = q('SELECT nummer, omschrijving, leider_id FROM projecten WHERE id = ?', [$pid])->fetch();
                seintje_project($pid, 'Opleverdossier klaargezet door Claude, graag controleren: ' . mb_substr(project_naam($pr), 0, 120), 'oplevering.php?nr=' . rawurlencode($pr['nummer']));
            }
            uit(['ok' => true, 'gevuld' => $gevuld]);
            break;

        // Vraag aan Claude vanuit de app beantwoorden: komt op de tijdlijn van het project en de vrager krijgt een seintje.
        // {"id":7,"antwoord":"..."}  (id van de notitie met soort "vraag")
        case 'vraag_antwoord':
            $n = q("SELECT n.*, pr.nummer FROM notities n LEFT JOIN projecten pr ON pr.id = n.project_id WHERE n.id = ? AND n.soort = 'vraag'", [(int)($d['id'] ?? 0)])->fetch();
            $antw = trim((string)($d['antwoord'] ?? ''));
            if (!$n) { uit(['fout' => 'vraag niet gevonden']); exit(1); }
            if ($antw === '') { uit(['fout' => 'antwoord ontbreekt']); exit(1); }
            $vrager = q('SELECT naam FROM gebruikers WHERE id = ?', [$n['gebruiker_id']])->fetchColumn();
            if ($n['project_id']) q("INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst, extern_id) VALUES (?, ?, 'claude', 'Claude', ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE tekst = VALUES(tekst)", [$n['project_id'], date('Y-m-d H:i:s'), mb_substr('Antwoord aan ' . $vrager . ': ' . clean_text($n['tekst']), 0, 255), $antw, 'vraag:' . $n['id']]);
            q("UPDATE notities SET status = 'verwerkt', verwerking = ?, verwerkt_op = UTC_TIMESTAMP() WHERE id = ?", [mb_substr($antw, 0, 4000), $n['id']]);
            seintje((int)$n['gebruiker_id'], 'Claude antwoordde op je vraag' . ($n['nummer'] ? ' over ' . $n['nummer'] : '') . ': ' . mb_substr(clean_text($antw), 0, 200), $n['nummer'] ? 'project.php?nr=' . rawurlencode($n['nummer']) . '#tijdlijn' : 'dag.php');
            uit(['id' => (int)$n['id'], 'status' => 'verwerkt']);
            break;

        // Tweede inlogstap uitzetten voor iemand die zijn telefoon kwijt is. Alleen via SSH (beheer), niet via api_brein.php.
        // {"gebruiker":"ruben"}
        case 'tweestaps_uit':
            $g = gebruiker_zoek((string)($d['gebruiker'] ?? ''));
            if (!$g) { uit(['fout' => 'gebruiker onbekend']); exit(1); }
            q('UPDATE gebruikers SET totp_geheim = NULL, totp_sinds = NULL WHERE id = ?', [$g]);
            uit(['ok' => true, 'gebruiker' => (string)$d['gebruiker'], 'melding' => 'Bij de volgende keer inloggen stelt deze persoon de code-app opnieuw in.']);
            break;

        // --- Stand van het brein ---
        // Een lijst in de stand aanvullen zonder de rest te overschrijven (bijv. verwerkte mail-id's). Houdt de laatste "max" (standaard 200).
        // {"sleutel":"groot","lijst":"mail_opdrachten_verwerkt","ids":["<id>", ...],"max":200}
        case 'brein_lijst':
            $k = mb_substr((string)($d['sleutel'] ?? ''), 0, 100); $lijst = (string)($d['lijst'] ?? '');
            if ($k === '' || $lijst === '' || $k === 'slot') { uit(['fout' => 'sleutel of lijst ontbreekt']); exit(1); }
            $pdo = db(); $pdo->beginTransaction();
            $r = q('SELECT waarde FROM brein_stand WHERE sleutel = ? FOR UPDATE', [$k])->fetch();
            $w = $r ? (json_decode($r['waarde'], true) ?: []) : [];
            if (!is_array($w)) $w = [];
            $oud = (array)($w[$lijst] ?? []);
            $nieuw = array_values(array_unique(array_merge($oud, array_map('strval', (array)($d['ids'] ?? [])))));
            $w[$lijst] = array_slice($nieuw, -max(1, min(2000, (int)($d['max'] ?? 200))));
            q('INSERT INTO brein_stand (sleutel, waarde) VALUES (?, ?) ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)', [$k, json_encode($w, JSON_UNESCAPED_UNICODE)]);
            $pdo->commit();
            uit(['sleutel' => $k, 'lijst' => $lijst, 'aantal' => count($w[$lijst])]);
            break;

        // Logboek van Claude lezen (wat de runs veranderden en wat kantoor ongedaan maakte). {"sinds":"2026-11-28"}
        case 'claude_log':
            uit(q('SELECT l.id, l.aangemaakt, l.run, l.opdracht, l.wat, l.ongedaan_op, g.naam AS ongedaan_door FROM claude_log l LEFT JOIN gebruikers g ON g.id = l.ongedaan_door WHERE l.aangemaakt >= ? ORDER BY l.id LIMIT 1000',
                [geldige_datum($d['sinds'] ?? '') ?? date('Y-m-d', time() - 86400)])->fetchAll());
            break;

        // --- Stand van het brein ---
        // Slot: {"wie":"cloud-licht","minuten":50} neemt het slot als het vrij of verlopen is. Antwoord {"ok":true} of {"ok":false,"bezet_door":..,"tot":..}.
        case 'brein_slot':
            $wie = mb_substr(clean_text((string)($d['wie'] ?? '')), 0, 50);
            $min = max(5, min(240, (int)($d['minuten'] ?? 60)));
            if ($wie === '') { uit(['fout' => 'wie ontbreekt']); exit(1); }
            $pdo = db(); $pdo->beginTransaction();
            $r = q("SELECT waarde FROM brein_stand WHERE sleutel = 'slot' FOR UPDATE")->fetch();
            $nu = q('SELECT UTC_TIMESTAMP()')->fetchColumn();
            $oud = $r ? json_decode($r['waarde'], true) : null;
            if ($oud && ($oud['tot'] ?? '') > $nu && ($oud['wie'] ?? '') !== $wie) { $pdo->commit(); uit(['ok' => false, 'bezet_door' => $oud['wie'], 'sinds' => $oud['sinds'] ?? '', 'tot' => $oud['tot']]); break; }
            $nieuw = ['wie' => $wie, 'sinds' => $nu];
            $nieuw['tot'] = gmdate('Y-m-d H:i:s', strtotime($nu . ' UTC') + 60 * $min);
            q("INSERT INTO brein_stand (sleutel, waarde) VALUES ('slot', ?) ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)", [json_encode($nieuw)]);
            $pdo->commit();
            uit(['ok' => true] + $nieuw);
            break;

        // Slot vrijgeven aan het eind van een run. {"wie":"cloud-licht"} (alleen als dat slot van deze run is)
        case 'brein_slot_vrij':
            $r = q("SELECT waarde FROM brein_stand WHERE sleutel = 'slot'")->fetch();
            $oud = $r ? json_decode($r['waarde'], true) : null;
            if ($oud && ($oud['wie'] ?? '') === (string)($d['wie'] ?? '')) q("DELETE FROM brein_stand WHERE sleutel = 'slot'");
            uit(['ok' => true]);
            break;

        // ---------- Ronde 2: locaties, installaties, werkbon, begroting, termijnen, facturen ----------
        // Locatie aanmaken of bijwerken. {"klant_id":12,"naam":"Loods 2","adres":"..","contact":"..","telefoon":".."} of {"id":3,...} of {"project":"PRJ-296"} (koppelt en geeft de locatie)
        case 'locatie':
            if (!empty($d['project']) && empty($d['id'])) {
                $pid = pid_of_fout((string)$d['project']);
                $lid = project_koppel_locatie($pid);
                uit($lid ? q('SELECT * FROM locaties WHERE id = ?', [$lid])->fetch() : ['fout' => 'project heeft geen klant en geen adres']);
                break;
            }
            $velden = ['naam' => 150, 'adres' => 255, 'contact' => 255, 'telefoon' => 50];
            if (!empty($d['id'])) {
                $l = q('SELECT * FROM locaties WHERE id = ?', [(int)$d['id']])->fetch();
                if (!$l) { uit(['fout' => 'locatie niet gevonden']); exit(1); }
                foreach ($velden as $k => $max) if (clean_text($d[$k] ?? '') !== '' && ($l[$k] === '' || !empty($d['overschrijf']))) q("UPDATE locaties SET `$k` = ? WHERE id = ?", [mb_substr(clean_text($d[$k]), 0, $max), $l['id']]);
                uit(q('SELECT * FROM locaties WHERE id = ?', [$l['id']])->fetch());
                break;
            }
            if (empty($d['klant_id']) || !q('SELECT 1 FROM klanten WHERE id = ?', [(int)$d['klant_id']])->fetch()) { uit(['fout' => 'klant_id ontbreekt of bestaat niet (zoek met vapp.py klant)']); exit(1); }
            $lid = locatie_zoek_of_maak((int)$d['klant_id'], (string)($d['adres'] ?? ''), (string)($d['naam'] ?? ''));
            foreach ($velden as $k => $max) if (clean_text($d[$k] ?? '') !== '') q("UPDATE locaties SET `$k` = ? WHERE id = ? AND `$k` = ''", [mb_substr(clean_text($d[$k]), 0, $max), $lid]);
            uit(q('SELECT * FROM locaties WHERE id = ?', [$lid])->fetch());
            break;

        // Installatie in het register. Nieuw: {"project":"PRJ-296","soort":"batterij","merk":"Dyness","type":"..","serienummer":"..","monitoring":"Teleport","monitoring_id":"..","garantie_tot":"2036-12-01"}
        // Bestaande (id of zelfde serienummer): vult alleen lege velden, tenzij "overschrijf": true. Nooit wachtwoorden.
        case 'installatie':
            if (preg_match('/\b(wachtwoord|password|pass)\b/i', (string)($d['notitie'] ?? ''))) { uit(['fout' => 'geen wachtwoorden in het register']); exit(1); }
            $r = installatie_opslaan($d, 'Claude', empty($d['overschrijf']));
            if (isset($r['fout'])) { uit($r); exit(1); }
            uit(['id' => $r['id'], 'nieuw' => $r['nieuw'], 'installatie' => q('SELECT * FROM installaties WHERE id = ?', [$r['id']])->fetch()]);
            break;

        // Installaties zoeken. {"project":"PRJ-296"} of {"klant_id":12} of {"q":"serienummer, monitoring-id, merk of type"}
        case 'installaties':
            if (!empty($d['project'])) { $pr = q('SELECT * FROM projecten WHERE id = ?', [pid_of_fout((string)$d['project'])])->fetch(); uit(installaties_van_project($pr)); break; }
            if (!empty($d['klant_id'])) { uit(q('SELECT i.* FROM installaties i JOIN locaties l ON l.id = i.locatie_id WHERE l.klant_id = ? ORDER BY i.id', [(int)$d['klant_id']])->fetchAll()); break; }
            if (clean_text($d['q'] ?? '') === '') { uit(['fout' => 'geef project, klant_id of q']); exit(1); }
            $zoek = '%' . clean_text($d['q']) . '%';
            uit(q("SELECT i.*, l.adres, k.naam klant, k.id klant_id FROM installaties i LEFT JOIN locaties l ON l.id = i.locatie_id LEFT JOIN klanten k ON k.id = l.klant_id
                   WHERE i.serienummer LIKE ? OR i.monitoring_id LIKE ? OR i.merk LIKE ? OR i.type LIKE ? ORDER BY i.id DESC LIMIT 25", [$zoek, $zoek, $zoek, $zoek])->fetchAll());
            break;

        // Ticket aan een installatie koppelen. {"ticket":"WW-0123","installatie":5}
        case 'ticket_installatie':
            $pid = pid_of_fout((string)($d['ticket'] ?? ''));
            $iid = (int)($d['installatie'] ?? 0);
            if (!q('SELECT 1 FROM installaties WHERE id = ?', [$iid])->fetch()) { uit(['fout' => 'installatie niet gevonden']); exit(1); }
            q('UPDATE projecten SET installatie_id = ? WHERE id = ?', [$iid, $pid]);
            $c = ticket_reactietijd($pid);
            uit(['ok' => true, 'contract' => $c ? contract_naam($c) : null]);
            break;

        // Onderhoudscontract vastleggen of bijwerken (ronde 3). Alleen wat in een mail, offerte of notitie staat; nooit zelf verzinnen.
        // {"klant_id":12,"locatie_id":30,"omschrijving":"Onderhoud batterij","soort":"onderhoud","startdatum":"2026-01-01","interval_maanden":12,"reactietijd_uren":24,"prijs_jaar":450}
        // Bijwerken: {"id":3, ...}
        case 'contract':
            $r = contract_opslaan($d, 'Claude');
            if (isset($r['fout'])) { uit($r); exit(1); }
            uit(['id' => $r['id'], 'nieuw' => $r['nieuw'], 'contract' => q('SELECT * FROM contracten WHERE id = ?', [$r['id']])->fetch()]);
            break;

        // Contracten bekijken. {} = alle actieve; {"klant_id":12}; {"onderhoud":true} maakt ook de onderhoudstickets die binnen een maand vallen
        case 'contracten':
            $nieuw = !empty($d['onderhoud']) ? contracten_onderhoud_maken('Claude') : [];
            $w = !empty($d['klant_id']) ? ['c.klant_id = ?', [(int)$d['klant_id']]] : ["c.status = 'actief'", []];
            uit(['onderhoudstickets_gemaakt' => $nieuw, 'contracten' => q("SELECT c.*, k.naam klant, l.adres FROM contracten c LEFT JOIN klanten k ON k.id = c.klant_id LEFT JOIN locaties l ON l.id = c.locatie_id WHERE {$w[0]} ORDER BY c.volgende_onderhoud IS NULL, c.volgende_onderhoud LIMIT 200", $w[1])->fetchAll(),
                'reactietijd_verstreken' => q("SELECT nummer, klant, omschrijving, reactie_voor FROM projecten WHERE soort = 'service' AND fase = 'melding' AND gereageerd_op IS NULL AND reactie_voor IS NOT NULL AND reactie_voor < UTC_TIMESTAMP() LIMIT 50")->fetchAll()]);
            break;

        // Concept-bericht aan de klant (ronde 3). Kantoor leest het na en verstuurt het zelf; Claude verstuurt nooit iets.
        // {"project":"WW-…","soort":"ingepland|dag_ervoor|opgelost|oplevering|vrij","onderwerp":"..","tekst":"..","sleutel":"dag_ervoor:WW-…:2026-12-01","aan":"optioneel"}
        // Zonder onderwerp/tekst: de standaardtekst van de app ({"datum":"2026-12-01"} voor de afspraakdatum).
        case 'klantbericht':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            $pr = q('SELECT * FROM projecten WHERE id = ?', [$pid])->fetch();
            [$ond, $tekst] = klantbericht_sjabloon($pr, (string)($d['soort'] ?? 'vrij'), geldige_datum($d['datum'] ?? null));
            $r = klantbericht_concept($pid, (string)($d['soort'] ?? 'vrij'), (string)($d['onderwerp'] ?? '') ?: $ond, (string)($d['tekst'] ?? '') ?: $tekst, 'Claude', $d['sleutel'] ?? null, $d['aan'] ?? null);
            if (isset($r['fout'])) { uit($r); exit(1); }
            uit($r + ['aan' => q('SELECT aan FROM klantberichten WHERE id = ?', [$r['id']])->fetchColumn()]);
            break;

        // Open concepten en wat recent is verstuurd. {"project":"…"} optioneel
        case 'klantberichten':
            $pid = !empty($d['project']) ? pid_of_fout((string)$d['project']) : null;
            uit(['concepten' => klantberichten_open($pid),
                'verstuurd_14_dagen' => q("SELECT b.id, p.nummer, b.soort, b.sleutel, b.onderwerp, b.verstuurd_op FROM klantberichten b JOIN projecten p ON p.id = b.project_id WHERE b.status = 'verstuurd' AND b.verstuurd_op >= UTC_TIMESTAMP() - INTERVAL 14 DAY" . ($pid ? ' AND b.project_id = ' . $pid : '') . ' ORDER BY b.verstuurd_op DESC LIMIT 50')->fetchAll()]);
            break;

        // Werkbon als concept (na "Werk klaar"). Vult alleen lege velden van de concept-werkbon van die dag; een getekende werkbon blijft onaangeroerd.
        // {"project":"PRJ-296","datum":"2026-11-30","werk":"..","materiaal":"..","opmerkingen":".."}
        case 'werkbon_concept':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            $datum = geldige_datum($d['datum'] ?? '') ?? vandaag();
            if (q("SELECT 1 FROM werkbonnen WHERE project_id = ? AND datum = ? AND status = 'getekend'", [$pid, $datum])->fetch() && empty($d['tweede'])) { uit(['fout' => 'werkbon van die dag is al getekend']); exit(1); }
            $wid = q("SELECT id FROM werkbonnen WHERE project_id = ? AND datum = ? AND status = 'concept' ORDER BY id DESC LIMIT 1", [$pid, $datum])->fetchColumn();
            $wid = $wid ? (int)$wid : werkbon_nieuw($pid, $datum, ['id' => null, 'naam' => ''], 'Claude');
            $w = q('SELECT * FROM werkbonnen WHERE id = ?', [$wid])->fetch();
            $gevuld = [];
            foreach (['werk', 'materiaal', 'opmerkingen'] as $k) {
                $v = trim((string)($d[$k] ?? ''));
                if ($v !== '' && trim((string)$w[$k]) === '') { q("UPDATE werkbonnen SET `$k` = ? WHERE id = ?", [mb_substr($v, 0, 10000), $wid]); $gevuld[] = $k; }
            }
            if ($gevuld) {
                q("UPDATE werkbonnen SET concept_door = 'Claude', bijgewerkt_door = 'Claude' WHERE id = ?", [$wid]);
                $pr = q('SELECT nummer, omschrijving FROM projecten WHERE id = ?', [$pid])->fetch();
                seintje_project($pid, 'Werkbon klaargezet door Claude, graag nakijken en laten tekenen: ' . $pr['nummer'], 'werkbon.php?id=' . $wid);
            }
            uit(['id' => $wid, 'gevuld' => $gevuld]);
            break;

        // Werkbonnen lezen. {"project":"PRJ-296"} of {"sinds":"2026-11-25"}
        case 'werkbonnen':
            $w = []; $p = [];
            if (!empty($d['project'])) { $w[] = 'w.project_id = ?'; $p[] = pid_of_fout((string)$d['project']); }
            if ($s = geldige_datum($d['sinds'] ?? '')) { $w[] = 'w.datum >= ?'; $p[] = $s; }
            if (!$w) $w[] = 'w.datum >= CURDATE() - INTERVAL 14 DAY';
            uit(q('SELECT w.id, pr.nummer, w.datum, w.status, w.monteurs, w.werk, w.materiaal, w.uren, w.opmerkingen, w.klant_naam, w.getekend_op, w.concept_door
                   FROM werkbonnen w JOIN projecten pr ON pr.id = w.project_id WHERE ' . implode(' AND ', $w) . ' ORDER BY w.datum DESC, w.id DESC LIMIT 100', $p)->fetchAll());
            break;

        // Begroting uit de offerte (bij een gewonnen deal). Bedragen excl. btw.
        // {"project":"PRJ-301","bron":"Offorte offerte 2026-123","vervang":true,"regels":[{"soort":"materiaal|arbeid|overig","omschrijving":"..","aantal":1,"eenheid":"stuks","prijs":12500}]}
        case 'begroting':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            if (!array_key_exists('regels', $d)) { uit(['regels' => begroting_regels($pid), 'totaal' => begroting_totaal($pid), 'termijnen' => termijnen_van($pid)]); break; }
            if (!is_array($d['regels']) || !$d['regels']) { uit(['fout' => 'regels ontbreken']); exit(1); }
            if (empty($d['vervang']) && q('SELECT 1 FROM begroting WHERE project_id = ?', [$pid])->fetch()) { uit(['fout' => 'er staat al een begroting; stuur "vervang": true om hem te vervangen']); exit(1); }
            $n = begroting_opslaan($pid, $d['regels'], 'Claude: ' . clean_text($d['bron'] ?? ''), true);
            q("INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst) VALUES (?, ?, 'claude', 'Claude', ?, ?)",
                [$pid, date('Y-m-d H:i:s'), 'Begroting ingevuld (' . $n . ' regels)', 'Bron: ' . mb_substr(clean_text($d['bron'] ?? 'offerte'), 0, 200) . '. Totaal ' . fmt_eur(begroting_totaal($pid)) . ' excl. btw.']);
            uit(['regels' => $n, 'totaal' => begroting_totaal($pid)]);
            break;

        // Betaaltermijnen. {"project":"PRJ-301","termijnen":[{"omschrijving":"Bij opdracht","percentage":30,"moment":"opdracht"},{"omschrijving":"Bij levering","percentage":60,"moment":"levering"},{"omschrijving":"Bij oplevering","percentage":10,"moment":"oplevering"}]}
        // moment: opdracht, levering, installatie, oplevering of handmatig. Een vast bedrag mag ook ("bedrag").
        // "controleren": true = moment is al voorbij bij een lopend project; misschien al buiten de app gefactureerd. Daar maakt Claude geen voorstel voor; Ruben beslist.
        case 'termijnen':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            $r = termijnen_opslaan($pid, (array)($d['termijnen'] ?? []));
            if (isset($r['fout'])) { uit($r); exit(1); }
            uit($r + ['lijst' => termijnen_van($pid)]);
            break;

        // Factuurvoorstel (concept; Ruben of Berend keurt goed en maakt de factuur in de boekhouding).
        // {"project":"PRJ-301","termijn":1} (volgnummer) of {"project":"WW-0123","regie":true} (uren en materiaal sinds het vorige voorstel)
        case 'factuur_voorstel':
            $pid = pid_of_fout((string)($d['project'] ?? ''));
            if (!empty($d['regie'])) $r = factuur_voorstel_regie($pid, 'Claude');
            else {
                $tid = q('SELECT id FROM termijnen WHERE project_id = ? AND volgorde = ?', [$pid, (int)($d['termijn'] ?? 0)])->fetchColumn();
                $r = !$tid ? ['fout' => 'termijn niet gevonden']
                   : ((int)q('SELECT controleren FROM termijnen WHERE id = ?', [$tid])->fetchColumn() ? ['fout' => 'termijn staat op controleren (misschien al gefactureerd buiten de app); Ruben beslist']
                   : factuur_voorstel_termijn((int)$tid, 'Claude'));
            }
            if (isset($r['fout'])) { uit($r); exit(1); }
            seintje_project($pid, 'Factuurvoorstel klaargezet door Claude', 'facturen.php?id=' . $r['factuur']);
            uit($r);
            break;

        // Factuurvoorstellen en termijnen die klaar zijn om te factureren. {"status":"concept"} (optioneel)
        case 'facturen':
            $st = isset(FACTUUR_STATUS[$d['status'] ?? '']) ? $d['status'] : null;
            uit(['voorstellen' => q('SELECT f.id, pr.nummer, f.soort, f.omschrijving, f.status, f.boekhouding_nummer, f.concept_door, f.aangemaakt, (SELECT COALESCE(SUM(aantal * prijs), 0) FROM factuurregels r WHERE r.factuur_id = f.id) totaal_excl
                                         FROM facturen f JOIN projecten pr ON pr.id = f.project_id WHERE ' . ($st ? 'f.status = ?' : "f.status IN ('concept','akkoord','geboekt')") . ' ORDER BY f.id DESC LIMIT 100', $st ? [$st] : [])->fetchAll(),
                 'klaar_om_te_factureren' => array_values(array_map(fn($t) => ['project' => $t['nummer'], 'termijn' => (int)$t['volgorde'], 'omschrijving' => $t['omschrijving'], 'moment' => $t['moment']], array_filter(termijnen_te_factureren(), fn($t) => !$t['controleren']))),
                 'eerst_controleren' => (int)q('SELECT COUNT(*) FROM termijnen WHERE controleren = 1')->fetchColumn()]);
            break;

        // Kleine standen lezen of zetten (watermerken, laatste run). {"sleutel":"licht_laatste"} leest; met "waarde" (tekst of JSON) zet. {} geeft alles.
        case 'brein_stand':
            if (empty($d['sleutel'])) { uit(q("SELECT sleutel, waarde, bijgewerkt FROM brein_stand WHERE sleutel <> 'slot' ORDER BY sleutel")->fetchAll()); break; }
            $k = mb_substr((string)$d['sleutel'], 0, 100);
            if (array_key_exists('waarde', $d)) {
                $w = is_string($d['waarde']) ? $d['waarde'] : json_encode($d['waarde'], JSON_UNESCAPED_UNICODE);
                if (strlen($w) > 4000000) { uit(['fout' => 'waarde te groot (meer dan 4 MB)']); exit(1); }
                q('INSERT INTO brein_stand (sleutel, waarde) VALUES (?, ?) ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)', [$k, $w]);
            }
            $r = q('SELECT waarde, bijgewerkt FROM brein_stand WHERE sleutel = ?', [$k])->fetch();
            uit(['sleutel' => $k, 'waarde' => $r ? (json_decode($r['waarde'], true) ?? $r['waarde']) : null, 'bijgewerkt' => $r['bijgewerkt'] ?? null]);
            break;

        default:
            $clVoor = null;
            uit(['opdrachten' => ['migreer', 'materiaallijst', 'bestelling', 'geleverd', 'factuur', 'project', 'bestellijst', 'bestellingen', 'zoek', 'product',
                'mensen', 'mens', 'taak', 'taken', 'taak_klaar', 'dagstatus', 'planning', 'plan_voorstel', 'voorstellen', 'plan_zet', 'dagopdracht', 'instructies', 'notities', 'notitie_verwerkt', 'project_info', 'ticket', 'tickets', 'pipedrive_import', 'afspraak', 'afspraken', 'klant', 'oplevering', 'bespreekpunt', 'bespreekpunten', 'whatsapp_in', 'whatsapp_chats', 'whatsapp_indelen', 'whatsapp', 'brein_slot', 'brein_slot_vrij', 'brein_stand', 'brein_lijst', 'taak_opschoonvoorstel', 'taak_opschoonvoorstellen', 'oplevering_concept', 'vraag_antwoord', 'claude_log', 'tweestaps_uit',
                'locatie', 'installatie', 'installaties', 'ticket_installatie', 'werkbon_concept', 'werkbonnen', 'begroting', 'termijnen', 'factuur_voorstel', 'facturen']]);
    }
    if (is_array($clVoor)) {
        $clNa = array_keys($clVoor);
        foreach (cl_doelen($opdracht, $d, $GLOBALS['CL_UIT'] ?? null) as [$t, $i]) $clNa[] = "$t:$i";
        foreach (array_unique($clNa) as $k) { [$t, $i] = explode(':', $k); cl_vast($opdracht, $t, (int)$i, $clVoor[$k] ?? null); }
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    uit(['fout' => $e->getMessage()]);
    exit(1);
}
