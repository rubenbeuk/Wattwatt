# Watt Watt Voorraad

Webapp voor de voorraad: scannen of intypen, pakken, en direct boeken op een projectnummer.
Draait op een eigen DigitalOcean-droplet (PHP + MariaDB, geen framework, geen build-stap).

## Wat het doet
- **Scan**: camera scant de EAN-barcode op de verpakking of het QR-label op de bak. Typen kan ook, en een handscanner werkt in het zoekveld.
- **Project actief zetten**: één keer het projectnummer invullen of het projectlabel scannen. Alles wat je daarna pakt, gaat op dat project.
- **Pakken, terugbrengen, ontvangen**: iedereen. **Tellen** (voorraad op een exact aantal zetten): alleen beheerders.
- **Ongedaan maken**: een eigen boeking tot 30 minuten terug.
- **Projecten**: per project zie je welk materiaal erheen ging en wat de waarde is, met export naar Excel.
- **Import**: Excel uploaden (standaard het tabblad *Bewerkt*). Je ziet eerst wat er verandert; pas na akkoord wordt het opgeslagen.
- **Export**: de voorraadlijst gaat terug naar Excel in hetzelfde formaat als *Bewerkt*.
- **Labels**: QR-stickers printen (A4-vel, 3 × 8, 70 × 37 mm) voor producten en projecten.
- **Gebruikers**: beheerders maken accounts aan. Er is geen cloudaccount nodig, alleen een gebruikersnaam en wachtwoord.

## Mappen
- `public_html/`: de app (dit komt op de server)
- `server/setup.sh`: richt een verse Ubuntu 24.04-droplet in (nginx, PHP, MariaDB, HTTPS, firewall, nachtelijke back-up)
- `server/deploy.sh <ip>`: zet een nieuwe versie online
- `config.example.php`: voorbeeld van de config (de echte config staat op de server, buiten `public_html`)

## Hoe het nu draait
- **Adres:** https://voorraad.ruben-06c.workers.dev
- Cloudflare Worker `voorraad` in Rubens Cloudflare-account (code: `server/worker.js`) zorgt voor HTTPS en stuurt alles door naar de droplet.
- **Droplet:** `voorraad`, 188.166.16.61, DigitalOcean AMS3, $6 per maand. Inloggen als root met `~/.ssh/wattwatt_voorraad`.
- De droplet accepteert alleen verzoeken van Cloudflare-IP's met de header `CF-Worker: ruben-06c.workers.dev` (nginx: `snippets/alleen-cloudflare.conf`). Direct naar het IP geeft 403.
- Elke nacht een back-up van de database in `/var/backups/voorraad` (30 dagen bewaard).

## Inkoop en materiaallijsten (sinds 15-11-2026)
- **Materiaallijst per project** (projectpagina): Excel importeren, of Claude een Excel of PDF geven met het projectnummer. Wat op voorraad ligt wordt **klaargezet** (gereserveerd). Pas bij scannen en pakken gaat het van de voorraad. De rest komt op de bestellijst.
- **Inkoop** (menu): *Nog bestellen*, per project, met de Rexel-code en EAN, en een export naar Excel. Daaronder de *Bestellingen*, met status, leverdatum en afleveradres, en de knoppen **Geleverd in magazijn** (voorraad omhoog en klaargezet voor het project) en **Geleverd op het project**.
- **Scanscherm**: met een actief project zie je wat er voor dat project klaarligt.
- **Claude** (brein-run 3x per dag, stap A9) zet orderbevestigingen, leverdata en leveringen uit de mail van Ruben, Julian en Berend in de app. De facturencontrole (dagelijks, stap 2a) koppelt de facturen. Claude bestelt nooit zelf. De werkwijze staat in `claude-inkoop.md`. Commando's gaan via `python3 vapp.py <opdracht>`.
- Testen zonder de echte voorraad te raken: de database `voorraad_test` op de server, met `VOORRAAD_DB=voorraad_test php .../inc/cli.php ...`.

