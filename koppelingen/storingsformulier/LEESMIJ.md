# Storingsformulier koppelen aan de operatie-app

Het storingsformulier (wattwatt-service.vercel.app/storing-melden) zet meldingen nu als project op het Pipedrive-bord
"Service projecten". Na de overstap moeten ze op het servicebord van de app komen. Zo doe je dat:

1. Zet `app-tickets.js` in de broncode van het formulier als `lib/app-tickets.js`.
2. In `app/api/ticket/route.js`:
   - bovenaan: `import { pushNaarApp } from "../../../lib/app-tickets";`
   - direct na de eerste `await logMelding(...)` (het vangnet) en vóór de Pipedrive-stap:
     ```js
     let app = null;
     try {
       app = await pushNaarApp({ gegevens, triage, fotos, belofte, nr });
       await logMelding({ ticketnummer: nr, app: app?.overgeslagen ? `overgeslagen (${app.overgeslagen})` : "aangekomen" });
     } catch (e) {
       await logMelding({ ticketnummer: nr, app: "MISLUKT", appFout: String(e?.message || e).slice(0, 400) });
       console.error(`[ticket ${nr}] app mislukt:`, e);
     }
     ```
   - in het antwoord aan de browser mag `inCrm` worden: `Boolean(pd?.projectId) || Boolean(app?.nummer)`.
3. In Vercel twee instellingen toevoegen: `WW_APP_TICKET_URL` en `WW_APP_TICKET_SLEUTEL` (sleutel staat in de serverconfig).
4. Opnieuw uitrollen. Test met "TEST" in de naam; het ticket staat dan op het servicebord.
5. Na de overstap: maak `PIPEDRIVE_API_TOKEN` in Vercel leeg. Dan maakt het formulier geen Pipedrive-projecten meer;
   de rest blijft werken. (Het koppelen aan bestaande Pipedrive-contacten stopt dan ook; de app bewaart naam, telefoon,
   e-mail en adres op het ticket zelf.)

Het ticketnummer van het formulier (WW-jjmmdd-XXXX) blijft in de app hetzelfde, en dezelfde melding twee keer sturen
(dubbelklik, netwerkherhaling) maakt geen tweede ticket: de app herkent de formulier-id.
