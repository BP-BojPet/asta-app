<?php
/**
 * Startseite des öffentlichen Terminplaners.
 *
 * Ziel dieser Seite: In fünf Sekunden soll klar sein, was das ist, dass es nichts kostet und
 * wo man klickt. Alles Weitere steht danach.
 */
require __DIR__ . '/termin-lib.php';

if (tplan_offline_guard()) exit;

// „Vergiss mich": Der Knopf unten in der Geräte-Liste. Vor jeder Ausgabe, sonst geht kein
// Cookie mehr raus.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && tplan_param($_POST['action'] ?? '') === 'forget') {
    tplan_check_csrf();
    tplan_forget();
    header('Location: index.php?weg=1');
    exit;
}

$neuOffen = tplan_new_allowed();

/*
 * Was dieses Gerät kennt. Im Cookie stehen nur die Schlüssel – die Titel holen wir frisch aus
 * der Datenbank. So steht hier nie der Name einer Umfrage, die längst gelöscht ist, und
 * Umbenennungen sind sofort zu sehen.
 */
$meine = [];
foreach (tplan_mine('polls') as $k) {
    $p = tplan_by_admin_key((string)$k);
    if ($p) $meine[(string)$k] = (string)$p['title'];
}
$antworten = [];
foreach (tplan_mine('entries') as $slug => $tok) {
    $p = tplan_by_slug((string)$slug);
    if ($p) $antworten[(string)$slug . '|' . (string)$tok] = (string)$p['title'];
}

tplan_head('Terminplaner – gemeinsamen Termin finden, ohne Konto',
    'Kostenloser Terminplaner des ' . tplan_traeger() . ': gemeinsamen Termin finden, ohne Konto, ohne Werbung.',
    false, 'pat-body cinema');
?>
<?php
/* Strukturierte Daten – nur hier, denn nur diese Seite gehoert in einen Index. Als
   WebApplication und nicht als WebSite: Es IST ein Werkzeug, und die kostenlose Nutzung
   (offers/price 0) ist genau das, was jemand sucht, der eine Doodle-Alternative braucht. */
$tpBasis = tplan_base_url();
if ($tpBasis !== '') {
    /* Zwei Schreibweisen, weil die Auszeichnung sie bislang so führt: ausgeschrieben als
       Herausgeber, kurz im Brotkrumen-Pfad. Beide als Einstellung, Vorgabe wie gehabt. */
    $tpTraegerName = tplan_setting_get('traeger_name_lang', tplan_traeger());
    $tpTraegerKurz = tplan_traeger();
    $tpTraegerUrl  = tplan_traeger_url();
    $tpSeite = $tpBasis . '/termin/';
    $tpGraph = [
        [
            '@type' => 'WebApplication',
            '@id'   => $tpSeite . '#tool',
            'name'  => 'Terminplaner des ' . tplan_traeger(),
            'url'   => $tpSeite,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem'     => 'Web',
            'browserRequirements' => 'Beliebiger Browser, kein Konto nötig',
            'inLanguage'          => 'de',
            'description' => 'Gemeinsamen Termin finden – ohne Konto, ohne Werbung, kostenlos.',
            'isAccessibleForFree' => true,
            'featureList' => [
                'Terminumfrage ohne Konto und ohne Anmeldung anlegen',
                'Teilnahme über einen Link, Antworten später änderbar',
                'Ort und Höchstzahl je Vorschlag festlegen',
                'Ergebnis wahlweise anonym oder erst nach Fristende sichtbar',
                'Endet mit einem festgelegten Termin samt Kalender-Datei',
            ],
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
            'publisher' => ['@type' => 'Organization', 'name' => $tpTraegerName,
                            'url' => $tpTraegerUrl],
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id'   => $tpSeite . '#weg',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => $tpTraegerKurz,
                 'item' => $tpTraegerUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Terminplaner'],
            ],
        ],
    ];
    /* Die Antworten stammen aus derselben Quelle wie die Kaesten weiter unten – ausgezeichnete
       und sichtbare Fassung muessen wortgleich sein, sonst wertet Google die Auszeichnung ab. */
    $tpFragen = [];
    foreach (tplan_faq() as $tpF) {
        $tpFragen[] = ['@type' => 'Question', 'name' => $tpF['f'],
                       'acceptedAnswer' => ['@type' => 'Answer', 'text' => $tpF['a']]];
    }
    $tpGraph[] = ['@type' => 'FAQPage', '@id' => $tpSeite . '#fragen', 'mainEntity' => $tpFragen];

    echo '<script type="application/ld+json" nonce="' . h(tplan_nonce()) . '">'
       . json_encode(['@context' => 'https://schema.org', '@graph' => $tpGraph],
                     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
       . '</script>' . "\n";
}
?>
<section class="pat-hero">
  <div class="pat-in">
    <p class="pat-hero-kicker"><?= h(tplan_traeger()) ?></p>
    <span class="pat-hero-chip"><i class="ti ti-gift"></i> kostenlos, für alle</span>
    <h1>Findet euren Termin.</h1>
    <p class="pat-hero-lead"><?= h(tplan_text('start_lead')) ?></p>
  </div>
