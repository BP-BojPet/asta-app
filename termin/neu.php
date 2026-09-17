<?php
/**
 * Terminumfrage anlegen.
 *
 * Ein einziges Formular, absichtlich ohne Schritt-für-Schritt-Strecke: Wer einen Termin sucht,
 * will nicht durch vier Seiten klicken. Das Nötige steht oben, alles Feine liegt aufgeklappt
 * darunter – mit Vorgaben, die für die allermeisten Fälle passen.
 *
 * Ohne JavaScript funktioniert die Seite vollständig: Die Datums- und Uhrzeitfelder stehen im
 * Quelltext als `type="date"` bzw. `type="time"` da, der Wähler der App macht daraus erst beim
 * Start Textfelder. Fällt das Skript aus, bleibt der Browser-Wähler übrig. Aus demselben Grund
 * sind von Anfang an mehrere leere Zeilen da – der Knopf für weitere braucht JavaScript.
 */
require __DIR__ . '/termin-lib.php';

if (tplan_offline_guard()) exit;

if (!tplan_new_allowed()) {
    tplan_head('Gerade pausiert', '', true, 'pat-body cinema');
    echo '<div class="pat-in"><div class="tp-col"><div class="card"><h1>Neue Terminumfragen sind pausiert</h1>'
       . '<p>Bereits angelegte Umfragen laufen weiter. Bitte versuch es später noch einmal.</p>'
       . '<p class="pat-back"><a href="index.php">‹ Zur Startseite</a></p></div></div></div>';
    tplan_foot();
    exit;
}

$fehler  = '';
$gesendet = $_SERVER['REQUEST_METHOD'] === 'POST';
$lim = tplan_limits();

if ($gesendet) {
    tplan_check_csrf();
    if (!empty($_POST['website'])) {           // Honigtopf: Menschen füllen das nie aus
        // Nicht widersprechen, nur nichts tun – ein ehrliches „erkannt" wäre eine Anleitung.
        header('Location: index.php');
        exit;
    } elseif (!tplan_rate_ok('new', 6)) {
        $fehler = 'Von hier wurden gerade sehr viele Terminumfragen angelegt. Bitte in einer Stunde noch einmal versuchen.';
    } else {
        $r = tplan_create($_POST);
        if (!$r['ok']) {
            $fehler = (string)$r['msg'];
        } else {
            $poll = (array)$r['poll'];
            tplan_remember('polls', (string)$poll['admin_key'], (string)$poll['title']);
            // Die Link-Mail ist freiwillig und darf nie den Erfolg gefährden: Die Links stehen
            // auf der nächsten Seite ohnehin.
            $mailOk = trim((string)$poll['contact']) !== '' && tplan_mail_links($poll);
            header('Location: verwalten.php?k=' . rawurlencode((string)$poll['admin_key']) . '&neu=1' . ($mailOk ? '&mail=1' : ''));
            exit;
        }
    }
}

/** Wert aus dem letzten Versuch (nach einem Fehler soll nichts verloren gehen). */
$v = fn(string $k, string $vorgabe = ''): string => $gesendet ? tplan_param($_POST[$k] ?? '') : $vorgabe;
/** Kästchen: vor dem ersten Absenden gilt die Vorgabe, danach das, was angekreuzt war. */
$an = fn(string $k, bool $vorgabe): bool => $gesendet ? !empty($_POST[$k]) : $vorgabe;
/** Reihenwert eines Terminvorschlags. */
$rv = function (string $k, int $i) use ($gesendet): string {
    if (!$gesendet) return '';
    $r = (array)($_POST[$k] ?? []);
    return tplan_param($r[$i] ?? '');
};

// So viele Zeilen zeigen, wie zuletzt ausgefüllt waren – mindestens vier.
$zeilen = 4;
if ($gesendet) $zeilen = max(4, min($lim['options'], count((array)($_POST['o_day'] ?? []))));

/**
 * Eine Zeile des Terminvorschlag-Formulars. Sie steht an zwei Stellen – als sichtbare Zeile
 * und als Vorlage für weitere – und darf zwischen beiden nicht auseinanderlaufen.
 * $nr ist die laufende Nummer bzw. das Kürzel `_n`, das das Skript in der Vorlage ersetzt.
 */
