<?php
// Operatie: planning (per dagdeel), dagopdrachten, taken, foto's, meldingen en voorstellen.
// De planning in deze app is leidend. Claude doet alleen voorstellen; een mens geeft akkoord.

const DAGDELEN = ['ochtend' => 'Ochtend', 'middag' => 'Middag'];
const TAAK_STATUS = ['open' => 'Open', 'bezig' => 'Bezig', 'wacht' => 'Wacht op', 'klaar' => 'Klaar'];
const TAAK_PRIO = ['kritiek' => 'Kritiek', 'hoog' => 'Hoog', 'normaal' => 'Normaal', 'laag' => 'Laag'];
const FOTO_SOORT = ['uitvoering' => 'Uitvoering', 'schouw' => 'Schouw', 'oplevering' => 'Oplevering', 'probleem' => 'Probleem'];
const DAGEN_KORT = [1 => 'ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
const DAGEN_LANG = [1 => 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];
const MAANDEN = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

// ---------- Datums (altijd Nederlandse tijd, als 'Y-m-d') ----------
function vandaag(): string { return date('Y-m-d'); }
function geldige_datum($d): ?string
{
    $d = trim((string)$d);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : null;
}
function dag_plus(string $d, int $n): string { return date('Y-m-d', strtotime("$d " . ($n >= 0 ? '+' : '') . "$n day")); }
function maandag_van(string $d): string { return dag_plus($d, 1 - (int)date('N', strtotime($d))); }
function volgende_werkdag(string $d): string
{
    do { $d = dag_plus($d, 1); } while ((int)date('N', strtotime($d)) >= 6);
    return $d;
}
function datum_kort(string $d): string { $t = strtotime($d); return DAGEN_KORT[(int)date('N', $t)] . ' ' . date('d-m', $t); }
function datum_lang(string $d): string { $t = strtotime($d); return DAGEN_LANG[(int)date('N', $t)] . ' ' . (int)date('j', $t) . ' ' . MAANDEN[(int)date('n', $t)]; }
function weeknr(string $d): int { return (int)date('W', strtotime($d)); }

// ---------- Mensen ----------
function mensen_planbaar(): array
{
    return q('SELECT id, naam, gebruikersnaam, rol FROM gebruikers WHERE planbaar = 1 AND actief = 1 ORDER BY volgorde, naam')->fetchAll();
}
function mensen_kantoor(): array
{
    // Ook wie nog geen wachtwoord heeft: dan kunnen er al taken op zijn naam staan
    return q("SELECT id, naam, gebruikersnaam FROM gebruikers WHERE rol = 'admin' AND actief = 1 ORDER BY volgorde, naam")->fetchAll();
}
/** Zoekt een gebruiker op gebruikersnaam, volledige naam of voornaam. "Jules" is Julian. */
function gebruiker_zoek(?string $naam): ?int
{
    $n = mb_strtolower(clean_text($naam ?? ''));
    if ($n === '') return null;
    if ($n === 'jules') $n = 'julian';
    $r = q('SELECT id FROM gebruikers WHERE LOWER(gebruikersnaam) = ? OR LOWER(naam) = ? ORDER BY actief DESC LIMIT 1', [$n, $n])->fetch();
    if (!$r) $r = q('SELECT id FROM gebruikers WHERE LOWER(naam) LIKE ? ORDER BY actief DESC LIMIT 1', [$n . ' %'])->fetch();
    return $r ? (int)$r['id'] : null;
}

// ---------- Projecten ----------
// Fases zoals op het BESS-bord in Pipedrive (tot 07-12-2026 leidend daar, daarna in de app). Sleutel => [naam, Pipedrive phase_id, groep op het bord]
const FASES = [
    'nieuw' => ['Nieuw gewonnen', 39, 'voorbereiding'], 'werkbon' => ['Werkbon aanmaken', 54, 'voorbereiding'], 'materiaal' => ['Materialen bestellen', 42, 'voorbereiding'],
    'inplannen' => ['In te plannen', 55, 'voorbereiding'], 'gepland' => ['Gepland', 52, 'gepland'], 'bezig' => ['Bezig met installatie', 80, 'bezig'],
    'afgerond' => ['Installatie afgerond', 43, 'afgerond'], 'factuur' => ['Factuur gestuurd', 44, 'financieel'], 'betaald' => ['Betaald', 45, 'financieel'],
];
const FASE_GROEPEN = ['voorbereiding' => 'Voorbereiding', 'gepland' => 'Gepland', 'bezig' => 'Bezig', 'afgerond' => 'Afgerond', 'financieel' => 'Factuur en betaling'];
// Service-tickets (storingen en serviceverzoeken), zoals het bord "Service projecten" in Pipedrive. Sleutel => [naam, Pipedrive phase_id, groep]
const SERVICE_FASES = [
    'melding' => ['Nieuwe melding', 70, 'nieuw'], 'uitzoeken' => ['Bezig met uitzoeken', 71, 'bezig'], 'op_locatie' => ['Locatie in te plannen', 72, 'inplannen'],
    'ingepland' => ['Ingepland', 73, 'gepland'], 'wacht_klant' => ['Wachten op klant', 74, 'wacht'], 'opgelost' => ['Opgelost', 0, 'klaar'],
];
const SOORTEN = ['project' => 'Project', 'service' => 'Service'];
const APPARATEN = ['energieopslag' => 'Energieopslag', 'zonnepanelen' => 'Zonnepanelen', 'laadpaal' => 'Laadpaal', 'onbekend' => 'Onbekend'];
const URGENTIES = ['acuut' => 'Acuut: veiligheid', 'spoed' => 'Spoed', 'normaal' => 'Normaal', 'laag' => 'Laag'];
const AFHANDELINGEN = ['afstand' => 'Op afstand', 'eerst_afstand' => 'Eerst op afstand proberen', 'locatie' => 'Waarschijnlijk op locatie', 'bellen' => 'Direct bellen', 'onbekend' => 'Nog te bepalen'];
/** De fases die bij een soort horen (project of service). */
function fases(string $soort = 'project'): array { return $soort === 'service' ? SERVICE_FASES : FASES; }
function fase_naam(string $fase, string $soort = 'project'): string { return fases($soort)[$fase][0] ?? $fase; }
/** Pipedrive phase_id naar fase. Werkt voor het BESS-bord, het servicebord en "Nieuw bord" (11). Geeft [soort, fase] of null. */
function fase_van_pipedrive_bord(int $phaseId): ?array
{
    if ($phaseId <= 0) return null;
    foreach (FASES as $k => $f) if ($f[1] === $phaseId) return ['project', $k];
    foreach (SERVICE_FASES as $k => $f) if ($f[1] === $phaseId) return ['service', $k];
    // "Nieuw bord" (11): 75 nieuw gewonnen, 76 nieuwe service ticket, 77 opgepakte projecten, 78 opgepakte storingen, 79 gepland
    return [75 => ['project', 'nieuw'], 76 => ['service', 'melding'], 77 => ['project', 'bezig'], 78 => ['service', 'uitzoeken'], 79 => ['project', 'gepland']][$phaseId] ?? null;
}
function fase_van_pipedrive(int $phaseId): ?string { $f = fase_van_pipedrive_bord($phaseId); return $f && $f[0] === 'project' ? $f[1] : null; }
/** Nieuw ticketnummer in dezelfde vorm als het webformulier: WW-jjmmdd-XXXX */
function ticket_nummer_nieuw(): string
{
    $tekens = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $nr = 'WW-' . date('ymd') . '-';
        for ($i = 0; $i < 4; $i++) $nr .= $tekens[random_int(0, strlen($tekens) - 1)];
    } while (q('SELECT 1 FROM projecten WHERE nummer = ?', [$nr])->fetch());
    return $nr;
}
// Mijlpalen per project: vaste momenten zoals een levering, keuring of oplevering. Met de hand of door Claude (uit mail e.d.) gezet.
const MIJLPAAL_SOORT = ['levering' => 'Levering', 'installatie' => 'Installatie', 'netbeheer' => 'Netbeheerder', 'keuring' => 'Keuring', 'oplevering' => 'Oplevering', 'betaling' => 'Betaling', 'overig' => 'Overig'];
const MIJLPAAL_BRON = ['hand' => '', 'mail' => 'uit mail', 'whatsapp' => 'uit WhatsApp', 'teams' => 'uit Teams', 'pipedrive' => 'uit Pipedrive', 'inkoop' => 'uit inkoop', 'claude' => 'Claude'];
function mijlpalen(int $projectId): array
{
    return q('SELECT * FROM project_mijlpalen WHERE project_id = ? ORDER BY gedaan, datum IS NULL, datum, id', [$projectId])->fetchAll();
}
function mijlpaal_tekst(array $m): string { return ($m['datum'] ? datum_kort($m['datum']) . ' ' : '') . $m['titel'] . ($m['zeker'] ? '' : ' (verwacht)'); }
function fase_stap(string $fase, string $soort = 'project'): int { $i = array_search($fase, array_keys(fases($soort)), true); return $i === false ? 0 : $i; }
/** Grootte uit een omschrijving als "4x Hoymiles 125kW/261kWh" of "2 MWh": totaal in kWh, of null. */
function kwh_uit_tekst(string $t): ?int
{
    $t = str_replace(',', '.', $t);
    if (preg_match('/(\d+(?:\.\d+)?)\s*MWh/i', $t, $m)) return (int)round($m[1] * 1000);
    if (preg_match('/(\d+)\s*[x×].{0,40}?(\d+)\s*kWh/iu', $t, $m)) return (int)$m[1] * (int)$m[2];
    if (preg_match('/(\d+)\s*kWh/i', $t, $m)) return (int)$m[1];
    return null;
}
function project_naam(array $r): string
{
    if (($r['soort'] ?? '') === 'service' && ($r['klant'] ?? '') !== '') return (string)$r['klant'];
    // Korte naam: het deel voor het streepje ("Oostendorp – 4x Hoymiles ..." wordt "Oostendorp")
    $o = clean_text(preg_split('/\s[–—-]\s/u', (string)($r['omschrijving'] ?? ''), 2)[0]);
    return $o !== '' ? $o : (string)($r['nummer'] ?? '');
}
function route_url(string $adres): string { return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($adres); }

