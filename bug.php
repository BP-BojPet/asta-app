<?php
require __DIR__ . '/lib.php';
db();
require_login();

$me = current_member();
$myId = $me ? (int)$me['id'] : null;
$myName = $me ? short_name((string)$me['name']) : 'Technik-Login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'bug_add') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));
        if ($title === '') {
            flash('Bitte beschreibe den Fehler kurz im Titel.', 'error');
            redirect('bug.php');
        }
        db()->prepare('INSERT INTO bug_reports(title, body, created_by) VALUES(?,?,?)')->execute([$title, $body, $myId]);
        $newId = (int)db()->lastInsertId();
        bug_notify_technik('Neuer Fehler gemeldet von ' . $myName . ': „' . $title . '"', $myId);
        flash('Danke! Dein Fehler-Ticket ist beim Technik-Referat gelandet. 💚', 'success');
        redirect('bug.php?id=' . $newId);
    }

    // Antwort auf das eigene Ticket
    if ($action === 'bug_reply') {
        $id  = (int)($_POST['id'] ?? 0);
        $bug = bug_get($id);
        if (!$bug || (int)($bug['created_by'] ?? 0) !== $myId) { flash('Ticket nicht gefunden.', 'error'); redirect('bug.php'); }
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '') {
            db()->prepare('INSERT INTO bug_comments(bug_id, member_id, body) VALUES(?,?,?)')->execute([$id, $myId, $body]);
            db()->prepare("UPDATE bug_reports SET updated_at = datetime('now','localtime') WHERE id = ?")->execute([$id]);
            bug_notify_technik('Neuer Kommentar von ' . $myName . ' zu „' . (string)$bug['title'] . '"', $myId);
        }
        redirect('bug.php?id=' . $id);
    }
    redirect('bug.php');
}

$fmt = fn($ts) => ($t = strtotime((string)$ts)) ? date('d.m.Y, H:i', $t) . ' Uhr' : '';
$who = fn($name) => trim((string)$name) !== '' ? h(short_name((string)$name)) : 'Technik-Referat';

// Detailansicht des eigenen Tickets?
$detailId = (int)($_GET['id'] ?? 0);
$detail = $detailId ? bug_get($detailId) : null;
if ($detail && (int)($detail['created_by'] ?? 0) !== $myId) { redirect('bug.php'); } // nur eigene Tickets

page_header($detail ? 'Mein Fehler-Ticket' : 'Fehler melden');
?>

<?php if ($detail): // ===== EIGENES TICKET ===== ?>
  <?php $open = ($detail['status'] ?? 'open') !== 'done'; $comments = bug_comments($detailId); ?>
  <p class="small"><a href="bug.php">‹ Zurück zur Übersicht</a></p>
  <div class="events-toolbar">
    <h1><i class="ti ti-bug" style="color:var(--petrol)"></i> Mein Fehler-Ticket</h1>
  </div>

  <div class="card">
    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
      <span class="pill <?= $open ? 'pill-warn' : 'pill-ok' ?>"><i class="ti <?= $open ? 'ti-alert-circle' : 'ti-circle-check' ?>"></i> <?= $open ? 'In Bearbeitung' : 'Erledigt' ?></span>
      <strong style="font-size:1.1rem"><?= h($detail['title']) ?></strong>
    </div>
    <?php if (trim((string)$detail['body']) !== ''): ?>
      <p style="margin:.6rem 0 0;white-space:pre-wrap;overflow-wrap:anywhere"><?= nl2br(h($detail['body'])) ?></p>
    <?php endif; ?>
    <p class="small muted" style="margin:.7rem 0 0">gemeldet am <?= h($fmt($detail['created_at'])) ?></p>
  </div>

  <div class="section-title"><i class="ti ti-messages"></i> Verlauf <span class="count"><?= count($comments) ?></span></div>
  <div class="card">
    <?php if (!$comments): ?>
      <p class="small muted" style="margin:0 0 .8rem">Noch keine Antworten. Das Technik-Referat meldet sich hier, wenn es Rückfragen gibt oder der Fehler behoben ist.</p>
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
      <?= csrf_field() ?><input type="hidden" name="action" value="bug_reply"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
      <label for="rbody">Etwas ergänzen?</label>
      <textarea name="body" id="rbody" rows="3" placeholder="Mehr Details, Rückmeldung …" required></textarea>
      <div class="btn-row" style="margin-top:.6rem"><button class="btn" type="submit"><i class="ti ti-send"></i> Antworten</button></div>
    </form>
  </div>

<?php else: // ===== MELDEN + EIGENE LISTE ===== ?>
  <?php $mine = $myId ? bugs_for_member($myId) : []; ?>
  <div class="events-toolbar">
    <h1><i class="ti ti-bug" style="color:var(--petrol)"></i> Fehler melden</h1>
  </div>

  <div class="card">
    <p class="muted" style="margin-top:0">Etwas funktioniert nicht wie erwartet? Melde es hier – dein Hinweis wird als <strong>Ticket</strong> beim Technik-Referat angelegt, das sich darum kümmert. Bei Antworten oder wenn der Fehler erledigt ist, bekommst du eine <strong>Mitteilung auf deinem Dashboard</strong>. Für allgemeines Lob &amp; Wünsche gibt es den <strong>Feedback</strong>-Knopf unten im Footer.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bug_add">
      <label for="title">Worum geht's? (kurzer Titel)</label>
      <input type="text" name="title" id="title" placeholder="z. B. Abstimmen-Button reagiert nicht" required>
      <label for="body">Was genau passiert? (optional)</label>
      <textarea name="body" id="body" rows="5" placeholder="Wo bist du, was hast du gemacht, was hättest du erwartet? Schritte zum Nachstellen helfen am meisten."></textarea>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-send"></i> Fehler melden</button></div>
    </form>
  </div>

  <div class="section-title"><i class="ti ti-list-details"></i> Meine gemeldeten Fehler <span class="count"><?= count($mine) ?></span></div>
  <?php if (!$mine): ?>
    <div class="card"><p class="empty"><i class="ti ti-mood-smile"></i> Du hast noch keine Fehler gemeldet.</p></div>
  <?php else: ?>
    <div class="card" style="padding:.4rem .2rem">
      <table class="list">
        <tbody>
        <?php foreach ($mine as $b): $open = ($b['status'] ?? 'open') !== 'done'; ?>
          <tr>
            <td style="width:1%;white-space:nowrap">
              <span class="pill <?= $open ? 'pill-warn' : 'pill-ok' ?>" style="font-size:.7rem"><?= $open ? 'offen' : 'erledigt' ?></span>
            </td>
            <td>
              <a href="bug.php?id=<?= (int)$b['id'] ?>" style="font-weight:600;text-decoration:none;color:var(--ink)"><?= h($b['title']) ?></a>
              <div class="small muted">gemeldet am <?= h($fmt($b['created_at'])) ?></div>
            </td>
            <td style="text-align:right;width:1%;white-space:nowrap">
              <a class="btn secondary small" href="bug.php?id=<?= (int)$b['id'] ?>">Öffnen</a>
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
