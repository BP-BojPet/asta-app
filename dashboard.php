<?php
require __DIR__ . '/lib.php';
db();
require_login();

$me = current_member();

// Get-Together: Ja/Nein-Rückmeldung direkt vom Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gt_rsvp' && $me) {
    check_csrf();
    gettogether_rsvp_set((int)($_POST['gt_id'] ?? 0), (int)$me['id'], (string)($_POST['status'] ?? ''));
    redirect('dashboard.php#offen');
}

// Sitzung: abmelden / nur online teilnehmen – Shortcut aus „Kommende Sitzungen"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'meeting_rsvp' && $me) {
    check_csrf();
    $st = db()->prepare('SELECT * FROM meetings WHERE id = ?');
    $st->execute([(int)($_POST['meeting_id'] ?? 0)]);
    if ($mt = $st->fetch()) {
        $r = meeting_rsvp_set($mt, (int)$me['id'], (string)($_POST['status'] ?? ''), (string)($_POST['reason'] ?? ''));
        flash($r['msg'], $r['ok'] ? 'success' : 'error');
    }
    redirect('dashboard.php');
}

// Dashboard-Nachricht als gelesen markieren (ausblenden)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_message' && $me) {
    check_csrf();
    dm_mark_read((int)($_POST['msg_id'] ?? 0), (int)$me['id']);
    redirect('dashboard.php');
}

// „Ansehen": Nachricht als gelesen markieren UND zur Fundstelle springen (z. B. Pinnwand)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'view_message' && $me) {
    check_csrf();
    $mid = (int)($_POST['msg_id'] ?? 0);
    $go  = dm_link_of($mid, (int)$me['id']); // nur eigene Nachricht, nur internes Ziel
    dm_mark_read($mid, (int)$me['id']);
    redirect($go !== '' ? $go : 'dashboard.php');
}

// Freiwillige Abstimmung vom Dashboard ausblenden (abstimmen bleibt über die Abstimmungs-Seite möglich)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'poll_hide' && $me) {
    check_csrf();
    poll_hide_for_member((int)($_POST['poll_id'] ?? 0), (int)$me['id']);
    redirect('dashboard.php');
}

// Sommer-Geschenk-Mitteilung wegklicken (》Danke《) → als gesehen markieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_sommer_gift' && $me) {
    check_csrf();
    sommer_gift_mark_seen((int)$me['id'], (int)($_POST['gift_id'] ?? 0));
    redirect('dashboard.php');
}

// Freiwilliges Rundgang-Angebot wegklicken („Ich kenne mich bereits gut aus")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tour_dismiss' && $me) {
    check_csrf();
    member_clear_tour_task((int)$me['id']);
    member_mark_onboarded((int)$me['id']); // dann bitte auch keinen Auto-Start mehr
    redirect('dashboard.php');
}

// Sekki-Kachel: Einladungs-/Reminder-Einstellungen direkt aus dem Zahnrad speichern (nur Sekretariat/Vorsitz/Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_sekki_settings' && $me && can_manage_meetings()) {
    check_csrf();
    setting_set('invite_reminder_on', isset($_POST['invite_reminder_on']) ? '1' : '0');
    setting_set('invite_reminder_days', (string)max(0, (int)($_POST['invite_reminder_days'] ?? 2)));
    setting_set('invite_lead_days', (string)max(0, (int)($_POST['invite_lead_days'] ?? 5)));
    setting_set('top_deadline_days', (string)max(0, (int)($_POST['top_deadline_days'] ?? 5)));
    flash('Einstellungen gespeichert.', 'success');
    // Zur Ursprungsseite zurück (Dashboard ODER Verwaltung → Sekretariatsaufgaben), nur lokale Pfade
    $ret = (string)($_POST['return'] ?? '');
    if ($ret === '' || $ret[0] !== '/' || substr($ret, 0, 2) === '//' || strpos($ret, '://') !== false) $ret = 'dashboard.php';
    redirect($ret);
}

// Vorlesungsfreie Zeit festlegen/ändern/beenden (Sekretariat/Vorsitz/Admin)
// Halbjährliche Technik-Passwort-Kontrolle des Vorsitzes (nur bestätigen, nichts ändern)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pw_check' && $me && admin_pw_check_pending()) {
    check_csrf();
    if (admin_pw_check_confirm((string)($_POST['pw'] ?? ''))) {
        flash('Danke – Technik-Login bestätigt. Die nächste Kontrolle kommt am ' . fmt_date(admin_pw_check_due_on()) . '.', 'success');
    } elseif (admin_pw_check_locked()) {
        flash('Zu viele Fehlversuche. Bitte in 15 Minuten noch einmal probieren.', 'error');
    } else {
        flash('Das Passwort stimmt nicht. Steht es noch irgendwo? Sonst kann das Technik-Referat ein neues setzen.', 'error');
    }
    redirect('dashboard.php#offen');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['set_lecture_break', 'end_lecture_break'], true) && $me && can_manage_meetings()) {
    check_csrf();
    if ($_POST['action'] === 'set_lecture_break') {
        if (lecture_break_set((string)($_POST['until'] ?? ''))) {
            flash('Vorlesungsfreie Zeit festgelegt – bis ' . fmt_date(lecture_break_until()) . ' kann keine Streak reißen (Sammeln läuft weiter).', 'success');
        } else {
            flash('Bitte ein gültiges Enddatum (heute oder später) wählen.', 'error');
        }
    } else {
        lecture_break_end_now();
        flash('Vorlesungsfreie Zeit beendet – ab jetzt läuft die 7-Tage-Schonfrist.', 'success');
    }
    redirect('dashboard.php');
}

page_header('Dashboard');

// Owner/Technik hat kein Mitgliedsprofil → eigene Ansicht
if (!$me) {
    ?>
    <div class="hero"><div class="avatar"><i class="ti ti-tool"></i></div>
      <div><h1>Technik-Login</h1><div class="sub">Angemeldet mit dem Verwaltungs-Passwort – kein persönliches Profil.</div></div>
    </div>
    <div class="btn-row" style="margin-top:1rem"><a class="btn" href="admin/index.php"><i class="ti ti-settings"></i> Zur Verwaltung</a> <a class="btn secondary" href="index.php">Zum Kalender</a></div>
    <?php
    page_footer();
    exit;
}

$meId = (int)$me['id'];

$openVotes = db()->prepare(
    "SELECT e.* FROM events e
     WHERE e.closed = 0 AND " . visible_drafts_sql('e.', true) . " AND e.deadline IS NOT NULL AND e.deadline >= date('now','localtime')
       AND EXISTS (SELECT 1 FROM event_slots s WHERE s.event_id = e.id)
       AND NOT EXISTS (SELECT 1 FROM responses r JOIN event_slots s ON s.id = r.slot_id WHERE s.event_id = e.id AND r.member_id = ?)
     ORDER BY e.deadline"
);
$openVotes->execute([$meId]);
$openVotes = $openVotes->fetchAll();

$myShifts = db()->prepare(
    "SELECT s.*, e.title AS event_title, e.id AS event_id FROM event_slots s
     JOIN events e ON e.id = s.event_id
     JOIN assignments a ON a.slot_id = s.id AND a.member_id = ?
     WHERE date(s.starts_at) >= date('now','localtime') AND e.plan_locked = 1 AND " . visible_drafts_sql('e.', true) . "
     ORDER BY s.starts_at LIMIT 20"
);
$myShifts->execute([$meId]);
$myShifts = $myShifts->fetchAll();

$meetings = db()->query(
    // StuPa-Sitzungen sind reine Kalender-Info – nicht in der AStA-operativen „Kommende Sitzungen"-Kachel
    "SELECT * FROM meetings WHERE cancelled = 0 AND kind != 'stupa' AND " . visible_drafts_sql() . " AND date(starts_at) >= date('now','localtime') ORDER BY starts_at LIMIT 10"
)->fetchAll();
$nextMeeting = $meetings[0] ?? null;

$liveMeeting = current_live_meeting();
$icalUrl = app_url('ical.php?token=' . urlencode((string)$me['ical_token']));
$webcalUrl = preg_replace('#^https?://#i', 'webcal://', $icalUrl); // Klick/QR → Abo-Dialog

// Kommende Events (Überblick, wenig wichtig – ganz unten)
$upcomingEvents = db()->query(
    "SELECT e.*,
            (SELECT MIN(starts_at) FROM event_slots WHERE event_id = e.id AND date(starts_at) >= date('now','localtime')) AS next_slot,
            (SELECT COUNT(*) FROM event_slots WHERE event_id = e.id) AS n_slots
     FROM events e
     WHERE " . visible_drafts_sql('e.', true) . " AND EXISTS (SELECT 1 FROM event_slots s WHERE s.event_id = e.id AND date(s.starts_at) >= date('now','localtime'))
     ORDER BY next_slot LIMIT 8"
)->fetchAll();

