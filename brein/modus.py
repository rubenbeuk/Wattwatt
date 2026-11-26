#!/usr/bin/env python3
"""Bepaalt wat de run van de taak Watt Watt brein moet doen, in Nederlandse tijd.

Gebruik: python3 modus.py [pad/naar/snapshot_mail.json]
Print JSON. Rekenwerk met datums gebeurt hier, niet in het model.
De Mac kan in een andere tijdzone staan; alles gaat in Europe/Amsterdam.
"""
import json
import sys
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

NL = ZoneInfo("Europe/Amsterdam")
DAGEN = ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag", "zaterdag", "zondag"]


def volgende_werkdag(d):
    d = d + timedelta(days=1)
    while d.weekday() >= 5:
        d += timedelta(days=1)
    return d


def werkdagen_later(d, n):
    for _ in range(n):
        d = volgende_werkdag(d)
    return d


def main():
    snap = {}
    if len(sys.argv) > 1:
        try:
            with open(sys.argv[1], encoding="utf-8") as f:
                snap = json.load(f)
            # ArtifactData kan het document inpakken
            if isinstance(snap, dict) and "data" in snap and isinstance(snap["data"], dict):
                snap = snap["data"]
        except (OSError, json.JSONDecodeError):
            snap = {}
    b = (snap or {}).get("brein") or {}
    nu = datetime.now(NL)
    vandaag = nu.date()
    werkdag = vandaag.weekday() < 5
    uur = nu.hour + nu.minute / 60
    vandaag_s = vandaag.isoformat()
    morgen = vandaag + timedelta(days=1)
    vrijdag = vandaag + timedelta(days=(4 - vandaag.weekday()) % 7)
    if vandaag.weekday() == 4:
        vrijdag = vandaag + timedelta(days=7)
    pauze_tot = b.get("pauze_tot")
    pauze_van = b.get("pauze_van")
    pauze = False
    if pauze_tot:
        try:
            van_ok = nu >= datetime.fromisoformat(pauze_van).astimezone(NL) if pauze_van else True
            pauze = van_ok and nu < datetime.fromisoformat(pauze_tot).astimezone(NL)
        except ValueError:
            pauze = False
    # Vorige run: veld bijgewerkt in snapshot/mail (starttijd van die run, UTC).
    # Begon die minder dan 3 uur geleden, dan slaat deze run over (inhaalrun na het openen van de laptop).
    vorige_uren = None
    try:
        if snap.get("bijgewerkt"):
            vorige = datetime.fromisoformat(str(snap["bijgewerkt"]).replace("Z", "+00:00"))
            if vorige.tzinfo is None:
                vorige = vorige.replace(tzinfo=ZoneInfo("UTC"))
            vorige_uren = round((nu - vorige.astimezone(NL)).total_seconds() / 3600, 1)
    except (ValueError, TypeError, AttributeError):
        vorige_uren = None
    uit = {
        # Ruben kan de taak tijdelijk overslaan met brein.pauze_tot in snapshot/mail
        "pauze": pauze,
        "vorige_run_uren": vorige_uren,
        "kort_na_vorige": vorige_uren is not None and 0 <= vorige_uren < 3,
        "pauze_tot": pauze_tot,
        "nl_nu": nu.isoformat(timespec="minutes"),
        "nl_datum": vandaag_s,
        "weekdag": DAGEN[vandaag.weekday()],
        "werkdag": werkdag,
        "utc_nu": nu.astimezone(ZoneInfo("UTC")).isoformat(timespec="seconds"),
        # Eerste run van de dag (vanaf 05:00 NL): terugkijken, deals koppelen, volledige risico-analyse, planninganalyse.
        "dagtaken": uur >= 5 and b.get("dagtaken_datum") != vandaag_s,
        # Projectbeheer (overdrachtsdocument, notities, PRJ-nummers, Pauline): eerste werkdagrun vanaf 05:00 NL.
        "projectbeheer": werkdag and uur >= 5 and b.get("projectbeheer_datum") != vandaag_s,
        # Ochtendmail 'vandaag te doen': werkdag, vanaf 05:00 NL, nog niet gemaakt vandaag.
        "ochtendmail": werkdag and uur >= 5 and b.get("ochtendmail_datum") != vandaag_s,
        # Avondmail 'morgen te doen': vanaf 16:00 NL, als morgen een werkdag is, nog niet gemaakt vandaag.
        "avondmail": uur >= 16 and morgen.weekday() < 5 and b.get("avondmail_datum") != vandaag_s,
        "morgen": morgen.isoformat(),
        "volgende_werkdag": volgende_werkdag(vandaag).isoformat(),
        # Standaarddeadlines per prioriteit (alleen als de bron geen datum noemt)
        "deadline_kritiek": vandaag_s,
        "deadline_hoog": werkdagen_later(vandaag, 2).isoformat(),
        "deadline_normaal": vrijdag.isoformat(),
        "nl_zomertijd": bool(nu.dst()),
        "utc_verschil_uren": int(nu.utcoffset().total_seconds() // 3600),
    }
    print(json.dumps(uit, ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
