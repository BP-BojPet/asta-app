<?php
/**
 * Öffentliches Ergebnis – erst NACH dem Ende der Umfrage.
 *
 * Kein Zwischenstand: Der würde beeinflussen, wer noch nicht abgestimmt hat, und bei kleinen
 * Zahlen ließe sich aus zwei aufeinanderfolgenden Ständen die einzelne Stimme herauslesen.
 */
require __DIR__ . '/umfrage-lib.php';

$slug = trim(umfrage_param($_GET['u'] ?? ''));
$poll = $slug !== '' ? umfrage_by_slug($slug) : null;
if ($poll && (string)$poll['status'] === 'draft') $poll = null;

if (!$poll) {
    http_response_code(404);
    umfrage_head('Umfrage nicht gefunden', '', true);
    echo '<div class="card"><h1>Diese Umfrage gibt es nicht</h1></div>';
    umfrage_foot();
    exit;
}

if (!umfrage_results_public($poll)) {
    umfrage_head($poll['title'] . ' – Ergebnis');
    ?>
    <div class="card">
      <h1><?= h($poll['title']) ?></h1>
      <p>Das Ergebnis wird erst nach dem Ende der Umfrage veröffentlicht<?= trim((string)$poll['ends_at']) !== '' ? ' – am ' . h(umfrage_dt((string)$poll['ends_at'])) : '' ?>.</p>
      <p class="um-klein">Ein laufender Zwischenstand würde beeinflussen, wer noch nicht abgestimmt hat.</p>
      <p><a class="btn secondary" href="index.php?u=<?= h(rawurlencode((string)$poll['slug'])) ?>">Zur Umfrage</a></p>
    </div>
    <?php
    umfrage_foot();
    exit;
}

$zahlen = umfrage_counts((int)$poll['id']);
$res    = umfrage_results((int)$poll['id'], (int)$poll['free_public'] === 1);

umfrage_head($poll['title'] . ' – Ergebnis', 'Ergebnis der Umfrage „' . $poll['title'] . '"');
?>
<div class="card um-kopf">
  <h1><?= h($poll['title']) ?></h1>
  <p class="um-frist"><i class="ti ti-chart-bar"></i> <strong><?= (int)$zahlen['stimmen'] ?></strong>
    <?= $zahlen['stimmen'] === 1 ? 'abgegebene Stimme' : 'abgegebene Stimmen' ?><?php
    if (trim((string)$poll['ends_at']) !== ''): ?> · beendet am <?= h(umfrage_dt((string)$poll['ends_at'])) ?><?php endif; ?></p>
</div>

<?php $verlaufBild = umfrage_verlauf_html(umfrage_verlauf((int)$poll['id']));
      if ($verlaufBild !== '' && $zahlen['stimmen'] > 0): ?>
  <div class="card">
    <h2><i class="ti ti-timeline"></i> Beteiligung über die Zeit</h2>
    <?= $verlaufBild ?>
    <p class="um-klein">Stimmen je Tag. Uhrzeiten speichert die Urne bewusst nicht.</p>
  </div>
<?php endif; ?>

<?php if (!$res || $zahlen['stimmen'] === 0): ?>
  <div class="card"><p>Für diese Umfrage liegen keine Stimmen vor.</p></div>
<?php else: $lfd = 0; foreach ($res as $e): $q = $e['frage']; ?>
  <?php if ($e['typ'] === 'info'): ?>
    <div class="card um-zwischen"><h2><?= h($q['title']) ?></h2></div>
    <?php continue; ?>
  <?php endif; $lfd++; ?>
  <div class="card um-erg">
    <h2><span class="um-nr"><?= $lfd ?></span> <?= h($q['title']) ?></h2>
    <?php if ($e['typ'] === 'text'): ?>
      <?php if ((int)$poll['free_public'] !== 1): ?>
        <p class="um-klein"><i class="ti ti-eye-off"></i> <?= (int)$e['abgegeben'] ?> Freitext-Antworten – sie werden nicht öffentlich gezeigt.</p>
      <?php elseif (!$e['texte']): ?>
        <p class="um-klein">Keine Antworten.</p>
      <?php else: ?>
        <ul class="um-texte">
          <?php foreach ($e['texte'] as $t): ?><li><?= nl2br(h($t)) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php elseif ($e['typ'] === 'scale'): ?>
      <?php if ($e['schnitt'] !== null): ?>
        <p class="um-klein">Mittelwert: <strong><?= h(number_format((float)$e['schnitt'], 2, ',', '')) ?></strong>
          (Skala <?= (int)$q['scale_min'] ?>–<?= (int)$q['scale_max'] ?><?php
          if (trim((string)$q['scale_lo']) !== '' || trim((string)$q['scale_hi']) !== ''): ?>,
            <?= h((string)$q['scale_lo']) ?> bis <?= h((string)$q['scale_hi']) ?><?php endif; ?>)</p>
      <?php endif; ?>
      <?= umfrage_skala_html($e['zeilen'], $e['schnitt'] !== null ? (float)$e['schnitt'] : null, (int)$q['scale_min']) ?>
    <?php else: ?>
      <?php if ($e['typ'] === 'multi'): ?>
        <p class="um-klein">Mehrfachauswahl – die Anteile beziehen sich auf die abgegebenen Stimmen.</p>
      <?php endif; ?>
      <?= umfrage_balken_html($e['zeilen']) ?>
      <?php // „Sonstiges"-Texte folgen der Freitext-Regel: öffentlich nur, wenn Freitexte öffentlich sind.
            if (!empty($e['sonstige']) && (int)$poll['free_public'] === 1): ?>
        <p class="um-klein" style="margin-top:.5rem">Unter „Sonstiges" genannt:</p>
        <ul class="um-texte">
          <?php foreach ($e['sonstige'] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
<?php
umfrage_foot();
