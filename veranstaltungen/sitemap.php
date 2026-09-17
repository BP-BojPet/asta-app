<?php
/**
 * Sitemap für Suchmaschinen.
 *
 * Zusammen mit den schema.org/Event-Daten auf v.php ist das der billigste Reichweiten-Hebel
 * der Seite: Google findet jede Beitragsseite, ohne dass sie irgendwo verlinkt sein muss.
 * ABGELEITET statt gepflegt – die Liste entsteht bei jedem Aufruf frisch aus der Datenbank:
 * die vier festen Seiten plus alle öffentlichen, noch nicht vergangenen Beiträge.
 *
 * Einmalig einzureichen unter https://search.google.com/search-console (oder als
 * `Sitemap: …/veranstaltungen/sitemap.php`-Zeile in der robots.txt des Webspace).
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

// Steht die Seite auf „aus", darf die Sitemap keine Adressen mehr anbieten – sonst schickt sie
// Suchmaschinen auf lauter Seiten, die mit 503 antworten. Kein HTML hier: Das liest eine Maschine.
if (wl_mode() === 'off') {
    http_response_code(503);
    header('Retry-After: 3600');
    header('Content-Type: text/plain; charset=utf-8');
    exit("Die Seite ist vorübergehend abgeschaltet.\n");
}

// Basis-Adresse nicht gesetzt: Ohne absolute Adressen ist eine Sitemap wertlos (der Standard
// verlangt volle URLs) – dann lieber ehrlich gar keine. (Der Aus-Schalter ist oben schon
// abgehandelt, und zwar mit 503 statt 404: „gerade nicht" ist etwas anderes als „gibt es nicht".)
$base = wl_base_url();
if ($base === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Keine Sitemap verfügbar.\n");
}

$pfad = $base . '/veranstaltungen/';

// Wie wl_items_public: nur Freigegebenes, nichts Vergangenes – und keine Demo-Daten, die
// Testphase gehört nicht in den Google-Index. Abgesagtes bleibt drin (EventCancelled ist
// für Suchmaschinen eine gültige, sogar erwünschte Information).
$items = wl_db()->query("SELECT id, decided_at, submitted_at FROM items
    WHERE status = 'live' AND demo = 0
      AND (CASE WHEN kind = 'kurs'
                THEN (bis_datum = '' OR date(bis_datum) >= date('now','localtime'))
                ELSE date(CASE WHEN ends_at <> '' THEN ends_at ELSE starts_at END) >= date('now','localtime')
           END)
    ORDER BY id")->fetchAll();

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$url = static function (string $loc, string $lastmod = ''): void {
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $lastmod)) echo '<lastmod>' . substr($lastmod, 0, 10) . '</lastmod>';
    echo "</url>\n";
};

$url($pfad . 'index.php');
$url($pfad . 'kurse.php');
// Die Rechtsseiten gehören dazu, sobald es sie gibt: Suchmaschinen bewerten es positiv, wenn
// Pflichtangaben auffindbar sind – und ohne eigenen Text existieren sie gar nicht.
if (trim(wl_text('recht_impressum')) !== '')   $url($pfad . 'impressum.php');
if (trim(wl_text('recht_datenschutz')) !== '') $url($pfad . 'datenschutz.php');
$url($pfad . 'ueber.php');
$url($pfad . 'veranstalter.php');
foreach ($items as $it) {
    $stand = trim((string)$it['decided_at']) !== '' ? (string)$it['decided_at'] : (string)$it['submitted_at'];
    $url($pfad . 'v.php?id=' . (int)$it['id'], $stand);
}

echo "</urlset>\n";
