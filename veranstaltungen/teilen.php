<?php
/**
 * Weitersagen – die Teilen-Seite zu einem Beitrag.
 *
 * Der Gedanke: Wer sein Event hier einträgt, soll es OHNE Doppelaufwand bewerben können.
 * Diese Seite baut aus den eingetragenen Daten fertiges Material: Insta-Bilder (Story und
 * Post, mit echtem Titel, Datum, Ort, Foto und QR), einen QR-Code, den Link und einen
 * vorformulierten Begleittext – alles zum direkten Herunterladen bzw. Kopieren.
 *
 * Öffentlich für jeden LIVE-Beitrag: Es steht nichts drauf, was nicht ohnehin auf der
 * Veranstaltungsseite steht. Die Bilder entstehen im Browser (Canvas) – der Server rendert
 * keine Grafiken, und Änderungen am Beitrag sind beim nächsten Aufruf automatisch drin.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard()) exit;

$id = (int)wl_param($_GET['e'] ?? '0');
$it = wl_item($id);

if (!$it || (string)$it['status'] !== 'live') {
    wl_head('Teilen', '', '', true);
    wl_nav();
    echo '<div class="wl-in wl-leer"><h1>Hier gibt es nichts zu teilen.</h1>'
       . '<p>Diesen Beitrag gibt es nicht – oder er ist noch nicht freigegeben. '
       . 'Sobald er online ist, findet ihr hier das fertige Material.</p>'
       . '<div class="wl-btns"><a class="wl-btn p" href="index.php">Zur Übersicht</a></div></div>';
    wl_foot();
    exit;
}

$kurs = (string)$it['kind'] === 'kurs';
$org  = wl_org((int)($it['org_id'] ?? 0));
$img  = wl_image((int)($it['image_id'] ?? 0));
$std  = $img ? null : wl_item_std($it);
// Das hinterlegte Gruppen-Logo (Promo-Bereich im Login) – für die Branding-Stufe
// „Eure Marke zuerst" wandert es groß auf die Bilder.
$logo = $org ? wl_image((int)($org['logo_id'] ?? 0)) : null;

// Die Adresse des Beitrags – die SCHÖNE Kurz-Fassung (wl_share_base), die Weiterleitung
// reicht Pfad und Parameter durch.
$evUrl = wl_share_base() . '/v.php?id=' . $id;

// Datum/Ort-Zeilen einmal serverseitig bauen – dieselben Helfer wie überall auf der Seite.
// wl_datum_lang bringt Uhrzeit und Bis-Zeit schon mit („Fr 21. Aug · 20:30–23:00").
$zeile1 = $kurs
    ? (string)$it['rhythmus']
    : wl_datum_lang((string)$it['starts_at'], (string)$it['ends_at']);
$ort = trim((string)$it['ort']);

// Der Begleittext fürs Kopieren – bewusst schlicht, ohne Deko-Übertreibung.
$caption = '🗓 ' . (string)$it['title'] . "\n"
    . ($kurs ? '🔁 ' : '📅 ') . $zeile1 . "\n"
    . ($ort !== '' ? '📍 ' . $ort . "\n" : '')
    . ((int)$it['frei'] === 1 ? '🎟 Eintritt frei' . "\n" : '')
    . "\nAlle Infos & Kalender-Eintrag:\n" . $evUrl . "\n\n"
    . 'Gefunden auf was.läuft – dem Veranstaltungskalender für ' . wl_ort() . '.';

$daten = [
    'titel'  => (string)$it['title'],
    'zeile1' => $zeile1,
    'ort'    => $ort,
    'frei'   => (int)$it['frei'] === 1,
    'kurs'   => $kurs,
    'bild'   => $img ? wl_img_url((string)$img['file'], true) : ($std ? wl_standard_url($std, true) : ''),
    'url'    => $evUrl,
    'urlKurz' => preg_replace('~^https?://~', '', $evUrl),
    // Für „Eure Marke zuerst": Name + Logo der Gruppe (gleicher Ursprung – der Canvas
    // bleibt damit exportierbar).
    'orgName' => $org ? (string)$org['name'] : '',
    'orgLogo' => $logo ? wl_img_url((string)$logo['file']) : '',
];
// Vorbelegung fürs eigene QR-Ziel: die Anmeldung der Gruppe schlägt ihre Info-Seite.
$zielVorschlag = trim((string)$it['anmeldung_url']) !== ''
    ? trim((string)$it['anmeldung_url']) : trim((string)$it['url']);

wl_head('Teilen: ' . (string)$it['title'], 'Fertiges Material zum Bewerben – Bilder, QR-Code, Link und Text.', '', true);
wl_nav();
$nonce = (string)($GLOBALS['wl_nonce'] ?? '');
?>

<section class="wl-hero">
  <div class="wl-hero-in wl-in">
    <h1 class="wl-h1">Weitersagen<span class="wl-h1-rest">: <?= h((string)$it['title']) ?></span></h1>
    <p>Fertiges Material aus deinem Eintrag – nichts muss doppelt gebaut werden.
      <a class="wl-tl-zurueck" href="v.php?id=<?= $id ?>">Zum Beitrag ›</a></p>
  </div>
</section>

<div class="wl-in wl-tl">

  <section class="wl-tl-block">
    <h2>Link &amp; Begleittext</h2>
    <div class="wl-tl-zeile">
      <input type="text" id="tl-link" readonly value="<?= h($evUrl) ?>" aria-label="Link zum Beitrag">
      <button type="button" class="wl-btn" data-kopier="tl-link">Link kopieren</button>
      <button type="button" class="wl-btn p" id="tl-share" hidden>Teilen …</button>
    </div>
    <textarea id="tl-text" readonly rows="8" aria-label="Begleittext"><?= h($caption) ?></textarea>
    <div class="wl-btns"><button type="button" class="wl-btn" data-kopier="tl-text">Text kopieren</button></div>
    <p class="wl-hint">Der Text passt für Insta-Captions, WhatsApp-Gruppen und Newsletter – einfach anpassen, was nicht passt.</p>
  </section>

  <?php /* Aussehen & Ziel (Veranstalter-Paket): Das Material gibt es in
           zwei Branding-Stufen – Standard ist die was.läuft-Marke, „Eure Marke zuerst"
           stellt Logo + Name der Gruppe nach oben und was.läuft klein an den Fuß. Der
           QR-Code (und der Web-Schnipsel) kann statt auf den Beitrag auf eine eigene
           Adresse zeigen – rein im Browser, der Server speichert dazu nichts. */ ?>
  <section class="wl-tl-block">
    <h2>Aussehen &amp; Ziel</h2>
    <?php if ($org): ?>
      <div class="wl-field">
        <label>Wessen Marke zuerst?</label>
        <div class="wl-kats">
          <label class="wl-kat-pill"><input type="radio" name="tlmarke" value="wl" checked><span>was.läuft zuerst</span></label>
          <label class="wl-kat-pill"><input type="radio" name="tlmarke" value="org"><span><?= h((string)$org['name']) ?> zuerst — was.läuft klein</span></label>
        </div>
        <?php if (!$logo): ?>
          <p class="wl-hint">Noch kein Logo hinterlegt — dann steht euer Gruppenname groß.
             Das Logo pflegt ihr im <a href="einreichen.php?t=promo">Promo-Bereich eures Logins</a>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="wl-field">
      <label>QR-Code und Web-Schnipsel führen auf</label>
      <div class="wl-kats">
        <label class="wl-kat-pill"><input type="radio" name="tlziel" value="beitrag" checked><span>die Beitragsseite auf was.läuft</span></label>
        <label class="wl-kat-pill"><input type="radio" name="tlziel" value="eigene"><span>eure eigene Adresse</span></label>
      </div>
      <input type="text" inputmode="url" id="tl-ziel-url" class="wl-tl-ziel" placeholder="euer-verein.de/event" value="<?= h($zielVorschlag) ?>" hidden aria-label="Eigene Ziel-Adresse">
      <p class="wl-hint">Gilt für den QR-Code, die Adresszeile auf den Bildern und den
         Einbett-Schnipsel. Der Link und der Begleittext oben führen weiter auf den Beitrag.</p>
    </div>
  </section>

  <section class="wl-tl-block">
    <h2>Bilder für Instagram</h2>
    <p class="wl-hint">Mit deinem Titel, Datum, Foto und QR-Code – fertig gerendert, einfach herunterladen und posten.</p>
    <div class="wl-tl-bilder">
      <figure>
        <canvas id="tl-story" width="1080" height="1920"></canvas>
        <figcaption>
          <span>Story · 1080 × 1920</span>
          <button type="button" class="wl-btn p" data-lade="tl-story" data-name="story">Herunterladen</button>
        </figcaption>
      </figure>
      <figure>
        <canvas id="tl-post" width="1080" height="1080"></canvas>
        <figcaption>
          <span>Post · 1080 × 1080</span>
          <button type="button" class="wl-btn p" data-lade="tl-post" data-name="post">Herunterladen</button>
        </figcaption>
      </figure>
    </div>
  </section>

  <?php /* Für die eigene Webseite der Gruppe: quer liegendes Banner plus
           fertiger HTML-Schnipsel. Das Bild hosten die Gruppen selbst – wir liefern den
           Download und den Schnipsel, in dem nur der Bildpfad anzupassen ist. */ ?>
  <section class="wl-tl-block">
    <h2>Für eure Webseite</h2>
    <p class="wl-hint">Ein Banner im Querformat plus fertiger HTML-Schnipsel: Bild herunterladen,
       auf eure Seite hochladen und im Schnipsel beim <strong>src</strong> den Pfad zu eurem
       hochgeladenen Bild eintragen — fertig.</p>
    <div class="wl-tl-bilder">
      <figure class="wl-tl-breit">
        <canvas id="tl-web" width="1200" height="630"></canvas>
        <figcaption>
          <span>Web-Banner · 1200 × 630</span>
          <button type="button" class="wl-btn p" data-lade="tl-web" data-name="web">Herunterladen</button>
        </figcaption>
      </figure>
    </div>
    <textarea id="tl-embed" readonly rows="4" aria-label="HTML-Schnipsel zum Einbetten"></textarea>
    <div class="wl-btns"><button type="button" class="wl-btn" data-kopier="tl-embed">Schnipsel kopieren</button></div>
  </section>

  <section class="wl-tl-block">
    <h2>QR-Code</h2>
    <p class="wl-hint">Führt direkt auf den Beitrag – für Aushänge, Tischkarten oder die Leinwand.</p>
    <div class="wl-tl-zeile">
      <span class="wl-tl-qr" id="tl-qr"></span>
      <button type="button" class="wl-btn" id="tl-qr-dl">Als Bild herunterladen</button>
    </div>
  </section>

