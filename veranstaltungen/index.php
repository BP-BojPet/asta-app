<?php
/**
 * Startseite von was.läuft.
 *
 * Ziel: In fünf Sekunden soll klar sein, was diese Woche los ist. Deshalb steht oben das
 * Hervorgehobene groß, danach kommt das Nächste in der Reihenfolge, in der es stattfindet, und
 * die Kurse liegen in einem eigenen Streifen darunter – sonst verstopfen sie die Wochenübersicht.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard()) exit;

/* DYNAMISCHER GRUNDZUSTAND: Ohne gewählten Zeitraum zeigt die Seite DIESE WOCHE – aber nur,
   wenn dabei mindestens WL_WOCHE_MIN Veranstaltungen zu sehen wären (mitgezählt werden die
   vorgezogenen Tipps, denn die stünden ja wirklich da). Sonst fällt sie auf „demnächst"
   (alles Kommende) zurück: Eine fast leere Startseite ist der schlechteste erste Eindruck,
   den ein neues Angebot machen kann – und in der Anlaufphase sind dünne Wochen normal.
   Die Woche zuerst heißt auch: Der Kurse-Streifen rückt näher an den Anfang.

   Die Dynamik wirkt NUR auf der Landung (kein zeit-Parameter). Sobald irgendwo geklickt wird,
   nagelt wl_url() den gerade wirksamen Zeitraum in die Adresse – sonst spränge die Ansicht
   beim Setzen eines Filters unerwartet zwischen Woche und Demnächst hin und her. */
const WL_WOCHE_MIN = 3;

$suche = trim(wl_param($_GET['q'] ?? ''));
$cat   = wl_param($_GET['cat'] ?? '');
$frei  = wl_param($_GET['frei'] ?? '') === '1';
$bfrei = wl_param($_GET['bf'] ?? '') === '1';
$orgId = (int)wl_param($_GET['org'] ?? '0');   // „12 weitere Veranstaltungen" auf der Detailseite

// 'alle' ist das AUSDRÜCKLICHE „demnächst" – der leere Parameter ist der dynamische Zustand.
$zeitParam = wl_param($_GET['zeit'] ?? '');
if (!in_array($zeitParam, ['heute', 'we', 'woche', 'monat', 'alle'], true)) $zeitParam = '';

if (!isset(wl_cats()[$cat])) $cat = '';
$orgFilter = $orgId > 0 ? wl_org($orgId) : null;
if (!$orgFilter) $orgId = 0;

$filterOhneZeit = $suche !== '' || $cat !== '' || $frei || $bfrei || $orgId > 0;
// Ob der ZEITRAUM als Filter zählt, entscheidet sich erst unten: „Diese Woche" anklicken,
// wenn die Startseite ohnehin die Woche zeigt, ist KEIN Filtern.

// Zeitraum-Grenzen. „Wochenende" heißt: der kommende Samstag und Sonntag – nicht „in 2 Tagen".
$heute  = date('Y-m-d');
$grenzen = static function (string $z) use ($heute): array {
    switch ($z) {
        case 'heute': return [$heute, $heute];
        case 'woche': return [$heute, date('Y-m-d', strtotime('+6 days'))];
        case 'we':
            $sa = date('Y-m-d', strtotime('saturday this week'));
            if ($sa < $heute) $sa = date('Y-m-d', strtotime('saturday next week'));
            return [$sa, date('Y-m-d', strtotime($sa . ' +1 day'))];
        case 'monat': return [$heute, date('Y-m-d', strtotime('+31 days'))];
        default:      return ['', ''];   // 'alle'
    }
};

$basis  = ['cat' => $cat, 'suche' => $suche, 'frei' => $frei, 'barrierefrei' => $bfrei, 'org' => $orgId];
$events = null;

/* Die Woche zur Probe laden – auch bei ausdrücklich gewähltem „woche"/„alle": Nur so lässt
   sich sagen, was die NACKTE Landung zeigen würde. Entspricht die Wahl genau dem, bleibt
   die Seite Startseite (Überschrift, Banner, Empfohlene, kein „Filter zurücksetzen") statt
   in den Treffer-Modus zu kippen. Gezählt wird NUR, was wirklich in der Woche steht. */
$zeitAuto = null;
$probe = null;
if (in_array($zeitParam, ['', 'woche', 'alle'], true)) {
    [$von, $bis] = $grenzen('woche');
    $probe = wl_items_public($basis + ['von' => $von, 'bis' => $bis, 'kind' => 'event'], 48);
    $zeitAuto = count($probe) >= WL_WOCHE_MIN ? 'woche' : 'alle';
}
$zeit = $zeitParam !== '' ? $zeitParam : $zeitAuto;
if ($zeit === 'woche' && $zeitAuto === 'woche') $events = $probe;   // keine zweite Abfrage

$filterAktiv = $filterOhneZeit || ($zeitParam !== '' && $zeitParam !== $zeitAuto);
$tipps  = $filterAktiv ? [] : wl_featured(6);
$banner = $filterAktiv ? [] : wl_banners_active();

