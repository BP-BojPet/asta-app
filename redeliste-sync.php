<?php
// redeliste-sync.php — server-autoritativer Sync-Endpunkt für die Redeliste (v2)
//
// Aufbau:
//  • Der SERVER hält den autoritativen Zustand (SQLite, eine Zeile je Raum) und wendet
//    kleine, feldgenaue OPERATIONEN selbst an – die Leitung ist nicht mehr im kritischen Pfad.
//  • Teilnehmer-Ops (join/raise/lower) werden serverseitig angewandt → eine Meldung erscheint
//    auch dann, wenn der Leitungs-Tab gerade nicht pollt (Hintergrund-Throttling egal) und kann
//    nicht mehr „im RAM der Leitung" verloren gehen.
//  • Jede Op bumpt eine monotone VERSION. Gelesen wird per Cursor (?since=v) → 204, wenn nichts
//    Neues. Kein Full-Snapshot-Upload, kein Last-Write-Wins.
//
// Räume:  Sitzungsname "room-XXXX" → eigenes Geheimnis pro Raum (erster seed mit key beansprucht).
//         Andere Sitzungsnamen (z. B. "intern") → globaler $WRITE_KEY.
// Räume, die 18 Tage nicht angefasst wurden, werden gelegentlich aufgeräumt.

$WRITE_KEY = 'Sitzungsleitung';                 // Leitungs-Schlüssel der internen (Nicht-Raum-)Liste
$DIR = __DIR__ . '/redeliste-data';
$DBF = $DIR . '/redeliste.sqlite';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$session = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['session'] ?? 'default');
if ($session === '') $session = 'default';
$isRoom   = strpos($session, 'room-') === 0;
$provided = (string)($_GET['key'] ?? '');

function fail(int $code, string $msg): void { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

if (!is_dir($DIR)) @mkdir($DIR, 0775, true);
try {
    $db = new PDO('sqlite:' . $DBF);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL');   // bessere Nebenläufigkeit von Lesern/Schreibern
    $db->exec('PRAGMA busy_timeout = 4000');  // kurz auf einen Lock warten statt sofort zu scheitern
    $db->exec('CREATE TABLE IF NOT EXISTS rooms (
        session    TEXT PRIMARY KEY,
        key        TEXT,
        state      TEXT NOT NULL,
        version    INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL
    )');
} catch (Throwable $e) { fail(500, 'db'); }

// Gelegentlich alte Räume aufräumen (18 Tage unangetastet)
if (rand(1, 60) === 1) {
    try { $db->prepare('DELETE FROM rooms WHERE updated_at < ?')->execute([time() - 18 * 86400]); } catch (Throwable $e) {}
}

function roomRow(PDO $db, string $session): ?array {
    $st = $db->prepare('SELECT * FROM rooms WHERE session = ?');
    $st->execute([$session]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
// Gültiger Leitungs-Schlüssel der Session: Raum → gespeicherter Claim-Key; sonst globaler WRITE_KEY.
function leaderKey(bool $isRoom, ?array $row, string $WRITE_KEY): ?string {
    if (!$isRoom) return $WRITE_KEY;
    return $row ? ($row['key'] ?? null) : null;
}

$row = roomRow($db, $session);

// ===== Raum löschen (nur Leitung) =====
if (isset($_GET['delete'])) {
    $lk = leaderKey($isRoom, $row, $WRITE_KEY);
    if ($lk === null || $provided === '' || $provided !== $lk) fail(403, 'forbidden');
    $db->prepare('DELETE FROM rooms WHERE session = ?')->execute([$session]);
    echo '{"ok":true}'; exit;
}

// ===== Lesen (alle): Versions-Cursor =====
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$row) { echo '{"v":0,"state":null}'; exit; } // Raum (noch) nicht gestartet
    $v = (int)$row['version'];
    if (isset($_GET['since']) && $_GET['since'] !== '' && (int)$_GET['since'] >= $v) {
        http_response_code(204); exit;                 // unverändert → nichts senden
    }
    echo '{"v":' . $v . ',"state":' . $row['state'] . '}'; exit;
}

// ===== Schreiben: Operationen =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method');

$op  = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? ''));
$raw = file_get_contents('php://input');
if (strlen($raw) > 200000) fail(413, 'too large');
$payload = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($payload)) $payload = [];

