#!/usr/bin/env python3
"""Hulpscript voor de bijlagen die Power Automate in Kennisbank Watt Watt/_Binnengekomen zet.

Power Automate (flows "Bijlagen naar Kennisbank - Ruben/Support/Berend/Julian") slaat per bijlage op:
  <yyyyMMdd-HHmmss> <mailbox> <bijlagenaam>
  <yyyyMMdd-HHmmss> <mailbox> <bijlagenaam>.info.txt   (van, aan, onderwerp, ontvangen, mail_id)

Gebruik:
  python3 kennisbank_inbox.py lijst [max]          -> compacte lijst van wat nog gesorteerd moet worden (JSON)
  python3 kennisbank_inbox.py verplaats <bestand> <doelmap>
                                                   -> verplaatst bestand naar doelmap (zonder datum/mailbox-voorvoegsel),
                                                      info naar _Binnengekomen/_verwerkt, regel in <doelmap>/_index.md
Het script opent nooit de inhoud van de bijlage zelf.
"""
import json
import os
import re
import shutil
import sys
from datetime import datetime

ONEDRIVE = "/Users/rubenvanbeukering/Library/CloudStorage/OneDrive-WattWattB.V"
INBOX = os.path.join(ONEDRIVE, "Kennisbank Watt Watt", "_Binnengekomen")
VERWERKT = os.path.join(INBOX, "_verwerkt")
VOORVOEGSEL = re.compile(r"^(\d{8}-\d{6}) (\S+) (.+)$")
TOEGESTAAN = (os.path.join(ONEDRIVE, "Kennisbank Watt Watt"), os.path.join(ONEDRIVE, "Watt Watt projecten Admin"))


def lees_info(pad):
    info = {}
    try:
        with open(pad, encoding="utf-8", errors="replace") as f:
            for regel in f:
                if ":" in regel:
                    k, v = regel.split(":", 1)
                    info[k.strip()] = v.strip()[:300]
    except OSError:
        pass
    return info


def lijst(maximum=50):
    if not os.path.isdir(INBOX):
        print(json.dumps({"fout": "map _Binnengekomen bestaat niet"}))
        return
    uit = []
    for naam in sorted(os.listdir(INBOX)):
        pad = os.path.join(INBOX, naam)
        if naam.startswith(".") or naam.endswith(".info.txt") or os.path.isdir(pad):
            continue
        m = VOORVOEGSEL.match(naam)
        info = lees_info(pad + ".info.txt")
        uit.append({
            "bestand": naam,
            "origineel": m.group(3) if m else naam,
            "ontvangen": m.group(1) if m else "",
            "mailbox": info.get("mailbox") or (m.group(2) if m else ""),
            "van": info.get("van", ""),
            "onderwerp": info.get("onderwerp", ""),
            "mail_id": info.get("mail_id", ""),
            "kb": round(os.path.getsize(pad) / 1024),
            "info_ontbreekt": not os.path.exists(pad + ".info.txt"),
        })
        if len(uit) >= maximum:
            break
    print(json.dumps({"aantal": len(uit), "items": uit}, ensure_ascii=False, indent=1))


def vrije_naam(map_, naam):
    basis, ext = os.path.splitext(naam)
    kandidaat, n = naam, 2
    while os.path.exists(os.path.join(map_, kandidaat)):
        kandidaat = f"{basis} ({n}){ext}"
        n += 1
    return kandidaat


def verplaats(bestand, doelmap):
    bron = os.path.join(INBOX, bestand)
    doelmap = os.path.abspath(doelmap)
    if not doelmap.startswith(TOEGESTAAN):
        sys.exit(f"Doelmap niet toegestaan: {doelmap}")
    if not os.path.isfile(bron):
        sys.exit(f"Niet gevonden: {bron}")
    os.makedirs(doelmap, exist_ok=True)
    m = VOORVOEGSEL.match(bestand)
    schoon = m.group(3) if m else bestand
    doelnaam = vrije_naam(doelmap, schoon)
    shutil.move(bron, os.path.join(doelmap, doelnaam))
    info_pad = bron + ".info.txt"
    info = lees_info(info_pad)
    if os.path.exists(info_pad):
        os.makedirs(VERWERKT, exist_ok=True)
        shutil.move(info_pad, os.path.join(VERWERKT, os.path.basename(info_pad)))
    index = os.path.join(doelmap, "_index.md")
    nieuw = not os.path.exists(index)
    with open(index, "a", encoding="utf-8") as f:
        if nieuw:
            f.write("| Bestand | Ontvangen | Van | Onderwerp | Mailbox |\n|---|---|---|---|---|\n")
        ontvangen = m.group(1) if m else datetime.now().strftime("%Y%m%d-%H%M%S")
        cel = lambda s: str(s).replace("|", "/")
        f.write(f"| {cel(doelnaam)} | {ontvangen} | {cel(info.get('van', ''))} | {cel(info.get('onderwerp', ''))} | {cel(info.get('mailbox', ''))} |\n")
    print(json.dumps({"verplaatst": os.path.join(doelmap, doelnaam)}, ensure_ascii=False))


if __name__ == "__main__":
    if len(sys.argv) >= 2 and sys.argv[1] == "lijst":
        lijst(int(sys.argv[2]) if len(sys.argv) > 2 else 50)
    elif len(sys.argv) == 4 and sys.argv[1] == "verplaats":
        verplaats(sys.argv[2], sys.argv[3])
    else:
        print(__doc__)
