<?php
/**
 * Pat:innenprogramm – „Schreib uns": das Hilfe-Formular der öffentlichen Seiten.
 *
 * Warum ein Formular und kein mailto:-Link? Ein mailto: setzt voraus, dass auf dem Gerät ein
 * Mailprogramm eingerichtet ist. Bei Studierenden, die die Uni-Adresse im Browser lesen, ist
 * das oft nicht der Fall – der Klick öffnet dann nichts oder ein leeres Outlook, und die Frage
 * wird nie gestellt. Hier wird sie stattdessen abgelegt; der Versand-Cron macht daraus eine
 * Mail an den AStA, mit der Adresse der fragenden Person als ANTWORTADRESSE.
 *
 * Die Seite steht für sich (Link vom schwebenden Knopf) und liefert dasselbe Formular auch dem
 * Dialog aus, den derselbe Knopf mit JavaScript öffnet. Abgeschickt wird immer hierher – der
 * Dialog nur über fetch, damit eine halb ausgefüllte Anmeldung nicht verlorengeht.
 *
 * Zugriff auf Daten: schreibend ausschließlich in pat_messages, lesend nur auf das Programm
 * hinter der mitgegebenen Nummer. Es gibt hier keinen Codepfad, der Anmeldungen sieht.
 */

require_once __DIR__ . '/pat-lib.php';

// Wohin es zurückgeht. Der Wert kommt von außen und wird deshalb streng gefiltert.
$von = pat_help_back(pat_param($_POST['von'] ?? $_GET['von'] ?? ''));

// Programm, aus dem heraus gefragt wurde – nur zur Einordnung in der Verwaltung.
$roundId = (int)pat_param($_POST['round'] ?? $_GET['r'] ?? '0');
$round   = $roundId > 0 ? pat_round_get($roundId) : null;
$label   = $round ? pat_round_label($round) : '';

$fertig = ($_GET['done'] ?? '') === '1';
$fehler = '';
$alt    = ['name' => '', 'email' => '', 'body' => ''];

// --- Abgeschickt ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'frage') {
    pat_check_csrf();
    // Der Dialog schickt per fetch und möchte eine Antwort in JSON statt einer ganzen Seite.
    $perSkript = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    /** Fertig – je nach Weg als JSON oder als Weiterleitung auf die Dankeseite. */
    $raus = function (bool $ok, string $meldung = '') use ($perSkript, $von, $roundId) {
        if ($perSkript) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'error' => $meldung], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($ok) {
            header('Location: hilfe.php?done=1' . ($von !== '' ? '&von=' . rawurlencode($von) : '')
                . ($roundId > 0 ? '&r=' . $roundId : ''));
            exit;
        }
    };

    // Honigtopf: das versteckte Feld füllen nur Bots. Denen sagen wir freundlich „danke" und
    // speichern nichts – ein sichtbarer Fehler wäre nur eine Anleitung zum Nachbessern.
    if (trim(pat_param($_POST['website'] ?? '')) !== '') $raus(true);

    foreach (array_keys($alt) as $k) $alt[$k] = pat_param($_POST[$k] ?? '');

    if (!pat_message_rate_ok()) {
        $fehler = 'Du hast gerade schon mehrere Fragen abgeschickt. Warte bitte kurz – '
                . 'wir haben die vorherigen und melden uns.';
    } else {
        $res = pat_message_add([
            'round_id' => $roundId,
            'name'     => $alt['name'],
            'email'    => $alt['email'],
            'body'     => $alt['body'],
            'page'     => $von !== '' ? $von : 'hilfe.php',
        ]);
        if (!$res['ok']) $fehler = (string)$res['error'];
    }
    $raus($fehler === '', $fehler);
    // Ohne JavaScript und mit Fehler: die Seite wird unten mit der Meldung neu aufgebaut.
}

$t = function (string $key) use ($round): string {
    $vars = $round ? pat_round_vars($round)
                   : ['{{PROGRAMM}}' => '', '{{ANMELDUNG_VON}}' => '', '{{ANMELDUNG_BIS}}' => '',
                      '{{EINTEILUNG_BIS}}' => ''];
    return trim(pat_text_fill(pat_text($key), $vars));
};

pat_head(($label !== '' ? 'Frage zum Pat:innenprogramm ' . $label : 'Frage zum Pat:innenprogramm'),
    '', '', true);
?>
<div class="pat-col">
  <?php if ($fertig): ?>
    <div class="pat-done">
      <span class="pat-done-ico"><i class="ti ti-mail-check" aria-hidden="true"></i></span>
      <h1 class="pat-done-t">Danke für deine Frage!</h1>
      <?php if ($t('help_form_done') !== ''): ?>
        <div class="pat-done-b"><?= pat_paragraphs($t('help_form_done')) ?></div>
      <?php endif; ?>
      <p class="pat-back">
        <a href="<?= h($von !== '' ? $von : 'index.php') ?>">
          <i class="ti ti-arrow-left" aria-hidden="true"></i>
          <?= $von !== '' ? 'Zurück zur Seite' : 'Zum Pat:innenprogramm' ?></a>
      </p>
    </div>
  <?php else: ?>
    <?php if ($label !== ''): ?><p class="pat-kicker"><?= h($label) ?></p><?php endif; ?>
    <h1><?= h($t('help_form_title') !== '' ? $t('help_form_title') : 'Schreib uns') ?></h1>
    <?php if ($t('help_form_lead') !== ''): ?>
      <div class="pat-prose pat-lead"><?= pat_paragraphs($t('help_form_lead')) ?></div>
    <?php endif; ?>
    <?php if ($fehler !== ''): ?>
      <div class="pat-note err">
        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
        <div class="pat-note-b"><?= pat_paragraphs($fehler) ?></div>
      </div>
    <?php endif; ?>
    <?= pat_help_form($von, $roundId, $alt) ?>
    <?php if ($t('help_who') !== ''): ?>
      <p class="pat-privacy"><i class="ti ti-users" aria-hidden="true"></i>
        <span><?= h($t('help_who')) ?></span></p>
    <?php endif; ?>
    <?php if ($von !== ''): ?>
      <p class="pat-back"><a href="<?= h($von) ?>">
        <i class="ti ti-arrow-left" aria-hidden="true"></i> Zurück ohne zu fragen</a></p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
// Auf der Hilfe-Seite selbst braucht es weder den schwebenden Knopf noch die Hilfe-Zeile.
pat_foot($label, false);
