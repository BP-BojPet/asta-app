<?php
// redeliste-protocol.php — Protokollant:in aus der Redeliste-Leitung setzen.
//
// Die Sitzungsleitung kann direkt in der Redeliste (zu Sitzungsbeginn) die:den
// Protokollant:in festlegen, falls das in der App noch nicht geschah. Authentifiziert
// wird per Capability wie der Leitungs-Link: Raum-Token (aus ?session=room-TOKEN) + geheimer
// Leitungs-Key müssen zur Sitzung passen (kein App-Login nötig).
//
// Wirkung: schreibt meetings.protocol_taker_id in der App-DB UND spiegelt die Auswahl in den
// Redelisten-Raum-State (Feld protocolTaker), damit die Wahl in der Liste sichtbar wird.
require __DIR__ . '/lib.php';
db();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jfail(int $code, string $msg): void { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail(405, 'method');

$session = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['session'] ?? ''));
$key     = (string)($_GET['key'] ?? '');
if (strpos($session, 'room-') !== 0 || $key === '') jfail(403, 'forbidden');
$token = substr($session, 5);

// Sitzung über Token authentifizieren; Leitungs-Key zeitkonstant prüfen.
$st = db()->prepare('SELECT * FROM meetings WHERE redeliste_token = ? LIMIT 1');
$st->execute([$token]);
$m = $st->fetch();
if (!$m || !hash_equals((string)($m['redeliste_key'] ?? ''), $key)) jfail(403, 'forbidden');

$raw = file_get_contents('php://input');
$p = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($p)) $p = [];

// Teilnehmer-ID ist 'm{memberId}' (Stammliste); '' bzw. nichts = entfernen.
$pid = (string)($p['id'] ?? '');
$memberId = preg_match('/^m(\d+)$/', $pid, $mm) ? (int)$mm[1] : 0;

$taker = null;
if ($memberId > 0) {
    $mr = db()->prepare('SELECT id, name FROM members WHERE id = ? AND active = 1');
    $mr->execute([$memberId]);
    $mem = $mr->fetch();
    if (!$mem) jfail(404, 'member');
    $taker = ['id' => 'm' . (int)$mem['id'], 'name' => short_name((string)$mem['name'])];
}

// App-DB: Protokollant setzen/entfernen (löst die Dashboard-Aufgabe der Person aus).
protocol_set_taker((int)$m['id'], $memberId ?: null);

// Redelisten-Raum-State spiegeln (atomar, Version bumpen → pollende Clients sehen es).
$out = null;
$rdb = redeliste_db();
if ($rdb) {
    try {
        $rdb->exec('BEGIN IMMEDIATE');
        $cur = $rdb->prepare('SELECT state, version FROM rooms WHERE session = ?');
        $cur->execute([$session]);
        $room = $cur->fetch(PDO::FETCH_ASSOC);
        if ($room) {
            $state = json_decode((string)$room['state'], true);
            if (!is_array($state)) $state = [];
            $state['protocolTaker'] = $taker;
            $nv  = (int)$room['version'] + 1;
            $enc = json_encode($state, JSON_UNESCAPED_UNICODE);
            $rdb->prepare('UPDATE rooms SET state = ?, version = ?, updated_at = ? WHERE session = ?')
                ->execute([$enc, $nv, time(), $session]);
            $rdb->exec('COMMIT');
            $out = ['v' => $nv, 'state' => $state];
        } else {
            $rdb->exec('ROLLBACK');
        }
    } catch (\Throwable $e) {
        try { $rdb->exec('ROLLBACK'); } catch (\Throwable $e2) {}
    }
}

echo json_encode($out ?? ['ok' => true, 'taker' => $taker], JSON_UNESCAPED_UNICODE);