[$von, $bis] = $grenzen($zeit);
$grund = $basis + ['von' => $von, 'bis' => $bis];

if ($events === null) $events = wl_items_public($grund + ['kind' => 'event'], 48);
$kurse = wl_items_public($grund + ['kind' => 'kurs'], 12);

/* Die Überschrift folgt dem WIRKSAMEN Zeitraum: Wer „Diesen Monat" wählt, liest auch „diesen
   Monat" – und die dynamische Landung sagt ehrlich „diese Woche" oder „demnächst", je nachdem,
   was wirklich zu sehen ist. Ein Wort, das nicht zur Liste passt, ist ein Fehler. */
$zeitWort = match ($zeit) {
    'heute' => 'heute',
    'we'    => 'am Wochenende',
    'woche' => 'diese Woche',
    'monat' => 'diesen Monat',
    default => 'demnächst',
};

/* Hervorgehobenes: Die Terminliste bleibt STRENG CHRONOLOGISCH – kein Vorziehen und keine
   eigene Reihe, die nähme Platz oberhalb der Liste weg. Empfohlene stechen IN der Liste
   heraus: goldener Rahmen um das Bild plus Stern-Chip in Gelb, der Auszeichnungs-Farbe der
   Seite. Bewusst nicht mehr.

   Kurse: Der Kurs-Streifen hat keine Chronologie, dort ziehen Empfohlene nach vorn
   (Marke „Tipp" an der Tafel). Ein hervorgehobener Kurs gehört in den Streifen, nicht in die
   Wochenübersicht. */
if ($tipps) {
    $vorn = array_flip(array_map(static fn ($e) => (int)$e['id'], $tipps));
    foreach ($events as &$e) { if (isset($vorn[(int)$e['id']])) $e['tipp'] = 1; } unset($e);

    $ohne = static fn (array $liste) => array_values(array_filter(
        $liste, static fn ($e) => !isset($vorn[(int)$e['id']])));
    $kurse = $ohne($kurse);
    $vorKurse = [];
    foreach ($tipps as $t) {
        if (($t['kind'] ?? 'event') === 'kurs') { $t['tipp'] = 1; $vorKurse[] = $t; }
    }
    $kurse = array_merge($vorKurse, $kurse);
}

/** Adresse mit geänderten Filtern – die übrigen bleiben stehen.
 *  Der Zeitraum wird als der WIRKSAME eingesetzt, nicht als der aus der Adresse: Auf der
 *  dynamischen Landung ist der Parameter leer, und jeder Klick soll den gerade gezeigten
 *  Zustand festhalten statt die Dynamik neu würfeln zu lassen. */
function wl_url(array $neu): string
{
    $jetzt = ['q' => wl_param($_GET['q'] ?? ''), 'cat' => wl_param($_GET['cat'] ?? ''),
              'zeit' => (string)($GLOBALS['zeit'] ?? ''), 'frei' => wl_param($_GET['frei'] ?? ''),
              'bf' => wl_param($_GET['bf'] ?? ''), 'org' => wl_param($_GET['org'] ?? '')];
    $p = array_filter(array_merge($jetzt, $neu), static fn ($v) => (string)$v !== '');
    return 'index.php' . ($p ? '?' . http_build_query($p) : '');
}

wl_head(wl_text('start_titel'), wl_text('start_meta'));
wl_nav('events');
?>

<?php
/* Strukturierte Daten fuer Suchmaschinen. Bewusst NUR auf der Startseite und bewusst schlank:
     • WebSite mit SearchAction – Google darf ein Suchfeld direkt im Treffer anbieten.
     • Organization – sagt, WER dahintersteht; ohne das raet die Suchmaschine.
   Die Veranstaltungen selbst tragen ihr eigenes Event-JSON-LD auf v.php; sie hier ein zweites
   Mal als Liste auszugeben, waere dieselbe Aussage in zwei Stimmen. */
$ldBasis = wl_base_url();
if ($ldBasis !== '') {
    $ld = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type'    => 'WebSite',
                '@id'      => $ldBasis . '/veranstaltungen/#website',
                'url'      => $ldBasis . '/veranstaltungen/',
                'name'     => 'was.läuft',
                'inLanguage' => wl_lang(),
                'description' => wl_text('start_meta'),
                'publisher'   => ['@id' => $ldBasis . '/veranstaltungen/#asta'],
                'potentialAction' => [
                    '@type'  => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint',
                                 'urlTemplate' => $ldBasis . '/veranstaltungen/?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
            [
                '@type' => 'Organization',
                '@id'   => $ldBasis . '/veranstaltungen/#asta',
                'name'  => wl_traeger_name(),
                'url'   => wl_traeger_url(),
                'areaServed' => ['@type' => 'City', 'name' => wl_ort_lang()],
            ],
        ],
    ];
    echo '<script type="application/ld+json">'
       . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
?>

