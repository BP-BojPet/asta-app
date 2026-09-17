<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Admin & Vorsitz

$templates = mail_templates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $key = (string)($_POST['key'] ?? '');
    if (!isset($templates[$key])) { flash('Unbekannte Vorlage.', 'error'); redirect('mailtexts.php'); }

    if ($action === 'save_mailtext') {
        setting_set('mailtpl_' . $key . '_subject', trim((string)($_POST['subject'] ?? '')));
        setting_set('mailtpl_' . $key . '_body', trim((string)($_POST['body'] ?? '')));
        setting_set('mailtpl_' . $key . '_format', ($_POST['format'] ?? 'plain') === 'html' ? 'html' : 'plain');
        flash('Mailtext „' . $templates[$key]['label'] . '" gespeichert.', 'success');
        redirect('mailtexts.php#' . $key);
    }
    if ($action === 'reset_mailtext') {
        setting_set('mailtpl_' . $key . '_subject', '');
        setting_set('mailtpl_' . $key . '_body', '');
        setting_set('mailtpl_' . $key . '_format', '');
        flash('Mailtext „' . $templates[$key]['label'] . '" auf den Standard zurückgesetzt.', 'success');
        redirect('mailtexts.php#' . $key);
    }
}

page_header('Mailtexte', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-mail-cog" style="color:var(--petrol)"></i> Mailtexte</h1>
</div>
<p class="small muted">Betreff und Text aller automatischen System-Mails. Platzhalter in <code>{{…}}</code> werden beim Versand ersetzt. Felder leer lassen bzw. „Auf Standard zurücksetzen" stellt die Vorlage wieder her. Die <strong>Sitzungseinladung</strong> wird separat unter <a href="meetings.php?t=einladungen">Sitzungen → Einladungen</a> gepflegt.</p>

<?php foreach ($templates as $key => $tpl): ?>
  <div class="section-title" id="<?= h($key) ?>"><i class="ti ti-mail"></i> <?= h($tpl['label']) ?></div>
  <div class="card">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_mailtext">
      <input type="hidden" name="key" value="<?= h($key) ?>">
      <label for="subj_<?= h($key) ?>">Betreff</label>
      <input type="text" id="subj_<?= h($key) ?>" name="subject" value="<?= h(mail_tpl_subject($key)) ?>">
      <label for="body_<?= h($key) ?>" style="margin-top:.5rem">Text</label>
      <textarea id="body_<?= h($key) ?>" name="body" rows="<?= max(5, substr_count(mail_tpl_body($key), "\n") + 2) ?>" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem"><?= h(mail_tpl_body($key)) ?></textarea>
      <label for="fmt_<?= h($key) ?>" style="margin-top:.5rem">Format</label>
      <select name="format" id="fmt_<?= h($key) ?>">
        <option value="plain" <?= mail_tpl_format($key) === 'html' ? '' : 'selected' ?>>Plaintext (reiner Text)</option>
        <option value="html" <?= mail_tpl_format($key) === 'html' ? 'selected' : '' ?>>HTML (Zeilenumbrüche werden übernommen; eigene HTML-Tags möglich)</option>
      </select>
      <p class="small muted" style="margin:.4rem 0 .2rem">Platzhalter:
        <?php foreach ($tpl['vars'] as $ph => $desc): ?><code><?= h($ph) ?></code> <span class="muted">(<?= h($desc) ?>)</span><?= ' ' ?><?php endforeach; ?>
      </p>
      <div class="btn-row" style="margin-top:.6rem">
        <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
      </div>
    </form>
    <form method="post" data-confirm="Diesen Mailtext auf den Standard zurücksetzen? Eigene Änderungen gehen verloren." data-confirm-ok="Zurücksetzen" style="margin-top:.5rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_mailtext">
      <input type="hidden" name="key" value="<?= h($key) ?>">
      <button class="btn secondary small" type="submit"><i class="ti ti-rotate"></i> Auf Standard zurücksetzen</button>
    </form>
  </div>
<?php endforeach; ?>
<?php
page_footer();
