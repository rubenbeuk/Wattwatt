<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = q('SELECT wachtwoord_hash FROM gebruikers WHERE id = ?', [$u['id']])->fetch();
    $nieuw = (string)($_POST['nieuw'] ?? '');
    if (!password_verify((string)($_POST['huidig'] ?? ''), $row['wachtwoord_hash'])) flash('Huidig wachtwoord klopt niet.', 'err');
    elseif (strlen($nieuw) < 8) flash('Nieuw wachtwoord minimaal 8 tekens.', 'err');
    else { q('UPDATE gebruikers SET wachtwoord_hash = ? WHERE id = ?', [password_hash($nieuw, PASSWORD_DEFAULT), $u['id']]); flash('Wachtwoord gewijzigd.'); }
    redirect('account.php');
}

page_head('Account', 'account');
?>
<section class="card narrow">
  <h1><?= e($u['naam']) ?></h1>
  <p class="muted"><?= e($u['gebruikersnaam']) ?> · <?= e(ROLLEN[$u['rol']] ?? $u['rol']) ?></p>
  <h2>Wachtwoord wijzigen</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Huidig wachtwoord <input name="huidig" type="password" autocomplete="current-password" required></label>
    <label>Nieuw wachtwoord <input name="nieuw" type="password" minlength="8" autocomplete="new-password" required></label>
    <button class="btn primary">Wijzigen</button>
  </form>
  <h2>Inloggen met een code</h2>
  <p><?= q('SELECT totp_geheim FROM gebruikers WHERE id = ?', [$u['id']])->fetchColumn() ? 'De code-app staat aan.' : 'De code-app staat nog uit.' ?> <a href="tweestaps.php">Instellen of wijzigen</a></p>
  <h2>Meldingen op je telefoon</h2>
  <p class="small muted">Krijg een melding als er een seintje voor je is: spoedticket, iets voor je project, onderhoud om in te plannen. Per apparaat aan te zetten.</p>
  <div class="row wrap"><button type="button" class="btn" id="pushKnop" data-csrf="<?= e(csrf_token()) ?>">Meldingen aanzetten</button><button type="button" class="btn ghost small" id="pushTest" hidden>Testmelding</button></div>
  <p class="small" id="pushStatus"></p>
  <p class="small muted">Mijn dag is ook zonder bereik te lezen: open hem een keer met bereik, dan bewaart de app de laatste versie.</p>
  <form method="post" action="logout.php" class="mt"><?= csrf_field() ?><button class="btn ghost">Uitloggen</button></form>
  <p class="muted small mt">Tip: zet deze app op je beginscherm (Safari: Deel → Zet op beginscherm), dan opent hij als een gewone app.</p>
</section>
<?php page_foot();
