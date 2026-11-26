<?php
// A4-poster voor het magazijn: grote QR naar de app, met (optioneel) een testlogin.
// De ingevulde tekst blijft in de browser; er wordt niets opgeslagen of verstuurd.
require __DIR__ . '/inc/bootstrap.php';
require_admin();

$url = APP_URL . '/';
page_head('Poster', 'labels');
?>
<section class="card noprint">
  <div class="row between wrap">
    <h1>A4-poster voor het magazijn</h1>
    <div class="row"><a class="btn ghost" href="labels.php">Terug</a><button class="btn primary" type="button" onclick="window.print()">🖨 Printen</button></div>
  </div>
  <p class="muted small">Pas de tekst aan; de poster verandert direct mee. Print op A4, schaal 100%, kop- en voetteksten uit.</p>
  <div class="row wrap">
    <label class="grow">Kop <input data-bind="kop" value="Test de nieuwe voorraadapp!"></label>
    <label class="grow">Gebruikersnaam <input data-bind="gn" value="test" autocomplete="off"></label>
    <label class="grow">Wachtwoord <input data-bind="ww" value="" autocomplete="off" placeholder="leeg = niet op poster"></label>
    <label class="grow">Vragen aan <input data-bind="contact" value="Ruben"></label>
  </div>
</section>

<div class="poster">
  <div class="p-top"><span class="p-bolt">⚡</span> Watt Watt Voorraad</div>
  <h1 class="p-kop" data-out="kop">Test de nieuwe voorraadapp!</h1>
  <p class="p-sub">Scan de code met de camera van je telefoon</p>
  <div class="p-qr" data-qr="<?= e($url) ?>" data-px="900"></div>
  <p class="p-url"><?= e(preg_replace('#^https?://#', '', rtrim($url, '/'))) ?></p>
  <div class="p-login" data-show="ww" hidden>
    <div><span>Gebruikersnaam</span><b data-out="gn">test</b></div>
    <div><span>Wachtwoord</span><b data-out="ww"></b></div>
  </div>
  <ol class="p-steps">
    <li><b>Scan</b> de barcode op de verpakking of het QR-label op de bak</li>
    <li><b>Pak</b>: vul het aantal en het projectnummer in</li>
    <li><b>Klaar</b>: zo weten we altijd waar het materiaal heen is</li>
  </ol>
  <p class="p-foot">Tip: zet de app op je beginscherm (Deel → Zet op beginscherm). Vragen of iets raars gezien? Meld het bij <b data-out="contact">Ruben</b>.</p>
</div>
<?php page_foot(['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js']);
