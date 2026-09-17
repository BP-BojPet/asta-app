<?php
declare(strict_types=1);
/**
 * Liefert ein Marken-Bild aus – eigenes aus data/branding/, sonst den Platzhalter aus assets/.
 *
 * Bewusst OHNE lib.php: Die Marke steht auch auf Seiten, die wildfremden Menschen offenstehen
 * und die App-Bibliothek nicht laden dürfen. Der Name kommt aus der URL und wird deshalb
 * gegen eine feste Liste geprüft, nie zu einem Pfad zusammengebaut.
 */
require __DIR__ . '/brand-core.php';

$pfad = brand_pfad((string)($_GET['b'] ?? ''));
if ($pfad === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Nicht gefunden\n");
}

$zeit = (int)@filemtime($pfad);
$etag = '"' . md5($pfad . '|' . $zeit) . '"';

// Lange zwischenspeichern ist sicher: Ein neues Logo bekommt einen neuen Zeitstempel in der
// Adresse (brand_url) und damit eine andere URL.
header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $zeit) . ' GMT');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . (string)filesize($pfad));
readfile($pfad);
