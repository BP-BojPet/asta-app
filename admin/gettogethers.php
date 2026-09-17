<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_login();
if (!can_manage_gettogethers()) {
    flash('Get-Togethers dürfen nur Sekretariat, Vorsitz und Admin verwalten.', 'error');
    redirect('../events.php');
}

$normDt = fn(string $v): ?string => ($v = trim($v)) === '' ? null : str_replace('T', ' ', $v);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $me = current_member();

    if ($action === 'create' || $action === 'update') {
        $title = trim((string)($_POST['title'] ?? ''));
        $start = $normDt((string)($_POST['starts_at'] ?? ''));
        $endRaw = $normDt((string)($_POST['ends_at'] ?? ''));
        $end = ($endRaw && $start && $endRaw > $start) ? $endRaw : null;
        $desc = trim((string)($_POST['description'] ?? ''));
        $loc  = trim((string)($_POST['location'] ?? ''));
        if ($title === '' || !$start) {
            flash('Bitte mindestens Titel und Start (Datum/Uhrzeit) angeben.', 'error');
            redirect('gettogethers.php');
        }
        if ($action === 'create') {
            db()->prepare('INSERT INTO gettogethers(title, description, starts_at, ends_at, location, created_by) VALUES(?,?,?,?,?,?)')
                ->execute([$title, $desc, $start, $end, $loc, $me ? (int)$me['id'] : null]);
            // Alle einmal Bescheid geben – Zu-/Absagen bleibt freiwillig (siehe gettogether_notify_new)
            $nGt = gettogether_notify_new((int)db()->lastInsertId(), $me ? (int)$me['id'] : null);
            flash('Get-Together angelegt – es ist jetzt im Kalender und auf den Dashboards sichtbar. '
                . $nGt . ' Mitteilung(en) verschickt, mit der Bitte um kurze Zu- oder Absage.', 'success');
        } else {
            $gid = (int)($_POST['id'] ?? 0);
            if (gettogether_get($gid)) {
                db()->prepare('UPDATE gettogethers SET title=?, description=?, starts_at=?, ends_at=?, location=? WHERE id=?')
                    ->execute([$title, $desc, $start, $end, $loc, $gid]);
                flash('Get-Together aktualisiert.', 'success');
            }
        }
        redirect('gettogethers.php');
    }

    if ($action === 'delete') {
        $gid = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM gettogethers WHERE id = ?')->execute([$gid]); // RSVPs per Cascade
        flash('Get-Together gelöscht.', 'success');
        redirect('gettogethers.php');
    }
}

$all = gettogethers_all();
$today = date('Y-m-d');

page_header('AStA Get-Togethers', true);
?>
<p class="small"><a href="../events.php">‹ Events</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-confetti" style="color:var(--petrol)"></i> AStA Get-Togethers</h1>
</div>
<p class="muted small" style="margin-top:-.3rem">Interne, <strong>unverbindliche</strong> Spaß-Treffen. Sie erscheinen im Kalender und bitten die Mitglieder im Dashboard nur um eine <strong>Ja/Nein-Rückmeldung</strong>. Wer „Nein" sagt, bei dem verschwindet das Treffen aus dem persönlichen Kalender.</p>

<div class="card">
  <h2 style="margin-top:0"><i class="ti ti-plus"></i> Neues Get-Together</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label for="gt_title">Titel</label>
    <input type="text" name="title" id="gt_title" placeholder="z. B. Spieleabend, Kneipentour, Grillen" required>
    <div class="field-row">
      <div><label>Start (Datum &amp; Uhrzeit)</label><input type="text" class="fp-datetime" name="starts_at" placeholder="Datum &amp; Uhrzeit wählen" required></div>
      <div><label>Ende (optional)</label><input type="text" class="fp-datetime" name="ends_at" placeholder="optional"></div>
    </div>
    <label for="gt_loc">Ort (optional)</label>
    <input type="text" name="location" id="gt_loc" placeholder="z. B. AStA-Büro / Lokal">
    <label for="gt_desc">Beschreibung (optional)</label>
    <textarea name="description" id="gt_desc" placeholder="Worum geht's? Was mitbringen?"></textarea>
    <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Anlegen</button></div>
  </form>
