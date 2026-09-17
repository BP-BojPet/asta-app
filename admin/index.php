<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
try_remember(); // evtl. via „eingeloggt bleiben"-Cookie als Organisator:in erkennen

// --- Erst-Einrichtung: Verwaltungs-/Technik-Passwort festlegen ---
if (!admin_password_set()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $p1 = (string)($_POST['pw'] ?? '');
        $p2 = (string)($_POST['pw2'] ?? '');
        if (strlen($p1) < 6) {
            flash('Bitte ein Passwort mit mindestens 6 Zeichen wählen.', 'error');
        } elseif ($p1 !== $p2) {
            flash('Die Passwörter stimmen nicht überein.', 'error');
        } else {
            setting_set('admin_password_hash', password_hash($p1, PASSWORD_DEFAULT));
            $_SESSION['is_owner'] = true;
            flash('Verwaltungs-Passwort gesetzt. Willkommen!', 'success');
            redirect('index.php');
        }
    }
    page_header('Einrichtung', true);
    ?>
    <h1>Einrichtung</h1>
    <div class="card" style="max-width:420px">
      <p>Lege ein Technik-/Verwaltungspasswort fest. Damit richtest du anschließend die Mitglieder ein und bestimmst, wer Organisator:in ist. Mitglieder selbst melden sich später per Login-Link an – nicht mit diesem Passwort.</p>
      <form method="post">
        <?= csrf_field() ?>
        <label for="pw">Passwort</label>
        <input type="password" name="pw" id="pw" required>
        <label for="pw2">Passwort wiederholen</label>
        <input type="password" name="pw2" id="pw2" required>
        <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit">Festlegen</button></div>
      </form>
    </div>
    <?php
    page_footer();
    exit;
}

// Eingeloggte Mitglieder ohne Orga-Rolle gehören nicht hierher
if (current_member() && !is_organizer()) {
    flash('Der Verwaltungsbereich ist nur für Organisator:innen.', 'error');
    redirect('../dashboard.php');
}

// --- Technik-/Owner-Login (Bootstrap & Notfall) ---
if (!is_organizer()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $pw = (string)($_POST['pw'] ?? '');
        if (password_verify($pw, (string)setting_get('admin_password_hash'))) {
            session_regenerate_id(true);
            $_SESSION['is_owner'] = true;
            redirect('index.php');
        }
        flash('Falsches Passwort.', 'error');
    }
    page_header('Anmelden', true);
    ?>
    <h1>Verwaltung – Technik-Login</h1>
    <div class="card" style="max-width:380px">
      <p class="small muted">Für die Ersteinrichtung und den Notfall. Organisator:innen melden sich normalerweise per <a href="../login.php">Login-Link</a> an.</p>
      <form method="post">
        <?= csrf_field() ?>
        <label for="pw">Verwaltungs-Passwort</label>
        <input type="password" name="pw" id="pw" autofocus required>
        <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit">Anmelden</button></div>
      </form>
    </div>
    <?php
    page_footer();
    exit;
}

// --- Dashboard ---
// Einstellungen speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    check_csrf();
    setting_set('mail_from', trim((string)($_POST['mail_from'] ?? '')));
    setting_set('base_url', rtrim(trim((string)($_POST['base_url'] ?? '')), '/'));
    setting_set('reminder_lead_days', (string)max(0, (int)($_POST['reminder_lead_days'] ?? 3)));
    setting_set('swap_lead_days', (string)max(0, (int)($_POST['swap_lead_days'] ?? 3)));
    // Der gemeinsame Mail-Topf liegt in einer eigenen Datei – hier steht die einzige Stelle,
    // an der man Hoster-Grenze und Reserve einstellt.
    mail_pool_setting_set('limit',   (string)max(50, (int)($_POST['mail_limit'] ?? 3000)));
    mail_pool_setting_set('reserve', (string)max(0,  (int)($_POST['mail_reserve'] ?? 200)));
    flash('Einstellungen gespeichert.', 'success');
    redirect('index.php');
}

