<?php
declare(strict_types=1);

// ---------- Config ----------
// Config staat bij voorkeur BUITEN de webroot: ../voorraad-config.php (naast public_html)
$cfgPaths = [dirname(__DIR__, 2) . '/voorraad-config.php', dirname(__DIR__) . '/config.php'];
$CFG = null;
foreach ($cfgPaths as $p) {
    if (is_file($p)) { $CFG = require $p; break; }
}
if (!is_array($CFG)) {
    http_response_code(500);
    exit('Config ontbreekt. Zet voorraad-config.php naast public_html (zie config.example.php).');
}
define('APP_NAME', $CFG['app_name'] ?? 'Watt Watt Voorraad');
define('APP_URL', rtrim($CFG['app_url'] ?? '', '/'));
date_default_timezone_set('Europe/Amsterdam');

define('IS_CLI', PHP_SAPI === 'cli');

// ---------- Sessie (30 dagen ingelogd blijven) ----------
if (!IS_CLI) {
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['HTTP_X_WW_PROTO'] ?? '') === 'https');
$sessDir = dirname(__DIR__, 2) . '/voorraad-sessions';
if (!is_dir($sessDir)) { @mkdir($sessDir, 0700, true); }
if (is_dir($sessDir) && is_writable($sessDir)) { session_save_path($sessDir); }
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
session_name('wwvoorraad');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
}

