<?php
/**
 * Die Terminumfrage aus Sicht der Person, die sie angelegt hat.
 *
 * Der Verwaltungs-Link IST der Ausweis – es gibt keine Konten. Deshalb steht ganz oben, was
 * dieser Link bedeutet, und deshalb bekommt er einen eigenen, deutlich abgesetzten Kasten:
 * Wer ihn versehentlich in die Gruppe schickt, gibt die Umfrage aus der Hand.
 *
 * Jede schreibende Aktion endet mit einer Weiterleitung. Ohne das legt ein Neuladen der Seite
 * denselben Terminvorschlag ein zweites Mal an.
 */
require __DIR__ . '/termin-lib.php';

if (tplan_offline_guard()) exit;

$poll = tplan_by_admin_key(tplan_param($_GET['k'] ?? ''));
if (!$poll) {
    http_response_code(404);
    tplan_head('Verwaltung nicht gefunden', '', true, 'pat-body cinema');
    echo '<div class="pat-in"><div class="tp-col"><div class="card"><h1>Dieser Verwaltungs-Link führt nirgendwohin</h1>'
       . '<p>Entweder ist er unvollständig kopiert worden, oder die Terminumfrage wurde gelöscht –'
       . ' von Hand oder automatisch, weil sie lange nicht mehr benutzt wurde.</p>'
       . '<p>Weil es hier keine Konten gibt, können wir eine Umfrage ohne ihren Verwaltungs-Link'
       . ' leider niemandem zuordnen.</p>'
       . '<p class="pat-back"><a href="index.php">‹ Zum Terminplaner</a></p></div></div></div>';
    tplan_foot();
    exit;
}

$key = (string)$poll['admin_key'];
$zurueck = 'verwalten.php?k=' . rawurlencode($key);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tplan_check_csrf();
    $action = tplan_param($_POST['action'] ?? '');

    if ($action === 'settings') {
        $r = tplan_update($poll, $_POST);
        tplan_flash((string)$r['msg'], $r['ok'] ? 'success' : 'error');
        header('Location: ' . $zurueck . '#einstellungen');
        exit;
    }
    if ($action === 'add_option') {
        $r = tplan_option_add($poll, $_POST);
        tplan_flash((string)$r['msg'], $r['ok'] ? 'success' : 'error');
        header('Location: ' . $zurueck . '#termine');
        exit;
    }
    if ($action === 'del_option') {
        tplan_option_remove($poll, (int)($_POST['option_id'] ?? 0));
        tplan_flash('Terminvorschlag entfernt.');
        header('Location: ' . $zurueck . '#termine');
        exit;
    }
    if ($action === 'finalize') {
        $r = tplan_finalize($poll, (int)($_POST['option_id'] ?? 0), tplan_param($_POST['final_note'] ?? (string)$poll['final_note']));
        tplan_flash((string)$r['msg'], $r['ok'] ? 'success' : 'error');
        header('Location: ' . $zurueck);
        exit;
    }
    if ($action === 'final_note') {
        tplan_finalize($poll, (int)$poll['final_option'], tplan_param($_POST['final_note'] ?? ''));
        tplan_flash('Hinweis gespeichert.');
        header('Location: ' . $zurueck);
        exit;
    }
    if ($action === 'toggle_closed') {
        $zu = empty($poll['closed']);
        tplan_set_closed((int)$poll['id'], $zu);
        tplan_flash($zu ? 'Umfrage geschlossen – das Ergebnis bleibt sichtbar.' : 'Umfrage wieder geöffnet.');
        header('Location: ' . $zurueck);
        exit;
    }
    if ($action === 'del_entry') {
        tplan_entry_delete((int)$poll['id'], (int)($_POST['entry_id'] ?? 0));
        tplan_flash('Rückmeldung gelöscht.');
        header('Location: ' . $zurueck . '#rueckmeldungen');
        exit;
    }
    if ($action === 'send_links') {
        if (!tplan_rate_ok('mail', 3)) {
            tplan_flash('Die Links wurden gerade schon verschickt. Bitte sieh erst im Postfach nach.', 'error');
        } elseif (trim((string)$poll['contact']) === '') {
            tplan_flash('Für diese Umfrage ist keine Mailadresse hinterlegt.', 'error');
        } elseif (tplan_mail_budget() <= 0) {
            tplan_flash('Der Mailversand ist gerade nicht möglich. Die Links stehen auf dieser Seite – bitte kopier sie dir.', 'error');
        } else {
            $ok = tplan_mail_links($poll);
            tplan_flash($ok ? 'Die Links sind unterwegs an ' . (string)$poll['contact'] . '.'
                             : 'Der Versand hat nicht geklappt. Bitte kopier dir die Links von dieser Seite.', $ok ? 'success' : 'error');
        }
        header('Location: ' . $zurueck . '#links');
        exit;
    }
    if ($action === 'delete' && tplan_param($_POST['bestaetigt'] ?? '') === 'ja') {
        tplan_delete((int)$poll['id']);
        tplan_flash('Die Terminumfrage wurde mit allen Antworten gelöscht.');
        header('Location: index.php');
        exit;
    }
    header('Location: ' . $zurueck);
    exit;
}

