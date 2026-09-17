<?php
declare(strict_types=1);
/**
 * Das Web-App-Manifest. Früher eine feste Datei – jetzt erzeugt, weil Name, Beschreibung und
 * Symbol dem Träger dieser Installation gehören und in der Verwaltung stehen.
 */
require_once __DIR__ . '/lib.php';

// Kopfzeilen NUR, wenn diese Datei selbst aufgerufen wurde. Der Selbsttest bindet sie ein,
// um ihre Ausgabe zu prüfen – dort würde ein Content-Type die umgebende HTML-Seite kapern
// und der Browser zeigte Quelltext. Auf headers_sent() ist dabei kein Verlass: Bei
// eingeschaltetem Output-Buffering meldet es „noch nicht gesendet", obwohl längst Ausgabe läuft.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
}

echo json_encode([
    'name'             => APP_NAME,
    'short_name'       => APP_NAME,
    'description'      => 'Termin-Abstimmungen, Helfer-Einteilung, Sitzungen, Berichte und Kalender des '
                          . org_name_kurz() . '.',
    'lang'             => 'de',
    'start_url'        => 'dashboard.php',
    'scope'            => '.',
    'display'          => 'standalone',
    'background_color' => '#0E5C73',
    'theme_color'      => '#0E5C73',
    'icons'            => [
        ['src' => brand_url('icon-192'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => brand_url('icon-512'), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => brand_url('icon-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
