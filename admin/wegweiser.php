<?php
/**
 * Verwaltung des Wegweisers (Verzeichnis aller Dienste, Zugänge und Ansprechpartner:innen).
 * Gepflegt wie die Wichtigen Informationen: Vorsitz & Admin legen an, alle lesen.
 */
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Admin & Vorsitz

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $cm = current_member();

    if ($action === 'ww_add' || $action === 'ww_save') {
        $id = $action === 'ww_save' ? (int)($_POST['id'] ?? 0) : 0;
        if ($id > 0 && !wegweiser_get($id)) { flash('Eintrag nicht gefunden.', 'error'); redirect('wegweiser.php'); }
        $r = wegweiser_save($id, $_POST, (int)($cm['id'] ?? 0));
        flash($r['msg'], $r['typ']);
        redirect($id > 0 ? 'wegweiser.php?edit=' . $id : 'wegweiser.php');
    }

    if ($action === 'ww_move') { // Reihenfolge innerhalb der Rubrik
        move_wegweiser((int)($_POST['id'] ?? 0), ($_POST['dir'] ?? '') === 'up' ? -1 : 1);
        redirect('wegweiser.php');
    }

    if ($action === 'ww_delete') {
        db()->prepare('DELETE FROM wegweiser WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Eintrag gelöscht.', 'success');
        redirect('wegweiser.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? wegweiser_get($editId) : null;
$gruppen = wegweiser_all();
$anzahl = array_sum(array_map('count', $gruppen));

page_header('Wegweiser', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-directions" style="color:var(--petrol)"></i> Wegweiser</h1>
  <?php /* Bleibt im selben Fenster: die Mitglieder-Ansicht ist Teil der App, und in der
          installierten App würde jeder Klick hier ein eigenes App-Fenster aufmachen. */ ?>
  <a class="btn secondary" href="../wegweiser.php"><i class="ti ti-eye"></i> Mitglieder-Ansicht öffnen</a>
</div>
<div class="flash flash-info">
  Hier steht, <strong>wo was liegt und wer wofür zuständig ist</strong>: unsere eigenen Dienste, die Anlaufstellen an der Uni und die Menschen außerhalb. Die Mitglieder finden das über einen <strong>Klick auf den eigenen Namen</strong> oben rechts unter <strong>„Wegweiser"</strong> – und legen dort auch <strong>eigene Einträge</strong> an, die sie selbst pflegen. Du siehst in der Spalte <strong>„Von"</strong>, von wem ein Eintrag stammt, und darfst alle bearbeiten. Die <strong>Rubrik</strong> bündelt Einträge zu Blöcken – gleiche Schreibweise, gleicher Block. Unter <strong>„Sichtbar für"</strong> hakst du bei Bedarf ein <strong>oder mehrere</strong> Referate an; ohne Häkchen sehen ihn alle.
</div>

<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti <?= $edit ? 'ti-edit' : 'ti-plus' ?>"></i> <?= $edit ? 'Eintrag bearbeiten' : 'Neuer Eintrag' ?></div>
  <?php wegweiser_form($edit, 'wegweiser.php'); ?>
</div>

<div class="section-title"><i class="ti ti-list-details"></i> Vorhandene Einträge <span class="count"><?= (int)$anzahl ?></span></div>
<?php if (!$anzahl): ?>
  <div class="card"><p class="empty"><i class="ti ti-directions"></i> Noch nichts angelegt.</p></div>
<?php else: ?>
  <?php foreach ($gruppen as $label => $eintraege): ?>
    <div class="card" style="padding:.4rem .2rem">
      <div class="section-title" style="margin:.4rem 0 .2rem .6rem;font-size:1rem"><?= h($label) ?> <span class="count"><?= count($eintraege) ?></span></div>
      <table class="list">
        <thead><tr><th>Name</th><th>Adresse</th><th>Sichtbar für</th><th>Von</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($eintraege as $idx => $e):
            $href = wegweiser_href($e);
            $ziel = trim((string)$e['url']) !== '' ? wegweiser_host((string)$e['url']) : str_replace(['mailto:', 'tel:'], '', $href);
        ?>
          <tr>
            <td><a href="wegweiser.php?edit=<?= (int)$e['id'] ?>"><i class="ti <?= h(wegweiser_icon($e)) ?>"></i> <?= h($e['title']) ?></a></td>
            <td class="muted small"><?= h($ziel) ?></td>
            <td><?= wegweiser_referat_pills((string)($e['referat'] ?? '')) ?></td>
            <td class="muted small"><?php $wvon = (int)($e['created_by'] ?? 0); $wm = $wvon ? member_get($wvon) : null;
              echo $wm ? member_link($wm) : '<span class="muted">Verwaltung</span>'; ?></td>
            <td style="text-align:right">
              <div class="btn-row" style="justify-content:flex-end">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="ww_move"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="dir" value="up">
                  <button class="btn secondary small" type="submit" title="Nach oben"<?= $idx === 0 ? ' disabled' : '' ?>><i class="ti ti-chevron-up"></i></button>
                </form>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="ww_move"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="dir" value="down">
                  <button class="btn secondary small" type="submit" title="Nach unten"<?= $idx === count($eintraege) - 1 ? ' disabled' : '' ?>><i class="ti ti-chevron-down"></i></button>
                </form>
                <a class="btn secondary small" href="wegweiser.php?edit=<?= (int)$e['id'] ?>">Bearbeiten</a>
                <form method="post" data-confirm="Diesen Eintrag löschen?" data-confirm-danger data-confirm-ok="Löschen">
                  <?= csrf_field() ?><input type="hidden" name="action" value="ww_delete"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                  <button class="btn danger small" type="submit">Löschen</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php
page_footer();