// AStA Get-Togethers fürs Dashboard: distinkt als Spaß markiert, abgesagte ausgeblendet.
$gtUpcomingDash = $me ? gettogethers_upcoming() : [];
$myGtRsvp = $me ? gettogether_rsvp_map($meId) : [];
// „Kommende Events": echte Events + nicht-abgesagte Get-Togethers, nach Datum gemischt
$mixedUpcoming = [];
foreach ($upcomingEvents as $e) $mixedUpcoming[] = ['gt' => false, 'date' => (string)$e['next_slot'], 'row' => $e];
foreach ($gtUpcomingDash as $g) {
    if (($myGtRsvp[(int)$g['id']] ?? '') === 'no') continue;
    $mixedUpcoming[] = ['gt' => true, 'date' => (string)$g['starts_at'], 'row' => $g];
}
usort($mixedUpcoming, fn($a, $b) => strcmp($a['date'] ?: '9999', $b['date'] ?: '9999'));
// Inhalt der „Kommende Events"-Liste – einmal definiert, damit die volle Ansicht (ganz unten) und
// die kompakte Kachel (erscheint nur, wenn das Kalender-Abo ausgeblendet ist) identisch aussehen.
$renderUpcoming = function () use ($mixedUpcoming) {
    if (!$mixedUpcoming) {
        echo '<p class="empty"><i class="ti ti-calendar-off"></i> Zurzeit keine kommenden Events.</p>';
        return;
    } ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.7rem 1.3rem">
      <?php foreach ($mixedUpcoming as $mi): $row = $mi['row']; $d = dt($mi['date']); ?>
        <?php if ($mi['gt']): // Get-Together (Spaß) ?>
          <div style="display:flex;align-items:center;gap:.7rem;min-width:0">
            <div class="datechip gt"><div class="d"><?= $d ? $d->format('d') : '–' ?></div><div class="m"><?= $d ? mb_substr(MONTHS[(int)$d->format('n')], 0, 3) : '' ?></div></div>
            <div style="min-width:0">
              <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <a href="gettogether.php?id=<?= (int)$row['id'] ?>" style="text-decoration:none;color:var(--ink)"><i class="ti ti-confetti" style="color:var(--violet)"></i> <?= h($row['title']) ?></a>
                <span class="pill pill-fun">Spaß · freiwillig</span>
              </div>
              <div class="small muted"><?= h(fmt_slot($row['starts_at'], $row['ends_at'])) ?><?php if (trim((string)$row['location']) !== ''): ?> · <?= h($row['location']) ?><?php endif; ?></div>
            </div>
          </div>
        <?php else: $e = $row; ?>
          <div style="display:flex;align-items:center;gap:.7rem;min-width:0">
            <div class="datechip"><div class="d"><?= $d ? $d->format('d') : '–' ?></div><div class="m"><?= $d ? mb_substr(MONTHS[(int)$d->format('n')], 0, 3) : '' ?></div></div>
            <div style="min-width:0">
              <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <a href="event.php?id=<?= (int)$e['id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h($e['title']) ?></a>
                <?php if ($e['closed']): ?> <span class="badge badge-closed">abgeschlossen</span>
                <?php elseif (deadline_passed($e['deadline'] ?? null)): ?> <span class="badge badge-closed">Frist abgelaufen</span><?php endif; ?>
              </div>
              <div class="small muted"><?= $e['next_slot'] ? h(fmt_slot($e['next_slot'], null)) : 'noch keine Termine' ?> · <?= (int)$e['n_slots'] ?> Termin(e)</div>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php
};
// „Meine nächsten Einsätze": Schichten + zugesagte Get-Togethers, chronologisch gemischt
$myGt = array_values(array_filter($gtUpcomingDash, fn($g) => ($myGtRsvp[(int)$g['id']] ?? '') === 'yes'));
$myAgenda = [];
foreach ($myShifts as $s) $myAgenda[] = ['gt' => false, 'date' => (string)$s['starts_at'], 'row' => $s];
foreach ($myGt as $g)     $myAgenda[] = ['gt' => true,  'date' => (string)$g['starts_at'], 'row' => $g];
usort($myAgenda, fn($a, $b) => strcmp($a['date'] ?: '9999', $b['date'] ?: '9999'));

$lead = (int)($me['reminder_lead_days'] ?? 0);
$notifyAssign = (int)($me['notify_assignment'] ?? 1);

// Eventscore (nur eigener Wert + Einordnung relativ zum Schnitt)
$allScores = member_scores();
$myScore = $allScores[$meId] ?? 0;
$scoreAvg = score_average($allScores);
achievements_evaluate($meId, $allScores);          // fällige Achievements freischalten (Toast bei Neuem)
$equippedDecos = member_equipped_decos($meId);      // Avatar-Schmuck je Trage-Position (slot => key)
$streak   = member_streak($meId);                   // Tages-Streak für die Hero-Kachel
$scoreBand = score_band_for($meId, $allScores);
// Royal-Freischaltung läuft jetzt über das „spitze"-Achievement (siehe royal_unlocked()); achievements_evaluate() oben hat den Status bereits aktualisiert.

// Basis-Score fürs Hero (Eventscore-Bänder wiederverwenden, nicht doppelt rechnen)
$basisBd = member_basis_breakdown($me, basis_context($allScores));
$basisBandD = basis_band((int)$basisBd['total']);
$justSpitze = spitze_congratulate($meId, $scoreBand === 'spitze'); // einmalige Glückwunsch-Nachricht (Reset bei Verlust)

// Wer ist gerade / demnächst abwesend? (alle Mitglieder, laufende zuerst)
$absencesAll = db()->query(
    "SELECT a.*, m.name, m.pronouns, m.avatar_decos, m.avatar_palette, m.avatar_ink
     FROM absences a JOIN members m ON m.id = a.member_id
     WHERE a.ends_at >= date('now','localtime')
     ORDER BY (a.starts_at <= date('now','localtime')) DESC, a.starts_at LIMIT 10"
)->fetchAll();
// Spitzenklasse-Halter für royale Avatar-Chips in der Abwesenheits-Karte (eine Query für alle)
$spitzeChipIds = [];
try {
    $spitzeChipIds = array_flip(array_map('intval', array_column(
        db()->query("SELECT member_id FROM member_achievements WHERE code = 'spitze'")->fetchAll(), 'member_id')));
} catch (\Throwable $e) {}

// Helfer
$today = new DateTime('today');
// days_until_d() liegt jetzt in lib.php (wird auch von sekretariat_overview_html genutzt)
function deadline_pill(int $days): array {
    $cls = deadline_pill_class($days); // rot ≤1, gelb ≤3, sonst neutral – zentral in lib.php
    if ($days <= 0)  return [$cls, 'Frist heute'];
    if ($days === 1) return [$cls, 'Frist morgen'];
    return [$cls, 'Frist in ' . $days . ' Tg.'];
}
$parts = preg_split('/\s+/', trim($me['name']));
$initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
$dateStr = WD_LONG[(int)$today->format('N') - 1] . ', ' . $today->format('j') . '. ' . MONTHS[(int)$today->format('n')] . ' ' . $today->format('Y');
$nOpen = count($openVotes);
$nextDays = $nextMeeting ? days_until_d($nextMeeting['starts_at']) : null;

// Fehlender Bericht? (eigenes Referat, nächste berichtspflichtige Sitzung, ab 3 Tagen vorher)
// $reportDone = Bericht fürs eigene Referat zur kommenden Sitzung bereits eingetragen (fensterunabhängig).
$reportMeeting = current_report_meeting();
$reportDue = false; $reportDays = null; $reportDone = false;
if (trim((string)($me['referat'] ?? '')) !== '' && $reportMeeting) {
    $reportDays = days_until_d($reportMeeting['starts_at']);
    $rep = report_get((string)$me['referat'], (int)$reportMeeting['id']);
    $reportDone = $rep && trim((string)$rep['content']) !== '';
    if ($reportDays >= 0 && deadline_is_soon($reportDays) && !$reportDone) $reportDue = true;
}
$reportUrgent = $reportDue && $reportDays !== null && deadline_is_urgent($reportDays);

// Offene „Evtl."-Rückmeldungen (werden zum Fristende zu „Nein")
$maybeEvents = events_with_my_maybe($meId);

// Neue/ungesehene „Wichtige Informationen" (auch beim ersten Login)
$infosUnseen = infos_unseen($me);
$newInfos = $infosUnseen ? infos_new_for($me) : []; // seit letztem Besuch neu hinzugekommen (benennbar)

// Pronomen noch nicht festgelegt? (einmalige Aufgabe)
$pronounsMissing = trim((string)($me['pronouns'] ?? '')) === '';

// Ungelesene Abstimmungsgegenstände kommender Sitzungen (eine Aufgabe je Sitzung)
$unreadVotes = $me ? vote_items_unread_for_member($meId) : [];
$votesByMeeting = [];
foreach ($unreadVotes as $uv) $votesByMeeting[(int)$uv['meeting_id']][] = $uv;

// Get-Togethers, zu denen noch keine Rückmeldung vorliegt
$gtPending = $me ? gettogethers_pending_for_member($meId) : [];

// Offene Terminabstimmungen ohne eigene Stimme (verpflichtend für alle)
$pollsPending = $me ? date_polls_pending_for_member($meId) : [];

// Laufende Umlaufverfahren + verpflichtende Abstimmungen ohne eigene Stimme
umlauf_maintain(); // abgelaufene Fristen sofort auswerten
poll_maintain();
$umlaufPending = $me ? umlauf_pending_for_member($meId) : [];
$pollTaskPending = $me ? poll_pending_for_member($meId) : [];
// Freiwillige Abstimmungen ohne eigene Stimme (Hinweis mit „Ausblenden", keine Pflicht)
$pollOptional = $me ? poll_optional_for_member($meId) : [];

// Protokoll-Workflow: als Protokollant:in eingeteilt & noch nicht hochgeladen (eigene Aufgabe)
$protocolUploads = $me ? member_protocol_upload_tasks($meId) : [];
// Angenommene, noch nicht veröffentlichte Protokolle → Sekretariats-Aufgabe „veröffentlichen"
$protocolPublish = ($me && can_manage_meetings()) ? protocol_publish_pending() : [];
// Keine ordentliche Sitzung mehr geplant (oder Pause ohne Enddatum) → Sekki-Aufgabe „vorlesungsfreie Zeit festlegen"
$lbPending = ($me && can_manage_meetings()) ? lecture_break_pending_for_sekki() : false;
// Halbjährliche Kontrolle: Hat der Vorsitz den Technik-Login (Notfall-Zugang) noch?
$pwCheck = $me ? admin_pw_check_pending() : false;

$nTasks = $nOpen + ($reportDue ? 1 : 0) + count($maybeEvents) + ($infosUnseen ? 1 : 0) + ($pronounsMissing ? 1 : 0) + count($votesByMeeting) + count($gtPending) + count($pollsPending) + count($umlaufPending) + count($pollTaskPending) + count($pollOptional) + count($protocolUploads) + count($protocolPublish) + ($lbPending ? 1 : 0) + ($pwCheck ? 1 : 0);

