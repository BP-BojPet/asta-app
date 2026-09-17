<?php
/**
 * Verwaltung → Pat:innenprogramm → ein einzelnes Programm.
 *
 * Alles zu EINEM Semester an einer Stelle: Kennzahlen, Wunsch-Gruppengröße, automatische
 * Einteilung, Zuteilung von Hand, sämtliche Anmeldedaten und der CSV-Export.
 *
 * Zugriff wie die Übersicht: Admin & Vorsitz plus die festgelegten Verantwortlichen.
 */

$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_login();
if (!can_admin() && !is_pat_manager()) {
    flash('Diese Seite ist nur für Admins und die Verantwortlichen des Pat:innenprogramms.', 'error');
    redirect(base() . 'dashboard.php');
}
require_once __DIR__ . '/../pat-db.php';

$rid   = (int)($_GET['id'] ?? 0);
$round = pat_round_get($rid);
if (!$round) {
    flash('Programm nicht gefunden.', 'error');
    redirect('paten.php');
}
$self = 'paten-programm.php?id=' . $rid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'groupsize') {
        $min = (int)($_POST['group_min'] ?? 0);
        $max = (int)($_POST['group_max'] ?? 0);
        if (pat_round_set_groupsize($rid, $min, $max)) {
            flash('Wunsch-Gruppengröße gespeichert: ' . $min . '–' . $max . ' Erstsemester je Pat:in.', 'success');
        } else {
            flash('Ungültige Gruppengröße (Untergrenze ab 1, Obergrenze nicht kleiner als die Untergrenze).', 'error');
        }
        redirect($self . '&t=einteilung');
    }

    if ($action === 'assign_auto' || $action === 'assign_new') {
        $res = pat_assign_auto($rid, $action === 'assign_new');
        if (!$res['ok']) {
            flash($res['error'], 'error');
        } else {
            flash($res['assigned'] . ' Erstsemester eingeteilt (Gruppengröße bis ' . $res['cap'] . ').'
                . ($res['note'] !== '' ? ' ' . $res['note'] : ''),
                ($res['unmatched'] ?? 0) > 0 ? 'error' : 'success');
        }
        redirect($self . '&t=einteilung');
    }

    if ($action === 'assign_clear') {
        $n = pat_assign_clear($rid);
        flash($n . ' Zuteilungen gelöst – alle Erstsemester sind wieder frei.', 'success');
        redirect($self . '&t=einteilung');
    }

    if ($action === 'assign_one') {
        $pid = (int)($_POST['pate_id'] ?? 0);
        flash(pat_assign_set((int)($_POST['ersti_id'] ?? 0), $pid > 0 ? $pid : null)
            ? 'Zuteilung geändert.' : 'Zuteilung nicht möglich.', $pid >= 0 ? 'success' : 'error');
        redirect($self . '&t=einteilung');
    }

    if ($action === 'delete_after') {
        $d = trim((string)($_POST['delete_after'] ?? ''));
        if (pat_round_set_delete_after($rid, $d)) {
            flash($d === '' ? 'Löschfrist entfernt – dieses Programm wird nicht mehr automatisch gelöscht.'
                : 'Löschtag gesetzt: ' . date('d.m.Y', strtotime($d)) . '.', $d === '' ? 'error' : 'success');
        } else {
            flash('Ungültiges Datum.', 'error');
        }
        redirect($self . '&t=programm');
    }

    if ($action === 'mail_queue') {
        $res = pat_mail_queue_assignments($rid, (string)($_POST['role'] ?? 'both'));
        if (!$res['ok']) {
            flash($res['error'], 'error');
        } else {
            flash($res['queued'] . ' Mails vorbereitet.'
                . ($res['skipped'] > 0 ? ' ' . $res['skipped'] . ' bekommen nichts (ohne Gruppe bzw. leere Gruppe).' : '')
                . ' Sie gehen erst raus, wenn du unten auf „Senden" klickst.', 'success');
        }
        redirect($self . '&t=mails');
    }

    if ($action === 'schedule') {
        $res = pat_round_set_schedule($rid, (string)($_POST['signup_from'] ?? ''),
            (string)($_POST['signup_to'] ?? ''), (string)($_POST['match_by'] ?? ''));
        flash($res['ok'] ? 'Zeitplan gespeichert.' : $res['error'], $res['ok'] ? 'success' : 'error');
        redirect($self . '&t=programm');
    }

    if ($action === 'mail_reject') {
        $res = pat_mail_queue_rejections($rid, (string)($_POST['role'] ?? 'both'));
        if (!$res['ok']) {
            flash($res['error'], 'error');
        } elseif ($res['queued'] === 0) {
            flash('Es gibt niemanden abzusagen.', 'error');
        } else {
            flash($res['queued'] . ' Absage' . ($res['queued'] === 1 ? '' : 'n') . ' vorbereitet. '
                . 'Sie gehen erst raus, wenn du oben bei den Mails auf „Alle verschicken" klickst.', 'success');
        }
        redirect($self . '&t=mails');
    }

    if ($action === 'mail_send') {
        // EIN Klick verschickt die ganze Warteschlange. Damit dabei nie das Zeitlimit von PHP
        // zuschlägt (dann wüsste niemand, wie weit es kam), läuft der Versand gegen ein
        // Zeitbudget: ist es aufgebraucht, hört er sauber auf und der Rest bleibt stehen –
        // den holt der tägliche Cron-Lauf, oder man klickt einfach noch einmal.
        @set_time_limit(0);
        ignore_user_abort(true);   // Tab zu = trotzdem zu Ende senden
        $secs   = max(5, min(300, (int)setting_get('pat_mail_seconds', '120')));
        $dayCap = max(0, min(3000, (int)setting_get('pat_mail_day_cap', '0')));
        $budget = pat_mail_budget_left($dayCap);
        if ($budget <= 0) {
            flash('Für dieses 24-Stunden-Fenster ist das Mail-Kontingent aufgebraucht ('
                . pat_mail_sent_last24() . ' von ' . $dayCap . '). Der stündliche Cron-Lauf macht '
                . 'automatisch weiter, sobald wieder Luft ist – du musst nichts tun.', 'error');
            redirect($self . '&t=mails');
        }
        $snd = pat_mail_sender(mail_from()); // Antworten sollen bei den Verantwortlichen landen, nicht in der App
        // $cc: die Gruppen-Mail trägt alle Erstis der Gruppe im CC (bei allen anderen Arten leer).
        $res = pat_mail_run($budget, fn (string $to, string $s, string $b, string $cc = '')
            => send_mail($to, $s, $b, false, $snd['from'], $snd['reply'], $snd['name'], 'pat', $cc), $rid, $secs);
        $tage = pat_mail_days_needed($res['left'], $dayCap);
        flash($res['sent'] . ' Mails verschickt'
            . ($res['failed'] > 0 ? ', ' . $res['failed'] . ' fehlgeschlagen (erneut versuchen möglich)' : '')
            . ($res['left'] > 0
                ? ' – ' . $res['left'] . ' folgen automatisch über den stündlichen Cron-Lauf'
                  . ($tage > 0 ? ' (voraussichtlich fertig ' . ($tage === 1 ? 'morgen' : 'in ' . $tage . ' Tagen') . ')' : ' im Lauf der nächsten Stunden')
                  . ' – du musst nichts weiter tun.'
                : ' – alles raus.'),
            $res['failed'] > 0 ? 'error' : 'success');
        redirect($self . '&t=mails');
    }

    if ($action === 'mail_clear') {
        $what = (string)($_POST['what'] ?? 'queued');
        $n = pat_mail_clear($rid, $what);
        flash($what === 'failed' ? $n . ' fehlgeschlagene Mails zurück in die Warteschlange gelegt.'
            : $n . ' wartende Mails verworfen.', 'success');
        redirect($self . '&t=mails');
    }

    if ($action === 'signup_delete') {
        $s = pat_signup_get((int)($_POST['id'] ?? 0));
        if ($s && (int)$s['round_id'] === $rid && pat_signup_delete((int)$s['id'])) {
            flash('Anmeldung von ' . $s['first_name'] . ' ' . $s['last_name'] . ' gelöscht.', 'success');
        } else {
            flash('Anmeldung nicht gefunden.', 'error');
        }
        redirect($self . '&t=anmeldungen');
    }

    redirect($self);
}

