<?php
// Eenmalige installatie: maakt tabellen en de eerste beheerder.
// Werkt alleen zolang er nog geen gebruikers zijn.
require __DIR__ . '/inc/bootstrap.php';

require __DIR__ . '/inc/schema.php';
schema_migreren();

$heeftGebruikers = (int)q('SELECT COUNT(*) c FROM gebruikers')->fetch()['c'] > 0;
$fout = '';

if (!$heeftGebruikers && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $naam = clean_text($_POST['naam'] ?? '');
    $gn = strtolower(clean_text($_POST['gebruikersnaam'] ?? ''));
    $ww = (string)($_POST['wachtwoord'] ?? '');
    if ($naam === '' || $gn === '' || strlen($ww) < 8) {
        $fout = 'Vul alles in; wachtwoord minimaal 8 tekens.';
    } else {
        q('INSERT INTO gebruikers (naam, gebruikersnaam, wachtwoord_hash, rol) VALUES (?,?,?,\'admin\')',
            [$naam, $gn, password_hash($ww, PASSWORD_DEFAULT)]);
        flash('Installatie klaar. Log in met je nieuwe account.');
        redirect('login.php');
    }
}

page_head('Installatie');
?>
<section class="card narrow">
  <h1>Installatie</h1>
  <?php if ($heeftGebruikers): ?>
    <p>De database is bijgewerkt. Er bestaan al gebruikers, dus hier kun je geen beheerder meer aanmaken.</p>
    <p><a class="btn" href="login.php">Naar inloggen</a></p>
  <?php else: ?>
    <p>Tabellen zijn aangemaakt. Maak de eerste beheerder aan.</p>
    <?php if ($fout): ?><div class="flash err"><?= e($fout) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label>Naam <input name="naam" required></label>
      <label>Gebruikersnaam <input name="gebruikersnaam" autocapitalize="none" required></label>
      <label>Wachtwoord (min. 8 tekens) <input name="wachtwoord" type="password" minlength="8" required></label>
      <button class="btn primary">Beheerder aanmaken</button>
    </form>
  <?php endif; ?>
</section>
<?php page_foot();
