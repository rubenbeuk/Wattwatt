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
UIT=$(ssh -i "$SLEUTEL" "root@$HOST" "cp '$F' '$cp_naam' && php -r '
  \$f = \"$F\"; \$t = bin2hex(random_bytes(24)); \$s = file_get_contents(\$f);
  \$s = preg_replace(\"/\\s*.brein_sleutel. => .[^\\x27]*.,/\", \"\", \$s);
  \$p = strrpos(\$s, \"];\"); if (\$p === false) { fwrite(STDERR, \"config onbekend\\n\"); exit(1); }
  \$s = substr(\$s, 0, \$p) . \"    \\x27brein_sleutel\\x27 => \\x27\" . \$t . \"\\x27,\\n\" . substr(\$s, \$p);
  file_put_contents(\$f, \$s); \$c = require \$f; echo (\$c[\"brein_sleutel\"] ?? \"\") === \$t ? \$t : \"MISLUKT\";'")
if [ "$UIT" = "MISLUKT" ] || [ ${#UIT} -ne 48 ]; then echo "Er ging iets mis; de oude config staat in $cp_naam op de server."; exit 1; fi
echo
echo "Sleutel staat op de server (back-up van de oude config: $cp_naam)."
echo "Zet deze twee regels nu in de instellingen van de cloudomgeving, onder Omgevingsvariabelen:"
echo
echo "VAPP_URL=https://voorraad.ruben-06c.workers.dev"
echo "VAPP_SLEUTEL=$UIT"
echo
echo "Sluit daarna dit venster (dan staat de sleutel niet meer op je scherm)."
