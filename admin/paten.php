<?php
/**
 * Verwaltung → Pat:innenprogramm.
 *
 * Hier startet der AStA je Semester ein neues Programm. Jedes Semester ist ein EIGENER
 * Datensatz mit EIGENEM öffentlichen Link – Anmeldungen verschiedener Semester vermischen
 * sich nie. Der Link ist bewusst EINER für alle: Erstsemester und Pat:innen landen auf
 * derselben Seite und werden dort durch die Auswahl geführt.
 *
 * Diese Seite ist die einzige Stelle, die BEIDE Datenbanken kennt (App + Pat:innenprogramm).
 * Der öffentliche Teil unter pat/ kennt nur pat.sqlite.
 */

$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
// Zugriff: Admin & Vorsitz – plus die unten festgelegten Verantwortlichen. Für Letztere ist
// das die EINZIGE Verwaltungsseite (eigener Titelleisten-Punkt, kein übriger Admin-Bereich).
require_login();
if (!can_admin() && !is_pat_manager()) {
    flash('Diese Seite ist nur für Admins und die Verantwortlichen des Pat:innenprogramms.', 'error');
    redirect(base() . 'dashboard.php');
}
$canTeam = can_admin(); // Verantwortliche festlegen dürfen nur Admin & Vorsitz
require_once __DIR__ . '/../pat-db.php';

// Basis-URL der App in die Pat-Datenbank spiegeln: die öffentliche Seite braucht sie für die
// Linkvorschau, darf dafür aber nicht in asta.sqlite schauen. Datenfluss also nur nach innen.
$appBase = base_url();
if ($appBase !== '' && pat_setting_get('base_url', '') !== $appBase) {
    pat_setting_set('base_url', $appBase);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'create_round') {
        $term = (string)($_POST['term'] ?? '');
        $year = (int)($_POST['year'] ?? 0);
        $res  = pat_round_create($term, $year);
        if ($res['ok']) {
            flash('Programm „' . pat_label($term, $year) . '" angelegt. Der Studi-Link steht bereit – '
                . 'die Anmeldung ist noch geschlossen, bis du sie öffnest.', 'success');
        } else {
            flash($res['error'], 'error');
        }
        redirect('paten.php?t=programme');
    }

    if ($action === 'set_status') {
        $status = (string)($_POST['status'] ?? '');
        $r = pat_round_get($id);
        if ($r && pat_round_set_status($id, $status)) {
            flash('„' . pat_round_label($r) . '": ' . match ($status) {
                'open'     => 'Anmeldung ist jetzt offen – der Link funktioniert für Studierende.',
                'closed'   => 'Anmeldung geschlossen. Der Link zeigt einen Hinweis, es kommt nichts mehr rein.',
                'draft'    => 'zurück auf Entwurf gestellt.',
                'archived' => 'archiviert.',
                default    => 'Status geändert.',
            }, 'success');
        } else {
            flash('Status konnte nicht geändert werden.', 'error');
        }
        redirect('paten.php?t=programme');
    }

    if ($action === 'regen_link') {
        $r = pat_round_get($id);
        if ($r && pat_round_regen_slug($id)) {
            flash('Neuer Studi-Link für „' . pat_round_label($r) . '" erzeugt. Der alte Link führt '
                . 'ab jetzt ins Leere – bitte den neuen verteilen.', 'success');
        } else {
            flash('Link konnte nicht erneuert werden.', 'error');
        }
        redirect('paten.php?t=programme');
    }

    if ($action === 'delete_round') {
        $r = pat_round_get($id);
        if ($r && pat_round_delete($id)) {
            flash('Programm „' . pat_round_label($r) . '" gelöscht.', 'success');
        } else {
            flash('Programm konnte nicht gelöscht werden.', 'error');
        }
        redirect('paten.php?t=programme');
    }

    if ($action === 'zust_save') {
        // Referate ODER Personen – umschaltbar wie bei was.läuft, externen
        // Events und Umfragen. Ersetzt die alten Einzelaktionen manager_add/manager_remove:
        // Die Auswahl wird als Ganzes gespeichert, dadurch kann sie auch leer sein.
        if (!$canTeam) {
            flash('Die Zuständigkeit dürfen nur Admin & Vorsitz festlegen.', 'error');
        } else {
            zust_form_speichern('pat', $_POST);
            flash('Zuständigkeit gespeichert: ' . zust_text('pat') . '.', 'success');
        }
        redirect('paten.php?t=einstellungen');
    }

    if ($action === 'course_add') {
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $res = pat_course_add((string)($_POST['degree'] ?? ''), (string)($_POST['name'] ?? ''), $parentId);
        flash($res['ok'] ? ($parentId > 0 ? 'Unterpunkt angelegt.' : 'Studiengang angelegt.') : $res['error'],
            $res['ok'] ? 'success' : 'error');
        redirect('paten.php?t=studiengaenge');
    }

    if ($action === 'course_sublabel') {
        $ok = pat_course_sublabel_set((int)($_POST['id'] ?? 0), (string)($_POST['sub_label'] ?? ''));
        flash($ok ? 'Bezeichnung der Unterauswahl gespeichert.' : 'Konnte die Bezeichnung nicht speichern.',
            $ok ? 'success' : 'error');
        redirect('paten.php?t=studiengaenge');
    }

    if ($action === 'course_delete') {
        $c = pat_course_get((int)($_POST['id'] ?? 0));
        if ($c && pat_course_delete((int)$c['id'])) {
            flash('„' . $c['name'] . '" (' . pat_degrees()[(string)$c['degree']] . ') gelöscht.', 'success');
        } else {
            flash('Studiengang nicht gefunden.', 'error');
        }
        redirect('paten.php?t=studiengaenge');
    }

    // Speichern und Zurücksetzen laufen über das Register – neue Textfelder brauchen hier
    // also nie eine Anpassung. Eine Liste an dieser Stelle veraltet zwangsläufig.
    if ($action === 'save_texts') {
        foreach (array_keys(pat_text_fields()) as $k) {
            if (!array_key_exists('t_' . $k, $_POST)) continue; // fehlt im Formular → nicht anfassen
            pat_setting_set('text_' . $k, trim((string)$_POST['t_' . $k]));
        }
        flash('Texte gespeichert.', 'success');
        redirect('paten.php?t=texte');
    }

    if ($action === 'reset_texts') {
        // Zeilen löschen (nicht auf "" setzen): ein gespeichertes Leerfeld bedeutet
        // „Element weglassen", eine fehlende Zeile dagegen „Standard verwenden".
        foreach (array_keys(pat_text_fields()) as $k) {
            pat_setting_delete('text_' . $k);
        }
        flash('Texte auf den Standard zurückgesetzt.', 'success');
        redirect('paten.php?t=texte');
    }

    if ($action === 'save_mailrate') {
        $cap = max(0, min(3000, (int)($_POST['pat_mail_day_cap'] ?? 0)));
        $run = max(1, min(3000, (int)($_POST['pat_mail_per_run'] ?? 250)));
        $sec = max(5, min(300, (int)($_POST['pat_mail_seconds'] ?? 120)));
        setting_set('pat_mail_day_cap', (string)$cap);
        setting_set('pat_mail_per_run', (string)$run);
        setting_set('pat_mail_seconds', (string)$sec);
        flash('Gespeichert: höchstens ' . $cap . ' Mails je 24 Stunden, ' . $run . ' je Cron-Lauf.', 'success');
        redirect('paten.php?t=einstellungen');
    }

    if ($action === 'msg_retry') {
        pat_message_retry($id);
        flash('Die Frage steht wieder in der Schlange – der nächste Cron-Lauf schickt sie.', 'success');
        redirect('paten.php?t=fragen');
    }

    if ($action === 'msg_delete') {
        pat_message_delete($id);
        flash('Frage gelöscht.', 'success');
        redirect('paten.php?t=fragen');
    }

    if ($action === 'save_legal') {
        $imp  = trim((string)($_POST['imprint_url'] ?? ''));
        $pri  = trim((string)($_POST['privacy_url'] ?? ''));
        $mail = trim((string)($_POST['paten_email'] ?? ''));
        foreach ([$imp, $pri] as $u) {
            if ($u !== '' && !preg_match('~^https?://~', $u)) {
                flash('Bitte vollständige Adressen mit https:// angeben.', 'error');
                redirect('paten.php?t=einstellungen');
            }
        }
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            flash('Die Paten-Mail ist keine gültige E-Mail-Adresse.', 'error');
            redirect('paten.php?t=einstellungen');
        }
        pat_setting_set('imprint_url', $imp);
        pat_setting_set('privacy_url', $pri);
        pat_setting_set('paten_email', $mail);
        flash('Kontakt & Links gespeichert.', 'success');
        redirect('paten.php?t=einstellungen');
    }

    redirect('paten.php');
}