## Nieuwe versie online zetten
`bash server/deploy.sh 188.166.16.61`

Worker aangepast? Plak `server/worker.js` in Cloudflare → Workers & Pages → voorraad → Edit code → Deploy.

## Later naar voorraad.wattwatt.nl
Een Worker kan alleen een custom domain krijgen in het account waar de zone wattwatt.nl staat, en dat is niet Rubens account. Twee routes: (a) de beheerder geeft Ruben toegang tot dat account en we verhuizen de Worker erheen, of (b) de beheerder zet een A-record `voorraad` → 188.166.16.61 met proxy aan (oranje wolk), en dan passen we de nginx-regel aan zodat de zone wattwatt.nl ook mag. Pas daarna `app_url` aan in `/var/www/voorraad/voorraad-config.php`. Let op: QR-labels bevatten het adres, dus print ze pas als het definitieve adres vaststaat.

## Eerste installatie (voor een nieuwe server)
1. Maak in DigitalOcean een droplet aan: Ubuntu 24.04, Basic, $6/maand, regio Amsterdam, met de SSH-sleutel van deze Mac.
2. Zet in Cloudflare een A-record `voorraad` naar het IP van de droplet, met de **grijze wolk** (alleen DNS).
3. `scp server/setup.sh root@IP:` en daarna `ssh root@IP bash setup.sh voorraad.wattwatt.nl`
4. `bash server/deploy.sh IP`
5. Open `https://voorraad.wattwatt.nl/install.php` en maak de eerste beheerder aan.
6. Ga naar Import en upload `Watt Watt Voorraad lijst.xlsx`.

## Operatie: planning, taken, monteursdag en foto's (versie 1, gebouwd 16-11-2026)
**Live sinds 16-11-2026** (avond), na akkoord van Ruben. Back-up van de database van vlak daarvoor: `/var/backups/voorraad/voorraad-voor-operatie-2026-11-16-1935.sql.gz` op de droplet.

- **Mijn dag** (`dag.php`, startpagina): voor de monteur zijn planning van vandaag en de komende dagen, met adres, route, de vrijgegeven instructie, materiaal, foto's en de knoppen *Werk klaar* en *Probleem melden*. Voor kantoor de eigen taken, de ploeg van vandaag en meldingen van de ploeg.
- **Planning** (`planning.php`; monteurs zien dit als *Ploeg*, alleen lezen): per persoon per dag, ochtend en middag apart. Kantoor klikt op een vak om het in te vullen, ook voor meer mensen en meer dagen tegelijk. Deze planning is leidend.
- **Instructie voor de monteurs** (`opdracht.php`): per project per dag *wat doen*, *meenemen* en *stroom eraf*. Een monteur ziet de instructie pas als twee verschillende mensen akkoord hebben gegeven. Wie iets verandert, laat de controle vervallen. Wat Claude klaarzet telt niet als akkoord.
- **Team** (`team.php`, alleen kantoor): wie heeft wat open, nog te verdelen (één klik per persoon) en wat er vandaag is bijgewerkt.
- **Taken** (`taak.php`): Gedaan, status (open, bezig, wacht op), notitie en doorschuiven naar de volgende werkdag. Alles komt in een logboek per taak.
- **Foto's** (`fotos.php`): per project, per soort (uitvoering, schouw, oplevering, probleem). De telefoon verkleint de foto vóór het versturen. De bestanden staan buiten de webmap in `voorraad-fotos/` en zijn alleen na inloggen te zien.
- **Rollen**: *Kantoor* (in de database `admin`) ziet alles. *Monteur* ziet zijn dag, de ploeg, projecten, foto's en de voorraad, zonder bedragen. Iemand *zonder login* (onderaannemer) kan alleen worden ingepland. Wie in de planning staat, stel je in bij Gebruikers.
- **Claude** levert taken, instructies (als concept) en voorstellen voor de planning aan via `vapp.py`: `mensen`, `mens`, `taak`, `taken`, `taak_klaar`, `dagstatus`, `planning`, `plan_voorstel`, `voorstellen`, `dagopdracht`, `project_info`. Een voorstel verandert de planning pas na *Akkoord* in de app. `plan_zet` schrijft direct en is alleen voor de eerste vulling of als Ruben erom vraagt. De invoer per opdracht staat als commentaar in `public_html/inc/cli.php`.
- De code staat in `public_html/inc/ops.php` (functies) en `public_html/inc/schema.php` (tabellen planning, dagopdrachten, taken, taak_log, fotos, meldingen, voorstellen).

