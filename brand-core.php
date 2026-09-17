<?php
declare(strict_types=1);
/**
 * Marken-Bilder dieser Installation: Kopf-Logo, großes Logo, App-Symbol.
 *
 * EIGENSTÄNDIG wie push-core.php und der dav_*-Kern: keine lib.php, keine Datenbank. Das
 * Logo erscheint auch in den öffentlichen Bereichen, und die dürfen die App-Bibliothek nicht
 * einbinden – also braucht die Marke einen Ort, den alle erreichen.
 *
 * Zwei Ablagen, klare Rangfolge:
 *   1. data/branding/<name>.png – das Logo DIESER Installation, hochgeladen in der Verwaltung.
 *      Liegt in data/ und geht damit weder ins Repo noch über den rsync: Wer die App
 *      übernimmt, überschreibt euer Logo nie, und ihr verliert eures bei keinem Einspielen.
 *   2. assets/<name>.png – der neutrale Platzhalter, der mit dem Programm ausgeliefert wird.
 *      Greift, solange niemand etwas hochgeladen hat.
 */

/** Erlaubte Marken-Namen. Alles andere wird nicht ausgeliefert – der Name kommt aus der URL. */
function brand_namen(): array
{
    // 'logo-app' ist das kompakte Kopf-Logo der App (54x38-Kasten in .brand-logo-img) und
    // ein ANDERES Bild als 'logo-header', das die öffentlichen Bereiche breit in ihrer
    // Kopfzeile führen. Sie sehen ähnlich aus, sind aber verschieden beschnitten – wer sie
    // vertauscht, verschiebt das Logo in der Titelleiste sichtbar.
    return ['logo-app', 'logo-header', 'logo', 'logo-420', 'logo-verlauf', 'icon-180', 'icon-192', 'icon-512'];
}

/** Wurzelverzeichnis der App (dort liegen data/ und assets/). */
function brand_root(): string
{
    return __DIR__;
}

/** Eigenes, hochgeladenes Bild – oder null. */
function brand_eigen(string $name): ?string
{
    if (!in_array($name, brand_namen(), true)) return null;
    $p = brand_root() . '/data/branding/' . $name . '.png';
    return is_file($p) ? $p : null;
}

/** Auszuliefernde Datei: eigenes Bild, sonst Platzhalter, sonst null. */
function brand_pfad(string $name): ?string
{
    $eigen = brand_eigen($name);
    if ($eigen !== null) return $eigen;
    if (!in_array($name, brand_namen(), true)) return null;
    $p = brand_root() . '/assets/' . $name . '.png';
    return is_file($p) ? $p : null;
}

/**
 * Adresse fürs HTML. $auf ist der Weg zurück zur Wurzel ('' im Hauptteil, '../' in den
 * öffentlichen Unterordnern). Der Zeitstempel hängt dran, damit ein neues Logo sofort
 * durchschlägt – die Auslieferung darf deshalb lange zwischenspeichern.
 */
function brand_url(string $name, string $auf = ''): string
{
    $p = brand_pfad($name);
    $v = $p ? (int)@filemtime($p) : 0;
    return $auf . 'brand.php?b=' . rawurlencode($name) . ($v ? '&v=' . $v : '');
}

/**
 * Das Bild als Data-URI. Nur fürs Kopf-Logo gedacht: Es steckt im Stylesheet und ist damit
 * Teil des ERSTEN Bildes. Als eigene Anfrage würde Firefox es sichtbar nachreichen.
 */
function brand_data_uri(string $name): string
{
    $p = brand_pfad($name);
    if ($p === null) return '';
    $roh = @file_get_contents($p);
    return $roh === false ? '' : 'data:image/png;base64,' . base64_encode($roh);
}

/** Liegt für diesen Namen ein eigenes Bild? (Für die Anzeige in der Verwaltung.) */
function brand_hat_eigenes(string $name): bool
{
    return brand_eigen($name) !== null;
}