function tp_zeile(string $nr, string $tag = '', string $von = '', string $bis = '', string $notiz = '', string $max = ''): void
{
    ?>
    <div class="tp-row">
      <div class="tp-row-f tp-f-day">
        <label for="d<?= h($nr) ?>">Datum</label>
        <input type="date" class="fp-date" name="o_day[]" id="d<?= h($nr) ?>" value="<?= h($tag) ?>">
      </div>
      <div class="tp-row-f">
        <label for="f<?= h($nr) ?>">von</label>
        <input type="time" class="fp-time" name="o_from[]" id="f<?= h($nr) ?>" value="<?= h($von) ?>">
      </div>
      <div class="tp-row-f">
        <label for="b<?= h($nr) ?>">bis</label>
        <input type="time" class="fp-time" name="o_to[]" id="b<?= h($nr) ?>" value="<?= h($bis) ?>">
      </div>
      <div class="tp-row-f tp-f-label">
        <label for="l<?= h($nr) ?>">Notiz</label>
        <input type="text" name="o_label[]" id="l<?= h($nr) ?>" maxlength="80" placeholder="optional" value="<?= h($notiz) ?>">
      </div>
      <div class="tp-row-f tp-f-cap">
        <label for="c<?= h($nr) ?>">max.</label>
        <input type="number" name="o_cap[]" id="c<?= h($nr) ?>" min="0" max="9999" placeholder="∞" value="<?= h($max) ?>">
      </div>
    </div>
    <?php
}

tplan_head('Terminumfrage anlegen', 'Neue Terminumfrage beim ' . tplan_traeger() . ' anlegen.', true, 'pat-body cinema', true);
?>
<section class="pat-hero sub tp-hero">
  <div class="pat-in">
    <span class="pat-hero-chip"><i class="ti ti-calendar-plus"></i> Neue Terminumfrage</span>
    <h1>Terminumfrage anlegen</h1>
    <p class="pat-hero-lead">Titel und ein paar Terminvorschläge genügen. Alles andere ist voreingestellt.</p>
  </div>
</section>
<div class="pat-in"><div class="tp-col">
<p class="pat-back"><a href="index.php"><i class="ti ti-arrow-left"></i> Startseite</a></p>

<?php if ($fehler !== ''): ?>
  <div class="card um-fehlerkarte"><p class="um-fehler"><i class="ti ti-alert-triangle"></i> <?= h($fehler) ?></p></div>
<?php endif; ?>

