<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Vorsitz & Admin

$defs = docx_templates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $key = (string)($_POST['key'] ?? '');
    if (!isset($defs[$key])) { flash('Unbekannte Vorlage.', 'error'); redirect('vorlagen.php'); }

    if ($action === 'upload_template') {
        $file = $_FILES['template'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('Bitte eine .docx-Datei auswählen.', 'error');
        } elseif (strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'docx') {
            flash('Bitte eine Word-Datei (.docx) hochladen.', 'error');
        } elseif (save_uploaded_docx_template($key, $file)) {
            flash('Vorlage „' . $defs[$key]['label'] . '" aktualisiert.', 'success');
        } else {
            flash('Upload fehlgeschlagen – ist es eine gültige .docx-Datei?', 'error');
        }
        redirect('vorlagen.php');
    }
    if ($action === 'reset_template') {
        reset_docx_template($key);
        flash('Vorlage „' . $defs[$key]['label'] . '" auf den Standard zurückgesetzt.', 'success');
        redirect('vorlagen.php');
    }
}

// Aktuelle Vorlage herunterladen
if (isset($_GET['download'])) {
    $key = (string)$_GET['download'];
    $path = isset($defs[$key]) ? docx_template_path($key) : null;
    if (!$path) { flash('Für diese Vorlage ist keine Datei hinterlegt.', 'error'); redirect('vorlagen.php'); }
    file_delivery_headers('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $key . '-vorlage.docx', (int)filesize($path));
    readfile($path);
    exit;
}

page_header('Word-Vorlagen', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-file-type-docx" style="color:var(--petrol)"></i> Word-Vorlagen</h1>
</div>
<p class="muted">Hier kannst du die Word-Vorlagen austauschen, aus denen Protokoll und Berichte erzeugt werden – ohne Code. Lade einfach eine angepasste <code>.docx</code> mit denselben Platzhaltern hoch. Die Dateien liegen geschützt unter <code>data/templates/</code>.</p>

<?php foreach ($defs as $key => $d):
    $custom = docx_template_is_custom($key);
    $hasAny = docx_template_path($key) !== null;
?>
  <div class="card" style="margin-bottom:1rem">
    <div class="section-title" style="margin-top:0"><i class="ti ti-file-text"></i> <?= h($d['label']) ?>
      <?php if ($custom): ?><span class="pill pill-ok" style="font-size:.7rem">eigene Vorlage</span>
      <?php elseif ($hasAny): ?><span class="pill pill-info" style="font-size:.7rem">mitgelieferter Standard</span>
      <?php else: ?><span class="pill pill-warn" style="font-size:.7rem">keine Vorlage – Code-Fallback</span><?php endif; ?>
    </div>
    <p class="small muted" style="margin-top:0"><?= h($d['desc']) ?></p>

    <p class="small" style="margin:.2rem 0 .2rem"><strong>Platzhalter</strong> (am Stück tippen, sonst greift die Ersetzung evtl. nicht):</p>
    <ul class="small muted" style="margin:0 0 .6rem">
      <?php foreach ($d['placeholders'] as $ph => $desc): ?>
        <li><code><?= h($ph) ?></code> – <?= h($desc) ?></li>
      <?php endforeach; ?>
    </ul>

    <div class="events-toolbar" style="gap:.6rem;flex-wrap:wrap">
      <form method="post" enctype="multipart/form-data" class="events-toolbar" style="gap:.6rem;flex-wrap:wrap;margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_template">
        <input type="hidden" name="key" value="<?= h($key) ?>">
        <input type="file" name="template" accept=".docx" required>
        <button class="btn" type="submit"><i class="ti ti-upload"></i> Vorlage hochladen</button>
      </form>
      <?php if ($hasAny): ?><a class="btn secondary" href="vorlagen.php?download=<?= h($key) ?>" target="_blank" rel="noopener"><i class="ti ti-download"></i> Aktuelle herunterladen</a><?php endif; ?>
      <?php if ($custom): ?>
        <form method="post" data-confirm="Eigene Vorlage entfernen und wieder den Standard verwenden?" data-confirm-ok="Zurücksetzen" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_template">
          <input type="hidden" name="key" value="<?= h($key) ?>">
          <button class="btn secondary" type="submit"><i class="ti ti-rotate"></i> Auf Standard zurücksetzen</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php
page_footer();
