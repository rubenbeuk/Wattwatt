<?php
// Onderhoudscontracten en reactietijden (ronde 3, 28-11-2026).
// Een contract hoort bij een klant en meestal een locatie (soms één installatie). Het geeft:
//  - periodiek onderhoud: de app maakt het onderhoudsticket zelf aan, een maand voor de datum;
//  - een reactietijd: een nieuw ticket op die locatie krijgt een "reageren voor"-tijd op het servicebord.
// Reageren = het ticket uit "Nieuwe melding" halen (uitzoeken, inplannen, klant bellen). Tijden staan in UTC, zoals de rest van de database.

const CONTRACT_SOORTEN = ['onderhoud' => 'Onderhoud', 'onderhoud_monitoring' => 'Onderhoud en monitoring', 'monitoring' => 'Monitoring', 'service' => 'Service en storingen'];
const CONTRACT_STATUS = ['actief' => 'Actief', 'opgezegd' => 'Opgezegd', 'verlopen' => 'Verlopen'];
const CONTRACT_VELDEN = ['klant_id', 'locatie_id', 'installatie_id', 'omschrijving', 'soort', 'startdatum', 'einddatum', 'interval_maanden', 'volgende_onderhoud', 'reactietijd_uren', 'prijs_jaar', 'status', 'notitie'];
const ONDERHOUD_VOORUIT_DAGEN = 30;

function contract_opslaan(array $d, string $wie): array
{
    $v = [];
    foreach (CONTRACT_VELDEN as $k) {
        if (!array_key_exists($k, $d)) continue;
        $x = $k === 'notitie' ? trim((string)$d[$k]) : clean_text((string)$d[$k]);
        $v[$k] = match ($k) {
            'klant_id', 'locatie_id', 'installatie_id' => (int)$x ?: null,
            'soort' => isset(CONTRACT_SOORTEN[$x]) ? $x : 'onderhoud',
            'status' => isset(CONTRACT_STATUS[$x]) ? $x : 'actief',
            'startdatum', 'einddatum', 'volgende_onderhoud' => geldige_datum($x),
            'interval_maanden' => max(0, min(60, (int)$x)),
            'reactietijd_uren' => ($n = (int)$x) > 0 ? min($n, 2000) : null,
            'prijs_jaar' => ($n = num($x)) !== null && $n >= 0 ? $n : null,
            'notitie' => $x !== '' ? $x : null,
            default => mb_substr($x, 0, 255),
        };
    }
    $id = (int)($d['id'] ?? 0);
    $oud = $id ? q('SELECT * FROM contracten WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$oud) return ['fout' => 'contract niet gevonden'];
    $m = array_merge($oud ?: [], $v);
    if (!empty($m['installatie_id'])) {
        $ins = q('SELECT locatie_id FROM installaties WHERE id = ?', [$m['installatie_id']])->fetch();
        if (!$ins) return ['fout' => 'installatie niet gevonden'];
        if (empty($m['locatie_id']) && $ins['locatie_id']) $v['locatie_id'] = $m['locatie_id'] = (int)$ins['locatie_id'];
    }
    if (!empty($m['locatie_id'])) {
        $loc = q('SELECT klant_id FROM locaties WHERE id = ?', [$m['locatie_id']])->fetch();
        if (!$loc) return ['fout' => 'locatie niet gevonden'];
        if (empty($m['klant_id']) && $loc['klant_id']) $v['klant_id'] = $m['klant_id'] = (int)$loc['klant_id'];
    }
    if (empty($m['klant_id'])) return ['fout' => 'kies een klant of locatie'];
    if (!q('SELECT 1 FROM klanten WHERE id = ?', [$m['klant_id']])->fetch()) return ['fout' => 'klant niet gevonden'];
    // Eerste onderhoud: start + interval, als het niet is ingevuld
    if (empty($m['volgende_onderhoud']) && (int)($m['interval_maanden'] ?? 12) > 0 && !empty($m['startdatum']))
        $v['volgende_onderhoud'] = date('Y-m-d', strtotime($m['startdatum'] . ' +' . (int)($m['interval_maanden'] ?? 12) . ' months'));
    if (($m['omschrijving'] ?? '') === '' && !$oud) $v['omschrijving'] = CONTRACT_SOORTEN[$m['soort'] ?? 'onderhoud'] ?? 'Onderhoud';
    $v['bijgewerkt_door'] = mb_substr($wie, 0, 100);
    if ($oud) {
        q('UPDATE contracten SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v))) . ' WHERE id = ?', [...array_values($v), $id]);
        return ['id' => $id, 'nieuw' => false];
    }
    q('INSERT INTO contracten (' . implode(', ', array_map(fn($k) => "`$k`", array_keys($v))) . ') VALUES (' . implode(', ', array_fill(0, count($v), '?')) . ')', array_values($v));
    return ['id' => (int)db()->lastInsertId(), 'nieuw' => true];
}

/** Lopend contract voor een locatie (of anders de klant), met de kortste reactietijd voorop. */
function contract_lopend(?int $lid, ?int $kid, ?int $insId = null): ?array
{
    if (!$lid && !$kid) return null;
    $v = vandaag();
    $r = q("SELECT * FROM contracten WHERE status = 'actief' AND (startdatum IS NULL OR startdatum <= ?) AND (einddatum IS NULL OR einddatum >= ?)
              AND ((locatie_id IS NOT NULL AND locatie_id = ?) OR (locatie_id IS NULL AND klant_id = ?))
            ORDER BY (installatie_id IS NOT NULL AND installatie_id = ?) DESC, locatie_id IS NULL, reactietijd_uren IS NULL, reactietijd_uren LIMIT 1",
        [$v, $v, $lid ?: 0, $kid ?: 0, $insId ?: 0])->fetch();
    return $r ?: null;
}

/** Nieuw ticket: koppel het lopende contract en zet de reactietijd. Geeft het contract terug (of null). */
function ticket_reactietijd(int $pid): ?array
{
    $t = q('SELECT id, klant_id, locatie_id, installatie_id, contract_id, reactie_voor, aangemaakt, labels FROM projecten WHERE id = ?', [$pid])->fetch();
    if (!$t) return null;
    $c = $t['contract_id'] ? (q('SELECT * FROM contracten WHERE id = ?', [$t['contract_id']])->fetch() ?: null) : contract_lopend($t['locatie_id'] ? (int)$t['locatie_id'] : null, $t['klant_id'] ? (int)$t['klant_id'] : null, $t['installatie_id'] ? (int)$t['installatie_id'] : null);
    if (!$c) return null;
    if (!$t['contract_id']) q('UPDATE projecten SET contract_id = ? WHERE id = ?', [$c['id'], $pid]);
    if (!$t['reactie_voor'] && $c['reactietijd_uren'] && !str_contains((string)$t['labels'], 'onderhoud'))
        q('UPDATE projecten SET reactie_voor = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', strtotime(($t['aangemaakt'] ?: gmdate('Y-m-d H:i:s')) . ' UTC') + 3600 * (int)$c['reactietijd_uren']), $pid]);
    return $c;
}

