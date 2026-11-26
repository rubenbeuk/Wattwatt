<?php
// Overstap van Pipedrive Projects naar de app (Ruben, 26-11-2026: "maak deze overstap nu gelijk. Dan stoppen we met pipedrive").
// Haalt projecten, service-tickets, notities en open taken uit Pipedrive en zet ze in de app. Mag vaker draaien:
// bestaande projecten worden aangevuld en bijgewerkt, niets wordt dubbel aangemaakt. Pipedrive zelf wordt nooit gewijzigd.
// Pipedrive blijft wel in gebruik voor sales (deals); alleen het projectdeel verhuist.

// Pipedrive-gebruiker => gebruikersnaam in de app
const PD_GEBRUIKERS = [
    18683925 => 'ruben', 12657391 => 'julian', 20043690 => 'berend', 19427283 => 'berend', 21067427 => 'boris', 20043679 => 'fay',
    19634061 => 'matthijs', 21067416 => 'milo', 25463511 => 'nick', 25316507 => 'sebastiaan', 21133394 => 'stef', 22403047 => 'thomas', 29808258 => 'tristan',
];
const PD_LABELS = [183 => 'BESS Hoymiles', 184 => 'Thuisbatterij', 185 => 'Bess + PV', 186 => 'Oplevering 2026', 200 => 'Installatie werk', 211 => 'Jules Project'];
const PD_VELD = [
    'ticket' => 'dd397681ac585c642707f608c54f94a7d5c33c06', 'apparaat' => '0407c54d1c6103cc84a9cdb35745c13efc03ffdb',
    'urgentie' => 'f18198934578d9443edc7aeee01e75a1926fe1e1', 'afhandeling' => '0952d5d811d8e76710ba5852c6c7c10e2d19ce9a',
    'foutcode' => 'c5aaa79b20d008bb6edac451bb66fa1bc8a606e3', 'telefoon' => '1e28e565789858c22e664b5cfc79cd59225ac3dc',
];
const PD_KEUZE = [
    'apparaat' => [187 => 'energieopslag', 188 => 'zonnepanelen', 189 => 'laadpaal', 190 => 'onbekend'],
    'urgentie' => [191 => 'acuut', 192 => 'spoed', 193 => 'normaal', 194 => 'laag'],
    'afhandeling' => [195 => 'afstand', 196 => 'eerst_afstand', 197 => 'locatie', 198 => 'bellen', 199 => 'onbekend'],
];
// Containers in Pipedrive die geen echt project zijn
const PD_OVERSLAAN = ['Project voor losse taken'];

function pd_gebruiker($id): ?int { return $id && isset(PD_GEBRUIKERS[(int)$id]) ? gebruiker_zoek(PD_GEBRUIKERS[(int)$id]) : null; }

