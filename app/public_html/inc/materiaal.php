<?php
// Materiaallijsten per project, reserveringen, bestellingen en leveringen.
//
// Per materiaalregel (wat een project nodig heeft):
//   nodig            wat het project nodig heeft
//   gepakt           netto gescand/gepakt op dit project (uit min retour)
//   gereserveerd     uit de magazijnvoorraad klaargezet, nog niet gepakt
//   besteld_open     besteld voor dit project, nog niet geleverd
//   op_project       rechtstreeks op het project geleverd
//   nog_bestellen    nodig - gepakt - gereserveerd - besteld_open - op_project (min. 0)

const BESTEL_STATUS = ['besteld' => 'Besteld', 'bevestigd' => 'Bevestigd', 'verzonden' => 'Onderweg', 'geleverd' => 'Geleverd', 'gefactureerd' => 'Gefactureerd'];
const BESTEL_RANG = ['besteld' => 1, 'bevestigd' => 2, 'verzonden' => 3, 'geleverd' => 4, 'gefactureerd' => 5];

function lev_naam($s): string
{
    $s = clean_text($s);
    return strtolower($s) === 'rexel' ? 'Rexel' : $s;
}

function clean_code($s): ?string
{
    $s = strtoupper(preg_replace('/\s+/', '', (string)$s));
    return $s === '' ? null : $s;
}

/** Zoek een bestaand product op leveranciercode, EAN of exacte naam. */
function product_zoek(?string $ean, ?string $code, string $omschrijving): ?int
{
    $code = clean_code($code);
    if ($code) {
        $r = q('SELECT id FROM producten WHERE leverancier_code = ? AND actief = 1', [$code])->fetchAll();
        if (count($r) === 1) return (int)$r[0]['id'];
    }
    $ean = clean_ean($ean ?? '');
    if ($ean !== '') {
        $r = q("SELECT id FROM producten WHERE actief = 1 AND (ean = ? OR TRIM(LEADING '0' FROM ean) = TRIM(LEADING '0' FROM ?))", [$ean, $ean])->fetchAll();
        if (count($r) === 1) return (int)$r[0]['id'];
        if (count($r) > 1) {
            $n = q("SELECT id FROM producten WHERE actief = 1 AND ean = ? AND LOWER(naam) = LOWER(?)", [$ean, clean_text($omschrijving)])->fetch();
            if ($n) return (int)$n['id'];
            return (int)$r[0]['id'];
        }
    }
    $r = q('SELECT id FROM producten WHERE actief = 1 AND LOWER(naam) = LOWER(?)', [clean_text($omschrijving)])->fetchAll();
    return count($r) === 1 ? (int)$r[0]['id'] : null;
}

/** Product aanmaken vanuit een bestel- of materiaalregel. */
function product_maak(string $naam, ?string $ean, ?string $code, string $leverancier, ?float $prijs, string $eenheid = 'stuks'): int
{
    q('INSERT INTO producten (naam, groep, ean, leverancier, leverancier_code, prijs, eenheid, voorraad) VALUES (?,?,?,?,?,?,?,0)',
        [clean_text($naam), 'Nieuw (via inkoop)', clean_ean($ean ?? '') ?: null, $leverancier, clean_code($code), $prijs, $eenheid ?: 'stuks']);
    $id = (int)db()->lastInsertId();
    q('UPDATE producten SET code = ? WHERE id = ?', [sprintf('WW%04d', $id), $id]);
    return $id;
}

