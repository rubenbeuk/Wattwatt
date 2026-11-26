#!/usr/bin/env bash
# Zet de Pipedrive API-sleutel (alleen lezen gebruikt) in de config op de server, zonder dat hij in een chat of bestand op je Mac komt.
# Gebruik:  bash server/zet-pipedrive-sleutel.sh            -> alleen de testkopie
#           bash server/zet-pipedrive-sleutel.sh productie  -> ook de echte app
# De sleutel vind je in Pipedrive: rechtsboven je naam > Persoonlijke voorkeuren > API.
set -euo pipefail
HOST="188.166.16.61"
SLEUTEL="$HOME/.ssh/wattwatt_voorraad"
read -r -s -p "Plak hier je Pipedrive API-sleutel en druk op Enter (je ziet niets verschijnen): " TOKEN; echo
[[ "$TOKEN" =~ ^[A-Za-z0-9]{20,64}$ ]] || { echo "Dat lijkt geen Pipedrive-sleutel. Niets gewijzigd."; exit 1; }
DOELEN="/var/www/voorraad-test/voorraad-config.php"
[ "${1:-}" = "productie" ] && DOELEN="$DOELEN /var/www/voorraad/voorraad-config.php"
for F in $DOELEN; do
  printf '%s' "$TOKEN" | ssh -i "$SLEUTEL" "root@$HOST" "php -r '
    \$f = \"$F\"; \$t = trim(stream_get_contents(STDIN)); \$s = file_get_contents(\$f);
    \$s = preg_replace(\"/\\s*.pipedrive_token. => .[^\\x27]*.,/\", \"\", \$s);
    \$p = strrpos(\$s, \"];\"); if (\$p === false) { fwrite(STDERR, \"config onbekend\\n\"); exit(1); }
    \$s = substr(\$s, 0, \$p) . \"    \\x27pipedrive_token\\x27 => \\x27\" . \$t . \"\\x27,\\n\" . substr(\$s, \$p);
    file_put_contents(\$f, \$s); \$c = require \$f; echo (\$c[\"pipedrive_token\"] ?? \"\") === \$t ? \"OK: \$f\\n\" : \"MISLUKT: \$f\\n\";'"
done
