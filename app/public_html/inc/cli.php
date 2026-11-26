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

function uit($x): void { echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION), "\n"; }
function pid_of_fout(string $nr): int
{
    $r = q('SELECT id FROM projecten WHERE nummer = ?', [project_nummer($nr)])->fetch();
    if (!$r) { uit(['fout' => 'project niet gevonden: ' . project_nummer($nr)]); exit(1); }
    return (int)$r['id'];
}

try {
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
            uit(q("SELECT naam, gebruikersnaam, IF(wachtwoord_hash = '', 'geen login', IF(rol = 'admin', 'kantoor', 'monteur')) AS rol, planbaar FROM gebruikers WHERE actief = 1 ORDER BY volgorde, naam")->fetchAll());
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
            uit(q("SELECT n.id, g.gebruikersnaam AS van, g.beslisser, IF(g.rol = 'admin', 'kantoor', 'monteur') AS rol, pr.nummer AS project, n.tekst, n.status, n.verwerking, n.aangemaakt
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
            uit(['ok' => true, 'project' => project_nummer((string)$d['project']), 'extern_id' => $ext]);
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
            foreach ($ks as &$k) { $k['projecten'] = q('SELECT nummer, soort, omschrijving AS naam, fase, afgerond FROM projecten WHERE klant_id = ? ORDER BY id DESC', [$k['id']])->fetchAll(); unset($k['id']); }
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

        default:
            uit(['opdrachten' => ['migreer', 'materiaallijst', 'bestelling', 'geleverd', 'factuur', 'project', 'bestellijst', 'bestellingen', 'zoek', 'product',
                'mensen', 'mens', 'taak', 'taken', 'taak_klaar', 'dagstatus', 'planning', 'plan_voorstel', 'voorstellen', 'plan_zet', 'dagopdracht', 'instructies', 'notities', 'notitie_verwerkt', 'project_info', 'ticket', 'tickets', 'pipedrive_import', 'afspraak', 'afspraken', 'klant', 'oplevering', 'bespreekpunt', 'bespreekpunten']]);
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    uit(['fout' => $e->getMessage()]);
    exit(1);
}
