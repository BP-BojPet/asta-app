<?php
require __DIR__ . '/lib.php';
db();

// Persönlicher Feed: Zugriff nur über den geheimen Token des Mitglieds
$member = member_by_ical_token((string)($_GET['token'] ?? ''));
if (!$member) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Ungültiger oder fehlender Token. Den persönlichen Abo-Link findest du in deinem Dashboard.\n");
}
$meId = (int)$member['id'];

header('Content-Type: text/calendar; charset=utf-8');
// Dateiname aus der Domain des Trägers – aus „beispiel.de" wird „beispiel.ics".
$icsName = preg_replace('~\.[a-z]{2,}$~i', '', org_domain()) ?: 'kalender';
header('Content-Disposition: inline; filename="' . $icsName . '.ics"');
// Abo-freundlich: Clients sollen regelmäßig neu laden, nichts cachen
header('Cache-Control: no-cache, max-age=0, must-revalidate');
header('Pragma: no-cache');

function ics_escape(string $s): string
{
    return addcslashes(str_replace(["\r\n", "\n", "\r"], '\\n', $s), ",;\\");
}

function ics_dt(string $s, bool $dateOnly = false): string
{
    $d = dt($s);
    if (!$d) return '';
    return $dateOnly ? $d->format('Ymd') : $d->format('Ymd\THis');
}

$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//' . str_replace(['//', "\r", "\n"], ' ', org_name_kurz()) . '//' . APP_NAME . '//DE';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$lines[] = 'X-WR-CALNAME:' . ics_escape(org_name_kurz() . ' – ' . $member['name']);
$lines[] = 'X-WR-TIMEZONE:Europe/Berlin';
// Abonnierende Apps bitten, stündlich zu aktualisieren
$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
$lines[] = 'X-PUBLISHED-TTL:PT1H';

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Sitzungen (ausgefallene ausgeblendet; StuPa je nach persönlicher Einstellung)
$mSql = 'SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0' . (member_hides_stupa($member) ? " AND kind != 'stupa'" : '') . ' ORDER BY starts_at';
foreach (db()->query($mSql)->fetchAll() as $m) {
    // Sitzungen sind nie ganztägig: Sie beginnen zu einer Uhrzeit und laufen bis Tagesende
    // (meeting_ends_at) – so ist der Termin im Kalender nicht 0 Sekunden lang.
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:meeting-' . $m['id'] . '@' . $host;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    $lines[] = 'DTSTART;TZID=Europe/Berlin:' . ics_dt($m['starts_at']);
    $lines[] = 'DTEND;TZID=Europe/Berlin:' . ics_dt(meeting_ends_at($m));
    $lines[] = 'SUMMARY:' . ics_escape(meeting_label($m));
    if ($m['location']) $lines[] = 'LOCATION:' . ics_escape($m['location']);
    if ($m['description']) $lines[] = 'DESCRIPTION:' . ics_escape($m['description']);
    $lines[] = 'END:VEVENT';
}

// Freie Termine, die für dieses Mitglied sichtbar sind (für mich / ausgewählte Personen / alle)
foreach (termine_visible_upcoming($meId) as $t) {
    $allDay = !empty($t['all_day']);
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:termin-' . $t['id'] . '@' . $host;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if ($allDay) {
        // Ganztägig: DTEND ist exklusiv (Folgetag). Bei Zeitspanne bis zum Endtag +1.
        $endDay = substr((string)($t['ends_at'] ?: $t['starts_at']), 0, 10);
        $endEx = (new DateTime($endDay))->modify('+1 day')->format('Ymd');
        $lines[] = 'DTSTART;VALUE=DATE:' . ics_dt($t['starts_at'], true);
        $lines[] = 'DTEND;VALUE=DATE:' . $endEx;
    } else {
        $lines[] = 'DTSTART;TZID=Europe/Berlin:' . ics_dt($t['starts_at']);
        $end = $t['ends_at'] ?: day_end_for($t['starts_at']);
        $lines[] = 'DTEND;TZID=Europe/Berlin:' . ics_dt($end);
    }
    $lines[] = 'SUMMARY:' . ics_escape($t['title']);
    if ($t['location']) $lines[] = 'LOCATION:' . ics_escape($t['location']);
    if ($t['description']) $lines[] = 'DESCRIPTION:' . ics_escape($t['description']);
    $lines[] = 'END:VEVENT';
}