// ---------- Database ----------
function db(): PDO
{
    static $pdo = null;
    global $CFG;
    if ($pdo === null) {
        $d = $CFG['db'];
        // Alleen op de opdrachtregel: andere database kiezen (testkopie)
        if (PHP_SAPI === 'cli' && getenv('VOORRAAD_DB')) $d['name'] = getenv('VOORRAAD_DB');
        $pdo = new PDO(
            "mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4",
            $d['user'],
            $d['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// ---------- Helpers ----------
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function redirect(string $url): void { header('Location: ' . $url); exit; }

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ongeldig verzoek (sessie verlopen?). Ga terug en probeer opnieuw.');
    }
}

function user(): ?array { return $_SESSION['user'] ?? null; }
function is_admin(): bool { return (user()['rol'] ?? '') === 'admin'; }
/** Rollen (28-11-2026): admin = kantoor, monteur, verkoop (klanten en projecten, geen inkoopprijzen), onderaannemer (alleen eigen dag). */
const ROLLEN = ['admin' => 'Kantoor', 'monteur' => 'Monteur', 'verkoop' => 'Verkoop', 'onderaannemer' => 'Onderaannemer'];
function rol(): string { return (string)(user()['rol'] ?? ''); }
function is_verkoop(): bool { return rol() === 'verkoop'; }
function is_onderaannemer(): bool { return rol() === 'onderaannemer'; }
/** Onderaannemer: alleen deze pagina's. */
const ONDERAANNEMER_PAGINAS = ['dag.php', 'uren.php', 'fotos.php', 'foto.php', 'project.php', 'oplevering.php', 'account.php', 'logout.php', 'seintjes.php', 'taak.php', 'tweestaps.php'];
/** Mag een onderaannemer dit project zien? Alleen als hij er van 14 dagen terug tot 30 dagen vooruit op staat ingepland. */
function onderaannemer_mag_project(int $pid, int $gid): bool
{
    return (bool)q('SELECT 1 FROM planning WHERE project_id = ? AND gebruiker_id = ? AND datum BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() + INTERVAL 30 DAY LIMIT 1', [$pid, $gid])->fetch();
}
function require_rol(array $rollen): array
{
    $u = require_login();
    if (!in_array($u['rol'], $rollen, true)) { http_response_code(403); exit('Deze pagina is niet voor jouw rol.'); }
    return $u;
}
/** Tweede inlogstap (code-app op de telefoon) verplicht voor kantoor, tenzij 'tweestaps' => false in de config. */
function tweestaps_verplicht(array $u): bool { global $CFG; return $u['rol'] === 'admin' && ($CFG['tweestaps'] ?? true) !== false; }
/** Ruben en Berend: wat zij invullen of goedkeuren is direct goed. */
function is_beslisser(): bool { return (int)(user()['beslisser'] ?? 0) === 1; }

function require_login(): array
{
    $u = user();
    if (!$u) {
        $_SESSION['na_login'] = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('login.php');
    }
    // Gedeactiveerde gebruiker direct eruit
    $row = q('SELECT id, naam, gebruikersnaam, rol, actief, beslisser, totp_geheim FROM gebruikers WHERE id = ?', [$u['id']])->fetch();
    if (!$row || !$row['actief']) { session_destroy(); redirect('login.php'); }
    $_SESSION['user'] = ['id' => (int)$row['id'], 'naam' => $row['naam'], 'gebruikersnaam' => $row['gebruikersnaam'], 'rol' => $row['rol'], 'beslisser' => (int)$row['beslisser']];
    $pagina = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // Kantoor zonder ingestelde code-app: eerst instellen (behalve uitloggen)
    if (tweestaps_verplicht($_SESSION['user']) && !$row['totp_geheim'] && !in_array($pagina, ['tweestaps.php', 'logout.php'], true)) redirect('tweestaps.php');
    if ($row['rol'] === 'onderaannemer') {
        if (!in_array($pagina, ONDERAANNEMER_PAGINAS, true)) redirect('dag.php');
        // Projectpagina's alleen voor projecten waar hij is ingepland
        if (in_array($pagina, ['project.php', 'fotos.php', 'oplevering.php'], true)) {
            $pid = q('SELECT id FROM projecten WHERE nummer = ?', [project_nummer($_GET['nr'] ?? $_POST['nr'] ?? '')])->fetchColumn();
            if (!$pid || !onderaannemer_mag_project((int)$pid, (int)$row['id'])) { http_response_code(403); exit('Je ziet alleen projecten waar je bent ingepland.'); }
        }
        if ($pagina === 'foto.php') {
            $pid = q('SELECT project_id FROM fotos WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetchColumn();
            if (!$pid || !onderaannemer_mag_project((int)$pid, (int)$row['id'])) { http_response_code(403); exit('Niet beschikbaar.'); }
        }
    }
    return $_SESSION['user'];
}
function require_admin(): array
{
    $u = require_login();
    if ($u['rol'] !== 'admin') { http_response_code(403); exit('Alleen voor kantoor.'); }
    return $u;
}

/** Getal uit invoer: accepteert "2,5", "€ 9.68", "1.234,50" */
function num($v): ?float
{
    if ($v === null) return null;
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = trim(str_replace(["\u{a0}", '€', ' '], '', (string)$v));
    if ($s === '') return null;
    if (str_contains($s, ',') && str_contains($s, '.')) { $s = str_replace('.', '', $s); }
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : null;
}

function fmt_n($n): string
{
    $n = (float)$n;
    return (floor($n) == $n) ? number_format($n, 0, ',', '.') : rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}
function fmt_eur($n): string { return '€ ' . number_format((float)$n, 2, ',', '.'); }
function fmt_dt(string $utc): string
{
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Europe/Amsterdam'));
    return $d->format('d-m-Y H:i');
}

function clean_text($s): string
{
    return trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', (string)$s)));
}
function clean_ean($s): string { return preg_replace('/\D/', '', (string)$s); }

/** "289", "prj289", "PRJ 289" -> "PRJ-289". Andere nummers blijven zoals ze zijn. */
function project_nummer(?string $nummer): string
{
    $nummer = clean_text($nummer ?? '');
    if (preg_match('/^(?:prj)?[\s\-]*(\d{3,5})$/i', $nummer, $m)) return 'PRJ-' . $m[1];
    return $nummer;
}

/** Project zoeken of aanmaken op nummer. */
function project_id(?string $nummer): ?int
{
    $nummer = project_nummer($nummer);
    if ($nummer === '') return null;
    $row = q('SELECT id FROM projecten WHERE nummer = ?', [$nummer])->fetch();
    if ($row) return (int)$row['id'];
    q('INSERT INTO projecten (nummer, aangemaakt_door) VALUES (?, ?)', [$nummer, IS_CLI ? null : (user()['id'] ?? null)]);
    return (int)db()->lastInsertId();
}

function lookup_url(string $q): string { return APP_URL . '/lookup.php?q=' . rawurlencode($q); }

const TYPES = [
    'uit' => 'Gepakt',
    'retour' => 'Teruggebracht',
    'ontvangst' => 'Ontvangen',
    'telling' => 'Telling',
    'import' => 'Import',
    'correctie' => 'Ongedaan',
];

require __DIR__ . '/ops.php';
require __DIR__ . '/claude.php';
require __DIR__ . '/totp.php';
require __DIR__ . '/layout.php';