$label = pat_round_label($round);

// --- CSV-Export (alle Daten, inkl. Zuteilung) -------------------------------
if (($_GET['csv'] ?? '') === '1') {
    $g = pat_groups_of($rid);
    $pateName = []; $pateRow = [];
    foreach ($g['groups'] as $pid => $grp) {
        $pateName[$pid] = $grp['pate']['first_name'] . ' ' . $grp['pate']['last_name'];
        $pateRow[$pid]  = $grp['pate'];   // für die Passgenauigkeit je Zeile
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pat-' . $round['term'] . $round['year'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel die Umlaute richtig liest
    // Formel-Injection: führende =, +, -, @ entschärfen
    $safe = fn ($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
    fputcsv($out, ['Rolle', 'Vorname', 'Nachname', 'E-Mail', 'Abschluss', 'Studiengang',
        'Weitere Fächer', 'Von Hand zuordnen', 'Zugeteilt an', 'Passung', 'Über mich', 'Angemeldet am'], ';');
    foreach (pat_signups_of($rid) as $s) {
        $pate = $s['role'] === 'ersti' ? ($pateRow[(int)($s['pate_id'] ?? 0)] ?? null) : null;
        $note = $pate ? pat_match_note(pat_match_score($s, $pate)) : null;
        fputcsv($out, array_map($safe, [
            $s['role'] === 'pate' ? 'Pat:in' : 'Erstsemester',
            $s['first_name'], $s['last_name'], $s['email'],
            pat_degrees()[(string)$s['degree']] ?? $s['degree'],
            $s['course_label'],
            implode(', ', pat_signup_extra_labels($s)),
            $s['course_id'] === null ? 'ja' : '',
            $s['role'] === 'ersti' ? ($pateName[(int)($s['pate_id'] ?? 0)] ?? '') : '',
            $note === null ? '' : ($note['text'] === '' ? 'genau so gewählt' : $note['text']),
            $s['about'], $s['created_at'],
        ]), ';');
    }
    fclose($out);
    exit;
}

$stats  = pat_round_stats($rid);
$g      = pat_groups_of($rid);
$mo     = pat_match_overview($g);   // Kompromisse der Einteilung (siehe pat_match_note())
$status = (string)$round['status'];

/**
 * Knopf zum Verschieben einer Person. BEWUSST kein Auswahlfeld je Zeile: bei 300 Erstis und
 * 60 Gruppen wären das rund 18.000 option-Elemente im HTML. Stattdessen öffnet der Knopf
 * EINEN gemeinsamen Dialog, in dem die Gruppenliste genau einmal steht.
 */
function pat_move_btn(array $e): string
{
    return '<button type="button" class="btn secondary small pat-move-btn"'
        . ' data-id="' . (int)$e['id'] . '"'
        . ' data-name="' . h($e['first_name'] . ' ' . $e['last_name']) . '"'
        . ' data-top="' . (int)pat_signup_top_course($e) . '"'
        . ' data-deg="' . h((string)$e['degree']) . '"'
        // Das konkrete Fach des Erstis: damit hebt der Dialog die Pat:innen hervor, die dieses
        // Fach studieren – als Erst- oder als weiteres Fach. Dieselbe Rangfolge wie die
        // Automatik, nur eben von Hand.
        . ' data-fach="' . (int)($e['course_id'] ?? 0) . '"'
        . ' data-cur="' . (int)($e['pate_id'] ?? 0) . '">'
        . '<i class="ti ti-arrows-exchange"></i> Verschieben</button>';
}

/** Alle Fächer, die eine Pat:in studiert (Erstfach + weitere), als IDs – für den Verschiebe-Dialog. */
function pat_faecher_attr(array $p): string
{
    $ids = [(int)($p['course_id'] ?? 0)];
    foreach ((array)($p['extra_ids'] ?? []) as $cid) $ids[] = (int)$cid;
    return implode(',', array_filter(array_unique($ids)));
}

/** Such-Text einer Zeile (Name, Fach, Mail, weitere Fächer) – füttert die Filterfelder. */
function pat_needle(array $s): string
{
    return mb_strtolower($s['first_name'] . ' ' . $s['last_name'] . ' '
        . $s['course_label'] . ' ' . $s['email']
        . ' ' . implode(' ', pat_signup_extra_labels($s)));
}

page_header('Pat:innenprogramm ' . $label, true);
?>
<p class="small"><a href="paten.php">‹ Pat:innenprogramm</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-heart-handshake" style="color:var(--petrol)"></i> <?= h($label) ?></h1>
  <span class="badge <?= $status === 'open' ? 'badge-meeting' : ($status === 'draft' ? 'badge-draft' : 'badge-closed') ?>">
    <?= h(pat_status_label($status)) ?></span>
</div>

<div class="event-cards" style="margin-bottom:1.2rem">
  <div class="event-card">
    <div class="ec-title"><i class="ti ti-school"></i> <?= (int)$stats['erstis'] ?> Erstsemester</div>
    <div class="ec-meta"><span><?= (int)$stats['assigned'] ?> eingeteilt · <strong<?= $stats['free'] > 0 ? ' style="color:var(--amber)"' : '' ?>><?= (int)$stats['free'] ?> ohne Gruppe</strong></span></div>
  </div>
  <div class="event-card">
    <div class="ec-title"><i class="ti ti-users-group"></i> <?= (int)$stats['pates'] ?> Pat:innen</div>
    <div class="ec-meta"><span><?= $stats['pates'] ? 'Gruppen mit ' . (int)$stats['size_min'] . '–' . (int)$stats['size_max'] . ' Erstis' : 'noch keine' ?><?= $stats['empty'] > 0 ? ' · ' . (int)$stats['empty'] . ' leer' : '' ?></span></div>
  </div>
</div>

<?php
// Reiter (Muster wie die was.läuft-Verwaltung); $self trägt ?id=…, daher &t=….
$ppTabs = [
    'einteilung'  => ['label' => 'Einteilung & Gruppen', 'icon' => 'ti-adjustments'],
    'anmeldungen' => ['label' => 'Anmeldungen',          'icon' => 'ti-table'],
    'mails'       => ['label' => 'Mails',                'icon' => 'ti-mail-forward'],
    'programm'    => ['label' => 'Zeitplan & Löschung',  'icon' => 'ti-calendar-event'],
];
$ppTab = (string)($_GET['t'] ?? '');
if (!isset($ppTabs[$ppTab])) $ppTab = 'einteilung';
?>
<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($ppTabs as $tk => $td): ?>
    <a<?= $tk === $ppTab ? ' class="on" aria-current="page"' : '' ?> href="<?= h($self) ?>&t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($ppTab === 'programm'): ?>
<div class="section-title" id="zeitplan"><i class="ti ti-calendar-event"></i> Zeitplan</div>
<div class="card"<?= pat_signup_overdue($round) ? ' style="border-left:3px solid var(--red)"' : '' ?>>
  <p class="small muted" style="margin:0 0 .8rem">Diese Daten stehen <strong>auf der Studi-Seite</strong>
    und lassen sich als Platzhalter in jeden Text und jede Mail setzen – damit niemand raten muss,
    bis wann man sich anmelden kann und wann die Einteilung kommt. Alle drei sind freiwillig; was
    leer bleibt, wird nirgends angezeigt.</p>
  <?php if (pat_signup_overdue($round)): ?>
    <p style="margin:0 0 .8rem"><i class="ti ti-alert-triangle" style="color:var(--red)"></i>
      <strong>Der Anmeldeschluss ist vorbei</strong>
      (<?= h(pat_date((string)$round['signup_to'])) ?>), die Anmeldung ist aber noch
      <strong>offen</strong>. Auf der Studi-Seite steht damit etwas anderes, als tatsächlich gilt –
      entweder oben schließen oder das Datum verschieben.</p>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="schedule">
    <div><label for="signup_from">Anmeldung ab</label>
      <input type="text" class="fp-date" name="signup_from" id="signup_from"
             placeholder="Datum wählen" value="<?= h((string)($round['signup_from'] ?? '')) ?>"></div>
    <div><label for="signup_to">Anmeldeschluss</label>
      <input type="text" class="fp-date" name="signup_to" id="signup_to"
             placeholder="Datum wählen" value="<?= h((string)($round['signup_to'] ?? '')) ?>"></div>
    <div><label for="match_by">Einteilung bis</label>
      <input type="text" class="fp-date" name="match_by" id="match_by"
             placeholder="Datum wählen" value="<?= h((string)($round['match_by'] ?? '')) ?>"></div>
    <button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
  </form>
  <p class="small muted" style="margin:.7rem 0 0">Platzhalter für die <a href="paten.php?t=texte">Texte</a>
    und Mails: <code>{{ANMELDUNG_VON}}</code> · <code>{{ANMELDUNG_BIS}}</code> ·
    <code>{{EINTEILUNG_BIS}}</code>. Der Anmeldeschluss schließt die Anmeldung <strong>nicht</strong>
    von selbst – das bleibt dein Klick oben, damit euch niemand automatisch die Tür zumacht,
    solange ihr noch Anmeldungen annehmen wollt.</p>
</div>

<?php endif; ?>

<?php if ($ppTab === 'einteilung'): ?>
<div class="section-title" id="einteilung"><i class="ti ti-adjustments"></i> Wunschgröße &amp; Einteilung</div>
<div class="card">
  <form method="post" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="groupsize">
    <div><label for="group_min">Wunschgröße von</label>
      <input type="number" name="group_min" id="group_min" min="1" max="50" value="<?= (int)$round['group_min'] ?>" style="width:6rem"></div>
    <div><label for="group_max">bis</label>
      <input type="number" name="group_max" id="group_max" min="1" max="50" value="<?= (int)$round['group_max'] ?>" style="width:6rem"></div>
    <button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
  </form>
  <p class="small muted" style="margin:.7rem 0 0">Die Spanne ist ein <strong>Wunsch</strong>: Die
    Verteilung passt sich dem tatsächlichen Angebot an. Sind zu wenige Pat:innen da, werden die
    Gruppen größer, statt Erstsemester übrig zu lassen; sind viele da, bleiben sie kleiner.
    Zugeteilt wird <strong>nach Passgenauigkeit</strong> – gleiches Fach inklusive Erstfach zuerst,
    dann gleicher Studiengang, dann gleicher Abschluss – und bei Gleichstand in die kleinste Gruppe.
    Seltene Studiengänge kommen zuerst dran.</p>

  <div class="btn-row" style="margin-top:1rem;flex-wrap:wrap;gap:.4rem">
    <form method="post" data-confirm="Alle Erstsemester neu verteilen? Bestehende Zuteilungen – auch von Hand gesetzte – werden dabei überschrieben." data-confirm-ok="Neu verteilen">
      <?= csrf_field() ?><input type="hidden" name="action" value="assign_auto">
      <button class="btn" type="submit"><i class="ti ti-wand"></i> Alle neu verteilen</button>
    </form>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="assign_new">
      <button class="btn secondary" type="submit"><i class="ti ti-user-plus"></i> Nur Neue einteilen</button>
    </form>
    <form method="post" data-confirm="Die ganze Einteilung löschen? Danach ist niemand mehr zugeteilt." data-confirm-danger data-confirm-ok="Einteilung löschen">
      <?= csrf_field() ?><input type="hidden" name="action" value="assign_clear">
      <button class="btn danger secondary" type="submit"><i class="ti ti-eraser"></i> Einteilung leeren</button>
    </form>
  </div>
  <p class="small muted" style="margin:.6rem 0 0"><strong>Nur Neue einteilen</strong> lässt alles
    Bestehende unangetastet und verteilt ausschließlich die noch freien Erstsemester – der
    Nachrücker-Fall, wenn sich jemand später anmeldet.</p>

  <?php if ($mo['summe'] > 0): ?>
    <div class="pat-chips" style="margin-top:.9rem">
      <span class="pat-chip ok"><i class="ti ti-target-arrow"></i> <?= $mo['exakt'] ?>x genau so gewählt</span>
      <?php if ($mo['info'] > 0): ?>
        <?php // Sammelt drei Fälle: anderes Erstfach der Pat:in, ihr weiteres Fach, oder derselbe
              // Studiengang in anderer Schreibweise. Gemeinsam ist ihnen nur, dass es NICHT
              // derselbe Eintrag ist – deshalb die neutrale Beschriftung. ?>
        <a class="pat-chip" href="#gruppen" title="Fachlich passend, aber nicht derselbe Eintrag: anderes Erstfach der Pat:in, eines ihrer weiteren Fächer oder eine frei eingetragene Schreibweise"><i class="ti ti-arrows-shuffle"></i> <?= $mo['info'] ?>x nicht derselbe Eintrag</a>
      <?php endif; ?>
      <?php if ($mo['warn'] > 0): ?>
        <a class="pat-chip warn" href="#gruppen" title="Gleiches Fach, aber Bachelor/Master unterschiedlich"><i class="ti ti-school"></i> <?= $mo['warn'] ?>x anderer Abschluss</a>
      <?php endif; ?>
      <?php if ($mo['bad'] > 0): ?>
        <a class="pat-chip warn" href="#gruppen" title="Keine fachliche Gemeinsamkeit – kann nur von Hand entstanden sein"><i class="ti ti-alert-triangle"></i> <?= $mo['bad'] ?>x fachfremd</a>
      <?php endif; ?>
    </div>
    <p class="small muted" style="margin:.5rem 0 0">Wo die Automatik einen <strong>Kompromiss</strong>
      machen musste, steht es an der Person auch in den <a href="#gruppen">Gruppen</a> – dort lässt
      es sich über die Filter-Knöpfe gezielt durchgehen und von Hand nachbessern.</p>
  <?php endif; ?>
</div>

<?php if ($g['free']): ?>
  <div class="section-title" id="frei"><i class="ti ti-user-question"></i> Ohne Gruppe
    <span class="count"><?= count($g['free']) ?></span></div>
  <div class="card">
    <p class="small muted" style="margin:0 0 .6rem">Diese Erstsemester teilt die Automatik
      <strong>bewusst nicht</strong> zu: zu ihrem Fach gibt es keine passende Pat:in, und eine
      fachfremde Gruppe wäre schlechter als eine sichtbare Lücke. Bitte von Hand entscheiden.</p>
    <?php foreach ($g['free'] as $e): ?>
      <div class="pat-row">
        <span><?= h($e['first_name'] . ' ' . $e['last_name']) ?>
          <span class="small muted">· <?= h(pat_degrees()[(string)$e['degree']] ?? '') ?> · <?= h((string)$e['course_label']) ?></span>
          <?php if ($e['course_id'] === null): ?><span class="badge badge-draft">frei eingetragen</span><?php endif; ?>
        </span>
        <?= pat_move_btn($e) ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php endif; ?>

<?php if ($ppTab === 'mails'): ?>
<?php
$mq = pat_mail_stats($rid);
// Absagen (Abschnitt weiter unten) teilen sich diese Warteschlange – beide Blöcke rechnen
// deshalb mit denselben Zahlen.
$rej  = pat_rejection_lists($rid);
$rejE = count($rej['erstis']);
$rejP = count($rej['pates']);
$rejQ = (int)pat_db()->query("SELECT COUNT(*) FROM pat_mailqueue WHERE round_id = " . (int)$rid
      . " AND status IN ('queued','sending') AND kind LIKE 'reject_%'")->fetchColumn();
?>
<div class="section-title" id="mails"><i class="ti ti-mail-forward"></i> Mails zur Einteilung</div>
<div class="card">
  <p class="small muted" style="margin:0 0 .8rem">Jede:r bekommt eine <strong>persönliche</strong>
    Mail: Erstsemester ihre Pat:in samt Kontakt und Vorstellung <em>und</em> die anderen Erstis
    ihrer Gruppe, Pat:innen die Liste ihrer Erstsemester. Bei „Alle vorbereiten" kommt je Gruppe
    zusätzlich <strong>eine gemeinsame Kennenlern-Mail</strong> dazu – Pat:in im An-Feld, alle
    Erstis im CC, damit ein „Allen antworten" die ganze Gruppe erreicht. Wer keine Gruppe hat,
    bekommt nichts. Die Texte pflegst du in der Übersicht
    unter <a href="paten.php?t=texte">Texte → „Mails zur Einteilung"</a>.</p>
  <?php if ($rejQ > 0): ?>
    <p class="small muted" style="margin:-.4rem 0 .8rem"><i class="ti ti-mail-x"></i> Darunter sind
      <strong><?= $rejQ ?> vorbereitete <a href="#absagen">Absage<?= $rejQ === 1 ? '' : 'n' ?></a></strong> –
      sie gehen mit demselben Klick raus.</p>
  <?php endif; ?>

  <?php $mqTotal = (int)$mq['queued'] + (int)$mq['sent'] + (int)$mq['failed'];
        $mqDone  = (int)$mq['sent'] + (int)$mq['failed']; ?>
  <?php if ($mqTotal > 0): ?>
    <p style="margin:0 0 .4rem">
      <strong><?= $mqDone ?> von <?= $mqTotal ?></strong> verschickt<?php if ($mq['queued'] > 0): ?>
      · <?= (int)$mq['queued'] ?> wartend<?php endif; ?><?php if ($mq['failed'] > 0): ?> ·
      <strong style="color:var(--red)"><?= (int)$mq['failed'] ?> fehlgeschlagen</strong><?php endif; ?>
    </p>
    <div class="pat-bar"><span style="width:<?= $mqTotal ? round(100 * $mqDone / $mqTotal) : 0 ?>%"></span></div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 .8rem">Noch nichts vorbereitet.</p>
  <?php endif; ?>

  <div class="btn-row" style="flex-wrap:wrap;gap:.4rem">
    <form method="post" data-confirm="Mails für ALLE eingeteilten Personen vorbereiten? Noch wartende Mails dieses Programms werden dabei ersetzt – verschickt wird noch nichts." data-confirm-ok="Vorbereiten">
      <?= csrf_field() ?><input type="hidden" name="action" value="mail_queue"><input type="hidden" name="role" value="both">
      <button class="btn secondary" type="submit"><i class="ti ti-stack-push"></i> Alle vorbereiten</button>
    </form>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="mail_queue"><input type="hidden" name="role" value="ersti">
      <button class="btn secondary small" type="submit">nur Erstsemester</button>
    </form>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="mail_queue"><input type="hidden" name="role" value="pate">
      <button class="btn secondary small" type="submit">nur Pat:innen</button>
    </form>
  </div>

  <?php if ($mq['queued'] > 0): ?>
    <div class="btn-row" style="margin-top:.9rem;flex-wrap:wrap;gap:.4rem">
      <form method="post" data-confirm="Jetzt alle <?= (int)$mq['queued'] ?> wartenden Mails verschicken? Sie gehen an echte Studierende und lassen sich nicht zurückholen." data-confirm-ok="Alle verschicken">
        <?= csrf_field() ?><input type="hidden" name="action" value="mail_send">
        <button class="btn" type="submit"><i class="ti ti-send"></i> Alle <?= (int)$mq['queued'] ?> verschicken</button>
      </form>
      <form method="post" data-confirm="Alle wartenden Mails verwerfen? Schon verschickte bleiben unberührt." data-confirm-danger data-confirm-ok="Verwerfen">
        <?= csrf_field() ?><input type="hidden" name="action" value="mail_clear"><input type="hidden" name="what" value="queued">
        <button class="btn danger secondary small" type="submit"><i class="ti ti-trash"></i> Warteschlange verwerfen</button>
      </form>
    </div>
    <?php $dayCap = max(0, min(3000, (int)setting_get('pat_mail_day_cap', '0')));
          $used   = pat_mail_sent_last24();
          $tage   = pat_mail_days_needed((int)$mq['queued'], $dayCap); ?>
    <p class="small muted" style="margin:.6rem 0 0"><strong>Ein Klick genügt.</strong> Alle Bereiche
      teilen sich <strong>ein</strong> Mail-Konto: Was Login-Links, Umfragen und externe Events
      schon verbraucht haben, ist hier bereits abgezogen. Gerade frei: <strong><?= (int)pat_mail_budget_left($dayCap) ?></strong>
      Mails (aus dem Pat:innenprogramm gingen in den letzten 24 h <?= $used ?> raus<?= $dayCap > 0 ? ', eigener Deckel ' . (int)$dayCap : '' ?>).
      Passt die Warteschlange
      nicht hinein, geht der Rest <strong>automatisch weiter</strong> – ein stündlicher Cron-Lauf
      arbeitet sie ab<?php if ($tage > 0): ?>, voraussichtlich fertig
      <strong><?= $tage === 1 ? 'morgen' : 'in ' . $tage . ' Tagen' ?></strong><?php endif; ?>.
      Du musst dafür nichts weiter tun. Abgelehnte Mails landen als „fehlgeschlagen" und sind
      erneut versuchbar.</p>
  <?php endif; ?>

  <?php if ($mq['failed'] > 0): ?>
    <form method="post" style="margin-top:.7rem">
      <?= csrf_field() ?><input type="hidden" name="action" value="mail_clear"><input type="hidden" name="what" value="failed">
      <button class="btn secondary small" type="submit"><i class="ti ti-refresh"></i> Fehlgeschlagene erneut versuchen</button>
    </form>
  <?php endif; ?>
</div>

<?php
// --- Absagen ($rej/$rejE/$rejP/$rejQ oben bei den Mails berechnet) ----------
// Wer leer ausgeht, soll das erfahren statt ewig zu warten. Empfängerliste und Versand
// stammen aus derselben Funktion (pat_rejection_lists), damit der Bestätigungsdialog
// wirklich zeigt, was gleich rausgeht.
?>
<div class="section-title" id="absagen"><i class="ti ti-mail-x"></i> Absagen
  <?php if ($rejE + $rejP > 0): ?><span class="count"><?= $rejE + $rejP ?></span><?php endif; ?></div>
<div class="card">
  <p class="small muted" style="margin:0 0 .8rem">Nicht jede:r bekommt eine Gruppe – und niemand
    sollte deswegen wochenlang auf eine Mail warten, die nie kommt. Betroffen sind
    <strong>Pat:innen ohne Erstsemester</strong> und <strong>Erstsemester ohne Pat:in</strong>.
    Die Texte pflegst du unter <a href="paten.php?t=texte">Texte → „Absagen"</a>; verschickt wird
    über dieselbe Warteschlange wie die Einteilungs-Mails.</p>

  <?php if ($rejE + $rejP === 0): ?>
    <p class="muted" style="margin:0"><i class="ti ti-circle-check"></i> Niemand geht leer aus –
      es gibt nichts abzusagen.</p>
  <?php else: ?>
    <?php if ($status === 'open'): ?>
      <p class="small" style="margin:0 0 .8rem;padding:.5rem .7rem;border-left:3px solid var(--red);background:var(--petrol-soft)">
        <strong>Die Anmeldung läuft noch.</strong> Solange können neue Pat:innen dazukommen, die
        genau zu diesen Erstsemestern passen. Absagen erst nach dem Schließen verschicken.</p>
    <?php endif; ?>
    <p style="margin:0 0 .8rem">
      <?php if ($rejP > 0): ?><strong><?= $rejP ?></strong> Pat:in<?= $rejP === 1 ? '' : 'nen' ?> ohne Gruppe<?php endif; ?>
      <?php if ($rejP > 0 && $rejE > 0): ?> · <?php endif; ?>
      <?php if ($rejE > 0): ?><strong><?= $rejE ?></strong> Erstsemester ohne Pat:in<?php endif; ?>
      <?php if ($rejQ > 0): ?> · <span class="badge badge-event"><?= $rejQ ?> in der Warteschlange</span><?php endif; ?>
    </p>
    <div class="btn-row" style="flex-wrap:wrap;gap:.4rem">
      <button type="button" class="btn secondary js-rej" data-role="both"><i class="ti ti-mail-x"></i> Absagen vorbereiten</button>
      <?php if ($rejE > 0): ?>
        <button type="button" class="btn secondary small js-rej" data-role="ersti">nur Erstsemester</button>
      <?php endif; ?>
      <?php if ($rejP > 0): ?>
        <button type="button" class="btn secondary small js-rej" data-role="pate">nur Pat:innen</button>
      <?php endif; ?>
    </div>
    <p class="small muted" style="margin:.6rem 0 0">„Vorbereiten" stellt die Absagen nur in die
      Warteschlange – abgeschickt werden sie oben bei den <a href="#mails">Mails</a>, zusammen mit
      allem anderen, was dort wartet.</p>
  <?php endif; ?>
</div>

<?php if ($rejE + $rejP > 0): ?>
<dialog id="rejDlg" class="pat-move-dlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mail_reject">
    <input type="hidden" name="role" id="rejRole" value="both">
    <h3 style="margin:0 0 .2rem"><i class="ti ti-mail-x" style="color:var(--petrol)"></i>
      Absage verschicken an <span id="rejCount"></span></h3>
    <p class="small muted" style="margin:0 0 .7rem">Bitte einmal durchsehen – diese Personen
      bekommen die Absage. Verschickt wird noch nichts: Sie wandern in die Warteschlange.</p>
    <div class="pat-move-list">
      <?php if ($rejP > 0): ?>
        <p class="small muted rej-grp" data-role="pate" style="margin:.2rem 0"><strong>Pat:innen ohne Gruppe</strong></p>
        <?php foreach ($rej['pates'] as $p): ?>
          <div class="pat-move-opt rej-grp" data-role="pate">
            <span><?= h($p['first_name'] . ' ' . $p['last_name']) ?></span>
            <span class="small muted"><?= h((string)$p['course_label']) ?> · <?= h((string)$p['email']) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($rejE > 0): ?>
        <p class="small muted rej-grp" data-role="ersti" style="margin:.5rem 0 .2rem"><strong>Erstsemester ohne Pat:in</strong></p>
        <?php foreach ($rej['erstis'] as $e): ?>
          <div class="pat-move-opt rej-grp" data-role="ersti">
            <span><?= h($e['first_name'] . ' ' . $e['last_name']) ?></span>
            <span class="small muted"><?= h((string)$e['course_label']) ?> · <?= h((string)$e['email']) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="btn-row" style="margin-top:.9rem;justify-content:flex-end">
      <button type="button" class="btn secondary" id="rejCancel">Abbrechen</button>
      <button class="btn" type="submit"><i class="ti ti-stack-push"></i> In die Warteschlange</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php endif; ?>

<?php if ($ppTab === 'programm'): ?>
<?php $days = pat_delete_days_left($round); ?>
<div class="section-title" id="loeschfrist"><i class="ti ti-clock-x"></i> Löschfrist</div>
<div class="card"<?= $days !== null && $days <= 14 ? ' style="border-left:3px solid var(--red)"' : '' ?>>
  <?php if ($days === null): ?>
    <p style="margin:0 0 .5rem"><i class="ti ti-alert-triangle" style="color:var(--amber)"></i>
      <strong>Keine Löschfrist gesetzt</strong> – dieses Programm bleibt mit allen Personendaten
      dauerhaft gespeichert. Beim Öffnen der Anmeldung wird die Frist normalerweise automatisch
      auf <?= (int)PAT_DELETE_MONTHS ?> Monate gesetzt.</p>
  <?php elseif ($days < 0): ?>
    <p style="margin:0 0 .5rem"><i class="ti ti-trash" style="color:var(--red)"></i>
      <strong>Überfällig seit <?= h(date('d.m.Y', strtotime((string)$round['delete_after']))) ?></strong>
      – der nächste Cron-Lauf räumt dieses Programm ab.<?= $status === 'open' ? ' (Noch nicht: solange
      die Anmeldung offen ist, wird nichts gelöscht.)' : '' ?></p>
  <?php else: ?>
    <p style="margin:0 0 .5rem"<?= $days <= 14 ? ' class="small"' : '' ?>>
      <i class="ti ti-clock" style="color:var(--<?= $days <= 14 ? 'red' : 'petrol' ?>)"></i>
      Wird am <strong><?= h(date('d.m.Y', strtotime((string)$round['delete_after']))) ?></strong>
      gelöscht – <strong>noch <?= (int)$days ?> Tage</strong>.</p>
  <?php endif; ?>
  <p class="small muted" style="margin:0 0 .8rem">Gelöscht wird dann das <strong>ganze Programm</strong>:
    alle Anmeldungen mit Namen, Mailadressen und Vorstellungstexten, die Einteilung und die
    Mail-Warteschlange. Das lässt sich nicht rückgängig machen – wenn ihr Zahlen behalten wollt,
    ladet vorher den <a href="<?= h($self) ?>&t=anmeldungen">CSV-Export</a> herunter. Solange die Anmeldung
    <strong>offen</strong> ist, wird nichts gelöscht.</p>
  <form method="post" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_after">
    <div><label for="delete_after">Löschtag</label>
      <input type="text" class="fp-date" name="delete_after" id="delete_after"
             placeholder="Datum wählen" value="<?= h((string)($round['delete_after'] ?? '')) ?>"></div>
    <button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
  </form>
  <p class="small muted" style="margin:.5rem 0 0">Leeres Feld = keine automatische Löschung
    (nur in begründeten Ausnahmen).</p>
</div>

<?php endif; ?>

<?php if ($ppTab === 'einteilung'): ?>
<?php
  // Kompromisse ($mo, oben berechnet): wo sitzt jemand NICHT in seiner exakten Auswahl?
  $probLeer  = 0;
  foreach ($g['groups'] as $grp) if (!$grp['erstis']) $probLeer++;
  $probFremd = $mo['bad'];
  $probKomp  = $mo['info'] + $mo['warn'];   // bewusst ohne 'bad' – dafür gibt es den eigenen Chip
?>
<div class="section-title" id="gruppen"><i class="ti ti-users-group"></i> Gruppen
  <span class="count"><?= count($g['groups']) ?></span></div>

<?php if (!$g['groups']): ?>
  <div class="card"><p class="muted" style="margin:0">Noch keine Pat:innen angemeldet - sobald welche
    da sind, erscheinen hier die Gruppen.</p></div>
<?php else: ?>

<div class="card pat-tools">
  <div class="pat-tools-row">
    <input type="search" id="grpSearch" class="pat-search" placeholder="Name, Fach oder Mailadresse suchen ..."
           aria-label="Gruppen und Personen durchsuchen" autocomplete="off">
    <span class="btn-row" style="gap:.4rem">
      <button type="button" class="btn secondary small" id="grpOpenAll"><i class="ti ti-chevrons-down"></i> Alle aufklappen</button>
      <button type="button" class="btn secondary small" id="grpCloseAll"><i class="ti ti-chevrons-up"></i> Alle zuklappen</button>
    </span>
  </div>
  <div class="pat-chips">
    <?php if ($stats['free'] > 0): ?>
      <a class="pat-chip warn" href="#frei"><i class="ti ti-user-question"></i> <?= (int)$stats['free'] ?> ohne Gruppe</a>
    <?php endif; ?>
    <?php if ($probLeer > 0): ?>
      <button type="button" class="pat-chip" data-filter="leer"><i class="ti ti-user-off"></i> <?= $probLeer ?> leere Gruppe<?= $probLeer === 1 ? '' : 'n' ?></button>
    <?php endif; ?>
    <?php if ($probFremd > 0): ?>
      <button type="button" class="pat-chip warn" data-filter="fremd"><i class="ti ti-alert-triangle"></i> <?= $probFremd ?>x fachfremd</button>
    <?php endif; ?>
    <?php if ($probKomp > 0): ?>
      <button type="button" class="pat-chip" data-filter="komp"
              title="Zugeteilt, aber nicht in der exakt gewählten Kombination – etwa anderes Erstfach oder anderer Abschluss"><i class="ti ti-arrows-shuffle"></i> <?= $probKomp ?>x Kompromiss</button>
    <?php endif; ?>
    <?php if ($stats['free'] === 0 && $probLeer === 0 && $probFremd === 0 && $probKomp === 0): ?>
      <span class="pat-chip ok"><i class="ti ti-circle-check"></i> Keine offenen Punkte</span>
    <?php endif; ?>
    <span class="small muted" id="grpCount"></span>
  </div>
</div>

<div id="grpList">
<?php foreach ($g['groups'] as $pid => $grp): $p = $grp['pate'];
    $cnt = count($grp['erstis']);
    $fremd = 0; $komp = 0;
    foreach ($grp['erstis'] as $e) {
        $lvl = pat_match_note(pat_match_score($e, $p))['level'];
        if ($lvl === 'bad') $fremd++;
        elseif ($lvl !== '') $komp++;
    }
    $needle = pat_needle($p);
    foreach ($grp['erstis'] as $e) $needle .= ' ' . pat_needle($e);
?>
  <details class="card pat-grp"<?= ($cnt === 0 || $fremd > 0) ? ' open' : '' ?>
           data-needle="<?= h($needle) ?>"
           data-leer="<?= $cnt === 0 ? '1' : '0' ?>" data-fremd="<?= $fremd > 0 ? '1' : '0' ?>"
           data-komp="<?= $komp > 0 ? '1' : '0' ?>">
    <summary class="pat-grp-head">
      <i class="ti ti-chevron-right pat-grp-caret" aria-hidden="true"></i>
      <span class="pat-grp-name"><i class="ti ti-user-star"></i> <?= h($p['first_name'] . ' ' . $p['last_name']) ?></span>
      <span class="pat-grp-meta small muted"><?= h((string)$p['course_label']) ?></span>
      <span class="badge <?= $cnt ? 'badge-event' : 'badge-draft' ?>"><?= $cnt ?></span>
      <?php if ($fremd > 0): ?><span class="badge badge-draft"><?= $fremd ?>x fachfremd</span><?php endif; ?>
      <?php if ($komp > 0): ?><span class="badge badge-draft" title="Nicht die exakt gewählte Kombination"><?= $komp ?>x Kompromiss</span><?php endif; ?>
    </summary>
    <div class="pat-grp-body">
      <p class="small muted" style="margin:0 0 .6rem">
        <?= h(pat_degrees()[(string)$p['degree']] ?? '') ?> ·
        <a href="mailto:<?= h((string)$p['email']) ?>"><?= h((string)$p['email']) ?></a>
        <?php if ($p['course_id'] === null): ?> · <span class="badge badge-draft">frei eingetragen</span><?php endif; ?>
      </p>
      <?php // Weitere Fächer: Erstsemester mit genau diesem Fach werden hier bevorzugt einsortiert.
            $pWeitere = pat_signup_extra_labels($p); if ($pWeitere): ?>
        <p class="small muted" style="margin:0 0 .6rem"><i class="ti ti-plus"></i>
          Studiert außerdem: <strong><?= h(implode(', ', $pWeitere)) ?></strong></p>
      <?php endif; ?>
      <?php if (trim((string)$p['about']) !== ''): ?>
        <details class="small" style="margin:0 0 .6rem"><summary style="cursor:pointer">Über mich</summary>
          <p style="margin:.3rem 0 0;white-space:pre-line"><?= h((string)$p['about']) ?></p></details>
      <?php endif; ?>
      <?php if (!$grp['erstis']): ?>
        <p class="small muted" style="margin:0">Noch niemand zugeteilt.</p>
      <?php else: ?>
        <?php foreach ($grp['erstis'] as $e): ?>
          <?php $note = pat_match_note(pat_match_score($e, $p)); ?>
          <div class="pat-row" data-needle="<?= h(pat_needle($e)) ?>"
               data-komp="<?= $note['level'] === 'info' || $note['level'] === 'warn' ? '1' : '0' ?>"
               data-fremd="<?= $note['level'] === 'bad' ? '1' : '0' ?>">
            <span><?= h($e['first_name'] . ' ' . $e['last_name']) ?>
              <span class="small muted">· <?= h((string)$e['course_label']) ?></span>
              <?php if ($note['text'] !== ''): ?>
                <span class="badge badge-draft" title="<?= h($note['title']) ?>"><?= h($note['text']) ?></span>
              <?php endif; ?>
            </span>
            <?= pat_move_btn($e) ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </details>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($ppTab === 'anmeldungen'): ?>
<div class="section-title" id="anmeldungen"><i class="ti ti-table"></i> Alle Anmeldungen
  <span class="count"><?= (int)$stats['erstis'] + (int)$stats['pates'] ?></span></div>
<div class="card">
  <div class="btn-row" style="margin-bottom:.8rem">
    <a class="btn secondary small" href="<?= h($self . '&csv=1') ?>"><i class="ti ti-file-spreadsheet"></i> Als CSV herunterladen</a>
  </div>
  <?php $all = pat_signups_of($rid); ?>
  <?php if (!$all): ?>
    <p class="muted" style="margin:0">Noch keine Anmeldungen.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="list">
        <thead><tr>
          <th>Rolle</th><th>Name</th><th>E-Mail</th><th>Studium</th><th>Gruppe</th><th>Seit</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($all as $s): $isP = (string)$s['role'] === 'pate'; ?>
          <tr>
            <td><span class="badge <?= $isP ? 'badge-meeting' : 'badge-event' ?>"><?= $isP ? 'Pat:in' : 'Ersti' ?></span></td>
            <td><?= h($s['first_name'] . ' ' . $s['last_name']) ?>
              <?php if (pat_is_test_signup($s)): ?><span class="badge badge-draft" title="Testdatensatz – über Diagnose &amp; Fehler-Log wieder entfernbar">Test</span><?php endif; ?>
              <?php if (trim((string)$s['about']) !== ''): ?>
                <details class="small"><summary style="cursor:pointer;color:var(--petrol)">Über mich</summary>
                  <p style="margin:.2rem 0 0;white-space:pre-line;max-width:40ch"><?= h((string)$s['about']) ?></p></details>
              <?php endif; ?>
            </td>
            <td class="small"><a href="mailto:<?= h((string)$s['email']) ?>"><?= h((string)$s['email']) ?></a></td>
            <td class="small"><?= h(pat_degrees()[(string)$s['degree']] ?? '') ?><br><?= h((string)$s['course_label']) ?>
              <?php if ($s['course_id'] === null): ?><br><span class="badge badge-draft">frei eingetragen</span><?php endif; ?>
              <?php $sw = pat_signup_extra_labels($s); if ($sw): ?>
                <br><span class="pat-wf" title="Weitere Fächer, die diese Pat:in studiert">+ <?= h(implode(', ', $sw)) ?></span>
              <?php endif; ?></td>
            <td class="small"><?= $isP ? '—' : h((string)(($g['groups'][(int)($s['pate_id'] ?? 0)]['pate']['first_name'] ?? '') !== ''
                  ? $g['groups'][(int)$s['pate_id']]['pate']['first_name'] . ' ' . $g['groups'][(int)$s['pate_id']]['pate']['last_name']
                  : '—')) ?></td>
            <td class="small muted"><?= h(date('d.m.y', strtotime((string)$s['created_at']))) ?></td>
            <td>
              <form method="post" data-confirm="Anmeldung von <?= h($s['first_name'] . ' ' . $s['last_name']) ?> löschen?<?= $isP ? ' Die zugeteilten Erstsemester werden dabei wieder frei.' : '' ?>" data-confirm-danger data-confirm-ok="Löschen" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="signup_delete">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn danger small" type="submit" title="Löschen"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <p class="small muted" style="margin:.8rem 0 0"><i class="ti ti-shield-lock"></i> Diese Daten
    stammen von Studierenden, die keine Mitglieder sind. Bitte nur für das Pat:innenprogramm
    verwenden und den CSV-Export nicht weitergeben.</p>
</div>

<?php if ($g['groups']): ?>
<!-- EIN Dialog für alle Verschiebe-Aktionen: die Gruppenliste steht genau einmal im HTML. -->
<dialog id="moveDlg" class="pat-move-dlg">
  <form method="post" id="moveForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="assign_one">
    <input type="hidden" name="ersti_id" id="moveErsti" value="">
    <h3 style="margin:0 0 .2rem"><i class="ti ti-arrows-exchange" style="color:var(--petrol)"></i>
      <span id="moveTitle">Verschieben</span></h3>
    <p class="small muted" style="margin:0 0 .7rem">Fachlich passende Gruppen stehen oben und sind
      grün markiert.</p>
    <input type="search" id="moveSearch" class="pat-search" placeholder="Gruppe suchen ..."
           aria-label="Gruppe suchen" autocomplete="off">
    <div class="pat-move-list" id="moveList">
      <button type="submit" name="pate_id" value="0" class="pat-move-opt" data-top="-1">
        <span><i class="ti ti-user-off"></i> ohne Gruppe</span></button>
      <?php foreach ($g['groups'] as $pid => $grp): $p = $grp['pate']; ?>
        <button type="submit" name="pate_id" value="<?= (int)$pid ?>" class="pat-move-opt"
                data-top="<?= (int)pat_signup_top_course($p) ?>" data-deg="<?= h((string)$p['degree']) ?>"
                data-fach="<?= h(pat_faecher_attr($p)) ?>"
                data-needle="<?= h(pat_needle($p)) ?>">
          <span><?= h($p['first_name'] . ' ' . $p['last_name']) ?></span>
          <span class="small muted"><?= h((string)$p['course_label']) ?><?php
            $pw = pat_signup_extra_labels($p); if ($pw): ?> <span class="pat-wf">+ <?= h(implode(', ', $pw)) ?></span><?php endif; ?>
            · <?= count($grp['erstis']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="btn-row" style="margin-top:.8rem;justify-content:flex-end">
      <button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Abbrechen</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  // ---- Filter über Gruppen und Personen -------------------------------------------------
  var list = document.getElementById('grpList');
  var search = document.getElementById('grpSearch');
  var counter = document.getElementById('grpCount');
  var chips = Array.prototype.slice.call(document.querySelectorAll('.pat-chip[data-filter]'));
  var mode = '';                                    // '', 'leer', 'fremd' oder 'komp'
  if (!list) return;
  var groups = Array.prototype.slice.call(list.querySelectorAll('.pat-grp'));

  function apply() {
    var q = (search && search.value || '').trim().toLowerCase();
    var shown = 0;
    groups.forEach(function (gEl) {
      var okMode = !mode || gEl.getAttribute('data-' + mode) === '1';
      var okText = !q || (gEl.getAttribute('data-needle') || '').indexOf(q) >= 0;
      var vis = okMode && okText;
      gEl.hidden = !vis;
      if (vis) {
        shown++;
        // Bei aktiver Suche ODER einem Personen-Filter die Gruppe aufklappen und die
        // gemeinten Zeilen hervorheben – sonst müsste man sie in jeder Gruppe selbst suchen.
        var rowFilter = (mode === 'komp' || mode === 'fremd') ? mode : '';
        if (q || rowFilter) {
          gEl.open = true;
          Array.prototype.forEach.call(gEl.querySelectorAll('.pat-row'), function (r) {
            var hit = (!q || (r.getAttribute('data-needle') || '').indexOf(q) >= 0)
                   && (!rowFilter || r.getAttribute('data-' + rowFilter) === '1');
            r.classList.toggle('is-hit', hit);
          });
        } else {
          Array.prototype.forEach.call(gEl.querySelectorAll('.pat-row.is-hit'), function (r) {
            r.classList.remove('is-hit');
          });
        }
      }
    });
    if (counter) counter.textContent = (q || mode) ? shown + ' von ' + groups.length + ' Gruppen' : '';
  }
  if (search) search.addEventListener('input', apply);
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      mode = (mode === c.getAttribute('data-filter')) ? '' : c.getAttribute('data-filter');
      chips.forEach(function (x) { x.classList.toggle('active', x.getAttribute('data-filter') === mode); });
      apply();
    });
  });
  var oa = document.getElementById('grpOpenAll'), ca = document.getElementById('grpCloseAll');
  if (oa) oa.addEventListener('click', function () { groups.forEach(function (g) { g.open = true; }); });
  if (ca) ca.addEventListener('click', function () { groups.forEach(function (g) { g.open = false; }); });

  // ---- Verschieben ----------------------------------------------------------------------
  var dlg = document.getElementById('moveDlg');
  var mList = document.getElementById('moveList');
  var mSearch = document.getElementById('moveSearch');
  if (!dlg || !mList) return;
  var opts = Array.prototype.slice.call(mList.querySelectorAll('.pat-move-opt'));

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.pat-move-btn') : null;
    if (!b) return;
    document.getElementById('moveErsti').value = b.getAttribute('data-id');
    document.getElementById('moveTitle').textContent = b.getAttribute('data-name') + ' verschieben';
    var top = b.getAttribute('data-top'), cur = b.getAttribute('data-cur');
    var fach = b.getAttribute('data-fach') || '0';
    // Passende zuerst: wer dieses Fach studiert (Erstfach oder weiteres) ganz nach oben, dann
    // der gleiche Studiengang, aktuelle Gruppe ausgrauen.
    opts.forEach(function (o) {
      var kann = (o.getAttribute('data-fach') || '').split(',');
      var exakt = fach !== '0' && kann.indexOf(fach) !== -1;
      var fit = exakt || (top !== '0' && o.getAttribute('data-top') === top);
      o.classList.toggle('fits', fit);
      o.classList.toggle('current', o.value === cur);
      o.hidden = false;
      o.style.order = exakt ? '-1' : (fit ? '0' : '1');
    });
    if (mSearch) mSearch.value = '';
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', 'open');
    if (mSearch) mSearch.focus();
  });
  if (mSearch) mSearch.addEventListener('input', function () {
    var q = mSearch.value.trim().toLowerCase();
    opts.forEach(function (o) {
      o.hidden = q !== '' && (o.getAttribute('data-needle') || '').indexOf(q) < 0
                 && o.getAttribute('data-top') !== '-1';
    });
  });
  dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
})();
</script>
<?php endif; ?>

