<?php
// HTTP-ingang voor het brein: dezelfde opdrachten als inc/cli.php, maar zonder root-SSH.
// Daardoor kan het brein ook vanuit de cloud draaien als Rubens Mac uit staat.
// POST /api_brein.php?opdracht=<naam>  met header "Authorization: Bearer <brein_sleutel uit voorraad-config.php>" en JSON als body.
// Antwoord: precies wat cli.php teruggeeft (JSON). Zonder geldige sleutel: 404 (de ingang verraadt niet dat hij bestaat).
require __DIR__ . '/inc/bootstrap.php';

global $CFG;
$sleutel = (string)($CFG['brein_sleutel'] ?? '');
$kop = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($sleutel === '' || strlen($sleutel) < 32 || !hash_equals('Bearer ' . $sleutel, $kop) || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(404); exit; }

$opdracht = (string)($_GET['opdracht'] ?? '');
// Alleen opdrachten die het brein nodig heeft; migreer en pipedrive_import blijven via SSH (beheer)
const BREIN_OPDRACHTEN = ['materiaallijst', 'bestelling', 'geleverd', 'factuur', 'project', 'bestellijst', 'bestellingen', 'zoek', 'product', 'mensen', 'mens',
    'taak', 'taken', 'taak_klaar', 'taak_samenvoegen', 'taak_status', 'taak_vervalt', 'dagstatus', 'planning', 'plan_voorstel', 'voorstellen', 'plan_zet', 'dagopdracht',
    'instructies', 'notities', 'notitie_verwerkt', 'projecten', 'project_info', 'project_bericht', 'mijlpaal', 'mijlpalen', 'project_samenvatting', 'project_berichten',
    'ticket', 'tickets', 'afspraak', 'afspraken', 'klant', 'oplevering', 'bespreekpunt', 'bespreekpunten', 'project_opruimen'];
header('Content-Type: application/json; charset=utf-8');
if (!in_array($opdracht, BREIN_OPDRACHTEN, true)) { http_response_code(400); echo json_encode(['fout' => 'onbekende opdracht']); exit; }
$invoer = (string)file_get_contents('php://input', false, null, 0, 2_000_000);
if ($invoer !== '' && !is_array(json_decode($invoer, true))) { http_response_code(400); echo json_encode(['fout' => 'ongeldige JSON']); exit; }

// Draai cli.php als apart proces (zelfde gedrag als via SSH), met de JSON op stdin
$p = proc_open([PHP_BINARY === '' ? 'php' : (str_contains(PHP_BINARY, 'fpm') ? 'php' : PHP_BINARY), __DIR__ . '/inc/cli.php', $opdracht], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($p)) { http_response_code(500); echo json_encode(['fout' => 'kon opdracht niet starten']); exit; }
fwrite($pipes[0], $invoer); fclose($pipes[0]);
$uit = stream_get_contents($pipes[1]); fclose($pipes[1]);
$err = stream_get_contents($pipes[2]); fclose($pipes[2]);
$code = proc_close($p);
if ($code !== 0) http_response_code(422);
echo $uit !== '' ? $uit : json_encode(['fout' => 'geen uitvoer', 'detail' => mb_substr($err, 0, 300)]);
