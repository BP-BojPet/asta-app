<?php
/**
 * Umfrage: erst abstimmen, dann die Adresse bestätigen.
 *
 * Der Stimmzettel steht sofort da – wer gerade motiviert ist, soll jetzt abstimmen können.
 * Die Adresse kommt ganz unten dazu; gezählt wird die Stimme erst mit dem Klick in der Mail.
 *
 * Nach dem Absenden zeigt die Seite IMMER dieselbe Antwort – auch wenn die Endung nicht
 * teilnahmeberechtigt ist oder für die Adresse schon eine Stimme gezählt wurde. Eine
 * ehrlichere Antwort würde diese Seite in ein Werkzeug verwandeln, mit dem man das
 * Abstimmverhalten einzelner Leute abfragen kann.
 */
require __DIR__ . '/umfrage-lib.php';

$slug = trim(umfrage_param($_GET['u'] ?? ''));
$poll = $slug !== '' ? umfrage_by_slug($slug) : null;

// Vorschau: Wer den geheimen Schlüssel hat, sieht auch den ENTWURF und darf den ganzen Bogen
// durchklicken – abgeschickt wird dabei nichts (siehe $vorschau weiter unten). Rollen kann
// dieser Bereich nicht prüfen, er kennt die App nicht; der Schlüssel ist der ganze Ausweis.
$vorschau = false;
if ($poll) {
    $vt = trim((string)($poll['preview_token'] ?? ''));
    $mit = trim(umfrage_param($_GET['vorschau'] ?? ''));
    $vorschau = $vt !== '' && $mit !== '' && hash_equals($vt, $mit);
}
if ($poll && (string)$poll['status'] === 'draft' && !$vorschau) $poll = null;

// Sprache: ?lang=en schaltet um, die Wahl bleibt in der Adresse (kein Cookie nötig).
$lang = umfrage_param($_GET['lang'] ?? '') === 'en' ? 'en' : 'de';

if (!$poll) {
    http_response_code(404);
    umfrage_head('Umfrage nicht gefunden', '', true);
    echo '<div class="card"><h1>Diese Umfrage gibt es nicht</h1>'
       . '<p>Vielleicht ist der Link nicht vollständig kopiert worden, oder die Umfrage wurde entfernt.</p></div>';
    umfrage_foot();
    exit;
}

$offen  = umfrage_is_open($poll);
// Der Bogen wird gezeigt, wenn die Umfrage läuft – oder in der Vorschau, denn genau dafür
// ist sie da. Gespeichert wird deshalb trotzdem nichts (siehe POST-Zweig).
$zeigeBogen = $offen || $vorschau;
$fertig = umfrage_results_public($poll);
$fragen = umfrage_questions((int)$poll['id']);
$abgegeben = false;
$eta     = '';
$fehler  = '';
$eingabe = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    umfrage_check_csrf();
    $eingabe = (array)($_POST['f'] ?? []);
    if ($vorschau) {
        // In der Vorschau wird NICHTS gespeichert und NICHTS verschickt – sonst hätte die
        // Probe eine Stimme in der Urne und eine Mail beim Absender.
        $fehler = $lang === 'en'
            ? 'Preview only – nothing was submitted.'
            : 'Nur Vorschau – abgeschickt wurde nichts.';
    } elseif (!empty($_POST['website'])) {    // Honigtopf: Menschen füllen das nie aus
        $abgegeben = true;
        $eta = 'in den nächsten Minuten';
    } elseif (!$offen) {
        $fehler = 'Diese Umfrage nimmt gerade keine Stimmen an.';
    } elseif (!umfrage_rate_ok()) {
        $fehler = 'Von hier kamen gerade sehr viele Stimmen. Bitte in einer Stunde noch einmal versuchen.';
    } else {
        $r = umfrage_vote_start($poll, umfrage_param($_POST['email'] ?? ''), $eingabe, (array)($_POST['fo'] ?? []));
        if (!$r['ok']) {
            $fehler = (string)$r['msg'];
        } else {
            $abgegeben = true;
            $eta = (string)$r['eta'];
            // Reicht das Kontingent, geht die Mail sofort raus – nur dann ist das Token noch
            // bekannt und muss nirgends abgelegt werden.
            if (!empty($r['sofort']) && !empty($r['token'])) {
                $link = umfrage_url($poll, (string)$r['token']);
                $vars = ['{{TITEL}}' => (string)$poll['title'], '{{LINK}}' => $link,
                         '{{FRIST_SATZ}}' => trim((string)$poll['ends_at']) !== ''
                             ? "\n\nZeit zum Bestätigen hast du bis zum Ende der Umfrage am " . umfrage_dt((string)$poll['ends_at']) . '.'
                             : ''];
                // Wer auf Englisch abgestimmt hat, bekommt die Bestätigung auch auf Englisch –
                // sofern in den Einstellungen eine englische Fassung gepflegt ist.
                $ok = $link !== '' && umfrage_mail($_POST['email'] ?? '',
                    umfrage_text_fill(umfrage_text('mail_subject', $lang), $vars),
                    umfrage_text_fill(umfrage_text('mail_body', $lang), $vars));
                if ($ok) {
                    umfrage_pending_mailed((int)$r['pending_id']);
                } else {
                    umfrage_log('Sofortversand fehlgeschlagen (Umfrage ' . (int)$poll['id'] . ') – die Zeile bleibt in der Warteschlange.');
                    $eta = umfrage_mail_eta(umfrage_queue_len());
                }
            }
        }
    }
}

