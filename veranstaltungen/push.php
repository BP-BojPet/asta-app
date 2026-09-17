<?php
/**
 * Push-Endpoint von was.läuft – die JSON-Schnittstelle hinter „Erinnere mich" und den Abos.
 *
 * Identität ist die PUSH-ADRESSE des Browsers (endpoint): unerratbar, vom Push-Dienst vergeben,
 * nur dem Gerät selbst bekannt. Wer sie hat, IST das Gerät – deshalb braucht es kein Konto.
 *
 * CSRF: `sub` kommt BEWUSST ohne Prüfmarke aus – der Service Worker ruft es bei
 * `pushsubscriptionchange` von sich aus auf (der Push-Dienst hat die Adresse gewechselt),
 * und im Worker gibt es keine Sitzung zum Abfragen. Gefahr entsteht daraus keine: Gespeichert
 * wird nur, was der Aufrufer ohnehin selbst mitbringt (seine eigene Adresse samt Schlüsseln).
 * Alles Übrige verlangt die Marke aus `boot`.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$raus = static function (array $a, int $code = 200): never {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
};

if (wl_mode() === 'off') $raus(['ok' => false, 'msg' => 'Seite ist gerade zu.'], 503);
if (!push_available() || !wl_push_vapid()) $raus(['ok' => false, 'msg' => 'Push steht auf diesem Server nicht bereit.'], 503);

$was = wl_param($_REQUEST['was'] ?? '');

// Startpaket für das Skript: öffentlicher Schlüssel + Prüfmarke der Sitzung.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($was !== 'boot') $raus(['ok' => false], 400);
    $raus(['ok' => true, 'pub' => (string)(wl_push_vapid()['pub'] ?? ''), 'csrf' => wl_csrf_token()]);
}

if (!wl_rate_ok('push', 240)) $raus(['ok' => false, 'msg' => 'Zu viele Anfragen – kurz warten.'], 429);

$endpoint = wl_param($_POST['endpoint'] ?? '');
if ($endpoint === '') $raus(['ok' => false, 'msg' => 'Ohne Push-Adresse geht nichts.'], 400);

if ($was === 'sub') {
    $sid = wl_push_sub_save($endpoint, wl_param($_POST['p256dh'] ?? ''), wl_param($_POST['auth'] ?? ''));
    $raus(['ok' => $sid > 0]);
}

// Ab hier: Prüfmarke Pflicht.
$t = wl_param($_POST['csrf'] ?? '');
if ($t === '' || !hash_equals(wl_csrf_token(), $t)) $raus(['ok' => false, 'msg' => 'Bitte die Seite neu laden.'], 400);

$sub = wl_push_sub_by_endpoint($endpoint);
if (!$sub) $raus(['ok' => false, 'msg' => 'Gerät unbekannt – bitte neu anmelden.'], 404);
$sid = (int)$sub['id'];

switch ($was) {
    case 'state':
        $raus(['ok' => true] + wl_push_state($sid));

    case 'remind':
        $it = wl_item((int)wl_param($_POST['item'] ?? '0'));
        if (!$it || (string)$it['status'] !== 'live') $raus(['ok' => false, 'msg' => 'Beitrag nicht gefunden.'], 404);
        $r = wl_reminder_toggle($sid, $it, wl_param($_POST['an'] ?? '') === '1');
        $raus(['ok' => $r['ok'], 'remind_at' => $r['remind_at']]);

    case 'follow':
        $ok = wl_follow_toggle($sid, (int)wl_param($_POST['org'] ?? '0'),
            wl_param($_POST['cat'] ?? ''), wl_param($_POST['an'] ?? '') === '1');
        $raus(['ok' => $ok]);

    case 'weg':
        wl_push_sub_delete($sid);
        $raus(['ok' => true]);
}

$raus(['ok' => false], 400);