tplan_remember('polls', $key, (string)$poll['title']);

$neu       = !empty($_GET['neu']);
$mailRaus  = !empty($_GET['mail']);
$flash     = tplan_flash_take();
$optionen  = tplan_options((int)$poll['id']);
$eintraege = tplan_entries((int)$poll['id']);
$anzahl    = count($eintraege);
$ergebnis  = tplan_results($poll);
$best      = tplan_best_ids($ergebnis);
$offen     = tplan_is_open($poll);
$final     = (int)$poll['final_option'] > 0 ? tplan_option_get((int)$poll['final_option']) : null;
if ($final && (int)$final['poll_id'] !== (int)$poll['id']) $final = null;

$frist = trim((string)$poll['deadline']);
$fTag  = $frist !== '' ? substr($frist, 0, 10) : '';
$fZeit = $frist !== '' && substr($frist, 11, 5) !== '23:59' ? substr($frist, 11, 5) : '';

/** Formularwerte kommen hier immer aus der Datenbank – jede Änderung wurde vorher gespeichert. */
$v = function (string $k, string $vorgabe = '') use ($poll, $fTag, $fZeit): string {
    if ($k === 'deadline_day')  return $fTag;
    if ($k === 'deadline_time') return $fZeit;
    if ($k === 'max_entries')   return (int)$poll['max_entries'] > 0 ? (string)$poll['max_entries'] : '';
    return (string)($poll[$k] ?? $vorgabe);
};
$an = fn(string $k, bool $vorgabe): bool => !empty($poll[$k]);

require __DIR__ . '/_ansicht.php';

tplan_head('Verwalten: ' . $poll['title'], '', true, 'pat-body cinema', true);
?>
<?php tp_kopf($poll, $anzahl, $offen, true); ?>
<div class="pat-in"><div class="tp-col">
<p class="pat-back"><a href="index.php"><i class="ti ti-arrow-left"></i> Terminplaner</a></p>

<?php if ($flash): ?>
  <div class="card <?= $flash['t'] === 'error' ? 'um-fehlerkarte' : 'tp-ok' ?>">
    <p class="<?= $flash['t'] === 'error' ? 'um-fehler' : 'tp-ok-t' ?>">
      <i class="ti ti-<?= $flash['t'] === 'error' ? 'alert-triangle' : 'circle-check' ?>"></i> <?= h((string)$flash['m']) ?></p>
  </div>
<?php endif; ?>

<?php if ($neu): ?>
  <div class="card tp-neu">
    <h1><i class="ti ti-confetti"></i> Deine Terminumfrage steht</h1>
    <p>Jetzt fehlt nur noch der erste Link – verschick ihn an alle, die abstimmen sollen.</p>
    <?php if ($mailRaus): ?>
      <p class="um-klein"><i class="ti ti-mail-fast"></i> Beide Links sind zusätzlich an <strong><?= h((string)$poll['contact']) ?></strong> unterwegs.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>


<div class="section-title" id="links"><i class="ti ti-link"></i> Deine zwei Links</div>
<div class="card">
  <?php if (tplan_url($poll) === ''): ?>
    <p class="um-fehler"><i class="ti ti-alert-triangle"></i> Für diese Anlage ist noch keine Basis-Adresse hinterlegt –
      die Links lassen sich deshalb nicht anzeigen. Bitte gib dem AStA Bescheid.</p>
  <?php else: ?>
    <?php tplan_copybox('l1', 'Teilnahme-Link – diesen weitergeben', tplan_url($poll),
        'Wer ihn hat, kann antworten. Mehr nicht.'); ?>
    <?php tplan_copybox('l2', 'Verwaltungs-Link – nur für dich', tplan_admin_url($poll),
        'Damit kommst du auf diese Seite. Wer ihn hat, kann die Umfrage ändern und löschen – also bitte nicht in die Gruppe schicken.', 'warn'); ?>
    <p class="um-klein"><i class="ti ti-bookmark"></i> Leg dir ein Lesezeichen an oder speichere den Verwaltungs-Link.
      Es gibt keine Konten – ohne ihn kommt niemand mehr an die Umfrage, wir auch nicht.</p>
    <?php if (trim((string)$poll['contact']) !== ''): ?>
      <form method="post" class="tp-inline"><?= tplan_csrf_field() ?>
        <input type="hidden" name="action" value="send_links">
        <button class="btn secondary small" type="submit"><i class="ti ti-mail"></i> Beide Links erneut an <?= h((string)$poll['contact']) ?> schicken</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
  <p class="tp-inline"><a class="btn secondary small" href="t.php?t=<?= h(rawurlencode((string)$poll['slug'])) ?>" target="_blank" rel="noopener">
    <i class="ti ti-external-link"></i> Ansehen, wie die anderen es sehen</a></p>