/** Pipedrive-HTML (notities) naar leesbare tekst met regels. */
function pd_tekst(?string $h): string
{
    $h = (string)$h;
    $h = preg_replace('~<\s*(br|/div|/p|/li|/h\d|/tr)\s*/?>~i', "\n", $h);
    $h = preg_replace('~<\s*li[^>]*>~i', '- ', $h);
    $h = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $h = str_replace("\u{a0}", ' ', $h);
    $h = preg_replace("/[ \t]+\n/", "\n", $h);
    return trim(preg_replace("/\n{3,}/", "\n\n", $h));
}
/** Pipedrive-tijd (UTC) naar Nederlandse tijd. */
function pd_tijd(?string $t): string
{
    if (!$t) return date('Y-m-d H:i:s');
    $d = new DateTime(str_replace('T', ' ', substr($t, 0, 19)), new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i:s');
}

/** Welk nummer krijgt dit Pipedrive-project in de app, en bestaat het al? Geeft [nummer, bestaande rij of null]. */
function pd_nummer(array $p, string $soort): array
{
    $titel = clean_text($p['title'] ?? '');
    $ticket = clean_text($p['custom_fields'][PD_VELD['ticket']] ?? '');
    $best = q('SELECT * FROM projecten WHERE pipedrive_id = ? ORDER BY id LIMIT 1', [(int)$p['id']])->fetch() ?: null;
    if ($best) return [$best['nummer'], $best];
    if (preg_match('/^PRJ[\s\-]*(\d{3,5})\b/i', $titel, $m)) $nr = 'PRJ-' . $m[1];
    elseif ($ticket !== '') $nr = $ticket;
    else $nr = ($soort === 'service' ? 'SRV-' : 'PD-') . (int)$p['id'];
    $best = q('SELECT * FROM projecten WHERE nummer = ?', [$nr])->fetch() ?: null;
    return [$nr, $best];
}

/**
 * Eén Pipedrive-project (zoals de API het geeft) in de app zetten, met notities en taken.
 * Bestaande gegevens in de app blijven staan; lege velden worden aangevuld. Fase, labels en koppelingen komen uit Pipedrive.
 */
function pd_project_importeren(array $p, array $notities = [], array $taken = []): array
{
    $titel = clean_text($p['title'] ?? '');
    if (in_array($titel, PD_OVERSLAAN, true)) return ['pipedrive_id' => (int)$p['id'], 'overgeslagen' => $titel];

    $bordFase = fase_van_pipedrive_bord((int)($p['phase_id'] ?? 0));
    $soort = (int)($p['board_id'] ?? 0) === 10 ? 'service' : ($bordFase[0] ?? 'project');
    $fase = $bordFase && $bordFase[0] === $soort ? $bordFase[1] : ($soort === 'service' ? 'melding' : 'nieuw');
    $klaar = in_array($p['status'] ?? 'open', ['completed', 'canceled'], true);
    if ($klaar && $soort === 'service') $fase = 'opgelost';

    [$nummer, $best] = pd_nummer($p, $soort);
    $nieuw = !$best;
    $cf = $p['custom_fields'] ?? [];
    $naam = clean_text(preg_replace('/^PRJ[\s\-]*\d{3,5}\s*/i', '', $titel));
    if ($soort === 'service') {
        // Bij tickets is de naam het probleem; de klant krijgt een eigen veld. "Klant (Apparaat) · probleem" of "Klant · WW-nummer"
        $probleem = str_contains($naam, '·') ? clean_text(explode('·', $naam, 2)[1]) : '';
        if ($probleem === '' || preg_match('/^WW-\d{6}-\w{4}$/', $probleem)) {
            $probleem = preg_match('/(?:Wat de klant merkt|Wat er mis is):\s*(.+)/u', (string)($p['description'] ?? ''), $wm) ? clean_text($wm[1]) : clean_text(strtok((string)($p['description'] ?? ''), "\n") ?: '');
            if (preg_match('/^(Ticket WW-|VEILIGHEID|SPOED)/i', $probleem)) $probleem = clean_text(explode("\n", (string)$p['description'])[1] ?? '');
        }
        $naam = $probleem !== '' ? $probleem : $naam;
    }
    $labels = implode(', ', array_filter(array_map(fn($l) => PD_LABELS[(int)$l] ?? null, $p['label_ids'] ?? [])));
    $velden = [
        'omschrijving' => mb_substr($naam, 0, 255),
        'beschrijving' => pd_tekst($p['description'] ?? '') ?: null,
        'leider_id' => pd_gebruiker($p['owner_id'] ?? null),
        'startdatum' => geldige_datum($p['start_date'] ?? ''),
        'einddatum' => geldige_datum($p['end_date'] ?? ''),
        'telefoon' => mb_substr(clean_text($cf[PD_VELD['telefoon']] ?? ''), 0, 50),
        'foutcode' => mb_substr(clean_text($cf[PD_VELD['foutcode']] ?? ''), 0, 100),
    ];
    foreach (PD_KEUZE as $k => $opties) $velden[$k] = $opties[(int)($cf[PD_VELD[$k]] ?? 0)] ?? '';
    if ($soort === 'project') { $velden['opleverdatum'] = $velden['einddatum']; $velden['kwh'] = kwh_uit_tekst($titel . ' ' . ($p['description'] ?? '')); }
    // Klantnaam staat bij tickets vooraan in de titel: "Walter Roubos (Energieopslag) · ..." of "Anton van der Gun, Reijerscop 27 Utrecht (...)"
    if ($soort === 'service') {
        $velden['klant'] = mb_substr(clean_text(preg_split('/\s*(?:\(|·|,)\s*/u', preg_replace('/^PRJ[\s\-]*\d{3,5}\s*/i', '', $titel), 2)[0]), 0, 150);
        if (preg_match('/^[^(·]+?,\s*([^(·]+?)\s*(?:\(|·|$)/u', $titel, $am)) $velden['adres'] = mb_substr(clean_text($am[1]), 0, 255);
    }
    if ($soort === 'service' && preg_match('/ref:([0-9a-f\-]{36})/i', (string)($p['description'] ?? ''), $m)) $velden['extern_ref'] = $m[1];

    if ($nieuw) {
        q("INSERT INTO projecten (nummer, soort, omschrijving, fase, afgerond) VALUES (?,?,?,?,?)", [$nummer, $soort, $velden['omschrijving'], $fase, $klaar ? 1 : 0]);
        $pid = (int)db()->lastInsertId();
        $best = q('SELECT * FROM projecten WHERE id = ?', [$pid])->fetch();
    }
    $pid = (int)$best['id'];
    // Alleen lege velden aanvullen: wat in de app al is ingevuld (door een mens of het brein) blijft staan
    foreach ($velden as $kol => $v) {
        if ($v === null || $v === '') continue;
        if ($kol === 'extern_ref' && q('SELECT 1 FROM projecten WHERE extern_ref = ? AND id <> ?', [$v, $pid])->fetch()) continue;
        if ($best[$kol] === null || $best[$kol] === '' || ($kol === 'omschrijving' && $nieuw)) q("UPDATE projecten SET `$kol` = ? WHERE id = ?", [$v, $pid]);
    }
    // Uit Pipedrive overnemen: soort, fase, koppelingen en labels (Pipedrive was leidend tot de overstap)
    q('UPDATE projecten SET soort = ?, fase = ?, pipedrive_id = ?, pipedrive_deal_id = COALESCE(?, pipedrive_deal_id), labels = ? WHERE id = ?',
        [$soort, $fase, (int)$p['id'], !empty($p['deal_ids'][0]) ? (int)$p['deal_ids'][0] : null, mb_substr($labels, 0, 255), $pid]);
    if ($klaar) q('UPDATE projecten SET afgerond = 1, opgelost_op = COALESCE(opgelost_op, ?) WHERE id = ?', [pd_tijd($p['status_change_time'] ?? null), $pid]);

    // Notities naar de tijdlijn van het project; het vastgepinde projectoverzicht wordt de samenvatting (als die nog leeg is)
    $nNot = 0;
    foreach ($notities as $n) {
        $tekst = pd_tekst($n['content'] ?? '');
        if ($tekst === '') continue;
        $van = clean_text($n['user']['name'] ?? '') ?: (PD_GEBRUIKERS[(int)($n['user_id'] ?? 0)] ?? '');
        q('INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst, link, extern_id) VALUES (?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE tekst = VALUES(tekst), datum = VALUES(datum)',
            [$pid, pd_tijd($n['add_time'] ?? null), 'pipedrive', mb_substr($van, 0, 150), 'Notitie uit Pipedrive', $tekst, '', 'pd-notitie:' . (int)$n['id']]);
        $nNot++;
        if (!empty($n['pinned_to_project_flag']) && str_contains($tekst, 'Projectoverzicht'))
            q('UPDATE projecten SET samenvatting = ?, samenvatting_op = ? WHERE id = ? AND (samenvatting IS NULL OR samenvatting = \'\')', [$tekst, pd_tijd($n['update_time'] ?? null), $pid]);
    }

    // Open taken naar de app (zelfde extern_id als het brein gebruikt: pd:<taak-id>). Subtaken worden een afvinklijst in de toelichting.
    $nTaak = 0; $open = []; $sub = [];
    foreach ($taken as $t) {
        if (!empty($t['parent_task_id'])) $sub[(int)$t['parent_task_id']][] = $t; else $open[(int)$t['id']] = $t;
    }
    foreach ($sub as $ouder => $lijst) if (!isset($open[$ouder])) foreach ($lijst as $t) $open[(int)$t['id']] = $t; // wees-subtaken los
    foreach ($open as $id => $t) {
        if (!empty($t['done'])) continue;
        if (q('SELECT 1 FROM taken WHERE extern_id = ?', ['pd:' . $id])->fetch()) continue;
        $toel = pd_tekst($t['description'] ?? '');
        $tf = fase_van_pipedrive_bord((int)($t['stage_id'] ?? 0));
        if ($tf) $toel = trim('Fase in Pipedrive: ' . fase_naam($tf[1], $tf[0]) . "\n" . $toel);
        if (!empty($sub[$id])) {
            $toel .= "\n\nAfvinklijst:";
            foreach ($sub[$id] as $s) $toel .= "\n" . (!empty($s['done']) ? '[x] ' : '[ ] ') . clean_text($s['title']);
        }
        taak_maak(['titel' => clean_text($t['title'] ?? ''), 'toelichting' => trim($toel), 'project_id' => $pid, 'eigenaar_id' => pd_gebruiker($t['assignee_id'] ?? null),
            'deadline' => $t['due_date'] ?? null, 'prioriteit' => !empty($t['milestone']) ? 'hoog' : 'normaal', 'bron' => 'Pipedrive (overstap)', 'extern_id' => 'pd:' . $id], null, 'Overstap Pipedrive');
        $nTaak++;
    }
    return ['pipedrive_id' => (int)$p['id'], 'nummer' => $nummer, 'soort' => $soort, 'fase' => $fase, 'nieuw' => $nieuw, 'notities' => $nNot, 'taken' => $nTaak];
}

// ---------- Pipedrive-API (alleen lezen) ----------
// Sleutel in voorraad-config.php: 'pipedrive_token' => '...' (Pipedrive > Persoonlijke voorkeuren > API).
function pd_api(string $pad, array $query = []): array
{
    global $CFG;
    $token = $CFG['pipedrive_token'] ?? '';
    if ($token === '') throw new RuntimeException('pipedrive_token ontbreekt in voorraad-config.php');
    $url = 'https://' . ($CFG['pipedrive_domein'] ?? 'wattwatt') . '.pipedrive.com/api/' . ltrim($pad, '/') . ($query ? '?' . http_build_query($query) : '');
    $kop = ['x-api-token: ' . $token, 'Accept: application/json'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $kop]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    } else {
        // Server zonder php-curl: gewone PHP-stream
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $kop), 'timeout' => 60, 'ignore_errors' => true]]));
        $code = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0;
    }
    $d = json_decode((string)$body, true);
    if ($code !== 200 || !is_array($d) || empty($d['success'])) throw new RuntimeException("Pipedrive $pad: HTTP $code");
    return $d;
}
/** Alle pagina's van een lijst (cursor of start/limit). */
function pd_lijst(string $pad, array $query = []): array
{
    $uit = []; $query['limit'] = $query['limit'] ?? 500;
    for ($i = 0; $i < 50; $i++) {
        $d = pd_api($pad, $query);
        $uit = array_merge($uit, $d['data'] ?? []);
        $extra = $d['additional_data'] ?? [];
        if (!empty($extra['next_cursor'])) $query['cursor'] = $extra['next_cursor'];
        elseif (!empty($extra['pagination']['more_items_in_collection'])) $query['start'] = $extra['pagination']['next_start'];
        else break;
    }
    return $uit;
}
/** Alles ophalen wat nodig is: open projecten met hun notities en taken. */
function pd_alles_ophalen(): array
{
    $projecten = pd_lijst('v1/projects', ['status' => 'open']);
    $notities = []; $taken = [];
    foreach ($projecten as $p) {
        $notities[$p['id']] = pd_lijst('v1/notes', ['project_id' => $p['id']]);
        $taken[$p['id']] = pd_lijst('v1/tasks', ['project_id' => $p['id']]);
    }
    return ['projecten' => $projecten, 'notities' => $notities, 'taken' => $taken];
}
