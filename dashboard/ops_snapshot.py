#!/usr/bin/env python3
"""Maakt de databasebestanden voor het Operationeel dashboard.

Gebruik: python3 ops_snapshot.py <uitvoermap>
Leest: de planning uit de operatie-app (via voorraad-app/vapp.py planning; die is leidend sinds 16-11-2026),
de planning-Excel (alleen nog voor de projectentabel) en de CFO-momentopnamen.
Schrijft planning.json, acties.json, excel_projecten.json en financieel.json in de uitvoermap.
Past de Excel, Pipedrive en de CFO-map niet aan.
"""
import datetime as dt
import json
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
CFO = os.path.expanduser(
    "~/Library/CloudStorage/OneDrive-WattWattB.V/Financiën/CFO/werkbestanden/dashboard"
)

# Tekst in planning en administratie -> Pipedrive-project. Namen zijn vrije tekst, dus dit blijft handwerk.
KOPPELING = [
    ["Tolpoort", 216], ["Korse", 216], ["Oostendorp", 217], ["Heijden", 218], ["Venttri", 218],
    ["Plastimap", 218], ["PGHBV", 218], ["Bouthoorn", 225], ["Schooten", 187], ["Hove", 222],
    ["Hoeve", 222], ["Wolswinkel", 213], ["Woswinkel", 213], ["wolswinkel", 213], ["Verdoold", 214],
    ["verdoold", 214], ["Bosma", 195], ["Venrooy", 164], ["venrooy", 164], ["Aalbers", 201],
    ["PRJ-271", 201], ["Dijk en Waard", 202], ["IEO", 196], ["Marees Hoek", 211], ["Marees 247", 198],
    ["2mWh Marees", 198], ["Avia Capelle", 199], ["Avia Zwijndrecht", 200], ["Verloop", 140], ["Leeweg 19", 140],
]


def project_id(tekst):
    tekst = tekst or ""
    for sleutel, pid in KOPPELING:
        if sleutel in tekst:
            return pid
    return None


def lees_cfo(naam):
    pad = os.path.join(CFO, naam)
    with open(pad, encoding="utf-8") as f:
        data = json.load(f)
    peil = dt.datetime.fromtimestamp(os.path.getmtime(pad)).isoformat(timespec="minutes")
    return data, peil


BEDRAG = re.compile(r"(?:€|EUR)\s?\d[\d.,]*(?:\s?(?:excl\.?|incl\.?)(?:\s?btw)?)?", re.I)


def zonder_bedragen(waarde):
    """Bedragen horen alleen in prive/financieel, niet in teksten uit de Excel."""
    if isinstance(waarde, str):
        return BEDRAG.sub("[bedrag]", waarde)
    if isinstance(waarde, list):
        return [zonder_bedragen(x) for x in waarde]
    if isinstance(waarde, dict):
        return {k: zonder_bedragen(v) for k, v in waarde.items()}
    return waarde


def schrijf(uit, naam, inhoud):
    if naam != "financieel.json":
        inhoud = zonder_bedragen(inhoud)
    with open(os.path.join(uit, naam), "w", encoding="utf-8") as f:
        json.dump(inhoud, f, ensure_ascii=False, indent=1, default=str)


KOLOM = {"boris": "Boris", "joey": "Joey", "sander": "Sander", "julian": "Jules"}
DAGNAMEN = ["Maandag", "Dinsdag", "Woensdag", "Donderdag", "Vrijdag", "Zaterdag", "Zondag"]


def app_planning(van, tot):
    """Planning uit de app, in dezelfde vorm als de oude Excel-rijen (kolommen Boris, Joey, Sander, Jules)."""
    r = subprocess.run(
        [sys.executable, os.path.join(ROOT, "voorraad-app", "vapp.py"), "planning", json.dumps({"van": van, "tot": tot})],
        capture_output=True, text=True, check=True, cwd=ROOT, timeout=120,
    )
    rijen = json.loads(r.stdout)
    if not isinstance(rijen, list):
        raise RuntimeError(str(rijen)[:200])

    def tekst(x):
        naam = re.split(r"\s[–—-]\s", x.get("projectnaam") or "", maxsplit=1)[0].strip() or (x.get("project") or "")
        delen = [d for d in (naam, x.get("tekst") or "") if d]
        return " · ".join(delen) + (" (voorbehoud)" if x.get("voorbehoud") else "")

    per = {}
    for x in rijen:
        kol = KOLOM.get(x["wie"])
        if kol:
            per.setdefault(x["datum"], {}).setdefault(kol, {})[x["dagdeel"]] = tekst(x)
    dagen = []
    d = dt.date.fromisoformat(van)
    while d.isoformat() <= tot:
        if d.weekday() < 6:
            rij = {"datum": d.isoformat(), "dag": DAGNAMEN[d.weekday()]}
            for kol, dd in per.get(d.isoformat(), {}).items():
                o, m = dd.get("ochtend", ""), dd.get("middag", "")
                rij[kol] = o if o == m else " / ".join(t for t in (o and "ochtend: " + o, m and "middag: " + m) if t)
            dagen.append(rij)
        d += dt.timedelta(days=1)
    return dagen


