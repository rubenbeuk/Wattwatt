<?php
require __DIR__ . '/inc/bootstrap.php';

if (user()) redirect('dag.php');

$fout = '';
// Via de Cloudflare Worker (nginx laat alleen verzoeken met het geheim door) staat het echte IP in X-Client-IP
$ip = ($_SERVER['HTTP_X_CLIENT_IP'] ?? '') ?: ($_SERVER['REMOTE_ADDR'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pogingen = (int)q('SELECT COUNT(*) c FROM login_pogingen WHERE ip = ? AND tijd > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)', [$ip])->fetch()['c'];
    if ($pogingen >= 10) {
        $fout = 'Te veel pogingen. Probeer het over een kwartier opnieuw.';
    } else {
        $gn = strtolower(clean_text($_POST['gebruikersnaam'] ?? ''));
        $row = q('SELECT * FROM gebruikers WHERE gebruikersnaam = ? AND actief = 1', [$gn])->fetch();
        if ($row && password_verify((string)($_POST['wachtwoord'] ?? ''), $row['wachtwoord_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$row['id'], 'naam' => $row['naam'], 'gebruikersnaam' => $row['gebruikersnaam'], 'rol' => $row['rol']];
            q('UPDATE gebruikers SET laatst_ingelogd = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
            q('DELETE FROM login_pogingen WHERE ip = ?', [$ip]);
            $naar = $_SESSION['na_login'] ?? 'dag.php';
            unset($_SESSION['na_login']);
            // Alleen lokale paden toestaan
            if (!preg_match('#^/[^/\\\\]#', $naar) && $naar !== 'dag.php') $naar = 'dag.php';
            redirect($naar);
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
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Gebruikersnaam <input name="gebruikersnaam" autocapitalize="none" autocomplete="username" required autofocus></label>
    <label>Wachtwoord <input name="wachtwoord" type="password" autocomplete="current-password" required></label>
    <button class="btn primary big">Inloggen</button>
  </form>
  <p class="muted small">Geen account? Vraag het kantoor om er een aan te maken.</p>
</section>
<?php page_foot();
