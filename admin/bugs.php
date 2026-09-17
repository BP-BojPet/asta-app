<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_login();
// Admin/Vorsitz (über die Verwaltung) ODER das Technik-Referat (über die Dashboard-Kachel)
if (!can_manage_bugs()) {
    flash('Bug-Tickets verwalten dürfen nur das Technik-Referat, Vorsitz oder Admin.', 'error');
    redirect('../dashboard.php');
}

$me = current_member();
$myId = $me ? (int)$me['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'bug_add') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));
        if ($title === '') { flash('Bitte einen kurzen Titel angeben.', 'error'); redirect('bugs.php'); }
        db()->prepare('INSERT INTO bug_reports(title, body, created_by) VALUES(?,?,?)')->execute([$title, $body, $myId]);
        flash('Bug-Ticket angelegt.', 'success');
        redirect('bugs.php?id=' . (int)db()->lastInsertId());
    }

    // Ab hier: alle Aktionen betreffen ein bestehendes Ticket
    $id = (int)($_POST['id'] ?? 0);
    $bug = bug_get($id);
    if (!$bug) { flash('Ticket nicht gefunden.', 'error'); redirect('bugs.php'); }

    if ($action === 'bug_comment') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '') {
            db()->prepare('INSERT INTO bug_comments(bug_id, member_id, body) VALUES(?,?,?)')->execute([$id, $myId, $body]);
            db()->prepare("UPDATE bug_reports SET updated_at = datetime('now','localtime') WHERE id = ?")->execute([$id]);
            // Melder:in benachrichtigen (außer sie kommentiert selbst)
            bug_notify_reporter($id, 'Neuer Kommentar zu deinem gemeldeten Fehler „' . (string)$bug['title'] . '": ' . $body, $myId);
        }
        redirect('bugs.php?id=' . $id);
    }

    if ($action === 'bug_status') {
        $status = ($_POST['status'] ?? '') === 'done' ? 'done' : 'open';
        db()->prepare("UPDATE bug_reports SET status = ?, updated_at = datetime('now','localtime') WHERE id = ?")->execute([$status, $id]);
        // „Technik-Engel" geht an die Person, die abhakt – nicht an die meldende. Nur beim
        // Schließen: Wieder-Öffnen darf den Erfolg weder geben noch nehmen. Und nur an die
        // Technik: Diese Seite steht auch dem Vorsitz offen (can_manage_bugs = can_admin), der
        // Erfolg ist aber fürs Reparieren gedacht, nicht fürs Mitlesen.
        if ($status === 'done' && $myId && current_role() === 'admin') achievement_unlock($myId, 'technik_engel');
        bug_notify_reporter($id, $status === 'done'
            ? 'Dein gemeldeter Fehler „' . (string)$bug['title'] . '" wurde als erledigt markiert. ✅'
            : 'Dein gemeldeter Fehler „' . (string)$bug['title'] . '" wurde wieder geöffnet.', $myId);
        flash($status === 'done' ? 'Als erledigt markiert.' : 'Wieder geöffnet.', 'success');
        redirect('bugs.php?id=' . $id);
    }

    if ($action === 'bug_delete') {
        db()->prepare('DELETE FROM bug_reports WHERE id = ?')->execute([$id]); // Kommentare per Cascade
        flash('Ticket gelöscht.', 'success');
        redirect('bugs.php');
    }
    redirect('bugs.php');
}

$fmt = fn($ts) => ($t = strtotime((string)$ts)) ? date('d.m.Y, H:i', $t) . ' Uhr' : '';
$who = fn($name) => trim((string)$name) !== '' ? h(short_name((string)$name)) : 'Technik-Login';

$detailId = (int)($_GET['id'] ?? 0);
$detail = $detailId ? bug_get($detailId) : null;
$filter = in_array(($_GET['filter'] ?? ''), ['open', 'done', 'all'], true) ? (string)$_GET['filter'] : 'open';

page_header('Bug-Reports', true);
?>
<p class="small"><a href="<?= can_admin() ? 'index.php' : '../dashboard.php' ?>">‹ <?= can_admin() ? 'Verwaltung' : 'Dashboard' ?></a></p>

