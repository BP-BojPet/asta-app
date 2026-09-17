<?php
/**
 * Was soll das Banner auf fremden Seiten gerade sagen? – Antwort als JSON.
 *
 * Damit ist das Stück HTML, das einmal in WordPress eingesetzt wird, dumm: Es fragt hier nach
 * und zeigt, was zurückkommt. Ein- und ausschalten sowie die Texte ändern geht danach in der
 * Verwaltung, ohne die fremde Seite noch einmal anzufassen.
 *
 * Bewusst OHNE wl_offline_guard(): Das Banner soll auch dann eine Antwort bekommen, wenn das
 * Portal geschlossen ist – dann eben „aus". Eine 503-HTML-Seite auf eine JSON-Anfrage wäre eine
 * Antwort, mit der das Banner nichts anfangen kann.
 *
 * Access-Control-Allow-Origin: Planer und Website liegen zwar auf derselben Domain, aber schon
 * „www." davor wäre für den Browser eine andere Herkunft. Die Antwort enthält nichts als die
 * Werbetexte, die ohnehin öffentlich stehen sollen – also erlauben wir sie überall.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
// Fünf Minuten Vorrat: Die Texte ändern sich selten, und die Website soll nicht bei jedem
// Seitenaufruf nachfragen müssen. Wer umschaltet, sieht es also spätestens nach fünf Minuten.
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$an    = wl_setting('banner_web', '0') === '1';
$titel = trim(wl_text('banner_titel'));
$satz  = trim(wl_text('banner_satz'));
$knopf = trim(wl_text('banner_knopf'));
// Stichpunkte kommen als eine Zeile mit Kommas und gehen als Liste raus: Das Trennen gehoert
// hierher, nicht in das Stueck HTML auf der fremden Seite – das soll dumm bleiben.
$chips = array_values(array_filter(array_map('trim', explode(',', wl_text('banner_chips'))), 'strlen'));
$basis = wl_base_url();

// Ohne Titel oder ohne Adresse gibt es nichts zu zeigen – dann lieber ehrlich „aus" melden,
// als ein Band mit leerer Zeile oder totem Knopf auf eine fremde Seite zu setzen.
$aktiv = $an && $titel !== '' && $basis !== '';

echo json_encode([
    'aktiv' => $aktiv,
    'titel' => $aktiv ? $titel : '',
    'satz'  => $aktiv ? $satz : '',
    'knopf' => $aktiv ? ($knopf !== '' ? $knopf : 'Jetzt anschauen') : '',
    'chips' => $aktiv ? $chips : [],
    'url'   => $aktiv ? $basis . '/veranstaltungen/' : '',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