// Notfall: neues Technik-Passwort per Magic-Link anfordern (Admin/Vorsitz mit E-Mail)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'techpw_recover_request') {
    check_csrf();
    $m = current_member();
    if ($m && in_array(current_role(), ['admin', 'vorsitz'], true) && trim((string)$m['email']) !== '') {
        $url = app_url('admin/recover.php?token=' . create_stepup_token((int)$m['id']));
        send_mail($m['email'], 'Technik-Passwort zurücksetzen',
            "Hallo " . first_name($m['name']) . ",\n\n"
            . "über diesen Link kannst du innerhalb von 30 Minuten ein neues Technik-/Verwaltungs-Passwort setzen – ohne das alte zu kennen:\n\n"
            . $url . "\n\n"
            . "Wenn du das nicht angefordert hast, ignoriere diese Mail – es ändert sich nichts.\n");
        flash('Notfall-Link zum Zurücksetzen wurde an deine E-Mail (' . $m['email'] . ') geschickt.', 'success');
    } else {
        flash('Notfall-Reset ist nur für Admin/Vorsitz mit hinterlegter E-Mail möglich.', 'error');
    }
    redirect('index.php');
}

// Basis-URL bestimmen (für absolute Links in Cron-Mails)
$baseGuess = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')
    . rtrim(dirname($_SERVER['PHP_SELF'] ?? '', 2), '/');
// Defaults beim ersten Aufruf automatisch festlegen
if (setting_get('base_url') === null) setting_set('base_url', $baseGuess);
if (setting_get('mail_from') === null) setting_set('mail_from', mail_from());
if (setting_get('cron_key') === null) setting_set('cron_key', bin2hex(random_bytes(12)));

$counts = [
    'members'  => (int)db()->query('SELECT COUNT(*) FROM members WHERE active=1')->fetchColumn(),
    'events'   => (int)db()->query('SELECT COUNT(*) FROM events WHERE closed=0 AND draft=0')->fetchColumn(),
    'meetings' => (int)db()->query("SELECT COUNT(*) FROM meetings WHERE cancelled = 0 AND draft = 0 AND date(starts_at) >= date('now','localtime')")->fetchColumn(),
];
// Pat:innenprogramm: eigene Datenbank, deshalb bewusst gekapselt – schlägt sie fehl,
// bleibt die Verwaltung trotzdem benutzbar (die Kachel zeigt dann nur keinen Stand).
$patOpen = null;
$patAll  = [];
try {
    require_once __DIR__ . '/../pat-db.php';
    $patOpen = pat_round_single_open();
    $patAll  = pat_rounds_all();
} catch (\Throwable $e) {
    app_log_error('Pat:innenprogramm-Kachel: ' . $e->getMessage());
}

$base = base_url() ?: $baseGuess;
$icalUrl = $base . '/ical.php';

page_header('Verwaltung', true);
?>
<div class="events-toolbar">
  <h1><i class="ti ti-settings" style="color:var(--petrol)"></i> Verwaltung</h1>
</div>