$rounds  = pat_rounds_all();
$suggest = pat_suggest_next();
$imprint   = pat_setting_get('imprint_url', '');
$privacy   = pat_setting_get('privacy_url', '');
$patenMail = pat_setting_get('paten_email', '');
$openCnt = count(array_filter($rounds, fn($r) => $r['status'] === 'open'));

page_header('Pat:innenprogramm', true);
?>
<?php if ($canTeam): ?><p class="small"><a href="index.php">‹ Verwaltung</a></p><?php endif; ?>
<div class="events-toolbar">
  <h1><i class="ti ti-heart-handshake" style="color:var(--petrol)"></i> Pat:innenprogramm</h1>
</div>
<p class="small muted">Je Semester ein eigenes Programm mit eigenem Datensatz und eigenem Link.
  Studierende bekommen <strong>einen</strong> Link – ob Erstsemester oder Pat:in, entscheiden
  sie auf der Seite selbst.</p>

<?php if ($appBase === ''): ?>
  <div class="card" style="border-left:3px solid var(--red)">
    <p style="margin:0 0 .4rem"><strong><i class="ti ti-alert-triangle" style="color:var(--red)"></i>
      Basis-URL fehlt</strong></p>
    <p class="small muted" style="margin:0">Ohne hinterlegte Basis-URL der App lässt sich kein
      Studi-Link erzeugen und die Linkvorschau bleibt leer. Bitte einmal unter
      <?= app_place('verwaltung', 'Verwaltung → E-Mail & Einstellungen', 'einstellungen') ?> eintragen
      (z.&nbsp;B. <code><?= h(org_url() !== '' ? org_url() . '/planer' : 'https://beispiel.de/planer') ?></code>), dann hier weitermachen.</p>
  </div>
<?php endif; ?>

<?php if ($openCnt > 1): ?>
  <div class="card" style="border-left:3px solid var(--amber)">
    <p class="small" style="margin:0"><i class="ti ti-alert-triangle" style="color:var(--amber)"></i>
      Es sind <strong><?= (int)$openCnt ?> Programme gleichzeitig offen</strong>. Das geht technisch,
      ist aber selten gewollt – Studierende könnten sich im falschen Semester anmelden.</p>
  </div>
<?php endif; ?>

