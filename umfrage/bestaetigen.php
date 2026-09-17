<?php
/**
 * Schritt 2: Bestätigung der Mailadresse – hier wird die wartende Stimme gezählt.
 *
 * Der Token aus dem Link ist zugleich der Schlüssel zu den verschlüsselt wartenden Antworten.
 * Mit dem Zählen verschwindet die wartende Zeile, und damit der letzte Faden zwischen Person
 * und Stimme.
 */
require __DIR__ . '/umfrage-lib.php';

$token = trim(umfrage_param($_GET['t'] ?? ''));
$r = $token !== '' ? umfrage_confirm($token) : ['ok' => false, 'msg' => 'Dieser Link ist unvollständig.'];
$poll = $r['poll'] ?? null;

if (!$r['ok']) http_response_code(410);
umfrage_head($poll ? $poll['title'] : 'Bestätigung', '', true);
?>
<div class="card <?= $r['ok'] ? 'um-ok' : 'um-fehlerkarte' ?>">
  <h1><?= $r['ok'] ? '<i class="ti ti-circle-check"></i> Danke – deine Stimme zählt' : '<i class="ti ti-alert-triangle"></i> Das hat nicht geklappt' ?></h1>
  <p><?= nl2br(h((string)$r['msg'])) ?></p>
  <?php if ($r['ok']): ?>
    <p class="um-klein"><i class="ti ti-lock"></i> Die Verbindung zwischen deiner Mailadresse und deinen Antworten
      ist jetzt gelöscht. Auch wir können nicht mehr sehen, wie du abgestimmt hast.</p>
  <?php endif; ?>
  <?php if ($poll): ?>
    <?php if (umfrage_results_public($poll)): ?>
      <p><a class="btn secondary" href="ergebnis.php?u=<?= h(rawurlencode((string)$poll['slug'])) ?>">Ergebnis ansehen</a></p>
    <?php elseif (!$r['ok'] && umfrage_is_open($poll)): ?>
      <p><a class="btn secondary" href="index.php?u=<?= h(rawurlencode((string)$poll['slug'])) ?>">Noch einmal abstimmen</a></p>
    <?php elseif (trim((string)$poll['ends_at']) !== ''): ?>
      <p class="um-klein">Das Ergebnis wird nach dem Ende der Umfrage veröffentlicht – am <?= h(umfrage_dt((string)$poll['ends_at'])) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
umfrage_foot();
