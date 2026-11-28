<?php
// Ronde 2 (28-11-2026): van klus tot factuur.
// Locaties en installatieregister, digitale werkbon met handtekening en PDF, uren uit de planning met weekakkoord,
// begroting uit de offerte, betaaltermijnen en factuurvoorstellen. Facturen zelf maakt het boekhoudpakket; de app levert het voorstel.

// ---------- Locaties ----------
/** Locatie zoeken op klant en adres, of aanmaken. Een klant met meerdere panden heeft meerdere locaties. */
function locatie_zoek_of_maak(?int $klantId, string $adres, string $naam = ''): ?int
{
    $adres = mb_substr(clean_text($adres), 0, 255);
    if ($adres === '' && !$klantId) return null;
    $r = q('SELECT id FROM locaties WHERE klant_id <=> ? AND LOWER(adres) = LOWER(?) LIMIT 1', [$klantId, $adres])->fetch();
    if (!$r && $adres === '' && $klantId) $r = q('SELECT id FROM locaties WHERE klant_id = ? ORDER BY id LIMIT 1', [$klantId])->fetch();
    if (!$r && $adres !== '' && $klantId) {
        // Klant had nog een locatie zonder adres: die krijgt nu het adres
        $leeg = q("SELECT id FROM locaties WHERE klant_id = ? AND adres = '' ORDER BY id LIMIT 1", [$klantId])->fetch();
        if ($leeg) { q('UPDATE locaties SET adres = ? WHERE id = ?', [$adres, $leeg['id']]); return (int)$leeg['id']; }
    }
    if ($r) return (int)$r['id'];
    q('INSERT INTO locaties (klant_id, naam, adres) VALUES (?,?,?)', [$klantId, mb_substr(clean_text($naam), 0, 150), $adres]);
    return (int)db()->lastInsertId();
}
/** Project of ticket aan een locatie koppelen (op klant en adres). */
function project_koppel_locatie(int $pid): ?int
{
    $p = q('SELECT id, klant_id, adres, locatie_id FROM projecten WHERE id = ?', [$pid])->fetch();
    if (!$p) return null;
    if ($p['locatie_id']) return (int)$p['locatie_id'];
    $lid = locatie_zoek_of_maak($p['klant_id'] ? (int)$p['klant_id'] : null, (string)$p['adres']);
    if ($lid) q('UPDATE projecten SET locatie_id = ? WHERE id = ?', [$lid, $pid]);
    return $lid;
}
/** Alle projecten zonder locatie koppelen (migratie). Geeft het aantal gekoppelde projecten. */
function locaties_vullen(): int
{
    $n = 0;
    foreach (q("SELECT id FROM projecten WHERE locatie_id IS NULL AND (klant_id IS NOT NULL OR adres <> '') ORDER BY id")->fetchAll() as $r)
        if (project_koppel_locatie((int)$r['id'])) $n++;
    return $n;
}
function locatie_tekst(?array $l): string { return $l ? trim(($l['naam'] !== '' ? $l['naam'] . ', ' : '') . $l['adres'], ', ') ?: 'locatie zonder adres' : ''; }

// ---------- Installatieregister ----------
const INSTALLATIE_SOORTEN = ['batterij' => 'Batterij', 'omvormer' => 'Omvormer', 'ems' => 'EMS of regelaar', 'meter' => 'Meter', 'laadpaal' => 'Laadpaal', 'pv' => 'Zonnepanelen', 'overig' => 'Overig'];
const INSTALLATIE_STATUS = ['actief' => 'In gebruik', 'vervangen' => 'Vervangen', 'verwijderd' => 'Verwijderd'];
const INSTALLATIE_VELDEN = ['soort', 'merk', 'type', 'serienummer', 'firmware', 'vermogen', 'geplaatst_op', 'garantie_tot', 'monitoring', 'monitoring_id', 'status', 'notitie'];

