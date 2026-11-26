#!/usr/bin/env python3
"""Voert meerdere vapp.py-opdrachten in één aanroep uit (één modelaanroep in plaats van één per taak).

  python3 vapp_reeks.py <bestand.json> [--droog]

Het bestand is een lijst: [{"opdracht": "taak", "invoer": {...}}, {"opdracht": "taak_klaar", "invoer": {...}}, ...]
De opdrachten gaan in volgorde naar vapp.py. Na een fout gaat het script door met de volgende.
Per regel komt er één regel uitvoer: nummer, opdracht, ok of fout, en het begin van het antwoord van de app.
--droog controleert alleen het bestand en voert niets uit.

Niet via dit script: plan_zet (alleen los, na een letterlijke opdracht van Ruben), migreer en mens.
"""
import json
import os
import subprocess
import sys

HIER = os.path.dirname(os.path.abspath(__file__))
# Op de Mac staat vapp.py in <MAP>/voorraad-app, in de repository (cloud) in app/
VAPP = os.environ.get("VAPP") or next((p for p in (os.path.join(os.path.dirname(HIER), "voorraad-app", "vapp.py"),
                                                   os.path.join(os.path.dirname(HIER), "app", "vapp.py")) if os.path.exists(p)),
                                      os.path.join(os.path.dirname(HIER), "voorraad-app", "vapp.py"))
TOEGESTAAN = {
    "taak", "taak_klaar", "taak_samenvoegen", "taak_vervalt", "taak_status", "notitie_verwerkt",
    "plan_voorstel", "dagopdracht", "project_info", "project_bericht", "project_samenvatting", "mijlpaal",
    "bestelling", "geleverd", "materiaallijst", "product",
}
MAXIMUM = 40


def main():
    arg = [a for a in sys.argv[1:] if not a.startswith("--")]
    droog = "--droog" in sys.argv
    if not arg:
        print(json.dumps({"fout": "gebruik: vapp_reeks.py <bestand.json> [--droog]"}))
        return 2
    try:
        with open(arg[0], encoding="utf-8") as f:
            reeks = json.load(f)
    except (OSError, json.JSONDecodeError) as e:
        print(json.dumps({"fout": "bestand niet leesbaar: %s" % e}, ensure_ascii=False))
        return 2
    if not isinstance(reeks, list) or not reeks:
        print(json.dumps({"fout": "het bestand moet een lijst zijn met minstens één opdracht"}))
        return 2
    if len(reeks) > MAXIMUM:
        print(json.dumps({"fout": "maximaal %d opdrachten per bestand; dit zijn er %d" % (MAXIMUM, len(reeks))}))
        return 2
    # Eerst alles controleren, dan pas uitvoeren: een tikfout halverwege mag geen halve reeks opleveren.
    for i, r in enumerate(reeks, 1):
        if not isinstance(r, dict) or r.get("opdracht") not in TOEGESTAAN or not isinstance(r.get("invoer"), dict):
            print(json.dumps({"fout": "regel %d klopt niet: opdracht moet een van %s zijn en invoer een object" % (i, sorted(TOEGESTAAN)),
                              "uitgevoerd": 0}, ensure_ascii=False))
            return 2
    ok = fout = 0
    for i, r in enumerate(reeks, 1):
        if droog:
            print("%d %s droog" % (i, r["opdracht"]))
            continue
        try:
            p = subprocess.run([sys.executable or "python3", VAPP, r["opdracht"], json.dumps(r["invoer"])],
                               capture_output=True, text=True, timeout=120)
            uit = " ".join((p.stdout or p.stderr or "").split())
            mislukt = p.returncode != 0 or uit.startswith('{"fout"') or '"fout":' in uit[:40]
        except Exception as e:
            uit, mislukt = str(e), True
        if mislukt:
            fout += 1
        else:
            ok += 1
        sleutel = r["invoer"].get("extern_id") or r["invoer"].get("id") or r["invoer"].get("project") or ""
        print("%d %s %s %s | %s" % (i, r["opdracht"], sleutel, "FOUT" if mislukt else "ok", uit[:260]))
    print(json.dumps({"ok": ok, "fout": fout, "droog": droog}))
    return 0 if fout == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
