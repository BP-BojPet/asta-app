<?php
/**
 * Über was.läuft – die Visitenkarte der Seite.
 *
 * Drei Botschaften, in dieser Reihenfolge: die MARKE (riesig, nichts lenkt ab), der ABSENDER
 * (ein Angebot des AStA – steht deshalb schon als Zeile ÜBER der Marke), und das NETZWERK
 * (Kultur-Einrichtungen, Stadt, Hochschulgruppen, Studierendenwerk, Fachschaften).
 *
 * Die frei formulierten Sätze kommen aus dem Texte-Register (Gruppe „ueber", Verwaltung →
 * was.läuft → Texte); die Struktur – Kacheln, Zahlen, Knöpfe – steht bewusst hier im Code.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard()) exit;

/* Live-Zahlen direkt aus der Datenbank – nichts von Hand gepflegt. Die Leiste erscheint erst,
   wenn die Zahlen FÜR die Seite sprechen: Eine junge Seite mit „2 Veranstaltungen" würde sich
   damit kleiner machen, nicht größer. */
$zahl = wl_db()->query("SELECT
    (SELECT COUNT(*) FROM items WHERE status = 'live' AND kind = 'event'
        AND starts_at >= datetime('now','localtime'))                  AS events,
    (SELECT COUNT(*) FROM items WHERE status = 'live' AND kind = 'kurs') AS kurse,
    (SELECT COUNT(*) FROM orgs  WHERE active = 1)                        AS orgs")->fetch();
$zeigZahlen = (int)$zahl['orgs'] >= 3 && ((int)$zahl['events'] + (int)$zahl['kurse']) >= 5;

/* Die fünf Kacheln des Netzwerks. Titel und Untertitel bewusst fest: Sie benennen, MIT WEM
   die Seite gemacht wird – das ist Aussage, kein Fließtext. */
$partner = [
    ['ton' => 'koralle', 'titel' => 'Kultur-Einrichtungen',
     'satz' => 'Bühnen, Clubs, Kinos und Häuser der ' . wl_ort_adj() . ' Kulturszene tragen ihr Programm direkt ein.',
     'svg' => '<path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h13A1.5 1.5 0 0 1 20 8.5v1.2a2.3 2.3 0 0 0 0 4.6v1.2a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 15.5v-1.2a2.3 2.3 0 0 0 0-4.6z"/><path d="M14.5 7v10" stroke-dasharray="1.6 2.2"/>'],
    ['ton' => 'gelb', 'titel' => 'Stadt ' . wl_ort(),
     'satz' => 'Damit städtisches Programm und Studi-Leben zusammenfinden statt nebeneinanderher zu laufen.',
     'svg' => '<path d="M3.5 19.5h17M5.5 19.5v-6.5h4v6.5M14 19.5V9h4.5v10.5M16.25 9V5.8M16.25 5.8h2.2v1.7h-2.2M9.5 13V9.8l-2-1.6-2 1.6V13"/>'],
    ['ton' => 'lime', 'titel' => 'Hochschulgruppen',
     'satz' => 'Von der Theatergruppe bis zur Nachhaltigkeits-Initiative: Hier steht, was sie auf die Beine stellen.',
     'svg' => '<circle cx="8.2" cy="9" r="2.5"/><circle cx="15.8" cy="9" r="2.5"/><path d="M3.5 18.5c.6-2.9 2.4-4.4 4.7-4.4 1.4 0 2.6.6 3.4 1.7M11.9 18.5c.6-2.9 2.4-4.4 4.7-4.4s3.6 1.5 4.2 4.4"/>'],
    ['ton' => 'gelb', 'titel' => 'Studierendenwerk',
     'satz' => 'Wohnen, Mensa, Beratung, Kultur – die Angebote rund ums Studi-Leben.',
     'svg' => '<path d="M4 11.2 12 4.5l8 6.7M6.2 10v9.5h11.6V10"/><path d="M12 16.6c-1.7-1.1-2.8-2.1-2.8-3.3 0-.9.7-1.6 1.5-1.6.5 0 1 .3 1.3.8.3-.5.8-.8 1.3-.8.8 0 1.5.7 1.5 1.6 0 1.2-1.1 2.2-2.8 3.3z"/>'],
    ['ton' => 'koralle', 'titel' => 'Fachschaften',
     'satz' => 'Die Feste, Partys und Reihen deiner Fachbereiche – eingetragen von den Leuten, die sie machen.',
     'svg' => '<path d="m12 4.8-9.5 4.4L12 13.6l9.5-4.4z"/><path d="M6.6 11.5v4.2c0 1.1 2.4 2.4 5.4 2.4s5.4-1.3 5.4-2.4v-4.2M21 9.6v4.2"/>'],
];

wl_head('Über', wl_text('ueber_meta'));
wl_nav('ueber');
?>

<?php /* Die Bühne der Marke: nichts als die Marke. Die AStA-Zeile steht ÜBER ihr – wer die
         Seite aufschlägt, liest den Absender ZUERST und dann das riesige was.läuft. */ ?>
<section class="wl-uh">
  <div class="wl-in wl-uh-in">
    <?php $badge = trim(wl_text('ueber_badge')); if ($badge !== ''): ?>
      <?php /* Absender-Pille: das AStA-Logo (Original, wie in der App – keine Umfärbung),
               die Zeile aus dem Texte-Register und das Herz. Das Herz macht dasselbe wie
               beim Pat:innenprogramm: beim Öffnen aufgepustet, danach lässt es ab und zu
               kleine Herzen nach links und rechts oben davonfliegen (nur CSS, wl.css).
               Als Inline-SVG, weil die wl-Seiten keine Symbol-Schrift laden. */
            $herzSvg = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
                . '<path d="M12 21.3 10.55 20C5.4 15.4 2 12.3 2 8.5 2 5.4 4.4 3 7.5 3c1.7 0 '
                . '3.4.8 4.5 2.1C13.1 3.8 14.8 3 16.5 3 19.6 3 22 5.4 22 8.5c0 3.8-3.4 6.9-8.55 11.5z"/></svg>'; ?>
      <p class="wl-uh-badge">
        <img class="wl-uh-logo" src="<?= h(brand_url('logo-420', '../')) ?>" alt="AStA-Logo" width="34" height="24">
        <?= h($badge) ?>
        <span class="wl-uh-herz" aria-hidden="true"><?= $herzSvg ?><span class="wl-uh-herz-l"><?= $herzSvg ?></span><span class="wl-uh-herz-r"><?= $herzSvg ?></span></span>
      </p>
    <?php endif; ?>
    <h1 class="wl-uh-marke"><?= wl_marke() ?></h1>
    <?= wl_absaetze(wl_text('ueber_lead'), 'wl-uh-lead') ?>
    <a class="wl-uh-weiter" href="#angebot" aria-label="Weiterlesen: Wer dahintersteht">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="m5 9 7 7 7-7"/>
      </svg>
    </a>
  </div>
</section>

<section class="wl-ut" id="angebot">
  <div class="wl-in wl-ut-schmal">
    <div class="wl-ua">
      <div class="wl-ua-text">
        <h2 class="wl-ut-h2">Von Studis, <em>für Studis</em></h2>
        <?= wl_absaetze(wl_text('ueber_asta'), 'wl-ut-text') ?>
      </div>
      <?php /* Die Logo-Verwandlung: Das Original (Petrol) steht kurz, dann wischt die
               was.läuft-Fassung (Verlauf Gelb→Koralle, assets/logo-verlauf.png) von links
               darüber – beide Bilder liegen übereinander, der Wisch ist ein clip-path in
               wl.css. Der START ist IMMER das Original, die Schleife
               läuft schlicht endlos von vorn – ganz ohne Merker. Ohne Bewegungswunsch
               bleibt das Original stehen. Führt zur AStA-Seite. */ ?>
      <a class="wl-ua-logo" href="<?= h(wl_traeger_url()) ?>">
        <span class="wl-ua-wandel">
          <img src="<?= h(brand_url('logo', '../')) ?>" alt="Logo des <?= h(wl_traeger_kurz()) ?>" width="2388" height="1668">
          <img class="wl-ua-neu" src="<?= h(brand_url('logo-verlauf', '../')) ?>" alt="" aria-hidden="true" width="2388" height="1668">
        </span>
      </a>
    </div>
  </div>
</section>

<?php /* Das Netzwerk: dunkle Bühne wie der Hero der Startseite – hier spielt die Musik. */ ?>
<section class="wl-ut wl-ut-dunkel">
  <div class="wl-in">
    <h2 class="wl-ut-h2">Gemeinsam ist <em>mehr los</em></h2>
    <?= wl_absaetze(wl_text('ueber_partner'), 'wl-ut-text') ?>
    <div class="wl-up-gitter">
      <?php foreach ($partner as $p): ?>
        <article class="wl-up wl-up-<?= h($p['ton']) ?>">
          <span class="wl-up-i" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $p['svg'] ?></svg>
          </span>
          <h3><?= h($p['titel']) ?></h3>
          <p><?= h($p['satz']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($zeigZahlen): ?>
<section class="wl-ut">
  <div class="wl-in">
    <div class="wl-uz">
      <div class="wl-uz-z"><strong class="wl-uz-n" data-ziel="<?= (int)$zahl['events'] ?>"><?= (int)$zahl['events'] ?></strong><span>kommende Veranstaltungen</span></div>
      <div class="wl-uz-z"><strong class="wl-uz-n" data-ziel="<?= (int)$zahl['kurse'] ?>"><?= (int)$zahl['kurse'] ?></strong><span>Kurse &amp; Angebote</span></div>
      <div class="wl-uz-z"><strong class="wl-uz-n" data-ziel="<?= (int)$zahl['orgs'] ?>"><?= (int)$zahl['orgs'] ?></strong><span>eintragende Gruppen</span></div>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="wl-ut wl-ut-schluss">
  <div class="wl-in wl-ut-schmal">
    <h2 class="wl-ut-h2">Du machst was? <em>Dann läuft’s hier.</em></h2>
    <?= wl_absaetze(wl_text('ueber_mitmachen'), 'wl-ut-text') ?>
    <div class="wl-uc">
      <a class="wl-uc-voll" href="einreichen.php">Veranstaltung eintragen</a>
      <a class="wl-uc-rand" href="index.php?zeit=alle">Was demnächst läuft</a>
    </div>
  </div>
</section>

<script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>">
(function () {
  'use strict';
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  /* WIEDER-Besuch in derselben Sitzung: Der HERO-AUFTRITT (Marken-Einzug) entfällt
     (body.wl-wieder, Regeln in wl.css) – alles steht sofort da, wie es Browser mit
     Rückwärts-Cache (Safari) ohnehin machen. Firefox lädt beim Springen zwischen den
     Unterseiten neu und spielte sonst jedes Mal die volle langsame Anfahrt.
     NUR der Einzug: Die AStA-Logo-Verwandlung unten ist eine Endlos-Schleife und läuft
     immer einfach von vorn – ein Merker dafür wäre Unsinn (User, 2026-08-08). */
  try {
    if (sessionStorage.getItem('wlUeberGesehen')) document.body.classList.add('wl-wieder');
    sessionStorage.setItem('wlUeberGesehen', '1');
  } catch (e) { /* ohne sessionStorage bleibt schlicht die volle Anfahrt */ }

  if (!('IntersectionObserver' in window)) return;

  /* Abschnitte treten beim Scrollen auf. Die Klasse zum VERSTECKEN vergibt erst dieses
     Skript – ohne JavaScript (und bei „Ruhe bewahren") ist die Seite von Anfang an
     vollständig sichtbar. Der Hero bleibt außen vor, er hat seinen eigenen Auftritt. */
  var teile = document.querySelectorAll('.wl-ut');
  var io = new IntersectionObserver(function (eintraege) {
    eintraege.forEach(function (e) {
      if (e.isIntersecting) { e.target.classList.add('is-da'); io.unobserve(e.target); }
    });
  }, { rootMargin: '0px 0px -12% 0px' });
  teile.forEach(function (t) {
    var r = t.getBoundingClientRect();
    if (r.top > window.innerHeight * .82) { t.classList.add('is-wart'); io.observe(t); }
  });

  /* Die Zahlen zählen hoch, sobald man sie sieht. Im HTML steht die echte Zahl – das Skript
     nimmt sie nur kurz auf null und zählt wieder hin; fällt irgendwas aus, steht sie einfach da. */
  var zahlen = document.querySelectorAll('.wl-uz-n');
  if (zahlen.length) {
    var zio = new IntersectionObserver(function (eintraege) {
      eintraege.forEach(function (e) {
        if (!e.isIntersecting) return;
        zio.unobserve(e.target);
        var ziel = parseInt(e.target.getAttribute('data-ziel'), 10) || 0;
        var start = null;
        function tick(ts) {
          if (start === null) start = ts;
          var teil = Math.min(1, (ts - start) / 900);
          // erst schnell, dann auslaufend – wie ein Zähler, der einrastet
          var wert = Math.round(ziel * (1 - Math.pow(1 - teil, 3)));
          e.target.textContent = String(wert);
          if (teil < 1) requestAnimationFrame(tick);
        }
        e.target.textContent = '0';
        requestAnimationFrame(tick);
      });
    }, { threshold: .6 });
    zahlen.forEach(function (z) { zio.observe(z); });
  }
})();
</script>

<?php wl_foot(); ?>
