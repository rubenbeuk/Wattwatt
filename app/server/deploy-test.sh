#!/usr/bin/env bash
# Zet de app op de TESTKOPIE op de droplet (eigen map en eigen database voorraad_test).
# De echte app (/var/www/voorraad) wordt niet aangeraakt. Gebruik: bash server/deploy-test.sh [ip]
set -euo pipefail
HOST="${1:-188.166.16.61}"
SLEUTEL="$HOME/.ssh/wattwatt_voorraad"
cd "$(dirname "$0")/.."
ssh -i "$SLEUTEL" "root@${HOST}" 'mkdir -p /var/www/voorraad-test/public_html /var/www/voorraad-test/voorraad-sessions /var/www/voorraad-test/voorraad-fotos'
rsync -az --delete -e "ssh -i $SLEUTEL" --exclude '.DS_Store' public_html/ "root@${HOST}:/var/www/voorraad-test/public_html/"
ssh -i "$SLEUTEL" "root@${HOST}" 'cd /var/www/voorraad-test
if [ ! -f voorraad-config.php ]; then
  sed -e "s/'\''name'\'' => '\''voorraad'\''/'\''name'\'' => '\''voorraad_test'\''/" -e "s/Watt Watt Voorraad/Watt Watt TEST/" /var/www/voorraad/voorraad-config.php > voorraad-config.php
  chmod 640 voorraad-config.php
fi
grep -q "voorraad_test" voorraad-config.php || { echo "FOUT: testconfig wijst niet naar voorraad_test"; exit 1; }
for f in public_html/*.php public_html/inc/*.php; do php -l "$f" >/dev/null || exit 1; done
php public_html/inc/cli.php migreer
echo "Testkopie bijgewerkt en PHP-syntax in orde."'
