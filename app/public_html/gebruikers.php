<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actie = $_POST['actie'] ?? '';
    if ($actie === 'nieuw') {
        $naam = clean_text($_POST['naam'] ?? '');
        $gn = strtolower(clean_text($_POST['gebruikersnaam'] ?? ''));
        $ww = (string)($_POST['wachtwoord'] ?? '');
        $rol = isset(ROLLEN[$_POST['rol'] ?? '']) ? $_POST['rol'] : 'monteur';
        $zonderLogin = isset($_POST['zonder_login']);
        if ($naam === '' || $gn === '' || (!$zonderLogin && strlen($ww) < 8)) {
            flash('Vul naam en gebruikersnaam in; wachtwoord minimaal 8 tekens.', 'err');
        } elseif (q('SELECT id FROM gebruikers WHERE gebruikersnaam = ?', [$gn])->fetch()) {
            flash('Die gebruikersnaam bestaat al.', 'err');
        } else {
            q('INSERT INTO gebruikers (naam, gebruikersnaam, wachtwoord_hash, rol, planbaar) VALUES (?,?,?,?,?)',
                [$naam, $gn, $zonderLogin ? '' : password_hash($ww, PASSWORD_DEFAULT), $zonderLogin ? 'monteur' : $rol, isset($_POST['planbaar']) || $zonderLogin ? 1 : 0]);
            flash($zonderLogin ? e($naam) . ' toegevoegd. Deze persoon kan worden ingepland maar niet inloggen.' : 'Account voor ' . e($naam) . ' aangemaakt. Geef de gebruikersnaam en het wachtwoord persoonlijk door.');
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($actie === 'wachtwoord') {
            $ww = (string)($_POST['wachtwoord'] ?? '');
            if (strlen($ww) < 8) flash('Wachtwoord minimaal 8 tekens.', 'err');
            else { q('UPDATE gebruikers SET wachtwoord_hash = ? WHERE id = ?', [password_hash($ww, PASSWORD_DEFAULT), $id]); flash('Wachtwoord gewijzigd.'); }
        } elseif ($actie === 'actief' && $id !== $u['id']) {
            q('UPDATE gebruikers SET actief = 1 - actief WHERE id = ?', [$id]); flash('Bijgewerkt.');
        } elseif ($actie === 'beslisser' && $id !== $u['id'] && is_beslisser()) {
            q("UPDATE gebruikers SET beslisser = 1 - beslisser WHERE id = ? AND rol = 'admin'", [$id]); flash('Bijgewerkt.');
        } elseif ($actie === 'planbaar') {
            q('UPDATE gebruikers SET planbaar = 1 - planbaar WHERE id = ?', [$id]); flash('Bijgewerkt.');
        } elseif ($actie === 'volgorde') {
            q('UPDATE gebruikers SET volgorde = ? WHERE id = ?', [(int)($_POST['volgorde'] ?? 100), $id]); flash('Volgorde opgeslagen.');
        } elseif ($actie === 'rol' && $id !== $u['id'] && isset(ROLLEN[$_POST['rol'] ?? ''])) {
            q('UPDATE gebruikers SET rol = ? WHERE id = ?', [$_POST['rol'], $id]);
            // Alleen kantoor kan beslisser zijn
            if ($_POST['rol'] !== 'admin') q('UPDATE gebruikers SET beslisser = 0 WHERE id = ?', [$id]);
            flash('Rol gewijzigd naar ' . e(ROLLEN[$_POST['rol']]) . '.');
        } elseif ($actie === 'tweestaps_reset' && $id !== $u['id']) {
            q('UPDATE gebruikers SET totp_geheim = NULL, totp_sinds = NULL WHERE id = ?', [$id]);
            flash('Code-app gereset. Bij de volgende keer inloggen stelt deze persoon hem opnieuw in.');
        }
    }
    redirect('gebruikers.php');
}