$participantOps = ['join', 'raise', 'lower', 'protocol_pause'];
$leaderOps      = ['seed', 'end', 'reset', 'call', 'done', 'remove', 'remove_person', 'reorder', 'set_list', 'reset_counts', 'agenda'];
if (!in_array($op, $participantOps, true) && !in_array($op, $leaderOps, true)) fail(400, 'bad op');

$isLeaderOp = in_array($op, $leaderOps, true);
$lk = leaderKey($isRoom, $row, $WRITE_KEY);

// Server-autoritative Leitungs-Erkennung (für protocol_pause: Leitung darf beenden, egal wer pausiert hat).
// Bewusst NACH dem Dekodieren gesetzt → überschreibt ein evtl. vom Client mitgeschicktes _leader.
$payload['_leader'] = ($lk !== null && $provided !== '' && hash_equals((string)$lk, (string)$provided));

if ($isLeaderOp) {
    // seed darf einen noch freien Raum mit dem mitgegebenen Schlüssel beanspruchen (Capability).
    if ($op === 'seed' && !$row) {
        if ($isRoom) { if ($provided === '') fail(403, 'forbidden'); }
        else         { if ($WRITE_KEY === '' || $provided !== $WRITE_KEY) fail(403, 'forbidden'); }
        $newState = (isset($payload['state']) && is_array($payload['state'])) ? $payload['state'] : null;
        if ($newState === null) fail(400, 'no state');
        $enc = json_encode($newState, JSON_UNESCAPED_UNICODE);
        $db->prepare('INSERT INTO rooms(session, key, state, version, updated_at) VALUES(?,?,?,?,?)')
           ->execute([$session, $isRoom ? $provided : null, $enc, 1, time()]);
        echo '{"v":1,"state":' . $enc . '}'; exit;
    }
    if ($lk === null || $provided === '' || $provided !== $lk) fail(403, 'forbidden');
} else {
    if (!$row) fail(404, 'no room'); // Teilnehmer-Op nur in existierenden Räumen (kein Spam auf Zufalls-IDs)
}

// ===== Transaktion: laden → Op anwenden → version++ (atomar, serialisiert) =====
try {
    $db->exec('BEGIN IMMEDIATE');
    $cur = roomRow($db, $session);
    if (!$cur) { $db->exec('ROLLBACK'); fail(404, 'no room'); }
    $state = json_decode($cur['state'], true);
    if (!is_array($state)) $state = [];
    [$state, $changed] = apply_op($state, $op, $payload);
    if ($changed) {
        $nv  = (int)$cur['version'] + 1;
        $enc = json_encode($state, JSON_UNESCAPED_UNICODE);
        $db->prepare('UPDATE rooms SET state = ?, version = ?, updated_at = ? WHERE session = ?')
           ->execute([$enc, $nv, time(), $session]);
        $db->exec('COMMIT');
        echo '{"v":' . $nv . ',"state":' . $enc . '}';
    } else {
        $db->exec('COMMIT');
        echo '{"v":' . (int)$cur['version'] . ',"state":' . $cur['state'] . '}';
    }
} catch (Throwable $e) {
    try { $db->exec('ROLLBACK'); } catch (Throwable $e2) {}
    fail(500, 'tx');
}
exit;

// ===================== Op-Logik (feldgenaue Mutationen) =====================

/** Index eines Teilnehmers in der participants-Liste (oder -1). */
function part_idx(array $list, string $id): int {
    foreach ($list as $i => $x) if (($x['id'] ?? null) === $id) return $i;
    return -1;
}

/**
 * Wendet eine Operation feldgenau auf den Zustand an. Gibt [neuerZustand, bool veraendert] zurück.
 * Bewusst KEINE Aufruf-/Quotierungs-Logik – die rechnet der Client beim Rendern (predictOrder).
 */
