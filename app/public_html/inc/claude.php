<?php
// Logboek van wat Claude (het brein) in de app veranderde, met Ongedaan maken (28-11-2026).
// cli.php legt per schrijfopdracht vast hoe de rij eruitzag vóór en na. Ongedaan zet de rij terug,
// maar alleen als niemand hem daarna nog veranderde (anders zou je andermans werk wissen).

/** Kolom waarop een tabel zijn rijen vindt. */
function cl_sleutel(string $tabel): string { return $tabel === 'opleveringen' ? 'project_id' : 'id'; }
const CL_TABELLEN = ['taken', 'projecten', 'project_berichten', 'project_mijlpalen', 'afspraken', 'bespreekpunten', 'voorstellen', 'dagopdrachten', 'notities', 'opleveringen', 'taak_voorstellen', 'locaties', 'installaties', 'werkbonnen', 'facturen', 'contracten', 'klantberichten'];
/** Kolommen die vanzelf veranderen en niet meetellen bij "is het daarna gewijzigd". */
const CL_NEGEER = ['bijgewerkt', 'aangemaakt'];

function cl_rij(string $tabel, int $id): ?array
{
    if (!in_array($tabel, CL_TABELLEN, true)) throw new InvalidArgumentException('tabel niet toegestaan');
    return q("SELECT * FROM `$tabel` WHERE `" . cl_sleutel($tabel) . "` = ?", [$id])->fetch() ?: null;
}
function cl_gelijk(?array $a, ?array $b): bool
{
    if ($a === null || $b === null) return $a === $b;
    foreach (CL_NEGEER as $k) unset($a[$k], $b[$k]);
    return array_map('strval', $a) == array_map('strval', $b);
}
/** Wie draait er nu (staat in het slot): "mac", "cloud-licht" of leeg. */
function cl_run(): string
{
    static $run = null;
    if ($run === null) {
        $r = q("SELECT waarde FROM brein_stand WHERE sleutel = 'slot'")->fetch();
        $run = $r ? (string)(json_decode($r['waarde'], true)['wie'] ?? '') : '';
    }
    return $run;
}
/** Korte omschrijving voor het scherm "Vandaag door Claude". */
function cl_wat(string $opdracht, string $tabel, ?array $voor, ?array $na): string
{
    $r = $na ?? $voor ?? [];
    $naam = match ($tabel) {
        'taken' => $r['titel'] ?? '', 'projecten' => trim(($r['nummer'] ?? '') . ' ' . ($r['omschrijving'] ?? '')),
        'project_berichten' => ($r['onderwerp'] ?? '') ?: mb_substr((string)($r['tekst'] ?? ''), 0, 80),
        'project_mijlpalen' => ($r['datum'] ? datum_kort($r['datum']) . ' ' : '') . ($r['titel'] ?? ''),
        'afspraken' => ($r['titel'] ?? '') . ' (' . substr((string)($r['start'] ?? ''), 0, 16) . ')',
        'bespreekpunten', 'voorstellen' => $r['titel'] ?? '', 'dagopdrachten' => 'instructie ' . ($r['datum'] ?? ''),
        'notities' => mb_substr((string)($r['tekst'] ?? ''), 0, 80), 'opleveringen' => 'opleverdossier',
        'taak_voorstellen' => ($r['actie'] ?? '') . ': ' . ($r['reden'] ?? ''),
        'locaties' => trim(($r['naam'] ?? '') . ' ' . ($r['adres'] ?? '')), 'installaties' => trim(($r['merk'] ?? '') . ' ' . ($r['type'] ?? '') . ' ' . ($r['serienummer'] ?? '')),
        'werkbonnen' => 'werkbon ' . ($r['datum'] ?? ''), 'facturen' => $r['omschrijving'] ?? '', 'contracten' => $r['omschrijving'] ?? '', 'klantberichten' => $r['onderwerp'] ?? '', default => '',
    };
    $soort = ['taken' => 'Taak', 'projecten' => ($r['soort'] ?? '') === 'service' ? 'Ticket' : 'Project', 'project_berichten' => 'Tijdlijn', 'project_mijlpalen' => 'Mijlpaal',
              'afspraken' => 'Afspraak', 'bespreekpunten' => 'Bespreekpunt', 'voorstellen' => 'Planningsvoorstel', 'dagopdrachten' => 'Instructie', 'notities' => 'Notitie',
              'opleveringen' => 'Oplevering', 'taak_voorstellen' => 'Opschoonvoorstel',
              'locaties' => 'Locatie', 'installaties' => 'Installatie', 'werkbonnen' => 'Werkbon', 'facturen' => 'Factuurvoorstel', 'contracten' => 'Contract', 'klantberichten' => 'Klantbericht'][$tabel] ?? $tabel;
    if ($voor === null) $wat = 'nieuw';
    elseif ($na === null) $wat = 'verwijderd';
    else {
        $wat = [];
        foreach ($na as $k => $v) if (!in_array($k, CL_NEGEER, true) && (string)($voor[$k] ?? '') !== (string)$v) $wat[] = $k;
        $wat = match (true) {
            $tabel === 'taken' && ($na['status'] ?? '') === 'klaar' && ($voor['status'] ?? '') !== 'klaar' => 'afgevinkt',
            $tabel === 'taken' && ($na['status'] ?? '') !== ($voor['status'] ?? '') => 'status ' . $na['status'],
            $tabel === 'projecten' && in_array('fase', $wat, true) => 'fase ' . ($voor['fase'] ?? '') . ' → ' . $na['fase'],
            $tabel === 'notities' && ($na['status'] ?? '') === 'verwerkt' => 'verwerkt',
            default => 'bijgewerkt (' . implode(', ', array_slice($wat, 0, 4)) . ')',
        };
    }
    return mb_substr("$soort $wat: $naam", 0, 500);
}
/** Vastleggen. Doet niets als de rij niet veranderde. */
function cl_vast(string $opdracht, string $tabel, int $id, ?array $voor): void
{
    $na = cl_rij($tabel, $id);
    if (cl_gelijk($voor, $na)) return;
    $r = $na ?? $voor;
    $pid = $tabel === 'projecten' ? $id : (isset($r['project_id']) ? (int)$r['project_id'] : null);
    q('INSERT INTO claude_log (run, opdracht, tabel, rij_id, project_id, wat, voor, na) VALUES (?,?,?,?,?,?,?,?)',
        [cl_run(), $opdracht, $tabel, $id, $pid ?: null, cl_wat($opdracht, $tabel, $voor, $na),
         $voor === null ? null : json_encode($voor, JSON_UNESCAPED_UNICODE), $na === null ? null : json_encode($na, JSON_UNESCAPED_UNICODE)]);
}