$rows = q('SELECT * FROM gebruikers ORDER BY actief DESC, volgorde, naam')->fetchAll();
page_head('Gebruikers', 'gebruikers');
?>
<section class="card">
  <h1>Gebruikers</h1>
  <div class="tablewrap"><table>
    <thead><tr><th>Naam</th><th>Gebruikersnaam</th><th>Rol</th><th>In de planning</th><th>Laatst ingelogd</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['actief'] ? '' : 'muted' ?>">
        <td><?= e($r['naam']) ?></td><td><?= e($r['gebruikersnaam']) ?></td>
        <td><?php if ((int)$r['id'] !== $u['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="actie" value="rol">
              <select name="rol" onchange="this.form.submit()" aria-label="Rol"><?php foreach (ROLLEN as $k => $n): ?><option value="<?= $k ?>"<?= $r['rol'] === $k ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></form>
            <?php else: ?><?= e(ROLLEN[$r['rol']] ?? $r['rol']) ?><?php endif; ?>
            <?= $r['wachtwoord_hash'] === '' ? '<br><span class="small muted">nog geen login</span>' : '' ?><?= $r['beslisser'] ? '<br><span class="tag st-klaar">Beslist</span>' : '' ?>
            <?= $r['totp_geheim'] ? '<br><span class="small muted">code-app aan</span>' : ($r['rol'] === 'admin' && $r['wachtwoord_hash'] !== '' ? '<br><span class="small warn-ink">code-app nog niet ingesteld</span>' : '') ?></td>
        <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn small <?= $r['planbaar'] ? 'primary' : 'ghost' ?>" name="actie" value="planbaar"><?= $r['planbaar'] ? 'Ja' : 'Nee' ?></button>
            <?php if ($r['planbaar']): ?><input name="volgorde" value="<?= (int)$r['volgorde'] ?>" inputmode="numeric" aria-label="Volgorde in de planning" style="width:64px;min-height:36px;padding:.3rem .5rem" onchange="this.form.querySelector('[data-v]').click()"><button name="actie" value="volgorde" data-v hidden></button><?php endif; ?>
          </form></td>
        <td><?= $r['laatst_ingelogd'] ? e(fmt_dt($r['laatst_ingelogd'])) : '–' ?></td>
        <td class="actions">
          <form method="post" class="row">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input name="wachtwoord" type="password" placeholder="Nieuw wachtwoord" minlength="8" autocomplete="new-password">
            <button class="btn small" name="actie" value="wachtwoord">Zet</button>
            <?php if ((int)$r['id'] !== $u['id']): ?>
              <?php if ($r['totp_geheim']): ?><button class="btn small ghost" name="actie" value="tweestaps_reset" formnovalidate onclick="return confirm('Code-app van <?= e($r['naam']) ?> resetten? Alleen doen als de telefoon kwijt of nieuw is.')">Reset code-app</button><?php endif; ?>
              <button class="btn small ghost" name="actie" value="actief" formnovalidate><?= $r['actief'] ? 'Blokkeer' : 'Activeer' ?></button>
              <?php if ($r['rol'] === 'admin' && is_beslisser()): ?><button class="btn small ghost" name="actie" value="beslisser" formnovalidate title="Wat een beslisser invult of goedkeurt is direct goed"><?= $r['beslisser'] ? 'Beslist niet meer' : 'Laat beslissen' ?></button><?php endif; ?>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="card narrow">
  <h2>Nieuw account</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="actie" value="nieuw">
    <label>Naam <input name="naam" required></label>
    <label>Gebruikersnaam <input name="gebruikersnaam" autocapitalize="none" required></label>
    <label>Wachtwoord (min. 8 tekens) <input name="wachtwoord" type="password" minlength="8" autocomplete="new-password"></label>
    <label>Rol <select name="rol"><option value="monteur">Monteur (eigen dag, ploeg, foto's, scannen; geen bedragen)</option><option value="verkoop">Verkoop (klanten, projecten, service, agenda; geen bedragen)</option><option value="onderaannemer">Onderaannemer (alleen eigen dag, uren en foto's van projecten waar hij staat)</option><option value="admin">Kantoor (alles; inloggen met code-app)</option></select></label>
    <label class="check"><input type="checkbox" name="planbaar" checked> Staat in de planning</label>
    <label class="check"><input type="checkbox" name="zonder_login"> Kan niet inloggen (onderaannemer die alleen wordt ingepland; wachtwoord mag leeg)</label>
    <button class="btn primary">Account aanmaken</button>
  </form>
</section>
<?php page_foot();