// Royale Begrüßung – aber nur sichtbar, wenn der Royal-Modus aktiv ist (per CSS umgeschaltet,
// da der gewählte Modus reiner Client-State ist). King/Queen je nach erstem Pronomen, sonst „Majestät".
$plainName = first_name($me['name']);
$royalGreet = '';
if ($scoreBand === 'spitze' || royal_test_active()) { // Royal-Testmodus (Gefahrenzone) sieht die royale Begrüßung ebenfalls
    $pron0 = strtolower(explode('/', trim((string)($me['pronouns'] ?? '')))[0]);
    if ($pron0 === 'er')      $royalGreet = 'King ' . $plainName;
    elseif ($pron0 === 'sie') $royalGreet = 'Queen ' . $plainName;
    else                      $royalGreet = 'Majestät';
}
?>
<script>window.ASTA_BULB_HINT = true;<?= $scoreBand === 'spitze' ? ' window.ASTA_ROYAL_HINT = true;' : '' ?><?= $justSpitze ? ' window.ASTA_ROYAL_AUTO = true;' : '' ?></script>
<?php $pushPromptV = push_available() ? push_vapid() : null; if ($pushPromptV): ?>
<!-- Erst-Abfrage für Push in der installierten App (app.js zeigt sie nur einmal, nur standalone) -->
<script>window.ASTA_PUSH_PROMPT = {vapid: <?= json_encode($pushPromptV['pub']) ?>, csrf: <?= json_encode(csrf_token()) ?>};</script>
<?php endif; ?>
<?php $heroScoreColor = $scoreBand === 'spitze' ? '#ffe08a' : ($scoreBand === 'gut' ? '#a6f0c6' : ($scoreBand === 'ausbaufaehig' ? '#ffc2c3' : '#ffffff'));
      $heroBasisColor = ['einwandfrei' => '#a6f0c6', 'ausbaufaehig' => '#ffd591', 'gespraech' => '#ffc2c3'][$basisBandD];
      // Status-Ring am Avatar: NUR der Royal-Puls der Spitzenklasse. Die Regel steht in lib.php
      // (avatar_ring_class), damit Profil und Hero denselben Ring zeigen.
      $avRingVoll = avatar_ring_class($meId, $allScores);
      $ringTitle  = avatar_ring_titel($avRingVoll); ?>
<?php // Beide Hinweise lassen sich wegklicken: das ✕ lässt sie als Symbol in die Titelleiste fliegen
      // (Merker im Browser, siehe page_header/app.js) – ein Klick auf das Symbol holt sie zurück. ?>
<?php if (in_lecture_break()): $lbUntil = lecture_break_until(); ?>
  <div class="card lb-banner" id="lbBanner" style="margin-bottom:1rem;border-left:4px solid var(--petrol)">
    <button type="button" class="lb-x" aria-label="Hinweis ausblenden" title="Ausblenden – der Hinweis fliegt als Schneeflocke in die Titelleiste"><i class="ti ti-x"></i></button>
    <div class="section-title" style="margin-top:0"><i class="ti ti-snowflake lb-mark"></i> Vorlesungsfreie Zeit<?= $lbUntil !== '' ? ' <span class="muted small" style="font-weight:400">– bis ' . h(fmt_date($lbUntil)) . '</span>' : '' ?></div>
    <p class="small muted" style="margin:0">Gerade ist vorlesungsfreie Zeit<?= $lbUntil !== '' ? ' (bis <strong>' . h(fmt_date($lbUntil)) . '</strong>)' : ' – das Enddatum legt das Sekretariat noch fest' ?>. Deine <strong>Streak kann so lange nicht reißen</strong> – tägliche Flammen sammelst du aber ganz normal weiter. Nach dem Ende gilt zusätzlich eine <strong>Schonfrist von 7 Tagen</strong>, in der ebenfalls keine Streak verloren geht.</p>
  </div>
<?php elseif (in_streak_grace()): ?>
  <div class="card lb-banner" id="lbBanner" style="margin-bottom:1rem;border-left:4px solid var(--petrol)">
    <button type="button" class="lb-x" aria-label="Hinweis ausblenden" title="Ausblenden – der Hinweis fliegt als Symbol in die Titelleiste"><i class="ti ti-x"></i></button>
    <div class="section-title" style="margin-top:0"><i class="ti ti-flame lb-mark"></i> Streak-Schonfrist <span class="muted small" style="font-weight:400">– bis <?= h(fmt_date(lecture_break_grace_until())) ?></span></div>
    <p class="small muted" style="margin:0">Die vorlesungsfreie Zeit ist vorbei. Bis <strong><?= h(fmt_date(lecture_break_grace_until())) ?></strong> verlierst du noch keine Streak – danach gelten wieder die normalen Regeln. Also am besten gleich wieder täglich reinschauen. 🔥</p>
  </div>
<?php endif; ?>
<?php // Aufräum-Erinnerung NUR für die Rolle Vorsitz: Deaktivierte Konten sind ein Übergang,
      // kein Dauerzustand. Erscheint, sobald eins seit 14 Tagen deaktiviert ist, und geht erst
      // weg, wenn KEIN Konto mehr deaktiviert ist (alle gelöscht oder reaktiviert).
      $deaktListe = current_role() === 'vorsitz' ? deactivated_reminder() : []; ?>
<?php if ($deaktListe): ?>
  <div class="card" style="border-left:4px solid var(--red);margin-bottom:1rem">
    <div class="section-title" style="margin-top:0"><i class="ti ti-user-off" style="color:var(--red)"></i> Deaktivierte Konten aufräumen</div>
    <p class="small" style="margin:0 0 .5rem">Deaktivieren ist nur für den Übergang gedacht – bitte entscheide:
      <strong>löschen oder reaktivieren</strong>. Dieser Hinweis bleibt, solange noch ein Konto deaktiviert ist.</p>
    <p class="small muted" style="margin:0 0 .6rem">
      <?php foreach ($deaktListe as $di => $dm):
          $dTage = trim((string)$dm['deactivated_on']) !== ''
              ? (int)floor((time() - strtotime((string)$dm['deactivated_on'])) / 86400) : 0; ?>
        <?= $di > 0 ? ' · ' : '' ?><strong><?= h((string)$dm['name']) ?></strong> (seit <?= $dTage ?> Tag<?= $dTage === 1 ? '' : 'en' ?>)
      <?php endforeach; ?>
    </p>
    <a class="btn secondary small" href="admin/members.php"><i class="ti ti-users"></i> Zur Stammliste</a>
  </div>
<?php endif; ?>
<?php $bdaysToday = birthdays_today(); if ($bdaysToday): ?>
  <div class="card" style="border-left:4px solid var(--violet);margin-bottom:1rem">
    <p style="margin:0">🎂 Heute hat <?php $bdLinks = array_map(fn($b) => '<strong>' . member_link($b) . '</strong>', $bdaysToday);
      echo count($bdLinks) > 1 ? implode(', ', array_slice($bdLinks, 0, -1)) . ' und ' . end($bdLinks) . ' Geburtstag' : $bdLinks[0] . ' Geburtstag'; ?> – gratuliert doch mal ordentlich! 🥳</p>
    <?php // Gratulieren = netter Eintrag auf der Pinnwand: der Knopf springt direkt zum
          // Pinnwand-Formular des Geburtstagskinds. Für den EIGENEN Geburtstag gibt es
          // keinen Knopf – auf der eigenen Pinnwand kann man ja nicht posten.
          $bdFremde = array_values(array_filter($bdaysToday, fn($b) => (int)$b['id'] !== (int)($me['id'] ?? 0))); ?>
    <?php if ($bdFremde): ?>
      <div style="display:flex;flex-wrap:wrap;gap:.45rem;margin-top:.6rem">
        <?php foreach ($bdFremde as $b): ?>
          <a class="btn secondary small" href="profil.php?id=<?= (int)$b['id'] ?>#pinnwand"><i class="ti ti-confetti"></i> <?= count($bdFremde) > 1 ? h(first_name((string)$b['name'])) . ' ' : '' ?>Gratulieren</a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php // Streak-Stufe schon HIER, nicht erst an der Kachel: Ab Stufe 5 (100 Tage) trägt der
      // ganze Hero die Aura des Stils – Klasse am Feld, Teilchen-Layer als erstes Kind.
      $stCur = (int)$streak['current']; $stTier = streak_tier_shown($stCur);
      $stStyle = member_flame_style($meId); // '' = Flamme, sonst ein Key aus flame_styles_all() – ändert auch das Wording
      $stWord = flame_style_word($stStyle, $stCur);
      $stFrozen = in_lecture_break() || in_streak_grace(); // Pause/Schonfrist → Streak kann nicht reißen (Sammeln läuft) ?>
<div class="hero<?= streak_aura_class($stStyle, $stTier) ?>" style="--aur1:<?= $heroBasisColor ?>e6;--aur2:<?= $heroScoreColor ?>e6"><!-- Rand-Lauflicht in den Band-Farben der beiden Scores (e6 = 90 % Alpha) -->
  <?= streak_aura_html($stStyle, $stTier) ?>
  <?php // Eingeklappter Streak-Pausen-Hinweis fürs Handy: oben rechts in dieser Karte. In der
        // flachen Handy-Titelleiste wäre die Schneeflocke zwar da, würde aber niemandem auffallen. ?>
  <?= lb_chip_html('hero') ?>
  <div class="avatar<?= $scoreBand === 'spitze' ? ' avatar-spitze' : '' ?><?= member_avatar_class($me) ?>" style="<?= h(member_avatar_style($me)) ?>"<?= $ringTitle !== '' ? ' title="' . h($ringTitle) . '"' : '' ?>><?php if ($scoreBand === 'spitze' && !isset($equippedDecos['head'])): ?><i class="ti ti-crown avatar-crown" title="Spitzenklasse"></i><?php endif; ?><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span><?php foreach ($equippedDecos as $dSlot => $dKey): ?><span class="avatar-acc acc-<?= $dSlot ?> acc-k-<?= h($dKey) ?>" title="Avatar-Schmuck"><?= avatar_deco_svg($dKey) ?></span><?php endforeach; ?></div>
  <div>
    <h1>Hallo, <?php if ($royalGreet !== ''): ?><span class="greet-royal"><?= h($royalGreet) ?> 👑</span><span class="greet-plain"><?= h($plainName) ?> 👋</span><?php else: ?><?= h($plainName) ?> 👋<?php endif; ?></h1>
    <div class="sub"><?= h($dateStr) ?> · <?= $nTasks ? $nTasks . ' Aufgabe' . ($nTasks > 1 ? 'n' : '') . ' offen' : 'alles erledigt 🎉' ?></div>
  </div>
  <div class="hero-side">
    <!-- Achievement/Streak-Kachel · Flamme brennt ab 3 Tagen und wird mit steigender Streak heißer (st1–st5;
         ab Stufe 5 strahlt der Stil zusätzlich in den ganzen Hero aus, siehe streak_aura_html) -->
    <a class="hero-streak st<?= $stTier ?><?= $stTier ? ' lit' : '' ?><?= $stStyle ? ' ' . h($stStyle) : '' ?>" href="achievements.php" title="Achievements &amp; Streak – <?= $stCur ?> <?= $stWord ?> (beste Serie: <?= (int)$streak['best'] ?>)<?= $stFrozen ? ' · geschützt: gerade kann die Streak nicht reißen, Sammeln läuft normal weiter' : '' ?>">
      <?= flame_svg($stStyle) ?>
      <span class="hs-body"><span class="hs-num"><?= (int)$streak['current'] ?></span><span class="hs-lbl">Tage<br>Streak</span></span>
    </a>
    <!-- Kompakter Doppel-Score: Basis (nur Zustands-Icon) | Eventscore (Icon + Wort) -->
    <div class="hero-scoreband">
      <a class="hsb-item hsb-basis" href="basisscore.php" title="Basis-Score ansehen (Grundpflichten – 0 ist einwandfrei; aktuell: <?= (int)$basisBd['total'] ?> Punkte · <?= h(basis_band_label($basisBandD)) ?>)">
        <span class="hsb-label">Basis</span>
        <span class="hsb-val" style="color:<?= $heroBasisColor ?>"><i class="ti <?= basis_band_icon($basisBandD) ?>"></i></span>
      </a>
      <span class="hsb-divider" aria-hidden="true"></span>
      <a class="hsb-item hsb-event" href="score.php" title="Eventscore ansehen (du: <?= $myScore ?> Punkte · Schnitt aller Mitglieder: ⌀ <?= number_format($scoreAvg, 1, ',', '') ?>)">
        <span class="hsb-label">Eventscore</span>
        <span class="hsb-val" style="color:<?= $heroScoreColor ?>"><i class="ti <?= score_band_icon($scoreBand) ?>"></i> <?= h(score_band_label($scoreBand)) ?></span>
      </a>
    </div>
  </div>
