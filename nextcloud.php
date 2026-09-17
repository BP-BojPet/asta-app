<?php
/**
 * „In Nextcloud öffnen": stellt sicher, dass die Datei in der Nextcloud liegt
 * (einmaliger Upload, danach nur noch Nachschlagen) und leitet auf /f/<fileid>
 * weiter – die Nextcloud entscheidet dann selbst, ob sie die Datei im
 * Office-Server (Collabora/OnlyOffice) öffnet oder im Dateimanager zeigt.
 *
 * Die Weiche mirror_ensure() achtet darauf, dass eine Datei NIE in beiden
 * Ablagen landet: liegt sie schon in Teams, führt auch dieser Aufruf dorthin.
 * Rechte: mirror_open_permitted() – dieselbe Prüfung wie in teams.php.
 */
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();

$kind = (string)($_GET['kind'] ?? '');
$refId = (int)($_GET['id'] ?? 0);
if (!isset(mirror_kinds()[$kind]) || $refId <= 0) { http_response_code(404); exit('Unbekannter Bereich.'); }
if (!mirror_open_permitted($kind, $refId, $me)) {
    http_response_code(403);
    exit('Keine Berechtigung, diese Datei in der Ablage zu öffnen.');
}

$err = null;
$res = mirror_ensure($kind, $refId, $me ? (int)$me['id'] : 0, $err);
$url = $res ? ($res['store'] === 'nextcloud' ? nc_file_open_url($res['row']) : teams_file_open_url($res['row'])) : '';
if ($url === '') {
    // Zur Herkunftsseite zurück (nur lokale Pfade), sonst Dashboard
    flash('Öffnen in der Ablage fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $home = base_url() ?: '';
    redirect($home !== '' && str_starts_with($ref, $home) ? $ref : 'dashboard.php');
}
redirect($url);