</div>

<?php if ($final): ?><?php tp_final_karte($poll, $final); ?>
  <div class="card">
    <form method="post"><?= tplan_csrf_field() ?>
      <input type="hidden" name="action" value="final_note">
      <div class="tp-feld">
        <label for="final_note">Hinweis zum festgelegten Termin</label>
        <p class="um-klein tp-hilfe">Steht bei allen unter dem Datum und in der Kalenderdatei – zum Beispiel der Raum oder was mitzubringen ist.</p>
        <textarea name="final_note" id="final_note" rows="2" maxlength="500"><?= h((string)$poll['final_note']) ?></textarea>
      </div>
      <button class="btn secondary small" type="submit"><i class="ti ti-device-floppy"></i> Hinweis speichern</button>
    </form>
  </div>
<?php endif; ?>

<?php tp_ergebnis($poll, $ergebnis, $best, $anzahl, $final, true); ?>
<?php tp_matrix($poll, $optionen, $eintraege, true); ?>

<div class="section-title" id="termine"><i class="ti ti-calendar-plus"></i> Terminvorschläge</div>
<div class="card">
  <ul class="tp-optliste">
    <?php foreach ($optionen as $o): ?>
      <li>
        <span><strong><?= h(tplan_weekday((string)$o['day'])) ?>, <?= h(tplan_option_date($o)) ?></strong>
          <span class="tp-zeit"><?= h(tplan_option_time($o)) ?></span>
          <?php if (trim((string)$o['label']) !== ''): ?><span class="tp-note"><?= h($o['label']) ?></span><?php endif; ?>
          <?php if ((int)$o['capacity'] > 0): ?><span class="um-klein">· höchstens <?= (int)$o['capacity'] ?></span><?php endif; ?>
        </span>
        <form method="post"><?= tplan_csrf_field() ?>
          <input type="hidden" name="action" value="del_option"><input type="hidden" name="option_id" value="<?= (int)$o['id'] ?>">
          <button class="btn danger small" type="submit" aria-label="Terminvorschlag entfernen"><i class="ti ti-trash"></i></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($optionen) < tplan_limits()['options']): ?>
    <form method="post" class="tp-add"><?= tplan_csrf_field() ?>
      <input type="hidden" name="action" value="add_option">
      <div class="tp-row">
        <div class="tp-row-f tp-f-day"><label for="nd">Datum</label><input type="date" class="fp-date" name="day" id="nd" required></div>
        <div class="tp-row-f"><label for="nf">von</label><input type="time" class="fp-time" name="t_from" id="nf"></div>
        <div class="tp-row-f"><label for="nb">bis</label><input type="time" class="fp-time" name="t_to" id="nb"></div>
        <div class="tp-row-f tp-f-label"><label for="nl">Notiz</label><input type="text" name="label" id="nl" maxlength="80"></div>
        <div class="tp-row-f tp-f-cap"><label for="nc">max.</label><input type="number" name="capacity" id="nc" min="0" max="9999"></div>
      </div>
      <button class="btn secondary small" type="submit"><i class="ti ti-plus"></i> Vorschlag hinzufügen</button>
    </form>
    <p class="um-klein">Wer schon geantwortet hat, steht bei einem neuen Vorschlag auf „Nein" – am besten kurz Bescheid geben.</p>
  <?php else: ?>
    <p class="um-klein">Die Höchstzahl von <?= (int)tplan_limits()['options'] ?> Vorschlägen ist erreicht.</p>
  <?php endif; ?>
</div>

