<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();

page_header('Basis-Score');

if (!$me) {
    echo '<div class="flash flash-info">Mit dem Technik-Login gibt es keinen persönlichen Score. <a href="admin/score.php">Zur Score-Übersicht</a></div>';
    page_footer();
    exit;
}

$bb = member_basis_breakdown($me);
$band = basis_band($bb['total']);
$color = ['einwandfrei' => 'var(--green)', 'ausbaufaehig' => 'var(--amber)', 'gespraech' => 'var(--red)'][$band];
$pill  = ['einwandfrei' => 'pill-ok', 'ausbaufaehig' => 'pill-warn', 'gespraech' => 'pill-bad'][$band];
// Ring-Füllung: 0 Punkte = voller Ring („einwandfrei"), je Minuspunkt weniger (Skala bis −8)
$ringPct = max(0, min(100, ($bb['total'] + 8) / 8 * 100));
?>
<p class="small"><a href="dashboard.php">‹ Dashboard</a></p>
<h1><i class="ti <?= basis_band_icon($band) ?>"></i> Mein Basis-Score</h1>

<div class="card" style="display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap">
  <div class="score-ring" data-ring="<?= round($ringPct, 1) ?>" style="--rc:<?= $color ?>">
    <div class="sr-in">
      <div class="sr-val" style="color:<?= $color ?>"><?= $bb['total'] ?></div>
      <div class="sr-sub">Punkte</div>
    </div>
  </div>
  <div>
    <div><span class="pill <?= $pill ?>"><i class="ti <?= basis_band_icon($band) ?>"></i> <?= h(basis_band_label($band)) ?></span></div>
    <p class="small muted" style="margin:.5rem 0 0">Es gibt <strong>nur Minuspunkte</strong> – <strong>0 ist also einwandfrei</strong>. Ab <strong>−3</strong> Schlecht, ab <strong>−6</strong> Gesprächsbedarf.</p>
  </div>
</div>

<div class="section-title"><i class="ti ti-info-circle"></i> Was der Basis-Score misst</div>
<div class="card">
  <p style="margin-top:0">Der Basis-Score bildet die <strong>wirklich niederschwelligsten Dinge</strong> ab, die von der Arbeit im AStA erwartet werden – nichts davon kostet mehr als ein paar Minuten. Wer diese Grundpflichten erledigt, steht automatisch bei <strong>0 („Einwandfrei")</strong>:</p>
  <ul style="margin:.4rem 0 .6rem;padding-left:1.2rem;line-height:1.7">
    <li><strong>Bericht abgeben</strong> vor berichtspflichtigen Sitzungen (nur mit Referat) – vergessen: <strong>−1</strong></li>
    <li><strong>Von Sitzungen abmelden</strong>, wenn du nicht kommst – unentschuldigt gefehlt: <strong>−2</strong></li>
    <li><strong>Eventscore nicht im schlechten Bereich</strong> – Bereich „Ausbaufähig": <strong>−1</strong> (alles andere ist okay)</li>
    <li><strong>Die App mindestens einmal pro Woche öffnen</strong> – eine volle Woche gar nicht: <strong>−1</strong> (Wochen mit eingetragener Abwesenheit zählen nicht)</li>
    <li><strong>Wichtige Infos binnen 7 Tagen abhaken</strong> („Gelesen – erledigt" auf der Info-Seite) – verpasst: <strong>−1</strong> je Info</li>
    <li><strong>Abstimmungsgegenstände vor der Sitzung lesen</strong> – Sitzung mit ungelesenen Gegenständen: <strong>−1</strong></li>
    <li><strong>An Umlaufverfahren teilnehmen</strong> – bei einem abgeschlossenen Umlauf nicht abgestimmt: <strong>−2</strong> (Abstimmen geht online von überall, daher gilt das <em>auch bei eingetragener Abwesenheit</em>)</li>
    <li><strong>An verpflichtenden Abstimmungen teilnehmen</strong> – bei einer abgeschlossenen Pflicht-Abstimmung nicht abgestimmt: <strong>−1</strong> (ebenfalls auch im Urlaub)</li>
    <li class="small muted" style="list-style:none;margin-left:-1.2rem"><i class="ti ti-info-circle" style="color:var(--petrol)"></i> Für die letzten beiden Punkte gilt: Wird <strong>vorzeitig beendet</strong> – also bevor die Frist abgelaufen ist –, gibt es <strong>keinen Abzug</strong>. Wer bis zur Frist noch Zeit gehabt hätte, wird nicht dafür bestraft, dass früher abgebrochen wurde.</li>
  </ul>
  <p class="small muted" style="margin:0">Dazu kann der Vorsitz eine <strong>manuelle Korrektur (±)</strong> vergeben. Gezählt wird seit <strong><?= fmt_date(basis_since()) ?></strong>; dein Beitrittsdatum wird berücksichtigt. Deinen <a href="score.php">Eventscore</a> findest du auf seiner eigenen Seite.</p>
</div>

<div class="section-title"><i class="ti ti-list-details"></i> Meine Abzüge</div>
<div class="card">
  <?php if (!$bb['rows'] && !$bb['adjust']): ?>
    <p class="empty"><i class="ti ti-circle-check" style="color:var(--green)"></i> Keine Abzüge – einwandfrei, weiter so!</p>
  <?php else: ?>
    <table class="list">
      <thead><tr><th>Abzug</th><th style="text-align:right">Punkte</th></tr></thead>
      <tbody>
      <?php foreach ($bb['rows'] as $r): ?>
        <tr><td class="small"><?= h($r['reason']) ?></td><td style="text-align:right;font-weight:700;color:var(--red)"><?= (int)$r['points'] ?></td></tr>
      <?php endforeach; ?>
      <?php if ($bb['adjust']): ?>
        <tr><td class="small"><em>Manuelle Korrektur (Vorsitz)</em></td><td style="text-align:right;font-weight:700;color:<?= $bb['adjust'] > 0 ? 'var(--green)' : 'var(--red)' ?>"><?= $bb['adjust'] > 0 ? '+' . $bb['adjust'] : $bb['adjust'] ?></td></tr>
      <?php endif; ?>
      </tbody>
      <tfoot><tr class="tally"><td class="name-col">Gesamt</td><td style="text-align:right;font-weight:700;color:<?= $color ?>"><?= $bb['total'] ?></td></tr></tfoot>
    </table>
  <?php endif; ?>
</div>
<?php
page_footer();
