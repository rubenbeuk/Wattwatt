#!/usr/bin/env bash
# Eenmalige inrichting van een verse DigitalOcean-droplet (Ubuntu 24.04) voor de voorraadapp.
# Gebruik (als root op de droplet):  bash setup.sh voorraad.wattwatt.nl
set -euo pipefail

DOMAIN="${1:?Geef het domein mee, bv. voorraad.wattwatt.nl}"
APP=/var/www/voorraad
export DEBIAN_FRONTEND=noninteractive

echo "== Swap (1 GB RAM-droplet)"
if [ ! -f /swapfile ]; then
  fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "== Pakketten"
apt-get update -q
apt-get install -y -q nginx mariadb-server php-fpm php-mysql php-mbstring php-cli \
  certbot python3-certbot-nginx ufw unattended-upgrades fail2ban rsync
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

echo "== Firewall"
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable

echo "== Automatische beveiligingsupdates"
dpkg-reconfigure -f noninteractive unattended-upgrades

echo "== Database"
if [ ! -f /root/.voorraad-db ]; then
  DBPASS=$(openssl rand -base64 24 | tr -d '/+=')
  mysql -e "CREATE DATABASE IF NOT EXISTS voorraad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER IF NOT EXISTS 'voorraad'@'localhost' IDENTIFIED BY '${DBPASS}';
            GRANT ALL PRIVILEGES ON voorraad.* TO 'voorraad'@'localhost'; FLUSH PRIVILEGES;"
  echo "$DBPASS" > /root/.voorraad-db
  chmod 600 /root/.voorraad-db
fi
DBPASS=$(cat /root/.voorraad-db)

echo "== App-map en config"
mkdir -p "$APP/public_html" "$APP/voorraad-sessions"
if [ ! -f "$APP/voorraad-config.php" ]; then
  cat > "$APP/voorraad-config.php" <<EOF
<?php
return [
    'app_name' => 'Watt Watt Voorraad',
    'app_url'  => 'https://${DOMAIN}',
    'db' => ['host' => '127.0.0.1', 'name' => 'voorraad', 'user' => 'voorraad', 'pass' => '${DBPASS}'],
];
EOF
fi
chown -R www-data:www-data "$APP"
chmod 640 "$APP/voorraad-config.php"
chmod 700 "$APP/voorraad-sessions"

echo "== PHP"
INI=/etc/php/$PHPV/fpm/conf.d/90-voorraad.ini
cat > "$INI" <<EOF
expose_php = Off
upload_max_filesize = 20M
post_max_size = 25M
memory_limit = 256M
date.timezone = Europe/Amsterdam
session.gc_maxlifetime = 2592000
EOF
systemctl restart "php$PHPV-fpm"

echo "== Nginx"
cat > /etc/nginx/sites-available/voorraad <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${APP}/public_html;
    index index.php;
    client_max_body_size 25M;
    server_tokens off;

    location ^~ /inc/ { deny all; }
    location ~ /\. { deny all; }
    location / { try_files \$uri \$uri/ =404; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHPV}-fpm.sock;
    }
    location ~* \.(css|js|svg|png|json)$ { expires 7d; }
}
EOF
ln -sf /etc/nginx/sites-available/voorraad /etc/nginx/sites-enabled/voorraad
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "== Nachtelijke back-up van de database (30 dagen bewaard)"
mkdir -p /var/backups/voorraad
cat > /etc/cron.daily/voorraad-backup <<'EOF'
#!/bin/sh
mysqldump --single-transaction voorraad | gzip > /var/backups/voorraad/voorraad-$(date +%F).sql.gz
find /var/backups/voorraad -name '*.sql.gz' -mtime +30 -delete
EOF
chmod 755 /etc/cron.daily/voorraad-backup

echo "== HTTPS-certificaat"
if [ "${SKIP_CERT:-0}" = "1" ]; then
  echo "Certificaat overgeslagen (SKIP_CERT=1). Later: certbot --nginx -d ${DOMAIN} --redirect"
elif certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect; then
  echo "HTTPS staat aan."
else
  echo "LET OP: certificaat lukte niet. Wijst ${DOMAIN} al naar dit IP (Cloudflare: grijze wolk / DNS only)? Draai daarna:"
  echo "  certbot --nginx -d ${DOMAIN} --redirect"
fi

echo "Klaar. Zet de app-bestanden in ${APP}/public_html en open https://${DOMAIN}/install.php"