$titel = um_feld($poll, 'title', $lang);
$intro = trim(um_feld($poll, 'intro', $lang));
$zweisprachig = umfrage_hat_en($poll, $fragen);
// Adresse für den Umschalter: dieselbe Seite, nur andere Sprache
$umschalt = '?u=' . rawurlencode((string)$poll['slug'])
          . ($vorschau ? '&vorschau=' . rawurlencode(trim((string)$poll['preview_token'])) : '')
          . ($lang === 'en' ? '' : '&lang=en');
umfrage_head($titel, mb_substr($intro, 0, 160));
?>
<?php if ($vorschau): ?>
  <div class="card um-vorschau-band">
    <p style="margin:0"><i class="ti ti-eye"></i> <strong><?= $lang === 'en' ? 'Preview' : 'Vorschau' ?></strong> –
      <?= $lang === 'en'
          ? 'this is exactly what participants will see. Nothing you enter here is submitted.'
          : 'genau so sehen es die Teilnehmenden. Was du hier einträgst, wird nicht abgeschickt.' ?></p>
  </div>
<?php endif; ?>
<div class="card um-kopf">
  <?php if ($zweisprachig): ?>
    <p class="um-lang"><a href="<?= h($umschalt) ?>"><i class="ti ti-language"></i> <?= h(umfrage_ui('sprache', $lang)) ?></a></p>
  <?php endif; ?>
  <h1><?= h($titel) ?></h1>
  <?php if ($intro !== ''): ?>
    <div class="um-intro"><?= nl2br(h($intro)) ?></div>
  <?php endif; ?>
  <p class="um-frist">
    <?php if ($zeigeBogen && trim((string)$poll['ends_at']) !== ''): ?>
      <i class="ti ti-clock"></i> <?= h(umfrage_ui('lauft_bis', $lang)) ?> <strong><?= h(umfrage_dt((string)$poll['ends_at'])) ?></strong>.
    <?php elseif ($zeigeBogen): ?>
      <i class="ti ti-clock"></i> <?= h(umfrage_ui('lauft', $lang)) ?>
    <?php else: ?>
      <i class="ti ti-lock"></i> <?= h(umfrage_ui('beendet', $lang)) ?>
    <?php endif; ?>
  </p>
</div>

<?php if ($abgegeben): ?>
  <div class="card um-ok">
    <h2><i class="ti ti-mail-fast"></i> <?= h(umfrage_ui('danke_titel', $lang)) ?></h2>
    <p><strong>Deine Stimme zählt erst, wenn du den Link in der Mail anklickst.</strong>
       Sie liegt bis dahin verschlüsselt bereit – lesen kann sie niemand, auch wir nicht.</p>
    <p>Die Bestätigungsmail geht <strong><?= h($eta !== '' ? $eta : 'in den nächsten Minuten') ?></strong> raus.
       <?php if (trim((string)$poll['ends_at']) !== ''): ?>Zeit zum Bestätigen hast du bis zum Ende der Umfrage am <?= h(umfrage_dt((string)$poll['ends_at'])) ?>.<?php endif; ?></p>
    <p class="um-klein">Nichts angekommen? Sieh im Spam-Ordner nach. Du kannst hier auch einfach noch einmal abstimmen –
       solange nichts bestätigt ist, gilt immer die zuletzt abgegebene Stimme.</p>
  </div>