<form method="post">
  <?= tplan_csrf_field() ?>

  <div class="card">
    <h2 class="tp-h"><i class="ti ti-help-hexagon"></i> Worum geht es?</h2>
    <div class="tp-feld">
      <label for="title">Titel <span class="tp-pflicht">*</span></label>
      <input type="text" name="title" id="title" maxlength="160" required
             placeholder="z. B. Fachschaftssitzung im Mai" value="<?= h($v('title')) ?>">
    </div>
    <div class="tp-feld">
      <label for="intro">Beschreibung</label>
      <p class="um-klein tp-hilfe">Worum geht es, wie lange dauert es, was soll man mitbringen?</p>
      <textarea name="intro" id="intro" rows="3" maxlength="4000"><?= h($v('intro')) ?></textarea>
    </div>
    <div class="tp-two">
      <div class="tp-feld">
        <label for="place">Ort</label>
        <input type="text" name="place" id="place" maxlength="200" placeholder="Raum, Adresse oder Videolink" value="<?= h($v('place')) ?>">
      </div>
      <div class="tp-feld">
        <label for="organizer">Wer fragt?</label>
        <input type="text" name="organizer" id="organizer" maxlength="120" placeholder="Name oder Gruppe" value="<?= h($v('organizer')) ?>">
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="tp-h"><i class="ti ti-calendar-plus"></i> Terminvorschläge <span class="tp-pflicht">*</span></h2>
    <p class="um-klein">Datum genügt. Ohne Uhrzeit gilt der Vorschlag als ganztägig; leere Zeilen werden übersprungen.</p>
    <div class="tp-rows" id="rows">
      <?php for ($i = 0; $i < $zeilen; $i++) tp_zeile((string)$i, $rv('o_day', $i), $rv('o_from', $i), $rv('o_to', $i), $rv('o_label', $i), $rv('o_cap', $i)); ?>
    </div>
    <?php /* Die Vorlage für weitere Zeilen. Ein <template> steht außerhalb des Dokuments:
             querySelectorAll findet nichts darin, der Wähler fasst sie also nicht an. Genau
             deshalb wird hier kopiert und nicht die letzte sichtbare Zeile geklont – die
             trägt nach dem Start des Wählers ein zweites, von ihm erzeugtes Feld mit sich. */ ?>
    <template id="rowtpl"><?php tp_zeile('_n'); ?></template>
    <div class="tp-rowbtns">
      <button class="btn secondary small" type="button" id="mehr"><i class="ti ti-plus"></i> Weitere Zeile</button>
      <span class="um-klein">Höchstens <?= (int)$lim['options'] ?> Vorschläge.</span>
    </div>
    <p class="um-klein tp-caphint"><strong>max.</strong> begrenzt die Zusagen für diesen Termin – praktisch für Sprechstunden,
      Führungen oder Schichten. Leer heißt: unbegrenzt.</p>
  </div>

  <div class="card">
    <h2 class="tp-h"><i class="ti ti-clock-hour-4"></i> Frist</h2>
    <p class="um-klein">Nach der Frist kann niemand mehr antworten, das Ergebnis bleibt sichtbar. Ohne Frist läuft die Umfrage, bis du sie schließt.</p>
    <div class="tp-two">
      <div class="tp-feld">
        <label for="deadline_day">Antworten bis</label>
        <input type="date" class="fp-date" name="deadline_day" id="deadline_day" value="<?= h($v('deadline_day')) ?>">
      </div>
      <div class="tp-feld">
        <label for="deadline_time">Uhrzeit</label>
        <input type="time" class="fp-time" name="deadline_time" id="deadline_time" value="<?= h($v('deadline_time')) ?>">
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="tp-h"><i class="ti ti-arrow-back-up"></i> Wie kommen die Leute zu ihrer Antwort zurück?</h2>
    <p class="um-klein">Die wichtigste Entscheidung: Sie legt fest, ob überhaupt Mailadressen im Spiel sind.
      Später änderbar.</p>
    <?php tplan_zugang_wahl($v('zugang', 'offen')); ?>
  </div>

  <details class="tp-more"<?= $gesendet ? ' open' : '' ?>>
    <summary><i class="ti ti-adjustments"></i> Feineinstellungen <span class="um-klein">– die Vorgaben passen meistens</span></summary>
    <div class="card">
      <?php tplan_settings_fields($an, $v, false); ?>
    </div>
  </details>

  <div class="card um-absenden-karte">
    <h2 class="tp-h"><i class="ti ti-mail"></i> Deine Mailadresse <span class="um-klein">– freiwillig</span></h2>
    <p>Es gibt hier keine Konten. Wer den Verwaltungs-Link verliert, kommt nicht mehr an die Umfrage.
       <strong>Trag eine Adresse ein, dann schicken wir dir beide Links zu</strong> – das ist die einzige Sicherung.</p>
    <div class="um-form">
      <label for="contact">Mailadresse</label>
      <input type="email" name="contact" id="contact" maxlength="190" autocomplete="email" inputmode="email" value="<?= h($v('contact', tplan_me()['m'])) ?>">
      <p class="um-klein">Wird ausschließlich für diese eine Mail benutzt und danach nur noch gespeichert,
        damit du sie dir erneut schicken lassen kannst. Kein Newsletter, keine Weitergabe.</p>
    </div>
    <div class="um-hp" aria-hidden="true">
      <label for="website">Bitte frei lassen</label>
      <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
    </div>
    <button class="btn" type="submit"><i class="ti ti-check"></i> Terminumfrage anlegen</button>
  </div>

  <p class="um-klein tp-privacy"><?= h(tplan_text('privacy_note')) ?></p>
</form>

</div></div><!-- /.tp-col /.pat-in -->
<?php tplan_picker_script(); ?>
<script nonce="<?= h(tplan_nonce()) ?>">
(function(){
  var rows = document.getElementById('rows'), btn = document.getElementById('mehr'),
      tpl = document.getElementById('rowtpl'), max = <?= (int)$lim['options'] ?>;
  if (!rows || !btn || !tpl || !tpl.content) return;
  btn.addEventListener('click', function(){
    var n = rows.querySelectorAll('.tp-row').length;
    if (n >= max) { btn.disabled = true; return; }
    var teil = tpl.content.cloneNode(true), zeile = teil.querySelector('.tp-row');
    // Die Vorlage trägt „_n" als Platzhalter – hier wird die laufende Nummer daraus.
    teil.querySelectorAll('[id]').forEach(function(f){ f.id = f.id.replace('_n', n); });
    teil.querySelectorAll('label[for]').forEach(function(l){ l.htmlFor = l.htmlFor.replace('_n', n); });
    rows.appendChild(teil);
    if (window.tplanPicker) zeile.querySelectorAll('.fp-date, .fp-time').forEach(window.tplanPicker);
    // Nach dem Start des Wählers ist das Datumsfeld selbst versteckt – das sichtbare bekommt den Fokus.
    var erstes = zeile.querySelector('input:not([type="hidden"])'); if (erstes) erstes.focus();
    if (rows.querySelectorAll('.tp-row').length >= max) btn.disabled = true;
  });
})();
</script>
<?php
tplan_foot();
