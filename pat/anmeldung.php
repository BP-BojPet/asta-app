<?php
/**
 * Pat:innenprogramm – die Anmelde-Schritte nach der Rollenwahl.
 *
 * Gleiches Design wie die Einstiegsseite (randloser Hero, große Auswahl-Kacheln), nur mit
 * kompakterem Hero samt Schrittanzeige. BEIDE Rollen durchlaufen dieselben Schritte – zuerst
 * Bachelor oder Master, dann Studiengang (ggf. mit Unterpunkt) aus der Liste, die die
 * Verwaltung pflegt; nur das Wording unterscheidet sich, wo es Sinn ergibt (Erstis sagen,
 * was sie anfangen – Pat:innen, wen sie begleiten können). Alles läuft über die URL
 * (?deg=…&c=…) – kein Zustand auf dem Server, jeder Schritt ist verlinkbar und der
 * Zurück-Knopf des Browsers funktioniert einfach.
 *
 * Danach folgt Schritt 3 mit den Angaben (Name, Mailadresse zweimal, „Über mich") samt
 * Datenschutz-Häkchen, und nach dem Speichern per Weiterleitung die Danke-Seite.
 */

require_once __DIR__ . '/pat-lib.php';

$slug  = pat_param($_GET['p'] ?? '');
$role  = pat_param($_GET['r'] ?? '');
$round = pat_round_by_slug($slug);

// Ungültiger Link oder Rolle → zurück an den Anfang, nichts erklären.
if ($round === null || !in_array($role, ['ersti', 'pate'], true)) {
    header('Location: index.php' . ($slug !== '' ? '?p=' . rawurlencode($slug) : ''));
    exit;
}
// Nur bei offener Anmeldung geht es weiter (Entwurf/geschlossen erklärt die Startseite).
if ((string)$round['status'] !== 'open') {
    header('Location: index.php?p=' . rawurlencode($slug));
    exit;
}

$label   = pat_round_label($round);
$isErsti = $role === 'ersti';

/** Pflegbaren Text holen und die Platzhalter des Programms einsetzen (Semester + Zeitplan). */
$vars = pat_round_vars($round);
$t = function (string $key) use ($vars): string {
    return trim(pat_text_fill(pat_text($key), $vars));
};
/** Rollen-Weiche: Ersti-Text oder Pat:innen-Text – gleiche Schritte, angepasstes Wording. */
$rt = fn (string $erstiKey, string $pateKey): string => $t($isErsti ? $erstiKey : $pateKey);

// Basis-Adressen dieses Durchlaufs (roh; beim Ausgeben escapt h() das & selbst)
$wizBase   = 'anmeldung.php?p=' . rawurlencode($slug) . '&r=' . $role;
$backIndex = 'index.php?p=' . rawurlencode($slug);

// --- Auswahl aus der URL prüfen (alles Unbekannte → einen Schritt zurück) ---
$deg = pat_param($_GET['deg'] ?? '');
if ($deg !== '' && !isset(pat_degrees()[$deg])) {
    header('Location: ' . $wizBase);
    exit;
}
$course = null;
$parent = null;   // bei einem gewählten Unterpunkt: der Studiengang darüber
$kids   = [];     // bei einem gewählten Studiengang: seine Unterpunkte (z. B. Erstfächer)
$cid = (int)pat_param($_GET['c'] ?? 0);
if ($cid > 0) {
    $course = pat_course_get($cid);
    if (!$course || (int)$course['active'] !== 1 || $deg === '' || (string)$course['degree'] !== $deg) {
        header('Location: ' . $wizBase . ($deg !== '' ? '&deg=' . $deg : ''));
        exit;
    }
    if ((int)$course['parent_id'] > 0) {
        $parent = pat_course_get((int)$course['parent_id']);
    } else {
        $kids = pat_course_children((int)$course['id']);
    }
}
// Freitext („meins ist nicht dabei") – wird später von Hand zugeordnet. Er ist erlaubt auf der
// Studiengangs-Ebene (kein c) UND auf der Unterpunkt-Ebene (c = Studiengang mit Unterpunkten).
$free = trim((string)preg_replace('/\s+/u', ' ', pat_param($_GET['cf'] ?? '')));
if ($course !== null && ((int)$course['parent_id'] > 0 || !$kids)) $free = ''; // Auswahl ist schon final
if ($free !== '' && ($deg === '' || mb_strlen($free) < 2 || mb_strlen($free) > 120)) {
    header('Location: ' . $wizBase . ($deg !== '' ? '&deg=' . $deg : ''));
    exit;
}
// Final ist die Auswahl mit: einem Unterpunkt, einem Studiengang ohne Unterpunkte oder Freitext.
$picked = $free !== '' || ($course !== null && ((int)$course['parent_id'] > 0 || !$kids));

