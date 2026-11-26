#!/usr/bin/env python3
"""Koppelt de materiaallijst in een werkdocument (.docx) aan de voorraadapp.

  python3 werkdoc_sync.py status            alle projectmappen: status uit de app in het document zetten
  python3 werkdoc_sync.py status PRJ-284    één project
  python3 werkdoc_sync.py naar-app PRJ-284  regels uit het document in de app zetten (alleen wat er nog niet staat)
  python3 werkdoc_sync.py open              alle projectmappen: wat staat er nog open (open punten, te regelen, materiaal)
  python3 werkdoc_sync.py naar-app PRJ-284 --vervang   de hele lijst in de app vervangen door die uit het document
  python3 werkdoc_sync.py artikelen         artikelen uit nieuwe_artikelen.json aanmaken in de app en de WW-code in de documenten zetten
  voeg --droog toe om alleen te laten zien wat er zou gebeuren

Het script past in het document alleen de cel "Status" van de materiaallijst aan. De rest blijft zoals het is.
Status gaat alleen vooruit: Bestellen -> Besteld -> Binnen. "Navragen" en "Let op" blijven staan.
Uitvoer is JSON.
"""
import html, json, os, re, subprocess, sys, tempfile, zipfile

HIER = os.path.dirname(os.path.abspath(__file__))
VAPP = os.path.join(os.path.dirname(HIER), "voorraad-app", "vapp.py")
KLEUR = {"Bestellen": "FF9B9B", "Besteld": "F5A6E6", "Binnen": "A8E6A1"}
VOLGORDE = ["Bestellen", "Besteld", "Binnen"]
EENHEID = {"st": "stuks", "stk": "stuks", "stuk": "stuks", "stuks": "stuks", "m": "meter", "meter": "meter"}


def norm(t):
    return re.sub(r"[^a-z0-9]+", " ", t.lower().replace("²", "2")).strip()


def tekst(xml):
    return html.unescape("".join(re.findall(r"<w:t(?:\s[^>]*)?>([^<]*)</w:t>", xml))).strip()


def vapp(opdracht, invoer=None):
    arg = [sys.executable, VAPP, opdracht]
    pad = None
    if invoer is not None:
        f = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8")
        json.dump(invoer, f, ensure_ascii=False); f.close(); pad = f.name
        arg.append(pad)
    try:
        r = subprocess.run(arg, capture_output=True, text=True, timeout=150)
    finally:
        if pad: os.unlink(pad)
    try:
        return json.loads(r.stdout)
    except ValueError:
        return {"fout": (r.stderr or r.stdout or "geen antwoord van de voorraadapp").strip()[:300]}


def documenten(alleen=None):
    for map_ in sorted(os.listdir(HIER)):
        m = re.match(r"PRJ-(\d+)", map_)
        if not m or not os.path.isdir(os.path.join(HIER, map_)): continue
        prj = "PRJ-" + m.group(1)
        if alleen and prj != alleen: continue
        for f in sorted(os.listdir(os.path.join(HIER, map_))):
            if f.lower().endswith("werkdocument.docx") and not f.startswith("~$"):
                yield prj, os.path.join(HIER, map_, f)


def lees(pad):
    """Geeft (document.xml, rijen). Een rij: begin/eind van de statuscel in de xml en de celteksten."""
    xml = zipfile.ZipFile(pad).read("word/document.xml").decode("utf-8")
    return xml, lees_xml(xml)


def lees_xml(xml):
    for t in re.finditer(r"<w:tbl>.*?</w:tbl>", xml, re.S):
        rijen = list(re.finditer(r"<w:tr[ >].*?</w:tr>", t.group(0), re.S))
        if not rijen: continue
        kop = [tekst(c) for c in re.findall(r"<w:tc>.*?</w:tc>", rijen[0].group(0), re.S)]
        if len(kop) < 4 or kop[0] != "Artikel" or "Status" not in kop: continue
        si = kop.index("Status")
        uit = []
        for r in rijen[1:]:
            cellen = list(re.finditer(r"<w:tc>.*?</w:tc>", r.group(0), re.S))
            if len(cellen) <= si: continue  # tussenkop
            basis = t.start() + r.start()
            c = cellen[si]
            k = cellen[2]
            uit.append({"van": basis + c.start(), "tot": basis + c.end(), "cel": c.group(0),
                        "code_van": basis + k.start(), "code_tot": basis + k.end(), "code_cel": k.group(0),
                        "artikel": tekst(cellen[0].group(0)), "aantal": tekst(cellen[1].group(0)),
                        "code": tekst(cellen[2].group(0)), "status": tekst(c.group(0))})
        return uit
    return None