<section class="wl-hero">
  <div class="wl-hero-in">
    <?php /* Der Rest des Satzes liegt in einem eigenen Element: Er wartet auf durchsichtig,
             solange sich das Rad dreht – so kann sich am Zeilenumbruch nichts verschieben.
             Das Zeitwort darin hat noch einmal ein eigenes, weil es das einzige ist, das sich
             beim Umschalten der Ansicht ändert – es kommt deshalb eigens herein (siehe wl.css). */ ?>
    <?php /* „in Landau?" ist EIN Umbruch-Block (wl-h1-ort): Bricht die Zeile, dann VOR dem
             „in" – ein einzelnes „in" am Zeilenende sieht verloren aus. */ ?>
    <h1 class="wl-h1" id="wl-h1"><?= wl_marke('läuft', true) ?><span class="wl-h1-rest"> <span class="wl-h1-zeit"><?= h($zeitWort) ?></span><?php if (wl_ort() !== ''): ?> <span class="wl-h1-ort">in <em><?= h(wl_ort()) ?></em>?</span><?php endif; ?></span></h1>
    <?= wl_absaetze(wl_text('start_lead')) ?>
    <form class="wl-suche" method="get" action="index.php" role="search">
      <?php /* Die Lupe als SVG im Quelltext: Eine Symbolschrift laden wir hier nicht (der
               öffentliche Bereich soll mit möglichst wenig auskommen), und ein Bild wäre für
               zwei Striche zu viel. Farbe und Strichstärke stehen im Stylesheet – unter der
               CSP wird ein style="…" ohnehin verworfen. */ ?>
      <svg class="wl-suche-i" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.4 15.4 L20 20"/>
      </svg>
      <?php // Der Platzhaltertext enthält Anführungszeichen – ohne h() zerlegen sie das Attribut. ?>
      <input type="search" name="q" value="<?= h($suche) ?>"
             placeholder="<?= h(wl_text('start_suche')) ?>"
             aria-label="<?= h(wl_t('suchen_events')) ?>">
      <button type="submit"><?= h(wl_t('suche')) ?></button>
    </form>
  </div>
</section>

<?php wl_demo_note(true); ?>

<?php /* Drei Bedienelemente, drei Arten von Entscheidung – und jede in der Form, die zu ihr
         passt:
         • Zeitraum   – genau EINE Stufe ist aktiv, also ein Schalter mit Stufen.
         • Eigenschaft – zwei Schalter, an oder aus. Sie stehen VORNE und sind größer, weil sie
                         am häufigsten gebraucht werden.
         • Kategorie  – eine aus sieben oder keine, also ein Aufklapp-Menü. Als Reihe einzelner
                         Knöpfe füllten die sieben die halbe Leiste.
         Fünfzehn gleich aussehende Knöpfe nebeneinander müsste man einzeln lesen, um zu merken,
         dass sie gar nicht dasselbe tun. */ ?>
<div class="wl-filters">
  <div class="wl-filters-in">
    <div class="wl-seg" role="group" aria-label="Zeitraum">
      <?php
        // Der Standard („Diese Woche") steht vorn, „Demnächst" (alles Kommende, Schlüssel
        // 'alle') ganz hinten. Jede Stufe setzt NUR den Zeitraum – ausdrücklich, auch die
        // Demnächst-Stufe: Der leere Parameter gehört allein der dynamischen Landung.
        // Aktiv markiert wird der WIRKSAME Zeitraum, damit die Landung ehrlich anzeigt,
        // worauf sie sich entschieden hat.
        $zeiten = ['woche' => wl_t('zeit_woche'), 'heute' => wl_t('zeit_heute'), 'we' => wl_t('zeit_we'),
                   'monat' => wl_t('zeit_monat'), 'alle' => wl_t('zeit_alle')];
        foreach ($zeiten as $zk => $zl): ?>
        <a class="wl-seg-i<?= $zeit === $zk ? ' on' : '' ?>"
           href="<?= h(wl_url(['zeit' => $zk])) ?>"<?= $zeit === $zk ? ' aria-current="true"' : '' ?>><?= h($zl) ?></a>
      <?php endforeach; ?>
    </div>
    <span class="wl-fdiv" aria-hidden="true"></span>
    <?php /* Kategorien und Eigenschaften stecken in EINEM Behälter. Am Rechner löst der sich
             auf (display: contents) und alles steht in einer Reihe. Am Handy wird er zur
             zweiten, eigenständig schiebbaren Zeile – und dort stehen die beiden beliebten
             Schalter VORNE, damit man ohne Schieben sieht, dass es sie gibt. */ ?>
    <?php /* Die beiden Schalter stehen VOR der Kategorie und sind größer: Sie werden am
             häufigsten gebraucht. Die sieben Kategorien liegen dahinter in einem Aufklapp-Menü –
             als Reihe einzelner Knöpfe füllten sie die halbe Leiste und drängten genau die
             beiden an den Rand.

             „Alles zurücksetzen" steht NICHT hier, sondern über der Trefferliste: Es erscheint
             nur bei aktivem Filter und ließe die Leiste dadurch umbrechen. So hat sie IMMER
             dieselben Bedienelemente und damit immer dieselbe Höhe. */ ?>
    <div class="wl-frest">
      <div class="wl-fend">
        <a class="wl-chip wl-prom<?= $frei ? ' on' : '' ?>" href="<?= h(wl_url(['frei' => $frei ? '' : '1'])) ?>"
           aria-pressed="<?= $frei ? 'true' : 'false' ?>"><?= h(wl_t('filter_frei')) ?></a>
        <a class="wl-chip wl-prom<?= $bfrei ? ' on' : '' ?>" href="<?= h(wl_url(['bf' => $bfrei ? '' : '1'])) ?>"
           aria-pressed="<?= $bfrei ? 'true' : 'false' ?>"><?= h(wl_t('filter_bfrei')) ?></a>
      </div>
      <?php wl_kat_dropdown($cat, static fn (string $k): string => wl_url(['cat' => $k])); ?>
    </div>
  </div>