// --- Weitere Fächer: der Extra-Schritt NUR für Pat:innen ---------------------
// Nach der eigenen Auswahl dürfen Pat:innen angeben, bei welchen anderen Unterpunkten
// DESSELBEN Studiengangs sie ebenfalls helfen können. Erstsemester bekommen diesen Schritt
// nicht zu sehen. Welche Fächer überhaupt zur Wahl stehen, entscheidet pat_extras_choices()
// – dieselbe Funktion, die beim Speichern noch einmal aussiebt. Die Auswahl steht wie alles
// andere in der Adresse (x[]), damit auch hier der Zurück-Knopf funktioniert; „xd=1" merkt
// sich, dass der Schritt durchlaufen wurde (sonst ließe sich „nichts ausgewählt" nicht von
// „noch nicht gefragt" unterscheiden).
$extraPool = [];
if (!$isErsti && $picked) {
    $extraPool = pat_extras_choices([
        'course_id'        => $free !== '' ? 0 : (int)($course['id'] ?? 0),
        'course_parent_id' => ($free !== '' && $course !== null) ? (int)$course['id'] : 0,
    ]);
}
$extraQ    = $t('wiz_extra_q');                    // leer = der Schritt entfällt ganz
$extraStep = $extraPool !== [] && $extraQ !== '';
$extraDone = pat_param($_GET['xd'] ?? '') === '1';

// Angehakte Fächer aus der Adresse holen und auf den erlaubten Vorrat eindampfen.
$extraIds = [];
$extraRaw = $_GET['x'] ?? [];
if ($extraStep && is_array($extraRaw)) {
    $erlaubt = array_map(fn ($k) => (int)$k['id'], $extraPool);
    foreach ($extraRaw as $v) {
        $n = (int)pat_param($v);
        if ($n > 0 && in_array($n, $erlaubt, true)) $extraIds[$n] = true;
    }
}
$extraIds = array_keys($extraIds);
// Anhang für die Adressen der Folgeschritte (eckige Klammern gehören kodiert in eine URL).
$extraQuery = '';
foreach ($extraIds as $id) $extraQuery .= '&x%5B%5D=' . (int)$id;

// Anzeigename der finalen Auswahl („Gymnasiallehramt – Deutsch") – wird auch als eingefrorene
// Studiengangs-Angabe mit der Anmeldung gespeichert.
$studLabel = '';
if ($picked) {
    if ($free !== '') {
        $studLabel = ($course !== null ? (string)$course['name'] . ' – ' : '') . $free;
    } elseif ($parent !== null) {
        $studLabel = (string)$parent['name'] . ' – ' . (string)$course['name'];
    } else {
        $studLabel = (string)$course['name'];
    }
}

// Abgeschlossene Anmeldung (nach dem Speichern per Redirect hierher – ohne Personendaten in der URL)
$done = ($_GET['done'] ?? '') === '1';