/** Link naar waar de rij in de app staat. */
function cl_link(array $l): string
{
    $r = json_decode((string)($l['na'] ?? $l['voor'] ?? '{}'), true) ?: [];
    $nr = $l['project_id'] ? (q('SELECT nummer FROM projecten WHERE id = ?', [$l['project_id']])->fetchColumn() ?: '') : '';
    return match ($l['tabel']) {
        'taken' => 'taak.php?id=' . (int)$l['rij_id'],
        'afspraken' => 'agenda.php',
        'bespreekpunten' => 'overleg.php',
        'voorstellen' => 'planning.php#voorstellen',
        'taak_voorstellen' => 'opschonen.php',
        'dagopdrachten' => $nr ? 'opdracht.php?datum=' . rawurlencode((string)($r['datum'] ?? '')) . '&nr=' . rawurlencode($nr) : '',
        default => $nr ? 'project.php?nr=' . rawurlencode($nr) : '',
    };
}

/**
 * Een vastgelegde actie terugdraaien. Geeft een melding terug; gooit een fout als het niet veilig kan.
 * - nieuw aangemaakt: de rij gaat weg (bij een project of ticket alleen als er verder niets aan hangt)
 * - gewijzigd: de rij krijgt weer de oude waarden
 * - verwijderd: de rij komt terug
 */
function cl_ongedaan(int $logId, int $door): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $l = q('SELECT * FROM claude_log WHERE id = ? FOR UPDATE', [$logId])->fetch();
        if (!$l) throw new RuntimeException('Niet gevonden.');
        if ($l['ongedaan_op']) throw new RuntimeException('Dit is al ongedaan gemaakt.');
        $tabel = $l['tabel']; $id = (int)$l['rij_id']; $sl = cl_sleutel($tabel);
        $voor = $l['voor'] !== null ? json_decode($l['voor'], true) : null;
        $na = $l['na'] !== null ? json_decode($l['na'], true) : null;
        $nu = cl_rij($tabel, $id);
        if (!cl_gelijk($nu, $na)) {
            $later = q('SELECT COUNT(*) FROM claude_log WHERE tabel = ? AND rij_id = ? AND id > ? AND ongedaan_op IS NULL', [$tabel, $id, $logId])->fetchColumn();
            throw new RuntimeException($later ? 'Claude veranderde dit daarna nog een keer. Maak eerst die latere actie ongedaan.' : 'Dit is daarna door iemand veranderd. Pas het met de hand aan.');
        }
        if ($voor === null) {
            if ($tabel === 'projecten') {
                // Alleen weg als er niets meer aan hangt behalve het eigen bericht "Ticket aangemaakt"
                q("DELETE FROM project_berichten WHERE project_id = ? AND bron = 'app' AND onderwerp = 'Ticket aangemaakt'", [$id]);
                foreach (q("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'project_id' AND TABLE_NAME NOT IN ('claude_log')")->fetchAll(PDO::FETCH_COLUMN) as $t)
                    if (q("SELECT 1 FROM `$t` WHERE project_id = ? LIMIT 1", [$id])->fetch()) throw new RuntimeException('Aan dit ' . ($na['soort'] === 'service' ? 'ticket' : 'project') . ' hangt nog iets (' . $t . '). Maak dat eerst ongedaan.');
            }
            q("DELETE FROM `$tabel` WHERE `$sl` = ?", [$id]);
            $melding = 'Weggehaald.';
        } else {
            $kol = array_keys($voor);
            if ($nu === null) {
                q("INSERT INTO `$tabel` (`" . implode('`, `', $kol) . '`) VALUES (' . rtrim(str_repeat('?,', count($kol)), ',') . ')', array_values($voor));
                $melding = 'Teruggezet.';
            } else {
                $set = array_values(array_filter($kol, fn($k) => $k !== $sl && !in_array($k, CL_NEGEER, true)));
                q("UPDATE `$tabel` SET " . implode(', ', array_map(fn($k) => "`$k` = ?", $set)) . " WHERE `$sl` = ?", [...array_map(fn($k) => $voor[$k], $set), $id]);
                $melding = 'Oude stand teruggezet.';
            }
            if ($tabel === 'taken') {
                $naam = q('SELECT naam FROM gebruikers WHERE id = ?', [$door])->fetchColumn();
                taak_log($id, 'Actie van Claude ongedaan gemaakt: ' . $l['wat'], $door, (string)$naam);
            }
        }
        q('UPDATE claude_log SET ongedaan_op = UTC_TIMESTAMP(), ongedaan_door = ? WHERE id = ?', [$door, $logId]);
        $pdo->commit();
        return $melding;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
