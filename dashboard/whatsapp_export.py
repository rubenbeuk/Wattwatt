#!/usr/bin/env python3
"""Zet nieuwe WhatsApp-berichten uit zakelijke chats klaar voor de dashboardtaak.

Gebruik: python3 whatsapp_export.py <uitvoermap> <sinds ISO-tijd>
Chat indelen: python3 whatsapp_export.py --indelen zakelijk|prive "<naam>" <jid>

Leest een tijdelijke kopie van de database van de WhatsApp-app op deze Mac; het origineel blijft
onaangeraakt. Privechats worden niet geopend. Chats die nog niet zijn ingedeeld komen met maximaal
3 afgekorte berichten in nieuwe_chats.txt. Schrijft zakelijk.txt en nieuwe_chats.txt.
"""
import datetime as dt
import glob
import json
import os
import shutil
import sqlite3
import sys

BRON = os.path.expanduser("~/Library/Group Containers/group.net.whatsapp.WhatsApp.shared")
CHATS = os.path.expanduser("~/.claude/scheduled-tasks/ops-dashboard-bijwerken/whatsapp_chats.json")
APPLE_EPOCH = 978307200
MAX_TEKENS = 300
MEDIA = {1: "foto", 2: "video", 3: "spraak", 8: "document"}


def lees_chats():
    if not os.path.exists(CHATS):
        return {"zakelijk": {}, "prive": {}}
    with open(CHATS, encoding="utf-8") as f:
        return json.load(f)


def schrijf_chats(chats):
    with open(CHATS, "w", encoding="utf-8") as f:
        json.dump(chats, f, ensure_ascii=False, indent=1, sort_keys=True)
    os.chmod(CHATS, 0o600)


def kort(tekst, lengte=MAX_TEKENS):
    return " ".join((tekst or "").split())[:lengte]


def afzender(rij, naam):
    if rij["ik"] == 1:
        return "Ruben"
    return rij["push"] or (naam if not rij["groep"] else "groepslid")


def main():
    uit, sinds = sys.argv[1], sys.argv[2]
    os.makedirs(uit, exist_ok=True)
    os.chmod(uit, 0o700)
    grens = dt.datetime.fromisoformat(sinds).timestamp() - APPLE_EPOCH

    chats = lees_chats()
    kopie = os.path.join(uit, "kopie")
    os.makedirs(kopie, exist_ok=True)
    try:
        for bestand in glob.glob(os.path.join(BRON, "ChatStorage.sqlite*")):
            shutil.copy2(bestand, kopie)
        db = sqlite3.connect(os.path.join(kopie, "ChatStorage.sqlite"))
        db.row_factory = sqlite3.Row
        actief = db.execute(
            """SELECT s.Z_PK id, s.ZCONTACTJID jid, s.ZSESSIONTYPE type, COALESCE(NULLIF(s.ZPARTNERNAME,''),'naamloos') naam
               FROM ZWACHATSESSION s
               WHERE EXISTS (SELECT 1 FROM ZWAMESSAGE m WHERE m.ZCHATSESSION=s.Z_PK AND m.ZMESSAGEDATE>=?)""",
            (grens,),
        ).fetchall()

        zakelijk, nieuw, aantal = [], [], 0
        for chat in actief:
            jid, naam = chat["jid"], kort(chat["naam"], 80)
            if jid in chats["prive"]:
                continue
            if jid in chats["zakelijk"]:
                rijen = db.execute(
                    """SELECT m.ZISFROMME ik, m.ZTEXT tekst, m.ZMESSAGETYPE soort, m.ZMESSAGEDATE d, m.ZGROUPMEMBER groep, p.ZPUSHNAME push
                       FROM ZWAMESSAGE m
                       LEFT JOIN ZWAGROUPMEMBER g ON m.ZGROUPMEMBER=g.Z_PK
                       LEFT JOIN ZWAPROFILEPUSHNAME p ON p.ZJID=g.ZMEMBERJID
                       WHERE m.ZCHATSESSION=? AND m.ZMESSAGEDATE>=? AND m.ZMESSAGETYPE NOT IN (6,10,14)
                       ORDER BY m.ZMESSAGEDATE""",
                    (chat["id"], grens),
                ).fetchall()
                regels, vorige, herhaald = [], None, 0
                for r in rijen:
                    wie = afzender(r, naam)
                    if r["tekst"]:
                        inhoud = kort(r["tekst"])
                    elif r["soort"] in MEDIA:
                        inhoud = "<%s>" % MEDIA[r["soort"]]
                    else:
                        continue
                    if inhoud.startswith("<") and vorige == (wie, inhoud):
                        herhaald += 1
                        regels[-1] = regels[-1].split(" x")[0] + " x%d" % (herhaald + 1)
                        continue
                    herhaald, vorige = 0, (wie, inhoud)
                    tijd = dt.datetime.fromtimestamp(r["d"] + APPLE_EPOCH).strftime("%d-%m %H:%M")
                    regels.append("%s %s: %s" % (tijd, wie, inhoud))
                    aantal += 1
                if regels:
                    zakelijk.append("=== %s\n%s" % (naam, "\n".join(regels)))
            else:
                proef = db.execute(
                    """SELECT ZISFROMME ik, ZTEXT tekst FROM ZWAMESSAGE
                       WHERE ZCHATSESSION=? AND ZTEXT<>'' AND ZMESSAGETYPE NOT IN (6,10,14)
                       ORDER BY ZMESSAGEDATE DESC LIMIT 3""",
                    (chat["id"],),
                ).fetchall()
                nieuw.append("jid=%s | %s\n%s" % (jid, naam, "\n".join(
                    "  %s: %s" % ("Ruben" if r["ik"] == 1 else "ander", kort(r["tekst"], 110)) for r in proef)))
        db.close()
    finally:
        shutil.rmtree(kopie, ignore_errors=True)

    with open(os.path.join(uit, "zakelijk.txt"), "w", encoding="utf-8") as f:
        f.write("\n\n".join(zakelijk))
    with open(os.path.join(uit, "nieuwe_chats.txt"), "w", encoding="utf-8") as f:
        f.write("\n\n".join(nieuw))
    print("Zakelijke chats met nieuws: %d, berichten: %d, nog in te delen: %d" % (len(zakelijk), aantal, len(nieuw)))


if __name__ == "__main__":
    if len(sys.argv) >= 3 and sys.argv[1] == "--indelen":
        # whatsapp_export.py --indelen zakelijk|prive "<naam>" <jid>
        chats = lees_chats()
        chats[sys.argv[2]][sys.argv[4]] = sys.argv[3]
        schrijf_chats(chats)
        print("Ingedeeld:", sys.argv[3], "->", sys.argv[2])
    else:
        main()
