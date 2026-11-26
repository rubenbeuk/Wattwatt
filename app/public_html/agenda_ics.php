<?php
// Agenda-abonnement (iCalendar) voor Outlook, iPhone en Google: agenda_ics.php?t=<persoonlijke sleutel>[&alle=1]
// Alleen lezen. Zonder geldige sleutel geen toegang. "alle" alleen voor kantoor.
require __DIR__ . '/inc/bootstrap.php';

$t = (string)($_GET['t'] ?? '');
$g = strlen($t) === 40 ? q('SELECT id, naam, rol FROM gebruikers WHERE agenda_token = ? AND actief = 1', [$t])->fetch() : null;
if (!$g) { http_response_code(404); exit; }
$alle = !empty($_GET['alle']) && $g['rol'] === 'admin';

$rows = q("SELECT a.*, gb.naam, p.nummer, p.adres AS padres FROM afspraken a
           LEFT JOIN gebruikers gb ON gb.id = a.gebruiker_id LEFT JOIN projecten p ON p.id = a.project_id
           WHERE a.start >= NOW() - INTERVAL 60 DAY" . ($alle ? '' : ' AND (a.gebruiker_id = ? OR a.gebruiker_id IS NULL)') . ' ORDER BY a.start LIMIT 2000',
          $alle ? [] : [$g['id']])->fetchAll();
// Ook de eigen ploegdagen uit de planning, zodat monteurs hun werk in hun telefoonagenda zien
$plan = q("SELECT pl.id, pl.datum, pl.dagdeel, pl.tekst, pl.voorbehoud, gb.naam, p.nummer, p.omschrijving, p.adres FROM planning pl
           JOIN gebruikers gb ON gb.id = pl.gebruiker_id LEFT JOIN projecten p ON p.id = pl.project_id
           WHERE pl.datum >= CURDATE() - INTERVAL 14 DAY" . ($alle ? '' : ' AND pl.gebruiker_id = ?') . ' ORDER BY pl.datum LIMIT 3000', $alle ? [] : [$g['id']])->fetchAll();

function ics_tekst(string $s): string { return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s); }
function ics_regel(string $s): string { $uit = ''; while (strlen($s) > 74) { $deel = mb_strcut($s, 0, 74, 'UTF-8'); $uit .= $deel . "\r\n "; $s = substr($s, strlen($deel)); } return $uit . $s . "\r\n"; }
function ics_tijd(string $nl): string { return (new DateTime($nl, new DateTimeZone('Europe/Amsterdam')))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'); }

$host = parse_url(APP_URL ?: 'https://wattwatt.app', PHP_URL_HOST) ?: 'wattwatt';
$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Watt Watt//Operatie-app//NL\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
$out .= ics_regel('X-WR-CALNAME:' . ics_tekst('Watt Watt' . ($alle ? ' (iedereen)' : ' – ' . $g['naam'])));
$out .= "X-WR-TIMEZONE:Europe/Amsterdam\r\nREFRESH-INTERVAL;VALUE=DURATION:PT1H\r\nX-PUBLISHED-TTL:PT1H\r\n";
$nu = gmdate('Ymd\THis\Z');
foreach ($rows as $a) {
    $out .= "BEGIN:VEVENT\r\n" . ics_regel("UID:afspraak-{$a['id']}@$host") . "DTSTAMP:$nu\r\n";
    if ($a['hele_dag']) {
        $d = substr($a['start'], 0, 10);
        $out .= 'DTSTART;VALUE=DATE:' . str_replace('-', '', $d) . "\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime($d . ' +1 day')) . "\r\n";
    } else {
        $out .= 'DTSTART:' . ics_tijd($a['start']) . "\r\nDTEND:" . ics_tijd($a['eind'] ?: date('Y-m-d H:i:s', strtotime($a['start'] . ' +30 minutes'))) . "\r\n";
    }
    $out .= ics_regel('SUMMARY:' . ics_tekst(($alle && $a['naam'] ? explode(' ', $a['naam'])[0] . ': ' : '') . $a['titel'] . ($a['nummer'] ? ' (' . $a['nummer'] . ')' : '')));
    if ($a['locatie'] !== '' || $a['padres']) $out .= ics_regel('LOCATION:' . ics_tekst($a['locatie'] ?: $a['padres']));
    if ($a['notitie']) $out .= ics_regel('DESCRIPTION:' . ics_tekst($a['notitie']));
    $out .= $a['geannuleerd'] ? "STATUS:CANCELLED\r\n" : "STATUS:CONFIRMED\r\n";
    $out .= "END:VEVENT\r\n";
}
foreach ($plan as $p) {
    $tijd = $p['dagdeel'] === 'middag' ? ['13:00', '17:00'] : ['07:30', '12:30'];
    $wat = trim(($p['nummer'] ? $p['nummer'] . ' ' . project_naam(['omschrijving' => $p['omschrijving'], 'nummer' => $p['nummer']]) : '') . ($p['tekst'] ? ' · ' . $p['tekst'] : '')) ?: 'Ingepland';
    $out .= "BEGIN:VEVENT\r\n" . ics_regel("UID:planning-{$p['id']}@$host") . "DTSTAMP:$nu\r\n";
    $out .= 'DTSTART:' . ics_tijd($p['datum'] . ' ' . $tijd[0]) . "\r\nDTEND:" . ics_tijd($p['datum'] . ' ' . $tijd[1]) . "\r\n";
    $out .= ics_regel('SUMMARY:' . ics_tekst(($alle ? explode(' ', $p['naam'])[0] . ': ' : '') . $wat . (!empty($p['voorbehoud']) ? ' (onder voorbehoud)' : '')));
    if ($p['adres']) $out .= ics_regel('LOCATION:' . ics_tekst($p['adres']));
    $out .= "TRANSP:TRANSPARENT\r\nEND:VEVENT\r\n";
}
$out .= "END:VCALENDAR\r\n";
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="wattwatt.ics"');
header('Cache-Control: private, max-age=900');
echo $out;
