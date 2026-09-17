<?php
/**
 * Beleg-Download für das Präsidium – eigener Weg, weil download.php in der App eine
 * Mitglieds-Anmeldung verlangt und die es hier bewusst nicht gibt.
 * Es gibt genau einen Präsidiums-Zugang pro Haus; er darf die Belege aller Aufforderungen
 * sehen, die über ihn eingereicht wurden.
 */
require __DIR__ . '/stupa-lib.php';
$user = stupa_require_login();

$f = stupa_file_get((int)($_GET['f'] ?? 0));
if (!$f) { http_response_code(404); exit('Datei nicht gefunden.'); }
$claim = stupa_claim_get((int)$f['claim_id']);
if (!$claim || (int)$claim['created_by'] !== (int)$user['id']) {
    http_response_code(403);
    exit('Keine Berechtigung für diesen Beleg.');
}
$path = upload_dir() . '/' . basename((string)$f['stored_name']); // basename schützt vor Pfad-Tricks
if (!is_file($path)) { http_response_code(404); exit('Datei ist nicht mehr vorhanden.'); }

file_delivery_headers((string)$f['mime'] !== '' ? (string)$f['mime'] : 'application/octet-stream',
    (string)$f['orig_name'], (int)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
