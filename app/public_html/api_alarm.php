<?php
// Ingang voor monitoringalarmen (Teleport/Withthegrid, Eniris, Hoymiles, Sigenergy ...): maakt of vult een ticket op het servicebord.
// POST JSON met header "X-WW-Sleutel: <ticket_sleutel>":
// {"bron":"teleport","alarm_id":"y3jrek","klant":"Anton van der Gun","adres":"Reijerscop 27 Utrecht","apparaat":"energieopslag",
//  "melding":"Verbinding met batterij verslechterd","status":"open"|"opgelost","link":"https://..."}
// - Staat er al een open ticket voor deze klant of dit adres: het alarm komt als bericht op dat ticket (geen nieuw ticket).
// - Anders een nieuw ticket in fase "Nieuwe melding", afhandeling "eerst op afstand".
// - "opgelost": alleen een bericht "hersteld" op het ticket; sluiten doet een mens.
// Zelfde bron + alarm_id + status nog eens = niets dubbel.
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function antwoord(int $c, array $x): void { http_response_code($c); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }

global $CFG;
$sleutel = (string)($CFG['ticket_sleutel'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') antwoord(405, ['fout' => 'alleen POST']);
if ($sleutel === '' || !hash_equals($sleutel, (string)($_SERVER['HTTP_X_WW_SLEUTEL'] ?? ''))) antwoord(403, ['fout' => 'geen toegang']);
$d = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($d)) antwoord(400, ['fout' => 'ongeldige JSON']);

$bron = mb_substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($d['bron'] ?? 'monitoring'))), 0, 30) ?: 'monitoring';
$alarm = mb_substr(clean_text($d['alarm_id'] ?? ''), 0, 80);
$klant = mb_substr(clean_text($d['klant'] ?? ''), 0, 150);
$adres = mb_substr(clean_text($d['adres'] ?? ''), 0, 255);
$melding = mb_substr(clean_text($d['melding'] ?? ''), 0, 500);
$opgelost = ($d['status'] ?? 'open') === 'opgelost';
if ($melding === '' || ($klant === '' && $adres === '')) antwoord(422, ['fout' => 'melding en klant of adres zijn nodig']);

// Open ticket voor dezelfde klant of hetzelfde adres?
$t = q("SELECT id, nummer FROM projecten WHERE soort = 'service' AND fase <> 'opgelost' AND ((? <> '' AND klant = ?) OR (? <> '' AND adres = ?)) ORDER BY id DESC LIMIT 1",
       [$klant, $klant, $adres, $adres])->fetch();
$ext = "alarm:$bron:" . ($alarm !== '' ? $alarm : md5($klant . $adres . $melding)) . ($opgelost ? ':hersteld' : '');
if (!$t && $opgelost) antwoord(200, ['ok' => true, 'genegeerd' => 'hersteld zonder open ticket']);
if (!$t) {
    $r = ticket_opslaan(['klant' => $klant ?: $adres, 'adres' => $adres, 'apparaat' => $d['apparaat'] ?? 'energieopslag', 'urgentie' => 'normaal',
        'afhandeling' => 'eerst_afstand', 'titel' => mb_strimwidth($melding, 0, 80, '…'), 'beschrijving' => "Alarm van $bron" . ($alarm ? " ($alarm)" : '') . ": $melding", 'ref' => $ext], ucfirst($bron));
    $t = q('SELECT id, nummer FROM projecten WHERE nummer = ?', [$r['nummer']])->fetch();
    $nieuw = true;
} else $nieuw = false;
q('INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst, link, extern_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
   ON DUPLICATE KEY UPDATE tekst = VALUES(tekst)',
    [$t['id'], date('Y-m-d H:i:s'), 'app', ucfirst($bron), $opgelost ? 'Alarm hersteld' : 'Alarm', $melding, mb_substr((string)($d['link'] ?? ''), 0, 500), $ext]);
antwoord(200, ['ok' => true, 'ticket' => $t['nummer'], 'nieuw_ticket' => $nieuw]);