</div>

<?php if ($liveMeeting): $liveRede = meeting_redeliste($liveMeeting); ?>
  <div class="alert-live">
    <span class="dot"></span>
    <span><strong><a href="meeting.php?id=<?= (int)$liveMeeting['id'] ?>" style="color:inherit"><?= h(meeting_label($liveMeeting)) ?></a> läuft gerade</strong><?php if ($liveMeeting['location']): ?> · <?= h($liveMeeting['location']) ?><?php endif; ?></span>
    <a class="btn danger" href="<?= h(member_join_url($liveRede, $me)) ?>" target="_blank" rel="noopener"><i class="ti ti-list-numbers"></i> Zur Redeliste</a>
  </div>
<?php endif; ?>

<?php if (can_admin()) { echo graph_secret_notice_html(); echo cron_notice_html(); } ?>

<div class="tiles">
  <a class="tile link" href="#offen">
    <i class="ti ti-checkbox"></i>
    <div class="num"><?= $nTasks ?></div><div class="lbl">offene Aufgaben</div>
  </a>
  <div class="tile accent-green">
    <i class="ti ti-calendar-check"></i>
    <div class="num"><?= count($myShifts) ?></div><div class="lbl">kommende Einsätze</div>
  </div>
  <div class="tile">
    <i class="ti ti-gavel"></i>
    <?php if ($nextDays === null): ?>
      <div class="num">–</div><div class="lbl">keine Sitzung geplant</div>
    <?php elseif ($nextDays === 0): ?>
      <div class="num">heute</div><div class="lbl">ist die nächste Sitzung</div>
    <?php else: ?>
      <div class="num"><?= $nextDays ?></div><div class="lbl"><?= $nextDays === 1 ? 'Tag' : 'Tage' ?> bis zur nächsten Sitzung</div>
    <?php endif; ?>
    <?php if (trim((string)($me['referat'] ?? '')) !== '' && $reportMeeting): ?>
      <?php if ($reportDone): ?>
        <a class="tile-link is-done" href="report.php" title="Bericht ist eingetragen – zum Ändern anklicken"><i class="ti ti-circle-check"></i> <span class="tl-text">Bericht eingetragen</span><span class="tl-short">Bericht ✓</span></a>
      <?php else: ?>
        <a class="tile-link" href="report.php" title="Bericht eintragen"><i class="ti ti-file-text"></i> <span class="tl-text">Bericht eintragen</span><span class="tl-short">Berichte</span></a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if (is_technik($me) && ($bugsOpen = bug_open_count()) > 0): ?>
    <a class="tile link accent-amber" href="admin/bugs.php" title="Offene Bug-Tickets verwalten (Admin)">
      <i class="ti ti-bug"></i>
      <div class="num"><?= $bugsOpen ?></div><div class="lbl">offene Bug-Tickets</div>
    </a>
  <?php endif; ?>
  <?php if (is_technik($me) && ($fbOpen = feedback_open_count()) > 0): ?>
    <a class="tile link accent-amber" href="admin/feedback.php" title="Unerledigte Rückmeldungen bearbeiten (Admin)">
      <i class="ti ti-message-2"></i>
      <div class="num"><?= $fbOpen ?></div><div class="lbl">offene Rückmeldungen</div>
    </a>
  <?php endif; ?>
  <?php $notifyMail = trim((string)($me['notify_email'] ?? ''));
        $pushDevices = count(push_subscriptions_for((int)$me['id']));
        $remindCustom = count(notify_prefs_cache()[(int)$me['id']] ?? []); // Abweichungen vom Standard (Mitteilungs-Register)
        $remindTip = 'Vor Einsätzen: ' . ($lead ? 'an (' . $lead . ' Tg. vorher)' : 'aus')
                   . ' · Eingeplant: ' . ($notifyAssign ? 'an' : 'aus')
                   . ' · Push: ' . ($pushDevices ? $pushDevices . ' Gerät' . ($pushDevices === 1 ? '' : 'e') : 'aus')
                   . ($notifyMail !== '' ? ' · Zustellung an ' . $notifyMail : '')
                   . ' – klicken zum Einstellen'; ?>
  <a class="tile link" href="erinnerungen.php" title="<?= h($remindTip) ?>">
    <span class="tile-link"><i class="ti ti-settings"></i> Einstellen</span>
    <i class="ti ti-bell"></i>
    <?php if ($remindCustom > 0): ?>
      <div class="num"><?= $remindCustom ?></div><div class="lbl">Mitteilungs-Anpassungen</div>
    <?php else: ?>
      <div class="num"><i class="ti ti-circle-check" style="color:var(--green);font-size:1.5rem"></i></div><div class="lbl">Mitteilungen: Standard</div>
    <?php endif; ?>
  </a>
</div>

<?php if (current_role() === 'sekretariat'): ?>
  <div class="card sekki-card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-id-badge-2"></i> Sekki-Info</div>
    <?= sekretariat_overview_html(true) ?>
  </div>
<?php endif; ?>

<?php // Nach der Sitzung braucht jeder Abstimmungsgegenstand einen festgehaltenen Beschluss –
      // sonst bleibt z. B. die Protokoll-Veröffentlichung für immer hängen. Sichtbar für alle,
      // die Beschlüsse festhalten dürfen (Sekretariat, Vorsitz, Admin).
if (can_manage_meetings() && ($openVoteMeetings = meetings_with_open_vote_items())): ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-gavel"></i> Beschlüsse festhalten</div>
    <p class="small muted" style="margin:0 0 .5rem">Diese Sitzungen sind vorbei, aber Abstimmungsgegenstände stehen noch auf „offen" – bitte <strong>angenommen</strong> oder <strong>vertagt</strong> festhalten. Gesamtübersicht: <a href="admin/meetings.php?t=protokolle">Protokolle &amp; Abstimmungsgegenstände</a>.</p>
    <ul class="sekki-tops" style="margin:0">
      <?php foreach ($openVoteMeetings as $om): ?>
        <li>
          <span class="pill pill-warn" style="font-size:.7rem"><?= (int)$om['open_votes'] ?> offen</span>
          <strong><?= h(meeting_label($om)) ?></strong>
          <span class="muted small">· <?= h(fmt_date(substr((string)$om['starts_at'], 0, 10))) ?></span>
          <a class="small" href="meeting.php?id=<?= (int)$om['id'] ?>#abstimmung">festhalten</a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php // Finanzen-Kachel (bei Bedarf): offene Belegblätter für die Finanzen-Rolle