// --- Schritt 3 abgeschickt: prüfen, speichern, weiterleiten ------------------
$formError = '';
$old = ['first_name' => '', 'last_name' => '', 'email' => '', 'email2' => '', 'about' => '', 'privacy' => ''];
// Gibt es überhaupt einen Einwilligungstext? Nur dann steht das Häkchen im Formular – und nur
// dann darf es Pflicht sein, sonst wäre die Anmeldung durch einen leeren Text blockiert.
$needConsent = trim($rt('form_privacy_ersti', 'form_privacy_pate')) !== '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'signup' && $picked && !$done) {
    pat_check_csrf();
    // Honigtopf: das versteckte Feld füllen nur Bots – denen zeigen wir kommentarlos die Danke-Seite.
    if (trim(pat_param($_POST['website'] ?? '')) !== '') {
        header('Location: ' . $wizBase . '&done=1');
        exit;
    }
    foreach (array_keys($old) as $k) $old[$k] = pat_param($_POST[$k] ?? '');
    // Tippfehler-Bremse: die Adresse muss zweimal gleich eingegeben werden. Groß-/Kleinschreibung
    // und Leerraum ignorieren wir dabei – das ist kein Gedächtnistest.
    // Beide Einwände zusammen melden (Leerzeile = eigener Absatz), damit niemand zweimal
    // abschicken muss, um beide Hinweise zu sehen.
    $errs = [];
    $mail2 = trim(pat_param($_POST['email2'] ?? ''));
    if (mb_strtolower(trim($old['email'])) !== mb_strtolower($mail2)) {
        $errs[] = $t('form_email_mismatch');
    }
    // Einwilligung: das required-Attribut ist nur Bequemlichkeit im Browser – verbindlich ist
    // diese Prüfung. Ohne Häkchen wird NICHTS gespeichert.
    if ($needConsent && $old['privacy'] === '') {
        $errs[] = $t('form_privacy_error');
    }
    $formError = implode("\n\n", array_filter(array_map('trim', $errs)));
    $res = $formError !== '' ? ['ok' => false, 'error' => '', 'dupe' => false] : pat_signup_create([
        'round_id'     => (int)$round['id'],
        'role'         => $role,
        'degree'       => $deg,
        'course_id'    => $free !== '' ? 0 : (int)$course['id'],
        // Freitext UNTER einem Studiengang: dessen Zugehörigkeit festhalten, damit die
        // Einteilung die Person weiter zu ihrer Kategorie zählt.
        'course_parent_id' => ($free !== '' && $course !== null) ? (int)$course['id'] : 0,
        'course_free'  => $free,
        'course_label' => $studLabel,
        // Weitere Fächer der Pat:in (bei Erstis immer leer, siehe oben)
        'extra_ids'    => $extraIds,
        'first_name'   => $old['first_name'],
        'last_name'    => $old['last_name'],
        'email'        => $old['email'],
        'about'        => $old['about'],
    ]);
    if ($res['ok']) {
        header('Location: ' . $wizBase . '&done=1');
        exit;
    }
    if ($formError === '') $formError = $res['dupe'] ? $t('form_dupe') : (string)$res['error'];
}

$title = $isErsti ? $t('wiz_ersti_title') : $t('wiz_pate_title');
if ($title === '') $title = 'Anmeldung';

// Schrittanzeige: 1 Rolle (erledigt) · 2 Dein Studium · 3 Deine Angaben · 4 Fertig
// Die Frage nach den weiteren Fächern gehört inhaltlich noch zu „Dein Studium" – die Anzeige
// bekommt deshalb keinen fünften Punkt, der nur bei einer der beiden Rollen auftaucht.
$stepCur   = $done ? 5 : (($picked && (!$extraStep || $extraDone)) ? 3 : 2);
$stepNames = [$isErsti ? 'Erstsemester' : 'Pat:in', 'Dein Studium', 'Deine Angaben', 'Fertig'];

pat_head('Pat:innenprogramm ' . $label, '', '', true, 'cinema');
?>
<section class="pat-hero sub">
  <div class="pat-in">
    <p class="pat-hero-chip"><i class="ti ti-calendar-heart" aria-hidden="true"></i> <?= h($label) ?></p>
    <h1><?= h($title) ?></h1>
    <ol class="pat-steps dark">
      <?php foreach ($stepNames as $i => $s): $n = $i + 1; ?>
        <li<?= $n === $stepCur ? ' aria-current="step"' : ($n < $stepCur ? ' class="done"' : '') ?>>
          <span class="pat-step-n"><?= $n < $stepCur ? '<i class="ti ti-check" aria-hidden="true"></i>' : $n ?></span>
          <?= h($s) ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<div class="pat-in pat-wiz">
<?php if ($done): ?>
  <!-- Geschafft: nach dem Speichern (Redirect, damit Neuladen nichts doppelt abschickt) -->
  <div class="pat-done">
    <span class="pat-done-ico"><i class="ti ti-circle-check" aria-hidden="true"></i>
      <?php // Konfetti: reine Deko, deshalb komplett vor Screenreadern versteckt.
            // Zehn leere Elemente – Winkel, Weite und Farbe je Schnipsel stehen in pat.css. ?>
      <span class="pat-konfetti" aria-hidden="true"><?= str_repeat('<i></i>', 10) ?></span></span>
    <?php if ($t('done_title') !== ''): ?><h2 class="pat-done-t"><?= h($t('done_title')) ?></h2><?php endif; ?>
    <?php if ($t('done_body') !== ''): ?><div class="pat-done-b"><?= pat_paragraphs($t('done_body')) ?></div><?php endif; ?>
    <?php // Hier ist die Frage „und wann höre ich was?" am naheliegendsten – Termine direkt dazu.
          $sched = pat_schedule_items($round); if ($sched): ?>
      <ul class="pat-sched center">
        <?php foreach ($sched as $s): ?>
          <li><i class="ti <?= h($s['icon']) ?>" aria-hidden="true"></i>
            <span class="pat-sched-l"><?= h($s['label']) ?></span>
            <strong><?= h($s['date']) ?></strong></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