/** Totaal gereserveerd van een product over alle lopende projecten. */
function gereserveerd_totaal(int $productId, ?int $behalveMateriaal = null): float
{
    return (float)q('SELECT COALESCE(SUM(m.gereserveerd),0) s FROM materiaal m JOIN projecten p ON p.id = m.project_id
                     WHERE m.product_id = ? AND p.afgerond = 0 AND m.id <> ?', [$productId, $behalveMateriaal ?? 0])->fetch()['s'];
}

/** Rijen van de materiaallijst van een project, met alle berekende kolommen. */
function materiaal_rijen(int $projectId): array
{
    $rows = q("SELECT m.*, p.code, p.naam AS productnaam, p.voorraad, p.ean AS p_ean, p.leverancier_code AS p_code, p.prijs, p.locatie,
                 COALESCE((SELECT SUM(-mu.aantal) FROM mutaties mu WHERE mu.project_id = m.project_id AND mu.product_id = m.product_id
                           AND mu.type IN ('uit','retour','correctie')), 0) AS gepakt_product,
                 COALESCE((SELECT SUM(br.aantal - br.geleverd) FROM bestelregels br WHERE br.materiaal_id = m.id), 0) AS besteld_open,
                 (SELECT MIN(b.leverdatum) FROM bestelregels br JOIN bestellingen b ON b.id = br.bestelling_id
                   WHERE br.materiaal_id = m.id AND br.aantal > br.geleverd) AS leverdatum
               FROM materiaal m LEFT JOIN producten p ON p.id = m.product_id
               WHERE m.project_id = ? ORDER BY m.id", [$projectId])->fetchAll();

    // "Gepakt" per product verdelen over regels met hetzelfde product
    $restGepakt = [];
    foreach ($rows as &$r) {
        $pid = $r['product_id'];
        if ($pid !== null && !isset($restGepakt[$pid])) $restGepakt[$pid] = max(0, (float)$r['gepakt_product']);
        $g = 0.0;
        if ($pid !== null) {
            $g = min($restGepakt[$pid], (float)$r['nodig']);
            $restGepakt[$pid] -= $g;
        }
        $r['gepakt'] = $g;
        $r['ean_toon'] = $r['ean'] ?: ($r['p_ean'] ?? '');
        $r['code_toon'] = $r['leverancier_code'] ?: ($r['p_code'] ?? '');
        $r['nog_bestellen'] = max(0, (float)$r['nodig'] - $g - (float)$r['gereserveerd'] - (float)$r['besteld_open'] - (float)$r['op_project_geleverd']);
    }
    unset($r);
    return $rows;
}

/** Reserveer voor één regel wat nog openstaat uit de beschikbare voorraad. */
function materiaal_reserveer(int $materiaalId): float
{
    $m = q('SELECT m.*, pr.afgerond FROM materiaal m JOIN projecten pr ON pr.id = m.project_id WHERE m.id = ?', [$materiaalId])->fetch();
    if (!$m || !$m['product_id'] || $m['afgerond']) return 0.0;
    $rij = null;
    foreach (materiaal_rijen((int)$m['project_id']) as $r) { if ((int)$r['id'] === $materiaalId) { $rij = $r; break; } }
    if (!$rij || $rij['nog_bestellen'] <= 0) return 0.0;
    $p = q('SELECT voorraad FROM producten WHERE id = ? FOR UPDATE', [$m['product_id']])->fetch();
    $beschikbaar = max(0, (float)$p['voorraad'] - gereserveerd_totaal((int)$m['product_id']));
    $erbij = min($rij['nog_bestellen'], $beschikbaar);
    if ($erbij > 0) q('UPDATE materiaal SET gereserveerd = gereserveerd + ? WHERE id = ?', [$erbij, $materiaalId]);
    return $erbij;
}

function project_reserveer(int $projectId): float
{
    $tot = 0.0;
    foreach (q('SELECT id FROM materiaal WHERE project_id = ? AND product_id IS NOT NULL ORDER BY id', [$projectId])->fetchAll() as $r) {
        $tot += materiaal_reserveer((int)$r['id']);
    }
    return $tot;
}

/** Na pakken op een project: reservering van dat product op dat project verlagen. */
function materiaal_na_pakken(int $projectId, int $productId, float $aantal): void
{
    foreach (q('SELECT id, gereserveerd FROM materiaal WHERE project_id = ? AND product_id = ? AND gereserveerd > 0 ORDER BY id', [$projectId, $productId])->fetchAll() as $r) {
        if ($aantal <= 0) break;
        $af = min($aantal, (float)$r['gereserveerd']);
        q('UPDATE materiaal SET gereserveerd = gereserveerd - ? WHERE id = ?', [$af, $r['id']]);
        $aantal -= $af;
    }
}

/**
 * Materiaallijst importeren voor een project.
 * $regels: [{omschrijving, ean?, code?, aantal, eenheid?}]
 * Bestaande regels met hetzelfde product worden opgehoogd, tenzij $vervang: dan wordt de lijst eerst geleegd.
 */
function materiaallijst_import(string $projectNr, array $regels, string $bron, bool $vervang, string $omschrijving = ''): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $pid = project_id($projectNr);
    if ($omschrijving !== '') q("UPDATE projecten SET omschrijving = ? WHERE id = ? AND omschrijving = ''", [mb_substr($omschrijving, 0, 255), $pid]);
    if ($vervang) {
        q('UPDATE bestelregels SET materiaal_id = NULL WHERE materiaal_id IN (SELECT id FROM materiaal WHERE project_id = ?)', [$pid]);
        q('DELETE FROM materiaal WHERE project_id = ?', [$pid]);
    }
    $n = ['nieuw' => 0, 'opgehoogd' => 0, 'onbekend' => 0];
    foreach ($regels as $r) {
        $oms = clean_text($r['omschrijving'] ?? $r['naam'] ?? '');
        $aantal = num($r['aantal'] ?? null);
        if ($oms === '' || $aantal === null || $aantal <= 0) continue;
        $ean = clean_ean($r['ean'] ?? '') ?: null;
        $code = clean_code($r['code'] ?? $r['leverancier_code'] ?? null);
        $prod = product_zoek($ean, $code, $oms);
        // "al_binnen": staat in de werkvoorbereiding als groen (binnen of al op het project).
        // Telt als geregeld: geen reservering uit de voorraad en niet op de bestellijst.
        $binnen = !empty($r['al_binnen']) ? $aantal : 0;
        if ($prod) {
            $bestaand = q('SELECT id FROM materiaal WHERE project_id = ? AND product_id = ?', [$pid, $prod])->fetch();
            if ($bestaand) { q('UPDATE materiaal SET nodig = nodig + ?, op_project_geleverd = op_project_geleverd + ? WHERE id = ?', [$aantal, $binnen, $bestaand['id']]); $n['opgehoogd']++; continue; }
        } else {
            $n['onbekend']++;
        }
        q('INSERT INTO materiaal (project_id, product_id, omschrijving, ean, leverancier_code, eenheid, nodig, op_project_geleverd, bron) VALUES (?,?,?,?,?,?,?,?,?)',
            [$pid, $prod, $oms, $ean, $code, clean_text($r['eenheid'] ?? '') ?: 'stuks', $aantal, $binnen, mb_substr($bron, 0, 255)]);
        $n['nieuw']++;
        if ($binnen) $n['al_binnen'] = ($n['al_binnen'] ?? 0) + 1;
    }
    // Openstaande bestelregels van dit project opnieuw koppelen (na vervangen)
    foreach (q('SELECT br.* FROM bestelregels br JOIN bestellingen b ON b.id = br.bestelling_id WHERE b.project_id = ? AND br.materiaal_id IS NULL', [$pid])->fetchAll() as $br) {
        $mid = materiaal_voor_bestelregel($pid, $br, false);
        if ($mid) q('UPDATE bestelregels SET materiaal_id = ? WHERE id = ?', [$mid, $br['id']]);
    }
    $n['gereserveerd'] = project_reserveer($pid);
    $pdo->commit();
    return ['project' => q('SELECT nummer FROM projecten WHERE id = ?', [$pid])->fetch()['nummer'], 'project_id' => $pid] + $n;
}

/** Vind (of maak) de materiaalregel waar een bestelregel bij hoort. */
function materiaal_voor_bestelregel(int $projectId, array $br, bool $maak = true): ?int
{
    if (!empty($br['product_id'])) {
        $m = q('SELECT id FROM materiaal WHERE project_id = ? AND product_id = ? ORDER BY id LIMIT 1', [$projectId, $br['product_id']])->fetch();
        if ($m) return (int)$m['id'];
    }
    if (!empty($br['leverancier_code'])) {
        $m = q('SELECT id FROM materiaal WHERE project_id = ? AND leverancier_code = ? ORDER BY id LIMIT 1', [$projectId, $br['leverancier_code']])->fetch();
        if ($m) return (int)$m['id'];
    }
    if (!empty($br['ean'])) {
        $m = q('SELECT id FROM materiaal WHERE project_id = ? AND ean = ? ORDER BY id LIMIT 1', [$projectId, $br['ean']])->fetch();
        if ($m) return (int)$m['id'];
    }
    $m = q('SELECT id FROM materiaal WHERE project_id = ? AND LOWER(omschrijving) = LOWER(?) ORDER BY id LIMIT 1', [$projectId, $br['omschrijving']])->fetch();
    if ($m) return (int)$m['id'];
    if (!$maak) return null;
    // Besteld zonder materiaallijst: regel aanmaken zodat het op het project zichtbaar is
    q('INSERT INTO materiaal (project_id, product_id, omschrijving, ean, leverancier_code, nodig, bron) VALUES (?,?,?,?,?,?,?)',
        [$projectId, $br['product_id'] ?: null, $br['omschrijving'], $br['ean'] ?: null, $br['leverancier_code'] ?: null, $br['aantal'], 'Uit bestelling']);
    return (int)db()->lastInsertId();
}

/**
 * Bestelling toevoegen of bijwerken (uniek op leverancier + ordernummer).
 * Lege velden overschrijven niets; status gaat alleen vooruit.
 * $d: leverancier, ordernummer, project?, besteldatum?, leverdatum?, levering?, afleveradres?, status?, besteld_door?, bron?, opmerking?, regels?: [{omschrijving, ean?, code?, aantal, prijs?}]
 */
function bestelling_opslaan(array $d): array
{
    $lev = lev_naam($d['leverancier'] ?? '');
    $nr = clean_text($d['ordernummer'] ?? '');
    if ($lev === '' || $nr === '') throw new InvalidArgumentException('leverancier en ordernummer zijn verplicht');

    $pdo = db();
    $pdo->beginTransaction();
    $b = q('SELECT * FROM bestellingen WHERE leverancier = ? AND ordernummer = ? FOR UPDATE', [$lev, $nr])->fetch();
    $pid = !empty($d['project']) ? project_id($d['project']) : ($b['project_id'] ?? null);
    $datum = fn($v) => ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) ? $v : null;
    $status = isset(BESTEL_RANG[$d['status'] ?? '']) ? $d['status'] : null;
    $levering = in_array($d['levering'] ?? '', ['magazijn', 'project'], true) ? $d['levering'] : null;

    if (!$b) {
        q('INSERT INTO bestellingen (leverancier, ordernummer, project_id, besteldatum, leverdatum, levering, afleveradres, status, besteld_door, bron, opmerking)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [$lev, $nr, $pid, $datum($d['besteldatum'] ?? null), $datum($d['leverdatum'] ?? null), $levering ?? 'onbekend',
           mb_substr(clean_text($d['afleveradres'] ?? ''), 0, 255), in_array($status, ['geleverd', 'gefactureerd'], true) ? 'bevestigd' : ($status ?? 'besteld'), mb_substr(clean_text($d['besteld_door'] ?? ''), 0, 100),
           mb_substr(clean_text($d['bron'] ?? ''), 0, 255), mb_substr(clean_text($d['opmerking'] ?? ''), 0, 255)]);
        $bid = (int)$pdo->lastInsertId();
        $actie = 'nieuw';
    } else {
        $bid = (int)$b['id'];
        $set = ['project_id = ?' => $pid];
        if ($datum($d['besteldatum'] ?? null)) $set['besteldatum = ?'] = $d['besteldatum'];
        if ($datum($d['leverdatum'] ?? null)) $set['leverdatum = ?'] = $d['leverdatum'];
        if ($levering) $set['levering = ?'] = $levering;
        if (!empty($d['afleveradres'])) $set['afleveradres = ?'] = mb_substr(clean_text($d['afleveradres']), 0, 255);
        if (!empty($d['besteld_door'])) $set['besteld_door = ?'] = mb_substr(clean_text($d['besteld_door']), 0, 100);
        if (!empty($d['bron'])) $set['bron = ?'] = mb_substr(clean_text($d['bron']), 0, 255);
        if (!empty($d['opmerking'])) $set['opmerking = ?'] = mb_substr(clean_text($d['opmerking']), 0, 255);
        if ($status && BESTEL_RANG[$status] > BESTEL_RANG[$b['status']] && $status !== 'geleverd') $set['status = ?'] = $status;
        q('UPDATE bestellingen SET ' . implode(', ', array_keys($set)) . ' WHERE id = ?', [...array_values($set), $bid]);
        $actie = 'bijgewerkt';
    }

    // Regels alleen toevoegen als de bestelling nog geen regels heeft (bevestiging komt vaak vaker binnen)
    $heeftRegels = (int)q('SELECT COUNT(*) c FROM bestelregels WHERE bestelling_id = ?', [$bid])->fetch()['c'] > 0;
    $nRegels = 0;
    if (!$heeftRegels && !empty($d['regels']) && is_array($d['regels'])) {
        foreach ($d['regels'] as $r) {
            $oms = clean_text($r['omschrijving'] ?? '');
            $aantal = num($r['aantal'] ?? null);
            if ($oms === '' || !$aantal) continue;
            $br = ['omschrijving' => $oms, 'ean' => clean_ean($r['ean'] ?? '') ?: null, 'leverancier_code' => clean_code($r['code'] ?? $r['leverancier_code'] ?? null), 'aantal' => $aantal];
            $br['product_id'] = product_zoek($br['ean'], $br['leverancier_code'], $oms);
            // Rexel-code bij bekend product aanvullen
            if ($br['product_id'] && $br['leverancier_code']) {
                q("UPDATE producten SET leverancier_code = COALESCE(leverancier_code, ?), leverancier = IF(leverancier = '', ?, leverancier) WHERE id = ?", [$br['leverancier_code'], $lev, $br['product_id']]);
            }
            $mid = $pid ? materiaal_voor_bestelregel($pid, $br) : null;
            q('INSERT INTO bestelregels (bestelling_id, materiaal_id, product_id, omschrijving, ean, leverancier_code, aantal, prijs) VALUES (?,?,?,?,?,?,?,?)',
                [$bid, $mid, $br['product_id'], $oms, $br['ean'], $br['leverancier_code'], $aantal, num($r['prijs'] ?? null)]);
            $nRegels++;
        }
    }
    $pdo->commit();

    $geleverd = null;
    if ($status === 'geleverd') {
        $bb = q('SELECT status FROM bestellingen WHERE id = ?', [$bid])->fetch();
        if (BESTEL_RANG[$bb['status']] < BESTEL_RANG['geleverd']) $geleverd = bestelling_geleverd($bid, $levering ?? null, 'leveringsmelding');
    }
    return ['bestelling_id' => $bid, 'actie' => $actie, 'regels_toegevoegd' => $nRegels, 'geleverd' => $geleverd];
}