</div>

<?php /* Der Banner steht für sich – ohne Abschnitts-Überschrift. Er ist eine Ankündigung,
         kein Inhaltsverzeichnis-Eintrag. Er fällt weg, sobald gefiltert wird: Wer sucht, will
         Treffer sehen. */ ?>
<?php if ($banner): ?>
<div class="wl-in wl-banners">
  <?php
    /* Der Zeitraum-Kicker über dem Titel ist aus von/bis ABGELEITET – die Felder gibt es
       ohnehin (sie steuern, wann der Banner läuft), niemand pflegt eine zweite Datumszeile:
       „14.–18. Okt.", über Monatsgrenzen „28. Sep. – 4. Okt.", einseitig „ab …"/„bis …". */
    $bMon = ['', 'Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.'];
    $zeitraum = static function (string $von, string $bis) use ($bMon): string {
        $tv = $von !== '' ? strtotime($von) : false;
        $tb = $bis !== '' ? strtotime($bis) : false;
        $f  = static fn ($ts): string => (int)date('j', $ts) . '. ' . $bMon[(int)date('n', $ts)];
        if ($tv && $tb) {
            if (date('Y-m-d', $tv) === date('Y-m-d', $tb)) return $f($tv);
            if (date('Y-n', $tv) === date('Y-n', $tb)) return (int)date('j', $tv) . '.–' . $f($tb);
            return $f($tv) . ' – ' . $f($tb);
        }
        if ($tv) return 'ab ' . $f($tv);
        if ($tb) return 'bis ' . $f($tb);
        return '';
    };
  ?>
  <?php foreach ($banner as $b):
      $ziel = trim((string)$b['url']);
      $tag  = $ziel !== '' ? 'a' : 'div';
      $kick = $zeitraum(trim((string)($b['von'] ?? '')), trim((string)($b['bis'] ?? ''))); ?>
    <<?= $tag ?> class="wl-banner"<?= $ziel !== '' ? ' href="' . h($ziel) . '"' : '' ?>>
      <?php /* Bild-Weiche: erst ein MITGELIEFERTES Banner-Motiv (banners.std_bild →
               assets/wl-standard/, kommt direkt vom Webserver), sonst das Archiv-Bild
               über bild.php. */ ?>
      <?php $bStd = wl_banner_std_by_file(trim((string)($b['std_bild'] ?? ''))); ?>
      <?php if ($bStd): ?>
        <img class="wl-banner-bild" src="../assets/wl-standard/<?= h((string)$bStd['file']) ?>" alt="" width="1600" height="500">
      <?php elseif (trim((string)($b['bild'] ?? '')) !== ''): ?>
        <img class="wl-banner-bild" src="<?= h(wl_img_url((string)$b['bild'], true)) ?>" alt="" width="1600" height="400">
      <?php endif; ?>
      <div class="wl-banner-t">
        <?php if ($kick !== ''): ?><span class="wl-banner-kick"><?= h($kick) ?></span><?php endif; ?>
        <strong><?= h((string)$b['title']) ?></strong>
        <?php if (trim((string)$b['subtitle']) !== ''): ?><span class="wl-banner-sub"><?= h((string)$b['subtitle']) ?></span><?php endif; ?>
        <?php if ($ziel !== ''): ?><span class="wl-banner-cta">Mehr dazu <i aria-hidden="true">→</i></span><?php endif; ?>
      </div>
    </<?= $tag ?>>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<section class="wl-sect">
  <div class="wl-sect-h">
    <h2><?php
      if ($orgFilter)      echo 'Alles von ' . h((string)$orgFilter['name']);
      elseif ($filterAktiv) echo h(wl_text('start_treffer'));
      else                  echo h(wl_text('start_naechstes'));
    ?></h2>
    <?php // Der Ausweg gehört zur Trefferliste, nicht in die Leiste: Er erscheint nur, wenn
          // gefiltert ist, und in der Leiste hätte er sie dadurch umbrechen lassen. ?>
    <?php if ($filterAktiv): ?>
      <a class="wl-fclear" href="index.php"><?= h(wl_t('filter_weg')) ?></a>
    <?php elseif ($zeit === 'woche'): ?>
      <?php /* Die Landung zeigt nur diese Woche – hier geht es zum vollen Programm. Bei der
               Demnächst-Landung entfällt der Link: Es ist schon alles zu sehen. */ ?>
      <a class="wl-fclear" href="<?= h(wl_url(['zeit' => 'alle'])) ?>">Alle anzeigen →</a>
    <?php endif; ?>
  </div>
  <?php if ($events): ?>
    <div class="wl-grid">
      <?php foreach ($events as $e) wl_kachel($e); ?>
    </div>
  <?php else: ?>
    <?= wl_absaetze(wl_text($filterAktiv ? 'start_leer_filter' : 'start_leer'), 'wl-leer') ?>
  <?php endif; ?>