/** Wat het servicebord over de reactietijd zegt: null als er geen is. ['tekst', 'klasse'] */
function reactie_status(array $t): ?array
{
    if (empty($t['reactie_voor'])) return null;
    $voor = strtotime($t['reactie_voor'] . ' UTC'); // tijden in de database staan in UTC
    if (!empty($t['gereageerd_op'])) {
        $op = strtotime($t['gereageerd_op'] . ' UTC') <= $voor;
        return ['tekst' => $op ? 'binnen de reactietijd opgepakt' : 'reactietijd overschreden', 'klasse' => $op ? 'muted' : 'rood'];
    }
    $rest = $voor - time();
    if ($rest <= 0) return ['tekst' => 'reactietijd verstreken (' . fmt_dt($t['reactie_voor']) . ')', 'klasse' => 'rood'];
    $u = $rest / 3600;
    $tekst = 'reageren binnen ' . ($u < 1 ? max(1, (int)round($rest / 60)) . ' min' : ($u < 48 ? (int)floor($u) . ' uur' : (int)floor($u / 24) . ' dagen'));
    return ['tekst' => $tekst, 'klasse' => $u < 4 ? 'rood' : ($u < 24 ? 'oranje' : 'muted')];
}

/**
 * Onderhoudstickets maken voor contracten waarvan het onderhoud binnen een maand valt.
 * Eén open onderhoudsticket per contract; daarna schuift volgende_onderhoud één interval op.
 * Verlopen contracten krijgen de status "verlopen". Geeft de nieuwe ticketnummers terug.
 */
