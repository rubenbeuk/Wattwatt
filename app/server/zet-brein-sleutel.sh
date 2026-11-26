#!/usr/bin/env bash
# Maakt een nieuwe sleutel voor de brein-ingang (api_brein.php) en zet hem in de config op de server.
# De sleutel wordt op de server gemaakt en één keer hier getoond, zodat jij hem zelf in de instellingen van de
# cloudomgeving zet (variabele VAPP_SLEUTEL). Hij komt niet in een chat of in een bestand op je Mac.
# Gebruik:  bash server/zet-brein-sleutel.sh            -> echte app
#           bash server/zet-brein-sleutel.sh test       -> alleen de testkopie
# Opnieuw draaien maakt een nieuwe sleutel; de oude werkt dan meteen niet meer.
set -euo pipefail
HOST="188.166.16.61"
SLEUTEL="$HOME/.ssh/wattwatt_voorraad"
F="/var/www/voorraad/voorraad-config.php"
[ "${1:-}" = "test" ] && F="/var/www/voorraad-test/voorraad-config.php"
cp_naam="$F.bak-$(date +%Y%m%d-%H%M)"
# Het PHP-script gaat via stdin naar de server (geen gedoe met aanhalingstekens); het pad gaat mee als argument.
UIT=$(ssh -i "$SLEUTEL" "root@$HOST" "cp '$F' '$cp_naam' && php -- '$F'" <<'PHP'
<?php
$f = $argv[1];
$s = $orig = file_get_contents($f);
if (!is_string($s)) { fwrite(STDERR, "config niet leesbaar: $f\n"); exit(1); }
// oude brein_sleutel-regel weghalen
$s = preg_replace("/\n[ \t]*['\"]brein_sleutel['\"]\s*=>\s*['\"][^'\"]*['\"]\s*,?[ \t]*/", "", $s);
// het einde van de config zoeken: de laatste ] of ) vóór een ; (werkt voor return [...]; en return array(...);)
if (!preg_match_all('/[\])]\s*;/', $s, $m, PREG_OFFSET_CAPTURE)) { fwrite(STDERR, "config onbekend\n"); exit(1); }
$p = end($m[0])[1];
$voor = rtrim(substr($s, 0, $p));
if (!in_array(substr($voor, -1), [',', '[', '('], true)) $voor .= ',';
$t = bin2hex(random_bytes(24));
$s = $voor . "\n    'brein_sleutel' => '" . $t . "',\n" . substr($s, $p);
file_put_contents($f, $s);
if (function_exists('opcache_invalidate')) opcache_invalidate($f, true);
$c = require $f;
if (is_array($c) && ($c['brein_sleutel'] ?? '') === $t) { echo $t; exit(0); }
file_put_contents($f, $orig);  // klopt het niet: oude config terugzetten
echo 'MISLUKT';
PHP
)
if [ "$UIT" = "MISLUKT" ] || [ ${#UIT} -ne 48 ]; then echo "Er ging iets mis; de oude config staat in $cp_naam op de server."; exit 1; fi
echo
echo "Sleutel staat op de server (back-up van de oude config: $cp_naam)."
echo "Zet deze twee regels nu in de instellingen van de cloudomgeving, onder Omgevingsvariabelen:"
echo
echo "VAPP_URL=https://voorraad.ruben-06c.workers.dev"
echo "VAPP_SLEUTEL=$UIT"
echo
echo "Sluit daarna dit venster (dan staat de sleutel niet meer op je scherm)."