/**
 * Levering verwerken. Magazijn: voorraad omhoog en klaarzetten voor het project. Project: telt als op het project geleverd.
 */
function bestelling_geleverd(int $bid, ?string $waar, string $wie = ''): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $b = q('SELECT * FROM bestellingen WHERE id = ? FOR UPDATE', [$bid])->fetch();
    if (!$b) { $pdo->rollBack(); throw new InvalidArgumentException('bestelling niet gevonden'); }
    $waar = in_array($waar, ['magazijn', 'project'], true) ? $waar : ($b['levering'] !== 'onbekend' ? $b['levering'] : 'magazijn');
    $gebruiker = IS_CLI ? null : (user()['id'] ?? null);
    $n = 0;
    foreach (q('SELECT * FROM bestelregels WHERE bestelling_id = ? AND aantal > geleverd', [$bid])->fetchAll() as $br) {
        $rest = (float)$br['aantal'] - (float)$br['geleverd'];
        if ($waar === 'magazijn') {
            $prod = $br['product_id'] ?: product_maak($br['omschrijving'], $br['ean'], $br['leverancier_code'], $b['leverancier'], $br['prijs'] !== null ? (float)$br['prijs'] : null);
            if (!$br['product_id']) {
                q('UPDATE bestelregels SET product_id = ? WHERE id = ?', [$prod, $br['id']]);
                if ($br['materiaal_id']) q('UPDATE materiaal SET product_id = COALESCE(product_id, ?) WHERE id = ?', [$prod, $br['materiaal_id']]);
            }
            $p = q('SELECT * FROM producten WHERE id = ? FOR UPDATE', [$prod])->fetch();
            $nieuw = (float)$p['voorraad'] + $rest;
            q('UPDATE producten SET voorraad = ? WHERE id = ?', [$nieuw, $prod]);
            q('INSERT INTO mutaties (product_id, gebruiker_id, project_id, type, aantal, voorraad_na, prijs, opmerking) VALUES (?,?,?,?,?,?,?,?)',
                [$prod, $gebruiker, $b['project_id'], 'ontvangst', $rest, $nieuw, $br['prijs'] ?? $p['prijs'], mb_substr('Levering ' . $b['leverancier'] . ' ' . $b['ordernummer'] . ($wie ? " ($wie)" : ''), 0, 255)]);
            if ($br['materiaal_id']) q('UPDATE materiaal SET gereserveerd = gereserveerd + ? WHERE id = ?', [$rest, $br['materiaal_id']]);
        } else {
            if ($br['materiaal_id']) q('UPDATE materiaal SET op_project_geleverd = op_project_geleverd + ? WHERE id = ?', [$rest, $br['materiaal_id']]);
        }
        q('UPDATE bestelregels SET geleverd = aantal WHERE id = ?', [$br['id']]);
        $n++;
    }
    q("UPDATE bestellingen SET status = IF(status = 'gefactureerd', status, 'geleverd'), levering = ?, geleverd_op = UTC_TIMESTAMP() WHERE id = ?", [$waar, $bid]);
    $pdo->commit();
    return ['bestelling_id' => $bid, 'waar' => $waar, 'regels' => $n];
}

