#!/usr/bin/env python3
"""Zoek het volledige Nederlandse adres bij postcode + huisnummer via PDOK Locatieserver.

Gebruik: python3 pdok_adres.py "<postcode>" "<huisnummer incl. toevoeging, mag leeg>"
Uitvoer (JSON): {"status": "gevonden"|"meerdere"|"alleen_straat"|"niet_gevonden"|"fout", ...}
PDOK is het openbare adresregister (BAG) van de overheid; er gaat alleen postcode + huisnummer heen.
"""
import json
import re
import sys
import urllib.parse
import urllib.request

URL = "https://api.pdok.nl/bzk/locatieserver/search/v3_1/free"
FL = "type,weergavenaam,straatnaam,huisnummer,huisletter,huisnummertoevoeging,postcode,woonplaatsnaam"


def zoek(q, fq):
    params = urllib.parse.urlencode({"q": q, "fq": fq, "fl": FL, "rows": 20})
    with urllib.request.urlopen(f"{URL}?{params}", timeout=15) as r:
        return json.load(r)["response"]["docs"]


def main():
    postcode = re.sub(r"\s+", "", (sys.argv[1] if len(sys.argv) > 1 else "")).upper()
    nummer_ruw = (sys.argv[2] if len(sys.argv) > 2 else "").strip()
    if not re.fullmatch(r"\d{4}[A-Z]{2}", postcode):
        print(json.dumps({"status": "niet_gevonden", "reden": f"ongeldige postcode '{postcode}'"}))
        return
    m = re.match(r"^(\d+)\s*[-/ ]?\s*([A-Za-z0-9]*)", nummer_ruw)
    try:
        if not m:
            docs = zoek(f"postcode:{postcode}", "type:postcode")
            if docs:
                d = docs[0]
                print(json.dumps({"status": "alleen_straat", "postcode": d["postcode"],
                                  "straatnaam": d["straatnaam"], "woonplaats": d["woonplaatsnaam"],
                                  "reden": "geen huisnummer bekend"}))
            else:
                print(json.dumps({"status": "niet_gevonden", "reden": "postcode niet in BAG"}))
            return
        nr, toev = m.group(1), m.group(2).lower()
        docs = zoek(f"postcode:{postcode} AND huisnummer:{nr}", "type:adres")
        if not docs:
            print(json.dumps({"status": "niet_gevonden", "reden": f"{postcode} {nr} niet in BAG"}))
            return
        if toev:
            match = [d for d in docs
                     if (d.get("huisletter", "") + d.get("huisnummertoevoeging", "")).lower() == toev
                     or d.get("huisletter", "").lower() == toev
                     or d.get("huisnummertoevoeging", "").lower() == toev]
        else:
            match = [d for d in docs if not d.get("huisletter") and not d.get("huisnummertoevoeging")]
        kies = match[0] if len(match) == 1 else (docs[0] if len(docs) == 1 else None)
        if kies:
            toevoeging = (kies.get("huisletter", "") +
                          ("-" + kies["huisnummertoevoeging"] if kies.get("huisnummertoevoeging") else ""))
            print(json.dumps({"status": "gevonden", "postcode": kies["postcode"],
                              "straatnaam": kies["straatnaam"],
                              "huisnummer": f"{kies['huisnummer']}{toevoeging}",
                              "woonplaats": kies["woonplaatsnaam"],
                              "volledig": kies["weergavenaam"]}))
        else:
            d = docs[0]
            print(json.dumps({"status": "meerdere", "postcode": d["postcode"],
                              "straatnaam": d["straatnaam"], "huisnummer": nr,
                              "woonplaats": d["woonplaatsnaam"],
                              "opties": [x["weergavenaam"] for x in docs],
                              "reden": "meerdere toevoegingen mogelijk"}))
    except Exception as e:  # netwerk of PDOK storing
        print(json.dumps({"status": "fout", "reden": str(e)}))


if __name__ == "__main__":
    main()
