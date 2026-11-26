<?php
// Eén regel op de tijdlijn van een project. Verwacht $f = [t, soort, van, kop, tekst, link].
$groep = in_array($f['soort'], ['mail', 'whatsapp', 'teams', 'pipedrive', 'telefoon', 'claude', 'app'], true) ? 'bericht' : (in_array($f['soort'], ['notitie', 'taak'], true) ? $f['soort'] : 'overig');
$lang = mb_strlen($f['tekst']) > 400;
?>
<li class="fr fr-<?= e($f['soort']) ?>" data-groep="<?= $groep ?>">
  <span class="fr-ico" aria-hidden="true"><?= ['mail' => '✉', 'whatsapp' => '💬', 'teams' => 'T', 'pipedrive' => 'P', 'telefoon' => '☎', 'claude' => '✦', 'app' => '•', 'notitie' => '✎', 'melding' => '!', 'taak' => '✓', 'bestelling' => '📦', 'foto' => '▣'][$f['soort']] ?? '•' ?></span>
  <div class="fr-in">
    <div class="fr-kop"><span class="tag"><?= e(FEED_SOORT[$f['soort']] ?? $f['soort']) ?></span>
      <?php if ($f['kop'] !== ''): ?><?php if ($f['link'] !== '' && preg_match('#^[a-z_]+\.php#', $f['link'])): ?><a href="<?= e($f['link']) ?>"><b><?= e($f['kop']) ?></b></a><?php else: ?><b><?= e($f['kop']) ?></b><?php endif; ?><?php endif; ?>
      <span class="small muted"><?= e($f['van']) ?><?= $f['van'] !== '' ? ' · ' : '' ?><?= e($f['t'] !== '' ? date('d-m-Y H:i', strtotime($f['t'])) : '') ?></span></div>
    <?php if ($f['tekst'] !== ''): ?>
      <div class="fr-tekst<?= $lang ? ' kort' : '' ?>"><?= nl2br(e($f['tekst'])) ?></div>
      <?php if ($lang): ?><button type="button" class="linkbtn small fr-meer">Meer lezen</button><?php endif; ?>
    <?php endif; ?>
    <?php if ($f['link'] !== '' && preg_match('#^https://#', $f['link'])): ?><a class="small" href="<?= e($f['link']) ?>" target="_blank" rel="noopener">Openen</a><?php endif; ?>
  </div>
</li>
