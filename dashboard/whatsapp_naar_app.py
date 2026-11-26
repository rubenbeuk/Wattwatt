#!/usr/bin/env python3
"""Stuurt zakelijke WhatsApp-berichten van deze Mac naar de operatie-app, zodat het brein ze in de cloud kan lezen.

  python3 whatsapp_naar_app.py            normale ronde (draait via launchd elke 15 minuten)
  python3 whatsapp_naar_app.py --droog    laat zien wat er gestuurd zou worden, stuurt niets

Werkt zonder Claude (kost niets). Leest een tijdelijke kopie van de WhatsApp-database; het origineel blijft onaangeraakt.
Wat er naar de server gaat:
  - van elke actieve chat: jid en naam; van een chat die nog niet is ingedeeld ook 3 afgekorte proefregels (om in te delen)
  - berichten alleen uit chats die in de app als zakelijk staan. Privechats worden nooit gelezen of gestuurd.
De indeling (zakelijk/prive) staat in de app; het brein deelt nieuwe chats in met vapp.py whatsapp_indelen.
De indeling in whatsapp_chats.json (grote run op de Mac) en in de app (lichte run) wordt elke ronde naar elkaar overgenomen.
"""
import datetime as dt
import glob
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile

BRON = os.path.expanduser("~/Library/Group Containers/group.net.whatsapp.WhatsApp.shared")
OUDE_INDELING = os.path.expanduser("~/.claude/scheduled-tasks/ops-dashboard-bijwerken/whatsapp_chats.json")
HIER = os.path.dirname(os.path.abspath(__file__))
VAPP = os.path.join(HIER, "..", "voorraad-app", "vapp.py")
STAND = os.path.expanduser("~/.wattwatt_whatsapp_stand.json")
APPLE_EPOCH = 978307200
MAX_TEKENS = 1000
MEDIA = {1: "foto", 2: "video", 3: "spraak", 8: "document"}
EERSTE_KEER_TERUG = 3  # dagen


def kort(tekst, lengte):
    return " ".join((tekst or "").split())[:lengte]


def vapp(opdracht, invoer):
    if not os.path.exists(VAPP):
        raise SystemExit("vapp.py niet gevonden: " + VAPP)
    r = subprocess.run([sys.executable, VAPP, opdracht, json.dumps(invoer, ensure_ascii=False)], capture_output=True, text=True, timeout=180)
    if r.returncode != 0:
        raise SystemExit("vapp.py %s mislukt: %s %s" % (opdracht, r.stdout[:300], r.stderr[:300]))
    return json.loads(r.stdout)