/** Installaties op de locatie van een project, plus wat bij het project zelf hoort. */
function installaties_van_project(array $pr): array
{
    return q("SELECT i.*, p.nummer FROM installaties i LEFT JOIN projecten p ON p.id = i.project_id
              WHERE i.project_id = ? OR (i.locatie_id IS NOT NULL AND i.locatie_id = ?)
              ORDER BY i.status = 'actief' DESC, FIELD(i.soort, 'batterij', 'omvormer', 'ems', 'meter', 'laadpaal', 'pv', 'overig'), i.id",
        [$pr['id'], $pr['locatie_id'] ?? 0])->fetchAll();
}
/**
 * Installatie opslaan. Met id: bijwerken. Zonder id: zoekt eerst op serienummer (geen dubbelen), anders nieuw.
 * $alleenLeeg: alleen lege velden vullen (voor Claude). Geeft ['id' => .., 'nieuw' => bool] of ['fout' => ..].
 */
function installatie_opslaan(array $d, string $wie, bool $alleenLeeg = false): array
{
    $v = [];
    foreach (INSTALLATIE_VELDEN as $k) {
        if (!array_key_exists($k, $d)) continue;
        $x = $k === 'notitie' ? trim((string)$d[$k]) : clean_text($d[$k]);
        $v[$k] = match ($k) {
            'soort' => isset(INSTALLATIE_SOORTEN[$x]) ? $x : 'overig',
            'status' => isset(INSTALLATIE_STATUS[$x]) ? $x : 'actief',
            'geplaatst_op', 'garantie_tot' => geldige_datum($x),
            'notitie' => $x !== '' ? $x : null,
            default => mb_substr($x, 0, $k === 'type' ? 150 : 100),
        };
    }
    $pid = null;
    if (!empty($d['project'])) {
        $pid = project_id((string)$d['project']);
        if (!$pid) return ['fout' => 'project niet gevonden: ' . project_nummer((string)$d['project'])];
    }
    $lid = !empty($d['locatie_id']) ? (int)$d['locatie_id'] : ($pid ? project_koppel_locatie($pid) : null);
    if ($lid && !q('SELECT 1 FROM locaties WHERE id = ?', [$lid])->fetch()) return ['fout' => 'locatie niet gevonden'];
    $id = (int)($d['id'] ?? 0);
    if (!$id && ($v['serienummer'] ?? '') !== '') $id = (int)(q('SELECT id FROM installaties WHERE serienummer = ? LIMIT 1', [$v['serienummer']])->fetchColumn() ?: 0);
    $wie = mb_substr($wie, 0, 100);
    if ($id) {
        $oud = q('SELECT * FROM installaties WHERE id = ?', [$id])->fetch();
        if (!$oud) return ['fout' => 'installatie niet gevonden'];
        if ($pid && !$oud['project_id']) $v['project_id'] = $pid;
        if ($lid && !$oud['locatie_id']) $v['locatie_id'] = $lid;
        if ($alleenLeeg) $v = array_filter($v, fn($x, $k) => $x !== null && $x !== '' && ($oud[$k] === null || $oud[$k] === '' || ($k === 'soort' && $oud[$k] === 'overig')), ARRAY_FILTER_USE_BOTH);
        if ($v) {
            $v['bijgewerkt_door'] = $wie;
            q('UPDATE installaties SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v))) . ' WHERE id = ?', [...array_values($v), $id]);
        }
        return ['id' => $id, 'nieuw' => false];
    }
    if (($v['merk'] ?? '') === '' && ($v['type'] ?? '') === '' && ($v['serienummer'] ?? '') === '') return ['fout' => 'vul minstens merk, type of serienummer in'];
    if (!$lid && !$pid) return ['fout' => 'project of locatie ontbreekt'];
    $v += ['locatie_id' => $lid, 'project_id' => $pid, 'bijgewerkt_door' => $wie];
    q('INSERT INTO installaties (' . implode(', ', array_map(fn($k) => "`$k`", array_keys($v))) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')', array_values($v));
    return ['id' => (int)db()->lastInsertId(), 'nieuw' => true];
}
function installatie_naam(array $i): string
{
    return trim((INSTALLATIE_SOORTEN[$i['soort']] ?? '') . ' ' . $i['merk'] . ' ' . $i['type']) . ($i['serienummer'] !== '' ? ' · SN ' . $i['serienummer'] : '');
}
function garantie_klasse(?string $tot): string { return !$tot ? '' : ($tot < vandaag() ? 'muted' : ($tot <= dag_plus(vandaag(), 90) ? 'oranje' : 'ok-ink')); }

// ---------- Werkbon ----------
/** Nieuwe werkbon voor een bezoek, vooraf ingevuld uit planning, instructie, boekingen en uren van die dag. */
function werkbon_nieuw(int $pid, string $datum, array $u, string $conceptDoor = ''): int
{
    $namen = q("SELECT GROUP_CONCAT(DISTINCT g.naam ORDER BY g.volgorde, g.naam SEPARATOR ', ') FROM planning pl JOIN gebruikers g ON g.id = pl.gebruiker_id WHERE pl.project_id = ? AND pl.datum = ?", [$pid, $datum])->fetchColumn();
    $opdracht = q('SELECT wat FROM dagopdrachten WHERE project_id = ? AND datum = ?', [$pid, $datum])->fetchColumn();
    $mat = q("SELECT p.naam, p.eenheid, SUM(-m.aantal) n FROM mutaties m JOIN producten p ON p.id = m.product_id
              WHERE m.project_id = ? AND DATE(m.aangemaakt) = ? AND m.type IN ('uit','retour','correctie') GROUP BY p.id HAVING n > 0 ORDER BY p.naam", [$pid, $datum])->fetchAll();
    $uren = q("SELECT SUM(uren) FROM uren WHERE project_id = ? AND datum = ? AND soort = 'werk'", [$pid, $datum])->fetchColumn();
    q('INSERT INTO werkbonnen (project_id, datum, gebruiker_id, monteurs, werk, materiaal, uren, concept_door, bijgewerkt_door) VALUES (?,?,?,?,?,?,?,?,?)', [
        $pid, $datum, $u['id'] ?? null, mb_substr((string)($namen ?: ($u['naam'] ?? '')), 0, 255), $opdracht ?: null,
        $mat ? implode("\n", array_map(fn($r) => fmt_n($r['n']) . ' ' . ($r['eenheid'] !== 'stuks' ? $r['eenheid'] . ' ' : 'x ') . $r['naam'], $mat)) : null,
        $uren ?: null, $conceptDoor, mb_substr((string)($u['naam'] ?? $conceptDoor), 0, 100)]);
    return (int)db()->lastInsertId();
}
/** Handtekening als JPEG (data-URL van het canvas). Geeft de ruwe bytes of null. */
function handtekening_jpeg(?string $dataUrl): ?string
{
    if (!$dataUrl || !preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return null;
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) > 600000) return null;
    $info = @getimagesizefromstring($bin);
    return $info && $info[2] === IMAGETYPE_JPEG ? $bin : null;
}

/** Kleine PDF-schrijver zonder bibliotheek: tekst in Helvetica en JPEG-afbeeldingen (voor de werkbon). */
final class MiniPdf
{
    private const B = 595.28, H = 841.89, M = 48;
    private array $paginas = [];
    private array $plaatjes = [];
    private float $y = 0;

    public function __construct() { $this->pagina(); }
    private function pagina(): void { $this->paginas[] = ''; $this->y = self::H - self::M; }
    private function ruimteNodig(float $h): void { if ($this->y - $h < self::M) $this->pagina(); }
    private function zet(string $s): void { $this->paginas[count($this->paginas) - 1] .= $s . "\n"; }
    private static function esc(string $s): string
    {
        $s = (string)@iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']);
    }
    /** Tekst met automatische regelafbreking. */
    public function tekst(string $s, float $grootte = 10, bool $vet = false, float $in = 0, float $kleur = 0): void
    {
        $max = max(10, (int)floor((self::B - 2 * self::M - $in) / ($grootte * ($vet ? 0.56 : 0.52))));
        foreach (preg_split('/\R/', $s) as $alinea) {
            $regels = $alinea === '' ? [''] : explode("\n", wordwrap($alinea, $max, "\n", true));
            foreach ($regels as $r) {
                $this->ruimteNodig($grootte * 1.35);
                $this->y -= $grootte * 1.35;
                $this->zet(sprintf('BT %.2F g /%s %.1F Tf %.2F %.2F Td (%s) Tj ET', $kleur, $vet ? 'F2' : 'F1', $grootte, self::M + $in, $this->y, self::esc($r)));
            }
        }
    }
    /** Label links, waarde ernaast. */
    public function veld(string $label, string $waarde): void
    {
        $y0 = $this->y;
        $this->tekst($label, 10, true, 0, 0.4);
        $this->y = $y0;
        $this->tekst($waarde !== '' ? $waarde : '–', 10, false, 120);
    }
    public function ruimte(float $pt): void { $this->y -= $pt; if ($this->y < self::M) $this->pagina(); }
    public function lijn(): void { $this->ruimte(6); $this->zet(sprintf('0.8 G 0.6 w %.2F %.2F m %.2F %.2F l S', self::M, $this->y, self::B - self::M, $this->y)); $this->ruimte(6); }
    public function jpeg(string $bin, float $breedte, float $maxHoogte = 140): void
    {
        $info = getimagesizefromstring($bin);
        [$w, $h] = [$info[0], $info[1]];
        $hoogte = $breedte * $h / $w;
        if ($hoogte > $maxHoogte) { $breedte *= $maxHoogte / $hoogte; $hoogte = $maxHoogte; }
        $this->ruimteNodig($hoogte + 4);
        $this->y -= $hoogte;
        $n = count($this->plaatjes) + 1;
        $this->plaatjes[] = [$bin, $w, $h, count($this->paginas) - 1];
        $this->zet(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q', $breedte, $hoogte, self::M, $this->y, $n));
    }
    public function uitvoer(): string
    {
        $obj = [];
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $nr = 5; $plaatjeObj = [];
        foreach ($this->plaatjes as $i => [$bin, $w, $h]) {
            $plaatjeObj[$i] = $nr;
            $obj[$nr++] = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($bin) . " >>\nstream\n$bin\nendstream";
        }
        $kids = [];
        foreach ($this->paginas as $p => $inhoud) {
            $xo = '';
            foreach ($this->plaatjes as $i => $pl) if ($pl[3] === $p) $xo .= '/Im' . ($i + 1) . ' ' . $plaatjeObj[$i] . ' 0 R ';
            $obj[$nr] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>',
                self::B, self::H, $xo !== '' ? " /XObject << $xo>>" : '', $nr + 1);
            $kids[] = "$nr 0 R";
            $obj[$nr + 1] = '<< /Length ' . strlen($inhoud) . " >>\nstream\n$inhoud\nendstream";
            $nr += 2;
        }
        $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($obj);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $pos = [];
        foreach ($obj as $i => $o) { $pos[$i] = strlen($pdf); $pdf .= "$i 0 obj\n$o\nendobj\n"; }
        $x = strlen($pdf);
        $pdf .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
        foreach ($pos as $p) $pdf .= sprintf("%010d 00000 n \n", $p);
        return $pdf . "trailer\n<< /Size " . (count($obj) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    }
}
/** PDF van een werkbon. */
function werkbon_pdf(array $wb, array $pr): string
{
    $pdf = new MiniPdf();
    $pdf->tekst('Watt Watt', 18, true);
    $pdf->tekst('Werkbon ' . $pr['nummer'] . '-' . (int)$wb['id'], 12, true, 0, 0.3);
    $pdf->lijn();
    $pdf->veld('Datum', datum_lang($wb['datum']));
    $pdf->veld('Project', $pr['nummer'] . ' ' . project_naam($pr));
    $pdf->veld('Klant', (string)$pr['klant']);
    $pdf->veld('Adres', (string)$pr['adres']);
    $pdf->veld('Monteurs', (string)$wb['monteurs']);
    if ($wb['uren'] !== null) $pdf->veld('Uren', str_replace('.', ',', (string)(float)$wb['uren']) . ' uur');
    $pdf->lijn();
    foreach (['werk' => 'Uitgevoerd werk', 'materiaal' => 'Gebruikt materiaal', 'opmerkingen' => 'Opmerkingen en afspraken'] as $k => $kop) {
        if (trim((string)$wb[$k]) === '') continue;
        $pdf->tekst($kop, 11, true);
        $pdf->tekst((string)$wb[$k], 10);
        $pdf->ruimte(8);
    }
    $pdf->lijn();
    if ($wb['status'] === 'getekend') {
        $pdf->tekst('Voor akkoord, ' . $wb['klant_naam'] . ', ' . fmt_dt((string)$wb['getekend_op']), 10, true);
        $pdf->ruimte(6);
        if ($jpg = handtekening_jpeg($wb['handtekening'])) $pdf->jpeg($jpg, 220);
    } else {
        $pdf->tekst('Nog niet getekend door de klant (concept).', 10, true, 0, 0.4);
    }
    return $pdf->uitvoer();
}

// ---------- Uren: voorstel uit de planning ----------
/** Per dag (tot en met vandaag) in de week: planning met project waar nog geen uren voor staan. Een dagdeel is 4 uur. */
function uren_voorstellen(int $gid, string $week): array
{
    $eind = min(dag_plus($week, 6), vandaag());
    if ($eind < $week) return [];
    return q("SELECT pl.datum, p.id project_id, p.nummer, p.omschrijving, COUNT(*) * 4 uren
              FROM planning pl JOIN projecten p ON p.id = pl.project_id
              WHERE pl.gebruiker_id = ? AND pl.datum BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM uren u WHERE u.gebruiker_id = pl.gebruiker_id AND u.datum = pl.datum)
              GROUP BY pl.datum, p.id ORDER BY pl.datum, p.nummer", [$gid, $week, $eind])->fetchAll();
}

// ---------- Begroting, termijnen en factuurvoorstellen ----------
const FACTUUR_STATUS = ['concept' => 'Concept', 'akkoord' => 'Akkoord, klaar voor boekhouding', 'geboekt' => 'In boekhouding', 'betaald' => 'Betaald', 'vervallen' => 'Vervallen'];
const TERMIJN_MOMENTEN = ['opdracht' => 'Bij opdracht', 'levering' => 'Bij levering', 'installatie' => 'Na installatie', 'oplevering' => 'Bij oplevering', 'handmatig' => 'Handmatig'];

/** Tarieven voor regiewerk (service): staan in brein_stand 'tarieven' en zijn in te stellen op de pagina Facturen. */
function tarieven(): array
{
    $r = q("SELECT waarde FROM brein_stand WHERE sleutel = 'tarieven'")->fetchColumn();
    $t = $r ? (json_decode((string)$r, true) ?: []) : [];
    return ['uurtarief' => (float)($t['uurtarief'] ?? 0), 'reistarief' => (float)($t['reistarief'] ?? 0), 'opslag' => (float)($t['opslag'] ?? 0)];
}
function begroting_regels(int $pid): array { return q('SELECT * FROM begroting WHERE project_id = ? ORDER BY FIELD(soort, "materiaal", "arbeid", "overig"), id', [$pid])->fetchAll(); }
function begroting_totaal(int $pid): float { return (float)q('SELECT COALESCE(SUM(aantal * prijs), 0) FROM begroting WHERE project_id = ?', [$pid])->fetchColumn(); }
/** Begroting vervangen of aanvullen. Regels: [{soort, omschrijving, aantal, eenheid, prijs}]. */
function begroting_opslaan(int $pid, array $regels, string $bron, bool $vervang): int
{
    if ($vervang) q('DELETE FROM begroting WHERE project_id = ?', [$pid]);
    $n = 0;
    foreach ($regels as $r) {
        $oms = mb_substr(clean_text($r['omschrijving'] ?? ''), 0, 255);
        if ($oms === '') continue;
        q('INSERT INTO begroting (project_id, soort, omschrijving, aantal, eenheid, prijs, bron) VALUES (?,?,?,?,?,?,?)', [$pid,
            in_array($r['soort'] ?? '', ['materiaal', 'arbeid', 'overig'], true) ? $r['soort'] : 'materiaal', $oms,
            num($r['aantal'] ?? 1) ?? 1, mb_substr(clean_text($r['eenheid'] ?? 'stuks'), 0, 20) ?: 'stuks', num($r['prijs'] ?? 0) ?? 0, mb_substr(clean_text($bron), 0, 255)]);
        $n++;
    }
    return $n;
}
function termijnen_van(int $pid): array
{
    return q("SELECT t.*, f.status fstatus FROM termijnen t LEFT JOIN facturen f ON f.id = t.factuur_id AND f.status <> 'vervallen' WHERE t.project_id = ? ORDER BY t.volgorde, t.id", [$pid])->fetchAll();
}
function termijn_bedrag(array $t, ?float $totaal = null): float
{
    if ($t['bedrag'] !== null) return (float)$t['bedrag'];
    return round(($totaal ?? begroting_totaal((int)$t['project_id'])) * (float)$t['percentage'] / 100, 2);
}
/** Termijnen vervangen, alleen zolang er nog geen factuur aan hangt. */
function termijnen_opslaan(int $pid, array $termijnen): array
{
    if (q("SELECT 1 FROM termijnen t JOIN facturen f ON f.id = t.factuur_id AND f.status <> 'vervallen' WHERE t.project_id = ? LIMIT 1", [$pid])->fetch())
        return ['fout' => 'er is al een termijn gefactureerd; pas de termijnen op de projectpagina aan'];
    q('DELETE FROM termijnen WHERE project_id = ?', [$pid]);
    $i = 0;
    foreach ($termijnen as $t) {
        $oms = mb_substr(clean_text($t['omschrijving'] ?? ''), 0, 150);
        $pct = num($t['percentage'] ?? null); $bed = num($t['bedrag'] ?? null);
        if ($oms === '' || ($pct === null && $bed === null)) continue;
        q('INSERT INTO termijnen (project_id, volgorde, omschrijving, percentage, bedrag, moment) VALUES (?,?,?,?,?,?)',
            [$pid, ++$i, $oms, $pct, $bed, isset(TERMIJN_MOMENTEN[$t['moment'] ?? '']) ? $t['moment'] : 'handmatig']);
    }
    return ['termijnen' => $i];
}
function factuur_totaal(int $fid): float { return (float)q('SELECT COALESCE(SUM(aantal * prijs), 0) FROM factuurregels WHERE factuur_id = ?', [$fid])->fetchColumn(); }
function factuur_maak(int $pid, string $soort, string $oms, array $regels, ?int $termijnId, string $door): int
{
    q('INSERT INTO facturen (project_id, soort, termijn_id, omschrijving, concept_door) VALUES (?,?,?,?,?)', [$pid, $soort, $termijnId, mb_substr($oms, 0, 255), mb_substr($door, 0, 100)]);
    $fid = (int)db()->lastInsertId();
    foreach ($regels as $r) q('INSERT INTO factuurregels (factuur_id, omschrijving, aantal, eenheid, prijs, bron) VALUES (?,?,?,?,?,?)',
        [$fid, mb_substr((string)$r['omschrijving'], 0, 255), $r['aantal'] ?? 1, $r['eenheid'] ?? 'stuks', round((float)$r['prijs'], 2), mb_substr((string)($r['bron'] ?? ''), 0, 100)]);
    if ($termijnId) q('UPDATE termijnen SET factuur_id = ? WHERE id = ?', [$fid, $termijnId]);
    return $fid;
}
/** Factuurvoorstel voor een termijn. */
function factuur_voorstel_termijn(int $termijnId, string $door): array
{
    $t = q('SELECT * FROM termijnen WHERE id = ?', [$termijnId])->fetch();
    if (!$t) return ['fout' => 'termijn niet gevonden'];
    if ($t['factuur_id'] && q("SELECT 1 FROM facturen WHERE id = ? AND status <> 'vervallen'", [$t['factuur_id']])->fetch()) return ['fout' => 'voor deze termijn is al een factuurvoorstel', 'factuur' => (int)$t['factuur_id']];
    $totaal = begroting_totaal((int)$t['project_id']);
    if ($t['bedrag'] === null && $totaal <= 0) return ['fout' => 'geen begroting en geen vast bedrag: vul eerst de begroting of het bedrag van de termijn'];
    $bedrag = termijn_bedrag($t, $totaal);
    $oms = 'Termijn ' . (int)$t['volgorde'] . ': ' . $t['omschrijving'] . ($t['bedrag'] === null ? ' (' . rtrim(rtrim(number_format((float)$t['percentage'], 2, ',', ''), '0'), ',') . '% van ' . fmt_eur($totaal) . ')' : '');
    $fid = factuur_maak((int)$t['project_id'], 'termijn', $oms, [['omschrijving' => $oms, 'aantal' => 1, 'eenheid' => 'post', 'prijs' => $bedrag, 'bron' => 'termijn:' . $t['id']]], (int)$t['id'], $door);
    return ['factuur' => $fid, 'bedrag' => $bedrag];
}
/** Factuurvoorstel voor regiewerk (service): uren en materiaal sinds het vorige regievoorstel. */
function factuur_voorstel_regie(int $pid, string $door): array
{
    $tar = tarieven();
    $vanaf = (string)(q("SELECT MAX(aangemaakt) FROM facturen WHERE project_id = ? AND soort = 'regie' AND status <> 'vervallen'", [$pid])->fetchColumn() ?: '2000-01-01');
    $regels = [];
    foreach (q("SELECT u.soort, SUM(u.uren) n, MIN(u.datum) van, MAX(u.datum) tot FROM uren u WHERE u.project_id = ? AND u.aangemaakt > ? GROUP BY u.soort", [$pid, $vanaf])->fetchAll() as $r) {
        $prijs = $r['soort'] === 'reis' ? ($tar['reistarief'] ?: $tar['uurtarief']) : $tar['uurtarief'];
        $regels[] = ['omschrijving' => ($r['soort'] === 'reis' ? 'Reistijd' : 'Arbeid') . ' ' . date('d-m', strtotime($r['van'])) . ($r['tot'] !== $r['van'] ? ' t/m ' . date('d-m', strtotime($r['tot'])) : ''),
                     'aantal' => (float)$r['n'], 'eenheid' => 'uur', 'prijs' => $prijs, 'bron' => 'uren'];
    }
    foreach (q("SELECT p.naam, p.eenheid, p.prijs, SUM(-m.aantal) n FROM mutaties m JOIN producten p ON p.id = m.product_id
                WHERE m.project_id = ? AND m.aangemaakt > ? AND m.type IN ('uit','retour','correctie') GROUP BY p.id HAVING n > 0 ORDER BY p.naam", [$pid, $vanaf])->fetchAll() as $r)
        $regels[] = ['omschrijving' => $r['naam'], 'aantal' => (float)$r['n'], 'eenheid' => $r['eenheid'], 'prijs' => round((float)$r['prijs'] * (1 + $tar['opslag'] / 100), 2), 'bron' => 'materiaal'];
    if (!$regels) return ['fout' => 'geen nieuwe uren of materiaal sinds het vorige voorstel'];
    $pr = q('SELECT nummer, omschrijving FROM projecten WHERE id = ?', [$pid])->fetch();
    $fid = factuur_maak($pid, 'regie', 'Werkzaamheden ' . $pr['nummer'] . ' ' . $pr['omschrijving'], $regels, null, $door);
    return ['factuur' => $fid, 'regels' => count($regels), 'zonder_tarief' => $tar['uurtarief'] <= 0];
}
/** Status van een factuurvoorstel veranderen. Zet de fase van het project mee (factuur gestuurd, betaald). */
function factuur_zet_status(int $fid, string $status, array $u, string $nummer = ''): string
{
    $f = q('SELECT f.*, p.soort psoort, p.fase FROM facturen f JOIN projecten p ON p.id = f.project_id WHERE f.id = ?', [$fid])->fetch();
    if (!$f || !isset(FACTUUR_STATUS[$status])) return 'Factuur niet gevonden.';
    $wie = (string)$u['naam'];
    match ($status) {
        'akkoord' => q('UPDATE facturen SET status = ?, akkoord_door = ?, akkoord_op = UTC_TIMESTAMP() WHERE id = ?', [$status, $wie, $fid]),
        'geboekt' => q('UPDATE facturen SET status = ?, boekhouding_nummer = ?, geboekt_op = UTC_TIMESTAMP(), akkoord_door = IF(akkoord_door = "", ?, akkoord_door), akkoord_op = COALESCE(akkoord_op, UTC_TIMESTAMP()) WHERE id = ?', [$status, mb_substr(clean_text($nummer), 0, 50), $wie, $fid]),
        'betaald' => q('UPDATE facturen SET status = ?, betaald_op = CURDATE() WHERE id = ?', [$status, $fid]),
        default => q('UPDATE facturen SET status = ? WHERE id = ?', [$status, $fid]),
    };
    if ($status === 'vervallen' && $f['termijn_id']) q('UPDATE termijnen SET factuur_id = NULL WHERE id = ? AND factuur_id = ?', [$f['termijn_id'], $fid]);
    $tekst = 'Factuurvoorstel ' . FACTUUR_STATUS[$status] . ': ' . $f['omschrijving'] . ($nummer !== '' ? ' (' . clean_text($nummer) . ')' : '');
    q("INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst) VALUES (?, ?, 'app', ?, ?, NULL)", [$f['project_id'], date('Y-m-d H:i:s'), $wie, mb_substr($tekst, 0, 255)]);
    if ($f['psoort'] === 'project') {
        if ($status === 'geboekt' && in_array($f['fase'], ['afgerond', 'bezig'], true)) q("UPDATE projecten SET fase = 'factuur' WHERE id = ?", [$f['project_id']]);
        if ($status === 'betaald' && $f['fase'] === 'factuur') {
            $open = (int)q("SELECT COUNT(*) FROM facturen WHERE project_id = ? AND status IN ('concept','akkoord','geboekt')", [$f['project_id']])->fetchColumn()
                  + (int)q("SELECT COUNT(*) FROM termijnen t WHERE t.project_id = ? AND NOT EXISTS (SELECT 1 FROM facturen f WHERE f.id = t.factuur_id AND f.status <> 'vervallen')", [$f['project_id']])->fetchColumn();
            if (!$open) q("UPDATE projecten SET fase = 'betaald' WHERE id = ?", [$f['project_id']]);
        }
    }
    return 'Factuurvoorstel: ' . strtolower(FACTUUR_STATUS[$status]) . '.';
}
/** Termijnen die volgens hun moment nu gefactureerd kunnen worden (voor het brein en de pagina Facturen). */
function termijnen_te_factureren(): array
{
    $rijen = q("SELECT t.*, p.nummer, p.omschrijving pnaam, p.fase, p.soort,
                  (SELECT MIN(m.datum) FROM project_mijlpalen m WHERE m.project_id = p.id AND m.soort = 'levering' AND m.gedaan = 1) geleverd,
                  (SELECT afgerond_op FROM opleveringen o WHERE o.project_id = p.id) opgeleverd
                FROM termijnen t JOIN projecten p ON p.id = t.project_id
                WHERE NOT EXISTS (SELECT 1 FROM facturen f WHERE f.id = t.factuur_id AND f.status <> 'vervallen') ORDER BY p.nummer, t.volgorde")->fetchAll();
    $na = fn(string $fase, string $grens) => fase_stap($fase) >= fase_stap($grens);
    return array_values(array_filter($rijen, fn($t) => match ($t['moment']) {
        'opdracht' => true,
        'levering' => (bool)$t['geleverd'] || $na($t['fase'], 'bezig'),
        'installatie' => $na($t['fase'], 'afgerond'),
        'oplevering' => (bool)$t['opgeleverd'],
        default => false,
    }));
}