</section>

<div class="pat-in">
  <?php if (!empty($_GET['weg'])): ?>
    <div class="card tp-ok"><p class="tp-ok-t"><i class="ti ti-circle-check"></i>
      Dieses Gerät hat alles vergessen. Deine Umfragen und Antworten gibt es weiter – du kommst nur noch über
      deine Links hin.</p></div>
  <?php endif; ?>
  <div class="tp-start">
    <?php if ($neuOffen): ?>
      <a class="btn tp-cta" href="neu.php"><i class="ti ti-calendar-plus"></i> Terminumfrage anlegen</a>
      <p class="um-klein tp-cta-note">Dauert eine Minute. Kein Konto, keine Anmeldung, keine Mailadresse nötig.</p>
    <?php else: ?>
      <div class="card um-fehlerkarte">
        <p class="um-fehler"><i class="ti ti-alert-triangle"></i> Neue Terminumfragen sind gerade pausiert.</p>
        <p class="um-klein">Bereits angelegte Umfragen laufen normal weiter – ihre Links funktionieren.</p>
      </div>
    <?php endif; ?>
  </div>

  <div class="tp-feats">
    <div class="tp-feat">
      <span class="tp-feat-ico"><i class="ti ti-user-off"></i></span>
      <h2>Ohne Konto</h2>
      <p>Umfrage anlegen, Link verschicken, fertig. Wer abstimmt, trägt einen Namen ein – mehr nicht.</p>
    </div>
    <div class="tp-feat">
      <span class="tp-feat-ico"><i class="ti ti-adjustments"></i></span>
      <h2>Passt sich an</h2>
      <p>Mit oder ohne „Vielleicht", Ergebnis offen oder bis zum Schluss verdeckt, Frist, Höchstzahl je Termin.</p>
    </div>
    <div class="tp-feat">
      <span class="tp-feat-ico"><i class="ti ti-calendar-check"></i></span>
      <h2>Endet mit einer Entscheidung</h2>
      <p>Am Ende legt ihr den Termin fest. Alle sehen ihn oben auf der Seite und holen ihn sich in den Kalender.</p>
    </div>
    <div class="tp-feat">
      <span class="tp-feat-ico"><i class="ti ti-device-mobile"></i></span>
      <h2>Am Handy brauchbar</h2>
      <p>Keine Tabelle, durch die man seitwärts scrollen muss. Ein Termin, drei Knöpfe, weiter.</p>
    </div>
    <div class="tp-feat">
      <span class="tp-feat-ico"><i class="ti ti-shield-lock"></i></span>
      <h2>Sparsam mit Daten</h2>
      <p>Keine Werbung, keine Verfolgung, keine Weitergabe. Alte Umfragen löschen sich von selbst.</p>
    </div>
  </div>

  <?php if ($meine || $antworten): ?>
    <div class="card tp-mine">
      <h2><i class="ti ti-history"></i> Von diesem Gerät</h2>
      <p class="um-klein">Dieser Browser merkt sich <strong>30 Tage lang</strong>, welche Umfragen und Antworten dir gehören –
         und deinen Namen, damit du ihn nicht jedes Mal neu tippst. Gespeichert wird nur, was du selbst eingetragen hast.
         Der eigentliche Zugang bleiben die Links.</p>
      <?php if ($meine): ?>
        <h3 class="tp-mine-h">Deine Terminumfragen</h3>
        <ul class="tp-mine-list">
          <?php foreach ($meine as $k => $titel): ?>
            <li><a href="verwalten.php?k=<?= h(rawurlencode((string)$k)) ?>"><i class="ti ti-settings"></i> <?= h($titel) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($antworten): ?>
        <h3 class="tp-mine-h">Deine Antworten</h3>
        <ul class="tp-mine-list">
          <?php foreach ($antworten as $k => $titel): $teile = explode('|', (string)$k, 2); ?>
            <li><a href="t.php?t=<?= h(rawurlencode($teile[0])) ?>&amp;e=<?= h(rawurlencode($teile[1] ?? '')) ?>"><i class="ti ti-pencil"></i> <?= h($titel) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <form method="post" class="tp-vergiss">
        <?= tplan_csrf_field() ?><input type="hidden" name="action" value="forget">
        <button class="btn secondary small" type="submit"><i class="ti ti-eraser"></i> Dieses Gerät vergessen</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="card tp-how">
    <h2><i class="ti ti-help-circle"></i> Häufige Fragen</h2>
    <?php foreach (tplan_faq() as $tpF): ?>
    <details>
      <summary><?= h($tpF['f']) ?></summary>
      <p><?= h($tpF['a']) ?></p>
    </details>
    <?php endforeach; ?>
  </div>

  <p class="um-klein tp-privacy"><?= h(tplan_text('privacy_note')) ?></p>
</div>
<?php
tplan_foot();
