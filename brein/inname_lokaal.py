#!/usr/bin/env python3
"""Haalt in één aanroep alle lokale bronnen van een brein-run op en zet ze als bestanden klaar.

  python3 inname_lokaal.py <uitmap> <pad/naar/snapshot_mail.json> [--zonder-wa] [--zonder-werkdoc]

Wat het doet (alleen lezen, behalve werkdoc_sync.py status, dat de statuscel in de werkdocumenten bijwerkt):
  1. modus.py                      -> modus.json         (bij pauze of kort_na_vorige stopt het hier)
  2. vapp.py mensen                -> app/mensen.json
     vapp.py taken (ook klaar, 3 dagen) -> app/taken.json (open) en app/taken_klaar.json (afgerond, laatste 3 dagen)
     vapp.py dagstatus             -> app/dagstatus.json (eerste run van de dag ook app/dagstatus_gisteren.json)
     vapp.py notities (2 dagen)    -> app/notities.json
     vapp.py voorstellen (14 dagen)-> app/voorstellen.json
     vapp.py instructies (vandaag t/m 3 werkdagen verder) -> app/instructies.json
     vapp.py planning (14 dagen terug t/m 8 weken verder)  -> app/planning.json
     vapp.py bestellingen          -> app/bestellingen.json
  3. dashboard/ops_snapshot.py     -> ops/planning.json, acties.json, excel_projecten.json, financieel.json
  4. Werkdocumenten/werkdoc_sync.py status en open -> werkdoc_status.json, werkdoc_open.json
  5. dashboard/whatsapp_export.py  -> wa/zakelijk.txt, wa/nieuwe_chats.txt (sinds whatsapp_bijgewerkt)
     en als dagtaken true is ook   -> wa7/zakelijk.txt (laatste 7 dagen, voor de afvinkronde)

Print een kort overzicht als JSON. Een bron die niet lukt staat onder "fouten"; de rest gaat door.
De mappen wa en wa7 ruimt `start.py klaar <uitmap>` aan het eind van de run op.
"""
import json
import os
import subprocess
import sys
from datetime import date, datetime, timedelta, timezone

HIER = os.path.dirname(os.path.abspath(__file__))
MAP = os.path.dirname(HIER)
VAPP = os.path.join(MAP, "voorraad-app", "vapp.py")
PY = sys.executable or "python3"
fouten = []


def draai(naam, arg, uit=None, timeout=180):
    """Draait een script; stdout naar bestand `uit` (als opgegeven). Geeft stdout terug, of None bij een fout."""
    try:
        r = subprocess.run([PY] + arg, capture_output=True, text=True, timeout=timeout)
    except Exception as e:  # timeout, script ontbreekt
        fouten.append({"bron": naam, "fout": str(e)[:200]})
        return None
    if r.returncode != 0:
        fouten.append({"bron": naam, "fout": (r.stderr or r.stdout or "rc %d" % r.returncode).strip()[-300:]})
        return None
    if uit:
        with open(uit, "w", encoding="utf-8") as f:
            f.write(r.stdout)
    return r.stdout


def vapp(opdracht, invoer, uit):
    arg = [VAPP, opdracht] + ([json.dumps(invoer)] if invoer is not None else [])
    tekst = draai("vapp.py " + opdracht, arg, uit, timeout=120)
    if tekst is None:
        return None
    try:
        d = json.loads(tekst)
    except json.JSONDecodeError:
        fouten.append({"bron": "vapp.py " + opdracht, "fout": "geen JSON: " + tekst[:120]})
        return None
    if isinstance(d, dict) and d.get("fout"):
        fouten.append({"bron": "vapp.py " + opdracht, "fout": str(d["fout"])[:200]})
        return None
    return d


def werkdagen_verder(d, n):
    while n > 0:
        d += timedelta(days=1)
        if d.weekday() < 5:
            n -= 1
    return d


def werkdagen_tussen(a, b):
    n = 0
    while a < b:
        a += timedelta(days=1)
        if a.weekday() < 5:
            n += 1
    return n


def lijst(d, *sleutels):
    """Haalt een lijst uit de uitvoer, ook als die in een dict is ingepakt."""
    if isinstance(d, list):
        return d
    if isinstance(d, dict):
        for k in sleutels:
            if isinstance(d.get(k), list):
                return d[k]
    return []


