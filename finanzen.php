<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;

/** Hochgeladene receipts[]-Dateien an Einreichung/Nachricht hängen → [Anzahl, Fehlerliste]. */
function collect_receipt_uploads(int $claimId, ?int $commentId = null): array
{
    $n = 0;
    $errs = [];
    if (!empty($_FILES['receipts']) && is_array($_FILES['receipts']['name'])) {
        foreach (array_keys($_FILES['receipts']['name']) as $k) {
            $f = [
                'name' => $_FILES['receipts']['name'][$k], 'type' => $_FILES['receipts']['type'][$k],
                'tmp_name' => $_FILES['receipts']['tmp_name'][$k], 'error' => $_FILES['receipts']['error'][$k],
                'size' => $_FILES['receipts']['size'][$k],
            ];
            $e = expense_file_add($claimId, $f, $commentId);
            if ($e !== null) $errs[] = $f['name'] . ': ' . $e;
            elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $n++;
        }
    }
    return [$n, $errs];
}

// Ausgefülltes Belegblatt (.docx) herunterladen – Einreicher:in selbst oder Finanzen/Vorsitz/Admin
if (isset($_GET['dl'])) {
    $c = expense_claim_get((int)$_GET['dl']);
    if (!$c || (!can_finance() && (int)$c['member_id'] !== $meId)) {
        flash('Belegblatt nicht gefunden oder keine Berechtigung.', 'error');
        redirect('finanzen.php');
    }
    if (!class_exists('ZipArchive')) { flash('Word-Export nicht möglich (ZipArchive fehlt auf dem Server).', 'error'); redirect('finanzen.php'); }
    $bin = expense_claim_docx($c);
    $fn = expense_claim_filename($c);
    file_delivery_headers('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $fn, strlen($bin));
    echo $bin;
    exit;
}

// Ausgefüllte Auszahlungsaufforderung (.docx) – nur Finanzen/Vorsitz/Admin, sie enthält IBANs
if (isset($_GET['sdl'])) {
    if (!can_finance()) { flash('Nur Finanzen darf das herunterladen.', 'error'); redirect('finanzen.php'); }
    $c = stupa_claim_get((int)$_GET['sdl']);
    if (!$c) { flash('Auszahlungsaufforderung nicht gefunden.', 'error'); redirect('finanzen.php#stupa'); }
    if (!class_exists('ZipArchive')) { flash('Word-Export nicht möglich (ZipArchive fehlt auf dem Server).', 'error'); redirect('finanzen.php#stupa'); }
    $bin = stupa_auszahlung_docx($c);
    $fn = stupa_auszahlung_filename($c);
    file_delivery_headers('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $fn, strlen($bin));
    echo $bin;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'submit_claim' || $action === 'claim_update') {
        if (!$me) { flash('Mit dem Technik-Login können keine Belegblätter eingereicht werden.', 'error'); redirect('finanzen.php'); }
        // Beim Ändern zuerst prüfen, ob das überhaupt erlaubt ist
        $editC = $action === 'claim_update' ? expense_claim_get((int)($_POST['id'] ?? 0)) : null;
        if ($action === 'claim_update' && !expense_can_edit($editC, $me)) {
            flash('Belegblatt nicht gefunden oder keine Berechtigung.', 'error');
            redirect('finanzen.php');
        }
        $data = [
            'name'   => trim((string)($_POST['name'] ?? '')),
            'street' => trim((string)($_POST['street'] ?? '')),
            'city'   => trim((string)($_POST['city'] ?? '')),
            'bank'   => trim((string)($_POST['bank'] ?? '')),
            'bic'    => trim((string)($_POST['bic'] ?? '')),
            'iban'   => trim((string)($_POST['iban'] ?? '')),
            'source' => in_array($_POST['source'] ?? '', ['asta', 'stupa', 'projekt'], true) ? (string)$_POST['source'] : 'asta',
            'source_note' => trim((string)($_POST['source_note'] ?? '')),
            'purpose' => trim((string)($_POST['purpose'] ?? '')),
            // Nur Finanzen darf ein Blatt als „stellvertretend erfasst" kennzeichnen
            'by_finance' => can_finance() && !empty($_POST['by_finance']) ? 1 : 0,
        ];
        // Positionen einsammeln (parallele Arrays aus dem Formular)
        $items = [];
        $err = '';
        $ds = (array)($_POST['item_d'] ?? []);
        $ss = (array)($_POST['item_s'] ?? []);
        $bs = (array)($_POST['item_b'] ?? []);
        foreach ($ss as $k => $s) {
            $s = trim((string)$s);
            $bRaw = trim((string)($bs[$k] ?? ''));
            $d = trim((string)($ds[$k] ?? ''));
            if ($s === '' && $bRaw === '') continue; // leere Zeile
            $b = euro_parse($bRaw);
            if ($s === '') { $err = 'Bitte bei jeder Position eine Sachbeschreibung angeben.'; break; }
            if ($b === null || $b <= 0) { $err = 'Bitte bei jeder Position einen gültigen Betrag angeben (z. B. 12,34).'; break; }
            $items[] = ['d' => $d, 's' => $s, 'b' => $b];
        }
        if ($err === '' && $data['name'] === '') $err = 'Bitte Name, Vorname angeben (wie auf dem Belegblatt).';
        if ($err === '' && $data['iban'] === '') $err = 'Bitte die IBAN angeben – ohne sie kann nichts überwiesen werden.';
        if ($err === '' && $data['purpose'] === '') $err = 'Bitte angeben, wofür die Auslage war (Veranstaltung/Projekt).';
        if ($err === '' && $data['source'] === 'projekt' && $data['source_note'] === '') $err = 'Bitte kurz eintragen, welches Projekt/Sonstiges gemeint ist.';
        if ($err === '' && !$items) $err = 'Bitte mindestens eine Beleg-Position eintragen.';
        if ($err !== '') {
            flash($err, 'error');
        } else {
            if ($editC) {
                $id = (int)$editC['id'];
                expense_claim_update($id, $data, $items);
            } else {
                $id = expense_claim_add($meId, $data, $items);
            }
            // Angeheftete Belege (Fotos/PDF) speichern – Fehler einzelner Dateien kippen die Einreichung nicht
            [$nFiles, $fileErrs] = collect_receipt_uploads($id);
            $total = 0; foreach ($items as $it) $total += $it['b'];
            flash(($editC ? 'Belegblatt geändert (' : 'Belegblatt eingereicht (') . count($items) . ' Position(en), ' . euro($total) . ($nFiles ? ', ' . $nFiles . ' Beleg(e) angeheftet' : '') . ').' . ($editC ? '' : ' Finanzen kümmert sich um die Erstattung.'), 'success');
            // Archivieren in der Nextcloud (falls eingeschaltet). Ein Fehler dabei darf die
            // Einreichung NICHT kippen – sie ist gespeichert, das Archiv holt der nächste Lauf nach.
            $ncErr = null;
            if (expense_nc_enabled() && !expense_nc_sync($id, $ncErr) && $ncErr) {
                app_log_error('Beleg-Archivierung (Belegblatt ' . $id . '): ' . $ncErr);
                if (can_finance()) flash('Hinweis: Ablage in der Nextcloud hat nicht geklappt – ' . $ncErr, 'error');
            }
            if ($fileErrs) flash('Nicht alle Belege konnten angeheftet werden: ' . implode(' · ', $fileErrs), 'error');
            // Nur die ERSTE Einreichung wird gefeiert – eine Korrektur ist kein Moment.
            if (!$editC) puste_merken('beleg');
            if ($editC) redirect('finanzen.php?claim=' . $id);
        }
        // Nach einem Fehler zurück ins Bearbeiten-Formular, sonst auf die Übersicht
        redirect($editC ? 'finanzen.php?edit=' . (int)$editC['id'] : 'finanzen.php');
    }

    if ($action === 'stupa_reply') {
        if (!can_finance()) { flash('Nur Finanzen darf hier antworten.', 'error'); redirect('finanzen.php'); }
        $sid = (int)($_POST['claim_id'] ?? 0);
        $claim = stupa_claim_get($sid);
        $body = trim((string)($_POST['body'] ?? ''));
        if ($claim && $body !== '') {
            stupa_comment_add($sid, false, $meId, $body);
            // Das Präsidium hat kein Dashboard und kein Push – Mail ist der einzige Weg dorthin
            stupa_notify_presidium($claim, 'Rückfrage zur Auszahlung „' . stupa_claim_label((int)$claim['id']) . '"',
                ($me ? $me['name'] : 'Finanzen') . " schreibt:\n\n" . $body);
            flash('Nachricht ans Präsidium geschickt (per E-Mail).', 'success');
        } else {
            flash('Bitte eine Nachricht schreiben.', 'error');
        }
        redirect('finanzen.php#stupa');
    }

    if ($action === 'stupa_done') {
        if (!can_finance()) { flash('Nur Finanzen darf das abhaken.', 'error'); redirect('finanzen.php'); }
        $sid = (int)($_POST['id'] ?? 0);
        if (stupa_claim_get($sid)) {
            $fertig = ($_POST['done'] ?? '') === '1';
            stupa_claim_set_done($sid, $fertig, $meId);
            $claim = stupa_claim_get($sid);
            // Nur wenn das Präsidium beim Einreichen das Häkchen gesetzt hat
            if ($fertig && $claim && !empty($claim['notify_done'])) {
                stupa_notify_presidium($claim, 'Erledigt: „' . stupa_claim_label((int)$claim['id']) . '"',
                    'Die Auszahlung über ' . euro(stupa_claim_total((int)$claim['id'])) . ' ist erledigt.');
            }
            flash($fertig ? 'Auszahlung als erledigt markiert.' : 'Wieder als offen markiert.', 'success');
        }
        redirect('finanzen.php#stupa');
    }

    if ($action === 'claim_delete') {
        $c = expense_claim_get((int)($_POST['id'] ?? 0));
        if (!expense_can_edit($c, $me)) {
            flash('Belegblatt nicht gefunden oder keine Berechtigung.', 'error');
            redirect('finanzen.php');
        }
        // Häkchen im Lösch-Dialog: soll auch das Archiv in der Nextcloud weg?
        expense_claim_delete((int)$c['id'], !empty($_POST['nc_too']));
        flash('Belegblatt gelöscht' . (!empty($_POST['nc_too'])
            ? ' – auch die Dateien in der Nextcloud (sie liegen dort im Papierkorb).'
            : '. Die Dateien in der Nextcloud bleiben als Archiv erhalten.'), 'success');
        redirect('finanzen.php');
    }

    if ($action === 'add_comment') {
        $c = expense_claim_get((int)($_POST['claim_id'] ?? 0));
        $isOwner = $c && $me && (int)$c['member_id'] === $meId;
        if (!$c || (!$isOwner && !can_finance())) {
            flash('Belegblatt nicht gefunden oder keine Berechtigung.', 'error');
            redirect('finanzen.php');
        }
        $body = trim((string)($_POST['body'] ?? ''));
        $hasFiles = !empty($_FILES['receipts']['name'][0] ?? '');
        if ($body === '' && !$hasFiles) {
            flash('Bitte eine Nachricht schreiben (oder eine Datei anhängen).', 'error');
            redirect('finanzen.php?claim=' . (int)$c['id']);
        }
        // Finanzen/Vorsitz/Admin schreiben „als Finanzen" – außer es ist das eigene Belegblatt
        $fromFinance = !$isOwner && can_finance();
        $cid = expense_comment_add((int)$c['id'], $meId, $fromFinance, $body);
        [$nFiles, $fileErrs] = collect_receipt_uploads((int)$c['id'], $cid);
        // Gegenseite benachrichtigen (Dashboard + ggf. Push, Kategorie „Finanzen & Auslagen")
        $excerpt = mb_strlen($body) > 140 ? mb_substr($body, 0, 140) . '…' : $body;
        if ($excerpt === '') $excerpt = $nFiles . ' Datei(en) angehängt.';
        if ($fromFinance) {
            dm_send((int)$c['member_id'], 'Finanzen',
                'Zu deinem Belegblatt „' . $c['purpose'] . '": ' . $excerpt . ' – Antworten kannst du auf der Finanzen-Seite.',
                null, false, false, 'dm_finanzen');
        } else {
            $finMembers = db()->query("SELECT id FROM members WHERE active = 1 AND role = 'finanzen'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($finMembers as $fid) {
                if ((int)$fid === $meId) continue;
                dm_send((int)$fid, $me ? (string)$me['name'] : 'Mitglied',
                    'Antwort zum Belegblatt „' . $c['purpose'] . '" von ' . $c['name'] . ': ' . $excerpt,
                    null, false, false, 'dm_finanzen');
            }
        }
        flash('Nachricht gespeichert' . ($nFiles ? ' (' . $nFiles . ' Datei(en) angehängt)' : '') . '.', 'success');
        if (!empty($fileErrs)) flash('Nicht alle Dateien konnten angehängt werden: ' . implode(' · ', $fileErrs), 'error');
        redirect('finanzen.php?claim=' . (int)$c['id']);
    }

    if ($action === 'set_done' && can_finance()) {
        $c = expense_claim_get((int)($_POST['id'] ?? 0));
        if ($c) {
            $done = ($_POST['done'] ?? '') === '1';
            expense_claim_set_done((int)$c['id'], $done, $meId);
            flash($done
                ? 'Belegblatt von ' . $c['name'] . ' als erledigt markiert (' . euro((int)$c['total_cents']) . ').'
                : 'Belegblatt von ' . $c['name'] . ' wieder geöffnet.', 'success');
        }
        redirect('finanzen.php');
    }
}

// Die Felder starten LEER (nichts Altes vorausgefüllt). Nur der eigene Name wird angeboten –
// Stammliste hat „Vorname Nachname", das Belegblatt will „Nachname, Vorname". Die Kontaktdaten
// der letzten Einreichung gibt es optional per Klick („übernehmen"-Knopf im Formular).
$fallbackName = trim((string)($me['name'] ?? ''));
if ($fallbackName !== '' && !str_contains($fallbackName, ',') && str_contains($fallbackName, ' ')) {
    $p = preg_split('/\s+/', $fallbackName);
    $fallbackName = array_pop($p) . ', ' . implode(' ', $p);
}
$last = $me ? expense_claim_last_of($meId) : null;
$lastPref = $last ? [
    'name' => (string)$last['name'], 'street' => (string)$last['street'], 'city' => (string)$last['city'],
    'bank' => (string)$last['bank'], 'bic' => (string)$last['bic'], 'iban' => (string)$last['iban'],
] : null;
$mine = $me ? expense_claims_of($meId) : [];
$openClaims = can_finance() ? expense_claims_open() : [];
$doneClaims = can_finance() ? expense_claims_done() : [];

// --- Detailansicht: ein Belegblatt mit Nachrichten-Verlauf (?claim=ID) ---
if (isset($_GET['claim'])) {
    $vc = expense_claim_get((int)$_GET['claim']);
    $isOwner = $vc && $me && (int)$vc['member_id'] === $meId;
    if (!$vc || (!$isOwner && !can_finance())) {
        flash('Belegblatt nicht gefunden oder keine Berechtigung.', 'error');
        redirect('finanzen.php');
    }
    $items = expense_items($vc);
    $efs = expense_files_of((int)$vc['id']);
    $comments = expense_comments_of((int)$vc['id']);
    $srcLbl = ['asta' => 'AStA', 'stupa' => 'StuPa', 'projekt' => 'Projekt/Sonstiges'][(string)$vc['source']] ?? $vc['source'];
    if ((string)$vc['source'] === 'projekt' && $vc['source_note'] !== '') $srcLbl .= ' – ' . $vc['source_note'];
    page_header('Belegblatt');
    ?>
    <p class="small"><a href="finanzen.php">‹ Finanzen</a></p>
    <h1><i class="ti ti-receipt-2" style="color:var(--petrol)"></i> Belegblatt von <?= h($vc['name']) ?></h1>

    <div class="card">
      <div style="display:flex;gap:.5rem 1.2rem;flex-wrap:wrap;align-items:center">
        <span class="pill <?= $vc['status'] === 'done' ? 'pill-ok' : 'pill-info' ?>"><i class="ti <?= $vc['status'] === 'done' ? 'ti-check' : 'ti-hourglass' ?>"></i> <?= $vc['status'] === 'done' ? 'erstattet' : 'in Bearbeitung' ?></span>
        <span class="small muted"><i class="ti ti-calendar"></i> Eingereicht <?= h(fmt_date(substr((string)$vc['created_at'], 0, 10))) ?></span>
        <span class="small muted"><i class="ti ti-building-bank"></i> Beantragt von <?= h($srcLbl) ?></span>
        <span style="font-weight:800;margin-left:auto"><?= euro((int)$vc['total_cents']) ?></span>
      </div>
      <p class="small" style="margin:.5rem 0 0"><strong>Für:</strong> <?= h($vc['purpose']) ?></p>
      <table class="list pos-list" style="margin-top:.5rem">
        <thead><tr><th></th><th>Datum</th><th>Sachbeschreibung</th><th style="text-align:right">Betrag</th></tr></thead>
        <tbody>
        <?php foreach (array_values($items) as $k => $it): ?>
          <tr>
            <td class="muted small"><?= $k + 1 ?></td>
            <td class="small" style="white-space:nowrap"><?= ($t = strtotime((string)($it['d'] ?? ''))) ? date('d.m.Y', $t) : '—' ?></td>
            <td class="small"><?= h((string)($it['s'] ?? '')) ?></td>
            <td style="text-align:right;white-space:nowrap"><?= euro((int)($it['b'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($efs): ?>
        <p class="small" style="margin:.6rem 0 0"><i class="ti ti-paperclip" style="color:var(--petrol)"></i>
          <?= implode(' · ', array_map(fn($ef) => '<a href="download.php?efile=' . (int)$ef['id'] . '" target="_blank" rel="noopener">' . h($ef['orig_name']) . '</a>', $efs)) ?></p>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:.8rem">
        <?= expense_origin_badge($vc) ?>
        <a class="btn secondary small" href="finanzen.php?dl=<?= (int)$vc['id'] ?>" target="_blank" rel="noopener"><i class="ti ti-download"></i> Belegblatt (.docx)</a>
        <?php if (can_finance()): ?>
          <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="set_done"><input type="hidden" name="id" value="<?= (int)$vc['id'] ?>"><input type="hidden" name="done" value="<?= $vc['status'] === 'done' ? 0 : 1 ?>">
            <button class="btn <?= $vc['status'] === 'done' ? 'secondary ' : '' ?>small" type="submit"><i class="ti <?= $vc['status'] === 'done' ? 'ti-rotate-2' : 'ti-check' ?>"></i> <?= $vc['status'] === 'done' ? 'Wieder öffnen' : 'Erledigt' ?></button>
          </form>
        <?php endif; ?>
        <?php if (expense_can_edit($vc, $me)): ?>
          <a class="btn secondary small" href="finanzen.php?edit=<?= (int)$vc['id'] ?>"><i class="ti ti-pencil"></i> Bearbeiten</a>
          <form method="post" style="margin:0"
                data-confirm="Dieses Belegblatt wirklich löschen? Nachrichten und angeheftete Belege verschwinden mit."
                data-confirm-danger data-confirm-ok="Löschen"<?= expense_delete_check_attr((int)$vc['id']) ?>>
            <?= csrf_field() ?><input type="hidden" name="action" value="claim_delete"><input type="hidden" name="id" value="<?= (int)$vc['id'] ?>">
            <button class="btn danger small" type="submit"><i class="ti ti-trash"></i> Löschen</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="section-title"><i class="ti ti-messages"></i> Nachrichten<?= $comments ? ' <span class="badge-count">' . count($comments) . '</span>' : '' ?></div>
    <?php if (!$comments): ?>
      <div class="card"><p class="empty"><i class="ti ti-message-2"></i> Noch keine Nachrichten – Rückfragen und Antworten zu dieser Einreichung landen hier.</p></div>
    <?php endif; ?>
    <?php foreach ($comments as $cm): $cfs = expense_comment_files((int)$cm['id']); $fin = !empty($cm['from_finance']); ?>
      <div class="card" style="<?= $fin ? 'border-left:3px solid var(--petrol)' : '' ?>">
        <div class="small" style="display:flex;gap:.5rem;flex-wrap:wrap">
          <strong><i class="ti <?= $fin ? 'ti-coins' : 'ti-user' ?>"></i> <?= $fin ? 'Finanzen' . ($cm['author_name'] ? ' (' . h(first_name((string)$cm['author_name'])) . ')' : '') : h((string)($cm['author_name'] ?: $vc['name'])) ?></strong>
          <span class="muted" style="margin-left:auto"><?= ($t = strtotime((string)$cm['created_at'])) ? date('d.m.Y H:i', $t) : '' ?></span>
        </div>
        <?php if (trim((string)$cm['body']) !== ''): ?><p style="margin:.4rem 0 0;white-space:pre-wrap"><?= h($cm['body']) ?></p><?php endif; ?>
        <?php if ($cfs): ?>
          <p class="small" style="margin:.4rem 0 0"><i class="ti ti-paperclip" style="color:var(--petrol)"></i>
            <?= implode(' · ', array_map(fn($ef) => '<a href="download.php?efile=' . (int)$ef['id'] . '" target="_blank" rel="noopener">' . h($ef['orig_name']) . '</a>', $cfs)) ?></p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="card">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_comment"><input type="hidden" name="claim_id" value="<?= (int)$vc['id'] ?>">
        <?php $ownerM = member_get((int)$vc['member_id']); $ownerFirst = $ownerM ? first_name((string)$ownerM['name']) : 'die Person'; ?>
        <label for="cm_body"><?= can_finance() && !$isOwner ? 'Rückmeldung an ' . h($ownerFirst) . ' schreiben' : 'Antwort an Finanzen schreiben' ?></label>
        <textarea name="body" id="cm_body" rows="3" placeholder="z. B. Rückfrage zur Quittung Nr. 2 …"></textarea>
        <div style="margin-top:.5rem">
          <label for="cm_files" class="small"><i class="ti ti-paperclip"></i> Dateien anhängen (Fotos/PDF) – <strong>fehlende Belege kannst du hier nachreichen</strong></label>
          <input type="file" id="cm_files" name="receipts[]" multiple accept=".pdf,.png,.jpg,.jpeg,.heic,.heif,.webp,.gif,image/*,application/pdf">
        </div>
        <div class="btn-row" style="margin-top:.7rem"><button class="btn small" type="submit"><i class="ti ti-send"></i> Senden</button></div>
      </form>
      <p class="small muted" style="margin:.5rem 0 0">Die Gegenseite bekommt eine Dashboard-Nachricht (und je nach Einstellung einen Push). Angehängte Doks werden <strong>maximal 30 Tage</strong> gespeichert und sind danach nicht mehr herunterladbar.</p>
    </div>
    <?php
    page_footer();
    exit;
}

page_header('Finanzen');
?>
<div class="events-toolbar">
  <h1><i class="ti ti-coins" style="color:var(--petrol)"></i> Finanzen</h1>
</div>

<?php if (can_finance()): ?>
  <div class="section-title" id="fin-open"><i class="ti ti-inbox"></i> Offene Belegblätter<?= $openClaims ? ' <span class="pill pill-warn">' . count($openClaims) . '</span>' : '' ?></div>
  <div class="card">
    <?php if (!$openClaims): ?>
      <p class="empty"><i class="ti ti-circle-check"></i> Nichts offen – alle Erstattungen sind durch.</p>
    <?php else: ?>
      <table class="list stack-sm">
        <thead><tr><th>Eingereicht</th><th>Von</th><th>Anlass</th><th style="text-align:right">Summe</th><th style="text-align:right">Aktionen</th></tr></thead>
        <tbody>
        <?php foreach ($openClaims as $c): $efs = expense_files_of((int)$c['id']); ?>
          <tr class="claim-row">
            <td class="muted small" data-label="Eingereicht" style="white-space:nowrap"><a class="rowlink" href="finanzen.php?claim=<?= (int)$c['id'] ?>" title="Details und Nachrichten öffnen"><?= h(fmt_date(substr((string)$c['created_at'], 0, 10))) ?></a></td>
            <td><strong><?= h($c['name']) ?></strong> <?= expense_origin_badge($c) ?></td>
            <td class="small"><?= h($c['purpose']) ?> <span class="muted">· <?= count(expense_items($c)) ?> Position(en)</span>
              <?php if ($efs): ?><div style="margin-top:.15rem"><i class="ti ti-paperclip" style="color:var(--petrol)"></i>
                <?= implode(' · ', array_map(fn($ef) => '<a href="download.php?efile=' . (int)$ef['id'] . '" target="_blank" rel="noopener">' . h($ef['orig_name']) . '</a>', $efs)) ?></div><?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;white-space:nowrap"><?= euro((int)$c['total_cents']) ?></td>
            <td style="text-align:right">
              <div class="btn-row" style="justify-content:flex-end">
                <?php $nCm = expense_comment_count((int)$c['id']); ?>
                <?php if (expense_can_edit($c, $me)): ?><form method="post" style="margin:0" data-confirm="Dieses Belegblatt wirklich löschen? Nachrichten und angeheftete Belege verschwinden mit." data-confirm-danger data-confirm-ok="Löschen"<?= expense_delete_check_attr((int)$c['id']) ?>><?= csrf_field() ?><input type="hidden" name="action" value="claim_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn danger small" type="submit" title="Belegblatt löschen" aria-label="Belegblatt löschen"><i class="ti ti-trash"></i></button></form><?php endif; ?>
                <a class="btn secondary small" href="finanzen.php?claim=<?= (int)$c['id'] ?>" title="Details & Nachrichten (Rückfrage stellen)"><i class="ti ti-message-2"></i><?= $nCm ? ' ' . $nCm : '' ?></a>
                <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="set_done"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="done" value="1"><button class="btn small" type="submit" title="Überweisung ist raus – als erledigt markieren"><i class="ti ti-check"></i> Erledigt</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($doneClaims): ?>
    <details class="tl-more" style="margin-top:.6rem">
      <summary><span class="more-c">Erledigte anzeigen (<?= count($doneClaims) ?>)</span><span class="more-o">Erledigte ausblenden</span></summary>
      <div class="card" style="margin-top:.6rem">
        <table class="list stack-sm">
          <thead><tr><th>Erledigt</th><th>Von</th><th>Anlass</th><th style="text-align:right">Summe</th><th style="text-align:right">Aktionen</th></tr></thead>
          <tbody>
          <?php foreach ($doneClaims as $c): $efs = expense_files_of((int)$c['id']); ?>
            <tr class="claim-row">
              <td class="muted small" data-label="Eingereicht" style="white-space:nowrap"><a class="rowlink" href="finanzen.php?claim=<?= (int)$c['id'] ?>" title="Details und Nachrichten öffnen"><?= h(fmt_date(substr((string)($c['done_at'] ?? ''), 0, 10))) ?></a></td>
              <td><?= h($c['name']) ?></td>
              <td class="small"><?= h($c['purpose']) ?><?php if ($efs): ?> <span class="muted">· <i class="ti ti-paperclip"></i>
                <?= implode(' · ', array_map(fn($ef) => '<a href="download.php?efile=' . (int)$ef['id'] . '" target="_blank" rel="noopener">' . h($ef['orig_name']) . '</a>', $efs)) ?></span><?php endif; ?></td>
              <td style="text-align:right;white-space:nowrap"><?= euro((int)$c['total_cents']) ?></td>
              <td style="text-align:right">
                <div class="btn-row" style="justify-content:flex-end">
                  <?php $nCm = expense_comment_count((int)$c['id']); ?>
                  <?php if (expense_can_edit($c, $me)): ?><form method="post" style="margin:0" data-confirm="Dieses Belegblatt wirklich löschen? Nachrichten und angeheftete Belege verschwinden mit." data-confirm-danger data-confirm-ok="Löschen"<?= expense_delete_check_attr((int)$c['id']) ?>><?= csrf_field() ?><input type="hidden" name="action" value="claim_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn danger small" type="submit" title="Belegblatt löschen" aria-label="Belegblatt löschen"><i class="ti ti-trash"></i></button></form><?php endif; ?>
                  <a class="btn secondary small" href="finanzen.php?claim=<?= (int)$c['id'] ?>" title="Details & Nachrichten"><i class="ti ti-message-2"></i><?= $nCm ? ' ' . $nCm : '' ?></a>
                  <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="set_done"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="done" value="0"><button class="btn secondary small" type="submit" title="Doch nicht fertig – wieder öffnen"><i class="ti ti-rotate-2"></i> Öffnen</button></form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>
<?php endif; ?>

<?php if (can_finance()): $spOpen = stupa_claims_open(); $spDone = stupa_claims_done(); ?>
  <div class="section-title" id="stupa"><i class="ti ti-building-bank"></i> Auszahlungsaufforderungen des StuPa<?= $spOpen ? ' <span class="pill pill-warn">' . count($spOpen) . '</span>' : '' ?></div>
  <div class="card">
    <p class="small muted" style="margin-top:0">Anweisungen des <strong>StuPa-Präsidiums</strong> – keine Auslagen von AStA-Mitgliedern,
      deshalb hier getrennt geführt. Das Präsidium reicht sie über sein eigenes Login ein und sieht dort auch den Stand.</p>
    <?php if (!$spOpen): ?>
      <p class="empty" style="margin-bottom:0"><i class="ti ti-check"></i> Keine offene Auszahlungsaufforderung.</p>
    <?php else: ?>
      <table class="list stack-sm">
        <thead><tr><th>Eingereicht</th><th>Antragssteller:in</th><th>Verwendungszweck</th><th>Konto</th><th style="text-align:right">Betrag</th><th style="text-align:right">Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($spOpen as $c): $sPos = stupa_positions_of((int)$c['id']); ?>
          <tr>
            <td class="muted small" data-label="Eingereicht" style="white-space:nowrap"><?= h(fmt_date(substr((string)$c['created_at'], 0, 10))) ?>
              <?php if (count($sPos) > 1): ?><div class="muted small"><?= count($sPos) ?> Posten</div><?php endif; ?></td>
            <td><?php foreach ($sPos as $p): ?><div style="margin-bottom:.25rem"><strong><?= h($p['applicant']) ?></strong>
                <?php if (trim((string)$p['institution']) !== ''): ?><div class="muted small"><?= h($p['institution']) ?></div><?php endif; ?></div><?php endforeach; ?></td>
            <td class="small"><?php foreach ($sPos as $p): ?><div style="margin-bottom:.25rem"><?= h($p['purpose']) ?>
                <?php if (trim((string)$p['fund']) !== ''): ?><span class="muted">· Topf: <?= h($p['fund']) ?></span><?php endif; ?></div><?php endforeach; ?></td>
            <td class="small"><?php foreach ($sPos as $p): ?><div style="margin-bottom:.25rem"><?= h($p['account_holder']) ?><div class="muted small"><?= h($p['iban']) ?></div></div><?php endforeach; ?></td>
            <td style="text-align:right;white-space:nowrap">
              <?php foreach ($sPos as $p): ?><div style="margin-bottom:.25rem<?= count($sPos) > 1 ? '' : ';font-weight:700' ?>"><?= euro((int)$p['amount_cents']) ?></div><?php endforeach; ?>
              <?php if (count($sPos) > 1): ?><div style="font-weight:700;border-top:1px solid var(--line);padding-top:.25rem"><?= euro(stupa_claim_total($sPos)) ?></div><?php endif; ?></td>
            <td style="text-align:right">
              <div class="btn-row" style="justify-content:flex-end">
                <a class="btn secondary small" href="finanzen.php?sdl=<?= (int)$c['id'] ?>" target="_blank" rel="noopener" title="Ausgefüllte Auszahlungsaufforderung (.docx)"><i class="ti ti-file-type-docx"></i> Formular</a>
                <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="stupa_done"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="done" value="1">
                  <button class="btn small" type="submit" title="Überweisung ist raus"><i class="ti ti-check"></i> Erledigt</button>
                </form>
              </div>
            </td>
          </tr>
          <?php $sDat = stupa_files_of((int)$c['id']); $sVer = stupa_comments_of((int)$c['id']); ?>
          <tr>
            <td colspan="6" style="padding-top:0">
              <details<?= $sVer ? ' open' : '' ?>>
                <summary class="small muted">Belege &amp; Rückfragen<?= $sVer ? ' (' . count($sVer) . ')' : '' ?><?= $sDat ? ' · ' . count($sDat) . ' Beleg(e)' : '' ?><?= !empty($c['notify_done']) ? ' · Präsidium will eine Mail bei „erledigt"' : '' ?></summary>
                <?php if ($sDat): ?>
                  <p class="small" style="margin:.5rem 0 0"><i class="ti ti-paperclip" style="color:var(--petrol)"></i>
                    <?= implode(' · ', array_map(fn($f) => '<a href="download.php?sfile=' . (int)$f['id'] . '" target="_blank" rel="noopener">' . h($f['orig_name']) . '</a>', $sDat)) ?></p>
                <?php endif; ?>
                <?php foreach ($sVer as $k): $kf = stupa_comment_files((int)$k['id']); ?>
                  <div class="card" style="margin:.5rem 0;padding:.6rem .8rem">
                    <div class="small muted"><strong><?= h(stupa_comment_author($k)) ?></strong> · <?= h(fmt_date(substr((string)$k['created_at'], 0, 10))) ?></div>
                    <?php if (trim((string)$k['body']) !== ''): ?><div style="white-space:pre-wrap"><?= h($k['body']) ?></div><?php endif; ?>
                    <?php if ($kf): ?><div class="small"><i class="ti ti-paperclip"></i>
                      <?= implode(' · ', array_map(fn($f) => '<a href="download.php?sfile=' . (int)$f['id'] . '" target="_blank" rel="noopener">' . h($f['orig_name']) . '</a>', $kf)) ?></div><?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <form method="post" style="margin-top:.5rem">
                  <?= csrf_field() ?><input type="hidden" name="action" value="stupa_reply"><input type="hidden" name="claim_id" value="<?= (int)$c['id'] ?>">
                  <label for="sr<?= (int)$c['id'] ?>" class="small">Rückfrage ans Präsidium <span class="muted">(geht per E-Mail raus)</span></label>
                  <textarea id="sr<?= (int)$c['id'] ?>" name="body" rows="2" placeholder="Nachricht …"></textarea>
                  <div class="btn-row" style="margin-top:.5rem"><button class="btn secondary small" type="submit"><i class="ti ti-send"></i> Abschicken</button></div>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if ($spDone): ?>
      <details style="margin-top:.8rem">
        <summary class="small muted">Erledigte Auszahlungen (<?= count($spDone) ?>)</summary>
        <table class="list stack-sm" style="margin-top:.5rem">
          <tbody>
          <?php foreach ($spDone as $c): $dPos = stupa_positions_of((int)$c['id']); ?>
            <tr>
              <td class="muted small" data-label="Eingereicht" style="white-space:nowrap"><?= h(fmt_date(substr((string)($c['done_at'] ?? $c['created_at']), 0, 10))) ?></td>
              <td><?= h(implode(', ', array_column($dPos, 'applicant'))) ?></td>
              <td class="small"><?= h(stupa_claim_label($dPos)) ?></td>
              <td style="text-align:right;font-weight:700;white-space:nowrap"><?= euro(stupa_claim_total($dPos)) ?></td>
              <td style="text-align:right">
                <div class="btn-row" style="justify-content:flex-end">
                  <a class="btn secondary small" href="finanzen.php?sdl=<?= (int)$c['id'] ?>" target="_blank" rel="noopener" title="Ausgefüllte Auszahlungsaufforderung (.docx)"><i class="ti ti-file-type-docx"></i></a>
                  <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="stupa_done"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="done" value="0">
                    <button class="btn secondary small" type="submit" title="Doch nicht erledigt"><i class="ti ti-rotate-2"></i> Öffnen</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="section-title"><i class="ti ti-receipt-2"></i> Belegblatt für Auslagen einreichen</div>
<?php
// Bearbeiten-Modus: dasselbe Formular, nur vorbelegt und mit anderer Aktion (?edit=ID).
$editClaim = null;
if (isset($_GET['edit'])) {
    $editClaim = expense_claim_get((int)$_GET['edit']);
    if (!expense_can_edit($editClaim, $me)) { $editClaim = null; }
}
?>
<?php if (!$me): ?>
  <div class="card"><p class="empty"><i class="ti ti-info-circle"></i> Mit dem Technik-Login können keine Belegblätter eingereicht werden.</p></div>
<?php else: ?>
<div class="card">
  <?php if ($editClaim): ?>
    <p class="small muted" style="margin-top:0"><i class="ti ti-pencil" style="color:var(--petrol)"></i>
      Du bearbeitest ein bereits eingereichtes Belegblatt. Nach dem Speichern wird die Fassung in der
      Nextcloud <strong>ersetzt</strong>. Bereits angeheftete Belege bleiben erhalten – hier angehängte kommen dazu.</p>
  <?php endif; ?>
  <p class="small muted" style="margin-top:0"<?= $editClaim ? ' hidden' : '' ?>>Du hast für den AStA Geld ausgelegt? Trag hier ein, was du ausgegeben hast – daraus entsteht das offizielle <strong>Belegblatt</strong>, das direkt bei Finanzen als offene Aufgabe landet. Die <strong>Original-Belege</strong> (Quittungen/Rechnungen) bitte weiterhin in angeführter Reihenfolge abgeben bzw. anheften.</p>
  <?php // Beim Bearbeiten zählen bereits angeheftete Belege mit – sonst würde der Nachfrage-Dialog
        // fälschlich „kein Beleg dabei" melden, obwohl welche am Belegblatt hängen. ?>
  <form method="post" id="claimForm" enctype="multipart/form-data" data-has-files="<?= $editClaim ? count(expense_files_of((int)$editClaim['id'])) : 0 ?>"<?php if ($editClaim): ?> data-edit="<?= h(json_encode([
      'name' => $editClaim['name'], 'street' => $editClaim['street'], 'city' => $editClaim['city'],
      'bank' => $editClaim['bank'], 'bic' => $editClaim['bic'], 'iban' => $editClaim['iban'],
      'source' => $editClaim['source'], 'source_note' => $editClaim['source_note'],
      'purpose' => $editClaim['purpose'], 'items' => expense_items($editClaim),
  ], JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $editClaim ? 'claim_update' : 'submit_claim' ?>">
    <?php if ($editClaim): ?><input type="hidden" name="id" value="<?= (int)$editClaim['id'] ?>"><?php endif; ?>
    <?php if ($lastPref): ?>
      <div class="btn-row" style="margin-bottom:.6rem">
        <button type="button" class="btn secondary small" id="ecPrefill" data-pref="<?= h(json_encode($lastPref, JSON_UNESCAPED_UNICODE)) ?>"><i class="ti ti-wand"></i> Kontaktdaten der letzten Einreichung übernehmen</button>
      </div>
    <?php endif; ?>
    <div class="field-row">
      <div style="flex:1;min-width:220px"><label for="ec_name">Name, Vorname</label><input type="text" id="ec_name" name="name" required placeholder="<?= h($fallbackName) ?>" value="<?= h($fallbackName) ?>"></div>
      <div style="flex:1;min-width:220px"><label for="ec_street">Straße und Hausnummer</label><input type="text" id="ec_street" name="street"></div>
      <div style="flex:1;min-width:180px"><label for="ec_city">PLZ und Ort</label><input type="text" id="ec_city" name="city"></div>
    </div>
    <div class="field-row">
      <div style="flex:1;min-width:200px"><label for="ec_bank">Bankinstitut</label><input type="text" id="ec_bank" name="bank"></div>
      <div style="flex:1;min-width:160px"><label for="ec_bic">BIC</label><input type="text" id="ec_bic" name="bic"></div>
      <div style="flex:2;min-width:260px"><label for="ec_iban">IBAN</label><input type="text" id="ec_iban" name="iban" required placeholder="DE.."></div>
    </div>
    <div class="field-row" style="align-items:flex-end">
      <div>
        <label>Beantragt von</label>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;padding:.35rem 0">
          <label class="inline"><input type="radio" name="source" value="asta" checked> AStA</label>
          <label class="inline"><input type="radio" name="source" value="stupa"> StuPa</label>
          <label class="inline"><input type="radio" name="source" value="projekt"> Projekt/Sonstiges</label>
        </div>
      </div>
      <div style="flex:1;min-width:220px" id="srcNoteWrap" hidden><label for="ec_srcnote">Welches Projekt/Sonstiges?</label><input type="text" id="ec_srcnote" name="source_note"></div>
    </div>
    <?php if (can_finance()): ?>
      <label class="auto-toggle" style="margin:.2rem 0 .4rem"><input type="checkbox" name="by_finance" value="1"<?= ($editClaim && !empty($editClaim['by_finance'])) ? ' checked' : '' ?>>
        Ich erfasse das <strong>für jemand anderen</strong> (Beleg lag in Papierform vor)</label>
    <?php endif; ?>
    <div class="field-row">
      <div style="flex:1;min-width:260px"><label for="ec_purpose">Für (Veranstaltung/Projekt/…)</label><input type="text" id="ec_purpose" name="purpose" required placeholder="z. B. Demokratiefest 2026"></div>
    </div>

    <div class="section-title" style="font-size:1rem;margin:1rem 0 .4rem"><i class="ti ti-list-numbers"></i> Belege (in dieser Reihenfolge anheften)</div>
    <div id="ecItems"></div>
    <div class="btn-row" style="margin-top:.4rem">
      <button type="button" class="btn secondary small" id="ecAdd"><i class="ti ti-plus"></i> Position hinzufügen</button>
      <span class="small" style="margin-left:auto;font-weight:700">Summe: <span id="ecSum">0,00 €</span></span>
    </div>
    <div class="card ec-receipts" style="margin-top:.9rem">
      <label for="ec_files" style="font-size:1rem"><i class="ti ti-paperclip" style="color:var(--petrol)"></i> Belege anheften <span class="pill pill-warn" style="margin-left:.3rem">wichtig</span></label>
      <p class="small" style="margin:.2rem 0 .6rem"><strong>Ohne Beleg keine Erstattung.</strong> Zu <strong>jeder Position</strong> gehört eine Quittung oder Rechnung – fehlt eine, muss Finanzen nachfragen und die Auszahlung verzögert sich. Am schnellsten geht es, wenn du die Belege gleich hier abfotografierst oder als PDF anhängst.</p>
      <input type="file" id="ec_files" name="receipts[]" multiple accept=".pdf,.png,.jpg,.jpeg,.heic,.heif,.webp,.gif,image/*,application/pdf">
      <p class="small muted" style="margin:.4rem 0 0" id="ecFileHint">Mehrere Dateien auf einmal möglich – am besten <strong>in derselben Reihenfolge</strong> wie die Positionen oben. Die Originale bitte trotzdem aufheben und wie gewohnt abgeben. <strong>Angeheftete Doks werden maximal 30 Tage gespeichert</strong> und sind danach nicht mehr herunterladbar (sie löschen sich automatisch).</p>
    </div>
    <div class="btn-row" style="margin-top:1rem">
      <button class="btn" type="submit"><i class="ti <?= $editClaim ? 'ti-device-floppy' : 'ti-send' ?>"></i> <?= $editClaim ? 'Änderungen speichern' : 'Belegblatt einreichen' ?></button>
      <?php if ($editClaim): ?><a class="btn secondary" href="finanzen.php?claim=<?= (int)$editClaim['id'] ?>">Abbrechen</a><?php endif; ?>
    </div>
  </form>
  <script>
  (function(){
    var wrap = document.getElementById('ecItems'), add = document.getElementById('ecAdd'), sumEl = document.getElementById('ecSum');
    if (!wrap) return;
    function parseEuro(v){
      v = (v || '').replace(/[€\s]/g, '');
      if (!v || !/^\d[\d.,]*$/.test(v)) return null;
      if (v.indexOf('.') > -1 && v.indexOf(',') > -1) v = v.replace(/\./g, '').replace(',', '.');
      else if (v.indexOf(',') > -1) v = v.replace(',', '.');
      else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, '');
      var f = parseFloat(v);
      return isNaN(f) ? null : Math.round(f * 100);
    }
    function fmt(c){ return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
    function sum(){
      var t = 0;
      wrap.querySelectorAll('input[name="item_b[]"]').forEach(function(i){ var c = parseEuro(i.value); if (c) t += c; });
      sumEl.textContent = fmt(t);
    }
    function row(v){
      v = v || {};
      var n = wrap.children.length + 1;
      var div = document.createElement('div');
      div.className = 'field-row ec-row';
      div.style.alignItems = 'flex-end';
      div.innerHTML = '<span class="small muted ec-n" style="min-width:1.2rem;padding-bottom:.55rem;font-weight:700">' + n + '</span>'
        + '<div style="min-width:150px;max-width:180px"><label>Datum</label><div class="adp"><input type="text" class="adp-in" readonly placeholder="Datum wählen"><input type="hidden" name="item_d[]"></div></div>'
        + '<div style="flex:1;min-width:220px"><label>Sachbeschreibung</label><input type="text" name="item_s[]" placeholder="z. B. Getränke Edeka"></div>'
        + '<div style="min-width:110px;max-width:140px"><label>Betrag</label><input type="text" name="item_b[]" inputmode="decimal" placeholder="12,34"></div>'
        + '<button type="button" class="btn secondary small ec-del" title="Position entfernen" style="margin-bottom:.15rem"><i class="ti ti-x"></i></button>';
      if (v.s) div.querySelector('input[name="item_s[]"]').value = v.s;
      if (v.b) div.querySelector('input[name="item_b[]"]').value = (v.b / 100).toFixed(2).replace('.', ',');
      if (v.d) { div.querySelector('input[name="item_d[]"]').value = v.d; div.querySelector('.adp-in').value = v.d; }
      div.querySelector('.ec-del').addEventListener('click', function(){ div.remove(); renumber(); sum(); });
      div.querySelector('input[name="item_b[]"]').addEventListener('input', sum);
      wrap.appendChild(div);
      if (window.astaDatePicker) window.astaDatePicker(div.querySelector('.adp'));
    }
    function renumber(){
      var k = 1;
      wrap.querySelectorAll('.ec-n').forEach(function(s){ s.textContent = k++; });
    }
    add.addEventListener('click', function(){ row(); });   // Klick-Event darf nicht als Werte ankommen
    // Bearbeiten: Felder und Positionen aus dem gespeicherten Belegblatt füllen
    var form = document.getElementById('claimForm'), ed = null;
    try { ed = JSON.parse(form.dataset.edit || 'null'); } catch(e){}
    if (ed) {
      [['ec_name','name'],['ec_street','street'],['ec_city','city'],['ec_bank','bank'],
       ['ec_bic','bic'],['ec_iban','iban'],['ec_purpose','purpose'],['ec_srcnote','source_note']].forEach(function(p){
        var el = document.getElementById(p[0]);
        if (el && ed[p[1]]) el.value = ed[p[1]];
      });
      var src = form.querySelector('input[name="source"][value="' + (ed.source || 'asta') + '"]');
      if (src) src.checked = true;
      (ed.items || []).forEach(function(it){ row(it); });
    }
    if (!wrap.children.length) row();
    // Freitext nur bei „Projekt/Sonstiges" zeigen
    var noteWrap = document.getElementById('srcNoteWrap');
    document.querySelectorAll('#claimForm input[name="source"]').forEach(function(r){
      r.addEventListener('change', function(){ noteWrap.hidden = this.value !== 'projekt'; });
    });
    // Kontaktdaten der letzten Einreichung nur auf Wunsch übernehmen
    var srcNow = document.querySelector('#claimForm input[name="source"]:checked');
    if (noteWrap && srcNow) noteWrap.hidden = srcNow.value !== 'projekt';
    var pf = document.getElementById('ecPrefill');
    if (pf) pf.addEventListener('click', function(){
      var d = {};
      try { d = JSON.parse(pf.dataset.pref || '{}'); } catch(e){}
      [['ec_name','name'],['ec_street','street'],['ec_city','city'],['ec_bank','bank'],['ec_bic','bic'],['ec_iban','iban']].forEach(function(p){
        var el = document.getElementById(p[0]);
        if (el && d[p[1]]) el.value = d[p[1]];
      });
    });

    /* Nachfrage vor dem Absenden: „Sind alle Belege dabei?" – Text und Ton hängen davon ab,
       wie viele Dateien wirklich dranhängen. Die Belege sind bewusst KEINE Pflicht; wer ohne
       einreichen will, kommt mit einem Klick durch. Umgesetzt über die data-confirm-Mechanik
       aus app.js: Wir schreiben die Attribute vor dem Absenden fort, statt einen eigenen
       Dialog zu bauen (ein einziges Modal-Aussehen in der ganzen App). */
    var fileIn = document.getElementById('ec_files');
    var schon = parseInt(form.getAttribute('data-has-files'), 10) || 0; // bereits angeheftet (Bearbeiten)
    var verb = ed ? 'speichern' : 'einreichen';
    function belegNachfrage() {
      var neu = (fileIn && fileIn.files) ? fileIn.files.length : 0;
      var summe = neu + schon;
      var pos = wrap.querySelectorAll('.ec-row').length;
      var txt;
      if (!summe) {
        form.setAttribute('data-confirm-danger', '');
        form.setAttribute('data-confirm-title', 'Kein Beleg angehängt');
        form.setAttribute('data-confirm-ok', 'Trotzdem ' + verb);
        form.setAttribute('data-confirm-cancel', 'Belege anhängen');
        txt = 'Zu diesem Belegblatt hängt noch keine Quittung. Ohne Beleg kann Finanzen nicht erstatten '
            + 'und muss bei dir nachfragen – das dauert. Willst du wirklich ohne ' + verb + '?';
      } else {
        form.removeAttribute('data-confirm-danger');
        form.setAttribute('data-confirm-title', 'Alle Belege dabei?');
        form.setAttribute('data-confirm-ok', 'Ja, ' + verb);
        form.setAttribute('data-confirm-cancel', 'Nochmal nachsehen');
        txt = summe === 1 ? 'Es hängt 1 Beleg dran' : 'Es hängen ' + summe + ' Belege dran';
        txt += pos ? ' – bei ' + pos + ' Position' + (pos === 1 ? '' : 'en') + '.' : '.';
        if (summe < pos) txt += ' Das kann passen, wenn mehrere Quittungen in einer Datei stecken – sonst fehlt noch etwas.';
        txt += ' Zu jeder Position gehört ein Beleg.';
      }
      form.setAttribute('data-confirm', txt);
    }
    belegNachfrage();
    if (fileIn) fileIn.addEventListener('change', belegNachfrage);
    // Positionen ändern sich per Knopf/Tippen – Text vor dem Klick auf „Einreichen" frisch halten
    form.addEventListener('input', belegNachfrage);
    form.addEventListener('click', belegNachfrage);
  })();
  </script>
</div>

<?php if ($mine): ?>
  <div class="section-title"><i class="ti ti-history"></i> Deine Einreichungen</div>
  <div class="card<?= puste_klasse('beleg') ?>">
    <table class="list stack-sm">
      <thead><tr><th>Eingereicht</th><th>Anlass</th><th style="text-align:right">Summe</th><th>Status</th><th style="text-align:right"></th></tr></thead>
      <tbody>
      <?php foreach ($mine as $c): $efs = expense_files_of((int)$c['id']); ?>
        <tr class="claim-row">
          <td class="muted small" data-label="Eingereicht" style="white-space:nowrap"><a class="rowlink" href="finanzen.php?claim=<?= (int)$c['id'] ?>" title="Details und Nachrichten öffnen"><?= h(fmt_date(substr((string)$c['created_at'], 0, 10))) ?></a></td>
          <td class="small"><?= h($c['purpose']) ?><?php if ($efs): ?> <span class="muted" title="<?= count($efs) ?> Beleg(e) angeheftet">· <i class="ti ti-paperclip"></i> <?= count($efs) ?></span><?php endif; ?></td>
          <td style="text-align:right;white-space:nowrap"><?= euro((int)$c['total_cents']) ?></td>
          <td><?= $c['status'] === 'done'
              ? '<span class="pill pill-ok"><i class="ti ti-check"></i> erstattet</span>'
              : '<span class="pill pill-info"><i class="ti ti-hourglass"></i> in Bearbeitung</span>' ?></td>
          <td style="text-align:right">
            <div class="btn-row" style="justify-content:flex-end">
              <?php $nCm = expense_comment_count((int)$c['id']); ?>
              <?php if (expense_can_edit($c, $me)): ?><form method="post" style="margin:0" data-confirm="Dieses Belegblatt wirklich löschen? Nachrichten und angeheftete Belege verschwinden mit." data-confirm-danger data-confirm-ok="Löschen"<?= expense_delete_check_attr((int)$c['id']) ?>><?= csrf_field() ?><input type="hidden" name="action" value="claim_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn danger small" type="submit" title="Belegblatt löschen" aria-label="Belegblatt löschen"><i class="ti ti-trash"></i></button></form><?php endif; ?>
              <a class="btn secondary small" href="finanzen.php?claim=<?= (int)$c['id'] ?>" title="Details & Nachrichten mit Finanzen"><i class="ti ti-message-2"></i><?= $nCm ? ' ' . $nCm : '' ?></a>
            </div>
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
