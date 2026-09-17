<?php
/**
 * Die Terminumfrage aus Sicht der Teilnehmenden.
 *
 * Aufbau bewusst in dieser Reihenfolge: erst worum es geht, dann das eigene Eintragen, dann
 * das Ergebnis. Wer den Link bekommt, soll antworten können, ohne vorher zu scrollen.
 *
 * Die Vorschläge stehen nach Tagen gruppiert untereinander – keine breite Tabelle, durch die
 * man auf dem Handy seitwärts wischen muss. Die Tabellenansicht gibt es zusätzlich, aber
 * eingeklappt und für die, die sie wollen.
 */
require __DIR__ . '/termin-lib.php';

if (tplan_offline_guard()) exit;

$poll = tplan_by_slug(tplan_param($_GET['t'] ?? ''));
if (!$poll) {
    http_response_code(404);
    tplan_head('Terminumfrage nicht gefunden', '', true, 'pat-body cinema');
    echo '<div class="pat-in"><div class="tp-col"><div class="card"><h1>Diese Terminumfrage gibt es nicht</h1>'
       . '<p>Vielleicht wurde der Link nicht vollständig kopiert, oder die Umfrage ist inzwischen gelöscht –'
       . ' das passiert automatisch, wenn sie lange nicht mehr benutzt wurde.</p>'
       . '<p class="pat-back"><a href="index.php">‹ Zum Terminplaner</a></p></div></div></div>';
    tplan_foot();
    exit;
}

$modus = tplan_edit_mode($poll);

/*
 * Welche Antwort wird gerade bearbeitet – und mit welchem Recht?
 *
 * Drei Wege führen dahin, in dieser Reihenfolge:
 *  1. der persönliche Link aus der Mail (?e=Token) – gilt immer als „die eigene",
 *  2. das Gerät kennt sich (Cookie, 30 Tage) – ebenfalls die eigene,
 *  3. jemand klickt in der Liste auf „ändern" (?b=Nummer) – nur, wenn die Umfrage das erlaubt.
 * $eigen unterscheidet 1/2 von 3; daran hängt, ob „nur die eigene Antwort" greift.
 */
$entry  = null;
$eigen  = false;
$erkannt = false;

$entry = tplan_entry_by_token(tplan_param($_GET['e'] ?? ''));
if ($entry && (int)$entry['poll_id'] !== (int)$poll['id']) $entry = null;
if ($entry) $eigen = true;

// Kennt das Gerät hier schon eine Antwort? Sonst legt derselbe Mensch beim zweiten Öffnen des
// Links eine zweite Zeile an, und die Liste hat plötzlich zwei „Vivien".
if (!$entry && empty($_GET['neu']) && $modus !== 'none') {
    $tok = (string)(tplan_mine('entries')[(string)$poll['slug']] ?? '');
    $kandidat = $tok !== '' ? tplan_entry_by_token($tok) : null;
    if ($kandidat && (int)$kandidat['poll_id'] === (int)$poll['id']) {
        $entry = $kandidat; $eigen = true; $erkannt = true;
    }
}

// „Jede:r darf jeden Eintrag ändern": der Klick aus der Liste.
$fremd = false;
if (!$entry && $modus === 'all' && (int)($_GET['b'] ?? 0) > 0) {
    $entry = tplan_entry_get((int)$poll['id'], (int)$_GET['b']);
    if ($entry) $fremd = true;
}

$offen   = tplan_is_open($poll);
$fehler  = '';
$gerade  = !empty($_GET['ok']);   // gerade gespeichert (nach der Weiterleitung)

/*
 * Nach einem abgelehnten Absenden zählt, was gerade eingetippt wurde – nicht der Stand aus der
 * Datenbank. Sonst ist bei „dieser Termin ist schon voll" die ganze übrige Eingabe weg, und man
 * fängt wegen eines einzigen Kreuzes von vorn an.
 */
$abgeschickt = $_SERVER['REQUEST_METHOD'] === 'POST';
$feld = fn(string $k, string $vorher): string => $abgeschickt ? tplan_param($_POST[$k] ?? '') : $vorher;
// Kreuze aus dem letzten Versuch (null = es gab keinen). `?v[3][]=x` liefert ein Array,
// deshalb geht jeder Wert durch tplan_param().
$eingabe = null;
if ($abgeschickt) {
    $eingabe = [];
    foreach ((array)($_POST['v'] ?? []) as $oid => $w) $eingabe[(int)$oid] = tplan_param($w);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tplan_check_csrf();
    if (!empty($_POST['website'])) {              // Honigtopf
        header('Location: t.php?t=' . rawurlencode((string)$poll['slug']));
        exit;
    } elseif (!tplan_rate_ok('vote', 30)) {
        $fehler = 'Von hier kamen gerade sehr viele Antworten. Bitte in einer Stunde noch einmal versuchen.';
    } else {
        $altMail = $entry ? (string)$entry['email'] : '';
        $r = tplan_vote_save($poll, $_POST, $entry, $eigen);
        if (!$r['ok']) {
            $fehler = (string)$r['msg'];
        } else {
            $neu = (array)$r['entry'];
            $tok = (string)($neu['edit_token'] ?? '');
            // Nur die EIGENE Antwort merken. Wer für jemand anderen etwas korrigiert, soll beim
            // nächsten Besuch nicht plötzlich als diese Person dastehen.
            if (!$fremd) {
                tplan_remember('entries', (string)$poll['slug'] . '|' . $tok);
                tplan_remember_me((string)($neu['name'] ?? ''), (string)($neu['email'] ?? ''));
            }
            // Bestätigung mit dem persönlichen Link: Damit lässt sich die Antwort auch in zwei
            // Wochen von jedem Gerät aus ändern – ohne Konto, nur mit der Mail.
            tplan_mail_confirm($poll, $neu, $altMail);
            header('Location: t.php?t=' . rawurlencode((string)$poll['slug'])
                . ($modus === 'none' || $fremd ? '' : '&e=' . rawurlencode($tok)) . '&ok=1');
            exit;
        }
    }
}