// ---------- Planning ----------
/** Geeft [datum][gebruiker_id][dagdeel] => rij (met projectgegevens). */
function planning_tussen(string $van, string $tot, ?int $gid = null): array
{
    $sql = "SELECT pl.*, pr.nummer, pr.omschrijving, pr.klant, pr.adres, pr.contact
            FROM planning pl LEFT JOIN projecten pr ON pr.id = pl.project_id
            WHERE pl.datum BETWEEN ? AND ?" . ($gid ? ' AND pl.gebruiker_id = ?' : '') . ' ORDER BY pl.datum, pl.dagdeel';
    $uit = [];
    foreach (q($sql, $gid ? [$van, $tot, $gid] : [$van, $tot])->fetchAll() as $r) {
        $uit[$r['datum']][(int)$r['gebruiker_id']][$r['dagdeel']] = $r;
    }
    return $uit;
}
/** Zet één dagdeel. Zonder project en zonder tekst wordt het dagdeel leeggemaakt. */
function plan_zet(string $datum, string $dagdeel, int $gid, ?int $pid, string $tekst, bool $voorbehoud, ?int $door): void
{
    if (!isset(DAGDELEN[$dagdeel])) throw new InvalidArgumentException('onbekend dagdeel: ' . $dagdeel);
    $tekst = mb_substr(clean_text($tekst), 0, 255);
    if ($pid === null && $tekst === '') {
        q('DELETE FROM planning WHERE datum = ? AND dagdeel = ? AND gebruiker_id = ?', [$datum, $dagdeel, $gid]);
        return;
    }
    q('INSERT INTO planning (datum, dagdeel, gebruiker_id, project_id, tekst, voorbehoud, bijgewerkt_door) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE project_id = VALUES(project_id), tekst = VALUES(tekst), voorbehoud = VALUES(voorbehoud), bijgewerkt_door = VALUES(bijgewerkt_door)',
        [$datum, $dagdeel, $gid, $pid, $tekst, $voorbehoud ? 1 : 0, $door]);
}
/** Korte tekst voor een dagdeel: projectnaam, met de vrije tekst erachter. */
function plan_tekst(?array $r): string
{
    if (!$r) return '';
    $delen = [];
    if ($r['project_id']) $delen[] = project_naam($r);
    if ($r['tekst'] !== '') $delen[] = $r['tekst'];
    return implode(' · ', $delen);
}
function plan_gelijk(?array $a, ?array $b): bool
{
    if (!$a || !$b) return !$a && !$b;
    return (int)$a['project_id'] === (int)$b['project_id'] && $a['tekst'] === $b['tekst'] && (int)$a['voorbehoud'] === (int)$b['voorbehoud'];
}

