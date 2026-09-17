<?php
/**
 * Pat:innenprogramm – öffentliche Einstiegsseite für Studierende.
 *
 * EIN Link für alle: Erstsemester und Pat:innen kommen auf dieselbe Seite. Der erste Blick
 * soll ohne Scrollen beantworten, worum es geht und was man anklicken muss – deshalb Hero plus
 * zwei große Kacheln (links/rechts), jede mit ihrer eigenen Erklärung. Die längere Beschreibung
 * des Programms steht darunter, für die, die mehr wissen wollen.
 *
 * SÄMTLICHE Texte kommen aus dem Register pat_text_fields() und sind in der Verwaltung pflegbar.
 * Hier steht kein fester Satz mehr – ein leeres Feld blendet das jeweilige Element aus.
 *
 * Zugriff auf Daten: ausschließlich lesend auf pat_rounds, und das nur über den Schlüssel aus
 * dem Link. Es gibt hier keinen Codepfad, der Anmeldungen auflistet oder zählt.
 */

require_once __DIR__ . '/pat-lib.php';

$slug  = pat_param($_GET['p'] ?? '');
$round = $slug !== '' ? pat_round_by_slug($slug) : null;

// Ohne Schlüssel aufgerufen (jemand tippt die Adresse): wenn genau ein Programm offen ist,
// freundlich dorthin führen – sonst gibt es schlicht nichts zu sehen.
if ($round === null && $slug === '') {
    $round = pat_round_single_open();
}

/** Pflegbaren Text holen und die Platzhalter des Programms einsetzen (Semester + Zeitplan). */
$t = function (string $key) use (&$round): string {
    $vars = $round ? pat_round_vars($round) : ['{{PROGRAMM}}' => '', '{{ANMELDUNG_VON}}' => '',
        '{{ANMELDUNG_BIS}}' => '', '{{EINTEILUNG_BIS}}' => ''];
    return trim(pat_text_fill(pat_text($key), $vars));
};

// --- Unbekannter Link / gar keine Anmeldung ---------------------------------
if ($round === null) {
    http_response_code($slug !== '' ? 404 : 200);
    pat_head('Pat:innenprogramm', 'Anmeldung zum Pat:innenprogramm des ' . pat_traeger() . '.', '', true);
    ?>
    <p class="pat-kicker"><?= h(pat_traeger()) ?></p>
    <h1>Pat:innenprogramm</h1>
    <div class="pat-note">
      <i class="ti ti-info-circle" aria-hidden="true"></i>
      <div class="pat-note-b">
        <strong><?= $slug !== '' ? 'Dieser Link ist nicht (mehr) gültig' : 'Gerade läuft keine Anmeldung' ?></strong>
        <?= pat_paragraphs($t('gone_body')) ?>
      </div>
    </div>
    <?php
    pat_foot();
    exit;
}

$label  = pat_round_label($round);
$status = (string)$round['status'];
$title  = 'Pat:innenprogramm ' . $label;
$desc   = 'Melde dich für das Pat:innenprogramm des ' . pat_traeger() . ' an – als Erstsemester '
        . 'oder als Pat:in. ' . $label . '.';
$canon  = pat_public_url((string)$round['slug']);

// --- Anmeldung zu / vorbei --------------------------------------------------
if ($status === 'closed' || $status === 'archived') {
    pat_head($title, $desc, $canon, true);
    ?>
    <p class="pat-kicker"><?= h($label) ?></p>
    <h1>Pat:innenprogramm</h1>
    <div class="pat-note">
      <i class="ti ti-lock" aria-hidden="true"></i>
      <div class="pat-note-b">
        <?php if ($t('closed_title') !== ''): ?><strong><?= h($t('closed_title')) ?></strong><?php endif; ?>
        <?= pat_paragraphs($t('closed_body')) ?>
      </div>
    </div>
    <?php
    pat_foot($label, true, (int)$round['id']);
    exit;
}

// --- Entwurf: Vorschau für den AStA, noch nicht scharf ----------------------
$preview = $status === 'draft';
$link    = 'anmeldung.php?p=' . rawurlencode((string)$round['slug']) . '&amp;r=';

// Die beiden Kacheln: alles pflegbar (Überschrift, Inhalt, Knopf-Text).
$tiles = [
    'ersti' => ['icon' => 'ti-school',      'title' => $t('tile_ersti_title'),
                'cta' => $t('tile_ersti_cta'), 'body' => pat_tile_text('tile_ersti')],
    'pate'  => ['icon' => 'ti-users-group', 'title' => $t('tile_pate_title'),
                'cta' => $t('tile_pate_cta'),  'body' => pat_tile_text('tile_pate')],
];

