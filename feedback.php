<?php
require __DIR__ . '/lib.php';
db();
require_login();

$me = current_member();
$myId = $me ? (int)$me['id'] : null;
$myName = $me ? short_name((string)$me['name']) : 'Technik-Login';

$kinds = feedback_kinds();
$stati = feedback_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    // Neue Rückmeldung – kommt entweder von dieser Seite oder aus dem Feedback-Fenster im Footer
    if ($action === 'fb_add') {
        // Rücksprung-Ziel absichern (nur lokale Pfade); leer = von dieser Seite geschickt
        $from = (string)($_POST['from'] ?? '');
        if ($from === '' || str_contains($from, '://') || substr($from, 0, 2) === '//' || $from[0] !== '/') {
            $from = '';
        }
        $message = (string)($_POST['message'] ?? '');
        $kind = feedback_kind_ok((string)($_POST['kind'] ?? 'idee'));
        $newId = feedback_add($kind, $message, $myId, $from);
        if (!$newId) {
            flash('Bitte schreib noch etwas ins Feedback-Feld.', 'error');
            redirect($from !== '' ? $from : 'feedback.php');
        }
        feedback_notify_technik($kinds[$kind]['label'] . ' von ' . $myName . ': ' . feedback_excerpt($message), $newId, $myId);
        flash('Danke für deine Rückmeldung! 💚 Unter „Feedback" siehst du, was daraus wird – dort antwortet dir auch das Technik-Referat.', 'success');
        // Aus dem Footer-Fenster bleibt man, wo man war; von dieser Seite geht es in die eigene Rückmeldung
        redirect($from !== '' && !str_contains($from, 'feedback.php') ? $from : 'feedback.php?id=' . $newId);
    }

    // Antwort auf die eigene Rückmeldung
    if ($action === 'fb_reply') {
        $id = (int)($_POST['id'] ?? 0);
        $f = feedback_get($id);
        if (!$f || (int)($f['created_by'] ?? 0) !== $myId) { flash('Rückmeldung nicht gefunden.', 'error'); redirect('feedback.php'); }
        $body = (string)($_POST['body'] ?? '');
        if (feedback_comment_add($id, $myId, $body)) {
            feedback_notify_technik('Neue Antwort von ' . $myName . ' zu einer Rückmeldung: ' . feedback_excerpt($body), $id, $myId);
        }
        redirect('feedback.php?id=' . $id);
    }
    redirect('feedback.php');
}

$fmt = fn($ts) => ($t = strtotime((string)$ts)) ? date('d.m.Y, H:i', $t) . ' Uhr' : '';
$who = fn($name) => trim((string)$name) !== '' ? h(short_name((string)$name)) : 'Technik-Referat';

// Detailansicht der eigenen Rückmeldung?
$detailId = (int)($_GET['id'] ?? 0);
$detail = $detailId ? feedback_get($detailId) : null;
if ($detail && (int)($detail['created_by'] ?? 0) !== $myId) { redirect('feedback.php'); } // nur eigene

page_header($detail ? 'Meine Rückmeldung' : 'Feedback');
?>

<?php if ($detail): // ===== EIGENE RÜCKMELDUNG ===== ?>
  <?php
  $st = $stati[$detail['status']] ?? $stati['new'];
  $kd = $kinds[$detail['kind']] ?? $kinds['idee'];
  $comments = feedback_comments($detailId);
  ?>
  <p class="small"><a href="feedback.php">‹ Zurück zur Übersicht</a></p>
  <div class="events-toolbar">
    <h1><i class="ti ti-message-2" style="color:var(--petrol)"></i> Meine Rückmeldung</h1>
  </div>

  <div class="card">
    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
      <span class="pill <?= h($st['pill']) ?>"><i class="ti <?= h($st['icon']) ?>"></i> <?= h($st['label']) ?></span>
      <span class="pill pill-info"><i class="ti <?= h($kd['icon']) ?>"></i> <?= h($kd['label']) ?></span>
    </div>
    <p style="margin:.7rem 0 0;white-space:pre-wrap;overflow-wrap:anywhere"><?= nl2br(h($detail['body'])) ?></p>
    <p class="small muted" style="margin:.7rem 0 0">geschickt am <?= h($fmt($detail['created_at'])) ?></p>
  </div>

  <div class="section-title"><i class="ti ti-messages"></i> Verlauf <span class="count"><?= count($comments) ?></span></div>
  <div class="card">
    <?php if (!$comments): ?>
      <p class="small muted" style="margin:0 0 .8rem">Noch keine Antworten. Das Technik-Referat meldet sich hier, wenn es Rückfragen gibt oder etwas passiert ist.</p>
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
      <?= csrf_field() ?><input type="hidden" name="action" value="fb_reply"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
      <label for="rbody">Etwas ergänzen?</label>
      <textarea name="body" id="rbody" rows="3" placeholder="Mehr Details, Rückmeldung …" required></textarea>
      <div class="btn-row" style="margin-top:.6rem"><button class="btn" type="submit"><i class="ti ti-send"></i> Antworten</button></div>
    </form>
  </div>