<div class="section-title"><i class="ti ti-layout-grid"></i> Bereiche</div>
<div class="event-cards">
  <a class="event-card" href="members.php">
    <div class="ec-title"><i class="ti ti-users"></i> Mitglieder</div>
    <div class="ec-meta"><span><?= $counts['members'] ?> aktiv</span></div>
  </a>
  <a class="event-card" href="events.php">
    <div class="ec-title"><i class="ti ti-calendar-event"></i> Events &amp; Termine</div>
    <div class="ec-meta"><span><?= $counts['events'] ?> offen</span></div>
  </a>
  <a class="event-card" href="meetings.php">
    <div class="ec-title"><i class="ti ti-gavel"></i> Sitzungen</div>
    <div class="ec-meta"><span><?= $counts['meetings'] ?> kommend</span></div>
  </a>
  <a class="event-card" href="message.php">
    <div class="ec-title"><i class="ti ti-message-plus"></i> Nachricht hinterlassen</div>
    <div class="ec-meta"><span>Aufs Dashboard – einzeln oder als Ankündigung</span></div>
  </a>
  <a class="event-card" href="score.php">
    <div class="ec-title"><i class="ti ti-trophy"></i> Scores</div>
    <div class="ec-meta"><span>Eventscore &amp; Basis-Score</span></div>
  </a>
  <a class="event-card" href="activity.php">
    <div class="ec-title"><i class="ti ti-activity-heartbeat"></i> Aktivitätsstatistik</div>
    <div class="ec-meta"><span>Wer öffnet die App wie regelmäßig</span></div>
  </a>
  <a class="event-card" href="achievements.php">
    <div class="ec-title"><i class="ti ti-trophy"></i> Achievements-Übersicht</div>
    <div class="ec-meta"><span>Wer hat welche Erfolge · beliebteste Belohnungen</span></div>
  </a>
  <a class="event-card" href="../absence.php">
    <div class="ec-title"><i class="ti ti-plane-departure"></i> Abwesenheit</div>
    <div class="ec-meta"><span>Eigene Urlaube/Abwesenheiten eintragen</span></div>
  </a>
  <a class="event-card" href="help.php">
    <div class="ec-title"><i class="ti ti-book-2"></i> Anleitung</div>
    <div class="ec-meta"><span>So funktioniert das Tool</span></div>
  </a>
  <a class="event-card" href="infos.php">
    <div class="ec-title"><i class="ti ti-pin"></i> Wichtige Informationen</div>
    <div class="ec-meta"><span>Infos &amp; Dokumente für alle Mitglieder</span></div>
  </a>
  <a class="event-card" href="wegweiser.php">
    <div class="ec-title"><i class="ti ti-directions"></i> Wegweiser</div>
    <div class="ec-meta"><span>Dienste, Anlaufstellen &amp; Ansprechpartner:innen</span></div>
  </a>
  <a class="event-card" href="traeger.php">
    <div class="ec-title"><i class="ti ti-building-community"></i> Träger</div>
    <div class="ec-meta"><span>Name, Kontakt &amp; Logo dieser Installation</span></div>
  </a>
  <a class="event-card" href="mailtexts.php">
    <div class="ec-title"><i class="ti ti-mail-cog"></i> Mailtexte</div>
    <div class="ec-meta"><span>Betreff &amp; Text der System-Mails</span></div>
  </a>
  <a class="event-card" href="sekretariat.php">
    <div class="ec-title"><i class="ti ti-id-badge-2"></i> Sekretariatsaufgaben</div>
    <div class="ec-meta"><span>Bericht-Status &amp; eingereichte TOPs</span></div>
  </a>
  <a class="event-card" href="vorlagen.php">
    <div class="ec-title"><i class="ti ti-file-type-docx"></i> Word-Vorlagen</div>
    <div class="ec-meta"><span>Protokoll- &amp; Berichte-Vorlage anpassen</span></div>
  </a>
  <a class="event-card" href="../uploads.php">
    <div class="ec-title"><i class="ti ti-cloud-upload"></i> Uploads und Automationen</div>
    <div class="ec-meta"><span>Teams-/OLAT-Ablage, Berichte-Dok &amp; Protokoll-Workflow</span></div>
  </a>
  <?php $bugsOpen = bug_open_count(); ?>
  <a class="event-card" href="bugs.php">
    <div class="ec-title"><i class="ti ti-bug"></i> Bug-Reports</div>
    <div class="ec-meta"><span><?= $bugsOpen > 0 ? $bugsOpen . ' offen' : 'keine offenen Tickets' ?></span></div>
  </a>
  <?php $fbOpen = feedback_open_count(); ?>
  <a class="event-card" href="feedback.php">
    <div class="ec-title"><i class="ti ti-message-2"></i> Feedback</div>
    <div class="ec-meta"><span><?= $fbOpen > 0 ? $fbOpen . ' unerledigt' : 'nichts Unerledigtes' ?></span></div>
  </a>
  <a class="event-card" href="errorlog.php">
    <div class="ec-title"><i class="ti ti-stethoscope"></i> Diagnose &amp; Fehler-Log</div>
    <div class="ec-meta"><span>Selbsttest &amp; aufgezeichnete Fehler</span></div>
  </a>
</div>

<?php /* Was hier steht, sehen Menschen OHNE App-Zugang. Der eigene Abschnitt macht das sichtbar:
         Wer an diesen Stellen etwas ändert, ändert etwas an einer öffentlichen Seite. */ ?>
<div class="section-title" id="extern"><i class="ti ti-world"></i> Werkzeuge für Menschen außerhalb des AStA</div>
<div class="card">
  <p class="small muted" style="margin:0">Diese vier laufen <strong>außerhalb der App</strong> und in <strong>eigenen Datenbanken</strong>:
    Wer sie benutzt, hat kein Konto bei uns und sieht nur die öffentliche Seite. Änderungen hier wirken sich also
    unmittelbar auf das aus, was Außenstehende zu sehen bekommen. Die <strong>externe Redeliste</strong> gehört auch
    dazu, liegt aber nicht in dieser App und hat deshalb keine Kachel.</p>
