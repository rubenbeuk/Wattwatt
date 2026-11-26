<?php
// Vergelijkt Excel-regels met de database en voert de import uit.

/** @return array{rows: array, nieuw: int, gewijzigd: int, gelijk: int, nul: int} */
function import_plan(array $regels, bool $aantallen, bool $nulZetten): array
{
    $producten = q('SELECT * FROM producten')->fetchAll();
    $perEan = []; $perNaam = []; $perBeide = []; $perCode = [];
    foreach ($producten as $p) {
        if ($p['code']) $perCode[strtoupper($p['code'])] = $p;
        $n = mb_strtolower($p['naam']);
        if ($p['ean']) { $perEan[$p['ean']][] = $p; $perBeide[$n . '|' . $p['ean']] = $p; }
        $perNaam[$n][] = $p;
    }

    $plan = []; $gebruikt = []; $sleutels = [];
    foreach ($regels as $r) {
        $naam = clean_text($r['naam'] ?? '');
        if ($naam === '') continue;
        $ean = clean_ean($r['ean'] ?? '');
        $groep = clean_text($r['groep'] ?? '');
        $aantal = num($r['aantal'] ?? null);
        $prijs = num($r['prijs'] ?? null);
        $locatie = clean_text($r['locatie'] ?? '');
        $code = strtoupper(preg_replace('/\s+/', '', (string)($r['code'] ?? '')));
        $n = mb_strtolower($naam);

        // Dubbele regel in Excel (zelfde naam + EAN): aantallen optellen
        $key = ($code !== '' && isset($perCode[$code])) ? 'code|' . $code : $n . '|' . $ean;
        if (isset($sleutels[$key])) {
            $i = $sleutels[$key];
            if ($aantal !== null) $plan[$i]['aantal'] = ($plan[$i]['aantal'] ?? 0) + $aantal;
            if ($prijs !== null) $plan[$i]['prijs'] = $prijs; // laatste regel heeft de actuele prijs
            if ($groep !== '' && $plan[$i]['groep'] === '') $plan[$i]['groep'] = $groep;
            $plan[$i]['notities'][] = 'staat vaker in Excel, samengevoegd';
            continue;
        }

        // De WW-code (= de QR-code) wint: dan mag de naam in Excel anders zijn
        $match = ($code !== '' && isset($perCode[$code]) && !isset($gebruikt[$perCode[$code]['id']])) ? $perCode[$code] : null;
        if (!$match) $match = $perBeide[$n . '|' . $ean] ?? null;
        if (!$match && $ean !== '' && count($perEan[$ean] ?? []) === 1 && !isset($gebruikt[$perEan[$ean][0]['id']])) $match = $perEan[$ean][0];
        if (!$match && count($perNaam[$n] ?? []) === 1) {
            $c = $perNaam[$n][0];
            if (!isset($gebruikt[$c['id']]) && (!$c['ean'] || $ean === '' || $c['ean'] === $ean)) $match = $c;
        }
        if ($match && isset($gebruikt[$match['id']])) $match = null;
        if ($match) $gebruikt[$match['id']] = true;

        $item = ['naam' => $naam, 'groep' => $groep, 'ean' => $ean, 'aantal' => $aantal, 'prijs' => $prijs,
                 'locatie' => $locatie, 'product' => $match, 'notities' => []];
        $sleutels[$key] = count($plan);
        $plan[] = $item;
    }

    // Wijzigingen bepalen
    $tel = ['nieuw' => 0, 'gewijzigd' => 0, 'gelijk' => 0, 'nul' => 0];
    foreach ($plan as &$it) {
        $p = $it['product'];
        $nieuwAantal = $it['aantal'] ?? 0.0;
        if (!$p) { $it['actie'] = 'nieuw'; $it['wijzig'] = []; $tel['nieuw']++; continue; }
        $w = [];
        if ($p['naam'] !== $it['naam']) $w['naam'] = [$p['naam'], $it['naam']];
        if ($it['groep'] !== '' && $p['groep'] !== $it['groep']) $w['groep'] = [$p['groep'], $it['groep']];
        if ($it['ean'] !== '' && $p['ean'] !== $it['ean']) $w['ean'] = [$p['ean'], $it['ean']];
        if ($it['prijs'] !== null && ($p['prijs'] === null || abs((float)$p['prijs'] - $it['prijs']) > 0.004)) $w['prijs'] = [$p['prijs'], $it['prijs']];
        if ($it['locatie'] !== '' && $p['locatie'] !== $it['locatie']) $w['locatie'] = [$p['locatie'], $it['locatie']];
        if ($aantallen && abs((float)$p['voorraad'] - $nieuwAantal) > 0.004) $w['voorraad'] = [(float)$p['voorraad'], $nieuwAantal];
        if (!$p['actief']) $w['actief'] = [0, 1];
        $it['wijzig'] = $w;
        $it['actie'] = $w ? 'wijzig' : 'gelijk';
        $tel[$w ? 'gewijzigd' : 'gelijk']++;
    }
    unset($it);

    // Producten die niet in de Excel staan
    $ontbreekt = [];
    if ($nulZetten) {
        foreach ($producten as $p) {
            if (!isset($gebruikt[$p['id']]) && $p['actief'] && (float)$p['voorraad'] != 0) { $ontbreekt[] = $p; $tel['nul']++; }
        }
    }
    return ['rows' => $plan, 'ontbreekt' => $ontbreekt] + $tel;
}

