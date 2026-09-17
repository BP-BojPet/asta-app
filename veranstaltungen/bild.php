<?php
/**
 * Bildauslieferung.
 *
 * Warum nicht einfach der Ordner im Web? Weil dann jede Datei, die dort einmal landet, öffentlich
 * abrufbar wäre – auch eine, die versehentlich hineingerät. Hier geht nur durch, was in der
 * Datenbank steht, mit festem Typ und fester Größe. Der Ordner selbst ist per .htaccess gesperrt.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

$file  = wl_param($_GET['f'] ?? '');
$gross = wl_param($_GET['g'] ?? '') === '1';

$pfad = wl_image_path($file, $gross);
// Die Kachel-Fassung kann fehlen, wenn sie beim Übernehmen nicht entstanden ist – dann die große.
if ($pfad === '' && !$gross) $pfad = wl_image_path($file, true);

if ($pfad === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Bild nicht gefunden.\n");
}

// Bilder ändern sich nie (jede Fassung bekommt einen neuen Namen), also darf der Browser sie lange
// behalten. Das spart auf dem Handy spürbar Daten beim Blättern.
$etag = '"' . substr(md5($file . ($gross ? 'g' : 'k') . (string)@filemtime($pfad)), 0, 20) . '"';
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)@filesize($pfad));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

readfile($pfad);
