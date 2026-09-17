<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_meetings(); // Sekretariat oder Admin

// norm_dtl() liegt zentral in lib.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'leg_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $start = trim((string)($_POST['start_date'] ?? ''));
        $num = max(1, (int)($_POST['start_number'] ?? 1));
        // Ohne Beginn kann die Periode keine Sitzung einsammeln – sie gilt über ihren Zeitraum.
        if ($name !== '' && $start === '') {
            flash('Bitte den Beginn der Periode angeben – daran erkennt die App, welche Sitzungen dazugehören.', 'error');
            redirect('meetings.php?t=zeitraeume');
        }
        // Der Beginn bestimmt die Zugehörigkeit – zweimal derselbe Tag ergäbe eine Periode,
        // die nie eine Sitzung bekommt.
        if ($name !== '' && legislature_date_taken($start)) {
            flash('Zu diesem Tag gibt es schon eine Periode. Jeder Beginn darf nur einmal vorkommen.', 'error');
            redirect('meetings.php?t=zeitraeume');
        }
        if ($name !== '') {
            db()->prepare('INSERT INTO legislatures(name, start_date, start_number) VALUES(?,?,?)')->execute([$name, $start, $num]);
            flash('Legislaturperiode angelegt.', 'success');
        }
        redirect('meetings.php?t=zeitraeume');
    }

    // Beginn UND Startnummer sind änderbar: Seit die Nummerierung am Datum hängt, wäre ein Vertipper
    // sonst nur durch Löschen und Neuanlegen zu heilen.
    if ($action === 'leg_save') {
        $id    = (int)($_POST['id'] ?? 0);
        $num   = max(1, (int)($_POST['start_number'] ?? 1));
        $start = trim((string)($_POST['start_date'] ?? ''));
        if ($start === '') {
            flash('Bitte den Beginn der Periode angeben – daran erkennt die App, welche Sitzungen dazugehören.', 'error');
            redirect('meetings.php?t=zeitraeume');
        }
        if (legislature_date_taken($start, $id)) {
            flash('Zu diesem Tag gibt es schon eine Periode. Jeder Beginn darf nur einmal vorkommen.', 'error');
            redirect('meetings.php?t=zeitraeume');
        }
        db()->prepare('UPDATE legislatures SET start_date=?, start_number=? WHERE id=?')->execute([$start, $num, $id]);
        flash('Periode gespeichert – die Nummerierung richtet sich ab sofort danach.', 'success');
        redirect('meetings.php?t=zeitraeume');
    }

    if ($action === 'leg_delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM legislatures WHERE id = ?')->execute([$id]);
        // Kein Zurücksetzen an den Sitzungen mehr: Die Zugehörigkeit steht nirgends gespeichert.
        // Die Sitzungen fallen dadurch in die davorliegende Periode und zählen dort weiter.
        flash('Legislaturperiode gelöscht. Ihre Sitzungen zählen jetzt in der davorliegenden Periode weiter.', 'success');
        redirect('meetings.php?t=zeitraeume');
    }

    if ($action === 'series') {
        $kind = ((string)($_POST['kind'] ?? 'ordentlich') === 'stupa') ? 'stupa' : 'ordentlich';
        $isStupa = $kind === 'stupa';
        $first = norm_dtl((string)($_POST['first_at'] ?? ''));
        $weeks = max(1, (int)($_POST['interval_weeks'] ?? 2));
        $count = max(1, min(60, (int)($_POST['count'] ?? 1)));
        $loc = trim((string)($_POST['location'] ?? ''));
        $startNum = max(1, (int)($_POST['start_number'] ?? 1));
        if (!$first) {
            flash('Bitte Datum/Uhrzeit der ersten Sitzung angeben.', 'error');
            redirect('meetings.php');
        }
        // „1. Sitzung ist Nr." meint die erste Sitzung DIESER Serie. Gespeichert wird die Zahl aber
        // als Startnummer der Periode – und die gilt für deren erste Sitzung. Liegen in der Periode
        // schon Sitzungen vor der Serie, rücken sie davor: Die Startnummer wird um genau so viele
        // Plätze zurückgesetzt, sonst begänne die Serie um diese Anzahl zu spät (Eingabe 10 → 12).
        // Gezählt wird mit denselben Bedingungen wie in meeting_number(). StuPa zählt eigenständig ab 1.
        $legS = $isStupa ? null : legislature_for((string)$first);
        $vorher = 0;
        $serienHinweis = '';
        if ($legS) {
            [$legVon] = legislature_range($legS);
            $stV = db()->prepare("SELECT COUNT(*) FROM meetings
                                  WHERE kind = 'ordentlich' AND cancelled = 0 AND draft = 0
                                    AND date(starts_at) >= ? AND starts_at < ?");
            $stV->execute([$legVon, $first]);
            $vorher = (int)$stV->fetchColumn();
            $legStart = $startNum - $vorher;
            if ($legStart < 1) {
                // Die gewünschte Nummer ist nicht erreichbar, ohne frühere Sitzungen unter 1 zu drücken.
                $legStart = 1;
                $serienHinweis = ' Nr. ' . $startNum . ' ging nicht: Davor liegen in dieser Periode schon '
                    . $vorher . ' Sitzungen, die Serie beginnt deshalb bei Nr. ' . ($vorher + 1) . '.';
            } elseif ($vorher > 0) {
                $serienHinweis = ' Die ' . ($vorher === 1 ? 'Sitzung' : $vorher . ' Sitzungen')
                    . ' davor ' . ($vorher === 1 ? 'ist jetzt Nr. ' . $legStart
                                                 : 'sind jetzt Nr. ' . $legStart . ($vorher === 2 ? ' und ' : ' bis ') . ($startNum - 1)) . '.';
            }
            db()->prepare('UPDATE legislatures SET start_number=? WHERE id=?')->execute([$legStart, (int)$legS['id']]);
        }
        $cmS = current_member();
        $asDraft = (isset($_POST['as_draft']) && $cmS) ? 1 : 0;
        $creator = $cmS ? (int)$cmS['id'] : null;
        $sid = 'S' . bin2hex(random_bytes(5)); // gruppiert die Serie (für „ganze Serie freigeben")
        // StuPa: kein Bericht, keine Einladung. AStA-Serie: automatisch berichtspflichtig.
        $needsReport = $isStupa ? 0 : 1;
        $inviteReq = $isStupa ? 0 : 1;
        $d = new DateTime($first);
        $ins = db()->prepare('INSERT INTO meetings(title, starts_at, location, kind, cancelled, needs_report, draft, created_by, series_id, invite_required) VALUES(?,?,?,?,0,?,?,?,?,?)');
        for ($i = 0; $i < $count; $i++) {
            // ends_at wird nicht gespeichert: Sitzungen laufen immer bis Tagesende (meeting_ends_at()).
            $ins->execute(['', $d->format('Y-m-d H:i'), $loc, $kind, $needsReport, $asDraft, $creator, $sid, $inviteReq]);
            $d->modify("+{$weeks} weeks");
        }
        if (!$asDraft && !$isStupa) protocol_sync_pending_votes(); // wartende Protokoll-Abstimmungen an die neue Folgesitzung hängen
        $lbl = $isStupa ? 'StuPa-Sitzungen' : 'Sitzungen';
        // Die Nummer der ersten Serien-Sitzung gleich mitnennen – so fällt ein Versatz sofort auf,
        // statt erst in der Liste. Entwürfe haben noch keine Nummer, sie zählen erst veröffentlicht.
        $ersteNr = '';
        if ($legS && !$asDraft) {
            $ersteNr = ' Die erste ist Nr. ' . max(1, $startNum, $vorher + 1) . '.';
        }
        flash($count . ' ' . $lbl . ($asDraft ? ' als Entwurf' : '') . " im {$weeks}-Wochen-Rhythmus angelegt."
              . $ersteNr . $serienHinweis, 'success');
        redirect('meetings.php');
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $kind = (string)($_POST['kind'] ?? 'ordentlich');
        if (!in_array($kind, ['ordentlich', 'ausserordentlich', 'sonstige', 'stupa'], true)) $kind = 'ordentlich';
        $start = norm_dtl((string)($_POST['starts_at'] ?? ''));
        $loc = trim((string)($_POST['location'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $teams = trim((string)($_POST['teams_link'] ?? ''));
        $needsReport = isset($_POST['needs_report']) ? 1 : 0;
        $inviteReq = isset($_POST['no_invite']) ? 0 : 1; // Kästchen „muss nicht eingeladen werden"
        if ($kind === 'stupa') { $needsReport = 0; $inviteReq = 0; } // StuPa: kein Bericht, keine Einladung
        if (!$start) {
            flash('Bitte Datum/Zeit angeben.', 'error');
        } elseif ($loc === '' && $kind !== 'stupa') {
            flash('Bitte den Raum angeben (steht in der Einladung).', 'error');
        } elseif ($id) {
            // Bearbeiten: Entwurfs-Status und Anleger:in bleiben unverändert
            db()->prepare('UPDATE meetings SET title=?, starts_at=?, location=?, description=?, kind=?, needs_report=?, teams_link=?, invite_required=? WHERE id=?')
               ->execute([$title, $start, $loc, $desc, $kind, $needsReport, $teams, $inviteReq, $id]);
            protocol_sync_pending_votes(); // verschoben/umgewidmet: Protokoll-Abstimmungen nachziehen
            flash('Sitzung gespeichert.', 'success');
        } else {
            $cmS = current_member();
            $asDraft = (isset($_POST['as_draft']) && $cmS) ? 1 : 0;
            db()->prepare('INSERT INTO meetings(title, starts_at, location, description, kind, needs_report, draft, created_by, teams_link, invite_required) VALUES(?,?,?,?,?,?,?,?,?,?)')
               ->execute([$title, $start, $loc, $desc, $kind, $needsReport, $asDraft, $cmS ? (int)$cmS['id'] : null, $teams, $inviteReq]);
            if (!$asDraft) protocol_sync_pending_votes(); // wartende Protokoll-Abstimmungen an die neue Folgesitzung hängen
            flash('Sitzung' . ($asDraft ? ' als Entwurf' : '') . ' angelegt.', 'success');
        }
        redirect('meetings.php');
    }

    if ($action === 'publish') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM meetings WHERE id=?'); $st->execute([$id]); $m = $st->fetch();
        if ($m && can_see_draft($m)) {
            db()->prepare('UPDATE meetings SET draft=0 WHERE id=?')->execute([$id]);
            protocol_sync_pending_votes(); // wartende Protokoll-Abstimmungen an die freigegebene Folgesitzung hängen
            flash('Sitzung veröffentlicht.', 'success');
        }
        redirect('meetings.php');
    }

    if ($action === 'publish_series') {
        $sid = (string)($_POST['series_id'] ?? '');
        $cmS = current_member();
        if ($sid !== '' && $cmS) {
            $st = db()->prepare('UPDATE meetings SET draft=0 WHERE series_id=? AND created_by=? AND draft=1');
            $st->execute([$sid, (int)$cmS['id']]);
            protocol_sync_pending_votes(); // wartende Protokoll-Abstimmungen an die freigegebenen Folgesitzungen hängen
            flash($st->rowCount() . ' Sitzung(en) der Serie veröffentlicht.', 'success');
        }
        redirect('meetings.php');
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE meetings SET cancelled = 1 - cancelled WHERE id=?')->execute([$id]);
        protocol_sync_pending_votes(); // abgesagt: wartende Protokoll-Abstimmung in die nächste Sitzung
        flash('Status der Sitzung geändert.', 'success');
        redirect('meetings.php');
    }

    if ($action === 'delete') {
        meeting_delete((int)($_POST['id'] ?? 0)); // räumt auch Protokoll-Abstimmungen auf und hängt sie um
        flash('Sitzung gelöscht.', 'success');
        redirect('meetings.php');
    }

    if ($action === 'save_agenda_template') {
        setting_set('agenda_template', trim((string)($_POST['agenda_template'] ?? '')));
        // Verwaiste „keine Redeliste"-/Berichte-Einträge entfernen, deren TOP es nicht mehr gibt
        $valid = [];
        foreach (agenda_tree(agenda_template()) as $it) $valid[base_top_key($it['text'])] = true;
        $keep = array_values(array_filter(array_keys(agenda_template_skip_set()), fn($k) => isset($valid[$k])));
        setting_set('agenda_template_skip', json_encode($keep, JSON_UNESCAPED_UNICODE));
        $keepR = array_values(array_filter(array_keys(agenda_template_report_set()), fn($k) => isset($valid[$k])));
        setting_set('agenda_template_report', json_encode($keepR, JSON_UNESCAPED_UNICODE));
        flash('Standard-Tagesordnung gespeichert.', 'success');
        redirect('meetings.php?t=tagesordnung');
    }
    if ($action === 'toggle_norl_template') { // TOP der Standard-TO in/aus der Redeliste schalten
        $key = base_top_key((string)($_POST['top'] ?? ''));
        if ($key !== '') {
            $set = agenda_template_skip_set();
            if (isset($set[$key])) { unset($set[$key]); }
            else {
                $set[$key] = true;
                $rep = agenda_template_report_set(); unset($rep[$key]); // ein TOP ist nicht beides zugleich
                setting_set('agenda_template_report', json_encode(array_keys($rep), JSON_UNESCAPED_UNICODE));
            }
            setting_set('agenda_template_skip', json_encode(array_keys($set), JSON_UNESCAPED_UNICODE));
        }
        redirect('meetings.php?t=tagesordnung');
    }
    if ($action === 'toggle_report_template') { // TOP der Standard-TO als Berichte-TOP schalten
        $key = base_top_key((string)($_POST['top'] ?? ''));
        if ($key !== '') {
            $set = agenda_template_report_set();
            if (isset($set[$key])) { unset($set[$key]); }
            else {
                $set[$key] = true;
                $skip = agenda_template_skip_set(); unset($skip[$key]); // Berichte-TOP ist ein Rede-TOP
                setting_set('agenda_template_skip', json_encode(array_keys($skip), JSON_UNESCAPED_UNICODE));
            }
            setting_set('agenda_template_report', json_encode(array_keys($set), JSON_UNESCAPED_UNICODE));
        }
        redirect('meetings.php?t=tagesordnung');
    }
    if ($action === 'save_invite_settings') {
        setting_set('top_deadline_days', (string)max(0, (int)($_POST['top_deadline_days'] ?? 5)));
        setting_set('invite_lead_days', (string)max(0, (int)($_POST['invite_lead_days'] ?? 5)));
        setting_set('invite_reminder_days', (string)max(0, (int)($_POST['invite_reminder_days'] ?? 2)));
        setting_set('invite_from', trim((string)($_POST['invite_from'] ?? '')));
        setting_set('invite_to', trim((string)($_POST['invite_to'] ?? '')));
        setting_set('invite_reminder_on', isset($_POST['invite_reminder_on']) ? '1' : '0');
        setting_set('invite_format', ($_POST['invite_format'] ?? 'html') === 'plain' ? 'plain' : 'html');
        setting_set('invite_subject', trim((string)($_POST['invite_subject'] ?? '')));
        setting_set('invite_template', trim((string)($_POST['invite_template'] ?? '')));
        flash('Einladungs-Einstellungen gespeichert.', 'success');
        redirect('meetings.php?t=einladungen');
    }
    if ($action === 'reset_invite_template') {
        setting_set('invite_subject', '');
        setting_set('invite_template', '');
        flash('Betreff und Vorlage auf den Standardtext zurückgesetzt.', 'success');
        redirect('meetings.php?t=einladungen');
    }
    if ($action === 'set_lecture_break') {
        if (lecture_break_set((string)($_POST['until'] ?? ''))) {
            flash('Vorlesungsfreie Zeit festgelegt – bis ' . fmt_date(lecture_break_until()) . ' kann keine Streak reißen (Sammeln läuft weiter).', 'success');
        } else {
            flash('Bitte ein gültiges Enddatum (heute oder später) wählen.', 'error');
        }
        redirect('meetings.php?t=zeitraeume');
    }
    if ($action === 'end_lecture_break') {
        lecture_break_end_now();
        flash('Vorlesungsfreie Zeit beendet – ab jetzt läuft die 7-Tage-Schonfrist.', 'success');
        redirect('meetings.php?t=zeitraeume');
    }
    if ($action === 'protocol_publish') { // angenommenes Protokoll aus der Übersicht heraus veröffentlichen
        $mid = (int)($_POST['meeting_id'] ?? 0);
        $cmS = current_member();
        $err = null;
        if (protocol_publish_to_olat($mid, $cmS ? (int)$cmS['id'] : 0, $err)) {
            flash('Protokoll als PDF nach OLAT veröffentlicht.', 'success');
        } else {
            flash('Veröffentlichung fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
        }
        redirect('meetings.php?t=protokolle');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $st = db()->prepare('SELECT * FROM meetings WHERE id=?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
    if ($edit && !can_see_draft($edit)) { // fremden Entwurf nicht öffnen
        flash('Dieser Sitzungs-Entwurf gehört jemand anderem.', 'error');
        redirect('meetings.php');
    }
}
$startVal = $edit ? substr((string)$edit['starts_at'], 0, 16) : '';

$legs = legislatures_all();
$list = db()->query("SELECT * FROM meetings WHERE " . visible_drafts_sql() . " ORDER BY starts_at DESC")->fetchAll();

// Liste in kommende / vergangene trennen (Liste kommt absteigend rein)
$today = date('Y-m-d');
$upcoming = $pastList = [];
foreach ($list as $m) {
    if (substr((string)$m['starts_at'], 0, 10) >= $today) $upcoming[] = $m; else $pastList[] = $m;
}
$upcoming = array_reverse($upcoming); // bald zuerst

// Eine Sitzungs-Tabelle rendern
$meetingTable = function (array $rows) {
    ?>
    <table class="list">
      <thead><tr><th>Termin</th><th>Sitzung</th><th>Legislatur</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m):
          $legName = (string)(legislature_for((string)$m['starts_at'])['name'] ?? '');
      ?>
        <tr<?= $m['cancelled'] ? ' style="opacity:.55"' : '' ?>>
          <td><?= h(fmt_slot($m['starts_at'], meeting_ends_at($m))) ?></td>
          <td>
            <?php if (!empty($m['draft'])): ?><span class="badge badge-draft">Entwurf</span> <?php endif; ?>
            <?php $lbl = '<a href="../meeting.php?id=' . (int)$m['id'] . '">' . h(meeting_label($m)) . '</a>'; ?>
            <?= $m['cancelled'] ? '<s>' . $lbl . '</s> <span class="badge badge-closed">ausgefallen</span>' : $lbl ?>
            <?php if (($m['kind'] ?? '') === 'ausserordentlich'): ?><span class="badge badge-event">a.o.</span><?php endif; ?>
            <?php if (($m['kind'] ?? '') === 'stupa'): ?><span class="badge badge-event">StuPa</span><?php endif; ?>
            <?php if ($m['location']): ?><br><span class="muted small"><?= h($m['location']) ?></span><?php endif; ?>
          </td>
          <td class="muted small"><?= h($legName) ?></td>
          <td style="text-align:right">
            <div class="btn-row" style="justify-content:flex-end">
              <?php if (!empty($m['draft'])): ?>
                <form method="post" data-confirm="Diese Sitzung veröffentlichen? Sie wird dann für alle sichtbar." data-confirm-ok="Veröffentlichen"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn small" type="submit"><i class="ti ti-rocket"></i> Veröffentlichen</button></form>
                <?php if (!empty($m['series_id'])): ?><form method="post" data-confirm="Die ganze Serie (alle Entwurfs-Sitzungen dieser Serie) veröffentlichen?" data-confirm-ok="Serie freigeben"><?= csrf_field() ?><input type="hidden" name="action" value="publish_series"><input type="hidden" name="series_id" value="<?= h($m['series_id']) ?>"><button class="btn secondary small" type="submit"><i class="ti ti-rocket"></i> Serie freigeben</button></form><?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($m['needs_report']) && empty($m['draft'])): ?><a class="btn secondary small" href="reports.php?meeting=<?= (int)$m['id'] ?>"><i class="ti ti-file-text"></i> Berichte</a><?php endif; ?>
              <?php if (empty($m['draft'])): ?><?= share_button('meeting.php?id=' . (int)$m['id'], 'Sitzungs-Link teilen', true) ?><?php endif; ?>
              <a class="btn secondary small" href="meetings.php?edit=<?= (int)$m['id'] ?>#bearbeiten">Bearbeiten</a>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn secondary small" type="submit"><?= $m['cancelled'] ? 'Reaktivieren' : 'Ausfallen lassen' ?></button></form>
              <form method="post" data-confirm="Sitzung löschen?" data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn danger small" type="submit" title="Sitzung löschen" aria-label="Sitzung löschen"><i class="ti ti-trash"></i></button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
};

// Einzel-Sitzungs-Formular einmal vorbereiten (oben beim Bearbeiten, sonst unten im Anlegen-Bereich)
ob_start();
?>
<div class="card" id="bearbeiten">
  <div class="section-title" style="margin-top:0"><i class="ti ti-calendar-event"></i> <?= $edit ? 'Sitzung bearbeiten' : 'Einzelne Sitzung anlegen' ?></div>
  <?php if ($edit && !empty($edit['draft'])): ?>
    <div class="flash flash-info" style="margin-top:0"><i class="ti ti-eye-off"></i> Dies ist ein <strong>Entwurf</strong> – nur für dich sichtbar. Änderungen unten speichern, dann unten <strong>veröffentlichen</strong> oder <strong>löschen</strong>.</div>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="field-row">
      <div>
        <label for="kind">Art</label>
        <select name="kind" id="kind">
          <?php $k = $edit['kind'] ?? 'ordentlich'; ?>
          <option value="ordentlich" <?= $k==='ordentlich'?'selected':'' ?>>Ordentliche Sitzung (nummeriert)</option>
          <option value="ausserordentlich" <?= $k==='ausserordentlich'?'selected':'' ?>>Außerordentliche Sitzung</option>
          <option value="stupa" <?= $k==='stupa'?'selected':'' ?>>StuPa-Sitzung (nummeriert, nur Kalender-Info)</option>
          <option value="sonstige" <?= $k==='sonstige'?'selected':'' ?>>Sonstige</option>
        </select>
      </div>
    </div>
    <label for="title">Zusatz-Titel (optional)</label>
    <input type="text" name="title" id="title" value="<?= h($edit['title'] ?? '') ?>" placeholder="z. B. Haushalt – frei lassen für reine Nummerierung">

    <div style="margin-top:.8rem">
      <label for="starts_at">Start</label>
      <input type="text" class="fp-datetime" name="starts_at" id="starts_at" value="<?= h($startVal) ?>" placeholder="Datum &amp; Uhrzeit">
      <p class="small muted" style="margin:.3rem 0 0">Sitzungen haben kein Ende – sie laufen automatisch <strong>bis Tagesende</strong> (im Kalender ein Termin über den Tag, kein 0-Sekunden-Punkt).</p>
    </div>

    <label for="location">Raum <span class="small muted">(bei StuPa optional)</span></label>
    <input type="text" name="location" id="location" value="<?= h($edit['location'] ?? '') ?>" placeholder="z. B. CIII 248">
    <label for="teams_link">Teams-Beitrittslink (für die Online-Teilnahme &amp; die Einladung)</label>
    <input type="text" name="teams_link" id="teams_link" value="<?= h($edit['teams_link'] ?? '') ?>" placeholder="https://teams.microsoft.com/meet/…">
    <label for="description">Notiz (optional)</label>
    <textarea name="description" id="description"><?= h($edit['description'] ?? '') ?></textarea>
    <label class="wl-sw"><input type="checkbox" name="needs_report" <?= !empty($edit['needs_report']) ? 'checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Bericht benötigt</strong>
        <span>Mitglieder müssen vor dieser Sitzung einen Bericht abgeben (Serien-Sitzungen sind automatisch berichtspflichtig).</span></span></label>
    <label class="wl-sw"><input type="checkbox" name="no_invite" <?= ($edit && (int)($edit['invite_required'] ?? 1) === 0) ? 'checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Zu dieser Sitzung muss nicht eingeladen werden</strong>
        <span>Sie taucht dann gar nicht in den offenen Einladungen auf – keine Einladungs-Karte, kein Reminder, kein Text/Versand.</span></span></label>
    <div class="btn-row" style="margin-top:.9rem">
      <button class="btn" type="submit"><?= $edit ? 'Speichern' : 'Anlegen' ?></button>
      <?php if (!$edit && current_member()): ?><button class="btn secondary" type="submit" name="as_draft" value="1"><i class="ti ti-eye-off"></i> Als Entwurf anlegen</button><?php endif; ?>
      <?php if ($edit): ?><a class="btn secondary" href="meetings.php">Abbrechen</a><?php endif; ?>
    </div>
  </form>
  <?php if ($edit && !empty($edit['draft'])): ?>
    <div class="btn-row" style="margin-top:.6rem;border-top:1px solid var(--line);padding-top:.8rem">
      <form method="post" data-confirm="Diesen Entwurf veröffentlichen? Er wird dann für alle sichtbar." data-confirm-ok="Veröffentlichen"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn" type="submit"><i class="ti ti-rocket"></i> Veröffentlichen</button></form>
      <?php if (!empty($edit['series_id'])): ?><form method="post" data-confirm="Die ganze Serie (alle Entwurfs-Sitzungen dieser Serie) veröffentlichen?" data-confirm-ok="Serie freigeben"><?= csrf_field() ?><input type="hidden" name="action" value="publish_series"><input type="hidden" name="series_id" value="<?= h($edit['series_id']) ?>"><button class="btn secondary" type="submit"><i class="ti ti-rocket"></i> Ganze Serie freigeben</button></form><?php endif; ?>
      <form method="post" data-confirm="Diesen Entwurf löschen?" data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn danger" type="submit"><i class="ti ti-trash"></i> Entwurf löschen</button></form>
    </div>
  <?php endif; ?>
</div>
<?php
$singleForm = ob_get_clean();

// Reiter der Seite (Muster wie die was.läuft-Verwaltung): Auf einer langen Rolle müsste man
// für Legislaturen an Tagesordnung und Einladungs-Mailtext vorbeiscrollen. Kein Abschnitt ist
// verschoben – jeder rendert nur auf seinem Reiter.
$mTabs = [
    'sitzungen'    => ['label' => 'Sitzungen',    'icon' => 'ti-calendar-event'],
    'tagesordnung' => ['label' => 'Tagesordnung', 'icon' => 'ti-list-check'],
    'protokolle'   => ['label' => 'Protokolle & Abstimmungsgegenstände', 'icon' => 'ti-notebook'],
    'einladungen'  => ['label' => 'Einladungen',  'icon' => 'ti-mail-forward'],
    'zeitraeume'   => ['label' => 'Zeiträume',    'icon' => 'ti-timeline'],
];
// Zähler am Protokolle-Reiter: Sitzungen mit Protokoll-Schritt offen + offene Beschlüsse
$protoOffen = (int)db()->query("SELECT COUNT(*) FROM meetings
    WHERE kind != 'stupa' AND cancelled = 0 AND draft = 0
      AND starts_at <= datetime('now','localtime') AND protocol_status != 'published'")->fetchColumn()
    + count(meetings_with_open_vote_items());
$mTab = (string)($_GET['t'] ?? '');
if (!isset($mTabs[$mTab])) $mTab = 'sitzungen';

page_header('Sitzungen', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-gavel" style="color:var(--petrol)"></i> Sitzungen</h1>
  <?php $cmM = current_member(); if ($cmM && trim((string)($cmM['referat'] ?? '')) !== ''): ?>
    <a class="btn secondary" href="../report.php"><i class="ti ti-file-text"></i> Bericht eintragen</a>
  <?php endif; ?>
</div>

<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($mTabs as $tk => $td): ?>
    <a<?= $tk === $mTab ? ' class="on" aria-current="page"' : '' ?> href="meetings.php?t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?>
      <?php if ($tk === 'sitzungen' && $upcoming): ?><span class="count"><?= count($upcoming) ?></span><?php endif; ?>
      <?php if ($tk === 'protokolle' && $protoOffen): ?><span class="count"><?= $protoOffen ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($mTab === 'sitzungen'): ?>
<?php if ($edit) echo $singleForm; // beim Bearbeiten oben anzeigen ?>

<div class="section-title"><i class="ti ti-calendar-up"></i> Kommende Sitzungen <span class="count"><?= count($upcoming) ?></span></div>
<?php if ($upcoming): ?>
  <div class="card" style="padding:.4rem .2rem"><?php $meetingTable($upcoming); ?></div>
<?php else: ?>
  <div class="card"><p class="empty"><i class="ti ti-calendar-off"></i> Keine kommenden Sitzungen. Leg unten eine an.</p></div>
<?php endif; ?>

<?php if ($pastList): ?>
  <details class="past-events">
    <summary><i class="ti ti-history"></i> Vergangene Sitzungen (<?= count($pastList) ?>)</summary>
    <div class="card" style="padding:.4rem .2rem;margin-top:1rem"><?php $meetingTable($pastList); ?></div>
  </details>
<?php endif; ?>
<p class="small muted" style="margin-top:.6rem">„Ausfallen lassen" behält den Termin im System, blendet ihn aber aus Kalender, Erinnerungen und Abos aus – und die Nummerierung rückt entsprechend nach.</p>
<?php endif; ?>

<?php if ($mTab === 'zeitraeume'): ?>
<div class="section-title" id="vorlesungsfrei"><i class="ti ti-snowflake"></i> Vorlesungsfreie Zeit <span class="muted small" style="font-weight:400">– Streak-Schutz</span></div>
<div class="card">
  <?php $lbUntil = lecture_break_until(); $lbActive = in_lecture_break(); $lbGrace = in_streak_grace(); ?>
  <?php if ($lbActive): ?>
    <p class="small" style="margin-top:0"><i class="ti ti-snowflake" style="color:var(--petrol)"></i> Vorlesungsfreie Zeit läuft <strong><?= $lbUntil !== '' ? 'bis ' . h(fmt_date($lbUntil)) : 'ohne Enddatum – bitte unten festlegen' ?></strong>. So lange (und in der <strong>7-Tage-Schonfrist</strong> danach) kann niemand die Streak verlieren – Flammen sammeln läuft ganz normal weiter.</p>
  <?php elseif ($lbGrace): ?>
    <p class="small" style="margin-top:0"><i class="ti ti-flame" style="color:var(--petrol)"></i> Die vorlesungsfreie Zeit ist vorbei – aktuell läuft die <strong>Schonfrist bis <?= h(fmt_date(lecture_break_grace_until())) ?></strong>. Danach gelten wieder die normalen Streak-Regeln.</p>
  <?php else: ?>
    <p class="small muted" style="margin-top:0">Aktuell ist keine vorlesungsfreie Zeit festgelegt. Sobald keine ordentliche Sitzung mehr ansteht, erinnert das Dashboard das Sekretariat daran – festlegen geht aber jederzeit auch hier.</p>
  <?php endif; ?>
  <div class="btn-row" style="align-items:flex-end;gap:.6rem;flex-wrap:wrap">
    <form method="post" class="btn-row" style="align-items:flex-end;gap:.6rem;flex-wrap:wrap;margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_lecture_break">
      <div style="min-width:180px"><label for="lb_until" class="small">Vorlesungsfrei bis (einschließlich)</label><input type="text" class="fp-date" name="until" id="lb_until" required placeholder="Enddatum wählen"<?= $lbUntil !== '' ? ' value="' . h($lbUntil) . '"' : '' ?>></div>
      <button class="btn secondary" type="submit"><i class="ti ti-snowflake"></i> <?= $lbActive ? 'Enddatum ändern' : 'Festlegen' ?></button>
    </form>
    <?php if ($lbActive): ?>
      <form method="post" style="margin:0" data-confirm="Die vorlesungsfreie Zeit jetzt beenden? Ab heute läuft die 7-Tage-Schonfrist." data-confirm-ok="Beenden">
        <?= csrf_field() ?><input type="hidden" name="action" value="end_lecture_break">
        <button class="btn secondary" type="submit"><i class="ti ti-player-stop"></i> Jetzt beenden</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<?php if ($mTab === 'sitzungen'): ?>
<div class="section-title"><i class="ti ti-calendar-plus"></i> Sitzung(en) anlegen</div>
<?php if (!$edit) echo $singleForm; // beim Anlegen hier zeigen ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-repeat"></i> Sitzungsserie erzeugen</div>
    <p class="small muted">Legt mehrere Sitzungen im festen Rhythmus an (Standard: alle 2 Wochen). Mit „1. Sitzung ist Nr." kannst du z. B. direkt bei der 7. anfangen – kein Rückwärtsrechnen. Einzelne kannst du danach verschieben oder ausfallen lassen. <strong>StuPa-Sitzungen</strong> zählen eigenständig ab 1, sind nur Kalender-Info (kein Bericht, keine Einladung, keine Rückmeldung).</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="series">
      <div class="field-row">
        <div>
          <label for="s_kind">Art</label>
          <select name="kind" id="s_kind">
            <option value="ordentlich">Ordentliche AStA-Sitzungen (berichtspflichtig)</option>
            <option value="stupa">StuPa-Sitzungen (nur Kalender-Info)</option>
          </select>
        </div>
      </div>
      <label for="s_first">Erste Sitzung (Datum &amp; Uhrzeit)</label>
      <input type="text" class="fp-datetime" name="first_at" id="s_first" placeholder="Datum &amp; Uhrzeit wählen">
      <div class="field-row">
        <div><label for="s_weeks">Intervall (Wochen)</label><input type="number" name="interval_weeks" id="s_weeks" min="1" value="2"></div>
        <div><label for="s_count">Anzahl</label><input type="number" name="count" id="s_count" min="1" max="60" value="13"></div>
        <div><label for="s_num">1. Sitzung ist Nr.</label><input type="number" name="start_number" id="s_num" min="1" value="1"></div>
      </div>
      <label for="s_loc">Raum (optional – je Sitzung änderbar)</label>
      <input type="text" name="location" id="s_loc" placeholder="z. B. CIII 248">
      <div class="btn-row" style="margin-top:.7rem">
        <button class="btn" type="submit">Serie anlegen</button>
        <?php if (current_member()): ?><button class="btn secondary" type="submit" name="as_draft" value="1"><i class="ti ti-eye-off"></i> Serie als Entwurf anlegen</button><?php endif; ?>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php if ($mTab === 'tagesordnung'): ?>
<div class="section-title" id="standard-to"><i class="ti ti-list-check"></i> Standard-Tagesordnung</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Diese Vorlage erscheint als <strong>vorläufige Tagesordnung</strong> auf der Detailseite jeder Sitzung – solange dort keine eigene gepflegt wurde. Ein TOP pro Zeile.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_agenda_template">
    <div class="agenda-editor"><textarea name="agenda_template" class="ag-data" rows="8" style="width:100%"><?= h(agenda_template()) ?></textarea></div>
    <div class="btn-row" style="margin-top:.7rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Standard-TO speichern</button></div>
  </form>

  <div class="section-title" style="font-size:1.05rem"><i class="ti ti-microphone-off"></i> Was nicht in die Redeliste?</div>
  <p class="small muted" style="margin-top:0">TOPs mit durchgestrichenem Redelisten-Icon bleiben in der Tagesordnung sichtbar, werden aber <strong>kein Rede-TOP</strong> – die Redeliste überspringt sie und startet auf dem ersten echten Rede-TOP. Gilt für alle Sitzungen, die diese Standard-TO nutzen.</p>
  <?php
  $tplTree = agenda_tree(agenda_template());
  $tplSkipSet = agenda_template_skip_set();
  $tplReportSet = agenda_template_report_set();
  $tj = 0; $tk = 0;
  ?>
  <?php if ($tplTree): ?>
  <ul class="agenda">
    <?php foreach ($tplTree as $it):
        if (!empty($it['sub'])) { $tk++; $no = $tj . '.' . $tk; $cls = 'ag-sub'; }
        else { $tj++; $tk = 0; $no = 'TOP ' . $tj; $cls = 'ag-main'; }
        if (!empty($it['internal'])) $cls .= ' ag-internal';
        $rep = isset($tplReportSet[base_top_key($it['text'])]);
        $sk = !$rep && isset($tplSkipSet[base_top_key($it['text'])]);
        if ($sk) $cls .= ' ag-norl';
        if ($rep) $cls .= ' ag-report';
    ?>
      <li class="<?= $cls ?>"><span class="ag-no"><?= $no ?></span> <span class="ag-text"><?= h($it['text']) ?><?php if ($sk): ?> <span class="ag-norl-tag"><i class="ti ti-microphone-off"></i> keine Redeliste</span><?php endif; ?><?php if ($rep): ?> <span class="ag-report-tag"><i class="ti ti-speakerphone"></i> Berichte-TOP</span><?php endif; ?></span>
        <span class="ag-subctl">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_report_template"><input type="hidden" name="top" value="<?= h($it['text']) ?>">
            <button class="ag-btn<?= $rep ? ' rep-on' : '' ?>" title="<?= $rep ? 'Berichte-TOP – klicken, um wieder ein normaler TOP zu werden' : 'Als Berichte-TOP markieren (eigene Bericht-Untertops in der Redeliste)' ?>"><i class="ti ti-speakerphone"></i></button>
          </form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_norl_template"><input type="hidden" name="top" value="<?= h($it['text']) ?>">
            <button class="ag-btn<?= $sk ? ' rl-on' : '' ?>" title="<?= $sk ? 'Wird NICHT in die Redeliste aufgenommen – klicken, um ihn wieder aufzunehmen' : 'Diesen TOP NICHT in die Redeliste aufnehmen' ?>"><span class="rl-strike"><i class="ti ti-list-numbers"></i></span></button>
          </form>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($mTab === 'protokolle'): ?>
<?php
// Alle gehaltenen AStA-Sitzungen mit ihrem Protokoll-Stand, neueste zuerst. StuPa bleibt
// draußen (reine Kalender-Info, kein Protokoll-Workflow).
$protoMeetings = db()->query("SELECT * FROM meetings
    WHERE kind != 'stupa' AND cancelled = 0 AND draft = 0
      AND starts_at <= datetime('now','localtime')
    ORDER BY starts_at DESC")->fetchAll();
$protoLaufend = array_values(array_filter($protoMeetings, fn($m) => (string)$m['protocol_status'] !== 'published'));
$protoFertig  = array_values(array_filter($protoMeetings, fn($m) => (string)$m['protocol_status'] === 'published'));
// Der Verlauf eines Protokolls als vier Stationen – jede sagt, was passiert ist oder was fehlt.
// Grün = erledigt, Bernstein = hier ist der nächste Schritt, grau = kommt später.
$protoKarte = function (array $pm) {
    $b = '../';
    $st = (string)$pm['protocol_status'];
    $pill = ['' => 'pill-warn', 'uploaded' => 'pill-info', 'approved' => 'pill-warn', 'published' => 'pill-ok'][$st] ?? 'pill-warn';
    $taker = (int)($pm['protocol_taker_id'] ?? 0);
    $takerRow = $taker ? member_get($taker) : null;
    $mLink = $b . 'meeting.php?id=' . (int)$pm['id'] . '#protokoll';
    $wer = function (?int $id): string {
        $m = $id ? member_get($id) : null;
        return $m ? ' durch ' . h(short_name((string)$m['name'])) : '';
    };
    $station = function (string $zustand, string $html) { // done | due | wait
        $ic = ['done' => 'ti-circle-check-filled', 'due' => 'ti-progress-alert', 'wait' => 'ti-circle-dashed'][$zustand];
        echo '<li class="pv-' . $zustand . '"><i class="ti ' . $ic . '"></i><span>' . $html . '</span></li>';
    };

    echo '<div class="proto-karte">';
    echo '<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">'
       . '<strong><a href="' . $mLink . '">' . h(meeting_label($pm)) . '</a></strong>'
       . '<span class="small muted">' . h(fmt_date(substr((string)$pm['starts_at'], 0, 10))) . '</span>'
       . '<span class="pill ' . $pill . '" style="margin-left:auto">' . h(protocol_status_label($st)) . '</span></div>';
    echo '<ul class="proto-verlauf">';

    // 1) Protokollant:in
    if ($takerRow) $station('done', 'Protokollant:in: <strong>' . h(short_name((string)$takerRow['name'])) . '</strong>');
    else $station($st === '' ? 'due' : 'done', 'Keine Protokollant:in eingetragen – <a href="' . $mLink . '">wählen</a>');

    // 2) Hochladen (öffentlich treibt das Verfahren; intern nur zur Info). Der ENTWURF kann in
    // Teams oder Nextcloud entstehen, die FERTIGE Fassung geht immer nach Teams – deshalb heißt
    // die erledigte Station fest „Teams", die offene bleibt neutral.
    if ($st === '') {
        $station('due', 'Öffentliches Protokoll noch nicht fertig – <a href="' . $mLink . '">hochladen oder Entwurf schreiben</a>');
    } else {
        $teams = trim((string)($pm['protocol_web_url'] ?? '')) !== '' ? protocol_open_url($pm) : '';
        $station('done', 'Öffentliches Protokoll liegt in Teams'
            . ($teams !== '' ? ' – <a href="' . h($teams) . '" target="_blank" rel="noopener">öffnen</a>' : ''));
    }
    if (trim((string)($pm['protocol_intern_web_url'] ?? '')) !== '') {
        $station('done', 'Internes Protokoll liegt in Teams – <a href="' . h(protocol_open_url($pm, true)) . '" target="_blank" rel="noopener">öffnen</a> <span class="muted">(wird nie veröffentlicht)</span>');
    }

    // 3) Abstimmung: die GANZE Kette, inklusive Vertagungen
    $viAlle = db()->prepare("SELECT * FROM vote_items WHERE kind = 'protocol' AND ref_meeting_id = ? ORDER BY id");
    $viAlle->execute([(int)$pm['id']]);
    $kette = $viAlle->fetchAll();
    if (!$kette) {
        if ($st === '') $station('wait', 'Abstimmung folgt, sobald das Protokoll hochgeladen ist');
        elseif ($st === 'uploaded' && (int)($pm['protocol_vote_off'] ?? 0) === 1) $station('due', 'Abstimmung wurde von Hand entfernt – <a href="' . $mLink . '">wieder anlegen</a>');
        elseif ($st === 'uploaded') $station('wait', 'Noch keine Folgesitzung geplant – die Abstimmung kommt automatisch in die nächste Sitzung, die angelegt wird. Längst angenommen? Dann den <a href="' . $mLink . '">Beschluss nachtragen</a>');
        else $station('done', ($st === 'approved' ? 'Angenommen' : 'Veröffentlicht') . ' <span class="muted">– zur Abstimmung selbst ist nichts vermerkt (nachgetragen oder Altbestand)</span>');
    }
    foreach ($kette as $vi) {
        $vmQ = db()->prepare('SELECT * FROM meetings WHERE id = ?');
        $vmQ->execute([(int)$vi['meeting_id']]);
        $vm = $vmQ->fetch() ?: null;
        $wo = $vm ? 'in der <a href="' . $b . 'meeting.php?id=' . (int)$vi['meeting_id'] . '#abstimmung">' . h(meeting_label($vm)) . '</a> <span class="muted">(' . h(fmt_date(substr((string)$vm['starts_at'], 0, 10))) . ')</span>' : '';
        $seit = trim(substr((string)($vi['created_at'] ?? ''), 0, 10));
        $vd = (string)$vi['decision'];
        if ($vd === 'angenommen') {
            $station('done', 'Abstimmung ' . $wo . ': <strong>angenommen</strong> am ' . h(fmt_date(substr((string)$vi['decided_at'], 0, 10))) . $wer((int)($vi['decided_by'] ?? 0)));
        } elseif ($vd === 'vertagt') {
            $station('done', 'Abstimmung ' . $wo . ': <strong>vertagt</strong> am ' . h(fmt_date(substr((string)$vi['decided_at'], 0, 10))) . $wer((int)($vi['decided_by'] ?? 0)));
            if ($vi === end($kette)) $station('wait', 'Neue Abstimmung folgt automatisch in der nächsten geplanten Sitzung');
        } else {
            $vergangen = $vm && strtotime((string)$vm['starts_at']) <= time();
            $station($vergangen ? 'due' : 'wait', 'Abstimmung angelegt' . ($seit !== '' ? ' am ' . h(fmt_date($seit)) : '') . ' ' . $wo . ': noch offen'
                . ($vergangen ? ' – die Sitzung ist vorbei, bitte den <a href="' . $b . 'meeting.php?id=' . (int)$vi['meeting_id'] . '#abstimmung">Beschluss festhalten</a>' : ''));
        }
    }

    // 4) OLAT
    if ($st === 'published') {
        $station('done', 'Als PDF in OLAT veröffentlicht am ' . h(fmt_date(substr((string)($pm['protocol_published_at'] ?? ''), 0, 10)))
            . $wer((int)($pm['protocol_published_by'] ?? 0))
            . (trim((string)($pm['protocol_olat_path'] ?? '')) !== '' ? ' <span class="muted">(' . h((string)$pm['protocol_olat_path']) . ')</span>' : ''));
    } elseif ($st === 'approved') {
        if (olat_configured()) {
            $station('due', 'Angenommen – jetzt als PDF nach OLAT veröffentlichen:');
            echo '<li class="pv-form"><form method="post" style="margin:0" data-confirm="Protokoll der ' . h(meeting_label($pm)) . ' jetzt als PDF nach OLAT veröffentlichen?" data-confirm-ok="Veröffentlichen">'
               . csrf_field() . '<input type="hidden" name="action" value="protocol_publish"><input type="hidden" name="meeting_id" value="' . (int)$pm['id'] . '">'
               . '<button class="btn small" type="submit"><i class="ti ti-school"></i> Nach OLAT veröffentlichen</button></form></li>';
        } else {
            $station('due', 'Angenommen – aber OLAT ist nicht eingerichtet (<a href="../uploads.php">Uploads und Automationen</a>)');
        }
    } else {
        $station('wait', 'OLAT-Veröffentlichung folgt nach der Annahme');
    }
    echo '</ul></div>';
};
?>
<div class="section-title"><i class="ti ti-notebook"></i> Protokolle <?php if ($protoLaufend): ?><span class="count"><?= count($protoLaufend) ?></span><?php endif; ?></div>
<div class="card">
  <p class="small muted" style="margin-top:0">Der Weg jedes Protokolls: <strong>in Teams hochladen</strong> → <strong>Abstimmung</strong> in der Folgesitzung (legt die App selbst an) → nach der Annahme <strong>als PDF nach OLAT</strong>. Jede Karte zeigt den kompletten Verlauf: <i class="ti ti-circle-check-filled" style="color:var(--green)"></i> erledigt · <i class="ti ti-progress-alert" style="color:var(--amber)"></i> hier hängt es · <i class="ti ti-circle-dashed" style="color:var(--muted)"></i> kommt später.</p>
  <?php if (!$protoLaufend): ?>
    <p class="empty" style="margin:0"><i class="ti ti-circle-check"></i> Alle Protokolle sind veröffentlicht.</p>
  <?php else: ?>
    <?php foreach ($protoLaufend as $pm) $protoKarte($pm); ?>
  <?php endif; ?>
  <?php if ($protoFertig): ?>
    <details style="margin-top:.6rem">
      <summary class="small"><i class="ti ti-history"></i> Veröffentlichte Protokolle (<?= count($protoFertig) ?>)</summary>
      <?php foreach ($protoFertig as $pm) $protoKarte($pm); ?>
    </details>
  <?php endif; ?>
</div>

<?php $openVoteMeetings = meetings_with_open_vote_items(); ?>
<div class="section-title"><i class="ti ti-checkbox"></i> Offene Abstimmungsgegenstände <?php if ($openVoteMeetings): ?><span class="count"><?= array_sum(array_map(fn($m) => (int)$m['open_votes'], $openVoteMeetings)) ?></span><?php endif; ?></div>
<div class="card">
  <p class="small muted" style="margin-top:0">Nach der Sitzung gehört zu jedem Abstimmungsgegenstand ein festgehaltener Beschluss – <strong>angenommen</strong> oder <strong>vertagt</strong>. Bei Protokoll-Gegenständen hängt daran die OLAT-Veröffentlichung. Hier steht alles, was noch auf „offen" steht.</p>
  <?php if (!$openVoteMeetings): ?>
    <p class="empty" style="margin:0"><i class="ti ti-circle-check"></i> Kein Gegenstand wartet auf einen Beschluss.</p>
  <?php else: ?>
    <?php foreach ($openVoteMeetings as $om): ?>
      <p class="small" style="margin:.3rem 0"><strong><?= h(meeting_label($om)) ?></strong> <span class="muted">· <?= h(fmt_date(substr((string)$om['starts_at'], 0, 10))) ?></span></p>
      <ul style="margin:.2rem 0 .7rem">
        <?php $oi = db()->prepare("SELECT * FROM vote_items WHERE meeting_id = ? AND decision = 'offen' ORDER BY sort"); $oi->execute([(int)$om['id']]); ?>
        <?php foreach ($oi->fetchAll() as $ov): ?>
          <li class="small"><?php if (($ov['kind'] ?? '') === 'protocol'): ?><i class="ti ti-notebook" title="Protokoll-Genehmigung" style="color:var(--petrol)"></i> <?php endif; ?><?= h((string)$ov['title']) ?>
            <a class="small" href="../meeting.php?id=<?= (int)$om['id'] ?>#abstimmung">Beschluss festhalten</a></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($mTab === 'einladungen'): ?>
<details id="einladungen" class="collapse-card"<?= invite_mode() === 'auto' ? ' open' : '' ?>>
<summary class="section-title"><i class="ti ti-mail-forward"></i> Einladungen<?php if (invite_mode() !== 'auto'): ?> <span class="small muted" style="font-weight:400">· Auto-Versand aus, nur Einstellungen</span><?php endif; ?><i class="ti ti-chevron-down collapse-chev"></i></summary>
<div class="card">
  <p class="small muted" style="margin-top:0">Das Sekretariat trägt pro Sitzung den Teams-Link ein und kümmert sich um die Einladung. <strong>Versandmodus</strong> (nur Text ausgeben ⇄ automatischer Mailversand an den Verteiler) wird unter <a href="sekretariat.php">Sekretariatsaufgaben</a> umgeschaltet – aktuell: <strong><?= invite_mode() === 'auto' ? 'automatischer Versand' : 'nur Text ausgeben' ?></strong>. Die folgenden Einstellungen (Verteiler, Format, Vorlage) gelten für den automatischen Versand.</p>
  <?php if (invite_mode() !== 'auto'): ?><p class="small" style="margin:.2rem 0 .6rem"><i class="ti ti-info-circle" style="color:var(--petrol)"></i> <strong>Text-Modus aktiv:</strong> Den fertigen Einladungstext zum Kopieren findest du <strong>pro Sitzung</strong> auf der jeweiligen <strong>Sitzungsseite</strong> (Karte „Einladung") – dort versendet das Sekretariat ihn selbst und markiert ihn als versendet.</p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_invite_settings">
    <div class="field-row">
      <div>
        <label for="top_deadline_days">TOP-Einreichefrist (Tage vor Sitzung)</label>
        <input type="number" name="top_deadline_days" id="top_deadline_days" min="0" value="<?= h((string)top_deadline_days()) ?>">
      </div>
      <div>
        <label for="invite_lead_days">Einladung verschicken (Tage vor Sitzung)</label>
        <input type="number" name="invite_lead_days" id="invite_lead_days" min="0" value="<?= h((string)invite_lead_days()) ?>">
      </div>
      <div>
        <label for="invite_reminder_days">Reminder-Vorlauf (Tage vor Stichtag)</label>
        <input type="number" name="invite_reminder_days" id="invite_reminder_days" min="0" value="<?= h((string)invite_reminder_days()) ?>">
      </div>
    </div>
    <label for="invite_from">Absender-Adresse der Einladungen</label>
    <input type="email" name="invite_from" id="invite_from" value="<?= h((string)setting_get('invite_from', '')) ?>" placeholder="<?= h(mail_from()) ?> (Standard, wenn leer)">
    <label for="invite_to">Empfänger / Uni-Verteiler</label>
    <input type="email" name="invite_to" id="invite_to" value="<?= h((string)setting_get('invite_to', '')) ?>" placeholder="<?= h('z. B. ' . org_mail_beispiel('studierende')) ?>">
    <p class="small muted" style="margin:.3rem 0 0">An diese Adresse geht die Einladung (sie verteilt dann intern weiter). Ohne Verteiler-Adresse wird nichts verschickt.</p>
    <label class="wl-sw"><input type="checkbox" name="invite_reminder_on" <?= (string)setting_get('invite_reminder_on', '1') !== '0' ? 'checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Reminder-Mails ans Sekretariat/Vorsitz verschicken</strong>
        <span>Erinnert vor dem Stichtag an noch nicht freigegebene Einladungen (zusätzlich pro Sitzung
          abwählbar). Ausgeschaltet bleibt nur der Dashboard-Hinweis.</span></span></label>
    <label for="invite_format" style="margin-top:.6rem">Versandformat der Einladung (nur automatischer Mailversand)</label>
    <select name="invite_format" id="invite_format">
      <option value="html" <?= invite_is_html() ? 'selected' : '' ?>>HTML (Text hübsch verpackt, Links klickbar)</option>
      <option value="plain" <?= invite_is_html() ? '' : 'selected' ?>>Plaintext (der Text 1:1)</option>
    </select>

    <div class="section-title" style="font-size:1.05rem"><i class="ti ti-mail-cog"></i> Mailtext</div>
    <label for="invite_subject">Betreff</label>
    <input type="text" name="invite_subject" id="invite_subject" value="<?= h((string)setting_get('invite_subject', '')) ?>" placeholder="<?= h(default_invite_subject()) ?>">
    <label for="invite_template" style="margin-top:.5rem">Mailtext (Plaintext – Zeilenumbrüche gelten wie eingegeben, keine HTML-Tags nötig)</label>
    <textarea name="invite_template" id="invite_template" rows="16" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.82rem"><?= h(invite_template()) ?></textarea>
    <p class="small muted" style="margin:.4rem 0 .2rem">Platzhalter (werden beim Versand automatisch ersetzt):</p>
    <ul class="small muted" style="margin:0 0 .4rem;columns:2;column-gap:1.5rem">
      <?php foreach (invite_placeholders() as $ph => $desc): ?>
        <li><code><?= h($ph) ?></code> – <?= h($desc) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="btn-row" style="margin-top:.7rem;flex-wrap:wrap">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Einladungs-Einstellungen speichern</button>
      <?php $nmInv = next_meeting(); if ($nmInv): ?><a class="btn secondary" href="../meeting.php?id=<?= (int)$nmInv['id'] ?>&invite_preview=1" target="_blank" rel="noopener"><i class="ti ti-eye"></i> Vorschau (nächste Sitzung)</a><?php endif; ?>
    </div>
  </form>
  <form method="post" data-confirm="Betreff und Mailtext auf den Standardtext zurücksetzen? Eigene Änderungen gehen verloren." data-confirm-ok="Zurücksetzen" style="margin-top:.5rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_invite_template">
    <button class="btn secondary small" type="submit"><i class="ti ti-rotate"></i> Auf Standardtext zurücksetzen</button>
  </form>
</div>
</details>
<?php endif; ?>

<?php if ($mTab === 'zeitraeume'): ?>
<div class="section-title" id="legislaturen"><i class="ti ti-list-numbers"></i> Legislaturperioden</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Die Nummerierung der ordentlichen Sitzungen startet je Legislatur neu bei 1.
    Welche Sitzung zu welcher Periode gehört, ergibt sich <strong>aus ihrem Datum</strong> – eine Periode läuft
    ab ihrem Beginn bis zum Beginn der nächsten. Deshalb muss jeder Beginn eindeutig sein, und eine Änderung
    hier nummeriert die betroffenen Sitzungen sofort neu.</p>
  <?php if ($legs): ?>
    <table class="list"><tbody>
    <?php foreach ($legs as $l): ?>
      <tr>
        <td>
          <?= h($l['name']) ?>
          <?php [$lVon, $lBis] = legislature_range($l); if ($lVon !== ''): ?>
            <span class="muted small">· <?= fmt_date($lVon) ?> bis <?= $lBis !== null ? fmt_date(date('Y-m-d', strtotime($lBis . ' -1 day'))) : 'heute' ?></span>
          <?php endif; ?>
          <form method="post" class="btn-row" style="gap:.3rem;margin-top:.3rem;flex-wrap:wrap;align-items:center">
            <?= csrf_field() ?><input type="hidden" name="action" value="leg_save"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <span class="small muted">ab</span>
            <input type="text" class="fp-date" name="start_date" value="<?= h((string)$l['start_date']) ?>" required style="max-width:130px" aria-label="Beginn der Periode">
            <span class="small muted">, 1. Sitzung ist Nr.</span>
            <input type="number" name="start_number" value="<?= (int)($l['start_number'] ?? 1) ?>" min="1" style="max-width:70px" aria-label="Startnummer">
            <button class="btn secondary small" type="submit">OK</button>
          </form>
        </td>
        <td style="text-align:right;vertical-align:top"><form method="post" data-confirm="Legislatur löschen? Die Sitzungen bleiben und zählen dann in der davorliegenden Periode weiter – ihre Nummern ändern sich." data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?><input type="hidden" name="action" value="leg_delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn danger small">Löschen</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <p class="muted small">Noch keine angelegt.</p>
  <?php endif; ?>
  <form method="post" style="margin-top:.6rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="leg_add">
    <label for="leg_name">Name</label>
    <input type="text" name="name" id="leg_name" placeholder="z. B. Legislatur 2026/27" required>
    <div class="field-row">
      <div><label for="leg_start">Beginn</label><input type="text" class="fp-date" name="start_date" id="leg_start" required placeholder="Datum wählen"></div>
      <div><label for="leg_num">1. Sitzung ist Nr.</label><input type="number" name="start_number" id="leg_num" min="1" value="1" style="max-width:90px"></div>
    </div>
    <div class="btn-row" style="margin-top:.7rem"><button class="btn secondary" type="submit">Legislatur anlegen</button></div>
  </form>
</div>
<?php endif; ?>
<?php
page_footer();