<?php elseif ($zeigeBogen && $fragen): ?>
  <?php if ($fehler !== ''): ?>
    <div class="card um-fehlerkarte"><p class="um-fehler"><i class="ti ti-alert-triangle"></i> <?= h($fehler) ?></p></div>
  <?php endif; ?>
  <form method="post">
    <?= umfrage_csrf_field() ?>
    <?php $nr = 0; foreach ($fragen as $q): $qid = (int)$q['id']; $typ = (string)$q['type'];
          $vor = $eingabe[$qid] ?? null;
          $andereErlaubt = (int)($q['allow_other'] ?? 0) === 1;
          $andererText   = umfrage_param($_POST['fo'][$qid] ?? ''); ?>
      <?php if ($typ === 'info'): ?>
        <?php /* Zwischentext: gliedert den Bogen, zählt nicht als Frage und trägt keine Nummer. */ ?>
        <div class="card um-zwischen">
          <h2><?= h(um_feld($q, 'title', $lang)) ?></h2>
          <?php if (trim(um_feld($q, 'help', $lang)) !== ''): ?><p><?= nl2br(h(trim(um_feld($q, 'help', $lang)))) ?></p><?php endif; ?>
          <?php if (trim((string)($q['image'] ?? '')) !== ''): ?>
            <p class="um-bild"><img src="<?= h(umfrage_bild_url((string)$q['image'])) ?>" alt="<?= h((string)($q['image_alt'] ?? '')) ?>" loading="lazy"></p>
          <?php endif; ?>
        </div>
        <?php continue; ?>
      <?php endif; $nr++; ?>
      <div class="card um-frage">
        <h2><span class="um-nr"><?= $nr ?></span> <?= h(um_feld($q, 'title', $lang)) ?>
          <?php if ((int)$q['required'] !== 1): ?><span class="um-opt"><?= h(umfrage_ui('freiwillig', $lang)) ?></span><?php endif; ?></h2>
        <?php if (trim(um_feld($q, 'help', $lang)) !== ''): ?><p class="um-klein"><?= nl2br(h(trim(um_feld($q, 'help', $lang)))) ?></p><?php endif; ?>
        <?php /* Bild direkt unter der Frage – vor den Antwortmöglichkeiten, sonst rutscht es
                 bei langen Listen aus dem Blick. */ ?>
        <?php if (trim((string)($q['image'] ?? '')) !== ''): ?>
          <p class="um-bild"><img src="<?= h(umfrage_bild_url((string)$q['image'])) ?>" alt="<?= h((string)($q['image_alt'] ?? '')) ?>" loading="lazy"></p>
        <?php endif; ?>

        <?php if ($typ === 'single'): ?>
          <div class="um-wahl">
            <?php foreach ($q['options'] as $o): $oid = (int)$o['id']; ?>
              <label class="um-opt-l"><input type="radio" name="f[<?= $qid ?>]" value="<?= $oid ?>" <?= (int)$vor === $oid ? 'checked' : '' ?>><span><?= h(um_feld($o, 'label', $lang)) ?></span></label>
            <?php endforeach; ?>
            <?php if ($andereErlaubt): ?>
              <label class="um-opt-l um-sonst"><input type="radio" name="f[<?= $qid ?>]" value="x" <?= (string)$vor === 'x' ? 'checked' : '' ?>><span><?= h(umfrage_ui('sonstiges', $lang)) ?></span>
                <input class="um-sonst-txt" type="text" name="fo[<?= $qid ?>]" maxlength="200" value="<?= h($andererText) ?>" placeholder="<?= h(umfrage_ui('eigene', $lang)) ?>"></label>
            <?php endif; ?>
          </div>
        <?php elseif ($typ === 'multi'): $gewaehlt = array_map('strval', (array)$vor); ?>
          <div class="um-wahl">
            <?php foreach ($q['options'] as $o): $oid = (int)$o['id']; ?>
              <label class="um-opt-l"><input type="checkbox" name="f[<?= $qid ?>][]" value="<?= $oid ?>" <?= in_array((string)$oid, $gewaehlt, true) ? 'checked' : '' ?>><span><?= h(um_feld($o, 'label', $lang)) ?></span></label>
            <?php endforeach; ?>
            <?php if ($andereErlaubt): ?>
              <label class="um-opt-l um-sonst"><input type="checkbox" name="f[<?= $qid ?>][]" value="x" <?= in_array('x', $gewaehlt, true) ? 'checked' : '' ?>><span><?= h(umfrage_ui('sonstiges', $lang)) ?></span>
                <input class="um-sonst-txt" type="text" name="fo[<?= $qid ?>]" maxlength="200" value="<?= h($andererText) ?>" placeholder="<?= h(umfrage_ui('eigene', $lang)) ?>"></label>
            <?php endif; ?>
          </div>
          <?php if ((int)$q['max_choices'] > 0): ?><p class="um-klein">Höchstens <?= (int)$q['max_choices'] ?> Antworten.</p><?php endif; ?>
        <?php elseif ($typ === 'scale'): ?>
          <div class="um-skala">
            <?php if (trim(um_feld($q, 'scale_lo', $lang)) !== ''): ?><span class="um-skala-e"><?= h(um_feld($q, 'scale_lo', $lang)) ?></span><?php endif; ?>
            <?php for ($v = (int)$q['scale_min']; $v <= (int)$q['scale_max']; $v++): ?>
              <label class="um-stufe"><input type="radio" name="f[<?= $qid ?>]" value="<?= $v ?>" <?= (string)$vor === (string)$v ? 'checked' : '' ?>><span><?= $v ?></span></label>
            <?php endfor; ?>
            <?php if (trim(um_feld($q, 'scale_hi', $lang)) !== ''): ?><span class="um-skala-e"><?= h(um_feld($q, 'scale_hi', $lang)) ?></span><?php endif; ?>
          </div>
        <?php else: ?>
          <textarea name="f[<?= $qid ?>]" rows="4" maxlength="2000" placeholder="<?= h(umfrage_ui('antwort', $lang)) ?>"><?= h(is_array($vor) ? '' : (string)$vor) ?></textarea>
          <p class="um-klein">Bitte keine Namen oder Kontaktdaten eintragen – Freitexte können veröffentlicht werden.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="card um-absenden-karte">
      <h2><i class="ti ti-mail"></i> Deine Hochschul-Mailadresse</h2>
      <p><?= h(umfrage_ui('warum_mail', $lang)) ?></p>
      <p class="um-klein"><i class="ti ti-lock"></i> Bis dahin liegt sie <strong>verschlüsselt</strong> bereit –
         der Schlüssel steckt nur in deiner Mail. Nach dem Bestätigen wird die Verbindung zwischen deiner
         Adresse und deinen Antworten gelöscht; aus dem Ergebnis lässt sich nicht zurückverfolgen, wer wie abgestimmt hat.</p>
      <div class="um-form">
        <label for="email"><?= h(umfrage_ui('mailadresse', $lang)) ?></label>
        <input type="email" name="email" id="email" required autocomplete="email" inputmode="email"
               <?php /* Kein Beispiel-Name als Platzhalter: Die Adressen an der RPTU sehen nicht bei
                       allen gleich aus, und ein erfundenes Muster liest sich schnell wie eine
                       Vorschrift. Was gilt, steht darunter – die erlaubten Endungen. */ ?>
               maxlength="190" value="<?= h(umfrage_param($_POST['email'] ?? '')) ?>">
        <p class="um-klein"><?= nl2br(h(umfrage_text('mail_hint', $lang))) ?></p>
        <p class="um-klein"><?= h(umfrage_ui('endungen', $lang)) ?>
          <?php foreach (umfrage_domains($poll) as $i => $d): ?><?= $i ? ', ' : '' ?><code>@<?= h($d) ?></code><?php endforeach; ?>
        </p>
      </div>
      <div class="um-hp" aria-hidden="true">
        <label for="website"><?= h(umfrage_ui('frei_lassen', $lang)) ?></label>
        <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
      </div>
      <button class="btn" type="submit"><i class="ti ti-send"></i> <?= h(umfrage_ui('absenden', $lang)) ?></button>
    </div>
  </form>

<?php elseif ($zeigeBogen): ?>
  <div class="card"><p class="empty"><i class="ti ti-help-circle"></i> <?= h(umfrage_ui('keine_fragen', $lang)) ?></p></div>
<?php endif; ?>

<?php if ($fertig): ?>
  <div class="card">
    <h2><i class="ti ti-chart-bar"></i> Ergebnis</h2>
    <p>Die Umfrage ist beendet – das Ergebnis ist öffentlich einsehbar.</p>
    <p><a class="btn secondary" href="ergebnis.php?u=<?= h(rawurlencode((string)$poll['slug'])) ?>"><?= h(umfrage_ui('ergebnis', $lang)) ?></a></p>
  </div>
<?php endif; ?>
<?php
umfrage_foot();
