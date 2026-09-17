<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_login();
// Wie bei den Bug-Tickets: Admin/Vorsitz über die Verwaltung ODER das Technik-Referat über die Dashboard-Kachel
if (!can_manage_bugs()) {
    flash('Rückmeldungen verwalten dürfen nur das Technik-Referat, Vorsitz oder Admin.', 'error');
    redirect('../dashboard.php');
}

$me = current_member();
$myId = $me ? (int)$me['id'] : null;
$kinds = feedback_kinds();
$stati = feedback_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    $id = (int)($_POST['id'] ?? 0);
    $f = feedback_get($id);
    if (!$f) { flash('Rückmeldung nicht gefunden.', 'error'); redirect('feedback.php'); }

    if ($action === 'fb_comment') {
        $body = (string)($_POST['body'] ?? '');
        if (feedback_comment_add($id, $myId, $body)) {
            feedback_notify_author($id, 'Antwort auf deine Rückmeldung: ' . $body, $myId);
        }
        redirect('feedback.php?id=' . $id);
    }

    if ($action === 'fb_status') {
        $status = (string)($_POST['status'] ?? 'new');
        if (!isset($stati[$status])) { redirect('feedback.php?id=' . $id); }
        // „Abgelehnt" ohne Begründung wäre respektlos gegenüber jemandem, der sich Mühe gemacht hat
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($status === 'rejected' && $reason === '') {
            flash('Bitte schreib kurz dazu, warum du das ablehnst – das geht als Antwort an die Person.', 'error');
            redirect('feedback.php?id=' . $id);
        }
        feedback_set_status($id, $status);
        if ($reason !== '') feedback_comment_add($id, $myId, $reason);
        $msg = [
            'new'      => 'Deine Rückmeldung wurde wieder auf „Neu" gesetzt.',
            'accepted' => 'Deine Rückmeldung wurde angenommen und ist in Arbeit. 👍',
            'done'     => 'Deine Rückmeldung wurde umgesetzt. ✅',
            'rejected' => 'Deine Rückmeldung wird nicht umgesetzt.',
        ][$status];
        feedback_notify_author($id, $msg . ($reason !== '' ? ' – ' . $reason : ''), $myId);
        flash('Stand gesetzt: ' . $stati[$status]['label'] . '.', 'success');
        redirect('feedback.php?id=' . $id);
    }

    if ($action === 'fb_delete') {
        feedback_delete($id); // Verlauf per Cascade
        flash('Rückmeldung gelöscht.', 'success');
        redirect('feedback.php');
    }
    redirect('feedback.php');
}

$fmt = fn($ts) => ($t = strtotime((string)$ts)) ? date('d.m.Y, H:i', $t) . ' Uhr' : '';
$who = fn($name) => trim((string)$name) !== '' ? h(short_name((string)$name)) : 'Technik-Login';

$detailId = (int)($_GET['id'] ?? 0);
$detail = $detailId ? feedback_get($detailId) : null;
$allowed = array_merge(['open', 'all'], array_keys($stati), array_keys($kinds));
$filter = in_array(($_GET['filter'] ?? ''), $allowed, true) ? (string)$_GET['filter'] : 'open';

page_header('Feedback', true);
?>
<p class="small"><a href="<?= can_admin() ? 'index.php' : '../dashboard.php' ?>">‹ <?= can_admin() ? 'Verwaltung' : 'Dashboard' ?></a></p>