def aantal_eenheid(t):
    m = re.match(r"\s*(\d+(?:[.,]\d+)?)\s*([A-Za-zé]*)", t)
    if not m or "‹" in t: return None, None
    e = m.group(2).lower()
    return float(m.group(1).replace(",", ".")), EENHEID.get(e, e or "stuks")


def artikelcode(t):
    for c in re.findall(r"\b[A-Z]{0,4}\d[A-Z0-9]{4,}\b", t):
        if not re.match(r"WW\d+$", c): return c  # WW-codes zijn van ons magazijn, geen leverancierscode
    return ""


def status_uit_app(r):
    nodig = float(r.get("nodig") or 0)
    if nodig <= 0: return None
    gedekt = float(r.get("gepakt") or 0) + float(r.get("klaargezet") or 0) + float(r.get("op_project_geleverd") or 0)
    if gedekt >= nodig: return "Binnen"
    if float(r.get("nog_bestellen") or 0) > 0: return "Bestellen"
    return "Besteld"


def zoek(rij, app):
    n = norm(rij["artikel"])
    for r in app:  # "regel" is de naam uit het document; "omschrijving" kan de productnaam uit de voorraad zijn
        if n in (norm(r.get("regel") or ""), norm(r.get("omschrijving") or "")): return r
    codes = set(re.findall(r"[A-Z0-9]{5,}", rij["code"]))  # WW-code, EAN of artikelnummer in de kolom "Artikelnummer / link"
    for r in app:
        if codes & {r.get("rexel_code"), r.get("wwcode"), r.get("ean")} - {"", None}: return r
    return None


def nieuwe_cel(oud, status):
    w = re.search(r"<w:tcW[^>]*/>", oud)
    return ('<w:tc><w:tcPr>%s<w:shd w:val="clear" w:color="auto" w:fill="%s"/></w:tcPr><w:p><w:pPr>'
            '<w:spacing w:before="30" w:after="30"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr>'
            '<w:t>%s</w:t></w:r></w:p></w:tc>') % (w.group(0) if w else "", KLEUR[status], status)


def schrijf(pad, xml):
    tmp = pad + ".tmp"
    with zipfile.ZipFile(pad) as zin, zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as zuit:
        for i in zin.infolist():
            zuit.writestr(i, xml.encode("utf-8") if i.filename == "word/document.xml" else zin.read(i.filename))
    os.replace(tmp, pad)


def open_in_word(pad):
    return os.path.exists(os.path.join(os.path.dirname(pad), "~$" + os.path.basename(pad)[2:]))


def status(prj, pad, droog):
    uit = {"project": prj, "document": os.path.basename(pad), "gewijzigd": [], "niet_in_app": []}
    xml, rijen = lees(pad)
    if rijen is None: uit["fout"] = "geen materiaallijst gevonden (tabel met kolommen Artikel en Status)"; return uit
    p = vapp("project", {"project": prj})
    if "fout" in p: uit["fout"] = p["fout"]; return uit
    app = p.get("regels", [])
    for rij in reversed(rijen):  # van achter naar voren, dan blijven de posities kloppen
        r = zoek(rij, app)
        if not r:
            if rij["status"] == "Bestellen": uit["niet_in_app"].append(rij["artikel"])
            continue
        nieuw = status_uit_app(r)
        if not nieuw or rij["status"] not in VOLGORDE or VOLGORDE.index(nieuw) <= VOLGORDE.index(rij["status"]): continue
        xml = xml[:rij["van"]] + nieuwe_cel(rij["cel"], nieuw) + xml[rij["tot"]:]
        uit["gewijzigd"].append({"artikel": rij["artikel"], "van": rij["status"], "naar": nieuw, "leverdatum": r.get("leverdatum")})
    uit["gewijzigd"].reverse(); uit["niet_in_app"].reverse()
    if uit["gewijzigd"] and not droog:
        if open_in_word(pad): uit["overgeslagen"] = "document staat open in Word; volgende keer opnieuw"
        else: schrijf(pad, xml)
    return uit