</div>

<div class="section-title"><i class="ti ti-calendar-event"></i> Angelegte Get-Togethers <span class="count"><?= count($all) ?></span></div>
<?php if (!$all): ?>
  <div class="card"><p class="empty"><i class="ti ti-mood-smile"></i> Noch keine Get-Togethers. Leg oben das erste an!</p></div>
<?php endif; ?>
<?php foreach ($all as $g): $gid = (int)$g['id']; $c = gettogether_rsvp_counts($gid);
    $past = substr((string)($g['ends_at'] ?: $g['starts_at']), 0, 10) < $today; ?>
  <div class="card<?= $past ? '' : '' ?>">
    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
      <strong style="font-size:1.05rem"><i class="ti ti-confetti" style="color:var(--petrol)"></i> <?= h($g['title']) ?></strong>
      <?php if ($past): ?><span class="pill">vorbei</span><?php endif; ?>
      <span class="pill pill-ok" style="margin-left:auto"><i class="ti ti-check"></i> <?= $c['yes'] ?> dabei</span>
      <span class="pill"><i class="ti ti-x"></i> <?= $c['no'] ?> nicht</span>
    </div>
    <div class="event-facts" style="margin-top:.4rem">
      <span class="fact"><i class="ti ti-calendar-event"></i> <?= h(fmt_slot($g['starts_at'], $g['ends_at'])) ?></span>
      <?php if (trim((string)$g['location']) !== ''): ?><span class="fact"><i class="ti ti-map-pin"></i> <?= h($g['location']) ?></span><?php endif; ?>
    </div>
    <?php if (trim((string)$g['description']) !== ''): ?><p class="small" style="margin:.4rem 0 0"><?= nl2br(h($g['description'])) ?></p><?php endif; ?>
    <div class="btn-row" style="margin-top:.7rem">
      <a class="btn small secondary" href="../gettogether.php?id=<?= $gid ?>"><i class="ti ti-eye"></i> Ansehen &amp; Bedarf</a>
      <?= share_button('gettogether.php?id=' . $gid, 'Get-Together-Link teilen', true) ?>
      <button class="btn small secondary" type="button" onclick="document.getElementById('gtedit-<?= $gid ?>').hidden = !document.getElementById('gtedit-<?= $gid ?>').hidden"><i class="ti ti-edit"></i> Bearbeiten</button>
      <form method="post" data-confirm="Dieses Get-Together wirklich löschen? Alle Rückmeldungen gehen verloren." data-confirm-danger data-confirm-title="Get-Together löschen" data-confirm-ok="Löschen">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $gid ?>">
        <button class="btn small danger" type="submit"><i class="ti ti-trash"></i> Löschen</button>
      </form>
    </div>
    <div id="gtedit-<?= $gid ?>" hidden style="margin-top:.8rem;border-top:1px solid var(--line);padding-top:.8rem">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $gid ?>">
        <label>Titel</label>
        <input type="text" name="title" value="<?= h($g['title']) ?>" required>
        <div class="field-row">
          <div><label>Start (Datum &amp; Uhrzeit)</label><input type="text" class="fp-datetime" name="starts_at" value="<?= h(substr((string)$g['starts_at'], 0, 16)) ?>" required></div>
          <div><label>Ende (optional)</label><input type="text" class="fp-datetime" name="ends_at" value="<?= h($g['ends_at'] ? substr((string)$g['ends_at'], 0, 16) : '') ?>" placeholder="optional"></div>
        </div>
        <label>Ort (optional)</label>
        <input type="text" name="location" value="<?= h($g['location']) ?>">
        <label>Beschreibung (optional)</label>
        <textarea name="description"><?= h($g['description']) ?></textarea>
        <div class="btn-row" style="margin-top:.7rem"><button class="btn small" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button></div>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<?php page_footer(); ?>