<?php // Der Reiter muss mit in die Bedingung: $rejE/$rejP entstehen oben im Mails-Block, und
      // dort steht auch der Dialog. Ohne die Abfrage warnte jeder andere Reiter über
      // undefinierte Variablen ins Fehler-Log. ?>
<?php if ($ppTab === 'mails' && $rejE + $rejP > 0): ?>
<script>
// Absage-Dialog. BEWUSST außerhalb des Gruppen-Skripts: das gibt es nur, wenn Gruppen
// existieren – Absagen kann es aber auch ohne geben (Erstsemester ohne eine einzige Pat:in).
(function () {
  var dlg = document.getElementById('rejDlg');
  if (!dlg) return;
  var role = document.getElementById('rejRole');
  var cnt = document.getElementById('rejCount');
  var rows = Array.prototype.slice.call(dlg.querySelectorAll('.rej-grp'));

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.js-rej') : null;
    if (!b) return;
    var r = b.getAttribute('data-role');
    role.value = r;
    // Nur die Empfänger zeigen, die dieser Knopf wirklich anschreibt
    var n = 0;
    rows.forEach(function (el) {
      var show = (r === 'both') || el.getAttribute('data-role') === r;
      el.hidden = !show;
      if (show && el.classList.contains('pat-move-opt')) n++;
    });
    cnt.textContent = n + (n === 1 ? ' Person' : ' Personen');
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', 'open');
  });
  var cancel = document.getElementById('rejCancel');
  if (cancel) cancel.addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php
page_footer();