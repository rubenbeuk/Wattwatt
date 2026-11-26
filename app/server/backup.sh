#!/usr/bin/env bash
# Nachtelijke externe back-up van de operatie-app: database (echt en test) en foto's, naar opslag BUITEN de droplet.
# Draait op de droplet als root via cron:  15 3 * * * /var/www/voorraad/backup.sh >> /var/log/wattwatt-backup.log 2>&1
# Eenmalig nodig: rclone met een remote "wwbackup" (bijv. Backblaze B2 of DigitalOcean Spaces, ca. $6/mnd), zie FUNDAMENT.md.
# Bewaart 30 dagelijkse en 12 maandelijkse back-ups. Versleuteld met de sleutel in /root/.wattwatt-backup-sleutel (bewaar een kopie in de wachtwoordkluis!).
set -euo pipefail
DATUM=$(date +%F); MAAND=$(date +%Y-%m); DAG=$(date +%d)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
SLEUTEL=/root/.wattwatt-backup-sleutel
[ -s "$SLEUTEL" ] || { openssl rand -hex 32 > "$SLEUTEL"; chmod 600 "$SLEUTEL"; echo "Nieuwe back-upsleutel gemaakt in $SLEUTEL: zet een kopie in de wachtwoordkluis."; }
for DB in voorraad voorraad_test; do
  mysqldump --single-transaction --routines --default-character-set=utf8mb4 "$DB" | gzip -9 > "$TMP/$DB.sql.gz"
done
tar -czf "$TMP/fotos.tar.gz" -C /var/www/voorraad voorraad-fotos 2>/dev/null || true
tar -cf - -C "$TMP" . | openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:"$SLEUTEL" > "$TMP/../wattwatt-$DATUM.tar.enc"
rclone copyto "$TMP/../wattwatt-$DATUM.tar.enc" "wwbackup:wattwatt-backup/dag/wattwatt-$DATUM.tar.enc"
[ "$DAG" = "01" ] && rclone copyto "$TMP/../wattwatt-$DATUM.tar.enc" "wwbackup:wattwatt-backup/maand/wattwatt-$MAAND.tar.enc"
rm -f "$TMP/../wattwatt-$DATUM.tar.enc"
rclone delete --min-age 31d "wwbackup:wattwatt-backup/dag/"
rclone delete --min-age 370d "wwbackup:wattwatt-backup/maand/"
echo "$(date -Is) back-up $DATUM klaar"
# Terugzetten (test eerst op voorraad_test!):
#   rclone copyto wwbackup:wattwatt-backup/dag/wattwatt-JJJJ-MM-DD.tar.enc /tmp/b.enc
#   openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.wattwatt-backup-sleutel -in /tmp/b.enc | tar -xf - -C /tmp/terug
#   gunzip < /tmp/terug/voorraad.sql.gz | mysql voorraad_test