### Uitbreiding van 16-11-2026 (laat), live na akkoord van Ruben
- **Beslissers** (Ruben en Berend; kolom `gebruikers.beslisser`, in te stellen bij Gebruikers): wat zij invullen of goedkeuren is direct goed. Een instructie die een ander of Claude invult, zien monteurs pas na akkoord van een beslisser. Een planningswijziging van een ander (Julian) wordt een voorstel; een beslisser geeft akkoord.
- **Notities** (Mijn dag, onderaan): iedereen kan iets doorgeven, met de optie "Dit is ook een taak voor mij". Kantoor ziet alle notities, een monteur alleen zijn eigen. Claude verwerkt nieuwe notities bij elke brein-run (`vapp.py notities`, daarna `notitie_verwerkt` met wat ermee is gedaan).
- **Vraag aan kantoor** (monteur, Mijn dag): wordt een taak met `van_id`. De ploeg ziet onder "Waar wacht de ploeg op" alle vragen van monteurs met hun stand, en elkaars taken. De eigen taken van kantoor ziet de ploeg niet. Verandert kantoor de stand, dan krijgt de vrager een seintje.
- **Seintjes** (tabel `seintjes`): bij "Werk klaar" of "Probleem" van een monteur krijgt de projectleider een seintje (in te stellen op de projectpagina; zonder projectleider gaan ze naar de beslissers). Ook bij een vraag van een monteur en bij een planningsvoorstel van een collega. De teller staat in de menubalk en ververst elke minuut; er verschijnt een melding rechtsonder.
- **Materiaallijst** op de telefoon als kaarten in plaats van een tabel.

- **Taak "Watt Watt notities"** (`~/.claude/scheduled-tasks/watt-watt-notities/`): elk uur op werkdagen van 07:50 tot 18:50. Verwerkt nieuwe notities met alleen de gegevens uit de app (taak, afvinken, planningsvoorstel, concept-instructie) en meldt ze af. Stopt direct als er geen nieuwe notities zijn. Het brein leest alle notities van de laatste 2 dagen mee (stap A13).
- Back-up van de database van vlak voor deze uitbreiding: `/var/backups/voorraad/voorraad-voor-uitbreiding-2026-11-16-2032.sql.gz`.

### Testkopie
- Eigen map `/var/www/voorraad-test` en eigen database `voorraad_test` op dezelfde droplet. De echte app wordt niet geraakt.
- Bijwerken: `bash server/deploy-test.sh`. Opdrachten voor de testkopie: `VAPP_DOEL=test python3 vapp.py <opdracht>`.
- De testkopie heeft geen publiek adres. Bekijken kan via een tunnel vanaf de Mac:
  `ssh -i ~/.ssh/wattwatt_voorraad -L 8081:127.0.0.1:8081 root@188.166.16.61 'cd /var/www/voorraad-test/public_html && exec php -d upload_max_filesize=20M -d post_max_size=25M -S 127.0.0.1:8081'`
  en dan http://localhost:8081 openen.
- Vóór de bouw van versie 1 is de oude app gekopieerd naar `Archief/voorraad-public_html_voor_operatie_2026-11-16`.

