Module van de taak Watt Watt brein: werken NA de overstap van Pipedrive Projects naar de operatie-app.
Geldt zodra in <MAP>/brein/overstap.json staat: {"projecten_in_app": true}. Staat dat er niet, of is het false, sla deze module over en werk zoals de kern zegt.
Besluit Ruben 26-11-2026: "maak deze overstap nu gelijk. Dan stoppen we met pipedrive." Pipedrive blijft alleen voor sales (deals, leads, personen, activiteiten van sales).

Wat deze module vervangt (lees de kern met deze regels erbovenop; bij verschil wint deze module):

1. Projecten en fases leven in de app.
- Lees projecten met: python3 "<MAP>/voorraad-app/vapp.py" projecten '{}' (velden nummer, soort, naam, klant, fase, opleverdatum). Fase-sleutels project: nieuw, werkbon, materiaal, inplannen, gepland, bezig, afgerond, factuur, betaald.
- B3 (fase bijhouden): zelfde regels en bewijs, maar je zet de fase alleen in de app: vapp.py project_info {"project":"PRJ-<nr>","fase":"<sleutel>"} en daarna vapp.py project_bericht {"project":"PRJ-<nr>","extern_id":"fase:<sleutel>:<dd-mm-jjjj>","bron":"claude","onderwerp":"Fase bijgewerkt","tekst":"<oud> → <nieuw>. Bewijs: <feit, bron, datum>."}. Ook startdatum en einddatum gaan via project_info. Geen updateProject, geen Zapier update_project, geen addNote op projecten.
- A11 en E1: "fase 43 of later in Pipedrive" wordt "fase afgerond, factuur of betaald in de app".
- Pipedrive getProjects, getProject, getProjectTasks en getNotes(project_id) gebruik je niet meer. Deal-informatie (getDeal, getNotes(deal_id)) mag nog wel.

2. Service-tickets leven in de app (servicebord).
- Open tickets: vapp.py tickets '{}' (nummer, naam, klant, fase, urgentie, apparaat, afhandeling, telefoon, eigenaar). Fase-sleutels service: melding, uitzoeken, op_locatie, ingepland, wacht_klant, opgelost.
- Nieuwe storing (B2, regel "Serviceticket"): bestaat er een open ticket voor die klant of locatie, zet de melding op dat ticket met vapp.py project_bericht {"project":"<ticketnummer>","extern_id":"<bron>:<id>","bron":"mail|whatsapp|teams","van":"..","onderwerp":"..","tekst":"korte samenvatting"}. Anders: vapp.py ticket {"klant":"<naam>","titel":"<korte omschrijving> <dd-mm>","adres":"..","telefoon":"..","email":"..","apparaat":"energieopslag|zonnepanelen|laadpaal|onbekend","urgentie":"acuut|spoed|normaal|laag","afhandeling":"afstand|eerst_afstand|locatie|bellen|onbekend","beschrijving":"afzender, datum, wat er mis is, voorstel, verwijzing naar bestaand project. (Automatisch aangemaakt door Claude)","eigenaar":"julian"}. Het antwoord geeft het ticketnummer (WW-jjmmdd-XXXX).
- De taak voor wie het ticket oppakt: extern_id "ticket:<ticketnummer>", project = het ticketnummer.
- Fase van een ticket: vapp.py project_info {"project":"<ticketnummer>","fase":"<sleutel>"}. "opgelost" sluit het ticket. Zet een ticket alleen op opgelost als de klant of een collega zegt dat het werkt; anders wacht_klant met een bericht.
- Tickets van het webformulier wattwatt.nl/service komen vanzelf in de app binnen. Niet nog eens aanmaken; zoek eerst met vapp.py tickets.
- Maximaal 5 nieuwe tickets per run (blijft).

3. Projectoverzicht (E4) staat in de app, niet meer als vastgezette Pipedrive-notitie.
- Zet dezelfde inhoud (zonder HTML, blokken als kop in hoofdletters, punten als "- ") alleen met vapp.py project_samenvatting {"project":"PRJ-<nr>","tekst":"..."}. Geen addNote of updateNote in Pipedrive.

4. Nieuw PRJ-nummer (E5).
- Nieuw gewonnen project = een deal die in Pipedrive op gewonnen ging of die Ruben als gewonnen meldt, en waarvoor nog geen project in de app staat (zoek op klant en deal met vapp.py projecten).
- Nummer: zoals de kern zegt, maar kijk naar de PRJ-nummers in de app (vapp.py projecten {"alle":true}) in plaats van Pipedrive-projecttitels. Geen Zapier update_project meer.
- Maak het project in de app met vapp.py project_info {"project":"PRJ-<nr>","naam":"..","klant":"..","adres":"..","fase":"nieuw","systeem":"..","kwh":..,"opleverdatum":".."} (alleen wat je zeker weet). OneDrive-map zoals de kern zegt.

5. Afspraken met een vast tijdstip blijven voorlopig een Pipedrive-activiteit voor Ruben (zoals de kern zegt), maar zonder project_id. Zodra de app een agenda heeft, vervalt deze regel.

6. Oude Pipedrive-taken met extern_id "pd:<id>": niet meer terugschrijven naar Pipedrive (geen Zapier update_task). De app is de enige plek.

7. Eenmalig na de overstap (eerste run waarin projecten_in_app true is): controleer met vapp.py projecten en vapp.py tickets of alle open projecten en tickets er staan (aantal noemen in F2), en meld in F2 wat ontbreekt. Zet daarna in overstap.json "gecontroleerd": "<datum>".