if (current_role() === 'finanzen' && ($finOpen = expense_claims_open())): ?>
  <div class="card finance-card">
    <?php // Der Knopf sitzt bewusst OBEN in der Überschrift: Bei bis zu 5 Zeilen Liste müsste man
          // sonst erst an allem vorbeiscrollen, um zur Finanzen-Seite zu kommen (Rückmeldung aus der Praxis). ?>
    <div class="section-title" style="margin-top:0;flex-wrap:wrap">
      <?php // Überschrift + Zahl bleiben als EINE Einheit zusammen; nur der Knopf rutscht beim
            // Umbruch (Handy) als Ganzes in die zweite Zeile – sonst stünde die Zahl allein da. ?>
      <span style="display:inline-flex;align-items:center;gap:.5rem"><i class="ti ti-coins" style="color:var(--petrol)"></i> Finanzen – offene Belegblätter <span class="badge-count"><?= count($finOpen) ?></span></span>
      <?php // Ohne Symbol im Knopf: ein Icon davor schöbe die Beschriftung sichtbar aus der Mitte. ?>
      <a class="btn small" href="finanzen.php" style="margin-left:auto;text-align:center">Zur Finanzen-Seite</a>
    </div>
    <table class="list stack-sm">
      <tbody>
      <?php foreach (array_slice($finOpen, 0, 5) as $fc): ?>
        <tr>
          <td class="muted small" data-label="Eingereicht" style="white-space:nowrap"><?= h(fmt_date(substr((string)$fc['created_at'], 0, 10))) ?></td>
          <td><strong><?= h($fc['name']) ?></strong> <span class="small muted">· <?= h($fc['purpose']) ?></span></td>
          <td style="text-align:right;font-weight:700;white-space:nowrap"><?= euro((int)$fc['total_cents']) ?></td>
          <td style="text-align:right"><a class="btn secondary small" href="finanzen.php?dl=<?= (int)$fc['id'] ?>" target="_blank" rel="noopener" title="Belegblatt herunterladen"><i class="ti ti-download"></i></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($finOpen) > 5): ?>
      <p class="small muted" style="margin:.5rem 0 0">… und <strong><?= count($finOpen) - 5 ?></strong> weitere – alle <?= count($finOpen) ?> stehen auf der <a href="finanzen.php">Finanzen-Seite</a>.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php $sunGifts = sommer_gifts_unseen($meId);
      // Testvorschau (nur Admin/Technik, keine echte Mitteilung vorhanden): Demo-Karte, wie sie ein:e Empfänger:in sieht.
      if (!$sunGifts && test_unlock_all()) $sunGifts = [['id' => 0, 'from_name' => 'Beispiel-Person', 'message' => 'Danke für deine mega Hilfe beim Landauer Sommer – ohne dich wär das nichts geworden! ☀️', 'demo' => true]];
      foreach ($sunGifts as $gift): $isDemo = !empty($gift['demo']); ?>
  <div class="card sun-gift">
    <div class="sun-gift-rays" aria-hidden="true"></div>
    <div class="sun-gift-disc" aria-hidden="true"></div>
    <div class="sun-gift-inner">
      <div class="sun-gift-kicker"><i class="ti ti-sun"></i> Ein Sommergeschenk<?= $isDemo ? ' · Testvorschau' : '' ?></div>
      <h2 class="sun-gift-title">Du hast den Hochsommer geschenkt bekommen! ☀️</h2>
      <p class="sun-gift-from">von <strong><?= h(short_name((string)$gift['from_name'])) ?></strong></p>
      <?php if (trim((string)$gift['message']) !== ''): ?>
        <blockquote class="sun-gift-msg"><?= nl2br(h($gift['message'])) ?></blockquote>
      <?php endif; ?>
      <p class="sun-gift-note"><i class="ti ti-palette"></i> Das App-Design „Hochsommer" ist jetzt für dich freigeschaltet und wurde gerade aktiviert – umschalten kannst du es jederzeit über das Design-Menü.</p>
      <?php if ($isDemo): ?>
        <button class="btn sun-gift-btn" type="button" disabled><i class="ti ti-heart"></i> Danke!</button>
      <?php else: ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="dismiss_sommer_gift"><input type="hidden" name="gift_id" value="<?= (int)$gift['id'] ?>">
        <button class="btn sun-gift-btn" type="submit"><i class="ti ti-heart"></i> Danke!</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php $sommerQuota = sommer_gift_quota($meId); $sommerPrev = $sommerQuota < 1 && test_unlock_all(); if ($sommerQuota > 0 || $sommerPrev): $sommerN = $sommerQuota > 0 ? $sommerQuota : 2; ?>
  <div class="card gift-card sommer-offer">
    <div class="gift-sun" aria-hidden="true"><span class="gift-sun-disc"></span></div>
    <div class="sommer-offer-body">
      <div>
        <strong><i class="ti ti-sun" style="color:#f2a900"></i> Du kannst den Sommer verschenken<?= $sommerPrev ? ' <span class="small muted">(Testvorschau)</span>' : '' ?></strong>
        <p class="small muted" style="margin:.2rem 0 0">Als König:in des Sommers darfst du noch <strong><?= $sommerN ?></strong> <?= $sommerN === 1 ? 'Person' : 'Personen' ?> das App-Design „Hochsommer" schenken. Kein Muss – nur ein nettes Dankeschön, wann immer du magst.</p>
      </div>
      <a class="btn small" href="achievements.php#sommer-gift"><i class="ti ti-gift"></i> Verschenken</a>
    </div>
  </div>
<?php endif; ?>

<?php $profilOffen = profile_todo($me); if ($profilOffen): ?>
  <?php /* Bewusst KEINE echte Aufgabe: zählt nicht in $nTasks, geht in keinen Score ein und
           löst nichts aus. Der Hinweis verschwindet von selbst, sobald alles beantwortet ist. */ ?>
  <div class="card tour-offer">
    <div class="tour-offer-body">
      <div>
        <strong><i class="ti ti-user-edit" style="color:var(--petrol)"></i> Dein Profil ist noch nicht ganz fertig</strong>
        <p class="small muted" style="margin:.2rem 0 0">Die anderen sehen dort, wer du bist und womit man zu dir kommen kann – gerade für neue Mitglieder ist das Gold wert. Es fehlt noch:
          <?php foreach ($profilOffen as $i => $po): ?><?= $i ? ' · ' : '' ?><a href="profil.php#<?= h($po['anchor']) ?>"><?= h($po['label']) ?></a><?php endforeach; ?>.
        </p>
        <p class="small muted" style="margin:.35rem 0 0"><i class="ti ti-info-circle"></i> Freiwillig – das zählt in keine Aufgabe und in keinen Score. Bei der Telefonnummer reicht auch ein Häkchen bei „möchte ich nicht angeben".</p>
      </div>
      <div class="tour-offer-actions">
        <a class="btn small" href="profil.php#<?= h($profilOffen[0]['anchor']) ?>"><i class="ti ti-pencil"></i> Profil ausfüllen</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($me && member_show_tour_task($meId)): ?>
  <div class="card tour-offer">
    <div class="tour-offer-body">
      <div>
        <strong><i class="ti ti-route" style="color:var(--petrol)"></i> Kennst du dich schon aus?</strong>
        <p class="small muted" style="margin:.2rem 0 0">Ein kurzer <strong>geführter Rundgang</strong> zeigt dir das Dashboard und alle Bereiche der App. Ganz freiwillig – wann immer du magst.</p>
      </div>
      <div class="tour-offer-actions">
        <a class="btn small" href="dashboard.php?tour=1"><i class="ti ti-player-play"></i> Rundgang starten</a>
        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="tour_dismiss"><button class="btn small secondary" type="submit"><i class="ti ti-check"></i> Ich kenne mich bereits gut aus</button></form>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php $messages = dm_unread_for($meId); if ($messages):
    // Absender-Avatare: sender_name ist Freitext – wo er zu einer aktiven Person passt, zeigen wir deren Mini-Avatar.
    $senderAv = [];
    $senderNames = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x['sender_name']), $messages))));
    if ($senderNames) {
        $in = implode(',', array_fill(0, count($senderNames), '?'));
        $sq = db()->prepare("SELECT name, pronouns, avatar_decos, avatar_palette, avatar_ink FROM members WHERE active = 1 AND name IN ($in)");
        $sq->execute($senderNames);
        foreach ($sq->fetchAll() as $sr) $senderAv[(string)$sr['name']] = $sr; // bei Namensgleichheit gewinnt der letzte – für ein Deko-Chip unkritisch
    } ?>
  <div class="section-title"><i class="ti ti-message-2"></i> Nachrichten <span class="badge-count"><?= count($messages) ?></span></div>
  <?php foreach ($messages as $msg): $sn = trim((string)$msg['sender_name']); ?>
    <div class="card msg-card<?= $msg['is_announce'] ? ' is-announce' : '' ?>">
      <div class="msg-head">
        <?php if ($sn !== '' && isset($senderAv[$sn])): ?><?= avatar_bubble($senderAv[$sn], ' msg-av') ?><?php endif; ?>
        <strong><i class="ti <?= $msg['is_announce'] ? 'ti-speakerphone' : 'ti-message-2' ?>"></i>
          <?= $msg['is_announce'] ? 'Ankündigung' : 'Nachricht' ?><?= $sn !== '' ? ' von ' . h($sn) : '' ?></strong>
        <span class="small muted"><?= h(fmt_date(substr((string)$msg['created_at'], 0, 10))) ?></span>
        <?php $mLink = trim((string)($msg['link'] ?? '')); ?>
        <div class="msg-actions">
          <?php if ($mLink !== ''): ?>
            <form method="post" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="action" value="view_message"><input type="hidden" name="msg_id" value="<?= (int)$msg['id'] ?>">
              <button class="btn small" type="submit"><i class="ti ti-eye"></i> Ansehen</button>
            </form>
          <?php endif; ?>
          <form method="post" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="dismiss_message"><input type="hidden" name="msg_id" value="<?= (int)$msg['id'] ?>">
            <button class="btn secondary small" type="submit" data-puste="aufgabe"><i class="ti ti-check"></i> <?= $mLink !== '' ? 'Gelesen' : 'Erledigt' ?></button>
          </form>
        </div>
      </div>
      <div class="msg-body"><?= nl2br(h($msg['body'])) ?></div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if (!$nTasks): // deckt ALLE Aufgaben-Typen ab, die unten Karten rendern ?>
  <div class="section-title" id="offen"><i class="ti ti-circle-check" style="color:var(--green)"></i> Offene Aufgaben</div>
  <div class="card"><p class="empty"><i class="ti ti-circle-check" style="color:var(--green)"></i> Alles erledigt – nichts zu tun.</p></div>
<?php else:
  $urgent = ($openVotes && deadline_is_soon(days_until_d($openVotes[0]['deadline']))) || $reportUrgent
            || ($maybeEvents && deadline_is_urgent(days_until_d($maybeEvents[0]['deadline'])));
