<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Admin & Vorsitz

/** Eine hochgeladene Datei einem Info-Eintrag anhängen. Gibt null oder eine Fehlermeldung zurück. */
function save_info_upload(int $infoId, array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null; // kein File ausgewählt
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload fehlgeschlagen.';
    if ((int)$file['size'] > 50 * 1024 * 1024) return 'Datei zu groß (max. 50 MB).';
    $orig = (string)$file['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','odt','rtf','txt','xls','xlsx','ods','csv','ppt','pptx','odp','png','jpg','jpeg','gif','webp','zip'];
    if (!in_array($ext, $allowed, true)) return 'Dateityp „.' . $ext . '" ist nicht erlaubt.';
    $dir = upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return 'Upload-Ordner konnte nicht angelegt werden.';
    $stored = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return 'Datei konnte nicht gespeichert werden.';
    $mime = function_exists('mime_content_type') ? (mime_content_type($dir . '/' . $stored) ?: '') : '';
    db()->prepare('INSERT INTO info_files(info_id, orig_name, stored_name, mime, size) VALUES(?,?,?,?,?)')
        ->execute([$infoId, $orig, $stored, $mime, (int)$file['size']]);
    return null;
}

/** Mehrere hochgeladene Dateien (docs[]) verarbeiten; sammelt Fehlermeldungen. */
function handle_uploads(int $infoId): array
{
    $errors = [];
    if (empty($_FILES['docs']) || !is_array($_FILES['docs']['name'])) return $errors;
    foreach (array_keys($_FILES['docs']['name']) as $k) {
        $f = [
            'name' => $_FILES['docs']['name'][$k], 'type' => $_FILES['docs']['type'][$k],
            'tmp_name' => $_FILES['docs']['tmp_name'][$k], 'error' => $_FILES['docs']['error'][$k],
            'size' => $_FILES['docs']['size'][$k],
        ];
        $err = save_info_upload($infoId, $f);
        if ($err) $errors[] = $f['name'] . ': ' . $err;
    }
    return $errors;
}

/** Aus der Ablage gewählte Doks an eine Information hängen (Ordnungen, Formulare … liegen dort schon). */
function handle_info_picks(int $infoId, int $byMemberId): array
{
    return ablage_pick_attach('info', function (string $orig, string $stored, string $mime, int $size) use ($infoId): int {
        db()->prepare('INSERT INTO info_files(info_id, orig_name, stored_name, mime, size) VALUES(?,?,?,?,?)')
            ->execute([$infoId, $orig, $stored, $mime, $size]);
        return (int)db()->lastInsertId();
    }, $byMemberId);
}

/** Datei physisch + aus der DB löschen. */
function delete_info_file(int $fileId): void
{
    $st = db()->prepare('SELECT stored_name FROM info_files WHERE id = ?');
    $st->execute([$fileId]);
    if ($sn = $st->fetchColumn()) {
        $p = upload_dir() . '/' . basename((string)$sn);
        if (is_file($p)) @unlink($p);
    }
    db()->prepare('DELETE FROM info_files WHERE id = ?')->execute([$fileId]);
    mirror_forget('info', $fileId); // Verweise auf beide Ablagen aufräumen (das Dok dort bleibt)
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $cm = current_member();

    // Sichtbarkeit: '' = alle, sonst muss es ein existierendes Referat sein
    $refIn = trim((string)($_POST['referat'] ?? ''));
    if ($refIn !== '' && !in_array($refIn, referate_list(), true)) $refIn = '';

    if ($action === 'info_add') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') { flash('Bitte einen Titel angeben.', 'error'); redirect('infos.php'); }
        db()->prepare('INSERT INTO infos(title, body, sort, referat, created_by) VALUES(?,?,?,?,?)')
            ->execute([$title, trim((string)($_POST['body'] ?? '')), (int)($_POST['sort'] ?? 0), $refIn, $cm['id'] ?? null]);
        $newId = (int)db()->lastInsertId();
        $errs = array_merge(handle_uploads($newId), handle_info_picks($newId, (int)($cm['id'] ?? 0)));
        flash('Info angelegt.' . ($errs ? ' Einige Dateien wurden abgelehnt: ' . implode('; ', $errs) : ''), $errs ? 'error' : 'success');
        redirect('infos.php?edit=' . $newId);
    }

    if ($action === 'info_save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        if (!info_get($id)) { flash('Info nicht gefunden.', 'error'); redirect('infos.php'); }
        if ($title === '') { flash('Bitte einen Titel angeben.', 'error'); redirect('infos.php?edit=' . $id); }
        db()->prepare('UPDATE infos SET title=?, body=?, sort=?, referat=? WHERE id=?')
            ->execute([$title, trim((string)($_POST['body'] ?? '')), (int)($_POST['sort'] ?? 0), $refIn, $id]);
        $errs = array_merge(handle_uploads($id), handle_info_picks($id, (int)($cm['id'] ?? 0)));
        flash('Gespeichert.' . ($errs ? ' Abgelehnt: ' . implode('; ', $errs) : ''), $errs ? 'error' : 'success');
        redirect('infos.php?edit=' . $id);
    }

    if ($action === 'info_move') { // Reihenfolge ändern
        move_info((int)($_POST['id'] ?? 0), ($_POST['dir'] ?? '') === 'up' ? -1 : 1);
        redirect('infos.php');
    }

    if ($action === 'info_delete') {
        $id = (int)($_POST['id'] ?? 0);
        foreach (db()->query('SELECT id FROM info_files WHERE info_id = ' . $id)->fetchAll() as $f) delete_info_file((int)$f['id']);
        db()->prepare('DELETE FROM infos WHERE id = ?')->execute([$id]);
        flash('Info gelöscht.', 'success');
        redirect('infos.php');
    }

    if ($action === 'file_delete') {
        $fid = (int)($_POST['file_id'] ?? 0);
        $iid = (int)($_POST['id'] ?? 0);
        if (!empty($_POST['store_too'])) { // Häkchen im Lösch-Dialog: Dok mit in den Papierkorb der Ablage
            $err = null;
            if (!mirror_delete_remote('info', $fid, $err)) {
                flash('Das Dok in der Ablage konnte nicht gelöscht werden' . ($err ? ': ' . $err : '.') . ' Die App-Datei wurde trotzdem entfernt.', 'error');
            }
        }
        delete_info_file($fid);
        flash('Datei gelöscht.', 'success');
        redirect('infos.php?edit=' . $iid);
    }
}