$optionen = tplan_options((int)$poll['id']);
$sichtbar = tplan_results_visible($poll);
$ergebnis = $sichtbar ? tplan_results($poll) : [];
$best     = $sichtbar ? tplan_best_ids($ergebnis) : [];
$anzahl   = tplan_entry_count((int)$poll['id']);
$final    = (int)$poll['final_option'] > 0 ? tplan_option_get((int)$poll['final_option']) : null;
if ($final && (int)$final['poll_id'] !== (int)$poll['id']) $final = null;

require __DIR__ . '/_ansicht.php';

tplan_head($poll['title'], mb_substr(trim((string)$poll['intro']), 0, 160), true, 'pat-body cinema');
?>
<?php tp_kopf($poll, $anzahl, $offen); ?>
<div class="pat-in"><div class="tp-col">

<?php if ($final): ?>
  <?php tp_final_karte($poll, $final); ?>
<?php endif; ?>

<?php if ($gerade): ?>
  <div class="card tp-ok">
    <h2><i class="ti ti-circle-check"></i> Danke, deine Antwort ist gespeichert.</h2>
    <?php if ($entry && $modus !== 'none'): ?>
      <p>Du kannst sie jederzeit ändern – über diese Seite, solange sie offen ist.</p>
      <?php tplan_copybox('mylink', 'Dein persönlicher Link zum Ändern',
          tplan_edit_url($poll, (string)$entry['edit_token']),
          'Nur für dich. Ohne ihn legst du beim nächsten Mal versehentlich eine zweite Antwort an.'); ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($fehler !== ''): ?>
  <div class="card um-fehlerkarte"><p class="um-fehler"><i class="ti ti-alert-triangle"></i> <?= h($fehler) ?></p></div>
<?php endif; ?>

<?php if (!$optionen): ?>
  <div class="card"><p class="empty"><i class="ti ti-calendar-off"></i> Hier stehen noch keine Terminvorschläge.</p></div>