?>
  <div class="attention<?= $urgent ? '' : ' warn' ?>" id="offen">
  <div class="section-title"><i class="ti <?= $urgent ? 'ti-alert-triangle' : 'ti-clock' ?>"></i> Offene Aufgaben <span class="badge-count"><?= $nTasks ?></span></div>
  <p class="note small" style="margin:-.3rem 0 .9rem"><?= $urgent
      ? 'Achtung: Etwas ist dringend – bitte zeitnah erledigen!'
      : 'Bitte zeitnah erledigen – sonst fehlt der Orga die Planung.' ?></p>
  <div class="cardgrid">
  <?php if ($reportDue): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem">
        <strong style="font-size:1.02rem"><i class="ti ti-file-text"></i> Bericht fehlt</strong>
        <span class="pill <?= $reportUrgent ? 'pill-bad' : 'pill-warn' ?>" style="margin-left:auto"><?= $reportDays <= 0 ? 'heute fällig' : ($reportDays === 1 ? 'morgen fällig' : 'in ' . $reportDays . ' Tagen') ?></span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0">Dein Referat-Bericht zur <strong><?= meeting_link($reportMeeting) ?></strong> (<?= h(fmt_date(substr((string)$reportMeeting['starts_at'], 0, 10))) ?>) fehlt noch.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small urgent" href="report.php"><i class="ti ti-pencil"></i> Bericht eintragen</a></div>
    </div>
  <?php endif; ?>
  <?php foreach ($openVotes as $e):
      $days = days_until_d($e['deadline']);
      [$pillCls, $pillTxt] = deadline_pill($days);
      $hs = event_help_stats((int)$e['id']);
  ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><a href="event.php?id=<?= (int)$e['id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h($e['title']) ?></a></strong>
        <?php if (!empty($e['important'])): ?><span class="pill pill-important"><i class="ti ti-alert-triangle-filled"></i> Extrem wichtig</span><?php endif; ?>
        <span class="pill <?= $pillCls ?>" style="margin-left:auto"><?= $pillTxt ?> · <?= fmt_date($e['deadline']) ?></span>
      </div>
      <?php $evRange = fmt_event_range($e['starts_at'] ?? null, $e['ends_at'] ?? null); if ($evRange !== ''): ?>
        <div class="small muted" style="margin-top:.3rem"><i class="ti ti-calendar-event"></i> <?= h($evRange) ?></div>
      <?php endif; ?>
      <?php if ($hs['needed'] > 0): $pct = min(100, (int)round($hs['got'] / $hs['needed'] * 100)); $barCls = $hs['got'] >= $hs['needed'] ? '' : ($hs['got'] > 0 ? 'warn' : 'bad'); ?>
        <div class="progress-row">
          <div class="progress <?= $barCls ?>"><span style="width:<?= $pct ?>%"></span></div>
          <span class="small muted"><?= $hs['got'] ?>/<?= $hs['needed'] ?> besetzte Schichten</span>
        </div>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small urgent" href="event.php?id=<?= (int)$e['id'] ?>"><i class="ti ti-pencil"></i> Jetzt eintragen</a></div>
    </div>
  <?php endforeach; ?>
  <?php foreach ($maybeEvents as $e):
      $days = days_until_d($e['deadline']);
  ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem">
        <strong style="font-size:1.02rem"><i class="ti ti-help-circle"></i> „Evtl." offen</strong>
        <span class="pill <?= deadline_is_urgent($days) ? 'pill-bad' : 'pill-warn' ?>" style="margin-left:auto"><?= $days <= 0 ? 'läuft heute ab' : ($days === 1 ? 'läuft morgen ab' : 'in ' . $days . ' Tagen') ?> · <?= fmt_date($e['deadline']) ?></span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0">Bei <strong><?= event_link($e) ?></strong> hast du noch „Evtl." stehen. Bitte auf Ja oder Nein festlegen – sonst wird es zum Fristende automatisch <strong>Nein</strong>.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small urgent" href="event.php?id=<?= (int)$e['id'] ?>#meine-rueckmeldung"><i class="ti ti-pencil"></i> Festlegen</a></div>
    </div>
  <?php endforeach; ?>
  <?php if ($infosUnseen): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem">
        <strong style="font-size:1.02rem"><i class="ti ti-pin"></i> Wichtige Informationen</strong>
        <span class="pill pill-info" style="margin-left:auto">neu</span>
      </div>
      <?php if ($newInfos): ?>
        <p class="small muted" style="margin:.3rem 0 0"><?php
          if (count($newInfos) === 1) {
              echo '„<strong>' . h((string)$newInfos[0]['title']) . '</strong>" wurde zu den wichtigen Infos in deinem Bereich hinzugefügt.';
          } else {
              $titles = array_map(fn($i) => '„' . h((string)$i['title']) . '"', array_slice($newInfos, 0, 3));
              echo count($newInfos) . ' neue Einträge wurden zu den wichtigen Infos in deinem Bereich hinzugefügt: ' . implode(', ', $titles) . (count($newInfos) > 3 ? ' …' : '') . '.';
          }
        ?></p>
      <?php else: ?>
        <p class="small muted" style="margin:.3rem 0 0">Es gibt wichtige Infos &amp; Anleitungen für dich – bitte einmal ansehen.</p>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small" href="info.php"><i class="ti ti-eye"></i> Jetzt ansehen</a></div>
    </div>
  <?php endif; ?>
  <?php foreach ($votesByMeeting as $mid => $vitems): $vm = $vitems[0]; $vdays = days_until_d($vm['starts_at']); ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-checkbox"></i> Abstimmungsgegenstände lesen</strong>
        <span class="pill pill-info" style="margin-left:auto"><?= count($vitems) ?> ungelesen<?php if ($vdays >= 0): ?> · <?= $vdays === 0 ? 'Sitzung heute' : ($vdays === 1 ? 'Sitzung morgen' : 'in ' . $vdays . ' Tagen') ?><?php endif; ?></span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0">Zur <strong><?= h((string)$vm['meeting_title']) ?></strong> (<?= h(fmt_date(substr((string)$vm['starts_at'], 0, 10))) ?>) <?= count($vitems) === 1 ? 'gibt es einen Abstimmungsgegenstand' : 'gibt es ' . count($vitems) . ' Abstimmungsgegenstände' ?>, den/die du vor der Sitzung lesen solltest.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small" href="meeting.php?id=<?= (int)$mid ?>#abstimmung"><i class="ti ti-eye"></i> Jetzt lesen</a></div>
    </div>
  <?php endforeach; ?>
  <?php foreach ($protocolUploads as $pm): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-notebook" style="color:var(--petrol)"></i> Protokoll hochladen</strong>
        <span class="pill pill-warn" style="margin-left:auto">du bist Protokollant:in</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0">Für die <strong><?= meeting_link($pm, '', 'protokoll') ?></strong> (<?= h(fmt_date(substr((string)$pm['starts_at'], 0, 10))) ?>) fehlt noch das fertige Protokoll. <strong>Kein Word auf dem Gerät? Kein Problem:</strong> Auf der Sitzungsseite legt <strong>„In Teams schreiben"</strong> den Entwurf an – schreiben im Browser, am Ende dort „Fertige Fassung aus Teams übernehmen". Alternativ die Vorlage in Word ausfüllen und als .docx hochladen. Bitte nicht von Hand in einen Teams-Kanal legen – davon erfährt die App nichts.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small" href="meeting.php?id=<?= (int)$pm['id'] ?>#protokoll"><i class="ti ti-notebook"></i> Zum Protokoll</a></div>
    </div>
  <?php endforeach; ?>
  <?php foreach ($protocolPublish as $pm): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-upload" style="color:var(--petrol)"></i> Protokoll veröffentlichen</strong>
        <span class="pill pill-warn" style="margin-left:auto">Sekretariat</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0">Das Protokoll der <strong><?= meeting_link($pm, '', 'protokoll') ?></strong> (<?= h(fmt_date(substr((string)$pm['starts_at'], 0, 10))) ?>) wurde <strong>angenommen</strong> – jetzt als PDF nach OLAT veröffentlichen.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small" href="meeting.php?id=<?= (int)$pm['id'] ?>#protokoll"><i class="ti ti-upload"></i> Veröffentlichen</a></div>
    </div>
  <?php endforeach; ?>
  <?php if ($lbPending): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-snowflake" style="color:var(--petrol)"></i> Vorlesungsfreie Zeit festlegen</strong>
        <span class="pill pill-warn" style="margin-left:auto">Sekretariat</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0"><?= streak_break_since() !== '' ? 'Der Streak-Schutz läuft bereits, aber <strong>ohne Enddatum</strong>.' : 'Es steht <strong>keine ordentliche Sitzung</strong> mehr an.' ?> Leg fest, <strong>bis wann</strong> vorlesungsfreie Zeit ist – so lange kann niemand die Streak verlieren (gesammelt wird normal weiter; danach 7 Tage Schonfrist).</p>
      <form method="post" class="btn-row" style="margin-top:.7rem;align-items:flex-end;gap:.6rem;flex-wrap:wrap">
        <?= csrf_field() ?><input type="hidden" name="action" value="set_lecture_break">
        <div style="min-width:180px"><label for="lb_until" class="small">Vorlesungsfrei bis (einschließlich)</label><input type="text" class="fp-date" name="until" id="lb_until" required placeholder="Enddatum wählen"></div>
        <button class="btn small" type="submit"><i class="ti ti-snowflake"></i> Festlegen</button>
      </form>
    </div>
  <?php endif; ?>
  <?php if ($pwCheck): $pwLast = admin_pw_check_last(); ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-shield-lock" style="color:var(--petrol)"></i> Technik-Login bestätigen</strong>
        <span class="pill pill-warn" style="margin-left:auto">Vorsitz</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0">
        Halbjährliche Kontrolle: Der <strong>Technik-Login</strong> ist der Notfall-Zugang, wenn sonst nichts mehr geht – deshalb sollte der Vorsitz ihn nicht aus den Augen verlieren.
        Gib das Verwaltungs-Passwort einmal ein; es wird <strong>nur geprüft</strong>, nichts geändert.
        <?= $pwLast !== '' ? 'Zuletzt bestätigt am <strong>' . h(fmt_date($pwLast)) . '</strong>.' : 'Bisher noch nie bestätigt.' ?>
      </p>
      <?php if (admin_pw_check_locked()): ?>
        <p class="small" style="margin:.6rem 0 0"><i class="ti ti-lock-exclamation" style="color:var(--red)"></i> Zu viele Fehlversuche – bitte in ein paar Minuten noch einmal.</p>
      <?php else: ?>
        <form method="post" class="btn-row" style="margin-top:.7rem;align-items:flex-end;gap:.6rem;flex-wrap:wrap">
          <?= csrf_field() ?><input type="hidden" name="action" value="pw_check">
          <div style="min-width:200px"><label for="pw_check" class="small">Verwaltungs-Passwort</label><input type="password" name="pw" id="pw_check" autocomplete="off" required></div>
          <button class="btn small" type="submit"><i class="ti ti-shield-check"></i> Bestätigen</button>
        </form>
        <p class="small muted" style="margin:.5rem 0 0">Nicht mehr zur Hand? Dann setzt das <strong>Technik-Referat</strong> unter Verwaltung ein neues und gibt es dir weiter – genau dafür ist diese Kontrolle da.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($pronounsMissing): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem">
        <strong style="font-size:1.02rem"><i class="ti ti-user-circle"></i> Pronomen festlegen</strong>
        <span class="pill pill-info" style="margin-left:auto">einmalig</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0">Bitte einmal deine Pronomen wählen – sie werden in der Redeliste angezeigt.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small" href="profil.php#pronomen"><i class="ti ti-user-circle"></i> Jetzt festlegen</a></div>
    </div>
  <?php endif; ?>
  <?php foreach ($gtPending as $g): $gdays = days_until_d((string)$g['starts_at']); ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-confetti" style="color:var(--violet)"></i> Get-Together: bist du dabei?</strong>
        <span class="pill" style="margin-left:auto;background:var(--violet-bg);color:var(--violet)"><?= $gdays <= 0 ? 'heute' : ($gdays === 1 ? 'morgen' : 'in ' . $gdays . ' Tagen') ?></span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0"><strong><a class="entity-link" href="gettogether.php?id=<?= (int)$g['id'] ?>"><?= h($g['title']) ?></a></strong> · <?= h(fmt_slot($g['starts_at'], $g['ends_at'])) ?><?php if (trim((string)$g['location']) !== ''): ?> · <?= h($g['location']) ?><?php endif; ?>. Freiwillig – sag kurz Bescheid. Bei „Nicht dabei" verschwindet es aus deinem Kalender.</p>
      <form method="post" class="gt-pick" style="margin-top:.7rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="gt_rsvp">
        <input type="hidden" name="gt_id" value="<?= (int)$g['id'] ?>">
        <button type="submit" name="status" value="yes" data-puste="zusage" class="gt-btn gt-yes"><i class="ti ti-check"></i> Dabei</button>
        <button type="submit" name="status" value="maybe" class="gt-btn gt-maybe"><i class="ti ti-help"></i> Vielleicht</button>
        <button type="submit" name="status" value="no" class="gt-btn gt-no"><i class="ti ti-x"></i> Nicht dabei</button>
      </form>
      <div style="margin-top:.5rem"><a class="small" href="gettogether.php?id=<?= (int)$g['id'] ?>"><i class="ti ti-basket"></i> Wer kommt &amp; wer bringt was mit ›</a></div>
    </div>
  <?php endforeach; ?>
  <?php foreach ($pollsPending as $p): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-calendar-search" style="color:var(--petrol)"></i> Terminabstimmung: bitte abstimmen</strong>
        <span class="pill pill-bad" style="margin-left:auto;white-space:nowrap">verpflichtend</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0"><strong><a class="entity-link" href="terminfinder.php?id=<?= (int)$p['id'] ?>"><?= h($p['title']) ?></a></strong><?php if (trim((string)$p['location']) !== ''): ?> · <?= h($p['location']) ?><?php endif; ?>. Trag kurz ein, wann du kannst – das Ergebnis hilft der Orga, einen Termin zu finden.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small" href="terminfinder.php?id=<?= (int)$p['id'] ?>"><i class="ti ti-checkbox"></i> Jetzt abstimmen</a></div>
    </div>
  <?php endforeach; ?>
  <?php foreach ($umlaufPending as $u): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-mailbox" style="color:var(--petrol)"></i> Umlaufbeschluss: bitte abstimmen</strong>
        <span class="pill pill-bad" style="margin-left:auto;white-space:nowrap">verpflichtend</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0"><strong><?= umlauf_link($u) ?></strong> · Ja/Nein/Enthaltung bis <?= h(fmt_slot((string)$u['deadline'], null)) ?><?= empty($u['secret']) ? '' : ' · geheime Abstimmung' ?>.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small" href="umlauf.php?id=<?= (int)$u['id'] ?>"><i class="ti ti-checkbox"></i> Jetzt abstimmen</a></div>
    </div>
  <?php endforeach; ?>
  <?php foreach ($pollTaskPending as $p): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-list-check" style="color:var(--petrol)"></i> Abstimmung: bitte abstimmen</strong>
        <span class="pill pill-bad" style="margin-left:auto;white-space:nowrap">verpflichtend</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0"><strong><?= h($p['title']) ?></strong><?php if (trim((string)($p['deadline'] ?? '')) !== ''): ?> · bis <?= h(fmt_slot((string)$p['deadline'], null)) ?><?php endif; ?><?= empty($p['secret']) ? '' : ' · geheime Abstimmung' ?>.</p>
      <div class="btn-row" style="margin-top:.7rem"><a class="btn small" href="umlauf.php?poll=<?= (int)$p['id'] ?>"><i class="ti ti-checkbox"></i> Jetzt abstimmen</a></div>
    </div>
  <?php endforeach; ?>
  <?php foreach ($pollOptional as $p): ?>
    <div class="card hoverable">
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <strong style="font-size:1.02rem"><i class="ti ti-list-check" style="color:var(--petrol)"></i> Abstimmung läuft</strong>
        <span class="pill pill-warn" style="margin-left:auto;white-space:nowrap">freiwillig</span>
      </div>
      <p class="small muted" style="margin:.3rem 0 0"><strong><?= h($p['title']) ?></strong><?php if (trim((string)($p['deadline'] ?? '')) !== ''): ?> · bis <?= h(fmt_slot((string)$p['deadline'], null)) ?><?php endif; ?><?= empty($p['secret']) ? '' : ' · geheime Abstimmung' ?>. Mitmachen ist freiwillig – du kannst den Hinweis auch einfach ausblenden.</p>
      <div class="btn-row" style="margin-top:.7rem;flex-wrap:wrap">
        <a class="btn small" href="umlauf.php?poll=<?= (int)$p['id'] ?>"><i class="ti ti-checkbox"></i> Jetzt abstimmen</a>
        <form method="post" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="poll_hide"><input type="hidden" name="poll_id" value="<?= (int)$p['id'] ?>">
          <button class="btn secondary small" type="submit" title="Diesen Hinweis für dich ausblenden – abstimmen geht weiterhin über die Seite Abstimmungen"><i class="ti ti-eye-off"></i> Ausblenden</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  </div>
