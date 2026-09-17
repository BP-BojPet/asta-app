<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();

page_header('Eventscore');

if (!$me) {
    echo '<div class="flash flash-info">Mit dem Technik-Login gibt es keinen persönlichen Score. <a href="admin/score.php">Zur Score-Übersicht</a></div>';
    page_footer();
    exit;
}

$scores = member_scores();
$bd = member_score_breakdown((int)$me['id']);
$avg = score_average($scores);
$band = score_band_for((int)$me['id'], $scores);
$bandColor = $band === 'spitze' ? '#c8901f' : ($band === 'gut' ? 'var(--green)' : ($band === 'ausbaufaehig' ? 'var(--red)' : 'var(--petrol)'));
// Ring-Füllung relativ zum aktuell besten Score (100 % = Spitzenwert)
$bestScore = $scores ? max($scores) : 0;
$ringPct = ($bestScore > 0 && $bd['total'] > 0) ? max(6, min(100, $bd['total'] / $bestScore * 100)) : 0;
?>
<p class="small"><a href="dashboard.php">‹ Dashboard</a></p>
<h1><i class="ti <?= score_band_icon($band) ?>"></i> Mein Eventscore</h1>

<div class="card" style="display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap">
  <div class="score-ring" data-ring="<?= round($ringPct, 1) ?>" style="--rc:<?= $bandColor ?>">
    <div class="sr-in">
      <div class="sr-val" style="color:<?= $bandColor ?>"><?= $bd['total'] ?></div>
      <div class="sr-sub">Punkte</div>
    </div>
  </div>
  <div>
    <div><span class="pill <?= ['spitze'=>'pill-spitze','gut'=>'pill-ok','okay'=>'pill-info','ausbaufaehig'=>'pill-bad'][$band] ?>"><i class="ti <?= score_band_icon($band) ?>"></i> <?= h(score_band_label($band)) ?></span> im Vergleich zum Schnitt (⌀ <?= number_format($avg, 1, ',', '') ?>)</div>
    <p class="small muted" style="margin:.5rem 0 0">So entstehen Punkte: eingeteilte Schicht <strong>+1</strong> (über 2&nbsp;Std. <strong>+2</strong>), nicht abgestimmt <strong>−2</strong>, verfügbar aber nicht eingeteilt oder überall „nein" <strong>0</strong>. Event-Orga/Besitzer:innen bekommen für ihr eigenes Event automatisch die <strong>Event-Maximalpunktzahl minus 1</strong> (also +3, bei wichtigen Events +4) – ohne sich eintragen zu müssen.</p>
  </div>
</div>

<div class="section-title"><i class="ti ti-list-details"></i> Aufschlüsselung</div>
<div class="card">
  <?php if (!$bd['rows'] && !$bd['adjust'] && !$bd['start']): ?>
    <p class="empty"><i class="ti ti-info-circle"></i> Noch keine gewerteten Events (es zählen nur Events mit abgelaufener Abstimmungsfrist).</p>
  <?php else: ?>
    <table class="list">
      <thead><tr><th>Event</th><th>Frist</th><th>Bewertung</th><th style="text-align:right">Punkte</th></tr></thead>
      <tbody>
      <?php foreach ($bd['rows'] as $r):
          $col = $r['points'] > 0 ? 'var(--green)' : ($r['points'] < 0 ? 'var(--red)' : 'var(--muted)'); ?>
        <tr>
          <td><a href="event.php?id=<?= $r['id'] ?>"><?= h($r['title']) ?></a></td>
          <td class="muted small"><?= fmt_date($r['date']) ?></td>
          <td class="small"><?= h($r['reason']) ?></td>
          <td style="text-align:right;font-weight:700;color:<?= $col ?>"><?= $r['points'] > 0 ? '+' . $r['points'] : $r['points'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bd['start']): ?>
        <tr>
          <td><em>Startpunkte bei Beitritt</em></td>
          <td class="muted small">—</td>
          <td class="small">Gruppenschnitt zum Zeitpunkt deines Beitritts – damit startest du im „Okay"-Bereich statt bei 0</td>
          <td style="text-align:right;font-weight:700;color:var(--green)">+<?= (int)$bd['start'] ?></td>
        </tr>
      <?php endif; ?>
      <?php if ($bd['adjust']): ?>
        <tr>
          <td><em>Manuelle Korrektur (Vorsitz)</em></td>
          <td class="muted small">—</td>
          <td class="small">Anpassung durch den Vorsitz</td>
          <td style="text-align:right;font-weight:700;color:<?= $bd['adjust'] > 0 ? 'var(--green)' : 'var(--red)' ?>"><?= $bd['adjust'] > 0 ? '+' . $bd['adjust'] : $bd['adjust'] ?></td>
        </tr>
      <?php endif; ?>
      </tbody>
      <tfoot>
        <tr class="tally"><td class="name-col" colspan="3">Gesamt</td><td style="text-align:right;font-weight:700;color:<?= $bandColor ?>"><?= $bd['total'] ?></td></tr>
      </tfoot>
    </table>
  <?php endif; ?>
</div>

<p class="small muted" style="margin-top:1rem"><i class="ti ti-checklist"></i> Deinen <a href="basisscore.php">Basis-Score</a> (Grundpflichten) findest du auf seiner eigenen Seite.</p>
<?php
page_footer();
