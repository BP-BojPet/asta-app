<?php
/**
 * Ergebnis-Grafiken – der GEMEINSAME Bausatz (Umfragen, externe Events, wer noch kommt).
 *
 * EIGENSTÄNDIG wie push-core.php und der dav_*-Kern: keine lib.php, kein Datenbank-Zugriff.
 * Bewusst SVG mit ATTRIBUTEN statt style="width:…": Die öffentlichen Seiten laufen unter
 * einer CSP ohne 'unsafe-inline' – ein style-Attribut würde still verworfen, ein
 * width-Attribut im SVG nicht. Farben und Maße kommen aus den Stylesheets (Klassen um-g-*).
 * SVGs mit Schrift werden NIE gestreckt und bekommen im CSS eine feste HÖHE.
 */

declare(strict_types=1);

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Balken je Zeile ['label','n','pct']; die stärkste Zeile ist markiert (um-g-top). */
function chart_balken_html(array $zeilen): string
{
    $maxN = 0;
    foreach ($zeilen as $z) $maxN = max($maxN, (int)$z['n']);
    $out = '<div class="um-graph">';
    foreach ($zeilen as $z) {
        $top = $maxN > 0 && (int)$z['n'] === $maxN;
        $breite = max(0.6, min(100, (int)$z['pct']));
        $out .= '<div class="um-g-zeile' . ($top ? ' um-g-top' : '') . '">'
              . '<span class="um-g-label">' . h((string)$z['label']) . '</span>'
              . '<svg class="um-g-balken" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">'
              . '<rect class="um-g-grund" x="0" y="0" width="100" height="10" rx="2"></rect>'
              . '<rect class="um-g-wert" x="0" y="0" width="' . $breite . '" height="10" rx="2"></rect></svg>'
              . '<span class="um-g-zahl">' . (int)$z['n'] . '<em> · ' . (int)$z['pct'] . ' %</em></span>'
              . '</div>';
    }
    return $out . '</div>';
}

/** Verteilung als kleines Säulen-Diagramm, optional mit Mittelwert-Marker auf der Grundlinie. */
function chart_saeulen_html(array $zeilen, ?float $schnitt, int $min): string
{
    $n = count($zeilen);
    if ($n === 0) return '';
    $maxPct = 1;
    foreach ($zeilen as $z) $maxPct = max($maxPct, (int)$z['pct']);
    $sw = 14; $gap = 6;
    $w = $n * $sw + ($n - 1) * $gap;
    $out = '<svg class="um-g-saeulen" viewBox="0 0 ' . $w . ' 64" role="img" aria-label="Verteilung der Antworten">';
    foreach ($zeilen as $i => $z) {
        $x = $i * ($sw + $gap);
        $hoehe = round(((int)$z['pct']) / $maxPct * 40, 1);
        $out .= '<rect class="um-g-grund" x="' . $x . '" y="8" width="' . $sw . '" height="40" rx="2"></rect>'
              . '<rect class="um-g-wert" x="' . $x . '" y="' . (48 - $hoehe) . '" width="' . $sw . '" height="' . $hoehe . '" rx="2"></rect>'
              . '<text class="um-g-anzahl" x="' . ($x + $sw / 2) . '" y="5.5" text-anchor="middle">' . (int)$z['n'] . '</text>'
              . '<text class="um-g-stufe" x="' . ($x + $sw / 2) . '" y="56" text-anchor="middle">' . h((string)$z['label']) . '</text>';
    }
    if ($schnitt !== null && $n > 1) {
        $pos = ($schnitt - $min) / ($n - 1);
        $x = max(0, min(1, $pos)) * (($n - 1) * ($sw + $gap)) + $sw / 2;
        $out .= '<path class="um-g-marker" d="M' . round($x, 1) . ' 49 l-3 6 h6 z"></path>';
    }
    return $out . '</svg>';
}

/** Zeitverlauf als schmale Säulenreihe. $tage: [['tag' => 'Y-m-d', 'n' => int], …]. */
function chart_verlauf_html(array $tage): string
{
    if (count($tage) < 2) return '';
    $maxN = 1;
    foreach ($tage as $t) $maxN = max($maxN, (int)$t['n']);
    $n = count($tage);
    $sw = max(3, min(14, (int)floor(240 / $n) - 2));
    $gap = max(1, (int)round($sw / 4));
    $w = $n * $sw + ($n - 1) * $gap;
    $out = '<svg class="um-g-verlauf" viewBox="0 0 ' . $w . ' 36" role="img" aria-label="Verlauf je Tag">';
    foreach ($tage as $i => $t) {
        $x = $i * ($sw + $gap);
        $hoehe = max(1, round((int)$t['n'] / $maxN * 30, 1));
        $out .= '<rect class="um-g-wert" x="' . $x . '" y="' . (32 - $hoehe) . '" width="' . $sw . '" height="' . $hoehe . '" rx="1">'
              . '<title>' . h(date('d.m.', (int)strtotime((string)$t['tag']))) . ': ' . (int)$t['n'] . '</title></rect>';
    }
    return $out . '</svg>';
}

/** Aus Tag-=>Anzahl-Paaren eine lückenlose Reihe machen (Tage ohne Treffer als Nuller). */
function chart_tage_fuellen(array $roh): array
{
    if (!$roh) return [];
    ksort($roh);
    $tage = array_keys($roh);
    $von = strtotime((string)$tage[0]);
    $bis = strtotime((string)end($tage));
    if (!$von || !$bis || ($bis - $von) / 86400 > 120) {
        $out = [];
        foreach ($roh as $t => $c) $out[] = ['tag' => (string)$t, 'n' => (int)$c];
        return $out;
    }
    $out = [];
    for ($t = $von; $t <= $bis; $t += 86400) {
        $key = date('Y-m-d', $t);
        $out[] = ['tag' => $key, 'n' => (int)($roh[$key] ?? 0)];
    }
    return $out;
}
