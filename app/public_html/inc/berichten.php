<?php
// Klantberichten (ronde 3, 28-11-2026): het brein of kantoor zet een concept klaar ("afspraak staat", "morgen komen we", "storing opgelost").
// Kantoor leest het na en verstuurt het zelf vanuit de eigen mail (mailto-knop); daarna staat het als verstuurd in de tijdlijn.
// De app en het brein versturen zelf nooit iets naar een klant.

const KLANTBERICHT_SOORTEN = ['ingepland' => 'Afspraak ingepland', 'dag_ervoor' => 'Herinnering dag ervoor', 'opgelost' => 'Storing opgelost', 'oplevering' => 'Oplevering', 'vrij' => 'Bericht'];

/** E-mailadres van de klant voor dit project of ticket. */
function klant_email(array $pr): string
{
    if (($pr['email'] ?? '') !== '') return $pr['email'];
    if (!empty($pr['klant_id'])) return (string)(q('SELECT email FROM klanten WHERE id = ?', [$pr['klant_id']])->fetchColumn() ?: '');
    return '';
}

/** Standaardtekst als startpunt; kantoor of het brein past hem aan. */
function klantbericht_sjabloon(array $pr, string $soort, ?string $datum = null): array
{
    $contact = trim(explode(',', (string)($pr['contact'] ?? ''))[0]);
    $aanhef = 'Beste ' . ($contact !== '' ? $contact : ($pr['klant'] ?: 'klant')) . ',';
    $wat = $pr['soort'] === 'service' ? 'uw melding (' . $pr['nummer'] . ')' : 'uw project ' . project_naam($pr);
    $dag = $datum ? datum_lang($datum) : 'de afgesproken dag';
    $groet = "\n\nMet vriendelijke groet,\nTeam Watt Watt";
    return match ($soort) {
        'ingepland' => ['Afspraak ' . $dag . ' – ' . $wat, "$aanhef\n\nWe komen op $dag langs voor $wat. Wilt u ervoor zorgen dat we bij de installatie en de meterkast kunnen? Past het onverhoopt niet, laat het ons dan weten.$groet"],
        'dag_ervoor' => ['Morgen komen we langs – ' . $wat, "$aanhef\n\nEen korte herinnering: morgen ($dag) komen we langs voor $wat. Zorgt u dat we bij de installatie en de meterkast kunnen?$groet"],
        'opgelost' => ['Opgelost – ' . $wat, "$aanhef\n\n" . ucfirst($wat) . " is afgehandeld. Merkt u toch nog iets, reageer dan gerust op deze mail of bel ons.$groet"],
        'oplevering' => ['Oplevering – ' . $wat, "$aanhef\n\nDe installatie is opgeleverd. In de bijlage vindt u de werkbon. Heeft u nog vragen over het gebruik of de monitoring, laat het ons gerust weten.$groet"],
        default => ['Watt Watt – ' . $wat, "$aanhef\n\n$groet"],
    };
}

/**
 * Concept opslaan. Met een sleutel (bijv. "opgelost:123" of "dag_ervoor:123:2026-12-01") komt er per gebeurtenis maar één;
 * een concept met dezelfde sleutel wordt bijgewerkt, een verstuurd bericht blijft staan.
 */
function klantbericht_concept(int $pid, string $soort, string $onderwerp, string $tekst, string $wie, ?string $sleutel = null, ?string $aan = null): array
{
    $pr = q('SELECT * FROM projecten WHERE id = ?', [$pid])->fetch();
    if (!$pr) return ['fout' => 'project niet gevonden'];
    $soort = isset(KLANTBERICHT_SOORTEN[$soort]) ? $soort : 'vrij';
    $onderwerp = mb_substr(clean_text($onderwerp), 0, 255);
    $tekst = trim($tekst);
    if ($onderwerp === '' || $tekst === '') return ['fout' => 'onderwerp en tekst zijn nodig'];
    $aan = mb_substr(mb_strtolower(clean_text($aan ?? '')), 0, 150) ?: klant_email($pr);
    $sleutel = $sleutel !== null && clean_text($sleutel) !== '' ? mb_substr(clean_text($sleutel), 0, 60) : null;
    if ($sleutel && ($b = q('SELECT * FROM klantberichten WHERE sleutel = ?', [$sleutel])->fetch())) {
        if ($b['status'] !== 'concept') return ['id' => (int)$b['id'], 'nieuw' => false, 'al_verstuurd' => $b['status'] === 'verstuurd'];
        q('UPDATE klantberichten SET aan = ?, onderwerp = ?, tekst = ?, concept_door = ? WHERE id = ?', [$aan, $onderwerp, $tekst, mb_substr($wie, 0, 100), $b['id']]);
        return ['id' => (int)$b['id'], 'nieuw' => false];
    }
    q('INSERT INTO klantberichten (project_id, soort, sleutel, aan, onderwerp, tekst, concept_door) VALUES (?,?,?,?,?,?,?)', [$pid, $soort, $sleutel, $aan, $onderwerp, $tekst, mb_substr($wie, 0, 100)]);
    return ['id' => (int)db()->lastInsertId(), 'nieuw' => true];
}

/** Na het versturen vanuit de eigen mail: markeren en in de tijdlijn zetten. */
function klantbericht_verstuurd(int $id, string $wie): bool
{
    $b = q("SELECT * FROM klantberichten WHERE id = ? AND status = 'concept'", [$id])->fetch();
    if (!$b) return false;
    q("UPDATE klantberichten SET status = 'verstuurd', verstuurd_door = ?, verstuurd_op = UTC_TIMESTAMP() WHERE id = ?", [mb_substr($wie, 0, 100), $id]);
    q("INSERT INTO project_berichten (project_id, datum, bron, van, onderwerp, tekst) VALUES (?, ?, 'app', ?, ?, ?)",
        [$b['project_id'], date('Y-m-d H:i:s'), mb_substr($wie, 0, 150), mb_substr('Aan klant: ' . $b['onderwerp'], 0, 255), mb_substr($b['tekst'], 0, 2000)]);
    return true;
}

function klantbericht_mailto(array $b): string
{
    return 'mailto:' . rawurlencode($b['aan']) . '?subject=' . rawurlencode($b['onderwerp']) . '&body=' . rawurlencode(str_replace("\n", "\r\n", (string)$b['tekst']));
}

function klantberichten_open(?int $pid = null): array
{
    return q("SELECT b.*, p.nummer, p.klant, p.omschrijving FROM klantberichten b JOIN projecten p ON p.id = b.project_id WHERE b.status = 'concept'" . ($pid ? ' AND b.project_id = ' . (int)$pid : '') . ' ORDER BY b.id DESC LIMIT 50')->fetchAll();
}
