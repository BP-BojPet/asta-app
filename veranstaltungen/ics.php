<?php
/**
 * Eine einzelne Veranstaltung als Kalenderdatei.
 *
 * Bewusst KEIN Abo-Kalender: Der AStA hat sich für „einzelne Termine speichern" entschieden.
 * Die Bausteine hier (Zeitzone, Escaping, Faltung) sind aber dieselben, die ein Abo bräuchte –
 * falls es später doch eines geben soll, ist das ein Nachbau, kein neues Fundament.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard()) exit;

$id = (int)wl_param($_GET['id'] ?? '0');
$it = wl_item($id);
if (!$it || (string)$it['status'] !== 'live' || ($it['kind'] ?? 'event') !== 'event') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Termin nicht gefunden.\n");
}

/** Text für den Kalender aufbereiten: Sonderzeichen maskieren, Umbrüche als \n. */
function wl_ics_text(string $v): string
{
    $v = str_replace(["\r\n", "\r"], "\n", trim($v));
    $v = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $v);
    return str_replace("\n", '\n', $v);
}

/**
 * Zeilen über 75 Oktette müssen umgebrochen werden – sonst weisen manche Kalender die Datei ab.
 *
 * Gezählt wird in BYTES (so steht es in der Norm), geschnitten aber an ZEICHENGRENZEN: Stumpf
 * nach 73 Bytes zu schneiden zerlegt jeden Umlaut, der auf der Grenze liegt – bei deutschen
 * Veranstaltungstiteln der Normalfall – und ergibt ungültiges UTF-8.
 */
function wl_ics_fold(string $zeile): string
{
    if (strlen($zeile) <= 73) return $zeile;
    $out = '';
    $zeilenlaenge = 0;
    $grenze = 73;                                   // die Folgezeilen beginnen mit einem Leerzeichen
    foreach (mb_str_split($zeile, 1, 'UTF-8') as $z) {
        $b = strlen($z);
        if ($zeilenlaenge + $b > $grenze) {
            $out .= "\r\n ";
            $zeilenlaenge = 1;
            $grenze = 73;
        }
        $out .= $z;
        $zeilenlaenge += $b;
    }
    return $out;
}

$start = (string)$it['starts_at'];
$ende  = trim((string)$it['ends_at']);
// Ohne Endzeit rechnen wir zwei Stunden – ein Termin ohne Dauer erscheint in vielen Kalendern
// als Punkt und rutscht in der Tagesansicht nach oben weg.
if ($ende === '') $ende = date('Y-m-d H:i', (int)strtotime($start . ' +2 hours'));

$fmt = static fn (string $v): string => date('Ymd\THis', (int)strtotime($v));
$org = wl_org((int)($it['org_id'] ?? 0));
$base = wl_base_url();

$ort = trim((string)$it['ort']);
if (trim((string)$it['adresse']) !== '') $ort = trim($ort . ', ' . (string)$it['adresse']);

$beschr = trim((string)$it['text']);
if ($org) $beschr = trim($beschr . "\n\nVeranstalter: " . (string)$org['name']);
if ($base !== '') $beschr = trim($beschr . "\n" . $base . '/veranstaltungen/v.php?id=' . $id);

$zeilen = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//' . str_replace(['//', "\r", "\n"], ' ', wl_traeger_kurz()) . '//was.laeuft//DE',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VTIMEZONE',
    'TZID:Europe/Berlin',
    'BEGIN:STANDARD',
    'DTSTART:19701025T030000',
    'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
    'TZOFFSETFROM:+0200',
    'TZOFFSETTO:+0100',
    'TZNAME:CET',
    'END:STANDARD',
    'BEGIN:DAYLIGHT',
    'DTSTART:19700329T020000',
    'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
    'TZOFFSETFROM:+0100',
    'TZOFFSETTO:+0200',
    'TZNAME:CEST',
    'END:DAYLIGHT',
    'END:VTIMEZONE',
    'BEGIN:VEVENT',
    'UID:wl-' . $id . '@' . wl_uid_domain(),
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART;TZID=Europe/Berlin:' . $fmt($start),
    'DTEND;TZID=Europe/Berlin:' . $fmt($ende),
    'SUMMARY:' . wl_ics_text((string)$it['title']),
];
// Abgesagt: Wer die Datei trotzdem zieht (der Knopf ist dann schon weg), bekommt
// den Termin als offiziell abgesagt – Kalender stellen ihn durchgestrichen dar.
if ((int)($it['abgesagt'] ?? 0) === 1) $zeilen[] = 'STATUS:CANCELLED';
if ($ort !== '')    $zeilen[] = 'LOCATION:' . wl_ics_text($ort);
if ($beschr !== '') $zeilen[] = 'DESCRIPTION:' . wl_ics_text($beschr);
if ($base !== '')   $zeilen[] = 'URL:' . $base . '/veranstaltungen/v.php?id=' . $id;
$zeilen[] = 'END:VEVENT';
$zeilen[] = 'END:VCALENDAR';

$datei = wl_slug((string)$it['title']) . '.ics';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $datei . '"');
header('X-Content-Type-Options: nosniff');
echo implode("\r\n", array_map('wl_ics_fold', $zeilen)) . "\r\n";