/** Factuur koppelen aan een bestelling (op ordernummer, anders laatste open bestelling van dat project bij die leverancier). */
function factuur_koppelen(array $d): array
{
    $lev = lev_naam($d['leverancier'] ?? '');
    $b = null;
    if (!empty($d['ordernummer'])) $b = q('SELECT * FROM bestellingen WHERE leverancier = ? AND ordernummer = ?', [$lev, clean_text($d['ordernummer'])])->fetch();
    if (!$b && !empty($d['project'])) {
        $b = q("SELECT b.* FROM bestellingen b JOIN projecten p ON p.id = b.project_id WHERE b.leverancier = ? AND p.nummer = ? AND b.factuurnummer = ''
                ORDER BY b.besteldatum DESC, b.id DESC LIMIT 1", [$lev, project_nummer($d['project'])])->fetch();
    }
    if (!$b) return ['gekoppeld' => false, 'reden' => 'geen bestelling gevonden'];
    q("UPDATE bestellingen SET factuurnummer = ?, factuurbedrag = COALESCE(?, factuurbedrag), status = IF(status = 'geleverd', 'gefactureerd', status),
       project_id = COALESCE(project_id, ?) WHERE id = ?",
      [mb_substr(clean_text($d['factuurnummer'] ?? ''), 0, 100), num($d['bedrag'] ?? null), !empty($d['project']) ? project_id($d['project']) : null, $b['id']]);
    return ['gekoppeld' => true, 'bestelling_id' => (int)$b['id'], 'ordernummer' => $b['ordernummer']];
}

/** Alles wat nog besteld moet worden, over alle lopende projecten (of één project). */
function bestellijst(?int $projectId = null): array
{
    $ids = $projectId ? [$projectId] : array_map('intval', q('SELECT DISTINCT m.project_id FROM materiaal m JOIN projecten p ON p.id = m.project_id WHERE p.afgerond = 0')->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    foreach ($ids as $pid) {
        $nr = q('SELECT nummer, omschrijving FROM projecten WHERE id = ?', [$pid])->fetch();
        foreach (materiaal_rijen($pid) as $r) {
            if ($r['nog_bestellen'] <= 0) continue;
            $out[] = ['project' => $nr['nummer'], 'project_omschrijving' => $nr['omschrijving'], 'materiaal_id' => (int)$r['id'],
                      'omschrijving' => $r['productnaam'] ?: $r['omschrijving'], 'ean' => $r['ean_toon'], 'rexel_code' => $r['code_toon'],
                      'aantal' => (float)$r['nog_bestellen'], 'eenheid' => $r['eenheid'], 'wwcode' => $r['code'] ?? ''];
        }
    }
    return $out;
}

/** Samenvatting van een project voor Claude of export. */
function project_overzicht(int $projectId): array
{
    $pr = q('SELECT * FROM projecten WHERE id = ?', [$projectId])->fetch();
    $rows = materiaal_rijen($projectId);
    $best = q('SELECT leverancier, ordernummer, status, leverdatum, levering, factuurnummer FROM bestellingen WHERE project_id = ? ORDER BY id', [$projectId])->fetchAll();
    return [
        'project' => $pr['nummer'], 'omschrijving' => $pr['omschrijving'],
        'regels' => array_map(fn($r) => [
            'omschrijving' => $r['productnaam'] ?: $r['omschrijving'], 'regel' => $r['omschrijving'], 'wwcode' => $r['code'] ?? '', 'ean' => $r['ean_toon'], 'rexel_code' => $r['code_toon'],
            'nodig' => (float)$r['nodig'], 'gepakt' => $r['gepakt'], 'klaargezet' => (float)$r['gereserveerd'],
            'besteld_open' => (float)$r['besteld_open'], 'leverdatum' => $r['leverdatum'], 'op_project_geleverd' => (float)$r['op_project_geleverd'],
            'nog_bestellen' => $r['nog_bestellen'], 'locatie' => $r['locatie'] ?? '', 'bekend_product' => $r['product_id'] !== null,
        ], $rows),
        'bestellingen' => $best,
    ];
}