<?php if ($detail): // ===== DETAIL ===== ?>
  <?php $isOpen = ($detail['status'] ?? 'open') !== 'done'; $comments = bug_comments($detailId); ?>
  <div class="events-toolbar">
    <h1><i class="ti ti-bug" style="color:var(--petrol)"></i> Bug-Ticket #<?= (int)$detail['id'] ?></h1>
    <a class="btn secondary" href="bugs.php?filter=<?= h($filter) ?>"><i class="ti ti-arrow-left"></i> Alle Tickets</a>
  </div>

  <div class="card">
    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
      <span class="pill <?= $isOpen ? 'pill-warn' : 'pill-ok' ?>"><i class="ti <?= $isOpen ? 'ti-alert-circle' : 'ti-circle-check' ?>"></i> <?= $isOpen ? 'Offen' : 'Erledigt' ?></span>
      <strong style="font-size:1.1rem"><?= h($detail['title']) ?></strong>
    </div>
    <?php if (trim((string)$detail['body']) !== ''): ?>
      <p style="margin:.6rem 0 0;white-space:pre-wrap;overflow-wrap:anywhere"><?= nl2br(h($detail['body'])) ?></p>
    <?php endif; ?>
    <p class="small muted" style="margin:.7rem 0 0"><i class="ti ti-user"></i> gemeldet von <?= $who($detail['author']) ?> · <?= h($fmt($detail['created_at'])) ?></p>
    <div class="btn-row" style="margin-top:.9rem">
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="bug_status"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
        <input type="hidden" name="status" value="<?= $isOpen ? 'done' : 'open' ?>">
        <button class="btn <?= $isOpen ? '' : 'secondary' ?>" type="submit"><i class="ti <?= $isOpen ? 'ti-circle-check' : 'ti-rotate' ?>"></i> <?= $isOpen ? 'Als erledigt markieren' : 'Wieder öffnen' ?></button>
      </form>
      <form method="post" style="display:inline" data-confirm="Dieses Ticket inkl. aller Kommentare löschen?" data-confirm-danger data-confirm-ok="Löschen">
        <?= csrf_field() ?><input type="hidden" name="action" value="bug_delete"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
        <button class="btn danger small" type="submit"><i class="ti ti-trash"></i> Löschen</button>
      </form>
    </div>
  </div>

  <div class="section-title"><i class="ti ti-messages"></i> Kommentare <span class="count"><?= count($comments) ?></span></div>
  <div class="card">
    <?php if (!$comments): ?>
      <p class="small muted" style="margin:0 0 .8rem">Noch keine Kommentare.</p>
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
      <?= csrf_field() ?><input type="hidden" name="action" value="bug_comment"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
      <label for="cbody">Kommentar hinzufügen</label>
      <textarea name="body" id="cbody" rows="3" placeholder="Antwort, Zwischenstand, Lösung …" required></textarea>
      <div class="btn-row" style="margin-top:.6rem"><button class="btn" type="submit"><i class="ti ti-send"></i> Kommentieren</button></div>
    </form>
  </div>

<?php else: // ===== LISTE ===== ?>
  <div class="events-toolbar">
    <h1><i class="ti ti-bug" style="color:var(--petrol)"></i> Bug-Reports</h1>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-plus"></i> Neues Ticket melden</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bug_add">
      <label for="title">Titel</label>
      <input type="text" name="title" id="title" placeholder="Kurz: was funktioniert nicht?" required>
      <label for="body">Beschreibung (optional)</label>
      <textarea name="body" id="body" rows="4" placeholder="Was passiert, wo, und was hättest du erwartet? Schritte zum Nachstellen helfen."></textarea>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-plus"></i> Ticket anlegen</button></div>
    </form>
  </div>

  <?php
  $tabs = ['open' => 'Offen', 'done' => 'Erledigt', 'all' => 'Alle'];
  $list = bugs_all($filter);
  ?>
  <div class="section-title">
    <i class="ti ti-list-details"></i> Tickets
    <span style="margin-left:.6rem;font-weight:400;font-size:.85rem">
      <?php foreach ($tabs as $k => $lbl): ?>
        <a href="bugs.php?filter=<?= $k ?>" style="text-decoration:none;margin-right:.5rem;<?= $filter === $k ? 'font-weight:700;color:var(--petrol-dark)' : 'color:var(--muted)' ?>"><?= h($lbl) ?></a>
      <?php endforeach; ?>
    </span>
  </div>

  <?php if (!$list): ?>
    <div class="card"><p class="empty"><i class="ti ti-mood-smile"></i> <?= $filter === 'open' ? 'Keine offenen Tickets – alles erledigt.' : 'Keine Tickets in dieser Ansicht.' ?></p></div>
  <?php else: ?>
    <div class="card" style="padding:.4rem .2rem">
      <table class="list">
        <tbody>
        <?php foreach ($list as $b): $open = ($b['status'] ?? 'open') !== 'done'; ?>
          <tr>
            <td style="width:1%;white-space:nowrap">
              <span class="pill <?= $open ? 'pill-warn' : 'pill-ok' ?>" style="font-size:.7rem"><?= $open ? 'offen' : 'erledigt' ?></span>
            </td>
            <td>
              <a href="bugs.php?id=<?= (int)$b['id'] ?>&filter=<?= h($filter) ?>" style="font-weight:600;text-decoration:none;color:var(--ink)">#<?= (int)$b['id'] ?> <?= h($b['title']) ?></a>
              <div class="small muted"><?= $who($b['author']) ?> · <?= h($fmt($b['created_at'])) ?><?php if ((int)$b['n_comments'] > 0): ?> · <i class="ti ti-message"></i> <?= (int)$b['n_comments'] ?><?php endif; ?></div>
            </td>
            <td style="text-align:right;width:1%;white-space:nowrap">
              <a class="btn secondary small" href="bugs.php?id=<?= (int)$b['id'] ?>&filter=<?= h($filter) ?>">Öffnen</a>
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
