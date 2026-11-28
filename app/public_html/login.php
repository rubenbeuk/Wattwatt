<?php
require __DIR__ . '/inc/bootstrap.php';

if (user()) redirect('dag.php');

$fout = '';
// Via de Cloudflare Worker (nginx laat alleen verzoeken met het geheim door) staat het echte IP in X-Client-IP
$ip = ($_SERVER['HTTP_X_CLIENT_IP'] ?? '') ?: ($_SERVER['REMOTE_ADDR'] ?? '');

/** Inloggen afronden: sessie zetten en door naar waar je heen wilde. */
function login_klaar(array $row, string $ip): void
{
    session_regenerate_id(true);
    unset($_SESSION['tweestaps_wacht']);
    $_SESSION['user'] = ['id' => (int)$row['id'], 'naam' => $row['naam'], 'gebruikersnaam' => $row['gebruikersnaam'], 'rol' => $row['rol']];
    q('UPDATE gebruikers SET laatst_ingelogd = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
    q('DELETE FROM login_pogingen WHERE ip = ?', [$ip]);
    $naar = $_SESSION['na_login'] ?? 'dag.php';
    unset($_SESSION['na_login']);
    // Alleen lokale paden toestaan
    if (!preg_match('#^/[^/\\\\]#', $naar) && $naar !== 'dag.php') $naar = 'dag.php';
    redirect($naar);
}
$wacht = $_SESSION['tweestaps_wacht'] ?? null;
// Stap 2 verloopt na 5 minuten
if ($wacht && ($wacht['tot'] ?? 0) < time()) { unset($_SESSION['tweestaps_wacht']); $wacht = null; $fout = 'Te lang gewacht. Log opnieuw in.'; }
if (isset($_GET['opnieuw'])) { unset($_SESSION['tweestaps_wacht']); $wacht = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pogingen = (int)q('SELECT COUNT(*) c FROM login_pogingen WHERE ip = ? AND tijd > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)', [$ip])->fetch()['c'];
    if ($pogingen >= 10) {
        $fout = 'Te veel pogingen. Probeer het over een kwartier opnieuw.';
    } elseif ($wacht && isset($_POST['code'])) {
        // Stap 2: de code uit de code-app
        $row = q('SELECT * FROM gebruikers WHERE id = ? AND actief = 1', [(int)$wacht['id']])->fetch();
        if ($row && $row['totp_geheim'] && totp_klopt($row['totp_geheim'], (string)$_POST['code'])) login_klaar($row, $ip);
        q('INSERT INTO login_pogingen (ip) VALUES (?)', [$ip]);
        $fout = 'De code klopt niet. Kijk of je de code van Watt Watt gebruikt en probeer het opnieuw.';
    } else {
        $gn = strtolower(clean_text($_POST['gebruikersnaam'] ?? ''));
        $row = q('SELECT * FROM gebruikers WHERE gebruikersnaam = ? AND actief = 1', [$gn])->fetch();
        if ($row && password_verify((string)($_POST['wachtwoord'] ?? ''), $row['wachtwoord_hash'])) {
            // Heeft dit account een code-app, dan eerst de code (stap 2)
            if ($row['totp_geheim']) { session_regenerate_id(true); $_SESSION['tweestaps_wacht'] = ['id' => (int)$row['id'], 'tot' => time() + 300]; redirect('login.php'); }
            login_klaar($row, $ip);
        }
        q('INSERT INTO login_pogingen (ip) VALUES (?)', [$ip]);
        $fout = 'Gebruikersnaam of wachtwoord klopt niet.';
    }
}

page_head('Inloggen');
?>
<section class="card narrow login">
  <div class="logo">⚡</div>
  <h1><?= e(APP_NAME) ?></h1>
  <?php if ($fout): ?><div class="flash err"><?= e($fout) ?></div><?php endif; ?>
  <?php if ($wacht): ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <p>Open de code-app op je telefoon en typ de 6 cijfers bij <b>Watt Watt</b>.</p>
    <label>Code <input name="code" class="codeveld" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
    <button class="btn primary big">Verder</button>
  </form>
  <p class="small"><a href="login.php?opnieuw=1">Terug naar inloggen</a> · Telefoon kwijt? Vraag een collega op kantoor om je code-app te resetten.</p>
  <?php else: ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Gebruikersnaam <input name="gebruikersnaam" autocapitalize="none" autocomplete="username" required autofocus></label>
    <label>Wachtwoord <input name="wachtwoord" type="password" autocomplete="current-password" required></label>
    <button class="btn primary big">Inloggen</button>
  </form>
  <p class="muted small">Geen account? Vraag het kantoor om er een aan te maken.</p>
  <?php endif; ?>
</section>
<?php page_foot();
