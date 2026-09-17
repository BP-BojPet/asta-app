<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$pendingAbsence = null; // bei Überschneidung mit „extrem wichtigem" Event: Rückfrage statt direkt speichern

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'add';
    if ($action === 'add') {
        // Zielperson: standardmäßig man selbst; Admin/Vorsitz dürfen für andere eintragen
        $targetId = $me ? (int)$me['id'] : 0;
        if (can_admin() && (int)($_POST['for_member'] ?? 0) > 0) {
            $cand = (int)$_POST['for_member'];
            $chk = db()->prepare('SELECT 1 FROM members WHERE id=? AND active=1'); $chk->execute([$cand]);
            if ($chk->fetchColumn()) $targetId = $cand;
        }
        if (!$targetId) { flash('Mit dem Technik-Login kannst du nur für ein konkretes Mitglied eintragen – bitte eine Person auswählen.', 'error'); redirect('absence.php'); }
        $from  = trim((string)($_POST['starts_at'] ?? ''));
        $to    = trim((string)($_POST['ends_at'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!$from || !$to) {
            flash('Bitte Von- und Bis-Datum angeben.', 'error');
        } elseif ($to < $from) {
            flash('Das Bis-Datum darf nicht vor dem Von-Datum liegen.', 'error');
        } else {
            $conflicts = important_events_overlapping($from, $to);
            if ($conflicts && empty($_POST['ack'])) {
                // extrem wichtiges Event im Zeitraum → erst bestätigen lassen, noch nicht speichern
                $pendingAbsence = ['from' => $from, 'to' => $to, 'reason' => $reason, 'conflicts' => $conflicts, 'for_member' => $targetId];
            } else {
                $st = db()->prepare('INSERT INTO absences(member_id, starts_at, ends_at, reason) VALUES(?,?,?,?)');
                $st->execute([$targetId, $from, $to, $reason]);
                $tname = $targetId === (int)($me['id'] ?? 0) ? '' : (string)(db()->query('SELECT name FROM members WHERE id=' . $targetId)->fetchColumn() ?: '');
                // Sitzungen im Zeitraum: automatisch abmelden (entschuldigt)
                $autoMeetings = absence_apply_to_meetings($targetId, $from, $to, $reason);
                // Vorsitz per Dashboard-Nachricht informieren (inkl. Event-Überschneidungen + Auto-Abmeldungen)
                notify_vorsitz_absence($targetId, $from, $to, $reason, (int)($me['id'] ?? 0), $autoMeetings);
                $note = $autoMeetings ? ' Automatisch von ' . count($autoMeetings) . ' Sitzung' . (count($autoMeetings) > 1 ? 'en' : '') . ' abgemeldet (entschuldigt).' : '';
                flash(($tname !== '' ? 'Abwesenheit für ' . $tname . ' eingetragen.' : 'Abwesenheit eingetragen.') . $note, 'success');
                redirect('absence.php');
            }
        }
    } elseif ($action === 'delete') {
        $aid = (int)($_POST['id'] ?? 0);
        // nur eigene löschen – Organisator:innen dürfen alle
        $own = db()->prepare('SELECT member_id FROM absences WHERE id = ?');
        $own->execute([$aid]);
        $ownerId = (int)$own->fetchColumn();
        if (is_organizer() || ($me && $ownerId === (int)$me['id'])) {
            $range = db()->prepare('SELECT starts_at, ends_at FROM absences WHERE id = ?');
            $range->execute([$aid]);
            $ab = $range->fetch();
            db()->prepare('DELETE FROM absences WHERE id = ?')->execute([$aid]);
            // Automatische Sitzungs-Abmeldungen aus dieser Abwesenheit zurücknehmen (von Hand gesetzte bleiben)
            $reverted = $ab ? absence_revert_meetings($ownerId, (string)$ab['starts_at'], (string)$ab['ends_at']) : [];
            if ($reverted) {
                $rname = (string)(db()->query('SELECT name FROM members WHERE id=' . $ownerId)->fetchColumn() ?: 'Ein Mitglied');
                foreach (db()->query("SELECT id FROM members WHERE active = 1 AND role = 'vorsitz'")->fetchAll(PDO::FETCH_COLUMN) as $vid) {
                    if ((int)$vid === $ownerId) continue;
                    dm_send((int)$vid, 'Sitzungs-Teilnahme', $rname . ' hat die Abwesenheit gelöscht – die automatische Sitzungs-Abmeldung wurde zurückgenommen: ' . implode(', ', $reverted) . '.', null, false, false, 'dm_sitzung');
                }
            }
            flash('Abwesenheit gelöscht.' . ($reverted ? ' Automatische Sitzungs-Abmeldung zurückgenommen: ' . implode(', ', $reverted) . '.' : ''), 'success');
        } else {
            flash('Du kannst nur deine eigenen Abwesenheiten löschen.', 'error');
        }
        redirect('absence.php');
    }
}

$upcoming = db()->query(
    "SELECT a.*, m.name FROM absences a JOIN members m ON m.id = a.member_id
     WHERE a.ends_at >= date('now','localtime') ORDER BY a.starts_at, m.name"
)->fetchAll();

page_header('Abwesenheiten');
?>
<div class="events-toolbar">
  <h1><i class="ti ti-plane-departure" style="color:var(--petrol)"></i> Abwesenheiten</h1>
</div>
<p class="muted">Trag hier ein, wann du nicht verfügbar bist. Bei Events siehst du dann automatisch eine Warnung, wenn du an einem Tag zusagst, an dem du abwesend bist.</p>

<?php if ($pendingAbsence): ?>
<div class="card" style="border-color:var(--red)">
  <div class="section-title" style="margin-top:0;color:#93292c"><i class="ti ti-alert-triangle" style="color:var(--red)"></i> Extrem wichtiges Event in diesem Zeitraum</div>
  <p>In deinem gewählten Zeitraum (<strong><?= fmt_date($pendingAbsence['from']) ?> – <?= fmt_date($pendingAbsence['to']) ?></strong>) liegt:</p>
  <ul style="margin:.3rem 0 .8rem">
    <?php foreach ($pendingAbsence['conflicts'] as $c): ?>
      <li><strong><?= event_link($c) ?></strong> <span class="muted">(<?= h(fmt_event_range($c['starts_at'], $c['ends_at'])) ?>)</span></li>
    <?php endforeach; ?>
  </ul>
  <p>Dieses Event ist als <strong>extrem wichtig</strong> markiert. Eine Abwesenheit ist hier nur in <strong>Ausnahmefällen nach Absprache mit dem Vorsitz</strong> möglich.</p>
  <div class="btn-row" style="margin-top:.9rem">
    <form method="post" action="absence.php" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="ack" value="1">
      <?php if (!empty($pendingAbsence['for_member'])): ?><input type="hidden" name="for_member" value="<?= (int)$pendingAbsence['for_member'] ?>"><?php endif; ?>
      <input type="hidden" name="starts_at" value="<?= h($pendingAbsence['from']) ?>">
      <input type="hidden" name="ends_at" value="<?= h($pendingAbsence['to']) ?>">
      <input type="hidden" name="reason" value="<?= h($pendingAbsence['reason']) ?>">
      <button class="btn danger" type="submit">Trotzdem eintragen</button>
    </form>
    <a class="btn secondary" href="absence.php">Abbrechen</a>
  </div>
</div>
<?php endif; ?>

<?php if ($me || can_admin()): ?>
<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-calendar-plus"></i> Abwesenheit eintragen <?php if (!can_admin() && $me): ?><span class="small muted" style="font-weight:400">· für <?= h($me['name']) ?></span><?php endif; ?></div>
  <form method="post" action="absence.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <?php if (can_admin()): ?>
      <label for="for_member">Für wen?</label>
      <select name="for_member" id="for_member">
        <?php if (!$me): ?><option value="">– Person wählen –</option><?php endif; ?>
        <?php foreach (members_all() as $mm): ?>
          <option value="<?= (int)$mm['id'] ?>" <?= ($me && (int)$me['id'] === (int)$mm['id']) ? 'selected' : '' ?>><?= h($mm['name']) ?><?= ($me && (int)$me['id'] === (int)$mm['id']) ? ' (du)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <div class="field-row">
      <div><label for="starts_at">Von</label><input type="text" class="fp-date" name="starts_at" id="starts_at" placeholder="Datum wählen"></div>
      <div><label for="ends_at">Bis</label><input type="text" class="fp-date" name="ends_at" id="ends_at" placeholder="Datum wählen"></div>
    </div>
    <label for="reason">Grund (optional)</label>
    <input type="text" name="reason" id="reason" placeholder="z. B. Urlaub, Prüfung, Praktikum">
    <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit">Eintragen</button></div>
  </form>
</div>
<?php endif; ?>

<div class="section-title"><i class="ti ti-plane-departure"></i> Kommende Abwesenheiten <span class="count"><?= count($upcoming) ?></span></div>
<?php if (!$upcoming): ?>
  <div class="card"><p class="empty"><i class="ti ti-calendar-off"></i> Keine kommenden Abwesenheiten eingetragen.</p></div>
<?php else: ?>
  <div class="card" style="padding:.4rem .2rem">
  <table class="list">
    <thead><tr><th>Name</th><th>Zeitraum</th><th>Grund</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $a): $own = $me && (int)$me['id'] === (int)$a['member_id']; ?>
      <tr>
        <td><?= h($a['name']) ?><?= $own ? ' <span class="small muted">(du)</span>' : '' ?></td>
        <td><?= fmt_date($a['starts_at']) ?> – <?= fmt_date($a['ends_at']) ?></td>
        <td class="muted"><?= h($a['reason']) ?></td>
        <td style="text-align:right">
          <?php if (is_organizer() || $own): ?>
          <form method="post" action="absence.php" data-confirm="Diese Abwesenheit löschen?" data-confirm-danger data-confirm-ok="Löschen">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="btn danger small" type="submit">Löschen</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php
page_footer();