// ---------- Dagopdracht: wat de monteur die dag op dat project doet ----------
// Vrijgegeven = een beslisser (Ruben of Berend) heeft opgeslagen of akkoord gegeven; dat is in één keer goed.
// Wat iemand anders of Claude invult, moet eerst door een beslisser worden goedgekeurd.
function dagopdracht(string $datum, int $pid): ?array
{
    $r = q('SELECT d.*, a.naam akkoord1_naam, b.naam akkoord2_naam, a.beslisser a1b, b.beslisser a2b FROM dagopdrachten d
            LEFT JOIN gebruikers a ON a.id = d.akkoord1_door LEFT JOIN gebruikers b ON b.id = d.akkoord2_door
            WHERE d.datum = ? AND d.project_id = ?', [$datum, $pid])->fetch();
    return $r ?: null;
}
function dagopdracht_vrij(?array $o): bool
{
    return $o && (($o['akkoord1_door'] && !empty($o['a1b'])) || ($o['akkoord2_door'] && !empty($o['a2b'])));
}
/** Wie gaf het akkoord waardoor de instructie vrij is. */
function dagopdracht_akkoord_naam(array $o): string
{
    return !empty($o['a2b']) && $o['akkoord2_door'] ? (string)$o['akkoord2_naam'] : (string)$o['akkoord1_naam'];
}
/** leeg | concept (nog niemand keek) | controle (wacht op een beslisser) | vrij */
function dagopdracht_stand(?array $o): string
{
    if (dagopdracht_leeg($o)) return 'leeg';
    if (dagopdracht_vrij($o)) return 'vrij';
    return $o['akkoord1_door'] ? 'controle' : 'concept';
}
function dagopdracht_leeg(?array $o): bool
{
    return !$o || (trim($o['wat']) === '' && trim($o['meenemen']) === '' && trim($o['stroom']) === '');
}
/** Opslaan. Verandert de inhoud, dan vervalt de controle. Een mens die opslaat geeft daarmee het eerste akkoord. */
function dagopdracht_opslaan(string $datum, int $pid, string $wat, string $meenemen, string $stroom, ?int $door, string $wie, string $bron = ''): void
{
    $wat = trim($wat); $meenemen = trim($meenemen); $stroom = mb_substr(clean_text($stroom), 0, 255);
    $oud = dagopdracht($datum, $pid);
    if ($oud && $oud['wat'] === $wat && $oud['meenemen'] === $meenemen && $oud['stroom'] === $stroom) return;
    q('INSERT INTO dagopdrachten (datum, project_id, wat, meenemen, stroom, bron, akkoord1_door, akkoord1_op, akkoord2_door, akkoord2_op, bijgewerkt_door)
       VALUES (?,?,?,?,?,?,?,' . ($door ? 'UTC_TIMESTAMP()' : 'NULL') . ',NULL,NULL,?)
       ON DUPLICATE KEY UPDATE wat = VALUES(wat), meenemen = VALUES(meenemen), stroom = VALUES(stroom), bron = IF(VALUES(bron) <> \'\', VALUES(bron), bron),
         akkoord1_door = VALUES(akkoord1_door), akkoord1_op = VALUES(akkoord1_op), akkoord2_door = NULL, akkoord2_op = NULL, bijgewerkt_door = VALUES(bijgewerkt_door)',
        [$datum, $pid, $wat, $meenemen, $stroom, mb_substr($bron, 0, 255), $door, mb_substr($wie, 0, 100)]);
}
/** Akkoord geven. Een beslisser maakt de instructie direct vrij; een ander kan alleen "gezien" zetten. */
function dagopdracht_akkoord(int $id, int $door, bool $beslisser): string
{
    $o = q('SELECT * FROM dagopdrachten WHERE id = ?', [$id])->fetch();
    if (!$o) return 'Instructie niet gevonden.';
    if (dagopdracht_leeg($o)) return 'Er staat nog niets in de instructie.';
    if ($beslisser) {
        if (!$o['akkoord1_door'] || (int)$o['akkoord1_door'] === $door) q('UPDATE dagopdrachten SET akkoord1_door = ?, akkoord1_op = UTC_TIMESTAMP() WHERE id = ?', [$door, $id]);
        else q('UPDATE dagopdrachten SET akkoord2_door = ?, akkoord2_op = UTC_TIMESTAMP() WHERE id = ?', [$door, $id]);
        return 'Akkoord. De monteurs zien de instructie nu.';
    }
    if (!$o['akkoord1_door']) {
        q('UPDATE dagopdrachten SET akkoord1_door = ?, akkoord1_op = UTC_TIMESTAMP() WHERE id = ?', [$door, $id]);
        return 'Nagekeken door jou. Ruben of Berend moet nog akkoord geven.';
    }
    return 'Ruben of Berend moet akkoord geven.';
}

// ---------- Seintjes: korte meldingen voor één persoon, zichtbaar op elk scherm ----------
function beslissers(): array { return array_map('intval', array_column(q('SELECT id FROM gebruikers WHERE beslisser = 1 AND actief = 1')->fetchAll(), 'id')); }
function seintje(int $voor, string $tekst, string $link = ''): void
{
    q('INSERT INTO seintjes (gebruiker_id, tekst, link) VALUES (?,?,?)', [$voor, mb_substr(clean_text($tekst), 0, 500), mb_substr($link, 0, 255)]);
}
/** Seintje aan de projectleider; is die er niet, dan aan Ruben en Berend. De afzender krijgt het niet zelf. */
function seintje_project(?int $pid, string $tekst, string $link, int $nietAan = 0): void
{
    $leider = $pid ? (int)(q('SELECT leider_id FROM projecten WHERE id = ?', [$pid])->fetch()['leider_id'] ?? 0) : 0;
    foreach (array_unique($leider ? [$leider] : beslissers()) as $id) if ($id !== $nietAan) seintje($id, $tekst, $link);
}
function seintjes_open(int $gid): array
{
    return q('SELECT * FROM seintjes WHERE gebruiker_id = ? AND gezien_op IS NULL ORDER BY id DESC LIMIT 30', [$gid])->fetchAll();
}

// ---------- Notities: iedereen kan iets doorgeven; Claude verwerkt het bij de volgende ronde ----------
function notities_recent(?int $gid, int $max = 12): array
{
    return q('SELECT n.*, g.naam, pr.nummer, pr.omschrijving FROM notities n JOIN gebruikers g ON g.id = n.gebruiker_id
              LEFT JOIN projecten pr ON pr.id = n.project_id ' . ($gid ? 'WHERE n.gebruiker_id = ? ' : '') . 'ORDER BY n.id DESC LIMIT ' . (int)$max, $gid ? [$gid] : [])->fetchAll();
}

// ---------- Taken ----------
function taak_log(int $taakId, string $tekst, ?int $gid = null, string $wie = ''): void
{
    q('INSERT INTO taak_log (taak_id, gebruiker_id, wie, tekst) VALUES (?,?,?,?)', [$taakId, $gid, mb_substr($wie, 0, 100), mb_substr(clean_text($tekst), 0, 600)]);
}
function taak_maak(array $d, ?int $gid, string $wie): int
{
    $titel = mb_substr(clean_text($d['titel'] ?? ''), 0, 255);
    if ($titel === '') throw new InvalidArgumentException('titel ontbreekt');
    $prio = isset(TAAK_PRIO[$d['prioriteit'] ?? '']) ? $d['prioriteit'] : 'normaal';
    q('INSERT INTO taken (titel, toelichting, project_id, eigenaar_id, van_id, datum, deadline, volgorde, prioriteit, bron, extern_id, aangemaakt_door) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
        $titel, trim((string)($d['toelichting'] ?? '')) ?: null, $d['project_id'] ?? null, $d['eigenaar_id'] ?? null, $d['van_id'] ?? null,
        geldige_datum($d['datum'] ?? null), geldige_datum($d['deadline'] ?? null), (int)($d['volgorde'] ?? 100), $prio,
        mb_substr(clean_text($d['bron'] ?? ''), 0, 255), ($d['extern_id'] ?? '') !== '' ? mb_substr((string)$d['extern_id'], 0, 150) : null, mb_substr($wie, 0, 100),
    ]);
    $id = (int)db()->lastInsertId();
    taak_log($id, 'Aangemaakt' . (($d['bron'] ?? '') !== '' ? ' (' . $d['bron'] . ')' : ''), $gid, $wie);
    return $id;
}
function taak_zet_status(int $id, string $status, string $notitie, ?int $gid, string $wie, string $wachtOp = ''): void
{
    if (!isset(TAAK_STATUS[$status])) throw new InvalidArgumentException('onbekende status');
    q('UPDATE taken SET status = ?, wacht_op = ?, afgerond_op = ' . ($status === 'klaar' ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE id = ?',
        [$status, $status === 'wacht' ? mb_substr(clean_text($wachtOp), 0, 255) : '', $id]);
    $t = TAAK_STATUS[$status] . ($status === 'wacht' && $wachtOp !== '' ? ' ' . clean_text($wachtOp) : '');
    taak_log($id, $t . ($notitie !== '' ? ': ' . $notitie : ''), $gid, $wie);
}
/** Open taken, belangrijkste bovenaan. $eigenaar: id, 0 = nog te verdelen, null = alles. */
function taken_open(?int $eigenaar = null): array
{
    $w = "t.status <> 'klaar'"; $p = [];
    if ($eigenaar === 0) $w .= ' AND t.eigenaar_id IS NULL';
    elseif ($eigenaar !== null) { $w .= ' AND t.eigenaar_id = ?'; $p[] = $eigenaar; }
    // laatste = laatste regel uit het logboek die iets zegt over de stand (niet "Aangemaakt" of "Belang"/"Verzet")
    return q("SELECT t.*, pr.nummer, pr.omschrijving, g.naam eigenaar, v.naam van, v.rol van_rol,
              (SELECT CONCAT(l.aangemaakt, '|', COALESCE(NULLIF(l.wie, ''), ''), '|', l.tekst)
                 FROM taak_log l WHERE l.taak_id = t.id AND l.tekst NOT LIKE 'Aangemaakt%' AND l.tekst NOT LIKE 'Belang:%' AND l.tekst NOT LIKE 'Verzet naar%' AND l.tekst <> 'Taak aangepast'
                 ORDER BY l.id DESC LIMIT 1) laatste
              FROM taken t
              LEFT JOIN projecten pr ON pr.id = t.project_id LEFT JOIN gebruikers g ON g.id = t.eigenaar_id LEFT JOIN gebruikers v ON v.id = t.van_id
              WHERE $w ORDER BY COALESCE(t.datum, t.deadline, '2999-12-31'), t.volgorde, FIELD(t.prioriteit,'kritiek','hoog','normaal','laag'), t.deadline, t.id", $p)->fetchAll();
}
function taak_te_laat(array $t): bool { return $t['status'] !== 'klaar' && $t['deadline'] && $t['deadline'] < vandaag(); }
/** Op welke dag hoort de taak in Mijn dag: 'laat', een datum (eerder dan vandaag telt als vandaag) of 'geen'. */
function taak_dag(array $t): string
{
    if (taak_te_laat($t)) return 'laat';
    $d = $t['datum'] ?: $t['deadline'];
    if (!$d) return 'geen';
    return max($d, vandaag());
}
/** Zet een nieuwe doe-datum. Ligt de deadline daarvoor, dan schuift die mee (anders blijft de taak "te laat"). */
function taak_verzet(int $id, ?string $datum, ?string $prio, int $gid, string $wie): void
{
    $t = q('SELECT * FROM taken WHERE id = ?', [$id])->fetch();
    if (!$t) throw new InvalidArgumentException('taak niet gevonden');
    $log = [];
    if ($datum !== null) {
        $deadline = ($t['deadline'] && $t['deadline'] < $datum) ? $datum : $t['deadline'];
        q('UPDATE taken SET datum = ?, deadline = ? WHERE id = ?', [$datum, $deadline, $id]);
        $log[] = 'Verzet naar ' . datum_kort($datum) . ($deadline !== $t['deadline'] ? ' (uiterlijk ook ' . datum_kort($datum) . ')' : '');
    }
    if ($prio !== null && isset(TAAK_PRIO[$prio]) && $prio !== $t['prioriteit']) {
        q('UPDATE taken SET prioriteit = ? WHERE id = ?', [$prio, $id]);
        $log[] = 'Belang: ' . TAAK_PRIO[$prio];
    }
    if ($log) taak_log($id, implode(', ', $log), $gid, $wie);
}

// ---------- Planning verplaatsen (slepen, een heel project of een hele dag) ----------
/**
 * $moves: lijst van [datum, dagdeel, gid, naar_datum, naar_gid]. Geeft ['conflicten' => [...]] of voert uit.
 * Een beslisser wijzigt direct; van een ander wordt het een voorstel.
 */
function plan_verplaats(array $moves, bool $uitvoeren, array $u, string $titel): array
{
    $bron = []; $doel = [];
    foreach ($moves as [$d, $dd, $gid, $nd, $ngid]) {
        $r = q('SELECT pl.*, pr.nummer, pr.omschrijving FROM planning pl LEFT JOIN projecten pr ON pr.id = pl.project_id
                WHERE pl.datum = ? AND pl.dagdeel = ? AND pl.gebruiker_id = ?', [$d, $dd, $gid])->fetch();
        if (!$r) continue;
        $bron["$d|$dd|$gid"] = $r;
        $doel["$nd|$dd|$ngid"] = $r;
    }
    if (!$doel) return ['fout' => 'Er staat niets om te verplaatsen.'];
    $namen = [];
    foreach (q('SELECT id, naam, gebruikersnaam FROM gebruikers')->fetchAll() as $g) $namen[(int)$g['id']] = $g;
    // Botsingen: op de nieuwe plek staat al iets dat niet zelf meeverhuist
    $conf = [];
    foreach ($doel as $k => $r) {
        if (isset($bron[$k])) continue;
        [$nd, $dd, $ngid] = explode('|', $k);
        $nu = planning_tussen($nd, $nd, (int)$ngid)[$nd][(int)$ngid][$dd] ?? null;
        if ($nu && !plan_gelijk($nu, $r)) $conf[explode(' ', $namen[(int)$ngid]['naam'])[0] . ' ' . datum_kort($nd)][$dd] = plan_tekst($nu);
    }
    // Eén regel per persoon per dag; ochtend en middag samen als ze gelijk zijn
    $conf = array_map(fn($k, $v) => $k . ': ' . (count($v) === 2 && count(array_unique($v)) === 1 ? reset($v)
        : implode(', ', array_map(fn($dd, $t) => "$dd $t", array_keys($v), $v))), array_keys($conf), $conf);
    if ($conf && !$uitvoeren) return ['conflicten' => $conf];
    $vrij = array_diff_key($bron, $doel);   // plekken die leeg worden
    if (!is_beslisser()) {
        $w = [];
        foreach ($vrij as $k => $r) { [$d, $dd, $gid] = explode('|', $k); $w[] = ['datum' => $d, 'dagdeel' => $dd, 'wie' => $namen[(int)$gid]['gebruikersnaam'], 'project' => '', 'tekst' => '']; }
        foreach ($doel as $k => $r) { [$nd, $dd, $ngid] = explode('|', $k); $w[] = ['datum' => $nd, 'dagdeel' => $dd, 'wie' => $namen[(int)$ngid]['gebruikersnaam'], 'project' => (string)$r['nummer'], 'tekst' => $r['tekst'], 'voorbehoud' => (bool)$r['voorbehoud']]; }
        q('INSERT INTO voorstellen (titel, wijzigingen, bron) VALUES (?,?,?)', [mb_substr($titel, 0, 255), json_encode($w, JSON_UNESCAPED_UNICODE), 'Voorstel van ' . $u['naam']]);
        foreach (beslissers() as $b) seintje($b, explode(' ', $u['naam'])[0] . ' stelt voor: ' . $titel, 'planning.php#voorstellen');
        return ['ok' => true, 'bericht' => 'Staat klaar als voorstel. Ruben of Berend geeft akkoord.'];
    }
    db()->beginTransaction();
    foreach ($bron as $k => $r) { [$d, $dd, $gid] = explode('|', $k); plan_zet($d, $dd, (int)$gid, null, '', false, $u['id']); }
    foreach ($doel as $k => $r) { [$nd, $dd, $ngid] = explode('|', $k); plan_zet($nd, $dd, (int)$ngid, $r['project_id'] ? (int)$r['project_id'] : null, $r['tekst'], (bool)$r['voorbehoud'], $u['id']); }
    db()->commit();
    return ['ok' => true, 'bericht' => count($doel) . ' dagdelen verplaatst.'];
}
/** Dagdelen verwijderen. $slots: lijst van [datum, dagdeel, gid]. Beslisser direct, anders een voorstel. */
function plan_verwijder(array $slots, array $u, string $titel): array
{
    $weg = [];
    foreach ($slots as [$d, $dd, $gid]) {
        if (q('SELECT 1 FROM planning WHERE datum = ? AND dagdeel = ? AND gebruiker_id = ?', [$d, $dd, $gid])->fetch()) $weg[] = [$d, $dd, (int)$gid];
    }
    if (!$weg) return ['fout' => 'Er staat niets om te verwijderen.'];
    if (!is_beslisser()) {
        $namen = [];
        foreach (q('SELECT id, gebruikersnaam FROM gebruikers')->fetchAll() as $g) $namen[(int)$g['id']] = $g['gebruikersnaam'];
        $w = array_map(fn($x) => ['datum' => $x[0], 'dagdeel' => $x[1], 'wie' => $namen[$x[2]], 'project' => '', 'tekst' => ''], $weg);
        q('INSERT INTO voorstellen (titel, wijzigingen, bron) VALUES (?,?,?)', [mb_substr($titel, 0, 255), json_encode($w, JSON_UNESCAPED_UNICODE), 'Voorstel van ' . $u['naam']]);
        foreach (beslissers() as $b) seintje($b, explode(' ', $u['naam'])[0] . ' stelt voor: ' . $titel, 'planning.php#voorstellen');
        return ['ok' => true, 'bericht' => 'Staat klaar als voorstel. Ruben of Berend geeft akkoord.'];
    }
    foreach ($weg as [$d, $dd, $gid]) plan_zet($d, $dd, $gid, null, '', false, $u['id']);
    return ['ok' => true, 'bericht' => 'Verwijderd uit de planning (' . count($weg) . ' dagdelen).'];
}
/** Instructie van een project gaat mee naar de nieuwe dag, als daar nog geen instructie staat en niemand meer op de oude dag op dat project staat. */
function dagopdracht_mee(string $van, string $naar, int $pid): void
{
    if (q('SELECT 1 FROM planning WHERE datum = ? AND project_id = ?', [$van, $pid])->fetch()) return;
    if (q('SELECT 1 FROM dagopdrachten WHERE datum = ? AND project_id = ?', [$naar, $pid])->fetch()) return;
    q('UPDATE dagopdrachten SET datum = ? WHERE datum = ? AND project_id = ?', [$naar, $van, $pid]);
}
/** Verdeelt taken in vandaag, later en zonder datum. */
function taken_in_bakken(array $taken): array
{
    $v = vandaag(); $bak = ['vandaag' => [], 'later' => [], 'geen' => []];
    foreach ($taken as $t) {
        $d = $t['datum']; $dl = $t['deadline'];
        if (($d && $d <= $v) || ($dl && $dl <= $v)) $bak['vandaag'][] = $t;
        elseif ($d || $dl) $bak['later'][] = $t;
        else $bak['geen'][] = $t;
    }
    return $bak;
}

// ---------- Voorstellen voor de planning ----------
/** Maakt de wijzigingen van een voorstel leesbaar en controleert ze. Geeft [regels, fouten]. */
function voorstel_regels(array $wijzigingen): array
{
    $regels = []; $fouten = [];
    foreach ($wijzigingen as $i => $w) {
        $datum = geldige_datum($w['datum'] ?? null);
        $gid = gebruiker_zoek($w['wie'] ?? '');
        $dd = $w['dagdeel'] ?? 'dag';
        if (!$datum || !$gid || !in_array($dd, ['dag', 'ochtend', 'middag'], true)) { $fouten[] = 'regel ' . ($i + 1) . ': datum, persoon of dagdeel klopt niet'; continue; }
        $nr = project_nummer($w['project'] ?? '');
        $pr = $nr !== '' ? q('SELECT id, nummer, omschrijving FROM projecten WHERE nummer = ?', [$nr])->fetch() : null;
        if ($nr !== '' && !$pr) { $fouten[] = 'regel ' . ($i + 1) . ': project ' . $nr . ' bestaat niet'; continue; }
        $regels[] = ['datum' => $datum, 'dagdelen' => $dd === 'dag' ? ['ochtend', 'middag'] : [$dd], 'dagdeel' => $dd, 'gid' => $gid,
            'wie' => q('SELECT naam FROM gebruikers WHERE id = ?', [$gid])->fetch()['naam'],
            'pid' => $pr ? (int)$pr['id'] : null, 'project' => $pr ? project_naam($pr) : '',
            'tekst' => clean_text($w['tekst'] ?? ''), 'voorbehoud' => !empty($w['voorbehoud'])];
    }
    return [$regels, $fouten];
}
function voorstel_toepassen(int $id, int $door): int
{
    $v = q("SELECT * FROM voorstellen WHERE id = ? AND status = 'open'", [$id])->fetch();
    if (!$v) return 0;
    [$regels, $fouten] = voorstel_regels(json_decode($v['wijzigingen'], true) ?: []);
    if ($fouten) throw new RuntimeException(implode('; ', $fouten));
    $n = 0;
    db()->beginTransaction();
    foreach ($regels as $r) {
        foreach ($r['dagdelen'] as $dd) { plan_zet($r['datum'], $dd, $r['gid'], $r['pid'], $r['tekst'], $r['voorbehoud'], $door); $n++; }
    }
    q("UPDATE voorstellen SET status = 'akkoord', besloten_door = ?, besloten_op = UTC_TIMESTAMP() WHERE id = ?", [$door, $id]);
    db()->commit();
    return $n;
}

// ---------- Foto's (staan buiten de webmap; alleen zichtbaar na inloggen) ----------
function foto_map(): string { return dirname(__DIR__, 2) . '/voorraad-fotos'; }
/** Slaat een geüploade foto op. Geeft een foutmelding terug, of null als het gelukt is. */
function foto_opslaan(int $pid, array $upload, string $soort, string $omschrijving, int $gid): ?string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 'Uploaden is niet gelukt. Probeer het opnieuw.';
    if ($upload['size'] > 12 * 1024 * 1024) return 'De foto is te groot (meer dan 12 MB).';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) return 'Alleen foto\'s (jpg, png of webp).';
    $map = foto_map() . '/' . $pid;
    if (!is_dir($map) && !mkdir($map, 0750, true)) return 'De fotomap kan niet worden aangemaakt.';
    $naam = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($upload['tmp_name'], "$map/$naam")) return 'Opslaan is niet gelukt.';
    q('INSERT INTO fotos (project_id, gebruiker_id, bestand, soort, omschrijving) VALUES (?,?,?,?,?)',
        [$pid, $gid, $naam, isset(FOTO_SOORT[$soort]) ? $soort : 'uitvoering', mb_substr(clean_text($omschrijving), 0, 255)]);
    return null;
}

// ---------- Service-tickets ----------
/**
 * Ticket aanmaken of bijwerken. Bijwerken op "nummer" (WW-...) of op "ref" (id van het webformulier, dan wordt hetzelfde formulier nooit dubbel).
 * {"klant":"..","adres":"..","telefoon":"..","email":"..","apparaat":"energieopslag","urgentie":"normaal","afhandeling":"eerst_afstand",
 *  "foutcode":"..","titel":"korte omschrijving","beschrijving":"..","fase":"melding","eigenaar":"julian","ref":"<uuid>"}
 */
function ticket_opslaan(array $d, string $wie): array
{
    $best = null;
    if (!empty($d['nummer'])) $best = q("SELECT * FROM projecten WHERE nummer = ? AND soort = 'service'", [clean_text($d['nummer'])])->fetch() ?: null;
    if (!$best && !empty($d['ref'])) $best = q('SELECT * FROM projecten WHERE extern_ref = ?', [clean_text($d['ref'])])->fetch() ?: null;
    $klant = mb_substr(clean_text($d['klant'] ?? ''), 0, 150);
    $titel = clean_text($d['titel'] ?? '');
    $velden = [
        'klant' => $klant, 'adres' => mb_substr(clean_text($d['adres'] ?? ''), 0, 255),
        'telefoon' => mb_substr(clean_text($d['telefoon'] ?? ''), 0, 50), 'email' => mb_substr(clean_text($d['email'] ?? ''), 0, 150),
        'apparaat' => isset(APPARATEN[$d['apparaat'] ?? '']) ? $d['apparaat'] : '', 'urgentie' => isset(URGENTIES[$d['urgentie'] ?? '']) ? $d['urgentie'] : '',
        'afhandeling' => isset(AFHANDELINGEN[$d['afhandeling'] ?? '']) ? $d['afhandeling'] : '', 'foutcode' => mb_substr(clean_text($d['foutcode'] ?? ''), 0, 100),
        'beschrijving' => trim((string)($d['beschrijving'] ?? '')), 'contact' => mb_substr(clean_text($d['contact'] ?? ''), 0, 255),
    ];
    if (!$best) {
        if ($klant === '' && $titel === '') throw new InvalidArgumentException('klant of titel ontbreekt');
        // Het webformulier maakt zelf al een ticketnummer (WW-jjmmdd-XXXX); dat houden we aan zodat de klant hetzelfde nummer ziet
        $gegeven = strtoupper(clean_text($d['ticketnummer'] ?? ''));
        $nr = preg_match('/^WW-\d{6}-[A-Z0-9]{4}$/', $gegeven) && !q('SELECT 1 FROM projecten WHERE nummer = ?', [$gegeven])->fetch() ? $gegeven : ticket_nummer_nieuw();
        q("INSERT INTO projecten (nummer, soort, omschrijving, fase, startdatum, extern_ref) VALUES (?, 'service', ?, 'melding', ?, ?)",
            [$nr, mb_substr($titel !== '' ? $titel : 'Melding ' . $klant, 0, 255), vandaag(), ($d['ref'] ?? '') !== '' ? mb_substr(clean_text($d['ref']), 0, 64) : null]);
        $best = q('SELECT * FROM projecten WHERE id = ?', [(int)db()->lastInsertId()])->fetch();
        $nieuw = true;
    } else $nieuw = false;
    $pid = (int)$best['id'];
    foreach ($velden as $k => $v) if ($v !== '' && ($nieuw || !empty($d['overschrijf']) || ($best[$k] ?? '') === '' || $best[$k] === null)) q("UPDATE projecten SET `$k` = ? WHERE id = ?", [$v, $pid]);
    if ($titel !== '' && !$nieuw && !empty($d['overschrijf'])) q('UPDATE projecten SET omschrijving = ? WHERE id = ?', [mb_substr($titel, 0, 255), $pid]);
    if (isset($d['fase']) && isset(SERVICE_FASES[$d['fase']])) ticket_fase($pid, $d['fase']);
    if (($d['eigenaar'] ?? '') !== '' && ($g = gebruiker_zoek((string)$d['eigenaar']))) q('UPDATE projecten SET leider_id = ? WHERE id = ?', [$g, $pid]);
    if ($nieuw) {
        q("INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst) VALUES (?, ?, 'app', ?, 'Ticket aangemaakt', ?)",
            [$pid, date('Y-m-d H:i:s'), mb_substr($wie, 0, 150), $velden['beschrijving'] ?: null]);
        // Bij acuut of spoed krijgt kantoor direct een seintje; de rest staat op het servicebord
        if (in_array($velden['urgentie'], ['acuut', 'spoed'], true))
            foreach (mensen_kantoor() as $m) seintje((int)$m['id'], 'SPOED ticket: ' . mb_substr($best['omschrijving'] ?: $klant, 0, 150), 'project.php?nr=' . rawurlencode($best['nummer']));
    }
    return ['nummer' => $best['nummer'], 'nieuw' => $nieuw] + q('SELECT omschrijving AS naam, klant, fase, urgentie, apparaat, afhandeling, telefoon FROM projecten WHERE id = ?', [$pid])->fetch();
}
/** Fase van een ticket zetten; "opgelost" sluit het ticket af. */
function ticket_fase(int $pid, string $fase): void
{
    if (!isset(SERVICE_FASES[$fase])) throw new InvalidArgumentException('onbekende fase');
    q('UPDATE projecten SET fase = ?, afgerond = ?, opgelost_op = ' . ($fase === 'opgelost' ? 'COALESCE(opgelost_op, UTC_TIMESTAMP())' : 'NULL') . ' WHERE id = ?', [$fase, $fase === 'opgelost' ? 1 : 0, $pid]);
}

// ---------- Agenda ----------
/** Tijd "2026-12-03 14:30" (Nederlandse tijd) of null. */
function geldige_tijd($t): ?string
{
    $t = trim(str_replace('T', ' ', (string)$t));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) $t .= ' 00:00';
    $d = DateTime::createFromFormat('Y-m-d H:i', substr($t, 0, 16), new DateTimeZone('Europe/Amsterdam'));
    return $d ? $d->format('Y-m-d H:i:00') : null;
}
/**
 * Afspraak aanmaken of bijwerken (zelfde extern_id = zelfde afspraak).
 * {"titel":"..","start":"2026-12-03 14:30","eind":"2026-12-03 15:00","hele_dag":false,"wie":"ruben","project":"PRJ-293","locatie":"..","notitie":"..","bron":"..","extern_id":".."}
 * "geannuleerd":true zegt een afspraak af (hij blijft bewaard en verdwijnt uit de agenda).
 */
function afspraak_opslaan(array $d, string $door): array
{
    $ext = clean_text($d['extern_id'] ?? '') ?: null;
    $best = $ext ? q('SELECT * FROM afspraken WHERE extern_id = ?', [$ext])->fetch() : null;
    if (!$best && !empty($d['id'])) $best = q('SELECT * FROM afspraken WHERE id = ?', [(int)$d['id']])->fetch() ?: null;
    $wie = ($d['wie'] ?? '') !== '' ? gebruiker_zoek((string)$d['wie']) : null;
    if (($d['wie'] ?? '') !== '' && !$wie) throw new InvalidArgumentException('onbekend: ' . $d['wie']);
    $pid = ($d['project'] ?? '') !== '' ? (q('SELECT id FROM projecten WHERE nummer = ?', [project_nummer((string)$d['project'])])->fetch()['id'] ?? null) : null;
    $start = array_key_exists('start', $d) ? geldige_tijd($d['start']) : ($best['start'] ?? null);
    if (!$start) throw new InvalidArgumentException('start ontbreekt of is ongeldig (JJJJ-MM-DD UU:MM)');
    $heleDag = array_key_exists('hele_dag', $d) ? (!empty($d['hele_dag']) ? 1 : 0) : (int)($best['hele_dag'] ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($d['start'] ?? ''))) ? 1 : 0));
    $eind = array_key_exists('eind', $d) ? geldige_tijd($d['eind']) : ($best['eind'] ?? null);
    if (!$eind && !$heleDag) $eind = date('Y-m-d H:i:00', strtotime($start . ' +30 minutes'));
    $v = [
        'titel' => mb_substr(clean_text($d['titel'] ?? ($best['titel'] ?? '')), 0, 255),
        'gebruiker_id' => $wie ?? ($best['gebruiker_id'] ?? null), 'project_id' => $pid ?? ($best['project_id'] ?? null),
        'start' => $start, 'eind' => $eind, 'hele_dag' => $heleDag,
        'locatie' => mb_substr(clean_text($d['locatie'] ?? ($best['locatie'] ?? '')), 0, 255),
        'notitie' => array_key_exists('notitie', $d) ? (trim((string)$d['notitie']) ?: null) : ($best['notitie'] ?? null),
        'bron' => mb_substr(clean_text($d['bron'] ?? ($best['bron'] ?? '')), 0, 255),
        'geannuleerd' => array_key_exists('geannuleerd', $d) ? (!empty($d['geannuleerd']) ? 1 : 0) : (int)($best['geannuleerd'] ?? 0),
    ];
    if ($v['titel'] === '') throw new InvalidArgumentException('titel ontbreekt');
    if ($best) {
        q('UPDATE afspraken SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ' WHERE id = ?', [...array_values($v), $best['id']]);
        $id = (int)$best['id'];
    } else {
        q('INSERT INTO afspraken (' . implode(', ', array_keys($v)) . ', extern_id, aangemaakt_door) VALUES (' . str_repeat('?,', count($v)) . '?, ?)', [...array_values($v), $ext, mb_substr($door, 0, 100)]);
        $id = (int)db()->lastInsertId();
        if ($v['gebruiker_id'] && $door !== '' && (user()['id'] ?? 0) !== (int)$v['gebruiker_id'])
            seintje((int)$v['gebruiker_id'], 'Nieuwe afspraak: ' . mb_substr($v['titel'], 0, 120) . ' (' . datum_kort(substr($start, 0, 10)) . ($heleDag ? '' : ' ' . substr($start, 11, 5)) . ')', 'agenda.php');
    }
    return ['id' => $id, 'nieuw' => !$best] + afspraak_rij($id);
}
function afspraak_rij(int $id): array
{
    return q("SELECT a.titel, a.start, a.eind, a.hele_dag, a.locatie, a.geannuleerd, g.gebruikersnaam AS wie, p.nummer AS project, a.extern_id
              FROM afspraken a LEFT JOIN gebruikers g ON g.id = a.gebruiker_id LEFT JOIN projecten p ON p.id = a.project_id WHERE a.id = ?", [$id])->fetch() ?: [];
}
/** Persoonlijke sleutel voor het agenda-abonnement; nieuw = de oude link werkt niet meer. */
function agenda_token(int $gid, bool $nieuw = false): string
{
    $t = q('SELECT agenda_token FROM gebruikers WHERE id = ?', [$gid])->fetch()['agenda_token'] ?? null;
    if (!$t || $nieuw) { $t = bin2hex(random_bytes(20)); q('UPDATE gebruikers SET agenda_token = ? WHERE id = ?', [$t, $gid]); }
    return $t;
}