<?php else: // ===== SCHICKEN + EIGENE LISTE ===== ?>
  <?php $mine = $myId ? feedback_for_member($myId) : []; ?>
  <div class="events-toolbar">
    <h1><i class="ti ti-message-2" style="color:var(--petrol)"></i> Feedback</h1>
  </div>

  <div class="card">
    <p class="muted" style="margin-top:0">Was läuft gut, was nervt, was fehlt? Deine Rückmeldung landet beim <strong>Technik-Referat</strong> – nicht als Mail, sondern als Eintrag, den ihr beide hier weiterverfolgen könnt. Bei Antworten und Bearbeitungsständen bekommst du eine <strong>Mitteilung auf dem Dashboard</strong>. Lesen können das nur das Technik-Referat und du selbst. Ist etwas <strong>kaputt</strong>, nimm lieber <a href="bug.php">Fehler melden</a>.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="fb_add">
      <label>Worum geht's?</label>
      <div class="fb-kinds">
        <?php foreach ($kinds as $k => $kk): ?>
          <label class="fb-kind">
            <input type="radio" name="kind" value="<?= h($k) ?>"<?= $k === 'idee' ? ' checked' : '' ?>>
            <span><i class="ti <?= h($kk['icon']) ?>"></i> <?= h($kk['label']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label for="message" style="margin-top:.9rem">Deine Rückmeldung</label>
      <textarea name="message" id="message" rows="5" placeholder="Immer raus damit …" required></textarea>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-send"></i> Abschicken</button></div>
    </form>
  </div>

  <div class="section-title"><i class="ti ti-list-details"></i> Meine Rückmeldungen <span class="count"><?= count($mine) ?></span></div>
  <?php if (!$mine): ?>
    <div class="card"><p class="empty"><i class="ti ti-mood-smile"></i> Du hast noch nichts geschickt.</p></div>
  <?php else: ?>
    <div class="card" style="padding:.4rem .2rem">
      <table class="list">
        <tbody>
        <?php foreach ($mine as $f): $st = $stati[$f['status']] ?? $stati['new']; $kd = $kinds[$f['kind']] ?? $kinds['idee']; ?>
          <tr>
            <td style="width:1%;white-space:nowrap">
              <span class="pill <?= h($st['pill']) ?>" style="font-size:.7rem"><?= h($st['label']) ?></span>
            </td>
            <td>
              <a href="feedback.php?id=<?= (int)$f['id'] ?>" style="font-weight:600;text-decoration:none;color:var(--ink)"><i class="ti <?= h($kd['icon']) ?>"></i> <?= h(feedback_excerpt((string)$f['body'])) ?></a>
              <div class="small muted">geschickt am <?= h($fmt($f['created_at'])) ?><?php if ((int)$f['n_comments'] > 0): ?> · <i class="ti ti-message"></i> <?= (int)$f['n_comments'] ?><?php endif; ?></div>
            </td>
            <td style="text-align:right;width:1%;white-space:nowrap">
              <a class="btn secondary small" href="feedback.php?id=<?= (int)$f['id'] ?>">Öffnen</a>
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
