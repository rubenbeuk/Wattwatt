# Waar de instructie van het Watt Watt brein staat

Dit bestand was een kopie van de hele instructie (stand 17-11-2026). Sinds 19-11-2026 is de instructie gesplitst en is dit alleen nog een wegwijzer.

| Wat | Waar | Wanneer gelezen |
|---|---|---|
| Kern | `~/.claude/scheduled-tasks/watt-watt-brein/SKILL.md` | elke run |
| Dagronde (A3c, A4, A8, B4) | `brein/instructie/dagronde.md` | één keer per dag (dagtaken true) |
| Projectbeheer (E1-E5, E7) | `brein/instructie/projectbeheer.md` | eerste werkdagrun |
| @claude-opdrachten | `brein/instructie/opdrachten.md` | alleen als er een opdracht is |
| Overzichtsmail voor Ruben (F2) | `brein/instructie/conceptmail.md` | ochtend- en avondmail |

Scripts in `brein/`:

- `start.py`: startcheck (3-uursregel) en `start.py klaar` aan het eind van een run. Stand in `run_stand.json`.
- `inname_lokaal.py`: haalt in één aanroep de app, de planning voor het oude dashboard, de werkdocumenten en WhatsApp op.
- `vapp_reeks.py`: voert meerdere schrijfopdrachten naar de app in één aanroep uit.
- `modus.py`: Nederlandse tijd en welke onderdelen een run doet.

Oude versies van de instructie staan in `Archief/` (`brein_SKILL_voor_afslanken_2026-11-19.md` is de laatste versie in één bestand).

Regel aanpassen: zet hem in de kern als hij elke run geldt, anders in de juiste module. Lees het bestand eerst opnieuw; meerdere sessies schrijven erin.