function contracten_onderhoud_maken(string $wie = 'app', int $vooruit = ONDERHOUD_VOORUIT_DAGEN): array
{
    $v = vandaag();
    q("UPDATE contracten SET status = 'verlopen' WHERE status = 'actief' AND einddatum IS NOT NULL AND einddatum < ?", [$v]);
    $tot = date('Y-m-d', strtotime("$v +$vooruit days"));
    $nieuw = [];
    $lijst = q("SELECT c.*, k.naam klantnaam, k.telefoon ktel, k.email kmail, l.adres ladres, l.telefoon ltel, l.contact lcontact
                FROM contracten c LEFT JOIN klanten k ON k.id = c.klant_id LEFT JOIN locaties l ON l.id = c.locatie_id
                WHERE c.status = 'actief' AND c.interval_maanden > 0 AND c.volgende_onderhoud IS NOT NULL AND c.volgende_onderhoud <= ?
                  AND (c.einddatum IS NULL OR c.einddatum >= c.volgende_onderhoud) LIMIT 50", [$tot])->fetchAll();
    foreach ($lijst as $c) {
        if (q("SELECT 1 FROM projecten WHERE contract_id = ? AND fase <> 'opgelost' AND labels LIKE '%onderhoud%' LIMIT 1", [$c['id']])->fetch()) continue;
        $ins = $c['installatie_id'] ? q('SELECT * FROM installaties WHERE id = ?', [$c['installatie_id']])->fetch() : null;
        $r = ticket_opslaan([
            'klant' => $c['klantnaam'] ?: 'Klant ' . $c['klant_id'],
            'titel' => $ins ? 'Periodiek onderhoud ' . installatie_naam($ins) : (stripos($c['omschrijving'], 'onderhoud') !== false ? $c['omschrijving'] : trim('Periodiek onderhoud ' . $c['omschrijving'])),
            'adres' => $c['ladres'] ?? '', 'telefoon' => ($c['ltel'] ?? '') ?: ($c['ktel'] ?? ''), 'email' => $c['kmail'] ?? '',
            'contact' => $c['lcontact'] ?? '', 'urgentie' => 'laag', 'afhandeling' => 'locatie',
            'beschrijving' => 'Onderhoud volgens contract (' . (CONTRACT_SOORTEN[$c['soort']] ?? $c['soort']) . ', elke ' . (int)$c['interval_maanden'] . ' maanden). Uiterlijk ' . datum_lang($c['volgende_onderhoud']) . ' uitvoeren.' . ($c['notitie'] ? "\n\n" . $c['notitie'] : ''),
        ], $wie);
        $pid = project_id($r['nummer']);
        q("UPDATE projecten SET contract_id = ?, klant_id = ?, locatie_id = COALESCE(?, locatie_id), installatie_id = ?, labels = TRIM(CONCAT(labels, ' onderhoud')), einddatum = ?, reactie_voor = NULL, fase = 'op_locatie' WHERE id = ?",
            [$c['id'], $c['klant_id'], $c['locatie_id'], $c['installatie_id'], $c['volgende_onderhoud'], $pid]);
        q('UPDATE contracten SET volgende_onderhoud = DATE_ADD(volgende_onderhoud, INTERVAL interval_maanden MONTH) WHERE id = ?', [$c['id']]);
        foreach (mensen_kantoor() as $m) seintje((int)$m['id'], 'Onderhoud inplannen: ' . ($c['klantnaam'] ?: $r['nummer']) . ' (uiterlijk ' . datum_kort($c['volgende_onderhoud']) . ')', 'project.php?nr=' . rawurlencode($r['nummer']));
        $nieuw[] = $r['nummer'];
    }
    return $nieuw;
}

/** Eén keer per dag, vanuit het servicebord of de brein-run. */
function contracten_dagelijks(): array
{
    $sleutel = 'contracten_laatst';
    $laatst = q('SELECT waarde FROM brein_stand WHERE sleutel = ?', [$sleutel])->fetchColumn();
    if ($laatst === vandaag()) return [];
    q('INSERT INTO brein_stand (sleutel, waarde) VALUES (?, ?) ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)', [$sleutel, vandaag()]);
    return contracten_onderhoud_maken();
}

function contract_naam(array $c): string
{
    return trim(($c['omschrijving'] ?: (CONTRACT_SOORTEN[$c['soort']] ?? 'Contract')) . ($c['reactietijd_uren'] ? ' · reactie ' . (int)$c['reactietijd_uren'] . ' uur' : ''));
}
