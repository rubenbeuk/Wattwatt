<?php
// Geeft het aantal ongelezen seintjes en de nieuwste teksten; de pagina vraagt dit elke minuut op.
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$u = user();
if (!$u) { echo json_encode(['n' => 0, 'nieuw' => []]); exit; }
$rijen = seintjes_open($u['id']);
echo json_encode(['n' => count($rijen), 'nieuw' => array_map(fn($r) => ['id' => (int)$r['id'], 'tekst' => $r['tekst']], array_slice($rijen, 0, 5))], JSON_UNESCAPED_UNICODE);
