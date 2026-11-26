<?php
// Alleen voor een lokale of test-database: testaccounts en een paar projecten, zodat je de app kunt bekijken als kantoor en als monteur.
// Gebruik:  VOORRAAD_DB=voorraad php app/dev/seed_lokaal.php
// Testwachtwoord voor alle accounts: test1234 (nooit op productie draaien).
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/public_html/inc/bootstrap.php';
require dirname(__DIR__) . '/public_html/inc/schema.php';
if (str_contains(strtolower((string)(getenv('VOORRAAD_DB') ?: $CFG['db']['name'])), 'voorraad') && !getenv('VOORRAAD_DB')) {
    fwrite(STDERR, "Zet VOORRAAD_DB expliciet (bijv. VOORRAAD_DB=voorraad_test) zodat je niet per ongeluk productie vult.\n"); exit(1);
}
schema_migreren();
$hash = password_hash('test1234', PASSWORD_DEFAULT);
foreach ([['Ruben', 'ruben', 'admin', 0, 1], ['Berend', 'berend', 'admin', 0, 1], ['Julian', 'julian', 'admin', 1, 0], ['Boris', 'boris', 'monteur', 1, 0], ['Joey', 'joey', 'monteur', 1, 0]] as [$n, $g, $r, $pl, $b]) {
    q('INSERT INTO gebruikers (naam, gebruikersnaam, wachtwoord_hash, rol, planbaar, beslisser) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE wachtwoord_hash = VALUES(wachtwoord_hash)', [$n, $g, $hash, $r, $pl, $b]);
}
// Een project dat al in de app stond voor de overstap (om samenvoegen te testen)
q("INSERT IGNORE INTO projecten (nummer, omschrijving, klant, adres, fase) VALUES ('PRJ-296', 'Oostendorp – 4x Hoymiles 261 kWh', 'Ron en Corno Oostendorp', 'Reijerscop 23, De Meern', 'materiaal')");
echo "klaar\n";
