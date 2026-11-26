#!/usr/bin/env bash
# Zet de app-bestanden op de droplet. Gebruik: bash server/deploy.sh <ip-van-droplet>
set -euo pipefail
HOST="${1:?Geef het IP-adres van de droplet mee}"
cd "$(dirname "$0")/.."
rsync -az --delete -e "ssh -i $HOME/.ssh/wattwatt_voorraad" --exclude '.DS_Store' public_html/ "root@${HOST}:/var/www/voorraad/public_html/"
ssh -i "$HOME/.ssh/wattwatt_voorraad" "root@${HOST}" 'chown -R www-data:www-data /var/www/voorraad/public_html && for f in /var/www/voorraad/public_html/*.php /var/www/voorraad/public_html/inc/*.php; do php -l "$f" >/dev/null || exit 1; done && echo "Uitgerold en PHP-syntax in orde."'