<?php if ($eintraege): ?>
  <div class="section-title" id="rueckmeldungen"><i class="ti ti-users"></i> Rückmeldungen</div>
  <div class="card">
    <ul class="tp-optliste">
      <?php foreach ($eintraege as $e): ?>
        <li>
          <span><strong><?= h((string)$e['name']) ?></strong>
            <?php if (trim((string)$e['email']) !== ''): ?><span class="um-klein">· <?= h((string)$e['email']) ?></span><?php endif; ?>
            <?php if (trim((string)$e['comment']) !== ''): ?><br><span class="um-klein"><?= h((string)$e['comment']) ?></span><?php endif; ?>
          </span>
          <form method="post"><?= tplan_csrf_field() ?>
            <input type="hidden" name="action" value="del_entry"><input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>">
            <button class="btn danger small" type="submit" aria-label="Rückmeldung löschen"><i class="ti ti-trash"></i></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<details class="tp-more" id="einstellungen">
  <summary><i class="ti ti-settings"></i> Einstellungen ändern</summary>
  <form method="post">
    <?= tplan_csrf_field() ?><input type="hidden" name="action" value="settings">
    <div class="card">
      <div class="tp-feld">
        <label for="title">Titel <span class="tp-pflicht">*</span></label>
        <input type="text" name="title" id="title" maxlength="160" required value="<?= h($v('title')) ?>">
      </div>
      <div class="tp-feld">
        <label for="intro">Beschreibung</label>
        <textarea name="intro" id="intro" rows="3" maxlength="4000"><?= h($v('intro')) ?></textarea>
      </div>
      <div class="tp-two">
        <div class="tp-feld"><label for="place">Ort</label>
          <input type="text" name="place" id="place" maxlength="200" value="<?= h($v('place')) ?>"></div>
        <div class="tp-feld"><label for="organizer">Wer fragt?</label>
          <input type="text" name="organizer" id="organizer" maxlength="120" value="<?= h($v('organizer')) ?>"></div>
      </div>
      <div class="tp-two">
        <div class="tp-feld"><label for="deadline_day">Antworten bis</label>
          <input type="date" class="fp-date" name="deadline_day" id="deadline_day" value="<?= h($v('deadline_day')) ?>"></div>
        <div class="tp-feld"><label for="deadline_time">Uhrzeit</label>
          <input type="time" class="fp-time" name="deadline_time" id="deadline_time" value="<?= h($v('deadline_time')) ?>"></div>
      </div>
      <?php /* Hier bewusst die Einzelfelder statt des großen Umschalters: Beim Anlegen soll es
               einfach sein, beim Nachjustieren vollständig – etwa „gar nicht mehr änderbar",
               wenn die Runde durch ist. */ ?>
      <p class="um-klein"><i class="ti ti-info-circle"></i> Aktuell:
        <strong><?= h(match (tplan_zugang($poll)) {
            'offen' => 'offene Liste – alle dürfen jeden Eintrag ändern, ohne Mailadressen',
            'mail'  => 'persönlicher Link per Mail – jede Person ändert nur ihre eigene Antwort',
            default => 'eigene Zusammenstellung',
        }) ?></strong></p>
      <?php tplan_settings_fields($an, $v); ?>
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
    </div>
  </form>
</details>

<div class="section-title"><i class="ti ti-tools"></i> Umfrage steuern</div>
<div class="card">
  <form method="post" class="tp-inline"><?= tplan_csrf_field() ?>
    <input type="hidden" name="action" value="toggle_closed">
    <button class="btn secondary" type="submit"><i class="ti ti-<?= empty($poll['closed']) ? 'lock' : 'lock-open' ?>"></i>
      <?= empty($poll['closed']) ? 'Umfrage schließen' : 'Umfrage wieder öffnen' ?></button>
  </form>
  <p class="um-klein"><?= empty($poll['closed'])
      ? 'Geschlossen heißt: Das Ergebnis bleibt sichtbar, aber niemand kann mehr antworten.'
      : 'Die Umfrage ist geschlossen. Ein Klick, und sie nimmt wieder Antworten an.' ?></p>

  <details class="tp-gefahr">
    <summary><i class="ti ti-trash"></i> Terminumfrage löschen</summary>
    <p>Das entfernt die Umfrage mit allen <?= $anzahl ?> Rückmeldungen sofort und endgültig. Beide Links führen
       danach ins Leere. Rückgängig machen können wir das nicht – es gibt keine Sicherungskopie einzelner Umfragen.</p>
    <form method="post"><?= tplan_csrf_field() ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="bestaetigt" value="ja">
      <button class="btn danger" type="submit"><i class="ti ti-trash"></i> Ja, endgültig löschen</button>
    </form>
  </details>
</div>
</div></div><!-- /.tp-col /.pat-in -->
<?php
tplan_picker_script();
tplan_copy_script();
tplan_foot();