</div>
<div class="event-cards">
  <a class="event-card" href="paten.php">
    <div class="ec-title"><i class="ti ti-heart-handshake"></i> Pat:innenprogramm</div>
    <div class="ec-meta"><span><?= $patOpen
        ? 'Anmeldung offen · ' . h(pat_round_label($patOpen))
        : ($patAll ? count($patAll) . ' Programm(e) · keine Anmeldung offen' : 'noch nicht gestartet') ?></span></div>
  </a>
  <a class="event-card" href="umfragen.php">
    <div class="ec-title"><i class="ti ti-chart-donut"></i> Umfragen</div>
    <div class="ec-meta"><span>Hochschulöffentlich, mit Prüfung der Hochschul-Adresse</span></div>
  </a>
  <a class="event-card" href="terminplaner.php">
    <div class="ec-title"><i class="ti ti-calendar-search"></i> Terminplaner</div>
    <div class="ec-meta"><span>Termine finden ohne Konto – kostenfrei für alle</span></div>
  </a>
  <a class="event-card" href="../extern.php">
    <div class="ec-title"><i class="ti ti-ticket"></i> Externe Events</div>
    <div class="ec-meta"><span>Öffentliche Anmeldungen, Gruppen &amp; Fahrpläne</span></div>
  </a>
  <?php if (wl_can_manage()):
      // Wartende Freigaben direkt auf der Kachel: Ein eingereichter Beitrag, den niemand sieht,
      // ist für die einreichende Gruppe dasselbe wie eine Ablehnung – nur ohne Begründung.
      $wlWartet = 0;
      try { require_once __DIR__ . '/../wl-db.php'; wl_db(); $wlWartet = wl_count('pending'); } catch (\Throwable $e) {}
  ?>
  <a class="event-card" href="../veranstaltungen.php">
    <div class="ec-title"><i class="ti ti-calendar-star"></i> <?= wl_marke_app() ?> <span class="wip" title="Work in Progress – dieser Bereich ist noch nicht fertig">WIP</span></div>
    <div class="ec-meta"><span><?= $wlWartet > 0
        ? '<strong>' . $wlWartet . ' ' . ($wlWartet === 1 ? 'Beitrag wartet' : 'Beiträge warten') . ' auf Freigabe</strong>'
        : 'Veranstaltungsportal für Studis – Freigabe, Veranstalter, Banner' ?></span></div>
  </a>
  <?php endif; ?>
</div>

<div class="section-title"><i class="ti ti-calendar-down"></i> Kalender abonnieren (iCal)</div>
<div class="card">
  <p class="muted" style="margin:0">Jedes Mitglied hat einen <strong>persönlichen</strong> Abo-Link in seinem Dashboard – er zeigt alle Sitzungen sowie die Schichten/Termine, zu denen die Person <strong>eingeteilt</strong> ist. So kann jede:r den AStA-Kalender auf Handy/Outlook abonnieren, ohne Teams.</p>
</div>

<div class="section-title" id="einstellungen"><i class="ti ti-mail-cog"></i> E-Mail &amp; Einstellungen</div>
<div class="card">
  <form method="post" action="index.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    <div class="field-row">
      <div>
        <label for="mail_from">Absender-Adresse</label>
        <input type="text" name="mail_from" id="mail_from" value="<?= h((string)setting_get('mail_from','')) ?>">
      </div>
      <div>
        <label for="base_url">Basis-URL der App</label>
        <input type="text" name="base_url" id="base_url" value="<?= h((string)setting_get('base_url','')) ?>">
      </div>
      <div>
        <label for="reminder_lead_days">Erinnern ab … Tagen vor Frist</label>
        <input type="number" name="reminder_lead_days" id="reminder_lead_days" min="0" value="<?= (int)setting_get('reminder_lead_days','3') ?>" style="max-width:100px">
      </div>
      <div>
        <label for="swap_lead_days">Schichtbörse schließt … Tage vor Event-Start</label>
        <input type="number" name="swap_lead_days" id="swap_lead_days" min="0" value="<?= (int)swap_lead_days() ?>" style="max-width:100px">
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="mail_limit">Mails je 24 h beim Hoster</label>
        <input type="number" name="mail_limit" id="mail_limit" min="50" max="20000" value="<?= (int)mail_pool_limit() ?>" style="max-width:120px">
        <?= mail_hint('limit', mail_pool_limit()) ?>
      </div>
      <div>
        <label for="mail_reserve">davon Reserve für Login-Links</label>
        <input type="number" name="mail_reserve" id="mail_reserve" min="0" max="2000" value="<?= (int)mail_pool_reserve() ?>" style="max-width:120px">
        <?= mail_hint('reserve', mail_pool_reserve()) ?>
      </div>
    </div>
    <?php /* Der Hinweis auf die empfohlene Zahl steht direkt an jedem Feld (mail_hint) und
             braucht daneben keinen eigenen Warnkasten. */ ?>
    <p class="small muted" style="margin:.2rem 0 .6rem">Die <strong>Reserve</strong> rühren Rundmails nie an; sie ist die
      Luft für Login-Links und Mitteilungen.</p>
    <div class="btn-row" style="margin-top:.9rem"><button class="btn secondary" type="submit">Einstellungen speichern</button></div>
  </form>
  <?php mail_budget_card(); ?>
  <p class="small muted">Die Basis-URL wird für die Links in den Erinnerungs-Mails verwendet (für den Cron-Aufruf, der keinen Browser-Kontext hat).</p>
  <p class="small muted">Alle Bereiche schreiben in <strong>ein</strong> Mail-Konto und rechnen sich gegenseitig an, was schon rausging – niemand bekommt mehr eine feste Scheibe, die ungenutzt verfällt. Die <strong>Reserve</strong> ist das Einzige, was Rundmails nie anfassen: Login-Links und Mitteilungen gehen immer raus, auch wenn der Topf sonst leer ist.</p>
