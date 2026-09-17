<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // nur Admin/Vorsitz

/** Rubrik der Wegweiser-Beispiel-Einträge – daran erkennt das Aufräumen sie später wieder. */
const WW_DEMO_GRP = 'Beispiele (Diagnose)';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'clear_log') {
        @file_put_contents(ERROR_LOG_FILE, '');
        flash('Fehler-Log geleert.', 'success');
    }
    // Zustell-Tests: prüfen die echte Strecke bis zum eigenen Konto/Gerät –
    // das Einzige, was der Selbsttest prinzipiell nicht beweisen kann.
    if ($action === 'test_push') {
        $meT = current_member();
        if (!$meT) {
            flash('Test-Push braucht ein persönliches Konto (nicht den Technik-Login).', 'error');
        } else {
            // Ziel bewusst NICHT das Dashboard: Dort landet man auch, wenn die Weiterleitung
            // versagt (iOS-Fall) – der Test könnte dann Erfolg und Fehler nicht unterscheiden.
            // Stattdessen die eigene Profil-Pinnwand samt #Anker: kommt man dort an, stimmt der Weg.
            $target = app_url('profil.php?id=' . (int)$meT['id'] . '#pinnwand');
            $n = push_send((int)$meT['id'], 'Test-Push 👋',
                'Zustellung funktioniert. Tipp die Mitteilung an – sie muss auf DEINER Profil-Pinnwand landen, nicht auf dem Dashboard.',
                $target);
            flash($n > 0 ? 'Test-Push an ' . $n . ' Gerät(e) übergeben – müsste jetzt aufploppen. '
                           . 'Beim Antippen muss die eigene Profil-Pinnwand aufgehen; landest du auf dem Dashboard, greift die Weiterleitung noch nicht.'
                         : 'Kein Gerät erreicht – ist Push auf einem deiner Geräte aktiviert (Erinnerungen & Mitteilungen)?', $n > 0 ? 'success' : 'error');
        }
    }
    // ---- Test-Werkzeuge (Achievements & Design) – nur Admin-Rolle/Technik-Login ----
    if ($action === 'props_demo') {
        // Zeigt die „Props sind wieder verfügbar"-Animation beim nächsten Seitenaufbau.
        // Rührt den Monats-Merker NICHT an – die echte Anzeige am Monatsanfang bleibt erhalten.
        $_SESSION['props_demo'] = 1;
        flash('Props-Animation vorgemerkt – sie läuft gleich auf dem Dashboard.', 'success');
        redirect('../dashboard.php');
    }
    if ($action === 'royal_test_on' && royal_test_allowed())  { $_SESSION['royal_test'] = 1; flash('Royal-Testmodus aktiviert 👑 – im Design-Umschalter (Glühbirne) wählbar. Gilt nur für diese Login-Sitzung.', 'success'); }
    if ($action === 'royal_test_off')                          { unset($_SESSION['royal_test']); flash('Royal-Testmodus beendet.', 'success'); }
    if ($action === 'test_unlock_on' && royal_test_allowed())  { $_SESSION['test_unlock_all'] = 1; flash('Alle Belohnungen temporär freigeschaltet – auf der Achievements-Seite im Belohnungs-Locker auswählbar.', 'success'); }
    if ($action === 'test_unlock_off')                         { unset($_SESSION['test_unlock_all']); flash('Test-Freischaltung der Belohnungen beendet.', 'success'); }
    if ($action === 'test_aura_on' && royal_test_allowed()) {
        $stufe = (int)($_POST['stufe'] ?? 5);
        $_SESSION['test_streak_aura'] = in_array($stufe, [4, 5], true) ? $stufe : 5;
        flash(($stufe === 4 ? '50-' : '100-') . 'Tage-Stufe wird vorgeführt – schau auf das Dashboard und die Achievements-Seite.', 'success');
    }
    if ($action === 'test_aura_off')                           { unset($_SESSION['test_streak_aura']); flash('Vorführung der Streak-Stufe beendet.', 'success'); }
    if ($action === 'test_toast' && royal_test_allowed()) {
        $cat = achievements_catalog();
        $mk = function (string $code) use ($cat): array {
            $a = $cat[$code]; $r = $a['reward'] ?? null;
            return [
                'code' => $code, 'title' => $a['title'], 'icon' => $a['icon'],
                'color' => achievement_tier_meta()[$a['tier']]['color'] ?? '',
                'auto' => !empty($a['auto_apply']),
                'reward_type' => $r['type'] ?? '', 'reward_key' => $r['key'] ?? '', 'reward_label' => $r['label'] ?? '',
                'svg' => ($r && ($r['type'] ?? '') === 'deco') ? avatar_deco_svg((string)$r['key']) : '',
                'slot' => ($r && ($r['type'] ?? '') === 'deco') ? avatar_deco_slot((string)$r['key']) : '',
            ];
        };
        switch ($_POST['variant'] ?? 'simple') {
            case 'reward': ach_queue_unlock($mk('streak_7')); break;                                   // mit „Ausprobieren"
            case 'auto':   ach_queue_unlock($mk('spitze')); break;                                     // Royal/Krone-Look
            case 'all':    ach_queue_unlock($mk('first_open')); ach_queue_unlock($mk('streak_7')); ach_queue_unlock($mk('spitze')); break;
            default:       ach_queue_unlock($mk('first_open'));                                         // schlicht
        }
        // kein flash: die Test-Toasts selbst sind das Ergebnis
    }
    // ---- Pat:innenprogramm: großer Testdatensatz (nur Admin-Rolle/Technik-Login) ----
    if ($action === 'pat_seed' && royal_test_allowed()) {
        require_once __DIR__ . '/../pat-db.php';
        $res = pat_test_seed((int)($_POST['round_id'] ?? 0), (int)($_POST['erstis'] ?? 300), (int)($_POST['pates'] ?? 55));
        if (!$res['ok']) {
            flash($res['error'], 'error');
        } else {
            flash($res['erstis'] . ' Erstsemester und ' . $res['pates'] . ' Pat:innen als Testdaten angelegt. '
                . 'Enthaltene Grenzfälle: ' . implode(', ', array_unique($res['notes'])) . '. '
                . 'Alle Adressen enden auf ' . PAT_TEST_DOMAIN . ' – dorthin kann keine Mail zugestellt werden.', 'success');
        }
    }
    // ---- was.läuft: vollständiger Testdatensatz (nur Admin-Rolle/Technik-Login) ----
    if ($action === 'wl_seed' && royal_test_allowed()) {
        require_once __DIR__ . '/../wl-db.php';
        $r = wl_demo_seed();
        $bild = wl_image_engine() === ''
            ? ' Bilder konnten NICHT erzeugt werden – auf diesem Server fehlt PHP die Bildverarbeitung.'
            : ' ' . (int)$r['bilder_ok'] . ' Bilder liefen dabei durch die echte Verarbeitung ('
              . wl_image_engine_label() . ') – das beweist zugleich, dass Uploads hier funktionieren.';
        flash($r['msg'] . $bild . ' Die Beiträge stehen ab sofort ÖFFENTLICH auf der Seite – '
            . 'zum Ausprobieren gedacht, hier jederzeit wieder entfernbar.', 'success');
    }
    if ($action === 'wl_seed_purge' && royal_test_allowed()) {
        require_once __DIR__ . '/../wl-db.php';
        $w = wl_demo_purge();
        flash('Testdaten entfernt: ' . $w['items'] . ' Beiträge, ' . $w['orgs'] . ' Veranstalter, '
            . $w['images'] . ' Bilder, ' . $w['banners'] . ' Banner. Echte Inhalte blieben unberührt.', 'success');
    }
    if ($action === 'pat_seed_purge' && royal_test_allowed()) {
        require_once __DIR__ . '/../pat-db.php';
        $n = pat_test_purge((int)($_POST['round_id'] ?? 0));
        flash($n . ' Testanmeldungen entfernt (echte Anmeldungen bleiben unberührt).', 'success');
    }
    if ($action === 'test_streak' && royal_test_allowed()) {
        // Streak-Flammen-Animation vorführen: gewünschte Stufe (Tage) einreihen, Redirect zündet sie
        $n = max(1, (int)($_POST['days'] ?? 0));
        streak_queue_gain($n);
    }
    /* ---- Zum Ausprobieren: Feier-Momente, Telefon-Dialog, Wegweiser-Suche ----
       Diese drei hängen im Alltag an Ereignissen, die es gerade nicht gibt (vorlesungsfreie Zeit,
       keine offenen Abstimmungen). Hier lassen sie sich unabhängig davon auslösen. */
    if ($action === 'test_puste' && royal_test_allowed()) {
        $moment = (string)($_POST['moment'] ?? '');
        if (in_array($moment, puste_momente(), true)) {
            puste_merken($moment);
            redirect('errorlog.php#vorschau');
        }
        flash('Unbekannter Moment.', 'error');
    }
    if ($action === 'test_phone_on' && royal_test_allowed()) {
        $meT = current_member();
        if (!$meT) {
            flash('Dafür braucht es ein persönliches Konto – mit dem Technik-Login gibt es kein Profil.', 'error');
        } else {
            member_set_phone((int)$meT['id'], '0151 00000000', 'Test-Eintrag aus der Diagnose: bitte nur zwischen 9 und 18 Uhr anrufen, kein WhatsApp.')
                ? flash('Test-Nummer in DEIN Profil eingetragen. Unten steht jetzt ein echter Knopf – und dein eigenes Profil zeigt das Feld.', 'success')
                : flash('Die Test-Nummer wurde abgelehnt – das sollte nicht passieren.', 'error');
        }
    }
    if ($action === 'test_phone_off' && royal_test_allowed()) {
        $meT = current_member();
        if ($meT) { member_set_phone((int)$meT['id'], '', ''); flash('Test-Nummer wieder aus deinem Profil entfernt.', 'success'); }
    }
    if ($action === 'test_ww_demo' && royal_test_allowed()) {
        $demo = [
            ['Nextcloud',        'https://cloud.example.org',  '', '', 'Unsere Ablage für alles, was nicht in Teams liegt.'],
            ['OLAT',             'https://olat.vcrp.de',       '', '', 'Protokolle und Berichte fürs Archiv.'],
            ['Studierendenwerk', 'https://www.beispiel.de', 'test@example.org', '0000 000000', 'BAföG, Wohnen, Mensa.'],
            ['Frau Beispiel',    '',                           'test@example.org', '06341 000001', 'Prüfungsamt – Fragen zu Fristen und Anerkennungen.'],
        ];
        $st = db()->prepare('INSERT INTO wegweiser(title, url, email, phone, note, grp, icon, referat, sort) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach ($demo as $i => $d) $st->execute([$d[0], $d[1], $d[2], $d[3], $d[4], WW_DEMO_GRP, '', '', $i]);
        flash(count($demo) . ' Beispiel-Einträge im Wegweiser angelegt (Rubrik „' . WW_DEMO_GRP . '"). Jetzt lässt sich die Suche ausprobieren – tipp Unsinn ein, dann kippt die Lupe.', 'success');
    }
    if ($action === 'test_ww_purge' && royal_test_allowed()) {
        $st = db()->prepare('DELETE FROM wegweiser WHERE grp = ?');
        $st->execute([WW_DEMO_GRP]);
        flash($st->rowCount() . ' Beispiel-Einträge entfernt (echte Einträge bleiben unberührt).', 'success');
    }
    if ($action === 'test_mail') {
        $meT = current_member();
        $to = $meT ? member_mail($meT) : '';
        if ($to === '') {
            flash('Testmail braucht ein persönliches Konto mit E-Mail-Adresse.', 'error');
        } else {
            $ok = send_mail($to, 'Testmail aus der AStA-App', "Hallo,\n\nwenn du das liest, funktioniert der Mailversand der App.\n\n(ausgelöst über Verwaltung → Diagnose)", false);
            flash($ok ? 'Testmail an ' . $to . ' übergeben – prüfe dein Postfach (ggf. Spam).'
                      : 'Mailversand fehlgeschlagen – Mail-Konfiguration prüfen.', $ok ? 'success' : 'error');
        }
    }
    redirect('errorlog.php');
}

/** Selbsttest-Assertion: Bedingung muss zutreffen, sonst fällt der Check mit $msg durch. */
function st_expect(bool $cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

/**
 * Kein öffentlicher Bereich darf die lib.php der App einbinden. Prüft ALLE .php eines Ordners.
 *
 * Bewusst über glob() und NICHT über eine aufgezählte Dateiliste: Eine Liste prüft nur, was
 * jemand hineingeschrieben hat. Eine neue Datei fehlt darin, und eine gelöschte liest sich als
 * leerer Inhalt – also als bestanden. Die Zusicherung meldete dann grün, ohne etwas zu prüfen.
 *
 * Das Muster erfasst jede Einbindungsform (auch dirname(__DIR__)), trifft aber nicht die
 * bereichseigene Bibliothek: In „/pat-lib.php" steht kein „/lib.php".
 *
 * @return int Zahl der geprüften Dateien – der Aufrufer stellt sicher, dass es nicht 0 sind.
 */
function st_no_app_lib(string $ordner): int
{
    $dateien = glob(__DIR__ . '/../' . $ordner . '/*.php') ?: [];
    foreach ($dateien as $pfad) {
        $src = (string)@file_get_contents($pfad);
        st_expect(!preg_match('~(?:require|include)(?:_once)?[^;]{0,120}/lib\.php~', $src),
            $ordner . '/' . basename($pfad) . ' bindet die lib.php der App ein');
    }
    return count($dateien);
}

// ---- Selbsttest: Diagnose (Umgebung/Cron), Assertions und Smoke der zentralen Pfade ----
$checks = []; // [Label, Status (true|false|'skip'), Notiz bzw. Fehler, Millisekunden]
$run = function (string $label, callable $fn) use (&$checks) {
    $t0 = microtime(true);
    try {
        $note = $fn(); // Checks dürfen eine kurze Info-Notiz zurückgeben (z. B. Dry-Run-Zahlen)
        $note = is_string($note) ? $note : '';
        // Render-Smoke-Checks geben ihr komplettes HTML zurück – nicht als Detailzeile ausschütten (sonst zugemüllt).
        if ($note !== '' && (strlen($note) > 200 || strpbrk($note, '<>') !== false)) $note = '';
        $checks[] = [$label, true, $note, (int)round((microtime(true) - $t0) * 1000)];
    } catch (\Throwable $e) {
        $checks[] = [$label, false, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', (int)round((microtime(true) - $t0) * 1000)];
    }
};
$skip = function (string $label, string $why) use (&$checks) { $checks[] = [$label, 'skip', $why, 0]; };
$doTest = isset($_GET['selftest']);
if ($doTest) {
    // Frühwarnungen zuerst: läuft der Cron überhaupt noch? Ist die DB gesund?
    $run('Cron-Herzschlag (Erinnerungen/Automationen)', function () {
        $stale = cron_stale();
        if ($stale) throw new \RuntimeException(implode(' · ', $stale));
        $note = 'Erinnerungen: ' . (cron_last('reminders') ?: '–');
        if (cron_last('automations')) $note .= ' · Automationen: ' . cron_last('automations');
        return 'letzte Läufe – ' . $note;
    });
    $run('Legislaturperioden (gelten über ihren Zeitraum)', function () {
        // Eine Periode ohne Beginn sammelt nichts ein – ihre Sitzungen verlören still die Nummer.
        $ohne = db()->query("SELECT COUNT(*) FROM legislatures WHERE start_date = '' OR start_date IS NULL")->fetchColumn();
        st_expect((int)$ohne === 0, (int)$ohne . ' Legislaturperiode(n) ohne Beginn – deren Sitzungen bekommen keine Nummer');
        // Zwei Perioden am selben Tag wären nicht entscheidbar.
        $doppelt = (int)db()->query("SELECT COUNT(*) FROM (SELECT start_date FROM legislatures
            WHERE start_date <> '' GROUP BY start_date HAVING COUNT(*) > 1)")->fetchColumn();
        st_expect($doppelt === 0, $doppelt . ' Periode(n) beginnen am selben Tag – die Zuordnung wäre nicht eindeutig');

        st_expect(function_exists('legislature_for') && function_exists('legislature_range')
            && function_exists('meeting_ends_at'), 'Ableitungs-Funktionen müssen vorhanden sein');
        st_expect(legislature_for('') === null, 'ohne Datum darf keine Periode herauskommen');

        // Kein Zwischenspeicher: Ein Cache hielte start_number fest, das beim Anlegen einer Serie
        // im selben Aufruf geändert wird – die Sitzungen bekämen dann die alte Nummerierung.
        $src = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect((bool)preg_match('~function legislature_for\(.*?\n\}~s', $src, $mm) && !str_contains($mm[0], 'static $'),
            'legislature_for() darf nicht zwischenspeichern – sonst rechnet „Serie anlegen" mit altem Versatz');

        // Das Sitzungsende wird abgeleitet – und muss das offene Ende ergeben (Anzeige „ab 18:00").
        st_expect(meeting_ends_at(['starts_at' => '2026-09-15 18:00']) === '2026-09-15 23:59:59', 'Sitzungsende muss das Tagesende sein');
        st_expect(is_open_end(meeting_ends_at(['starts_at' => '2026-09-15 18:00'])), 'abgeleitetes Ende muss als offenes Ende gelten');
        // Und die Formulare dürfen die Spalte nicht mehr füllen.
        $adm = (string)@file_get_contents(__DIR__ . '/meetings.php');
        st_expect(!str_contains($adm, 'ends_at=?') && !str_contains($adm, 'ends_at,'),
            'admin/meetings.php schreibt wieder ein ends_at – es wird abgeleitet, nicht gespeichert');
        st_expect(!str_contains($adm, "name=\"legislature_id\""),
            'admin/meetings.php hat wieder ein Legislatur-Dropdown – die Periode folgt aus dem Datum');

        // Seit die Nummerierung am Beginn hängt, muss er (a) korrigierbar und (b) eindeutig sein.
        st_expect(str_contains($adm, "value=\"leg_save\"") && str_contains($adm, 'name="start_date"'),
            'der Beginn einer Periode muss in der Verwaltung änderbar bleiben – sonst heilt ein Vertipper nur durch Löschen');
        st_expect(function_exists('legislature_date_taken') && substr_count($adm, 'legislature_date_taken') >= 2,
            'beide Perioden-Formulare müssen doppelte Beginn-Tage abweisen');
        // Gegenprobe an echten Daten: Der vorhandene Beginn gilt als belegt, sich selbst gegenüber nicht.
        $einL = db()->query("SELECT * FROM legislatures WHERE start_date <> '' LIMIT 1")->fetch();
        if ($einL) {
            st_expect(legislature_date_taken((string)$einL['start_date']), 'ein vergebener Beginn muss als belegt gelten');
            st_expect(!legislature_date_taken((string)$einL['start_date'], (int)$einL['id']),
                'die eigene Periode darf sich nicht selbst blockieren');
        }
        // Das Löschen einer Periode nimmt den Sitzungen NICHT die Nummer – sie zählen davor weiter.
        st_expect(!preg_match('~leg_delete.*?legislature_id = NULL~s', $adm),
            'leg_delete setzt wieder eine Zuordnungs-Spalte zurück – die gibt es nicht mehr');

        return (int)db()->query('SELECT COUNT(*) FROM legislatures')->fetchColumn() . ' Perioden';
    });

    $run('Verlinkung: Orte der App (Ziele, Rechte, Pfade)', function () {
        $orte = app_places();
        st_expect(count($orte) >= 20, 'app_places() wirkt unvollständig (' . count($orte) . ' Orte)');
        foreach ($orte as $key => $ort) {
            foreach (['url', 'label', 'icon', 'darf'] as $feld) {
                st_expect(isset($ort[$feld]) && $ort[$feld] !== '', 'app_places()[' . $key . ']: „' . $feld . '" fehlt');
            }
            // Ein Link, dessen Ziel es nicht gibt, ist schlimmer als gar keiner.
            st_expect(is_file(__DIR__ . '/../' . $ort['url']), 'app_places()[' . $key . ']: Datei ' . $ort['url'] . ' gibt es nicht');
            st_expect(is_callable($ort['darf']), 'app_places()[' . $key . ']: „darf" muss eine Prüfung sein');
        }
        // Pfade: aus admin/ heraus muss derselbe Aufruf ein anderes Präfix ergeben.
        $vorher = $GLOBALS['ASTA_BASE'] ?? '';
        try {
            $GLOBALS['ASTA_BASE'] = '';
            $wurzel = app_place_url('dashboard');
            $GLOBALS['ASTA_BASE'] = '../';
            $unter = app_place_url('dashboard');
        } finally { $GLOBALS['ASTA_BASE'] = $vorher; }
        st_expect($wurzel === 'dashboard.php' && $unter === '../dashboard.php',
            'app_place_url() beachtet den Unterordner nicht (' . $wurzel . ' / ' . $unter . ')');
        st_expect(str_contains(app_place_url('a_sitzungen', 'einladungen'), '#einladungen'), 'Anker fehlt in app_place_url()');

        // Ein unbekannter Schlüssel darf nichts zerstören, sondern nur den Text zeigen.
        // Das ist ein ABSICHTLICHER Fehlgriff: app_place() protokolliert ihn, damit Tippfehler im
        // Alltag auffallen. Für die Dauer der Probe schalten wir das Protokoll stumm – sonst stünde
        // nach jedem Selbsttest ein erfundener Fehler im echten Fehler-Log.
        $logVorher = @filesize(ERROR_LOG_FILE) ?: 0;
        $GLOBALS['ASTA_PROBE'] = true;
        try {
            $ersatz = app_place('gibt-es-nicht', 'Ersatztext');
        } finally {
            $GLOBALS['ASTA_PROBE'] = false;   // auch wenn die Probe unterwegs stolpert
        }
        clearstatcache(true, ERROR_LOG_FILE);
        st_expect($ersatz === 'Ersatztext', 'unbekannter Ort muss auf den Text zurückfallen');
        st_expect((@filesize(ERROR_LOG_FILE) ?: 0) === $logVorher,
            'die Probe hat ins Fehler-Log geschrieben – dann füllt jeder Selbsttest-Lauf das Log mit erfundenen Fehlern');
        st_expect(empty($GLOBALS['ASTA_PROBE']), 'der Proben-Merker steht noch – echte Fehler würden verschluckt');

        // Entitäts-Links: mit ID ein Link, ohne ID nur Text – sonst klickt man ins Leere.
        $probe = ['id' => 0, 'title' => 'Ohne ID', 'kind' => 'sonstige', 'starts_at' => '2026-05-06 20:00'];
        st_expect(!str_contains(meeting_link($probe), '<a '), 'Sitzung ohne ID darf kein Link sein');
        st_expect(!str_contains(event_link($probe), '<a '), 'Event ohne ID darf kein Link sein');
        st_expect(!str_contains(umlauf_link($probe), '<a '), 'Abstimmung ohne ID darf kein Link sein');
        $probe['id'] = 5;
        st_expect(str_contains(meeting_link($probe, '', 'protokoll'), 'meeting.php?id=5#protokoll'), 'Sitzungs-Link falsch aufgebaut');
        st_expect(str_contains(umlauf_link($probe, 'poll'), 'umlauf.php?poll=5'), 'Abstimmungs-Link falsch aufgebaut');
        st_expect(str_contains(event_link($probe), 'class="entity-link"'), 'Entitäts-Links brauchen die gemeinsame Klasse');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../assets/style.css'), '.entity-link'),
            'Die Klasse .entity-link fehlt im Stylesheet');

        // Wegbeschreibungen im Fließtext sollen Links sein, keine Klickanleitung.
        $wege = [];
        foreach (glob(__DIR__ . '/../*.php') ?: [] as $pfad) {
            if (str_starts_with(basename($pfad), 'cron_')) continue;
            foreach (file($pfad) ?: [] as $nr => $zeile) {
                if (!str_contains($zeile, 'Verwaltung →')) continue;
                $t = ltrim($zeile);
                if ($t === '' || $t[0] === '*' || str_starts_with($t, '//')) continue;   // Kommentar
                if (str_contains($zeile, 'app_place(')) continue;                        // schon umgestellt
                if (!str_contains($zeile, '<') && !str_contains($zeile, 'class=')) continue; // Mailtext/Meldung
                $wege[] = basename($pfad) . ':' . ($nr + 1);
            }
        }
        st_expect(!$wege, 'Wegbeschreibung statt Link in: ' . implode(', ', $wege));

        return count($orte) . ' Orte verlinkbar';
    });

    $run('Cron-Anleitung vollständig (jeder Lauf ist beschrieben)', function () {
        // Vergleicht die Registry mit dem, was wirklich im Ordner liegt: Zu jeder cron_*.php muss
        // ein Eintrag existieren, sonst fehlt der Lauf in der Anleitung.
        $jobs = cron_jobs();
        $dateien = array_column($jobs, 'datei');
        foreach (glob(__DIR__ . '/../cron_*.php') ?: [] as $pfad) {
            $name = basename($pfad);
            st_expect(in_array($name, $dateien, true),
                $name . ' fehlt in cron_jobs() – dann steht der Lauf auch nicht in der Anleitung');
        }
        foreach ($jobs as $key => $job) {
            st_expect(is_file(__DIR__ . '/../' . $job['datei']), 'cron_jobs(): Datei ' . $job['datei'] . ' gibt es nicht');
            st_expect((int)($job['frist'] ?? 0) >= 3600,
                'cron_jobs()[' . $key . ']: „frist" fehlt oder ist unrealistisch kurz');
            foreach (['titel', 'takt', 'wann', 'was'] as $feld) {
                st_expect(trim((string)($job[$feld] ?? '')) !== '', 'cron_jobs()[' . $key . ']: „' . $feld . '" fehlt');
            }
            st_expect(!empty($job['pflicht']) || trim((string)($job['nurWenn'] ?? '')) !== '',
                'cron_jobs()[' . $key . ']: optionale Läufe müssen sagen, wann man sie braucht');
            // Der Herzschlag-Schlüssel muss zum cron_stamp() in der Datei passen – sonst zeigt die
            // Verwaltung ewig „noch nie gelaufen", obwohl der Lauf längst läuft.
            $src = (string)@file_get_contents(__DIR__ . '/../' . $job['datei']);
            st_expect(str_contains($src, "cron_stamp('" . $key . "')"),
                $job['datei'] . ' stempelt nicht cron_stamp(\'' . $key . '\') – der Status bliebe leer');
        }
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/index.php'), 'cron_jobs()'),
            'Die Verwaltung baut ihre Cron-Anleitung nicht mehr aus der Registry');
        // Auch die Cron-Tabelle im README muss jeden Lauf nennen – cron_waslaeuft.php fehlte
        // dort (aufgefallen), obwohl Registry und Verwaltung längst stimmten.
        $qReadmeCron = (string)@file_get_contents(__DIR__ . '/../README.md');
        foreach ($jobs as $job) {
            st_expect(str_contains($qReadmeCron, '`' . $job['datei'] . '`'),
                $job['datei'] . ' fehlt in der Cron-Tabelle des README');
        }
        st_expect(str_contains(cron_job_url('cron_reminders.php', 'https://x.test'), 'https://x.test/cron_reminders.php?key='),
            'cron_job_url() baut die Aufruf-Adresse falsch');
        return count($jobs) . ' Läufe beschrieben';
    });

    $run('Öffentliche Bereiche: keiner bindet die lib.php der App ein', function () {
        // Die Trennung ist die Grundlage aller öffentlichen Bereiche: Sie stehen wildfremden
        // Menschen offen und dürfen den Mitgliederdaten gar nicht erst nahekommen. Eine
        // Einbindung wäre nicht nur ein Leck – sie startet auch den Fehler-Handler der App und
        // koppelt die Seite an eine Datenbank, die dort nichts zu suchen hat.
        $summe = 0;
        foreach (['pat', 'umfrage', 'anmeldung', 'termin', 'veranstaltungen'] as $bereich) {
            $n = st_no_app_lib($bereich);
            st_expect($n > 0, 'Im Ordner ' . $bereich . '/ liegt keine einzige PHP-Datei – heißt der Ordner noch so?');
            $summe += $n;
        }
        return $summe . ' Dateien in 5 Bereichen geprüft';
    });

    $run('Weitergabe: Trägerangaben sind Einstellungen, keine Zeilen im Programm', function () {
        // Zwei Dinge müssen gleichzeitig gelten: Ein anderer AStA soll die App übernehmen
        // können, ohne eine Datei anzufassen – und für DIESE Installation darf sich dabei
        // nichts ändern. Beides geht nur so: Jede Trägerangabe ist eine Einstellung, deren
        // VORGABE genau der Wert ist, der vorher fest im Programm stand. Dieser Test hält die
        // Vorgaben wörtlich fest; wer eine ändert, verschiebt den Auftritt und wird es hier
        // erfahren, statt es irgendwann auf einer Seite zu bemerken.
        require_once __DIR__ . '/../wl-db.php';
        require_once __DIR__ . '/../termin-db.php';
        require_once __DIR__ . '/../pat-db.php';
        require_once __DIR__ . '/../umfrage-db.php';
        require_once __DIR__ . '/../extern-db.php';
        // Der Träger darf ÜBERALL stehen, nur nicht im Programm. Geprüft wird deshalb
        // andersherum als früher: nicht „steht der erwartete Wert da", sondern „taucht das,
        // was diese Installation eingetragen hat, irgendwo im Quelltext auf". Damit gilt der
        // Test für jede Installation und nicht nur für die, für die er geschrieben wurde.
        $kern = ['lib.php', 'wl-db.php', 'termin-db.php', 'pat-db.php', 'umfrage-db.php',
                 'extern-db.php', 'admin/traeger.php', 'brand-core.php'];
        $eigene = array_values(array_filter([
            (string)setting_get('org_name', ''),
            (string)setting_get('org_name_kurz', ''),
            (string)parse_url((string)setting_get('org_url', ''), PHP_URL_HOST),
        ], fn ($v) => mb_strlen(trim($v)) >= 4));
        $gefunden = [];
        foreach ($kern as $datei) {
            $q = (string)@file_get_contents(__DIR__ . '/../' . $datei);
            foreach ($eigene as $wert) {
                if (str_contains($q, $wert)) $gefunden[] = $datei . ': „' . $wert . '"';
            }
        }
        st_expect($gefunden === [],
            'der Träger steht im Quelltext statt in den Einstellungen – ' . implode(', ', array_slice($gefunden, 0, 3)));


        // Die Kalender-Kennung ist die heikelste davon: Ändert sie sich, legen alle
        // abonnierten Kalender jeden Termin ein ZWEITES Mal an.
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/ics.php'), 'wl_uid_domain()'),
            'ics.php baut die UID nicht mehr aus wl_uid_domain() – Abos würden doppelte Termine bekommen');

        // Namens-Schmuck: Der Name steht in den Einstellungen, die Beschriftung wird daraus
        // gebildet. Der Genitiv darf dabei nicht verrutschen.
        st_expect(deco_tasse_label('Lotta Schwarz') === 'Lottas Tasse ☕',
            'deco_tasse_label: aus „Lotta Schwarz" muss „Lottas Tasse ☕" werden');
        st_expect(deco_tasse_label('Lars Meier') === 'Lars\' Tasse ☕',
            'deco_tasse_label: ein Name auf -s bekommt nur den Apostroph');

        // Mitgelieferte Fremdteile dürfen nur weitergegeben werden, wenn ihr Lizenztext dabei
        // ist. Verschwindet eine dieser Dateien, verstößt jede Kopie der App dagegen.
        foreach (['LICENSE', 'THIRD-PARTY.md', 'licenses/flatpickr-MIT.txt', 'licenses/tabler-icons-MIT.txt',
                  'licenses/qrcodejs-MIT.txt', 'licenses/outfit-OFL-1.1.txt'] as $datei) {
            st_expect(is_file(__DIR__ . '/../' . $datei) && filesize(__DIR__ . '/../' . $datei) > 200,
                $datei . ' fehlt oder ist leer – ohne sie darf die App nicht weitergegeben werden');
        }

        // Sind die Angaben auch WIRKLICH gespeichert, oder greift überall nur die Vorgabe aus
        // dem Programm? Das ist der Unterschied, auf den es bei einer Weitergabe ankommt: Erst
        // wenn sie in den Datenbanken stehen, dürfen die Vorgaben im Quelltext geleert werden.
        // Geprüft mit einem Wert, den niemand eintragen kann – kommt er zurück, steht dort nichts.
        $keiner  = "\x00nichts";
        $bereiche = [
            'App'          => setting_get('org_name', $keiner),
            'was.läuft'    => wl_setting('traeger_name', $keiner),
            'Terminplaner' => tplan_setting_get('traeger_name', $keiner),
            'Pat:innen'    => pat_setting_get('traeger_name', $keiner),
            'Umfragen'     => umfrage_setting_get('traeger_name', $keiner),
            'Anmeldung'    => extern_setting_get('traeger_name', $keiner),
        ];
        $offen = array_keys(array_filter($bereiche, fn ($v) => $v === $keiner));
        $da    = count($bereiche) - count($offen);
        // Kurz halten: Der Läufer wirft Notizen über 200 Zeichen weg (siehe $run oben).
        $stand = $offen === []
            ? 'in allen ' . count($bereiche) . ' Bereichen gespeichert'
            : 'erst in ' . $da . ' von ' . count($bereiche) . ' Bereichen gespeichert';

        // Die Redeliste liegt als .html auf der Platte und kann keine Einstellung lesen. Ihr
        // Feedback-Knopf geht deshalb über eine Weiterleitung – fehlt die, führt er ins Leere.
        // Der Schmuck im Kopfband des Pat:innenprogramms: Dort lagen die Buchstabenformen aus
        // dem Logo der Hochschule. Sie gehören dieser Installation, nicht dem Programm – und
        // dürfen bei einer Weitergabe nicht mitgehen. pat_hero_deko_uebernehmen() holt sie
        // einmalig in die Einstellungen; ERST danach darf der Block aus pat.css verschwinden.
        require_once __DIR__ . '/../pat-db.php';
        pat_hero_deko_uebernehmen();
        $patCss = (string)@file_get_contents(__DIR__ . '/../assets/pat.css');
        $dekoDa = pat_hero_deko() !== '';
        $dekoImCss = str_contains($patCss, 'svg+xml');
        if ($dekoImCss) {
            st_expect($dekoDa,
                'der Schmuck des Kopfbands steht noch in assets/pat.css und ist NICHT in den Einstellungen – '
                . 'erst übernehmen, dann darf er aus dem Stylesheet');
        }
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../pat/pat-lib.php'), 'pat_hero_deko()'),
            'pat-lib.php setzt den Schmuck nicht mehr in den Seitenkopf – das Band bliebe schlicht');

        // Promo-Vorlagen dürfen kein Logo einbetten. Vorher lagen dort 78 KB Träger-Logo als
        // base64 – unsichtbar für jede Suche nach Namen und trotzdem mit ausgeliefert.
        foreach (glob(__DIR__ . '/../assets/wl-promo/*.html') ?: [] as $pv) {
            $qPv = (string)@file_get_contents($pv);
            st_expect(!str_contains($qPv, 'base64,'),
                'assets/wl-promo/' . basename($pv) . ' bettet wieder ein Bild ein – die Marke gehört in data/branding/');
        }
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen.php'), 'function wl_promo_inhalt'),
            'die Promo-Vorlagen werden nicht mehr über wl_promo_inhalt() ausgeliefert – die Marke käme nicht mehr hinein');

        // Liegt hier ein .git-Verzeichnis? Dann ist es beim Hochladen mitgekommen – und die
        // komplette Projektgeschichte ist über das Web abrufbar, samt allem, was je in einem
        // Commit stand. Der rsync muss '.git/' ausschließen.
        st_expect(!is_dir(__DIR__ . '/../.git'),
            'auf dem Server liegt ein .git-Verzeichnis – die Projektgeschichte ist damit öffentlich abrufbar. '
            . 'Löschen und im rsync --exclude \'.git/\' ergänzen');

        st_expect(is_file(__DIR__ . '/../redeliste-kontakt.php'),
            'redeliste-kontakt.php fehlt – der Feedback-Knopf der Redeliste ginge ins Leere');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../redeliste.html'), 'redeliste-kontakt.php'),
            'redeliste.html verweist nicht mehr auf redeliste-kontakt.php');

        $dekoStand = $dekoImCss
            ? ($dekoDa ? ' · Kopfband-Schmuck gesichert, darf aus pat.css raus' : ' · Kopfband-Schmuck NOCH NICHT gesichert')
            : ' · pat.css frei von fremden Marken';

        return count($kern) . ' Kern-Dateien frei von Trägerangaben, Lizenzen vollständig · ' . $stand . $dekoStand;
    });

    $run('Weitergabe: Marke liegt in data/branding, nicht im Programm', function () {
        // Logo und App-Symbol gehören dem Träger, nicht der Software. Sie liegen deshalb in
        // data/branding/ – dort überstehen sie jedes Einspielen und gehen bei einer Weitergabe
        // der App nicht mit. Solange dort nichts liegt, greift der Platzhalter aus assets/.
        foreach (brand_namen() as $bn) {
            st_expect(brand_pfad($bn) !== null,
                'Marken-Bild „' . $bn . '" fehlt – weder eigenes in data/branding/ noch Platzhalter in assets/');
        }

        // Der Name kommt aus der URL: Was nicht auf der Liste steht, wird nicht ausgeliefert.
        st_expect(brand_pfad('../lib') === null && brand_pfad('../../etc/passwd') === null,
            'brand_pfad lässt sich aus dem Verzeichnis herausführen – brand.php würde fremde Dateien ausliefern');

        // Das Kopf-Logo der App ist ein ANDERER Zuschnitt als das der öffentlichen Bereiche.
        // Wer sie vertauscht, verschiebt das Logo in der Titelleiste sichtbar.
        $qLibM = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect(str_contains($qLibM, "brand_data_uri('logo-app')"),
            'die Titelleiste holt ihr Logo nicht mehr aus brand_data_uri(\'logo-app\') – es käme als eigene Anfrage und würde sichtbar nachgereicht');
        st_expect(brand_pfad('logo-app') !== brand_pfad('logo-header'),
            'logo-app und logo-header zeigen auf dieselbe Datei – einer der beiden Zuschnitte ist verlorengegangen');

        // Hat DIESE Installation ihre Marke schon übernommen? migrate_schema() kopiert die
        // mitgelieferten Bilder einmalig nach data/branding/ und setzt dann diesen Merker.
        // Ist er gesetzt, MÜSSEN die Dateien auch dort liegen – sonst würde ein Austausch der
        // Platzhalter in assets/ das Logo dieser Installation stillschweigend mitnehmen.
        $eigene = array_values(array_filter(brand_namen(), 'brand_hat_eigenes'));
        $uebernommen = (string)setting_get('branding_uebernommen', '') === '1';
        if ($uebernommen) {
            $fehlen = array_values(array_diff(brand_namen(), $eigene));
            st_expect($fehlen === [],
                'in data/branding/ fehlen: ' . implode(', ', $fehlen)
                . ' – diese Bilder kämen noch aus assets/ und gingen bei einem Austausch verloren');
        }

        return $uebernommen
            ? 'eigene Marke gesichert – ' . count($eigene) . ' von ' . count(brand_namen()) . ' Bildern liegen in data/branding/'
            : count($eigene) . ' eigene Bilder, sonst Platzhalter (diese Installation hat keine eigene Marke übernommen)';
    });

    $run('Weitergabe: Einladungsvorlage liegt in den Einstellungen', function () {
        // Die Vorlage nennt einen Kurs-Zugang samt Passwort und schließt mit einer
        // persönlichen Signatur. Das ist Inhalt des Sekretariats und darf nicht in einem
        // Programm stehen, das andere übernehmen sollen. migrate_schema() holt sie einmalig
        // in die Einstellungen; ERST danach darf der Text aus dem Quelltext verschwinden.
        // Solange dieser Test grün ist, ist die Vorlage dieser Installation gesichert.
        $gespeichert = (string)setting_get('invite_template', '');
        st_expect(trim($gespeichert) !== '',
            'invite_template steht nicht in den Einstellungen – die Migration ist noch nicht gelaufen');
        st_expect(trim(invite_template()) === trim($gespeichert),
            'invite_template() liest nicht die gespeicherte Vorlage');
        // Und sie darf nie zurückkommen: Weder das Kurs-Passwort noch die Signatur einer
        // Person gehören in ein Programm, das andere übernehmen. Wer die Vorlage künftig
        // ändern will, tut das in der Verwaltung – dort landet es in der Datenbank.
        // Gesucht wird über FINGERABDRÜCKE (SHA-256), nicht über den Klartext: Ein Wächter, der
        // das Passwort als Suchwort enthält, veröffentlicht es selbst. Geprüft werden einzelne
        // Wörter und Wortpaare aus lib.php; Längen außerhalb 3–40 fallen weg (Bild-Blobs).
        $qLib = (string)@file_get_contents(__DIR__ . '/../lib.php');
        $verboten = [
            '60a627c51739071ae6d4ed09494708f5775cde4a506eea0f9ecb925228706ef1' => 'das OLAT-Kurspasswort',
            'f7cc151cacebc4b06c65ae8aebbe3361ec6b45fd2c6860c66c9a58dff84c77ba' => 'eine persönliche Signatur',
        ];
        preg_match_all('~[\p{L}\p{N}!]+~u', $qLib, $wt);
        $woerter = array_values(array_filter($wt[0], fn ($w) => mb_strlen($w) >= 3 && mb_strlen($w) <= 40));
        $gefundenV = [];
        foreach ($woerter as $iw => $w) {
            foreach ([$w, isset($woerter[$iw + 1]) ? $w . ' ' . $woerter[$iw + 1] : ''] as $kand) {
                if ($kand !== '' && isset($verboten[hash('sha256', $kand)])) $gefundenV[$verboten[hash('sha256', $kand)]] = true;
            }
        }
        st_expect($gefundenV === [],
            implode(' und ', array_keys($gefundenV)) . (count($gefundenV) > 1 ? ' stehen' : ' steht')
            . ' wieder im Quelltext – das gehört in die Einladungsvorlage in der Verwaltung');
        st_expect(str_contains(default_invite_template(), '{{TAGESORDNUNG}}'),
            'die Standard-Vorlage hat ihre Platzhalter verloren – eine Neuinstallation bekäme eine unbrauchbare Einladung');

        return 'gesichert, Quelltext ist frei von Zugangsdaten und Signatur';
    });

    $run('Umfragen: Vorschau-Link, Bilder, englische Fassung', function () {
        // --- Geheimer Vorschau-Link ---
        // Der Entwurf ist sonst NIRGENDS zu sehen; Rollen kennt der öffentliche Bereich nicht,
        // der Schlüssel ist also der ganze Ausweis – und muss zeitkonstant verglichen werden.
        st_expect(function_exists('umfrage_preview_token') && function_exists('umfrage_preview_url'),
            'die Vorschau-Funktionen der Umfragen fehlen');
        $qIdx = (string)@file_get_contents(__DIR__ . '/../umfrage/index.php');
        st_expect(str_contains($qIdx, 'hash_equals($vt, $mit)'),
            'umfrage/index.php vergleicht den Vorschau-Schlüssel nicht mehr zeitkonstant');
        st_expect(str_contains($qIdx, "=== 'draft' && !\$vorschau"),
            'umfrage/index.php: Entwürfe sind wieder ohne Vorschau-Schlüssel sichtbar (oder gar nicht)');
        st_expect((bool)preg_match('~if \(\$vorschau\) \{.*?Nur Vorschau~s', $qIdx),
            'umfrage/index.php: die Vorschau schickt wieder echte Stimmen ab');

        // --- Bilder: jeder Weg muss die Datei mitnehmen ---
        foreach (['umfrage_bild_speichern', 'umfrage_bild_loeschen', 'umfrage_bild_waisen',
                  'umfrage_bilder_der_umfrage_loeschen', 'umfrage_question_bild_setzen'] as $f) {
            st_expect(function_exists($f), 'Bild-Funktion ' . $f . '() fehlt');
        }
        $qUmDb = (string)@file_get_contents(__DIR__ . '/../umfrage-db.php');
        st_expect(str_contains($qUmDb, 'umfrage_bilder_der_umfrage_loeschen($id);'),
            'umfrage_delete() lässt die Bilddateien liegen – die Fremdschlüssel-Kaskade räumt nur die Zeilen');
        st_expect((bool)preg_match('~function umfrage_question_delete.*?umfrage_bild_loeschen~s', $qUmDb),
            'umfrage_question_delete() lässt das Bild der Frage liegen');
        st_expect(str_contains($qUmDb, '+ umfrage_bild_waisen()'),
            'das Aufräumen holt die verwaisten Bilddateien nicht mehr ab');
        st_expect((bool)preg_match('~function umfrage_question_copy.*?@copy\(umfrage_bild_dir~s', $qUmDb),
            'umfrage_question_copy() teilt die Bilddatei mit dem Original – dann reißt EIN Löschen dem anderen das Bild weg');
        // Ein hochgeladener Name darf nie in einen Pfad geraten.
        st_expect(umfrage_bild_url('../../lib.php') === 'bilder/lib.php' && umfrage_bild_url('') === '',
            'umfrage_bild_url() lässt Pfad-Anteile durch');

        // --- Englische Fassung: nur wenn gepflegt, sonst Rückfall auf Deutsch ---
        st_expect(um_feld(['title' => 'De', 'title_en' => 'En'], 'title', 'en') === 'En'
            && um_feld(['title' => 'De', 'title_en' => ''], 'title', 'en') === 'De'
            && um_feld(['title' => 'De'], 'title', 'de') === 'De',
            'um_feld() liefert die Sprachfassung nicht richtig (Rückfall auf Deutsch fehlt?)');
        st_expect(umfrage_hat_en(['title_en' => '', 'intro_en' => ''], []) === false
            && umfrage_hat_en(['title_en' => '', 'intro_en' => ''], [['options' => [['label_en' => 'Yes']]]]) === true,
            'umfrage_hat_en() erkennt nicht, ob es überhaupt etwas zu schalten gibt');
        st_expect(umfrage_ui('absenden', 'en') !== umfrage_ui('absenden', 'de') && umfrage_ui('absenden', 'en') !== '',
            'das feste Seiten-Gerüst hat keine englische Fassung mehr');
        st_expect(umfrage_text('mail_subject', 'en') !== '', 'die Mail-Texte liefern in Englisch nichts (auch kein deutscher Rückfall)');
    });

    $run('Umfragen: Vorschau nummeriert wie der echte Bogen', function () {
        // Die Vorschau im Frage-Formular zeigte fest „1“ – bei Frage 7 also eine Lüge (erstes
        // Feedback von außen). Die Nummer kommt jetzt aus PHP; Zwischentexte zählen dort nicht
        // mit, genau wie auf der öffentlichen Seite.
        $qUmAdm = (string)@file_get_contents(__DIR__ . '/umfragen.php');
        st_expect(!str_contains($qUmAdm, "nr.textContent = '1'"),
            'admin/umfragen.php: die Vorschau nummeriert wieder jede Frage als „1“');
        st_expect(str_contains($qUmAdm, 'var FRAGE_NR = <?= (int)$vorschauNr ?>')
            && str_contains($qUmAdm, 'nr.textContent = String(FRAGE_NR)'),
            'admin/umfragen.php: die Vorschau bekommt ihre Nummer nicht mehr aus PHP');
        st_expect(str_contains($qUmAdm, 'if ((string)$qz[\'type\'] !== \'info\') $vorschauNr++;'),
            'admin/umfragen.php: die Vorschau-Nummer zählt Zwischentexte mit – der echte Bogen tut das nicht');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../umfrage/index.php'), '<?php endif; $nr++; ?>'),
            'umfrage/index.php: die Zählung des echten Bogens hat sich geändert – die Vorschau muss nachziehen');
    });

    $run('Basis-Score: vorzeitig beendete Abstimmungen kosten nichts', function () {
        // Der Vorsitz beendet, sobald das Stimmungsbild klar ist – wer bis zur Frist noch Zeit
        // gehabt hätte, darf dafür keinen Abzug bekommen (ausdrückliche Vorgabe). Ohne Frist
        // oder ohne vermerktes Ende bleibt es bei der bisherigen Wertung (Altbestand).
        st_expect(function_exists('deadline_moment') && function_exists('vote_closed_early'),
            'die Fristen-Helfer deadline_moment()/vote_closed_early() fehlen');
        st_expect(deadline_moment('2026-08-20') === '2026-08-20 23:59',
            'ein reines Datum als Frist muss bis Tagesende gelten (23:59)');
        st_expect(deadline_moment('2026-08-20 12:00') === '2026-08-20 12:00' && deadline_moment('') === '',
            'deadline_moment() verfälscht eine gesetzte Uhrzeit oder eine leere Frist');
        st_expect(vote_closed_early('2026-08-20 23:59', '2026-08-18 10:00') === true,
            'vor der Frist beendet muss als „vorzeitig" gelten');
        st_expect(vote_closed_early('2026-08-20', '2026-08-20 10:00') === true,
            'am Fristtag um 10:00 beendet ist vorzeitig – die Frist läuft bis 23:59');
        st_expect(vote_closed_early('2026-08-20', '2026-08-21 00:05') === false,
            'nach Fristablauf beendet ist regulär – der Abzug bleibt');
        st_expect(vote_closed_early('2026-08-20', null) === false && vote_closed_early('', '2026-08-18 10:00') === false,
            'ohne vermerktes Ende oder ohne Frist darf nicht auf „vorzeitig" geraten werden');
        // Die Wertung muss den Helfer auch wirklich benutzen – beide Töpfe.
        st_expect(substr_count($libSrcBasis = (string)@file_get_contents(__DIR__ . '/../lib.php'), 'vote_closed_early(') >= 3,
            'basis_context() filtert die vorzeitig beendeten Umläufe/Abstimmungen nicht mehr heraus');
        st_expect(str_contains($libSrcBasis, 'SELECT id, deadline, closed_at FROM circular_votes'),
            'basis_context() lädt closed_at der Umläufe nicht mehr – ohne das ist „vorzeitig" nicht erkennbar');
    });

    $run('Sitzungen: vorgegebene Kennungen werden verworfen', function () {
        // Jeder Bereich startet seine EIGENE Sitzung (bewusst, sie sind getrennt) – und jeder
        // braucht darum dieselbe Absicherung. Ohne session.use_strict_mode übernimmt PHP die
        // Kennung, die der Browser schickt: Bei Müll (Scanner auf den öffentlichen Seiten) gibt
        // es zwei Warnungen im Log UND eine Sitzung, die nie gespeichert wird – die betroffene
        // Person kommt an keinem Formular mehr vorbei („Ungültige Anfrage", auch nach Neuladen).
        $orte = ['lib.php', 'stupa/stupa-lib.php', 'veranstaltungen/wl-lib.php', 'anmeldung/anmeldung-lib.php',
                 'umfrage/umfrage-lib.php', 'pat/pat-lib.php', 'termin/termin-lib.php'];
        foreach ($orte as $ort) {
            $q = (string)@file_get_contents(__DIR__ . '/../' . $ort);
            st_expect($q !== '', $ort . ' ist nicht lesbar – die Sitzungs-Prüfung darunter greift sonst ins Leere');
            $iStrict = strpos($q, "ini_set('session.use_strict_mode', '1')");
            $iStart  = strpos($q, 'session_start()');
            st_expect($iStrict !== false, $ort . ': session.use_strict_mode fehlt – der Browser könnte die Sitzungs-Kennung vorgeben');
            st_expect($iStrict !== false && $iStart !== false && $iStrict < $iStart,
                $ort . ': use_strict_mode steht hinter session_start() – dort kommt es zu spät');
        }
        return count($orte) . ' Sitzungs-Starts geprüft';
    });

    $run('Datenbank-Integrität (quick_check + Fremdschlüssel)', function () {
        $qc = (string)db()->query('PRAGMA quick_check')->fetchColumn();
        st_expect($qc === 'ok', 'quick_check meldet: ' . $qc);
        $fk = db()->query('PRAGMA foreign_key_check')->fetchAll();
        st_expect(!$fk, count($fk) . ' verwaiste Fremdschlüssel-Zeile(n), z. B. Tabelle „' . (string)($fk[0]['table'] ?? '?') . '"');
        // Cron und Seitenaufruf schreiben gleichzeitig – ohne WAL + Wartezeit gab es live
        // „database is locked" (absence.php, 2026-08-12). Beides muss db() setzen.
        st_expect(strtolower((string)db()->query('PRAGMA journal_mode')->fetchColumn()) === 'wal',
            'die Datenbank läuft nicht mehr im WAL-Modus – gleichzeitige Schreibzugriffe scheitern dann wieder mit „database is locked"');
        st_expect((int)db()->query('PRAGMA busy_timeout')->fetchColumn() >= 4000,
            'busy_timeout fehlt auf der Hauptdatenbank – bei gleichzeitigen Schreibzugriffen scheitert der zweite sofort');
    });
    $run('Erinnerungs-Versand (Dry-Run – es wird nichts verschickt)', function () {
        $vote = 0;
        foreach (db()->query("SELECT * FROM events WHERE closed = 0 AND draft = 0 AND deadline IS NOT NULL AND deadline <> ''")->fetchAll() as $e) {
            $d = days_until_d((string)$e['deadline']);
            if ($d < 0 || $d > 7) continue;
            $vote += count(send_event_reminders($e, false, true)['sent']);
        }
        return 'würde beim nächsten Cron-Lauf senden – Abstimmung: ' . $vote
            . ' · Einsatz: ' . send_shift_reminders(true)
            . ' · Bericht: ' . send_report_reminders(true)
            . ' · Gegenstände: ' . send_voteitem_reminders(true)
            . ' · Vielleicht: ' . send_maybe_reminders(true)
            . ' · Inaktivität: ' . send_inactivity_reminders(true)
            . ' · Sommer-Geschenk: ' . send_sommer_gift_reminders(true);
    });
    $run('Sekretariats-Übersicht (Dashboard & Verwaltung)', fn() => sekretariat_overview_html());
    $run('Standard-Tagesordnung + „keine Redeliste"-/Berichte-Listen', function () {
        agenda_tree(agenda_template()); agenda_template_skip_set(); agenda_template_report_set();
        // Jeder Teil braucht sein „Sonstiges" am Ende: Eingereichte TOPs ohne gewählten Platz werden
        // davor einsortiert. Fehlt der interne, landen interne TOPs wieder hinter allem.
        $vorlage = agenda_tree(default_agenda());
        $letzter = $vorlage ? $vorlage[count($vorlage) - 1] : [];
        st_expect(!empty($letzter['internal']) && agenda_is_sonstiges((string)($letzter['text'] ?? '')),
            'Die Standard-Tagesordnung endet nicht mehr mit einem internen Sammel-TOP – neue interne TOPs hätten dann nichts, wovor sie einsortiert werden');
        st_expect(agenda_is_sonstiges('Sonstiges') && agenda_is_sonstiges(' sonstiges (intern) ') && !agenda_is_sonstiges('Sonstige Anträge'),
            'agenda_is_sonstiges() erkennt den Sammel-TOP nicht mehr zuverlässig');
        // Zwei TOPs mit demselben Text teilen sich ihren Schlüssel aus base_top_key() – eine
        // „keine Redeliste"-Markierung träfe dann beide. In der Vorlage darf sich nichts doppeln.
        $gesehen = [];
        foreach ($vorlage as $it) {
            $k = base_top_key((string)$it['text']);
            st_expect(!isset($gesehen[$k]), 'Standard-Tagesordnung: „' . $it['text'] . '" steht doppelt drin – beide TOPs teilen sich dann ihre Markierungen');
            $gesehen[$k] = true;
        }
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../lib.php'), '$a = $sonstInt !== null ? $sonstInt : $n;'),
            'merged_agenda() hängt eingereichte interne TOPs wieder ans Ende, statt sie vor den internen Sammel-TOP zu setzen');
    });
    $run('Eventscores berechnen', fn() => member_scores());
    $run('Eventscore-Band (asymmetrische Schwellen) + Startpunkte', function () {
        st_expect(score_band(20, 12.0) === 'gut', 'Deutlich über Schnitt muss Gut sein');
        st_expect(score_band(11, 12.0) === 'okay' && score_band(9, 12.0) === 'okay', 'Unterm Schnitt, aber über zwei Dritteln muss Okay bleiben');
        st_expect(score_band(8, 12.0) === 'ausbaufaehig', 'Zwei Drittel des Schnitts oder weniger muss Ausbaufähig sein');
        st_expect(score_band(5, 5.8) === 'okay' && score_band(3, 5.8) === 'ausbaufaehig' && score_band(2, 5.8) === 'ausbaufaehig', 'Realfall: bei Schnitt ~5,8 ist 5 Okay, 3 und 2 Ausbaufähig');
        st_expect(score_band(1, 2.0) === 'okay', 'Bei Mini-Schnitten schützt der 2-Punkte-Mindestabstand');
        $bd = member_score_breakdown(0);
        st_expect(array_key_exists('start', $bd) && array_key_exists('adjust', $bd) && array_key_exists('total', $bd), 'Score-Aufschlüsselung braucht start/adjust/total');
    });
    $run('Aktivitätsstatistik (Wochen-Fenster)', function () {
        $s = activity_stats(8);
        st_expect(count($s['weeks']) === 8 && $s['window'] === 8, 'Fenster muss 8 Wochen liefern');
        st_expect(isset($s['per_week'], $s['this_week'], $s['members'], $s['ever_active']), 'Statistik-Felder unvollständig');
        st_expect(!$s['members'] || isset($s['members'][0]['streak'], $s['members'][0]['streak_best']), 'Streak-Felder je Mitglied fehlen');
        st_expect(iso_week_label('2026-W27') === 'KW 27', 'ISO-Wochen-Label falsch');
    });
    $run('Achievements (Katalog, Streak, Belohnungen)', function () {
        $cat = achievements_catalog();
        st_expect(isset($cat['first_open'], $cat['streak_50'], $cat['night_owl'], $cat['flashbang']), 'Katalog unvollständig');
        st_expect(isset($cat['top3_shifts'], $cat['shifts_60'], $cat['reports_20'], $cat['active_52w'], $cat['hat_trick']), 'Fleiß-Achievements fehlen im Katalog');
        st_expect(isset($cat['shifts_25'], $cat['self_5'], $cat['rescue_3'], $cat['weekend_5'], $cat['gt_5'], $cat['infos_20'], $cat['responses_25'], $cat['friday13']), 'Ausbau-Achievements fehlen im Katalog');
        st_expect(isset($cat['meetings_10'], $cat['meetings_20'], $cat['top_1'], $cat['top_10'], $cat['votes_20'], $cat['responses_50'], $cat['self_15'], $cat['weekend_15'], $cat['rescue_10'], $cat['reports_30'], $cat['expense_1'], $cat['expense_5']), 'Paket-2-Achievements (sichtbar) fehlen im Katalog');
        st_expect(isset($cat['leet'], $cat['neujahr'], $cat['nikolaus'], $cat['april1'], $cat['allround'], $cat['streak_saved'], $cat['double_shift'], $cat['marathon'], $cat['lastminute'], $cat['fair_trade'], $cat['phoenix'], $cat['chameleon'], $cat['binge_reader'], $cat['bug_fixed']), 'Paket-2-Achievements (geheim) fehlen im Katalog');
        foreach (['leet', 'neujahr', 'nikolaus', 'april1', 'allround', 'streak_saved', 'double_shift', 'marathon', 'lastminute', 'fair_trade', 'phoenix', 'chameleon', 'binge_reader', 'bug_fixed'] as $sc) {
            st_expect(!empty($cat[$sc]['hidden']), "Geheim-Achievement $sc muss hidden sein");
        }
        st_expect(isset($cat['sommerkoenig'], $cat['urlauber']) && empty($cat['sommerkoenig']['hidden']) && !empty($cat['urlauber']['hidden']), '„sommerkoenig" (sichtbar) / „urlauber" (geheim) fehlen oder falsch eingestuft');
        // Geldsack ist Rollen-Schmuck (Finanzen), hängt also an keinem Achievement – SVG/Slot trotzdem nötig
        st_expect(avatar_deco_svg('moneybag') !== '' && avatar_deco_slot('moneybag') === 'hand', 'Geldsack (Rollen-Schmuck Finanzen) braucht SVG + hand-Slot');
        // Die Tasse ist Namens-Schmuck (Insider, kein Achievement) – Foto-PNG eingebettet, hand-Slot
        st_expect(str_contains(avatar_deco_svg('tasse'), 'data:image/png;base64,') && avatar_deco_slot('tasse') === 'hand', 'Tasse (Namens-Schmuck) braucht eingebettetes Foto + hand-Slot');
        st_expect((avatar_palettes()['lava']['ach'] ?? '') === 'rescue_10', 'Lava-Palette muss am „rescue_10"-Achievement hängen');
        st_expect(!empty($cat['prinz']['hidden']) && ($cat['prinz']['reward']['key'] ?? '') === 'pink' && (($cat['prinz']['rewards'][0]['key'] ?? '') === 'pinkbow') && (($cat['prinz']['rewards'][1]['key'] ?? '') === 'tiara'), '„prinz" muss geheim sein und Pink-Skin + Schleife + Diadem geben');
        st_expect(($cat['friday13']['reward']['key'] ?? '') === 'gothic' && (($cat['friday13']['rewards'][0]['key'] ?? '') === 'spider'), '„friday13" muss Gothik-Skin + Spinne geben');
        st_expect((avatar_palettes()['glitzer']['ach'] ?? '') === 'prinz', 'Glitzer-Palette muss am „prinz"-Achievement hängen');
        // Paletten-Stufen: Cuteness frei (kein ach), legendäre (animierte) Paletten korrekt markiert
        $pals = avatar_palettes();
        st_expect(($pals['cuteness']['ach'] ?? '') === 'gt_1' && isset($cat['gt_1']) && str_contains($pals['cuteness']['grad'], 'url('), 'Cuteness-Palette muss an „gt_1" (Herz der Gruppe) hängen und Herzchen-Layer haben');
        st_expect(($pals['galaxy']['tier'] ?? '') === 'legende' && ($pals['lava']['tier'] ?? '') === 'legende', 'Galaxie + Lava müssen legendäre Paletten sein');
        // Die Pride-Familie ist FREI – Vielfalt ist keine Belohnung. Ein Achievement oder ein
        // Preisschild an einer dieser Farben widerspricht dem Grundsatz.
        foreach (['pride', 'pride_fahne', 'pride_bahnen', 'pride_trans', 'pride_aqua', 'pride_faden'] as $pk) {
            st_expect(isset($pals[$pk]), "Die Pride-Farbe $pk fehlt im Register");
            st_expect(empty($pals[$pk]['ach']) && empty($pals[$pk]['shop']),
                "Die Pride-Farbe $pk hängt an einem Achievement oder im Laden – sie muss frei bleiben");
            st_expect(trim((string)($pals[$pk]['grad'] ?? '')) !== '', "Der Pride-Farbe $pk fehlt der Verlauf");
        }
        // Der Faden zeichnet seinen Bogen als eingebettetes SVG und verweist darin auf den
        // Verlauf. Im Daten-URI MUSS die Raute als %23 stehen (nackt wäre sie der Fragment-
        // Trenner) – wird sie versehentlich doppelt kodiert (%2523), findet der Verweis nichts
        // und der Faden ist unsichtbar, ohne dass irgendwo ein Fehler auftaucht.
        $fadenGrad = (string)($pals['pride_faden']['grad'] ?? '');
        st_expect(str_contains($fadenGrad, 'url(%23f)') && !str_contains($fadenGrad, '%2523'),
            'Pride-Faden: der Verweis auf den Verlauf im SVG ist falsch kodiert – der Bogen bliebe unsichtbar');
        // Anzeige-Reihenfolge: eigene zuerst, dann kaufbare, zuletzt verschlossene. Geprüft am
        // ausgegebenen Rang – eine Sortierung, die nichts tut, fiele hier auf.
        $rangFolge = [];
        $meinFrei  = member_palettes(0);
        foreach (avatar_palettes_sorted(0) as $pk => $p) {
            $rangFolge[] = isset($meinFrei[$pk]) ? 0 : ((!empty($p['shop']) && isset(shop_items()[$pk])) ? 1 : 2);
        }
        $sortiert = $rangFolge; sort($sortiert);
        st_expect($rangFolge === $sortiert, 'avatar_palettes_sorted() mischt Verschlossenes zwischen die wählbaren Farben');
        st_expect(count($rangFolge) === count($pals), 'avatar_palettes_sorted() verliert oder verdoppelt Paletten');
        // Und die Seite muss sie auch BENUTZEN – eine Sortierfunktion, die niemand aufruft, ist
        // wirkungslos. Kommentare vorher wegschneiden: der erklärende Kommentar in
        // achievements.php nennt den Namen ebenfalls und würde die Prüfung sonst selbst erfüllen.
        $qAch = (string)preg_replace('~/\*.*?\*/~s', '', (string)@file_get_contents(__DIR__ . '/../achievements.php'));
        st_expect(str_contains($qAch, 'avatar_palettes_sorted('),
            'achievements.php listet die Farben wieder in Register-Reihenfolge – Verschlossenes stünde zwischen den wählbaren');
        // Die neuen Belohnungen: jede Farbe an ihrem Achievement, jedes Achievement im Katalog.
        foreach (['aurora' => 'meetings_full', 'deepsea' => 'protocols_10', 'frost' => 'early_report_5',
                  'neon' => 'night_protocol', 'prisma' => 'kudos_10', 'puste' => 'kudos_giver_3'] as $pk => $ac) {
            st_expect(($pals[$pk]['ach'] ?? '') === $ac, "Palette $pk muss am „$ac\"-Achievement hängen");
            st_expect(isset($cat[$ac]), "Achievement $ac fehlt im Katalog");
            st_expect(str_contains((string)($cat[$ac]['desc'] ?? ''), (string)explode(' ', $pals[$pk]['label'])[0]),
                "die Beschreibung von $ac nennt die Farbe „" . $pals[$pk]['label'] . '" nicht – dann schaltet man etwas frei, ohne zu erfahren wofür');
        }
        st_expect(!empty($cat['night_protocol']['hidden']), '„Nachtschicht" muss ein geheimes Achievement sein');
        st_expect(empty($cat['protocols_5']['hidden']) && empty($cat['protocols_10']['hidden']),
            'die Protokoll-Achievements sollen sichtbar sein – sie sind ein Ansporn, keine Überraschung');
        // Props-Serie: die Rechnung „volle Monate in Folge" ist die einzige mit eigener Logik.
        $libSrcAch = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect(function_exists('kudos_full_month_streak'), 'kudos_full_month_streak() fehlt');
        st_expect(kudos_full_month_streak(0) === 0, 'ohne Mitglied darf keine Serie herauskommen');
        st_expect(KUDOS_PER_MONTH >= 1, 'das Monatskontingent muss mindestens 1 sein');
        // SQLite-Falle: Die Schwelle darf NICHT als Parameter gebunden werden – gebunden ist sie Text,
        // und Zahlen sortieren in SQLite vor Text, „COUNT(*) >= '2'" wäre also immer falsch.
        st_expect((bool)preg_match('~function kudos_full_month_streak\(.*?\n\}~s', $libSrcAch, $ks)
            && !preg_match('~HAVING COUNT\(\*\) >= \?~', $ks[0]),
            'kudos_full_month_streak() bindet die Schwelle wieder als Parameter – in SQLite ist die Bedingung dann immer falsch');
        foreach ($pals as $pk => $p) st_expect(!isset($p['tier']) || $p['tier'] === 'legende', "Palette $pk: unbekannte Stufe " . ($p['tier'] ?? '?'));
        // Alle Special-Farben sind animiert (anim-Flag), die freien Verläufe nicht
        foreach (['cuteness', 'ember', 'gold', 'galaxy', 'lava', 'glitzer',
                  'aurora', 'deepsea', 'frost', 'neon', 'prisma', 'puste'] as $pk) st_expect(!empty($pals[$pk]['anim']), "Palette $pk muss animiert sein (anim-Flag)");
        foreach (['ocean', 'forest', 'flamingo', 'matcha', 'honig', 'lavendel'] as $pk) st_expect(empty($pals[$pk]['anim']), "Freie Palette $pk darf nicht animiert sein");
        // Die Regel dahinter, damit sie nicht mit der Zeit verwässert: verdient = bewegt, frei =
        // ruhig. Gekauft (shop-Marke, AsT-Sortiment) zählt als verdient – mit Börsen-AsT bezahlt.
        foreach ($pals as $pk => $p) {
            st_expect((empty($p['ach']) && empty($p['shop'])) === empty($p['anim']),
                "Palette $pk: freie Farben bleiben ruhig, verdiente/gekaufte bewegen sich – hier passt Freischaltung und Animation nicht zusammen");
            if (!empty($p['ach'])) st_expect(isset($cat[$p['ach']]), "Palette $pk hängt an einem Achievement, das es nicht gibt: " . $p['ach']);
            if (!empty($p['shop'])) st_expect((shop_items()[$pk]['type'] ?? '') === 'palette', "Palette $pk trägt die shop-Marke, steht aber nicht als Farbe im AsT-Sortiment");
        }
        // Jede animierte Farbe braucht ihre Bewegung im Stylesheet – sonst steht sie stumm da,
        // ohne dass irgendetwas meckert. Und sie muss bei „Ruhe bewahren" auch wirklich stillstehen.
        $css = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        $ruhe = (bool)preg_match('~prefers-reduced-motion[^{]*\{([^}]*\.pal-[^}]*)\}~s', $css, $rm);
        foreach ($pals as $pk => $p) {
            if (empty($p['anim'])) continue;
            st_expect(str_contains($css, '.pal-' . $pk . ' {') || str_contains($css, '.pal-' . $pk . ' ,'),
                "Palette $pk ist als animiert markiert, hat aber keine .pal-$pk-Regel in style.css");
            // Bewusst mit Wortgrenze: „@keyframes pal-puste" steckt auch in „pal-pusteXX" – ein
            // reiner str_contains() würde eine umbenannte oder ähnlich heißende Regel durchwinken.
            st_expect((bool)preg_match('~@keyframes pal-' . preg_quote($pk, '~') . '\s*\{~', $css),
                "Palette $pk fehlen die Keyframes in style.css");
            st_expect($ruhe && str_contains($rm[1], '.pal-' . $pk), "Palette $pk steht bei „Ruhe bewahren\" nicht still (prefers-reduced-motion)");
        }
        // „Glut" ist eine liegende Kohlekruste mit einem drehenden Glutbogen
        // DARUNTER. Drei Dinge daran sind Entscheidungen des Users und keine Geschmacksfrage:
        //   1. Die Kruste LIEGT – sie darf keine Animation bekommen („die Kohle soll liegen").
        //   2. Sie ist EIN gezeichnetes Bild, kein wiederholtes Muster – ein Kachelraster wäre
        //      im Stillstand sofort als Raster zu erkennen.
        //   3. Kein overflow: hidden am Element, sonst wird die Krone der Spitzenklasse
        //      abgeschnitten (sie ragt über den oberen Rand hinaus).
        preg_match('~\.pal-ember::before\s*\{([^}]*)\}~', $css, $peB);
        preg_match('~\.pal-ember::after\s*\{([^}]*)\}~', $css, $peA);
        preg_match('~\.pal-ember\s*\{([^}]*)\}~', $css, $peE);
        st_expect(!empty($peB[1]) && !empty($peA[1]),
            'der Glut fehlen die beiden Ebenen (::before = Glutbogen, ::after = Kohlekruste)');
        st_expect(!empty($peB[1]) && str_contains($peB[1], 'animation:'),
            'der Glutbogen (.pal-ember::before) dreht sich nicht mehr');
        st_expect(!empty($peA[1]) && !str_contains($peA[1], 'animation'),
            'die Kohlekruste darf sich NICHT drehen – sie liegt (ausdrückliche Ansage des Users)');
        st_expect(!empty($peA[1]) && str_contains($peA[1], 'no-repeat'),
            'die Kohlekruste muss ein einzelnes Bild sein (no-repeat) – als Kachel sieht man im '
            . 'Stillstand das Raster');
        st_expect(!empty($peE[1]) && !str_contains($peE[1], 'overflow'),
            '.pal-ember darf nichts abschneiden – sonst fehlt der Spitzenklasse die Krone');
        st_expect(str_contains($css, '.pal-ember .avatar-acc') && str_contains($css, '.pal-ember .av-ini'),
            'bei der Glut müssen Buchstabe UND Avatar-Schmuck über die Ebenen gehoben werden');
        st_expect(str_contains(member_avatar_class(['avatar_palette' => 'gold']), 'pal-gold') && member_avatar_class(['avatar_palette' => 'ocean']) === '' && member_avatar_class([]) === '', 'member_avatar_class muss genau die animierten Paletten liefern');
        prinz_swap_tick(0); // ohne Mitglied safe (kein Fatal, kein Unlock)
        // Skin-Registry: Struktur, Basis-Themes, bekannte Keys; skin_unlocked muss ohne Login safe sein
        $skins = app_skins();
        st_expect(isset($skins['royal'], $skins['trueblack'], $skins['sunset'], $skins['sommer'], $skins['strand'], $skins['pink'], $skins['gothic']), 'Skin-Registry unvollständig');
        foreach ($skins as $sk => $sd) {
            st_expect(isset($sd['label'], $sd['icon'], $sd['emoji'], $sd['base'], $sd['tc']), "Skin $sk: Felder fehlen");
            st_expect(in_array($sd['base'], ['light', 'dark'], true), "Skin $sk: base muss light|dark sein");
            st_expect(preg_match('/^#[0-9a-f]{6}$/i', (string)$sd['tc']) === 1, "Skin $sk: theme-color kein Hex");
        }
        st_expect($skins['sommer']['base'] === 'light', '„Hochsommer" muss der helle Skin sein');
        st_expect(skin_unlocked('gibtsnicht') === false, 'Unbekannter Skin darf nie freigeschaltet sein');
        skin_unlocked('strand'); trueblack_unlocked(); sunset_unlocked(); // ohne Login nicht fatal
        st_expect(($cat['sommerkoenig']['reward']['key'] ?? '') === 'sommer' && ($cat['sommerkoenig']['rewards'][0]['key'] ?? '') === 'flowercrown', '„sommerkoenig" muss Hochsommer-Skin + Blumenkranz geben');
        st_expect(($cat['urlauber']['reward']['key'] ?? '') === 'strand', '„urlauber" muss den Strand-Skin geben');
        st_expect(($cat['active_52w']['reward']['key'] ?? '') === 'blumen' && ($cat['active_52w']['rewards'][0]['key'] ?? '') === 'scarf',
            '„active_52w" muss die Blumenwiese + den Schal geben (derselbe Erfolg schaltet auch den Streak-Stil „Sempervivium" frei)');
        // Jeder Skin der Registry braucht auch einen CSS-Block. Fehlt er, ist der Skin zwar
        // wählbar, sieht aber aus wie das Standard-Design – die Belohnung liefe ins Leere,
        // ohne dass irgendwo ein Fehler auftaucht.
        $skinCss = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        if ($skinCss !== '') {
            foreach (array_keys($skins) as $sk) {
                st_expect(str_contains($skinCss, 'html[data-skin="' . $sk . '"]'),
                    "Skin $sk steht in der Registry, hat aber keinen CSS-Block in style.css");
                // Die Glühbirne blendet ALLE Symbole aus und zeigt nur das des aktiven Modus.
                // Fehlt die Regel, ist die Titelleiste bei diesem Skin symbollos.
                // Mit \s+ gesucht, weil die Liste zur Ausrichtung mal ein, mal zwei Leerzeichen hat –
                // ein starrer Textvergleich hätte hier royal und pink fälschlich angemeckert.
                $q = preg_quote($sk, '~');
                // ZWEI handgepflegte Listen: die Glühbirne in der Titelleiste und der Umschalter im
                // „Mehr"-Menü. In der installierten App ist die Titelleiste ausgeblendet – fehlt der
                // Skin dort, steht im Menü nur ein Pfeil ohne Beschriftung (so gemeldet).
                foreach (['.bulb' => 'die Glühbirne in der Titelleiste', '.ns-theme' => 'der Design-Umschalter im „Mehr"-Menü'] as $sel => $wo) {
                    st_expect(preg_match('~html\[data-theme-mode="' . $q . '"\]\s+' . preg_quote($sel, '~') . '\s+\.mode-' . $q . '\b~', $skinCss) === 1,
                        'Skin ' . $sk . ': ' . $wo . ' zeigt nichts an (Regel .mode-' . $sk . ' fehlt in style.css)');
                }
            }
        }
        // Feiertagsskins: Registry-Einträge, Achievement→Skin-Kopplung, geheim + auto_apply, Datumsfenster-Helfer
        st_expect(isset($skins['weihnacht'], $skins['halloween'], $skins['ostern'], $skins['valentin']), 'Feiertagsskins fehlen in app_skins()');
        st_expect($skins['ostern']['base'] === 'light' && $skins['valentin']['base'] === 'light' && $skins['weihnacht']['base'] === 'dark' && $skins['halloween']['base'] === 'dark', 'Feiertagsskin-Basis-Themes falsch (Ostern/Valentin hell, Weihnachten/Halloween dunkel)');
        foreach (['weihnachten' => 'weihnacht', 'halloween' => 'halloween', 'ostern' => 'ostern', 'valentin' => 'valentin'] as $ac => $sk) {
            st_expect(($cat[$ac]['reward']['key'] ?? '') === $sk && !empty($cat[$ac]['hidden']) && !empty($cat[$ac]['auto_apply']), "Feiertags-Achievement $ac muss geheim + auto_apply sein und Skin $sk geben");
        }
        st_expect(easter_mmdd(2026) === '04-05' && easter_mmdd(2025) === '04-20' && easter_mmdd(2027) === '03-28', 'Osterformel (easter_mmdd) liefert falsche Daten');
        st_expect(is_string(holiday_skin_achievement()), 'holiday_skin_achievement() muss einen String liefern (Feiertags-Fenster)');
        foreach ($cat as $ck => $ca) { // jede Skin-Belohnung muss auf einen Registry-Eintrag zeigen
            $rws = (array)($ca['rewards'] ?? []); if (!empty($ca['reward'])) $rws[] = $ca['reward'];
            foreach ($rws as $r) if (($r['type'] ?? '') === 'skin') st_expect(isset($skins[$r['key']]), "Achievement $ck: Skin-Belohnung {$r['key']} fehlt in app_skins()");
        }
        st_expect(avatar_deco_slot('sunglasses') === 'face' && avatar_deco_slot('cape') === 'neck' && avatar_deco_slot('coffee') === 'hand' && avatar_deco_slot('halo') === 'head', 'Accessoire-Slots falsch zugeordnet');
        // Avatar-Farbpaletten: Struktur, gesperrte brauchen ein existierendes Achievement, Leser-Funktionen safe
        foreach (avatar_palettes() as $pk => $p) {
            // Mehrlagige Paletten (Herzchen/Sterne/Schirmchen) starten mit url(...), der Verlauf kommt
            // danach. Geprüft wird, dass überhaupt ein Verlauf drinsteckt – welche Art, ist egal:
            // „Prisma" ist ein reiner conic-gradient und hat gar keinen linearen Anteil.
            st_expect(isset($p['label'], $p['grad'])
                && (bool)preg_match('~(linear|radial|conic)-gradient\(~', (string)$p['grad']),
                "Palette $pk: Felder fehlen, oder der Wert malt gar keinen Verlauf");
            st_expect(empty($p['ach']) || isset($cat[$p['ach']]), "Palette $pk: unbekanntes Achievement " . ($p['ach'] ?? '?'));
        }
        st_expect(member_palette(0) === '' && member_set_palette(0, 'ocean') === false, 'Paletten-Leser/-Setzer ohne Mitglied müssen safe sein');
        st_expect(str_starts_with(member_avatar_gradient(['id' => 0, 'name' => 'Test Person']), 'linear-gradient('), 'member_avatar_gradient muss immer einen Verlauf liefern');
        st_expect(($cat['night_owl']['reward']['type'] ?? '') === 'skin' && ($cat['night_owl']['reward']['key'] ?? '') === 'trueblack', '„night_owl" muss den TrueBlack-Skin geben');
        st_expect(($cat['flashbang']['reward']['key'] ?? '') === 'lantern', '„flashbang" muss die Laterne geben');
        st_expect(($cat['streak_50']['reward']['key'] ?? '') === 'sunset', '„streak_50" muss den Sonnenuntergang-Skin geben');
        st_expect(!isset($cat['streak_100']) && !isset($cat['reports_50']) && !isset($cat['shifts_100']) && !isset($cat['responses_100']) && !isset($cat['meetings_25']), 'Alte Schwellen-Codes (Juli 2026 gesenkt) dürfen nicht mehr im Katalog stehen – Freischaltungen migrieren auf die neuen Codes');
        st_expect(!empty($cat['top3_shifts']['dynamic']) && ($cat['top3_shifts']['reward']['key'] ?? '') === 'medal', '„top3_shifts" muss dynamisch sein und die Medaille geben');
        st_expect(avatar_deco_slot('medal') === 'neck', 'Medaille muss am Hals sitzen (neck-Slot)');
        st_expect(str_contains(flame_svg(), 'aflame') && str_contains(flame_svg(), 'fl-core'), 'flame_svg muss die mehrlagige Flammen-SVG liefern');
        st_expect(str_contains(flame_svg('heart'), 'aheart') && !str_contains(flame_svg(), 'aheart'), 'flame_svg(\'heart\') muss die Herz-Variante liefern');
        st_expect(str_contains(flame_svg('bolt'), 'abolt') && str_contains(flame_svg('star'), 'astar'), 'flame_svg(\'bolt\'/\'star\') müssen ihre Stil-Klasse liefern');
        st_expect(str_contains(flame_svg('coffee'), 'acoffee') && str_contains(flame_svg('coffee'), 'cf-mug') && str_contains(flame_svg('coffee'), 'cf-steam'), 'flame_svg(\'coffee\') muss Becher + Dampf liefern');
        st_expect(!str_contains(flame_svg(), 'abolt') && !str_contains(flame_svg(), 'astar') && !str_contains(flame_svg(), 'acoffee') && !str_contains(flame_svg(), 'aflower'), 'Die Standard-Flamme darf keine Sonder-Stil-Klasse tragen');
        // Blume: alle Teile müssen drinstecken – sichtbar macht sie erst die Stufe in style.css.
        // Fehlt eines, bleibt die entsprechende Wachstumsstufe leer, ohne dass irgendwo ein Fehler auftaucht.
        $bl = flame_svg('flower');
        foreach (['aflower', 'bl-soil', 'bl-seed', 'bl-stem', 'bl-l1', 'bl-l2', 'bl-bud', 'bl-petals', 'bl-core', 'bl-drop'] as $teil) {
            st_expect(str_contains($bl, $teil), "flame_svg('flower') fehlt das Teil „$teil\"");
        }
        // Blätter zählen und messen: siehe weiter unten (Windrad-Schutz). Bewusst nur EINE
        // Stelle – eine zweite Zählung mit fester Blattzahl läuft nach jeder Änderung an der
        // Form ins Leere und färbt den ganzen Block rot.
        // Erde und Samen dürfen NICHT in der Pflanzen-Gruppe stehen – sonst würden sie
        // mitwachsen und mitwiegen, und bei st0 (Größe 0) wäre gar nichts mehr zu sehen.
        $grp = strpos($bl, '<g class="bl-plant">');
        st_expect($grp !== false && strpos($bl, 'bl-soil') < $grp && strpos($bl, 'bl-seed') < $grp,
            'Erde und Samen müssen vor der Pflanzen-Gruppe stehen (sie wachsen nicht mit)');
        // Der Name des Blumen-Stils heißt mit Absicht „Sempervivium" – mit einem i mehr als die
        // botanische Gattung. Das sieht wie ein Tippfehler aus und wurde schon einmal
        // „korrigiert"; deshalb steht die Schreibweise hier fest.
        $achDatei = (string)@file_get_contents(__DIR__ . '/../achievements.php');
        if ($achDatei !== '') {
            st_expect(str_contains($achDatei, 'Sempervivium'),
                'der Blumen-Stil muss „Sempervivium" heißen (mit dem zusätzlichen i – das ist Absicht, kein Tippfehler)');
            // Hall of Fame „Längste Streaks": zählen die zwei höchsten WERTE
            // (alle Personen mit einem davon), nicht mehr die Top-3-Plätze – sonst kam bei
            // mehreren Gleichauf-Spitzenreitern nie jemand Zweites zum Zug.
            st_expect(str_contains($achDatei, '$hofWerte'),
                'achievements.php: die Werte-Logik der Streak-Hall-of-Fame (zwei höchste Werte) fehlt');
            st_expect(!str_contains($achDatei, "hofStreaks[2]['cur']"),
                'achievements.php: die alte Top-3-Platz-Logik der Streak-Hall-of-Fame ist zurück');
        }
        // Streak-Stil-Registry: jeder Stil hängt an einem existierenden Achievement; Wording-Helfer deckt alle ab
        $fsu = flame_styles_unlock();
        // KEINE feste Liste: Die würde beim nächsten neuen Stil rot, obwohl nichts fehlt.
        // Geprüft gehört, dass die verdienten Stile ALLE da sind – neue dürfen dazukommen,
        // und ihre Kopplung prüft die Schleife darunter ohnehin.
        st_expect(array_diff(['heart', 'bolt', 'star', 'coffee', 'flower'], array_keys($fsu)) === [],
            'flame_styles_unlock() fehlt einer der verdienten Stile (heart/bolt/star/coffee/flower)');
        foreach ($fsu as $sk => $need) st_expect(isset($cat[$need]), "Streak-Stil $sk verweist auf unbekanntes Achievement $need");
        // Geheime Namens-Freischaltung: die Stile darin müssen es auch wirklich geben, sonst
        // liefe die Bedingung ins Leere, ohne dass es jemals auffiele.
        foreach (flame_style_secret() as $sk => $vorname) {
            st_expect(isset($fsu[$sk]), "geheime Namens-Freischaltung zeigt auf unbekannten Streak-Stil „$sk\"");
            st_expect(trim($vorname) !== '' && mb_strtolower($vorname) === $vorname,
                "der Vorname für die Freischaltung von „$sk\" muss gesetzt und kleingeschrieben sein");
        }
        st_expect(flame_style_allowed(0, '') === true && flame_style_allowed(0, 'flower') === false,
            'flame_style_allowed: ohne Mitglied nur die Flamme');
        st_expect(flame_style_allowed(1, 'gibtsnicht') === false, 'flame_style_allowed: unbekannter Stil muss abgelehnt werden');
        // Dieselbe geheime Namensbedingung gibt es für App-Designs. Skin und Streak-Stil hängen am
        // selben Erfolg und gehören zusammen – wer den einen geschenkt bekommt, bekommt auch den
        // anderen. Ginge einer der beiden Einträge verloren, fiele das sonst nie auf.
        foreach (skin_secret() as $sk => $vorname) {
            st_expect(isset(app_skins()[$sk]), "geheime Namens-Freischaltung zeigt auf unbekannten Skin „$sk\"");
            st_expect(trim($vorname) !== '' && mb_strtolower($vorname) === $vorname,
                "der Vorname für die Freischaltung von „$sk\" muss gesetzt und kleingeschrieben sein");
        }
        st_expect(in_array('vivien', flame_style_secret(), true) === in_array('vivien', skin_secret(), true),
            'die Blume und die Blumenwiese müssen dieselbe Namens-Freischaltung haben (sie hängen am selben Erfolg)');
        st_expect(member_first_name_is(0, 'vivien') === false, 'member_first_name_is: ohne Mitglied immer false');
        st_expect(member_first_name_is(1, '') === false, 'member_first_name_is: leerer Vorname darf nie greifen');
        // Jeder Stil braucht Wort UND Icon – sonst steht in Hall of Fame und Aktivitätsliste
        // die Flammen-Ikone neben einer Blume.
        // WICHTIG flame_styles_all(): verdiente UND gekaufte Stile. Stünde hier nur die Liste
        // der verdienten, bliebe unbemerkt, wenn ein gekaufter Stil kein eigenes Zählwort hat
        // und überall stumm auf „Flammen" zurückfällt.
        foreach (array_merge([''], flame_styles_all()) as $sk) {
            st_expect(flame_style_word($sk, 1) !== '', "flame_style_word('$sk') liefert kein Wort");
            st_expect($sk === '' || flame_style_word($sk, 2) !== flame_style_word('', 2),
                "flame_style_word('$sk') fällt auf „Flammen\" zurück – der Stil braucht ein eigenes Zählwort");
            st_expect(str_starts_with(flame_style_icon($sk), 'ti-'), "flame_style_icon('$sk') braucht ein Tabler-Icon");
        }
        /* Und jeder Stil braucht seine Beschriftung im Locker. Fehlt sie, zeigt der Umschalter
           den nackten Schlüssel („schein") ohne Sinnbild – aufgefallen ist das erst am
           fertigen Bildschirm. Geprüft wird am Quelltext, weil die Tabelle eine lokale
           Variable in achievements.php ist. */
        $qAchSeite = (string)@file_get_contents(__DIR__ . '/../achievements.php');
        $qMeta = preg_match('~\$streakStyleMeta = \[(.*?)\n\];~s', $qAchSeite, $mMeta) ? $mMeta[1] : '';
        st_expect($qMeta !== '', 'achievements.php: die Beschriftungen der Streak-Stile ($streakStyleMeta) sind nicht auffindbar');
        foreach (flame_styles_all() as $sk) {
            st_expect((bool)preg_match("~'" . preg_quote($sk, '~') . "'\s*=>\s*\['label'~", $qMeta),
                "achievements.php: Streak-Stil „$sk\" hat keine Beschriftung – im Umschalter stünde der nackte Schlüssel");
        }
        // Dieselbe Vollständigkeit für die Verwaltungs-Übersicht: Dort waren die Stile schon
        // einmal auf einem alten Stand eingefroren (4 von 10) – neue zählten dann als „Flamme".
        $qAchAdm = (string)@file_get_contents(__DIR__ . '/achievements.php');
        $qAdmMeta = preg_match('~\$styleLabels = \[(.*?)\n\];~s', $qAchAdm, $mAdm) ? $mAdm[1] : '';
        st_expect($qAdmMeta !== '', 'admin/achievements.php: die Streak-Stil-Beschriftungen ($styleLabels) sind nicht auffindbar');
        foreach (flame_styles_all() as $sk) {
            st_expect((bool)preg_match("~'" . preg_quote($sk, '~') . "'\s*=>~", $qAdmMeta),
                "admin/achievements.php: Streak-Stil „$sk\" fehlt in der Übersicht – er würde in der Statistik unsichtbar");
        }
        st_expect(str_contains($qAchAdm, 'member_flame_style((int)$m[\'id\'])'),
            'admin/achievements.php: die Stil-Zählung muss über member_flame_style() laufen – nur die kennt auch gekaufte Stile');
        // Aktivitätsstatistik: Icon-Zuordnung über member_flame_style() (kennt Kauf-Stile),
        // Legende und Fließtext-Farbwelten vollständig für JEDEN Stil der Registry.
        $qAct = (string)@file_get_contents(__DIR__ . '/activity.php');
        st_expect(str_contains($qAct, 'member_flame_style((int)$r[\'id\'])'),
            'admin/activity.php: die Stil-Zuordnung muss über member_flame_style() laufen – gekaufte Stile erschienen sonst als Flamme');
        $stCssAct = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        foreach (flame_styles_all() as $sk) {
            st_expect(str_contains($qAct, "'" . $sk . "'"),
                "admin/activity.php: Streak-Stil „$sk\" fehlt in der Legende der Aktivitätsstatistik");
            st_expect(str_contains($stCssAct, '.act-streak.' . $sk . 'line.t1') && str_contains($stCssAct, '.act-streak.' . $sk . 'line.t5'),
                "style.css: dem Streak-Stil „$sk\" fehlt die Farbwelt in der Aktivitätsstatistik (.act-streak.{$sk}line.t1–t5)");
        }
        // Feier-Overlay der Achievements-Seite: jedes Stil-Zählwort muss vorkommen ($giveWord)
        $qGive = preg_match('~\$giveWord = \[(.*?)\]\[~s', $qAchSeite2 = (string)@file_get_contents(__DIR__ . '/../achievements.php'), $mGive) ? $mGive[1] : '';
        st_expect($qGive !== '', 'achievements.php: die Zählwort-Liste $giveWord des Feier-Overlays ist nicht auffindbar');
        foreach (flame_styles_all() as $sk) {
            st_expect(str_contains($qGive, "'" . $sk . "'"),
                "achievements.php: Streak-Stil „$sk\" fehlt im \$giveWord des Feier-Overlays – dort stünde „eine Flamme\"");
        }
        // Keine der Zeichnungen füllt das 40x46-Feld aus. Ohne Zentrieren klebt jede am linken
        // Rand – beim liegenden Heiligenschein fiel es am meisten auf.
        st_expect((bool)preg_match('~\.flame-wahl\s*\{(?:[^{}]|\{[^{}]*\})*justify-content:\s*center~s', $stCssVor = (string)@file_get_contents(__DIR__ . '/../assets/style.css')),
            'style.css: .flame-wahl zentriert die Stil-Vorschau nicht mehr – die Zeichnungen kleben links');
        st_expect(!str_contains($qAchSeite, 'display:inline-flex;width:40px'),
            'achievements.php: die Stil-Vorschau steht wieder als Inline-Stil da statt als .flame-wahl');
        // Meilenstein-Sprüche im Streak-Overlay: ohne eigenen Eintrag in app.js gratuliert das
        // Overlay einem Raketen-Träger mit „Die Flamme lodert!".
        $spJs = (string)@file_get_contents(__DIR__ . '/../assets/app.js');
        $spBlock = '';
        if (preg_match('~var MILES = \{(.*?)\n  \};~s', $spJs, $mm)) { $spBlock = $mm[1]; }
        st_expect($spBlock !== '', 'app.js: die Meilenstein-Sprüche (var MILES) sind nicht auffindbar');
        foreach (flame_styles_all() as $sk) {
            st_expect(str_contains($spBlock, "'$sk'"), "app.js: Streak-Stil „$sk\" hat keine eigenen Meilenstein-Sprüche");
        }
        // Jede Zeile in MILES muss ALLE fünf Stufen führen – sonst steht beim 100er-Meilenstein
        // nichts unter der Zahl.
        foreach (explode("\n", $spBlock) as $zeile) {
            if (!str_contains($zeile, "1:")) continue;
            st_expect(str_contains($zeile, '5:'), 'app.js: eine Zeile der Meilenstein-Sprüche kennt die Stufe 5 (100 Tage) nicht: ' . trim(substr(trim($zeile), 0, 40)));
        }

        // --- Stufen: EINE Formel, ein Zwilling in app.js, Stufe 5 überall gebaut ---
        st_expect(streak_tier(0) === 0 && streak_tier(2) === 0 && streak_tier(3) === 1 && streak_tier(6) === 1
            && streak_tier(7) === 2 && streak_tier(29) === 2 && streak_tier(30) === 3 && streak_tier(49) === 3
            && streak_tier(50) === 4 && streak_tier(99) === 4 && streak_tier(100) === 5 && streak_tier(4000) === 5,
            'streak_tier(): Schwellen 3/7/30/50/100 stimmen nicht');
        st_expect(str_contains($spJs, 'c < 50 ? 3 : (c < 100 ? 4 : 5)'),
            'app.js: die Stufen-Formel tier() weicht von streak_tier() in lib.php ab');
        foreach (['dashboard.php', 'profil.php', 'achievements.php', 'admin/activity.php'] as $stDatei) {
            st_expect(!str_contains((string)@file_get_contents(__DIR__ . '/../' . $stDatei), '< 50 ? 3 : 4'),
                $stDatei . ': hier steht noch eine eigene Kopie der Stufen-Formel statt streak_tier()');
        }
        // Stufe 5 ist die einzige, die aus ihrer Kachel heraus wirkt: jeder Stil braucht seine
        // eigenen aura-Variablen UND eine eigene Aura – sonst fällt er auf Grau bzw. auf gar nichts zurück.
        $stCss = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        st_expect(str_contains($stCss, '.st5 {'), 'style.css: die Stufe 5 (100 Tage) hat keine Farbpalette');
        foreach (flame_styles_all() as $sk) {
            st_expect(str_contains($stCss, ".$sk.st5 "), "style.css: Streak-Stil „$sk\" hat keine eigene Stufe 5");
        }
        foreach (array_merge(['flame'], flame_styles_all()) as $sk) {
            st_expect(str_contains($stCss, ".aura-$sk "), "style.css: Streak-Stil „$sk\" strahlt auf Stufe 5 nicht in sein Feld aus (.aura-$sk fehlt)");

        }
        st_expect(streak_aura_html('', 3) === '' && streak_aura_class('', 3) === '', 'die Aura darf erst ab Stufe 4 erscheinen');
        // Stufe 4 ist die leise Vorstufe: dieselbe Mechanik, aber gedrosselt und mit weniger Teilchen.
        st_expect(str_contains(streak_aura_class('star', 4), 'aura-lite') && !str_contains(streak_aura_class('star', 5), 'aura-lite'),
            'die 50er-Stufe muss als aura-lite gekennzeichnet sein, die 100er nicht');
        st_expect(substr_count(streak_aura_html('star', 4), '<span') < substr_count(streak_aura_html('star', 5), '<span'),
            'die 50er-Stufe muss weniger Teilchen zeigen als die 100er');
        st_expect(str_contains($stCss, '.aura-lite .staura'), 'style.css: die 50er-Stufe wird nicht gedrosselt (.aura-lite fehlt)');
        // Stufe 5 soll eine EIGENE Bewegung am Zeichen haben, nicht dieselbe wie Stufe 4, nur
        // schneller. Die hängt an der STUFEN-Klasse der Kachel (.<stil>.st5) – nicht an der
        // Feld-Klasse, sonst liefe sie mit der leisen 50er-Aura schon mit.
        foreach (flame_styles_all() as $sk) {
            st_expect(str_contains($stCss, ".$sk.st5 ."), "style.css: Streak-Stil „$sk\" hat auf Stufe 5 keine eigene Bewegung am Zeichen");
        }
        st_expect(str_contains($stCss, '.st5:not(.heart)'), 'style.css: die schlichte Flamme hat auf Stufe 5 keine eigene Bewegung am Zeichen');
        st_expect(!str_contains($stCss, 'dm5-drehen'), 'der Diamant soll sich auf Stufe 5 NICHT drehen – das sah aus wie ein Zahnrad (bewusst entfernt)');
        st_expect(str_contains(streak_aura_html('flower', 5), 'staura') && str_contains(streak_aura_class('flower', 5), 'aura-flower'),
            'streak_aura_html/-class liefern auf Stufe 5 nichts Brauchbares');
        st_expect(streak_aura_class('', 5) === ' aura-feld aura-flame', 'die Flamme braucht als leerer Stil-Schlüssel den Namen „flame" in der Aura-Klasse');
        // Der Vorführ-Schalter darf NUR die Anzeige heben – wäre er an die echte Streak gekoppelt,
        // vergäbe schon das Ansehen der 100er-Stufe dauerhaft die Streak-Achievements.
        st_expect(test_streak_aura() || streak_tier_shown(4) === streak_tier(4),
            'streak_tier_shown() weicht ohne Vorführ-Schalter von streak_tier() ab');
        foreach (['dashboard.php', 'achievements.php'] as $auDatei) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../' . $auDatei), 'streak_tier_shown('),
                $auDatei . ': nutzt für die eigene Anzeige nicht streak_tier_shown() – die Vorführung der 100er-Stufe greift dort nicht');
        }
        // streak_admin_set() hebt streak_best mit an und vergibt damit dauerhaft Achievements:
        // Es darf ausschließlich am Kulanz-Werkzeug hängen, nie an einer Test- oder Anzeigefunktion.
        // (Diese Datei selbst steht bewusst nicht in der Liste – sie nennt den Namen ja gerade hier.)
        foreach (['dashboard.php', 'achievements.php', 'profil.php', 'mitglieder.php'] as $auDatei) {
            st_expect(!str_contains((string)@file_get_contents(__DIR__ . '/../' . $auDatei), 'streak_admin_set('),
                $auDatei . ': schreibt an der echten Streak – das vergibt ungewollt Achievements');
        }
        // „Karat" ist beim Diamanten ABSICHT in beiden Zahlformen: es ist EIN Stein, der wächst,
        // „7 Diamanten" wäre schlicht falsch. Nicht zu „Diamanten" „korrigieren".
        st_expect(flame_style_word('', 1) === 'Flamme' && flame_style_word('bolt', 2) === 'Blitze' && flame_style_word('coffee', 1) === 'Tasse' && flame_style_word('star', 2) === 'Sterne' && flame_style_word('flower', 2) === 'Gießtage' && flame_style_word('rakete', 2) === 'Schübe' && flame_style_word('diamant', 2) === 'Karat', 'flame_style_word: Singular/Plural je Stil');
        st_expect(flame_style_icon('') === 'ti-flame' && flame_style_icon('bolt') === 'ti-bolt' && flame_style_icon('star') === 'ti-star-filled' && flame_style_icon('coffee') === 'ti-coffee' && flame_style_icon('heart') === 'ti-heart-filled' && flame_style_icon('flower') === 'ti-flower', 'flame_style_icon: Tabler-Icon je Stil (Mini-Badges)');
        // Kleine Streak-Badges: alle Stile liefern etwas, die Blume eine eigene Zeichnung.
        foreach (array_merge([''], array_keys(flame_styles_unlock())) as $sk) {
            st_expect(trim(flame_style_mini($sk)) !== '', "flame_style_mini('$sk') liefert nichts");
        }
        st_expect(str_contains(flame_style_mini('flower'), '<svg') && str_contains(flame_style_mini('flower'), 'currentColor'),
            'Die Blume im Text-Badge braucht eine eigene Zeichnung in currentColor – die Font-Ikone verkommt dort zum Fleck');
        st_expect(!str_contains(flame_style_mini('heart'), '<svg'), 'Die anderen Stile bleiben Font-Ikonen');
        /* ---- Blume („Sempervivium") ----------------------------------------------------
           Bewusst gegen SELEKTOREN geprüft, nicht gegen ganze Deklarationen: Eine Prüfung auf
           den exakten Text „.aflower { filter: none; }" ist beim nächsten Mal rot, sobald jemand
           eine Eigenschaft ergänzt – genau das ist hier zweimal passiert. Und alles an EINER
           Stelle statt in drei Blöcken, sonst pflegt man wieder nur einen davon. */
        $cssBl = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        $hatRegel = fn(string $sel) => (bool)preg_match('~' . preg_quote($sel, '~') . '\s*\{~', $cssBl);
        $vb = fn(string $svg) => preg_match('~viewBox="([^"]+)"~', $svg, $m) ? $m[1] : '';
        $gross = flame_svg('flower');
        $klein = flame_svg('flower', true);

        // Beide Fassungen brauchen DIESELBE viewBox: die transform-origin-Werte in style.css sind
        // absolute Nutzereinheiten und gälten sonst für die kleine nicht mehr.
        st_expect($vb($gross) === $vb($klein), 'Große und kleine Blume brauchen dieselbe viewBox');
        st_expect(preg_match('~^-?[\d.]+ -?[\d.]+ [\d.]+ [\d.]+$~', $vb($gross)) === 1, 'Die Blume hat keine brauchbare viewBox');

        // Jedes gezeichnete Teil braucht eine Regel, sonst bleibt es unsichtbar oder ungefärbt.
        foreach (['bl-soil', 'bl-seed', 'bl-plant', 'bl-stem', 'bl-l1', 'bl-l2', 'bl-bud',
                  'bl-petals', 'bl-petals2', 'bl-core', 'bl-core2', 'bl-bloom'] as $teil) {
            st_expect(str_contains($gross, $teil), 'In der großen Blume fehlt „' . $teil . '"');
            st_expect(str_contains($klein, $teil), 'In der kleinen Blume fehlt „' . $teil . '" – dann bleibt eine Wachstumsstufe leer');
        }
        st_expect(str_contains($gross, 'bl-spark'), 'Das Funkeln der letzten Stufe fehlt');
        foreach (['.aflower', '.aflower .bl-bloom', '.aflower .bl-petals ellipse', '.aflower .bl-petals2 ellipse',
                  '.aflower .bl-core', '.aflower .bl-core2', '.aflower .bl-spark', '.flower.st4 .bl-spark',
                  '.ach-flame .aflower', '.profil-flame .aflower', '.mem-streak .profil-flame .aflower',
                  '.sp-stage .aflower'] as $sel) {
            st_expect($hatRegel($sel), 'In style.css fehlt eine Regel für „' . $sel . '"');
        }
        st_expect(str_contains($cssBl, '@keyframes bl-atmen'), 'Das Atmen der Blüte fehlt');

        // Volle Rosette: zwei versetzte Kränze zu je acht Blättern. Weniger sah neben den runden
        // Blüten des Blumenwiesen-Skins verloren aus.
        foreach ([$gross, $klein] as $nr => $svg) {
            st_expect(substr_count($svg, '<ellipse') >= 16, 'Blume Nr. ' . ($nr + 1) . ': die Blüte braucht zwei Kränze zu je acht Blättern');
        }
        // Das Zeichen im Fließtext geht BEWUSST einen anderen Weg: fünf Blätter als ein Pfad mit
        // ausgestanzter Mitte. Bei 17 px werden aus sechzehn Blättern ein Fleck.
        $badge = flame_style_mini('flower');
        st_expect(str_contains($badge, 'fill-rule="evenodd"'), 'Dem Zeichen im Fließtext fehlt die ausgestanzte Mitte – ohne sie ist es ein Klecks');
        st_expect(!str_contains($badge, '<ellipse'), 'Das Zeichen im Fließtext hat wieder die volle Rosette – bei 17 px wird daraus ein Fleck');

        // Windrad-Schutz: breite Blätter (Verhältnis unter 1:1.8) und keine Drehung beim Aufgehen.
        // Schmale Speichen um eine kleine Nabe sind eine Turbine, keine Blüte.
        st_expect(!str_contains($cssBl, 'rotate(calc(var(--bl-open'),
            'Die Blüte dreht sich beim Aufgehen wieder – genau daran sah sie aus wie ein Windrad');
        st_expect(!str_contains($gross, 'bl-tip'), 'Die Tupfen auf den Blattenden sind zurück – sie haben das Speichenmuster gemacht');
        foreach ([$gross, $klein] as $nr => $svg) {
            preg_match_all('~<ellipse cx="13" cy="[\d.]+" rx="([\d.]+)" ry="([\d.]+)"~', $svg, $mm);
            st_expect($mm[1] !== [], 'Blume Nr. ' . ($nr + 1) . ': keine Blütenblätter gefunden');
            foreach ($mm[1] as $i => $rx) {
                $v = (float)$mm[2][$i] / max(0.01, (float)$rx);
                st_expect($v < 1.8, 'Blume Nr. ' . ($nr + 1) . ': ein Blütenblatt ist zu schmal (' . round($v, 2) . ':1) – so wird die Blüte zum Windrad');
            }
        }
        unset($gross, $klein);
        /* ---- Ende Blume ---------------------------------------------------------------- */
        st_expect(member_onboarded(0) === true, 'member_onboarded(0) muss true sein (kein Mitglied → kein Tutorial-Zwang)');
        st_expect(member_show_tour_task(0) === false, 'member_show_tour_task(0) muss false sein (kein Mitglied → kein Angebot)');
        member_mark_onboarded(0); member_clear_tour_task(0); // No-Ops bei id<=0 – nur prüfen, dass sie nicht fatal sind
        // Schriftfarbe der Initialen: ALLE frei, keine an ein Achievement gekoppelt.
        $inks = avatar_inks();
        st_expect(count($inks) >= 8, 'Es sollten mindestens acht Schriftfarben zur Wahl stehen');
        foreach ($inks as $k => $t) {
            st_expect(!isset($t['ach']), 'Schriftfarbe „' . $k . '" darf an kein Achievement gekoppelt sein – sie sind alle frei');
            st_expect((bool)preg_match('~^#[0-9a-f]{6}$~i', (string)($t['color'] ?? '')), 'Schriftfarbe „' . $k . '" hat keinen gültigen Farbwert');
            st_expect(trim((string)($t['label'] ?? '')) !== '', 'Schriftfarbe „' . $k . '" hat keine Beschriftung');
        }
        st_expect(member_ink(0) === '' && member_set_ink(0, 'gold') === false, 'Ohne Mitglied darf sich keine Schriftfarbe setzen lassen');
        st_expect(member_set_ink((int)($_SESSION['member_id'] ?? 0), 'gibtsnicht') === false, 'Eine unbekannte Schriftfarbe muss abgelehnt werden');
        /* ---- Pride („stylisch statt bunt"): Skin + Palette + Verlaufs-Schrift ----
           Drei Ebenen, EIN Farbstand. Die Schrift ist die technisch besondere: Ein Verlauf passt
           nicht durch --av-ink, er kommt als Klasse (ink-pride) und braucht die .av-ini-Hülle um
           die Initialen – fehlt eine der drei Zutaten (grad im Register, Klasse, CSS-Regel,
           Hülle), sieht man einfach nur weiße Buchstaben und nichts meldet sich.
           Der SKIN hängt am Pronomen-Achievement „Gute Ansprache"; wer davor beigetreten ist,
           behält ihn per Besitzstand (Stichtag in member_reward_skins()).
           Palette und Initialen-Farbe „Pride" bleiben weiterhin FREI für alle. */
        st_expect(empty(app_skins()['pride']['frei']) && app_skins()['pride']['base'] === 'dark',
            'Pride-Skin fehlt in app_skins() oder ist wieder frei markiert – er hängt am Pronomen-Achievement');
        $prAch = achievements_catalog()['pronomen'] ?? [];
        st_expect((($prAch['reward']['key'] ?? '') === 'pride') && empty($prAch['hidden']),
            'Das sichtbare Achievement „pronomen" mit Pride-Skin-Belohnung fehlt im Katalog');
        // Besitzstand funktional: irgendein Alt-Mitglied muss Pride weiterhin in den Reward-Skins haben
        $altId = (int)db()->query("SELECT id FROM members WHERE joined_at = '' OR joined_at <= '2026-08-09' LIMIT 1")->fetchColumn();
        if ($altId > 0) {
            st_expect(isset(member_reward_skins($altId)['pride']),
                'Besitzstand verletzt: Alt-Mitglied #' . $altId . ' hat den Pride-Skin nicht mehr');
        }
        st_expect((bool)preg_match('~\'pronomen\'\s*=>\s*\$pronomenDa~', (string)@file_get_contents(__DIR__ . '/../lib.php')),
            'achievements_evaluate() wertet die Pronomen nicht mehr aus (Code „pronomen")');
        st_expect(empty(avatar_palettes()['pride']['ach']) && empty(avatar_palettes()['pride']['anim'])
                && isset(avatar_palettes()['pride']),
            'Pride-Palette muss frei (kein Achievement) und unanimiert sein');
        st_expect(!empty($inks['pride']['grad']) && str_contains((string)$inks['pride']['grad'], 'linear-gradient'),
            'Pride-Schriftfarbe braucht ihren Verlauf (grad) im Register');
        st_expect(str_contains(member_avatar_class(['avatar_ink' => 'pride']), 'ink-pride')
                && !str_contains(member_avatar_class(['avatar_ink' => 'gold']), 'ink-'),
            'member_avatar_class muss genau die Verlaufs-Schriftfarben als ink-Klasse liefern');
        st_expect(str_contains(avatar_bubble(['name' => 'Test Person']), 'class="av-ini"'),
            'avatar_bubble hüllt die Initialen nicht mehr in .av-ini – die Verlaufs-Schrift griffe nirgends');
        // Ohne Wahl kein --av-ink: dann greift die Vorgabe aus dem Stylesheet.
        st_expect(!str_contains(member_avatar_style(['name' => 'Test', 'avatar_ink' => '']), '--av-ink'), 'Ohne Wahl darf keine Schriftfarbe gesetzt werden');
        st_expect(str_contains(member_avatar_style(['name' => 'Test', 'avatar_ink' => 'gold']), '--av-ink:#f4cd54'), 'member_avatar_style() setzt die gewählte Schriftfarbe nicht');
        st_expect(!str_contains(member_avatar_style(['name' => 'Test', 'avatar_ink' => 'quatsch']), '--av-ink'), 'Ein unbekannter Wert in der Datenbank darf nicht ins Markup durchschlagen');
        $cssA = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        foreach (['.hero .avatar', '.gt-av', '.avatar-mini', '.deco-av'] as $sel) {
            st_expect(substr_count($cssA, 'var(--av-ink') >= 4, 'Nicht alle Avatar-Darstellungen lesen --av-ink – dann gilt die Farbe nur an manchen Stellen');
        }
        // text-shadow wird VERERBT: Ohne das ausdrückliche none läge der Schatten sichtbar
        // hinter den durchsichtigen Verlaufs-Buchstaben.
        st_expect((bool)preg_match('~\.ink-pride \.av-ini\s*\{[^}]*background-clip:\s*text~s', $cssA)
                && (bool)preg_match('~\.ink-pride \.av-ini\s*\{[^}]*text-shadow:\s*none~s', $cssA),
            'die Verlaufs-Regel .ink-pride .av-ini fehlt in style.css (oder lässt den vererbten text-shadow stehen)');
        st_expect(in_array('avatar_ink', array_map(fn($c) => (string)$c['name'], db()->query('PRAGMA table_info(members)')->fetchAll()), true),
            'Die Spalte members.avatar_ink fehlt – die Nachrüstung greift nicht');

        // Eine Farbe im style-Attribut schlaegt jede Regel aus dem Stylesheet. Am Symbol einer
        // Ueberschrift ist sie ausserdem ueberfluessig – .section-title .ti faerbt schon – und
        // sie wuerde den Regenbogen im Pride-Skin und das Rot der Achtung-Karten aushebeln.
        // Das Muster wird zusammengesetzt, damit diese Zeile sich nicht selbst meldet.
        $mustPetrol = '~class="section-title"[^>]*>\s*<i class="ti[^>]*style="color:' . 'var\(--petrol\)"~';
        $sünder = [];
        $wurzelPfad = (string)realpath(__DIR__ . '/..');
        foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/*.php') ?: [],
                             glob(__DIR__ . '/../veranstaltungen/*.php') ?: []) as $datei) {
            if (basename($datei) === 'errorlog.php') continue;
            if (preg_match($mustPetrol, (string)@file_get_contents($datei))) {
                // Mit Ordner, sofern es nicht die Wurzel ist: Der nackte Dateiname passt auf
                // mehrere Ordner – beim ersten Fund war deshalb erst unklar, wo die Datei liegt.
                $ordner = (string)realpath(dirname($datei));
                $sünder[] = ($ordner === $wurzelPfad ? '' : basename($ordner) . '/') . basename($datei);
            }
        }
        st_expect($sünder === [],
            'Überschrifts-Symbol mit fester Farbe im style-Attribut in: ' . implode(', ', $sünder)
            . ' – die Farbe weglassen, .section-title .ti setzt sie, und nur so greift der Pride-Regenbogen');

        $tp = tour_pages(); st_expect(isset($tp['dashboard']) && isset($tp['finanzen']), 'tour_pages() muss mindestens Dashboard + Auslagen (finanzen) führen (Rundgang-Kapitel)');
        st_expect(is_array(tour_flags()) && array_key_exists('finanzen', tour_flags()) && array_key_exists('sekretariat', tour_flags()), 'tour_flags() muss die Rollen-Flags finanzen/sekretariat führen');
        // Jeder Anker, auf den der Rundgang zeigt, muss es auch geben. Ein Tippfehler oder eine
        // umbenannte Überschrift fällt sonst erst auf, wenn jemand den Rundgang durchklickt –
        // und der Spot landet dann irgendwo (bei „Umfragen" auf dem ganzen Kachelraster).
        $js = (string)@file_get_contents(__DIR__ . '/../assets/app.js');
        $marke = strpos($js, 'var CATS =');
        $tour  = $marke === false ? '' : substr($js, $marke, 20000);
        preg_match_all("~(?:sel|clickTo):\s*'([^']+)'~", $tour, $tr);
        $ids = [];
        foreach ($tr[1] ?? [] as $ausdruck) {
            foreach (explode(',', $ausdruck) as $teil) {
                if (preg_match('~^#([A-Za-z][\w-]*)~', trim($teil), $m)) $ids[$m[1]] = true;
            }
        }
        st_expect($ids !== [], 'Im Rundgang wurde kein einziger Anker gefunden – steht die Schrittliste noch in app.js?');
        // Bewusst ALLE Seiten einlesen statt einer gepflegten Liste: Eine solche Liste veraltet,
        // und dann meldet die Prüfung einen Anker als fehlend, den es längst gibt.
        $quellen = '';
        foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/*.php') ?: []) as $datei) {
            if (basename($datei) === 'errorlog.php') continue;   // sich selbst nicht mitlesen
            $quellen .= (string)@file_get_contents($datei);
        }
        foreach (array_keys($ids) as $id) {
            st_expect(str_contains($quellen, 'id="' . $id . '"'),
                'Der Rundgang zeigt auf #' . $id . ' – diesen Anker gibt es auf keiner der Rundgang-Seiten');
        }
        st_expect(!empty($cat['pinkdream']['hidden']), '„pinkdream" (Pretty in Pink) muss geheim sein');
        // Neue Beteiligungs-Achievements (Umlauf/Terminfinder/Steckbrief/Pinnwand-Antwort)
        st_expect(isset($cat['umlauf_2'], $cat['polls_4'], $cat['profile_done'], $cat['pin_reply_5']), 'Neue Achievements umlauf_2/polls_4/profile_done/pin_reply_5 fehlen im Katalog');
        st_expect(!empty($cat['pin_reply_5']['hidden']) && empty($cat['umlauf_2']['hidden']) && empty($cat['polls_4']['hidden']) && empty($cat['profile_done']['hidden']), '„Schlagfertig" geheim, die anderen drei sichtbar');
        // Die Krone auf den Mitglieder-Karten ist eine EIGENE Zeichnung – die Avatar-Krone ist
        // für einen Kopf gemacht und wird auf 34 px zur Zacke.
        $kr = mem_crown_svg();
        st_expect(str_starts_with($kr, '<svg') && str_contains($kr, 'viewBox="0 0 64 44"'), 'mem_crown_svg() liefert keine brauchbare Krone');
        st_expect(!str_contains($kr, '<linearGradient') && !str_contains($kr, ' id="'),
            'Die Karten-Krone darf keine id enthalten – sie steht auf einer Seite dutzendfach');
        st_expect($kr !== avatar_deco_svg('crown'), 'Karten-Krone und Avatar-Krone dürfen nicht dieselbe Zeichnung sein');
        // Die Avatar-Krone sitzt über die viewBox auf dem Kopf – ändert die sich, rutscht sie.
        st_expect(str_contains(avatar_deco_svg('crown'), 'viewBox="0 0 100 62"'), 'Die Avatar-Krone muss ihre viewBox 100x62 behalten, sonst sitzt sie nicht mehr auf dem Kopf');
        st_expect(!str_contains(avatar_deco_svg('crown'), ' id="'), 'Auch die Avatar-Krone darf keine id enthalten');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../lib.php'), 'mem_crown_svg()'), 'mem_crown_svg() wird nirgends benutzt');
        st_expect(avatar_deco_slot('stamp') === 'hand' && avatar_deco_slot('dart') === 'hand' && avatar_deco_slot('nametag') === 'neck' && avatar_deco_slot('speechbubble') === 'back', 'Neue Deco-Slots (Stempel/Dartpfeil=hand, Namensschild=neck, Sprechblase=back) falsch');
        st_expect(member_flame_style(0) === '' && member_flame_heart(0) === false && member_set_flame_style(0, 'heart') === false && member_set_flame_style(0, 'bolt') === false && member_set_flame_style(0, 'star') === false && member_set_flame_style(0, 'coffee') === false && member_set_flame_style(0, 'quatsch') === false, 'Streak-Stil: ohne Mitglied/Freischaltung/mit Unsinn muss alles safe ablehnen');
        royal_unlocked(); trueblack_unlocked(); sunset_unlocked(); // ohne Login nicht fatal
        foreach ($cat as $code => $a) {
            st_expect(isset($a['title'], $a['desc'], $a['icon'], $a['tier']), "Achievement $code: Felder fehlen");
            st_expect(isset(achievement_tier_meta()[$a['tier']]), "Achievement $code: unbekannte Stufe {$a['tier']}");
            $rws = (array)($a['rewards'] ?? []); if (!empty($a['reward'])) $rws[] = $a['reward'];
            foreach ($rws as $r) {
                if (($r['type'] ?? '') !== 'deco') continue;
                st_expect(avatar_deco_svg($r['key']) !== '', "Belohnung {$r['key']}: kein Accessoire-SVG");
                st_expect(in_array(avatar_deco_slot($r['key']), ['head', 'hand', 'neck', 'face', 'back', 'under'], true), "Belohnung {$r['key']}: unbekannter Slot");
            }
        }
        // Leser-Funktionen dürfen auch ohne echtes Mitglied nicht fatal werden
        member_achievement_codes(0); member_reward_decos(0); member_reward_skins(0); member_equipped_decos(0);
        // AsT-Markt: kaufbarer Schmuck OHNE Achievement. Währung ist der
        // DEPOTWERT des Schicht-Depots in AsT (Satire wird ernst; Guthaben wird gerechnet,
        // nie gespeichert); Royals beschlagnahmen alle 3 Monate ein Stück kostenlos
        // (Status live geprüft).
        $rwKeys = [];
        foreach ($cat as $a2) {
            $rs2 = (array)($a2['rewards'] ?? []); if (!empty($a2['reward'])) $rs2[] = $a2['reward'];
            foreach ($rs2 as $r2) if (isset($r2['key'])) $rwKeys[(string)$r2['key']] = 1;
        }
        $qCssShop = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        foreach (shop_items() as $sk2 => $si2) {
            st_expect((int)$si2['price'] > 0 && trim((string)$si2['label']) !== '', "AsT-Sortiment: $sk2 braucht Label und Preis > 0");
            st_expect(!isset($rwKeys[$sk2]), "AsT-Sortiment: $sk2 hängt zusätzlich an einem Achievement – kaufbar UND verdienbar wäre doppelt");
            switch ((string)($si2['type'] ?? '')) {
                case 'deco':
                    st_expect(avatar_deco_svg($sk2) !== '', "AsT-Sortiment: Deco $sk2 hat kein Accessoire-SVG");
                    st_expect(in_array(avatar_deco_slot($sk2), ['head', 'hand', 'neck', 'face', 'back', 'under'], true), "AsT-Sortiment: Deco $sk2 hat keinen gültigen Slot");
                    break;
                case 'palette':
                    st_expect(!empty(avatar_palettes()[$sk2]['shop']) && !empty(avatar_palettes()[$sk2]['grad']),
                        "AsT-Sortiment: Farbe $sk2 fehlt in avatar_palettes() oder trägt keine shop-Marke");
                    st_expect(str_contains($qCssShop, '.pal-' . $sk2), "AsT-Sortiment: Farbe $sk2 hat keine pal-Animation in style.css");
                    break;
                case 'flame':
                    st_expect(str_contains(flame_svg($sk2), 'a' . $sk2), "AsT-Sortiment: Streak-Stil $sk2 hat keine eigene flame_svg-Fassung");
                    st_expect(str_contains($qCssShop, '.' . $sk2 . '.st4'), "AsT-Sortiment: Streak-Stil $sk2 hat keine Stufen-Farben in style.css");
                    break;
                default:
                    st_expect(false, "AsT-Sortiment: $sk2 hat einen unbekannten Typ");
            }
        }
        $qLibAch = (string)@file_get_contents(__DIR__ . '/../lib.php');
        // Kauf-Wege: Die Krone darf NICHT mehr als eigener Knopf an der Kachel hängen – ein
        // Fehlgriff verbrauchte damit sofort das Privileg, das es nur alle drei Monate gibt.
        $achQ = (string)@file_get_contents(__DIR__ . '/../achievements.php');
        st_expect(!str_contains($achQ, 'class="shop-claim"'),
            'achievements.php: das anklickbare 👑 ist zurück – Beschlagnahmen gehört in den Kauf-Dialog');
        st_expect(substr_count($achQ, 'data-kauf=') === 3,
            'achievements.php: nicht alle drei Kauf-Locker (Schmuck/Farben/Stile) öffnen den Bezahl-Dialog');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../assets/app.js'), "d.propsOk")
            && str_contains((string)@file_get_contents(__DIR__ . '/../assets/app.js'), "absenden('royal')"),
            'app.js: dem Kauf-Dialog fehlt ein Zahlweg (Props oder Krone)');
        // Props als Zahlmittel: „erhalten" und „übrig" sind zwei verschiedene Zahlen. Die
        // Achievements müssen weiter mit den ERHALTENEN rechnen, sonst verliert man „Gute Seele"
        // wieder, sobald man sich etwas davon gönnt.
        st_expect(function_exists('props_available') && function_exists('shop_buy_props') && PROPS_PREIS > 0,
            'die Props-Währung fehlt (props_available/shop_buy_props/PROPS_PREIS)');
        st_expect(str_contains($qLibAch, '$propsGot  = $cnt(\'SELECT COUNT(*) FROM kudos WHERE to_id = ?\')'),
            'die Props-Achievements dürfen NICHT auf die übrigen Props umgestellt werden – sonst nimmt ein Kauf sie wieder weg');
        st_expect(props_available(0) === 0 && (shop_buy_props(0, 'wolke')['ok'] ?? true) === false,
            'Props-Kauf ohne Mitglied muss ins Leere laufen');
        // Schloss-Kacheln: sagen WIE VIELE es noch gibt, aber nie welche.
        st_expect(function_exists('locker_versteckt') && locker_versteckt(0, 'deco') === 0,
            'locker_versteckt() fehlt oder zählt ohne Mitglied etwas');
        st_expect(substr_count($achQ, 'locker_versteckt_html(') === 3 && str_contains($achQ, "locker_versteckt(\$meId, 'skin')"),
            'achievements.php: nicht alle vier Kategorien zeigen, wie viel noch versteckt ist');
        // Der Katalog darf den Code „streak_100" NIE wieder benutzen: Die Umbenennungs-Migration
        // schreibt ihn bei JEDEM Datenbank-Aufbau auf „streak_50" um – ein Erfolg
        // mit diesem Code wäre nach dem ersten Seitenaufruf spurlos weg. Deshalb heißt er „hundert".
        st_expect(!isset(achievements_catalog()['streak_100']) && isset(achievements_catalog()['hundert']),
            'Der 100-Tage-Erfolg muss „hundert" heißen – „streak_100" frisst die alte Umbenennungs-Migration');
        // Mondphasen: Die Stufe schiebt NUR den Schattenkreis; fehlt eine Stufe, steht der Mond still.
        for ($t = 0; $t <= 5; $t++) {
            st_expect((bool)preg_match('~\.mond\.st' . $t . '\s*\{[^}]*--mo-x~', $qCssShop),
                "style.css: dem Mond fehlt Stufe $t (--mo-x)");
        }
        st_expect(str_contains($qCssShop, '.amond .mo-schatten') && str_contains($qCssShop, 'var(--card'),
            'style.css: der Mondschatten muss die Kartenfarbe treffen, sonst klebt ein Fleck auf der Scheibe');
        st_expect(str_contains($qCssShop, '.aura-mond '), 'style.css: der Mond strahlt auf Stufe 5 nicht ins Feld (.aura-mond fehlt)');
        // Die Lichtstrahlen gehören ins FELD, nicht an die Scheibe: Als rotierende Keile am SVG
        // sahen sie aus wie eine Windmühle. Und sie müssen schon auf der 50 stehen, sonst hat die
        // Vorstufe gar keinen Effekt (der Lite-Modus blendet den Feld-Schleier sonst aus).
        st_expect(!str_contains(flame_svg('mond'), 'mo-kegel') && !str_contains($qCssShop, '@keyframes mo-kegel'),
            'die rotierenden Lichtkeile am Mond sind zurück – die Strahlen gehören in die Aura');
        st_expect(str_contains($qCssShop, '@keyframes aur-strahlen') && str_contains($qCssShop, '.aura-lite.aura-mond .staura::before'),
            'style.css: dem Mond fehlen die Lichtstrahlen im Feld (oder sie fehlen auf der 50er-Stufe)');
        // Die neuen Erfolge und ihre Belohnungen hängen zusammen – fehlt eine Seite, ist die
        // Belohnung entweder unerreichbar oder der Erfolg bleibt ohne Wirkung.
        foreach (['hundert' => 'lorbeer', 'anstoss' => 'klemmbrett'] as $ach => $deco) {
            st_expect(isset(achievements_catalog()[$ach]) && (achievements_catalog()[$ach]['reward']['key'] ?? '') === $deco,
                "Achievement „$ach\" muss die Deko „$deco\" vergeben");
            st_expect(avatar_deco_svg($deco) !== '', "Der Deko „$deco\" fehlt die Zeichnung");
        }
        // „Gewitter" darf es nicht geben.
        st_expect((avatar_palettes()['morgenrot']['ach'] ?? '') === 'erste_reihe',
            'Die Farbe Morgenrot hängt nicht mehr an ihrem Erfolg');
        st_expect(!isset(avatar_palettes()['gewitter']) && !str_contains($qCssShop, 'pal-gewitter'),
            'Die Farbe „Gewitter" ist zurück – sie wurde bewusst entfernt');
        st_expect((flame_styles_unlock()['mond'] ?? '') === 'mondsucht', 'Der Mond-Stil hängt nicht am Erfolg „mondsucht"');
        /* ---- Technik-Engel: Farbe „Engel" + Streak-Stil „Heiligenschein" ------------------
           Das Set hat EINE Quelle: den Erfolg technik_engel, vergeben beim Abhaken eines
           Fehler-Tickets. Fällt dieser eine Aufruf weg, kann ihn niemand mehr verdienen, und
           beide Belohnungen wären für alle künftigen Mitglieder tot – ohne dass etwas rot wird. */
        st_expect((flame_styles_unlock()['schein'] ?? '') === 'technik_engel',
            'Der Heiligenschein hängt nicht mehr am Erfolg „technik_engel"');
        st_expect((avatar_palettes()['engel']['ach'] ?? '') === 'technik_engel',
            'Die Farbe „Engel" hängt nicht mehr am Erfolg „technik_engel"');
        $engBel = array_merge([$cat['technik_engel']['reward'] ?? []], $cat['technik_engel']['rewards'] ?? []);
        $engHat = fn(string $typ, string $key) => (bool)array_filter($engBel,
            fn($b) => ($b['type'] ?? '') === $typ && ($b['key'] ?? '') === $key);
        st_expect($engHat('palette', 'engel') && $engHat('flame', 'schein') && $engHat('deco', 'halo'),
            'Der Erfolg „Technik-Engel" gibt nicht mehr alle drei Teile des Sets heraus (Farbe + Streak-Stil + Schmuck)');
        // Der Heiligenschein-Schmuck hat ABSICHTLICH zwei Quellen: „Hutsammlung" und „Technik-Engel".
        // Wer die zweite für einen Fehler hält und sie herausnimmt, nimmt dem Paket sein Stück.
        st_expect(($cat['hat_trick']['reward']['key'] ?? '') === 'halo',
            'Der Schmuck „halo" hängt nicht mehr an der „Hutsammlung" – die zweite Quelle im Engel-Paket ersetzt sie nicht');
        $qBugs = (string)preg_replace('~//[^\n]*~', '', (string)@file_get_contents(__DIR__ . '/bugs.php'));
        st_expect((bool)preg_match("~'done'[^;]*achievement_unlock~", $qBugs),
            'admin/bugs.php vergibt beim Abhaken keinen „Technik-Engel" mehr – dann ist das Set nicht mehr zu verdienen');
        st_expect(!preg_match("~'open'[^;]*achievement_unlock~", $qBugs),
            'admin/bugs.php vergibt den „Technik-Engel" auch beim Wieder-Öffnen – der Erfolg wäre beliebig oft zu holen');
        // Die Einmal-Gabe an die Technik ist eine Starthilfe: Erledigt-Markierungen von früher
        // stehen nirgends fest, rückwirkend ist also nichts zu erkennen. Sie darf nur an is_admin
        // gehen – als Dauerregel „wer Technik ist, hat es" wäre der Erfolg wertlos.
        $engGabe = preg_match('~function member_reward_skins\(.*?\n\}~s', $qLibAch, $mEng) ? $mEng[0] : '';
        st_expect((bool)preg_match("~role FROM members.*?=== 'admin'.*?technik_engel~s", $engGabe),
            'lib.php: die einmalige Gabe des Engel-Sets ist weg oder hängt nicht mehr an der Rolle Technik/Admin');
        // Der Vorsitz darf Tickets abhaken (can_manage_bugs = can_admin), soll das Set aber nicht
        // bekommen. Ohne die Rollen-Bedingung in bugs.php holt er es sich mit einem Klick.
        st_expect(str_contains($qBugs, "current_role() === 'admin'"),
            'admin/bugs.php: der „Technik-Engel" geht wieder an alle mit Verwaltungsrechten – auch an den Vorsitz');
        $scheinSvg = flame_svg('schein');
        st_expect(str_contains($scheinSvg, 'aschein') && substr_count($scheinSvg, '<ellipse') === 2,
            'Der Heiligenschein braucht genau ZWEI Ellipsen: satte Kante und helle Innenlinie');
        // Der Lichtschein kommt aus dem drop-shadow von .aflame. Eine gefüllte Ellipse dahinter
        // war schon einmal drin und sah auf hellem Kartengrund aus wie ein Fleck, in dem der Ring
        // sitzt – deshalb muss jede Stufe --fl-glow setzen und keine Schein-Fläche zeichnen.
        st_expect(!str_contains($scheinSvg, 'sc-glow'),
            'Der Heiligenschein hat wieder eine gefüllte Schein-Fläche – auf hellen Karten ist das ein Fleck, kein Leuchten');
        st_expect((bool)preg_match('~\.schein\.st1[^}]*--fl-glow~', $qCssShop),
            'style.css: die Stufen des Heiligenscheins setzen kein --fl-glow – dann leuchtet er gar nicht');
        foreach (['.aschein .sc-r1', '.schein.st1', '.schein.st5', '@keyframes sc-kipp',
                  '.aschein .sc-k1'] as $scSel) {
            st_expect(str_contains($qCssShop, $scSel),
                'style.css fehlt „' . $scSel . '" – ohne das ist der Heiligenschein ein grauer Kasten oder er steht still');
        }
        /* Der Lichtkegel ist der EIGENE Effekt dieses Stils – ohne ihn ist der Ring das einzige
           Zeichen ohne Bewegungsidee. Er steht VOR dem Ring im Markup (sonst läge er darüber)
           und bekommt seine Farbe aus dem Stylesheet, damit sie mit der Stufe wechselt. */
        st_expect(str_contains($scheinSvg, 'class="sc-kegel"') && str_contains($scheinSvg, 'url(#sc-licht)'),
            'Dem Heiligenschein fehlt der Lichtkegel');
        // Mit der Klammer geprüft: „@keyframes sc-licht" allein passt auch noch auf einen
        // umbenannten „sc-lichtX" – der Wächter meldete den Wegfall dann nicht.
        st_expect(str_contains($qCssShop, '@keyframes sc-licht {') && str_contains($qCssShop, 'animation: sc-licht '),
            'style.css: das Atmen des Lichtkegels (sc-licht) ist weg oder wird nicht mehr aufgerufen');
        st_expect(strpos($scheinSvg, 'sc-kegel') < strpos($scheinSvg, 'sc-ring'),
            'Der Lichtkegel muss VOR dem Ring stehen – sonst liegt er über ihm statt dahinter');
        st_expect(!str_contains($scheinSvg, 'stop-color'),
            'Die Farbe des Lichtkegels gehört ins Stylesheet (stop-color an .sc-k1/.sc-k2), nicht in die Zeichnung – sonst bleibt sie auf allen Stufen gleich');
        foreach (['st1', 'st2', 'st3', 'st4', 'st5'] as $scStufe) {
            st_expect((bool)preg_match('~\.schein\.' . $scStufe . '\s*\{[^}]*--sc-kegel~', $qCssShop),
                'style.css: der Stufe ' . $scStufe . ' des Heiligenscheins fehlt --sc-kegel – der Kegel wüchse nicht mit');
        }
        // Nacht-Kette: ohne die beiden Spalten zählt „Mondsüchtig" nie hoch.
        $mSpalten = array_column(db()->query('PRAGMA table_info(members)')->fetchAll(), 'name');
        st_expect(in_array('night_run', $mSpalten, true) && in_array('night_last', $mSpalten, true),
            'Der members-Tabelle fehlen night_run/night_last – „Mondsüchtig" kann nicht zählen');
        st_expect(str_contains($qLibAch, "if (\$h < 5) {") && str_contains($qLibAch, "'mondsucht'"),
            'lib.php: die Nacht-Kette (0–5 Uhr) wird nicht mehr gezählt');
        // Wackelaugen: Ohne die zwei rollenden Pupillen sind es nur zwei weiße Kreise.
        $wack = avatar_deco_svg('wackelaugen');
        st_expect(substr_count($wack, 'class="wa-p') === 2, 'Den Wackelaugen fehlt eine Pupille (zwei erwartet)');
        st_expect(str_contains($qCssShop, '@keyframes wa-rollen') && str_contains($qCssShop, '.acc-k-wackelaugen .wa-p2'),
            'style.css: den Wackelaugen fehlt das Rollen oder der Versatz zwischen beiden Augen');
        // Ringplanet: Der Witz des Stücks ist, dass der Ring VORNE und HINTEN durchläuft. Ohne die
        // Maske (hinterer Teil) oder ohne den ungemaskten unteren Bogen (vorderer Teil) wäre es nur
        // ein Ring, der auf dem Gesicht klebt. Beides muss im SVG stehen, dazu der z-index im CSS.
        $ringp = avatar_deco_svg('ringplanet');
        st_expect(str_contains($ringp, 'mask="url(#acc-ringmask)"') && str_contains($ringp, 'A66 21 0 0 0'),
            'Ringplanet: es fehlt der maskierte (hintere) oder der vordere Teil des Rings');
        st_expect((bool)preg_match('~\.avatar-acc\.acc-k-ringplanet\s*\{[^}]*z-index:\s*[2-9]~', $qCssShop),
            'style.css: der Ringplanet braucht einen z-index über dem Avatar, sonst verschwindet der vordere Ringteil');
        // Regenbogen-Streak: Die Stufen zeigen NICHT mehr vom Gleichen, sondern mehr Farben – dafür
        // müssen alle sechs Streifen im SVG stecken und jede Stufe ihre Sichtbarkeiten setzen.
        $rb = flame_svg('regenbogen');
        st_expect(substr_count($rb, 'class="rb-b rb-b') === 6, 'Dem Regenbogen fehlen Farbstreifen (sechs erwartet)');
        st_expect(str_contains($rb, 'rb-neben') && str_contains($rb, 'rb-glanz'),
            'Dem Regenbogen fehlt der Nebenbogen oder der Lichtwisch (beides gehört den obersten Stufen)');
        for ($t = 0; $t <= 5; $t++) {
            st_expect((bool)preg_match('~\.regenbogen\.st' . $t . '\s*\{[^}]*--rb-o1~', $qCssShop),
                "style.css: dem Regenbogen fehlt Stufe $t (--rb-o1 …)");
        }
        st_expect(str_contains($qCssShop, '--rb-neben: .6'), 'style.css: der Nebenregenbogen darf erst auf Stufe 5 auftauchen');
        // Regenwölkchen: Das Stück lebt von der Bewegung – ohne Fallweg und Position hängt eine
        // graue Wolke reglos im Nichts. Beides steckt in style.css, das SVG liefert nur die Teile.
        $wolke = avatar_deco_svg('wolke');
        st_expect(substr_count($wolke, 'class="wolk-t') === 3, 'Dem Regenwölkchen fehlen Tropfen (drei erwartet)');
        st_expect(str_contains($qCssShop, '.avatar-acc.acc-k-wolke') && str_contains($qCssShop, '@keyframes wolk-tropf')
            && str_contains($qCssShop, '@keyframes wolk-schweben'),
            'style.css: dem Regenwölkchen fehlt die Position oder der Regen');
        // Status-Ring am Avatar: Die Regel lag NUR im Dashboard und galt deshalb
        // auch nur dort. Jetzt steht sie in lib.php und wird von Hero und Profil gelesen – und die
        // CSS-Regeln dürfen nicht mehr auf .hero beschränkt sein, sonst bleibt das Profil blass.
        st_expect(function_exists('avatar_ring_class') && avatar_ring_class(0) === '',
            'avatar_ring_class() fehlt oder vergibt ohne Mitglied einen Ring');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../profil.php'), 'avatar_ring_class('),
            'profil.php zeigt den Status-Ring nicht mehr');
        st_expect(str_contains($qCssShop, '.gt-av.avatar-spitze'),
            'style.css: der Status-Ring gilt wieder nur im Hero – auf Profilen wäre er unsichtbar');
        // Es bleibt bei EINEM Ring („der ist doof, es verbleibt nur der von
        // royal"). Der Gold-Ring für die Top 3 der Schichten ist raus – das Achievement
        // top3_shifts bleibt, nur der Reifen am Avatar nicht.
        st_expect(!str_contains($qCssShop, 'ring-gold')
                && !str_contains((string)@file_get_contents(__DIR__ . '/../lib.php'), 'ring-gold'),
            'der Gold-Ring für die Top 3 der Schichten ist zurück – der wurde ausdrücklich gestrichen');
        // Die Streak-Aura hängt an vier Stellen: Dashboard-Hero, Streak-Karte, Meilenstein-Overlay
        // und der Profil-Kopfkarte. Wer eine davon vergisst, merkt es nie,
        // weil unter Stufe 4 ohnehin nichts zu sehen ist.
        foreach (['dashboard.php', 'achievements.php', 'profil.php'] as $auraSeite) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../' . $auraSeite), 'streak_aura_class('),
                "$auraSeite zeigt die Streak-Aura nicht mehr an");
        }
        st_expect((bool)preg_match('~\.profil-hero\.aura-feld\s*\{[^}]*position:\s*relative~', $qCssShop),
            'style.css: der Profil-Kopfkarte fehlt position: relative – der Teilchen-Layer säße sonst irgendwo');
        // Bewegungs-Ebenen (::before/::after mit transform): Am Ende eines Durchlaufs müssen
        // Winkel UND Versatz wieder zum Anfang passen, sonst ruckt es sichtbar. Geprüft wird der
        // erste gegen den letzten Keyframe.
        // Herbstlaub loopt NICHT über eine Kachel, sondern über die Deckkraft: Anfang und Ende
        // sind unsichtbar, dazwischen fällt und dreht sich ein einzelnes Blatt. Deshalb hier nur
        // die Bedingung, die dort zählt.
        if (preg_match('~@keyframes pal-herbstlaub\s*\{((?:[^{}]|\{[^{}]*\})*)\}~', $qCssShop, $mHl)) {
            st_expect((bool)preg_match('~0%[^}]*opacity:\s*0~', $mHl[1]) && (bool)preg_match('~100%[^}]*opacity:\s*0~', $mHl[1]),
                'Herbstlaub: Das Blatt muss unsichtbar starten UND enden, sonst springt es beim Neustart ins Bild');
        }
        foreach (['konfetti' => 56] as $pk => $kachel) {
            if (!preg_match('~@keyframes pal-' . $pk . '\s*\{((?:[^{}]|\{[^{}]*\})*)\}~', $qCssShop, $m)) continue;
            preg_match_all('~transform:\s*translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)\s*rotate\((-?[\d.]+)deg\)~', $m[1], $tf, PREG_SET_ORDER);
            if (count($tf) < 2) continue;
            $a = $tf[0]; $b = $tf[count($tf) - 1];
            st_expect((float)$a[1] === (float)$b[1] && (float)$a[3] === (float)$b[3],
                "Farbe $pk: Die Bewegungs-Ebene endet mit anderem Winkel oder Seitenversatz als sie beginnt – das ruckt bei jedem Durchlauf");
            st_expect(fmod(abs((float)$b[2] - (float)$a[2]), $kachel) < 0.01,
                "Farbe $pk: Der Fallweg ist kein Vielfaches der Kachel ($kachel px)");
        }
        // ---- Kachel-Animationen: nahtloser Loop, automatisch nachgerechnet -----------------
        // Wer ein Muster verschiebt, muss es über den GANZEN Durchlauf um ein ganzzahliges
        // Vielfaches der Kachel bewegen – sonst steht am Ende ein anderes Bild als am Anfang, und
        // bei jedem Neustart springt es sichtbar („es laggt"). Das war bei vier
        // Farben falsch. Gerechnet wird die DIFFERENZ zwischen dem ersten und dem letzten
        // Keyframe; Zwischenschritte dürfen beliebig liegen.
        foreach (avatar_palettes() as $pk => $pdef) {
            if (empty($pdef['anim'])) continue;
            if (!preg_match('~\.pal-' . preg_quote($pk, '~') . '\s*\{[^}]*background-size:\s*([^;!]+)~', $qCssShop, $mGr)) continue;
            $groessen = [];
            foreach (explode(',', $mGr[1]) as $ebene) {
                $groessen[] = preg_match('~(\d+(?:\.\d+)?)px\s+(\d+(?:\.\d+)?)px~', trim($ebene), $mE)
                    ? [(float)$mE[1], (float)$mE[2]] : null;
            }
            if (!preg_match('~@keyframes pal-' . preg_quote($pk, '~') . '\s*\{((?:[^{}]|\{[^{}]*\})*)\}~', $qCssShop, $mKf)) continue;
            preg_match_all('~background-position:\s*([^;]+);~', $mKf[1], $mPos);
            if (count($mPos[1]) < 2) continue;
            $ersteZeile = explode(',', reset($mPos[1]));
            $letzteZeile = explode(',', end($mPos[1]));
            foreach ($groessen as $i => $kachel) {
                if ($kachel === null || !isset($ersteZeile[$i], $letzteZeile[$i])) continue;
                if (!preg_match('~(-?\d+(?:\.\d+)?)px\s+(-?\d+(?:\.\d+)?)px~', trim($ersteZeile[$i]), $mA)) continue;
                if (!preg_match('~(-?\d+(?:\.\d+)?)px\s+(-?\d+(?:\.\d+)?)px~', trim($letzteZeile[$i]), $mB)) continue;
                [$kw, $kh] = $kachel;
                $wegX = abs((float)$mB[1] - (float)$mA[1]);
                $wegY = abs((float)$mB[2] - (float)$mA[2]);
                st_expect(($kw <= 0 || fmod($wegX, $kw) < 0.01) && ($kh <= 0 || fmod($wegY, $kh) < 0.01),
                    "Farbe $pk: Der Weg über den ganzen Durchlauf ({$wegX}px/{$wegY}px) ist kein Vielfaches der Kachel ({$kw}×{$kh}) – das Muster springt beim Neustart");
            }
        }
        // Locker-Layout: ein Raster mit gleichen Spalten, keine festen Kachelbreiten – sonst
        // verspringen die Spalten auf dem Handy von Zeile zu Zeile.
        st_expect((bool)preg_match('~\.deco-locker\s*\{[^}]*display:\s*grid~', $qCssShop),
            'style.css: der Belohnungs-Locker muss ein Raster sein (.deco-locker mit display: grid), sonst verrutschen die Spalten auf dem Handy');
        st_expect(!preg_match('~\.(deco-opt|shop-opt)[^{]*\{[^}]*width:\s*\d+px~', $qCssShop),
            'style.css: Locker-Kacheln dürfen keine feste Pixelbreite haben – die Breite kommt aus dem Raster');
        // Startguthaben: 4.000 AsT für alle ECHTEN Mitglieder (auch Neulinge) – gerechnet, nie
        // gespeichert; ohne Mitglied bleibt das Konto leer.
        st_expect(defined('AST_STARTGUTHABEN') && AST_STARTGUTHABEN === 4000 && ast_balance(0) === 0,
            'das AsT-Startguthaben muss 4.000 betragen und nur echten Mitgliedern gehören');
        // Kauf-Paletten/-Stile ohne Kauf: nicht wählbar, nicht freigeschaltet (Leser 0-sicher)
        st_expect(!isset(member_palettes(0)['bulle']) && member_set_palette(0, 'bulle') === false,
            'Kauf-Farben dürfen ohne Kauf nicht wählbar sein');
        st_expect(!flame_style_allowed(0, 'rakete') && !flame_style_allowed(0, 'diamant'),
            'Kauf-Streak-Stile dürfen ohne Kauf nicht freigeschaltet sein');
        // EINE Quelle für alles Gekaufte: shop_available() (Käufe + Diagnose-Testmodus). Wer daran
        // vorbei direkt shop_owned() abfragt, sperrt den Testmodus wieder aus – genau das ist bei
        // den Kauf-Streak-Stilen schon passiert.
        $qLib2 = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect(function_exists('shop_available') && shop_available(0) === [], 'shop_available() fehlt oder ist ohne Mitglied nicht leer');
        // Die drei Verbraucher (Schmuck, Farben, Streak-Stile) müssen aus shop_available() lesen;
        // shop_owned() bleibt nur dem Kaufen/Beschlagnahmen (dort zählt echter Besitz).
        st_expect(substr_count($qLib2, 'shop_available($memberId)') >= 3,
            'Freischaltungen laufen nicht mehr über shop_available() – dann sperrt der Diagnose-Testmodus wieder aus');
        st_expect(str_contains($qLib2, 'in_array($style, flame_styles_shop(), true)) return isset(shop_available($memberId)[$style])'),
            'flame_style_allowed() liest die Kauf-Stile nicht mehr aus shop_available()');
        // KEINE feste Anzahl: Die würde beim nächsten Kauf-Stil rot, obwohl nichts kaputt ist.
        // Geprüft gehört, dass BEIDE Quellen zusammenlaufen.
        st_expect(flame_styles_all() === array_merge(array_keys(flame_styles_unlock()), flame_styles_shop())
            && flame_styles_shop() !== [],
            'flame_styles_all() muss verdiente UND kaufbare Stile führen (sonst gilt ein Kauf-Stil irgendwo als unbekannt)');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../achievements.php'), 'flame_styles_all()'),
            'achievements.php prüft die Stile nicht mehr gegen die Gesamtliste');
        // Die Schema-Migration räumt unbekannte Stile weg – stünde dort nur flame_styles_unlock(),
        // löschte sie bei JEDEM Seitenaufruf einen gewählten Kauf-Stil wieder (genau so passiert:
        // gespeichert war er, angezeigt wurde die Flamme).
        st_expect(str_contains($qLib2, "\$valid = array_merge([''], flame_styles_all());"),
            'die Streak-Stil-Migration kennt die Kauf-Stile nicht – sie würde sie bei jedem Aufruf löschen');
        $bekannt = array_merge([''], flame_styles_all());
        $fremd = (int)db()->query("SELECT COUNT(*) FROM members WHERE flame_style NOT IN ('"
            . implode("','", array_map(static fn ($s) => str_replace("'", "''", (string)$s), $bekannt)) . "')")->fetchColumn();
        st_expect($fremd === 0, $fremd . ' Mitglied(er) tragen einen Streak-Stil, den es nicht (mehr) gibt');
        st_expect(ast_balance(0) === 0 && ast_depot(0) === 0 && shop_owned(0) === [] && royal_claim_last(0) === '',
            'AsT-Markt: Leser ohne Mitglied müssen 0/leer liefern');
        st_expect(!shop_buy(0, 'zwille')['ok'] && !shop_buy(1, 'gibtsnicht')['ok'],
            'AsT-Markt: Kauf ohne Mitglied bzw. mit unbekanntem Stück muss ablehnen');
        st_expect(!shop_claim(0, 'zwille')['ok'], 'AsT-Markt: Beschlagnahme ohne Mitglied muss ablehnen');
        st_expect((bool)db()->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='shop_purchases_uni'")->fetchColumn(),
            'AsT-Markt: der UNIQUE-Index gegen Doppelkäufe fehlt');
        // Der Hausbaum steht HINTER dem Avatar: Masken-Kreis stanzt die
        // Bubble aus (wie bei der aufgehenden Sonne) + eigene Größenregel im CSS – ohne die
        // Regel sprengte das SVG die Locker-Kachel (schon passiert).
        st_expect(str_contains(avatar_deco_svg('baum'), 'mask'), 'der Hausbaum hat seine Ausstanz-Maske verloren – er stünde VOR dem Avatar');
        // ETF-Sparpläne des Schicht-Depots: deterministisch gewürfelt, drei Risikostufen,
        // Leser ohne Mitglied bleiben folgenlos. (Verfügbar = Depot − Käufe − etf_flow.)
        st_expect(count(etf_plans()) === 3, 'es müssen genau drei Sparpläne sein (niedrig/mittel/hoch)');
        $etfVols = array_values(array_column(etf_plans(), 'vol'));
        st_expect($etfVols[0] < $etfVols[1] && $etfVols[1] < $etfVols[2], 'die Sparplan-Risiken müssen aufsteigend sortiert sein');
        st_expect(abs(etf_index('defensiv') - etf_index('defensiv')) < 0.0001 && etf_index('defensiv') > 0,
            'etf_index() muss deterministisch und positiv sein');
        st_expect(etf_index('gibtsnicht') === 100.0, 'ein unbekannter Sparplan muss neutral (100,00) bleiben');
        st_expect(etf_flow(0) === 0 && !etf_invest(0, 'defensiv', 100)['ok']
            && !etf_invest(1, 'defensiv', 10)['ok'] && !etf_sell(0, 'defensiv')['ok'],
            'Sparpläne: ohne Mitglied bzw. unter der Mindestanlage muss abgelehnt werden');
        $qCss2 = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        foreach (['baum', 'vogelnest', 'zwille', 'wanderstock', 'bernstein'] as $dk2) {
            st_expect(str_contains($qCss2, '.acc-k-' . $dk2), "style.css: Größenregel für das Kauf-Stück $dk2 fehlt");
        }
        $s = member_streak(0);
        st_expect(isset($s['current'], $s['best']), 'Streak-Felder unvollständig');
        // Streak-Pause (vorlesungsfreie Zeit) + Abwesenheits-Kulanz: read-only – streak_maintain_break() NICHT aufrufen (mutiert die DB)
        st_expect(streak_alive('') === false && streak_alive('1990-01-01') === false && streak_alive_for(0, '') === false, 'Uralter/leerer Streak-Tag darf nie „lebendig" sein');
        st_expect(member_absent_days_between(0, '2026-01-01', '2026-02-01') === 0, 'Abwesenheits-Kulanz ohne Mitglied muss 0 Tage liefern');
        st_expect(is_bool(in_lecture_break()) && is_string(streak_break_since()) && function_exists('streak_maintain_break') && function_exists('streak_alive_for'), 'Streak-Pause-/Abwesenheits-Funktionen müssen vorhanden & aufrufbar sein');
        // Vorlesungsfreie Zeit: manuelles Enddatum + 7-Tage-Schonfrist (nur lesende Aufrufe;
        // lecture_break_set nur mit UNGÜLTIGEN Daten – die lehnt sie ab, ohne etwas zu speichern)
        st_expect(is_string(lecture_break_until()) && is_string(lecture_break_grace_until()) && is_bool(in_streak_grace()) && is_bool(lecture_break_pending_for_sekki()) && function_exists('lecture_break_end_now'), 'Vorlesungsfrei-Funktionen (Enddatum + Schonfrist) müssen vorhanden sein');
        st_expect(lecture_break_set('quatsch') === false && lecture_break_set('2000-01-01') === false, 'Ungültiges/vergangenes Enddatum darf nicht angenommen werden');
        st_expect(STREAK_GRACE_DAYS === 7, 'Schonfrist muss 7 Tage betragen');
        // Einmal-Flammen-Bonus: nur Existenz prüfen – NIE hier aufrufen (würde beim ersten Mal die DB anfassen)
        st_expect(function_exists('streak_grant_bonus_once'), 'Einmal-Bonus-Funktion muss vorhanden sein');
        // Admin-Streak-Werkzeug: ungültige Eingaben müssen ablehnen, OHNE zu schreiben
        st_expect(streak_admin_set(0, 5) === false && streak_admin_set(1, -1) === false, 'Streak-Anpassung: ohne Person/negativ darf nichts gesetzt werden');
        st_expect(achievement_unlock(0, 'kein_solches') === false, 'Unbekannter Code darf nicht freischalten');
        st_expect(member_equip_deco(0, 'nicht_frei') === false && member_unequip_deco(0, 'crown') === false, 'Slot-Schmuck: Nicht-Freigeschaltetes/Nicht-Getragenes muss abgelehnt werden');
        st_expect(member_equipped_decos(0) === [], 'member_equipped_decos ohne Mitglied muss leer sein');
        st_expect(array_keys(avatar_slot_labels()) === ['head', 'hand', 'neck', 'face', 'back', 'under'], 'Slot-Labels müssen alle sechs Trage-Positionen kennen (inkl. „back" und „under")');
        // König:in des Sommers: aufgehende Sonne (back-Slot) + Auto-Skin + Schenk-Funktion
        st_expect(!empty($cat['sommerkoenig']['auto_apply']) && ($cat['sommerkoenig']['reward']['type'] ?? '') === 'skin', '„sommerkoenig" muss auto_apply mit Skin-Belohnung sein (Skin wird einmalig automatisch angewandt)');
        st_expect((($cat['sommerkoenig']['rewards'][1]['key'] ?? '') === 'sunrise') && avatar_deco_slot('sunrise') === 'back' && avatar_deco_svg('sunrise') !== '', '„sommerkoenig" muss die aufgehende Sonne (Schmuck „sunrise", back-Slot) mitbringen');
        // Pending-Auto-Skin + Skin-Geschenke: ohne Mitglied/Freischaltung safe ablehnen
        member_queue_skin(0, 'sommer'); member_clear_pending_skin(0); // ohne Mitglied kein Fatal
        st_expect(member_pending_skin(0) === '' && member_granted_skins(0) === [], 'Auto-Skin/Skin-Geschenke ohne Mitglied müssen leer sein');
        st_expect(sommer_gift_quota(0) === 0 && sommer_gift(0, 0, 'x') === false && has_sommer_skin(0) === false, 'Sommer-Geschenk ohne Mitglied/Kontingent muss abgelehnt werden');
        st_expect(is_array(sommer_gift_candidates(0)) && is_array(sommer_gifts_unseen(0)), 'Sommer-Geschenk-Listen müssen immer Arrays liefern');
        sommer_gift_mark_seen(0, 0); // ohne Mitglied kein Fatal
        // Avatar-Chip (Get-Togethers, Hall of Fame, Abwesenheits-Karte): Grundstruktur + royale Titel
        $chip = avatar_chip(['name' => 'Anna Muster', 'pronouns' => 'sie/ihr', 'avatar_decos' => 'coffee', 'avatar_palette' => ''], true);
        st_expect(str_contains($chip, 'gt-av') && str_contains($chip, 'acc-k-coffee') && str_contains($chip, 'ti-crown') && str_contains($chip, 'Queen Anna'), 'avatar_chip muss Avatar, Schmuck, Krone und Queen-Titel rendern');
        st_expect(!str_contains(avatar_chip(['name' => 'Max Muster']), 'ti-crown'), 'avatar_chip ohne royal darf keine Krone zeigen');
        // avatar_bubble: nur die Bubble (Verlauf + Schmuck), ohne Namen/Krone – z. B. Nachrichten-Absender
        $bub = avatar_bubble(['name' => 'Max Muster', 'avatar_decos' => 'halo', 'avatar_palette' => ''], ' msg-av');
        st_expect(str_contains($bub, 'gt-av') && str_contains($bub, 'msg-av') && str_contains($bub, 'acc-k-halo') && !str_contains($bub, 'Max'), 'avatar_bubble muss die Bubble mit Schmuck, aber OHNE Namen liefern');
        // Nutzerprofile: Links, Rollen-Badges, Profil-Texte (nur ungültige Werte → keine DB-Schreibzugriffe)
        st_expect(member_profile_url(7) === 'profil.php?id=7', 'member_profile_url muss auf profil.php?id=… zeigen');
        st_expect(str_contains(member_link(['id' => 5, 'name' => 'Max Muster']), 'profil.php?id=5'), 'member_link muss den Namen aufs Profil verlinken');
        st_expect(!str_contains(member_link(['id' => 0, 'name' => 'Max Muster']), '<a '), 'member_link ohne gültige id darf keinen Link bauen');
        st_expect(str_contains(member_chip_link(['id' => 5, 'name' => 'Anna Muster']), 'chip-link') && str_contains(member_chip_link(['id' => 5, 'name' => 'Anna Muster']), 'gt-av'), 'member_chip_link muss Chip samt Profil-Link rendern');
        st_expect(member_role_badges(['role' => 'member']) === '', 'normale Mitglieder haben keine Sonderfunktions-Pill');
        st_expect(str_contains(member_role_badges(['role' => 'vorsitz']), 'Vorsitz'), 'Vorsitz muss eine Rollen-Pill bekommen');
        // Kurzform, damit die Pill neben langen Namen nicht umbricht – Langform bleibt im title
        $sekkiPill = member_role_badges(['role' => 'sekretariat']);
        st_expect(str_contains($sekkiPill, '>Sekki<') || str_contains($sekkiPill, ' Sekki<'), 'Sekretariats-Pill muss die Kurzform „Sekki" tragen');
        st_expect(str_contains($sekkiPill, 'title="Sekretariat"'), 'Sekretariats-Pill muss „Sekretariat" im title behalten');
        $adminPill = member_role_badges(['role' => 'admin']);
        st_expect(str_contains($adminPill, ' Technik<'), 'Admin-Pill muss die Kurzform „Technik" tragen');
        st_expect(str_contains($adminPill, 'title="Admin &amp; Technik"'), 'Admin-Pill muss „Admin & Technik" im title behalten');
        st_expect(member_save_profile_texts(0, 'x', 'y') === false, 'Profil-Texte ohne gültige id dürfen nicht gespeichert werden');
        // Mitglieder-Kachel: EINE Funktion für Liste und Vorschau – sonst zeigt die Vorschau in der
        // Profil-Bearbeitung irgendwann etwas anderes als die Mitgliederliste.
        st_expect(member_card_text("A\n\n\nB\r\n\r\nC") === "A\nB\nC", 'member_card_text muss Absätze zu einfachen Zeilenumbrüchen zusammenziehen');
        $probe = ['id' => 999999, 'name' => 'Test Person', 'role' => 'member', 'referat' => 'Kultur',
                  'referat_desc' => "Referatstext\n\nzweiter Absatz", 'about_me' => 'Über mich'];
        $karte = member_card_html($probe, ['referat' => true, 'viewer' => 1, 'given' => [], 'quota' => 0]);
        st_expect(str_contains($karte, 'mem-card') && str_contains($karte, 'Referat Kultur'), 'member_card_html muss die Kachel samt Referat rendern');
        st_expect(str_contains($karte, "Referatstext\nzweiter Absatz"), 'Kachel-Text muss ohne Leerzeile zwischen den Absätzen stehen');
        st_expect(!str_contains($karte, 'Über mich'), '„Über mich" darf nur einspringen, wenn kein Referatstext da ist');
        st_expect(!str_contains($karte, 'mem-leer'), 'mit Text darf der Leer-Hinweis nicht im Markup stehen');
        // Vorschau: beide Textzustände im Markup (das Skript schaltet live um), aber keine Props-Taste
        $vor = member_card_html($probe, ['referat' => true, 'viewer' => 999999, 'preview' => true]);
        st_expect(str_contains($vor, 'mem-quote') && str_contains($vor, 'mem-leer'), 'Vorschau-Kachel muss Text UND Leer-Hinweis enthalten (einer davon hidden)');
        st_expect(str_contains($vor, 'mem-leer" hidden') || str_contains($vor, 'mem-leer\' hidden'), 'in der Vorschau mit Text muss der Leer-Hinweis hidden sein');
        st_expect(!str_contains($vor, 'kudo-btn'), 'die Vorschau darf keine Props-Taste zeigen (das Formular gehört zur Mitgliederliste)');
        // Kartenkörper: Text UND Zahlen müssen darin liegen, und die Verschachtelung muss
        // aufgehen – ein unausgeglichenes div zerlegt die ganze Mitgliederliste.
        st_expect(str_contains($karte, 'mem-koerper'), 'Kachel muss den Kartenkörper um Text und Zahlen legen');
        st_expect(str_contains($karte, 'mem-meta" title="'), 'Metazeile braucht den vollen Wortlaut im title (sie wird einzeilig gekürzt)');
        // Status-Badges sind nur Zeichen – der Klartext muss deshalb in title UND aria-label stehen
        $frisch = member_card_html(['id' => 999999, 'name' => 'Neu Person', 'referat' => 'Kultur',
                                    'birthday' => date('m-d'), 'joined_at' => date('Y-m-d', strtotime('-10 days'))],
                                   ['referat' => true, 'viewer' => 1]);
        st_expect(str_contains($frisch, 'pill-zeichen') && str_contains($frisch, 'aria-label="Hat heute Geburtstag!"'),
            'Status-Badges müssen als Zeichen mit Klartext in aria-label erscheinen');
        st_expect(str_contains($frisch, 'mem-status'), 'Status-Badges gehören in die Fußzeile zwischen Streak und Erfolge');
        // Geburtstag schlägt „Neu": in der Fußzeile steht immer nur EIN Status-Badge.
        // Geprüft wird nur INNERHALB der Status-Gruppe – sonst zählt ein Mitgliedsname mit.
        preg_match('/<span class="mem-status">(.*?)<\/span><\/span>/s', $frisch, $statusBlock);
        st_expect(!empty($statusBlock[1]) && substr_count($statusBlock[1] . '</span>', '<span class="pill') === 1
            && str_contains($statusBlock[1], 'Geburtstag'),
            'nur das seltenste Status-Badge wird gezeigt (Geburtstag verdrängt Urlaub und „Neu")');
        preg_match('/<div class="profil-list".*?<\/div>/s', $frisch, $kopfzeile);
        st_expect(!empty($kopfzeile[0]) && !str_contains($kopfzeile[0], 'pill-fun') && !str_contains($kopfzeile[0], 'pill-info'),
            'in der Kopfzeile darf kein Status-Badge stehen (sie bricht sonst um und zieht die Rasterzeile höher)');
        // Spitzenklasse: der ausgeschriebene Titel bleibt dort, wo Platz ist (Dashboard, Profil …);
        // die Kachel darf ihn nicht in die Kopfzeile schreiben – sie trägt die Krone selbst.
        st_expect(str_contains(avatar_chip(['name' => 'Nika Muster', 'pronouns' => 'sie/ihr'], true), 'Queen'),
            'avatar_chip muss den King/Queen-Titel weiterhin können');
        st_expect(!str_contains($karte, 'Queen') && !str_contains($karte, 'King '),
            'die Mitglieder-Kachel darf keinen King/Queen-Titel in die Kopfzeile schreiben');
        st_expect(substr_count($karte, '<div') === substr_count($karte, '</div>'), 'Kachel muss ausgeglichene div-Klammern liefern');
        $ohneText = member_card_html(['id' => 999999, 'name' => 'Test Person', 'referat' => 'Kultur'], ['referat' => true, 'viewer' => 1]);
        st_expect(str_contains($ohneText, 'mem-leer') && !str_contains($ohneText, 'ti-pencil-off'),
            'leerer Steckbrief bekommt den einladenden Hinweis ohne durchgestrichenen Stift');
        $profilQuelle = @file_get_contents(__DIR__ . '/../profil.php');
        st_expect(is_string($profilQuelle) && str_contains($profilQuelle, "member_card_html(\$p") && str_contains($profilQuelle, 'pf_vorschau'),
            'profil.php muss die Kachel-Vorschau über member_card_html rendern');
        st_expect(meeting_rsvp_members(0) === ['abgemeldet' => [], 'online' => []], 'meeting_rsvp_members ohne Sitzung muss leer sein');
        // „Mitglied löschen"-Bestand: Inhalte dürfen NICHT an members kaskadieren (Beschluss-Archiv,
        // Abstimmungen, Terminfinder, Termine, Belegblätter, Pinnwand-Autor:in, Skin-Geschenke-Absender)
        $memberFk = function (string $t, string $c): bool {
            foreach (db()->query("PRAGMA foreign_key_list($t)")->fetchAll() as $fk) {
                if (strcasecmp((string)$fk['from'], $c) === 0 && strcasecmp((string)$fk['table'], 'members') === 0) return true;
            }
            return false;
        };
        foreach (['expense_claims' => 'member_id', 'termine' => 'created_by', 'date_polls' => 'created_by',
                  'circular_votes' => 'created_by', 'simple_polls' => 'created_by',
                  'profile_posts' => 'author_id', 'skin_gifts' => 'from_id'] as $dt => $dc) {
            st_expect(!$memberFk($dt, $dc), "$dt.$dc darf NICHT an members hängen – Inhalte überleben das Löschen der Person");
        }
        st_expect($memberFk('profile_posts', 'profile_id'), 'profile_posts.profile_id muss kaskadieren – die Pinnwand stirbt mit dem Profil');
        // Profil-Pinnwand: nur ungültige Werte → keine DB-Schreibzugriffe
        st_expect(profile_posts_of(0) === [], 'Pinnwand ohne Profil muss leer sein');
        st_expect(profile_post_add(1, 1, 'x', false) === false, 'Pinnwand: Eintrag auf dem eigenen Profil muss abgelehnt werden');
        st_expect(profile_post_add(1, 2, '', false) === false, 'Pinnwand: leerer Text muss abgelehnt werden');
        st_expect(profile_post_delete(0, null) === false, 'Pinnwand: Löschen ohne Login/ID muss abgelehnt werden');
        st_expect(profile_post_reply(0, 1, 'x') === false && profile_post_reply(1, 2, '') === false, 'Pinnwand-Antwort: ohne Eltern-Eintrag/Text muss abgelehnt werden');
        st_expect(dm_safe_link('profil.php?id=5#pinnwand') === 'profil.php?id=5#pinnwand', 'dm_safe_link: internes Seite.php?…#… muss erlaubt sein');
        st_expect(dm_safe_link('https://evil.example/x') === '' && dm_safe_link('//evil') === '' && dm_safe_link('../secret.php') === '', 'dm_safe_link: externe/absolute Ziele müssen verworfen werden (kein Open-Redirect)');
        st_expect(dm_link_of(0, 0) === '', 'dm_link_of ohne Nachricht/Login muss leer sein');
        dm_mark_read_for_link(0, 'profil.php#pinnwand'); dm_mark_read_for_link(1, 'https://evil'); // No-Ops ( id<=0 / externes Ziel) – dürfen nicht fatal sein
        $pc = achievements_catalog();
        st_expect(isset($pc['pin_1'], $pc['pin_10'], $pc['pin_spread']), 'Pinnwand-Achievements pin_1/pin_10/pin_spread müssen im Katalog stehen');
        st_expect(isset(notify_types()['dm_profil']), 'Mitteilungs-Typ dm_profil (Profil-Pinnwand) muss registriert sein');
        // Pinnwand-Wache: Admin-only, Push ab Werk aus, Rollen-Gate greift auch serverseitig beim Setzen
        $pw = notify_types()['dm_pin_watch'] ?? null;
        st_expect($pw && ($pw['role'] ?? '') === 'admin' && empty($pw['push_default']) && $pw['mail'] === 'none', 'dm_pin_watch muss Admin-gebunden, Push-aus und mail-frei sein');
        st_expect(notify_pref_set(0, 'dm_pin_watch', 'push', 1) === false, 'Rollen-gebundene Mitteilung darf ohne Admin-Rolle nicht setzbar sein');
        st_expect(function_exists('pinnwand_notify_admins'), 'pinnwand_notify_admins() muss vorhanden sein');
        // No-Op ohne Profil: darf weder fatal sein NOCH ins Fehler-Log schreiben – ein Selbsttest,
        // der selbst Einträge erzeugt, löst genau den Alarm aus, den er finden soll.
        $logBefore = @filesize(ERROR_LOG_FILE) ?: 0;
        clearstatcache(true, ERROR_LOG_FILE);
        pinnwand_notify_admins(0, 0, 'Jemand');
        clearstatcache(true, ERROR_LOG_FILE);
        st_expect((@filesize(ERROR_LOG_FILE) ?: 0) === $logBefore, 'Pinnwand-Wache ohne Profil darf nichts ins Fehler-Log schreiben');
        // Props (anonyme Anerkennung, Mitglieder-Tab): Kontingent + Validierung read-only prüfen (nichts vergeben)
        st_expect(KUDOS_PER_MONTH === 2, 'Props-Kontingent muss 2 pro Monat sein');
        st_expect(kudos_can_give(0, 0) === false && kudos_can_give(1, 1) === false && kudos_give(0, 5) === false, 'Props: ohne Person / an sich selbst muss abgelehnt werden (kein DB-Write)');
        st_expect(kudos_quota_left(0) === 2 && kudos_given_ids(0) === [] && is_array(kudos_admin_overview()), 'Props-Leser-Funktionen müssen ohne Mitglied safe sein');
        st_expect(kudos_take_unseen(0) === 0 && kudos_received_total(0) === 0, 'Props-Toast/-Zähler ohne Person müssen 0 liefern (kein DB-Write)');
        // Im eigenen Profil zählt das Erhaltene, nicht der Kontostand: Wie viele Props noch frei
        // sind, sagt der Belohnungs-Locker – hier steht die Anerkennung.
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../profil.php'), 'Props</strong> bekommen')
            && !str_contains((string)@file_get_contents(__DIR__ . '/../profil.php'), 'Props</strong> übrig'),
            'profil.php: die Props-Zeile muss die insgesamt ERHALTENEN nennen („Du hast insgesamt X Props bekommen"), nicht die übrigen');
        // Admin-Werkzeug (anonyme Vergabe/Anpassung): ungültige Eingaben müssen ablehnen, OHNE zu schreiben
        st_expect(kudos_admin_set(0, 3) === false && kudos_admin_set(1, -1) === false, 'Props-Anpassung: ohne Person / negativ darf nichts gesetzt werden');
        st_expect(pinkdream_head_decos() === ['tiara', 'pinkbow', 'curlers', 'crown'], '„Pretty in Pink" muss Diadem, Schleife, Lockenwickler und Krone als Kopfschmuck zählen');
        st_expect(pinkdream_head_ok(0) === false, 'pinkdream_head_ok ohne Person muss false sein');
        st_expect(isset(notify_types()['dm_kudos']) && empty(notify_types()['dm_kudos']['push_default']), 'Mitteilungs-Typ dm_kudos muss registriert und Push ab Werk AUS sein');
        st_expect(isset($pc['famous']) && !empty($pc['famous']['hidden']) && ($pc['famous']['reward']['key'] ?? '') === 'starshades', '„Famous" muss geheim sein + Star-Sonnenbrille geben');
        st_expect(avatar_deco_slot('starshades') === 'face' && avatar_deco_svg('starshades') !== '', 'Star-Sonnenbrille braucht SVG + face-Slot');
        // Geburtstag / Abwesenheits-Hinweis / nächstes Achievement (nur ungültige Werte → keine Schreibzugriffe)
        st_expect(member_set_birthday(0, 24, 7) === false, 'Geburtstag ohne gültige id darf nicht gespeichert werden');
        st_expect(member_set_birthday(1, 32, 1) === false && member_set_birthday(1, 1, 13) === false, 'unmögliche Geburtstage müssen abgelehnt werden');
        st_expect(birthday_label('07-24') === '24. Juli' && birthday_label('') === '' && birthday_label('kaputt') === '', 'birthday_label muss MM-TT hübsch formatieren und Müll schlucken');
        birthdays_today(); // darf nicht crashen
        st_expect(member_current_absence(0) === null, 'Abwesenheits-Hinweis ohne Mitglied muss null sein');
        st_expect(member_next_achievement(0) === null, 'nächstes Achievement ohne Mitglied muss null sein');
        // Serie „App-Öffnungen" (opens_3/10/20): Katalog + Belohnungs-Schmuck (Lockenwickler/Gras)
        $cat3 = achievements_catalog();
        st_expect(isset($cat3['opens_3'], $cat3['opens_10'], $cat3['opens_20']), 'Öffnungs-Serie opens_3/10/20 muss im Katalog stehen');
        member_count_app_open(0); // ungültige id → No-Op (Zählung kommt als Client-Signal über push.php)
        // Seitenwechsel dürfen NICHT als Öffnung zählen. Der Lade-Check allein reicht nicht:
        // Safari feuert beim Laden jeder Seite ein focus-Event nach, die Zurück-Taste stellt
        // Seiten per pageshow(persisted) wieder her – deshalb muss die Navigations-Sperre in
        // fire() selbst sitzen, und der pagehide-Stempel darf nicht gelöscht werden.
        $appJsQ = (string)@file_get_contents(__DIR__ . '/../assets/app.js');
        st_expect(str_contains($appJsQ, 'function navRecent()'),
            'app.js: die Navigations-Sperre navRecent() der Öffnungs-Zählung fehlt – dann zählt jeder Seitenwechsel als Öffnung');
        st_expect((bool)preg_match('~function fire\(\) \{\s*\n\s*if \(navRecent\(\)\) return;~', $appJsQ),
            'app.js: fire() prüft navRecent() nicht mehr als Erstes – Safari-focus/pageshow-Artefakte zählen dann wieder als Öffnung');
        // Nur der Öffnungs-Block zählt (andere Blöcke räumen IHREN Storage-Schlüssel zurecht ab):
        // vom asta-nav-at-Schlüssel bis zum Ende seiner Funktion darf kein removeItem stehen.
        $oVon = strpos($appJsQ, "'asta-nav-at'");
        $oBis = $oVon !== false ? strpos($appJsQ, "\n})();", $oVon) : false;
        $oBlock = ($oVon !== false && $oBis !== false) ? substr($appJsQ, $oVon, $oBis - $oVon) : '';
        st_expect($oBlock !== '' && !str_contains($oBlock, 'removeItem'),
            'app.js: der Öffnungs-Block löscht den pagehide-Stempel wieder – die Navigations-Artefakte nach dem Lade-Check treffen dann auf einen leeren Stempel');
        st_expect(avatar_deco_svg('curlers') !== '' && avatar_deco_slot('curlers') === 'head', 'Lockenwickler brauchen SVG + head-Slot');
        // AsT verschenken (Vorsitz/Admin, Achievements-Übersicht): Buchung + Feier-Nachricht.
        // Das Guthaben bleibt ABGELEITET – ast_balance() muss die Geschenke mitzählen.
        st_expect(function_exists('ast_granted') && ast_granted(0) === 0, 'ast_granted() fehlt oder liefert ohne Person nicht 0');
        st_expect(ast_grant(0, 100, '', 0) === false && ast_grant(1, 0, '', 0) === false && ast_grant(1, 999999, '', 0) === false,
            'ast_grant() muss ungültige Person/Beträge ablehnen (kein DB-Write)');
        st_expect(str_contains($qLibAch, '+ ast_granted($memberId)'),
            'ast_balance() zählt die geschenkten AsT nicht mehr mit – Geschenke kämen sonst nie an');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/achievements.php'), "'ast_grant'"),
            'admin/achievements.php: das Verschenk-Formular („AsT verschenken") fehlt');
        // Prisma dreht als eigene Kreis-Ebene (::before) unter den Initialen. hue-rotate ist
        // verboten: Es färbt Initialen und getragenen Schmuck mit um (goldene Krone wird grün)
        // und verschmutzt die Farben. Die Farbliste der Ebene muss der Registry entsprechen.
        $cssAll = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        st_expect(str_contains($cssAll, '.pal-prisma::before'), 'Prisma-Animation: die drehende ::before-Ebene fehlt');
        st_expect(!preg_match('~@keyframes pal-prisma[^}]*hue-rotate~', $cssAll),
            'Prisma dreht wieder per hue-rotate – das verfärbt Initialen und Schmuck und macht die Farben schmutzig');
        preg_match('~conic-gradient\(([^)]*)\)~', (string)(avatar_palettes()['prisma']['grad'] ?? ''), $mReg);
        preg_match('~\.pal-prisma::before[^}]*conic-gradient\(([^)]*)\)~s', $cssAll, $mCss);
        st_expect(($mReg[1] ?? '') !== '' && preg_replace('~\s+~', '', (string)($mReg[1] ?? 'a')) === preg_replace('~\s+~', '', (string)($mCss[1] ?? 'b')),
            'Prisma: die Farbliste der ::before-Ebene weicht von avatar_palettes() ab – beide Stellen müssen dieselben Farben tragen');
        st_expect(avatar_deco_svg('gras') !== '' && avatar_deco_slot('gras') === 'under', 'Gras (Touch Grass) braucht SVG + under-Slot');
        // Eingefrorene Ergebnisse: Snapshot-Spalten müssen existieren (Umlauf + Abstimmung)
        st_expect(in_array('result_yes', array_column(db()->query('PRAGMA table_info(circular_votes)')->fetchAll(), 'name'), true), 'circular_votes braucht die Snapshot-Spalte result_yes');
        st_expect(in_array('result_json', array_column(db()->query('PRAGMA table_info(simple_polls)')->fetchAll(), 'name'), true), 'simple_polls braucht die Snapshot-Spalte result_json');
        st_expect(!empty($cat['spitze']['dynamic']), '„spitze" muss ein dynamisches (entziehbares) Achievement sein');
        st_expect(!empty($cat['spitze']['auto_apply']), '„spitze" muss auto_apply sein (Krone/Royal direkt)');
        st_expect(achievement_revoke(0, 'spitze') === false, 'Entziehen ohne vorhandenen Eintrag darf nichts löschen');
        royal_unlocked(); // darf ohne Login nicht fatal werden (false)
        // Toast-Queue: einreihen und wieder leeren
        $before = take_ach_unlocks();
        ach_queue_unlock(['code' => 'test', 'title' => 'Test']);
        st_expect(count(take_ach_unlocks()) === 1, 'Achievement-Toast-Queue funktioniert nicht');
        // Streak-Kulanz: Kette lebt bis 5 verpasste Tage, danach reißt sie – gilt aber nur außerhalb
        // von vorlesungsfreier Zeit UND Schonfrist (dort steht die Uhr, die 5-Tage-Regel greift nicht).
        st_expect(!streak_alive('') && !streak_alive('quatsch'), 'Ohne/mit ungültigem Datum muss die Kette immer reißen');
        if (!in_lecture_break() && !in_streak_grace()) {
            st_expect(streak_alive(date('Y-m-d')) && streak_alive(date('Y-m-d', strtotime('-5 days'))), 'Kulanz: bis 5 Tage Pause muss die Kette leben');
            st_expect(!streak_alive(date('Y-m-d', strtotime('-6 days'))), 'Nach 5 verpassten Tagen muss die Kette reißen');
        }
        // Flammen-Animations-Queue: einreihen und wieder leeren
        streak_queue_gain(3);
        st_expect(take_streak_gain() === 3 && take_streak_gain() === 0, 'Streak-Animations-Queue funktioniert nicht');
    });
    $run('Basis-Score (Einordnung + Aufschlüsselung)', function () {
        basis_since();
        st_expect(basis_band(0) === 'einwandfrei' && basis_band(-2) === 'einwandfrei', 'Schwelle: 0/-2 muss Einwandfrei sein');
        st_expect(basis_band(-3) === 'ausbaufaehig' && basis_band(-5) === 'ausbaufaehig', 'Schwelle: -3/-5 muss das Schlecht-Band (ausbaufaehig) sein');
        st_expect(basis_band_label('ausbaufaehig') === 'Schlecht', 'Basis-Band-Label muss bewusst drastisch „Schlecht" heißen (Juli 2026)');
        st_expect(setting_get('basis_info_cutoff', '') !== '', 'Info-Amnestie-Stichtag muss gesetzt sein (Migration)');
        st_expect(basis_band(-6) === 'gespraech' && basis_band(-99) === 'gespraech', 'Schwelle: ab -6 muss Gesprächsbedarf sein');
        basis_band_label('gespraech'); basis_band_icon('einwandfrei');
        meeting_noshows(0); info_reads_for(0);
        member_basis_breakdown(['id' => 0, 'joined_at' => date('Y-m-d'), 'referat' => '', 'basis_adjust' => 0]);
    });
    $run('Score-Einordnung inkl. Spitzenklasse', function () {
        $s = [1 => 10, 2 => 8, 3 => 8, 4 => 2]; // ⌀ 7, Marge 1.05
        st_expect(score_top_ids($s) === [1, 2, 3], 'Top-IDs müssen tie-inklusiv [1,2,3] sein');
        st_expect(score_band_for(1, $s) === 'spitze', 'Bester + „gut" muss Spitzenklasse sein');
        st_expect(score_band_for(2, $s) === 'okay', 'Top-ID ohne „gut"-Band darf NICHT Spitzenklasse sein');
        st_expect(score_band_for(4, $s) === 'ausbaufaehig', 'Deutlich unter ⌀ muss Ausbaufähig sein');
        st_expect(score_top_ids([]) === [] && score_band_for(1, []) === 'okay', 'Leere Score-Liste muss neutral bleiben');
        score_band_label('spitze'); score_band_icon('spitze');
    });
    $run('Test-Werkzeuge (Royal/Belohnungen freischalten)', function () { royal_test_allowed(); royal_test_active(); test_unlock_all(); });
    $run('Props-Animation: Bedingungen nur lesen (Merker bleibt unberührt)', function () {
        // Die Diagnose zeigt an, warum die Animation läuft oder nicht. Diese Auskunft darf NICHTS
        // verändern – täte sie es, würde das Nachschauen die echte Anzeige aufbrauchen.
        $meP = current_member();
        $vorher = $meP ? (string)(member_get((int)$meP['id'])['props_seen_ym'] ?? '') : '';
        $st = props_reset_status((int)($meP['id'] ?? 0));
        st_expect(props_reset_status(0) === [], 'Ohne Mitglied darf die Auskunft leer bleiben');
        if ($meP) {
            st_expect(count($st) === 4, 'Die Auskunft muss alle vier Bedingungen nennen');
            foreach ($st as $c) st_expect(isset($c['label'], $c['ok'], $c['info']), 'Bedingung ohne Beschriftung/Ergebnis');
            st_expect((string)(member_get((int)$meP['id'])['props_seen_ym'] ?? '') === $vorher,
                'props_reset_status() hat den Monats-Merker verändert – die Auskunft muss rein lesend sein');
        }
    });
    $run('Mitteilungs-Register (Typen, Gruppen, Prefs)', function () {
        $types = notify_types();
        if (count($types) < 10) throw new \RuntimeException('Register unvollständig');
        foreach ($types as $key => $def) {
            foreach (['group', 'label', 'desc', 'icon', 'mail'] as $f) {
                if (!isset($def[$f])) throw new \RuntimeException("$key: Feld $f fehlt");
            }
            if (!isset(notify_groups()[$def['group']])) throw new \RuntimeException("$key: unbekannte Gruppe");
            if (!in_array($def['mail'], ['forced', 'push_opt', 'sender', 'opt', 'none'], true)) throw new \RuntimeException("$key: mail-Modus ungültig");
            // „Pflicht, außer per Push" ergibt nur Sinn, wenn es diesen Typ überhaupt als Push gibt
            if ($def['mail'] === 'push_opt' && empty($def['push'])) throw new \RuntimeException("$key: push_opt ohne Push-Kanal");
            if (!empty($def['timing']) && !isset($def['timing_default'])) throw new \RuntimeException("$key: timing ohne Default");
        }
        $p = notify_pref(0, 'vote_open');
        if (!$p['mail'] || $p['timing'] < 1) throw new \RuntimeException('vote_open-Defaults kaputt (Pflicht-Mail/Fenster)');
        $ai = notify_pref(0, 'app_inactive');
        if ($ai['push'] !== false || $ai['mail'] !== false || $ai['timing'] !== 5) throw new \RuntimeException('app_inactive muss ab Werk aus sein (Opt-in-Push, 5 Tage)');
        if (notify_pref(0, 'gibtsnicht') !== ['mail' => false, 'push' => false, 'timing' => 0]) throw new \RuntimeException('Unbekannter Typ nicht neutral');
        if (notify_pref_set(0, 'vote_open', 'mail', 0) !== false) throw new \RuntimeException('Ungültiges pref_set muss false liefern');
        // Pflicht-Mail „nur mit Push abschaltbar": ohne Push-Gerät darf sie sich nicht abwählen lassen,
        // und Absender-/Nicht-Mail-Typen bleiben grundsätzlich gesperrt. (Beides greift VOR dem Schreiben.)
        $ohnePush = (int)(db()->query('SELECT m.id FROM members m WHERE m.active = 1
                                       AND NOT EXISTS (SELECT 1 FROM push_subscriptions p WHERE p.member_id = m.id)
                                       ORDER BY m.id LIMIT 1')->fetchColumn() ?: 0);
        if ($ohnePush > 0) {
            if (has_push_device($ohnePush)) throw new \RuntimeException('has_push_device widerspricht der Abfrage');
            if (notify_pref_set($ohnePush, 'vote_open', 'mail', 0) !== false) throw new \RuntimeException('Ohne Push-Gerät darf die Pflicht-Mail nicht abwählbar sein');
            if (!notify_pref($ohnePush, 'vote_open')['mail']) throw new \RuntimeException('Ohne Push-Gerät muss die Pflicht-Mail an sein');
        }
        if (notify_pref_set(0, 'dm_vorsitz', 'mail', 0) !== false) throw new \RuntimeException('Absender-Mail darf nicht umstellbar sein');
        if (notify_pref_set(0, 'dm_bugs', 'mail', 1) !== false) throw new \RuntimeException('Typ ohne Mail-Kanal darf nicht umstellbar sein');
    });
    $run('Web Push (Server-Voraussetzungen, VAPID, Verschlüsselung)', function () {
        if (!push_available()) throw new \RuntimeException('Server kann kein Web Push (openssl/curl/hash_hkdf/pkey_derive fehlt)');
        $v = push_vapid();
        if (!$v || strlen(b64u_decode($v['pub'])) !== 65) throw new \RuntimeException('VAPID-Schlüssel defekt');
        $jwt = push_vapid_jwt('https://example.com/x', $v);
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen(b64u_decode($parts[2])) !== 64) throw new \RuntimeException('VAPID-JWT defekt');
        // Verschlüsselung gegen ein frisches Dummy-„Browser"-Schlüsselpaar
        $client = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $body = push_encrypt('{"test":1}', b64u_encode(push_ec_pub_raw($client)), b64u_encode(random_bytes(16)));
        if ($body === null || strlen($body) < 86 + 11 + 16) throw new \RuntimeException('RFC-8291-Verschlüsselung fehlgeschlagen');
    });
    $run('Avatar-Verlauf (personalisierte Bubble-Farbe)', function () {
        if (avatar_gradient('Max Mustermann') !== avatar_gradient('max mustermann ')) throw new \RuntimeException('nicht stabil');
        if (!str_starts_with(avatar_gradient('Ünal Öztürk'), 'linear-gradient(')) throw new \RuntimeException('kein CSS-Verlauf');
    });
    $run('Assets tragen eine Versions-Kennung', function () {
        // assets/.htaccess lässt Stylesheets, Skripte, Schriften und Bilder EIN JAHR zwischenspeichern.
        // Das ist nur gefahrlos, solange jede Adresse eine Kennung trägt (asset_v()) – sonst bliebe
        // eine geänderte Datei bei allen Mitgliedern ein Jahr lang eingefroren. Diese Prüfung fängt
        // genau das: neue Einbindung ohne asset_v() → hier rot, nicht erst beim nächsten Deploy.
        $htaccess = @file_get_contents(__DIR__ . '/../assets/.htaccess') ?: '';
        st_expect(str_contains($htaccess, 'max-age'), 'assets/.htaccess mit den Cache-Regeln fehlt');
        // Ausnahme: die beiden Outfit-Schriften. Sie werden aus style.css OHNE Kennung geladen;
        // ein Vorladen mit Kennung würde nicht mehr dazu passen und die Datei doppelt holen.
        $ausnahmen = ['assets/fonts/outfit-latin.woff2', 'assets/fonts/outfit-latin-ext.woff2'];
        $ohne = [];
        foreach (['lib.php', 'dashboard.php'] as $datei) {
            $src = @file_get_contents(__DIR__ . '/../' . $datei) ?: '';
            // Erst die korrekt versionierten Einbindungen herausschneiden – was danach noch an
            // assets/… übrig ist, wird ohne Kennung ausgeliefert.
            $src = preg_replace("~asset_v\(\s*'[^']*'\s*\)~", '', $src);
            // Auch serverseitige Zugriffe herausschneiden: __DIR__ . '/assets/…' liest eine
            // Datei von der Platte, das ist keine Einbindung im Browser und braucht keine Kennung.
            $src = preg_replace("~__DIR__\s*\.\s*'/assets/[^']*'~", '', $src);
            preg_match_all('~(assets/[\w./-]+\.(?:css|js|png|woff2))(\?v)?~', $src, $m, PREG_SET_ORDER);
            foreach ($m as $t) {
                if ($t[2] ?? '') continue;                       // trägt eine eigene Kennung (?v3.31.0)
                if (in_array($t[1], $ausnahmen, true)) continue; // bewusste Ausnahme
                $ohne[] = $datei . ': ' . $t[1];
            }
            $ohne = array_values(array_unique($ohne));
        }
        st_expect(!$ohne, 'ohne asset_v() eingebunden (würde ein Jahr eingefroren): ' . implode(', ', $ohne));
        return count($ausnahmen) . ' bekannte Ausnahmen';
    });
    $run('Anleitungs-Texte aus README', function () {
        $sec = [
            'Mitglieder'        => readme_member_section(),
            'Sekretariat'       => readme_secretariat_section(),
            'Pat:innenprogramm' => readme_pat_section(),
        ];
        foreach ($sec as $name => $md) st_expect(strlen($md) > 500, 'Kapitel „' . $name . '" fehlt in der README');
        // Mitglieder kommen nicht in die Verwaltung – ein Link dorthin liefe für sie ins Leere.
        st_expect(!str_contains($sec['Mitglieder'], '](admin/'), 'Mitglieder-Kapitel darf nicht in die Verwaltung verlinken');
        // Das Sekretariat erreicht von der Verwaltung nur die Sitzungs-Seiten.
        preg_match_all('#\]\(admin/([\w.-]+\.php)#', $sec['Sekretariat'], $mSek);
        foreach (array_unique($mSek[1]) as $p) st_expect(in_array($p, ['meetings.php', 'reports.php'], true), 'Sekretariat kann admin/' . $p . ' nicht öffnen');
        // Jede verlinkte Seite muss es auch geben (Tippfehler und umbenannte Dateien).
        $all = @file_get_contents(__DIR__ . '/../README.md') ?: '';
        st_expect($all !== '', 'README.md nicht lesbar');
        preg_match_all('~\]\(((?:admin/)?[\w.-]+\.php)[)?\#]~', $all, $mAll);
        foreach (array_unique($mAll[1]) as $p) st_expect(is_file(__DIR__ . '/../' . $p), 'Verlinkte Seite fehlt: ' . $p);
        render_markdown($all); // Vollfassung wie in admin/help.php
        return count($sec) . ' Rollen-Kapitel, ' . count(array_unique($mAll[1])) . ' Links geprüft';
    });
    $run('Fristen-Pillen-Schwellen', function () {
        st_expect(deadline_pill_class(0) === 'pill-bad' && deadline_pill_class(1) === 'pill-bad', '≤1 Tag muss rot sein');
        st_expect(deadline_pill_class(2) === 'pill-warn' && deadline_pill_class(3) === 'pill-warn', '2–3 Tage müssen gelb sein');
        st_expect(deadline_pill_class(4) === 'pill-info', '≥4 Tage müssen neutral sein');
    });
    $run('Schicht-Endzeit (Übernacht-Logik) + Anzeige', function () {
        st_expect(slot_end_from_time('2025-07-18 21:00', '02:00') === '2025-07-19 02:00', 'Ende ≤ Start muss auf den Folgetag rollen');
        st_expect(slot_end_from_time('2025-07-18 14:00', '18:00') === '2025-07-18 18:00', 'normales Ende muss am selben Tag bleiben');
        st_expect(slot_end_from_time('2025-07-18 14:00', '') === null, 'leere Endzeit muss null ergeben');
        fmt_slot('2025-07-18 21:00', '2025-07-19 02:00');
        st_expect(fmt_duration_min(300) === '5 h' && fmt_duration_min(210) === '3 h 30 min' && fmt_duration_min(0) === '–', 'Dauer-Formatierung');
        st_expect(norm_dtl('2025-07-18T21:00') === '2025-07-18 21:00' && norm_dtl('') === null, 'datetime-local-Normalisierung');
    });
    $run('Bug-Reports laden', function () { bug_open_count(); bugs_all('all'); bugs_for_member(0); technik_members(); });
    $run('Streak-Pausen-Hinweis (Symbol-Knopf)', function () {
        $lb = lb_chip_info();
        st_expect(isset($lb['key'], $lb['icon'], $lb['label']), 'lb_chip_info muss key/icon/label liefern');
        $bar = lb_chip_html('bar');
        $hero = lb_chip_html('hero');
        if ($lb['key'] === '') {
            st_expect($bar === '' && $hero === '', 'ohne laufende Pause darf kein Knopf entstehen');
            return 'keine Pause aktiv';
        }
        // Beide Sitzplätze müssen dieselbe Kennung tragen – sonst zeigt einer von beiden den
        // Hinweis wieder an, während der andere ihn für weggeklickt hält.
        st_expect(str_contains($bar, 'lb-chip-bar') && str_contains($hero, 'lb-chip-hero'), 'Varianten-Klasse fehlt');
        st_expect(substr_count($bar, $lb['key']) === 1 && substr_count($hero, $lb['key']) === 1, 'Kennung muss in beiden Knöpfen stehen');
        st_expect(str_contains($bar, $lb['icon']) && str_contains($hero, $lb['icon']), 'Symbol fehlt');
        return 'aktiv: ' . $lb['key'];
    });
    $run('Technik-Passwort-Kontrolle (Vorsitz, halbjährlich)', function () {
        $last = admin_pw_check_last();
        st_expect(ADMIN_PW_CHECK_MONTHS === 6, 'Kontroll-Abstand muss 6 Monate sein');
        // Fälligkeit rechnet genau 6 Monate ab der letzten Bestätigung
        $probe = date('Y-m-d', strtotime('-1 year'));
        setting_set('admin_pw_check_last', $probe);
        st_expect(admin_pw_check_due_on() === date('Y-m-d', strtotime($probe . ' +6 months')), 'Fälligkeitsdatum falsch berechnet');
        setting_set('admin_pw_check_last', date('Y-m-d'));
        st_expect(admin_pw_check_due_on() > date('Y-m-d'), 'frisch bestätigt darf nicht sofort wieder fällig sein');
        setting_set('admin_pw_check_last', $last); // Ausgangszustand wiederherstellen – der Selbsttest ändert nichts
        st_expect(admin_pw_check_last() === $last, 'Selbsttest muss den Ausgangszustand wiederherstellen');
        // Nur der Vorsitz bekommt die Aufgabe (diese Seite läuft als Admin/Technik → nie fällig)
        st_expect(current_role() !== 'vorsitz' ? admin_pw_check_pending() === false : true, 'Kontrolle darf nur beim Vorsitz auftauchen');
        return 'zuletzt bestätigt: ' . ($last === '' ? 'nie' : $last);
    });
    $run('Feedback laden + Filter/Registry', function () {
        feedback_open_count(); feedback_for_member(0); feedback_get(0); feedback_comments(0);
        // Jeder Filter muss eine gültige Abfrage ergeben: 'open', 'all', jeder Stand und jede Art
        foreach (array_merge(['open', 'all'], array_keys(feedback_statuses()), array_keys(feedback_kinds())) as $f) feedback_all($f);
        st_expect(feedback_kind_ok('lob') === 'lob' && feedback_kind_ok('quatsch') === 'idee', 'unbekannte Art muss zur „Idee" werden');
        st_expect(isset(feedback_statuses()['new'], feedback_statuses()['done']), 'Stände „new" und „done" müssen existieren');
        st_expect(feedback_excerpt(str_repeat('a', 200), 20) !== '' && mb_strlen(feedback_excerpt(str_repeat('a', 200), 20)) === 20, 'Kurzfassung muss auf die Länge gekürzt werden');
        st_expect(feedback_excerpt('   ') === '(ohne Text)', 'leerer Text braucht einen Platzhalter');
        st_expect(feedback_add('idee', '   ', null) === 0, 'Rückmeldung ohne Text darf nicht angelegt werden');
        // Sprungziele der Mitteilungen müssen die Link-Prüfung überstehen (sonst landet der Klick blind auf dem Dashboard)
        st_expect(dm_safe_link('admin/feedback.php?id=7') === 'admin/feedback.php?id=7', 'Verwaltungs-Sprungziel muss erlaubt sein');
        st_expect(dm_safe_link('feedback.php?id=7') === 'feedback.php?id=7', 'Mitglieder-Sprungziel muss erlaubt sein');
        st_expect(dm_safe_link('https://evil.example/x.php') === '' && dm_safe_link('../../etc/passwd.php') === '', 'externe/relative Pfade müssen abgewiesen werden');
        // Im Feedback-Dialog dürfen nie ZWEI Knöpfe hervorgehoben sein. „Idee" ist die Vorauswahl
        // (gefüllt); ohne autofocus am Textfeld setzt der Browser den Fokus beim Öffnen auf den
        // ersten Auswahlkreis („Lob"), der dann zusätzlich den Fokusring trägt – zwei
        // Hervorhebungen mit verschiedener Bedeutung (gemeldet).
        $qLibFb = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect((bool)preg_match('~<textarea name="message"[^>]*\bautofocus\b~', $qLibFb),
            'dem Feedback-Textfeld fehlt autofocus – dann liegt der Fokusring beim Öffnen auf „Lob", '
            . 'während „Idee" vorausgewählt ist');
    });
    $run('Auszahlungsaufforderungen (StuPa)', function () {
        // Vorlage muss vorhanden sein und alle Platzhalter tragen – sonst käme ein leeres Blatt raus
        $tpl = docx_template_path('auszahlung');
        st_expect($tpl !== null && is_file($tpl), 'Vorlage auszahlung-vorlage.docx fehlt');
        if (class_exists('ZipArchive')) {
            $z = new ZipArchive();
            st_expect($z->open($tpl) === true, 'Vorlage ist kein lesbares .docx');
            $doc = (string)$z->getFromName('word/document.xml');
            $hdr = (string)$z->getFromName('word/header1.xml');
            $z->close();
            foreach (['((AA))', '((AZ))', '((AB))', '((AK))', '((AT))', '((AKI))', '((AIB))', '((PRAESIDIUM))'] as $ph) {
                st_expect(str_contains($doc, $ph), 'Platzhalter ' . $ph . ' fehlt in der Vorlage');
            }
            // Die Legislatur-Zeile sitzt in der Kopfzeile – eigener Dateiteil, eigener Ersetzungslauf
            st_expect(str_contains($hdr, '((LEG))'), 'Platzhalter ((LEG)) fehlt in der Kopfzeile der Vorlage');
        }
        // Kopfangaben: Eingaben werden normalisiert, Leeres darf den Bestand nicht kippen
        st_expect(stupa_legislatur() !== '', 'Legislatur-Nummer darf nie leer sein');
        st_expect(stupa_praesidium() !== '', 'Präsidiums-Namen dürfen nie leer sein');
        st_expect(docx_text("a\nb") === 'a</w:t><w:br/><w:t xml:space="preserve">b',
            'Zeilenumbrüche müssen zu echten Word-Umbrüchen werden');
        st_expect(docx_text('<b>&') === '&lt;b&gt;&amp;', 'Sonderzeichen müssen escapt bleiben');
        // Rechenwege ohne Schreibzugriff prüfen
        $probe = [['amount_cents' => 1250, 'purpose' => 'A'], ['amount_cents' => 750, 'purpose' => 'B']];
        st_expect(stupa_claim_total($probe) === 2000, 'Summe der Posten muss stimmen');
        st_expect(stupa_claim_label($probe) === 'A (+1 weitere)', 'Bezeichnung bei mehreren Posten');
        st_expect(stupa_claim_label([$probe[0]]) === 'A', 'Bezeichnung bei einem Posten');
        st_expect(stupa_claim_total([]) === 0 && stupa_claim_label([]) === 'Auszahlungsaufforderung',
            'leeres Blatt darf nicht stolpern');
        st_expect(stupa_position_valid(['applicant' => 'X', 'purpose' => 'Y', 'account_holder' => 'X',
            'iban' => 'DE1', 'amount_cents' => 100]), 'vollständiger Posten muss gültig sein');
        st_expect(!stupa_position_valid(['applicant' => 'X', 'purpose' => '', 'account_holder' => 'X',
            'iban' => 'DE1', 'amount_cents' => 100]), 'Posten ohne Verwendungszweck muss abgelehnt werden');
        st_expect(!stupa_position_valid(['applicant' => 'X', 'purpose' => 'Y', 'account_holder' => 'X',
            'iban' => 'DE1', 'amount_cents' => 0]), 'Posten ohne Betrag muss abgelehnt werden');
        st_expect(stupa_claim_add(0, []) === 0, 'Blatt ohne gültigen Posten darf nichts schreiben');
        return 'Vorlage + ' . count(docx_templates()['auszahlung']['placeholders']) . ' Platzhalter';
    });
    $run('Einladungs-Versandmodus', function () { invite_mode(); invite_text_mode(); });
    $run('Sitzungs-Rückmeldung (abmelden/online)', function () {
        meeting_rsvp_open(['starts_at' => date('Y-m-d') . ' 20:00', 'cancelled' => 0, 'draft' => 0]);
        meeting_rsvp_open(['starts_at' => '', 'cancelled' => 0, 'draft' => 0]);
        meeting_rsvp_status(0, 0); meeting_rsvp_lists(0);
        protokoll_fill_absent_table('<w:tbl></w:tbl>', ['Test']); protokoll_fill_absent_table('', []);
    });
    $run('Redelisten-SQLite öffnen', function () { redeliste_db(); redeliste_started(['redeliste_token' => '']); });
    $run('Pronomen in der Redeliste (feste Auswahl, gleiche Optionen wie im Profil)', function () {
        $rl = (string)@file_get_contents(__DIR__ . '/../redeliste.html');
        st_expect($rl !== '', 'redeliste.html fehlt');
        // Die Redeliste ist eine eigenständige HTML-Datei ohne PHP – die Listen stehen dort ein
        // zweites Mal. Genau deshalb diese Prüfung: Ergänzt jemand im Profil ein Pronomen und
        // vergisst die Redeliste, wählt man beim Beitreten plötzlich etwas anderes.
        foreach ([['PRON_FIRST', pronoun_first_options()], ['PRON_SECOND', pronoun_second_options()]] as [$name, $soll]) {
            st_expect((bool)preg_match('~const\s+' . $name . '\s*=\s*\[([^\]]*)\]~', $rl, $m), $name . ' fehlt in redeliste.html');
            $ist = array_values(array_filter(array_map(fn($x) => trim($x, " '\""), explode(',', $m[1])), fn($x) => $x !== ''));
            st_expect($ist === $soll, 'Pronomen-Liste ' . $name . ' weicht vom Profil ab: [' . implode(', ', $ist) . '] statt [' . implode(', ', $soll) . ']');
        }
        // Freitext ist der eigentliche Punkt: Niemand soll „keine" eintippen müssen.
        st_expect(!str_contains($rl, 'id="grPron"') && !str_contains($rl, 'id="exPron"'),
            'In der Redeliste steht wieder ein Freitextfeld für Pronomen');
        st_expect(!str_contains($rl, 'prompt(`Pronomen'), 'Pronomen werden wieder per Browser-Abfrage geändert');
        st_expect(!preg_match('~pronoun|Pronomen[^<]{0,40}keine~i', $rl) || !str_contains($rl, 'er/ihm, keine'),
            'Im Beitritts-Dialog steht wieder „keine" als Vorschlag');
        st_expect(str_contains($rl, "pronMarkieren(box, 'grPronA', 'grPronB')"),
            'Beim Beitritt sind die Pronomen nicht mehr verpflichtend');
        // Und die App muss weiter genau das Format akzeptieren, das die Redeliste zusammensetzt.
        st_expect(pronoun_valid(pronoun_first_options()[0] . '/' . pronoun_second_options()[0]), 'Format „erstes/zweites" muss gültig sein');
        st_expect(!pronoun_valid('keine') && !pronoun_valid(''), '„keine" bzw. leer darf nicht als Pronomen durchgehen');
    });
    $run('Fehler-Log bleibt Fehler-Log (keine Notizen)', function () {
        // Notizen wie „N Mitglieder benachrichtigt" oder Aufräum-Statistiken gehören in die
        // Cron-AUSGABE, nicht ins Fehler-Log (User-Regel). app_log_error ist Fehlern vorbehalten.
        st_expect(!str_contains((string)@file_get_contents(__DIR__ . '/../cron_waslaeuft.php'), 'app_log_error('),
            'cron_waslaeuft.php schreibt wieder Notizen ins Fehler-Log – bitte in die Cron-Ausgabe (echo) statt app_log_error');
        $crQ = (string)@file_get_contents(__DIR__ . '/../cron_reminders.php');
        st_expect(substr_count($crQ, 'app_log_error(') === 1 && str_contains($crQ, "app_log_error('Pat:innenprogramm-Löschfristen (Cron): ' . \$e->getMessage())"),
            'cron_reminders.php: app_log_error nur für die eine echte Fehlerstelle (Löschfristen-Exception) – Vollzugs-Notizen gehören in die Cron-Ausgabe');
    });
    $run('Redeliste ohne Achievement-Schmuck', function () {
        // Der Raum-Stand wird EINMAL gebaut und liegt danach in redeliste-data: Was hier hineingerät,
        // steht bis zum Neustart der Sitzung drin. Deshalb an der Quelle prüfen, nicht am Ergebnis.
        $libQ = (string)@file_get_contents(__DIR__ . '/../lib.php');
        $von  = strpos($libQ, 'function redeliste_seed(');
        $bis  = strpos($libQ, 'function redeliste_started(');
        $seed = ($von !== false && $bis !== false && $bis > $von) ? substr($libQ, $von, $bis - $von) : '';
        st_expect($seed !== '', 'redeliste_seed() ist im Quelltext nicht mehr auffindbar – der Wächter unten prüft sonst nichts');
        foreach (['avatar-acc' => 'Avatar-Schmuck', 'member_equipped_decos' => 'getragenen Schmuck',
                  "'rtitle'" => 'den King/Queen-Titel', 'member_avatar_gradient' => 'die Avatar-Farbe'] as $nadel => $was) {
            st_expect(!str_contains($seed, $nadel),
                'redeliste_seed() gibt wieder ' . $was . ' in den Raum-Stand – in der Sitzung zählt, wer dran ist, nicht wer welches Achievement trägt');
        }
        $rlQ = (string)@file_get_contents(__DIR__ . '/../redeliste.html');
        foreach (['p.deco' => 'Avatar-Schmuck', 'avatar-acc' => 'Avatar-Schmuck', 'rl-royal' => 'royale Titel',
                  'rl-av' => 'Mitglieder-Avatare', 'p.grad' => 'Avatar-Farben'] as $nadel => $was) {
            st_expect(!str_contains($rlQ, $nadel), 'redeliste.html zeichnet wieder ' . $was . ' in der Teilnehmenden-Leiste');
        }
    });
    $run('Redelisten-Links: nichts, woran eine Link-Erkennung zerbricht', function () {
        // Die Gast-Adresse landet in der Einladung an alle Studis. Enthielt sie kodierte Zeichen
        // (%2C, %20, %3A aus dem Klartext-Datum), schnitten Mailprogramm und Word die Adresse
        // mittendrin ab – der Rest stand als toter Text dahinter. Also: nur harmlose Zeichen.
        $probe = ['id' => 0, 'redeliste_token' => 'aabbccddeeff', 'redeliste_key' => '00112233445566',
                  'starts_at' => '2026-07-08 20:00:00'];
        $q = 'redeliste.html?session=room-' . $probe['redeliste_token']
           . '&start=' . date('YmdHi', strtotime((string)$probe['starts_at']));
        st_expect(!str_contains($q, '%'), 'In der Redelisten-Adresse steht wieder ein kodiertes Zeichen');
        st_expect((bool)preg_match('~^[A-Za-z0-9:/?=&._-]+$~', $q), 'Die Redelisten-Adresse enthält Zeichen, an denen die Link-Erkennung abbricht');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../lib.php'), "date('YmdHi', \$t)"),
            'meeting_redeliste() gibt den Sitzungsbeginn nicht mehr als reine Ziffernfolge mit');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../redeliste.html'), '(\\d{4})(\\d{2})(\\d{2})(\\d{2})(\\d{2})'),
            'redeliste.html kann die Ziffernfolge des Sitzungsbeginns nicht mehr lesen');
        // Und der Linkfilter der Einladung darf kein Satzzeichen mitverschlucken.
        $re = '/(https?:\/\/[^\s<]*[^\s<.,;:!?)\]])/i';
        st_expect(preg_replace($re, '[$1]', 'Kurs: https://olat.vcrp.de/x.') === 'Kurs: [https://olat.vcrp.de/x].',
            'Die Linkerkennung der Einladung zieht den Schlusspunkt mit in die Adresse');
    });
    $run('Event-Überschneidungen (Abwesenheit)', function () { events_overlapping(date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))); });
    $run('Abstimmungsgegenstände (laden/lesen/aufräumen)', function () {
        $mid = (int)(db()->query('SELECT id FROM meetings ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
        vote_items_for($mid); vote_item_read_set($mid, 0); vote_items_unread_for_member(0); vote_item_read_count(0);
    });
    $run('Neue „Wichtige Infos" ermitteln (inkl. Referats-Filter)', function () {
        infos_new_for(['infos_seen_at' => '2000-01-01 00:00:00']);
        infos_new_for(['infos_seen_at' => '2000-01-01 00:00:00', 'referat' => 'Sekretariat']);
        infos_all(); infos_all(''); infos_all('Sekretariat');
    });
    $run('Telefonnummer im Profil (freiwillig, verborgen)', function () {
        // Wählbare Fassung: Trennzeichen raus, Ziffern und + bleiben
        st_expect(phone_dial('0151 234-567') === '0151234567' && phone_dial('+49 (0)6341 1') === '+4906341' . '1', 'phone_dial räumt die Nummer falsch auf');
        st_expect(phone_dial('auf Anfrage') === '' && phone_dial('') === '', 'phone_dial muss ohne Ziffern leer liefern');
        // Ohne Nummer gibt es einen neutralen Standardsatz statt einer leeren Zeile im Dialog
        st_expect(member_phone(['phone' => ' 0151 1 ']) === '0151 1' && member_phone([]) === '', 'member_phone liest die Nummer falsch');
        st_expect(member_phone_note(['phone_note' => 'nur Notfall']) === 'nur Notfall', 'Eigene Bitte muss unverändert durchgehen');
        st_expect(trim(member_phone_note([])) !== '', 'Ohne eigene Bitte braucht der Dialog einen Standardsatz');
        // Speichern prüft die Eingabe – ohne Mitglied darf nichts passieren
        st_expect(member_set_phone(0, '0151 1', '') === false, 'Ohne Mitglied darf keine Nummer gespeichert werden');
        // Spalten da?
        $mc = array_map(fn($c) => (string)$c['name'], db()->query('PRAGMA table_info(members)')->fetchAll());
        st_expect(in_array('phone', $mc, true) && in_array('phone_note', $mc, true) && in_array('phone_none', $mc, true),
            'members: Spalten phone/phone_note/phone_none fehlen');
        // Deaktivierte Konten sind ein ÜBERGANG: Stempel-Spalte, Selbstheilung,
        // Stempel beim Umschalten und die Vorsitz-Erinnerung auf dem Dashboard (ab 14 Tagen,
        // weg erst ohne deaktivierte Konten).
        st_expect(in_array('deactivated_on', $mc, true), 'members: Spalte deactivated_on fehlt – die Aufräum-Erinnerung wüsste kein Seit-wann');
        st_expect(function_exists('deactivated_reminder') && is_array(deactivated_reminder()),
            'deactivated_reminder() fehlt oder liefert kein Array');
        st_expect((int)db()->query("SELECT COUNT(*) FROM members WHERE active = 0 AND deactivated_on = ''")->fetchColumn() === 0,
            'Deaktivierte ohne Stempel – die Selbstheilung in der Migration greift nicht mehr');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/members.php'), "CASE WHEN active = 1 THEN date('now','localtime')"),
            'admin/members.php stempelt die Deaktivierung nicht mehr');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../dashboard.php'), 'deactivated_reminder'),
            'dashboard.php zeigt die Aufräum-Erinnerung des Vorsitzes nicht mehr');
        // „Ich möchte keine angeben" ist eine vollwertige Antwort – aber nur ohne hinterlegte Nummer
        st_expect(member_phone_optout(['phone_none' => 1, 'phone' => '']) === true, 'Bewusste Absage wird nicht erkannt');
        st_expect(member_phone_optout(['phone_none' => 1, 'phone' => '0151 1']) === false, 'Mit hinterlegter Nummer ist es keine Absage');
        st_expect(member_phone_optout([]) === false, 'Ohne Angabe darf nichts als Absage gelten');
        // Der Profil-Hinweis ist bewusst FOLGENLOS: Er darf weder Aufgaben noch Score berühren.
        $vollProfil = ['pronouns' => 'sie/ihr', 'referat' => 'Technik', 'referat_desc' => 'x', 'about_me' => 'y', 'phone' => '', 'phone_none' => 1];
        st_expect(profile_todo($vollProfil) === [], 'Vollständiges Profil (mit bewusster Telefon-Absage) darf nichts mehr offen haben');
        st_expect(profile_todo(null) === [], 'Ohne Mitglied darf der Profil-Hinweis nichts melden');
        st_expect(count(profile_todo(['pronouns' => '', 'referat' => '', 'about_me' => '', 'phone' => ''])) === 3,
            'Ohne Referat sind genau drei Punkte offen (Pronomen, Über dich, Telefon)');
        st_expect(count(profile_todo(array_merge($vollProfil, ['phone_none' => 0]))) === 1, 'Ohne Telefon-Antwort muss genau ein Punkt offen sein');
        $dashSrc = (string)@file_get_contents(__DIR__ . '/../dashboard.php');
        st_expect($dashSrc === '' || !preg_match('~\$nTasks[^;\n]*profile_todo~', $dashSrc),
            'dashboard.php: Der Profil-Hinweis darf NICHT in die offenen Aufgaben einfließen');
        $scoreQuellen = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect(substr_count($scoreQuellen, 'profile_todo(') <= 2,
            'profile_todo() wird an mehr Stellen benutzt als vorgesehen – es darf in keine Score-/Aufgaben-Rechnung geraten');
        // Der Kern der Zusage: Die Nummer darf NICHT im Seitenquelltext stehen, sondern erst
        // nach Bestätigung über den eigenen Endpunkt kommen. Beides muss vorhanden sein.
        $prof = (string)@file_get_contents(__DIR__ . '/../profil.php');
        st_expect($prof === '' || str_contains($prof, "'phone_reveal'"), 'profil.php: Der Endpunkt zum Nachholen der Nummer fehlt');
        st_expect($prof === '' || str_contains($prof, 'data-tel-note'), 'profil.php: Der Knopf mit der Bitte fehlt auf fremden Profilen');
        // Die bewusste Absage ist ein eigener Knopf STATT Speichern – mit eigenem Formular,
        // damit die Rückfrage nur an ihr klebt und nicht am gewöhnlichen Speichern.
        st_expect($prof === '' || (str_contains($prof, "'save_phone_none'") && str_contains($prof, 'form="phoneNoneForm"')),
            'profil.php: Der Knopf „Keine Nummer hinterlegen" (eigenes Formular) fehlt');
        st_expect($prof === '' || !preg_match('~data-tel-(nummer|phone)=~', $prof), 'profil.php: Die Nummer darf NIE im Quelltext des fremden Profils stehen');
        $jsP = (string)@file_get_contents(__DIR__ . '/../assets/app.js');
        st_expect($jsP === '' || (str_contains($jsP, 'phone_reveal') && str_contains($jsP, 'astaConfirm')), 'app.js: Der Zustimmungs-Dialog für die Nummer fehlt');
        // „Mehr anzeigen" muss NACH dem Schriftwechsel noch einmal messen. Wer nur einmal beim
        // Ausführen misst, misst mit der Ersatzschrift – der Abschnitt ist dann kurz zu hoch, der
        // Knopf erscheint, und wenn die richtige Schrift steht, passt alles hinein. Der Knopf blieb
        // stehen und beim Klicken passierte sichtbar nichts.
        if ($jsP !== '' && str_contains($jsP, "querySelectorAll('.clamp')")) {
            st_expect(str_contains($jsP, 'document.fonts.ready.then(pruefen)'),
                'app.js: „Mehr anzeigen" misst nicht mehr nach, wenn die Schriften stehen – der Knopf bliebe an Abschnitten stehen, die hineinpassen');
            st_expect(!str_contains($jsP, 'c.clientHeight > 4'),
                'app.js: „Mehr anzeigen" erscheint wieder ab 4 Pixeln – dafür lohnt kein Knopf');
        }
        // hidden heißt hidden. Das Attribut wirkt nur über die Browser-Voreinstellung, und die
        // verliert gegen jede Regel aus unseren Stylesheets – ein `display: flex` auf derselben
        // Klasse hebelt das Verstecken still aus, das Element bleibt sichtbar, egal was das
        // Skript tut. Genau daran hing der Knopf „Mehr anzeigen", und davor neun weitere Stellen,
        // die alle einzeln geflickt wurden. Deshalb steht die Regel jetzt einmal ganz oben –
        // und deshalb wird hier geprüft, dass sie dort bleibt.
        foreach (['style.css', 'pat.css'] as $datei) {
            $roh = (string)@file_get_contents(__DIR__ . '/../assets/' . $datei);
            if ($roh === '') { st_expect(false, 'assets/' . $datei . ' fehlt'); continue; }
            // Kommentare raus, sonst liest die Prüfung den Erklärtext direkt über der Regel –
            // dort steht „[hidden] {display:none}" als Beschreibung. Dieselbe Falle wie in wl.css.
            $roh = (string)preg_replace('~/\*.*?\*/~s', '', $roh);
            st_expect((bool)preg_match('~\[hidden\]\s*\{[^}]*display:\s*none\s*!important~', $roh),
                $datei . ': die Regel „[hidden] { display: none !important }" fehlt – jedes eigene display '
                . 'auf einer Klasse würde das Verstecken still aushebeln');
        }
        // Die Hausregel gilt unabhängig von der persönlichen Bitte und muss IMMER im Dialog stehen –
        // auch bei Leuten, die selbst nichts dazugeschrieben haben.
        st_expect($jsP === '' || (str_contains($jsP, 'HAUSREGEL') && str_contains($jsP, 'AStA-intern')),
            'app.js: Die feste Regel „Nummern sind intern und werden nicht weitergegeben" fehlt im Dialog');
    });
    $run('Feier-Momente („Puster"): Registry, Auslöser und Ziele', function () {
        // Der Effekt hängt an DREI Stellen, die zueinander passen müssen: puste_merken() in der
        // Seite, die Registry in app.js und das Ziel-Element. Passt eine nicht, passiert einfach
        // nichts – ohne Fehler, ohne Log. Genau das soll hier auffallen.
        $wurzel = __DIR__ . '/..';
        $js  = (string)@file_get_contents($wurzel . '/assets/app.js');
        $css = (string)@file_get_contents($wurzel . '/assets/style.css');
        $lib = (string)@file_get_contents($wurzel . '/lib.php');

        // Momente einsammeln: der übliche Weg sind Knöpfe mit data-puste, dazu der Server-Weg
        // (puste_merken) für Formulare, die man nicht in den Hintergrund schieben will.
        $momente = [];
        foreach (glob($wurzel . '/*.php') ?: [] as $datei) {
            $inhalt = (string)@file_get_contents($datei);
            if (preg_match_all('~data-puste="([a-z]+)"~', $inhalt, $tr)) foreach ($tr[1] as $m) $momente[$m] = true;
            if (preg_match_all("~puste_merken\('([a-z]+)'\)~", $inhalt, $tr)) foreach ($tr[1] as $m) $momente[$m] = true;
        }
        st_expect($momente !== [], 'Kein einziger Feier-Moment ist verdrahtet – weder data-puste noch puste_merken()');
        // Drei Listen müssen deckungsgleich sein: was verdrahtet ist, das Verzeichnis in lib.php
        // (davon hängen die Diagnose-Knöpfe ab) und die Registry in app.js.
        foreach (array_keys($momente) as $m) {
            st_expect(in_array($m, puste_momente(), true),
                'Feier-Moment „' . $m . '" ist verdrahtet, fehlt aber in puste_momente() – dann gibt es dafür auch keinen Test-Knopf in der Diagnose');
        }
        // Der Klick-Weg lebt davon, dass das Formular im Hintergrund geht und die Seite danach
        // NEU LÄDT. Fehlt eins von beidem, bliebe entweder der Effekt aus oder der Stand veraltet.
        st_expect($js === '' || (str_contains($js, "e.preventDefault()") && str_contains($js, 'location.reload()') && str_contains($js, 'data-puste')),
            'app.js: Der Klick-Weg (Effekt am Knopf, Formular im Hintergrund, danach neu laden) ist unvollständig');
        st_expect($js === '' || str_contains($js, 'form.submit()'),
            'app.js: Ohne Rückfallweg (normales Absenden) ginge bei Netzproblemen die Aktion verloren');
        if ($js !== '') {
            foreach (puste_momente() as $m) {
                st_expect(preg_match('~\n\s*' . preg_quote($m, '~') . ':\s*\{~', $js) === 1,
                    'Feier-Moment „' . $m . '" steht in puste_momente(), aber nicht in der Registry in app.js');
            }
        }
        st_expect(count(puste_momente(true)) === count(puste_momente()), 'puste_momente(): Beschriftungen und Schlüssel passen nicht zusammen');
        // Der eine-Schuss-Weg: merken → page_header holt ab → app.js bekommt ihn
        st_expect(str_contains($lib, "\$_SESSION['puste']") && str_contains($lib, 'window.ASTA_PUSTE'),
            'Der Weg Sitzung → page_header → app.js (window.ASTA_PUSTE) ist unterbrochen');
        st_expect(puste_klasse('gibtsnicht') === '', 'puste_klasse darf ohne passenden Moment nichts markieren');
        // Ohne diese CSS-Bausteine bliebe der Effekt unsichtbar
        foreach (['.puste-pop', '.puste-teil', '@keyframes puste-links', '@keyframes puste-rechts',
                  '.foot-herz', '@keyframes foot-schlag', '.ww-searchbar.ww-leer'] as $stueck) {
            st_expect($css === '' || str_contains($css, $stueck), 'style.css: „' . $stueck . '" fehlt – der Effekt liefe ins Leere');
        }
        // Rücksicht auf „weniger Bewegung" ist hier Pflicht, nicht Kür
        st_expect($js === '' || str_contains($js, 'prefers-reduced-motion'), 'app.js: Der Puster muss reduzierte Bewegung respektieren');
        // Symbole der Registry müssen es in der Icon-Schrift geben (sonst leerer Kasten im Flug)
        $tab = (string)@file_get_contents($wurzel . '/assets/tabler/tabler-icons.min.css');
        if ($js !== '' && $tab !== '' && preg_match_all("~ic:\s*'(ti-[a-z0-9-]+)'~", $js, $ic)) {
            foreach (array_unique($ic[1]) as $name) {
                st_expect(str_contains($tab, '.' . $name . ':before'), "Puster-Symbol $name gibt es in der Tabler-Schrift nicht");
            }
        }
        // Das Herz im Fuß braucht seinen Rahmen – ohne den Span greift keine der Regeln
        st_expect(str_contains($lib, 'class="foot-herz"'), 'lib.php: Das Herzchen im Seitenfuß hat seinen Rahmen (.foot-herz) verloren');
    });
    $run('was.läuft: Trennung, Bild-Weiche, Freigabe-Fluss', function () {
        require_once __DIR__ . '/../wl-db.php';
        wl_db();

        // Der öffentliche Bereich darf die lib.php NIE einbinden – sonst hinge das Portal an der
        // Mitgliederdatenbank, und ein Fehler dort legte die öffentliche Seite mit lahm.
        $dbSrc = (string)@file_get_contents(__DIR__ . '/../wl-db.php');
        st_expect($dbSrc !== '', 'wl-db.php fehlt');
        st_expect(!str_contains($dbSrc, "/lib.php"), 'wl-db.php darf die lib.php NICHT einbinden');
        foreach (['wl-lib.php', 'index.php', 'v.php', 'kurse.php', 'einreichen.php', 'bild.php', 'ics.php'] as $datei) {
            $q = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/' . $datei);
            st_expect($q !== '', 'veranstaltungen/' . $datei . ' fehlt');
        }
        // --- Sicherheits-Durchsicht: Bremsen, die eine weggeworfene Sitzung überleben ---
        // Die alten Bremsen zählten allein in der Sitzung; wer das Cookie fallen ließ, bekam
        // einen frischen Zähler. Login und Passwort-Link hängen deshalb jetzt am Konto bzw. an
        // der Adresse. Die Prüfungen unten sind fachlich, nicht kosmetisch: Sie fallen aus,
        // sobald jemand die Sperre wieder herausnimmt.
        st_expect(function_exists('wl_bremse') && function_exists('wl_org_anmelden'),
            'die sitzungsunabhängigen Bremsen von was.läuft fehlen');
        $mSp = (array)(wl_db()->query('PRAGMA table_info(orgs)')->fetchAll() ?: []);
        $mNam = array_column($mSp, 'name');
        st_expect(in_array('fail_n', $mNam, true) && in_array('fail_bis', $mNam, true),
            'orgs braucht fail_n/fail_bis – ohne sie lässt sich der Veranstalter-Login unbegrenzt durchprobieren');
        st_expect(str_contains($dbSrc, "wl_bremse('reset-adresse'"),
            'der Passwort-Link ist nicht an der ANGEFRAGTEN ADRESSE gebremst – so lässt sich ein Postfach fluten');
        // Push-Adressen: nur echte Dienste. Sonst ruft der Cron später an, wohin man ihn schickt.
        st_expect(wl_push_host_ok('https://updates.push.services.mozilla.com/wpush/v2/x')
            && wl_push_host_ok('https://fcm.googleapis.com/fcm/send/x')
            && !wl_push_host_ok('https://irgendwo.example.com/x')
            && !wl_push_host_ok('http://fcm.googleapis.com/x'),
            'die Positivliste der Push-Dienste greift nicht – der Server ließe sich als Bote einspannen');
        // ICS: an Zeichengrenzen falten, sonst zerschneidet die Umbruch-Regel jeden Umlaut.
        $icsSrc = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/ics.php');
        st_expect(str_contains($icsSrc, 'mb_str_split'),
            'ics.php faltet wieder nach Bytes – das zerlegt Umlaute in den Titeln');
        // EINE Bot-Erkennung für alle Zählungen.
        st_expect(function_exists('wl_ist_bot')
            && str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php'), 'wl_ist_bot()'),
            'Seiten-Statistik und Aufruf-Zähler benutzen wieder verschiedene Bot-Listen');
        // --- Sprache: Englisch NUR für die feste Oberfläche, und NUR öffentlich ---
        // Der wichtigste Wächter ist der letzte: Ohne die Marke $GLOBALS['wl_public'] bekämen
        // die Verwaltung englische Kategorien und der Cron englische Push-Texte.
        st_expect(function_exists('wl_lang') && function_exists('wl_t'), 'die Sprachwahl von was.läuft fehlt');
        st_expect(wl_lang() === 'de', 'wl_lang() antwortet AUSSERHALB des öffentlichen Bereichs nicht mehr Deutsch – '
            . 'damit bekämen Verwaltung und Cron englische Texte');
        st_expect(str_contains($libSrc ?? '', "wl_public") || str_contains(
            (string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php'), "wl_public"),
            'wl-lib.php setzt die Marke für den öffentlichen Bereich nicht – die Sprache bliebe überall deutsch');
        // Jede Zeichenkette braucht beide Sprachen (leer heißt: fällt auf Deutsch zurück, das
        // ist erlaubt – ein fehlender SCHLÜSSEL dagegen liefert den Schlüssel selbst).
        foreach (wl_texte() as $tk => $tv) {
            st_expect(trim((string)($tv['de'] ?? '')) !== '' || $tk === 'nur_deutsch',
                "wl_texte(): „$tk\" hat keine deutsche Fassung");
        }
        st_expect(wl_t('nav_events') === 'Veranstaltungen', 'wl_t() liefert außerhalb des öffentlichen Bereichs nicht Deutsch');
        st_expect(wl_t('gibtsnicht') === 'gibtsnicht', 'wl_t() muss bei unbekanntem Schlüssel den Schlüssel zurückgeben');
        // Die pflegbaren Texte bleiben deutsch – wer sie übersetzt, macht es von Hand.
        st_expect(!str_contains((string)@file_get_contents(__DIR__ . '/../wl-db.php'), "wl_text_en("),
            'die pflegbaren Texte sollen NICHT automatisch übersetzt werden');

        // Melde-Weg: öffentlich vorhanden UND in der Verwaltung angekommen.
        st_expect(function_exists('wl_report_add') && function_exists('wl_reports_offen'),
            'der Melde-Weg für Beiträge fehlt');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php'), "'melden'"),
            'v.php bietet kein Melde-Formular an');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen.php'), "'meldungen'"),
            'die Verwaltung hat keinen Reiter für gemeldete Beiträge – der Melde-Knopf liefe ins Leere');
        // Mitteilung an die ZUSTÄNDIGEN Referate (nicht an alle mit Zugriff): Der Weg läuft über
        // den Cron, weil die öffentliche Seite die Stammliste nicht kennt und nie kennen soll.
        st_expect(isset(notify_types()['dm_waslaeuft']), 'der Mitteilungstyp für was.läuft fehlt im Register');
        st_expect(function_exists('wl_zustaendige_ids'), 'wl_zustaendige_ids() fehlt – niemand bekäme Bescheid');
        // Seit kann die Zuständigkeit auf REFERATE oder auf PERSONEN stehen. Der
        // Wächter prüfte nur den Referats-Fall und schlug im Personen-Modus zwangsläufig fehl –
        // die Eingetragenen haben dort ja irgendein Referat, das mit der Liste nichts zu tun hat.
        $zModus = zust_modus('waslaeuft');
        $zPers  = $zModus === 'personen' ? zust_personen('waslaeuft') : [];
        foreach (wl_zustaendige_ids() as $zid) {
            $zm = member_get((int)$zid);
            st_expect($zm && ($zModus === 'personen'
                    ? in_array((int)$zid, $zPers, true)
                    : in_array(trim((string)$zm['referat']), wl_referate(), true)),
                $zModus === 'personen'
                    ? 'wl_zustaendige_ids() liefert jemanden, der gar nicht als zuständige Person eingetragen ist'
                    : 'wl_zustaendige_ids() liefert jemanden, dessen Referat gar nicht zuständig ist');
        }
        $cronSrc = (string)@file_get_contents(__DIR__ . '/../cron_waslaeuft.php');
        st_expect(str_contains($cronSrc, 'wl_staff_neues_abholen') && str_contains($cronSrc, 'dm_waslaeuft'),
            'der Cron sagt den zuständigen Referaten nicht Bescheid');
        // Der Merker MUSS beim Abholen gesetzt werden, sonst wiederholt sich dieselbe Mitteilung
        // alle 15 Minuten, bis jemand freigibt.
        st_expect(str_contains($dbSrc, 'UPDATE items SET staff_done = 1')
            && str_contains($dbSrc, 'UPDATE item_edits SET staff_done = 1')
            && str_contains($dbSrc, 'UPDATE reports SET staff_done = 1'),
            'wl_staff_neues_abholen() setzt keinen Merker – die Mitteilung käme im Viertelstundentakt erneut');
        // Alle drei Dinge, die im Stapel landen, müssen gemeldet werden – Einreichungen,
        // Änderungen an Veröffentlichtem und Meldungen. GEPRÜFT WIRD AM QUELLTEXT, nicht durch
        // einen Aufruf: wl_staff_neues_abholen() setzt die Merker: Ein Selbsttest, der die
        // Funktion aufriefe, würde die nächste echte Mitteilung verschlucken.
        st_expect(str_contains($dbSrc, "'aenderungen' => 0") && str_contains($dbSrc, 'FROM item_edits e JOIN items'),
            'wl_staff_neues_abholen() zählt die wartenden Änderungen nicht mit');
        st_expect(str_contains($cronSrc, "aenderungen"),
            'der Cron erwähnt die wartenden Änderungen nicht');

        // Strenge Sicherheitsregeln wie in den übrigen öffentlichen Bereichen.
        $libSrc = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php');
        st_expect(str_contains($libSrc, "default-src 'none'"), 'was.läuft fehlt die strenge Content-Security-Policy');
        // Geprüft wird die KOPFZEILE selbst, nicht die ganze Datei: Im Kommentar darüber steht
        // erklärend das Wort „unsafe-inline", und eine Suche über den ganzen Quelltext schlug genau
        // darauf an – eine Zusicherung, die am eigenen Kommentar scheitert, ist keine.
        $cspPos = strpos($libSrc, "Content-Security-Policy: default-src");
        st_expect($cspPos !== false, 'die CSP-Kopfzeile von was.läuft ist nicht auffindbar');
        if ($cspPos !== false) {
            st_expect(!str_contains(substr($libSrc, $cspPos, 400), 'unsafe-inline'),
                'was.läuft erlaubt wieder unsafe-inline in der CSP-Kopfzeile');
        }
        // Unter dieser CSP wird jedes style="…" verworfen. Die Platzhalterfarben MÜSSEN deshalb
        // Klassen sein, sonst bleibt die Kachel grau.
        $css = (string)@file_get_contents(__DIR__ . '/../assets/wl.css');
        st_expect($css !== '', 'assets/wl.css fehlt');
        // Für alle Regel-Prüfungen die Kommentare herausnehmen. Sonst schlägt eine Prüfung auf
        // den ERKLÄRENDEN TEXT an, der beschreibt, warum es die Regel gerade nicht geben darf.
        // Das ist hier bereits dreimal passiert: „unsafe-inline" im CSP-Kommentar, „color:
        // inherit" im Kommentar zur a-Falle, und beim Wortmarken-Stil. Eine Prüfung, die den
        // eigenen Kommentar liest, prüft nichts.
        $cssRein = (string)preg_replace('~/\*.*?\*/~s', '', $css);
        for ($i = 1; $i <= 8; $i++) {
            st_expect(str_contains($cssRein, '.wl-ph-' . $i . ' {'), 'Platzhalterfarbe .wl-ph-' . $i . ' fehlt in wl.css');
        }
        st_expect(str_starts_with(wl_placeholder_class(0), 'wl-ph-') && wl_placeholder_class(7) !== wl_placeholder_class(8),
            'wl_placeholder_class() verteilt die Farben nicht');
        // Wortmarke: entschieden ist der Verlauf Gelb → Koralle, die Umschalt-Mechanik ist
        // ausgebaut. Der Verlauf muss fest an der Marke stehen (Wort UND Rad-Wörter, sonst
        // rollte die Animation farblos), und nirgends darf noch die alte Registry auftauchen.
        st_expect((bool)preg_match('~\.wl-mark-w,\s*\.wl-mark-item\s*\{[^}]*linear-gradient\(92deg,\s*#ffd93d~', $cssRein),
            'der feste Marken-Verlauf Gelb → Koralle fehlt in wl.css (.wl-mark-w, .wl-mark-item)');
        st_expect(!str_contains($cssRein, '.wl-l-'), 'wl.css enthält noch .wl-l-*-Stilvarianten – die Wortmarken-Mechanik sollte ausgebaut sein');
        st_expect(!function_exists('wl_logo_stile'), 'wl_logo_stile() existiert wieder – die Wortmarken-Registry sollte ausgebaut sein');
        st_expect(!str_contains($libSrc, 'wl_logo_stil'), 'wl-lib.php ruft noch wl_logo_stil() auf – die Funktion gibt es nicht mehr');
        // Sprachumschalter: EIN Flaggen-Knopf unten in der Mitte. Er zeigt das ZIEL, nicht den
        // Zustand – und der Ehrlichkeits-Hinweis (nur die Oberfläche ist übersetzt) muss bleiben,
        // sonst erwarten englische Leser:innen übersetzte Beiträge.
        // Nur gegen den QUELLTEXT prüfen: wl-lib.php gehört zum öffentlichen Bereich und wird von
        // der Verwaltung nie geladen – function_exists() ist hier immer false.
        st_expect(str_contains($libSrc, 'function wl_sprachknopf(') && str_contains($libSrc, 'wl_sprachknopf()'),
            'wl-lib.php: der Sprachumschalter fehlt in der Fußzeile');
        st_expect(str_contains($libSrc, "wl_t('nur_deutsch')"),
            'wl-lib.php: der Hinweis, dass nur die Oberfläche übersetzt ist, ist verschwunden');
        st_expect(!str_contains($libSrc, 'wl-lang-wahl'),
            'wl-lib.php: die alte zweispaltige Sprachwahl ist zurück – gewollt ist der eine Flaggen-Knopf');
        // KEINE Bottom-Tab-Bar für die wl-PWA – die Seite bleibt oben-navigiert.
        st_expect(!str_contains($libSrc, 'wl-tabbar'), 'wl-lib.php enthält wieder eine wl-tabbar – der Testlauf wurde vom User verworfen');
        st_expect(!str_contains($cssRein, '.wl-tabbar'), 'wl.css enthält wieder .wl-tabbar-Regeln – der Testlauf wurde vom User verworfen');
        // Zugang für zuständige Referate: Ihr Recht greift, aber die Kachel liegt in der
        // Verwaltung – und die weist Mitglieder ohne Orga-Rolle ab. Ohne eigenen Menüpunkt wäre
        // die Seite für sie nur über die getippte Adresse erreichbar (gleiche Regel wie beim
        // Pat:innenprogramm). Das `!can_admin()` gehört dazu, sonst steht der Punkt bei Vorsitz
        // und Admin doppelt: einmal hier, einmal über die Verwaltung.
        $appSrc = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect((bool)preg_match('~wl_can_manage\(\)\s*&&\s*!can_admin\(\).*?veranstaltungen\.php~s', $appSrc),
            'der Menüpunkt „was.läuft" für zuständige Referate fehlt – sie kämen nur noch über die getippte Adresse hin');
        st_expect(wl_placeholder_class(5) === wl_placeholder_class(5 + 8),
            'gleiche ID muss immer dieselbe Farbe ergeben');
        // ---- Der Wortwechsel in der Überschrift ----------------------------------------------
        // Das Tempo steht in CSS-Variablen, gesetzt werden sie im Skript. Wer eine davon in nur
        // einer der beiden Dateien umbenennt, merkt es nicht: Die Animation liefe still in der
        // Voreinstellung weiter, also immer langsam.
        $idxSrc = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/index.php');
        st_expect($idxSrc !== '', 'veranstaltungen/index.php fehlt');
        // Mit Wortgrenze, nicht mit str_contains: „--wl-rad-fade" enthält „--wl-rad-f" – ein
        // Umbenennen wäre sonst genau der Fall, den diese Prüfung durchgehen ließe.
        foreach (['--wl-rad-t', '--wl-rest-t'] as $v) {
            $wort = '~' . preg_quote($v, '~') . '(?![-\w])~';
            st_expect((bool)preg_match($wort, $cssRein), 'Tempo-Variable ' . $v . ' fehlt in wl.css');
            st_expect((bool)preg_match($wort, $idxSrc), 'index.php setzt die Tempo-Variable ' . $v . ' nicht mehr');
        }
        // Zwei Takte: Ankunft in Ruhe, Weiterklicken schnell. Fällt die Herkunfts-Erkennung weg,
        // sieht man bei jedem Filterwechsel wieder den vollen Vorspann.
        st_expect(str_contains($idxSrc, 'document.referrer.indexOf(basis) === 0'),
            'die Herkunfts-Erkennung fehlt – der Vorspann liefe bei jedem Filterwechsel in voller Länge');
        // „halt" ist die Pause vor dem Ausblenden und MUSS mindestens so lang sein wie „dreh" –
        // sonst blendet das Rad noch in voller Fahrt aus und hinterlässt einen Schmierer.
        // (Genau daran ist der ruhige Takt beim Bauen hängengeblieben: 300 ms Pause, 340 ms Weg.)
        if (preg_match_all('~start:\s*(\d+), schritt:\s*(\d+), halt:\s*(\d+), dreh: \'\.(\d+)s\'~', $idxSrc, $mm, PREG_SET_ORDER)) {
            st_expect(count($mm) === 2, 'es sollten genau zwei Takte sein: Ankunft und Seitenwechsel');
            foreach ($mm as $t) {
                st_expect((int)$t[3] >= (int)round((float)('0.' . $t[4]) * 1000),
                    'Takt mit Start ' . $t[1] . ' ms: „halt" ist kürzer als „dreh" – es schaltet in voller Fahrt um');
            }
            if (count($mm) === 2) {
                // Der Seitenwechsel steht IMMER zuerst im Quelltext (die Bedingung wechsel ? … : …).
                // Er darf nicht einrasten: schritt = 0 heißt „ein einziger durchgehender Weg".
                // Mit Stufen sah es aus wie ein Schluckauf, und genau das soll nicht zurückkommen.
                st_expect((int)$mm[0][2] === 0,
                    'der Takt beim Seitenwechsel rastet wieder Stufe für Stufe ein – das war der Schluckauf');
                st_expect((int)$mm[1][2] > 0,
                    'der Takt bei der Ankunft rastet nicht mehr ein – dann liest niemand die Wörter');
            }
        } else {
            st_expect(false, 'die beiden Takte des Wortwechsels sind nicht mehr auffindbar');
        }
        // Keine Überblendung zwischen Rad und echtem Wort: zwei halbdurchsichtige Kopien
        // desselben Wortes ergeben eine sichtbare Dichte-Delle („läuft" poppte kurz weg).
        st_expect(!preg_match('~\.wl-mark-anim \.wl-mark-live\s*\{[^}]*transition~', $cssRein),
            'das echte Wort blendet wieder über – dann poppt „läuft" am Ende kurz weg');
        st_expect(!preg_match('~\.wl-mark-rad\s*\{[^}]*transition~', $cssRein),
            'das Rad blendet wieder über – dann poppt „läuft" am Ende kurz weg');
        // Das Zeitwort kommt eigens herein: Element im HTML, Auslöser im Skript, Bewegung im CSS.
        // Alle drei Teile müssen da sein, sonst passiert schlicht nichts.
        st_expect(str_contains($idxSrc, 'class="wl-h1-zeit"'), 'das Zeitwort hat kein eigenes Element mehr');
        st_expect(str_contains($idxSrc, "classList.add('is-zeit')"), 'das Skript löst die Zeitwort-Animation nicht aus');
        st_expect(str_contains($cssRein, '@keyframes wl-zeit-in') && str_contains($cssRein, '@keyframes wl-zeit-strich'),
            'die Zeitwort-Animation fehlt in wl.css');
        // Der Buchstaben-Auftritt von „Landau": Keyframes im Stylesheet, das Zerlegen im Skript.
        // Fehlt eines von beiden, stünden die Spannen dauerhaft auf opacity 0 – unsichtbare Stadt.
        st_expect(str_contains($cssRein, '@keyframes wl-stadt-in')
                && str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/index.php'), 'wl-h1-stadt'),
            'der Buchstaben-Auftritt von „Landau" ist unvollständig (Keyframes oder Zerlege-Skript fehlt)');
        // Die Buchstaben-Spannen dürfen NIE inline-block werden: Atomic-Inline-Kästen erzeugen
        // Umbruchgelegenheiten zwischen sich – „Landau" brach am Handy mitten im Wort
        // („Land / au?"). Deshalb bewegt die Animation nur die Deckkraft, keine Transformation.
        st_expect(!preg_match('~\.wl-h1-stadt span\s*\{[^}]*inline-block~', $cssRein),
            'die „Landau"-Buchstaben sind wieder inline-block – das Wort bricht dann mitten drin um');
        /* Die Filterleiste: drei Bedienelemente, jedes mit einer eigenen Aufgabe.
           Beide öffentlichen Seiten benutzen denselben Helfer für die Kategorien – sonst
           driftet die eine Leiste von der anderen weg, sobald jemand nur eine anfasst. */
        st_expect(str_contains($libSrc, 'function wl_kat_dropdown'),
            'wl_kat_dropdown() fehlt – die Kategorien wären wieder sieben einzelne Knöpfe');
        foreach (['index.php', 'kurse.php'] as $seite) {
            $q = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/' . $seite);
            st_expect(str_contains($q, 'wl_kat_dropdown('),
                $seite . ' benutzt das gemeinsame Kategorie-Menü nicht mehr');
            st_expect(substr_count($q, 'wl-prom') >= 2,
                $seite . ': die beiden hervorgehobenen Schalter fehlen');
        }
        // Das Menü darf NICHT von einem Skript abhängen: Unter der strengen CSP wäre es sonst
        // ohne JavaScript unbedienbar. <details> kann der Browser von sich aus.
        st_expect(str_contains($libSrc, '<details class="wl-drop'),
            'das Kategorie-Menü ist kein <details> mehr – ohne JavaScript wäre es unbedienbar');
        // Eingeschaltet werden die beiden Schalter limette – dieselbe Signalfarbe wie die
        // „kostenlos"-Marke auf den Kacheln. Ohne die Regel sähen sie aus wie alles andere.
        st_expect((bool)preg_match('~\.wl-prom\.on\s*\{[^}]*background:\s*var\(--wl-acc-3\)~', $cssRein),
            'die aktiven Schalter sind nicht mehr limette – dann heben sie sich nicht mehr ab');
        // Einen Schein hinter „läuft" darf es nicht geben; die Sperre steht weiter unten bei
        // den Ostereiern.
        // Die Wortmarke IN DER APP: Der Helfer gibt Klassen aus. Fehlt die Regel dazu in der
        // style.css, steht dort einfach schwarzer Text und niemandem fällt es auf.
        $appCss = (string)preg_replace('~/\*.*?\*/~s', '',
            (string)@file_get_contents(__DIR__ . '/../assets/style.css'));
        foreach (['wlm', 'wlm-p', 'wlm-w'] as $k) {
            st_expect(str_contains(wl_marke_app(), 'class="' . $k . '"'),
                'wl_marke_app() gibt die Klasse ' . $k . ' nicht mehr aus');
        }
        /* Jede .wlm-Regel steht ZWEIMAL: hell als Grundfassung, dunkel als Überschreibung im
           @media-Block. Eine Prüfung über die ganze Datei findet immer irgendeine davon und
           meldet Erfolg, auch wenn genau die gebrauchte fehlt. Deshalb wird hier ausdrücklich
           nur der helle Block betrachtet – alles ab dem ersten @media danach gehört nicht mehr
           dazu. (Diese Falle ist beim Bauen dreimal zugeschlagen; siehe auch die Kommentare
           weiter oben zum Ruhe-Block und zum Kommentar-Text.) */
        $wlmPos  = strpos($appCss, '.wlm {');
        $wlmEnde = $wlmPos === false ? false : strpos($appCss, '@media', $wlmPos);
        $wlmHell = $wlmPos === false ? '' : substr($appCss, $wlmPos,
            ($wlmEnde === false ? 800 : $wlmEnde - $wlmPos));
        st_expect((bool)preg_match('~\.wlm\s*\{[^}]*font-weight~', $wlmHell),
            'style.css: die Grundregel für .wlm fehlt');
        st_expect((bool)preg_match('~\.wlm-p\s*\{[^}]*color:~', $wlmHell),
            'style.css: der Punkt der Marke hat im hellen Modus keine Farbe mehr');
        st_expect((bool)preg_match('~\.wlm-w\s*\{[^}]*color:\s*transparent~', $wlmHell),
            'style.css: dem „läuft" der App fehlt im hellen Modus der Verlauf – der Text wäre unsichtbar');
        // Jede Kategorie legt DREI Werte fest: Schrift auf hell, Feldfarbe, Schrift auf dunkel.
        // Alle drei werden gebraucht – der Chip nutzt die ersten beiden, die Kategorie-Zeile auf
        // der Kachel je nach Farbmodus den ersten oder den dritten. Fehlt einer, steht dort
        // stillschweigend der Ersatzwert (grau) statt der Kategorienfarbe.
        foreach (wl_cats() as $ck => $cd) {
            $regel = (bool)preg_match('~\.' . preg_quote((string)$cd['css'], '~') . '\s*\{([^}]*)\}~', $cssRein, $mc);
            st_expect($regel, 'Kategorie „' . $ck . '" hat keine Farbregel in wl.css');
            foreach (['--wl-c', '--wl-c-bg', '--wl-c-d'] as $v) {
                st_expect($regel && (bool)preg_match('~' . preg_quote($v, '~') . '(?![-\w])\s*:~', $mc[1]),
                    'Kategorie „' . $ck . '": ' . $v . ' fehlt');
            }
        }
        foreach (wl_org_arten() as $ok => $od) {
            st_expect(str_contains($cssRein, '.' . (string)$od['css'] . ' '),
                'Veranstalter-Art „' . $ok . '" hat keine Farbregel in wl.css');
        }
        // Die a-Falle: `body.wl a { color: inherit }` ist spezifischer als eine einzelne Klasse.
        // Wer die Schriftfarbe einer dunklen Kachel am <a> selbst setzt, sieht sie still
        // verworfen – die Titel stünden dunkel auf dunklem Grund. Sie MUSS deshalb auf dem
        // inneren Element stehen. Geprüft an den Flächen, die es gibt: Kurs-Tafel und Banner.
        foreach (['.wl-ku-t' => 'Kurs-Tafel', '.wl-banner-t' => 'Banner'] as $sel => $wo) {
            st_expect((bool)preg_match('~' . preg_quote($sel, '~') . '\s*\{[^}]*color:~', $cssRein),
                $wo . ': ' . $sel . ' hat keine eigene Schriftfarbe – auf dunklem Grund wäre der Titel unlesbar');
        }
        foreach (['.wl-ku' => 'Kurs-Tafel', '.wl-banner' => 'Banner'] as $sel => $wo) {
            st_expect(!preg_match('~' . preg_quote($sel, '~') . '\s*\{[^}]*[^-]color:~', $cssRein),
                $wo . ': ' . $sel . ' setzt wieder selbst eine Schriftfarbe – die wird von der a-Regel verworfen');
        }
        // Banner-Redesign „Schlagzeilen-Plakat": vollflächiges Bild + Scrim im
        // after (ein::before läge UNTER dem <img>), abgeleiteter Zeitraum-Kicker, CTA-Pille.
        // ($qIndex gibt es erst weiter unten – hier deshalb eine eigene Quelle.)
        $qIndexBan = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/index.php');
        st_expect(str_contains($qIndexBan, 'wl-banner-kick') && str_contains($qIndexBan, 'wl-banner-cta')
                && str_contains($qIndexBan, 'wl-banner-bild'),
            'Startseite: das Banner-Plakat (Kicker/CTA/vollflächiges Bild) ist zurückgebaut');
        st_expect(str_contains($cssRein, '.wl-banner-bild') && str_contains($cssRein, '.wl-banner-kick')
                && (bool)preg_match('~\.wl-banner::after~', $cssRein),
            'wl.css: die Plakat-Regeln des Banners (Bild/Kicker/Scrim-::after) fehlen');
        // Die Pillen-Falle: Ein allgemeines `.wl-banner-t > span` schlug Kicker UND CTA-Pille
        // per Spezifität – helle Schrift auf Limette (aufgefallen). Der
        // Untertitel braucht seine EIGENE Klasse, die Pille ihre eigene Tinten-Farbe.
        st_expect(str_contains($cssRein, '.wl-banner-sub') && !str_contains($cssRein, '.wl-banner-t > span')
                && (bool)preg_match('~\.wl-banner-cta\s*\{[^}]*color:\s*var\(--wl-ink\)~', $cssRein),
            'Banner: der Untertitel-Selektor ist wieder zu breit oder die CTA-Pille verliert ihre Tinten-Schrift');
        /* Die Kurzschrift-Falle: `background:` setzt auch background-image – auf none. Die
           Detailseite ohne Bild trägt eine .wl-ph-Klasse mit den Logo-Lichtern, aber die Regel
           .wl-det-hero steht SPÄTER in der Datei; mit der Kurzschrift löschte sie die Lichter
           stillschweigend und die Fläche war ein toter dunkler Balken über die volle Breite. */
        st_expect((bool)preg_match('~\.wl-det-hero\s*\{[^}]*background-color:~', $cssRein)
                && !preg_match('~\.wl-det-hero\s*\{[^}]*[^-]background:~', $cssRein),
            'Detailseite: .wl-det-hero muss background-color statt background setzen – die Kurzschrift löscht die Platzhalter-Lichter');
        // Der Link neben einer Abschnitts-Überschrift („Alle Kurse →") darf nie umbrechen – am
        // Handy stand der Pfeil sonst allein in einer Zeile unter dem Text.
        st_expect((bool)preg_match('~\.wl-sect-h a\s*\{[^}]*white-space:\s*nowrap~', $cssRein),
            'Abschnitts-Links brechen wieder um – white-space: nowrap fehlt an .wl-sect-h a');
        /* „Filter zurücksetzen" gehört NEBEN die Überschrift, nicht in die Filterleiste: Dort
           erschien es nur bei aktivem Filter und gab der Leiste am Handy eine dritte Zeile.
           Geprüft wird der Ausschnitt BIS zum ersten <section> – der Link darf ja weiterhin
           in der Datei stehen, nur eben nicht in der Leiste. */
        $qKurse  = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/kurse.php');
        $lAnfang = strpos($qKurse, 'wl-filters');
        // das erste <section NACH der Leiste – die Bühne davor ist auch eine <section>
        $lEnde   = $lAnfang === false ? false : strpos($qKurse, '<section', $lAnfang);
        st_expect($lAnfang !== false && $lEnde !== false && $lAnfang < $lEnde
                && !str_contains(substr($qKurse, $lAnfang, $lEnde - $lAnfang), 'wl-fclear'),
            'kurse.php: „Filter zurücksetzen" steht wieder in der Filterleiste – sie ändert dann ihre Höhe');
        st_expect(str_contains($qKurse, 'wl-sect-links'),
            'kurse.php: die Link-Reihe neben der Überschrift (wl-sect-links) fehlt');
        // Die aktive Stufe des Zeit-Schalters muss am Handy in den Ausschnitt gerückt werden –
        // sonst sieht bei „Diesen Monat" keine Stufe gewählt aus (die aktive liegt rechts
        // außerhalb, der Schalter startet immer ganz links).
        $qIndex = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/index.php');
        st_expect(str_contains($qIndex, 'seg.scrollLeft'),
            'index.php: der Zeit-Schalter rückt die aktive Stufe nicht mehr ins Bild');
        /* Der dynamische Grundzustand: Landung = diese Woche, aber nur ab WL_WOCHE_MIN
           angezeigten Veranstaltungen, sonst „demnächst" – eine fast leere Startseite ist der
           schlechteste erste Eindruck. Dazu gehört der Ausweg „Alle anzeigen" neben der
           Abschnitts-Überschrift und der ausdrückliche Schlüssel 'alle' im Zeit-Schalter
           (der leere Parameter gehört allein der Dynamik). */
        st_expect(str_contains($qIndex, 'WL_WOCHE_MIN') && str_contains($qIndex, "'alle'")
                && str_contains($qIndex, 'Alle anzeigen'),
            'index.php: der dynamische Grundzustand (Woche mit Demnächst-Rückfall + „Alle anzeigen") fehlt');
        /* Der öffentliche „Eintragen"-Knopf: MAILBASIERTER Login (Mailadresse + Passwort,
           Passwort-Setzen über den Mail-Link – Einladung wie „Passwort vergessen" sind EIN
           Mechanismus) + Zugangsanfrage. Ohne das Anfrage-Formular wäre der Knopf wieder eine
           Mail-Sackgasse, ohne den Honigtopf ein Spam-Einfallstor, und ohne den Stapel in der
           Verwaltung liefen die Anfragen ins Leere. */
        st_expect(function_exists('wl_org_anmelden') && function_exists('wl_org_passwort_setzen')
                && function_exists('wl_org_mail_frei') && function_exists('wl_org_zugangsmail')
                && function_exists('wl_org_reset_start') && function_exists('wl_org_by_reset')
                && function_exists('wl_mail'),
            'die Anmelde-/Reset-Funktionen der Veranstalter fehlen in wl-db.php');
        // Der Bereich muss im Mail-Topf angemeldet sein, sonst zählt sein Verbrauch nicht
        // gegen die Reserve, die die Login-Links der App schützt.
        st_expect(isset(mail_pool_quellen()['waslaeuft']),
            'was.läuft fehlt als Quelle im Mail-Topf (mail_pool_quellen)');
        st_expect(function_exists('wl_org_request_add') && function_exists('wl_org_requests')
                && function_exists('wl_org_request_delete'),
            'die Zugangsanfrage-Funktionen fehlen in wl-db.php');
        $qEinr = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/einreichen.php');
        st_expect(str_contains($qEinr, "'login'") && str_contains($qEinr, "'abmelden'")
                && str_contains($qEinr, "'reset'") && str_contains($qEinr, "'neupass'")
                && str_contains($qEinr, "'anfrage'") && str_contains($qEinr, 'wl-hp'),
            'einreichen.php: Anmelden/Abmelden/Passwort-Strecke/Zugangsanfrage (oder der Honigtopf) fehlt');
        // Veranstalter-DASHBOARD: Nach dem Login erst die Übersicht
        // (Zahlen, Karten, Beiträge) – das Formular NUR auf ausdrücklichen Klick (?t=neu).
        st_expect(str_contains($qEinr, 'wl-dash-karten') && str_contains($qEinr, 't=neu')
                && str_contains($qEinr, "\$tab === 'neu'"),
            'einreichen.php: das Veranstalter-Dashboard fehlt oder das Formular steht wieder direkt nach dem Login');
        // Formular-Redesign „Neuer Beitrag": nummerierte Abschnitte, Typ als
        // Kachel-Paar (Radios!), bedingte Felder (data-nur) und die Vorschau als klebende
        // Spalte. Das Skript muss den GEDRÜCKTEN kind-Radio lesen (art()), nicht den ersten.
        st_expect(str_contains($qEinr, 'wl-nb-abs') && str_contains($qEinr, 'wl-typ')
                && str_contains($qEinr, 'data-nur') && str_contains($qEinr, 'wl-nb-seite')
                && str_contains($qEinr, "[name=\"kind\"]:checked"),
            'einreichen.php: das Formular-Redesign (Abschnitte/Typ-Kacheln/data-nur/Vorschau-Spalte) fehlt oder kind wird wieder als Einzelfeld gelesen');
        // Promo-Seite der Gruppen: Logo (orgs.logo_id) + eigene Bilder mit
        // Deckel WL_ORG_IMG_MAX (zentral in wl_image_store); das Logo bleibt aus dem
        // Bild-Wähler draußen, und die eigene Veranstalter-Seite trägt den Bearbeiten-Knopf
        // (Profil-Karte und Seiten-Karte waren ZWEI Karten für EINE Sache – Festlegung).
        st_expect(in_array('logo_id', array_column(wl_db()->query('PRAGMA table_info(orgs)')->fetchAll(), 'name'), true),
            'orgs.logo_id fehlt – die Gruppen-Logos gingen verloren');
        st_expect(str_contains($qEinr, "'logo'") && str_contains($qEinr, "'bildneu'")
                && str_contains($qEinr, "'bildweg'") && str_contains($qEinr, 't=promo'),
            'einreichen.php: die Promo-Seite (Logo/Bilder) fehlt');
        // Eigene Beiträge löschen: nur die EIGENEN (org_id-Prüfung),
        // mit Rückfrage – und wl_item_delete() muss offene Erinnerungen mit abräumen,
        // sonst klingelte der Cron für Gelöschtes.
        st_expect(str_contains($qEinr, "'beitragweg'") && str_contains($qEinr, 'wl_item_delete')
                && preg_match('~beitragweg.*?org_id.*?wl_item_delete~s', $qEinr),
            'einreichen.php: das Löschen eigener Beiträge (beitragweg samt org_id-Prüfung) fehlt');
        // …und die Rückfrage stellt der EIGENE Dialog (kein Browser-
        // confirm, roter Papierkorb-Knopf in ordentlicher Größe).
        st_expect(str_contains($qEinr, 'wl-weg-dialog') && str_contains($qEinr, 'wl-weg-btn')
                && !str_contains($qEinr, 'confirm('),
            'einreichen.php: der eigene Lösch-Dialog fehlt oder das Browser-confirm ist zurück (User-Veto)');
        // Studi-Rabatt: Feld im Formular (nur bei Preis sichtbar),
        // Spalte am Beitrag, Zeile auf der Detailseite – NUR wenn ein Preis dransteht.
        st_expect(in_array('studi_rabatt', array_column(wl_db()->query('PRAGMA table_info(items)')->fetchAll(), 'name'), true)
                && str_contains($qEinr, 'studi_rabatt'),
            'Der Studi-Rabatt (items.studi_rabatt + Formularfeld) fehlt');
        st_expect(preg_match('~studi_rabatt.*?preis.*?Für Studis~s', (string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php')),
            'v.php: die Studi-Rabatt-Zeile fehlt oder hängt nicht mehr am Preis');
        // „Nur für Studierende": PFLICHTFRAGE beim Eintragen – ohne Vorgabe,
        // serverseitig geprüft (das required-Attribut allein wäre nur eine Browser-Bitte) – und
        // eine eigene Zeile auf der Detailseite. Ein stilles Standard-„nein" ist ausdrücklich
        // nicht gewollt: Wer nicht antwortet, hat die Frage übersehen.
        $qV = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php');
        st_expect(in_array('nur_studis', array_column(wl_db()->query('PRAGMA table_info(items)')->fetchAll(), 'name'), true),
            'items.nur_studis fehlt – die Angabe „nur für Studierende" hätte keinen Platz');
        st_expect(substr_count($qEinr, 'name="nur_studis"') === 2 && str_contains($qEinr, 'Wer darf kommen?'),
            'einreichen.php: die Frage „Wer darf kommen?" braucht BEIDE Antworten als Auswahlkreise');
        st_expect(preg_match('~\$fehler\s*===\s*\'\'\s*&&\s*!in_array\(\(string\)wl_param\(\$_POST\[.nur_studis.\]~', $qEinr),
            'einreichen.php: die Pflichtfrage wird nicht mehr serverseitig geprüft – required im '
            . 'Browser allein genügt nicht');
        // Kein FEST gesetztes checked: dafür die PHP-Blöcke herausnehmen und nur ansehen, was
        // roh im HTML steht. (Die Vorauswahl beim Bearbeiten kommt aus $studiAlt und ist
        // erwünscht – eine Prüfung auf das bloße Wort „checked" schlüge darauf fälschlich an.)
        $einrHtml = (string)preg_replace('~<\?.*?\?>~s', '', $qEinr);
        st_expect(!preg_match('~name="nur_studis"[^>]*checked~', $einrHtml),
            'einreichen.php: keine der beiden Antworten darf fest vorbelegt sein – die Frage soll '
            . 'beantwortet und nicht durchgewinkt werden');
        st_expect(str_contains($qV, "nur_studis") && str_contains($qV, "wl_t('nur_studis')"),
            'v.php: die Zeile „Nur für Studierende" fehlt auf der Beitragsseite');
        foreach (['nur_studis', 'nur_studis_hint'] as $tk) {
            st_expect(trim(wl_t($tk)) !== '', 'im Übersetzungsregister fehlt „' . $tk . '"');
        }
        // Kategorie als PILLEN statt Dropdown + Vorschlags-Dialog:
        // Wünsche landen als Stapel (cat_wuensche) in der Verwaltung; gewählt werden muss
        // trotzdem eine bestehende Kategorie (Radios, eine ist immer an).
        st_expect(str_contains($qEinr, 'wl-kats') && !str_contains($qEinr, '<select name="cat"')
                && str_contains($qEinr, "'katwunsch'") && str_contains($qEinr, '[name="cat"]:checked'),
            'einreichen.php: die Kategorie-Pillen oder der Vorschlags-Dialog fehlen – oder das Dropdown ist zurück (User-Veto)');
        // $qVer entsteht erst weiter unten (Promo-Sektion) – eigene Quelle laden.
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen.php'), 'katwunsch_del')
                && function_exists('wl_cat_wuensche'),
            'Die Kategorie-Vorschläge (cat_wuensche) fehlen in der Verwaltung');
        // Endgültiges Veranstalter-Löschen: wl_org_delete räumt Beiträge
        // über wl_item_delete (Erinnerungen + Bilddateien!) sowie Bilder/Logo/Abos mit ab.
        st_expect(function_exists('wl_org_delete')
                && preg_match('~function wl_org_delete.*?wl_item_delete.*?push_follows.*?DELETE FROM orgs~s',
                       (string)@file_get_contents(__DIR__ . '/../wl-db.php'))
                && str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen.php'), 'org_del'),
            'Das endgültige Veranstalter-Löschen fehlt oder räumt Anhängendes nicht mehr mit ab');
        st_expect(preg_match('~function wl_item_delete.*?push_reminders.*?DELETE FROM items~s',
                (string)@file_get_contents(__DIR__ . '/../wl-db.php')),
            'wl_item_delete() räumt die Erinnerungen nicht mehr mit ab – der Cron erinnerte an Gelöschtes');
        st_expect(defined('WL_ORG_IMG_MAX') && WL_ORG_IMG_MAX === 30
                && preg_match('~WL_ORG_IMG_MAX~', (string)@file_get_contents(__DIR__ . '/../wl-db.php')),
            'der Bilder-Deckel je Veranstalter (WL_ORG_IMG_MAX = 30) fehlt oder wurde verstellt');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../wl-db.php'), 'logo_id FROM orgs'),
            'wl_images_for_org() klammert das Logo nicht mehr aus – es stünde als Veranstaltungsbild zur Wahl');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/veranstalter.php'), 'einreichen.php?t=profil')
                && str_contains($libSrc, 'function wl_org_avatar'),
            'veranstalter.php: Bearbeiten-Knopf der eigenen Seite oder die Absender-Marke (wl_org_avatar) fehlt');
        // Der Login darf nicht verraten, ob es die Adresse gibt – EINE Fehlermeldung für beide
        // Fälle, gegen Zeitmessung wird immer ein Hash geprüft, und auch „Passwort vergessen"
        // antwortet für bekannte wie unbekannte Adressen gleich.
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../wl-db.php'), 'nur-zum-zeitausgleich'),
            'wl_org_anmelden() prüft ohne Zeitausgleich – Mailadressen wären erratbar');
        $qVerw = (string)@file_get_contents(__DIR__ . '/../veranstaltungen.php');
        st_expect(str_contains($qVerw, 'request_del') && str_contains($qVerw, 'org_zugangsmail'),
            'veranstaltungen.php: Anfragen-Stapel oder „Zugang-Mail" fehlt in der Verwaltung');
        /* Das Texte-Register: Die öffentlichen Seiten lesen ihre Sätze über wl_text() aus dem
           Register (Muster wie beim Pat:innenprogramm), die Verwaltung rendert ihr Formular
           komplett daraus – in einem EIGENEN Reiter, damit es die Übersicht nicht zustellt.
           Jedes Feld braucht Gruppe, Beschriftung und Standard; wl_setting_raw() muss es geben,
           sonst hieße ein bewusst geleertes Feld wieder „Standard" statt „ausblenden". */
        st_expect(function_exists('wl_text') && function_exists('wl_text_fields')
                && function_exists('wl_text_groups') && function_exists('wl_setting_raw')
                && function_exists('wl_setting_delete'),
            'die Texte-Register-Funktionen fehlen in wl-db.php');
        if (function_exists('wl_text_fields')) {
            foreach (wl_text_fields() as $tk => $tf) {
                st_expect(isset(wl_text_groups()[(string)($tf['group'] ?? '')]),
                    'Textfeld „' . $tk . '" zeigt auf eine unbekannte Gruppe');
                st_expect(in_array((string)($tf['type'] ?? ''), ['line', 'text'], true)
                        && trim((string)($tf['label'] ?? '')) !== '' && trim((string)($tf['default'] ?? '')) !== '',
                    'Textfeld „' . $tk . '": Typ, Beschriftung oder Standard fehlt');
            }
        }
        // Die Seiten müssen das Register auch BENUTZEN – sonst pflegt die Verwaltung Texte,
        // die nirgends erscheinen. wl_absaetze() ist der Ausgabeweg für die mehrzeiligen.
        foreach (['index.php', 'kurse.php', 'einreichen.php', 'wl-lib.php'] as $qd) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/' . $qd), 'wl_text('),
                'veranstaltungen/' . $qd . ' liest keine Texte aus dem Register (wl_text)');
        }
        st_expect(str_contains($libSrc, 'function wl_absaetze'),
            'wl_absaetze() fehlt in wl-lib.php – mehrzeilige Texte hätten keinen Ausgabeweg');
        st_expect(str_contains($qVerw, 'save_texts') && str_contains($qVerw, 'reset_texts')
                && str_contains($qVerw, 'wl_text_fields'),
            'veranstaltungen.php: der Texte-Reiter (Speichern/Zurücksetzen aus dem Register) fehlt');
        // Die Reiter selbst: Navigation in der Seite plus die Regeln in der style.css – ohne
        // die Regeln stünden die Reiter als nackte Linkliste da und niemand erkennte sie.
        st_expect(str_contains($qVerw, 'wl-adm-tabs') && !str_contains($qVerw, 'logo_stil'),
            'veranstaltungen.php: Reiter-Navigation fehlt oder die alte Wortmarken-Auswahl ist zurück');
        // Mit Klammer direkt dahinter, nicht als blanker Teilstring: „.wl-adm-tabs a.on .count"
        // enthält denselben Anfang – eine Teilstring-Prüfung wäre schon durch diese zweite
        // Fundstelle zufrieden, auch wenn die eigentliche Aktiv-Regel fehlt.
        $qCssAdm = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        st_expect(preg_match('~\.wl-adm-tabs a\.on\s*\{~', $qCssAdm) === 1,
            'style.css: die Reiter-Regeln (.wl-adm-tabs a.on) fehlen');
        // Die modernisierten Bausteine der Kacheln: Schalter (wl-sw) statt nackter Häkchen,
        // Auswahl-Chips (wl-chk) für die Referate, Eintrags-Zeilen (wl-adm-ent) statt Tabellen.
        // Jede Klasse braucht Markup UND Regel – nur eines von beiden fällt sonst still aus.
        // (wl-chk steht dort nicht mehr im Markup: Die Referats-/Personen-Auswahl kommt seit
        // aus dem gemeinsamen Baustein zust_picker_html() – geprüft wird sie unten.)
        foreach (['wl-sw', 'wl-adm-ent', 'wl-adm-form', 'wl-adm-reject'] as $bk) {
            st_expect(str_contains($qVerw, $bk), 'veranstaltungen.php benutzt den Baustein .' . $bk . ' nicht mehr');
            st_expect(preg_match('~\.' . preg_quote($bk, '~') . '[\s,{:.\[]~', $qCssAdm) === 1,
                'style.css: die Regeln für .' . $bk . ' fehlen');
        }
        st_expect(preg_match('~\.wl-chk[\s,{:.\[]~', $qCssAdm) === 1, 'style.css: die Regeln für .wl-chk fehlen');
        /* Datei-Felder: app-weit EINE Regel, keine Klasse pro Seite. Sie hing zuerst als
           .wl-adm-file nur an einem einzigen Feld in der was.läuft-Verwaltung – alle anderen 18
           Felder standen im Browser-Rohzustand da. Geprüft wird deshalb beides: dass die Regel
           allgemein greift, und dass niemand wieder eine Einzelklasse dafür einführt. */
        st_expect((bool)preg_match('~input\[type=file\]::file-selector-button~', $qCssAdm),
            'style.css: die Datei-Felder stehen wieder im Browser-Rohzustand (Regel für ::file-selector-button fehlt)');
        st_expect(!str_contains($qCssAdm, 'wl-adm-file'),
            'style.css: .wl-adm-file ist zurück – Datei-Felder werden app-weit gestaltet, nicht pro Seite');
        $qCssWl = (string)@file_get_contents(__DIR__ . '/../assets/wl.css');
        st_expect((bool)preg_match('~input\[type="file"\]::file-selector-button~', $qCssWl),
            'wl.css: die Datei-Felder der öffentlichen Seiten stehen wieder im Browser-Rohzustand');
        // Die WIP-Marken (und alle anderen Träger des festen Brauns #8a5d00) brauchen im
        // Dunkeln die helle Amber-Schrift.
        st_expect(str_contains($qCssAdm, 'html[data-theme="dark"] .wip'),
            'style.css: die Dunkel-Ausnahme der WIP-Marke fehlt – dunkles Braun auf dunklem Amber');
        // Der Schalter zeichnet die Pille NUR, wenn das echte Kästchen unsichtbar bleibt und der
        // Haken-Zustand die Bahn färbt – sonst stünden Häkchen UND Pille nebeneinander.
        st_expect((bool)preg_match('~\.wl-sw input\s*\{[^}]*opacity:\s*0~', $qCssAdm)
                && str_contains($qCssAdm, '.wl-sw input:checked ~ .wl-sw-track'),
            'style.css: der Schalter (.wl-sw) versteckt das Kästchen nicht oder reagiert nicht auf checked');
        // Die Reiter-Kategorisierung der übrigen Verwaltungsseiten (app-weit
        // ausgerollt): Fällt eine Nav raus, ist die Seite wieder eine unerreichbar lange Rolle –
        // und ihre umgestellten redirect()-Ziele (?t=…) liefen auf den Standard-Reiter.
        foreach (['meetings.php', 'paten.php', 'paten-programm.php', 'umfragen.php', 'terminplaner.php',
                  'events.php', 'members.php', 'score.php', 'activity.php'] as $tp) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/' . $tp), 'wl-adm-tabs'),
                'admin/' . $tp . ': die Reiter-Navigation fehlt');
        }
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../uploads.php'), 'wl-adm-tabs'),
            'uploads.php: die Reiter-Navigation fehlt');
        // Ein Reiter ohne Ziel ist schlimmer als keiner: Die umgestellten redirect()-Ziele
        // müssen den Reiter mitgeben, sonst landet man nach dem Speichern im Standard-Reiter
        // und sucht seine Änderung.
        // Ein Compute-Block im FALSCHEN Reiter fällt nicht auf: Der Statuscode steht längst auf
        // 200, wenn die Seite mitten im Rendern abbricht. Deshalb die zwei Variablen namentlich.
        $qSc = (string)@file_get_contents(__DIR__ . '/score.php');
        st_expect((int)strpos($qSc, '$canEdit = can_edit_score();') < (int)strpos($qSc, "\$tab === 'event'"),
            'admin/score.php: $canEdit muss VOR den Reitern stehen – er wird in mehreren gebraucht');
        st_expect((int)strpos($qSc, '$basisSince = basis_since();') > (int)strpos($qSc, "\$tab === 'basis'"),
            'admin/score.php: der Basis-Score-Block muss IM Basis-Reiter liegen, sonst bricht die Seite ab');

        $qEv = (string)@file_get_contents(__DIR__ . '/events.php');
        st_expect(str_contains($qEv, "&t=plan#plan") && str_contains($qEv, "&t=kontakt#nachrichten"),
            'admin/events.php: die Sprünge nach dem Einteilen/Nachrichten tragen keinen Reiter');
        $qUp = (string)@file_get_contents(__DIR__ . '/../uploads.php');
        st_expect(str_contains($qUp, '?t=sharepoint#') && str_contains($qUp, '?t=olat#') && str_contains($qUp, '?t=nextcloud#'),
            'uploads.php: die Rücksprünge nach einem Test tragen keinen Reiter');
        // --- Zuständigkeit: Referate ODER Personen ---------------------------
        // Der Kern ist das ENTWEDER-ODER: Bliebe beim Umschalten die alte Liste stehen, hätten
        // Leute später wieder Zugriff, ohne dass jemand sie eingetragen hat.
        foreach (array_keys(zust_bereiche()) as $zb) {
            st_expect(in_array(zust_modus($zb), ['referate', 'personen'], true),
                'Zuständigkeit „' . $zb . '": unbekannter Modus');
            st_expect(zust_modus($zb) !== 'personen' || !zust_referate($zb),
                'Zuständigkeit „' . $zb . '": im Personen-Modus liegt noch eine Referats-Liste herum');
            st_expect(zust_modus($zb) !== 'referate' || !zust_personen($zb),
                'Zuständigkeit „' . $zb . '": im Referate-Modus liegt noch eine Personen-Liste herum');
        }
        // Alle vier Stellen benutzen denselben Baustein – sonst sieht dieselbe Frage
        // viermal anders aus, und das Umschalten fehlt irgendwo.
        foreach ([['../veranstaltungen.php', 'waslaeuft'], ['../extern.php', 'extern'],
                  ['umfragen.php', 'umfrage:'], ['paten.php', 'pat']] as [$zp, $zk]) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/' . $zp), 'zust_picker_html'),
                $zp . ': die umschaltbare Zuständigkeit (zust_picker_html) fehlt');
        }
        st_expect(function_exists('zust_darf') && function_exists('zust_ids') && function_exists('zust_form_speichern'),
            'die Kernfunktionen der Zuständigkeit fehlen');

        // Auswahl-Chips: Referats-Zuständigkeit sieht überall gleich aus (war bei den
        // externen Events zuletzt noch die alte fb-kind-Optik).
        // Die Chips stehen seit der umschaltbaren Zuständigkeit nicht mehr in den Seiten,
        // sondern EINMAL im gemeinsamen Baustein – dort wird jetzt geprüft.
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../lib.php'), 'wl-chk')
            && str_contains(zust_picker_html('waslaeuft'), 'wl-chk'),
            'zust_picker_html(): die Auswahl benutzt nicht die Chips (wl-chk)');
        st_expect(str_contains(zust_picker_html('waslaeuft'), 'zust_modus')
            && str_contains(zust_picker_html('waslaeuft'), 'zust_pers'),
            'zust_picker_html(): der Umschalter oder die Personen-Liste fehlt');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/meetings.php'), 'wl-sw'),
            'admin/meetings.php: die Schalter-Bausteine (wl-sw) fehlen wieder');
        // was.läuft-Branding fürs Netz: Icons + Vorschaubild müssen als Dateien mitkommen,
        // wl_head() muss sie verlinken und Links ohne eigenes Bild das Marken-Bild geben.
        foreach (['wl-icon.svg', 'wl-icon-32.png', 'wl-icon-180.png', 'wl-og.png'] as $bf) {
            st_expect(is_file(__DIR__ . '/../assets/' . $bf) && filesize(__DIR__ . '/../assets/' . $bf) > 200,
                'assets/' . $bf . ' fehlt – Tab-Icon bzw. Link-Vorschau der was.läuft-Seite wären weg');
        }
        st_expect(str_contains($libSrc, 'apple-touch-icon') && str_contains($libSrc, 'wl-og.png'),
            'wl_head() verlinkt Icons oder Standard-Vorschaubild nicht mehr');
        // PWA: Manifest + Service Worker machen die Seite installierbar und offen
        // auch offline benutzbar. Vier Teile müssen zusammenhalten: die Dateien, die
        // Installations-Icons, die Verlinkung/Anmeldung in wl_head() und die CSP-Freigaben –
        // worker-src fällt sonst auf script-src zurück, und dessen Einmal-Wert passt nie
        // auf sw.js (der Browser meldete den Worker still nicht an).
        foreach (['wl-icon-192.png', 'wl-icon-512.png', 'wl-icon-512-maskable.png'] as $bf) {
            st_expect(is_file(__DIR__ . '/../assets/' . $bf) && filesize(__DIR__ . '/../assets/' . $bf) > 1000,
                'assets/' . $bf . ' fehlt – ohne Installations-Icons keine PWA');
        }
        // Das Manifest wird erzeugt (manifest.php), weil seine Beschreibung den Ort nennt.
        // Geprüft wird deshalb die AUSGABE, nicht eine Datei: Ein Tippfehler im Erzeuger
        // bliebe sonst unbemerkt, bis jemand die App zu installieren versucht.
        ob_start();
        include __DIR__ . '/../veranstaltungen/manifest.php';
        $qMani = (string)ob_get_clean();
        $qManiD = json_decode($qMani, true);
        st_expect(is_array($qManiD) && ($qManiD['display'] ?? '') === 'standalone'
                && ($qManiD['start_url'] ?? '') !== '' && count($qManiD['icons'] ?? []) >= 3
                && str_contains($qMani, 'maskable'),
            'veranstaltungen/manifest.php liefert kein gültiges Manifest (display/start_url/icons/maskable)');
        st_expect(str_contains((string)($qManiD['description'] ?? ''), wl_ort()),
            'das Manifest von was.läuft nennt den Ort nicht mehr – er kommt aus den Einstellungen (Verwaltung → Träger)');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php'), 'href="manifest.php"'),
            'wl-lib.php verweist nicht mehr auf manifest.php – ohne Manifest lässt sich was.läuft nicht installieren');

        // Die Manifeste dürfen ihre Kopfzeilen NUR beim direkten Aufruf senden. Werden sie –
        // wie hier – eingebunden, kaperte ein Content-Type die umgebende Seite: Der Browser
        // zeigte dann den Quelltext der Verwaltung statt der Seite. headers_sent() genügt als
        // Schutz NICHT – bei eingeschaltetem Output-Buffering meldet es „noch nichts gesendet".
        foreach (['manifest.php', 'veranstaltungen/manifest.php'] as $mf) {
            $qMf = (string)@file_get_contents(__DIR__ . '/../' . $mf);
            st_expect(str_contains($qMf, "realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)"),
                $mf . ': die Kopfzeilen hängen nicht mehr am direkten Aufruf – eingebunden kapert der Content-Type die Seite');
            // Auf die BEDINGUNG prüfen, nicht auf das Wort: Der Kommentar in der Datei nennt
            // headers_sent() ja gerade als das, was hier nicht taugt.
            st_expect(!str_contains($qMf, 'if (!headers_sent())'),
                $mf . ': die Kopfzeilen hängen wieder an headers_sent() – mit Output-Buffering meldet das „noch nichts gesendet"');
        }
        $qSw = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/sw.js');
        st_expect($qSw !== '' && is_file(__DIR__ . '/../veranstaltungen/offline.html'),
            'veranstaltungen/sw.js oder offline.html fehlt – die PWA verliert ihren Offline-Rückfall');
        st_expect(str_contains($qSw, 'offline.html') && str_contains($qSw, "mode === 'navigate'"),
            'sw.js: Seiten-Weiche oder Offline-Rückfall fehlt – Seiten kämen nicht mehr netzfrisch');
        st_expect(str_contains($libSrc, 'rel="manifest"') && str_contains($libSrc, 'serviceWorker'),
            'wl_head(): Manifest-Link oder Service-Worker-Anmeldung fehlt');
        st_expect(str_contains($libSrc, "manifest-src 'self'") && str_contains($libSrc, "worker-src 'self'"),
            'CSP der was.läuft-Seiten: manifest-src/worker-src fehlen – der Browser lädt Manifest und sw.js nicht');
        // Push-Paket: Erinnerungen + Abos. Der GEMEINSAME Kern push-core.php trägt
        // die Mathematik – lib.php und wl-db.php binden ihn ein und dürfen sie NICHT doppelt
        // definieren (zweimal function push_encrypt wäre ein Fatal beim ersten Aufruf).
        st_expect(is_file(__DIR__ . '/../push-core.php'), 'push-core.php fehlt – Web Push wäre app-weit tot');
        $qWlDb = (string)@file_get_contents(__DIR__ . '/../wl-db.php');
        $qLib  = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect(str_contains($qWlDb, "push-core.php'") && str_contains($qLib, "push-core.php'"),
            'lib.php oder wl-db.php bindet push-core.php nicht mehr ein');
        st_expect(!str_contains($qLib, 'function push_encrypt') && !str_contains($qWlDb, 'function push_encrypt'),
            'push_encrypt ist doppelt definiert – die Krypto gehört NUR in push-core.php');
        // wl_datum_lang wohnt seit dem Push-Paket in wl-db.php (der Cron braucht sie) –
        // eine zweite Fassung in wl-lib.php wäre eine Funktions-Kollision.
        st_expect(str_contains($qWlDb, 'function wl_datum_lang') && !str_contains($libSrc, 'function wl_datum_lang'),
            'wl_datum_lang muss GENAU EINMAL existieren – in wl-db.php');
        foreach (['wl_push_vapid', 'wl_push_cron', 'wl_reminder_toggle', 'wl_follow_toggle', 'wl_remind_at',
                  'wl_push_state', 'wl_org_profil_save', 'push_core_deliver', 'push_core_keypair'] as $fn) {
            st_expect(function_exists($fn), 'Funktion ' . $fn . '() fehlt – das Push-Paket ist unvollständig');
        }
        // Schema: die drei Push-Tabellen samt Nachrüst-Spalten.
        $wlSpalten = static fn (string $t): array => array_column(wl_db()->query('PRAGMA table_info(' . $t . ')')->fetchAll(), 'name');
        foreach (['push_subs', 'push_reminders', 'push_follows'] as $tab) {
            st_expect($wlSpalten($tab) !== [], 'wl-Datenbank: Tabelle ' . $tab . ' fehlt');
        }
        st_expect(in_array('push_done', $wlSpalten('items'), true), 'items.push_done fehlt – der Cron fände Neues nicht');
        st_expect(in_array('profil', $wlSpalten('orgs'), true) && in_array('insta', $wlSpalten('orgs'), true),
            'orgs: die Profil-Spalten (profil/web/insta) fehlen');
        // Die Erinnerungszeit ist ABGELEITET: 2 Std. vorher, ohne Uhrzeit 09:00, Vergangenes nie.
        $morgen = date('Y-m-d', time() + 2 * 86400);
        st_expect(wl_remind_at(['kind' => 'event', 'starts_at' => $morgen . ' 20:00']) === $morgen . ' 18:00',
            'wl_remind_at(): 2 Stunden vor Beginn stimmt nicht');
        st_expect(wl_remind_at(['kind' => 'event', 'starts_at' => $morgen]) === $morgen . ' 09:00',
            'wl_remind_at(): Termine ohne Uhrzeit müssen um 09:00 erinnern');
        st_expect(wl_remind_at(['kind' => 'event', 'starts_at' => '2020-01-01 20:00']) === ''
                && wl_remind_at(['kind' => 'kurs']) === '',
            'wl_remind_at(): Vergangenes und Kurse dürfen keine Erinnerungszeit bekommen');
        // Service Worker: VERSION mindestens 3 (V3 = fester Kern-Bestand) und alle drei
        // Push-Ereignisse.
        $qSw2 = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/sw.js');
        st_expect(preg_match('~const VERSION = (\d+)~', $qSw2, $mv) === 1 && (int)$mv[1] >= 3,
            'sw.js: VERSION muss mindestens 3 sein (fester Kern-Bestand gegen fehlende Logos in der PWA)');
        // PWA-Logo-Bug („in der installierten PWA MANCHMAL das originale
        // Logo nicht da"): eigene Speicher-Partition der App + FIFO-Stutzen warfen die
        // Kern-Bilder aus dem Vorrat, ein Netz-Wackler ließ das <img> dann leer. Deshalb:
        // KERN-Liste im festen Bestand + Cache-Rückgriff, wenn ein Asset-Fetch scheitert.
        st_expect(str_contains($qSw2, "'../brand.php?b=logo'") && str_contains($qSw2, "'../brand.php?b=logo-verlauf'")
                && preg_match('~const KERN[^;]*wl-icon-192~s', $qSw2) === 1,
            'sw.js: die KERN-Liste (Logo, Logo im Verlauf, wl-icon-192) fehlt im festen Bestand');
        st_expect(str_contains($qSw2, 'caches.match(req, { cacheName: FEST })') && str_contains($qSw2, 'const alt = await caches.match(req)'),
            'sw.js: fester Bestand wird bei Assets nicht mehr mitgeprüft oder der Cache-Rückgriff bei Netz-Fehlern fehlt');
        foreach (['logo.png', 'logo-verlauf.png', 'wl-icon-192.png'] as $st_kern) {
            st_expect(is_file(__DIR__ . '/../assets/' . $st_kern),
                "assets/$st_kern fehlt – damit scheitert die KOMPLETTE Installation des Service Workers (addAll)");
        }
        foreach (["'push'", "'notificationclick'", "'pushsubscriptionchange'"] as $ev) {
            st_expect(str_contains($qSw2, $ev), 'sw.js: der ' . trim($ev, "'") . '-Handler fehlt');
        }
        // Endpoint, Skript und die Einstiege auf den Seiten.
        $qPush = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/push.php');
        st_expect($qPush !== '' && str_contains($qPush, "'boot'") && str_contains($qPush, "'remind'")
                && str_contains($qPush, "'follow'") && str_contains($qPush, "'weg'"),
            'push.php fehlt oder verliert Aktionen');
        st_expect(is_file(__DIR__ . '/../assets/wl-push.js') && str_contains($libSrc, 'wl-push.js'),
            'wl-push.js fehlt oder wl_head() bindet es nicht mehr ein');
        st_expect(str_contains($qV = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php'), 'data-art="remind"')
                && str_contains($qV, 'veranstalter.php?id='),
            'v.php: Erinnerungs-Knopf oder Veranstalter-Link fehlt');
        // Detailseite: Fakten als ruhige Symbol-Zeilen (wl-df), keine Chips, und eine
        // Knopfreihe mit EINEM Hauptknopf (wl-det-akt) vor dem Fließtext.
        st_expect(str_contains($qV, 'wl-df-list') && str_contains($qV, 'wl-det-akt'),
            'v.php: der Detailseiten-Umbau (Fakten-Zeilen wl-df / Knopfreihe wl-det-akt) fehlt');
        st_expect(!str_contains($qV, 'wl-fact'),
            'v.php: die Chip-Bubbles (wl-fact) sind zurück auf der Detailseite – die waren ein User-Veto');
        // Desktop-Zweispaltigkeit („Platz besser nutzen"): Grid-Areas
        // über den unveränderten Kindern; der 404-Zweig bleibt einspaltig (nur .wl-det).
        st_expect(str_contains($qV, 'wl-det wl-det-2s')
                && preg_match('~\.wl-det-2s[^}]*grid-template-areas~s', $cssRein),
            'Detailseite: die Desktop-Zweispaltigkeit (wl-det-2s + Grid-Areas) fehlt – am Desktop verschenkt die 820px-Wurst ein Drittel des Schirms');
        // Handy-Knopfraster + Button-Farbe: Ohne color:inherit malt iOS
        // Safari die <button> blau; ohne das 2-Spalten-Raster flattern die Knöpfe.
        st_expect((bool)preg_match('~\.wl-btn \{[^}]*color: inherit~s', $cssRein),
            'wl.css: .wl-btn hat kein color:inherit mehr – iOS Safari färbt die <button>-Knöpfe (Erinnern/Abonnieren) sonst blau');
        st_expect(str_contains($cssRein, ':last-child:nth-child(even)'),
            'wl.css: das feste Handy-Knopfraster der Detailseite (2 Spalten + Voll-Zeile für den Rest-Knopf) fehlt');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/veranstalter.php'), 'data-art="follow"'),
            'veranstalter.php: der Abo-Knopf fehlt');
        st_expect(is_file(__DIR__ . '/../veranstaltungen/abos.php')
                && str_contains($libSrc, 'abos.php') && str_contains($libSrc, 'veranstalter.php'),
            'abos.php fehlt oder Fußzeilen-Links (Veranstalter / Erinnerungen & Abos) sind weg');
        st_expect(str_contains($qEinr, "'profil'") && str_contains($qEinr, 'wl_org_profil_save'),
            'einreichen.php: der Profil-Editor der Veranstalter fehlt');
        // Cron: Push jeder Lauf, Aufräumen über die Tages-Weiche.
        $qCronWl = (string)@file_get_contents(__DIR__ . '/../cron_waslaeuft.php');
        st_expect(str_contains($qCronWl, 'wl_push_cron(') && str_contains($qCronWl, 'cleanup_tag'),
            'cron_waslaeuft.php: Push-Versand oder Einmal-am-Tag-Weiche fehlt');
        // Kategorie-Feld: Weder eine Chip-Zeile (das wäre am Handy eine dritte Filterzeile)
        // noch Farbpunkte im zugeklappten Feld (albern, und sie brechen die Leiste um).
        // Das Feld bleibt das nackte Wort. Dazu die hidden-Schutzregel.
        st_expect(!str_contains($libSrc, 'function wl_kat_chips'),
            'Die verworfene Kategorie-Chip-Zeile (wl_kat_chips) ist zurück – dritte Filterzeile am Handy war ein User-Veto');
        st_expect(!str_contains($libSrc, 'wl-drop-dots'),
            'Die verworfenen Farbpunkte im Kategorie-Feld (wl-drop-dots) sind zurück – auch das war ein User-Veto');
        st_expect(str_contains($cssRein, '[hidden]'),
            'wl.css: die [hidden]-Schutzregel fehlt');
        // Event-Separation, Endstand: Die KACHEL trennt nur die Luft. Linien,
        // Rahmen-Karte und Flächen (mit wie ohne Schatten) waren allesamt Festlegungen –
        // diese Sperre hält jede Rückkehr solcher Anbauten fest.
        st_expect(!preg_match('~\.wl-grid \.wl-card[^{]*\{[^}]*(border|background|box-shadow)~s', $cssRein),
            'wl.css: an den Event-Kacheln klebt wieder eine Trenn-Optik (Linie/Fläche/Rahmen) – alle Varianten waren User-Vetos, es gilt „nur Luft"');
        // Einzige Ergänzung: der 1px-Innenring auf dem BILD – als
        // after-Overlay, weil ein inset-Schatten am Container unter dem absoluten <img>
        // läge. Er fasst helle Plakate; fällt er weg, verschwimmen die wieder mit der Seite.
        st_expect((bool)preg_match('~\.wl-card-img::after[^}]*inset 0 0 0 1px~s', $cssRein),
            'wl.css: der 1px-Bild-Ring (.wl-card-img::after) fehlt – die vom User gewählte Kachel-Separation „Bild hauchdünn gefasst"');
        // Zuordnung Text→Bild über das NÄHE-GESETZ – der Abstand in
        // der Kachel muss klein bleiben und der Zeilenabstand am Desktop groß, sonst kleben
        // Text und NÄCHSTES Bild wieder gleich eng beieinander.
        st_expect((bool)preg_match('~\.wl-grid \{ row-gap: 2\.4rem~', $cssRein),
            'wl.css: der große Desktop-Zeilenabstand (.wl-grid row-gap 2.4rem) fehlt – ohne ihn hängt der Kacheltext wieder zwischen den Bildern');
        // Die aspect-ratio-Falle: Eine FESTE min-height am Detail-Hero überträgt
        // sich über das Seitenverhältnis auf die BREITE (190 × 21/8 = 499 px Mindestbreite) –
        // am Handy scrollte die Detailseite seitwärts. Die Mindesthöhe muss an die Breite
        // gekoppelt bleiben (min(190px, …vw)).
        st_expect((bool)preg_match('~\.wl-det-hero[^}]*min-height:\s*min\(~', $cssRein),
            'wl.css: .wl-det-hero hat wieder eine feste min-height – unter 499 px Breite scrollt die Seite seitwärts');
        // Die Handschrift-Zeile: ALLE öffentlichen Bereiche tragen sie im Fuß –
        // wie die App. Fällt sie irgendwo raus, meldet sich diese Prüfung.
        foreach (['veranstaltungen/wl-lib.php', 'pat/pat-lib.php', 'umfrage/umfrage-lib.php',
                  'termin/termin-lib.php', 'anmeldung/anmeldung-lib.php'] as $fu) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../' . $fu), 'von Boj Petersen'),
                $fu . ': die „Mit ♥ von Boj Petersen"-Zeile fehlt im Fuß');
        }
        // Fuß-Links „Was demnächst läuft"/„Kurse & Angebote" nur am HANDY: Am Desktop
        // verdoppeln sie die Kopfleiste. Beide tragen die Klasse
        // wl-foot-doppel, und wl.css blendet sie ab der Mehrspalten-Grenze aus.
        st_expect(substr_count($libSrc, 'wl-foot-doppel') >= 2,
            'wl-lib.php: die Fuß-Links Demnächst/Kurse tragen die Handy-Klasse (wl-foot-doppel) nicht mehr');
        st_expect((bool)preg_match('~min-width:\s*761px[^}]*\{[^}]*\.wl-foot-doppel[^}]*display:\s*none~s', $cssRein),
            'wl.css: die Desktop-Ausblendung der Fuß-Links (.wl-foot-doppel) fehlt – am Desktop verdoppeln sie die Kopfleiste (User-Wunsch: raus)');
        // App-Werbung, LEISE – keine große Karte: Auf der
        // Startseite nur noch die schlanke Hinweis-Zeile mit Knopf zur App-Seite; Anleitung
        // (je Plattform), Direkt-Installieren (beforeinstallprompt) und FAQ leben auf app.php.
        // Die Push-Fehlermeldungen und die Fußzeile verweisen ebenfalls dorthin.
        st_expect(str_contains($qIndex, 'wl-app-hinweis') && str_contains($qIndex, 'app.php')
                && !str_contains($qIndex, 'wl-app-karte'),
            'index.php: der schlanke App-Hinweis fehlt oder die aufdringliche App-Karte ist zurück (User-Veto)');
        $qApp = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/app.php');
        st_expect(str_contains($qApp, 'beforeinstallprompt') && str_contains($qApp, 'Zum Home-Bildschirm')
                && substr_count($qApp, '<details') >= 4,
            'app.php: die App-Seite (Anleitung je Plattform, Direkt-Installieren, FAQ) fehlt oder ist geschrumpft');
        $qPushJs = (string)@file_get_contents(__DIR__ . '/../assets/wl-push.js');
        st_expect(str_contains($qPushJs, 'app.php') && str_contains($libSrc, 'app.php'),
            'Der App-Verweis fehlt in den Push-Fehlermeldungen (wl-push.js) oder in der Fußzeile');
        // Push-Meldungen als DIALOG: der rote Kasten am Seitenende
        // (.wl-push-info) war auf den meisten Geräten unsichtbar, und der App-Verweis darin
        // ein unscheinbarer Textlink. Jetzt baut wl-push.js einen eigenen wl-Dialog mit
        // echtem Knopf zur App-Seite. Der alte Kasten darf nirgends zurückkommen.
        st_expect(str_contains($qPushJs, 'wl-push-dialog') && str_contains($qPushJs, 'showModal'),
            'wl-push.js: die Push-Meldung erscheint nicht mehr als eigener Dialog (wl-push-dialog/showModal)');
        st_expect(!str_contains($qPushJs, "querySelector('.wl-push-info')"),
            'wl-push.js: der unsichtbare Melde-Kasten .wl-push-info wird wieder benutzt (User-Veto: Dialog!)');
        foreach (['v.php', 'veranstalter.php', 'abos.php'] as $st_f) {
            st_expect(!str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/' . $st_f), 'wl-push-info'),
                "veranstaltungen/$st_f: der Kasten .wl-push-info ist zurück – Push-Meldungen kommen als Dialog");
        }
        st_expect(str_contains($cssRein, '.wl-push-dialog') && !str_contains($cssRein, '.wl-push-info'),
            'wl.css: Dialog-Feinschliff (.wl-push-dialog) fehlt oder der alte Kasten (.wl-push-info) ist zurück');
        // App-Hinweis-Zeile: gleich viel Luft oben wie unten („so sieht es dumm aus") –
        // unten liegt die Haarlinie der Kurs-Tafel bei 2rem (ihr margin-top kollabiert über
        // die .8rem der Zeile), oben stehen 2.2rem zu den Kacheln. Gemessen: 35px/32px.
        st_expect((bool)preg_match('~\.wl-app-hinweis\s*\{[^}]*margin:\s*2\.2rem 0 \.8rem~s', $cssRein),
            'wl.css: das ausgeglichene Spacing der App-Hinweis-Zeile (margin: 2.2rem 0 .8rem) fehlt');
        // Einreich-Formular: der App-Datums-Wähler statt des Browser-Rohlings – Feld-Klassen im
        // Formular UND die flatpickr-Einbindung im Kopf (mit Nonce, sonst frisst die CSP sie).
        st_expect(str_contains($qEinr, 'fp-datetime') && str_contains($qEinr, 'fp-date')
                && !str_contains($qEinr, 'datetime-local'),
            'einreichen.php: flatpickr-Felder fehlen oder der Browser-Rohling ist zurück');
        st_expect(str_contains($libSrc, 'flatpickr.min.js') && str_contains($libSrc, 'wl_flatpickr'),
            'wl_head(): die flatpickr-Einbindung für die Einreich-Seite fehlt');
        // Bild-Auswahl = Kachel-Vorschau: height:auto ist der Kern (das height-Attribut des
        // <img> schlägt sonst aspect-ratio – die „vertikalen Ungetüme").
        st_expect((bool)preg_match('~\.wl-pick img[^{]*\{[^}]*height:\s*auto~s', $cssRein)
                && str_contains($qEinr, 'wl-pick-std'),
            'Bild-Auswahl: Kachel-Vorschau (height:auto / Standard-Foto-Karte) fehlt');
        // Die LIVE-Vorschau der Kachel im Einreich-Formular: echte wl-card-Klassen + das
        // Nachführ-Skript. Ohne eines von beiden sähen Veranstalter wieder nicht, wie ihr
        // Beitrag auf der Startseite aussieht.
        st_expect(str_contains($qEinr, 'wl-vorschau') && str_contains($qEinr, 'pv-imgbox')
                && str_contains($qEinr, "addEventListener('input', malen)"),
            'einreichen.php: die Live-Kachel-Vorschau (Markup oder Nachführ-Skript) fehlt');
        // Die Ostereier der Startseite (Klick auf was/Punkt/läuft/Landau, Lupen-Tilt, Logo-Hover):
        // Skript-Anker in index.php, Keyframes in wl.css, die was-Hülle in der Wortmarke.
        st_expect(str_contains($qIndex, 'wl-stadt-pop') && str_contains($qIndex, 'ort_url')
                && str_contains($qIndex, 'is-hall') && str_contains($qIndex, 'is-kick') && str_contains($qIndex, 'is-los'),
            'index.php: die Klick-Ostereier (Landau-Blase / Hall / Kick / Joggen) fehlen');
        // Der Buchstaben-Auftritt braucht den erzwungenen Reflow zwischen Zerlegen und
        // Startschuss – ohne ihn zündet die Animation nicht in jedem Browser und „Landau"
        // bliebe unsichtbar. Und die Versteck-Regel darf nur VOR dem Auftritt gelten.
        st_expect(str_contains($qIndex, 'void stadt.offsetWidth'),
            'index.php: der Reflow vor is-zeit fehlt – der „Landau"-Auftritt zündet nicht überall');
        st_expect(str_contains($cssRein, '.wl-h1:not(.is-zeit) .wl-h1-stadt span'),
            'wl.css: die „Landau"-Versteck-Regel ist nicht mehr auf :not(.is-zeit) begrenzt – bleibt der Auftritt aus, wäre die Stadt dauerhaft unsichtbar');
        // Das Fragezeichen tritt MIT den Buchstaben auf (Skript wickelt es in .wl-h1-frage).
        // Ohne das hängt es während des Auftritts allein neben dem freigehaltenen Loch
        // („in     ?") und sieht nach Darstellungsfehler aus.
        st_expect(str_contains($qIndex, 'wl-h1-frage') && str_contains($cssRein, '.wl-h1-frage'),
            'der Fragezeichen-Mitauftritt (.wl-h1-frage) fehlt – das „?" stünde beim Landau-Auftritt wieder allein neben dem Loch');
        // Repaint-Blitzer (linke „Landau"-Hälfte verschwand kurz nach dem Auftritt): Das Rad
        // bleibt unsichtbar im DOM statt spät entfernt zu werden, und der Blasen-Anker
        // (position: relative) gilt nur bei OFFENER Blase – nie dauerhaft am Satzteil.
        st_expect(!str_contains($qIndex, 'rad.remove()'),
            'index.php: das Rad wird wieder spät entfernt – dieser Aufräum-Repaint ließ die linke „Landau"-Hälfte kurz wegblitzen');
        st_expect(str_contains($cssRein, '.wl-h1-ort.is-pop')
                && !preg_match('~\.wl-h1-ort\s*\{[^}]*position:\s*relative~', $cssRein),
            'wl.css: „in Landau?" ist wieder dauerhaft positioniert (eigene Render-Ebene) – der Anker gehört an .wl-h1-ort.is-pop, nur bei offener Blase');
        /* Die Über-Seite: Marke riesig, Absender AStA, und das Netzwerk MUSS vollständig
           benannt sein – Kultur, Stadt, Hochschulgruppen, Studierendenwerk, Fachschaften.
           Fällt eine Kachel raus, fehlt einem echten Partner die Nennung. */
        $qUeber = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/ueber.php');
        st_expect($qUeber !== '' && str_contains($qUeber, 'wl_marke('),
            'veranstaltungen/ueber.php fehlt oder trägt die Wortmarke nicht');
        // Auf die KACHEL-Zuweisung prüfen, nicht auf das blanke Wort: Die Namen stehen auch im
        // erklärenden Dateikommentar, und daran wäre str_contains immer satt geworden.
        foreach (['Kultur-Einrichtungen', 'Hochschulgruppen', 'Studierendenwerk', 'Fachschaften'] as $pn) {
            st_expect(str_contains($qUeber, "'titel' => '" . $pn . "'"),
                'Über-Seite: die Partner-Kachel „' . $pn . '" fehlt');
        }
        // Die Stadt-Kachel trägt den Ort aus den Einstellungen, nicht einen festen Namen.
        st_expect(str_contains($qUeber, "'titel' => 'Stadt ' . wl_ort()"),
            'Über-Seite: die Partner-Kachel der Stadt nennt den Ort nicht mehr aus den Einstellungen');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php'), 'ueber.php'),
            'die Über-Seite ist nicht mehr verlinkt (Kopfleiste/Fußzeile in wl-lib.php)');
        $wlTexte = function_exists('wl_text_fields') ? wl_text_fields() : [];
        // Die Badge-Zeile führt die LANGE Namensform („Vom <Trägername, ausgeschrieben>"); die kurze
        // steckt nicht darin, sobald sich beide unterscheiden. Deshalb gegen wl_traeger_name()
        // prüfen – und nicht gegen die Kurzform, die nur Fußzeile und Pressetext benutzen.
        st_expect(isset($wlTexte['ueber_badge'], $wlTexte['ueber_asta'], $wlTexte['ueber_partner'])
                && str_contains((string)$wlTexte['ueber_badge']['default'], wl_traeger_name()),
            'Über-Seite: die Texte der Gruppe „ueber" fehlen im Register (oder die Badge-Zeile nennt den Träger nicht mehr)');
        st_expect(str_contains($cssRein, '.wl-uh-marke')
                && (bool)preg_match('~@keyframes wl-u-auf\s*\{~', $cssRein),
            'wl.css: der große Marken-Auftritt der Über-Seite (.wl-uh-marke / wl-u-auf) fehlt');
        // Die Scroll-Auftritte dürfen NUR das Skript verstecken: ohne JavaScript ist alles sichtbar.
        st_expect(str_contains($qUeber, "classList.add('is-wart')")
                && !preg_match('~class="[^"]*is-wart~', $qUeber),
            'Über-Seite: is-wart steht im Markup statt nur im Skript – ohne JavaScript blieben Abschnitte unsichtbar');
        // Das AStA-Logo steht auf der Über-Seite in einer hellen Karte.
        st_expect(str_contains($qUeber, 'wl-ua-logo') && str_contains($qUeber, "brand_url('logo'"),
            'Über-Seite: die Logo-Karte fehlt');
        // Absender-Pille: AStA-Logo und das Herz mit davonfliegenden Herzchen (wie beim
        // Pat:innenprogramm, als Inline-SVG – die wl-Seiten laden keine Symbol-Schrift).
        // Ein grüner Punkt gehört nicht hinein.
        st_expect(str_contains($qUeber, 'wl-uh-logo') && str_contains($qUeber, 'wl-uh-herz')
                && !str_contains($qUeber, 'wl-uh-punkt'),
            'Über-Seite: die Absender-Pille braucht Logo + Herz – und den alten grünen Punkt nicht');
        // Logo-Verwandlung: Der START ist IMMER das Original-Logo
        // (Grundzustand der Verlaufs-Fassung ZUGEKLAPPT), und die Schleife läuft OHNE
        // jeden Merker einfach von vorn – eine sessionStorage-Abkürzung fürs Logo wäre
        // Unsinn und darf nicht auftauchen.
        st_expect((bool)preg_match('~\.wl-ua-neu \{[^}]*inset\(0 100% 0 0\)~', $cssRein)
                && !str_contains($cssRein, 'is-wieder')
                && !str_contains($qUeber, 'animationDelay'),
            'Logo-Verwandlung: startet wieder als das BUNTE Logo, oder die verworfene Merker-Abkürzung (is-wieder/animationDelay) ist zurück');
        // Wieder-Besuch (gemeint war der MARKEN-EINZUG): body.wl-wieder
        // lässt NUR den Hero-Auftritt entfallen – ohne das spielte Firefox (kein
        // Rückwärts-Cache) bei jedem Unterseiten-Wechsel die volle Anfahrt.
        st_expect(str_contains($qUeber, "classList.add('wl-wieder')")
                && (bool)preg_match('~body\.wl-wieder \.wl-uh-badge,.*?\.wl-mark-was.*?animation: none~s', $cssRein),
            'Der Wieder-Besuchs-Schnellstart des Hero-Auftritts (body.wl-wieder) fehlt – Firefox spielte den Marken-Einzug bei jedem Seitenwechsel');
        // Startseiten-Marke: Die Kurz-Fassung beim Weiterklicken hing NUR am
        // Referrer – Firefox schickt je nach Privatsphäre-Einstellung keinen, dann spielte
        // bei jedem Zeit-Umschalten die volle Anfahrt. Der Sitzungs-Merker ist das zweite
        // Standbein und muss bleiben.
        st_expect(str_contains($qIndex, 'wlStartGesehen') && str_contains($qIndex, 'document.referrer'),
            'index.php: der Sitzungs-Merker der Marken-Kurzfassung (wlStartGesehen) fehlt – ohne Referrer (Firefox) spielte immer die lange Anfahrt');
        // Hintergrund-Kacheln: Body-Verläufe der Skins
        // wiederholten sich auf kurzen Seiten über die Leinwand (Naht + „gespiegelter"
        // Glanz). Beide Bereiche halten den Body deshalb mindestens sichtbereichshoch und
        // ohne Wiederholung; die Über-Bühne endet erst bei 1200px statt mitten im Bild.
        st_expect((bool)preg_match('~\nbody \{[^}]*min-height: 100vh[^}]*background-repeat: no-repeat[^}]*\}~s',
                (string)@file_get_contents(__DIR__ . '/../assets/style.css')),
            'style.css: der Kachel-Schutz am Body (min-height 100vh + background-repeat no-repeat) fehlt – Skin-Verläufe wiederholen sich auf kurzen Seiten');
        st_expect((bool)preg_match('~body\.wl \{[^}]*min-height: 100vh[^}]*background-repeat: no-repeat~s', $cssRein)
                && str_contains($cssRein, '86svh, 1200px'),
            'wl.css: Kachel-Schutz am wl-Body oder die 1200px-Kappung der Über-Bühne fehlt');
        st_expect((bool)preg_match('~@keyframes wl-herz-weg-l\s*\{~', $cssRein)
                && str_contains($cssRein, '.wl-uh-herz-l'),
            'wl.css: die Flieger-Herzchen der Absender-Pille fehlen');
        // Am Handy (gestapelt) steht das Verwandlungs-Logo zentriert statt links am Rand.
        st_expect((bool)preg_match('~\.wl-ua-logo\s*\{\s*margin-inline:\s*auto~', $cssRein),
            'wl.css: das „Von Studis"-Logo ist am Handy nicht mehr zentriert');
        st_expect((bool)preg_match('~\.wl-nav-ueber\s*\{\s*display:\s*none~', $cssRein),
            'wl.css: „Über" steht wieder auf schmalen Bildschirmen in der Kopfleiste');
        st_expect(str_contains($cssRein, 'body.wl .wl-uc-voll'),
            'wl.css: der Eintragen-Knopf der Über-Seite hängt nicht mehr an body.wl – im Dunkel-Modus wird er unlesbar (a-Falle)');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php'), "addEventListener('mouseenter'")
                && !str_contains($cssRein, '.wl-logo:hover .wl-mark-dot'),
            'der Punkt-Hüpfer der Kopfleiste hängt wieder an :hover statt an mouseenter – nach einem Klick hüpft er doppelt');
        // Der Punkt bleibt der Schrift-Glyph: Ein gezeichneter Ersatz-Kreis schwebt neben
        // der Grundlinie und wird unter Windows zum Oval.
        st_expect(!str_contains($cssRein, '.wl-mark-dot::after'),
            'der Marken-Punkt ist wieder ein gezeichneter Kreis (::after) – der User wollte den echten Glyph zurück');
        /* Der Promo-Reiter (Pressekit): Reiter, Dateiliste, ZIP-Bündel – und die Vorlagen
           müssen wirklich auf der Platte liegen, sonst zeigt die Verwaltung tote Knöpfe.
           Das Druck-Layout ist EIN Entwurf: Flyer und Aufsteller tragen dieselbe viewBox,
           nur die physische Größe unterscheidet sie (die A-Reihe skaliert verlustfrei). */
        $qVer = (string)@file_get_contents(__DIR__ . '/../veranstaltungen.php');
        st_expect(str_contains($qVer, "'promo'") && str_contains($qVer, 'wl_promo_dateien')
                && str_contains($qVer, 'ZipArchive'),
            'veranstaltungen.php: der Promo-Reiter (Dateiliste/ZIP) fehlt');
        if (function_exists('wl_promo_dateien')) {
            foreach (wl_promo_dateien() as $pg) {
                foreach ($pg['dateien'] as $pd => $egal) {
                    st_expect(is_file(__DIR__ . '/../assets/wl-promo/' . $pd),
                        'Promo-Vorlage fehlt auf der Platte: assets/wl-promo/' . $pd);
                }
            }
        }
        // Statische Druck-/Insta-Vorlagen sind BEWUSST weg – der Vorlagen-Generator ersetzt
        // sie (eigener Spruch, alle Texte editierbar); nur die zwei Animationen bleiben Dateien.
        st_expect(!is_file(__DIR__ . '/../assets/wl-promo/flyer-a6.svg'),
            'statische Druckvorlagen sind zurück in assets/wl-promo – der Generator ist die eine Quelle');
        st_expect(str_contains($qVer, 'data-anim'),
            'der „Mit euren Texten"-Umbau der Animationen fehlt im Promo-Reiter');

        // Eine Wort-Rad-Schleife darf es nicht geben.
        st_expect(!is_file(__DIR__ . '/../assets/wl-promo/insta-animation.html'),
            'die gestrichene Wort-Rad-Schleife ist zurück – der User hat sie ausdrücklich verworfen');
        // Der Vorlagen-Generator in der Verwaltung: Sprüche-Register + SVG-Bauer + Kurz-Adresse.
        st_expect(str_contains($qVer, 'wl_promo_sprueche') && str_contains($qVer, 'svgBau')
                && str_contains($qVer, 'RedirectMatch 301'),
            'veranstaltungen.php: der Vorlagen-Generator oder die Kurz-Adressen-Anleitung fehlt');
        // Das AStA-Logo im Marken-Verlauf (so gewollt) samt Verwandlungs-Schleife.
        st_expect(brand_pfad('logo-verlauf') !== null,
            'das Logo im was.läuft-Verlauf fehlt – weder ein eigenes in data/branding/ noch der Platzhalter in assets/');
        st_expect(str_contains($appCss ?? (string)@file_get_contents(__DIR__ . '/../assets/style.css'), '.wl-promo-farben'),
            'style.css: die Promo-Bausteine (.wl-promo-farben) fehlen');
        // Das Original-Logo bleibt überall unverändert, auch auf Dunklem – keine helle
        // Sonderfassung.
        st_expect(!str_contains($qUeber, 'logo-hell'),
            'die verworfene weiße Logo-Fassung ist zurück auf der Über-Seite – das Original bleibt, wie in der App');
        // Die Logo-Verwandlung auf der Über-Seite: beide Fassungen übereinander + Wisch-Keyframes.
        st_expect(str_contains($qUeber, 'logo-verlauf.png')
                && (bool)preg_match('~@keyframes wl-ua-wisch\s*\{~', $cssRein),
            'die Logo-Verwandlung der Über-Seite (logo-verlauf + wl-ua-wisch) fehlt');
        /* Die Teilen-Seite (Weitersagen): fertiges Werbematerial je Beitrag – und sie muss von
           überall erreichbar sein, wo man einem Beitrag begegnet (Beitrag, Einreich-Erfolg,
           Beitragsliste der Veranstalter, Verwaltung). Bricht ein Einstieg weg, entsteht
           genau der Doppelaufwand wieder, den die Seite abschaffen soll. */
        $qTeilen = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/teilen.php');
        st_expect($qTeilen !== '' && str_contains($qTeilen, 'tl-story') && str_contains($qTeilen, 'tl-post')
                && str_contains($qTeilen, 'qrcode.min.js') && str_contains($qTeilen, 'navigator.share'),
            'veranstaltungen/teilen.php fehlt oder hat Bilder/QR/System-Teilen verloren');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php'), 'teilen.php?e='),
            'die Beitragsseite verlinkt die Teilen-Seite nicht mehr');
        // Geteilt wird die SCHÖNE Kurz-Adresse (wl_share_base) – nicht der lange Pfad.
        st_expect(function_exists('wl_share_base') && str_contains($qTeilen, 'wl_share_base'),
            'die Teilen-Seite baut ihre Links nicht mehr über wl_share_base() – geteilt würde wieder die lange Adresse');
        $qEin = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/einreichen.php');
        st_expect(substr_count($qEin, 'teilen.php?e=') >= 2,
            'einreichen.php: Teilen-Einstiege fehlen (nach dem Eintragen und in „Eure Beiträge")');
        st_expect(str_contains($qVer, 'veranstaltungen/teilen.php?e='),
            'die Verwaltung verlinkt die Teilen-Seite nicht mehr');
        st_expect(str_contains($cssRein, '.wl-tl-block'),
            'wl.css: die Teilen-Seiten-Bausteine (.wl-tl-*) fehlen');
        // Teilen ohne Umweg: Auf Geräten mit Teilen-Blatt öffnet der
        // Knopf der Detailseite es DIREKT (navigator.share); der Link zur Teilen-Seite
        // bleibt als Rückfall bestehen (der Wächter oben prüft ihn weiter).
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php'), 'navigator.share'),
            'v.php: der direkte Teilen-Weg (navigator.share) fehlt – am Handy ginge es wieder über den Umweg der Teilen-Seite');
        // Freigabe-Vorschau (Bug): Unveröffentlichtes zeigt v.php nur mit dem
        // Beitrags-Token (?schau=…, hash_equals) – und der Vorschau-Link der Verwaltung
        // muss ihn mitgeben, sonst läuft die Freigabe in „gibt es nicht".
        st_expect(preg_match('~hash_equals[^;]*edit_token~s', (string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php'))
                && str_contains($qVer, 'schau='),
            'Die Freigabe-Vorschau (v.php?schau=Token) fehlt – wartende Beiträge wären aus der Verwaltung nicht ansehbar');
        // a-Falle am Zurück-Knopf: Ohne body.wl-Präfix gewinnt
        // `body.wl a { color: inherit }` – dunkle Tinte auf der dunklen Hero-Pille.
        st_expect(str_contains($cssRein, 'body.wl .wl-back'),
            'wl.css: .wl-back hat das body.wl-Präfix verloren – der Zurück-Knopf wäre wieder Tinte auf Dunkel (a-Falle)');
        // „Ersti-Woche" gibt es nicht – das Ding heißt O-Woche (Rückmeldung des Users,
        // abgeglichen mit der AStA-Seite). Der Begriff darf nirgends zurückkehren.
        foreach (['../wl-db.php', '../veranstaltungen.php', '../extern.php', '../admin/events.php', '../README.md'] as $ewDatei) {
            st_expect(!str_contains((string)@file_get_contents(__DIR__ . '/' . $ewDatei), 'Ersti-Woche'),
                'die erfundene „Ersti-Woche" ist zurück in ' . basename($ewDatei) . ' – das Ding heißt O-Woche');
        }
        // Einen Schein hinter „läuft" (::before am Wort) darf es nicht geben.
        st_expect(!str_contains($cssRein, '.wl-h1 .wl-mark-w::before'),
            'wl.css: der Schein hinter „läuft" (.wl-h1 .wl-mark-w::before) ist zurück – der User wollte ihn ausdrücklich nicht');
        foreach (['wl-hall', 'wl-lauf', 'wl-dot-kick', 'wl-dot-fall', 'wl-lupe-tilt', 'wl-dot-hop', 'wl-pop-auf'] as $kf) {
            st_expect((bool)preg_match('~@keyframes ' . preg_quote($kf, '~') . '\s*\{~', $cssRein),
                'wl.css: Keyframes ' . $kf . ' fehlen (Osterei ohne Bewegung)');
        }
        st_expect(str_contains($libSrc, 'wl-mark-was'),
            'wl_marke(): die was-Hülle (wl-mark-was) fehlt – die Ostereier hätten kein Ziel');
        // Empfohlen: WEDER Vorziehen NOCH eigene Reihe – das eine bricht die Chronologie, das
        // andere frisst Platz. Markiert wird an Ort und Stelle, allein durch den Stern-Chip.
        st_expect(!str_contains($qIndex, '$vorEvents') && !str_contains($qIndex, '<h2>Empfohlen</h2>'),
            'index.php: „Empfohlen" zieht wieder vor oder hat wieder eine eigene Reihe – die Liste bleibt chronologisch');
        st_expect(str_contains($libSrc, 'wl-card-tipp') && str_contains($cssRein, '.wl-card-tipp'),
            'der Stern-Chip „Empfohlen" fehlt auf der Kachel (Markup oder CSS)');
        // Kein goldener Rahmen (wl-card-emp) – der Chip allein ist die Markierung. $cssRein ist
        // kommentarbereinigt, ein erklärender Kommentar in wl.css schlägt hier also nicht an.
        st_expect(!str_contains($libSrc, 'wl-card-emp') && !str_contains($cssRein, '.wl-card-emp'),
            'der Empfohlen-Rahmen (wl-card-emp) ist zurück – der User wollte nur den Chip');
        /* Standard-Bilder: Jede Kategorie braucht mindestens DREI, jede Datei muss wirklich in
           assets/wl-standard/ liegen (sie werden mit der App ausgeliefert), und jede Angabe
           braucht credit UND quelle – zwei Aufnahmen stehen unter CC BY, da ist die
           Namensnennung Pflicht; ohne Fundort kann niemand mehr belegen, dass wir das Bild
           benutzen dürfen. */
        st_expect(function_exists('wl_standard_bilder'), 'wl_standard_bilder() fehlt – Beiträge ohne Bild wären wieder Farbflächen');
        if (function_exists('wl_standard_bilder')) {
            $stdB = wl_standard_bilder();
            foreach (wl_cats() as $ck => $cd) {
                $liste = (array)($stdB[$ck] ?? []);
                // auf Vorgabe von 3 auf 8 je Kategorie aufgestockt (CC0/PD,
                // handverlesen aus StockSnap/Commons/rawpixel – Vorschau angeschaut, Vollbilder
                // auf Wasserzeichen geprüft). Weniger als 8 hieße: jemand hat welche entfernt.
                st_expect(count($liste) >= 8, 'Standard-Bilder: Kategorie „' . $ck . '" hat weniger als acht');
                foreach ($liste as $b) {
                    $bn = (string)($b['file'] ?? '?');
                    $datei = __DIR__ . '/../assets/wl-standard/' . $bn;
                    st_expect(is_file($datei) && (int)@filesize($datei) > 10000,
                        'Standard-Bild fehlt oder ist leer: ' . $bn);
                    st_expect(trim((string)($b['credit'] ?? '')) !== '' && trim((string)($b['quelle'] ?? '')) !== '',
                        'Standard-Bild ohne Herkunftsangabe: ' . $bn);
                }
            }
            // Seit läuft die Anzeige über wl_item_std(): erst die GEWÄHLTE Datei
            // (items.std_bild, im Einreich-Formular auswählbar), sonst die Automatik.
            st_expect(str_contains($libSrc, 'wl_item_std('),
                'wl_kachel() nutzt die Standard-Bilder (wl_item_std) nicht mehr');
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php'), 'wl_item_std('),
                'v.php nutzt die Standard-Bilder (wl_item_std) nicht mehr');
            st_expect(in_array('std_bild', array_column(wl_db()->query('PRAGMA table_info(items)')->fetchAll(), 'name'), true),
                'items.std_bild fehlt – die Wahl eines Standard-Fotos ginge beim Speichern verloren');
            $qEin = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/einreichen.php');
            st_expect(str_contains($qEin, 'wl-pick-skat') && str_contains($qEin, "'std:'"),
                'einreichen.php: die Standard-Foto-Auswahl (wl-pick-skat / std:-Werte) fehlt – User-Wunsch vom 8. Aug 2026');
            st_expect(wl_item_std(['std_bild' => 'party-2.jpg', 'cat' => 'sport', 'id' => 7])['file'] === 'party-2.jpg'
                    && wl_item_std(['std_bild' => 'boese.jpg', 'cat' => 'party', 'id' => 1])['file'] === 'party-2.jpg',
                'wl_item_std(): gewählte Datei greift nicht oder Unbekanntes fällt nicht auf die Automatik zurück');
            /* Auch der TESTDATENSATZ muss die Fotos nehmen: Seine Bilder laufen als echte
               Uploads durch die Pipeline – wären es gemalte Verläufe, zeigte die Seite mit
               Testdaten genau die Farbflächen, die es nicht geben soll.
               Geprüft wird NUR der Rumpf von wl_demo_bildquelle(): „wl-standard" steht auch in
               Kommentaren weiter hinten in der Datei, eine Suche über den ganzen Quelltext
               wäre schon damit zufrieden. */
            $qDb   = (string)@file_get_contents(__DIR__ . '/../wl-db.php');
            $qAnf  = strpos($qDb, 'function wl_demo_bildquelle');
            $qEnde = $qAnf === false ? false : strpos($qDb, "\nfunction ", $qAnf + 1);
            st_expect($qAnf !== false && $qEnde !== false
                    && str_contains(substr($qDb, $qAnf, $qEnde - $qAnf), 'wl-standard'),
                'der Testdatensatz malt wieder Farbverläufe statt die Standard-Fotos zu nehmen');

            // Mitgelieferte BANNER-Motive: fünf Panorama-Bilder
            // (banner-*.jpg) samt Registry, in der Verwaltung als VORSCHAU-Kacheln wählbar
            // (kein Dropdown mehr), die Startseite kennt die std_bild-Weiche, und der
            // Testdatensatz legt beide Banner mit mitgeliefertem Motiv an.
            st_expect(function_exists('wl_banner_bilder') && count(wl_banner_bilder()) >= 5,
                'wl_banner_bilder() fehlt oder hat weniger als fünf Motive');
            if (function_exists('wl_banner_bilder')) {
                foreach (wl_banner_bilder() as $st_bb) {
                    $st_bf = (string)($st_bb['file'] ?? '?');
                    st_expect(is_file(__DIR__ . '/../assets/wl-standard/' . $st_bf) && (int)@filesize(__DIR__ . '/../assets/wl-standard/' . $st_bf) > 10000,
                        'Banner-Motiv fehlt oder ist leer: ' . $st_bf);
                    st_expect(str_contains((string)($st_bb['credit'] ?? ''), 'CC0') && trim((string)($st_bb['quelle'] ?? '')) !== '',
                        'Banner-Motiv ohne CC0-Nachweis oder Fundort: ' . $st_bf . ' (auf dem Banner ist kein Platz für Namensnennung – nur CC0 aufnehmen)');
                }
                st_expect(wl_banner_std_by_file('banner-1.jpg') !== null && wl_banner_std_by_file('gibtsnicht.jpg') === null,
                    'wl_banner_std_by_file(): Nachschlagen der Banner-Motive kaputt');
            }
            st_expect(in_array('std_bild', array_column(wl_db()->query('PRAGMA table_info(banners)')->fetchAll(), 'name'), true),
                'banners.std_bild fehlt – gewählte Banner-Motive gingen beim Speichern verloren');
            $qVerw = (string)@file_get_contents(__DIR__ . '/../veranstaltungen.php');
            st_expect(str_contains($qVerw, 'wl-adm-bildwahl') && str_contains($qVerw, 'wl_banner_bilder()')
                    && !str_contains($qVerw, '<select name="image_id" id="b_img">'),
                'veranstaltungen.php: die Banner-Bildwahl mit Vorschau-Kacheln fehlt oder das alte Dropdown ist zurück');
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/index.php'), 'wl_banner_std_by_file'),
                'index.php: die Banner zeigen mitgelieferte Motive (std_bild-Weiche) nicht mehr an');
            st_expect(str_contains($qDb, "'banner-1.jpg'") && str_contains($qDb, "'studi_rabatt' => '6 €")
                    && str_contains($qDb, "'std_bild' => 'sonstiges-6.jpg'"),
                'Testdatensatz: Banner-Motiv, Studi-Rabatt-Beitrag oder gewähltes Standard-Foto fehlen wieder');
            // „Nur für Studierende" braucht ZWEI Fälle im Testdatensatz: einen LIVE-Beitrag für
            // die Zeile auf der Beitragsseite und einen WARTENDEN für das Etikett in der Freigabe.
            // Mit nur einem davon bliebe die halbe Anzeige ungeprüft.
            st_expect(substr_count($qDb, "'nur_studis' => 1") >= 2
                    && preg_match("~'status' => 'pending'[^;]*'nur_studis' => 1~s", $qDb),
                'Testdatensatz: es fehlt ein Beitrag „nur für Studierende" – gebraucht werden ein '
                . 'live sichtbarer (Zeile auf der Beitragsseite) und ein wartender (Etikett in der Freigabe)');
        }

        // Bild-Weiche: Auf dem Server entscheidet sich hier, ob Uploads überhaupt gehen.
        st_expect(in_array(wl_image_engine(), ['imagick', 'gd', 'cli', ''], true), 'unbekannte Bild-Weiche');
        st_expect(wl_image_engine_label() !== '', 'die Bild-Weiche hat keine Beschreibung');
        st_expect(wl_image_engine() !== '',
            'Auf diesem Server kann PHP keine Bilder verarbeiten – Uploads sind nicht möglich. '
            . 'Mittwald hat ImageMagick installiert; nötig ist aber die PHP-Erweiterung imagick oder GD.');
        // HEIC (iPhone) darf nur angeboten werden, wenn es auch verarbeitet werden kann.
        st_expect(isset(wl_image_types()['image/heic']) === in_array(wl_image_engine(), ['imagick', 'cli'], true),
            'HEIC wird angeboten, obwohl GD es nicht verarbeiten kann (oder umgekehrt)');
        // Pfad-Hygiene beim Ausliefern – hier käme man sonst an beliebige Dateien.
        foreach (['../../lib', 'aa/bb', 'abc', str_repeat('A', 32), 'x.jpg'] as $boese) {
            st_expect(wl_image_path($boese) === '', 'wl_image_path() lässt „' . $boese . '" durch');
        }

        // Links aus Formularen: nur echte Web-Adressen.
        $probe = wl_item_save(['kind' => 'event', 'title' => 'Prüfung', 'starts_at' => '2030-01-01 20:00',
                               'url' => 'javascript:alert(1)', 'cat' => 'gibtsnicht', 'preis' => '7,50']);
        st_expect($probe['ok'], 'Probe-Beitrag ließ sich nicht anlegen: ' . $probe['msg']);
        if ($probe['ok']) {
            $p = wl_item($probe['id']);
            st_expect((string)$p['url'] === '', 'javascript:-Adressen kommen durch');
            st_expect((string)$p['cat'] === 'sonstiges', 'unbekannte Kategorien werden nicht ersetzt');
            // Nackte Zahl bekommt ihr €; „Spende" & Co. bleiben unangetastet.
            st_expect((string)$p['preis'] === '7,50 €', 'eine nackte Zahl als Preis bekommt kein „ €" mehr (steht: „' . (string)$p['preis'] . '")');
            st_expect((string)$p['status'] === 'pending', 'neue Beiträge müssen auf Freigabe warten');
            st_expect(strlen((string)$p['edit_token']) === 48, 'Bearbeiten-Token fehlt');
            // Vorbei-Rechnung: Veranstaltung über das Datum, Kurs über sein Enddatum.
            st_expect(!wl_item_vorbei($p), 'ein Termin 2030 kann nicht vorbei sein');
            st_expect(wl_item_vorbei(['kind' => 'event', 'starts_at' => '2020-01-01 20:00', 'ends_at' => '']),
                'ein Termin von 2020 muss als vorbei gelten');
            st_expect(!wl_item_vorbei(['kind' => 'kurs', 'bis_datum' => '']),
                'ein Kurs ohne Enddatum läuft weiter');
            st_expect(wl_item_vorbei(['kind' => 'kurs', 'bis_datum' => '2020-01-01']),
                'ein Kurs mit vergangenem Enddatum muss als vorbei gelten');
            // Nur Freigegebenes ist öffentlich.
            $ids = array_map(static fn ($x) => (int)$x['id'], wl_items_public([], 200));
            st_expect(!in_array($probe['id'], $ids, true), 'ein wartender Beitrag erscheint öffentlich');
            wl_item_freigeben($probe['id']);
            $ids = array_map(static fn ($x) => (int)$x['id'], wl_items_public([], 200));
            st_expect(in_array($probe['id'], $ids, true), 'ein freigegebener Beitrag erscheint NICHT öffentlich');
            wl_item_delete($probe['id']);
            st_expect(wl_item($probe['id']) === null, 'Löschen hat nicht gewirkt');
        }
        // Pflichtangaben
        st_expect(!wl_item_save(['kind' => 'event', 'title' => 'Ohne Datum'])['ok'], 'Veranstaltung ohne Datum wird angenommen');
        st_expect(!wl_item_save(['kind' => 'kurs', 'title' => 'Ohne Rhythmus'])['ok'], 'Kurs ohne Rhythmus wird angenommen');
        st_expect(!wl_item_save(['kind' => 'event', 'title' => ''])['ok'], 'Beitrag ohne Titel wird angenommen');

        // ---- Bearbeiten + Absagen + Sichtbarkeit -----------------------------------
        // Gruppen bearbeiten ihre Beiträge selbst (live über den Änderungs-Stapel, die alte
        // Fassung bleibt so lange online) und sagen ab, statt zu löschen.
        st_expect(in_array('abgesagt', array_column(wl_db()->query('PRAGMA table_info(items)')->fetchAll(), 'name'), true)
                && in_array('absage_push_done', array_column(wl_db()->query('PRAGMA table_info(items)')->fetchAll(), 'name'), true),
            'items.abgesagt/absage_push_done fehlen – Absagen gingen beim Speichern verloren');
        st_expect((int)wl_db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='item_edits'")->fetchColumn() === 1,
            'die Tabelle item_edits fehlt – Änderungen an Live-Beiträgen hätten keinen Ort');
        st_expect(function_exists('wl_item_felder') && substr_count($dbSrc, 'wl_item_felder(') >= 3,
            'wl_item_felder() muss der GEMEINSAME Prüf-Kern von Speichern und Änderungs-Stapel sein');
        // Der ganze Weg einmal in echt: Beitrag anlegen, Änderung beiseitelegen (Original
        // unberührt), anwenden, absagen, Absage zurücknehmen – und alles restlos aufräumen.
        $st_ed = wl_item_save(['kind' => 'event', 'title' => 'Änderungs-Probe', 'starts_at' => '2030-01-01 20:00']);
        st_expect($st_ed['ok'], 'Probe-Beitrag für den Änderungs-Stapel ließ sich nicht anlegen');
        if ($st_ed['ok']) {
            $st_r = wl_item_edit_store((int)$st_ed['id'], ['kind' => 'event', 'title' => 'Änderungs-Probe NEU', 'starts_at' => '2030-01-02 21:00']);
            st_expect($st_r['ok'], 'wl_item_edit_store nimmt eine gültige Änderung nicht an: ' . $st_r['msg']);
            st_expect(!wl_item_edit_store((int)$st_ed['id'], ['kind' => 'event', 'title' => ''])['ok'],
                'wl_item_edit_store lässt eine Änderung ohne Titel durch – die scheiterte erst beim Anwenden');
            st_expect((string)wl_item((int)$st_ed['id'])['title'] === 'Änderungs-Probe',
                'das Beiseitelegen einer Änderung hat den Beitrag schon verändert – die alte Fassung muss stehen bleiben');
            st_expect(wl_item_edit((int)$st_ed['id']) !== null, 'die beiseitegelegte Änderung ist nicht auffindbar');
            st_expect(wl_item_edit_apply((int)$st_ed['id'])
                    && (string)wl_item((int)$st_ed['id'])['title'] === 'Änderungs-Probe NEU'
                    && wl_item_edit((int)$st_ed['id']) === null,
                'wl_item_edit_apply schreibt die Änderung nicht (oder räumt sie nicht ab)');
            wl_item_absage((int)$st_ed['id'], true);
            st_expect((int)wl_item((int)$st_ed['id'])['abgesagt'] === 1, 'wl_item_absage setzt die Banderole nicht');
            wl_db()->prepare('UPDATE items SET absage_push_done = 1 WHERE id = ?')->execute([(int)$st_ed['id']]);
            wl_item_absage((int)$st_ed['id'], false);
            $st_it = wl_item((int)$st_ed['id']);
            st_expect((int)$st_it['abgesagt'] === 0 && (int)$st_it['absage_push_done'] === 0,
                'die Rücknahme einer Absage muss auch den Push-Merker zurücksetzen (sonst bliebe eine zweite Absage stumm)');
            wl_item_delete((int)$st_ed['id']);
            st_expect((int)wl_db()->query('SELECT COUNT(*) FROM item_edits WHERE item_id = ' . (int)$st_ed['id'])->fetchColumn() === 0,
                'Löschen lässt Zeilen in item_edits zurück');
        }
        // Cron: Absage-Mitteilung an die Erinnerten (VOR den Erinnerungen, sonst klingelt im
        // selben Lauf noch ein „Gleich: …"), und weder Erinnerung noch Neu-Meldung für Abgesagtes.
        st_expect(str_contains($dbSrc, 'absage_push_done = 0') && str_contains($dbSrc, "'Abgesagt: '"),
            'wl_push_cron: der Absage-Versand an die Erinnerten fehlt');
        st_expect(str_contains($dbSrc, "\$r['abgesagt']"),
            'wl_push_cron: fällige Erinnerungen prüfen die Absage nicht mehr – Abgesagtes würde brav erinnert');
        st_expect(str_contains($dbSrc, 'i.push_done = 0 AND i.demo = 0 AND i.abgesagt = 0'),
            'wl_push_cron: Abgesagtes würde als „Neu" an die Abonnenten ausgerufen');
        // Veranstalter-Dashboard: Bearbeiten, Nochmal eintragen (Vorlage ohne Termin), Absagen
        // mit eigenem Dialog – Besitz-Prüfungen hängen an org_id, nicht am Formular.
        $qEinr2 = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/einreichen.php');
        st_expect(str_contains($qEinr2, 'name="bearb"') && str_contains($qEinr2, 'wl_item_edit_store')
                && str_contains($qEinr2, '?bearb='),
            'einreichen.php: der Bearbeiten-Weg (verstecktes bearb-Feld + Änderungs-Stapel) fehlt');
        // Ein „Nochmal eintragen" (?kopie=) darf es nicht geben – geprüft wird der Code-Griff
        // und nicht das Wort, damit der erklärende Kommentar in einreichen.php diese Sperre
        // nicht selbst auslöst.
        st_expect(!str_contains($qEinr2, "\$_GET['kopie']"),
            'einreichen.php: „Nochmal eintragen" (?kopie=) ist zurück – das hat der User ausdrücklich NICHT gewollt');
        // Der Rückkanal an die Veranstalter: Aufruf-Zähler je Beitrag – reines
        // Hochzählen einmal je Sitzung, Crawler zählen nicht, Anzeige im Gruppen-Dashboard
        // (Summe + je Beitrag samt gesetzter Erinnerungen).
        st_expect(in_array('aufrufe', array_column(wl_db()->query('PRAGMA table_info(items)')->fetchAll(), 'name'), true),
            'items.aufrufe fehlt – der Aufruf-Zähler hätte keine Spalte');
        // Besucherzählung: datensparsam per Tages-Salz. Geprüft wird, dass es die
        // beiden Tabellen gibt, dass gezählt wird – und vor allem, dass NICHTS Persönliches
        // gespeichert wird: stats_seen hat genau zwei Spalten, keine IP, keine Adresse.
        $wlTab = static fn (string $tab): array => array_column(wl_db()->query('PRAGMA table_info(' . $tab . ')')->fetchAll(), 'name');
        st_expect($wlTab('stats_days') === ['day', 'visitors', 'views'], 'stats_days fehlt oder hat die falschen Spalten');
        st_expect($wlTab('stats_seen') === ['h', 'day'], 'stats_seen darf NUR Hash und Tag führen – niemals Adresse, Pfad oder Kennung');
        st_expect(function_exists('wl_stat_hit') && function_exists('wl_stats_range') && function_exists('wl_stats_summe'),
            'die Zähl-/Auswertungs-Funktionen der Besucherzählung fehlen');
        $qWlDb = (string)@file_get_contents(__DIR__ . '/../wl-db.php');
        st_expect(str_contains($qWlDb, "wl_setting_set('stats_salt'"), 'das Tages-Salz wird nicht mehr rotiert – Hashes blieben über Tage vergleichbar');
        st_expect(str_contains($qWlDb, "DELETE FROM stats_seen WHERE day <> ?"), 'die Tagesmerker werden beim Salz-Wechsel nicht mehr gelöscht');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php'), 'wl_stat_hit()'),
            'wl_head() zählt keine Besuche mehr');
        // Veraltete Merker wären ein Datenrest. Geprüft wird die MECHANIK, nicht der Zeitpunkt:
        // Erst selbst rotieren (räumt beim Tageswechsel ab) – sonst meldet der Test jeden Morgen
        // Gestern-Merker, die nur darauf warten, dass Cron oder erste:r Besucher:in rotiert.
        // Verglichen wird gegen date('Y-m-d') aus PHP, dasselbe Datum, mit dem wl_stat_hit()
        // schreibt – SQLites date('now','localtime') kann je Server-Zeitzone ein anderer „Tag" sein.
        wl_tages_salz();
        $amq = wl_db()->prepare('SELECT COUNT(*) FROM stats_seen WHERE day <> ?');
        $amq->execute([date('Y-m-d')]);
        $altMerker = (int)$amq->fetchColumn();
        st_expect($altMerker === 0, $altMerker . ' Besucher-Merker von früheren Tagen liegen noch da – die Abräum-Mechanik beim Salz-Wechsel greift nicht');
        // Adressen: „www.foo.de" muss reichen, „javascript:" darf nie durchkommen.
        st_expect(wl_url_norm('beispiel.de/x') === 'https://beispiel.de/x'
            && wl_url_norm('www.foo.de') === 'https://www.foo.de'
            && wl_url_norm('https://foo.de/a') === 'https://foo.de/a',
            'wl_url_norm() ergänzt das Schema nicht mehr – dann verlangt die Eingabe wieder „https://"');
        st_expect(wl_url_norm('javascript:alert(1)') === '' && wl_url_norm('data:x') === ''
            && wl_url_norm('mailto:a@b.de') === '' && wl_url_norm('ohnepunkt') === '',
            'wl_url_norm() lässt etwas durch, das keine Web-Adresse ist');
        st_expect(wl_url_kurz('https://www.beispiel.de/was.laeuft/') === 'beispiel.de/was.laeuft',
            'wl_url_kurz() liefert nicht die kurze Form fürs Gedruckte');
        st_expect(!str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/einreichen.php'), 'type="url"'),
            'einreichen.php hat wieder type="url"-Felder – die verlangen im Browser zwingend „https://"');
        // $qV2 entsteht erst weiter unten – eigene Quelle laden (bekannte Falle, s. $qVer oben).
        $qVza = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php');
        // Die Bot-Liste steht EINMAL in wl_ist_bot(): Seiten-Statistik und Aufruf-Zähler je
        // Beitrag müssen dasselbe Publikum meinen.
        st_expect(str_contains($qVza, 'aufrufe = aufrufe + 1') && str_contains($qVza, "wl_gesehen")
                && str_contains($qVza, 'wl_ist_bot()'),
            'v.php: das Hochzählen der Aufrufe (einmal je Sitzung, ohne Crawler) fehlt');
        st_expect(str_contains($qEinr2, 'Aufrufe eurer Beiträge') && str_contains($qEinr2, 'wecker'),
            'einreichen.php: die Aufruf-/Erinnerungs-Zahlen im Veranstalter-Dashboard fehlen');
        st_expect((bool)preg_match("~'absage'.*?org_id.*?wl_item_absage~s", $qEinr2)
                && str_contains($qEinr2, 'wl-absage-dialog'),
            'einreichen.php: Absagen (Handler mit org_id-Prüfung + eigener Dialog) fehlt');
        // Verwaltung: Änderungs-Stapel mit Diff, Übernehmen/Verwerfen, Absage auch von dort.
        $qVerw2 = (string)@file_get_contents(__DIR__ . '/../veranstaltungen.php');
        st_expect(str_contains($qVerw2, 'wl_item_edits_offen') && str_contains($qVerw2, "'edit_apply'")
                && str_contains($qVerw2, "'edit_verwerfen'") && str_contains($qVerw2, "'item_absage'"),
            'veranstaltungen.php: Änderungs-Stapel oder Absage-Aktionen fehlen');
        // Öffentliche Seiten: Banderole überall, Handlungs-Knöpfe weg, Kalenderdatei ehrlich.
        $qV2 = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php');
        // Der Text der Banderole steht seit der Zweisprachigkeit im Sprachregister, nicht mehr
        // wörtlich in v.php – geprüft wird deshalb der Schlüssel.
        st_expect((bool)preg_match("~wl-note bad.*?wl_t\('abgesagt'\)~s", $qV2) && str_contains($qV2, '!$abgesagt'),
            'v.php: Absage-Banderole oder das Ausblenden der Handlungs-Knöpfe fehlt');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php'), 'wl-card-abges')
                && str_contains($cssRein, '.wl-card-abges'),
            'die Absage-Banderole auf der Kachel (wl-card-abges) fehlt in wl_kachel oder wl.css');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/ics.php'), 'STATUS:CANCELLED'),
            'ics.php: abgesagte Termine müssen als STATUS:CANCELLED in die Kalenderdatei');
        // Sichtbarkeit von außen: schema.org/Event auf der Beitragsseite + Sitemap. Der
        // billigste Reichweiten-Hebel der Seite – wer ihn ausbaut, soll es hier merken.
        st_expect(str_contains($qV2, 'application/ld+json') && str_contains($qV2, "'@type'               => 'Event'")
                && str_contains($qV2, 'EventCancelled'),
            'v.php: die schema.org/Event-Daten (inkl. EventCancelled bei Absagen) fehlen');
        $qSitemap = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/sitemap.php');
        st_expect(str_contains($qSitemap, '<urlset') && str_contains($qSitemap, "v.php?id=")
                && str_contains($qSitemap, 'demo = 0'),
            'veranstaltungen/sitemap.php fehlt oder lässt Demo-Beiträge in den Google-Index');
        // ---- Teilen-Seite: Branding-Stufen + Web-Paket --------------------
        // Zwei Marken-Fassungen (Standard was.läuft; „Eure Marke zuerst" mit Logo/Name und
        // kleiner was.läuft-Marke am Fuß), wählbares QR-Ziel (nur http/https, rein im Browser)
        // und das Web-Banner samt Einbett-Schnipsel für die eigene Seite der Gruppe.
        $qTl = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/teilen.php');
        st_expect(str_contains($qTl, 'tlmarke') && str_contains($qTl, 'logoKreis')
                && str_contains($qTl, "wahl.marke === 'org'"),
            'teilen.php: die Branding-Stufe „Eure Marke zuerst" (rundes Logo mit Verlaufs-Ring) fehlt');
        // Die weiße Logo-Trägerkachel ist ein USER-VETO („Igitt! Auf weißem Hintergrund?!") –
        // und der Post ist das Schlagzeilen-Plakat (Foto vollflächig + Scrim), nicht mehr die
        // gestapelte Karten-Optik.
        st_expect(!str_contains($qTl, 'logoKachel'),
            'teilen.php: die weiße Logo-Kachel ist zurück – das Logo gehört als runder Avatar mit Verlaufs-Ring aufs Material');
        st_expect(substr_count($qTl, 'rgba(23,20,28') >= 2 && str_contains($qTl, 'markeLinks'),
            'teilen.php: das Schlagzeilen-Post (vollflächiges Foto mit Tinten-Scrim, Text unten links) fehlt');
        st_expect(str_contains($qTl, 'tlziel') && str_contains($qTl, 'qrNeu(') && str_contains($qTl, 'function zielVoll('),
            'teilen.php: das wählbare QR-Ziel (mit Adress-Prüfung) fehlt');
        st_expect(str_contains($qTl, 'tl-web') && str_contains($qTl, 'tl-embed')
                && str_contains($qTl, '1200') && str_contains($qTl, 'waslaeuft-web-'),
            'teilen.php: Web-Banner oder Einbett-Schnipsel für die eigene Webseite fehlen');
        st_expect(str_contains($qTl, 'orgLogo') && str_contains($qTl, 'logo_id'),
            'teilen.php: das hinterlegte Gruppen-Logo kommt nicht mehr aufs Material');
        st_expect(str_contains($cssRein, '.wl-tl-breit') && str_contains($cssRein, '.wl-tl-ziel'),
            'wl.css: die Bausteine der Teilen-Optionen (.wl-tl-breit/.wl-tl-ziel) fehlen');
        // Formular-Knöpfe („Absagen", Papierkorb …) müssen so hoch sein wie die Link-Knöpfe
        // daneben. Ursache ist die Zeilenhöhe: <button> steht auf „normal", <a> erbt die 1.5
        // der Seite – .wl-btn setzt sie deshalb explizit.
        // Dazu bleibt die Streck-Regel für Formulare in der Beitragszeile.
        st_expect((bool)preg_match('~\.wl-btn \{[^}]*line-height: 1\.5~s', $cssRein),
            'wl.css: .wl-btn ohne line-height – Formular-Knöpfe fallen wieder kleiner aus als Link-Knöpfe');
        st_expect(str_contains($cssRein, '.wl-meine-a form { display: flex; }'),
            'wl.css: die Streck-Regel für Formular-Knöpfe in der Beitragszeile fehlt');
        // ---- Bescheid-Mails + Ablehnungs-Kreislauf ------------------------
        // Die Gruppe erfährt automatisch, was aus ihrer Einreichung wurde (vier Anlässe über
        // wl_item_bescheid), und Abgelehntes steht MIT BEGRÜNDUNG im Dashboard – Überarbeiten
        // setzt es wieder auf „wartet". Eine nur gespeicherte Begründung erreicht niemanden.
        st_expect(function_exists('wl_item_bescheid')
                && substr_count($qVerw2, 'wl_item_bescheid(') >= 4,
            'wl_item_bescheid fehlt oder die Verwaltung verschickt nicht alle vier Bescheide (freigeben/ablehnen/Änderung übernommen/verworfen)');
        st_expect(str_contains($qEinr2, "'live','pending','rejected'")
                && str_contains($qEinr2, 'wl-meine-grund')
                && str_contains($qEinr2, 'Überarbeiten &amp; neu einreichen'),
            'einreichen.php: Abgelehntes fehlt im Dashboard (Liste, Begründung oder Überarbeiten-Knopf)');
        st_expect((bool)preg_match("~'rejected'.*?status = 'pending', note = ''~s", $qEinr2),
            'einreichen.php: Überarbeiten setzt einen abgelehnten Beitrag nicht zurück auf „wartet"');
        // Mail-Einstellungen nach dem Muster der anderen externen Bereiche: Absender-Adresse
        // (hier DARF es die eigene was.läuft-Adresse sein), Absender-Name, cap_day (Vorgabe
        // 300), Schalter, Testmail. Schalter und Absender müssen im VERSAND greifen.
        st_expect(str_contains($dbSrc, "wl_setting('bescheid_mails', '1')")
                && function_exists('wl_mail_cap') && str_contains($dbSrc, "wl_setting('cap_day', '300')"),
            'wl-db.php: Bescheid-Schalter oder Tagesdeckel (cap_day, Vorgabe 300) greifen nicht');
        st_expect(str_contains($dbSrc, "wl_setting('from_email', '')")
                && str_contains($dbSrc, "wl_setting('from_name', 'was.läuft')"),
            'wl_mail: die eingestellte Absender-Adresse/-Name kommen nicht in die Mail-Köpfe');
        st_expect(str_contains($qVerw2, "'bescheid_mails'") && str_contains($qVerw2, "'cap_day'")
                && str_contains($qVerw2, 'name="from_email"') && str_contains($qVerw2, 'name="from_name"')
                && str_contains($qVerw2, "'test_mail'"),
            'veranstaltungen.php: die Mail-Einstellungen (Absender/Name/Deckel/Schalter/Testmail) fehlen im Einstellungen-Reiter');
        /* ---- Demo-Modus -------------------------------------------------------------------
           Der Schalter besteht aus VIER Teilen, und jeder einzelne macht ihn wirkungslos, wenn er
           fehlt: die Abfrage, das Speichern, der Hinweis-Baustein und seine zwei Einbauorte.
           Besonders heimtückisch wäre der Verlust eines Einbauorts – der Schalter sähe in der
           Verwaltung weiter richtig aus, und niemand käme auf die Idee nachzusehen. */
        st_expect(function_exists('wl_demo'), 'wl-db.php: wl_demo() fehlt – der Demo-Modus ist nicht abfragbar');
        st_expect(wl_setting_raw('demo') !== null || wl_demo() === false,
            'wl_demo(): ohne Eintrag muss der Demo-Modus AUS sein');
        // Der Betriebszustand steht als drei Karten da, nicht als Aufklapp-Menü. Geprüft wird der
        // ganze Satz: Ein verlorener Wert ließe sich nicht mehr einstellen, und niemand vermisst
        // eine Möglichkeit, die er nie gesehen hat.
        st_expect(!preg_match('~<select[^>]*name="mode"~', $qVerw2),
            'veranstaltungen.php: der Betriebszustand ist wieder ein Aufklapp-Menü');
        // Gegen die AUSRICHTUNG unempfindlich: Die Werte stehen im Quelltext bündig untereinander,
        // und ein Leerzeichen mehr oder weniger darf keine Prüfung rot machen.
        foreach (['on', 'soft', 'off'] as $zst) {
            st_expect((bool)preg_match("~'" . $zst . "'\s*=>\s*\['ti-~", $qVerw2),
                'veranstaltungen.php: dem Betriebszustand fehlt die Karte für „' . $zst . '"');
        }
        // style.css hier selbst lesen: Der Selbsttest läuft in einzelnen Blöcken, und die weiter
        // oben eingelesenen Quelltexte gelten in diesem nicht. Eine geerbte Variable wäre hier
        // leer – die Prüfung schlüge fehl, obwohl die Regel dasteht.
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../assets/style.css'),
            '.wahlkarte:has(input:checked)'),
            'style.css: die Auswahl-Karten zeigen nicht mehr, welche gewählt ist');
        st_expect(str_contains($qVerw2, 'name="demo"') && str_contains($qVerw2, "wl_setting_set('demo'"),
            'veranstaltungen.php: der Demo-Schalter fehlt in den Einstellungen oder wird nicht gespeichert');
        st_expect(str_contains($qVerw2, 'name="demo_text"') && str_contains($qVerw2, "wl_setting_set('demo_text'"),
            'veranstaltungen.php: das Feld für den eigenen Wortlaut fehlt oder wird nicht gespeichert');
        $qWlLib = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php');
        /* ---- Auffindbarkeit (SEO) ----------------------------------------------------------
           Der kanonische Verweis ist der wichtigste Teil: Ohne ihn zaehlt jede Filter-Kombination
           der Listenseiten (?zeit=, ?kat=, ?q=) als eigene Seite mit fast gleichem Inhalt.
           Beim Terminplaner gilt er NUR fuer die Startseite – einzelne Umfragen tragen noindex,
           ihr Link ist der Schluessel. */
        /* Die App selbst gehoert in keinen Index – sichtbar ist nach aussen nur die Anmeldung.
           Bewusst per noindex und NICHT per robots.txt: Wer nicht crawlen darf, liest das
           noindex nie, und eine verlinkte Adresse landet trotzdem im Index. */
        $qLibKopf = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect(str_contains($qLibKopf, '<meta name="robots" content="noindex, nofollow">')
                && str_contains($qLibKopf, "header('X-Robots-Tag: noindex, nofollow')"),
            'lib.php: die App-Seiten (samt Anmeldung) sind wieder indexierbar');
        st_expect(str_contains($qWlLib, 'rel="canonical"') && str_contains($qWlLib, '$kanon'),
            'wl-lib.php: der kanonische Verweis fehlt – gefilterte Listen zaehlen dann als eigene Seiten');
        st_expect(str_contains($qWlLib, 'hreflang="x-default"'),
            'wl-lib.php: die Sprachverweise (hreflang) fehlen');
        $qWlIdx = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/index.php');
        st_expect(str_contains($qWlIdx, 'application/ld+json') && str_contains($qWlIdx, 'SearchAction'),
            'veranstaltungen/index.php: die strukturierten Daten der Startseite fehlen');
        $qTpLib = (string)@file_get_contents(__DIR__ . '/../termin/termin-lib.php');
        st_expect(str_contains($qTpLib, 'function tplan_kanon(')
                && (bool)preg_match('~\$noindex \? \x27\x27 : tplan_kanon\(\)~', $qTpLib),
            'termin-lib.php: der kanonische Verweis fehlt oder steht auch auf den noindex-Seiten');
        $qTpIdx = (string)@file_get_contents(__DIR__ . '/../termin/index.php');
        st_expect(str_contains($qTpIdx, 'WebApplication'),
            'termin/index.php: die strukturierten Daten des Terminplaners fehlen');
        /* Ohne Bild geht ein geteilter Link in Messengern und Feeds unter – und geteilt wird
           dieses Werkzeug fast nur so. */
        st_expect(str_contains($qTpLib, 'property="og:image"') && str_contains($qTpLib, 'name="twitter:card"'),
            'termin-lib.php: die Teilen-Vorschau fehlt, geteilte Links erscheinen ohne Bild');
        /* Die haeufigen Fragen stehen EINMAL in tplan_faq(); Kaesten und Auszeichnung entstehen
           beide daraus. Steht eine Frage wieder fest im Quelltext, laufen sichtbare und
           ausgezeichnete Fassung auseinander – genau das wertet Google ab. */
        st_expect(str_contains($qTpIdx, 'FAQPage') && str_contains($qTpIdx, 'tplan_faq()'),
            'termin/index.php: die haeufigen Fragen sind nicht mehr als FAQ ausgezeichnet');
        st_expect(!preg_match('~<summary>\s*[^<\s]~', $qTpIdx),
            'termin/index.php: eine Frage steht wieder fest im Quelltext statt in tplan_faq()');
        /* ---- Banner fuer die eigene Website ---------------------------------------------------
           Vorlage im Promo-Ordner, damit sie im Pressekit landet; die Verwaltung setzt nur die
           Adresse ein. Ein Banner mit dem Platzhalter drin waere schlimmer als keines. */
        $qBan = (string)@file_get_contents(__DIR__ . '/../assets/wl-promo/banner-website.html');
        /* Der Trick des Banners: Das HTML wird EINMAL in die fremde Seite gesetzt und holt sich
           Texte und Ein/Aus danach bei banner.php. Fehlt der Abruf, waere es wieder ein starres
           Stueck, das niemand mehr aendern kann, ohne WordPress anzufassen. */
        st_expect(str_contains($qBan, 'data-quelle="WLB_URL"') && str_contains($qBan, "fetch(el.getAttribute('data-quelle')"),
            'das Website-Banner holt seine Texte nicht mehr aus der Verwaltung – dann klebt es fest');
        $qBanPhp = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/banner.php');
        st_expect($qBanPhp !== '' && str_contains($qBanPhp, 'Access-Control-Allow-Origin'),
            'veranstaltungen/banner.php fehlt oder erlaubt den Abruf von der Website nicht');
        st_expect(!str_contains((string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $qBanPhp), 'wl_offline_guard'),
            'banner.php hat einen Wächter – dann bekommt die Website bei geschlossenem Portal HTML statt einer Antwort');
        st_expect(str_contains($qVerw2, "'banner_web'") && isset(wl_text_fields()['banner_titel'])
                && isset(wl_text_fields()['banner_chips']),
            'der Schalter oder die Texte des Website-Banners fehlen in der Verwaltung');
        // Das Band darf nicht randlos ueber die ganze Seite laufen: Auf breiten Bildschirmen
        // klebte es sonst an den Kanten und der Schliesser sass auf dem Fensterrand.
        st_expect(str_contains($qBan, 'max-width:1180px') && str_contains($qBan, '@keyframes wlb-rein'),
            'das Website-Banner ist wieder randlos oder schiebt sich nicht mehr herein');
        st_expect(str_contains($qBan, 'WLB_URL') && str_contains($qBan, 'wlb-zu'),
            'assets/wl-promo/banner-website.html fehlt, hat keinen Adress-Platzhalter oder keinen Schliessen-Knopf');
        st_expect(!preg_match('~<(script|link|img)[^>]+(src|href)=["\x27]https?://~i', $qBan),
            'das Website-Banner laedt etwas von fremden Servern – es soll ohne alles auskommen');
        st_expect(str_contains($qVerw2, 'banner-website.html') && str_contains($qVerw2, 'promo-banner'),
            'veranstaltungen.php: der Banner-Code fehlt im Promo-Reiter');
        // Reihenfolge im Reiter: Der Banner steht direkt hinter dem Pressekit, und der lange
        // Code liegt eingeklappt darunter – ausgeklappt schöbe er alles andere nach unten.
        st_expect(strpos($qVerw2, 'Banner für eure Website') > strpos($qVerw2, '> Pressekit')
                && strpos($qVerw2, 'Banner für eure Website') < strpos($qVerw2, 'Marke &amp; Farben'),
            'veranstaltungen.php: der Banner-Block steht nicht mehr direkt unter dem Pressekit');
        st_expect((bool)preg_match('~<details class="collapse-card"(?![^>]*\bopen\b)~', $qVerw2),
            'veranstaltungen.php: der Banner-Code ist nicht mehr eingeklappt');
        /* ---- Betriebszustand „Geschlossen": eigene Seite im Portal-Look --------------------
           Der Wächter wird hier NICHT aufgerufen: Er setzt Kopfzeilen (503, Retry-After) und
           würde den Statuscode dieser Selbsttest-Seite mitreißen. Geprüft wird deshalb der
           Quelltext – und zwar auf alle drei Teile, die die Seite ausmachen. */
        /* Erst den Funktionskörper herausschneiden, dann darin suchen. Sonst prüft man die ganze
           Datei: „wl_css_url()" steht dort auch als Funktionsdefinition, und ein ungebremstes
           .*? läuft über das Ende des Wächters hinaus bis zum nächsten wl_nav() irgendwo weiter
           unten. Beide Fallen sind beim Bauen zugeschlagen. */
        // Ohne die Klammern festzunageln: Die Signatur hat seit dem Vorabstart einen Parameter,
        // und „wl_offline_guard()" traf danach nicht mehr – der Ausschnitt blieb leer und ALLE
        // Prüfungen darunter schlugen fehl, obwohl die Seite in Ordnung war.
        $qZuFn = preg_match('~function wl_offline_guard\(.*?\n\}~s', $qWlLib, $mZu) ? $mZu[0] : '';
        st_expect(str_contains($qZuFn, 'wl-zu') && str_contains($qZuFn, 'wl_css_url()'),
            'wl-lib.php: die Geschlossen-Seite steht wieder ohne Stylesheet da (nacktes HTML)');
        /* ---- Vorabstart („pre") -----------------------------------------------------------
           Die Sonderregel ist der ganze Sinn des Zustands: Einreichen und Veranstalter-Bereich
           bleiben offen, alles andere ist zu. Fiele das `true` in einer der beiden Seiten weg,
           sähen die Gruppen bis zum Start nur die Vorab-Seite – und niemand merkte es, weil die
           Verwaltung weiter richtig aussieht. */
        st_expect(in_array('pre', ['on', 'soft', 'off', 'pre'], true)
            && str_contains((string)@file_get_contents(__DIR__ . '/../wl-db.php'), "'off', 'pre'"),
            'wl-db.php: wl_mode() kennt den Vorabstart nicht mehr');
        foreach (['einreichen.php', 'veranstalter.php'] as $preSeite) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/' . $preSeite),
                'wl_offline_guard(true)'),
                'veranstaltungen/' . $preSeite . ': im Vorabstart zu – dort sollen die Gruppen aber schon eintragen');
        }
        foreach (['index.php', 'v.php', 'kurse.php', 'ueber.php'] as $preZu) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/' . $preZu),
                'wl_offline_guard()'),
                'veranstaltungen/' . $preZu . ': steht im Vorabstart offen – dort gehört die Vorab-Seite hin');
        }
        st_expect(str_contains($qZuFn, 'wl_launch_at()') && str_contains($qZuFn, 'wl_datum_voll'),
            'wl-lib.php: der Vorab-Seite fehlt der Starttermin');

        /* ---- Interne Sicht: angemeldete Gruppen kommen durch den Wächter -------------------
           Ohne diesen Durchlass sehen die Gruppen im Vorabstart ihre eigenen Beiträge nicht –
           genau daran ist es einmal aufgefallen. Und ohne das Band halten sie das, was nur sie
           sehen, für den öffentlichen Stand. */
        st_expect(str_contains($qWlLib, 'function wl_login_org(') && str_contains($qWlLib, 'function wl_intern_sicht('),
            'wl-lib.php: wl_login_org()/wl_intern_sicht() fehlen – dann kennt der Wächter die Angemeldeten nicht');
        st_expect(str_contains($qZuFn, 'wl_login_org()'),
            'wl-lib.php: der Wächter lässt angemeldete Gruppen nicht mehr durch – sie kommen an ihre eigenen Beiträge nicht heran');
        // Der Kopf muss AUCH in wl_head stehen: Dort wird er zuletzt gesetzt und überschreibt sonst
        // den privaten Wert aus dem Wächter – genau so gesehen.
        st_expect(substr_count($qWlLib, 'private, no-store') >= 2,
            'wl-lib.php: die interne Vorschau darf nirgends zwischengespeichert werden (Wächter UND wl_head)');
        $qNavFn = preg_match('~function wl_nav\(.*?
\}~s', $qWlLib, $mNav) ? $mNav[0] : '';
        st_expect(str_contains($qNavFn, 'wl_intern_band()'),
            'wl-lib.php: das Band „Nur für euch sichtbar" hängt nicht mehr in der Kopfleiste');
        st_expect((bool)preg_match('~\.wl-intern\s*\{~', (string)@file_get_contents(__DIR__ . '/../assets/wl.css')),
            'wl.css: dem Band .wl-intern fehlen die Regeln – dann steht es unauffällig als graue Zeile da');
        foreach (['intern_titel', 'intern_pre', 'intern_off', 'intern_ab', 'intern_ab_uhr'] as $iK) {
            st_expect(trim(wl_t($iK)) !== '', 'Der Text „' . $iK . '" fehlt im Register');
        }
        // Die Platzhalter müssen die sein, die wl_intern_band() ersetzt – sonst steht {datum} draußen.
        st_expect(str_contains(wl_t('intern_ab'), '{datum}')
               && str_contains(wl_t('intern_ab_uhr'), '{datum}') && str_contains(wl_t('intern_ab_uhr'), '{zeit}'),
            'Den Texten „Öffentlich ab …" fehlen die Platzhalter {datum}/{zeit}');
        // Der Weg zum Formular ist im Vorabstart der eigentliche Zweck der Seite – und er darf
        // NUR dort stehen: Bei „Geschlossen" weist derselbe Wächter das Formular ebenfalls ab.
        st_expect((bool)preg_match('~\$vorab.*?einreichen\.php~s', $qZuFn),
            'wl-lib.php: die Vorab-Seite verweist nicht mehr aufs Eintragen – dann findet keine Gruppe hin');
        // Die große Zahl IST die Seite – ohne ihre Regeln steht dort nur eine nackte Ziffer.
        $qCssVor = (string)@file_get_contents(__DIR__ . '/../assets/wl.css');
        /* Der Farbfluss muss NAHTLOS loopen. Dafuer muss das Muster sich wiederholen und die
           Verschiebung genau eine Periode betragen – mit linear-gradient + Prozentwerten blitzte
           an einer Stelle der Sprung. Geprueft wird beides, weil eines ohne das andere nichts
           nuetzt. */
        // Die Kachel MUSS so breit sein wie die Verschiebung – sonst liegt eine harte Kante
        // mitten in der Zahl (genau so gesehen).
        st_expect((bool)preg_match('~\.wl-vor-zahl\s*\{(?:[^{}]|\{[^{}]*\})*background-size:\s*10em~s', $qCssVor),
            'wl.css: die Kachel des Countdown-Verlaufs ist nicht mehr 10em breit – dann schneidet die Kachelgrenze durch die Zahl');
        // Mit dem Muster für VERSCHACHTELTE Blöcke: Ein schlichtes [^}]* endet schon am ersten
        // „}" – also am Ende des from-Blocks – und findet das to- nie.
        st_expect(preg_match('~@keyframes wl-vor-fluss\s*\{((?:[^{}]|\{[^{}]*\})*)\}~s', $qCssVor, $mFl) === 1
                && (bool)preg_match('~to\s*\{\s*background-position:\s*10em~', $mFl[1]),
            'wl.css: der Countdown-Verlauf wird nicht mehr um genau eine Periode (10em) geschoben – das zuckt sichtbar');
        // Schein und Funken ragen absichtlich über den Block hinaus – ohne das Klippen wächst die
        // Seite in die Breite und liess sich am Handy seitwärts schieben (genau so passiert).
        st_expect((bool)preg_match('~\.wl-vor\s*\{(?:[^{}]|\{[^{}]*\})*overflow-x:\s*clip~s', $qCssVor),
            'wl.css: der Countdown-Block klippt nicht mehr waagerecht – dann lässt sich die Seite am Handy seitwärts schieben');
        foreach (['.wl-vor-zahl', '@keyframes wl-vor-fluss', '@keyframes wl-vor-funke'] as $vSel) {
            st_expect(str_contains($qCssVor, $vSel), 'wl.css fehlt „' . $vSel . '" – der Countdown steht dann still oder ungestaltet da');
        }
        st_expect((bool)preg_match('~prefers-reduced-motion[^{]*\{[^}]*wl-vor~s', $qCssVor),
            'wl.css: der Countdown läuft auch bei „Ruhe bewahren" weiter');
        st_expect(str_contains($qVerw2, 'name="launch_at"') && str_contains($qVerw2, "wl_setting_set('launch_at'")
                && str_contains($qVerw2, 'name="launch_time"'),
            'veranstaltungen.php: Feld für Starttermin oder Uhrzeit fehlt oder wird nicht gespeichert');
        // Datum und Uhrzeit über flatpickr, nicht über die Browser-Auswahl: Die sieht auf jedem
        // Gerät anders aus und passt nirgends zur App.
        st_expect(str_contains($qVerw2, 'class="fp-date" name="launch_at"')
                && str_contains($qVerw2, 'class="fp-time" name="launch_time"'),
            'veranstaltungen.php: Starttermin/Uhrzeit hängen wieder an der nackten Browser-Auswahl statt an flatpickr');
        /* Der öffentliche Teil setzt seine Zeitzone SELBST: Er bindet lib.php nicht ein und lief
           damit in der Server-Vorgabe. Ohne das liegt „heute" im Sommer zwei Stunden daneben –
           der Countdown zählt falsch, und Listen kippen um zwei Uhr nachts auf den Folgetag. */
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../wl-db.php'), "date_default_timezone_set('Europe/Berlin')"),
            'wl-db.php: der öffentliche Teil setzt seine Zeitzone nicht mehr – dort gilt dann die Server-Vorgabe (oft UTC)');
        foreach (['vorab_titel', 'vorab_text', 'vorab_start', 'vorab_heute', 'vorab_morgen', 'vorab_tage'] as $vK) {
            st_expect(wl_t($vK) !== $vK && trim(wl_t($vK)) !== '',
                'wl-db.php: der Vorab-Seite fehlt der Text „' . $vK . '"');
        }
        // Impressum und Datenschutz sind Pflicht, auch wenn die Seite gerade nichts zeigt – sie
        // ist trotzdem oeffentlich erreichbar. Verlinkt wird nur, was eingetragen ist.
        st_expect(str_contains($qZuFn, "wl_recht_url('impressum')") && str_contains($qZuFn, "wl_recht_url('datenschutz')")
                && str_contains($qZuFn, "wl_t('f_impressum')") && str_contains($qZuFn, "wl_t('f_datenschutz')"),
            'wl-lib.php: der Vorab-/Geschlossen-Seite fehlen Impressum und Datenschutz – die Seite ist trotzdem öffentlich');
        /* ---- Eigene Rechtsseiten -----------------------------------------------------------
           Zwei Wege für dieselbe Angabe: eigener Text (dann die eigene Seite) oder die Adresse
           aus den Einstellungen. wl_recht_url() ist die EINE Stelle, die das entscheidet –
           liefe die Fußzeile daran vorbei, zeigte sie irgendwann woanders hin als die
           Vorab-Seite. Und die Seiten dürfen KEINEN Wächter haben: Pflichtangaben müssen auch
           erreichbar sein, wenn das Portal geschlossen ist. */
        foreach (['impressum.php' => 'recht_impressum', 'datenschutz.php' => 'recht_datenschutz'] as $rSeite => $rFeld) {
            $qR = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/' . $rSeite);
            st_expect($qR !== '', 'veranstaltungen/' . $rSeite . ' fehlt');
            // Kommentare raus: Der Dateikopf erklärt, dass dort ABSICHTLICH kein Wächter steht –
            // und erfüllte die Suche danach prompt selbst.
            $qR = (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $qR);
            st_expect(!str_contains($qR, 'wl_offline_guard'),
                'veranstaltungen/' . $rSeite . ': hat einen Wächter – dann sind die Pflichtangaben bei geschlossenem Portal weg');
            st_expect(str_contains($qR, "wl_text('" . $rFeld . "')"),
                'veranstaltungen/' . $rSeite . ': liest den Text nicht mehr aus dem Register');
            st_expect(isset(wl_text_fields()[$rFeld]) && (wl_text_fields()[$rFeld]['group'] ?? '') === 'recht',
                'wl-db.php: „' . $rFeld . '" fehlt in der Register-Gruppe „recht"');
        }
        st_expect(isset(wl_text_groups()['recht']),
            'wl-db.php: die Gruppe „Rechtliches" fehlt – dann sind die Texte in der Verwaltung unsichtbar');
        st_expect(str_contains($qWlLib, 'function wl_recht_url(') && str_contains($qWlLib, "wl_recht_url('impressum')"),
            'wl-lib.php: die Fußzeile geht an wl_recht_url() vorbei – eigene Seite und Einstellung laufen dann auseinander');
        // Zeilenumbrüche in den Standardtexten: In einfachen Anführungszeichen wäre \n zweimal
        // ein Zeichen und kein Absatz – die Seite wäre eine einzige Textwurst (genau so passiert).
        foreach (['recht_impressum', 'recht_datenschutz'] as $rF) {
            $rTxt = (string)(wl_text_fields()[$rF]['default'] ?? '');
            st_expect(!str_contains($rTxt, '\\n') && substr_count($rTxt, "\n") > 5,
                'wl-db.php: der Standardtext „' . $rF . '" hat keine echten Absätze');
        }
        st_expect(str_contains($qZuFn, "if (\$imp !== '' || \$dat !== '')"),
            'wl-lib.php: die Rechtszeile steht auch ohne eingetragene Adressen da – dann fuehrt sie ins Leere');
        st_expect(str_contains($qZuFn, 'http_response_code(503)'),
            'wl-lib.php: die geschlossene Seite meldet keine 503 mehr – Suchmaschinen halten die Pause dann für eine Löschung');
        // Kopfleiste und Fußzeile gehören dort bewusst NICHT hin: Ihre Verweise führen alle auf
        // Seiten, die derselbe Wächter ebenfalls abweist.
        st_expect($qZuFn !== '' && !preg_match('~wl_nav\(|wl_foot\(~', $qZuFn),
            'wl-lib.php: die Geschlossen-Seite hat wieder eine Navigation – dort führt jeder Weg hierher zurück');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../assets/wl.css'), '.wl-zu-in'),
            'wl.css: der Geschlossen-Seite fehlen ihre Regeln');
        foreach (['zu_titel', 'zu_text', 'zu_kontakt'] as $zuK) {
            st_expect(wl_t($zuK) !== $zuK && trim(wl_t($zuK)) !== '',
                'wl-db.php: der Geschlossen-Seite fehlt der Text „' . $zuK . '"');
        }
        st_expect(str_contains($qWlLib, 'function wl_demo_note('),
            'wl-lib.php: der gemeinsame Baustein für den Demo-Hinweis fehlt');
        // Ein leeres Feld muss den Standardsatz zeigen, nicht nichts: Sonst stünde der Schalter
        // auf an und draußen wäre kein Hinweis – und niemand sähe, dass etwas fehlt.
        st_expect((bool)preg_match("~demo_text.*?!==\s*''\s*\?.*?wl_t\('demo_text'\)~s", $qWlLib),
            'wl-lib.php: ohne eigenen Wortlaut fällt der Demo-Hinweis nicht mehr auf den Standardsatz zurück');
        foreach (['index.php' => 'Startseite', 'v.php' => 'Veranstaltungsseite'] as $wlSeite => $wlWas) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/' . $wlSeite), 'wl_demo_note('),
                'veranstaltungen/' . $wlSeite . ': die ' . $wlWas . ' zeigt den Demo-Hinweis nicht mehr an');
        }
        // Der Hinweis ist der einzige Satz, den Besuchende im Demo-Modus lesen – er muss in beiden
        // Sprachen dastehen, sonst steht auf der englischen Seite der Schlüssel selbst.
        foreach (['demo', 'demo_text'] as $wlK) {
            st_expect(wl_t($wlK) !== $wlK && trim(wl_t($wlK)) !== '',
                'wl-db.php: dem Demo-Hinweis fehlt der Text „' . $wlK . '"');
        }

        // ---- Doppel-Submit-Schutz (doppelt angelegter Beitrag) -------------
        // EIN gemeinsames Skript für App und ALLE öffentlichen Bereiche: sperrt die Knöpfe
        // nach dem ersten Abschicken (erst NACH der Serialisierung – sonst fiele der geklickte
        // Knopf aus den Daten), respektiert preventDefault der Dialoge, löst sich bei
        // bfcache-Rückkehr. Dazu das Server-Netz gegen Zwillinge beim Einreichen.
        $qDk = (string)@file_get_contents(__DIR__ . '/../assets/doppelklick.js');
        st_expect(str_contains($qDk, 'defaultPrevented') && str_contains($qDk, 'setTimeout')
                && str_contains($qDk, 'pageshow') && str_contains($qDk, 'data-dk-gesperrt'),
            'assets/doppelklick.js fehlt oder hat seine Schutz-Mechanik verloren');
        foreach (['lib.php', 'veranstaltungen/wl-lib.php', 'termin/termin-lib.php',
                  'anmeldung/anmeldung-lib.php', 'pat/pat-lib.php', 'umfrage/umfrage-lib.php'] as $st_dkDatei) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../' . $st_dkDatei), 'doppelklick.js'),
                $st_dkDatei . ': der Doppel-Submit-Schutz ist nicht mehr eingebunden');
        }
        st_expect(str_contains($qEinr2, "'-2 minutes'"),
            'einreichen.php: das Server-Netz gegen doppelt angelegte Beiträge (Zwillings-Fänger) fehlt');
        // ---- Erinnerungs-Zeitpunkt + Groß-Bilder --------------------------
        // „Erinnere mich" sagt beim Einschalten, WANN es klingelt, und die Detailseite nimmt
        // Groß-Fassungen (-g.jpg) der Standard-Fotos, wo die Quelle genug Auflösung hergab.
        // Ein Desktop-Kalender-MENÜ darf es nicht geben – der Kalender-Knopf ist und bleibt
        // der direkte ics-Link.
        $qV3 = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/v.php');
        st_expect(!str_contains($qV3, 'wl-kal-dialog') && !str_contains($qV3, 'calendar.google.com'),
            'v.php: das Desktop-Kalender-Menü ist zurück – das hat der User ausdrücklich NICHT gewollt');
        st_expect(str_contains($qV3, 'wl_standard_url($std, true)'),
            'v.php: die Detailseite nimmt nicht mehr die Groß-Fassung der Standard-Fotos');
        // Die Rückmeldung ist eine SPRECHBLASE am Knopf, die von selbst geht – ein Dialog,
        // den man wegklicken muss, war hier ein Festlegung.
        $qPushJs2 = (string)@file_get_contents(__DIR__ . '/../assets/wl-push.js');
        st_expect(str_contains($qPushJs2, 'function wann(') && str_contains($qPushJs2, 'function blase(')
                && (bool)preg_match('~blase\(btn,\s*.Wir erinnern dich~u', $qPushJs2),
            'wl-push.js: die Sprechblase „wann wird erinnert" am Knopf fehlt');
        st_expect(str_contains($cssRein, '.wl-push-pop') && str_contains($cssRein, 'wl-push-pop-auf'),
            'wl.css: die Erinnerungs-Sprechblase (.wl-push-pop) fehlt');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../veranstaltungen/wl-lib.php'), "'-g.jpg'"),
            'wl_standard_url: die Groß-Fassungs-Weiche (-g.jpg) fehlt');
        // Direkt-Installieren in der Hinweis-Zeile („wie bei der AStA-App"):
        // Android & Co. bekommen den nativen Installieren-Dialog mit EINEM Tipp, das iPhone
        // behält „So geht's" (dort gibt es kein beforeinstallprompt).
        $qIdx2 = (string)@file_get_contents(__DIR__ . '/../veranstaltungen/index.php');
        st_expect(str_contains($qIdx2, 'wl-hinweis-install') && str_contains($qIdx2, 'beforeinstallprompt')
                && str_contains($qIdx2, 'appinstalled'),
            'index.php: der Direkt-Installieren-Knopf in der App-Hinweis-Zeile fehlt');

        // Rechte liegen in der lib.php, nicht im Modul – wie bei den externen Events.
        st_expect(function_exists('wl_can_manage') && !str_contains($dbSrc, 'function wl_can_manage'),
            'wl_can_manage() gehört in die lib.php, nicht in wl-db.php');
        // Verwaiste Bilder im Blick behalten
        $waisen = wl_image_waisen();
        st_expect((int)$waisen['eintraege_ohne_datei'] === 0,
            (int)$waisen['eintraege_ohne_datei'] . ' Bild-Einträge ohne Datei – die Kacheln bleiben dort leer');

        // Testdatensatz: Die Markierung muss auf ALLEN vier Tabellen liegen, sonst bliebe beim
        // Entfernen etwas zurück – und zwar öffentlich sichtbar.
        st_expect(function_exists('wl_demo_seed') && function_exists('wl_demo_purge')
            && function_exists('wl_demo_counts'), 'die Testdaten-Funktionen fehlen');
        foreach (['orgs', 'items', 'images', 'banners'] as $tab) {
            $sp = array_column(wl_db()->query('PRAGMA table_info(' . $tab . ')')->fetchAll(), 'name');
            st_expect(in_array('demo', $sp, true), 'Tabelle ' . $tab . ' hat keine demo-Spalte – Testdaten ließen sich dort nicht wieder entfernen');
        }
        // Hier wird BEWUSST weder angelegt noch entfernt: Der Selbsttest läuft auch mal nebenbei,
        // und er darf weder etwas öffentlich stellen noch einen Datensatz wegräumen, mit dem
        // gerade jemand arbeitet.
        $demo = wl_demo_counts();

        return wl_count('live') . ' öffentlich · ' . wl_count('pending') . ' warten · Bilder: ' . wl_image_engine()
            . (array_sum($demo) > 0 ? ' · ACHTUNG: ' . (int)$demo['items'] . ' Testbeiträge liegen öffentlich' : '');
    });

    $run('Externe Events: Rechte und Grundfunktionen', function () {
        require_once __DIR__ . '/../extern-db.php';
        extern_db();                       // legt das Schema an, falls noch nicht vorhanden
        extern_events_all(); extern_events_all([]); extern_event_get(0); extern_event_by_slug('gibtsnicht');

        // Rechte: drei Stufen, die sich ergänzen. Vorsitz/Admin sehen alles – der Selbsttest
        // läuft in dieser Rolle, deshalb wird die Nicht-Admin-Regel über die Liste geprüft.
        st_expect(extern_can_manage(null) === true, 'Vorsitz/Admin müssen externe Events betreuen dürfen');
        st_expect(extern_can_create() === true, 'Vorsitz/Admin müssen externe Events anlegen dürfen');
        $vorher = extern_referate();
        st_expect(is_array($vorher), 'Die Liste global freigeschalteter Referate muss lesbar sein');
        // Unbekannte Referate dürfen nicht in der Liste landen – sonst hätte ein Tippfehler
        // stillschweigend gar keine Wirkung, statt aufzufallen.
        extern_referate_set(array_merge($vorher, ['Gibtsnicht-Referat']));
        st_expect(!in_array('Gibtsnicht-Referat', extern_referate(), true), 'Unbekannte Referate müssen abgewiesen werden');
        extern_referate_set($vorher);      // Zustand unverändert zurücklassen
        st_expect(extern_referate() === $vorher, 'Der Selbsttest darf die Referats-Liste nicht verändern');

        // Ausbau (gleiche Kur wie bei den Umfragen):
        // Reiter, Link-Sicherung, gemeinsamer Grafik-Bausatz, hübsche Anmeldeseite.
        $qExt  = (string)@file_get_contents(__DIR__ . '/../extern.php');
        $qExtE = (string)@file_get_contents(__DIR__ . '/../extern-event.php');
        $qExtT = (string)@file_get_contents(__DIR__ . '/../extern-teilnahme.php');
        st_expect(str_contains($qExt, 'wl-adm-tabs') && str_contains($qExt, "extern.php?t=einstellungen"),
            'extern.php: die Reiter-Kategorien fehlen (oder die redirect-Ziele zeigen nicht mehr auf ?t=…)');
        st_expect(str_contains($qExtE, 'weicht von der App ab') && str_contains($qExtE, "extern_setting_set('base_url', \$appBasis)"),
            'extern-event.php: Selbstheilung/Warnung der Basis-Adresse fehlt – ein kaputter Anmelde-Link fiele niemandem auf');
        st_expect(is_file(__DIR__ . '/../chart-core.php') && function_exists('chart_balken_html')
                && function_exists('chart_verlauf_html') && function_exists('chart_tage_fuellen'),
            'chart-core.php fehlt – Umfragen UND externe Events verlieren ihre Grafiken');
        st_expect(str_contains($qExtT, 'chart_verlauf_html') && str_contains($qExtT, 'chart_balken_html'),
            'extern-teilnahme.php: die „Auf einen Blick"-Grafiken fehlen');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../anmeldung/index.php'), 'an-hero')
                && str_contains((string)@file_get_contents(__DIR__ . '/../assets/anmeldung.css'), '.an-hero'),
            'Anmeldeseite: der Bühnen-Kopf (an-hero) ist zurückgebaut');

        // Register vollständig? Ohne die Feldarten gäbe es kein Formular.
        st_expect(count(extern_field_types()) >= 6 && isset(extern_field_types()['single'], extern_field_types()['multi']), 'Feldarten fehlen');
        st_expect(extern_field_has_options('single') && extern_field_has_options('multi') && !extern_field_has_options('text'),
            'Nur Auswahlfelder dürfen Antwortmöglichkeiten haben');
        st_expect(isset(extern_group_modes()['none'], extern_group_modes()['fixed'], extern_group_modes()['auto']), 'Gruppen-Modi fehlen');
        st_expect(isset(extern_visibility_modes()['never'], extern_visibility_modes()['signed'], extern_visibility_modes()['public']),
            'Sichtbarkeits-Stufen fehlen');

        // Zeitfenster und Fristen
        st_expect(extern_reg_open(['status' => 'draft', 'reg_from' => '', 'reg_until' => '']) === false, 'Ein Entwurf darf keine Anmeldungen annehmen');
        st_expect(extern_reg_open(['status' => 'open', 'reg_from' => '', 'reg_until' => date('Y-m-d H:i:s', time() - 60)]) === false,
            'Nach Anmeldeschluss darf nichts mehr angenommen werden');
        st_expect(extern_reg_open(['status' => 'open', 'reg_from' => '', 'reg_until' => '']) === true, 'Ohne Frist muss die Anmeldung offen sein');
        st_expect(extern_cancel_open(['cancel_until' => date('Y-m-d H:i:s', time() - 60), 'starts_at' => '']) === false, 'Abmeldefrist wird nicht beachtet');

        // Datumsform aus dem Wähler
        st_expect(extern_dt_norm('2026-10-01 18:00') === '2026-10-01 18:00:00' && extern_dt_norm('2026-10-01') === '2026-10-01 00:00:00'
            && extern_dt_norm('quatsch') === '' && extern_dt_norm('') === '', 'Datums-Normalisierung stimmt nicht');
        st_expect(extern_slugify('Kneipentour für Erstis!') === 'kneipentour-fuer-erstis', 'Link-Schlüssel wird falsch gebaut');

        // Schema: die Tabellen, auf denen die späteren Schritte aufbauen
        $spalten = fn(string $t) => array_map(fn($c) => (string)$c['name'], extern_db()->query('PRAGMA table_info(' . $t . ')')->fetchAll());
        foreach (['events' => ['slug', 'referat', 'status', 'capacity', 'group_mode', 'show_groups', 'keep_days',
                                'rot_rounds', 'rot_start', 'rot_minutes'],
                  'stations' => ['event_id', 'name', 'sort'], 'rotation' => ['event_id', 'round_no', 'group_id', 'station_id'],
                  'fields' => ['event_id', 'type', 'label'], 'field_opts' => ['field_id', 'label', 'capacity'],
                  'groups' => ['event_id', 'name', 'capacity', 'leader_id'],
                  'signups' => ['event_id', 'name', 'email', 'status', 'party_size', 'code', 'group_id'],
                  'answers' => ['signup_id', 'field_id'], 'mailqueue' => ['status', 'kind'], 'mail_log' => ['sent_at']] as $t => $noetig) {
            $da = $spalten($t);
            foreach ($noetig as $c) st_expect(in_array($c, $da, true), 'Externe Events: Spalte ' . $t . '.' . $c . ' fehlt');
        }
        // Die Anmeldedaten dürfen nicht ewig liegen bleiben – der Wert steht je Veranstaltung.
        st_expect(EXTERN_KEEP_DAYS >= 7, 'Aufbewahrungsfrist muss gesetzt sein');

        // Plätze zählen PERSONEN: Eine Sammelanmeldung zu viert belegt vier – sonst wäre eine
        // Veranstaltung mit 120 Plätzen bei 120 Anmeldungen voll, obwohl 300 Leute kommen.
        $cSrc = (string)@file_get_contents(__DIR__ . '/../extern-db.php');
        st_expect(str_contains($cSrc, "SUM(party_size), 0) FROM signups WHERE event_id = ? AND status IN ('confirmed','pending')"),
            'Freie Plätze müssen Personen zählen und auch unbestätigte Anmeldungen mitrechnen');
        st_expect(str_contains($cSrc, "'belegt'"), 'Die Platzrechnung („belegt") fehlt');
        // Codes ohne Verwechslungszeichen – sie werden vorgelesen und abgetippt
        $code = extern_code_new();
        st_expect(strlen($code) === 6 && preg_match('~^[A-HJ-NP-Z2-9]+$~', $code) === 1,
            'Der Freundeskreis-Code darf keine verwechselbaren Zeichen (O/0, I/1) enthalten');
        // Selbstbedienungs-Token: nur der Hash liegt in der Datenbank
        st_expect(str_contains($cSrc, "hash('sha256', \$token)") && !str_contains($cSrc, 'token TEXT NOT NULL'),
            'Vom Selbstbedienungs-Link darf nur der Hash gespeichert werden');
        st_expect(extern_signup_by_token('unsinn') === null && extern_signup_by_token('') === null, 'Ungültige Links müssen abgelehnt werden');
        st_expect(extern_mail_budget() >= 0, 'Das Mail-Kontingent der externen Events rechnet falsch');
        // Der Abschnitt im Events-Tab hing einmal INNERHALB von „if ($upcoming)" – in der
        // vorlesungsfreien Zeit war er damit unsichtbar, obwohl alles eingerichtet war.
        $ev = (string)@file_get_contents(__DIR__ . '/../events.php');
        $posExtern = strpos($ev, 'id="externe"');
        $posUpcoming = strpos($ev, '<?php if ($upcoming): ?>');
        st_expect($posExtern !== false, 'Im Events-Tab fehlt der Abschnitt „Externe Events" ganz');
        st_expect($posExtern !== false && $posUpcoming !== false && $posExtern < $posUpcoming,
            'Der Abschnitt „Externe Events" steckt wieder in „if ($upcoming)" – ohne anstehende Events ist er dann unsichtbar');
        // „Alle Gruppen öffentlich" darf nichts versprechen, was es nicht gibt.
        st_expect(is_file(__DIR__ . '/../anmeldung/gruppen.php'), 'Die Einstellung „öffentliche Gruppen" hat keine Seite (anmeldung/gruppen.php fehlt)');
        // Ohne Termin zählt fürs Aufräumen das Anlegedatum – sonst bliebe so eine Anmeldung ewig liegen.
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../extern-db.php'), "\$e['created_at']"),
            'extern_prune() muss Veranstaltungen ohne Termin über das Anlegedatum aufräumen');
        extern_prune();                    // muss ohne Daten durchlaufen

        // ---- Einteilung: das Verfahren an einem gestellten Fall nachrechnen ----
        // Nur lesend geht das nicht, deshalb wird eine eigene Wegwerf-Veranstaltung angelegt
        // und am Ende restlos gelöscht. Die echten Daten bleiben unberührt.
        $probeId = extern_event_save(0, ['title' => '__Selbsttest__'], 0, 'basis')['id'];
        if ($probeId > 0) {
            extern_event_save($probeId, ['capacity' => 0, 'confirm_mail' => 0], 0, 'anmeldung');
            extern_event_save($probeId, ['group_mode' => 'fixed', 'allow_code' => 1, 'allow_party' => 1, 'party_max' => 5], 0, 'gruppen');
            extern_group_save($probeId, 0, ['name' => 'A', 'capacity' => 4]);
            extern_group_save($probeId, 0, ['name' => 'B', 'capacity' => 4]);
            extern_event_status($probeId, 'open');
            $pe = extern_event_get($probeId);
            $r1 = extern_signup_add($pe, ['name' => 'Block', 'email' => 'block@example.invalid', 'party_size' => 3]);
            $code = extern_signup_get((int)$r1['signup_id'])['code'];
            extern_signup_add($pe, ['name' => 'Einzel 1', 'email' => 'e1@example.invalid']);
            extern_signup_add($pe, ['name' => 'Einzel 2', 'email' => 'e2@example.invalid', 'code' => $code]);
            // Der Code-Partner gehört zum Block: 3 + 1 = 4 Personen, unteilbar.
            $bl = extern_blocks($probeId);
            $groessen = array_map(fn($b) => $b['size'], $bl);
            rsort($groessen);
            st_expect($groessen === [4, 1], 'Blockbildung falsch: Code und Sammelanmeldung müssen zusammen einen Block ergeben');

            $res = extern_assign_auto($probeId);
            st_expect((int)$res['verteilt'] === 5 && (int)$res['offen'] === 0, 'Einteilung hat nicht alle untergebracht');
            $last = extern_group_load($probeId);
            $zusammen = [];
            foreach (extern_signups_of($probeId, 'confirmed') as $s) if ((string)$s['code'] === $code) $zusammen[] = (int)$s['group_id'];
            st_expect(count(array_unique($zusammen)) === 1, 'Ein Freundeskreis (gleicher Code) darf NIE auseinandergerissen werden');
            // Ein Block, der nirgends passt, muss gemeldet werden statt still zu verschwinden
            $r3 = extern_signup_add(extern_event_get($probeId), ['name' => 'Zu groß', 'email' => 'gross@example.invalid', 'party_size' => 5]);
            $res2 = extern_assign_auto($probeId);
            st_expect((int)$res2['offen'] === 5 && !empty($res2['probleme']), 'Ein zu großer Block muss gemeldet werden, nicht zerrissen');
            // Nachrichten: Auswahl, Platzhalter und der frische Link
            st_expect(count(extern_recipients($probeId, 'confirmed')) === 4, 'Die Empfänger-Auswahl „alle Angemeldeten" stimmt nicht');
            st_expect(extern_recipients($probeId, 'gibtsnicht') === [], 'Unbekannte Empfänger-Auswahl muss leer bleiben');
            $einer = extern_signups_of($probeId, 'confirmed')[0];
            $altHash = (string)$einer['token_hash'];
            $neuToken = extern_token_reset((int)$einer['id']);
            st_expect(hash('sha256', $neuToken) !== $altHash
                && (string)extern_signup_get((int)$einer['id'])['token_hash'] === hash('sha256', $neuToken),
                'Der frische Selbstbedienungs-Link wird nicht richtig ausgestellt');
            st_expect(extern_signup_by_token($neuToken) !== null, 'Der frische Link muss die Anmeldung finden');
            $gv = extern_group_vars(extern_signup_get((int)$einer['id']));
            st_expect(isset($gv['{{GRUPPE}}'], $gv['{{GRUPPE_SATZ}}']) && $gv['{{GRUPPE}}'] !== '', 'Gruppen-Platzhalter werden nicht gefüllt');
            // Leerer Betreff oder Text darf nichts verschicken
            st_expect(extern_send_bulk(extern_event_get($probeId), [$einer], '', 'x')['ok'] === false, 'Ohne Betreff darf nichts rausgehen');
            st_expect(extern_send_bulk(extern_event_get($probeId), [], 'Betreff', 'Text')['ok'] === false, 'Ohne Empfänger darf nichts rausgehen');


            // ---- Rotationsplan: jede Gruppe an jeder Station, nie zwei zur selben Zeit ----
            extern_station_save($probeId, 0, ['name' => 'S1']);
            extern_station_save($probeId, 0, ['name' => 'S2']);
            $stn = extern_stations_of($probeId);
            st_expect(count($stn) === 2, 'Stationen werden nicht gespeichert');
            // Ohne „stations" in der Weißliste von extern_reorder tut das Verschieben stillschweigend nichts.
            extern_station_move((int)$stn[1]['id'], -1);
            $reihe = array_map(fn($x) => (string)$x['name'], extern_stations_of($probeId));
            st_expect($reihe === ['S2', 'S1'], 'Stationen lassen sich nicht umsortieren – fehlt „stations" in der Weißliste von extern_reorder?');

            extern_event_save($probeId, ['rot_rounds' => 2, 'rot_start' => date('Y-m-d') . ' 19:00', 'rot_minutes' => 45], 0, 'gruppen');
            $bau = extern_rotation_build($probeId);
            st_expect($bau['ok'] === true && $bau['hinweise'] === [], 'Bei 2 Gruppen an 2 Stationen muss der Plan glatt aufgehen');
            $plan = extern_rotation_of($probeId);           // [runde => [ [gruppe, station, zeit], … ]]
            st_expect(count($plan) === 2 && count($plan[1]) === 2, 'Der Rotationsplan muss 2 Runden mit je 2 Gruppen haben');
            $proGruppe = [];
            foreach ($plan as $runde => $zeilen) {
                $orte = array_map(fn($z) => (int)$z['station']['id'], $zeilen);
                st_expect(count(array_unique($orte)) === count($orte),
                    'Runde ' . (int)$runde . ': zwei Gruppen am selben Ort – der Plan ist kaputt');
                foreach ($zeilen as $z) $proGruppe[(int)$z['gruppe']['id']][] = (int)$z['station']['id'];
            }
            foreach ($proGruppe as $stationen)
                st_expect(count(array_unique($stationen)) === count($stationen), 'Eine Gruppe darf dieselbe Station nicht zweimal bekommen');
            $eineGruppe = (int)array_key_first($proGruppe);
            st_expect(count(extern_rotation_for_group($eineGruppe)) === 2, 'Der persönliche Fahrplan einer Gruppe fehlt');
            st_expect(str_contains(extern_rotation_text($eineGruppe), 'S1'), 'Der Platzhalter {{ROTATION}} bleibt leer');
            // Engpass: mehr Runden als Stationen kann nicht aufgehen und MUSS gemeldet werden
            extern_event_save($probeId, ['rot_rounds' => 5, 'rot_start' => date('Y-m-d') . ' 19:00', 'rot_minutes' => 45], 0, 'gruppen');
            st_expect(!empty(extern_rotation_build($probeId)['hinweise']), 'Mehr Runden als Stationen muss einen Hinweis geben');
            extern_rotation_clear($probeId);
            st_expect(extern_rotation_of($probeId) === [], 'Der Rotationsplan lässt sich nicht zurücksetzen');
            st_expect(extern_rotation_on(['id' => $probeId, 'rot_rounds' => 0]) === false, '0 Runden bedeutet: Rotationsplan aus');

            extern_event_delete($probeId);
            st_expect(extern_event_get($probeId) === null, 'Die Wegwerf-Veranstaltung des Selbsttests wurde nicht wieder entfernt');
            st_expect((int)extern_db()->query('SELECT COUNT(*) FROM stations WHERE event_id = ' . (int)$probeId)->fetchColumn() === 0,
                'Mit der Veranstaltung müssen auch ihre Stationen verschwinden');
            st_expect((int)extern_db()->query('SELECT COUNT(*) FROM mailqueue')->fetchColumn() >= 0, 'Mail-Warteschlange nicht lesbar');
            st_expect(extern_mail_cap_hour() !== 0
                || extern_mail_budget() === mail_pool_budget('extern', extern_mail_cap_day()),
                'Ohne Stundenbremse darf sie das Kontingent der externen Events nicht kürzen');
        }
    });
    $run('Mail-Konto: ein Topf für alle Bereiche', function () {
        // Der Topf muss ohne lib.php auskommen – der öffentliche Bereich bindet sie nie ein.
        $src = (string)@file_get_contents(__DIR__ . '/../mail-pool.php');
        st_expect($src !== '', 'mail-pool.php fehlt');
        st_expect(!str_contains($src, "/lib.php"), 'mail-pool.php darf die lib.php NICHT einbinden – sonst bricht der öffentliche Bereich');
        foreach (['pat-db.php', 'umfrage-db.php', 'extern-db.php', 'termin-db.php'] as $modul) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../' . $modul), "require_once __DIR__ . '/mail-pool.php'"),
                $modul . ' bindet das gemeinsame Mail-Konto nicht ein – dann rechnet es wieder mit einer eigenen Scheibe');
        }
        // Jede tatsächlich verschickte Mail muss eingetragen werden, sonst stimmt der Kontostand nicht.
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../lib.php'), 'mail_pool_note($quelle)'),
            'send_mail() trägt nicht ins gemeinsame Mail-Konto ein');
        foreach (['umfrage' => 'umfrage-db.php', 'extern' => 'extern-db.php', 'termin' => 'termin-db.php'] as $q => $modul) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../' . $modul), "mail_pool_note('" . $q . "')"),
                $modul . ' trägt seinen Versand nicht ins gemeinsame Konto ein');
        }
        // Bewusst auf die QUELLE geprüft, nicht auf eine exakte Argumentfolge: Als send_mail()
        // den CC-Parameter für die Gruppen-Mail bekam, schlug die alte Prüfung an, obwohl das
        // Buchen völlig in Ordnung war. Was zählt: In beiden Versandwegen steht 'pat'.
        foreach (['cron_patmail.php', 'admin/paten-programm.php'] as $patDatei) {
            st_expect((bool)preg_match("~send_mail\([^;]*'pat'~s", (string)@file_get_contents(__DIR__ . '/../' . $patDatei)),
                $patDatei . ': Die Mails des Pat:innenprogramms werden nicht auf ihren eigenen Bereich gebucht');
        }

        // EINE Absenderadresse für die ganze App. Beim Hoster ist genau ein Postfach zum Versenden
        // freigegeben; trägt ein Bereich eine andere Adresse in die From-Zeile, behauptet die Mail
        // nur etwas anderes – und büßt dafür Zustellbarkeit ein (SPF/DMARC).
        st_expect(function_exists('mail_pool_absender'), 'mail_pool_absender() fehlt – die Bereiche hätten wieder je eigene Absender');
        // Ohne eingestellte Adresse rät mail_from() aus dem Hostnamen – das ist keine echte
        // Adresse und wird bewusst NICHT gespiegelt. Dann fehlt aber die Einstellung, und genau
        // das ist zu melden (sonst klagt der Selbsttest über eine Spiegelung, die gar nicht darf).
        if (filter_var(mail_from(), FILTER_VALIDATE_EMAIL)) {
            st_expect(mail_pool_absender() === mail_from(),
                'der gespiegelte Absender (' . (mail_pool_absender() ?: '–') . ') weicht von der App-Adresse ('
                . mail_from() . ') ab – die öffentlichen Bereiche verschicken dann unter falschem Namen');
        } else {
            st_expect(false, 'es ist keine Absenderadresse eingestellt – die App verschickt sonst als „'
                . mail_from() . '", und Umfragen, externe Events und Terminplaner benutzen weiter ihre eigenen Adressen');
        }
        foreach (['umfrage-db.php', 'extern-db.php', 'termin-db.php'] as $modul) {
            $ms = (string)@file_get_contents(__DIR__ . '/../' . $modul);
            st_expect((bool)preg_match('~\$from\s*=\s*mail_pool_absender\(\)~', $ms),
                $modul . ' baut die From-Zeile wieder aus einer eigenen Adresse statt aus mail_pool_absender()');
        }
        // send_mail() darf sich den Absender nicht mehr von außen vorgeben lassen.
        st_expect((bool)preg_match('~function send_mail\(.*?\n\}~s', (string)@file_get_contents(__DIR__ . '/../lib.php'), $sm)
            && (bool)preg_match('~\$from\s*=\s*mail_from\(\);~', $sm[0] ?? ''),
            'send_mail() setzt den Absender nicht mehr fest auf mail_from()');
        // Und das Pat:innenprogramm darf bei gleicher Domain nicht mehr umschalten.
        st_expect(!str_contains((string)@file_get_contents(__DIR__ . '/../pat-db.php'), "\$out['from'] = \$paten"),
            'pat_mail_sender() schaltet den Absender wieder auf die Paten-Adresse um');

        // Die Rechnung selbst: frei = Grenze − Reserve − Verbrauch, nie unter null.
        $limit = mail_pool_limit(); $res = mail_pool_reserve(); $used = mail_pool_used(24);
        st_expect($res < $limit, 'Die Reserve darf nicht die ganze Hoster-Grenze auffressen');
        st_expect(mail_pool_free() === max(0, $limit - $res - $used), 'mail_pool_free() rechnet falsch');
        st_expect(mail_pool_free() <= $limit - $res, 'Rundmails dürfen nie mehr als Grenze minus Reserve bekommen');
        st_expect(mail_pool_day_allow() >= 1, 'Die Tagesmenge für Prognosen darf nicht 0 sein');
        // Ein eigener Deckel darf nur bremsen, nie mehr erlauben als der Topf hergibt.
        st_expect(mail_pool_budget('extern', 5) <= 5, 'Ein eigener Deckel muss greifen');
        st_expect(mail_pool_budget('extern', 0) === mail_pool_free(), 'Deckel 0 muss „kein eigener Deckel" bedeuten');
        st_expect(mail_pool_budget('extern', 100000) === mail_pool_free(), 'Ein hoher Deckel darf den Topf nicht vergrößern');
        // Unbekannte Bereiche landen auf „app", statt still zu verschwinden.
        $vorher = mail_pool_used(24, 'app');
        mail_pool_note('gibtsnicht');
        st_expect(mail_pool_used(24, 'app') === $vorher + 1, 'Ein unbekannter Bereich muss auf „app" gebucht werden');
        mail_pool_db()->exec('DELETE FROM sends WHERE id = (SELECT MAX(id) FROM sends)');   // Probe-Strich wieder weg
        st_expect(mail_pool_used(24, 'app') === $vorher, 'Der Probe-Eintrag des Selbsttests wurde nicht wieder entfernt');

        $b = mail_budget_overview();
        st_expect(count($b['zeilen']) === count(mail_pool_quellen()), 'In der Kontingent-Tabelle fehlt ein Bereich');
        foreach ($b['zeilen'] as $z) st_expect(isset($z['cap']), 'In der Kontingent-Tabelle fehlt der eigene Deckel von „' . $z['quelle'] . '"');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/index.php'), 'mail_pool_setting_set'),
            'Hoster-Grenze und Reserve lassen sich nirgends einstellen');

        /*
         * Jede Seite mit Mail-Zahlen muss die Empfehlung daneben zeigen. Der Grund steht in
         * mail_pool_empfehlung(): Eine einmal gespeicherte Zahl schlägt jede Vorgabe im Code,
         * und beim Sprung von 400 auf 3000 Mails standen überall noch die alten Werte, ohne
         * dass irgendwo ein Hinweis darauf war.
         */
        foreach (['index.php' => 'Gemeinsames Mail-Konto', 'umfragen.php' => 'Umfragen',
                  'paten.php' => 'Pat:innenprogramm', 'terminplaner.php' => 'Terminplaner'] as $datei => $wo) {
            st_expect(str_contains((string)@file_get_contents(__DIR__ . '/' . $datei), 'mail_hint('),
                'Bei „' . $wo . '" steht keine empfohlene Einstellung neben den Mail-Feldern');
        }
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../extern.php'), 'mail_hint('),
            'Bei den externen Events steht keine empfohlene Einstellung neben den Mail-Feldern');
        foreach (mail_pool_empfehlung() as $k => $e) {
            st_expect(isset($e['wert'], $e['was']) && trim((string)$e['was']) !== '',
                'Der Empfehlung „' . $k . '" fehlt die Begründung – eine Zahl ohne Warum hilft niemandem');
            st_expect(mail_hint($k, (int)$e['wert']) !== '', 'mail_hint() kennt den Schlüssel „' . $k . '" nicht');
        }
        st_expect(mail_hint('gibtsnicht', 0) === '', 'mail_hint() erfindet Empfehlungen für unbekannte Schlüssel');
        st_expect((int)mail_pool_empfehlung()['limit']['wert'] === 3000,
            'Die empfohlene Hoster-Grenze passt nicht mehr zu dem, was Mittwald freigeschaltet hat');
        mail_pool_prune();
    });
    $run('Terminplaner: Trennung, Stimmzettel, Kapazität, Kalenderdatei', function () {
        require_once __DIR__ . '/../termin-db.php';

        /*
         * Namensraum. termin-db.php ist die EINZIGE Datei des Terminplaners, die zusammen mit
         * der lib.php geladen wird (auf dieser Verwaltungsseite). Zwei gleich heißende
         * Funktionen sind dort kein Schönheitsfehler, sondern ein sofortiger Fatal-Error und
         * eine weiße Seite – genau so sind termin_get(), termin_create() und termin_delete()
         * mit den geteilten Terminen aus der lib.php zusammengestoßen. Seitdem gilt: alles im
         * Terminplaner heißt tplan_…, und diese Prüfung hält das fest.
         */
        preg_match_all('~^\s*function\s+([a-zA-Z_][\w]*)\s*\(~m', (string)@file_get_contents(__DIR__ . '/../termin-db.php'), $tf);
        foreach ($tf[1] as $fn) {
            st_expect(str_starts_with($fn, 'tplan_'),
                'termin-db.php definiert ' . $fn . '() ohne das tplan_-Präfix. Die Datei läuft zusammen mit der '
                . 'lib.php – ein dort schon vergebener Name legt die Verwaltungsseite komplett lahm.');
        }

        // ---- Der öffentliche Bereich steht für sich ----
        foreach (['termin-lib.php', 'index.php', 'neu.php', 't.php', 'verwalten.php', 'ics.php', '_ansicht.php'] as $datei) {
            $src = (string)@file_get_contents(__DIR__ . '/../termin/' . $datei);
            st_expect($src !== '', 'termin/' . $datei . ' fehlt');
            // Die CSP dort kennt kein 'unsafe-inline': ein style="…" wird stillschweigend verworfen.
            // Kommentare erklären genau diese Regel und dürfen den Fund nicht auslösen.
            st_expect(!str_contains((string)preg_replace('~/\*.*?\*/~s', '', $src), 'style="'),
                'termin/' . $datei . ' enthält ein style="…" – das verwirft die CSP wirkungslos');
        }
        // ---- Der Geräte-Cookie: kommt vom Gerät, darf also alles enthalten ----
        // Anlass: Ein falsch geschriebener Typtest ließ die öffentliche Startseite für JEDEN
        // abstürzen, der noch keinen Cookie hatte (mb_substr bekam null). Der leere Fall zuerst.
        foreach ([
            'ohne Cookie'            => null,
            'leeres Objekt'          => [],
            'kaputtes JSON'          => 'Unsinn',
            'falsche Typen'          => ['n' => 42, 'm' => null, 'e' => 'nein', 'p' => 7],
            'Liste statt Objekt'     => [1, 2, 3],
            'nur Verwaltungs-Teil'   => ['p' => ['abc123']],
        ] as $fall => $roh) {
            $d = tplan_dev_norm($roh);
            st_expect(array_keys($d) === ['n', 'm', 'e', 'p'], 'Geräte-Cookie (' . $fall . '): vier Schlüssel erwartet');
            st_expect(is_string($d['n']) && is_string($d['m']), 'Geräte-Cookie (' . $fall . '): Name und Adresse müssen Text sein');
            st_expect(is_array($d['e']) && is_array($d['p']), 'Geräte-Cookie (' . $fall . '): die Schlüssel-Listen müssen Listen sein');
        }
        st_expect(tplan_dev_norm(['n' => str_repeat('a', 500)])['n'] === str_repeat('a', 80), 'Name muss auf 80 Zeichen gekürzt werden');
        st_expect(tplan_dev_norm(['n' => 'Bo', 'p' => ['k1']])['n'] === 'Bo', 'ein gültiger Name muss erhalten bleiben');
        st_expect(tplan_dev_norm(['p' => ['k1']])['p'] === ['k1'], 'gemerkte Schlüssel müssen erhalten bleiben');

        $lib = (string)@file_get_contents(__DIR__ . '/../termin/termin-lib.php');
        st_expect(str_contains($lib, "default-src 'none'") && str_contains($lib, "script-src 'nonce-"),
            'Dem Terminplaner fehlt die strenge Content-Security-Policy');
        st_expect(str_contains($lib, 'tplan_dev_norm('),
            'termin-lib.php prüft den Geräte-Cookie wieder selbst statt über tplan_dev_norm()');
        // Datum und Uhrzeit werden mit DEM Wähler der App gewählt, nicht mit den nackten
        // Browser-Feldern – die sehen überall anders aus und passen zu nichts hier.
        st_expect(str_contains($lib, 'flatpickr.min.js') && str_contains($lib, 'flatpickr.min.css'),
            'Der Terminplaner bindet den Datums-Wähler der App nicht mehr ein');
        foreach (['neu.php', 'verwalten.php'] as $datei) {
            $s = (string)@file_get_contents(__DIR__ . '/../termin/' . $datei);
            st_expect(str_contains($s, 'fp-date') && str_contains($s, 'fp-time'),
                'termin/' . $datei . ' benutzt die nackten Browser-Felder statt des Wählers der App');
            st_expect(str_contains($s, 'tplan_picker_script()'),
                'termin/' . $datei . ' lädt den Wähler nicht – die Felder blieben unbedient');
        }
        st_expect(str_contains($lib, "session_name('asta_termin_sid')"), 'Der Terminplaner benutzt keine eigene Sitzung');
        // Im Fuß jeder Seite muss der Weg für Rückmeldungen stehen – sonst meldet niemand etwas.
        st_expect(str_contains($lib, 'mailto:') && str_contains($lib, 'tplan_feedback_mail()'),
            'Im Fuß des Terminplaners fehlt der Knopf für Rückmeldungen und Fehlermeldungen');
        // Die Adresse der Seite darf NIE in die vorbereitete Mail – auf verwalten.php stünde
        // dort der Verwaltungs-Link, und der gehört niemandem außer der anlegenden Person.
        st_expect(!preg_match('~body=.{0,400}(REQUEST_URI|HTTP_HOST|\$_SERVER)~s', $lib),
            'Die Rückmelde-Mail baut die Seitenadresse ein – damit verschickt man seinen Verwaltungs-Link');

        // ---- Schlüssel, Grenzen, Eingabeprüfung ----
        for ($i = 0; $i < 20; $i++) {
            st_expect(!preg_match('~[01loi]~', tplan_key(12)), 'In den Links dürfen keine verwechselbaren Zeichen stehen');
        }
        $qTpFaq = tplan_faq();
        $qTpFaqOk = count($qTpFaq) >= 3;
        foreach ($qTpFaq as $qTpF) {
            if (trim((string)($qTpF['f'] ?? '')) === '' || mb_strlen(trim((string)($qTpF['a'] ?? ''))) < 40) $qTpFaqOk = false;
        }
        st_expect($qTpFaqOk, 'Eine haeufige Frage des Terminplaners hat keine oder eine zu duenne Antwort');
        $lim = tplan_limits();
        st_expect($lim['options'] >= 2 && $lim['entries'] >= 5 && $lim['keep_days'] >= 7, 'Die Grenzen des Terminplaners sind unbrauchbar klein');
        st_expect(tplan_norm_day('2026-02-30') === '', 'Der 30. Februar muss abgelehnt werden');
        st_expect(tplan_norm_day('2026-08-12') === '2026-08-12', 'Ein gültiges Datum wird abgelehnt');
        st_expect(tplan_norm_time('9:5') === '' && tplan_norm_time('25:00') === '', 'Unsinnige Uhrzeiten müssen abgelehnt werden');
        st_expect(tplan_norm_time('09:30') === '09:30', 'Eine gültige Uhrzeit wird abgelehnt');
        st_expect(tplan_norm_deadline('2026-08-12', '') === '2026-08-12 23:59:59', 'Frist ohne Uhrzeit muss bis Tagesende gelten');
        // Zeilen ohne Datum sind leere Zeilen, kein Fehler; Ende vor Beginn fliegt raus.
        $c = tplan_collect_options(['o_day' => ['', '2026-08-12', 'quatsch'], 'o_from' => ['', '14:00', ''], 'o_to' => ['', '09:00', '']]);
        st_expect($c['ok'] && count($c['liste']) === 1, 'Leere und kaputte Zeilen werden nicht sauber übersprungen');
        st_expect($c['liste'][0]['t_to'] === '', 'Ein Ende vor dem Beginn muss entfernt werden');
        st_expect(!tplan_collect_options(['o_day' => ['']])['ok'], 'Ohne einen einzigen Termin darf nichts entstehen');

        // ---- Durchlauf mit einer Wegwerf-Umfrage ----
        $tag = date('Y-m-d', time() + 86400 * 14);
        // Die Kästchen müssen ausdrücklich mit: Ein nicht angekreuztes Kästchen fehlt im
        // abgeschickten Formular, deshalb bedeutet „Feld nicht da" beim Anlegen AUS. Ohne
        // allow_edit wäre das Ändern unten gesperrt, ohne allow_maybe würde „Vielleicht" zu „Nein".
        $r = tplan_create(['title' => 'Selbsttest (wird gleich gelöscht)', 'place' => 'Testraum',
            'allow_maybe' => 1, 'allow_edit' => 1, 'show_names' => 1, 'ask_comment' => 1,
            'o_day' => [$tag, $tag], 'o_from' => ['10:00', ''], 'o_to' => ['12:00', ''], 'o_cap' => [1, 0]]);
        st_expect($r['ok'], 'Anlegen scheitert: ' . (string)$r['msg']);
        $p = (array)$r['poll'];
        try {
            st_expect(tplan_by_slug((string)$p['slug']) !== null, 'Die Umfrage ist über ihren Teilnahme-Link nicht auffindbar');
            st_expect(tplan_by_admin_key((string)$p['admin_key']) !== null, 'Der Verwaltungs-Link greift nicht');
            st_expect((string)$p['slug'] !== (string)$p['admin_key'], 'Teilnahme- und Verwaltungs-Link dürfen nie gleich sein');
            $opt = tplan_options((int)$p['id']);
            st_expect(count($opt) === 2, 'Die Terminvorschläge wurden nicht gespeichert');
            // Nicht über den Index ansprechen: Am selben Tag steht der ganztägige Vorschlag
            // absichtlich VOR dem mit Uhrzeit, und daran soll dieser Test sich nicht aufhängen.
            $mitZeit = null; $ganztags = null;
            foreach ($opt as $o) { if (trim((string)$o['t_from']) !== '') $mitZeit = $o; else $ganztags = $o; }
            st_expect($mitZeit !== null && $ganztags !== null, 'Der Probe-Umfrage fehlt ein Vorschlag mit oder ohne Uhrzeit');
            st_expect((int)$mitZeit['capacity'] === 1, 'Die Höchstzahl wurde nicht am richtigen Vorschlag gespeichert');

            $v1 = tplan_vote_save($p, ['name' => 'Testperson A', 'v' => [(int)$mitZeit['id'] => 'yes', (int)$ganztags['id'] => 'maybe']]);
            st_expect($v1['ok'], 'Antworten scheitert: ' . (string)$v1['msg']);
            st_expect(!tplan_vote_save($p, ['name' => '', 'v' => []])['ok'], 'Ohne Namen darf keine Antwort entstehen');
            // Höchstzahl 1: die zweite Zusage auf denselben Vorschlag muss abgewiesen werden.
            st_expect(!tplan_vote_save($p, ['name' => 'Testperson B', 'v' => [(int)$mitZeit['id'] => 'yes']])['ok'],
                'Die Höchstzahl je Termin greift nicht – Plätze wären beliebig überbuchbar');
            // Die eigene Zusage darf man behalten, auch wenn der Termin damit voll ist.
            $eigen = tplan_entry_by_token((string)$v1['entry']['edit_token']);
            st_expect(tplan_vote_save($p, ['name' => 'Testperson A', 'v' => [(int)$mitZeit['id'] => 'yes']], $eigen)['ok'],
                'Wer schon zugesagt hat, verliert beim Ändern seinen Platz');
            st_expect(tplan_entry_count((int)$p['id']) === 1, 'Das Ändern hat eine zweite Zeile angelegt');

            $res = tplan_results($p);
            st_expect(count($res) === 2, 'Das Ergebnis hat nicht für jeden Vorschlag eine Zeile');
            $voll = null;
            foreach ($res as $z) if ((int)$z['option']['id'] === (int)$mitZeit['id']) $voll = $z;
            st_expect($voll !== null && $voll['yes'] === 1, 'Das Ergebnis zählt falsch');
            st_expect($voll['voll'] === true && $voll['frei'] === 0, 'Ein voller Termin wird nicht als voll erkannt');
            st_expect(tplan_best_ids($res) === [(int)$mitZeit['id']], 'Der beste Termin wird nicht erkannt');

            // Balkenbreiten stehen als Klassen in der CSS – sonst bleibt der Balken leer (CSP).
            $css = (string)@file_get_contents(__DIR__ . '/../assets/termin.css');
            require_once __DIR__ . '/../termin/_ansicht.php';
            foreach ($res as $z) {
                foreach ($z['pct'] as $wert) {
                    st_expect(str_contains($css, '.' . tp_w((int)$wert) . ' {'),
                        'In termin.css fehlt die Balkenbreite ' . tp_w((int)$wert) . ' – der Balken bliebe leer');
                }
            }

            // Sichtbarkeit: „nur ich" heißt nur ich, „am Ende" heißt nicht jetzt.
            st_expect(tplan_results_visible($p) === true, 'Das offene Ergebnis muss sichtbar sein');
            st_expect(!tplan_results_visible(['show_results' => 'orga'] + $p, false)
                && tplan_results_visible(['show_results' => 'orga'] + $p, true), '„Nur ich" wird nicht eingehalten');

            // Festlegen und Kalenderdatei.
            st_expect(tplan_finalize($p, (int)$mitZeit['id'], 'Selbsttest')['ok'], 'Der Termin lässt sich nicht festlegen');
            $p = (array)tplan_get((int)$p['id']);
            st_expect(!tplan_is_open($p), 'Nach der Festlegung darf nichts mehr eingetragen werden');
            $ics = tplan_ics($p, $mitZeit);
            st_expect(str_contains($ics, 'BEGIN:VEVENT') && str_contains($ics, 'DTSTART;TZID=Europe/Berlin:')
                && str_contains($ics, 'DTEND;TZID=Europe/Berlin:'), 'Die Kalenderdatei hat keinen brauchbaren Zeitraum');
            st_expect(str_contains(tplan_ics($p, $ganztags), 'DTSTART;VALUE=DATE:'), 'Ganztägige Termine brauchen ein Datum ohne Uhrzeit');
            st_expect(!tplan_finalize($p, 999999999, '')['ok'], 'Ein fremder Terminvorschlag darf nicht festlegbar sein');
        } finally {
            tplan_delete((int)$p['id']);
        }
        st_expect(tplan_get((int)$p['id']) === null, 'Die Wegwerf-Umfrage des Selbsttests wurde nicht wieder entfernt');
        st_expect((int)tplan_db()->query('SELECT COUNT(*) FROM entries WHERE poll_id = ' . (int)$p['id'])->fetchColumn() === 0,
            'Mit der Umfrage müssen auch ihre Rückmeldungen verschwinden');

        // ---- Bearbeitungsrechte: die Vorgabe ist „alle", und die Stufen müssen greifen ----
        $pm = tplan_create(['title' => 'Selbsttest Rechte', 'edit_mode' => 'own',
            'o_day' => [$tag], 'o_from' => [''], 'o_to' => ['']]);
        st_expect($pm['ok'], 'Anlegen für die Rechte-Prüfung scheitert');
        $pr = (array)$pm['poll'];
        try {
            $orr = tplan_options((int)$pr['id']);
            $err = tplan_vote_save($pr, ['name' => 'Rechte-Test', 'v' => [(int)$orr[0]['id'] => 'yes']])['entry'];
            st_expect(tplan_vote_save($pr, ['name' => 'Rechte-Test', 'v' => []], $err, true)['ok'],
                'Bei „nur die eigene Antwort" darf die eigene Person nicht ausgesperrt werden');
            st_expect(!tplan_vote_save($pr, ['name' => 'Fremd', 'v' => []], $err, false)['ok'],
                'Bei „nur die eigene Antwort" dürfen Fremde nicht ändern');
            // Ein Eintrag darf nie über eine fremde Umfrage erreichbar sein. Bewusst NICHT die
            // Nummer der vorherigen Probe-Umfrage: Die ist gelöscht, und SQLite vergibt frei
            // gewordene Nummern neu – die Prüfung verglich sonst eine Umfrage mit sich selbst.
            st_expect(tplan_entry_get((int)$pr['id'] + 1, (int)$err['id']) === null,
                'tplan_entry_get() findet einen Eintrag in einer FREMDEN Umfrage – damit käme man ohne deren Link an Daten');
        } finally {
            tplan_delete((int)$pr['id']);
        }
        st_expect(tplan_edit_mode(['edit_mode' => 'quatsch', 'allow_edit' => 1]) === 'own',
            'Ein unbekannter Wert in edit_mode muss auf das alte Verhalten zurückfallen');
        st_expect(array_keys(tplan_edit_modes()) === ['all', 'own', 'none'], 'Die Rechte-Stufen sind durcheinandergeraten');

        // Der große Umschalter im Anlege-Formular setzt drei Felder auf einmal – und muss über
        // widersprechende Einzelfelder gewinnen, sonst hinge das Verhalten am Zufall.
        foreach (tplan_zugang_modi() as $name => $soll) {
            $g = tplan_collect_settings(['title' => 'x', 'zugang' => $name,
                'edit_mode' => 'none', 'ask_mail' => 'off', 'mail_confirm' => 0]);
            foreach ($soll as $feld => $wert) {
                st_expect((string)$g[$feld] === (string)$wert,
                    'Der Umschalter „' . $name . '" setzt ' . $feld . ' nicht auf ' . $wert . ' – die Einzelfelder gewinnen');
            }
            st_expect(tplan_zugang($g) === $name, 'tplan_zugang() erkennt den Weg „' . $name . '" nicht wieder');
        }
        // Ohne Umschalter (Verwaltung) zählen die Einzelfelder weiter.
        $ohne = tplan_collect_settings(['title' => 'x', 'edit_mode' => 'none', 'ask_mail' => 'optional']);
        st_expect($ohne['edit_mode'] === 'none' && $ohne['ask_mail'] === 'optional',
            'Ohne den Umschalter müssen die Einzelfelder der Verwaltung greifen');
        // Beim Anlegen darf die Wahl nicht doppelt dastehen.
        $srcNeu = (string)@file_get_contents(__DIR__ . '/../termin/neu.php');
        st_expect(str_contains($srcNeu, 'tplan_zugang_wahl(') && str_contains($srcNeu, 'tplan_settings_fields($an, $v, false)'),
            'Im Anlege-Formular steht die Zugangs-Wahl doppelt (Umschalter UND Feineinstellungen)');

        // ---- Mail: ein Topf, und höchstens eine Mail je Umfrage ----
        st_expect(tplan_mail_budget() <= mail_pool_free(), 'Der Terminplaner darf nie mehr verschicken, als im gemeinsamen Topf frei ist');
        st_expect(array_key_exists('termin', mail_pool_quellen()), 'Der Terminplaner fehlt im gemeinsamen Mail-Konto');
        // Der Schalter in der Verwaltung muss wirklich abschalten, nicht nur den Knopf ausblenden.
        $vorher = tplan_setting_get('mail_links', '1');
        tplan_setting_set('mail_links', '0');
        $aus = tplan_mail_budget();
        tplan_setting_set('mail_links', $vorher);
        st_expect($aus === 0, 'Der Schalter „Links per Mail verschicken" hält den Versand nicht auf');
        // Die Bestätigung an Teilnehmende darf nie zweimal für dieselbe Adresse rausgehen –
        // sonst wäre jede Korrektur eine weitere Mail an eine womöglich fremde Adresse.
        $probe = ['email' => 'niemand@example.invalid', 'name' => 'Test', 'edit_token' => str_repeat('a', 32)];
        st_expect(tplan_mail_confirm(['mail_confirm' => 1, 'ask_mail' => 'optional', 'id' => 0, 'title' => 'x', 'slug' => 'x'] , $probe, 'niemand@example.invalid') === false,
            'Die Bestätigung geht bei unveränderter Adresse ein zweites Mal raus');
        st_expect(tplan_mail_confirm(['mail_confirm' => 0, 'ask_mail' => 'optional', 'id' => 0, 'title' => 'x', 'slug' => 'x'], $probe, '') === false,
            'Die abgeschaltete Bestätigung verschickt trotzdem');

        // ---- Geräte-Cookie: 30 Tage, nur auf dem Server lesbar ----
        $libq = (string)@file_get_contents(__DIR__ . '/../termin/termin-lib.php');
        st_expect(str_contains($libq, "'httponly' => true") && str_contains($libq, 'TPLAN_COOKIE_DAYS'),
            'Der Geräte-Cookie des Terminplaners ist nicht mehr httponly oder hat keine Laufzeit');
        st_expect(str_contains($libq, 'function tplan_forget'),
            'Ohne „Dieses Gerät vergessen" gäbe es keinen Weg, den Cookie wieder loszuwerden');

        // ---- Anbindung ----
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/index.php'), 'terminplaner.php'),
            'In der Verwaltung fehlt die Kachel zum Terminplaner');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/reset.php'), "'termin'"),
            'Der Terminplaner fehlt in der Gefahrenzone – man käme an seine Daten nicht heran');

        // Die fehlende Basis-Adresse ist ein Betriebszustand, kein Defekt – sie gehört in die
        // Notiz, nicht in ein st_expect (das würde alles Folgende in diesem Block abwürgen).
        $s = tplan_stats();
        return ($mode = tplan_mode()) . ' · ' . $s['polls'] . ' Terminumfragen · ' . $s['offen'] . ' offen · '
            . $s['entries'] . ' Rückmeldungen'
            . (tplan_base_url() === '' ? ' · ACHTUNG: keine Basis-Adresse, neue Umfragen gesperrt' : '');
    });
    $run('Umfragen: Adressprüfung, Trennung von Stimme und Adresse', function () {
        require_once __DIR__ . '/../umfrage-db.php';
        umfrage_db();                       // legt das Schema an, falls noch nicht vorhanden
        umfrage_all(); umfrage_get(0); umfrage_by_slug('gibtsnicht');

        // Nur erlaubte Endungen kommen durch; Unterdomains sind mitgemeint.
        $p = ['salt' => 'test', 'domains' => 'rptu.de'];
        st_expect(umfrage_mail_normalize('Max.Muster@RPTU.de', $p) === 'max.muster@rptu.de', 'Adresse wird nicht normalisiert');
        st_expect(umfrage_mail_normalize('x@stud.rptu.de', $p) === 'x@stud.rptu.de', 'Unterdomains müssen mitgelten');
        st_expect(umfrage_mail_normalize('x@gmail.com', $p) === '', 'Fremde Endungen müssen abgelehnt werden');
        st_expect(umfrage_mail_normalize('kein-mail', $p) === '' && umfrage_mail_normalize('', $p) === '', 'Unsinn muss abgelehnt werden');
        // Ohne diesen Schnitt könnte man mit name+1@, name+2@ … beliebig oft abstimmen.
        st_expect(umfrage_mail_normalize('a+42@rptu.de', $p) === 'a@rptu.de', 'Der „+Zusatz" muss abgeschnitten werden');
        // Eigenes Salz je Umfrage: derselbe Mensch ist über zwei Umfragen hinweg nicht wiedererkennbar.
        st_expect(umfrage_mail_hash(['salt' => 'A'], 'x@rptu.de') !== umfrage_mail_hash(['salt' => 'B'], 'x@rptu.de'),
            'Adress-Hashes zweier Umfragen dürfen NIE gleich sein');
        // Ohne hinterlegte Endungen muss die Prüfung ZU sein, nicht offen – sonst nähme eine
        // frisch aufgesetzte Umfrage jede beliebige Adresse an.
        st_expect(umfrage_mail_normalize('wer@auch.immer', ['domains' => '', 'salt' => 't']) === ''
                  || umfrage_domains(['domains' => '']) !== [],
            'ohne hinterlegte Mail-Endungen darf KEINE Adresse durchgehen');
        if (umfrage_domains(['domains' => '']) === []) {
            $hinweisDomains = ' · Achtung: keine Mail-Endungen hinterlegt, Umfragen nehmen niemanden an';
        }

        // Der Kern des Verfahrens: Die Urne darf keinen Weg zurück zum Verzeichnis haben.
        $spalten = fn(string $t) => array_map(fn($c) => (string)$c['name'], umfrage_db()->query('PRAGMA table_info(' . $t . ')')->fetchAll());
        $ballots = $spalten('ballots');
        $voters  = $spalten('voters');
        st_expect(!in_array('voter_id', $ballots, true) && !in_array('mail_hash', $ballots, true) && !in_array('token_id', $ballots, true),
            'ballots darf KEINEN Verweis auf das Verzeichnis enthalten – sonst ist die Abstimmung nicht mehr geheim');
        st_expect(!in_array('created_at', $ballots, true) && in_array('created_on', $ballots, true),
            'ballots darf nur das DATUM speichern – ein sekundengenauer Zeitstempel wäre über das Verzeichnis zuordenbar');
        st_expect(!in_array('used_at', $voters, true) && in_array('used', $voters, true),
            'voters darf keinen Zeitpunkt der Stimmabgabe speichern, nur ein Ja/Nein');
        st_expect(!in_array('email', $voters, true), 'Im Verzeichnis darf keine Klartext-Adresse stehen');

        // Die Klartext-Adresse verschwindet mit dem Versand. Sie steht nur in „pending", und nur
        // so lange, bis die Bestätigungsmail draußen ist – eine eigene tokens-Tabelle gibt es
        // nicht.
        $qSrc = (string)@file_get_contents(__DIR__ . '/../umfrage-db.php');
        st_expect(!str_contains($qSrc, 'CREATE TABLE IF NOT EXISTS tokens'),
            'Es gibt wieder eine tokens-Tabelle – dann stimmt die Anonymitäts-Prüfung hier nicht mehr');
        // Die tokens-Tabelle gibt es nicht mehr; auf älteren Datenbanken kann sie noch stehen –
        // samt Klartext-Adressen. Nur MELDEN, nicht
        // von selbst löschen: Was in einer Datenbank liegt, schaut man sich vorher an. Der Knopf
        // dafür steht in der Gefahrenzone, wo alles Löschende hingehört.
        st_expect((int)umfrage_db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tokens'")->fetchColumn() === 0,
            'In der Umfrage-Datenbank liegt noch die alte tokens-Tabelle (kann Klartext-Adressen enthalten). Verwaltung → Gefahrenzone → „Öffentliche Bereiche" zeigt die Zeilenzahl und entfernt sie auf Klick.');

        // Ausbau („funktioniert nicht / unübersichtlich / zu wenig Optionen"):
        // 1) Der Klammer-Fehler in umfrage_prune darf nicht zurück – „status='closed' OR …"
        //    löschte Verzeichnis und WARTENDE Stimmen in der Sekunde des Schließens.
        st_expect(!preg_match("~status = 'closed' OR \(ends_at~", $qSrc) && str_contains($qSrc, 'closed_at'),
            'umfrage_prune(): die alte ODER-Klammer ist zurück – Schließen würde wartende Stimmen sofort löschen');
        st_expect(in_array('closed_at', $spalten('polls'), true), 'polls.closed_at fehlt – prune wüsste nicht, wann geschlossen wurde');
        // 2) Der öffentliche Link hängt an der Basis-Adresse – die Verwaltung muss eine leere
        //    selbst heilen und eine abweichende laut melden (der „gibt es nicht"-Fall).
        $qAdm = (string)@file_get_contents(__DIR__ . '/umfragen.php');
        st_expect(str_contains($qAdm, 'weicht von der App ab') && str_contains($qAdm, "umfrage_setting_set('base_url', \$appBasis)"),
            'admin/umfragen.php: Selbstheilung/Warnung der Basis-Adresse fehlt – ein kaputter öffentlicher Link fiele wieder niemandem auf');
        // 3) Neue Frage-Optionen: Zwischentext, „Sonstiges"-Feld, Verdoppeln.
        st_expect(isset(umfrage_question_types()['info']), 'Der Frage-Typ „Zwischentext" (info) fehlt');
        st_expect(in_array('allow_other', $spalten('questions'), true) && in_array('is_other', $spalten('answers'), true),
            'Die „Sonstiges"-Spalten (questions.allow_other / answers.is_other) fehlen');
        st_expect(function_exists('umfrage_question_copy'), 'umfrage_question_copy() fehlt – der Verdoppeln-Knopf liefe ins Leere');
        // „Sonstiges" muss durch die Antwort-Prüfung kommen und als Merker landen.
        $probe = umfrage_answers_check(
            ['id' => -1, 'salt' => 'x'],
            [], []);
        st_expect($probe['ok'] === false, 'answers_check: eine Umfrage ohne Fragen darf nicht durchgehen');
        // 4) Editor und Grafiken: Kachel-Auswahl, Vorschau, Schnellwahl; die SVG-Bausteine
        //    (Attribute statt style-Breiten – die öffentliche CSP kennt kein unsafe-inline).
        st_expect(str_contains($qAdm, 'wahlkarten') && str_contains($qAdm, 'qpreview') && str_contains($qAdm, 'um-preset'),
            'admin/umfragen.php: Kachel-Auswahl, Live-Vorschau oder Skala-Schnellwahl fehlen');
        foreach (['umfrage_balken_html', 'umfrage_skala_html', 'umfrage_verlauf_html', 'umfrage_verlauf'] as $fn) {
            st_expect(function_exists($fn), $fn . '() fehlt – die Ergebnis-Grafiken sind unvollständig');
        }
        st_expect(!str_contains(umfrage_balken_html([['label' => 'A', 'n' => 1, 'pct' => 50]]), 'style='),
            'umfrage_balken_html(): style-Attribute – die öffentliche CSP verwirft sie still, die Balken wären leer');
        $qErgSrc = (string)@file_get_contents(__DIR__ . '/../umfrage/ergebnis.php');
        st_expect(str_contains($qErgSrc, 'umfrage_balken_html') && str_contains($qErgSrc, 'umfrage_skala_html'),
            'umfrage/ergebnis.php nutzt die Grafik-Bausteine nicht mehr');
        $qFormSrc = (string)@file_get_contents(__DIR__ . '/../umfrage/index.php');
        st_expect(str_contains($qFormSrc, 'um-zwischen') && str_contains($qFormSrc, "fo[") && str_contains($qFormSrc, 'um-sonst'),
            'umfrage/index.php: Zwischentext oder „Sonstiges"-Feld fehlen im Stimmzettel');
        $qStyleSrc = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        st_expect(str_contains($qStyleSrc, '.um-g-wert') && str_contains($qStyleSrc, '.wahlkarten'),
            'style.css: die Umfrage-Grafik-/Editor-Klassen fehlen');
        // Die neuen Spalten müssen auch auf einer BESTEHENDEN Datenbank da sein.
        foreach (['pending' => ['mail_token', 'email', 'mailed', 'payload', 'token_hash'],
                  'voters' => ['mail_hash', 'used'], 'ballots' => ['created_on']] as $t => $noetig) {
            $vorh = $spalten($t);
            foreach ($noetig as $c) st_expect(in_array($c, $vorh, true), 'Umfragen: Spalte ' . $t . '.' . $c . ' fehlt – Nachrüstung greift nicht');
        }
        // Zuständige Referate je Umfrage: Spalte, Funktionen, Rechte-Weiche und
        // die Titelleisten-Punkte – Referats-Zugänge sehen und betreuen NUR ihre Umfragen.
        st_expect(in_array('referate', $spalten('polls'), true), 'Umfragen: Spalte polls.referate fehlt – Zuständigkeiten gingen verloren');
        st_expect(function_exists('umfrage_referate') && function_exists('umfrage_referate_set')
            && function_exists('umfrage_polls_of_referat') && function_exists('umfrage_zustaendig_fuer_mich'),
            'die Referats-Zuständigkeits-Funktionen der Umfragen fehlen');
        st_expect(umfrage_referate(['referate' => 'Kultur|Soziales']) === ['Kultur', 'Soziales']
            && umfrage_referate(['referate' => '']) === [] && umfrage_polls_of_referat('') === [],
            'umfrage_referate()/umfrage_polls_of_referat() zerlegen die |-Liste falsch');
        st_expect(!str_contains($qAdm, 'require_admin'),
            'admin/umfragen.php sperrt wieder pauschal auf Admin – Referats-Zugänge kämen nicht mehr hinein');
        st_expect(str_contains($qAdm, '$umDarf') && str_contains($qAdm, 'referate_da'),
            'admin/umfragen.php: der Zuständigkeits-Wächter je Umfrage oder der Referate-Marker fehlt');
        st_expect(str_contains((string)@file_get_contents(__DIR__ . '/../lib.php'), "'admin/umfragen.php?edit=' . (int)"),
            'lib.php: die Titelleisten-Punkte der Umfragen-Zuständigen fehlen');
        st_expect(str_contains($qSrc, "UPDATE pending SET mailed = 1, email = '', mail_token = '' WHERE id = ?"),
            'Adresse und Mail-Token müssen mit dem Versand aus pending verschwinden');
        st_expect(!str_contains($qSrc, 'INSERT INTO ballots(poll_id, voter'), 'Die Urne darf keine Verzeichnis-ID mitschreiben');
        // Auch am Token darf kein Zeitpunkt der Stimmabgabe hängen: Über voter_id ergäbe die
        // Reihenfolge der Einlösungen zusammen mit der Reihenfolge der Stimmzettel die Zuordnung.
        // Eingelöste Token werden deshalb gelöscht statt abgehakt.
        // Wartende Stimmen: hier liegen Adress-Merkmal und Antworten zwangsläufig zusammen –
        // die Antworten deshalb VERSCHLÜSSELT, mit einem Schlüssel, der nur in der Mail steht.
        $pend = $spalten('pending');
        st_expect(in_array('payload', $pend, true) && !in_array('answers', $pend, true),
            'pending muss die Antworten verschlüsselt in payload halten, nicht im Klartext');
        st_expect(!in_array('used_at', $pend, true) && !in_array('confirmed_at', $pend, true),
            'pending darf keinen Bestätigungs-Zeitpunkt speichern – die Zeile wird beim Zählen gelöscht');
        st_expect(str_contains($qSrc, 'DELETE FROM pending WHERE id = ?'), 'Die wartende Zeile muss beim Zählen verschwinden');
        // Ver- und Entschlüsselung müssen wirklich funktionieren, sonst wären alle Stimmen verloren
        $tk = bin2hex(random_bytes(32));
        $blob = umfrage_seal($tk, [['q' => 1, 'o' => 2, 'n' => null, 't' => 'geheim']]);
        st_expect($blob !== '' && umfrage_unseal($tk, $blob)[0]['t'] === 'geheim', 'Verschlüsselung der wartenden Stimme funktioniert nicht');
        st_expect(umfrage_unseal(bin2hex(random_bytes(32)), $blob) === null, 'Mit falschem Schlüssel darf sich nichts entschlüsseln lassen');
        st_expect(!str_contains($blob, 'geheim'), 'Die Antworten dürfen im Ablagewert nicht lesbar sein');
        st_expect(umfrage_confirm('unsinn')['ok'] === false && umfrage_confirm('')['ok'] === false, 'Ungültige Bestätigungslinks müssen abgelehnt werden');
        // Kontingent: Umfragen nehmen sich aus dem gemeinsamen Konto (siehe mail-pool.php)
        // Der eigene Deckel ist seit dem gemeinsamen Mail-Konto nur noch eine freiwillige Bremse
        // (0 = keine). Er darf nie MEHR versprechen, als der Topf überhaupt hergeben kann –
        // sonst stünde in der Verwaltung eine Zahl, die nie erreicht wird.
        st_expect(umfrage_mail_cap_day() >= 0, 'Ein negativer Deckel ergibt keinen Sinn');
        // 0 heißt „keine Stundenbremse". Rechnet sie dann trotzdem herunter, stünde das
        // Kontingent auf 0 und jede Mail ginge grundlos in die Warteschlange – genau der Zustand,
        // den es vor der Freischaltung auf 3000 Mails gab.
        st_expect(umfrage_mail_cap_hour() !== 0
            || umfrage_mail_budget() === mail_pool_budget('umfrage', umfrage_mail_cap_day()),
            'Ohne Stundenbremse darf sie das Umfrage-Kontingent nicht kürzen – sonst wartet alles auf den Cron');
        st_expect(umfrage_mail_cap_day() === 0 || umfrage_mail_cap_day() <= mail_pool_day_allow(),
            'Der eigene Deckel der Umfragen (' . (int)umfrage_mail_cap_day() . ') liegt über dem, was der gemeinsame Topf am Tag hergibt ('
            . (int)mail_pool_day_allow() . ') – die Zahl wird nie erreicht. 0 heißt „kein eigener Deckel".');
        st_expect(umfrage_mail_budget() <= mail_pool_free(), 'Umfragen dürfen nie mehr verschicken, als im gemeinsamen Topf frei ist');
        st_expect(umfrage_mail_budget() >= 0 && umfrage_queue_len() >= 0, 'Kontingent-Rechnung liefert Unsinn');

        // Zwischenstände wären ein Leck: Aus zwei Ständen ließe sich die einzelne Stimme lesen.
        st_expect(str_contains($qSrc, 'function umfrage_results_public'), 'Die Regel „Ergebnis erst nach dem Ende" fehlt');
        st_expect(umfrage_results_public(['status' => 'open', 'ends_at' => date('Y-m-d H:i:s', time() + 3600)]) === false,
            'Eine laufende Umfrage darf ihr Ergebnis nicht öffentlich zeigen');
        st_expect(umfrage_results_public(['status' => 'closed', 'ends_at' => '']) === true, 'Nach dem Schließen muss das Ergebnis öffentlich sein');
        st_expect(umfrage_is_open(['status' => 'open', 'starts_at' => '', 'ends_at' => date('Y-m-d H:i:s', time() - 60)]) === false,
            'Nach Fristende darf nicht mehr abgestimmt werden');
        umfrage_prune();                    // muss ohne Daten durchlaufen
    });
    $run('Wegweiser (Adress-Filter, Symbole, Rubriken)', function () {
        wegweiser_all(); wegweiser_all(''); wegweiser_all('Sekretariat'); wegweiser_groups(); wegweiser_get(0);
        // Der Wegweiser zeigt FREMDE Links – „javascript:" & Co. dürfen nie durchrutschen.
        foreach (['javascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd', 'kein link', ''] as $boese) {
            st_expect(wegweiser_url_ok($boese) === '', 'wegweiser_url_ok muss „' . $boese . '" ablehnen');
        }
        st_expect(wegweiser_url_ok('beispiel.de/planer') === 'https://beispiel.de/planer', 'Fehlendes Schema muss zu https:// ergänzt werden');
        st_expect(wegweiser_url_ok(' https://rptu.de ') === 'https://rptu.de', 'Gültige Adresse muss unverändert (nur getrimmt) durchgehen');
        st_expect(wegweiser_host('https://www.rptu.de/pfad') === 'rptu.de', 'wegweiser_host muss „www." abschneiden');
        // Hauptziel-Reihenfolge: Web vor Mail vor Telefon
        st_expect(wegweiser_href(['url' => 'https://a.de', 'email' => 'a@b.de']) === 'https://a.de', 'Web-Adresse muss das Hauptziel sein');
        st_expect(wegweiser_href(['email' => 'a@b.de']) === 'mailto:a@b.de' && wegweiser_href(['phone' => '06341 280-1']) === 'tel:063412801', 'Mail/Telefon als Hauptziel falsch gebaut');
        st_expect(wegweiser_href([]) === '' && wegweiser_href(['phone' => 'auf Anfrage']) === '', 'Eintrag ohne wählbare Adresse darf kein Ziel liefern');
        st_expect(wegweiser_icon(['url' => 'https://teams.microsoft.com/x']) === 'ti-brand-teams'
            && wegweiser_icon(['url' => 'https://irgendwas.de']) === 'ti-world-www'
            && wegweiser_icon(['icon' => 'scale']) === 'ti-scale'
            && wegweiser_icon(['icon' => 'gibtsnicht']) === 'ti-bookmark', 'Symbol-Wahl (eigene Wahl / geraten / Rückfall) stimmt nicht');
        st_expect(wegweiser_group_label('') === 'Sonstiges', 'Eintrag ohne Rubrik gehört unter „Sonstiges"');
        // Eigene Einträge: Jede:r darf beitragen, ändern aber nur das Selbstangelegte.
        // (can_admin() gilt hier für die aufrufende Person – der Selbsttest läuft als Vorsitz/Admin,
        //  deshalb wird die Nicht-Admin-Regel über die reinen Datenfälle geprüft.)
        st_expect(wegweiser_can_edit(null, ['id' => 7]) === false, 'Ein nicht vorhandener Eintrag darf nie bearbeitbar sein');
        if (!can_admin()) {
            st_expect(wegweiser_can_edit(['created_by' => 7], ['id' => 7]) === true, 'Eigene Einträge müssen bearbeitbar sein');
            st_expect(wegweiser_can_edit(['created_by' => 8], ['id' => 7]) === false, 'Fremde Einträge dürfen NICHT bearbeitbar sein');
            st_expect(wegweiser_can_edit(['created_by' => null], ['id' => 7]) === false, 'Einträge ohne Urheber gehören der Verwaltung');
        }
        st_expect(wegweiser_mine(0) === [], 'Ohne Mitglied darf es keine eigenen Einträge geben');
        // Die Mitglieder-Seite muss die Rechte WIRKLICH prüfen – sonst könnte jede:r per
        // untergeschobener ID fremde Einträge ändern oder löschen.
        $wwSrc = (string)@file_get_contents(__DIR__ . '/../wegweiser.php');
        st_expect($wwSrc === '' || (substr_count($wwSrc, 'wegweiser_can_edit') >= 2 && str_contains($wwSrc, "'ww_delete'")),
            'wegweiser.php: Rechteprüfung beim Speichern/Löschen eigener Einträge fehlt');
        // Vorsitz/Admin haben ihre eigene Seite – der Abschnitt „Deine Einträge" wäre dort ein
        // zweiter, schwächerer Weg zum selben Ziel und bleibt deshalb ausgeblendet.
        st_expect($wwSrc === '' || str_contains($wwSrc, '$me && !can_admin()'),
            'wegweiser.php: „Deine Einträge" darf für Vorsitz/Admin nicht erscheinen');
        // Sichtbarkeit: mehrere Referate je Eintrag, gespeichert mit „|" getrennt.
        // Ein einzelner Name ohne Trenner muss weiter gelten – sonst wären bestehende
        // Einträge plötzlich für alle sichtbar.
        st_expect(wegweiser_referate('') === [] && wegweiser_referate('Sekretariat') === ['Sekretariat']
            && wegweiser_referate('A|B') === ['A', 'B'] && wegweiser_referate('| A | B |') === ['A', 'B'],
            'wegweiser_referate zerlegt die Sichtbarkeits-Angabe falsch');
        st_expect(wegweiser_visible('', 'Finanzen') && wegweiser_visible('Sekretariat', null)
            && wegweiser_visible('Sekretariat|Finanzen', 'Finanzen') && !wegweiser_visible('Sekretariat|Finanzen', 'Technik')
            && !wegweiser_visible('Sekretariat', ''),
            'Sichtbarkeits-Prüfung (leer = alle, Liste = nur diese Referate) stimmt nicht');
        $echt = referate_list();
        if ($echt) { // Speicherform: nur existierende Referate, ohne Dubletten
            st_expect(wegweiser_referate_feld([$echt[0], $echt[0], 'Gibtsnicht', '']) === $echt[0],
                'wegweiser_referate_feld muss Dubletten und unbekannte Referate wegwerfen');
        }
        st_expect(wegweiser_referate_feld([]) === '', 'Nichts angehakt muss „für alle" (leeres Feld) ergeben');
        // Jedes anbietbare Symbol muss es in der Icon-Schrift WIRKLICH geben – sonst steht in der
        // Kachel ein leerer Kasten. (Genau so ist „ti-signpost" beim Bau aufgefallen.)
        $tabler = (string)@file_get_contents(__DIR__ . '/../assets/tabler/tabler-icons.min.css');
        if ($tabler !== '') {
            foreach (array_keys(wegweiser_icons()) as $ic) {
                st_expect(str_contains($tabler, '.ti-' . $ic . ':before'), "Wegweiser-Symbol ti-$ic gibt es in der Tabler-Schrift nicht");
            }
            st_expect(str_contains($tabler, '.ti-directions:before'), 'Das Wegweiser-Symbol ti-directions fehlt in der Tabler-Schrift');
        }
        // Fenster-Regel: Links, die AUS der App herausführen, bekommen ein neues Fenster (die App
        // bleibt stehen) – interne Seiten NICHT, sonst sammeln sich in der installierten App
        // beim Durchklicken Dutzende eigener App-Fenster an.
        $wwSrc = (string)@file_get_contents(__DIR__ . '/../wegweiser.php');
        st_expect($wwSrc === '' || (str_contains($wwSrc, 'target="_blank" rel="noopener noreferrer"') && str_contains($wwSrc, '$extern ?')),
            'Externe Wegweiser-Links müssen ein neues Fenster öffnen (target="_blank" + rel="noopener noreferrer")');
        foreach (['wegweiser.php' => '../wegweiser.php', 'infos.php' => '../info.php'] as $adminSeite => $ziel) {
            $src = (string)@file_get_contents(__DIR__ . '/' . $adminSeite);
            st_expect($src === '' || !str_contains($src, 'href="' . $ziel . '" target="_blank"'),
                'admin/' . $adminSeite . ': Die Mitglieder-Ansicht ist ein App-interner Link und darf kein neues Fenster aufmachen');
        }
        // Die Sofort-Suche blendet Kacheln per „hidden" aus. Seit „hidden heißt hidden" steht
        // dafür EINE globale Regel am Dateianfang ([hidden] { display: none !important; }) statt
        // der neun Einzelflicken – ohne sie schlägt das display der Klasse das display:none des
        // Browsers, und die Suche sieht aus, als täte sie gar nichts.
        $wwCss = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        st_expect($wwCss === '' || preg_match('~^\[hidden\]\s*\{\s*display:\s*none\s*!important~m', $wwCss) === 1,
            'style.css: die globale [hidden]-Regel fehlt – die Wegweiser-Suche (und alles andere mit hidden) würde nichts ausblenden');
        // Ohne Verlinkung im Namens-Dropdown wäre die Seite nur über die direkte Adresse erreichbar.
        $libSrc = (string)@file_get_contents(__DIR__ . '/../lib.php');
        st_expect($libSrc === '' || substr_count($libSrc, 'wegweiser.php') >= 2, 'Wegweiser fehlt im Namens-Dropdown oder im „Mehr"-Menü (lib.php)');
        $cols = array_map(fn($c) => (string)$c['name'], db()->query('PRAGMA table_info(wegweiser)')->fetchAll());
        foreach (['title', 'url', 'email', 'phone', 'note', 'grp', 'icon', 'referat', 'sort'] as $c) {
            st_expect(in_array($c, $cols, true), "Wegweiser-Tabelle: Spalte $c fehlt");
        }
    });
    $run('Get-Togethers (laden/Rückmeldungen/Aufgaben/Mitbringen)', function () {
        gettogethers_upcoming(); gettogethers_in_range(date('Y-m-d'), date('Y-m-d', strtotime('+30 days')));
        gettogether_rsvp_map(0); gettogethers_pending_for_member(0); gettogether_rsvp_counts(0);
        $gid = (int)(db()->query('SELECT id FROM gettogethers ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
        gettogether_attendees($gid); gettogether_items($gid); gettogether_item_get(0);
        // Ankündigung: ohne gültige ID darf nichts verschickt werden (der Selbsttest verschickt nie etwas)
        st_expect(gettogether_notify_new(0) === 0, 'Ankündigung ohne Get-Together muss 0 liefern');
        st_expect(isset(notify_types()['gt_new']) && notify_types()['gt_new']['mail'] === 'opt',
            'Get-Together-Ankündigung muss abwählbar bleiben (freiwillig)');
        st_expect(dm_safe_link('gettogether.php?id=1') === 'gettogether.php?id=1', 'Sprungziel der Ankündigung muss erlaubt sein');
    });
    $run('Einladungs-Vorlage rendern', fn() => default_invite_template());
    $run('Upload-Zentrale: Graph-Status & Secret-Ablauf (ohne Netzwerk)', function () {
        graph_configured(); graph_secret_days_left(); graph_secret_warning(); graph_secret_notice_html();
    });
    $run('Upload-Zentrale: OLAT-WebDAV-Status (ohne Netzwerk)', function () {
        olat_configured(); olat_base_url(); olat_url_for('Protokolle/2026');
    });
    $run('Automationen: Berichte-Dok (Status/Helfer, ohne Upload)', function () {
        auto_reportdoc_enabled(); automation_last('reportdoc'); automation_done('reportdoc', 0);
        $m = db()->query("SELECT * FROM meetings WHERE draft=0 ORDER BY starts_at DESC LIMIT 1")->fetch();
        if ($m) { meeting_report_rows($m); meeting_reports_filled($m); automation_filename('Berichte_{sitzung}_{datum}', $m); }
        // Test-Uploads dürfen den echten Cron NICHT blockieren: nur status='success' zählt als erledigt
        automation_log_add('selftest_probe', 999999, 'test', 'Selbsttest');
        st_expect(!automation_done('selftest_probe', 999999), 'Test-Upload (status=test) darf nicht als erledigt gelten');
        automation_log_add('selftest_probe', 999999, 'success', 'Selbsttest');
        st_expect(automation_done('selftest_probe', 999999), 'Erfolgreicher Lauf (status=success) muss als erledigt gelten');
        db()->prepare("DELETE FROM automation_log WHERE automation='selftest_probe'")->execute();
    });
    $run('Finanzen: Belegblätter (Helfer + DOCX aus der Vorlage, ohne DB-Schreiben)', function () {
        st_expect(euro(123456) === '1.234,56 €' && euro(0) === '0,00 €', 'euro() muss deutsch formatieren');
        st_expect(euro_parse('12,34') === 1234 && euro_parse('12.34') === 1234 && euro_parse('1.234,56') === 123456, 'euro_parse muss Komma/Punkt-Eingaben verstehen');
        st_expect(euro_parse('5') === 500 && euro_parse('abc') === null && euro_parse('') === null, 'euro_parse: Ganzzahl in Euro, Müll ergibt null');
        st_expect(expense_claim_get(0) === null, 'expense_claim_get(0) muss null liefern');
        st_expect(expense_items(['items' => 'kaputt']) === [], 'expense_items muss kaputtes JSON abfangen');
        st_expect(expense_files_of(0) === [], 'expense_files_of(0) muss leer sein');
        st_expect(expense_comments_of(0) === [] && expense_comment_count(0) === 0 && expense_comment_files(0) === [], 'Nachrichten-Verlauf: leere Abfragen müssen leer sein');
        st_expect(cleanup_expense_files(36500) === 0, 'Beleg-Dok-Cleanup: mit 100-Jahres-Frist darf nichts gelöscht werden');
        st_expect(isset(notify_types()['dm_finanzen']), 'Mitteilungs-Typ dm_finanzen muss registriert sein');
        expense_claims_open(); expense_claims_done(1); can_finance();
        st_expect(docx_template_path('belegblatt') !== null, 'Belegblatt-Vorlage (assets/belegblatt-vorlage.docx) muss vorhanden sein');
        $fake = ['id' => 0, 'name' => 'Test, Selbst', 'street' => '', 'city' => '', 'bank' => '', 'bic' => '', 'iban' => 'DE00',
                 'source' => 'asta', 'source_note' => '', 'purpose' => 'Selbsttest',
                 'items' => json_encode([['d' => '2026-01-01', 's' => 'Probe', 'b' => 150]]), 'total_cents' => 150,
                 'created_at' => date('Y-m-d H:i:s')];
        $bin = expense_claim_docx($fake);
        st_expect(strlen($bin) > 1000 && substr($bin, 0, 2) === 'PK', 'expense_claim_docx muss eine gefüllte DOCX (Zip) liefern');
        st_expect(str_ends_with(expense_claim_filename($fake), '.docx'), 'Dateiname muss auf .docx enden');
    });

    // Beispiel-Mitglied: Score-Aufschlüsselung
    $sampleMember = (int)(db()->query('SELECT id FROM members WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    if ($sampleMember) $run('Score-Aufschlüsselung (Beispiel-Mitglied)', fn() => member_score_breakdown($sampleMember));
    else $skip('Score-Aufschlüsselung (Beispiel-Mitglied)', 'kein aktives Mitglied vorhanden');

    // Beispiel-Event: Schicht-Sortierung, Helfer-Statistik und Dienstplan-Tabelle
    $sampleEvent = (int)(db()->query('SELECT id FROM events WHERE draft = 0 ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
    if ($sampleEvent) {
        $run('Schicht-Sortierung + Helfer-Statistik (Beispiel-Event)', function () use ($sampleEvent) {
            $gs = slots_grouped(event_slots($sampleEvent));
            event_help_stats($sampleEvent);
            foreach ($gs as $gsSlot) slot_external_names($gsSlot); // externe Helfer pro Schicht
        });
        $run('Merkmale laden (Beispiel-Event)', function () use ($sampleEvent) {
            $ev = event_get($sampleEvent) ?: [];
            event_attr_labels($ev); event_attr_holders_all($ev); event_attr_holders($sampleEvent, 2);
        });
        $run('Fixierte Zuteilungen laden (Beispiel-Event)', fn() => locked_assignments($sampleEvent));
        $run('Selbst eingesprungene Zuteilungen laden (Beispiel-Event)', fn() => self_claimed_assignments($sampleEvent));
        $run('Schichtbörse + offene Schichten (Status/Angebote laden)', function () use ($sampleEvent) {
            $ev = event_get($sampleEvent) ?: [];
            st_expect(swap_lead_days() >= 0, 'Schichtbörsen-Vorlauf muss ≥ 0 sein');
            // Orga-Übersteuerung: „offen halten" macht die Börse auch ohne Vorlauf/Startzeit offen (Plan veröffentlicht, nicht abgeschlossen).
            st_expect(swap_market_open(['plan_locked' => 1, 'closed' => 0, 'swap_force_open' => 1, 'starts_at' => '2000-01-01 00:00']) === true, 'swap_force_open muss die Börse offen halten');
            st_expect(swap_market_open(['plan_locked' => 1, 'closed' => 0, 'swap_force_open' => 0, 'starts_at' => '2000-01-01 00:00']) === false, 'ohne Force muss ein vergangenes Event geschlossen sein');
            event_start_ts($ev); swap_market_open($ev); shift_swaps_open($sampleEvent); member_assigned_slots($sampleEvent, 0); member_swap_events(0);
            $open = event_open_slots($sampleEvent); foreach ($open as $os) { slot_open_remaining($os, 0); member_time_conflict(0, $os); }
            member_open_slot_events(0); swap_proposals_by_member(0); swap_proposals_for_offer(0); swap_proposal_get(0); swap_market_stats(0);
            swap_invalidate_orphaned(0); // nebenwirkungsfrei (eventId 0 → nichts zu tun)
            plan_overlaps($sampleEvent); plan_overlaps(0); // Arbeitsplan-Überschneidungen (Orga)
        });
        $run('Teilen-/Vorschau-Links (Open Graph)', function () {
            share_url('event.php?id=1'); share_url('meeting.php?id=1'); share_url('gettogether.php?id=1'); share_url('dashboard.php');
            share_preview('event', 0); share_preview('meeting', 0); share_preview('gt', 0); share_preview('unbekannt', 0);
        });
        $run('Dienstplan-Tabelle rendern (Beispiel-Event)', fn() => shift_roster_html(slots_grouped(event_slots($sampleEvent)), fn($s) => ''));
        $run('Arbeitsplan im Druck-Layout rendern (Beispiel-Event)', fn() => plan_print_html(event_get($sampleEvent) ?: []));
        $run('Fairteilung-Statistik (Beispiel-Event)', function () use ($sampleEvent) { event_fairness_stats(event_get($sampleEvent) ?: []); fmt_duration_min(210); });
    } else {
        $skip('Beispiel-Event-Prüfungen (Schichten, Merkmale, Börse, Druck, Fairteilung …)', 'kein Event vorhanden – 9 Prüfungen ausgelassen');
    }

    $nm = next_meeting();
    if ($nm) {
        $run('Einladungsmail (HTML) für „' . meeting_label($nm) . '"', fn() => invite_mail_html($nm));
        $run('Einladungsmail (Plaintext)', fn() => invite_mail_text($nm));
        $run('Tagesordnung zusammenführen', fn() => merged_agenda($nm));
        $run('Protokoll-Vorlage (.docx) erzeugen', fn() => build_protokoll_docx($nm));
        $run('Internes Protokoll (.docx) erzeugen', fn() => build_protokoll_intern_docx($nm));
    } else {
        $skip('Sitzungs-Prüfungen (Einladung, Tagesordnung, Protokoll-DOCX)', 'keine kommende Sitzung – 5 Prüfungen ausgelassen');
    }
    $run('Berichte-Export (.docx) erzeugen', fn() => build_reports_docx('Selbsttest', []));
    $run('Protokoll-Workflow (Status, Dateiname, Abfragen)', function () {
        st_expect(protocol_status_label('uploaded') !== '' && protocol_status_label('published') !== '', 'Status-Labels müssen gefüllt sein');
        // kind=sonstige → meeting_label = Titel (keine Legislatur-Abfrage nötig)
        $fn = protocol_pdf_filename(['title' => 'Selbsttest', 'kind' => 'sonstige', 'starts_at' => '2026-05-06 20:00']);
        st_expect(str_ends_with($fn, '.pdf') && strpbrk($fn, '\\/:*?"<>|') === false, 'PDF-Dateiname muss auf .pdf enden und keine verbotenen Zeichen enthalten');
        st_expect(function_exists('graph_download_file') && function_exists('protocol_publish_to_olat') && function_exists('vote_item_set_decision') && function_exists('graph_list_folders'), 'Protokoll-Funktionen müssen vorhanden sein');
        st_expect(protocol_year_of(['starts_at' => '2026-05-06 20:00']) === '2026', 'Jahr muss aus dem Sitzungsdatum kommen');
        protocol_teams_target_folder(['starts_at' => '2026-05-06 20:00']); // Smoke: Ziel-Ordner-Aufbau (+ ggf. /JAHR)
        protocol_teams_intern_target_folder(['starts_at' => '2026-05-06 20:00']);
        protocol_olat_target_folder(['starts_at' => '2026-05-06 20:00']);
        st_expect(str_ends_with(protocol_docx_filename(['title' => 'T', 'kind' => 'sonstige', 'starts_at' => '2026-05-06 20:00'], true), '.docx'), 'Protokoll-Dateiname (.docx)');
        report_target_folder(['starts_at' => '2026-05-06 20:00']); report_year_subfolder();
        protocol_next_meeting_after('2000-01-01 00:00'); // Query darf nicht crashen
        member_protocol_upload_tasks(0);
        protocol_publish_pending();
    });

    $run('Protokoll-Automatik (Zielsitzung, Umhängen, Bestand)', function () {
        foreach (['protocol_vote_off', 'protocol_vote_after'] as $sp) {
            st_expect(in_array($sp, array_column(db()->query('PRAGMA table_info(meetings)')->fetchAll(), 'name'), true),
                'Spalte meetings.' . $sp . ' muss nachgerüstet sein');
        }
        st_expect(function_exists('protocol_vote_anchor') && function_exists('protocol_vote_texts')
            && function_exists('protocol_vote_reactivate') && function_exists('meeting_delete')
            && function_exists('protokoll_pending_approvals'), 'Funktionen der Protokoll-Automatik müssen vorhanden sein');

        // Ankerpunkt: nach einer Vertagung zählt die Sitzung, in der vertagt wurde
        st_expect(protocol_vote_anchor(['starts_at' => '2026-01-01 18:00', 'protocol_vote_after' => '']) === '2026-01-01 18:00',
            'ohne Vertagung ist die Sitzung selbst der Anker');
        st_expect(protocol_vote_anchor(['starts_at' => '2026-01-01 18:00', 'protocol_vote_after' => '2026-02-01 18:00']) === '2026-02-01 18:00',
            'nach einer Vertagung zählt die Sitzung, in der vertagt wurde');
        st_expect(protocol_vote_anchor(['starts_at' => '2026-03-01 18:00', 'protocol_vote_after' => '2026-02-01 18:00']) === '2026-03-01 18:00',
            'ein älterer Merker darf den Anker nicht zurückdatieren');

        [$tt, $bb] = protocol_vote_texts(['id' => 0, 'title' => 'X', 'kind' => 'sonstige', 'starts_at' => '2026-05-06 20:00']);
        st_expect(str_contains($tt, 'genehmigen') && str_contains($bb, '06.05.2026'), 'Gegenstands-Text muss Sitzung und Datum nennen');
        st_expect(protokoll_pending_approvals([]) === [], 'ohne Sitzung keine anstehenden Genehmigungen');

        // Ziel muss noch kommen – sonst stimmt niemand mehr darüber ab
        $z = protocol_next_meeting_after('2000-01-01 00:00');
        st_expect($z === null || strtotime((string)($z['ends_at'] ?: $z['starts_at'])) >= strtotime('today'),
            'als Ziel darf nur eine Sitzung dienen, die noch nicht vorbei ist');
        st_expect($z === null || ((int)$z['cancelled'] === 0 && (int)$z['draft'] === 0 && (string)$z['kind'] !== 'stupa'),
            'Ziel darf nicht abgesagt, Entwurf oder StuPa sein');

        // Derselbe Lauf wie im Cron: beim zweiten Mal darf sich nichts mehr ändern
        protocol_sync_pending_votes();
        st_expect(protocol_sync_pending_votes() === 0, 'der Abgleich muss idempotent sein (zweiter Lauf ohne Änderung)');

        // Bestandsprüfung: keine Protokoll-Abstimmung darf irgendwo hängen, wo sie nichts nützt
        st_expect((int)db()->query("SELECT COUNT(*) FROM vote_items WHERE kind = 'protocol'
            AND (ref_meeting_id IS NULL OR ref_meeting_id NOT IN (SELECT id FROM meetings))")->fetchColumn() === 0,
            'keine Protokoll-Abstimmung ohne existierende Quell-Sitzung');
        $krumm = (int)db()->query("SELECT COUNT(*) FROM vote_items v
            JOIN meetings z ON z.id = v.meeting_id JOIN meetings q ON q.id = v.ref_meeting_id
            WHERE v.kind = 'protocol' AND v.decision = 'offen' AND z.starts_at <= q.starts_at")->fetchColumn();
        st_expect($krumm === 0, 'eine offene Genehmigung darf nicht vor der Sitzung stehen, um die es geht (' . $krumm . ')');
        $tot = (int)db()->query("SELECT COUNT(*) FROM vote_items v JOIN meetings z ON z.id = v.meeting_id
            WHERE v.kind = 'protocol' AND v.decision = 'offen' AND (z.cancelled = 1 OR z.draft = 1)")->fetchColumn();
        st_expect($tot === 0, 'keine offene Genehmigung in einer abgesagten Sitzung oder einem Entwurf (' . $tot . ')');
        $doppelt = (int)db()->query("SELECT COUNT(*) FROM (SELECT ref_meeting_id FROM vote_items
            WHERE kind = 'protocol' AND decision = 'offen' GROUP BY ref_meeting_id HAVING COUNT(*) > 1)")->fetchColumn();
        st_expect($doppelt === 0, 'dasselbe Protokoll darf nicht zweimal offen zur Genehmigung stehen (' . $doppelt . ')');

        // Die Beziehung steht NUR in vote_items.ref_meeting_id. Der frühere Rückverweis
        // meetings.protocol_vote_item_id war dieselbe Aussage ein zweites Mal – und blieb nach dem
        // Löschen der Zielsitzung als Leiche stehen, wodurch das Protokoll aus dem Verfahren fiel.
        st_expect(function_exists('protocol_vote_item'), 'protocol_vote_item() muss die Ableitung übernehmen');
        foreach (glob(__DIR__ . '/../*.php') ?: [] as $pfad) {
            if (basename($pfad) === 'lib.php') continue; // dort nur Kommentar + Altlast-Spalte
            $src = (string)@file_get_contents($pfad);
            st_expect(!str_contains($src, 'protocol_vote_item_id'),
                basename($pfad) . ' benutzt wieder den Rückverweis – die Beziehung steht in vote_items.ref_meeting_id');
        }
        $libSrc = (string)@file_get_contents(__DIR__ . '/../lib.php');
        foreach (['SET protocol_vote_item_id', 'protocol_vote_item_id =', "['protocol_vote_item_id']"] as $muster) {
            st_expect(!str_contains($libSrc, $muster), 'lib.php schreibt/liest wieder protocol_vote_item_id (' . $muster . ')');
        }
        // Nach einer Vertagung gibt es zwei Gegenstände – „der aktuelle" muss der offene sein.
        $mehr = db()->query("SELECT ref_meeting_id FROM vote_items WHERE kind = 'protocol'
                             GROUP BY ref_meeting_id HAVING COUNT(*) > 1 LIMIT 1")->fetchColumn();
        if ($mehr !== false) {
            $akt = protocol_vote_item((int)$mehr);
            st_expect($akt !== null, 'bei mehreren Gegenständen muss einer als aktuell gelten');
        }

        // Reiter „Protokolle & Abstimmungsgegenstände" + Dashboard-Karte „Beschlüsse festhalten":
        // ohne sie sieht niemand außerhalb der Sitzungsseiten, dass ein Beschluss aussteht – genau
        // so blieb ein angenommenes Protokoll unbemerkt unveröffentlicht.
        st_expect(function_exists('meetings_with_open_vote_items') && is_array(meetings_with_open_vote_items()),
            'meetings_with_open_vote_items() fehlt – Dashboard-Karte und Protokolle-Reiter hätten keine Datenquelle');
        st_expect(str_contains($libSrc, "AND v.decision = 'offen'") && str_contains($libSrc, "m.kind != 'stupa'"),
            'meetings_with_open_vote_items() muss offene Gegenstände zählen und StuPa ausschließen');
        $mtQ = (string)@file_get_contents(__DIR__ . '/meetings.php');
        st_expect(str_contains($mtQ, "'protokolle'") && str_contains($mtQ, "\$action === 'protocol_publish'"),
            'admin/meetings.php: der Protokolle-Reiter oder sein Veröffentlichen-Handler fehlt');
        st_expect(substr_count($mtQ, "\$station(") >= 8 && str_contains($mtQ, 'proto-verlauf')
            && str_contains((string)@file_get_contents(__DIR__ . '/../assets/style.css'), '.proto-verlauf'),
            'der Protokoll-Verlauf (Stationen-Karten) fehlt – der Reiter zeigt sonst nicht mehr, was mit jedem Protokoll passiert ist');
        st_expect((bool)preg_match("~\\\$protoMeetings = db\(\)->query\(\"SELECT \* FROM meetings\s*\n\s*WHERE kind != 'stupa'~", $mtQ),
            'die Protokoll-Übersicht muss StuPa-Sitzungen ausschließen (kalender-only, kein Protokoll)');
        $dashQ = (string)@file_get_contents(__DIR__ . '/../dashboard.php');
        st_expect(str_contains($dashQ, 'can_manage_meetings() && ($openVoteMeetings = meetings_with_open_vote_items())'),
            'dashboard.php: die Karte „Beschlüsse festhalten" fehlt oder hängt nicht mehr an can_manage_meetings() – Vorsitz sähe sie sonst nicht');
        // Protokollant:innen ohne Word sind der Normalfall: Beide Einreich-Wege müssen erklärt
        // sein, sonst laden Verzweifelte die Datei von Hand in Teams hoch und die App erfährt nichts.
        $mtgQ = (string)@file_get_contents(__DIR__ . '/../meeting.php');
        st_expect(str_contains($mtgQ, 'So reichst du das Protokoll ein') && str_contains($mtgQ, 'nicht von Hand in einen Teams-Kanal'),
            'meeting.php: die Einreich-Anleitung für Protokollant:innen (inkl. Warnung vor dem Hand-Upload in Teams) fehlt');
        // Die Einreich-Aktionen müssen VOR der Verwaltungs-Sammelschranke stehen: Dahinter erreicht
        // ein normales Mitglied als Protokollant:in die eigene Taker-Prüfung nie und bekommt
        // „Dafür fehlt dir die Berechtigung" – genau so sind die Protokolle liegengeblieben.
        $schranke = strpos($mtgQ, "flash('Dafür fehlt dir die Berechtigung.'");
        st_expect($schranke !== false, 'meeting.php: die Verwaltungs-Sammelschranke ist nicht mehr auffindbar – die Reihenfolge-Prüfung darunter greift sonst nicht');
        foreach (["'protocol_upload'", "'protocol_take_from_teams'"] as $aktion) {
            $wo = strpos($mtgQ, '$action === ' . $aktion);
            st_expect($wo !== false && $wo < $schranke,
                'meeting.php: die Aktion ' . $aktion . ' steht wieder HINTER der Verwaltungs-Schranke – Protokollant:innen ohne Verwaltungsrolle können dann nicht einreichen');
        }
        st_expect(str_contains($dashQ, 'Kein Word auf dem Gerät?') && str_contains($dashQ, 'In Teams schreiben'),
            'dashboard.php: die Protokollant:innen-Karte erklärt den Teams-Schreibweg nicht mehr – ohne Word scheitern die Leute sonst am Prozess');
        // Fertiges Protokoll öffnet die Teams-APP (Deep-Link, wie bei den Entwürfen), nicht Office
        // im Browser. Die rohe webUrl darf an keinem Öffnen-Knopf mehr stehen.
        st_expect(function_exists('protocol_open_url') && protocol_open_url(['id' => 0]) === '',
            'protocol_open_url() fehlt oder liefert ohne Daten nicht leer');
        st_expect(protocol_open_url(['id' => 1, 'protocol_teams_url' => 'x', 'protocol_web_url' => 'y']) === 'x',
            'protocol_open_url() muss den gemerkten Teams-Deep-Link vor der webUrl liefern');
        st_expect(substr_count($mtgQ, 'protocol_open_url(') >= 3 && !str_contains($mtgQ, 'href="<?= h($pWebUrl)'),
            'meeting.php: die Protokoll-Öffnen-Knöpfe nutzen wieder die rohe webUrl – das öffnet Office im Browser statt der Teams-App');
        st_expect(str_contains($libSrc, "protocol_teams_url = NULL") && str_contains($libSrc, 'protocol_intern_teams_url = NULL'),
            'lib.php: Neu-Upload leert den gemerkten Teams-Deep-Link nicht mehr – nach dem Ersetzen zeigte der Knopf sonst auf die alte Datei');
        // Beschluss nachtragen (Altbestand ohne Folgesitzung): Ohne den Weg hängen längst
        // angenommene Protokolle in der Warteschleife auf die nächste Sitzung fest.
        st_expect(str_contains($mtgQ, "\$action === 'protocol_mark_approved'")
            && str_contains($mtgQ, "protocol_status = 'approved', protocol_vote_off = 1"),
            'meeting.php: „Beschluss nachtragen" fehlt oder setzt vote_off nicht mehr – der Abgleich legte sonst doch noch eine Abstimmung an');
        st_expect((bool)preg_match("~decision'\] === 'offen'\) \{\s*\n\s*flash\('Es steht schon eine Abstimmung an~", $mtgQ),
            'meeting.php: Nachtragen muss bei offener Abstimmung ablehnen und auf sie verweisen – sonst gibt es zwei Wahrheiten zum selben Beschluss');
        // Teams-Verknüpfung reparieren: Hand-Ersetzungen in Teams (löschen + neu hochladen) machen
        // die gemerkte Datei-Id tot – ohne Reparatur-Weg scheitert die OLAT-Veröffentlichung mit 404.
        st_expect(str_contains($mtgQ, "\$action === 'protocol_relink'")
            && str_contains($mtgQ, "muss in Teams liegen") && str_contains($mtgQ, "!== 'docx'"),
            'meeting.php: „Teams-Verknüpfung reparieren" fehlt (oder prüft Ablage/.docx nicht mehr)');
        st_expect(str_contains($libSrc, 'Teams-Verknüpfung reparieren'),
            'lib.php: die 404-Fehlermeldung der OLAT-Veröffentlichung erklärt den Reparatur-Weg nicht mehr');
        // Zurücksetzen (Vertipper/zu früh festgelegt): Muss offene Genehmigungen einsammeln,
        // gefasste Beschlüsse aber als Beleg stehen lassen.
        st_expect(str_contains($mtgQ, "\$action === 'protocol_reset'")
            && str_contains($mtgQ, "AND ref_meeting_id = ? AND decision = 'offen'"),
            'meeting.php: „Protokoll-Eintrag zurücksetzen" fehlt oder löscht nicht mehr NUR die offenen Genehmigungen');
        // Datum in Dateinamen als TT.MM.JJJJ (User-Wunsch) – wie bei den Vorlagen-Downloads
        $fnProbe = ['id' => 0, 'kind' => 'sonstige', 'title' => 'Probe', 'starts_at' => '2026-07-15 20:00'];
        st_expect(protocol_docx_filename($fnProbe) === 'Protokoll_Probe_15.07.2026.docx'
            && protocol_docx_filename($fnProbe, true) === 'Protokoll_intern_Probe_15.07.2026.docx'
            && protocol_pdf_filename($fnProbe) === 'Protokoll_Probe_15.07.2026.pdf',
            'Protokoll-Dateinamen müssen das Datum als TT.MM.JJJJ tragen (nicht mehr JJJJ-MM-TT)');
        // Sitzungsnummer im Dateinamen zweistellig („07."), damit der Ordner in Reihenfolge sortiert
        st_expect(protokoll_nummer_pad('7. Ordentliche Sitzung') === '07. Ordentliche Sitzung'
            && protokoll_nummer_pad('12. Ordentliche Sitzung – Klausur') === '12. Ordentliche Sitzung – Klausur'
            && protokoll_nummer_pad('Außerordentliche Sitzung') === 'Außerordentliche Sitzung',
            'protokoll_nummer_pad() füllt die Sitzungsnummer nicht mehr zweistellig auf (7. → 07.)');
        st_expect(substr_count($libSrc, 'protokoll_nummer_pad(meeting_label(') === 2,
            'protocol_docx_filename()/protocol_pdf_filename() lassen die Nummer wieder einstellig – bitte über protokoll_nummer_pad() führen');
    });

    $run('Ablage-Picker (Pfad-Hygiene, Verweise, Löschschutz)', function () {
        st_expect(function_exists('ablage_browse') && function_exists('ablage_fetch')
            && function_exists('mirror_link_existing') && function_exists('mirror_linked')
            && function_exists('ablage_pick_field'), 'Picker-Funktionen müssen vorhanden sein');
        foreach ([['teams_files'], ['nc_files']] as [$tab]) {
            st_expect(in_array('linked', array_column(db()->query('PRAGMA table_info(' . $tab . ')')->fetchAll(), 'name'), true),
                'Spalte ' . $tab . '.linked muss nachgerüstet sein');
        }
        // Pfade kommen aus dem Browser – „.." darf nie aus der Ablage herausführen
        st_expect(ablage_clean_path('a/../../etc/passwd') === 'a/etc/passwd', 'Pfad-Hygiene: „.." muss verschwinden');
        st_expect(ablage_clean_path('/////x//y/') === 'x/y', 'Pfad-Hygiene: Schrägstriche normalisieren');
        st_expect(ablage_clean_path('../..') === '', 'Pfad aus lauter „.." muss leer sein');
        $e = null;
        st_expect(ablage_browse('dropbox', '', $e) === null, 'unbekannte Ablage muss abgelehnt werden');
        st_expect(ablage_fetch('dropbox', 'x', $e) === null, 'unbekannte Ablage darf nichts liefern');

        // Anhängbare Endungen: eine Liste für Upload und Auswahl
        st_expect(in_array('pdf', attach_ext_allowed(), true) && !in_array('exe', attach_ext_allowed(), true),
            'Endungs-Liste muss pdf erlauben und exe nicht');

        // Löschschutz für nur verlinkte Originale – der gefährlichste Teil des Pickers
        $probe = 987654321;
        db()->prepare("INSERT OR REPLACE INTO nc_files(kind, ref_id, path, file_id, name, linked) VALUES('vfile',?,'Probe/x.pdf','1','x.pdf',1)")->execute([$probe]);
        try {
            st_expect(mirror_linked('vfile', $probe), 'verlinkte Datei muss als solche erkannt werden');
            st_expect(mirror_delete_check_attr('vfile', $probe) === '', 'für ein fremdes Original darf es kein „mitlöschen"-Häkchen geben');
            $err = null;
            st_expect(mirror_delete_remote('vfile', $probe, $err) === true, 'Fernlöschen muss beim Verweis folgenlos durchgehen');
            db()->prepare("UPDATE nc_files SET linked = 0 WHERE kind='vfile' AND ref_id = ?")->execute([$probe]);
            st_expect(!mirror_linked('vfile', $probe), 'selbst angelegte Spiegel dürfen nicht als verlinkt gelten');
            st_expect(str_contains(mirror_delete_check_attr('vfile', $probe), 'store_too'), 'für eigene Spiegel muss das Häkchen erscheinen');
        } finally {
            db()->prepare("DELETE FROM nc_files WHERE kind='vfile' AND ref_id = ?")->execute([$probe]);
        }
        st_expect(!mirror_linked('vfile', $probe), 'Probe-Zeile muss wieder weg sein');

        // Der Dialog muss in den Bildschirm passen. Ohne Höhenbegrenzung wächst er bei einem
        // vollen Ordner darüber hinaus – und weil er mittig sitzt, verschwinden Titel und
        // Ablage-Umschalter nach OBEN aus dem Bild. Genau so ist es beim ersten Mal passiert.
        $css = (string)@file_get_contents(__DIR__ . '/../assets/style.css');
        $js  = (string)@file_get_contents(__DIR__ . '/../assets/app.js');
        st_expect(preg_match('~\.modal\s*\{[^}]*max-height~s', $css) === 1,
            'Dem Dialog (.modal) fehlt die Höhenbegrenzung – lange Listen schneiden oben ab');
        foreach (['.pick-body', '.modal-list'] as $sel) {
            st_expect(preg_match('~' . preg_quote($sel, '~') . '\s*\{[^}]*overflow-y:\s*auto~s', $css) === 1,
                $sel . ' muss scrollen können, sonst läuft eine lange Liste aus dem Dialog');
        }
        // Klassen, die das JS setzt, müssen im CSS auch vorkommen – sonst sieht es kaputt aus,
        // ohne dass irgendwo ein Fehler auftaucht.
        preg_match_all('~[\'"]pick-[a-z-]+~', $js, $tref);
        foreach (array_unique(array_map(fn($t) => ltrim($t, '\'"'), $tref[0])) as $klasse) {
            if (in_array($klasse, ['pick-title', 'pick-close'], true)) continue; // id bzw. data-Attribut
            st_expect(str_contains($css, '.' . $klasse), 'CSS-Klasse .' . $klasse . ' wird im JS gesetzt, fehlt aber in style.css');
        }
        st_expect(str_contains($js, 'pick-store') && str_contains($js, 'pick-crumbs'),
            'Dem Ablage-Picker fehlen Ablagen-Umschalter oder Pfadzeile');
        // Zwei Formulare stecken in einem echten <dialog>. Das liegt im Top-Layer, über jedem
        // z-index – ohne Umhängen wäre der Picker dort unsichtbar hinter dem Dialog.
        st_expect(str_contains($js, "dialog[open]"),
            'Der Picker hängt sich nicht in einen offenen <dialog> – dort bliebe er unsichtbar');

        // Überall verdrahtet? Knopf im Formular UND Verarbeitung beim Speichern gehören zusammen;
        // fehlt eines von beiden, klickt man ins Leere oder der Knopf fehlt ganz.
        foreach ([
            'meeting.php'      => 'Abstimmungsgegenstände',
            'umlauf.php'       => 'Umlauf/Abstimmungen',
            'admin/infos.php'  => 'Wichtige Infos',
        ] as $datei => $wo) {
            $src = (string)@file_get_contents(__DIR__ . '/../' . $datei);
            st_expect(str_contains($src, 'ablage_pick_field('), $wo . ' (' . $datei . '): Knopf „Aus der Ablage wählen" fehlt');
            st_expect(str_contains($src, 'ablage_pick_attach('), $wo . ' (' . $datei . '): die Auswahl wird beim Speichern nicht verarbeitet');
        }

        return ablage_stores() ? 'wählbar aus: ' . implode(', ', ablage_stores()) : 'keine Ablage eingerichtet – der Knopf bleibt aus';
    });

    $run('StuPa-Sitzungen (Label, eigene Nummerierung, kalender-only)', function () {
        // Vor der ersten Legislaturperiode gibt es keine Nummer, aber ein sauberes Label.
        // Die Periode folgt aus dem DATUM, also muss die Probe vor der ersten Periode liegen.
        $frueh = (string)(db()->query("SELECT MIN(start_date) FROM legislatures WHERE start_date <> ''")->fetchColumn() ?: '');
        $vor = $frueh !== '' ? date('Y-m-d', strtotime($frueh . ' -1 day')) : '1999-01-01';
        $s = ['id' => 0, 'title' => '', 'kind' => 'stupa', 'starts_at' => $vor . ' 18:00', 'cancelled' => 0];
        // „1. Sitzung ist Nr." beim Anlegen einer Serie meint die erste Sitzung DER SERIE – auch
        // wenn in der Periode schon Sitzungen davor liegen. Früher wurde die Zahl ungeprüft zur
        // Startnummer der Periode, und die Serie begann um die Zahl der früheren Sitzungen zu spät
        // (Bug-Ticket: Eingabe 10 → 12). Nachgestellt ohne HTTP über dieselbe Rechnung wie dort.
        $qMt = (string)@file_get_contents(__DIR__ . '/meetings.php');
        st_expect(str_contains($qMt, '$legStart = $startNum - $vorher;'),
            'meetings.php zieht beim Anlegen einer Serie die früheren Sitzungen der Periode nicht mehr ab – die Serie beginnt dann zu spät');
        // Entwürfe tragen keine Nummer: Sie zählen sich selbst nicht mit und hießen sonst doppelt.
        st_expect(meeting_number(['kind' => 'ordentlich', 'cancelled' => 0, 'draft' => 1,
                                  'starts_at' => date('Y-m-d H:i'), 'id' => 0]) === null,
            'ein Entwurf bekommt eine Sitzungsnummer – sie wäre um eins zu klein');
        st_expect(meeting_number($s) === null, 'Vor der ersten Periode darf es keine Nummer geben (Probe: ' . $vor . ')');
        st_expect(meeting_label($s) === 'StuPa-Sitzung', 'StuPa-Label ohne Nummer muss „StuPa-Sitzung" sein, ist: ' . meeting_label($s));
        // Ausgefallene StuPa-Sitzung wird nicht nummeriert
        $sc = $s; $sc['cancelled'] = 1;
        st_expect(meeting_number($sc) === null, 'ausgefallene StuPa-Sitzung darf keine Nummer bekommen');
        // Zusatz-Titel wird angehängt
        $st2 = $s; $st2['title'] = 'Haushalt';
        st_expect(str_contains(meeting_label($st2), 'Haushalt'), 'Zusatz-Titel muss im StuPa-Label erscheinen');
        // StuPa taucht nicht als „nächste AStA-Sitzung" auf (Query darf nicht crashen)
        next_meeting();
    });

    $run('Freie Termine (Zielgruppen, Sichtbarkeits-Abfragen, Labels)', function () {
        st_expect(count(termin_audiences()) === 3 && isset(termin_audiences()['all']), 'Es muss die Zielgruppen self/members/all geben');
        // Abfragen dürfen nicht crashen (meId 0 → keine Treffer)
        termine_in_range(0, '2026-01-01', '2026-12-31');
        termine_visible_upcoming(0);
        termine_mine_upcoming(0);
        termin_member_ids(0);
        st_expect(termin_audience_label(['audience' => 'all']) !== '' && termin_audience_label(['audience' => 'self']) !== '', 'Zielgruppen-Labels müssen gefüllt sein');
        st_expect(function_exists('member_hides_stupa') && function_exists('tplan_create') && function_exists('tplan_delete'), 'Termin-/StuPa-Funktionen müssen vorhanden sein');
        st_expect(member_hides_birthdays(null) === false && member_hides_birthdays(['hide_birthdays' => 1]) === true, 'Geburtstags-Ausblenden (member_hides_birthdays) muss korrekt lesen');
        member_set_hide_birthdays(0, true); // No-Op bei id<=0 – darf nicht fatal sein
    });

    $run('Terminfinder (Abfragen, Ergebnis-Sortierung, Pflichtaufgabe)', function () {
        // Abfragen dürfen nicht crashen
        date_polls_all();
        date_polls_pending_for_member(0);
        date_poll_options(0);
        date_poll_results(0);
        date_poll_voter_ids(0);
        st_expect(active_member_count() >= 0, 'Aktive-Mitglieder-Zahl muss abfragbar sein');
        // Ergebnis-Sortierung: mehr „Ja" gewinnt, „Evtl." zählt halb
        $mk = fn($yes, $maybe) => ['option' => ['id' => 0, 'starts_at' => '2026-01-01 10:00', 'ends_at' => null], 'yes' => $yes, 'maybe' => $maybe, 'no' => 0, 'score' => $yes + $maybe * 0.5];
        $rows = [$mk(1, 0), $mk(3, 0), $mk(3, 2)];
        usort($rows, function ($a, $b) {
            if ($a['yes'] !== $b['yes']) return $b['yes'] <=> $a['yes'];
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            return 0;
        });
        st_expect($rows[0]['yes'] === 3 && $rows[0]['maybe'] === 2, 'Sortierung: die meisten „Ja" (bei Gleichstand mehr „Evtl.") zuerst');
        st_expect(function_exists('date_poll_create') && function_exists('date_poll_save_votes') && function_exists('date_poll_has_voted'), 'Terminfinder-Funktionen müssen vorhanden sein');
        // „Abstimmen bis": offen ohne Frist, offen vor Frist, zu nach Frist, zu bei manuellem Schließen
        st_expect(date_poll_voting_open(['closed' => 0, 'deadline' => null]), 'ohne Frist muss offen sein');
        st_expect(date_poll_voting_open(['closed' => 0, 'deadline' => '2999-01-01 00:00']), 'vor der Frist muss offen sein');
        st_expect(!date_poll_voting_open(['closed' => 0, 'deadline' => '2000-01-01 00:00']), 'nach der Frist muss zu sein');
        st_expect(date_poll_deadline_passed(['closed' => 0, 'deadline' => '2000-01-01 00:00']), 'abgelaufene Frist muss erkannt werden');
        st_expect(!date_poll_voting_open(['closed' => 1, 'deadline' => null]), 'manuell geschlossen muss zu sein');
    });

    $run('Umlaufverfahren (Regeln, Quorum, Fristen, Pflichtaufgabe)', function () {
        // Abfragen dürfen nicht crashen
        umlauf_all();
        umlauf_pending_for_member(0);
        umlauf_tally(0);
        umlauf_ballots(0);
        umlauf_send_reminders(true); // Dry-Run: nur zählen, nichts verschicken
        st_expect(function_exists('umlauf_create') && function_exists('umlauf_vote') && function_exists('umlauf_finalize') && function_exists('umlauf_maintain'), 'Umlauf-Funktionen müssen vorhanden sein');
        st_expect(count(umlauf_rules()) === 3, 'drei Annahme-Regeln (einfach/absolut/einstimmig)');
        // Auswertung: alle Regeln + Quorum an festen Zählungen
        $t = fn($y, $n, $a) => ['yes' => $y, 'no' => $n, 'abstain' => $a, 'total' => $y + $n + $a];
        $u = fn($rule, $q = 0) => ['rule' => $rule, 'quorum_pct' => $q];
        st_expect(umlauf_eval($u('simple'), $t(3, 2, 1), 10) === 'accepted', 'einfache Mehrheit: 3 Ja / 2 Nein = angenommen');
        st_expect(umlauf_eval($u('simple'), $t(2, 2, 1), 10) === 'rejected', 'einfache Mehrheit: Gleichstand = abgelehnt');
        st_expect(umlauf_eval($u('absolute'), $t(5, 0, 0), 10) === 'rejected', 'absolute Mehrheit: 5 von 10 reicht NICHT');
        st_expect(umlauf_eval($u('absolute'), $t(6, 0, 0), 10) === 'accepted', 'absolute Mehrheit: 6 von 10 reicht');
        st_expect(umlauf_eval($u('unanimous'), $t(4, 0, 2), 10) === 'accepted', 'einstimmig: Enthaltungen sind ok');
        st_expect(umlauf_eval($u('unanimous'), $t(9, 1, 0), 10) === 'rejected', 'einstimmig: eine Nein-Stimme kippt');
        st_expect(umlauf_eval($u('simple', 50), $t(3, 1, 0), 10) === 'no_quorum', 'Quorum 50 %: 4 von 10 Beteiligung = ungültig');
        st_expect(umlauf_eval($u('simple', 50), $t(4, 1, 0), 10) === 'accepted', 'Quorum 50 %: 5 von 10 Beteiligung reicht');
        // Fristen: offen vor Frist, zu nach Frist, zu bei zurückgezogen/geschlossen
        st_expect(umlauf_open(['status' => 'open', 'deadline' => '2999-01-01 12:00']), 'vor der Frist muss offen sein');
        st_expect(!umlauf_open(['status' => 'open', 'deadline' => '2000-01-01 12:00']), 'nach der Frist muss zu sein');
        st_expect(!umlauf_open(['status' => 'closed', 'deadline' => '2999-01-01 12:00']), 'geschlossen bleibt zu');
        st_expect(!umlauf_open(['status' => 'withdrawn', 'deadline' => '2999-01-01 12:00']), 'zurückgezogen bleibt zu');
        st_expect(umlauf_result_label('no_quorum') !== '—', 'Ergebnis-Labels müssen belegt sein');
        // Mitteilungs-Register: alle Umlauf-/Abstimmungs-Typen deklariert
        foreach (['umlauf_new', 'umlauf_reminder', 'umlauf_result', 'poll_new'] as $nt) {
            st_expect(isset(notify_types()[$nt]), "Mitteilungs-Typ $nt muss im Register stehen");
        }
    });

    $run('Normale Abstimmungen (Abfragen, Offen-Logik, Pflichtaufgabe)', function () {
        // Abfragen dürfen nicht crashen
        poll_all();
        poll_pending_for_member(0);
        poll_optional_for_member(0);
        poll_hide_for_member(0, 0); // ungültige IDs → No-Op (nichts wird gespeichert)
        st_expect(poll_optional_for_member(0) === [], 'freiwillige Dashboard-Hinweise ohne Mitglied müssen leer sein');
        poll_options(0);
        poll_results(0);
        poll_voter_ids(0);
        poll_vote_names(0);
        poll_send_reminders(true); // Dry-Run: nur zählen, nichts verschicken
        st_expect(function_exists('poll_create') && function_exists('poll_vote') && function_exists('poll_close') && function_exists('poll_maintain'), 'Abstimmungs-Funktionen müssen vorhanden sein');
        // Offen-Logik: ohne Frist offen, vor Frist offen, nach Frist zu, geschlossen bleibt zu
        st_expect(poll_open(['status' => 'open', 'deadline' => null]), 'ohne Frist muss offen sein (bis zum manuellen Beenden)');
        st_expect(poll_open(['status' => 'open', 'deadline' => '2999-01-01 12:00']), 'vor der Frist muss offen sein');
        st_expect(!poll_open(['status' => 'open', 'deadline' => '2000-01-01 12:00']), 'nach der Frist muss zu sein');
        st_expect(!poll_open(['status' => 'closed', 'deadline' => null]), 'beendet bleibt zu');
        // Anlegen validiert: ohne 2 Optionen bzw. verpflichtend ohne Frist → 0 (kein Insert)
        st_expect(poll_create(0, 'x', '', null, false, false, false, ['a', 'b']) === 0, 'ohne Ersteller:in darf nichts angelegt werden');
        st_expect(poll_create(1, 'x', '', null, false, false, false, ['nur eine']) === 0, 'unter zwei Antwortoptionen darf nichts angelegt werden');
        st_expect(poll_create(1, 'x', '', null, false, false, true, ['a', 'b']) === 0, 'verpflichtend ohne Frist darf nichts angelegt werden');
    });

    $run('„In Teams öffnen" (Bereiche, Rechte, Spiegel-Verwaltung)', function () {
        // Abfragen dürfen nicht crashen; ensure/forget nur mit ungültigen Werten (keine Uploads/Deletes)
        st_expect(array_keys(teams_open_kinds()) === ['info', 'vfile', 'ufile', 'protokoll', 'protokoll_intern'], 'Teams-Bereiche müssen info/vfile/ufile/protokoll(_intern) sein');
        // Umlauf-/Abstimmungs-Anhänge: ungültige Werte lehnen ab, ohne zu schreiben
        st_expect(umlauf_files_of('quatsch', 1) === [] && umlauf_files_of('umlauf', 0) === [], 'Anhang-Abfrage muss bei ungültigem Bereich leer sein');
        st_expect(is_string(umlauf_file_add('quatsch', 1, ['error' => UPLOAD_ERR_OK], 0)) && function_exists('umlauf_file_delete') && function_exists('umlauf_files_delete_all'), 'Anhang-Upload muss unbekannte Bereiche ablehnen');
        st_expect(teams_open_allowed('quatsch') === false, 'Unbekannter Bereich darf nie erlaubt sein');
        st_expect(teams_file_row('info', 0) === null, 'Ohne Spiegel-Eintrag muss null kommen');
        $err = null;
        st_expect(teams_file_ensure('quatsch', 1, 0, $err) === null && teams_file_current('info', 0) === null, 'Ungültiger Bereich/fehlender Spiegel müssen sauber ablehnen');
        st_expect(is_string(teams_open_base_folder()) && teams_open_base_folder() !== '', 'Basisordner der Teams-Spiegel muss gesetzt sein');
        st_expect(function_exists('teams_file_forget') && function_exists('graph_upload_bytes') && function_exists('teams_open_button'), 'Teams-Öffnen-Funktionen müssen vorhanden sein');
        st_expect(teams_file_delete_remote('info', 0) === true && function_exists('graph_delete_item'), 'Teams-Mitlöschen: ohne Spiegel muss es ein No-Op sein (Lösch-Dialog-Häkchen)');
        st_expect(teams_deep_link([], '') === '' && function_exists('teams_file_open_url'), 'Teams-Deep-Link muss ohne SharePoint-IDs leer bleiben (Fallback webUrl)');
    });

    $run('StuPa-Zugänge sind KEINE Mitglieder', function () {
        // 1) Keine Mitglieds-Zeile mit unbekannter Rolle: current_role() macht daraus stillschweigend
        //    'member' – ein übrig gebliebenes StuPa-Konto hätte damit vollen Zugriff.
        $fremd = (int)db()->query("SELECT COUNT(*) FROM members
            WHERE COALESCE(role,'') NOT IN ('member','sekretariat','finanzen','admin','vorsitz')")->fetchColumn();
        st_expect($fremd === 0, $fremd . ' Mitglied(er) mit unbekannter Rolle – die gälten als vollwertige Mitglieder');
        // 2) Keine StuPa-Adresse in der Mitgliedertabelle (sonst liefe der Zugang doch in Listen mit)
        $doppelt = (int)db()->query('SELECT COUNT(*) FROM members m JOIN stupa_users s
            ON LOWER(TRIM(m.email)) = LOWER(TRIM(s.email))')->fetchColumn();
        st_expect($doppelt === 0, $doppelt . ' Mitglied(er) teilen sich eine Adresse mit einem StuPa-Zugang');
        // 3) Getrennte Nummernkreise: stupa_claims.created_by zeigt auf stupa_users, NICHT auf members.
        //    Ein JOIN gegen members würde die falsche Person anzeigen – darf es nirgends geben.
        st_expect(function_exists('stupa_user_get') && function_exists('stupa_token_consume'), 'StuPa-Funktionen müssen vorhanden sein');
        st_expect(stupa_token_consume('unsinn') === null, 'Ein erfundener Anmelde-Token darf nie durchgehen');
        return (int)db()->query('SELECT COUNT(*) FROM stupa_users')->fetchColumn() . ' Zugang/Zugänge';
    });

    $run('Ablagen-Weiche (Teams ODER Nextcloud – nie beides für dieselbe Datei)', function () {
        // Keine Netzaufrufe: nur Registry, Weiche und Grenzfälle ohne Spiegel.
        st_expect(mirror_kinds() === teams_open_kinds(), 'Bereichsliste muss für beide Ablagen dieselbe sein');
        st_expect(mirror_allowed('quatsch') === false, 'Unbekannter Bereich darf nie erlaubt sein');
        st_expect(in_array(mirror_backend(), ['teams', 'nextcloud'], true), 'Ablage für neue Doks muss teams oder nextcloud sein');
        st_expect(mirror_store('info', 0) === '', 'Ohne Spiegel darf keine Ablage gemeldet werden');
        st_expect(mirror_open_url('info', 0) === '' && mirror_delete_check_attr('info', 0) === '',
            'Ohne Spiegel: keine Öffnen-URL und kein Lösch-Häkchen');
        st_expect(mirror_delete_remote('info', 0) === true, 'Mitlöschen ohne Spiegel muss ein No-Op sein');
        st_expect(mirror_open_permitted('quatsch', 1, null) === false, 'Ohne Anmeldung darf nichts erlaubt sein');
        st_expect(mirror_label('nextcloud') === 'Nextcloud' && mirror_label('teams') === 'Teams', 'Anzeigenamen der Ablagen');
        // Eine Datei darf nie in beiden Tabellen stehen – das wäre die zweite bearbeitbare Fassung.
        $doppelt = (int)db()->query('SELECT COUNT(*) FROM nc_files n JOIN teams_files t ON t.kind = n.kind AND t.ref_id = n.ref_id')->fetchColumn();
        st_expect($doppelt === 0, 'Keine Datei darf gleichzeitig in Teams UND Nextcloud gespiegelt sein (gefunden: ' . $doppelt . ')');
        st_expect(function_exists('mirror_ensure') && function_exists('mirror_current') && function_exists('mirror_forget')
            && function_exists('mirror_open_button'), 'Weichen-Funktionen müssen vorhanden sein');
    });

    $run('Nextcloud-Anbindung (WebDAV-Kern, Adresse, Spiegel)', function () {
        // Kein Netz: geprüft werden Zugangsprofil, URL-Bau und die Grenzfälle ohne Einrichtung.
        st_expect(array_keys(dav_stores()) === ['olat', 'nextcloud'], 'Bekannte WebDAV-Ablagen: olat und nextcloud');
        st_expect(dav_profile('olat')['root'] === olat_base_url(), 'OLAT-Hülle und Kern müssen dieselbe Wurzel liefern');
        st_expect(nc_normalize_base_url('cloud.example.de/index.php/apps/files/x') === 'https://cloud.example.de',
            'Adresse: Schema ergänzen, mitkopierten Pfad abschneiden');
        st_expect(nc_normalize_base_url('https://cloud.example.de/') === 'https://cloud.example.de', 'Schluss-Slash muss weg');
        st_expect(nc_normalize_base_url('') === '', 'Leere Adresse bleibt leer');
        st_expect(nc_kind_folder('info') === trim(nc_base_folder() . '/Infos', '/'), 'Bereichsordner hängt am Basisordner');
        st_expect(nc_kind_folder('protokoll') === nc_kind_folder('protokoll_intern'), 'Beide Protokoll-Entwürfe teilen den Ordner');
        st_expect(nc_file_row('info', 0) === null && nc_file_current('info', 0) === null, 'Ohne Spiegel muss null kommen');
        st_expect(nc_file_delete_remote('info', 0) === true, 'Löschen ohne Spiegel ist ein No-Op');
        $err = null;
        st_expect(nc_file_ensure('quatsch', 1, 0, $err) === null && $err === 'Unbekannter Bereich.', 'Unbekannter Bereich wird abgewiesen');
        if (nc_configured()) {
            // Eingerichtet: Wurzel muss den WebDAV-Pfad des Kontos tragen, sonst zeigt alles ins Leere
            st_expect(str_contains(dav_profile('nextcloud')['root'], '/remote.php/dav/files/'),
                'WebDAV-Wurzel der Nextcloud muss auf das Konto zeigen');
            st_expect(in_array(nc_editor(), ['collabora', 'onlyoffice', ''], true), 'Office-Erkennung darf nur bekannte Werte liefern');
            // Verwaiste Spiegel: Verweis da, Quelldatei weg → der Öffnen-Knopf liefe ins Leere
            $waisen = (int)db()->query("SELECT COUNT(*) FROM nc_files n WHERE
                (n.kind = 'info'  AND NOT EXISTS (SELECT 1 FROM info_files f WHERE f.id = n.ref_id))
             OR (n.kind = 'vfile' AND NOT EXISTS (SELECT 1 FROM vote_item_files f WHERE f.id = n.ref_id))
             OR (n.kind = 'ufile' AND NOT EXISTS (SELECT 1 FROM umlauf_files f WHERE f.id = n.ref_id))")->fetchColumn();
            st_expect($waisen === 0, 'Keine verwaisten Nextcloud-Verweise (Quelldatei gelöscht, Verweis geblieben): ' . $waisen);
            return 'eingerichtet' . (nc_editor_label() !== '' ? ' · ' . nc_editor_label() : ' · kein Office-Server');
        }
        return 'Nextcloud nicht eingerichtet – 4 Prüfungen ausgelassen';
    });

    $run('Datei-Auslieferung (Content-Disposition inline/attachment)', function () {
        st_expect(download_disposition('application/vnd.openxmlformats-officedocument.wordprocessingml.document') === 'inline', 'DOCX muss inline gehen (Quick-Look-Vorschau statt weißer Seite in der iOS-PWA)');
        st_expect(download_disposition('application/pdf') === 'inline', 'PDF muss inline gehen');
        st_expect(download_disposition('text/html') === 'attachment', 'HTML darf NIE inline gehen (sonst XSS über Uploads)');
        st_expect(download_disposition('image/svg+xml') === 'attachment', 'SVG darf NIE inline gehen (kann Skripte enthalten)');
        st_expect(download_disposition('') === 'attachment', 'unbekannter Typ bleibt attachment');
        st_expect(function_exists('file_delivery_headers'), 'zentraler Datei-Header-Helfer muss vorhanden sein (ersetzt no-store der Session – sonst weiße Quick-Look-Seite auf iOS)');
    });

    $run('Pat:innenprogramm (eigene Datenbank, Semester, Studi-Link)', function () {
        require_once __DIR__ . '/../pat-db.php';
        st_expect(function_exists('pat_db') && function_exists('pat_round_create') && function_exists('pat_round_by_slug'),
            'Pat:innenprogramm-Funktionen müssen vorhanden sein');

        // Trennung: die öffentliche Seite darf die App-Datenbank nicht sehen können.
        pat_db(); // legt die Datei beim ersten Lauf an – danach ist realpath() aussagekräftig
        st_expect(realpath(PAT_DB_FILE) !== realpath(DB_FILE) && PAT_DB_FILE !== DB_FILE,
            'Pat:innenprogramm MUSS eine eigene Datenbankdatei haben');
        st_expect(is_file(__DIR__ . '/../pat/pat-lib.php'), 'öffentlicher Bootstrap pat/pat-lib.php muss existieren');
        foreach (['pat/index.php', 'pat/anmeldung.php', 'pat/hilfe.php'] as $f) {
            st_expect(is_file(__DIR__ . '/../' . $f), $f . ' fehlt');
        }
        st_expect(is_file(__DIR__ . '/../data/.htaccess'), 'data/.htaccess muss die Datenbanken vor dem Web schützen');

        // Semester-Beschriftung (Jahreswechsel beim Wintersemester)
        st_expect(pat_label('wise', 2026) === 'WiSe 2026/27', 'WiSe muss beide Jahre zeigen');
        st_expect(pat_label('sose', 2026) === 'SoSe 2026', 'SoSe zeigt ein Jahr');

        // Schlüsselprüfung: nur die eigene Form darf in eine Abfrage gehen
        st_expect(pat_round_by_slug('') === null && pat_round_by_slug("' OR 1=1 --") === null
            && pat_round_by_slug('../../data/asta.sqlite') === null, 'ungültige Studi-Links müssen abgewiesen werden');
        st_expect(pat_slug_valid(pat_new_slug('wise', 2026)), 'erzeugte Schlüssel müssen die eigene Formprüfung bestehen');
        st_expect(!preg_match('/[01loi]/', substr(pat_new_slug('sose', 2027), 9)), 'Schlüssel-Alphabet ohne Verwechsler (0/1/l/o/i)');

        // Anlegen wird sauber abgewiesen, wenn die Angaben nicht stimmen
        st_expect(!pat_round_create('herbst', 2026)['ok'], 'unbekannte Semesterart muss abgelehnt werden');
        st_expect(!pat_round_create('wise', 1999)['ok'], 'unsinniges Jahr muss abgelehnt werden');
        st_expect(!pat_round_set_status(0, 'open') && !pat_round_delete(0), 'unbekannte Programm-ID muss abgelehnt werden');

        // Verantwortliche (Nicht-Admins mit Zugriff nur auf die Pat-Verwaltungsseite + Titelleisten-Punkt)
        st_expect(function_exists('pat_manager_ids') && function_exists('is_pat_manager') && function_exists('pat_managers_set'),
            'Verantwortlichen-Funktionen müssen vorhanden sein');
        st_expect(is_array(pat_manager_ids()), 'Verantwortlichen-Liste muss ein Array sein');
        st_expect(!is_pat_manager(['id' => -5]) && !is_pat_manager(['name' => 'ohne id']),
            'Unsinnige Mitglieder dürfen nie als verantwortlich gelten');
        foreach (pat_manager_ids() as $mgId) {
            st_expect(member_get($mgId) !== null, 'Verantwortliche:r #' . $mgId . ' existiert nicht mehr in der Stammliste');
        }

        // Studiengänge (Erstis wählen Abschluss → Studiengang; Verwaltung pflegt die Listen)
        st_expect(function_exists('pat_courses') && function_exists('pat_course_add') && function_exists('pat_course_delete'),
            'Studiengangs-Funktionen müssen vorhanden sein');
        st_expect(array_keys(pat_degrees()) === ['bachelor', 'master'], 'Abschlüsse müssen bachelor/master sein');
        st_expect(pat_courses('quatsch') === [], 'unbekannter Abschluss muss eine leere Liste liefern');
        st_expect(!pat_course_add('quatsch', 'Psychologie')['ok'], 'Studiengang mit unbekanntem Abschluss muss abgelehnt werden');
        st_expect(!pat_course_add('bachelor', 'x')['ok'], 'zu kurzer Studiengangsname muss abgelehnt werden');
        st_expect(!pat_course_add('bachelor', 'Deutsch', 999999)['ok'],
            'Unterpunkt unter unbekanntem Studiengang muss abgelehnt werden');
        st_expect(!pat_course_add('bachelor', 'Deutsch', -1)['ok'], 'negative Eltern-ID muss abgelehnt werden');
        st_expect(pat_course_get(0) === null && !pat_course_delete(0), 'unbekannte Studiengangs-ID muss sauber ablehnen');
        st_expect(function_exists('pat_course_children') && pat_course_children(0) === [],
            'Unterpunkt-Abfrage muss vorhanden sein und ID 0 leer beantworten');
        st_expect(function_exists('pat_course_sublabel_set') && !pat_course_sublabel_set(0, 'Erstfach'),
            'Unterauswahl-Bezeichnung muss unbekannte IDs ablehnen');

        // Anmeldungen (das Formular validiert in der Datenschicht – hier die Ablehnpfade, die nichts schreiben)
        st_expect(function_exists('pat_signup_create') && function_exists('pat_signup_counts'),
            'Anmelde-Funktionen müssen vorhanden sein');
        st_expect(!pat_signup_create(['round_id' => 0])['ok'], 'Anmeldung ohne offenes Programm muss abgelehnt werden');
        st_expect(pat_signup_counts(0) === ['ersti' => 0, 'pate' => 0], 'Zählung eines unbekannten Programms muss 0/0 sein');

        // Einteilung (Gruppengröße als Wunsch, Zuteilung von Hand, Kennzahlen)
        st_expect(function_exists('pat_assign_auto') && function_exists('pat_assign_set')
            && function_exists('pat_assign_clear') && function_exists('pat_groups_of') && function_exists('pat_round_stats'),
            'Einteilungs-Funktionen müssen vorhanden sein');
        st_expect(!pat_round_set_groupsize(0, 3, 6), 'Gruppengröße für unbekanntes Programm muss abgelehnt werden');
        st_expect(!pat_assign_auto(0)['ok'], 'Einteilung eines unbekannten Programms muss abgelehnt werden');
        st_expect(!pat_assign_set(0, null), 'Zuteilung einer unbekannten Person muss abgelehnt werden');
        st_expect(pat_round_stats(0)['pates'] === 0 && pat_groups_of(0)['free'] === [],
            'Kennzahlen eines unbekannten Programms müssen leer sein');
        foreach (pat_rounds_all() as $rr) {
            $gm = (int)$rr['group_min']; $gx = (int)$rr['group_max'];
            st_expect($gm >= 1 && $gx >= $gm, 'Programm „' . pat_round_label($rr) . '" hat eine unsinnige Wunsch-Gruppengröße (' . $gm . '–' . $gx . ')');
        }

        // Einteilungs-Mails: Warteschlange (verschickt wird im Selbsttest NICHTS)
        st_expect(function_exists('pat_mail_queue_assignments') && function_exists('pat_mail_run')
            && function_exists('pat_mail_stats') && function_exists('pat_mail_clear'),
            'Mail-Warteschlangen-Funktionen müssen vorhanden sein');
        st_expect(!pat_mail_queue_assignments(0)['ok'], 'Mails für ein unbekanntes Programm müssen abgelehnt werden');
        st_expect(!pat_mail_queue_assignments(1, 'quatsch')['ok'], 'unbekannte Rolle muss abgelehnt werden');
        st_expect(pat_mail_stats(0) === ['queued' => 0, 'sent' => 0, 'failed' => 0], 'Mailzahlen unbekannter Programme müssen 0 sein');
        foreach (['mail_ersti_body' => ['{{PATIN}}', '{{PATIN_MAIL}}'], 'mail_pate_body' => ['{{LISTE}}']] as $k => $phs) {
            foreach ($phs as $ph) {
                st_expect(str_contains(pat_text($k), $ph), 'in der Vorlage „' . $k . '" fehlt der Platzhalter ' . $ph);
            }
        }
        // Gruppen-Kennenlernen: Die ganze Gruppe bekommt die Kontakte aller.
        // Geprüft werden die STANDARD-Texte des Registers (bewusst geänderte Verwaltungs-Texte
        // sind erlaubt) und die Mechanik: cc-Spalte, Gruppen-Vorlage, {{GRUPPE}}-Bau, CC-Durchreiche.
        $ptf = pat_text_fields();
        st_expect(str_contains((string)$ptf['mail_ersti_body']['default'], '{{GRUPPE}}'),
            'Standard-Vorlage mail_ersti_body verlor den {{GRUPPE}}-Block (die anderen Erstis der Gruppe)');
        st_expect(isset($ptf['mail_gruppe_subject'], $ptf['mail_gruppe_body'])
            && str_contains((string)$ptf['mail_gruppe_body']['default'], '{{LISTE}}'),
            'die Gruppen-Kennenlern-Mail (mail_gruppe_*) fehlt im Register oder verlor {{LISTE}}');
        st_expect(str_contains((string)$ptf['form_privacy_ersti']['default'], 'an die anderen Erstsemester'),
            'die Standard-Einwilligung der Erstis deckt die Weitergabe an die Gruppe nicht mehr ab');
        st_expect(in_array('cc', array_column(pat_db()->query('PRAGMA table_info(pat_mailqueue)')->fetchAll(), 'name'), true),
            'pat_mailqueue hat keine cc-Spalte – die Gruppen-Mail könnte niemanden ins CC nehmen');
        $qPatDb = (string)@file_get_contents(__DIR__ . '/../pat-db.php');
        st_expect(str_contains($qPatDb, "\$m['cc']"), 'pat_mail_run() reicht das CC nicht mehr an den Sender durch');
        st_expect(str_contains($qPatDb, "'group'"), 'pat_mail_queue_assignments() stellt keine Gruppen-Mails (kind group) mehr ein');
        st_expect((new ReflectionFunction('send_mail'))->getNumberOfParameters() >= 9,
            'send_mail() hat den CC-Parameter verloren – die Gruppen-Mail käme nur bei der Pat:in an');
        // Wettlauf-Schutz: keine Mail darf dauerhaft auf 'sending' hängen (siehe pat_mail_run)
        st_expect(function_exists('pat_mail_forget_queued') && defined('PAT_MAIL_CLAIM_MINUTES'),
            'der Wettlauf-Schutz des Mailversands muss vorhanden sein');
        $haengt = (int)pat_db()->query("SELECT COUNT(*) FROM pat_mailqueue WHERE status='sending'
            AND (claimed_at IS NULL OR claimed_at < datetime('now','localtime','-"
            . (2 * PAT_MAIL_CLAIM_MINUTES) . " minutes'))")->fetchColumn();
        st_expect($haengt === 0, $haengt . ' Mail(s) hängen seit über '
            . (2 * PAT_MAIL_CLAIM_MINUTES) . ' Minuten im Versand – der nächste Cron-Lauf gibt sie '
            . 'normalerweise selbst wieder frei');

        $stuck = (int)pat_db()->query("SELECT COUNT(*) FROM pat_mailqueue WHERE status='failed'")->fetchColumn();
        st_expect($stuck === 0, $stuck . ' Einteilungs-Mail(s) konnten nicht zugestellt werden – in der Verwaltung erneut versuchen');

        // Einwilligung: ohne Text kein Häkchen – und ohne Häkchen keine Rechtsgrundlage
        foreach (['form_privacy_ersti', 'form_privacy_pate'] as $k) {
            st_expect(trim(pat_text($k)) !== '',
                'der Datenschutz-Text „' . $k . '" ist leer – dann fragt das Anmeldeformular keine '
                . 'Einwilligung mehr ab');
            st_expect(str_contains(pat_text($k), '{{MONATE}}'),
                'in „' . $k . '" fehlt {{MONATE}} – die Löschfrist gehört in die Einwilligung');
        }
        st_expect(trim(pat_text('form_privacy_error')) !== '',
            'ohne Meldung erfährt niemand, warum die Anmeldung ohne Häkchen nicht klappt');
        st_expect(preg_match('~^https?://~', trim(pat_setting_get('privacy_url', ''))) === 1,
            'es ist keine Datenschutzerklärung hinterlegt (Kontakt & Rechtliches) – im '
            . 'Einwilligungstext bleibt dann nur das Wort ohne Link stehen');

        // Zeitplan: was auf der Studi-Seite steht, muss zur Wirklichkeit passen
        st_expect(function_exists('pat_round_set_schedule') && function_exists('pat_schedule_items')
            && function_exists('pat_round_vars') && function_exists('pat_valid_date'),
            'Zeitplan-Funktionen müssen vorhanden sein');
        st_expect(!pat_valid_date('2026-02-30'), 'der 30. Februar darf nicht als Datum durchgehen');
        st_expect(pat_valid_date('2026-10-15'), 'ein gültiges Datum muss akzeptiert werden');
        // Nur mit id 0 prüfen: der Selbsttest darf keine echten Programme verändern.
        st_expect(!pat_round_set_schedule(0, '', '', '')['ok'], 'Zeitplan für unbekanntes Programm muss abgelehnt werden');
        // Ein verstrichener Anmeldeschluss bei offener Anmeldung ist KEIN Defekt: Das Schließen
        // ist bewusst ein Klick und kein Automatismus, und manchmal lässt man eben noch jemanden
        // rein. Als roter Fehler gemeldet hätte es zweierlei angerichtet – es sähe aus, als wäre
        // etwas kaputt, und es hätte alle folgenden Prüfungen dieses Blocks abgewürgt (st_expect
        // wirft). Deshalb wandert es als Notiz an die Zeile; gewarnt wird ohnehin dort, wo man
        // etwas tun kann: in der Verwaltung des Programms.
        $ueberfaellig = [];
        foreach (pat_rounds_all() as $rr) {
            if (pat_signup_overdue($rr)) $ueberfaellig[] = pat_round_label($rr) . ' (Schluss ' . pat_date((string)$rr['signup_to']) . ')';
        }

        // Absagen: eigene Vorlagen, eigene Mail-Art – dürfen die Einteilungs-Mails nicht verdrängen
        st_expect(function_exists('pat_rejection_lists') && function_exists('pat_mail_queue_rejections')
            && function_exists('pat_mail_forget_queued'), 'Absage-Funktionen müssen vorhanden sein');
        st_expect(!pat_mail_queue_rejections(0)['ok'], 'Absagen für ein unbekanntes Programm müssen abgelehnt werden');
        st_expect(!pat_mail_queue_rejections(1, 'quatsch')['ok'], 'unbekannte Rolle muss auch bei Absagen abgelehnt werden');
        foreach (['mail_rej_ersti_body', 'mail_rej_pate_body'] as $k) {
            st_expect(str_contains(pat_text($k), '{{VORNAME}}'), 'in der Absage-Vorlage „' . $k . '" fehlt {{VORNAME}}');
        }

        // Kompromisse: die Beschriftung muss zur Grenze der Automatik passen
        st_expect(function_exists('pat_match_note') && function_exists('pat_match_overview'),
            'Funktionen zur Passgenauigkeit müssen vorhanden sein');
        st_expect(pat_match_note(PAT_MATCH_MIN - 1)['level'] === 'bad',
            'unterhalb der Automatik-Grenze muss „fachfremd" stehen');
        st_expect(pat_match_note(PAT_MATCH_MIN)['level'] !== 'bad',
            'auf der Automatik-Grenze darf nicht „fachfremd" stehen');

        // Weitere Fächer der Pat:innen: die Rangfolge wird hier NICHT an festen Zahlen geprüft
        // (die haben sich schon einmal verschoben), sondern am Verhalten. Drei Pat:innen, ein
        // Ersti mit Fach 11 im Studiengang 1 – die, die es als weiteres Fach studiert, muss
        // zwischen der exakten Übereinstimmung und dem bloßen Studiengangs-Treffer landen.
        st_expect(function_exists('pat_extras_choices') && function_exists('pat_signup_extras_set')
            && function_exists('pat_signup_extra_labels'), 'die Funktionen für weitere Fächer müssen vorhanden sein');
        $mkKey = fn (int $cid, array $extra = []) => ['cid' => $cid, 'top' => 1, 'subj' => 'lehramt',
            'deg' => 'bachelor', 'extra' => array_fill_keys($extra, true)];
        $eK   = $mkKey(11);
        $sEx  = pat_match_score_keys($eK, $mkKey(11));        // studiert genau dieses Fach
        $sZw  = pat_match_score_keys($eK, $mkKey(12, [11]));  // anderes Erstfach, aber auch dieses
        $sStg = pat_match_score_keys($eK, $mkKey(12));        // nur derselbe Studiengang
        st_expect($sEx > $sZw && $sZw > $sStg,
            'Rangfolge kaputt: exakt (' . $sEx . ') muss über Zweitfach (' . $sZw
            . ') und das über den bloßen Studiengang (' . $sStg . ') stehen');
        st_expect(pat_match_note($sEx)['text'] === '', 'die exakte Zuteilung darf keinen Kompromiss-Hinweis tragen');
        st_expect(pat_match_note($sZw)['text'] !== '' && pat_match_note($sZw)['level'] !== 'bad',
            'ein Treffer über ein weiteres Fach braucht einen Hinweis, gilt aber nicht als fachfremd');
        st_expect($sStg >= PAT_MATCH_MIN, 'der gleiche Studiengang muss weiterhin für die Automatik reichen');
        // Ohne angebotene Fächer muss die Bewertung dieselbe sein wie ohne diese Stufe.
        st_expect(pat_match_score_keys($mkKey(11), $mkKey(11)) === $sEx
            && pat_match_score_keys($mkKey(11), $mkKey(12)) === $sStg,
            'ohne weitere Fächer muss die Bewertung unverändert bleiben');

        // Studiengangsgrenze: zur Auswahl stehen NUR Geschwister-Einträge, nie etwas Fremdes.
        foreach (pat_courses(null, false) as $stg) {
            $kinder = pat_course_children((int)$stg['id'], false);
            if (!$kinder) continue;
            $wahl = pat_extras_choices(['course_id' => (int)$kinder[0]['id'], 'course_parent_id' => 0]);
            $fremd = array_filter($wahl, fn ($k) => (int)$k['parent_id'] !== (int)$stg['id']);
            st_expect(!$fremd, 'die weiteren Fächer dürfen nur Unterpunkte desselben Studiengangs sein – '
                . 'bei „' . $stg['name'] . '" steht etwas Fremdes zur Wahl');
            st_expect(!array_filter($wahl, fn ($k) => (int)$k['id'] === (int)$kinder[0]['id']),
                'das eigene Fach darf nicht noch einmal als weiteres Fach zur Wahl stehen');
            break;
        }
        // Nur Pat:innen bekommen den Schritt – Erstsemester dürfen gar nicht gefragt werden.
        $patAnm = (string)@file_get_contents(__DIR__ . '/../pat/anmeldung.php');
        if ($patAnm !== '') {
            st_expect(str_contains($patAnm, '!$isErsti && $picked'),
                'der Schritt „weitere Fächer" muss an die Rolle gebunden bleiben (nur Pat:innen)');
            st_expect(str_contains($patAnm, "name=\"xd\""),
                'ohne den Merker xd käme der Schritt „weitere Fächer" nach dem Überspringen immer wieder');
        }

        // Absender/Antwortadresse: Antworten der Studierenden müssen bei den Verantwortlichen ankommen
        st_expect(function_exists('pat_mail_sender') && function_exists('mail_header_name')
            && function_exists('mail_header_safe'), 'Absender-Funktionen müssen vorhanden sein');
        $snd = pat_mail_sender(mail_from());
        st_expect(filter_var($snd['from'], FILTER_VALIDATE_EMAIL) !== false,
            'die Absender-Adresse der Einteilungs-Mails ist keine gültige Mailadresse: ' . $snd['from']);
        st_expect(filter_var($snd['reply'], FILTER_VALIDATE_EMAIL) !== false,
            'die Antwortadresse der Einteilungs-Mails ist keine gültige Mailadresse: ' . $snd['reply']);
        st_expect(trim((string)pat_setting_get('paten_email', '')) !== '',
            'es ist keine Paten-Mail hinterlegt – Antworten auf die Einteilungs-Mails landen im '
            . 'allgemeinen App-Postfach (' . mail_from() . ') statt bei den Verantwortlichen');
        // Kopfzeilen dürfen sich nicht auseinandernehmen lassen (Header-Injection)
        st_expect(mail_header_safe("a@b.de\r\nBcc: x@y.de") === 'a@b.de', 'Zeilenumbrüche müssen aus Kopfzeilen fliegen');
        st_expect(!str_contains(mail_header_name(pat_text('mail_from_name')), "\n"),
            'der Absender-Name darf keinen Zeilenumbruch enthalten');
        // Auch bei gleicher Domain bleibt der Absender die App-Adresse (siehe mail_pool_absender()).
        st_expect($snd['from'] === mail_from(),
            'die Einteilungs-Mails würden aus ' . $snd['from'] . ' statt aus ' . mail_from() . ' verschickt');

        // Löschfristen: fällige Programme werden komplett abgeräumt (Cron). Hier NUR prüfen, nicht löschen.
        st_expect(function_exists('pat_purge_due') && function_exists('pat_delete_days_left')
            && function_exists('pat_round_set_delete_after'), 'Löschfrist-Funktionen müssen vorhanden sein');
        st_expect(!pat_round_set_delete_after(0, '2027-01-01'), 'Löschfrist für unbekanntes Programm muss abgelehnt werden');
        st_expect(pat_delete_days_left(['delete_after' => null]) === null, 'ohne Frist darf kein Countdown entstehen');
        st_expect(pat_delete_days_left(['delete_after' => date('Y-m-d')]) === 0, 'heute fällig muss 0 Tage ergeben');
        foreach (pat_rounds_all() as $rr) {
            $dl = pat_delete_days_left($rr);
            if ($dl === null && (string)$rr['status'] !== 'draft') {
                st_expect(false, 'Programm „' . pat_round_label($rr) . '" hat KEINE Löschfrist – Personendaten blieben dauerhaft gespeichert');
            }
            if ($dl !== null && $dl < 0 && (string)$rr['status'] === 'open') {
                st_expect(false, 'Programm „' . pat_round_label($rr) . '" ist überfällig, aber noch offen – Löschung greift erst nach dem Schließen');
            }
        }

        // Text-Register: es speist die öffentliche Seite UND das Verwaltungsformular
        $groups = pat_text_groups();
        foreach (pat_text_fields() as $fk => $fd) {
            st_expect(isset($groups[$fd['group'] ?? '']), 'Textfeld „' . $fk . '" zeigt auf einen unbekannten Bereich');
            st_expect(in_array($fd['type'] ?? '', ['line', 'text', 'tile'], true), 'Textfeld „' . $fk . '" hat einen unbekannten Typ');
            st_expect(trim((string)($fd['label'] ?? '')) !== '', 'Textfeld „' . $fk . '" braucht ein Label für die Verwaltung');
            st_expect(trim((string)($fd['default'] ?? '')) !== '', 'Textfeld „' . $fk . '" braucht einen Standardtext');
        }
        st_expect(str_contains(pat_text('invite_body'), '{{LINK}}'), 'die Rundmail-Vorlage muss {{LINK}} enthalten');
        st_expect(pat_text_fill('x {{PROGRAMM}}', ['{{PROGRAMM}}' => 'SoSe 2027']) === 'x SoSe 2027', 'Platzhalter müssen ersetzt werden');
        // „leer gespeichert" (Element weglassen) muss von „nichts gespeichert" (Standard) unterscheidbar bleiben
        st_expect(function_exists('pat_setting_raw') && function_exists('pat_setting_delete'),
            'Rohwert-Zugriff und Löschen einer Einstellung müssen vorhanden sein (sonst wäre „leer = ausblenden" nicht von „Standard" zu trennen)');
        st_expect(pat_setting_raw('__gibt_es_nicht__') === null, 'nicht gespeicherte Einstellung muss null liefern');
        // Kachel-Aufteilung: erste Zeile Einleitung, weitere Zeilen Punkte
        $tile = pat_tile_text('tile_ersti');
        st_expect($tile['lead'] !== '' && count($tile['points']) >= 1,
            'die Erstsemester-Kachel braucht Einleitungssatz und mindestens einen Punkt');

        // Hilfe-Formular: die Prüfungen laufen bewusst nur über die ABLEHNENDEN Wege – eine
        // gültige Frage würde einen echten Datensatz anlegen, und der Selbsttest schreibt nicht
        // in den Bestand.
        st_expect(function_exists('pat_message_add') && function_exists('pat_messages_pending')
            && function_exists('pat_message_mark') && function_exists('pat_messages_prune'),
            'die Funktionen für das Hilfe-Formular müssen vorhanden sein');
        st_expect(!pat_message_add(['email' => '', 'body' => str_repeat('a', 20)])['ok'],
            'Frage ohne Adresse muss abgelehnt werden – sonst könnte niemand antworten');
        st_expect(!pat_message_add(['email' => 'kaputt', 'body' => str_repeat('a', 20)])['ok'],
            'Frage mit unbrauchbarer Adresse muss abgelehnt werden');
        st_expect(!pat_message_add(['email' => 'a@b.de', 'body' => 'kurz'])['ok'],
            'zu kurze Frage muss abgelehnt werden');
        st_expect(!pat_message_add(['email' => 'a@b.de', 'body' => str_repeat('a', 5001)])['ok'],
            'übermäßig lange Frage muss abgelehnt werden');
        $msgSpalten = array_column(pat_db()->query('PRAGMA table_info(pat_messages)')->fetchAll(), 'name');
        foreach (['round_id', 'name', 'email', 'body', 'status', 'tries', 'sent_at'] as $sp) {
            st_expect(in_array($sp, $msgSpalten, true), 'pat_messages fehlt die Spalte „' . $sp . '"');
        }

        // Der Hilfe-Dialog schickt per fetch. Die öffentlichen Seiten laufen unter einer CSP mit
        // default-src 'none' – ohne connect-src wäre der Versand still blockiert, und zwar erst
        // im Browser der Studis. Deshalb hier festgenagelt.
        $patLib = (string)@file_get_contents(__DIR__ . '/../pat/pat-lib.php');
        if ($patLib !== '') {
            st_expect(!str_contains($patLib, 'fetch(') || str_contains($patLib, "connect-src 'self'"),
                'pat-lib.php schickt per fetch, aber die CSP erlaubt kein connect-src – der '
                . 'Hilfe-Dialog käme im Browser nicht durch');
            st_expect(str_contains($patLib, 'function pat_help_back'),
                'das Rückkehr-Ziel des Hilfe-Formulars braucht seinen Filter (sonst offene Weiterleitung)');
        }

        // Konfetti auf der Danke-Seite: die Schnipsel sind leere Elemente, Winkel und Farbe
        // stehen je Schnipsel in pat.css. Kommt eins dazu (oder fällt eins weg), ohne dass die
        // andere Seite mitzieht, fliegen Schnipsel farblos und ohne Richtung los. Beides sind
        // Dateien, keine Funktionen – deshalb hier schlicht nachgezählt statt ausgeführt.
        // (pat/pat-lib.php wird bewusst NICHT geladen: die beiden Welten bleiben getrennt.)
        $patCss  = (string)@file_get_contents(__DIR__ . '/../assets/pat.css');
        $patHtml = (string)@file_get_contents(__DIR__ . '/../pat/anmeldung.php');
        if ($patCss !== '' && $patHtml !== '') {
            preg_match("~str_repeat\('<i></i>',\s*(\d+)\)~", $patHtml, $mKonf);
            $schnipsel = (int)($mKonf[1] ?? 0);
            $regeln    = (int)preg_match_all('~\.pat-konfetti i:nth-child\(\d+\)~', $patCss);
            st_expect($schnipsel > 0 && $schnipsel === $regeln,
                'Konfetti: ' . $schnipsel . ' Schnipsel in anmeldung.php, aber ' . $regeln
                . ' Regeln in pat.css – ohne Regel hat ein Schnipsel weder Winkel noch Farbe');
        }

        $rounds = pat_rounds_all();
        $open   = array_values(array_filter($rounds, fn($r) => $r['status'] === 'open'));
        $note   = count($rounds) . ' Programm(e) · ' . count(pat_courses()) . ' Studiengänge';
        if ($open) {
            $note .= ' · offen: ' . pat_round_label($open[0]);
            st_expect(pat_public_url((string)$open[0]['slug']) !== '',
                'für ein offenes Programm muss ein Studi-Link gebaut werden können (Basis-URL in den Einstellungen)');
        }
        if (count($open) > 1) $note .= ' (Achtung: ' . count($open) . ' gleichzeitig offen)';
        $offeneFragen = pat_messages_open_count();
        if ($offeneFragen > 0) $note .= ' · ' . $offeneFragen . ' unbeantwortete Frage(n)';
        if ($ueberfaellig) $note .= ' · Anmeldeschluss verstrichen, Anmeldung noch offen: ' . implode(', ', $ueberfaellig);
        return $note;
    });

    foreach (mail_templates() as $k => $d) {
        $run('Mailvorlage „' . $d['label'] . '" (Platzhalter vollständig)', function () use ($k, $d) {
            $text = mail_tpl_subject($k) . "\n" . mail_tpl_body($k);
            $missing = [];
            foreach (array_keys($d['vars'] ?? []) as $ph) {
                if (strpos($text, $ph) === false) $missing[] = $ph;
            }
            st_expect(!$missing, 'in der gespeicherten Vorlage fehlen: ' . implode(', ', $missing));
        });
    }
}

// ---- Log einlesen (neueste zuerst) ----
$lines = is_file(ERROR_LOG_FILE) ? array_filter(array_map('rtrim', (array)@file(ERROR_LOG_FILE))) : [];
$lines = array_reverse($lines);
$shown = array_slice($lines, 0, 300);

page_header('Diagnose & Fehler-Log', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-bug" style="color:var(--petrol)"></i> Diagnose &amp; Fehler-Log</h1>
  <a class="btn" href="errorlog.php?selftest=1"><i class="ti ti-stethoscope"></i> Selbsttest ausführen</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-send"></i> Zustell-Tests</div>
  <p class="small muted" style="margin-top:0">Prüfen die <strong>echte Strecke bis zu dir</strong> – das kann kein Selbsttest beweisen: eine Testmail bzw. ein Test-Push an dein eigenes Konto.</p>
  <div class="btn-row">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_mail"><button class="btn secondary small" type="submit"><i class="ti ti-mail-fast"></i> Testmail an mich</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_push"><button class="btn secondary small" type="submit"><i class="ti ti-device-mobile-message"></i> Test-Push an mich</button></form>
  </div>
  <?php if (!current_member()): ?><p class="small muted" style="margin:.5rem 0 0"><i class="ti ti-info-circle"></i> Im Technik-Login nicht möglich – bitte mit persönlichem Konto anmelden.</p><?php endif; ?>
</div>

<?php if (royal_test_allowed()): // Design-/Achievement-Testwerkzeuge – nur Admin-Rolle & Technik-Login ?>
<div class="card" id="testwerkzeuge" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-flask"></i> Test-Werkzeuge (Achievements &amp; Design)</div>
  <p class="small muted" style="margin-top:0">Alles nur zum Ausprobieren – <strong>temporär, nur für deine Login-Sitzung auf diesem Gerät</strong>, ohne echte Achievements zu vergeben.</p>

  <?php
    // ---- Zum Ausprobieren: Sachen, die im Alltag an Ereignissen hängen, die es gerade nicht gibt ----
    $meTst   = current_member();
    $telTest = $meTst ? member_phone($meTst) : '';
    $wwSt = db()->prepare('SELECT COUNT(*) FROM wegweiser WHERE grp = ?');
    $wwSt->execute([WW_DEMO_GRP]);
    $wwDemo = (int)$wwSt->fetchColumn();
  ?>
  <div class="section-title" id="ausprobieren" style="font-size:1.02rem"><i class="ti ti-confetti"></i> Feier-Momente ansehen</div>
  <p class="small muted" style="margin-top:0">Die Effekte hängen sonst an einer Zusage, einer Abstimmung oder einem eingereichten Beleg – in der vorlesungsfreien Zeit passiert davon nichts. Diese Knöpfe spielen sie <strong>an sich selbst</strong> ab, genau wie später der echte Knopf: kein Neuladen, kein Umweg über den Server.</p>
  <div class="btn-row">
    <?php foreach (puste_momente(true) as $mKey => $mLabel): ?>
      <button class="btn secondary small" type="button" data-puste="<?= h($mKey) ?>" title="<?= h($mLabel) ?>"><i class="ti ti-player-play"></i> <?= h($mKey) ?></button>
    <?php endforeach; ?>
  </div>
  <p class="small muted" style="margin:.6rem 0 0">Das <strong>Belegblatt</strong> geht bewusst weiter über den Server: Dort hängen Datei-Uploads am Formular, und ohne die Fortschrittsanzeige des Browsers sähe ein langer Upload aus wie ein Absturz. Dieser Knopf zeigt diesen zweiten Weg – Seite lädt neu, Effekt läuft danach an der Vorschau-Karte.</p>
  <div class="btn-row" style="margin-top:.4rem">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_puste"><input type="hidden" name="moment" value="beleg">
      <button class="btn secondary small" type="submit"><i class="ti ti-refresh"></i> Server-Weg (Belegblatt)</button></form>
  </div>
  <div class="card<?= puste_klasse('beleg') ?>" id="vorschau" style="margin:.7rem 0 0;text-align:center">
    <p class="small muted" style="margin:0"><i class="ti ti-target-arrow"></i> Vorschau-Karte für den Server-Weg.<?= puste_moment() !== '' ? ' <strong>Gerade: „' . h(puste_moment()) . '"</strong>' : '' ?></p>
  </div>
  <p class="small muted" style="margin:.5rem 0 0"><i class="ti ti-heart"></i> Das <strong>Herzchen im Seitenfuß</strong> braucht keinen Knopf: einfach unten mit der Maus darüberfahren.</p>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-phone"></i> Telefonnummer-Dialog</div>
  <p class="small muted" style="margin-top:0">Um den Knopf auf einem <em>fremden</em> Profil zu sehen, muss dort eine Nummer hinterlegt sein. Statt bei Kolleg:innen herumzuschreiben, trägt das hier eine Test-Nummer in <strong>dein eigenes</strong> Profil ein – der Knopf darunter ist derselbe wie auf fremden Profilen und geht denselben Weg (Dialog → Zustimmung → Nummer nachladen).</p>
  <?php if (!$meTst): ?>
    <p class="small" style="margin:0"><i class="ti ti-info-circle" style="color:var(--amber)"></i> Mit dem Technik-Login gibt es kein Profil – dafür braucht es ein persönliches Konto.</p>
  <?php elseif ($telTest === ''): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_phone_on"><button class="btn small" type="submit"><i class="ti ti-phone-plus"></i> Test-Nummer eintragen</button></form>
  <?php else: ?>
    <p class="small" style="margin:0 0 .5rem"><span class="pill pill-ok"><i class="ti ti-check"></i> eingetragen</span> <code><?= h($telTest) ?></code> – sichtbar in <a href="../profil.php#telefon">deinem Profil</a>.</p>
    <div class="btn-row">
      <button type="button" class="btn secondary small profil-tel" data-tel-id="<?= (int)$meTst['id'] ?>"
              data-tel-name="<?= h(first_name((string)$meTst['name'])) ?>" data-tel-note="<?= h(member_phone_note($meTst)) ?>">
        <i class="ti ti-phone"></i> Dialog ausprobieren
      </button>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_phone_off"><button class="btn secondary small" type="submit"><i class="ti ti-phone-off"></i> Test-Nummer entfernen</button></form>
    </div>
  <?php endif; ?>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-directions"></i> Wegweiser-Suche</div>
  <p class="small muted" style="margin-top:0">Die Suchleiste erscheint erst, wenn es überhaupt Einträge gibt. Diese vier Beispiele reichen zum Ausprobieren – tipp im <a href="../wegweiser.php">Wegweiser</a> etwas ein, das es nicht gibt, dann kippt die Lupe ratlos zur Seite.</p>
  <div class="btn-row">
    <?php if ($wwDemo === 0): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_ww_demo"><button class="btn small" type="submit"><i class="ti ti-database-plus"></i> Beispiel-Einträge anlegen</button></form>
    <?php else: ?>
      <span class="small"><span class="pill pill-ok"><i class="ti ti-check"></i> <?= $wwDemo ?> angelegt</span></span>
      <a class="btn secondary small" href="../wegweiser.php"><i class="ti ti-external-link"></i> Wegweiser öffnen</a>
      <form method="post" data-confirm="Die <?= $wwDemo ?> Beispiel-Einträge wieder entfernen? Echte Einträge bleiben unberührt." data-confirm-ok="Entfernen">
        <?= csrf_field() ?><input type="hidden" name="action" value="test_ww_purge"><button class="btn secondary small" type="submit"><i class="ti ti-trash"></i> Beispiele entfernen</button></form>
    <?php endif; ?>
  </div>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-crown"></i> Royal-Design</div>
  <?php if (royal_test_active()): ?>
    <p class="small" style="margin-top:0"><span class="pill pill-spitze"><i class="ti ti-crown"></i> aktiv</span> Royal steht im Design-Umschalter (Glühbirne) zur Wahl.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="royal_test_off"><button class="btn secondary small" type="submit"><i class="ti ti-crown-off"></i> Royal-Test beenden</button></form>
  <?php else: ?>
    <p class="small muted" style="margin-top:0">Schaltet den Royal-Farbmodus (Spitzenklasse-Belohnung) zum Testen frei – ohne echte Spitzenklasse.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="royal_test_on"><button class="btn small" type="submit"><i class="ti ti-crown"></i> Royal-Modus freischalten</button></form>
  <?php endif; ?>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-hand-love-you"></i> Props-Animation ansehen</div>
  <p class="small muted" style="margin-top:0">Spielt die Einblendung <strong>„Props sind wieder verfügbar"</strong> einmal ab – die sonst nur
    beim ersten Öffnen im neuen Monat kommt. Der Monats-Merker bleibt unberührt, die echte Anzeige geht dadurch nicht verloren.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="props_demo"><button class="btn small" type="submit"><i class="ti ti-player-play"></i> Animation abspielen</button></form>
  <?php /* Warum lief sie (nicht)? Vier Bedingungen, hier nur GELESEN – das Nachschauen darf den
           Monats-Merker nicht verbrauchen, sonst wäre die echte Anzeige weg. */
    $ppSt = props_reset_status((int)(current_member()['id'] ?? 0));
    if ($ppSt): $ppAlle = array_reduce($ppSt, fn($t, $c) => $t && $c['ok'], true); ?>
    <p class="small" style="margin:.7rem 0 .2rem"><strong>Würde sie bei dir beim nächsten Öffnen laufen?</strong>
      <?= $ppAlle ? '<span class="pill pill-ok"><i class="ti ti-check"></i> ja</span>' : '<span class="pill pill-warn"><i class="ti ti-x"></i> nein</span>' ?></p>
    <ul class="small muted" style="margin:.2rem 0 0;padding-left:1.1rem">
      <?php foreach ($ppSt as $c): ?>
        <li><i class="ti <?= $c['ok'] ? 'ti-check' : 'ti-x' ?>" style="color:var(--<?= $c['ok'] ? 'green' : 'red' ?>)"></i>
          <?= h($c['label']) ?> <span class="muted">(<?= h($c['info']) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
    <p class="small muted" style="margin:.3rem 0 0">Der Merker wird beim <strong>ersten Seitenaufruf im Monat</strong> gesetzt – auch dann, wenn nichts gezeigt wird. Nach einem Monatswechsel steht hier also nur bis zum ersten Aufruf „ja".</p>
  <?php endif; ?>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-gift"></i> Alle Belohnungen freischalten</div>
  <?php if (test_unlock_all()): ?>
    <p class="small" style="margin-top:0"><span class="pill pill-spitze"><i class="ti ti-check"></i> aktiv</span> Avatar-Schmuck, Avatar-Farben, Skins <strong>und Streak-Stile</strong> (auch die kaufbaren) sind für dich freigeschaltet – auswählbar im <a href="../achievements.php">Belohnungs-Locker</a>, wo das oben auch angezeigt wird.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_unlock_off"><button class="btn secondary small" type="submit"><i class="ti ti-lock"></i> Freischaltung beenden</button></form>
  <?php else: ?>
    <p class="small muted" style="margin-top:0">Macht auf der Achievements-Seite alles auswählbar, ohne es erst zu verdienen oder zu kaufen: Avatar-Schmuck, Avatar-Farben, Skins und Streak-Stile. Gilt nur für diese Login-Sitzung und nur für dich.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_unlock_on"><button class="btn small" type="submit"><i class="ti ti-gift"></i> Alle Belohnungen freischalten</button></form>
  <?php endif; ?>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-bell-ringing"></i> Achievement-Toasts testen</div>
  <p class="small muted" style="margin-top:0">Löst einen Vorschau-Toast aus (vergibt kein echtes Achievement). „Ausprobieren" im Toast funktioniert, wenn oben „Alle Belohnungen" aktiv ist.</p>
  <div class="btn-row">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_toast"><input type="hidden" name="variant" value="simple"><button class="btn secondary small" type="submit"><i class="ti ti-trophy"></i> Schlicht</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_toast"><input type="hidden" name="variant" value="reward"><button class="btn secondary small" type="submit"><i class="ti ti-sparkles"></i> Mit Belohnung</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_toast"><input type="hidden" name="variant" value="auto"><button class="btn secondary small" type="submit"><i class="ti ti-crown"></i> Royal-Look</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_toast"><input type="hidden" name="variant" value="all"><button class="btn secondary small" type="submit"><i class="ti ti-stack-2"></i> Alle drei</button></form>
  </div>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-flame"></i> Streak-Animation testen</div>
  <p class="small muted" style="margin-top:0">Spielt die „neue Flamme"-Vollbild-Animation ab (ändert keine echte Streak). 2 = Glut (brennt noch nicht), 5 = normales +1; 3/7/30/50/100 sind Meilensteine mit Flammen-Verwandlung. Bei 100 tritt der Stil aus seiner Kachel heraus und strahlt ins ganze Bild – im Alltag trägt dann auch die Begrüßungskarte diese Aura.</p>
  <div class="btn-row">
    <?php foreach ([2, 5, 3, 7, 30, 50, 100] as $d): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_streak"><input type="hidden" name="days" value="<?= $d ?>"><button class="btn secondary small" type="submit"><i class="ti ti-flame"></i> <?= $d ?> Tage<?= in_array($d, [3, 7, 30, 50, 100], true) ? ' ✨' : '' ?></button></form>
    <?php endforeach; ?>
  </div>
  <?php // Die Aura im Alltag (Begrüßungskarte, Streak-Karte) sieht man sonst nur mit echter
        // Streak ab 100 – und die über „Streaks anpassen" zu setzen, wäre eine Falle: Der
        // Bestwert steigt mit und vergibt dauerhaft die Streak-Achievements samt Belohnungen.
        // Dieser Schalter fasst die Datenbank nicht an. ?>
  <?php if (test_streak_aura()): ?>
    <p class="small" style="margin:.6rem 0 .3rem"><i class="ti ti-flask"></i> Die <strong><?= test_streak_aura() === 4 ? '50' : '100' ?>-Tage-Stufe wird gerade vorgeführt</strong> – Begrüßungskarte und Streak-Karte tragen die Aura deines Stils, obwohl deine Streak bei <?= (int)member_streak((int)($_SESSION['member_id'] ?? 0))['current'] ?> steht.</p>
    <div class="btn-row">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_aura_on"><input type="hidden" name="stufe" value="<?= test_streak_aura() === 4 ? 5 : 4 ?>"><button class="btn secondary small" type="submit"><i class="ti ti-arrows-exchange"></i> Zur <?= test_streak_aura() === 4 ? '100' : '50' ?>er-Stufe wechseln</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_aura_off"><button class="btn secondary small" type="submit"><i class="ti ti-eye-off"></i> Vorführung beenden</button></form>
    </div>
  <?php else: ?>
    <p class="small muted" style="margin:.6rem 0 .3rem">Ab 50 Tagen greift der Streak-Stil leise auf die Begrüßungskarte über, ab 100 dann voll. Zum Ansehen <strong>nicht</strong> die eigene Streak hochsetzen – das hebt den Bestwert mit an und vergibt dauerhaft „Woche am Ball", „Durchhalter" und „Unaufhaltsam" samt Hüten, Paletten und Sunset-Skin. Diese Schalter ändern nur die Anzeige, nur für dich, nur für diese Sitzung.</p>
    <div class="btn-row">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_aura_on"><input type="hidden" name="stufe" value="4"><button class="btn secondary small" type="submit"><i class="ti ti-sparkles"></i> 50-Tage-Stufe vorführen</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_aura_on"><input type="hidden" name="stufe" value="5"><button class="btn secondary small" type="submit"><i class="ti ti-sparkles"></i> 100-Tage-Stufe vorführen</button></form>
    </div>
  <?php endif; ?>

  <?php
    // Pat:innenprogramm-Testdaten: braucht ein OFFENES Programm mit Studiengängen.
    require_once __DIR__ . '/../pat-db.php';
    $patRounds = array_values(array_filter(pat_rounds_all(), fn ($r) => (string)$r['status'] === 'open'));
  ?>
  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-users-plus"></i> Pat:innenprogramm: Testdatensatz</div>
  <p class="small muted" style="margin-top:0">Füllt ein <strong>offenes</strong> Programm mit
    erfundenen Anmeldungen – aus den <strong>wirklich eingetragenen Studiengängen</strong>, zufällig
    verteilt, plus bewusst eingestreute Grenzfälle: Erstis in einem Fach ohne Pat:in, eine Pat:in
    ohne Erstis (leere Gruppe), frei eingetragene Studiengänge, Bachelor/Master über Kreuz, sehr
    lange Namen mit Umlauten und Apostroph, ein 2000-Zeichen-„Über mich", HTML/Script in Namen
    (Escaping-Probe) und dieselbe Adresse als Ersti <em>und</em> Pat:in.
    Alle Adressen enden auf <code><?= h(PAT_TEST_DOMAIN) ?></code> – eine
    <strong>nicht zustellbare</strong> Endung, dorthin kann selbst versehentlich keine Mail rausgehen.</p>
  <?php if (!$patRounds): ?>
    <p class="small" style="margin:0"><i class="ti ti-info-circle" style="color:var(--amber)"></i>
      Kein Programm mit offener Anmeldung – erst eines unter
      <a href="paten.php">Pat:innenprogramm</a> öffnen.</p>
  <?php else: ?>
    <?php foreach ($patRounds as $pr): $prId = (int)$pr['id']; $prTest = pat_test_count($prId); ?>
      <div class="pat-row">
        <span><strong><?= h(pat_round_label($pr)) ?></strong>
          <span class="small muted">· <?= (int)pat_signup_counts($prId)['ersti'] + (int)pat_signup_counts($prId)['pate'] ?> Anmeldungen<?= $prTest > 0 ? ', davon ' . $prTest . ' Testdaten' : '' ?></span></span>
        <span class="btn-row" style="gap:.4rem">
          <form method="post" data-confirm="300 Erstsemester und 55 Pat:innen als Testdaten in „<?= h(pat_round_label($pr)) ?>“ anlegen? Echte Anmeldungen bleiben unberührt, die Testdaten lassen sich hier auch wieder entfernen." data-confirm-ok="Anlegen">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pat_seed">
            <input type="hidden" name="round_id" value="<?= $prId ?>">
            <input type="hidden" name="erstis" value="300">
            <input type="hidden" name="pates" value="55">
            <button class="btn secondary small" type="submit"><i class="ti ti-database-plus"></i> 300 Studis anlegen</button>
          </form>
          <?php if ($prTest > 0): ?>
            <form method="post" data-confirm="Alle <?= $prTest ?> Testanmeldungen aus „<?= h(pat_round_label($pr)) ?>“ entfernen?" data-confirm-danger data-confirm-ok="Entfernen">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="pat_seed_purge">
              <input type="hidden" name="round_id" value="<?= $prId ?>">
              <button class="btn danger small" type="submit"><i class="ti ti-database-minus"></i> Testdaten entfernen</button>
            </form>
          <?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php // ---- was.läuft: Testdatensatz für das Veranstaltungsportal ----
require_once __DIR__ . '/../wl-db.php';
wl_db();
$wlDemo  = wl_demo_counts();
$wlEcht  = max(0, (int)wl_db()->query('SELECT COUNT(*) FROM items WHERE demo = 0')->fetchColumn());
$wlBasis = trim(wl_setting('base_url', ''));
?>
<div class="section-title"><i class="ti ti-calendar-star"></i> was.läuft: Testdatensatz</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Eine leere Veranstaltungsseite lässt sich nicht beurteilen.
    Dieser Datensatz füllt sie mit <strong>allem, was vorkommen kann</strong>: jede Kategorie, beide
    Inhaltstypen, alle vier Veranstalter-Arten, mit und ohne Bild, kostenlos und kostenpflichtig,
    hervorgehoben im Karussell, ein laufender und ein künftiger Banner, zwei wartende Beiträge
    (einer mit <strong>ungeklärten Bildrechten</strong>, damit man den Warnhinweis in der Freigabe
    sieht, der andere <strong>nur für Studierende</strong>, damit man das Etikett dort sieht),
    ein abgelehnter und ein vergangener. Dazu Grenzfälle: sehr langer Titel, offenes Ende,
    Altersgrenze, Studi-Rabatt, nur für Studierende, Termin heute, Termin am Wochenende.</p>

  <p class="small muted">Beim Anlegen laufen echte Bilder durch die echte Verarbeitung. Wer den Satz
    <strong>auf dem Server</strong> erzeugt, weiß danach, ob Bild-Uploads dort funktionieren –
    aktuell: <strong><?= h(wl_image_engine_label()) ?></strong>.</p>

  <?php if (array_sum($wlDemo) > 0): ?>
    <p class="note wl-adm-warn small"><i class="ti ti-alert-triangle"></i>
      <strong>Gerade liegen Testdaten auf der öffentlichen Seite:</strong>
      <?= (int)$wlDemo['items'] ?> Beiträge, <?= (int)$wlDemo['orgs'] ?> Veranstalter,
      <?= (int)$wlDemo['images'] ?> Bilder, <?= (int)$wlDemo['banners'] ?> Banner.
      Vor dem Echtbetrieb bitte entfernen.</p>
  <?php endif; ?>
  <?php if ($wlEcht > 0): ?>
    <p class="small muted">Daneben stehen <strong><?= $wlEcht ?> echte Beiträge</strong> im Portal –
      die bleiben von beiden Knöpfen unberührt.</p>
  <?php endif; ?>

  <div class="btn-row" style="flex-wrap:wrap">
    <form method="post" data-confirm="Testdatensatz anlegen? Die Beiträge stehen danach ÖFFENTLICH auf was.läuft – zum Ausprobieren gedacht und hier jederzeit wieder entfernbar. Ein vorhandener Testdatensatz wird dabei ersetzt, echte Inhalte bleiben unberührt." data-confirm-ok="Anlegen">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="wl_seed">
      <button class="btn secondary" type="submit"><i class="ti ti-database-plus"></i> Testdatensatz anlegen</button>
    </form>
    <?php if (array_sum($wlDemo) > 0): ?>
      <form method="post" data-confirm="Alle Testdaten von was.läuft entfernen? Echte Beiträge, Veranstalter und Archivbilder bleiben." data-confirm-danger data-confirm-ok="Entfernen">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="wl_seed_purge">
        <button class="btn danger" type="submit"><i class="ti ti-database-minus"></i> Testdaten entfernen</button>
      </form>
    <?php endif; ?>
    <a class="btn secondary" href="../veranstaltungen.php"><i class="ti ti-settings"></i> Zur Verwaltung</a>
    <?php if ($wlBasis !== ''): ?>
      <a class="btn secondary" href="<?= h(rtrim($wlBasis, '/') . '/veranstaltungen/') ?>" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> Seite ansehen</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($doTest):
    $bad = array_filter($checks, fn($c) => $c[1] === false);
    $skipped = array_filter($checks, fn($c) => $c[1] === 'skip');
    $okCount = count($checks) - count($bad) - count($skipped); ?>
  <div class="section-title" style="margin-top:0"><i class="ti ti-stethoscope"></i> Selbsttest</div>
  <div class="card">
    <?php if (!$bad): ?>
      <p class="small" style="margin:0;color:var(--green)"><i class="ti ti-circle-check"></i> Alle <?= $okCount ?> ausgeführten Prüfungen ok<?= $skipped ? ' – ' . count($skipped) . ' mangels Daten übersprungen (unten grau)' : '' ?>.</p>
    <?php else: ?>
      <p class="small" style="margin:0 0 .5rem;color:var(--red)"><i class="ti ti-alert-triangle"></i> <?= count($bad) ?> von <?= $okCount + count($bad) ?> ausgeführten Prüfungen fehlgeschlagen:</p>
    <?php endif; ?>
    <ul class="sekki-tops" style="margin:.4rem 0 0">
      <?php foreach ($checks as [$label, $st, $msg, $ms]): ?>
        <li>
          <?php if ($st === 'skip'): ?>
            <span class="pill" style="font-size:.7rem;background:var(--petrol-soft);color:var(--muted)">übersprungen</span>
          <?php else: ?>
            <span class="pill <?= $st ? 'pill-info' : 'pill-bad' ?>" style="font-size:.7rem"><?= $st ? 'ok' : 'Fehler' ?></span>
          <?php endif; ?>
          <strong><?= h($label) ?></strong>
          <?php if ($msg !== ''): ?><span class="<?= $st === false ? 'small' : 'muted small' ?>"<?= $st === false ? ' style="color:var(--red)"' : '' ?>>· <?= h($msg) ?></span><?php endif; ?>
          <?php if ($st !== 'skip' && $ms >= 100): ?><span class="small" style="color:<?= $ms >= 500 ? 'var(--amber)' : 'var(--muted)' ?>">· <?= $ms ?> ms</span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-file-search"></i> Datei-Vorschau-Test (iOS/PWA)</div>
  <p class="small muted" style="margin-top:0">Vier winzige Testdateien, falls das Datei-Öffnen in der installierten iPhone-App wieder zickt (weiße Seite). Am iPhone in der <strong>installierten App</strong> antippen und merken, was jeweils erscheint: <strong>1. Session</strong> (kommt Text mit deinem Namen? Falls stattdessen die Login-Seite: das In-App-Fenster hat keine Session), <strong>2. Text</strong>, <strong>3. PDF</strong>, <strong>4. Word</strong>. Die Knöpfe zeigen bewusst das <strong>rohe Fenster-Verhalten</strong> ohne den Teilen-Blatt-Umweg – bei „Word" ist eine weiße Seite deshalb NORMAL (das In-App-Fenster kann Office nicht darstellen; echte Word-Links öffnen stattdessen das Teilen-Blatt). Auffällig wäre: Login-Seite bei 1 oder Weiß bei 2/3.</p>
  <div class="btn-row" style="flex-wrap:wrap">
    <a class="btn secondary small" href="../download.php?diag=session" target="_blank" rel="noopener"><i class="ti ti-user-check"></i> 1 · Session</a>
    <a class="btn secondary small" href="../download.php?diag=txt" target="_blank" rel="noopener"><i class="ti ti-file-text"></i> 2 · Text</a>
    <a class="btn secondary small" href="../download.php?diag=pdf" target="_blank" rel="noopener"><i class="ti ti-file-type-pdf"></i> 3 · PDF</a>
    <a class="btn secondary small" href="../download.php?diag=docx" target="_blank" rel="noopener"><i class="ti ti-file-type-docx"></i> 4 · Word</a>
  </div>
  <?php // Kontroll-Zähler für die Öffnungs-Achievements: zeigt, ob die app_open-Signale ankommen
    $meDiag = current_member();
    if ($meDiag) {
        $od = db()->prepare('SELECT opens_on, opens_count, last_seen_at FROM members WHERE id = ?');
        $od->execute([(int)$meDiag['id']]);
        $odr = $od->fetch() ?: [];
        $odCnt = (string)($odr['opens_on'] ?? '') === date('Y-m-d') ? (int)($odr['opens_count'] ?? 0) : 0; ?>
    <p class="small muted" style="margin:.7rem 0 0"><i class="ti ti-repeat"></i> <strong>App-Öffnungen heute (dein Konto):</strong> <?= $odCnt ?>
      <?= trim((string)($odr['last_seen_at'] ?? '')) !== '' ? '· zuletzt gezählt ' . h((string)$odr['last_seen_at']) : '· noch nie ein Signal angekommen' ?>
      – zählt hoch, wenn app.js beim App-Start/Sichtbarwerden <code>push.php?action=app_open</code> meldet. Bleibt die Zahl bei 0, kommt das Client-Signal nicht an (app.js-Stand am Gerät prüfen).
      Noch nicht gemeldete Öffnungen in der Warteschlange <strong>dieses Geräts</strong>: <span id="opens-pending">–</span>
      <script>try { document.getElementById('opens-pending').textContent = localStorage.getItem('asta-open-pending') || '0'; } catch (e) { document.getElementById('opens-pending').textContent = '?'; }</script></p>
  <?php } ?>
</div>

<div class="section-title"><i class="ti ti-list"></i> Fehler-Log <span class="count"><?= count($lines) ?></span></div>
<div class="card">
  <p class="small muted" style="margin-top:0">Hier landen Programmfehler (Fatals, Exceptions, Warnungen) mit Zeitpunkt und aufgerufener Seite. Die Datei liegt geschützt unter <code>data/error.log</code> und ist nicht über das Web abrufbar.</p>
  <?php if (!$shown): ?>
    <p class="small" style="margin:0;color:var(--green)"><i class="ti ti-check"></i> Das Log ist leer – aktuell keine Fehler aufgezeichnet.</p>
  <?php else: ?>
    <pre style="max-height:60vh;overflow:auto;background:var(--bg);color:var(--ink);border:1px solid var(--line);padding:.7rem;border-radius:10px;font-size:.8rem;line-height:1.5;white-space:pre-wrap;word-break:break-word"><?php foreach ($shown as $l) echo h($l) . "\n"; ?></pre>
    <?php if (count($lines) > count($shown)): ?><p class="small muted" style="margin:.3rem 0 0">(nur die neuesten <?= count($shown) ?> Einträge gezeigt)</p><?php endif; ?>
    <form method="post" data-confirm="Das gesamte Fehler-Log löschen?" data-confirm-ok="Leeren" style="margin-top:.6rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_log">
      <button class="btn secondary small" type="submit"><i class="ti ti-trash"></i> Log leeren</button>
    </form>
  <?php endif; ?>
</div>
<?php
page_footer();
