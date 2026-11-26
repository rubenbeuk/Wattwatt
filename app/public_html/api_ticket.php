<?php
// Ingang voor het service-webformulier: maakt een ticket op het servicebord (vervangt het aanmaken van een Pipedrive-project).
// POST JSON met header "X-WW-Sleutel: <ticket_sleutel uit voorraad-config.php>". Velden (alles optioneel behalve naam of omschrijving):
// {"ticketnummer":"WW-261126-7U8P","ref":"<uuid van het formulier>","naam":"..","telefoon":"..","email":"..","adres":"..",
//  "apparaat":"Energieopslag","urgentie":"Normaal","afhandeling":"Eerst op afstand proberen","foutcode":"..",
//  "omschrijving":"..","wat_merkt":"..","sinds":".."}
// Antwoord: {"ok":true,"nummer":"WW-...","nieuw":true}. Zelfde ref nog eens sturen maakt geen tweede ticket.
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function antwoord(int $code, array $x): void { http_response_code($code); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
/** Tekst van het formulier ("Eerst op afstand proberen") naar de sleutel in de app. */
function keuze(string $tekst, array $opties): string
{
    $t = mb_strtolower(clean_text($tekst));
    if ($t === '') return '';
    if (isset($opties[$t])) return $t;
    foreach ($opties as $k => $naam) if (mb_strtolower($naam) === $t || str_starts_with(mb_strtolower($naam), $t)) return $k;
    return '';
}

global $CFG;
$sleutel = (string)($CFG['ticket_sleutel'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') antwoord(405, ['fout' => 'alleen POST']);
if ($sleutel === '' || !hash_equals($sleutel, (string)($_SERVER['HTTP_X_WW_SLEUTEL'] ?? ''))) antwoord(403, ['fout' => 'geen toegang']);
$d = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($d)) antwoord(400, ['fout' => 'ongeldige JSON']);

$beschrijving = trim(implode("\n", array_filter([
    trim((string)($d['omschrijving'] ?? '')),
    ($d['wat_merkt'] ?? '') !== '' ? 'Wat de klant merkt: ' . clean_text($d['wat_merkt']) : '',
    ($d['sinds'] ?? '') !== '' ? 'Sinds: ' . clean_text($d['sinds']) : '',
    ($d['foutcode'] ?? '') !== '' ? 'Foutcode: ' . clean_text($d['foutcode']) : '',
])));
try {
    $r = ticket_opslaan([
        'ticketnummer' => $d['ticketnummer'] ?? '', 'ref' => $d['ref'] ?? '', 'klant' => $d['naam'] ?? '', 'telefoon' => $d['telefoon'] ?? '',
        'email' => $d['email'] ?? '', 'adres' => $d['adres'] ?? '', 'foutcode' => $d['foutcode'] ?? '',
        'apparaat' => keuze((string)($d['apparaat'] ?? ''), APPARATEN), 'urgentie' => keuze((string)($d['urgentie'] ?? ''), URGENTIES),
        'afhandeling' => keuze((string)($d['afhandeling'] ?? ''), AFHANDELINGEN),
        'titel' => mb_strimwidth(clean_text($d['wat_merkt'] ?? '') ?: clean_text($d['omschrijving'] ?? ''), 0, 80, '…'), 'beschrijving' => $beschrijving,
    ], 'Webformulier');
    antwoord(200, ['ok' => true, 'nummer' => $r['nummer'], 'nieuw' => $r['nieuw']]);
} catch (InvalidArgumentException $e) {
    antwoord(422, ['fout' => $e->getMessage()]);
}
