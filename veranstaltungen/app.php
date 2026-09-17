<?php
/**
 * Die was.läuft-App: EINE Seite, die erklärt, was die installierte App bringt, wie man sie
 * auf iPhone und Android auf den Startbildschirm holt – und die häufigsten Fragen dazu.
 * Hierhin verweisen der schlanke App-Hinweis der Startseite, die Fußzeile und die
 * Fehlermeldungen der Mitteilungs-Knöpfe (wl-push.js), wenn Push im Browser nicht geht.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard()) exit;

wl_head('Die was.läuft-App', 'was.läuft auf den Startbildschirm holen – mit Erinnerungen und Abos als Mitteilung.', '', true);
wl_nav();
?>

<div class="wl-det">
  <h1>was.läuft als App</h1>
  <p class="wl-lead">Kein Store, kein Konto, kostenlos: was.läuft lässt sich direkt aus dem
     Browser auf den Startbildschirm holen. Danach öffnet es sich wie eine App – und kann,
     was der Browser-Tab nicht kann.</p>

  <div class="wl-df-list">
    <div class="wl-df"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 5.2a2 2 0 1 1 4 0 7 7 0 0 1 4 6.3v2.6a3.5 3.5 0 0 0 1.8 3H4.2a3.5 3.5 0 0 0 1.8-3v-2.6a7 7 0 0 1 4-6.3z"/><path d="M9.5 17.5v.5a2.5 2.5 0 0 0 5 0v-.5"/></svg>
      <span><b>Erinnerungen</b> — „Erinnere mich" meldet sich kurz vor Beginn als Mitteilung.</span></div>
    <div class="wl-df"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.2 2.3 2.3 4.7-4.8"/></svg>
      <span><b>Abos</b> — deine Lieblings-Veranstalter melden sich, sobald sie etwas Neues eintragen.</span></div>
    <div class="wl-df"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/></svg>
      <span><b>Ein Tipp vom Startbildschirm</b> — und was.läuft ist offen, auch mit schlechtem Netz.</span></div>
  </div>

  <section class="wl-nb-abs">
    <h2 class="wl-nb-h">So installierst du sie</h2>
    <?php /* Der Android-Knopf erscheint nur, wenn der Browser das Installieren hier wirklich
             anbietet (beforeinstallprompt); in der installierten App ersetzt eine Notiz die
             Anleitung. Die Schritt-Listen stehen IMMER da – die Seite wird auch am Rechner
             gelesen und dann am Handy nachgemacht. */ ?>
    <p class="wl-note ok" id="wl-app-drin" hidden>Sieht gut aus: Du liest das gerade in der
       installierten App. Alles erledigt!</p>
    <div class="wl-btns" id="wl-app-direkt" hidden>
      <button type="button" class="wl-btn p" id="wl-app-install">Jetzt installieren</button>
    </div>

    <div class="wl-app-wege">
      <div class="wl-app-weg-block">
        <h3>iPhone &amp; iPad <span>(Safari)</span></h3>
        <ol class="wl-app-schritte">
          <li>Öffne <strong>was.läuft in Safari</strong>.</li>
          <li>Tippe auf das <span class="wl-app-step"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 14V3M8.5 6.5 12 3l3.5 3.5"/><path d="M7 10H5.5A1.5 1.5 0 0 0 4 11.5v8A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5v-8a1.5 1.5 0 0 0-1.5-1.5H17"/></svg> Teilen</span>-Symbol unten in der Mitte.</li>
          <li>Wähle <strong>„Zum Home-Bildschirm"</strong> und dann <strong>Hinzufügen</strong>.</li>
        </ol>
      </div>
      <div class="wl-app-weg-block">
        <h3>Android <span>(Chrome &amp; Co.)</span></h3>
        <ol class="wl-app-schritte">
          <li>Öffne <strong>was.läuft im Browser</strong>.</li>
          <li>Tippe oben rechts aufs <strong>⋮-Menü</strong>.</li>
          <li>Wähle <strong>„App installieren"</strong> (heißt je nach Browser auch „Zum Startbildschirm hinzufügen").</li>
        </ol>
      </div>
    </div>
  </section>

  <section class="wl-nb-abs">
    <h2 class="wl-nb-h">Häufige Fragen</h2>
    <details class="wl-klapp">
      <summary>Was kostet das? Brauche ich ein Konto?</summary>
      <p class="wl-hint">Nichts und nein. Die App ist derselbe kostenlose Dienst wie diese
         Seite – ohne Anmeldung, ohne Werbung. Erinnerungen und Abos hängen nur an deinem
         Gerät, nicht an einem Konto.</p>
    </details>
    <details class="wl-klapp">
      <summary>Warum ist sie nicht im App Store / Play Store?</summary>
      <p class="wl-hint">was.läuft ist eine sogenannte Web-App: dieselbe Seite, die sich wie
         eine App installiert. Das spart den Umweg über die Stores – keine Updates nötig, du
         hast automatisch immer den aktuellen Stand, und es bleibt für alle kostenlos.</p>
    </details>
    <details class="wl-klapp">
      <summary>Wie bekomme ich Mitteilungen?</summary>
      <p class="wl-hint">Auf einer Veranstaltung „Erinnere mich" antippen oder einen
         Veranstalter abonnieren – beim ersten Mal fragt dich das Gerät nach der Erlaubnis
         für Mitteilungen. Verwalten kannst du alles unter
         <a href="abos.php">Erinnerungen &amp; Abos</a>.
         Wichtig fürs iPhone: Mitteilungen gibt es dort <strong>nur in der installierten
         App</strong>, nicht im Safari-Tab.</p>
    </details>
    <details class="wl-klapp">
      <summary>Es kommen keine Mitteilungen an — woran liegt's?</summary>
      <p class="wl-hint">Meist an der Erlaubnis: Prüfe in den Einstellungen deines Geräts
         unter Mitteilungen, ob was.läuft erlaubt ist. Am iPhone braucht es die installierte
         App (und mindestens iOS 16.4). Manche Desktop-Browser können gar keine Mitteilungen
         oder haben sie abgeschaltet – am Handy klappt es zuverlässiger. Und: Erinnerungen
         kommen erst kurz vor Beginn, nicht sofort.</p>
    </details>
    <details class="wl-klapp">
      <summary>Wie viel Speicher braucht die App?</summary>
      <p class="wl-hint">So gut wie keinen – installiert wird nur ein Icon samt einer kleinen
         Hülle, die Inhalte kommen wie im Browser aus dem Netz.</p>
    </details>
    <details class="wl-klapp">
      <summary>Wie werde ich sie wieder los?</summary>
      <p class="wl-hint">Wie jede App: Icon gedrückt halten und entfernen. Deine Erinnerungen
         und Abos kannst du vorher unter <a href="abos.php">Erinnerungen &amp; Abos</a> mit
         einem Knopf komplett abschalten.</p>
    </details>
  </section>

  <div class="wl-btns">
    <a class="wl-btn" href="index.php">← Zu den Veranstaltungen</a>
  </div>
</div>

<script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>">
(function () {
  'use strict';
  // In der installierten App: nur die Erfolgs-Notiz. Sonst: Android-Direktknopf, wenn der
  // Browser das Installieren hier anbietet (beforeinstallprompt).
  if ((window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone) {
    document.getElementById('wl-app-drin').hidden = false;
    return;
  }
  var frage = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    frage = e;
    document.getElementById('wl-app-direkt').hidden = false;
  });
  document.getElementById('wl-app-install').addEventListener('click', function () {
    if (frage) { frage.prompt(); frage = null; }
  });
})();
</script>

<?php wl_foot(); ?>
