#!/usr/bin/env python3
"""Maakt een Watt Watt werkdocument (.docx) uit een JSON-bestand.

Gebruik:  python3 maak_werkdocument.py <invoer.json> <uitvoer.docx>

Geen extra pakketten nodig. Foto's moeten PNG of JPG zijn; paden zijn
relatief aan het JSON-bestand. De opbouw van de JSON staat in LEESMIJ.md.
"""
import json, os, struct, sys, zipfile
from xml.sax.saxutils import escape

BREED = 10206  # A4 met 1,5 cm marge, in twintigste punten (dxa)
STATUS = {  # zelfde kleuren als de oude Google Docs
    "Bestellen": "FF9B9B", "Navragen": "FFF176", "Let op": "9EC5FE",
    "Besteld": "F5A6E6", "Binnen": "A8E6A1",
    "Open": "FFF176", "Klaar": "A8E6A1", "N.v.t.": "E0E0E0",
}
GROEN, GRIJS, LICHT = "0F6B4F", "666666", "EEF4F1"


class Doc:
    def __init__(self, basis):
        self.b, self.basis = [], basis
        self.rels, self.media = [], []

    # ---------- tekst ----------
    def _rel(self, soort, doel, extern=False):
        rid = "rId%d" % (len(self.rels) + 10)
        self.rels.append((rid, soort, doel, extern))
        return rid

    def run(self, t, vet=False, cursief=False, kleur=None, maat=None, link=None):
        rpr = ""
        if vet: rpr += "<w:b/>"
        if cursief: rpr += "<w:i/>"
        if link: kleur = "0B57D0"
        if kleur: rpr += '<w:color w:val="%s"/>' % kleur
        if maat: rpr += '<w:sz w:val="%d"/><w:szCs w:val="%d"/>' % (maat, maat)
        if link: rpr += '<w:u w:val="single"/>'
        r = '<w:r><w:rPr>%s</w:rPr><w:t xml:space="preserve">%s</w:t></w:r>' % (rpr, escape(str(t)))
        if link:
            rid = self._rel("hyperlink", link, True)
            r = '<w:hyperlink r:id="%s">%s</w:hyperlink>' % (rid, r)
        return r

    def rijk(self, t, **kw):
        """Tekst; '‹...›' wordt grijs en cursief (nog in te vullen)."""
        if isinstance(t, dict):  # {"t": tekst, "link": url}
            return self.run(t.get("t", ""), link=t.get("link"), **kw)
        t = "" if t is None else str(t)
        uit, rest = "", t
        while "‹" in rest and "›" in rest:
            a, rest = rest.split("‹", 1)
            m, rest = rest.split("›", 1)
            if a: uit += self.run(a, **kw)
            uit += self.run(m, cursief=True, kleur="888888", maat=kw.get("maat"))
        if rest or not uit: uit += self.run(rest, **kw)
        return uit

    def p(self, inhoud="", stijl=None, voor=0, na=80, uitlijn=None, bijvolgende=False, rand=False):
        ppr = ""
        if stijl: ppr += '<w:pStyle w:val="%s"/>' % stijl
        if bijvolgende: ppr += "<w:keepNext/>"
        if rand: ppr += '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="2" w:color="%s"/></w:pBdr>' % GROEN
        if not stijl: ppr += '<w:spacing w:before="%d" w:after="%d"/>' % (voor, na)
        if uitlijn: ppr += '<w:jc w:val="%s"/>' % uitlijn
        return "<w:p><w:pPr>%s</w:pPr>%s</w:p>" % (ppr, inhoud)

    def add(self, x): self.b.append(x)
    def kop(self, t, niveau=1): self.add(self.p(self.run(t), stijl="Heading%d" % niveau))
    def tekst(self, t, **kw): self.add(self.p(self.rijk(t), **kw))
    def uitleg(self, t): self.add(self.p(self.run(t, cursief=True, kleur=GRIJS, maat=17), na=100))
    def nieuwe_pagina(self): self.add('<w:p><w:r><w:br w:type="page"/></w:r></w:p>')

    # ---------- tabellen ----------
    def cel(self, inhoud, breedte, vul=None, vet=False, span=1, maat=18):
        if not isinstance(inhoud, list): inhoud = [inhoud]
        ps = "".join(x if isinstance(x, str) and x.startswith("<w:p>") else
                     self.p(self.rijk(x, vet=vet, maat=maat), voor=30, na=30) for x in inhoud)
        tcpr = '<w:tcW w:w="%d" w:type="dxa"/>' % breedte
        if span > 1: tcpr += '<w:gridSpan w:val="%d"/>' % span
        if vul: tcpr += '<w:shd w:val="clear" w:color="auto" w:fill="%s"/>' % vul
        return "<w:tc><w:tcPr>%s</w:tcPr>%s</w:tc>" % (tcpr, ps)

    def tabel(self, kolommen, rijen, statuskolom=None, kop=True, lijnen=True):
        """kolommen: [(titel, breedte)], rijen: lijst van lijsten; een rij {"groep": titel} wordt een tussenkop."""
        tot = sum(b for _, b in kolommen)
        br = [int(b * BREED / tot) for _, b in kolommen]
        br[-1] += BREED - sum(br)
        rand = "".join('<w:%s w:val="single" w:sz="4" w:space="0" w:color="BBBBBB"/>' % k
                       for k in ("top", "left", "bottom", "right", "insideH", "insideV")) if lijnen else ""
        x = ('<w:tbl><w:tblPr><w:tblW w:w="%d" w:type="dxa"/><w:tblBorders>%s</w:tblBorders>'
             '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar>'
             '</w:tblPr><w:tblGrid>%s</w:tblGrid>') % (BREED, rand, "".join('<w:gridCol w:w="%d"/>' % b for b in br))
        if kop:
            x += '<w:tr><w:trPr><w:cantSplit/><w:tblHeader/></w:trPr>%s</w:tr>' % "".join(
                self.cel(self.p(self.run(t, vet=True, kleur="FFFFFF", maat=17), voor=30, na=30), b, vul=GROEN)
                for (t, _), b in zip(kolommen, br))
        for rij in rijen:
            if isinstance(rij, dict):
                x += "<w:tr><w:trPr><w:cantSplit/></w:trPr>%s</w:tr>" % self.cel(rij["groep"], BREED, vul=LICHT, vet=True, span=len(br))
                continue
            cellen = ""
            for i, (v, b) in enumerate(zip(list(rij) + [""] * len(br), br)):
                vul = STATUS.get(v) if i == statuskolom else None
                cellen += self.cel(v, b, vul=vul, vet=(i == statuskolom and bool(vul)))
            x += "<w:tr><w:trPr><w:cantSplit/></w:trPr>%s</w:tr>" % cellen
        self.add(x + "</w:tbl>")
        self.add(self.p("", na=60))

    # ---------- foto's ----------
    def _maat(self, pad):
        d = open(pad, "rb").read()
        if d[:8] == b"\x89PNG\r\n\x1a\n":
            return struct.unpack(">II", d[16:24]), "png", d
        i = 2  # JPEG
        while i < len(d):
            if d[i] != 0xFF: i += 1; continue
            m = d[i + 1]
            if m in (0xC0, 0xC1, 0xC2):
                h, w = struct.unpack(">HH", d[i + 5:i + 9]); return (w, h), "jpeg", d
            i += 2 + struct.unpack(">H", d[i + 2:i + 4])[0]
        raise ValueError("onbekend fotoformaat: " + pad)

    def foto(self, pad, max_b_cm, max_h_cm=9.0):
        (w, h), soort, data = self._maat(os.path.join(self.basis, pad))
        n = len(self.media) + 1
        naam = "foto%d.%s" % (n, "png" if soort == "png" else "jpg")
        self.media.append((naam, data))
        rid = self._rel("image", "media/" + naam)
        cx = int(max_b_cm * 360000); cy = int(cx * h / w)
        if cy > max_h_cm * 360000: cy = int(max_h_cm * 360000); cx = int(cy * w / h)
        return self.p(
            '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="%d" cy="%d"/>'
            '<wp:docPr id="%d" name="Foto %d"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr>'
            '<pic:cNvPr id="%d" name="%s"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="%s"/>'
            '<a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/>'
            '<a:ext cx="%d" cy="%d"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>'
            % (cx, cy, n, n, n, naam, rid, cx, cy), voor=40, na=40, uitlijn="center")

    def fotoraster(self, fotos, per_rij):
        """fotos: [{"nr","bestand","bijschrift"}]; zonder bestand komt er een leeg vak om een foto in te plakken."""
        b = BREED // per_rij
        cm = b / 567.0 - 0.4
        for i in range(0, len(fotos), per_rij):
            rij = fotos[i:i + per_rij]
            cellen = []
            for f in rij:
                if f.get("bestand"):
                    beeld = self.foto(f["bestand"], cm)
                else:
                    beeld = self.p(self.run("plak hier de foto", cursief=True, kleur="888888"), voor=900, na=900, uitlijn="center")
                bij = self.p(self.run("Foto %s. " % f["nr"], vet=True, maat=17) + self.rijk(f.get("bijschrift", ""), maat=17), voor=20, na=60)
                cellen.append([beeld, bij])
            while len(cellen) < per_rij: cellen.append([""])
            br = [b] * per_rij; br[-1] += BREED - b * per_rij
            rand = "".join('<w:%s w:val="single" w:sz="4" w:space="0" w:color="DDDDDD"/>' % k
                           for k in ("top", "left", "bottom", "right", "insideH", "insideV"))
            self.add('<w:tbl><w:tblPr><w:tblW w:w="%d" w:type="dxa"/><w:tblBorders>%s</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>'
                     '<w:tblGrid>%s</w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>%s</w:tr></w:tbl>'
                     % (BREED, rand, "".join('<w:gridCol w:w="%d"/>' % x for x in br),
                        "".join(self.cel(c, x) for c, x in zip(cellen, br))))
            self.add(self.p("", na=40))

    # ---------- opslaan ----------
    def opslaan(self, pad, voettekst):
        ns = ('xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
              'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
              'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"')
        kop = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        document = (kop + "<w:document %s><w:body>%s<w:sectPr><w:footerReference w:type=\"default\" r:id=\"rId1\"/>"
                    '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="850" w:right="850" w:bottom="1000" w:left="850" '
                    'w:header="500" w:footer="500" w:gutter="0"/></w:sectPr></w:body></w:document>') % (ns, "".join(self.b))
        def kopstijl(i, maat, voor, na, kleur):
            return ('<w:style w:type="paragraph" w:styleId="Heading%d"><w:name w:val="heading %d"/><w:basedOn w:val="Normal"/>'
                    '<w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="%d" w:after="%d"/>'
                    '<w:outlineLvl w:val="%d"/></w:pPr><w:rPr><w:b/><w:color w:val="%s"/><w:sz w:val="%d"/><w:szCs w:val="%d"/></w:rPr></w:style>'
                    % (i, i, voor, na, i - 1, kleur, maat, maat))
        stijlen = (kop + '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr>'
                   '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial" w:eastAsia="Arial"/><w:sz w:val="20"/><w:szCs w:val="20"/>'
                   '<w:lang w:val="nl-NL"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="80"/></w:pPr></w:pPrDefault></w:docDefaults>'
                   '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
                   + kopstijl(1, 28, 280, 100, GROEN) + kopstijl(2, 22, 200, 80, "222222") + "</w:styles>")
        voet = (kop + "<w:ftr %s><w:p><w:pPr><w:jc w:val=\"center\"/></w:pPr>%s"
                '<w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r>'
                '<w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
                '<w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>'
                ) % (ns, self.run(voettekst + "  ·  pagina ", kleur="666666", maat=16))
        basis = "http://schemas.openxmlformats.org/officeDocument/2006/relationships/"
        rels = kop + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        rels += '<Relationship Id="rId1" Type="%sfooter" Target="footer1.xml"/><Relationship Id="rId2" Type="%sstyles" Target="styles.xml"/>' % (basis, basis)
        for rid, soort, doel, extern in self.rels:
            rels += '<Relationship Id="%s" Type="%s%s" Target="%s"%s/>' % (
                rid, basis, soort, escape(doel, {'"': "&quot;"}), ' TargetMode="External"' if extern else "")
        rels += "</Relationships>"
        types = (kop + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                 '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                 '<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
                 '<Default Extension="jpg" ContentType="image/jpeg"/>'
                 '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                 '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                 '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>')
        hoofd = (kop + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                 '<Relationship Id="rId1" Type="%sofficeDocument" Target="word/document.xml"/></Relationships>' % basis)
        with zipfile.ZipFile(pad, "w", zipfile.ZIP_DEFLATED) as z:
            z.writestr("[Content_Types].xml", types)
            z.writestr("_rels/.rels", hoofd)
            z.writestr("word/document.xml", document)
            z.writestr("word/styles.xml", stijlen)
            z.writestr("word/footer1.xml", voet)
            z.writestr("word/_rels/document.xml.rels", rels)
            for naam, data in self.media:
                z.writestr("word/media/" + naam, data)


