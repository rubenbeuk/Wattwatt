<?php
// Boekt een voorraadmutatie: pakken, terugbrengen, ontvangen, tellen, of ongedaan maken.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/materiaal.php';
$u = require_login();
csrf_check();

$pdo = db();

// ---- Ongedaan maken (eigen boeking, binnen 30 minuten; beheerder altijd) ----
if (isset($_POST['ongedaan'])) {
    $mid = (int)$_POST['ongedaan'];
    $pdo->beginTransaction();
    $m = q('SELECT * FROM mutaties WHERE id = ? FOR UPDATE', [$mid])->fetch();
    $magHet = $m && !$m['ongedaan_van'] && in_array($m['type'], ['uit', 'retour', 'ontvangst', 'telling'], true)
        && (is_admin() || ((int)$m['gebruiker_id'] === $u['id'] && strtotime($m['aangemaakt'] . ' UTC') > time() - 1800));
    $alGedaan = $m ? q('SELECT id FROM mutaties WHERE ongedaan_van = ?', [$mid])->fetch() : true;
    if (!$magHet || $alGedaan) {
        $pdo->rollBack();
        flash('Deze boeking kan niet (meer) ongedaan worden gemaakt.', 'err');
        redirect('index.php');
    }
    $p = q('SELECT * FROM producten WHERE id = ? FOR UPDATE', [$m['product_id']])->fetch();
    $nieuw = (float)$p['voorraad'] - (float)$m['aantal'];
    q('UPDATE producten SET voorraad = ? WHERE id = ?', [$nieuw, $p['id']]);
    q('INSERT INTO mutaties (product_id, gebruiker_id, project_id, type, aantal, voorraad_na, prijs, opmerking, ongedaan_van) VALUES (?,?,?,?,?,?,?,?,?)',
        [$p['id'], $u['id'], $m['project_id'], 'correctie', -(float)$m['aantal'], $nieuw, $p['prijs'], 'Ongedaan: ' . TYPES[$m['type']], $mid]);
    $pdo->commit();
    // Pakken ongedaan: reservering voor dit project weer aanvullen
    if ($m['type'] === 'uit' && $m['project_id']) {
        foreach (q('SELECT id FROM materiaal WHERE project_id = ? AND product_id = ?', [$m['project_id'], $p['id']])->fetchAll() as $mr) materiaal_reserveer((int)$mr['id']);
    }
    flash('Boeking ongedaan gemaakt. ' . e($p['naam']) . ' staat weer op ' . fmt_n($nieuw) . '.');
    redirect('product.php?id=' . (int)$p['id']);
}

// ---- Gewone boeking ----
$type = (string)($_POST['type'] ?? '');
if (!in_array($type, ['uit', 'retour', 'ontvangst', 'telling'], true)) { http_response_code(400); exit('Onbekend type.'); }
if ($type === 'telling' && !is_admin()) { http_response_code(403); exit('Alleen beheerders kunnen tellen.'); }

$aantal = num($_POST['aantal'] ?? null);
if ($aantal === null || $aantal < 0 || ($aantal == 0 && $type !== 'telling')) {
    flash('Vul een geldig aantal in.', 'err');
    redirect('product.php?id=' . (int)($_POST['product_id'] ?? 0));
}
$projectNr = project_nummer($_POST['project'] ?? '');
if ($type === 'uit' && $projectNr === '') {
    flash('Vul het projectnummer in, zodat we weten waar het heen gaat.', 'err');
    redirect('product.php?id=' . (int)($_POST['product_id'] ?? 0));
}
$opm = mb_substr(clean_text($_POST['opmerking'] ?? ''), 0, 255);

$pdo->beginTransaction();
$p = q('SELECT * FROM producten WHERE id = ? FOR UPDATE', [(int)($_POST['product_id'] ?? 0)])->fetch();
if (!$p) { $pdo->rollBack(); flash('Product niet gevonden.', 'err'); redirect('index.php'); }

$oud = (float)$p['voorraad'];
switch ($type) {
    case 'uit':       $delta = -$aantal; break;
    case 'telling':   $delta = $aantal - $oud; break;
    default:          $delta = $aantal; // retour, ontvangst
}
$nieuw = $oud + $delta;
$pid = ($type === 'telling') ? null : project_id($projectNr);

q('UPDATE producten SET voorraad = ? WHERE id = ?', [$nieuw, $p['id']]);
q('INSERT INTO mutaties (product_id, gebruiker_id, project_id, type, aantal, voorraad_na, prijs, opmerking) VALUES (?,?,?,?,?,?,?,?)',
    [$p['id'], $u['id'], $pid, $type, $delta, $nieuw, $p['prijs'], $opm]);
$mid = (int)$pdo->lastInsertId();
if ($type === 'uit' && $pid) materiaal_na_pakken($pid, (int)$p['id'], $aantal);
$pdo->commit();

if ($type === 'uit' && $projectNr !== '') $_SESSION['project'] = $projectNr;

$tekst = [
    'uit' => fmt_n($aantal) . ' × ' . e($p['naam']) . ' gepakt voor project <b>' . e($projectNr) . '</b>.',
    'retour' => fmt_n($aantal) . ' × ' . e($p['naam']) . ' teruggebracht.',
    'ontvangst' => fmt_n($aantal) . ' × ' . e($p['naam']) . ' ontvangen.',
    'telling' => e($p['naam']) . ' geteld: ' . fmt_n($nieuw) . ' (was ' . fmt_n($oud) . ').',
][$type];
$tekst .= ' Nog ' . fmt_n($nieuw) . ' op voorraad.';
if ($nieuw < 0) $tekst .= ' <b>Let op: voorraad is negatief, graag even tellen.</b>';
$tekst .= ' <form method="post" action="boek.php" class="inline">' . csrf_field()
    . '<button name="ongedaan" value="' . $mid . '" class="linkbtn">Ongedaan maken</button></form>';
flash($tekst, $nieuw < 0 ? 'warn' : 'ok');

// Na pakken: terug naar scannen voor het volgende item
redirect($type === 'uit' ? 'index.php' : 'product.php?id=' . (int)$p['id']);
