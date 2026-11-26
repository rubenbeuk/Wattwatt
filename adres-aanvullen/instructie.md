
Je werkt voor Watt Watt B.V. Doel: elke nieuwe SEA-lead in Pipedrive krijgt een volledig adres (straat, huisnummer, postcode, woonplaats). Het websiteformulier levert alleen postcode + huisnummer. Werk snel en zuinig: geen analyse, geen rapport, alleen deze klus.

Map: ~/Library/CloudStorage/OneDrive-WattWattB.V/Operationeel Watt Watt Claude/adres-aanvullen/
- pdok_adres.py — hulpscript, draai als: python3 -I "<map>/pdok_adres.py" "<postcode>" "<huisnummer>"
- verwerkt.json — lijst met deal-id's die al klaar zijn (maak aan als [] als hij niet bestaat)
- log.md — één regel per verwerkte lead

Stappen
1. Haal via de Pipedrive-connector (getDeals) de deals op in pipeline 25, gesorteerd op add_time aflopend, limit 50. SEA-leads zijn deals met label 163 (SEA zakelijk), 167 (SEA Particulier) of 171 (SEA klein zakelijk). Neem alleen deals van de afgelopen 7 dagen die niet in verwerkt.json staan.
2. Per deal: haal de persoon op (getPerson, person_id). Persoonvelden (custom_fields):
   - Postcode: 7ef7273df6ea57bf995e06a29f022d94416c5c75
   - Huisnummer: c81fdc0d30a317fa8b487fc267317fc7bd33392c
   - straatnaam: 77c289b9f56ff1d36499712d57d83773889781c1
   - Woonplaats: eabacd3f7b2d596a4e7d5c05dd30d5968a67feba
   Let op: het formulier zet het huisnummer soms in het veld Woonplaats. Is Huisnummer leeg en Woonplaats alleen een nummer (evt. met letter/toevoeging, bv. "12", "4a", "10-2"), gebruik die waarde als huisnummer. Staat er een huisnummer in de dealtitel ("Naam, 12, Groot zakelijk") en ontbreekt het elders, gebruik dat.
   Al compleet (straatnaam, huisnummer, postcode, échte woonplaats gevuld)? Alleen op verwerkt zetten.
3. Draai pdok_adres.py met postcode + huisnummer. Uitkomst:
   - "gevonden": updatePerson met custom_fields straatnaam, Huisnummer, Postcode (format 1234AB) en Woonplaats uit het resultaat, plus postal_address {value: "<volledig>", route, street_number, postal_code, locality, country: "Nederland"}. Bestaat al een andere, ingevulde straat/woonplaats die afwijkt van PDOK, overschrijf niets en meld het.
   - "alleen_straat" (geen huisnummer): vul straatnaam, Postcode en Woonplaats in (Huisnummer leeg laten), zet geen postal_address, en voeg via addNote op de deal toe: "Adres onvolledig: huisnummer ontbreekt. Straat volgens postcode: <straat>, <postcode> <plaats>." Markeer als verwerkt.
   - "meerdere": vul straat, postcode en woonplaats in, en noteer op de deal welke toevoegingen mogelijk zijn. Markeer als verwerkt.
   - "niet_gevonden": verander niets aan de persoon, zet een notitie op de deal "Adres niet gevonden in BAG voor <postcode> <huisnummer> — graag navragen bij klant." Markeer als verwerkt.
   - "fout" (PDOK of netwerk): niets markeren, volgende run opnieuw proberen.
   Geen postcode en geen huisnummer: notitie "Geen adres opgegeven" op de deal, markeer als verwerkt.
4. Voeg verwerkte deal-id's toe aan verwerkt.json en schrijf per lead een regel in log.md: datum-tijd | deal-id | persoon | uitkomst | adres.
5. Eindig met één korte regel: aantal aangevuld / onvolledig / niet gevonden, of "Geen nieuwe SEA-leads".

Grenzen: wijzig alleen de vier adresvelden en postal_address van de persoon en voeg notities toe. Niets aan deals (stage, eigenaar, labels, waarde) of andere persoonsvelden veranderen. Geen mails of berichten sturen. Stuur naar PDOK alleen postcode en huisnummer, nooit naam, mail of telefoon.
