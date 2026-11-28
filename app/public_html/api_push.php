<?php
// Pushmeldingen aan- en uitzetten, en het nieuwste seintje voor de service worker (ronde 3).
// GET  ?sleutel          -> publieke VAPID-sleutel
// GET  ?laatste          -> nieuwste ongelezen seintje van de ingelogde gebruiker (de melding zelf)
// POST actie=aan|uit|test (met csrf) -> abonnement opslaan, weghalen of een testmelding sturen
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$u = user();
if (!$u) { http_response_code(401); echo json_encode(['fout' => 'niet ingelogd']); exit; }

if (isset($_GET['sleutel'])) { $k = vapid_sleutels(); echo json_encode(['sleutel' => $k['pub'] ?? null]); exit; }
if (isset($_GET['laatste'])) {
    $r = q('SELECT id, tekst, link FROM seintjes WHERE gebruiker_id = ? AND gezien_op IS NULL ORDER BY id DESC LIMIT 1', [$u['id']])->fetch();
    $n = (int)q('SELECT COUNT(*) FROM seintjes WHERE gebruiker_id = ? AND gezien_op IS NULL', [$u['id']])->fetchColumn();
    echo json_encode($r ? ['titel' => $n > 1 ? "Watt Watt ($n nieuw)" : 'Watt Watt', 'tekst' => $r['tekst'], 'link' => $r['link'] ?: 'dag.php#seintjes', 'tag' => 'seintje-' . $r['id']]
                        : ['titel' => 'Watt Watt', 'tekst' => 'Er is iets nieuws in de app.', 'link' => 'dag.php#seintjes', 'tag' => 'seintje'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_check();
$actie = $_POST['actie'] ?? '';
$ep = (string)($_POST['endpoint'] ?? '');
if ($actie === 'aan') {
    if (!preg_match('#^https://[^\s]+$#', $ep) || strlen($ep) > 1000) { http_response_code(400); echo json_encode(['fout' => 'ongeldig abonnement']); exit; }
    q('INSERT INTO push_abonnementen (gebruiker_id, endpoint, endpoint_hash, apparaat) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE gebruiker_id = VALUES(gebruiker_id), fouten = 0, apparaat = VALUES(apparaat)',
        [$u['id'], $ep, hash('sha256', $ep), mb_substr(clean_text($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 150)]);
    echo json_encode(['ok' => true]);
} elseif ($actie === 'uit') {
    q('DELETE FROM push_abonnementen WHERE endpoint_hash = ? AND gebruiker_id = ?', [hash('sha256', $ep), $u['id']]);
    echo json_encode(['ok' => true]);
} elseif ($actie === 'test') {
    seintje((int)$u['id'], 'Testmelding: pushmeldingen werken op dit apparaat.', 'account.php');
    echo json_encode(['ok' => true]);
} else { http_response_code(400); echo json_encode(['fout' => 'onbekende actie']); }