<?php elseif ($deg === ''): ?>
  <!-- Schritt 2a: Bachelor oder Master? -->
  <?php if ($rt('wiz_degree_q', 'wiz_p_degree_q') !== ''): ?>
    <h2 class="pat-q"><?= h($rt('wiz_degree_q', 'wiz_p_degree_q')) ?></h2>
  <?php endif; ?>
  <div class="pat-duo flat">
    <a class="pat-tile bachelor" href="<?= h($wizBase . '&deg=bachelor') ?>">
      <span class="pat-tile-ico"><i class="ti ti-book-2" aria-hidden="true"></i></span>
      <span class="pat-tile-t">Bachelor</span>
      <?php if ($rt('wiz_bachelor_desc', 'wiz_p_bachelor_desc') !== ''): ?>
        <span class="pat-tile-lead"><?= h($rt('wiz_bachelor_desc', 'wiz_p_bachelor_desc')) ?></span>
      <?php endif; ?>
      <span class="pat-tile-cta">Weiter <i class="ti ti-arrow-right" aria-hidden="true"></i></span>
    </a>
    <a class="pat-tile master" href="<?= h($wizBase . '&deg=master') ?>">
      <span class="pat-tile-ico"><i class="ti ti-certificate" aria-hidden="true"></i></span>
      <span class="pat-tile-t">Master</span>
      <?php if ($rt('wiz_master_desc', 'wiz_p_master_desc') !== ''): ?>
        <span class="pat-tile-lead"><?= h($rt('wiz_master_desc', 'wiz_p_master_desc')) ?></span>
      <?php endif; ?>
      <span class="pat-tile-cta">Weiter <i class="ti ti-arrow-right" aria-hidden="true"></i></span>
    </a>
  </div>
  <p class="pat-back"><a href="<?= h($backIndex) ?>"><i class="ti ti-arrow-left" aria-hidden="true"></i> Zurück zur Auswahl</a></p>

<?php elseif (!$picked && $course === null): ?>
  <!-- Schritt 2b: Studiengang aus der (in der Verwaltung gepflegten) Liste -->
  <?php $courses = pat_courses($deg); ?>
  <?php if ($rt('wiz_course_q', 'wiz_p_course_q') !== ''): ?>
    <h2 class="pat-q"><?= h($rt('wiz_course_q', 'wiz_p_course_q')) ?> <span class="pat-q-tag"><?= h(pat_degrees()[$deg]) ?></span></h2>
  <?php endif; ?>
  <?php if ($courses): ?>
    <div class="pat-grid">
      <?php foreach ($courses as $c): ?>
        <a class="pat-pick" href="<?= h($wizBase . '&deg=' . $deg . '&c=' . (int)$c['id']) ?>">
          <span><?= h((string)$c['name']) ?></span>
          <i class="ti ti-arrow-right" aria-hidden="true"></i>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="pat-note warn">
      <i class="ti ti-list-search" aria-hidden="true"></i>
      <div class="pat-note-b"><?= pat_paragraphs($t('wiz_course_empty')) ?></div>
    </div>
  <?php endif; ?>
  <?php if ($t('wiz_other_title') !== ''): ?>
    <div class="pat-other">
      <h3 class="pat-other-t"><?= h($t('wiz_other_title')) ?></h3>
      <?php if ($t('wiz_other_hint') !== ''): ?>
        <p class="pat-other-d"><?= nl2br(h($t('wiz_other_hint'))) ?></p>
      <?php endif; ?>
      <form method="get" action="anmeldung.php" class="pat-other-form">
        <input type="hidden" name="p" value="<?= h($slug) ?>">
        <input type="hidden" name="r" value="<?= h($role) ?>">
        <input type="hidden" name="deg" value="<?= h($deg) ?>">
        <!-- aria-label statt sichtbarem Label: der Kasten hat schon eine Überschrift, aber ein
             Platzhalter allein wird von Screenreadern nicht als Feldname vorgelesen. -->
        <input type="text" name="cf" class="pat-other-in" required minlength="2" maxlength="120"
               aria-label="Dein Studiengang" placeholder="Dein Studiengang …">
        <button class="btn" type="submit">Weiter <i class="ti ti-arrow-right" aria-hidden="true"></i></button>
      </form>
    </div>
  <?php endif; ?>
  <p class="pat-back"><a href="<?= h($wizBase) ?>"><i class="ti ti-arrow-left" aria-hidden="true"></i> Abschluss ändern</a></p>