</div>

<script defer nonce="<?= h($nonce) ?>" src="../assets/qrcode.min.js"></script>
<script nonce="<?= h($nonce) ?>">
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var D = <?= json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  // ---- Kopieren + System-Teilen ---------------------------------------------------------------
  document.querySelectorAll('[data-kopier]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var feld = document.getElementById(btn.getAttribute('data-kopier'));
      if (!feld) return;
      feld.select();
      var fertig = function () {
        var alt = btn.textContent;
        btn.textContent = 'Kopiert ✓';
        setTimeout(function () { btn.textContent = alt; }, 1500);
      };
      if (navigator.clipboard) navigator.clipboard.writeText(feld.value).then(fertig, function () { document.execCommand('copy'); fertig(); });
      else { document.execCommand('copy'); fertig(); }
    });
  });
  var share = document.getElementById('tl-share');
  if (share && navigator.share) {
    share.hidden = false;
    share.addEventListener('click', function () {
      navigator.share({ title: D.titel, text: D.titel + ' – ' + D.zeile1, url: D.url }).catch(function () {});
    });
  }

  // ---- Aussehen & Ziel ------------------------------------------------------
  // wahl.marke: 'wl' = was.läuft zuerst (Standard) | 'org' = die Marke der Gruppe zuerst,
  // was.läuft klein am Fuß. wahl.ziel: wohin QR, Adresszeile und Web-Schnipsel führen –
  // Standard ist der Beitrag, wählbar eine eigene Adresse (nur http/https, rein im Browser).
  var wahl = { marke: 'wl', ziel: D.url };
  // Aufs Bild gehört die kurze Form: ohne Schema, ohne „www.", ohne Schluss-Schrägstrich.
  function zielKurz() { return wahl.ziel.replace(/^https?:\/\//i, '').replace(/^www\./i, '').replace(/\/+$/, '').slice(0, 48); }

  // ---- QR: einmal groß rendern, überall wiederverwenden; bei Zielwechsel neu ------------------
  var qrBox = document.getElementById('tl-qr');
  var qrCanvas = null;
  function qrNeu(url) {
    if (!window.QRCode || !qrBox) return;
    qrBox.textContent = '';
    var lager = document.createElement('div');
    new QRCode(lager, { text: url, width: 660, height: 660, correctLevel: QRCode.CorrectLevel.M });
    qrCanvas = lager.querySelector('canvas');
    new QRCode(qrBox, { text: url, width: 132, height: 132, correctLevel: QRCode.CorrectLevel.M });
  }
  qrNeu(wahl.ziel);
  var qrDl = document.getElementById('tl-qr-dl');
  if (qrDl) qrDl.addEventListener('click', function () {
    if (!qrCanvas) return;
    var a = document.createElement('a');
    a.href = qrCanvas.toDataURL('image/png');
    a.download = 'qr-' + dateiname() + '.png';
    document.body.appendChild(a); a.click(); a.remove();
  });

  function dateiname() {
    return (D.titel || 'beitrag').toLowerCase().replace(/[äöüß]/g, function (z) {
      return { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss' }[z];
    }).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'beitrag';
  }

  // ---- Die Bühne der Marke, gezeichnet --------------------------------------------------------
  function buehne(x, W, H) {
    x.fillStyle = '#17141c'; x.fillRect(0, 0, W, H);
    var g1 = x.createRadialGradient(W * .08, H * 1.02, 0, W * .08, H * 1.02, H * .55);
    g1.addColorStop(0, 'rgba(255,217,61,.16)'); g1.addColorStop(1, 'rgba(255,217,61,0)');
    x.fillStyle = g1; x.fillRect(0, 0, W, H);
    var g2 = x.createRadialGradient(W * .92, -H * .04, 0, W * .92, -H * .04, H * .6);
    g2.addColorStop(0, 'rgba(255,92,114,.22)'); g2.addColorStop(1, 'rgba(255,92,114,0)');
    x.fillStyle = g2; x.fillRect(0, 0, W, H);
  }
  var SCHRIFT = "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif";

  // Die Wortmarke: „was" weiß, Punkt Limette, „läuft" im Verlauf. markeLinks zeichnet ab der
  // linken Kante (und gibt die Breite zurück – für rechtsbündige Plätze), marke zentriert um
  // cx. Beide zeichnen immer linksbündig, egal welches textAlign gerade gilt.
  function markeLinks(x, lx, y, fs) {
    var alignVorher = x.textAlign;
    x.textAlign = 'left';
    x.font = '800 ' + fs + 'px ' + SCHRIFT;
    var bWas = x.measureText('was').width, bDot = x.measureText('.').width, bW = x.measureText('läuft').width;
    x.fillStyle = '#ffffff'; x.fillText('was', lx, y);
    x.fillStyle = '#d9f24b'; x.fillText('.', lx + bWas, y);
    var vg = x.createLinearGradient(lx + bWas + bDot, 0, lx + bWas + bDot + bW, 0);
    vg.addColorStop(0, '#ffd93d'); vg.addColorStop(.75, '#ff5c72');
    x.fillStyle = vg; x.fillText('läuft', lx + bWas + bDot, y);
    x.textAlign = alignVorher;
    return bWas + bDot + bW;
  }
  function markeBreite(x, fs) {
    x.font = '800 ' + fs + 'px ' + SCHRIFT;
    return x.measureText('was').width + x.measureText('.').width + x.measureText('läuft').width;
  }
  function marke(x, cx, y, fs) {
    markeLinks(x, cx - markeBreite(x, fs) / 2, y, fs);
  }

  function rundPfad(x, rx, ry, rw, rh, r) {
    x.beginPath();
    x.moveTo(rx + r, ry);
    x.arcTo(rx + rw, ry, rx + rw, ry + rh, r);
    x.arcTo(rx + rw, ry + rh, rx, ry + rh, r);
    x.arcTo(rx, ry + rh, rx, ry, r);
    x.arcTo(rx, ry, rx + rw, ry, r);
    x.closePath();
  }

  // Foto formatfüllend in eine abgerundete Fläche (cover, mittig beschnitten).
  function foto(x, bild, rx, ry, rw, rh, r) {
    rundPfad(x, rx, ry, rw, rh, r);
    x.save(); x.clip();
    var f = Math.max(rw / bild.width, rh / bild.height);
    var bw = bild.width * f, bh = bild.height * f;
    x.drawImage(bild, rx + (rw - bw) / 2, ry + (rh - bh) / 2, bw, bh);
    x.restore();
  }

  // Titel umbrechen (höchstens `max` Zeilen, letzte mit „…").
  function zeilen(x, text, breite, max) {
    var woerter = text.split(/\s+/), aus = [], zeile = '';
    for (var i = 0; i < woerter.length; i++) {
      var probe = zeile === '' ? woerter[i] : zeile + ' ' + woerter[i];
      if (x.measureText(probe).width <= breite || zeile === '') { zeile = probe; continue; }
      aus.push(zeile); zeile = woerter[i];
      if (aus.length === max - 1) break;
    }
    if (zeile !== '') {
      while (x.measureText(zeile + (i < woerter.length ? ' …' : '')).width > breite && zeile.indexOf(' ') > 0) {
        zeile = zeile.replace(/\s+\S*$/, '');
      }
      aus.push(i < woerter.length ? zeile + ' …' : zeile);
    }
    return aus;
  }

  function qrKarte(x, cx, oy, groesse) {
    if (!qrCanvas) return;
    rundPfad(x, cx - groesse / 2, oy, groesse, groesse, groesse * .08);
    x.fillStyle = '#ffffff'; x.fill();
    var qr = groesse * .82;
    x.drawImage(qrCanvas, cx - qr / 2, oy + (groesse - qr) / 2, qr, qr);
  }

  // Das Gruppen-Logo als runder Avatar mit Marken-Verlaufs-Ring – die Profilbild-Optik, die
  // jeder von Insta kennt. KEINE weiße Trägerkachel darunter: Das Logo wird formatfüllend
  // kreisrund beschnitten, zwischen Ring und Bild liegt ein schmaler Spalt in Tinte.
  function logoKreis(x, cx, cy, d) {
    var r = d / 2;
    var ring = x.createLinearGradient(cx - r, cy - r, cx + r, cy + r);
    ring.addColorStop(0, '#ffd93d'); ring.addColorStop(.75, '#ff5c72');
    x.beginPath(); x.arc(cx, cy, r + 7, 0, Math.PI * 2); x.fillStyle = ring; x.fill();
    x.beginPath(); x.arc(cx, cy, r + 3, 0, Math.PI * 2); x.fillStyle = '#17141c'; x.fill();
    x.save();
    x.beginPath(); x.arc(cx, cy, r, 0, Math.PI * 2); x.clip();
    var f = Math.max(d / logoBild.width, d / logoBild.height);
    var bw = logoBild.width * f, bh = logoBild.height * f;
    x.drawImage(logoBild, cx - bw / 2, cy - bh / 2, bw, bh);
    x.restore();
  }

  function einZeile(x, text, breite) { return zeilen(x, text, breite, 1)[0] || ''; }

  // Der Kopf je Branding-Stufe: Standard ist die was.läuft-Marke; „org" stellt Logo (oder
  // Gruppenname) nach oben. Gibt die Y-Kante zurück, ab der der Inhalt beginnt.
  function kopf(x, cx, org, mFs, mY, logoS, nameFs, breite) {
    if (!org) { marke(x, cx, mY, mFs); return null; }
    var oy = mY - mFs + 14;
    if (logoBild) { logoKreis(x, cx, oy + logoS / 2, logoS); oy += logoS + nameFs + 26; }
    else oy += nameFs + 8;
    x.font = '800 ' + nameFs + 'px ' + SCHRIFT; x.fillStyle = '#ffffff';
    x.fillText(einZeile(x, D.orgName, breite), cx, oy);
    return oy;
  }

  function malen() {
    var org = wahl.marke === 'org' && (logoBild || D.orgName);
    var kurz = zielKurz();

    // ---- Story 1080×1920 ----
    var s = document.getElementById('tl-story').getContext('2d');
    buehne(s, 1080, 1920);
    s.textAlign = 'center';
    var kante = kopf(s, 540, org, 96, 150, 170, 54, 920);
    var fotoTop = org ? kante + 56 : 230;
    var fotoH   = org ? 560 : 620;
    var titelY;
    if (evBild) { foto(s, evBild, 70, fotoTop, 940, fotoH, 30); titelY = fotoTop + fotoH + 140; }
    else titelY = org ? fotoTop + 320 : 700;
    s.font = '800 78px ' + SCHRIFT; s.fillStyle = '#ffffff';
    var tz = zeilen(s, D.titel, 920, 3);
    tz.forEach(function (z, i) { s.fillText(z, 540, titelY + i * 92); });
    var y = titelY + tz.length * 92 + 10;
    s.font = '700 52px ' + SCHRIFT; s.fillStyle = '#d9f24b';
    s.fillText(D.zeile1, 540, y); y += 70;
    if (D.ort) { s.font = '400 44px ' + SCHRIFT; s.fillStyle = '#cdc6bb'; s.fillText(D.ort, 540, y); y += 64; }
    if (D.frei) { s.font = '700 40px ' + SCHRIFT; s.fillStyle = '#d9f24b'; s.fillText('Eintritt frei', 540, y); y += 56; }
    // Bei „org" bleibt unten Platz für die kleine was.läuft-Marke – der QR rückt dafür
    // einen Tick hoch und die Adresse etwas näher an die Karte.
    var qrY = org ? Math.min(Math.max(y + 30, 1430), 1470) : Math.max(y + 30, 1460);
    qrKarte(s, 540, qrY, 300);
    s.font = '700 36px ' + SCHRIFT; s.fillStyle = '#ffffff';
    s.fillText(kurz, 540, qrY + (org ? 344 : 362));
    if (org) marke(s, 540, qrY + 400, 34);
    s.textAlign = 'start';

    // ---- Post 1080×1080: „Schlagzeilen-Plakat" ----
    // Dieselbe Design-Sprache wie das Startseiten-Banner: Foto VOLLFLÄCHIG, Tinten-Scrim von
    // unten, Text unten LINKS, die Verlaufs-Kante Gelb → Koralle als Marken-Signatur an der
    // Unterkante. Keine gestapelten Karten.
    var p = document.getElementById('tl-post').getContext('2d');
    p.textAlign = 'left';
    if (evBild) {
      var pf = Math.max(1080 / evBild.width, 1080 / evBild.height);
      p.drawImage(evBild, (1080 - evBild.width * pf) / 2, (1080 - evBild.height * pf) / 2,
                  evBild.width * pf, evBild.height * pf);
      var scU = p.createLinearGradient(0, 380, 0, 1080);
      scU.addColorStop(0, 'rgba(23,20,28,0)'); scU.addColorStop(.55, 'rgba(23,20,28,.82)');
      scU.addColorStop(1, 'rgba(23,20,28,.97)');
      p.fillStyle = scU; p.fillRect(0, 0, 1080, 1080);
      var scO = p.createLinearGradient(0, 0, 0, 280);
      scO.addColorStop(0, 'rgba(23,20,28,.66)'); scO.addColorStop(1, 'rgba(23,20,28,0)');
      p.fillStyle = scO; p.fillRect(0, 0, 1080, 280);
    } else {
      buehne(p, 1080, 1080);
    }
    var pKante = p.createLinearGradient(0, 0, 1080, 0);
    pKante.addColorStop(0, '#ffd93d'); pKante.addColorStop(.75, '#ff5c72');
    p.fillStyle = pKante; p.fillRect(0, 1068, 1080, 12);
    // Branding oben links: Marke – oder rundes Logo + Gruppenname auf Kreis-Mitte.
    if (org) {
      if (logoBild) {
        logoKreis(p, 70 + 55, 78 + 55, 110);
        p.font = '800 46px ' + SCHRIFT; p.fillStyle = '#ffffff';
        p.fillText(einZeile(p, D.orgName, 760), 70 + 110 + 40, 78 + 55 + 16);
      } else {
        p.font = '800 52px ' + SCHRIFT; p.fillStyle = '#ffffff';
        p.fillText(einZeile(p, D.orgName, 900), 70, 130);
      }
    } else {
      markeLinks(p, 70, 132, 58);
    }
    // Unten links, von der festen Unterkante hochgerechnet – so bleibt die Adresse immer
    // an ihrem Platz, egal wie viele Zeilen der Titel braucht.
    p.font = '800 76px ' + SCHRIFT;
    var pz = zeilen(p, D.titel, 940, 3);
    var pUrlY   = 1012;
    var pOrtY   = D.ort ? pUrlY - 58 : pUrlY;
    var pDatumY = pOrtY - 62;
    var pTitelUnten = pDatumY - 84;
    p.fillStyle = '#ffffff';
    pz.forEach(function (z, i) { p.fillText(z, 70, pTitelUnten - (pz.length - 1 - i) * 88); });
    // „Eintritt frei" als Limetten-Pille überm Titel – wie der Chip auf den Kacheln.
    if (D.frei) {
      var chipY = pTitelUnten - (pz.length - 1) * 88 - 76 - 56;
      p.font = '800 32px ' + SCHRIFT;
      var chipB = p.measureText('Eintritt frei').width + 56;
      rundPfad(p, 70, chipY, chipB, 58, 29);
      p.fillStyle = '#d9f24b'; p.fill();
      p.fillStyle = '#17141c'; p.fillText('Eintritt frei', 70 + 28, chipY + 40);
    }
    p.font = '700 48px ' + SCHRIFT; p.fillStyle = '#d9f24b';
    p.fillText(einZeile(p, D.zeile1, 940), 70, pDatumY);
    if (D.ort) {
      p.font = '400 38px ' + SCHRIFT; p.fillStyle = '#cdc6bb';
      p.fillText(einZeile(p, D.ort, 940), 70, pOrtY);
    }
    p.font = '700 32px ' + SCHRIFT; p.fillStyle = '#ffffff';
    p.fillText(kurz, 70, pUrlY);
    // Bei „Eure Marke zuerst" steht was.läuft klein unten rechts – gegenüber der Adresse.
    if (org) markeLinks(p, 1010 - markeBreite(p, 34), pUrlY, 34);
    p.textAlign = 'start';

    // ---- Web-Banner 1200×630 (für die eigene Seite der Gruppe) ----
    var w = document.getElementById('tl-web').getContext('2d');
    buehne(w, 1200, 630);
    w.textAlign = 'center';
    var hatFoto = !!evBild;
    var cx = hatFoto ? 370 : 600, tb = hatFoto ? 580 : 1000;
    if (hatFoto) foto(w, evBild, 700, 70, 430, 490, 26);
    var wKante = kopf(w, cx, org, 52, 104, 92, 34, tb);
    var wy = org ? wKante + 64 : 190;
    w.font = '800 56px ' + SCHRIFT; w.fillStyle = '#ffffff';
    var wz = zeilen(w, D.titel, tb, org ? 2 : 3);
    wz.forEach(function (z, i) { w.fillText(z, cx, wy + i * 66); });
    var yy = wy + wz.length * 66 + 6;
    w.font = '700 38px ' + SCHRIFT; w.fillStyle = '#d9f24b';
    w.fillText(D.zeile1 + (D.frei ? '  ·  Eintritt frei' : ''), cx, yy); yy += 52;
    if (D.ort) { w.font = '400 32px ' + SCHRIFT; w.fillStyle = '#cdc6bb'; w.fillText(einZeile(w, D.ort, tb), cx, yy); }
    w.font = '700 28px ' + SCHRIFT; w.fillStyle = '#ffffff';
    w.fillText(kurz, cx, org ? 534 : 560);
    if (org) marke(w, cx, 586, 26);
    w.textAlign = 'start';
  }

  // ---- Web-Schnipsel: Link + Bild, fertig zum Einfügen – nur der Bildpfad ist anzupassen ------
  var embed = document.getElementById('tl-embed');
  function schnipsel() {
    if (!embed) return;
    var alt = (D.titel + ' – ' + D.zeile1)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    embed.value = '<a href="' + wahl.ziel + '">\n'
      + '  <img src="waslaeuft-web-' + dateiname() + '.png" alt="' + alt + '"\n'
      + '       width="1200" height="630" style="max-width:100%;height:auto">\n'
      + '</a>';
  }
  schnipsel();

  // ---- Umschalter: Branding + Ziel ------------------------------------------------------------
  var zielFeld = document.getElementById('tl-ziel-url');
  // Gegenstück zu wl_url_norm() in PHP: Schema ergänzen, Unfug abweisen.
  function zielVoll(v) {
    v = (v || '').trim();
    if (v === '') return '';
    if (/^[a-z][a-z0-9+.-]*:/i.test(v) && !/^https?:\/\//i.test(v)) return '';
    var rest = v.replace(/^https?:\/\//i, '').replace(/^\/+/, '');
    var host = rest.split('/')[0];
    if (!/^[A-Za-z0-9._~-]+(:\d+)?$/.test(host) || host.indexOf('.') < 0) return '';
    return (/^http:\/\//i.test(v) ? 'http://' : 'https://') + rest;
  }
  function neu() {
    var m = document.querySelector('[name="tlmarke"]:checked');
    wahl.marke = m ? m.value : 'wl';
    var eigene = document.querySelector('[name="tlziel"]:checked');
    var will = !!eigene && eigene.value === 'eigene';
    if (zielFeld) zielFeld.hidden = !will;
    // „euer-verein.de/event" genügt – das Schema kommt automatisch davor (wie im Formular
    // beim Eintragen). Was auch dann keine Web-Adresse ergibt, fällt still auf den Beitrag zurück.
    wahl.ziel = will ? zielVoll(zielFeld ? zielFeld.value : '') || D.url : D.url;
    qrNeu(wahl.ziel);
    malen();
    schnipsel();
  }
  document.querySelectorAll('[name="tlmarke"], [name="tlziel"]').forEach(function (el) {
    el.addEventListener('change', neu);
  });
  if (zielFeld) zielFeld.addEventListener('input', neu);

  // Bilder laden (gleicher Ursprung, der Canvas bleibt exportierbar), dann zeichnen.
  var evBild = null, logoBild = null;
  function lade(url, cb) {
    if (!url) return cb(null);
    var b = new Image();
    b.onload = function () { cb(b); };
    b.onerror = function () { cb(null); };
    b.src = url;
  }
  lade(D.bild, function (b1) {
    evBild = b1;
    lade(D.orgLogo, function (b2) { logoBild = b2; malen(); });
  });

  document.querySelectorAll('[data-lade]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var c = document.getElementById(btn.getAttribute('data-lade'));
      if (!c) return;
      var a = document.createElement('a');
      a.href = c.toDataURL('image/png');
      a.download = 'waslaeuft-' + btn.getAttribute('data-name') + '-' + dateiname() + '.png';
      document.body.appendChild(a); a.click(); a.remove();
    });
  });
});
</script>

<?php wl_foot(); ?>
