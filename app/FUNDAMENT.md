# Fundament: wat er nodig is om de app bedrijfsbreed en toekomstbestendig te draaien

Stand 26-11-2026. Per punt: wat het oplost, wat Ruben (of Berend) eenmalig doet, en wat daarna vanzelf gaat.

## 1. Externe back-up (nachtelijks, versleuteld)
Lost op: nu staat alles (database en foto's) alleen op de droplet. Gaat die stuk, dan is alles weg.
- Eenmalig: maak een opslagaccount (Backblaze B2 of DigitalOcean Spaces, ca. $6 per maand, akkoord Ruben 26-11) en maak een "application key".
- Eenmalig op de server (doet Claude via de Mac-sessie, met jouw akkoord): `apt install rclone`, `rclone config` met remote-naam `wwbackup`, `server/backup.sh` naar `/var/www/voorraad/backup.sh`, cronregel uit het script.
- Bewaar de back-upsleutel (`/root/.wattwatt-backup-sleutel`) in de wachtwoordkluis. Zonder die sleutel is een back-up onleesbaar.
- Daarna: elke nacht om 03:15 een back-up; 30 dagen en 12 maanden bewaard. Eens per kwartaal een terugzet-test op `voorraad_test`.

## 2. Eigen adres: app.wattwatt.nl
Lost op: het adres voorraad.ruben-06c.workers.dev hangt aan Rubens persoonlijke Cloudflare-account en ziet er niet uit als een bedrijfsapp.
- Eenmalig in Cloudflare (Ruben beheert wattwatt.nl daar): Workers & Pages > de voorraad-worker > Settings > Domains & Routes > Add > Custom domain > `app.wattwatt.nl`. Cloudflare maakt het DNS-record en certificaat zelf.
- Daarna in de serverconfig `app_url` op `https://app.wattwatt.nl` zetten (Claude, met akkoord). Het oude adres blijft werken.
- Agenda-links en het storingsformulier (`WW_APP_TICKET_URL`) daarna op het nieuwe adres zetten.

## 3. Het brein los van de laptop (API in plaats van root-SSH)
Lost op: het brein schrijft nu via SSH als root vanaf Rubens Mac. Staat de Mac uit, dan stopt alles; en root-toegang is meer dan nodig.
- `api_brein.php` doet dezelfde opdrachten als `cli.php`, met een eigen sleutel (`brein_sleutel` in de serverconfig, minimaal 32 tekens). Zonder geldige sleutel geeft hij 404.
- `vapp.py` gebruikt die ingang vanzelf als `VAPP_URL` en `VAPP_SLEUTEL` gezet zijn; anders blijft het SSH.
- Volgende stap: het brein als geplande cloudtaak draaien (Claude Code op het web of een eigen server), met de sleutel als geheime instelling. Dan kan de root-SSH-sleutel van de Mac weg.

## 4. Tweede beheerder: Berend
- GitHub: zodra Berend een account heeft, nodigt Ruben hem uit op github.com/rubenbeuk/Wattwatt (Settings > Collaborators).
- DigitalOcean: Ruben nodigt Berend uit in het team (Settings > Team).
- Cloudflare: Ruben voegt Berend toe als member (Manage account > Members).
- Wachtwoordkluis: back-upsleutel, serverwachtwoord en Pipedrive/Vercel-toegang delen.

## 5. Testomgeving
- `server/deploy-test.sh` zet de app op de testkopie (`/var/www/voorraad-test`, database `voorraad_test`). Elke wijziging gaat eerst daarheen.
- `app/dev/seed_lokaal.php` maakt testaccounts voor een lokale of testdatabase (wachtwoord test1234), zodat Claude de app kan testen als kantoor en als monteur zonder echte wachtwoorden.
- Later (optioneel, $6 per maand): een aparte kleine droplet voor test, zodat een fout in een test nooit de echte app raakt.