def main():
    uit = sys.argv[1]
    os.makedirs(uit, exist_ok=True)
    nu = dt.datetime.now().astimezone().isoformat(timespec="minutes")
    vandaag = dt.date.today()

    dump = subprocess.run(
        [sys.executable, os.path.join(ROOT, "planning_tool.py"), "dump"],
        capture_output=True, text=True, check=True, cwd=ROOT,
    )
    p = json.loads(dump.stdout)

    van = (vandaag - dt.timedelta(days=7)).isoformat()
    tot = (vandaag + dt.timedelta(days=56)).isoformat()
    try:
        dagen, bron = app_planning(van, tot), "Operatie-app (voorraad.ruben-06c.workers.dev)"
    except Exception as e:  # de app is leidend; lukt het niet, dan de laatste stand uit de Excel met een duidelijke bron
        dagen = [r for r in p["planning"] if van <= r["datum"] <= tot]
        bron = "LET OP: app niet bereikbaar (%s); dit is de OUDE planning-Excel" % str(e)[:80]
    schrijf(uit, "planning.json", {"bijgewerkt": nu, "bron": bron, "dagen": dagen, "koppeling": KOPPELING})

    # Actiepunten staan sinds 16-11-2026 als taken in de operatie-app, niet meer in de Excel.
    schrijf(uit, "acties.json", {"bijgewerkt": nu, "bron": "Verhuisd naar de operatie-app (Mijn dag en Team)", "items": []})

    rijen = []
    for r in p["projecten"]:
        if r["Project"] == "Totaal":
            continue
        rijen.append({
            "project": r["Project"], "project_id": project_id(r["Project"]),
            "uitvoering": r["Uitvoering volgens planning"], "ploeg": r["Ploeg"],
            "status": r["Status planning"], "deadline": r["Deadline / mijlpaal"],
            "volgende_stap": r["Volgende stap"], "risico": r["Risico / aandachtspunt"],
        })
    schrijf(uit, "excel_projecten.json", {"bijgewerkt": nu, "bron": "Tabblad Projecten", "items": rijen})

    # Alleen projectniveau. Banksaldo, lonen, klantsaldi en crediteuren blijven hier bewust buiten.
    fin = {}
    projecten, peil = lees_cfo("projecten.json")
    for x in projecten:
        pid = project_id(x.get("project"))
        sleutel = str(pid) if pid else "deal-%s" % x.get("deal_id")
        fin.setdefault(sleutel, {}).update({
            "naam": x.get("project"), "deal_id": x.get("deal_id"), "dealwaarde": x.get("dealwaarde"),
            "gefactureerd": x.get("gefactureerd_incl_augokt"), "signaal": x.get("signaal") or "",
        })
    detail, _ = lees_cfo("prognose_detail.json")
    for x in detail:
        pid = project_id(x.get("project"))
        if pid:
            r = fin.setdefault(str(pid), {"naam": x.get("project")})
            r["oplevermaand"] = x.get("maand")
            r["nog_te_factureren"] = (r.get("nog_te_factureren") or 0) + (x.get("nog_te_factureren") or 0)
    inkoop, _ = lees_cfo("inkoop_nog.json")
    for x in inkoop:
        pid = project_id(x.get("project"))
        if pid:
            r = fin.setdefault(str(pid), {"naam": x.get("project")})
            r["inkoop_open"] = bool(r.get("inkoop_open")) or bool(x.get("nog_in_te_kopen"))
    schrijf(uit, "financieel.json", {"bijgewerkt": nu, "peildatum_cfo": peil, "bron": "CFO-momentopname", "per_project": fin})
    print("Klaar:", uit)


if __name__ == "__main__":
    main()
