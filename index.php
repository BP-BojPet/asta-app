<?php
require __DIR__ . '/lib.php';
db(); // Schema sicherstellen
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;

// Kalender-Optionen unter dem Kalender: StuPa ausblenden, freie Termine anlegen/löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $back = 'index.php' . (($_POST['ret'] ?? '') !== '' ? '?' . ltrim((string)$_POST['ret'], '?') : '') . '#kal-optionen';

    if ($action === 'toggle_stupa' && $me) {
        member_set_hide_stupa($meId, !member_hides_stupa($me));
        flash(member_hides_stupa(current_member()) ? 'StuPa-Sitzungen werden jetzt ausgeblendet (auch im Kalender-Abo).' : 'StuPa-Sitzungen werden wieder angezeigt.', 'success');
        redirect($back);
    }

    if ($action === 'toggle_birthdays' && $me) {
        member_set_hide_birthdays($meId, !member_hides_birthdays($me));
        flash(member_hides_birthdays(current_member()) ? 'Geburtstage werden im Kalender jetzt ausgeblendet.' : 'Geburtstage werden im Kalender wieder angezeigt.', 'success');
        redirect($back);
    }

    if ($action === 'add_termin' && $me) {
        $title = trim((string)($_POST['title'] ?? ''));
        $allDay = isset($_POST['all_day']);
        $start = norm_dtl((string)($_POST['starts_at'] ?? ''));
        if ($allDay && $start) $start = substr($start, 0, 10) . ' 00:00'; // ganztägig: Uhrzeit auf 00:00
        $end = norm_dtl((string)($_POST['ends_at'] ?? ''));
        if ($allDay && $end) $end = substr($end, 0, 10) . ' 00:00';
        $loc = trim((string)($_POST['location'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $audience = (string)($_POST['audience'] ?? 'self');
        $memberIds = array_map('intval', (array)($_POST['members'] ?? []));
        if ($title === '') {
            flash('Bitte gib dem Termin einen Titel.', 'error');
        } elseif (!$start) {
            flash('Bitte Datum/Uhrzeit des Termins angeben.', 'error');
        } elseif ($audience === 'members' && !$memberIds) {
            flash('Bitte wähle mindestens eine Person aus – oder stelle den Termin auf „Nur für mich" bzw. „Für alle".', 'error');
        } else {
            termin_create($meId, $title, $start, $end, $allDay, $loc, $desc, $audience, $memberIds);
            flash('Termin im Kalender angelegt.', 'success');
        }
        redirect($back);
    }

    if ($action === 'del_termin' && $me) {
        termin_delete((int)($_POST['id'] ?? 0), $me);
        flash('Termin gelöscht.', 'success');
        redirect($back);
    }

    if ($action === 'add_poll' && $me) {
        $title = trim((string)($_POST['title'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $loc = trim((string)($_POST['location'] ?? ''));
        $deadline = norm_dtl((string)($_POST['deadline'] ?? ''));
        $opts = [];
        foreach ((array)($_POST['opt'] ?? []) as $o) { $n = norm_dtl((string)$o); if ($n) $opts[] = $n; }
        if ($title === '') {
            flash('Bitte gib der Terminabstimmung einen Titel.', 'error');
            redirect($back);
        } elseif (!$opts) {
            flash('Bitte gib mindestens einen Datumsvorschlag an.', 'error');
            redirect($back);
        } else {
            $pid = date_poll_create($meId, $title, $desc, $loc, $opts, $deadline);
            flash('Terminabstimmung gestartet – alle werden zum Abstimmen aufgefordert.', 'success');
            redirect('terminfinder.php?id=' . $pid);
        }
    }
}

$today = date('Y-m-d');
$view = ($_GET['view'] ?? 'month') === 'week' ? 'week' : 'month';

if ($view === 'week') {
    $ref = DateTime::createFromFormat('Y-m-d', (string)($_GET['d'] ?? '')) ?: new DateTime('today');
    $ref->setTime(0, 0);
    $weekStart = clone $ref;
    $weekStart->modify('-' . (((int)$ref->format('N')) - 1) . ' days');
    $weekEnd = (clone $weekStart)->modify('+6 days');
    $rangeFrom = $weekStart->format('Y-m-d');
    $rangeTo   = $weekEnd->format('Y-m-d');
    $prevD = (clone $weekStart)->modify('-7 days')->format('Y-m-d');
    $nextD = (clone $weekStart)->modify('+7 days')->format('Y-m-d');
} else {
    $cur = DateTime::createFromFormat('Y-m-d', ($_GET['ym'] ?? date('Y-m')) . '-01') ?: new DateTime('first day of this month');
    $cur->modify('first day of this month');
    $cur->setTime(0, 0);
    $monthStart = clone $cur;
    $monthEnd   = (clone $cur)->modify('last day of this month');
    $prev = (clone $cur)->modify('-1 month')->format('Y-m');
    $next = (clone $cur)->modify('+1 month')->format('Y-m');
    $rangeFrom = $monthStart->format('Y-m-d');
    $rangeTo   = $monthEnd->format('Y-m-d');
}

// Daten für den Monat holen
$hideStupa = member_hides_stupa($me); // persönliche Einstellung „StuPa ausblenden"
$hideBirthdays = member_hides_birthdays($me); // persönliche Einstellung „Geburtstage ausblenden"
$meetings = db()->prepare(
    'SELECT * FROM meetings WHERE ' . visible_drafts_sql() . ($hideStupa ? " AND kind != 'stupa'" : '') . ' AND date(starts_at) BETWEEN ? AND ? ORDER BY starts_at'
);
$meetings->execute([$rangeFrom, $rangeTo]);
$meetings = $meetings->fetchAll();

// Freie Termine (für mich / für ausgewählte Personen / für alle) im Sichtfenster
$termine = $meId ? termine_in_range($meId, $rangeFrom, $rangeTo) : [];

// Events (je Event ein Eintrag über sein eigenes Datum) – plus: habe ich abgestimmt?
$events = db()->prepare(
    "SELECT e.id, e.title, e.closed, e.deadline, e.important, e.starts_at, e.ends_at,
            EXISTS(SELECT 1 FROM responses r JOIN event_slots s ON s.id = r.slot_id
                   WHERE s.event_id = e.id AND r.member_id = :me) AS responded
     FROM events e
     WHERE " . visible_drafts_sql('e.', true) . " AND e.starts_at IS NOT NULL AND e.starts_at <> ''
       AND date(e.starts_at) <= :to AND date(COALESCE(NULLIF(e.ends_at, ''), e.starts_at)) >= :from
     ORDER BY e.starts_at"
);
$events->execute([':me' => $meId, ':from' => $rangeFrom, ':to' => $rangeTo]);
$events = $events->fetchAll();

// Nur die Schichten, zu denen die anschauende Person eingeteilt ist
$myShifts = db()->prepare(
    "SELECT s.*, e.title AS event_title
     FROM event_slots s
     JOIN events e ON e.id = s.event_id
     JOIN assignments a ON a.slot_id = s.id AND a.member_id = :me
     WHERE date(s.starts_at) BETWEEN :from AND :to AND e.plan_locked = 1 ORDER BY s.starts_at"
);
$myShifts->execute([':me' => $meId, ':from' => $rangeFrom, ':to' => $rangeTo]);
$myShifts = $myShifts->fetchAll();

$absences = absences_in_range($rangeFrom, $rangeTo);

// AStA Get-Togethers (interne Spaß-Treffen) – wer „Nein" gesagt hat, sieht sie nicht im persönlichen Kalender
$gettogethers = gettogethers_in_range($rangeFrom, $rangeTo);
$gtRsvp = $meId ? gettogether_rsvp_map($meId) : [];

// Zeitinfo für ein Element (ganztägig vs. Uhrzeit + Dauer in Minuten)
$timeinfo = function ($startTs, $endTs) {
    $s = dt($startTs);
    if (!$s || $s->format('H:i') === '00:00') return ['allday' => true, 'startMin' => null, 'endMin' => null];
    $sm = (int)$s->format('G') * 60 + (int)$s->format('i');
    $e = dt($endTs);
    $em = $e ? (int)$e->format('G') * 60 + (int)$e->format('i') : $sm + 60;
    if ($em <= $sm) $em = $sm + 60;
    return ['allday' => false, 'startMin' => $sm, 'endMin' => $em];
};

// Nach Tag indexieren – mit persönlicher Relevanz + Zeitinfo
$byDay = []; // 'Y-m-d' => list of ['type','title','url','allday','startMin','endMin']
foreach ($meetings as $m) {
    $d = substr($m['starts_at'], 0, 10);
    $mType = ($m['kind'] ?? '') === 'stupa' ? 'stupa' : 'meeting';
    $byDay[$d][] = ['type' => $m['cancelled'] ? 'event' : $mType,
        'title' => ($m['cancelled'] ? '(ausgefallen) ' : '') . meeting_label($m), 'url' => 'meeting.php?id=' . (int)$m['id']]
        + $timeinfo($m['starts_at'], meeting_ends_at($m));
}
// Freie Termine (für mich / ausgewählte Personen / alle) – ganztägig spannt über mehrere Tage
foreach ($termine as $t) {
    $tip = trim((string)($t['location'] ?? '')) !== '' ? '📍 ' . $t['location'] : '';
    if (trim((string)($t['description'] ?? '')) !== '') $tip = trim($tip . ' · ' . $t['description'], ' ·');
    if (!empty($t['all_day'])) {
        $from = max(substr((string)$t['starts_at'], 0, 10), $rangeFrom);
        $to   = min(substr((string)($t['ends_at'] ?: $t['starts_at']), 0, 10), $rangeTo);
        $d = new DateTime($from); $end = new DateTime($to);
        while ($d <= $end) {
            $byDay[$d->format('Y-m-d')][] = ['type' => 'termin', 'title' => $t['title'],
                'url' => null, 'tip' => $tip, 'allday' => true, 'startMin' => null, 'endMin' => null];
            $d->modify('+1 day');
        }
    } else {
        $d = substr((string)$t['starts_at'], 0, 10);
        $byDay[$d][] = ['type' => 'termin', 'title' => $t['title'], 'url' => null, 'tip' => $tip]
            + $timeinfo($t['starts_at'], $t['ends_at']);
    }
}
// Event-Einträge (ganztägig über das Event-Datum, mehrtägig berücksichtigt).
// „todo" (noch abstimmen) solange offen und noch nicht abgestimmt, sonst neutral „event".
foreach ($events as $e) {
    $votingOpen = !event_locked($e); // offen = nicht geschlossen UND Frist nicht abgelaufen
    $type = (!$e['responded'] && $votingOpen) ? 'todo' : 'event';
    $from = max(substr((string)$e['starts_at'], 0, 10), $rangeFrom);
    $to   = min(substr((string)($e['ends_at'] ?: $e['starts_at']), 0, 10), $rangeTo);
    $d = new DateTime($from); $end = new DateTime($to);
    while ($d <= $end) {
        $byDay[$d->format('Y-m-d')][] = ['type' => $type, 'title' => $e['title'],
            'url' => 'event.php?id=' . (int)$e['id'], 'important' => !empty($e['important']),
            'allday' => true, 'startMin' => null, 'endMin' => null];
        $d->modify('+1 day');
    }
}
// Eigene eingeteilte Schichten (mit Uhrzeit) – grün hervorgehoben
foreach ($myShifts as $s) {
    $d = substr($s['starts_at'], 0, 10);
    $t = dt($s['starts_at']);
    $label = ($t && $t->format('H:i') !== '00:00' ? $t->format('H:i') . ' ' : '') . $s['event_title']
        . ($s['label'] ? ' (' . $s['label'] . ')' : '');
    $byDay[$d][] = ['type' => 'shift', 'title' => $label, 'url' => 'event.php?id=' . (int)$s['event_id']]
        + $timeinfo($s['starts_at'], $s['ends_at']);
}
foreach ($absences as $a) {
    $own = $meId && (int)$a['member_id'] === $meId;
    $from = max($a['starts_at'], $rangeFrom);
    $to   = min($a['ends_at'], $rangeTo);
    $d = new DateTime($from);
    $end = new DateTime($to);
    while ($d <= $end) {
        $byDay[$d->format('Y-m-d')][] = ['type' => $own ? 'absence' : 'absence-muted',
            'title' => $own ? 'Ich (abwesend)' : $a['name'], 'url' => null,
            'allday' => true, 'startMin' => null, 'endMin' => null];
        $d->modify('+1 day');
    }
}
foreach ($gettogethers as $g) {
    if (($gtRsvp[(int)$g['id']] ?? '') === 'no') continue; // abgesagt → nicht im persönlichen Kalender
    $from = max(substr((string)$g['starts_at'], 0, 10), $rangeFrom);
    $to   = min(substr((string)($g['ends_at'] ?: $g['starts_at']), 0, 10), $rangeTo);
    $d = new DateTime($from); $end = new DateTime($to);
    while ($d <= $end) {
        $byDay[$d->format('Y-m-d')][] = ['type' => 'gettogether', 'title' => $g['title'], 'url' => 'gettogether.php?id=' . (int)$g['id']]
            + $timeinfo($g['starts_at'], $g['ends_at']);
        $d->modify('+1 day');
    }
}
// Mitglieds-Geburtstage (wiederkehrend, MM-TT; anklickbar zum Profil) – per persönlichem Toggle ausblendbar
if (!$hideBirthdays) {
    $bmap = []; // 'MM-TT' => [ ['name','id'], … ]
    foreach (members_all() as $bm) {
        $bd = trim((string)($bm['birthday'] ?? ''));
        if (preg_match('/^\d{2}-\d{2}$/', $bd)) $bmap[$bd][] = ['name' => short_name((string)$bm['name']), 'id' => (int)$bm['id']];
    }
    if ($bmap) {
        $d = new DateTime($rangeFrom); $end = new DateTime($rangeTo);
        while ($d <= $end) {
            foreach ($bmap[$d->format('m-d')] ?? [] as $b) {
                $byDay[$d->format('Y-m-d')][] = ['type' => 'birthday', 'title' => $b['name'],
                    'url' => 'profil.php?id=' . $b['id'], 'tip' => 'Geburtstag',
                    'allday' => true, 'startMin' => null, 'endMin' => null];
            }
            $d->modify('+1 day');
        }
    }
}

// Kommende Events (Bedarf)
$upcoming = db()->query(
    "SELECT e.*,
            (SELECT MIN(starts_at) FROM event_slots WHERE event_id = e.id) AS first_slot,
            (SELECT MAX(starts_at) FROM event_slots WHERE event_id = e.id) AS last_slot
     FROM events e
     WHERE e.closed = 0 AND " . visible_drafts_sql('e.', true) . "
       AND (SELECT MAX(date(starts_at)) FROM event_slots WHERE event_id = e.id) >= date('now','localtime')
     ORDER BY first_slot"
)->fetchAll();

page_header('Kalender');
// Anzeige-Helfer für Kalender-Einträge
$icons = ['meeting' => 'ti-gavel', 'stupa' => 'ti-building-bank', 'shift' => 'ti-clipboard-check', 'todo' => 'ti-pencil',
          'event' => 'ti-calendar-event', 'absence' => 'ti-plane-departure', 'absence-muted' => 'ti-plane-departure',
          'gettogether' => 'ti-confetti', 'termin' => 'ti-calendar-pin', 'birthday' => 'ti-cake'];
$renderDay = function (array $items) use ($icons) {
    foreach ($items as $item) {
        $c = 'ev ev-' . $item['type'] . (!empty($item['important']) ? ' ev-important' : '');
        $ic = '<i class="ti ' . (!empty($item['important']) ? 'ti-alert-triangle-filled' : ($icons[$item['type']] ?? 'ti-point')) . '"></i> ';
        $tt = $item['title'] . (trim((string)($item['tip'] ?? '')) !== '' ? ' — ' . $item['tip'] : '');
        if ($item['url']) {
            echo '<a class="' . $c . '" href="' . h($item['url']) . '" title="' . h($tt) . '">' . $ic . h($item['title']) . '</a>';
        } else {
            echo '<span class="' . $c . '" title="' . h($tt) . '">' . $ic . h($item['title']) . '</span>';
        }
    }
};
// Titel + Umschalt-/Navigations-Links
$monthForToggle = $view === 'month' ? $cur->format('Y-m') : substr($rangeFrom, 0, 7);
$dForToggle = $view === 'week' ? $rangeFrom : $today;
if ($view === 'week') {
    $ws = dt($rangeFrom); $we = dt($rangeTo);
    $calTitle = $ws->format('j.') . ($ws->format('n') !== $we->format('n') ? ' ' . mb_substr(MONTHS[(int)$ws->format('n')], 0, 3) : '')
        . ' – ' . $we->format('j') . '. ' . MONTHS[(int)$we->format('n')] . ' ' . $we->format('Y');
} else {
    $calTitle = MONTHS[(int)$cur->format('n')] . ' ' . $cur->format('Y');
}
?>
<div class="cal-head">
  <h1><i class="ti ti-calendar-month" style="color:var(--petrol)"></i> <?= h($calTitle) ?></h1>
  <div class="btn-row">
    <span class="seg">
      <a href="?view=month&ym=<?= $monthForToggle ?>" class="<?= $view === 'month' ? 'active' : '' ?>"><i class="ti ti-layout-grid"></i> Monat</a>
      <a href="?view=week&d=<?= $dForToggle ?>" class="<?= $view === 'week' ? 'active' : '' ?>"><i class="ti ti-layout-rows"></i> Woche</a>
    </span>
    <span class="cal-nav"><!-- ‹ Heute › als unteilbare Gruppe: bricht nur geschlossen um, nie ein einzelner Pfeil -->
    <?php if ($view === 'week'): ?>
      <a class="btn secondary small" href="?view=week&d=<?= $prevD ?>" title="Vorwoche"><i class="ti ti-chevron-left"></i></a>
      <a class="btn secondary small" href="?view=week&d=<?= $today ?>"><i class="ti ti-calendar-due"></i> Heute</a>
      <a class="btn secondary small" href="?view=week&d=<?= $nextD ?>" title="Folgewoche"><i class="ti ti-chevron-right"></i></a>
    <?php else: ?>
      <a class="btn secondary small" href="?view=month&ym=<?= $prev ?>" title="Vormonat"><i class="ti ti-chevron-left"></i></a>
      <a class="btn secondary small" href="index.php"><i class="ti ti-calendar-due"></i> Heute</a>
      <a class="btn secondary small" href="?view=month&ym=<?= $next ?>" title="Folgemonat"><i class="ti ti-chevron-right"></i></a>
    <?php endif; ?>
    </span>
  </div>
</div>

<?php if ($view === 'week'):
    // Sichtbares Zeitfenster aus den Terminen ableiten (Standard 10–22 Uhr)
    $PXH = 48; $H0 = 10; $H1 = 22;
    $d = clone $weekStart;
    for ($i = 0; $i < 7; $i++) {
        foreach ($byDay[$d->format('Y-m-d')] ?? [] as $it) {
            if (empty($it['allday']) && $it['startMin'] !== null) {
                $H0 = min($H0, intdiv($it['startMin'], 60));
                $H1 = max($H1, (int)ceil($it['endMin'] / 60));
            }
        }
        $d->modify('+1 day');
    }
    $H0 = max(0, $H0); $H1 = min(24, $H1); if ($H1 <= $H0) $H1 = $H0 + 1;
    $bodyH = ($H1 - $H0) * $PXH;
    // ganztägige Einträge je Wochentag sammeln
    $allday = []; $hasAllday = false; $d = clone $weekStart;
    for ($i = 0; $i < 7; $i++) {
        $allday[$i] = [];
        foreach ($byDay[$d->format('Y-m-d')] ?? [] as $it) {
            if (!empty($it['allday'])) { $allday[$i][] = $it; $hasAllday = true; }
        }
        $d->modify('+1 day');
    }
?>
<div class="weekgrid-wrap">
  <div class="weekgrid">
    <div class="wg-head">
      <div class="wg-corner"></div>
      <?php $d = clone $weekStart; for ($i = 0; $i < 7; $i++): $key = $d->format('Y-m-d'); ?>
        <div class="wg-dayhead<?= $key === $today ? ' today' : '' ?>"><?= WD[$i] ?> <span class="wg-d"><?= $d->format('j.n.') ?></span></div>
      <?php $d->modify('+1 day'); endfor; ?>
    </div>
    <?php if ($hasAllday): ?>
    <div class="wg-allday">
      <div class="wg-axislabel">ganztägig</div>
      <?php $d = clone $weekStart; for ($i = 0; $i < 7; $i++): $key = $d->format('Y-m-d'); ?>
        <div class="wg-allday-col<?= $key === $today ? ' today' : '' ?>"><?php $renderDay($allday[$i]); ?></div>
      <?php $d->modify('+1 day'); endfor; ?>
    </div>
    <?php endif; ?>
    <div class="wg-body" style="height:<?= $bodyH ?>px">
      <div class="wg-axis">
        <?php for ($hh = $H0; $hh <= $H1; $hh++): ?>
          <div class="wg-hour" style="top:<?= ($hh - $H0) * $PXH ?>px"><?= $hh ?>:00</div>
        <?php endfor; ?>
      </div>
      <?php $d = clone $weekStart; for ($i = 0; $i < 7; $i++): $key = $d->format('Y-m-d'); ?>
        <div class="wg-daycol<?= $key === $today ? ' today' : '' ?>">
          <?php foreach ($byDay[$key] ?? [] as $it):
              if (!empty($it['allday']) || $it['startMin'] === null) continue;
              $top = round(($it['startMin'] - $H0 * 60) / 60 * $PXH);
              $hgt = round(max(($it['endMin'] - $it['startMin']) / 60 * $PXH - 2, 18));
              $imp  = !empty($it['important']);
              $ic  = '<i class="ti ' . ($imp ? 'ti-alert-triangle-filled' : ($icons[$it['type']] ?? 'ti-point')) . '"></i> ';
              $tag = $it['url'] ? 'a' : 'span';
              $href = $it['url'] ? ' href="' . h($it['url']) . '"' : '';
          ?>
            <<?= $tag ?> class="wg-ev ev-<?= $it['type'] ?><?= $imp ? ' ev-important' : '' ?>" style="top:<?= $top ?>px;height:<?= $hgt ?>px"<?= $href ?> title="<?= h($it['title'] . (trim((string)($it['tip'] ?? '')) !== '' ? ' — ' . $it['tip'] : '')) ?>"><?= $ic . h($it['title']) ?></<?= $tag ?>>
          <?php endforeach; ?>
        </div>
      <?php $d->modify('+1 day'); endfor; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="calendar-wrap">
<table class="calendar">
  <thead><tr>
    <?php foreach (WD as $w): ?><th><?= $w ?></th><?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php
  $first = clone $monthStart;
  $gridStart = (clone $first)->modify('-' . (((int)$first->format('N')) - 1) . ' days');
  $d = clone $gridStart;
  for ($week = 0; $week < 6; $week++):
      if ($week === 5 && $d->format('m') !== $cur->format('m')) break;
      echo '<tr>';
      for ($i = 0; $i < 7; $i++):
          $key = $d->format('Y-m-d');
          $other = $d->format('m') !== $cur->format('m');
          $cls = $other ? ' class="other"' : ($key === $today ? ' class="today"' : '');
          echo '<td' . $cls . '><span class="daynum">' . $d->format('j') . '</span>';
          $renderDay($byDay[$key] ?? []);
          echo '</td>';
          $d->modify('+1 day');
      endfor;
      echo '</tr>';
  endfor;
  ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<div class="cal-legend small">
  <span class="ev ev-shift"><i class="ti ti-clipboard-check"></i> Mein Einsatz</span>
  <span class="ev ev-meeting"><i class="ti ti-gavel"></i> Sitzung</span>
  <span class="ev ev-stupa"><i class="ti ti-building-bank"></i> StuPa</span>
  <span class="ev ev-termin"><i class="ti ti-calendar-pin"></i> Termin</span>
  <span class="ev ev-todo"><i class="ti ti-pencil"></i> Noch abstimmen</span>
  <span class="ev ev-event"><i class="ti ti-calendar-event"></i> Event</span>
  <span class="ev ev-gettogether"><i class="ti ti-confetti"></i> Get-Together</span>
  <?php if (!$hideBirthdays): ?><span class="ev ev-birthday"><i class="ti ti-cake"></i> Geburtstag</span><?php endif; ?>
  <span class="ev ev-important"><i class="ti ti-alert-triangle-filled"></i> Extrem wichtig</span>
  <span class="ev ev-absence"><i class="ti ti-plane-departure"></i> Abwesenheit</span>
</div>

<?php if ($me):
    $retQS = $view === 'week' ? 'view=week&d=' . $rangeFrom : 'ym=' . substr($rangeFrom, 0, 7);
    $myTermine = termine_mine_upcoming($meId);
    $pickMembers = array_values(array_filter(members_all(), fn($x) => (int)$x['id'] !== $meId));
?>
<div id="kal-optionen" class="kal-actions">
  <div class="kal-hide">
    <span class="kal-lbl"><i class="ti ti-eye-off"></i> Ausblenden:</span>
    <div class="kal-toggles" role="group" aria-label="StuPa und Geburtstage aus- oder einblenden">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_stupa"><input type="hidden" name="ret" value="<?= h($retQS) ?>">
        <button class="kal-toggle<?= $hideStupa ? ' is-off' : '' ?>" type="submit" aria-pressed="<?= $hideStupa ? 'true' : 'false' ?>" title="<?= $hideStupa ? 'StuPa-Sitzungen wieder einblenden' : 'StuPa-Sitzungen ausblenden' ?>"><i class="ti ti-building-bank"></i> <span class="kt-lbl">StuPa</span></button>
      </form>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_birthdays"><input type="hidden" name="ret" value="<?= h($retQS) ?>">
        <button class="kal-toggle<?= $hideBirthdays ? ' is-off' : '' ?>" type="submit" aria-pressed="<?= $hideBirthdays ? 'true' : 'false' ?>" title="<?= $hideBirthdays ? 'Geburtstage wieder einblenden' : 'Geburtstage ausblenden' ?>"><i class="ti ti-cake"></i> <span class="kt-lbl">Geburtstage</span></button>
      </form>
    </div>
  </div>
  <div class="kal-create" role="group" aria-label="Neu anlegen">
    <span class="kal-lbl"><i class="ti ti-plus"></i> Anlegen:</span>
    <button type="button" class="btn secondary" onclick="astaKalPanel('panel-termin')" title="Freien Termin anlegen"><i class="ti ti-calendar-pin"></i> Termin</button>
    <button type="button" class="btn secondary" onclick="astaKalPanel('panel-poll')" title="Terminabstimmung starten"><i class="ti ti-calendar-search"></i> Terminfinder</button>
  </div>
</div>

<div id="panel-termin" class="card" hidden>
  <div class="section-title" style="margin-top:0;font-size:1.05rem"><i class="ti ti-calendar-pin"></i> Termin anlegen</div>
  <p class="small muted" style="margin-top:0">Ein freier Kalender-Termin – nur für dich, für ausgewählte Personen oder für alle. Erscheint bei den betreffenden Leuten im Kalender und im Kalender-Abo.</p>
  <form method="post" id="termin-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_termin"><input type="hidden" name="ret" value="<?= h($retQS) ?>">
    <label for="t_title">Titel</label>
    <input type="text" name="title" id="t_title" required placeholder="z. B. Redaktionstreffen, Geburtstag, Frist …">
    <div class="field-row">
      <div>
        <label for="t_start">Start</label>
        <input type="text" class="fp-datetime" name="starts_at" id="t_start" placeholder="Datum &amp; Uhrzeit">
      </div>
      <div>
        <label for="t_end">Ende (optional)</label>
        <input type="text" class="fp-datetime" name="ends_at" id="t_end" placeholder="Datum &amp; Uhrzeit">
      </div>
    </div>
    <label class="inline" style="margin-top:.5rem"><input type="checkbox" name="all_day" id="t_allday"> <strong>Ganztägig</strong> (Uhrzeit egal; kann über mehrere Tage gehen)</label>
    <label for="t_loc" style="margin-top:.5rem">Ort (optional)</label>
    <input type="text" name="location" id="t_loc" placeholder="z. B. AStA-Büro, online, …">
    <label for="t_desc">Notiz (optional)</label>
    <textarea name="description" id="t_desc" rows="2"></textarea>

    <label style="margin-top:.6rem">Für wen?</label>
    <div class="btn-row" role="radiogroup" style="gap:1rem;flex-wrap:wrap">
      <?php foreach (termin_audiences() as $av => $lbl): ?>
        <label class="inline"><input type="radio" name="audience" value="<?= h($av) ?>" <?= $av === 'self' ? 'checked' : '' ?> onchange="astaTerminAudience(this.value)"> <?= h($lbl) ?></label>
      <?php endforeach; ?>
    </div>
    <div id="t_members" hidden style="margin-top:.5rem;border:1px solid var(--line);border-radius:10px;padding:.6rem;max-height:230px;overflow:auto">
      <p class="small muted" style="margin:0 0 .4rem">Wer soll den Termin sehen? (Du selbst siehst ihn ohnehin.)</p>
      <div class="member-pick">
        <?php foreach ($pickMembers as $pm): ?>
          <label class="inline mp-item"><input type="checkbox" name="members[]" value="<?= (int)$pm['id'] ?>"> <?= h($pm['name']) ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit"><i class="ti ti-calendar-plus"></i> Termin anlegen</button></div>
  </form>
  <?php if ($myTermine): ?>
  <div class="section-title" style="font-size:1.05rem;border-top:1px solid var(--line);padding-top:.8rem"><i class="ti ti-list"></i> Meine kommenden Termine</div>
  <table class="list"><tbody>
    <?php foreach ($myTermine as $t): ?>
      <tr>
        <td>
          <strong><?= h($t['title']) ?></strong>
          <span class="badge badge-event"><?= h(termin_audience_label($t)) ?></span>
          <br><span class="small muted"><i class="ti ti-calendar"></i> <?= h(!empty($t['all_day']) ? fmt_date(substr((string)$t['starts_at'],0,10)) . (($t['ends_at'] ?? '') ? ' – ' . fmt_date(substr((string)$t['ends_at'],0,10)) : '') . ' · ganztägig' : fmt_slot($t['starts_at'], $t['ends_at'])) ?><?php if (trim((string)$t['location']) !== ''): ?> · <?= h($t['location']) ?><?php endif; ?></span>
        </td>
        <td style="text-align:right;vertical-align:top">
          <form method="post" data-confirm="Diesen Termin löschen?" data-confirm-danger data-confirm-ok="Löschen">
            <?= csrf_field() ?><input type="hidden" name="action" value="del_termin"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="ret" value="<?= h($retQS) ?>">
            <button class="btn danger small" type="submit" title="Termin löschen" aria-label="Termin löschen"><i class="ti ti-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>

<div id="panel-poll" class="card" hidden>
  <div class="section-title" style="margin-top:0;font-size:1.05rem"><i class="ti ti-calendar-search"></i> Terminfinder starten</div>
  <p class="small muted" style="margin-top:0">Schlage mehrere Termine vor – z. B. für eine außerordentliche Sitzung. <strong>Alle</strong> werden im Dashboard zum Abstimmen aufgefordert; am Ende siehst du, wann die meisten können. Der Terminfinder legt selbst <strong>keine</strong> Sitzung an.</p>
  <form method="post" id="poll-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_poll"><input type="hidden" name="ret" value="<?= h($retQS) ?>">
    <label for="p_title">Worum geht's?</label>
    <input type="text" name="title" id="p_title" placeholder="z. B. Außerordentliche Sitzung – Terminfindung">
    <label for="p_loc">Ort (optional)</label>
    <input type="text" name="location" id="p_loc" placeholder="z. B. CIII 248, online …">
    <label for="p_desc">Notiz (optional)</label>
    <textarea name="description" id="p_desc" rows="2"></textarea>
    <label for="p_deadline" style="margin-top:.6rem">Abstimmen bis (optional)</label>
    <input type="text" class="fp-datetime" name="deadline" id="p_deadline" placeholder="Datum &amp; Uhrzeit – danach ist die Abstimmung zu">
    <p class="small muted" style="margin:.25rem 0 0">Bis dahin können alle ihre Verfügbarkeit noch <strong>ändern</strong>. Nach Ablauf ist nur noch das Ergebnis sichtbar. Ohne Angabe bleibt die Abstimmung offen, bis du sie schließt.</p>
    <label style="margin-top:.6rem">Zur Auswahl stehende Termine</label>
    <div id="poll-opts">
      <div class="poll-optrow"><input type="text" class="fp-datetime" name="opt[]" placeholder="Datum &amp; Uhrzeit"></div>
      <div class="poll-optrow"><input type="text" class="fp-datetime" name="opt[]" placeholder="Datum &amp; Uhrzeit"></div>
      <div class="poll-optrow"><input type="text" class="fp-datetime" name="opt[]" placeholder="Datum &amp; Uhrzeit"></div>
    </div>
    <div class="btn-row" style="margin-top:.5rem">
      <button type="button" class="btn small secondary" onclick="astaPollAddOpt()"><i class="ti ti-plus"></i> Weiterer Vorschlag</button>
    </div>
    <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit"><i class="ti ti-calendar-search"></i> Terminfinder starten</button></div>
  </form>
</div>
<script>
function astaTerminAudience(v){var m=document.getElementById('t_members');if(m)m.hidden=(v!=='members');}
function astaKalPanel(pid){var p=document.getElementById(pid);if(!p)return;var show=p.hidden;['panel-termin','panel-poll'].forEach(function(x){var el=document.getElementById(x);if(el)el.hidden=true;});p.hidden=!show;if(show)p.scrollIntoView({behavior:'smooth',block:'nearest'});}
function astaPollAddOpt(){var box=document.getElementById('poll-opts');if(!box)return;var row=document.createElement('div');row.className='poll-optrow';var inp=document.createElement('input');inp.type='text';inp.className='fp-datetime';inp.name='opt[]';inp.placeholder='Datum & Uhrzeit';row.appendChild(inp);box.appendChild(row);if(window.astaInitFlatpickr)window.astaInitFlatpickr(inp);inp.focus();}
</script>
<?php endif; ?>

<div class="section-title"><i class="ti ti-confetti"></i> Aktuelle Events – Helfer gesucht</div>
<?php if (!$upcoming): ?>
  <div class="card"><p class="empty"><i class="ti ti-calendar-off"></i> Zurzeit keine offenen Events. <?php if (can_create_events()): ?><a href="admin/events.php">Event anlegen ›</a><?php endif; ?></p></div>
<?php else: ?>
  <div class="grid">
  <?php foreach ($upcoming as $e):
      $slotRows = event_slots((int)$e['id']);
      $hs = event_help_stats((int)$e['id']);
  ?>
    <div class="card hoverable stretch-card">
      <div style="display:flex;align-items:flex-start;gap:.5rem">
        <h3 style="margin:.1rem 0;font-size:1.05rem"><a class="tl-stretch" href="event.php?id=<?= (int)$e['id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h($e['title']) ?></a></h3>
        <?php if (!empty($e['deadline'])): $dd = days_until_d($e['deadline']);
          $pc = deadline_pill_class($dd); ?>
          <span class="pill <?= $pc ?>" style="margin-left:auto;white-space:nowrap">bis <?= fmt_date($e['deadline']) ?></span>
        <?php endif; ?>
      </div>
      <p class="small muted" style="margin:.2rem 0">
        <i class="ti ti-calendar"></i> <?= $e['first_slot'] ? fmt_slot($e['first_slot'], null) : 'noch keine Termine' ?>
        · <?= count($slotRows) ?> Termin(e)
      </p>
      <?php if ($hs['needed'] > 0): $pct = min(100, (int)round($hs['got'] / $hs['needed'] * 100)); $bc = $hs['got'] >= $hs['needed'] ? '' : ($hs['got'] > 0 ? 'warn' : 'bad'); ?>
        <div class="progress-row">
          <div class="progress <?= $bc ?>"><span style="width:<?= $pct ?>%"></span></div>
          <span class="small muted"><?= $hs['got'] ?>/<?= $hs['needed'] ?> besetzte Schichten</span>
        </div>
      <?php else: ?>
        <span class="pill pill-ok"><?= (int)$hs['yes'] ?> Zusagen</span>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:.7rem">
        <a class="btn small" href="event.php?id=<?= (int)$e['id'] ?>"><i class="ti ti-pencil"></i> Eintragen / ansehen</a>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($me):
    $allPolls = date_polls_all();
    $openPolls = array_values(array_filter($allPolls, fn($p) => empty($p['closed'])));
    $pastPolls = array_values(array_filter($allPolls, fn($p) => !empty($p['closed'])));
    $activeN = active_member_count();
    // Kompakte Zusammenfassung je Abstimmung (führendes Datum + Stimmenzahl)
    $pollSummary = function (array $p) use ($meId, $activeN) {
        $pid = (int)$p['id'];
        $res = date_poll_results($pid);
        $voters = count(date_poll_voter_ids($pid));
        $lead = $res && (int)$res[0]['yes'] > 0 ? $res[0] : null;
        return ['voters' => $voters, 'soll' => max($voters, $activeN),
                'voted' => date_poll_has_voted($pid, $meId),
                'lead' => $lead ? date_poll_option_label($lead['option']) : null,
                'leadYes' => $lead ? (int)$lead['yes'] : 0];
    };
?>
<div class="section-title" id="terminabstimmungen"><i class="ti ti-calendar-search"></i> Terminabstimmungen</div>
<?php if (!$openPolls && !$pastPolls): ?>
  <div class="card"><p class="empty"><i class="ti ti-calendar-off"></i> Aktuell keine Terminabstimmungen. Starte oben eine über <strong>Terminfinder</strong>.</p></div>
<?php else: ?>
  <?php if ($openPolls): ?>
    <div class="grid">
    <?php foreach ($openPolls as $p): $s = $pollSummary($p); ?>
      <div class="card hoverable stretch-card">
        <div style="display:flex;align-items:flex-start;gap:.5rem">
          <h3 style="margin:.1rem 0;font-size:1.05rem"><a class="tl-stretch" href="terminfinder.php?id=<?= (int)$p['id'] ?>" style="text-decoration:none;color:var(--ink)"><?= h($p['title']) ?></a></h3>
          <?php if ($s['voted']): ?><span class="pill pill-ok" style="margin-left:auto;white-space:nowrap"><i class="ti ti-check"></i> abgestimmt</span>
          <?php else: ?><span class="pill pill-bad" style="margin-left:auto;white-space:nowrap"><i class="ti ti-alert-triangle"></i> bitte abstimmen</span><?php endif; ?>
        </div>
        <p class="small muted" style="margin:.2rem 0"><i class="ti ti-users"></i> <?= $s['voters'] ?>/<?= $s['soll'] ?> abgestimmt<?php if ($s['lead']): ?> · <i class="ti ti-crown" style="color:#d4a017"></i> vorn: <?= h($s['lead']) ?> (<?= $s['leadYes'] ?>×)<?php endif; ?></p>
        <div class="btn-row" style="margin-top:.7rem">
          <a class="btn small" href="terminfinder.php?id=<?= (int)$p['id'] ?>"><i class="ti ti-checkbox"></i> <?= $s['voted'] ? 'Ergebnis / ändern' : 'Jetzt abstimmen' ?></a>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($pastPolls): ?>
    <details class="past-events">
      <summary><i class="ti ti-history"></i> Abgeschlossene Terminabstimmungen (<?= count($pastPolls) ?>)</summary>
      <div class="card" style="margin-top:1rem"><table class="list"><tbody>
        <?php foreach ($pastPolls as $p): $s = $pollSummary($p); ?>
          <tr>
            <td>
              <a href="terminfinder.php?id=<?= (int)$p['id'] ?>"><strong><?= h($p['title']) ?></strong></a>
              <br><span class="small muted"><?php if ($s['lead']): ?><i class="ti ti-crown" style="color:#d4a017"></i> Ergebnis: <?= h($s['lead']) ?> (<?= $s['leadYes'] ?>× Ja)<?php else: ?>keine Zusagen<?php endif; ?> · <?= $s['voters'] ?> Stimmen</span>
            </td>
            <td style="text-align:right;vertical-align:top"><a class="btn secondary small" href="terminfinder.php?id=<?= (int)$p['id'] ?>">Ansehen</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </details>
  <?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<div id="ical-restore-bar" class="ical-restore-bar" hidden>
  <span class="small"><i class="ti ti-calendar-plus" style="color:var(--petrol)"></i> Die Kachel „Mein Kalender-Abo" ist im Dashboard ausgeblendet.</span>
  <button type="button" id="ical-restore" class="btn secondary small"><i class="ti ti-eye"></i> Wieder einblenden</button>
</div>

<script>
(function(){
  // Ist „Mein Kalender-Abo" im Dashboard ausgeblendet? Dann hier den Einblenden-Button zeigen.
  var bar = document.getElementById('ical-restore-bar'), btn = document.getElementById('ical-restore');
  if (!bar) return;
  var hidden = false; try { hidden = localStorage.getItem('astaHideIcalCard') === '1'; } catch(e){}
  bar.hidden = !hidden;
  if (btn) btn.addEventListener('click', function(){
    try { localStorage.removeItem('astaHideIcalCard'); } catch(e){}
    location.href = 'dashboard.php';
  });
})();
</script>
<?php
page_footer();