def main():
    arg = [a for a in sys.argv[1:] if not a.startswith("--")]
    if len(arg) < 2:
        print(json.dumps({"fout": "gebruik: inname_lokaal.py <uitmap> <snapshot_mail.json> [--zonder-wa] [--zonder-werkdoc]"}))
        return 2
    uit, snap_pad = arg[0], arg[1]
    os.makedirs(os.path.join(uit, "app"), exist_ok=True)
    os.chmod(uit, 0o700)

    # 1. Modus (Nederlandse tijd, welke onderdelen deze run doet)
    tekst = draai("modus.py", [os.path.join(HIER, "modus.py"), snap_pad], os.path.join(uit, "modus.json"), timeout=30)
    if tekst is None:
        print(json.dumps({"fout": "modus.py lukte niet", "fouten": fouten}, ensure_ascii=False))
        return 1
    modus = json.loads(tekst)
    if modus.get("pauze") or modus.get("kort_na_vorige"):
        print(json.dumps({"stop": True, "modus": modus}, ensure_ascii=False, indent=1))
        return 0

    try:
        with open(snap_pad, encoding="utf-8") as f:
            snap = json.load(f)
        if isinstance(snap, dict) and isinstance(snap.get("data"), dict):
            snap = snap["data"]
    except (OSError, json.JSONDecodeError):
        snap = {}
    vandaag = date.fromisoformat(modus["nl_datum"])
    iso = lambda d: d.isoformat()
    overzicht = {"modus": modus, "uitmap": uit, "bestanden": {}}
    b = overzicht["bestanden"]

    # 2. De operatie-app
    app = os.path.join(uit, "app")
    mensen = vapp("mensen", None, os.path.join(app, "mensen.json"))
    alle = vapp("taken", {"ook_klaar_sinds": iso(vandaag - timedelta(days=3))}, os.path.join(app, "taken_alle.json"))
    if alle is not None:
        alle = lijst(alle, "taken")
        open_t = [t for t in alle if t.get("status") != "klaar"]
        klaar_t = [t for t in alle if t.get("status") == "klaar"]
        for naam, rijen in (("taken.json", open_t), ("taken_klaar.json", klaar_t)):
            with open(os.path.join(app, naam), "w", encoding="utf-8") as f:
                json.dump(rijen, f, ensure_ascii=False, indent=1)
        os.remove(os.path.join(app, "taken_alle.json"))
        per = {}
        for t in open_t:
            per[t.get("eigenaar") or "nog te verdelen"] = per.get(t.get("eigenaar") or "nog te verdelen", 0) + 1
        te_laat = {}
        for t in open_t:
            try:
                if t.get("deadline") and werkdagen_tussen(date.fromisoformat(t["deadline"]), vandaag) > 5:
                    te_laat[t.get("eigenaar") or "nog te verdelen"] = te_laat.get(t.get("eigenaar") or "nog te verdelen", 0) + 1
            except ValueError:
                pass
        b["app/taken.json"] = {"open": len(open_t), "per_eigenaar": per, "meer_dan_5_werkdagen_te_laat": te_laat,
                               "op_wacht": sum(1 for t in open_t if t.get("status") == "wacht")}
        b["app/taken_klaar.json"] = {
            "afgerond_laatste_3_dagen": len(klaar_t),
            # voor B2: oude Pipedrive-taken die in de app klaar staan; controleer of ze in Pipedrive ook dicht zijn
            "pd_extern_ids": [t["extern_id"] for t in klaar_t if str(t.get("extern_id") or "").startswith("pd:")],
        }
    d = vapp("dagstatus", {"datum": iso(vandaag)}, os.path.join(app, "dagstatus.json"))
    if d is not None:
        mens = [x for x in lijst(d, "taken") if x.get("door") != "Claude"]
        b["app/dagstatus.json"] = {"regels": len(lijst(d, "taken")), "door_mensen": len(mens), "meldingen": len(lijst(d, "meldingen"))}
    if modus.get("dagtaken"):
        d = vapp("dagstatus", {"datum": iso(vandaag - timedelta(days=1))}, os.path.join(app, "dagstatus_gisteren.json"))
        if d is not None:
            b["app/dagstatus_gisteren.json"] = {"regels": len(lijst(d, "taken")), "meldingen": len(lijst(d, "meldingen"))}
    d = vapp("notities", {"sinds": iso(vandaag - timedelta(days=2))}, os.path.join(app, "notities.json"))
    if d is not None:
        n = lijst(d, "notities")
        b["app/notities.json"] = {"aantal": len(n), "nog_verwerken_ids": [x.get("id") for x in n if x.get("status") == "nieuw"]}
    d = vapp("voorstellen", {"sinds": iso(vandaag - timedelta(days=14))}, os.path.join(app, "voorstellen.json"))
    if d is not None:
        v = lijst(d, "voorstellen")
        tel = {}
        for x in v:
            tel[x.get("status") or "?"] = tel.get(x.get("status") or "?", 0) + 1
        b["app/voorstellen.json"] = {"per_status": tel}
    tot_instr = werkdagen_verder(vandaag, 3)
    d = vapp("instructies", {"van": iso(vandaag), "tot": iso(tot_instr)}, os.path.join(app, "instructies.json"))
    if d is not None:
        i = lijst(d, "instructies")
        grens = iso(werkdagen_verder(vandaag, 1))
        b["app/instructies.json"] = {
            "t/m": iso(tot_instr), "aantal": len(i),
            "niet_vrij_vandaag_of_volgende_werkdag": [
                "%s %s: %s" % (x.get("datum"), x.get("project") or x.get("nummer") or "", x.get("stand"))
                for x in i if x.get("stand") != "vrij" and str(x.get("datum")) <= grens],
        }
    d = vapp("planning", {"van": iso(vandaag - timedelta(days=14)), "tot": iso(vandaag + timedelta(weeks=8))}, os.path.join(app, "planning.json"))
    if d is not None:
        b["app/planning.json"] = {"van": iso(vandaag - timedelta(days=14)), "tot": iso(vandaag + timedelta(weeks=8)), "regels": len(lijst(d, "planning", "dagen"))}
    d = vapp("bestellingen", None, os.path.join(app, "bestellingen.json"))
    if d is not None:
        b["app/bestellingen.json"] = {"aantal": len(lijst(d, "bestellingen"))}
    if mensen is not None:
        b["app/mensen.json"] = {"gebruikersnamen": [m.get("gebruikersnaam") for m in lijst(mensen, "mensen")]}

    # 3. Planning in de oude vorm voor het Operationeel dashboard en de tv (C1)
    ops = os.path.join(uit, "ops")
    tekst = draai("ops_snapshot.py", [os.path.join(MAP, "dashboard", "ops_snapshot.py"), ops], timeout=180)
    if tekst is not None:
        bron = ""
        try:
            with open(os.path.join(ops, "planning.json"), encoding="utf-8") as f:
                bron = str(json.load(f).get("bron", ""))[:120]
        except (OSError, json.JSONDecodeError, AttributeError):
            pass
        b["ops/"] = {"bestanden": sorted(os.listdir(ops)) if os.path.isdir(ops) else [], "bron_planning": bron}

    # 4. Werkdocumenten (A10 en A11)
    if "--zonder-werkdoc" not in sys.argv:
        wd = os.path.join(MAP, "Werkdocumenten", "werkdoc_sync.py")
        tekst = draai("werkdoc_sync.py status", [wd, "status"], os.path.join(uit, "werkdoc_status.json"), timeout=300)
        if tekst is not None:
            b["werkdoc_status.json"] = {"tekens": len(tekst)}
        tekst = draai("werkdoc_sync.py open", [wd, "open"], os.path.join(uit, "werkdoc_open.json"), timeout=300)
        if tekst is not None:
            try:
                p = json.loads(tekst)
                b["werkdoc_open.json"] = {"projecten": [
                    "%s: %d open punten, %d te regelen" % (x.get("project"), len(x.get("open_punten") or []), len(x.get("te_regelen") or []))
                    for x in p if isinstance(x, dict)]}
            except json.JSONDecodeError:
                b["werkdoc_open.json"] = {"tekens": len(tekst)}

    # 5. WhatsApp (A6; en de laatste 7 dagen voor de afvinkronde A2c als dagtaken true is)
    if "--zonder-wa" not in sys.argv:
        wa_script = os.path.join(MAP, "dashboard", "whatsapp_export.py")
        sinds = snap.get("whatsapp_bijgewerkt") or (datetime.now(timezone.utc) - timedelta(days=1)).isoformat(timespec="seconds")
        tekst = draai("whatsapp_export.py", [wa_script, os.path.join(uit, "wa"), str(sinds)], timeout=180)
        if tekst is not None:
            b["wa/"] = {"sinds": str(sinds), "resultaat": tekst.strip()[-200:]}
        if modus.get("dagtaken"):
            week = (datetime.now(timezone.utc) - timedelta(days=7)).isoformat(timespec="seconds")
            tekst = draai("whatsapp_export.py 7 dagen", [wa_script, os.path.join(uit, "wa7"), week], timeout=180)
            if tekst is not None:
                b["wa7/"] = {"sinds": week, "resultaat": tekst.strip()[-200:]}

    overzicht["fouten"] = fouten
    with open(os.path.join(uit, "overzicht.json"), "w", encoding="utf-8") as f:
        json.dump(overzicht, f, ensure_ascii=False, indent=1)
    print(json.dumps(overzicht, ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
