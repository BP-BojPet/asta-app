<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$canManage = can_manage_meetings();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM meetings WHERE id = ?');
$st->execute([$id]);
$m = $st->fetch();
if (!$m || !can_see_draft($m)) {
    http_response_code(404);
    page_header('Nicht gefunden');
    echo '<p>Sitzung nicht gefunden.</p>';
    page_footer();
    exit;
}

// StuPa-Sitzungen sind reine Kalender-/Info-Termine: keine Redeliste, keine Berichte,
// keine Rückmeldung, keine Einladung, kein Protokoll. Schlanke Detailseite und fertig.
if (($m['kind'] ?? '') === 'stupa') {
    $legName = (string)(legislature_for((string)$m['starts_at'])['name'] ?? '');
    page_header(meeting_label($m));
    ?>
    <p class="small"><a href="index.php">‹ Kalender</a></p>
    <div class="card event-hero">
      <div class="hero-head">
        <h1 class="event-title">
          <i class="ti ti-building-bank" style="color:var(--petrol)"></i> <?= h(meeting_label($m)) ?>
          <?php if (!empty($m['draft'])): ?><span class="badge badge-draft">Entwurf</span><?php endif; ?>
          <?php if (!empty($m['cancelled'])): ?><span class="badge badge-closed">ausgefallen</span><?php endif; ?>
          <span class="badge badge-event">StuPa</span>
        </h1>
        <?= share_button('meeting.php?id=' . (int)$m['id'], 'Sitzungs-Link teilen') ?>
      </div>
      <div class="event-facts">
        <span class="fact"><i class="ti ti-calendar-event"></i> <?= h(fmt_slot($m['starts_at'], meeting_ends_at($m))) ?></span>
        <?php if ($m['location']): ?><span class="fact"><i class="ti ti-map-pin"></i> Raum <?= h($m['location']) ?></span><?php endif; ?>
        <?php if (trim((string)($m['teams_link'] ?? '')) !== ''): ?><a class="fact" href="<?= h($m['teams_link']) ?>" target="_blank" rel="noopener"><i class="ti ti-brand-teams"></i> Online beitreten (Teams)</a><?php endif; ?>
        <?php if ($legName !== ''): ?><span class="fact"><i class="ti ti-list-numbers"></i> <?= h($legName) ?></span><?php endif; ?>
      </div>
      <?php if (trim((string)$m['description']) !== ''): ?><p class="event-desc"><?= h($m['description']) ?></p><?php endif; ?>
      <div class="event-byline">
        <span class="byline-meta small muted"><i class="ti ti-info-circle"></i> Sitzung des Studierendenparlaments – nur zur Info im Kalender. Keine Rückmeldung, kein Bericht nötig.</span>
        <?php if ($canManage): ?><a class="btn small secondary" href="admin/meetings.php?edit=<?= $id ?>#bearbeiten"><i class="ti ti-edit"></i> Sitzung bearbeiten</a><?php endif; ?>
      </div>
    </div>
    <?php
    page_footer();
    exit;
}

