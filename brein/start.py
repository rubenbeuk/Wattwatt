#!/usr/bin/env python3
"""Goedkope startcheck van het Watt Watt brein. Eerste en laatste handeling van elke run.

  python3 start.py                  beslist of deze run doorgaat en legt de start vast
  python3 start.py klaar [uitmap]   legt vast dat de run af is; ruimt in de uitmap de WhatsApp-mappen op
  python3 start.py stand            toont alleen de stand, legt niets vast
  python3 start.py --toch           gaat altijd door (alleen als Ruben er in een chat zelf om vraagt)

Regels (besluit Ruben 17-11-2026, kosten):
- De vorige afgeronde run begon minder dan 3 uur geleden: overslaan.
- Er is minder dan 60 minuten geleden een run begonnen die nog niet af is: overslaan (die loopt waarschijnlijk nog).
Een run die is afgebroken telt dus niet mee voor de 3 uur.

De stand staat in run_stand.json naast dit script. Alles in UTC; de melding in Nederlandse tijd.
"""
import json
import os
import shutil
import sys
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

HIER = os.path.dirname(os.path.abspath(__file__))
STAND = os.path.join(HIER, "run_stand.json")
NL = ZoneInfo("Europe/Amsterdam")
UREN_TUSSEN_RUNS = 3
MINUTEN_LOPENDE_RUN = 60


def lees():
    try:
        with open(STAND, encoding="utf-8") as f:
            s = json.load(f)
        return s if isinstance(s, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def schrijf(s):
    tmp = STAND + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(s, f, ensure_ascii=False, indent=1)
    os.replace(tmp, STAND)


def tijd(s):
    try:
        t = datetime.fromisoformat(str(s).replace("Z", "+00:00"))
        return t if t.tzinfo else t.replace(tzinfo=timezone.utc)
    except (ValueError, TypeError):
        return None


def main():
    arg = sys.argv[1:]
    nu = datetime.now(timezone.utc)
    s = lees()
    gestart, klaar_start = tijd(s.get("gestart")), tijd(s.get("klaar_van_start"))

    if arg[:1] == ["klaar"]:
        s["klaar_van_start"] = s.get("gestart") or nu.isoformat(timespec="seconds")
        s["klaar_op"] = nu.isoformat(timespec="seconds")
        schrijf(s)
        opgeruimd = []
        if len(arg) > 1 and os.path.isdir(arg[1]):
            for naam in ("wa", "wa7"):
                pad = os.path.join(arg[1], naam)
                if os.path.isdir(pad):
                    shutil.rmtree(pad, ignore_errors=True)
                    opgeruimd.append(naam)
        print(json.dumps({"vastgelegd": "run klaar", "opgeruimd": opgeruimd}, ensure_ascii=False))
        return

    uit = {"overslaan": False, "nl_nu": nu.astimezone(NL).isoformat(timespec="minutes")}
    if klaar_start:
        uren = (nu - klaar_start).total_seconds() / 3600
        uit["vorige_run_uren"] = round(uren, 1)
        if 0 <= uren < UREN_TUSSEN_RUNS:
            uit["overslaan"] = True
            uit["melding"] = "Overgeslagen: vorige run %s uur geleden." % str(round(uren, 1)).replace(".", ",")
    if not uit["overslaan"] and gestart and gestart != klaar_start:
        minuten = (nu - gestart).total_seconds() / 60
        if 0 <= minuten < MINUTEN_LOPENDE_RUN:
            uit["overslaan"] = True
            uit["melding"] = "Overgeslagen: er begon %d minuten geleden een run die nog niet af is." % minuten

    if "--toch" in arg:
        uit["overslaan"] = False
        uit.pop("melding", None)
    if arg[:1] == ["stand"]:
        uit["stand"] = s
    elif not uit["overslaan"]:
        s["gestart"] = nu.isoformat(timespec="seconds")
        schrijf(s)
    print(json.dumps(uit, ensure_ascii=False))


if __name__ == "__main__":
    main()