<?php
// Reiter der Seite (Muster wie die was.läuft-Verwaltung): kein Abschnitt wird verschoben,
// jeder rendert nur noch auf seinem Reiter. Anker-Fremdlinks (paten-programm.php → Texte)
// zeigen jetzt auf ?t=….
$pTabs = [
    'programme'     => ['label' => 'Programme',     'icon' => 'ti-folders'],
    'studiengaenge' => ['label' => 'Studiengänge',  'icon' => 'ti-books'],
    'texte'         => ['label' => 'Texte',         'icon' => 'ti-text-caption'],
    'fragen'        => ['label' => 'Fragen',        'icon' => 'ti-message-circle-question'],
    'einstellungen' => ['label' => 'Einstellungen', 'icon' => 'ti-settings'],
];
$pTab = (string)($_GET['t'] ?? '');
if (!isset($pTabs[$pTab])) $pTab = 'programme';
?>
<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($pTabs as $tk => $td): ?>
    <a<?= $tk === $pTab ? ' class="on" aria-current="page"' : '' ?> href="paten.php?t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($pTab === 'programme'): ?>
<div class="section-title" id="neu"><i class="ti ti-player-play"></i> Neues Programm starten</div>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_round">
    <div class="field-row">
      <div>
        <label for="term">Semester</label>
        <select name="term" id="term">
          <?php foreach (pat_terms() as $k => $lbl): ?>
            <option value="<?= h($k) ?>" <?= $suggest['term'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="year">Startjahr</label>
        <input type="number" name="year" id="year" min="2020" max="2100" step="1"
               value="<?= (int)$suggest['year'] ?>" required>
      </div>
    </div>
    <p class="small muted" style="margin:.5rem 0 0">Beim Wintersemester ist das Startjahr das erste
      der beiden Jahre: <strong><?= h(pat_label('wise', (int)$suggest['year'])) ?></strong> trägst du
      als <?= (int)$suggest['year'] ?> ein. Pro Semester ist genau ein Programm möglich.</p>
    <div class="btn-row" style="margin-top:.8rem">
      <button class="btn" type="submit"><i class="ti ti-plus"></i> Programm anlegen</button>
    </div>
  </form>
</div>

<div class="section-title" id="programme"><i class="ti ti-folders"></i> Programme
  <?php if ($rounds): ?><span class="count"><?= count($rounds) ?></span><?php endif; ?></div>

<?php if (!$rounds): ?>
  <div class="card">
    <p class="muted" style="margin:0">Noch kein Programm angelegt. Starte oben eines für das
      kommende Semester – danach bekommst du den Link für die Rundmail.</p>
  </div>
<?php endif; ?>

<?php foreach ($rounds as $r):
    $rid    = (int)$r['id'];
    $label  = pat_round_label($r);
    $status = (string)$r['status'];
    $url    = pat_public_url((string)$r['slug']);
    $badge  = match ($status) {
        'open'     => ['badge-meeting', 'Anmeldung offen'],
        'closed'   => ['badge-closed', 'Anmeldung geschlossen'],
        'archived' => ['badge-closed', 'archiviert'],
        default    => ['badge-draft', 'Entwurf'],
    };
    $rvars   = pat_round_vars($r) + ['{{LINK}}' => $url];
    $subject = pat_text_fill(pat_text('invite_subject'), $rvars);
    $body    = pat_text_fill(pat_text('invite_body'),    $rvars);
?>
  <div class="card" id="p<?= $rid ?>">
    <div class="events-toolbar" style="margin-bottom:.6rem">
      <h2 style="margin:0;font-size:1.15rem"><?= h($label) ?></h2>
      <span class="badge <?= h($badge[0]) ?>"><?= h($badge[1]) ?></span>
    </div>

    <?php $sc = pat_signup_counts($rid); $st = pat_round_stats($rid); ?>
    <p class="small" style="margin:0 0 .2rem"><strong><?= $sc['ersti'] + $sc['pate'] ?> Anmeldungen</strong>
      · <?= $sc['ersti'] ?> Erstsemester · <?= $sc['pate'] ?> Pat:innen<?php
        if ($st['free'] > 0): ?> · <span style="color:var(--amber);font-weight:700"><?= (int)$st['free'] ?> ohne Gruppe</span><?php endif; ?></p>
    <?php $sched = pat_schedule_items($r); if ($sched || pat_signup_overdue($r)): ?>
      <p class="small" style="margin:0 0 .4rem">
        <?php foreach ($sched as $i => $s): ?><?= $i ? ' · ' : '' ?><i class="ti <?= h($s['icon']) ?>"></i>
          <?= h($s['label']) ?> <strong><?= h($s['date']) ?></strong><?php endforeach; ?>
        <?php if (pat_signup_overdue($r)): ?>
          <a href="paten-programm.php?id=<?= $rid ?>&t=programm" style="color:var(--red);font-weight:700">
            <i class="ti ti-alert-triangle"></i> Anmeldeschluss vorbei, noch offen</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <div class="btn-row" style="margin:0 0 .6rem">
      <a class="btn small" href="paten-programm.php?id=<?= $rid ?>"><i class="ti ti-list-details"></i> Anmeldungen &amp; Einteilung</a>
    </div>
    <p class="small muted" style="margin:0 0 .7rem">
      Angelegt am <?= h(date('d.m.Y', strtotime((string)$r['created_at']))) ?>
      <?php if (!empty($r['opened_at'])): ?> · geöffnet am <?= h(date('d.m.Y', strtotime((string)$r['opened_at']))) ?><?php endif; ?>
      <?php if (!empty($r['closed_at'])): ?> · geschlossen am <?= h(date('d.m.Y', strtotime((string)$r['closed_at']))) ?><?php endif; ?>
    </p>

    <label for="url<?= $rid ?>">Link für die Studierenden</label>
    <?php if ($url === ''): ?>
      <p class="small muted" style="margin:.2rem 0 0">Erst die Basis-URL hinterlegen (siehe Hinweis oben).</p>
    <?php else: ?>
      <div class="btn-row" style="gap:.5rem;align-items:center">
        <input type="text" id="url<?= $rid ?>" value="<?= h($url) ?>" readonly
               style="flex:1 1 260px;min-width:0;font-size:.85rem">
        <button type="button" class="btn secondary small js-copy" data-copy="url<?= $rid ?>"><i class="ti ti-copy"></i> Kopieren</button>
        <a class="btn secondary small" href="<?= h($url) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> Ansehen</a>
      </div>
      <p class="small muted" style="margin:.35rem 0 0">Ein Link für beide Gruppen. Er zeigt in
        Teams, WhatsApp &amp; Co. eine Vorschau mit Titel und Semester.</p>
    <?php endif; ?>

    <?php if ($url !== ''): ?>
      <details style="margin-top:.9rem">
        <summary style="cursor:pointer;font-weight:600"><i class="ti ti-mail"></i> Rundmail-Text (fertig, mit Link)</summary>
        <label for="subj<?= $rid ?>" style="margin-top:.6rem">Betreff</label>
        <div class="btn-row" style="gap:.5rem;align-items:center">
          <input type="text" id="subj<?= $rid ?>" value="<?= h($subject) ?>" readonly style="flex:1 1 240px;min-width:0">
          <button type="button" class="btn secondary small js-copy" data-copy="subj<?= $rid ?>"><i class="ti ti-copy"></i> Kopieren</button>
        </div>
        <label for="body<?= $rid ?>" style="margin-top:.6rem">Text</label>
        <textarea id="body<?= $rid ?>" rows="<?= max(6, substr_count($body, "\n") + 2) ?>" readonly
                  style="width:100%;font-size:.88rem"><?= h($body) ?></textarea>
        <div class="btn-row" style="margin-top:.5rem">
          <button type="button" class="btn secondary small js-copy" data-copy="body<?= $rid ?>"><i class="ti ti-copy"></i> Text kopieren</button>
        </div>
        <p class="small muted" style="margin:.4rem 0 0">Der Text wird <a href="paten.php?t=texte">unten</a>
          gepflegt; hier steht er mit eingesetztem Semester und Link. Verschickt wird von Hand
          über die üblichen Studi-Mailwege – die App verschickt nichts an Studierende.</p>
      </details>
    <?php endif; ?>

    <div class="btn-row" style="margin-top:.9rem;flex-wrap:wrap;gap:.4rem">
      <?php if ($status !== 'open'): ?>
        <form method="post"><?= csrf_field() ?>
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="id" value="<?= $rid ?>">
          <input type="hidden" name="status" value="open">
          <button class="btn small" type="submit"><i class="ti ti-lock-open"></i> Anmeldung öffnen</button>
        </form>
      <?php else: ?>
        <form method="post" data-confirm="Anmeldung für „<?= h($label) ?>“ schließen? Studierende können sich dann nicht mehr eintragen." data-confirm-ok="Schließen"><?= csrf_field() ?>
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="id" value="<?= $rid ?>">
          <input type="hidden" name="status" value="closed">
          <button class="btn secondary small" type="submit"><i class="ti ti-lock"></i> Anmeldung schließen</button>
        </form>
      <?php endif; ?>

      <?php if ($status === 'closed'): ?>
        <form method="post"><?= csrf_field() ?>
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="id" value="<?= $rid ?>">
          <input type="hidden" name="status" value="archived">
          <button class="btn secondary small" type="submit"><i class="ti ti-archive"></i> Archivieren</button>
        </form>
      <?php endif; ?>

      <form method="post" data-confirm="Neuen Link erzeugen? Der bisherige Link funktioniert danach nicht mehr – wer ihn schon hat, landet auf einer Fehlerseite." data-confirm-ok="Neuen Link erzeugen"><?= csrf_field() ?>
        <input type="hidden" name="action" value="regen_link">
        <input type="hidden" name="id" value="<?= $rid ?>">
        <button class="btn secondary small" type="submit"><i class="ti ti-refresh"></i> Link erneuern</button>
      </form>

      <form method="post" data-confirm="Programm „<?= h($label) ?>“ endgültig löschen? Der Link wird ungültig." data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_round">
        <input type="hidden" name="id" value="<?= $rid ?>">
        <button class="btn danger small" type="submit"><i class="ti ti-trash"></i> Löschen</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<?php endif; ?>

<?php if ($pTab === 'studiengaenge'): ?>
<?php $allCourses = pat_courses(null, false); ?>
<div class="section-title" id="studiengaenge"><i class="ti ti-books"></i> Studiengänge
  <?php if ($allCourses): ?><span class="count"><?= count($allCourses) ?></span><?php endif; ?></div>
<div class="card">
  <p class="small muted" style="margin:0 0 .9rem">Erstis wählen bei der Anmeldung zuerst
    <strong>Bachelor oder Master</strong> und dann ihren Studiengang aus der jeweiligen Liste hier.
    Ein Studiengang, den es in beiden Abschlüssen gibt, wird zweimal angelegt.
    Ein Studiengang kann eine <strong>Unterauswahl</strong> bekommen (z. B. „Gymnasiallehramt"
    mit Erstfach Deutsch/Englisch/…): Unterpunkte anlegen, und die Anmeldung fragt nach dem
    Studiengang automatisch den Unterpunkt ab. Die <strong>Bezeichnung</strong> der Unterauswahl
    („Erstfach") bestimmt dabei die Frage („Und dein Erstfach?").
    Wer seins nicht findet, kann es auf der Anmeldeseite <strong>frei eintragen</strong> und wird
    später von Hand zugeordnet.
    <?php if (!$allCourses): ?><br><strong style="color:var(--amber)">Noch keine Studiengänge
    hinterlegt</strong> – Erstis sehen dann statt der Auswahl einen Hinweis (plus Freitext-Feld).<?php endif; ?></p>
  <div class="grid-2">
    <?php foreach (pat_degrees() as $dKey => $dLabel): $list = pat_courses($dKey, false); ?>
      <div>
        <h3 style="margin:0 0 .5rem;font-size:1rem">
          <i class="ti <?= $dKey === 'master' ? 'ti-certificate' : 'ti-book-2' ?>" style="color:var(--petrol)"></i>
          <?= h($dLabel) ?> <span class="count"><?= count($list) ?></span>
        </h3>
        <?php if (!$list): ?>
          <p class="small muted" style="margin:.2rem 0 .6rem">Noch keine hinterlegt.</p>
        <?php endif; ?>
        <?php foreach ($list as $c): $kids = pat_course_children((int)$c['id']); ?>
          <div style="padding:.3rem 0;border-bottom:1px solid var(--line)">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
              <span><?= h((string)$c['name']) ?><?php if ($kids): ?>
                <span class="small muted">· <?= count($kids) ?> <?= h(trim((string)$c['sub_label']) !== '' ? $c['sub_label'] : 'Unterpunkte') ?></span><?php endif; ?></span>
              <form method="post" data-confirm="„<?= h((string)$c['name']) ?>“ (<?= h($dLabel) ?>)<?= $kids ? ' samt ' . count($kids) . ' Unterpunkten' : '' ?> löschen?" data-confirm-danger data-confirm-ok="Löschen" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="course_delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn danger small" type="submit" title="Löschen"><i class="ti ti-trash"></i></button>
              </form>
            </div>
            <details style="margin:.15rem 0 .1rem .9rem">
              <summary class="small" style="cursor:pointer;color:var(--petrol)">
                Unterauswahl<?= $kids ? ' (' . count($kids) . ')' : ' hinzufügen' ?> …</summary>
              <?php foreach ($kids as $k): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.2rem 0">
                  <span class="small"><?= h((string)$k['name']) ?></span>
                  <form method="post" data-confirm="Unterpunkt „<?= h((string)$k['name']) ?>“ löschen?" data-confirm-danger data-confirm-ok="Löschen" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="course_delete">
                    <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                    <button class="btn danger small" type="submit" title="Löschen"><i class="ti ti-x"></i></button>
                  </form>
                </div>
              <?php endforeach; ?>
              <form method="post" style="display:flex;gap:.4rem;margin:.4rem 0;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="course_add">
                <input type="hidden" name="degree" value="<?= h($dKey) ?>">
                <input type="hidden" name="parent_id" value="<?= (int)$c['id'] ?>">
                <input type="text" name="name" placeholder="Unterpunkt, z. B. Deutsch …" required
                       maxlength="120" style="flex:1 1 150px;min-width:0">
                <button class="btn small" type="submit"><i class="ti ti-plus"></i></button>
              </form>
              <form method="post" style="display:flex;gap:.4rem;margin:.2rem 0 .3rem;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="course_sublabel">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="text" name="sub_label" value="<?= h((string)$c['sub_label']) ?>"
                       placeholder="Bezeichnung, z. B. Erstfach" maxlength="40" style="flex:1 1 150px;min-width:0">
                <button class="btn secondary small" type="submit" title="Bezeichnung speichern"><i class="ti ti-device-floppy"></i></button>
              </form>
            </details>
          </div>
        <?php endforeach; ?>
        <form method="post" style="display:flex;gap:.5rem;margin-top:.7rem;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="course_add">
          <input type="hidden" name="degree" value="<?= h($dKey) ?>">
          <input type="text" name="name" placeholder="Studiengang hinzufügen …" required
                 maxlength="120" style="flex:1 1 160px;min-width:0">
          <button class="btn small" type="submit"><i class="ti ti-plus"></i></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php endif; ?>

<?php if ($pTab === 'texte'): ?>
<div class="section-title" id="texte"><i class="ti ti-text-caption"></i> Texte</div>
<div class="card">
  <p class="small muted" style="margin:0 0 .3rem">Hier steht <strong>jeder Satz</strong>, den
    Studierende auf der Anmeldeseite lesen – im Code ist kein Text mehr festgeschrieben.</p>
  <ul class="small muted" style="margin:0 0 1rem;padding-left:1.2rem">
    <li><code>{{PROGRAMM}}</code> darf in <em>jedem</em> Feld stehen und wird zum Semester
      (z. B. <?= h(pat_label('wise', (int)$suggest['year'])) ?>).</li>
    <li>Ebenso überall erlaubt: <code>{{ANMELDUNG_VON}}</code>, <code>{{ANMELDUNG_BIS}}</code> und
      <code>{{EINTEILUNG_BIS}}</code> – die Termine aus dem <strong>Zeitplan</strong> des jeweiligen
      Programms, im Format 15.10.2026. <strong>Achtung:</strong> Ist ein Termin dort nicht
      eingetragen, bleibt der Platzhalter <em>leer</em> – dann steht in der Mail „Melde dich bis an".
      Solche Sätze also nur schreiben, wenn die Daten auch gepflegt sind (die Termin-Leiste auf der
      Studi-Seite blendet fehlende Termine dagegen von selbst aus).</li>
    <li>Ein <strong>leeres Feld blendet das Element aus</strong> – so wird man Dinge auch los.</li>
    <li>Bei mehrzeiligen Feldern ist eine <strong>Leerzeile ein neuer Absatz</strong>.</li>
    <li>Bei den <strong>Kachel-Inhalten</strong> ist die <strong>erste Zeile der Einleitungssatz</strong>,
      jede weitere Zeile wird ein Punkt mit Häkchen.</li>
  </ul>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_texts">

    <?php foreach (pat_text_groups() as $gKey => $g): ?>
      <div class="section-title" style="font-size:1rem;margin:1.3rem 0 .5rem">
        <i class="ti <?= h($g['icon']) ?>"></i> <?= h($g['label']) ?>
      </div>
      <?php foreach (pat_text_fields() as $fKey => $f):
          if ($f['group'] !== $gKey) continue;
          $val  = pat_text($fKey);
          $id   = 't_' . $fKey;
          $mono = !empty($f['mono']);
          $rows = $f['type'] === 'tile' ? 5 : max(3, substr_count($val, "\n") + 2);
      ?>
        <label for="<?= h($id) ?>"><?= h($f['label']) ?></label>
        <?php if (!empty($f['hint'])): ?>
          <p class="small muted" style="margin:.15rem 0 .3rem"><?= h($f['hint']) ?></p>
        <?php endif; ?>
        <?php if ($f['type'] === 'line'): ?>
          <input type="text" name="<?= h($id) ?>" id="<?= h($id) ?>" value="<?= h($val) ?>" style="width:100%">
        <?php else: ?>
          <textarea name="<?= h($id) ?>" id="<?= h($id) ?>" rows="<?= (int)$rows ?>"
            style="width:100%<?= $mono ? ';font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem' : '' ?>"><?= h($val) ?></textarea>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="btn-row" style="margin-top:1rem">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Alle Texte speichern</button>
    </div>
  </form>
  <form method="post" data-confirm="Alle Texte auf den Standard zurücksetzen? Eigene Formulierungen gehen verloren." data-confirm-ok="Zurücksetzen" style="margin-top:.5rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_texts">
    <button class="btn secondary small" type="submit"><i class="ti ti-restore"></i> Auf Standard zurücksetzen</button>
  </form>
</div>

<?php endif; ?>

<?php if ($pTab === 'fragen'): ?>
<?php
// --- Fragen aus dem Hilfe-Formular der Studi-Seiten --------------------------
// Sie werden per Mail an die Paten-Adresse weitergeleitet; hier stehen sie zusätzlich. Das ist
// kein Luxus: geht der Mailversand schief, wäre die Frage sonst weg, ohne dass es jemand merkt.
$fragen = pat_messages_recent(40);
$offen  = pat_messages_open_count();
?>
<div class="section-title" id="fragen"><i class="ti ti-message-circle-question"></i> Fragen von der Studi-Seite
  <?php if ($fragen): ?><span class="count"><?= count($fragen) ?></span><?php endif; ?></div>
<div class="card">
  <p class="small muted" style="margin:0 0 .8rem">Studierende fragen über das Formular hinter dem
    Hilfe-Knopf. Jede Frage geht als Mail an die <strong>Paten-Mail</strong> – mit der Adresse der
    fragenden Person als <strong>Antwortadresse</strong>, ein „Antworten" im Postfach genügt also.
    Verschickt wird beim nächsten <strong>stündlichen Cron-Lauf</strong>, nicht sofort. Hier stehen
    die Fragen zusätzlich, damit keine verlorengeht, wenn beim Versand etwas klemmt.
    Erledigte werden nach <?= PAT_MSG_KEEP_DAYS ?> Tagen automatisch gelöscht.</p>
  <?php if ($offen > 0): ?>
    <p class="small" style="margin:0 0 .8rem"><i class="ti ti-clock" style="color:var(--amber)"></i>
      <strong><?= $offen ?></strong> Frage(n) noch nicht weitergeleitet.</p>
  <?php endif; ?>
  <?php if (!$fragen): ?>
    <p class="small muted" style="margin:0">Noch keine Fragen eingegangen.</p>
  <?php else: ?>
    <table class="list">
      <thead><tr><th style="width:1%">Stand</th><th>Von</th><th>Frage</th><th style="width:1%"></th></tr></thead>
      <tbody>
      <?php foreach ($fragen as $m):
          $st   = (string)$m['status'];
          $pill = $st === 'gesendet' ? 'pill-ok' : ($st === 'fehler' ? 'pill-bad' : 'pill-warn');
          $wort = $st === 'gesendet' ? 'weitergeleitet' : ($st === 'fehler' ? 'Versand klemmt' : 'wartet');
          $wer  = trim((string)$m['name']);
          $rnd  = $m['round_id'] ? pat_round_get((int)$m['round_id']) : null;
      ?>
        <tr>
          <td style="white-space:nowrap">
            <span class="pill <?= $pill ?>" style="font-size:.7rem"><?= h($wort) ?></span>
            <?php if ((string)$m['last_error'] !== ''): ?>
              <br><span class="small muted" title="<?= h((string)$m['last_error']) ?>">
                <?= h(mb_strimwidth((string)$m['last_error'], 0, 32, '…')) ?></span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($wer !== ''): ?><strong><?= h($wer) ?></strong><br><?php endif; ?>
            <a href="mailto:<?= h((string)$m['email']) ?>?subject=<?= h(rawurlencode('Antwort auf deine Frage zum Pat:innenprogramm')) ?>"><?= h((string)$m['email']) ?></a>
            <br><span class="small muted"><?= h(fmt_date(substr((string)$m['created_at'], 0, 10))) ?>
              <?= h(substr((string)$m['created_at'], 11, 5)) ?> Uhr<?php
              if ($rnd): ?> · <?= h(pat_round_label($rnd)) ?><?php endif; ?></span>
          </td>
          <td class="small" style="white-space:pre-line"><?= h((string)$m['body']) ?></td>
          <td style="white-space:nowrap">
            <?php if ($st === 'fehler'): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="msg_retry">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button class="btn secondary small" type="submit"><i class="ti ti-refresh"></i> Nochmal</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline" data-confirm="Diese Frage endgültig löschen?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="msg_delete">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn danger small" type="submit" title="Löschen"><i class="ti ti-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($pTab === 'einstellungen'): ?>
<div class="section-title" id="versand"><i class="ti ti-send"></i> Mailversand</div>
<div class="card">
  <p class="small" style="margin:0 0 .7rem"><i class="ti ti-info-circle" style="color:var(--petrol)"></i>
    <strong>Der Hoster lässt eine feste Zahl E-Mails je 24 Stunden durch</strong> (bei Mittwald 3000, für <code><?= h(org_mail_beispiel('app')) ?></code>
    freigeschaltet im August 2026, davor waren es 400) – und zwar
    für <em>alles</em>, was aus eurer Adresse rausgeht. Deshalb gibt es <strong>ein gemeinsames
    Mail-Konto</strong>: Login-Links, Mitteilungen, Umfragen, externe Events und die persönlichen
    Einteilungs-Mails des Pat:innenprogramms tragen alle dort ein und rechnen sich gegenseitig an,
    was schon rausging. Ein Semester mit 300 Erstsemestern sind allein rund 355 Mails – seit der
    Hoster <strong>3000 je 24 Stunden</strong> zulässt, passt das <strong>an einem Stück</strong>.
    Ein Klick auf „Alle verschicken" schickt sie also normalerweise komplett raus.</p>
  <p class="small muted">Was den Klick trotzdem begrenzt, ist <strong>nicht</strong> das Kontingent, sondern
    die <strong>Laufzeit</strong>: Hunderte Mails brauchen ihre Sekunden, und irgendwann bricht der Webserver eine
    Anfrage ab. Deshalb hört der Versand nach dem eingestellten <strong>Zeitbudget</strong> sauber auf, statt
    mittendrin zu sterben – und was dann noch übrig ist, holt der stündliche Cron-Lauf. Bei den Vorgaben
    (120&nbsp;Sekunden, 250 je Lauf) kommt das selten vor.</p>
  <form method="post" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_mailrate">
    <div><label for="pat_mail_day_cap">Eigener Deckel je 24 h</label>
      <input type="number" name="pat_mail_day_cap" id="pat_mail_day_cap" min="0" max="3000"
             value="<?= (int)setting_get('pat_mail_day_cap', '0') ?>" style="width:7rem">
      <?= mail_hint('pat_day_cap', (int)setting_get('pat_mail_day_cap', '0')) ?></div>
    <div><label for="pat_mail_per_run">Je Cron-Lauf</label>
      <input type="number" name="pat_mail_per_run" id="pat_mail_per_run" min="1" max="3000"
             value="<?= (int)setting_get('pat_mail_per_run', '250') ?>" style="width:7rem">
      <?= mail_hint('pat_per_run', (int)setting_get('pat_mail_per_run', '250')) ?></div>
    <div><label for="pat_mail_seconds">Zeitbudget (Sek.)</label>
      <input type="number" name="pat_mail_seconds" id="pat_mail_seconds" min="5" max="300"
             value="<?= (int)setting_get('pat_mail_seconds', '120') ?>" style="width:7rem">
      <?= mail_hint('pat_seconds', (int)setting_get('pat_mail_seconds', '120')) ?></div>
    <button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
  </form>
  <?php mail_budget_card(); ?>
  <p class="small muted" style="margin:.7rem 0 0">
    <strong>Eigener Deckel je 24 h</strong> ist eine freiwillige Bremse nur für diesen Bereich –
    <strong>0 heißt: keine</strong>, dann gilt allein der gemeinsame Topf oben. Setz hier nur etwas
    ein, wenn eine große Aussendung nicht das ganze Tageskontingent aufbrauchen soll. Gerechnet wird in einem <strong>gleitenden Fenster</strong>, nicht nach
    Kalendertagen. <strong>Je Cron-Lauf</strong> verteilt die Menge über den Tag, damit nicht
    alles in einer Stunde rausgeht. Das <strong>Zeitbudget</strong> schützt den einzelnen Aufruf
    vor dem PHP-Zeitlimit: Ist es aufgebraucht, hört der Versand sauber auf statt mittendrin
    abzubrechen.</p>
  <p class="small muted" style="margin:.5rem 0 0"><strong>Dafür braucht es einen stündlichen
    Cron-Job</strong> auf <code>cron_patmail.php</code> (siehe <a href="index.php#cron">Cron-Job
    einrichten</a>). Fehlt er, bleibt der Rest der Warteschlange liegen, bis jemand erneut auf
    „Alle verschicken" klickt – verloren geht nichts.</p>
</div>

<div class="section-title" id="rechtliches"><i class="ti ti-scale"></i> Kontakt, Impressum &amp; Datenschutz</div>
<div class="card">
  <?php if (trim($imprint) === '' || trim($privacy) === ''): ?>
    <p class="small" style="margin:0 0 .6rem"><i class="ti ti-alert-triangle" style="color:var(--amber)"></i>
      Die Anmeldeseite ist öffentlich erreichbar. In Deutschland gehören dort ein
      <strong>Impressum</strong> und eine <strong>Datenschutzerklärung</strong> hin – am
      einfachsten als Link auf die Seiten der AStA-Website. Solange hier nichts steht, zeigt der
      Seitenfuß die Links nicht an.</p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_legal">
    <?php
    $snd = pat_mail_sender(mail_from());
    // Gleiches Kriterium wie pat_mail_sender(), damit hier nie etwas anderes behauptet wird,
    // als tatsächlich verschickt wird.
    $patenOk = $patenMail !== '' && filter_var($patenMail, FILTER_VALIDATE_EMAIL) !== false;
    ?>
    <label for="paten_email">Paten-Mail (Fragen der Studis und Antworten auf die Einteilungs-Mails)</label>
    <p class="small muted" style="margin:.15rem 0 .3rem">Auf allen Studi-Seiten schwebt unten
      rechts ein Hilfe-Knopf mit einem Formular dahinter. Was dort abgeschickt wird, kommt an
      dieser Adresse an – und steht oben unter <a href="paten.php?t=fragen">Fragen von der Studi-Seite</a>.
      Solange hier nichts steht, gibt es den Knopf nicht und niemand kann fragen. Beschriftung,
      Texte und Mail-Betreff pflegst du bei den <a href="paten.php?t=texte">Texten</a> (Bereich
      „Hilfe-Knopf").</p>
    <p class="small muted" style="margin:.15rem 0 .3rem">
      <?php if (!$patenOk): ?>
        <i class="ti ti-alert-triangle"></i> Ohne Adresse landen auch die <strong>Antworten</strong>
        der Studierenden auf ihre Einteilungs-Mail im allgemeinen App-Postfach
        (<?= h($snd['from']) ?>) – dort liest sie erfahrungsgemäß niemand.
      <?php else: ?>
        <i class="ti ti-check"></i> Antworten auf die Einteilungs-Mails kommen hier an.
        <strong>Verschickt</strong> werden sie – wie alles aus der App – aus
        <?= h($snd['from']) ?>: Das ist das einzige Postfach, das der Hoster dafür freigegeben hat.
      <?php endif; ?>
      Den Absender-Namen stellst du bei den <a href="paten.php?t=texte">Texten</a> ein
      (Bereich „Mails zur Einteilung").
    </p>
    <input type="email" name="paten_email" id="paten_email" value="<?= h($patenMail) ?>" placeholder="<?= h(org_mail_beispiel('paten')) ?>">
    <label for="imprint_url" style="margin-top:.6rem">Impressum (vollständige Adresse)</label>
    <input type="url" name="imprint_url" id="imprint_url" value="<?= h($imprint) ?>" placeholder="<?= h('https://' . org_domain() . '/impressum') ?>">
    <label for="privacy_url" style="margin-top:.6rem">Datenschutzerklärung</label>
    <input type="url" name="privacy_url" id="privacy_url" value="<?= h($privacy) ?>" placeholder="<?= h('https://' . org_domain() . '/datenschutz') ?>">
    <div class="btn-row" style="margin-top:.8rem">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
    </div>
  </form>
</div>

<?php /* Der Abschnitt steht jetzt für ALLE, die die Seite betreuen: Wer nicht festlegen darf,
         sieht wenigstens, wer zuständig ist. Die frühere Liste samt Auswahlfeld ist im
         gemeinsamen Baustein zust_picker_html() aufgegangen. */ ?>
<div class="section-title" id="verantwortliche"><i class="ti ti-user-star"></i> Verantwortliche</div>
<div class="card">
  <p class="small muted" style="margin:0 0 .8rem">Wer das Pat:innenprogramm betreut, bekommt
    <strong>Zugriff auf genau diese Seite</strong> und sieht sie als eigenen Punkt
    <strong>„Pat:innen" in der Titelleiste</strong> – sonst nichts aus der Verwaltung.
    Admin &amp; Vorsitz haben immer Zugriff (über die Verwaltung).</p>
  <?php if ($canTeam): ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="zust_save">
      <?= zust_picker_html('pat') ?>
      <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button></div>
    </form>
  <?php else: ?>
    <p class="small muted" style="margin:0"><i class="ti ti-users"></i> Zuständig:
      <?= h(zust_text('pat')) ?> <span class="muted">(festlegen dürfen das Admin &amp; Vorsitz)</span></p>
  <?php endif; ?>
</div>

<div class="section-title"><i class="ti ti-shield-lock"></i> Wie die Daten getrennt sind</div>
<div class="card">
  <p class="small muted" style="margin:0">Das Pat:innenprogramm hat eine <strong>eigene
    Datenbank</strong> (<code>data/pat.sqlite</code>, von außen nicht abrufbar). Die öffentliche
    Anmeldeseite lädt die App-Bibliothek nicht und hat damit keine Verbindung zu Mitgliedern,
    Anmelde-Tokens oder Sitzungen – sie <em>kann</em> dort nichts erreichen, nicht nur „darf
    nicht". Diese Verwaltungsseite ist die einzige Stelle, die beide Datenbanken kennt.</p>
</div>
<?php endif; ?>

<script>
(function () {
  // Kopier-Knöpfe (Link, Betreff, Mailtext)
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.js-copy') : null;
    if (!b) return;
    var el = document.getElementById(b.getAttribute('data-copy'));
    if (!el) return;
    var done = function () {
      var old = b.innerHTML;
      b.innerHTML = '<i class="ti ti-check"></i> Kopiert';
      setTimeout(function () { b.innerHTML = old; }, 1500);
    };
    try { el.select(); } catch (_) {}
    if (navigator.clipboard) { navigator.clipboard.writeText(el.value).then(done, function () { try { document.execCommand('copy'); } catch (_) {} done(); }); }
    else { try { document.execCommand('copy'); } catch (_) {} done(); }
  });
})();
</script>
<?php
page_footer();