/** Sichtbarkeits-Auswahl (Alle oder ein Referat) rendern. */
function info_referat_select(string $current): void
{
    echo '<select name="referat" id="referat"><option value=""' . ($current === '' ? ' selected' : '') . '>Alle Mitglieder</option>';
    foreach (referate_list() as $r) {
        echo '<option value="' . h($r) . '"' . ($current === $r ? ' selected' : '') . '>nur Referat ' . h($r) . '</option>';
    }
    echo '</select>';
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? info_get($editId) : null;
$editFiles = $edit ? db()->query('SELECT * FROM info_files WHERE info_id = ' . (int)$edit['id'] . ' ORDER BY orig_name COLLATE NOCASE')->fetchAll() : [];
$list = infos_all();

page_header('Wichtige Informationen', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-pin" style="color:var(--petrol)"></i> Wichtige Informationen</h1>
  <?php /* Bleibt im selben Fenster (wie im Wegweiser): die Mitglieder-Ansicht gehört zur App,
          und in der installierten App würde jeder Klick ein eigenes App-Fenster aufmachen. */ ?>
  <a class="btn secondary" href="../info.php"><i class="ti ti-eye"></i> Mitglieder-Ansicht öffnen</a>
</div>
<div class="flash flash-info">
  Was du hier anlegst, erscheint auf der Seite <strong><?= app_place('infos', 'Wichtige Infos & Anleitung') ?></strong> – erreichbar über einen <strong>Klick auf den eigenen Namen</strong> oben rechts. Pro Eintrag gibt es Titel, Text und beliebig viele Dokumente zum Herunterladen. Über <strong>„Sichtbar für"</strong> steuerst du, ob ein Eintrag <strong>allen Mitgliedern</strong> oder <strong>nur einem bestimmten Referat</strong> angezeigt wird.
</div>
<?php $srvLimit = php_upload_limit_bytes(); if ($srvLimit > 0): ?>
  <p class="small <?= $srvLimit < 50 * 1024 * 1024 ? 'warn-line' : 'muted' ?>" style="margin:-.3rem 0 .9rem">
    <i class="ti ti-server-cog"></i> Aktuelles <strong>Server-Limit pro Datei: <?= h(human_filesize($srvLimit)) ?></strong>.
    <?php if ($srvLimit < 50 * 1024 * 1024): ?>
      Größere Dateien lehnt der Server ab – zum Anheben in den <strong>PHP-Einstellungen</strong> (Mittwald-mStudio) <code>upload_max_filesize</code> und <code>post_max_size</code> auf ≥ 50 MB setzen.
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php if ($edit): ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-edit"></i> Info bearbeiten</div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="info_save">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <label for="title">Titel</label>
      <input type="text" name="title" id="title" value="<?= h($edit['title']) ?>" required>
      <label for="body">Text (optional, einfache Formatierung möglich)</label>
      <textarea name="body" id="body" rows="6"><?= h($edit['body']) ?></textarea>
      <div class="field-row">
        <div style="max-width:140px"><label for="sort">Reihenfolge</label><input type="number" name="sort" id="sort" value="<?= (int)$edit['sort'] ?>"></div>
        <div><label for="referat">Sichtbar für</label><?php info_referat_select((string)($edit['referat'] ?? '')); ?></div>
      </div>
      <label for="docs2" style="margin-top:.6rem">Dokumente hinzufügen</label>
      <input type="file" name="docs[]" id="docs2" multiple>
      <p class="small muted" style="margin:.2rem 0 0">Erlaubt: PDF, Word/Excel/PowerPoint, OpenDocument, Bilder, ZIP, TXT/CSV – max. 50 MB pro Datei.</p>
      <?= ablage_pick_field('info', '../') ?>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button> <a class="btn secondary" href="infos.php">Fertig</a></div>
    </form>

    <?php if ($editFiles): ?>
      <div class="section-title" style="font-size:1rem"><i class="ti ti-paperclip"></i> Angehängte Dokumente</div>
      <table class="list">
        <tbody>
        <?php foreach ($editFiles as $f): ?>
          <tr>
            <td><a href="../download.php?file=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><i class="ti ti-file-download"></i> <?= h($f['orig_name']) ?></a> <?= mirror_open_button('info', (int)$f['id'], '../') ?></td>
            <td class="muted small"><?= h(human_filesize((int)$f['size'])) ?></td>
            <td style="text-align:right">
              <form method="post" data-confirm="Diese Datei löschen?" data-confirm-danger data-confirm-ok="Löschen"<?= mirror_delete_check_attr('info', (int)$f['id']) ?>>
                <?= csrf_field() ?><input type="hidden" name="action" value="file_delete"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                <button class="btn danger small" type="submit">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-plus"></i> Neue Information</div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="info_add">
      <label for="ntitle">Titel</label>
      <input type="text" name="title" id="ntitle" placeholder="z. B. How-to AStA, Geschäftsordnung, Onboarding …" required>
      <label for="nbody">Text (optional)</label>
      <textarea name="body" id="nbody" rows="5" placeholder="Kurze Beschreibung …"></textarea>
      <label for="referat" style="margin-top:.6rem">Sichtbar für</label>
      <?php info_referat_select(''); ?>
      <label for="docs" style="margin-top:.6rem">Dokumente (optional, mehrere möglich)</label>
      <input type="file" name="docs[]" id="docs" multiple>
      <p class="small muted" style="margin:.2rem 0 0">Erlaubt: PDF, Word/Excel/PowerPoint, OpenDocument, Bilder, ZIP, TXT/CSV – max. 50 MB pro Datei.</p>
      <?= ablage_pick_field('info', '../') ?>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-plus"></i> Anlegen</button></div>
    </form>
  </div>
<?php endif; ?>

<div class="section-title"><i class="ti ti-list-details"></i> Vorhandene Informationen <span class="count"><?= count($list) ?></span></div>
<?php if (!$list): ?>
  <div class="card"><p class="empty"><i class="ti ti-info-circle"></i> Noch nichts angelegt.</p></div>
<?php else: ?>
  <div class="card" style="padding:.4rem .2rem">
    <table class="list">
      <thead><tr><th>Titel</th><th>Sichtbar für</th><th>Dateien</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $idx => $i): $iRef = trim((string)($i['referat'] ?? '')); ?>
        <tr>
          <td><a href="infos.php?edit=<?= (int)$i['id'] ?>"><?= h($i['title']) ?></a></td>
          <td><?= $iRef === '' ? '<span class="muted small">Alle</span>' : '<span class="pill pill-info" style="font-size:.72rem">' . h($iRef) . '</span>' ?></td>
          <td class="muted small"><?= count($i['files']) ?></td>
          <td style="text-align:right">
            <div class="btn-row" style="justify-content:flex-end">
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="info_move"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="dir" value="up">
                <button class="btn secondary small" type="submit" title="Nach oben"<?= $idx === 0 ? ' disabled' : '' ?>><i class="ti ti-chevron-up"></i></button>
              </form>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="info_move"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="dir" value="down">
                <button class="btn secondary small" type="submit" title="Nach unten"<?= $idx === count($list) - 1 ? ' disabled' : '' ?>><i class="ti ti-chevron-down"></i></button>
              </form>
              <a class="btn secondary small" href="infos.php?edit=<?= (int)$i['id'] ?>">Bearbeiten</a>
              <form method="post" data-confirm="Diese Information inkl. aller Dokumente löschen?" data-confirm-danger data-confirm-ok="Löschen">
                <?= csrf_field() ?><input type="hidden" name="action" value="info_delete"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                <button class="btn danger small" type="submit">Löschen</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php
page_footer();
