<?php
declare(strict_types=1);
/**
 * Web-App-Manifest von was.läuft. Erzeugt statt fest, weil die Beschreibung den Ort nennt –
 * und der steht in den Einstellungen. Der Name selbst bleibt: „was.läuft" ist die Marke des
 * Angebots, nicht die eines Standorts.
 */
require_once __DIR__ . '/../wl-db.php';

// Kopfzeilen NUR, wenn diese Datei selbst aufgerufen wurde. Der Selbsttest bindet sie ein,
// um ihre Ausgabe zu prüfen – dort würde ein Content-Type die umgebende HTML-Seite kapern
// und der Browser zeigte Quelltext. Auf headers_sent() ist dabei kein Verlass: Bei
// eingeschaltetem Output-Buffering meldet es „noch nicht gesendet", obwohl längst Ausgabe läuft.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
}

echo json_encode([
    'id'               => './',
    'name'             => 'was.läuft',
    'short_name'       => 'was.läuft',
    'description'      => 'Veranstaltungen, Partys, Kurse und Angebote in ' . wl_ort()
                          . ' – gebündelt auf einer Seite.',
    'lang'             => 'de',
    'start_url'        => 'index.php',
    'scope'            => '.',
    'display'          => 'standalone',
    'background_color' => '#141220',
    'theme_color'      => '#141220',
    'icons'            => [
        ['src' => '../assets/wl-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '../assets/wl-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => '../assets/wl-icon-512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts'        => [
        ['name' => 'Kurse & Angebote',        'url' => 'kurse.php'],
        ['name' => 'Veranstaltung eintragen', 'url' => 'einreichen.php'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