def magazijnproduct(celtekst):
    """Staat er een WW-code in de kolom 'Artikelnummer / link', zoek dan dat product in de voorraad."""
    m = re.search(r"\bWW\d{3,}\b", celtekst)
    if not m: return None
    r = vapp("zoek", {"q": m.group(0)})
    for p in r if isinstance(r, list) else []:
        if p.get("code") == m.group(0): return p
    return None


def naar_app(prj, pad, droog, vervang=False):
    uit = {"project": prj, "document": os.path.basename(pad), "vervang": vervang}
    _, rijen = lees(pad)
    if rijen is None: uit["fout"] = "geen materiaallijst gevonden"; return uit
    p = vapp("project", {"project": prj})
    app = p.get("regels", []) if "fout" not in p else []
    if vervang and any(float(r.get("gepakt") or 0) > 0 for r in app):
        uit["fout"] = "er is al materiaal gepakt op dit project; niet vervangen"; return uit
    regels, vragen = [], []
    for rij in rijen:
        if rij["status"] not in ("Bestellen", "Binnen") or (not vervang and zoek(rij, app)): continue
        n, e = aantal_eenheid(rij["aantal"])
        if n is None:
            if rij["status"] == "Bestellen": vragen.append(rij["artikel"])
            continue
        regel = {"omschrijving": rij["artikel"], "aantal": n, "eenheid": e}
        if artikelcode(rij["code"]): regel["code"] = artikelcode(rij["code"])
        prod = magazijnproduct(rij["code"])
        if prod and prod.get("ean"): regel["ean"] = prod["ean"]
        elif prod: regel["omschrijving"] = prod["naam"]  # zonder EAN koppelt de app op de exacte productnaam
        # "Binnen" kan twee dingen zijn: klaargezet uit ons magazijn (reservering, die moet blijven)
        # of echt al geleverd. Wat de app nu als klaargezet of gepakt kent, blijft een reservering.
        nu = zoek(rij, app)
        uit_magazijn = bool(nu) and (float(nu.get("klaargezet") or 0) > 0 or float(nu.get("gepakt") or 0) > 0)
        if rij["status"] == "Binnen" and not uit_magazijn: regel["al_binnen"] = True
        regels.append(regel)
    uit["regels"], uit["zonder_aantal"] = regels, vragen
    if regels and not droog:
        kaart = zipfile.ZipFile(pad).read("word/document.xml").decode("utf-8")
        titel = re.search(r"PRJ-\d+ · ([^<]+)", html.unescape(kaart))
        uit["app"] = vapp("materiaallijst", {"project": prj, "omschrijving": titel.group(1).strip() if titel else "",
                                            "bron": "Werkdocument " + os.path.basename(pad), "vervang": vervang, "regels": regels})
        if isinstance(uit["app"], dict): uit["app"] = uit["app"].get("resultaat", {k: v for k, v in uit["app"].items() if k != "overzicht"})
    return uit


def schoon(t):
    """Grijze invultekst ('wie', 'invullen', 'datum invullen') telt als leeg."""
    t = t.strip()
    return "" if t.lower() in ("wie", "datum", "invullen", "naam", "namen") or t.lower().endswith("invullen") else t


def tabellen(xml):
    for t in re.finditer(r"<w:tbl>.*?</w:tbl>", xml, re.S):
        rijen = [[tekst(c) for c in re.findall(r"<w:tc>.*?</w:tc>", r, re.S)] for r in re.findall(r"<w:tr[ >].*?</w:tr>", t.group(0), re.S)]
        if rijen: yield rijen


def open_punten(prj, pad, droog=False):
    """Wat staat er nog open in het werkdocument: open punten, stappen om te regelen, materiaal."""
    uit = {"project": prj, "document": os.path.basename(pad), "gewijzigd_op": __import__("time").strftime("%Y-%m-%d", __import__("time").localtime(os.path.getmtime(pad))),
           "kaart": {}, "open_punten": [], "te_regelen": [], "materiaal": {}}
    xml = zipfile.ZipFile(pad).read("word/document.xml").decode("utf-8")
    eerste = True
    for rijen in tabellen(xml):
        kop = rijen[0]
        if eerste and len(kop) == 4:  # projectkaart: label, waarde, label, waarde
            for r in rijen:
                for i in (0, 2):
                    if i + 1 < len(r) and r[i]: uit["kaart"][r[i]] = schoon(r[i + 1])
        eerste = False
        if kop[:2] == ["Nr", "Punt"] and "Status" in kop:
            si, wi, ui, ai = kop.index("Status"), kop.index("Wie"), kop.index("Uiterlijk"), len(kop) - 1
            for r in rijen[1:]:
                if len(r) > si and r[1] and r[si] == "Open":
                    uit["open_punten"].append({"nr": r[0], "punt": r[1], "wie": schoon(r[wi]), "uiterlijk": schoon(r[ui]), "antwoord": schoon(r[ai])})
        elif kop[:2] == ["Stap", "Wie"] and "Status" in kop:
            si = kop.index("Status")
            for r in rijen[1:]:
                if len(r) > si and r[si] == "Open":
                    uit["te_regelen"].append({"stap": r[0], "wie": schoon(r[1]), "opmerking": schoon(r[-1])})
        elif kop[0] == "Artikel" and "Status" in kop:
            si = kop.index("Status")
            for r in rijen[1:]:
                if len(r) > si and r[si]: uit["materiaal"][r[si]] = uit["materiaal"].get(r[si], 0) + 1
    return uit


