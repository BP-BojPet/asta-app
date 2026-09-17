<?php
/**
 * Impressum von was.läuft – die eigene, kurze Fassung.
 *
 * Absichtlich OHNE wl_offline_guard(): Pflichtangaben müssen erreichbar bleiben, auch wenn die
 * Seite geschlossen ist oder im Vorabstart steht. Genau darauf verweisen die beiden Seiten dort
 * in ihren unteren Ecken.
 *
 * Steht im Register kein eigener Text, gibt es diese Seite nicht: Dann führt alles auf die
 * Adresse aus den Einstellungen, und niemand landet auf einer leeren Rechtsseite.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

$text = trim(wl_text('recht_impressum'));
if ($text === '') {
    $aus = trim(wl_setting('imprint_url', ''));
    if ($aus !== '') { header('Location: ' . $aus); exit; }
    http_response_code(404);
    wl_head(wl_t('f_impressum'), '', '', true);
    wl_nav();
    echo '<main class="wl-wrap wl-recht"><h1>' . h(wl_t('f_impressum')) . '</h1>'
       . '<p>' . h(wl_t('recht_fehlt')) . '</p></main>';
    wl_foot();
    exit;
}

wl_head(wl_t('f_impressum'), '', '', true);
wl_nav();
?>
<main class="wl-wrap wl-recht">
  <h1><?= h(wl_t('f_impressum')) ?></h1>
  <?= wl_recht_html($text) ?>
</main>
<?php
wl_foot();
