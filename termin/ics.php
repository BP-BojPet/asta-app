<?php
/**
 * Der festgelegte Termin als Kalenderdatei.
 *
 * Bewusst kein Abo-Feed, sondern eine einzelne Datei: Es gibt genau einen Termin, und der
 * ändert sich nach der Festlegung nicht mehr. Wer die Datei öffnet, hat den Termin im
 * Kalender – das ist der letzte Schritt, an dem die üblichen Werkzeuge aufhören.
 */
require __DIR__ . '/termin-lib.php';

// „Aus" heißt aus – auch hier. Der Abschalter nimmt bestehende Links vom Netz, und eine
// Kalenderdatei ist ein bestehender Link. Antwort als Klartext, nicht als HTML-Seite:
// Wer hier landet, ist ein Kalenderprogramm, kein Browser.
if (!tplan_service_on()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Der Terminplaner ist im Moment abgeschaltet.\n");
}

$poll = tplan_by_slug(tplan_param($_GET['t'] ?? ''));
$o = $poll && (int)$poll['final_option'] > 0 ? tplan_option_get((int)$poll['final_option']) : null;
if (!$poll || !$o || (int)$o['poll_id'] !== (int)$poll['id']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Für diese Terminumfrage steht noch kein Termin fest.\n");
}

$name = preg_replace('~[^a-z0-9\-]~', '', strtolower(strtr((string)$poll['title'],
    ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', ' ' => '-'])));
$name = trim((string)$name, '-');
if ($name === '') $name = 'termin';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . substr($name, 0, 60) . '.ics"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');
echo tplan_ics($poll, $o);