def lees_stand():
    try:
        with open(STAND, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def main():
    droog = "--droog" in sys.argv
    stand = lees_stand()
    nu = dt.datetime.now()
    # een kwartier overlap, zodat niets tussen twee rondes valt; dubbele berichten negeert de app (bron_id)
    sinds = dt.datetime.fromisoformat(stand["tot"]) - dt.timedelta(minutes=15) if stand.get("tot") else nu - dt.timedelta(days=EERSTE_KEER_TERUG)
    grens = sinds.timestamp() - APPLE_EPOCH

    indeling = {c["jid"]: c["soort"] for c in vapp("whatsapp_chats", {})}
    # De grote run op de Mac deelt in via whatsapp_chats.json, de lichte run in de cloud via de app. Hier gaan ze over en weer.
    oud, lokaal = {}, {"zakelijk": {}, "prive": {}}
    if os.path.exists(OUDE_INDELING):
        with open(OUDE_INDELING, encoding="utf-8") as f:
            lokaal = json.load(f)
        oud = {jid: soort for soort in ("zakelijk", "prive") for jid in lokaal.get(soort, {})}

    kopie = tempfile.mkdtemp(prefix="wa-")
    os.chmod(kopie, 0o700)
    chats, berichten = [], []
    bekend_zakelijk, nu_zakelijk = set(stand.get("zakelijk", [])), set()
    try:
        for bestand in glob.glob(os.path.join(BRON, "ChatStorage.sqlite*")):
            shutil.copy2(bestand, kopie)
        db = sqlite3.connect(os.path.join(kopie, "ChatStorage.sqlite"))
        db.row_factory = sqlite3.Row
        actief = db.execute(
            """SELECT s.Z_PK id, s.ZCONTACTJID jid, COALESCE(NULLIF(s.ZPARTNERNAME,''),'naamloos') naam
               FROM ZWACHATSESSION s
               WHERE EXISTS (SELECT 1 FROM ZWAMESSAGE m WHERE m.ZCHATSESSION=s.Z_PK AND m.ZMESSAGEDATE>=?)""",
            (grens,),
        ).fetchall()
        for chat in actief:
            jid, naam = chat["jid"], kort(chat["naam"], 150)
            soort = indeling.get(jid) or oud.get(jid) or "nieuw"
            if soort == "prive":  # privechats gaan nooit naar de server, ook de naam niet
                continue
            item = {"jid": jid, "naam": naam}
            if soort == "zakelijk" and jid not in indeling:
                item["soort"] = "zakelijk"
            if soort == "nieuw":
                proef = db.execute(
                    """SELECT ZISFROMME ik, ZTEXT tekst FROM ZWAMESSAGE
                       WHERE ZCHATSESSION=? AND ZTEXT<>'' AND ZMESSAGETYPE NOT IN (6,10,14)
                       ORDER BY ZMESSAGEDATE DESC LIMIT 3""", (chat["id"],)).fetchall()
                item["proef"] = "\n".join("%s: %s" % ("Ruben" if r["ik"] == 1 else "ander", kort(r["tekst"], 110)) for r in proef)
            chats.append(item)
            if soort != "zakelijk":
                continue
            # pas zakelijk geworden chat: haal ook de dagen ervoor op, zodat het brein de aanloop ziet
            vanaf = grens if jid in bekend_zakelijk or not stand.get("tot") else (nu - dt.timedelta(days=EERSTE_KEER_TERUG)).timestamp() - APPLE_EPOCH
            nu_zakelijk.add(jid)
            for r in db.execute(
                """SELECT m.Z_PK pk, m.ZISFROMME ik, m.ZTEXT tekst, m.ZMESSAGETYPE soort, m.ZMESSAGEDATE d, m.ZGROUPMEMBER groep, p.ZPUSHNAME push
                   FROM ZWAMESSAGE m
                   LEFT JOIN ZWAGROUPMEMBER g ON m.ZGROUPMEMBER=g.Z_PK
                   LEFT JOIN ZWAPROFILEPUSHNAME p ON p.ZJID=g.ZMEMBERJID
                   WHERE m.ZCHATSESSION=? AND m.ZMESSAGEDATE>=? AND m.ZMESSAGETYPE NOT IN (6,10,14)
                   ORDER BY m.ZMESSAGEDATE""", (chat["id"], vanaf)):
                if r["tekst"]:
                    inhoud = kort(r["tekst"], MAX_TEKENS)
                elif r["soort"] in MEDIA:
                    inhoud = "<%s>" % MEDIA[r["soort"]]
                else:
                    continue
                wie = "Ruben" if r["ik"] == 1 else (r["push"] or (naam if not r["groep"] else "groepslid"))
                berichten.append({"bron_id": r["pk"], "jid": jid, "ik": 1 if r["ik"] == 1 else 0, "van": kort(wie, 150), "tekst": inhoud,
                                  "tijd": dt.datetime.fromtimestamp(r["d"] + APPLE_EPOCH).strftime("%Y-%m-%d %H:%M:%S")})
        db.close()
    finally:
        shutil.rmtree(kopie, ignore_errors=True)

    if droog:
        print(json.dumps({"sinds": sinds.isoformat(timespec="minutes"), "chats": len(chats), "berichten": len(berichten),
                          "nog_in_te_delen": sum(1 for c in chats if "proef" in c)}, ensure_ascii=False))
        return
    antwoord = {"nieuwe_chats": 0, "nieuwe_berichten": 0}
    for i in range(0, max(len(berichten), 1), 1000):  # in delen, zodat een grote eerste ronde niet te groot wordt
        a = vapp("whatsapp_in", {"chats": chats if i == 0 else [], "berichten": berichten[i:i + 1000]})
        antwoord["nieuwe_chats"] += a.get("nieuwe_chats", 0)
        antwoord["nieuwe_berichten"] += a.get("nieuwe_berichten", 0)
    namen = {c["jid"]: c["naam"] for c in chats}
    gewijzigd = False
    for jid, soort in indeling.items():
        if soort in ("zakelijk", "prive") and jid not in lokaal.get(soort, {}):
            lokaal.setdefault(soort, {})[jid] = namen.get(jid, jid)
            lokaal.get("prive" if soort == "zakelijk" else "zakelijk", {}).pop(jid, None)
            gewijzigd = True
    if gewijzigd and os.path.isdir(os.path.dirname(OUDE_INDELING)):
        with open(OUDE_INDELING, "w", encoding="utf-8") as f:
            json.dump(lokaal, f, ensure_ascii=False, indent=1, sort_keys=True)
        os.chmod(OUDE_INDELING, 0o600)
    stand.update({"tot": nu.isoformat(timespec="seconds"), "laatste": antwoord,
                  "zakelijk": sorted(bekend_zakelijk | nu_zakelijk | {j for j, s in indeling.items() if s == "zakelijk"})})
    with open(STAND, "w", encoding="utf-8") as f:
        json.dump(stand, f)
    os.chmod(STAND, 0o600)
    print("%s chats bekeken, %d nieuwe berichten, %d nieuwe chats" % (len(chats), antwoord["nieuwe_berichten"], antwoord["nieuwe_chats"]))


if __name__ == "__main__":
    main()