<?php endif; ?>

<div class="duo">
  <div class="card">
    <div class="section-title"><i class="ti ti-calendar-check"></i> Meine nächsten Termine</div>
    <?php
    $agendaRow = function (array $mi): void {
        $row = $mi['row']; $d = dt($mi['date']);
        $chip = '<div class="datechip' . ($mi['gt'] ? ' gt' : '') . '"><div class="d">' . ($d ? $d->format('d') : '') . '</div><div class="m">' . ($d ? h(mb_substr(MONTHS[(int)$d->format('n')], 0, 3)) : '') . '</div></div>';
        if ($mi['gt']) { ?>
          <div class="tl-row tl-fun">
            <?= $chip ?>
            <div>
              <div style="font-weight:600"><a class="tl-stretch" href="gettogether.php?id=<?= (int)$row['id'] ?>" style="text-decoration:none;color:var(--ink)"><i class="ti ti-confetti" style="color:var(--violet)"></i> <?= h($row['title']) ?></a> <span class="pill pill-fun">Spaß · freiwillig</span></div>
              <div class="small muted"><?= h(fmt_slot($row['starts_at'], $row['ends_at'])) ?><?php if (trim((string)$row['location']) !== ''): ?> · <i class="ti ti-map-pin" style="font-size:.9em"></i> <?= h($row['location']) ?><?php endif; ?></div>
            </div>
          </div>
        <?php } else { ?>
          <div class="tl-row">
            <?= $chip ?>
            <div>
              <div style="font-weight:600"><a class="tl-stretch" href="event.php?id=<?= (int)$row['event_id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h($row['event_title']) ?></a></div>
              <div class="small muted"><?= h(fmt_slot($row['starts_at'], $row['ends_at'])) ?><?php if ($row['location']): ?> · <i class="ti ti-map-pin" style="font-size:.9em"></i> <?= h($row['location']) ?><?php endif; ?><?php if ($row['label']): ?> · <?= h($row['label']) ?><?php endif; ?></div>
            </div>
          </div>
        <?php }
    };
    ?>
    <?php if (!$myAgenda): ?>
      <p class="empty"><i class="ti ti-coffee"></i> Aktuell keine zugesagten Termine.</p>
    <?php else: ?>
      <div class="timeline">
        <?php foreach (array_slice($myAgenda, 0, 3) as $mi) $agendaRow($mi); ?>
      </div>
      <?php if (count($myAgenda) > 3): ?>
        <details class="tl-more">
          <summary><span class="more-c">… <?= count($myAgenda) - 3 ?> weitere anzeigen</span><span class="more-o">weniger anzeigen</span></summary>
          <div class="timeline" style="margin-top:.2rem">
            <?php foreach (array_slice($myAgenda, 3) as $mi) $agendaRow($mi); ?>
          </div>
        </details>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($myShifts): ?>
      <div class="btn-row" style="margin-top:.8rem"><a class="btn small secondary" href="tauschboerse.php"><i class="ti ti-arrows-exchange"></i> Schichten tauschen</a></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="section-title"><i class="ti ti-gavel"></i> Kommende Sitzungen</div>
    <?php if (!$meetings): ?>
      <p class="empty"><i class="ti ti-calendar-off"></i> Keine Sitzung geplant.</p>
    <?php else: ?>
      <div class="timeline">
        <?php foreach (array_slice($meetings, 0, 3) as $m): $d = dt($m['starts_at']); ?>
          <div class="tl-row">
            <div class="datechip"><div class="d"><?= $d ? $d->format('d') : '' ?></div><div class="m"><?= $d ? mb_substr(MONTHS[(int)$d->format('n')], 0, 3) : '' ?></div></div>
            <div>
              <div style="font-weight:600"><a class="tl-stretch" href="meeting.php?id=<?= (int)$m['id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h(meeting_label($m)) ?></a></div>
              <div class="small muted"><?= h(fmt_slot($m['starts_at'], meeting_ends_at($m))) ?><?php if ($m['location']): ?> · <?= h($m['location']) ?><?php endif; ?></div>
            </div>
            <?php if ($me && meeting_rsvp_open($m)): $mrId = (int)$m['id']; ?>
              <form id="mrsvpOff<?= $mrId ?>" method="post" hidden><?= csrf_field() ?><input type="hidden" name="action" value="meeting_rsvp"><input type="hidden" name="meeting_id" value="<?= $mrId ?>"><input type="hidden" name="status" value="abgemeldet"><input type="hidden" name="reason" value=""></form>
              <form id="mrsvpOn<?= $mrId ?>" method="post" hidden><?= csrf_field() ?><input type="hidden" name="action" value="meeting_rsvp"><input type="hidden" name="meeting_id" value="<?= $mrId ?>"><input type="hidden" name="status" value="online"><input type="hidden" name="reason" value=""></form>
              <button type="button" class="btn small secondary" style="margin-left:auto;white-space:nowrap;padding:.3rem .45rem" title="Abmelden oder online teilnehmen"
                onclick="astaConfirm({title:'Deine Teilnahme',icon:'ti-door-exit',message:'Kommst du zur Sitzung? Du kannst dich abmelden (du wirst im Protokoll als entschuldigt geführt) oder vermerken, dass du nur online teilnimmst – änderbar bis 18:00 Uhr am Sitzungstag.',input:{label:'Warum ist die Teilnahme (in Präsenz) nicht möglich? Wird nur an den Vorsitz weitergeleitet.',placeholder:'z. B. Klausur, krank, unterwegs …'},buttons:[{label:'Abbrechen',class:'secondary'},{label:'Nur online dabei',class:'secondary',requireInput:true,onClick:function(v){var f=document.getElementById('mrsvpOn<?= $mrId ?>');f.querySelector('[name=reason]').value=v||'';f.submit();}},{label:'Abmelden – ich komme nicht',requireInput:true,onClick:function(v){var f=document.getElementById('mrsvpOff<?= $mrId ?>');f.querySelector('[name=reason]').value=v||'';f.submit();}}]})"><i class="ti ti-door-exit"></i></button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($meetings) > 3): ?><div class="small muted" style="margin-top:.4rem"><a href="index.php">Alle im Kalender ›</a></div><?php endif; ?>
      <?php // TOP einreichen unten in der Kachel (wie „Schichten tauschen" nebenan); fragt per Liste, für welche Sitzung
        $topItems = [];
        foreach ($meetings as $tm) {
            if (!top_submission_open($tm)) continue;
            $topItems[] = ['label' => meeting_label($tm), 'sub' => fmt_slot($tm['starts_at'], meeting_ends_at($tm)) . (trim((string)$tm['location']) !== '' ? ' · ' . $tm['location'] : ''),
                           'href' => 'meeting.php?id=' . (int)$tm['id'] . '#tagesordnung', 'icon' => 'ti-gavel'];
        }
      ?>
      <?php if (count($topItems) === 1): ?>
        <div class="btn-row" style="margin-top:.8rem"><a class="btn small secondary" href="<?= h($topItems[0]['href']) ?>"><i class="ti ti-plus"></i> TOP einreichen</a></div>
      <?php elseif ($topItems): ?>
        <div class="btn-row" style="margin-top:.8rem">
          <button type="button" class="btn small secondary" onclick="astaConfirm({title:'TOP einreichen',icon:'ti-plus',message:'Für welche Sitzung möchtest du einen Tagesordnungspunkt einreichen?',list:<?= h(json_encode($topItems, JSON_UNESCAPED_UNICODE)) ?>,buttons:[{label:'Abbrechen',class:'secondary'}]})"><i class="ti ti-plus"></i> TOP einreichen</button>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>try{if(localStorage.getItem('astaHideIcalCard')==='1')document.body.classList.add('ical-hidden');}catch(e){}</script>