</div>

<div class="section-title" id="cron"><i class="ti ti-clock"></i> Cron-Jobs einrichten</div>
<div class="card">
  <p class="muted" style="margin-top:0">Die App erledigt einiges zeitgesteuert – Erinnerungen, Einladungen,
    Berichte-Dok, Löschfristen. Dafür muss der Hoster sie regelmäßig aufrufen: im
    <strong>Mittwald-Kundencenter → Cronjobs</strong> je einen Job mit der jeweiligen Adresse anlegen.
    <strong>Ohne diese Läufe passiert nichts davon.</strong></p>
  <p class="small muted">Der <code>key</code> in der Adresse schützt den Aufruf – die Adressen also
    nicht öffentlich teilen. Wer lieber per SSH arbeitet, nimmt die CLI-Zeile darunter; sie braucht
    keinen Schlüssel.</p>

  <?php foreach (cron_jobs() as $cronKey => $job):
      $jobUrl  = cron_job_url((string)$job['datei'], $base);
      $jobPfad = realpath(__DIR__ . '/../' . $job['datei']) ?: ('/pfad/zur/app/' . $job['datei']);
      $letzter = cron_last($cronKey);
      // Wie lange Stille normal ist, sagt der Lauf selbst (cron_jobs()['frist']).
      $frist   = (int)($job['frist'] ?? 26 * 3600);
      $alter   = $letzter ? time() - (int)strtotime($letzter) : null;
  ?>
    <hr class="auto-sep" style="margin:1rem 0 .9rem">
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
      <strong><?= h((string)$job['titel']) ?></strong>
      <span class="pill pill-info"><?= h((string)$job['takt']) ?></span>
      <?php if (!empty($job['pflicht'])): ?><span class="pill pill-warn">nötig</span><?php endif; ?>
    </div>
    <p class="small muted" style="margin:.35rem 0 .5rem">
      <?php if (empty($job['pflicht'])): ?><strong>Nur nötig, wenn <?= h((string)($job['nurWenn'] ?? '')) ?>.</strong> <?php endif; ?>
      <?= h((string)$job['was']) ?></p>
    <p style="margin:0 0 .3rem"><code><?= h($jobUrl) ?></code></p>
    <p class="small muted" style="margin:0 0 .35rem">Empfohlene Zeit: <?= h((string)$job['wann']) ?> ·
      per CLI: <code>php <?= h($jobPfad) ?></code></p>
    <?php if ($letzter): ?>
      <p class="small" style="margin:0;color:<?= $alter !== null && $alter <= $frist ? 'var(--green)' : 'var(--red)' ?>">
        <i class="ti <?= $alter !== null && $alter <= $frist ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i>
        Letzter Lauf: <?= h(date('d.m.Y H:i', (int)strtotime($letzter))) ?> Uhr<?= $alter !== null && $alter > $frist ? ' – läuft offenbar nicht mehr.' : '.' ?>
      </p>
    <?php else: ?>
      <p class="small muted" style="margin:0"><i class="ti ti-circle-dashed"></i> Noch nie gelaufen<?= empty($job['pflicht']) ? ' – in Ordnung, solange ihr den Bereich nicht nutzt.' : ' – bitte einrichten.' ?></p>
    <?php endif; ?>
  <?php endforeach; ?>

  <hr class="auto-sep" style="margin:1rem 0 .9rem">
  <p class="small muted" style="margin:0">Alle Läufe sind <strong>idempotent</strong>: Ein häufigerer Takt
    schadet nie, ein verpasster Lauf wird beim nächsten Mal nachgeholt. Steht nichts an, kostet ein Lauf
    praktisch nichts. Ob sie tatsächlich laufen, prüft auch der Selbsttest unter
    <a href="errorlog.php">Diagnose &amp; Fehler-Log</a>.</p>
