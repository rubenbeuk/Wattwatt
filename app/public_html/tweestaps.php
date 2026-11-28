<?php
// Code-app instellen (tweede inlogstap). Verplicht voor kantoor; monteurs, verkoop en onderaannemers mogen het ook aanzetten.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$rij = q('SELECT totp_geheim, totp_sinds FROM gebruikers WHERE id = ?', [$u['id']])->fetch();
$aan = (bool)$rij['totp_geheim'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    if ($actie === 'bevestig' && !empty($_SESSION['totp_nieuw'])) {
        if (totp_klopt($_SESSION['totp_nieuw'], (string)($_POST['code'] ?? ''))) {
            q('UPDATE gebruikers SET totp_geheim = ?, totp_sinds = UTC_TIMESTAMP() WHERE id = ?', [$_SESSION['totp_nieuw'], $u['id']]);
            unset($_SESSION['totp_nieuw']);
            flash('De code-app staat aan. Voortaan vraagt de app bij het inloggen ook om de code.');
            redirect('dag.php');
        }
        flash('Die code klopt niet. Controleer of de tijd op je telefoon goed staat en probeer de nieuwste code.', 'err');
    } elseif ($actie === 'opnieuw' && $aan) {
        if (totp_klopt($rij['totp_geheim'], (string)($_POST['code'] ?? ''))) {
            q('UPDATE gebruikers SET totp_geheim = NULL, totp_sinds = NULL WHERE id = ?', [$u['id']]);
            unset($_SESSION['totp_nieuw']);
            flash('Oude koppeling verwijderd. Stel de code-app hieronder opnieuw in.');
        } else flash('Die code klopt niet.', 'err');
    }
    redirect('tweestaps.php');
}

if (!$aan && empty($_SESSION['totp_nieuw'])) $_SESSION['totp_nieuw'] = totp_nieuw_geheim();
$geheim = $_SESSION['totp_nieuw'] ?? '';
$verplicht = tweestaps_verplicht($u);

page_head('Code-app');
?>
<section class="card narrow">
  <h1>Inloggen met een code</h1>
  <?php if ($aan): ?>
    <p>De code-app staat aan sinds <?= e(fmt_dt((string)$rij['totp_sinds'])) ?>. Bij het inloggen typ je na je wachtwoord de 6 cijfers uit de app.</p>
    <details><summary>Nieuwe telefoon? Opnieuw instellen</summary>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="actie" value="opnieuw">
        <label>Huidige code <input name="code" class="codeveld" inputmode="numeric" autocomplete="one-time-code" required></label>
        <button class="btn">Opnieuw instellen</button></form>
      <p class="small muted">Oude telefoon al kwijt? Vraag een collega op kantoor om je code-app te resetten bij Gebruikers.</p>
    </details>
  <?php else: ?>
    <?php if ($verplicht): ?><div class="flash warn">Voor kantoor is een tweede inlogstap verplicht. Het kost één minuut.</div><?php endif; ?>
    <ol>
      <li>Installeer een code-app op je telefoon, bijvoorbeeld <b>Microsoft Authenticator</b> (die heb je misschien al voor je mail), Google Authenticator of 1Password.</li>
      <li>Kies in de app <i>Account toevoegen</i> en scan deze code:</li>
    </ol>
    <p class="center"><span class="qrvak" id="qr" data-link="<?= e(totp_link($geheim, $u['gebruikersnaam'])) ?>"></span></p>
    <p class="small muted">Lukt scannen niet? Typ dan deze sleutel over: <code><?= e(trim(chunk_split($geheim, 4, ' '))) ?></code></p>
    <ol start="3"><li>Typ hieronder de 6 cijfers die de app nu laat zien.</li></ol>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="actie" value="bevestig">
      <label>Code <input name="code" class="codeveld" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
      <button class="btn primary big">Aanzetten</button>
    </form>
    <?php if ($verplicht): ?><form method="post" action="logout.php"><?= csrf_field() ?><button class="linkbtn small">Uitloggen</button></form><?php endif; ?>
  <?php endif; ?>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('qr');
  if (el && window.QRCode) new QRCode(el, { text: el.dataset.link, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
});
</script>
<?php page_foot(['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js']);