function import_uitvoeren(array $plan, int $gebruikerId, string $bestand): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $opm = mb_substr('Excel-import ' . $bestand, 0, 255);
    $n = ['nieuw' => 0, 'gewijzigd' => 0, 'nul' => 0];
    foreach ($plan['rows'] as $it) {
        if ($it['actie'] === 'nieuw') {
            $aantal = $it['aantal'] ?? 0.0;
            q('INSERT INTO producten (naam, groep, ean, prijs, locatie, voorraad) VALUES (?,?,?,?,?,?)',
                [$it['naam'], $it['groep'], $it['ean'] ?: null, $it['prijs'], $it['locatie'], $aantal]);
            $id = (int)$pdo->lastInsertId();
            q('UPDATE producten SET code = ? WHERE id = ?', [sprintf('WW%04d', $id), $id]);
            q('INSERT INTO mutaties (product_id, gebruiker_id, type, aantal, voorraad_na, prijs, opmerking) VALUES (?,?,?,?,?,?,?)',
                [$id, $gebruikerId, 'import', $aantal, $aantal, $it['prijs'], $opm]);
            $n['nieuw']++;
        } elseif ($it['actie'] === 'wijzig') {
            $p = $it['product']; $w = $it['wijzig'];
            $set = []; $vals = [];
            foreach (['naam', 'groep', 'ean', 'prijs', 'locatie', 'actief'] as $veld) {
                if (array_key_exists($veld, $w)) { $set[] = "$veld = ?"; $vals[] = $w[$veld][1]; }
            }
            if ($set) { $vals[] = $p['id']; q('UPDATE producten SET ' . implode(', ', $set) . ' WHERE id = ?', $vals); }
            if (isset($w['voorraad'])) {
                $cur = (float)q('SELECT voorraad FROM producten WHERE id = ? FOR UPDATE', [$p['id']])->fetch()['voorraad'];
                $nieuw = (float)$w['voorraad'][1];
                q('UPDATE producten SET voorraad = ? WHERE id = ?', [$nieuw, $p['id']]);
                q('INSERT INTO mutaties (product_id, gebruiker_id, type, aantal, voorraad_na, prijs, opmerking) VALUES (?,?,?,?,?,?,?)',
                    [$p['id'], $gebruikerId, 'import', $nieuw - $cur, $nieuw, $it['prijs'] ?? $p['prijs'], $opm]);
            }
            $n['gewijzigd']++;
        }
    }
    foreach ($plan['ontbreekt'] as $p) {
        $cur = (float)q('SELECT voorraad FROM producten WHERE id = ? FOR UPDATE', [$p['id']])->fetch()['voorraad'];
        q('UPDATE producten SET voorraad = 0 WHERE id = ?', [$p['id']]);
        q('INSERT INTO mutaties (product_id, gebruiker_id, type, aantal, voorraad_na, prijs, opmerking) VALUES (?,?,?,?,?,?,?)',
            [$p['id'], $gebruikerId, 'import', -$cur, 0, $p['prijs'], $opm . ' (niet in Excel)']);
        $n['nul']++;
    }
    $pdo->commit();
    return $n;
}