def artikelen(droog):
    """Leest nieuwe_artikelen.json: maakt ontbrekende artikelen aan in de voorraadapp, zet de WW-code in het
    werkdocument en vervangt daarna de materiaallijst van de geraakte projecten."""
    lijst = json.load(open(os.path.join(HIER, "nieuwe_artikelen.json"), encoding="utf-8"))
    uit = {"aangemaakt": [], "bestond_al": [], "in_document": [], "niet_gevonden": [], "fouten": []}
    werk = {}  # project -> [(artikel, wwcode)]
    for x in lijst:
        if "ww" in x:
            werk.setdefault(x["project"], []).append((x["artikel"], x["ww"])); continue
        n = {k: v for k, v in x["nieuw"].items() if v}
        if droog: ww = "WW????"
        else:
            r = vapp("product", n)
            if not isinstance(r, dict) or not r.get("code"):
                uit["fouten"].append("%s: %s" % (n["naam"], "de app kent de opdracht 'product' nog niet (eerst uitrollen)" if isinstance(r, dict) and "opdrachten" in r else str(r)[:200]))
                continue
            ww = r["code"]; uit["aangemaakt" if r.get("nieuw") else "bestond_al"].append("%s %s" % (ww, r["naam"]))
        for rij in x["rijen"]: werk.setdefault(rij["project"], []).append((rij["artikel"], ww))
    if uit["fouten"]: return uit
    for prj, pad in documenten():
        if prj not in werk: continue
        if open_in_word(pad): uit["fouten"].append(prj + ": document staat open in Word, overgeslagen"); continue
        xml, rijen = lees(pad)
        for artikel, ww in werk[prj]:
            treffers = [r for r in (rijen or []) if r["artikel"] == artikel]
            if not treffers: uit["niet_gevonden"].append("%s: %s" % (prj, artikel)); continue
            if re.search(r"\bWW\d{3,}\b", treffers[0]["code"]): continue
            uit["in_document"].append("%s: %s -> %s" % (prj, artikel, ww))
            if droog: continue
            r = treffers[0]
            leeg = not r["code"]
            run = '<w:r><w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t xml:space="preserve">%s%s (magazijn)</w:t></w:r>' % ("" if leeg else " · ", ww)
            i = r["code_cel"].rindex("</w:p>")
            xml = xml[:r["code_van"]] + r["code_cel"][:i] + run + r["code_cel"][i:] + xml[r["code_tot"]:]
            xml_, rijen = xml, lees_xml(xml)
        if not droog:
            schrijf(pad, xml)
            uit.setdefault("app", {})[prj] = naar_app(prj, pad, False, True).get("app")
    return uit


def main():
    arg = [a for a in sys.argv[1:] if not a.startswith("--")]
    droog, vervang = "--droog" in sys.argv, "--vervang" in sys.argv
    if arg and arg[0] == "artikelen":
        print(json.dumps(artikelen(droog), ensure_ascii=False, indent=1)); return 0
    if not arg or arg[0] not in ("status", "naar-app", "open") or (arg[0] == "naar-app" and len(arg) < 2):
        print(__doc__); return 2
    prj = None
    if len(arg) > 1:
        m = re.search(r"\d+", arg[1]); prj = "PRJ-" + m.group(0) if m else None
    werk = {"status": status, "naar-app": naar_app, "open": open_punten}[arg[0]]
    print(json.dumps([(werk(p, d, droog, vervang) if arg[0] == "naar-app" else werk(p, d, droog)) for p, d in documenten(prj)], ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
