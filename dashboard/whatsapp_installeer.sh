#!/usr/bin/env bash
# Laat whatsapp_naar_app.py elke 15 minuten draaien op deze Mac (launchd, zonder Claude).
#   bash whatsapp_installeer.sh          installeren of bijwerken
#   bash whatsapp_installeer.sh uit      stoppen en verwijderen
set -euo pipefail
LABEL="nl.wattwatt.whatsapp-naar-app"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
SCRIPT="$(cd "$(dirname "$0")" && pwd)/whatsapp_naar_app.py"
LOG="$HOME/Library/Logs/wattwatt-whatsapp.log"

launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true
if [ "${1:-}" = "uit" ]; then rm -f "$PLIST"; echo "WhatsApp naar de app staat uit."; exit 0; fi

mkdir -p "$HOME/Library/LaunchAgents" "$HOME/Library/Logs"
cat > "$PLIST" <<P
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>$LABEL</string>
  <key>ProgramArguments</key><array><string>/usr/bin/python3</string><string>$SCRIPT</string></array>
  <key>StartInterval</key><integer>900</integer>
  <key>RunAtLoad</key><true/>
  <key>StandardOutPath</key><string>$LOG</string>
  <key>StandardErrorPath</key><string>$LOG</string>
</dict></plist>
P
launchctl bootstrap "gui/$(id -u)" "$PLIST"
sleep 20
echo "Geïnstalleerd. Laatste regels van het logboek:"
tail -n 5 "$LOG" 2>/dev/null || echo "(nog leeg)"
echo "Staat er 'Operation not permitted', geef dan python3 volledige schijftoegang: Systeeminstellingen > Privacy en beveiliging > Volledige schijftoegang > + > /usr/bin/python3."
