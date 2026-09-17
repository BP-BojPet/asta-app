<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_login(); // Events dürfen alle Mitglieder anlegen; bearbeiten nur eigene (Admins alle)

$cm = current_member();

// norm_dtl() liegt zentral in lib.php

/** Datum + optionale Uhrzeit -> "Y-m-d H:i:00" (oder null, wenn kein Datum). */
function combine_dt(string $date, string $time): ?string
{
    $date = trim($date);
    if ($date === '') return null;
    $time = trim($time);
    return $date . ' ' . ($time !== '' ? $time : '00:00') . ':00';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    // --- Event-Vorlagen (Templates) – pro anlegender Person ---
    if (isset($_POST['save_template'])) {
        $name = trim((string)($_POST['template_name'] ?? ''));
        if ($name === '') $name = trim((string)($_POST['title'] ?? '')) ?: 'Vorlage';
        $sn = (array)($_POST['slot_name'] ?? []); $ss = (array)($_POST['slot_start'] ?? []); $se = (array)($_POST['slot_end'] ?? []);
        $sl = (array)($_POST['slot_loc'] ?? []);  $stg = (array)($_POST['slot_target'] ?? []); $sr = (array)($_POST['slot_req'] ?? []);
        $sr2 = (array)($_POST['slot_req2'] ?? []); $sr3 = (array)($_POST['slot_req3'] ?? []);
        $sd = (array)($_POST['slot_date'] ?? []);
        $slots = [];
        foreach ($sn as $i => $_x) {
            $slots[] = [
                'name'   => trim((string)($sn[$i] ?? '')),
                'date'   => trim((string)($sd[$i] ?? '')),
                'start'  => trim((string)($ss[$i] ?? '')),
                'end'    => trim((string)($se[$i] ?? '')),
                'loc'    => trim((string)($sl[$i] ?? '')),
                'target' => max(0, (int)($stg[$i] ?? 0)),
                'req'    => max(0, (int)($sr[$i] ?? 0)),
                'req2'   => max(0, (int)($sr2[$i] ?? 0)),
                'req3'   => max(0, (int)($sr3[$i] ?? 0)),
            ];
        }
        $slots = array_values(array_filter($slots, fn($s) => $s['name'] !== '' || $s['start'] !== '' || $s['date'] !== '' || $s['loc'] !== '' || $s['target'] || $s['req'] || $s['req2'] || $s['req3']));
        $payload = json_encode([
            'title'      => trim((string)($_POST['title'] ?? '')),
            'description'=> trim((string)($_POST['description'] ?? '')),
            'starts_at'  => trim((string)($_POST['starts_at'] ?? '')),
            'ends_at'    => trim((string)($_POST['ends_at'] ?? '')),
            'deadline'   => trim((string)($_POST['deadline'] ?? '')),
            'max_shifts' => trim((string)($_POST['max_shifts'] ?? '')),
            'important'  => isset($_POST['important']) ? 1 : 0,
            'req_label'  => trim((string)($_POST['req_label'] ?? '')),
            'req_label2' => trim((string)($_POST['req_label2'] ?? '')),
            'req_label3' => trim((string)($_POST['req_label3'] ?? '')),
            'slots'      => $slots,
        ], JSON_UNESCAPED_UNICODE);
        db()->prepare('INSERT INTO event_templates(owner_id, name, payload) VALUES(?,?,?)')->execute([$cm['id'] ?? null, $name, $payload]);
        flash('Vorlage „' . $name . '" gespeichert.', 'success');
        redirect('events.php');
    }
    if (isset($_POST['delete_template'])) {
        $tid = (int)($_POST['template_id'] ?? 0);
        if (can_admin()) {
            db()->prepare('DELETE FROM event_templates WHERE id=?')->execute([$tid]);
        } else {
            db()->prepare('DELETE FROM event_templates WHERE id=? AND owner_id=?')->execute([$tid, (int)($cm['id'] ?? 0)]);
        }
        flash('Vorlage gelöscht.', 'success');
        redirect('events.php');
    }

    $minDeadline = date('Y-m-d', strtotime('today +2 days'));

    // --- Event anlegen (mit Von–Bis-Datum + Schichten in einem Schritt) ---
    if ($action === 'create_event') {
        $title = trim((string)($_POST['title'] ?? ''));
        $deadline = trim((string)($_POST['deadline'] ?? ''));
        $startsAt = trim((string)($_POST['starts_at'] ?? ''));
        $endsAt   = trim((string)($_POST['ends_at'] ?? ''));
        if ($title === '') { flash('Bitte einen Titel angeben.', 'error'); redirect('events.php'); }
        if ($startsAt === '') { flash('Bitte angeben, wann das Event beginnt.', 'error'); redirect('events.php'); }
        if ($endsAt === '' || $endsAt < $startsAt) $endsAt = $startsAt;
        if ($deadline === '' || $deadline < $minDeadline) {
            flash('Bitte eine Abstimmungsfrist mindestens 2 Tage in der Zukunft angeben (frühestens ' . fmt_date($minDeadline) . ').', 'error');
            redirect('events.php');
        }
        $maxShifts = trim((string)($_POST['max_shifts'] ?? '')) === '' ? null : max(1, (int)$_POST['max_shifts']);
        $asDraft = (isset($_POST['as_draft']) && $cm) ? 1 : 0;
        $st = db()->prepare('INSERT INTO events(title, description, target_helpers, max_shifts_per_member, deadline, starts_at, ends_at, important, req_label, req_label2, req_label3, created_by, draft) VALUES(?,?,0,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$title, trim((string)($_POST['description'] ?? '')), $maxShifts, $deadline, $startsAt, $endsAt, isset($_POST['important']) ? 1 : 0, trim((string)($_POST['req_label'] ?? '')), trim((string)($_POST['req_label2'] ?? '')), trim((string)($_POST['req_label3'] ?? '')), $cm['id'] ?? null, $asDraft]);
        $newId = (int)db()->lastInsertId();
        if ($cm) set_event_owners($newId, [(int)$cm['id']]); // Ersteller:in als Besitzer:in eintragen
        // Schichten aus dem Formular (optional, beliebig viele) – je mit Name + Helferzahl + Merkmal-Bedarf (1–3)
        $names   = (array)($_POST['slot_name'] ?? []);
        $dates   = (array)($_POST['slot_date'] ?? []);
        $starts  = (array)($_POST['slot_start'] ?? []);
        $ends    = (array)($_POST['slot_end'] ?? []);
        $locs    = (array)($_POST['slot_loc'] ?? []);
        $targets = (array)($_POST['slot_target'] ?? []);
        $reqs    = (array)($_POST['slot_req'] ?? []);
        $reqs2   = (array)($_POST['slot_req2'] ?? []);
        $reqs3   = (array)($_POST['slot_req3'] ?? []);
        $ins = db()->prepare('INSERT INTO event_slots(event_id, starts_at, ends_at, label, location, target_helpers, req_count, req_count2, req_count3) VALUES(?,?,?,?,?,?,?,?,?)');
        $nSlots = 0;
        foreach ($dates as $i => $d) {
            $start = combine_dt((string)$d, (string)($starts[$i] ?? ''));
            if (!$start) continue;
            $end = slot_end_from_time($start, (string)($ends[$i] ?? ''));
            $ins->execute([$newId, $start, $end, trim((string)($names[$i] ?? '')), trim((string)($locs[$i] ?? '')), max(0, (int)($targets[$i] ?? 0)), max(0, (int)($reqs[$i] ?? 0)), max(0, (int)($reqs2[$i] ?? 0)), max(0, (int)($reqs3[$i] ?? 0))]);
            $nSlots++;
        }
        flash(($asDraft ? 'Event-Entwurf angelegt' : 'Event angelegt') . ($nSlots ? " mit $nSlots Schicht(en)." : ' – jetzt ggf. Schichten hinzufügen.'), 'success');
        redirect('events.php?edit=' . $newId);
    }

    // Ab hier: alle Aktionen betreffen ein bestehendes Event -> Besitz prüfen
    $id = (int)($_POST['id'] ?? $_POST['event_id'] ?? 0);
    $event = $id ? event_get($id) : null;
    if (!$event || !can_manage_event($event)) {
        flash('Nur Besitzer:innen oder Admins dürfen dieses Event verwalten.', 'error');
        redirect('events.php');
    }
    $slots = event_slots($id);

    if ($action === 'update_event') {
        $deadline = trim((string)($_POST['deadline'] ?? ''));
        if ($deadline !== '' && $deadline !== ($event['deadline'] ?? '') && $deadline < $minDeadline) {
            flash('Neue Abstimmungsfrist muss mindestens 2 Tage in der Zukunft liegen (frühestens ' . fmt_date($minDeadline) . ').', 'error');
            redirect('events.php?edit=' . $id);
        }
        $startsAt = trim((string)($_POST['starts_at'] ?? ''));
        $endsAt   = trim((string)($_POST['ends_at'] ?? ''));
        if ($endsAt === '' || ($startsAt !== '' && $endsAt < $startsAt)) $endsAt = $startsAt;
        $maxShifts = trim((string)($_POST['max_shifts'] ?? '')) === '' ? null : max(1, (int)$_POST['max_shifts']);
        $st = db()->prepare('UPDATE events SET title=?, description=?, max_shifts_per_member=?, deadline=?, starts_at=?, ends_at=?, important=?, req_label=?, req_label2=?, req_label3=?, closed=? WHERE id=?');
        $st->execute([
            trim((string)($_POST['title'] ?? '')),
            trim((string)($_POST['description'] ?? '')),
            $maxShifts,
            $deadline === '' ? null : $deadline,
            $startsAt === '' ? null : $startsAt,
            $endsAt === '' ? null : $endsAt,
            isset($_POST['important']) ? 1 : 0,
            trim((string)($_POST['req_label'] ?? '')),
            trim((string)($_POST['req_label2'] ?? '')),
            trim((string)($_POST['req_label3'] ?? '')),
            isset($_POST['closed']) ? 1 : 0,
            $id,
        ]);
        flash('Event gespeichert.', 'success');
        redirect('events.php?edit=' . $id);
    }

    if ($action === 'send_reminders') {
        if (event_locked($event)) {
            flash('Event ist abgeschlossen oder die Frist ist abgelaufen – keine Erinnerungen gesendet.', 'error');
        } else {
            $res = send_event_reminders($event, true);
            $n = count($res['sent']);
            flash($n > 0
                ? $n . ' Erinnerung(en) verschickt an: ' . implode(', ', $res['sent'])
                : 'Keine Erinnerung nötig – alle aktiven Mitglieder mit E-Mail haben bereits abgestimmt.',
                $n > 0 ? 'success' : 'info');
        }
        redirect('events.php?edit=' . $id);
    }

    if ($action === 'delete_event') {
        db()->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
        flash('Event gelöscht.', 'success');
        redirect('events.php');
    }

    if ($action === 'publish_event') {
        db()->prepare('UPDATE events SET draft=0 WHERE id=?')->execute([$id]);
        flash('Event veröffentlicht – jetzt für alle sichtbar.', 'success');
        redirect('events.php?edit=' . $id);
    }

    if ($action === 'save_event_template') {
        $name = trim((string)($_POST['template_name'] ?? '')) ?: (trim((string)$event['title']) ?: 'Vorlage');
        $tslots = [];
        foreach ($slots as $s) {
            $tslots[] = [
                'name'   => (string)$s['label'],
                'date'   => substr((string)$s['starts_at'], 0, 10),
                'start'  => substr((string)$s['starts_at'], 11, 5),
                'end'    => $s['ends_at'] ? substr((string)$s['ends_at'], 11, 5) : '',
                'loc'    => (string)$s['location'],
                'target' => (int)$s['target_helpers'],
                'req'    => (int)$s['req_count'],
                'req2'   => (int)($s['req_count2'] ?? 0),
                'req3'   => (int)($s['req_count3'] ?? 0),
            ];
        }
        $payload = json_encode([
            'title'      => (string)$event['title'],
            'description'=> (string)$event['description'],
            'starts_at'  => substr((string)($event['starts_at'] ?? ''), 0, 10),
            'ends_at'    => substr((string)($event['ends_at'] ?? ''), 0, 10),
            'deadline'   => (string)($event['deadline'] ?? ''),
            'max_shifts' => $event['max_shifts_per_member'] !== null ? (string)(int)$event['max_shifts_per_member'] : '',
            'important'  => (int)$event['important'],
            'req_label'  => (string)$event['req_label'],
            'req_label2' => (string)($event['req_label2'] ?? ''),
            'req_label3' => (string)($event['req_label3'] ?? ''),
            'slots'      => $tslots,
        ], JSON_UNESCAPED_UNICODE);
        db()->prepare('INSERT INTO event_templates(owner_id, name, payload) VALUES(?,?,?)')->execute([$cm['id'] ?? null, $name, $payload]);
        flash('Vorlage „' . $name . '" aus diesem Event gespeichert.', 'success');
        redirect('events.php?edit=' . $id);
    }

    if ($action === 'send_message') {
        $rid = (int)($_POST['recipient'] ?? 0);
        $body = trim((string)($_POST['body'] ?? ''));
        $recips = event_message_recipients($id);
        if (!isset($recips[$rid])) {
            flash('Diese Person hat sich nicht zurückgemeldet und ist nicht eingeteilt.', 'error');
        } elseif ($body === '') {
            flash('Bitte eine Nachricht eingeben.', 'error');
        } else {
            dm_send($rid, (string)($cm['name'] ?? 'AStA'), $body, $id, isset($_POST['send_mail']), false, 'dm_vorsitz');
            flash('Nachricht an ' . $recips[$rid] . ' hinterlassen' . (isset($_POST['send_mail']) ? ' – auch per E-Mail.' : '.'), 'success');
        }
        redirect('events.php?edit=' . $id . '&t=kontakt#nachrichten');
    }

    if ($action === 'save_owners') {
        $picked = array_map('intval', (array)($_POST['owners'] ?? []));
        $picked = array_values(array_unique(array_filter($picked, fn($x) => $x > 0)));
        // nur aktive Mitglieder zulassen
        $valid = [];
        foreach ($picked as $mid) { $mm = member_get($mid); if ($mm && (int)$mm['active'] === 1) $valid[] = $mid; }
        if (!$valid) {
            flash('Bitte mindestens eine (aktive) Besitzer:in wählen.', 'error');
        } else {
            set_event_owners($id, $valid); // begrenzt selbst auf EVENT_OWNER_MAX
            flash('Besitzer:innen gespeichert: ' . event_owner_names(event_get($id) ?? []) . '.', 'success');
        }
        redirect('events.php?edit=' . $id);
    }

    if ($action === 'add_slot') {
        $start = norm_dtl((string)($_POST['starts_at'] ?? ''));
        $endTime = trim((string)($_POST['end_time'] ?? ''));
        if ($start) {
            $end = slot_end_from_time($start, $endTime);
            db()->prepare('INSERT INTO event_slots(event_id, starts_at, ends_at, label, location, target_helpers, req_count, req_count2, req_count3) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $start, $end, trim((string)($_POST['label'] ?? '')), trim((string)($_POST['location'] ?? '')), max(0, (int)($_POST['slot_target'] ?? 0)), max(0, (int)($_POST['slot_req'] ?? 0)), max(0, (int)($_POST['slot_req2'] ?? 0)), max(0, (int)($_POST['slot_req3'] ?? 0))]);
            flash('Schicht hinzugefügt.', 'success');
        } else {
            flash('Bitte ein Start-Datum/-Zeit angeben.', 'error');
        }
        redirect('events.php?edit=' . $id);
    }

    if ($action === 'delete_slot') {
        db()->prepare('DELETE FROM event_slots WHERE id=? AND event_id=?')->execute([(int)($_POST['slot_id'] ?? 0), $id]);
        flash('Termin entfernt.', 'success');
        redirect('events.php?edit=' . $id);
    }

    if ($action === 'update_slot') {
        // Schicht IN PLACE bearbeiten: gleiche ID → Rückmeldungen und (falls vorhanden) Einteilung bleiben erhalten.
        $sid = (int)($_POST['slot_id'] ?? 0);
        $own = db()->prepare('SELECT 1 FROM event_slots WHERE id=? AND event_id=?');
        $own->execute([$sid, $id]);
        if ($own->fetchColumn()) {
            $start = norm_dtl((string)($_POST['starts_at'] ?? ''));
            if ($start) {
                $endTime = trim((string)($_POST['end_time'] ?? ''));
                $end = slot_end_from_time($start, $endTime);
                db()->prepare('UPDATE event_slots SET starts_at=?, ends_at=?, label=?, location=?, target_helpers=?, req_count=?, req_count2=?, req_count3=? WHERE id=? AND event_id=?')
                    ->execute([$start, $end, trim((string)($_POST['label'] ?? '')), trim((string)($_POST['location'] ?? '')),
                               max(0, (int)($_POST['slot_target'] ?? 0)), max(0, (int)($_POST['slot_req'] ?? 0)),
                               max(0, (int)($_POST['slot_req2'] ?? 0)), max(0, (int)($_POST['slot_req3'] ?? 0)), $sid, $id]);
                flash('Schicht aktualisiert – Rückmeldungen bleiben erhalten.', 'success');
            } else {
                flash('Bitte ein Start-Datum/-Zeit angeben.', 'error');
            }
        }
        redirect('events.php?edit=' . $id);
    }

    // --- Arbeitsplan / Einteilung ---
    if ($action === 'notify_plan') {
        $sent = notify_assigned_members($event, false);
        db()->prepare('UPDATE events SET plan_locked = 1 WHERE id = ?')->execute([$id]);
        flash($sent ? count($sent) . ' Helfer benachrichtigt: ' . implode(', ', $sent) : 'Niemand mit E-Mail eingeteilt – keine Mail verschickt.', $sent ? 'success' : 'info');
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'unlock_plan') {
        db()->prepare('UPDATE events SET plan_locked = 0 WHERE id = ?')->execute([$id]);
        flash('Arbeitsplan zum Bearbeiten geöffnet.', 'info');
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'toggle_swap_open') {
        // Schichtbörse für dieses Event offen halten bzw. wieder auf den Standard-Vorlauf zurückstellen.
        $on = (int)!($event['swap_force_open'] ?? 0);
        db()->prepare('UPDATE events SET swap_force_open = ? WHERE id = ?')->execute([$on, $id]);
        flash($on ? 'Schichtbörse für dieses Event offen gehalten – auch innerhalb des Vorlaufs bzw. nachträglich.' : 'Schichtbörse folgt wieder dem Standard-Vorlauf.', 'success');
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'unlock_all_fixations') {
        // Alle Schlösser lösen – ohne neu zu mischen und ohne den Plan unzuveröffentlichen (Börse bleibt offen).
        db()->prepare('UPDATE assignments SET locked = 0 WHERE slot_id IN (SELECT id FROM event_slots WHERE event_id = ?)')->execute([$id]);
        flash('Alle Fixierungen gelöst – die Schichten sind jetzt wieder über die Schichtbörse tauschbar.', 'success');
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'toggle_lock') {
        // Eine einzelne Person fixieren/lösen – direkt aus der veröffentlichten Ansicht, nur fürs Tauschen (Plan unverändert).
        $sid = (int)($_POST['slot_id'] ?? 0); $mid = (int)($_POST['member_id'] ?? 0);
        $own = db()->prepare('SELECT 1 FROM event_slots WHERE id = ? AND event_id = ?'); $own->execute([$sid, $id]);
        if ($own->fetchColumn()) toggle_assignment_lock($sid, $mid);
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'set_shift_lock') {
        // Eine ganze Schicht fixieren/lösen (alle dort Eingeteilten) – nur fürs Tauschen, Plan unverändert.
        $sid = (int)($_POST['slot_id'] ?? 0); $lock = (($_POST['lock'] ?? '') === '1') ? 1 : 0;
        $own = db()->prepare('SELECT 1 FROM event_slots WHERE id = ? AND event_id = ?'); $own->execute([$sid, $id]);
        if ($own->fetchColumn()) {
            db()->prepare('UPDATE assignments SET locked = ? WHERE slot_id = ?')->execute([$lock, $sid]);
            flash($lock ? 'Schicht fixiert – nicht mehr über die Börse tauschbar.' : 'Schicht entsperrt – wieder tauschbar.', 'success');
        }
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'remove_assignment') {
        // Eine einzelne Person direkt aus der veröffentlichten Ansicht aus ihrer Schicht nehmen
        // (ohne den Plan zu bearbeiten/unzuveröffentlichen). Praktisch v. a. für kurzfristig Eingesprungene.
        $sid = (int)($_POST['slot_id'] ?? 0); $mid = (int)($_POST['member_id'] ?? 0);
        $own = db()->prepare('SELECT 1 FROM event_slots WHERE id = ? AND event_id = ?'); $own->execute([$sid, $id]);
        if ($own->fetchColumn() && $mid > 0) {
            db()->prepare('DELETE FROM assignments WHERE slot_id = ? AND member_id = ?')->execute([$sid, $mid]);
            swap_invalidate_orphaned($id); // evtl. angebotene Schicht dieser Person aus der Tauschbörse entfernen
            $slotRow = event_slot_get($sid);
            if ($slotRow) {
                $lab = fmt_slot($slotRow['starts_at'], $slotRow['ends_at']) . ($slotRow['label'] ? ' (' . $slotRow['label'] . ')' : '');
                dm_send($mid, 'Arbeitsplan', 'Die Orga hat dich bei „' . (string)$event['title'] . '" aus dieser Schicht genommen: ' . $lab . '.', $id, false, false, 'dm_plan');
            }
            flash('Person aus der Schicht genommen.', 'success');
        }
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'auto_assign') {
        $fairMode = ($_POST['fair'] ?? '') === 'hours' ? 'hours' : 'shifts';
        auto_assign_event($event, $fairMode);
        $n = (int)db()->query('SELECT COUNT(*) FROM assignments a JOIN event_slots s ON s.id=a.slot_id WHERE s.event_id=' . $id)->fetchColumn();
        flash($n > 0 ? "Zufällig eingeteilt (fair nach " . ($fairMode === 'hours' ? 'Stunden' : 'Schichten') . "): $n Schicht-Plätze besetzt." : 'Keine Einteilung möglich – fehlt eine Ziel-Helferzahl oder gibt es keine Zusagen?', $n > 0 ? 'success' : 'info');
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'clear_plan') {
        clear_event_assignments($id);
        swap_invalidate_orphaned($id); // ohne Einteilung gibt es keine anbietbaren Schichten mehr
        flash('Einteilung geleert.', 'success');
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'assign_keep_locked') {
        // Fixierungen aus dem Formular übernehmen (Person ggf. neu eintragen) und dann neu einteilen.
        // Bleibt ein ENTWURF (plan_locked unberührt) – Mitglieder sehen nichts.
        $locks = $_POST['lock'] ?? []; // [slot_id => [member_id, ...]]
        db()->prepare('UPDATE assignments SET locked = 0 WHERE slot_id IN (SELECT id FROM event_slots WHERE event_id = ?)')->execute([$id]);
        $insL = db()->prepare('INSERT OR IGNORE INTO assignments(slot_id, member_id) VALUES(?,?)');
        $setL = db()->prepare('UPDATE assignments SET locked = 1 WHERE slot_id = ? AND member_id = ?');
        foreach ($slots as $s) {
            $slotId = (int)$s['id'];
            foreach ((array)($locks[$slotId] ?? []) as $mid) {
                $mid = (int)$mid;
                if ($mid <= 0 || !member_get($mid)) continue;
                $insL->execute([$slotId, $mid]);   // fixierte Person sicher eingetragen
                $setL->execute([$slotId, $mid]);
            }
        }
        $fairMode = ($_POST['fair'] ?? '') === 'hours' ? 'hours' : 'shifts';
        auto_assign_event($event, $fairMode); // hält die Fixierten, verteilt den Rest neu
        flash('Neu eingeteilt (fair nach ' . ($fairMode === 'hours' ? 'Stunden' : 'Schichten') . ') – fixierte Plätze beibehalten. Noch nicht veröffentlicht.', 'success');
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
    if ($action === 'save_plan') {
        $picks = $_POST['assign'] ?? [];        // [slot_id => [member_id, ...]]
        $before = event_assign_status($id);     // [slot_id => [member_id => bool notified]]
        $oldSets = []; $wasNotified = [];
        foreach ($before as $slotId => $ms) {
            foreach ($ms as $mid => $n) { $oldSets[$mid][] = (int)$slotId; if ($n) $wasNotified[$mid] = true; }
        }
        $del = db()->prepare('DELETE FROM assignments WHERE slot_id = ? AND member_id = ?');
        $ins = db()->prepare('INSERT OR IGNORE INTO assignments(slot_id, member_id) VALUES(?,?)');
        foreach ($slots as $s) {
            $slotId = (int)$s['id'];
            $cur = array_keys($before[$slotId] ?? []);
            $desired = array_values(array_filter(array_map('intval', (array)($picks[$slotId] ?? [])), fn($mid) => (bool)member_get($mid)));
            foreach ($cur as $mid)     if (!in_array($mid, $desired, true)) $del->execute([$slotId, $mid]);
            foreach ($desired as $mid) if (!in_array($mid, $cur, true))     $ins->execute([$slotId, $mid]);
        }
        // Fixierungen (Schloss) aus dem Formular übernehmen, damit sie auch nach dem Veröffentlichen gelten
        $locks = $_POST['lock'] ?? []; // [slot_id => [member_id, ...]]
        db()->prepare('UPDATE assignments SET locked = 0 WHERE slot_id IN (SELECT id FROM event_slots WHERE event_id = ?)')->execute([$id]);
        $setLock = db()->prepare('UPDATE assignments SET locked = 1 WHERE slot_id = ? AND member_id = ?');
        foreach ($slots as $s) {
            $slotId = (int)$s['id'];
            foreach ((array)($locks[$slotId] ?? []) as $mid) $setLock->execute([$slotId, (int)$mid]);
        }
        $newSets = [];
        foreach (event_assignments($id) as $slotId => $mids) {
            foreach ($mids as $mid) $newSets[$mid][] = (int)$slotId;
        }
        $removed = [];
        $resetStmt = db()->prepare("UPDATE assignments SET notified_at = NULL WHERE member_id = ? AND slot_id IN (SELECT id FROM event_slots WHERE event_id = ?)");
        foreach (array_unique(array_merge(array_keys($oldSets), array_keys($newSets))) as $mid) {
            $old = $oldSets[$mid] ?? []; sort($old);
            $new = $newSets[$mid] ?? []; sort($new);
            if ($old === $new) continue;
            if (!$new) { if (!empty($wasNotified[$mid])) $removed[] = (int)$mid; }
            elseif (!empty($wasNotified[$mid])) { $resetStmt->execute([(int)$mid, $id]); }
        }
        // Speichern = veröffentlichen: ab jetzt sehen Mitglieder ihren Plan (Dashboard, Kalender, iCal …).
        // Der einzige Unterschied der beiden Optionen ist, ob zusätzlich eine Mail rausgeht.
        db()->prepare('UPDATE events SET plan_locked = 1 WHERE id = ?')->execute([$id]);
        swap_invalidate_orphaned($id); // Tausch-Angebote von Personen entfernen, die ihre Schicht nicht mehr haben
        if (($_POST['notify'] ?? '0') === '1') {
            $sent = notify_assigned_members($event, true);
            $cancelled = $removed ? notify_removed_members($event, $removed) : [];
            $parts = [];
            if ($sent)      $parts[] = count($sent) . ' informiert: ' . implode(', ', $sent);
            if ($cancelled) $parts[] = count($cancelled) . ' abgemeldet: ' . implode(', ', $cancelled);
            flash('Arbeitsplan veröffentlicht. ' . ($parts ? implode(' · ', $parts) : 'Keine Benachrichtigung nötig.'), 'success');
        } else {
            flash('Arbeitsplan veröffentlicht (ohne Mail – Mitglieder sehen ihn jetzt in App & Kalender).'
                . ($removed ? ' Hinweis: ' . count($removed) . ' gestrichene Person(en) wurden nicht informiert.' : ''), 'success');
        }
        redirect('events.php?edit=' . $id . '&t=plan#plan');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? event_get($editId) : null;
if ($edit && !can_manage_event($edit)) {
    flash('Dieses Event kannst du nicht verwalten.', 'error');
    redirect('events.php');
}
if ($edit && !can_see_event_draft($edit)) { // fremden Entwurf nicht öffnen
    flash('Dieser Event-Entwurf gehört jemand anderem.', 'error');
    redirect('events.php');
}

// Vorlagen (für alle zugänglich) + ggf. geladene Vorlage zum Vorbefüllen des Anlege-Formulars
$templates = db()->query('SELECT id, name FROM event_templates ORDER BY name COLLATE NOCASE')->fetchAll();
$tpl = null; $tplId = (int)($_GET['tpl'] ?? 0);
if ($tplId) {
    $st = db()->prepare('SELECT * FROM event_templates WHERE id=?');
    $st->execute([$tplId]);
    if ($row = $st->fetch()) $tpl = (json_decode((string)$row['payload'], true) ?: []) + ['_name' => $row['name'], '_owner' => (int)$row['owner_id']];
}

page_header('Events verwalten', true);
?>
<p class="small"><a href="../events.php">‹ Events-Übersicht</a></p>

<?php
/* REITER in der BEARBEITEN-Ansicht. Die Übersicht bleibt unangetastet – dort steht nur die
   Liste. Beim Bearbeiten gehören sieben Karten zusammen, von den Event-Daten bis zu den
   Besitzer:innen; gebraucht wird fast immer genau eine davon. Die Karten sind NICHT
   verschoben, nur in ihren Reiter gehüllt (Umbau-Regel). */
$eTabs = [
    'daten'     => ['label' => 'Event',           'icon' => 'ti-calendar-event'],
    'schichten' => ['label' => 'Schichten',       'icon' => 'ti-clock'],
    'plan'      => ['label' => 'Einteilung',      'icon' => 'ti-clipboard-list'],
    'kontakt'   => ['label' => 'Nachrichten',     'icon' => 'ti-message-2'],
    'mehr'      => ['label' => 'Vorlage & Besitz','icon' => 'ti-template'],
];
$eTab = (string)($_GET['t'] ?? '');
if (!isset($eTabs[$eTab])) $eTab = 'daten';
?>
<?php if ($edit):
    $slots = slots_grouped(event_slots((int)$edit['id'])); // gleiche Reihenfolge wie „Wer kann wann"
    $anyTarget = array_sum(array_map(fn($s) => (int)$s['target_helpers'], $slots)) > 0;
    $maxShifts = (int)($edit['max_shifts_per_member'] ?? 0);
    $planLocked = !empty($edit['plan_locked']);
    $assign = event_assign_status((int)$edit['id']);
    $lockedMap = locked_assignments((int)$edit['id']); // [slot_id => [member_id, ...]] – fixierte Zuteilungen
    $selfClaimedMap = self_claimed_assignments((int)$edit['id']); // [slot_id => [member_id => true]] – selbst Eingesprungene
    $responses = event_responses((int)$edit['id']);
    $attrLabels = event_attr_labels($edit);           // [idx => Label] der aktiven Merkmale
    $attrHoldersAll = event_attr_holders_all($edit);  // [idx => [member_id => true]]
    $attrHolders = $attrHoldersAll[1] ?? [];          // Merkmal 1 (Abwärtskompat. für Alt-Code)
    $reqLabel = trim((string)($edit['req_label'] ?? ''));
    // Merkmal-Pillen einer Schicht: je aktivem Merkmal mit Bedarf „X/Y" (grün, wenn gedeckt).
    $attrPills = function (array $s, array $assignedHere) use ($attrLabels, $attrHoldersAll): string {
        $out = '';
        foreach ($attrLabels as $ai => $alab) {
            $rc = (int)($s[attr_count_col($ai)] ?? 0);
            if ($rc <= 0) continue;
            $ah = 0; foreach (array_keys($assignedHere) as $m) if (!empty($attrHoldersAll[$ai][$m])) $ah++;
            $out .= ' <span class="pill ' . ($ah >= $rc ? 'pill-ok' : 'pill-bad') . '" title="' . h($alab) . '"><i class="ti ti-discount-check-filled attr-badge a' . (int)$ai . '"></i> ' . $ah . '/' . $rc . '</span>';
        }
        return $out;
    };
    // Merkmal-Badges eines Mitglieds: alle Merkmale, die es hat.
    $attrBadges = function (int $mid) use ($attrLabels, $attrHoldersAll): string {
        $out = '';
        foreach ($attrLabels as $ai => $alab) if (!empty($attrHoldersAll[$ai][$mid])) {
            $out .= ' <i class="ti ti-discount-check-filled attr-badge a' . (int)$ai . '" title="' . h($alab) . '"></i>';
        }
        return $out;
    };
    $mname = [];
    foreach (members_all(true) as $m) $mname[(int)$m['id']] = $m['name'];
?>
  <h1>Event bearbeiten <?php if (!empty($edit['draft'])): ?><span class="badge badge-draft">Entwurf</span><?php endif; ?></h1>
  <?php if (!empty($edit['draft'])): ?>
    <div class="card" style="border-left:4px solid var(--amber)">
      <div style="display:flex;align-items:center;gap:.8rem;flex-wrap:wrap">
        <div style="flex:1 1 auto"><strong><i class="ti ti-eye-off"></i> Entwurf – nur für dich sichtbar.</strong> <span class="small muted">Teste alles in Ruhe; mit „Veröffentlichen" wird es für alle sichtbar.</span></div>
        <form method="post" data-confirm="Dieses Event veröffentlichen? Es wird dann für alle sichtbar." data-confirm-ok="Veröffentlichen">
          <?= csrf_field() ?><input type="hidden" name="action" value="publish_event"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
          <button class="btn" type="submit"><i class="ti ti-rocket"></i> Veröffentlichen</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
  <nav class="wl-adm-tabs" aria-label="Bereiche">
    <?php foreach ($eTabs as $tk => $td): ?>
      <a<?= $tk === $eTab ? ' class="on" aria-current="page"' : '' ?> href="events.php?edit=<?= (int)$edit['id'] ?>&amp;t=<?= h($tk) ?>">
        <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?>
        <?php if ($tk === 'schichten' && $slots): ?><span class="count"><?= count($slots) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($eTab === 'daten'): ?>
  <div class="card">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_event">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <label for="title">Titel</label>
      <input type="text" name="title" id="title" value="<?= h($edit['title']) ?>" required>
      <label for="description">Beschreibung</label>
      <textarea name="description" id="description"><?= h($edit['description']) ?></textarea>
      <div class="field-row">
        <div>
          <label for="starts_at">Event von</label>
          <input type="text" class="fp-date" name="starts_at" id="starts_at" value="<?= h(substr((string)($edit['starts_at'] ?? ''), 0, 10)) ?>" placeholder="Datum wählen">
        </div>
        <div>
          <label for="ends_at">bis</label>
          <input type="text" class="fp-date" name="ends_at" id="ends_at" value="<?= h(substr((string)($edit['ends_at'] ?? ''), 0, 10)) ?>" placeholder="Datum wählen">
        </div>
        <div>
          <label for="deadline">Abstimmungsfrist</label>
          <input type="text" class="fp-date" name="deadline" id="deadline" value="<?= h($edit['deadline'] ?? '') ?>" placeholder="Datum wählen">
        </div>
      </div>
      <div class="field-row">
        <div>
          <label for="max_shifts">Max. Schichten pro Person</label>
          <input type="number" name="max_shifts" id="max_shifts" min="1" value="<?= $edit['max_shifts_per_member'] !== null ? (int)$edit['max_shifts_per_member'] : '' ?>" placeholder="leer = unbegrenzt">
        </div>
        <div style="display:flex;align-items:flex-end">
          <label class="wl-sw slim"><input type="checkbox" name="closed" <?= $edit['closed'] ? 'checked' : '' ?>>
            <span class="wl-sw-track" aria-hidden="true"></span>
            <span class="wl-sw-txt"><strong>abgeschlossen</strong></span></label>
        </div>
      </div>
      <label class="wl-sw"><input type="checkbox" name="important" <?= !empty($edit['important']) ? 'checked' : '' ?>>
        <span class="wl-sw-track" aria-hidden="true"></span>
        <span class="wl-sw-txt"><strong>Extrem wichtig</strong>
          <span>Warnt beim Eintragen von Abwesenheiten in diesem Zeitraum.</span></span></label>
      <label for="req_label" style="margin-top:.6rem">Zusatz-Abfragen (optional, bis zu 3 Merkmale)</label>
      <div class="attr-input-row"><input type="text" name="req_label" id="req_label" value="<?= h($edit['req_label'] ?? '') ?>" placeholder="Merkmal 1, z. B. Führerschein"><i class="ti ti-discount-check-filled attr-badge a1" title="Farbe für Merkmal 1"></i></div>
      <div class="attr-input-row"><input type="text" name="req_label2" value="<?= h($edit['req_label2'] ?? '') ?>" placeholder="Merkmal 2 (optional)"><i class="ti ti-discount-check-filled attr-badge a2" title="Farbe für Merkmal 2"></i></div>
      <div class="attr-input-row"><input type="text" name="req_label3" value="<?= h($edit['req_label3'] ?? '') ?>" placeholder="Merkmal 3 (optional)"><i class="ti ti-discount-check-filled attr-badge a3" title="Farbe für Merkmal 3"></i></div>
      <p class="small muted" style="margin:.4rem 0 0">Mitglieder kreuzen die zutreffenden Merkmale beim Zurückmelden an (jedes Merkmal hat seine Farbe). Pro Schicht legst du unten fest, wie viele mit dem jeweiligen Merkmal gebraucht werden.</p>
      <?php if (deadline_passed($edit['deadline'] ?? null)): ?>
        <p class="warn-line small">Die Frist (<?= fmt_date($edit['deadline']) ?>) ist abgelaufen – die Abstimmung ist gesperrt.</p>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:.9rem">
        <button class="btn" type="submit">Speichern</button>
        <a class="btn secondary" href="../event.php?id=<?= (int)$edit['id'] ?>">Öffentliche Ansicht</a>
      </div>
    </form>
  </div>

  <?php endif; /* Ende Reiter „Event" – das Löschen steht am Fuß desselben Reiters */ ?>

  <?php if ($eTab === 'schichten'): ?>
  <div class="card">
    <h2>Schichten</h2>
    <?php if (!$slots): ?>
      <p class="muted">Noch keine Schichten. Füge unten welche hinzu.</p>
    <?php else: ?>
      <table class="list">
        <thead><tr><th>Name</th><th>Termin</th><th>Ort</th><th>Helfer</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($slots as $s): ?>
          <tr>
            <td><?= $s['label'] ? h($s['label']) : '<span class="muted">–</span>' ?></td>
            <td><?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?></td>
            <td class="muted"><?= h($s['location']) ?></td>
            <td><?= (int)$s['target_helpers'] > 0 ? (int)$s['target_helpers'] : '<span class="muted">–</span>' ?><?php
              foreach ($attrLabels as $ai => $alab) { $rc = (int)($s[attr_count_col($ai)] ?? 0); if ($rc > 0) echo ' <span class="muted small">(' . $rc . '&nbsp;×&nbsp;' . h($alab) . ')</span>'; }
            ?></td>
            <td style="text-align:right;white-space:nowrap">
              <button class="btn secondary small" type="button" onclick="var r=document.getElementById('slotedit-<?= (int)$s['id'] ?>');r.hidden=!r.hidden;"><i class="ti ti-edit"></i> Bearbeiten</button>
              <form method="post" data-confirm="Diese Schicht löschen? Die Rückmeldungen dazu gehen verloren." data-confirm-danger data-confirm-ok="Löschen" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_slot">
                <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                <button class="btn danger small" type="submit">Löschen</button>
              </form>
            </td>
          </tr>
          <tr class="slot-edit-row" id="slotedit-<?= (int)$s['id'] ?>" hidden>
            <td colspan="5">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_slot">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
                <div class="field-row">
                  <div><label>Name</label><input type="text" name="label" value="<?= h((string)$s['label']) ?>" placeholder="z. B. Abbau"></div>
                  <div><label>Start (Datum &amp; Uhrzeit)</label><input type="text" class="fp-datetime" name="starts_at" value="<?= h(substr((string)$s['starts_at'], 0, 16)) ?>" required></div>
                  <div><label>Ende (geschätzt)</label><input type="text" class="fp-time" name="end_time" value="<?= $s['ends_at'] ? h(substr((string)$s['ends_at'], 11, 5)) : '' ?>" placeholder="--:--" required title="Frühere Uhrzeit als der Start = am nächsten Tag (z. B. 21:00–02:00)."></div>
                  <div><label>Ort</label><input type="text" name="location" value="<?= h((string)$s['location']) ?>"></div>
                  <div><label>Benötigte Helfer</label><input type="number" name="slot_target" min="0" value="<?= (int)$s['target_helpers'] ?>"></div>
                  <?php foreach ($attrLabels as $ai => $alab): ?>
                    <div><label>Davon mit <?= h($alab) ?></label><input type="number" name="slot_req<?= $ai > 1 ? (int)$ai : '' ?>" min="0" value="<?= (int)($s[attr_count_col($ai)] ?? 0) ?>"></div>
                  <?php endforeach; ?>
                </div>
                <div class="btn-row" style="margin-top:.7rem">
                  <button class="btn small" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
                  <button class="btn small secondary" type="button" onclick="document.getElementById('slotedit-<?= (int)$s['id'] ?>').hidden=true">Abbrechen</button>
                  <span class="small muted" style="align-self:center"><i class="ti ti-info-circle"></i> Gleiche Schicht – Rückmeldungen &amp; Einteilung bleiben erhalten.</span>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3>Schicht hinzufügen</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_slot">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <div class="field-row">
        <div><label for="label">Name</label><input type="text" name="label" id="label" placeholder="z. B. Abbau"></div>
        <div><label for="starts_at_new">Start (Datum &amp; Uhrzeit)</label><input type="text" class="fp-datetime" name="starts_at" id="starts_at_new" placeholder="Datum &amp; Uhrzeit wählen" required></div>
        <div><label for="end_time">Ende (geschätzt)</label><input type="text" class="fp-time" name="end_time" id="end_time" placeholder="--:--" required title="Frühere Uhrzeit als der Start = am nächsten Tag (z. B. 21:00–02:00)."></div>
        <div><label for="location">Ort</label><input type="text" name="location" id="location" placeholder="z. B. Studibühne"></div>
        <div><label for="slot_target">Benötigte Helfer</label><input type="number" name="slot_target" id="slot_target" min="0" placeholder="z. B. 2"></div>
        <?php foreach ($attrLabels as $ai => $alab): ?>
          <div><label>Davon mit <?= h($alab) ?></label><input type="number" name="slot_req<?= $ai > 1 ? (int)$ai : '' ?>" min="0" placeholder="0"></div>
        <?php endforeach; ?>
      </div>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit">Schicht hinzufügen</button></div>
    </form>
  </div>

  <?php endif; ?>

  <?php // ===== Arbeitsplan / Einteilung ===== ?>
  <?php if ($eTab === 'plan'): ?>
  <div class="card" id="plan" style="border-color:var(--petrol)">
    <h2 style="margin-top:0"><i class="ti ti-clipboard-list"></i> Arbeitsplan / Einteilung
      <?php if ($planLocked): ?> <span class="pill pill-ok" style="font-size:.72rem"><i class="ti ti-lock"></i> veröffentlicht</span><?php endif; ?>
    </h2>
    <?php if ($slots): ?>
      <div class="btn-row" style="margin-bottom:1rem"><a class="btn secondary" href="plan.php?id=<?= (int)$edit['id'] ?>" target="_blank"><i class="ti ti-printer"></i> Arbeitsplan (Druckansicht / PDF)</a></div>
    <?php endif; ?>
    <?php if (!$slots): ?>
      <p class="muted small">Erst Termine anlegen, dann kann eingeteilt werden.</p>
    <?php elseif ($planLocked): // ===== GESPERRTE ANSICHT ===== ?>
      <p class="muted small">Der Plan ist veröffentlicht und gegen versehentliches Ändern gesperrt. Zum Bearbeiten auf „Arbeitsplan ändern". <i class="ti ti-mail-check" style="color:var(--green)"></i> = bereits benachrichtigt. <i class="ti ti-lock" style="color:var(--petrol)"></i>/<i class="ti ti-lock-open" style="color:var(--muted)"></i> direkt anklicken, um eine Person – oder per Schicht-Schloss die ganze Schicht – für die <strong>Tauschbörse</strong> zu sperren bzw. freizugeben (ändert die Einteilung nicht). Bei <strong>kurzfristig Eingesprungenen</strong> (<i class="ti ti-hand-finger"></i>) erscheint zusätzlich ein <i class="ti ti-x"></i>: damit nimmst du sie direkt wieder <strong>aus der Schicht</strong> – der Platz wird frei, die Person bekommt einen Dashboard-Hinweis. Regulär Eingeteilte änderst du wie gewohnt über „Arbeitsplan ändern".</p>
      <?php foreach ($slots as $s):
          $slotId = (int)$s['id'];
          $assignedHere = $assign[$slotId] ?? [];
          $ac = count($assignedHere);
          $tt = (int)$s['target_helpers']; $cls = $tt > 0 ? ($ac >= $tt ? 'pill-ok' : ($ac > 0 ? 'pill-warn' : 'pill-bad')) : 'pill-info';
      ?>
        <div class="plan-line">
          <div class="plan-line-head">
            <strong><?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?></strong>
            <?php if ($s['location']): ?><span class="muted"><i class="ti ti-map-pin" style="font-size:.9em"></i> <?= h($s['location']) ?></span><?php endif; ?>
            <?php if ($s['label']): ?><span class="muted">· <?= h($s['label']) ?></span><?php endif; ?>
            <span class="pill <?= $cls ?>" style="margin-left:auto"><?= $ac ?><?= $tt > 0 ? '/' . $tt : '' ?> eingeteilt</span><?= $attrPills($s, $assignedHere) ?>
            <?php if ($assignedHere): $allLockedHere = !array_diff(array_keys($assignedHere), $lockedMap[$slotId] ?? []); ?>
              <form method="post" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_shift_lock">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                <input type="hidden" name="slot_id" value="<?= $slotId ?>">
                <input type="hidden" name="lock" value="<?= $allLockedHere ? '0' : '1' ?>">
                <button class="shift-lock<?= $allLockedHere ? ' on' : '' ?>" type="submit" title="Ganze Schicht für die Börse <?= $allLockedHere ? 'entsperren' : 'fixieren' ?>"><i class="ti ti-lock<?= $allLockedHere ? '' : '-open' ?>"></i></button>
              </form>
            <?php endif; ?>
          </div>
          <?php if (!$assignedHere): ?>
            <div class="plan-people muted">— niemand eingeteilt</div>
          <?php else: ?>
            <div class="plan-people">
              <?php foreach ($assignedHere as $mid => $notified): $isLockedP = in_array((int)$mid, $lockedMap[$slotId] ?? [], true); ?>
                <span class="pp-chip<?= $isLockedP ? ' locked' : '' ?>">
                  <form method="post" class="pp-lockform">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_lock">
                    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                    <input type="hidden" name="slot_id" value="<?= $slotId ?>">
                    <input type="hidden" name="member_id" value="<?= (int)$mid ?>">
                    <button type="submit" class="pp-lock<?= $isLockedP ? ' on' : '' ?>" title="<?= $isLockedP ? 'fixiert – klicken zum Lösen (Börse)' : 'klicken zum Fixieren (Börse)' ?>"><i class="ti ti-lock<?= $isLockedP ? '' : '-open' ?>"></i></button>
                  </form>
                  <?= h($mname[$mid] ?? ('#' . $mid)) ?>
                  <?php if ($notified): ?><i class="ti ti-mail-check" style="color:var(--green)" title="benachrichtigt"></i><?php else: ?><i class="ti ti-mail-off" style="color:var(--muted)" title="noch nicht benachrichtigt"></i><?php endif; ?>
                  <?php if (!empty($selfClaimedMap[$slotId][(int)$mid])): ?>
                    <i class="ti ti-hand-finger" style="color:var(--muted);font-size:.85em" title="kurzfristig selbst eingesprungen"></i>
                    <form method="post" class="pp-lockform" data-confirm="<?= h($mname[$mid] ?? ('#' . $mid)) ?> aus dieser Schicht nehmen? Die Person ist kurzfristig selbst eingesprungen und wird per Dashboard-Hinweis informiert. Der Platz wird wieder frei (auch zum Einspringen)." data-confirm-title="Aus Schicht nehmen" data-confirm-ok="Entfernen" data-confirm-danger>
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="remove_assignment">
                      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                      <input type="hidden" name="slot_id" value="<?= $slotId ?>">
                      <input type="hidden" name="member_id" value="<?= (int)$mid ?>">
                      <button type="submit" class="pp-remove" title="Aus dieser Schicht nehmen"><i class="ti ti-x"></i></button>
                    </form>
                  <?php endif; ?>
                </span><?= ' ' ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php $anyLocked = false; foreach ($lockedMap as $lm) { if ($lm) { $anyLocked = true; break; } } ?>
      <?php if ($anyLocked): ?>
        <p class="small muted" style="margin:1rem 0 .3rem"><i class="ti ti-lock" style="color:var(--petrol)"></i> Fixierte Plätze (🔒) lassen sich <strong>nicht</strong> über die Schichtbörse tauschen. Wenn die Leute tauschen können sollen, hier lösen – der Plan bleibt veröffentlicht.</p>
      <?php endif; ?>
      <?php $swForce = !empty($edit['swap_force_open']); $swOpen = swap_market_open($edit); $swLead = swap_lead_days(); ?>
      <p class="small muted" style="margin:1rem 0 .3rem"><i class="ti ti-arrows-exchange" style="color:var(--petrol)"></i> <strong>Schichtbörse:</strong>
        <?php if ($swForce): ?>von dir <strong>offen gehalten</strong> (Standard-Vorlauf von <?= (int)$swLead ?> Tag<?= $swLead === 1 ? '' : 'en' ?> übersteuert)<?php else: ?>schließt automatisch <strong><?= (int)$swLead ?> Tag<?= $swLead === 1 ? '' : 'e' ?></strong> vor Event-Start<?php endif; ?>
        · aktuell <strong><?= $swOpen ? 'offen' : 'geschlossen' ?></strong>. <span class="muted">Standard-Vorlauf global unter <?= app_place('verwaltung', 'Verwaltung → Einstellungen', 'einstellungen') ?>.</span></p>
      <div class="btn-row" style="margin-top:<?= $anyLocked ? '.3rem' : '1rem' ?>">
        <form method="post" data-confirm="Zum Bearbeiten wird der Arbeitsplan wieder ent-veröffentlicht. Die Mitglieder sehen ihre Einteilung dann vorübergehend NICHT mehr (Dashboard, Kalender, iCal) und die Schichtbörse ist zu, bis du erneut veröffentlichst. Fortfahren?" data-confirm-title="Arbeitsplan ent-veröffentlichen?" data-confirm-ok="Bearbeiten"><?= csrf_field() ?><input type="hidden" name="action" value="unlock_plan"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn" type="submit"><i class="ti ti-edit"></i> Arbeitsplan ändern</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_swap_open"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn secondary" type="submit"><i class="ti ti-<?= $swForce ? 'clock-play' : 'lock-open' ?>"></i> <?= $swForce ? 'Börse: Standard-Vorlauf' : 'Schichtbörse offen halten' ?></button></form>
        <?php if ($anyLocked): ?>
        <form method="post" data-confirm="Alle Fixierungen (🔒) lösen? Die Einteilung bleibt unverändert, aber die Schichten werden wieder über die Schichtbörse tauschbar. Der Plan bleibt veröffentlicht." data-confirm-title="Fixierungen lösen" data-confirm-ok="Alle lösen"><?= csrf_field() ?><input type="hidden" name="action" value="unlock_all_fixations"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn secondary" type="submit"><i class="ti ti-lock-open"></i> Alle Fixierungen lösen</button></form>
        <?php endif; ?>
        <form method="post" data-confirm="Allen eingeteilten Helfern (mit E-Mail) ihren Plan erneut schicken?" data-confirm-title="Alle benachrichtigen" data-confirm-ok="Senden"><?= csrf_field() ?><input type="hidden" name="action" value="notify_plan"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn secondary" type="submit"><i class="ti ti-mail"></i> Alle benachrichtigen</button></form>
      </div>

    <?php else: // ===== BEARBEITEN ===== ?>
      <p class="muted small">
        Die Zufallseinteilung verwendet nur Zusagen (Ja). „Evtl." wird zum Fristende automatisch zu „Nein" und nicht eingeteilt.
        <?php if ($anyTarget): ?>Pro Schicht wird die jeweils gesetzte Helferzahl angestrebt.<?php else: ?><span class="warn-line">Keine Schicht hat eine Helferzahl – Zufallseinteilung nicht möglich (manuell geht).</span><?php endif; ?>
        <?php if ($maxShifts > 0): ?> Max. <strong><?= $maxShifts ?></strong> Schicht(en) pro Person.<?php else: ?> Mehrfach-Einteilung unbegrenzt.<?php endif; ?>
        <?php if (!deadline_passed($edit['deadline'] ?? null) && !empty($edit['deadline'])): ?><br><span class="warn-line">Hinweis: Die Abstimmungsfrist läuft noch – am besten erst nach Fristende einteilen.</span><?php endif; ?>
        <br><i class="ti ti-mail-check" style="color:var(--green)"></i> = wurde zu dieser Einteilung schon benachrichtigt.
      </p>
      <p class="small muted" style="margin:-.3rem 0 .8rem"><i class="ti ti-lock" style="color:var(--petrol)"></i> <strong>Fixieren:</strong> Setz bei einer Person das <strong>Schloss-Häkchen</strong> – dann bleibt sie beim „Zufällig einteilen" auf ihrer Schicht, alle anderen Plätze werden neu verteilt. Du kannst jemanden auch von Hand in eine Schicht setzen (linkes Häkchen) und gleich fixieren. Eine fixierte Schicht zählt ganz normal (Schicht-Zähler &amp; Eventscore). Das Raster zeigt immer den <strong>aktuellen Ist-Stand</strong> – auch kurzfristig Eingesprungene (✋) und ohne Zusage übernommene Schichten (z. B. per Tausch) sind angehakt und bleiben beim Speichern erhalten, solange du sie nicht abwählst. Das passiert alles noch im <strong>Entwurf</strong> – sichtbar wird der Plan erst beim Veröffentlichen.</p>
      <div class="btn-row" style="margin-bottom:1rem">
        <button class="btn" type="button" <?= $anyTarget ? '' : 'disabled' ?> onclick="astaConfirm({title:'Zufällig einteilen',icon:'ti-wand',message:'Nicht fixierte Plätze neu zufällig verteilen – fair nach SCHICHTEN (gleich viele Einsätze pro Person) oder fair nach STUNDEN (gleich viel Zeit pro Person, Schichtdauer zählt)? Fixierte (🔒) und kurzfristig Eingesprungene (✋) bleiben unverändert. Der Plan wird dabei noch NICHT veröffentlicht.',buttons:[{label:'Abbrechen',class:'secondary'},{label:'Fair nach Stunden',class:'secondary',onClick:function(){document.getElementById('planFair').value='hours';document.getElementById('planAction').value='assign_keep_locked';document.getElementById('planform').submit();}},{label:'Fair nach Schichten',class:'',onClick:function(){document.getElementById('planFair').value='shifts';document.getElementById('planAction').value='assign_keep_locked';document.getElementById('planform').submit();}}]})"><i class="ti ti-wand"></i> Zufällig einteilen (fair)</button>
        <form method="post" data-confirm="Ganze Einteilung leeren? Das entfernt ALLES – auch Fixierungen, kurzfristig Eingesprungene und über die Börse getauschte Schichten." data-confirm-danger data-confirm-ok="Leeren">
          <?= csrf_field() ?><input type="hidden" name="action" value="clear_plan"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
          <button class="btn secondary" type="submit"><i class="ti ti-eraser"></i> Einteilung leeren</button>
        </form>
        <?php if (array_filter($lockedMap)): ?>
        <form method="post" data-confirm="Alle Fixierungen (🔒) lösen? Die Einteilung bleibt, nur die Schlösser werden entfernt." data-confirm-title="Schlösser lösen" data-confirm-ok="Alle lösen">
          <?= csrf_field() ?><input type="hidden" name="action" value="unlock_all_fixations"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
          <button class="btn secondary" type="submit"><i class="ti ti-lock-open"></i> Alle Schlösser lösen</button>
        </form>
        <?php endif; ?>
      </div>

      <form method="post" id="planform">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="planAction" value="save_plan">
        <input type="hidden" name="fair" id="planFair" value="shifts">
        <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <input type="hidden" name="notify" id="planNotify" value="1">
        <?php foreach ($slots as $s):
            $slotId = (int)$s['id'];
            $assignedHere = $assign[$slotId] ?? [];
            $poolYes = $poolMaybe = [];
            foreach ($responses[$slotId] ?? [] as $mid => $r) {
                if ($r['status'] === 'yes') $poolYes[] = (int)$mid;
                elseif ($r['status'] === 'maybe') $poolMaybe[] = (int)$mid;
            }
            // WICHTIG: auch Zugeteilte OHNE „Ja/Evtl."-Antwort anzeigen (eingesprungen, per Tausch/Abgabe
            // übernommen, von Hand gesetzt). Sonst fehlen sie im Formular und save_plan würde sie löschen.
            $poolAssigned = [];
            foreach (array_keys($assignedHere) as $mid) {
                if (!in_array((int)$mid, $poolYes, true) && !in_array((int)$mid, $poolMaybe, true)) $poolAssigned[] = (int)$mid;
            }
            $extNames = slot_external_names($s);
            $ac = count($assignedHere) + count($extNames); // Externe zählen wie Eingeteilte mit
            $tt = (int)$s['target_helpers']; $cls = $tt > 0 ? ($ac >= $tt ? 'pill-ok' : ($ac > 0 ? 'pill-warn' : 'pill-bad')) : 'pill-info';
        ?>
          <div class="plan-line">
            <div class="plan-line-head">
              <strong><?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?></strong>
              <?php if ($s['location']): ?><span class="muted"><i class="ti ti-map-pin" style="font-size:.9em"></i> <?= h($s['location']) ?></span><?php endif; ?>
              <?php if ($s['label']): ?><span class="muted">· <?= h($s['label']) ?></span><?php endif; ?>
              <span class="pill <?= $cls ?>" style="margin-left:auto"><?= $ac ?><?= $tt > 0 ? '/' . $tt : '' ?> eingeteilt</span><?= $attrPills($s, $assignedHere) ?>
              <?php $allLocked = $assignedHere && !array_diff(array_keys($assignedHere), $lockedMap[$slotId] ?? []); ?>
              <button type="button" class="shift-lock<?= $allLocked ? ' on' : '' ?>" title="Ganze Schicht fixieren bzw. lösen – alle hier Eingeteilten bleiben beim Neu-Einteilen"><i class="ti ti-lock"></i></button>
            </div>
            <?php if (!$poolYes && !$poolMaybe && !$poolAssigned): ?>
              <p class="small muted" style="margin:.4rem 0 0">Keine Zusagen für diese Schicht.</p>
            <?php else: ?>
              <div style="display:flex;flex-wrap:wrap;gap:.45rem 1rem;margin-top:.55rem">
                <?php foreach (['yes' => $poolYes, 'maybe' => $poolMaybe, 'assigned' => $poolAssigned] as $stt => $pool): foreach ($pool as $mid):
                    $absent = member_absent_on($mid, $s['starts_at']);
                    $isAssigned = array_key_exists($mid, $assignedHere);
                    $isNotified = !empty($assignedHere[$mid]);
                    $isLocked = in_array($mid, $lockedMap[$slotId] ?? [], true);
                    $isClaimed = !empty($selfClaimedMap[$slotId][(int)$mid]);
                ?>
                  <span class="plan-person<?= $isLocked ? ' locked' : '' ?>">
                    <label class="inline" style="font-weight:500">
                      <input type="checkbox" name="assign[<?= $slotId ?>][]" value="<?= $mid ?>" <?= $isAssigned ? 'checked' : '' ?>>
                      <?= h($mname[$mid] ?? ('#' . $mid)) ?>
                      <?= $attrBadges($mid) ?>
                      <?php if ($stt === 'maybe'): ?><span class="pill pill-warn" style="font-size:.68rem;padding:.05rem .4rem">evtl.</span><?php endif; ?>
                      <?php if ($stt === 'assigned'): ?><span class="pill <?= $isClaimed ? 'pill-info' : 'pill-warn' ?>" style="font-size:.68rem;padding:.05rem .4rem" title="<?= $isClaimed ? 'kurzfristig selbst eingesprungen' : 'hält die Schicht ohne eigene Zusage (z. B. per Tausch/Abgabe übernommen oder von Hand gesetzt)' ?>"><?= $isClaimed ? '✋ eingesprungen' : 'ohne Zusage' ?></span><?php endif; ?>
                      <?php if ($isNotified): ?><i class="ti ti-mail-check" style="color:var(--green)" title="bereits benachrichtigt"></i><?php endif; ?>
                      <?php if ($absent): ?><span class="warn-line small" title="an dem Tag abwesend">🌴</span><?php endif; ?>
                    </label>
                    <label class="lockwrap<?= $isLocked ? ' on' : '' ?>" title="Fixieren – diese Person bleibt beim „Zufällig einteilen" auf dieser Schicht">
                      <input type="checkbox" class="lockcb" name="lock[<?= $slotId ?>][]" value="<?= $mid ?>" <?= $isLocked ? 'checked' : '' ?>>
                      <i class="ti ti-lock"></i>
                    </label>
                  </span>
                <?php endforeach; endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($extNames): ?>
              <div class="small muted" style="margin-top:.5rem"><i class="ti ti-user-plus"></i> extern: <?= h(implode(', ', $extNames)) ?> <span style="opacity:.7">(verwaltest du auf der <a href="../event.php?id=<?= (int)$edit['id'] ?>#externe">Event-Seite</a>)</span></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="btn-row">
          <button class="btn" type="button" onclick="astaConfirm({title:'Arbeitsplan veröffentlichen',icon:'ti-device-floppy',message:'Mitglieder sehen ihren Plan danach in App, Kalender und iCal. Sollen die Eingeteilten zusätzlich per Mail informiert werden?',buttons:[{label:'Abbrechen',class:'secondary'},{label:'Ohne Mail',class:'secondary',onClick:function(){document.getElementById('planAction').value='save_plan';document.getElementById('planNotify').value='0';document.getElementById('planform').submit();}},{label:'Mit Mail senden',class:'',onClick:function(){document.getElementById('planAction').value='save_plan';document.getElementById('planNotify').value='1';document.getElementById('planform').submit();}}]})">
            <i class="ti ti-device-floppy"></i> Veröffentlichen
          </button>
        </div>
      </form>
      <script>
        // Schloss-Häkchen: Fixieren impliziert „eingeteilt" (linkes Häkchen mitsetzen) + Optik aktualisieren.
        document.querySelectorAll('#planform .lockcb').forEach(function (cb) {
          cb.addEventListener('change', function () {
            var wrap = cb.closest('.lockwrap'); if (wrap) wrap.classList.toggle('on', cb.checked);
            if (cb.checked) {
              var a = cb.closest('.plan-person').querySelector('input[name^="assign"]');
              if (a) a.checked = true;
            }
            syncShiftLock(cb.closest('.plan-line'));
          });
        });
        // Schicht-Schloss: fixiert/löst alle aktuell EINGETEILTEN Personen dieser Schicht auf einmal.
        function lockedSet(line) {
          var on = [];
          line.querySelectorAll('.plan-person').forEach(function (p) {
            var a = p.querySelector('input[name^="assign"]'), l = p.querySelector('.lockcb');
            if (a && a.checked && l) on.push(l);
          });
          return on;
        }
        function syncShiftLock(line) {
          if (!line) return;
          var btn = line.querySelector('.shift-lock'); if (!btn) return;
          var on = lockedSet(line);
          btn.classList.toggle('on', on.length > 0 && on.every(function (l) { return l.checked; }));
        }
        document.querySelectorAll('#planform .shift-lock').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var line = btn.closest('.plan-line');
            var on = lockedSet(line);
            if (!on.length) { astaConfirm({title:'Niemand eingeteilt',icon:'ti-info-circle',message:'Diese Schicht hat noch keine eingeteilte Person zum Fixieren. Setz zuerst links Häkchen.',buttons:[{label:'OK',class:''}]}); return; }
            var allOn = on.every(function (l) { return l.checked; });
            on.forEach(function (l) { l.checked = !allOn; var w = l.closest('.lockwrap'); if (w) w.classList.toggle('on', !allOn); });
            btn.classList.toggle('on', !allOn);
          });
        });
      </script>
    <?php endif; ?>
  </div>

  <?php endif; ?>

  <?php if ($eTab === 'kontakt'): ?>
  <?php $msgRecips = event_message_recipients((int)$edit['id']); ?>
  <div class="card" id="nachrichten">
    <h2 style="margin-top:0"><i class="ti ti-message-2"></i> Nachricht an Teilnehmende</h2>
    <?php if (!$msgRecips): ?>
      <p class="muted small">Sobald sich jemand zurückgemeldet hat oder eingeteilt ist, kannst du hier eine Dashboard-Nachricht hinterlassen.</p>
    <?php else: ?>
      <p class="small muted" style="margin-top:0">Hinterlässt eine kurze Nachricht auf dem Dashboard der Person – auf Wunsch zusätzlich per E-Mail. Tipp auf den Namen zum Schreiben.</p>
      <div class="dm-list">
        <?php foreach ($msgRecips as $rid => $rname): ?>
          <details class="dm-item">
            <summary><i class="ti ti-message-2"></i> <?= h($rname) ?></summary>
            <form method="post" class="dm-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="send_message">
              <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <input type="hidden" name="recipient" value="<?= (int)$rid ?>">
              <textarea name="body" rows="2" placeholder="Nachricht an <?= h($rname) ?> …" required></textarea>
              <label class="wl-sw slim"><input type="checkbox" name="send_mail">
                <span class="wl-sw-track" aria-hidden="true"></span>
                <span class="wl-sw-txt"><strong>auch per E-Mail benachrichtigen</strong></span></label>
              <div class="btn-row"><button class="btn small" type="submit"><i class="ti ti-send"></i> Senden</button></div>
            </form>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>E-Mail-Erinnerungen</h2>
    <?php
      $pending = members_without_vote((int)$edit['id']);
      $noEmail = (int)db()->query('SELECT COUNT(*) FROM members WHERE active=1 AND email=\'\'')->fetchColumn();
    ?>
    <?php if (event_locked($edit)): ?>
      <p class="muted">Event ist abgeschlossen bzw. Frist abgelaufen – es werden keine Erinnerungen mehr versendet.</p>
    <?php elseif (empty($edit['deadline'])): ?>
      <p class="muted">Für automatische Erinnerungen bitte oben eine Abstimmungsfrist setzen. (Manuell senden geht trotzdem.)</p>
    <?php endif; ?>
    <p class="small muted">
      Noch nicht abgestimmt (mit E-Mail): <strong><?= count($pending) ?></strong>
      <?= $pending ? '– ' . h(implode(', ', array_column($pending, 'name'))) : '' ?>
      <?php if ($noEmail): ?><br><?= $noEmail ?> aktive:r ohne E-Mail (bekommen keine Erinnerung – in der <a href="members.php">Stammliste</a> ergänzen).<?php endif; ?>
    </p>
    <p class="small muted">Automatisch erinnert der Cron-Job ab <?= (int)setting_get('reminder_lead_days','3') ?> Tagen vor der Frist (täglich, höchstens 1×/Tag pro Person).</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_reminders">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <button class="btn secondary" type="submit" <?= (event_locked($edit) || !$pending) ? 'disabled' : '' ?>>Erinnerung jetzt senden</button>
    </form>
  </div>

  <?php endif; /* Ende Reiter „Nachrichten" – die E-Mail-Erinnerungen gehören dazu */ ?>

  <?php if ($eTab === 'mehr'): ?>
  <div class="card">
    <h2><i class="ti ti-template"></i> Als Vorlage speichern</h2>
    <p class="small muted" style="margin-top:0">Speichert Titel, Beschreibung, Optionen, Datumsangaben und die Schicht-Struktur dieses Events als wiederverwendbare Vorlage – für alle zugänglich. Beim Laden passt du die Daten einfach an.</p>
    <form method="post" class="events-toolbar">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_event_template">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <input type="text" name="template_name" placeholder="Vorlagen-Name (Standard: Event-Titel)">
      <button class="btn secondary" type="submit"><i class="ti ti-template"></i> Als Vorlage speichern</button>
    </form>
  </div>

  <div class="card">
    <h2><i class="ti ti-users"></i> Besitzer:innen <span class="muted small">(max. <?= EVENT_OWNER_MAX ?>)</span></h2>
    <p class="small muted" style="margin-top:0">Alle hier gewählten Personen dürfen das Event verwalten und sehen es auch als Entwurf (Admins weiterhin auch). Aktuell: <strong><?= h(event_owner_names($edit) ?: '—') ?></strong>.</p>
    <form method="post" data-confirm="Besitzer:innen dieses Events speichern?" data-confirm-ok="Speichern">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_owners">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <?php $curOwners = array_pad(event_owner_ids((int)$edit['id']), EVENT_OWNER_MAX, 0); ?>
      <div class="field-row">
        <?php for ($i = 0; $i < EVENT_OWNER_MAX; $i++): ?>
          <div>
            <label for="own<?= $i ?>">Besitzer:in <?= $i + 1 ?><?= $i === 0 ? '' : ' (optional)' ?></label>
            <select name="owners[]" id="own<?= $i ?>">
              <option value="">– keine –</option>
              <?php foreach (members_all() as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= (int)($curOwners[$i] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endfor; ?>
      </div>
      <div class="btn-row" style="margin-top:.6rem"><button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Besitzer:innen speichern</button></div>
    </form>
  </div>

  <?php endif; ?>

  <?php /* Löschen gehört zu den Event-Daten, nicht ans Ende der Vorlagen-Karte. */ ?>
  <?php if ($eTab === 'daten'): ?>
  <form method="post" data-confirm="Ganzes Event inkl. aller Termine und Rückmeldungen löschen?" data-confirm-danger data-confirm-ok="Event löschen">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_event">
    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <button class="btn danger" type="submit">Event löschen</button>
  </form>
  <?php endif; ?>

<?php else: // ===== ÜBERSICHT / ANLEGEN ===== ?>
  <h1>Events verwalten</h1>
  <div class="card">
    <h2>Neues Event</h2>
    <?php if ($templates): ?>
      <div class="events-toolbar" style="margin-bottom:.6rem">
        <form method="get" class="btn-row" style="gap:.4rem;align-items:flex-end;flex-wrap:wrap">
          <div>
            <label for="tplsel" style="font-size:.85rem">Aus Vorlage starten</label>
            <select name="tpl" id="tplsel" onchange="this.form.submit()">
              <option value="0">— leeres Formular —</option>
              <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tplId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </form>
        <?php if ($tpl && (can_admin() || (int)($tpl['_owner'] ?? 0) === (int)($cm['id'] ?? 0))): ?>
          <form method="post" data-confirm="Vorlage „<?= h($tpl['_name'] ?? '') ?>“ löschen?" data-confirm-danger data-confirm-ok="Löschen">
            <?= csrf_field() ?><input type="hidden" name="delete_template" value="1"><input type="hidden" name="template_id" value="<?= $tplId ?>">
            <button class="btn danger small" type="submit"><i class="ti ti-trash"></i> Vorlage löschen</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($tpl): ?><p class="small muted" style="margin-top:0"><i class="ti ti-template"></i> Vorlage <strong><?= h($tpl['_name'] ?? '') ?></strong> geladen – Datumsangaben bitte prüfen und anpassen.</p><?php endif; ?>
    <?php endif; ?>
    <form method="post" id="createform">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_event">
      <label for="title">Titel</label>
      <input type="text" name="title" id="title" placeholder="z. B. O-Woche Standbetreuung" value="<?= h($tpl['title'] ?? '') ?>" required>
      <label for="description">Beschreibung (optional)</label>
      <textarea name="description" id="description"><?= h($tpl['description'] ?? '') ?></textarea>
      <div class="field-row">
        <div>
          <label for="starts_at">Event von</label>
          <input type="text" class="fp-date" name="starts_at" id="starts_at" required placeholder="Datum wählen" value="<?= h($tpl['starts_at'] ?? '') ?>">
        </div>
        <div>
          <label for="ends_at">bis (optional)</label>
          <input type="text" class="fp-date" name="ends_at" id="ends_at" placeholder="Datum wählen" value="<?= h($tpl['ends_at'] ?? '') ?>">
        </div>
        <div>
          <label for="deadline">Abstimmungsfrist (Pflicht, min. 2 Tage)</label>
          <input type="text" class="fp-date" name="deadline" id="deadline" data-min="<?= date('Y-m-d', strtotime('today +2 days')) ?>" value="<?= h($tpl['deadline'] ?? date('Y-m-d', strtotime('today +7 days'))) ?>" placeholder="Datum wählen">
        </div>
      </div>
      <div class="field-row">
        <div>
          <label for="max_shifts">Max. Schichten pro Person</label>
          <input type="number" name="max_shifts" id="max_shifts" min="1" placeholder="leer = unbegrenzt" value="<?= h((string)($tpl['max_shifts'] ?? '')) ?>">
        </div>
      </div>
      <label class="wl-sw"><input type="checkbox" name="important" <?= !empty($tpl['important']) ? 'checked' : '' ?>>
        <span class="wl-sw-track" aria-hidden="true"></span>
        <span class="wl-sw-txt"><strong>Extrem wichtig</strong>
          <span>Warnt beim Eintragen von Abwesenheiten in diesem Zeitraum.</span></span></label>
      <label for="req_label" style="margin-top:.6rem">Zusatz-Abfragen (optional, bis zu 3 Merkmale)</label>
      <div class="attr-input-row"><input type="text" name="req_label" id="req_label" placeholder="Merkmal 1, z. B. Führerschein" value="<?= h($tpl['req_label'] ?? '') ?>"><i class="ti ti-discount-check-filled attr-badge a1" title="Farbe für Merkmal 1"></i></div>
      <div class="attr-input-row"><input type="text" name="req_label2" placeholder="Merkmal 2 (optional)" value="<?= h($tpl['req_label2'] ?? '') ?>"><i class="ti ti-discount-check-filled attr-badge a2" title="Farbe für Merkmal 2"></i></div>
      <div class="attr-input-row"><input type="text" name="req_label3" placeholder="Merkmal 3 (optional)" value="<?= h($tpl['req_label3'] ?? '') ?>"><i class="ti ti-discount-check-filled attr-badge a3" title="Farbe für Merkmal 3"></i></div>
      <p class="small muted" style="margin:.4rem 0 .2rem">Mitglieder kreuzen die zutreffenden Merkmale beim Zurückmelden an (jedes Merkmal hat seine Farbe). Pro Schicht legst du unten „davon mit Merkmal 1/2/3" fest.</p>

      <h3 style="margin-bottom:.4rem">Schichten</h3>
      <p class="small muted" style="margin-top:0">Optional – kannst du auch später ergänzen. Pro Schicht: Name, Datum, Start, optional Ende, Ort und benötigte Helferzahl. „Übernehmen" kopiert den Wert aus der Schicht darüber.</p>
      <div id="slotrows"></div>
      <div class="btn-row" style="margin:.4rem 0 1rem">
        <button class="btn secondary small" type="button" id="addslot"><i class="ti ti-plus"></i> Schicht hinzufügen</button>
      </div>

      <div class="btn-row">
        <button class="btn" type="submit"><i class="ti ti-calendar-plus"></i> Event anlegen</button>
        <?php if ($cm): ?><button class="btn secondary" type="submit" name="as_draft" value="1"><i class="ti ti-eye-off"></i> Als Entwurf anlegen</button><?php endif; ?>
      </div>
      <?php if ($cm): ?>
        <div class="btn-row" style="margin-top:.8rem;border-top:1px solid var(--line);padding-top:.8rem;align-items:flex-end">
          <div><label for="template_name" style="font-size:.85rem">Diese Eingaben als Vorlage merken</label>
            <input type="text" name="template_name" id="template_name" placeholder="Vorlagen-Name (z. B. Standbetreuung)"></div>
          <button class="btn secondary" type="submit" name="save_template" value="1" formnovalidate><i class="ti ti-template"></i> Als Vorlage speichern</button>
        </div>
        <p class="small muted" style="margin:.3rem 0 0">Speichert Titel, Beschreibung, Optionen, Datumsangaben und die Schicht-Struktur zum Wiederverwenden – die Daten passt du beim nächsten Mal einfach an.</p>
      <?php endif; ?>
    </form>
  </div>
  <script>window.PREFILL_SLOTS = <?= json_encode($tpl['slots'] ?? [], JSON_UNESCAPED_UNICODE) ?>;</script>

  <?php
  if (can_admin()) {
      $events = db()->query(
          "SELECT e.*, (SELECT COUNT(*) FROM event_slots WHERE event_id=e.id) AS n_slots
           FROM events e WHERE " . visible_drafts_sql('e.', true) . " ORDER BY e.draft DESC, e.closed, e.starts_at DESC"
      )->fetchAll();
  } else {
      $st = db()->prepare(
          "SELECT e.*, (SELECT COUNT(*) FROM event_slots WHERE event_id=e.id) AS n_slots
           FROM events e WHERE e.created_by = ? OR e.id IN (SELECT event_id FROM event_owners WHERE member_id = ?)
           ORDER BY e.closed, e.starts_at DESC"
      );
      $mid = (int)($cm['id'] ?? 0);
      $st->execute([$mid, $mid]);
      $events = $st->fetchAll();
  }
  ?>
  <div class="section-title"><i class="ti ti-list-details"></i> <?= can_admin() ? 'Alle Events' : 'Meine Events' ?></div>
  <?php if (!$events): ?>
    <div class="card"><p class="muted">Noch keine Events angelegt.</p></div>
  <?php else: ?>
    <table class="list">
      <thead><tr><th>Event</th><th>Zeitraum</th><th>Termine</th><?php if (can_admin()): ?><th>Besitzer:innen</th><?php endif; ?><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($events as $e): ?>
        <tr>
          <td><a href="events.php?edit=<?= (int)$e['id'] ?>"><?= h($e['title']) ?></a></td>
          <td class="small"><?= ($e['starts_at'] ?? '') ? h(fmt_event_range($e['starts_at'], $e['ends_at'])) : '<span class="muted">–</span>' ?></td>
          <td><?= (int)$e['n_slots'] ?></td>
          <?php if (can_admin()): ?><td class="muted small"><?= h(event_owner_names($e) ?: '—') ?></td><?php endif; ?>
          <td><?php if (!empty($e['draft'])): ?><span class="badge badge-draft">Entwurf</span> <?php endif; ?><?= $e['closed'] ? '<span class="badge badge-closed">abgeschlossen</span>' : '<span class="badge badge-event">offen</span>' ?></td>
          <td style="text-align:right"><a class="btn secondary small" href="events.php?edit=<?= (int)$e['id'] ?>">Bearbeiten</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <script>
  window.addEventListener('DOMContentLoaded', function(){
    var wrap = document.getElementById('slotrows');
    var btn = document.getElementById('addslot');
    if (!wrap || !btn) return;
    function initPickers(scope){
      if (!window.flatpickr) return;
      scope.querySelectorAll('.fp-date').forEach(function(el){
        flatpickr(el, {dateFormat:'Y-m-d', altInput:true, altFormat:'D, d.m.Y'});
      });
      scope.querySelectorAll('.fp-time').forEach(function(el){
        flatpickr(el, {enableTime:true, noCalendar:true, time_24hr:true, dateFormat:'H:i', minuteIncrement:15});
      });
    }
    var COPY = '<button type="button" class="copyup" tabindex="-1" title="Aus der Schicht darüber übernehmen"><i class="ti ti-arrow-up"></i> übernehmen</button>';
    function field(label, inputHtml, cls){
      return '<div class="slotfield ' + (cls || '') + '"><label>' + label + '</label>' + inputHtml + COPY + '</div>';
    }
    function row(v){
      v = v || {};
      var d = document.createElement('div');
      d.className = 'slotrow field-row';
      d.innerHTML =
        field('Name', '<input type="text" name="slot_name[]" placeholder="z. B. Abbau">') +
        field('Datum', '<input type="text" class="fp-date" name="slot_date[]" placeholder="Datum">') +
        field('Start', '<input type="text" class="fp-time" name="slot_start[]" placeholder="--:--">') +
        field('Ende (geschätzt)', '<input type="text" class="fp-time slot-end" name="slot_end[]" placeholder="--:--">') +
        field('Ort', '<input type="text" name="slot_loc[]" placeholder="z. B. Studibühne">') +
        field('Helfer', '<input type="number" min="0" name="slot_target[]" placeholder="z. B. 2">') +
        field('Merkmal 1', '<input type="number" min="0" name="slot_req[]" placeholder="0">', 'mk mk1') +
        field('Merkmal 2', '<input type="number" min="0" name="slot_req2[]" placeholder="0">', 'mk mk2') +
        field('Merkmal 3', '<input type="number" min="0" name="slot_req3[]" placeholder="0">', 'mk mk3') +
        '<div class="slotfield slotfield-del"><label>&nbsp;</label><button type="button" class="btn danger small slotdel" title="Schicht entfernen"><i class="ti ti-trash"></i></button></div>';
      d.querySelector('.slotdel').addEventListener('click', function(){ d.remove(); });
      d.addEventListener('click', function(e){
        var b = e.target.closest ? e.target.closest('.copyup') : null;
        if (!b) return;
        var input = b.closest('.slotfield').querySelector('input');
        var prev = d.previousElementSibling;
        if (!prev || !prev.classList.contains('slotrow')) return;
        var pin = prev.querySelector('input[name="' + input.name + '"]');
        if (!pin) return;
        if (input._flatpickr) input._flatpickr.setDate(pin.value, true); else input.value = pin.value;
      });
      wrap.appendChild(d);
      // Werte aus Vorlage setzen
      var setv = function(name, val){ var el = d.querySelector('[name="' + name + '"]'); if (el && val != null && val !== '') el.value = val; };
      setv('slot_name[]', v.name); setv('slot_loc[]', v.loc);
      if (v.target) setv('slot_target[]', v.target);
      if (v.req) setv('slot_req[]', v.req);
      if (v.req2) setv('slot_req2[]', v.req2);
      if (v.req3) setv('slot_req3[]', v.req3);
      initPickers(d);
      var setTime = function(name, val){ if (val == null || val === '') return; var el = d.querySelector('[name="' + name + '"]'); if (!el) return; if (el._flatpickr) el._flatpickr.setDate(val, true); else el.value = val; };
      setTime('slot_date[]', v.date); setTime('slot_start[]', v.start); setTime('slot_end[]', v.end);
      syncMk();
    }
    // Bedarfsfelder pro Merkmal nur zeigen, wenn das jeweilige Merkmal benannt ist – und das Label übernehmen.
    function syncMk(){
      [['mk1','req_label'],['mk2','req_label2'],['mk3','req_label3']].forEach(function(p, i){
        var lab = document.getElementsByName(p[1])[0]; var name = lab && lab.value.trim();
        document.querySelectorAll('.slotfield.' + p[0]).forEach(function(f){
          f.style.display = name ? '' : 'none';
          var l = f.querySelector('label'); if (l && name) l.textContent = name;
        });
      });
    }
    ['req_label','req_label2','req_label3'].forEach(function(n){ var el = document.getElementsByName(n)[0]; if (el) el.addEventListener('input', syncMk); });
    btn.addEventListener('click', function(){ row(); });
    var pre = window.PREFILL_SLOTS;
    if (pre && pre.length) { pre.forEach(function(s){ row(s); }); } else { row(); /* eine Startzeile */ }
    syncMk();
    // Endzeit ist Pflicht – aber nur für Zeilen, die überhaupt eine Schicht definieren (Datum/Start gesetzt).
    var createForm = document.getElementById('createform');
    if (createForm) createForm.addEventListener('submit', function(e){
      var bad = null;
      wrap.querySelectorAll('.slotrow').forEach(function(r){
        var val = function(n){ var el = r.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ''; };
        if ((val('slot_date[]') !== '' || val('slot_start[]') !== '') && val('slot_end[]') === '') {
          if (!bad) bad = r;
        }
      });
      var prev = document.getElementById('slotend-error');
      if (prev) prev.remove();
      if (bad) {
        e.preventDefault();
        var msg = document.createElement('div');
        msg.id = 'slotend-error';
        msg.className = 'flash flash-error';
        msg.innerHTML = '<i class="ti ti-alert-triangle"></i> Bitte für jede Schicht eine (geschätzte) Endzeit angeben – über Mitternacht einfach die frühere Uhrzeit (z. B. 02:00).';
        bad.parentNode.insertBefore(msg, bad);
        msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var endEl = bad.querySelector('.slot-end');
        if (endEl) endEl.focus();
      }
    });
  });
  </script>
<?php endif; ?>
<?php
page_footer();
