<?php
// Toont één foto. Alleen voor wie is ingelogd; de bestanden staan buiten de webmap.
require __DIR__ . '/inc/bootstrap.php';
require_login();
$f = q('SELECT * FROM fotos WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
$pad = $f ? foto_map() . '/' . (int)$f['project_id'] . '/' . basename($f['bestand']) : '';
if (!$f || !is_file($pad)) { http_response_code(404); exit('Foto niet gevonden.'); }
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($pad, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($pad));
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', 'foto-' . $f['id'] . '.' . pathinfo($pad, PATHINFO_EXTENSION)) . '"');
readfile($pad);