<?php elseif (!$picked): ?>
  <!-- Schritt 2c: Unterpunkt des gewählten Studiengangs (z. B. das Erstfach) -->
  <?php $subLbl = trim((string)($course['sub_label'] ?? '')) !== '' ? trim((string)$course['sub_label']) : 'Schwerpunkt'; ?>
  <?php if ($t('wiz_sub_q') !== ''): ?>
    <h2 class="pat-q"><?= h(pat_text_fill($t('wiz_sub_q'), ['{{LABEL}}' => $subLbl])) ?>
      <span class="pat-q-tag"><?= h((string)$course['name']) ?></span></h2>
  <?php endif; ?>
  <div class="pat-grid">
    <?php foreach ($kids as $k): ?>
      <a class="pat-pick" href="<?= h($wizBase . '&deg=' . $deg . '&c=' . (int)$k['id']) ?>">
        <span><?= h((string)$k['name']) ?></span>
        <i class="ti ti-arrow-right" aria-hidden="true"></i>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if ($t('wiz_other_sub_title') !== ''): ?>
    <div class="pat-other">
      <h3 class="pat-other-t"><?= h($t('wiz_other_sub_title')) ?></h3>
      <?php if ($t('wiz_other_hint') !== ''): ?>
        <p class="pat-other-d"><?= nl2br(h($t('wiz_other_hint'))) ?></p>
      <?php endif; ?>
      <form method="get" action="anmeldung.php" class="pat-other-form">
        <input type="hidden" name="p" value="<?= h($slug) ?>">
        <input type="hidden" name="r" value="<?= h($role) ?>">
        <input type="hidden" name="deg" value="<?= h($deg) ?>">
        <input type="hidden" name="c" value="<?= (int)$course['id'] ?>">
        <input type="text" name="cf" class="pat-other-in" required minlength="2" maxlength="120"
               aria-label="<?= h('Dein ' . $subLbl) ?>" placeholder="<?= h('Dein ' . $subLbl . ' …') ?>">
        <button class="btn" type="submit">Weiter <i class="ti ti-arrow-right" aria-hidden="true"></i></button>
      </form>
    </div>
  <?php endif; ?>
  <p class="pat-back"><a href="<?= h($wizBase . '&deg=' . $deg) ?>"><i class="ti ti-arrow-left" aria-hidden="true"></i> Anderen Studiengang wählen</a></p>

<?php elseif ($extraStep && !$extraDone): ?>
  <!-- Schritt 2d (nur Pat:innen): weitere Fächer, bei denen sie helfen können -->
  <?php $subLbl = trim((string)(($parent['sub_label'] ?? $course['sub_label'] ?? ''))) !== ''
          ? trim((string)($parent['sub_label'] ?? $course['sub_label']))
          : 'Schwerpunkt';
        $topName = (string)(($parent['name'] ?? $course['name']) ?? ''); ?>
  <h2 class="pat-q"><?= h(pat_text_fill($extraQ, ['{{LABEL}}' => $subLbl])) ?>
    <?php if ($topName !== ''): ?><span class="pat-q-tag"><?= h($topName) ?></span><?php endif; ?></h2>
  <?php if ($t('wiz_extra_hint') !== ''): ?>
    <div class="pat-extra-lead"><?= pat_paragraphs(pat_text_fill($t('wiz_extra_hint'), ['{{LABEL}}' => $subLbl])) ?></div>
  <?php endif; ?>
  <form method="get" action="anmeldung.php">
    <input type="hidden" name="p" value="<?= h($slug) ?>">
    <input type="hidden" name="r" value="<?= h($role) ?>">
    <input type="hidden" name="deg" value="<?= h($deg) ?>">
    <?php if ($course !== null): ?><input type="hidden" name="c" value="<?= (int)$course['id'] ?>"><?php endif; ?>
    <?php if ($free !== ''): ?><input type="hidden" name="cf" value="<?= h($free) ?>"><?php endif; ?>
    <!-- Merker: der Schritt wurde durchlaufen. Ohne ihn wäre „nichts ausgewählt" von
         „noch nicht gefragt" nicht zu unterscheiden, und der Schritt käme immer wieder. -->
    <input type="hidden" name="xd" value="1">
    <div class="pat-checks">
      <?php foreach ($extraPool as $k): ?>
        <label class="pat-check">
          <input type="checkbox" name="x[]" value="<?= (int)$k['id'] ?>"
                 <?= in_array((int)$k['id'], $extraIds, true) ? ' checked' : '' ?>>
          <span><?= h((string)$k['name']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <button class="pat-submit" type="submit">Weiter <i class="ti ti-arrow-right" aria-hidden="true"></i></button>
  </form>
  <p class="pat-back"><a href="<?= h($wizBase . '&deg=' . $deg
        . ($parent !== null ? '&c=' . (int)$parent['id'] : ($course !== null ? '&c=' . (int)$course['id'] : ''))) ?>"><i class="ti ti-arrow-left" aria-hidden="true"></i> Auswahl ändern</a></p>