</div>

<div class="section-title"><i class="ti ti-key"></i> Technik-Login-Passwort ändern</div>
<div class="card">
  <p class="muted small" style="margin-top:0">Ändert das Passwort des <strong>Technik-/Verwaltungs-Logins</strong> – der Notfall-/Bootstrap-Zugang ohne Mitglieds-Account. <strong>Nicht</strong> euer persönlicher Login-Link.</p>
  <?php $pwLast = admin_pw_check_last(); $pwDue = admin_pw_check_due_on(); ?>
  <p class="small" style="margin:-.3rem 0 .8rem">
    <i class="ti ti-shield-lock" style="color:var(--petrol)"></i>
    <strong>Halbjährliche Kontrolle beim Vorsitz:</strong>
    <?php if ($pwLast === ''): ?>
      <span class="pill pill-warn">offen</span> – der Vorsitz hat das Passwort noch nicht bestätigt (Aufgabe liegt auf seinem Dashboard).
    <?php elseif ($pwDue !== '' && date('Y-m-d') >= $pwDue): ?>
      <span class="pill pill-warn">fällig</span> – zuletzt bestätigt am <?= h(fmt_date($pwLast)) ?>.
    <?php else: ?>
      <span class="pill pill-ok">bestätigt</span> am <?= h(fmt_date($pwLast)) ?> · nächste Kontrolle ab <?= h(fmt_date($pwDue)) ?>.
    <?php endif; ?>
    <br><span class="muted">Nach jeder Passwort-Änderung wird sie sofort wieder fällig – gib das neue Passwort also an den Vorsitz weiter.</span>
  </p>
  <form method="post" action="password.php" style="max-width:380px">
    <?= csrf_field() ?>
    <label for="old">Aktuelles Passwort</label>
    <input type="password" name="old" id="old" required>
    <label for="new">Neues Passwort</label>
    <input type="password" name="new" id="new" required>
    <div class="btn-row" style="margin-top:.9rem"><button class="btn secondary" type="submit">Ändern</button></div>
  </form>
  <?php $recm = current_member(); if ($recm && in_array(current_role(), ['admin', 'vorsitz'], true) && trim((string)$recm['email']) !== ''): ?>
    <hr style="border:none;border-top:1px solid var(--line);margin:1rem 0">
    <p class="muted small" style="margin:0 0 .6rem">Passwort vergessen? Als <strong><?= h(current_role()) ?></strong> kannst du es im Notfall per Magic-Link an deine E-Mail (<?= h($recm['email']) ?>) zurücksetzen – ohne das alte Passwort. Der Link verifiziert dich erneut.</p>
    <form method="post" action="index.php" data-confirm="Dir einen Notfall-Link zum Zurücksetzen des Technik-Passworts an deine E-Mail schicken?" data-confirm-title="Notfall-Reset" data-confirm-ok="Link schicken">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="techpw_recover_request">
      <button class="btn secondary" type="submit"><i class="ti ti-mail-forward"></i> Notfall-Reset per Magic-Link</button>
    </form>
  <?php endif; ?>
</div>

<div class="section-title" style="color:#93292c"><i class="ti ti-alert-triangle" style="color:var(--red)"></i> Daten löschen (Gefahrenzone)</div>
<div class="card">
  <p class="muted small" style="margin-top:0">Einzelne Datenbereiche gezielt löschen oder alles zurücksetzen. Einstellungen und Technik-Login bleiben immer erhalten.</p>
  <a class="btn danger" href="reset.php">Zur Gefahrenzone</a>
</div>
<?php
page_footer();