// „cinema": randloser Hero über die volle Fensterbreite. Der Inhaltsbereich hat dann kein
// eigenes Seitenpolster mehr – jeder Block braucht seinen eigenen .pat-in-Container.
pat_head($title, $desc, $canon, $preview, 'cinema');
?>
<?php if ($preview): ?>
  <div class="pat-in pat-in-top">
    <div class="pat-note warn">
      <i class="ti ti-eye" aria-hidden="true"></i>
      <p class="pat-note-b">
        <strong>Vorschau – die Anmeldung ist noch nicht geöffnet</strong>
        So sehen Studierende die Seite. Solange das Programm ein Entwurf ist, kann sich niemand
        anmelden. In der Verwaltung unter „Pat:innenprogramm" auf <em>Anmeldung öffnen</em> klicken.
      </p>
    </div>
  </div>
<?php endif; ?>

<section class="pat-hero">
  <div class="pat-in">
    <p class="pat-hero-chip"><i class="ti ti-calendar-heart" aria-hidden="true"></i> <?= h($label) ?></p>
    <?php if ($t('hero_kicker') !== ''): ?>
      <?php // Das Herzchen wird beim Öffnen der Seite aufgepustet und lässt danach ab und zu
            // ein kleines Herz nach links oben und eines nach rechts oben davonfliegen
            // (alles in pat.css). Reine Deko und deshalb vor Screenreadern versteckt –
            // der Satz daneben sagt schon alles. ?>
      <p class="pat-hero-kicker"><?= h($t('hero_kicker')) ?><i class="ti ti-heart-filled pat-herz" aria-hidden="true"><i class="ti ti-heart-filled pat-herz-links"></i><i class="ti ti-heart-filled pat-herz-rechts"></i></i></p>
    <?php endif; ?>
    <?php if ($t('hero_title') !== ''): ?>
      <h1><?= h($t('hero_title')) ?></h1>
    <?php endif; ?>
    <?php if ($t('hero_lead') !== ''): ?>
      <p class="pat-hero-lead"><?= nl2br(h($t('hero_lead'))) ?></p>
    <?php endif; ?>
    <?php $sched = pat_schedule_items($round); if ($sched): ?>
      <ul class="pat-sched">
        <?php foreach ($sched as $s): ?>
          <li><i class="ti <?= h($s['icon']) ?>" aria-hidden="true"></i>
            <span class="pat-sched-l"><?= h($s['label']) ?></span>
            <strong><?= h($s['date']) ?></strong></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<div class="pat-in">
<div class="pat-duo">
  <?php foreach ($tiles as $key => $tile):
      $tag = $preview ? 'div' : 'a';
  ?>
    <<?= $tag ?> class="pat-tile <?= h($key) ?><?= $preview ? ' is-preview' : '' ?>"<?= $preview ? '' : ' href="' . $link . h($key) . '"' ?>>
      <span class="pat-tile-ico"><i class="ti <?= h($tile['icon']) ?>" aria-hidden="true"></i></span>
      <?php if ($tile['title'] !== ''): ?>
        <span class="pat-tile-t"><?= h($tile['title']) ?></span>
      <?php endif; ?>
      <?php if ($tile['body']['lead'] !== ''): ?>
        <span class="pat-tile-lead"><?= h($tile['body']['lead']) ?></span>
      <?php endif; ?>
      <?php if ($tile['body']['points']): ?>
        <ul class="pat-tile-list">
          <?php foreach ($tile['body']['points'] as $p): ?>
            <li><i class="ti ti-check" aria-hidden="true"></i> <span><?= h($p) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <span class="pat-tile-cta">
        <?php if ($preview): ?>
          In der Vorschau nicht anklickbar
        <?php else: ?>
          <?= h($tile['cta'] !== '' ? $tile['cta'] : 'Weiter') ?> <i class="ti ti-arrow-right" aria-hidden="true"></i>
        <?php endif; ?>
      </span>
    </<?= $tag ?>>
  <?php endforeach; ?>
</div>

<?php if ($t('duo_note') !== ''): ?>
  <p class="pat-duo-note">
    <i class="ti ti-clock-check" aria-hidden="true"></i>
    <?= h($t('duo_note')) ?>
  </p>
<?php endif; ?>

</div><!-- /.pat-in -->

<?php if ($t('public_intro') !== ''): ?>
  <!-- Farbiges Band statt App-Karte: passt zur randlosen Optik von Hero und Kacheln -->
  <section class="pat-band">
    <div class="pat-in">
      <?php if ($t('more_title') !== ''): ?>
        <h2 class="pat-band-h"><?= h($t('more_title')) ?></h2>
      <?php endif; ?>
      <div class="pat-prose pat-more"><?= pat_paragraphs($t('public_intro')) ?></div>
    </div>
  </section>
<?php endif; ?>
<?php
pat_foot($label, true, (int)$round['id']);