// Event-Slots: nur die Schichten, für die das Mitglied tatsächlich eingeteilt ist
$slotStmt = db()->prepare(
    "SELECT s.*, e.title AS event_title, e.description AS event_desc
     FROM event_slots s
     JOIN events e ON e.id = s.event_id
     JOIN assignments a ON a.slot_id = s.id AND a.member_id = :me
     WHERE e.plan_locked = 1
     ORDER BY s.starts_at"
);
$slotStmt->execute([':me' => $meId]);
$slots = $slotStmt->fetchAll();
foreach ($slots as $s) {
    $start = dt($s['starts_at']);
    $allDay = $start && $start->format('H:i') === '00:00' && empty($s['ends_at']);
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:slot-' . $s['id'] . '@' . $host;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if ($allDay) {
        $lines[] = 'DTSTART;VALUE=DATE:' . ics_dt($s['starts_at'], true);
    } else {
        $lines[] = 'DTSTART;TZID=Europe/Berlin:' . ics_dt($s['starts_at']);
        if ($s['ends_at']) $lines[] = 'DTEND;TZID=Europe/Berlin:' . ics_dt($s['ends_at']);
    }
    $title = $s['event_title'] . ($s['label'] ? ' (' . $s['label'] . ')' : '');
    $lines[] = 'SUMMARY:' . ics_escape('Einsatz: ' . $title);
    if (trim((string)$s['location']) !== '') $lines[] = 'LOCATION:' . ics_escape($s['location']);
    $desc = trim('Du bist für diese Schicht eingeteilt.' . ($s['event_desc'] ? "\n\n" . $s['event_desc'] : ''));
    $lines[] = 'DESCRIPTION:' . ics_escape($desc);
    $lines[] = 'END:VEVENT';
}

// AStA Get-Togethers (interne Spaß-Treffen) – abgesagte (Nein) bleiben aus dem persönlichen Abo
$gtRsvp = gettogether_rsvp_map($meId);
foreach (gettogethers_all() as $g) {
    if (($gtRsvp[(int)$g['id']] ?? '') === 'no') continue;
    $start = dt($g['starts_at']);
    $allDay = $start && $start->format('H:i') === '00:00' && empty($g['ends_at']);
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:gettogether-' . (int)$g['id'] . '@' . $host;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if ($allDay) {
        $lines[] = 'DTSTART;VALUE=DATE:' . ics_dt($g['starts_at'], true);
    } else {
        $lines[] = 'DTSTART;TZID=Europe/Berlin:' . ics_dt($g['starts_at']);
        $end = $g['ends_at'] ?: day_end_for($g['starts_at']);
        $lines[] = 'DTEND;TZID=Europe/Berlin:' . ics_dt($end);
    }
    $lines[] = 'SUMMARY:' . ics_escape('Get-Together: ' . $g['title']);
    if (trim((string)$g['location']) !== '') $lines[] = 'LOCATION:' . ics_escape($g['location']);
    if (trim((string)$g['description']) !== '') $lines[] = 'DESCRIPTION:' . ics_escape($g['description']);
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

// Zeilen auf 75 Oktette falten (RFC 5545)
$out = '';
foreach ($lines as $l) {
    while (strlen($l) > 75) {
        $out .= substr($l, 0, 75) . "\r\n ";
        $l = substr($l, 75);
    }
    $out .= $l . "\r\n";
}
echo $out;
