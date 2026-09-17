<?php
/**
 * Träger dieser Installation: Name, Web-Auftritt, Kontakt und die Marke (Logo, App-Symbol).
 *
 * Warum eine eigene Seite: Diese Angaben stehen bewusst NICHT im Programm. Die App soll ein
 * anderer AStA übernehmen können, ohne eine Datei anzufassen – hier trägt er sich ein. Die
 * Texte gehen dabei in ALLE Bereiche: App, was.läuft, Terminplaner, Pat:innenprogramm,
 * Umfragen und öffentliche Anmeldung führen je eine eigene Datenbank, sollen aber denselben
 * Träger nennen. Deshalb schreibt das Formular in alle sechs.
 */
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Admin & Vorsitz

require_once __DIR__ . '/../wl-db.php';
require_once __DIR__ . '/../termin-db.php';
require_once __DIR__ . '/../pat-db.php';
require_once __DIR__ . '/../umfrage-db.php';
require_once __DIR__ . '/../extern-db.php';

/** Verzeichnis der eigenen Marken-Bilder – wird beim ersten Hochladen angelegt. */
function traeger_branding_dir(): string
{
    $d = __DIR__ . '/../data/branding';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/**
 * Ein hochgeladenes Bild als PNG in der Marken-Ablage speichern. Bewusst streng: Nur echte
 * Bilder (die Endung sagt nichts), und alles wird neu als PNG geschrieben – damit landet
 * kein fremder Dateikopf und kein eingebetteter Schadcode in der Ablage.
 */
function traeger_bild_speichern(string $name, array $datei, ?int $kante = null, ?string &$fehler = null): bool
{
    if (!in_array($name, brand_namen(), true)) { $fehler = 'Unbekannter Marken-Name.'; return false; }
    if (($datei['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return false;
    if (($datei['error'] ?? 1) !== UPLOAD_ERR_OK) { $fehler = 'Die Datei kam nicht vollständig an.'; return false; }
    if ((int)($datei['size'] ?? 0) > 5 * 1024 * 1024) { $fehler = 'Höchstens 5 MB.'; return false; }

    $tmp = (string)($datei['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) { $fehler = 'Ungültiger Upload.'; return false; }
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        $fehler = 'Bitte ein PNG, JPEG oder WebP hochladen.';
        return false;
    }
    $bild = match ($info[2]) {
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        default        => @imagecreatefromwebp($tmp),
    };
    if (!$bild) { $fehler = 'Das Bild ließ sich nicht lesen.'; return false; }

    if ($kante !== null && ($info[0] > $kante || $info[1] > $kante)) {
        $skaliert = imagescale($bild, $kante, -1, IMG_BILINEAR_FIXED);
        if ($skaliert) { imagedestroy($bild); $bild = $skaliert; }
    }
    imagealphablending($bild, false);
    imagesavealpha($bild, true);           // Transparenz erhalten – das Logo steht auf farbigem Grund
    $ok = imagepng($bild, traeger_branding_dir() . '/' . $name . '.png');
    imagedestroy($bild);
    if (!$ok) $fehler = 'Das Bild ließ sich nicht speichern.';
    return $ok;
}

/** App-Symbol in den drei gebrauchten Größen ablegen (ein Upload, drei Dateien). */
function traeger_symbol_speichern(array $datei, ?string &$fehler = null): int
{
    $wie = 0;
    foreach ([['icon-512', 512], ['icon-192', 192], ['icon-180', 180]] as $paar) {
        // Für jede Größe braucht imagecreatefrom* die Ursprungsdatei erneut – der Upload
        // liegt bis zum Ende der Anfrage im tmp-Verzeichnis, das geht also.
        if (traeger_bild_speichern($paar[0], $datei, $paar[1], $fehler)) $wie++;
    }
    return $wie;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $aktion = (string)($_POST['action'] ?? '');

    if ($aktion === 'save_texte') {
        $lang    = trim((string)($_POST['org_name'] ?? ''));
        $kurz    = trim((string)($_POST['org_name_kurz'] ?? ''));
        $url     = rtrim(trim((string)($_POST['org_url'] ?? '')), '/');
        $mail    = trim((string)($_POST['org_kontakt_mail'] ?? ''));

        if ($lang === '' || $kurz === '') {
            flash('Name (lang und kurz) darf nicht leer bleiben – er steht in jeder Kopfzeile.', 'error');
            redirect('traeger.php');
        }
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            flash('Die Kontaktadresse ist keine gültige E-Mail-Adresse.', 'error');
            redirect('traeger.php');
        }

        setting_set('org_name', $lang);
        setting_set('org_name_kurz', $kurz);
        setting_set('org_url', $url);
        setting_set('org_kontakt_mail', $mail);
        // Der Ort steht auch in der App-Datenbank: Die erzeugten Dokumente tragen ihn als
        // Ortszeile über dem Datum, und die kennen die was.läuft-Einstellungen nicht.
        if (array_key_exists('ort', $_POST)) setting_set('org_ort', trim((string)$_POST['ort']));

        // Dieselben Angaben in die Bereiche mit eigener Datenbank. Sie lesen sie dort selbst,
        // weil sie die lib.php nicht einbinden dürfen.
        wl_setting_set('traeger_name', $lang);
        wl_setting_set('traeger_kurz', $kurz);
        wl_setting_set('traeger_url', $url);
        tplan_setting_set('traeger_name', $kurz);
        tplan_setting_set('traeger_name_lang', $lang);
        tplan_setting_set('traeger_url', $url);
        pat_setting_set('traeger_name', $kurz);
        if ($mail !== '') pat_setting_set('kontakt_mail', $mail);
        umfrage_setting_set('traeger_name', $kurz);
        extern_setting_set('traeger_name', $kurz);

        // Angaben, die nur was.läuft braucht: Impressum, Datenschutz, Kurz-Adresse.
        foreach (['traeger_recht', 'traeger_anschrift', 'kontakt_recht', 'kontakt_datenschutz',
                  'aufsichtsbehoerde', 'share_base', 'push_kontakt_mail', 'uid_domain',
                  'ort', 'ort_adj', 'ort_lang', 'ort_url'] as $wlFeld) {
            if (array_key_exists($wlFeld, $_POST)) wl_setting_set($wlFeld, trim((string)$_POST[$wlFeld]));
        }

        flash('Träger gespeichert – in allen Bereichen.', 'success');
        redirect('traeger.php');
    }

    if ($aktion === 'save_marke') {
        $meldungen = [];
        foreach ([['logo-app', 'Kopf-Logo (App)', null], ['logo-header', 'Kopf-Logo (öffentlich)', null], ['logo', 'Großes Logo', null],
                  ['logo-420', 'Kleines Logo', null], ['logo-verlauf', 'Logo im was.läuft-Verlauf', null]] as $feld) {
            $fehler = null;
            if (traeger_bild_speichern($feld[0], $_FILES[$feld[0]] ?? [], $feld[2], $fehler)) {
                $meldungen[] = $feld[1] . ' gespeichert';
            } elseif ($fehler !== null) {
                flash($feld[1] . ': ' . $fehler, 'error');
                redirect('traeger.php');
            }
        }
        $fehler = null;
        if (($_FILES['icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $n = traeger_symbol_speichern($_FILES['icon'], $fehler);
            if ($n > 0) $meldungen[] = 'App-Symbol in ' . $n . ' Größen gespeichert';
            elseif ($fehler !== null) { flash('App-Symbol: ' . $fehler, 'error'); redirect('traeger.php'); }
        }
        flash($meldungen ? implode(' · ', $meldungen) . '.' : 'Nichts ausgewählt – es wurde nichts geändert.',
              $meldungen ? 'success' : 'info');
        redirect('traeger.php');
    }

    if ($aktion === 'reset_marke') {
        $name = (string)($_POST['name'] ?? '');
        $eigen = brand_eigen($name);
        if ($eigen !== null && @unlink($eigen)) flash('Zurückgesetzt – es gilt wieder der mitgelieferte Platzhalter.', 'success');
        else flash('Da war nichts zum Zurücksetzen.', 'info');
        redirect('traeger.php');
    }
}

page_header('Träger', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-building-community" style="color:var(--petrol)"></i> Träger</h1>
</div>
<p class="small muted" style="margin-top:-.4rem">Wer diese App betreibt. Die Angaben stehen in jeder Kopfzeile,
  in den Mails und in den öffentlichen Bereichen – und nicht im Programm, damit sie ohne Eingriff änderbar bleiben.</p>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-signature"></i> Name &amp; Kontakt</div>
  <form method="post" action="traeger.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_texte">
    <div class="field-row">
      <div>
        <label for="org_name">Name, ausgeschrieben</label>
        <input type="text" name="org_name" id="org_name" value="<?= h(org_name()) ?>" required>
        <p class="small muted" style="margin:.25rem 0 0">Steht als Herausgeber in den Auszeichnungen für Suchmaschinen.</p>
      </div>
      <div>
        <label for="org_name_kurz">Name, kurz</label>
        <input type="text" name="org_name_kurz" id="org_name_kurz" value="<?= h(org_name_kurz()) ?>" required>
        <p class="small muted" style="margin:.25rem 0 0">Die Fassung für Kopfzeilen und Seitentitel.</p>
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="org_url">Web-Auftritt</label>
        <input type="text" name="org_url" id="org_url" value="<?= h(org_url()) ?>" placeholder="https://…">
      </div>
      <div>
        <label for="org_kontakt_mail">Kontaktadresse</label>
        <input type="email" name="org_kontakt_mail" id="org_kontakt_mail" value="<?= h(org_kontakt_mail()) ?>">
        <p class="small muted" style="margin:.25rem 0 0">Für Rückmeldungen aus der App und als Absenderkontakt gegenüber den Push-Diensten.</p>
      </div>
    </div>
    <div class="btn-row" style="margin-top:.8rem">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
    </div>
  </form>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-map-pin"></i> Ort</div>
  <p class="small muted" style="margin-top:0">Das Veranstaltungsportal fragt „was.läuft &hellip; in &lt;Ort&gt;?".
    Der Name <strong>was.läuft</strong> gehört zum Angebot und bleibt; der Ort ist eure Sache.</p>
  <form method="post" action="traeger.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_texte">
    <input type="hidden" name="org_name" value="<?= h(org_name()) ?>">
    <input type="hidden" name="org_name_kurz" value="<?= h(org_name_kurz()) ?>">
    <input type="hidden" name="org_url" value="<?= h(org_url()) ?>">
    <input type="hidden" name="org_kontakt_mail" value="<?= h(org_kontakt_mail()) ?>">
    <div class="field-row">
      <div>
        <label for="ort">Ort</label>
        <input type="text" name="ort" id="ort" value="<?= h(wl_ort()) ?>">
        <p class="small muted" style="margin:.25rem 0 0">Steht in der großen Überschrift.</p>
      </div>
      <div>
        <label for="ort_adj">Als Eigenschaftswort</label>
        <input type="text" name="ort_adj" id="ort_adj" value="<?= h(wl_ort_adj()) ?>">
        <p class="small muted" style="margin:.25rem 0 0">„die &hellip; Kulturszene". Wird aus dem Ort gebildet; hier nur ändern, wenn das schiefgeht.</p>
      </div>
      <div>
        <label for="ort_url">Website der Stadt</label>
        <input type="url" name="ort_url" id="ort_url" value="<?= h(wl_setting('ort_url', '')) ?>" placeholder="https://…">
        <p class="small muted" style="margin:.25rem 0 0">Ziel des Ostereis an der Überschrift. Leer = kein Sprung.</p>
      </div>
      <div>
        <label for="ort_lang">Ausgeschrieben</label>
        <input type="text" name="ort_lang" id="ort_lang" value="<?= h(wl_ort_lang()) ?>">
        <p class="small muted" style="margin:.25rem 0 0">Für Suchmaschinen, z.&nbsp;B. mit Regionszusatz.</p>
      </div>
    </div>
    <div class="btn-row" style="margin-top:.8rem">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
    </div>
  </form>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-scale"></i> Impressum, Datenschutz &amp; Adressen</div>
  <p class="small muted" style="margin-top:0">Diese Angaben stehen in den Rechtstexten des Veranstaltungsportals.
    Sie sind vorbelegt mit dem, was bisher fest im Programm stand – <strong>einmal speichern</strong>, dann stehen sie
    in der Datenbank und nicht mehr im Quelltext.</p>
  <form method="post" action="traeger.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_texte">
    <input type="hidden" name="org_name" value="<?= h(org_name()) ?>">
    <input type="hidden" name="org_name_kurz" value="<?= h(org_name_kurz()) ?>">
    <input type="hidden" name="org_url" value="<?= h(org_url()) ?>">
    <input type="hidden" name="org_kontakt_mail" value="<?= h(org_kontakt_mail()) ?>">
    <div class="field-row">
      <div>
        <label for="traeger_recht">Rechtliche Bezeichnung</label>
        <input type="text" name="traeger_recht" id="traeger_recht" value="<?= h(wl_traeger_recht()) ?>">
        <p class="small muted" style="margin:.25rem 0 0">Die vollständige Form, wie sie ins Impressum gehört.</p>
      </div>
      <div>
        <label for="traeger_anschrift">Anschrift</label>
        <input type="text" name="traeger_anschrift" id="traeger_anschrift" value="<?= h(wl_traeger_anschrift()) ?>">
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="kontakt_recht">Kontakt (Impressum)</label>
        <input type="email" name="kontakt_recht" id="kontakt_recht" value="<?= h(wl_kontakt_recht()) ?>">
      </div>
      <div>
        <label for="kontakt_datenschutz">Kontakt (Datenschutz)</label>
        <input type="email" name="kontakt_datenschutz" id="kontakt_datenschutz" value="<?= h(wl_kontakt_datenschutz()) ?>">
      </div>
    </div>
    <div>
      <label for="aufsichtsbehoerde">Zuständige Datenschutz-Aufsichtsbehörde</label>
      <input type="text" name="aufsichtsbehoerde" id="aufsichtsbehoerde" value="<?= h(wl_aufsichtsbehoerde()) ?>">
      <p class="small muted" style="margin:.25rem 0 0">Ein Satzteil, wie er nach „Zuständig ist" steht – mit Anschrift.</p>
    </div>
    <div class="field-row" style="margin-top:.6rem">
      <div>
        <label for="share_base">Kurz-Adresse fürs Teilen und Drucken</label>
        <input type="text" name="share_base" id="share_base" value="<?= h(wl_share_base()) ?>">
        <p class="small muted" style="margin:.25rem 0 0">Leer lassen, wenn es keine Weiterleitung gibt – dann gilt die lange Adresse.</p>
      </div>
      <div>
        <label for="push_kontakt_mail">Kontakt gegenüber den Push-Diensten</label>
        <input type="email" name="push_kontakt_mail" id="push_kontakt_mail" value="<?= h(wl_setting('push_kontakt_mail', '')) ?>">
      </div>
    </div>
    <div style="margin-top:.6rem">
      <label for="uid_domain">Kalender-Kennung</label>
      <input type="text" name="uid_domain" id="uid_domain" value="<?= h(wl_uid_domain()) ?>">
      <p class="small muted" style="margin:.25rem 0 0"><strong>Bitte nicht ändern.</strong> Sie steckt in der Kennung
        jedes Termins im Kalender-Abo. Eine Änderung lässt alle abonnierten Kalender sämtliche Termine ein zweites Mal anlegen.</p>
    </div>
    <div class="btn-row" style="margin-top:.8rem">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-photo"></i> Marke</div>
  <p class="small muted" style="margin-top:0">Logo und App-Symbol liegen in <code>data/branding/</code> – dort
    überstehen sie jedes Einspielen und wandern nie in eine Weitergabe der App. Solange nichts hochgeladen ist,
    gilt der mitgelieferte Platzhalter. PNG mit Transparenz ist am besten; JPEG und WebP gehen auch.</p>
  <form method="post" action="traeger.php" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_marke">
    <table class="tbl">
      <thead><tr><th>Bild</th><th>Wo es erscheint</th><th>Stand</th><th>Neu wählen</th></tr></thead>
      <tbody>
      <?php
      $felder = [
        ['logo-app',     'Kopf-Logo (App)',           'Titelleiste der App – ein kompakter Zuschnitt, im Kasten 54 × 38'],
        ['logo-header',  'Kopf-Logo (öffentlich)',    'Kopfzeile der öffentlichen Bereiche, Browser-Symbol, Mails'],
        ['logo',         'Großes Logo',               'Über-Seite von was.läuft'],
        ['logo-420',     'Kleines Logo',              'Absender-Pille von was.läuft, Vorlagen-Generator'],
        ['logo-verlauf', 'Logo im was.läuft-Verlauf', 'Verwandlungs-Schleife auf der Über-Seite'],
      ];
      foreach ($felder as $f): ?>
        <tr>
          <td>
            <img src="<?= h(brand_url($f[0])) ?>" alt="" style="max-height:38px;max-width:120px;vertical-align:middle">
            <div class="small muted"><?= h($f[1]) ?></div>
          </td>
          <td class="small"><?= h($f[2]) ?></td>
          <td class="small">
            <?php if (brand_hat_eigenes($f[0])): ?>
              <span class="ok"><i class="ti ti-circle-check"></i> eigenes Bild</span>
            <?php else: ?>
              <span class="muted">Platzhalter</span>
            <?php endif; ?>
          </td>
          <td><input type="file" name="<?= h($f[0]) ?>" accept="image/png,image/jpeg,image/webp"></td>
        </tr>
      <?php endforeach; ?>
        <tr>
          <td>
            <img src="<?= h(brand_url('icon-512')) ?>" alt="" style="max-height:38px;max-width:38px;vertical-align:middle">
            <div class="small muted">App-Symbol</div>
          </td>
          <td class="small">Symbol der installierten App und Vorschaubild geteilter Links.
            Ein quadratisches Bild genügt – es wird in 512, 192 und 180 Pixeln abgelegt.</td>
          <td class="small">
            <?php if (brand_hat_eigenes('icon-512')): ?>
              <span class="ok"><i class="ti ti-circle-check"></i> eigenes Bild</span>
            <?php else: ?>
              <span class="muted">Platzhalter</span>
            <?php endif; ?>
          </td>
          <td><input type="file" name="icon" accept="image/png,image/jpeg,image/webp"></td>
        </tr>
      </tbody>
    </table>
    <div class="btn-row" style="margin-top:.8rem">
      <button class="btn" type="submit"><i class="ti ti-upload"></i> Hochladen</button>
    </div>
  </form>

  <?php $eigene = array_values(array_filter(brand_namen(), 'brand_hat_eigenes')); ?>
  <?php if ($eigene): ?>
    <div class="section-title"><i class="ti ti-arrow-back-up"></i> Zurücksetzen</div>
    <p class="small muted" style="margin-top:0">Löscht das eigene Bild; danach gilt wieder der Platzhalter aus dem Programm.</p>
    <div class="btn-row">
      <?php foreach ($eigene as $n): ?>
        <form method="post" action="traeger.php" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_marke">
          <input type="hidden" name="name" value="<?= h($n) ?>">
          <button class="btn secondary" type="submit"><i class="ti ti-trash"></i> <?= h($n) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
page_footer();
