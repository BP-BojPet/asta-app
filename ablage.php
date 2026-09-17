<?php
/**
 * Blättern in der Ablage (Teams/Nextcloud) für den Datei-Picker – reines Lesen, JSON.
 *
 * Wählen und Anhängen macht die jeweilige Seite selbst (mit CSRF und ihren eigenen Regeln);
 * dieser Endpunkt zeigt nur, was da ist. Deshalb GET und keine Zustandsänderung.
 *
 *   GET ablage.php?kind=vfile&store=teams&path=Protokolle/2026
 *   → {"ok":true,"store":"teams","path":"…","up":"…","entries":[{"name","dir","ref","size"}]}
 *
 * Wer blättern darf, entscheidet dieselbe Prüfung wie fürs Öffnen in der Ablage
 * (mirror_open_permitted) – ohne Bezugs-ID, denn beim Anhängen gibt es die noch nicht.
 */
require __DIR__ . '/lib.php';
db();
require_login();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function ablage_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$kind = (string)($_GET['kind'] ?? '');
if (!isset(mirror_kinds()[$kind])) ablage_json(['ok' => false, 'error' => 'Unbekannter Bereich.'], 400);
if (!mirror_allowed($kind))        ablage_json(['ok' => false, 'error' => 'Keine Berechtigung für diesen Bereich.'], 403);

$stores = ablage_stores();
if (!$stores) ablage_json(['ok' => false, 'error' => 'Es ist keine Ablage eingerichtet (Verwaltung → Uploads und Automationen).'], 400);

$store = (string)($_GET['store'] ?? '');
if ($store === '' || !isset($stores[$store])) $store = (string)array_key_first($stores);

$err = null;
$res = ablage_browse($store, (string)($_GET['path'] ?? ''), $err);
if ($res === null) ablage_json(['ok' => false, 'error' => $err ?: 'Die Ablage antwortet gerade nicht.'], 502);

// Nur anhängbare Dateitypen anbieten – der Rest wäre beim Anhängen ohnehin abgelehnt worden.
$erlaubt = attach_ext_allowed();
$entries = [];
foreach ($res['entries'] as $e) {
    if (!$e['dir'] && !in_array(strtolower(pathinfo((string)$e['name'], PATHINFO_EXTENSION)), $erlaubt, true)) continue;
    $entries[] = $e;
}

$pfad = (string)$res['path'];
ablage_json([
    'ok'      => true,
    'store'   => $store,
    'stores'  => $stores,
    'label'   => mirror_label($store),
    'path'    => $pfad,
    'up'      => $pfad === '' ? null : trim((string)dirname($pfad), '.'),
    'entries' => $entries,
]);