/** Eine hochgeladene Datei einem Abstimmungsgegenstand anhängen. Gibt null oder eine Fehlermeldung zurück. */
function save_voteitem_upload(int $itemId, array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null; // kein File gewählt
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload fehlgeschlagen.';
    if ((int)$file['size'] > 50 * 1024 * 1024) return 'Datei zu groß (max. 50 MB).';
    $orig = (string)$file['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, attach_ext_allowed(), true)) return 'Dateityp „.' . $ext . '" ist nicht erlaubt.';
    $dir = upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return 'Upload-Ordner konnte nicht angelegt werden.';
    $stored = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return 'Datei konnte nicht gespeichert werden.';
    $mime = function_exists('mime_content_type') ? (mime_content_type($dir . '/' . $stored) ?: '') : '';
    db()->prepare('INSERT INTO vote_item_files(item_id, orig_name, stored_name, mime, size) VALUES(?,?,?,?,?)')
        ->execute([$itemId, $orig, $stored, $mime, (int)$file['size']]);
    return null;
}

/** Aus der Ablage gewählte Dateien an einen Abstimmungsgegenstand hängen; sammelt Fehlermeldungen. */
function handle_voteitem_picks(int $itemId, int $byMemberId): array
{
    return ablage_pick_attach('vfile', function (string $orig, string $stored, string $mime, int $size) use ($itemId): int {
        db()->prepare('INSERT INTO vote_item_files(item_id, orig_name, stored_name, mime, size) VALUES(?,?,?,?,?)')
            ->execute([$itemId, $orig, $stored, $mime, $size]);
        return (int)db()->lastInsertId();
    }, $byMemberId);
}

/** Mehrere hochgeladene Dateien (docs[]) eines Abstimmungsgegenstands verarbeiten; sammelt Fehlermeldungen. */
function handle_voteitem_uploads(int $itemId): array
{
    $errors = [];
    if (empty($_FILES['docs']) || !is_array($_FILES['docs']['name'])) return $errors;
    foreach (array_keys($_FILES['docs']['name']) as $k) {
        $f = [
            'name' => $_FILES['docs']['name'][$k], 'type' => $_FILES['docs']['type'][$k],
            'tmp_name' => $_FILES['docs']['tmp_name'][$k], 'error' => $_FILES['docs']['error'][$k],
            'size' => $_FILES['docs']['size'][$k],
        ];
        $err = save_voteitem_upload($itemId, $f);
        if ($err) $errors[] = $f['name'] . ': ' . $err;
    }
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    // Sitzungs-Rückmeldung: abmelden / nur online / doch in Präsenz (bis 18:00 am Sitzungstag)
    if ($action === 'meeting_rsvp') {
        if (!$me) { flash('Mit dem Technik-Login geht das nicht – melde dich als Mitglied an.', 'error'); redirect('meeting.php?id=' . $id); }
        $r = meeting_rsvp_set($m, (int)$me['id'], (string)($_POST['status'] ?? ''), (string)($_POST['reason'] ?? ''));
        flash($r['msg'], $r['ok'] ? 'success' : 'error');
        redirect('meeting.php?id=' . $id . '#rueckmeldung');
    }

    // TOP einreichen – alle Mitglieder
    if ($action === 'submit_top') {
        if (!$me) { flash('Mit dem Technik-Login kannst du keinen TOP einreichen.', 'error'); redirect('meeting.php?id=' . $id); }
        if (!top_submission_open($m)) { flash('Die Einreichefrist ist abgelaufen – TOPs konnten nur bis ' . fmt_date(top_deadline_date($m)) . ' eingereicht werden (dann wird eingeladen).', 'error'); redirect('meeting.php?id=' . $id); }
        $title = trim((string)($_POST['title'] ?? ''));
        $internal = (string)($_POST['internal'] ?? '0') === '1' ? 1 : 0;
        $anchor = ($_POST['anchor'] ?? '') === '' ? null : (int)$_POST['anchor'];
        $timeEst = trim((string)($_POST['time_est'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        if ($title === '') { flash('Bitte einen Titel für den TOP angeben.', 'error'); redirect('meeting.php?id=' . $id); }
        if ($internal === 0 && ($timeEst === '' || $desc === '')) {
            flash('Öffentliche TOPs brauchen eine ungefähre Zeitangabe und eine kurze Beschreibung.', 'error');
            redirect('meeting.php?id=' . $id);
        }
        db()->prepare('INSERT INTO top_submissions(meeting_id, member_id, title, internal, anchor, time_est, description) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, (int)$me['id'], $title, $internal, $anchor, $timeEst, $desc]);
        flash('TOP eingereicht – das Sekretariat ordnet ihn ein.', 'success');
        redirect('meeting.php?id=' . $id);
    }

    if ($action === 'sub_delete') {
        $sid = (int)($_POST['sub_id'] ?? 0);
        $own = db()->prepare('SELECT member_id FROM top_submissions WHERE id=? AND meeting_id=?');
        $own->execute([$sid, $id]); $owner = $own->fetchColumn();
        if ($owner !== false && ($canManage || ($me && (int)$owner === (int)$me['id']))) {
            db()->prepare('DELETE FROM top_submissions WHERE id=?')->execute([$sid]);
            flash('TOP entfernt.', 'success');
        } else { flash('Das darfst du nicht.', 'error'); }
        redirect('meeting.php?id=' . $id);
    }

    // Abstimmungsgegenstand als gelesen markieren – alle Mitglieder
    if ($action === 'vote_read') {
        if (!$me) { flash('Mit dem Technik-Login kannst du nichts als gelesen markieren.', 'error'); redirect('meeting.php?id=' . $id . '#abstimmung'); }
        $vid = (int)($_POST['vote_id'] ?? 0);
        $chk = db()->prepare('SELECT 1 FROM vote_items WHERE id = ? AND meeting_id = ?'); $chk->execute([$vid, $id]);
        if ($chk->fetchColumn()) vote_item_mark_read($vid, (int)$me['id']);
        redirect('meeting.php?id=' . $id . '#abstimmung');
    }
    // Alle Abstimmungsgegenstände dieser Sitzung als gelesen markieren – alle Mitglieder
    if ($action === 'vote_read_all') {
        if (!$me) { flash('Mit dem Technik-Login kannst du nichts als gelesen markieren.', 'error'); redirect('meeting.php?id=' . $id . '#abstimmung'); }
        foreach (vote_items_for($id) as $vi) vote_item_mark_read((int)$vi['id'], (int)$me['id']);
        flash('Alle Abstimmungsgegenstände als gelesen markiert.', 'success');
        redirect('meeting.php?id=' . $id . '#abstimmung');
    }

    // Protokoll einreichen dürfen AUCH eingeteilte Protokollant:innen (meist normale
    // Mitglieder) – deshalb stehen diese zwei Aktionen VOR der Verwaltungs-Schranke
    // unten. Ihre eigene Prüfung (Protokollant:in oder Verwaltung) steht in der Aktion.
    if ($action === 'protocol_upload') { // fertiges Protokoll (.docx) direkt nach Teams laden – NICHT in der App speichern
        $intern  = (string)($_POST['kind'] ?? 'public') === 'intern';
        $isTaker = $me && (int)($m['protocol_taker_id'] ?? 0) === (int)$me['id'];
        if (!$isTaker && !$canManage) { flash('Nur die:der eingeteilte Protokollant:in (oder Sekretariat/Vorsitz) darf das Protokoll hochladen.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        $driveId = (string)setting_get('graph_drive_id', '');
        if ($driveId === '') { flash('Teams-Ablage ist noch nicht eingerichtet (Verwaltung → Uploads und Automationen).', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Keine Datei empfangen (oder zu groß).', 'error'); redirect('meeting.php?id=' . $id . '#protokoll');
        }
        $ext = strtolower(pathinfo((string)($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'docx') { flash('Bitte die fertige Protokoll-Datei als .docx hochladen.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        $folder = $intern ? protocol_teams_intern_target_folder($m) : protocol_teams_target_folder($m); // Basisordner + ggf. /JAHR
        $fname  = protocol_docx_filename($m, $intern);
        $err = null;
        $item = graph_upload_file($driveId, $folder, $fname, (string)$_FILES['file']['tmp_name'], $err);
        if ($item) {
            if ($intern) {
                protocol_after_intern_upload($m, $item, $me ? (int)$me['id'] : 0);
                flash('Internes Protokoll in die Teams-Ablage hochgeladen (bleibt nur in Teams, wird nicht veröffentlicht).', 'success');
            } else {
                protocol_after_upload($m, $item, $me ? (int)$me['id'] : 0);
                flash('Öffentliches Protokoll hochgeladen. Ein Abstimmungsgegenstand für die nächste Sitzung wird angelegt, sobald eine geplant ist.', 'success');
            }
        } else {
            flash('Upload nach Teams fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
        }
        redirect('meeting.php?id=' . $id . '#protokoll');
    }
    if ($action === 'protocol_take_from_teams') { // fertige Fassung direkt aus dem Teams-Entwurf übernehmen
        $intern  = (string)($_POST['kind'] ?? 'public') === 'intern';
        $isTaker = $me && (int)($m['protocol_taker_id'] ?? 0) === (int)$me['id'];
        if (!$isTaker && !$canManage) { flash('Nur die:der eingeteilte Protokollant:in (oder Sekretariat/Vorsitz) darf das Protokoll übernehmen.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        $driveId = (string)setting_get('graph_drive_id', '');
        if ($driveId === '') { flash('Teams-Ablage ist noch nicht eingerichtet (Verwaltung → Uploads und Automationen).', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        $err = null;
        $bytes = teams_file_current($intern ? 'protokoll_intern' : 'protokoll', $id, $err);
        if ($bytes === null || $bytes === '') {
            flash('Der Teams-Entwurf konnte nicht geladen werden' . ($err ? ': ' . $err : '.'), 'error');
            redirect('meeting.php?id=' . $id . '#protokoll');
        }
        $folder = $intern ? protocol_teams_intern_target_folder($m) : protocol_teams_target_folder($m);
        $fname  = protocol_docx_filename($m, $intern);
        $item = graph_upload_bytes($driveId, $folder, $fname, $bytes, $err);
        if ($item) {
            if ($intern) {
                protocol_after_intern_upload($m, $item, $me ? (int)$me['id'] : 0);
                flash('Interner Teams-Entwurf als fertiges Protokoll übernommen (bleibt nur in Teams).', 'success');
            } else {
                protocol_after_upload($m, $item, $me ? (int)$me['id'] : 0);
                flash('Teams-Entwurf als fertiges Protokoll übernommen. Ein Abstimmungsgegenstand für die nächste Sitzung wird angelegt, sobald eine geplant ist.', 'success');
            }
        } else {
            flash('Übernahme nach Teams fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
        }
        redirect('meeting.php?id=' . $id . '#protokoll');
    }

    // ab hier nur Sekretariat/Admin/Vorsitz
    if (!$canManage) { flash('Dafür fehlt dir die Berechtigung.', 'error'); redirect('meeting.php?id=' . $id); }

    // --- Abstimmungsgegenstände verwalten (Sekretariat/Vorsitz/Admin) ---
    if ($action === 'vote_add') {
        $title = trim((string)($_POST['vote_title'] ?? ''));
        $body  = trim((string)($_POST['vote_body'] ?? ''));
        if ($title === '') { flash('Bitte einen Titel für den Abstimmungsgegenstand angeben.', 'error'); redirect('meeting.php?id=' . $id . '#abstimmung'); }
        $sort = (int)db()->query('SELECT COALESCE(MAX(sort), -1) + 1 FROM vote_items WHERE meeting_id = ' . (int)$id)->fetchColumn();
        $st = db()->prepare('INSERT INTO vote_items(meeting_id, title, body, sort, created_by) VALUES(?,?,?,?,?)');
        $st->execute([$id, $title, $body, $sort, (int)($me['id'] ?? 0)]);
        $newId = (int)db()->lastInsertId();
        if ($me) vote_item_mark_read($newId, (int)$me['id']); // Ersteller:in gilt als gelesen
        $errs = array_merge(handle_voteitem_uploads($newId), handle_voteitem_picks($newId, (int)($me['id'] ?? 0)));
        flash($errs ? 'Angelegt, aber: ' . implode(' · ', $errs) : 'Abstimmungsgegenstand hinzugefügt.', $errs ? 'error' : 'success');
        redirect('meeting.php?id=' . $id . '#abstimmung');
    }
    if ($action === 'vote_edit') {
        $vid = (int)($_POST['vote_id'] ?? 0);
        $vi = vote_item_get($vid);
        if ($vi && (int)$vi['meeting_id'] === $id) {
            $title = trim((string)($_POST['vote_title'] ?? ''));
            $body  = trim((string)($_POST['vote_body'] ?? ''));
            if ($title === '') { flash('Bitte einen Titel angeben.', 'error'); redirect('meeting.php?id=' . $id . '#abstimmung'); }
            db()->prepare('UPDATE vote_items SET title = ?, body = ? WHERE id = ?')->execute([$title, $body, $vid]);
            $errs = array_merge(handle_voteitem_uploads($vid), handle_voteitem_picks($vid, (int)($me['id'] ?? 0)));
            flash($errs ? 'Gespeichert, aber: ' . implode(' · ', $errs) : 'Abstimmungsgegenstand gespeichert.', $errs ? 'error' : 'success');
        }
        redirect('meeting.php?id=' . $id . '#abstimmung');
    }
    if ($action === 'vote_delete') {
        $vid = (int)($_POST['vote_id'] ?? 0);
        $vi = vote_item_get($vid);
        if ($vi && (int)$vi['meeting_id'] === $id) {
            protocol_detach_vote_item($vid); // Protokoll-Verknüpfung der Quell-Sitzung lösen (falls es ein Protokoll-Gegenstand ist)
            foreach (db()->query('SELECT id FROM vote_item_files WHERE item_id = ' . $vid)->fetchAll() as $f) vote_item_file_delete((int)$f['id']);
            db()->prepare('DELETE FROM vote_items WHERE id = ?')->execute([$vid]); // reads cascaden per FK
            flash('Abstimmungsgegenstand gelöscht.', 'success');
        }
        redirect('meeting.php?id=' . $id . '#abstimmung');
    }
    if ($action === 'vote_decision') { // Beschluss festhalten (Sitzungsleitung/Sekretariat)
        if (!$canManage) { flash('Nur Sekretariat/Vorsitz dürfen Beschlüsse festhalten.', 'error'); redirect('meeting.php?id=' . $id . '#abstimmung'); }
        $vid = (int)($_POST['vote_id'] ?? 0);
        $vi = vote_item_get($vid);
        if ($vi && (int)$vi['meeting_id'] === $id && $me) {
            vote_item_set_decision($vid, (string)($_POST['decision'] ?? 'offen'), (int)$me['id']);
            flash('Beschluss festgehalten.', 'success');
        }
        redirect('meeting.php?id=' . $id . '#abstimmung');
    }
    if ($action === 'vote_file_delete') {
        $fid = (int)($_POST['file_id'] ?? 0);
        $chk = db()->prepare('SELECT 1 FROM vote_item_files vf JOIN vote_items v ON v.id = vf.item_id WHERE vf.id = ? AND v.meeting_id = ?');
        $chk->execute([$fid, $id]);
        if ($chk->fetchColumn()) {
            if (!empty($_POST['store_too'])) { // Häkchen im Lösch-Dialog: Dok mit in den Papierkorb der Ablage
                $err = null;
                if (!mirror_delete_remote('vfile', $fid, $err)) {
                    flash('Das Dok in der Ablage konnte nicht gelöscht werden' . ($err ? ': ' . $err : '.') . ' Die App-Datei wurde trotzdem entfernt.', 'error');
                }
            }
            vote_item_file_delete($fid);
            flash('Datei gelöscht.', 'success');
        }
        redirect('meeting.php?id=' . $id . '#abstimmung');
    }
    if ($action === 'vote_move') {
        $vid = (int)($_POST['vote_id'] ?? 0);
        $vi = vote_item_get($vid);
        if ($vi && (int)$vi['meeting_id'] === $id) move_vote_item($vid, ((string)($_POST['dir'] ?? '')) === 'up' ? -1 : 1);
        redirect('meeting.php?id=' . $id . '#abstimmung');
    }

    // --- Protokoll-Workflow ---
    if ($action === 'protocol_set_taker') { // Protokollant:in wählen (Sitzungsleitung/Sekretariat)
        if (!$canManage) { flash('Nur Sekretariat/Vorsitz dürfen die:den Protokollant:in wählen.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        $pid = (int)($_POST['taker_id'] ?? 0);
        protocol_set_taker($id, $pid ?: null);
        flash($pid ? 'Protokollant:in gesetzt.' : 'Protokollant:in entfernt.', 'success');
        redirect('meeting.php?id=' . $id . '#protokoll');
    }
    if ($action === 'protocol_vote_again') { // zuvor gelöschte Protokoll-Abstimmung wieder anlegen
        if (!$canManage) { flash('Nur Sekretariat/Vorsitz dürfen die Abstimmung wieder anlegen.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        flash(protocol_vote_reactivate($id)
            ? 'Die Abstimmung steht wieder in der nächsten Sitzung.'
            : 'Vorgemerkt – sobald eine nächste Sitzung geplant ist, taucht die Abstimmung dort auf.', 'success');
        redirect('meeting.php?id=' . $id . '#protokoll');
    }
    if ($action === 'protocol_relink') { // Teams-Verknüpfung reparieren: vorhandene Datei als Protokoll verknüpfen
        if (!$canManage) { flash('Nur Sekretariat/Vorsitz dürfen die Verknüpfung ändern.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        $intern = (string)($_POST['kind'] ?? 'public') === 'intern';
        $store = (string)(((array)($_POST['picked_store'] ?? []))[0] ?? '');
        $ref   = (string)(((array)($_POST['picked_ref'] ?? []))[0] ?? '');
        if ($store === '' || $ref === '') {
            flash('Bitte zuerst „Aus der Ablage wählen" und eine Datei anklicken.', 'error');
        } elseif ($store !== 'teams') {
            flash('Das fertige Protokoll muss in Teams liegen – bitte eine Datei aus der Teams-Ablage wählen.', 'error');
        } else {
            $err = null;
            $d = ablage_fetch('teams', $ref, $err, 31457280);
            if ($d === null) {
                flash('Datei konnte nicht geladen werden' . ($err ? ': ' . $err : '.'), 'error');
            } elseif (strtolower(pathinfo((string)$d['name'], PATHINFO_EXTENSION)) !== 'docx') {
                flash('Bitte eine .docx-Datei wählen – nur daraus kann die App das OLAT-PDF bauen.', 'error');
            } else {
                $item = ['id' => $d['item_id'], 'webUrl' => $d['web_url'], 'name' => $d['name'],
                         'parentReference' => ['driveId' => $d['drive_id']]];
                $leer = trim((string)($intern ? $m['protocol_intern_item_id'] : $m['protocol_item_id'])) === ''
                     && (!$intern ? (string)$m['protocol_status'] === '' : true);
                if ($leer) {
                    // Noch nichts hinterlegt → wie ein frischer Upload (startet beim öffentlichen die Kette)
                    $intern ? protocol_after_intern_upload($m, $item, $me ? (int)$me['id'] : 0)
                            : protocol_after_upload($m, $item, $me ? (int)$me['id'] : 0);
                } elseif ($intern) {
                    db()->prepare('UPDATE meetings SET protocol_intern_drive_id = ?, protocol_intern_item_id = ?,
                                       protocol_intern_web_url = ?, protocol_intern_filename = ?, protocol_intern_teams_url = NULL WHERE id = ?')
                       ->execute([$d['drive_id'], $d['item_id'], $d['web_url'], $d['name'], $id]);
                } else {
                    // Nur den Verweis tauschen – Status, Beschluss und Abstimmung bleiben unangetastet
                    db()->prepare('UPDATE meetings SET protocol_drive_id = ?, protocol_item_id = ?,
                                       protocol_web_url = ?, protocol_filename = ?, protocol_teams_url = NULL WHERE id = ?')
                       ->execute([$d['drive_id'], $d['item_id'], $d['web_url'], $d['name'], $id]);
                }
                flash('Teams-Verknüpfung aktualisiert – die App zeigt und veröffentlicht jetzt „' . $d['name'] . '".', 'success');
            }
        }
        redirect('meeting.php?id=' . $id . '#protokoll');
    }
    if ($action === 'protocol_reset') { // Protokoll-Eintrag zurücksetzen (Vertipper/falsche Datei/zu früh festgelegt)
        if (!$canManage) { flash('Nur Sekretariat/Vorsitz dürfen den Protokoll-Eintrag zurücksetzen.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        $intern = (string)($_POST['kind'] ?? 'public') === 'intern';
        if ($intern) {
            db()->prepare('UPDATE meetings SET protocol_intern_drive_id = NULL, protocol_intern_item_id = NULL,
                               protocol_intern_web_url = NULL, protocol_intern_filename = NULL, protocol_intern_teams_url = NULL,
                               protocol_intern_uploaded_at = NULL, protocol_intern_uploaded_by = NULL WHERE id = ?')->execute([$id]);
            flash('Eintrag zum internen Protokoll zurückgesetzt – die Datei in Teams bleibt unangetastet.', 'success');
        } else {
            // Nur OFFENE Genehmigungen einsammeln – gefasste Beschlüsse bleiben als Beleg stehen.
            db()->prepare("DELETE FROM vote_items WHERE kind = 'protocol' AND ref_meeting_id = ? AND decision = 'offen'")->execute([$id]);
            db()->prepare("UPDATE meetings SET protocol_status = '', protocol_drive_id = NULL, protocol_item_id = NULL,
                               protocol_web_url = NULL, protocol_filename = NULL, protocol_teams_url = NULL,
                               protocol_uploaded_at = NULL, protocol_uploaded_by = NULL,
                               protocol_vote_off = 0, protocol_vote_after = NULL,
                               protocol_olat_path = NULL, protocol_published_at = NULL, protocol_published_by = NULL
                           WHERE id = ?")->execute([$id]);
            flash('Protokoll-Eintrag zurückgesetzt – Status, Verknüpfung und eine offene Abstimmung sind entfernt. Dateien in Teams und OLAT bleiben unangetastet.', 'success');
        }
        redirect('meeting.php?id=' . $id . '#protokoll');
    }
    if ($action === 'protocol_mark_approved') { // längst gefassten Beschluss nachtragen (Altbestand)
        if (!$canManage) { flash('Nur Sekretariat/Vorsitz dürfen einen Beschluss nachtragen.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        if ((string)($m['protocol_status'] ?? '') !== 'uploaded') {
            flash('Nachtragen geht nur bei einem hochgeladenen Protokoll, das noch auf seine Annahme wartet.', 'error');
        } elseif (($pv = protocol_vote_item($id)) && (string)$pv['decision'] === 'offen') {
            flash('Es steht schon eine Abstimmung an – bitte den Beschluss dort festhalten.', 'error');
        } else {
            // vote_off verhindert, dass der nächste Abgleich doch noch eine Abstimmung anlegt.
            db()->prepare("UPDATE meetings SET protocol_status = 'approved', protocol_vote_off = 1 WHERE id = ?")->execute([$id]);
            flash('Beschluss nachgetragen – das Protokoll gilt als angenommen und kann nach OLAT veröffentlicht werden.', 'success');
        }
        redirect('meeting.php?id=' . $id . '#protokoll');
    }
    if ($action === 'protocol_publish') { // angenommenes Protokoll als PDF nach OLAT veröffentlichen (Sekretariat)
        if (!$canManage) { flash('Nur Sekretariat/Vorsitz dürfen das Protokoll veröffentlichen.', 'error'); redirect('meeting.php?id=' . $id . '#protokoll'); }
        $err = null;
        if (protocol_publish_to_olat($id, $me ? (int)$me['id'] : 0, $err)) {
            flash('Protokoll als PDF nach OLAT veröffentlicht.', 'success');
        } else {
            flash('Veröffentlichung fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
        }
        redirect('meeting.php?id=' . $id . '#protokoll');
    }

    if ($action === 'save_agenda') {
        db()->prepare('UPDATE meetings SET agenda = ? WHERE id = ?')->execute([trim((string)($_POST['agenda'] ?? '')), $id]);
        flash('Tagesordnung gespeichert.', 'success');
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'reset_agenda') {
        db()->prepare('UPDATE meetings SET agenda = NULL WHERE id = ?')->execute([$id]);
        flash('Tagesordnung auf die Standard-TO zurückgesetzt.', 'success');
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'sub_move') {
        db()->prepare('UPDATE top_submissions SET anchor=? WHERE id=? AND meeting_id=?')
            ->execute([(int)($_POST['anchor'] ?? 0), (int)($_POST['sub_id'] ?? 0), $id]);
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'sub_internal') { // umschalten + Position zurücksetzen (sonst falscher Abschnitt)
        db()->prepare('UPDATE top_submissions SET internal = 1 - internal, anchor = NULL WHERE id=? AND meeting_id=?')
            ->execute([(int)($_POST['sub_id'] ?? 0), $id]);
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'toggle_norl_sub') { // eingereichten TOP in/aus der Redeliste schalten
        db()->prepare('UPDATE top_submissions SET no_redeliste = 1 - no_redeliste WHERE id=? AND meeting_id=?')
            ->execute([(int)($_POST['sub_id'] ?? 0), $id]);
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'toggle_norl_base') { // Basis-TOP in/aus der Redeliste schalten (Schlüssel = Text)
        $key = base_top_key((string)($_POST['top'] ?? ''));
        if ($key !== '') {
            $set = meeting_skip_set($m);
            if (isset($set[$key])) { unset($set[$key]); }
            else {
                $set[$key] = true;
                $rep = meeting_report_set($m); unset($rep[$key]); // nicht beides zugleich
                db()->prepare('UPDATE meetings SET redeliste_report = ? WHERE id = ?')->execute([json_encode(array_keys($rep), JSON_UNESCAPED_UNICODE), $id]);
            }
            db()->prepare('UPDATE meetings SET redeliste_skip = ? WHERE id = ?')
                ->execute([json_encode(array_keys($set), JSON_UNESCAPED_UNICODE), $id]);
        }
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'toggle_report_base') { // Basis-TOP dieser Sitzung als Berichte-TOP schalten
        $key = base_top_key((string)($_POST['top'] ?? ''));
        if ($key !== '') {
            $set = meeting_report_set($m);
            if (isset($set[$key])) { unset($set[$key]); }
            else {
                $set[$key] = true;
                $skip = meeting_skip_set($m); unset($skip[$key]); // Berichte-TOP ist ein Rede-TOP
                db()->prepare('UPDATE meetings SET redeliste_skip = ? WHERE id = ?')->execute([json_encode(array_keys($skip), JSON_UNESCAPED_UNICODE), $id]);
            }
            db()->prepare('UPDATE meetings SET redeliste_report = ? WHERE id = ?')
                ->execute([json_encode(array_keys($set), JSON_UNESCAPED_UNICODE), $id]);
        }
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'seed_redeliste') { // TO + Stammliste in den Raum schreiben und Leitung (mit Identität) öffnen
        redeliste_seed($m);
        redirect(leader_join_url(meeting_redeliste($m), $me));
    }
    if ($action === 'reset_redeliste') { // Raum löschen -> gilt wieder als „nicht gestartet" (nicht „beendet")
        redeliste_reset($m);
        flash('Redeliste zurückgesetzt – die Sitzung gilt wieder als „nicht gestartet".', 'success');
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'invite_save') { // Sekki: Teams-Link, Reminder-Wunsch, Freigabe; optional sofort senden
        $teams = trim((string)($_POST['teams_link'] ?? ''));
        $rem = isset($_POST['invite_reminder']) ? 1 : 0;
        $approve = isset($_POST['approve']) ? 1 : (int)($m['invite_approved'] ?? 0);
        db()->prepare('UPDATE meetings SET teams_link=?, invite_reminder=?, invite_approved=? WHERE id=?')
            ->execute([$teams, $rem, $approve, $id]);
        if (isset($_POST['send_now'])) {
            $to = invite_to_addr();
            $mm = db()->prepare('SELECT * FROM meetings WHERE id=?'); $mm->execute([$id]); $mm = $mm->fetch();
            if ($to === '') flash('Keine Verteiler-Adresse hinterlegt (Verwaltung → Sitzungen → Einladungen).', 'error');
            elseif (trim((string)$mm['teams_link']) === '') flash('Bitte zuerst den Teams-Link eintragen.', 'error');
            elseif (send_mail($to, invite_mail_subject($mm), invite_is_html() ? invite_mail_html($mm) : invite_mail_text($mm), invite_is_html(), invite_from_addr())) {
                db()->prepare('UPDATE meetings SET invite_sent_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $id]);
                flash('Einladung verschickt an ' . $to . '.', 'success');
            } else flash('Versand fehlgeschlagen.', 'error');
        } else {
            flash($approve ? 'Einladung freigegeben – Versand am Stichtag.' : 'Einladungs-Angaben gespeichert.', 'success');
        }
        redirect('meeting.php?id=' . $id);
    }
    if ($action === 'invite_set_sent') { // Text-Modus: Einladung von Hand als versendet markieren (oder zurücknehmen)
        $sent = (int)($_POST['sent'] ?? 0) === 1;
        db()->prepare('UPDATE meetings SET invite_sent_at=? WHERE id=?')
            ->execute([$sent ? date('Y-m-d H:i:s') : null, $id]);
        flash($sent ? 'Einladung als versendet markiert.' : 'Markierung „versendet" zurückgenommen.', 'success');
        redirect('meeting.php?id=' . $id);
    }
}

// Vorschau der Einladungsmail (nur Verwaltung) in neuem Tab – im konfigurierten Format
if ($canManage && isset($_GET['invite_preview'])) {
    if (invite_is_html()) { header('Content-Type: text/html; charset=utf-8'); echo invite_mail_html($m); }
    else { header('Content-Type: text/plain; charset=utf-8'); echo invite_mail_text($m); }
    exit;
}

// Protokollvorlage (.docx) herunterladen – jederzeit verfügbar.
// Liegt schon ein Teams-Entwurf zur Sitzung, ist DER führend (aktuelle Bearbeitung),
// sonst wird die Vorlage frisch aus den Live-Daten erzeugt.
if (isset($_GET['protokoll'])) {
    $bin = mirror_store('protokoll', $id) !== '' ? mirror_current('protokoll', $id) : null;
    if ($bin === null || $bin === '') {
        if (!class_exists('ZipArchive')) { flash('Word-Export nicht möglich (ZipArchive fehlt auf dem Server).', 'error'); redirect('meeting.php?id=' . $id); }
        $bin = build_protokoll_docx($m);
    }
    $fn = protokoll_filename($m);
    file_delivery_headers('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $fn, strlen($bin));
    echo $bin;
    exit;
}

// Internes Protokoll (.docx) – für alle angemeldeten Mitglieder verfügbar (nicht-öffentlicher Teil)
if (isset($_GET['protokoll_intern'])) {
    $bin = mirror_store('protokoll_intern', $id) !== '' ? mirror_current('protokoll_intern', $id) : null;
    if ($bin === null || $bin === '') {
        if (!class_exists('ZipArchive')) { flash('Word-Export nicht möglich (ZipArchive fehlt auf dem Server).', 'error'); redirect('meeting.php?id=' . $id); }
        $bin = build_protokoll_intern_docx($m);
    }
    $fn = protokoll_intern_filename($m);
    file_delivery_headers('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $fn, strlen($bin));
    echo $bin;
    exit;
}

$legName = (string)(legislature_for((string)$m['starts_at'])['name'] ?? '');
$agendaText = meeting_agenda($m);
$base = agenda_tree($agendaText);
$subs = submissions_for($id);
$usesOwn = trim((string)($m['agenda'] ?? '')) !== '';
$live = current_live_meeting();
$isLive = $live && (int)$live['id'] === (int)$m['id'];
$rede = meeting_redeliste($m);
$redeLive = redeliste_is_active($m); // läuft schon eine Liste? -> Neustart warnt
$topOpen = top_submission_open($m);  // dürfen noch TOPs eingereicht werden?
$skipSet = meeting_skip_set($m);     // sitzungseigen ausgenommene Basis-TOPs
$tplSkip = (trim((string)($m['agenda'] ?? '')) === '') ? agenda_template_skip_set() : []; // aus der Standard-TO geerbt
$reportSet = meeting_report_set($m); // sitzungseigene Berichte-TOPs
$tplReport = (trim((string)($m['agenda'] ?? '')) === '') ? agenda_template_report_set() : []; // Berichte-TOPs aus der Standard-TO geerbt

// Abstimmungsgegenstände + Lese-Status des aktuellen Mitglieds
$voteItems = vote_items_for($id);
$voteReadSet = $me ? vote_item_read_set($id, (int)$me['id']) : [];
$voteUnread = 0;
foreach ($voteItems as $vi) if (!isset($voteReadSet[(int)$vi['id']])) $voteUnread++;

$n = count($base);
$sonst = null; $firstInt = null;
foreach ($base as $i => $it) {
    if ($firstInt === null && !empty($it['internal'])) $firstInt = $i;
    if ($sonst === null && empty($it['internal']) && mb_strtolower(trim($it['text'])) === 'sonstiges') $sonst = $i;
}
$intStart = $firstInt !== null ? $firstInt : $n;     // eine Sitzung hat immer einen internen Teil (ggf. leer)
$byAnchor = [];
foreach ($subs as $s) {
    $a = ($s['anchor'] === null || $s['anchor'] === '') ? null : (int)$s['anchor'];
    if ($a === null) $a = ((int)$s['internal'] === 1) ? $n : ($sonst !== null ? $sonst : $intStart);
    $a = max(0, min($n, $a));
    $byAnchor[$a][] = $s;
}

page_header(meeting_label($m));

$maj = 0; $min = 0;
// Schalter „nicht in die Redeliste" (durchgestrichenes Redelisten-Icon) – nur Leitung/Sekretariat
$rlToggle = function (string $action, string $field, $value, bool $on) {
    echo '<form method="post"><input type="hidden" name="action" value="' . h($action) . '">';
    echo '<input type="hidden" name="' . h($field) . '" value="' . h((string)$value) . '">' . csrf_field();
    echo '<button class="ag-btn' . ($on ? ' rl-on' : '') . '" title="'
        . ($on ? 'Wird NICHT in die Redeliste aufgenommen – klicken, um ihn wieder aufzunehmen'
               : 'Diesen TOP NICHT in die Redeliste aufnehmen')
        . '"><span class="rl-strike"><i class="ti ti-list-numbers"></i></span></button></form>';
};
$renderBaseItem = function (int $i) use (&$maj, &$min, $base, $canManage, $skipSet, $tplSkip, $reportSet, $tplReport, $rlToggle) {
    $it = $base[$i];
    if ($it['sub']) { $min++; $no = $maj . '.' . $min; $cls = 'ag-sub'; }
    else { $maj++; $min = 0; $no = 'TOP ' . $maj; $cls = 'ag-main'; }
    if (!empty($it['internal'])) $cls .= ' ag-internal';
    $key = base_top_key($it['text']);
    $repTpl = isset($tplReport[$key]);       // Berichte-TOP aus der Standard-TO geerbt
    $repMeeting = isset($reportSet[$key]);   // Berichte-TOP für diese Sitzung gesetzt
    $report = $repTpl || $repMeeting;
    $inTpl = !$report && isset($tplSkip[$key]);  // aus der Standard-TO geerbt (dort ändern)
    $inMeeting = !$report && isset($skipSet[$key]); // für diese Sitzung gesetzt
    $skip = $inTpl || $inMeeting;            // Berichte-TOP ist ein Rede-TOP → niemals „keine Redeliste"
    if ($skip) $cls .= ' ag-norl';
    if ($report) $cls .= ' ag-report';
    echo '<li class="' . $cls . '"><span class="ag-no">' . $no . '</span> <span class="ag-text">' . h($it['text']);
    if (trim((string)($it['time'] ?? '')) !== '') echo ' <span class="ag-time"><i class="ti ti-clock"></i> ' . h($it['time']) . '</span>';
    if ($skip) echo ' <span class="ag-norl-tag" title="Steht in der TO, ist aber kein Rede-TOP"><i class="ti ti-microphone-off"></i> keine Redeliste' . ($inTpl ? ' (Standard-TO)' : '') . '</span>';
    if ($report) echo ' <span class="ag-report-tag" title="In der Redeliste werden hieraus eigene Bericht-Untertops"><i class="ti ti-speakerphone"></i> Berichte-TOP' . ($repTpl ? ' (Standard-TO)' : '') . '</span>';
    echo '</span>';
    if ($canManage) {
        echo '<span class="ag-subctl">';
        // Berichte-TOP-Schalter
        if ($repTpl) {
            echo '<button type="button" class="ag-btn rep-on" disabled title="In der Standard-TO als Berichte-TOP gesetzt – dort ändern"><i class="ti ti-speakerphone"></i></button>';
        } else {
            echo '<form method="post"><input type="hidden" name="action" value="toggle_report_base"><input type="hidden" name="top" value="' . h($it['text']) . '">' . csrf_field();
            echo '<button class="ag-btn' . ($repMeeting ? ' rep-on' : '') . '" title="' . ($repMeeting ? 'Berichte-TOP – klicken zum Aufheben' : 'Als Berichte-TOP markieren (eigene Bericht-Untertops in der Redeliste)') . '"><i class="ti ti-speakerphone"></i></button></form>';
        }
        // „keine Redeliste"-Schalter
        if ($inTpl) {
            echo '<button type="button" class="ag-btn rl-on" disabled title="In der Standard-TO ausgenommen – dort unter „Standard-Tagesordnung“ ändern"><span class="rl-strike"><i class="ti ti-list-numbers"></i></span></button>';
        } else {
            $rlToggle('toggle_norl_base', 'top', $it['text'], $inMeeting);
        }
        echo '</span>';
    }
    echo '</li>';
};
$renderGap = function (int $anchor, string $section) use ($me, $topOpen) {
    if (!$me || !$topOpen) return;
    echo '<li class="ag-gap" data-section="' . $section . '"><button type="button" class="ag-gap-btn" data-anchor="' . $anchor . '"><i class="ti ti-plus"></i> Hier einfügen</button></li>';
};
$renderSub = function (array $s, int $anchorIdx) use (&$maj, &$min, $canManage, $me, $n, $rlToggle) {
    $internal = (int)$s['internal'] === 1;
    $skip = (int)($s['no_redeliste'] ?? 0) === 1;
    $maj++; $min = 0;
    $cls = 'ag-main ag-submitted' . ($internal ? ' ag-internal' : '') . ($skip ? ' ag-norl' : '');
    echo '<li class="' . $cls . '"><span class="ag-no">TOP ' . $maj . '</span>';
    echo '<span class="ag-text">' . h($s['title']);
    $te = trim((string)($s['time_est'] ?? ''));
    if ($te !== '') echo ' <span class="ag-time"><i class="ti ti-clock"></i> ' . h(is_numeric($te) ? $te . ' Min' : $te) . '</span>';
    if (trim((string)$s['member_name']) !== '') echo ' <span class="ag-by"><i class="ti ti-user"></i> ' . h($s['member_name']) . '</span>';
    if ($skip) echo ' <span class="ag-norl-tag" title="Steht in der TO, ist aber kein Rede-TOP"><i class="ti ti-microphone-off"></i> keine Redeliste</span>';
    if (trim((string)($s['description'] ?? '')) !== '') echo '<span class="ag-desc">' . h($s['description']) . '</span>';
    echo '</span><span class="ag-subctl">';
    if ($canManage) {
        $rlToggle('toggle_norl_sub', 'sub_id', (int)$s['id'], $skip);
        echo '<form method="post"><input type="hidden" name="action" value="sub_move"><input type="hidden" name="sub_id" value="' . (int)$s['id'] . '"><input type="hidden" name="anchor" value="' . max(0, $anchorIdx - 1) . '">' . csrf_field() . '<button class="ag-btn" title="Nach oben"><i class="ti ti-chevron-up"></i></button></form>';
        echo '<form method="post"><input type="hidden" name="action" value="sub_move"><input type="hidden" name="sub_id" value="' . (int)$s['id'] . '"><input type="hidden" name="anchor" value="' . min($n, $anchorIdx + 1) . '">' . csrf_field() . '<button class="ag-btn" title="Nach unten"><i class="ti ti-chevron-down"></i></button></form>';
        echo '<form method="post"><input type="hidden" name="action" value="sub_internal"><input type="hidden" name="sub_id" value="' . (int)$s['id'] . '">' . csrf_field() . '<button class="ag-btn" title="Öffentlich/intern umschalten"><i class="ti ti-' . ($internal ? 'lock-open' : 'lock') . '"></i></button></form>';
    }
    if ($canManage || ($me && (int)$s['member_id'] === (int)$me['id'])) {
        echo '<form method="post" data-confirm="Diesen eingereichten TOP entfernen?" data-confirm-danger data-confirm-ok="Entfernen"><input type="hidden" name="action" value="sub_delete"><input type="hidden" name="sub_id" value="' . (int)$s['id'] . '">' . csrf_field() . '<button class="ag-btn ag-del" title="Entfernen"><i class="ti ti-trash"></i></button></form>';
    }
    echo '</span></li>';
};
?>
<p class="small"><a href="index.php">‹ Kalender</a></p>

<div class="card event-hero">
  <div class="hero-head">
    <h1 class="event-title">
      <?= h(meeting_label($m)) ?>
      <?php if (!empty($m['draft'])): ?><span class="badge badge-draft">Entwurf</span><?php endif; ?>
      <?php if (!empty($m['cancelled'])): ?><span class="badge badge-closed">ausgefallen</span><?php endif; ?>
      <?php if (($m['kind'] ?? '') === 'ausserordentlich'): ?><span class="badge badge-event">außerordentlich</span><?php endif; ?>
    </h1>
    <?= share_button('meeting.php?id=' . (int)$m['id'], 'Sitzungs-Link teilen') ?>
  </div>
  <div class="event-facts">
    <span class="fact"><i class="ti ti-calendar-event"></i> <?= h(fmt_slot($m['starts_at'], meeting_ends_at($m))) ?></span>
    <?php if ($m['location']): ?><span class="fact"><i class="ti ti-map-pin"></i> Raum <?= h($m['location']) ?></span><?php endif; ?>
    <?php if (trim((string)($m['teams_link'] ?? '')) !== ''): ?><a class="fact" href="<?= h($m['teams_link']) ?>" target="_blank" rel="noopener"><i class="ti ti-brand-teams"></i> Online beitreten (Teams)</a><?php endif; ?>
    <?php if ($legName !== ''): ?><span class="fact"><i class="ti ti-list-numbers"></i> <?= h($legName) ?></span><?php endif; ?>
    <?php if (!empty($m['needs_report'])): ?><span class="fact fact-accent"><i class="ti ti-file-text"></i> Bericht nötig</span><?php endif; ?>
  </div>
  <?php if (trim((string)$m['description']) !== ''): ?><p class="event-desc"><?= h($m['description']) ?></p><?php endif; ?>
  <div class="event-byline">
    <span class="byline-meta">
      <?php if (!empty($m['needs_report'])): ?><a href="report.php"><i class="ti ti-file-text"></i> Bericht eintragen</a><?php endif; ?>
    </span>
    <?php if ($canManage): ?><a class="btn small secondary" href="admin/meetings.php?edit=<?= $id ?>#bearbeiten"><i class="ti ti-edit"></i> Sitzung bearbeiten</a><?php endif; ?>
  </div>
</div>

<?php if ($isLive): ?>
  <div class="flash flash-success"><i class="ti ti-broadcast"></i> Diese Sitzung läuft gerade.</div>
<?php endif; ?>

<?php if (empty($m['cancelled'])):
    $rsvpLists = meeting_rsvp_members($id); // id + name je Person → Namen verlinken aufs Nutzerprofil
    $myRsvp = $me ? meeting_rsvp_status($id, (int)$me['id']) : null;
    $rsvpOpen = meeting_rsvp_open($m);
    if ($me || $rsvpLists['abgemeldet'] || $rsvpLists['online']): ?>
  <div class="card" id="rueckmeldung">
    <div class="section-title" style="margin-top:0;font-size:1.05rem"><i class="ti ti-door-exit"></i> Deine Teilnahme</div>
    <?php if ($me): ?>
      <p class="small" style="margin:.2rem 0 .6rem">
        Aktuell: <strong><?= $myRsvp === 'abgemeldet' ? 'abgemeldet (entschuldigt)' : ($myRsvp === 'online' ? 'online dabei' : 'in Präsenz dabei') ?></strong>
        <?php if ($rsvpOpen): ?><span class="muted">· änderbar bis 18:00 Uhr am Sitzungstag</span><?php else: ?><span class="muted">· die Frist (18:00 Uhr am Sitzungstag) ist vorbei</span><?php endif; ?>
      </p>
      <?php if ($rsvpOpen): ?>
        <form id="rsvpOffForm" method="post" hidden><?= csrf_field() ?><input type="hidden" name="action" value="meeting_rsvp"><input type="hidden" name="status" value="abgemeldet"><input type="hidden" name="reason" id="rsvpOffReason" value=""></form>
        <form id="rsvpOnForm" method="post" hidden><?= csrf_field() ?><input type="hidden" name="action" value="meeting_rsvp"><input type="hidden" name="status" value="online"><input type="hidden" name="reason" id="rsvpOnReason" value=""></form>
        <div class="btn-row">
          <?php if ($myRsvp !== 'abgemeldet'): ?>
            <button class="btn secondary small" type="button" onclick="astaConfirm({title:'Von der Sitzung abmelden',icon:'ti-door-exit',message:'Du wirst im Protokoll als entschuldigt abwesend geführt.',input:{label:'Warum kannst du nicht teilnehmen?',placeholder:'z. B. Klausur, krank, Arbeit …'},buttons:[{label:'Abbrechen',class:'secondary'},{label:'Abmelden',class:'',requireInput:true,onClick:function(v){document.getElementById('rsvpOffReason').value=v||'';document.getElementById('rsvpOffForm').submit();}}]});"><i class="ti ti-door-exit"></i> Abmelden – ich komme nicht</button>
          <?php endif; ?>
          <?php if ($myRsvp !== 'online'): ?>
            <button class="btn secondary small" type="button" onclick="astaConfirm({title:'Online teilnehmen',icon:'ti-device-laptop',message:'Du wirst als online teilnehmend vermerkt.',input:{label:'Warum ist Präsenz nicht möglich?',placeholder:'z. B. unterwegs, krank …'},buttons:[{label:'Abbrechen',class:'secondary'},{label:'Online teilnehmen',class:'',requireInput:true,onClick:function(v){document.getElementById('rsvpOnReason').value=v||'';document.getElementById('rsvpOnForm').submit();}}]});"><i class="ti ti-device-laptop"></i> Ich nehme online teil</button>
          <?php endif; ?>
          <?php if ($myRsvp !== null): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="meeting_rsvp"><input type="hidden" name="status" value="none">
              <button class="btn secondary small" type="submit" data-puste="zusage"><i class="ti ti-arrow-back-up"></i> Doch in Präsenz dabei</button>
            </form>
          <?php endif; ?>
        </div>
        <p class="small muted" style="margin:.5rem 0 0"><i class="ti ti-lock"></i> Der angegebene Grund wird <strong>nur an den Vorsitz</strong> weitergeleitet.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($rsvpLists['abgemeldet'] || $rsvpLists['online']): ?>
      <p class="small muted" style="margin:.6rem 0 0">
        <?php if ($rsvpLists['abgemeldet']): ?><i class="ti ti-door-exit"></i> Abgemeldet: <?= implode(', ', array_map('member_link', $rsvpLists['abgemeldet'])) ?><?php endif; ?>
        <?php if ($rsvpLists['abgemeldet'] && $rsvpLists['online']): ?> · <?php endif; ?>
        <?php if ($rsvpLists['online']): ?><i class="ti ti-device-laptop"></i> Online dabei: <?= implode(', ', array_map('member_link', $rsvpLists['online'])) ?><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
<?php endif; endif; ?>

<?php // Entwurf in der Ablage: für Protokollant:in + Verwaltung (erster Klick kopiert die Vorlage einmalig dorthin).
      // Welche Ablage gilt, entscheidet die Datei selbst – liegt schon ein Entwurf in Teams, bleibt er dort.
      $canDraft = mirror_configured() && ($canManage || ($me && (int)($m['protocol_taker_id'] ?? 0) === (int)$me['id']));
      $draftPub = mirror_store('protokoll', $id) !== '';
      $draftInt = mirror_store('protokoll_intern', $id) !== '';
      /** Knopf „In … schreiben" bzw. „Entwurf in …" für einen Protokollteil. */
      $draftBtn = function (string $kind, bool $exists, string $style = '') use ($id): string {
          $store = mirror_store($kind, $id) ?: mirror_backend();
          $name  = mirror_label($store);
          $page  = $store === 'nextcloud' ? 'nextcloud.php' : 'teams.php';
          $icon  = $store === 'nextcloud' ? 'ti-brand-nextcloud' : 'ti-brand-teams';
          $title = $exists
              ? 'Entwurf liegt in ' . $name . ' – dort weiterschreiben (der Download oben liefert dann diesen Stand)'
              : 'Vorlage einmalig nach ' . $name . ' kopieren und dort gemeinsam schreiben';
          return '<a class="btn secondary"' . ($style !== '' ? ' style="' . h($style) . '"' : '')
              . ' href="' . $page . '?kind=' . rawurlencode($kind) . '&amp;id=' . $id . '" target="_blank" rel="noopener"'
              . ' title="' . h($title) . '"><i class="ti ' . $icon . '"></i> '
              . h($exists ? 'Entwurf in ' . $name : 'In ' . $name . ' schreiben') . '</a>';
      };
      $draftPubName = mirror_label(mirror_store('protokoll', $id) ?: mirror_backend());
      $draftIntName = mirror_label(mirror_store('protokoll_intern', $id) ?: mirror_backend()); ?>
<p style="margin:.2rem 0 1rem">
  <a class="btn secondary" href="meeting.php?id=<?= $id ?>&protokoll=1" target="_blank" rel="noopener"><i class="ti ti-file-download"></i> Protokollvorlage – öffentlicher Teil (.docx)</a>
  <?php if ($canDraft): ?><?= $draftBtn('protokoll', $draftPub) ?><?php endif; ?>
  <span class="small muted" style="margin-left:.4rem">inkl. Tagesordnung &amp; aktueller Berichte<?= $draftPub ? ' · <strong>Entwurf in ' . h($draftPubName) . ' vorhanden</strong> – der Download liefert dessen aktuellen Stand' : '' ?></span>
  <br>
  <a class="btn secondary" style="margin-top:.4rem" href="meeting.php?id=<?= $id ?>&protokoll_intern=1" target="_blank" rel="noopener"><i class="ti ti-file-download"></i> Protokollvorlage – interner Teil (.docx)</a>
  <?php if ($canDraft): ?><?= $draftBtn('protokoll_intern', $draftInt, 'margin-top:.4rem') ?><?php endif; ?>
  <span class="small muted" style="margin-left:.4rem">nicht-öffentlich, mit den internen TOPs<?= $draftInt ? ' · <strong>Entwurf in ' . h($draftIntName) . ' vorhanden</strong>' : '' ?></span>
</p>

<?php
// --- Protokoll-Workflow: Protokollant:in wählen → öffentliches + internes Protokoll nach Teams; Abstimmung/OLAT nur öffentlich ---
$pTaker  = (int)($m['protocol_taker_id'] ?? 0);
$pStatus = (string)($m['protocol_status'] ?? '');
$isTaker = $me && $pTaker === (int)$me['id'];
$pWebUrl = trim((string)($m['protocol_web_url'] ?? ''));
$pInternUp  = trim((string)($m['protocol_intern_item_id'] ?? '')) !== '';
$pInternWeb = trim((string)($m['protocol_intern_web_url'] ?? ''));
if ($canManage || $isTaker || $pStatus !== '' || $pInternUp):
    $takerName = '';
    if ($pTaker) { $tq = db()->prepare('SELECT name FROM members WHERE id = ?'); $tq->execute([$pTaker]); $takerName = (string)($tq->fetchColumn() ?: ''); }
    $teamsReady = trim((string)setting_get('graph_drive_id', '')) !== '';
    $statusPill = ['' => 'pill-warn', 'uploaded' => 'pill-info', 'approved' => 'pill-warn', 'published' => 'pill-ok'][$pStatus] ?? 'pill-warn';
    $canUpload  = ($isTaker || $canManage) && $teamsReady && $pTaker !== 0;
    // Upload-Formular für einen Protokoll-Teil ('public' | 'intern'); liegt ein Teams-Entwurf
    // vor, gibt es zusätzlich die Ein-Klick-Übernahme direkt aus Teams (ohne Runter-/Hochladen).
    $protoUploadForm = function (string $kind) use ($id) {
        $dk = $kind === 'intern' ? 'protokoll_intern' : 'protokoll';
        $dStore = mirror_store($dk, $id);
        ?>
        <?php if ($dStore === 'nextcloud'): ?>
          <p class="small muted" style="margin:.4rem 0 0"><i class="ti ti-info-circle"></i>
            Der Entwurf liegt in der <strong>Nextcloud</strong>. Die Ein-Klick-Übernahme gibt es bisher nur für Teams –
            bitte den fertigen Stand dort herunterladen und hier hochladen.</p>
        <?php endif; ?>
        <?php if ($dStore === 'teams'): ?>
          <form method="post" style="margin-top:.5rem" data-confirm="Den aktuellen Teams-Entwurf als fertiges Protokoll übernehmen?" data-confirm-ok="Übernehmen">
            <?= csrf_field() ?><input type="hidden" name="action" value="protocol_take_from_teams"><input type="hidden" name="kind" value="<?= h($kind) ?>">
            <button class="btn" type="submit"><i class="ti ti-brand-teams"></i> Fertige Fassung aus Teams übernehmen</button>
          </form>
          <p class="small muted" style="margin:.4rem 0 0">… oder klassisch eine Datei hochladen:</p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:.5rem">
          <?= csrf_field() ?><input type="hidden" name="action" value="protocol_upload"><input type="hidden" name="kind" value="<?= h($kind) ?>">
          <input type="file" name="file" accept=".docx" required>
          <div class="btn-row" style="margin-top:.6rem"><button class="btn" type="submit"><i class="ti ti-cloud-upload"></i> Nach Teams hochladen</button></div>
        </form>
    <?php };
?>
<div class="section-title" id="protokoll"><i class="ti ti-notebook"></i> Protokoll</div>
<div class="card">
  <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
    <strong>Status (öffentlich)</strong>
    <span class="pill <?= $statusPill ?>"><?= h(protocol_status_label($pStatus)) ?></span>
    <?php if ($takerName !== ''): ?><span class="small muted" style="margin-left:auto"><i class="ti ti-user-edit"></i> <?= member_link(['id' => $pTaker, 'name' => $takerName], '', true) ?></span><?php endif; ?>
  </div>

  <?php if ($canManage): ?>
    <form method="post" style="margin:.7rem 0 0">
      <?= csrf_field() ?><input type="hidden" name="action" value="protocol_set_taker">
      <label for="taker_id" class="small">Protokollant:in dieser Sitzung</label>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <select name="taker_id" id="taker_id" style="flex:1;min-width:12rem">
          <option value="0">— niemand gewählt —</option>
          <?php foreach (db()->query("SELECT id, name FROM members WHERE active = 1 ORDER BY name COLLATE NOCASE")->fetchAll() as $mm): ?>
            <option value="<?= (int)$mm['id'] ?>"<?= (int)$mm['id'] === $pTaker ? ' selected' : '' ?>><?= h($mm['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn small" type="submit"><i class="ti ti-check"></i> Übernehmen</button>
      </div>
      <p class="small muted" style="margin:.4rem 0 0">Die:der Gewählte bekommt im Dashboard die Aufgabe, das fertige Protokoll hochzuladen.</p>
    </form>
  <?php elseif ($takerName !== ''): ?>
    <p class="small muted" style="margin:.6rem 0 0">Protokollant:in: <strong><?= member_link(['id' => $pTaker, 'name' => $takerName], '', true) ?></strong><?= $isTaker ? ' (du)' : '' ?></p>
  <?php endif; ?>

  <?php if (($isTaker || $canManage) && !$teamsReady): ?>
    <hr class="auto-sep" style="margin:.9rem 0">
    <p class="help">Die Teams-Ablage ist noch nicht eingerichtet – das erledigt der Vorsitz unter <?= app_place('a_uploads') ?> (Technik).
      <?php if (nc_configured()): ?><br><strong>Hinweis:</strong> Entwürfe könnt ihr schon in der Nextcloud schreiben;
      das <em>fertige</em> Protokoll nimmt die App bisher nur über die Teams-Ablage entgegen.<?php endif; ?></p>
  <?php elseif (($isTaker || $canManage) && $pTaker === 0): ?>
    <hr class="auto-sep" style="margin:.9rem 0">
    <p class="help muted">Sobald ein:e Protokollant:in gewählt ist, können hier das öffentliche und das interne Protokoll hochgeladen werden.</p>
  <?php elseif (($isTaker || $canManage) && $pStatus === ''): ?>
    <hr class="auto-sep" style="margin:.9rem 0">
    <div class="help">
      <strong>So reichst du das Protokoll ein</strong> – zwei Wege, beide enden hier in der App:
      <ol class="small" style="margin:.4rem 0 0 1.1rem;padding:0">
        <li style="margin-bottom:.3rem"><strong>Ohne Word (empfohlen):</strong> Oben bei den Vorlagen <strong>„In Teams schreiben"</strong> klicken – der Entwurf öffnet sich in Office <strong>im Browser</strong>, ohne installiertes Word, vorbefüllt mit Tagesordnung und Berichten. Fertig? Dann unten <strong>„Fertige Fassung aus Teams übernehmen"</strong> – ein Klick, nichts herunterladen.</li>
        <li><strong>Mit Word:</strong> Vorlage oben herunterladen, in Word ausfüllen (nicht in Apple Pages) und unten als .docx hochladen.</li>
      </ol>
      <p class="small" style="margin:.5rem 0 0"><i class="ti ti-alert-triangle" style="color:var(--amber)"></i> <strong>Bitte nicht von Hand in einen Teams-Kanal legen:</strong> Davon erfährt die App nichts – die Abstimmung in der Folgesitzung und die OLAT-Veröffentlichung bleiben dann aus.</p>
    </div>
  <?php endif; ?>

  <hr class="auto-sep" style="margin:.9rem 0">
  <div class="section-title" style="font-size:1rem;margin-top:0"><i class="ti ti-world"></i> Öffentliches Protokoll</div>
  <?php if ($pStatus === ''): ?>
    <?php if ($canUpload): ?>
      <p class="help"><strong>Fertiges öffentliches Protokoll hochladen (.docx).</strong> Geht direkt in eure Teams-Ablage (nicht in der App gespeichert) und wird automatisch als Abstimmungsgegenstand in die nächste Sitzung gehängt.</p>
      <?php $protoUploadForm('public'); ?>
    <?php else: ?>
      <p class="small muted" style="margin:0">Noch nicht hochgeladen.</p>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($pWebUrl !== ''): ?>
      <p style="margin:0 0 .5rem"><a class="btn secondary" href="<?= h(protocol_open_url($m)) ?>" target="_blank" rel="noopener"><i class="ti ti-brand-teams"></i> In Teams öffnen &amp; bearbeiten</a>
        <?php if (trim((string)($m['protocol_filename'] ?? '')) !== ''): ?><span class="small muted" style="margin-left:.4rem"><?= h((string)$m['protocol_filename']) ?></span><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($pStatus === 'uploaded'): ?>
      <?php
        // Wo die Genehmigung ansteht – das kann wandern, wenn Sitzungen abgesagt oder verschoben
        // werden, deshalb hier die tatsächliche Zielsitzung und nicht nur „die nächste".
        $pVote = protocol_vote_item($id);   // abgeleitet aus vote_items.ref_meeting_id
        $pZiel = null;
        if ($pVote) { $zq = db()->prepare('SELECT * FROM meetings WHERE id = ?'); $zq->execute([(int)$pVote['meeting_id']]); $pZiel = $zq->fetch() ?: null; }
      ?>
      <?php if ($pZiel): ?>
        <p class="small muted" style="margin:0">Steht zur Genehmigung in der
          <a href="meeting.php?id=<?= (int)$pZiel['id'] ?>#abstimmung"><strong><?= h(meeting_label($pZiel)) ?></strong> am <?= h(fmt_date(substr((string)$pZiel['starts_at'], 0, 10))) ?></a>.
          Wird es dort <strong>angenommen</strong>, erscheint hier (und in der Sekki-Kachel) die Aufgabe, es als PDF nach OLAT zu veröffentlichen.</p>
      <?php elseif (!empty($m['protocol_vote_off'])): ?>
        <p class="small muted" style="margin:0"><i class="ti ti-circle-minus"></i> Die Abstimmung wurde von Hand entfernt – sie wird nicht von selbst wieder angelegt.</p>
        <?php if ($canManage): ?>
          <form method="post" style="margin:.5rem 0 0">
            <?= csrf_field() ?><input type="hidden" name="action" value="protocol_vote_again">
            <button class="btn small secondary" type="submit"><i class="ti ti-rotate"></i> Abstimmung wieder anlegen</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="small muted" style="margin:0">Sobald eine nächste Sitzung geplant ist, hängt die App die Genehmigung dort automatisch an. Wird es <strong>angenommen</strong>, erscheint hier die Aufgabe, es als PDF nach OLAT zu veröffentlichen.</p>
      <?php endif; ?>
      <?php if ($canManage && !($pVote && (string)$pVote['decision'] === 'offen')): // keine offene Abstimmung unterwegs: längst gefassten Beschluss nachtragen ?>
        <form method="post" style="margin:.5rem 0 0" data-confirm="Wurde dieses Protokoll wirklich schon in einer Sitzung angenommen? Es wird dann OHNE neue Abstimmung zur OLAT-Veröffentlichung freigegeben." data-confirm-ok="Als angenommen nachtragen">
          <?= csrf_field() ?><input type="hidden" name="action" value="protocol_mark_approved">
          <button class="btn small secondary" type="submit"><i class="ti ti-checkbox"></i> Bereits angenommen? Beschluss nachtragen</button>
        </form>
        <p class="small muted" style="margin:.3rem 0 0">Für Protokolle, die längst in einer Sitzung genehmigt wurden, deren Beschluss aber nie in der App gelandet ist – so hängen sie nicht in der Warteschleife auf die nächste Sitzung fest.</p>
      <?php endif; ?>
    <?php elseif ($pStatus === 'approved' && $canManage): ?>
      <p class="help" style="margin:.2rem 0 .5rem"><strong>Angenommen.</strong> Jetzt als PDF nach OLAT veröffentlichen – die finale Version wird aus Teams geholt, konvertiert und kopiert. <em>(Nur das öffentliche Protokoll – der interne Teil bleibt in Teams.)</em></p>
      <form method="post" data-confirm="Das angenommene öffentliche Protokoll jetzt als PDF nach OLAT veröffentlichen?" data-confirm-ok="Veröffentlichen">
        <?= csrf_field() ?><input type="hidden" name="action" value="protocol_publish">
        <button class="btn" type="submit"><i class="ti ti-upload"></i> Als PDF nach OLAT veröffentlichen</button>
      </form>
    <?php elseif ($pStatus === 'approved'): ?>
      <p class="small muted" style="margin:0">Angenommen – das Sekretariat veröffentlicht es demnächst als PDF in OLAT.</p>
    <?php elseif ($pStatus === 'published'): ?>
      <p class="small" style="margin:0;color:var(--green)"><i class="ti ti-circle-check"></i> Als PDF in OLAT veröffentlicht<?php if (trim((string)($m['protocol_olat_path'] ?? '')) !== ''): ?> <span class="muted">(<?= h((string)$m['protocol_olat_path']) ?>)</span><?php endif; ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($canManage && ($pickField = ablage_pick_field('protokoll')) !== ''): ?>
    <details style="margin-top:.5rem"><summary class="small muted" style="cursor:pointer"><i class="ti ti-link"></i> Teams-Verknüpfung reparieren</summary>
      <p class="small muted" style="margin:.4rem 0 0">Zeigt der Verweis auf eine Datei, die es nicht mehr gibt (gelöscht oder durch eine neue ersetzt – bloßes Umbenennen schadet nicht), oder liegt das Protokoll schon in Teams, ohne dass die App es kennt: Hier die richtige .docx auswählen. Der Stand (angenommen usw.) bleibt unangetastet.</p>
      <form method="post" style="margin:.4rem 0 0" data-confirm="Die gewählte Datei ab jetzt als öffentliches Protokoll dieser Sitzung führen?" data-confirm-ok="Verknüpfen">
        <?= csrf_field() ?><input type="hidden" name="action" value="protocol_relink"><input type="hidden" name="kind" value="public">
        <?= $pickField ?>
        <div class="btn-row" style="margin-top:.5rem"><button class="btn small secondary" type="submit"><i class="ti ti-link"></i> Als öffentliches Protokoll verknüpfen</button></div>
      </form>
    </details>
  <?php endif; ?>
  <?php if ($canManage && $pStatus !== ''): ?>
    <details style="margin-top:.4rem"><summary class="small muted" style="cursor:pointer"><i class="ti ti-arrow-back-up"></i> Zurücksetzen</summary>
      <p class="small muted" style="margin:.4rem 0 0">Macht das Festlegen rückgängig: Status wieder „offen", Verknüpfung weg, eine noch <strong>offene</strong> Abstimmung wird eingesammelt – ein bereits <strong>gefasster Beschluss</strong> bleibt als Beleg stehen, und die Dateien in Teams und OLAT bleiben liegen. Danach lässt sich das Protokoll ganz normal neu einreichen.</p>
      <form method="post" style="margin:.4rem 0 0" data-confirm="Protokoll-Eintrag wirklich zurücksetzen? Status, Verknüpfung und eine offene Abstimmung werden entfernt – Dateien bleiben liegen." data-confirm-ok="Zurücksetzen">
        <?= csrf_field() ?><input type="hidden" name="action" value="protocol_reset"><input type="hidden" name="kind" value="public">
        <button class="btn small secondary" type="submit"><i class="ti ti-arrow-back-up"></i> Protokoll-Eintrag zurücksetzen</button>
      </form>
    </details>
  <?php endif; ?>

  <hr class="auto-sep" style="margin:.9rem 0">
  <div class="section-title" style="font-size:1rem;margin-top:0"><i class="ti ti-lock"></i> Internes Protokoll <span class="small muted" style="font-weight:400">· bleibt nur in Teams, keine Veröffentlichung</span></div>
  <?php if (!$pInternUp): ?>
    <?php if ($canUpload): ?>
      <p class="help"><strong>Fertiges internes Protokoll hochladen (.docx).</strong> Geht in den <em>internen</em> Teams-Ordner – wird <strong>nicht</strong> abgestimmt und <strong>nicht</strong> nach OLAT veröffentlicht.</p>
      <?php $protoUploadForm('intern'); ?>
    <?php else: ?>
      <p class="small muted" style="margin:0">Noch nicht hochgeladen.</p>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($pInternWeb !== ''): ?>
      <p style="margin:0 0 .3rem"><a class="btn secondary" href="<?= h(protocol_open_url($m, true)) ?>" target="_blank" rel="noopener"><i class="ti ti-brand-teams"></i> In Teams öffnen &amp; bearbeiten</a>
        <?php if (trim((string)($m['protocol_intern_filename'] ?? '')) !== ''): ?><span class="small muted" style="margin-left:.4rem"><?= h((string)$m['protocol_intern_filename']) ?></span><?php endif; ?></p>
    <?php endif; ?>
    <p class="small" style="margin:0;color:var(--green)"><i class="ti ti-circle-check"></i> Hochgeladen – liegt ausschließlich in Teams.</p>
    <?php if ($isTaker || $canManage): ?>
      <details style="margin-top:.4rem"><summary class="small muted" style="cursor:pointer">Neu hochladen / ersetzen</summary><?php $protoUploadForm('intern'); ?></details>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($canManage && ($pickFieldI = ablage_pick_field('protokoll_intern')) !== ''): ?>
    <details style="margin-top:.5rem"><summary class="small muted" style="cursor:pointer"><i class="ti ti-link"></i> Teams-Verknüpfung reparieren</summary>
      <p class="small muted" style="margin:.4rem 0 0">Die richtige interne .docx aus Teams auswählen – etwa wenn die verknüpfte Datei gelöscht oder ersetzt wurde.</p>
      <form method="post" style="margin:.4rem 0 0" data-confirm="Die gewählte Datei ab jetzt als internes Protokoll dieser Sitzung führen?" data-confirm-ok="Verknüpfen">
        <?= csrf_field() ?><input type="hidden" name="action" value="protocol_relink"><input type="hidden" name="kind" value="intern">
        <?= $pickFieldI ?>
        <div class="btn-row" style="margin-top:.5rem"><button class="btn small secondary" type="submit"><i class="ti ti-link"></i> Als internes Protokoll verknüpfen</button></div>
      </form>
    </details>
  <?php endif; ?>
  <?php if ($canManage && $pInternUp): ?>
    <details style="margin-top:.4rem"><summary class="small muted" style="cursor:pointer"><i class="ti ti-arrow-back-up"></i> Zurücksetzen</summary>
      <p class="small muted" style="margin:.4rem 0 0">Macht das Festlegen des internen Protokolls rückgängig – die Datei in Teams bleibt liegen.</p>
      <form method="post" style="margin:.4rem 0 0" data-confirm="Eintrag zum internen Protokoll wirklich zurücksetzen? Die Datei in Teams bleibt liegen." data-confirm-ok="Zurücksetzen">
        <?= csrf_field() ?><input type="hidden" name="action" value="protocol_reset"><input type="hidden" name="kind" value="intern">
        <button class="btn small secondary" type="submit"><i class="ti ti-arrow-back-up"></i> Eintrag zurücksetzen</button>
      </form>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($me && !$canManage): ?>
  <a class="redeliste-join" href="<?= h(member_join_url($rede, $me)) ?>" target="_blank" rel="noopener">
    <span class="rj-icon"><i class="ti ti-list-numbers"></i></span>
    <span class="rj-text"><strong>Redeliste dieser Sitzung</strong><span class="rj-sub">Beitreten und dich zu Wort melden</span></span>
    <span class="rj-go">Beitreten <i class="ti ti-arrow-right"></i></span>
  </a>
<?php endif; ?>

<div class="section-title" id="tagesordnung"><i class="ti ti-list-check"></i> Vorläufige Tagesordnung</div>
<div class="card">
  <?php if ($me && $topOpen): ?>
    <div class="ag-toolbar">
      <button type="button" id="topAdd" class="btn"><i class="ti ti-plus"></i> TOP einreichen</button>
      <span class="ag-hint" hidden><i class="ti ti-arrow-down"></i> Klicke an die Stelle, wo dein TOP hin soll. <button type="button" id="topCancel" class="linklike">Abbrechen</button></span>
      <span class="small muted"><i class="ti ti-clock"></i> Einreichen noch bis <?= h(fmt_date(top_deadline_date($m))) ?></span>
    </div>
  <?php elseif ($me && !$m['cancelled'] && strtotime($m['starts_at']) >= time()): ?>
    <p class="small muted" style="margin:.2rem 0 .6rem"><i class="ti ti-lock"></i> Die Einreichefrist für TOPs ist abgelaufen (TOPs konnten bis <?= h(fmt_date(top_deadline_date($m))) ?> eingereicht werden – danach wird eingeladen).</p>
  <?php endif; ?>

  <ul class="agenda" id="agendaList">
    <?php
    // Öffentlicher Teil
    for ($i = 0; $i < $intStart; $i++) {
        foreach ($byAnchor[$i] ?? [] as $s) if ((int)$s['internal'] !== 1) $renderSub($s, $i);
        $renderGap($i, 'public');
        $renderBaseItem($i);
    }
    foreach ($byAnchor[$intStart] ?? [] as $s) if ((int)$s['internal'] !== 1) $renderSub($s, $intStart);
    $renderGap($intStart, 'public');
    // Trenner – immer vorhanden
    echo '<li class="ag-divider"><i class="ti ti-lock"></i> Interner Teil</li>';
    // Interner Teil
    for ($i = $intStart; $i < $n; $i++) {
        foreach ($byAnchor[$i] ?? [] as $s) if ((int)$s['internal'] === 1) $renderSub($s, $i);
        $renderGap($i, 'internal');
        $renderBaseItem($i);
    }
    foreach ($byAnchor[$n] ?? [] as $s) if ((int)$s['internal'] === 1) $renderSub($s, $n);
    $renderGap($n, 'internal');
    ?>
  </ul>
  <p class="small muted" style="margin-top:.7rem">
    <i class="ti ti-info-circle"></i>
    <?= $usesOwn ? 'Eigene Tagesordnung dieser Sitzung.' : 'Basiert auf der Standard-Tagesordnung.' ?>
    Vorläufig – Änderungen vorbehalten.
  </p>

  <?php if ($canManage): ?>
    <details class="agenda-edit">
      <summary><i class="ti ti-edit"></i> Tagesordnung bearbeiten</summary>
      <form method="post" style="margin-top:.7rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_agenda">
        <p class="small muted" style="margin-top:0">Punkte hinzufügen, per <i class="ti ti-arrow-bar-right"></i> einrücken (1.1, 1.2 …), mit <i class="ti ti-lock"></i> den internen Teil abtrennen. Eingereichte TOPs der Mitglieder erscheinen oben in der Liste und werden hier nicht mitbearbeitet.</p>
        <div class="agenda-editor"><textarea name="agenda" class="ag-data" rows="9" style="width:100%"><?= h($agendaText) ?></textarea></div>
        <div class="btn-row" style="margin-top:.7rem">
          <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
        </div>
      </form>
      <?php if ($usesOwn): ?>
        <form method="post" data-confirm="Tagesordnung dieser Sitzung verwerfen und wieder die Standard-TO verwenden?" data-confirm-ok="Zurücksetzen" style="margin-top:.5rem">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_agenda">
          <button class="btn secondary small" type="submit"><i class="ti ti-rotate"></i> Auf Standard-TO zurücksetzen</button>
        </form>
      <?php endif; ?>
    </details>
  <?php endif; ?>
</div>

<!-- Abstimmungsgegenstände: vom Sekretariat/Vorsitz/Admin gepflegt, von Mitgliedern vor der Sitzung zu lesen -->
<div class="section-title" id="abstimmung"><i class="ti ti-checkbox"></i> Abstimmungsgegenstände<?php if ($voteItems): ?> <span class="count"><?= count($voteItems) ?></span><?php endif; ?></div>
<div class="card">
  <p class="small muted" style="margin-top:0">Gegenstände, über die in dieser Sitzung abgestimmt wird. <strong>Bitte vor der Sitzung lesen</strong> und als gelesen markieren.</p>

  <?php if (!$voteItems): ?>
    <p class="empty" style="margin:.4rem 0"><i class="ti ti-inbox"></i> Noch keine Abstimmungsgegenstände eingetragen.</p>
  <?php else: ?>
    <?php if ($me && $voteUnread > 1): ?>
      <form method="post" style="margin:0 0 .7rem">
        <?= csrf_field() ?><input type="hidden" name="action" value="vote_read_all">
        <button class="btn small" type="submit"><i class="ti ti-checks"></i> Alle als gelesen markieren (<?= $voteUnread ?>)</button>
      </form>
    <?php endif; ?>
    <?php $totalMembers = (int)db()->query("SELECT COUNT(*) FROM members WHERE active = 1")->fetchColumn(); ?>
    <?php foreach ($voteItems as $pos => $vi): $read = $me ? isset($voteReadSet[(int)$vi['id']]) : false; ?>
      <div class="vote-item<?= $read ? ' read' : '' ?>">
        <div class="vote-head">
          <strong style="font-size:1.02rem"><?php if (($vi['kind'] ?? '') === 'protocol'): ?><i class="ti ti-notebook" title="Protokoll-Genehmigung" style="color:var(--petrol)"></i> <?php endif; ?><?= h($vi['title']) ?></strong>
          <?php if ($me): ?>
            <span class="pill <?= $read ? 'pill-ok' : 'pill-warn' ?>" style="margin-left:auto"><?= $read ? '<i class="ti ti-check"></i> gelesen' : 'ungelesen' ?></span>
          <?php endif; ?>
          <?php $vd = (string)($vi['decision'] ?? 'offen'); if ($vd !== 'offen'): ?>
            <span class="pill <?= $vd === 'angenommen' ? 'pill-ok' : 'pill-warn' ?>" style="margin-left:<?= $me ? '.4rem' : 'auto' ?>"><?= $vd === 'angenommen' ? '<i class="ti ti-check"></i> angenommen' : '<i class="ti ti-clock-pause"></i> vertagt' ?></span>
          <?php endif; ?>
        </div>
        <?php if (trim((string)$vi['body']) !== ''): ?>
          <div class="vote-body"><?= nl2br(h($vi['body'])) ?></div>
        <?php endif; ?>
        <?php if (($vi['kind'] ?? '') === 'protocol' && (int)($vi['ref_meeting_id'] ?? 0) > 0):
            $srcQ = db()->prepare('SELECT * FROM meetings WHERE id = ?'); $srcQ->execute([(int)$vi['ref_meeting_id']]);
            $srcRow = $srcQ->fetch() ?: [];
            $srcWeb  = trim((string)($srcRow['protocol_web_url'] ?? '')) !== '' ? protocol_open_url($srcRow) : '';
            $srcWebI = trim((string)($srcRow['protocol_intern_web_url'] ?? '')) !== '' ? protocol_open_url($srcRow, true) : '';
            if ($srcWeb !== '' || $srcWebI !== ''): ?>
          <p style="margin:.3rem 0 .2rem;display:flex;gap:.4rem;flex-wrap:wrap">
            <?php if ($srcWeb !== ''): ?><a class="btn small secondary" href="<?= h($srcWeb) ?>" target="_blank" rel="noopener"><i class="ti ti-brand-teams"></i> Öffentliches Protokoll in Teams</a><?php endif; ?>
            <?php if ($srcWebI !== ''): ?><a class="btn small secondary" href="<?= h($srcWebI) ?>" target="_blank" rel="noopener"><i class="ti ti-lock"></i> Internes Protokoll in Teams</a><?php endif; ?>
          </p>
        <?php endif; endif; ?>
        <?php if (!empty($vi['files'])): ?>
          <div class="vote-files">
            <?php foreach ($vi['files'] as $f): ?>
              <a class="info-file" href="download.php?vfile=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><i class="ti ti-file-download"></i> <span><?= h($f['orig_name']) ?></span></a> <?= mirror_open_button('vfile', (int)$f['id']) ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="vote-actions">
          <?php if ($me && !$read): ?>
            <form method="post" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="action" value="vote_read"><input type="hidden" name="vote_id" value="<?= (int)$vi['id'] ?>">
              <button class="btn small" type="submit"><i class="ti ti-check"></i> Als gelesen markieren</button>
            </form>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <span class="small muted" title="So viele aktive Mitglieder haben gelesen"><i class="ti ti-eye"></i> <?= vote_item_read_count((int)$vi['id']) ?>/<?= $totalMembers ?> gelesen</span>
            <span class="spacer" style="flex:1"></span>
            <?php if ($pos > 0): ?><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="vote_move"><input type="hidden" name="vote_id" value="<?= (int)$vi['id'] ?>"><input type="hidden" name="dir" value="up"><button class="btn small secondary" type="submit" title="Nach oben"><i class="ti ti-arrow-up"></i></button></form><?php endif; ?>
            <?php if ($pos < count($voteItems) - 1): ?><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="vote_move"><input type="hidden" name="vote_id" value="<?= (int)$vi['id'] ?>"><input type="hidden" name="dir" value="down"><button class="btn small secondary" type="submit" title="Nach unten"><i class="ti ti-arrow-down"></i></button></form><?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($canManage): $vd = (string)($vi['decision'] ?? 'offen'); ?>
          <div class="vote-decision" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;margin-top:.5rem">
            <span class="small muted"><i class="ti ti-gavel"></i> Beschluss:</span>
            <?php foreach (['angenommen' => 'ti-check', 'vertagt' => 'ti-clock-pause', 'offen' => 'ti-dots'] as $dv => $ic): ?>
              <form method="post" style="margin:0">
                <?= csrf_field() ?><input type="hidden" name="action" value="vote_decision"><input type="hidden" name="vote_id" value="<?= (int)$vi['id'] ?>"><input type="hidden" name="decision" value="<?= $dv ?>">
                <button class="btn small <?= $vd === $dv ? '' : 'secondary' ?>" type="submit"><i class="ti <?= $ic ?>"></i> <?= ucfirst($dv) ?></button>
              </form>
            <?php endforeach; ?>
            <?php if (($vi['kind'] ?? '') === 'protocol'): ?><span class="small muted" title="„Angenommen" schaltet die Protokoll-Veröffentlichung frei">· schaltet die OLAT-Veröffentlichung frei</span><?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($canManage): ?>
          <details class="vote-edit">
            <summary class="small"><i class="ti ti-edit"></i> Bearbeiten / Dateien</summary>
            <form method="post" enctype="multipart/form-data" style="margin:.5rem 0">
              <?= csrf_field() ?><input type="hidden" name="action" value="vote_edit"><input type="hidden" name="vote_id" value="<?= (int)$vi['id'] ?>">
              <label>Titel</label>
              <input type="text" name="vote_title" value="<?= h($vi['title']) ?>" required>
              <label>Text (optional)</label>
              <textarea name="vote_body" rows="4"><?= h($vi['body']) ?></textarea>
              <label>Dateien hinzufügen (max. 50 MB je Datei)</label>
              <input type="file" name="docs[]" multiple>
              <?= ablage_pick_field('vfile') ?>
              <div class="btn-row" style="margin-top:.5rem"><button class="btn small" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button></div>
            </form>
            <?php if (!empty($vi['files'])): ?>
              <div class="small muted" style="margin:.3rem 0 .2rem">Vorhandene Dateien:</div>
              <div class="vote-files">
                <?php foreach ($vi['files'] as $f): ?>
                  <span class="vote-file-row">
                    <a class="info-file" href="download.php?vfile=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><i class="ti ti-file"></i> <span><?= h($f['orig_name']) ?></span></a> <?= mirror_open_button('vfile', (int)$f['id']) ?>
                    <form method="post" style="margin:0" data-confirm="Diese Datei löschen?" data-confirm-danger data-confirm-ok="Löschen"<?= mirror_delete_check_attr('vfile', (int)$f['id']) ?>><?= csrf_field() ?><input type="hidden" name="action" value="vote_file_delete"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"><button class="btn small danger" type="submit" title="Datei löschen"><i class="ti ti-trash"></i></button></form>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <form method="post" style="margin:.6rem 0 0" data-confirm="Diesen Abstimmungsgegenstand mit allen Dateien löschen?" data-confirm-danger data-confirm-ok="Löschen">
              <?= csrf_field() ?><input type="hidden" name="action" value="vote_delete"><input type="hidden" name="vote_id" value="<?= (int)$vi['id'] ?>">
              <button class="btn small danger" type="submit"><i class="ti ti-trash"></i> Abstimmungsgegenstand löschen</button>
            </form>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($canManage): ?>
    <details class="vote-add"<?= $voteItems ? '' : ' open' ?> style="margin-top:.7rem">
      <summary class="btn" style="display:inline-flex"><i class="ti ti-plus"></i> Abstimmungsgegenstand hinzufügen</summary>
      <form method="post" enctype="multipart/form-data" style="margin-top:.7rem">
        <?= csrf_field() ?><input type="hidden" name="action" value="vote_add">
        <label for="vote_title">Titel</label>
        <input type="text" name="vote_title" id="vote_title" required placeholder="Worüber wird abgestimmt?">
        <label for="vote_body" style="margin-top:.5rem">Text (optional)</label>
        <textarea name="vote_body" id="vote_body" rows="4" placeholder="Erläuterung, Antragstext …"></textarea>
        <label style="margin-top:.5rem">Dateien (optional · löschen sich nach 40 Tagen automatisch · max. 50 MB je Datei)</label>
        <input type="file" name="docs[]" multiple>
        <?= ablage_pick_field('vfile') ?>
        <div class="btn-row" style="margin-top:.7rem"><button class="btn" type="submit"><i class="ti ti-plus"></i> Hinzufügen</button></div>
      </form>
    </details>
  <?php endif; ?>
</div>

<?php if ($me): ?>
<form method="post" id="topForm" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="submit_top">
  <input type="hidden" name="title" id="tf-title">
  <input type="hidden" name="internal" id="tf-internal" value="0">
  <input type="hidden" name="anchor" id="tf-anchor" value="">
  <input type="hidden" name="time_est" id="tf-time">
  <input type="hidden" name="description" id="tf-desc">
</form>
<dialog id="topModal" class="fb-modal">
  <h3 style="margin:0 0 .2rem"><i class="ti ti-plus" style="color:var(--petrol)"></i> TOP einreichen</h3>
  <label for="top-title">Titel des TOP</label>
  <input type="text" id="top-title" required placeholder="Worum geht es?">
  <div style="margin:.7rem 0">
    <div class="small muted" style="margin-bottom:.2rem">In welchen Teil?</div>
    <label class="inline" style="margin-right:1rem"><input type="radio" name="top-part" value="0" id="top-pub" checked> Öffentlicher Teil</label>
    <label class="inline"><input type="radio" name="top-part" value="1" id="top-int"> Interner Teil</label>
  </div>
  <div id="top-pubfields">
    <label for="top-time">Ungefähre Dauer (Minuten)</label>
    <input type="number" id="top-time" min="1" step="1" placeholder="z. B. 10">
    <label for="top-desc" style="margin-top:.6rem">Kurze Beschreibung</label>
    <textarea id="top-desc" rows="3" placeholder="z. B. Der AStA stellt ein neues Raumkonzept vor."></textarea>
  </div>
  <p class="small muted" style="margin:.5rem 0 .8rem">Möchtest du eine bestimmte Position wählen? Sonst ordnet das Sekretariat den TOP passend ein.</p>
  <div class="btn-row" style="justify-content:flex-end;flex-wrap:wrap">
    <button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Abbrechen</button>
    <button type="button" class="btn secondary" id="top-noPos"><i class="ti ti-send"></i> Positionierung ist mir egal</button>
    <button type="button" class="btn" id="top-pickPos"><i class="ti ti-list-search"></i> Position wählen</button>
  </div>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('topModal');
  var list = document.getElementById('agendaList');
  var form = document.getElementById('topForm');
  var addBtn = document.getElementById('topAdd');
  var hint = document.querySelector('.ag-hint');
  var cancel = document.getElementById('topCancel');
  if (!dlg || !list || !form || !addBtn) return;

  function v(id) { return document.getElementById(id).value.trim(); }
  function isInternal() { return document.getElementById('top-int').checked; }
  function endPlacing() { list.classList.remove('placing-public', 'placing-internal'); if (hint) hint.hidden = true; }

  // Zeit + Beschreibung nur im öffentlichen Teil zeigen
  function syncFields() { document.getElementById('top-pubfields').style.display = isInternal() ? 'none' : ''; }
  document.getElementById('top-pub').addEventListener('change', syncFields);
  document.getElementById('top-int').addEventListener('change', syncFields);

  // füllt das versteckte Formular; validiert Pflichtfelder. Gibt true bei Erfolg.
  function fill(anchor) {
    if (!v('top-title')) { document.getElementById('top-title').focus(); return false; }
    var intern = isInternal();
    if (!intern && !v('top-time')) { document.getElementById('top-time').focus(); return false; }
    if (!intern && !v('top-desc')) { document.getElementById('top-desc').focus(); return false; }
    document.getElementById('tf-title').value = v('top-title');
    document.getElementById('tf-internal').value = intern ? '1' : '0';
    document.getElementById('tf-time').value = intern ? '' : v('top-time');
    document.getElementById('tf-desc').value = intern ? '' : v('top-desc');
    document.getElementById('tf-anchor').value = anchor;
    return true;
  }

  addBtn.addEventListener('click', function () {
    endPlacing();
    document.getElementById('top-title').value = '';
    document.getElementById('top-time').value = '';
    document.getElementById('top-desc').value = '';
    document.getElementById('top-pub').checked = true;
    syncFields();
    if (dlg.showModal) dlg.showModal();
    document.getElementById('top-title').focus();
  });
  if (cancel) cancel.addEventListener('click', endPlacing);

  document.getElementById('top-noPos').addEventListener('click', function () {
    if (fill('')) form.submit();
  });
  document.getElementById('top-pickPos').addEventListener('click', function () {
    if (!fill('')) return;
    var part = isInternal() ? 'internal' : 'public';
    if (dlg.close) dlg.close();
    list.classList.add('placing-' + part);
    if (hint) hint.hidden = false;
    list.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
  document.querySelectorAll('.ag-gap-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      document.getElementById('tf-anchor').value = b.dataset.anchor;
      form.submit();
    });
  });
})();
</script>
<?php endif; ?>

<?php if ($canManage && (int)($m['invite_required'] ?? 1) === 1 && empty($m['cancelled'])):
    $textMode = invite_text_mode();
    $due = invite_due_ts($m); $dueStr = fmt_date(date('Y-m-d', $due));
    $invSent = trim((string)($m['invite_sent_at'] ?? '')) !== '';
    $invApproved = (int)($m['invite_approved'] ?? 0) === 1;
    $invHasTeams = trim((string)($m['teams_link'] ?? '')) !== '';
?>
<details class="card collapse-card"<?= $invSent ? '' : ' open' ?>>
  <summary class="section-title" style="margin-top:0"><i class="ti ti-mail-forward"></i> Einladung
    <?php if ($invSent): ?>
      <span class="pill pill-ok" style="margin-left:auto"><i class="ti ti-check"></i> versendet</span>
    <?php else: ?>
      <span class="pill <?= $textMode ? 'pill-info' : 'pill-warn' ?>" style="margin-left:auto"><i class="ti <?= $textMode ? 'ti-clipboard-text' : 'ti-mail-fast' ?>"></i> <?= $textMode ? 'Text ausgeben' : 'Auto-Versand' ?></span>
    <?php endif; ?>
    <i class="ti ti-chevron-down collapse-chev" style="margin-left:.4rem"></i>
  </summary>

  <?php if ($textMode): /* ---------- Text-Modus: fertigen Text ausgeben, Sekki versendet selbst ---------- */ ?>
    <?php if ($invSent): ?>
      <p class="small" style="margin:0 0 .5rem;color:var(--green)"><i class="ti ti-check"></i> Am <?= h(fmt_date(substr((string)$m['invite_sent_at'], 0, 10))) ?> als <strong>versendet</strong> markiert.</p>
      <form method="post" data-confirm="Markierung „versendet" zurücknehmen? Du kannst die Einladung dann erneut ausgeben und markieren." data-confirm-ok="Zurücknehmen" data-confirm-title="Versand-Markierung">
        <?= csrf_field() ?><input type="hidden" name="action" value="invite_set_sent"><input type="hidden" name="sent" value="0">
        <button class="btn secondary small" type="submit"><i class="ti ti-arrow-back-up"></i> Doch nicht versendet</button>
      </form>
    <?php else: ?>
      <p class="small muted" style="margin:0 0 .6rem">Modus <strong>„Text ausgeben"</strong>: Teams-Link eintragen, dann den fertigen Einladungstext unten kopieren und über euer <strong>Verteiler-Webinterface</strong> versenden. Danach unten <strong>„Als versendet markieren"</strong>. (Umschaltbar unter <?= app_place('a_sekretariat', 'Verwaltung → Sekretariatsaufgaben') ?>.)</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="invite_save">
        <label for="i_teams">Teams-Beitrittslink</label>
        <input type="text" name="teams_link" id="i_teams" value="<?= h($m['teams_link'] ?? '') ?>" placeholder="https://teams.microsoft.com/meet/…">
        <label class="inline" style="margin-top:.5rem"><input type="checkbox" name="invite_reminder" <?= (int)($m['invite_reminder'] ?? 1) === 1 ? 'checked' : '' ?>> Reminder ans Sekretariat, solange noch nicht versendet</label>
        <div class="btn-row" style="margin-top:.6rem"><button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Teams-Link speichern</button></div>
      </form>
      <?php if (!$invHasTeams): ?>
        <p class="small" style="margin:.5rem 0 0;color:var(--amber)"><i class="ti ti-alert-triangle"></i> Ohne Teams-Link erscheint im Text „(Teams-Link fehlt)" – bitte zuerst eintragen und speichern.</p>
      <?php endif; ?>
      <hr style="border:0;border-top:1px solid var(--line);margin:.9rem 0">
      <label for="inv_subject">Betreff</label>
      <div style="display:flex;gap:.4rem;align-items:center">
        <input type="text" id="inv_subject" value="<?= h(invite_mail_subject($m)) ?>" readonly onclick="this.select()" style="flex:1;min-width:0">
        <button type="button" class="btn secondary small inv-copy" data-copy="#inv_subject"><i class="ti ti-copy"></i> Kopieren</button>
      </div>
      <label for="inv_text" style="margin-top:.6rem">Einladungstext</label>
      <textarea id="inv_text" readonly rows="14" onclick="this.select()" style="width:100%;font-size:.85rem;line-height:1.5"><?= h(invite_mail_text($m)) ?></textarea>
      <div class="btn-row" style="margin-top:.7rem;flex-wrap:wrap">
        <button type="button" class="btn secondary inv-copy" data-copy="#inv_text"><i class="ti ti-copy"></i> Text kopieren</button>
        <form method="post" style="margin:0" data-confirm="Einladung jetzt als versendet markieren? Mach das erst, nachdem du sie über den Verteiler verschickt hast." data-confirm-ok="Als versendet markieren" data-confirm-title="Einladung versendet?">
          <?= csrf_field() ?><input type="hidden" name="action" value="invite_set_sent"><input type="hidden" name="sent" value="1">
          <button class="btn" type="submit"><i class="ti ti-circle-check"></i> Als versendet markieren</button>
        </form>
      </div>
    <?php endif; ?>

  <?php else: /* ---------- Auto-Modus: App verschickt am Stichtag an den Verteiler ---------- */ ?>
    <?php if ($invSent): ?>
      <p class="small" style="margin:0;color:var(--green)"><i class="ti ti-check"></i> Einladung verschickt am <?= h(fmt_date(substr((string)$m['invite_sent_at'], 0, 10))) ?> an den Verteiler.</p>
    <?php else: ?>
      <p class="small muted" style="margin:0 0 .6rem">Automatischer Versand am <strong><?= h($dueStr) ?></strong> (Sitzungstag − <?= (int)invite_lead_days() ?> Tage), sobald freigegeben &amp; Teams-Link gesetzt. Empfänger: <?= invite_to_addr() !== '' ? h(invite_to_addr())
        : '⚠ kein Verteiler hinterlegt (' . app_place('a_sitzungen', 'Sitzungen → Einladungen', 'einladungen') . ')' ?>.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="invite_save">
        <label for="i_teams">Teams-Beitrittslink</label>
        <input type="text" name="teams_link" id="i_teams" value="<?= h($m['teams_link'] ?? '') ?>" placeholder="https://teams.microsoft.com/meet/…">
        <label class="inline" style="margin-top:.5rem"><input type="checkbox" name="invite_reminder" <?= (int)($m['invite_reminder'] ?? 1) === 1 ? 'checked' : '' ?>> Reminder-Mail ans Sekretariat, solange nicht freigegeben</label>
        <div class="btn-row" style="margin-top:.7rem;flex-wrap:wrap">
          <button class="btn" type="submit" name="approve" value="1"><i class="ti ti-circle-check"></i> Freigeben &amp; am Stichtag senden</button>
          <button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Nur speichern</button>
          <a class="btn secondary" href="meeting.php?id=<?= $id ?>&invite_preview=1" target="_blank" rel="noopener"><i class="ti ti-eye"></i> Vorschau</a>
          <?php if ($invApproved && $invHasTeams): ?><button class="btn" type="submit" name="send_now" value="1" onclick="return confirm('Einladung jetzt sofort an den Verteiler senden?')"><i class="ti ti-send"></i> Jetzt senden</button><?php endif; ?>
        </div>
        <?php if ($invApproved): ?>
          <p class="small" style="margin:.5rem 0 0;<?= $invHasTeams ? 'color:var(--green)' : 'color:var(--red)' ?>"><i class="ti ti-<?= $invHasTeams ? 'check' : 'alert-triangle' ?>"></i> Freigegeben<?= $invHasTeams ? ' – Versand am Stichtag automatisch.' : ' – aber der Teams-Link fehlt noch, sonst kann nicht gesendet werden!' ?></p>
        <?php else: ?>
          <p class="small" style="margin:.5rem 0 0"><i class="ti ti-alert-triangle" style="color:var(--amber)"></i> Noch nicht freigegeben – die Einladung wird (noch) nicht verschickt.</p>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</details>
<?php if ($textMode && !$invSent): ?>
<script>
(function(){
  document.querySelectorAll('.inv-copy').forEach(function(btn){
    btn.addEventListener('click', function(){
      var el = document.querySelector(btn.getAttribute('data-copy')); if (!el) return;
      el.select();
      var done = function(){ var o = btn.innerHTML; btn.innerHTML = '<i class="ti ti-check"></i> Kopiert'; setTimeout(function(){ btn.innerHTML = o; }, 1500); };
      if (navigator.clipboard) { navigator.clipboard.writeText(el.value).then(done, function(){ document.execCommand('copy'); done(); }); }
      else { document.execCommand('copy'); done(); }
    });
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-list-numbers"></i> Redeliste</div>
  <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;margin:.2rem 0 .3rem">
    <form method="post" target="_blank"<?= $redeLive ? ' data-confirm="Für diese Sitzung läuft bereits eine Redeliste. Neu starten setzt Redeliste, Redezähler und Gäste zurück und lädt Tagesordnung &amp; Stammliste frisch. Zum bloßen Weiterarbeiten stattdessen unten „Sitzungsleitung – öffnen“ nutzen." data-confirm-ok="Neu starten" data-confirm-danger' : '' ?> style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="seed_redeliste">
      <button class="btn" type="submit"><i class="ti ti-player-play"></i> <?= $redeLive ? 'Redeliste neu starten' : 'Redeliste starten' ?> – Tagesordnung &amp; Stammliste laden</button>
    </form>
    <?php if (redeliste_started($m)): ?>
    <form method="post" data-confirm="Redeliste zurücksetzen? Die laufende bzw. beendete Liste wird gelöscht und die Sitzung gilt wieder als „nicht gestartet“. Beim nächsten „Redeliste starten“ wird sie frisch geladen." data-confirm-ok="Zurücksetzen" data-confirm-danger style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_redeliste">
      <button class="btn secondary" type="submit" title="Setzt die Sitzung auf „nicht gestartet“ zurück (löscht den Redelisten-Raum)"><i class="ti ti-rotate-2"></i> Zurücksetzen</button>
    </form>
    <?php endif; ?>
  </div>
  <p class="small muted" style="margin:0 0 .9rem">Schreibt die komplette TO (inkl. eingereichte TOPs) und alle aktiven Mitglieder (mit Pronomen) in den Raum und öffnet die Sitzungsleitung – nichts mehr von Hand einzutragen.<?php if ($redeLive): ?> <strong>Achtung:</strong> Es läuft schon eine Liste – ein Neustart überschreibt sie.<?php endif; ?></p>
  <p class="small muted" style="margin-top:0">Oder einzelne Links öffnen / weitergeben:</p>
  <div class="rede-links">
    <?php
    $row = function (string $label, string $desc, string $url, bool $secret = false) {
        echo '<div class="rede-link' . ($secret ? ' is-secret' : '') . '">';
        echo '<div class="rede-meta"><strong>' . h($label) . '</strong><span class="small muted">' . h($desc) . '</span></div>';
        echo '<div class="rede-actions">';
        echo '<a class="btn small secondary" href="' . h($url) . '" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> Öffnen</a>';
        echo '<button type="button" class="btn small secondary copy-link" data-url="' . h($url) . '"><i class="ti ti-copy"></i> Kopieren</button>';
        echo '</div></div>';
    };
    if (redeliste_started($m)) $row('Sitzungsleitung – öffnen', 'Öffnet den aktuellen Stand zum Weiterarbeiten (ohne neu zu laden). Steuert die Liste & sieht den internen Teil. Geheim – nicht weitergeben!', leader_join_url($rede, $me), true);
    if ($me) $row('Selbst beitreten (als ' . (string)$me['name'] . ')', 'Dein persönlicher Beitritt mit Name & Pronomen – sieht den internen Teil.', member_join_url($rede, $me));
    $row('Gast', 'Beitreten & melden – nur der öffentliche Teil.', $rede['guest']);
    $row('Beamer (öffentlich)', 'Nur mitlesen/zeigen, ohne internen Teil. Schmale Ansicht – passt neben den Online-Call (mit &voll große Ansicht).', $rede['beamer']);
    $row('Beamer (intern)', 'Nur mitlesen/zeigen, mit internem Teil. Schmale Ansicht – passt neben den Online-Call (mit &voll große Ansicht).', $rede['beamer_intern']);
    ?>
  </div>
</div>
<script>
(function () {
  document.querySelectorAll('.copy-link').forEach(function (b) {
    b.addEventListener('click', function () {
      var done = function () { var o = b.innerHTML; b.innerHTML = '<i class="ti ti-check"></i> Kopiert'; setTimeout(function () { b.innerHTML = o; }, 1500); };
      if (navigator.clipboard) { navigator.clipboard.writeText(b.dataset.url || '').then(done, done); } else { done(); }
    });
  });
})();
</script>
<?php endif; ?>
<?php
page_footer();