</section>

<?php /* App-Hinweis, bewusst SCHLANK: eine leise Zeile mit Knopf zur eigenen App-Seite
         (app.php) – DORT stehen Anleitung und FAQ. In der installierten App blendet das
         Skript die Zeile aus; ein Wegklick-Kreuz braucht sie in dieser Größe nicht. Eine
         große Werbekarte mit Handy-Mockup soll hier nicht stehen. */ ?>
<div class="wl-in">
  <aside class="wl-app-hinweis" id="wl-app-hinweis">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M10 5.2a2 2 0 1 1 4 0 7 7 0 0 1 4 6.3v2.6a3.5 3.5 0 0 0 1.8 3H4.2a3.5 3.5 0 0 0 1.8-3v-2.6a7 7 0 0 1 4-6.3z"/>
      <path d="M9.5 17.5v.5a2.5 2.5 0 0 0 5 0v-.5"/>
    </svg>
    <span>Erinnerungen und Neues von deinen Veranstaltern direkt aufs Handy —
      hol dir <?= wl_marke() ?> als App.</span>
    <?php /* Der Direkt-Knopf, wie in der AStA-App: Wo der Browser das
             Installieren anbietet (beforeinstallprompt – Android/Chrome & Co.), öffnet er
             den nativen Installieren-Dialog ohne Umweg über die Erklär-Seite. Überall
             sonst (iPhone!) bleibt „So geht's" der Weg – dort gibt es kein solches
             Ereignis, die Seite erklärt stattdessen den Teilen-Knopf. */ ?>
    <button type="button" class="wl-btn s p" id="wl-hinweis-install" hidden><?= h(wl_t('app_install')) ?></button>
    <a class="wl-btn s" href="app.php">So geht's</a>
  </aside>
</div>
<script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>">
(function () {
  'use strict';
  var el = document.getElementById('wl-app-hinweis');
  if (!el) return;
  // Wer die App schon nutzt, braucht den Hinweis nicht.
  if ((window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone) {
    el.hidden = true;
    return;
  }
  var frage = null;
  var knopf = document.getElementById('wl-hinweis-install');
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    frage = e;
    knopf.hidden = false;
  });
  knopf.addEventListener('click', function () {
    if (frage) { frage.prompt(); frage = null; knopf.hidden = true; }
  });
  // Frisch installiert: Die Zeile hat ihren Zweck erfüllt.
  window.addEventListener('appinstalled', function () { el.hidden = true; });
})();
</script>

<?php if ($kurse): ?>
<?php /* Die Haarlinie trennt die zwei Welten der Startseite: oben die Termine, unten die
         laufenden Kurse – ohne sie gingen die beiden ohne Kante ineinander über. */ ?>
<section class="wl-sect wl-sect-kurse">
  <div class="wl-sect-h">
    <h2><?= h(wl_text('start_kurse_titel')) ?></h2>
    <a href="kurse.php">Alle Kurse →</a>
  </div>
  <div class="wl-kurse">
    <?php foreach ($kurse as $k): ?>
      <a class="wl-ku" href="v.php?id=<?= (int)$k['id'] ?>">
        <?php /* Neben den Foto-Kacheln der Events wirken leere Tafeln kahl. Deshalb liegt auch
                 hier ein Foto UNTER der dunklen Fläche (eigenes Bild, sonst das
                 Standard-Foto der Kategorie) – als <img>, nicht als style="background", das die
                 CSP verwerfen würde. Der Abdunkler darüber steckt in .wl-ku::before; die Tafel
                 bleibt dunkel und damit klar vom Terminraster unterscheidbar. */ ?>
        <?php $kBild = trim((string)($k['bild'] ?? ''));
              $kStd  = $kBild === '' ? wl_item_std($k) : null; ?>
        <?php if ($kBild !== ''): ?>
          <img class="wl-ku-bg" src="<?= h(wl_img_url($kBild)) ?>" alt="" loading="lazy">
        <?php elseif ($kStd): ?>
          <img class="wl-ku-bg" src="<?= h(wl_standard_url($kStd)) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <?php // Dieselbe Marke wie auf den Kacheln – sonst stünde ein Kurs ohne Grund vorne. ?>
        <?php if ((int)($k['tipp'] ?? 0) === 1): ?><span class="wl-ku-tipp">Tipp</span><?php endif; ?>
        <span class="wl-ku-t"><?= h((string)$k['title']) ?></span>
        <span class="wl-ku-m">
          <?php
            $teile = [];
            if ((int)$k['termine'] > 0) $teile[] = (int)$k['termine'] . ' Termine';
            $p = wl_preis_text($k);
            if ($p !== '') $teile[] = $p;
            if ((int)$k['einstieg'] === 1) $teile[] = 'Einstieg jederzeit';
            echo h(implode(' · ', $teile) ?: (string)($k['org_name'] ?? ''));
          ?>
        </span>
        <?php if (trim((string)$k['rhythmus']) !== ''): ?>
          <span class="wl-ku-w"><?= h((string)$k['rhythmus']) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* Der Wortwechsel beim Laden – das Kernstück der Marke.
         Läuft unter der strengen CSP nur mit dem Einmal-Wert aus wl_head() (nonce). Wer „Ruhe
         bewahren" eingestellt hat, sieht direkt „läuft" und keine Bewegung. Fällt JavaScript aus,
         steht ebenfalls von Anfang an „läuft" – die Überschrift ist im HTML schon fertig. */ ?>