<?php if ($detail): // ===== DETAIL ===== ?>
  <?php
  $st = $stati[$detail['status']] ?? $stati['new'];
  $kd = $kinds[$detail['kind']] ?? $kinds['idee'];
  $comments = feedback_comments($detailId);
  ?>
  <div class="events-toolbar">
    <h1><i class="ti ti-message-2" style="color:var(--petrol)"></i> Rückmeldung #<?= (int)$detail['id'] ?></h1>
    <a class="btn secondary" href="feedback.php?filter=<?= h($filter) ?>"><i class="ti ti-arrow-left"></i> Alle Rückmeldungen</a>
  </div>

  <div class="card">
    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
      <span class="pill <?= h($st['pill']) ?>"><i class="ti <?= h($st['icon']) ?>"></i> <?= h($st['label']) ?></span>
      <span class="pill pill-info"><i class="ti <?= h($kd['icon']) ?>"></i> <?= h($kd['label']) ?></span>
    </div>
    <p style="margin:.7rem 0 0;white-space:pre-wrap;overflow-wrap:anywhere"><?= nl2br(h($detail['body'])) ?></p>
    <p class="small muted" style="margin:.7rem 0 0">
      <i class="ti ti-user"></i> von <?= $who($detail['author']) ?> · <?= h($fmt($detail['created_at'])) ?>
      <?php if (trim((string)$detail['page']) !== ''): ?><br><i class="ti ti-link"></i> geschickt von: <code><?= h($detail['page']) ?></code><?php endif; ?>
    </p>

    <div class="section-title" style="margin-bottom:.3rem"><i class="ti ti-progress-check"></i> Bearbeitungsstand</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="fb_status"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
      <div class="fb-kinds">
        <?php foreach ($stati as $k => $sk): ?>
          <label class="fb-kind">
            <input type="radio" name="status" value="<?= h($k) ?>"<?= $detail['status'] === $k ? ' checked' : '' ?>>
            <span><i class="ti <?= h($sk['icon']) ?>"></i> <?= h($sk['label']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label for="reason" style="margin-top:.8rem">Kurze Rückmeldung dazu <span class="muted">(bei „Abgelehnt" nötig – geht als Antwort raus)</span></label>
      <textarea name="reason" id="reason" rows="2" placeholder="z. B. „Kommt mit dem nächsten Update" oder „Machen wir nicht, weil …"></textarea>
      <div class="btn-row" style="margin-top:.7rem">
        <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Stand setzen &amp; benachrichtigen</button>
      </div>
    </form>

    <div class="btn-row" style="margin-top:.9rem">
      <form method="post" data-confirm="Diese Rückmeldung inkl. Verlauf löschen?" data-confirm-danger data-confirm-ok="Löschen">
        <?= csrf_field() ?><input type="hidden" name="action" value="fb_delete"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
        <button class="btn danger small" type="submit"><i class="ti ti-trash"></i> Löschen</button>
      </form>
    </div>
  </div>

  <div class="section-title"><i class="ti ti-messages"></i> Verlauf <span class="count"><?= count($comments) ?></span></div>
  <div class="card">
    <?php if (!$comments): ?>
      <p class="small muted" style="margin:0 0 .8rem">Noch keine Antworten.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:.7rem;margin-bottom:1rem">
        <?php foreach ($comments as $c): ?>
          <div style="border-left:3px solid var(--line);padding:.1rem 0 .1rem .7rem">
            <div class="small muted" style="margin-bottom:.15rem"><strong style="color:var(--ink)"><?= $who($c['author']) ?></strong> · <?= h($fmt($c['created_at'])) ?></div>
            <div style="white-space:pre-wrap;overflow-wrap:anywhere"><?= nl2br(h($c['body'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="fb_comment"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
      <label for="cbody">Antworten</label>
      <textarea name="body" id="cbody" rows="3" placeholder="Rückfrage, Zwischenstand, Danke …" required></textarea>
      <div class="btn-row" style="margin-top:.6rem"><button class="btn" type="submit"><i class="ti ti-send"></i> Antworten</button></div>
    </form>
  </div>

<?php else: // ===== LISTE ===== ?>
  <div class="events-toolbar">
    <h1><i class="ti ti-message-2" style="color:var(--petrol)"></i> Feedback</h1>
  </div>

  <div class="card">
    <p class="muted" style="margin-top:0">Rückmeldungen aus dem Feedback-Fenster im Footer und von der Seite <a href="../feedback.php">Feedback</a>. Sie sind ausschließlich hier und für die einreichende Person sichtbar. Jede Antwort und jeder gesetzte Stand geht als <strong>Mitteilung aufs Dashboard</strong> der Person. Kaputtes läuft weiter über die <a href="bugs.php">Bug-Reports</a>.</p>
  </div>

  <?php
  $tabs = ['open' => 'Unerledigt', 'done' => 'Umgesetzt', 'rejected' => 'Abgelehnt', 'all' => 'Alle'];
  $list = feedback_all($filter);
  ?>
  <div class="section-title">
    <i class="ti ti-list-details"></i> Rückmeldungen
    <span style="margin-left:.6rem;font-weight:400;font-size:.85rem">
      <?php foreach ($tabs as $k => $lbl): ?>
        <a href="feedback.php?filter=<?= h($k) ?>" style="text-decoration:none;margin-right:.5rem;<?= $filter === $k ? 'font-weight:700;color:var(--petrol-dark)' : 'color:var(--muted)' ?>"><?= h($lbl) ?></a>
      <?php endforeach; ?>
      <span class="muted">·</span>
      <?php foreach ($kinds as $k => $kk): ?>
        <a href="feedback.php?filter=<?= h($k) ?>" style="text-decoration:none;margin-left:.5rem;<?= $filter === $k ? 'font-weight:700;color:var(--petrol-dark)' : 'color:var(--muted)' ?>"><i class="ti <?= h($kk['icon']) ?>"></i> <?= h($kk['label']) ?></a>
      <?php endforeach; ?>
    </span>
  </div>

  <?php if (!$list): ?>
    <div class="card"><p class="empty"><i class="ti ti-mood-smile"></i> <?= $filter === 'open' ? 'Nichts Unerledigtes – alles durch.' : 'Nichts in dieser Ansicht.' ?></p></div>
  <?php else: ?>
    <div class="card" style="padding:.4rem .2rem">
      <table class="list">
        <tbody>
        <?php foreach ($list as $f): $st = $stati[$f['status']] ?? $stati['new']; $kd = $kinds[$f['kind']] ?? $kinds['idee']; ?>
          <tr>
            <td style="width:1%;white-space:nowrap">
              <span class="pill <?= h($st['pill']) ?>" style="font-size:.7rem"><?= h($st['label']) ?></span>
            </td>
            <td>
              <a href="feedback.php?id=<?= (int)$f['id'] ?>&amp;filter=<?= h($filter) ?>" style="font-weight:600;text-decoration:none;color:var(--ink)"><i class="ti <?= h($kd['icon']) ?>"></i> #<?= (int)$f['id'] ?> <?= h(feedback_excerpt((string)$f['body'])) ?></a>
              <div class="small muted"><?= $who($f['author']) ?> · <?= h($fmt($f['created_at'])) ?><?php if ((int)$f['n_comments'] > 0): ?> · <i class="ti ti-message"></i> <?= (int)$f['n_comments'] ?><?php endif; ?></div>
            </td>
            <td style="text-align:right;width:1%;white-space:nowrap">
              <a class="btn secondary small" href="feedback.php?id=<?= (int)$f['id'] ?>&amp;filter=<?= h($filter) ?>">Öffnen</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php
page_footer();
