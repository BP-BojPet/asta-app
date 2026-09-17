<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_meetings(); // Sekretariat, Admin oder Vorsitz
$cm = current_member();

$meetingId = (int)($_GET['meeting'] ?? $_POST['meeting'] ?? 0);
$meeting = null;
if ($meetingId) {
    $st = db()->prepare('SELECT * FROM meetings WHERE id = ?');
    $st->execute([$meetingId]);
    $meeting = $st->fetch() ?: null;
}
if (!$meeting) $meeting = current_report_meeting();

// --- Berichte speichern (Verwaltung darf alle Referate füllen/ergänzen) ---
if ($meeting && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_reports') {
    check_csrf();
    $contents = (array)($_POST['content'] ?? []);
    foreach (referate_list() as $ref) {
        if (array_key_exists($ref, $contents)) {
            report_save($ref, (int)$meeting['id'], trim((string)$contents[$ref]), (int)($cm['id'] ?? 0));
        }
    }
    flash('Berichte gespeichert.', 'success');
    redirect('reports.php?meeting=' . (int)$meeting['id']);
}

// --- Word-Download ---
if ($meeting && isset($_GET['download'])) {
    if (!class_exists('ZipArchive')) {
        flash('Word-Export nicht möglich: die PHP-Erweiterung „zip" ist auf dem Server nicht aktiv.', 'error');
        redirect('reports.php?meeting=' . (int)$meeting['id']);
    }
    $bin = meeting_reports_docx($meeting)['bin'];
    $fname = 'Berichte_' . preg_replace('/[^A-Za-z0-9]+/', '-', meeting_label($meeting)) . '_' . substr((string)$meeting['starts_at'], 0, 10) . '.docx';
    file_delivery_headers('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $fname, strlen($bin));
    echo $bin;
    exit;
}

$meetingsWithReports = db()->query("SELECT * FROM meetings WHERE needs_report = 1 AND draft = 0 ORDER BY starts_at DESC")->fetchAll();

page_header('Berichte', true);
?>
<p class="small"><a href="meetings.php">‹ Sitzungen</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-file-text" style="color:var(--petrol)"></i> Berichte</h1>
</div>

<?php if (!$meeting): ?>
  <div class="card"><p class="empty"><i class="ti ti-calendar-off"></i> Keine berichtspflichtige Sitzung. In <?= app_place('a_sitzungen') ?> bei einer Sitzung „Bericht benötigt" setzen (Serien-Sitzungen sind es automatisch).</p></div>
<?php else:
    $map = reports_for_meeting((int)$meeting['id']);
    $referate = referate_list();
    $done = 0; foreach ($referate as $ref) if (trim($map[$ref] ?? '') !== '') $done++;
?>
  <?php if (count($meetingsWithReports) > 1): ?>
    <form method="get" action="reports.php" style="margin-bottom:1rem">
      <label for="meeting">Sitzung</label>
      <select name="meeting" id="meeting" onchange="this.form.submit()">
        <?php foreach ($meetingsWithReports as $mm): ?>
          <option value="<?= (int)$mm['id'] ?>" <?= (int)$mm['id'] === (int)$meeting['id'] ? 'selected' : '' ?>><?= h(meeting_label($mm)) ?> · <?= h(fmt_date(substr((string)$mm['starts_at'], 0, 10))) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>

  <div class="events-toolbar">
    <div class="section-title" style="margin:0"><i class="ti ti-clipboard-list"></i> <?= h(meeting_label($meeting)) ?> <span class="count"><?= $done ?>/<?= count($referate) ?></span></div>
    <a class="btn" href="reports.php?meeting=<?= (int)$meeting['id'] ?>&amp;download=1" target="_blank" rel="noopener"><i class="ti ti-download"></i> Als Word herunterladen</a>
  </div>

  <?php if (!$referate): ?>
    <div class="card"><p class="empty"><i class="ti ti-info-circle"></i> Noch keine Referate vergeben – in der <a href="members.php">Stammliste</a> bei Mitgliedern ein Referat eintragen.</p></div>
  <?php else: ?>
    <p class="small muted">Du kannst hier alle Berichte einsehen, ergänzen oder nachtragen (z. B. fürs Protokoll). <strong>Ein Stichpunkt pro Zeile</strong> – kein Strich/Spiegelstrich nötig, das Aufzählungszeichen kommt automatisch.</p>
    <form method="post" action="reports.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_reports">
      <input type="hidden" name="meeting" value="<?= (int)$meeting['id'] ?>">
      <?php $nr = 1; foreach ($referate as $ref): $c = (string)($map[$ref] ?? ''); ?>
        <div class="card" style="margin-bottom:.8rem">
          <div class="section-title" style="margin-top:0"><i class="ti ti-clipboard-text"></i> <?= $nr++ ?>. <?= h($ref) ?> <?php if (trim($c) === ''): ?><span class="pill pill-warn" style="font-size:.7rem">fehlt</span><?php else: ?><span class="pill pill-ok" style="font-size:.7rem">abgegeben</span><?php endif; ?></div>
          <textarea name="content[<?= h($ref) ?>]" rows="4" placeholder="Stichpunkte – ein Punkt pro Zeile"><?= h($c) ?></textarea>
        </div>
      <?php endforeach; ?>
      <div class="btn-row"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Berichte speichern</button></div>
    </form>
  <?php endif; ?>
<?php endif; ?>
<?php
page_footer();