function apply_op(array $s, string $op, array $p): array {
    $s['participants'] = $s['participants'] ?? [];
    $s['queue'] = is_array($s['queue'] ?? null) ? $s['queue'] : [];
    $s['queue']['flinta'] = $s['queue']['flinta'] ?? [];
    $s['queue']['offen']  = $s['queue']['offen']  ?? [];
    $s['queue']['go']        = $s['queue']['go']        ?? []; // GO-Anträge (rot), flach FIFO, höchste Priorität
    $s['queue']['nachfrage'] = $s['queue']['nachfrage'] ?? []; // Direkte Nachfragen (orange), flach FIFO
    $allLists = ['flinta', 'offen', 'go', 'nachfrage'];
    $id = isset($p['id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$p['id']) : '';
    $listOf = fn($v) => ($v === 'flinta') ? 'flinta' : 'offen';

    switch ($op) {
        case 'seed': case 'end': case 'reset':            // voller Zustands-Ersatz (Sitzungsgrenzen)
            $ns = (isset($p['state']) && is_array($p['state'])) ? $p['state'] : null;
            return $ns !== null ? [$ns, true] : [$s, false];

        case 'done':
            $s['nowSpeaking'] = null; return [$s, true];

        case 'reset_counts':
            foreach ($s['participants'] as &$pp) $pp['count'] = 0;
            unset($pp);
            return [$s, true];

        case 'remove_person':
            if ($id === '') return [$s, false];
            $i = part_idx($s['participants'], $id);
            if ($i >= 0) array_splice($s['participants'], $i, 1);
            foreach ($allLists as $k) {
                $idx = array_search($id, $s['queue'][$k], true);
                if ($idx !== false) array_splice($s['queue'][$k], $idx, 1);
            }
            if (isset($s['nowSpeaking']['id']) && $s['nowSpeaking']['id'] === $id) $s['nowSpeaking'] = null;
            return [$s, true];

        case 'join':
            if ($id === '') return [$s, false];
            $list = $listOf($p['list'] ?? '');
            $status = (isset($p['status']) && trim((string)$p['status']) !== '') ? mb_substr((string)$p['status'], 0, 20) : '';
            $self     = !empty($p['self']);    // Selbst-Beitritt übers eigene Gerät → online (Krone)
            $isMember = !empty($p['member']);  // AStA-Mitglied (vs. externer Gast)
            $i = part_idx($s['participants'], $id);
            if ($i >= 0) {
                // Vorhandener (z. B. geseedetes Mitglied oder von der Leitung hinzugefügt) → Felder aktualisieren.
                if (isset($p['name']) && $p['name'] !== '') $s['participants'][$i]['name'] = mb_substr((string)$p['name'], 0, 60);
                if (isset($p['pronouns'])) $s['participants'][$i]['pronouns'] = mb_substr((string)$p['pronouns'], 0, 40);
                $s['participants'][$i]['list'] = $list;   // Selbst-Beitritt überschreibt die von der Leitung gewählte Liste
                if ($status !== '') $s['participants'][$i]['status'] = $status;
                elseif (empty($s['participants'][$i]['status'])) $s['participants'][$i]['status'] = 'AStA';
                $s['participants'][$i]['present'] = true;            // ist jetzt im Raum → links sichtbar
                if ($self) $s['participants'][$i]['online'] = true;  // online nur setzen, nie löschen
                if ($isMember) $s['participants'][$i]['member'] = true;
            } else {
                $s['participants'][] = [
                    'id' => $id, 'name' => mb_substr((string)($p['name'] ?? 'Teilnehmer:in'), 0, 60),
                    'pronouns' => mb_substr((string)($p['pronouns'] ?? ''), 0, 40),
                    'status' => $status !== '' ? $status : ($isMember ? 'AStA' : 'Gast'),
                    'list' => $list, 'count' => 0,
                    'present' => true, 'online' => $self, 'member' => $isMember,
                ];
            }
            return [$s, true];

        case 'raise':
            $i = part_idx($s['participants'], $id);
            if ($i < 0) return [$s, false];
            foreach ($allLists as $k) if (in_array($id, $s['queue'][$k], true)) return [$s, false]; // schon irgendwo gemeldet
            $kind = (string)($p['kind'] ?? '');
            if ($kind === 'go' || $kind === 'nachfrage') { $s['queue'][$kind][] = $id; return [$s, true]; }
            $list = $listOf($s['participants'][$i]['list'] ?? 'offen');
            $s['queue'][$list][] = $id;
            return [$s, true];

        case 'lower': case 'remove':
            $changed = false;
            foreach ($allLists as $k) {
                $idx = array_search($id, $s['queue'][$k], true);
                if ($idx !== false) { array_splice($s['queue'][$k], $idx, 1); $changed = true; }
            }
            return [$s, $changed];

        case 'call':
            // GO/Nachfrage geben die Liste direkt mit; sonst flinta/offen
            $rawList = (string)($p['list'] ?? '');
            $list = ($rawList === 'go' || $rawList === 'nachfrage') ? $rawList : $listOf($rawList);
            foreach ($allLists as $k) {
                $idx = array_search($id, $s['queue'][$k], true);
                if ($idx !== false) array_splice($s['queue'][$k], $idx, 1);
            }
            $s['nowSpeaking'] = ['id' => $id, 'list' => $list];
            $pi = part_idx($s['participants'], $id);
            if ($pi >= 0) $s['participants'][$pi]['count'] = (int)($s['participants'][$pi]['count'] ?? 0) + 1;
            if (isset($p['nextList'])) $s['nextList'] = $listOf($p['nextList']);
            return [$s, true];

        case 'set_list':
            $i = part_idx($s['participants'], $id);
            if ($i < 0) return [$s, false];
            $s['participants'][$i]['list'] = $listOf($p['list'] ?? '');
            foreach ($allLists as $k) {
                $idx = array_search($id, $s['queue'][$k], true);
                if ($idx !== false) array_splice($s['queue'][$k], $idx, 1);
            }
            return [$s, true];

        case 'reorder':
            $list = $listOf($p['list'] ?? '');
            if (!is_array($p['ids'] ?? null)) return [$s, false];
            $ids = array_values(array_filter(array_map(fn($x) => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$x), $p['ids']), fn($x) => $x !== ''));
            $cur = $s['queue'][$list];
            $kept = array_values(array_filter($ids, fn($x) => in_array($x, $cur, true))); // nur reale IDs, keine Injektion
            foreach ($cur as $x) if (!in_array($x, $kept, true)) $kept[] = $x;            // Ungenannte hinten anhängen
            $s['queue'][$list] = $kept;
            return [$s, true];

        case 'protocol_pause':
            // Start/Ende der Protokollpause. Start nur durch die:den Protokollant:in (id == protocolTaker.id);
            // Ende durch die:den Protokollant:in ODER die Leitung (_leader, serverseitig gesetzt).
            $taker   = is_array($s['protocolTaker'] ?? null) ? $s['protocolTaker'] : null;
            $takerId = $taker ? (string)($taker['id'] ?? '') : '';
            $isTaker = ($id !== '' && $takerId !== '' && $id === $takerId);
            $byLeader = !empty($p['_leader']);
            if (!empty($p['on'])) {
                if (!$isTaker) return [$s, false];                       // nur die:der Protokollant:in startet
                if (!empty($s['protocolPause'])) return [$s, false];     // läuft schon
                $s['protocolPause'] = ['by' => $takerId, 'name' => $taker ? (string)($taker['name'] ?? '') : '', 'at' => time()];
                return [$s, true];
            }
            if (!$isTaker && !$byLeader) return [$s, false];             // Ende: Protokollant:in oder Leitung
            if (empty($s['protocolPause'])) return [$s, false];
            $s['protocolPause'] = null;
            return [$s, true];

        case 'agenda': // flexibel: setzt nur die mitgegebenen Felder (TOPs, aktueller TOP, Timer, Sitzungsstatus)
            if (isset($p['tops']) && is_array($p['tops'])) $s['tops'] = $p['tops'];
            if (array_key_exists('currentTop', $p)) $s['currentTop'] = (int)$p['currentTop'];
            if (array_key_exists('topStartedAt', $p)) $s['topStartedAt'] = $p['topStartedAt'] !== null ? (int)$p['topStartedAt'] : null;
            if (array_key_exists('topHadMeldungen', $p)) $s['topHadMeldungen'] = (bool)$p['topHadMeldungen'];
            if (isset($p['sessionTitle'])) $s['sessionTitle'] = mb_substr((string)$p['sessionTitle'], 0, 200);
            if (array_key_exists('sessionActive', $p)) $s['sessionActive'] = (bool)$p['sessionActive'];
            return [$s, true];
    }
    return [$s, false];
}
