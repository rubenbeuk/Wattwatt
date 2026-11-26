<?php
// Verwerkt alles wat gescand of getypt wordt: productcode, EAN, QR-link of projectlabel.
require __DIR__ . '/inc/bootstrap.php';
require_login();

$raw = trim((string)($_GET['q'] ?? ''));
$val = $raw;

// QR met link naar deze app: haal q= uit de URL
if (preg_match('#^https?://#i', $val)) {
    $parts = parse_url($val);
    parse_str($parts['query'] ?? '', $qs);
    $val = trim((string)($qs['q'] ?? ''));
}

// Projectlabel: "PROJECT:2025-014"
if (preg_match('/^PROJECT:(.+)$/i', $val, $m)) {
    $nr = project_nummer($m[1]);
    project_id($nr);
    $_SESSION['project'] = $nr;
    flash('Project <b>' . e($nr) . '</b> staat nu actief. Scan je materiaal.');
    redirect('index.php');
}

if ($val === '') redirect('index.php');

// De WW-code op het label hoort altijd bij hetzelfde product, ook als het inactief is
$hits = q('SELECT id FROM producten WHERE code = ?', [strtoupper(preg_replace('/\s+/', '', $val))])->fetchAll();
if (!$hits) {
    $ean = clean_ean($val);
    if ($ean !== '' && $ean === preg_replace('/\s/', '', $val)) {
        $hits = q("SELECT id FROM producten WHERE actief = 1 AND (ean = ? OR TRIM(LEADING '0' FROM ean) = TRIM(LEADING '0' FROM ?))", [$ean, $ean])->fetchAll();
    }
}

if (count($hits) === 1) redirect('product.php?id=' . (int)$hits[0]['id']);
redirect('index.php?q=' . rawurlencode($val));