<?php elseif ($offen): ?>
  <?php if ($fremd): ?>
    <div class="card tp-erkannt">
      <p><i class="ti ti-pencil"></i> Du änderst gerade den Eintrag von <strong><?= h((string)$entry['name']) ?></strong>.
        Diese Umfrage erlaubt das ausdrücklich – bitte trotzdem nur, was abgesprochen ist.</p>
      <p class="um-klein"><a href="t.php?t=<?= h(rawurlencode((string)$poll['slug'])) ?>&amp;neu=1">Doch lieber eine eigene Antwort eintragen</a></p>
    </div>
  <?php endif; ?>
  <?php if ($erkannt): ?>
    <div class="card tp-erkannt">
      <p><i class="ti ti-user-check"></i> Du hast hier schon geantwortet – als <strong><?= h((string)$entry['name']) ?></strong>.
        Unten steht deine bisherige Antwort; änderst du sie, ersetzt sie die alte.</p>
      <p class="um-klein">Bist du jemand anderes? <a href="t.php?t=<?= h(rawurlencode((string)$poll['slug'])) ?>&amp;neu=1">Neue, eigene Antwort eintragen</a></p>
    </div>
  <?php endif; ?>
  <div class="section-title" id="antworten"><i class="ti ti-checkbox"></i> <?= $fremd
      ? 'Eintrag von ' . h((string)$entry['name']) . ' ändern'
      : ($entry ? 'Deine Antwort ändern' : 'Wann kannst du?') ?></div>
  <?php /* Das Ziel steht ausdrücklich da: Nur so weiß die Seite nach dem Absenden noch, ob
           gerade eine bestehende Antwort geändert oder bewusst eine zweite angelegt wird.
           Auch die wiedererkannte Antwort bekommt ihr Token mit – sonst hinge das Ändern an
           der Sitzung, und nach deren Ablauf entstünde beim Absenden eine zweite Zeile. */ ?>
  <form method="post" class="tp-form" action="t.php?t=<?= h(rawurlencode((string)$poll['slug'])) ?><?php
      if (isset($_GET['neu'])) echo '&amp;neu=1';
      elseif ($fremd) echo '&amp;b=' . (int)$entry['id'];
      elseif ($entry) echo '&amp;e=' . h(rawurlencode((string)$entry['edit_token'])); ?>">
    <?= tplan_csrf_field() ?>
    <?php tp_stimmzettel($poll, $optionen, $entry, $eingabe); ?>

    <div class="card um-absenden-karte">
      <div class="tp-feld">
        <label for="name">Dein Name <span class="tp-pflicht">*</span></label>
        <input type="text" name="name" id="name" maxlength="80" required autocomplete="name"
               value="<?= h($feld('name', $entry ? (string)$entry['name'] : tplan_me()['n'])) ?>">
      </div>
      <?php if ((string)$poll['ask_mail'] !== 'off'): ?>
        <div class="tp-feld">
          <label for="email">Mailadresse <?= (string)$poll['ask_mail'] === 'required' ? '<span class="tp-pflicht">*</span>' : '<span class="um-klein">– freiwillig</span>' ?></label>
          <p class="um-klein tp-hilfe">Sieht nur die Person, die diese Umfrage angelegt hat. Es werden keine Mails verschickt.</p>
          <input type="email" name="email" id="email" maxlength="190" autocomplete="email" inputmode="email"
                 <?= (string)$poll['ask_mail'] === 'required' ? 'required' : '' ?>
                 value="<?= h($feld('email', $entry ? (string)$entry['email'] : tplan_me()['m'])) ?>">
        </div>
      <?php endif; ?>
      <?php if (!empty($poll['ask_comment'])): ?>
        <div class="tp-feld">
          <label for="comment">Bemerkung</label>
          <textarea name="comment" id="comment" rows="2" maxlength="500" placeholder="z. B. „am 12. erst ab 16 Uhr&#34;"><?= h($feld('comment', $entry ? (string)$entry['comment'] : '')) ?></textarea>
        </div>
      <?php endif; ?>
      <div class="um-hp" aria-hidden="true">
        <label for="website">Bitte frei lassen</label>
        <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
      </div>
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> <?= $entry ? 'Antwort aktualisieren' : 'Antwort speichern' ?></button>
      <?php if (!$entry && $modus !== 'none'): ?>
        <p class="um-klein tp-nachher">Nach dem Speichern bekommst du einen persönlichen Link, mit dem du deine Antwort
          später ändern kannst<?= !empty($poll['mail_confirm']) && (string)$poll['ask_mail'] !== 'off'
            ? ' – und mit Mailadresse zusätzlich per Mail' : '' ?>.</p>
      <?php endif; ?>
    </div>
  </form>

<?php elseif (!$final): ?>
  <div class="card tp-zu">
    <p><i class="ti ti-lock"></i> <?= tplan_deadline_passed($poll)
        ? 'Die Frist ist am ' . h(tplan_dt((string)$poll['deadline'])) . ' abgelaufen.'
        : 'Diese Terminumfrage wurde geschlossen.' ?>
      Neue Antworten sind nicht mehr möglich.</p>
  </div>
<?php endif; ?>

<?php if ($offen && $modus === 'all' && $anzahl > 0 && !$entry): ?>
  <?php /* Ohne diese Liste wüsste niemand, dass er fremde Einträge ändern DARF. Sie steht
           bewusst unter dem Formular: zuerst die eigene Antwort, dann das Aufräumen. */ ?>
  <div class="card tp-aendern">
    <h2><i class="ti ti-pencil"></i> Eintrag einer anderen Person ändern</h2>
    <p class="um-klein">Bei dieser Umfrage darf das jede:r – für Tippfehler oder wenn jemand gerade nicht am Rechner ist.</p>
    <ul class="tp-namen">
      <?php foreach (tplan_entries((int)$poll['id']) as $e): ?>
        <li><a href="t.php?t=<?= h(rawurlencode((string)$poll['slug'])) ?>&amp;b=<?= (int)$e['id'] ?>"><?= h((string)$e['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($sichtbar && $optionen): ?>
  <?php tp_ergebnis($poll, $ergebnis, $best, $anzahl, $final); ?>
  <?php tp_matrix($poll, $optionen, tplan_entries((int)$poll['id'])); ?>
<?php elseif ($optionen): ?>
  <div class="card">
    <p class="um-klein"><i class="ti ti-eye-off"></i>
      <?= (string)$poll['show_results'] === 'after'
          ? 'Das Ergebnis wird erst sichtbar, wenn die Umfrage beendet ist – damit die ersten Antworten die späteren nicht beeinflussen.'
          : 'Das Ergebnis sieht nur die Person, die diese Umfrage angelegt hat.' ?>
    </p>
  </div>
<?php endif; ?>

<p class="um-klein tp-privacy"><?= h(tplan_text('privacy_note')) ?>
  <a href="index.php">Eigene Terminumfrage anlegen</a></p>
</div></div><!-- /.tp-col /.pat-in -->
<?php
tplan_copy_script();
tplan_foot();