<?php else: ?>
  <!-- Schritt 3: Wer bist du? -->
  <?php
    // Zurück: auf die Ebene, auf der zuletzt gewählt wurde – bei Pat:innen mit weiteren
    // Fächern aber zuerst auf deren Schritt, sonst wäre er nicht mehr erreichbar.
    $backSel = ($parent !== null) ? $wizBase . '&deg=' . $deg . '&c=' . (int)$parent['id']
             : (($course !== null && $free !== '') ? $wizBase . '&deg=' . $deg . '&c=' . (int)$course['id']
             : $wizBase . '&deg=' . $deg);
    if ($extraStep) {
        $backSel = $wizBase . '&deg=' . $deg
            . ($course !== null ? '&c=' . (int)$course['id'] : '')
            . ($free !== '' ? '&cf=' . rawurlencode($free) : '') . $extraQuery;
    }
    // Formular-Ziel: dieselbe Adresse inkl. Auswahl (die bleibt in der URL, Personendaten im POST)
    $formAction = $wizBase . '&deg=' . $deg
        . ($course !== null ? '&c=' . (int)$course['id'] : '')
        . ($free !== '' ? '&cf=' . rawurlencode($free) : '')
        . $extraQuery . ($extraStep ? '&xd=1' : '');
  ?>
  <div class="pat-col">
    <div class="pat-recap">
      <ul class="pat-recap-l">
        <li><i class="ti ti-user-check" aria-hidden="true"></i> <?= $isErsti ? 'Erstsemester' : 'Pat:in' ?></li>
        <li><i class="ti <?= $deg === 'master' ? 'ti-certificate' : 'ti-book-2' ?>" aria-hidden="true"></i> <?= h(pat_degrees()[$deg]) ?></li>
        <li><i class="ti ti-school" aria-hidden="true"></i> <?= h($studLabel) ?><?php
          if ($free !== '' && $t('wiz_other_recap') !== ''): ?> <span class="pat-recap-tag"><?= h($t('wiz_other_recap')) ?></span><?php endif; ?></li>
        <?php if ($extraIds && $t('wiz_extra_recap') !== ''):
                $extraNamen = [];
                foreach ($extraPool as $k) {
                    if (in_array((int)$k['id'], $extraIds, true)) $extraNamen[] = (string)$k['name'];
                } ?>
          <li><i class="ti ti-plus" aria-hidden="true"></i> <?= h($t('wiz_extra_recap')) ?>
            <?= h(implode(', ', $extraNamen)) ?></li>
        <?php endif; ?>
      </ul>
    </div>

    <?php if ($t('form_q') !== ''): ?>
      <h2 class="pat-q"><?= h($t('form_q')) ?></h2>
    <?php endif; ?>

    <?php if ($formError !== ''): ?>
      <div class="pat-note err">
        <i class="ti ti-alert-circle" aria-hidden="true"></i>
        <div class="pat-note-b"><?= pat_paragraphs($formError) ?></div>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= h($formAction) ?>" class="pat-form">
      <?= pat_csrf_field() ?>
      <input type="hidden" name="action" value="signup">
      <!-- Honigtopf: für Menschen unsichtbar, Bots füllen es aus -->
      <div class="pat-hp" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
      </div>

      <div class="pat-form-row">
        <div class="pat-field">
          <label for="first_name">Vorname</label>
          <input type="text" name="first_name" id="first_name" required maxlength="60"
                 autocomplete="given-name" value="<?= h($old['first_name']) ?>">
        </div>
        <div class="pat-field">
          <label for="last_name">Nachname</label>
          <input type="text" name="last_name" id="last_name" required maxlength="60"
                 autocomplete="family-name" value="<?= h($old['last_name']) ?>">
        </div>
      </div>

      <div class="pat-field">
        <label for="email">E-Mail-Adresse</label>
        <?php if ($rt('form_email_note_ersti', 'form_email_note_pate') !== ''): ?>
          <p class="pat-form-note"><?= nl2br(h($rt('form_email_note_ersti', 'form_email_note_pate'))) ?></p>
        <?php endif; ?>
        <input type="email" name="email" id="email" required maxlength="190"
               autocomplete="email" inputmode="email" value="<?= h($old['email']) ?>">
      </div>

      <div class="pat-field">
        <label for="email2">E-Mail-Adresse wiederholen</label>
        <?php if ($t('form_email2_note') !== ''): ?>
          <p class="pat-form-note"><?= nl2br(h($t('form_email2_note'))) ?></p>
        <?php endif; ?>
        <!-- autocomplete=off: sonst füllt der Browser hier dieselbe (evtl. falsche) Adresse
             automatisch ein und die Prüfung liefe ins Leere. -->
        <input type="email" name="email2" id="email2" required maxlength="190"
               autocomplete="off" inputmode="email" value="<?= h($old['email2']) ?>">
      </div>

      <div class="pat-field">
        <label for="about">Über mich<?= $isErsti ? '<span class="pat-opt">freiwillig</span>' : '' ?></label>
        <?php if ($rt('form_about_note_ersti', 'form_about_note_pate') !== ''): ?>
          <p class="pat-form-note"><?= nl2br(h($rt('form_about_note_ersti', 'form_about_note_pate'))) ?></p>
        <?php endif; ?>
        <textarea name="about" id="about" rows="4" maxlength="2000"<?= $isErsti ? '' : ' required' ?>><?= h($old['about']) ?></textarea>
      </div>

      <?php // Einwilligung: Pflicht-Häkchen. Ohne Text im Register gibt es keins (und dann auch
            // keine Prüfung) – deshalb hängt beides an derselben Bedingung, siehe $needConsent.
            $consent = trim(pat_text_fill($rt('form_privacy_ersti', 'form_privacy_pate'),
                ['{{MONATE}}' => (string)PAT_DELETE_MONTHS])); ?>
      <?php if ($consent !== ''): ?>
        <div class="pat-consent">
          <!-- Der Text steht BEWUSST in einem span und nicht in einer Beschriftung mit
               for-Bezug: darin verschluckt Firefox den Klick auf den Datenschutz-Link (es
               kreuzt das Kästchen an, statt dem Link zu folgen – Safari folgt ihm). Über
               aria-labelledby ist der Text trotzdem die Beschriftung, und der Link
               funktioniert in jedem Browser. -->
          <input type="checkbox" name="privacy" id="privacy" value="1" required
                 aria-labelledby="privacyTxt"<?= $old['privacy'] !== '' ? ' checked' : '' ?>>
          <span class="pat-consent-t" id="privacyTxt"><?= pat_text_privacy_html($consent) ?></span>
        </div>
      <?php endif; ?>

      <button class="pat-submit" type="submit">
        Anmeldung abschicken <i class="ti ti-arrow-right" aria-hidden="true"></i>
      </button>

      <?php $priv = trim(pat_text_fill($t('form_privacy'), ['{{MONATE}}' => (string)PAT_DELETE_MONTHS])); ?>
      <?php if ($priv !== ''): ?>
        <p class="pat-privacy"><i class="ti ti-lock" aria-hidden="true"></i> <?= pat_text_privacy_html($priv) ?></p>
      <?php endif; ?>
    </form>

    <p class="pat-back"><a href="<?= h($backSel) ?>"><i class="ti ti-arrow-left" aria-hidden="true"></i> Auswahl ändern</a></p>
  </div>
<?php endif; ?>
</div><!-- /.pat-in -->
<?php
pat_foot($label, true, (int)$round['id']);