<div class="duo" id="abo-duo">
  <div class="card ical-card" id="ical-card">
    <button type="button" class="card-x" id="ical-hide" title="Kachel ausblenden – wenn du dein Kalender-Abo eingerichtet hast" aria-label="Kalender-Abo-Kachel ausblenden"><i class="ti ti-x"></i></button>
    <div class="section-title"><i class="ti ti-calendar-plus"></i> Mein Kalender-Abo</div>
    <div style="display:flex;gap:.9rem;align-items:center">
      <div id="ical-qr" style="background:#fff;padding:6px;border-radius:8px;border:1px solid var(--line);flex:none;width:110px;height:110px;display:grid;place-items:center"></div><!-- feste Größe: QR (96px) lädt per JS nach – ohne Platzhalter springt das Layout -->
      <div>
        <a class="btn small" href="<?= h($webcalUrl) ?>"><i class="ti ti-calendar-plus"></i> Abonnieren</a>
        <p class="small muted" style="margin:.4rem 0 0">Aktualisiert sich automatisch. <strong>iPhone:</strong> Knopf tippen oder QR scannen. <strong>Android/Google/Outlook:</strong> Link kopieren und „Kalender per URL hinzufügen".</p>
      </div>
    </div>
    <div class="btn-row" style="margin-top:.6rem;align-items:center">
      <input type="text" id="ical-url" value="<?= h($icalUrl) ?>" readonly onclick="this.select()" style="flex:1;min-width:0;font-size:.8rem">
      <button class="btn secondary small" type="button" id="ical-copy"><i class="ti ti-copy"></i> Kopieren</button>
    </div>
    <details style="margin-top:.5rem">
      <summary class="small muted" style="cursor:pointer">Anleitung je App</summary>
      <ul class="small muted" style="margin:.4rem 0 0;padding-left:1.1rem">
        <li><strong>Apple/iPhone:</strong> „Abonnieren" tippen → bestätigen.</li>
        <li><strong>Google Kalender</strong> (nur am PC): Andere Kalender → + → „Per URL" → Link einfügen.</li>
        <li><strong>Outlook:</strong> Kalender hinzufügen → Aus dem Internet → Link einfügen.</li>
      </ul>
      <p class="small muted" style="margin:.3rem 0 0">Zeigt Sitzungen &amp; deine eingeteilten Schichten – bitte nicht weitergeben.</p>
    </details>
  </div>

  <div class="card" id="db-absence">
    <div class="section-title"><i class="ti ti-plane-departure"></i> Abwesenheiten</div>
    <?php if (!$absencesAll): ?>
      <p class="empty"><i class="ti ti-users"></i> Aktuell ist niemand abwesend.</p>
    <?php else: $todayStr = date('Y-m-d'); foreach ($absencesAll as $a):
        $now = $a['starts_at'] <= $todayStr && $a['ends_at'] >= $todayStr; ?>
      <div class="small" style="margin:.45rem 0;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <?= member_chip_link(['id' => (int)$a['member_id']] + $a, isset($spitzeChipIds[(int)$a['member_id']]), ' gt-chip-mini') ?>
        <?php if ($now): ?><span class="pill pill-warn" style="font-size:.68rem;padding:.05rem .45rem">jetzt</span><?php endif; ?>
        <span class="muted"><?= fmt_date($a['starts_at']) ?> – <?= fmt_date($a['ends_at']) ?></span>
        <?php if ($a['reason']): ?><span class="muted">· <?= h($a['reason']) ?></span><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
    <div class="small" style="margin-top:.5rem"><a href="absence.php">Abwesenheit eintragen / verwalten ›</a></div>
  </div>

  <!-- Kompakte „Kommende Events"-Kachel: rückt an die Stelle der Abo-Kachel, sobald diese ausgeblendet ist. Standardmäßig aus. -->
  <div class="card events-mini" id="events-mini">
    <div class="section-title" style="margin-top:0"><i class="ti ti-calendar-event"></i> Kommende Events</div>
    <?php $renderUpcoming(); ?>
  </div>
</div>

<div class="section-title upcoming-full" style="margin-top:1.6rem"><i class="ti ti-calendar-event"></i> Kommende Events</div>
<div class="card upcoming-full">
  <?php $renderUpcoming(); ?>
</div>

<div class="card" id="install-card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-device-mobile-down"></i> AStA-App installieren</div>
  <p style="margin-top:0">Leg dir die AStA-App wie eine echte App aufs Gerät – startet im Vollbild, mit eigenem Icon, direktem Zugriff und <strong>echten Push-Benachrichtigungen</strong>: nach der Installation unter <a href="erinnerungen.php">Erinnerungen &amp; Mitteilungen</a> einschalten. (Auf dem iPhone gibt es Push nur aus der installierten App heraus – noch ein Grund mehr. 😉)</p>
  <div class="install-cols">
    <div class="install-col">
      <h3><i class="ti ti-brand-android"></i> Android</h3>
      <button type="button" class="btn install-go" hidden><i class="ti ti-download"></i> Jetzt installieren</button>
      <p class="small muted install-go-hint">In Chrome/Edge auf das Menü <strong>⋮</strong> → „<strong>App installieren</strong>" bzw. „Zum Startbildschirm hinzufügen". Sobald der Browser bereit ist, erscheint hier auch ein Button.</p>
    </div>
    <div class="install-col">
      <h3><i class="ti ti-brand-apple"></i> iPhone / iPad</h3>
      <p class="small">In <strong>Safari</strong> auf das Teilen-Symbol <i class="ti ti-share-3"></i> tippen und dann auf „<strong>Zum Home-Bildschirm</strong>". (Aus technischen Gründen geht das auf iOS/iPadOS nur über diesen Weg, nicht per Knopf.)</p>
    </div>
  </div>
</div>

<script src="<?= base() . asset_v('assets/qrcode.min.js') ?>"></script>
<script>
(function(){
  var el = document.getElementById('ical-qr');
  if (el && window.QRCode) { new QRCode(el, {text: <?= json_encode($webcalUrl) ?>, width: 96, height: 96, correctLevel: QRCode.CorrectLevel.M}); }
  var btn = document.getElementById('ical-copy'), inp = document.getElementById('ical-url');
  if (btn && inp) btn.addEventListener('click', function(){
    inp.select();
    var done = function(){ var o = btn.innerHTML; btn.innerHTML = '<i class="ti ti-check"></i> Kopiert'; setTimeout(function(){ btn.innerHTML = o; }, 1500); };
    if (navigator.clipboard) { navigator.clipboard.writeText(inp.value).then(done, function(){ document.execCommand('copy'); done(); }); }
    else { document.execCommand('copy'); done(); }
  });

  // „Mein Kalender-Abo" ausblenden: X → Rückfrage → Body-Klasse (CSS blendet die Kachel aus und
  // schiebt die kompakte „Kommende Events"-Kachel an ihren Platz). Merker lokal je Gerät.
  var hideBtn = document.getElementById('ical-hide');
  if (hideBtn) hideBtn.addEventListener('click', function(){
    astaConfirm({
      title: 'Kachel ausblenden?',
      icon: 'ti-calendar-off',
      message: 'Blende „Mein Kalender-Abo" aus, wenn du dein Kalender-Abo eingerichtet hast. „Kommende Events" rückt dann an ihre Stelle. Über den Kalender kannst du die Kachel jederzeit wieder einblenden.',
      buttons: [
        { label: 'Abbrechen', class: 'secondary' },
        { label: 'Ausblenden', onClick: function(){ try { localStorage.setItem('astaHideIcalCard', '1'); } catch(e){} document.body.classList.add('ical-hidden'); } }
      ]
    });
  });
})();
</script>
<?php
page_footer();