<script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>">
(function () {
  /* Der Zeit-Schalter ist am Handy breiter als der Bildschirm und wird seitlich geschoben.
     Ohne Nachhilfe steht er nach dem Umschalten wieder ganz links – wer „Diesen Monat" wählt,
     sähe danach eine Leiste, in der KEINE Stufe gewählt aussieht, weil die aktive rechts
     außerhalb des Ausschnitts liegt. Deshalb wird sie beim Laden in die Mitte gerückt.
     Das ist Positionierung, keine Animation – es läuft darum auch bei „Ruhe bewahren",
     VOR dem Ausstieg weiter unten. Ohne JavaScript fehlt nur diese Starthilfe. */
  var seg   = document.querySelector('.wl-seg');
  var segOn = seg ? seg.querySelector('.wl-seg-i.on') : null;
  if (seg && segOn && seg.scrollWidth > seg.clientWidth) {
    seg.scrollLeft = Math.max(0,
      segOn.offsetLeft - seg.offsetLeft - (seg.clientWidth - segOn.offsetWidth) / 2);
  }

  var marke = document.getElementById('wl-marke');
  var h1    = document.getElementById('wl-h1');
  if (!h1) return;

  /* Osterei „Landau": Erster Klick öffnet die Frage, der zweite (aufs Wort oder auf die Blase)
     führt zur Stadt; nach 5 Sekunden ohne Antwort verschwindet sie wieder. Steht VOR dem
     „Ruhe bewahren"-Ausstieg – es ist ein Wegweiser, keine Animation. */
  (function () {
    var ort = h1.querySelector('.wl-h1-ort');
    var em  = ort ? ort.querySelector('em') : null;
    if (!em) return;
    var blase = null, wecker = 0;
    /* Der Anker (position: relative via .is-pop) kommt NUR für die offene Blase an den
       Satzteil und geht danach wieder weg. Dauerhaft positioniert bekam „in Landau?" eine
       eigene Render-Ebene – und beim Ebenen-Abbau nach dem Buchstaben-Auftritt blitzte
       die linke Worthälfte kurz weg. */
    function zu() {
      if (blase) { blase.remove(); blase = null; ort.classList.remove('is-pop'); }
      clearTimeout(wecker);
    }
    function hin() { var z = <?= json_encode(wl_setting('ort_url', '')) ?>; if (z) location.href = z; }
    em.addEventListener('click', function () {
      if (blase) { hin(); return; }
      blase = document.createElement('button');
      blase.type = 'button';
      blase.className = 'wl-stadt-pop';
      blase.textContent = 'Zur Seite der Stadt? ↗';
      blase.addEventListener('click', hin);
      ort.classList.add('is-pop');
      ort.appendChild(blase);
      wecker = setTimeout(zu, 5000);
    });
  })();

  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  /* Das Zeitwort kommt IMMER herein – auch dann, wenn das Rad ausfällt. Es ist der Teil, der
     sich beim Umschalten der Ansicht wirklich ändert, und er darf nicht davon abhängen, dass
     die Marke funktioniert. Hier tritt auch „Landau" auf: Das Wort wird ERST JETZT in
     Buchstaben zerlegt (im HTML bleibt es ein Wort – für Suchmaschinen und für alle ohne
     JavaScript) und die Staffelung läuft über die CSSOM, nicht über style-Attribute. */
  function zeitwort() {
    var stadt = h1.querySelector('.wl-h1-rest em');
    if (stadt && !stadt.classList.contains('wl-h1-stadt')) {
      var txt = stadt.textContent;
      stadt.textContent = '';
      stadt.classList.add('wl-h1-stadt');
      for (var j = 0; j < txt.length; j++) {
        var b = document.createElement('span');
        b.textContent = txt.charAt(j);
        b.style.animationDelay = (100 + j * 45) + 'ms';
        stadt.appendChild(b);
      }
      /* Das Fragezeichen tritt MIT auf, als letztes Zeichen nach dem „u". Ohne das stand es
         während des ganzen Buchstaben-Auftritts allein neben dem Loch, das das noch
         unsichtbare „Landau" freihält – „… in         ?" las sich wie ein Darstellungsfehler,
         nicht wie eine Animation. Es bleibt ein eigener Span AUSSERHALB des em, damit es
         weiß bleibt (das em färbt limette). */
      var frage = stadt.nextSibling;
      if (frage && frage.nodeType === 3 && frage.textContent.trim() !== '') {
        var f = document.createElement('span');
        f.className = 'wl-h1-frage';
        f.textContent = frage.textContent;
        f.style.animationDelay = (100 + txt.length * 45) + 'ms';
        stadt.parentNode.replaceChild(f, frage);
      }
      /* Erst den Umbau verdauen lassen, DANN den Startschuss: Kommen neue Spannen und die
         auslösende Klasse im selben Durchlauf an, zündet die Animation nicht in jedem
         Browser – die Buchstaben blieben dann auf ihrer Startdeckkraft hängen. Derselbe
         Kniff wie beim Rad (void offsetWidth = erzwungener Reflow dazwischen). */
      void stadt.offsetWidth;
    }
    h1.classList.add('is-zeit');
  }

  var kasten = marke ? marke.querySelector('.wl-mark-w') : null;
  var woerter = <?= json_encode(wl_marke_woerter(), JSON_UNESCAPED_UNICODE) ?>;
  if (!marke || !kasten || woerter.length < 2) { zeitwort(); return; }

  /* TEMPO: Ankunft oder Weiterklicken?
     Beim ersten Aufruf soll die Marke in Ruhe durchlaufen – sie ist das Branding. Wer aber
     schon auf der Seite ist und nur auf „Diesen Monat" klickt, will die Ansicht sehen; derselbe
     Vorspann noch einmal in voller Länge wäre eine Bremse. Erkannt wird das an der Herkunft
     (kommt man aus demselben Verzeichnis, ist es ein Seitenwechsel) – UND an einem
     Sitzungs-Merker: Firefox liefert je nach Privatsphäre-Einstellung KEINEN Referrer,
     dann spielte trotz bloßen Zeit-Umschaltens jedes Mal die volle Anfahrt (User-Fund
     2026-08-08). Der Merker ist rein funktional wie auf der Über-Seite (sessionStorage,
     stirbt mit dem Tab – keine Einwilligung nötig).
     Der Referrer-Vergleich bleibt bewusst ein schlichter Zeichenkettenvergleich statt
     URL(): Was sich nicht prüfen lässt, soll hier nicht stehen. Passt nichts von beidem,
     läuft eben die volle Fassung – der harmlose Ausgang. */
  var basis   = location.origin + location.pathname.replace(/[^\/]*$/, '');
  var wechsel = document.referrer.indexOf(basis) === 0;
  try {
    if (sessionStorage.getItem('wlStartGesehen')) wechsel = true;
    sessionStorage.setItem('wlStartGesehen', '1');
  } catch (e) { /* ohne sessionStorage entscheidet allein der Referrer */ }

  /* ZWEI VERSCHIEDENE BEWEGUNGEN, nicht dieselbe in zwei Geschwindigkeiten.

     Bei der Ankunft rastet das Rad Wort für Wort ein („schritt"): Man soll die Wörter lesen
     können, das ist der Witz der Marke.

     Beim Weiterklicken wurde daraus zuerst dasselbe Einrasten in 90 ms – und genau das sah aus
     wie ein Schluckauf: fünf angerissene Bewegungen, die sich gegenseitig abwürgen. Deshalb ist
     es jetzt gar kein Einrasten mehr, sondern EIN einziger durchgehender Weg über zwei Wörter,
     der sanft ausläuft (schritt = 0, „ab" ist die Startstufe). Kurz, aber eine Bewegung.

     „halt" ist die Pause, nachdem das Ziel angesteuert wurde, bevor auf das echte Wort
     umgeschaltet wird. Sie MUSS mindestens so lang sein wie „dreh" – sonst schaltet es noch in
     voller Fahrt um und man sieht einen Sprung. */
  var takt = wechsel
    ? { start:   0, schritt:   0, halt: 470, dreh: '.44s', rest: '.18s', ab: Math.min(2, woerter.length - 1) }
    : { start: 420, schritt: 400, halt: 380, dreh: '.34s', rest: '.3s',  ab: woerter.length - 1 };
  marke.style.setProperty('--wl-rad-t', takt.dreh);
  h1.style.setProperty('--wl-rest-t', takt.rest);

  /* Das Rad entsteht ERST HIER. Im Quelltext steht nur „was.läuft" – sonst läse eine
     Suchmaschine in der Überschrift alle Wörter hintereinander weg. */
  function radBau() {
    var rad  = document.createElement('span');
    rad.className = 'wl-mark-rad';
    rad.setAttribute('aria-hidden', 'true');
    var roll = document.createElement('span');
    roll.className = 'wl-mark-roll';
    woerter.forEach(function (w) {
      var i = document.createElement('span');
      i.className = 'wl-mark-item';
      i.textContent = w;
      roll.appendChild(i);
    });
    rad.appendChild(roll);
    return { rad: rad, roll: roll };
  }
  function stellAuf(roll, n, weich) {
    roll.style.transition = weich ? '' : 'none';
    // Das aktive Wort steht UNTEN im zweizeiligen Ausschnitt: eine Zeile nach oben schieben.
    roll.style.transform = 'translateY(calc(var(--wl-zeile) * ' + -(n - 1) + '))';
    if (!weich) { void roll.offsetWidth; roll.style.transition = ''; }
  }

  var teile = radBau();
  var rad = teile.rad, roll = teile.roll;
  kasten.appendChild(rad);

  /* Die Spalte steht auf dem Kopf (column-reverse): von oben gezählt ist das LETZTE Wort der
     Liste – „läuft" – die 0. Das Rad startet oben und dreht auf die 0 zu, also genau auf den
     Zustand, der ohne Skript ohnehin dastünde. */
  var k = takt.ab;

  function stell(n, weich) { stellAuf(roll, n, weich); }

  h1.classList.add('is-warten');     // der Rest des Satzes wartet auf durchsichtig
  marke.classList.add('is-rad');
  stell(k, false);

  function weiter() {
    // Einrasten: Stufe für Stufe. Nur bei der Ankunft.
    if (takt.schritt > 0 && k > 0) { k--; stell(k, true); setTimeout(weiter, takt.schritt); return; }
    // Ein Weg: von der Startstufe direkt auf die 0, der Browser fährt die Strecke durch.
    if (k > 0) { k = 0; stell(0, true); }
    setTimeout(function () {
      /* Fertig. Der Wechsel vom Rad auf das echte Wort ist HART, nicht überblendet – siehe
         wl.css: Zwei halbdurchsichtige Kopien desselben Wortes ergeben eine Dichte-Delle, und
         die sah aus wie ein kurzes Wegpoppen.
         Es ändert sich KEINE Breite und keine Höhe. Deshalb rutscht
         hier nichts, und der Zeilenumbruch bleibt, wie er von Anfang an war.
         Erst jetzt kommt das Zeitwort: Der Satz ist da, und das eine Wort, das die Ansicht
         benennt, setzt sich als Letztes hinein. */
      marke.classList.remove('is-rad');
      h1.classList.remove('is-warten');
      zeitwort();
      /* Das Rad BLEIBT im DOM (unsichtbar: opacity 0, absolut, pointer-events none).
         Es wurde hier früher 400 ms später entfernt – und genau dieses späte Aufräumen
         stieß nach fertigem Auftritt noch einen Repaint der Überschrift an, bei dem die
         linke Hälfte von „Landau" für einen Augenblick wegblitzte. Ein verstecktes
         Element kostet nichts; ein Repaint zur Unzeit schon. */
    }, takt.halt);
  }
  setTimeout(weiter, takt.start);

  /* ---- Ostereier an der Wortmarke (nur die große Überschrift) --------------------------------
     Reine Spielerei auf Klick – liegt hinter dem „Ruhe bewahren"-Ausstieg oben und läuft
     deshalb nur für Leute, die Bewegung sehen wollen. */

  // „was": wächst kurz, und der Impuls hallt in den Punkt und das Wort dahinter hinein.
  var wasEl = marke.querySelector('.wl-mark-was');
  if (wasEl) wasEl.addEventListener('click', function () {
    marke.classList.remove('is-hall'); void marke.offsetWidth;   // Neustart auch bei Doppelklick
    marke.classList.add('is-hall');
    setTimeout(function () { marke.classList.remove('is-hall'); }, 950);
  });

  // Der Punkt: wird weggekickt wie ein Fußball, ein neuer fällt von oben in die Lücke.
  var punkt = marke.querySelector('.wl-mark-dot');
  var kickt = false;
  if (punkt) punkt.addEventListener('click', function () {
    if (kickt) return;
    kickt = true;
    punkt.classList.add('is-kick');
    setTimeout(function () {
      punkt.classList.remove('is-kick');
      punkt.classList.add('is-fall');
      setTimeout(function () { punkt.classList.remove('is-fall'); kickt = false; }, 600);
    }, 640);
  });

  // „läuft": nimmt sich beim Anklicken wörtlich und joggt ein paar Schritte auf der Stelle
  // (Keyframes wl-lauf in wl.css). Ein erneutes Rad an dieser Stelle wirkt gehetzt –
  // das Wort soll LAUFEN, nicht noch einmal würfeln. Während das echte Rad dreht, wird der
  // Klick geschluckt, sonst joggt ein unsichtbares Wort.
  var los = false;
  kasten.addEventListener('click', function () {
    if (los || marke.classList.contains('is-rad')) return;
    los = true;
    marke.classList.add('is-los');
    setTimeout(function () { marke.classList.remove('is-los'); los = false; }, 800);
  });
})();
</script>

<?php wl_foot(); ?>