### Stand na het live zetten (16-11-2026)
Gedaan: code uitgerold, database uitgebreid (`migreer`), fotomap aangemaakt, Boris, Joey, Sander, Julian en Turgay staan in de planning (Joey en Turgay nog zonder login), klant en adres ingevuld waar bekend, de planning t/m 24-12 gevuld uit de Excel plus Rubens weekmail van 16-11, drie concept-instructies (17, 18 en 19-11) en één planningsvoorstel (Verdoold naar vrijdag 18-12).

Ook gedaan op 16-11 (avond), na akkoord van Ruben:
- De taak watt-watt-brein is omgezet: taken, planningsvoorstellen en concept-instructies gaan naar de app; er komen geen nieuwe taken meer in Pipedrive en er wordt niets meer in de planning-Excel geschreven. De versie van vóór de overstap staat in `Archief/brein_SKILL_voor_app_2026-11-16.md`.
- 71 open taken overgezet: 58 uit Pipedrive (extern_id `pd:<taak-id>`), 9 uit Rubens mail "Te doen 17-11" aan Julian en 4 die alleen in de Excel-actielijst stonden. Pipedrive-taak 1222 (personeelszaak) is bewust niet overgezet. De taken staan in Pipedrive nog open; het brein rondt ze daar af zodra ze in de app klaar zijn.
- Berend staat in de app als kantoor, nog zonder wachtwoord. Zijn taken staan al op zijn naam.
- `dashboard/ops_snapshot.py` haalt de planning nu uit de app, zodat het oude Operationeel dashboard en de tv dezelfde planning tonen. De actielijst in dat dashboard is leeg.
- Nieuwe opdracht `instructies` in vapp.py (stand van de instructies per ingepland project per dag).

Nog te doen:
1. Ruben, bij Gebruikers: een wachtwoord voor Berend en Joey, en voor iedereen een eigen wachtwoord in plaats van het pilotwachtwoord. Kiezen of Sander monteur of kantoor is. Het account TEST blokkeren.
2. Adres van Verdoold (PRJ-297) en contactpersonen op locatie invullen.
3. Na de eerste brein-runs controleren of taken en voorstellen goed in de app komen.

Controleren zonder in te loggen (alleen lezen, op de droplet): `php /root/render-live.php dag.php "" boris` rendert een pagina tegen de echte database als die gebruiker.

## Ronde 1 verbeteradvies (28-11-2026)
- **Door Claude** (`claude.php`, kantoor): alles wat het brein via vapp.py veranderde, per run, met een knop Ongedaan. Ongedaan weigert als iemand de rij daarna zelf aanpaste. Tabel `claude_log` met voor/na van elke rij.
- **Takenlijst opschonen** (`opschonen.php`, kantoor): voorstellen van Claude (afvinken, vervalt, samenvoegen, eigenaar, deadline, project) goedkeuren of afwijzen, en zelf snel opruimen. Tabel `taak_voorstellen`.
- **Strengere taakregels in de CLI**: een nieuwe taak zonder eigenaar wordt geweigerd; een taak die lijkt op een open taak ook, tenzij `toch_nieuw`.
- **Rollen** verkoop (klanten, projecten, geen beheer) en onderaannemer (alleen eigen dag, uren, foto's en geplande projecten).
- **Tweede inlogstap** (`tweestaps.php`): code-app verplicht voor kantoor (uit te zetten met `'tweestaps' => false` in de config). Reset bij Gebruikers of met `vapp.py tweestaps_uit` (alleen via SSH).
- **Vraag Claude** op de projectpagina (notitie soort "vraag"); het brein antwoordt met `vraag_antwoord` op de tijdlijn.
- **Team**: takencijfers en "Wat aandacht vraagt" (brein_stand `overzicht`). **Overleg**: taken die meer dan 3 werkdagen te laat zijn.
- **Opleverdossier als concept**: `oplevering_concept` vult lege velden na "werk klaar"; de projectleider ziet een banner.
- **Brein-stand** staat nu in de app (brein_stand `groot`), niet meer in het dashboard. Regels: `brein/instructie/app_regels.md`.
- Na het live zetten: `php cli.php migreer` draaien (nieuwe tabellen en kolommen).
