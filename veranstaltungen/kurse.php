<?php
/**
 * Alle Kurse und regelmäßigen Angebote.
 *
 * Eigene Seite, weil ein Kurs keine Wochenübersicht verträgt: Er hat kein Datum, sondern einen
 * Rhythmus, und stünde er zwischen den Veranstaltungen, wäre er entweder überall oder nirgends.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard()) exit;

$suche = trim(wl_param($_GET['q'] ?? ''));
$cat   = wl_param($_GET['cat'] ?? '');
$frei  = wl_param($_GET['frei'] ?? '') === '1';
$offen = wl_param($_GET['offen'] ?? '') === '1';   // Einstieg jederzeit
if (!isset(wl_cats()[$cat])) $cat = '';

$kurse = wl_items_public(['kind' => 'kurs', 'cat' => $cat, 'suche' => $suche, 'frei' => $frei], 120);
if ($offen) $kurse = array_values(array_filter($kurse, static fn ($k) => (int)$k['einstieg'] === 1));

function wl_kurs_url(array $neu): string
{
    $jetzt = ['q' => wl_param($_GET['q'] ?? ''), 'cat' => wl_param($_GET['cat'] ?? ''),
              'frei' => wl_param($_GET['frei'] ?? ''), 'offen' => wl_param($_GET['offen'] ?? '')];
    $p = array_filter(array_merge($jetzt, $neu), static fn ($v) => (string)$v !== '');
    return 'kurse.php' . ($p ? '?' . http_build_query($p) : '');
}

wl_head('Kurse', wl_text('kurse_meta'));
wl_nav('kurse');
?>

<section class="wl-hero">
  <div class="wl-hero-in wl-in">
    <h1 class="wl-h1"><?= wl_marke('läuft') ?> jede Woche<?php if (wl_ort() !== ''): ?> <span class="wl-h1-ort">in <em><?= h(wl_ort()) ?></em></span><?php endif; ?></h1>
    <?= wl_absaetze(wl_text('kurse_lead')) ?>
    <form class="wl-suche" method="get" action="kurse.php" role="search">
      <input type="search" name="q" value="<?= h($suche) ?>"
             placeholder="<?= h(wl_text('kurse_suche')) ?>"
             aria-label="<?= h(wl_t('suchen_kurse')) ?>">
      <button type="submit"><?= h(wl_t('suche')) ?></button>
    </form>
  </div>
</section>

<div class="wl-filters">
  <div class="wl-filters-in">
    <?php /* Dieselbe Leiste wie auf der Startseite: erst die Schalter, dann die Kategorien im
             Aufklapp-Menü. Kurse haben keinen Zeitraum – sie laufen regelmäßig –, deshalb fehlt
             hier der Zeit-Schalter und „Einstieg jederzeit" tritt an seine Stelle. */ ?>
    <div class="wl-fend">
      <a class="wl-chip wl-prom<?= $offen ? ' on' : '' ?>" href="<?= h(wl_kurs_url(['offen' => $offen ? '' : '1'])) ?>"
         aria-pressed="<?= $offen ? 'true' : 'false' ?>"><?= h(wl_t('filter_offen')) ?></a>
      <a class="wl-chip wl-prom<?= $frei ? ' on' : '' ?>" href="<?= h(wl_kurs_url(['frei' => $frei ? '' : '1'])) ?>"
         aria-pressed="<?= $frei ? 'true' : 'false' ?>"><?= h(wl_t('filter_frei')) ?></a>
    </div>
    <?php wl_kat_dropdown($cat, static fn (string $k): string => wl_kurs_url(['cat' => $k])); ?>
    <?php /* „Filter zurücksetzen" steht NICHT hier, sondern neben der Überschrift – wie auf der
             Startseite: In der Leiste erschiene es nur bei aktivem Filter und gäbe ihr am Handy
             eine dritte Zeile. Die Leiste hat immer dieselben Bedienelemente, immer dieselbe
             Höhe. */ ?>
  </div>
</div>

<section class="wl-sect">
  <div class="wl-sect-h">
    <h2><?= count($kurse) ?> <?= count($kurse) === 1 ? 'Kurs' : 'Kurse' ?></h2>
    <span class="wl-sect-links">
      <?php if ($cat !== '' || $frei || $offen || $suche !== ''): ?>
        <a class="wl-fclear" href="kurse.php"><?= h(wl_t('filter_weg')) ?></a>
      <?php endif; ?>
      <a href="einreichen.php">Eigenen Kurs eintragen →</a>
    </span>
  </div>
  <?php if ($kurse): ?>
    <div class="wl-grid">
      <?php foreach ($kurse as $k) wl_kachel($k); ?>
    </div>
  <?php else: ?>
    <?= wl_absaetze(wl_text('kurse_leer'), 'wl-leer') ?>
  <?php endif; ?>
</section>

<?php wl_foot(); ?>
