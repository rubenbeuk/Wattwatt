# Watt Watt

Code van de Watt Watt operatie-app en het "brein", overgenomen uit de OneDrive-map "Operationeel Watt Watt Claude".

- `app/` — de operatie-app (voorraad, projecten, planning): PHP-site in `public_html/`, serverscripts in `server/`, beheerhulp `vapp.py`.
- `brein/` — instructies en scripts van de dagelijkse Claude-taak (brein), inclusief de kern-skill (`SKILL_kern.md`) en notities-skill (`SKILL_notities.md`).
- `dashboard/` — scripts die de operatie-snapshot en WhatsApp-export voor het dashboard maken.
- `werkdocumenten/` — scripts voor het maken en synchroniseren van werkdocumenten.
- `adres-aanvullen/` — instructie en PDOK-script om adressen van SEA-leads in Pipedrive aan te vullen.

Data met klant- of persoonsgegevens (`*.json`, logs), echte config (`voorraad-config.php`) en geheimen staan niet in deze repository; zie `.gitignore`.
