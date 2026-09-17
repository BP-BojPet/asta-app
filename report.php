<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();

$meeting = current_report_meeting();

// Sitzung: abmelden / nur online teilnehmen – gleicher Shortcut wie im Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'meeting_rsvp' && $me) {
    check_csrf();
    $st = db()->prepare('SELECT * FROM meetings WHERE id = ?');
    $st->execute([(int)($_POST['meeting_id'] ?? 0)]);
    if ($mt = $st->fetch()) {
        $r = meeting_rsvp_set($mt, (int)$me['id'], (string)($_POST['status'] ?? ''), (string)($_POST['reason'] ?? ''));
        flash($r['msg'], $r['ok'] ? 'success' : 'error');
    }
    redirect('report.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$me || trim((string)$me['referat']) === '') {
        flash('Nur Mitglieder mit Referat können Berichte schreiben.', 'error');
        redirect('report.php');
    }
    if (!$meeting) {
        flash('Aktuell ist keine berichtspflichtige Sitzung offen.', 'error');
        redirect('report.php');
    }
    report_save((string)$me['referat'], (int)$meeting['id'], trim((string)($_POST['content'] ?? '')), (int)$me['id']);
    flash('Bericht gespeichert. Danke!', 'success');
    redirect('report.php');
}

page_header('Sitzungen & Berichte');
?>
<div class="events-toolbar">
  <h1><i class="ti ti-gavel" style="color:var(--petrol)"></i> Sitzungen &amp; Berichte</h1>
</div>

<?php
// --- Kommende Sitzungen: nur ansehen & teilen (keine Verwaltungs-Funktionen) ---
$upcomingMeetings = db()->query(
    "SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa' AND date(starts_at) >= date('now','localtime') ORDER BY starts_at LIMIT 12"
)->fetchAll();
?>
<div class="section-title"><i class="ti ti-calendar-event"></i> Kommende Sitzungen</div>
<?php if (!$upcomingMeetings): ?>
  <div class="card"><p class="empty"><i class="ti ti-calendar-off"></i> Aktuell ist keine Sitzung angesetzt.</p></div>
<?php else:
  $meetingCard = function ($mt) use ($me) { ?>
      <div class="card hoverable stretch-card">
        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
          <strong style="font-size:1.02rem"><a class="tl-stretch" href="meeting.php?id=<?= (int)$mt['id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h(meeting_label($mt)) ?></a></strong>
          <span style="margin-left:auto;display:inline-flex;align-items:center;gap:.35rem">
            <?php if ($me && meeting_rsvp_open($mt)): $mrId = (int)$mt['id']; // Abmelden/online – gleicher Dialog wie im Dashboard ?>
              <form id="rmrsvpOff<?= $mrId ?>" method="post" hidden><?= csrf_field() ?><input type="hidden" name="action" value="meeting_rsvp"><input type="hidden" name="meeting_id" value="<?= $mrId ?>"><input type="hidden" name="status" value="abgemeldet"><input type="hidden" name="reason" value=""></form>
              <form id="rmrsvpOn<?= $mrId ?>" method="post" hidden><?= csrf_field() ?><input type="hidden" name="action" value="meeting_rsvp"><input type="hidden" name="meeting_id" value="<?= $mrId ?>"><input type="hidden" name="status" value="online"><input type="hidden" name="reason" value=""></form>
              <button type="button" class="btn small secondary" style="white-space:nowrap;padding:.3rem .45rem" title="Abmelden oder online teilnehmen"
                onclick="astaConfirm({title:'Deine Teilnahme',icon:'ti-door-exit',message:'Kommst du zur Sitzung? Du kannst dich abmelden (du wirst im Protokoll als entschuldigt geführt) oder vermerken, dass du nur online teilnimmst – änderbar bis 18:00 Uhr am Sitzungstag.',input:{label:'Warum ist die Teilnahme (in Präsenz) nicht möglich? Wird nur an den Vorsitz weitergeleitet.',placeholder:'z. B. Klausur, krank, unterwegs …'},buttons:[{label:'Abbrechen',class:'secondary'},{label:'Nur online dabei',class:'secondary',requireInput:true,onClick:function(v){var f=document.getElementById('rmrsvpOn<?= $mrId ?>');f.querySelector('[name=reason]').value=v||'';f.submit();}},{label:'Abmelden – ich komme nicht',requireInput:true,onClick:function(v){var f=document.getElementById('rmrsvpOff<?= $mrId ?>');f.querySelector('[name=reason]').value=v||'';f.submit();}}]})"><i class="ti ti-door-exit"></i></button>
            <?php endif; ?>
            <?= share_button('meeting.php?id=' . (int)$mt['id'], 'Sitzung teilen') ?>
          </span>
        </div>
        <div class="small muted" style="margin-top:.3rem"><i class="ti ti-clock"></i> <?= h(fmt_slot($mt['starts_at'], null)) ?><?php if (trim((string)$mt['location']) !== ''): ?> · <i class="ti ti-map-pin"></i> <?= h($mt['location']) ?><?php endif; ?></div>
      </div>
  <?php }; ?>
  <div class="cardgrid">
    <?php foreach (array_slice($upcomingMeetings, 0, 2) as $mt) $meetingCard($mt); ?>
  </div>
  <?php if (count($upcomingMeetings) > 2): ?>
    <details class="tl-more">
      <summary><span class="more-c">… <?= count($upcomingMeetings) - 2 ?> weitere anzeigen</span><span class="more-o">weniger anzeigen</span></summary>
      <div class="cardgrid" style="margin-top:.6rem">
        <?php foreach (array_slice($upcomingMeetings, 2) as $mt) $meetingCard($mt); ?>
      </div>
    </details>
  <?php endif; ?>