def vink(t): return "☐  " + t


def bouw(d, uit, basis):
    doc = Doc(basis)
    naam = d["naam"]
    # ===== Voorblad / projectkaart =====
    doc.add(doc.p(doc.run("Werkdocument", vet=True, kleur=GROEN, maat=22), na=0))
    doc.add(doc.p(doc.rijk(naam, vet=True, maat=40), na=60, rand=True))
    doc.tekst(d.get("kort", ""), na=120)
    paren = d["project"]
    rijen = []
    for i in range(0, len(paren), 2):
        a = paren[i]; b = paren[i + 1] if i + 1 < len(paren) else ["", ""]
        rijen.append((a, b))
    b4 = [1900, 3203, 1900, 3203]
    x = ('<w:tbl><w:tblPr><w:tblW w:w="%d" w:type="dxa"/><w:tblBorders>%s</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>%s</w:tblGrid>'
         % (BREED, "".join('<w:%s w:val="single" w:sz="4" w:space="0" w:color="BBBBBB"/>' % k for k in ("top", "left", "bottom", "right", "insideH", "insideV")),
            "".join('<w:gridCol w:w="%d"/>' % w for w in b4)))
    for a, b in rijen:
        x += "<w:tr>%s%s%s%s</w:tr>" % (doc.cel(a[0], b4[0], vul=LICHT, vet=True), doc.cel(a[1], b4[1]),
                                        doc.cel(b[0], b4[2], vul=LICHT, vet=True), doc.cel(b[1], b4[3]))
    doc.add(x + "</w:tbl>"); doc.add(doc.p("", na=60))

    doc.kop("Zo werkt dit document", 2)
    doc.tabel([("Wie", 2200), ("Doet wat", 5200), ("Waar", 2806)], [
        ["Werkvoorbereider", "Vult deel A in na de schouw en de offerte. Schrijft het monteursblad (deel B).", "1 t/m 5 en deel B"],
        ["Binnendienst / inkoop", "Controleert de materiaallijst met de voorraadapp, bestelt wat rood is en werkt de status bij.", "5. Materiaallijst"],
        ["Iedereen", "Zet elke vraag of twijfel als regel in de open punten, met naam en datum. Niet los in de tekst.", "3. Open punten"],
        ["Monteur", "Werkt vanaf het monteursblad. Schrijft op wat anders was dan verwacht.", "Deel B"],
    ], kop=True)
    leg = [("Bestellen", "nog bestellen"), ("Navragen", "eerst uitzoeken of vragen"), ("Let op", "geen actie, wel weten"),
           ("Besteld", "onderweg"), ("Binnen", "ligt klaar in magazijn of op het project")]
    lb = BREED // 5
    x = '<w:tbl><w:tblPr><w:tblW w:w="%d" w:type="dxa"/><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>%s</w:tblGrid><w:tr>' % (
        BREED, "".join('<w:gridCol w:w="%d"/>' % lb for _ in leg))
    for s, u in leg:
        x += doc.cel(doc.p(doc.run(s, vet=True, maat=17) + doc.run(": " + u, maat=16), voor=30, na=30), lb, vul=STATUS[s])
    doc.add(x + "</w:tr></w:tbl>"); doc.add(doc.p("", na=40))

    # ===== Deel A =====
    doc.kop("Deel A · Voorbereiding (kantoor)")
    doc.kop("1. Wat is verkocht", 2)
    doc.uitleg("Neem over uit de getekende offerte. Zo is duidelijk wat wij doen, wat de klant doet en wat een ander doet.")
    doc.tabel([("Onderdeel", 4400), ("Wie doet het", 1900), ("Opmerking", 3906)], d["verkocht"])

    doc.kop("2. Regelen vóór de uitvoering", 2)
    doc.uitleg("Vaste lijst voor elk project. Zet 'N.v.t.' als een stap niet speelt; haal geen regels weg.")
    doc.tabel([("Stap", 4300), ("Wie", 1300), ("Status", 1100), ("Afspraak / opmerking", 3506)], d["regelen"], statuskolom=2)

    doc.kop("3. Open punten", 2)
    doc.uitleg("Alles wat nog uitgezocht, gevraagd of besloten moet worden. Het werk start pas als hier niets meer op 'Open' staat, of als bij het punt staat dat het op locatie bekeken wordt.")
    doc.tabel([("Nr", 500), ("Punt", 4200), ("Wie", 1100), ("Uiterlijk", 1100), ("Status", 900), ("Antwoord / besluit", 2406)], d["open"], statuskolom=4)

    doc.kop("4. Technische gegevens", 2)
    doc.tabel([("Gegeven", 3000), ("Waarde", 7206)], d["techniek"], kop=False)
    doc.add(doc.p(doc.run("Kabels", vet=True), bijvolgende=True, voor=60))
    doc.tabel([("Nr", 500), ("Van", 1700), ("Naar", 1700), ("Kabel", 2100), ("Lengte", 1000), ("Hoe gelegd", 3206)], d["kabels"])
    doc.add(doc.p(doc.run("Data en netwerk", vet=True), bijvolgende=True, voor=60))
    doc.tabel([("Nr", 500), ("Van", 2300), ("Naar", 2300), ("Kabel", 1700), ("Opmerking", 3406)], d["data"])

    doc.kop("5. Materiaallijst", 2)
    doc.uitleg("Eén regel per artikel, altijd met aantal. Controleer eerst de voorraadapp: wat er ligt wordt 'Binnen', de rest 'Bestellen'. "
               "Na bestellen wordt het 'Besteld'. Weet je het aantal of het type nog niet: 'Navragen' en een open punt aanmaken.")
    doc.tabel([("Artikel", 3700), ("Aantal", 900), ("Artikelnummer / link", 2300), ("Status", 1100), ("Opmerking", 2206)], d["materiaal"], statuskolom=3)

    for e in d.get("extra", []):
        doc.kop(e["titel"], 2)
        if e.get("uitleg"): doc.uitleg(e["uitleg"])
        doc.tabel([tuple(k) for k in e["kolommen"]], e["rijen"], statuskolom=e.get("statuskolom"))

    # ===== Deel B =====
    doc.nieuwe_pagina()
    doc.add(doc.p(doc.run("Deel B · Monteursblad", vet=True, kleur=GROEN, maat=22), na=0))
    doc.add(doc.p(doc.rijk(naam, vet=True, maat=36), na=60, rand=True))
    m = d["monteur"]
    x = '<w:tbl><w:tblPr><w:tblW w:w="%d" w:type="dxa"/><w:tblBorders>%s</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>%s</w:tblGrid>' % (
        BREED, "".join('<w:%s w:val="single" w:sz="4" w:space="0" w:color="BBBBBB"/>' % k for k in ("top", "left", "bottom", "right", "insideH", "insideV")),
        "".join('<w:gridCol w:w="%d"/>' % w for w in b4))
    kaart = m["kaart"]
    for i in range(0, len(kaart), 2):
        a = kaart[i]; b = kaart[i + 1] if i + 1 < len(kaart) else ["", ""]
        x += "<w:tr>%s%s%s%s</w:tr>" % (doc.cel(a[0], b4[0], vul=LICHT, vet=True), doc.cel(a[1], b4[1]),
                                        doc.cel(b[0], b4[2], vul=LICHT, vet=True), doc.cel(b[1], b4[3]))
    doc.add(x + "</w:tbl>"); doc.add(doc.p("", na=60))

    doc.kop("Wat gaan we doen", 2)
    for t in m["doel"]: doc.tekst(t)

    doc.kop("Let op", 2)
    x = '<w:tbl><w:tblPr><w:tblW w:w="%d" w:type="dxa"/><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="%d"/></w:tblGrid><w:tr>%s</w:tr></w:tbl>' % (
        BREED, BREED, doc.cel([doc.p(doc.run("!  ", vet=True) + doc.rijk(t), voor=40, na=40) for t in m["letop"]], BREED, vul="DCE9FF"))
    doc.add(x); doc.add(doc.p("", na=60))

    doc.kop("Werkvolgorde", 2)
    doc.uitleg("Vink af wat klaar is. Nummers tussen haakjes verwijzen naar de foto's en naar de kabels in deel A.")
    rijen = []
    for blok in m["stappen"]:
        rijen.append({"groep": blok["titel"]})
        for s in blok["stappen"]:
            rijen.append(["☐", s])
    doc.tabel([("", 500), ("", 9706)], rijen, kop=False)

    doc.kop("Foto's", 2)
    if m.get("luchtfotos"): doc.fotoraster(m["luchtfotos"], 2)
    if m.get("fotos"): doc.fotoraster(m["fotos"], 3)

    doc.kop("Klaar? Controle bij oplevering", 2)
    doc.tabel([("", 500), ("", 9706)], [["☐", s] for s in m["oplevering"]], kop=False)

    doc.kop("Gevonden op locatie", 2)
    doc.uitleg("Wat was anders dan in dit document? Meerwerk, ontbrekend materiaal, afspraken met de klant. Geef dit dezelfde dag door aan kantoor.")
    doc.tabel([("", 10206)], [[doc.p("", voor=0, na=1500)]], kop=False)

    doc.opslaan(uit, "Watt Watt werkdocument · %s · bijgewerkt %s" % (d.get("voet", naam), d.get("bijgewerkt", "")))


if __name__ == "__main__":
    bron, uit = sys.argv[1], sys.argv[2]
    bouw(json.load(open(bron, encoding="utf-8")), uit, os.path.dirname(os.path.abspath(bron)))
    print("gemaakt:", uit)
