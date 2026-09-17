<?php
/**
 * Abstimmungen & Umlaufverfahren:
 *  - Umlaufbeschlüsse (Ja/Nein/Enthaltung mit Frist, Annahme-Regel, Quorum) – verbindlich.
 *  - Normale Abstimmungen/Umfragen (freie Antwortoptionen, Einzel-/Mehrfachauswahl,
 *    freiwillig oder verpflichtend) – Detail über ?poll=ID.
 * Alle Mitglieder können beides starten; Sichtbarkeit (namentlich/geheim) ist je Vorgang
 * wählbar. Abgelaufene Fristen werten umlauf_maintain()/poll_maintain() beim Seitenaufruf
 * und im Cron automatisch aus.
 */
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;
umlauf_maintain(); // abgelaufene Fristen sofort auswerten (nicht erst beim nächsten Cron)
poll_maintain();

/** Mehrfach-Upload docs[] an einen Umlauf ('umlauf') / eine Abstimmung ('poll') hängen; Fehler als Flash. */
function umlauf_handle_doc_uploads(string $kind, int $refId, int $byId): void
{
    foreach ((array)($_FILES['docs']['name'] ?? []) as $k => $n) {
        $f = ['name' => $n, 'type' => $_FILES['docs']['type'][$k] ?? '', 'tmp_name' => $_FILES['docs']['tmp_name'][$k] ?? '',
              'error' => $_FILES['docs']['error'][$k] ?? UPLOAD_ERR_NO_FILE, 'size' => $_FILES['docs']['size'][$k] ?? 0];
        $err = umlauf_file_add($kind, $refId, $f, $byId);
        if ($err !== null) flash(h((string)$n) . ': ' . $err, 'error');
    }
    // … und dazu, was aus der Ablage gewählt wurde (Beschlussvorschlag, Antrag, Vertrag – das
    // liegt meist längst in Teams/Nextcloud und muss nicht erst durch den Rechner wandern).
    foreach (ablage_pick_attach('ufile', function (string $orig, string $stored, string $mime, int $size) use ($kind, $refId, $byId): int {
        db()->prepare('INSERT INTO umlauf_files(kind, ref_id, orig_name, stored_name, mime, size, uploaded_by) VALUES(?,?,?,?,?,?,?)')
            ->execute([$kind, $refId, $orig, $stored, $mime, $size, $byId ?: null]);
        return (int)db()->lastInsertId();
    }, $byId) as $err) flash(h($err), 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && $me) {
        $deadline = norm_dtl((string)($_POST['deadline'] ?? ''));
        if (!$deadline) { flash('Bitte eine Frist (Datum & Uhrzeit) angeben.', 'error'); redirect('umlauf.php#neu'); }
        if ($deadline <= date('Y-m-d H:i')) { flash('Die Frist muss in der Zukunft liegen.', 'error'); redirect('umlauf.php#neu'); }
        $nid = umlauf_create($meId, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''),
            $deadline, ($_POST['visibility'] ?? '') === 'secret', (string)($_POST['rule'] ?? 'simple'),
            (int)($_POST['quorum_pct'] ?? 0));
        if ($nid) {
            umlauf_handle_doc_uploads('umlauf', $nid, $meId);
            flash('Umlaufverfahren gestartet – alle Mitglieder wurden benachrichtigt.', 'success');
            redirect('umlauf.php?id=' . $nid);
        }
        flash('Bitte mindestens einen Titel angeben.', 'error');
        redirect('umlauf.php#neu');
    }

    if ($action === 'poll_create' && $me) {
        $deadline = norm_dtl((string)($_POST['deadline'] ?? '')); // optional
        $mandatory = ($_POST['binding'] ?? '') === 'mandatory';
        if ($deadline !== null && $deadline !== '' && $deadline <= date('Y-m-d H:i')) { flash('Die Frist muss in der Zukunft liegen.', 'error'); redirect('umlauf.php#neu'); }
        if ($mandatory && !$deadline) { flash('Verpflichtende Abstimmungen brauchen eine Frist.', 'error'); redirect('umlauf.php#neu'); }
        $optLines = preg_split('/\r\n|\r|\n/', (string)($_POST['options'] ?? ''));
        $nid = poll_create($meId, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''),
            $deadline, ($_POST['visibility'] ?? '') === 'secret', ($_POST['mode'] ?? '') === 'multi',
            $mandatory, $optLines ?: []);
        if ($nid) {
            umlauf_handle_doc_uploads('poll', $nid, $meId);
            flash('Abstimmung gestartet – alle Mitglieder wurden benachrichtigt.', 'success');
            redirect('umlauf.php?poll=' . $nid);
        }
        flash('Bitte einen Titel und mindestens zwei Antwortoptionen (eine pro Zeile) angeben.', 'error');
        redirect('umlauf.php#neu');
    }

    if ($action === 'poll_vote' && $me) {
        $pid = (int)($_POST['poll_id'] ?? 0);
        $picks = array_map('intval', (array)($_POST['opt'] ?? []));
        if (poll_vote($pid, $meId, $picks)) {
            $closedNow = (string)(poll_get($pid)['status'] ?? '') === 'closed';
            flash($closedNow ? 'Deine Stimme ist gespeichert – damit haben alle abgestimmt, die Abstimmung ist beendet.'
                             : 'Deine Stimme ist gespeichert. Du kannst sie bis zum Abschluss ändern.', 'success');
        } else {
            flash('Abstimmen nicht möglich – bitte mindestens eine Option wählen (oder die Abstimmung ist beendet).', 'error');
        }
        redirect('umlauf.php?poll=' . $pid);
    }

    if ($action === 'poll_close') {
        $pid = (int)($_POST['poll_id'] ?? 0);
        $p = $pid ? poll_get($pid) : null;
        if ($p && poll_can_manage($p, $me) && poll_close($pid)) flash('Abstimmung beendet – das Ergebnis bleibt sichtbar.', 'success');
        else flash('Beenden nicht möglich.', 'error');
        redirect('umlauf.php?poll=' . $pid);
    }

    if ($action === 'poll_delete') {
        $pid = (int)($_POST['poll_id'] ?? 0);
        if (poll_delete($pid, $me)) { flash('Abstimmung gelöscht.', 'success'); redirect('umlauf.php'); }
        flash('Löschen dürfen nur Ersteller:in und Admin.', 'error');
        redirect('umlauf.php?poll=' . $pid);
    }

    if ($action === 'ufile_add' && $me) {
        $fkind = ($_POST['fkind'] ?? '') === 'poll' ? 'poll' : 'umlauf';
        $rid = (int)($_POST['ref_id'] ?? 0);
        $obj = $fkind === 'umlauf' ? umlauf_get($rid) : poll_get($rid);
        $can = $obj && ($fkind === 'umlauf' ? umlauf_can_manage($obj, $me) : poll_can_manage($obj, $me));
        if ($can) { umlauf_handle_doc_uploads($fkind, $rid, $meId); flash('Anhänge gespeichert.', 'success'); }
        else flash('Anhänge dürfen nur Ersteller:in und Admin verwalten.', 'error');
        redirect($fkind === 'poll' ? 'umlauf.php?poll=' . $rid : 'umlauf.php?id=' . $rid);
    }

    if ($action === 'ufile_delete' && $me) {
        $fid = (int)($_POST['file_id'] ?? 0);
        $st = db()->prepare('SELECT * FROM umlauf_files WHERE id = ?');
        $st->execute([$fid]);
        $f = $st->fetch();
        $obj = $f ? ((string)$f['kind'] === 'umlauf' ? umlauf_get((int)$f['ref_id']) : poll_get((int)$f['ref_id'])) : null;
        $can = $f && $obj && ((string)$f['kind'] === 'umlauf' ? umlauf_can_manage($obj, $me) : poll_can_manage($obj, $me));
        if ($can) {
            if (!empty($_POST['store_too'])) { // Häkchen im Lösch-Dialog: Dok mit in den Papierkorb der Ablage
                $err = null;
                if (!mirror_delete_remote('ufile', $fid, $err)) {
                    flash('Das Dok in der Ablage konnte nicht gelöscht werden' . ($err ? ': ' . $err : '.') . ' Der Anhang wurde trotzdem entfernt.', 'error');
                }
            }
            umlauf_file_delete($fid);
            flash('Anhang gelöscht.', 'success');
            redirect((string)$f['kind'] === 'poll' ? 'umlauf.php?poll=' . (int)$f['ref_id'] : 'umlauf.php?id=' . (int)$f['ref_id']);
        }
        flash('Anhänge dürfen nur Ersteller:in und Admin verwalten.', 'error');
        redirect('umlauf.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $u = $id ? umlauf_get($id) : null;

    if ($action === 'vote' && $me && $u) {
        if (umlauf_vote($id, $meId, (string)($_POST['choice'] ?? ''))) {
            $closedNow = (string)(umlauf_get($id)['status'] ?? '') === 'closed';
            flash($closedNow ? 'Deine Stimme ist gespeichert – damit haben alle abgestimmt, der Umlauf ist ausgewertet.'
                             : 'Deine Stimme ist gespeichert. Du kannst sie bis zum Abschluss ändern.', 'success');
        } else {
            flash('Abstimmen nicht möglich – der Umlauf ist bereits abgeschlossen.', 'error');
        }
        redirect('umlauf.php?id=' . $id);
    }

    if ($action === 'withdraw' && $u) {
        if (umlauf_withdraw($id, $me)) flash('Umlauf zurückgezogen – es wird kein Ergebnis ermittelt.', 'success');
        else flash('Zurückziehen nicht möglich.', 'error');
        redirect('umlauf.php?id=' . $id);
    }

    if ($action === 'finalize' && $u && can_admin()) {
        if (umlauf_finalize($id)) flash('Umlauf ausgewertet – das Ergebnis wurde an alle verschickt.', 'success');
        else flash('Auswerten nicht möglich – der Umlauf ist nicht (mehr) offen.', 'error');
        redirect('umlauf.php?id=' . $id);
    }

    if ($action === 'delete' && $u) {
        if (umlauf_delete($id, $me)) { flash('Umlauf gelöscht.', 'success'); redirect('umlauf.php'); }
        flash('Löschen dürfen nur Admins.', 'error');
        redirect('umlauf.php?id=' . $id);
    }
}

$id = (int)($_GET['id'] ?? 0);
$pollId = (int)($_GET['poll'] ?? 0);
$activeN = active_member_count();
$rules = umlauf_rules();

// Anhänge-Karte für beide Detailansichten (Download für alle; verwalten + „In Teams
// öffnen" nur Ersteller:in/Admin – der Download liefert bei Teams-Doks den Teams-Stand)
$ufileBlock = function (string $fkind, int $rid, bool $canEdit) {
    $files = umlauf_files_of($fkind, $rid);
    if (!$files && !$canEdit) return;
    ?>
    <div class="section-title"><i class="ti ti-paperclip"></i> Anhänge<?= $files ? ' <span class="count">' . count($files) . '</span>' : '' ?></div>
    <div class="card">
      <?php foreach ($files as $f): ?>
        <div class="btn-row" style="align-items:center;gap:.5rem;flex-wrap:wrap;margin:.25rem 0">
          <a class="info-file" href="download.php?ufile=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><i class="ti ti-file-download"></i> <span><?= h($f['orig_name']) ?></span> <span class="muted small">· <?= h(human_filesize((int)$f['size'])) ?></span></a>
          <?= mirror_open_button('ufile', (int)$f['id'], '', $canEdit) ?>
          <?php if ($canEdit): ?>
            <form method="post" style="margin:0" data-confirm="Diesen Anhang löschen?" data-confirm-danger data-confirm-ok="Löschen"<?= mirror_delete_check_attr('ufile', (int)$f['id']) ?>>
              <?= csrf_field() ?><input type="hidden" name="action" value="ufile_delete"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
              <button class="btn danger small" type="submit" title="Anhang löschen" aria-label="Anhang löschen"><i class="ti ti-trash"></i></button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($canEdit): ?>
        <form method="post" enctype="multipart/form-data" class="btn-row" style="align-items:center;gap:.6rem;flex-wrap:wrap;margin-top:<?= $files ? '.6rem' : '0' ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="ufile_add"><input type="hidden" name="fkind" value="<?= h($fkind) ?>"><input type="hidden" name="ref_id" value="<?= $rid ?>">
          <input type="file" name="docs[]" multiple>
          <?= ablage_pick_field('ufile') ?>
          <button class="btn secondary small" type="submit"><i class="ti ti-paperclip"></i> Anhängen</button>
        </form>
      <?php elseif (!$files): ?><p class="empty small" style="margin:0">Keine Anhänge.</p><?php endif; ?>
    </div>
<?php };

// ---------------------------------------------------------------- Detail: Abstimmung
if ($pollId) {
    $p = poll_get($pollId);
    if (!$p) { http_response_code(404); page_header('Nicht gefunden'); echo '<p>Abstimmung nicht gefunden.</p>'; page_footer(); exit; }
    $open = poll_open($p);
    $secret = !empty($p['secret']);
    $multi = !empty($p['multi']);
    $mandatory = !empty($p['mandatory']);
    $canManage = poll_can_manage($p, $me);
    $options = poll_options($pollId);
    $res = poll_results($pollId);
    $mine = $meId ? poll_my_votes($pollId, $meId) : [];
    $creator = member_get((int)$p['created_by']);
    $names = $secret ? [] : poll_vote_names($pollId);
    $hideTally = $open && $secret; // geheim: Zwischenstand bis zum Abschluss verborgen
    $topN = $res['counts'] ? max($res['counts']) : 0;

    page_header('Abstimmung – ' . $p['title']);
    ?>
    <p class="small"><a href="umlauf.php">‹ Alle Abstimmungen &amp; Umläufe</a></p>

    <div class="card event-hero">
      <div class="hero-head">
        <h1 class="event-title">
          <i class="ti ti-list-check" style="color:var(--petrol)"></i> <?= h($p['title']) ?>
          <?php if ((string)$p['status'] === 'closed'): ?><span class="badge badge-closed">beendet</span>
          <?php else: ?><span class="badge badge-event">läuft</span><?php endif; ?>
        </h1>
        <?= share_button('umlauf.php?poll=' . $pollId, 'Abstimmungs-Link teilen') ?>
      </div>
      <div class="event-facts">
        <?php if (trim((string)($p['deadline'] ?? '')) !== ''): ?><span class="fact<?= $open ? ' fact-accent' : '' ?>"><i class="ti ti-clock"></i> Abstimmen bis <?= h(fmt_slot((string)$p['deadline'], null)) ?></span><?php endif; ?>
        <span class="fact"><i class="ti ti-users"></i> <?= $res['voters'] ?> von <?= max($res['voters'], $activeN) ?> haben abgestimmt</span>
        <span class="fact"><i class="ti ti-<?= $multi ? 'checklist' : 'circle-dot' ?>"></i> <?= $multi ? 'Mehrfachauswahl' : 'eine Option' ?></span>
        <span class="fact"><i class="ti ti-<?= $secret ? 'eye-off' : 'eye' ?>"></i> <?= $secret ? 'geheim' : 'namentlich' ?></span>
        <span class="fact"><i class="ti ti-<?= $mandatory ? 'alert-triangle' : 'feather' ?>"></i> <?= $mandatory ? 'verpflichtend' : 'freiwillig' ?></span>
        <?php if ($creator): ?><span class="fact"><i class="ti ti-user"></i> gestartet von <?= member_link($creator) ?></span><?php endif; ?>
      </div>
      <?php if (trim((string)$p['description']) !== ''): ?><p class="event-desc" style="white-space:pre-line"><?= h($p['description']) ?></p><?php endif; ?>
    </div>

    <?php $ufileBlock('poll', $pollId, $canManage); ?>

    <?php if ($me && $open && $mandatory && !$mine): ?>
      <div class="attention" style="margin-bottom:1rem"><div class="section-title" style="margin-top:0"><i class="ti ti-alert-triangle"></i> Bitte abstimmen</div>
      <p class="note small" style="margin:0">Diese Abstimmung ist <strong>für alle verpflichtend</strong> – gib unten deine Stimme ab<?= trim((string)($p['deadline'] ?? '')) !== '' ? ' (bis ' . h(fmt_slot((string)$p['deadline'], null)) . ')' : '' ?>. Du kannst sie bis zum Abschluss jederzeit ändern.</p></div>
    <?php endif; ?>

    <?php if ($me && $open): ?>
      <div class="section-title" id="abstimmen"><i class="ti ti-checkbox"></i> Deine Stimme <span class="muted small" style="font-weight:400">– <?= $multi ? 'mehrere Optionen möglich' : 'genau eine Option' ?></span></div>
      <div class="card">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="poll_vote"><input type="hidden" name="poll_id" value="<?= $pollId ?>">
          <?php foreach ($options as $o): $oid = (int)$o['id']; ?>
            <label style="display:block;font-weight:400;margin:.3rem 0">
              <input type="<?= $multi ? 'checkbox' : 'radio' ?>" name="opt[]" value="<?= $oid ?>" <?= in_array($oid, $mine, true) ? 'checked' : '' ?><?= $multi ? '' : ' required' ?>>
              <?= h($o['label']) ?>
            </label>
          <?php endforeach; ?>
          <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> <?= $mine ? 'Stimme ändern' : 'Stimme abgeben' ?></button></div>
          <?php if ($secret): ?><p class="small muted" style="margin:.6rem 0 0"><i class="ti ti-eye-off"></i> Geheime Abstimmung: Es wird nur gezählt – niemand sieht, wie du gestimmt hast.</p><?php endif; ?>
        </form>
      </div>
    <?php endif; ?>

    <div class="section-title" id="ergebnis"><i class="ti ti-chart-bar"></i> <?= (string)$p['status'] === 'closed' ? 'Ergebnis' : 'Zwischenstand' ?></div>
    <div class="card">
      <?php if ($hideTally): ?>
        <p class="small" style="margin:0"><i class="ti ti-eye-off" style="color:var(--petrol)"></i> Geheime Abstimmung: Der Zwischenstand bleibt bis zum Abschluss verborgen. Bisher haben <strong><?= $res['voters'] ?> von <?= max($res['voters'], $activeN) ?></strong> abgestimmt.</p>
      <?php else: ?>
        <ul class="poll-results">
          <?php foreach ($options as $o): $oid = (int)$o['id']; $n = $res['counts'][$oid] ?? 0;
              $isLead = $n === $topN && $topN > 0; $vs = max(1, $res['voters']); ?>
            <li class="poll-opt<?= $isLead ? ' poll-lead' : '' ?>">
              <div class="poll-opt-head">
                <span class="poll-date"><?php if ($isLead): ?><i class="ti ti-crown" title="Aktuell die meisten Stimmen"></i> <?php endif; ?><?= h($o['label']) ?></span>
                <span class="poll-counts"><span class="pc pc-yes" title="Stimmen"><i class="ti ti-check"></i> <?= $n ?></span></span>
              </div>
              <div class="poll-bar" aria-hidden="true"><span class="pb-yes" style="width:<?= round($n / $vs * 100) ?>%"></span></div>
              <?php if (!$secret && !empty($names[$oid])): ?>
                <div class="poll-who small muted"><span><i class="ti ti-check" style="color:var(--green)"></i> <?= implode(', ', array_map('member_link', $names[$oid])) ?></span></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="small muted" style="margin:.4rem 0 0">Beteiligung: <?= $res['voters'] ?> von <?= max($res['voters'], $activeN) ?><?= $multi ? ' · Mehrfachauswahl (Stimmen können sich auf mehrere Optionen verteilen)' : '' ?></p>
      <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
      <details class="collapse-card">
      <summary class="section-title"><i class="ti ti-settings"></i> Abstimmung verwalten<i class="ti ti-chevron-down collapse-chev"></i></summary>
      <div class="card">
        <div class="btn-row" style="flex-wrap:wrap">
          <?php if ((string)$p['status'] === 'open'):
              // Der Zusatz gilt NUR, solange die Frist läuft – danach ist das Fehlen ein echtes Versäumnis.
              $frage = 'Die Abstimmung jetzt beenden? Das Ergebnis bleibt sichtbar.'
                     . (poll_open($p) ? ' Wer noch nicht abgestimmt hat, bekommt dafür KEINEN Abzug im Basis-Score – die Frist läuft ja noch.' : ''); ?>
            <form method="post" data-confirm="<?= h($frage) ?>" data-confirm-ok="Beenden"><?= csrf_field() ?><input type="hidden" name="action" value="poll_close"><input type="hidden" name="poll_id" value="<?= $pollId ?>"><button class="btn secondary" type="submit"><i class="ti ti-flag-check"></i> Abstimmung beenden</button></form>
          <?php endif; ?>
          <form method="post" data-confirm="Diese Abstimmung samt aller Stimmen endgültig löschen?" data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?><input type="hidden" name="action" value="poll_delete"><input type="hidden" name="poll_id" value="<?= $pollId ?>"><button class="btn danger" type="submit"><i class="ti ti-trash"></i> Löschen</button></form>
        </div>
        <p class="small muted" style="margin:.6rem 0 0">Beenden und Löschen können Ersteller:in und Admin<?= trim((string)($p['deadline'] ?? '')) === '' ? ' – ohne Frist läuft die Abstimmung, bis sie hier beendet wird' : '' ?>.</p>
      </div>
      </details>
    <?php endif; ?>
    <?php
    page_footer();
    exit;
}

// ---------------------------------------------------------------- Detail: Umlauf
if ($id) {
    $u = umlauf_get($id);
    if (!$u) { http_response_code(404); page_header('Nicht gefunden'); echo '<p>Umlauf nicht gefunden.</p>'; page_footer(); exit; }
    $open = umlauf_open($u);
    $secret = !empty($u['secret']);
    $canManage = umlauf_can_manage($u, $me);
    $isAdmin = $me && !empty($me['is_admin']);
    $t = umlauf_tally($id);
    $eligible = (string)$u['status'] === 'closed' && $u['eligible_count'] !== null ? (int)$u['eligible_count'] : $activeN;
    $myChoice = $meId ? umlauf_my_ballot($id, $meId) : null;
    $creator = member_get((int)$u['created_by']);
    // Namen je Wahl – nur bei namentlicher Sichtbarkeit; als Profil-Links (member_link kürzt selbst)
    $names = ['yes' => [], 'no' => [], 'abstain' => []];
    if (!$secret) foreach (umlauf_ballots($id) as $b) $names[(string)$b['choice']][] = member_link(['id' => (int)$b['member_id'], 'name' => (string)$b['name']]);

    page_header('Umlauf – ' . $u['title']);
    ?>
    <p class="small"><a href="umlauf.php">‹ Alle Umlaufverfahren</a></p>

    <div class="card event-hero">
      <div class="hero-head">
        <h1 class="event-title">
          <i class="ti ti-mailbox" style="color:var(--petrol)"></i> <?= h($u['title']) ?>
          <?php if ((string)$u['status'] === 'withdrawn'): ?><span class="badge badge-closed">zurückgezogen</span>
          <?php elseif ((string)$u['status'] === 'closed'): ?><span class="badge badge-closed"><?= h(umlauf_result_label($u['result'])) ?></span>
          <?php else: ?><span class="badge badge-event">läuft</span><?php endif; ?>
        </h1>
        <?= share_button('umlauf.php?id=' . $id, 'Umlauf-Link teilen') ?>
      </div>
      <div class="event-facts">
        <span class="fact<?= $open ? ' fact-accent' : '' ?>"><i class="ti ti-clock"></i> Abstimmen bis <?= h(fmt_slot((string)$u['deadline'], null)) ?></span>
        <span class="fact"><i class="ti ti-users"></i> <?= $t['total'] ?> von <?= max($t['total'], $eligible) ?> haben abgestimmt</span>
        <span class="fact"><i class="ti ti-scale"></i> <?= h($rules[(string)$u['rule']][0] ?? $u['rule']) ?><?= (int)$u['quorum_pct'] > 0 ? ', mind. ' . (int)$u['quorum_pct'] . ' % Beteiligung' : '' ?></span>
        <span class="fact"><i class="ti ti-<?= $secret ? 'eye-off' : 'eye' ?>"></i> <?= $secret ? 'geheim' : 'namentlich' ?></span>
        <?php if ($creator): ?><span class="fact"><i class="ti ti-user"></i> gestartet von <?= member_link($creator) ?></span><?php endif; ?>
      </div>
      <?php if (trim((string)$u['description']) !== ''): ?><p class="event-desc" style="white-space:pre-line"><?= h($u['description']) ?></p><?php endif; ?>
    </div>

    <?php $ufileBlock('umlauf', $id, $canManage); ?>

    <?php if ($me && $open && $myChoice === null): ?>
      <div class="attention" style="margin-bottom:1rem"><div class="section-title" style="margin-top:0"><i class="ti ti-alert-triangle"></i> Bitte abstimmen</div>
      <p class="note small" style="margin:0">Dieses Umlaufverfahren ist <strong>für alle verpflichtend</strong> – gib unten deine Stimme ab (bis <?= h(fmt_slot((string)$u['deadline'], null)) ?>). Du kannst sie bis zum Abschluss jederzeit ändern.</p></div>
    <?php endif; ?>

    <?php if ($me && $open): ?>
      <div class="section-title" id="abstimmen"><i class="ti ti-checkbox"></i> Deine Stimme</div>
      <div class="card">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="vote"><input type="hidden" name="id" value="<?= $id ?>">
          <span class="statuspick">
            <label><input type="radio" name="choice" value="yes" <?= $myChoice === 'yes' ? 'checked' : '' ?> required><span class="s-yes">Ja</span></label>
            <label><input type="radio" name="choice" value="no" <?= $myChoice === 'no' ? 'checked' : '' ?>><span class="s-no">Nein</span></label>
            <label><input type="radio" name="choice" value="abstain" <?= $myChoice === 'abstain' ? 'checked' : '' ?>><span class="s-maybe">Enthaltung</span></label>
          </span>
          <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit" data-puste="stimme"><i class="ti ti-device-floppy"></i> <?= $myChoice !== null ? 'Stimme ändern' : 'Stimme abgeben' ?></button></div>
          <?php if ($secret): ?><p class="small muted" style="margin:.6rem 0 0"><i class="ti ti-eye-off"></i> Geheime Abstimmung: Es wird nur gezählt – niemand sieht, wie du gestimmt hast.</p><?php endif; ?>
        </form>
      </div>
    <?php elseif ($me && (string)$u['status'] === 'open' && !$open): ?>
      <p class="small muted"><i class="ti ti-lock"></i> Die Frist ist abgelaufen – der Umlauf wird gerade ausgewertet.</p>
    <?php endif; ?>

    <div class="section-title" id="ergebnis"><i class="ti ti-chart-bar"></i> <?= (string)$u['status'] === 'closed' ? 'Ergebnis' : 'Zwischenstand' ?></div>
    <div class="card">
      <?php if ((string)$u['status'] === 'withdrawn'): ?>
        <p class="empty"><i class="ti ti-arrow-back-up"></i> Dieser Umlauf wurde zurückgezogen – es gibt kein Ergebnis.</p>
      <?php elseif ($open && $secret): ?>
        <p class="small" style="margin:0"><i class="ti ti-eye-off" style="color:var(--petrol)"></i> Geheime Abstimmung: Der Zwischenstand bleibt bis zum Abschluss verborgen. Bisher haben <strong><?= $t['total'] ?> von <?= max($t['total'], $eligible) ?></strong> abgestimmt.</p>
      <?php else: ?>
        <?php if ((string)$u['status'] === 'closed'): ?>
          <p style="margin:0 0 .7rem"><span class="pill <?= (string)$u['result'] === 'accepted' ? 'pill-ok' : ((string)$u['result'] === 'rejected' ? 'pill-bad' : 'pill-warn') ?>" style="font-size:1rem"><i class="ti ti-<?= (string)$u['result'] === 'accepted' ? 'check' : ((string)$u['result'] === 'rejected' ? 'x' : 'alert-triangle') ?>"></i> <?= h(ucfirst(umlauf_result_label($u['result']))) ?></span>
          <span class="small muted" style="margin-left:.5rem">ausgewertet am <?= h(fmt_slot(substr((string)$u['closed_at'], 0, 16), null)) ?></span></p>
        <?php endif; ?>
        <?php $tot = max(1, $t['total']); ?>
        <div class="poll-bar" aria-hidden="true" style="margin-bottom:.6rem">
          <span class="pb-yes" style="width:<?= round($t['yes'] / $tot * 100) ?>%"></span>
          <span class="pb-maybe" style="width:<?= round($t['abstain'] / $tot * 100) ?>%"></span>
          <span class="pb-no" style="width:<?= round($t['no'] / $tot * 100) ?>%"></span>
        </div>
        <p class="small" style="margin:0">
          <span class="pc pc-yes" title="Ja"><i class="ti ti-check"></i> Ja: <strong><?= $t['yes'] ?></strong></span>
          <span class="pc pc-no" style="margin-left:.8rem" title="Nein"><i class="ti ti-x"></i> Nein: <strong><?= $t['no'] ?></strong></span>
          <span class="pc pc-maybe" style="margin-left:.8rem" title="Enthaltung"><i class="ti ti-minus"></i> Enthaltung: <strong><?= $t['abstain'] ?></strong></span>
          <span class="muted" style="margin-left:.8rem">Beteiligung: <?= $t['total'] ?> von <?= max($t['total'], $eligible) ?></span>
        </p>
        <?php if (!$secret && ($names['yes'] || $names['no'] || $names['abstain'])): ?>
          <div class="poll-who small muted" style="margin-top:.6rem">
            <?php if ($names['yes']): ?><div><i class="ti ti-check" style="color:var(--green)"></i> <?= implode(', ', $names['yes']) ?></div><?php endif; ?>
            <?php if ($names['no']): ?><div><i class="ti ti-x" style="color:var(--red)"></i> <?= implode(', ', $names['no']) ?></div><?php endif; ?>
            <?php if ($names['abstain']): ?><div><i class="ti ti-minus" style="color:#b8860b"></i> <?= implode(', ', $names['abstain']) ?> (Enthaltung)</div><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (($canManage && (string)$u['status'] === 'open') || $isAdmin): ?>
      <details class="collapse-card">
      <summary class="section-title"><i class="ti ti-settings"></i> Umlauf verwalten<i class="ti ti-chevron-down collapse-chev"></i></summary>
      <div class="card">
        <div class="btn-row" style="flex-wrap:wrap">
          <?php if ($canManage && (string)$u['status'] === 'open'): ?>
            <form method="post" data-confirm="Diesen Umlauf zurückziehen? Es wird kein Ergebnis ermittelt." data-confirm-danger data-confirm-ok="Zurückziehen"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn secondary" type="submit"><i class="ti ti-arrow-back-up"></i> Zurückziehen</button></form>
          <?php endif; ?>
          <?php if (can_admin() && (string)$u['status'] === 'open'):
              $frageU = 'Den Umlauf jetzt mit den bisherigen Stimmen auswerten?'
                      . (umlauf_open($u) ? ' Wer noch nicht abgestimmt hat, bekommt dafür KEINEN Abzug im Basis-Score – die Frist läuft ja noch.' : ''); ?>
            <form method="post" data-confirm="<?= h($frageU) ?>" data-confirm-ok="Auswerten"><?= csrf_field() ?><input type="hidden" name="action" value="finalize"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn secondary" type="submit"><i class="ti ti-flag-check"></i> Jetzt auswerten</button></form>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
            <form method="post" data-confirm="Diesen Umlauf samt aller Stimmen endgültig löschen?" data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn danger" type="submit"><i class="ti ti-trash"></i> Löschen</button></form>
          <?php endif; ?>
        </div>
        <p class="small muted" style="margin:.6rem 0 0">Zurückziehen können Ersteller:in und Admin, solange der Umlauf läuft. „Jetzt auswerten" (Vorsitz/Admin) schließt vorzeitig mit den bisherigen Stimmen. Löschen ist Admins vorbehalten.</p>
      </div>
      </details>
    <?php endif; ?>
    <?php
    page_footer();
    exit;
}

// ---------------------------------------------------------------------- Liste
$all = umlauf_all();
$openList = array_values(array_filter($all, fn($u) => (string)$u['status'] === 'open'));
$doneList = array_values(array_filter($all, fn($u) => (string)$u['status'] !== 'open'));
$allPolls = poll_all();
$openPolls = array_values(array_filter($allPolls, fn($p) => (string)$p['status'] === 'open'));
$donePolls = array_values(array_filter($allPolls, fn($p) => (string)$p['status'] !== 'open'));

page_header('Abstimmungen & Umlaufverfahren');
?>
<div class="section-title" style="margin-top:0"><i class="ti ti-mailbox"></i> Abstimmungen &amp; Umlaufverfahren</div>
<div class="card">
  <p class="small muted" style="margin:0">Zwei Werkzeuge: Ein <strong>Umlaufbeschluss</strong> ist die <strong>verbindliche</strong> Beschlussfassung außerhalb von Sitzungen (Ja / Nein / Enthaltung, Annahme-Regel, für alle verpflichtend, automatische Auswertung zur Frist). Eine <strong>normale Abstimmung</strong> ist flexibler: eigene Frage mit <strong>eigenen Antwortoptionen</strong> (z. B. „Welches Motto fürs Sommerfest?"), Einzel- oder Mehrfachauswahl, freiwillig oder verpflichtend. Starten kann beides jede:r.</p>
</div>

<div class="duo">
  <div>
    <div class="section-title" style="margin-top:0"><i class="ti ti-progress-check"></i> Laufende Umlaufbeschlüsse</div>
<?php if (!$openList): ?>
  <div class="card"><p class="empty"><i class="ti ti-mailbox-off"></i> Gerade läuft kein Umlaufverfahren. Starte unten eines.</p></div>
<?php else: ?>
  <?php foreach ($openList as $u): $t = umlauf_tally((int)$u['id']); $mine = $meId ? umlauf_my_ballot((int)$u['id'], $meId) : null; ?>
    <div class="card hoverable stretch-card" style="margin-bottom:.7rem">
      <div class="hero-head" style="align-items:flex-start">
        <h3 style="margin:.1rem 0;font-size:1.05rem"><a class="tl-stretch" href="umlauf.php?id=<?= (int)$u['id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h($u['title']) ?></a></h3>
        <?php if ($mine !== null): ?><span class="pill pill-ok"><i class="ti ti-check"></i> abgestimmt</span><?php else: ?><span class="pill pill-important"><i class="ti ti-alert-triangle"></i> Stimme fehlt</span><?php endif; ?>
      </div>
      <p class="small muted" style="margin:.2rem 0 .5rem"><i class="ti ti-clock"></i> bis <?= h(fmt_slot((string)$u['deadline'], null)) ?> · <i class="ti ti-users"></i> <?= $t['total'] ?>/<?= max($t['total'], $activeN) ?> · <?= empty($u['secret']) ? 'namentlich' : 'geheim' ?> · <?= h($rules[(string)$u['rule']][0] ?? '') ?></p>
      <a class="btn small" href="umlauf.php?id=<?= (int)$u['id'] ?>"><i class="ti ti-checkbox"></i> <?= $mine !== null ? 'Ansehen / Stimme ändern' : 'Jetzt abstimmen' ?></a>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
  </div>
  <div>
    <div class="section-title" style="margin-top:0"><i class="ti ti-list-check"></i> Laufende Abstimmungen</div>
<?php if (!$openPolls): ?>
  <div class="card"><p class="empty"><i class="ti ti-clipboard-off"></i> Gerade läuft keine Abstimmung. Starte unten eine.</p></div>
<?php else: ?>
  <?php foreach ($openPolls as $p): $res = poll_results((int)$p['id']); $mineP = $meId ? poll_my_votes((int)$p['id'], $meId) : []; ?>
    <div class="card hoverable stretch-card" style="margin-bottom:.7rem">
      <div class="hero-head" style="align-items:flex-start">
        <h3 style="margin:.1rem 0;font-size:1.05rem"><a class="tl-stretch" href="umlauf.php?poll=<?= (int)$p['id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h($p['title']) ?></a></h3>
        <?php if ($mineP): ?><span class="pill pill-ok"><i class="ti ti-check"></i> abgestimmt</span>
        <?php elseif (!empty($p['mandatory'])): ?><span class="pill pill-important"><i class="ti ti-alert-triangle"></i> Stimme fehlt</span><?php endif; ?>
      </div>
      <p class="small muted" style="margin:.2rem 0 .5rem"><?php if (trim((string)($p['deadline'] ?? '')) !== ''): ?><i class="ti ti-clock"></i> bis <?= h(fmt_slot((string)$p['deadline'], null)) ?> · <?php endif; ?><i class="ti ti-users"></i> <?= $res['voters'] ?>/<?= max($res['voters'], $activeN) ?> · <?= empty($p['secret']) ? 'namentlich' : 'geheim' ?> · <?= empty($p['multi']) ? 'eine Option' : 'Mehrfachauswahl' ?> · <?= empty($p['mandatory']) ? 'freiwillig' : 'verpflichtend' ?></p>
      <a class="btn small" href="umlauf.php?poll=<?= (int)$p['id'] ?>"><i class="ti ti-checkbox"></i> <?= $mineP ? 'Ansehen / Stimme ändern' : 'Jetzt abstimmen' ?></a>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

  </div>
</div>

<div class="section-title" id="neu"><i class="ti ti-plus"></i> Neu starten</div>
<div class="btn-row" style="flex-wrap:wrap;gap:.6rem;margin-bottom:2rem">
  <button type="button" class="btn" onclick="document.getElementById('dlgPoll').showModal()"><i class="ti ti-list-check"></i> Neue Abstimmung</button>
  <button type="button" class="btn secondary" onclick="document.getElementById('dlgUmlauf').showModal()"><i class="ti ti-mailbox"></i> Neuer Umlaufbeschluss</button>
</div>

<dialog id="dlgPoll" class="fb-modal fb-form">
  <div class="fb-form-head"><h3><i class="ti ti-list-check" style="color:var(--petrol)"></i> Neue Abstimmung</h3><button type="button" class="fb-x" onclick="this.closest('dialog').close()" aria-label="Schließen"><i class="ti ti-x"></i></button></div>
  <p class="small muted" style="margin-top:0">Für alles, was kein förmlicher Beschluss sein muss – vom Sommerfest-Motto bis zur Meinungsabfrage. <strong>Alle Mitglieder</strong> werden beim Start benachrichtigt.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="poll_create">
    <label for="p_title">Frage / Titel</label>
    <input type="text" name="title" id="p_title" required maxlength="200" placeholder="z. B. Welches Motto fürs Sommerfest?">
    <label for="p_desc">Details (optional)</label>
    <textarea name="description" id="p_desc" rows="3"></textarea>
    <label for="p_options">Antwortoptionen <span class="muted small">– eine pro Zeile, mindestens zwei</span></label>
    <textarea name="options" id="p_options" rows="4" required placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
    <label for="p_deadline">Abstimmen bis <span class="muted small">– optional; ohne Frist läuft die Abstimmung, bis du sie beendest</span></label>
    <input type="text" class="fp-datetime" name="deadline" id="p_deadline" placeholder="Datum &amp; Uhrzeit wählen (optional)">
    <label>Auswahl</label>
    <span class="statuspick">
      <label><input type="radio" name="mode" value="single" checked><span class="s-yes">Eine Option</span></label>
      <label><input type="radio" name="mode" value="multi"><span class="s-maybe">Mehrfachauswahl</span></label>
    </span>
    <label style="margin-top:.8rem">Sichtbarkeit der Stimmen</label>
    <span class="statuspick">
      <label><input type="radio" name="visibility" value="open" checked><span class="s-yes">Namentlich</span></label>
      <label><input type="radio" name="visibility" value="secret"><span class="s-maybe">Geheim</span></label>
    </span>
    <label style="margin-top:.8rem">Verbindlichkeit</label>
    <span class="statuspick">
      <label><input type="radio" name="binding" value="optional" checked><span class="s-yes">Freiwillig</span></label>
      <label><input type="radio" name="binding" value="mandatory"><span class="s-no">Verpflichtend</span></label>
    </span>
    <p class="small muted" style="margin:.4rem 0 0">Verpflichtend = Pflicht-Aufgabe im Dashboard + tägliche Erinnerung an Säumige (braucht eine Frist); Abschluss automatisch, sobald alle abgestimmt haben. Freiwillig = nur die Start-Benachrichtigung.</p>
    <label for="p_docs" style="margin-top:.8rem">Anhänge <span class="muted small">– optional, mehrere möglich</span></label>
    <input type="file" name="docs[]" id="p_docs" multiple>
    <?= ablage_pick_field('ufile') ?>
    <div class="btn-row" style="margin-top:.9rem;justify-content:flex-end"><button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Abbrechen</button><button class="btn" type="submit"><i class="ti ti-list-check"></i> Abstimmung starten</button></div>
  </form>
</dialog>

<dialog id="dlgUmlauf" class="fb-modal fb-form">
  <div class="fb-form-head"><h3><i class="ti ti-mailbox" style="color:var(--petrol)"></i> Neuer Umlaufbeschluss</h3><button type="button" class="fb-x" onclick="this.closest('dialog').close()" aria-label="Schließen"><i class="ti ti-x"></i></button></div>
  <p class="small muted" style="margin-top:0">Beschreib kurz, worüber abgestimmt wird, und setz eine Frist. <strong>Alle Mitglieder</strong> werden sofort benachrichtigt und im Dashboard ans Abstimmen erinnert.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label for="u_title">Titel / Beschlussvorschlag</label>
    <input type="text" name="title" id="u_title" required maxlength="200" placeholder="z. B. Anschaffung einer neuen Kaffeemaschine (max. 150 €)">
    <label for="u_desc">Begründung / Details (optional)</label>
    <textarea name="description" id="u_desc" rows="4" placeholder="Worum geht es, warum, was kostet es …"></textarea>
    <label for="u_deadline">Abstimmen bis (Frist)</label>
    <input type="text" class="fp-datetime" name="deadline" id="u_deadline" required placeholder="Datum &amp; Uhrzeit wählen">
    <label>Sichtbarkeit der Stimmen</label>
    <span class="statuspick">
      <label><input type="radio" name="visibility" value="open" checked><span class="s-yes">Namentlich</span></label>
      <label><input type="radio" name="visibility" value="secret"><span class="s-maybe">Geheim</span></label>
    </span>
    <label style="margin-top:.8rem">Angenommen bei …</label>
    <?php foreach ($rules as $rk => [$rLabel, $rDesc]): ?>
      <label class="small" style="display:block;font-weight:400;margin:.15rem 0"><input type="radio" name="rule" value="<?= h($rk) ?>" <?= $rk === 'simple' ? 'checked' : '' ?>> <strong><?= h($rLabel) ?></strong> – <?= h($rDesc) ?></label>
    <?php endforeach; ?>
    <label for="u_quorum" style="margin-top:.8rem">Mindestbeteiligung</label>
    <select name="quorum_pct" id="u_quorum">
      <option value="0">keine – jedes Ergebnis zählt</option>
      <option value="50">mindestens die Hälfte muss abstimmen (50 %)</option>
      <option value="67">mindestens zwei Drittel müssen abstimmen (67 %)</option>
      <option value="75">mindestens drei Viertel müssen abstimmen (75 %)</option>
    </select>
    <label for="u_docs" style="margin-top:.8rem">Anhänge <span class="muted small">– optional, mehrere möglich (z. B. der Beschlussvorschlag als Dok)</span></label>
    <input type="file" name="docs[]" id="u_docs" multiple>
    <?= ablage_pick_field('ufile') ?>
    <div class="btn-row" style="margin-top:.9rem;justify-content:flex-end"><button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Abbrechen</button><button class="btn" type="submit"><i class="ti ti-mailbox"></i> Umlauf starten</button></div>
  </form>
</dialog>

<div class="duo">
  <div>
    <div class="section-title" style="margin-top:0"><i class="ti ti-archive"></i> Abgeschlossene Umläufe</div>
<?php if (!$doneList): ?>
  <div class="card"><p class="empty"><i class="ti ti-archive-off"></i> Noch keine abgeschlossenen Umläufe.</p></div>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead><tr><th>Beschluss</th><th>Frist</th><th style="text-align:right">Ergebnis</th></tr></thead>
      <tbody>
      <?php foreach ($doneList as $u): ?>
        <tr>
          <td><a href="umlauf.php?id=<?= (int)$u['id'] ?>"><strong><?= h($u['title']) ?></strong></a></td>
          <td class="small muted" style="white-space:nowrap"><?= h(fmt_date(substr((string)$u['deadline'], 0, 10))) ?></td>
          <td style="text-align:right">
            <?php if ((string)$u['status'] === 'withdrawn'): ?><span class="pill"><i class="ti ti-arrow-back-up"></i> zurückgezogen</span>
            <?php elseif ((string)$u['result'] === 'accepted'): ?><span class="pill pill-ok"><i class="ti ti-check"></i> angenommen</span>
            <?php elseif ((string)$u['result'] === 'rejected'): ?><span class="pill pill-bad"><i class="ti ti-x"></i> abgelehnt</span>
            <?php else: ?><span class="pill pill-warn"><i class="ti ti-alert-triangle"></i> ungültig</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
  </div>
  <div>
    <div class="section-title" style="margin-top:0"><i class="ti ti-archive"></i> Beendete Abstimmungen</div>
<?php if (!$donePolls): ?>
  <div class="card"><p class="empty"><i class="ti ti-archive-off"></i> Noch keine beendeten Abstimmungen.</p></div>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead><tr><th>Abstimmung</th><th>Beendet</th><th style="text-align:right">Vorn</th></tr></thead>
      <tbody>
      <?php foreach ($donePolls as $p): $res = poll_results((int)$p['id']);
          $top = []; $topN = -1;
          foreach (poll_options((int)$p['id']) as $o) {
              $n = $res['counts'][(int)$o['id']] ?? 0;
              if ($n > $topN) { $top = [(string)$o['label']]; $topN = $n; }
              elseif ($n === $topN) $top[] = (string)$o['label'];
          } ?>
        <tr>
          <td><a href="umlauf.php?poll=<?= (int)$p['id'] ?>"><strong><?= h($p['title']) ?></strong></a></td>
          <td class="small muted" style="white-space:nowrap"><?= h(fmt_date(substr((string)($p['closed_at'] ?? $p['created_at']), 0, 10))) ?></td>
          <td style="text-align:right" class="small"><?= $topN > 0 ? '<span class="pill pill-ok"><i class="ti ti-crown"></i> ' . h(implode(' / ', $top)) . ' (' . $topN . ')</span>' : '<span class="muted">keine Stimmen</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
  </div>
</div>
<?php
page_footer();