<?php endif; ?>

<div class="section-title"><i class="ti ti-file-text"></i> Dein Referat-Bericht</div>
<?php if (!$me): ?>
  <div class="card"><p class="empty"><i class="ti ti-info-circle"></i> Mit dem Technik-Login können keine Berichte geschrieben werden.</p></div>
<?php elseif (trim((string)$me['referat']) === ''): ?>
  <div class="card"><p class="empty"><i class="ti ti-info-circle"></i> Dir ist noch kein Referat zugeordnet. Melde dich beim Vorsitz/Sekretariat, damit du Berichte abgeben kannst.</p></div>
<?php elseif (!$meeting): ?>
  <div class="card"><p class="empty"><i class="ti ti-calendar-off"></i> Aktuell ist keine berichtspflichtige Sitzung angesetzt. Sobald die nächste feststeht, kannst du hier deinen Bericht eintragen.</p></div>
<?php else:
    $rep = report_get((string)$me['referat'], (int)$meeting['id']);
    $prev = meeting_before((string)$meeting['starts_at']);
    $editor = $rep && $rep['updated_by'] ? member_get((int)$rep['updated_by']) : null;
?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-clipboard-text"></i> Referat <?= h($me['referat']) ?></div>
    <p class="muted small" style="margin-top:0">
      Für die <strong><?= meeting_link($meeting) ?></strong> am <strong><?= h(fmt_slot($meeting['starts_at'], null)) ?></strong>
      <?php if ($prev): ?> · Zeitraum seit der Sitzung am <?= h(fmt_date(substr((string)$prev['starts_at'], 0, 10))) ?><?php endif; ?>
    </p>
    <?php if ($editor): ?>
      <p class="small"><i class="ti ti-pencil"></i> Zuletzt bearbeitet von <strong><?= h(first_name($editor['name'])) ?></strong> am <?= h(fmt_date(substr((string)$rep['updated_at'], 0, 10))) ?> – du kannst ergänzen.</p>
    <?php endif; ?>
    <form method="post" action="report.php">
      <?= csrf_field() ?>
      <label for="content">Stichpunkte – <strong>ein Punkt pro Zeile</strong> <span class="small muted">(kein Strich/Spiegelstrich nötig – das Aufzählungszeichen kommt automatisch)</span></label>
      <textarea name="content" id="content" rows="10" placeholder="Tagesgeschäft&#10;Unterstützung Demokratiefest&#10;…"><?= h($rep['content'] ?? '') ?></textarea>
      <div class="bullet-preview" id="preview" hidden>
        <div class="small muted">So erscheint dein Bericht:</div>
        <ul></ul>
      </div>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit">Speichern</button></div>
    </form>
    <script>
    (function(){
      var ta = document.getElementById('content'), pv = document.getElementById('preview');
      if (!ta || !pv) return;
      var ul = pv.querySelector('ul');
      function render(){
        var lines = ta.value.split(/\r?\n/).map(function(l){ return l.replace(/^\s*[-–—•*·]+\s*/, '').trim(); }).filter(Boolean);
        ul.innerHTML = '';
        lines.forEach(function(l){ var li = document.createElement('li'); li.textContent = l; ul.appendChild(li); });
        pv.hidden = lines.length === 0;
      }
      ta.addEventListener('input', render);
      render();
    })();
    </script>
    <p class="small muted">Mitglieder desselben Referats teilen sich diesen Bericht – ihr seht den aktuellen Stand und ergänzt ihn.</p>
  </div>
<?php endif; ?>
<?php
page_footer();
