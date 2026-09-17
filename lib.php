<?php
/**
 * AStA-App
 * Zentrale Bibliothek: Bootstrap, Datenbank, Schema, Helfer, Layout.
 *
 * Eine einzige Datei, die jede Seite per require einbindet. Kein Framework,
 * kein Build-Schritt – läuft auf jedem PHP-8-Hosting (z. B. Mittwald).
 */

declare(strict_types=1);

// Zeitzone konsistent für PHP UND SQLite festlegen: date_default_timezone_set() steuert PHPs
// date()/DateTime, putenv('TZ=…') die C-Library, auf die sich SQLites date('now','localtime')
// stützt. Ohne TZ könnten beide am Server um Mitternacht einen Tag auseinanderliegen (Off-by-one
// bei Fristen/Stichtagen), falls das Host-OS nicht ohnehin auf Europe/Berlin steht.
putenv('TZ=Europe/Berlin');
date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

// Gemeinsames Mail-Konto: eigenständig und ohne Abhängigkeiten, damit der öffentliche
// Bereich (der die lib.php nie einbindet) denselben Kontostand sieht.
require_once __DIR__ . '/mail-pool.php';

// Marke dieser Installation (Logo, App-Symbol): ebenfalls eigenständig, denn die öffentlichen
// Bereiche zeigen dasselbe Logo und dürfen die lib.php nicht laden.
require_once __DIR__ . '/brand-core.php';

// ---------------------------------------------------------------------------
// Fehler-Logging: alles in eine geschützte Datei schreiben (nie im Web ausgeben).
// So lassen sich auch „stille" Fatals (z. B. undefinierte Funktion) nachvollziehen.
// ---------------------------------------------------------------------------
const ERROR_LOG_FILE = __DIR__ . '/data/error.log';
error_reporting(E_ALL);
@ini_set('display_errors', '0'); // Produktion: keine Details an Besucher:innen
@ini_set('log_errors', '0');     // wir loggen selbst (mit URL-Kontext)

/** Eine Zeile ins Fehler-Log schreiben (mit Zeitstempel + aufgerufener URL). */
function app_log_error(string $msg): void
{
    // Der Selbsttest reizt an einzelnen Stellen absichtlich einen Fehlerpfad aus – etwa einen
    // unbekannten Ort in app_place(), um den Ersatztext zu prüfen. Solche Proben dürfen das echte
    // Fehler-Log nicht füllen. Der Merker steht immer nur um den einen Aufruf herum (siehe
    // admin/errorlog.php).
    if (!empty($GLOBALS['ASTA_PROBE'])) return;
    $dir = dirname(ERROR_LOG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $where = PHP_SAPI === 'cli' ? 'cli' : (($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-'));
    @file_put_contents(ERROR_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . trim($msg) . ' | ' . trim($where) . "\n", FILE_APPEND | LOCK_EX);
    // Log nicht unbegrenzt wachsen lassen: bei >1 MB auf die letzten 500 Zeilen kürzen
    if (@filesize(ERROR_LOG_FILE) > 1048576) { $l = @file(ERROR_LOG_FILE); if ($l) @file_put_contents(ERROR_LOG_FILE, implode('', array_slice($l, -500))); }
}
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    if (in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT], true)) return true; // Rauschen ignorieren
    app_log_error('PHP-Warnung: ' . $str . ' in ' . $file . ':' . $line);
    return true;
});
set_exception_handler(function (\Throwable $e) {
    app_log_error('Unbehandelte Exception ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI !== 'cli') { http_response_code(500); echo '<p style="font-family:system-ui,sans-serif;padding:2rem">Es ist ein Fehler aufgetreten. Bitte später erneut versuchen – das Technik-Referat kann den Fehler im Log nachsehen.</p>'; }
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        app_log_error('FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

// ---------------------------------------------------------------------------
// Konfiguration
// ---------------------------------------------------------------------------
// Speicherort der SQLite-Datenbank. Liegt im geschützten data/-Ordner.
const DB_FILE = __DIR__ . '/data/asta.sqlite';

// Name der App (erscheint im Titel)
const APP_NAME = 'AStA-App';

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Vom Browser vorgegebene Sitzungs-Kennungen nicht übernehmen: Müll erzeugt sonst
    // Warnungen und eine leere Sitzung, und eine vorgegebene Kennung wäre angreifbar.
    @ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// Basis-URL-Präfix: Admin-Seiten liegen in /admin/ und setzen $GLOBALS['ASTA_BASE']='../'.
function base(): string { return $GLOBALS['ASTA_BASE'] ?? ''; }

/** Asset-URL mit Versions-Parameter (Dateizeit) gegen veraltete Browser-Caches. */
function asset_v(string $rel): string
{
    $v = @filemtime(__DIR__ . '/' . $rel);
    return $rel . ($v ? '?v=' . $v : '');
}

// ---------------------------------------------------------------------------
// Datenbank + Schema
// ---------------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit('Fehler: Die PHP-Erweiterung "pdo_sqlite" ist nicht aktiviert. '
            . 'Bitte im Mittwald-mStudio aktivieren oder auf MySQL umstellen (siehe README.md).');
    }
    $fresh = !file_exists(DB_FILE);
    if ($fresh && !is_dir(dirname(DB_FILE))) {
        mkdir(dirname(DB_FILE), 0775, true);
    }
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    // Gegen „database is locked", wenn Cron und Seitenaufruf gleichzeitig schreiben (ist live
    // passiert): WAL lässt Lesende neben einem Schreibenden arbeiten und beseitigt vor allem die
    // Sperr-Eskalation des Rollback-Journals, die SOFORT scheitert, ohne die Wartezeit zu fragen.
    // Dazu 4 s Geduld für den Rest. WAL legt asta.sqlite-wal/-shm neben die Datenbank – data/
    // ist per .htaccess komplett gesperrt, die Dateien gehören dort hin und bleiben liegen.
    $pdo->exec('PRAGMA busy_timeout = 4000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    init_schema($pdo);
    migrate_schema($pdo);
    return $pdo;
}

/** Spalten ergänzen, falls eine ältere Datenbank existiert. */
function migrate_schema(PDO $pdo): void
{
    $cols = function (string $table) use ($pdo): array {
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        return array_column($rows, 'name');
    };
    if (!in_array('email', $cols('members'), true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN email TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('deadline', $cols('events'), true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN deadline TEXT');
    }
    $memberCols = $cols('members');
    if (!in_array('is_admin', $memberCols, true)) {
        $pdo->exec('ALTER TABLE members ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('ical_token', $memberCols, true)) {
        $pdo->exec('ALTER TABLE members ADD COLUMN ical_token TEXT');
    }
    // iCal-Token für bestehende Mitglieder nachziehen
    foreach ($pdo->query("SELECT id FROM members WHERE ical_token IS NULL OR ical_token = ''")->fetchAll() as $r) {
        $pdo->prepare('UPDATE members SET ical_token = ? WHERE id = ?')
            ->execute([bin2hex(random_bytes(16)), (int)$r['id']]);
    }
    // Rollen-Spalte + Backfill aus is_admin
    if (!in_array('role', $cols('members'), true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN role TEXT NOT NULL DEFAULT 'member'");
        $pdo->exec("UPDATE members SET role = 'admin' WHERE is_admin = 1");
    }
    if (!in_array('created_by', $cols('events'), true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN created_by INTEGER');
    }
    if (!in_array('max_shifts_per_member', $cols('events'), true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN max_shifts_per_member INTEGER');
    }
    $evCols = $cols('events');
    if (!in_array('starts_at', $evCols, true)) $pdo->exec('ALTER TABLE events ADD COLUMN starts_at TEXT');
    if (!in_array('ends_at', $evCols, true))   $pdo->exec('ALTER TABLE events ADD COLUMN ends_at TEXT');
    if (!in_array('important', $evCols, true)) $pdo->exec('ALTER TABLE events ADD COLUMN important INTEGER NOT NULL DEFAULT 0');
    if (!in_array('req_label', $evCols, true)) $pdo->exec("ALTER TABLE events ADD COLUMN req_label TEXT NOT NULL DEFAULT ''");
    if (!in_array('req_label2', $evCols, true)) $pdo->exec("ALTER TABLE events ADD COLUMN req_label2 TEXT NOT NULL DEFAULT ''");
    if (!in_array('req_label3', $evCols, true)) $pdo->exec("ALTER TABLE events ADD COLUMN req_label3 TEXT NOT NULL DEFAULT ''");
    // Schichtbörse: Orga hält sie für dieses Event offen (auch nach dem Standard-Vorlauf / nachträglich)
    if (!in_array('swap_force_open', $evCols, true)) $pdo->exec('ALTER TABLE events ADD COLUMN swap_force_open INTEGER NOT NULL DEFAULT 0');
    // Eigenes Event-Datum für Bestandsdaten aus den Schicht-Daten ableiten
    $pdo->exec("UPDATE events SET starts_at = (SELECT MIN(date(s.starts_at)) FROM event_slots s WHERE s.event_id = events.id)
                WHERE (starts_at IS NULL OR starts_at = '') AND EXISTS(SELECT 1 FROM event_slots s WHERE s.event_id = events.id)");
    $pdo->exec("UPDATE events SET ends_at = (SELECT MAX(date(COALESCE(s.ends_at, s.starts_at))) FROM event_slots s WHERE s.event_id = events.id)
                WHERE (ends_at IS NULL OR ends_at = '') AND EXISTS(SELECT 1 FROM event_slots s WHERE s.event_id = events.id)");
    if (!in_array('location', $cols('event_slots'), true)) {
        $pdo->exec("ALTER TABLE event_slots ADD COLUMN location TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('target_helpers', $cols('event_slots'), true)) {
        $pdo->exec('ALTER TABLE event_slots ADD COLUMN target_helpers INTEGER NOT NULL DEFAULT 0');
        // bisherige event-weite Helferzahl je Schicht übernehmen
        $pdo->exec("UPDATE event_slots SET target_helpers = (SELECT target_helpers FROM events e WHERE e.id = event_slots.event_id) WHERE target_helpers = 0");
    }
    if (!in_array('req_count', $cols('event_slots'), true)) {
        $pdo->exec('ALTER TABLE event_slots ADD COLUMN req_count INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('req_count2', $cols('event_slots'), true)) {
        $pdo->exec('ALTER TABLE event_slots ADD COLUMN req_count2 INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('req_count3', $cols('event_slots'), true)) {
        $pdo->exec('ALTER TABLE event_slots ADD COLUMN req_count3 INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('external_helpers', $cols('event_slots'), true)) {
        $pdo->exec("ALTER TABLE event_slots ADD COLUMN external_helpers TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('has_attr2', $cols('event_member_attr'), true)) {
        $pdo->exec('ALTER TABLE event_member_attr ADD COLUMN has_attr2 INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('has_attr3', $cols('event_member_attr'), true)) {
        $pdo->exec('ALTER TABLE event_member_attr ADD COLUMN has_attr3 INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('plan_locked', $cols('events'), true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN plan_locked INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('notified_at', $cols('assignments'), true)) {
        $pdo->exec('ALTER TABLE assignments ADD COLUMN notified_at TEXT');
    }
    if (!in_array('locked', $cols('assignments'), true)) {
        $pdo->exec('ALTER TABLE assignments ADD COLUMN locked INTEGER NOT NULL DEFAULT 0'); // fixierte Zuteilung (bleibt bei Neu-Einteilung)
    }
    if (!in_array('self_claimed', $cols('assignments'), true)) {
        $pdo->exec('ALTER TABLE assignments ADD COLUMN self_claimed INTEGER NOT NULL DEFAULT 0'); // selbst über „kurzfristig einspringen" eingetragen
    }
    // Tauschbörse: Angebots-Modus – 1:1-Tausch ('swap') oder einseitige Abgabe ('giveaway')
    if (!in_array('mode', $cols('shift_swaps'), true)) {
        $pdo->exec("ALTER TABLE shift_swaps ADD COLUMN mode TEXT NOT NULL DEFAULT 'swap'");
    }
    $mCols = $cols('meetings');
    if (!in_array('legislature_id', $mCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN legislature_id INTEGER');
    if (!in_array('kind', $mCols, true))           $pdo->exec("ALTER TABLE meetings ADD COLUMN kind TEXT NOT NULL DEFAULT 'ordentlich'");
    if (!in_array('cancelled', $mCols, true))      $pdo->exec('ALTER TABLE meetings ADD COLUMN cancelled INTEGER NOT NULL DEFAULT 0');
    if (!in_array('needs_report', $mCols, true))   $pdo->exec('ALTER TABLE meetings ADD COLUMN needs_report INTEGER NOT NULL DEFAULT 0');
    if (!in_array('start_number', $cols('legislatures'), true)) {
        $pdo->exec('ALTER TABLE legislatures ADD COLUMN start_number INTEGER NOT NULL DEFAULT 1');
    }
    // Belegblätter: Dateien können auch an einer Nachricht im Verlauf hängen
    if (in_array('claim_id', $cols('expense_files'), true) && !in_array('comment_id', $cols('expense_files'), true)) {
        $pdo->exec('ALTER TABLE expense_files ADD COLUMN comment_id INTEGER');
    }
    if (!in_array('reminder_lead_days', $cols('members'), true)) {
        $pdo->exec('ALTER TABLE members ADD COLUMN reminder_lead_days INTEGER');
    }
    if (!in_array('notify_assignment', $cols('members'), true)) {
        $pdo->exec('ALTER TABLE members ADD COLUMN notify_assignment INTEGER NOT NULL DEFAULT 1');
    }
    // NICHT MEHR IN BENUTZUNG: Die Kanalwahl je Benachrichtigungs-Art steht im Mitteilungs-
    // Register (notify_prefs). Die Spalten bleiben nur stehen, weil SQLite DROP COLUMN schlecht
    // kann; ihre Werte sind einmalig übernommen (Merker: settings.notify_prefs_migrated).
    foreach (['shift_mail', 'push_shift', 'assign_mail', 'push_assign', 'push_msgs', 'push_reminders'] as $chanCol) {
        if (!in_array($chanCol, $cols('members'), true)) {
            $pdo->exec("ALTER TABLE members ADD COLUMN $chanCol INTEGER NOT NULL DEFAULT 1");
        }
    }
    // Einmalige Migration der alten Kanal-Spalten ins Mitteilungs-Register (nur 0-Werte = Abweichungen)
    $migrated = $pdo->query("SELECT value FROM settings WHERE key = 'notify_prefs_migrated'")->fetchColumn();
    if (!$migrated) {
        $ins = $pdo->prepare('INSERT OR REPLACE INTO notify_prefs(member_id, type, mail, push, timing) VALUES(?,?,?,?,?)');
        foreach ($pdo->query('SELECT id, shift_mail, push_shift, assign_mail, push_assign, push_msgs, push_reminders FROM members')->fetchAll() as $mRow) {
            $mid = (int)$mRow['id'];
            $prefs = []; // type => [mail, push]
            if ((int)$mRow['shift_mail'] === 0)  $prefs['shift_reminder']['mail'] = 0;
            if ((int)$mRow['push_shift'] === 0)  $prefs['shift_reminder']['push'] = 0;
            if ((int)$mRow['assign_mail'] === 0) $prefs['assignment']['mail'] = 0;
            if ((int)$mRow['push_assign'] === 0) $prefs['assignment']['push'] = 0;
            if ((int)$mRow['push_msgs'] === 0) {
                foreach (['dm_vorsitz', 'dm_boerse', 'dm_bugs', 'dm_plan', 'dm_score', 'dm_sitzung'] as $t) $prefs[$t]['push'] = 0;
            }
            if ((int)$mRow['push_reminders'] === 0) {
                foreach (['vote_open', 'maybe_deadline', 'report_due', 'voteitems_unread'] as $t) $prefs[$t]['push'] = 0;
            }
            foreach ($prefs as $type => $p) {
                $ins->execute([$mid, $type, $p['mail'] ?? null, $p['push'] ?? null, null]);
            }
        }
        $pdo->exec("INSERT OR REPLACE INTO settings(key, value) VALUES('notify_prefs_migrated', '1')");
    }
    if (!in_array('score_adjust', $cols('members'), true)) {
        $pdo->exec('ALTER TABLE members ADD COLUMN score_adjust INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('referat', $cols('members'), true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN referat TEXT NOT NULL DEFAULT ''");
    }
    // Beitrittsdatum: Mitglieder zählen für den Eventscore erst ab ihrem Beitritt.
    // Leer heißt: keine Einschränkung.
    if (!in_array('joined_at', $cols('members'), true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN joined_at TEXT NOT NULL DEFAULT ''");
    }
    // Entwurfs-Modus: Events und Sitzungen können testweise (nur für Anleger:in sichtbar) angelegt werden
    if (!in_array('draft', $cols('events'), true)) $pdo->exec('ALTER TABLE events ADD COLUMN draft INTEGER NOT NULL DEFAULT 0');
    $mDraftCols = $cols('meetings');
    if (!in_array('draft', $mDraftCols, true))      $pdo->exec('ALTER TABLE meetings ADD COLUMN draft INTEGER NOT NULL DEFAULT 0');
    if (!in_array('created_by', $mDraftCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN created_by INTEGER');
    if (!in_array('series_id', $mDraftCols, true))  $pdo->exec('ALTER TABLE meetings ADD COLUMN series_id TEXT');
    // „Wichtige Infos" zuletzt gesehen (für Dashboard-Hinweis)
    if (!in_array('infos_seen_at', $cols('members'), true)) $pdo->exec('ALTER TABLE members ADD COLUMN infos_seen_at TEXT');
    // Kurzcode zum Login (für installierte Apps, wo der Mail-Link in Safari statt in der App aufgeht)
    if (!in_array('code_hash', $cols('login_tokens'), true)) $pdo->exec('ALTER TABLE login_tokens ADD COLUMN code_hash TEXT');
    // Optionale Privat-Mail für persönliche Erinnerungen (leer = die hinterlegte Adresse nutzen)
    if (!in_array('notify_email', $cols('members'), true)) $pdo->exec("ALTER TABLE members ADD COLUMN notify_email TEXT NOT NULL DEFAULT ''");
    // Pronomen (für die Redeliste); festes Set, selbst gesetzt
    if (!in_array('pronouns', $cols('members'), true)) $pdo->exec("ALTER TABLE members ADD COLUMN pronouns TEXT NOT NULL DEFAULT ''");
    // Streak-Stil ('' = Flamme; weitere Stile mit ihrem Freischalt-Achievement in flame_styles_unlock())
    if (!in_array('flame_style', $cols('members'), true)) $pdo->exec("ALTER TABLE members ADD COLUMN flame_style TEXT NOT NULL DEFAULT ''");
    else { // unbekannte/entfernte Stile (z. B. der alte Hasen-Stil) zurück auf Flamme – Whitelist aus der Registry
        // Muss flame_styles_all() sein – verdiente UND kaufbare Stile. Mit flame_styles_unlock()
        // würde ein gekaufter Stil bei jedem Seitenaufruf zurückgesetzt.
        $valid = array_merge([''], flame_styles_all());
        $ph = implode(',', array_fill(0, count($valid), '?'));
        $pdo->prepare("UPDATE members SET flame_style = '' WHERE flame_style NOT IN ($ph)")->execute($valid);
    }
    // PWA-Nutzung: Datum des letzten Öffnens im installierten (Standalone-)Modus – stempelt app.js via push.php (pwa_ping)
    if (!in_array('pwa_last_seen', $cols('members'), true)) $pdo->exec("ALTER TABLE members ADD COLUMN pwa_last_seen TEXT NOT NULL DEFAULT ''");
    // „Sommer verschenken"-Push: Datum, an dem König:in des Sommers einmalig ans offene Geschenk erinnert wurde ('' = noch nicht)
    if (!in_array('sommer_reminded_on', $cols('members'), true)) $pdo->exec("ALTER TABLE members ADD COLUMN sommer_reminded_on TEXT NOT NULL DEFAULT ''");
    if (!in_array('sommer_reminded2_on', $cols('members'), true)) $pdo->exec("ALTER TABLE members ADD COLUMN sommer_reminded2_on TEXT NOT NULL DEFAULT ''"); // zweite, dringlichere Erinnerung nach 14 Tagen
    // Onboarding-Tutorial: 0 = noch nicht durchlaufen. Neue Mitglieder bekommen beim ersten Dashboard-Besuch den geführten Rundgang.
    /*
     * „Rundgang wirklich zu Ende geklickt" – eigene Spalte, NICHT `onboarded`.
     * `onboarded` heißt bloß „nicht mehr automatisch starten" – auch „Ich kenne mich bereits
     * gut aus" setzt es. Als Grundlage für ein Achievement wäre es gelogen. Dieses Feld setzt
     * ausschließlich push.php beim Erreichen des letzten Schritts.
     */
    if (!in_array('tour_done', $cols('members'), true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN tour_done INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('onboarded', $cols('members'), true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN onboarded INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE members SET onboarded = 1"); // Bestandsmitglieder nicht nachträglich zwangs-touren – sie können es unter „Wichtige Infos" wiederholen
    }
    // Freiwilliges Dashboard-Angebot „mach den Rundgang mal" (1 = anzeigen). Bestandsmitglieder bekommen es durch
    // DEFAULT 1 automatisch; ein VOLL abgeschlossener Rundgang oder „Ich kenne mich aus" setzt es auf 0 (Abbrechen NICHT).
    if (!in_array('tour_task', $cols('members'), true)) $pdo->exec("ALTER TABLE members ADD COLUMN tour_task INTEGER NOT NULL DEFAULT 1");
    // Einmalig automatisch anzuwendender Skin (z. B. „Hochsommer" nach dem Sommerkönig-Unlock oder einem Skin-Geschenk):
    // page_header reicht ihn als window.ASTA_AUTO_SKIN an app.js, das ihn genau einmal aktiviert und per push.php (skin_applied) leert.
    if (!in_array('pending_skin', $cols('members'), true)) $pdo->exec("ALTER TABLE members ADD COLUMN pending_skin TEXT NOT NULL DEFAULT ''");
    // Skin-Geschenke: König:in des Sommers darf bis zu zwei Helfer:innen den „Hochsommer"-Skin schenken (mit persönlicher Nachricht).
    $pdo->exec("CREATE TABLE IF NOT EXISTS skin_gifts (
        id         INTEGER PRIMARY KEY,
        from_id    INTEGER NOT NULL,
        to_id      INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        skin       TEXT NOT NULL DEFAULT 'sommer',
        message    TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        seen_at    TEXT
    )");
    // Merker: Glückwunsch-Nachricht zur Spitzenklasse schon zugestellt? (wird beim Verlust zurückgesetzt)
    if (!in_array('spitze_greeted', $cols('members'), true)) $pdo->exec('ALTER TABLE members ADD COLUMN spitze_greeted INTEGER NOT NULL DEFAULT 0');
    // Ast-Markt: Käufe aus dem Belohnungs-Laden. Das GUTHABEN wird nicht gespeichert, sondern
    // gerechnet (1 Ast je übernommene Börsen-Schicht, minus Summe der Käufe – siehe ast_balance()).
    // royal=1 heißt „beschlagnahmt": kostenlose Royal-Entnahme, höchstens alle 3 Monate eine.
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_purchases (
        id         INTEGER PRIMARY KEY,
        member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        item       TEXT    NOT NULL,
        price      INTEGER NOT NULL DEFAULT 0,
        royal      INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS shop_purchases_uni ON shop_purchases(member_id, item)');
    // Geschenkte AsT (Vorsitz/Admin über die Achievements-Übersicht): additive Buchungen,
    // ast_balance() zählt sie dazu – das Guthaben selbst bleibt abgeleitet, nie gespeichert.
    $pdo->exec("CREATE TABLE IF NOT EXISTS ast_grants (
        id         INTEGER PRIMARY KEY,
        member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        amount     INTEGER NOT NULL,
        reason     TEXT    NOT NULL DEFAULT '',
        granted_by INTEGER,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    // ETF-Sparpläne des Schicht-Depots: angelegte AsT je Plan. Nur die KÄUFE stehen in der
    // Tabelle – der aktuelle Wert wird aus der deterministischen Kursreihe gerechnet
    // (etf_index); ein Verkauf trägt sold_on/sold_value ein und macht den Erlös verfügbar.
    $pdo->exec("CREATE TABLE IF NOT EXISTS ast_invest (
        id         INTEGER PRIMARY KEY,
        member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        plan       TEXT    NOT NULL,
        amount     INTEGER NOT NULL,
        bought_on  TEXT    NOT NULL DEFAULT (date('now','localtime')),
        sold_on    TEXT,
        sold_value INTEGER
    )");
    // Wichtige Informationen: optional nur für ein bestimmtes Referat sichtbar (leer = alle)
    if (!in_array('referat', $cols('infos'), true)) $pdo->exec("ALTER TABLE infos ADD COLUMN referat TEXT NOT NULL DEFAULT ''");
    // Sitzungs-Rückmeldung: Grund (wird nur dem Vorsitz weitergeleitet)
    if (!in_array('reason', $cols('meeting_rsvp'), true)) $pdo->exec("ALTER TABLE meeting_rsvp ADD COLUMN reason TEXT NOT NULL DEFAULT ''");
    // Basis-Score: manueller Vorsitz-Zuschlag (analog score_adjust beim Eventscore)
    if (!in_array('basis_adjust', $cols('members'), true)) $pdo->exec('ALTER TABLE members ADD COLUMN basis_adjust INTEGER NOT NULL DEFAULT 0');
    // Inaktivitäts-Erinnerung (Opt-in-Push): letzter App-Öffnungs-Tag (tagesgenau, activity_weeks ist nur wochengenau)
    // + Merker, wann zuletzt erinnert wurde (höchstens 1× je Inaktivitäts-Phase)
    $mActCols = $cols('members');
    if (!in_array('last_active_on', $mActCols, true))       $pdo->exec('ALTER TABLE members ADD COLUMN last_active_on TEXT');
    if (!in_array('inactive_notified_on', $mActCols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN inactive_notified_on TEXT');
    // Achievements/Gamification: Tages-Streak (fortlaufende App-Öffnungen) + ausgerüstetes Avatar-Schmuckstück
    if (!in_array('streak_current', $mActCols, true))  $pdo->exec('ALTER TABLE members ADD COLUMN streak_current INTEGER NOT NULL DEFAULT 0');
    if (!in_array('streak_best', $mActCols, true))     $pdo->exec('ALTER TABLE members ADD COLUMN streak_best INTEGER NOT NULL DEFAULT 0');
    if (!in_array('streak_last_day', $mActCols, true)) $pdo->exec("ALTER TABLE members ADD COLUMN streak_last_day TEXT NOT NULL DEFAULT ''");
    if (!in_array('avatar_deco', $mActCols, true))     $pdo->exec("ALTER TABLE members ADD COLUMN avatar_deco TEXT NOT NULL DEFAULT ''");
    if (!in_array('avatar_palette', $mActCols, true))  $pdo->exec("ALTER TABLE members ADD COLUMN avatar_palette TEXT NOT NULL DEFAULT ''");
    // Schriftfarbe der Initialen im Avatar. '' = Vorgabe (dunkles Blaugrau), siehe avatar_inks().
    if (!in_array('avatar_ink', $mActCols, true))      $pdo->exec("ALTER TABLE members ADD COLUMN avatar_ink TEXT NOT NULL DEFAULT ''");
    // Schmuck je Trage-Position (Komma-Liste, max. eins pro Slot): löst das Einzel-Feld avatar_deco ab und übernimmt dessen Wert
    if (!in_array('avatar_decos', $mActCols, true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN avatar_decos TEXT NOT NULL DEFAULT ''");
        $pdo->exec("UPDATE members SET avatar_decos = avatar_deco WHERE avatar_deco <> ''");
    }
    // Eventscore-Startpunkte: Neue steigen mit dem Gruppenschnitt zum Beitrittszeitpunkt ein (statt bei 0 → „Ausbaufähig")
    if (!in_array('score_start', $mActCols, true))     $pdo->exec('ALTER TABLE members ADD COLUMN score_start INTEGER NOT NULL DEFAULT 0');
    // Persönliche Kalender-Einstellung: StuPa-Sitzungen ausblenden (App + eigenes iCal-Abo)
    if (!in_array('hide_stupa', $mActCols, true))      $pdo->exec('ALTER TABLE members ADD COLUMN hide_stupa INTEGER NOT NULL DEFAULT 0');
    if (!in_array('hide_birthdays', $mActCols, true))  $pdo->exec('ALTER TABLE members ADD COLUMN hide_birthdays INTEGER NOT NULL DEFAULT 0');
    // Nutzerprofile: persönliche Referatsbeschreibung + „Über mich" (jede:r pflegt die eigenen Texte)
    if (!in_array('referat_desc', $mActCols, true))    $pdo->exec("ALTER TABLE members ADD COLUMN referat_desc TEXT NOT NULL DEFAULT ''");
    if (!in_array('about_me', $mActCols, true))        $pdo->exec("ALTER TABLE members ADD COLUMN about_me TEXT NOT NULL DEFAULT ''");
    // Geburtstag (freiwillig, nur Tag/Monat als 'MM-TT' – kein Jahr, das geht niemanden was an)
    if (!in_array('birthday', $mActCols, true))        $pdo->exec("ALTER TABLE members ADD COLUMN birthday TEXT NOT NULL DEFAULT ''");
    // Deaktivierung ist nur ein ÜBERGANG: Der Stempel merkt sich, seit wann – ab 14 Tagen
    // erinnert das Dashboard den Vorsitz ans Aufräumen (löschen oder reaktivieren).
    if (!in_array('deactivated_on', $mActCols, true))  $pdo->exec("ALTER TABLE members ADD COLUMN deactivated_on TEXT NOT NULL DEFAULT ''");
    // Selbstheilung bei jedem Start: Alt-Deaktivierte von vor der Spalte bekommen HEUTE als
    // Stempel (die Frist beginnt damit fair ab dem Update), Reaktivierte verlieren ihn.
    $pdo->exec("UPDATE members SET deactivated_on = date('now','localtime') WHERE active = 0 AND deactivated_on = ''");
    $pdo->exec("UPDATE members SET deactivated_on = '' WHERE active = 1 AND deactivated_on <> ''");
    // Telefonnummer (freiwillig). Sie steht NIE einfach auf der Profilseite: Andere sehen erst
    // die Spielregel (phone_note) und bekommen die Nummer nur, wenn sie zusagt haben.
    if (!in_array('phone', $mActCols, true))           $pdo->exec("ALTER TABLE members ADD COLUMN phone TEXT NOT NULL DEFAULT ''");
    if (!in_array('phone_note', $mActCols, true))      $pdo->exec("ALTER TABLE members ADD COLUMN phone_note TEXT NOT NULL DEFAULT ''");
    // Bewusstes „ich gebe keine an" – zählt fürs Profil als beantwortet und steht auch so im Profil.
    if (!in_array('phone_none', $mActCols, true))      $pdo->exec('ALTER TABLE members ADD COLUMN phone_none INTEGER NOT NULL DEFAULT 0');
    // App-Öffnungen pro Tag (Achievement-Serie „Stammgast"): Zähler + letzter Request-Zeitstempel
    // (last_seen_at wird NIRGENDS angezeigt – nur interner Abstands-Messer für „neue Öffnung")
    if (!in_array('opens_on', $mActCols, true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN opens_on TEXT NOT NULL DEFAULT ''");
        $pdo->exec('ALTER TABLE members ADD COLUMN opens_count INTEGER NOT NULL DEFAULT 0');
        $pdo->exec("ALTER TABLE members ADD COLUMN last_seen_at TEXT NOT NULL DEFAULT ''");
    }
    // Eingefrorene Ergebnisse: beim Abschluss wird die Zählung als Snapshot gespeichert – so ändert
    // das spätere Löschen von Mitgliedern (deren Stimmen kaskadieren) KEINE archivierten Ergebnisse mehr.
    $cvCols = $cols('circular_votes');
    if ($cvCols && !in_array('result_yes', $cvCols, true)) {
        $pdo->exec('ALTER TABLE circular_votes ADD COLUMN result_yes INTEGER');
        $pdo->exec('ALTER TABLE circular_votes ADD COLUMN result_no INTEGER');
        $pdo->exec('ALTER TABLE circular_votes ADD COLUMN result_abstain INTEGER');
    }
    // Backfill: bereits geschlossene Umläufe einmalig mit dem aktuellen Stand einfrieren
    $pdo->exec("UPDATE circular_votes SET
        result_yes     = (SELECT COUNT(*) FROM circular_ballots b WHERE b.vote_id = circular_votes.id AND b.choice = 'yes'),
        result_no      = (SELECT COUNT(*) FROM circular_ballots b WHERE b.vote_id = circular_votes.id AND b.choice = 'no'),
        result_abstain = (SELECT COUNT(*) FROM circular_ballots b WHERE b.vote_id = circular_votes.id AND b.choice = 'abstain')
        WHERE status = 'closed' AND result_yes IS NULL");
    $spCols = $cols('simple_polls');
    if ($spCols && !in_array('result_json', $spCols, true)) $pdo->exec('ALTER TABLE simple_polls ADD COLUMN result_json TEXT');
    // Backfill: bereits beendete Abstimmungen einmalig einfrieren
    foreach ($pdo->query("SELECT id FROM simple_polls WHERE status = 'closed' AND (result_json IS NULL OR result_json = '')")->fetchAll() as $spRow) {
        $spId = (int)$spRow['id'];
        $counts = [];
        $cq = $pdo->prepare('SELECT o.id, COUNT(v.member_id) AS n FROM simple_poll_options o
                             LEFT JOIN simple_poll_votes v ON v.option_id = o.id WHERE o.poll_id = ? GROUP BY o.id');
        $cq->execute([$spId]);
        foreach ($cq->fetchAll() as $cr) $counts[(int)$cr['id']] = (int)$cr['n'];
        $vq = $pdo->prepare('SELECT COUNT(DISTINCT v.member_id) FROM simple_poll_votes v
                             JOIN simple_poll_options o ON o.id = v.option_id WHERE o.poll_id = ?');
        $vq->execute([$spId]);
        $pdo->prepare('UPDATE simple_polls SET result_json = ? WHERE id = ?')
            ->execute([json_encode(['counts' => $counts, 'voters' => (int)$vq->fetchColumn()]), $spId]);
    }
    // Terminfinder: „Abstimmen bis"-Frist (nur wenn Tabelle schon existiert, sonst legt sie das CREATE mit an)
    $dpCols = $cols('date_polls');
    if ($dpCols && !in_array('deadline', $dpCols, true)) $pdo->exec('ALTER TABLE date_polls ADD COLUMN deadline TEXT');
    // Stichtag fürs Basis-Score-Kriterium „Wichtige Info nicht binnen 7 Tagen gelesen":
    // Infos davor zählen nicht mehr. INSERT OR IGNORE setzt ihn genau einmal.
    $pdo->exec("INSERT OR IGNORE INTO settings(key, value) VALUES('basis_info_cutoff', date('now','localtime'))");
    // „In Teams öffnen": Deep-Link-Spalte (Tabelle kann live schon ohne sie existieren)
    // Legislaturperioden gelten über ihren ZEITRAUM (siehe legislature_for()). Eine Periode ohne
    // Beginn sammelt nichts ein, ihre Sitzungen verlören still ihre Nummer – deshalb fehlende
    // Anfangsdaten aus dem Bestand nachtragen: frühestes Datum der zugeordneten Sitzungen.
    try {
        foreach ($pdo->query("SELECT id FROM legislatures WHERE start_date = '' OR start_date IS NULL")->fetchAll() as $l) {
            $q = $pdo->prepare("SELECT MIN(date(starts_at)) FROM meetings WHERE legislature_id = ?");
            $q->execute([(int)$l['id']]);
            $frueh = (string)($q->fetchColumn() ?: '');
            if ($frueh !== '') {
                $pdo->prepare('UPDATE legislatures SET start_date = ? WHERE id = ?')->execute([$frueh, (int)$l['id']]);
                app_log_error('Migration: Legislaturperiode ' . (int)$l['id'] . ' hatte keinen Beginn – aus der '
                    . 'frühesten zugeordneten Sitzung auf ' . $frueh . ' gesetzt.');
            }
        }
    } catch (\Throwable $e) { /* Migration darf den Start nie blockieren */ }

    $tfCols = $cols('teams_files');
    if ($tfCols && !in_array('teams_url', $tfCols, true)) $pdo->exec('ALTER TABLE teams_files ADD COLUMN teams_url TEXT');
    // Ablage-Picker: 1 = die Datei lag schon dort und wurde nur ausgewählt. Solche Originale darf
    // die App in der Ablage NIE löschen – sie gehören dem Team, nicht dem Anhang.
    if ($tfCols && !in_array('linked', $tfCols, true)) $pdo->exec('ALTER TABLE teams_files ADD COLUMN linked INTEGER NOT NULL DEFAULT 0');
    $ncCols = $cols('nc_files');
    if ($ncCols && !in_array('linked', $ncCols, true)) $pdo->exec('ALTER TABLE nc_files ADD COLUMN linked INTEGER NOT NULL DEFAULT 0');
    // Achievement-Codes umbenennen: bereits Freigeschaltetes läuft unter dem neuen Code weiter,
    // niemand verliert etwas.
    foreach (['reports_25' => 'reports_20', 'reports_50' => 'reports_30', 'streak_100' => 'streak_50',
              'shifts_50' => 'shifts_35', 'shifts_100' => 'shifts_60',
              'responses_100' => 'responses_50', 'meetings_25' => 'meetings_20'] as $oldAch => $newAch) {
        $pdo->exec("UPDATE OR IGNORE member_achievements SET code = '$newAch' WHERE code = '$oldAch'");
    }
    // Props als Zahlmittel: Wie viele Props dieser Kauf gekostet hat (0 = mit AsT oder Krone).
    // Die kudos-Zeilen bleiben unangetastet – „erhalten" und „übrig" sind zwei verschiedene Zahlen,
    // und alle Props-Achievements rechnen weiter mit den ERHALTENEN.
    if (!in_array('props', $cols('shop_purchases'), true)) {
        $pdo->exec('ALTER TABLE shop_purchases ADD COLUMN props INTEGER NOT NULL DEFAULT 0');
    }
    // Mondsüchtig: Kette der Nächte (0–5 Uhr) – Länge und letzter gezählter Tag.
    $mNightCols = $cols('members');
    if (!in_array('night_run', $mNightCols, true))  $pdo->exec('ALTER TABLE members ADD COLUMN night_run INTEGER NOT NULL DEFAULT 0');
    if (!in_array('night_last', $mNightCols, true)) $pdo->exec("ALTER TABLE members ADD COLUMN night_last TEXT NOT NULL DEFAULT ''");
    // Pro-Sitzung-Tagesordnung (leer = Standard-TO)
    if (!in_array('agenda', $cols('meetings'), true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN agenda TEXT');
    // Basis-TOPs, die NICHT in die Redeliste aufgenommen werden (JSON-Liste normalisierter Texte)
    if (!in_array('redeliste_skip', $cols('meetings'), true)) $pdo->exec("ALTER TABLE meetings ADD COLUMN redeliste_skip TEXT NOT NULL DEFAULT ''");
    if (!in_array('redeliste_report', $cols('meetings'), true)) $pdo->exec("ALTER TABLE meetings ADD COLUMN redeliste_report TEXT NOT NULL DEFAULT ''"); // Berichte-TOPs (Schlüssel-Liste)
    // Pro-Sitzung-Redeliste: Raum-Token (öffentlich im Beitrittslink) + Leitungs-Key (geheim)
    $mRedeCols = $cols('meetings');
    if (!in_array('redeliste_token', $mRedeCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN redeliste_token TEXT');
    if (!in_array('redeliste_key', $mRedeCols, true))   $pdo->exec('ALTER TABLE meetings ADD COLUMN redeliste_key TEXT');
    // Einladungen: Teams-Link, „muss eingeladen werden", Sekki-Freigabe, Reminder-Wunsch, Versand-/Reminder-Zeitpunkt
    $mInvCols = $cols('meetings');
    if (!in_array('teams_link', $mInvCols, true))       $pdo->exec("ALTER TABLE meetings ADD COLUMN teams_link TEXT NOT NULL DEFAULT ''");
    if (!in_array('invite_required', $mInvCols, true))  $pdo->exec('ALTER TABLE meetings ADD COLUMN invite_required INTEGER NOT NULL DEFAULT 1');
    if (!in_array('invite_approved', $mInvCols, true))  $pdo->exec('ALTER TABLE meetings ADD COLUMN invite_approved INTEGER NOT NULL DEFAULT 0');
    if (!in_array('invite_reminder', $mInvCols, true))  $pdo->exec('ALTER TABLE meetings ADD COLUMN invite_reminder INTEGER NOT NULL DEFAULT 1');
    if (!in_array('invite_sent_at', $mInvCols, true))   $pdo->exec('ALTER TABLE meetings ADD COLUMN invite_sent_at TEXT');
    if (!in_array('invite_reminded_at', $mInvCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN invite_reminded_at TEXT');
    // Eingereichte TOPs: Zeitangabe + Beschreibung
    $tsCols = $cols('top_submissions');
    if ($tsCols && !in_array('time_est', $tsCols, true))    $pdo->exec("ALTER TABLE top_submissions ADD COLUMN time_est TEXT NOT NULL DEFAULT ''");
    if ($tsCols && !in_array('description', $tsCols, true)) $pdo->exec("ALTER TABLE top_submissions ADD COLUMN description TEXT NOT NULL DEFAULT ''");
    // Eingereichter TOP nicht in die Redeliste aufnehmen
    if ($tsCols && !in_array('no_redeliste', $tsCols, true)) $pdo->exec('ALTER TABLE top_submissions ADD COLUMN no_redeliste INTEGER NOT NULL DEFAULT 0');
    // Protokoll-Workflow: gewählte:r Protokollant:in, Status & Verweise auf die Teams-/OLAT-Ablage.
    // protocol_status: '' = nichts · 'uploaded' = Dok liegt in Teams (Abstimmung folgt) · 'approved' = angenommen (OLAT-Freigabe offen) · 'published' = als PDF in OLAT
    // Das StuPa-Präsidium gehört in seine eigene Tabelle und darf KEINE Mitglieds-Zeile haben:
    // current_role() machte daraus sonst ein vollwertiges 'member' mit App-Zugriff, Pinnwand,
    // Stammliste und Score. Diese Zeilen räumen solche Konten um.
    try {
        if (in_array('role', $cols('members'), true)) {
            $alt = $pdo->query("SELECT id, name, email FROM members WHERE role = 'stupa'")->fetchAll();
            foreach ($alt as $a) {
                $mail = trim((string)($a['email'] ?? ''));
                if ($mail !== '') {
                    $ins = $pdo->prepare('INSERT OR IGNORE INTO stupa_users(name, email) VALUES(?,?)');
                    $ins->execute([trim((string)$a['name']) !== '' ? $a['name'] : 'StuPa-Präsidium', $mail]);
                }
                $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([(int)$a['id']]);
                app_log_error('Migration: StuPa-Mitgliedskonto ' . (int)$a['id'] . ' („' . $a['name']
                    . '") entfernt und als eigener Zugang übernommen.');
            }
        }
    } catch (\Throwable $e) { /* Migration darf den Start nie blockieren */ }

    if (!in_array('notify_done', $cols('stupa_claims'), true)) {
        $pdo->exec('ALTER TABLE stupa_claims ADD COLUMN notify_done INTEGER NOT NULL DEFAULT 0');
    }

    // Props: in welchem Monat wurde der Person zuletzt das aufgefrischte Kontingent gezeigt?
    if (!in_array('props_seen_ym', $cols('members'), true)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN props_seen_ym TEXT NOT NULL DEFAULT ''");
    }

    // Belegblatt: von Finanzen stellvertretend erfasst (Papierbeleg) statt selbst eingereicht
    if (!in_array('by_finance', $cols('expense_claims'), true)) {
        $pdo->exec('ALTER TABLE expense_claims ADD COLUMN by_finance INTEGER NOT NULL DEFAULT 0');
    }

    $mProtoCols = $cols('meetings');
    if (!in_array('protocol_taker_id', $mProtoCols, true))   $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_taker_id INTEGER');
    if (!in_array('protocol_status', $mProtoCols, true))     $pdo->exec("ALTER TABLE meetings ADD COLUMN protocol_status TEXT NOT NULL DEFAULT ''");
    if (!in_array('protocol_drive_id', $mProtoCols, true))   $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_drive_id TEXT');   // Teams-Drive der fertigen Dok
    if (!in_array('protocol_item_id', $mProtoCols, true))    $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_item_id TEXT');    // Teams-Item-ID (stabil über Umbenennen/Verschieben)
    if (!in_array('protocol_web_url', $mProtoCols, true))    $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_web_url TEXT');    // Teams-Link zum Mitbearbeiten
    if (!in_array('protocol_filename', $mProtoCols, true))   $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_filename TEXT');
    if (!in_array('protocol_uploaded_at', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_uploaded_at TEXT');
    if (!in_array('protocol_uploaded_by', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_uploaded_by INTEGER');
    // ALTLAST: Rückverweis auf den Abstimmungsgegenstand. Wird NICHT mehr gelesen oder geschrieben –
    // die Beziehung steht in vote_items.ref_meeting_id (siehe protocol_vote_item()). Die Spalte
    // bleibt bewusst stehen, statt sie per DROP COLUMN zu entfernen: Das hinge an der SQLite-Version
    // des Hosters, und eine ungenutzte Spalte kostet nichts.
    if (!in_array('protocol_vote_item_id', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_vote_item_id INTEGER');
    // Abstimmung bewusst entfernt (jemand hat den Gegenstand gelöscht) – dann legt der Abgleich
    // ihn NICHT wieder an. Sonst käme er beim nächsten Cron-Lauf stumm zurück.
    if (!in_array('protocol_vote_off', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_vote_off INTEGER NOT NULL DEFAULT 0');
    // Nach einer Vertagung: frühester Zeitpunkt für die Zielsuche (die Sitzung, in der vertagt
    // wurde, kommt nicht noch einmal in Frage).
    if (!in_array('protocol_vote_after', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_vote_after TEXT');
    if (!in_array('protocol_olat_path', $mProtoCols, true))  $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_olat_path TEXT');
    if (!in_array('protocol_published_at', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_published_at TEXT');
    if (!in_array('protocol_published_by', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_published_by INTEGER');
    // Internes Protokoll (nicht-öffentlicher Teil): eigener Teams-Ordner, wird NIE nach OLAT veröffentlicht.
    if (!in_array('protocol_intern_drive_id', $mProtoCols, true))    $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_intern_drive_id TEXT');
    if (!in_array('protocol_intern_item_id', $mProtoCols, true))     $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_intern_item_id TEXT');
    if (!in_array('protocol_intern_web_url', $mProtoCols, true))     $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_intern_web_url TEXT');
    // Teams-Deep-Link zum fertigen Protokoll (öffnet die Teams-APP statt Office im Browser);
    // wird beim ersten Öffnen einmalig per Graph gebaut und hier gemerkt
    if (!in_array('protocol_teams_url', $mProtoCols, true))          $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_teams_url TEXT');
    if (!in_array('protocol_intern_teams_url', $mProtoCols, true))   $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_intern_teams_url TEXT');
    if (!in_array('protocol_intern_filename', $mProtoCols, true))    $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_intern_filename TEXT');
    if (!in_array('protocol_intern_uploaded_at', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_intern_uploaded_at TEXT');
    if (!in_array('protocol_intern_uploaded_by', $mProtoCols, true)) $pdo->exec('ALTER TABLE meetings ADD COLUMN protocol_intern_uploaded_by INTEGER');
    // Abstimmungsgegenstände: einfacher Beschluss-Status + Sondertyp „Protokoll-Genehmigung"
    $viCols = $cols('vote_items');
    if ($viCols && !in_array('decision', $viCols, true))   $pdo->exec("ALTER TABLE vote_items ADD COLUMN decision TEXT NOT NULL DEFAULT 'offen'"); // offen | angenommen | vertagt
    if ($viCols && !in_array('decided_by', $viCols, true)) $pdo->exec('ALTER TABLE vote_items ADD COLUMN decided_by INTEGER');
    if ($viCols && !in_array('decided_at', $viCols, true)) $pdo->exec('ALTER TABLE vote_items ADD COLUMN decided_at TEXT');
    if ($viCols && !in_array('kind', $viCols, true))       $pdo->exec("ALTER TABLE vote_items ADD COLUMN kind TEXT NOT NULL DEFAULT ''"); // '' | 'protocol'
    if ($viCols && !in_array('ref_meeting_id', $viCols, true)) $pdo->exec('ALTER TABLE vote_items ADD COLUMN ref_meeting_id INTEGER'); // bei kind=protocol: Quell-Sitzung, deren Protokoll genehmigt wird
    // Referat-Liste einmalig befüllen: Vorlage + bereits bei Mitgliedern eingetragene Referate
    if ((int)$pdo->query('SELECT COUNT(*) FROM referate')->fetchColumn() === 0) {
        $template = ['Vorsitz', 'Finanzen', 'Umwelt & Mobilität', 'Hochschulpolitik', 'Politische Bildung',
            'Events & Kultur', 'Datenschutz', 'PR', 'Studium & FS',
            'Soziales Studifinanzierung, Wohnen, Arbeitsrecht', 'Soziales Gleichstellung, Feminismus, Queer',
            'Soziales Inklusion, Antidiskriminierung, Internationales', 'Studentisches Engagement', 'Sekretariat'];
        $ins = $pdo->prepare('INSERT INTO referate(name, sort) VALUES(?,?)');
        $i = 1; $seen = [];
        foreach ($template as $nm) { $ins->execute([$nm, $i++]); $seen[mb_strtolower($nm)] = true; }
        foreach ($pdo->query("SELECT DISTINCT referat FROM members WHERE referat <> ''")->fetchAll() as $r) {
            $nm = trim((string)$r['referat']);
            if ($nm !== '' && !isset($seen[mb_strtolower($nm)])) { $ins->execute([$nm, $i++]); $seen[mb_strtolower($nm)] = true; }
        }
    }

    // Pinnwand-Antworten (Konversation auf dem Profil) + „Ansehen"-Sprungziel für Dashboard-Nachrichten
    if (!in_array('parent_id', $cols('profile_posts'), true)) $pdo->exec('ALTER TABLE profile_posts ADD COLUMN parent_id INTEGER NOT NULL DEFAULT 0');
    if (!in_array('link', $cols('dashboard_messages'), true)) $pdo->exec("ALTER TABLE dashboard_messages ADD COLUMN link TEXT NOT NULL DEFAULT ''");
    // Props: Toast-Merker (NULL = Empfänger:in hat den „Du hast Props bekommen"-Toast noch nicht gesehen)
    if (!in_array('seen_at', $cols('kudos'), true)) $pdo->exec('ALTER TABLE kudos ADD COLUMN seen_at TEXT');
    // Props: Tabelle umbauen, damit ANONYME ADMIN-VERGABEN möglich sind (from_id NULL, mehrfach pro Person/Monat).
    // Die Eindeutigkeit je Person/Monat gilt deshalb nur für echte Vergaben.
    try {
        $kSql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='kudos'")->fetchColumn();
        if ($kSql !== '' && stripos($kSql, 'UNIQUE(from_id') !== false) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec("CREATE TABLE kudos_neu (
                id         INTEGER PRIMARY KEY,
                from_id    INTEGER REFERENCES members(id) ON DELETE CASCADE,
                to_id      INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
                ym         TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                seen_at    TEXT
            )");
            $pdo->exec('INSERT INTO kudos_neu(id, from_id, to_id, ym, created_at, seen_at)
                        SELECT id, from_id, to_id, ym, created_at, seen_at FROM kudos');
            $pdo->exec('DROP TABLE kudos');
            $pdo->exec('ALTER TABLE kudos_neu RENAME TO kudos');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS kudos_peer_unique ON kudos(from_id, to_id, ym) WHERE from_id IS NOT NULL');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    } catch (\Throwable $e) { /* Migration darf den Start nie verhindern */ }
    // Alt-Bestand: Pinnwand-Nachrichten mit dem Link als Fließtext
    // („… 💌 – ansehen: https://…/profil.php") und ohne Sprungziel einmalig säubern:
    // Fließtext-Link raus, sauberes „Ansehen"-Ziel setzen. Idempotent.
    $pdo->exec("UPDATE dashboard_messages
                   SET body = rtrim(substr(body, 1, instr(body, ' – ansehen:') - 1)),
                       link = 'profil.php#pinnwand'
                 WHERE link = '' AND instr(body, ' – ansehen:') > 0 AND instr(body, 'Profil-Pinnwand') > 0");
    // Nachbesserung: Pinnwand-Hinweise an die PROFIL-INHABER:IN („… auf deine/deiner Profil-Pinnwand …"), die
    // wegen eines früheren Regex-Bugs (Delimiter-Kollision in dm_safe_link) ohne Sprungziel gespeichert wurden.
    $pdo->exec("UPDATE dashboard_messages SET link = 'profil.php#pinnwand'
                 WHERE link = '' AND (instr(body, 'deine Profil-Pinnwand') > 0 OR instr(body, 'deiner Profil-Pinnwand') > 0)");

    // --- Inhalte überleben die Person, die sie angelegt hat -----------------------------------
    // Die Autor:innen-Spalte hängt bewusst OHNE ON DELETE CASCADE an members: Sonst risse das
    // Löschen eines Mitglieds ganze Inhalte mit (Beschluss-Archiv, Abstimmungen samt fremder
    // Stimmen, Terminfinder, geteilte Termine, Belegblätter, Pinnwand, verschenkte Skins).
    // SQLite kann Constraints nicht ändern, also Tabelle kopieren und tauschen. Persönliches
    // (Stimmen, Einteilungen, Reads, Erfolge …) kaskadiert weiterhin.
    $detach = ['expense_claims' => 'member_id', 'termine' => 'created_by', 'date_polls' => 'created_by',
               'circular_votes' => 'created_by', 'simple_polls' => 'created_by',
               'profile_posts' => 'author_id', 'skin_gifts' => 'from_id'];
    $needsDetach = [];
    foreach ($detach as $dt => $dc) {
        try {
            foreach ($pdo->query("PRAGMA foreign_key_list($dt)")->fetchAll() as $fk) {
                if (strcasecmp((string)$fk['from'], $dc) === 0 && strcasecmp((string)$fk['table'], 'members') === 0) {
                    $needsDetach[$dt] = $dc;
                    break;
                }
            }
        } catch (\Throwable $e) {}
    }
    if ($needsDetach) {
        // WICHTIG: FK-Prüfung aus, sonst würde DROP TABLE die Kind-Tabellen (Stimmen/Optionen/Belege) leerkaskadieren
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($needsDetach as $dt => $dc) {
            $sql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '$dt'")->fetchColumn();
            // Nur die FK-Klausel HINTER genau dieser Spalte entfernen (Definition sonst unverändert)
            $new = preg_replace('/(\b' . $dc . '\b[^,()]*?)\s+REFERENCES\s+"?members"?\s*\(\s*"?id"?\s*\)(\s+ON\s+DELETE\s+(CASCADE|SET\s+NULL))?/i', '$1', $sql, 1);
            if (!is_string($new) || $new === $sql) continue; // nicht erkannt → lieber nichts anfassen
            $new = preg_replace('/^\s*CREATE\s+TABLE\s+"?' . $dt . '"?/i', 'CREATE TABLE ' . $dt . '_neu', $new, 1);
            $pdo->exec($new);
            $pdo->exec("INSERT INTO {$dt}_neu SELECT * FROM $dt");
            $pdo->exec("DROP TABLE $dt");
            $pdo->exec("ALTER TABLE {$dt}_neu RENAME TO $dt");
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    // Tabelle einer nicht mehr vorhandenen Funktion aus Bestands-Datenbanken räumen.
    // Idempotent – auf frischen Installationen ist ohnehin nichts zu tun.
    $pdo->exec('DROP TABLE IF EXISTS feature_plans');

    // Auszahlungsaufforderungen: 1 Blatt = 1..n Posten. Alt-Zeilen mit genau einem Posten in
    // den Spalten von stupa_claims wandern hier einmalig in ihren eigenen Posten; die Spalten
    // bleiben stehen (SQLite), werden aber nicht gelesen.
    if (in_array('stupa_positions', array_column($pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name'), true)
        && !$pdo->query("SELECT value FROM settings WHERE key = 'stupa_positions_migrated'")->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO stupa_positions(claim_id, applicant, institution, purpose,
                                                          amount_cents, account_holder, fund, iban, sort)
                              VALUES(?,?,?,?,?,?,?,?,0)');
        foreach ($pdo->query('SELECT c.* FROM stupa_claims c
                              WHERE NOT EXISTS (SELECT 1 FROM stupa_positions p WHERE p.claim_id = c.id)')->fetchAll() as $c) {
            $ins->execute([(int)$c['id'], (string)$c['applicant'], (string)($c['institution'] ?? ''),
                           (string)$c['purpose'], (int)$c['amount_cents'], (string)$c['account_holder'],
                           (string)($c['fund'] ?? ''), (string)$c['iban']]);
        }
        $pdo->exec("INSERT OR REPLACE INTO settings(key, value) VALUES('stupa_positions_migrated', '1')");
    }

    // Einladungsvorlage einmalig aus dem Quelltext in die Einstellungen holen. Sie ist Inhalt
    // des Sekretariats, kein Programmteil: Sie nennt einen Kurs-Zugang samt Passwort und
    // schließt mit einer persönlichen Signatur. Beides gehört in die Datenbank DIESER
    // Installation, nicht in ein Programm, das andere übernehmen können sollen. Eine bereits
    // gespeicherte eigene Vorlage bleibt unangetastet.
    $vorlageDa = (string)$pdo->query("SELECT value FROM settings WHERE key = 'invite_template'")->fetchColumn();
    if (trim($vorlageDa) === '') {
        $stV = $pdo->prepare("INSERT OR REPLACE INTO settings(key, value) VALUES('invite_template', ?)");
        $stV->execute([default_invite_template()]);
    }
}

function init_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS members (
        id          INTEGER PRIMARY KEY,
        name        TEXT NOT NULL,
        email       TEXT NOT NULL DEFAULT '',
        notify_email TEXT NOT NULL DEFAULT '',
        is_admin    INTEGER NOT NULL DEFAULT 0,
        role        TEXT NOT NULL DEFAULT 'member',
        ical_token  TEXT,
        reminder_lead_days INTEGER,
        notify_assignment  INTEGER NOT NULL DEFAULT 1,
        shift_mail         INTEGER NOT NULL DEFAULT 1,  -- Kanalwahl je Benachrichtigungs-Art: Mail/Push getrennt
        push_shift         INTEGER NOT NULL DEFAULT 1,
        assign_mail        INTEGER NOT NULL DEFAULT 1,
        push_assign        INTEGER NOT NULL DEFAULT 1,
        push_msgs          INTEGER NOT NULL DEFAULT 1,  -- Dashboard-Nachrichten als Push
        push_reminders     INTEGER NOT NULL DEFAULT 1,  -- übrige Erinnerungen (Abstimmung, Fristen, Bericht, Lesen) als Push
        score_adjust       INTEGER NOT NULL DEFAULT 0,
        color       TEXT NOT NULL DEFAULT '#0E5C73',
        referat     TEXT NOT NULL DEFAULT '',
        joined_at   TEXT NOT NULL DEFAULT '',
        active      INTEGER NOT NULL DEFAULT 1,
        sort        INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS shift_reminders (
        id        INTEGER PRIMARY KEY,
        slot_id   INTEGER NOT NULL,
        member_id INTEGER NOT NULL,
        sent_at   TEXT NOT NULL,
        UNIQUE(slot_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS legislatures (
        id           INTEGER PRIMARY KEY,
        name         TEXT NOT NULL,
        start_date   TEXT NOT NULL DEFAULT '',
        start_number INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS login_tokens (
        id         INTEGER PRIMARY KEY,
        token_hash TEXT NOT NULL,
        code_hash  TEXT,
        email      TEXT NOT NULL,
        expires_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS remember_tokens (
        id         INTEGER PRIMARY KEY,
        token_hash TEXT NOT NULL,
        email      TEXT NOT NULL,
        member_id  INTEGER,
        expires_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS stepup_tokens (
        id         INTEGER PRIMARY KEY,
        token_hash TEXT NOT NULL,
        member_id  INTEGER NOT NULL,
        purpose    TEXT NOT NULL DEFAULT 'techpw',
        expires_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS events (
        id                    INTEGER PRIMARY KEY,
        title                 TEXT NOT NULL,
        description           TEXT NOT NULL DEFAULT '',
        target_helpers        INTEGER NOT NULL DEFAULT 0,
        max_shifts_per_member INTEGER,
        deadline              TEXT,
        starts_at             TEXT,
        ends_at               TEXT,
        important             INTEGER NOT NULL DEFAULT 0,
        req_label             TEXT NOT NULL DEFAULT '',   -- Merkmal 1 (z. B. „Führerschein")
        req_label2            TEXT NOT NULL DEFAULT '',   -- Merkmal 2 (optional)
        req_label3            TEXT NOT NULL DEFAULT '',   -- Merkmal 3 (optional)
        closed                INTEGER NOT NULL DEFAULT 0,
        plan_locked           INTEGER NOT NULL DEFAULT 0,
        created_by            INTEGER,
        created_at            TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS event_owners (
        event_id  INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        PRIMARY KEY (event_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS assignments (
        id          INTEGER PRIMARY KEY,
        slot_id     INTEGER NOT NULL REFERENCES event_slots(id) ON DELETE CASCADE,
        member_id   INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        notified_at TEXT,
        self_claimed INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(slot_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS shift_swaps (
        id            INTEGER PRIMARY KEY,
        event_id      INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        slot_id       INTEGER NOT NULL REFERENCES event_slots(id) ON DELETE CASCADE,  -- angebotene Schicht (X)
        offered_by    INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,      -- bietet X an (A)
        status        TEXT NOT NULL DEFAULT 'open',   -- open | done | cancelled
        mode          TEXT NOT NULL DEFAULT 'swap',    -- swap (1:1) | giveaway (einfach abgeben)
        taken_slot_id INTEGER,                          -- im Tausch hergegebene Schicht (Y)
        taken_by      INTEGER,                          -- nimmt X, gibt Y (B)
        created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        resolved_at   TEXT
    );
    CREATE TABLE IF NOT EXISTS swap_proposals (
        id               INTEGER PRIMARY KEY,
        swap_id          INTEGER NOT NULL REFERENCES shift_swaps(id) ON DELETE CASCADE, -- Angebot (A bietet X)
        proposer_id      INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,     -- schlägt vor (B)
        proposer_slot_id INTEGER NOT NULL REFERENCES event_slots(id) ON DELETE CASCADE, -- B gibt diese Schicht (Y) her
        status           TEXT NOT NULL DEFAULT 'pending',  -- pending | accepted | declined | withdrawn
        created_at       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        resolved_at      TEXT
    );
    CREATE TABLE IF NOT EXISTS reminder_log (
        id        INTEGER PRIMARY KEY,
        event_id  INTEGER NOT NULL,
        member_id INTEGER NOT NULL,
        sent_on   TEXT NOT NULL,
        UNIQUE(event_id, member_id, sent_on)
    );
    CREATE TABLE IF NOT EXISTS event_slots (
        id        INTEGER PRIMARY KEY,
        event_id  INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        starts_at TEXT NOT NULL,
        ends_at   TEXT,
        label     TEXT NOT NULL DEFAULT '',
        location  TEXT NOT NULL DEFAULT '',
        target_helpers INTEGER NOT NULL DEFAULT 0,
        req_count INTEGER NOT NULL DEFAULT 0,   -- benötigte Träger:innen Merkmal 1
        req_count2 INTEGER NOT NULL DEFAULT 0,  -- benötigte Träger:innen Merkmal 2
        req_count3 INTEGER NOT NULL DEFAULT 0,  -- benötigte Träger:innen Merkmal 3
        external_helpers TEXT NOT NULL DEFAULT '',  -- externe Helfer (Namen, eine pro Zeile); zählen wie Eingeteilte
        sort      INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS gettogethers (
        id          INTEGER PRIMARY KEY,
        title       TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        starts_at   TEXT NOT NULL,
        ends_at     TEXT,
        location    TEXT NOT NULL DEFAULT '',
        created_by  INTEGER,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS gettogether_rsvp (
        gettogether_id INTEGER NOT NULL REFERENCES gettogethers(id) ON DELETE CASCADE,
        member_id      INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        status         TEXT NOT NULL DEFAULT 'yes',   -- yes | no
        updated_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (gettogether_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS gettogether_items (
        id             INTEGER PRIMARY KEY,
        gettogether_id INTEGER NOT NULL REFERENCES gettogethers(id) ON DELETE CASCADE,
        label          TEXT NOT NULL,
        is_need        INTEGER NOT NULL DEFAULT 0,   -- 1 = vom Orga als Bedarf gesetzt, 0 = freier Beitrag
        brought_by     INTEGER REFERENCES members(id) ON DELETE SET NULL, -- wer es mitbringt (NULL = offener Bedarf)
        created_by     INTEGER,
        sort           INTEGER NOT NULL DEFAULT 0,
        created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS event_member_attr (
        event_id  INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        has_attr  INTEGER NOT NULL DEFAULT 0,   -- hat Merkmal 1
        has_attr2 INTEGER NOT NULL DEFAULT 0,   -- hat Merkmal 2
        has_attr3 INTEGER NOT NULL DEFAULT 0,   -- hat Merkmal 3
        PRIMARY KEY (event_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS responses (
        id         INTEGER PRIMARY KEY,
        slot_id    INTEGER NOT NULL REFERENCES event_slots(id) ON DELETE CASCADE,
        member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        status     TEXT NOT NULL DEFAULT 'no',
        comment    TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(slot_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS meetings (
        id             INTEGER PRIMARY KEY,
        title          TEXT NOT NULL DEFAULT '',
        starts_at      TEXT NOT NULL,
        ends_at        TEXT,
        location       TEXT NOT NULL DEFAULT '',
        description    TEXT NOT NULL DEFAULT '',
        legislature_id INTEGER,
        kind           TEXT NOT NULL DEFAULT 'ordentlich',
        cancelled      INTEGER NOT NULL DEFAULT 0,
        needs_report   INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS reports (
        id          INTEGER PRIMARY KEY,
        referat     TEXT NOT NULL,
        meeting_id  INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        content     TEXT NOT NULL DEFAULT '',
        updated_by  INTEGER,
        updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(referat, meeting_id)
    );
    CREATE TABLE IF NOT EXISTS meeting_rsvp (
        meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        status     TEXT NOT NULL,            -- abgemeldet (entschuldigt) | online (nimmt online teil)
        reason     TEXT NOT NULL DEFAULT '', -- Grund; wird nur dem Vorsitz weitergeleitet
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(meeting_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS meeting_noshows (   -- vom Vorsitz nachgetragen: unentschuldigt gefehlt (Basis-Score)
        meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(meeting_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS info_reads (        -- „Gelesen/Erledigt"-Haken je Wichtiger Information
        info_id   INTEGER NOT NULL REFERENCES infos(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        read_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (info_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS activity_weeks (    -- „App geöffnet"-Wochen (ISO, z. B. 2026-W27) je Mitglied
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        week      TEXT NOT NULL,
        PRIMARY KEY (member_id, week)
    );
    CREATE TABLE IF NOT EXISTS report_reminders (
        meeting_id INTEGER NOT NULL,
        member_id  INTEGER NOT NULL,
        sent_on    TEXT NOT NULL,
        UNIQUE(meeting_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS voteitem_reminders (
        meeting_id INTEGER NOT NULL,
        member_id  INTEGER NOT NULL,
        sent_on    TEXT NOT NULL,
        UNIQUE(meeting_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS referate (
        id    INTEGER PRIMARY KEY,
        name  TEXT NOT NULL,
        sort  INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS automation_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        automation TEXT NOT NULL,
        ref_id     INTEGER NOT NULL,
        status     TEXT NOT NULL,
        detail     TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS event_templates (
        id         INTEGER PRIMARY KEY,
        owner_id   INTEGER,
        name       TEXT NOT NULL,
        payload    TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS dashboard_messages (
        id           INTEGER PRIMARY KEY,
        recipient_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        sender_name  TEXT NOT NULL DEFAULT '',
        body         TEXT NOT NULL,
        event_id     INTEGER,
        link         TEXT NOT NULL DEFAULT '',   -- optionales internes Sprungziel (z. B. profil.php#pinnwand) für „Ansehen"
        is_announce  INTEGER NOT NULL DEFAULT 0,
        created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        read_at      TEXT
    );
    CREATE TABLE IF NOT EXISTS infos (
        id         INTEGER PRIMARY KEY,
        title      TEXT NOT NULL,
        body       TEXT NOT NULL DEFAULT '',
        sort       INTEGER NOT NULL DEFAULT 0,
        referat    TEXT NOT NULL DEFAULT '',   -- leer = für alle sichtbar, sonst nur fürs genannte Referat
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS info_files (
        id          INTEGER PRIMARY KEY,
        info_id     INTEGER NOT NULL REFERENCES infos(id) ON DELETE CASCADE,
        orig_name   TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime        TEXT NOT NULL DEFAULT '',
        size        INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    -- Wegweiser: das Verzeichnis aller Dienste, Zugänge und Ansprechpartner:innen.
    -- Ein Eintrag kann Web-Adresse, E-Mail und Telefon zugleich haben (Dienst ODER Person –
    -- bewusst EIN Eintragstyp, weil „Studierendenwerk" mal Seite, mal Mensch ist).
    CREATE TABLE IF NOT EXISTS wegweiser (
        id         INTEGER PRIMARY KEY,
        title      TEXT NOT NULL,
        url        TEXT NOT NULL DEFAULT '',
        email      TEXT NOT NULL DEFAULT '',
        phone      TEXT NOT NULL DEFAULT '',
        note       TEXT NOT NULL DEFAULT '',   -- ein, zwei Sätze: wofür ist das gut
        grp        TEXT NOT NULL DEFAULT '',   -- Rubrik, frei wählbar (leer = „Sonstiges")
        icon       TEXT NOT NULL DEFAULT '',   -- Tabler-Name OHNE „ti-“; leer = automatisch aus der Adresse
        referat    TEXT NOT NULL DEFAULT '',   -- leer = für alle sichtbar, sonst nur fürs genannte Referat
        sort       INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS vote_items (
        id         INTEGER PRIMARY KEY,
        meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        title      TEXT NOT NULL,
        body       TEXT NOT NULL DEFAULT '',
        sort       INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS vote_item_files (
        id          INTEGER PRIMARY KEY,
        item_id     INTEGER NOT NULL REFERENCES vote_items(id) ON DELETE CASCADE,
        orig_name   TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime        TEXT NOT NULL DEFAULT '',
        size        INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS vote_item_reads (
        item_id   INTEGER NOT NULL REFERENCES vote_items(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        read_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (item_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS bug_reports (
        id         INTEGER PRIMARY KEY,
        title      TEXT NOT NULL,
        body       TEXT NOT NULL DEFAULT '',
        status     TEXT NOT NULL DEFAULT 'open',   -- open | done
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS bug_comments (
        id         INTEGER PRIMARY KEY,
        bug_id     INTEGER NOT NULL REFERENCES bug_reports(id) ON DELETE CASCADE,
        member_id  INTEGER,
        body       TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS feedback_items (   -- Rückmeldungen (Lob/Idee/Kritik/Frage) als Ticket, nicht als Mail
        id         INTEGER PRIMARY KEY,
        kind       TEXT NOT NULL DEFAULT 'idee',  -- lob | idee | kritik | frage
        body       TEXT NOT NULL DEFAULT '',
        page       TEXT NOT NULL DEFAULT '',      -- Seite, von der abgeschickt wurde (Kontext für Technik)
        status     TEXT NOT NULL DEFAULT 'new',   -- new | accepted | done | rejected
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS feedback_comments (
        id          INTEGER PRIMARY KEY,
        feedback_id INTEGER NOT NULL REFERENCES feedback_items(id) ON DELETE CASCADE,
        member_id   INTEGER,
        body        TEXT NOT NULL DEFAULT '',
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS top_submissions (
        id          INTEGER PRIMARY KEY,
        meeting_id  INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        member_id   INTEGER,
        title       TEXT NOT NULL,
        internal    INTEGER NOT NULL DEFAULT 0,
        anchor      INTEGER,
        time_est    TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS absences (
        id        INTEGER PRIMARY KEY,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        starts_at TEXT NOT NULL,
        ends_at   TEXT NOT NULL,
        reason    TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS notify_prefs (        -- persönliche Mitteilungs-Einstellungen (nur Abweichungen vom Standard)
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        type      TEXT NOT NULL,                     -- Schlüssel aus notify_types()
        mail      INTEGER,                           -- NULL = Standard des Typs
        push      INTEGER,                           -- NULL = Standard des Typs
        timing    INTEGER,                           -- NULL = Standard-Vorlauf des Typs
        PRIMARY KEY (member_id, type)
    );
    CREATE TABLE IF NOT EXISTS push_subscriptions (  -- Web-Push-Abos (ein Eintrag je Gerät/Browser)
        id         INTEGER PRIMARY KEY,
        member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        endpoint   TEXT NOT NULL UNIQUE,             -- URL beim Push-Dienst des Browsers (FCM/Apple/Mozilla)
        p256dh     TEXT NOT NULL,                    -- öffentlicher Client-Schlüssel (base64url)
        auth       TEXT NOT NULL,                    -- Auth-Secret (base64url, 16 Bytes)
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS member_achievements ( -- freigeschaltete Achievements je Mitglied (Code aus achievements_catalog())
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        code      TEXT NOT NULL,
        earned_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (member_id, code)
    );
    CREATE TABLE IF NOT EXISTS expense_claims (      -- eingereichte Belegblätter für Auslagen (Rolle Finanzen arbeitet sie ab)
        id          INTEGER PRIMARY KEY,
        member_id   INTEGER NOT NULL,                -- bewusst OHNE FK: Finanz-Historie überlebt das Löschen der Person
        name        TEXT NOT NULL,                   -- Name, Vorname (wie auf dem Belegblatt)
        street      TEXT NOT NULL DEFAULT '',
        city        TEXT NOT NULL DEFAULT '',        -- PLZ und Ort
        bank        TEXT NOT NULL DEFAULT '',
        bic         TEXT NOT NULL DEFAULT '',
        iban        TEXT NOT NULL,
        source      TEXT NOT NULL DEFAULT 'asta',    -- beantragt von: asta | stupa | projekt
        source_note TEXT NOT NULL DEFAULT '',        -- Freitext bei „Projekt/Sonstiges"
        purpose     TEXT NOT NULL,                   -- Für (Veranstaltung/Projekt/…)
        items       TEXT NOT NULL,                   -- JSON-Liste der Positionen [{d,s,b}] (b in Cent)
        total_cents INTEGER NOT NULL DEFAULT 0,
        status      TEXT NOT NULL DEFAULT 'open',    -- open | done (Überweisung erledigt)
        by_finance  INTEGER NOT NULL DEFAULT 0,      -- 1 = von Finanzen für jemand anderen erfasst (Papierbeleg), 0 = selbst eingereicht
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        done_at     TEXT,
        done_by     INTEGER
    );
    CREATE TABLE IF NOT EXISTS expense_files (      -- angeheftete Belege (Fotos/PDFs) zu einem Belegblatt
        id          INTEGER PRIMARY KEY,
        claim_id    INTEGER NOT NULL REFERENCES expense_claims(id) ON DELETE CASCADE,
        comment_id  INTEGER,                         -- gesetzt, wenn die Datei an einer Nachricht hängt (sonst Einreichung)
        orig_name   TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime        TEXT NOT NULL DEFAULT '',
        size        INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS expense_comments (    -- Nachrichten-Verlauf zu einem Belegblatt (Rückfragen/Antworten)
        id           INTEGER PRIMARY KEY,
        claim_id     INTEGER NOT NULL REFERENCES expense_claims(id) ON DELETE CASCADE,
        member_id    INTEGER NOT NULL DEFAULT 0,     -- Autor:in (0 = Technik-Login)
        from_finance INTEGER NOT NULL DEFAULT 0,     -- 1 = im Namen von Finanzen geschrieben
        body         TEXT NOT NULL,
        created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS termine (            -- freie Kalender-Termine (für sich, bestimmte Leute oder alle)
        id          INTEGER PRIMARY KEY,
        title       TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        location    TEXT NOT NULL DEFAULT '',
        starts_at   TEXT NOT NULL,
        ends_at     TEXT,
        all_day     INTEGER NOT NULL DEFAULT 0,
        audience    TEXT NOT NULL DEFAULT 'self',    -- self | members | all
        created_by  INTEGER NOT NULL,                -- OHNE FK: geteilte Termine überleben das Löschen der Person
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS termin_members (      -- Empfänger:innen bei audience='members'
        termin_id INTEGER NOT NULL REFERENCES termine(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        PRIMARY KEY (termin_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS date_polls (          -- Terminfinder: mehrere Datumsvorschläge zur Abstimmung
        id          INTEGER PRIMARY KEY,
        title       TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        location    TEXT NOT NULL DEFAULT '',
        created_by  INTEGER NOT NULL,                -- OHNE FK: Terminfinder samt Stimmen überlebt das Löschen der Ersteller:in
        closed      INTEGER NOT NULL DEFAULT 0,
        deadline    TEXT,                             -- „Abstimmen bis" (danach automatisch zu; leer = kein Enddatum)
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS date_poll_options (   -- ein Datumsvorschlag je Zeile
        id        INTEGER PRIMARY KEY,
        poll_id   INTEGER NOT NULL REFERENCES date_polls(id) ON DELETE CASCADE,
        starts_at TEXT NOT NULL,
        ends_at   TEXT,
        sort      INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS date_poll_votes (     -- Stimme je Option/Mitglied (yes|maybe|no)
        option_id INTEGER NOT NULL REFERENCES date_poll_options(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        vote      TEXT NOT NULL DEFAULT 'no',
        PRIMARY KEY (option_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS circular_votes (      -- Umlaufverfahren: Ja/Nein/Enthaltung mit Frist
        id             INTEGER PRIMARY KEY,
        title          TEXT NOT NULL DEFAULT '',
        description    TEXT NOT NULL DEFAULT '',
        created_by     INTEGER NOT NULL,            -- OHNE FK: das Beschluss-Archiv überlebt das Löschen der Ersteller:in
        deadline       TEXT NOT NULL DEFAULT '',    -- "Abstimmen bis" (Y-m-d H:i, Pflicht)
        secret         INTEGER NOT NULL DEFAULT 0,  -- 1 = geheim (nur Zählung, nie Namen)
        rule           TEXT NOT NULL DEFAULT 'simple', -- simple | absolute | unanimous
        quorum_pct     INTEGER NOT NULL DEFAULT 0,  -- Mindestbeteiligung in % (0 = keine)
        status         TEXT NOT NULL DEFAULT 'open',-- open | closed | withdrawn
        result         TEXT,                        -- accepted | rejected | no_quorum (bei closed)
        eligible_count INTEGER,                     -- Stimmberechtigte beim Abschluss (Momentaufnahme)
        closed_at      TEXT,
        created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS circular_ballots (    -- eine Stimme je Mitglied und Umlauf
        vote_id   INTEGER NOT NULL REFERENCES circular_votes(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        choice    TEXT NOT NULL,                     -- yes | no | abstain
        voted_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (vote_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS umlauf_reminder_log ( -- höchstens 1 Erinnerung je Tag/Mitglied/Umlauf
        vote_id   INTEGER NOT NULL,
        member_id INTEGER NOT NULL,
        sent_on   TEXT NOT NULL,
        UNIQUE(vote_id, member_id, sent_on)
    );
    CREATE TABLE IF NOT EXISTS simple_polls (        -- normale Abstimmungen/Umfragen (freie Antwortoptionen)
        id          INTEGER PRIMARY KEY,
        title       TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        created_by  INTEGER NOT NULL,                -- OHNE FK: Abstimmungen überleben das Löschen der Ersteller:in
        deadline    TEXT,                            -- optional; bei verpflichtenden Pflicht
        secret      INTEGER NOT NULL DEFAULT 0,      -- 1 = geheim (nur Zählung, nie Namen)
        multi       INTEGER NOT NULL DEFAULT 0,      -- 1 = Mehrfachauswahl erlaubt
        mandatory   INTEGER NOT NULL DEFAULT 0,      -- 1 = verpflichtend (Pflichtaufgabe + Erinnerungen)
        status      TEXT NOT NULL DEFAULT 'open',    -- open | closed
        closed_at   TEXT,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS simple_poll_options ( -- eine Antwortoption je Zeile
        id      INTEGER PRIMARY KEY,
        poll_id INTEGER NOT NULL REFERENCES simple_polls(id) ON DELETE CASCADE,
        label   TEXT NOT NULL,
        sort    INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS simple_poll_votes (   -- Kreuz(e) je Mitglied (bei multi mehrere Zeilen)
        option_id INTEGER NOT NULL REFERENCES simple_poll_options(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        PRIMARY KEY (option_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS poll_reminder_log (   -- höchstens 1 Erinnerung je Tag/Mitglied/Abstimmung
        poll_id   INTEGER NOT NULL,
        member_id INTEGER NOT NULL,
        sent_on   TEXT NOT NULL,
        UNIQUE(poll_id, member_id, sent_on)
    );
    CREATE TABLE IF NOT EXISTS poll_hides (          -- freiwillige Abstimmung vom Dashboard ausgeblendet
        poll_id   INTEGER NOT NULL REFERENCES simple_polls(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        PRIMARY KEY (poll_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS profile_posts (       -- Pinnwand auf dem Nutzerprofil (nette Einträge anderer)
        id         INTEGER PRIMARY KEY,
        profile_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        author_id  INTEGER NOT NULL,                 -- OHNE FK: Einträge sterben mit dem PROFIL, nicht mit der Autor:in
        parent_id  INTEGER NOT NULL DEFAULT 0,       -- 0 = Top-Eintrag; >0 = Antwort auf profile_posts.id (Konversation)
        anonymous  INTEGER NOT NULL DEFAULT 0,       -- 1 = ohne Namen angezeigt (Autor:in bleibt technisch gespeichert)
        body       TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS umlauf_files (        -- Anhänge zu Umlaufbeschlüssen und Abstimmungen
        id          INTEGER PRIMARY KEY,
        kind        TEXT NOT NULL,                   -- umlauf | poll
        ref_id      INTEGER NOT NULL,                -- circular_votes.id bzw. simple_polls.id
        orig_name   TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime        TEXT NOT NULL DEFAULT '',
        size        INTEGER NOT NULL DEFAULT 0,
        uploaded_by INTEGER,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS teams_files (         -- „In Teams öffnen": Spiegel-Doks in der Teams-Bibliothek
        id         INTEGER PRIMARY KEY,
        kind       TEXT NOT NULL,                    -- info | vfile | protokoll | protokoll_intern
        ref_id     INTEGER NOT NULL,                 -- info_files.id / vote_item_files.id / meetings.id
        drive_id   TEXT NOT NULL,
        item_id    TEXT NOT NULL,
        web_url    TEXT NOT NULL,
        teams_url  TEXT,                             -- Deep-Link in die Teams-App (lazy nachgebaut)
        name       TEXT NOT NULL DEFAULT '',
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(kind, ref_id)
    );
    CREATE TABLE IF NOT EXISTS stupa_users (        -- Zugänge des StuPa-Präsidiums – BEWUSST nicht in members
        id            INTEGER PRIMARY KEY,           -- (sonst liefe das Konto in Score, Einteilung, Mitteilungen mit)
        name          TEXT NOT NULL,
        email         TEXT NOT NULL,
        active        INTEGER NOT NULL DEFAULT 1,
        created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        last_login_at TEXT
    );
    CREATE UNIQUE INDEX IF NOT EXISTS stupa_users_mail ON stupa_users(email);
    CREATE TABLE IF NOT EXISTS stupa_tokens (       -- Anmelde-Links: Einmal-Token mit Ablauf
        id         INTEGER PRIMARY KEY,
        user_id    INTEGER NOT NULL REFERENCES stupa_users(id) ON DELETE CASCADE,
        token_hash TEXT NOT NULL,                    -- nur der Hash liegt hier, nie der Link selbst
        expires_at TEXT NOT NULL,
        used_at    TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS stupa_claims (       -- Auszahlungsaufforderungen des StuPa-Präsidiums
        id             INTEGER PRIMARY KEY,
        created_by     INTEGER NOT NULL,             -- stupa_users.id, bewusst OHNE FK (Historie überlebt das Konto)
        applicant      TEXT NOT NULL,                -- Antragssteller:in
        institution    TEXT NOT NULL DEFAULT '',     -- ggf. Institution/Gremium
        purpose        TEXT NOT NULL,                -- Verwendungszweck
        amount_cents   INTEGER NOT NULL DEFAULT 0,   -- Geldbetrag
        account_holder TEXT NOT NULL,                -- Kontoinhaber:in (kann von Antragssteller:in abweichen)
        fund           TEXT NOT NULL DEFAULT '',     -- Topf
        iban           TEXT NOT NULL,
        status         TEXT NOT NULL DEFAULT 'open', -- open | done (Überweisung erledigt)
        notify_done    INTEGER NOT NULL DEFAULT 0,   -- 1 = Präsidium will eine Mail, wenn erledigt (Häkchen, Standard aus)
        created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        done_at        TEXT,
        done_by        INTEGER
    );
    CREATE TABLE IF NOT EXISTS stupa_files (       -- Belege zu einer Auszahlungsaufforderung
        id          INTEGER PRIMARY KEY,
        claim_id    INTEGER NOT NULL REFERENCES stupa_claims(id) ON DELETE CASCADE,
        comment_id  INTEGER,                         -- gesetzt, wenn die Datei an einer Nachricht hängt
        orig_name   TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime        TEXT NOT NULL DEFAULT '',
        size        INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS stupa_positions (   -- Einzelposten einer Aufforderung (1 Blatt = 1..n Posten)
        id             INTEGER PRIMARY KEY,
        claim_id       INTEGER NOT NULL REFERENCES stupa_claims(id) ON DELETE CASCADE,
        applicant      TEXT NOT NULL,                -- Antragssteller:in
        institution    TEXT NOT NULL DEFAULT '',     -- ggf. Institution/Gremium
        purpose        TEXT NOT NULL,                -- Verwendungszweck
        amount_cents   INTEGER NOT NULL DEFAULT 0,
        account_holder TEXT NOT NULL,                -- Kontoinhaber:in (kann abweichen)
        fund           TEXT NOT NULL DEFAULT '',     -- Topf
        iban           TEXT NOT NULL,
        sort           INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS stupa_comments (    -- Rückfragen zwischen Präsidium und Finanzen
        id         INTEGER PRIMARY KEY,
        claim_id   INTEGER NOT NULL REFERENCES stupa_claims(id) ON DELETE CASCADE,
        from_stupa INTEGER NOT NULL DEFAULT 0,      -- 1 = Präsidium, 0 = Finanzen. WICHTIG: author_id
        author_id  INTEGER NOT NULL DEFAULT 0,      -- zeigt je nach Seite auf stupa_users ODER members –
        body       TEXT NOT NULL,                   -- getrennte Nummernkreise, nie ohne from_stupa lesen!
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS nc_files (            -- „In Nextcloud öffnen": Spiegel-Doks in der Nextcloud
        id         INTEGER PRIMARY KEY,
        kind       TEXT NOT NULL,                    -- gleiche Bereiche wie teams_files (mirror_kinds())
        ref_id     INTEGER NOT NULL,                 -- info_files.id / vote_item_files.id / umlauf_files.id / meetings.id
        path       TEXT NOT NULL,                    -- Pfad relativ zur WebDAV-Wurzel des Kontos
        file_id    TEXT NOT NULL DEFAULT '',         -- oc:fileid – überlebt Umbenennen/Verschieben, Basis des /f/…-Links
        name       TEXT NOT NULL DEFAULT '',
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(kind, ref_id)
    );
    CREATE TABLE IF NOT EXISTS kudos (          -- „Props": anonyme Anerkennung zwischen Mitgliedern
        id         INTEGER PRIMARY KEY,
        from_id    INTEGER REFERENCES members(id) ON DELETE CASCADE, -- NULL = reine Zahlen-Korrektur durch Admin (kein Absender, kein Kontingent)
        to_id      INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        ym         TEXT NOT NULL,               -- Vergabemonat 'YYYY-MM' (Monatskontingent)
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        seen_at    TEXT                         -- NULL = Empfänger:in hat den Toast noch nicht gesehen
    );
    -- „höchstens 1 pro Empfänger:in und Monat" gilt nur für ECHTE Vergaben; Admin-Zeilen (from_id NULL) beliebig oft
    CREATE UNIQUE INDEX IF NOT EXISTS kudos_peer_unique ON kudos(from_id, to_id, ym) WHERE from_id IS NOT NULL;
    CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT
    );
    SQL);
}

// ---------------------------------------------------------------------------
// Einstellungen (key/value)
// ---------------------------------------------------------------------------
function setting_get(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function setting_set(string $key, string $value): void
{
    $st = db()->prepare(
        'INSERT INTO settings(key,value) VALUES(?,?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $st->execute([$key, $value]);
}

/* --- Träger dieser Installation ------------------------------------------------------
 * Wer die App betreibt, steht an genau einer Stelle: in den Einstellungen, gepflegt unter
 * Verwaltung → Träger. Im Programm steht nur ein neutraler Rückfallwert – kein Name, keine
 * Adresse, kein Logo. Wer die App aufsetzt, trägt seins ein und fasst keine Zeile Programm an. Die öffentlichen Bereiche (termin/, pat/, veranstaltungen/, umfrage/)
 * dürfen lib.php nicht einbinden und führen dieselben Werte in ihren eigenen Einstellungen.
 */

/** Name des Trägers, ausgeschrieben – für Absender, Auszeichnungen und Fußzeilen. */
function org_name(): string
{
    return (string)setting_get('org_name', 'Studierendenvertretung');
}

/** Trägername in der Kurzform, wie ihn die öffentlichen Seiten in der Kopfzeile führen. */
function org_name_kurz(): string
{
    return (string)setting_get('org_name_kurz', 'Studierendenvertretung');
}

/** Web-Auftritt des Trägers, ohne Schluss-Schrägstrich. */
function org_url(): string
{
    return rtrim((string)setting_get('org_url', ''), '/');
}

/**
 * Die Domain des Trägers – aus der Kontaktadresse, sonst aus dem Web-Auftritt. Dient den
 * Beispielen in Eingabefeldern: Ein Platzhalter „name@beispiel.de" hilft weniger als einer,
 * der die eigene Domain zeigt. Ohne beides bleibt es bei „beispiel.de".
 */
function org_domain(): string
{
    $mail = (string)setting_get('org_kontakt_mail', '');
    if (str_contains($mail, '@')) return substr($mail, strpos($mail, '@') + 1);
    $host = (string)parse_url(org_url(), PHP_URL_HOST);
    return $host !== '' ? preg_replace('~^www\.~', '', $host) : 'beispiel.de';
}

/** Sitz des Trägers – steht als Ortszeile über dem Datum in den erzeugten Dokumenten. */
function org_ort(): string
{
    return (string)setting_get('org_ort', '');
}

/** Beispiel-Adresse für Platzhalter: org_mail_beispiel('name') → „name@<Domain>". */
function org_mail_beispiel(string $lokal): string
{
    return $lokal . '@' . org_domain();
}

/** Adresse für Rückmeldungen und Fehlermeldungen aus der App. */
function org_kontakt_mail(): string
{
    return (string)setting_get('org_kontakt_mail', '');
}

// --- Cron-Herzschlag: Läufe stempeln + Stillstand erkennen (Frühwarnung) -----
/** Am Ende eines erfolgreichen Cron-Laufs aufrufen (which: 'reminders' | 'automations'). */
function cron_stamp(string $which): void
{
    setting_set('cron_last_' . $which, date('Y-m-d H:i:s'));
}

function cron_last(string $which): ?string
{
    return setting_get('cron_last_' . $which);
}

/**
 * Alle zeitgesteuerten Läufe der App an EINER Stelle: Datei, Takt, Zweck, wann er nötig ist.
 * Die Verwaltung baut ihre Anleitung hieraus, und der Selbsttest prüft, dass zu JEDER
 * cron_*.php im Ordner ein Eintrag existiert – wer einen Lauf anlegt, kommt daran nicht vorbei.
 *
 * Schlüssel = der Name im Herzschlag-Stempel (cron_stamp/cron_last).
 * 'frist' = wie alt der letzte Lauf werden darf, bevor die Verwaltung ihn als ausgefallen
 * meldet. Eigenes Feld, weil sich aus dem Takt-Text nicht ableiten lässt, ab wann Stille
 * verdächtig ist.
 */
function cron_jobs(): array
{
    return [
        'reminders' => [
            'datei'  => 'cron_reminders.php',
            'titel'  => 'Erinnerungen & Aufräumen',
            'takt'   => 'täglich',
            'wann'   => 'morgens, z. B. 7:00 Uhr',
            'frist'  => 93600,
            'pflicht' => true,
            'was'    => 'Der wichtigste Lauf. Verschickt alle Erinnerungen (offene Abstimmung, Einsatz, '
                      . 'fälliger Bericht, ungelesene Abstimmungsgegenstände, „Vielleicht"-Frist, Umlauf, '
                      . 'längere Inaktivität), schickt fällige Sitzungseinladungen, wertet abgelaufene '
                      . 'Umläufe und Abstimmungen aus, pflegt die Streaks und räumt Altdaten weg '
                      . '(Rauschen nach 90 Tagen, Abstimmungs-Dateien nach 40, Beleg-Anhänge nach 30).',
        ],
        'automations' => [
            'datei'  => 'cron_automations.php',
            'titel'  => 'Berichte-Dok & Protokoll-Abstimmungen',
            'takt'   => 'alle 15 Minuten',
            'wann'   => 'durchgehend – ein Leerlauf kostet ~13 ms Rechenzeit und keinen Netzaufruf',
            'frist'  => 10800,
            'pflicht' => true,
            'was'    => 'Erzeugt am Sitzungstag die Berichte-Dok und legt sie in die Ablage. Gleicht danach '
                      . 'die Protokoll-Genehmigungen mit dem Sitzungskalender ab: anlegen, wenn eine '
                      . 'Folgesitzung dazugekommen ist, und umhängen, wenn die bisherige Zielsitzung '
                      . 'abgesagt, gelöscht oder verschoben wurde.',
        ],
        'patmail' => [
            'datei'  => 'cron_patmail.php',
            'titel'  => 'Pat:innenprogramm – Versand-Netz',
            'takt'   => 'stündlich',
            'wann'   => 'zu jeder vollen Stunde',
            'frist'  => 10800,
            'pflicht' => false,
            'nurWenn' => 'ihr das Pat:innenprogramm nutzt',
            'was'    => 'Seit der Freischaltung auf 3000 Mails am Tag ist er das Netz, nicht der Weg: '
                      . '„Alle verschicken" bringt die Warteschlange normalerweise in einem Rutsch raus. '
                      . 'Dieser Lauf holt nur, was das Zeitbudget eines Klicks nicht mehr geschafft hat, '
                      . 'wiederholt fehlgeschlagene Zustellungen und leitet Fragen aus dem Hilfe-Formular weiter.',
        ],
        'umfrage' => [
            'datei'  => 'cron_umfrage.php',
            'titel'  => 'Umfragen – Nachzügler & Aufräumen',
            'takt'   => 'alle 10–15 Minuten',
            'wann'   => 'z. B. alle 15 Minuten',
            'frist'  => 10800,
            'pflicht' => false,
            'nurWenn' => 'ihr Umfragen anbietet',
            'was'    => 'Im Normalfall geht jede Bestätigungsmail sofort beim Abstimmen raus. Hier laufen '
                      . 'nur die Fälle auf, in denen das Kontingent gerade erschöpft war oder der Versand '
                      . 'klemmte – dazu das Wegräumen abgelaufener Einträge.',
        ],
        'extern' => [
            'datei'  => 'cron_extern.php',
            'titel'  => 'Externe Events – Nachzügler & Löschfristen',
            'takt'   => 'alle 10–15 Minuten',
            'wann'   => 'z. B. alle 15 Minuten',
            'frist'  => 10800,
            'pflicht' => false,
            'nurWenn' => 'ihr öffentliche Anmeldungen nutzt',
            'was'    => 'Schickt liegengebliebene Anmelde-Mails nach – und löscht, was weg muss: '
                      . 'unbestätigte Anmeldungen nach 48 Stunden und die Anmeldedaten nach der Frist. '
                      . 'Dieser zweite Teil läuft auch dann, wenn gar keine Mail wartet.',
        ],
        'waslaeuft' => [
            'datei'  => 'cron_waslaeuft.php',
            'titel'  => 'was.läuft – Push-Versand + Aufräumen',
            'takt'   => 'alle 15 Minuten',
            'wann'   => 'rund um die Uhr (*/15)',
            // Läuft er im Viertelstunden-Takt, ist alles über 2 Stunden ein Ausfall.
            'frist'  => 7200,
            'pflicht' => false,
            'nurWenn' => 'ihr das Veranstaltungsportal nutzt',
            'was'    => 'Verschickt die Push-Mitteilungen der öffentlichen Seite: „Erinnere mich" vor '
                      . 'dem Beginn und Neuigkeiten an Abonnenten von Veranstaltern oder Kategorien. '
                      . 'Der enge Takt gehört zur Sache – eine Erinnerung „2 Stunden vorher" darf nicht '
                      . 'erst abends kommen. Einmal täglich (beim ersten Lauf des Tages) löscht er '
                      . 'außerdem lange Vergangenes samt Bildern; Archivbilder bleiben immer.',
        ],
    ];
}

/** Aufruf-Adresse eines Laufs für das Cron-Feld beim Hoster (mit Schlüssel). */
function cron_job_url(string $datei, string $base = ''): string
{
    $base = rtrim($base !== '' ? $base : (string)base_url(), '/');
    return $base . '/' . $datei . '?key=' . rawurlencode((string)setting_get('cron_key', ''));
}

/**
 * Warn-Zeilen zu Crons, die noch nie oder seit >26 h nicht mehr liefen.
 * Der Automationen-Cron wird nur erwartet, wenn mindestens eine Automation aktiv ist.
 */
function cron_stale(): array
{
    $expected = ['reminders' => 'Erinnerungs-Cron (cron_reminders.php)'];
    if (auto_reportdoc_enabled()) {
        $expected['automations'] = 'Automationen-Cron (cron_automations.php)';
    }
    $out = [];
    foreach ($expected as $key => $label) {
        $last = cron_last($key);
        if (!$last) {
            $out[] = $label . ': noch nie gelaufen – bitte im Mittwald-Cron einrichten';
        } elseif (strtotime($last) < time() - 26 * 3600) {
            $out[] = $label . ': letzter Lauf ' . date('d.m.Y H:i', strtotime($last)) . ' Uhr – läuft offenbar nicht mehr';
        }
    }

    // Versand-Cron des Pat:innenprogramms: nur erwarten, solange wirklich Mails warten –
    // sonst wäre das eine Dauerwarnung für alle, die das Programm gar nicht nutzen.
    // Er läuft STÜNDLICH, daher hier ein engeres Fenster als die 26 h oben.
    try {
        if (is_file(__DIR__ . '/pat-db.php')) {
            require_once __DIR__ . '/pat-db.php';
            $wartend = (int)pat_db()->query("SELECT COUNT(*) FROM pat_mailqueue WHERE status = 'queued'")->fetchColumn();
            if ($wartend > 0) {
                $last = cron_last('patmail');
                if (!$last) {
                    $out[] = 'Versand-Cron Pat:innenprogramm (cron_patmail.php): noch nie gelaufen – '
                        . $wartend . ' Mails warten und bleiben ohne ihn liegen';
                } elseif (strtotime($last) < time() - 3 * 3600) {
                    $out[] = 'Versand-Cron Pat:innenprogramm (cron_patmail.php): letzter Lauf '
                        . date('d.m.Y H:i', strtotime($last)) . ' Uhr – ' . $wartend . ' Mails warten';
                }
            }
        }
    } catch (\Throwable $e) {
        // Pat-Datenbank nicht erreichbar: kein Grund, die übrige Cron-Warnung zu verlieren
    }

    // Umfragen: derselbe Gedanke. Im Normalfall geht jede Bestätigungsmail sofort raus und der
    // Cron wird gar nicht gebraucht – deshalb NUR warnen, wenn wirklich etwas wartet. Sonst
    // stünde bei allen, die den Cron bewusst nicht eingerichtet haben, dauerhaft eine Warnung.
    try {
        if (is_file(__DIR__ . '/umfrage-db.php')) {
            require_once __DIR__ . '/umfrage-db.php';
            $wartend = umfrage_queue_len();
            if ($wartend > 0) {
                $last = cron_last('umfrage');
                if (!$last) {
                    $out[] = 'Umfrage-Cron (cron_umfrage.php): noch nie gelaufen – ' . $wartend
                        . ' Bestätigungsmails warten und bleiben ohne ihn liegen';
                } elseif (strtotime($last) < time() - 3 * 3600) {
                    $out[] = 'Umfrage-Cron (cron_umfrage.php): letzter Lauf '
                        . date('d.m.Y H:i', strtotime($last)) . ' Uhr – ' . $wartend . ' Bestätigungsmails warten';
                }
            }
        }
    } catch (\Throwable $e) {
        // Umfrage-Datenbank nicht erreichbar: der Rest der Warnung bleibt trotzdem gültig
    }

    // Externe Events: gleiche Regel – nur melden, wenn Mails wirklich liegen bleiben.
    try {
        if (is_file(__DIR__ . '/extern-db.php')) {
            require_once __DIR__ . '/extern-db.php';
            $wartend = (int)extern_db()->query("SELECT COUNT(*) FROM mailqueue WHERE status = 'queued'")->fetchColumn();
            if ($wartend > 0) {
                $last = cron_last('extern');
                if (!$last) {
                    $out[] = 'Cron für externe Events (cron_extern.php): noch nie gelaufen – ' . $wartend
                        . ' Mails warten und bleiben ohne ihn liegen';
                } elseif (strtotime($last) < time() - 3 * 3600) {
                    $out[] = 'Cron für externe Events (cron_extern.php): letzter Lauf '
                        . date('d.m.Y H:i', strtotime($last)) . ' Uhr – ' . $wartend . ' Mails warten';
                }
            }
        }
    } catch (\Throwable $e) {
        // Externe Datenbank nicht erreichbar: der Rest der Warnung bleibt gültig
    }
    return $out;
}

/** Rote Dashboard-Warnung (Admin/Vorsitz), wenn ein erwarteter Cron nicht (mehr) läuft. */
function cron_notice_html(): string
{
    $stale = cron_stale();
    if (!$stale) return '';
    return '<div class="flash flash-error"><i class="ti ti-alert-triangle-filled"></i> <strong>Cron-Warnung:</strong> '
        . h(implode(' · ', $stale))
        . ' – ohne Cron gehen keine Erinnerungen raus.</div>';
}

// ---------------------------------------------------------------------------
// Microsoft Graph (SharePoint/OneDrive-Anbindung der Upload-Zentrale)
//
// App-only-Authentifizierung (Client-Credentials-Flow): Der Server holt sich
// selbst ein Token, ein eingeloggter Nutzer ist nicht nötig. Voraussetzung ist
// eine App-Registrierung in Entra ID mit Application-Permission (Sites.Selected
// bzw. Sites.ReadWrite.All) und erteiltem Admin-Consent.
// Zugangsdaten liegen in der settings-Tabelle (DB ist nicht im rsync/Repo).
// ---------------------------------------------------------------------------

/** Sind alle Zugangsdaten hinterlegt? */
function graph_configured(): bool
{
    return trim((string)setting_get('graph_tenant_id', '')) !== ''
        && trim((string)setting_get('graph_client_id', '')) !== ''
        && trim((string)setting_get('graph_client_secret', '')) !== '';
}

/**
 * Roher HTTP-Call. Gibt ['status'=>int, 'body'=>mixed, 'raw'=>string, 'error'=>string] zurück.
 * Bei JSON-Antworten ist 'body' dekodiert, sonst der rohe String.
 */
function graph_http(string $method, string $url, array $headers = [], $body = null): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => null, 'raw' => '', 'error' => 'cURL-Erweiterung fehlt auf dem Server.'];
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => null, 'raw' => '', 'error' => 'Netzwerkfehler: ' . $err];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$raw, true);
    return [
        'status' => $status,
        'body'   => $decoded === null ? $raw : $decoded,
        'raw'    => (string)$raw,
        'error'  => '',
    ];
}

/** Liefert ein gültiges App-only-Access-Token (mit Settings-Cache) oder null. */
function graph_token(?string &$err = null): ?string
{
    $cache = json_decode((string)setting_get('graph_token_cache', ''), true);
    if (is_array($cache) && !empty($cache['token']) && (int)($cache['exp'] ?? 0) > time() + 60) {
        return (string)$cache['token'];
    }
    $tenant = trim((string)setting_get('graph_tenant_id', ''));
    $client = trim((string)setting_get('graph_client_id', ''));
    $secret = (string)setting_get('graph_client_secret', '');
    if ($tenant === '' || $client === '' || $secret === '') {
        $err = 'Zugangsdaten unvollständig.';
        return null;
    }
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
    $r = graph_http('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id'     => $client,
        'client_secret' => $secret,
        'grant_type'    => 'client_credentials',
        'scope'         => 'https://graph.microsoft.com/.default',
    ]));
    if ($r['status'] !== 200 || !is_array($r['body']) || empty($r['body']['access_token'])) {
        $desc = is_array($r['body']) ? ($r['body']['error_description'] ?? $r['body']['error'] ?? '') : '';
        $err = 'Token-Abruf fehlgeschlagen (HTTP ' . $r['status'] . ')' . ($desc ? ': ' . $desc : ($r['error'] ? ': ' . $r['error'] : ''));
        return null;
    }
    $token = (string)$r['body']['access_token'];
    $exp = time() + (int)($r['body']['expires_in'] ?? 3600);
    setting_set('graph_token_cache', json_encode(['token' => $token, 'exp' => $exp]));
    return $token;
}

/** Bequemer Graph-API-Call gegen v1.0. $path beginnt mit '/'. Body-Array wird als JSON gesendet. */
function graph_api(string $method, string $path, $jsonBody = null, ?string &$err = null): ?array
{
    $token = graph_token($err);
    if ($token === null) return null;
    $url = str_starts_with($path, 'http') ? $path : 'https://graph.microsoft.com/v1.0' . $path;
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $body = null;
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($jsonBody);
    }
    $r = graph_http($method, $url, $headers, $body);
    if ($r['status'] >= 200 && $r['status'] < 300) {
        return is_array($r['body']) ? $r['body'] : [];
    }
    $msg = is_array($r['body']) ? ($r['body']['error']['message'] ?? '') : '';
    $err = 'Graph-Fehler (HTTP ' . $r['status'] . ')' . ($msg ? ': ' . $msg : ($r['error'] ? ': ' . $r['error'] : ''));
    return null;
}

/** Löst die konfigurierte SharePoint-Site auf und liefert deren ID (oder null). */
function graph_site_id(?string &$err = null): ?string
{
    $cached = trim((string)setting_get('graph_site_id', ''));
    if ($cached !== '') return $cached;
    $host = trim((string)setting_get('graph_site_hostname', ''));
    $path = trim((string)setting_get('graph_site_path', ''));
    if ($host === '') { $err = 'Kein SharePoint-Host hinterlegt.'; return null; }
    $rel = $path !== '' ? ':' . '/' . ltrim($path, '/') : '';
    $res = graph_api('GET', '/sites/' . rawurlencode($host) . $rel, null, $err);
    if (!$res || empty($res['id'])) return null;
    $id = (string)$res['id'];
    setting_set('graph_site_id', $id);
    return $id;
}

/** Listet die Dokumentbibliotheken (Drives) der Site: [ ['id'=>, 'name'=>], ... ]. */
function graph_site_drives(?string &$err = null): ?array
{
    $siteId = graph_site_id($err);
    if ($siteId === null) return null;
    // Site-ID ist ein zusammengesetzter Bezeichner (mit Kommas) – nicht enkodieren.
    $res = graph_api('GET', '/sites/' . $siteId . '/drives', null, $err);
    if ($res === null) return null;
    $out = [];
    foreach (($res['value'] ?? []) as $d) {
        $out[] = ['id' => (string)($d['id'] ?? ''), 'name' => (string)($d['name'] ?? ''), 'webUrl' => (string)($d['webUrl'] ?? '')];
    }
    return $out;
}

/**
 * Lädt eine Datei in den gewählten Drive hoch. $folder ist ein relativer Ordnerpfad
 * innerhalb der Bibliothek (z. B. 'Protokolle/2026', '' = Wurzel). Nutzt eine
 * Upload-Session (auch für große Dateien geeignet). Gibt das Drive-Item zurück oder null.
 */
function graph_upload_file(string $driveId, string $folder, string $filename, string $localPath, ?string &$err = null): ?array
{
    if ($driveId === '') { $err = 'Keine Dokumentbibliothek gewählt.'; return null; }
    if (!is_file($localPath)) { $err = 'Lokale Datei nicht gefunden.'; return null; }
    $folder = trim(str_replace('\\', '/', $folder), '/');
    $segments = array_map('rawurlencode', array_filter(explode('/', $folder), fn($s) => $s !== ''));
    $segments[] = rawurlencode($filename);
    $itemPath = '/root:/' . implode('/', $segments);

    // Upload-Session anlegen. Drive-ID (beginnt mit "b!") ist bereits URL-tauglich – nicht enkodieren.
    $sessRes = graph_api(
        'POST',
        '/drives/' . $driveId . $itemPath . ':/createUploadSession',
        ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']],
        $err
    );
    if (!$sessRes || empty($sessRes['uploadUrl'])) {
        if (!$err) $err = 'Upload-Session konnte nicht angelegt werden.';
        return null;
    }
    $uploadUrl = (string)$sessRes['uploadUrl'];

    $size = filesize($localPath);
    $data = file_get_contents($localPath);
    if ($data === false) { $err = 'Datei konnte nicht gelesen werden.'; return null; }

    $r = graph_http('PUT', $uploadUrl, [
        'Content-Length: ' . $size,
        'Content-Range: bytes 0-' . max(0, $size - 1) . '/' . $size,
    ], $data);
    if ($r['status'] >= 200 && $r['status'] < 300) {
        return is_array($r['body']) ? $r['body'] : [];
    }
    $msg = is_array($r['body']) ? ($r['body']['error']['message'] ?? '') : '';
    $err = 'Upload fehlgeschlagen (HTTP ' . $r['status'] . ')' . ($msg ? ': ' . $msg : ($r['error'] ? ': ' . $r['error'] : ''));
    return null;
}

/**
 * Lädt den Inhalt eines Drive-Items herunter – optional serverseitig konvertiert
 * (z. B. $format='pdf' für DOCX→PDF, das Microsoft Graph selbst erledigt, ohne dass
 * wir einen lokalen Konverter brauchen). Gibt die rohen Bytes zurück oder null.
 * Graph antwortet mit 302 auf eine vorautorisierte Speicher-URL – der folgen wir
 * manuell OHNE Bearer (der Speicher-Host akzeptiert ihn teils nicht).
 */
function graph_download_file(string $driveId, string $itemId, string $format = '', ?string &$err = null): ?string
{
    if ($driveId === '' || $itemId === '') { $err = 'Kein Teams-Dokument hinterlegt.'; return null; }
    if (!function_exists('curl_init')) { $err = 'cURL-Erweiterung fehlt auf dem Server.'; return null; }
    $token = graph_token($err);
    if ($token === null) return null;
    $url = 'https://graph.microsoft.com/v1.0/drives/' . $driveId . '/items/' . rawurlencode($itemId) . '/content'
         . ($format !== '' ? '?format=' . rawurlencode($format) : '');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // 302 selbst behandeln
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Accept: application/octet-stream']);
    $location = '';
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$location) {
        if (stripos($line, 'location:') === 0 && $location === '') $location = trim(substr($line, strlen('location:')));
        return strlen($line);
    });
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); $err = 'Netzwerkfehler: ' . $e; return null; }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status >= 200 && $status < 300 && $location === '') return (string)$body; // Bytes direkt geliefert
    if (in_array($status, [301, 302, 303, 307], true) && $location !== '') {
        $ch2 = curl_init($location);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 15);
        $data = curl_exec($ch2);
        if ($data === false) { $e = curl_error($ch2); curl_close($ch2); $err = 'Download fehlgeschlagen: ' . $e; return null; }
        $st2 = (int)curl_getinfo($ch2, CURLINFO_RESPONSE_CODE);
        curl_close($ch2);
        if ($st2 >= 200 && $st2 < 300) return (string)$data;
        $err = 'Download fehlgeschlagen (HTTP ' . $st2 . ').';
        return null;
    }
    $decoded = json_decode((string)$body, true);
    $msg = is_array($decoded) ? ($decoded['error']['message'] ?? '') : '';
    $err = 'Graph-Download fehlgeschlagen (HTTP ' . $status . ')' . ($msg ? ': ' . $msg : '');
    return null;
}

/**
 * Listet die direkten Unterordner eines Ordners in der gewählten Bibliothek (für den
 * Ordner-Browser). $folder = '' → Wurzel. Gibt ein Array von Ordnernamen zurück oder null.
 */
function graph_list_folders(string $driveId, string $folder = '', ?string &$err = null): ?array
{
    if ($driveId === '') { $err = 'Keine Dokumentbibliothek gewählt.'; return null; }
    $folder = trim(str_replace('\\', '/', $folder), '/');
    if ($folder === '') {
        $path = '/drives/' . $driveId . '/root/children';
    } else {
        $segs = array_map('rawurlencode', array_filter(explode('/', $folder), fn($s) => $s !== ''));
        $path = '/drives/' . $driveId . '/root:/' . implode('/', $segs) . ':/children';
    }
    $res = graph_api('GET', $path, null, $err);
    if ($res === null) return null;
    $out = [];
    foreach (($res['value'] ?? []) as $it) {
        if (isset($it['folder'])) $out[] = (string)($it['name'] ?? '');
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Verbindungstest: Token holen + Site auflösen + Bibliotheken zählen.
 * Gibt ['ok'=>bool, 'msg'=>string, 'drives'=>array] zurück.
 */
function graph_connection_test(): array
{
    $err = null;
    $token = graph_token($err);
    if ($token === null) return ['ok' => false, 'msg' => $err ?: 'Token-Abruf fehlgeschlagen.', 'drives' => []];
    $drives = graph_site_drives($err);
    if ($drives === null) return ['ok' => false, 'msg' => $err ?: 'Site konnte nicht aufgelöst werden.', 'drives' => []];
    return ['ok' => true, 'msg' => 'Verbindung erfolgreich. ' . count($drives) . ' Dokumentbibliothek(en) gefunden.', 'drives' => $drives];
}

/** Ablaufdatum des Client-Secrets (YYYY-MM-DD) oder null, falls nicht hinterlegt. */
function graph_secret_expiry(): ?string
{
    $v = trim((string)setting_get('graph_secret_expires', ''));
    return $v !== '' ? $v : null;
}

/** Tage bis Ablauf des Secrets; negativ = bereits abgelaufen; null = unbekannt. */
function graph_secret_days_left(): ?int
{
    $exp = graph_secret_expiry();
    if ($exp === null) return null;
    $ts = strtotime($exp . ' 23:59:59');
    if ($ts === false) return null;
    return (int)floor(($ts - time()) / 86400);
}

/**
 * Soll vor dem Ablauf gewarnt werden? Liefert ['days'=>int,'expired'=>bool,'expires'=>string]
 * oder null (nicht konfiguriert, kein Datum, oder noch > 30 Tage hin).
 */
function graph_secret_warning(): ?array
{
    if (!graph_configured()) return null;
    $days = graph_secret_days_left();
    if ($days === null || $days > 30) return null;
    return ['days' => $days, 'expired' => $days < 0, 'expires' => (string)graph_secret_expiry()];
}

/** Deep-Link zur „Zertifikate & Geheimnisse"-Seite der App-Registrierung (oder zur App-Liste). */
function graph_secret_portal_link(): string
{
    $appId = trim((string)setting_get('graph_client_id', ''));
    if ($appId === '') {
        return 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade';
    }
    return 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Credentials/appId/'
        . rawurlencode($appId);
}

/**
 * HTML-Meldung „Client-Secret läuft ab / ist abgelaufen" mit genauer Anleitung
 * und Link zur richtigen Stelle. $base ist der Pfad-Präfix (z. B. '../' im Admin-Ordner).
 * Gibt '' zurück, wenn (noch) keine Warnung nötig ist.
 */
function graph_secret_notice_html(string $base = ''): string
{
    $w = graph_secret_warning();
    if ($w === null) return '';
    $portal = graph_secret_portal_link();
    $expDe = '';
    if ($w['expires'] !== '') {
        $ts = strtotime($w['expires']);
        $expDe = $ts ? date('d.m.Y', $ts) : $w['expires'];
    }
    if ($w['expired']) {
        $head = '<i class="ti ti-alert-octagon"></i> Microsoft-Upload: Client-Secret abgelaufen';
        $lead = 'Das Client-Secret der App-Registrierung ist <strong>seit dem ' . h($expDe)
              . ' abgelaufen</strong>. Uploads in die SharePoint-/OneDrive-Ablage funktionieren nicht mehr, bis ein neues Secret hinterlegt ist.';
        $cls = 'secret-notice expired';
    } else {
        $d = (int)$w['days'];
        $when = $d <= 0 ? 'heute' : ($d === 1 ? 'morgen' : 'in ' . $d . ' Tagen');
        $head = '<i class="ti ti-alert-triangle"></i> Microsoft-Upload: Client-Secret läuft bald ab';
        $lead = 'Das Client-Secret der App-Registrierung läuft <strong>' . h($when)
              . '</strong> ab (' . h($expDe) . '). Erneuere es rechtzeitig, sonst brechen die Uploads ab.';
        $cls = 'secret-notice';
    }
    return '<div class="card ' . $cls . '">'
        . '<h2 style="margin-top:0">' . $head . '</h2>'
        . '<p>' . $lead . '</p>'
        . '<p style="margin-bottom:.3rem"><strong>So erneuerst du das Secret:</strong></p>'
        . '<ol class="secret-steps">'
        . '<li><a href="' . h($portal) . '" target="_blank" rel="noopener">App-Registrierung „AStA-App" → Zertifikate &amp; Geheimnisse</a> öffnen.</li>'
        . '<li><strong>Neuer geheimer Clientschlüssel</strong> → Gültigkeit <strong>24 Monate</strong> → <strong>Hinzufügen</strong>.</li>'
        . '<li>Den angezeigten <strong>Wert</strong> sofort kopieren (wird nur einmal angezeigt!).</li>'
        . '<li>Hier unter ' . app_place('a_uploads', 'Uploads und Automationen → Zugangsdaten', 'config') . ' als neues <strong>Client-Secret</strong> einfügen und das neue <strong>Ablaufdatum</strong> eintragen, dann speichern.</li>'
        . '</ol>'
        . '</div>';
}

// ---------------------------------------------------------------------------
// „In Teams öffnen": Doks einmalig in die Teams-Bibliothek spiegeln und dort
// gemeinsam ansehen/bearbeiten (Office im Web braucht keine eigene Lizenz).
// Sobald eine Datei in Teams liegt, ist der TEAMS-STAND führend: Der App-Download
// holt die aktuelle Fassung von dort (Fallback: lokale Kopie).
// ---------------------------------------------------------------------------
/** Bereiche: kind => [Unterordner in der Ablage, Label]. Gilt für BEIDE Ablagen
 *  (Teams und Nextcloud) – die Ordnerstruktur soll überall dieselbe sein. */
function mirror_kinds(): array
{
    return [
        'info'             => ['Infos', 'Info-Dok'],
        'vfile'            => ['Sitzungs-Anhänge', 'Sitzungs-Anhang'],
        'ufile'            => ['Abstimmungen', 'Abstimmungs-/Umlauf-Anhang'],
        'protokoll'        => ['Protokoll-Entwürfe', 'Protokoll-Entwurf (öffentlich)'],
        'protokoll_intern' => ['Protokoll-Entwürfe', 'Protokoll-Entwurf (intern)'],
    ];
}

/** Darf die aktuelle Rolle diesen Bereich in der Ablage ÖFFNEN/pflegen? (Protokoll: zusätzlich
 *  Protokollant:in, Umlauf-Anhänge: zusätzlich Ersteller:in – das prüfen teams.php/nextcloud.php
 *  und die jeweilige Seite selbst mit ab.) */
function mirror_allowed(string $kind): bool
{
    return match ($kind) {
        'info'                          => can_admin(),
        'vfile'                         => can_manage_meetings(),
        'ufile'                         => can_admin(),
        'protokoll', 'protokoll_intern' => can_manage_meetings(),
        default                         => false,
    };
}

/** Alte Namen – die Bereichsliste und die Rechte gelten für beide Ablagen gleich. */
function teams_open_kinds(): array { return mirror_kinds(); }
function teams_open_allowed(string $kind): bool { return mirror_allowed($kind); }

/** Basisordner der App-Spiegel-Doks in der Bibliothek (Unterordner je Bereich kommt dazu). */
function teams_open_base_folder(): string
{
    return trim(str_replace('\\', '/', (string)setting_get('teams_open_folder', 'AStA-App')), '/');
}

/** Gemerkter Teams-Spiegel einer Datei (oder null). */
function teams_file_row(string $kind, int $refId): ?array
{
    $st = db()->prepare('SELECT * FROM teams_files WHERE kind = ? AND ref_id = ?');
    $st->execute([$kind, $refId]);
    return $st->fetch() ?: null;
}

/** Bytes in die Bibliothek hochladen (über eine Temp-Datei, graph_upload_file kann nur Pfade). */
function graph_upload_bytes(string $driveId, string $folder, string $filename, string $bytes, ?string &$err = null): ?array
{
    $tmp = tempnam(sys_get_temp_dir(), 'asta');
    if ($tmp === false || file_put_contents($tmp, $bytes) === false) { $err = 'Temp-Datei konnte nicht geschrieben werden.'; return null; }
    try { return graph_upload_file($driveId, $folder, $filename, $tmp, $err); }
    finally { @unlink($tmp); }
}

/**
 * Stellt sicher, dass die Datei in Teams liegt (einmaliger Upload, danach nur noch
 * nachgeschlagen – so bleiben Bearbeitungen in Teams erhalten). Quelle je Bereich:
 * info/vfile = gespeicherte Upload-Datei; protokoll(_intern) = frisch erzeugte Vorlage
 * der Sitzung (ref_id = Sitzungs-ID). Rückgabe: teams_files-Zeile oder null (+ $err).
 */
function teams_file_ensure(string $kind, int $refId, int $byMemberId, ?string &$err = null): ?array
{
    $kinds = teams_open_kinds();
    if (!isset($kinds[$kind]) || $refId <= 0) { $err = 'Unbekannter Bereich.'; return null; }
    $row = teams_file_row($kind, $refId);
    if ($row) return $row;
    if (!graph_configured()) { $err = 'Teams-Ablage ist noch nicht eingerichtet (Verwaltung → Uploads und Automationen).'; return null; }
    $driveId = (string)setting_get('graph_drive_id', '');
    if ($driveId === '') { $err = 'Keine Dokumentbibliothek gewählt.'; return null; }

    // Quelle bestimmen: Name + Bytes/Pfad
    $name = ''; $item = null;
    $folder = trim(teams_open_base_folder() . '/' . $kinds[$kind][0], '/');
    if ($kind === 'info' || $kind === 'vfile' || $kind === 'ufile') {
        $st = db()->prepare(match ($kind) {
            'info'  => 'SELECT * FROM info_files WHERE id = ?',
            'vfile' => 'SELECT * FROM vote_item_files WHERE id = ?',
            default => 'SELECT * FROM umlauf_files WHERE id = ?',
        });
        $st->execute([$refId]);
        $f = $st->fetch();
        if (!$f) { $err = 'Datei nicht gefunden.'; return null; }
        $path = upload_dir() . '/' . basename((string)$f['stored_name']);
        if (!is_file($path)) { $err = 'Datei ist nicht mehr vorhanden.'; return null; }
        $name = (string)$f['orig_name'];
        // Namenskollision im Zielordner (gleicher Name, andere Datei) → Kennung anhängen,
        // sonst würde conflictBehavior=replace ein fremdes Dok überschreiben.
        $clash = db()->prepare('SELECT 1 FROM teams_files WHERE kind = ? AND name = ? AND ref_id != ?');
        $clash->execute([$kind, $name, $refId]);
        if ($clash->fetchColumn()) {
            $dot = strrpos($name, '.');
            $name = $dot === false ? $name . ' (' . $refId . ')' : substr($name, 0, $dot) . ' (' . $refId . ')' . substr($name, $dot);
        }
        $item = graph_upload_file($driveId, $folder, $name, $path, $err);
    } else { // protokoll | protokoll_intern: Vorlage der Sitzung frisch erzeugen
        $st = db()->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$refId]);
        $m = $st->fetch();
        if (!$m) { $err = 'Sitzung nicht gefunden.'; return null; }
        if (!class_exists('ZipArchive')) { $err = 'Word-Export nicht möglich (ZipArchive fehlt auf dem Server).'; return null; }
        $intern = $kind === 'protokoll_intern';
        $bytes = $intern ? build_protokoll_intern_docx($m) : build_protokoll_docx($m);
        $name = 'Entwurf_' . protocol_docx_filename($m, $intern);
        $item = graph_upload_bytes($driveId, $folder, $name, $bytes, $err);
    }
    if (!$item || empty($item['id'])) { if (!$err) $err = 'Upload nach Teams fehlgeschlagen.'; return null; }

    db()->prepare('INSERT INTO teams_files(kind, ref_id, drive_id, item_id, web_url, name, created_by)
                   VALUES(?,?,?,?,?,?,?)
                   ON CONFLICT(kind, ref_id) DO UPDATE SET drive_id = excluded.drive_id,
                       item_id = excluded.item_id, web_url = excluded.web_url, name = excluded.name')
        ->execute([$kind, $refId,
                   (string)($item['parentReference']['driveId'] ?? $driveId), (string)$item['id'],
                   (string)($item['webUrl'] ?? ''), $name, $byMemberId ?: null]);
    return teams_file_row($kind, $refId);
}

/** Aktuelle Teams-Fassung einer gespiegelten Datei holen (Bytes oder null → Fallback lokal). */
function teams_file_current(string $kind, int $refId, ?string &$err = null): ?string
{
    $row = teams_file_row($kind, $refId);
    if (!$row || !graph_configured()) return null;
    return graph_download_file((string)$row['drive_id'], (string)$row['item_id'], '', $err);
}

/** Teams-Spiegel vergessen (z. B. wenn die Quelldatei gelöscht wird); löscht NICHT in Teams. */
function teams_file_forget(string $kind, int $refId): void
{
    db()->prepare('DELETE FROM teams_files WHERE kind = ? AND ref_id = ?')->execute([$kind, $refId]);
}

/** Ein Drive-Item in der Bibliothek löschen (landet im SharePoint-Papierkorb). */
function graph_delete_item(string $driveId, string $itemId, ?string &$err = null): bool
{
    if ($driveId === '' || $itemId === '') { $err = 'Kein Teams-Dok angegeben.'; return false; }
    $token = graph_token($err);
    if (!$token) return false;
    $r = graph_http('DELETE', 'https://graph.microsoft.com/v1.0/drives/' . $driveId . '/items/' . rawurlencode($itemId),
        ['Authorization: Bearer ' . $token]);
    if ($r['status'] === 204 || $r['status'] === 404) return true; // 404 = war schon weg
    $err = 'Löschen in Teams fehlgeschlagen (HTTP ' . $r['status'] . ').';
    return false;
}

/** Teams-Spiegel MITSAMT dem Dok in Teams löschen (Papierkorb). Rückgabe: true bei Erfolg. */
function teams_file_delete_remote(string $kind, int $refId, ?string &$err = null): bool
{
    $row = teams_file_row($kind, $refId);
    if (!$row) return true; // nichts gespiegelt → nichts zu tun
    $ok = graph_delete_item((string)$row['drive_id'], (string)$row['item_id'], $err);
    if ($ok) teams_file_forget($kind, $refId);
    return $ok;
}

/** Group-ID (M365-Gruppe) hinter der Bibliothek – Baustein der Teams-Deep-Links; gecached. */
function graph_drive_group_id(string $driveId): string
{
    if ($driveId === '') return '';
    $map = json_decode((string)setting_get('teams_group_of_drive', '{}'), true) ?: [];
    if (isset($map[$driveId])) return (string)$map[$driveId];
    $err = null;
    $res = graph_api('GET', '/drives/' . $driveId . '?$select=owner', null, $err);
    $gid = (string)($res['owner']['group']['id'] ?? '');
    if ($gid !== '') { $map[$driveId] = $gid; setting_set('teams_group_of_drive', json_encode($map)); }
    return $gid;
}

/**
 * Deep-Link, der die Datei direkt in der TEAMS-APP öffnet (statt der SharePoint-Seite
 * im Browser). Braucht SharePoint-IDs + Tenant + Gruppe; fehlt etwas, kommt '' zurück
 * und der Aufrufer fällt auf die webUrl (Browser) zurück.
 */
function teams_deep_link(array $item, string $driveId): string
{
    $sp = (array)($item['sharepointIds'] ?? []);
    $uid = trim((string)($sp['listItemUniqueId'] ?? ''), '{}');
    $siteUrl = (string)($sp['siteUrl'] ?? '');
    $objectUrl = (string)($item['webDavUrl'] ?? '');
    $tenant = trim((string)setting_get('graph_tenant_id', ''));
    $ext = strtolower(pathinfo((string)($item['name'] ?? ''), PATHINFO_EXTENSION));
    if ($uid === '' || $siteUrl === '' || $objectUrl === '' || $tenant === '' || $ext === '') return '';
    $url = 'https://teams.microsoft.com/l/file/' . rawurlencode($uid)
        . '?tenantId=' . rawurlencode($tenant)
        . '&fileType=' . rawurlencode($ext)
        . '&objectUrl=' . rawurlencode($objectUrl)
        . '&baseUrl=' . rawurlencode($siteUrl)
        . '&serviceName=teams';
    $gid = graph_drive_group_id($driveId);
    if ($gid !== '') $url .= '&groupId=' . rawurlencode($gid);
    return $url;
}

/** Beste Öffnen-URL eines Spiegels: Teams-Deep-Link (App), sonst webUrl (Browser).
 *  Fehlt der Deep-Link noch (ältere Einträge), wird er einmalig per Graph nachgebaut. */
function teams_file_open_url(array $row): string
{
    $t = trim((string)($row['teams_url'] ?? ''));
    if ($t !== '') return $t;
    $err = null;
    $item = graph_api('GET', '/drives/' . $row['drive_id'] . '/items/' . rawurlencode((string)$row['item_id'])
        . '?$select=id,name,webUrl,webDavUrl,sharepointIds', null, $err);
    if ($item) {
        $t = teams_deep_link($item, (string)$row['drive_id']);
        if ($t !== '') db()->prepare('UPDATE teams_files SET teams_url = ? WHERE id = ?')->execute([$t, (int)$row['id']]);
    }
    return $t !== '' ? $t : (string)$row['web_url'];
}

/** Kleiner „In Teams öffnen"-Knopf (oder '' wenn nicht berechtigt/nicht eingerichtet).
 *  $allowed überschreibt die Rollen-Prüfung (z. B. Ersteller:in bei Umlauf-Anhängen). */
function teams_open_button(string $kind, int $refId, string $base = '', ?bool $allowed = null): string
{
    if (!graph_configured() || !($allowed ?? teams_open_allowed($kind))) return '';
    $has = teams_file_row($kind, $refId) !== null;
    return '<a class="btn secondary small" href="' . $base . 'teams.php?kind=' . rawurlencode($kind) . '&amp;id=' . $refId . '" target="_blank" rel="noopener" title="' . ($has ? 'In Teams öffnen (liegt schon dort – Teams-Stand ist führend)' : 'Einmalig nach Teams kopieren und dort öffnen') . '"><i class="ti ti-brand-teams"></i> In Teams öffnen</a>';
}

// ---------------------------------------------------------------------------
// OLAT (OpenOLAT) – Datei-Upload per WebDAV
//
// OpenOLAT stellt Ordner (persönlicher Ordner, Kurs-/Gruppenordner) per WebDAV
// bereit. Der Server lädt Dateien mit Basic-Auth über HTTPS hoch (PUT), legt
// fehlende Ordner per MKCOL an. Hinweis: OpenOLAT verlangt meist ein separates
// WebDAV-Passwort (in OLAT unter Einstellungen → WebDAV zu setzen).
// Zugangsdaten liegen in der settings-Tabelle (DB ist nicht im rsync/Repo).
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// WebDAV-Kern (gemeinsam für OLAT und Nextcloud)
//
// Beide Ablagen sprechen dasselbe Protokoll, deshalb liegt die Mechanik EINMAL
// hier: Anfrage mit Zugangsdaten, URL-Bau, Ordner auflisten (PROPFIND), Ordner
// anlegen (MKCOL), hoch- und herunterladen (PUT/GET), löschen, kopieren.
// Unterschiede stecken allein im Zugangsprofil dav_profile(): Wurzel-URL,
// Benutzer, Passwort und Auth-Verfahren. Die olat_*-Funktionen darunter sind nur
// noch Hüllen – so bleiben alle bestehenden Aufrufstellen unverändert gültig.
// Zugangsdaten liegen in der settings-Tabelle (DB ist nicht im rsync/Repo).
// ---------------------------------------------------------------------------

/** Die bekannten WebDAV-Ablagen: store => Anzeigename. */
function dav_stores(): array
{
    return ['olat' => 'OLAT', 'nextcloud' => 'Nextcloud'];
}

/**
 * Zugangsprofil einer Ablage. 'root' ist die WebDAV-Wurzel OHNE Schluss-Slash.
 * Bei Nextcloud wird sie aus Adresse + Benutzer gebaut – niemand soll
 * „/remote.php/dav/files/…" von Hand tippen müssen.
 */
function dav_profile(string $store): array
{
    if ($store === 'nextcloud') {
        $base = rtrim(trim((string)setting_get('nc_base_url', '')), '/');
        $user = trim((string)setting_get('nc_user', ''));
        return [
            'label' => 'Nextcloud',
            'root'  => ($base === '' || $user === '') ? '' : $base . '/remote.php/dav/files/' . rawurlencode($user),
            'user'  => $user,
            'pass'  => (string)setting_get('nc_password', ''),
            // Nextcloud kann immer Basic (mit App-Passwort) – direkt senden spart eine Vorrunde.
            'auth'  => CURLAUTH_BASIC,
        ];
    }
    return [
        'label' => 'OLAT',
        'root'  => rtrim(trim((string)setting_get('olat_webdav_url', '')), '/'),
        'user'  => trim((string)setting_get('olat_user', '')),
        'pass'  => (string)setting_get('olat_password', ''),
        // CURLAUTH_ANY: cURL handelt Basic/Digest selbst aus (OpenOLAT nutzt teils Digest).
        'auth'  => CURLAUTH_ANY,
    ];
}

/** Sind die Zugangsdaten dieser Ablage vollständig hinterlegt? */
function dav_configured(string $store): bool
{
    $p = dav_profile($store);
    return $p['root'] !== '' && $p['user'] !== '' && trim((string)$p['pass']) !== '';
}

/**
 * Roher WebDAV-Call. Gibt ['status'=>int,'raw'=>string,'error'=>string,'auth'=>string] zurück.
 */
function dav_request(string $store, string $method, string $url, array $headers = [], $body = null): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'raw' => '', 'error' => 'cURL-Erweiterung fehlt auf dem Server.', 'auth' => ''];
    }
    $p = dav_profile($store);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPAUTH, $p['auth']);
    curl_setopt($ch, CURLOPT_USERPWD, $p['user'] . ':' . $p['pass']);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $authChallenge = '';
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$authChallenge) {
        if (stripos($line, 'www-authenticate:') === 0 && $authChallenge === '') {
            $authChallenge = trim(substr($line, strlen('www-authenticate:')));
        }
        return strlen($line);
    });
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'raw' => '', 'error' => 'Netzwerkfehler: ' . $err, 'auth' => ''];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'raw' => (string)$raw, 'error' => '', 'auth' => $authChallenge];
}

/** Vollständige WebDAV-URL aus Wurzel + relativem Pfad (Segmente einzeln enkodiert). */
function dav_url_for(string $store, string $relPath): string
{
    $root = dav_profile($store)['root'];
    $relPath = trim(str_replace('\\', '/', $relPath), '/');
    if ($relPath === '') return $root;
    $segs = array_map('rawurlencode', array_filter(explode('/', $relPath), fn($s) => $s !== ''));
    return $root . '/' . implode('/', $segs);
}

/** Einheitliche Fehlermeldung aus einer WebDAV-Antwort (spricht Deutsch, nicht HTTP). */
function dav_error_text(array $r, string $what = ''): string
{
    if ($r['status'] === 0)   return $r['error'] ?: 'Server nicht erreichbar.';
    if ($r['status'] === 401) return 'Anmeldung fehlgeschlagen (HTTP 401) – Benutzername oder Passwort prüfen.';
    if ($r['status'] === 403) return 'Zugriff verweigert (HTTP 403) – Rechte des Kontos prüfen.';
    if ($r['status'] === 404) return ($what !== '' ? $what : 'Ziel') . ' nicht gefunden (HTTP 404).';
    if ($r['status'] === 507) return 'Kein Speicherplatz mehr in der Ablage (HTTP 507).';
    return 'Unerwartete Antwort (HTTP ' . $r['status'] . ').';
}

/** Verbindungstest: PROPFIND auf den Basisordner der Ablage. */
function dav_test_connection(string $store, string $relFolder = ''): array
{
    if (!dav_configured($store)) return ['ok' => false, 'msg' => 'Zugangsdaten unvollständig.'];
    $r = dav_request($store, 'PROPFIND', dav_url_for($store, trim($relFolder, '/')),
        ['Depth: 0', 'Content-Type: application/xml'],
        '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>');
    if ($r['status'] === 207 || $r['status'] === 200) {
        return ['ok' => true, 'msg' => 'Verbindung erfolgreich – Ordner erreichbar.'];
    }
    $msg = dav_error_text($r, 'Ordner');
    if ($r['status'] === 401 && !empty($r['auth'])) {
        $word = strtok($r['auth'], ' ');
        if ($word) $msg .= ' [Server verlangt: ' . $word . ']';
    }
    if ($r['status'] === 404) $msg .= ' Basisordner-Pfad prüfen.';
    return ['ok' => false, 'msg' => $msg];
}

/**
 * Listet die direkten Unterordner/Dateien eines Ordners (PROPFIND Depth:1).
 * Gibt [ ['name'=>string,'dir'=>bool,'fileid'=>string], ... ] zurück (ohne den Ordner
 * selbst) oder null. 'fileid' liefert nur Nextcloud (oc:fileid), sonst ''.
 */
function dav_list_folder(string $store, string $relPath = '', ?string &$err = null): ?array
{
    if (!dav_configured($store)) { $err = dav_profile($store)['label'] . ' ist nicht eingerichtet.'; return null; }
    $relPath = trim(str_replace('\\', '/', $relPath), '/');
    $url = dav_url_for($store, $relPath);
    $r = dav_request($store, 'PROPFIND', $url, ['Depth: 1', 'Content-Type: application/xml'],
        '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
        . '<d:prop><d:resourcetype/><d:displayname/><oc:fileid/><d:getcontentlength/></d:prop></d:propfind>');
    if ($r['status'] !== 207 && $r['status'] !== 200) { $err = dav_error_text($r, 'Ordner'); return null; }
    $xml = @simplexml_load_string($r['raw']);
    if ($xml === false) { $err = 'Antwort konnte nicht gelesen werden.'; return null; }
    $xml->registerXPathNamespace('d', 'DAV:');
    $basePath = rtrim((string)(parse_url($url, PHP_URL_PATH) ?? ''), '/');
    $out = [];
    foreach (($xml->xpath('//d:response') ?: []) as $resp) {
        $resp->registerXPathNamespace('d', 'DAV:');
        $resp->registerXPathNamespace('oc', 'http://owncloud.org/ns');
        $hrefNodes = $resp->xpath('d:href');
        $href = $hrefNodes ? (string)$hrefNodes[0] : '';
        $path = rtrim((string)(parse_url($href, PHP_URL_PATH) ?? $href), '/');
        if ($path === '' || $path === $basePath) continue; // der Ordner selbst
        $name = rawurldecode(basename($path));
        if ($name === '') continue;
        $isDir = !empty($resp->xpath('d:propstat/d:prop/d:resourcetype/d:collection'));
        $idNodes = $resp->xpath('d:propstat/d:prop/oc:fileid');
        $lenNodes = $resp->xpath('d:propstat/d:prop/d:getcontentlength');
        $out[] = ['name' => $name, 'dir' => $isDir, 'fileid' => $idNodes ? trim((string)$idNodes[0]) : '',
                  'size' => $lenNodes ? (int)trim((string)$lenNodes[0]) : 0];
    }
    usort($out, fn($a, $b) => ($b['dir'] <=> $a['dir']) ?: strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Legt einen Ordnerpfad (relativ zur Wurzel) per MKCOL an – Segment für Segment. */
function dav_ensure_folder(string $store, string $relFolder, ?string &$err = null): bool
{
    $relFolder = trim(str_replace('\\', '/', $relFolder), '/');
    if ($relFolder === '') return true;
    $parts = array_filter(explode('/', $relFolder), fn($s) => $s !== '');
    $cum = '';
    foreach ($parts as $p) {
        $cum = $cum === '' ? $p : $cum . '/' . $p;
        $r = dav_request($store, 'MKCOL', dav_url_for($store, $cum));
        // 201 = neu angelegt, 405/301 = existiert bereits → beides ok
        if (!in_array($r['status'], [201, 405, 301], true)) {
            $err = $r['status'] === 0 || $r['status'] === 401
                ? dav_error_text($r)
                : 'Ordner „' . $cum . '" konnte nicht angelegt werden (HTTP ' . $r['status'] . ').';
            return false;
        }
    }
    return true;
}

/** Bytes an einen relativen Pfad schreiben (Ordner werden bei Bedarf angelegt). */
function dav_put(string $store, string $relTarget, string $bytes, ?string &$err = null): bool
{
    if (!dav_configured($store)) { $err = dav_profile($store)['label'] . ' ist nicht eingerichtet.'; return false; }
    $relTarget = trim(str_replace('\\', '/', $relTarget), '/');
    if ($relTarget === '') { $err = 'Kein Zielpfad angegeben.'; return false; }
    $folder = trim((string)dirname($relTarget), '/.');
    if ($folder !== '' && !dav_ensure_folder($store, $folder, $err)) return false;
    $r = dav_request($store, 'PUT', dav_url_for($store, $relTarget),
        ['Content-Type: application/octet-stream', 'Content-Length: ' . strlen($bytes)], $bytes);
    if ($r['status'] >= 200 && $r['status'] < 300) return true;
    $err = dav_error_text($r);
    return false;
}

/** Datei herunterladen (Bytes) oder null (+ $err). */
function dav_get(string $store, string $relPath, ?string &$err = null): ?string
{
    if (!dav_configured($store)) { $err = dav_profile($store)['label'] . ' ist nicht eingerichtet.'; return null; }
    $r = dav_request($store, 'GET', dav_url_for($store, $relPath));
    if ($r['status'] >= 200 && $r['status'] < 300) return $r['raw'];
    $err = dav_error_text($r, 'Datei');
    return null;
}

/** Datei oder Ordner löschen. 404 gilt als Erfolg (war schon weg). */
function dav_delete(string $store, string $relPath, ?string &$err = null): bool
{
    if (!dav_configured($store)) { $err = dav_profile($store)['label'] . ' ist nicht eingerichtet.'; return false; }
    $r = dav_request($store, 'DELETE', dav_url_for($store, $relPath));
    if (($r['status'] >= 200 && $r['status'] < 300) || $r['status'] === 404) return true;
    $err = dav_error_text($r, 'Datei');
    return false;
}

/** Serverseitig kopieren (ohne Runter-/Hochladen). Zielordner wird angelegt. */
function dav_copy(string $store, string $relFrom, string $relTo, ?string &$err = null): bool
{
    if (!dav_configured($store)) { $err = dav_profile($store)['label'] . ' ist nicht eingerichtet.'; return false; }
    $folder = trim((string)dirname(trim(str_replace('\\', '/', $relTo), '/')), '/.');
    if ($folder !== '' && !dav_ensure_folder($store, $folder, $err)) return false;
    $r = dav_request($store, 'COPY', dav_url_for($store, $relFrom),
        ['Destination: ' . dav_url_for($store, $relTo), 'Overwrite: T']);
    if ($r['status'] >= 200 && $r['status'] < 300) return true;
    $err = dav_error_text($r, 'Datei');
    return false;
}

/** Nextclouds dauerhafte Datei-Kennung (oc:fileid) eines Pfads – Basis der /f/…-Links. */
function dav_fileid(string $store, string $relPath, ?string &$err = null): string
{
    $r = dav_request($store, 'PROPFIND', dav_url_for($store, $relPath),
        ['Depth: 0', 'Content-Type: application/xml'],
        '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>');
    if ($r['status'] !== 207 && $r['status'] !== 200) { $err = dav_error_text($r, 'Datei'); return ''; }
    $xml = @simplexml_load_string($r['raw']);
    if ($xml === false) { $err = 'Antwort konnte nicht gelesen werden.'; return ''; }
    $xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');
    $nodes = $xml->xpath('//oc:fileid');
    return $nodes ? trim((string)$nodes[0]) : '';
}

// ---- OLAT: Hüllen um den gemeinsamen Kern (Namen bleiben, Aufrufstellen unverändert) ----

/** Sind die OLAT-WebDAV-Zugangsdaten hinterlegt? */
function olat_configured(): bool
{
    return dav_configured('olat');
}

/** WebDAV-Wurzel-URL ohne abschließenden Slash. */
function olat_base_url(): string
{
    return dav_profile('olat')['root'];
}

/** Roher WebDAV-Call gegen OLAT. */
function olat_dav_request(string $method, string $url, array $headers = [], $body = null): array
{
    return dav_request('olat', $method, $url, $headers, $body);
}

/** Baut die vollständige WebDAV-URL aus Basis + relativem Pfad. */
function olat_url_for(string $relPath): string
{
    return dav_url_for('olat', $relPath);
}

/** Verbindungstest: PROPFIND auf den OLAT-Basisordner. */
function olat_test_connection(): array
{
    return dav_test_connection('olat', trim((string)setting_get('olat_base_folder', ''), '/'));
}

/** Listet die direkten Unterordner/Dateien eines OLAT-Ordners. */
function olat_list_folder(string $relPath = '', ?string &$err = null): ?array
{
    return dav_list_folder('olat', $relPath, $err);
}

/** Legt einen Ordnerpfad in OLAT an. */
function olat_ensure_folder(string $relFolder, ?string &$err = null): bool
{
    return dav_ensure_folder('olat', $relFolder, $err);
}

/**
 * Lädt eine Datei in einen OLAT-Ordner. $folder ist relativ zur WebDAV-Wurzel
 * (inkl. evtl. Basisordner). Gibt true bei Erfolg, sonst false (+ $err).
 */
function olat_upload_file(string $folder, string $filename, string $localPath, ?string &$err = null): bool
{
    if (!is_file($localPath)) { $err = 'Lokale Datei nicht gefunden.'; return false; }
    $data = file_get_contents($localPath);
    if ($data === false) { $err = 'Datei konnte nicht gelesen werden.'; return false; }
    $folder = trim(str_replace('\\', '/', $folder), '/');
    return dav_put('olat', ($folder === '' ? '' : $folder . '/') . $filename, $data, $err);
}

// ---------------------------------------------------------------------------
// Nextcloud – „In Nextcloud öffnen" (Gegenstück zur Teams-Ablage)
//
// Eingerichtet wird mit Adresse, Benutzername und APP-PASSWORT (in Nextcloud unter
// Einstellungen → Sicherheit → „Neues App-Passwort erstellen"). Bewusst nicht das
// echte Kennwort: ein App-Passwort lässt sich einzeln widerrufen, funktioniert auch
// bei aktiver Zwei-Faktor-Anmeldung und hängt an keinem Browser-Login.
// Links zeigen auf /f/<fileid>: NEXTCLOUD entscheidet dann selbst, ob die Datei in
// Collabora/OnlyOffice aufgeht oder im Dateimanager landet – die App muss dafür
// nichts wissen. Die Fähigkeiten fragen wir nur ab, um Knöpfe richtig zu benennen.
// ---------------------------------------------------------------------------

/** Sind die Nextcloud-Zugangsdaten vollständig hinterlegt? */
function nc_configured(): bool
{
    return dav_configured('nextcloud');
}

/** Adresse der Nextcloud ohne Schluss-Slash (z. B. https://cloud.beispiel.de). */
function nc_base_url(): string
{
    return rtrim(trim((string)setting_get('nc_base_url', '')), '/');
}

/**
 * Nimmt die Adresse so an, wie Leute sie einfügen, und macht die Wurzel daraus:
 * ohne Schema, mit Schluss-Slash oder gleich mit kopiertem Pfad („…/index.php/apps/files/…",
 * „…/remote.php/dav/…"). Der WebDAV-Pfad kommt von der App, nicht von der Eingabe.
 */
function nc_normalize_base_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
    // Alles ab dem ersten Nextcloud-Einstiegspunkt abschneiden
    $url = (string)preg_replace('~/(remote\.php|index\.php|apps|ocs|s|f)(/.*)?$~i', '', $url);
    return rtrim($url, '/');
}

/** Basisordner der App-Doks in der Nextcloud (Unterordner je Bereich kommt dazu). */
function nc_base_folder(): string
{
    return trim(str_replace('\\', '/', (string)setting_get('nc_open_folder', 'AStA-App')), '/');
}

/** Zielordner eines Bereichs, relativ zur WebDAV-Wurzel des Kontos. */
function nc_kind_folder(string $kind): string
{
    $kinds = mirror_kinds();
    if (!isset($kinds[$kind])) return nc_base_folder();
    return trim(nc_base_folder() . '/' . $kinds[$kind][0], '/');
}

/**
 * Verbindungstest. Prüft zuerst das Konto (Wurzel), dann den Basisordner – ein noch
 * fehlender Ordner ist KEIN Fehler, den legt die App beim ersten Dok selbst an.
 */
function nc_test_connection(): array
{
    $r = dav_test_connection('nextcloud', '');
    if (!$r['ok']) return $r;
    $folder = nc_base_folder();
    if ($folder === '') return ['ok' => true, 'msg' => 'Verbindung erfolgreich – Konto erreichbar.'];
    $err = null;
    if (dav_list_folder('nextcloud', $folder, $err) === null) {
        return ['ok' => true, 'msg' => 'Verbindung erfolgreich. Den Ordner „' . $folder
            . '" gibt es noch nicht – die App legt ihn beim ersten Dok selbst an.'];
    }
    return ['ok' => true, 'msg' => 'Verbindung erfolgreich – Ordner „' . $folder . '" erreichbar.'];
}

/**
 * Fähigkeiten der Nextcloud (OCS-Schnittstelle), gecached in den Settings.
 * $fresh erzwingt eine neue Abfrage (Knopf in der Verwaltung).
 */
function nc_capabilities(bool $fresh = false, ?string &$err = null): array
{
    $cache = json_decode((string)setting_get('nc_caps_cache', ''), true);
    if (!$fresh && is_array($cache) && !empty($cache['at']) && strtotime((string)$cache['at']) > time() - 7 * 86400) {
        return is_array($cache['data'] ?? null) ? $cache['data'] : [];
    }
    if (!nc_configured()) { $err = 'Nextcloud ist nicht eingerichtet.'; return []; }
    $r = dav_request('nextcloud', 'GET', nc_base_url() . '/ocs/v2.php/cloud/capabilities?format=json',
        ['OCS-APIRequest: true', 'Accept: application/json']);
    if ($r['status'] < 200 || $r['status'] >= 300) { $err = dav_error_text($r); return []; }
    $data = json_decode($r['raw'], true);
    $caps = is_array($data['ocs']['data']['capabilities'] ?? null) ? $data['ocs']['data']['capabilities'] : [];
    if (!$caps) { $err = 'Antwort der Nextcloud war nicht lesbar.'; return []; }
    setting_set('nc_caps_cache', json_encode(['at' => date('Y-m-d H:i:s'), 'data' => $caps]));
    return $caps;
}

/**
 * Erkannter Office-Server: 'collabora' | 'onlyoffice' | ''. Beeinflusst NUR die
 * Beschriftung der Knöpfe – geöffnet wird die Datei in jedem Fall.
 */
function nc_editor(): string
{
    $caps = nc_capabilities();
    if (isset($caps['richdocuments'])) return 'collabora';
    if (isset($caps['onlyoffice']))    return 'onlyoffice';
    return '';
}

/** Anzeigename des erkannten Office-Servers ('' wenn keiner gefunden wurde). */
function nc_editor_label(): string
{
    return match (nc_editor()) {
        'collabora'  => 'Collabora Online',
        'onlyoffice' => 'OnlyOffice',
        default      => '',
    };
}

/** Gemerkter Nextcloud-Spiegel einer Datei (oder null). */
function nc_file_row(string $kind, int $refId): ?array
{
    $st = db()->prepare('SELECT * FROM nc_files WHERE kind = ? AND ref_id = ?');
    $st->execute([$kind, $refId]);
    return $st->fetch() ?: null;
}

/**
 * Stellt sicher, dass die Datei in der Nextcloud liegt (einmaliger Upload, danach nur
 * noch nachgeschlagen – so bleiben Bearbeitungen dort erhalten). Quelle je Bereich:
 * info/vfile/ufile = gespeicherte Upload-Datei; protokoll(_intern) = frisch erzeugte
 * Vorlage der Sitzung (ref_id = Sitzungs-ID). Rückgabe: nc_files-Zeile oder null (+ $err).
 */
function nc_file_ensure(string $kind, int $refId, int $byMemberId, ?string &$err = null): ?array
{
    $kinds = mirror_kinds();
    if (!isset($kinds[$kind]) || $refId <= 0) { $err = 'Unbekannter Bereich.'; return null; }
    $row = nc_file_row($kind, $refId);
    if ($row) return $row;
    if (!nc_configured()) { $err = 'Nextcloud ist noch nicht eingerichtet (Verwaltung → Uploads und Automationen).'; return null; }

    $folder = nc_kind_folder($kind);
    $name = ''; $bytes = null;
    if ($kind === 'info' || $kind === 'vfile' || $kind === 'ufile') {
        $st = db()->prepare(match ($kind) {
            'info'  => 'SELECT * FROM info_files WHERE id = ?',
            'vfile' => 'SELECT * FROM vote_item_files WHERE id = ?',
            default => 'SELECT * FROM umlauf_files WHERE id = ?',
        });
        $st->execute([$refId]);
        $f = $st->fetch();
        if (!$f) { $err = 'Datei nicht gefunden.'; return null; }
        $path = upload_dir() . '/' . basename((string)$f['stored_name']);
        if (!is_file($path)) { $err = 'Datei ist nicht mehr vorhanden.'; return null; }
        $name = (string)$f['orig_name'];
        // Namenskollision im Zielordner (gleicher Name, andere Datei) → Kennung anhängen,
        // sonst würde der PUT ein fremdes Dok überschreiben.
        $clash = db()->prepare('SELECT 1 FROM nc_files WHERE kind = ? AND name = ? AND ref_id != ?');
        $clash->execute([$kind, $name, $refId]);
        if ($clash->fetchColumn()) {
            $dot = strrpos($name, '.');
            $name = $dot === false ? $name . ' (' . $refId . ')' : substr($name, 0, $dot) . ' (' . $refId . ')' . substr($name, $dot);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) { $err = 'Datei konnte nicht gelesen werden.'; return null; }
    } else { // protokoll | protokoll_intern: Vorlage der Sitzung frisch erzeugen
        $st = db()->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$refId]);
        $m = $st->fetch();
        if (!$m) { $err = 'Sitzung nicht gefunden.'; return null; }
        if (!class_exists('ZipArchive')) { $err = 'Word-Export nicht möglich (ZipArchive fehlt auf dem Server).'; return null; }
        $intern = $kind === 'protokoll_intern';
        $bytes = $intern ? build_protokoll_intern_docx($m) : build_protokoll_docx($m);
        $name = 'Entwurf_' . protocol_docx_filename($m, $intern);
    }

    $relPath = trim($folder . '/' . $name, '/');
    if (!dav_put('nextcloud', $relPath, (string)$bytes, $err)) {
        if (!$err) $err = 'Upload in die Nextcloud fehlgeschlagen.';
        return null;
    }
    $idErr = null;
    $fileId = dav_fileid('nextcloud', $relPath, $idErr); // fehlt sie, holt nc_file_open_url sie später nach

    db()->prepare('INSERT INTO nc_files(kind, ref_id, path, file_id, name, created_by)
                   VALUES(?,?,?,?,?,?)
                   ON CONFLICT(kind, ref_id) DO UPDATE SET path = excluded.path,
                       file_id = excluded.file_id, name = excluded.name')
        ->execute([$kind, $refId, $relPath, $fileId, $name, $byMemberId ?: null]);
    return nc_file_row($kind, $refId);
}

/** Aktuelle Nextcloud-Fassung einer gespiegelten Datei holen (Bytes oder null → Fallback lokal). */
function nc_file_current(string $kind, int $refId, ?string &$err = null): ?string
{
    $row = nc_file_row($kind, $refId);
    if (!$row || !nc_configured()) return null;
    return dav_get('nextcloud', (string)$row['path'], $err);
}

/** Nextcloud-Spiegel vergessen (z. B. wenn die Quelldatei gelöscht wird); löscht NICHT dort. */
function nc_file_forget(string $kind, int $refId): void
{
    db()->prepare('DELETE FROM nc_files WHERE kind = ? AND ref_id = ?')->execute([$kind, $refId]);
}

/** Spiegel MITSAMT dem Dok in der Nextcloud löschen (landet im dortigen Papierkorb). */
function nc_file_delete_remote(string $kind, int $refId, ?string &$err = null): bool
{
    $row = nc_file_row($kind, $refId);
    if (!$row) return true; // nichts gespiegelt → nichts zu tun
    $ok = dav_delete('nextcloud', (string)$row['path'], $err);
    if ($ok) nc_file_forget($kind, $refId);
    return $ok;
}

/**
 * Öffnen-URL eines Spiegels. Bevorzugt /f/<fileid>: dieser Link überlebt Umbenennen
 * und Verschieben in der Nextcloud. Fehlt die Kennung noch, wird sie einmalig
 * nachgeholt; erst als letzte Rückfalloption zeigt der Link auf den Ordner.
 */
function nc_file_open_url(array $row): string
{
    $fid = trim((string)($row['file_id'] ?? ''));
    if ($fid === '' && nc_configured()) {
        $err = null;
        $fid = dav_fileid('nextcloud', (string)$row['path'], $err);
        if ($fid !== '') db()->prepare('UPDATE nc_files SET file_id = ? WHERE id = ?')->execute([$fid, (int)$row['id']]);
    }
    if ($fid !== '') return nc_base_url() . '/index.php/f/' . rawurlencode($fid);
    $dir = trim((string)dirname('/' . trim((string)$row['path'], '/')), '/');
    return nc_base_url() . '/index.php/apps/files/?dir=/' . implode('/', array_map('rawurlencode', array_filter(explode('/', $dir))))
        . '&scrollto=' . rawurlencode(basename((string)$row['path']));
}

// ---------------------------------------------------------------------------
// Ablage-Weiche: Teams ODER Nextcloud – niemals beides für dieselbe Datei
//
// Zwei bearbeitbare Spiegel derselben Datei hieße: zwei Leute schreiben in
// verschiedene Kopien und der Download müsste raten, welche gilt. Deshalb gilt:
//   * Wo eine Datei schon liegt, bleibt sie – mirror_store() entscheidet je Datei.
//   * Nur NEUE Spiegel gehen in die eingestellte Ablage (mirror_backend()).
// So ist ein Umzug möglich, ohne dass der Altbestand seine Knöpfe verliert.
// ---------------------------------------------------------------------------

/** Ablage für NEUE Spiegel: 'teams' | 'nextcloud'. Standard richtet sich danach,
 *  was überhaupt eingerichtet ist (bestehende Installationen bleiben bei Teams). */
function mirror_backend(): string
{
    $want = (string)setting_get('mirror_backend', '');
    if ($want === 'nextcloud' && nc_configured()) return 'nextcloud';
    if ($want === 'teams' && graph_configured()) return 'teams';
    if (graph_configured()) return 'teams';
    return nc_configured() ? 'nextcloud' : 'teams';
}

/** In WELCHER Ablage liegt diese Datei schon? 'teams' | 'nextcloud' | '' (nirgends). */
function mirror_store(string $kind, int $refId): string
{
    if (teams_file_row($kind, $refId)) return 'teams';
    if (nc_file_row($kind, $refId))    return 'nextcloud';
    return '';
}

/** Ist überhaupt eine Ablage eingerichtet? */
function mirror_configured(): bool
{
    return graph_configured() || nc_configured();
}

/** Anzeigename einer Ablage. */
function mirror_label(string $store): string
{
    return $store === 'nextcloud' ? 'Nextcloud' : 'Teams';
}

/** Spiegel anlegen bzw. nachschlagen – in der Ablage, in der die Datei schon liegt,
 *  sonst in der eingestellten. Rückgabe: ['store'=>…, 'row'=>…] oder null (+ $err). */
function mirror_ensure(string $kind, int $refId, int $byMemberId, ?string &$err = null): ?array
{
    $store = mirror_store($kind, $refId) ?: mirror_backend();
    $row = $store === 'nextcloud'
        ? nc_file_ensure($kind, $refId, $byMemberId, $err)
        : teams_file_ensure($kind, $refId, $byMemberId, $err);
    return $row ? ['store' => $store, 'row' => $row] : null;
}

/** Aktueller Stand aus der Ablage, in der die Datei liegt (oder null → lokale Kopie). */
function mirror_current(string $kind, int $refId, ?string &$err = null): ?string
{
    return match (mirror_store($kind, $refId)) {
        'teams'     => teams_file_current($kind, $refId, $err),
        'nextcloud' => nc_file_current($kind, $refId, $err),
        default     => null,
    };
}

/** Verweise auf beide Ablagen vergessen (Quelldatei ist weg); löscht dort nichts. */
function mirror_forget(string $kind, int $refId): void
{
    teams_file_forget($kind, $refId);
    nc_file_forget($kind, $refId);
}

/** Dok in der Ablage löschen, in der es liegt (Papierkorb dort). */
function mirror_delete_remote(string $kind, int $refId, ?string &$err = null): bool
{
    // Nur ausgewählt, nicht von der App angelegt: Das Original bleibt, wo es ist. Der Anhang
    // verschwindet trotzdem – nur eben ohne fremdes Dok im Papierkorb der Ablage.
    if (mirror_linked($kind, $refId)) return true;
    return match (mirror_store($kind, $refId)) {
        'teams'     => teams_file_delete_remote($kind, $refId, $err),
        'nextcloud' => nc_file_delete_remote($kind, $refId, $err),
        default     => true,
    };
}

/** Öffnen-URL des vorhandenen Spiegels ('' wenn keiner da ist). */
function mirror_open_url(string $kind, int $refId): string
{
    if ($row = teams_file_row($kind, $refId)) return teams_file_open_url($row);
    if ($row = nc_file_row($kind, $refId))    return nc_file_open_url($row);
    return '';
}

/**
 * Attribute fürs Häkchen „Dok in der Ablage mitlöschen" im Lösch-Dialog.
 * Leer, wenn die Datei nirgends gespiegelt ist – dann gibt es nichts mitzulöschen.
 * Das Formularfeld heißt store_too, weil es beide Ablagen meint.
 */
function mirror_delete_check_attr(string $kind, int $refId): string
{
    $store = mirror_store($kind, $refId);
    if ($store === '') return '';
    // Ausgewählte Originale gehören nicht der App – gar nicht erst anbieten.
    if (mirror_linked($kind, $refId)) return '';
    return ' data-confirm-check="Auch das Dok in ' . h(mirror_label($store))
        . ' löschen (landet im Papierkorb)" data-confirm-check-name="store_too"';
}

/**
 * Darf diese Person die Datei in der Ablage öffnen? Rollen aus mirror_allowed(),
 * zusätzlich zwei Sonderfälle, die keine Rolle abbildet:
 *   * Protokoll-Entwürfe: die:der eingeteilte Protokollant:in der Sitzung
 *   * Abstimmungs-/Umlauf-Anhänge: die:der Ersteller:in des Vorgangs
 * Beide Endpoints (teams.php, nextcloud.php) benutzen genau diese Prüfung.
 */
function mirror_open_permitted(string $kind, int $refId, ?array $me): bool
{
    if (mirror_allowed($kind)) return true;
    if (!$me) return false;
    if (in_array($kind, ['protokoll', 'protokoll_intern'], true)) {
        $st = db()->prepare('SELECT protocol_taker_id FROM meetings WHERE id = ?');
        $st->execute([$refId]);
        return (int)$st->fetchColumn() === (int)$me['id'];
    }
    if ($kind === 'ufile') {
        $st = db()->prepare('SELECT kind, ref_id FROM umlauf_files WHERE id = ?');
        $st->execute([$refId]);
        if ($f = $st->fetch()) {
            $st = db()->prepare((string)$f['kind'] === 'umlauf'
                ? 'SELECT created_by FROM circular_votes WHERE id = ?'
                : 'SELECT created_by FROM simple_polls WHERE id = ?');
            $st->execute([(int)$f['ref_id']]);
            return (int)$st->fetchColumn() === (int)$me['id'];
        }
    }
    return false;
}

/**
 * Kleiner „In Teams/Nextcloud öffnen"-Knopf – zeigt die Ablage, in der die Datei liegt,
 * sonst die eingestellte. '' wenn nichts eingerichtet ist oder die Rolle nicht darf.
 * $allowed überschreibt die Rollen-Prüfung (z. B. Ersteller:in bei Umlauf-Anhängen).
 */
function mirror_open_button(string $kind, int $refId, string $base = '', ?bool $allowed = null): string
{
    if (!mirror_configured() || !($allowed ?? mirror_allowed($kind))) return '';
    $has   = mirror_store($kind, $refId);
    $store = $has ?: mirror_backend();
    if ($store === 'nextcloud') {
        $ed = nc_editor_label();
        $verb = $ed !== '' ? 'bearbeiten' : 'öffnen';
        $title = $has
            ? 'In Nextcloud ' . $verb . ' (liegt schon dort – der Nextcloud-Stand ist führend)'
            : 'Einmalig in die Nextcloud kopieren und dort ' . $verb;
        if ($ed !== '') $title .= ' · ' . $ed;
        return '<a class="btn secondary small" href="' . h($base) . 'nextcloud.php?kind=' . rawurlencode($kind)
            . '&amp;id=' . $refId . '" target="_blank" rel="noopener" title="' . h($title)
            . '"><i class="ti ti-brand-nextcloud"></i> In Nextcloud ' . $verb . '</a>';
    }
    return teams_open_button($kind, $refId, $base, true);
}

// ---------------------------------------------------------------------------
// Datei aus der Ablage auswählen („Ablage-Picker")
//
// Der Picker blättert durch die Ablage und hängt eine dort vorhandene Datei direkt an –
// ohne den Umweg über Herunterladen und wieder Hochladen.
//
// Angehängt wird BEIDES, und das ist Absicht: eine lokale Fassung als Fallback UND der Verweis
// auf das Original. Damit gilt hier dieselbe Regel wie überall sonst in der App – der Stand in
// der Ablage ist führend (download.php liefert ihn aus), die lokale Kopie springt nur ein, wenn
// die Ablage einmal nicht erreichbar ist. „In Teams/Nextcloud öffnen" führt zum Original,
// nicht zu einem Duplikat.
//
// Die eine Sache, die dabei GEFÄHRLICH wäre: Beim Löschen eines Anhangs gibt es das Häkchen
// „Dok in der Ablage mitlöschen". Bei einer selbst hochgeladenen Datei ist das richtig – bei
// einer nur ausgewählten wäre es fatal, denn das Original gehört dem Team und wurde nicht für
// die App angelegt. Deshalb die Spalte `linked`: ausgewählte Dateien lassen sich in der Ablage
// nicht über die App löschen, das Häkchen erscheint gar nicht erst.
// ---------------------------------------------------------------------------

/** Erlaubte Dateiendungen für Anhänge – eine Liste für Upload UND Ablage-Auswahl. */
function attach_ext_allowed(): array
{
    return ['pdf','doc','docx','odt','rtf','txt','xls','xlsx','ods','csv','ppt','pptx','odp','png','jpg','jpeg','gif','webp','zip'];
}

/** Ablagen, aus denen ausgewählt werden kann (leer = nichts eingerichtet). */
function ablage_stores(): array
{
    $out = [];
    if (graph_configured() && trim((string)setting_get('graph_drive_id', '')) !== '') $out['teams'] = mirror_label('teams');
    if (nc_configured()) $out['nextcloud'] = mirror_label('nextcloud');
    return $out;
}

/** Kinder eines Teams-Ordners – Ordner UND Dateien (graph_list_folders liefert nur Ordner). */
function graph_list_children(string $driveId, string $folder = '', ?string &$err = null): ?array
{
    if ($driveId === '') { $err = 'Keine Dokumentbibliothek gewählt.'; return null; }
    $folder = trim(str_replace('\\', '/', $folder), '/');
    if ($folder === '') {
        $path = '/drives/' . $driveId . '/root/children';
    } else {
        $segs = array_map('rawurlencode', array_filter(explode('/', $folder), fn($s) => $s !== ''));
        $path = '/drives/' . $driveId . '/root:/' . implode('/', $segs) . ':/children';
    }
    $res = graph_api('GET', $path . '?$top=400&$select=id,name,size,folder,file', null, $err);
    if ($res === null) return null;
    $out = [];
    foreach (($res['value'] ?? []) as $it) {
        $out[] = [
            'name' => (string)($it['name'] ?? ''),
            'dir'  => isset($it['folder']),
            'ref'  => (string)($it['id'] ?? ''),   // Item-ID: überlebt Umbenennen und Verschieben
            'size' => (int)($it['size'] ?? 0),
        ];
    }
    usort($out, fn($a, $b) => ($b['dir'] <=> $a['dir']) ?: strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Pfad-Angaben aus dem Browser entschärfen: keine „..", keine führenden Schrägstriche. */
function ablage_clean_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    $segs = [];
    foreach (explode('/', $path) as $s) {
        $s = trim($s);
        if ($s === '' || $s === '.' || $s === '..') continue; // „..": kein Ausbruch nach oben
        $segs[] = $s;
    }
    return implode('/', $segs);
}

/**
 * Einen Ordner der Ablage auflisten. Rückgabe: ['path'=>…, 'entries'=>[['name','dir','ref','size']]].
 * Für Teams ist `ref` die Item-ID, für die Nextcloud der Pfad relativ zur WebDAV-Wurzel.
 */
function ablage_browse(string $store, string $path = '', ?string &$err = null): ?array
{
    $path = ablage_clean_path($path);
    if ($store === 'teams') {
        $rows = graph_list_children((string)setting_get('graph_drive_id', ''), $path, $err);
        if ($rows === null) return null;
        // Teams adressiert Ordner beim Blättern über den Pfad – die Item-ID braucht nur die Datei.
        foreach ($rows as &$r) if ($r['dir']) $r['ref'] = trim($path . '/' . $r['name'], '/');
        unset($r);
        return ['path' => $path, 'entries' => $rows];
    }
    if ($store === 'nextcloud') {
        $rows = dav_list_folder('nextcloud', $path, $err);
        if ($rows === null) return null;
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => (string)$r['name'], 'dir' => !empty($r['dir']),
                      'ref' => trim($path . '/' . (string)$r['name'], '/'), 'size' => (int)($r['size'] ?? 0)];
        }
        return ['path' => $path, 'entries' => $out];
    }
    $err = 'Unbekannte Ablage.';
    return null;
}

/**
 * Eine ausgewählte Datei holen: Bytes plus die Angaben, die für den Verweis nötig sind.
 * Rückgabe: ['name','bytes','drive_id','item_id','web_url','path','file_id'] oder null (+ $err).
 */
function ablage_fetch(string $store, string $ref, ?string &$err = null, int $maxBytes = 0): ?array
{
    if ($store === 'teams') {
        $driveId = (string)setting_get('graph_drive_id', '');
        $ref = trim($ref);
        if ($driveId === '' || $ref === '') { $err = 'Keine Datei gewählt.'; return null; }
        $meta = graph_api('GET', '/drives/' . $driveId . '/items/' . rawurlencode($ref)
            . '?$select=id,name,size,webUrl,file', null, $err);
        if ($meta === null) return null;
        if (isset($meta['folder'])) { $err = 'Das ist ein Ordner, keine Datei.'; return null; }
        // Teams nennt die Größe vorab – dann muss ein 500-MB-Video gar nicht erst durch den Server.
        if ($maxBytes > 0 && (int)($meta['size'] ?? 0) > $maxBytes) {
            $err = (string)($meta['name'] ?? 'Die Datei') . ': zu groß (max. ' . round($maxBytes / 1048576) . ' MB).';
            return null;
        }
        $bytes = graph_download_file($driveId, $ref, '', $err);
        if ($bytes === null) return null;
        return ['name' => (string)($meta['name'] ?? ''), 'bytes' => $bytes, 'drive_id' => $driveId,
                'item_id' => (string)($meta['id'] ?? $ref), 'web_url' => (string)($meta['webUrl'] ?? ''),
                'path' => '', 'file_id' => ''];
    }
    if ($store === 'nextcloud') {
        $rel = ablage_clean_path($ref);
        if ($rel === '') { $err = 'Keine Datei gewählt.'; return null; }
        $bytes = dav_get('nextcloud', $rel, $err);
        if ($bytes === null) return null;
        return ['name' => basename($rel), 'bytes' => $bytes, 'drive_id' => '', 'item_id' => '',
                'web_url' => '', 'path' => $rel, 'file_id' => dav_fileid('nextcloud', $rel)];
    }
    $err = 'Unbekannte Ablage.';
    return null;
}

/**
 * Eine BEREITS in der Ablage liegende Datei als Spiegel eintragen (statt eine neue anzulegen).
 * `linked = 1` merkt sich, dass die App sie nicht erzeugt hat – siehe Kommentar oben.
 */
function mirror_link_existing(string $kind, int $refId, string $store, array $meta, int $byMemberId): bool
{
    if ($refId <= 0 || !isset(mirror_kinds()[$kind])) return false;
    if ($store === 'teams') {
        db()->prepare('INSERT OR REPLACE INTO teams_files(kind, ref_id, drive_id, item_id, web_url, name, created_by, linked)
                       VALUES(?,?,?,?,?,?,?,1)')
           ->execute([$kind, $refId, (string)$meta['drive_id'], (string)$meta['item_id'],
                      (string)$meta['web_url'], (string)$meta['name'], $byMemberId ?: null]);
        return true;
    }
    if ($store === 'nextcloud') {
        db()->prepare('INSERT OR REPLACE INTO nc_files(kind, ref_id, path, file_id, name, created_by, linked)
                       VALUES(?,?,?,?,?,?,1)')
           ->execute([$kind, $refId, (string)$meta['path'], (string)$meta['file_id'],
                      (string)$meta['name'], $byMemberId ?: null]);
        return true;
    }
    return false;
}

/**
 * Knopf „Aus der Ablage wählen" samt Ablageplatz für die getroffene Auswahl. Gehört IN das
 * Formular, das die Datei anhängt – die Auswahl reist als picked_store[]/picked_ref[] mit.
 * Leerer String, wenn keine Ablage eingerichtet ist oder die Rolle den Bereich nicht darf.
 */
function ablage_pick_field(string $kind, string $base = ''): string
{
    if (!ablage_stores() || !mirror_allowed($kind)) return '';
    return '<div class="ablage-pick" data-kind="' . h($kind) . '" data-base="' . h($base) . '">'
        . '<button type="button" class="btn small secondary ablage-pick-btn">'
        . '<i class="ti ti-folder-search"></i> Aus der Ablage wählen</button>'
        . ' <span class="small muted">statt herunterladen und wieder hochladen</span>'
        . '<div class="ablage-picked"></div></div>';
}

/**
 * Die im Formular gewählten Ablage-Dateien anhängen – der gemeinsame Teil für alle Bereiche.
 *
 * Was hier passiert, ist überall gleich: Datei holen, Endung und Größe prüfen, eine lokale
 * Fassung als Fallback ablegen und das Original als Spiegel eintragen. Verschieden ist nur die
 * Tabelle, in die die Zeile kommt – das erledigt $insert und gibt die neue ID zurück
 * (0 = hat nicht geklappt). Rückgabe: Fehlermeldungen, eine je Datei.
 */
function ablage_pick_attach(string $kind, callable $insert, int $byMemberId, int $maxBytes = 52428800): array
{
    $fehler = [];
    $stores = (array)($_POST['picked_store'] ?? []);
    $refs   = (array)($_POST['picked_ref'] ?? []);
    if (!$stores) return $fehler;
    // Die Felder kommen aus dem Formular – die Rechte hängen am Bereich, nicht am Formular.
    if (!mirror_allowed($kind)) return ['Für diesen Bereich darfst du keine Dateien aus der Ablage anhängen.'];

    $dir = upload_dir();
    foreach ($stores as $i => $s) {
        $ref = (string)($refs[$i] ?? '');
        if (!is_string($s) || $ref === '') continue;
        $err = null;
        $datei = ablage_fetch($s, $ref, $err, $maxBytes);
        if ($datei === null) { $fehler[] = $err ?: 'Eine Datei aus der Ablage konnte nicht geladen werden.'; continue; }

        $orig = (string)$datei['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, attach_ext_allowed(), true)) { $fehler[] = $orig . ': Dateityp „.' . $ext . '" ist nicht erlaubt.'; continue; }
        // Bei Teams steht die Größe schon vorab fest (ablage_fetch prüft sie); die Nextcloud
        // verrät sie erst mit dem Inhalt – deshalb hier noch einmal.
        if (strlen($datei['bytes']) > $maxBytes) { $fehler[] = $orig . ': zu groß (max. ' . round($maxBytes / 1048576) . ' MB).'; continue; }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $fehler[] = 'Upload-Ordner konnte nicht angelegt werden.'; continue; }

        $stored = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        if (@file_put_contents($dir . '/' . $stored, $datei['bytes']) === false) { $fehler[] = $orig . ': konnte nicht gespeichert werden.'; continue; }
        $mime = function_exists('mime_content_type') ? (mime_content_type($dir . '/' . $stored) ?: '') : '';
        $id = (int)$insert($orig, $stored, $mime, strlen($datei['bytes']));
        if ($id > 0) {
            mirror_link_existing($kind, $id, $s, $datei, $byMemberId);
        } else {
            @unlink($dir . '/' . $stored);   // keine Zeile, keine Datei – sonst bleibt Müll liegen
            $fehler[] = $orig . ': konnte nicht angehängt werden.';
        }
    }
    return $fehler;
}

/** Ist der Spiegel dieser Datei nur ein Verweis auf ein fremdes Original (nicht von der App angelegt)? */
function mirror_linked(string $kind, int $refId): bool
{
    if ($row = teams_file_row($kind, $refId)) return !empty($row['linked']);
    if ($row = nc_file_row($kind, $refId))    return !empty($row['linked']);
    return false;
}

// ---------------------------------------------------------------------------
// Upload-Automationen (zeitgesteuert, ausgeführt von cron_automations.php)
// ---------------------------------------------------------------------------

/** Idempotenz: wurde diese Automation für die Referenz schon erfolgreich ausgeführt? */
function automation_done(string $automation, int $refId): bool
{
    $st = db()->prepare("SELECT 1 FROM automation_log WHERE automation=? AND ref_id=? AND status='success' LIMIT 1");
    $st->execute([$automation, $refId]);
    return (bool)$st->fetchColumn();
}

/** Einen Lauf protokollieren. */
function automation_log_add(string $automation, int $refId, string $status, string $detail = ''): void
{
    $st = db()->prepare('INSERT INTO automation_log(automation, ref_id, status, detail) VALUES(?,?,?,?)');
    $st->execute([$automation, $refId, $status, $detail]);
}

/** Letzter Log-Eintrag einer Automation (für die Status-Anzeige). */
function automation_last(string $automation): ?array
{
    $st = db()->prepare('SELECT * FROM automation_log WHERE automation=? ORDER BY id DESC LIMIT 1');
    $st->execute([$automation]);
    return $st->fetch() ?: null;
}

/** Datei je nach Ziel-Dienst hochladen. $target = 'sharepoint' | 'olat'. */
function automation_upload(string $target, string $folder, string $filename, string $localPath, ?string &$err = null): bool
{
    if ($target === 'olat' || $target === 'nextcloud') {
        // Beide sprechen WebDAV – nur der Basisordner unterscheidet sich.
        $base = $target === 'olat'
            ? trim((string)setting_get('olat_base_folder', ''), '/')
            : nc_base_folder();
        $full = trim(($base !== '' ? $base . '/' : '') . trim($folder, '/'), '/');
        if (!is_file($localPath)) { $err = 'Lokale Datei nicht gefunden.'; return false; }
        $data = file_get_contents($localPath);
        if ($data === false) { $err = 'Datei konnte nicht gelesen werden.'; return false; }
        return dav_put($target, trim($full . '/' . $filename, '/'), $data, $err);
    }
    // Standard: SharePoint/Teams
    $driveId = (string)setting_get('graph_drive_id', '');
    if ($driveId === '') { $err = 'Keine SharePoint-Dokumentbibliothek gewählt (Technik/Einrichtung).'; return false; }
    return graph_upload_file($driveId, $folder, $filename, $localPath, $err) !== null;
}

/** Dateiname aus Muster bilden. Platzhalter: {sitzung}, {datum}, {datumzeit}. */
function automation_filename(string $pattern, array $meeting, string $ext = 'docx'): string
{
    $repl = [
        '{sitzung}'   => meeting_label($meeting),
        '{datum}'     => substr((string)$meeting['starts_at'], 0, 10),
        '{datumzeit}' => substr((string)$meeting['starts_at'], 0, 16),
    ];
    $name = strtr($pattern !== '' ? $pattern : 'Berichte_{sitzung}_{datum}', $repl);
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name);
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    if ($name === '') $name = 'Berichte_' . substr((string)$meeting['starts_at'], 0, 10);
    return $name . '.' . $ext;
}

/** Ist die Berichte-Dok-Automation aktiviert? */
function auto_reportdoc_enabled(): bool { return setting_get('auto_reportdoc_enabled', '0') === '1'; }

/**
 * Berichte-Dok-Automation ausführen: kurz vor Sitzungsstart die Berichte-DOCX
 * erzeugen und in die konfigurierte Ablage hochladen.
 * $opts: 'force' (bool) – Zeitfenster/Done-Prüfung ignorieren (Test); 'meeting_id' (int) – gezielt eine Sitzung.
 * Rückgabe: ['count'=>int hochgeladen, 'lines'=>string[] Protokoll].
 */
function run_report_doc_automation(array $opts = []): array
{
    $force = (bool)($opts['force'] ?? false);
    $lines = [];
    if (!$force && !auto_reportdoc_enabled()) return ['count' => 0, 'lines' => ['Automation ist deaktiviert.']];
    if (!class_exists('ZipArchive')) return ['count' => 0, 'lines' => ['ZipArchive (PHP-Zip) fehlt – kein DOCX möglich.']];

    $trigger = (string)setting_get('auto_reportdoc_time', '18:00'); // Uhrzeit am Sitzungstag, ab der erzeugt wird
    if (!preg_match('/^\d{1,2}:\d{2}$/', $trigger)) $trigger = '18:00';
    $target  = in_array(setting_get('auto_reportdoc_target', 'sharepoint'), ['olat', 'nextcloud'], true)
        ? (string)setting_get('auto_reportdoc_target', 'sharepoint') : 'sharepoint';
    $pattern = (string)setting_get('auto_reportdoc_filename', 'Berichte_{sitzung}_{datum}');
    $requireReports = setting_get('auto_reportdoc_require_reports', '1') === '1';

    if (!empty($opts['meeting_id'])) {
        $st = db()->prepare('SELECT * FROM meetings WHERE id=? AND draft=0');
        $st->execute([(int)$opts['meeting_id']]);
        $meetings = ($m = $st->fetch()) ? [$m] : [];
    } else {
        $meetings = db()->query("SELECT * FROM meetings WHERE needs_report=1 AND draft=0 AND cancelled=0 ORDER BY starts_at")->fetchAll();
    }

    $now = time();
    $count = 0;
    foreach ($meetings as $m) {
        $start = strtotime((string)$m['starts_at']);
        if (!$start) continue;
        if (!$force) {
            // Fällig ab der Auslösezeit am Sitzungstag (Standard 18:00, nach der RSVP-Frist).
            // Wichtig: bis zu 3 Tage NACHHOLEN – wenn um 18:01 noch keine Berichte da waren
            // (oder ein Cron-Lauf ausfiel), lädt der nächste tägliche Lauf trotzdem noch hoch.
            $due = strtotime(date('Y-m-d', $start) . ' ' . $trigger);
            if ($due === false || $now < $due) continue;      // noch nicht fällig (auch: Sitzung liegt in der Zukunft)
            $daysAgo = (int)round((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $start))) / 86400);
            if ($daysAgo > 3) continue;                       // Sitzungstag länger als 3 Tage her – nicht mehr nachholen
            if (automation_done('reportdoc', (int)$m['id'])) continue;
        }
        $filled = meeting_reports_filled($m);
        if ($requireReports && $filled === 0) {
            $lines[] = meeting_label($m) . ': übersprungen (noch keine Berichte eingetragen).';
            continue; // nicht als erledigt markieren → späterer Lauf versucht es erneut
        }
        $doc   = meeting_reports_docx($m);
        $fname = automation_filename($pattern, $m);
        $tmp   = tempnam(sys_get_temp_dir(), 'rep');
        if ($tmp === false || @file_put_contents($tmp, $doc['bin']) === false) {
            automation_log_add('reportdoc', (int)$m['id'], 'error', 'Temp-Datei fehlgeschlagen');
            $lines[] = meeting_label($m) . ': Fehler beim Erzeugen der Datei.';
            if ($tmp) @unlink($tmp);
            continue;
        }
        $err = null;
        $ok = automation_upload($target, report_target_folder($m), $fname, $tmp, $err); // Basisordner + ggf. /JAHR
        @unlink($tmp);
        if ($ok) {
            // Test-Uploads (force) zählen NICHT als erledigt – sonst würde automation_done()
            // den echten Cron-Lauf für diese Sitzung dauerhaft überspringen.
            automation_log_add('reportdoc', (int)$m['id'], $force ? 'test' : 'success', ($force ? 'Test: ' : '') . $fname . ' → ' . $target);
            $lines[] = meeting_label($m) . ': „' . $fname . '" nach '
                . match ($target) { 'olat' => 'OLAT', 'nextcloud' => 'Nextcloud', default => 'Teams/SharePoint' }
                . ' hochgeladen (' . $filled . ' Bericht(e)).';
            $count++;
        } else {
            automation_log_add('reportdoc', (int)$m['id'], 'error', (string)$err);
            $lines[] = meeting_label($m) . ': Upload fehlgeschlagen – ' . $err;
        }
    }
    if (!$lines) $lines[] = 'Keine passende Sitzung im Zeitfenster.';
    return ['count' => $count, 'lines' => $lines];
}

// ---------------------------------------------------------------------------
// Protokoll-Workflow: Protokollant:in wählen → fertiges Protokoll nach Teams
// hochladen → Abstimmungsgegenstand in der Folgesitzung → nach Annahme als PDF
// nach OLAT veröffentlichen. Die Dok selbst wird NICHT in der App gespeichert,
// sondern lebt in Teams (Metadaten/Verweise reichen).
// ---------------------------------------------------------------------------

/** Klartext-Label eines Protokoll-Status. */
function protocol_status_label(string $status): string
{
    return [
        ''          => 'offen',
        'uploaded'  => 'in Teams – Abstimmung offen',
        'approved'  => 'angenommen – Veröffentlichung offen',
        'published' => 'in OLAT veröffentlicht',
    ][$status] ?? $status;
}

/** Protokollant:in einer Sitzung setzen (0/null entfernt die Zuordnung). */
function protocol_set_taker(int $meetingId, ?int $memberId): void
{
    db()->prepare('UPDATE meetings SET protocol_taker_id = ? WHERE id = ?')
       ->execute([$memberId ?: null, $meetingId]);
}

/** Soll unter dem Protokoll-Ordner automatisch nach Jahr unterteilt werden? (Standard: ja) */
function protocol_year_subfolder(): bool
{
    return (string)setting_get('protocol_year_subfolder', '1') !== '0';
}

/** Jahr (YYYY) einer Sitzung – für die /Protokolle/JAHR/-Ablage. */
function protocol_year_of(array $m): string
{
    return substr((string)($m['starts_at'] ?? ''), 0, 4) ?: date('Y');
}

/** Teams-Zielordner für das ÖFFENTLICHE Protokoll einer Sitzung (Basisordner + ggf. /JAHR). */
function protocol_teams_target_folder(array $m): string
{
    $base = trim(str_replace('\\', '/', (string)setting_get('protocol_teams_folder', '')), '/');
    if (protocol_year_subfolder()) $base = trim($base . '/' . protocol_year_of($m), '/');
    return $base;
}

/** Teams-Zielordner für das INTERNE Protokoll (eigener Ordner, wird nie nach OLAT veröffentlicht). */
function protocol_teams_intern_target_folder(array $m): string
{
    $base = trim(str_replace('\\', '/', (string)setting_get('protocol_teams_intern_folder', '')), '/');
    if (protocol_year_subfolder()) $base = trim($base . '/' . protocol_year_of($m), '/');
    return $base;
}

/** Dateiname der hochgeladenen Protokoll-Dok (.docx); $intern = interner Teil. */
/** Sitzungsnummer im Label zweistellig auffüllen (nur für Dateinamen: „7." → „07.",
 *  damit die Dateien im Ordner in Sitzungsreihenfolge sortieren – wie die Vorlagen-Downloads). */
function protokoll_nummer_pad(string $label): string
{
    return preg_replace_callback('/^(\d+)\./', fn($m) => sprintf('%02d', (int)$m[1]) . '.', $label);
}

function protocol_docx_filename(array $m, bool $intern = false): string
{
    // Datum als TT.MM.JJJJ – so lesen es Menschen, und so heißen auch die Vorlagen-Downloads
    $datum = ($t = strtotime((string)$m['starts_at'])) ? date('d.m.Y', $t) : '';
    $name = ($intern ? 'Protokoll_intern_' : 'Protokoll_') . protokoll_nummer_pad(meeting_label($m)) . '_' . $datum;
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name);
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    if ($name === '') $name = ($intern ? 'Protokoll_intern_' : 'Protokoll_') . $datum;
    return $name . '.docx';
}

/** OLAT-Zielordner für das veröffentlichte Protokoll-PDF (OLAT-Basisordner + Protokoll-Unterordner + ggf. /JAHR). */
function protocol_olat_target_folder(array $m): string
{
    $base = trim((string)setting_get('olat_base_folder', ''), '/');
    $sub  = trim(str_replace('\\', '/', (string)setting_get('protocol_olat_folder', '')), '/');
    $folder = trim(($base !== '' ? $base . '/' : '') . $sub, '/');
    if (protocol_year_subfolder()) $folder = trim($folder . '/' . protocol_year_of($m), '/');
    return $folder;
}

/** Soll die Berichte-Dok automatisch nach Jahr unterteilt werden? (Standard: ja) */
function report_year_subfolder(): bool
{
    return (string)setting_get('auto_reportdoc_year_subfolder', '1') !== '0';
}

/** Zielordner der Berichte-Dok einer Sitzung (Basisordner + ggf. /JAHR – analog zum Protokoll). */
function report_target_folder(array $m): string
{
    $base = trim(str_replace('\\', '/', (string)setting_get('auto_reportdoc_folder', '')), '/');
    if (report_year_subfolder()) $base = trim($base . '/' . protocol_year_of($m), '/');
    return $base;
}

/**
 * Nächste Sitzung NACH einem Zeitpunkt, die als Ziel einer Protokoll-Abstimmung taugt:
 * veröffentlicht, nicht abgesagt, kein StuPa (das genehmigt seine Protokolle selbst) – und
 * standardmäßig noch NICHT vorbei.
 *
 * Das „noch nicht vorbei" ist kein Schönheitsfehler, sondern der Unterschied zwischen einer
 * Abstimmung, die stattfindet, und einer, die niemand mehr sieht: Wird ein Protokoll erst
 * Wochen später hochgeladen, ist die eigentliche Folgesitzung längst gelaufen. Der Gegenstand
 * gehört dann in die nächste Sitzung, die noch kommt. Sitzungen laufen bis Tagesende – am
 * Sitzungstag selbst zählt die Sitzung deshalb noch als „kommend".
 */
function protocol_next_meeting_after(string $startsAt, bool $nurKommende = true): ?array
{
    $sql = "SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa' AND starts_at > ?";
    // „Noch nicht vorbei" heißt bei Sitzungen: Der Sitzungstag ist noch nicht durch. Bewusst über
    // das DATUM und nicht über ends_at: Die Spalte wird nicht geschrieben (meeting_ends_at()),
    // und ein leerer Wert würde jede Sitzung ab ihrer Startminute als vorbei zählen. Am
    // Sitzungstag selbst gilt die Sitzung also weiter als kommend.
    if ($nurKommende) $sql .= " AND date(starts_at) >= date('now','localtime')";
    $st = db()->prepare($sql . ' ORDER BY starts_at LIMIT 1');
    $st->execute([$startsAt]);
    return $st->fetch() ?: null;
}

/**
 * Ab wann für diese Quell-Sitzung nach einem Ziel gesucht wird: nach der Sitzung selbst –
 * und nach einer Vertagung erst nach der Sitzung, in der vertagt wurde.
 */
function protocol_vote_anchor(array $src): string
{
    $ab = (string)($src['starts_at'] ?? '');
    $nach = trim((string)($src['protocol_vote_after'] ?? ''));
    return ($nach !== '' && $nach > $ab) ? $nach : $ab;
}

/** Titel und Text des Protokoll-Gegenstands – aus der Quell-Sitzung abgeleitet, an einer Stelle. */
function protocol_vote_texts(array $src): array
{
    // Genitiv nach „Protokoll der …": aus „13. Ordentliche Sitzung" wird „13. Ordentlichen Sitzung".
    $label = preg_replace('/\b(Ordentliche|Außerordentliche)(?= Sitzung)/u', '$1n', meeting_label($src));
    return [
        'Protokoll der ' . $label . ' genehmigen',
        'Abstimmung über das Protokoll der ' . $label . ' vom ' . fmt_date(substr((string)$src['starts_at'], 0, 10))
            . '. Das Dokument liegt in Teams und kann dort gemeinsam bearbeitet werden.',
    ];
}

/**
 * Nach erfolgreichem Upload der fertigen Protokoll-Dok nach Teams: Verweise merken,
 * Status auf 'uploaded' und – falls schon eine Folgesitzung existiert – dort den
 * Abstimmungsgegenstand anlegen (sonst passiert das beim Anlegen der Folgesitzung).
 */
function protocol_after_upload(array $meeting, array $driveItem, int $byMemberId): void
{
    // Nachgereichte Fassung: Der bestehende Gegenstand bleibt stehen (mit seinen Gelesen-Haken),
    // steht aber wieder auf „offen" – abgestimmt wird über das neue Dokument. Ein zweiter
    // Gegenstand für dieselbe Sitzung wäre nur verwirrend.
    if ($alt = protocol_vote_item((int)$meeting['id'])) {
        db()->prepare("UPDATE vote_items SET decision = 'offen', decided_by = NULL, decided_at = NULL WHERE id = ?")
           ->execute([(int)$alt['id']]);
    }
    db()->prepare(
        "UPDATE meetings SET protocol_status = 'uploaded', protocol_drive_id = ?, protocol_item_id = ?,
             protocol_web_url = ?, protocol_filename = ?, protocol_uploaded_at = datetime('now','localtime'),
             protocol_uploaded_by = ?, protocol_vote_off = 0, protocol_teams_url = NULL WHERE id = ?"
    )->execute([
        (string)($driveItem['parentReference']['driveId'] ?? setting_get('graph_drive_id', '')),
        (string)($driveItem['id'] ?? ''),
        (string)($driveItem['webUrl'] ?? ''),
        (string)($driveItem['name'] ?? ''),
        $byMemberId ?: null,
        (int)$meeting['id'],
    ]);
    protocol_sync_pending_votes();
}

/**
 * Beste Öffnen-URL des fertigen Protokolls: Teams-Deep-Link (öffnet die Teams-App), sonst die
 * webUrl (Office im Browser). Der Deep-Link wird beim ersten Aufruf einmalig per Graph gebaut
 * und an der Sitzung gemerkt – dieselbe Mechanik wie teams_file_open_url() bei den Entwürfen.
 */
function protocol_open_url(array $m, bool $intern = false): string
{
    $pre = $intern ? 'protocol_intern_' : 'protocol_';
    $t = trim((string)($m[$pre . 'teams_url'] ?? ''));
    if ($t !== '') return $t;
    $web   = trim((string)($m[$pre . 'web_url'] ?? ''));
    $drive = trim((string)($m[$pre . 'drive_id'] ?? ''));
    $item  = trim((string)($m[$pre . 'item_id'] ?? ''));
    if ($drive === '' || $item === '' || (int)($m['id'] ?? 0) <= 0 || !graph_configured()) return $web;
    $err = null;
    $it = graph_api('GET', '/drives/' . $drive . '/items/' . rawurlencode($item)
        . '?$select=id,name,webUrl,webDavUrl,sharepointIds', null, $err);
    $t = $it ? teams_deep_link($it, $drive) : '';
    if ($t === '') return $web;
    db()->prepare('UPDATE meetings SET ' . $pre . 'teams_url = ? WHERE id = ?')->execute([$t, (int)$m['id']]);
    return $t;
}

/**
 * Nach Upload des INTERNEN Protokolls nach Teams: nur Verweise merken. Kein Status-Wechsel,
 * keine Abstimmung, keine OLAT-Veröffentlichung – der interne Teil bleibt ausschließlich in Teams.
 */
function protocol_after_intern_upload(array $meeting, array $driveItem, int $byMemberId): void
{
    db()->prepare(
        "UPDATE meetings SET protocol_intern_drive_id = ?, protocol_intern_item_id = ?, protocol_intern_web_url = ?,
             protocol_intern_filename = ?, protocol_intern_uploaded_at = datetime('now','localtime'), protocol_intern_uploaded_by = ?,
             protocol_intern_teams_url = NULL
         WHERE id = ?"
    )->execute([
        (string)($driveItem['parentReference']['driveId'] ?? setting_get('graph_drive_id', '')),
        (string)($driveItem['id'] ?? ''),
        (string)($driveItem['webUrl'] ?? ''),
        (string)($driveItem['name'] ?? ''),
        $byMemberId ?: null,
        (int)$meeting['id'],
    ]);
}

/**
 * Der aktuelle Protokoll-Gegenstand einer Sitzung – ABGELEITET aus vote_items.ref_meeting_id.
 *
 * Die Beziehung steht NUR in vote_items.ref_meeting_id und wird nirgends gespiegelt. Ein
 * zusätzlicher Rückverweis an der Sitzung überlebte die Fremdschlüssel-Kaskade beim Löschen
 * der Zielsitzung und ließe das Protokoll still aus dem Verfahren fallen.
 *
 * „Aktuell" heißt: der noch offene. Nach einer Vertagung gibt es zwei – der vertagte bleibt als
 * Beleg in seiner Sitzung stehen, der offene ist der, um den es geht. Gibt es keinen offenen
 * (angenommen oder vertagt ohne Nachfolger), zählt der zuletzt angelegte.
 */
function protocol_vote_item(int $meetingId): ?array
{
    if ($meetingId <= 0) return null;
    $st = db()->prepare("SELECT * FROM vote_items WHERE kind = 'protocol' AND ref_meeting_id = ?
                         ORDER BY (decision = 'offen') DESC, id DESC LIMIT 1");
    $st->execute([$meetingId]);
    return $st->fetch() ?: null;
}

/** Nächster freier Platz in der Abstimmungsliste einer Sitzung. */
function protocol_vote_sort(int $meetingId): int
{
    return (int)db()->query('SELECT COALESCE(MAX(sort), -1) + 1 FROM vote_items WHERE meeting_id = ' . $meetingId)->fetchColumn();
}

/** Legt den Protokoll-Abstimmungsgegenstand in der Zielsitzung an und gibt seine ID zurück. */
function protocol_create_vote_item(array $source, array $target): int
{
    [$title, $body] = protocol_vote_texts($source);
    db()->prepare("INSERT INTO vote_items(meeting_id, title, body, sort, created_by, kind, ref_meeting_id, decision) VALUES(?,?,?,?,?,'protocol',?,'offen')")
       ->execute([(int)$target['id'], $title, $body, protocol_vote_sort((int)$target['id']), null, (int)$source['id']]);
    return (int)db()->lastInsertId();
}

/**
 * Hält die Protokoll-Abstimmungen mit dem Sitzungskalender in Übereinstimmung. Läuft nach jedem
 * Upload, nach jeder Änderung am Sitzungsplan und einmal täglich im Cron. Idempotent.
 *
 * Der Kalender ist beweglich – Sitzungen werden abgesagt, gelöscht, verschoben, und manchmal
 * wird eine Folgesitzung erst Wochen später eingetragen. Ein einmal gesetzter Verweis darf
 * deshalb nicht als endgültig gelten. Drei Durchgänge:
 *
 *  1. WAISEN: Gegenstände, deren Quell-Sitzung es nicht mehr gibt, verschwinden.
 *  2. UMHÄNGEN: Ist die Zielsitzung gelöscht, abgesagt, wieder Entwurf oder liegt sie nach einer
 *     Verschiebung gar nicht mehr NACH der Sitzung, deren Protokoll sie genehmigen soll, wandert
 *     der Gegenstand in die richtige Sitzung – als Umzug, nicht als Neuanlage, damit
 *     Gelesen-Haken und Anhänge erhalten bleiben. Ist inzwischen eine FRÜHERE passende Sitzung
 *     dazugekommen, rutscht er dorthin: Ein Protokoll wird in der nächsten Sitzung genehmigt,
 *     nicht irgendwann. Beides nur, solange kein Beschluss festgehalten ist.
 *  3. ANLEGEN: Alles Hochgeladene ohne Gegenstand bekommt einen – sofern die Abstimmung nicht
 *     bewusst entfernt wurde.
 *
 * Rückgabe: Anzahl der Änderungen (angelegt + umgehängt).
 */
function protocol_sync_pending_votes(): int
{
    $pdo = db();
    $aenderungen = 0;

    // --- 1. Waisen: Quell-Sitzung gelöscht → der Gegenstand hat keinen Gegenstand mehr --------
    $pdo->exec("DELETE FROM vote_items WHERE kind = 'protocol' AND ref_meeting_id IS NOT NULL
                AND ref_meeting_id NOT IN (SELECT id FROM meetings)");

    // --- 2. Vorhandene Gegenstände prüfen ----------------------------------------------------
    // Gelaufen wird über die Gegenstände selbst: Sie tragen die Beziehung (ref_meeting_id). Fällt
    // einer weg, ist er einfach weg – es bleibt kein Verweis zurück, der ins Leere zeigt.
    $items = $pdo->query("SELECT * FROM vote_items WHERE kind = 'protocol' ORDER BY id")->fetchAll();
    foreach ($items as $vi) {
        $mq = $pdo->prepare('SELECT * FROM meetings WHERE id = ?');
        $mq->execute([(int)$vi['ref_meeting_id']]);
        $m = $mq->fetch();
        if (!$m) continue; // Waise – Durchgang 1 hat sie bereits entfernt
        // Titel nachziehen: Sitzungsnummern verschieben sich, wenn davor etwas eingefügt oder
        // abgesagt wird. Der Gegenstand soll nicht auf eine Nummer zeigen, die es nicht mehr gibt.
        [$titel, $text] = protocol_vote_texts($m);
        if ((string)$vi['title'] !== $titel) {
            $pdo->prepare('UPDATE vote_items SET title = ?, body = ? WHERE id = ?')->execute([$titel, $text, (int)$vi['id']]);
        }
        if ((string)($vi['decision'] ?? 'offen') !== 'offen') continue; // Beschlossenes bleibt, wo es ist

        $ziel = null;
        $zq = $pdo->prepare('SELECT * FROM meetings WHERE id = ?');
        $zq->execute([(int)$vi['meeting_id']]);
        $ziel = $zq->fetch() ?: null;
        $ab = protocol_vote_anchor($m);
        $untauglich = !$ziel || !empty($ziel['cancelled']) || !empty($ziel['draft']) || (string)$ziel['starts_at'] <= $ab;
        $neu = protocol_next_meeting_after($ab);
        if (!$untauglich) {
            // Taugliches Ziel – aber vielleicht gibt es inzwischen eine frühere passende Sitzung.
            if (!$neu || (int)$neu['id'] === (int)$ziel['id'] || (string)$neu['starts_at'] >= (string)$ziel['starts_at']) continue;
        }
        if (!$neu) { // gar keine kommende Sitzung mehr: Gegenstand weg, Durchgang 3 wartet auf eine neue
            if ($untauglich) {
                $pdo->prepare('DELETE FROM vote_items WHERE id = ?')->execute([(int)$vi['id']]);
                $aenderungen++;
            }
            continue;
        }
        $pdo->prepare('UPDATE vote_items SET meeting_id = ?, sort = ? WHERE id = ?')
           ->execute([(int)$neu['id'], protocol_vote_sort((int)$neu['id']), (int)$vi['id']]);
        $aenderungen++;
    }

    // --- 3. Fehlende Gegenstände anlegen -----------------------------------------------------
    // Nur 'uploaded': Ein bereits angenommenes Protokoll bekommt keine zweite Genehmigung, auch
    // wenn der Gegenstand mit seiner Sitzung gelöscht wurde – der Beschluss ist gefasst.
    $offen = $pdo->query(
        "SELECT m.* FROM meetings m WHERE m.protocol_status = 'uploaded' AND m.protocol_vote_off = 0
           AND NOT EXISTS (SELECT 1 FROM vote_items v WHERE v.kind = 'protocol'
                           AND v.ref_meeting_id = m.id AND v.decision = 'offen')
         ORDER BY m.starts_at"
    )->fetchAll();
    foreach ($offen as $m) {
        $target = protocol_next_meeting_after(protocol_vote_anchor($m));
        if (!$target) continue;
        if (protocol_create_vote_item($m, $target) > 0) $aenderungen++;
    }
    return $aenderungen;
}

/**
 * Eine bewusst entfernte Protokoll-Abstimmung wieder zulassen (Knopf in der Protokoll-Karte).
 * Rückgabe: true, wenn dabei ein Gegenstand entstanden ist.
 */
function protocol_vote_reactivate(int $meetingId): bool
{
    db()->prepare('UPDATE meetings SET protocol_vote_off = 0 WHERE id = ?')->execute([$meetingId]);
    return protocol_sync_pending_votes() > 0;
}

/**
 * Eine Sitzung samt allem löschen, was auf sie zeigt. Die eigenen Abstimmungsgegenstände nimmt
 * die Fremdschlüssel-Kaskade mit; die Protokoll-Genehmigung, die in einer ANDEREN Sitzung auf
 * diese hier verweist, nicht – die muss hier weg, sonst bleibt sie als Karteileiche stehen.
 */
function meeting_delete(int $meetingId): void
{
    db()->prepare("DELETE FROM vote_items WHERE kind = 'protocol' AND ref_meeting_id = ?")->execute([$meetingId]);
    db()->prepare('DELETE FROM meetings WHERE id = ?')->execute([$meetingId]);
    protocol_sync_pending_votes();
}

/**
 * Beschluss eines Abstimmungsgegenstands festhalten. Bei einem Protokoll-Gegenstand
 * (kind=protocol) schaltet „angenommen" die Quell-Sitzung auf 'approved' → dann
 * erscheint die Sekretariats-Aufgabe „Protokoll veröffentlichen". Zurücknehmen
 * (offen/vertagt) setzt 'approved' wieder auf 'uploaded' (ein bereits in OLAT
 * veröffentlichtes Protokoll bleibt unangetastet). $decision: offen|angenommen|vertagt.
 */
function vote_item_set_decision(int $itemId, string $decision, int $byMemberId): void
{
    if (!in_array($decision, ['offen', 'angenommen', 'vertagt'], true)) return;
    $vi = vote_item_get($itemId);
    if (!$vi) return;
    db()->prepare("UPDATE vote_items SET decision = ?, decided_by = ?, decided_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$decision, $byMemberId ?: null, $itemId]);
    if (($vi['kind'] ?? '') !== 'protocol' || (int)($vi['ref_meeting_id'] ?? 0) <= 0) return;

    $src = (int)$vi['ref_meeting_id'];
    if ($decision === 'angenommen') {
        db()->prepare("UPDATE meetings SET protocol_status = 'approved' WHERE id = ? AND protocol_status = 'uploaded'")->execute([$src]);
        return;
    }
    db()->prepare("UPDATE meetings SET protocol_status = 'uploaded' WHERE id = ? AND protocol_status = 'approved'")->execute([$src]);

    if ($decision === 'vertagt') {
        // Vertagt heißt: nicht hier, sondern beim nächsten Mal. Der vertagte Gegenstand bleibt als
        // Beleg in seiner Sitzung stehen; für die Genehmigung entsteht ein neuer in der nächsten
        // Sitzung. Ohne den Merker würde der Abgleich dieselbe Sitzung wieder wählen.
        $mq = db()->prepare('SELECT starts_at FROM meetings WHERE id = ?');
        $mq->execute([(int)$vi['meeting_id']]);
        $wo = (string)($mq->fetchColumn() ?: '');
        db()->prepare('UPDATE meetings SET protocol_vote_after = ? WHERE id = ?')->execute([$wo, $src]);
        protocol_sync_pending_votes();
        return;
    }

    // Zurück auf „offen": eine wegen Vertagung angelegte Nachfolge-Abstimmung wieder einsammeln,
    // sonst stünde dieselbe Genehmigung zweimal offen im Kalender.
    $nf = db()->prepare("SELECT id FROM vote_items WHERE kind = 'protocol' AND ref_meeting_id = ?
                         AND id <> ? AND decision = 'offen'");
    $nf->execute([$src, $itemId]);
    foreach ($nf->fetchAll() as $x) {
        db()->prepare('DELETE FROM vote_items WHERE id = ?')->execute([(int)$x['id']]);
    }
    db()->prepare('UPDATE meetings SET protocol_vote_after = NULL WHERE id = ?')->execute([$src]);
}

/**
 * Wird beim Löschen eines Protokoll-Abstimmungsgegenstands von Hand aufgerufen: Verknüpfung lösen
 * UND merken, dass die Abstimmung nicht gewollt ist. Ohne diesen Merker legte der nächste
 * Abgleich sie stumm wieder an – man bekam den Gegenstand nicht weg. In der Protokoll-Karte der
 * Quell-Sitzung steht dafür der Knopf „Abstimmung wieder anlegen".
 */
function protocol_detach_vote_item(int $itemId): void
{
    $vi = vote_item_get($itemId);
    if (!$vi || ($vi['kind'] ?? '') !== 'protocol' || (int)($vi['ref_meeting_id'] ?? 0) <= 0) return;
    // Zurück auf 'uploaded': ohne Abstimmung gibt es keine Annahme, die man veröffentlichen könnte.
    db()->prepare("UPDATE meetings SET protocol_status = 'uploaded' WHERE id = ? AND protocol_status = 'approved'")
       ->execute([(int)$vi['ref_meeting_id']]);
    db()->prepare('UPDATE meetings SET protocol_vote_off = 1 WHERE id = ?')->execute([(int)$vi['ref_meeting_id']]);
}

/** Dateiname des veröffentlichten Protokoll-PDFs. */
function protocol_pdf_filename(array $m): string
{
    $datum = ($t = strtotime((string)$m['starts_at'])) ? date('d.m.Y', $t) : '';
    $name = 'Protokoll_' . protokoll_nummer_pad(meeting_label($m)) . '_' . $datum;
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name);
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    if ($name === '') $name = 'Protokoll_' . $datum;
    return $name . '.pdf';
}

/**
 * Angenommenes Protokoll aus Teams als PDF ziehen (Graph konvertiert DOCX→PDF)
 * und nach OLAT hochladen (reine Kopie – das Dok bleibt in Teams). Setzt bei
 * Erfolg protocol_status='published'. Rückgabe true/false (+ $err).
 */
function protocol_publish_to_olat(int $meetingId, int $byMemberId, ?string &$err = null): bool
{
    $st = db()->prepare('SELECT * FROM meetings WHERE id = ?');
    $st->execute([$meetingId]);
    $m = $st->fetch();
    if (!$m) { $err = 'Sitzung nicht gefunden.'; return false; }
    if ((string)$m['protocol_status'] !== 'approved') { $err = 'Das Protokoll ist nicht im Zustand „angenommen".'; return false; }
    if (trim((string)$m['protocol_item_id']) === '') { $err = 'Kein Teams-Dokument hinterlegt.'; return false; }
    if (!olat_configured()) { $err = 'OLAT ist nicht konfiguriert (Upload-Zentrale → Technik).'; return false; }

    $driveId = (string)$m['protocol_drive_id'] ?: (string)setting_get('graph_drive_id', '');
    $pdf = graph_download_file($driveId, (string)$m['protocol_item_id'], 'pdf', $err);
    if ($pdf === null) {
        if (str_contains((string)$err, 'HTTP 404')) {
            $err .= ' – Die hinterlegte Teams-Datei existiert nicht mehr (gelöscht oder durch eine neue Datei ersetzt; '
                . 'bloßes Umbenennen schadet nicht). In der Protokoll-Karte unter „Teams-Verknüpfung reparieren" '
                . 'die richtige Datei auswählen, dann klappt die Veröffentlichung.';
        }
        return false;
    }
    if (strlen($pdf) < 100) { $err = 'PDF-Konvertierung lieferte keine gültige Datei.'; return false; }

    $folder = protocol_olat_target_folder($m);
    $fname = protocol_pdf_filename($m);

    $tmp = tempnam(sys_get_temp_dir(), 'prot');
    if ($tmp === false || @file_put_contents($tmp, $pdf) === false) { if ($tmp) @unlink($tmp); $err = 'Temp-Datei fehlgeschlagen.'; return false; }
    $ok = olat_upload_file($folder, $fname, $tmp, $err);
    @unlink($tmp);
    if (!$ok) { automation_log_add('protocol_publish', $meetingId, 'error', (string)$err); return false; }

    db()->prepare("UPDATE meetings SET protocol_status = 'published', protocol_olat_path = ?, protocol_published_at = datetime('now','localtime'), protocol_published_by = ? WHERE id = ?")
       ->execute([($folder !== '' ? $folder . '/' : '') . $fname, $byMemberId ?: null, $meetingId]);
    automation_log_add('protocol_publish', $meetingId, 'success', $fname . ' → OLAT');
    return true;
}

/**
 * Sitzungen, für die dieses Mitglied als Protokollant:in eingeteilt ist und noch
 * kein fertiges Protokoll hochgeladen hat. Dashboard-Aufgabe „Protokoll hochladen".
 */
function member_protocol_upload_tasks(int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare(
        "SELECT * FROM meetings
         WHERE protocol_taker_id = ? AND cancelled = 0 AND draft = 0
           AND (protocol_status = '' OR protocol_status IS NULL)
         ORDER BY starts_at DESC"
    );
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** Angenommene, noch nicht veröffentlichte Protokolle (Sekretariats-Aufgabe „veröffentlichen"). */
function protocol_publish_pending(): array
{
    return db()->query("SELECT * FROM meetings WHERE protocol_status = 'approved' ORDER BY starts_at")->fetchAll();
}

/**
 * Vergangene (bzw. laufende) Sitzungen, in denen noch Abstimmungsgegenstände auf „offen" stehen.
 * Nach der Sitzung gehört zu jedem Gegenstand ein festgehaltener Beschluss (angenommen/vertagt) –
 * bei Protokoll-Gegenständen hängt daran die OLAT-Veröffentlichung. Gefüttert werden davon die
 * Dashboard-Karte „Beschlüsse festhalten" (Sekretariat/Vorsitz/Admin) und der Protokolle-Reiter.
 */
function meetings_with_open_vote_items(): array
{
    return db()->query(
        "SELECT m.*, COUNT(v.id) AS open_votes
         FROM meetings m JOIN vote_items v ON v.meeting_id = m.id AND v.decision = 'offen'
         WHERE m.cancelled = 0 AND m.draft = 0 AND m.kind != 'stupa'
           AND m.starts_at <= datetime('now','localtime')
         GROUP BY m.id ORDER BY m.starts_at DESC"
    )->fetchAll();
}

// ---------------------------------------------------------------------------
// Sicherheit: Escaping, CSRF, Admin-Login
// ---------------------------------------------------------------------------
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden und erneut versuchen.');
    }
}

function admin_password_set(): bool
{
    return setting_get('admin_password_hash') !== null;
}

// ---------------------------------------------------------------------------
// Halbjährliche Technik-Passwort-Kontrolle (Vorsitz)
// Der Technik-Login ist der Notfall-Zugang, wenn sonst nichts mehr geht – also die letzte
// Bastion. Diese Kontrolle sorgt dafür, dass der Vorsitz ihn nicht still verliert: Alle 6
// Monate erscheint auf dem Dashboard des Vorsitzes die Aufgabe, das Passwort einmal
// einzugeben. Kein neues Geheimnis, keine neue Zugriffsmöglichkeit – nur ein Nachweis,
// dass es noch bekannt ist. Wird das Passwort geändert, ist sofort wieder eine Kontrolle
// fällig (sonst hätte der Vorsitz noch das alte im Kopf).
// ---------------------------------------------------------------------------
const ADMIN_PW_CHECK_MONTHS = 6;

/** Datum der letzten bestandenen Kontrolle ('' = noch nie). */
function admin_pw_check_last(): string
{
    return trim((string)setting_get('admin_pw_check_last', ''));
}

/** Fällig ab diesem Datum ('' = sofort, weil noch nie bestätigt). */
function admin_pw_check_due_on(): string
{
    $last = admin_pw_check_last();
    if ($last === '') return '';
    $ts = strtotime($last . ' +' . ADMIN_PW_CHECK_MONTHS . ' months');
    return $ts === false ? '' : date('Y-m-d', $ts);
}

/** Ist die Kontrolle gerade fällig? (Nur für den Vorsitz und nur, wenn es ein Passwort gibt.) */
function admin_pw_check_pending(): bool
{
    if (current_role() !== 'vorsitz' || !admin_password_set()) return false;
    $due = admin_pw_check_due_on();
    return $due === '' || date('Y-m-d') >= $due;
}

/** Zu viele Fehlversuche in dieser Sitzung? Dann ist das Feld kurz gesperrt. */
function admin_pw_check_locked(): bool
{
    return (int)($_SESSION['pwcheck_until'] ?? 0) > time();
}

/**
 * Eingabe prüfen. Richtig → Datum merken, Zähler zurücksetzen, true.
 * Falsch → Versuch protokollieren (Fehler-Log = Prüfspur für die Technik); nach 5 Versuchen
 * ist das Feld 15 Minuten gesperrt, damit hier niemand in Ruhe raten kann.
 */
function admin_pw_check_confirm(string $pw): bool
{
    if (admin_pw_check_locked()) return false;
    if ($pw !== '' && password_verify($pw, (string)setting_get('admin_password_hash'))) {
        setting_set('admin_pw_check_last', date('Y-m-d'));
        unset($_SESSION['pwcheck_fails'], $_SESSION['pwcheck_until']);
        return true;
    }
    $n = (int)($_SESSION['pwcheck_fails'] ?? 0) + 1;
    $_SESSION['pwcheck_fails'] = $n;
    $wer = current_member()['name'] ?? 'unbekannt';
    app_log_error('Technik-Passwort-Kontrolle: Fehlversuch ' . $n . ' von ' . $wer);
    if ($n >= 5) $_SESSION['pwcheck_until'] = time() + 900;
    return false;
}

/** Kontrolle sofort wieder fällig stellen (nach einer Passwort-Änderung). */
function admin_pw_check_reset(): void
{
    setting_set('admin_pw_check_last', '');
}

/**
 * Laufende Streak-Pause für den wegklickbaren Hinweis: Kennung, Symbol, Beschriftung.
 * key = '' heißt: keine Pause, also auch kein Hinweis und kein Symbol.
 *
 * Die Kennung enthält das Enddatum der Phase. Der Browser merkt sich damit, ob der Hinweis für
 * die AKTUELLE Pause weggeklickt wurde – eine neue vorlesungsfreie Zeit zeigt ihn wieder.
 */
function lb_chip_info(): array
{
    if (is_logged_in()) {
        if (in_lecture_break()) {
            return ['key' => 'lb:' . (lecture_break_until() ?: 'offen'), 'icon' => 'ti-snowflake', 'label' => 'Vorlesungsfreie Zeit'];
        }
        if (in_streak_grace()) {
            return ['key' => 'gr:' . lecture_break_grace_until(), 'icon' => 'ti-flame', 'label' => 'Streak-Schonfrist'];
        }
    }
    return ['key' => '', 'icon' => '', 'label' => ''];
}

/**
 * Der eingeklappte Hinweis als Knopf. Es gibt ihn ZWEIMAL im Dokument, weil er je nach
 * Bildschirmbreite woanders sitzt (CSS blendet den jeweils anderen aus):
 *   'bar'  – in der Titelleiste neben dem Design-Umschalter (ab 641 px)
 *   'hero' – oben rechts in der Begrüßungs-Karte (bis 640 px; nur auf dem Dashboard, wo es einen
 *            Hero gibt – in der flachen Handy-Titelleiste würde er schlicht nicht auffallen)
 * Rückgabe: leerer String, wenn gerade keine Pause läuft.
 */
function lb_chip_html(string $variant = 'bar'): string
{
    $lb = lb_chip_info();
    if ($lb['key'] === '') return '';
    return '<button type="button" class="lb-chip lb-chip-' . h($variant) . '"'
        . ' data-lb-key="' . h($lb['key']) . '" data-lb-home="' . h(base() . 'dashboard.php') . '"'
        . ' aria-label="' . h($lb['label']) . ': Hinweis wieder einblenden"'
        . ' title="' . h($lb['label']) . ' – klicken, um den Hinweis wieder einzublenden">'
        . '<i class="ti ' . h($lb['icon']) . '"></i></button>';
}

// --- Rollen & Anmeldestatus -------------------------------------------------
/** Owner = Technik-/Notfall-Login per gemeinsamem Verwaltungspasswort. */
function is_owner(): bool
{
    return !empty($_SESSION['is_owner']);
}

/** Aktuell eingeloggtes Mitglied (oder null). */
function current_member(): ?array
{
    $id = (int)($_SESSION['member_id'] ?? 0);
    $m = $id ? member_get($id) : null;
    // Aktivitäts-Woche fürs Basis-Score-Kriterium „App wöchentlich geöffnet" vermerken (1× pro Request)
    static $tracked = false;
    if ($m && !$tracked) { $tracked = true; activity_track($id); }
    return $m;
}

/** Osterdatum (Ostersonntag) eines Jahres als 'MM-TT' (Anonyme Gregorianische / Meeus-Formel, kein ext-calendar nötig). */
function easter_mmdd(int $y): string
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%02d-%02d', $month, $day);
}

/** Steht heute im ±3-Tage-Fenster um einen Feiertag? Gibt den zugehörigen Achievement-Code zurück ('' = keiner).
 *  Weihnachten 25.12, Halloween 31.10, Valentinstag 14.2, Ostern (beweglich). */
function holiday_skin_achievement(): string
{
    $y = (int)date('Y');
    $today = strtotime(date('Y-m-d'));
    if ($today === false) return '';
    $near = function (string $mmdd) use ($today, $y): bool {
        $d = strtotime($y . '-' . $mmdd);
        return $d !== false && abs($today - $d) <= 3 * 86400;
    };
    if ($near('12-25')) return 'weihnachten';
    if ($near('10-31')) return 'halloween';
    if ($near('02-14')) return 'valentin';
    if ($near(easter_mmdd($y))) return 'ostern';
    return '';
}

/** Vermerkt die aktuelle ISO-Woche als „App geöffnet" (idempotent, für den Basis-Score)
 *  sowie den heutigen Tag (tagesgenau, für die Opt-in-Inaktivitäts-Erinnerung). */
function activity_track(int $memberId): void
{
    if ($memberId <= 0) return;
    try {
        $today = date('Y-m-d');
        db()->prepare('INSERT OR IGNORE INTO activity_weeks(member_id, week) VALUES(?, ?)')
            ->execute([$memberId, date('o-\WW')]);
        // „1337": exakt um 13:37 Uhr in der App – muss jede Anfrage prüfen, nicht nur die erste des Tages
        if (date('H:i') === '13:37') achievement_unlock($memberId, 'leet');
        streak_maintain_break(); // Pausen-Status pflegen (1× pro Request)
        streak_grant_bonus_once(); // Bonus-Flammen für alle – läuft genau einmal, danach No-Op
        // Tages-Streak pflegen: gestern aktiv → +1, sonst Neustart bei 1. Pro Tag nur einmal.
        $st = db()->prepare('SELECT streak_current, streak_best, streak_last_day, night_run, night_last FROM members WHERE id = ?');
        $st->execute([$memberId]);
        $m = $st->fetch() ?: [];
        $last = trim((string)($m['streak_last_day'] ?? ''));
        if ($last === $today) return; // heute schon erfasst
        // Kulanz: Die Kette reißt erst nach 5 verpassten Tagen in Folge (streak_alive);
        // eine neue Flamme gibt es trotzdem nur an Tagen, an denen man wirklich reinschaut.
        // In der vorlesungsfreien Zeit + Schonfrist wird GESAMMELT wie immer – nur reißen
        // kann die Kette dort nicht (streak_alive_for misst zusätzlich gegen den Pausenbeginn).
        $cur  = streak_alive_for($memberId, $last) ? (int)($m['streak_current'] ?? 0) + 1 : 1;
        $best = max((int)($m['streak_best'] ?? 0), $cur);
        // Auf den letzten Drücker: die Kette lebt noch, aber der letzte Streak-Tag ist exakt 5 Tage her
        if ($cur > 1 && $last !== '' && strtotime($last) !== false && !in_lecture_break() && !in_streak_grace()
            && (int)floor((strtotime($today) - strtotime($last)) / 86400) === 5) {
            achievement_unlock($memberId, 'streak_saved');
        }
        db()->prepare('UPDATE members SET last_active_on = ?, streak_current = ?, streak_best = ?, streak_last_day = ? WHERE id = ?')
            ->execute([$today, $cur, $best, $today, $memberId]);
        // Neue Flamme → Einmal-Animation beim Seitenaufbau vormerken (nur fürs eigene Login)
        if ((int)($_SESSION['member_id'] ?? 0) === $memberId) streak_queue_gain($cur);
        // Geheime Tageszeit-/Kalender-Achievements (nur beim ersten Öffnen des Tages geprüft)
        $h = (int)date('G');
        if ($h < 4)               achievement_unlock($memberId, 'night_owl');  // 00–03:59 Uhr
        if ($h >= 5 && $h < 7)    achievement_unlock($memberId, 'early_bird'); // 05–06:59 Uhr
        // Mondsüchtig: VIER Nächte hintereinander zwischen 0 und 5 Uhr. Anders als „Nachteule"
        // (einmal reicht) braucht das eine Kette, also zwei Spalten. Dass hier nur das ERSTE
        // Öffnen des Tages ankommt, ist genau richtig: Wer um 2 Uhr aufmacht, zählt für diesen
        // Kalendertag – wer um 23 Uhr aufmacht, für den davor. Das Fenster geht bis 5 Uhr und
        // schließt damit auch die Stunde, in der weder Nachteule noch Früher Vogel greifen.
        if ($h < 5) {
            $vor = trim((string)($m['night_last'] ?? ''));
            $lauf = ($vor !== '' && $vor === date('Y-m-d', strtotime($today . ' -1 day'))) ? (int)($m['night_run'] ?? 0) + 1 : 1;
            db()->prepare('UPDATE members SET night_run = ?, night_last = ? WHERE id = ?')->execute([$lauf, $today, $memberId]);
            if ($lauf >= 4) achievement_unlock($memberId, 'mondsucht');
        }
        if ((int)date('N') === 5 && (int)date('j') === 13) achievement_unlock($memberId, 'friday13'); // Freitag, der 13.
        $md = date('m-d');
        if ($md === '01-01') achievement_unlock($memberId, 'neujahr');  // Neujahr
        if ($md === '04-01') achievement_unlock($memberId, 'april1');   // 1. April
        if ($md === '12-06') achievement_unlock($memberId, 'nikolaus'); // Nikolaus
        // Feiertagsskins: ±3-Tage-Fenster um Weihnachten/Halloween/Ostern/Valentinstag → Skin dauerhaft frei (+ auto_apply einmal)
        if (($holiday = holiday_skin_achievement()) !== '') achievement_unlock($memberId, $holiday);
    } catch (\Throwable $e) { /* Tracking darf nie eine Seite brechen */ }
}

// ---------------------------------------------------------------------------
// Achievements / Gamification: Katalog, Freischaltung, Streak, Belohnungen
// ---------------------------------------------------------------------------

/** Katalog aller Achievements (Code => Definition). Kriterien werden in achievements_evaluate() geprüft. */
function achievements_catalog(): array
{
    return [
        'first_open'   => ['title' => 'Willkommen an Bord', 'desc' => 'Die App zum ersten Mal geöffnet.',            'icon' => 'ti-door-enter',      'tier' => 'bronze'],
        'first_shift'  => ['title' => 'Erste Schicht',       'desc' => 'Zur ersten Schicht eingeteilt worden.',        'icon' => 'ti-clipboard-check', 'tier' => 'bronze'],
        'first_report' => ['title' => 'Zu Protokoll',        'desc' => 'Deinen ersten Referats-Bericht abgegeben.',    'icon' => 'ti-file-pencil',     'tier' => 'bronze'],
        'streak_7'     => ['title' => 'Woche am Ball',        'desc' => 'Eine Streak von 7 Flammen erreicht.',                       'icon' => 'ti-flame',           'tier' => 'silber',  'reward' => ['type' => 'deco', 'key' => 'partyhut', 'label' => 'Partyhut 🎉']],
        'shifts_10'    => ['title' => 'Zehn im Dienst',       'desc' => '10 Schichten übernommen.',                     'icon' => 'ti-hand-grab',       'tier' => 'silber'],
        'shifts_25'    => ['title' => 'Verlässliche Größe',   'desc' => '25 Schichten übernommen.',                     'icon' => 'ti-checklist',       'tier' => 'silber'],
        'self_5'       => ['title' => 'Freiwillig vorn',      'desc' => '5 Schichten selbst gegriffen, statt eingeteilt zu werden.', 'icon' => 'ti-rocket',  'tier' => 'silber'],
        'gt_5'         => ['title' => 'Gesellig',             'desc' => 'Bei 5 Get-Togethers zugesagt.',                'icon' => 'ti-confetti',        'tier' => 'silber',  'reward' => ['type' => 'deco', 'key' => 'sunglasses', 'label' => 'Sonnenbrille 😎']],
        // Serie „App-Öffnungen an EINEM Tag" (neue Öffnung = erster Aufruf nach 30+ Minuten Pause; vergeben in activity_track)
        'opens_3'      => ['title' => 'Stammgast',            'desc' => 'An einem Tag 3-mal in die App geschaut. Einmal ist Zufall, zweimal ist Gewohnheit – dreimal ist Zuneigung.', 'icon' => 'ti-repeat', 'tier' => 'bronze'],
        'opens_10'     => ['title' => 'Praktisch eingezogen', 'desc' => '10-mal an einem Tag reingeschaut. In der Bahn, in der Schlange, zwischen zwei Vorlesungen – wir urteilen nicht, wir freuen uns. Und wer quasi hier wohnt, bekommt zum Einzug Lockenwickler.', 'icon' => 'ti-home-heart', 'tier' => 'silber', 'reward' => ['type' => 'deco', 'key' => 'curlers', 'label' => 'Lockenwickler 🧖']],
        'opens_20'     => ['title' => 'Touch Grass',          'desc' => 'An einem Tag 20-mal in die App geschaut. Manche würden jetzt sagen, du solltest mal rausgehen und Gras anfassen. Aber warum solltest du? Dein Avatar sitzt ab sofort die ganze Zeit auf Gras. Den Hatern hast du es gezeigt. Und weil du quasi auf Koffein läufst, kann deine Streak jetzt als dampfender Kaffee brennen – umschaltbar im Belohnungs-Locker. ☕', 'icon' => 'ti-plant', 'tier' => 'gold', 'reward' => ['type' => 'deco', 'key' => 'gras', 'label' => 'Gras unterm Avatar 🌱']],
        'infos_20'     => ['title' => 'Auf dem Laufenden',    'desc' => '20 wichtige Infos gelesen.',      'icon' => 'ti-news',            'tier' => 'silber',  'reward' => ['type' => 'deco', 'key' => 'glasses', 'label' => 'Brille 🤓']],
        'responses_25' => ['title' => 'Meldet sich immer',    'desc' => 'Auf 25 Verfügbarkeits-Abfragen geantwortet.',  'icon' => 'ti-mailbox',         'tier' => 'silber'],
        'active_26w'   => ['title' => 'Halbjahres-Held',      'desc' => 'In 26 verschiedenen Wochen aktiv gewesen.',    'icon' => 'ti-calendar-stats',  'tier' => 'gold',    'reward' => ['type' => 'deco', 'key' => 'gradcap', 'label' => 'Doktorhut 🎓']],
        'weekend_5'    => ['title' => 'Wochenend-Held:in',    'desc' => '5 Wochenend-Schichten (Sa/So) übernommen.',    'icon' => 'ti-beach',           'tier' => 'gold'],
        'rescue_3'     => ['title' => 'Börsen-Retter:in',     'desc' => '3 Schichten aus der Schichtbörse übernommen und damit jemanden gerettet.', 'icon' => 'ti-lifebuoy', 'tier' => 'gold', 'reward' => ['type' => 'deco', 'key' => 'cape', 'label' => 'Helden-Umhang 🦸']],
        'reports_10'   => ['title' => 'Chronist',             'desc' => '10 Referats-Berichte abgegeben.',              'icon' => 'ti-books',           'tier' => 'gold'],
        'reports_20'   => ['title' => 'Geschichtsschreiber',  'desc' => '20 Referats-Berichte abgegeben.',              'icon' => 'ti-book-2',          'tier' => 'gold'],
        'streak_30'    => ['title' => 'Durchhalter',          'desc' => 'Eine Streak von 30 Flammen erreicht.',                      'icon' => 'ti-flame',           'tier' => 'gold',    'reward' => ['type' => 'deco', 'key' => 'wizardhut', 'label' => 'Zaubererhut 🧙']],
        'shifts_35'    => ['title' => 'Rückgrat des AStA',    'desc' => '35 Schichten übernommen.',                     'icon' => 'ti-barbell',         'tier' => 'gold',    'reward' => ['type' => 'deco', 'key' => 'helmet', 'label' => 'Bauhelm ⛑️']],
        'top3_shifts'  => ['title' => 'Tragende Säule',       'desc' => 'Aktuell in den Top 3 der übernommenen Schichten (ab 5 Schichten). Rutschst du raus, geht der Status – samt Medaille – wieder verloren.', 'icon' => 'ti-medal', 'tier' => 'gold', 'dynamic' => true, 'reward' => ['type' => 'deco', 'key' => 'medal', 'label' => 'Medaille 🥇']],
        'spitze'       => ['title' => 'Spitzenklasse',        'desc' => 'Aktuell im höchsten Score-Band – schaltet Kronen-Schmuck & das Royal-Design frei und lässt deine Streak als „Stern" funkeln (im Belohnungs-Locker umschaltbar). Sinkt der Score wieder, geht der Status verloren.', 'icon' => 'ti-crown', 'tier' => 'legende', 'dynamic' => true, 'auto_apply' => true, 'reward' => ['type' => 'deco', 'key' => 'crown', 'label' => 'Krone 👑 + Royal-Design']],
        'streak_50'    => ['title' => 'Unaufhaltsam',         'desc' => 'Eine Streak von 50 Flammen erreicht. Ab jetzt steht deine Serie unter Hochspannung – der Streak-Stil „Blitz" ist frei, umschaltbar im Belohnungs-Locker. ⚡', 'icon' => 'ti-bolt',            'tier' => 'legende', 'reward' => ['type' => 'skin', 'key' => 'sunset', 'label' => 'App-Skin „Sonnenuntergang" 🌇']],
        // ACHTUNG beim Code: „streak_100" ist verbrannt – die Umbenennungs-Migration schreibt ihn
        // bei jedem DB-Aufbau auf „streak_50" um. Deshalb heißt dieser Code „hundert".
        'hundert'      => ['title' => 'Hundert Tage',        'desc' => '100 Tage Streak am Stück. Dein Stil steht damit auf der höchsten Stufe und strahlt aus seiner Kachel heraus – dazu gibt es den goldenen Lorbeerkranz. 🏛️', 'icon' => 'ti-award', 'tier' => 'legende', 'reward' => ['type' => 'deco', 'key' => 'lorbeer', 'label' => 'Lorbeerkranz 🏛️']],
        'anstoss'      => ['title' => 'Anstoß',              'desc' => 'Drei Mal selbst einen Umlauf oder einen Terminfinder gestartet. Mitmachen bringt hier sonst alles – das hier ist für die, die die Sache überhaupt erst aufsetzen.', 'icon' => 'ti-flag', 'tier' => 'silber', 'reward' => ['type' => 'deco', 'key' => 'klemmbrett', 'label' => 'Klemmbrett 📋']],
        'erste_reihe'  => ['title' => 'Erste Reihe',         'desc' => 'Bei einer Abstimmung oder einem Umlauf als Allererste:r abgestimmt – schaltet die Avatar-Farbe „Morgenrot" frei. 🌄', 'icon' => 'ti-hand-click', 'tier' => 'silber', 'hidden' => true],
        'mondsucht'    => ['title' => 'Mondsüchtig',         'desc' => 'Vier Nächte hintereinander zwischen Mitternacht und 5 Uhr in der App gewesen – schaltet den Streak-Stil „Mondphasen" frei. 🌙', 'icon' => 'ti-moon', 'tier' => 'gold', 'hidden' => true],
        'shifts_60'    => ['title' => 'Sechzig im Einsatz',   'desc' => '60 Schichten übernommen. Respekt.',           'icon' => 'ti-tornado',         'tier' => 'legende', 'reward' => ['type' => 'deco', 'key' => 'trophy', 'label' => 'Pokal 🏆']],
        'active_52w'   => ['title' => 'Jahresringe',          'desc' => 'In 52 verschiedenen Wochen aktiv gewesen – ein ganzes Jahr AStA. Wer so lange dabei ist, darf etwas wachsen sehen: das App-Design „Blumenwiese" und der Streak-Stil „Sempervivium" sind frei – eine Blume, die du täglich gießt und die mit jeder Stufe weiter aufgeht (umschaltbar im Belohnungs-Locker). 🌼', 'icon' => 'ti-trees', 'tier' => 'legende', 'reward' => ['type' => 'skin', 'key' => 'blumen', 'label' => 'App-Design „Blumenwiese" 🌼'], 'rewards' => [['type' => 'deco', 'key' => 'scarf', 'label' => 'Schal 🧣']]],
        'top_1'        => ['title' => 'Tagesordnungs-Punkt',  'desc' => 'Deinen ersten TOP für eine Sitzung eingereicht.', 'icon' => 'ti-list-details', 'tier' => 'bronze'],
        'gt_1'         => ['title' => 'Herz der Gruppe',      'desc' => 'Bei einem Get-Together zugesagt – schaltet die Avatar-Farbe „Cuteness" frei.', 'icon' => 'ti-heart-handshake', 'tier' => 'bronze'],
        'expense_1'    => ['title' => 'Spesenritter:in',      'desc' => 'Dein erstes Belegblatt für Auslagen eingereicht.', 'icon' => 'ti-receipt', 'tier' => 'bronze'],
        'meetings_10'  => ['title' => 'Sitzungsprofi',        'desc' => 'Bei 10 Sitzungen dabei gewesen (seit deinem Eintritt, ohne Abmeldung oder Fehlen).', 'icon' => 'ti-armchair', 'tier' => 'silber'],
        'votes_20'     => ['title' => 'Demokratie-Fan',       'desc' => '20 Abstimmungs-Vorlagen gelesen.',             'icon' => 'ti-checkbox',        'tier' => 'silber'],
        'umlauf_2'     => ['title' => 'Umlauf-Gewissen',      'desc' => 'An 2 Umlauf-Abstimmungen teilgenommen. Umläufe sind selten – kein Beschluss geht ohne dich durch.', 'icon' => 'ti-mail-forward', 'tier' => 'silber', 'reward' => ['type' => 'deco', 'key' => 'stamp', 'label' => 'Stempel 📮']],
        'polls_4'      => ['title' => 'Terminjäger:in',       'desc' => 'Bei 4 Terminfindern abgestimmt. Du triffst jeden Termin.', 'icon' => 'ti-target-arrow', 'tier' => 'silber', 'reward' => ['type' => 'deco', 'key' => 'dart', 'label' => 'Dartpfeil 🎯']],
        'expense_5'    => ['title' => 'Kreditgeber:in',       'desc' => '5 Belegblätter eingereicht – so viel ausgelegt, das ist quasi ein Kredit ans Referat.', 'icon' => 'ti-cash', 'tier' => 'silber', 'reward' => ['type' => 'deco', 'key' => 'monocle', 'label' => 'Monokel 🧐']],
        'meetings_20'  => ['title' => 'Sitzungs-Urgestein',   'desc' => 'Bei 20 Sitzungen dabei gewesen.',              'icon' => 'ti-award',           'tier' => 'gold',    'reward' => ['type' => 'deco', 'key' => 'bowtie', 'label' => 'Fliege 🎀']],
        'top_10'       => ['title' => 'Agenda-Architekt:in',  'desc' => '10 TOPs für Sitzungen eingereicht.',           'icon' => 'ti-layout-list',     'tier' => 'gold'],
        'responses_50' => ['title' => 'Immer erreichbar',     'desc' => 'Auf 50 Verfügbarkeits-Abfragen geantwortet.', 'icon' => 'ti-phone-call',      'tier' => 'gold',    'reward' => ['type' => 'deco', 'key' => 'headset', 'label' => 'Headset 🎧']],
        'self_15'      => ['title' => 'Macher:in',            'desc' => '15 Schichten selbst gegriffen, statt eingeteilt zu werden.', 'icon' => 'ti-run', 'tier' => 'gold', 'reward' => ['type' => 'deco', 'key' => 'wrench', 'label' => 'Schraubenschlüssel 🔧']],
        'weekend_15'   => ['title' => 'Wochenend-Legende',    'desc' => '15 Wochenend-Schichten (Sa/So) übernommen.',   'icon' => 'ti-umbrella',        'tier' => 'legende', 'reward' => ['type' => 'deco', 'key' => 'cocktail', 'label' => 'Cocktail 🍹']],
        'rescue_10'    => ['title' => 'Rettungsdienst',       'desc' => '10 Schichten aus der Schichtbörse übernommen – schaltet die Avatar-Farbe „Lava" frei.', 'icon' => 'ti-ambulance', 'tier' => 'legende'],
        'reports_30'   => ['title' => 'Berichte-Bibliothek',  'desc' => '30 Referats-Berichte abgegeben.',              'icon' => 'ti-feather',         'tier' => 'legende', 'reward' => ['type' => 'deco', 'key' => 'quill', 'label' => 'Schreibfeder 🪶']],
        'sommerkoenig' => ['title' => 'König:in des Sommers', 'desc' => 'Als Orga ein Event mit über 400 Schichtstunden ausgerichtet – schaltet Blumenkranz, aufgehende Sonne und das App-Design „Hochsommer" frei. Der Skin wird einmalig automatisch angewandt, und du darfst zwei Helfer:innen den Sommer schenken.', 'icon' => 'ti-sun', 'tier' => 'legende', 'auto_apply' => true, 'reward' => ['type' => 'skin', 'key' => 'sommer', 'label' => 'App-Design „Hochsommer" ☀️'], 'rewards' => [['type' => 'deco', 'key' => 'flowercrown', 'label' => 'Blumenkranz 🌼'], ['type' => 'deco', 'key' => 'sunrise', 'label' => 'Aufgehende Sonne 🌅']]],
        // Pinnwand: nette Worte auf FREMDEN Profilen (Einträge + Antworten; das eigene Profil zählt nicht)
        'pin_1'        => ['title' => 'Liebe Grüße',          'desc' => 'Den ersten netten Eintrag auf der Pinnwand einer anderen Person hinterlassen.', 'icon' => 'ti-message-2-heart', 'tier' => 'bronze'],
        'pin_10'       => ['title' => 'Pinnwand-Poet:in',     'desc' => '10 nette Einträge auf fremden Pinnwänden hinterlassen.', 'icon' => 'ti-messages',        'tier' => 'silber'],
        'pin_spread'   => ['title' => 'Streut Freude',        'desc' => 'Auf den Pinnwänden von 5 verschiedenen Personen etwas Nettes hinterlassen.', 'icon' => 'ti-heart-handshake', 'tier' => 'gold'],
        'tour_done'    => ['title' => 'Einmal alles gesehen', 'desc' => 'Den geführten Rundgang komplett bis zum letzten Schritt durchgeklickt – du weißt jetzt, wo alles liegt.', 'icon' => 'ti-map-2', 'tier' => 'bronze', 'reward' => ['type' => 'deco', 'key' => 'compass', 'label' => 'Kompass 🧭']],
        'profile_done' => ['title' => 'Steckbrief komplett',  'desc' => '„Über mich" und die Referatsbeschreibung im eigenen Profil ausgefüllt – jetzt wissen alle, wer du bist.', 'icon' => 'ti-id-badge-2', 'tier' => 'bronze', 'reward' => ['type' => 'deco', 'key' => 'nametag', 'label' => 'Namensschild 🪪']],
        // Schaltet den Pride-Skin frei. Mitglieder aus der Zeit, als er Grundausstattung war,
        // behalten ihn per Besitzstand (member_reward_skins()).
        'pronomen'     => ['title' => 'Gute Ansprache',       'desc' => 'Deine Pronomen im Profil eingetragen – so wissen alle, wie sie von dir sprechen. Schaltet das App-Design „Pride" frei. 🏳️‍🌈', 'icon' => 'ti-rainbow', 'tier' => 'bronze', 'reward' => ['type' => 'skin', 'key' => 'pride', 'label' => 'App-Design „Pride" 🏳️‍🌈']],
        // Geheim: erst nach dem Freischalten sichtbar (vorher „???")
        'early_bird'   => ['title' => 'Früher Vogel',         'desc' => 'Zwischen 5 und 7 Uhr morgens aktiv gewesen.',  'icon' => 'ti-sunrise',         'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'coffee', 'label' => 'Kaffeebecher ☕']],
        'night_owl'    => ['title' => 'Nachteule',            'desc' => 'Nach Mitternacht (bis 4 Uhr) noch aktiv.',     'icon' => 'ti-moon-stars',      'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'skin', 'key' => 'trueblack', 'label' => 'App-Design „TrueBlack" 🖤']],
        'flashbang'    => ['title' => 'Flashbang',            'desc' => 'Zwischen Mitternacht und 4 Uhr in den hellen Modus geschaltet. Autsch, die Augen!', 'icon' => 'ti-bulb', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'lantern', 'label' => 'Laterne 🏮']],
        // Technik-Engel: Wer einen gemeldeten Fehler abhakt, hat etwas repariert, das andere
        // aufgehalten hat. Vergeben wird beim Umschalten auf „erledigt" in der Fehler-Verwaltung –
        // an die Person, die umschaltet, nicht an die meldende. Der Schmuck „halo" kommt hier ein
        // ZWEITES Mal vor – er hängt auch am geheimen Erfolg „Hutsammlung". Das ist gewollt und
        // unproblematisch: member_rewards_of_type() sammelt Schlüssel => Label über alle erreichten
        // Erfolge, zwei Quellen fallen dort einfach zusammen.
        'technik_engel' => ['title' => 'Technik-Engel',       'desc' => 'Einen gemeldeten Fehler als erledigt abgehakt – du hast repariert, was andere aufgehalten hat. Schaltet die Avatar-Farbe „Engel" und den Streak-Stil „Heiligenschein" und den Schmuck gleichen Namens frei. 😇', 'icon' => 'ti-bug-off', 'tier' => 'gold', 'reward' => ['type' => 'palette', 'key' => 'engel', 'label' => 'Avatar-Farbe „Engel" 😇'], 'rewards' => [['type' => 'flame', 'key' => 'schein', 'label' => 'Streak-Stil „Heiligenschein" 😇'], ['type' => 'deco', 'key' => 'halo', 'label' => 'Heiligenschein 😇']]],
        'hat_trick'    => ['title' => 'Hutsammlung',          'desc' => 'Alle vier Sammel-Hüte verdient: Partyhut, Doktorhut, Zaubererhut und Bauhelm.', 'icon' => 'ti-hanger', 'tier' => 'gold', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'halo', 'label' => 'Heiligenschein 😇']],
        'friday13'     => ['title' => 'Freitag der 13.',      'desc' => 'An einem Freitag, dem 13. furchtlos die App geöffnet – schaltet die Spinne und das App-Design „Gothik" frei.', 'icon' => 'ti-spider', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'skin', 'key' => 'gothic', 'label' => 'App-Design „Gothik" 🦇'], 'rewards' => [['type' => 'deco', 'key' => 'spider', 'label' => 'Spinne 🕷️']]],
        'leet'         => ['title' => '1337',                 'desc' => 'Um exakt 13:37 Uhr in der App gewesen. Elite!', 'icon' => 'ti-keyboard', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'propeller', 'label' => 'Propellerhut 🚁']],
        'neujahr'      => ['title' => 'Guten Rutsch',         'desc' => 'Am 1. Januar in die App geschaut – frohes neues Jahr!', 'icon' => 'ti-sparkles', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'sparkler', 'label' => 'Wunderkerze ✨']],
        'nikolaus'     => ['title' => 'Nikolaus',             'desc' => 'Am 6. Dezember die App geöffnet – die Stiefel stehen bereit.', 'icon' => 'ti-gift', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'santahat', 'label' => 'Nikolausmütze 🎅']],
        'april1'       => ['title' => 'Kein Scherz',          'desc' => 'Am 1. April eingeloggt. Diese Auszeichnung ist echt. Wirklich.', 'icon' => 'ti-masks-theater', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'jesterhat', 'label' => 'Narrenkappe 🃏']],
        // Feiertagsskins: reinschauen im ±3-Tage-Fenster um den Feiertag → Skin dauerhaft frei, einmalig automatisch aktiv
        'weihnachten'  => ['title' => 'Fest der Feste',       'desc' => 'In den Tagen um Weihnachten (±3) in die App geschaut – schaltet das App-Design „Weihnachten" 🎄 frei.', 'icon' => 'ti-christmas-tree', 'tier' => 'silber', 'hidden' => true, 'auto_apply' => true, 'reward' => ['type' => 'skin', 'key' => 'weihnacht', 'label' => 'App-Design „Weihnachten" 🎄']],
        'halloween'    => ['title' => 'Süßes oder Saures',    'desc' => 'Rund um Halloween (±3 Tage) mutig reingeschaut – schaltet das App-Design „Halloween" 🎃 frei.', 'icon' => 'ti-pumpkin-scary', 'tier' => 'silber', 'hidden' => true, 'auto_apply' => true, 'reward' => ['type' => 'skin', 'key' => 'halloween', 'label' => 'App-Design „Halloween" 🎃']],
        'ostern'       => ['title' => 'Osterhase',            'desc' => 'In den Tagen um Ostern (±3) vorbeigehoppelt – schaltet das App-Design „Ostern" 🐣 frei.', 'icon' => 'ti-egg', 'tier' => 'silber', 'hidden' => true, 'auto_apply' => true, 'reward' => ['type' => 'skin', 'key' => 'ostern', 'label' => 'App-Design „Ostern" 🐣']],
        'valentin'     => ['title' => 'Herzensangelegenheit',  'desc' => 'Um den Valentinstag (±3 Tage) reingeschaut – schaltet das App-Design „Valentinstag" 💐 frei.', 'icon' => 'ti-flower-filled', 'tier' => 'silber', 'hidden' => true, 'auto_apply' => true, 'reward' => ['type' => 'skin', 'key' => 'valentin', 'label' => 'App-Design „Valentinstag" 💐']],
        'allround'     => ['title' => 'Rund um die Uhr',      'desc' => 'Früher Vogel und Nachteule in einer Person – beide Tageszeit-Erfolge geholt.', 'icon' => 'ti-yin-yang', 'tier' => 'gold', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'sleepmask', 'label' => 'Schlafmaske 😴']],
        'streak_saved' => ['title' => 'Auf den letzten Drücker', 'desc' => 'Die Streak am allerletzten Kulanztag gerettet. Puh, das war knapp!', 'icon' => 'ti-alarm', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'lifebuoy', 'label' => 'Rettungsring 🛟']],
        'double_shift' => ['title' => 'Doppelschicht',        'desc' => 'Zwei Schichten an einem einzigen Tag übernommen.', 'icon' => 'ti-stack-2', 'tier' => 'silber', 'hidden' => true],
        'marathon'     => ['title' => 'Marathon',             'desc' => 'Eine Schicht von 6 oder mehr Stunden durchgezogen.', 'icon' => 'ti-road', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'sweatband', 'label' => 'Stirnband 💦']],
        'lastminute'   => ['title' => 'Last-Minute-Held:in',  'desc' => 'Eine Börsen-Schicht weniger als 24 Stunden vor Beginn übernommen.', 'icon' => 'ti-hourglass', 'tier' => 'gold', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'stopwatch', 'label' => 'Stoppuhr ⏱️']],
        'fair_trade'   => ['title' => 'Fairer Tausch',        'desc' => 'Eine eigene Schicht in die Börse gegeben UND eine fremde übernommen.', 'icon' => 'ti-arrows-exchange', 'tier' => 'gold', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'scale', 'label' => 'Waage ⚖️']],
        'phoenix'      => ['title' => 'Phönix',               'desc' => 'Eine Streak von 30+ ist gerissen – und du hast dich wieder auf 7 hochgekämpft.', 'icon' => 'ti-flame', 'tier' => 'gold', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'firefeather', 'label' => 'Phönix-Feder 🔥']],
        'chameleon'    => ['title' => 'Chamäleon',            'desc' => 'Alle verfügbaren App-Designs in einer Sitzung durchprobiert.', 'icon' => 'ti-palette', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'lizard', 'label' => 'Chamäleon 🦎']],
        'binge_reader' => ['title' => 'Binge-Reader',         'desc' => '5 wichtige Infos an einem einzigen Tag gelesen.', 'icon' => 'ti-book', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'bookstack', 'label' => 'Bücherstapel 📚']],
        'pin_reply_5'  => ['title' => 'Schlagfertig',         'desc' => 'Auf 5 Pinnwand-Kommentare geantwortet. Du lässt niemanden auf Read.', 'icon' => 'ti-message-reply', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'speechbubble', 'label' => 'Sprechblase 💬']],
        'famous'       => ['title' => 'Famous',               'desc' => 'Du hast von jemandem Props bekommen 🙌 – deine Arbeit wird gesehen. Zur Feier gibt es die Star-Sonnenbrille.', 'icon' => 'ti-star', 'tier' => 'gold', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'starshades', 'label' => 'Star-Sonnenbrille ⭐']],
        // --- Protokollführung ---------------------------------------------------------------------
        'protocols_5'  => ['title' => 'Protokollant:in',      'desc' => 'Bei 5 Sitzungen das Protokoll geführt und hochgeladen. Wer mitschreibt, hält fest, was sonst niemand mehr weiß.', 'icon' => 'ti-writing', 'tier' => 'silber'],
        'protocols_10' => ['title' => 'Gedächtnis des AStA',  'desc' => '10 Protokolle geführt und hochgeladen – schaltet die Avatar-Farbe „Tiefsee" frei. 🫧', 'icon' => 'ti-books', 'tier' => 'gold'],
        // --- Props --------------------------------------------------------------------------------
        'kudos_10'     => ['title' => 'Gute Seele',           'desc' => '10-mal Props bekommen 🙌 – so viele Menschen haben sich bei dir bedankt. Schaltet die legendäre Avatar-Farbe „Prisma" frei. 🌈', 'icon' => 'ti-heart-handshake', 'tier' => 'legende'],
        'kudos_giver_3' => ['title' => 'Großzügig',           'desc' => 'Drei Monate hintereinander dein volles Props-Kontingent verschenkt. Schaltet die Avatar-Farbe „Pusteblume" frei – die Schirmchen fliegen davon, und man sieht nie, wo sie landen. 🌼', 'icon' => 'ti-gift', 'tier' => 'gold'],
        // --- Sitzungen & Berichte ------------------------------------------------------------------
        'meetings_full' => ['title' => 'Vollversammlung',     'desc' => 'Ein ganzes Quartal keine einzige Sitzung verpasst (bei mindestens drei Sitzungen). Schaltet die legendäre Avatar-Farbe „Nordlicht" frei. 🌠', 'icon' => 'ti-calendar-check', 'tier' => 'legende'],
        'early_report_5' => ['title' => 'Ausgeschlafen',      'desc' => '5-mal den Bericht mindestens 3 Tage vor der Sitzung abgegeben – schaltet die Avatar-Farbe „Frost" frei. ❄️', 'icon' => 'ti-alarm', 'tier' => 'gold'],
        'night_protocol' => ['title' => 'Nachtschicht',       'desc' => 'Ein Protokoll nach 23 Uhr hochgeladen. Irgendwer muss es ja machen – schaltet die Avatar-Farbe „Neon" frei. 🕶️', 'icon' => 'ti-moon-stars', 'tier' => 'silber', 'hidden' => true],
        'bug_fixed'    => ['title' => 'Käferjäger:in',        'desc' => 'Einen Bug gemeldet, der behoben wurde. Danke für den Scharfblick!', 'icon' => 'ti-bug', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'deco', 'key' => 'magnifier', 'label' => 'Lupe 🔍']],
        'urlauber'     => ['title' => 'Urlauber:in',          'desc' => 'Zwei Wochen (oder mehr) Abwesenheit am Stück eingetragen. Gönn dir – wir halten die Stellung! Schaltet das App-Design „Abend am Strand" frei.', 'icon' => 'ti-luggage', 'tier' => 'silber', 'hidden' => true, 'reward' => ['type' => 'skin', 'key' => 'strand', 'label' => 'App-Design „Abend am Strand" 🏝️']],
        'prinz'        => ['title' => 'Prinzessin/Prinz',     'desc' => 'Mehr als 4-mal in einer Minute den Avatar-Schmuck oder die Farbe gewechselt – dein Avatar verdient die volle Garderobe: Rosa Schleife, Diadem, die Avatar-Farbe „Glitzer" und das App-Design „Ein Traum in Pink".', 'icon' => 'ti-diamond', 'tier' => 'gold', 'hidden' => true, 'reward' => ['type' => 'skin', 'key' => 'pink', 'label' => 'App-Design „Ein Traum in Pink" 💗'], 'rewards' => [['type' => 'deco', 'key' => 'pinkbow', 'label' => 'Rosa Schleife 🎀'], ['type' => 'deco', 'key' => 'tiara', 'label' => 'Diadem 👑']]],
        'pinkdream'    => ['title' => 'Pretty in Pink',       'desc' => 'Den Skin „Ein Traum in Pink", eine rosa Avatar-Farbe (Cuteness oder Glitzer) und passenden Kopfschmuck (Diadem, rosa Schleife, Lockenwickler oder Krone) gleichzeitig getragen. Zur Belohnung kann deine Streak-Flamme als Herz schlagen – umschaltbar im Belohnungs-Locker.', 'icon' => 'ti-heart', 'tier' => 'gold', 'hidden' => true],
    ];
}

/** Optik je Stufe (Rahmen/Label). */
function achievement_tier_meta(): array
{
    return [
        'bronze'  => ['label' => 'Bronze',   'color' => '#b8763a'],
        'silber'  => ['label' => 'Silber',   'color' => '#8c99a3'],
        'gold'    => ['label' => 'Gold',     'color' => '#d9971a'],
        'legende' => ['label' => 'Legendär', 'color' => '#8a4fd0'],
    ];
}

/** Avatar-Accessoire als Inline-SVG je Belohnungs-Key. Wird direkt am Avatar getragen/gehalten (siehe avatar_deco_slot). */
function avatar_deco_svg(string $key): string
{
    $svg = [
        // Kopfbedeckungen (sitzen oben auf dem Kopf)
        // Fünf Zacken mit abwechselnder Höhe, Perlen auf den hohen, Steinreihe im Reif. Bewusst
        // ANDERS als die Karten-Krone in mem_crown_svg() (drei Zacken, drei Steine) – dieselbe
        // Zeichnung zweimal wäre langweilig, zwei völlig fremde Stile passten nicht zusammen.
        // viewBox unverändert 100x62, damit die Krone weiter genauso auf dem Kopf sitzt.
        'crown'    => '<svg viewBox="0 0 100 62" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            . '<path d="M9 49 C 8.2 34, 9.4 24, 10.4 16.8 Q 12 13.4, 13.6 16.8 L20.4 32.6 Q 21.5 34.6, 22.6 32.6'
            .   ' L29.6 21.4 Q 31 19.2, 32.4 21.4 L39.4 32.6 Q 40.5 34.6, 41.6 32.6 L48.1 7.6 Q 50 4.2, 51.9 7.6'
            .   ' L58.4 32.6 Q 59.5 34.6, 60.6 32.6 L67.6 21.4 Q 69 19.2, 70.4 21.4 L77.4 32.6 Q 78.5 34.6, 79.6 32.6'
            .   ' L86.4 16.8 Q 88 13.4, 89.6 16.8 C 90.6 24, 91.8 34, 91 49 Z"'
            .   ' fill="#f4cd54" stroke="#c8901f" stroke-width="3.6" stroke-linejoin="round"/>'
            . '<path d="M15 44 C 14.4 32, 15 25, 15.6 20.4 L21 33" fill="none" stroke="#ffe9a3" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".7"/>'
            . '<g fill="#ffe9a3" stroke="#c8901f" stroke-width="2.6">'
            .   '<circle cx="12" cy="12.4" r="4.4"/><circle cx="50" cy="3.6" r="5.2"/><circle cx="88" cy="12.4" r="4.4"/>'
            . '</g>'
            . '<rect x="7" y="45" width="86" height="15" rx="5" fill="#e0a92e" stroke="#c8901f" stroke-width="3.2"/>'
            . '<rect x="12" y="48.6" width="76" height="2.6" rx="1.3" fill="#c8901f" opacity=".35"/>'
            . '<circle cx="50" cy="53" r="4.6" fill="#e0533d" stroke="#a9331f" stroke-width="1.8"/>'
            . '<g fill="#5f9fd8" stroke="#3d76a8" stroke-width="1.6">'
            .   '<circle cx="31" cy="53" r="3.2"/><circle cx="69" cy="53" r="3.2"/>'
            . '</g>'
            . '<g fill="#7bc47f" stroke="#3f8b46" stroke-width="1.4">'
            .   '<circle cx="16" cy="53" r="2.4"/><circle cx="84" cy="53" r="2.4"/>'
            . '</g>'
            . '</svg>',
        'partyhut' => '<svg viewBox="0 0 70 94" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M35 6 L60 86 L10 86 Z" fill="#8a4fd0" stroke="#5f2fa0" stroke-width="4" stroke-linejoin="round"/><circle cx="35" cy="7" r="7" fill="#f4cd54"/><circle cx="30" cy="42" r="4.5" fill="#fff"/><circle cx="43" cy="60" r="4.5" fill="#fff"/><circle cx="27" cy="73" r="4.5" fill="#fff"/></svg>',
        'wizardhut'=> '<svg viewBox="0 0 96 98" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M48 6 C 52 34, 62 64, 84 86 L12 86 C 34 64, 44 34, 48 6 Z" fill="#3b5bdb" stroke="#26408b" stroke-width="4" stroke-linejoin="round"/><rect x="4" y="82" width="88" height="13" rx="6" fill="#26408b"/><path d="M48 42 l4 9 10 1 -7 7 2 10 -9 -5 -9 5 2 -10 -7 -7 10 -1 Z" fill="#f4cd54"/></svg>',
        'gradcap'  => '<svg viewBox="0 0 100 74" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="32" y="40" width="36" height="22" rx="3" fill="#2b2f3a"/><path d="M50 18 L96 38 L50 58 L4 38 Z" fill="#3a3f4d" stroke="#20242e" stroke-width="3" stroke-linejoin="round"/><path d="M84 38 L84 60" stroke="#f4cd54" stroke-width="3" fill="none"/><circle cx="84" cy="63" r="5" fill="#f4cd54"/><circle cx="50" cy="38" r="4" fill="#f4cd54"/></svg>',
        'helmet'   => '<svg viewBox="0 0 100 60" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 50 Q8 12 50 12 Q92 12 92 50 Z" fill="#f2b705" stroke="#c8901f" stroke-width="4" stroke-linejoin="round"/><rect x="4" y="47" width="92" height="10" rx="4" fill="#e0a92e" stroke="#c8901f" stroke-width="2"/><rect x="44" y="14" width="12" height="36" rx="4" fill="#e0a92e"/></svg>',
        'jesterhat'=> '<svg viewBox="0 0 96 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M14 58 C 16 34, 10 20, 5 10 C 22 16, 32 28, 36 40 C 36 22, 42 10, 48 3 C 54 10, 60 22, 60 40 C 64 28, 74 16, 91 10 C 86 20, 80 34, 82 58 Z" fill="#8a4fd0" stroke="#5f2fa0" stroke-width="4" stroke-linejoin="round"/><rect x="10" y="54" width="76" height="12" rx="6" fill="#f4cd54" stroke="#c8901f" stroke-width="3"/><circle cx="6" cy="10" r="5" fill="#f4cd54" stroke="#c8901f" stroke-width="2.5"/><circle cx="48" cy="4" r="5" fill="#e0533d" stroke="#a33327" stroke-width="2.5"/><circle cx="90" cy="10" r="5" fill="#f4cd54" stroke="#c8901f" stroke-width="2.5"/></svg>',
        'lizard'   => '<svg viewBox="0 0 100 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20 36 C 18 20, 34 8, 54 10 C 70 12, 80 20, 82 30" fill="none" stroke="#43a047" stroke-width="13" stroke-linecap="round"/><path d="M20 36 a8 8 0 1 0 10 -7" fill="none" stroke="#43a047" stroke-width="7" stroke-linecap="round"/><circle cx="84" cy="30" r="10" fill="#43a047"/><circle cx="87" cy="27" r="4" fill="#fff"/><circle cx="87" cy="27" r="2" fill="#1b5e20"/><path d="M78 39 v6 M60 17 v-4" stroke="#2e7d32" stroke-width="4" stroke-linecap="round"/></svg>',
        'spider'   => '<svg viewBox="0 0 48 76" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M24 0 v26" stroke="#5c6a75" stroke-width="2.5"/><path d="M14 34 L2 26 M14 42 L2 44 M15 48 L6 58 M34 34 L46 26 M34 42 L46 44 M33 48 L42 58" stroke="#0e1118" stroke-width="3" stroke-linecap="round" fill="none"/><circle cx="24" cy="27" r="6" fill="#2b2f3a"/><circle cx="24" cy="42" r="11" fill="#2b2f3a" stroke="#0e1118" stroke-width="3"/><circle cx="21" cy="26" r="1.6" fill="#fff"/><circle cx="27" cy="26" r="1.6" fill="#fff"/></svg>',
        'headset'  => '<svg viewBox="0 0 96 72" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 52 C 12 20, 84 20, 84 52" fill="none" stroke="#26404a" stroke-width="8"/><rect x="4" y="40" width="16" height="26" rx="7" fill="#3b5bdb" stroke="#26408b" stroke-width="3.5"/><rect x="76" y="40" width="16" height="26" rx="7" fill="#3b5bdb" stroke="#26408b" stroke-width="3.5"/><path d="M20 64 q10 8 22 4" fill="none" stroke="#26404a" stroke-width="4" stroke-linecap="round"/><circle cx="44" cy="67" r="4.5" fill="#e0533d"/></svg>',
        'sleepmask'=> '<svg viewBox="0 0 92 44" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 14 Q 46 2, 88 14" fill="none" stroke="#26408b" stroke-width="4"/><path d="M14 14 C 26 8, 66 8, 78 14 C 86 18, 86 32, 76 36 C 64 40, 28 40, 16 36 C 6 32, 6 18, 14 14 Z" fill="#3b5bdb" stroke="#26408b" stroke-width="4" stroke-linejoin="round"/><path d="M30 20 h12 l-12 10 h12 M56 18 h9 l-9 8 h9" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'sweatband'=> '<svg viewBox="0 0 92 28" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="4" y="4" width="84" height="20" rx="10" fill="#e0533d" stroke="#a33327" stroke-width="4"/><path d="M14 14 h64" stroke="#fff" stroke-width="4" stroke-linecap="round"/></svg>',
        'curlers'  => '<svg viewBox="0 0 92 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g stroke="#d81b60" stroke-width="3"><rect x="6" y="12" width="24" height="16" rx="8" fill="#ff9ad5"/><rect x="34" y="6" width="24" height="16" rx="8" fill="#9ad0ff"/><rect x="62" y="12" width="24" height="16" rx="8" fill="#ff9ad5"/></g><g stroke="#fff" stroke-width="2.5" opacity=".85"><path d="M11 14 v12 M18 14 v12 M25 14 v12"/><path d="M39 8 v12 M46 8 v12 M53 8 v12"/><path d="M67 14 v12 M74 14 v12 M81 14 v12"/></g></svg>',
        'flowercrown' => '<svg viewBox="0 0 96 38" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 28 Q 48 8, 90 28" fill="none" stroke="#2e7d32" stroke-width="6" stroke-linecap="round"/><path d="M28 20 l7 -8 M62 16 l8 7" stroke="#2e7d32" stroke-width="4" stroke-linecap="round"/><g fill="#ff9a9e" stroke="#d81b60" stroke-width="2.5"><circle cx="18" cy="22" r="7"/><circle cx="48" cy="13" r="8"/><circle cx="78" cy="22" r="7"/></g><g fill="#f4cd54"><circle cx="18" cy="22" r="3"/><circle cx="48" cy="13" r="3.5"/><circle cx="78" cy="22" r="3"/></g></svg>',
        'tiara'    => '<svg viewBox="0 0 72 36" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 32 C 14 12, 58 12, 68 32" fill="none" stroke="#f4cd54" stroke-width="7" stroke-linecap="round"/><path d="M4 32 C 14 15, 58 15, 68 32" fill="none" stroke="#ffe9a8" stroke-width="2" opacity=".8"/><path d="M36 4 l4.5 8.5 -4.5 4 -4.5 -4 Z" fill="#ff9ad5" stroke="#d81b60" stroke-width="2" stroke-linejoin="round"/><circle cx="19" cy="20" r="3" fill="#ff9ad5" stroke="#d81b60" stroke-width="1.5"/><circle cx="53" cy="20" r="3" fill="#ff9ad5" stroke="#d81b60" stroke-width="1.5"/></svg>',
        'pinkbow'  => '<svg viewBox="0 0 76 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 8 C 2 12, 2 34, 12 38 C 22 34, 30 28, 34 24 C 30 20, 22 12, 12 8 Z" fill="#ff9ad5" stroke="#d81b60" stroke-width="3.5" stroke-linejoin="round"/><path d="M64 8 C 74 12, 74 34, 64 38 C 54 34, 46 28, 42 24 C 46 20, 54 12, 64 8 Z" fill="#ff9ad5" stroke="#d81b60" stroke-width="3.5" stroke-linejoin="round"/><circle cx="38" cy="23" r="8" fill="#ffc1e3" stroke="#d81b60" stroke-width="3"/></svg>',
        'santahat' => '<svg viewBox="0 0 96 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M14 52 C 18 18, 44 6, 66 12 C 84 17, 90 32, 88 44 L74 42 C 78 28, 70 20, 58 20 C 38 20, 28 34, 26 52 Z" fill="#d64545" stroke="#a32e2e" stroke-width="4" stroke-linejoin="round"/><rect x="8" y="48" width="72" height="14" rx="7" fill="#fff" stroke="#cfd6da" stroke-width="3"/><circle cx="88" cy="46" r="9" fill="#fff" stroke="#cfd6da" stroke-width="3"/></svg>',
        'propeller'=> '<svg viewBox="0 0 96 74" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20 64 Q20 32 48 32 Q76 32 76 64 Z" fill="#3b5bdb" stroke="#26408b" stroke-width="4" stroke-linejoin="round"/><path d="M48 32 v-10" stroke="#26408b" stroke-width="4"/><ellipse cx="27" cy="17" rx="18" ry="7" fill="#e0533d" stroke="#a33327" stroke-width="3"/><ellipse cx="69" cy="17" rx="18" ry="7" fill="#f4cd54" stroke="#c8901f" stroke-width="3"/><circle cx="48" cy="17" r="5.5" fill="#26408b"/></svg>',
        'halo'     => '<svg viewBox="0 0 88 26" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><ellipse cx="44" cy="13" rx="36" ry="9" fill="none" stroke="#f4cd54" stroke-width="7"/><ellipse cx="44" cy="11" rx="36" ry="9" fill="none" stroke="#fff3c2" stroke-width="2" opacity=".8"/></svg>',
        // Handobjekte
        'lantern'  => '<svg viewBox="0 0 44 66" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M22 4 a9 9 0 0 1 9 9" fill="none" stroke="#8a5a2b" stroke-width="3"/><rect x="9" y="13" width="26" height="6" rx="2" fill="#8a5a2b"/><rect x="11" y="19" width="22" height="30" rx="3" fill="#ffd75e" stroke="#8a5a2b" stroke-width="3"/><path d="M22 23 v22" stroke="#f2a900" stroke-width="3"/><rect x="8" y="47" width="28" height="7" rx="2" fill="#8a5a2b"/></svg>',
        'coffee'   => '<svg viewBox="0 0 56 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M19 5 c-3 5 3 7 0 12 M31 3 c-3 5 3 7 0 12" fill="none" stroke="#b9c2c9" stroke-width="3.5" stroke-linecap="round"/><path d="M40 32 h4 a7 7 0 0 1 0 14 h-4" fill="none" stroke="#a33327" stroke-width="4"/><rect x="8" y="24" width="32" height="32" rx="7" fill="#e0533d" stroke="#a33327" stroke-width="4"/><rect x="13" y="31" width="22" height="7" rx="3.5" fill="#f2988a"/></svg>',
        'trophy'   => '<svg viewBox="0 0 60 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M16 6 h28 v13 a14 14 0 0 1 -28 0 Z" fill="#f4cd54" stroke="#c8901f" stroke-width="4" stroke-linejoin="round"/><path d="M16 10 h-8 a9 9 0 0 0 10 11 M44 10 h8 a9 9 0 0 1 -10 11" fill="none" stroke="#c8901f" stroke-width="4"/><rect x="26" y="32" width="8" height="11" fill="#e0a92e"/><rect x="18" y="43" width="24" height="8" rx="2" fill="#c8901f"/><rect x="14" y="51" width="32" height="7" rx="2" fill="#e0a92e"/></svg>',
        'stopwatch'=> '<svg viewBox="0 0 56 66" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="22" y="2" width="12" height="8" rx="2" fill="#26404a"/><path d="M41 15 l7 -7" stroke="#26404a" stroke-width="4" stroke-linecap="round"/><circle cx="28" cy="39" r="24" fill="#cfe6ee" stroke="#26404a" stroke-width="4"/><circle cx="28" cy="39" r="17" fill="#fff"/><path d="M28 39 L28 26 M28 39 l9 6" stroke="#e0533d" stroke-width="3.5" stroke-linecap="round"/></svg>',
        'moneybag' => '<svg viewBox="0 0 60 68" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20 4 h20 l-6 10 h-8 Z" fill="#8a5a2b" stroke="#5f3d1c" stroke-width="3" stroke-linejoin="round"/><path d="M26 14 C 8 24, 4 42, 8 52 C 12 62, 48 62, 52 52 C 56 42, 52 24, 34 14 Z" fill="#f2b705" stroke="#c8901f" stroke-width="4" stroke-linejoin="round"/><path d="M37 31 a11 11 0 1 0 0 17 M22 36 h13 M22 43 h13" fill="none" stroke="#8a5a2b" stroke-width="4" stroke-linecap="round"/></svg>',
        'magnifier'=> '<svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="24" cy="24" r="16" fill="#cfe6ee" fill-opacity=".4" stroke="#26404a" stroke-width="5"/><path d="M36 36 L54 54" stroke="#8a5a2b" stroke-width="8" stroke-linecap="round"/><path d="M15 20 q3 -6 10 -7" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity=".8"/></svg>',
        // Umlauf-Gewissen: Genehmigungs-Stempel (Griff, Kopf, roter „OK"-Abdruck, Stempelkissen)
        'stamp'    => '<svg viewBox="0 0 60 66" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="24" y="5" width="12" height="21" rx="5" fill="#5c6a75" stroke="#3a454e" stroke-width="3"/><rect x="13" y="23" width="34" height="12" rx="4" fill="#8c99a3" stroke="#3a454e" stroke-width="3"/><rect x="18" y="33" width="24" height="13" rx="3" fill="#d64545" stroke="#a32e2e" stroke-width="3"/><path d="M24 40 l4 4 8 -8" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/><rect x="9" y="53" width="42" height="8" rx="3" fill="#c9b48a" stroke="#8a6f43" stroke-width="3"/></svg>',
        // Terminjäger:in: Dartpfeil (Spitze, Schaft, rote Flights)
        'dart'     => '<svg viewBox="0 0 72 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M3 16 L20 9 L20 23 Z" fill="#3a454e" stroke="#20242e" stroke-width="2.5" stroke-linejoin="round"/><rect x="18" y="13" width="34" height="6" rx="2" fill="#8c99a3" stroke="#5c6a75" stroke-width="2.5"/><path d="M50 16 L70 5 L63 16 L70 27 Z" fill="#d64545" stroke="#a32e2e" stroke-width="2.5" stroke-linejoin="round"/><circle cx="9" cy="16" r="2.4" fill="#f4cd54"/></svg>',
        // Steckbrief komplett: Namensschild („HELLO my name is"-Banner + Namenszeile)
        'nametag'  => '<svg viewBox="0 0 64 46" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="6" y="6" width="52" height="34" rx="5" fill="#fff" stroke="#2b6cb0" stroke-width="3"/><path d="M6 11 a5 5 0 0 1 5 -5 h42 a5 5 0 0 1 5 5 v9 h-52 Z" fill="#2b6cb0"/><path d="M14 12 h16 M34 12 h16" stroke="#cfe0f5" stroke-width="2.5" stroke-linecap="round"/><path d="M16 29 h32 M16 34 h20" stroke="#5c6a75" stroke-width="3" stroke-linecap="round"/></svg>',
        // Schlagfertig: Sprechblase mit „…"
        'speechbubble' => '<svg viewBox="0 0 60 56" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 6 h44 a6 6 0 0 1 6 6 v22 a6 6 0 0 1 -6 6 h-24 l-13 10 v-10 h-7 a6 6 0 0 1 -6 -6 v-22 a6 6 0 0 1 6 -6 Z" fill="#fff" stroke="#8a4fd0" stroke-width="3.5" stroke-linejoin="round"/><circle cx="21" cy="23" r="3.3" fill="#8a4fd0"/><circle cx="31" cy="23" r="3.3" fill="#8a4fd0"/><circle cx="41" cy="23" r="3.3" fill="#8a4fd0"/></svg>',
        /* Kompass: Gehäuse, Ring, Nadel. Die Nadelhälften sind zwei Dreiecke um denselben
           Mittelpunkt – Nord rot, Süd hell, wie auf jedem Wanderkompass. Kein <linearGradient>
           und keine id: Das Ding steht dutzendfach auf einer Seite. */
        'compass'  => '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="32" cy="32" r="26" fill="#e8eef2" stroke="#5c6a75" stroke-width="4"/><circle cx="32" cy="32" r="19" fill="#fff" stroke="#b8c4cc" stroke-width="2"/><path d="M32 14 l6 18 -6 4 -6 -4 Z" fill="#d24b4b"/><path d="M32 50 l-6 -18 6 -4 6 4 Z" fill="#8c99a3"/><circle cx="32" cy="32" r="3.2" fill="#26404a"/></svg>',
        'quill'    => '<svg viewBox="0 0 56 68" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M46 4 C 26 10, 12 28, 10 52 C 24 48, 38 36, 44 20 C 46 14, 47 8, 46 4 Z" fill="#8c99a3" stroke="#5c6a75" stroke-width="3.5" stroke-linejoin="round"/><path d="M46 4 C 34 20, 22 36, 10 52" fill="none" stroke="#5c6a75" stroke-width="3"/><path d="M10 52 l-4 12" stroke="#26404a" stroke-width="4" stroke-linecap="round"/></svg>',
        'cocktail' => '<svg viewBox="0 0 60 68" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 6 L52 6 L30 32 Z" fill="#cfe6ee" fill-opacity=".45" stroke="#26404a" stroke-width="4" stroke-linejoin="round"/><path d="M14 12 L46 12 L30 31 Z" fill="#f2a900"/><path d="M30 32 v22" stroke="#26404a" stroke-width="4"/><path d="M18 60 h24" stroke="#26404a" stroke-width="5" stroke-linecap="round"/><path d="M40 5 l10 -3" stroke="#e0533d" stroke-width="3.5" stroke-linecap="round"/><circle cx="23" cy="10" r="4" fill="#e0533d"/></svg>',
        'firefeather' => '<svg viewBox="0 0 56 68" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M46 4 C 26 10, 12 28, 10 52 C 24 48, 38 36, 44 20 C 46 14, 47 8, 46 4 Z" fill="#ff8a54" stroke="#d84315" stroke-width="3.5" stroke-linejoin="round"/><path d="M46 4 C 34 20, 22 36, 10 52" fill="none" stroke="#d84315" stroke-width="3"/><path d="M10 52 l-4 12" stroke="#a33327" stroke-width="4" stroke-linecap="round"/><path d="M47 5 c4 3, 6 7, 5 11" fill="none" stroke="#f4cd54" stroke-width="3" stroke-linecap="round"/></svg>',
        'scale'    => '<svg viewBox="0 0 80 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M40 8 v50" stroke="#8a5a2b" stroke-width="4"/><path d="M14 12 h52" stroke="#8a5a2b" stroke-width="4" stroke-linecap="round"/><circle cx="40" cy="8" r="4" fill="#c8901f"/><path d="M14 12 l-9 15 M14 12 l9 15 M66 12 l-9 15 M66 12 l9 15" stroke="#c8901f" stroke-width="2.5" fill="none"/><path d="M2 27 a12 6 0 0 0 24 0 Z" fill="#f4cd54" stroke="#c8901f" stroke-width="3"/><path d="M54 27 a12 6 0 0 0 24 0 Z" fill="#f4cd54" stroke="#c8901f" stroke-width="3"/><path d="M26 62 h28" stroke="#8a5a2b" stroke-width="6" stroke-linecap="round"/></svg>',
        'wrench'   => '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M52 12 c6 6 6 16 0 22 c-4 4 -10 5 -15 3 L20 54 a7 7 0 0 1 -10 -10 L27 27 c-2 -5 -1 -11 3 -15 c5 -5 12 -6 18 -3 l-9 9 2 8 8 2 Z" fill="#8c99a3" stroke="#5c6a75" stroke-width="3.5" stroke-linejoin="round"/></svg>',
        'sparkler' => '<svg viewBox="0 0 56 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M28 30 v34" stroke="#8a5a2b" stroke-width="4" stroke-linecap="round"/><path d="M28 6 v10 M28 32 v-6 M11 9 l7 7 M45 9 l-7 7 M7 24 h9 M49 24 h-9 M13 38 l6 -6 M43 38 l-6 -6" stroke="#f4cd54" stroke-width="3.5" stroke-linecap="round"/><circle cx="28" cy="24" r="5" fill="#ffd75e" stroke="#e0a92e" stroke-width="2"/></svg>',
        'bookstack'=> '<svg viewBox="0 0 64 56" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="6" y="38" width="52" height="12" rx="3" fill="#3b5bdb" stroke="#26408b" stroke-width="3"/><rect x="10" y="26" width="46" height="12" rx="3" fill="#e0533d" stroke="#a33327" stroke-width="3"/><rect x="8" y="14" width="44" height="12" rx="3" fill="#f4cd54" stroke="#c8901f" stroke-width="3"/><path d="M14 44 h30 M18 32 h26 M16 20 h24" stroke="#fff" stroke-width="2.5" opacity=".6"/></svg>',
        // Spaß-Insider: Lottas berühmte Tasse – echtes Foto (freigestellt) als eingebettetes PNG.
        // Kein Achievement: Namens-Schmuck nur für Lotta Schwarz (siehe member_rewards_of_type).
        'tasse'    => '<svg viewBox="0 0 115 150" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><image href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAHMAAACWCAYAAADtyrfXAAAABGdBTUEAALGPC/xhBQAAACBjSFJNAAB6JgAAgIQAAPoAAACA6AAAdTAAAOpgAAA6mAAAF3CculE8AAAAnGVYSWZNTQAqAAAACAAHARIAAwAAAAEAAQAAARoABQAAAAEAAABiARsABQAAAAEAAABqASgAAwAAAAEAAgAAAUIABAAAAAEAAAIAAUMABAAAAAEAAAIAh2kABAAAAAEAAAByAAAAAAAAAEgAAAABAAAASAAAAAEAA6ABAAMAAAABAAEAAKACAAQAAAABAAAAc6ADAAQAAAABAAAAlgAAAACvqwmxAAAACXBIWXMAAAsTAAALEwEAmpwYAAADKWlUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPHg6eG1wbWV0YSB4bWxuczp4PSJhZG9iZTpuczptZXRhLyIgeDp4bXB0az0iWE1QIENvcmUgNi4wLjAiPgogICA8cmRmOlJERiB4bWxuczpyZGY9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMiPgogICAgICA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0iIgogICAgICAgICAgICB4bWxuczp0aWZmPSJodHRwOi8vbnMuYWRvYmUuY29tL3RpZmYvMS4wLyIKICAgICAgICAgICAgeG1sbnM6ZXhpZj0iaHR0cDovL25zLmFkb2JlLmNvbS9leGlmLzEuMC8iPgogICAgICAgICA8dGlmZjpZUmVzb2x1dGlvbj43MjwvdGlmZjpZUmVzb2x1dGlvbj4KICAgICAgICAgPHRpZmY6UmVzb2x1dGlvblVuaXQ+MjwvdGlmZjpSZXNvbHV0aW9uVW5pdD4KICAgICAgICAgPHRpZmY6WFJlc29sdXRpb24+NzI8L3RpZmY6WFJlc29sdXRpb24+CiAgICAgICAgIDx0aWZmOlRpbGVXaWR0aD41MTI8L3RpZmY6VGlsZVdpZHRoPgogICAgICAgICA8dGlmZjpUaWxlTGVuZ3RoPjUxMjwvdGlmZjpUaWxlTGVuZ3RoPgogICAgICAgICA8dGlmZjpPcmllbnRhdGlvbj4xPC90aWZmOk9yaWVudGF0aW9uPgogICAgICAgICA8ZXhpZjpQaXhlbFhEaW1lbnNpb24+ODk0PC9leGlmOlBpeGVsWERpbWVuc2lvbj4KICAgICAgICAgPGV4aWY6Q29sb3JTcGFjZT4xPC9leGlmOkNvbG9yU3BhY2U+CiAgICAgICAgIDxleGlmOlBpeGVsWURpbWVuc2lvbj4xMTY1PC9leGlmOlBpeGVsWURpbWVuc2lvbj4KICAgICAgPC9yZGY6RGVzY3JpcHRpb24+CiAgIDwvcmRmOlJERj4KPC94OnhtcG1ldGE+Cp4uxFIAAEAASURBVHgB5L0JlGRXeef5vdj3iMzIPSursjZVSSXEIhAIhBHgBbBhwFiyjYHB4AF7fLp7TE/3NF4OqT628XEvntPdnh4zxoOn2x4O8rHdNhjTxiAYjGyMdqm0lUq15b5Gxr689+b3vy+zJCEEaIMq1auKjIgXb7n3/u+3f/d7nl0k2ytecd2BbDb3C7lc8YrQwoFnMfM8Gh/b7UDgPsTiMYvH4zHP79TCXvNPhzsn/uqTt5zq7B71Qn5PXCSd83y/P+R5sdfF4t4rggg3M8D09Eebt4sqe2Ixi4XeWjzs330s1Y5HB7zw/+6OwAXd00OHLAU+40E4GPcD6FKv0OdlvPhM67UviD4BZsISMc8y3iAcHx+/oPv2XDbuogBzMJj1UhbPxrx4arfzAhAEHaDRn4hCY6CeiDt6HXiBP8h0NnbpePfUF+z7RQImIjLuuCXtFYzBDnt1fNbJzVA9QYgCOGIUKg2DlXY/fvqGY+e6L1j0vqljF4XMPHToUBjGkkHc4lCZo0n+hg5QpwTxKRSusNyBBGq3Z14v6MFqeza3c8I3dfyF+PWioMzR0euDUmmyk0hl2lJ5RIERU42A3f3uaBbWC1WKBXtx338hYvaUfboowNyzpxTPlks5tNR81BOBCZy03hNp8j+EIiNq5QjIFFrlwyWjyLphuSjY7IkTD3j9oJb0+71kPJGk4ZFOExOFxoAwiKATftJwA/NtEIYb6VhsQzi7nl4Cfy4KyrSF28w21yzm9x00ziQBHAQpFBiHECU/oy2SoRgsQbDRHfQ2LwEMz3fx4gBzcdHMb5gHBe6yVsdmATH0Ya+w2IhWIzL0sDm1JUKpRZfOdlGAuQAefXSZQBToWKnAEjWGMFTBGGm2ju3KEwSEAGkFO2+WXhKIXhRgTgFFHH+BGutYLH+ifwAp2pPsFJU6yAJceaHlcRwcLhTcnkvlz0UB5uTkJOCgmZ73vwpUwOMl6ozUH0eyFjgWG1qSYwuFS4syLwptNqIsFB0+REIQACNNB0B3paUjUbdfMnTgd4NGp7374yVBnBcFmKg/1ofIApzn8YiXOkmpz9JxnCsPKnUmi8wUzxpdLzhdH9TWLwkUdzp5UbBZtTWIY19CZ7uSUV/EUcVknS+PYzwHsQ4KBoEXNCqTuUsijrmDpdMpdj9fsO+ZVMrRI2KQd0VKADDQPBR8j4W+HG2KQj0c8XHzsnkc9JfQdlFQZqpSSWaTsTJU+GSNRgg6FivUiJrAioMwbr2B2cMnLiEk6epFITN77Xaun0odxEApiK0SshRubpMitIvnjvfH/EHoDQh8nWhfUiLz4mCzS/W612uLZfJfXNR94g9UuOvj0T45DUSkuBKCvt8Lklu76lIE/Av970XBZgVCb4BiI/cPoElOKs+HnCC+6h8yUqgCpiiVb6u+Fz+dvurB1gsdwMf376JgsyZIMo4ogdHpr+wIYbfKKuAT5OhygFBvnV8hDLr4+do338zfS2i7OMDMgYi0V7kARJ3QosBzO9ittEuFvcRj3f4w8ONITg64pLaLAkwRpotKi43yT5uocaCQmEQpMU33izityzKwhYQFC+7AS+jPRSEzRZixRCQfd7A0f8eNp1imaFMqbuTiU1JX0CMNuncJ4ei6elFQplnOUnGaivbqi5XqJfycAuSgjFiso0xkKV3LJnp8u7S2iwLMHKQpHbUPNUqTjWhRZgifYL1u02f9wBHky+LMjXZfSn8vCjbrIHItFVU+NTzuJw6Q1y+lVKFLbLsowGQhkJRUsBIffQqEHMocEoomvY2M1288xZEv2N0XBZi9wQCfrFc5z1J34HBsFhCdDN3ZB9fthF5sMZEML6lkLnX/ogAz6Xn4ZIODkZMnIk0H5A6AehPhBqJOvYKBpRLYK5fYdlGAGQQBBEcoRKA5dvpElHYp9Il7L71vFwWYDpZdrfWbMHIs1rHanR+kydol5/xxnb84wEyl8Lk+lebzRHRht3BbP6jVnrj/Uvh2cYAJEs4VIDy/ic1KTu6yWf2MF2jD8xJnL5sMmpcCgI/v40UB5mAQC/H8CDWhdb79DsSdb07bISsvHgs7iUSs8dFP33fJuQ0ueDDn0LgTubCQ8GJFkHTQCbhdWXmeUoW16DeO6ku4zG56SovUXeOF+OeCB9Oun00Np7ypRDyc3gXzWwIhiuU//ngrJhGwV9zwGAl/yxNeeDsvfDBnZ62QUUKIMn+e2nQUcjpKlSwK2Sfnfb3woHtyjy58MGlzlC7wGJACzoW7Hic/1TXtT2GNZpLOJNWuS2q7KMDs9T0Vivk2dCmaBWyiKsQx+5mU17cbjj2G/iUC6QUfAmunEul0IjZFLkEuUnFARlrt7iYM9VlkyYe+Hyz1BrFF8+Yed9DuwS/s9wueMrOxWJql7xOw1cJuysh5SHY0WIcin7WsT4XW0inzwfaSA/OCo8w59Jh977PU1MgPx2uFMgsNspW63xm1Gj6Ap4JnN4WE95Fy7vKXXTb+Q4t/9Zvbvc3txQcW5nv+mbXBW/7j55RG8lRXOD8/LuYPYk7fr8379Nz1+fL4y3ND0/v3VQsTY/FC/mgimz2UTMYOJ1LpXCKZCbe3tgpf+NLfzX7qc39X6g4UdgaPXTbrKFNfyS6gFwlvYFcfLNtbrz/aPbhvejWVyS2n4qmu169vekH3pMVHav1YsRH2/ZNkyZ9qtE4vPnziltW3/NPPvSAKP32vKDP+c29748jb3vr6/UcPHNhfKaWvTBRGqpYaujyRzVVDLzEZS8YK8MlUPBmzfrdh3X7f2t2+rbXqtt5okKMezTknGqXF7gLK7t0ZGaAmrdc27OEzS2nz+nuG8qk9+XzVrH3OYkHP0tWkxchBiReGBpnq/no2vHK5uv8HH1m7+5fv6zWWb20vzN/3lXvvOPuzc5+8KFeP7Y7Dc0qdx7AQXvRTb5965Yuuunx2dvbq0bGJl1RHxo6Wy4XpdDospf1FEGtYL1axxtLdFuYOWJdgZa+xan5/YM3mpg36HDLo2Mrquv3DvYt21yN4zomCiTLVaBe7dLV/+LLDZmNBxyaSG3bVvrwNZeGqvVUbKmcsnuSVnbL8yIwlk2lLFiYtPXzMsuVxywNuGkc+4c9ewvMXwk7znn5z9Sut7YUvd7565/3H5v6PiyZj4bkC06MkWvEn3/mWK688fPS103v2Xzs8NnxlsVSezhfyGWCB0rrW6gZUQuvaYONBa57+qgXZfbb16D8CxrblhietU5u3QY9jWFnrAUCnsWArW127Z7liJ7eoT6B1esLO4QepqsqIFB+3B/WICZLbPmmT8SWiLIG1OqFNj6btwNEXWXnqMMRMBu6gbYlU0bzyUXIXDlk8lbNkIm75TMZK5bKNViqWSycpSDNYCdubd3Zrp/5i+cz9nznx579x7sYLPEP+WYG5Z49l3/OWd171qmuv++GZ2UNvGB6dfHFuaHgoxsLYDmyy02lav9ezzuYZa22dsUFyyDrtHokAHQs721QPSVl9+RFrnPqS5SpD5NVlza+zTrq9hjoaWqu3bevNtD3SOGzz/Wl+dws0SXSGPsVmd8HUZ44PuGZq46RVeivgruVDZsM5z/bPlmx0epzJU2VRYJljgT896QCNJQGW7wmKDieSCUsnk1Br1saqwzY+WrVCOhzYoHlnc+3sH3fP3H/zH52YW5iboykX4PZMwPR+/EdeO/GOd/zEmy47OP624fzgddXxvUNhumTbANWgjECDd79XhwjWYZt83lqw/spdFi+NWWcA0M1lTAjU1lgaVgjgtXPm+11LFvdauzWw9fu+YmG/abFMyppeyR5qzNiitxe2G4G422hWIUQFKQQm4xu2tyyxesKqpP8MDUkhUqEKsuGxUMvVolXG9lgK1hokR6yXmjE/MwVNk8bHMQKU/65OrdKtU+m4FXJ5qw6X7MDMiJVz6cGg2/xyc+3Uv6+du+/LL37vv73gQmxPRwGKfeRDHzr62jdc986D+/f++HC1eFU6Y7Fac9vm62ad9TpscdE69WUSlIsWthYs7G1Z0K2B16ZtnfiKJdK+ZYdGLZkaslhyGGJq8fuKBe3TFnLcoL9iqeysxfLDVjvbtvQga30vaf32AKDjjqX6Km6ogXfUKAAAUTJTL78H7Q6grtAqGags5XFN8oHw1aIlMxmQo81VAC2xr2R+OmdKTJDipOsGA1emzdF/FxWo3e5YZ3vTth/9Wxsr1BMHj73ujeXJI0dSmfLv3vWn//kPXvzjv7ByIRHodwOm98F3v3vixne+9V0H9h/+xdHJ0f2D+MA21hZsa33L6m2Gz08xUAxIA2o8+xVKhNYsPtiifl3SunHAK1UtPvoiG3TWrQ37TeAMzxWGLWgtWhfq3VwDyCbgJTtQ4wZLRxLW5nobi3Vr9DrWhkKSQwnrkajlMfhRHNNJzojlMqIh64QGHYpedvrG3IItBzjcA8tkobY8hS2QjYn8uIWJNIpXEc6QdavIJIFF6VoeqEkiQCkhbn0mQZ9SpyEWTXPhb21x+6wtPHzcXva2X9lTGT38K1Uvmb/v07/7u8du/MWlCwXQbwvmDTfckL3xR6+/4RVXveyfjk5NvqxP4fOFjVXbrG1Zo163bpcO+6zA8jssG+gDYML82JA1Fm91gzPAtMjNAGQqbaUS8i7NKzGOOlQ0v7tlXRScVgMAivus21m12sY6mmeHBV9Jaydzts752/Wetb2WZUNqyDLgAYA6fUcAQp2OwzrxSbkYlKwAELZ6yNuOxFpoWfxGWnaiiTSAlcTT+81Lj9POLGX7H8sVcpQuUMX+qViisuBBv231M/dbZ2HZYmm4TXvCjj84b8cyk4Xi8NQ/C/zuxn2fnvvDYzfObVwIgD5VeCH2mx/555e/78a3/+Y1V7/iX+VGyntXtmremcVlW14DzM2GtZCLgy5sEtYVUnApyZJIL2zjQZ0wS49Yurwfk2MGgTVtyXTZOi2ocuu0dBbAO2Q9P8M1+hYkhqw0dsASaJP9Tp1ItA8Vjloc+5Arw2YH5idSlh7iWqkMlNxh+QHoORR3hlDKD7LXuuuW8ppETQJDhzEtawA/g5taLJWwEPMkVjzMQhRkZSzl2GvgMyFgz6ANpx44ylS9WrHvRHvB6mdvtx61aVJ7rrV49RgRHNg+a+xHquV0Np2YoVrxne++buLcxz9zm3r2fd2eRJkf/OAHk6++bPgnrnvNq35t+uDRyzcaXVs6s2TbZEjVanXYD6BRCkGFIPzuJjMZILHdQqWpdmoM2pAVJq4yf+0OS5CG46E1UvcDFncEDRZ22kZmgWiyiDaZyiMvV6DoFcsnV1E9U7a96luqs0neKyCWWWXZ5UkIUEoShSSVLVhtU7nNAlNcUQxS1Im8AxAdU8gNWxpenBq04Agdy5YSTmaKY4SZGSYbQHK811mxJIqqzBqd6/caFu8jHgKyTRIls8Y6HOYB87eXsEmZBLkh+t5nDDZRrMwmRsu2Z2rP5YnS+NuKUy99gIbMf1+R5OZPAPPt119fuf41r/zf3vDKl/x8PudXTi2v2OpW3errC7BBTAlYKn5sZ4S7wh+JDOwVvQPvCnOf0UVJ6S5BXUPsg4o2ly2J7PIyFcCHMrw8rPUBqPNrlhh+qYXJLNTRs15r0xoLJ1A2zFpNWCTUnmZBZhlTYjsOq21hzjCJShPFaBJxU+62s8EOmRySc3kod2ioYnkmQtBeApweLD1lg1jJMoAB96YPULCfpOz3Nmx2A625BkXSr9SwtTuw+NYyMdF59KSz1lpb43j6C8UmpGhxzqC7bS0ibI3VtPlDpGcnYm+Ip7OfZhgWwJi37992ns2+/e3XV95/40/edP3rrvulbKmcPbdRtyUUnK2tmrVraKYoK0nWo6fbJ1AK1sCN0WYLoVCxJ3ggMz6GKcIg9THOBTKsr9dccGZIH/koc6MHJfbaUDTzKI4cklxqbZ1Fmdq27WZA7YK41euhrS01bXkRBase2EYLC7MyYmN7Dtv6+jqKSd9R5a45IVkpj1IBeVtJt6nitYUShQzPIK/zk5hE01DkuHmJPO0VF6GttNgP0+i+JQpG4UkMEBGiUmT1IEhh40oB6lgParQSBjXXiCFGBo0NJsKWVTMdzB008kwp1W3X/uGLB9P333zLcdSz79/mKHNubi51ZCT73ldde80vpHKp2Pzygq2tb1odquz16KQHq0rileksWOPkF63dS1j5CpQRKQWsaQ5wq2JvQDUMVBwttb8VKRJFDH2Uo06zZkF9wfwWE0M1XeJZWOmSDdaRirDlEJsvWYlhyzFYANVBdjbQSmsNPEdQQZhLIytbmAot7L+UdVuSb5HyE5km0nJh/wxyv8skwT0XhxLT5SnLFMeQx7QvBReIZwAP/yyU7VG+P+bRXnzBNBJFDrYOR8A9jLKD6TJUsn5sHOoOLT2K0gRH8jBr/Lb8xCh0k6+23MR+a7b6hV7QGXvFvvxjzOL7hKcDM9luv/7l1/zQLxeq1eQiCs46cmlrC22zwwDBO+J4dAZ8jkGAmxtd2zhzDtb2t5bdc8iC3EGLV3gQDOaED/PzEiTRyRnQOmX9jdOwNqx3vrfbUN8q1CPDPVdg5kub3bRCVSyQCdEv2xY2XX2zifNhYF2KciUAmNRJuKJ8sjIXupbFXmxsNwBTRIA7T6DCYkNknTw+PiwziON/FWvPjiIN5D1IOgdCnInhtoBF8nAR6VEC0oNNxxM7pgo7eRwKkwZWDjcI4EAEcCyoPWKDFmwZx0VhcsJKU4fwaBUwtVZgRN3wVHTl7+tfgZl65bWv/Lm9+2fH1+trtglb3dzcwtndcHIuoRXLYcaxUrGnbHXUglPLtnZqHs0R4EYO2fgwaj4X4hlPvPMJlR8uZ9vLp2BTcRveh/eGQU1U01ANhUDap8yHgjN4Y3CvWwd7rhOIiQ9bB60zWR1YFi6uZ9IMuCYVfayHxuzD8lxaFwoMJ/JfjBEAGfy4vD3pNHYqbDNVYW4NO5bqIZNjmEG4oNC4t5mYyD4PWZ+eBgzsTsSD/LsCMERpSki2oxjJrThAHKSK45zvW3PlYXQFbNVszg6++JXI71nrQ6GtTtfrtFq05vu/JX72ne986YF9+17no5rXNlZse7tuzUaLDhG1x+fm5AgU52Noy9OSKJQJF8etscExlNvOjTDIGlxNc40ss9zNeAYpDosLtpZgs3h2FJ3Iw7qaS9YdlJFnaLlojS0GUT7aMJu23CQD12pYhtmfxvTpdrEbyRlIUAPIl2MCB4IrQMFtdhTZyGmAzKZWMBwAVoqSJX9rDGqkFiJAwMrhAi3Cag0oOD5Ay+psWCZ9n2WH95tfvNz6ODZUok2TVpNHinkQU/ItXKF+yoXj+tIDuMehy66xQy9+ORwoaXXaWqvVtged3mLlH+75vspLGmuJK4+96M2VSnm4P+gjH301jvcuIkadkaYohzW9k2yT9yaDLCphI+KxWd9oWEKDBSACUVSpSeBwBSCvgPdH7M9RkrRdBjt7EDNFIanRyHAXRXAC+JHAzG2SOBH6aJVpSLuDLcsP/W3cgs4z04PtpohyMMwcz7i7yRbjg+Ipcdir5HYM1hkCWICT38K6pYjKJAG4m6zAFlGyGoC70bR866Rlpji+DHWi8Uo5CgijeTJVkMF9JZ9QhIZUFLcEf9+Rl9g1b36X5ap7bIMYqzjY0spKbbC2tfTVqdu+/2COjlZf7iXjcQqUAQK+TNRz2u5Uf7EvuTxVScABq0FLFJBHsN04mi2gJLKquNN1IGqERb2sJQBALiLqg5p6ABEjWuFBiRpwLdCjghYDhxKSQnZBMfBQvqMcAYorPIGcjgk1yUPYW4jyVMPu47GL6MFcA/YvQANYekDbEwj0NG5G6MW8QRMKUxuogwCg280mMrNu8SGuJXYNFcpPENtGuUs/il1Knm3+IO3KQulxgEsQgivCTZCb2NIs9rVjr73BrvvRn0HGjzsg1zY24WSb1m+hPXvh4KNzFs7R+t3ta790bXaw/2ihXKl6lKRGTczbrme+sdy0RmPJeg//Y+OtH7+N2et42u6pz/g9kZa7RFocfCqOL0uKBTC4qe/AdCtcpagx+NICMdZClBrpFYVsEq30HgYalT8FpUFhAtNH5gRQumRTLLMPOw1m7ZSYBGo/CND2AApR2RdN55BjUWMBHm22jQYtQkfT5AdeTCBu76hjExsWJ0Jcxn98iIGHegYy9HEQDDD6YXtiCzGSZ2N4nfx4zjqJMl4mJkVrxYod3ILFitNs5UnCemTyJiyLWMl4y4TB4CbENkOc+xqHbgPxgLP+VW96t73k9W/FDMnYKq7MZezvJtwiw2Q7uH//cLyV/x8e/puPj5zKj1aSqdRViWwpjVtlJkx4+2KxLByf+yPsd33KGifsZoo1/o/nzv5c81R9c+Whc8snv/S5//x/3vc7t54TuM9oSyzOn423W00rlpk9eQVq6aDCVgywxl2ySY2QXSiZJNaWKg5btbSfBrWIljyAbMIcmHiNDdD8Iq0SOxCPitdjkOGdfQY9oPhSiF3noIQlQ3IOKAHiIRsHuh/svdfjHSB5mKlj66GoXq42vvdgueISXgbqdTXbe5ZFqWkyGWV76r3DMKbTuPPytDlHmh6y25hIsbx8sRj9sMdYqmRJXIhxPFBemv30ud9aRVtep5e0DVauMlJ7j7zCrn3Lu2zvocutSdtWsHHXcCTUYa86LgZ3yA2PlSsHLvulfC7zzxI8iTWDHzqupwQybrSSPxIA9D3S3HR1+i1y0V7vqHpUac/ayIFja0eOXX/7+1fOfOHU8Vv/+2316j2YjO5wnfLdbPGDkxMvOXbl5a8qlsvKabQ6LGm7vh0pGDRmN5IvEHwM/IDAcR+FIkxNWLx8BLNhlIGEXcEWPVR1oR1yXHz7IezKFWfXBdgMqE70AfOFz5r1IV6jmGpI4Exot7YBnMwCFJ2ASIuHKuzheB8Q3NaD2nzYXgtzpc2kSRSnLM1kKpWQf1CiHP4dqCwJkCizsEYmFKwcwclE4l54eAaaWGLvSUwgmSSYONAJIgP5K22dgQ5oWxedoYOTfmTqiP3A2z9gb3jn+21oYsbWt2sAuWZrq8tWA1Afma6lEGlKmmSLRXzLE7gRs55MVnES9U8f9WIH//UCF9520dF4Ssdw35lM6VwxVxoeOVioTv7Q2Myht+4bSs6885qZk3/42Vs3brrJXeg7/omX02Fq/4GD7xgdHU9l8U6T5mEbmxuYJm36qC7TAGmoNFBOdcPY72I8B/hcY9gPUtfjROedw12yTCxVLq/F21GSmig6qP8MVCg55foEUFKWkI+9rmQHuUAhFJJEAYG9qeMByou/eYKwGEY6rFrarvyi8qR66QKylPvA9mWqNNtwJRz+2bBjRWxD/Av6BZ8u1A7HGcCzYwAZxz+YSGN7MuHEAeSdSuDoSEDhkrk+bHti9ip7/Y+9x974E++36cuuQlka2CIArqxgivG+CZCYIdxbpWmS6A4py6Ch58vDloWVMiUiABFFmhyBojzunXvw7kuM6ShHmPyR94x9HnJExCoZr8+50lCpODL+quL4vuvP3XV97VAnfuLzx48jc779RjcbvaMHDv14MlMcLpZKViwU8W8OYW9uAgbmBzd0QMJzZX8FOMrb9XVk4STf2ddD+1V6CAlYvqIOGN9+F7lXW2SASk77dVEJKDHOZFDSVYjrzD3hAHXFl1uQzpMKidyEXcJSe9i4zUYTTxDXg00nGIiMT1tQhkRTXXKJOjy1JgYIch120MDDbc5nvziDD6umgDAUyGBB7ejKyHrNJHQD7hNRDPs5VqJkYt8Re8sNH7AfeusNNnP4MuvAPZZXV21hacGWlpdsA6qsbaxhYrXcpHILlJgIGVh0ivHKlcpQOFNIXISrh1LuNDHlDMZuxQB2HrJQ3MsRSEQkUhLVOk5zVOtWhwtU9sqFlilWxtOFypsPXzmTufHa3B2f+Is7vq08Tdy/2FnoNzcfOHH/8UNMCzt0aBZAy/aqV7ySstgP28mzZ7GzRBNs0hDlbAfExGCJr+OYFKK4HG3mndkexAnRM8CuW5gaQX+Tz8gNyb7mGQd+iMzqZyZgh4CNWjlA4x0ozQSfqE9ptQGKS5jfiyWD3GOQxGq3AWgLF1/ApPA1SJLdwarlw3XbBtQ+ClUD15vqImZyKEloyXHa5CQTE8KFvKByQrDOMVCd2GdHX/Jqe/E1r7fZw1eglWfIs27a1tKS1fB+1beQxY1tWDmThaC3WKsGQXJQzncFr/Wwcmnezhxjv7PKYb/iRLJDRXUiOZ9xU2SGYXPnyhRzG9dzn3jXdTS+krW6koxdEVGqWM55M5f/i8lk/OjxP5r8l5e/69dP6BbRBZ74l2lj/aTfu71T3/ixf7j9Tozrrh06uN+Ghit21Yteantn9gHqCTt77ozVUfM9BpsoLcrhHRj6RyxVmqUhXLtPhgCGeS9WdtTg06GEXyOEhOMd0HxYZUupJVvzlsksWqYC8ISjeoDoc12BBJNhUlQJTWlmwwrpmJQRZTEgpbg2ihEKjWSoJq8ALJDVUCFK0pP7jT56aJ8eQMagnICoTIwMg0DUgFyqju+zI1e+wo699Bo7cNkVKH3ETKH2GjrC5uoS8rdpdbIlWrx60nadQsZk4xiB4hYWKnaKsiaHRgsfcYZJNqB9ioH2YOku40Go6T+TUMfKDB/AtXyOkzav4YK/OzHmHnYOyyZhGLZPmE/KGmMnh42e0iv7P0yl4sWpo2/nrPRDN//K/2T2Gwt81lWesAlMXGfBV5nbNVx55dvuvAeZuWUHD8za2OiolcmXueblr7TLjxy1U2dP2ulHHrDlrZO23QhR54kt9haRd1NQHjMYx3w4sRdg5Gkh3WPzFBSBey5fxlSooqDkrJ8fRc5tWG9rDWMeLVJylsrATEJAleJ1BiABQho1ilVMznEC2WEXamQi+bAvBw7f+hj6AY/UnChtWI2uESnjXDkgYM3I8iHAmz3yUjty7MV25IorbM/efZYvDXG9gVP0zq4QOICdN6DAbfQEOfL7zlEBFTkdwTFBPmuUhA4TRWBAOAKpBdWmYb2NesMpa2LpA0BWrpEcLz1sdilnbWzRDr5taeoK1Tn6Fnh6oURl8Dfninne82jhBV5pUmiw4R3gIl/Najh1dc+bw97VH37gE++fO/qBP1B2zBM2d9Rnf/uGiaXm2M1nGunrWgyqhHm1UrapPVM2gVN5dHTEKoCaITFKmunW2oqdO/uQzZ/4ui0+/BUa27Uu4DS2gGb/DyBH8KWu3Gurp08S6Yd6SBmB/5IxQESFRK5B7VFYIHSYxAaMwaI1xwQCCtEADVehKhx0LtriS8NEi13vFTA7pkg9gUVrPNAaY8z2VPOsIeEtXahadRLwDl1hB49eZQeOXGFTe2etRB6sFLm2KAmwOrw3AEEA1nHYt2Gtish0OkRDoCyxNlG9FiFpcx4w0NTjN1DjnNtQSpMoKwUI5ZExKw8rUkRwAN2hg83ahMIbyH2BOVBmGBMzREOXOSPlz00MvrnVTVxLIiCJ/E0TgCiRcloqV6zA+BcrKFckMSXQusX9FFQnHtnuLt39C/WH7I9f/qEPaSTObw7Mv/on/yS9OTr41bPNzK9uYJSnIHEqXKGlkhyMdlsFzLGRUasSvyuhJOWLzB7ZAShH8oBsEchdPHPCFs6dQp3C2iAbr7Z60mor55ix25ggeFGaPRzt+zEtpolfnmOGorBg0MdRqjokSzGSsGdkIizJIz8yoJOMgWOpAzxHDWm8uUkrDI1YqVi28fFRm56atqmxqs3u3WtjU1NWGaoy4WTLKucWUUDbRD1ih6K6LrKvg5beajd3ZCG5S45apBMwUGyiQil6TD+3YWgwgSXLxGalKSOrATIO2xag0mYTsMlGDQ2+SV4U91MimDIWUmjYaUYonfQfJWC+DGz3MrZ1xAMcOCj04/FjmMf7O2Fib98yqR5mmRSnJNSJ68iG4Iyj41NWKJbwfEGpTEqx32Bz/j5//o6f+q/faB5/vC3qwPzS3PWJtdSRty00Mn+80Iql1VhRp2YnT4hFCYEVEEfMA2RlaNiGmDUFNLgCTgaMZdgCTnSywKXRCQ0J/A4mQxvZo+hLC6qt11aJfuAuxM6TfdhTMhhKkY+rTLlE0lSVHJ3AWMvkirD+EjO1REeGmaXDsKGiFUt8pg0KgyVpj7hPn/PlLhRba8PKBFqHl9Ik27DMHi8pMF2opAfblhyUg8KxUVEhBOiUDiEFpE6ZgQ1LyRGgok/BHEd+yZmvwzQ+Kdopf3Ioswnqq6P9B0wMSM9yyPtKonNqKBv+5UQl8TliEXfNjK1u2Vv/ss3pEcnr2l/7peyjx5fHzjS9l212wndst2NvaJPM2w7SXh+5mUZTLlVHbGJ6BkIaIaORh0jCllPMuGDtof+UPHfuX87c+OHzGq564Przmd9+36HFZvEzZxuJy+SjzStFn5nQhy0oz0cKmBiEZqHWZmSJguTlc+RVKKGe4+rKQhVZbK8UVJti5sq3KsEu91qSGaXsOikIzC1mmOZ41C+PgZL6osaANZMhUijksHCDTuO7gNaHDcLlCauhlNAg2b99ySnAETWIlUagCTi+477rQJ0Kb0UdZWJyjwg33Rtq2yVB/eIUF0eafIS16h60TCxW2qajTI6Xl4y0XBzxIZo+yyBwKsj+TOOkKCU6nalC8Ikje3IfP/3Q/cdfP3fLd7QPaYiFn74h9Y9bicOLa/bezW7y/Y0wO9JDuw9wMRarVRvfsxduBJWW8I0DaNhYW7SVB9959E1r/+B5c4IGxs2mbny61VxMx3J34H67rIuKr0dP5EiQkk80eggpHeJIyYYWA9uG8jY3yDAU6QOawM3ACtIAmQZYZSa4dH8BS4xSfD+Ot0VUIBz1WZ7ZyFXIwDJ4zrfL8Dm5Rbt6aLkDBKS+R0/ei3Ry2WdipeIAshX7UjYAzx0PsPIpO/OBz2KPSZQw3VdoCDt9lMly/gu3j2QZE1dN0e/cQwGBKAVTbDZitTqXi8NR5MvmSCaWlLV03CcfrXVuZsj+w750++MH3vWHNR363W7ejTcjVOy+cO76X7l1dPr/O73R+PXaoPCiDp6G+jqeNGnM9Gcs3BO5XWPZST9deffiZ9bu4jzicztg6gMbT+cO/x7d8idrSOZ+jJMJ/0A0jr045wEd1OY8GLwLCEYuivdBFfVt4OYYCXZRtQZQWXwewOmxFfl8HrAzbr/8l9o02LJJuZiu6AZZTn/JTmB135UrK3BEqTpZE0HA8Pg2B6ZMB/mTnduQSaF7c6Au764vBUhmhZDSNNjNkRW70f11X1FitEVt0N8Y+2RyuE3N41gdH2m0BMN1XUyqJGy1nGo8OlkK/tcZ2/zswQ898/WeXkTJn7nj/37fgw+c2f4dFsT8SNtPJdqIqiXaqXH0JiadaMPke8umX/hdhuV+miWDbWe7D2XzZeHdmYRXo41l59mnIwl1go4JQA2KU8/dkOhj1DlhLKezhpCfonexKHYoFCVDSzk0kl+6hrwxnMpLgDMggOeMZu3jEvKkiPXGyWmMQGDnDpCO38eQebqVmwARWyQ/m+vywixxhrwupEarcdwsYt1+pES4dkf30nUdS929Hu3hJF5sHCe3g+rC63La9IuK/2syoo9ajrBbPt0+O52zD0/GNz93+DlauPvSn/3kw9/4vZ/5yPHFBisIikdDPxFrKmKzeM5xvDActXQivbebLr7t+M2fPmF2Y8+Nvxp5w6dvDvBvLhTSwSpOJ0cFYm2MjwNHXp8oyq/BkwGMNgrlSH6JxamzGnjJQr3kEZLG575rBjNIohg3IXR9wHaDKEAEPBNHMgoCdJt+i4xsWBpsLZKfYsGKUcJKYb9SnhS01iU08GKiEZBMU4SrvuuebrkBURdRuFPvabfYujiL4y46fQc/7dfG5aLJ4Yx3fVEPo8194nit1CZLb36mHP7ryfj6cwbk7n1e/qE/umcs49+UDdqbTqugry3SetaXl9HKGxBLPOZ7uZ8YxL8xpnPOg0lbw0JvaymXjD2ShRwZs4hC3FyMOi9KEMDqjGOj2D2ec6VJQ+wSceiQrdZEg8XeQpvUsdFgRRTs7oa2J9ar0Fg0QAKSCzpwub4baA2y5KTkBF4TFB8tANJLDncFnLUoV3JE+wInV7nXzrmu0AxtlaKlCaiJmJLzgvvKSwTS0fXPv4sTRJPRsVGaIwJ1gGpKqMPRFz5oE6XK5hzg3LcvYC584bmiyOj6j/39kQODPy8k+n+S9Hp9HvFBf7s4OLYiJ4eyFGOZy9Pp6st0xnkw9WW1luymY4MHsfkZBA2yOh6xGGdEMzDqhAASFaqPKTTbMt4LJ5fotTNnoIAOtlwLz0gd+6vLUnYZ1C1MEuUYtQBci247ztsiU0ZaaqT4SPPcVWAca4RUpQTJ1JELzU0kKF6bBln+UY2zZp+oMRK2TDKlRgKmbEaBKxBdZ9Wnp9h0GYeZ60c0Ad21JRr4TVqt3jmKq3ErGk1qyYmk/JjP0ybFaDRr/wUn5pr64NHHLivvtshykE0bJlO5npd7jW7/mMzky2ZzaDCT6t6WTnoDKC8RscFosNQN19GdsXAUpx0MTrQrAj2JGSJbSB3Wv2jsIgqNQx1ABZfGW+JsMha4KsTlBl0sksu50eNa2CiaGLqPuIAMZn51rDKSg/wmmch/0aQ2dyrfIw1N3ITPOm3nmnyKPuoDW6T8qAuanHqPJkj0a/RXx+gSXE234hxdF84izdD3tvqe/2iynjhv60VnPbd/Z6Y27lk6MXJvz0+NEVaLy1ysk5Zaq8nuJJ10kHjpo3PXs0L9cdvi1Mf9dCx9bz4ZruTIW41g2oVqpzPqkgZHPXOjtUO5fHO7HLvU8OofHafzUkyk5MjMycpGxYQpYMoUsEs125w9J40V7RSNSV5pRg/5Jm2Vl/J8RHWO8pysE9XRrh35FrUjatZOK5gM0eTb/R51k7Y6YHe+RTPN7dNH/bSzKzpAf9mhe7m+nX93P4CpFpjaeqJJOOd53A69+3P1fDq8E1LYURAwPXCQbGPfyu/b7ceONKav2P8EMOfmLCgM10/n4+HDRXB25gO9iDqjwROwki9it+yXpigwHtdJUWO0AQj7o5c+a4uO1W5RvTYNIHMcuRZRgLsuf9x8oXVOdu1c08kzfohYKweJnHY2R7h83r2j07SdLiruIIbLuy72uM3dgz96d+e5JunTzqb+SrTwBjfnON1vd5I4gLFSvumiu+c+h+9qXjbeuwOGSf6NlFL2QDQ9nPxNRBklB0YGicz+J7BZ3f/4o7FmtRDcXugHr6NsmRtGdSECUh/Y6LS6sIOt+xz9sPN3ZzyEtTt392/0xR30eArRDveT/rhB290j9gftijr4LRpLDWZ0Ib1LrouQ+xS2QF9yB4kDRHas5D4/sl9gpDB1Al76PQJ/B8id9u5cVTd/wqbbOSWN45zzYKc/0uq/V1s27h33ggGWvF9gDR0qDf0mQN9V9iPBU8bgsieB2VtI+bEj4e2JmN/PxGNJAaIXY6HY6W43GGBNY9GUDtD/aMAdwOyKjtQoiTL0m9vJ92ifjnj8Fp0iqt+VlZypm3K8CNC1gz+6rcBTsnIX56hYtC6dgI2n8EQJNNdRx5Gic1WDVuCqxe58OiKNGuvJnafrq1mKLToy1G3ZtFvtdk1Xs3c3/aCroVghsHb3Pq/vsVxvMVFLnebWk0xvmCJmHOJHZlo/yRLlMPXqJ4EpuXl57IPHNz1bSca9aRz8NJJL0COFqmDa7qta7pQBST3t2umx+vkYoDpq9zedpsFyIxH9oN946Q4aRAckH30lXTkZyWeQ6+FSksNftqlQFXXIJUj7WH+74yRgcOX2U7qIriVwtfqZwtCAtHNPztU15MhQwFumT5JAdjGD81rHcN75DXampkbNjc7XhFVrnTzmE70hKiJ28PxvleXEFm1ZgcUqiMOMVz9wq2IZ5LWQmLjvk8Ccm7PgzX/YOZ3u5B8mtjmNm5YBj7rBsLjBj5pOBx2K0bfdI/TNKTVuXDQa7j8RE26Ob1HfouvJSa6ztD+i+ii9Qh5bKAZyUvBWE0ZB2iTf08qHpUeyt3RdWSLKeOeTo14RVlzeGYHIZydb+FmUqnkgsOWgF5jSs+BShOi6BMt7NlEh8oPtq2NFdVxesLl3R518i2Ypb/oxonN9+J5sVww3vPs2SaOg7c5k1CgzAEpf2Z2ETwJTLZPcHM4Ht8d7dr1rt6anerAzc6M3YGC/A0bvUQ/dID+Gsecy3NZYxrCFjan7aqAdBfLOsLslenkCswIsRRwzDqUldtx4uq3ces77w03lEZIeK8rUKMu7pPo9Ypn8F3pCwLnfBuLFDhi9cS6UJuqONOcI3AGDoQlRI9YqTXqiWiCxWReKriXK040ikaL78hv3dv3nWsgvOU12TnAHP69/ovGgP845rvZITMjDpcVVCn5/i623cI+fPnr1bdTh7Qc+MS8GybEqdUATlHP0cpv28bu7Pjs0cOqdsFXi8KOLqxj7opwoAiMINShAALXpHbOD5BFF7yXX5LERH4lsRzU4AkvniOrUDkUrnBLDnTjFSW7NUgEi9ixRIILVuhK3EAiS3WXhAtgZTtyH2rQMBN84dqNORgDb9ChL+ETW2tQRR4G87XSYFmkn7ZaW6/diMb/Xnjx5fjjcj8/Hn0M04RuMrhqrlziPnO78c14wojbfEsxFFsEcDV9yf8qLLUEZM1pyp4CwBkmTn7F2m2aGPkZf9VeDr5+IujBI51bIq+mQYQbVuUy2nZvrCM0oXUygyzXXIkksgWdHsVJ3fHQZJxvV5IiiNVGiyRKnQw5o3R2KDQjZ+wpOEyjeJNVkHU1cS/woxkQxxTbrQ3o2VEgbxREddQkksXUnYwFWrH19m5gkMnRsiFI1riM7PdPgqW+7A8lv+hk2/mg7DJeu+9Btz6udqaE4ccJNKze6uyOu/eq+3JWSI98SzLk5C970v7cWYrH8OTzzM3WyyRM41OU4dzNDneECuoYoRV0VyJJLoiCBXWMAG5yn4LRKn4l63Z11rqaVBoc397sg4hw51DuSq1BehlTFaEmhLg5eujYv/jsnwgClSEUxdC8tWW+QzVVrsCwRF2GNKl9aFHtluWnj5LwWyVGKpUZso1cmkYu6B0Uy0WGn0SyHmtUaZpXe1zZbBNqTEeh8f+LGObBrx544mvN78SCtOOTzvh2aKIRfZ62vk5eaSWwaSfdJbcJ9+S3B1IFnTmPTVMPbYGPXUiLFaYEJLqIB4O+OvORAvgsa7d29hW5YZ3CdS06KDAeIMiNtdAdYwHUKCmcp10ifNRkGaENas6l4qoLeDjS0W9VL2GGQTunpkse6jW9ys961LaVFCgmOUWpKFk7wskpgl28/aMHSSUJUeTuamrcWGe1rxT1W96rWInufura0UdNDm7gO5/N1s9ahZh75PbQrUjaintFrHcYmBUmsXtxhV8Bo//O33X26Rq2PiSQOEOYdY8f4CVPRiJK9xPafEszR8mp/OZb/0uJq7QOJfDkbY6SlyusKohCnsdJ59U0X5Uc+uL2ApoqSpIe4G/GH/9qnTcdLoRB1ik3qZA2SJoN+lAyUx0YCPWz7LMfr2zqFnbRGJMWkUpbBJixTvlGF2VKkVLL4jsnTIZmsbyXSXV4+FNjhjfvNzt3PIiPSO8f2WbM6g2wNbM/6vNniaasNz9j2EEncVOxyE5J2yJzRpOoga5uIB6W6aKKqw86LRB8kalxXMY2caBV//h5sW+HI1CD0DsvjxFC48dJYapD5R5UJ7xbX1m/VluvnbvGzmdzdKCRnTlH1Y0Xp/ww+Y+06rxntwktPOllyiE5DYRqYCFAGHiXEvQS4ZoMGhWsoOViapzw10lJFHc5dpzthguTJTi/mqdgFm19Yb9oj8zVbXO/aPK9zK107tUyB4Qb3IT1z/9iQvWY6YQdYtBQuPKx4n3VZxu4NTViZpQlZlvkPlI+KwjV+6us2deZOyxHREdUnYOsuj1XvvLp4lKTtalM7FaAWq48yDzT1RA1CUnHd53/bDvtDcKmiLznmiIB7Mvmc/kKqljIAnxJMEUhyu7ZSKiaOj5ZzmBZdBpPggK7lEOWj6wM9pKNu29kvY9ZtXF0AunUe7NCg6FDNJW36Hl1F13C7oj/a774LXM8qhYQNlbJQu0+lE/0m0wK5CKcIUX7aTbL6WBo4O4bCQ7Gl5sSsdScvswHFDmMsjo2hCIWn77XYqTuYOD2r7z1mjcuuJvsvZUPU/UmTdhIjcUpRHa0KU8KWOinW7QJp3FI9ErZu8NR+7gu7Ox2LhbtraB/Xgef+Y7eXejHpQBVNPN0+4myMJG1jzU0YD3qPSxv5FvenJFOvnAkfaaIdHhgv2qnVOtmdDCwKhFYTy33mxnzn4tElNGfdDjcgwMe3iJVKZrrP4lOkd+i3Xbegw4+W6XcnPyWXxNpFrVx4vEymH5VM5ldxLmNFqLSMsvU0uvsnM/aiy4YBXavFCLKNzlifYsTJtXOWXj1n8VVYK8WDe8krLSC7PsUkkElEEQUrkgztN+atQW5qZLeyVA+TiTCgm4TOxqVNsr1cwpmzzdQuFCA/XIaan3fSJMkr8Sk/8UoYf8IlmYma+K/N2dnWWcim2vc9pczUgbN2alBLzd7Ry3mDxeYgMTNSgcXVXYnR9VqfvFkqpBW19kOQ8pKdEUHklB0UWfdNP2kGqQ0RNeowaYMRoDpqp238IFamvzsnuI/a4VsVllvaU7TNFpUrW3G0V8wernNwhsx4DOc6RRVd7i7oB9y8Oz5rAXIxQ9WT9PIZyniPohyRpkhitkdOqhYNi2XlIcFQ2i3nMVctm0aJoqSDlAxNNnWPWIVrpeah2qYJxvpPVUB63rev52dmKI30KpbvY6VJWeRN8osB1dOYqUh+7kovXP22YH7Zbgmus5++t53OLra95My5NZbvwIoeOr1moyMlO0Ndn3iiYqVspAhFjudIrj7mNaHbwkX/uL+KVUitELuKi/JQJNzsZ8R0TMSKBR6nMXBSOZzBD2XIiNA14LgsSorZKHZjn2WBD5zFnKAN4xUtwNG5mDa4/gpZLc6llkLlJRabPWyJrVVKf2P6UDLVLb1nsZCxfCFb0NpQzkO7TZAyozWeKeSRJtiA++qKmmCatGqrgHR79SF83pIMuHi0LXYGP9z1c/tR/lhTJN0jAlPmm8rAER673fvZT3bUnKfc5uYw6bKDc15i8GAx65HVniDHh+KCJDpr7eRIpWgPnSFAyvjAiZ3yogHYtQ9dRIPvktlCQb9JwdH7EzYG6jzF8gMQO2rYEQ+RbARUZyaAsJziZQrpk1OKHIf1s56lgWPjwfmm3XOmbieWWnYW+X5qrWUPLtTt7tPbdrZH1UxkacBk9B+8w4LFRyFB0j4F0Na65amLUGQJIQ+R4/pyU6jJtJV2S1bKpytfspQgtVV6AKmYT+rKE/r1HHy573dvKMAUf4zFHamQdqlOkUoARLIdKkXT9Lr9O3Srb0uZOmBxe7WV97J3xOP5HyyqsojEHR3SSquTZ1nbzww+sdAgHzZhU1SlLOBBUbTCsSgO1rqRFEpFHzbINzerNVIaEGmGcCpn/DsARa5ML42QPrrkZ4BVhr27KftF8fpOAMy9pxhURT2qxZi1iAqs1aggBvvdaFIVj9oHZTRhcaTFNSWDZaw8NG1DR5G/64vcCEBYFSbHPYoMszxirU4m0Arnz6UhaotwUymZge7Pb7S/B53UKHq1o+1ptJ777Uwj94bmIP6avqpsQIlaNS4/NsMOm4fNBu1zxUTvbt3521KmDpgFN0yG2ykk0R9icGbH87ZFqVKWgBJlCG3PWNGxs+PMfj2uos9AC0yBLL9nlK/KoHGnXfNE13XUqg/aduSQCFhIiobdZzVP5ClWJ5bLCxcD8IpRI68LMZtgAvVQetpQJivh4BZxFDQojLSXITLThikHPkRb87DdHuZGjVBQfXjagoOXOy03hhbM+n/YcQlxpKuy0QZxAeXaOPDgOlqKoAIZmsyuL15Q6wz8R3NxNLLnafvS7/30yFoj9vOsPSlpCb0KVUUprLKxtbZHaTj+rTxZ6ZSasNP6p27N9R+9xaf24z3UZj2XRJ7sGU3Zwb0oQkubdtm+KgpRA6UhZdPVHO67PpUwZb7Ie8KKJv2j93J2CzxRCEg4sBxbFYk71DT11QaxV1Eiaj/vERWyFyTl6nNZfLj7XO0fjpfXQ9xCobEWzvwOvL6ECBhBqxXQGQoRp7mpftdCH3my5HuVydErUP5mcobJzgIkVqApifv8BIOlSj52CYjoAQJNzCGkCv0Q/4jaT1fCROj5p9Ts52Hj9t7SYvKDtb73BooXkyagZX8s9WBclaYacb5OkPJ7XyxODjmN+juyWcY6/JvfGpzrDoLjAy/crwGfHk7j8uq5ypfToyW798Qqmm3GZifLUEjfVnCH+VCTqEjUJJPk/CAwfQSUk6ACUp/lUGACRHMLRUeeFuzIKO6otSYcw3Fif3IsuOGE5WnSiGRHMJWkfMmFqFSKAoCOjeZg7yhaTAJxiSyeIRYgO+d6FpU1y2e0N0W4YfWQHu0URQ64jrxNsmfrlEbtqE4Ch8bgciqyKE+QNv1FdNke9+25//PZ33zPD9e68Z/nmRJEHmg7C3G1Ek8KqHzDCdqZ99v3xeLtL1/9oU86Vv8dKVPN7OcaHZTEO3mMt6MSaXKH95RZQU2UgcKC09h5S1sNW2PltGKCeQZo1zUmDKLyn6j4O9Tp0jMATOmTWUXYRAkkNncAo4djQOajCl4MtIBJeS5ooPIU6b5SoCQz9JgLeaBkiybF7kcLtmeExapk/OVRbKQoaLVUnu8lJloJB0GWdhVzSRzpWkwEpwBkUWQIy6IFSnmj3I0CBD1sWfJToXStMuvTDik/Mk/cpANJzJJQrwEPoXquofzsb93wopWG9+8oDjntJ6gflsm7hVmqjBZnIir1NMni3bTX/uv5TTtLc1wbviswsxtt6uk376HuAOsZcEYzaxkT2zNRcVS5j2eGVLEXVmuBPXp2jRASbHeMldaEsxh7pzyI1ao0igaDh4HbMOs7R1iJ7bEgJiSpN8dsGUrhvmMRbpZjxR5FDTGKUcgPqyC1LiZN2W003xEpE2yA012dm+S+IyXV9hG100WISk8TSilWyncFvxUtkVNATnIZVIqC9JkwPknWAQX/A1ZTtxqKwsjNGClparuTl1ClrqsICxRKk/2qn4dynsPtb37rhr1LtdRv1sP0kX4yG1PxyAI2cZrAgPKRHYuFKBKDxlIirP/5+2z2fNSGEfrO2ydvORU+/PrLO50w/aZ4Ij3aU7UtTsvnEvhGSR3FMT0GVdSbYgpRbZ0MbK2tNSHIGedQh66Ah8FgIoCHXGh3/ePX7eyDD9rywqL1F85akZoGyeERiuwjA6EGZTFmkHXQDKxPLwZXGPFZ9+fCDqSIW3NdtNIyMlTF9XW3OKCKQwjEBBxEipmc5wqEC2t5dLoA2UQgNmh7G5bawg3Q0pJ93UAigjZLxmpFsxQ4Qg7IZZ7Pkoyn4+lSs9FLfOOTn7mNtY3PfvvCx9554NR26j+2gsKb/FQukcFGLrKwOcfquYQIw3WUaBKrA1j6/4mR7ZU/mp77+HkwvyvKFBl3SzZPJcsvdpGJ0hrV1xxs68oDVVsjSj9WTMB6qcEOS7z31Kbd/jDFgXsMIrIpwWCqHXo5aoGkgoVHcasNbLg8hLLCwl3KgfsP32mn77vHFggSy12WJE20DM9No4VK/qq+jtieVkor0UwGvbRnyU6ZEXVWR/NEGxuBwivYxXkevZElbYZMFF5a3h+1Q9eQi3ITmbgKFS4j/0/XfLtrI2afv3/FHlmhjA0T1E0YNRlkZWeKlpXtILkZZljCP3YdosmnAAAet0lEQVT4xzPTV374jv/3X81y2DPewjmL/flNN/7IqVr+j+pB/i39TD6RrVStOMzjq6hElmZluojEKZFwt3R/697Ar3386o/+5RMy6b+jAnS+hbfi2jwc/ywrmN+XEBPnojI9JikcvEqIqklU4/BUnjgghjwPYxOL1OBp81lRLO1LL2UReMQqQwZ+FirqUFYqgcKSiY9YgUrLWpu5ooVLUC5EYBmWsI/CailbQUURwMM0duxV5Mn1wFC2k2O/ysZpMvDKiShhe1LzxE0gUaQsnA6/yYxpMclaiIoWS/EVqmszYVrI6wznSIwozUVUK44uhUscoQe4WnohbdhI0WCELTc6maao//s7VOz/2u9/4COv/rlPPG0K/dqv3zD9hy3vvfVO/J8NktmxWL7kFanNkHMFQbJMHtpE/7T+VIEFr73Zy1vt97cbvSfVA/qu2KwA+fTx43bijS8PUZPfjMNgVLILUcLGCit4uZ6mN1LMOAPe1ZSj45Ivkjfi805oc46WJJQocJHMpCkSTNH66RmqdYxYlgZrpXWKUt9NSoWeoOi+WGCBNA8Fr7PUuE1zjBQiqW6aGC6NEpAdcfKb1E4pWUmUKml+uqc2BazBikC2b4sbhNJ4LW72+cxjPjZJ8+dH5eD2ADHBxBP4RXx6UZ4Ryg+acxvRoroNU1SzzuMmzMFRikPU081jyMbjL8IPU/nAD1/20O+/7B+3brrlPFG7+3/zn/BLlnj1oRv2/di1L3rP6VbqYxTf+KkwN1zKD40RrZukgglgUnkkxdMgpOxJI++hlPnNDUrVLPw/FX/h37zl1/78SdGa75oyGarwr217o+EVV9e2UItZW68CRKp4lcN+08raM6tt2zuRt0peHp8YLjUKNkHBSQcmAy2Zx8CKmurEH7UwNnH6EevASrTeMsXsy/PckFzvJPVPL7ezemoQDvMDmBl5bpSHvcqaWFesUXaB8NOLPy6LQdSHa7EJKPkUS9MBpY/crXc9HunRs+V18oNgqwJGpdDkAOhwXWm3GS5c4xkuJfQAKUbiPJLxurbYOtkoVmRiSqHK4FWSZhmTzUcVy2RpJJUNYv9zO1N++RdSv/Gpz/9fy185O7/4aGnu5tpxrnTdR94x1Ez7Qz0vtS/wsy/5r1+MXdMKkldTsHE2XijEyyrEweMeVS5GDgxXsVMTkfZpgZUSnQMK/ccbK7cXe1v/5rpf/eyWfUT9fuL2XYOp007jkS3E0seLmdLrakQoqhWKEjKmqsScZybf8+iaY3dlHODihZUij4DaZgI5qnGjTuNY7kfjFii+EffzdpDHamTUUOgt2agBaMOok2lHR2btQa9C8f2uLZOUP4u2rHtlqbg1Fu8CKE++ZcxF9UkAE9dlqnCvGPIQwDpQEkWEVXRJobN2M+4y8GrISVxjXAuWz3iVtKaGpjVxDiiLpsek1NOKZJNqvqC+uaQvx3qhXh3s6jNIXPBZ5eB4pLLFoNJ0vnxNsrJ9Ta8xu35gtHli8KnrVq5utcJ6tzuJw2OKoP0wcdNMNpmheirihZIAKuSUZhGVngrhCkrSft1TmnQfLd1XSR0KbQT1+flquPFrf+cvP/xDkf75RCT59rTAHBJj7Pqn8ASFWYq+OttLrI+ZXpAWSefuOrkBdVLYl4FqYDOKGuXAlkYp3s9XoPKog6eRKhC0mLCplQdcRa+Qx1X4sOxku2YTm4+Yt+9lNsiVeegMOUjK14FSfMBLwaIpqU8dPpYGyjSB2iFCiImLM4nk9G/CGdKsBfUevJ3MQp6fOXWlbRZUIVN2qwYrCm1piulciA8wUXTgDhXsVFKA+AZ2mDESJz0UP3nAXBom/ZQy5JwhdCgyoRhKwBmiho9XDauYcVWfVFMtBHbFJRAPOkv/nG8XTuDRL2aCU2xouBsbl9EAkKqzi6sLfY5iU1vnOqXB+r/banW+MPdtqpc8LTCNugedqdZd/XSO5wKlCkqkUyUSzVrJpX1jZbv3zBqyaJvMgLydnOdpBTx+4sgMgV9MEnrtZnMC9hqjKlUNheRsZo+tzow5k2G4krcSBaBSS6dBY9tKgNqhLE0B95yzO5XBgA8W3oYNm7EKVLwBgD0AlXwUZ3Jymn015GCae1CZ24JH77EhNN7xkStsACUWMV2wRNxka6MMSUsUy9XTj2Ymk3AUFQIQyFyQydMTmhSiqOTgAtjXKNpO+RvQFqWYeOyMqj1Ljiu1m02z1p1HyE+feWliy9erewlaB6rap0ORi/Jju2WLGlja0qZ8a3/lTDfbXv6tPZPzH3/xe//7eTNEt/jmzd33m3c+1febUYJueMU+vxPm3jzwUmNu4Q4DIVajmSV7TA0tUu47A5V10Rgn0HbHR9LII8qSMjbSVjiF+amn+oCNTkLtXmF0l/AgbUJR9dK4dUd5IBzFGMeqxBvdCMo2RLlhGJQfK7kiTqBBUg814/VX8liDpnpDCbIIEnLVsWV4PHF+qAylVzBn5MkBQAZWtxdVyoVIMoVdNYsHC+1aipR0AsSzrWzwJKNC3A5OlwFaLjX6AMhii0o10VIKVUDRSzas2K58vtjkjA3aqPwKUGEoaiTqociH9kuJVFhLlVx2264K3D00+DZFoprzD9fyveWPzSx//d+/+F987UkKj+vY4/48PcrkjvWgt0olrXsGYf5KVyoJ416OAJfATLn3IZSfPDLzwXObKB1ouFTUkmYpBJ3cgVUlqJuXB0Dy5Z3ZMkTHDuNNGpCRp3SNIoOpKudlpVu2t6lYWUTOafA5V9StpXR8FkXkobguic9tpreUK6UdOmWI+xFQtyKTIj0xY978I5Yj46A6nrMN2LsmkUynZA6uApgDZOblMzx4HA+Soy6xYq6nok2SraM8ZC5O+bR+T78zceIq3cbTdwFjV3ZGGQCasQKHTaBTaUt6VEKlyvmnCeRzTfgvLD1qb0g/XYQJG7mtypoUaGwtnfz7bG3+Y/v8b/z1sX9737elyOhmT1Nm6qTV1dFepTq4rTfo/tQAHV5eGlEFApRZiZyCDckPun8sZ6OlHI4FWA+dUu1yKTOhvEd0CDUEuy6St+BCp+NEOxJW4CkIFRScFJl0HnURBKAn+5DHapzuMXDIkVkW+aTYH4NdiwIzfBbb6nEfsSyBIZO/w1P9WpRATXO/VJ7nsvDwuJHsafMnrrBFIhByymslt2Sl5G0R15TMElG9z28+JonKwo1UmKBQaRNlqkUbXfwTBajNd+feA1CVFQ0xWRRGSzI5HLcCU4WqlP+Lw4g+MsGwr/V8Fq17EbCaeGLXbcRKg0rTm2ceHXTXF/4k1n7k13507i8eEe/ZBes7vT8tNquLjR47bjPxQ+mmJd/Ok2szio6oHh5zTZDSegYEoTIzgokChaKjwHKkLMTck4V0iJqnCSA3mlyASloWGxvLx62kh8jd/3XrLs3D4mBZ4zxQTbxz4RRlqFJ2CvszzfWK0lBkSDPwupZktzwzGkRNHsdquVUelb8EBXqkjMQojpgSVbNQKaBMXLUim5Hr0F6KsLjlC7Ilu4AoG9e5D5HHiou6CQjL5lFpTBZNGtUHbLm6tE7OadQZByXFyOEgj5QmYoAyIwVIClTLFdlow5EIKFA1pA87bVGPt7a+yhOcTtjaw3edqc8//Bux9S/d9NO/feviTbT/6WxPl83azTdbcO17mw+GQfZBhPk1PO2W+4ltwEsYlDSzEPIDUL7yW8C+XheYoRYty+siVDVD+e9CV4p6To8kbazM88YY0BqPcUzwtB/lJucmpiw49aCK51Kkt2Z7qJ4ZTL6UFBEekkMN46IDEkpiMuipRGqCliFJseB27p6+SpWdudvSPA9Tzgc9ZS9N6fFslkdV8TBzufxyKESqiKQJuYY9qnJz6odYvgIIesykihdr5otiu4DSg2sETI4mHKWuJRCUFs3y7DBVo1QlUCc3pakyuV2USBMYgHs4SrSMUJVC2nVKwCzOW33t3FJr5ex/s7VH/mD5wF3fmPst15Sng6M79mmDyVnh2XZ7o5Ds394LB4AJKhoF3qQ0uJQG9mHCuIBuCHXBgRliha7IClBpUoFJ57JQl56zqfRJxy5huykM6PLl16DRnrGQx1nFeBpCHBvOG5si7/V+O7x6p7WzVzL4s8hbsTRNDikXbrRcpyKvqpQcRADU0QV0eDqsDgUF1pZublmKKpsdnhZImVBXblVhsnPYvnIiDOMQYX44JqLwnahwiyTwlKNelCI8CP76fDteX6DMWbzeTRX2trLFYZ4GWMoUS16aZ6coSUz1Y11pcdrp1lLCXjskXaPcBN365nJne+O4v736N/2N5c/Xbr7v7rloJF0fnsmfZwKmNU/2BoXLO7cSg/wAD1xLqhihZp+TPdCG1jgqAUzKg9ItxG7cxiyOErqgVLFC2FYeQDcp4/wQyVh66M0o2Y+ZYQr/HzxG4vJxVndhYkDRSqxOH7zSYifZN/8AuTtVGxRHGWAiNcRCHVuFuoSAnloQBbOZRNSbD5gc7pFV/NZeWcH1yAMCsF/FZuX6U77sMmtIa02f7ASFyeTV0j3FurFjmYx15H0VMSBr18NvHG6vfr29uPDPw7OPPJAYrozGi5WJQTZ9hc8Dapp6LmgsORuPp/YHiRihU/pKji1+3vvbjW2KRW/W/PX1u0/ffe7Ex9F1nglw3+qcZwTm79122+CmA2/8RiLWX8C5tU/PIwm1th9N0wV1ocoCSlDdBXglRiFdwNZAS8FwycRO+MtBTqbAcMbOrjaJYBDZpyyaXGcLeGD2ThyyYm3e2vOnqDNMRecRnqVy5CrCULBZHnhD+BSqw8nANRWohgdGc5vJIzYu8h/AovFL2QJlyJXWstksYcrkbD+sfFJxTV5adri+rYpiPPAcRS1H/VGF3lJ4+lMsL5/nuWGKkw4jO6XkBai/KDx3x3KFpQ/fradCMTts6xR3/PtvGmRNL5uLXuJfz+v2jMBknMKPtbqLiVz//mw8tk8mSkPqNqAxPO4RTGukjmyz8MetTQJIRR80wEpCQmVCIWKspbYz8Bm8PkPE1eqJvosXKt9Vy+oClBFv5pAlRkbkguF5lhUGWbFgFBDYa4sAcpsn4W5zDWmFqoWgTSxcrFHMXXJqqdZ2gOiYPLLw4EjOLi+h9WIKnMVXu8gaFgWoZRKNsBRDdrLaKRmvyIrcjwXiaGXMLilX3BtTNVgJOpDrt98cgHPf/pjn7NdnBKbuvhAUW+mwc0cm1nsT7kYM8cijIvYJqjxJgVRHnNZneXptWnYmsEopScBqFQbriHdpsAFGHpKJoZyNsycHgEqfzEMdik5In0rkiE7wm4B3ERIYulZdl9BEeQ4pi2tl4uhaHKvj1AR90cY9tTSvSBtUOmZ/NWsHM9AmNuc21cGa6UnbZNINU9F6bAh3HBSY5ngtftJKsEUenwx8JFjztFvAlXMBCm5S+P/RIvkI0U0ujL/PGMxqu+1vpeK3D6i2XxpB8sGWmqQ0buHIllquoZ0agtpYkrdGzYDdLOwi7HN8uEx+K3X1CHG4MBXg6VFPCo+p9ruy0aVJKr9IFC0jXSC6zaEJQgALx3MDnMNFp2UJMk0it5jsWDgAvys7QNcaQz5Ol9K2N4aBgwNefuA4EyGEp2d4rNT4cN6GlRGPDO9gTyVdKEypmyRS4ehQULoFhWbkT+3j++o1+801p8pfGEjSCmnbz2i75dSp8PVHD9hmJ/k2Sk9XqsQbZbMpbSOPzcjkJmE8DqA5l5jcQH7KViuTPLQfv5m03hoLZaUcudgkbC3NQCkJq4tC5ZzhgCMMlXDdJIwlx30Xp4NKyUipaCID5ayTnZoGzMh9EZGkzB8xiSggToI2bHy6uUzo5wGeX4apUx1z9l8bDbcwPMTCJNxsgC+WClYoZpgmULO8O3lylWokVctjpYx3Ho/1UNiu//HWX9x95pbHeMAzGsfn8qRnTJk0Imx7/oIX9I4TvZ9VzE/rNfTIwiwDJC1xG4qcqKTsCJl8tz2C2g8rbGE8z2/iCmP2KzOd1FMuJS1Xfl0Z2TLWedwGS9r1gDbG1tZII9liga0oVmtExqGyy/bwvGcoS8qVxKieSC9uIEp3xCvu4K5PaJRE5+L8o+bzNAeP53YlcMB3VhadXNUTIfpQrMJmuob6UZWiA6eRJs6T91B6tIK76xSjNK7IGI9F4OE3nblI3Xou8XhW13o2YPIQmSwpbf07vEH/LVIY5LZq4G+cX+bxF/DAIQbJpUoCwOw4hSK2ZbwYdiUlNqEqxSHzRCLECuWwdg5sfi+Rt1PJdm2R51QuNmK2gZLTo8S4vEWK7/E8eGvAwsX6xG47GLIKbbl8UrnW+CfZpsWyUoRi3D/gabYh4amQpxA1eTxUMLrH+pgtHZ5ixGOGUcSxjQkMZLnmCDlJSkXZYBJVkaGyPXUfKUHJOO1Oeyvx4ee3kuUzQfVZgXkPcvPKtHcHdWO7q7Veuo5nRN6YCnHDLBqhWJIy7ag/Y5WSIimwRQZFjyseUBCSic+Aix2KAngHVCVqkfdP5aukTSf7Nto5aw0qhzRIoFoniNvE8VAdVqacFBEFmgERKuI2QAh5i7W6d12PL9InSVmy4UnnsQl47xLf7KUKrpZ8UrKZ4zcbtA2lZwJNl1nGSjcc/LAFadktJoM0WxiD8w37Mf803OP5X/71NBF9VmDecsst/rVvfu39G+3O8tK57b0dH9WdTo+NeHaAQdCjo+4/yaOZAKeErMzyvK48tlud1bIK9dXJLWsQY9oHEpTgjDRFWKwYbwuf73ZxAnOBx2zUeRww6nIJyi9iN2rZndJM4kpXgZU6uQtonMIm1Ye/TBppyjKHFHoKcbT32DfAbuQhKWjVcs8xm9hXQxHjqe74k3kCBIHvJTRwrQQrwlnOsdxCyyG0KMo538Num1I3jwRJnjt1gW3PCkz6EvrbK2d5LO8dmBd7pcLLdbe4wlOIsN8KuOJ83HnKVd1Aq/W3B2SdZ9wyhiYLZmWM99d9W9hkjSH+0XIBAMSH9Yf/zAGrB1TvKso/ChuHGrWoVr/LwS7Aesg5V94UNi+lR05wSJPPYrYRYSqi0iiMWDxf5eE3LBLiXh0mkZPhDkg8UWjS8hwpwevcGn5g8oClEG00eSQIo8QpTACu5HeW08nggaT//C0YeqZz5Blrs7s3nCpNsCI5PcNTZ9844CHLeuinFA+t1VAhJXldpIU2QKbFAK4iN1XeTNlvBybKNlTO2jyLeJXHqugEGImeHFUJLMQeGqzyYciV5SWuKdDkH5ALTy43OPP5CaB26XddhQ8OULVHKYsZbEnJdS0vbOOlklNDitgw0R257fQMaT1ecqySZZkFogBSX2PZxSg5wVrgm4rBSrqbX+n5jZvf9dFbV3SvC2l7tpRpx+67bzA/NXxrxgsWcIdMu8WrjjqQXIy8NFR9kEUmIJThvrAxgAJqOAYI7qJ4NNFK9Tq72rH9E9h/woHBdXhwDiLVAefUJ3ZLOWGBF1QpMHGtIRsFmKhXTFYzVOJSSpiMTeXtaOGQ9umxiNSawh5m+QGTY5xqXHJ05JlFk5hRonwtXxD1nuSp7Xp2icBOc0MePNcF7q+m/XD1QgJxty3iRM9qmwOjsuffn48Hf886KQZVkCGtoBqxP8f22MM4uu+88TODzkuUsUUuq/BmXGG7AwAlCUrHClAHjSZDNBEEGDqKyxJo4EnTs77coh6oWknKekqSXkrWEkWjHzmKjSpai8pZYd1hQS45s5ssytXCpRbnqiSNJkQSJ4VceQmc7Oc2O1BlB3ZLuAwFi1wVbtxknergSzfY57fUjQtte9ZgqkNrvdQ2z0P5k1y8X1fsRHXtFFBGNQEoRpTvDhEhygZjdPtEwHrKQbRcPlppdQaWq5CYWGzkMnBnOtap4903NGBNhkDvXErACSgtI9BnLZKVjahlCLv3Vi7t0maPVBaKP8Hm5VKkYAwr2Sg7g4Kk6I1s1C7nLqyRKL3SQOlJ2yheIS2O7rc3mpSn+ZP4YPOkN+fmmxpzQW3PWmaqN3eeOjV427ED6/1k8li7HzuqwdzdRE38d5v2CttoFZV45+5Rj72rgKJq7uGudyExgaoJIYp3Xh0OFTuVPFTYTRSsbRdo95t26KZMVa0ik3Nha1saa1Q5TBEdsdFVkp4VkssArChQydzSjFeJoEjT3TuCwx+bl2fEUANr7U+9ePv3fvqjX57X5S/E7TmhTHVsqR3bqGbD/zI9FKtptaOQktxTiTMXnnLfoVj9JmAcqgKJQx02okP9k280xuqyrpOh+lGizzkW+MDYs0tUKwDd9OA3fuc3RTS06Sq6P+LRadVSwKSYyTs1gpaaxD3XJAY6hLmkhbmK0uDX57JQM9TOOg4bJnbJA3twjPCA1tbaV2Ox3n9qnN04petfqNtzQpnq3NdPnPDf+OrDq4VMZpoH17x0o47X5fywSkFhePkjZcZpnzsAatAdrhEO7hg55eGSsEPcawjTjPO86ABNAslhQOWrq0/rgIyUH4Ep0EXBcgN2MDtUhEpODC2ZGOK9jUbdoGLKHrTVAxM5Clsk0F7Jr4XNaoqc3sl6mGCtZ9rrkN6xeCoba/96cSz2lXfddMt3Cnl9X3F+zsBUL64vTvVSQ4PVYin3xlQqXVnfJlcWUDS4AtDR03nQRAk6i73aB0guV4YvEWzIP35XiKuFoqTcVCkiyisSdYoadd0oOy4CUxfSJeVNqmCbTlTJhmetgbLtZIJskMaoSMxBVqvtha1WMCBLTBRFVXTPDWTpBqvBBTRpMQC5cjqXaM2VCr3P/NgvfvaC8/jQ5CdszymYRFKCN12ZY5VjtjVUKf5AHh62iZvHxRpBQAPt/DJ80PA5Wcpn7XcAwjUFkqIVWqwrj4vWRfbw99bJa1VStQpNuKUOHOdOcn8EIiczc5SIVSHzToivk7ezBUBa5aXV1NRstQNjZM1DhVqEq3tGEwlzB7egPFMu+pPg8RL1c4/mkt2P7hkd/Nkbf+GzT3pYN6decNtzCqZ69zd3L/ffMTl6YpChnke5/IqxajkmV1lb7hxRn0Yv+u8GwwGpUWWn+wmQ5HNVbVulaipS0gckyT85whvk6Si+P6DmgWaBSpbKZ1pB/o0Re/SIiCxiVsh0UTrmZCVDJRRSRGCbokInXzk1uiV/uTaS27WlpGX53c1et376r/Op1q/uy6T++jW/+GcXnNvONfZb/HF9+hb7n/Wu3/vg1SObfvXn89U9/4ulRqoPntu2E6w92VbwGl+sWC4lHpxcdRTKd727GgQKAMNOxS4V/8wSI90mYtJBkcnxWQWWVOotQcL09EjKrr9qgjWfWWxDqnSdq3EOywwob1NAq+EuTmN1DNjxdW7CRHEsml4qo9A5OnyeEL+1dKZfX/lEOdP9xHt//YsL/BSh/KxH43tzgecNTDV/7oNX5yqxsZ8h7eOjuaGp6Q0KKj14ZsNOLTWNtBznLlMSmPyo2lxEBdKRYiMzQXVmlUVQ5HOV8mpSUpSBono/Z5cbGPgxm4V1pqGoM2tte3gRIMm3fcnskAtdCQoXY+X6SjpTLFVBbF3byVrRJ4Ut2s2tbmNj/vNJr/4fev3G1z78O7fSuotve17B1HDM3XAsVa2OvrqfqH6kMDz+g+lcNbZCDYQzi9ssFOoQoejihYE6MNzVGCrE4bf1yIZLA6bsPNaLqOwLCV5lFBbV75H0lWKlJ8zKPfgQjv0TC9tEVMyuhCKHWcWlxThyJUp7ldzcwqZULtA0kREVfpIvttOsdbbry/cOWpufmsgNPvnej/3thuC9+GCMWvy8g6nbMDre7//yK8d6jfJ7wnj1PcniyFVpQlJyki9R4P70YpMB19qTlE3xsMhJMgkKsNeIXgFaMg6eJ7S1T5TsEvFAVB6bR5YaTibPsiRiCESFRhMQ12qsDaGCiCixQkiuoiX1eJxajc3Odm31HhKRP5X2a3/W3PP103MXqFdH4/fdbt8TMHcbw4DF9m1dv7flp9/ZixV+MpWrHMuXKrlYjOV/iozwUh5tAs1TnhzXOChMbp5dcSc4hatDjHc53xWR0Xdl9Ok82bNyQslNqLxYJYT1MP7brdpWu7lxe69X/29pr/mXzZEXBoi74+vGa/fL9+qdsfY+98uvHTnXzFxX95Nvi6eLP0BOzXQsnuFh8cQvyR+SbSg4RWW7r4g4aXL037HaSCbqSP79/wOKg/qzoB3Hf0Db4759+f3z59c3f39+u/H399djDL/e7RPlZb+Q3Hf8PSjO6eVfetkzIJGJ7Ln9DQwsT364K3z8/E37+192UyYWTn1gxSnPxMopxcLKyQWsSzlBu5NBWwhAG1xB/VRI7IIGFYCD66CFXKD9IMClB8BNOcBVAL/f/f/389H/v98u///97TjL788X2bm+PcqddOfzcIxA5LAc8MhEdgwwmhjvbPNku3TkixBwlkoJeH+nGnDpif6PX8AhbuB907BZEpAe0LAd6Nw74Got4BgqaNnO38fAqZrL/GzMDySV2Z65CAp+Ad7XDJxYGzkAAA4wXZ9rNZHpAAAAAElFTkSuQmCC" width="115" height="150"/></svg>',
        // Um den Hals
        'bowtie'   => '<svg viewBox="0 0 72 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 6 L30 16 L30 24 L8 34 Z" fill="#8a4fd0" stroke="#5f2fa0" stroke-width="3.5" stroke-linejoin="round"/><path d="M64 6 L42 16 L42 24 L64 34 Z" fill="#8a4fd0" stroke="#5f2fa0" stroke-width="3.5" stroke-linejoin="round"/><rect x="29" y="13" width="14" height="14" rx="4" fill="#a875e0" stroke="#5f2fa0" stroke-width="3"/></svg>',
        'lifebuoy' => '<svg viewBox="0 0 100 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><ellipse cx="50" cy="24" rx="42" ry="16" fill="none" stroke="#f4f7f9" stroke-width="14"/><ellipse cx="50" cy="24" rx="42" ry="16" fill="none" stroke="#e0533d" stroke-width="14" stroke-dasharray="24 42"/><ellipse cx="50" cy="24" rx="49" ry="23" fill="none" stroke="#9aa7b0" stroke-width="2.5"/><ellipse cx="50" cy="24" rx="35" ry="9" fill="none" stroke="#9aa7b0" stroke-width="2.5"/></svg>',
        'medal'    => '<svg viewBox="0 0 56 76" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 2 L24 34 L32 34 L46 2" fill="none" stroke="#c0392b" stroke-width="9" stroke-linecap="round"/><circle cx="28" cy="52" r="20" fill="#f4cd54" stroke="#c8901f" stroke-width="4"/><circle cx="28" cy="52" r="12" fill="#ffe9a8"/><path d="M28 44 l2.6 5.6 6.2 .7 -4.6 4.3 1.2 6.1 -5.4 -3 -5.4 3 1.2 -6.1 -4.6 -4.3 6.2 -.7 Z" fill="#e0a92e"/></svg>',
        'scarf'    => '<svg viewBox="0 0 64 60" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="6" y="5" width="52" height="16" rx="8" fill="#d64545" stroke="#a32e2e" stroke-width="4"/><path d="M19 7 v12 M29 7 v12" stroke="#ee8080" stroke-width="4"/><path d="M40 19 l5 25 a4 4 0 0 1 -4 5 h-8 a4 4 0 0 1 -4 -5 l4 -25 Z" fill="#d64545" stroke="#a32e2e" stroke-width="4" stroke-linejoin="round"/><path d="M33 43 v6 M39 43 v6" stroke="#ee8080" stroke-width="3.5"/></svg>',
        // Umhang liegt HINTER dem Avatar: die Kreis-Aussparung (even-odd, r47 ≈ Avatar-Bubble bei der
        // acc-k-cape-Positionierung) lässt nur Schulter-Flügel und den wehenden Saum rausschauen.
        'cape'     => '<svg viewBox="0 0 120 110" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill-rule="evenodd" fill="#c0392b" d="M60 3 C36 3 16 14 9 32 C2 50 2 74 9 95 Q32 110 60 104 Q88 110 111 95 C118 74 118 50 111 32 C104 14 84 3 60 3 Z M107 52.2 a47 47 0 1 1 -94 0 a47 47 0 1 1 94 0 Z"/><path d="M9 32 C2 50 2 74 9 95 Q32 110 60 104 Q88 110 111 95 C118 74 118 50 111 32" fill="none" stroke="#8e2620" stroke-width="4" stroke-linejoin="round"/></svg>',
        // Im Gesicht
        'monocle'  => '<svg viewBox="0 0 84 46" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="63" cy="16" r="12" fill="#cfe6ee" fill-opacity=".35" stroke="#c8901f" stroke-width="4"/><path d="M63 28 q7 8 3 16" fill="none" stroke="#c8901f" stroke-width="3" stroke-linecap="round"/><path d="M57 11 q3 -3 7 -2" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" opacity=".8"/></svg>',
        'sunglasses' => '<svg viewBox="0 0 84 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M9 12 L2 5 M75 12 L82 5" stroke="#0e1118" stroke-width="4" stroke-linecap="round"/><circle cx="21" cy="18" r="11" fill="#1f2430" stroke="#0e1118" stroke-width="3.5"/><circle cx="63" cy="18" r="11" fill="#1f2430" stroke="#0e1118" stroke-width="3.5"/><path d="M32 15 q10 -7 20 0" fill="none" stroke="#0e1118" stroke-width="4"/><path d="M15 13 q4 -3 8 -1" fill="none" stroke="#8b93a5" stroke-width="2.5" stroke-linecap="round"/></svg>',
        // Famous: goldene Stern-Sonnenbrille (Celebrity-Look)
        'starshades' => '<svg viewBox="0 0 84 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M9 12 L2 5 M75 12 L82 5" stroke="#d9a520" stroke-width="4" stroke-linecap="round"/><path d="M32 16 q10 -6 20 0" fill="none" stroke="#d9a520" stroke-width="4"/><g transform="translate(21,17)"><path d="M0 -11 L2.7 -3.7 L10.5 -3.4 L4.4 1.4 L6.5 8.9 L0 4.6 L-6.5 8.9 L-4.4 1.4 L-10.5 -3.4 L-2.7 -3.7 Z" fill="#1f2430" stroke="#d9a520" stroke-width="2.5" stroke-linejoin="round"/></g><g transform="translate(63,17)"><path d="M0 -11 L2.7 -3.7 L10.5 -3.4 L4.4 1.4 L6.5 8.9 L0 4.6 L-6.5 8.9 L-4.4 1.4 L-10.5 -3.4 L-2.7 -3.7 Z" fill="#1f2430" stroke="#d9a520" stroke-width="2.5" stroke-linejoin="round"/></g></svg>',
        'glasses'  => '<svg viewBox="0 0 84 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 14 L2 8 M74 14 L82 8" stroke="#26404a" stroke-width="4" stroke-linecap="round"/><circle cx="21" cy="18" r="11" fill="#cfe6ee" fill-opacity=".35" stroke="#26404a" stroke-width="4"/><circle cx="63" cy="18" r="11" fill="#cfe6ee" fill-opacity=".35" stroke="#26404a" stroke-width="4"/><path d="M32 16 q10 -6 20 0" fill="none" stroke="#26404a" stroke-width="4"/></svg>',
        // HINTER dem Avatar (Slot „back"): eine aufgehende Sonne, die HALB hinter der Bubble hervorlugt.
        // Die Bubble-Fläche wird per <mask> ausgestanzt (Kreis 70,90 r50 = Avatar-Position im 140×148-Kasten,
        // CSS-Mapping in .acc-k-sunrise) → echtes „dahinter"; die Strahlen drehen sich langsam (sunrise-spin)
        // und tauchen am Avatar-Rand auf/ab. Sonnen-Mittelpunkt (97,48) liegt auf dem Bubble-Rand oben RECHTS (~33°).
        'sunrise'  => '<svg viewBox="0 0 140 148" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs><mask id="acc-sunmask"><rect x="0" y="0" width="140" height="148" fill="#fff"/><circle cx="70" cy="90" r="50" fill="#000"/></mask></defs><g mask="url(#acc-sunmask)"><g class="sunrise-rays" stroke="#ffcb4a" stroke-width="4.5" stroke-linecap="round" stroke-opacity=".85"><line x1="125.5" y1="48" x2="136" y2="48"/><line x1="121.7" y1="62.3" x2="130.8" y2="67.5"/><line x1="111.3" y1="72.7" x2="116.5" y2="81.8"/><line x1="97" y1="76.5" x2="97" y2="87"/><line x1="82.8" y1="72.7" x2="77.5" y2="81.8"/><line x1="72.3" y1="62.3" x2="63.2" y2="67.5"/><line x1="68.5" y1="48" x2="58" y2="48"/><line x1="72.3" y1="33.8" x2="63.2" y2="28.5"/><line x1="82.8" y1="23.3" x2="77.5" y2="14.2"/><line x1="97" y1="19.5" x2="97" y2="9"/><line x1="111.3" y1="23.3" x2="116.5" y2="14.2"/><line x1="121.7" y1="33.8" x2="130.8" y2="28.5"/></g><circle class="sunrise-glow" cx="97" cy="48" r="25.5" fill="none" stroke="#ffd154" stroke-width="7" stroke-opacity=".3"/><circle cx="97" cy="48" r="22" fill="#ffd154" stroke="#f6b73c" stroke-width="3"/><circle cx="97" cy="48" r="14" fill="#ffe08a" fill-opacity=".9"/></g></svg>',
        // Unterm Avatar (Touch Grass): Wiesenstück mit Grasbüscheln, auf dem die Bubble sitzt
        // ---- Kaufbarer Schmuck (kein Achievement – siehe shop_items()) ----------------------
        // Zwille: Y-Ast mit Gummiband.
        'zwille'   => '<svg viewBox="0 0 64 92" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M32 88 C 31 70, 31 58, 32 46 C 22 36, 14 24, 10 12" fill="none" stroke="#8a5a2b" stroke-width="9" stroke-linecap="round"/><path d="M32 46 C 42 36, 50 24, 54 12" fill="none" stroke="#8a5a2b" stroke-width="9" stroke-linecap="round"/><path d="M10 9 C 20 22, 44 22, 54 9" fill="none" stroke="#c94f4f" stroke-width="4.5" stroke-linecap="round"/><rect x="4" y="2" width="12" height="13" rx="4" fill="#e0a92e" stroke="#c8901f" stroke-width="2.5"/><rect x="48" y="2" width="12" height="13" rx="4" fill="#e0a92e" stroke="#c8901f" stroke-width="2.5"/><path d="M28 62 l-6 4 M33 74 l6 3" stroke="#5f3d1c" stroke-width="3" stroke-linecap="round" opacity=".5"/></svg>',
        // Vogelnest: geflochtenes Nest mit drei Eiern, sitzt oben auf dem Kopf.
        'vogelnest'=> '<svg viewBox="0 0 100 62" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><ellipse cx="34" cy="28" rx="9" ry="11" fill="#f6f1e3" stroke="#cfc3a6" stroke-width="2.5"/><ellipse cx="52" cy="24" rx="9" ry="11" fill="#eaf4f8" stroke="#b9d2dc" stroke-width="2.5"/><ellipse cx="68" cy="29" rx="8" ry="10" fill="#f6e9d8" stroke="#d8c4a4" stroke-width="2.5"/><ellipse cx="50" cy="44" rx="43" ry="15" fill="#9a6631" stroke="#5f3d1c" stroke-width="3.5"/><path d="M10 42 C 26 32, 74 32, 90 42 M16 48 C 34 40, 66 40, 84 48" fill="none" stroke="#5f3d1c" stroke-width="3" stroke-linecap="round" opacity=".55"/><path d="M8 46 l-6 3 M92 46 l6 3" stroke="#8a5a2b" stroke-width="3.5" stroke-linecap="round"/></svg>',
        // Wanderstock: knorrig, mit Knauf und frischem Trieb.
        'wanderstock' => '<svg viewBox="0 0 44 96" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M22 10 C 16 32, 26 46, 20 66 C 17 78, 20 86, 22 92" fill="none" stroke="#8a5a2b" stroke-width="9" stroke-linecap="round"/><circle cx="22" cy="10" r="8.5" fill="#b07c42" stroke="#5f3d1c" stroke-width="3"/><path d="M21 42 C 28 38, 32 34, 36 28" stroke="#8a5a2b" stroke-width="5" stroke-linecap="round" fill="none"/><circle cx="37" cy="26" r="4.5" fill="#7bc47f" stroke="#3f8b46" stroke-width="2"/><path d="M20 58 l-5 3" stroke="#5f3d1c" stroke-width="3" stroke-linecap="round" opacity=".5"/></svg>',
        // Bernstein-Kette: Lederband mit leuchtendem Stein.
        'bernstein'=> '<svg viewBox="0 0 100 66" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 4 C 24 26, 76 26, 94 4" fill="none" stroke="#5f3d1c" stroke-width="4.5" stroke-linecap="round"/><path d="M50 23 v8" stroke="#5f3d1c" stroke-width="4"/><path d="M50 30 l13 10 -5 17 -16 0 -5 -17 Z" fill="#e8963c" stroke="#a95f14" stroke-width="3" stroke-linejoin="round"/><circle cx="46" cy="43" r="3" fill="#ffd9a0" opacity=".85"/><path d="M54 37 c3 2 4.5 5 4 8.5" stroke="#ffd9a0" stroke-width="2.5" fill="none" stroke-linecap="round" opacity=".7"/></svg>',
        // Hausbaum (Slot back): dieselbe Masken-Technik wie die aufgehende Sonne – der
        // Kreis (70,90 r50) stanzt die Avatar-Fläche aus, der Baum steht dadurch DAHINTER:
        // Krone lugt oben heraus, der Stammfuß unten.
        'baum'     => '<svg viewBox="0 0 140 148" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs><mask id="acc-baummask"><rect x="0" y="0" width="140" height="148" fill="#fff"/><circle cx="70" cy="90" r="50" fill="#000"/></mask></defs><g mask="url(#acc-baummask)"><path d="M70 146 C 68 110, 68 78, 70 46 M70 74 C 58 66, 50 58, 46 46 M70 62 C 82 56, 90 48, 94 38" fill="none" stroke="#8a5a2b" stroke-width="10" stroke-linecap="round"/><g fill="#7bc47f" stroke="#3f8b46" stroke-width="3.5"><circle cx="44" cy="32" r="24"/><circle cx="96" cy="30" r="24"/><circle cx="70" cy="17" r="24"/></g><circle cx="70" cy="30" r="20" fill="#8fce8f"/><circle cx="58" cy="12" r="3.5" fill="#e8f5e0" opacity=".8"/><circle cx="100" cy="22" r="3" fill="#e8f5e0" opacity=".7"/><circle cx="88" cy="40" r="3.5" fill="#f4cd54"/><circle cx="50" cy="44" r="3" fill="#ff9ad5"/></g></svg>',
        // Lorbeerkranz (Belohnung „Hundert Tage"): FRONTAL, also wie er wirklich auf dem Kopf sitzt –
        // ein Reif in perspektivischer Verkürzung (breite, flache Ellipse), nicht das flache U-Symbol
        // aus Wappen. Vorn liegt er tiefer als hinten, deshalb zwei Bögen: der hintere schmal und
        // gedeckt, der vordere kräftig und mit den größeren Blättern. Gold in drei Tönen, damit die
        // Blätter nicht zur Fläche verschwimmen; in der Mitte vorn der Knoten des Bandes.
        'lorbeer'  => '<svg viewBox="0 0 120 46" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#b8860f" stroke-width="3" stroke-linecap="round"><path d="M8 22 A52 17 0 0 1 112 22"/></g><g fill="#c9a227" stroke="#8a6a10" stroke-width="1"><ellipse cx="20" cy="16" rx="6" ry="3.2" transform="rotate(-58 20 16)"/><ellipse cx="34" cy="9" rx="6" ry="3.2" transform="rotate(-36 34 9)"/><ellipse cx="50" cy="5.5" rx="6" ry="3.2" transform="rotate(-16 50 5.5)"/><ellipse cx="70" cy="5.5" rx="6" ry="3.2" transform="rotate(16 70 5.5)"/><ellipse cx="86" cy="9" rx="6" ry="3.2" transform="rotate(36 86 9)"/><ellipse cx="100" cy="16" rx="6" ry="3.2" transform="rotate(58 100 16)"/></g><g fill="none" stroke="#e8c14a" stroke-width="4.5" stroke-linecap="round"><path d="M8 22 A52 20 0 0 0 112 22"/></g><g fill="#f4d76a" stroke="#a8801a" stroke-width="1.2"><ellipse cx="16" cy="27" rx="8" ry="4.2" transform="rotate(38 16 27)"/><ellipse cx="30" cy="35" rx="8" ry="4.2" transform="rotate(22 30 35)"/><ellipse cx="46" cy="40" rx="8" ry="4.2" transform="rotate(8 46 40)"/><ellipse cx="74" cy="40" rx="8" ry="4.2" transform="rotate(-8 74 40)"/><ellipse cx="90" cy="35" rx="8" ry="4.2" transform="rotate(-22 90 35)"/><ellipse cx="104" cy="27" rx="8" ry="4.2" transform="rotate(-38 104 27)"/></g><path d="M60 38 l-6 6 h12 Z" fill="#e8c14a" stroke="#a8801a" stroke-width="1.2" stroke-linejoin="round"/><circle cx="60" cy="38" r="3.6" fill="#fdf0b8" stroke="#a8801a" stroke-width="1.2"/></svg>',
        // Klemmbrett (Belohnung „Anstoß"): Brett mit Klemme und drei Zeilen – das Werkzeug derer,
        // die etwas aufsetzen, statt nur mitzumachen.
        'klemmbrett' => '<svg viewBox="0 0 68 88" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="4" y="12" width="60" height="72" rx="6" fill="#c98f4e" stroke="#8a5a2b" stroke-width="4"/><rect x="12" y="24" width="44" height="52" rx="3" fill="#fbf7ee"/><rect x="22" y="3" width="24" height="16" rx="4" fill="#8a939f" stroke="#5f6874" stroke-width="4"/><g stroke="#b9bfc9" stroke-width="4" stroke-linecap="round"><path d="M20 38 h28 M20 50 h28 M20 62 h18"/></g></svg>',
        // Wackelaugen: aufgeklebte Klebeaugen, die beiden Pupillen rollen versetzt nach – dadurch
        // wird der Avatar selbst zum Gesicht, statt eins zu tragen. Sitzen bewusst in der oberen
        // Hälfte (siehe .acc-k-wackelaugen), damit die Initialen darunter lesbar bleiben.
        'wackelaugen' => '<svg viewBox="0 0 46 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="#ffffff" stroke="#39404d" stroke-width="2"><circle cx="12" cy="12" r="10.5"/><circle cx="34" cy="12" r="10.5"/></g><circle class="wa-p" cx="12" cy="12" r="4.4" fill="#22262e"/><circle class="wa-p wa-p2" cx="34" cy="12" r="4.4" fill="#22262e"/></svg>',
        // Ringplanet (Slot back, aber nach vorn geholt): Der Avatar IST der Planet, der Ring läuft
        // um ihn herum – hinten dahinter, vorn davor. Beides steckt in EINEM SVG:
        //   1. der volle Ring, per Maske um die Bubble-Fläche beschnitten → das ist der hintere Teil,
        //   2. derselbe Ring nur als UNTERER Bogen, ungemaskt darüber → das ist der vordere Teil.
        // Die Maske nutzt dieselbe Geometrie wie Sonne und Hausbaum (Kreis 70,90 r50 im 140×148-Kasten),
        // die Neigung liegt in der rotate-Gruppe. Der vordere Bogen kreuzt bewusst das untere Drittel,
        // damit die Initialen frei bleiben.
        'ringplanet' => '<svg viewBox="0 0 140 148" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs><mask id="acc-ringmask"><rect x="0" y="0" width="140" height="148" fill="#fff"/><circle cx="70" cy="90" r="50" fill="#000"/></mask></defs><g transform="rotate(-18 70 90)" fill="none" stroke-linecap="round"><g mask="url(#acc-ringmask)"><ellipse cx="70" cy="90" rx="66" ry="21" stroke="#e0b978" stroke-width="7"/><ellipse cx="70" cy="90" rx="55" ry="17.5" stroke="#c08f45" stroke-width="3.5"/></g><path d="M4 90 A66 21 0 0 0 136 90" stroke="#e0b978" stroke-width="7"/><path d="M15 90 A55 17.5 0 0 0 125 90" stroke="#c08f45" stroke-width="3.5"/></g></svg>',
        // Papierflieger (Slot back): zieht seine Bahn hinter dem Kopf. Die Faltkanten sind zwei
        // Flächen in verschiedenen Grautönen – ohne sie sieht ein Flieger von der Seite wie ein
        // beliebiges Dreieck aus. Die Flugbahn selbst steckt in .acc-k-papierflieger.
        'papierflieger' => '<svg viewBox="0 0 88 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 8 L80 22 L28 30 Z" fill="#eef1f6" stroke="#8b95a8" stroke-width="3" stroke-linejoin="round"/><path d="M28 30 L36 44 L48 26 Z" fill="#ccd4e0" stroke="#8b95a8" stroke-width="3" stroke-linejoin="round"/></svg>',
        // Herzballon: hängt an einer geschwungenen Schnur in der Hand und wippt (CSS).
        'ballon'   => '<svg viewBox="0 0 44 96" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M22 40 C 13 52, 16 66, 19 76 C 21 84, 15 88, 12 92" fill="none" stroke="#c9b8a0" stroke-width="3" stroke-linecap="round"/><path d="M22 6 C 33 6, 39 14, 39 21 C 39 31, 28 37, 22 46 C 16 37, 5 31, 5 21 C 5 14, 11 6, 22 6 Z" fill="#e0607a" stroke="#a83450" stroke-width="3.5" stroke-linejoin="round"/><path d="M22 46 l-4 5 h8 Z" fill="#a83450"/><path d="M13 17 q4 -6 10 -6" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" opacity=".6"/></svg>',
        // Regenwölkchen: schwebt überm Kopf und tröpfelt drei Tropfen auf den Avatar. Die viewBox
        // reicht bewusst weiter nach unten als die Wolke selbst – dort fallen die Tropfen hinein,
        // ohne dass das SVG sie abschneidet (Fallweg + Ausblenden stecken in style.css).
        'wolke'    => '<svg viewBox="0 0 104 80" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g class="wolk-luft"><g fill="#dbe7f2" stroke="#7b93a8" stroke-width="4" stroke-linejoin="round"><circle cx="38" cy="26" r="18"/><circle cx="66" cy="28" r="14"/><rect x="20" y="26" width="64" height="18" rx="9"/></g><path d="M30 18 q6 -8 15 -8" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" opacity=".7"/></g><g fill="#4fa3d8" stroke="#2f7bab" stroke-width="2" stroke-linejoin="round"><path class="wolk-t" d="M32 48 c4 6 6 9 6 11 a6 6 0 0 1 -12 0 c0 -2 2 -5 6 -11 Z"/><path class="wolk-t wolk-t2" d="M52 50 c4 6 6 9 6 11 a6 6 0 0 1 -12 0 c0 -2 2 -5 6 -11 Z"/><path class="wolk-t wolk-t3" d="M72 48 c4 6 6 9 6 11 a6 6 0 0 1 -12 0 c0 -2 2 -5 6 -11 Z"/></g></svg>',
        'gras'     => '<svg viewBox="0 0 120 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><ellipse cx="60" cy="26" rx="56" ry="8" fill="#66bb6a"/><g stroke="#2e7d32" stroke-width="4" stroke-linecap="round" fill="none"><path d="M12 26 C 11 18, 9 14, 5 10"/><path d="M22 27 C 22 18, 24 13, 27 8"/><path d="M38 27 C 36 20, 33 16, 30 13"/><path d="M92 27 C 94 18, 92 13, 89 8"/><path d="M104 26 C 105 18, 108 13, 112 9"/><path d="M84 27 C 85 21, 88 17, 91 14"/></g><g stroke="#43a047" stroke-width="4" stroke-linecap="round" fill="none"><path d="M17 26 C 17 20, 16 16, 13 12"/><path d="M31 27 C 31 20, 32 15, 34 11"/><path d="M98 26 C 98 20, 99 15, 101 11"/></g><circle cx="46" cy="29" r="3" fill="#f4cd54"/><circle cx="74" cy="30" r="3" fill="#ff9ad5"/></svg>',
    ];
    $out = $svg[$key] ?? '';
    if ($out === '') return '';
    // Feste Grundgröße aus der viewBox mitgeben. Ohne width/height hat ein Inline-SVG keine
    // eigene Größe: Firefox rendert es dann in der Standardgröße (bis über die ganze Breite),
    // solange das Stylesheet beim Seitenaufbau noch nicht greift – der Schmuck blitzt riesig
    // auf und verschiebt kurz das Layout. CSS (height:100%) überschreibt beides ohnehin.
    if (preg_match('/viewBox="[\d.\-]+ [\d.\-]+ ([\d.]+) ([\d.]+)"/', $out, $m)) {
        $out = preg_replace('/<svg /', '<svg width="' . $m[1] . '" height="' . $m[2] . '" ', $out, 1);
    }
    return $out;
}

/** Trage-Position eines Accessoires: 'head' (Kopf), 'hand' (gehalten), 'neck' (um den Hals), 'face' (im Gesicht). */
function avatar_deco_slot(string $key): string
{
    return match ($key) {
        'lantern', 'coffee', 'trophy', 'stopwatch', 'moneybag', 'magnifier', 'quill',
        'cocktail', 'firefeather', 'scale', 'wrench', 'sparkler', 'bookstack', 'tasse',
        'stamp', 'dart', 'compass', 'zwille', 'wanderstock', 'ballon', 'klemmbrett' => 'hand',
        'medal', 'scarf', 'cape', 'bowtie', 'lifebuoy', 'nametag', 'bernstein' => 'neck',
        'sunglasses', 'glasses', 'monocle', 'starshades', 'wackelaugen' => 'face',
        'sunrise', 'speechbubble', 'baum', 'ringplanet', 'papierflieger' => 'back', // liegt hinter dem Avatar (Sonne / Sprechblase / Hausbaum / Ring)
        'gras' => 'under',   // wächst unter dem Avatar (Touch Grass) – eigener Slot, kombinierbar mit allem
        default => 'head',
    };
}

/** Wählbare Avatar-Farbverläufe: einige frei, besondere an ein Achievement gekoppelt ('ach').
 *  'anim' => true markiert animierte Paletten (alle Special-Farben): CSS-Klasse pal-<key> via
 *  member_avatar_class, Keyframes in style.css. 'tier' => 'legende' gibt zusätzlich das ✨-Badge.
 *  Der 'grad'-Wert landet als kompletter background-Wert in der --av-Variable, url()-Layer sind ok. */
function avatar_palettes(): array
{
    return [
        // --- Frei für alle, bewusst ruhig und UNANIMIERT ------------------------------------------
        // Sie füllen das Grundangebot auf, bleiben aber ruhig: Der Unterschied zwischen „verdient"
        // und „frei" soll auf einen Blick sichtbar bleiben.
        'ocean'    => ['label' => 'Ozean 🌊',    'grad' => 'linear-gradient(135deg, #4fc3f7, #1565c0)'],
        'forest'   => ['label' => 'Wald 🌲',     'grad' => 'linear-gradient(135deg, #81c784, #1b5e20)'],
        'flamingo' => ['label' => 'Flamingo 🦩', 'grad' => 'linear-gradient(135deg, #ff9a9e, #d81b60)'],
        'matcha'   => ['label' => 'Matcha 🍵',   'grad' => 'linear-gradient(135deg, #d8e9b8, #6e9c52 60%, #40632f)'],
        'honig'    => ['label' => 'Honig 🍯',    'grad' => 'linear-gradient(135deg, #ffe08a, #f0a92e 58%, #b5721a)'],
        'lavendel' => ['label' => 'Lavendel 💜', 'grad' => 'linear-gradient(135deg, #e2d4ff, #a98cf0 55%, #6a4bb5)'],
        // Pride, sechs Fassungen – alle FREI: Vielfalt ist keine Belohnung. Bewusst kein zweites
        // Prisma (das ist der drehende Vollkreis) und bewusst still: Wer Farbe zeigen will, soll
        // wählen können, wie laut. „Sanft" ist der weiche Verlauf, „Fahne" und „Bahnen" tragen sie
        // mit harten Kanten (Bahnen senkrecht wie der Regenbogen-Streifen im Pride-Skin), „Trans"
        // ist eine eigene Fahne, „Aquarell" gibt Farbe ohne Symbol, und „Faden" ist Nachtgrau mit
        // EINER Linie – dieselbe Sprache wie der Skin, wo der Regenbogen als Faden auftritt.
        // Der Faden braucht ein SVG: Einen Bogen bekommt man mit CSS-Verläufen nicht hin.
        'pride'    => ['label' => 'Pride 🏳️‍🌈',   'grad' => 'linear-gradient(150deg, #e6535f 0%, #e6883f 20%, #dfc23f 40%, #46b46a 60%, #4585d6 80%, #8f63d9 100%)'],
        'pride_fahne' => ['label' => 'Pride · Fahne 🏳️‍🌈', 'grad' => "linear-gradient(180deg, #e6535f 0 16.67%, #e6883f 0 33.33%, #dfc23f 0 50.0%, #46b46a 0 66.67%, #4585d6 0 83.33%, #8f63d9 0 100.0%)"],
        'pride_bahnen' => ['label' => 'Pride · Bahnen 🏳️‍🌈', 'grad' => "linear-gradient(90deg, #e6535f 0 16.67%, #e6883f 0 33.33%, #dfc23f 0 50.0%, #46b46a 0 66.67%, #4585d6 0 83.33%, #8f63d9 0 100.0%)"],
        'pride_trans' => ['label' => 'Trans 🏳️‍⚧️', 'grad' => "linear-gradient(180deg, #7fd4f5 0 20%, #f6a8c0 0 40%, #ffffff 0 60%, #f6a8c0 0 80%, #7fd4f5 0 100%)"],
        'pride_aqua' => ['label' => 'Pride · Aquarell 🎨', 'grad' => "radial-gradient(62% 62% at 28% 24%, #e6535ff0, #e6535f00 72%), radial-gradient(62% 62% at 74% 22%, #e6883ff0, #e6883f00 72%), radial-gradient(62% 62% at 84% 60%, #dfc23ff0, #dfc23f00 72%), radial-gradient(62% 62% at 44% 52%, #46b46af0, #46b46a00 72%), radial-gradient(62% 62% at 20% 76%, #4585d6f0, #4585d600 72%), radial-gradient(62% 62% at 62% 86%, #8f63d9f0, #8f63d900 72%), #f7f2ec"],
        'pride_faden' => ['label' => 'Pride · Faden 🧵', 'grad' => "url('data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20viewBox=%220%200%20100%20100%22%3E%3Cdefs%3E%3ClinearGradient%20id=%22f%22%20x1=%220%22%20x2=%221%22%3E%3Cstop%20offset=%220.0%22%20stop-color=%22%23e6535f%22/%3E%3Cstop%20offset=%220.2%22%20stop-color=%22%23e6883f%22/%3E%3Cstop%20offset=%220.4%22%20stop-color=%22%23dfc23f%22/%3E%3Cstop%20offset=%220.6%22%20stop-color=%22%2346b46a%22/%3E%3Cstop%20offset=%220.8%22%20stop-color=%22%234585d6%22/%3E%3Cstop%20offset=%221.0%22%20stop-color=%22%238f63d9%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Cpath%20d=%22M9%2071%20Q%2050%2049%2091%2071%22%20fill=%22none%22%20stroke=%22url(%23f)%22%20stroke-width=%229%22%20stroke-linecap=%22round%22/%3E%3Ccircle%20cx=%2250%22%20cy=%2250%22%20r=%2249.2%22%20fill=%22none%22%20stroke=%22%23ffffff%22%20stroke-opacity=%22.16%22%20stroke-width=%221.6%22/%3E%3C/svg%3E') center/100% 100% no-repeat, radial-gradient(closest-side at 50% 44%, #2a2532, #1b1821)"],
        // Engel: Licht, das von oben einschlägt, und Glut am Fuß. Die Strahlen sind bewusst
        // BREIT und weich – schmale Nadeln lassen den Avatar wie ein Medaillon aussehen, und
        // die Glut unten ist der Gegenpol: ohne sie wäre es nur eine Lampe.
        'engel'    => ['label' => 'Engel 😇',    'grad' => 'radial-gradient(circle at 50% 4%, #fffdf4 0%, #ffeba0 7%, rgba(255,196,70,.5) 16%, transparent 40%), conic-gradient(from 152deg at 50% 2%, transparent 3deg, rgba(255,240,200,.20) 3deg 10deg, transparent 10deg 17deg, rgba(255,240,200,.14) 17deg 25deg, transparent 25deg 33deg, rgba(255,240,200,.18) 33deg 42deg, transparent 42deg 49deg, rgba(255,240,200,.12) 49deg 57deg, transparent 57deg 360deg), radial-gradient(120% 60% at 50% 112%, rgba(255,124,32,.52), rgba(255,86,20,.16) 42%, transparent 70%), linear-gradient(180deg, #1b1233 0%, #120b24 55%, #070410 100%)', 'ach' => 'technik_engel', 'anim' => true],
        'cuteness' => ['label' => 'Cuteness 💕', 'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2226%22 height=%2226%22 viewBox=%220 0 26 26%22%3E%3Cpath d=%22M13 20 C 6 15, 4 11, 4 8.5 C 4 6, 6 4.5, 8.2 4.5 C 10 4.5, 12 6, 13 7.8 C 14 6, 16 4.5, 17.8 4.5 C 20 4.5, 22 6, 22 8.5 C 22 11, 20 15, 13 20 Z%22 fill=%22%23ffffff%22 fill-opacity=%22.45%22/%3E%3C/svg%3E') repeat, linear-gradient(135deg, #ffb6d9, #ff6fa5)", 'ach' => 'gt_1', 'anim' => true],
        // Glut: die ausgebrannte Mitte einer Feuerstelle. Kruste und Glutbogen liegen als eigene
        // Ebenen darüber (siehe .pal-ember in style.css); hier steht nur der dunkle Grund.
        'ember'    => ['label' => 'Glut 🔥',     'grad' => 'radial-gradient(closest-side at 50% 46%, #120b06, #090402)', 'ach' => 'streak_30', 'anim' => true],
        'gold'     => ['label' => 'Gold 🥇',     'grad' => 'linear-gradient(135deg, #ffe082 30%, #c8901f 50%, #ffe082 70%)', 'ach' => 'shifts_35', 'anim' => true],
        // Galaxie: Fast-Schwarz, farbige Gaswolken und ein Milchstraßen-Band. Die Sterne sind klein
        // und dicht gestreut (26er-Kachel), damit auch der 23-px-Chip in Listen noch wirkt.
        'galaxy'   => ['label' => 'Galaxie 🌌',  'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2226%22 height=%2226%22%3E%3Cg fill=%22%23ffffff%22%3E%3Ccircle cx=%224%22 cy=%226%22 r=%22.85%22/%3E%3Ccircle cx=%2219%22 cy=%223%22 r=%22.5%22 fill-opacity=%22.8%22/%3E%3Ccircle cx=%2223%22 cy=%2217%22 r=%22.7%22 fill-opacity=%22.95%22/%3E%3Ccircle cx=%2211%22 cy=%2221%22 r=%22.45%22 fill-opacity=%22.75%22/%3E%3C/g%3E%3C/svg%3E') repeat, linear-gradient(58deg, transparent 30%, rgba(226,214,255,.34) 44%, rgba(255,255,255,.16) 50%, rgba(190,150,255,.3) 56%, transparent 72%), radial-gradient(closest-side at 30% 66%, rgba(150,60,255,.5), transparent 70%), radial-gradient(closest-side at 70% 30%, rgba(40,180,220,.4), transparent 68%), linear-gradient(150deg, #0f0a2e, #04020c 62%, #0a0620)", 'ach' => 'streak_50', 'tier' => 'legende', 'anim' => true],
        'lava'     => ['label' => 'Lava 🌋',     'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2236%22 height=%2236%22 viewBox=%220 0 36 36%22%3E%3Cg fill=%22%23ffc266%22%3E%3Ccircle cx=%228%22 cy=%2228%22 r=%222.4%22 fill-opacity=%22.85%22/%3E%3Ccircle cx=%2226%22 cy=%2214%22 r=%221.6%22 fill-opacity=%22.7%22/%3E%3Ccircle cx=%2218%22 cy=%2233%22 r=%221.2%22 fill-opacity=%22.6%22/%3E%3Ccircle cx=%2230%22 cy=%2230%22 r=%222%22 fill-opacity=%22.8%22/%3E%3Ccircle cx=%224%22 cy=%2210%22 r=%221.3%22 fill-opacity=%22.55%22/%3E%3C/g%3E%3C/svg%3E') repeat, radial-gradient(120% 90% at 50% 115%, rgba(255,140,50,.85), rgba(200,60,10,.3) 45%, transparent 72%), linear-gradient(135deg, #ff7043, #6d1b07 60%, #3e0c03)", 'ach' => 'rescue_10', 'tier' => 'legende', 'anim' => true],
        'glitzer'  => ['label' => 'Glitzer ✨',  'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2244%22 height=%2244%22 viewBox=%220 0 44 44%22%3E%3Cg fill=%22%23ffffff%22%3E%3Cpath d=%22M10 4 l1.4 3.6 L15 9 l-3.6 1.4 L10 14 l-1.4 -3.6 L5 9 l3.6 -1.4 Z%22 fill-opacity=%22.85%22/%3E%3Cpath d=%22M32 24 l1.2 3 3 1.2 -3 1.2 -1.2 3 -1.2 -3 -3 -1.2 3 -1.2 Z%22 fill-opacity=%22.7%22/%3E%3Ccircle cx=%2224%22 cy=%2210%22 r=%221.1%22 fill-opacity=%22.8%22/%3E%3Ccircle cx=%228%22 cy=%2234%22 r=%221.3%22 fill-opacity=%22.7%22/%3E%3C/g%3E%3C/svg%3E') repeat, linear-gradient(135deg, #ffe3f4, #ff9ad5 40%, #c86bff 75%, #7f6bff)", 'ach' => 'prinz', 'anim' => true],
        // Nordlicht: Vorhänge aus Grün, Violett und Türkis wandern gegenläufig über den Nachthimmel.
        'aurora'   => ['label' => 'Nordlicht 🌠', 'grad' => 'linear-gradient(12deg, transparent 24%, rgba(60,255,190,.6) 38%, transparent 52%), linear-gradient(-8deg, transparent 40%, rgba(140,110,255,.55) 58%, transparent 74%), linear-gradient(20deg, transparent 54%, rgba(90,220,255,.4) 68%, transparent 84%), linear-gradient(170deg, #071a2c, #020a14 70%)', 'ach' => 'meetings_full', 'tier' => 'legende', 'anim' => true],
        // Tiefsee: Blasen steigen auf, ein Lichtstrahl von oben wandert durchs Wasser.
        'deepsea'  => ['label' => 'Tiefsee 🫧',  'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2232%22 height=%2232%22%3E%3Cg fill=%22none%22 stroke=%22%23bff0ff%22 stroke-opacity=%22.55%22%3E%3Ccircle cx=%227%22 cy=%2224%22 r=%222.4%22/%3E%3Ccircle cx=%2222%22 cy=%2213%22 r=%221.5%22/%3E%3Ccircle cx=%2227%22 cy=%2227%22 r=%221.1%22/%3E%3C/g%3E%3C/svg%3E') repeat, linear-gradient(100deg, transparent 30%, rgba(180,245,255,.22) 44%, transparent 58%), linear-gradient(165deg, #0a5d78, #032235 62%, #010e18)", 'ach' => 'protocols_10', 'anim' => true],
        // Frost: Eiskristalle rieseln, ein kalter Glanz zieht schräg übers Eis.
        'frost'    => ['label' => 'Frost ❄️',    'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2230%22 height=%2230%22%3E%3Cg stroke=%22%23ffffff%22 stroke-width=%221%22 stroke-opacity=%22.8%22%3E%3Cpath d=%22M8 3 v10 M3.5 5.5 L12.5 10.5 M12.5 5.5 L3.5 10.5%22/%3E%3Cpath d=%22M22 18 v7 M19 19.5 L25 23.5 M25 19.5 L19 23.5%22/%3E%3C/g%3E%3C/svg%3E') repeat, linear-gradient(120deg, transparent 34%, rgba(255,255,255,.4) 50%, transparent 66%), linear-gradient(150deg, #cfeaff, #6fa8d8 55%, #35618f)", 'ach' => 'early_report_5', 'anim' => true],
        // Neon: Raster zieht nach unten weg, Magenta und Cyan pulsieren gegeneinander.
        'neon'     => ['label' => 'Neon 🕶️',     'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2224%22 height=%2224%22%3E%3Cg stroke=%22%2300f0ff%22 stroke-width=%22.8%22 stroke-opacity=%22.55%22%3E%3Cpath d=%22M0 12 H24 M12 0 V24%22/%3E%3C/g%3E%3C/svg%3E') repeat, linear-gradient(155deg, #ff2bd1, #6a1b9a 48%, #120a2e)", 'ach' => 'night_protocol', 'anim' => true],
        // Prisma: der ganze Farbkreis dreht sich einmal durch – die auffälligste Farbe im Locker.
        'prisma'   => ['label' => 'Prisma 🌈',   'grad' => 'conic-gradient(from 0deg, #ff5f6d, #ffc371, #47e0a0, #4fc3f7, #9b6bff, #ff5f6d)', 'ach' => 'kudos_10', 'tier' => 'legende', 'anim' => true],
        // Pusteblume: die Schirmchen fliegen davon – man gibt etwas weg, das sich verteilt, ohne zu
        // sehen wohin. Genau das sind Props. „Pusten" ist in der App ohnehin das Wort für Feiern.
        'puste'    => ['label' => 'Pusteblume 🌼', 'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2234%22 height=%2234%22%3E%3Cg stroke=%22%23ffffff%22 stroke-width=%22.85%22 stroke-linecap=%22round%22 stroke-opacity=%22.9%22%3E%3Cg transform=%22translate(8 24)%22%3E%3Cpath d=%22M0 0 v3.4%22/%3E%3Cpath d=%22M0 0 l-2.6 -2.2 M0 0 l2.6 -2.2 M0 0 l0 -3.2 M0 0 l-3.2 -.3 M0 0 l3.2 -.3%22/%3E%3C/g%3E%3Cg transform=%22translate(25 11)%22 stroke-opacity=%22.65%22%3E%3Cpath d=%22M0 0 v2.6%22/%3E%3Cpath d=%22M0 0 l-2 -1.7 M0 0 l2 -1.7 M0 0 l0 -2.4 M0 0 l-2.4 -.2 M0 0 l2.4 -.2%22/%3E%3C/g%3E%3Cg transform=%22translate(17 30)%22 stroke-opacity=%22.5%22%3E%3Cpath d=%22M0 0 v2%22/%3E%3Cpath d=%22M0 0 l-1.6 -1.4 M0 0 l1.6 -1.4 M0 0 l0 -1.9%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E') repeat, radial-gradient(closest-side at 50% 108%, rgba(255,255,255,.5), transparent 70%), linear-gradient(155deg, #a8e6dd, #5bb8c9 52%, #3d7fa8)", 'ach' => 'kudos_giver_3', 'anim' => true],
        // ---- Kaufbar im AsT-Sortiment ('shop' statt 'ach' – Freischaltung über shop_owned) ----
        // Bulle & Bär: die Kerzenchart-Farben, der Verlauf pendelt zwischen Grün- und Rot-Übergewicht.
        'bulle'       => ['label' => 'Bulle & Bär 📈', 'grad' => 'linear-gradient(135deg, #0f9e55, #5cc287 35%, #e05252 65%, #a3232f)', 'shop' => true, 'anim' => true],
        // Kupfer: Metall mit wanderndem Glanzstreifen – die ehrliche Etage unter dem (verdienten) Gold.
        'kupfer'      => ['label' => 'Kupfer 🪙', 'grad' => 'linear-gradient(115deg, #8a4f21 25%, #c07a3d 42%, #f2c396 50%, #c07a3d 58%, #8a4f21 75%)', 'shop' => true, 'anim' => true],
        // Mitternacht: tiefes Blau, der Sternenstaub driftet ganz langsam (Punkt-Layer wandern).
        'mitternacht' => ['label' => 'Mitternacht 🌃', 'grad' => 'radial-gradient(1.4px 1.4px at 22% 30%, rgba(255,255,255,.95) 60%, transparent 61%), radial-gradient(1px 1px at 68% 22%, rgba(255,255,255,.8) 60%, transparent 61%), radial-gradient(1.4px 1.4px at 78% 64%, rgba(190,215,255,.9) 60%, transparent 61%), radial-gradient(1px 1px at 36% 76%, rgba(255,255,255,.75) 60%, transparent 61%), linear-gradient(150deg, #0b1026, #17224e 55%, #25356e)', 'shop' => true, 'anim' => true],
        // Pfau: schillert langsam wie Federn im Licht (Verlauf wandert durch Petrol/Smaragd/Violett).
        'pfau'        => ['label' => 'Pfau 🦚', 'grad' => 'linear-gradient(135deg, #0e7f86, #18a06c 40%, #3f57b8 70%, #5b3fae)', 'shop' => true, 'anim' => true],
        // Herbstlaub: Grund + eine Kachel voller fallender Blätter (das ist der „ganze Baum").
        // Die zwei GROSSEN Blätter, die sich dabei richtig drehen, liegen in den Bewegungs-Ebenen
        // (.pal-herbstlaub::before/::after) – ein Kachelmuster kann sich nicht drehen, ohne dass
        // alles gemeinsam um die Bildmitte kreist.
        'herbstlaub'  => ['label' => 'Herbstlaub 🍂', 'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2264%22 height=%2264%22%3E%3Cg fill=%22%23ffd489%22%3E%3Cg transform=%22rotate(28 12 10)%22%3E%3Cpath d=%22M12 10 c 3 -4, 9 -3, 10 2 c -4 5, -10 3, -10 -2 Z%22/%3E%3C/g%3E%3Cg transform=%22rotate(-52 46 22)%22 fill=%22%23f7b978%22%3E%3Cpath d=%22M46 22 c 3 -3, 8 -2, 9 2 c -4 4, -9 2, -9 -2 Z%22/%3E%3C/g%3E%3Cg transform=%22rotate(66 26 40)%22 fill=%22%23ffe0aa%22%3E%3Cpath d=%22M26 40 c 3 -4, 8 -2, 9 2 c -4 4, -9 2, -9 -2 Z%22/%3E%3C/g%3E%3Cg transform=%22rotate(-18 54 52)%22 fill=%22%23f2a95c%22%3E%3Cpath d=%22M54 52 c 2 -3, 7 -2, 8 2 c -3 3, -8 2, -8 -2 Z%22/%3E%3C/g%3E%3Cg transform=%22rotate(40 6 50)%22 fill=%22%23ffcf80%22%3E%3Cpath d=%22M6 50 c 2 -3, 7 -2, 8 2 c -3 3, -8 2, -8 -2 Z%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E') repeat, linear-gradient(150deg, #e8a33d, #c2532a 58%, #7a2f1e)", 'shop' => true, 'anim' => true],
        // Vinyl: Rillen und Label sind der Hintergrund und stehen still. Die DREHUNG kann ein
        // Hintergrund nicht leisten – background-position verschiebt, sie dreht nicht. Deshalb
        // liegt der Glanz als eigene, rotierende Ebene darüber (.pal-vinyl::after in style.css).
        'vinyl'       => ['label' => 'Vinyl 🎶', 'grad' => 'radial-gradient(circle at 50% 50%, #d8524f 0 15%, #16171c 15.5%), repeating-radial-gradient(circle at 50% 50%, #16171c 0 2px, #24252c 2px 4px)', 'shop' => true, 'anim' => true],
        // Strick: Zickzack wie eine Maschenreihe, das Ganze wogt ganz langsam wie ein Stoff.
        // Strick: ZOPFMUSTER statt Zickzack. Ein Zopf ist das, woran man Strick erkennt – zwei
        // Stränge, die sich umeinander legen, dazu schmale Rippen an den Seiten. Jeder Strang
        // bekommt eine helle Oberkante und einen dunklen Fuß, sonst bleibt es flaches Gekritzel.
        'strick'      => ['label' => 'Strick 🧶', 'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2244%22 height=%2236%22%3E%3Cg fill=%22none%22 stroke-linecap=%22round%22%3E%3Cg stroke=%22%238a4a3c%22 stroke-width=%227%22 stroke-opacity=%22.55%22%3E%3Cpath d=%22M6 1 v34 M38 1 v34%22/%3E%3Cpath d=%22M14 1 C 14 10, 30 10, 30 19 C 30 28, 14 28, 14 37%22/%3E%3Cpath d=%22M30 1 C 30 10, 14 10, 14 19 C 14 28, 30 28, 30 37%22/%3E%3C/g%3E%3Cg stroke=%22%23c98070%22 stroke-width=%225.4%22%3E%3Cpath d=%22M6 0 v34 M38 0 v34%22/%3E%3Cpath d=%22M14 0 C 14 9, 30 9, 30 18 C 30 27, 14 27, 14 36%22/%3E%3Cpath d=%22M30 0 C 30 9, 14 9, 14 18 C 14 27, 30 27, 30 36%22/%3E%3C/g%3E%3Cg stroke=%22%23e8ab98%22 stroke-width=%221.7%22 stroke-opacity=%22.85%22%3E%3Cpath d=%22M6 -1 v34 M38 -1 v34%22/%3E%3Cpath d=%22M14 -1 C 14 8, 30 8, 30 17 C 30 26, 14 26, 14 35%22/%3E%3Cpath d=%22M30 -1 C 30 8, 14 8, 14 17 C 14 26, 30 26, 30 35%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E') repeat, linear-gradient(160deg, #b06d5d, #96513f)", 'shop' => true, 'anim' => true],
        'regentag'    => ['label' => 'Regentag 🌧️', 'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2228%22 height=%2228%22%3E%3Cg stroke=%22%23e2eef8%22 stroke-width=%221.2%22 stroke-linecap=%22round%22 stroke-opacity=%22.62%22%3E%3Cpath d=%22M6 2 L2 10 M17 0 L13 8 M24 6 L20 14 M10 15 L6 23 M22 18 L18 26%22/%3E%3C/g%3E%3C/svg%3E') repeat, radial-gradient(105% 78% at 50% 122%, transparent 52%, rgba(214,92,116,.62) 54%, rgba(240,168,72,.6) 60%, rgba(232,222,96,.55) 66%, rgba(96,196,120,.55) 72%, rgba(78,150,222,.6) 78%, rgba(140,110,210,.5) 84%, transparent 88%), linear-gradient(165deg, #8ba3b8, #47607a 58%, #26384a)", 'shop' => true, 'anim' => true],
        // Morgenrot: kleine Sonne + Wolkenbänder, beide als eigene Ebenen (siehe .pal-morgenrot).
        'morgenrot'   => ['label' => 'Morgenrot 🌄', 'grad' => "url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22140%22 height=%2270%22%3E%3Cg fill=%22%23fff0c4%22%3E%3Crect x=%22-14%22 y=%2216%22 width=%2286%22 height=%224.6%22 rx=%222.3%22 fill-opacity=%22.62%22/%3E%3Crect x=%2286%22 y=%2226%22 width=%2268%22 height=%223.8%22 rx=%221.9%22 fill-opacity=%22.5%22/%3E%3Crect x=%2218%22 y=%2240%22 width=%22104%22 height=%224.2%22 rx=%222.1%22 fill-opacity=%22.55%22/%3E%3Crect x=%22-8%22 y=%2254%22 width=%2256%22 height=%223.4%22 rx=%221.7%22 fill-opacity=%22.45%22/%3E%3C/g%3E%3C/svg%3E') no-repeat, radial-gradient(closest-side circle at 50% 50%, rgba(255,250,222,1), rgba(255,198,116,.9) 54%, rgba(255,160,100,0) 74%) no-repeat, linear-gradient(168deg, #ffc178 0%, #f0845f 46%, #9c4f6e 100%)", 'ach' => 'erste_reihe', 'anim' => true],
        // Konfetti: wie beim Herbstlaub nur der Grund – die Schnipsel fallen in der Bewegungs-Ebene.
        'konfetti'    => ['label' => 'Konfetti 🎉', 'grad' => 'radial-gradient(closest-side at 50% 18%, rgba(255,255,255,.16), transparent 72%), linear-gradient(155deg, #2f2757, #1a1436 58%, #0e0a22)', 'shop' => true, 'anim' => true],
    ];
}

/** Für dieses Mitglied wählbare Paletten (freie + per Achievement freigeschaltete; Diagnose-Testmodus: alle). */
function member_palettes(int $memberId): array
{
    $all = avatar_palettes();
    if (test_unlock_all() && $memberId === (int)($_SESSION['member_id'] ?? 0)) return $all;
    $held = array_flip(member_achievement_codes($memberId));
    $own  = shop_available($memberId); // Kauf-Paletten ('shop') gehören erst nach Kauf/Beschlagnahme dazu
    return array_filter($all, fn($p, $k) => (empty($p['ach']) && empty($p['shop']))
        || (!empty($p['ach']) && isset($held[$p['ach']]))
        || (!empty($p['shop']) && isset($own[$k])), ARRAY_FILTER_USE_BOTH);
}

/**
 * Die Paletten in ANZEIGE-Reihenfolge: erst die eigenen, dann die kaufbaren, zuletzt die
 * verschlossenen. Innerhalb einer Gruppe bleibt die Reihenfolge des Registers erhalten
 * (uasort sortiert seit PHP 8 stabil).
 *
 * Warum: In der Register-Reihenfolge stehen Schlösser zwischen den wählbaren Kacheln, und man
 * sucht sein eigenes Zeug zwischen Dingen, die man gar nicht anklicken kann. Schmuck und
 * Streak-Stile sind im Locker schon so gebaut – die Farben ziehen hiermit nach.
 *
 * @return array<string,array> Palettenschlüssel => Registereintrag
 */
function avatar_palettes_sorted(int $memberId): array
{
    $alle  = avatar_palettes();
    $frei  = member_palettes($memberId);
    $laden = shop_items();
    $rang  = [];
    foreach ($alle as $k => $p) {
        $rang[$k] = isset($frei[$k]) ? 0 : ((!empty($p['shop']) && isset($laden[$k])) ? 1 : 2);
    }
    uksort($alle, fn($a, $b) => $rang[$a] <=> $rang[$b]);
    return $alle;
}

/** Gewählte Avatar-Palette ('' = automatischer Namens-Verlauf). */
function member_palette(int $memberId): string
{
    if ($memberId <= 0) return '';
    try {
        $s = db()->prepare('SELECT avatar_palette FROM members WHERE id = ?');
        $s->execute([$memberId]);
        return trim((string)($s->fetchColumn() ?: ''));
    } catch (\Throwable $e) { return ''; }
}

/** Setzt die Avatar-Palette ('' = zurück auf den Namens-Verlauf). Nur Wählbares ist erlaubt. */
function member_set_palette(int $memberId, string $key): bool
{
    if ($memberId <= 0) return false;
    if ($key !== '' && !isset(member_palettes($memberId)[$key])) return false;
    try {
        db()->prepare('UPDATE members SET avatar_palette = ? WHERE id = ?')->execute([$key, $memberId]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/** Avatar-Verlauf eines Mitglieds: gewählte Palette (sofern existent), sonst der stabile Namens-Verlauf. */
function member_avatar_gradient(array $m): string
{
    $key = trim((string)($m['avatar_palette'] ?? ''));
    $all = avatar_palettes();
    if ($key !== '' && isset($all[$key])) return $all[$key]['grad'];
    return avatar_gradient((string)($m['name'] ?? ''));
}

/**
 * Schriftfarben für die Initialen im Avatar. ALLE frei wählbar – anders als die Verläufe hängt
 * hier bewusst nichts an einem Achievement: Es ist eine Lesbarkeits- und Geschmacksfrage, keine
 * Belohnung. Wer einen dunklen Verlauf hat, braucht helle Initialen, und umgekehrt.
 *
 * Einträge mit 'grad' sind Verlaufs-Schriften: Ein Verlauf passt nicht durch die --av-ink-Variable
 * (color kennt keine Verläufe), deshalb hängt member_avatar_class() dafür eine Klasse an
 * (ink-<key>) und die Regel in style.css schneidet den Verlauf per background-clip auf die
 * Buchstaben zu – das braucht die .av-ini-Hülle um die Initialen. 'color' bleibt trotzdem
 * gesetzt: Er ist der Rückfall an Stellen, die die Initialen nicht einhüllen.
 */
function avatar_inks(): array
{
    return [
        'weiss'     => ['label' => 'Weiß',      'color' => '#ffffff'],
        'creme'     => ['label' => 'Creme',     'color' => '#fff2d8'],
        'schwarz'   => ['label' => 'Schwarz',   'color' => '#14181f'],
        'gold'      => ['label' => 'Gold',      'color' => '#f4cd54'],
        'rose'      => ['label' => 'Rosé',      'color' => '#ff9ad5'],
        'koralle'   => ['label' => 'Koralle',   'color' => '#ff8a6b'],
        'mint'      => ['label' => 'Mint',      'color' => '#7bd8b0'],
        'limette'   => ['label' => 'Limette',   'color' => '#c3e86b'],
        'himmel'    => ['label' => 'Himmel',    'color' => '#8ecbff'],
        'lavendel'  => ['label' => 'Lavendel',  'color' => '#c9a8f2'],
        'petrol'    => ['label' => 'Petrol',    'color' => '#1f6f7a'],
        'pflaume'   => ['label' => 'Pflaume',   'color' => '#7e3550'],
        // Bewusst PASTELL statt der satten Fahnen-Töne: Auf der Pride-PALETTE (satter Verlauf)
        // wären farbgleiche Buchstaben unsichtbar – hell auf satt bleibt lesbar, und auf den
        // dunklen Paletten sowieso. Auf hellen Flächen ist sie schwach, wie Weiß auch.
        'pride'     => ['label' => 'Pride 🏳️‍🌈', 'color' => '#ffffff',
                        'grad' => 'linear-gradient(160deg, #ffb9be 0%, #ffd9a6 20%, #fff2ad 40%, #b5ecc6 60%, #b7d7ff 80%, #dcc8ff 100%)'],
    ];
}

/** Gewählte Schriftfarbe ('' = Vorgabe). */
function member_ink(int $memberId): string
{
    if ($memberId <= 0) return '';
    try {
        $s = db()->prepare('SELECT avatar_ink FROM members WHERE id = ?');
        $s->execute([$memberId]);
        return trim((string)($s->fetchColumn() ?: ''));
    } catch (\Throwable $e) { return ''; }
}

/** Setzt die Schriftfarbe ('' = zurück auf die Vorgabe). Nur bekannte Werte sind erlaubt. */
function member_set_ink(int $memberId, string $key): bool
{
    if ($memberId <= 0) return false;
    if ($key !== '' && !isset(avatar_inks()[$key])) return false;
    try {
        db()->prepare('UPDATE members SET avatar_ink = ? WHERE id = ?')->execute([$key, $memberId]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/**
 * Fertiges style-Schnipsel für einen Avatar: Verlauf und – falls gewählt – Schriftfarbe.
 * Eine Funktion für beides, damit keine Stelle die eine setzt und die andere vergisst.
 */
function member_avatar_style(array $m): string
{
    $stil = '--av:' . member_avatar_gradient($m);
    $ink = trim((string)($m['avatar_ink'] ?? ''));
    $all = avatar_inks();
    if ($ink !== '' && isset($all[$ink])) $stil .= ';--av-ink:' . $all[$ink]['color'];
    return $stil;
}

/** Avatar-Chip: Mini-Avatar (Verlauf + getragener Schmuck) + Name – für Teilnahme-, Fame- und
 *  Abwesenheits-Listen. Erwartet eine members-Zeile mit name, avatar_decos, avatar_palette
 *  (+ optional pronouns). $royal ergänzt Krone + King/Queen-Titel (Spitzenklasse),
 *  $suffix ist fertiges HTML hinter dem Namen (z. B. Pronomen oder Streak-Zahl).
 *  Auf den Mitglieder-Kacheln wird bewusst OHNE $royal aufgerufen – dort trägt die Karte selbst
 *  Krone und goldenen Rahmen; „Queen " vor dem Namen lässt die Kopfzeile umbrechen. */
function avatar_chip(array $m, bool $royal = false, string $extraCls = '', string $suffix = ''): string
{
    $name = short_name((string)($m['name'] ?? ''));
    if ($royal) { // King/Queen je nach erstem Pronomen (wie die royale Dashboard-Begrüßung), sonst nur Krone
        $p0 = strtolower(explode('/', trim((string)($m['pronouns'] ?? '')))[0]);
        $name = ($p0 === 'er' ? 'King ' : ($p0 === 'sie' ? 'Queen ' : '')) . $name;
    }
    return '<span class="gt-chip gt-chip-av' . $extraCls . '"' . ($royal ? ' title="Spitzenklasse 👑"' : '') . '>'
         . avatar_bubble($m)
         . ($royal ? '<i class="ti ti-crown gt-royal"></i> ' : '')
         . h($name) . $suffix . '</span>';
}

/** Profil-URL eines Mitglieds. $base = ''-Präfix für Unterordner (aus admin/ z. B. '../'). */
// ---------------------------------------------------------------------------
// Orte der App – jede Stelle, auf die ein Text verweisen kann
//
// Statt Wegbeschreibungen im Fließtext („bitte unter Verwaltung → Uploads einrichten") gibt
// es hier EIN Name je Ort, den Pfad relativ zur aufrufenden Seite (base() weiß, ob wir in
// admin/ stehen) und eine Rechteprüfung. Wer den Ort nicht öffnen darf, sieht den Namen
// weiterhin – nur eben ohne toten Link.
//
// 'darf' ist bewusst ein Closure: Die Rechte hängen an der angemeldeten Person und dürfen erst
// beim Rendern ausgewertet werden, nicht beim Aufbau der Liste.
// ---------------------------------------------------------------------------

function app_places(): array
{
    $eingeloggt = static fn(): bool => is_logged_in();
    $admin      = static fn(): bool => can_admin();
    return [
        // --- Bereiche für alle Mitglieder ---
        'dashboard'    => ['url' => 'dashboard.php',    'label' => 'Dashboard',                  'icon' => 'ti-home',          'darf' => $eingeloggt],
        'kalender'     => ['url' => 'index.php',        'label' => 'Kalender',                   'icon' => 'ti-calendar',      'darf' => $eingeloggt],
        'events'       => ['url' => 'events.php',       'label' => 'Events',                     'icon' => 'ti-confetti',      'darf' => $eingeloggt],
        'infos'        => ['url' => 'info.php',         'label' => 'Wichtige Informationen',     'icon' => 'ti-pin',           'darf' => $eingeloggt],
        'mitglieder'   => ['url' => 'mitglieder.php',   'label' => 'Mitglieder',                 'icon' => 'ti-users',         'darf' => $eingeloggt],
        'profil'       => ['url' => 'profil.php',       'label' => 'dein Profil',                'icon' => 'ti-user-circle',   'darf' => $eingeloggt],
        'umlauf'       => ['url' => 'umlauf.php',       'label' => 'Abstimmungen & Umläufe',     'icon' => 'ti-mailbox',       'darf' => $eingeloggt],
        'terminfinder' => ['url' => 'terminfinder.php', 'label' => 'Terminfinder',               'icon' => 'ti-calendar-search', 'darf' => $eingeloggt],
        'finanzen'     => ['url' => 'finanzen.php',     'label' => 'Belegblätter',               'icon' => 'ti-cash',          'darf' => $eingeloggt],
        'abwesenheit'  => ['url' => 'absence.php',      'label' => 'Abwesenheit',                'icon' => 'ti-plane-departure', 'darf' => $eingeloggt],
        'erinnerungen' => ['url' => 'erinnerungen.php', 'label' => 'Erinnerungen & Mitteilungen', 'icon' => 'ti-bell',         'darf' => $eingeloggt],
        'achievements' => ['url' => 'achievements.php', 'label' => 'Erfolge',                    'icon' => 'ti-trophy',        'darf' => $eingeloggt],
        'score'        => ['url' => 'score.php',        'label' => 'Eventscore',                 'icon' => 'ti-chart-bar',     'darf' => $eingeloggt],
        'bericht'      => ['url' => 'report.php',       'label' => 'Bericht schreiben',          'icon' => 'ti-clipboard-text', 'darf' => $eingeloggt],
        'wegweiser'    => ['url' => 'wegweiser.php',    'label' => 'Wegweiser',                  'icon' => 'ti-map-2',         'darf' => $eingeloggt],
        'tauschboerse' => ['url' => 'tauschboerse.php', 'label' => 'Schichtbörse',               'icon' => 'ti-arrows-exchange', 'darf' => $eingeloggt],
        'gettogether'  => ['url' => 'gettogether.php',  'label' => 'Get-Together',               'icon' => 'ti-beer',          'darf' => $eingeloggt],
        'bug'          => ['url' => 'bug.php',          'label' => 'Fehler melden',              'icon' => 'ti-bug',           'darf' => $eingeloggt],
        'feedback'     => ['url' => 'feedback.php',     'label' => 'Feedback',                   'icon' => 'ti-message-heart', 'darf' => $eingeloggt],

        // --- Verwaltung ---
        'verwaltung'   => ['url' => 'admin/index.php',       'label' => 'Verwaltung',                 'icon' => 'ti-settings',      'darf' => $eingeloggt],
        'a_mitglieder' => ['url' => 'admin/members.php',     'label' => 'Mitglieder',                 'icon' => 'ti-users',         'darf' => $admin],
        'a_events'     => ['url' => 'admin/events.php',      'label' => 'Events & Termine',           'icon' => 'ti-calendar-event', 'darf' => $eingeloggt],
        'a_sitzungen'  => ['url' => 'admin/meetings.php',    'label' => 'Sitzungen',                  'icon' => 'ti-gavel',         'darf' => static fn(): bool => can_manage_meetings()],
        'a_nachricht'  => ['url' => 'admin/message.php',     'label' => 'Nachricht hinterlassen',     'icon' => 'ti-message-plus',  'darf' => $admin],
        'a_scores'     => ['url' => 'admin/score.php',       'label' => 'Scores',                     'icon' => 'ti-trophy',        'darf' => $admin],
        'a_aktivitaet' => ['url' => 'admin/activity.php',    'label' => 'Aktivitätsstatistik',        'icon' => 'ti-chart-line',    'darf' => $admin],
        'a_erfolge'    => ['url' => 'admin/achievements.php', 'label' => 'Achievements-Übersicht',    'icon' => 'ti-medal',         'darf' => $admin],
        'a_anleitung'  => ['url' => 'admin/help.php',        'label' => 'Anleitung',                  'icon' => 'ti-book',          'darf' => $admin],
        'a_infos'      => ['url' => 'admin/infos.php',       'label' => 'Wichtige Informationen',     'icon' => 'ti-pin',           'darf' => $admin],
        'a_wegweiser'  => ['url' => 'admin/wegweiser.php',   'label' => 'Wegweiser',                  'icon' => 'ti-map-2',         'darf' => $admin],
        'a_traeger'    => ['url' => 'admin/traeger.php',     'label' => 'Träger',                     'icon' => 'ti-building-community', 'darf' => $admin],
        'a_mailtexte'  => ['url' => 'admin/mailtexts.php',   'label' => 'Mailtexte',                  'icon' => 'ti-mail-cog',      'darf' => $admin],
        'a_sekretariat' => ['url' => 'admin/sekretariat.php', 'label' => 'Sekretariatsaufgaben',      'icon' => 'ti-checklist',     'darf' => $admin],
        'a_vorlagen'   => ['url' => 'admin/vorlagen.php',    'label' => 'Word-Vorlagen',              'icon' => 'ti-file-text',     'darf' => $admin],
        'a_uploads'    => ['url' => 'uploads.php',           'label' => 'Uploads und Automationen',   'icon' => 'ti-cloud-upload',  'darf' => $admin],
        'a_bugs'       => ['url' => 'admin/bugs.php',        'label' => 'Bug-Reports',                'icon' => 'ti-bug',           'darf' => $eingeloggt],
        'a_feedback'   => ['url' => 'admin/feedback.php',    'label' => 'Feedback',                   'icon' => 'ti-message-heart', 'darf' => $eingeloggt],
        'a_diagnose'   => ['url' => 'admin/errorlog.php',    'label' => 'Diagnose & Fehler-Log',      'icon' => 'ti-stethoscope',   'darf' => $admin],
        'a_berichte'   => ['url' => 'admin/reports.php',     'label' => 'Berichte',                   'icon' => 'ti-clipboard-check', 'darf' => static fn(): bool => can_manage_meetings()],
        'a_gefahr'     => ['url' => 'admin/reset.php',       'label' => 'Gefahrenzone',               'icon' => 'ti-alert-triangle', 'darf' => $admin],

        // --- Werkzeuge für Menschen außerhalb des AStA ---
        'a_paten'      => ['url' => 'admin/paten.php',       'label' => 'Pat:innenprogramm',          'icon' => 'ti-users-group',   'darf' => static fn(): bool => can_admin() || is_pat_manager()],
        'a_umfragen'   => ['url' => 'admin/umfragen.php',    'label' => 'Umfragen',                   'icon' => 'ti-chart-donut',   'darf' => $admin],
        'a_termin'     => ['url' => 'admin/terminplaner.php', 'label' => 'Terminplaner',              'icon' => 'ti-calendar-plus', 'darf' => $admin],
        'a_extern'     => ['url' => 'extern.php',            'label' => 'Externe Events',             'icon' => 'ti-world',         'darf' => static fn(): bool => extern_can_manage(null)],
        'a_waslaeuft'  => ['url' => 'veranstaltungen.php',   'label' => 'was.läuft',                  'icon' => 'ti-calendar-star', 'darf' => static fn(): bool => wl_can_manage()],
    ];
}

/**
 * Einen Ort als Link ausgeben. $text überschreibt die Beschriftung, $anchor springt an eine Stelle
 * der Zielseite ($anchor ohne „#"). Wer nicht hindarf, bekommt nur den Namen – kein toter Link.
 * $mitIcon setzt das Symbol des Ortes davor.
 */
function app_place(string $key, string $text = '', string $anchor = '', bool $mitIcon = false): string
{
    $orte = app_places();
    if (!isset($orte[$key])) {                       // Tippfehler sollen auffallen, aber nichts zerstören
        app_log_error('app_place(): unbekannter Ort „' . $key . '"');
        return h($text !== '' ? $text : $key);
    }
    $ort = $orte[$key];
    $beschriftung = $text !== '' ? $text : (string)$ort['label'];
    $symbol = $mitIcon ? '<i class="ti ' . h((string)$ort['icon']) . '"></i> ' : '';
    if (!($ort['darf'])()) return $symbol . '<strong>' . h($beschriftung) . '</strong>';
    return '<a href="' . h(base() . $ort['url'] . ($anchor !== '' ? '#' . $anchor : ''))
        . '">' . $symbol . h($beschriftung) . '</a>';
}

/** Nur die Adresse eines Ortes (für eigene Knöpfe/Formulare). */
function app_place_url(string $key, string $anchor = ''): string
{
    $ort = app_places()[$key] ?? null;
    if (!$ort) return base();
    return base() . $ort['url'] . ($anchor !== '' ? '#' . $anchor : '');
}

/** Sitzung als Link auf ihre Detailseite ($anchor z. B. 'protokoll', 'abstimmung'). */
function meeting_link(array $m, string $text = '', string $anchor = ''): string
{
    $id = (int)($m['id'] ?? 0);
    $beschriftung = $text !== '' ? $text : meeting_label($m);
    if ($id <= 0) return h($beschriftung);
    return '<a class="entity-link" href="' . h(base() . 'meeting.php?id=' . $id . ($anchor !== '' ? '#' . $anchor : ''))
        . '">' . h($beschriftung) . '</a>';
}

/** Event als Link auf seine Detailseite. */
function event_link(array $e, string $text = ''): string
{
    $id = (int)($e['id'] ?? 0);
    $beschriftung = $text !== '' ? $text : (string)($e['title'] ?? 'Event');
    if ($id <= 0) return h($beschriftung);
    return '<a class="entity-link" href="' . h(base() . 'event.php?id=' . $id) . '">' . h($beschriftung) . '</a>';
}

/** Umlaufbeschluss oder Abstimmung als Link ($art: 'umlauf' | 'poll'). */
function umlauf_link(array $u, string $art = 'umlauf', string $text = ''): string
{
    $id = (int)($u['id'] ?? 0);
    $beschriftung = $text !== '' ? $text : (string)($u['title'] ?? 'Abstimmung');
    if ($id <= 0) return h($beschriftung);
    $q = $art === 'poll' ? 'poll=' : 'id=';
    return '<a class="entity-link" href="' . h(base() . 'umlauf.php?' . $q . $id) . '">' . h($beschriftung) . '</a>';
}

function member_profile_url(int $memberId, string $base = ''): string
{
    return $base . 'profil.php?id=' . $memberId;
}

/**
 * Mitgliedsname als klickbarer Profil-Link (Kurzname, erbt Textfarbe – funktioniert dadurch auch in
 * Namenslisten und auf farbigen Flächen). Erwartet mindestens id + name; ohne gültige id nur der Name.
 */
function member_link(array $m, string $base = '', bool $fullName = false): string
{
    $name = $fullName ? (string)($m['name'] ?? '') : short_name((string)($m['name'] ?? ''));
    $id = (int)($m['id'] ?? 0);
    if ($id <= 0) return h($name);
    return '<a class="member-link" href="' . h(member_profile_url($id, $base)) . '">' . h($name) . '</a>';
}

/** avatar_chip als Profil-Link – gleiche Optik, ganzer Chip klickbar. */
function member_chip_link(array $m, bool $royal = false, string $extraCls = '', string $suffix = '', string $base = ''): string
{
    $id = (int)($m['id'] ?? 0);
    if ($id <= 0) return avatar_chip($m, $royal, $extraCls, $suffix);
    return '<a class="chip-link" href="' . h(member_profile_url($id, $base)) . '">' . avatar_chip($m, $royal, $extraCls, $suffix) . '</a>';
}

/**
 * Sonderfunktions-Badges eines Mitglieds (die App-Rollen als Pills; '' bei normalem Mitglied).
 * Bewusst aus dem role-Feld abgeleitet – es gibt nichts separat zu pflegen.
 */
function member_role_badges(array $m): string
{
    // Kurzformen „Sekki“ und „Technik“: die Langformen sind so breit, dass die Pill neben einem
    // langen Namen in die nächste Zeile rutscht und die Kachel höher zieht.
    // Die Langform steht jeweils im title, damit die Bedeutung nicht verloren geht.
    $map = ['vorsitz'     => ['Vorsitz', 'ti-star', 'Vorsitz'],
            'sekretariat' => ['Sekki', 'ti-id-badge-2', 'Sekretariat'],
            'finanzen'    => ['Finanzen', 'ti-coins', 'Finanzen'],
            'admin'       => ['Technik', 'ti-tool', 'Admin & Technik']];
    $r = (string)($m['role'] ?? 'member');
    if (!isset($map[$r])) return '';
    [$label, $icon, $titel] = $map[$r];
    return '<span class="pill pill-role" title="' . h($titel) . '"><i class="ti ' . $icon . '"></i> ' . h($label) . '</span>';
}

/**
 * Steckbrief-Text für die Kachel: Absätze werden zu einfachen Zeilenumbrüchen zusammengezogen.
 * Im drei Zeilen hohen Fenster der Kachel verschenkt eine Leerzeile ein Drittel des Platzes –
 * auf der Profilseite bleiben die Absätze dagegen erhalten. Zieht nebenbei CRLF glatt.
 * Das Gegenstück im Browser (Vorschau in profil.php) muss dieselbe Regel anwenden.
 */
function member_card_text(string $s): string
{
    return trim((string)preg_replace('/(?:\h*\R)+\h*/u', "\n", trim($s)));
}

/**
 * EINE Mitglieder-Kachel, wie sie auf der Mitgliederliste steht.
 * Zentral und nicht als Schleife in mitglieder.php, weil die Vorschau in der Profil-Bearbeitung
 * (profil.php) exakt dieselbe Kachel zeigen muss: Zwei Fassungen laufen auseinander, und dann
 * lügt die Vorschau.
 *
 * $opt: referat  – Referat mit anzeigen (bei den Ansprechpersonen steht es schon in der Rolle)
 *       viewer   – wer schaut (für die Props-Taste; Standard: angemeldetes Mitglied)
 *       given, quota – Props-Stand des Betrachters; von der Liste einmal übergeben, statt ihn
 *                      für jede der ~30 Kacheln neu abzufragen
 *       preview  – Bearbeitungs-Vorschau: keine Props-Taste (das Formular gehört zur Liste) und
 *                  BEIDE Textzustände im Markup, damit das Skript beim Tippen live umschalten kann
 */
function member_card_html(array $m, array $opt = []): string
{
    static $catalog = null;
    if ($catalog === null) $catalog = achievements_catalog();

    $showReferat = !empty($opt['referat']);
    $preview     = !empty($opt['preview']);
    $viewer      = isset($opt['viewer']) ? (int)$opt['viewer'] : (int)(current_member()['id'] ?? 0);
    $id          = (int)($m['id'] ?? 0);

    $today    = date('Y-m-d');
    $mmdd     = date('m-d');
    $newSince = date('Y-m-d', strtotime('-90 days'));

    $joined  = trim((string)($m['joined_at'] ?? ''));
    $bday    = trim((string)($m['birthday'] ?? ''));
    $referat = trim((string)($m['referat'] ?? ''));
    $abs     = member_current_absence($id);
    $jubi    = $joined !== '' && substr($joined, 5) === $mmdd && substr($joined, 0, 10) < $today;
    $jahre   = $jubi ? max(1, (int)date('Y') - (int)substr($joined, 0, 4)) : 0;

    // Spitzenklasse: auf der Kachel KEIN „Queen/King" vor dem Namen – das schob die Kopfzeile in
    // eine zweite Reihe und riss die ganze Rasterzeile mit. Stattdessen trägt die KARTE den
    // Status: eine Krone oben auf der Kante und ein goldener Rahmen. Ausgeschrieben steht der
    // Titel weiterhin dort, wo Platz ist (Dashboard, Hall of Fame, Redeliste, Profil).
    $royal = in_array('spitze', member_achievement_codes($id), true);

    $o = '<div class="card hoverable stretch-card mem-card' . ($royal ? ' mem-card-royal' : '')
       . ($preview ? ' mem-card-preview' : '') . '">';
    if ($royal) {
        // EIGENE Zeichnung, nicht die Avatar-Krone: Die ist für einen Kopf gemacht (breit, mit
        // kräftiger Kontur) und wird hier auf 34 px Kartenbreite heruntergerechnet – dabei wird
        // die Kontur zum Fussel und der Zickzack zur Zacke. Diese hier ist für genau diese Größe
        // gezeichnet. Sitzt oben auf der Kartenkante und ragt in den Rasterabstand.
        $o .= '<span class="mem-krone" title="Spitzenklasse 👑" role="img" aria-label="Spitzenklasse">'
            . mem_crown_svg() . '</span>';
    }

    // Props 🙌 – nie auf der eigenen Kachel und nie in der Vorschau (das Formular gehört zur Liste)
    if (!$preview && $viewer > 0 && $id !== $viewer) {
        $given = isset($opt['given']) ? (array)$opt['given'] : kudos_given_ids($viewer);
        $quota = isset($opt['quota']) ? (int)$opt['quota'] : kudos_quota_left($viewer);
        if (in_array($id, $given, true)) {
            $o .= '<span class="kudo-btn given" title="Diesen Monat schon Props gegeben 🙌" aria-hidden="true">🙌</span>';
        } elseif ($quota > 0) {
            $o .= '<form method="post" class="kudo-form">' . csrf_field()
                . '<input type="hidden" name="action" value="give_kudo">'
                . '<input type="hidden" name="to_id" value="' . $id . '">'
                . '<button type="submit" class="kudo-btn" title="Props geben 🙌 (anonym)" aria-label="Props für '
                . h((string)($m['name'] ?? '')) . ' geben">🙌</button></form>';
        } else {
            $o .= '<span class="kudo-btn off" title="Dein Monatskontingent an Props ist aufgebraucht" aria-hidden="true">🙌</span>';
        }
    }

    // Kopf: Avatar samt Schmuck, Sonderfunktion und Status-Badges
    $o .= '<div class="profil-list" style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap">'
        . '<a class="tl-stretch chip-link" href="' . h(member_profile_url($id)) . '">'
        . avatar_chip($m) . '</a>'
        . member_role_badges($m);
    if ($id === $viewer) $o .= '<span class="pill">du</span>';
    $o .= '</div>';

    // Status-Badges gehören NICHT in die Kopfzeile: dort schoben sie Avatar und Rolle in eine
    // zweite Reihe und rissen die ganze Rasterzeile höher. Sie stehen unten in der Fußzeile
    // zwischen Streak und Erfolgen – da ist Platz, und „bis wann jemand im Urlaub ist" bleibt
    // als Klartext lesbar. Die selteneren Anlässe bleiben Zeichen (Klartext in title/aria-label).
    $zeichen = function (string $emoji, string $klartext, string $cls): string {
        return '<span class="pill ' . $cls . ' pill-zeichen" title="' . h($klartext) . '" role="img" aria-label="'
            . h($klartext) . '">' . $emoji . '</span>';
    };
    // GENAU EIN Badge, nach Seltenheit sortiert: Geburtstag und Jahrestag gibt es einmal im Jahr,
    // die verdrängen an ihrem Tag alles andere. Sonst zählt die Abwesenheit (mit Datum – das will
    // man wissen), und erst wenn auch die fehlt, steht „Neu" da.
    $status = '';
    if ($bday === $mmdd) {
        $status = $zeichen('🎂', 'Hat heute Geburtstag!', 'pill-fun');
    } elseif ($jubi) {
        $status = $zeichen('🎉', 'Heute vor ' . $jahre . ' Jahr' . ($jahre > 1 ? 'en' : '') . ' in den AStA eingetreten', 'pill-ok');
    } elseif ($abs) {
        $status = '<span class="pill pill-warn" title="Abwesend ' . h(fmt_date((string)$abs['starts_at'])) . ' – '
            . h(fmt_date((string)$abs['ends_at'])) . '">🌴 bis ' . h(fmt_date((string)$abs['ends_at'])) . '</span>';
    } elseif ($joined !== '' && $joined >= $newSince) {
        // Für „neu dabei" gibt es kein Symbol, das man ohne Erklärung versteht – „Neu" ist kurz genug.
        $status = '<span class="pill pill-info" title="Neu dabei – seit ' . h(fmt_date($joined)) . '">Neu</span>';
    }

    // Metazeile (Pronomen · Referat) – gehört zum Kopf der Karte. Sie bleibt IMMER einzeilig
    // (Stylesheet kürzt mit „…"): ein langer Referatsname brach sonst um, machte die Kachel höher
    // und zog die ganze Rasterzeile mit. Der volle Wortlaut steht im title.
    $pron = trim((string)($m['pronouns'] ?? ''));
    $meta = '';
    $metaKlar = [];
    if ($pron !== '') {
        $meta .= '<i class="ti ti-speakerphone"></i> ' . h($pron) . ($showReferat ? ' · ' : '');
        $metaKlar[] = $pron;
    }
    if ($showReferat) {
        $meta .= '<i class="ti ti-briefcase"></i> ' . ($referat !== '' ? 'Referat ' . h($referat) : '<span class="muted">ohne Referat</span>');
        $metaKlar[] = $referat !== '' ? 'Referat ' . $referat : 'ohne Referat';
    }
    if ($meta !== '') $o .= '<p class="small muted mem-meta" title="' . h(implode(' · ', $metaKlar)) . '">' . $meta . '</p>';

    // Die eigenen Worte tragen die Karte: „Was ich im Referat mache" zuerst – das ist die Info,
    // für die man hier nachschaut. Fehlt sie, rückt „Über mich" nach. Beides wäre eine Textwand.
    $refD  = member_card_text((string)($m['referat_desc'] ?? ''));
    $about = member_card_text((string)($m['about_me'] ?? ''));
    $text  = $refD !== '' ? $refD : $about;
    $quelle = $refD !== '' ? 'ref' : 'about';
    $koerper = '';
    if ($text !== '' || $preview) {
        $koerper .= '<blockquote class="mem-quote mem-quote-' . $quelle . '" title="'
            . ($quelle === 'ref' ? 'Was ich im Referat mache' : 'Über mich') . '"'
            . ($text === '' ? ' hidden' : '') . '>' . h($text) . '</blockquote>';
    }
    if ($text === '' || $preview) {
        // Eine Einladung statt einer Fehlermeldung: „Steckbrief noch leer" mit durchgestrichenem
        // Stift wäre auf einer Karte ohne Text das Auffälligste überhaupt.
        $koerper .= '<p class="small muted mem-leer"' . ($text !== '' ? ' hidden' : '')
            . '>Erzählt noch nichts über sich.</p>';
    }
    $o .= $koerper;

    // Schlanker Fuß: laufende Streak und die ANZAHL der Erfolge – bewusst keine Pills, die machten
    // die Kachel unruhig. Was genau jemand geholt hat, steht im Profil; geheime bleiben geheim
    // (sie zählen nur mit, benannt werden sie nie).
    $st     = member_streak($id);
    $stCur  = (int)$st['current'];
    $stTier = streak_tier($stCur);
    $stStyle = member_flame_style($id);
    $nErfolge = 0; $nGeheim = 0;
    foreach (member_achievement_codes($id) as $c) {
        if (!isset($catalog[$c])) continue;
        $nErfolge++;
        if (!empty($catalog[$c]['hidden'])) $nGeheim++;
    }
    $streakHtml = ''; $achsHtml = '';
    if ($stCur >= 3) {
        $streakHtml = '<span class="mem-streak" title="' . $stCur . ' Tage am Stück in der App'
            . ((int)$st['best'] > $stCur ? ' (Bestmarke: ' . (int)$st['best'] . ')' : '') . '">'
            . '<span class="profil-flame st' . $stTier . ' lit' . ($stStyle ? ' ' . h($stStyle) : '') . '">'
            . flame_svg($stStyle, true) . '</span><strong>' . $stCur . '</strong></span>';
    }
    if ($nErfolge) {
        $achsHtml = '<span class="mem-achs" title="' . $nErfolge . ' Erfolg' . ($nErfolge === 1 ? '' : 'e') . ' geholt'
            . ($nGeheim ? ', davon ' . $nGeheim . ' geheim' : '') . ' – welche, steht im Profil">'
            . '<i class="ti ti-trophy"></i><strong>' . $nErfolge . '</strong></span>';
    }
    // Fußzeile: Streak links, Status in der Mitte, Erfolge rechts. Sie erscheint auch dann, wenn
    // es nur ein Status-Badge zu zeigen gibt.
    if ($streakHtml !== '' || $achsHtml !== '' || $status !== '') {
        $o .= '<div class="mem-trophies">' . $streakHtml
            . ($status !== '' ? '<span class="mem-status">' . $status . '</span>' : '')
            . $achsHtml . '</div>';
    }

    // Kartenkörper: Text und Zahlen sitzen zusammen auf einer eigenen Fläche, die bis an die
    // Kartenkanten läuft (Rundung und Ränder macht das Stylesheet). Der Text hing sonst als
    // loser Absatz unter den Metadaten und der reservierte Dreizeiler wirkte wie ein Loch.
    $trenn = strpos($o, '<blockquote') !== false ? '<blockquote' : '<p class="small muted mem-leer"';
    $pos = strpos($o, $trenn);
    if ($pos !== false) $o = substr($o, 0, $pos) . '<div class="mem-koerper">' . substr($o, $pos) . '</div>';
    return $o . '</div>';
}

/** Eigene Profil-Texte speichern (persönliche Referatsbeschreibung + „Über mich", je max. 2000 Zeichen). */
function member_save_profile_texts(int $memberId, string $referatDesc, string $aboutMe): bool
{
    if ($memberId <= 0) return false;
    $cut = fn(string $s) => mb_substr(trim($s), 0, 2000);
    db()->prepare('UPDATE members SET referat_desc = ?, about_me = ? WHERE id = ?')
        ->execute([$cut($referatDesc), $cut($aboutMe), $memberId]);
    return true;
}

/** Geburtstag setzen ('MM-TT'; Tag/Monat 0 = löschen). Nur Tag+Monat, bewusst ohne Jahr. */
function member_set_birthday(int $memberId, int $day, int $month): bool
{
    if ($memberId <= 0) return false;
    $val = '';
    if ($day > 0 || $month > 0) {
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) return false;
        $val = sprintf('%02d-%02d', $month, $day);
    }
    db()->prepare('UPDATE members SET birthday = ? WHERE id = ?')->execute([$val, $memberId]);
    return true;
}

/* ---------------------------------------------------------------------------
 * Telefonnummer im Profil – freiwillig und standardmäßig UNSICHTBAR.
 *
 * Die Nummer steht nie im Seitenquelltext: Die Profilseite zeigt nur einen Knopf mit der
 * Spielregel, die die Person selbst formuliert hat („nur im Notfall", „kein WhatsApp" …).
 * Erst wer im Dialog zusagt, holt sich die Nummer über einen eigenen kleinen Endpunkt.
 * ------------------------------------------------------------------------- */

/** Wählbare Fassung einer Telefonnummer (nur Ziffern und +); '' wenn nichts Wählbares bleibt. */
function phone_dial(string $roh): string
{
    $t = preg_replace('~[^0-9+]~', '', trim($roh));
    return preg_match('~[0-9]~', (string)$t) ? (string)$t : '';
}

/** Die hinterlegte Nummer eines Mitglieds (roh, wie eingetippt) – '' wenn keine da ist. */
function member_phone(array $m): string
{
    return trim((string)($m['phone'] ?? ''));
}

/** Die Spielregel dazu; ohne eigene Angabe ein neutraler Standardsatz. */
function member_phone_note(array $m): string
{
    $n = trim((string)($m['phone_note'] ?? ''));
    return $n !== '' ? $n : 'Es wurde nichts weiter angegeben – geh bitte sparsam damit um und nutze die Nummer nur, wenn es wirklich nötig ist.';
}

/** Hat jemand ausdrücklich gesagt, dass er/sie keine Nummer hinterlegen möchte? */
function member_phone_optout(array $m): bool
{
    return !empty($m['phone_none']) && trim((string)($m['phone'] ?? '')) === '';
}

/**
 * Telefonnummer speichern (leer = wieder entfernen). Gibt false zurück, wenn die Eingabe
 * offensichtlich keine Nummer ist – lieber melden als stillschweigend Unsinn ablegen.
 * $keine = „ich möchte keine angeben": räumt Nummer und Bitte weg und gilt als beantwortet.
 */
function member_set_phone(int $memberId, string $phone, string $note, bool $keine = false): bool
{
    if ($memberId <= 0) return false;
    $p = $keine ? '' : trim($phone);           // die bewusste Absage schlägt eine getippte Nummer
    if ($p !== '') {
        if (mb_strlen($p) > 40 || preg_match('~[^0-9+()/\s.-]~', $p)) return false;
        if (strlen(phone_dial($p)) < 4) return false; // vier Ziffern sind die unterste Schmerzgrenze
    }
    $n = $p === '' ? '' : mb_substr(trim($note), 0, 300); // ohne Nummer braucht es auch keine Regel
    db()->prepare('UPDATE members SET phone = ?, phone_note = ?, phone_none = ? WHERE id = ?')
        ->execute([$p, $n, $keine ? 1 : 0, $memberId]);
    return true;
}

/* ---------------------------------------------------------------------------
 * „Profil ausgefüllt?" – ein freundlicher Hinweis, KEINE echte Aufgabe.
 *
 * Bewusst OHNE Wirkung: zählt nicht in die offenen Aufgaben, geht in keinen Score ein und
 * löst nichts aus. Er verschwindet einfach, sobald alles beantwortet ist – und „keine
 * Telefonnummer" ist eine vollwertige Antwort.
 * Der Geburtstag steht bewusst NICHT auf der Liste: ein Datum, nach dem niemand gefragt
 * werden möchte, gehört nicht in eine Erinnerung.
 * ------------------------------------------------------------------------- */

/** Was im eigenen Profil noch fehlt: [['label' => …, 'anchor' => …], …]; leer = alles beantwortet. */
function profile_todo(?array $m): array
{
    if (!$m) return [];
    $offen = [];
    if (trim((string)($m['pronouns'] ?? '')) === '')     $offen[] = ['label' => 'Pronomen',                'anchor' => 'pronomen'];
    if (trim((string)($m['referat'] ?? '')) !== '' && trim((string)($m['referat_desc'] ?? '')) === '') {
        $offen[] = ['label' => 'Was du im Referat machst', 'anchor' => 'bearbeiten'];
    }
    if (trim((string)($m['about_me'] ?? '')) === '')     $offen[] = ['label' => 'Über dich',               'anchor' => 'bearbeiten'];
    if (trim((string)($m['phone'] ?? '')) === '' && !member_phone_optout($m)) {
        $offen[] = ['label' => 'Telefonnummer (oder: „möchte ich nicht")', 'anchor' => 'telefon'];
    }
    return $offen;
}

/** Geburtstag ('MM-TT') hübsch formatiert, z. B. „24. Juli" ('' bei ungesetzt/kaputt). */
function birthday_label(string $birthday): string
{
    if (!preg_match('/^(\d{2})-(\d{2})$/', trim($birthday), $m)) return '';
    $label = MONTHS[(int)$m[1]] ?? '';
    return $label !== '' ? (int)$m[2] . '. ' . $label : '';
}

/** Aktive Mitglieder, die HEUTE Geburtstag haben (fürs Dashboard-Ständchen + 🎂-Badges). */
function birthdays_today(): array
{
    try {
        $st = db()->prepare("SELECT * FROM members WHERE active = 1 AND birthday = ? ORDER BY name COLLATE NOCASE");
        $st->execute([date('m-d')]);
        return $st->fetchAll();
    } catch (\Throwable $e) { return []; }
}

/**
 * Aufräum-Erinnerung für den Vorsitz: Deaktivierte Konten sind nur für den Übergang gedacht.
 * Sobald EIN Konto seit 14 Tagen deaktiviert ist, kommen ALLE deaktivierten zurück (die
 * Dashboard-Karte zeigt die komplette Liste) – sie verschwindet erst, wenn keins mehr
 * deaktiviert ist (gelöscht oder reaktiviert).
 */
function deactivated_reminder(): array
{
    try {
        $rows = db()->query("SELECT id, name, deactivated_on FROM members WHERE active = 0
            ORDER BY deactivated_on, name COLLATE NOCASE")->fetchAll();
    } catch (\Throwable $e) { return []; }
    $frist = date('Y-m-d', strtotime('-14 days'));
    foreach ($rows as $r) {
        $d = trim((string)$r['deactivated_on']);
        if ($d !== '' && $d <= $frist) return $rows; // fällig – die ganze Liste zeigen
    }
    return [];
}

/** Laufende Abwesenheit eines Mitglieds (heute mittendrin), sonst null – für 🌴-Hinweise. */
function member_current_absence(int $memberId): ?array
{
    if ($memberId <= 0) return null;
    $st = db()->prepare('SELECT * FROM absences WHERE member_id = ? AND ? BETWEEN starts_at AND ends_at
                         ORDER BY ends_at DESC LIMIT 1');
    $st->execute([$memberId, date('Y-m-d')]);
    $a = $st->fetch();
    return $a ?: null;
}

/** Pinnwand eines Profils: Top-Einträge (neueste zuerst), jeder mit `replies` (chronologisch) darunter.
 *  LEFT JOIN: Einträge gelöschter Autor:innen bleiben stehen (author_name dann NULL → „Ehemaliges Mitglied"). */
function profile_posts_of(int $profileId): array
{
    if ($profileId <= 0) return [];
    $st = db()->prepare('SELECT p.*, m.name AS author_name, m.pronouns, m.avatar_decos, m.avatar_palette, m.avatar_ink
                         FROM profile_posts p LEFT JOIN members m ON m.id = p.author_id
                         WHERE p.profile_id = ? ORDER BY p.id ASC');
    $st->execute([$profileId]);
    $rows = $st->fetchAll();
    $replies = []; // parent_id => [Antworten chronologisch]
    foreach ($rows as $r) { if ((int)$r['parent_id'] > 0) $replies[(int)$r['parent_id']][] = $r; }
    $tops = [];
    foreach ($rows as $r) {
        if ((int)$r['parent_id'] !== 0) continue;
        $r['replies'] = $replies[(int)$r['id']] ?? [];
        $tops[] = $r;
    }
    return array_reverse($tops); // Top-Einträge neueste zuerst (Antworten bleiben chronologisch)
}

/** Ein einzelner Top-Pinnwand-Eintrag (ohne Antworten) – für Berechtigungs-/Ziel-Prüfungen. */
function profile_post_get(int $postId): ?array
{
    if ($postId <= 0) return null;
    $st = db()->prepare('SELECT * FROM profile_posts WHERE id = ?');
    $st->execute([$postId]);
    return $st->fetch() ?: null;
}

/**
 * Netten Pinnwand-Eintrag hinterlassen – nur auf FREMDEN Profilen, wählbar anonym (die Autor:in
 * bleibt technisch gespeichert, wird aber nirgends angezeigt). Max. 500 Zeichen; die Profil-Person
 * bekommt eine Dashboard-Mitteilung (Typ dm_profil, ohne Mail) mit „Ansehen"-Sprung zur Pinnwand.
 */
/**
 * „Pinnwand-Wache": informiert Mitglieder mit der ADMIN-Rolle per Push über jeden neuen Pinnwand-Eintrag
 * (opt-in, ab Werk aus – Typ dm_pin_watch). Bewusst NUR Push und KEINE Dashboard-Nachricht, damit das
 * Mitlesen nicht die Aufgabenliste flutet. Anonymität bleibt gewahrt (nie der echte Name).
 * Übersprungen werden Autor:in selbst und die Profil-Inhaber:in (die bekommt ihren eigenen Hinweis).
 */
function pinnwand_notify_admins(int $profileId, int $authorId, string $who, bool $isReply = false, string $body = ''): void
{
    // Ohne Profil gibt es nichts zu melden. Bewusst VOR der Log-Zeile unten: Ein Aufruf mit 0
    // ist ein leerer Aufruf (z. B. der No-Op-Check im Selbsttest), kein auflösbares Profil –
    // der wäre sonst als Fehler im Log gelandet und hätte einen Alarm vorgetäuscht.
    if ($profileId <= 0) return;
    try {
        $owner = member_get($profileId);
        // Vollen Namen zeigen, nicht die Kurzform: „Pinnwand von Lotta" hilft nur, wenn es
        // genau eine Lotta gibt. Fällt die Person weg, bleibt es beim neutralen Text.
        $ownerName = $owner ? trim((string)$owner['name']) : '';
        // Nie ein vages „jemandem" verschicken: Lässt sich die Person nicht auflösen (gelöscht,
        // Name leer, kaputte ID), nennt der Titel wenigstens die Profil-Nummer – und der Fall
        // landet im Fehler-Log, damit man ihn findet statt ihn nur zu lesen.
        if ($ownerName === '') {
            app_log_error('Pinnwand-Push: Profil ' . $profileId . ' nicht auflösbar (Name leer oder Mitglied fehlt)');
            $ownerName = 'Profil #' . $profileId;
        }
        $title = 'Pinnwand von ' . $ownerName . ' 📌';
        // WER + WAS in den Text: anonyme Einträge bleiben „Jemand", der Auszug zeigt den Inhalt.
        $excerpt = trim((string)preg_replace('/\s+/', ' ', $body));
        if (mb_strlen($excerpt) > 120) $excerpt = mb_substr($excerpt, 0, 117) . '…';
        $txt = $who . ($isReply ? ' hat geantwortet' : ' hat geschrieben')
            . ($excerpt !== '' ? ': „' . $excerpt . '"' : '.');
        $link = 'profil.php?id=' . $profileId . '#pinnwand';
        foreach (members_all() as $m) {
            $mid = (int)$m['id'];
            if (($m['role'] ?? '') !== 'admin') continue;          // bewusst NUR die Admin-Rolle (nicht Vorsitz)
            if ($mid === $authorId || $mid === $profileId) continue; // sich selbst / Empfänger:in nicht doppelt
            if (!notify_pref($mid, 'dm_pin_watch')['push']) continue; // opt-in
            push_send($mid, $title, $txt, app_url($link));
        }
    } catch (\Throwable $e) { /* Mitlese-Push darf das Posten nie brechen */ }
}

function profile_post_add(int $profileId, int $authorId, string $body, bool $anonymous): bool
{
    $body = mb_substr(trim($body), 0, 500);
    if ($profileId <= 0 || $authorId <= 0 || $profileId === $authorId || $body === '') return false;
    $author = member_get($authorId);
    if (!$author || !member_get($profileId)) return false;
    db()->prepare('INSERT INTO profile_posts(profile_id, author_id, parent_id, anonymous, body) VALUES(?,?,0,?,?)')
        ->execute([$profileId, $authorId, $anonymous ? 1 : 0, $body]);
    $wer = $anonymous ? 'Jemand' : short_name((string)$author['name']);
    dm_send($profileId, '', $wer . ' hat dir etwas auf deine Profil-Pinnwand geschrieben 💌',
        null, false, false, 'dm_profil', 'profil.php?id=' . $profileId . '#pinnwand');
    pinnwand_notify_admins($profileId, $authorId, $wer, false, $body); // Admin-Mitlese-Push (opt-in)
    return true;
}

/**
 * Auf einen Pinnwand-Eintrag antworten. Erlaubt nur den beiden Beteiligten: der Profil-Inhaber:in und
 * der Autor:in des Top-Eintrags. Die Autor:in erbt ihre Anonymität (blieb sie anonym, bleibt es auch die
 * Antwort); die Profil-Inhaber:in schreibt namentlich. Die jeweils ANDERE Person bekommt einen Hinweis.
 */
function profile_post_reply(int $parentId, int $authorId, string $body): bool
{
    $body = mb_substr(trim($body), 0, 500);
    if ($parentId <= 0 || $authorId <= 0 || $body === '') return false;
    $top = profile_post_get($parentId);
    if (!$top || (int)$top['parent_id'] !== 0) return false; // nur auf Top-Einträge antworten (keine Schachtel-Tiefe)
    $ownerId = (int)$top['profile_id'];
    $topAuthor = (int)$top['author_id'];
    if ($authorId !== $ownerId && $authorId !== $topAuthor) return false; // nur die zwei Beteiligten
    $author = member_get($authorId);
    if (!$author) return false;
    $anon = ($authorId === $topAuthor && $authorId !== $ownerId) ? (int)$top['anonymous'] : 0; // Anonymität der Autor:in erben
    db()->prepare('INSERT INTO profile_posts(profile_id, author_id, parent_id, anonymous, body) VALUES(?,?,?,?,?)')
        ->execute([$ownerId, $authorId, $parentId, $anon, $body]);
    $wer = $anon ? 'Jemand' : short_name((string)$author['name']);
    $link = 'profil.php?id=' . $ownerId . '#pinnwand';
    foreach (array_unique([$ownerId, $topAuthor]) as $rid) {
        if ((int)$rid === $authorId || (int)$rid <= 0) continue; // nicht sich selbst
        $txt = (int)$rid === $ownerId
            ? $wer . ' hat auf einen Eintrag auf deiner Profil-Pinnwand geantwortet 💬'
            : $wer . ' hat auf deinen Pinnwand-Eintrag geantwortet 💬';
        dm_send((int)$rid, '', $txt, null, false, false, 'dm_profil', $link);
    }
    pinnwand_notify_admins($ownerId, $authorId, $wer, true, $body); // Admin-Mitlese-Push (opt-in)
    return true;
}

/** Pinnwand-Eintrag löschen: Autor:in, Profil-Inhaber:in (die eigene Pinnwand gehört einem selbst) oder Admin.
 *  Beim Löschen eines Top-Eintrags fallen dessen Antworten mit weg. */
function profile_post_delete(int $postId, ?array $me): bool
{
    if ($postId <= 0 || !$me) return false;
    $p = profile_post_get($postId);
    if (!$p) return false;
    $meId = (int)($me['id'] ?? 0);
    if ($meId !== (int)$p['author_id'] && $meId !== (int)$p['profile_id'] && !can_admin()) return false;
    db()->prepare('DELETE FROM profile_posts WHERE id = ? OR parent_id = ?')->execute([$postId, $postId]);
    return true;
}

/** Nur die Avatar-Bubble (Verlauf + getragener Schmuck), OHNE Namen – für Fließtext-Kontexte, in denen
 *  der Name schon danebensteht (z. B. Absender einer Dashboard-Nachricht). Erwartet name/avatar_decos/avatar_palette. */
function avatar_bubble(array $m, string $extraCls = ''): string
{
    $accs = ''; $seen = [];
    foreach (array_filter(array_map('trim', explode(',', (string)($m['avatar_decos'] ?? '')))) as $k) {
        if (avatar_deco_svg($k) === '') continue;
        $seen[avatar_deco_slot($k)] = $k; // pro Slot das letzte Stück
    }
    foreach ($seen as $slot => $k) $accs .= '<span class="avatar-acc acc-' . $slot . ' acc-k-' . h($k) . '">' . avatar_deco_svg($k) . '</span>';
    $ini = h(mb_substr(first_name((string)($m['name'] ?? '')), 0, 1) ?: '?');
    // Die .av-ini-Hülle um den Buchstaben: nötig für Verlaufs-Schriftfarben (background-clip
    // schneidet den Verlauf des ELEMENTS zu – auf dem Avatar selbst läge er über der Füllung).
    return '<span class="gt-av' . member_avatar_class($m) . $extraCls . '" style="' . h(member_avatar_style($m)) . '">'
         . '<span class="av-ini">' . $ini . '</span>' . $accs . '</span>';
}

/** CSS-Zusatzklassen fürs Avatar-Element: animierte Paletten liefern ' pal-<key>',
 *  Verlaufs-Schriftfarben (avatar_inks mit 'grad') zusätzlich ' ink-<key>' – deren Regel in
 *  style.css legt den Verlauf über die .av-ini-Hülle auf die Buchstaben. */
function member_avatar_class(array $m): string
{
    $cls = '';
    $key = trim((string)($m['avatar_palette'] ?? ''));
    $p = avatar_palettes()[$key] ?? null;
    if ($p && !empty($p['anim'])) $cls .= ' pal-' . $key;
    $ink = trim((string)($m['avatar_ink'] ?? ''));
    $t = avatar_inks()[$ink] ?? null;
    if ($t && !empty($t['grad'])) $cls .= ' ink-' . $ink;
    return $cls;
}

/** Animiert „brennende" Streak-Flamme als mehrlagige Inline-SVG (außen/innen/Kern).
 *  Silhouette = die klassische Tabler-Flamme (ti-flame, asymmetrisch mit Schwung);
 *  innen/Kern sind an der Basis (12|21) verankerte, skalierte Kopien derselben Form.
 *  Farben kommen aus CSS-Variablen (--fl1/--fl2/--fl3), das Flackern aus style.css –
 *  die Stufe (st0–st4) setzt der umgebende Container. Einzige Quelle der Flammen-Form;
 *  das Streak-Overlay bekommt dieselbe SVG über window.ASTA_FLAME_SVG gereicht. */
/** $mini = für sehr kleine Darstellungen (Mitglieder-Karten, ~15 px): gröbere Formen, gleiche
 *  Ankerpunkte. Nur der Blumen-Stil braucht das – die anderen sind einfache Silhouetten. */
/**
 * Krone für die Karten der Spitzenklasse (~34 px breit).
 *
 * Bewusst getrennt von der Avatar-Krone in avatar_deco_svg('crown'): Dort sitzt sie auf einem
 * Kopf und darf grob sein, hier steht sie frei auf einer Kartenkante. Deshalb geschwungene
 * Flanken statt Zickzack, gerundete Zacken mit Perlen, ein Reif mit Fuge und drei Steine.
 * Ohne Verlauf gezeichnet – ein <linearGradient> bräuchte eine id, und die käme auf einer Seite
 * mit vielen Karten dutzendfach vor. Flache Flächen sind bei dieser Größe ohnehin sauberer.
 */
function mem_crown_svg(): string
{
    return '<svg viewBox="0 0 64 44" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
         // Zackenkörper: die Flanken schwingen leicht ein, die Täler sind gerundet.
         . '<path d="M6.5 33 L4.6 13.4 Q 4.7 11.4 6.4 12.6 L17.2 20.6 Q 18.6 21.6 19.6 20.2'
         .   ' L30.6 5.6 Q 32 3.8 33.4 5.6 L44.4 20.2 Q 45.4 21.6 46.8 20.6 L57.6 12.6'
         .   ' Q 59.3 11.4 59.4 13.4 L57.5 33 Z" fill="#f4cd54" stroke="#c8901f" stroke-width="2.2" stroke-linejoin="round"/>'
         // Lichtkante innen: gibt Tiefe, ohne einen Verlauf zu brauchen.
         . '<path d="M9.6 30.4 L8.2 15.6 L17.4 22.4 Q 19 23.5 20.2 22" fill="none" stroke="#ffe9a3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" opacity=".75"/>'
         // Perlen auf den Zacken
         . '<g fill="#ffe9a3" stroke="#c8901f" stroke-width="1.6">'
         .   '<circle cx="5" cy="10.4" r="3"/><circle cx="32" cy="4.2" r="3.4"/><circle cx="59" cy="10.4" r="3"/>'
         . '</g>'
         // Reif mit Fuge
         . '<rect x="5.2" y="30.6" width="53.6" height="11" rx="3.6" fill="#e0a92e" stroke="#c8901f" stroke-width="2.2"/>'
         . '<rect x="9" y="33.6" width="46" height="1.9" rx=".95" fill="#c8901f" opacity=".38"/>'
         // Steine: mittig rot, außen blau – symmetrisch, damit es bei 34 px ruhig bleibt.
         . '<circle cx="32" cy="36.4" r="3.3" fill="#e0533d" stroke="#a9331f" stroke-width="1.1"/>'
         . '<circle cx="19" cy="36.4" r="2" fill="#5f9fd8" stroke="#3d76a8" stroke-width="1"/>'
         . '<circle cx="45" cy="36.4" r="2" fill="#5f9fd8" stroke="#3d76a8" stroke-width="1"/>'
         . '</svg>';
}

function flame_svg(string $style = '', bool $mini = false): string
{
    // Kaffee-Stil („Koffein-Modus"): dampfender Becher – eigene Silhouette (Becher + Henkel +
    // drei aufsteigende Dampf-Schwaden) statt der geschichteten Flamme; Farben/Dampf aus .acoffee-CSS.
    if ($style === 'coffee') {
        return '<svg class="aflame acoffee" width="26" height="32" viewBox="0 0 26 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
             . '<g class="cf-steam">'
             .   '<path class="cf-s cf-s1" d="M8 11 C 6 8.5, 9.5 7, 7.5 4"/>'
             .   '<path class="cf-s cf-s2" d="M13 11 C 11 8.5, 14.5 7, 12.5 4"/>'
             .   '<path class="cf-s cf-s3" d="M18 11 C 16 8.5, 19.5 7, 17.5 4"/>'
             . '</g>'
             . '<path class="cf-handle" d="M18 15 h2.4 a3.6 3.6 0 0 1 0 7.2 h-2.4"/>'
             . '<path class="cf-mug" d="M4 13 H18 V19 A7 7 0 0 1 11 26 A7 7 0 0 1 4 19 Z"/>'
             . '<rect class="cf-brew" x="6.2" y="14.4" width="9.6" height="3" rx="1.5"/>'
             . '</svg>';
    }
    // Heiligenschein („Technik-Engel"): ein liegender Ring statt eines Flammenkörpers. Die Stufen
    // legt style.css über die .aschein-Variablen an – hier stehen nur die Teile: die Strahlen und
    // ZWEI Ellipsen (satte Kante plus helle Innenlinie, sonst wirkt der Ring flach). KEINE
    // gefüllte Schein-Ellipse dahinter: Den Lichtschein macht .aflame ohnehin per drop-shadow,
    // eine zweite Fläche säße als undurchsichtiger Fleck hinter dem Ring. Bewusst eine flache
    // Ellipse und kein Kreis: Erst die Perspektive lässt sie über einem Kopf schweben, ein Kreis
    // wäre ein Reifen.
    if ($style === 'schein') {
        // Der Lichtkegel steht ZUERST im Markup und liegt damit hinter dem Ring. Er beginnt an
        // der Ringbreite und läuft nach unten auseinander – Licht, das durch den Reif fällt.
        // Der Verlauf braucht eine id, und die kommt auf einer Seite mit vielen Streaks mehrfach
        // vor; das ist hier hinnehmbar, weil alle Fassungen identisch sind (der Mond macht es mit
        // seinem clipPath genauso). Die Alternative – gestapelte Bänder mit fallender Deckkraft –
        // war gebaut und sichtbar gestreift. filter: blur() scheidet aus: SVG schneidet ihn an
        // der Kante ab. Die Farbe steht nicht hier, sondern als stop-color im Stylesheet, damit
        // sie mit der Stufe wechselt.
        return '<svg class="aflame aschein" width="30" height="30" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
             . '<defs><linearGradient id="sc-licht" x1="20" y1="21" x2="20" y2="40" gradientUnits="userSpaceOnUse">'
             .   '<stop class="sc-k1" offset="0"/><stop class="sc-k2" offset="1"/></linearGradient></defs>'
             . '<path class="sc-kegel" d="M7 21H33L40 40H0Z" fill="url(#sc-licht)"/>'
             . '<g class="sc-rays">'
             .   '<path d="M20 3.5 V8"/><path d="M6 10 L9.5 13"/><path d="M34 10 L30.5 13"/>'
             .   '<path d="M4 26 L8 24"/><path d="M36 26 L32 24"/>'
             . '</g>'
             . '<g class="sc-ring">'
             .   '<ellipse class="sc-r1" cx="20" cy="20" rx="14" ry="5"/>'
             .   '<ellipse class="sc-r2" cx="20" cy="20" rx="14" ry="5"/>'
             . '</g>'
             . '</svg>';
    }
    // Blumen-Stil („Sempervivium" – immer lebendig; das zusätzliche i ist Absicht, siehe
    // achievements.php): wächst über die Stufen von der Knospe zur offenen Blüte und wird
    // dabei jeden Durchlauf gegossen. Wie beim Kaffee eine EIGENE Silhouette statt der Schicht-
    // Flamme – alle Teile stecken immer in der SVG, sichtbar macht sie die Stufe (--bl-*) in
    // style.css. Reihenfolge im Markup ist Zeichenreihenfolge: Blütenblätter, darauf die Mitte,
    // darauf die Knospe (die auf den unteren Stufen die noch geschlossene Blüte verdeckt).
    if ($style === 'flower') {
        // Zwei Ausbaustufen derselben Pflanze. Die Ankerpunkte sind in BEIDEN gleich (Erdlinie
        // 13|28, Blattansätze 12.8|23.6 und 13.2|20, Knospe 13|13.6, Blüte 13|10) – die
        // transform-origin-Werte in style.css sind absolute Nutzereinheiten, also darf sich die
        // viewBox nicht ändern. Nur die Formen werden gröber.
        //
        // Die Blüte ist bewusst eine VOLLE Rosette aus zwei Kränzen ovaler Blätter statt einer
        // botanischen Skizze mit fünf Spitzen: Im Blumenwiesen-Skin steht sie neben lauter runden
        // Blüten aus genau solchen Ovalen, daneben wirkt eine zarte Fassung verloren. Zwei Kränze,
        // versetzt, sind außerdem das, was eine Hauswurz ausmacht.
        //
        // KEINE Drehung beim Aufgehen und keine Tupfen auf den Blattenden: Mit schmalen Blättern
        // sieht die Blüte sonst aus wie ein Windrad. Was eine Blume davon unterscheidet, sind
        // BREITE, kurze Blätter und ein großer Kern – schmale Speichen um eine kleine Nabe sind
        // eine Turbine. Aufgehen heißt nur größer werden.
        //
        // Die ganze Blüte steckt in .bl-bloom, und nur sie strahlt. Flamme, Herz, Blitz und Stern
        // leuchten, weil ihre GESAMTE Silhouette glüht; über einer Pflanze läge derselbe Schein
        // flach über allem, auch über der braunen Erde, und verpuffte dort.
        $kranz = function (int $n, float $cy, float $rx, float $ry, float $versatz = 0.0): string {
            $out = '';
            $schritt = 360 / $n;
            for ($i = 0; $i < $n; $i++) {
                $w = $versatz + $i * $schritt;
                $out .= '<ellipse cx="13" cy="' . $cy . '" rx="' . $rx . '" ry="' . $ry . '"'
                      . ($w ? ' transform="rotate(' . round($w, 2) . ' 13 10)"' : '') . '/>';
            }
            return $out;
        };
        // Funkeln – nur auf der letzten Stufe sichtbar (siehe --bl-spark in style.css).
        $funke = fn(float $x, float $y, float $r) =>
            '<path d="M' . $x . ' ' . ($y - $r) . ' Q ' . $x . ' ' . $y . ', ' . ($x + $r) . ' ' . $y
          . ' Q ' . $x . ' ' . $y . ', ' . $x . ' ' . ($y + $r)
          . ' Q ' . $x . ' ' . $y . ', ' . ($x - $r) . ' ' . $y
          . ' Q ' . $x . ' ' . $y . ', ' . $x . ' ' . ($y - $r) . ' Z"/>';
        $funken = '<g class="bl-spark">' . $funke(3.6, 5.2, 2.9) . $funke(22.4, 7.4, 2.5)
                . $funke(19.8, 0.6, 2) . $funke(6.4, 0.2, 1.7) . '</g>';

        if ($mini) {
            // Klein gezeichnet: flacherer Boden, gerader dicker Stängel, zwei fette Blätter.
            // Der Wassertropfen fällt weg – bei 15 px ist er ein Pixel.
            return '<svg class="aflame aflower aflower-mini" width="26" height="34" viewBox="0 -2 26 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
                 . '<path class="bl-soil" d="M2 31.6 C 4 27.8, 8.6 27, 13 27 C 17.4 27, 22 27.8, 24 31.6 Z"/>'
                 . '<ellipse class="bl-seed" cx="13" cy="29.2" rx="2.9" ry="2" transform="rotate(-22 13 29.2)"/>'
                 . '<g class="bl-plant">'
                 .   '<path class="bl-stem" d="M13 28 L13 11"/>'
                 .   '<path class="bl-leaf bl-l1" d="M12.6 23.6 C 8.2 23.6, 6.3 21.3, 6.6 18.6 C 10.5 18.9, 12.4 20.9, 12.6 23.6 Z"/>'
                 .   '<path class="bl-leaf bl-l2" d="M13.4 20 C 17.8 20, 19.7 17.7, 19.4 15 C 15.5 15.3, 13.6 17.3, 13.4 20 Z"/>'
                 .   '<g class="bl-bloom">'
                 .     '<g class="bl-petals">' . $kranz(8, 4.9, 3.5, 5.2) . '</g>'
                 .     '<g class="bl-petals bl-petals2">' . $kranz(8, 6.7, 2.4, 3.4, 22.5) . '</g>'
                 .     '<circle class="bl-core" cx="13" cy="10" r="3.9"/>'
                 .     '<circle class="bl-core bl-core2" cx="13" cy="10" r="1.7"/>'
                 .   '</g>'
                 .   '<path class="bl-bud" d="M13 3 C 17.2 6.2, 17.9 10.1, 16 13 C 14.8 14.7, 11.2 14.7, 10 13 C 8.1 10.1, 8.8 6.2, 13 3 Z"/>'
                 . '</g>'
                 . '</svg>';
        }
        // Der Boden steht AUSSERHALB der Pflanzen-Gruppe: er wächst nicht mit und wiegt sich
        // nicht im Wind. In der ersten Phase ist er alles, was man sieht – mit dem Samen darin.
        return '<svg class="aflame aflower" width="26" height="34" viewBox="0 -2 26 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
             . '<path class="bl-drop" d="M13 1.2 C 14.3 3.1, 14.9 4.1, 14.9 4.9 A 1.9 1.9 0 0 1 11.1 4.9 C 11.1 4.1, 11.7 3.1, 13 1.2 Z"/>'
             . '<path class="bl-soil" d="M3 31.4 C 4.5 28.2, 8.8 27.4, 13 27.4 C 17.2 27.4, 21.5 28.2, 23 31.4 Z"/>'
             . '<ellipse class="bl-seed" cx="13" cy="29.4" rx="2.3" ry="1.6" transform="rotate(-22 13 29.4)"/>'
             . $funken
             . '<g class="bl-plant">'
             .   '<path class="bl-stem" d="M13 28 C 12.4 23, 13.6 18.5, 13 12"/>'
             .   '<path class="bl-leaf bl-l1" d="M12.8 23.6 C 8.8 22.8, 7.1 20.5, 7.5 18.8 C 10.2 18.7, 12.3 20.7, 12.8 23.6 Z"/>'
             .   '<path class="bl-leaf bl-l2" d="M13.2 20 C 17.2 19.2, 18.9 16.9, 18.5 15.2 C 15.8 15.1, 13.7 17.1, 13.2 20 Z"/>'
             .   '<g class="bl-bloom">'
             .     '<g class="bl-petals">' . $kranz(8, 4.6, 3.4, 5.5) . '</g>'
             .     '<g class="bl-petals bl-petals2">' . $kranz(8, 6.6, 2.3, 3.5, 22.5) . '</g>'
             .     '<circle class="bl-core" cx="13" cy="10" r="3.7"/>'
             .     '<circle class="bl-core bl-core2" cx="13" cy="10" r="1.6"/>'
             .   '</g>'
             .   '<path class="bl-bud" d="M13 3.6 C 16.3 6.4, 16.9 9.8, 15.4 12.4 C 14.5 14, 11.5 14, 10.6 12.4 C 9.1 9.8, 9.7 6.4, 13 3.6 Z"/>'
             . '</g>'
             . '</svg>';
    }
    // Rakete („to the moon", Kauf-Stil): eigene Silhouette wie der Kaffee – Schiff mit Bullauge
    // und Finnen, darunter der Schub in zwei Feuerlagen. Sichtbarkeit/Größe des Feuers und das
    // Beben ab den hohen Stufen steuern die --rk-Variablen in style.css.
    if ($style === 'rakete') {
        return '<svg class="aflame arakete" width="26" height="34" viewBox="0 0 26 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
             . '<g class="rk-ship">'
             .   '<path class="rk-fin rk-f1" d="M7.6 12.5 L3 20.5 L7.6 18.2 Z"/>'
             .   '<path class="rk-fin rk-f2" d="M18.4 12.5 L23 20.5 L18.4 18.2 Z"/>'
             .   '<path class="rk-body" d="M13 1.2 C 17.4 5.6, 18.5 12, 18.5 17.6 L7.5 17.6 C 7.5 12, 8.6 5.6, 13 1.2 Z"/>'
             .   '<circle class="rk-window" cx="13" cy="9.8" r="2.5"/>'
             . '</g>'
             . '<g class="rk-fire">'
             .   '<path class="rk-fl1" d="M13 18.6 C 16.2 22.8, 15.8 27.6, 13 32.4 C 10.2 27.6, 9.8 22.8, 13 18.6 Z"/>'
             .   '<path class="rk-fl2" d="M13 20 C 14.9 23, 14.7 26.4, 13 29.4 C 11.3 26.4, 11.1 23, 13 20 Z"/>'
             . '</g>'
             . '</svg>';
    }
    // Mondphasen („Mondsüchtig"): Der Mond wechselt mit der Stufe seine Phase – von der schmalen
    // Sichel zum Vollmond. Umgesetzt als EINE Scheibe plus ein zweiter, deckender Kreis davor, der
    // sie abschattet: Wie weit der Schatten zur Seite geschoben ist, sagt --mo-x in style.css.
    // Genau so funktioniert die Phase in echt (Schattengrenze wandert), und man braucht nur EINE
    // Zeichnung statt fünf. Die Krater sitzen auf der Scheibe, der Hof liegt außen für die 100.
    if ($style === 'mond') {
        return '<svg class="aflame amond" width="30" height="34" viewBox="0 0 30 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
             . '<defs><clipPath id="mo-clip"><circle cx="15" cy="17" r="12"/></clipPath></defs>'
             // Am Mond selbst passiert NICHTS außer der Phase – rotierende Strahlenkeile an der
             // Scheibe sehen aus wie eine Windmühle. Das Licht gehört ins Feld, nicht an
             // die Scheibe: Es liegt als Strahlenbündel in der Aura (.aura-mond in style.css).
             . '<circle class="mo-hof" cx="15" cy="17" r="12"/>'
             . '<g clip-path="url(#mo-clip)">'
             .   '<circle class="mo-disk" cx="15" cy="17" r="12"/>'
             .   '<g class="mo-krater"><circle cx="11" cy="12.5" r="2.6"/><circle cx="19.5" cy="20" r="2"/><circle cx="13" cy="22.5" r="1.5"/></g>'
             .   '<circle class="mo-schatten" cx="15" cy="17" r="12"/>'
             . '</g>'
             . '</svg>';
    }
    // Regenbogen („Lichtblicke", Kauf-Stil): Der Bogen wächst nicht in der Größe, sondern in der
    // ZAHL SEINER FARBEN – alle sechs Streifen stecken immer im SVG, wie viele davon zu sehen sind,
    // sagt die Stufe über --rb-o1…6 in style.css. Dazu zwei Ebenen, die nur die 100 zeigt: der
    // Nebenregenbogen (größerer Radius, Farbfolge UMGEKEHRT wie in der Natur) und ein Lichtwisch,
    // der über alle Streifen entlangwandert. Grundlinie y=24, Mittelpunkt x=18 – wer Radien ändert,
    // muss beide Enden mitziehen (Bogen von cx-r bis cx+r).
    if ($style === 'regenbogen') {
        return '<svg class="aflame aregenbogen" width="36" height="26" viewBox="0 0 36 26" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
             . '<g class="rb-neben" fill="none" stroke-width="1.1" stroke-linecap="round">'
             .   '<path d="M1 24 A17 17 0 0 1 35 24" stroke="#9a6bd6"/>'
             .   '<path d="M2 24 A16 16 0 0 1 34 24" stroke="#4f9be0"/>'
             .   '<path d="M3 24 A15 15 0 0 1 33 24" stroke="#5ab86f"/>'
             .   '<path d="M4 24 A14 14 0 0 1 32 24" stroke="#e0607a"/>'
             . '</g>'
             . '<g class="rb-haupt" fill="none" stroke-width="1.6" stroke-linecap="round">'
             .   '<path class="rb-b rb-b1" d="M5.4 24 A12.6 12.6 0 0 1 30.6 24"/>'
             .   '<path class="rb-b rb-b2" d="M7 24 A11 11 0 0 1 29 24"/>'
             .   '<path class="rb-b rb-b3" d="M8.6 24 A9.4 9.4 0 0 1 27.4 24"/>'
             .   '<path class="rb-b rb-b4" d="M10.2 24 A7.8 7.8 0 0 1 25.8 24"/>'
             .   '<path class="rb-b rb-b5" d="M11.8 24 A6.2 6.2 0 0 1 24.2 24"/>'
             .   '<path class="rb-b rb-b6" d="M13.4 24 A4.6 4.6 0 0 1 22.6 24"/>'
             . '</g>'
             . '<path class="rb-glanz" d="M9.4 24 A8.6 8.6 0 0 1 26.6 24" fill="none" stroke-width="9.6" stroke-linecap="butt"/>'
             . '</svg>';
    }
    // Flamme, Herz, Blitz und Stern teilen sich das dreilagige Schicht-System (außen/innen/Kern):
    // die inneren Kopien sind am Anker (ax|ay) fixiert – translate = punkt * (1 - scale). Flamme/Herz
    // verankern an der Basis (12|21) und tanzen; Blitz/Stern verankern in der Mitte (12|12) → heller Kern.
    switch ($style) {
        case 'heart':
            $d = 'M12 21 C 5.2 16.2, 3 11.8, 3 8.8 C 3 6.2, 5 4.2, 7.5 4.2 C 9.4 4.2, 11.1 5.6, 12 7.3 C 12.9 5.6, 14.6 4.2, 16.5 4.2 C 19 4.2, 21 6.2, 21 8.8 C 21 11.8, 18.8 16.2, 12 21 Z';
            $vb = '2.5 3.7 19 17.8'; $mod = ' aheart'; $ax = 12; $ay = 21; break;
        case 'bolt': // „Hochspannung" – klassischer Blitz, Spitze unten
            $d = 'M13 3 L13 10 L19 10 L11 21 L11 14 L5 14 Z';
            $vb = '3.5 1.5 17 21'; $mod = ' abolt'; $ax = 12; $ay = 12; break;
        case 'star': // „Sternstunde" – fünfzackiger Stern
            $d = 'M12 1.5 L15.09 8.26 L22.5 9.04 L16.95 14.02 L18.51 21.5 L12 17.6 L5.49 21.5 L7.05 14.02 L1.5 9.04 L8.91 8.26 Z';
            $vb = '1 1 22 22'; $mod = ' astar'; $ax = 12; $ay = 12; break;
        case 'diamant': // Kauf-Stil („diamond hands") – klassischer Schliff, heller Kern in der Mitte
            $d = 'M7 3.5 L17 3.5 L21.5 9.5 L12 21 L2.5 9.5 Z';
            $vb = '1.5 2.5 21 19.5'; $mod = ' adiamant'; $ax = 12; $ay = 11; break;
        default: // Flamme
            $d = 'M12 12c2 -2.96 0 -7 -1 -8c0 3.038 -1.773 4.741 -3 6c-1.226 1.26 -2 3.24 -2 5a6 6 0 1 0 12 0c0 -1.532 -1.056 -3.94 -2 -5c-1.786 3 -2.791 3 -4 2z';
            $vb = '5.5 3.5 13 18.2'; $mod = ''; $ax = 12; $ay = 21;
    }
    $t1 = 'translate(' . round($ax * 0.38, 2) . ' ' . round($ay * 0.38, 2) . ') scale(.62)';
    $t2 = 'translate(' . round($ax * 0.66, 2) . ' ' . round($ay * 0.66, 2) . ') scale(.34)';
    // Grundgröße aus der viewBox (siehe avatar_deco_svg): ohne width/height rendert Firefox
    // die Flamme vor dem Stylesheet über die ganze Breite und sie blitzt beim Seitenwechsel auf.
    [, , $vbW, $vbH] = array_map('floatval', explode(' ', $vb));
    return '<svg class="aflame' . $mod . '" width="' . $vbW . '" height="' . $vbH . '" viewBox="' . $vb . '" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
         . '<g class="fl-outer"><path d="' . $d . '"/></g>'
         . '<g class="fl-inner"><path d="' . $d . '" transform="' . $t1 . '"/></g>'
         . '<g class="fl-core"><path d="' . $d . '" transform="' . $t2 . '"/></g>'
         . '</svg>';
}

/** Streak-Stile jenseits der Standard-Flamme: Stil-Key => Achievement-Code, der ihn freischaltet.
 *  Einzige Quelle der Wahrheit – Migration, Freischaltprüfung und Selbsttest lesen hier. */
function flame_styles_unlock(): array
{
    return [
        'heart'  => 'pinkdream',  // „Pretty in Pink" – schlagendes Herz
        'bolt'   => 'streak_50',  // „Hochspannung" – die 50er-Streak knistert
        'star'   => 'spitze',     // „Sternstunde" – Status wie das Royal-Design (nur im Spitzen-Score-Band)
        'coffee' => 'opens_20',   // „Koffein-Modus" – wer 20× am Tag reinschaut, läuft auf Kaffee
        // „Jahresringe" – ein ganzes Jahr AStA. Bewusst KEIN Streak-Achievement: sonst hätte man
        // den Stil erst, wenn die Blume ohnehin schon in voller Blüte steht, und sähe sie nie
        // wachsen. So darf sie bei jedem Stand ausgewählt werden und legt von vorn los.
        'flower' => 'active_52w',
        // „Mondsüchtig" – vier Nächte hintereinander zwischen 0 und 5 Uhr (siehe activity_track)
        'mond'   => 'mondsucht',
        // Der Technik-Engel trägt einen RING statt einer Flamme: Er ist auch bei 20 px in der
        // Liste noch als Ring zu erkennen. Eine Feder wäre dort nur ein Fleck.
        'schein' => 'technik_engel',
    ];
}

/** Streak-Stile aus dem AsT-Sortiment (kaufbar statt verdient). */
function flame_styles_shop(): array
{
    return array_keys(array_filter(shop_items(), fn ($i) => ($i['type'] ?? '') === 'flame'));
}

/** ALLE Sonder-Stile (verdient + kaufbar). Wer wissen will, ob ein Stil überhaupt existiert,
 *  fragt hier – sonst fällt ein Kauf-Stil irgendwo als „unbekannt" auf die Flamme zurück. */
function flame_styles_all(): array
{
    return array_merge(array_keys(flame_styles_unlock()), flame_styles_shop());
}

/**
 * „Pretty in Pink": welche KOPF-Accessoires zählen als passender Kopfschmuck zum Rosa-Outfit?
 * Nicht nur das Diadem – auch die rosa Schleife, die Lockenwickler und die Royal-Krone.
 * EINE Quelle für beide Prüfstellen (page_header-Flag + serverseitige Nachprüfung in achievements.php).
 */
function pinkdream_head_decos(): array
{
    return ['tiara', 'pinkbow', 'curlers', 'crown'];
}

/** Trägt die Person gerade eines der „Pretty in Pink"-taugenden Kopfstücke? */
function pinkdream_head_ok(int $memberId): bool
{
    if ($memberId <= 0) return false;
    return (bool)array_intersect(pinkdream_head_decos(), array_values(member_equipped_decos($memberId)));
}

/** Wort für die Streak-Einheit je Stil (Wording „X Flammen/Herzen/Blitze/Sterne/Tassen"). */
function flame_style_word(string $style, int $count = 2): string
{
    $one = $count === 1;
    switch ($style) {
        case 'heart':  return $one ? 'Herz'   : 'Herzen';
        case 'bolt':   return $one ? 'Blitz'  : 'Blitze';
        case 'star':   return $one ? 'Stern'  : 'Sterne';
        case 'coffee': return $one ? 'Tasse'  : 'Tassen';
        case 'rakete': return $one ? 'Schub'  : 'Schübe';
        // Beim Diamanten zählt kein Stück, sondern das Gewicht – „7 Diamanten" wäre falsch,
        // es ist EIN Stein, der wächst. Karat bleibt in beiden Zahlformen gleich.
        case 'diamant': return 'Karat';
        // Bei der Blume zählt kein Gegenstand, sondern das tägliche Gießen – „7 Blüten" wäre
        // auch falsch, es ist ja EINE Blume, die wächst.
        case 'flower': return $one ? 'Gießtag' : 'Gießtage';
        // Regenbogen: Auch hier ist es EIN Bogen, der Farben dazugewinnt – gezählt wird das,
        // wofür er steht. „7 Regenbögen" wäre wieder der falsche Gegenstand.
        case 'regenbogen': return $one ? 'Lichtblick' : 'Lichtblicke';
        // Mond: gezählt werden die Nächte, in denen er stand – nicht die Monde selbst.
        case 'mond': return $one ? 'Nacht' : 'Nächte';
        case 'schein': return $one ? 'Tag im Licht' : 'Tage im Licht';
        default:       return $one ? 'Flamme' : 'Flammen';
    }
}

/**
 * Stufe einer Streak (0–5) – EINE Quelle für Farbe, Tempo, Wording und Aura.
 *   0 = unter 3 Tagen (kalt, es brennt noch nichts), 1 ab 3, 2 ab 7, 3 ab 30, 4 ab 50,
 *   5 ab 100 Tagen. Stufe 5 ist die einzige, die aus ihrer Kachel heraus wirkt (siehe
 *   streak_aura_html()).
 * ACHTUNG: app.js hat für das Meilenstein-Overlay einen Zwilling (Funktion tier()).
 * (Ohne Pfad geschrieben: Der Asset-Wächter im Selbsttest liest jedes „assets/…" in dieser
 * Datei als Einbindung ohne Versions-Kennung – auch im Kommentar.)
 * Wer hier eine Schwelle ändert, muss sie DORT MIT ändern – der Selbsttest vergleicht beide.
 */
function streak_tier(int $days): int
{
    if ($days < 3)   return 0;
    if ($days < 7)   return 1;
    if ($days < 30)  return 2;
    if ($days < 50)  return 3;
    if ($days < 100) return 4;
    return 5;
}

/**
 * Ab Stufe 5 (100 Tage) bleibt der Streak-Stil nicht mehr in seiner Kachel, sondern strahlt in
 * das Feld aus, in dem er sitzt – je nach Stil anders: Glutfunken, Herzen, Hochspannung auf der
 * Schrift, Sternenstaub, Dampf, wachsende Blümchen, vorbeifliegende Sterne, Prismen-Funkeln.
 *
 * Zwei Teile, die zusammengehören:
 *   streak_aura_class() an das FELD (Hero, Streak-Karte, Overlay) – daran hängen die Effekte,
 *   die etwas Vorhandenes verändern (die elektrisierte Schrift beim Blitz),
 *   streak_aura_html() als LAYER hinein – die frei schwebenden Teilchen.
 * Das Feld braucht dafür nichts weiter: position/overflow/isolation setzt .aura-feld selbst.
 *
 * Die Teilchen sind bewusst NICHT zufällig gestreut, sondern deterministisch (teilerfremde
 * Schrittweiten): So sieht die Aura auf jedem Gerät und nach jedem Seitenaufruf gleich aus,
 * statt bei jedem Klick neu zu würfeln. Die negativen Verzögerungen starten die Animationen
 * mitten im Durchlauf – sonst zündet beim Laden alles gleichzeitig und die Fläche ist erst
 * einmal leer.
 */
function streak_aura_html(string $style, int $tier): string
{
    if ($tier < 4) return '';
    $n = ['' => 11, 'heart' => 9, 'bolt' => 7, 'star' => 13, 'coffee' => 6,
          'flower' => 9, 'rakete' => 9, 'diamant' => 11, 'regenbogen' => 10, 'mond' => 12][$style] ?? 10;
    if ($tier < 5) $n = max(3, (int)round($n * 0.4)); // Stufe 4: nur ein paar Teilchen als Vorgeschmack
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $x = 3 + ($i * 37 + 11) % 94;                       // Position in Prozent des Feldes
        $y = 4 + ($i * 61 + 23) % 90;
        $d = round((($i * 53) % 100) / 100 * 7, 2);          // Versatz im Durchlauf
        $s = round(0.68 + (($i * 29) % 70) / 100, 2);        // Größe/Tempo-Faktor
        $out .= '<span style="--x:' . $x . '%;--y:' . $y . '%;--d:' . ($d > 0 ? '-' . $d : '0') . 's;--s:' . $s . '"></span>';
    }
    return '<span class="staura" aria-hidden="true">' . $out . '</span>';
}

/**
 * Klassen fürs Feld, in dem die Aura sitzt (leer unterhalb von Stufe 4). Siehe streak_aura_html().
 * Ab Stufe 4 (50 Tage) läuft dieselbe Mechanik schon einmal leise an – `aura-lite` blasst sie ab,
 * nimmt den Schleier über der Fläche weg und lässt die Kacheln in Ruhe. Die eigene Bewegung des
 * Zeichens bleibt der 100 vorbehalten: Die hängt an der Stufen-Klasse der Kachel, nicht hier.
 */
function streak_aura_class(string $style, int $tier): string
{
    if ($tier < 4) return '';
    return ' aura-feld aura-' . ($style !== '' ? $style : 'flame') . ($tier < 5 ? ' aura-lite' : '');
}

/**
 * Zeichen für die kleinen Streak-Badges im Fließtext (Hall of Fame, Aktivitäts-Liste).
 *
 * Dort gibt es KEINE Stufen-Klasse (.flower.st3 & Co.), also auch keine --bl-*-Variablen – die
 * gewachsene Pflanze käme als leerer Kasten heraus. Deshalb hier eine feste, immer offene Blüte
 * in currentColor, damit sie sich wie die Font-Ikonen der anderen Stile einfärben lässt.
 * Für den Blumen-Stil eine eigene Zeichnung: Tablers „ti-flower" ist eine dünne Strichgrafik und
 * verkommt bei 17 px zum Fleck.
 */
function flame_style_mini(string $style): string
{
    if ($style !== 'flower') return '<i class="ti ' . flame_style_icon($style) . '"></i>';
    // Fünf Blütenblätter als EIN Pfad, der Kern per fill-rule="evenodd" ausgestanzt: So scheint
    // der Seitenhintergrund durch und die Mitte bleibt auch bei einer einzigen Farbe erkennbar.
    //
    // BEWUSST NICHT die volle Rosette der großen Blüte: Bei 17 px werden aus sechzehn Blättern
    // ein Fleck. Hier zählt Silhouette, nicht Fülle – das Zeichen steht mitten im Fließtext
    // neben einer Zahl und muss auf einen Blick als Blume lesbar sein.
    $blatt = 'M12 9.5 C 9.2 8.3, 8.4 5, 12 1.4 C 15.6 5, 14.8 8.3, 12 9.5 Z';
    $d = $blatt;
    for ($i = 1; $i < 5; $i++) {
        $w = deg2rad($i * 72);
        // Von Hand rotieren statt per transform: ein einziger Pfad kann nur eine Füllregel haben,
        // und die Aussparung funktioniert nur, wenn Blätter und Kern in DEMSELBEN Pfad stecken.
        $d .= ' ' . preg_replace_callback('~(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)~', function ($m) use ($w) {
            $x = (float)$m[1] - 12; $y = (float)$m[2] - 9.5;
            return round(12 + $x * cos($w) - $y * sin($w), 2) . ' ' . round(9.5 + $x * sin($w) + $y * cos($w), 2);
        }, $blatt);
    }
    $d .= ' M14.4 9.5 A 2.4 2.4 0 1 1 9.6 9.5 A 2.4 2.4 0 1 1 14.4 9.5 Z';   // ausgestanzte Mitte
    return '<svg class="fs-mini" width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
         . '<path d="' . $d . '" fill="currentColor" fill-rule="evenodd"/>'
         . '<path d="M12 12 C 12.4 15.5, 11.6 18, 12 21.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
         . '<path d="M11.5 18.6 C 8.5 18, 7.3 16.1, 7.6 14.6 C 9.7 14.8, 11.2 16.3, 11.5 18.6 Z" fill="currentColor"/>'
         . '</svg>';
}

/** Tabler-Icon-Klasse für die kleinen Streak-Badges (Fließtext/Tabellen: Hall of Fame, Aktivitäts-Liste –
 *  dort steht die Font-Ikone, nicht die animierte flame_svg). Passend zur .<stil>line-Färbung in style.css. */
function flame_style_icon(string $style): string
{
    switch ($style) {
        case 'heart':  return 'ti-heart-filled';
        case 'bolt':   return 'ti-bolt';
        case 'star':   return 'ti-star-filled';
        case 'coffee': return 'ti-coffee';
        case 'flower': return 'ti-flower';
        case 'rakete': return 'ti-rocket';
        case 'diamant': return 'ti-diamond';
        case 'regenbogen': return 'ti-rainbow';
        case 'mond': return 'ti-moon';
        case 'schein': return 'ti-circle';
        default:       return 'ti-flame';
    }
}

/** Hat dieses Mitglied den Onboarding-Rundgang schon durchlaufen (oder übersprungen)?
 *  Technik-Login/kein Mitglied → true (kein Tutorial). */
function member_onboarded(int $memberId): bool
{
    if ($memberId <= 0) return true;
    try { $s = db()->prepare('SELECT onboarded FROM members WHERE id = ?'); $s->execute([$memberId]); return (int)$s->fetchColumn() === 1; }
    catch (\Throwable $e) { return true; }
}

/** Merkt den Onboarding-Rundgang als (einmal) gelaufen → kein Auto-Start mehr. Gilt für Abschluss UND Abbruch. */
function member_mark_onboarded(int $memberId): void
{
    if ($memberId <= 0) return;
    try { db()->prepare('UPDATE members SET onboarded = 1 WHERE id = ?')->execute([$memberId]); } catch (\Throwable $e) {}
}

/** Soll dem Mitglied das freiwillige Dashboard-Angebot „geh den Rundgang mal durch" gezeigt werden? */
function member_show_tour_task(int $memberId): bool
{
    if ($memberId <= 0) return false;
    try { $s = db()->prepare('SELECT tour_task FROM members WHERE id = ?'); $s->execute([$memberId]); return (int)$s->fetchColumn() === 1; }
    catch (\Throwable $e) { return false; }
}

/** Räumt das freiwillige Dashboard-Angebot weg (voll abgeschlossener Rundgang oder „Ich kenne mich aus"). */
/** Der Rundgang wurde bis zum letzten Schritt geklickt. Setzt nur push.php. */
function member_mark_tour_done(int $memberId): void
{
    if ($memberId <= 0) return;
    try { db()->prepare('UPDATE members SET tour_done = 1 WHERE id = ?')->execute([$memberId]); } catch (\Throwable $e) {}
}

function member_clear_tour_task(int $memberId): void
{
    if ($memberId <= 0) return;
    try { db()->prepare('UPDATE members SET tour_task = 0 WHERE id = ?')->execute([$memberId]); } catch (\Throwable $e) {}
}

/** Seiten-Karte für den geführten Rundgang: Schlüssel => Datei (ohne Basis-Pfad), rollen-korrekt.
 *  app.js navigiert damit von Seite zu Seite; unbekannte Schlüssel überspringt die Tour. */
function tour_pages(): array
{
    $p = [
        'dashboard' => 'dashboard.php', 'kalender' => 'index.php', 'events' => 'events.php',
        'umlauf' => 'umlauf.php', 'mitglieder' => 'mitglieder.php', 'profil' => 'profil.php',
        'achievements' => 'achievements.php', 'basisscore' => 'basisscore.php', 'score' => 'score.php',
        'erinnerungen' => 'erinnerungen.php', 'info' => 'info.php', 'wegweiser' => 'wegweiser.php',
        'finanzen' => 'finanzen.php', // Auslagen/Belegblatt: für ALLE Mitglieder
    ];
    if (can_manage_meetings()) $p['sitzungen'] = 'admin/meetings.php';
    else { $m = current_member(); if ($m && trim((string)($m['referat'] ?? '')) !== '') $p['sitzungen'] = 'report.php'; }
    if (can_admin()) { $p['verwaltung'] = 'admin/index.php'; $p['adminmembers'] = 'admin/members.php'; } // Verwaltung + Deep-Dive Mitgliederverwaltung
    return $p;
}

/** Rollen-Flags für den Rundgang: schaltet rollenspezifische Erklär-Schritte frei (referat/admin/…). */
function tour_flags(): array
{
    $m = current_member();
    return [
        'referat'     => (bool)($m && trim((string)($m['referat'] ?? '')) !== ''),
        'admin'       => can_admin(),
        'sekretariat' => can_manage_meetings(),
        'finanzen'    => current_role() === 'finanzen',
        // Externe Events erklärt der Rundgang nur denen, die sie auch betreuen dürfen.
        'extern'      => function_exists('extern_can_manage') && extern_can_manage(null),
    ];
}

/**
 * Heißt dieses Mitglied mit VORNAMEN so? Vorname = das erste Wort des Namens, Groß- und
 * Kleinschreibung egal. Basis der geheimen Namens-Freischaltungen (Streak-Stil UND App-Design),
 * deshalb an einer Stelle – nicht zweimal fast gleich.
 */
function member_first_name_is(int $memberId, string $vorname): bool
{
    $vorname = mb_strtolower(trim($vorname));
    if ($vorname === '' || $memberId <= 0) return false;
    $m = member_get($memberId);
    if (!$m) return false;
    $erstes = preg_split('/\s+/u', trim((string)($m['name'] ?? '')))[0] ?? '';
    return mb_strtolower($erstes) === $vorname;
}

/**
 * Beschriftung des Namens-Schmucks „Tasse" aus dem hinterlegten Namen: aus „Lotta Schwarz"
 * wird „Lottas Tasse ☕". Nur der Vorname zählt, und ein Name auf s/ß/x/z bekommt statt des
 * Genitiv-s nur den Apostroph („Lars' Tasse"), sonst stolpert man beim Lesen.
 */
function deco_tasse_label(string $name): string
{
    $vor = preg_split('/\s+/u', trim($name))[0] ?? '';
    if ($vor === '') return 'Tasse ☕';
    $endet = mb_strtolower(mb_substr($vor, -1));
    return $vor . (in_array($endet, ['s', 'ß', 'x', 'z'], true) ? '\'' : 's') . ' Tasse ☕';
}

/**
 * Geheime Zusatz-Freischaltung für Streak-Stile: Stil-Key => Vorname (klein geschrieben).
 * Wer so heißt, darf den Stil tragen, ohne das Achievement dafür geholt zu haben.
 *
 * Bewusst NUR der Stil und NICHT das Achievement selbst: „Durchhalter" behauptet eine Streak von
 * 30 Tagen: Hätte es jemand ohne diese Tage, stünde in der Hall of Fame und in der Verwaltungs-
 * Übersicht eine Unwahrheit – und der Zaubererhut käme obendrauf. So bleibt die Statistik ehrlich,
 * und der Stil steht trotzdem bereit.
 *
 * Es sagt niemandem etwas an: keine Meldung, kein Eintrag im Erfolge-Gitter. Der Stil taucht
 * einfach in der Auswahl auf.
 */
function flame_style_secret(): array
{
    // „Sempervivium" ist auf Viviens Wunsch entstanden. Der Vorname steht in den
    // Einstellungen, nicht im Programm: Wer die App übernimmt, hat andere Leute – und
    // ein leerer Eintrag schaltet die Vergabe schlicht ab.
    $wer = mb_strtolower(trim((string)setting_get('insider_blume_vorname', 'vivien')));
    return $wer === '' ? [] : ['flower' => $wer];
}

/**
 * Darf dieses Mitglied diesen Streak-Stil tragen? EINE Quelle für Auswahl, Anzeige und
 * Speichern – die Prüfung darf nirgends ein zweites Mal stehen.
 */
function flame_style_allowed(int $memberId, string $style): bool
{
    if ($style === '') return true;                       // die Flamme kann immer
    if ($memberId <= 0) return false;
    $need = flame_styles_unlock()[$style] ?? null;
    if ($need === null) {
        // Kauf-Stile (AsT-Sortiment): gehören dazu, sobald gekauft oder beschlagnahmt –
        // im Diagnose-Testmodus wie alles andere aus dem Sortiment (shop_available).
        if (in_array($style, flame_styles_shop(), true)) return isset(shop_available($memberId)[$style]);
        return false;                                     // unbekannter Stil
    }
    // Testmodus gilt nur für die Person, die ihn eingeschaltet hat – nicht für alle anderen.
    if (test_unlock_all() && $memberId === (int)($_SESSION['member_id'] ?? 0)) return true;
    if (in_array($need, member_achievement_codes($memberId), true)) return true;
    return member_first_name_is($memberId, (string)(flame_style_secret()[$style] ?? ''));
}

/** Effektiver Streak-Stil dieses Mitglieds: '' = Flamme, sonst 'heart'/'bolt'/'star'/'coffee'/'flower'.
 *  Ein gewählter Sonder-Stil zählt nur, solange er freigeschaltet ist (Achievement, Testmodus oder
 *  die geheime Namensbedingung) – fällt der Status weg (z. B. „spitze" beim Absinken), rutscht die
 *  Anzeige sauber auf die Flamme zurück. */
function member_flame_style(int $memberId): string
{
    if ($memberId <= 0) return '';
    try {
        $s = db()->prepare('SELECT flame_style FROM members WHERE id = ?');
        $s->execute([$memberId]);
        $style = trim((string)$s->fetchColumn());
    } catch (\Throwable $e) { return ''; }
    return flame_style_allowed($memberId, $style) ? $style : '';
}

/** Zeigt dieses Mitglied die Streak als Herz? (Wrapper für die Wording-Stellen.) */
function member_flame_heart(int $memberId): bool { return member_flame_style($memberId) === 'heart'; }

/** Setzt den Streak-Stil ('' = Flamme immer erlaubt, sonst nur wenn freigeschaltet – siehe flame_style_allowed). */
function member_set_flame_style(int $memberId, string $style): bool
{
    if ($memberId <= 0) return false;
    if (!flame_style_allowed($memberId, $style)) return false;
    try { db()->prepare('UPDATE members SET flame_style = ? WHERE id = ?')->execute([$style, $memberId]); return true; }
    catch (\Throwable $e) { return false; }
}

/** Bereits freigeschaltete Achievement-Codes eines Mitglieds. */
function &_ach_codes_store(): array { static $c = []; return $c; }

/** Freigeschaltete Achievement-Codes eines Mitglieds. Pro Request gecacht – die Funktion wird sehr oft
 *  aufgerufen (Glühbirne/Skins, Paletten, Royal-Check …); jede Vergabe/Entziehung bust den Cache selbst. */
function member_achievement_codes(int $memberId): array
{
    if ($memberId <= 0) return [];
    $c = &_ach_codes_store();
    if (array_key_exists($memberId, $c)) return $c[$memberId];
    try {
        $s = db()->prepare('SELECT code FROM member_achievements WHERE member_id = ?');
        $s->execute([$memberId]);
        return $c[$memberId] = array_map('strval', array_column($s->fetchAll(), 'code'));
    } catch (\Throwable $e) { return []; }
}

/** Cache invalidieren, wenn sich die Achievements eines Mitglieds ändern (Vergabe/Entziehung). */
function member_achievement_codes_bust(int $memberId): void
{
    $c = &_ach_codes_store();
    unset($c[$memberId]);
}

/** Schaltet ein Achievement frei (idempotent). Gibt true zurück, wenn es NEU war (dann Toast). */
function achievement_unlock(int $memberId, string $code): bool
{
    if ($memberId <= 0) return false;
    $cat = achievements_catalog();
    if (!isset($cat[$code])) return false;
    try {
        $s = db()->prepare('INSERT OR IGNORE INTO member_achievements(member_id, code, earned_at) VALUES(?, ?, ?)');
        $s->execute([$memberId, $code, date('Y-m-d H:i:s')]);
        if ($s->rowCount() < 1) return false; // war schon vorhanden
    } catch (\Throwable $e) { return false; }
    member_achievement_codes_bust($memberId); // Cache auffrischen: die/der Nutzer:in hat jetzt einen Code mehr
    $a = $cat[$code];
    $reward = $a['reward'] ?? null;
    $auto   = !empty($a['auto_apply']);
    // Auto-Belohnungen (Royal/Spitzenklasse) direkt ausrüsten – z. B. Kronen-Schmuck (in seinen Slot).
    if ($auto && $reward && ($reward['type'] ?? '') === 'deco') {
        member_equip_deco($memberId, (string)$reward['key']);
    }
    // Auto-Skin (z. B. „Hochsommer" beim Sommerkönig): einmalig automatisch anwenden – als Pending merken,
    // page_header/app.js aktivieren ihn genau einmal und leeren das Feld danach wieder.
    if ($auto && $reward && ($reward['type'] ?? '') === 'skin') {
        member_queue_skin($memberId, (string)$reward['key']);
    }
    // Eigenen Achievement-Toast nur für die/den aktuelle:n Nutzer:in einreihen (nicht bei Fremd-/Batch-Vergabe).
    if ($memberId === (int)($_SESSION['member_id'] ?? 0)) {
        $tier = achievement_tier_meta()[$a['tier']] ?? [];
        ach_queue_unlock([
            'code'         => $code,
            'title'        => $a['title'],
            'icon'         => $a['icon'],
            'color'        => $tier['color'] ?? '',
            'auto'         => $auto,
            'reward_type'  => $reward['type'] ?? '',
            'reward_key'   => $reward['key'] ?? '',
            'reward_label' => $reward['label'] ?? '',
            'svg'          => ($reward && ($reward['type'] ?? '') === 'deco') ? avatar_deco_svg((string)$reward['key']) : '',
            'slot'         => ($reward && ($reward['type'] ?? '') === 'deco') ? avatar_deco_slot((string)$reward['key']) : '',
        ]);
    }
    return true;
}

/**
 * Eine App-Öffnung zählen (Serie „Stammgast"/„Praktisch eingezogen"/„Touch Grass").
 * App-Öffnungen zählen (auch mehrere auf einmal): app.js sammelt Öffnungen in einer lokalen
 * Warteschlange und meldet sie gebündelt (push.php action=app_open, Feld n) – so gehen auch
 * Öffnungen nicht verloren, deren Request iOS beim schnellen Wieder-Schließen eingefroren hat
 * (Nachmeldung beim nächsten Öffnen). Rückkehr aus dem Hintergrund zählt IMMER (Sekundentakt
 * erlaubt – es geht nur um Spaß), Seitenwechsel innerhalb der App nie; Doppel-Events derselben
 * Öffnung fängt der Client (0,7-s-Puffer). Hier wird bewusst nicht dedupliziert.
 */
function member_count_app_open(int $memberId, int $n = 1): void
{
    $n = max(1, min(50, $n)); // Schutz vor kaputten/organisierten Riesen-Meldungen
    if ($memberId <= 0) return;
    try {
        $st = db()->prepare('SELECT opens_on, opens_count FROM members WHERE id = ?');
        $st->execute([$memberId]);
        $o = $st->fetch();
        if (!$o) return;
        $cnt = ((string)($o['opens_on'] ?? '') === date('Y-m-d') ? (int)($o['opens_count'] ?? 0) : 0) + $n;
        db()->prepare('UPDATE members SET opens_on = ?, opens_count = ?, last_seen_at = ? WHERE id = ?')
            ->execute([date('Y-m-d'), $cnt, date('Y-m-d H:i:s'), $memberId]);
        if ($cnt >= 3)  achievement_unlock($memberId, 'opens_3');
        if ($cnt >= 10) achievement_unlock($memberId, 'opens_10');
        if ($cnt >= 20) achievement_unlock($memberId, 'opens_20');
    } catch (\Throwable $e) { /* Zählen darf nie etwas kaputt machen */ }
}

/** Zähler-Stände aus dem letzten achievements_evaluate()-Lauf (pro Request, member_id => stats). */
function &_ach_progress_store(): array { static $p = []; return $p; }

/**
 * „Dein nächstes Achievement": das SICHTBARE, noch nicht freigeschaltete Achievement mit dem meisten
 * Fortschritt (Gleichstand: das mit den wenigsten fehlenden Schritten). Nur fürs eigene Profil gedacht;
 * Geheime und dynamische (spitze/top3) bleiben außen vor. null, wenn nichts (mehr) offen ist.
 * @return array{code:string,title:string,icon:string,tier:string,current:int,target:int}|null
 */
function member_next_achievement(int $memberId): ?array
{
    if ($memberId <= 0) return null;
    $store = _ach_progress_store();
    if (!isset($store[$memberId])) {
        try { achievements_evaluate($memberId); } catch (\Throwable $e) { return null; }
        $store = _ach_progress_store();
    }
    $stats = $store[$memberId] ?? null;
    if (!$stats) return null;
    // code => [Zähler-Schlüssel, Ziel] – nur zählbare, sichtbare Meilensteine
    $targets = ['first_shift' => ['shifts', 1], 'shifts_10' => ['shifts', 10], 'shifts_25' => ['shifts', 25],
        'shifts_35' => ['shifts', 35], 'shifts_60' => ['shifts', 60], 'self_5' => ['self', 5],
        'rescue_3' => ['rescues', 3], 'weekend_5' => ['weekend', 5], 'gt_5' => ['gts', 5],
        'infos_20' => ['infos', 20], 'responses_25' => ['answers', 25], 'responses_50' => ['answers', 50],
        'first_report' => ['reports', 1], 'reports_10' => ['reports', 10], 'reports_20' => ['reports', 20],
        'reports_30' => ['reports', 30], 'streak_7' => ['streak_best', 7], 'streak_30' => ['streak_best', 30],
        'streak_50' => ['streak_best', 50], 'hundert' => ['streak_best', 100], 'anstoss' => ['starts', 3],
        'active_26w' => ['weeks', 26], 'active_52w' => ['weeks', 52],
        'meetings_10' => ['meetings', 10], 'meetings_20' => ['meetings', 20], 'top_1' => ['tops', 1],
        'top_10' => ['tops', 10], 'votes_20' => ['votes_read', 20], 'expense_1' => ['claims', 1],
        'expense_5' => ['claims', 5], 'pin_1' => ['pins', 1], 'pin_10' => ['pins', 10],
        'pin_spread' => ['pin_people', 5], 'umlauf_2' => ['umlauf', 2], 'polls_4' => ['polls', 4],
        'protocols_5' => ['protocols', 5], 'protocols_10' => ['protocols', 10],
        'kudos_10' => ['props_got', 10], 'early_report_5' => ['early_reports', 5]];
    $cat = achievements_catalog();
    $have = array_flip(member_achievement_codes($memberId));
    $best = null; $bestRatio = -1.0;
    foreach ($targets as $code => [$key, $target]) {
        if (!isset($cat[$code]) || !empty($cat[$code]['hidden']) || !empty($cat[$code]['dynamic']) || isset($have[$code])) continue;
        $cur = min((int)($stats[$key] ?? 0), $target);
        $ratio = $cur / $target;
        if ($ratio > $bestRatio || ($ratio === $bestRatio && $best && ($target - $cur) < ($best['target'] - $best['current']))) {
            $best = ['code' => $code, 'title' => (string)$cat[$code]['title'], 'icon' => (string)$cat[$code]['icon'],
                     'tier' => (string)$cat[$code]['tier'], 'current' => $cur, 'target' => $target];
            $bestRatio = $ratio;
        }
    }
    return $best;
}

/** Prinzessin/Prinz-Zähler: merkt sich Schmuck-/Farbwechsel der letzten Minute in der Session –
 *  mehr als 4 Wechsel innerhalb von 60 Sekunden schalten das Geheim-Achievement „prinz" frei. */
function prinz_swap_tick(int $memberId): void
{
    if ($memberId <= 0) return;
    $now = time();
    $t = array_values(array_filter((array)($_SESSION['prinz_swaps'] ?? []), fn($x) => (int)$x > $now - 60));
    $t[] = $now;
    $_SESSION['prinz_swaps'] = $t;
    if (count($t) > 4) achievement_unlock($memberId, 'prinz');
}

/** Entzieht ein Achievement wieder (nur für dynamische/statusgebundene wie „spitze" gedacht). Still, ohne Toast. */
function achievement_revoke(int $memberId, string $code): bool
{
    if ($memberId <= 0) return false;
    try {
        $s = db()->prepare('DELETE FROM member_achievements WHERE member_id = ? AND code = ?');
        $s->execute([$memberId, $code]);
        if ($s->rowCount() > 0) { member_achievement_codes_bust($memberId); return true; }
        return false;
    } catch (\Throwable $e) { return false; }
}

/** Royal-Design freigeschaltet? Über das „spitze"-Achievement (Spitzenklasse) oder den Test-Modus der Gefahrenzone. */
function royal_unlocked(): bool
{
    if (!is_logged_in()) return false;
    if (royal_test_active()) return true;
    $id = (int)($_SESSION['member_id'] ?? 0);
    return $id > 0 && in_array('spitze', member_achievement_codes($id), true);
}

/** Zentrale Skin-Registry: alle freischaltbaren App-Designs jenseits von System/Hell/Dunkel.
 *  Ein neuer Skin braucht nur noch: einen Eintrag hier, den CSS-Block (html[data-skin="…"])
 *  und eine Skin-Belohnung an einem Achievement (bzw. bei Royal den Spitzenklasse-Status).
 *  'base'  = Basis-Theme, auf dem der Skin aufsetzt ('dark' oder 'light'),
 *  'tc'    = theme-color für Browser-/PWA-Statusleiste, 'emoji' für Tooltips/Labels. */
function app_skins(): array
{
    return [
        'royal'     => ['label' => 'Royal',           'icon' => 'ti-crown',       'emoji' => '👑', 'base' => 'dark',  'tc' => '#191130'],
        'trueblack' => ['label' => 'TrueBlack',       'icon' => 'ti-moon-filled', 'emoji' => '🖤', 'base' => 'dark',  'tc' => '#000000'],
        'sunset'    => ['label' => 'Sonnenuntergang', 'icon' => 'ti-sunset-2',    'emoji' => '🌇', 'base' => 'dark',  'tc' => '#241031'],
        'sommer'    => ['label' => 'Hochsommer',      'icon' => 'ti-sun',         'emoji' => '☀️', 'base' => 'light', 'tc' => '#cfe9fa'],
        'strand'    => ['label' => 'Abend am Strand', 'icon' => 'ti-beach',       'emoji' => '🏝️', 'base' => 'dark',  'tc' => '#0b222e'],
        'pink'      => ['label' => 'Ein Traum in Pink', 'icon' => 'ti-heart',     'emoji' => '💗', 'base' => 'light', 'tc' => '#ffd9ec'],
        'gothic'    => ['label' => 'Gothik',          'icon' => 'ti-bat',         'emoji' => '🦇', 'base' => 'dark',  'tc' => '#0d0a12'],
        'blumen'    => ['label' => 'Blumenwiese',     'icon' => 'ti-flower',      'emoji' => '🌸', 'base' => 'light', 'tc' => '#fdf7ec'],
        // Der Pride-SKIN hängt am Achievement „Gute Ansprache" (Pronomen im Profil); Mitglieder aus
    // der Zeit, als er Grundausstattung war, behalten ihn per Besitzstand (member_reward_skins()).
    // Avatar-Farbe und Initialen-Farbe „Pride" sind davon unabhängig und FREI.
        'pride'     => ['label' => 'Pride',           'icon' => 'ti-rainbow',     'emoji' => '🏳️‍🌈', 'base' => 'dark', 'tc' => '#131019'],
        // Feiertagsskins (freigeschaltet im ±3-Tage-Fenster um den jeweiligen Feiertag)
        'weihnacht' => ['label' => 'Weihnachten',     'icon' => 'ti-christmas-tree', 'emoji' => '🎄', 'base' => 'dark',  'tc' => '#0b1d15'],
        'halloween' => ['label' => 'Halloween',       'icon' => 'ti-pumpkin-scary',  'emoji' => '🎃', 'base' => 'dark',  'tc' => '#140c06'],
        'ostern'    => ['label' => 'Ostern',          'icon' => 'ti-egg',            'emoji' => '🐣', 'base' => 'light', 'tc' => '#f6f2fc'],
        'valentin'  => ['label' => 'Valentinstag',    'icon' => 'ti-flower-filled',  'emoji' => '💐', 'base' => 'light', 'tc' => '#fdeff2'],
    ];
}

/**
 * Geheime Zusatz-Freischaltung für App-Designs: Skin-Key => Vorname (klein geschrieben).
 * Gegenstück zu flame_style_secret() – die „Blumenwiese" und die Blume gehören zusammen, also
 * bekommt Vivien beides. Angekündigt wird nichts: der Skin steht einfach in der Glühbirne.
 */
function skin_secret(): array
{
    $wer = mb_strtolower(trim((string)setting_get('insider_blume_vorname', 'vivien')));
    return $wer === '' ? [] : ['blumen' => $wer];
}

/** Ist ein Skin für die/den aktuelle:n Nutzer:in freigeschaltet? Royal hängt am Spitzenklasse-Status,
 *  mit 'frei' markierte gehören jeder angemeldeten Person, alle anderen hängen an Skin-Belohnungen
 *  von Achievements (member_reward_skins; Diagnose-Testmodus schaltet alle frei) – dazu die geheime
 *  Namens-Freischaltung aus skin_secret(). */
function skin_unlocked(string $key): bool
{
    if (!isset(app_skins()[$key])) return false;
    if (!empty(app_skins()[$key]['frei'])) return is_logged_in();
    if ($key === 'royal') return royal_unlocked();
    if (!is_logged_in()) return false;
    $id = (int)($_SESSION['member_id'] ?? 0);
    if ($id <= 0) return false;
    if (isset(member_reward_skins($id)[$key])) return true;
    return member_first_name_is($id, (string)(skin_secret()[$key] ?? ''));
}

/** TrueBlack-Design freigeschaltet? (Skin-Belohnung des „night_owl"-Achievements; im Test-Modus für alle.) */
function trueblack_unlocked(): bool { return skin_unlocked('trueblack'); }

/** Sonnenuntergang-Design freigeschaltet? (Skin-Belohnung der 100er-Streak; im Test-Modus für alle.) */
function sunset_unlocked(): bool { return skin_unlocked('sunset'); }

/** Prüft alle datengetriebenen Kriterien und schaltet Erfülltes frei. Gibt neu vergebene Codes zurück. */
function achievements_evaluate(int $memberId, ?array $scores = null): array
{
    if ($memberId <= 0) return [];
    $cat  = achievements_catalog();
    $have = array_flip(member_achievement_codes($memberId));
    $cnt = function (string $sql) use ($memberId): int {
        try { $s = db()->prepare($sql); $s->execute([$memberId]); return (int)$s->fetchColumn(); }
        catch (\Throwable $e) { return 0; }
    };
    $shifts  = $cnt('SELECT COUNT(*) FROM assignments WHERE member_id = ?');
    $reports = $cnt("SELECT COUNT(*) FROM reports WHERE updated_by = ? AND content <> ''");
    $weeks   = $cnt('SELECT COUNT(*) FROM activity_weeks WHERE member_id = ?');
    $selfGrab  = $cnt('SELECT COUNT(*) FROM assignments WHERE member_id = ? AND self_claimed = 1');
    $rescues   = $cnt("SELECT COUNT(*) FROM shift_swaps WHERE taken_by = ? AND status = 'done'");
    $gts       = $cnt("SELECT COUNT(*) FROM gettogether_rsvp WHERE member_id = ? AND status = 'yes'");
    $infosRead = $cnt('SELECT COUNT(*) FROM info_reads WHERE member_id = ?');
    $answers   = $cnt('SELECT COUNT(*) FROM responses WHERE member_id = ?');
    $weekend   = $cnt("SELECT COUNT(*) FROM assignments a JOIN event_slots s ON s.id = a.slot_id WHERE a.member_id = ? AND strftime('%w', s.starts_at) IN ('0','6')");
    $tops      = $cnt('SELECT COUNT(*) FROM top_submissions WHERE member_id = ?');
    $votesRead = $cnt('SELECT COUNT(*) FROM vote_item_reads WHERE member_id = ?');
    $claims    = $cnt('SELECT COUNT(*) FROM expense_claims WHERE member_id = ?');
    $umlaufVotes = $cnt('SELECT COUNT(*) FROM circular_ballots WHERE member_id = ?');
    // Terminfinder-Teilnahme: verschiedene date_polls, in denen man abgestimmt hat
    $pollVotes = $cnt('SELECT COUNT(DISTINCT o.poll_id) FROM date_poll_votes v JOIN date_poll_options o ON o.id = v.option_id WHERE v.member_id = ?');
    $propsGot  = $cnt('SELECT COUNT(*) FROM kudos WHERE to_id = ?'); // erhaltene Props („Famous", „Gute Seele")
    // Geführte Protokolle: nur solche, die auch WIRKLICH abgeliefert wurden. Als Protokollant:in
    // eingetragen zu sein ist eine Zusage, kein Verdienst – gezählt wird ab dem Hochladen.
    $protocols = $cnt("SELECT COUNT(*) FROM meetings WHERE protocol_taker_id = ? AND cancelled = 0 AND draft = 0
                       AND protocol_status IN ('uploaded','approved','published')");
    $propsGiven = $cnt('SELECT COUNT(*) FROM kudos WHERE from_id = ?'); // verschenkte Props („Großzügig")
    // „Anstoß": selbst aufgesetzte Umläufe UND Terminfinder zusammen (zwei Abfragen, weil $cnt
    // genau einen Parameter bindet – ein UNION mit zweimal ? ginge daran vorbei).
    $gestartet = $cnt('SELECT COUNT(*) FROM circular_votes WHERE created_by = ?')
               + $cnt('SELECT COUNT(*) FROM date_polls WHERE created_by = ?');
    // Nachtschicht: irgendein Protokoll dieser Person nach 23 Uhr hochgeladen (bis Mitternacht).
    $nightProto = $cnt("SELECT COUNT(*) FROM meetings WHERE protocol_taker_id = ? AND protocol_uploaded_at IS NOT NULL
                        AND CAST(strftime('%H', protocol_uploaded_at) AS INTEGER) = 23");
    // Ausgeschlafen: Bericht mindestens 3 volle Tage vor Sitzungsbeginn eingetragen. Gezählt wird der
    // Stand JETZT (reports hat nur updated_at) – wer später nachbessert, verliert den Vorsprung wieder.
    $earlyReports = $cnt("SELECT COUNT(*) FROM reports r JOIN meetings m ON m.id = r.meeting_id
                          WHERE r.updated_by = ? AND r.content <> '' AND m.cancelled = 0
                            AND julianday(m.starts_at) - julianday(r.updated_at) >= 3");
    $gaveAway  = $cnt("SELECT COUNT(*) FROM shift_swaps WHERE offered_by = ? AND status = 'done'");
    // Tage mit 2+ Schichten / Schichten ab 6 Std. / Börsen-Übernahmen < 24 Std. vor Schichtbeginn / Tage mit 5+ gelesenen Infos
    $doubleDays = $cnt('SELECT COUNT(*) FROM (SELECT date(s.starts_at) d FROM assignments a JOIN event_slots s ON s.id = a.slot_id WHERE a.member_id = ? GROUP BY d HAVING COUNT(*) >= 2)');
    $marathons  = $cnt("SELECT COUNT(*) FROM assignments a JOIN event_slots s ON s.id = a.slot_id WHERE a.member_id = ? AND s.ends_at IS NOT NULL AND s.ends_at > s.starts_at AND (julianday(s.ends_at) - julianday(s.starts_at)) * 24 >= 6");
    $lastMin    = $cnt("SELECT COUNT(*) FROM shift_swaps w JOIN event_slots s ON s.id = w.slot_id WHERE w.taken_by = ? AND w.status = 'done' AND w.resolved_at IS NOT NULL AND (julianday(s.starts_at) - julianday(w.resolved_at)) * 24 <= 24");
    $bingeDays  = $cnt('SELECT COUNT(*) FROM (SELECT date(read_at) d FROM info_reads WHERE member_id = ? GROUP BY d HAVING COUNT(*) >= 5)');
    $bugsFixed  = $cnt("SELECT COUNT(*) FROM bug_reports WHERE created_by = ? AND status = 'done'");
    // König:in des Sommers: als Orga ein bereits gestartetes Event mit > 400 eingeteilten Schichtstunden
    // ausgerichtet (Stunden = Summe der Slot-Dauern aller Einteilungen; ohne Endzeit zählt eine Schicht 2 Std.)
    $bigEvents  = $cnt("SELECT COUNT(*) FROM (
        SELECT s.event_id FROM assignments a JOIN event_slots s ON s.id = a.slot_id
        WHERE s.event_id IN (SELECT event_id FROM event_owners WHERE member_id = ?)
        GROUP BY s.event_id
        HAVING SUM(CASE WHEN s.ends_at IS NOT NULL AND s.ends_at > s.starts_at
                        THEN (julianday(s.ends_at) - julianday(s.starts_at)) * 24 ELSE 2 END) > 400
           AND MAX(s.starts_at) <= datetime('now','localtime'))");
    $longVacs   = $cnt('SELECT COUNT(*) FROM absences WHERE member_id = ? AND julianday(ends_at) - julianday(starts_at) >= 13');
    // Pinnwand-Kommentare auf FREMDEN Profilen (Einträge + Antworten; das eigene Profil zählt nicht) – Anzahl & verschiedene Personen
    $pinPosts = 0; $pinPeople = 0; $pinReplies = 0;
    try {
        $s = db()->prepare('SELECT COUNT(*) AS c, COUNT(DISTINCT profile_id) AS p,
            SUM(CASE WHEN parent_id > 0 THEN 1 ELSE 0 END) AS r
            FROM profile_posts WHERE author_id = ? AND profile_id <> ?');
        $s->execute([$memberId, $memberId]);
        $row = $s->fetch() ?: [];
        $pinPosts = (int)($row['c'] ?? 0); $pinPeople = (int)($row['p'] ?? 0); $pinReplies = (int)($row['r'] ?? 0);
    } catch (\Throwable $e) {}
    $streakBest = 0; $streakCur = 0; $opened = false; $joined = ''; $profileDone = false; $tourDone = false; $pronomenDa = false;
    try {
        $s = db()->prepare('SELECT streak_best, streak_current, streak_last_day, last_active_on, joined_at, about_me, referat_desc, tour_done, pronouns FROM members WHERE id = ?');
        $s->execute([$memberId]); $mrow = $s->fetch() ?: [];
        $streakBest = (int)($mrow['streak_best'] ?? 0);
        $streakCur  = streak_alive_for($memberId, (string)($mrow['streak_last_day'] ?? '')) ? (int)($mrow['streak_current'] ?? 0) : 0;
        $opened = trim((string)($mrow['last_active_on'] ?? '')) !== '';
        $joined = trim((string)($mrow['joined_at'] ?? ''));
        // Steckbrief komplett: beide Freitext-Felder gefüllt
        $profileDone = trim((string)($mrow['about_me'] ?? '')) !== '' && trim((string)($mrow['referat_desc'] ?? '')) !== '';
        $tourDone    = (int)($mrow['tour_done'] ?? 0) === 1;
        // Gute Ansprache: Pronomen im Profil hinterlegt (einmal verdient bleibt – wie jedes Achievement)
        $pronomenDa  = trim((string)($mrow['pronouns'] ?? '')) !== '';
    } catch (\Throwable $e) {}
    // Besuchte Sitzungen: vergangene, nicht abgesagte Sitzungen seit Eintritt, bei denen man weder
    // abgemeldet war noch unentschuldigt gefehlt hat (Anwesenheit ist der Normalfall; online zählt mit).
    $meetings = 0;
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM meetings mt
            WHERE mt.cancelled = 0 AND mt.kind != 'stupa' AND mt.starts_at <= datetime('now','localtime')
              AND (? = '' OR date(mt.starts_at) >= ?)
              AND NOT EXISTS (SELECT 1 FROM meeting_rsvp r WHERE r.meeting_id = mt.id AND r.member_id = ? AND r.status = 'abgemeldet')
              AND NOT EXISTS (SELECT 1 FROM meeting_noshows n WHERE n.meeting_id = mt.id AND n.member_id = ?)");
        $s->execute([$joined, $joined, $memberId, $memberId]);
        $meetings = (int)$s->fetchColumn();
    } catch (\Throwable $e) {}
    // Vollversammlung: ein Kalenderquartal, in dem ALLE Sitzungen besucht wurden. Ein Quartal mit
    // ein, zwei Sitzungen wäre keine Leistung – deshalb erst ab dreien. Gerechnet wird wie oben:
    // anwesend ist, wer weder abgemeldet war noch unentschuldigt gefehlt hat.
    $vollQuartale = 0;
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM (
            SELECT COUNT(*) AS gesamt,
                   SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM meeting_rsvp r
                                             WHERE r.meeting_id = mt.id AND r.member_id = :m AND r.status = 'abgemeldet')
                             AND NOT EXISTS (SELECT 1 FROM meeting_noshows n
                                             WHERE n.meeting_id = mt.id AND n.member_id = :m2)
                            THEN 1 ELSE 0 END) AS da
            FROM meetings mt
            WHERE mt.cancelled = 0 AND mt.draft = 0 AND mt.kind != 'stupa'
              AND mt.starts_at <= datetime('now','localtime')
              AND (:j = '' OR date(mt.starts_at) >= :j2)
            GROUP BY strftime('%Y', mt.starts_at) || '-' || ((CAST(strftime('%m', mt.starts_at) AS INTEGER) + 2) / 3)
            HAVING gesamt >= 3 AND da = gesamt)");
        $s->execute([':m' => $memberId, ':m2' => $memberId, ':j' => $joined, ':j2' => $joined]);
        $vollQuartale = (int)$s->fetchColumn();
    } catch (\Throwable $e) {}
    $band = '';
    try { $band = score_band_for($memberId, $scores ?? member_scores()); } catch (\Throwable $e) {}
    // Top 3 der übernommenen Schichten (Status, ab 5 Schichten; Gleichstand mit Platz 3 zählt mit)
    $top3 = false;
    if ($shifts >= 5) {
        try {
            $counts = array_map('intval', array_column(
                db()->query('SELECT COUNT(*) c FROM assignments GROUP BY member_id ORDER BY c DESC LIMIT 3')->fetchAll(), 'c'));
            $cutoff = $counts ? (int)end($counts) : PHP_INT_MAX;
            $top3 = $shifts >= max(5, $cutoff);
        } catch (\Throwable $e) {}
    }

    // Zähler-Stände fürs eigene Profil merken („Dein nächstes Achievement") – pro Request
    $achProg = &_ach_progress_store();
    $achProg[$memberId] = ['shifts' => $shifts, 'self' => $selfGrab, 'rescues' => $rescues,
        'weekend' => $weekend, 'gts' => $gts, 'infos' => $infosRead, 'answers' => $answers,
        'reports' => $reports, 'streak_best' => $streakBest, 'weeks' => $weeks, 'meetings' => $meetings,
        'tops' => $tops, 'votes_read' => $votesRead, 'claims' => $claims, 'pins' => $pinPosts, 'pin_people' => $pinPeople,
        'umlauf' => $umlaufVotes, 'polls' => $pollVotes, 'protocols' => $protocols, 'props_got' => $propsGot,
        'early_reports' => $earlyReports, 'starts' => $gestartet];

    $met = [
        'first_open'   => $opened,
        'first_shift'  => $shifts >= 1,
        'shifts_10'    => $shifts >= 10,
        'shifts_25'    => $shifts >= 25,
        'shifts_35'    => $shifts >= 35,
        'shifts_60'    => $shifts >= 60,
        'self_5'       => $selfGrab >= 5,
        'rescue_3'     => $rescues >= 3,
        'weekend_5'    => $weekend >= 5,
        'gt_1'         => $gts >= 1,
        'gt_5'         => $gts >= 5,
        'infos_20'     => $infosRead >= 20,
        'responses_25' => $answers >= 25,
        'first_report' => $reports >= 1,
        'reports_10'   => $reports >= 10,
        'reports_20'   => $reports >= 20,
        'streak_7'     => $streakBest >= 7,
        'streak_30'    => $streakBest >= 30,
        'streak_50'    => $streakBest >= 50,
        'hundert'      => $streakBest >= 100,
        'anstoss'      => $gestartet >= 3,
        'active_26w'   => $weeks >= 26,
        'active_52w'   => $weeks >= 52,
        'top3_shifts'  => $top3,
        'spitze'       => $band === 'spitze',
        'meetings_10'  => $meetings >= 10,
        'meetings_20'  => $meetings >= 20,
        'top_1'        => $tops >= 1,
        'top_10'       => $tops >= 10,
        'votes_20'     => $votesRead >= 20,
        'responses_50' => $answers >= 50,
        'self_15'      => $selfGrab >= 15,
        'weekend_15'   => $weekend >= 15,
        'rescue_10'    => $rescues >= 10,
        'reports_30'   => $reports >= 30,
        'expense_1'    => $claims >= 1,
        'expense_5'    => $claims >= 5,
        'pin_1'        => $pinPosts >= 1,
        'pin_10'       => $pinPosts >= 10,
        'pin_spread'   => $pinPeople >= 5,
        'pin_reply_5'  => $pinReplies >= 5,
        'umlauf_2'     => $umlaufVotes >= 2,
        'polls_4'      => $pollVotes >= 4,
        'profile_done' => $profileDone,
        'pronomen'     => $pronomenDa,
        'tour_done'    => $tourDone,
        'famous'       => $propsGot >= 1,
        'kudos_10'     => $propsGot >= 10,
        // Nur auswerten, wenn es überhaupt eine Serie geben kann – sonst eine Abfrage pro Seitenaufruf
        // für alle, die noch nie Props verschenkt haben.
        'kudos_giver_3' => $propsGiven >= KUDOS_PER_MONTH * 3 && kudos_full_month_streak($memberId) >= 3,
        'protocols_5'  => $protocols >= 5,
        'protocols_10' => $protocols >= 10,
        'night_protocol' => $nightProto >= 1,
        'meetings_full' => $vollQuartale >= 1,
        'early_report_5' => $earlyReports >= 5,
        'double_shift' => $doubleDays >= 1,
        'marathon'     => $marathons >= 1,
        'lastminute'   => $lastMin >= 1,
        'fair_trade'   => $gaveAway >= 1 && $rescues >= 1,
        'binge_reader' => $bingeDays >= 1,
        'bug_fixed'    => $bugsFixed >= 1,
        'sommerkoenig' => $bigEvents >= 1,
        'urlauber'     => $longVacs >= 1, // 14 Tage inklusiv = 13 Tage Datums-Differenz
        // Phönix: aktuelle Streak (>= 7) liegt UNTER der Bestmarke (>= 30) – das geht nur, wenn die Kette
        // nach der Bestmarke gerissen ist und man sich gerade wieder hochkämpft.
        'phoenix'      => $streakCur >= 7 && $streakBest >= 30 && $streakCur < $streakBest,
        // Rund um die Uhr: beide Tageszeit-Geheimnisse bereits freigeschaltet (werden in activity_track vergeben)
        'allround'     => isset($have['early_bird']) && isset($have['night_owl']),
    ];
    // Hutsammlung: alle vier Sammel-Hüte verdient (Meilensteine – einmal verdient, bleibt sie)
    $met['hat_trick'] = $met['streak_7'] && $met['active_26w'] && $met['streak_30'] && $met['shifts_35'];
    $new = [];
    foreach ($met as $code => $ok) {
        if ($ok) {
            if (!isset($have[$code]) && achievement_unlock($memberId, $code)) $new[] = $code;
        } elseif (!empty($cat[$code]['dynamic']) && isset($have[$code])) {
            achievement_revoke($memberId, $code); // Status verloren (z. B. Spitzenklasse) → wieder entziehen
        }
    }
    // Selbstheilung: ausgerüstete Schmuckstücke ablegen, wenn sie nicht mehr freigeschaltet sind –
    // z. B. die Krone bei verlorener Spitzenklasse (dynamisches Achievement) oder der Geldsack nach
    // einem Rollenwechsel weg von Finanzen. Normal verdiente Schmuckstücke bleiben immer erhalten;
    // geprüft wird jetzt SLOT-weise, weil mehrere Stücke gleichzeitig getragen werden können.
    $eq = member_equipped_decos($memberId);
    if ($eq) {
        // Im Diagnose-Testmodus sind alle Belohnungen legitim ausgerüstet – dynamisch verlorene nicht abräumen
        $testAll = test_unlock_all() && $memberId === (int)($_SESSION['member_id'] ?? 0);
        if (!$testAll) {
            $unlocked = member_reward_decos($memberId);
            $changed = false;
            foreach ($eq as $slot => $k) {
                if (!isset($unlocked[$k])) { unset($eq[$slot]); $changed = true; }
            }
            if ($changed) member_save_decos($memberId, $eq);
        }
    }
    // Lottas Tasse (Namens-Schmuck): beim ersten Freischalten EINMALIG automatisch in die Hand –
    // danach frei ablegbar (Merker in den Settings, Komma-Liste der schon versorgten IDs).
    // Testmodus-Freischaltungen lösen das bewusst nicht aus.
    try {
        $isTest = test_unlock_all() && $memberId === (int)($_SESSION['member_id'] ?? 0);
        if (!$isTest && isset(member_reward_decos($memberId)['tasse'])) {
            $done = array_values(array_filter(array_map('intval', explode(',', (string)setting_get('tasse_auto_equipped', '')))));
            if (!in_array($memberId, $done, true)) {
                member_equip_deco($memberId, 'tasse');
                $done[] = $memberId;
                setting_set('tasse_auto_equipped', implode(',', $done));
            }
        }
    } catch (\Throwable $e) {}
    return $new;
}

/** Enddatum der vorlesungsfreien Zeit ('' = keine festgelegt). Pro Request gecached; $set aktualisiert den Cache. */
function lecture_break_until(?string $set = null): string
{
    static $v = null;
    if ($set !== null) { $v = $set; return $v; }
    if ($v === null) $v = trim((string)setting_get('lecture_break_until', ''));
    return $v;
}

/** Anzahl Tage Schonfrist nach der vorlesungsfreien Zeit (kein Streak-Verlust, Sammeln läuft wieder). */
const STREAK_GRACE_DAYS = 7;

/** Vorlesungsfreie Zeit = von Sekretariat/Vorsitz explizit festgelegt und Enddatum noch nicht
 *  vorbei. Übergangsfall (z. B. Umstieg von der alten Automatik): Pausenbeginn gesetzt, aber
 *  noch KEIN Enddatum → die Pause läuft weiter, bis Sekki das Enddatum festlegt. */
function in_lecture_break(): bool
{
    $until = lecture_break_until();
    if ($until !== '') return date('Y-m-d') <= $until;
    return streak_break_since() !== '';
}

/** Letzter Tag der Schonfrist (Enddatum + 7 Tage; '' = keine vorlesungsfreie Zeit gesetzt). */
function lecture_break_grace_until(): string
{
    $until = lecture_break_until();
    if ($until === '') return '';
    $ts = strtotime($until);
    return $ts === false ? '' : date('Y-m-d', $ts + STREAK_GRACE_DAYS * 86400);
}

/** Schonfrist läuft = vorlesungsfreie Zeit ist vorbei, aber noch keine 7 Tage her. */
function in_streak_grace(): bool
{
    $until = lecture_break_until();
    if ($until === '') return false;
    $today = date('Y-m-d');
    return $today > $until && $today <= lecture_break_grace_until();
}

/**
 * Vorlesungsfreie Zeit festlegen/ändern (Sekretariat/Vorsitz/Admin): schützt die Streaks
 * bis einschließlich $until – keine Kette kann reißen, gesammelt wird normal weiter.
 * Der Pausenbeginn (streak_break_since) wird beim ersten Festlegen auf heute gesetzt –
 * dort bleibt die Verlust-Uhr stehen. Rückgabe: false bei ungültigem/vergangenem Datum.
 */
function lecture_break_set(string $until): bool
{
    $until = trim($until);
    $ts = strtotime($until);
    if ($ts === false) return false;
    $until = date('Y-m-d', $ts);
    if ($until < date('Y-m-d')) return false;
    setting_set('lecture_break_until', $until); lecture_break_until($until);
    if (streak_break_since() === '') { setting_set('streak_break_since', date('Y-m-d')); streak_break_since(date('Y-m-d')); }
    return true;
}

/** Vorlesungsfreie Zeit sofort beenden: Enddatum = gestern → ab heute läuft die 7-Tage-Schonfrist.
 *  Funktioniert auch im Übergangsfall (Pause ohne Enddatum). */
function lecture_break_end_now(): void
{
    if (lecture_break_until() === '' && streak_break_since() === '') return;
    $y = date('Y-m-d', strtotime('-1 day'));
    setting_set('lecture_break_until', $y); lecture_break_until($y);
    if (streak_break_since() === '') { setting_set('streak_break_since', $y); streak_break_since($y); }
}

/** Sekki-Aufgabe „Vorlesungsfreie Zeit festlegen": Enddatum fehlt UND entweder läuft schon
 *  eine Pause ohne Enddatum (Übergangsfall) oder es steht keine ordentliche Sitzung mehr an. */
function lecture_break_pending_for_sekki(): bool
{
    if (lecture_break_until() !== '') return false; // schon festgelegt (oder Schonfrist läuft noch)
    if (streak_break_since() !== '') return true;   // Pause läuft, aber ohne Enddatum → festlegen!
    $st = db()->query(
        "SELECT 1 FROM meetings
         WHERE cancelled = 0 AND draft = 0 AND kind = 'ordentlich'
           AND date(starts_at) >= date('now','localtime') LIMIT 1"
    );
    return $st->fetchColumn() === false;
}

/** Startdatum der laufenden vorlesungsfreien Zeit ('' = keine). Pro Request gecached; $set aktualisiert den Cache. */
function streak_break_since(?string $set = null): string
{
    static $v = null;
    if ($set !== null) { $v = $set; return $v; }
    if ($v === null) $v = trim((string)setting_get('streak_break_since', ''));
    return $v;
}

/**
 * Hält den Pausen-Status aktuell (pro Request nur einmal):
 * - Läuft die vorlesungsfreie Zeit: sicherstellen, dass der Pausenbeginn gemerkt ist
 *   (dort steht die Streak-Uhr still).
 * - Ist auch die 7-Tage-Schonfrist vorbei: Zeitraum + Pausenbeginn aufräumen – ab dann
 *   gelten wieder die normalen Regeln. Eine Massen-„Brücke" braucht es nicht mehr:
 *   Wer sich bis zum Ende der Schonfrist einmal meldet, hat damit einen frischen
 *   letzten Streak-Tag; wer nicht, verliert die Kette danach regulär.
 */
function streak_maintain_break(): string
{
    static $done = false;
    if ($done) return 'skip';
    $done = true;
    try {
        $until = lecture_break_until();
        if ($until === '') {
            // Übergangsfall: Pause läuft (z. B. aus der alten Automatik), Enddatum fehlt noch –
            // NICHT abräumen, sonst stürben die geschützten Streaks; Sekki bekommt die Aufgabe.
            return streak_break_since() !== '' ? 'break-open-end' : 'no-break';
        }
        if (in_lecture_break()) {
            if (streak_break_since() === '') { setting_set('streak_break_since', date('Y-m-d')); streak_break_since(date('Y-m-d')); }
            return 'break-ongoing';
        }
        if (in_streak_grace()) return 'grace';
        // Schonfrist vorbei → Zeitraum abräumen, Normalbetrieb
        setting_set('lecture_break_until', ''); lecture_break_until('');
        setting_set('streak_break_since', ''); streak_break_since('');
        return 'break-cleared';
    } catch (\Throwable $e) { return 'error'; }
}

/**
 * Bonus-Flammen für alle aktiven Mitglieder. Läuft über einen Settings-Merker genau einmal,
 * inklusive kurzer Dashboard-Nachricht. Tote Ketten starten dadurch bei 3.
 */
function streak_grant_bonus_once(): void
{
    try {
        if (setting_get('streak_bonus_2026_07', '') !== '') return;
        setting_set('streak_bonus_2026_07', date('Y-m-d H:i')); // sofort merken – nie doppelt
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        foreach (members_all() as $mm) {
            $mid = (int)$mm['id'];
            $s = member_streak($mid); // 0, wenn die Kette (trotz Schutz) tot ist
            $new = $s['current'] + 3;
            // Heutige Flamme nicht verschlucken: wer heute noch nicht da war, bekommt „gestern"
            // als letzten Streak-Tag – der nächste Besuch zählt dann normal +1 obendrauf.
            $newLast = trim((string)($s['last'] ?? '')) === $today ? $today : $yesterday;
            db()->prepare('UPDATE members SET streak_current = ?, streak_best = MAX(streak_best, ?), streak_last_day = ? WHERE id = ?')
                ->execute([$new, $new, $newLast, $mid]);
            dm_send($mid, 'Streak-Fee 🔥', 'Kleines Dankeschön: Dir wurden einmalig 3 Bonus-Flammen gutgeschrieben – als Ausgleich für die vorlesungsfreie Zeit, in der das Sammeln pausiert war. Deine Streak steht jetzt bei ' . $new . '. 🔥',
                null, false, true, 'dm_score');
        }
    } catch (\Throwable $e) { /* die Gutschrift darf nie eine Seite crashen */ }
}

/**
 * Admin-Werkzeug (Aktivitätsstatistik): Streak eines Mitglieds manuell auf einen Wert setzen.
 * Bestwert wird nie gesenkt. 0 löscht die Kette (letzter Streak-Tag leer); sonst wird der
 * letzte Streak-Tag auf gestern gesetzt (bzw. bleibt heute), damit die Kette lebt und die
 * nächste Tages-Flamme normal obendrauf zählt. Rückgabe: false bei ungültigen Werten.
 */
function streak_admin_set(int $memberId, int $value): bool
{
    if ($memberId <= 0 || $value < 0 || !member_get($memberId)) return false;
    if ($value === 0) {
        db()->prepare("UPDATE members SET streak_current = 0, streak_last_day = '' WHERE id = ?")->execute([$memberId]);
        return true;
    }
    $today = date('Y-m-d');
    $last = trim((string)(member_streak($memberId)['last'] ?? ''));
    $newLast = $last === $today ? $today : date('Y-m-d', strtotime('-1 day'));
    db()->prepare('UPDATE members SET streak_current = ?, streak_best = MAX(streak_best, ?), streak_last_day = ? WHERE id = ?')
        ->execute([$value, $value, $newLast, $memberId]);
    return true;
}

/** Zahl der Tage im Zeitraum (nach $fromExclusive bis einschl. $toInclusive), die von einer
 *  eingetragenen Abwesenheit des Mitglieds abgedeckt sind (für die Streak-Kulanz). */
function member_absent_days_between(int $memberId, string $fromExclusive, string $toInclusive): int
{
    if ($memberId <= 0) return 0;
    $from = strtotime(substr($fromExclusive, 0, 10));
    $to   = strtotime(substr($toInclusive, 0, 10));
    if ($from === false || $to === false || $to <= $from) return 0;
    $st = db()->prepare(
        'SELECT starts_at, ends_at FROM absences
         WHERE member_id = ? AND date(ends_at) > date(?) AND date(starts_at) <= date(?)'
    );
    $st->execute([$memberId, $fromExclusive, $toInclusive]);
    $covered = [];
    foreach ($st->fetchAll() as $a) {
        $s = max(strtotime(substr((string)$a['starts_at'], 0, 10)), $from + 86400); // Tag nach dem letzten Streak-Tag
        $e = min(strtotime(substr((string)$a['ends_at'], 0, 10)), $to);
        for ($d = $s; $d !== false && $d <= $e; $d += 86400) $covered[date('Y-m-d', $d)] = true;
    }
    return count($covered);
}

/** Lebt die Tages-Streak noch? Kulanz: Die Kette reißt erst, wenn man sich
 *  5 Tage in Folge nicht einloggt – der letzte Streak-Tag darf also bis zu 5 Tage her sein.
 *  In der vorlesungsfreien Zeit UND der 7-Tage-Schonfrist danach steht die Uhr still:
 *  Die Kette gilt zusätzlich als lebendig, wenn sie es zum Pausenbeginn war (kein Verlust).
 *  Eingetragene Abwesenheiten zählen ebenfalls NICHT gegen die Kulanz. */
function streak_alive_for(int $memberId, string $lastDay): bool
{
    $lastDay = trim($lastDay);
    if ($lastDay === '') return false;
    $ts = strtotime($lastDay);
    if ($ts === false) return false;
    $aliveVs = function (string $refDay) use ($memberId, $lastDay, $ts): bool {
        $diff = (int)floor((strtotime($refDay) - $ts) / 86400);
        if ($diff < 0) return true; // letzter Streak-Tag liegt nach dem Bezugstag (z. B. frisch gesammelt)
        if ($diff <= 5) return true;
        // Abwesenheitstage im Loch abziehen – wer eingetragen weg war, verliert die Streak nicht.
        return $memberId > 0 && $diff - member_absent_days_between($memberId, $lastDay, $refDay) <= 5;
    };
    if ($aliveVs(date('Y-m-d'))) return true;
    // Pause/Schonfrist: lebte die Kette zum Pausenbeginn, bleibt sie bis zum Schonfrist-Ende am Leben
    $since = streak_break_since();
    if ($since !== '' && (in_lecture_break() || in_streak_grace())) return $aliveVs($since);
    return false;
}

/** Rückwärtskompatibler Wrapper (ohne Mitgliedsbezug → ohne Abwesenheits-Kulanz). */
function streak_alive(string $lastDay): bool
{
    return streak_alive_for(0, $lastDay);
}

/** Aktueller/bester Tages-Streak (abgelaufener aktueller Streak wird als 0 gemeldet). */
function member_streak(int $memberId): array
{
    $m = [];
    try {
        $s = db()->prepare('SELECT streak_current, streak_best, streak_last_day FROM members WHERE id = ?');
        $s->execute([$memberId]); $m = $s->fetch() ?: [];
    } catch (\Throwable $e) {}
    $cur  = (int)($m['streak_current'] ?? 0);
    $last = trim((string)($m['streak_last_day'] ?? ''));
    if (!streak_alive_for($memberId, $last)) $cur = 0; // Kette gerissen (Kulanz + Pause + Abwesenheit)
    return ['current' => $cur, 'best' => (int)($m['streak_best'] ?? 0), 'last' => $last];
}

/** Freigeschaltete Belohnungen eines Typs ('deco'|'skin') als key => Label.
 *  Im Diagnose-Test-Modus (test_unlock_all) sind für die/den eigene:n Nutzer:in alle freigeschaltet. */
function member_rewards_of_type(int $memberId, string $type): array
{
    $cat = achievements_catalog();
    $out = [];
    $all = test_unlock_all() && $memberId === (int)($_SESSION['member_id'] ?? 0);
    $codes = $all ? array_keys($cat) : member_achievement_codes($memberId);
    foreach ($codes as $code) {
        $rs = (array)($cat[$code]['rewards'] ?? []); // optionale Zusatz-Belohnungen (z. B. Prinzessin/Prinz: Schleife + Krönchen)
        if (!empty($cat[$code]['reward'])) $rs[] = $cat[$code]['reward'];
        foreach ($rs as $r) {
            if (($r['type'] ?? '') === $type && isset($r['key'])) $out[$r['key']] = $r['label'] ?? '';
        }
    }
    if ($type === 'deco') {
        // Kaufbarer Schmuck: Gekauftes und Beschlagnahmtes gehört dazu wie Verdientes (im
        // Testmodus alles, siehe shop_available) – aber nur die Deco-Stücke; Farben und
        // Streak-Stile holen sich ihre Freischaltung an ihrer eigenen Stelle, aus derselben Quelle.
        foreach (array_keys(shop_available($memberId)) as $sk) {
            $si = shop_items()[$sk] ?? null;
            if ($si && ($si['type'] ?? '') === 'deco') $out[$sk] = (string)$si['label'];
        }
        $mem = member_get($memberId) ?? [];
        // Rollen-Schmuck: Der Geldsack gehört zur Rolle Finanzen (kein Achievement) und geht mit der Rolle wieder verloren.
        if ($all || (string)($mem['role'] ?? '') === 'finanzen') {
            $out['moneybag'] = 'Geldsack 💰';
        }
        // Namens-Schmuck (Spaß-Insider): Lotta Schwarz bekommt ihre berühmte Tasse – kein Achievement,
        // hängt nur am Namen (geht mit einer Umbenennung wieder verloren; die Selbstheilung legt sie dann ab).
        // Der Name steht in den Einstellungen; ist dort nichts hinterlegt, gibt es das Stück nicht.
        $tassenName = trim((string)setting_get('deco_tasse_name', 'Lotta Schwarz'));
        if ($tassenName !== '' && ($all || mb_strtolower(trim((string)($mem['name'] ?? ''))) === mb_strtolower($tassenName))) {
            $out['tasse'] = deco_tasse_label($tassenName);
        }
    }
    return $out;
}

/** Freigeschaltete Avatar-Schmuckstücke (key => Label). */
function member_reward_decos(int $memberId): array { return member_rewards_of_type($memberId, 'deco'); }

/** Freigeschaltete App-Skins (key => Label) inkl. geschenkter Skins (Skin-Geschenke, z. B. „Hochsommer"). */
function member_reward_skins(int $memberId): array
{
    $out = member_rewards_of_type($memberId, 'skin');
    $out += member_granted_skins($memberId); // geschenkte Skins ergänzen, ohne verdiente Labels zu überschreiben
    // Einmal-Gabe „Technik-Engel": Wer gerade die Rolle Technik/Admin hat, bekommt das Set ohne
    // Ticket – der Vorsitz NICHT, obwohl er dieselbe Verwaltung sieht. Gefragt wird nach `role`
    // und nicht nach der alten Spalte is_admin: die ist nur noch Altbestand aus der Zeit vor den
    // Rollen.
    // Grund: Erledigt-Markierungen von früher stehen nirgends fest, rückwirkend ist also nichts
    // zu erkennen. Alle NACH dieser Gabe müssen es sich verdienen. Sie läuft über
    // achievement_unlock() und ist damit von selbst einmalig.
    if ($memberId > 0 && !isset($out['engel'])) {
        try {
            $adm = db()->prepare('SELECT role FROM members WHERE id = ?');
            $adm->execute([$memberId]);
            if ((string)$adm->fetchColumn() === 'admin') achievement_unlock($memberId, 'technik_engel');
        } catch (\Throwable $e) {}
    }
    // Besitzstand „Pride": Wer beigetreten ist, solange der Skin Grundausstattung war, behält ihn
    // ohne Bedingung. Alle anderen schalten ihn über „Gute Ansprache" (Pronomen im Profil) frei.
    // Leeres joined_at heißt: Mitglied aus der Zeit vor dieser Spalte.
    if (!isset($out['pride']) && $memberId > 0) {
        try {
            $s = db()->prepare("SELECT 1 FROM members WHERE id = ? AND (joined_at = '' OR joined_at <= '2026-08-09')");
            $s->execute([$memberId]);
            if ($s->fetchColumn()) $out['pride'] = 'App-Design „Pride" 🏳️‍🌈';
        } catch (\Throwable $e) {}
    }
    return $out;
}

/** Per Skin-Geschenk erhaltene Skins (key => Label mit 🎁). */
function member_granted_skins(int $memberId): array
{
    if ($memberId <= 0) return [];
    $out = [];
    try {
        $s = db()->prepare('SELECT DISTINCT skin FROM skin_gifts WHERE to_id = ?');
        $s->execute([$memberId]);
        $skins = app_skins();
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $k) {
            if (isset($skins[$k])) $out[$k] = ($skins[$k]['label'] ?? $k) . ' 🎁';
        }
    } catch (\Throwable $e) {}
    return $out;
}

// ---------------------------------------------------------------------------
// AsT-Markt: kaufbarer Avatar-Schmuck jenseits der Achievements.
//
// Die Währung ist der AsT (AStA-Taler) – und zwar der DEPOTWERT des Schicht-Depots aus
// der Tauschbörse (swap_market_stats): Jeder vollzogene Tausch bringt 100 ASX-„Aktien",
// der Kurs steigt mit jedem Handel am Markt. Das Depot ist als Satire angelegt, hat mit dem
// AsT-Markt aber eine Funktion: Der Depotwert lässt sich hier ausgeben. Das Guthaben wird
// bewusst NICHT
// gespeichert, sondern gerechnet (Depotwert minus Summe der Käufe) – nichts zu pflegen,
// und wer je getauscht hat, steht rückwirkend im Plus. Gekauftes bleibt für immer.
//
// Royals (Spitzenklasse) dürfen alle 3 Monate EIN Stück kostenlos BESCHLAGNAHMEN. Der
// Status wird im Moment der Beschlagnahme live geprüft (royal_unlocked – er kann ja
// wandern); die Sperrfrist hängt an der letzten Beschlagnahme, nicht am Status: Wer die
// Krone verliert und wiedergewinnt, wartet trotzdem seine 3 Monate ab.
// ---------------------------------------------------------------------------

/** Registry des AsT-Markts: key => Label + Preis in AsT (Börsen-Größenordnung: EIN Tausch
 *  bringt beim Startkurs ~4.200 AsT Depotwert). Bewusst NICHT an Achievements gebunden;
 *  jedes Stück braucht ein SVG (avatar_deco_svg) und einen Slot (avatar_deco_slot).
 *  Die Sortimentslinie ist Absicht: Ein Depot investiert selbstverständlich in Sachwerte – Holz. */
function shop_items(): array
{
    return [
        // Avatar-Schmuck (type deco: SVG + Slot wie jede verdiente Deco)
        'zwille'      => ['label' => 'Zwille 🌿',          'price' => 4200,  'type' => 'deco'],
        'wolke'       => ['label' => 'Regenwölkchen ☁️',   'price' => 5500,  'type' => 'deco'],
        'wanderstock' => ['label' => 'Wanderstock 🪵',     'price' => 6900,  'type' => 'deco'],
        'vogelnest'   => ['label' => 'Vogelnest 🪺',       'price' => 9999,  'type' => 'deco'],
        'bernstein'   => ['label' => 'Bernstein-Kette 🧡', 'price' => 13370, 'type' => 'deco'],
        'ballon'      => ['label' => 'Herzballon 🎈',      'price' => 5800,  'type' => 'deco'],
        'wackelaugen' => ['label' => 'Wackelaugen 👀',     'price' => 6400,  'type' => 'deco'],
        'ringplanet'  => ['label' => 'Ringplanet 🪐',      'price' => 16000, 'type' => 'deco'],
        'papierflieger' => ['label' => 'Papierflieger ✈️', 'price' => 12000, 'type' => 'deco'],
        'baum'        => ['label' => 'Hausbaum 🌳',        'price' => 42000, 'type' => 'deco'],
        // Avatar-Farben (type palette: Eintrag mit 'shop' in avatar_palettes + pal-CSS)
        'bulle'       => ['label' => 'Bulle & Bär 📈',     'price' => 8400,  'type' => 'palette'],
        'kupfer'      => ['label' => 'Kupfer 🪙',          'price' => 8400,  'type' => 'palette'],
        'mitternacht' => ['label' => 'Mitternacht 🌃',     'price' => 8400,  'type' => 'palette'],
        'pfau'        => ['label' => 'Pfau 🦚',            'price' => 8400,  'type' => 'palette'],
        'regentag'    => ['label' => 'Regentag 🌧️',        'price' => 8400,  'type' => 'palette'],
        'konfetti'    => ['label' => 'Konfetti 🎉',        'price' => 8400,  'type' => 'palette'],
        'herbstlaub'  => ['label' => 'Herbstlaub 🍂',      'price' => 8400,  'type' => 'palette'],
        'vinyl'       => ['label' => 'Vinyl 🎶',           'price' => 8400,  'type' => 'palette'],
        'strick'      => ['label' => 'Strick 🧶',          'price' => 8400,  'type' => 'palette'],
        // Streak-Stile (type flame: eigene Silhouette/Farbwelt in flame_svg + style.css)
        'rakete'      => ['label' => 'Rakete 🚀',          'price' => 21000, 'type' => 'flame'],
        'diamant'     => ['label' => 'Diamant 💎',         'price' => 21000, 'type' => 'flame'],
        'regenbogen'  => ['label' => 'Regenbogen 🌈',      'price' => 21000, 'type' => 'flame'],
    ];
}

/** Startguthaben in AsT – für ALLE, auch künftige Neulinge: als Konstante gerechnet, nie
 *  gespeichert. Reicht bewusst knapp für nichts – wer mehr will, tauscht oder legt es an. */
const AST_STARTGUTHABEN = 4000;

/** Depotwert in AsT (abgerundet): Aktien × Kurs aus dem Schicht-Depot der Tauschbörse. */
function ast_depot(int $memberId): int
{
    if ($memberId <= 0) return 0;
    try { return (int)floor((float)swap_market_stats($memberId)['depot']); }
    catch (\Throwable $e) { return 0; }
}

/** Ausgegebene AsT (Beschlagnahmen kosten 0 und tauchen hier nicht auf). */
function ast_spent(int $memberId): int
{
    if ($memberId <= 0) return 0;
    try {
        $s = db()->prepare('SELECT COALESCE(SUM(price), 0) FROM shop_purchases WHERE member_id = ?');
        $s->execute([$memberId]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/** Geschenkte AsT (Vorsitz/Admin über die Achievements-Übersicht, Tabelle ast_grants). */
function ast_granted(int $memberId): int
{
    if ($memberId <= 0) return 0;
    try {
        $s = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM ast_grants WHERE member_id = ?');
        $s->execute([$memberId]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/**
 * AsT verschenken (Vorsitz/Admin): Buchung eintragen und der Person eine feierliche
 * Dashboard-Ankündigung hinlegen. Rechte prüft die aufrufende Seite (require_admin).
 */
function ast_grant(int $memberId, int $amount, string $reason, int $byId): bool
{
    $reason = trim($reason);
    if ($memberId <= 0 || $amount < 1 || $amount > 100000) return false;
    $m = member_get($memberId);
    if (!$m || (int)($m['active'] ?? 0) !== 1) return false;
    db()->prepare('INSERT INTO ast_grants(member_id, amount, reason, granted_by) VALUES(?,?,?,?)')
       ->execute([$memberId, $amount, $reason, $byId ?: null]);
    $wer = ($by = $byId ? member_get($byId) : null) ? short_name((string)$by['name']) : 'dem Vorsitz';
    dm_send($memberId, 'Tauschbörse',
        '🎉🪙 Überraschung: Du hast von ' . $wer . ' ' . number_format($amount, 0, ',', '.') . ' AsT geschenkt bekommen'
        . ($reason !== '' ? ' – ' . $reason : '') . '. Sie liegen schon in deinem Depot: Gönn dir was im Belohnungs-Locker!',
        null, false, true, 'dm_vorsitz', 'tauschboerse.php#depot');
    return true;
}

function ast_balance(int $memberId): int
{
    if ($memberId <= 0) return 0;
    // Verfügbar = Startguthaben plus Depotwert plus Geschenke minus Käufe minus gebundene
    // Sparplan-Einlagen plus realisierte Verkaufserlöse (etf_flow rechnet die letzten beiden zusammen).
    return max(0, AST_STARTGUTHABEN + ast_depot($memberId) + ast_granted($memberId) - ast_spent($memberId) - etf_flow($memberId));
}

// ---- ETF-Sparpläne des Schicht-Depots (Satire wie das Depot selbst) -----------------------

/** Die drei Sparpläne: niedriges/mittleres/hohes Risiko mit entsprechender Tages-Drift und
 *  -Schwankung. Die „zufällige" Entwicklung ist deterministisch aus dem Datum gewürfelt
 *  (etf_index) – gleicher Tag, gleicher Kurs, für alle, ohne Cron und ohne Speicherung. */
function etf_plans(): array
{
    return [
        'defensiv' => ['label' => 'ASX Solide Defensiv',    'risk' => 'niedriges Risiko', 'drift' => 0.0005, 'vol' => 0.004],
        'balance'  => ['label' => 'ASX Balance Global',     'risk' => 'mittleres Risiko', 'drift' => 0.0012, 'vol' => 0.02],
        'turbo'    => ['label' => 'ASX Turbo Opportunities', 'risk' => 'hohes Risiko',    'drift' => 0.002,  'vol' => 0.06],
    ];
}

/** Kursindex eines Sparplans am Tag $tag (Start 100,00). Deterministisch:
 *  je Tag ein Pseudo-Zufallsschritt aus crc32(plan|datum) – reproduzierbar ohne Tabelle. */
function etf_index(string $plan, string $tag = ''): float
{
    $p = etf_plans()[$plan] ?? null;
    if (!$p) return 100.0;
    $tag = $tag !== '' ? $tag : date('Y-m-d');
    if ($tag < '2026-08-01') $tag = '2026-08-01';
    static $cache = [];
    if (isset($cache[$plan . $tag])) return $cache[$plan . $tag];
    $idx = 100.0;
    $d = new DateTime('2026-08-01');
    $ende = new DateTime($tag);
    while ($d <= $ende) {
        $u = ((int)abs(crc32($plan . '|' . $d->format('Y-m-d'))) % 20001) / 10000 - 1.0; // -1..1
        $idx *= 1 + (float)$p['drift'] + (float)$p['vol'] * $u;
        if ($idx < 1.0) $idx = 1.0; // ein Totalverlust wäre nicht mehr witzig
        $d->modify('+1 day');
    }
    return $cache[$plan . $tag] = $idx;
}

/** Aktive Positionen je Plan: ['einzahlung' => AsT, 'wert' => AsT (heutiger Kurs)]. */
function etf_positions(int $memberId): array
{
    $out = [];
    foreach (array_keys(etf_plans()) as $pk) $out[$pk] = ['einzahlung' => 0, 'wert' => 0];
    if ($memberId <= 0) return $out;
    try {
        $s = db()->prepare('SELECT plan, amount, bought_on FROM ast_invest WHERE member_id = ? AND sold_on IS NULL');
        $s->execute([$memberId]);
        foreach ($s->fetchAll() as $r) {
            $pk = (string)$r['plan'];
            if (!isset($out[$pk])) continue;
            $out[$pk]['einzahlung'] += (int)$r['amount'];
            $out[$pk]['wert'] += (int)round((int)$r['amount'] * etf_index($pk) / etf_index($pk, (string)$r['bought_on']));
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** Gebundene AsT: Summe aller Einlagen minus realisierte Verkaufserlöse. */
function etf_flow(int $memberId): int
{
    if ($memberId <= 0) return 0;
    try {
        $s = db()->prepare('SELECT COALESCE(SUM(amount), 0) - COALESCE(SUM(sold_value), 0) FROM ast_invest WHERE member_id = ?');
        $s->execute([$memberId]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/** AsT in einen Sparplan legen (gegen das verfügbare Guthaben). */
function etf_invest(int $memberId, string $plan, int $amount): array
{
    $p = etf_plans()[$plan] ?? null;
    if ($memberId <= 0 || !$p) return ['ok' => false, 'msg' => 'Diesen Sparplan gibt es nicht.'];
    if ($amount < 100) return ['ok' => false, 'msg' => 'Mindestanlage: 100 AsT.'];
    $frei = ast_balance($memberId);
    if ($amount > $frei) {
        return ['ok' => false, 'msg' => 'So viel ist nicht frei – verfügbar sind ' . number_format($frei, 0, ',', '.') . ' AsT.'];
    }
    try {
        db()->prepare('INSERT INTO ast_invest(member_id, plan, amount) VALUES(?,?,?)')->execute([$memberId, $plan, $amount]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Das hat gerade nicht geklappt.'];
    }
    return ['ok' => true, 'msg' => number_format($amount, 0, ',', '.') . ' AsT in „' . $p['label'] . '" angelegt.'];
}

/** ALLE aktiven Positionen eines Plans zum heutigen Kurs verkaufen (realisiert Gewinn/Verlust). */
function etf_sell(int $memberId, string $plan): array
{
    $p = etf_plans()[$plan] ?? null;
    if ($memberId <= 0 || !$p) return ['ok' => false, 'msg' => 'Diesen Sparplan gibt es nicht.'];
    try {
        $s = db()->prepare('SELECT id, amount, bought_on FROM ast_invest WHERE member_id = ? AND plan = ? AND sold_on IS NULL');
        $s->execute([$memberId, $plan]);
        $rows = $s->fetchAll();
        if (!$rows) return ['ok' => false, 'msg' => 'In diesem Sparplan liegt gerade nichts.'];
        $erloes = 0; $einlage = 0;
        $upd = db()->prepare("UPDATE ast_invest SET sold_on = date('now','localtime'), sold_value = ? WHERE id = ? AND sold_on IS NULL");
        foreach ($rows as $r) {
            $wert = (int)round((int)$r['amount'] * etf_index($plan) / etf_index($plan, (string)$r['bought_on']));
            $upd->execute([$wert, (int)$r['id']]);
            if ($upd->rowCount() === 1) { $erloes += $wert; $einlage += (int)$r['amount']; }
        }
        $gv = $erloes - $einlage;
        return ['ok' => true, 'msg' => '„' . $p['label'] . '" verkauft: ' . number_format($erloes, 0, ',', '.') . ' AsT zurück ('
            . ($gv >= 0 ? '+' : '−') . number_format(abs($gv), 0, ',', '.') . ' AsT).'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Das hat gerade nicht geklappt.'];
    }
}

/** Gekaufte/beschlagnahmte Stücke: item => Zeile. */
function shop_owned(int $memberId): array
{
    if ($memberId <= 0) return [];
    try {
        $s = db()->prepare('SELECT * FROM shop_purchases WHERE member_id = ?');
        $s->execute([$memberId]);
        $out = [];
        foreach ($s->fetchAll() as $r) $out[(string)$r['item']] = $r;
        return $out;
    } catch (\Throwable $e) { return []; }
}

/**
 * Was dieser Person aus dem Sortiment ZUR VERFÜGUNG steht: gekauft/beschlagnahmt – und im
 * Diagnose-Testmodus alles. EINE Quelle für Schmuck, Farben und Streak-Stile; shop_owned()
 * bleibt bewusst „nur echt Gekauftes" (daran hängen Kauf- und Beschlagnahme-Prüfung).
 */
function shop_available(int $memberId): array
{
    if ($memberId <= 0) return [];
    if (test_unlock_all() && $memberId === (int)($_SESSION['member_id'] ?? 0)) {
        return array_fill_keys(array_keys(shop_items()), ['item' => '', 'price' => 0, 'royal' => 0]);
    }
    return shop_owned($memberId);
}

/** Kauf gegen Äste. Doppelklicks fängt der UNIQUE-Index, den Rest die Prüfungen. */
function shop_buy(int $memberId, string $item): array
{
    $it = shop_items()[$item] ?? null;
    if ($memberId <= 0 || !$it) return ['ok' => false, 'msg' => 'Dieses Stück gibt es nicht.'];
    if (isset(shop_owned($memberId)[$item])) return ['ok' => false, 'msg' => 'Das gehört dir schon.'];
    $preis = (int)$it['price'];
    $konto = ast_balance($memberId);
    if ($konto < $preis) {
        return ['ok' => false, 'msg' => 'Dafür fehlen dir noch ' . number_format($preis - $konto, 0, ',', '.')
            . ' AsT – handle in der Tauschbörse: Jeder Tausch bringt Aktien, und der Kurs steigt.'];
    }
    try {
        db()->prepare('INSERT INTO shop_purchases(member_id, item, price, royal) VALUES(?,?,?,0)')
            ->execute([$memberId, $item, $preis]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Das hat gerade nicht geklappt.'];
    }
    return ['ok' => true, 'msg' => $it['label'] . ' für ' . number_format($preis, 0, ',', '.') . ' AsT gekauft – liegt jetzt in deinem Avatar-Schmuck. 📈'];
}

/**
 * Wie viele Stücke einer Art gibt es, die man WEDER besitzt NOCH irgendwo im Locker sieht?
 * Das ist die Zahl hinter der Schloss-Kachel: Sie sagt „da geht noch was", ohne zu verraten
 * WAS – Achievement-Titel wären bei den geheimen ja glatte Spoiler.
 *   'deco'    – Schmuck aus Achievement-Belohnungen (Kaufbares steht ohnehin sichtbar da)
 *   'palette' – Farben, die an einem GEHEIMEN Achievement hängen (die offenen zeigt der Locker
 *               weiterhin mit Schloss und Titel, das ist ja gerade der Ansporn)
 *   'flame'   – Streak-Stile, die noch nicht freigeschaltet sind
 *   'skin'    – App-Designs, die weder frei noch verdient sind
 */
function locker_versteckt(int $memberId, string $art): int
{
    if ($memberId <= 0) return 0;
    $cat  = achievements_catalog();
    $have = array_flip(member_achievement_codes($memberId));
    if ($art === 'deco' || $art === 'skin') {
        $offen = [];
        foreach ($cat as $code => $a) {
            if (isset($have[$code])) continue;
            $rs = (array)($a['rewards'] ?? []);
            if (!empty($a['reward'])) $rs[] = $a['reward'];
            foreach ($rs as $r) if (($r['type'] ?? '') === $art && !empty($r['key'])) $offen[(string)$r['key']] = 1;
        }
        return count($offen);
    }
    if ($art === 'palette') {
        $n = 0;
        foreach (avatar_palettes() as $p) {
            $ach = (string)($p['ach'] ?? '');
            if ($ach === '' || isset($have[$ach])) continue;
            if (!empty($cat[$ach]['hidden'])) $n++;
        }
        return $n;
    }
    if ($art === 'flame') {
        $n = 0;
        foreach (flame_styles_unlock() as $sk => $ach) if (!flame_style_allowed($memberId, $sk)) $n++;
        return $n;
    }
    return 0;
}

/** Steht für dieses Stück gerade GAR KEIN Weg offen – zu wenig AsT, zu wenig Props, keine Krone?
 *  Dann bleibt das Preisschild grau. Anklickbar ist die Kachel trotzdem: Der Dialog sagt dann,
 *  wie viel fehlt – das ist mehr wert als ein toter Knopf. */
function kauf_unmoeglich(int $preis, int $ast, int $props, bool $royalFrei): bool
{
    return $ast < $preis && $props < PROPS_PREIS && !$royalFrei;
}

/**
 * Alles, was der Kauf-Dialog über EIN Stück wissen muss – als JSON am Formular (data-kauf).
 * Der Dialog selbst liegt in app.js: Er bietet nur die Wege an, die gerade wirklich offenstehen,
 * und fragt bei Props und Krone in einem zweiten Schritt nach (beides ist unwiederbringlich).
 */
function kauf_daten(string $label, int $preis, int $ast, int $props, bool $royalFrei): string
{
    return (string)json_encode([
        'label' => $label,
        'preis' => $preis,
        'ast'   => $ast,
        'astOk' => $ast >= $preis,
        'props' => $props,
        'propsOk' => $props >= PROPS_PREIS,
        'propsPreis' => PROPS_PREIS,
        'royal' => $royalFrei,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Die beiden Ecken-Marken an einer Kauf-Kachel: links die Props, rechts die Krone.
 * BEWUSST nicht anklickbar (pointer-events: none im CSS) – sie sagen nur „ginge auch so".
 * Gekauft wird ausschließlich über den Dialog, sonst löst ein Fehlgriff eine Beschlagnahme aus,
 * die man drei Monate lang nicht zurückbekommt.
 */
function kauf_marken(bool $props, bool $royal): string
{
    $out = '';
    if ($props) $out .= '<span class="kauf-marke kauf-props" aria-hidden="true" title="Auch mit Props zu haben">🙌</span>';
    if ($royal) $out .= '<span class="kauf-marke kauf-krone" aria-hidden="true" title="Als Royal beschlagnahmbar">👑</span>';
    return $out;
}

/** Die Schloss-Kachel selbst – gleiche Optik wie die gesperrten Kacheln, aber ohne Vorschau. */
function locker_versteckt_html(int $n, string $was = 'Stücke'): string
{
    if ($n < 1) return '';
    return '<div class="deco-opt locked deco-geheim" title="Diese ' . h($was)
         . ' bekommt man über Achievements – welche es sind, bleibt eine Überraschung.">'
         . '<span class="deco-av deco-locked deco-fragezeichen"><i class="ti ti-lock"></i></span>'
         . '<span class="small muted">noch <strong>' . (int)$n . '</strong> versteckte</span>'
         . '</div>';
}

/** Was ein Stück in Props kostet – EIN fester Preis für alles, egal wie teuer es in AsT ist.
 *  Props sind kein zweites Geld, sondern Anerkennung: Zehn Leute fanden dich gut, dafür darfst
 *  du dir EINE Sache aussuchen. Deshalb keine Preisliste. */
const PROPS_PREIS = 10;

/** Schon für Käufe ausgegebene Props. */
function props_spent(int $memberId): int
{
    if ($memberId <= 0) return 0;
    try {
        $s = db()->prepare('SELECT COALESCE(SUM(props), 0) FROM shop_purchases WHERE member_id = ?');
        $s->execute([$memberId]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/** Noch verfügbare Props (erhalten minus ausgegeben). Die Achievements rechnen bewusst
 *  weiter mit kudos_received_total() – wer etwas kauft, soll „Gute Seele" nicht verlieren. */
function props_available(int $memberId): int
{
    return max(0, kudos_received_total($memberId) - props_spent($memberId));
}

/** Kauf gegen Props: zehn Stück für ein beliebiges Teil aus dem Sortiment. */
function shop_buy_props(int $memberId, string $item): array
{
    $it = shop_items()[$item] ?? null;
    if ($memberId <= 0 || !$it) return ['ok' => false, 'msg' => 'Dieses Stück gibt es nicht.'];
    if (isset(shop_owned($memberId)[$item])) return ['ok' => false, 'msg' => 'Das gehört dir schon.'];
    $frei = props_available($memberId);
    if ($frei < PROPS_PREIS) {
        return ['ok' => false, 'msg' => 'Dafür brauchst du ' . PROPS_PREIS . ' Props – du hast noch ' . $frei . '.'];
    }
    try {
        db()->prepare('INSERT INTO shop_purchases(member_id, item, price, royal, props) VALUES(?,?,0,0,?)')
            ->execute([$memberId, $item, PROPS_PREIS]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Das hat gerade nicht geklappt.'];
    }
    return ['ok' => true, 'msg' => $it['label'] . ' für ' . PROPS_PREIS . ' Props eingelöst – dafür haben sich Leute bei dir bedankt. 🙌'];
}

/** Letzte Royal-Beschlagnahme (Zeitpunkt) – '' wenn noch nie. Abgeleitet aus den Käufen. */
function royal_claim_last(int $memberId): string
{
    if ($memberId <= 0) return '';
    try {
        $s = db()->prepare('SELECT MAX(created_at) FROM shop_purchases WHERE member_id = ? AND royal = 1');
        $s->execute([$memberId]);
        return (string)($s->fetchColumn() ?: '');
    } catch (\Throwable $e) { return ''; }
}

/** Darf JETZT beschlagnahmt werden? (Sperrfrist-Anteil; den Royal-Status prüft der Aufrufer live.) */
function royal_claim_free(int $memberId): bool
{
    $last = royal_claim_last($memberId);
    return $last === '' || $last <= date('Y-m-d H:i:s', strtotime('-3 months'));
}

/** Royal-Beschlagnahme: kostenlos, alle 3 Monate eine – Status wird HIER live geprüft. */
function shop_claim(int $memberId, string $item): array
{
    $it = shop_items()[$item] ?? null;
    if ($memberId <= 0 || !$it) return ['ok' => false, 'msg' => 'Dieses Stück gibt es nicht.'];
    // royal_unlocked() gilt für die angemeldete Person – Beschlagnahmen gehen nur fürs eigene Konto.
    if ($memberId !== (int)($_SESSION['member_id'] ?? 0) || !royal_unlocked() || royal_test_active()) {
        return ['ok' => false, 'msg' => 'Beschlagnahmen dürfen nur echte Royals (Spitzenklasse).'];
    }
    if (isset(shop_owned($memberId)[$item])) return ['ok' => false, 'msg' => 'Das gehört dir schon.'];
    if (!royal_claim_free($memberId)) {
        return ['ok' => false, 'msg' => 'Deine nächste Beschlagnahme geht erst ab dem '
            . date('d.m.Y', strtotime(royal_claim_last($memberId) . ' +3 months')) . '.'];
    }
    try {
        db()->prepare('INSERT INTO shop_purchases(member_id, item, price, royal) VALUES(?,?,0,1)')
            ->execute([$memberId, $item]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Das hat gerade nicht geklappt.'];
    }
    return ['ok' => true, 'msg' => $it['label'] . ' im Namen der Krone beschlagnahmt – gehört jetzt dir. 👑'];
}

// --- Einmalige Auto-Skin-Anwendung (Pending) ---------------------------------
/** Merkt einen Skin zur einmaligen automatischen Anwendung vor (page_header → app.js aktiviert ihn genau einmal). */
function member_queue_skin(int $memberId, string $key): void
{
    if ($memberId <= 0 || !isset(app_skins()[$key])) return;
    try { db()->prepare('UPDATE members SET pending_skin = ? WHERE id = ?')->execute([$key, $memberId]); }
    catch (\Throwable $e) {}
}

/** Vorgemerkter Auto-Skin ('' = keiner). */
function member_pending_skin(int $memberId): string
{
    if ($memberId <= 0) return '';
    try { $s = db()->prepare('SELECT pending_skin FROM members WHERE id = ?'); $s->execute([$memberId]); return trim((string)($s->fetchColumn() ?: '')); }
    catch (\Throwable $e) { return ''; }
}

/** Löscht den vorgemerkten Auto-Skin (nach der einmaligen Anwendung durch app.js). */
function member_clear_pending_skin(int $memberId): void
{
    if ($memberId <= 0) return;
    try { db()->prepare("UPDATE members SET pending_skin = '' WHERE id = ?")->execute([$memberId]); }
    catch (\Throwable $e) {}
}

// --- Sommer-Skin verschenken (König:in des Sommers) ---------------------------
/** Wie viele „Hochsommer"-Geschenke darf diese Person noch vergeben? (Sommerkönig:in: 2 insgesamt.) */
function sommer_gift_quota(int $memberId): int
{
    if ($memberId <= 0) return 0;
    if (!in_array('sommerkoenig', member_achievement_codes($memberId), true)) return 0;
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM skin_gifts WHERE from_id = ? AND skin = 'sommer'");
        $s->execute([$memberId]);
        return max(0, 2 - (int)$s->fetchColumn());
    } catch (\Throwable $e) { return 0; }
}

/** Hat dieses Mitglied den „Hochsommer"-Skin schon (verdient oder geschenkt)? Dann kommt es als Ziel nicht in Frage. */
function has_sommer_skin(int $memberId): bool
{
    if ($memberId <= 0) return false;
    if (in_array('sommerkoenig', member_achievement_codes($memberId), true)) return true;
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM skin_gifts WHERE to_id = ? AND skin = 'sommer'");
        $s->execute([$memberId]);
        return (int)$s->fetchColumn() > 0;
    } catch (\Throwable $e) { return false; }
}

/** Schon verschenkte Sommer-Skins: [to_name, from_name] – für die Transparenz-Info „X hat von Y geschenkt bekommen". */
function sommer_gifted_list(): array
{
    try {
        return db()->query("SELECT r.name AS to_name, COALESCE(g.name, 'einem ehemaligen Mitglied') AS from_name
            FROM skin_gifts sg JOIN members r ON r.id = sg.to_id LEFT JOIN members g ON g.id = sg.from_id
            WHERE sg.skin = 'sommer' ORDER BY r.name COLLATE NOCASE")->fetchAll();
    } catch (\Throwable $e) { return []; }
}

/** Verschenkbare Ziele: aktive Mitglieder außer sich selbst und außer allen, die den Sommer-Skin schon haben. */
function sommer_gift_candidates(int $fromId): array
{
    try {
        $rows = db()->query('SELECT id, name, pronouns, avatar_decos, avatar_palette, avatar_ink FROM members WHERE active = 1 ORDER BY name COLLATE NOCASE')->fetchAll();
    } catch (\Throwable $e) { return []; }
    return array_values(array_filter($rows, fn($r) => (int)$r['id'] !== $fromId && !has_sommer_skin((int)$r['id'])));
}

/** Verschenkt den „Hochsommer"-Skin an ein Mitglied: schaltet ihn frei, merkt ihn zur einmaligen Auto-Anwendung
 *  vor und legt eine ungesehene Sonnen-Mitteilung fürs Dashboard an. Prüft Kontingent + Ziel. true bei Erfolg. */
function sommer_gift(int $fromId, int $toId, string $message): bool
{
    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) return false;
    if (sommer_gift_quota($fromId) < 1) return false;
    if (!member_get($toId) || has_sommer_skin($toId)) return false;
    try {
        db()->prepare('INSERT INTO skin_gifts(from_id, to_id, skin, message) VALUES(?,?,?,?)')
            ->execute([$fromId, $toId, 'sommer', mb_substr(trim($message), 0, 500)]);
    } catch (\Throwable $e) { return false; }
    member_queue_skin($toId, 'sommer'); // beim nächsten Seitenaufruf einmalig aktivieren
    return true;
}

/** Ungesehene Sommer-Skin-Geschenke an dieses Mitglied (für die animierte Dashboard-Mitteilung). */
function sommer_gifts_unseen(int $memberId): array
{
    if ($memberId <= 0) return [];
    try {
        $s = db()->prepare("SELECT g.id, g.message, g.created_at, m.name AS from_name, m.pronouns AS from_pronouns
            FROM skin_gifts g JOIN members m ON m.id = g.from_id
            WHERE g.to_id = ? AND g.skin = 'sommer' AND g.seen_at IS NULL ORDER BY g.id");
        $s->execute([$memberId]);
        return $s->fetchAll();
    } catch (\Throwable $e) { return []; }
}

/** Markiert ein Sommer-Skin-Geschenk als gesehen (Dashboard-Mitteilung weggeklickt). */
function sommer_gift_mark_seen(int $memberId, int $giftId): void
{
    if ($memberId <= 0 || $giftId <= 0) return;
    try { db()->prepare("UPDATE skin_gifts SET seen_at = datetime('now','localtime') WHERE id = ? AND to_id = ? AND seen_at IS NULL")->execute([$giftId, $memberId]); }
    catch (\Throwable $e) {}
}

/** Deutsche Slot-Namen für die Trage-Positionen (UI/Tooltips). */
function avatar_slot_labels(): array
{
    return ['head' => 'Kopf', 'hand' => 'Hand', 'neck' => 'Hals', 'face' => 'Gesicht', 'back' => 'Hinter dir', 'under' => 'Unter dir'];
}

/** Ausgerüstete Schmuckstücke je Trage-Position (slot => key). Pro Slot maximal EIN Stück –
 *  Kopf, Hand, Hals und Gesicht lassen sich also gleichzeitig kombinieren. */
function member_equipped_decos(int $memberId): array
{
    if ($memberId <= 0) return [];
    try { $s = db()->prepare('SELECT avatar_decos FROM members WHERE id = ?'); $s->execute([$memberId]); $raw = trim((string)$s->fetchColumn()); }
    catch (\Throwable $e) { return []; }
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', $raw))) as $k) {
        if (avatar_deco_svg($k) === '') continue;   // unbekanntes/veraltetes Stück ignorieren
        $out[avatar_deco_slot($k)] = $k;            // pro Slot gewinnt der letzte Eintrag
    }
    return $out;
}

/** Speichert die Slot-Belegung (intern; hält das Alt-Feld avatar_deco leer, es ist abgelöst). */
function member_save_decos(int $memberId, array $slotMap): bool
{
    if ($memberId <= 0) return false;
    try {
        db()->prepare("UPDATE members SET avatar_decos = ?, avatar_deco = '' WHERE id = ?")
            ->execute([implode(',', array_values($slotMap)), $memberId]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/** Rüstet ein Schmuckstück in seinem Slot aus (ersetzt das dortige); '' legt ALLES ab. Nur Freigeschaltetes. */
function member_equip_deco(int $memberId, string $key): bool
{
    $key = trim($key);
    if ($memberId <= 0) return false;
    if ($key === '') return member_save_decos($memberId, []);
    if (!isset(member_reward_decos($memberId)[$key])) return false;
    $eq = member_equipped_decos($memberId);
    $eq[avatar_deco_slot($key)] = $key;
    return member_save_decos($memberId, $eq);
}

/** Legt genau dieses Schmuckstück ab (der Slot wird frei). */
function member_unequip_deco(int $memberId, string $key): bool
{
    $key = trim($key);
    $eq = member_equipped_decos($memberId);
    $slot = avatar_deco_slot($key);
    if ($key === '' || ($eq[$slot] ?? '') !== $key) return false;
    unset($eq[$slot]);
    return member_save_decos($memberId, $eq);
}

/**
 * Aktivitäts-Statistik (App-Öffnungen je ISO-Woche) für die Verwaltung.
 * Betrachtet die letzten $window Wochen (inkl. der laufenden), chronologisch alt→neu.
 * Rückgabe:
 *   'weeks'      => string[] der ISO-Wochen (z. B. '2026-W27'),
 *   'window'     => int,
 *   'per_week'   => [woche => Anzahl aktiver Mitglieder],
 *   'this_week'  => int (aktive Mitglieder in der laufenden Woche),
 *   'ever_active'=> int (Mitglieder mit mind. einer erfassten Woche insgesamt),
 *   'members'    => [ ['id','name','last_active_on','active'(woche=>bool),'in_window','total'], … ]
 */
function activity_stats(int $window = 12): array
{
    $window = max(1, min(52, $window));
    $weeks = [];
    for ($i = $window - 1; $i >= 0; $i--) $weeks[] = date('o-\WW', strtotime("-$i weeks"));
    $thisWeek = $weeks[count($weeks) - 1];

    $byMember = [];
    foreach (db()->query('SELECT member_id, week FROM activity_weeks')->fetchAll() as $r) {
        $byMember[(int)$r['member_id']][(string)$r['week']] = true;
    }

    $perWeek = array_fill_keys($weeks, 0);
    $members = [];
    foreach (members_all() as $m) {
        $mid = (int)$m['id'];
        $set = $byMember[$mid] ?? [];
        $active = [];
        foreach ($weeks as $w) { $on = isset($set[$w]); $active[$w] = $on; if ($on) $perWeek[$w]++; }
        // Tages-Streak: zählt nur, solange die Kette lebt (Kulanz: 5 verpasste Tage)
        $sLast = trim((string)($m['streak_last_day'] ?? ''));
        $members[] = [
            'id'             => $mid,
            'name'           => (string)$m['name'],
            'last_active_on' => trim((string)($m['last_active_on'] ?? '')),
            'active'         => $active,
            'in_window'      => count(array_filter($active)),
            'total'          => count($set),
            'streak'         => streak_alive_for((int)$mid, $sLast) ? (int)($m['streak_current'] ?? 0) : 0,
            'streak_best'    => (int)($m['streak_best'] ?? 0),
        ];
    }
    return [
        'weeks'       => $weeks,
        'window'      => $window,
        'per_week'    => $perWeek,
        'this_week'   => $perWeek[$thisWeek] ?? 0,
        'ever_active' => count(array_keys($byMember)),
        'members'     => $members,
    ];
}

/** Kurzlabel einer ISO-Woche 'YYYY-Www' → 'KW ww'. */
function iso_week_label(string $weekKey): string
{
    return preg_match('/-W(\d{1,2})$/', $weekKey, $m) ? 'KW ' . (int)$m[1] : $weekKey;
}

/** Vorname für die persönliche Ansprache ("Hallo Max"). */
function first_name(string $name): string
{
    $name = trim($name);
    $sp = strpos($name, ' ');
    return $sp === false ? $name : substr($name, 0, $sp);
}

/**
 * Personalisierter Avatar-Verlauf: leitet aus dem Namen einen stabilen Farbton ab
 * und liefert einen fertigen CSS-Verlauf (für die --av-Variable der Avatar-Bubble).
 * Gleicher Name = immer gleiche Farbe; nebenwirkungsfrei.
 */
function avatar_gradient(string $name): string
{
    $hue  = abs(crc32(mb_strtolower(trim($name)))) % 360;
    $hue2 = ($hue + 42) % 360;
    return "linear-gradient(135deg, hsl($hue, 68%, 78%), hsl($hue2, 72%, 62%))";
}

/**
 * Anzeigename für Tabellen: nur der Vorname. Tragen mehrere Mitglieder denselben
 * Vornamen, wird zur Unterscheidung der erste Buchstabe des Nachnamens angehängt
 * (z. B. „Boj P."). Die Doppel-Vornamen werden einmal pro Request ermittelt.
 */
function short_name(string $fullName): string
{
    static $dupes = null;
    if ($dupes === null) {
        $dupes = [];
        $counts = [];
        foreach (db()->query('SELECT name FROM members')->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $key = mb_strtolower(first_name((string)$n));
            if ($key === '') continue;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        foreach ($counts as $k => $c) {
            if ($c > 1) $dupes[$k] = true;
        }
    }
    $first = first_name($fullName);
    if (isset($dupes[mb_strtolower($first)])) {
        $rest = trim(mb_substr($fullName, mb_strlen($first)));
        if ($rest !== '') return $first . ' ' . mb_strtoupper(mb_substr($rest, 0, 1)) . '.';
    }
    return $first;
}

/**
 * Rolle der angemeldeten Person:
 *  'admin'       – darf alles (auch der Owner/Technik-Login)
 *  'sekretariat' – darf zusätzlich Sitzungen verwalten
 *  'finanzen'    – wie Mitglied, arbeitet zusätzlich Belegblätter ab (Kachel + Finanzen-Seite)
 *  'member'      – darf Events anlegen, abstimmen, Abwesenheiten
 *  ''            – nicht angemeldet
 */
function current_role(): string
{
    if (is_owner()) return 'admin';
    $m = current_member();
    if (!$m) return '';
    $r = (string)($m['role'] ?? 'member');
    return in_array($r, ['member', 'sekretariat', 'finanzen', 'admin', 'vorsitz'], true) ? $r : 'member';
}

/** Alle eingeloggten Mitglieder dürfen Events anlegen/bearbeiten. */
function can_create_events(): bool  { return is_logged_in(); }
function can_manage_meetings(): bool { return in_array(current_role(), ['sekretariat', 'admin', 'vorsitz'], true); }
function can_admin(): bool           { return in_array(current_role(), ['admin', 'vorsitz'], true); }
/** Nur Vorsitz (bzw. Technik-Owner) darf den Eventscore bearbeiten/zurücksetzen. */
function can_edit_score(): bool      { return current_role() === 'vorsitz' || is_owner(); }
/** Belegblätter abarbeiten (Download/erledigt): Rolle Finanzen; Vorsitz/Admin als Vertretung. */
function can_finance(): bool         { return in_array(current_role(), ['finanzen', 'admin', 'vorsitz'], true); }

/**
 * „Technik" = die Admin-Rolle (bei uns liegt das Technik-Referat beim Admin).
 * Bewusst an die Rolle geknüpft, NICHT an den Referatsnamen. Ohne Argument das angemeldete Mitglied.
 */
function is_technik(?array $m = null): bool
{
    $m = $m ?? current_member();
    return $m !== null && ($m['role'] ?? '') === 'admin';
}
/** Bug-Tickets verwalten: Admin & Vorsitz (Dashboard-Kachel zeigt sie nur Admins, Verwaltung auch Vorsitz). */
function can_manage_bugs(): bool { return can_admin(); }

/**
 * Pat:innenprogramm – Verantwortliche: beliebig viele Mitglieder aus der Stammliste, die
 * (zusätzlich zu Admin & Vorsitz) Zugriff auf Verwaltung → Pat:innenprogramm bekommen und die
 * Seite als eigenen Punkt in der Titelleiste sehen – und NUR das (kein übriger
 * Verwaltungszugriff). Bewusst app-seitig gespeichert (settings, JSON-Liste von Mitglieds-IDs):
 * die Titelleiste rendert auf jeder Seite und soll dafür nicht die zweite Datenbank öffnen,
 * und die pat.sqlite bleibt reine Studi-Datenbank ohne Bezug zu Mitgliedern.
 */
/* ---------------------------------------------------------------------------
 * Externe Events (öffentliche Anmeldungen) – wer darf sie betreuen?
 *
 * Drei Stufen, die sich ergänzen:
 *   1. Vorsitz und Admin dürfen alles.
 *   2. Global freigeschaltete Referate dürfen alle externen Events – für Referate, die das
 *      ständig machen (Kultur, Erstsemesterarbeit …).
 *   3. Das je Event eingetragene betreuende Referat darf genau dieses eine.
 *
 * Die Liste steht bewusst in den APP-Einstellungen und nicht in der externen Datenbank:
 * Rechte sind eine Sache der internen Rollenlogik. So braucht diese Prüfung auch keine
 * Verbindung zur zweiten Datenbank – sie bekommt das Event einfach übergeben.
 * ------------------------------------------------------------------------- */

/**
 * Der Kontostand des gemeinsamen Mail-Topfs, angereichert um die Rundmail-Deckel.
 * Die eigentliche Rechnung steht in mail-pool.php – hier kommt nur dazu, welchen
 * freiwilligen eigenen Deckel die drei Rundmail-Bereiche gesetzt haben.
 */
function mail_budget_overview(): array
{
    $b = mail_pool_overview();
    $deckel = ['app' => 0];
    $deckel['pat'] = max(0, (int)setting_get('pat_mail_day_cap', '0'));
    try { require_once __DIR__ . '/umfrage-db.php'; $deckel['umfrage'] = umfrage_mail_cap_day(); } catch (\Throwable $e) { $deckel['umfrage'] = 0; }
    try { require_once __DIR__ . '/extern-db.php';  $deckel['extern']  = extern_mail_cap_day();  } catch (\Throwable $e) { $deckel['extern']  = 0; }
    foreach ($b['zeilen'] as $i => $z) $b['zeilen'][$i]['cap'] = $deckel[$z['quelle']] ?? 0;
    return $b;
}

/**
 * Das Mail-Konto als kleine Tabelle. Steht auf jeder Seite, die etwas daran einstellt –
 * man soll sehen, was die anderen Bereiche schon verbraucht haben, statt an einer Zahl zu
 * schrauben und zu raten.
 */
/**
 * Die Empfehlung neben einem Mail-Eingabefeld – überall gleich, aus einer Quelle.
 *
 * Weicht der eingestellte Wert ab, sagt die Zeile es in Amber. Das ist der eigentliche Zweck:
 * Eine gespeicherte Zahl schlägt jede Vorgabe im Code – ändert sich die Hoster-Grenze, stehen
 * sonst überall noch die alten Werte, ohne dass irgendwo etwas darauf hinweist.
 */
function mail_hint(string $key, int $aktuell): string
{
    $reg = mail_pool_empfehlung();
    if (!isset($reg[$key])) return '';
    $soll = (int)$reg[$key]['wert'];
    $was  = (string)$reg[$key]['was'];
    $ok   = $aktuell === $soll;
    return '<p class="small' . ($ok ? ' muted' : '') . '" style="margin:.25rem 0 0'
        . ($ok ? '' : ';color:var(--amber)') . '">'
        . '<i class="ti ti-' . ($ok ? 'circle-check' : 'alert-triangle') . '"></i> Empfohlen: <strong>'
        . $soll . '</strong> – ' . h($was)
        . ($ok ? '' : '. Eingestellt sind <strong>' . $aktuell . '</strong>.') . '</p>';
}

function mail_budget_card(): void
{
    $b = mail_budget_overview();
    ?>
    <div class="mailbudget<?= $b['eng'] ? ' eng' : '' ?>">
      <p class="mailbudget-kopf"><i class="ti ti-gauge"></i> <strong>Gemeinsames Mail-Konto</strong>
        <span class="small muted">Hoster-Grenze <?= (int)$b['limit'] ?> Mails am Tag</span></p>
      <table class="mailbudget-t">
        <thead><tr><th>Bereich</th><th>letzte 24 h</th><th>eigener Deckel</th></tr></thead>
        <tbody>
          <?php foreach ($b['zeilen'] as $z): ?>
            <tr><td><?= h((string)$z['label']) ?></td><td><?= (int)$z['sent'] ?></td>
              <td><?= (int)$z['cap'] > 0 ? (int)$z['cap'] : '–' ?></td></tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><th>zusammen verbraucht</th><th><?= (int)$b['gesamt'] ?></th><th></th></tr>
          <tr><th>Reserve für Login-Links</th><th><?= (int)$b['reserve'] ?></th><th></th></tr>
          <tr><th>frei für Rundmails</th><th><?= (int)$b['frei'] ?></th><th></th></tr>
        </tfoot>
      </table>
      <p class="small<?= $b['eng'] ? ' warn-line' : ' muted' ?>" style="margin:.4rem 0 0">
        <?php if ($b['frei'] < 1): ?>
          Der Topf ist für heute leer. Rundmails warten in ihrer Warteschlange und gehen raus,
          sobald das gleitende 24-Stunden-Fenster wieder Platz macht. <strong>Login-Links kommen weiterhin durch</strong> –
          dafür sind die <?= (int)$b['reserve'] ?> reservierten Mails da.
        <?php else: ?>
          Rundmails rechnen sich <strong>gegenseitig an</strong>, was schon rausging: Aktuell dürfen noch
          <strong><?= (int)$b['frei'] ?></strong> Mails raus – egal aus welchem Bereich. Die Reserve von
          <?= (int)$b['reserve'] ?> Mails bleibt für Login-Links stehen und wird nie angetastet.
        <?php endif; ?>
      </p>
    </div>
    <?php
}

/**
 * =============================================================================================
 * ZUSTÄNDIGKEIT: Referate ODER Personen – umschaltbar, nie beides
 * =============================================================================================
 * Vier Stellen in der App beantworten dieselbe Frage: „Wer im AStA kümmert sich darum?"
 * Referate oder Personen – beides ist richtig, je nach Bereich und Amtszeit. Deshalb wird es
 * pro Bereich eingestellt.
 *
 * AUSDRÜCKLICH ENTWEDER-ODER: Beim Umschalten wird die vorherige Auswahl GELÖSCHT. Sonst läge
 * unsichtbar eine zweite Liste herum, die beim nächsten Umschalten plötzlich wieder gälte –
 * und niemand wüsste mehr, warum jemand Zugriff hat.
 *
 * Der Bereich-Schlüssel ist 'waslaeuft' | 'extern' | 'pat' | 'umfrage:<id>' (Umfragen legen
 * das je Umfrage fest). Gespeichert wird jeweils dort, wo die Liste ohnehin liegt: wl_referate,
 * extern_referate, die referate-Spalte der Umfrage, pat_managers.
 */
function zust_bereiche(): array
{
    return ['waslaeuft' => 'was.läuft', 'extern' => 'Externe Events', 'pat' => 'Pat:innenprogramm'];
}

/** Wo liegt was? Ein Zugriffspaar je Bereich – Umfragen sind der Sonderfall mit eigener DB. */
function zust_speicher(string $bereich): array
{
    if (str_starts_with($bereich, 'umfrage:')) {
        $id = (int)substr($bereich, 8);
        return [
            'ref_get' => function () use ($id): array {
                require_once __DIR__ . '/umfrage-db.php';
                $p = umfrage_get($id);
                return $p ? umfrage_referate($p) : [];
            },
            'ref_set' => function (array $r) use ($id): void {
                require_once __DIR__ . '/umfrage-db.php';
                umfrage_referate_set($id, $r);
            },
        ];
    }
    $paare = [
        'waslaeuft' => ['wl_referate', 'wl_referate_set'],
        'extern'    => ['extern_referate', 'extern_referate_set'],
        'pat'       => [null, null],
    ];
    [$get, $set] = $paare[$bereich] ?? [null, null];
    return [
        'ref_get' => $get ? $get : static function () use ($bereich): array {
            $raw = array_map('trim', explode('|', (string)setting_get('zust_ref_' . $bereich, '')));
            $gueltig = referate_list();
            return array_values(array_filter($raw, static fn ($r) => $r !== '' && in_array($r, $gueltig, true)));
        },
        'ref_set' => $set ? $set : static function (array $refs) use ($bereich): void {
            $sauber = [];
            foreach ($refs as $r) {
                $r = trim((string)$r);
                if ($r !== '' && !in_array($r, $sauber, true) && in_array($r, referate_list(), true)) $sauber[] = $r;
            }
            setting_set('zust_ref_' . $bereich, implode('|', $sauber));
        },
    ];
}

/** 'referate' oder 'personen'. Ohne gespeicherte Wahl gilt der für den Bereich übliche Modus. */
function zust_modus(string $bereich): string
{
    $vorgabe = $bereich === 'pat' ? 'personen' : 'referate';
    $m = trim((string)setting_get('zust_modus_' . $bereich, ''));
    return $m === 'personen' || $m === 'referate' ? $m : $vorgabe;
}

/** Umschalten – und dabei die andere Seite LEEREN (siehe Kopfkommentar). */
function zust_modus_set(string $bereich, string $modus): void
{
    $modus = $modus === 'personen' ? 'personen' : 'referate';
    if ($modus === zust_modus($bereich)) { setting_set('zust_modus_' . $bereich, $modus); return; }
    setting_set('zust_modus_' . $bereich, $modus);
    if ($modus === 'personen') zust_referate_set($bereich, []);
    else                       zust_personen_set($bereich, []);
}

function zust_referate(string $bereich): array
{
    return (zust_speicher($bereich)['ref_get'])();
}

function zust_referate_set(string $bereich, array $referate): void
{
    (zust_speicher($bereich)['ref_set'])($referate);
}

/** Zuständige Personen. Das Pat:innenprogramm behält seinen alten Speicher (pat_managers). */
function zust_personen(string $bereich): array
{
    if ($bereich === 'pat') return pat_manager_ids();
    $raw = json_decode((string)setting_get('zust_pers_' . $bereich, '[]'), true);
    if (!is_array($raw)) return [];
    return array_values(array_unique(array_filter(array_map('intval', $raw), static fn ($i) => $i > 0)));
}

function zust_personen_set(string $bereich, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
    if ($bereich === 'pat') { pat_managers_set($ids); return; }
    setting_set('zust_pers_' . $bereich, json_encode($ids));
}

/** Die wirksamen Mitglieds-IDs – egal, über welchen Weg sie zustande kommen. */
function zust_ids(string $bereich): array
{
    if (zust_modus($bereich) === 'personen') {
        // Nur aktive Mitglieder: Wer die Stammliste verlassen hat, ist nicht mehr zuständig.
        $ids = zust_personen($bereich);
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = db()->prepare("SELECT id FROM members WHERE active = 1 AND id IN ($ph)");
        $s->execute($ids);
        return array_map('intval', array_column($s->fetchAll(), 'id'));
    }
    $ref = zust_referate($bereich);
    if (!$ref) return [];
    $ph = implode(',', array_fill(0, count($ref), '?'));
    $s = db()->prepare("SELECT id FROM members WHERE active = 1 AND referat IN ($ph)");
    $s->execute($ref);
    return array_map('intval', array_column($s->fetchAll(), 'id'));
}

/** Ist dieses Mitglied (ohne Argument: das angemeldete) für den Bereich zuständig? */
function zust_darf(string $bereich, ?array $m = null): bool
{
    $m = $m ?? current_member();
    if (!$m) return false;                              // Technik-Login hat weder Referat noch ID
    if (zust_modus($bereich) === 'personen') {
        return in_array((int)($m['id'] ?? 0), zust_personen($bereich), true);
    }
    $ref = trim((string)($m['referat'] ?? ''));
    return $ref !== '' && in_array($ref, zust_referate($bereich), true);
}

/** Kurzfassung fürs Anzeigen: „Referat Öffentlichkeitsarbeit" bzw. „3 Personen". */
function zust_text(string $bereich): string
{
    if (zust_modus($bereich) === 'personen') {
        $n = count(zust_personen($bereich));
        return $n === 0 ? 'niemand eingetragen' : ($n === 1 ? '1 Person' : $n . ' Personen');
    }
    $r = zust_referate($bereich);
    return $r ? implode(', ', $r) : 'kein Referat eingetragen';
}

/**
 * Die Auswahl als fertiger Formular-Baustein – EIN Aussehen an allen vier Stellen.
 *
 * Liefert: zwei Umschalt-Chips (Referate | Personen) und darunter die passende Liste. Beide
 * Listen stehen im HTML, sichtbar ist über `hidden` immer nur die aktive; ein winziges Skript
 * schaltet beim Klicken um, damit man die Wahl sieht, BEVOR man speichert. Ohne JavaScript
 * bleibt die gespeicherte Seite sichtbar – dann schaltet man eben in zwei Schritten.
 *
 * Feldnamen: `zust_modus`, `zust_ref[]`, `zust_pers[]`. Der Aufrufer gibt sie an
 * zust_form_speichern() weiter – die Reihenfolge (erst Modus, dann Liste) ist wichtig, weil
 * das Umschalten die andere Seite leert.
 */
function zust_picker_html(string $bereich): string
{
    $modus = zust_modus($bereich);
    $refs  = zust_referate($bereich);
    $pers  = zust_personen($bereich);
    $uid   = 'z' . substr(md5($bereich), 0, 6);          // eindeutig, falls mehrere auf einer Seite stehen

    $o = '<div class="zust-wahl" data-zust="' . h($uid) . '">';
    $o .= '<div class="wl-chips zust-modus">';
    foreach (['referate' => ['ti-list-numbers', 'Referate'], 'personen' => ['ti-users', 'Personen']] as $k => [$ico, $lbl]) {
        $o .= '<label class="wl-chk"><input type="radio" name="zust_modus" value="' . $k . '"'
            . ($modus === $k ? ' checked' : '') . '>'
            . '<span><i class="ti ' . $ico . '"></i>' . h($lbl) . '</span></label>';
    }
    $o .= '</div>';

    $o .= '<div class="wl-chips zust-liste zust-ref"' . ($modus === 'referate' ? '' : ' hidden') . '>';
    foreach (referate_list() as $r) {
        $o .= '<label class="wl-chk"><input type="checkbox" name="zust_ref[]" value="' . h($r) . '"'
            . (in_array($r, $refs, true) ? ' checked' : '') . '>'
            . '<span><i class="ti ti-check"></i>' . h($r) . '</span></label>';
    }
    $o .= '</div>';

    $o .= '<div class="wl-chips zust-liste zust-pers"' . ($modus === 'personen' ? '' : ' hidden') . '>';
    foreach (members_all() as $m) {
        $o .= '<label class="wl-chk"><input type="checkbox" name="zust_pers[]" value="' . (int)$m['id'] . '"'
            . (in_array((int)$m['id'], $pers, true) ? ' checked' : '') . '>'
            . '<span><i class="ti ti-check"></i>' . h(short_name((string)$m['name'])) . '</span></label>';
    }
    $o .= '</div>';
    $o .= '<p class="small muted" style="margin:.45rem 0 0"><i class="ti ti-info-circle"></i> '
        . 'Entweder <strong>Referate</strong> oder <strong>Personen</strong> – beim Umschalten wird die '
        . 'vorherige Auswahl gelöscht. Vorsitz und Admin haben unabhängig davon immer Zugriff.</p>';
    return $o . '</div>';
}

/** Gegenstück zum Baustein: nimmt den POST entgegen. Erst der Modus (leert die andere Seite),
 *  dann die Liste – umgekehrt wäre das eben Gespeicherte gleich wieder weg. */
function zust_form_speichern(string $bereich, array $post): void
{
    zust_modus_set($bereich, (string)($post['zust_modus'] ?? zust_modus($bereich)));
    if (zust_modus($bereich) === 'personen') zust_personen_set($bereich, (array)($post['zust_pers'] ?? []));
    else                                     zust_referate_set($bereich, (array)($post['zust_ref'] ?? []));
}

/** Referate mit grundsätzlichem Zugriff auf ALLE externen Events. */
function extern_referate(): array
{
    $raw = array_map('trim', explode('|', (string)setting_get('extern_referate', '')));
    $gueltig = referate_list();
    return array_values(array_filter($raw, fn($r) => $r !== '' && in_array($r, $gueltig, true)));
}

function extern_referate_set(array $referate): void
{
    $sauber = [];
    foreach ($referate as $r) {
        $r = trim((string)$r);
        if ($r !== '' && !in_array($r, $sauber, true) && in_array($r, referate_list(), true)) $sauber[] = $r;
    }
    setting_set('extern_referate', implode('|', $sauber));
}

/**
 * Darf die angemeldete Person externe Events betreuen?
 * Ohne $event: „darf überhaupt in den Bereich" (Vorsitz/Admin oder global freigeschaltet).
 * Mit $event: zusätzlich das betreuende Referat dieses einen Events.
 */
function extern_can_manage(?array $event = null): bool
{
    if (can_admin()) return true;                       // Vorsitz und Admin sehen alles
    $m = current_member();
    if (!$m) return false;                              // Technik-Login hat kein Referat
    if (zust_darf('extern', $m)) return true;           // grundsätzlich zuständig (Referate ODER Personen)
    // Und darüber hinaus das Referat, das bei DIESEM einen Event eingetragen ist.
    $ref = trim((string)($m['referat'] ?? ''));
    return $ref !== '' && $event !== null && trim((string)($event['referat'] ?? '')) === $ref;
}

/** Neue externe Events anlegen darf, wer den Bereich überhaupt betreuen darf. */
function extern_can_create(): bool
{
    return extern_can_manage(null);
}

// -------------------------------------------------------------------------------------------
// was.läuft (öffentliches Veranstaltungsportal): Rechte
//
// Die Rechte stehen hier und NICHT in wl-db.php – genauso wie bei den externen Events. Rollen
// sind Sache der internen App; die Modul-Datei kennt nur Daten und darf die lib.php nie sehen.
// -------------------------------------------------------------------------------------------

/**
 * Die Wortmarke „was.läuft" für die APP – bewusst die einzige Stelle, an der die Verwaltung
 * nicht durchgehend Petrol ist.
 *
 * Warum die Ausnahme: Das Portal hat ein eigenes Gesicht. Wer in der Verwaltung darauf stößt,
 * soll auf einen Blick sehen, dass es um dieses Ding geht und nicht um irgendein Modul.
 *
 * Warum ANDERE Zahlen als draußen: Auf der Seite steht die Marke auf fast Schwarz, hier auf
 * Weiß. Das Gelb des Verlaufs hätte dort 1,6:1 und das Limette des Punktes 1,3:1 – schlicht
 * unlesbar. Beide sind deshalb abgedunkelt; die Richtung (warmes Gelb → Koralle, Punkt in
 * Limette) bleibt dieselbe. Fläche und Schrift brauchen verschiedene Helligkeiten, das ist
 * dieselbe Regel wie bei --wl-acc/--wl-link im Portal.
 */
function wl_marke_app(): string
{
    return '<span class="wlm">was<span class="wlm-p">.</span><span class="wlm-w">läuft</span></span>';
}

/** Referate, die das Portal betreuen dürfen (Verwaltung → was.läuft). */
function wl_referate(): array
{
    $raw = array_map('trim', explode('|', (string)setting_get('wl_referate', '')));
    $gueltig = referate_list();
    return array_values(array_filter($raw, static fn ($r) => $r !== '' && in_array($r, $gueltig, true)));
}

function wl_referate_set(array $referate): void
{
    $sauber = [];
    foreach ($referate as $r) {
        $r = trim((string)$r);
        if ($r !== '' && !in_array($r, $sauber, true) && in_array($r, referate_list(), true)) $sauber[] = $r;
    }
    setting_set('wl_referate', implode('|', $sauber));
}

/**
 * Wer wird benachrichtigt, wenn im Portal etwas liegen bleibt?
 *
 * BEWUSST NICHT derselbe Kreis wie wl_can_manage(): Zugriff haben Vorsitz und Admin immer,
 * eine Mitteilung bekommt aber nur, wessen REFERAT als zuständig eingetragen ist. Wer als
 * Vorsitz mitbenachrichtigt werden will, trägt sein Referat dort ein –
 * so entscheidet EINE Liste über die Zuständigkeit, statt dass die halbe Stammliste
 * ungefragt Push zu fremder Arbeit bekommt.
 *
 * Rückgabe: Mitglieds-IDs (aktiv, nicht deaktiviert).
 */
function wl_zustaendige_ids(): array
{
    return zust_ids('waslaeuft');
}

/**
 * Wer darf Beiträge freigeben, Veranstalter anlegen und Banner pflegen?
 * Vorsitz und Admin immer; sonst die Referate, die dafür eingetragen sind.
 */
function wl_can_manage(): bool
{
    if (can_admin()) return true;
    $m = current_member();
    if (!$m) return false;                              // Technik-Login hat kein Referat
    return zust_darf('waslaeuft', $m);
}

/**
 * Umfragen, für die das Referat des aktuellen Mitglieds als zuständig eingetragen ist.
 * Grundlage für die Titelleisten-Punkte (je Umfrage einer mit ihrem Titel) und den Zugang
 * zur Umfragen-Verwaltung ohne Orga-Rolle. Läuft auf jeder Seite – deshalb der billige
 * Datei-Check zuerst: Solange es gar keine Umfrage-Datenbank gibt, wird sie hier auch
 * nicht angelegt. Einmal je Anfrage gerechnet (memoisiert).
 */
function umfrage_zustaendig_fuer_mich(): array
{
    static $polls = null;
    if ($polls !== null) return $polls;
    $polls = [];
    if (!is_logged_in()) return $polls;
    $m = current_member();
    if (!$m || !is_file(__DIR__ . '/data/umfrage.sqlite')) return $polls;
    try {
        require_once __DIR__ . '/umfrage-db.php';
        // Je Umfrage kann die Zuständigkeit an Referaten ODER an Personen hängen – deshalb
        // wird sie einzeln gefragt, statt einmal nach dem eigenen Referat zu suchen.
        foreach (umfrage_all() as $p) {
            if (zust_darf('umfrage:' . (int)$p['id'], $m)) $polls[] = $p;
        }
    } catch (\Throwable $e) { $polls = []; }
    return $polls;
}

function pat_manager_ids(): array
{
    $raw = json_decode((string)setting_get('pat_managers', '[]'), true);
    if (!is_array($raw)) return [];
    return array_values(array_unique(array_filter(array_map('intval', $raw), fn ($i) => $i > 0)));
}

function pat_managers_set(array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
    setting_set('pat_managers', json_encode($ids));
}

/** Ist das Mitglied (ohne Argument: das angemeldete) Verantwortliche:r des Pat:innenprogramms? */
function is_pat_manager(?array $m = null): bool
{
    return zust_darf('pat', $m);
}

/**
 * Royal-Testmodus (Gefahrenzone): Nur die Admin-Rolle und der Technik-Login dürfen den
 * Royal-Farbmodus zum Testen freischalten, ohne ihn per Spitzenklasse verdient zu haben.
 * Bewusst NICHT can_admin() (das schließt den Vorsitz ein).
 */
function royal_test_allowed(): bool { return is_owner() || is_technik(); }

/** Ist der Royal-Testmodus in dieser Sitzung aktiv (und die Person weiterhin berechtigt)? */
function royal_test_active(): bool { return !empty($_SESSION['royal_test']) && royal_test_allowed(); }

/** Test-Modus (Diagnose, nur Admin-Rolle/Technik): temporär ALLE Belohnungen (Avatar-Schmuck & Skins) freischalten. */
function test_unlock_all(): bool { return !empty($_SESSION['test_unlock_all']) && royal_test_allowed(); }

/**
 * Die 100-Tage-Stufe (Aura) nur ANSEHEN, ohne sie zu haben.
 *
 * Es gäbe einen zweiten Weg – die eigene Streak in der Aktivitätsstatistik auf 100 setzen –,
 * und der ist eine Falle: streak_admin_set() hebt dabei streak_best mit an, und
 * achievements_evaluate() vergibt beim nächsten Seitenaufruf dauerhaft „Woche am Ball",
 * „Durchhalter" und „Unaufhaltsam" samt Hüten, Paletten, Blitz-Stil und Sunset-Skin.
 * Zurücknehmen lässt sich das über die Oberfläche nicht, weil der Bestwert nie sinkt.
 * Dieser Schalter fasst nichts davon an: Er liegt in der Sitzung, gilt nur für die eigene
 * Anzeige und erlischt mit dem Abmelden.
 */
function test_streak_aura(): int
{
    $s = (int)($_SESSION['test_streak_aura'] ?? 0);
    return ($s === 4 || $s === 5) && royal_test_allowed() ? $s : 0;
}

/** Stufe für die EIGENE Anzeige – wie streak_tier(), nur dass der Vorführ-Schalter sie anhebt. */
function streak_tier_shown(int $days): int { return max(streak_tier($days), test_streak_aura()); }

/** Aliasse, die das Layout und ältere Seiten weiterhin nutzen. */
function is_admin(): bool { return can_admin(); }
function is_organizer(): bool { return can_admin(); }

function is_logged_in(): bool
{
    return current_member() !== null || is_owner();
}

/** Jede geschützte Seite ruft das zuerst auf. */
function require_login(): void
{
    if (!is_logged_in()) {
        try_remember();
        if (!is_logged_in()) {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
            redirect(base() . 'login.php');
        }
    }
}

function require_admin(): void
{
    require_login();
    if (!can_admin()) {
        flash('Dieser Bereich ist nur für Admins.', 'error');
        redirect(base() . 'dashboard.php');
    }
}

function require_meetings(): void
{
    require_login();
    if (!can_manage_meetings()) {
        flash('Sitzungen dürfen nur Sekretariat oder Admins verwalten.', 'error');
        redirect(base() . 'dashboard.php');
    }
}

// --- Magic-Link-Anmeldung ---------------------------------------------------
function random_token(): string
{
    return bin2hex(random_bytes(32));
}

/** Aktive Mitglieder mit dieser E-Mail (mehrere möglich = geteiltes Postfach). */
function members_by_email(string $email): array
{
    $st = db()->prepare('SELECT * FROM members WHERE active = 1 AND lower(email) = lower(?) ORDER BY name COLLATE NOCASE');
    $st->execute([trim($email)]);
    return $st->fetchAll();
}

/**
 * Bevorzugte Mail-Adresse für persönliche Erinnerungen/Benachrichtigungen eines Mitglieds:
 * die selbst gesetzte Privat-Mail (falls vorhanden), sonst die hinterlegte Adresse.
 * (Der Login-Link geht weiterhin IMMER an die hinterlegte Adresse, nicht hierüber.)
 */
function member_mail(array $row): string
{
    $alt = trim((string)($row['notify_email'] ?? ''));
    return $alt !== '' ? $alt : trim((string)($row['email'] ?? ''));
}

/**
 * Login-Link an eine E-Mail schicken, sofern mindestens ein aktives Mitglied
 * sie nutzt. Gibt true zurück, wenn eine Mail rausging.
 */
function send_login_link(string $email): bool
{
    $email = trim($email);
    if ($email === '' || !members_by_email($email)) return false;

    $token = random_token();
    // Kurzcode zum Eintippen in der installierten App (8 Hex-Zeichen, als XXXX-XXXX angezeigt)
    $codeRaw  = strtoupper(bin2hex(random_bytes(4)));
    $codeDisp = substr($codeRaw, 0, 4) . '-' . substr($codeRaw, 4, 4);
    db()->prepare('INSERT INTO login_tokens(token_hash, code_hash, email, expires_at) VALUES(?,?,?,?)')
        ->execute([hash('sha256', $token), hash('sha256', $codeRaw), $email, date('Y-m-d H:i:s', time() + 1800)]);

    $url = app_url('login.php?token=' . $token);
    // Format-bewusst rendern (wie mail_tpl_send), damit wir den Code notfalls anhängen können
    $vars = ['{{LOGIN_LINK}}' => $url, '{{LOGIN_CODE}}' => $codeDisp];
    $subject = strtr(mail_tpl_subject('login'), $vars);
    $body = strtr(mail_tpl_body('login'), $vars);
    // Sicherstellen, dass der Code in der Mail steht – auch wenn eine angepasste Vorlage den Platzhalter nicht enthält:
    if (strpos($body, $codeDisp) === false) {
        $body .= "\n\nApp installiert und der Link öffnet sich nicht in der App?\n"
            . "Gib stattdessen diesen Code in der App ein (Anmelden → Code eingeben): " . $codeDisp . "\n";
    }
    $html = mail_tpl_format('login') === 'html';
    return send_mail($email, $subject, $html ? mail_body_html($body) : $body, $html);
}

/** Token einlösen → zugehörige E-Mail (oder null). Token wird verbraucht. */
function consume_login_token(string $token): ?string
{
    $hash = hash('sha256', $token);
    $st = db()->prepare('SELECT * FROM login_tokens WHERE token_hash = ?');
    $st->execute([$hash]);
    $row = $st->fetch();
    // abgelaufene Tokens gleich mit aufräumen
    db()->exec("DELETE FROM login_tokens WHERE expires_at < datetime('now','localtime')");
    if (!$row) return null;
    db()->prepare('DELETE FROM login_tokens WHERE id = ?')->execute([(int)$row['id']]);
    if ($row['expires_at'] < date('Y-m-d H:i:s')) return null;
    return (string)$row['email'];
}

/** Kurzcode aus der Login-Mail einlösen → zugehörige E-Mail (oder null). Code wird verbraucht. */
function consume_login_code(string $code): ?string
{
    $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if ($norm === '') return null;
    $st = db()->prepare('SELECT * FROM login_tokens WHERE code_hash = ?');
    $st->execute([hash('sha256', $norm)]);
    $row = $st->fetch();
    db()->exec("DELETE FROM login_tokens WHERE expires_at < datetime('now','localtime')");
    if (!$row) return null;
    db()->prepare('DELETE FROM login_tokens WHERE id = ?')->execute([(int)$row['id']]);
    if ($row['expires_at'] < date('Y-m-d H:i:s')) return null;
    return (string)$row['email'];
}

/** Step-up-Token (z. B. für Notfall-Reset des Technik-Passworts) erzeugen. */
function create_stepup_token(int $memberId, string $purpose = 'techpw'): string
{
    $token = random_token();
    db()->prepare('INSERT INTO stepup_tokens(token_hash, member_id, purpose, expires_at) VALUES(?,?,?,?)')
        ->execute([hash('sha256', $token), $memberId, $purpose, date('Y-m-d H:i:s', time() + 1800)]);
    return $token;
}

/** Step-up-Token einlösen → member_id (oder null). Token wird verbraucht. */
function consume_stepup_token(string $token, string $purpose = 'techpw'): ?int
{
    $st = db()->prepare('SELECT * FROM stepup_tokens WHERE token_hash = ? AND purpose = ?');
    $st->execute([hash('sha256', $token), $purpose]);
    $row = $st->fetch();
    db()->exec("DELETE FROM stepup_tokens WHERE expires_at < datetime('now','localtime')");
    if (!$row) return null;
    db()->prepare('DELETE FROM stepup_tokens WHERE id = ?')->execute([(int)$row['id']]);
    if ($row['expires_at'] < date('Y-m-d H:i:s')) return null;
    return (int)$row['member_id'];
}

/** Als bestimmtes Mitglied einloggen (E-Mail muss zum Mitglied passen). */
function login_member(int $memberId): void
{
    session_regenerate_id(true);
    unset($_SESSION['royal_unlocked'], $_SESSION['royal_test']); // personengebunden – nicht vom vorherigen Konto erben (Konto-Wechsel ohne Logout)
    $_SESSION['member_id'] = $memberId;
}

/** Mitglieder, die dasselbe (in dieser Sitzung verifizierte) Postfach teilen. */
function shared_members(): array
{
    $email = $_SESSION['auth_email'] ?? '';
    return $email ? members_by_email($email) : [];
}

// --- „Eingeloggt bleiben" ---------------------------------------------------
function set_remember(string $email, int $memberId): void
{
    $token = random_token();
    db()->prepare('INSERT INTO remember_tokens(token_hash, email, member_id, expires_at) VALUES(?,?,?,?)')
        ->execute([hash('sha256', $token), $email, $memberId, date('Y-m-d H:i:s', time() + 60 * 86400)]);
    setcookie('asta_remember', $token, [
        'expires' => time() + 60 * 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']),
    ]);
}

function try_remember(): void
{
    $tok = $_COOKIE['asta_remember'] ?? '';
    if (!$tok) return;
    $st = db()->prepare('SELECT * FROM remember_tokens WHERE token_hash = ? AND expires_at > ?');
    $st->execute([hash('sha256', $tok), date('Y-m-d H:i:s')]);
    $row = $st->fetch();
    if (!$row) return;
    $_SESSION['auth_email'] = $row['email'];
    if (!empty($row['member_id']) && member_get((int)$row['member_id'])) {
        $_SESSION['member_id'] = (int)$row['member_id'];
    }
}

function clear_remember(): void
{
    $tok = $_COOKIE['asta_remember'] ?? '';
    if ($tok) {
        db()->prepare('DELETE FROM remember_tokens WHERE token_hash = ?')->execute([hash('sha256', $tok)]);
    }
    setcookie('asta_remember', '', ['expires' => time() - 3600, 'path' => '/']);
}

function logout_everything(): void
{
    clear_remember();
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Mitglied über seinen persönlichen iCal-Token finden. */
function member_by_ical_token(string $token): ?array
{
    if ($token === '') return null;
    $st = db()->prepare('SELECT * FROM members WHERE ical_token = ? AND active = 1');
    $st->execute([$token]);
    $m = $st->fetch();
    return $m ?: null;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------------------------------------------------------------------
 * Der „Puster" – die kleinen Feier-Momente (Vorbild: das Herzchen im Pat:innenprogramm).
 *
 * Der übliche Weg braucht von hier GAR NICHTS: Der Knopf trägt `data-puste="<moment>"`, app.js
 * spielt den Effekt beim Klick sofort an ihm ab, schickt sein Formular im Hintergrund los und
 * lädt die Seite danach normal neu. So sitzt der Effekt dort, wo geklickt wurde, und ist vor
 * dem Neuaufbau zu Ende – nach dem Neuladen abgespielt ginge er unter.
 *
 * Die Funktionen hier sind der ZWEITE Weg, für Formulare, die man nicht in den Hintergrund
 * schieben will: Beim Belegblatt hängen Datei-Uploads dran, und ohne die Fortschrittsanzeige
 * des Browsers sähe ein langer Upload aus wie ein Absturz. Dort wird der Moment in der Sitzung
 * gemerkt, page_header reicht ihn als window.ASTA_PUSTE weiter, und er läuft auf der Zielseite
 * am Element mit `puste_klasse()` ab.
 *
 * Bewusst sparsam: nur bei Momenten, über die man sich freut – nie beim Absagen oder Löschen.
 * ------------------------------------------------------------------------- */

/**
 * Alle bekannten Momente mit ihrer Beschriftung – EIN Verzeichnis für die Diagnose-Knöpfe
 * und den Selbsttest. Ein neuer Moment gehört hier UND in die Registry in app.js.
 */
function puste_momente(bool $mitLabel = false): array
{
    $m = [
        'zusage'  => 'Zusage zu Sitzung oder Get-Together',
        'aufgabe' => 'Aufgabe auf dem Dashboard erledigt',
        'beleg'   => 'Belegblatt eingereicht (läuft über den Server-Weg)',
        'stimme'  => 'Abgestimmt (nur Einschnappen, keine Teilchen)',
        'tausch'  => 'Schicht übernommen',
    ];
    return $mitLabel ? $m : array_keys($m);
}

/** Einen Feier-Moment für die nächste Seite vormerken (siehe puste_moment/puste_klasse). */
function puste_merken(string $moment): void
{
    $_SESSION['puste'] = $moment;
}

/** Der Moment DIESER Seite (von page_header aus der Sitzung geholt); '' = keiner. */
function puste_moment(): string
{
    return (string)($GLOBALS['ASTA_PUSTE'] ?? '');
}

/**
 * Markierung fürs Ziel-Element: `<div class="card<?= puste_klasse('beleg') ?>">`.
 * Seiten, deren Ziel schon einen eindeutigen Anker hat (#rueckmeldung, #ergebnis …),
 * brauchen das nicht – die stehen in der Registry in app.js.
 */
function puste_klasse(string $moment): string
{
    return puste_moment() === $moment ? ' puste-ziel' : '';
}

/** Reiht einen freigeschalteten Achievement-Erfolg für den eigenen Reich-Toast ein (siehe page_header). */
function ach_queue_unlock(array $data): void
{
    $_SESSION['ach_unlocks'][] = $data;
}

/** Holt und leert die eingereihten Achievement-Freischaltungen. */
function take_ach_unlocks(): array
{
    $u = $_SESSION['ach_unlocks'] ?? [];
    unset($_SESSION['ach_unlocks']);
    return $u;
}

/** Merkt die heute neu dazugewonnene Streak-Flamme für die Einmal-Animation vor (siehe page_header). */
function streak_queue_gain(int $current): void
{
    $_SESSION['streak_gain'] = $current;
}

/** Holt und leert die vorgemerkte Streak-Flamme (aktuelle Streak-Tage, 0 = nichts vorgemerkt). */
/**
 * Einmal pro Monat true: Das Props-Kontingent ist wieder aufgefüllt. Bewusst nur für Leute,
 * die im VORMONAT auch welche vergeben haben – wer nie welche verteilt, hat nichts vermisst
 * und braucht keine Animation. Der Merker verhindert, dass es beim zweiten Öffnen erneut kommt.
 */
function take_props_reset(int $memberId): bool
{
    if ($memberId <= 0) return false;
    $m = member_get($memberId);
    if (!$m) return false;
    $now = kudos_month();
    if ((string)($m['props_seen_ym'] ?? '') === $now) return false;   // diesen Monat schon gezeigt
    // Merker IMMER setzen (auch wenn nichts gezeigt wird) – sonst prüft jede Seite erneut
    db()->prepare('UPDATE members SET props_seen_ym = ? WHERE id = ?')->execute([$now, $memberId]);
    if ((string)($m['props_seen_ym'] ?? '') === '') return false;      // Erstkontakt: nichts „zurückgesetzt"
    $prev = date('Y-m', strtotime('first day of last month'));
    $st = db()->prepare('SELECT COUNT(*) FROM kudos WHERE from_id = ? AND ym = ?');
    $st->execute([$memberId, $prev]);
    if ((int)$st->fetchColumn() === 0) return false;                   // im Vormonat nichts vergeben
    return kudos_quota_left($memberId) > 0;
}

/**
 * Warum lief die Props-Animation (nicht)? Dieselben vier Bedingungen wie take_props_reset(),
 * aber NUR LESEND – für die Diagnose. Wichtig: Diese Funktion darf den Monats-Merker nicht
 * anfassen, sonst würde das Nachschauen die echte Anzeige verbrauchen.
 * Rückgabe: [['label' => …, 'ok' => bool, 'info' => …], …]
 */
function props_reset_status(int $memberId): array
{
    $m = $memberId > 0 ? member_get($memberId) : null;
    if (!$m) return [];
    $now  = kudos_month();
    $seen = (string)($m['props_seen_ym'] ?? '');
    $prev = date('Y-m', strtotime('first day of last month'));
    $st = db()->prepare('SELECT COUNT(*) FROM kudos WHERE from_id = ? AND ym = ?');
    $st->execute([$memberId, $prev]);
    $imVormonat = (int)$st->fetchColumn();
    $rest = kudos_quota_left($memberId);
    return [
        ['label' => 'Diesen Monat noch nicht gezeigt', 'ok' => $seen !== $now,
         'info' => $seen === '' ? 'noch nie' : 'zuletzt ' . $seen],
        ['label' => 'Kein Erstkontakt (Merker war schon gesetzt)', 'ok' => $seen !== '',
         'info' => $seen === '' ? 'beim allerersten Aufruf wird nichts „zurückgesetzt"' : 'gesetzt'],
        ['label' => 'Im Vormonat (' . $prev . ') Props vergeben', 'ok' => $imVormonat > 0,
         'info' => $imVormonat . ' vergeben'],
        ['label' => 'Kontingent diesen Monat noch frei', 'ok' => $rest > 0, 'info' => $rest . ' übrig'],
    ];
}

function take_streak_gain(): int
{
    $n = (int)($_SESSION['streak_gain'] ?? 0);
    unset($_SESSION['streak_gain']);
    return $n;
}

// ---------------------------------------------------------------------------
// Datums-Helfer
// ---------------------------------------------------------------------------
const WD = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const WD_LONG = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
const MONTHS = [1=>'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

function dt(?string $s): ?DateTime
{
    if (!$s) return null;
    try { return new DateTime($s); } catch (Exception) { return null; }
}

/** Tage von heute bis zum Datum (negativ = in der Vergangenheit). */
function days_until_d(string $date): int
{
    return (int)(new DateTime('today'))->diff(new DateTime(substr($date, 0, 10)))->format('%r%a');
}

/** Tagesende (sekundengenau) zum Datum eines Starts – „offenes" Ende für Sitzungen ohne feste Endzeit. */
function day_end_for(string $start): string
{
    return substr($start, 0, 10) . ' 23:59:59';
}

/** Ist dieses Ende ein „offenes Ende" (bis Tagesende eingetragen)? */
function is_open_end(?string $end): bool
{
    return $end !== null && $end !== '' && substr((string)$end, 11) === '23:59:59';
}

/** "Y-m-dTH:i" (datetime-local) oder "Y-m-d H:i" -> "Y-m-d H:i"; leer -> null. Zentral für die Admin-Formulare. */
function norm_dtl(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;
    return str_replace('T', ' ', $v);
}

/**
 * Baut aus Start-Datetime und einer End-Uhrzeit (H:i) das ends_at.
 * Liegt die Endzeit am/vor dem Start, gilt sie als am Folgetag (Übernacht-Schicht).
 * Leere Uhrzeit → null.
 */
function slot_end_from_time(string $start, string $endTime): ?string
{
    $endTime = trim($endTime);
    if ($endTime === '') return null;
    $endTime = substr($endTime, 0, 5);   // "H:i"
    $day     = substr($start, 0, 10);
    $startHm = substr($start, 0, 16);    // "Y-m-d H:i"
    $end     = $day . ' ' . $endTime;
    if (strcmp($end, $startHm) <= 0) {   // Ende ≤ Start → Folgetag
        $ts = strtotime($day . ' ' . $endTime . ' +1 day');
        if ($ts) $end = date('Y-m-d H:i', $ts);
    }
    return $end;
}

/** "Fr 18.07. · 14:00–18:00", "… · ab 14:00" bei offenem Ende, bzw. ohne Uhrzeit. Übernacht-Ende mit „(+1)". */
function fmt_slot(string $start, ?string $end): string
{
    $s = dt($start);
    if (!$s) return h($start);
    $out = WD[(int)$s->format('N') - 1] . ' ' . $s->format('d.m.Y');
    if ($s->format('H:i') !== '00:00') {
        $open = is_open_end($end);
        $out .= ' · ' . ($open ? 'ab ' : '') . $s->format('H:i');
        if (!$open) {
            $e = dt($end);
            if ($e) {
                $out .= '–' . $e->format('H:i');
                $dayDiff = (int)$s->diff($e)->format('%a');
                if ($e->format('Y-m-d') !== $s->format('Y-m-d')) $out .= ' (+' . max(1, $dayDiff) . ')';
            }
        }
    }
    return $out;
}

/** kurze Form für Spaltenköpfe: "Fr 18.07.\n14:00" */
function fmt_slot_short(string $start): array
{
    $s = dt($start);
    if (!$s) return [$start, ''];
    $day = WD[(int)$s->format('N') - 1] . ' ' . $s->format('d.m.');
    $time = $s->format('H:i') !== '00:00' ? $s->format('H:i') : '';
    return [$day, $time];
}

function fmt_date(string $d): string
{
    $x = dt($d);
    return $x ? $x->format('d.m.Y') : h($d);
}

/** Event-Zeitraum als Text: "Fr, 18.07.2025" oder mehrtägig "18.–20.07.2025". */
function fmt_event_range(?string $start, ?string $end): string
{
    $s = dt($start);
    if (!$s) return '';
    $e = dt($end) ?: $s;
    if ($e < $s) $e = $s;
    if ($s->format('Y-m-d') === $e->format('Y-m-d')) return WD[(int)$s->format('N') - 1] . ', ' . $s->format('d.m.Y');
    if ($s->format('Y-m') === $e->format('Y-m'))      return $s->format('d.') . '–' . $e->format('d.m.Y');
    if ($s->format('Y') === $e->format('Y'))          return $s->format('d.m.') . '–' . $e->format('d.m.Y');
    return $s->format('d.m.Y') . ' – ' . $e->format('d.m.Y');
}


/** Höchstzahl der Besitzer:innen pro Event. */
const EVENT_OWNER_MAX = 4;

/** Member-IDs aller Besitzer:innen eines Events (Fallback: created_by bei Alt-Events ohne Eintrag). */
function event_owner_ids(int $eventId): array
{
    if ($eventId <= 0) return [];
    $st = db()->prepare('SELECT member_id FROM event_owners WHERE event_id = ?');
    $st->execute([$eventId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) { // Alt-Event ohne Besitzer-Einträge: Ersteller:in zählt
        $e = event_get($eventId);
        $cid = $e ? (int)($e['created_by'] ?? 0) : 0;
        if ($cid) $ids = [$cid];
    }
    return $ids;
}

/** Komma-getrennte Namen aller Besitzer:innen (für die Anzeige). */
function event_owner_names(array $event): string
{
    $names = [];
    foreach (event_owner_ids((int)($event['id'] ?? 0)) as $mid) {
        $m = member_get($mid);
        if ($m) $names[] = (string)$m['name'];
    }
    return implode(', ', $names);
}

/** Ist die (aktuelle) Person Besitzer:in dieses Events? */
function is_event_owner(array $event, ?array $m = null): bool
{
    $m = $m ?? current_member();
    if (!$m) return false;
    $mid = (int)$m['id'];
    if ((int)($event['created_by'] ?? 0) === $mid) return true; // Ersteller:in immer
    return in_array($mid, event_owner_ids((int)($event['id'] ?? 0)), true);
}

/** Besitzer:innen eines Events setzen (1–4 Mitglieder); created_by zeigt auf die erste. */
function set_event_owners(int $eventId, array $memberIds): void
{
    $clean = [];
    foreach ($memberIds as $mid) { $mid = (int)$mid; if ($mid > 0 && !in_array($mid, $clean, true)) $clean[] = $mid; }
    $clean = array_slice($clean, 0, EVENT_OWNER_MAX);
    if (!$clean) return; // mind. eine Person muss bleiben
    db()->prepare('DELETE FROM event_owners WHERE event_id = ?')->execute([$eventId]);
    $ins = db()->prepare('INSERT OR IGNORE INTO event_owners(event_id, member_id) VALUES(?, ?)');
    foreach ($clean as $mid) $ins->execute([$eventId, $mid]);
    // created_by auf die erste Besitzer:in setzen (für Anzeige & Alt-Abfragen)
    db()->prepare('UPDATE events SET created_by = ? WHERE id = ?')->execute([$clean[0], $eventId]);
}

// ---------------------------------------------------------------------------
// Daten-Helfer
// ---------------------------------------------------------------------------
/** Pronomen als zwei feste Dropdowns (vor/nach „/"), Kombis wie „er/dey" möglich. */
function pronoun_first_options(): array  { return ['sie', 'er', 'they', 'dey']; }
function pronoun_second_options(): array { return ['ihr', 'ihm', 'them', 'dem', 'dey']; }

/** Prüft eine kombinierte Pronomen-Angabe „erstes/zweites". */
function pronoun_valid(string $p): bool
{
    $parts = explode('/', $p, 2);
    return count($parts) === 2
        && in_array($parts[0], pronoun_first_options(), true)
        && in_array($parts[1], pronoun_second_options(), true);
}

function members_all(bool $includeInactive = false): array
{
    $sql = 'SELECT * FROM members';
    if (!$includeInactive) $sql .= ' WHERE active = 1';
    $sql .= ' ORDER BY sort, name COLLATE NOCASE';
    return db()->query($sql)->fetchAll();
}

function member_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM members WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    return $m ?: null;
}

function event_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    return $e ?: null;
}

/**
 * SQL-Bedingung für member-seitige Abfragen: Entwürfe (draft=1) sind nur für die
 * anlegende Person sichtbar, alles andere für alle. $p = Tabellen-Präfix, z. B. 'e.'.
 */
function visible_drafts_sql(string $p = '', bool $events = false): string
{
    $cm = current_member();
    $id = $cm ? (int)$cm['id'] : 0;
    $cond = "{$p}draft = 0 OR {$p}created_by = {$id}";
    // Bei Events sind Entwürfe für ALLE Besitzer:innen sichtbar (nicht nur Ersteller:in)
    if ($events) $cond .= " OR {$p}id IN (SELECT event_id FROM event_owners WHERE member_id = {$id})";
    return "($cond)";
}

/** Darf die aktuelle Person diesen Entwurf sehen? (Sitzungen: nur anlegende Person) */
function can_see_draft(array $row): bool
{
    if ((int)($row['draft'] ?? 0) === 0) return true;
    $cm = current_member();
    return $cm && (int)($row['created_by'] ?? 0) === (int)$cm['id'];
}

/** Darf die aktuelle Person diesen Event-Entwurf sehen? (jede:r Besitzer:in) */
function can_see_event_draft(array $event): bool
{
    if ((int)($event['draft'] ?? 0) === 0) return true;
    return is_event_owner($event);
}

// ---------------------------------------------------------------------------
// „Props" – anonyme Anerkennung zwischen Mitgliedern (Mitglieder-Tab)
// Jede:r vergibt KUDOS_PER_MONTH pro Kalendermonat, höchstens 1 je Empfänger:in.
// Empfänger:innen sehen nur eine kleine (anonyme) Dashboard-Nachricht. Wer wem etwas
// gab, sieht AUSSCHLIESSLICH die Admin-Rolle in den Scores (kudos_admin_overview).
// ---------------------------------------------------------------------------
const KUDOS_PER_MONTH = 2;

/** Aktueller Vergabemonat 'YYYY-MM' (Kontingent-Fenster). */
function kudos_month(): string { return date('Y-m'); }

/** Wie viele Props hat die Person diesen Monat schon vergeben? */
function kudos_given_this_month(int $memberId): int
{
    if ($memberId <= 0) return 0;
    $s = db()->prepare('SELECT COUNT(*) FROM kudos WHERE from_id = ? AND ym = ?');
    $s->execute([$memberId, kudos_month()]);
    return (int)$s->fetchColumn();
}

/** Verbleibendes Monatskontingent (0…KUDOS_PER_MONTH). */
function kudos_quota_left(int $memberId): int
{
    return max(0, KUDOS_PER_MONTH - kudos_given_this_month($memberId));
}

/**
 * Längste Serie aufeinanderfolgender Monate, in denen diese Person ihr VOLLES Props-Kontingent
 * verschenkt hat (für „Großzügig").
 *
 * Bewusst in PHP statt in SQL: „aufeinanderfolgend" über einen Jahreswechsel hinweg ist mit
 * Textvergleichen auf 'YYYY-MM' eine Falle (2025-12 → 2026-01 ist keine Nachbarschaft, die sich
 * durch Zeichenkettenvergleich ergibt). Mit Monatszahlen (Jahr*12 + Monat) ist es eine Subtraktion.
 *
 * Admin-Korrekturen (from_id NULL) haben keinen Absender und können hier gar nicht auftauchen –
 * gezählt wird also nur, was Menschen wirklich verschenkt haben.
 */
function kudos_full_month_streak(int $memberId): int
{
    if ($memberId <= 0) return 0;
    try {
        // Die Schwelle steht ABSICHTLICH direkt im SQL statt als gebundener Wert: PDO bindet sie
        // als Text, und SQLite sortiert Zahlen grundsätzlich VOR Text – „COUNT(*) >= '2'" wäre
        // damit immer falsch, ohne dass irgendetwas warnt. In einem HAVING über COUNT(*) gibt es
        // keine Spalte, deren Typ den Parameter zurückverwandeln würde. KUDOS_PER_MONTH ist eine
        // Zahl-Konstante aus dem Code, hier kommt also nichts von außen ins SQL.
        $s = db()->prepare('SELECT ym FROM kudos WHERE from_id = ? GROUP BY ym
                            HAVING COUNT(*) >= ' . (int)KUDOS_PER_MONTH . ' ORDER BY ym');
        $s->execute([$memberId]);
        $monate = array_column($s->fetchAll(), 'ym');
    } catch (\Throwable $e) { return 0; }

    $zahl = static function (string $ym): ?int {          // 'YYYY-MM' -> fortlaufende Monatsnummer
        if (!preg_match('/^(\d{4})-(\d{2})$/', trim($ym), $t)) return null;
        $mon = (int)$t[2];
        if ($mon < 1 || $mon > 12) return null;
        return (int)$t[1] * 12 + $mon;
    };
    $best = 0; $lauf = 0; $vorher = null;
    foreach ($monate as $ym) {
        $n = $zahl((string)$ym);
        if ($n === null) continue;                        // kaputter Wert bricht die Serie nicht ab
        $lauf = ($vorher !== null && $n === $vorher + 1) ? $lauf + 1 : 1;
        $vorher = $n;
        if ($lauf > $best) $best = $lauf;
    }
    return $best;
}

/** IDs, denen $fromId diesen Monat schon Props gab (für die Karten-Anzeige „schon gegeben"). */
function kudos_given_ids(int $fromId): array
{
    if ($fromId <= 0) return [];
    $s = db()->prepare('SELECT to_id FROM kudos WHERE from_id = ? AND ym = ?');
    $s->execute([$fromId, kudos_month()]);
    return array_map('intval', array_column($s->fetchAll(), 'to_id'));
}

/** Darf $fromId der Person $toId gerade Props geben? (nicht sich selbst, aktives Mitglied, Kontingent frei, diesen Monat noch nicht an sie). */
function kudos_can_give(int $fromId, int $toId): bool
{
    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) return false;
    $to = member_get($toId);
    if (!$to || (int)($to['active'] ?? 1) === 0) return false;
    if (kudos_quota_left($fromId) <= 0) return false;
    $s = db()->prepare('SELECT 1 FROM kudos WHERE from_id = ? AND to_id = ? AND ym = ?');
    $s->execute([$fromId, $toId, kudos_month()]);
    return !$s->fetchColumn();
}

/** Vergibt Props (validiert). Sendet der Empfänger:in eine anonyme kleine Dashboard-Nachricht. */
function kudos_give(int $fromId, int $toId): bool
{
    if (!kudos_can_give($fromId, $toId)) return false;
    try {
        db()->prepare('INSERT INTO kudos(from_id, to_id, ym) VALUES(?,?,?)')->execute([$fromId, $toId, kudos_month()]);
    } catch (\Throwable $e) {
        return false; // UNIQUE-Kollision (Doppelklick/Race) → gilt als „schon gegeben"
    }
    // KEIN Dashboard-Hinweis: die Empfänger:in sieht beim NÄCHSTEN App-Öffnen nur einen Toast (kudos_take_unseen).
    // Zusätzlich optional ein Push – aber nur, wenn die Person ihn AKTIV eingeschaltet hat (dm_kudos ist opt-in, ab Werk aus).
    try {
        if (notify_pref($toId, 'dm_kudos')['push']) {
            push_send($toId, 'Props 🙌', 'Jemand hat dir Props gegeben — schön, dass deine Arbeit gesehen wird!', app_url('profil.php'));
        }
    } catch (\Throwable $e) { /* Push darf die Vergabe nie brechen */ }
    return true;
}

/**
 * ADMIN-Werkzeug (nur Admin-Rolle, die Aufruf-Seite muss das prüfen): Gesamtzahl der erhaltenen Props
 * auf genau $count setzen (0 = alle entfernen) – das Gegenstück zu streak_admin_set().
 * MEHR Props (auch +1) sind für die Empfänger:in NICHT von echten Props zu unterscheiden: Toast beim
 * nächsten Öffnen + Push (falls eingeschaltet). Sie kommen vom „System" statt von einer Person
 * (from_id NULL) und laufen nicht gegen ein Monatskontingent.
 * WENIGER Props passiert still (keine Mitteilung); dabei werden ZUERST System-Zeilen entfernt, damit
 * echte Vergaben von Mitgliedern so lange wie möglich erhalten bleiben.
 */
function kudos_admin_set(int $memberId, int $count): bool
{
    if ($memberId <= 0 || $count < 0 || !member_get($memberId)) return false;
    $cur = kudos_received_total($memberId);
    if ($count === $cur) return true;
    if ($count > $cur) {
        $add = $count - $cur;
        // seen_at bleibt NULL → die Person bekommt den normalen „Du hast Props bekommen"-Toast
        $st = db()->prepare('INSERT INTO kudos(from_id, to_id, ym) VALUES(NULL, ?, ?)');
        for ($i = 0; $i < $add; $i++) $st->execute([$memberId, kudos_month()]);
        try { // Push wie bei einer echten Vergabe (nur wenn die Person ihn eingeschaltet hat)
            if (notify_pref($memberId, 'dm_kudos')['push']) {
                push_send($memberId, 'Props 🙌', $add === 1
                    ? 'Jemand hat dir Props gegeben — schön, dass deine Arbeit gesehen wird!'
                    : 'Du hast ' . $add . ' Props bekommen — schön, dass deine Arbeit gesehen wird!', app_url('profil.php'));
            }
        } catch (\Throwable $e) {}
        return true;
    }
    $remove = $cur - $count;
    // Erst anonyme Admin-Zeilen (neueste zuerst), dann echte Vergaben – beides über eine sortierte Auswahl
    $s = db()->prepare('SELECT id FROM kudos WHERE to_id = ?
        ORDER BY (from_id IS NOT NULL) ASC, id DESC LIMIT ?');
    $s->execute([$memberId, $remove]);
    $ids = array_map('intval', array_column($s->fetchAll(), 'id'));
    if (!$ids) return true;
    db()->exec('DELETE FROM kudos WHERE id IN (' . implode(',', $ids) . ')');
    return true;
}

/** Ungesehene erhaltene Props zählen UND als gesehen markieren (Toast beim nächsten Öffnen). Idempotent. */
function kudos_take_unseen(int $memberId): int
{
    if ($memberId <= 0) return 0;
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM kudos WHERE to_id = ? AND seen_at IS NULL');
        $s->execute([$memberId]);
        $n = (int)$s->fetchColumn();
        if ($n > 0) db()->prepare("UPDATE kudos SET seen_at = datetime('now','localtime') WHERE to_id = ? AND seen_at IS NULL")->execute([$memberId]);
        return $n;
    } catch (\Throwable $e) { return 0; }
}

/** Wie viele Props hat die Person insgesamt bekommen? (Anzeige NUR im eigenen Profil, sonst privat.) */
function kudos_received_total(int $memberId): int
{
    if ($memberId <= 0) return 0;
    $s = db()->prepare('SELECT COUNT(*) FROM kudos WHERE to_id = ?');
    $s->execute([$memberId]);
    return (int)$s->fetchColumn();
}

/**
 * VERTRAULICHE Admin-Auswertung: wer wie viele Props bekam, samt Geber:innen.
 * NUR für die Admin-Rolle bestimmt (die Aufruf-Seite muss das prüfen) – hier werden bewusst Namen offengelegt.
 * Rückgabe: [ ['id','name','count','givers'=>[['name','count'], …]], … ] absteigend nach count.
 */
function kudos_admin_overview(): array
{
    // LEFT JOIN: Admin-Zahlenkorrekturen haben KEINE Absender:in (from_id NULL) und zählen trotzdem mit
    $rows = db()->query('SELECT k.to_id, mt.name AS to_name, mf.name AS from_name
        FROM kudos k JOIN members mt ON mt.id = k.to_id LEFT JOIN members mf ON mf.id = k.from_id')->fetchAll();
    $agg = [];
    foreach ($rows as $r) {
        $tid = (int)$r['to_id'];
        if (!isset($agg[$tid])) $agg[$tid] = ['id' => $tid, 'name' => (string)$r['to_name'], 'count' => 0, 'g' => []];
        $agg[$tid]['count']++;
        $fn = $r['from_name'] !== null ? (string)$r['from_name'] : 'System';
        $agg[$tid]['g'][$fn] = ($agg[$tid]['g'][$fn] ?? 0) + 1;
    }
    $out = [];
    foreach ($agg as $a) {
        arsort($a['g']);
        $out[] = ['id' => $a['id'], 'name' => $a['name'], 'count' => $a['count'],
            'givers' => array_map(fn($n, $c) => ['name' => $n, 'count' => $c], array_keys($a['g']), array_values($a['g']))];
    }
    usort($out, fn($x, $y) => ($y['count'] <=> $x['count']) ?: strcasecmp($x['name'], $y['name']));
    return $out;
}

// ---------------------------------------------------------------------------
// Dashboard-Nachrichten (kurze Hinweise auf dem Dashboard, optional per Mail)
// ---------------------------------------------------------------------------
/**
 * Hinterlässt eine Nachricht auf dem Dashboard einer Person; optional zusätzlich per E-Mail.
 * $type = Kategorie aus notify_types() (dm_*) – steuert, ob die Person dafür Push bekommt.
 */
function dm_send(int $recipientId, string $senderName, string $body, ?int $eventId, bool $sendMail, bool $announce = false, string $type = 'dm_vorsitz', string $link = ''): bool
{
    $body = trim($body);
    if ($recipientId <= 0 || $body === '') return false;
    // Empfänger:in muss (noch) in der Stammliste existieren – sonst verletzt der INSERT den Fremdschlüssel
    // (z. B. wenn die Melder:in eines Tickets inzwischen aus der Stammliste entfernt wurde).
    $exists = db()->prepare('SELECT 1 FROM members WHERE id = ?');
    $exists->execute([$recipientId]);
    if (!$exists->fetchColumn()) return false;
    $link = dm_safe_link($link); // nur interne Seite.php[?#…] als „Ansehen"-Ziel zulassen
    db()->prepare('INSERT INTO dashboard_messages(recipient_id, sender_name, body, event_id, link, is_announce) VALUES(?,?,?,?,?,?)')
        ->execute([$recipientId, $senderName, $body, $eventId, $link, $announce ? 1 : 0]);
    // Zusätzlich als Web Push aufs Gerät (falls abonniert UND Kategorie aktiv); Fehler brechen nie den Versand
    $pushed = 0;
    if (notify_pref($recipientId, $type)['push']) {
        $pushed = push_send($recipientId,
            ($announce ? 'Ankündigung' : 'Nachricht') . ($senderName !== '' ? ' von ' . $senderName : ''),
            $body, app_url($link !== '' ? $link : 'dashboard.php'));
    }
    // Dasselbe Auffangnetz wie in notify_deliver(): Bei einer Pflicht-Erinnerung, deren Mail die
    // Person per Push ersetzt hat („push_opt", z. B. neuer Umlaufbeschluss), muss die Mail doch
    // raus, wenn der Push auf KEINEM Gerät ankam – sonst bliebe nur die Dashboard-Nachricht.
    if (!$sendMail && $pushed === 0 && (notify_types()[$type]['mail'] ?? 'none') === 'push_opt') {
        $sendMail = true;
    }
    if ($sendMail) {
        $m = db()->prepare('SELECT name, email, notify_email FROM members WHERE id=?'); $m->execute([$recipientId]); $row = $m->fetch();
        if ($row && member_mail($row) !== '') {
            $kopf = ($announce ? 'Ankündigung' : 'Nachricht') . ($senderName !== '' ? ' von ' . $senderName : '') . ':';
            mail_tpl_send('dm', member_mail($row), [
                '{{VORNAME}}' => first_name((string)$row['name']),
                '{{KOPF}}' => $kopf,
                '{{NACHRICHT}}' => $body,
                '{{LINK}}' => app_url('dashboard.php'),
            ]);
        }
    }
    return true;
}

/**
 * Stellt Spitzenklasse-Mitgliedern einmalig eine Glückwunsch-Dashboard-Nachricht zu
 * (über die bestehende dm_send-Mechanik). Der Merker `spitze_greeted` verhindert
 * Wiederholungen; beim Verlust der Spitzenklasse wird er zurückgesetzt, sodass die
 * Nachricht beim Wiedererlangen erneut kommt. Idempotent – gefahrlos je Dashboard-Aufruf.
 * Rückgabe: true, wenn auf diesem Aufruf NEU gratuliert wurde (Übergang „wird Spitzenklasse") –
 * darauf hin wird einmalig der Royal-Modus aktiviert, damit man ihn gleich sieht.
 */
function spitze_congratulate(int $memberId, bool $isSpitze): bool
{
    if ($memberId <= 0) return false;
    $st = db()->prepare('SELECT spitze_greeted FROM members WHERE id = ?');
    $st->execute([$memberId]);
    $val = $st->fetchColumn();
    if ($val === false) return false; // Mitglied existiert nicht
    $greeted = (int)$val;
    if ($isSpitze && $greeted === 0) {
        dm_send($memberId, 'Eventscore 👑',
            'Glückwunsch – du gehörst gerade zur Spitzenklasse! 👑 Das bekommst du, weil du bei den Events so großartig mitgeholfen hast. Danke, dass du so toll bist – mach weiter so! Als kleines Dankeschön ist für dich der exklusive „Royal"-Farbmodus freigeschaltet: einfach oben auf das Glühbirnen-Symbol tippen.',
            null, false, true, 'dm_score');
        db()->prepare('UPDATE members SET spitze_greeted = 1 WHERE id = ?')->execute([$memberId]);
        return true;
    }
    if (!$isSpitze && $greeted === 1) {
        db()->prepare('UPDATE members SET spitze_greeted = 0 WHERE id = ?')->execute([$memberId]);
    }
    return false;
}

/** Ungelesene Dashboard-Nachrichten einer Person (neueste zuerst). */
function dm_unread_for(int $memberId): array
{
    $st = db()->prepare('SELECT * FROM dashboard_messages WHERE recipient_id=? AND read_at IS NULL ORDER BY created_at DESC, id DESC');
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** Markiert eine Nachricht als gelesen – nur die eigene. */
function dm_mark_read(int $id, int $memberId): void
{
    db()->prepare("UPDATE dashboard_messages SET read_at = datetime('now','localtime') WHERE id=? AND recipient_id=?")->execute([$id, $memberId]);
}

/** Erlaubt als „Ansehen"-Ziel nur ein internes [admin/]Seite.php[?…][#…] (kein Open-Redirect: keine Schemata/Hosts/absolute Pfade). */
function dm_safe_link(string $link): string
{
    $link = trim($link);
    // Delimiter ~ (NICHT #), weil das Muster selbst ein # in der Zeichenklasse [?#] enthält.
    // Genau EIN optionales „admin/" als Präfix (wörtlich, kein allgemeiner Pfad) – für Hinweise,
    // die auf eine Verwaltungsseite führen. Alles andere bleibt ausgeschlossen.
    return preg_match('~^(admin/)?[a-z0-9_\-]+\.php([?#][^\s]*)?$~i', $link) ? $link : '';
}

/** Sprungziel einer EIGENEN Dashboard-Nachricht (für „Ansehen") – leer, wenn keins/kein Zugriff. */
function dm_link_of(int $id, int $memberId): string
{
    if ($id <= 0 || $memberId <= 0) return '';
    $st = db()->prepare('SELECT link FROM dashboard_messages WHERE id=? AND recipient_id=?');
    $st->execute([$id, $memberId]);
    return dm_safe_link((string)$st->fetchColumn());
}

/** Markiert alle ungelesenen eigenen Dashboard-Nachrichten mit GENAU diesem Sprungziel als gelesen –
 *  damit ein Hinweis verschwindet, sobald man die Fundstelle direkt aufruft (z. B. per Push-Klick). */
function dm_mark_read_for_link(int $memberId, string $link): void
{
    $link = dm_safe_link($link);
    if ($memberId <= 0 || $link === '') return;
    db()->prepare("UPDATE dashboard_messages SET read_at = datetime('now','localtime')
                   WHERE recipient_id = ? AND read_at IS NULL AND link = ?")->execute([$memberId, $link]);
}

/** Mitglieder, die zu einem Event abgestimmt haben ODER eingeteilt sind – [id => name]. */
function event_message_recipients(int $eventId): array
{
    $st = db()->prepare(
        "SELECT DISTINCT m.id, m.name FROM members m
         WHERE m.active = 1 AND (
            EXISTS (SELECT 1 FROM responses r JOIN event_slots s ON s.id=r.slot_id WHERE s.event_id=:e AND r.member_id=m.id)
            OR EXISTS (SELECT 1 FROM assignments a JOIN event_slots s ON s.id=a.slot_id WHERE s.event_id=:e AND a.member_id=m.id)
         )
         ORDER BY m.sort, m.name COLLATE NOCASE"
    );
    $st->execute([':e' => $eventId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = $r['name'];
    return $out;
}

// ---------------------------------------------------------------------------
// Mitglieder-Infoseite: README-Mitgliederteil + vom Vorsitz gepflegte Infos/Dateien
// ---------------------------------------------------------------------------
/** Verzeichnis für hochgeladene Dateien (liegt unter data/, ist also nicht direkt aus dem Web abrufbar). */
function upload_dir(): string { return __DIR__ . '/data/uploads'; }

/**
 * Schneidet ein Hauptkapitel („## …") aus der README als Markdown heraus.
 * Die Anleitung wird bewusst rollenweise ausgeliefert: Mitglieder sehen auf der Infos-Seite
 * nur ihren Teil, die Vollfassung liegt unter admin/help.php (Vorsitz & Admin).
 */
function readme_section(string $heading): string
{
    $md = @file_get_contents(__DIR__ . '/README.md');
    if ($md === false) return '';
    $start = strpos($md, $heading);
    if ($start === false) return '';
    $next = strpos($md, "\n## ", $start + 5); // nächste Hauptüberschrift
    $sec = $next === false ? substr($md, $start) : substr($md, $start, $next - $start);
    return rtrim(preg_replace('/\n-{3,}\s*$/', '', rtrim($sec))); // abschließende Trennlinie entfernen
}

/** Mitglieder-Abschnitt (Infos-Seite, für alle). */
function readme_member_section(): string { return readme_section('## Für alle Mitglieder'); }

/** Sekretariats-Abschnitt (Infos-Seite, nur Sekretariat/Vorsitz/Admin). */
function readme_secretariat_section(): string { return readme_section('## Für das Sekretariat'); }

/** Pat:innenprogramm-Abschnitt (Infos-Seite, nur Verantwortliche – sonst kämen sie nicht dran). */
function readme_pat_section(): string { return readme_section('## Für das Pat:innenprogramm'); }

/** Alle Info-Einträge (mit angehängten Dateien), in gepflegter Reihenfolge. */
/**
 * Wichtige Informationen inkl. Dateien.
 * $forReferat = null → ALLE Einträge (Verwaltung); sonst nur die für alle sichtbaren
 * plus die des genannten Referats (Mitglieder-Ansicht; '' = Mitglied ohne Referat).
 */
function infos_all(?string $forReferat = null): array
{
    if ($forReferat === null) {
        $infos = db()->query('SELECT * FROM infos ORDER BY sort, id')->fetchAll();
    } else {
        $st = db()->prepare("SELECT * FROM infos WHERE referat = '' OR referat = ? ORDER BY sort, id");
        $st->execute([$forReferat]);
        $infos = $st->fetchAll();
    }
    if (!$infos) return [];
    $files = db()->query('SELECT * FROM info_files ORDER BY orig_name COLLATE NOCASE')->fetchAll();
    $byInfo = [];
    foreach ($files as $f) $byInfo[(int)$f['info_id']][] = $f;
    foreach ($infos as &$i) $i['files'] = $byInfo[(int)$i['id']] ?? [];
    return $infos;
}

/** Einen Info-Eintrag holen (oder null). */
function info_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM infos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Eine Wichtige Information in der Reihenfolge verschieben ($dir < 0 = nach oben, sonst nach unten).
 * Normalisiert die sort-Werte dabei auf 0..n-1 – robust gegen doppelte/0-Werte aus dem Zahlenfeld.
 */
function move_info(int $id, int $dir): void
{
    $ids = array_map(fn($r) => (int)$r['id'], db()->query('SELECT id FROM infos ORDER BY sort, id')->fetchAll());
    $pos = array_search($id, $ids, true);
    if ($pos === false) return;
    $swap = $pos + ($dir < 0 ? -1 : 1);
    if ($swap < 0 || $swap >= count($ids)) return; // schon am Rand
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    $st = db()->prepare('UPDATE infos SET sort = ? WHERE id = ?');
    foreach ($ids as $i => $iid) $st->execute([$i, $iid]);
}

/* ---------------------------------------------------------------------------
 * Wegweiser – das Verzeichnis aller Dienste, Zugänge und Ansprechpartner:innen.
 * Gepflegt wird es in der Verwaltung, gelesen von allen (Namens-Dropdown, direkt
 * unter „Wichtige Infos & Anleitung"). Ein Eintrag = ein Ziel, das mehrere Wege
 * haben darf: Web-Adresse, E-Mail, Telefon.
 * ------------------------------------------------------------------------- */

/** Rubrik-Name fürs Anzeigen (leer = „Sonstiges"). */
function wegweiser_group_label(string $grp): string
{
    $g = trim($grp);
    return $g === '' ? 'Sonstiges' : $g;
}

/**
 * Eine eingegebene Web-Adresse prüfen und aufräumen.
 * Gibt die nutzbare Adresse zurück oder '' – NIE ungeprüft durchreichen: „javascript:"
 * und „data:" wären sonst ein Einfallstor, weil der Wegweiser fremde Links anzeigt.
 * Fehlt das Schema (beispiel.de), wird https:// ergänzt.
 */
function wegweiser_url_ok(string $roh): string
{
    $u = trim($roh);
    if ($u === '') return '';
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $u)) {
        $schema = strtolower(substr($u, 0, (int)strpos($u, ':')));
        if (!in_array($schema, ['http', 'https'], true)) return ''; // mailto/tel haben eigene Felder
    } else {
        if (!str_contains($u, '.') || str_contains($u, ' ')) return ''; // sieht nicht nach Adresse aus
        $u = 'https://' . $u;
    }
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : '';
}

/** Nur der Hostname einer Adresse, ohne „www." – als unaufdringliche Zweitzeile. */
function wegweiser_host(string $url): string
{
    $h = strtolower((string)parse_url($url, PHP_URL_HOST));
    return $h !== '' && str_starts_with($h, 'www.') ? substr($h, 4) : $h;
}

/** Auswahlliste fürs Symbol: Tabler-Name (ohne „ti-“) => Beschriftung. */
function wegweiser_icons(): array
{
    return [
        'world-www'           => 'Webseite',
        'brand-teams'         => 'Teams',
        'cloud'               => 'Cloud / Nextcloud',
        'school'              => 'Uni / OLAT',
        'mail'                => 'Mail',
        'at'                  => 'Postfach / Adresse',
        'phone'               => 'Telefon',
        'users'               => 'Gremium / Gruppe',
        'building-bank'       => 'Behörde / Verwaltung',
        'building-community'  => 'Fachschaft',
        'heart-handshake'     => 'Beratung / Soziales',
        'scale'               => 'Recht & Satzung',
        'briefcase'           => 'Partner / Firma',
        'file-text'           => 'Formular / Dokument',
        'folder'              => 'Ablage',
        'shield-lock'         => 'Zugang / Passwörter',
        'calendar-event'      => 'Termine',
        'printer'             => 'Druck & Gestaltung',
        'shopping-cart'       => 'Bestellen & Einkauf',
        'ticket'              => 'Semesterticket',
        'video'               => 'Videokonferenz',
        'message-circle'      => 'Chat',
        'brand-instagram'     => 'Instagram',
        'brand-facebook'      => 'Facebook',
        'brand-whatsapp'      => 'WhatsApp',
        'brand-youtube'       => 'YouTube',
    ];
}

/**
 * Symbol eines Eintrags als fertiger Tabler-Klassenname.
 * Ohne eigene Wahl wird aus der Adresse geraten – die häufigen Dienste treffen wir
 * damit, alles andere bekommt schlicht das Weltkugel-, Mail- oder Telefon-Symbol.
 */
function wegweiser_icon(array $e): string
{
    $icon = trim((string)($e['icon'] ?? ''));
    if ($icon !== '' && isset(wegweiser_icons()[$icon])) return 'ti-' . $icon;

    $u = strtolower(trim((string)($e['url'] ?? '')));
    if ($u !== '') {
        $map = [
            'teams.microsoft' => 'brand-teams', 'sharepoint' => 'brand-teams', 'office.com' => 'brand-office',
            'outlook' => 'mail', 'nextcloud' => 'cloud', 'olat' => 'school', 'moodle' => 'school',
            'ilias' => 'school', 'uni-' => 'school', 'hochschule' => 'school', 'campus' => 'school',
            'instagram' => 'brand-instagram', 'facebook' => 'brand-facebook',
            'whatsapp' => 'brand-whatsapp', 'youtube' => 'brand-youtube', 'zoom.us' => 'video',
            'studierendenwerk' => 'heart-handshake', 'github' => 'brand-github',
        ];
        foreach ($map as $needle => $ic) if (str_contains($u, $needle)) return 'ti-' . $ic;
        return 'ti-world-www';
    }
    if (trim((string)($e['email'] ?? '')) !== '') return 'ti-at';
    if (trim((string)($e['phone'] ?? '')) !== '') return 'ti-phone';
    return 'ti-bookmark';
}

/** Wählbare Fassung einer Telefonnummer – dieselbe Aufbereitung wie im Profil (phone_dial). */
function wegweiser_tel(string $roh): string
{
    return phone_dial($roh);
}

/** Hauptziel eines Eintrags (Web vor Mail vor Telefon); '' = reiner Merkposten ohne Link. */
function wegweiser_href(array $e): string
{
    $u = trim((string)($e['url'] ?? ''));
    if ($u !== '') return $u;
    $m = trim((string)($e['email'] ?? ''));
    if ($m !== '') return 'mailto:' . $m;
    $t = wegweiser_tel((string)($e['phone'] ?? ''));
    return $t !== '' ? 'tel:' . $t : '';
}

/**
 * Die Sichtbarkeits-Angabe eines Eintrags als Liste von Referaten; [] heißt „für alle".
 * Gespeichert wird mit „|" getrennt – ein einzelner Name (das alte Format) bleibt damit
 * gültig, ohne dass irgendetwas umgeschrieben werden müsste.
 */
function wegweiser_referate(string $feld): array
{
    return array_values(array_filter(array_map('trim', explode('|', $feld)), fn($r) => $r !== ''));
}

/** Umgekehrter Weg: eine Referats-Auswahl in die Speicherform bringen (leer = für alle). */
function wegweiser_referate_feld(array $liste): string
{
    $sauber = [];
    foreach ($liste as $r) {
        $r = trim((string)$r);
        if ($r !== '' && !in_array($r, $sauber, true) && in_array($r, referate_list(), true)) $sauber[] = $r;
    }
    return implode('|', $sauber);
}

/** Sieht jemand mit diesem Referat den Eintrag? $referat = null (Verwaltung/Technik) sieht alles. */
function wegweiser_visible(string $feld, ?string $referat): bool
{
    $liste = wegweiser_referate($feld);
    if (!$liste || $referat === null) return true;
    return in_array(trim($referat), $liste, true);
}

/**
 * Alle Wegweiser-Einträge, nach Rubrik gebündelt: [Rubrik => [Eintrag, …]].
 * $forReferat = null → alles (Verwaltung); sonst nur die für alle sichtbaren plus
 * die des genannten Referats (Mitglieder-Ansicht).
 * Gefiltert wird in PHP statt in SQL: Ein Eintrag kann MEHRERE Referate nennen, und eine
 * LIKE-Suche auf der Liste wäre hier fehleranfälliger als ein sauberer Vergleich.
 */
function wegweiser_all(?string $forReferat = null): array
{
    $rows = db()->query('SELECT * FROM wegweiser ORDER BY grp COLLATE NOCASE, sort, id')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if (!wegweiser_visible((string)$r['referat'], $forReferat)) continue;
        $out[wegweiser_group_label((string)$r['grp'])][] = $r;
    }
    return $out;
}

/**
 * Darf jemand diesen Eintrag ändern? Vorsitz/Admin dürfen alles, alle anderen genau das,
 * was sie selbst angelegt haben. Einträge aus der Zeit vor den Mitglieder-Einträgen (ohne
 * created_by) gehören damit automatisch nur der Verwaltung.
 */
function wegweiser_can_edit(?array $e, ?array $me): bool
{
    if (!$e) return false;
    if (can_admin()) return true;
    $mid = (int)($me['id'] ?? 0);
    return $mid > 0 && (int)($e['created_by'] ?? 0) === $mid;
}

/** Die selbst angelegten Einträge eines Mitglieds (für die eigene Liste im Wegweiser). */
function wegweiser_mine(int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare('SELECT * FROM wegweiser WHERE created_by = ? ORDER BY grp COLLATE NOCASE, sort, id');
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/**
 * Einen Eintrag anlegen ($id = 0) oder ändern – die EINE Stelle, an der geprüft und geschrieben
 * wird. Mitglieder-Seite und Verwaltung nutzen sie gemeinsam, damit die Regeln nicht auseinander
 * laufen. Die Reihenfolge darf nur die Verwaltung setzen (sie wirkt für alle).
 * Rückgabe: ['ok' => bool, 'id' => int, 'msg' => string, 'typ' => 'success'|'error']
 */
function wegweiser_save(int $id, array $post, int $memberId): array
{
    $title = trim((string)($post['title'] ?? ''));
    $urlIn = trim((string)($post['url'] ?? ''));
    $url   = wegweiser_url_ok($urlIn);
    $mail  = trim((string)($post['email'] ?? ''));
    $phone = trim((string)($post['phone'] ?? ''));
    $note  = mb_substr(trim((string)($post['note'] ?? '')), 0, 500);
    $grp   = mb_substr(trim((string)($post['grp'] ?? '')), 0, 60);
    $icon  = trim((string)($post['icon'] ?? ''));
    if (!isset(wegweiser_icons()[$icon])) $icon = '';                       // leer = automatisch raten
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $mail = '';
    $ref   = wegweiser_referate_feld((array)($post['referat'] ?? []));

    if ($title === '') return ['ok' => false, 'id' => $id, 'msg' => 'Bitte einen Namen angeben.', 'typ' => 'error'];
    if ($url === '' && $mail === '' && $phone === '') {
        return ['ok' => false, 'id' => $id, 'msg' => 'Bitte mindestens eine Adresse angeben – Web, E-Mail oder Telefon.', 'typ' => 'error'];
    }
    // Eine unbrauchbare Web-Adresse still zu schlucken wäre gemein: lieber sagen, was los ist.
    $hinweis = ($urlIn !== '' && $url === '') ? ' Die Web-Adresse war unbrauchbar und wurde nicht übernommen.' : '';

    if ($id <= 0) {
        $sort = can_admin() ? (int)($post['sort'] ?? 0) : 0;
        db()->prepare('INSERT INTO wegweiser(title, url, email, phone, note, grp, icon, referat, sort, created_by) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$title, $url, $mail, $phone, $note, $grp, $icon, $ref, $sort, $memberId ?: null]);
        return ['ok' => true, 'id' => (int)db()->lastInsertId(), 'msg' => 'Eintrag angelegt.' . $hinweis, 'typ' => $hinweis ? 'error' : 'success'];
    }
    if (can_admin()) {
        db()->prepare('UPDATE wegweiser SET title=?, url=?, email=?, phone=?, note=?, grp=?, icon=?, referat=?, sort=? WHERE id=?')
            ->execute([$title, $url, $mail, $phone, $note, $grp, $icon, $ref, (int)($post['sort'] ?? 0), $id]);
    } else { // ohne sort – die Reihenfolge gehört der Verwaltung
        db()->prepare('UPDATE wegweiser SET title=?, url=?, email=?, phone=?, note=?, grp=?, icon=?, referat=? WHERE id=?')
            ->execute([$title, $url, $mail, $phone, $note, $grp, $icon, $ref, $id]);
    }
    return ['ok' => true, 'id' => $id, 'msg' => 'Gespeichert.' . $hinweis, 'typ' => $hinweis ? 'error' : 'success'];
}

/** Sichtbarkeits-Auswahl: ein Chip je Referat, MEHRERE anklickbar (nichts gewählt = für alle). */
function wegweiser_referat_wahl(string $current): void
{
    $gewaehlt = wegweiser_referate($current);
    echo '<div class="fb-kinds">';
    foreach (referate_list() as $r) {
        echo '<label class="fb-kind"><input type="checkbox" name="referat[]" value="' . h($r) . '"'
           . (in_array($r, $gewaehlt, true) ? ' checked' : '') . '><span>' . h($r) . '</span></label>';
    }
    echo '</div><p class="small muted" style="margin:.4rem 0 0">Nichts ausgewählt = <strong>für alle Mitglieder sichtbar</strong>. Mehrere Referate sind möglich – der Eintrag erscheint dann bei allen ausgewählten.</p>';
}

/** Die gewählten Referate als Plaketten (oder „Alle"). */
function wegweiser_referat_pills(string $feld): string
{
    $liste = wegweiser_referate($feld);
    if (!$liste) return '<span class="muted small">Alle</span>';
    return implode(' ', array_map(fn($r) => '<span class="pill pill-info" style="font-size:.72rem">' . h($r) . '</span>', $liste));
}

/** Symbol-Auswahl („Automatisch" plus die Liste aus wegweiser_icons()). */
function wegweiser_icon_select(string $current): void
{
    echo '<select name="icon" id="icon"><option value=""' . ($current === '' ? ' selected' : '') . '>Automatisch (aus der Adresse)</option>';
    foreach (wegweiser_icons() as $key => $label) {
        echo '<option value="' . h($key) . '"' . ($current === $key ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    echo '</select>';
}

/**
 * Das Eingabeformular – gleich fürs Anlegen und fürs Bearbeiten, gleich in der Verwaltung und
 * auf der Mitglieder-Seite. $zurueck ist das Ziel des „Fertig"-Knopfes beim Bearbeiten.
 */
function wegweiser_form(?array $e, string $zurueck): void
{
    $v = fn(string $k) => h((string)($e[$k] ?? ''));
    $neu = $e === null;
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $neu ? 'ww_add' : 'ww_save' ?>">
      <?php if (!$neu): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
      <label for="title">Name</label>
      <input type="text" name="title" id="title" value="<?= $v('title') ?>" placeholder="z. B. Nextcloud, Studierendenwerk, Frau Müller (Prüfungsamt)" required>
      <label for="url">Web-Adresse (optional)</label>
      <input type="text" name="url" id="url" value="<?= $v('url') ?>" placeholder="beispiel.de/planer – https:// wird ergänzt">
      <div class="field-row">
        <div><label for="email">E-Mail (optional)</label><input type="email" name="email" id="email" value="<?= $v('email') ?>" placeholder="name@beispiel.de"></div>
        <div><label for="phone">Telefon (optional)</label><input type="text" name="phone" id="phone" value="<?= $v('phone') ?>" placeholder="06341 280-00000"></div>
      </div>
      <label for="note">Wofür ist das gut? (optional)</label>
      <textarea name="note" id="note" rows="2" maxlength="500" placeholder="Ein, zwei Sätze – was findet man dort, oder wofür ist die Person zuständig?"><?= $v('note') ?></textarea>
      <div class="field-row">
        <div><label for="grp">Rubrik</label>
          <input type="text" name="grp" id="grp" value="<?= $v('grp') ?>" list="wwGroups" maxlength="60" placeholder="z. B. Unsere Dienste">
          <datalist id="wwGroups"><?php foreach (wegweiser_groups() as $g): ?><option value="<?= h($g) ?>"></option><?php endforeach; ?></datalist>
        </div>
        <div><label for="icon">Symbol</label><?php wegweiser_icon_select((string)($e['icon'] ?? '')); ?></div>
        <?php if (can_admin()): // die Reihenfolge wirkt für alle – die stellt nur die Verwaltung ?>
          <div style="flex:0 1 120px"><label for="sort">Reihenfolge</label><input type="number" name="sort" id="sort" value="<?= (int)($e['sort'] ?? 0) ?>"></div>
        <?php endif; ?>
      </div>
      <label style="margin-top:.7rem">Sichtbar für</label>
      <?php wegweiser_referat_wahl((string)($e['referat'] ?? '')); ?>
      <div class="btn-row" style="margin-top:.9rem">
        <button class="btn" type="submit"><i class="ti <?= $neu ? 'ti-plus' : 'ti-device-floppy' ?>"></i> <?= $neu ? 'Anlegen' : 'Speichern' ?></button>
        <?php if (!$neu): ?><a class="btn secondary" href="<?= h($zurueck) ?>">Fertig</a><?php endif; ?>
      </div>
    </form>
    <?php
}

/** Einen Wegweiser-Eintrag holen (oder null). */
function wegweiser_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM wegweiser WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Vorhandene Rubriken (für die Vorschlagsliste im Formular). */
function wegweiser_groups(): array
{
    $rows = db()->query("SELECT DISTINCT grp FROM wegweiser WHERE grp <> '' ORDER BY grp COLLATE NOCASE")->fetchAll();
    return array_map(fn($r) => (string)$r['grp'], $rows);
}

/**
 * Einen Eintrag INNERHALB seiner Rubrik verschieben ($dir < 0 = nach oben).
 * Wie bei den Infos werden die sort-Werte dabei auf 0..n-1 normalisiert.
 */
function move_wegweiser(int $id, int $dir): void
{
    $e = wegweiser_get($id);
    if (!$e) return;
    $st = db()->prepare('SELECT id FROM wegweiser WHERE grp = ? ORDER BY sort, id');
    $st->execute([(string)$e['grp']]);
    $ids = array_map(fn($r) => (int)$r['id'], $st->fetchAll());
    $pos = array_search($id, $ids, true);
    if ($pos === false) return;
    $swap = $pos + ($dir < 0 ? -1 : 1);
    if ($swap < 0 || $swap >= count($ids)) return; // schon am Rand
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    $up = db()->prepare('UPDATE wegweiser SET sort = ? WHERE id = ?');
    foreach ($ids as $i => $wid) $up->execute([$i, $wid]);
}

/** Menschlich lesbare Dateigröße. */
function human_filesize(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    $u = ['KB', 'MB', 'GB']; $i = -1; $n = $bytes;
    do { $n /= 1024; $i++; } while ($n >= 1024 && $i < count($u) - 1);
    return number_format($n, $n >= 10 ? 0 : 1, ',', '') . ' ' . $u[$i];
}

/** PHP-Größenangabe wie „50M"/„1G" in Bytes umrechnen. */
function ini_bytes(string $val): int
{
    $val = trim($val);
    if ($val === '') return 0;
    $num = (int)$val;
    switch (strtolower(substr($val, -1))) {
        case 'g': $num *= 1024; // fallthrough
        case 'm': $num *= 1024; // fallthrough
        case 'k': $num *= 1024;
    }
    return $num;
}

/** Effektives Upload-Limit des Servers pro Datei (Minimum aus upload_max_filesize/post_max_size). */
function php_upload_limit_bytes(): int
{
    $vals = array_filter([
        ini_bytes((string)ini_get('upload_max_filesize')),
        ini_bytes((string)ini_get('post_max_size')),
    ], fn($v) => $v > 0);
    return $vals ? min($vals) : 0;
}

/**
 * Soll das Dashboard auf die Info-Seite hinweisen? Nur wenn das Mitglied sie noch NIE
 * gesehen hat (erster Login) oder es seither NEUE, für die Person sichtbare Einträge gibt.
 * Bewusst kein globaler „geändert"-Merker mehr: Einträge fremder Referate (und bloße
 * Bearbeitungen) lösen keinen Hinweis bei Unbeteiligten aus.
 */
function infos_unseen(array $member): bool
{
    $seen = trim((string)($member['infos_seen_at'] ?? ''));
    if ($seen === '') return true; // noch nie angesehen (z. B. erster Login)
    return infos_new_for($member) !== [];
}

/** Info-Seite für ein Mitglied als „gesehen" markieren. */
function mark_infos_seen(int $memberId): void
{
    db()->prepare("UPDATE members SET infos_seen_at = datetime('now','localtime') WHERE id = ?")->execute([$memberId]);
}

/**
 * Info-Einträge, die seit dem letzten Besuch des Mitglieds NEU dazugekommen sind (zum Benennen).
 * Beim ersten Login (noch nie gesehen) leer – dort greift der generische „bitte einmal ansehen"-Hinweis.
 */
function infos_new_for(array $member): array
{
    $seen = trim((string)($member['infos_seen_at'] ?? ''));
    if ($seen === '') return [];
    // Referats-Einträge anderer Referate nicht benennen – das Mitglied sieht sie ja nicht.
    $st = db()->prepare("SELECT id, title FROM infos WHERE created_at > ? AND (referat = '' OR referat = ?) ORDER BY created_at DESC, id DESC");
    $st->execute([$seen, trim((string)($member['referat'] ?? ''))]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------------
// Abstimmungsgegenstände einer Sitzung
//   Anlegen/Bearbeiten: Sekretariat, Vorsitz, Admin (can_manage_meetings()).
//   Mitglieder lesen sie vor der Sitzung und markieren sie als gelesen (Dashboard-Aufgabe).
//   Datei-Uploads löschen sich nach 40 Tagen selbst (cleanup_vote_item_files via Cron).
// ---------------------------------------------------------------------------

/** Abstimmungsgegenstände einer Sitzung (in Reihenfolge), je inkl. Dateien. */
function vote_items_for(int $meetingId): array
{
    $st = db()->prepare('SELECT * FROM vote_items WHERE meeting_id = ? ORDER BY sort, id');
    $st->execute([$meetingId]);
    $items = $st->fetchAll();
    if (!$items) return [];
    $files = db()->query('SELECT * FROM vote_item_files ORDER BY orig_name COLLATE NOCASE')->fetchAll();
    $byItem = [];
    foreach ($files as $f) $byItem[(int)$f['item_id']][] = $f;
    foreach ($items as &$it) $it['files'] = $byItem[(int)$it['id']] ?? [];
    return $items;
}

/** Einzelner Abstimmungsgegenstand (oder null). */
function vote_item_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM vote_items WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Einen Abstimmungsgegenstand innerhalb seiner Sitzung verschieben ($dir < 0 = hoch, sonst runter). */
function move_vote_item(int $id, int $dir): void
{
    $it = vote_item_get($id);
    if (!$it) return;
    $ids = array_map(fn($r) => (int)$r['id'],
        db()->query('SELECT id FROM vote_items WHERE meeting_id = ' . (int)$it['meeting_id'] . ' ORDER BY sort, id')->fetchAll());
    $pos = array_search($id, $ids, true);
    if ($pos === false) return;
    $swap = $pos + ($dir < 0 ? -1 : 1);
    if ($swap < 0 || $swap >= count($ids)) return;
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    $upd = db()->prepare('UPDATE vote_items SET sort = ? WHERE id = ?');
    foreach ($ids as $i => $vid) $upd->execute([$i, $vid]);
}

/** Markiert einen Abstimmungsgegenstand für ein Mitglied als gelesen (idempotent). */
function vote_item_mark_read(int $itemId, int $memberId): void
{
    if ($itemId <= 0 || $memberId <= 0) return;
    db()->prepare('INSERT OR IGNORE INTO vote_item_reads(item_id, member_id) VALUES(?,?)')->execute([$itemId, $memberId]);
}

/** IDs der von einem Mitglied bereits gelesenen Abstimmungsgegenstände einer Sitzung (als Menge id=>true). */
function vote_item_read_set(int $meetingId, int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare(
        'SELECT r.item_id FROM vote_item_reads r JOIN vote_items v ON v.id = r.item_id
         WHERE v.meeting_id = ? AND r.member_id = ?'
    );
    $st->execute([$meetingId, $memberId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $iid) $out[(int)$iid] = true;
    return $out;
}

/** Wie viele Mitglieder haben einen Abstimmungsgegenstand gelesen? */
function vote_item_read_count(int $itemId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM vote_item_reads WHERE item_id = ?');
    $st->execute([$itemId]);
    return (int)$st->fetchColumn();
}

/**
 * Ungelesene Abstimmungsgegenstände kommender Sitzungen für ein Mitglied (Dashboard-Aufgabe).
 * Nur veröffentlichte, nicht abgesagte Sitzungen, deren Tag noch nicht vorbei ist.
 * Rückgabe: [['id','title','meeting_id','meeting_title','starts_at'], …] gruppierbar nach Sitzung.
 */
function vote_items_unread_for_member(int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare(
        "SELECT v.id, v.title, v.meeting_id, m.title AS meeting_title, m.starts_at
         FROM vote_items v
         JOIN meetings m ON m.id = v.meeting_id
         WHERE m.cancelled = 0 AND m.draft = 0 AND date(m.starts_at) >= date('now','localtime')
           AND NOT EXISTS (SELECT 1 FROM vote_item_reads r WHERE r.item_id = v.id AND r.member_id = ?)
         ORDER BY m.starts_at, v.sort, v.id"
    );
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** Eine Datei eines Abstimmungsgegenstands physisch + aus der DB löschen. */
function vote_item_file_delete(int $fileId): void
{
    $st = db()->prepare('SELECT stored_name FROM vote_item_files WHERE id = ?');
    $st->execute([$fileId]);
    if ($sn = $st->fetchColumn()) {
        $p = upload_dir() . '/' . basename((string)$sn);
        if (is_file($p)) @unlink($p);
    }
    db()->prepare('DELETE FROM vote_item_files WHERE id = ?')->execute([$fileId]);
    mirror_forget('vfile', $fileId); // Verweise auf beide Ablagen aufräumen (das Dok dort bleibt)
}

/**
 * Räumt Abstimmungs-Uploads auf, die älter als $days Tage sind (Datei + DB-Zeile).
 * Die Abstimmungsgegenstände selbst (Texte) bleiben erhalten. Gibt die Anzahl gelöschter Dateien zurück.
 */
function cleanup_vote_item_files(int $days = 40): int
{
    $cut = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $st = db()->prepare('SELECT id FROM vote_item_files WHERE created_at < ?');
    $st->execute([$cut]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $fid) vote_item_file_delete((int)$fid);
    return count($ids);
}

// ---------------------------------------------------------------------------
// Bug-Reports (interne Fehler-Tickets mit Kommentaren)
// ---------------------------------------------------------------------------

/** Anzahl offener Bug-Tickets (für die Verwaltungs-Kachel). */
function bug_open_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM bug_reports WHERE status = 'open'")->fetchColumn();
}

/**
 * Bug-Tickets mit Melder-Name und Kommentarzahl. $filter: 'open' | 'done' | 'all'.
 * Sortierung: offen zuerst, dann nach letzter Aktivität (updated_at) absteigend.
 */
function bugs_all(string $filter = 'all'): array
{
    $sql = "SELECT b.*, m.name AS author,
                   (SELECT COUNT(*) FROM bug_comments c WHERE c.bug_id = b.id) AS n_comments
            FROM bug_reports b LEFT JOIN members m ON m.id = b.created_by";
    if ($filter === 'open') $sql .= " WHERE b.status = 'open'";
    elseif ($filter === 'done') $sql .= " WHERE b.status = 'done'";
    $sql .= " ORDER BY (b.status = 'open') DESC, b.updated_at DESC, b.id DESC";
    return db()->query($sql)->fetchAll();
}

/** Bug-Tickets, die ein bestimmtes Mitglied gemeldet hat (offene zuerst, dann neueste Aktivität). */
function bugs_for_member(int $memberId): array
{
    $st = db()->prepare("SELECT id, title, status, created_at, updated_at FROM bug_reports
                         WHERE created_by = ? ORDER BY (status = 'open') DESC, updated_at DESC, id DESC");
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** Ein Bug-Ticket mit Melder-Name (oder null). */
function bug_get(int $id): ?array
{
    $st = db()->prepare("SELECT b.*, m.name AS author FROM bug_reports b LEFT JOIN members m ON m.id = b.created_by WHERE b.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Kommentare eines Tickets (älteste zuerst) inkl. Verfasser-Name. */
function bug_comments(int $bugId): array
{
    $st = db()->prepare("SELECT c.*, m.name AS author FROM bug_comments c LEFT JOIN members m ON m.id = c.member_id WHERE c.bug_id = ? ORDER BY c.created_at, c.id");
    $st->execute([$bugId]);
    return $st->fetchAll();
}

/** Aktive Admins (= Technik) – Empfänger der Bug-Benachrichtigungen. Nutzt dieselbe Logik wie is_technik(). */
function technik_members(): array
{
    return array_values(array_filter(members_all(), 'is_technik'));
}

/** Technik-Referat über ein Bug-Ereignis benachrichtigen (Dashboard-Nachricht). $except = handelnde Person auslassen. */
function bug_notify_technik(string $body, ?int $except = null): void
{
    foreach (technik_members() as $t) {
        $tid = (int)$t['id'];
        if ($except !== null && $tid === $except) continue;
        dm_send($tid, 'Bug-Reports', $body, null, false, false, 'dm_bugs');
    }
}

/** Melder:in eines Tickets benachrichtigen (Dashboard-Nachricht), außer sie ist selbst die handelnde Person. */
function bug_notify_reporter(int $bugId, string $body, ?int $except = null): void
{
    $b = bug_get($bugId);
    $rid = (int)($b['created_by'] ?? 0);
    if ($rid > 0 && $rid !== $except) dm_send($rid, 'Bug-Reports', $body, null, false, false, 'dm_bugs');
}

// ---------------------------------------------------------------------------
// Feedback (Lob/Idee/Kritik/Frage als Ticket mit Verlauf – wie die Bug-Reports).
// Sichtbar sind Rückmeldungen ausschließlich für die einreichende Person selbst
// und für das Technik-Referat; es gibt bewusst keine app-weite Liste.
// ---------------------------------------------------------------------------

/** Arten der Rückmeldung: Schlüssel => Beschriftung, Symbol und Kurztext fürs Formular. */
function feedback_kinds(): array
{
    return [
        'lob'    => ['label' => 'Lob',    'icon' => 'ti-heart',       'hint' => 'Was gefällt dir?'],
        'idee'   => ['label' => 'Idee',   'icon' => 'ti-bulb',        'hint' => 'Was fehlt dir?'],
        'kritik' => ['label' => 'Kritik', 'icon' => 'ti-mood-sad',    'hint' => 'Was nervt dich?'],
        'frage'  => ['label' => 'Frage',  'icon' => 'ti-help-circle', 'hint' => 'Was ist unklar?'],
    ];
}

/** Art normalisieren – Unbekanntes wird zur „Idee", damit nie eine leere Kategorie entsteht. */
function feedback_kind_ok(string $kind): string
{
    return isset(feedback_kinds()[$kind]) ? $kind : 'idee';
}

/** Bearbeitungsstände: Schlüssel => Beschriftung, Pillen-Klasse und Symbol. */
function feedback_statuses(): array
{
    return [
        'new'      => ['label' => 'Neu',        'pill' => 'pill-info', 'icon' => 'ti-sparkles'],
        'accepted' => ['label' => 'Angenommen', 'pill' => 'pill-warn', 'icon' => 'ti-progress'],
        'done'     => ['label' => 'Umgesetzt',  'pill' => 'pill-ok',   'icon' => 'ti-circle-check'],
        'rejected' => ['label' => 'Abgelehnt',  'pill' => 'pill-bad',  'icon' => 'ti-circle-x'],
    ];
}

/** Anzahl unerledigter Rückmeldungen (neu + angenommen) – für die Verwaltungs-Kachel. */
function feedback_open_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM feedback_items WHERE status IN ('new','accepted')")->fetchColumn();
}

/** Kurzfassung für Listen (die Rückmeldung hat bewusst keinen Titel – das Feld soll schnell auszufüllen sein). */
function feedback_excerpt(string $body, int $max = 90): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    if ($t === '') return '(ohne Text)';
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
}

/**
 * Rückmeldungen mit Name und Kommentarzahl. $filter: 'open' (neu + angenommen), ein
 * Status-Schlüssel, eine Art (Schlüssel aus feedback_kinds()) oder 'all'.
 * Sortierung: Unerledigtes zuerst, dann nach letzter Aktivität.
 */
function feedback_all(string $filter = 'all'): array
{
    $sql = "SELECT f.*, m.name AS author,
                   (SELECT COUNT(*) FROM feedback_comments c WHERE c.feedback_id = f.id) AS n_comments
            FROM feedback_items f LEFT JOIN members m ON m.id = f.created_by";
    $args = [];
    if ($filter === 'open') {
        $sql .= " WHERE f.status IN ('new','accepted')";
    } elseif (isset(feedback_statuses()[$filter])) {
        $sql .= ' WHERE f.status = ?';
        $args[] = $filter;
    } elseif (isset(feedback_kinds()[$filter])) {
        $sql .= ' WHERE f.kind = ?';
        $args[] = $filter;
    }
    $sql .= " ORDER BY (f.status IN ('new','accepted')) DESC, f.updated_at DESC, f.id DESC";
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Rückmeldungen einer Person (Unerledigtes zuerst, dann neueste Aktivität). */
function feedback_for_member(int $memberId): array
{
    $st = db()->prepare("SELECT f.*, (SELECT COUNT(*) FROM feedback_comments c WHERE c.feedback_id = f.id) AS n_comments
                         FROM feedback_items f WHERE f.created_by = ?
                         ORDER BY (f.status IN ('new','accepted')) DESC, f.updated_at DESC, f.id DESC");
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** Eine Rückmeldung samt Name der einreichenden Person (oder null). */
function feedback_get(int $id): ?array
{
    $st = db()->prepare('SELECT f.*, m.name AS author FROM feedback_items f LEFT JOIN members m ON m.id = f.created_by WHERE f.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Neue Rückmeldung anlegen; gibt die neue ID zurück (0 = kein Text). */
function feedback_add(string $kind, string $body, ?int $memberId, string $page = ''): int
{
    $body = trim($body);
    if ($body === '') return 0;
    db()->prepare('INSERT INTO feedback_items(kind, body, page, created_by) VALUES(?,?,?,?)')
        ->execute([feedback_kind_ok($kind), $body, trim($page), $memberId]);
    return (int)db()->lastInsertId();
}

/** Verlauf einer Rückmeldung (älteste zuerst) inkl. Verfasser-Name. */
function feedback_comments(int $feedbackId): array
{
    $st = db()->prepare('SELECT c.*, m.name AS author FROM feedback_comments c LEFT JOIN members m ON m.id = c.member_id
                         WHERE c.feedback_id = ? ORDER BY c.created_at, c.id');
    $st->execute([$feedbackId]);
    return $st->fetchAll();
}

/** Antwort anhängen und die Rückmeldung als zuletzt bearbeitet markieren. */
function feedback_comment_add(int $feedbackId, ?int $memberId, string $body): bool
{
    $body = trim($body);
    if ($body === '') return false;
    db()->prepare('INSERT INTO feedback_comments(feedback_id, member_id, body) VALUES(?,?,?)')->execute([$feedbackId, $memberId, $body]);
    feedback_touch($feedbackId);
    return true;
}

/** Bearbeitungsstand setzen (unbekannte Werte werden zu 'new'). */
function feedback_set_status(int $id, string $status): void
{
    if (!isset(feedback_statuses()[$status])) $status = 'new';
    db()->prepare("UPDATE feedback_items SET status = ?, updated_at = datetime('now','localtime') WHERE id = ?")->execute([$status, $id]);
}

/** Zeitstempel der letzten Aktivität nachziehen (Sortierung der Listen). */
function feedback_touch(int $id): void
{
    db()->prepare("UPDATE feedback_items SET updated_at = datetime('now','localtime') WHERE id = ?")->execute([$id]);
}

/** Rückmeldung samt Verlauf löschen (Kommentare per Cascade). */
function feedback_delete(int $id): void
{
    db()->prepare('DELETE FROM feedback_items WHERE id = ?')->execute([$id]);
}

/** Technik-Referat über ein Feedback-Ereignis benachrichtigen. $except = handelnde Person auslassen. */
function feedback_notify_technik(string $body, int $feedbackId, ?int $except = null): void
{
    foreach (technik_members() as $t) {
        $tid = (int)$t['id'];
        if ($except !== null && $tid === $except) continue;
        dm_send($tid, 'Feedback', $body, null, false, false, 'dm_feedback', 'admin/feedback.php?id=' . $feedbackId);
    }
}

/** Einreichende Person benachrichtigen (außer sie ist selbst die handelnde Person). */
function feedback_notify_author(int $feedbackId, string $body, ?int $except = null): void
{
    $f = feedback_get($feedbackId);
    $rid = (int)($f['created_by'] ?? 0);
    if ($rid > 0 && $rid !== $except) {
        dm_send($rid, 'Feedback', $body, null, false, false, 'dm_feedback', 'feedback.php?id=' . $feedbackId);
    }
}

function event_slots(int $eventId): array
{
    $st = db()->prepare('SELECT * FROM event_slots WHERE event_id = ? ORDER BY sort, starts_at');
    $st->execute([$eventId]);
    return $st->fetchAll();
}

/**
 * Anzeige-Reihenfolge der Schichten: chronologisch, aber gleichnamige Schichten zusammenhalten.
 * Pro Tag bestimmt die früheste Schicht eines Namens den Gruppen-Anker; die Gruppen werden nach
 * diesem frühesten Zeitpunkt geordnet (chronologisch), innerhalb der Gruppe nach Zeit. So: frühestes
 * Event des Tages, dann alle gleichnamigen Schichten davon, dann das chronologisch nächst-früheste.
 */
function slots_grouped(array $slots): array
{
    $anchor = [];
    foreach ($slots as $s) {
        $key = substr((string)$s['starts_at'], 0, 10) . '|' . strtolower(trim((string)$s['label']));
        $stt = (string)$s['starts_at'];
        if (!isset($anchor[$key]) || strcmp($stt, $anchor[$key]) < 0) $anchor[$key] = $stt;
    }
    usort($slots, function ($a, $b) use ($anchor) {
        $da = substr((string)$a['starts_at'], 0, 10);
        $db = substr((string)$b['starts_at'], 0, 10);
        if ($da !== $db) return strcmp($da, $db);
        $ka = $da . '|' . strtolower(trim((string)$a['label']));
        $kb = $db . '|' . strtolower(trim((string)$b['label']));
        $cmp = strcmp($anchor[$ka], $anchor[$kb]);                       // Gruppen chronologisch nach frühester Schicht
        if ($cmp !== 0) return $cmp;
        $cmp = strcasecmp(trim((string)$a['label']), trim((string)$b['label'])); // Tiebreaker bei gleichem Anker
        if ($cmp !== 0) return $cmp;
        return strcmp((string)$a['starts_at'], (string)$b['starts_at']); // innerhalb der Gruppe nach Zeit
    });
    return $slots;
}

/** Antworten eines Events: [slot_id][member_id] => row */
function event_responses(int $eventId): array
{
    $st = db()->prepare(
        'SELECT r.* FROM responses r
         JOIN event_slots s ON s.id = r.slot_id
         WHERE s.event_id = ?'
    );
    $st->execute([$eventId]);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[(int)$r['slot_id']][(int)$r['member_id']] = $r;
    }
    return $map;
}

/** Ist das Mitglied an dem Tag des Slots als abwesend eingetragen? */
function member_absent_on(int $memberId, string $slotStart): bool
{
    $day = substr($slotStart, 0, 10);
    $st = db()->prepare(
        'SELECT 1 FROM absences WHERE member_id = ? AND ? BETWEEN starts_at AND ends_at LIMIT 1'
    );
    $st->execute([$memberId, $day]);
    return (bool)$st->fetchColumn();
}

/** Alle Abwesenheiten als [date => [memberId,...]] für Kalenderanzeige eines Monats */
function absences_in_range(string $from, string $to): array
{
    $st = db()->prepare(
        'SELECT a.*, m.name FROM absences a JOIN members m ON m.id = a.member_id
         WHERE a.ends_at >= ? AND a.starts_at <= ? ORDER BY m.name'
    );
    $st->execute([$from, $to]);
    return $st->fetchAll();
}

/** Als „extrem wichtig" markierte Events, deren Zeitraum sich mit [from,to] (Y-m-d) überschneidet. */
function important_events_overlapping(string $from, string $to): array
{
    $st = db()->prepare(
        "SELECT title, starts_at, ends_at FROM events
         WHERE important = 1 AND draft = 0 AND starts_at IS NOT NULL AND starts_at <> ''
           AND starts_at <= ? AND COALESCE(NULLIF(ends_at, ''), starts_at) >= ?
         ORDER BY starts_at"
    );
    $st->execute([$to, $from]);
    return $st->fetchAll();
}

/** Alle veröffentlichten Events, deren Zeitraum sich mit [from,to] überschneidet (für Abwesenheits-Hinweise). */
function events_overlapping(string $from, string $to): array
{
    $st = db()->prepare(
        "SELECT title, starts_at, ends_at FROM events
         WHERE draft = 0 AND starts_at IS NOT NULL AND starts_at <> ''
           AND starts_at <= ? AND COALESCE(NULLIF(ends_at, ''), starts_at) >= ?
         ORDER BY starts_at"
    );
    $st->execute([$to, $from]);
    return $st->fetchAll();
}

/**
 * Informiert den Vorsitz per Dashboard-Nachricht (dismissbar, ohne Mail) über eine neu eingetragene
 * Abwesenheit – inkl. Hinweis, ob sie sich mit Events überschneidet.
 * $exceptId = wer die Abwesenheit eingetragen hat (bekommt keine Selbst-Info).
 */
function notify_vorsitz_absence(int $memberId, string $from, string $to, string $reason, int $exceptId = 0, array $autoMeetings = []): void
{
    $name = (string)(db()->query('SELECT name FROM members WHERE id = ' . (int)$memberId)->fetchColumn() ?: 'Ein Mitglied');
    $range = fmt_date($from) . ($to !== $from ? ' – ' . fmt_date($to) : '');
    $body = $name . ' hat eine Abwesenheit eingetragen: ' . $range;
    if (trim($reason) !== '') $body .= ' (' . trim($reason) . ')';
    $events = events_overlapping($from, $to);
    if ($events) {
        $titles = array_map(fn($e) => '„' . (string)$e['title'] . '" (' . fmt_event_range($e['starts_at'], $e['ends_at']) . ')', array_slice($events, 0, 5));
        $body .= "\n\n⚠ Überschneidet sich mit " . count($events) . ' Event' . (count($events) > 1 ? 's' : '') . ': '
              . implode(', ', $titles) . (count($events) > 5 ? ' …' : '') . '.';
    } else {
        $body .= "\n\nKeine Überschneidung mit Events.";
    }
    if ($autoMeetings) {
        $body .= "\n\n" . 'In der Zeit liegen Sitzungen – ' . $name . ' wurde automatisch abgemeldet (entschuldigt): ' . implode(', ', $autoMeetings) . '.';
    }
    foreach (db()->query("SELECT id FROM members WHERE active = 1 AND role = 'vorsitz'")->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        if ((int)$vid === $exceptId) continue; // keine Selbst-Benachrichtigung
        dm_send((int)$vid, '', $body, null, false, false, 'dm_sitzung');
    }
}

/** Frist überschritten? (Abstimmung endet mit Ablauf des Frist-Tages) */
function deadline_passed(?string $deadline): bool
{
    if (!$deadline) return false;
    return $deadline < date('Y-m-d');
}

/** Event gesperrt = manuell abgeschlossen ODER Frist abgelaufen. */
function event_locked(array $event): bool
{
    return !empty($event['closed']) || deadline_passed($event['deadline'] ?? null);
}

// Dringlichkeits-Schwellen für Fristen (Resttage). Zentral, damit Dashboard und Kalender
// dieselben Grenzen verwenden – hier ändern wirkt überall.
const DEADLINE_URGENT_DAYS = 1; // ≤ → kritisch (rote Pille)
const DEADLINE_SOON_DAYS   = 3; // ≤ → bald fällig (gelbe Pille)

/** Frist kritisch nah? (≤ DEADLINE_URGENT_DAYS Resttage) */
function deadline_is_urgent(int $daysLeft): bool { return $daysLeft <= DEADLINE_URGENT_DAYS; }

/** Frist bald fällig? (≤ DEADLINE_SOON_DAYS Resttage) */
function deadline_is_soon(int $daysLeft): bool { return $daysLeft <= DEADLINE_SOON_DAYS; }

/** 3-stufige Pillen-Klasse rein nach Resttagen: rot (kritisch) ≤1, gelb (bald) ≤3, sonst neutral. */
function deadline_pill_class(int $daysLeft): string
{
    if (deadline_is_urgent($daysLeft)) return 'pill-bad';
    if (deadline_is_soon($daysLeft))   return 'pill-warn';
    return 'pill-info';
}

/** Aktive Mitglieder mit E-Mail, die für das Event noch gar nicht abgestimmt haben. */
function members_without_vote(int $eventId): array
{
    $st = db()->prepare(
        "SELECT m.* FROM members m
         WHERE m.active = 1 AND m.email <> ''
           AND NOT EXISTS (
             SELECT 1 FROM responses r JOIN event_slots s ON s.id = r.slot_id
             WHERE s.event_id = ? AND r.member_id = m.id
           )
         ORDER BY m.name COLLATE NOCASE"
    );
    $st->execute([$eventId]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------------
// Arbeitsplan / Einteilung
// ---------------------------------------------------------------------------
/** Darf die aktuelle Person den Arbeitsplan dieses Events machen? (jede:r Besitzer:in oder Admin) */
function can_manage_event(array $event): bool
{
    if (is_owner() || can_admin()) return true;
    return is_event_owner($event);
}

/** Maximale Zahl abfragbarer Zusatz-Merkmale pro Event. */
const EVENT_ATTR_MAX = 3;

/** Spaltennamen-Helfer für Merkmal $i (1..3). */
function attr_label_col(int $i): string { return $i <= 1 ? 'req_label' : 'req_label' . (int)$i; }
function attr_count_col(int $i): string { return $i <= 1 ? 'req_count' : 'req_count' . (int)$i; }
function attr_has_col(int $i): string   { return $i <= 1 ? 'has_attr'  : 'has_attr'  . (int)$i; }

/**
 * Aktive Merkmale eines Events: [idx => Label] – nur für gesetzte (nicht-leere) Labels.
 * z. B. [1 => 'Führerschein', 2 => 'Erste-Hilfe']. Leer = Event hat keine Zusatz-Abfrage.
 */
function event_attr_labels(array $event): array
{
    $out = [];
    for ($i = 1; $i <= EVENT_ATTR_MAX; $i++) {
        $l = trim((string)($event[attr_label_col($i)] ?? ''));
        if ($l !== '') $out[$i] = $l;
    }
    return $out;
}

/**
 * Mitglieder mit gesetztem Merkmal $idx (1..3) für ein Event: [member_id => true].
 * Standard idx=1 – abwärtskompatibel zu früheren Aufrufen mit nur einem Merkmal.
 */
function event_attr_holders(int $eventId, int $idx = 1): array
{
    $col = attr_has_col(max(1, min(EVENT_ATTR_MAX, $idx)));
    $st = db()->prepare("SELECT member_id FROM event_member_attr WHERE event_id = ? AND $col = 1");
    $st->execute([$eventId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['member_id']] = true;
    return $out;
}

/** Alle Merkmal-Träger:innen eines Events: [idx => [member_id => true]] für die aktiven Merkmale. */
function event_attr_holders_all(array $event): array
{
    $out = [];
    foreach (event_attr_labels($event) as $i => $_) $out[$i] = event_attr_holders((int)($event['id'] ?? 0), $i);
    return $out;
}

/** Einteilung eines Events: [slot_id => [member_id, ...]] */
function event_assignments(int $eventId): array
{
    $st = db()->prepare(
        'SELECT a.slot_id, a.member_id FROM assignments a
         JOIN event_slots s ON s.id = a.slot_id WHERE s.event_id = ?'
    );
    $st->execute([$eventId]);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[(int)$r['slot_id']][] = (int)$r['member_id'];
    }
    return $map;
}

/** Einteilung mit Benachrichtigt-Status: [slot_id => [member_id => bool notified]] */
function event_assign_status(int $eventId): array
{
    $st = db()->prepare(
        'SELECT a.slot_id, a.member_id, a.notified_at FROM assignments a
         JOIN event_slots s ON s.id = a.slot_id WHERE s.event_id = ?'
    );
    $st->execute([$eventId]);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[(int)$r['slot_id']][(int)$r['member_id']] = !empty($r['notified_at']);
    }
    return $map;
}

/** Minuten als „3 h 30 min" bzw. „45 min" formatieren. */
function fmt_duration_min(int $min): string
{
    if ($min <= 0) return '–';
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h === 0) return $m . ' min';
    if ($m === 0) return $h . ' h';
    return $h . ' h ' . $m . ' min';
}

/**
 * Fairness-/Verteilungs-Statistik der Einteilung eines Events.
 * Pro eingeteiltem Mitglied: Anzahl Schichten und summierte Arbeitszeit (Minuten).
 * Rückgabe sortiert nach Schichtanzahl (absteigend), dann Arbeitszeit.
 * ['rows'=>[['member_id','name','shifts','minutes','open_end','shift_dev','min_dev'], …],
 *  'count','total_shifts','total_minutes','avg_shifts','avg_minutes','max_shifts','max_minutes','open_end_total']
 */
function event_fairness_stats(array $event): array
{
    $eid    = (int)$event['id'];
    $slots  = event_slots($eid);
    $assign = event_assign_status($eid);

    // Ja-Stimmen je Mitglied (für wie viele Schichten hat sich die Person eingetragen)
    $yesCount = [];
    foreach (event_responses($eid) as $perMember) {
        foreach ($perMember as $mid => $r) {
            if (($r['status'] ?? '') === 'yes') $yesCount[(int)$mid] = ($yesCount[(int)$mid] ?? 0) + 1;
        }
    }

    $byMember = [];
    $openEndTotal = 0;
    foreach ($slots as $s) {
        $sid   = (int)$s['id'];
        $start = strtotime((string)$s['starts_at']);
        $end   = !empty($s['ends_at']) ? strtotime((string)$s['ends_at']) : null;
        $mins  = ($start && $end && $end > $start) ? (int)round(($end - $start) / 60) : 0;
        foreach (array_keys($assign[$sid] ?? []) as $mid) {
            $mid = (int)$mid;
            if (!isset($byMember[$mid])) $byMember[$mid] = ['shifts' => 0, 'minutes' => 0, 'open_end' => 0, 'slots' => []];
            $byMember[$mid]['shifts']++;
            $byMember[$mid]['minutes'] += $mins;
            if ($mins === 0) { $byMember[$mid]['open_end']++; $openEndTotal++; }
            $byMember[$mid]['slots'][] = [
                'starts_at' => (string)$s['starts_at'],
                'ends_at'   => (string)($s['ends_at'] ?? ''),
                'label'     => (string)($s['label'] ?? ''),
                'location'  => (string)($s['location'] ?? ''),
                'minutes'   => $mins,
            ];
        }
    }

    $names = [];
    foreach (members_all(true) as $m) $names[(int)$m['id']] = (string)$m['name'];

    $count       = count($byMember);
    $totalShifts = array_sum(array_column($byMember, 'shifts'));
    $totalMin    = array_sum(array_column($byMember, 'minutes'));
    $avgShifts   = $count ? $totalShifts / $count : 0.0;
    $avgMin      = $count ? $totalMin / $count : 0.0;

    $rows = [];
    foreach ($byMember as $mid => $d) {
        $slotsM = $d['slots'];
        usort($slotsM, fn($a, $b) => strcmp((string)$a['starts_at'], (string)$b['starts_at']));
        $rows[] = [
            'member_id' => $mid,
            'name'      => $names[$mid] ?? ('#' . $mid),
            'shifts'    => $d['shifts'],
            'minutes'   => $d['minutes'],
            'open_end'  => $d['open_end'],
            'signed_up' => $yesCount[$mid] ?? 0,
            'shift_dev' => $d['shifts'] - $avgShifts,
            'min_dev'   => $d['minutes'] - $avgMin,
            'slots'     => $slotsM,
        ];
    }
    // Sortierung nach Arbeitszeit (Minuten) zuerst, dann Anzahl Schichten, dann Name.
    usort($rows, fn($a, $b) => ($b['minutes'] <=> $a['minutes'])
        ?: ($b['shifts'] <=> $a['shifts'])
        ?: strcasecmp($a['name'], $b['name']));

    return [
        'rows'           => $rows,
        'count'          => $count,
        'total_shifts'   => $totalShifts,
        'total_minutes'  => $totalMin,
        'avg_shifts'     => $avgShifts,
        'avg_minutes'    => $avgMin,
        'max_shifts'     => $rows ? max(array_column($rows, 'shifts')) : 0,
        'max_minutes'    => $rows ? max(array_column($rows, 'minutes')) : 0,
        'open_end_total' => $openEndTotal,
    ];
}

function clear_event_assignments(int $eventId): void
{
    db()->prepare('DELETE FROM assignments WHERE slot_id IN (SELECT id FROM event_slots WHERE event_id = ?)')
        ->execute([$eventId]);
}

/** Wie clear_event_assignments, aber FIXIERTE (locked) und selbst EINGESPRUNGENE (self_claimed)
 *  Zuteilungen bleiben erhalten – Eingesprungene haben oft nie „Ja" gestimmt und könnten nach
 *  einem Neu-Mischen sonst nie wieder automatisch eingeteilt werden (stiller Datenverlust). */
function clear_unlocked_assignments(int $eventId): void
{
    db()->prepare('DELETE FROM assignments WHERE locked = 0 AND self_claimed = 0 AND slot_id IN (SELECT id FROM event_slots WHERE event_id = ?)')
        ->execute([$eventId]);
}

/** Fixierte Zuteilungen eines Events als [slot_id => [member_id, ...]]. */
function locked_assignments(int $eventId): array
{
    $st = db()->prepare(
        'SELECT a.slot_id, a.member_id FROM assignments a
         JOIN event_slots s ON s.id = a.slot_id
         WHERE s.event_id = ? AND a.locked = 1'
    );
    $st->execute([$eventId]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[(int)$r['slot_id']][] = (int)$r['member_id'];
    return $map;
}

/** Selbst über „kurzfristig einspringen" eingetragene Zuteilungen eines Events: [slot_id => [member_id => true]]. */
function self_claimed_assignments(int $eventId): array
{
    $st = db()->prepare(
        'SELECT a.slot_id, a.member_id FROM assignments a
         JOIN event_slots s ON s.id = a.slot_id
         WHERE s.event_id = ? AND a.self_claimed = 1'
    );
    $st->execute([$eventId]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[(int)$r['slot_id']][(int)$r['member_id']] = true;
    return $map;
}

/** Eine einzelne Zuteilung fixieren/lösen. Fixiert nur, wenn die Person der Schicht zugeteilt ist. */
function toggle_assignment_lock(int $slotId, int $memberId): void
{
    $st = db()->prepare('SELECT locked FROM assignments WHERE slot_id = ? AND member_id = ?');
    $st->execute([$slotId, $memberId]);
    $cur = $st->fetchColumn();
    if ($cur === false) return; // nicht zugeteilt → nichts zu fixieren
    db()->prepare('UPDATE assignments SET locked = ? WHERE slot_id = ? AND member_id = ?')
        ->execute([$cur ? 0 : 1, $slotId, $memberId]);
}

/**
 * Externe Helfer eines Slots als Namensliste (im Feld external_helpers eine Person pro Zeile gespeichert).
 * Externe stimmen nicht ab und sind keine Mitglieder – sie zählen aber als „eingeteilt" mit.
 */
function slot_external_names(array $slot): array
{
    $raw = (string)($slot['external_helpers'] ?? '');
    if (trim($raw) === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn($n) => $n !== ''));
}

/** Zeitintervall eines Slots als [startTs, endTs] (ohne Endzeit: +2 h). */
function slot_interval(array $slot): array
{
    $start = strtotime((string)$slot['starts_at']);
    $end = !empty($slot['ends_at']) ? strtotime((string)$slot['ends_at']) : 0;
    if ($end <= $start) $end = $start + 2 * 3600;
    return [$start, $end];
}

/** Eventscore-Punkte für eine eingeteilte Schicht: +1, lange Schicht (>2 h) +2. Zentral, damit Auto-Einteilung und Score-Berechnung nie auseinanderlaufen. */
function slot_points(array $slot): int
{
    $iv = slot_interval($slot);
    return ($iv[1] - $iv[0]) > 2 * 3600 ? 2 : 1;
}

/** Maximale Eventscore-Punkte pro Event: wichtige Events 5, sonst 4. */
function event_score_cap(array $event): int
{
    return !empty($event['important']) ? 5 : 4;
}

function intervals_overlap(array $a, array $b): bool
{
    return $a[0] < $b[1] && $b[0] < $a[1];
}

/**
 * Faire, rücksichtsvolle Zufallseinteilung für ein ganzes Event.
 * Pool: nur „Ja" – „Vielleicht" gilt nach Fristende als „Nein" und wird nicht eingeteilt.
 * Meidet Abwesende und Zeitüberschneidungen, respektiert die Schicht-Obergrenze pro Person
 * und verteilt möglichst gleichmäßig (niedriger Eventscore bevorzugt, aber kein Monopol).
 * Bestehende Einteilung wird vorher geleert – AUSSER fixierte (locked) und kurzfristig selbst
 * eingesprungene (self_claimed) Zuteilungen, die bleiben und zählen als besetzte Plätze mit.
 * Gibt die Zahl der Einteilungen zurück.
 */
/** Helfer-Bedarf eines Events über alle Schichten: needed/got (gedeckelt je Schicht) + Zusagen gesamt. */
function event_help_stats(int $eventId): array
{
    $slots = event_slots($eventId);
    $resp  = event_responses($eventId);
    $needed = $got = $yesTotal = 0;
    foreach ($slots as $s) {
        $t = (int)($s['target_helpers'] ?? 0);
        $eff = max(0, $t - count(slot_external_names($s))); // externe Helfer senken den Mitglieder-Bedarf
        $y = 0;
        foreach ($resp[(int)$s['id']] ?? [] as $r) if ($r['status'] === 'yes') $y++;
        $yesTotal += $y;
        $needed += $eff;
        if ($eff > 0) $got += min($y, $eff);
    }
    return ['needed' => $needed, 'got' => $got, 'yes' => $yesTotal];
}

/* ===================== Schichttauschbörse ===================== *
 * Mitglieder können – nur nach Veröffentlichung des Plans – eine ihnen zugeteilte
 * Schicht zum 1:1-Tausch anbieten. Wer annimmt, gibt eine eigene zugeteilte Schicht her.
 * Das System prüft beide Seiten auf Zeit-Überschneidungen und informiert die Orga.
 * Die Börse schließt standardmäßig 3 Tage vor Event-Start (Orga-einstellbar).
 */
const SWAP_LEAD_DAYS_DEFAULT = 3; // Standard-Vorlauf, wenn nichts eingestellt ist

/** Vorlauf (Tage), ab wann vor Event-Start die Schichtbörse schließt – global einstellbar (Verwaltung). */
function swap_lead_days(): int
{
    $v = setting_get('swap_lead_days', null);
    return $v === null || $v === '' ? SWAP_LEAD_DAYS_DEFAULT : max(0, (int)$v);
}

/** Event-Start als Unix-Zeit: bevorzugt event.starts_at, sonst die früheste Schicht. Null = unbekannt. */
function event_start_ts(array $event): ?int
{
    $s = trim((string)($event['starts_at'] ?? ''));
    if ($s !== '') { $t = strtotime($s); if ($t) return $t; }
    $st = db()->prepare('SELECT MIN(starts_at) FROM event_slots WHERE event_id = ?');
    $st->execute([(int)($event['id'] ?? 0)]);
    $min = $st->fetchColumn();
    return $min ? strtotime((string)$min) : null;
}

/**
 * Ist die Tauschbörse für ein Event offen? Voraussetzung: Plan veröffentlicht und Event nicht abgeschlossen.
 * Dann normalerweise nur bis zum eingestellten Vorlauf vor Start (Standard 3 Tage); hat die Orga die Börse
 * für dieses Event „offen gehalten" (swap_force_open), bleibt sie auch danach/nachträglich offen.
 */
function swap_market_open(array $event): bool
{
    if (empty($event['plan_locked']) || !empty($event['closed'])) return false;
    if (!empty($event['swap_force_open'])) return true; // Orga hält die Börse offen (Vorlauf übersteuert)
    $ts = event_start_ts($event);
    return $ts !== null && ($ts - time()) > swap_lead_days() * 86400;
}

/** Eine einzelne Zuteilungszeile (inkl. locked) oder null. */
function assignment_row(int $slotId, int $memberId): ?array
{
    $st = db()->prepare('SELECT * FROM assignments WHERE slot_id = ? AND member_id = ?');
    $st->execute([$slotId, $memberId]);
    return $st->fetch() ?: null;
}

/** Eine Schichtzeile oder null. */
function event_slot_get(int $slotId): ?array
{
    $st = db()->prepare('SELECT * FROM event_slots WHERE id = ?');
    $st->execute([$slotId]);
    return $st->fetch() ?: null;
}

/** Schichten, denen ein Mitglied in einem Event zugeteilt ist (volle Zeilen inkl. locked), chronologisch. */
function member_assigned_slots(int $eventId, int $memberId): array
{
    $st = db()->prepare(
        'SELECT s.*, a.locked FROM event_slots s
         JOIN assignments a ON a.slot_id = s.id
         WHERE s.event_id = ? AND a.member_id = ?
         ORDER BY s.starts_at'
    );
    $st->execute([$eventId, $memberId]);
    return $st->fetchAll();
}

/** Offene Tausch-Angebote eines Events (mit Schicht-Daten und Name der anbietenden Person), chronologisch.
 *  Defensiv: Angebote, deren anbietende Person die Schicht nicht (mehr) hat – z. B. nach einer erneuten
 *  Arbeitsplan-Einteilung – werden gar nicht erst gelistet. */
function shift_swaps_open(int $eventId): array
{
    $st = db()->prepare(
        "SELECT sw.*, s.starts_at, s.ends_at, s.label, s.location, m.name AS offerer_name
         FROM shift_swaps sw
         JOIN event_slots s ON s.id = sw.slot_id
         JOIN members m ON m.id = sw.offered_by
         WHERE sw.event_id = ? AND sw.status = 'open'
           AND EXISTS (SELECT 1 FROM assignments a WHERE a.slot_id = sw.slot_id AND a.member_id = sw.offered_by AND a.locked = 0)
         ORDER BY s.starts_at, sw.id"
    );
    $st->execute([$eventId]);
    return $st->fetchAll();
}

/**
 * Räumt verwaiste Tausch-Angebote/-Vorschläge auf, nachdem sich der Arbeitsplan geändert hat:
 * Hat die anbietende Person ihre angebotene Schicht nicht mehr (z. B. nach erneuter Einteilung
 * oder weil die Orga sie aus der Schicht genommen hat), wird das Angebot abgebrochen und alle
 * dazu offenen Vorschläge abgelehnt. Ebenso werden Vorschläge abgelehnt, deren hergegebene
 * Schicht die vorschlagende Person nicht mehr besitzt. Gibt die Zahl entfernter Angebote zurück.
 */
function swap_invalidate_orphaned(int $eventId): int
{
    if ($eventId <= 0) return 0;

    // 1) Angebote, deren anbietende Person die Schicht nicht mehr hat → abbrechen (+ Vorschläge ablehnen).
    $orphans = db()->prepare(
        "SELECT sw.id FROM shift_swaps sw
         WHERE sw.event_id = ? AND sw.status = 'open'
           AND NOT EXISTS (SELECT 1 FROM assignments a WHERE a.slot_id = sw.slot_id AND a.member_id = sw.offered_by)"
    );
    $orphans->execute([$eventId]);
    $ids = array_map('intval', $orphans->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $cancelOffer = db()->prepare("UPDATE shift_swaps SET status='cancelled', resolved_at=datetime('now','localtime') WHERE id=?");
        $declineProp = db()->prepare("UPDATE swap_proposals SET status='declined', resolved_at=datetime('now','localtime') WHERE swap_id=? AND status='pending'");
        foreach ($ids as $sid) { $cancelOffer->execute([$sid]); $declineProp->execute([$sid]); }
    }

    // 2) Offene Vorschläge, deren hergegebene Schicht (Y) die vorschlagende Person nicht mehr hat → ablehnen.
    db()->prepare(
        "UPDATE swap_proposals SET status='declined', resolved_at=datetime('now','localtime')
         WHERE status='pending'
           AND swap_id IN (SELECT id FROM shift_swaps WHERE event_id = ?)
           AND NOT EXISTS (SELECT 1 FROM assignments a
                           WHERE a.slot_id = swap_proposals.proposer_slot_id AND a.member_id = swap_proposals.proposer_id)"
    )->execute([$eventId]);

    return count($ids);
}

/**
 * Stellt eine zugeteilte (nicht fixierte) Schicht in die Tauschbörse.
 * $mode: 'swap' = 1:1-Tausch (Gegen-Schicht nötig) · 'giveaway' = einfach abgeben (jede:r kann übernehmen).
 * Gibt ['ok'=>bool,'msg'=>string].
 */
function shift_swap_offer(array $event, int $slotId, int $memberId, string $mode = 'swap'): array
{
    $mode = $mode === 'giveaway' ? 'giveaway' : 'swap';
    if (!swap_market_open($event)) return ['ok' => false, 'msg' => 'Die Tauschbörse für dieses Event ist geschlossen.'];
    $eid = (int)$event['id'];
    $st = db()->prepare(
        'SELECT a.locked FROM assignments a JOIN event_slots s ON s.id = a.slot_id
         WHERE a.slot_id = ? AND a.member_id = ? AND s.event_id = ?'
    );
    $st->execute([$slotId, $memberId, $eid]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'msg' => 'Du bist dieser Schicht nicht zugeteilt.'];
    if (!empty($row['locked'])) return ['ok' => false, 'msg' => 'Diese Schicht wurde von der Orga fixiert und kann nicht getauscht/abgegeben werden.'];
    $ex = db()->prepare("SELECT 1 FROM shift_swaps WHERE slot_id = ? AND offered_by = ? AND status = 'open'");
    $ex->execute([$slotId, $memberId]);
    if ($ex->fetchColumn()) return ['ok' => false, 'msg' => 'Diese Schicht steht bereits in der Tauschbörse.'];
    db()->prepare("INSERT INTO shift_swaps(event_id, slot_id, offered_by, status, mode) VALUES(?,?,?,'open',?)")
        ->execute([$eid, $slotId, $memberId, $mode]);
    return ['ok' => true, 'msg' => $mode === 'giveaway'
        ? 'Schicht zur Abgabe freigegeben – jede:r kann sie jetzt übernehmen.'
        : 'Schicht zum Tausch angeboten.'];
}

/**
 * Jemand (B) übernimmt eine zur Abgabe freigegebene Schicht – einseitig, ohne Gegen-Schicht und
 * ohne Zustimmung der abgebenden Person. Die Zuteilung wandert direkt von A auf B (B steht danach
 * normal im Plan; der Eventscore zieht automatisch nach, weil er aus der Einteilung abgeleitet wird).
 */
function shift_giveaway_take(array $event, int $swapId, int $memberB): array
{
    if (!swap_market_open($event)) return ['ok' => false, 'msg' => 'Die Tauschbörse für dieses Event ist geschlossen.'];
    $eid = (int)$event['id'];
    $st = db()->prepare("SELECT * FROM shift_swaps WHERE id = ? AND event_id = ? AND status = 'open' AND mode = 'giveaway'");
    $st->execute([$swapId, $eid]);
    $sw = $st->fetch();
    if (!$sw) return ['ok' => false, 'msg' => 'Diese Schicht ist nicht mehr zur Übernahme verfügbar.'];
    $memberA = (int)$sw['offered_by'];
    $slotXid = (int)$sw['slot_id'];
    if ($memberA === $memberB) return ['ok' => false, 'msg' => 'Das ist deine eigene Schicht.'];
    $ax = assignment_row($slotXid, $memberA);
    if (!$ax || !empty($ax['locked'])) return ['ok' => false, 'msg' => 'Diese Schicht ist nicht mehr abgebbar (evtl. fixiert oder schon übernommen).'];
    if (assignment_row($slotXid, $memberB)) return ['ok' => false, 'msg' => 'Du bist dieser Schicht bereits zugeteilt.'];
    $slotX = event_slot_get($slotXid);
    if (!$slotX || (int)$slotX['event_id'] !== $eid) return ['ok' => false, 'msg' => 'Ungültige Schicht.'];
    if (member_time_conflict($memberB, $slotX, $slotXid)) return ['ok' => false, 'msg' => 'Du hast in dieser Zeit bereits einen anderen Einsatz.'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Zuteilung von A auf B umhängen (B ist danach normaler Schicht-Inhaber).
        $move = $pdo->prepare('UPDATE assignments SET member_id = ?, notified_at = NULL, locked = 0, self_claimed = 0 WHERE slot_id = ? AND member_id = ?');
        $move->execute([$memberB, $slotXid, $memberA]);
        if ($move->rowCount() === 0) { // jemand war schneller (oder A hat die Schicht nicht mehr)
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Diese Schicht wurde gerade von jemand anderem übernommen.'];
        }
        $pdo->prepare("UPDATE shift_swaps SET status = 'done', taken_by = ?, resolved_at = datetime('now','localtime') WHERE id = ?")
            ->execute([$memberB, (int)$sw['id']]);
        // Andere offene Angebote zu genau dieser Schicht + darauf bezogene Vorschläge sind hinfällig.
        $pdo->prepare("UPDATE shift_swaps SET status = 'cancelled', resolved_at = datetime('now','localtime') WHERE status = 'open' AND slot_id = ?")
            ->execute([$slotXid]);
        $pdo->prepare("UPDATE swap_proposals SET status = 'declined', resolved_at = datetime('now','localtime')
                       WHERE status = 'pending' AND (proposer_slot_id = ? OR swap_id IN (SELECT id FROM shift_swaps WHERE status <> 'open'))")
            ->execute([$slotXid]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    notify_giveaway_taken($event, $memberA, $memberB, $slotX);
    return ['ok' => true, 'msg' => 'Schicht übernommen – sie steht jetzt in deinem Plan.'];
}

/** Zieht ein eigenes offenes Angebot zurück. Offene Vorschläge darauf werden hinfällig. */
function shift_swap_cancel(int $swapId, int $memberId): void
{
    $st = db()->prepare("UPDATE shift_swaps SET status = 'cancelled', resolved_at = datetime('now','localtime')
                   WHERE id = ? AND offered_by = ? AND status = 'open'");
    $st->execute([$swapId, $memberId]);
    if ($st->rowCount() > 0) {
        db()->prepare("UPDATE swap_proposals SET status = 'declined', resolved_at = datetime('now','localtime') WHERE swap_id = ? AND status = 'pending'")
            ->execute([$swapId]);
    }
}

/* ----- Tausch-Vorschläge (Vorschlag → Zustimmung): B schlägt vor, A muss zustimmen ----- */

/** Eine einzelne Vorschlags-Zeile (oder null). */
function swap_proposal_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM swap_proposals WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Offene Tausch-Vorschläge zu einem Angebot (mit Name & Schichtdaten der vorschlagenden Person). */
function swap_proposals_for_offer(int $swapId): array
{
    $st = db()->prepare(
        "SELECT p.*, m.name AS proposer_name, s.starts_at, s.ends_at, s.label, s.location
         FROM swap_proposals p
         JOIN members m ON m.id = p.proposer_id
         JOIN event_slots s ON s.id = p.proposer_slot_id
         WHERE p.swap_id = ? AND p.status = 'pending'
         ORDER BY p.created_at, p.id"
    );
    $st->execute([$swapId]);
    return $st->fetchAll();
}

/** Offene Vorschläge, die ein Mitglied selbst gemacht hat: [swap_id => proposal-Zeile]. */
function swap_proposals_by_member(int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare("SELECT * FROM swap_proposals WHERE proposer_id = ? AND status = 'pending'");
    $st->execute([$memberId]);
    $out = [];
    foreach ($st->fetchAll() as $p) $out[(int)$p['swap_id']] = $p;
    return $out;
}

/**
 * B schlägt einen Tausch auf ein offenes Angebot vor: gibt die eigene Schicht $takenSlotId (Y) her.
 * Es wird NICHT sofort getauscht – die anbietende Person (A) muss erst zustimmen. A wird per Mail+Dashboard informiert.
 */
function swap_propose(array $event, int $swapId, int $takenSlotId, int $memberB): array
{
    if (!swap_market_open($event)) return ['ok' => false, 'msg' => 'Die Tauschbörse für dieses Event ist geschlossen.'];
    $eid = (int)$event['id'];
    $st = db()->prepare("SELECT * FROM shift_swaps WHERE id = ? AND event_id = ? AND status = 'open'");
    $st->execute([$swapId, $eid]);
    $sw = $st->fetch();
    if (!$sw) return ['ok' => false, 'msg' => 'Dieses Angebot ist nicht mehr verfügbar.'];
    $memberA = (int)$sw['offered_by'];
    $slotXid = (int)$sw['slot_id'];
    if ($memberA === $memberB) return ['ok' => false, 'msg' => 'Du kannst nicht mit dir selbst tauschen.'];
    if ($slotXid === $takenSlotId) return ['ok' => false, 'msg' => 'Bitte eine andere eigene Schicht zum Tausch wählen.'];
    $ay = assignment_row($takenSlotId, $memberB);
    if (!$ay || !empty($ay['locked'])) return ['ok' => false, 'msg' => 'Du bist der gewählten Tausch-Schicht nicht (mehr) zugeteilt.'];
    $slotY = event_slot_get($takenSlotId);
    if (!$slotY || (int)$slotY['event_id'] !== $eid) return ['ok' => false, 'msg' => 'Ungültige Schicht.'];
    if (assignment_row($slotXid, $memberB)) return ['ok' => false, 'msg' => 'Du bist der angebotenen Schicht bereits zugeteilt.'];
    // Pro Angebot nur einen offenen Vorschlag je Person
    $ex = db()->prepare("SELECT 1 FROM swap_proposals WHERE swap_id = ? AND proposer_id = ? AND status = 'pending'");
    $ex->execute([$swapId, $memberB]);
    if ($ex->fetchColumn()) return ['ok' => false, 'msg' => 'Du hast für dieses Angebot schon einen offenen Vorschlag – zieh ihn erst zurück.'];
    db()->prepare("INSERT INTO swap_proposals(swap_id, proposer_id, proposer_slot_id, status) VALUES(?,?,?,'pending')")
        ->execute([$swapId, $memberB, $takenSlotId]);
    notify_swap_proposal($event, $memberA, $memberB, event_slot_get($slotXid) ?: [], $slotY);
    return ['ok' => true, 'msg' => 'Vorschlag gesendet – die anbietende Person muss noch zustimmen.'];
}

/** A nimmt einen Vorschlag an: jetzt wird wirklich getauscht (X→B, Y→A) – mit erneuter Prüfung. */
function swap_proposal_accept(array $event, int $proposalId, int $memberA): array
{
    if (!swap_market_open($event)) return ['ok' => false, 'msg' => 'Die Tauschbörse für dieses Event ist geschlossen.'];
    $eid = (int)$event['id'];
    $p = swap_proposal_get($proposalId);
    if (!$p || $p['status'] !== 'pending') return ['ok' => false, 'msg' => 'Diese Anfrage ist nicht mehr verfügbar.'];
    $swStmt = db()->prepare("SELECT * FROM shift_swaps WHERE id = ? AND event_id = ? AND status = 'open'");
    $swStmt->execute([(int)$p['swap_id'], $eid]);
    $sw = $swStmt->fetch();
    if (!$sw) return ['ok' => false, 'msg' => 'Das Angebot ist nicht mehr offen.'];
    if ((int)$sw['offered_by'] !== $memberA) return ['ok' => false, 'msg' => 'Das ist nicht dein Angebot.'];
    $memberB = (int)$p['proposer_id'];
    $slotXid = (int)$sw['slot_id'];
    $takenSlotId = (int)$p['proposer_slot_id'];

    // A muss X noch (unfixiert) haben, B muss Y noch (unfixiert) haben
    $ax = assignment_row($slotXid, $memberA);
    if (!$ax || !empty($ax['locked'])) return ['ok' => false, 'msg' => 'Dein Platz ist nicht mehr tauschbar (evtl. fixiert).'];
    $ay = assignment_row($takenSlotId, $memberB);
    if (!$ay || !empty($ay['locked'])) return ['ok' => false, 'msg' => 'Die vorgeschlagene Gegen-Schicht ist nicht mehr gültig.'];
    $slotX = event_slot_get($slotXid);
    $slotY = event_slot_get($takenSlotId);
    if (!$slotX || !$slotY || (int)$slotX['event_id'] !== $eid || (int)$slotY['event_id'] !== $eid) return ['ok' => false, 'msg' => 'Ungültige Schicht.'];
    if (assignment_row($takenSlotId, $memberA)) return ['ok' => false, 'msg' => 'Tausch nicht möglich – du bist der Gegen-Schicht bereits zugeteilt.'];
    if (assignment_row($slotXid, $memberB)) return ['ok' => false, 'msg' => 'Tausch nicht möglich – die andere Person ist deiner Schicht bereits zugeteilt.'];
    $ivX = slot_interval($slotX);
    $ivY = slot_interval($slotY);
    foreach (member_assigned_slots($eid, $memberA) as $s) {            // A bekommt Y
        if ((int)$s['id'] === $slotXid) continue;
        if (intervals_overlap($ivY, slot_interval($s))) return ['ok' => false, 'msg' => 'Tausch nicht möglich – die Gegen-Schicht überschneidet sich mit einem deiner anderen Einsätze.'];
    }
    foreach (member_assigned_slots($eid, $memberB) as $s) {            // B bekommt X
        if ((int)$s['id'] === $takenSlotId) continue;
        if (intervals_overlap($ivX, slot_interval($s))) return ['ok' => false, 'msg' => 'Tausch nicht möglich – deine Schicht überschneidet sich mit einem Einsatz der anderen Person.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Beide Seiten atomar tauschen; rowCount-Guard wie bei der Abgabe („Übernehmen"):
        // hat eine Seite ihre Schicht inzwischen verloren, wird nichts halb getauscht.
        $mvX = $pdo->prepare('UPDATE assignments SET member_id = ?, notified_at = NULL, locked = 0, self_claimed = 0 WHERE slot_id = ? AND member_id = ?');
        $mvX->execute([$memberB, $slotXid, $memberA]);
        $mvY = $pdo->prepare('UPDATE assignments SET member_id = ?, notified_at = NULL, locked = 0, self_claimed = 0 WHERE slot_id = ? AND member_id = ?');
        $mvY->execute([$memberA, $takenSlotId, $memberB]);
        if ($mvX->rowCount() === 0 || $mvY->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Tausch nicht möglich – eine der beiden Schichten hat sich gerade geändert. Bitte lade die Seite neu.'];
        }
        $pdo->prepare("UPDATE shift_swaps SET status = 'done', taken_slot_id = ?, taken_by = ?, resolved_at = datetime('now','localtime') WHERE id = ?")
            ->execute([$takenSlotId, $memberB, (int)$sw['id']]);
        $pdo->prepare("UPDATE swap_proposals SET status = 'accepted', resolved_at = datetime('now','localtime') WHERE id = ?")->execute([$proposalId]);
        // Andere offene Angebote zu genau diesen beiden Schichten sind hinfällig
        $pdo->prepare("UPDATE shift_swaps SET status = 'cancelled', resolved_at = datetime('now','localtime') WHERE status = 'open' AND slot_id IN (?, ?)")
            ->execute([$slotXid, $takenSlotId]);
        // Hinfällige Vorschläge zurückweisen: auf nun geschlossene Angebote oder mit X/Y als Gegen-Schicht
        $pdo->prepare("UPDATE swap_proposals SET status = 'declined', resolved_at = datetime('now','localtime')
                       WHERE status = 'pending' AND (proposer_slot_id IN (?, ?) OR swap_id IN (SELECT id FROM shift_swaps WHERE status <> 'open'))")
            ->execute([$slotXid, $takenSlotId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    notify_shift_swap($event, $memberA, $memberB, $slotX, $slotY);
    return ['ok' => true, 'msg' => 'Tausch bestätigt – die Einteilung wurde aktualisiert.'];
}

/** A lehnt einen Vorschlag ab. Informiert die vorschlagende Person per Dashboard. */
function swap_proposal_decline(int $proposalId, int $memberA): bool
{
    $p = swap_proposal_get($proposalId);
    if (!$p || $p['status'] !== 'pending') return false;
    $sw = db()->prepare('SELECT offered_by, event_id, slot_id FROM shift_swaps WHERE id = ?');
    $sw->execute([(int)$p['swap_id']]);
    $swr = $sw->fetch();
    if (!$swr || (int)$swr['offered_by'] !== $memberA) return false;
    db()->prepare("UPDATE swap_proposals SET status = 'declined', resolved_at = datetime('now','localtime') WHERE id = ?")->execute([$proposalId]);
    $ev = event_get((int)$swr['event_id']);
    $slotX = event_slot_get((int)$swr['slot_id']);
    $slotY = event_slot_get((int)$p['proposer_slot_id']);
    if ($ev && $slotX && $slotY) {
        $labX = fmt_slot($slotX['starts_at'], $slotX['ends_at']) . ($slotX['label'] ? ' (' . $slotX['label'] . ')' : '');
        $labY = fmt_slot($slotY['starts_at'], $slotY['ends_at']) . ($slotY['label'] ? ' (' . $slotY['label'] . ')' : '');
        dm_send((int)$p['proposer_id'], 'Tauschbörse', 'Dein Tausch-Vorschlag bei „' . (string)$ev['title'] . '" wurde abgelehnt: ' . $labY . ' gegen ' . $labX . '.', (int)$ev['id'], false, false, 'dm_boerse');
    }
    return true;
}

/** B zieht seinen eigenen offenen Vorschlag zurück. */
function swap_proposal_withdraw(int $proposalId, int $memberB): bool
{
    $st = db()->prepare("UPDATE swap_proposals SET status = 'withdrawn', resolved_at = datetime('now','localtime') WHERE id = ? AND proposer_id = ? AND status = 'pending'");
    $st->execute([$proposalId, $memberB]);
    return $st->rowCount() > 0;
}

/** Informiert die anbietende Person (A) über einen neuen Tausch-Vorschlag – Dashboard + Mail (eigene Vorlage). */
function notify_swap_proposal(array $event, int $memberA, int $memberB, array $slotX, array $slotY): void
{
    $eid = (int)$event['id'];
    $title = (string)$event['title'];
    $mA = member_get($memberA); $mB = member_get($memberB);
    if (!$mA) return;
    $nameB = $mB['name'] ?? ('#' . $memberB);
    $labX = !empty($slotX) ? fmt_slot($slotX['starts_at'], $slotX['ends_at']) . ($slotX['label'] ? ' (' . $slotX['label'] . ')' : '') : 'deine Schicht';
    $labY = fmt_slot($slotY['starts_at'], $slotY['ends_at']) . ($slotY['label'] ? ' (' . $slotY['label'] . ')' : '');
    dm_send($memberA, 'Tauschbörse', $nameB . ' schlägt dir einen Tausch bei „' . $title . '" vor: Du gibst ' . $labX . ' ab und bekommst dafür ' . $labY . '. Zustimmen oder ablehnen in der Schichtbörse.', $eid, false, false, 'dm_boerse');
    if (member_mail($mA) !== '' && notify_pref($memberA, 'swap_offer')['mail']) {
        mail_tpl_send('swap_offer', member_mail($mA), [
            '{{VORNAME}}' => first_name((string)$mA['name']),
            '{{EVENT}}' => $title,
            '{{ANBIETER}}' => $nameB,
            '{{DEINE_SCHICHT}}' => $labX,
            '{{ANGEBOTENE_SCHICHT}}' => $labY,
            '{{LINK}}' => app_url('tauschboerse.php#ev' . $eid),
        ]);
    }
}

/** Informiert Event-Orga (Dashboard) und beide Beteiligten über einen vollzogenen Schichttausch. */
function notify_shift_swap(array $event, int $memberA, int $memberB, array $slotX, array $slotY): void
{
    $eid   = (int)$event['id'];
    $title = (string)$event['title'];
    $mA = member_get($memberA); $mB = member_get($memberB);
    $nameA = $mA['name'] ?? ('#' . $memberA);
    $nameB = $mB['name'] ?? ('#' . $memberB);
    $labX = fmt_slot($slotX['starts_at'], $slotX['ends_at']) . ($slotX['label'] ? ' (' . $slotX['label'] . ')' : '');
    $labY = fmt_slot($slotY['starts_at'], $slotY['ends_at']) . ($slotY['label'] ? ' (' . $slotY['label'] . ')' : '');

    $orga = 'Schichttausch bei „' . $title . '": ' . $nameA . ' und ' . $nameB . ' haben getauscht. '
          . $nameA . ' übernimmt jetzt ' . $labY . ', ' . $nameB . ' übernimmt ' . $labX . '.';
    foreach (event_owner_ids($eid) as $oid) {
        if ($oid === $memberA || $oid === $memberB) continue; // Beteiligte bekommen eine eigene Nachricht
        dm_send($oid, 'Tauschbörse', $orga, $eid, false, false, 'dm_boerse');
    }
    dm_send($memberA, 'Tauschbörse', 'Dein Tausch bei „' . $title . '" ist durch: Du übernimmst jetzt ' . $labY . ' (statt ' . $labX . ').', $eid, false, false, 'dm_boerse');
    dm_send($memberB, 'Tauschbörse', 'Dein Tausch bei „' . $title . '" ist durch: Du übernimmst jetzt ' . $labX . ' (statt ' . $labY . ').', $eid, false, false, 'dm_boerse');
}

/** Benachrichtigt nach einer Schicht-Abgabe (giveaway): die abgebende Person, die Übernehmenden bekommen
 *  die Bestätigung im Flash, und die Event-Orga eine Dashboard-Info. */
function notify_giveaway_taken(array $event, int $memberA, int $memberB, array $slot): void
{
    $eid   = (int)$event['id'];
    $title = (string)$event['title'];
    $nameA = member_get($memberA)['name'] ?? ('#' . $memberA);
    $nameB = member_get($memberB)['name'] ?? ('#' . $memberB);
    $lab = fmt_slot($slot['starts_at'], $slot['ends_at']) . ($slot['label'] ? ' (' . $slot['label'] . ')' : '');

    // Abgebende Person
    dm_send($memberA, 'Tauschbörse', $nameB . ' hat deine abgegebene Schicht bei „' . $title . '" übernommen: ' . $lab . '. Sie ist jetzt aus deinem Plan raus.', $eid, false, false, 'dm_boerse');
    // Event-Orga
    $orga = $nameB . ' hat bei „' . $title . '" eine von ' . $nameA . ' abgegebene Schicht übernommen: ' . $lab . '.';
    foreach (event_owner_ids($eid) as $oid) {
        if ($oid === $memberA || $oid === $memberB) continue;
        dm_send($oid, 'Tauschbörse', $orga, $eid, false, false, 'dm_boerse');
    }
}

/** Events mit offener Tauschbörse, in denen das Mitglied mind. eine Schicht hat (für die Börsen-Seite), nach Event-Start sortiert. */
function member_swap_events(int $memberId): array
{
    if ($memberId <= 0) return [];
    $rows = db()->query("SELECT * FROM events WHERE draft = 0 AND plan_locked = 1 AND closed = 0")->fetchAll();
    $out = [];
    foreach ($rows as $e) {
        if (!swap_market_open($e)) continue;
        if (!member_assigned_slots((int)$e['id'], $memberId)) continue;
        $out[] = $e;
    }
    usort($out, fn($a, $b) => (event_start_ts($a) ?? PHP_INT_MAX) <=> (event_start_ts($b) ?? PHP_INT_MAX));
    return $out;
}

/**
 * SATIRE / Spaßfunktion: Das „Schicht-Depot". Jeder vollzogene Tausch bringt den
 * Beteiligten ASX-„Aktien"; der Kurs steigt mit der Gesamtzahl aller Tausche. Mehr
 * Aktien × höherer Kurs = höherer (fiktiver) Depotwert. Alles deterministisch aus der
 * Tausch-Historie abgeleitet – keine eigene Tabelle. Der Depotwert ist am AsT-Markt als
 * Guthaben ausgebbar (ast_depot/ast_balance).
 */
const ASX_BASE = 42.00;   // Startkurs in „AStA-Talern" (AsT)
const ASX_STEP = 1.37;    // Kursaufschlag je vollzogenem Tausch (marktweit)
const ASX_PER_TRADE = 100; // „Aktien" pro eigenem Tausch

function swap_market_stats(int $memberId): array
{
    $totalTrades = (int)db()->query("SELECT COUNT(*) FROM shift_swaps WHERE status = 'done'")->fetchColumn();
    $myTrades = 0;
    if ($memberId > 0) {
        $st = db()->prepare("SELECT COUNT(*) FROM shift_swaps WHERE status = 'done' AND (offered_by = ? OR taken_by = ?)");
        $st->execute([$memberId, $memberId]);
        $myTrades = (int)$st->fetchColumn();
    }
    $kurs   = ASX_BASE + $totalTrades * ASX_STEP;
    $shares = $myTrades * ASX_PER_TRADE;
    $depot  = $shares * $kurs;

    // Deterministische „historische" Kursreihe (Seed = Gesamttausche), damit der Chart seriös aussieht.
    $n = 32; $series = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $n > 1 ? $i / ($n - 1) : 1.0;
        $trend = ASX_BASE + ($kurs - ASX_BASE) * $t;                       // Aufwärtstrend bis zum aktuellen Kurs
        $wig = sin(($i + 1) * 1.7 + $totalTrades * 0.9) * (ASX_BASE * 0.05)
             + cos(($i + 1) * 0.55 + $totalTrades) * (ASX_BASE * 0.025);   // deterministisches Wackeln
        $series[] = round(max(1.0, $trend + $wig), 2);
    }
    $series[$n - 1] = round($kurs, 2);                                     // letzter Punkt = aktueller Kurs
    $prev = $series[$n - 2] ?? $kurs;
    $changePct = $prev > 0 ? round(($kurs - $prev) / $prev * 100, 2) : 0.0;
    $gvPct = ASX_BASE > 0 ? round(($kurs - ASX_BASE) / ASX_BASE * 100, 2) : 0.0; // „Gewinn/Verlust" gg. Startkurs

    return [
        'totalTrades' => $totalTrades, 'myTrades' => $myTrades,
        'shares' => $shares, 'kurs' => $kurs, 'depot' => $depot,
        'changePct' => $changePct, 'gvPct' => $gvPct,
        'high' => max($series), 'low' => min($series), 'series' => $series,
    ];
}

/* ===================== Offene Schichten: selbst einspringen ===================== *
 * Nach Veröffentlichung des Plans können Mitglieder unbesetzte Plätze selbst füllen,
 * solange kein Einsatz zeitlich kollidiert. Die Orga wird per Dashboard informiert.
 */

/** Wie viele Mitglieder einer Schicht noch fehlen (Ziel minus Externe minus zugeteilte Mitglieder). */
function slot_open_remaining(array $slot, int $assignedMembers): int
{
    $t = (int)($slot['target_helpers'] ?? 0);
    if ($t <= 0) return 0;
    $eff = $t - count(slot_external_names($slot));
    return max(0, $eff - $assignedMembers);
}

/** Künftige, noch unterbesetzte Schichten eines Events (jeweils mit Schlüssel 'remaining'). */
function event_open_slots(int $eventId): array
{
    $assign = event_assignments($eventId); // [slot_id => [member_id, ...]]
    $out = [];
    foreach (event_slots($eventId) as $s) {
        $sid = (int)$s['id'];
        if (strtotime((string)$s['starts_at']) <= time()) continue; // nur künftige Schichten
        $rem = slot_open_remaining($s, count($assign[$sid] ?? []));
        if ($rem > 0) { $s['remaining'] = $rem; $out[] = $s; }
    }
    return $out;
}

/** Hat das Mitglied zur Zeit dieser Schicht schon einen anderen Einsatz (event-übergreifend)? */
function member_time_conflict(int $memberId, array $slot, int $ignoreSlotId = 0): bool
{
    $iv = slot_interval($slot);
    $st = db()->prepare('SELECT s.* FROM assignments a JOIN event_slots s ON s.id = a.slot_id WHERE a.member_id = ?');
    $st->execute([$memberId]);
    foreach ($st->fetchAll() as $o) {
        if ((int)$o['id'] === $ignoreSlotId) continue;
        if (intervals_overlap($iv, slot_interval($o))) return true;
    }
    return false;
}

/**
 * Überschneidungen im Arbeitsplan eines Events: Mitglieder, die zwei zeitlich
 * überlappenden Schichten DESSELBEN Events zugeteilt sind (Doppelbelegung).
 * Die Zufallseinteilung meidet solche Konflikte – manuelles Einteilen oder
 * spätere Plan-Änderungen können sie aber erzeugen. Hilft der Orga, sie zu finden.
 * Rückgabe: [ ['member_id'=>int,'name'=>string,'a'=>slotRow,'b'=>slotRow], … ],
 * frühere Schicht jeweils als 'a', sortiert nach deren Startzeit.
 */
function plan_overlaps(int $eventId): array
{
    $byId = [];
    foreach (event_slots($eventId) as $s) $byId[(int)$s['id']] = $s;

    // Slot-IDs je Mitglied sammeln
    $perMember = [];
    foreach (event_assignments($eventId) as $slotId => $members) {
        if (!isset($byId[$slotId])) continue;
        foreach ($members as $mid) $perMember[(int)$mid][] = (int)$slotId;
    }

    $out = [];
    foreach ($perMember as $mid => $slotIds) {
        $n = count($slotIds);
        if ($n < 2) continue;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $sa = $byId[$slotIds[$i]];
                $sb = $byId[$slotIds[$j]];
                if (!intervals_overlap(slot_interval($sa), slot_interval($sb))) continue;
                if (strtotime((string)$sa['starts_at']) > strtotime((string)$sb['starts_at'])) { $t = $sa; $sa = $sb; $sb = $t; }
                $m = member_get($mid);
                $out[] = ['member_id' => $mid, 'name' => (string)($m['name'] ?? ('#' . $mid)), 'a' => $sa, 'b' => $sb];
            }
        }
    }
    usort($out, fn($x, $y) => strcmp((string)$x['a']['starts_at'], (string)$y['a']['starts_at']));
    return $out;
}

/** Mitglied trägt sich selbst für eine offene Schicht ein. Gibt ['ok'=>bool,'msg'=>string]. */
function claim_open_slot(array $event, int $slotId, int $memberId): array
{
    if (empty($event['plan_locked'])) return ['ok' => false, 'msg' => 'Der Arbeitsplan ist noch nicht veröffentlicht.'];
    if (!empty($event['closed']))     return ['ok' => false, 'msg' => 'Dieses Event ist abgeschlossen.'];
    $eid = (int)$event['id'];
    $slot = event_slot_get($slotId);
    if (!$slot || (int)$slot['event_id'] !== $eid) return ['ok' => false, 'msg' => 'Ungültige Schicht.'];
    if (strtotime((string)$slot['starts_at']) <= time()) return ['ok' => false, 'msg' => 'Diese Schicht liegt schon in der Vergangenheit.'];
    if (assignment_row($slotId, $memberId)) return ['ok' => false, 'msg' => 'Du bist dieser Schicht bereits zugeteilt.'];
    $assign = event_assignments($eid);
    if (slot_open_remaining($slot, count($assign[$slotId] ?? [])) <= 0) return ['ok' => false, 'msg' => 'Diese Schicht ist bereits voll besetzt.'];
    if (member_time_conflict($memberId, $slot)) return ['ok' => false, 'msg' => 'Du hast in dieser Zeit bereits einen anderen Einsatz.'];
    db()->prepare('INSERT OR IGNORE INTO assignments(slot_id, member_id, locked, self_claimed) VALUES(?,?,0,1)')->execute([$slotId, $memberId]);
    notify_open_slot_claim($event, $memberId, $slot);
    return ['ok' => true, 'msg' => 'Eingetragen – danke fürs Einspringen!'];
}

/** Für die Einspringen-Übersicht (Events-Seite): veröffentlichte Events mit künftigen, unterbesetzten
 *  Schichten. Eigene Schichten werden NICHT mehr herausgefiltert, sondern mit 'mine' markiert –
 *  die Übersicht zeigt sie wie die Event-Detailseite als „du bist dabei" (gleicher Kenntnisstand
 *  auf beiden Seiten). Nach Event-Start sortiert. */
function member_open_slot_events(int $memberId): array
{
    if ($memberId <= 0) return [];
    $rows = db()->query("SELECT * FROM events WHERE draft = 0 AND plan_locked = 1 AND closed = 0")->fetchAll();
    $out = [];
    foreach ($rows as $e) {
        $open = array_map(function (array $s) use ($memberId): array {
            $s['mine'] = (bool)assignment_row((int)$s['id'], $memberId);
            return $s;
        }, event_open_slots((int)$e['id']));
        if ($open) $out[] = ['event' => $e, 'slots' => $open];
    }
    usort($out, fn($a, $b) => (event_start_ts($a['event']) ?? PHP_INT_MAX) <=> (event_start_ts($b['event']) ?? PHP_INT_MAX));
    return $out;
}

/**
 * Arbeitsplan im Druckansicht-Layout (Schichten-Tabelle bzw. Dienstplan-Roster + „Wer macht was") als HTML.
 * Zentral, damit die Druckseite (admin/plan.php) und die Event-Detailseite exakt dasselbe Layout zeigen.
 */
function plan_print_html(array $event, bool $screenRoster = true): string
{
    $id = (int)($event['id'] ?? 0);
    $slots  = slots_grouped(event_slots($id)); // chronologisch, gleichnamige Schichten zusammen
    $assign = event_assign_status($id);        // [slot_id => [member_id => notified]]
    $attrLabels = event_attr_labels($event);
    $attrHoldersAll = event_attr_holders_all($event);
    $multiAttr = count($attrLabels) > 1;
    // Merkmal-Markierung hinter einem Namen: „ ✓" (ein Merkmal) bzw. „ ✓1 ✓2" (mehrere).
    $attrMark = function (int $mid) use ($attrLabels, $attrHoldersAll, $multiAttr): string {
        $m = '';
        foreach ($attrLabels as $ai => $_) if (!empty($attrHoldersAll[$ai][$mid])) $m .= $multiAttr ? ' ✓' . (int)$ai : ' ✓';
        return $m;
    };
    $attrLegend = function () use ($attrLabels, $multiAttr): string {
        if (!$attrLabels) return '';
        $leg = [];
        foreach ($attrLabels as $ai => $alab) $leg[] = ($multiAttr ? '✓' . (int)$ai : '✓') . ' = ' . h($alab);
        return '<p class="small muted">' . implode(' · ', $leg) . '</p>';
    };
    $mname = [];
    foreach (members_all(true) as $m) $mname[(int)$m['id']] = $m['name'];

    // Nur die Uhrzeit einer Schicht (ohne Datum – das Datum macht der Tages-Separator).
    $timeOnly = function (array $s): string {
        $st = dt($s['starts_at']);
        if (!$st) return '';
        if ($st->format('H:i') === '00:00') return 'ganztägig';
        $open = is_open_end($s['ends_at']);
        $out = ($open ? 'ab ' : '') . $st->format('H:i');
        if (!$open) {
            $e = dt($s['ends_at']);
            if ($e) {
                $out .= '–' . $e->format('H:i');
                if ($e->format('Y-m-d') !== $st->format('Y-m-d')) {
                    $out .= ' (+' . max(1, (int)$st->diff($e)->format('%a')) . ')';
                }
            }
        }
        return $out;
    };

    // Chronologischer Schichtplan: pro Schicht eine Zeile (Uhrzeit + Bezeichnung + Ort | Eingeteilte),
    // bei mehrtägigen Events mit Tages-Trennern. Druckfreundlich, schlank, nur zwei Spalten.
    $multiDay = count(array_unique(array_map(fn($s) => substr((string)$s['starts_at'], 0, 10), $slots))) > 1;
    $shiftTable = function () use ($slots, $assign, $mname, $attrMark, $timeOnly, $multiDay): string {
        ob_start(); ?>
        <table class="list plan-table plan-shifts">
          <thead><tr><th class="ps-c1">Schicht</th><th>Eingeteilt</th></tr></thead>
          <tbody>
          <?php $lastDay = null; foreach ($slots as $s):
              $day = substr((string)$s['starts_at'], 0, 10);
              if ($multiDay && $day !== $lastDay): $lastDay = $day; ?>
                <tr class="ps-day"><td colspan="2"><?= h(fmt_event_range($day, null)) ?></td></tr>
              <?php endif;
              $people = array_map(fn($mid) => short_name($mname[$mid] ?? ('#' . $mid)) . $attrMark((int)$mid), array_keys($assign[(int)$s['id']] ?? []));
              foreach (slot_external_names($s) as $n) $people[] = h($n) . ' <span class="muted">(extern)</span>';
              $tt = (int)$s['target_helpers'];
              $loc = trim((string)$s['location']);
          ?>
            <tr>
              <td class="ps-c1">
                <span class="ps-time"><?= h($timeOnly($s)) ?></span>
                <?php if ($s['label']): ?><span class="ps-label"><?= h($s['label']) ?></span><?php endif; ?>
                <?php if ($loc !== ''): ?><span class="ps-loc"><i class="ti ti-map-pin"></i> <?= h($loc) ?></span><?php endif; ?>
              </td>
              <td>
                <?php if ($people): ?>
                  <?= implode(', ', $people) ?><?php if ($tt > 0): ?> <span class="muted">(<?= count($people) ?>/<?= $tt ?>)</span><?php endif; ?>
                <?php else: ?>
                  <span class="muted">— niemand eingeteilt<?= $tt > 0 ? ' (0/' . $tt . ')' : '' ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php return (string)ob_get_clean();
    };

    ob_start();
    ?>
    <h2>Schichten</h2>
    <?php if (!$slots): ?>
      <p>Für dieses Event sind noch keine Schichten angelegt.</p>
    <?php elseif ($screenRoster && count($slots) >= 5): // ab 5 Schichten: am Bildschirm die Dienstplan-Übersicht (Gantt) ?>
      <?php
      // Die Gantt-Tabelle ist breit und nur für den Bildschirm gedacht; im Druck würde sie
      // über den Seitenrand laufen. Deshalb druckt stattdessen der schlanke Schichtplan.
      $rosterCell = function ($s) use ($assign, $mname, $attrMark) {
          $ids = array_keys($assign[(int)$s['id']] ?? []);
          $ext = slot_external_names($s);
          $tt = (int)$s['target_helpers'];
          $total = count($ids) + count($ext);
          if (!$ids && !$ext) return '<span class="muted">— niemand' . ($tt > 0 ? ' (0/' . $tt . ')' : '') . '</span>';
          $out = '';
          foreach ($ids as $mid) $out .= '<div>' . h(short_name($mname[$mid] ?? ('#' . $mid))) . $attrMark((int)$mid) . '</div>';
          foreach ($ext as $n)   $out .= '<div>' . h($n) . ' <span class="muted small">(extern)</span></div>';
          return $out . ($tt > 0 ? '<div class="muted small">' . $total . '/' . $tt . '</div>' : '');
      };
      ?>
      <div class="only-screen">
        <?= shift_roster_html($slots, $rosterCell) ?>
        <?= $attrLegend() ?>
      </div>
      <div class="only-print">
        <?= $shiftTable() ?>
        <?= $attrLegend() ?>
      </div>
    <?php else: // wenige Schichten: schlanker Schichtplan für Bildschirm und Druck ?>
      <?= $shiftTable() ?>
      <?= $attrLegend() ?>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/** Informiert die Event-Orga (Dashboard), dass jemand eine offene Schicht selbst übernommen hat. */
function notify_open_slot_claim(array $event, int $memberId, array $slot): void
{
    $eid = (int)$event['id'];
    $m = member_get($memberId);
    $name = $m['name'] ?? ('#' . $memberId);
    $lab = fmt_slot($slot['starts_at'], $slot['ends_at']) . ($slot['label'] ? ' (' . $slot['label'] . ')' : '');
    $msg = $name . ' ist bei „' . (string)$event['title'] . '" für eine offen gebliebene Schicht eingesprungen: ' . $lab . '.';
    foreach (event_owner_ids($eid) as $oid) {
        if ($oid === $memberId) continue;
        dm_send($oid, 'Schichtbörse', $msg, $eid, false, false, 'dm_boerse');
    }
}

/* ===================== AStA Get-Togethers (interne Spaß-Treffen) ===================== *
 * Unverbindliche interne Treffen. Erscheinen im Kalender und bitten im Dashboard nur um
 * eine Ja/Nein-Rückmeldung. Sagt jemand „Nein", verschwindet das Treffen aus seinem
 * persönlichen Kalender (App + iCal). Anlegen/verwalten dürfen Sekretariat, Vorsitz, Admin.
 */
function can_manage_gettogethers(): bool { return can_manage_meetings(); }

/** Ein Get-Together (oder null). */
function gettogether_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM gettogethers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Alle Get-Togethers, chronologisch (für die Verwaltung). */
function gettogethers_all(): array
{
    return db()->query('SELECT * FROM gettogethers ORDER BY starts_at')->fetchAll();
}

/** Künftige Get-Togethers (Tag heute oder später), chronologisch. */
function gettogethers_upcoming(): array
{
    return db()->query("SELECT * FROM gettogethers WHERE date(COALESCE(NULLIF(ends_at,''), starts_at)) >= date('now','localtime') ORDER BY starts_at")->fetchAll();
}

/** Get-Togethers, die einen Tag im Bereich berühren (für den Kalender). */
function gettogethers_in_range(string $from, string $to): array
{
    $st = db()->prepare("SELECT * FROM gettogethers
        WHERE date(starts_at) <= ? AND date(COALESCE(NULLIF(ends_at,''), starts_at)) >= ? ORDER BY starts_at");
    $st->execute([$to, $from]);
    return $st->fetchAll();
}

/** RSVP-Status eines Mitglieds zu einem Get-Together: 'yes' | 'no' | null (noch nicht entschieden). */
function gettogether_rsvp_status(int $gtId, int $memberId): ?string
{
    if ($memberId <= 0) return null;
    $st = db()->prepare('SELECT status FROM gettogether_rsvp WHERE gettogether_id = ? AND member_id = ?');
    $st->execute([$gtId, $memberId]);
    $s = $st->fetchColumn();
    return $s === false ? null : (string)$s;
}

/** Alle RSVPs eines Mitglieds: [gettogether_id => 'yes'|'no']. */
function gettogether_rsvp_map(int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare('SELECT gettogether_id, status FROM gettogether_rsvp WHERE member_id = ?');
    $st->execute([$memberId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['gettogether_id']] = (string)$r['status'];
    return $out;
}

/** Setzt/aktualisiert die Ja/Vielleicht/Nein-Rückmeldung eines Mitglieds. */
function gettogether_rsvp_set(int $gtId, int $memberId, string $status): void
{
    if ($memberId <= 0 || $gtId <= 0) return;
    $status = in_array($status, ['yes', 'maybe', 'no'], true) ? $status : 'yes';
    db()->prepare("INSERT INTO gettogether_rsvp(gettogether_id, member_id, status, updated_at)
                   VALUES(?,?,?, datetime('now','localtime'))
                   ON CONFLICT(gettogether_id, member_id) DO UPDATE SET status = excluded.status, updated_at = excluded.updated_at")
        ->execute([$gtId, $memberId, $status]);
}

/**
 * Neues Get-Together ankündigen: Dashboard-Mitteilung an alle aktiven Mitglieder (Push/Mail je
 * nach persönlicher Einstellung), außer der anlegenden Person.
 *
 * Bewusst KEINE Pflicht: Zu- oder Absagen ändert nichts am Basis-Score, und wer absagt, hört
 * nichts mehr davon (die Dashboard-Aufgabe verschwindet mit jeder Rückmeldung – auch mit „Nein").
 * Die Mitteilung bittet nur darum, sich überhaupt zu melden, damit die Orga planen kann.
 * Rückgabe: Anzahl zugestellter Mitteilungen.
 */
function gettogether_notify_new(int $gtId, ?int $exceptId = null): int
{
    $gt = gettogether_get($gtId);
    if (!$gt) return 0;
    $ort  = trim((string)$gt['location']);
    $body = '„' . (string)$gt['title'] . '" am ' . fmt_slot((string)$gt['starts_at'], $gt['ends_at'] ?? null)
          . ($ort !== '' ? ' · ' . $ort : '')
          . '. Kommst du? Sag kurz zu oder ab – freiwillig, aber die Orga plant besser mit Zahlen.';
    $n = 0;
    foreach (members_all() as $m) {
        $mid = (int)$m['id'];
        if ($exceptId !== null && $mid === $exceptId) continue;
        try {
            if (dm_send($mid, 'Get-Together 🎉', $body, null, notify_pref($mid, 'gt_new')['mail'], true,
                'gt_new', 'gettogether.php?id=' . $gtId)) $n++;
        } catch (\Throwable $e) { /* einzelne Versand-Fehler überspringen */ }
    }
    return $n;
}

/** Ja/Vielleicht/Nein-Zähler eines Get-Togethers: ['yes'=>n, 'maybe'=>n, 'no'=>n]. */
function gettogether_rsvp_counts(int $gtId): array
{
    $st = db()->prepare("SELECT status, COUNT(*) c FROM gettogether_rsvp WHERE gettogether_id = ? GROUP BY status");
    $st->execute([$gtId]);
    $out = ['yes' => 0, 'maybe' => 0, 'no' => 0];
    foreach ($st->fetchAll() as $r) { $k = (string)$r['status']; if (isset($out[$k])) $out[$k] = (int)$r['c']; }
    return $out;
}

/** Künftige Get-Togethers, zu denen ein Mitglied noch GAR nicht geantwortet hat (Dashboard-Aufgabe). */
function gettogethers_pending_for_member(int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare(
        "SELECT g.* FROM gettogethers g
         WHERE date(COALESCE(NULLIF(g.ends_at,''), g.starts_at)) >= date('now','localtime')
           AND NOT EXISTS (SELECT 1 FROM gettogether_rsvp r WHERE r.gettogether_id = g.id AND r.member_id = ?)
         ORDER BY g.starts_at"
    );
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** Mitglieder eines Get-Togethers mit einem bestimmten Status (Default „yes"), alphabetisch. */
function gettogether_attendees(int $gtId, string $status = 'yes'): array
{
    if (!in_array($status, ['yes', 'maybe', 'no'], true)) $status = 'yes';
    $st = db()->prepare(
        "SELECT m.id, m.name, m.pronouns, m.avatar_decos, m.avatar_palette, m.avatar_ink FROM gettogether_rsvp r
         JOIN members m ON m.id = r.member_id
         WHERE r.gettogether_id = ? AND r.status = ?
         ORDER BY m.name COLLATE NOCASE"
    );
    $st->execute([$gtId, $status]);
    return $st->fetchAll();
}

/** Mitbring-/Bedarfsliste eines Get-Togethers (mit Name der Person, die es mitbringt). */
function gettogether_items(int $gtId): array
{
    $st = db()->prepare(
        "SELECT i.*, m.name AS bringer_name, m.pronouns AS bringer_pronouns,
                m.avatar_decos AS bringer_decos, m.avatar_palette AS bringer_palette
         FROM gettogether_items i
         LEFT JOIN members m ON m.id = i.brought_by
         WHERE i.gettogether_id = ? ORDER BY i.is_need DESC, i.sort, i.id"
    );
    $st->execute([$gtId]);
    return $st->fetchAll();
}

/** Eintrag zur Mitbring-Liste hinzufügen. is_need=true: Orga-Bedarf (offen); sonst freier Beitrag (gleich mitgebracht von $broughtBy). */
function gettogether_item_add(int $gtId, string $label, bool $isNeed, ?int $broughtBy, ?int $createdBy): void
{
    $label = trim($label);
    if ($gtId <= 0 || $label === '') return;
    $sort = (int)db()->query('SELECT COALESCE(MAX(sort),0)+1 FROM gettogether_items WHERE gettogether_id = ' . $gtId)->fetchColumn();
    db()->prepare('INSERT INTO gettogether_items(gettogether_id, label, is_need, brought_by, created_by, sort) VALUES(?,?,?,?,?,?)')
        ->execute([$gtId, $label, $isNeed ? 1 : 0, $broughtBy, $createdBy, $sort]);
}

/** Einzelner Listeneintrag (oder null). */
function gettogether_item_get(int $itemId): ?array
{
    $st = db()->prepare('SELECT * FROM gettogether_items WHERE id = ?');
    $st->execute([$itemId]);
    return $st->fetch() ?: null;
}

/** Offenen Bedarf übernehmen: setzt brought_by, sofern noch frei. */
function gettogether_item_claim(int $itemId, int $memberId): void
{
    if ($memberId <= 0) return;
    db()->prepare('UPDATE gettogether_items SET brought_by = ? WHERE id = ? AND brought_by IS NULL')
        ->execute([$memberId, $itemId]);
}

/**
 * Eintrag „freigeben": Bei Orga-Bedarf wird er wieder offen (brought_by = NULL),
 * bei freiem Beitrag wird er gelöscht. Erlaubt für die mitbringende Person oder Orga.
 */
function gettogether_item_release(int $itemId, int $memberId, bool $isOrganizer): void
{
    $it = gettogether_item_get($itemId);
    if (!$it) return;
    if (!$isOrganizer && (int)$it['brought_by'] !== $memberId) return;
    if (!empty($it['is_need'])) {
        db()->prepare('UPDATE gettogether_items SET brought_by = NULL WHERE id = ?')->execute([$itemId]);
    } else {
        db()->prepare('DELETE FROM gettogether_items WHERE id = ?')->execute([$itemId]);
    }
}

/** Eintrag ganz löschen (Orga). */
function gettogether_item_delete(int $itemId): void
{
    db()->prepare('DELETE FROM gettogether_items WHERE id = ?')->execute([$itemId]);
}

/**
 * Faire Zufallseinteilung. $fair steuert, WONACH ausgeglichen wird:
 *  'shifts' (Standard) – möglichst gleich viele SCHICHTEN pro Person (bisheriges Verhalten)
 *  'hours'             – möglichst gleich viele STUNDEN pro Person (Schichtdauer zählt)
 */
function auto_assign_event(array $event, string $fair = 'shifts'): int
{
    $eventId = (int)$event['id'];
    // FIXIERTE (locked) und selbst EINGESPRUNGENE (self_claimed) Zuteilungen bleiben bestehen;
    // nur die übrigen werden neu verteilt. Beide zählen unten wie normale Schichten mit.
    $locked = locked_assignments($eventId); // [slot_id => [member_id, ...]]
    foreach (self_claimed_assignments($eventId) as $sid => $ms) {
        foreach (array_keys($ms) as $mid) {
            if (!in_array((int)$mid, $locked[$sid] ?? [], true)) $locked[$sid][] = (int)$mid;
        }
    }
    clear_unlocked_assignments($eventId);
    $slots = event_slots($eventId);
    $resp = event_responses($eventId);

    $maxShifts = (int)($event['max_shifts_per_member'] ?? 0);
    if ($maxShifts <= 0) $maxShifts = PHP_INT_MAX;
    $attrIdxs = array_keys(event_attr_labels($event));   // aktive Merkmale, z. B. [1,2]
    $holdersByAttr = event_attr_holders_all($event);     // [idx => [member_id => true]]

    $count = [];      // member_id => Anzahl Schichten in diesem Event
    $secs = [];       // member_id => eingeteilte Sekunden in diesem Event (für „fair nach Stunden")
    $earned = [];     // member_id => in diesem Lauf bereits "verdiente" Punkte (für faire Verteilung)
    $intervals = [];  // member_id => [[start,end], ...]
    $scores = member_scores($eventId); // bisheriger Beitrag (ohne dieses Event)
    $ins = db()->prepare('INSERT OR IGNORE INTO assignments(slot_id, member_id) VALUES(?,?)');
    $total = 0;

    // Gelockte Personen vorab als belegt einlesen: sie zählen wie eine ganz normale Schicht
    // (Schicht-Zähler, Stunden, Eventscore-Last, Zeit-Blockade gegen Doppelbelegung) und besetzen einen Platz.
    foreach ($slots as $s) {
        $slotId = (int)$s['id'];
        foreach ($locked[$slotId] ?? [] as $mid) {
            $ivL = slot_interval($s);
            $count[$mid] = ($count[$mid] ?? 0) + 1;
            $secs[$mid] = ($secs[$mid] ?? 0) + ($ivL[1] - $ivL[0]);
            $earned[$mid] = ($earned[$mid] ?? 0) + slot_points($s);
            $intervals[$mid][] = $ivL;
            $total++;
        }
    }

    foreach ($slots as $s) {
        $slotId = (int)$s['id'];
        $iv = slot_interval($s);
        $lockedHere = $locked[$slotId] ?? [];
        // Nur „Ja" wird eingeteilt – „Vielleicht" gilt nach Fristende als „Nein".
        // Gelockte dieser Schicht stehen schon → aus dem Auswahl-Pool nehmen.
        $yes = [];
        foreach ($resp[$slotId] ?? [] as $mid => $r) {
            if ($r['status'] === 'yes' && !in_array((int)$mid, $lockedHere, true)) $yes[] = (int)$mid;
        }
        $need = (int)($s['target_helpers'] ?? 0);
        if ($need <= 0) continue; // Schicht ohne Helferbedarf wird nicht automatisch besetzt
        $need = max(0, $need - count(slot_external_names($s)) - count($lockedHere)); // Externe + Gelockte decken Plätze mit ab
        if ($need <= 0) continue; // Bedarf bereits gedeckt
        // Auswahl-Reihenfolge – je nach $fair:
        //  'shifts': 1) wenigste SCHICHTEN in diesem Event  2) niedrigerer Eventscore
        //            3) geringere Punkte-Last  4) Zufall (durch das vorherige shuffle)
        //  'hours':  1) wenigste STUNDEN in diesem Event    2) wenigste Schichten
        //            3) niedrigerer Eventscore              4) Zufall
        // Der Eventscore ist damit NUR Tiebreak und kann die Gleichverteilung nie dominieren.
        // $attrIdx (1..3) filtert auf Träger:innen dieses Merkmals, null = alle.
        // Eligibilität nutzt den aktuellen Stand (Abwesenheit, Max, Überlappung).
        $pick = function (?int $attrIdx) use ($yes, $s, $iv, &$count, &$secs, &$earned, &$intervals, $maxShifts, $scores, $holdersByAttr, $fair): array {
            $elig = array_values(array_filter($yes, function ($mid) use ($s, $iv, &$count, &$intervals, $maxShifts, $attrIdx, $holdersByAttr) {
                if ($attrIdx !== null && empty($holdersByAttr[$attrIdx][$mid])) return false;
                if (member_absent_on($mid, $s['starts_at'])) return false;
                if (($count[$mid] ?? 0) >= $maxShifts) return false;
                foreach ($intervals[$mid] ?? [] as $exiv) if (intervals_overlap($iv, $exiv)) return false;
                return true;
            }));
            shuffle($elig);                                   // Zufall bei Gleichstand
            usort($elig, function ($a, $b) use ($scores, $earned, $count, $secs, $fair) {
                if ($fair === 'hours') {
                    // 1) möglichst gleich viele STUNDEN in diesem Event
                    if (($secs[$a] ?? 0) !== ($secs[$b] ?? 0)) return ($secs[$a] ?? 0) <=> ($secs[$b] ?? 0);
                    // 2) bei Gleichstand: weniger Schichten zuerst
                    if (($count[$a] ?? 0) !== ($count[$b] ?? 0)) return ($count[$a] ?? 0) <=> ($count[$b] ?? 0);
                    // 3) Eventscore nur als kleiner Ausschlag
                    return ($scores[$a] ?? 0) <=> ($scores[$b] ?? 0);
                }
                // 1) möglichst gleich VIELE Schichten in diesem Event
                if (($count[$a] ?? 0) !== ($count[$b] ?? 0)) return ($count[$a] ?? 0) <=> ($count[$b] ?? 0);
                // 2) Eventscore nur als kleiner Ausschlag: geringerer Beitrag bekommt die Rest-Schicht
                if (($scores[$a] ?? 0) !== ($scores[$b] ?? 0)) return ($scores[$a] ?? 0) <=> ($scores[$b] ?? 0);
                // 3) bisher geringere Last bevorzugen (lange Schichten zählen mehr)
                return ($earned[$a] ?? 0) <=> ($earned[$b] ?? 0);
            });
            return $elig;
        };
        $place = function (int $mid) use ($s, $ins, $slotId, $iv, &$count, &$secs, &$earned, &$intervals, &$total) {
            $ins->execute([$slotId, $mid]);
            $count[$mid] = ($count[$mid] ?? 0) + 1;
            $secs[$mid] = ($secs[$mid] ?? 0) + ($iv[1] - $iv[0]);
            $earned[$mid] = ($earned[$mid] ?? 0) + slot_points($s); // wie der Eventscore
            $intervals[$mid][] = $iv;
            $total++;
        };

        // Phase 1: Pflicht-Plätze PRO Merkmal zuerst. Eine eingeteilte Person, die mehrere
        // Merkmale hat, deckt auch deren Bedarf mit ab (rem für alle ihre Merkmale sinkt).
        $rem = [];
        foreach ($attrIdxs as $i) $rem[$i] = min((int)($s[attr_count_col($i)] ?? 0), $need);
        foreach ($attrIdxs as $i) {
            if (($rem[$i] ?? 0) <= 0 || $need <= 0) continue;
            foreach ($pick($i) as $mid) {                     // Träger:innen von Merkmal $i (ohne bereits Zugeteilte)
                if (($rem[$i] ?? 0) <= 0 || $need <= 0) break;
                $place($mid); $need--;
                foreach ($attrIdxs as $j) if (!empty($holdersByAttr[$j][$mid])) $rem[$j] = max(0, ($rem[$j] ?? 0) - 1);
            }
        }
        // Phase 2: Rest fair auffüllen (in Phase 1 Zugeteilte fallen über die Überlappungsprüfung heraus)
        foreach ($pick(null) as $mid) {
            if ($need <= 0) break;
            $place($mid); $need--;
        }
    }
    return $total;
}

/**
 * Schickt eingeteilten Mitgliedern (mit E-Mail) eine übersichtliche Mail mit
 * ihren Schichten (Datum/Uhrzeit/Ort) und markiert deren Einteilung als benachrichtigt.
 *
 * @param bool $onlyChanged true = nur Personen mit noch nicht benachrichtigter (neuer/geänderter) Einteilung
 * @return string[] Namen der Benachrichtigten
 */
function notify_assigned_members(array $event, bool $onlyChanged = false): array
{
    $eventId = (int)$event['id'];
    $sql = "SELECT DISTINCT m.id, m.name, m.email, m.notify_email FROM assignments a
            JOIN event_slots s ON s.id = a.slot_id
            JOIN members m ON m.id = a.member_id
            WHERE s.event_id = ? AND m.email <> '' AND m.notify_assignment = 1";
    if ($onlyChanged) $sql .= ' AND a.notified_at IS NULL';
    $sql .= ' ORDER BY m.name COLLATE NOCASE';
    $mst = db()->prepare($sql);
    $mst->execute([$eventId]);
    $url = app_url('event.php?id=' . $eventId);
    $slotStmt = db()->prepare(
        "SELECT s.* FROM assignments a JOIN event_slots s ON s.id = a.slot_id
         WHERE s.event_id = :e AND a.member_id = :m ORDER BY s.starts_at"
    );
    $mark = db()->prepare(
        "UPDATE assignments SET notified_at = datetime('now','localtime')
         WHERE member_id = :m AND slot_id IN (SELECT id FROM event_slots WHERE event_id = :e)"
    );
    $sent = [];
    foreach ($mst->fetchAll() as $m) {
        $slotStmt->execute([':e' => $eventId, ':m' => (int)$m['id']]);
        $lines = [];
        foreach ($slotStmt->fetchAll() as $s) {
            $line = '• ' . fmt_slot($s['starts_at'], $s['ends_at']);
            if (trim((string)$s['location']) !== '') $line .= ' · Ort: ' . $s['location'];
            if (trim((string)$s['label']) !== '') $line .= ' (' . $s['label'] . ')';
            $lines[] = $line;
        }
        if (!$lines) continue;
        // Kanäle getrennt: Mail und/oder Push, je nach Einstellung der Person (Mitteilungs-Register)
        $pref = notify_pref((int)$m['id'], 'assignment');
        $mailOk = $pref['mail'] && mail_tpl_send('assignment', member_mail($m), [
                '{{VORNAME}}' => first_name($m['name']),
                '{{EVENT}}' => (string)$event['title'],
                '{{SCHICHTEN}}' => implode("\n", $lines),
                '{{LINK}}' => $url,
            ]);
        $pushOk = $pref['push']
            ? push_send((int)$m['id'], 'Eingeteilt: ' . (string)$event['title'],
                count($lines) === 1 ? ltrim($lines[0], '• ') : count($lines) . ' Schichten – Details in der App.', $url) : 0;
        if ($mailOk || $pushOk) {
            $mark->execute([':m' => (int)$m['id'], ':e' => $eventId]);
            $sent[] = $m['name'];
        }
    }
    return $sent;
}

/**
 * Informiert Mitglieder, die aus der Einteilung gestrichen wurden („du bist nicht mehr eingeteilt").
 * @param int[] $memberIds
 * @return string[] Namen der Benachrichtigten
 */
function notify_removed_members(array $event, array $memberIds): array
{
    $url = app_url('event.php?id=' . (int)$event['id']);
    $st = db()->prepare("SELECT name, email, notify_email FROM members WHERE id = ? AND email <> '' AND notify_assignment = 1");
    $sent = [];
    foreach (array_unique(array_map('intval', $memberIds)) as $mid) {
        $st->execute([$mid]);
        $m = $st->fetch();
        if (!$m) continue;
        // Kanäle getrennt: Mail und/oder Push, je nach Einstellung der Person (Mitteilungs-Register)
        $pref = notify_pref($mid, 'assignment');
        $mailOk = $pref['mail'] && mail_tpl_send('unassignment', member_mail($m), [
                '{{VORNAME}}' => first_name($m['name']),
                '{{EVENT}}' => (string)$event['title'],
                '{{LINK}}' => $url,
            ]);
        $pushOk = $pref['push']
            ? push_send($mid, 'Einteilung geändert: ' . (string)$event['title'], 'Du bist nicht mehr eingeteilt – Details in der App.', $url) : 0;
        if ($mailOk || $pushOk) $sent[] = $m['name'];
    }
    return $sent;
}

/**
 * Verschickt fällige Schicht-Erinnerungen an Mitglieder, die das aktiviert haben
 * (members.reminder_lead_days). Pro Schicht höchstens einmal. Gibt die Anzahl zurück.
 */
function send_shift_reminders(bool $dryRun = false): int
{
    $rows = db()->query(
        "SELECT a.slot_id, a.member_id, m.name, m.email, m.notify_email,
                s.starts_at, s.ends_at, s.location, s.label, e.title AS event_title, e.id AS event_id
         FROM assignments a
         JOIN members m ON m.id = a.member_id
         JOIN event_slots s ON s.id = a.slot_id
         JOIN events e ON e.id = s.event_id
         WHERE m.reminder_lead_days > 0 AND m.email <> '' AND e.plan_locked = 1
           AND datetime(s.starts_at) >= datetime('now','localtime')
           AND date(s.starts_at) <= date('now','localtime','+' || m.reminder_lead_days || ' days')
           AND NOT EXISTS (SELECT 1 FROM shift_reminders sr WHERE sr.slot_id = a.slot_id AND sr.member_id = a.member_id)
         ORDER BY s.starts_at"
    )->fetchAll();
    $log = db()->prepare("INSERT OR IGNORE INTO shift_reminders(slot_id, member_id, sent_at) VALUES(?,?,datetime('now','localtime'))");
    $n = 0;
    foreach ($rows as $r) {
        $line = fmt_slot($r['starts_at'], $r['ends_at']);
        if (trim((string)$r['location']) !== '') $line .= ' · Ort: ' . $r['location'];
        if (trim((string)$r['label']) !== '') $line .= ' (' . $r['label'] . ')';
        if ($dryRun) { $n++; continue; } // nur zählen, nichts senden/loggen
        // Kanäle getrennt: Mail und/oder Push, je nach Einstellung der Person (Mitteilungs-Register)
        $pref = notify_pref((int)$r['member_id'], 'shift_reminder');
        $mailOk = $pref['mail'] && mail_tpl_send('shift_reminder', member_mail($r), [
                '{{VORNAME}}' => first_name($r['name']),
                '{{EVENT}}' => (string)$r['event_title'],
                '{{SCHICHT}}' => $line,
            ]);
        $pushOk = $pref['push']
            ? push_send((int)$r['member_id'], 'Einsatz bald: ' . (string)$r['event_title'], $line, app_url('event.php?id=' . (int)$r['event_id'])) : 0;
        if ($mailOk || $pushOk) {
            $log->execute([(int)$r['slot_id'], (int)$r['member_id']]);
            $n++;
        }
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Eventscore
// ---------------------------------------------------------------------------
/** Stichtag, ab dem gezählt wird (Admin-Reset). Leer = alles. */
function score_since(): string { return (string)setting_get('score_since', ''); }

/**
 * Vergangene, für den Eventscore gewertete Events: Frist abgelaufen und seit dem letzten Reset
 * (`score_since`). Liefert id, title, important, deadline. $orderBy optional (z. B. 'deadline DESC').
 */
function scored_events(string $orderBy = ''): array
{
    $since = score_since();
    $sql = "SELECT id, title, important, deadline, plan_locked FROM events WHERE draft = 0 AND deadline IS NOT NULL AND deadline <> '' AND deadline < date('now','localtime')";
    $params = [];
    if ($since !== '') { $sql .= ' AND deadline >= ?'; $params[] = $since; }
    if ($orderBy !== '') $sql .= ' ORDER BY ' . $orderBy;
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Die EINZIGE Stelle, an der die Eventscore-Regeln eines Events für ein Mitglied stehen:
 *  - eingeteilt: +1/Schicht (+2 bei >2 h), pro Event auf `event_score_cap()` gedeckelt
 *  - gar nicht abgestimmt: −2
 *  - verfügbar-aber-nicht-eingeteilt / überall „nein": 0
 * Gibt null zurück, wenn das Event für dieses Mitglied nicht zählt (Frist lag vor dem Beitritt).
 * $resp/$assign sind die vorab geladenen event_responses()/event_assignments() des Events.
 * @return array{points:int,reason:string,shifts:int}|null
 */
function score_event_for_member(array $event, array $slots, array $resp, array $assign, int $memberId, string $joinedAt = '', array $ownerIds = []): ?array
{
    // Events vor dem Beitritt zählen nicht – neue Mitglieder starten bei 0 (kein Malus).
    if ($joinedAt !== '' && substr((string)$event['deadline'], 0, 10) < $joinedAt) return null;

    $cap = event_score_cap($event);

    // Event-Orga/Besitzer:innen helfen organisatorisch ohnehin überall mit → automatisch
    // die Event-Maximalpunktzahl minus 1 (mind. 1), ohne sich eintragen oder abstimmen zu
    // müssen (auch kein „nicht abgestimmt"-Malus).
    if (in_array($memberId, $ownerIds, true)) {
        $orgaPts = max(1, $cap - 1);
        return ['points' => $orgaPts, 'shifts' => 0, 'reason' => 'Event-Orga (automatisch +' . $orgaPts . ')'];
    }

    $responded = false; $avail = false; $pts = 0; $shifts = 0;
    foreach ($slots as $s) {
        $sid = (int)$s['id'];
        $r = $resp[$sid][$memberId] ?? null;
        if ($r) { $responded = true; if ($r['status'] === 'yes' || $r['status'] === 'maybe') $avail = true; }
        if (in_array($memberId, $assign[$sid] ?? [], true)) { $pts += slot_points($s); $shifts++; }
    }
    if ($pts > 0)    return ['points' => min($pts, $cap), 'shifts' => $shifts,
                             'reason' => 'eingeteilt (' . $shifts . ' Schicht' . ($shifts > 1 ? 'en' : '') . ')' . ($pts > $cap ? ', gedeckelt auf ' . $cap : '')];
    if (!$responded) return ['points' => -2, 'shifts' => 0, 'reason' => 'nicht abgestimmt'];
    if (!$avail)     return ['points' => 0,  'shifts' => 0, 'reason' => 'überall „nein" angegeben'];
    return ['points' => 0, 'shifts' => 0, 'reason' => 'verfügbar, aber nicht eingeteilt'];
}

/**
 * Eventscore je aktivem Mitglied über alle gewerteten Events (siehe scored_events()).
 * Bewertung pro Event/Mitglied über score_event_for_member(). Plus manueller Zuschlag (Vorsitz).
 * @return array<int,int> [member_id => score]
 */
function member_scores(?int $excludeEventId = null): array
{
    $scores = [];
    $joined = [];
    foreach (members_all() as $m) {
        // manueller Zuschlag (Vorsitz) + Startpunkte (Gruppenschnitt beim Beitritt – Neue starten im „Okay"-Band)
        $scores[(int)$m['id']] = (int)($m['score_adjust'] ?? 0) + (int)($m['score_start'] ?? 0);
        $joined[(int)$m['id']] = (string)($m['joined_at'] ?? ''); // Beitrittsdatum (leer = keine Sperre)
    }

    foreach (scored_events() as $e) {
        $eid = (int)$e['id'];
        if ($excludeEventId && $eid === $excludeEventId) continue;
        $slots = event_slots($eid);
        if (!$slots) continue;
        $resp = event_responses($eid);
        $assign = !empty($e['plan_locked']) ? event_assignments($eid) : []; // Entwürfe (unveröffentlicht) geben noch keine Schicht-Punkte
        $ownerIds = event_owner_ids($eid);
        foreach ($scores as $mid => $_) {
            $r = score_event_for_member($e, $slots, $resp, $assign, $mid, $joined[$mid], $ownerIds);
            if ($r) $scores[$mid] += $r['points'];
        }
    }
    return $scores;
}

function score_average(array $scores): float
{
    return $scores ? array_sum($scores) / count($scores) : 0.0;
}

/** 'gut' | 'okay' | 'ausbaufaehig' – relativ zum Gruppenschnitt. Asymmetrisch: Lob gibt es
 *  schon knapp überm Schnitt, „Ausbaufähig" gilt ab ZWEI DRITTELN des Schnitts oder weniger
 *  (und mind. 2 Punkten Abstand, damit bei Mini-Schnitten niemand vorschnell rot wird) –
 *  knapp unterm Schnitt zu liegen ist normal und bleibt „Okay". */
function score_band(int $score, float $avg): string
{
    if ($score >= $avg + max(1.0, abs($avg) * 0.15)) return 'gut';
    if ($score <= min($avg * 2 / 3, $avg - 2)) return 'ausbaufaehig';
    return 'okay';
}

/**
 * Member-IDs der „Spitzenklasse": die besten beiden nach Eventscore. Bei Gleichstand an der
 * Grenze zum 2. Platz sind alle dort gleichauf liegenden Mitglieder dabei (faire Gleichstandsregel).
 */
function score_top_ids(array $scores, int $topN = 2): array
{
    if (!$scores) return [];
    $vals = array_values($scores);
    rsort($vals);
    $cutoff = $vals[min($topN, count($vals)) - 1]; // Wert auf Platz $topN (bzw. der letzte, wenn weniger)
    $ids = [];
    foreach ($scores as $mid => $sc) if ($sc >= $cutoff) $ids[] = (int)$mid;
    return $ids;
}

/** Wie score_band(), zusätzlich Top-Tier 'spitze' (Spitzenklasse) für die besten beiden – aber nur,
 *  wenn sie überhaupt im „gut"-Bereich liegen (in einer flachen Gruppe wird niemand gekürt). */
function score_band_for(int $memberId, array $scores): string
{
    $band = score_band($scores[$memberId] ?? 0, score_average($scores));
    if ($band === 'gut' && in_array($memberId, score_top_ids($scores), true)) return 'spitze';
    return $band;
}

/**
 * Status-Ring am Avatar: der pulsierende Royal-Schein der Spitzenklasse – oder gar keiner.
 *
 * Die Regel steht hier und nicht in den Seiten, damit Profil und Dashboard nie auseinander-
 * laufen. $scores darf mitgegeben werden, wenn sie ohnehin schon berechnet sind (Dashboard) –
 * sonst holt sie die Funktion selbst.
 *
 * ES BLEIBT BEI GENAU EINEM RING: Streak und Schichten haben ihre eigenen Anzeigen, ein
 * zweiter oder dritter Reifen am Avatar wäre nur Betrieb. Der Selbsttest hält das fest.
 */
function avatar_ring_class(int $memberId, ?array $scores = null): string
{
    if ($memberId <= 0) return '';
    return score_band_for($memberId, $scores ?? member_scores()) === 'spitze' ? ' avatar-spitze' : '';
}

/** Was der Ring bedeutet – als Titel für den Avatar ('' wenn keiner). */
function avatar_ring_titel(string $ringCls): string
{
    return str_contains($ringCls, 'avatar-spitze') ? 'Spitzenklasse 👑' : '';
}

function score_band_label(string $band): string
{
    return ['spitze' => 'Spitzenklasse', 'gut' => 'Gut', 'okay' => 'Okay', 'ausbaufaehig' => 'Ausbaufähig'][$band] ?? 'Okay';
}

function score_band_icon(string $band): string
{
    return ['spitze' => 'ti-crown', 'gut' => 'ti-trophy', 'okay' => 'ti-thumb-up', 'ausbaufaehig' => 'ti-mood-sad'][$band] ?? 'ti-thumb-up';
}

/* ===================== Basis-Score ===================== *
 * Zweiter Score neben dem Eventscore: misst die „Grundpflichten" – nur MINUSPUNKTE,
 * 0 ist also einwandfrei. Kriterien (je seit dem letzten Reset, joined_at wird beachtet):
 *   1) Bericht zu einer berichtspflichtigen Sitzung vergessen ............ −1 je Sitzung
 *   2) Unentschuldigt gefehlt (trägt der Vorsitz nach) ................... −2 je Sitzung
 *   3) Eventscore aktuell im schlechten Bereich („Ausbaufähig") .......... −1
 *   4) App eine volle Woche nicht geöffnet (Abwesenheits-Wochen zählen nicht) −1 je Woche
 *   5) Wichtige Info nicht binnen 7 Tagen abgehakt ....................... −1 je Info
 *   6) Abstimmungsgegenstände einer Sitzung nicht gelesen ................ −1 je Sitzung
 * Dazu ein manueller Vorsitz-Zuschlag (members.basis_adjust, ±).
 * Einordnung: > −3 Einwandfrei · ab −3 Schlecht · ab −6 Gesprächsbedarf. */

/** Zählbeginn des Basis-Scores; wird beim ersten Aufruf auf heute gesetzt (Feature-Start),
 *  damit Alt-Daten von vor der Einführung niemandem angelastet werden. */
function basis_since(): string
{
    $s = (string)setting_get('basis_since', '');
    if ($s === '') { $s = date('Y-m-d'); setting_set('basis_since', $s); }
    return $s;
}

/** Einordnung des Basis-Scores: einwandfrei (> −3) | ausbaufaehig (ab −3) | gespraech (ab −6). */
function basis_band(int $score): string
{
    if ($score <= -6) return 'gespraech';
    if ($score <= -3) return 'ausbaufaehig';
    return 'einwandfrei';
}

function basis_band_label(string $band): string
{
    // Bewusst drastisch „Schlecht" statt „Ausbaufähig" – der interne
    // Band-Schlüssel 'ausbaufaehig' bleibt unverändert (steckt in Vergleichen/CSS-Werten).
    return ['einwandfrei' => 'Einwandfrei', 'ausbaufaehig' => 'Schlecht', 'gespraech' => 'Gesprächsbedarf'][$band] ?? 'Einwandfrei';
}

function basis_band_icon(string $band): string
{
    return ['einwandfrei' => 'ti-circle-check', 'ausbaufaehig' => 'ti-mood-sad', 'gespraech' => 'ti-message-exclamation'][$band] ?? 'ti-circle-check';
}

/** Unentschuldigt-gefehlt-Markierungen einer Sitzung (member_ids). */
function meeting_noshows(int $meetingId): array
{
    $st = db()->prepare('SELECT member_id FROM meeting_noshows WHERE meeting_id = ?');
    $st->execute([$meetingId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Setzt die Unentschuldigt-gefehlt-Liste einer Sitzung komplett neu (Vorsitz). */
function meeting_noshows_set(int $meetingId, array $memberIds): void
{
    db()->prepare('DELETE FROM meeting_noshows WHERE meeting_id = ?')->execute([$meetingId]);
    $ins = db()->prepare('INSERT OR IGNORE INTO meeting_noshows(meeting_id, member_id) VALUES(?,?)');
    foreach ($memberIds as $mid) if ((int)$mid > 0 && member_get((int)$mid)) $ins->execute([$meetingId, (int)$mid]);
}

/** Gelesen-Haken eines Mitglieds für Wichtige Infos: [info_id => read_at]. */
function info_reads_for(int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare('SELECT info_id, read_at FROM info_reads WHERE member_id = ?');
    $st->execute([$memberId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['info_id']] = (string)$r['read_at'];
    return $out;
}

/** Eine Wichtige Info als gelesen/erledigt abhaken (idempotent, erster Zeitpunkt zählt). */
function info_mark_read(int $infoId, int $memberId): void
{
    if ($infoId <= 0 || $memberId <= 0) return;
    db()->prepare('INSERT OR IGNORE INTO info_reads(info_id, member_id) VALUES(?,?)')->execute([$infoId, $memberId]);
}

/**
 * Gemeinsamer Daten-Kontext für die Basis-Score-Berechnung (einmal laden, für alle Mitglieder nutzen).
 */
function basis_context(?array $eventScores = null): array
{
    $since = basis_since();
    $meetPast = db()->prepare("SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa'
                               AND date(starts_at) >= ? AND datetime(starts_at) < datetime('now','localtime') ORDER BY starts_at");
    $meetPast->execute([$since]);
    $meetings = $meetPast->fetchAll();

    // Berichte je (referat, meeting) mit Inhalt
    $reports = [];
    foreach (db()->query('SELECT referat, meeting_id, content FROM reports')->fetchAll() as $r) {
        if (trim((string)$r['content']) !== '') $reports[(string)$r['referat']][(int)$r['meeting_id']] = true;
    }
    // Unentschuldigt gefehlt
    $noshows = [];
    foreach (db()->query('SELECT meeting_id, member_id FROM meeting_noshows')->fetchAll() as $r) {
        $noshows[(int)$r['member_id']][(int)$r['meeting_id']] = true;
    }
    // Abstimmungsgegenstände je Sitzung + Lese-Haken
    $voteItems = [];
    foreach (db()->query('SELECT id, meeting_id FROM vote_items')->fetchAll() as $r) $voteItems[(int)$r['meeting_id']][] = (int)$r['id'];
    $voteReads = [];
    foreach (db()->query('SELECT item_id, member_id FROM vote_item_reads')->fetchAll() as $r) $voteReads[(int)$r['member_id']][(int)$r['item_id']] = true;
    // Wichtige Infos (>7 Tage alt, seit Zählbeginn) + Gelesen-Haken
    $infoRows = db()->prepare("SELECT id, referat, created_at FROM infos WHERE date(created_at) >= ? AND datetime(created_at) <= datetime('now','localtime','-7 days')");
    $infoRows->execute([$since]);
    $infos = $infoRows->fetchAll();
    $infoReads = [];
    foreach (db()->query('SELECT info_id, member_id, read_at FROM info_reads')->fetchAll() as $r) {
        $infoReads[(int)$r['member_id']][(int)$r['info_id']] = (string)$r['read_at'];
    }
    // App-Wochen + Abwesenheiten
    $weeks = [];
    foreach (db()->query('SELECT member_id, week FROM activity_weeks')->fetchAll() as $r) $weeks[(int)$r['member_id']][(string)$r['week']] = true;
    $absences = [];
    foreach (db()->query('SELECT member_id, starts_at, ends_at FROM absences')->fetchAll() as $r) {
        $absences[(int)$r['member_id']][] = [(string)$r['starts_at'], (string)$r['ends_at']];
    }
    // Abgeschlossene Umlaufverfahren (nicht zurückgezogen) + wer abgestimmt hat – für den Teilnahme-Abzug.
    // VORZEITIG beendete zählen nicht: Wer bis zur Frist noch Zeit gehabt hätte, wird nicht dafür
    // bestraft, dass die Leitung früher abgebrochen hat (das Stimmungsbild war klar).
    $umStmt = db()->prepare("SELECT id, deadline, closed_at FROM circular_votes WHERE status = 'closed' AND date(deadline) >= ?");
    $umStmt->execute([$since]);
    $umlaeufe = array_values(array_filter($umStmt->fetchAll(),
        fn($u) => !vote_closed_early((string)$u['deadline'], $u['closed_at'] ?? null)));
    $umlaufBallots = [];
    foreach (db()->query('SELECT vote_id, member_id FROM circular_ballots')->fetchAll() as $r) {
        $umlaufBallots[(int)$r['member_id']][(int)$r['vote_id']] = true;
    }
    // Abgeschlossene VERPFLICHTENDE Abstimmungen + wer (mind. eine Option) gekreuzt hat
    $mpStmt = db()->prepare("SELECT id, deadline, closed_at,
                                    COALESCE(NULLIF(deadline, ''), closed_at, created_at) AS d FROM simple_polls
                             WHERE status = 'closed' AND mandatory = 1
                               AND date(COALESCE(NULLIF(deadline, ''), closed_at, created_at)) >= ?");
    $mpStmt->execute([$since]);
    $mandPolls = array_values(array_filter($mpStmt->fetchAll(),   // vorzeitig beendete zählen nicht, siehe oben
        fn($p) => !vote_closed_early((string)$p['deadline'], $p['closed_at'] ?? null)));
    $pollVotes = [];
    foreach (db()->query('SELECT DISTINCT o.poll_id, v.member_id FROM simple_poll_votes v JOIN simple_poll_options o ON o.id = v.option_id')->fetchAll() as $r) {
        $pollVotes[(int)$r['member_id']][(int)$r['poll_id']] = true;
    }
    // Eventscore-Bänder (Kriterium 3) – übergeben, wenn die Seite sie schon berechnet hat
    $eventScores ??= member_scores();

    return ['since' => $since, 'meetings' => $meetings, 'reports' => $reports, 'noshows' => $noshows,
            'vote_items' => $voteItems, 'vote_reads' => $voteReads, 'infos' => $infos, 'info_reads' => $infoReads,
            'weeks' => $weeks, 'absences' => $absences, 'event_scores' => $eventScores,
            'umlaeufe' => $umlaeufe, 'umlauf_ballots' => $umlaufBallots, 'mand_polls' => $mandPolls, 'poll_votes' => $pollVotes];
}

/**
 * Basis-Score-Aufschlüsselung eines Mitglieds: nur Minuspunkte + manueller Zuschlag.
 * @return array{rows: array<int, array{reason: string, points: int}>, adjust: int, total: int}
 */
function member_basis_breakdown(array $member, ?array $ctx = null): array
{
    $ctx ??= basis_context();
    $mid = (int)$member['id'];
    $joined = (string)($member['joined_at'] ?? '');
    $referat = trim((string)($member['referat'] ?? ''));
    $rows = [];

    // 1) Berichte vergessen (nur berichtspflichtige Mitglieder = mit Referat)
    if ($referat !== '') {
        foreach ($ctx['meetings'] as $m) {
            if (empty($m['needs_report'])) continue;
            $day = substr((string)$m['starts_at'], 0, 10);
            if ($joined !== '' && $day < $joined) continue;
            if (empty($ctx['reports'][$referat][(int)$m['id']])) {
                $rows[] = ['reason' => 'Bericht fehlte: ' . meeting_label($m) . ' am ' . fmt_date($day), 'points' => -1];
            }
        }
    }

    // 2) Unentschuldigt gefehlt (vom Vorsitz nachgetragen)
    foreach ($ctx['meetings'] as $m) {
        if (empty($ctx['noshows'][$mid][(int)$m['id']])) continue;
        $rows[] = ['reason' => 'Unentschuldigt gefehlt: ' . meeting_label($m) . ' am ' . fmt_date(substr((string)$m['starts_at'], 0, 10)), 'points' => -2];
    }

    // 3) Eventscore im schlechten Bereich
    if (score_band_for($mid, $ctx['event_scores']) === 'ausbaufaehig') {
        $rows[] = ['reason' => 'Eventscore aktuell im Bereich „Ausbaufähig"', 'points' => -1];
    }

    // 4) App wöchentlich geöffnet: volle ISO-Wochen zwischen Zählbeginn/Beitritt und letzter Woche
    $floor = max($ctx['since'], $joined !== '' ? $joined : $ctx['since']);
    $fw = strtotime($floor);
    $mon = strtotime('monday this week', $fw);          // Montag der Stichtags-Woche …
    if ($mon < $fw) $mon = strtotime('+1 week', $mon);  // … angebrochene Woche überspringen
    $today = strtotime('today');
    $missed = 0;
    for ($w = $mon; $w !== false && $w + 6 * 86400 < $today; $w = strtotime('+1 week', $w)) { // nur abgeschlossene Wochen
        $wk = date('o-\WW', $w);
        if (!empty($ctx['weeks'][$mid][$wk])) continue;
        $wStart = date('Y-m-d', $w); $wEnd = date('Y-m-d', $w + 6 * 86400);
        $excused = false;
        foreach ($ctx['absences'][$mid] ?? [] as [$a, $b]) {
            if ($a <= $wEnd && $b >= $wStart) { $excused = true; break; } // Abwesenheit überlappt die Woche
        }
        if (!$excused) $missed++;
    }
    if ($missed > 0) $rows[] = ['reason' => 'App ' . $missed . ' Woche' . ($missed > 1 ? 'n' : '') . ' nicht geöffnet', 'points' => -$missed];

    // 5) Wichtige Infos binnen 7 Tagen gelesen (der Haken wird beim Seitenbesuch gesetzt);
    //    Infos vor dem Amnestie-Stichtag zählen nicht (Umstellung vom Abhak-Knopf auf Auto-Gelesen)
    $infoCutoff = trim((string)setting_get('basis_info_cutoff', ''));
    $lateInfos = 0;
    foreach ($ctx['infos'] as $inf) {
        $iRef = trim((string)($inf['referat'] ?? ''));
        if ($iRef !== '' && $iRef !== $referat) continue;                 // nicht für diese Person sichtbar
        $created = (string)$inf['created_at'];
        if ($joined !== '' && substr($created, 0, 10) < $joined) continue;
        if ($infoCutoff !== '' && substr($created, 0, 10) < $infoCutoff) continue; // vor dem Stichtag: zählt nicht
        $read = $ctx['info_reads'][$mid][(int)$inf['id']] ?? '';
        if ($read === '' || strtotime($read) > strtotime($created) + 7 * 86400) $lateInfos++;
    }
    if ($lateInfos > 0) $rows[] = ['reason' => 'Wichtige Infos nicht binnen 7 Tagen gelesen (' . $lateInfos . '×)', 'points' => -$lateInfos];

    // 6) Abstimmungsgegenstände gelesen (je vergangener Sitzung mit Gegenständen)
    $unreadMeetings = 0;
    foreach ($ctx['meetings'] as $m) {
        $items = $ctx['vote_items'][(int)$m['id']] ?? [];
        if (!$items) continue;
        $day = substr((string)$m['starts_at'], 0, 10);
        if ($joined !== '' && $day < $joined) continue;
        foreach ($items as $iid) {
            if (empty($ctx['vote_reads'][$mid][$iid])) { $unreadMeetings++; break; }
        }
    }
    if ($unreadMeetings > 0) $rows[] = ['reason' => 'Abstimmungsgegenstände nicht gelesen (' . $unreadMeetings . ' Sitzung' . ($unreadMeetings > 1 ? 'en' : '') . ')', 'points' => -$unreadMeetings];

    // 7) An abgeschlossenen Umlaufverfahren nicht teilgenommen (−2 je Umlauf; erst nach dem Umlauf Beigetretene sind raus)
    $missedUml = 0;
    foreach ($ctx['umlaeufe'] ?? [] as $u) {
        if ($joined !== '' && substr((string)$u['deadline'], 0, 10) < $joined) continue;
        if (empty($ctx['umlauf_ballots'][$mid][(int)$u['id']])) $missedUml++;
    }
    if ($missedUml > 0) $rows[] = ['reason' => 'An Umlaufverfahren nicht teilgenommen (' . $missedUml . '×)', 'points' => -2 * $missedUml];

    // 8) An verpflichtender Abstimmung nicht teilgenommen (−1 je Abstimmung)
    $missedPoll = 0;
    foreach ($ctx['mand_polls'] ?? [] as $p) {
        if ($joined !== '' && substr((string)$p['d'], 0, 10) < $joined) continue;
        if (empty($ctx['poll_votes'][$mid][(int)$p['id']])) $missedPoll++;
    }
    if ($missedPoll > 0) $rows[] = ['reason' => 'An verpflichtender Abstimmung nicht teilgenommen (' . $missedPoll . '×)', 'points' => -$missedPoll];

    $adjust = (int)($member['basis_adjust'] ?? 0);
    $total = array_sum(array_column($rows, 'points')) + $adjust;
    return ['rows' => $rows, 'adjust' => $adjust, 'total' => $total];
}

/** Basis-Scores aller aktiven Mitglieder: [member_id => ['total'=>int,'rows'=>…,'adjust'=>int]]. */
function basis_scores(): array
{
    $ctx = basis_context();
    $out = [];
    foreach (members_all() as $m) $out[(int)$m['id']] = member_basis_breakdown($m, $ctx);
    return $out;
}

/**
 * Nachvollziehbare Aufschlüsselung des Scores eines Mitglieds:
 * pro gewertetem Event Grund + Punkte, plus manuelle Korrektur.
 * @return array{rows:array<int,array{id:int,title:string,date:string,reason:string,points:int}>, adjust:int, total:int}
 */
function member_score_breakdown(int $memberId): array
{
    $joinedAt = (string)(db()->query('SELECT joined_at FROM members WHERE id = ' . (int)$memberId)->fetchColumn() ?: '');

    $rows = [];
    $total = 0;
    foreach (scored_events('deadline DESC') as $e) {
        $eid = (int)$e['id'];
        $slots = event_slots($eid);
        if (!$slots) continue;
        $resp = event_responses($eid);
        $assign = !empty($e['plan_locked']) ? event_assignments($eid) : []; // Entwürfe (unveröffentlicht) geben noch keine Schicht-Punkte
        $r = score_event_for_member($e, $slots, $resp, $assign, $memberId, $joinedAt, event_owner_ids($eid));
        if (!$r) continue; // Event zählt für dieses Mitglied nicht (vor dem Beitritt)
        $rows[] = ['id' => $eid, 'title' => (string)$e['title'], 'date' => (string)$e['deadline'], 'reason' => $r['reason'], 'points' => $r['points']];
        $total += $r['points'];
    }
    $mRow   = member_get($memberId) ?? [];
    $adjust = (int)($mRow['score_adjust'] ?? 0);
    $start  = (int)($mRow['score_start'] ?? 0); // Startpunkte (Gruppenschnitt beim Beitritt)
    return ['rows' => $rows, 'adjust' => $adjust, 'start' => $start, 'total' => $total + $adjust + $start];
}

// ---------------------------------------------------------------------------
// Sitzungen & Legislaturperioden
// ---------------------------------------------------------------------------
function legislatures_all(): array
{
    return db()->query('SELECT * FROM legislatures ORDER BY start_date DESC, id DESC')->fetchAll();
}

function legislature_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM legislatures WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Die Legislaturperiode einer Sitzung – abgeleitet aus ihrem DATUM, nicht von Hand zugewiesen.
 *
 * Eine Legislaturperiode IST ein Zeitraum – die Zugehörigkeit ergibt sich aus dem Sitzungsdatum.
 * Eine Spalte an der Sitzung wäre doppelte Buchführung und könnte leer bleiben; die Sitzung
 * hieße dann überall „Ordentliche Sitzung" statt „12. Ordentliche Sitzung", ohne Warnung.
 *
 * Es zählt die Periode mit dem größten Beginn, der nicht nach der Sitzung liegt.
 * Eine Periode ohne Beginn kann nichts einsammeln – deshalb ist das Datum beim Anlegen Pflicht.
 */
function legislature_for(string $startsAt): ?array
{
    $tag = substr(trim($startsAt), 0, 10);
    if ($tag === '') return null;
    // BEWUSST ohne Zwischenspeicher: Ein Cache hielte die ganze Zeile – samt start_number. Genau
    // die wird beim Anlegen einer Serie im selben Aufruf geändert, bevor die Sitzungen entstehen;
    // mit Cache hätte die App danach noch mit dem alten Versatz gerechnet. Die Abfrage ist klein.
    $st = db()->prepare("SELECT * FROM legislatures WHERE start_date <> '' AND start_date <= ?
                         ORDER BY start_date DESC, id DESC LIMIT 1");
    $st->execute([$tag]);
    return $st->fetch() ?: null;
}

/** Kurzform: die ID der abgeleiteten Periode (0 = keine passende). */
function legislature_id_for(string $startsAt): int
{
    return (int)((legislature_for($startsAt)['id'] ?? 0));
}

/**
 * Ende einer Sitzung – IMMER das Tagesende. Sitzungen haben keinen Schlusszeitpunkt; sie laufen
 * bis zum Ende des Tages, und genau dieses „23:59:59" ist zugleich das Kennzeichen für das
 * offene Ende in der Anzeige (is_open_end()).
 *
 * Bewusst beim Lesen abgeleitet statt auf die Spalte zu vertrauen: Der Wert wurde an zwei Stellen
 * geschrieben, und ical.php hat ihm ohnehin nie getraut (`$m['ends_at'] ?: day_end_for(...)`).
 * Ein gespeicherter Wert, der immer gleich berechnet wird und dem niemand traut, ist nur eine
 * Gelegenheit auseinanderzulaufen.
 */
function meeting_ends_at(array $m): string
{
    return day_end_for((string)($m['starts_at'] ?? ''));
}

/** Zeitraum einer Periode: [Beginn 'Y-m-d', Ende exklusiv 'Y-m-d' oder null]. */
function legislature_range(array $leg): array
{
    $von = substr((string)($leg['start_date'] ?? ''), 0, 10);
    if ($von === '') return ['', null];
    $st = db()->prepare("SELECT start_date FROM legislatures WHERE start_date <> '' AND start_date > ?
                         ORDER BY start_date LIMIT 1");
    $st->execute([$von]);
    $bis = $st->fetchColumn();
    return [$von, $bis === false ? null : substr((string)$bis, 0, 10)];
}

/**
 * Ist dieser Beginn schon von einer anderen Periode belegt?
 *
 * Zwei Perioden mit demselben Beginn wären eine Sackgasse: legislature_for() nimmt die zuletzt
 * angelegte, die andere bekäme nie eine Sitzung – sähe in der Verwaltung aber genauso aus. Seit
 * der Beginn die Zugehörigkeit BESTIMMT (statt sie nur zu dokumentieren), muss er eindeutig sein.
 */
function legislature_date_taken(string $date, int $exceptId = 0): bool
{
    $tag = substr(trim($date), 0, 10);
    if ($tag === '') return false;
    $st = db()->prepare('SELECT COUNT(*) FROM legislatures WHERE start_date = ? AND id <> ?');
    $st->execute([$tag, $exceptId]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Laufende Nummer einer nummerierten Sitzung in ihrer Legislatur (oder null).
 * Nummeriert werden ordentliche AStA-Sitzungen ('ordentlich') und StuPa-Sitzungen
 * ('stupa') – jeweils in einer EIGENEN Zählung pro Legislatur. Der Legislatur-Versatz
 * (start_number) gilt nur für AStA-Sitzungen; StuPa-Sitzungen zählen ab 1.
 */
function meeting_number(array $m): ?int
{
    $kind = $m['kind'] ?? 'ordentlich';
    if (!in_array($kind, ['ordentlich', 'stupa'], true) || !empty($m['cancelled'])) return null;
    // Entwürfe haben noch keinen Platz in der Zählung – sie zählen andere nicht mit und sich selbst
    // auch nicht, eine Nummer wäre also um eins zu klein (zwei Entwürfe hießen beide „Nr. 9").
    // Die Nummer entsteht mit dem Freigeben.
    if (!empty($m['draft'])) return null;
    // Die Periode kommt aus dem Datum (legislature_for) – nicht mehr aus einer Spalte, die man
    // beim Anlegen vergessen konnte. Liegt die Sitzung vor der ersten Periode, gibt es keine Nummer.
    $leg = legislature_for((string)($m['starts_at'] ?? ''));
    if (!$leg) return null;
    [$von, $bis] = legislature_range($leg);
    if ($von === '') return null;

    // Gezählt wird über den ZEITRAUM der Periode, nicht über eine Zuordnungs-Spalte.
    $sql = "SELECT COUNT(*) FROM meetings
            WHERE kind = :kind AND cancelled = 0 AND draft = 0
              AND date(starts_at) >= :von"
         . ($bis !== null ? ' AND date(starts_at) < :bis' : '')
         . " AND (starts_at < :s OR (starts_at = :s AND id <= :id))";
    $par = [':kind' => $kind, ':von' => $von, ':s' => $m['starts_at'], ':id' => (int)$m['id']];
    if ($bis !== null) $par[':bis'] = $bis;
    $st = db()->prepare($sql);
    $st->execute($par);
    $position = (int)$st->fetchColumn();
    if ($kind === 'stupa') return $position; // StuPa: eigene Zählung ab 1, ohne AStA-Startversatz
    // Startnummer der Periode als Versatz (z. B. erste erfasste Sitzung ist die Nr. 7)
    return $position + max(1, (int)($leg['start_number'] ?? 1)) - 1;
}

/** Anzeigename einer Sitzung (mit Nummer/Art). */
function meeting_label(array $m): string
{
    $title = trim((string)($m['title'] ?? ''));
    switch ($m['kind'] ?? 'ordentlich') {
        case 'ausserordentlich':
            return $title !== '' ? 'Außerordentliche Sitzung – ' . $title : 'Außerordentliche Sitzung';
        case 'stupa':
            $ns = meeting_number($m);
            $baseS = $ns ? $ns . '. StuPa-Sitzung' : 'StuPa-Sitzung';
            return $title !== '' ? $baseS . ' – ' . $title : $baseS;
        case 'sonstige':
            return $title !== '' ? $title : 'Sitzung';
        default: // ordentlich
            $n = meeting_number($m);
            $base = $n ? $n . '. Ordentliche Sitzung' : 'Ordentliche Sitzung';
            return $title !== '' ? $base . ' – ' . $title : $base;
    }
}

/** Standard-Tagesordnung (Default, falls keine eigene gespeichert ist). */
function default_agenda(): string
{
    return "Begrüßung und Feststellung der Beschlussfähigkeit\n"
        . "Genehmigung der Tagesordnung\n"
        . "Genehmigung des letzten Protokolls\n"
        . "Berichte der Referate\n"
        . "Finanzanträge\n"
        . "Sonstiges\n"
        . "[INTERN]\n"
        . "Sonstiges (intern)";
}

/**
 * Ist das der Sammel-TOP am Ende seines Teils? Eingereichte TOPs ohne gewählten Platz landen
 * davor. Der Zusatz in Klammern darf mitwandern („Sonstiges (intern)"), damit die Regel nicht
 * an der Schreibweise hängt. ACHTUNG: Zwei TOPs mit demselben Text teilen sich ihren Schlüssel
 * aus base_top_key() – „keine Redeliste" träfe dann beide. Deshalb heißt der interne anders.
 */
function agenda_is_sonstiges(string $text): bool
{
    return str_starts_with(mb_strtolower(trim($text)), 'sonstiges');
}

/** Aktuelle Standard-TO (gespeichert in den Einstellungen, sonst Default). */
function agenda_template(): string
{
    $t = (string)setting_get('agenda_template', '');
    return trim($t) !== '' ? $t : default_agenda();
}

/** Effektive Tagesordnung einer Sitzung: eigene, sonst Standard-TO. */
function meeting_agenda(array $m): string
{
    $own = trim((string)($m['agenda'] ?? ''));
    return $own !== '' ? $own : agenda_template();
}


/**
 * Tagesordnung als Baum mit einer Unter-Ebene.
 * Unterpunkte sind mit führendem Tab (oder ≥2 Leerzeichen / „- ") markiert.
 * Gibt [['text'=>..., 'sub'=>bool], ...] zurück (sub nur gültig nach einem Hauptpunkt).
 */
function agenda_tree(string $text): array
{
    $out = []; $haveMain = false; $internal = false;
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $trim = trim((string)$line);
        if ($trim === '') continue;
        if (strcasecmp($trim, '[INTERN]') === 0) { $internal = true; continue; } // Trenner zum internen Teil
        $sub = (bool)preg_match('/^(\t| {2,}|[-•]\s)/', $line);
        $t = trim(preg_replace('/^(\t+| +|[-•]\s)/', '', $line));
        if ($t === '') continue;
        $time = '';
        $pipe = strpos($t, ' | ');           // optionale Zeitangabe nach „ | "
        if ($pipe !== false) { $time = trim(substr($t, $pipe + 3)); $t = trim(substr($t, 0, $pipe)); }
        if ($t === '') continue;
        $isSub = $sub && $haveMain;       // ein Unterpunkt braucht einen Hauptpunkt davor
        if (!$isSub) $haveMain = true;
        $out[] = ['text' => $t, 'sub' => $isSub, 'internal' => $internal, 'time' => $time];
    }
    return $out;
}

/** Eingereichte TOPs einer Sitzung (mit Name der einreichenden Person). */
function submissions_for(int $meetingId): array
{
    $st = db()->prepare(
        'SELECT s.*, m.name AS member_name FROM top_submissions s
         LEFT JOIN members m ON m.id = s.member_id
         WHERE s.meeting_id = ? ORDER BY s.id'
    );
    $st->execute([$meetingId]);
    return $st->fetchAll();
}

/**
 * Tagesordnung + eingereichte TOPs zu einer Anzeige-Liste verschmelzen.
 * Eingereichte TOPs sitzen an ihrem Anker (vor Basis-Punkt N); ohne Anker landen sie
 * automatisch vor „Sonstiges" (öffentlich) bzw. am Ende des internen Teils.
 * Rückgabe: [['text','sub','internal','sub_id','submitter'], ...]
 */
/** Normalisierter Schlüssel eines Basis-TOPs (für die „nicht in Redeliste"-Liste). */
function base_top_key(string $text): string
{
    return mb_strtolower(trim($text));
}

/** JSON-Liste normalisierter TOP-Schlüssel in eine Menge (Schlüssel => true) umwandeln. */
function skip_keys_from_json(?string $json): array
{
    $a = json_decode((string)$json, true);
    if (!is_array($a)) return [];
    $out = [];
    foreach ($a as $s) { $k = base_top_key((string)$s); if ($k !== '') $out[$k] = true; }
    return $out;
}

/** Menge der Basis-TOPs einer Sitzung, die nicht in die Redeliste sollen (nur sitzungseigen). */
function meeting_skip_set(array $m): array
{
    return skip_keys_from_json((string)($m['redeliste_skip'] ?? ''));
}

/** Globale „nicht in die Redeliste"-TOPs der Standard-Tagesordnung. */
function agenda_template_skip_set(): array
{
    return skip_keys_from_json((string)setting_get('agenda_template_skip', ''));
}

/**
 * Wirksame Skip-Menge einer Sitzung: sitzungseigen, plus – wenn die Sitzung die Standard-TO
 * nutzt (keine eigene gepflegt) – die global in der Standard-TO ausgenommenen TOPs.
 */
function effective_skip_set(array $m): array
{
    $set = meeting_skip_set($m);
    if (trim((string)($m['agenda'] ?? '')) === '') {
        foreach (agenda_template_skip_set() as $k => $_) $set[$k] = true;
    }
    return $set;
}

/** Menge der Basis-TOPs einer Sitzung, die „Berichte-TOPs" sind (nur sitzungseigen). */
function meeting_report_set(array $m): array
{
    return skip_keys_from_json((string)($m['redeliste_report'] ?? ''));
}

/** Globale „Berichte-TOPs" der Standard-Tagesordnung. */
function agenda_template_report_set(): array
{
    return skip_keys_from_json((string)setting_get('agenda_template_report', ''));
}

/**
 * Wirksame Berichte-TOP-Menge einer Sitzung – analog zur Skip-Menge: sitzungseigen, plus die
 * Standard-TO-Vorgaben, wenn die Sitzung keine eigene Tagesordnung pflegt.
 * Ein Berichte-TOP betrifft NUR die Redeliste (eigene Untertops je Bericht), nichts im Protokoll/App.
 */
function effective_report_set(array $m): array
{
    $set = meeting_report_set($m);
    if (trim((string)($m['agenda'] ?? '')) === '') {
        foreach (agenda_template_report_set() as $k => $_) $set[$k] = true;
    }
    return $set;
}

function merged_agenda(array $m): array
{
    $base = agenda_tree(meeting_agenda($m));
    $subs = submissions_for((int)$m['id']);
    $skip = effective_skip_set($m); // sitzungseigen + Standard-TO-Vorgaben
    $report = effective_report_set($m); // Berichte-TOPs (nur Redeliste)
    $n = count($base);

    // Je Teil das „Sonstiges" suchen: Beide Teile sammeln Neues davor ein, damit der Punkt seine
    // Rolle behält – er ist der Rest, nicht der vorletzte Tagesordnungspunkt.
    $sonst = null; $sonstInt = null; $firstInt = null;
    foreach ($base as $i => $it) {
        if ($firstInt === null && !empty($it['internal'])) $firstInt = $i;
        if (!agenda_is_sonstiges((string)$it['text'])) continue;
        if (empty($it['internal'])) { if ($sonst === null) $sonst = $i; }
        elseif ($sonstInt === null) { $sonstInt = $i; }
    }
    $byAnchor = [];
    foreach ($subs as $s) {
        $a = $s['anchor'];
        if ($a === null || $a === '') {
            // intern: vor „Sonstiges (intern)", sonst ans Ende – öffentlich: vor „Sonstiges"
            if ((int)$s['internal'] === 1) $a = $sonstInt !== null ? $sonstInt : $n;
            else $a = $sonst !== null ? $sonst : ($firstInt !== null ? $firstInt : $n);
        }
        $a = max(0, min($n, (int)$a));
        $byAnchor[$a][] = $s;
    }

    $out = [];
    for ($i = 0; $i <= $n; $i++) {
        foreach ($byAnchor[$i] ?? [] as $s) {
            $out[] = ['text' => $s['title'], 'sub' => false, 'internal' => (int)$s['internal'] === 1,
                      'time' => (string)($s['time_est'] ?? ''), 'no_list' => (int)($s['no_redeliste'] ?? 0) === 1,
                      'sub_id' => (int)$s['id'], 'submitter' => (string)($s['member_name'] ?? '')];
        }
        if ($i < $n) {
            $key = base_top_key($base[$i]['text']);
            $isReport = isset($report[$key]);                 // Berichte-TOP ist ein Rede-TOP → niemals no_list
            $out[] = ['text' => $base[$i]['text'], 'sub' => $base[$i]['sub'], 'internal' => $base[$i]['internal'],
                      'time' => (string)($base[$i]['time'] ?? ''), 'no_list' => !$isReport && isset($skip[$key]),
                      'report' => $isReport, 'sub_id' => 0, 'submitter' => ''];
        }
    }
    return $out;
}

/** Nächste sichtbare, nicht ausgefallene AStA-Sitzung ab heute (oder null). StuPa-Sitzungen zählen hier nicht. */
function next_meeting(): ?array
{
    $st = db()->query("SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa' AND date(starts_at) >= date('now','localtime') ORDER BY starts_at LIMIT 1");
    return $st->fetch() ?: null;
}

// ---------------------------------------------------------------------------
// Persönliche Kalender-Einstellungen & freie Termine
// ---------------------------------------------------------------------------
/** Blendet dieses Mitglied StuPa-Sitzungen aus (App-Kalender + eigenes iCal-Abo)? */
function member_hides_stupa(?array $m): bool
{
    return $m ? !empty($m['hide_stupa']) : false;
}

/** Persönliche „StuPa ausblenden"-Einstellung setzen. */
function member_set_hide_stupa(int $memberId, bool $hide): void
{
    if ($memberId <= 0) return;
    db()->prepare('UPDATE members SET hide_stupa = ? WHERE id = ?')->execute([$hide ? 1 : 0, $memberId]);
}

/** Blendet dieses Mitglied die Geburtstage der anderen im App-Kalender aus? */
function member_hides_birthdays(?array $m): bool
{
    return $m ? !empty($m['hide_birthdays']) : false;
}

/** Persönliche „Geburtstage ausblenden"-Einstellung setzen. */
function member_set_hide_birthdays(int $memberId, bool $hide): void
{
    if ($memberId <= 0) return;
    db()->prepare('UPDATE members SET hide_birthdays = ? WHERE id = ?')->execute([$hide ? 1 : 0, $memberId]);
}

/** Gültige Zielgruppen eines freien Termins. */
function termin_audiences(): array
{
    return ['self' => 'Nur für mich', 'members' => 'Für bestimmte Personen', 'all' => 'Für alle'];
}

/** Mitglieds-IDs, für die ein „members"-Termin sichtbar ist. */
function termin_member_ids(int $terminId): array
{
    if ($terminId <= 0) return [];
    $st = db()->prepare('SELECT member_id FROM termin_members WHERE termin_id = ?');
    $st->execute([$terminId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Freien Termin anlegen. $memberIds gilt nur bei audience='members'.
 * Gibt die neue ID zurück (oder 0 bei Fehler).
 */
function termin_create(int $creatorId, string $title, string $startsAt, ?string $endsAt, bool $allDay, string $location, string $description, string $audience, array $memberIds = []): int
{
    if ($creatorId <= 0 || $startsAt === '') return 0;
    if (!array_key_exists($audience, termin_audiences())) $audience = 'self';
    db()->prepare('INSERT INTO termine(title, description, location, starts_at, ends_at, all_day, audience, created_by) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$title, $description, $location, $startsAt, $endsAt ?: null, $allDay ? 1 : 0, $audience, $creatorId]);
    $id = (int)db()->lastInsertId();
    if ($audience === 'members' && $memberIds) {
        $ins = db()->prepare('INSERT OR IGNORE INTO termin_members(termin_id, member_id) VALUES(?,?)');
        foreach (array_unique(array_map('intval', $memberIds)) as $mid) {
            if ($mid > 0 && $mid !== $creatorId) $ins->execute([$id, $mid]);
        }
    }
    return $id;
}

/** Einen Termin holen (oder null). */
function termin_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM termine WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Darf dieses Mitglied den Termin löschen/bearbeiten? (Ersteller:in oder Admin) */
function termin_can_manage(array $t, ?array $me): bool
{
    if (!$me) return false;
    return (int)$t['created_by'] === (int)$me['id'] || !empty($me['is_admin']);
}

/** Termin löschen (nur Ersteller:in oder Admin). Gibt true bei Erfolg. */
function termin_delete(int $id, ?array $me): bool
{
    $t = termin_get($id);
    if (!$t || !termin_can_manage($t, $me)) return false;
    db()->prepare('DELETE FROM termine WHERE id = ?')->execute([$id]);
    return true;
}

/**
 * Für ein Mitglied sichtbare freie Termine, die den Zeitraum [$from,$to] berühren.
 * Sichtbar: eigene Termine, 'all'-Termine und 'members'-Termine, in denen man steht.
 */
function termine_in_range(int $meId, string $from, string $to): array
{
    $st = db()->prepare(
        "SELECT DISTINCT t.* FROM termine t
         LEFT JOIN termin_members tm ON tm.termin_id = t.id AND tm.member_id = :me
         WHERE (t.audience = 'all' OR t.created_by = :me2 OR tm.member_id IS NOT NULL)
           AND date(t.starts_at) <= :to
           AND date(COALESCE(NULLIF(t.ends_at, ''), t.starts_at)) >= :from
         ORDER BY t.starts_at"
    );
    $st->execute([':me' => $meId, ':me2' => $meId, ':from' => $from, ':to' => $to]);
    return $st->fetchAll();
}

/** Für ein Mitglied sichtbare freie Termine ab heute (für iCal-Abo & Übersichten). */
function termine_visible_upcoming(int $meId): array
{
    $st = db()->prepare(
        "SELECT DISTINCT t.* FROM termine t
         LEFT JOIN termin_members tm ON tm.termin_id = t.id AND tm.member_id = :me
         WHERE (t.audience = 'all' OR t.created_by = :me2 OR tm.member_id IS NOT NULL)
           AND date(COALESCE(NULLIF(t.ends_at, ''), t.starts_at)) >= date('now','localtime')
         ORDER BY t.starts_at"
    );
    $st->execute([':me' => $meId, ':me2' => $meId]);
    return $st->fetchAll();
}

/** Vom Mitglied selbst angelegte, kommende Termine (zum Verwalten unter dem Kalender). */
function termine_mine_upcoming(int $meId): array
{
    $st = db()->prepare(
        "SELECT * FROM termine WHERE created_by = ?
           AND date(COALESCE(NULLIF(ends_at, ''), starts_at)) >= date('now','localtime')
         ORDER BY starts_at"
    );
    $st->execute([$meId]);
    return $st->fetchAll();
}

/** Kurzes Zielgruppen-Label eines Termins (für Chips/Listen). */
function termin_audience_label(array $t): string
{
    switch ($t['audience'] ?? 'self') {
        case 'all':     return 'Für alle';
        case 'members': return 'Für ausgewählte Personen';
        default:        return 'Nur für mich';
    }
}

// ---------------------------------------------------------------------------
// Terminfinder (Doodle-artige Terminabstimmung)
// ---------------------------------------------------------------------------
/** Eine Terminabstimmung holen (oder null). */
function date_poll_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM date_polls WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Datumsoptionen einer Abstimmung (chronologisch). */
function date_poll_options(int $pollId): array
{
    $st = db()->prepare('SELECT * FROM date_poll_options WHERE poll_id = ? ORDER BY starts_at, id');
    $st->execute([$pollId]);
    return $st->fetchAll();
}

/**
 * Terminabstimmung anlegen. $optionStarts = Liste normalisierter „Y-m-d H:i"-Starts.
 * Gibt die neue Poll-ID zurück (oder 0 bei Fehler / ohne gültige Option).
 */
function date_poll_create(int $creatorId, string $title, string $description, string $location, array $optionStarts, ?string $deadline = null): int
{
    $title = trim($title);
    $opts = [];
    foreach ($optionStarts as $s) { $s = trim((string)$s); if ($s !== '') $opts[$s] = true; } // Duplikate raus
    if ($creatorId <= 0 || $title === '' || !$opts) return 0;
    db()->prepare('INSERT INTO date_polls(title, description, location, created_by, deadline) VALUES(?,?,?,?,?)')
        ->execute([$title, trim($description), trim($location), $creatorId, ($deadline !== null && $deadline !== '') ? $deadline : null]);
    $id = (int)db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO date_poll_options(poll_id, starts_at, sort) VALUES(?,?,?)');
    $i = 0;
    foreach (array_keys($opts) as $s) $ins->execute([$id, $s, $i++]);
    return $id;
}

/** Darf dieses Mitglied die Abstimmung verwalten (Optionen/schließen/löschen)? Ersteller:in oder Admin. */
function date_poll_can_manage(array $p, ?array $me): bool
{
    if (!$me) return false;
    return (int)$p['created_by'] === (int)$me['id'] || !empty($me['is_admin']);
}

/** Läuft die Abstimmung noch (nicht manuell geschlossen UND „Abstimmen bis" nicht abgelaufen)? */
function date_poll_voting_open(array $p): bool
{
    if (!empty($p['closed'])) return false;
    $dl = trim((string)($p['deadline'] ?? ''));
    if ($dl === '') return true;
    if (strlen($dl) <= 10) $dl .= ' 23:59';       // reines Datum → bis Tagesende
    return date('Y-m-d H:i') <= substr($dl, 0, 16); // minutengenau (deadline hat keine Sekunden)
}

/** „Abstimmen bis"-Frist ist gesetzt UND bereits abgelaufen (aber nicht manuell geschlossen). */
function date_poll_deadline_passed(array $p): bool
{
    return empty($p['closed']) && trim((string)($p['deadline'] ?? '')) !== '' && !date_poll_voting_open($p);
}

/** Abstimmung löschen (nur Ersteller:in/Admin). */
function date_poll_delete(int $id, ?array $me): bool
{
    $p = date_poll_get($id);
    if (!$p || !date_poll_can_manage($p, $me)) return false;
    db()->prepare('DELETE FROM date_polls WHERE id = ?')->execute([$id]);
    return true;
}

/** Abstimmung schließen/wieder öffnen. */
function date_poll_set_closed(int $id, bool $closed): void
{
    db()->prepare('UPDATE date_polls SET closed = ? WHERE id = ?')->execute([$closed ? 1 : 0, $id]);
}

/** Eine weitere Datumsoption zu einer laufenden Abstimmung hinzufügen. */
function date_poll_add_option(int $pollId, string $startsAt): void
{
    $startsAt = trim($startsAt);
    if ($pollId <= 0 || $startsAt === '') return;
    $max = db()->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM date_poll_options WHERE poll_id = ?');
    $max->execute([$pollId]);
    db()->prepare('INSERT INTO date_poll_options(poll_id, starts_at, sort) VALUES(?,?,?)')
        ->execute([$pollId, $startsAt, (int)$max->fetchColumn()]);
}

/** Eine Datumsoption entfernen (inkl. ihrer Stimmen per Kaskade). */
function date_poll_remove_option(int $optionId): void
{
    db()->prepare('DELETE FROM date_poll_options WHERE id = ?')->execute([$optionId]);
}

/** Stimmen eines Mitglieds als option_id => 'yes'|'maybe'|'no'. */
function date_poll_member_votes(int $pollId, int $memberId): array
{
    $st = db()->prepare(
        'SELECT v.option_id, v.vote FROM date_poll_votes v
         JOIN date_poll_options o ON o.id = v.option_id
         WHERE o.poll_id = ? AND v.member_id = ?'
    );
    $st->execute([$pollId, $memberId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['option_id']] = (string)$r['vote'];
    return $out;
}

/** Hat dieses Mitglied für die Abstimmung schon abgestimmt (mind. eine Stimme gespeichert)? */
function date_poll_has_voted(int $pollId, int $memberId): bool
{
    $st = db()->prepare(
        'SELECT 1 FROM date_poll_votes v JOIN date_poll_options o ON o.id = v.option_id
         WHERE o.poll_id = ? AND v.member_id = ? LIMIT 1'
    );
    $st->execute([$pollId, $memberId]);
    return (bool)$st->fetchColumn();
}

/**
 * Stimmen eines Mitglieds speichern. $votes = option_id => 'yes'|'maybe'|'no'.
 * Für jede Option der Abstimmung wird eine Zeile gesetzt (fehlende = 'no'),
 * damit „hat abgestimmt" zuverlässig erkennbar ist.
 */
function date_poll_save_votes(int $pollId, int $memberId, array $votes): void
{
    if ($pollId <= 0 || $memberId <= 0) return;
    $ins = db()->prepare(
        'INSERT INTO date_poll_votes(option_id, member_id, vote) VALUES(?,?,?)
         ON CONFLICT(option_id, member_id) DO UPDATE SET vote = excluded.vote'
    );
    foreach (date_poll_options($pollId) as $o) {
        $oid = (int)$o['id'];
        $v = $votes[$oid] ?? 'no';
        if (!in_array($v, ['yes', 'maybe', 'no'], true)) $v = 'no';
        $ins->execute([$oid, $memberId, $v]);
    }
}

/**
 * Ergebnis: je Option die Zählungen + Beliebtheits-Score, sortiert nach Beliebtheit
 * (die meisten „Ja", dann „Ja+Evtl", dann früheres Datum). Rückgabe-Zeilen:
 * ['option'=>row, 'yes'=>int, 'maybe'=>int, 'no'=>int, 'score'=>float].
 */
function date_poll_results(int $pollId): array
{
    $counts = db()->prepare(
        "SELECT option_id,
                SUM(vote = 'yes')   AS yes,
                SUM(vote = 'maybe') AS maybe,
                SUM(vote = 'no')    AS no
         FROM date_poll_votes v JOIN date_poll_options o ON o.id = v.option_id
         WHERE o.poll_id = ? GROUP BY option_id"
    );
    $counts->execute([$pollId]);
    $byOpt = [];
    foreach ($counts->fetchAll() as $r) $byOpt[(int)$r['option_id']] = $r;
    $rows = [];
    foreach (date_poll_options($pollId) as $o) {
        $c = $byOpt[(int)$o['id']] ?? ['yes' => 0, 'maybe' => 0, 'no' => 0];
        $yes = (int)$c['yes']; $maybe = (int)$c['maybe']; $no = (int)$c['no'];
        $rows[] = ['option' => $o, 'yes' => $yes, 'maybe' => $maybe, 'no' => $no,
                   'score' => $yes + $maybe * 0.5]; // „Ja" voll, „Evtl." halb
    }
    usort($rows, function ($a, $b) {
        if ($a['yes'] !== $b['yes']) return $b['yes'] <=> $a['yes'];
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        return strcmp((string)$a['option']['starts_at'], (string)$b['option']['starts_at']);
    });
    return $rows;
}

/** IDs der Mitglieder, die für die Abstimmung schon abgestimmt haben. */
function date_poll_voter_ids(int $pollId): array
{
    $st = db()->prepare(
        'SELECT DISTINCT v.member_id FROM date_poll_votes v
         JOIN date_poll_options o ON o.id = v.option_id WHERE o.poll_id = ?'
    );
    $st->execute([$pollId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Anzahl aktiver Mitglieder (= Soll-Stimmen für die verpflichtende Abstimmung). */
function active_member_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM members WHERE active = 1')->fetchColumn();
}

/** Alle Abstimmungen (offene zuerst, dann nach Datum absteigend). */
function date_polls_all(): array
{
    return db()->query('SELECT * FROM date_polls ORDER BY closed ASC, created_at DESC, id DESC')->fetchAll();
}

/** Offene Terminabstimmungen, für die dieses Mitglied noch NICHT abgestimmt hat (verpflichtende Aufgabe). */
function date_polls_pending_for_member(int $memberId): array
{
    if ($memberId <= 0) return [];
    $st = db()->prepare(
        "SELECT p.* FROM date_polls p
         WHERE p.closed = 0
           AND (p.deadline IS NULL OR p.deadline = ''
                OR strftime('%Y-%m-%d %H:%M','now','localtime') <= (CASE WHEN length(p.deadline) <= 10 THEN p.deadline || ' 23:59' ELSE p.deadline END))
           AND EXISTS (SELECT 1 FROM date_poll_options o WHERE o.poll_id = p.id)
           AND NOT EXISTS (
               SELECT 1 FROM date_poll_votes v JOIN date_poll_options o2 ON o2.id = v.option_id
               WHERE o2.poll_id = p.id AND v.member_id = ?)
         ORDER BY p.created_at"
    );
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** Kurz-Label einer Datumsoption (Datum + Uhrzeit oder ganztägig). */
function date_poll_option_label(array $o): string
{
    return fmt_slot((string)$o['starts_at'], $o['ends_at'] ?? null);
}

/**
 * Content-Disposition für Datei-Auslieferungen wählen. Vorschau-taugliche Typen gehen
 * als „inline": Im Safari-Blatt der installierten iOS-App (Download-Links öffnen mit
 * target=_blank) gibt es keinen Download-Manager – ein attachment bliebe dort eine
 * weiße Seite, inline zeigt die Quick-Look-Vorschau mit Teilen/„Öffnen in …".
 * Desktop-Browser laden nicht darstellbare Typen (z. B. DOCX) trotz inline normal
 * herunter; PDF/Bilder bekommen eine Vorschau im Tab. Alle übrigen Typen bleiben
 * „attachment" – niemals inline für HTML/SVG, sonst könnten hochgeladene Dateien
 * Skripte im App-Kontext ausführen.
 */
function download_disposition(string $mime): string
{
    $preview = [
        'application/pdf', 'text/plain',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
    ];
    return in_array($mime, $preview, true) ? 'inline' : 'attachment';
}

/**
 * Alle Header für eine Datei-Auslieferung setzen (Content-Type, Disposition, Cache).
 * Wichtig: session_start() schickt „Cache-Control: no-store, no-cache" + „Pragma:
 * no-cache" mit – damit verweigert Safari/Quick Look auf dem iPhone die Vorschau,
 * weil die Datei nicht einmal temporär gespeichert werden darf (weiße Seite im
 * Safari-Blatt der installierten App). Deshalb ersetzen wir die Session-Cache-Header
 * hier durch „private, max-age=0": nichts landet in geteilten Caches, aber der
 * Browser der angemeldeten Person darf die Datei für die Vorschau ablegen.
 */
function file_delivery_headers(string $mime, string $filename, ?int $length = null): void
{
    header_remove('Pragma');
    header_remove('Expires');
    header('Cache-Control: private, max-age=0');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . download_disposition($mime) . '; filename="'
        . preg_replace('/["\\\\]/', '', $filename) . "\"; filename*=UTF-8''" . rawurlencode($filename));
    if ($length !== null) header('Content-Length: ' . $length);
}

// ---------------------------------------------------------------------------
// Umlaufverfahren (Umlaufbeschlüsse: Ja/Nein/Enthaltung mit Frist)
// ---------------------------------------------------------------------------
/** Annahme-Regeln: Schlüssel => [Label, Kurzbeschreibung fürs Formular/Ergebnis]. */
function umlauf_rules(): array
{
    return [
        'simple'    => ['Einfache Mehrheit', 'mehr Ja als Nein unter den abgegebenen Stimmen (Enthaltungen zählen nicht)'],
        'absolute'  => ['Mehrheit aller Stimmberechtigten', 'Ja-Stimmen von mehr als der Hälfte ALLER Mitglieder – wer nicht abstimmt, wirkt wie Nein'],
        'unanimous' => ['Einstimmig', 'keine einzige Nein-Stimme und mindestens ein Ja (Enthaltungen sind ok)'],
    ];
}

/** Einen Umlauf holen (oder null). */
function umlauf_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM circular_votes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Alle Umläufe (laufende zuerst, dann nach Anlage absteigend). */
function umlauf_all(): array
{
    return db()->query("SELECT * FROM circular_votes
                        ORDER BY (status = 'open') DESC, created_at DESC, id DESC")->fetchAll();
}

/**
 * Umlauf anlegen (dürfen alle Mitglieder). Frist ist Pflicht ("Y-m-d H:i").
 * Verschickt sofort die "Neuer Umlauf"-Mitteilung an alle anderen aktiven Mitglieder.
 * Gibt die neue ID zurück (oder 0 bei ungültigen Angaben).
 */
function umlauf_create(int $creatorId, string $title, string $description, string $deadline, bool $secret, string $rule, int $quorumPct): int
{
    $title = trim($title); $deadline = trim($deadline);
    if ($creatorId <= 0 || $title === '' || $deadline === '') return 0;
    if (!isset(umlauf_rules()[$rule])) $rule = 'simple';
    $quorumPct = max(0, min(100, $quorumPct));
    db()->prepare('INSERT INTO circular_votes(title, description, created_by, deadline, secret, rule, quorum_pct)
                   VALUES(?,?,?,?,?,?,?)')
        ->execute([$title, trim($description), $creatorId, $deadline, $secret ? 1 : 0, $rule, $quorumPct]);
    $id = (int)db()->lastInsertId();
    $u = umlauf_get($id);
    if ($u) umlauf_notify_new($u);
    return $id;
}

/** Darf dieses Mitglied den Umlauf verwalten (zurückziehen/löschen)? Ersteller:in oder Admin. */
function umlauf_can_manage(array $u, ?array $me): bool
{
    if (!$me) return false;
    return (int)$u['created_by'] === (int)$me['id'] || !empty($me['is_admin']);
}

/** Läuft der Umlauf noch (Status offen UND Frist nicht abgelaufen)? */
/**
 * Der Zeitpunkt, zu dem eine Frist wirklich abläuft: „Y-m-d H:i". Ein reines Datum meint den
 * ganzen Tag, endet also um 23:59. Leere Frist bleibt leer (= keine Frist).
 * EINE Quelle für „läuft noch" (umlauf_open/poll_open) und „vorzeitig beendet"
 * (vote_closed_early) – sonst driften die beiden Auslegungen desselben Feldes auseinander.
 */
function deadline_moment(?string $deadline): string
{
    $dl = trim((string)$deadline);
    if ($dl === '') return '';
    if (strlen($dl) <= 10) $dl .= ' 23:59';
    return substr($dl, 0, 16);
}

/**
 * Wurde vorzeitig beendet – also abgeschlossen, BEVOR die Frist ablief?
 * Dann hätten die Fehlenden noch Zeit gehabt, und ihr Fehlen darf nichts kosten
 * (siehe Basis-Score). Ohne Frist oder ohne vermerktes Ende ist es nicht beurteilbar:
 * Dann gilt wie bisher „regulär gelaufen", damit Altbestand seine Wertung behält.
 */
function vote_closed_early(?string $deadline, ?string $closedAt): bool
{
    $dl = deadline_moment($deadline);
    $ca = trim((string)$closedAt);
    if ($dl === '' || $ca === '') return false;
    return substr($ca, 0, 16) < $dl;
}

function umlauf_open(array $u): bool
{
    if ((string)$u['status'] !== 'open') return false;
    $dl = deadline_moment($u['deadline'] ?? '');
    if ($dl === '') return true;                     // sollte nicht vorkommen (Frist ist Pflicht)
    return date('Y-m-d H:i') <= $dl;
}

/** Zählung der abgegebenen Stimmen: ['yes'=>int,'no'=>int,'abstain'=>int,'total'=>int]. */
function umlauf_tally(int $id): array
{
    // Abgeschlossen? Dann zählt der beim Abschluss EINGEFRORENE Snapshot – das Archiv ändert sich
    // nicht mehr, auch wenn Mitglieder (samt ihrer Stimmen) später gelöscht werden.
    $u = umlauf_get($id);
    if ($u && (string)$u['status'] === 'closed' && $u['result_yes'] !== null) {
        $y = (int)$u['result_yes']; $n = (int)$u['result_no']; $a = (int)$u['result_abstain'];
        return ['yes' => $y, 'no' => $n, 'abstain' => $a, 'total' => $y + $n + $a];
    }
    $st = db()->prepare("SELECT SUM(choice='yes') AS yes, SUM(choice='no') AS no, SUM(choice='abstain') AS abstain, COUNT(*) AS total
                         FROM circular_ballots WHERE vote_id = ?");
    $st->execute([$id]);
    $r = $st->fetch() ?: [];
    return ['yes' => (int)($r['yes'] ?? 0), 'no' => (int)($r['no'] ?? 0),
            'abstain' => (int)($r['abstain'] ?? 0), 'total' => (int)($r['total'] ?? 0)];
}

/** Stimmen mit Namen (für die namentliche Anzeige): Zeilen mit member_id, name, choice, voted_at. */
function umlauf_ballots(int $id): array
{
    $st = db()->prepare('SELECT b.member_id, b.choice, b.voted_at, m.name FROM circular_ballots b
                         JOIN members m ON m.id = b.member_id WHERE b.vote_id = ?
                         ORDER BY m.name COLLATE NOCASE');
    $st->execute([$id]);
    return $st->fetchAll();
}

/** Eigene Stimme ('yes'|'no'|'abstain') oder null. */
function umlauf_my_ballot(int $id, int $memberId): ?string
{
    $st = db()->prepare('SELECT choice FROM circular_ballots WHERE vote_id = ? AND member_id = ?');
    $st->execute([$id, $memberId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/**
 * Stimme abgeben/ändern (bis zum Abschluss). Haben danach ALLE aktiven Mitglieder
 * abgestimmt, wird der Umlauf sofort ausgewertet (vorzeitiger Abschluss).
 * Rückgabe: true, wenn die Stimme gespeichert wurde.
 */
function umlauf_vote(int $id, int $memberId, string $choice): bool
{
    $u = umlauf_get($id);
    if (!$u || $memberId <= 0 || !umlauf_open($u)) return false;
    if (!in_array($choice, ['yes', 'no', 'abstain'], true)) return false;
    if (umlauf_tally($id)['total'] === 0) achievement_unlock($memberId, 'erste_reihe'); // siehe poll_vote()
    db()->prepare("INSERT INTO circular_ballots(vote_id, member_id, choice, voted_at)
                   VALUES(?,?,?,datetime('now','localtime'))
                   ON CONFLICT(vote_id, member_id) DO UPDATE SET choice = excluded.choice, voted_at = excluded.voted_at")
        ->execute([$id, $memberId, $choice]);
    if (umlauf_tally($id)['total'] >= active_member_count()) umlauf_finalize($id); // alle haben abgestimmt
    return true;
}

/** Ergebnis nach Regel + Quorum: 'accepted' | 'rejected' | 'no_quorum'. */
function umlauf_eval(array $u, array $t, int $eligible): string
{
    $eligible = max(1, $eligible);
    if ((int)$u['quorum_pct'] > 0 && $t['total'] * 100 < (int)$u['quorum_pct'] * $eligible) return 'no_quorum';
    return match ((string)$u['rule']) {
        'absolute'  => $t['yes'] * 2 > $eligible ? 'accepted' : 'rejected',
        'unanimous' => ($t['no'] === 0 && $t['yes'] > 0) ? 'accepted' : 'rejected',
        default     => $t['yes'] > $t['no'] ? 'accepted' : 'rejected',
    };
}

/** Lesbares Ergebnis-Label. */
function umlauf_result_label(?string $r): string
{
    return match ((string)$r) {
        'accepted'  => 'angenommen',
        'rejected'  => 'abgelehnt',
        'no_quorum' => 'ungültig – Mindestbeteiligung verfehlt',
        default     => '—',
    };
}

/**
 * Umlauf auswerten und schließen (idempotent – nur solange Status 'open').
 * Friert die Zahl der Stimmberechtigten ein und verschickt die Ergebnis-Mitteilung
 * an alle aktiven Mitglieder. Rückgabe: true, wenn JETZT geschlossen wurde.
 */
function umlauf_finalize(int $id): bool
{
    $u = umlauf_get($id);
    if (!$u || (string)$u['status'] !== 'open') return false;
    $eligible = active_member_count();
    $t = umlauf_tally($id);
    $result = umlauf_eval($u, $t, $eligible);
    // Zählung als Snapshot einfrieren: das Beschluss-Archiv bleibt für immer so, wie ausgewertet wurde
    $upd = db()->prepare("UPDATE circular_votes SET status = 'closed', result = ?, eligible_count = ?,
                          result_yes = ?, result_no = ?, result_abstain = ?,
                          closed_at = datetime('now','localtime') WHERE id = ? AND status = 'open'");
    $upd->execute([$result, $eligible, $t['yes'], $t['no'], $t['abstain'], $id]);
    if ($upd->rowCount() < 1) return false; // parallel schon geschlossen
    // Ergebnis an alle aktiven Mitglieder (Dashboard + Push, Mail je nach Einstellung)
    $zaehlung = 'Ja ' . $t['yes'] . ' · Nein ' . $t['no'] . ' · Enthaltung ' . $t['abstain']
        . ' (Beteiligung ' . $t['total'] . ' von ' . $eligible . ')';
    $body = 'Umlaufbeschluss „' . $u['title'] . '" ist ' . umlauf_result_label($result) . ': ' . $zaehlung
        . '. Details: ' . app_url('umlauf.php?id=' . $id);
    foreach (members_all() as $m) {
        try {
            dm_send((int)$m['id'], 'Umlaufverfahren 🗳️', $body, null,
                notify_pref((int)$m['id'], 'umlauf_result')['mail'], true, 'umlauf_result');
        } catch (\Throwable $e) { /* Versand-Fehler stoppen die Auswertung nie */ }
    }
    return true;
}

/** Umlauf zurückziehen (Ersteller:in/Admin, nur solange offen) – ohne Auswertung. */
function umlauf_withdraw(int $id, ?array $me): bool
{
    $u = umlauf_get($id);
    if (!$u || (string)$u['status'] !== 'open' || !umlauf_can_manage($u, $me)) return false;
    db()->prepare("UPDATE circular_votes SET status = 'withdrawn', closed_at = datetime('now','localtime') WHERE id = ?")
        ->execute([$id]);
    return true;
}

/** Umlauf löschen (nur Admin – Beschluss-Historie soll nicht leichtfertig verschwinden). */
function umlauf_delete(int $id, ?array $me): bool
{
    if (!$me || empty($me['is_admin'])) return false;
    umlauf_files_delete_all('umlauf', $id);
    db()->prepare('DELETE FROM circular_votes WHERE id = ?')->execute([$id]);
    return true;
}

/** Abgelaufene Umläufe auswerten (Seitenaufrufe + Cron). Rückgabe: Anzahl neu geschlossener. */
function umlauf_maintain(): int
{
    $n = 0;
    foreach (db()->query("SELECT * FROM circular_votes WHERE status = 'open'")->fetchAll() as $u) {
        if (!umlauf_open($u) && umlauf_finalize((int)$u['id'])) $n++;
    }
    return $n;
}

/** Laufende Umläufe, bei denen dieses Mitglied noch NICHT abgestimmt hat (verpflichtende Aufgabe). */
function umlauf_pending_for_member(int $memberId): array
{
    if ($memberId <= 0) return [];
    $out = [];
    $st = db()->prepare("SELECT * FROM circular_votes u WHERE u.status = 'open'
                         AND NOT EXISTS (SELECT 1 FROM circular_ballots b WHERE b.vote_id = u.id AND b.member_id = ?)
                         ORDER BY u.deadline, u.id");
    $st->execute([$memberId]);
    foreach ($st->fetchAll() as $u) if (umlauf_open($u)) $out[] = $u;
    return $out;
}

/** "Neuer Umlauf" an alle anderen aktiven Mitglieder (Dashboard + Push, Mail je nach Register). */
function umlauf_notify_new(array $u): int
{
    $creator = member_get((int)$u['created_by']);
    $wer = $creator ? short_name((string)$creator['name']) : 'Jemand';
    $body = $wer . ' hat einen Umlaufbeschluss gestartet: „' . $u['title'] . '". Bitte stimme bis '
        . fmt_slot((string)$u['deadline'], null) . ' ab: ' . app_url('umlauf.php?id=' . (int)$u['id']);
    $n = 0;
    foreach (members_all() as $m) {
        if ((int)$m['id'] === (int)$u['created_by']) continue;
        try {
            if (dm_send((int)$m['id'], 'Umlaufverfahren 🗳️', $body, null,
                notify_pref((int)$m['id'], 'umlauf_new')['mail'], true, 'umlauf_new')) $n++;
        } catch (\Throwable $e) { /* einzelne Versand-Fehler überspringen */ }
    }
    return $n;
}

/**
 * Erinnerungs-Cron: Mail + Push an alle, die bei einem laufenden Umlauf noch nicht
 * abgestimmt haben – innerhalb des persönlichen Vorlauf-Fensters, höchstens 1x täglich.
 * Rückgabe: Anzahl verschickter Erinnerungen.
 */
function umlauf_send_reminders(bool $dryRun = false): int
{
    $today = date('Y-m-d');
    $sent = 0;
    foreach (db()->query("SELECT * FROM circular_votes WHERE status = 'open'")->fetchAll() as $u) {
        if (!umlauf_open($u)) continue; // abgelaufene schließt umlauf_maintain()
        $daysLeft = days_until_d(substr((string)$u['deadline'], 0, 10));
        $url = app_url('umlauf.php?id=' . (int)$u['id']);
        $voted = array_map('intval', array_column(umlauf_ballots((int)$u['id']), 'member_id'));
        foreach (members_all() as $m) {
            $mid = (int)$m['id'];
            if (in_array($mid, $voted, true)) continue;
            if ($daysLeft > notify_pref($mid, 'umlauf_reminder')['timing']) continue; // persönliches Fenster
            $chk = db()->prepare('SELECT 1 FROM umlauf_reminder_log WHERE vote_id=? AND member_id=? AND sent_on=?');
            $chk->execute([(int)$u['id'], $mid, $today]);
            if ($chk->fetchColumn()) continue; // heute schon erinnert
            if ($dryRun) { $sent++; continue; }
            $hinweis = 'Beim Umlaufbeschluss „' . $u['title'] . '" fehlt noch deine Stimme – bitte stimme bis '
                . fmt_slot((string)$u['deadline'], null) . ' ab.';
            if (notify_deliver($mid, 'umlauf_reminder', fn() => mail_tpl_send('dm', member_mail($m), [
                    '{{VORNAME}}' => first_name((string)$m['name']),
                    '{{KOPF}}' => 'Erinnerung – Umlaufverfahren:',
                    '{{NACHRICHT}}' => $hinweis,
                    '{{LINK}}' => $url,
                ]), 'Umlauf: bitte abstimmen', $hinweis, $url)) {
                db()->prepare('INSERT OR IGNORE INTO umlauf_reminder_log(vote_id, member_id, sent_on) VALUES(?,?,?)')
                    ->execute([(int)$u['id'], $mid, $today]);
                $sent++;
            }
        }
    }
    return $sent;
}

// ---------------------------------------------------------------------------
// Normale Abstimmungen/Umfragen (freie Antwortoptionen, auf der Umlauf-Seite)
// ---------------------------------------------------------------------------
/** Eine Abstimmung holen (oder null). */
function poll_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM simple_polls WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Alle Abstimmungen (laufende zuerst, dann nach Anlage absteigend). */
function poll_all(): array
{
    return db()->query("SELECT * FROM simple_polls
                        ORDER BY (status = 'open') DESC, created_at DESC, id DESC")->fetchAll();
}

/** Antwortoptionen einer Abstimmung (in Eingabe-Reihenfolge). */
function poll_options(int $pollId): array
{
    $st = db()->prepare('SELECT * FROM simple_poll_options WHERE poll_id = ? ORDER BY sort, id');
    $st->execute([$pollId]);
    return $st->fetchAll();
}

/**
 * Abstimmung anlegen (dürfen alle Mitglieder). $optionLabels = Antwortoptionen (min. 2).
 * Frist ist optional – bei verpflichtenden Abstimmungen aber Pflicht (sonst 0).
 * Benachrichtigt sofort alle anderen aktiven Mitglieder. Rückgabe: neue ID oder 0.
 */
function poll_create(int $creatorId, string $title, string $description, ?string $deadline, bool $secret, bool $multi, bool $mandatory, array $optionLabels): int
{
    $title = trim($title);
    $opts = [];
    foreach ($optionLabels as $o) { $o = trim((string)$o); if ($o !== '' && !in_array($o, $opts, true)) $opts[] = $o; }
    if ($creatorId <= 0 || $title === '' || count($opts) < 2) return 0;
    $deadline = trim((string)$deadline);
    if ($mandatory && $deadline === '') return 0; // verpflichtend braucht eine Frist (für Erinnerungen/Abschluss)
    db()->prepare('INSERT INTO simple_polls(title, description, created_by, deadline, secret, multi, mandatory)
                   VALUES(?,?,?,?,?,?,?)')
        ->execute([$title, trim($description), $creatorId, $deadline !== '' ? $deadline : null,
                   $secret ? 1 : 0, $multi ? 1 : 0, $mandatory ? 1 : 0]);
    $id = (int)db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO simple_poll_options(poll_id, label, sort) VALUES(?,?,?)');
    foreach ($opts as $i => $o) $ins->execute([$id, $o, $i]);
    $p = poll_get($id);
    if ($p) poll_notify_new($p);
    return $id;
}

/** Darf dieses Mitglied die Abstimmung verwalten (schließen/löschen)? Ersteller:in oder Admin. */
function poll_can_manage(array $p, ?array $me): bool
{
    if (!$me) return false;
    return (int)$p['created_by'] === (int)$me['id'] || !empty($me['is_admin']);
}

/** Läuft die Abstimmung noch (Status offen UND ggf. Frist nicht abgelaufen)? */
function poll_open(array $p): bool
{
    if ((string)$p['status'] !== 'open') return false;
    $dl = deadline_moment($p['deadline'] ?? '');
    if ($dl === '') return true;                     // ohne Frist: offen bis zum manuellen Schließen
    return date('Y-m-d H:i') <= $dl;
}

/** Eigene Kreuze als Liste von Options-IDs. */
function poll_my_votes(int $pollId, int $memberId): array
{
    $st = db()->prepare('SELECT v.option_id FROM simple_poll_votes v
                         JOIN simple_poll_options o ON o.id = v.option_id
                         WHERE o.poll_id = ? AND v.member_id = ?');
    $st->execute([$pollId, $memberId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** IDs der Mitglieder, die schon abgestimmt haben. */
function poll_voter_ids(int $pollId): array
{
    $st = db()->prepare('SELECT DISTINCT v.member_id FROM simple_poll_votes v
                         JOIN simple_poll_options o ON o.id = v.option_id WHERE o.poll_id = ?');
    $st->execute([$pollId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Kreuz(e) abgeben/ändern (bis zum Abschluss). $optionIds werden gegen die Optionen
 * der Abstimmung geprüft; bei Einzelauswahl zählt nur das erste. Mindestens ein Kreuz.
 * Bei verpflichtenden Abstimmungen: Abschluss, sobald alle aktiven Mitglieder abgestimmt haben.
 */
function poll_vote(int $pollId, int $memberId, array $optionIds): bool
{
    $p = poll_get($pollId);
    if (!$p || $memberId <= 0 || !poll_open($p)) return false;
    $valid = array_map(fn($o) => (int)$o['id'], poll_options($pollId));
    $picks = array_values(array_unique(array_filter(array_map('intval', $optionIds), fn($i) => in_array($i, $valid, true))));
    if (!$picks) return false;
    if (empty($p['multi'])) $picks = [array_shift($picks)]; // Einzelauswahl: nur ein Kreuz
    // „Erste Reihe": Wer hier als Allererste:r ein Kreuz macht. Geprüft wird VOR dem Löschen der
    // eigenen alten Stimme – wer seine Stimme nur ändert, taucht in der Zählung selbst auf und
    // geht damit korrekt leer aus. Sofort-Erfolg wie die Tageszeit-Geheimnisse: Ein Zeitstempel
    // steht in dieser Tabelle nicht, im Moment des Abstimmens weiß man es aber genau.
    if (!poll_voter_ids($pollId)) achievement_unlock($memberId, 'erste_reihe');
    db()->prepare('DELETE FROM simple_poll_votes WHERE member_id = ?
                   AND option_id IN (SELECT id FROM simple_poll_options WHERE poll_id = ?)')
        ->execute([$memberId, $pollId]);
    $ins = db()->prepare('INSERT OR IGNORE INTO simple_poll_votes(option_id, member_id) VALUES(?,?)');
    foreach ($picks as $oid) $ins->execute([$oid, $memberId]);
    if (!empty($p['mandatory']) && count(poll_voter_ids($pollId)) >= active_member_count()) {
        poll_close($pollId); // alle haben abgestimmt → vorzeitiger Abschluss
    }
    return true;
}

/** Zählung: option_id => Stimmenzahl (fehlende Optionen = 0) + Gesamtzahl Abstimmender. */
function poll_results(int $pollId): array
{
    // Beendet? Dann liefert der beim Abschluss eingefrorene Snapshot das Ergebnis (unveränderlich,
    // auch wenn Mitglieder samt Stimmen später gelöscht werden).
    $p = poll_get($pollId);
    if ($p && (string)$p['status'] === 'closed' && trim((string)($p['result_json'] ?? '')) !== '') {
        $snap = json_decode((string)$p['result_json'], true);
        if (is_array($snap) && isset($snap['counts'], $snap['voters'])) {
            $counts = [];
            foreach ((array)$snap['counts'] as $oid => $n) $counts[(int)$oid] = (int)$n;
            return ['counts' => $counts, 'voters' => (int)$snap['voters']];
        }
    }
    $st = db()->prepare('SELECT o.id, COUNT(v.member_id) AS n FROM simple_poll_options o
                         LEFT JOIN simple_poll_votes v ON v.option_id = o.id
                         WHERE o.poll_id = ? GROUP BY o.id');
    $st->execute([$pollId]);
    $counts = [];
    foreach ($st->fetchAll() as $r) $counts[(int)$r['id']] = (int)$r['n'];
    return ['counts' => $counts, 'voters' => count(poll_voter_ids($pollId))];
}

/** Namen je Option (für die namentliche Anzeige): option_id => [Kurznamen]. */
function poll_vote_names(int $pollId): array
{
    $st = db()->prepare('SELECT v.option_id, m.id, m.name FROM simple_poll_votes v
                         JOIN simple_poll_options o ON o.id = v.option_id
                         JOIN members m ON m.id = v.member_id
                         WHERE o.poll_id = ? ORDER BY m.name COLLATE NOCASE');
    $st->execute([$pollId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['option_id']][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
    return $out;
}

/**
 * Abstimmung schließen (idempotent). Bei verpflichtenden geht das Ergebnis
 * (führende Option + Beteiligung) als Mitteilung an alle aktiven Mitglieder.
 */
function poll_close(int $id): bool
{
    $p = poll_get($id);
    if (!$p || (string)$p['status'] !== 'open') return false;
    $snap = poll_results($id); // Zählung VOR dem Schließen einfrieren (Status ist noch 'open' → live gezählt)
    $upd = db()->prepare("UPDATE simple_polls SET status = 'closed', closed_at = datetime('now','localtime'),
                          result_json = ? WHERE id = ? AND status = 'open'");
    $upd->execute([json_encode($snap), $id]);
    if ($upd->rowCount() < 1) return false;
    if (!empty($p['mandatory'])) {
        $res = poll_results($id);
        $top = []; $topN = -1;
        foreach (poll_options($id) as $o) {
            $n = $res['counts'][(int)$o['id']] ?? 0;
            if ($n > $topN) { $top = [(string)$o['label']]; $topN = $n; }
            elseif ($n === $topN) $top[] = (string)$o['label'];
        }
        $body = 'Abstimmung „' . $p['title'] . '" ist abgeschlossen. Vorn: '
            . implode(' / ', $top) . ' (' . $topN . ' Stimme(n), Beteiligung ' . $res['voters'] . ' von '
            . active_member_count() . '). Details: ' . app_url('umlauf.php?poll=' . $id);
        foreach (members_all() as $m) {
            try {
                dm_send((int)$m['id'], 'Abstimmung 🗳️', $body, null,
                    notify_pref((int)$m['id'], 'umlauf_result')['mail'], true, 'umlauf_result');
            } catch (\Throwable $e) { /* Versand-Fehler stoppen den Abschluss nie */ }
        }
    }
    return true;
}

/** Abstimmung löschen (Ersteller:in/Admin – informelle Umfragen sind kein Beschluss-Archiv). */
function poll_delete(int $id, ?array $me): bool
{
    $p = poll_get($id);
    if (!$p || !poll_can_manage($p, $me)) return false;
    umlauf_files_delete_all('poll', $id);
    db()->prepare('DELETE FROM poll_hides WHERE poll_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM simple_polls WHERE id = ?')->execute([$id]);
    return true;
}

/** Abgelaufene Abstimmungen schließen (Seitenaufrufe + Cron). Rückgabe: Anzahl neu geschlossener. */
function poll_maintain(): int
{
    $n = 0;
    foreach (db()->query("SELECT * FROM simple_polls WHERE status = 'open'")->fetchAll() as $p) {
        if (!poll_open($p) && poll_close((int)$p['id'])) $n++;
    }
    return $n;
}

/** Laufende VERPFLICHTENDE Abstimmungen ohne eigene Stimme (Dashboard-Pflichtaufgabe). */
function poll_pending_for_member(int $memberId): array
{
    if ($memberId <= 0) return [];
    $out = [];
    $st = db()->prepare("SELECT * FROM simple_polls p WHERE p.status = 'open' AND p.mandatory = 1
                         AND NOT EXISTS (SELECT 1 FROM simple_poll_votes v
                                         JOIN simple_poll_options o ON o.id = v.option_id
                                         WHERE o.poll_id = p.id AND v.member_id = ?)
                         ORDER BY p.deadline, p.id");
    $st->execute([$memberId]);
    foreach ($st->fetchAll() as $p) if (poll_open($p)) $out[] = $p;
    return $out;
}

/**
 * Laufende FREIWILLIGE Abstimmungen ohne eigene Stimme, die das Mitglied nicht per
 * „Ausblenden" weggeklickt hat (Dashboard-Hinweis – keine Pflicht-Aufgabe).
 */
function poll_optional_for_member(int $memberId): array
{
    if ($memberId <= 0) return [];
    $out = [];
    $st = db()->prepare("SELECT * FROM simple_polls p WHERE p.status = 'open' AND p.mandatory = 0
                         AND NOT EXISTS (SELECT 1 FROM simple_poll_votes v
                                         JOIN simple_poll_options o ON o.id = v.option_id
                                         WHERE o.poll_id = p.id AND v.member_id = ?)
                         AND NOT EXISTS (SELECT 1 FROM poll_hides h WHERE h.poll_id = p.id AND h.member_id = ?)
                         ORDER BY p.deadline IS NULL OR p.deadline = '', p.deadline, p.id");
    $st->execute([$memberId, $memberId]);
    foreach ($st->fetchAll() as $p) if (poll_open($p)) $out[] = $p;
    return $out;
}

/** Freiwillige Abstimmung für dieses Mitglied vom Dashboard ausblenden (abstimmen geht weiter über die Abstimmungs-Seite). */
function poll_hide_for_member(int $pollId, int $memberId): void
{
    if ($pollId <= 0 || $memberId <= 0) return;
    db()->prepare('INSERT OR IGNORE INTO poll_hides(poll_id, member_id) VALUES(?,?)')->execute([$pollId, $memberId]);
}

/** "Neue Abstimmung" an alle anderen aktiven Mitglieder (Dashboard + Push, Mail je nach Register). */
function poll_notify_new(array $p): int
{
    $creator = member_get((int)$p['created_by']);
    $wer = $creator ? short_name((string)$creator['name']) : 'Jemand';
    $frist = trim((string)($p['deadline'] ?? '')) !== '' ? ' bis ' . fmt_slot((string)$p['deadline'], null) : '';
    $body = $wer . ' hat eine ' . (empty($p['mandatory']) ? 'Abstimmung' : 'VERPFLICHTENDE Abstimmung')
        . ' gestartet: „' . $p['title'] . '". Bitte stimme' . $frist . ' ab: ' . app_url('umlauf.php?poll=' . (int)$p['id']);
    $n = 0;
    foreach (members_all() as $m) {
        if ((int)$m['id'] === (int)$p['created_by']) continue;
        try {
            if (dm_send((int)$m['id'], 'Abstimmung 🗳️', $body, null,
                notify_pref((int)$m['id'], 'poll_new')['mail'], true, 'poll_new')) $n++;
        } catch (\Throwable $e) { /* einzelne Versand-Fehler überspringen */ }
    }
    return $n;
}

/**
 * Erinnerungs-Cron für VERPFLICHTENDE Abstimmungen: Mail + Push an Säumige innerhalb
 * des persönlichen Vorlauf-Fensters (Typ umlauf_reminder), höchstens 1x täglich.
 */
function poll_send_reminders(bool $dryRun = false): int
{
    $today = date('Y-m-d');
    $sent = 0;
    foreach (db()->query("SELECT * FROM simple_polls WHERE status = 'open' AND mandatory = 1")->fetchAll() as $p) {
        if (!poll_open($p) || trim((string)($p['deadline'] ?? '')) === '') continue;
        $daysLeft = days_until_d(substr((string)$p['deadline'], 0, 10));
        $url = app_url('umlauf.php?poll=' . (int)$p['id']);
        $voted = poll_voter_ids((int)$p['id']);
        foreach (members_all() as $m) {
            $mid = (int)$m['id'];
            if (in_array($mid, $voted, true)) continue;
            if ($daysLeft > notify_pref($mid, 'umlauf_reminder')['timing']) continue;
            $chk = db()->prepare('SELECT 1 FROM poll_reminder_log WHERE poll_id=? AND member_id=? AND sent_on=?');
            $chk->execute([(int)$p['id'], $mid, $today]);
            if ($chk->fetchColumn()) continue;
            if ($dryRun) { $sent++; continue; }
            $hinweis = 'Bei der Abstimmung „' . $p['title'] . '" fehlt noch deine Stimme – bitte stimme bis '
                . fmt_slot((string)$p['deadline'], null) . ' ab.';
            if (notify_deliver($mid, 'umlauf_reminder', fn() => mail_tpl_send('dm', member_mail($m), [
                    '{{VORNAME}}' => first_name((string)$m['name']),
                    '{{KOPF}}' => 'Erinnerung – Abstimmung:',
                    '{{NACHRICHT}}' => $hinweis,
                    '{{LINK}}' => $url,
                ]), 'Abstimmung: bitte abstimmen', $hinweis, $url)) {
                db()->prepare('INSERT OR IGNORE INTO poll_reminder_log(poll_id, member_id, sent_on) VALUES(?,?,?)')
                    ->execute([(int)$p['id'], $mid, $today]);
                $sent++;
            }
        }
    }
    return $sent;
}

// ---------------------------------------------------------------------------
// Anhänge zu Umlaufbeschlüssen/Abstimmungen (umlauf.php; Download via ?ufile=)
// ---------------------------------------------------------------------------
/** Anhänge eines Umlaufs ('umlauf') bzw. einer Abstimmung ('poll'). */
function umlauf_files_of(string $kind, int $refId): array
{
    if (!in_array($kind, ['umlauf', 'poll'], true) || $refId <= 0) return [];
    $st = db()->prepare('SELECT * FROM umlauf_files WHERE kind = ? AND ref_id = ? ORDER BY orig_name COLLATE NOCASE');
    $st->execute([$kind, $refId]);
    return $st->fetchAll();
}

/** Eine hochgeladene Datei anhängen (Typen/Limit wie bei den Infos). Gibt null oder Fehlermeldung zurück. */
function umlauf_file_add(string $kind, int $refId, array $file, int $byMemberId): ?string
{
    if (!in_array($kind, ['umlauf', 'poll'], true) || $refId <= 0) return 'Unbekannter Bereich.';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null; // kein File gewählt
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload fehlgeschlagen.';
    if ((int)$file['size'] > 50 * 1024 * 1024) return 'Datei zu groß (max. 50 MB).';
    $orig = (string)$file['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','odt','rtf','txt','xls','xlsx','ods','csv','ppt','pptx','odp','png','jpg','jpeg','gif','webp','zip'];
    if (!in_array($ext, $allowed, true)) return 'Dateityp „.' . $ext . '" ist nicht erlaubt.';
    $dir = upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return 'Upload-Ordner konnte nicht angelegt werden.';
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return 'Datei konnte nicht gespeichert werden.';
    $mime = function_exists('mime_content_type') ? (mime_content_type($dir . '/' . $stored) ?: '') : '';
    db()->prepare('INSERT INTO umlauf_files(kind, ref_id, orig_name, stored_name, mime, size, uploaded_by) VALUES(?,?,?,?,?,?,?)')
        ->execute([$kind, $refId, $orig, $stored, $mime, (int)$file['size'], $byMemberId ?: null]);
    return null;
}

/** Einen Anhang löschen (Datei + Datensatz + Teams-Spiegel-Verweis; Dok in Teams bleibt). */
function umlauf_file_delete(int $fileId): void
{
    $st = db()->prepare('SELECT stored_name FROM umlauf_files WHERE id = ?');
    $st->execute([$fileId]);
    if (($sn = $st->fetchColumn()) !== false) {
        @unlink(upload_dir() . '/' . basename((string)$sn)); // basename schützt vor Pfad-Tricks
    }
    db()->prepare('DELETE FROM umlauf_files WHERE id = ?')->execute([$fileId]);
    mirror_forget('ufile', $fileId); // Verweise auf beide Ablagen aufräumen (das Dok dort bleibt)
}

/** Alle Anhänge eines Umlaufs/einer Abstimmung löschen (beim Löschen des Vorgangs). */
function umlauf_files_delete_all(string $kind, int $refId): void
{
    foreach (umlauf_files_of($kind, $refId) as $f) umlauf_file_delete((int)$f['id']);
}

/** Muss zum MEMBER_KEY in redeliste.html passen – schaltet für Mitglieder den internen Teil frei. */
const REDELISTE_MEMBER_KEY = 'AStA-Mitglied';

/**
 * Redeliste-Verknüpfung einer Sitzung: eigener Raum-Token + Leitungs-Key (werden bei Bedarf erzeugt).
 * Rückgabe: ['session','join','lead'] – join = Mitglieder-Beitritt, lead = Sitzungsleitung (mit Key).
 */
/* ---- Sitzungs-Rückmeldung: abmelden (entschuldigt) oder „nur online dabei" ----
 * Standard (keine Zeile) = kommt in Präsenz. Änderbar bis 18:00 Uhr am Sitzungstag.
 * Abgemeldete landen automatisch in der „Abwesende"-Tabelle der Protokollvorlage (x = entschuldigt). */

/** Ist die Sitzungs-Rückmeldung noch offen? (bis 18:00 Uhr am Sitzungstag, nicht ausgefallen/Entwurf) */
function meeting_rsvp_open(array $m): bool
{
    if (!empty($m['cancelled']) || !empty($m['draft'])) return false;
    if (($m['kind'] ?? '') === 'stupa') return false; // StuPa: keine Rückmeldung/Abmeldung

    $day = substr((string)($m['starts_at'] ?? ''), 0, 10);
    if ($day === '') return false;
    $cutoff = strtotime($day . ' 18:00');
    return $cutoff !== false && time() <= $cutoff;
}

/** Rückmeldung eines Mitglieds: 'abgemeldet' | 'online' | null (= kommt in Präsenz). */
function meeting_rsvp_status(int $meetingId, int $memberId): ?string
{
    if ($meetingId <= 0 || $memberId <= 0) return null;
    $st = db()->prepare('SELECT status FROM meeting_rsvp WHERE meeting_id = ? AND member_id = ?');
    $st->execute([$meetingId, $memberId]);
    $s = $st->fetchColumn();
    return $s === false ? null : (string)$s;
}

/** Rückmeldung setzen ('abgemeldet'/'online', mit Grund) bzw. mit 'none' zurücknehmen.
 *  Der Grund wird gespeichert und NUR dem Vorsitz per Dashboard-Nachricht weitergeleitet. */
function meeting_rsvp_set(array $m, int $memberId, string $status, string $reason = ''): array
{
    if ($memberId <= 0) return ['ok' => false, 'msg' => 'Nur Mitglieder können sich zurückmelden.'];
    if (!meeting_rsvp_open($m)) return ['ok' => false, 'msg' => 'Die Frist ist vorbei – Rückmeldungen sind nur bis 18:00 Uhr am Sitzungstag möglich.'];
    $mid = (int)$m['id'];
    $reason = mb_substr(trim($reason), 0, 500);
    if ($status === 'none') {
        $had = meeting_rsvp_status($mid, $memberId);
        db()->prepare('DELETE FROM meeting_rsvp WHERE meeting_id = ? AND member_id = ?')->execute([$mid, $memberId]);
        if ($had !== null) notify_vorsitz_meeting_rsvp($m, $memberId, 'none', '');
        return ['ok' => true, 'msg' => 'Alles klar – du bist wieder als „in Präsenz dabei" vermerkt.'];
    }
    if (!in_array($status, ['abgemeldet', 'online'], true)) return ['ok' => false, 'msg' => 'Ungültige Rückmeldung.'];
    if ($reason === '') return ['ok' => false, 'msg' => 'Bitte gib kurz an, warum die Teilnahme' . ($status === 'online' ? ' in Präsenz' : '') . ' nicht möglich ist – der Grund wird nur an den Vorsitz weitergeleitet.'];
    db()->prepare("INSERT INTO meeting_rsvp(meeting_id, member_id, status, reason, updated_at)
                   VALUES(?,?,?,?, datetime('now','localtime'))
                   ON CONFLICT(meeting_id, member_id) DO UPDATE SET status = excluded.status, reason = excluded.reason, updated_at = excluded.updated_at")
        ->execute([$mid, $memberId, $status, $reason]);
    notify_vorsitz_meeting_rsvp($m, $memberId, $status, $reason);
    return ['ok' => true, 'msg' => $status === 'abgemeldet'
        ? 'Du bist für diese Sitzung abgemeldet (entschuldigt) – das steht auch gleich in der Protokollvorlage. Der Grund geht nur an den Vorsitz.'
        : 'Vermerkt: Du nimmst online teil. Der Grund geht nur an den Vorsitz.'];
}

/** Vorsitz per Dashboard-Nachricht informieren, wer (nicht) an einer Sitzung teilnimmt und warum. */
function notify_vorsitz_meeting_rsvp(array $m, int $memberId, string $status, string $reason): void
{
    $name = (string)(db()->query('SELECT name FROM members WHERE id = ' . (int)$memberId)->fetchColumn() ?: 'Ein Mitglied');
    $label = meeting_label($m) . (($t = strtotime((string)($m['starts_at'] ?? ''))) ? ' am ' . date('d.m.Y', $t) : '');
    $body = match ($status) {
        'abgemeldet' => $name . ' hat sich für die ' . $label . ' abgemeldet (entschuldigt).',
        'online'     => $name . ' nimmt an der ' . $label . ' nur online teil.',
        default      => $name . ' nimmt an der ' . $label . ' nun doch in Präsenz teil.',
    };
    if ($reason !== '') $body .= "\nGrund: " . $reason;
    foreach (db()->query("SELECT id FROM members WHERE active = 1 AND role = 'vorsitz'")->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        if ((int)$vid === $memberId) continue; // keine Selbst-Benachrichtigung
        dm_send((int)$vid, 'Sitzungs-Teilnahme', $body, null, false, false, 'dm_sitzung');
    }
}

/** Kennungs-Präfix automatischer Sitzungs-Abmeldungen durch Abwesenheiten (fürs Zurücknehmen). */
const MEETING_RSVP_ABSENCE_PREFIX = 'Eingetragene Abwesenheit';

/**
 * Wendet eine eingetragene Abwesenheit auf Sitzungen im Zeitraum an: automatische Abmeldung
 * (entschuldigt) für alle nicht ausgefallenen Sitzungen, deren Rückmeldung noch offen ist.
 * Bereits Abgemeldete bleiben unangetastet. Der Grund wird mit Kennung gespeichert,
 * damit die Abmeldung beim Löschen der Abwesenheit wieder zurückgenommen werden kann.
 * Rückgabe: Labels der automatisch abgemeldeten Sitzungen (für die Vorsitz-Meldung).
 */
function absence_apply_to_meetings(int $memberId, string $from, string $to, string $reason = ''): array
{
    if ($memberId <= 0 || $from === '' || $to === '') return [];
    $st = db()->prepare("SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa' AND date(starts_at) BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $labels = [];
    foreach ($st->fetchAll() as $m) {
        if (!meeting_rsvp_open($m)) continue; // Frist vorbei – Protokoll ggf. schon erstellt
        if (meeting_rsvp_status((int)$m['id'], $memberId) === 'abgemeldet') continue;
        $r = mb_substr(MEETING_RSVP_ABSENCE_PREFIX . (trim($reason) !== '' ? ': ' . trim($reason) : ''), 0, 500);
        db()->prepare("INSERT INTO meeting_rsvp(meeting_id, member_id, status, reason, updated_at)
                       VALUES(?,?,'abgemeldet',?, datetime('now','localtime'))
                       ON CONFLICT(meeting_id, member_id) DO UPDATE SET status = excluded.status, reason = excluded.reason, updated_at = excluded.updated_at")
            ->execute([(int)$m['id'], $memberId, $r]);
        $labels[] = meeting_label($m) . (($t = strtotime((string)$m['starts_at'])) ? ' am ' . date('d.m.Y', $t) : '');
    }
    return $labels;
}

/**
 * Nimmt nach dem Löschen einer Abwesenheit die AUTOMATISCHEN Sitzungs-Abmeldungen zurück –
 * nur solche mit Abwesenheits-Kennung, nur solange die Rückmeldung offen ist und nur, wenn
 * keine andere Abwesenheit den Sitzungstag weiterhin abdeckt. Von Hand gesetzte Abmeldungen
 * bleiben unberührt. Rückgabe: Labels der zurückgenommenen Abmeldungen.
 */
function absence_revert_meetings(int $memberId, string $from, string $to): array
{
    if ($memberId <= 0 || $from === '' || $to === '') return [];
    $st = db()->prepare("SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa' AND date(starts_at) BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $labels = [];
    foreach ($st->fetchAll() as $m) {
        if (!meeting_rsvp_open($m)) continue;
        if (member_absent_on($memberId, (string)$m['starts_at'])) continue; // andere Abwesenheit deckt den Tag weiter ab
        $row = db()->prepare("SELECT 1 FROM meeting_rsvp WHERE meeting_id = ? AND member_id = ? AND status = 'abgemeldet' AND reason LIKE ?");
        $row->execute([(int)$m['id'], $memberId, MEETING_RSVP_ABSENCE_PREFIX . '%']);
        if (!$row->fetchColumn()) continue; // keine automatische Abmeldung (oder von Hand) → nicht anfassen
        db()->prepare('DELETE FROM meeting_rsvp WHERE meeting_id = ? AND member_id = ?')->execute([(int)$m['id'], $memberId]);
        $labels[] = meeting_label($m) . (($t = strtotime((string)$m['starts_at'])) ? ' am ' . date('d.m.Y', $t) : '');
    }
    return $labels;
}

/** Rückmeldungen einer Sitzung: ['abgemeldet' => [Namen], 'online' => [Namen]], alphabetisch. */
function meeting_rsvp_lists(int $meetingId): array
{
    $out = ['abgemeldet' => [], 'online' => []];
    if ($meetingId <= 0) return $out;
    $st = db()->prepare(
        'SELECT r.status, m.name FROM meeting_rsvp r
         JOIN members m ON m.id = r.member_id
         WHERE r.meeting_id = ? ORDER BY m.name COLLATE NOCASE'
    );
    $st->execute([$meetingId]);
    foreach ($st->fetchAll() as $r) {
        $k = (string)$r['status'];
        if (isset($out[$k])) $out[$k][] = (string)$r['name'];
    }
    return $out;
}

/** Wie meeting_rsvp_lists, aber je Person id + name – für verlinkte Namen (Nutzerprofile). */
function meeting_rsvp_members(int $meetingId): array
{
    $out = ['abgemeldet' => [], 'online' => []];
    if ($meetingId <= 0) return $out;
    $st = db()->prepare(
        'SELECT r.status, m.id, m.name FROM meeting_rsvp r
         JOIN members m ON m.id = r.member_id
         WHERE r.meeting_id = ? ORDER BY m.name COLLATE NOCASE'
    );
    $st->execute([$meetingId]);
    foreach ($st->fetchAll() as $r) {
        $k = (string)$r['status'];
        if (isset($out[$k])) $out[$k][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
    }
    return $out;
}

function meeting_redeliste(array $m): array
{
    $id = (int)$m['id'];
    $token = trim((string)($m['redeliste_token'] ?? ''));
    $key   = trim((string)($m['redeliste_key'] ?? ''));
    if ($token === '' || $key === '') {
        if ($token === '') $token = bin2hex(random_bytes(6));
        if ($key === '')   $key   = bin2hex(random_bytes(8));
        db()->prepare('UPDATE meetings SET redeliste_token = ?, redeliste_key = ? WHERE id = ?')->execute([$token, $key, $id]);
    }
    $session = 'room-' . $token;
    $q = 'redeliste.html?session=' . rawurlencode($session);
    // Geplanter Beginn mitgeben: zeigt Beitretenden, wann es (ungefähr) losgeht, solange die
    // Redeliste noch nicht gestartet wurde. BEWUSST als reine Ziffernfolge (JJJJMMTTHHMM) und
    // nicht als Klartext „08.07.2026, 20:00 Uhr": Der musste kodiert werden (%2C, %20, %3A), und
    // die Link-Erkennung von Mailprogrammen und Word hat die Adresse mittendrin abgeschnitten –
    // der Rest landete als toter Text dahinter. redeliste.html formatiert die Ziffern selbst.
    $startStamp = ($t = strtotime((string)($m['starts_at'] ?? ''))) ? date('YmdHi', $t) : '';
    if ($startStamp !== '') $q .= '&start=' . $startStamp;
    $mit = '&mitglied=' . rawurlencode(REDELISTE_MEMBER_KEY);
    return [
        'session'       => $session,
        'lead'          => app_url($q . '&key=' . rawurlencode($key)), // Leitung (steuert, sieht intern)
        'join'          => app_url($q . $mit),                          // AStA-Mitglied (sieht intern)
        'guest'         => app_url($q),                                 // Gast (nur öffentlicher Teil)
        'beamer'        => app_url($q . '&ansicht'),                    // Beamer/Mitlesen öffentlich
        'beamer_intern' => app_url($q . '&ansicht' . $mit),            // Beamer intern
    ];
}

/** Persönlicher Beitritts-Link eines Mitglieds: vorausgewählter Name + Pronomen, eindeutige ID. */
function member_join_url(array $rede, array $me): string
{
    return $rede['join']
        . '&me=' . rawurlencode('m' . (int)$me['id'])
        . '&name=' . rawurlencode(short_name((string)($me['name'] ?? '')))
        . '&pron=' . rawurlencode(trim((string)($me['pronouns'] ?? '')));
}

/**
 * Leitungs-Link mit erfasster Identität: geheimer Key + eigener Name/Pronomen,
 * damit auch die Sitzungsleitung als Person (mit Liste & Pronomen) geführt wird.
 * Ohne Mitglied (z. B. Technik-Login) bleibt es der reine Leitungs-Link.
 */
function leader_join_url(array $rede, ?array $me): string
{
    if (!$me) return $rede['lead'];
    return $rede['lead']
        . '&me=' . rawurlencode('m' . (int)$me['id'])
        . '&name=' . rawurlencode(short_name((string)($me['name'] ?? '')))
        . '&pron=' . rawurlencode(trim((string)($me['pronouns'] ?? '')));
}

/**
 * Schreibt den fertigen Redelisten-Zustand der Sitzung direkt in den Raum:
 * komplette Tagesordnung (inkl. eingereichte TOPs) + Stammliste (aktive Mitglieder mit Pronomen).
 * Danach kann die Leitung die Liste sofort nutzen – nichts mehr auszufüllen.
 */
/**
 * Öffnet die standalone-Redelisten-SQLite (gleiche Datei/Schema wie redeliste-sync.php).
 * Bewusst eine EIGENE DB unter redeliste-data/ – die Redeliste bleibt unabhängig von der App-DB.
 */
function redeliste_db(): ?PDO
{
    $dir = __DIR__ . '/redeliste-data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    try {
        $db = new PDO('sqlite:' . $dir . '/redeliste.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA busy_timeout = 4000');
        $db->exec('CREATE TABLE IF NOT EXISTS rooms (session TEXT PRIMARY KEY, key TEXT, state TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL)');
        return $db;
    } catch (\Throwable $e) { return null; }
}

function redeliste_seed(array $m): void
{
    meeting_redeliste($m); // stellt Token/Key sicher
    $row = db()->prepare('SELECT redeliste_token, redeliste_key FROM meetings WHERE id = ?');
    $row->execute([(int)$m['id']]);
    $r = $row->fetch();
    $token = (string)($r['redeliste_token'] ?? '');
    $key   = (string)($r['redeliste_key'] ?? '');
    if ($token === '' || $key === '') return;
    $session = 'room-' . $token;

    // Tagesordnung -> TOPs der Redeliste ({label, title, minutes, intern, noList, report})
    // noList = in der TO sichtbar, aber kein Rede-TOP (Navigation überspringt ihn).
    // report = Berichte-TOP: sammelt Meldungen und wird in der Redeliste zu eigenen Bericht-Untertops.
    $tops = [];
    $maj = 0; $min = 0;
    foreach (merged_agenda($m) as $it) {
        if (!empty($it['sub'])) { $min++; $label = $maj . '.' . $min; }
        else { $maj++; $min = 0; $label = (string)$maj; }
        $te = trim((string)($it['time'] ?? ''));
        $tops[] = ['label' => $label, 'title' => (string)$it['text'], 'minutes' => is_numeric($te) ? (int)$te : null,
                   'intern' => !empty($it['internal']), 'noList' => !empty($it['no_list']), 'report' => !empty($it['report'])];
    }
    // Start auf dem ersten echten Rede-TOP (formale TOPs am Anfang überspringen)
    $startTop = 0;
    foreach ($tops as $i => $t) { if (empty($t['noList'])) { $startTop = $i; break; } }

    // Stammliste -> Teilnehmende (aktive Mitglieder, Liste wählen sie selbst beim Beitritt)
    // present=false: Mitglied ist noch NICHT beigetreten → erscheint NICHT in der linken Leiste,
    // sondern nur im „Hinzufügen"-Dialog der Leitung. Tritt es selbst bei (eigenes Gerät), wird es
    // present=true + online=true (Krone). Die Leitung kann es auch ohne Gerät hinzufügen (present=true,
    // online bleibt false). member=true unterscheidet AStA-Mitglieder von externen Gästen.
    $parts = [];
    foreach (members_all() as $mem) {
        $mid = (int)$mem['id'];
        // Nichts aus dem Belohnungs-Locker in den Raum-Stand: keine Avatar-Farbe, kein Schmuck, kein
        // Royal-Titel. Die Redeliste ist Sitzungswerkzeug – hier zählt, wer dran ist.
        $parts[] = ['id' => 'm' . $mid, 'name' => short_name((string)$mem['name']),
                    'pronouns' => trim((string)($mem['pronouns'] ?? '')), 'status' => 'AStA', 'list' => 'offen', 'count' => 0,
                    'present' => false, 'online' => false, 'member' => true];
    }

    // Bereits in der App gesetzte:r Protokollant:in → in den Raum mitgeben (Leitung sieht/ändert es dort).
    $ptId = (int)($m['protocol_taker_id'] ?? 0);
    $protocolTaker = null;
    if ($ptId) {
        foreach ($parts as $pp) { if ($pp['id'] === 'm' . $ptId) { $protocolTaker = ['id' => 'm' . $ptId, 'name' => $pp['name']]; break; } }
    }

    $state = [
        'participants' => $parts,
        'queue' => ['flinta' => [], 'offen' => []],
        'nextList' => 'flinta',
        'nowSpeaking' => null,
        'tops' => $tops,
        'currentTop' => $startTop,
        'topStartedAt' => null,
        'topHadMeldungen' => false,
        'sessionTitle' => meeting_label($m),
        'sessionActive' => true,
        'protocolTaker' => $protocolTaker,
    ];

    // Raum in der SQLite beanspruchen (Leitungs-Key) + Zustand setzen. Version steigt monoton,
    // auch beim Neu-Seed („Redeliste starten" erneut), damit pollende Clients den neuen Stand sehen.
    $db = redeliste_db();
    if (!$db) return;
    $curV = $db->prepare('SELECT version FROM rooms WHERE session = ?');
    $curV->execute([$session]);
    $nv = (int)($curV->fetchColumn() ?: 0) + 1;
    $db->prepare('INSERT INTO rooms(session, key, state, version, updated_at) VALUES(?,?,?,?,?)
        ON CONFLICT(session) DO UPDATE SET key = excluded.key, state = excluded.state, version = excluded.version, updated_at = excluded.updated_at')
       ->execute([$session, $key, json_encode($state, JSON_UNESCAPED_UNICODE), $nv, time()]);
}

/** Wurde die Redeliste dieser Sitzung schon einmal gestartet (Raum in der SQLite vorhanden)? */
function redeliste_started(array $m): bool
{
    $token = trim((string)($m['redeliste_token'] ?? ''));
    if ($token === '') return false;
    $db = redeliste_db(); if (!$db) return false;
    $st = $db->prepare('SELECT 1 FROM rooms WHERE session = ?');
    $st->execute(['room-' . $token]);
    return (bool)$st->fetchColumn();
}

/** Läuft im Redelisten-Raum dieser Sitzung gerade eine (nicht beendete) Sitzung? */
function redeliste_is_active(array $m): bool
{
    $token = trim((string)($m['redeliste_token'] ?? ''));
    if ($token === '') return false;
    $db = redeliste_db(); if (!$db) return false;
    $st = $db->prepare('SELECT state FROM rooms WHERE session = ?');
    $st->execute(['room-' . $token]);
    $state = $st->fetchColumn();
    if ($state === false) return false;
    $s = json_decode((string)$state, true);
    return is_array($s) && !empty($s['sessionActive']);
}

/**
 * Setzt die Redeliste einer Sitzung komplett zurück: alle Raum-Dateien löschen.
 * Danach gilt sie wieder als „nicht gestartet" (Wartebildschirm), NICHT als „beendet".
 */
function redeliste_reset(array $m): void
{
    $token = trim((string)($m['redeliste_token'] ?? ''));
    if ($token === '') return;
    $db = redeliste_db(); if (!$db) return;
    $db->prepare('DELETE FROM rooms WHERE session = ?')->execute(['room-' . $token]);
}

// ---------------------------------------------------------------------------
// Sitzungseinladungen (Sekretariat): Fristen, Teams-Link, Freigabe, Auto-Versand
// ---------------------------------------------------------------------------
function top_deadline_days(): int    { return max(0, (int)setting_get('top_deadline_days', '5')); }
function invite_lead_days(): int     { return max(0, (int)setting_get('invite_lead_days', '5')); }
function invite_reminder_days(): int { return max(0, (int)setting_get('invite_reminder_days', '2')); }
function invite_from_addr(): string  { $v = trim((string)setting_get('invite_from', '')); return $v !== '' ? $v : mail_from(); }
function invite_to_addr(): string    { return trim((string)setting_get('invite_to', '')); }

/**
 * Versandmodus der Einladungen:
 *   'text' (Standard) – Sekki kopiert den fertigen Text und versendet selbst über das Verteiler-Webinterface,
 *                       danach manuell „als versendet" markieren. KEIN automatischer Mailversand.
 *   'auto'            – die App verschickt die freigegebene Einladung am Stichtag automatisch an den Verteiler.
 * Umschaltbar unter Verwaltung → Sekretariatsaufgaben.
 */
function invite_mode(): string      { return setting_get('invite_mode', 'text') === 'auto' ? 'auto' : 'text'; }
function invite_text_mode(): bool   { return invite_mode() !== 'auto'; }

/** Stichtag (Unix-Zeit, 00:00 des Tages), an dem die Einladung rausgehen soll: Sitzungstag − Vorlauf. */
function invite_due_ts(array $m): int
{
    $base = strtotime(substr((string)($m['starts_at'] ?? ''), 0, 10) . ' 00:00:00');
    return $base ? strtotime('-' . invite_lead_days() . ' days', $base) : PHP_INT_MAX;
}

/** Letzter Tag (Y-m-d), an dem noch TOPs eingereicht werden können: Sitzungstag − TOP-Frist. */
function top_deadline_date(array $m): string
{
    $base = strtotime(substr((string)($m['starts_at'] ?? ''), 0, 10) . ' 00:00:00');
    return $base ? date('Y-m-d', strtotime('-' . top_deadline_days() . ' days', $base)) : '';
}

/** Dürfen für diese Sitzung noch TOPs eingereicht werden? (bis Ende des Frist-Tages, Sitzung in der Zukunft) */
function top_submission_open(array $m): bool
{
    if (!empty($m['cancelled'])) return false;
    $start = strtotime((string)($m['starts_at'] ?? ''));
    if (!$start || $start < time()) return false;
    $end = strtotime(top_deadline_date($m) . ' 23:59:59');
    return $end ? time() <= $end : true;
}

/** Bezeichnung für die Einladung, z. B. „7. ordentlichen" / „außerordentlichen". */
function invite_meeting_phrase(array $m): string
{
    $kind = $m['kind'] ?? 'ordentlich';
    if ($kind === 'ordentlich')       { $n = meeting_number($m); return ($n ? $n . '. ' : '') . 'ordentlichen'; }
    if ($kind === 'ausserordentlich') return 'außerordentlichen';
    return meeting_label($m);
}
function invite_meeting_phrase_en(array $m): string
{
    $kind = $m['kind'] ?? 'ordentlich';
    if ($kind === 'ordentlich')       { $n = meeting_number($m); return $n ? $n . '.' : ''; }
    if ($kind === 'ausserordentlich') return 'extraordinary';
    return '';
}

/** „Mittwoch den 24. Juni um 20:00 Uhr" */
function invite_datetime_de(array $m): string
{
    $d = dt($m['starts_at'] ?? null); if (!$d) return '';
    $months = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    return WD_LONG[(int)$d->format('N') - 1] . ' den ' . (int)$d->format('j') . '. ' . $months[(int)$d->format('n') - 1] . ' um ' . $d->format('H:i') . ' Uhr';
}
/** „the 24th of June at 20:00" */
function invite_datetime_en(array $m): string
{
    $d = dt($m['starts_at'] ?? null); if (!$d) return '';
    $day = (int)$d->format('j');
    $suf = ($day % 10 === 1 && $day !== 11) ? 'st' : (($day % 10 === 2 && $day !== 12) ? 'nd' : (($day % 10 === 3 && $day !== 13) ? 'rd' : 'th'));
    return 'the ' . $day . $suf . ' of ' . $d->format('F') . ' at ' . $d->format('H:i');
}

/**
 * Tagesordnung als „TOP 1: …"-Liste (Plaintext, eine Zeile pro Haupt-TOP) für die Einladung.
 * Unterpunkte (1.1, 1.2 …) bleiben bewusst draußen – die Einladung nennt nur die Haupt-TOPs.
 * Interne TOPs (nach dem [INTERN]-Trenner, in merged_agenda zusammenhängend am Ende)
 * werden NICHT einzeln aufgeführt – sie werden durch einen einzigen Sammel-TOP ersetzt:
 * „TOP X: Interner Teil (nicht öffentlich nach §10 der GO)".
 */
function invite_agenda_lines(array $m): string
{
    $maj = 0; $out = [];
    foreach (merged_agenda($m) as $it) {
        if (!empty($it['internal'])) {            // interner Teil beginnt → Sammel-TOP, Rest weglassen
            $maj++;
            $out[] = 'TOP ' . $maj . ': Interner Teil (nicht öffentlich nach §10 der GO)';
            break;
        }
        if (!empty($it['sub'])) continue;         // Unterpunkte gehören nicht in die Einladung
        $maj++;
        $out[] = 'TOP ' . $maj . ': ' . (string)$it['text'];
    }
    return implode("\n", $out);
}

/** Verfügbare Platzhalter für Betreff/Text der Einladung (Name => kurze Erklärung). */
function invite_placeholders(): array
{
    return [
        '{{SITZUNG}}'             => 'Sitzungsbezeichnung, z. B. „7. ordentlichen"',
        '{{SITZUNG_EN}}'          => 'Bezeichnung englisch, z. B. „7."',
        '{{DATUM}}'               => 'Datum/Uhrzeit, z. B. „Mittwoch den 24. Juni um 20:00 Uhr"',
        '{{DATUM_EN}}'            => 'Datum englisch, z. B. „the 24th of June at 20:00"',
        '{{RAUM}}'                => 'Raum der Sitzung',
        '{{TEAMS_LINK}}'          => 'Teams-Beitrittslink (URL)',
        '{{REDELISTE_GAST_LINK}}' => 'Gast-Link zur Redeliste (URL)',
        '{{TAGESORDNUNG}}'        => 'Aktuelle Tagesordnung als „TOP 1: …"-Liste (nur Haupt-TOPs, ohne Unterpunkte)',
    ];
}

/** Platzhalter im Text/Betreff durch die konkreten Werte der Sitzung ersetzen. */
function invite_render(string $tpl, array $m): string
{
    $teams = trim((string)($m['teams_link'] ?? ''));
    return strtr($tpl, [
        '{{SITZUNG}}'             => invite_meeting_phrase($m),
        '{{SITZUNG_EN}}'          => invite_meeting_phrase_en($m),
        '{{DATUM}}'               => invite_datetime_de($m),
        '{{DATUM_EN}}'            => invite_datetime_en($m),
        '{{RAUM}}'                => (string)($m['location'] ?? ''),
        '{{TEAMS_LINK}}'          => $teams !== '' ? $teams : '(Teams-Link fehlt)',
        '{{REDELISTE_GAST_LINK}}' => meeting_redeliste($m)['guest'],
        '{{TAGESORDNUNG}}'        => invite_agenda_lines($m),
    ]);
}

/** Standard-Betreff (mit Platzhaltern), falls in den Einstellungen keiner gesetzt ist. */
function default_invite_subject(): string
{
    return 'Einladung zur {{SITZUNG}} AStA-Sitzung';
}

/**
 * Standard-Vorlage der Einladungsmail (Plaintext mit Platzhaltern) – greift, solange keine
 * eigene gespeichert ist. Bewusst ein GERÜST und nicht die fertige Mail eines bestimmten
 * AStA: Kurs-Links, Zugänge und die Signatur des Sekretariats sind Inhalt dieser
 * Installation und stehen in den Einstellungen, nicht im Programm. Wer die App neu
 * aufsetzt, schreibt seine Fassung einmal unter Sitzungen → Einladungen.
 */
function default_invite_template(): string
{
    return "Liebe Studis,\n\n"
        . "wir laden euch hiermit herzlich zur {{SITZUNG}} AStA-Sitzung dieser Legislatur am {{DATUM}} ein.\n\n"
        . "Die Sitzung wird im hybriden Format stattfinden. Die Präsenzsitzung wird in Raum {{RAUM}} stattfinden.\n\n"
        . "Online könnt ihr unter folgendem Link teilnehmen:\n{{TEAMS_LINK}}\n\n"
        . "Für Wortmeldungen nutzen wir eine digitale Redeliste – als Gast könnt ihr hier beitreten:\n{{REDELISTE_GAST_LINK}}\n\n"
        . "Wir werden folgende Tagesordnungspunkte behandeln:\n{{TAGESORDNUNG}}\n\n"
        . "Bei Fragen, Bemerkungen oder Anregungen meldet euch gerne bei uns.\n\n"
        . "Studentische Grüße,\n\n"
        . "--\n\n"
        . "Dear students,\n\n"
        . "we are inviting you to our {{SITZUNG_EN}} AStA Meeting in this legislature. It takes place on {{DATUM_EN}} in room {{RAUM}}.\n\n"
        . "You can join us online with the following link:\n{{TEAMS_LINK}}\n\n"
        . "For speaking requests we use a digital speakers\' list – you can join as a guest here:\n{{REDELISTE_GAST_LINK}}\n\n"
        . "The Meeting will be in german but if you have some questions or inquiries, we can answer them in English also.\n\n"
        . "Kind regards!";
}

/** Aktive Vorlage (eigene aus den Einstellungen, sonst Standard). Immer Plaintext:
 *  eine früher gespeicherte HTML-Vorlage wird beim Lesen automatisch in Text gewandelt
 *  (Absätze/Zeilenumbrüche bleiben, Links werden zu nackten URLs). */
function invite_template(): string
{
    $t = (string)setting_get('invite_template', '');
    if (trim($t) === '') return default_invite_template();
    if (strip_tags($t) !== $t) { // Alt-Vorlage im HTML-Format → einmalig konvertieren
        $t = preg_replace('/<a\b[^>]*href="([^"]*)"[^>]*>\s*\1\s*<\/a>/is', '$1', $t);           // <a href=X>X</a> → X
        $t = preg_replace('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', (string)$t); // sonst „Text (URL)"
        $t = preg_replace('/<\/p\s*>/i', "\n\n", (string)$t);
        $t = preg_replace('/<br\s*\/?>/i', "\n", (string)$t);
        $t = preg_replace('/<hr[^>]*>/i', "\n--\n", (string)$t);
        $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8');
        $t = trim(preg_replace("/\n{3,}/", "\n\n", (string)$t));
    }
    return $t;
}

function invite_mail_subject(array $m): string
{
    $s = (string)setting_get('invite_subject', '');
    if (trim($s) === '') $s = default_invite_subject();
    return strtr($s, ['{{SITZUNG}}' => invite_meeting_phrase($m), '{{SITZUNG_EN}}' => invite_meeting_phrase_en($m)]);
}

/** Soll die Einladung als HTML verschickt werden? (Standard ja) */
function invite_is_html(): bool
{
    return (string)setting_get('invite_format', 'html') !== 'plain';
}

/** Einladung als HTML (für den Auto-Versand im HTML-Format): die Plaintext-Vorlage wird
 *  sicher verpackt – Escaping, Zeilenumbrüche als <br>, nackte URLs klickbar. */
function invite_mail_html(array $m): string
{
    $txt = h(invite_render(invite_template(), $m));
    // Nackte URLs klickbar machen. Der Abschluss ist bewusst eng gefasst: Ein Punkt oder Komma
    // am Satzende gehört NICHT mehr zur Adresse, sonst führt der Link ins Leere.
    $txt = preg_replace('/(https?:\/\/[^\s<]*[^\s<.,;:!?)\]])/i', '<a href="$1">$1</a>', $txt);
    return '<div style="font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2b30;line-height:1.55">'
        . nl2br((string)$txt) . '</div>';
}

/** Einladung als Plaintext – die Vorlage IST Plaintext, also einfach rendern. */
function invite_mail_text(array $m): string
{
    return invite_render(invite_template(), $m);
}

/** Empfänger der Reminder-Mails: aktive Mitglieder mit Sekretariats-/Vorsitz-Rolle. */
function invite_reminder_recipients(): array
{
    return db()->query("SELECT * FROM members WHERE active = 1 AND email <> '' AND role IN ('sekretariat','vorsitz')")->fetchAll();
}

/** Kommende Sitzungen, die noch eine Einladung brauchen (für die Sekki-Übersicht). */
function pending_invitations(): array
{
    return db()->query(
        "SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND invite_required = 1
           AND (invite_sent_at IS NULL OR invite_sent_at = '') AND date(starts_at) >= date('now','localtime')
         ORDER BY starts_at"
    )->fetchAll();
}

/** Cron: freigegebene Einladungen am/ab Stichtag automatisch verschicken. Gibt versendete Sitzungen zurück. */
function send_meeting_invites(): array
{
    if (invite_text_mode()) return []; // Text-Modus: kein automatischer Versand (Sekki versendet selbst)
    $to = invite_to_addr();
    if ($to === '') return [];
    $now = time();
    $rows = db()->query(
        "SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND invite_required = 1 AND invite_approved = 1
           AND teams_link <> '' AND (invite_sent_at IS NULL OR invite_sent_at = '')
           AND date(starts_at) >= date('now','localtime')"
    )->fetchAll();
    $sent = [];
    foreach ($rows as $m) {
        if ($now < invite_due_ts($m)) continue; // Stichtag noch nicht erreicht
        $html = invite_is_html();
        if (send_mail($to, invite_mail_subject($m), $html ? invite_mail_html($m) : invite_mail_text($m), $html, invite_from_addr())) {
            db()->prepare('UPDATE meetings SET invite_sent_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int)$m['id']]);
            $sent[] = meeting_label($m);
        }
    }
    return $sent;
}

/** Cron: Sekretariat erinnern, noch nicht freigegebene Einladungen vorzubereiten. Gibt Anzahl Sitzungen zurück. */
function send_invite_reminders(): int
{
    if ((string)setting_get('invite_reminder_on', '1') !== '1') return 0; // Reminder-Mails global deaktiviert
    $recips = invite_reminder_recipients();
    if (!$recips) return 0;
    $now = time();
    // Text-Modus: erinnern, solange noch nicht als versendet markiert. Auto-Modus: solange noch nicht freigegeben.
    $openCond = invite_text_mode()
        ? "(invite_sent_at IS NULL OR invite_sent_at = '')"
        : "invite_approved = 0";
    $rows = db()->query(
        "SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND invite_required = 1 AND invite_reminder = 1
           AND $openCond AND (invite_reminded_at IS NULL OR invite_reminded_at = '')
           AND date(starts_at) >= date('now','localtime')"
    )->fetchAll();
    $count = 0;
    foreach ($rows as $m) {
        if ($now < invite_due_ts($m) - invite_reminder_days() * 86400) continue; // Reminder-Fenster noch nicht offen
        $url = app_url('meeting.php?id=' . (int)$m['id']);
        foreach ($recips as $r) {
            mail_tpl_send('invite_reminder', member_mail($r), [
                '{{VORNAME}}' => first_name((string)$r['name']),
                '{{SITZUNG}}' => meeting_label($m),
                '{{DATUM}}' => fmt_date(substr((string)$m['starts_at'], 0, 10)),
                '{{STICHTAG}}' => fmt_date(date('Y-m-d', invite_due_ts($m))),
                '{{LINK}}' => $url,
            ]);
        }
        db()->prepare('UPDATE meetings SET invite_reminded_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int)$m['id']]);
        $count++;
    }
    return $count;
}

/**
 * Sekretariats-Übersicht (Bericht-Status zur nächsten berichtspflichtigen Sitzung
 * + eingereichte TOPs zur nächsten Sitzung) als HTML. Wird im Dashboard (Sekretariat)
 * und in der Verwaltung (Vorsitz/Admin) genutzt.
 */
function sekretariat_overview_html(bool $withSettings = false): string
{
    $b = base();
    $rm = current_report_meeting();
    $referate = referate_list();
    $nm = next_meeting();
    $subs = $nm ? submissions_for((int)$nm['id']) : [];
    ob_start();
    ?>
    <?php if ($withSettings): // kleines Zahnrad oben rechts – nur diese (Dashboard-)Kachel ist einstellbar ?>
    <details class="sekki-gear">
      <summary title="Einstellungen"><i class="ti ti-settings"></i></summary>
      <div class="sekki-gear-pop">
        <form method="post" action="<?= $b ?>dashboard.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_sekki_settings">
          <input type="hidden" name="return" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
          <label class="inline" style="margin:0 0 .5rem"><input type="checkbox" name="invite_reminder_on" <?= (string)setting_get('invite_reminder_on', '1') !== '0' ? 'checked' : '' ?>> Reminder-Mail ans Sekretariat</label>
          <label class="small">Reminder-Vorlauf (Tage vor Stichtag)<input type="number" min="0" name="invite_reminder_days" value="<?= h((string)invite_reminder_days()) ?>"></label>
          <label class="small">Einladung verschicken (Tage vor Sitzung)<input type="number" min="0" name="invite_lead_days" value="<?= h((string)invite_lead_days()) ?>"></label>
          <label class="small">TOP-Einreichefrist (Tage vor Sitzung)<input type="number" min="0" name="top_deadline_days" value="<?= h((string)top_deadline_days()) ?>"></label>
          <button class="btn small" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
        </form>
      </div>
    </details>
    <?php endif; ?>
    <div class="sekki-grid">
      <div>
        <div class="section-title" style="margin-top:0;font-size:1rem"><i class="ti ti-file-text"></i> Berichte</div>
        <?php if (!$rm): ?>
          <p class="muted small" style="margin:0">Aktuell keine berichtspflichtige Sitzung.</p>
        <?php else:
            $rep = reports_for_meeting((int)$rm['id']);
            $done = []; $miss = [];
            foreach ($referate as $r) { if (trim((string)($rep[$r] ?? '')) !== '') $done[] = $r; else $miss[] = $r; }
            $tot = max(1, count($referate)); $got = count($done);
            $pct = (int)round($got / $tot * 100);
        ?>
          <p class="small" style="margin:0 0 .4rem"><strong><?= h(meeting_label($rm)) ?></strong> <span class="muted">· <?= h(fmt_date(substr((string)$rm['starts_at'], 0, 10))) ?></span></p>
          <div class="progress-row">
            <div class="progress <?= $got >= count($referate) ? '' : ($got > 0 ? 'warn' : 'bad') ?>"><span style="width:<?= $pct ?>%"></span></div>
            <span class="small muted"><?= $got ?>/<?= count($referate) ?> Referate</span>
          </div>
          <?php $daysToMeet = days_until_d($rm['starts_at']); ?>
          <?php if (!$miss): ?>
            <p class="small" style="margin:.5rem 0 0;color:var(--green)"><i class="ti ti-check"></i> Alle Berichte abgegeben.</p>
          <?php elseif ($daysToMeet <= 2): // wer noch fehlt: erst 2 Tage vor der Sitzung ?>
            <p class="small" style="margin:.5rem 0 0">Fehlt noch:
              <?php foreach ($miss as $r): ?><span class="pill pill-bad" style="font-size:.72rem"><?= h($r) ?></span> <?php endforeach; ?>
            </p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div>
        <div class="section-title" style="margin-top:0;font-size:1rem"><i class="ti ti-list-check"></i> Eingereichte TOPs</div>
        <?php if (!$nm): ?>
          <p class="muted small" style="margin:0">Keine kommende Sitzung.</p>
        <?php elseif (!$subs): ?>
          <p class="muted small" style="margin:0">Noch keine TOPs zur <strong><?= h(meeting_label($nm)) ?></strong> eingereicht.</p>
        <?php else: ?>
          <p class="small" style="margin:0 0 .4rem">zur <strong><?= h(meeting_label($nm)) ?></strong>:</p>
          <ul class="sekki-tops">
            <?php foreach ($subs as $s): $te = trim((string)($s['time_est'] ?? '')); ?>
              <li>
                <span class="pill <?= (int)$s['internal'] === 1 ? 'pill-warn' : 'pill-info' ?>" style="font-size:.7rem"><?= (int)$s['internal'] === 1 ? 'intern' : 'öffentl.' ?></span>
                <strong><?= h($s['title']) ?></strong>
                <span class="muted small">· <?= h($s['member_name'] ?: '—') ?><?= $te !== '' ? ' · ' . h(is_numeric($te) ? $te . ' Min' : $te) : '' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <a class="btn small secondary" href="<?= $b ?>meeting.php?id=<?= (int)$nm['id'] ?>"><i class="ti ti-arrow-right"></i> Zur Sitzung</a>
        <?php endif; ?>
      </div>
    </div>
    <?php $pend = pending_invitations(); if ($pend):
        $pm = $pend[0]; // immer nur die nächste Sitzung
        $due = invite_due_ts($pm);
        $textMode = invite_text_mode();
        $approved = (int)($pm['invite_approved'] ?? 0) === 1;
        $hasTeams = trim((string)($pm['teams_link'] ?? '')) !== '';
        $ready = $textMode ? $hasTeams : ($approved && $hasTeams); // Text-Modus: bereit, sobald Teams-Link da ist
        $urgent = !$ready && ($due - time()) <= 86400; // ab einem Tag vor dem Stichtag (oder überfällig)
    ?>
    <div class="section-title" style="font-size:1rem;margin-top:.9rem"><i class="ti ti-mail-forward"></i> Einladungen</div>
    <ul class="sekki-tops">
      <li>
        <?php if ($ready): ?><span class="pill pill-info" style="font-size:.7rem"><?= $textMode ? 'bereit' : 'freigegeben' ?></span>
        <?php elseif ($urgent): ?><span class="pill pill-bad" style="font-size:.7rem">DRINGEND</span>
        <?php else: ?><span class="pill pill-warn" style="font-size:.7rem">offen</span><?php endif; ?>
        <strong><?= h(meeting_label($pm)) ?></strong>
        <span class="muted small">· <?= $textMode ? 'bis' : 'Versand' ?> <?= h(fmt_date(date('Y-m-d', $due))) ?><?= !$hasTeams ? ' · Teams-Link fehlt' : ($textMode ? '' : ($approved ? '' : ' · nicht freigegeben')) ?></span>
        <a class="small" href="<?= $b ?>meeting.php?id=<?= (int)$pm['id'] ?>">bearbeiten</a>
      </li>
    </ul>
    <?php endif; ?>
    <?php $protPub = protocol_publish_pending(); if ($protPub): ?>
    <div class="section-title" style="font-size:1rem;margin-top:.9rem"><i class="ti ti-notebook"></i> Protokoll veröffentlichen</div>
    <ul class="sekki-tops">
      <?php foreach ($protPub as $pp): ?>
      <li>
        <span class="pill pill-warn" style="font-size:.7rem">angenommen</span>
        <strong><?= h(meeting_label($pp)) ?></strong>
        <span class="muted small">· als PDF nach OLAT</span>
        <a class="small" href="<?= $b ?>meeting.php?id=<?= (int)$pp['id'] ?>#protokoll">veröffentlichen</a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Berichte (Referate, zyklusweise zur nächsten berichtspflichtigen Sitzung)
// ---------------------------------------------------------------------------
/** Nächste nicht-ausgefallene Sitzung, die einen Bericht verlangt (oder null). */
function current_report_meeting(): ?array
{
    $st = db()->query("SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND needs_report = 1 AND date(starts_at) >= date('now','localtime') ORDER BY starts_at LIMIT 1");
    return $st->fetch() ?: null;
}

/** Letzte nicht-ausgefallene Sitzung vor einem Zeitpunkt (für „Zeitraum seit …"). */
function meeting_before(string $startsAt): ?array
{
    $st = db()->prepare("SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa' AND starts_at < ? ORDER BY starts_at DESC LIMIT 1");
    $st->execute([$startsAt]);
    return $st->fetch() ?: null;
}

/** Alle verwalteten Referate (Zeilen mit id/name/sort) in Reihenfolge. */
function referate_all(): array
{
    return db()->query('SELECT * FROM referate ORDER BY sort, name COLLATE NOCASE')->fetchAll();
}

/** Referat-Namen in verwalteter Reihenfolge – bestimmt die Reihenfolge im Bericht. */
function referate_list(): array
{
    return array_map(fn($r) => (string)$r['name'], referate_all());
}

/** Berichte einer Sitzung als [referat => content]. */
function reports_for_meeting(int $meetingId): array
{
    $st = db()->prepare('SELECT referat, content FROM reports WHERE meeting_id = ?');
    $st->execute([$meetingId]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[(string)$r['referat']] = (string)$r['content'];
    return $map;
}

function report_get(string $referat, int $meetingId): ?array
{
    $st = db()->prepare('SELECT * FROM reports WHERE referat = ? AND meeting_id = ?');
    $st->execute([$referat, $meetingId]);
    return $st->fetch() ?: null;
}

/** Stichpunkte säubern: führende Aufzählungszeichen/Striche entfernen, Leerzeilen raus. */
function report_clean(string $content): string
{
    $clean = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $ln) {
        $ln = preg_replace('/^\s*[-–—•*·]+\s*/u', '', trim($ln));
        if ($ln !== '') $clean[] = $ln;
    }
    return implode("\n", $clean);
}

function report_save(string $referat, int $meetingId, string $content, int $memberId): void
{
    $content = report_clean($content);
    db()->prepare(
        "INSERT INTO reports(referat, meeting_id, content, updated_by, updated_at)
         VALUES(:r, :m, :c, :u, datetime('now','localtime'))
         ON CONFLICT(referat, meeting_id) DO UPDATE SET
            content = excluded.content, updated_by = excluded.updated_by, updated_at = excluded.updated_at"
    )->execute([':r' => $referat, ':m' => $meetingId, ':c' => $content, ':u' => $memberId]);
}

/**
 * Erinnert (1 Tag vor der nächsten berichtspflichtigen Sitzung) Mitglieder mit Referat,
 * deren Referat noch keinen Bericht hat. Max. 1× pro Sitzung & Person.
 * @return int Anzahl verschickter Mails
 */
function send_report_reminders(bool $dryRun = false): int
{
    $meeting = current_report_meeting();
    if (!$meeting) return 0;
    $daysLeft = days_until_d((string)$meeting['starts_at']);
    if ($daysLeft < 0 || $daysLeft > 3) return 0; // maximales Fenster (persönlich wählbar 1–3 Tage; Sitzungstag als Sicherheitsnetz)

    $map = reports_for_meeting((int)$meeting['id']);
    $check = db()->prepare('SELECT 1 FROM report_reminders WHERE meeting_id = ? AND member_id = ?');
    $log   = db()->prepare('INSERT OR IGNORE INTO report_reminders(meeting_id, member_id, sent_on) VALUES(?,?,?)');
    $sent = 0;
    foreach (members_all() as $m) {
        $ref = trim((string)($m['referat'] ?? ''));
        if ($ref === '' || trim((string)$m['email']) === '') continue;
        if ($daysLeft > notify_pref((int)$m['id'], 'report_due')['timing']) continue; // persönlicher Vorlauf
        if (trim($map[$ref] ?? '') !== '') continue;     // Referat hat bereits einen Bericht
        $check->execute([(int)$meeting['id'], (int)$m['id']]);
        if ($check->fetchColumn()) continue;             // schon erinnert
        if ($dryRun) { $sent++; continue; }              // nur zählen, nichts senden/loggen
        if (notify_deliver((int)$m['id'], 'report_due', fn() => mail_tpl_send('report_reminder', member_mail($m), [
                '{{VORNAME}}' => first_name($m['name']),
                '{{SITZUNG}}' => meeting_label($meeting),
                '{{DATUM}}' => fmt_date(substr((string)$meeting['starts_at'], 0, 10)),
                '{{REFERAT}}' => $ref,
                '{{LINK}}' => app_url('report.php'),
            ]), 'Bericht fehlt noch: ' . $ref,
                'Für die ' . meeting_label($meeting) . ' fehlt noch der Bericht deines Referats.', app_url('report.php'))) {
            $log->execute([(int)$meeting['id'], (int)$m['id'], date('Y-m-d')]);
            $sent++;
        }
    }
    return $sent;
}

/**
 * Erinnert 2 Tage vor einer Sitzung alle aktiven Mitglieder per Mail, die noch nicht alle
 * Abstimmungsgegenstände dieser Sitzung gelesen haben. Pro Sitzung/Person nur einmal (Log-Tabelle).
 * Gibt die Zahl verschickter Mails zurück.
 */
function send_voteitem_reminders(bool $dryRun = false): int
{
    $rows = db()->query(
        "SELECT m.* FROM meetings m
         WHERE m.cancelled = 0 AND m.draft = 0
           AND date(m.starts_at) >= date('now','localtime')
           AND EXISTS (SELECT 1 FROM vote_items v WHERE v.meeting_id = m.id)
         ORDER BY m.starts_at"
    )->fetchAll();
    if (!$rows) return 0;
    $check = db()->prepare('SELECT 1 FROM voteitem_reminders WHERE meeting_id = ? AND member_id = ?');
    $log   = db()->prepare('INSERT OR IGNORE INTO voteitem_reminders(meeting_id, member_id, sent_on) VALUES(?,?,?)');
    $sent = 0;
    foreach ($rows as $meeting) {
        $daysLeft = days_until_d((string)$meeting['starts_at']);
        if ($daysLeft < 0 || $daysLeft > 7) continue;   // maximales Fenster (persönlich wählbar 1–7 Tage), bis zum Sitzungstag
        $mid = (int)$meeting['id'];
        $total = count(vote_items_for($mid));
        if ($total === 0) continue;
        foreach (members_all() as $m) {
            $memId = (int)$m['id'];
            if (trim((string)$m['email']) === '') continue;
            if ($daysLeft > notify_pref($memId, 'voteitems_unread')['timing']) continue; // persönlicher Vorlauf
            $readCount = count(vote_item_read_set($mid, $memId));
            if ($readCount >= $total) continue;          // schon alles gelesen
            $check->execute([$mid, $memId]);
            if ($check->fetchColumn()) continue;         // schon erinnert
            if ($dryRun) { $sent++; continue; }          // nur zählen, nichts senden/loggen
            if (notify_deliver($memId, 'voteitems_unread', fn() => mail_tpl_send('voteitem_reminder', member_mail($m), [
                    '{{VORNAME}}' => first_name($m['name']),
                    '{{SITZUNG}}' => meeting_label($meeting),
                    '{{DATUM}}'   => fmt_date(substr((string)$meeting['starts_at'], 0, 10)),
                    '{{ANZAHL}}'  => (string)($total - $readCount),
                    '{{LINK}}'    => app_url('meeting.php?id=' . $mid),
                ]), 'Abstimmungsgegenstände lesen',
                    'Zur ' . meeting_label($meeting) . ' sind noch ' . ($total - $readCount) . ' Gegenstände ungelesen.',
                    app_url('meeting.php?id=' . $mid))) {
                $log->execute([$mid, $memId, date('Y-m-d')]);
                $sent++;
            }
        }
    }
    return $sent;
}

/**
 * Inaktivitäts-Erinnerung (Opt-in-Push, ab Werk aus): erinnert, wenn die App seit X Tagen
 * (persönliches timing, Standard 5) nicht geöffnet wurde – bevor die Woche fürs
 * Basis-Score-Kriterium „App wöchentlich geöffnet" verfällt. Höchstens 1× je
 * Inaktivitäts-Phase: der Merker inactive_notified_on wird durch die nächste
 * App-Öffnung (last_active_on rückt vor) automatisch entwertet. Während einer
 * eingetragenen Abwesenheit wird nicht erinnert (die Woche zählt dann ohnehin nicht).
 */
function send_inactivity_reminders(bool $dryRun = false): int
{
    if (!push_available()) return 0;
    $today = date('Y-m-d');
    $sent = 0;
    foreach (members_all() as $m) {
        $mid = (int)$m['id'];
        $pref = notify_pref($mid, 'app_inactive');
        if (!$pref['push']) continue;                                   // Opt-in: nur wer es aktiv angeschaltet hat
        $last = trim((string)($m['last_active_on'] ?? ''));
        if ($last === '') continue;                                     // noch nie tagesgenau erfasst (frische Spalte)
        $days = (int)floor((strtotime($today) - strtotime($last)) / 86400);
        if ($days < max(1, $pref['timing'])) continue;
        $notified = trim((string)($m['inactive_notified_on'] ?? ''));
        if ($notified !== '' && $notified >= $last) continue;           // in dieser Phase schon erinnert
        if (member_absent_on($mid, $today)) continue;                   // abwesend gemeldet → in Ruhe lassen
        if ($dryRun) { $sent++; continue; }
        $ok = push_send($mid, 'Lange nicht gesehen 👋',
            'Du warst ' . $days . ' Tage nicht in der App. Schau kurz rein – sonst zählt die Woche im Basis-Score als „App nicht geöffnet".',
            app_url('dashboard.php'));
        if ($ok > 0) {
            db()->prepare('UPDATE members SET inactive_notified_on = ? WHERE id = ?')->execute([$today, $mid]);
            $sent++;
        }
    }
    return $sent;
}

/** Push-Erinnerung an König:innen des Sommers, die ihr „Hochsommer"-Geschenk 7 Tage nach der Freischaltung
 *  noch GAR NICHT genutzt haben (kein einziges verschenkt). Nur Push, genau einmal je Person (Dedup über
 *  members.sommer_reminded_on). Wird im Erinnerungs-Cron aufgerufen. Rückgabe: Anzahl verschickter Pushes. */
function send_sommer_gift_reminders(bool $dryRun = false): int
{
    if (!push_available()) return 0;
    $today = date('Y-m-d');
    $sent = 0;
    // Zwei Stufen mit eigenem Merker: die freundliche nach 7, die dringliche nach 14 Tagen.
    // Getrennte Spalten, weil sonst die erste Erinnerung die zweite unterdrücken würde.
    $stages = [
        ['days' => 7,  'col' => 'sommer_reminded_on',
         'title' => 'Verschenk den Sommer ☀️',
         'text'  => 'Als König:in des Sommers kannst du noch zwei Mal den „Hochsommer" verschenken – hast es aber noch nicht getan. Mach jemandem eine Freude!'],
        ['days' => 14, 'col' => 'sommer_reminded2_on',
         'title' => 'Dein Sommer wartet noch immer ☀️',
         'text'  => 'Du hast den „Hochsommer" noch an niemanden verschenkt. Mach es in den nächsten 14 Tagen – danach verfällt es.'],
    ];
    foreach ($stages as $st) {
        try {
            $rows = db()->query("SELECT ma.member_id AS id
                FROM member_achievements ma JOIN members m ON m.id = ma.member_id
                WHERE ma.code = 'sommerkoenig' AND m.active = 1
                  AND COALESCE(m." . $st['col'] . ", '') = ''
                  AND ma.earned_at <= datetime('now','localtime','-" . (int)$st['days'] . " days')")->fetchAll();
        } catch (\Throwable $e) { continue; }
        foreach ($rows as $r) {
            $mid = (int)$r['id'];
            if (sommer_gift_quota($mid) < 2) continue;      // schon (mind. eins) verschenkt → kein Nudge
            if (!sommer_gift_candidates($mid)) continue;    // niemand mehr zu beschenken → spar dir die Erinnerung
            if ($dryRun) { $sent++; continue; }
            $ok = push_send($mid, $st['title'], $st['text'], app_url('achievements.php'));
            if ($ok > 0) {
                db()->prepare('UPDATE members SET ' . $st['col'] . ' = ? WHERE id = ?')->execute([$today, $mid]);
                $sent++;
            }
        }
    }
    return $sent;
}

// ---- Word-Export (.docx) – ohne externe Bibliothek, nur ZipArchive + OOXML ----
function docx_escape(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Wie docx_escape(), macht aus Zeilenumbrüchen aber echte Word-Umbrüche.
 * Ein rohes \n im <w:t> ist bloßer Weißraum – Word macht daraus je nach Fassung ein Leerzeichen.
 */
function docx_text(string $s): string
{
    return str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">',
        docx_escape(str_replace(["\r\n", "\r"], "\n", $s)));
}

/** Ein Absatz; optional fett und/oder Schriftgröße in Punkt. */
function docx_paragraph(string $text, bool $bold = false, int $pt = 0): string
{
    $rpr = ($bold || $pt) ? '<w:rPr>' . ($bold ? '<w:b/>' : '') . ($pt ? '<w:sz w:val="' . ($pt * 2) . '"/>' : '') . '</w:rPr>' : '';
    return '<w:p><w:r>' . $rpr . '<w:t xml:space="preserve">' . docx_escape($text) . '</w:t></w:r></w:p>';
}

/** Verpackt fertiges OOXML-Body-Markup (Absätze/Tabellen) zu einer kompletten .docx (Binärinhalt). */
function docx_package(string $bodyXml): string
{
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $bodyXml
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';
    $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $relsRoot);
    $zip->addFromString('word/document.xml', $document);
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** 3-Spalten-Tabelle (Nr | Referat | Bericht) als OOXML. $rows: ['nr','referat','content']. */
function docx_reports_table(array $rows): string
{
    $colW = [560, 2220, 6700];
    $b = fn($e) => '<w:' . $e . ' w:val="single" w:sz="4" w:space="0" w:color="999999"/>';
    $borders = '<w:tblBorders>' . $b('top') . $b('left') . $b('bottom') . $b('right') . $b('insideH') . $b('insideV') . '</w:tblBorders>';
    $cell = fn(string $inner, int $w) => '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/></w:tcPr>' . $inner . '</w:tc>';
    $head = '<w:tr>' . $cell(docx_paragraph('Nr', true), $colW[0]) . $cell(docx_paragraph('Referat', true), $colW[1]) . $cell(docx_paragraph('Bericht', true), $colW[2]) . '</w:tr>';
    $bodyRows = '';
    foreach ($rows as $r) {
        $paras = '';
        $any = false;
        foreach (preg_split('/\r\n|\r|\n/', (string)$r['content']) as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $any = true;
            $paras .= docx_paragraph('•  ' . $ln);
        }
        if (!$any) $paras = docx_paragraph('BERICHT WIRD NACHGEREICHT', true);
        $bodyRows .= '<w:tr>'
            . $cell(docx_paragraph((string)$r['nr']), $colW[0])
            . $cell(docx_paragraph((string)$r['referat'], true), $colW[1])
            . $cell($paras, $colW[2])
            . '</w:tr>';
    }
    $grid = '<w:tblGrid>' . implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $colW)) . '</w:tblGrid>';
    return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>' . $borders . '</w:tblPr>' . $grid . $head . $bodyRows . '</w:tbl>';
}

// ---- Austauschbare Word-Vorlagen (im Frontend hochladbar) ----
const TEMPLATE_DIR = __DIR__ . '/data/templates';

/** Registry der Vorlagen: Schlüssel => [label, default-Pfad|null, desc, placeholders]. */
function docx_templates(): array
{
    return [
        'protokoll' => [
            'label' => 'Protokollvorlage',
            'default' => __DIR__ . '/assets/protokoll-vorlage.docx',
            'desc' => 'Wird beim Protokoll-Download pro Sitzung ausgefüllt (Layout/Logo bleiben erhalten).',
            'placeholders' => [
                '((Sitzungsdatum))' => 'Datum der Sitzung',
                '((VORSITZ))' => 'Namen aller Vorsitz-Personen',
                '((RefX))' => 'Anwesenheitsliste – eine je Mitglied (so viele ((RefX)) wie Plätze)',
                '((Tagesordnungspunkte))' => 'TO-Übersicht (bis ((TO-2)))',
                '((TO-2))' => 'Beginn des Protokoll-Gerüsts (öffentlicher Teil, ab hier wird ersetzt)',
            ],
        ],
        'protokoll_intern' => [
            'label' => 'Protokollvorlage (interner Teil)',
            'default' => __DIR__ . '/assets/protokoll-intern-vorlage.docx',
            'desc' => 'Wird beim Download „Internes Protokoll" pro Sitzung ausgefüllt (Layout/Logo bleiben erhalten). Datum und interne TOPs werden eingesetzt; Uhrzeiten („x Uhr") trägt die protokollierende Person live ein.',
            'placeholders' => [
                'dd.mm.202j' => 'wird durch das Sitzungsdatum ersetzt',
                'TOP 8.1' => 'Platzhalter-Absatz – wird durch die internen TOPs (korrekt nummeriert) ersetzt',
            ],
        ],
        'berichte' => [
            'label' => 'Berichte-Vorlage',
            'default' => null, // ohne Vorlage: einfache Tabelle aus dem Code
            'desc' => 'Optional. Wird beim „Berichte als Word"-Download ausgefüllt; ohne Vorlage entsteht eine einfache Tabelle.',
            'placeholders' => [
                '((SITZUNG))' => 'Sitzungsbezeichnung',
                '((DATUM))' => 'Datum der Sitzung',
                '((BERICHTE))' => 'Tabelle Nr | Referat | Bericht (ersetzt den ganzen Absatz)',
            ],
        ],
        'auszahlung' => [
            'label' => 'Auszahlungsaufforderung (StuPa)',
            'default' => __DIR__ . '/assets/auszahlung-vorlage.docx',
            'desc' => 'Wird beim Download einer Auszahlungsaufforderung ausgefüllt. Legislatur-Nummer und '
                . 'Präsidiums-Namen kommen aus den Angaben, die das Präsidium in seinem eigenen Bereich '
                . 'pflegt – an der Vorlage ist dafür nichts zu ändern.',
            'placeholders' => [
                '((LEG))' => 'Nummer des Parlaments (steht in der Kopfzeile, z. B. „38. Studierendenparlament")',
                '((PRAESIDIUM))' => 'Namen des Präsidiums, je Name eine Zeile',
                '((AA))' => 'Antragsteller:in (Institution kommt als zweite Zeile dazu)',
                '((AZ))' => 'Verwendungszweck', '((AB))' => 'Geldbetrag', '((AK))' => 'Kontoinhaber:in',
                '((AT))' => 'Topf – die Zeile mit diesen fünf wird je Posten geklont',
                '((AKI))' => 'Kontoinhaber:in im Anhang (Seite 2)',
                '((AIB))' => 'IBAN im Anhang – diese Zeile wird ebenfalls je Posten geklont',
            ],
        ],
        'belegblatt' => [
            'label' => 'Belegblatt für Auslagen',
            'default' => __DIR__ . '/assets/belegblatt-vorlage.docx',
            'desc' => 'Wird beim Download eines eingereichten Belegblatts (Finanzen-Seite) ausgefüllt (Layout/Logo bleiben erhalten).',
            'placeholders' => [
                '((NAME))' => 'Name, Vorname', '((STRASSE))' => 'Straße und Hausnummer', '((PLZORT))' => 'PLZ und Ort',
                '((BANK))' => 'Bankinstitut', '((BIC))' => 'BIC', '((IBAN))' => 'IBAN',
                '((VA))' => 'Kreuz „AStA" (☒/☐)', '((VS))' => 'Kreuz „StuPa"', '((VP))' => 'Kreuz „Projekt/Sonstiges"',
                '((PROJEKT))' => 'Freitext bei Projekt/Sonstiges', '((ANLASS))' => 'Für (Veranstaltung/Projekt/…)',
                '((BN))/((BD))/((BS))/((BB))' => 'Beleg-Zeile: Nr/Datum/Sachbeschreibung/Betrag – die Zeile wird je Position geklont',
                '((SUMME))' => 'Gesamtsumme', '((DATUM))' => 'Datum der Einreichung',
            ],
        ],
    ];
}

/** Effektiver Pfad einer Vorlage: hochgeladene (data/) bevorzugt, sonst mitgelieferte Standarddatei, sonst null. */
function docx_template_path(string $key): ?string
{
    $custom = TEMPLATE_DIR . '/' . $key . '.docx';
    if (is_file($custom)) return $custom;
    $def = docx_templates()[$key]['default'] ?? null;
    return ($def && is_file($def)) ? $def : null;
}
function docx_template_is_custom(string $key): bool { return is_file(TEMPLATE_DIR . '/' . $key . '.docx'); }

/** Hochgeladene Vorlage prüfen (.docx = Zip mit word/document.xml) und speichern. */
function save_uploaded_docx_template(string $key, array $file): bool
{
    if (!isset(docx_templates()[$key]) || !class_exists('ZipArchive')) return false;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) return false;
    $z = new ZipArchive();
    if ($z->open($file['tmp_name']) !== true) return false;
    $ok = $z->locateName('word/document.xml') !== false;
    $z->close();
    if (!$ok) return false;
    if (!is_dir(TEMPLATE_DIR)) @mkdir(TEMPLATE_DIR, 0775, true);
    return @move_uploaded_file($file['tmp_name'], TEMPLATE_DIR . '/' . $key . '.docx');
}
function reset_docx_template(string $key): void { if (isset(docx_templates()[$key])) @unlink(TEMPLATE_DIR . '/' . $key . '.docx'); }

/** Über Runs zerlegte Platzhalter „heilen" (nur die angegebenen Tokens, Tags innerhalb ((...)) entfernen). */
function docx_heal_placeholders(string $doc, array $known): string
{
    return (string)preg_replace_callback('/\(\((.*?)\)\)/s', function ($mm) use ($known) {
        $inner = trim(strip_tags($mm[1]));
        return in_array($inner, $known, true) ? '((' . $inner . '))' : $mm[0];
    }, $doc);
}

/** Den Absatz, der $token enthält, komplett durch $blockXml (z. B. eine Tabelle) ersetzen. */
function docx_replace_paragraph(string $doc, string $token, string $blockXml): string
{
    $pos = strpos($doc, $token);
    if ($pos === false) return $doc;
    $pStart = strrpos(substr($doc, 0, $pos), '<w:p ');
    $pEnd = strpos($doc, '</w:p>', $pos);
    if ($pStart === false || $pEnd === false) return $doc;
    return substr($doc, 0, $pStart) . $blockXml . substr($doc, $pEnd + strlen('</w:p>'));
}

/** Berichte als Word: nutzt die hochgeladene Berichte-Vorlage (Merge), sonst eine einfache Tabelle. */
function build_reports_docx(string $title, array $rows, ?array $m = null): string
{
    $tpl = docx_template_path('berichte');
    if ($tpl) {
        $merged = merge_reports_template($tpl, $title, $rows, $m);
        if ($merged !== null && $merged !== '') return $merged;
    }
    return docx_package(docx_paragraph($title, true, 16) . docx_paragraph('') . docx_reports_table($rows));
}

/** Berichte-Zeilen einer Sitzung (alle Referate, mit ggf. leerem Inhalt). */
function meeting_report_rows(array $meeting): array
{
    $map = reports_for_meeting((int)$meeting['id']);
    $rows = [];
    $nr = 1;
    foreach (referate_list() as $ref) {
        $rows[] = ['nr' => $nr++, 'referat' => $ref, 'content' => $map[$ref] ?? ''];
    }
    return $rows;
}

/** Wie viele Referate haben für die Sitzung tatsächlich einen Bericht eingetragen? */
function meeting_reports_filled(array $meeting): int
{
    $n = 0;
    foreach (meeting_report_rows($meeting) as $r) if (trim((string)$r['content']) !== '') $n++;
    return $n;
}

/** Komplette Berichte-DOCX einer Sitzung: ['bin'=>Binärinhalt, 'rows'=>…, 'title'=>…]. */
function meeting_reports_docx(array $meeting): array
{
    $rows  = meeting_report_rows($meeting);
    $title = 'Aktuelle Berichte der Referate – ' . meeting_label($meeting)
           . ' (' . fmt_date(substr((string)$meeting['starts_at'], 0, 10)) . ')';
    return ['bin' => build_reports_docx($title, $rows, $meeting), 'rows' => $rows, 'title' => $title];
}

/** Berichte in eine Vorlage mergen: ((SITZUNG)), ((DATUM)), ((BERICHTE))-Absatz → Tabelle. */
function merge_reports_template(string $tplFile, string $title, array $rows, ?array $m): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'rdocx');
    if (!@copy($tplFile, $tmp)) { @unlink($tmp); return null; }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }
    $doc = $zip->getFromName('word/document.xml');
    if ($doc === false) { $zip->close(); @unlink($tmp); return null; }
    $doc = docx_heal_placeholders((string)$doc, ['SITZUNG', 'DATUM', 'BERICHTE']);
    $sitzung = $m ? meeting_label($m) : $title;
    $datum = ($m && ($t = strtotime((string)($m['starts_at'] ?? '')))) ? date('d.m.Y', $t) : '';
    $doc = str_replace('((SITZUNG))', docx_escape($sitzung), $doc);
    $doc = str_replace('((DATUM))', docx_escape($datum), $doc);
    $doc = docx_replace_paragraph($doc, '((BERICHTE))', docx_reports_table($rows));
    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', $doc);
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

// ---------------------------------------------------------------------------
// Finanzen: Belegblätter für Auslagen – Mitglieder reichen ein (finanzen.php),
// die Rolle „finanzen" lädt die ausgefüllte DOCX herunter und markiert erledigt.
// ---------------------------------------------------------------------------

/** Cent-Betrag als deutscher Euro-String (1234 → „12,34 €"). */
function euro(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

/**
 * Euro-Eingabe in Cents wandeln („12,34", „12.34", „1.234,56", „5"); null bei Unlesbarem.
 * Punkt UND Komma → Punkt ist Tausendertrenner; nur Punkt mit 3 Nachstellen → Tausender.
 */
function euro_parse(string $s): ?int
{
    $s = trim(str_replace(['€', ' ', "\u{00a0}"], '', $s));
    if ($s === '' || !preg_match('/^\d[\d.,]*$/', $s)) return null;
    if (str_contains($s, '.') && str_contains($s, ',')) {
        $s = str_replace('.', '', $s);            // 1.234,56
        $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, ',')) {
        $s = str_replace(',', '.', $s);           // 12,34
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
        $s = str_replace('.', '', $s);            // 1.234 → 1234 (Tausender)
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
    return (int)round((float)$s * 100);
}

/** Positionen eines Belegblatts aus dem JSON-Feld ([{d,s,b}] – b in Cent). */
function expense_items(array $claim): array
{
    $arr = json_decode((string)($claim['items'] ?? '[]'), true);
    return is_array($arr) ? $arr : [];
}

/** Belegblatt einreichen; $items = [['d'=>'YYYY-MM-DD','s'=>Beschreibung,'b'=>Cent], …]. Gibt die neue ID zurück. */
function expense_claim_add(int $memberId, array $data, array $items): int
{
    $total = 0;
    foreach ($items as $it) $total += (int)($it['b'] ?? 0);
    db()->prepare('INSERT INTO expense_claims(member_id,name,street,city,bank,bic,iban,source,source_note,purpose,items,total_cents,by_finance)
                   VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([
           $memberId, (string)$data['name'], (string)$data['street'], (string)$data['city'],
           (string)$data['bank'], (string)$data['bic'], (string)$data['iban'],
           (string)$data['source'], (string)$data['source_note'], (string)$data['purpose'],
           json_encode(array_values($items), JSON_UNESCAPED_UNICODE), $total,
           !empty($data['by_finance']) ? 1 : 0,
       ]);
    return (int)db()->lastInsertId();
}

function expense_claim_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM expense_claims WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Offene Belegblätter (älteste zuerst – so lange offen, bis die Überweisung raus ist). */
function expense_claims_open(): array
{
    return db()->query("SELECT * FROM expense_claims WHERE status = 'open' ORDER BY created_at, id")->fetchAll();
}

/** Zuletzt erledigte Belegblätter (fürs eingeklappte Archiv). */
function expense_claims_done(int $limit = 30): array
{
    $st = db()->prepare("SELECT * FROM expense_claims WHERE status = 'done' ORDER BY done_at DESC, id DESC LIMIT ?");
    $st->execute([$limit]);
    return $st->fetchAll();
}

/** Eigene Einreichungen eines Mitglieds (neueste zuerst). */
function expense_claims_of(int $memberId): array
{
    $st = db()->prepare('SELECT * FROM expense_claims WHERE member_id = ? ORDER BY created_at DESC, id DESC');
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** Jüngste Einreichung eines Mitglieds – fürs Vorbefüllen der Kontaktdaten. */
function expense_claim_last_of(int $memberId): ?array
{
    $st = db()->prepare('SELECT * FROM expense_claims WHERE member_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$memberId]);
    return $st->fetch() ?: null;
}

/** Erledigt-Status setzen (done = Überweisung raus) bzw. wieder öffnen. */
function expense_claim_set_done(int $id, bool $done, int $byId): void
{
    if ($done) {
        db()->prepare("UPDATE expense_claims SET status='done', done_at=datetime('now','localtime'), done_by=? WHERE id=?")
           ->execute([$byId, $id]);
    } else {
        db()->prepare("UPDATE expense_claims SET status='open', done_at=NULL, done_by=NULL WHERE id=?")->execute([$id]);
    }
}

/** Angeheftete Belege der EINREICHUNG selbst (Dateien an Nachrichten hängen am Kommentar). */
function expense_files_of(int $claimId): array
{
    $st = db()->prepare('SELECT * FROM expense_files WHERE claim_id = ? AND comment_id IS NULL ORDER BY id');
    $st->execute([$claimId]);
    return $st->fetchAll();
}

/** Dateien, die an einer Nachricht im Verlauf hängen. */
function expense_comment_files(int $commentId): array
{
    $st = db()->prepare('SELECT * FROM expense_files WHERE comment_id = ? ORDER BY id');
    $st->execute([$commentId]);
    return $st->fetchAll();
}

/** Nachricht in den Verlauf eines Belegblatts schreiben. Gibt die Kommentar-ID zurück. */
function expense_comment_add(int $claimId, int $memberId, bool $fromFinance, string $body): int
{
    db()->prepare('INSERT INTO expense_comments(claim_id, member_id, from_finance, body) VALUES(?,?,?,?)')
       ->execute([$claimId, $memberId, $fromFinance ? 1 : 0, $body]);
    return (int)db()->lastInsertId();
}

/** Nachrichten-Verlauf eines Belegblatts (älteste zuerst, mit Autor:innen-Namen). */
function expense_comments_of(int $claimId): array
{
    $st = db()->prepare('SELECT ec.*, m.name AS author_name FROM expense_comments ec
                         LEFT JOIN members m ON m.id = ec.member_id
                         WHERE ec.claim_id = ? ORDER BY ec.id');
    $st->execute([$claimId]);
    return $st->fetchAll();
}

/** Anzahl Nachrichten zu einem Belegblatt (für die „Nachrichten"-Buttons). */
function expense_comment_count(int $claimId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM expense_comments WHERE claim_id = ?');
    $st->execute([$claimId]);
    return (int)$st->fetchColumn();
}

/** Hochgeladenen Beleg (Foto/PDF) zu einem Belegblatt speichern; Fehlermeldung oder null. */
function expense_file_add(int $claimId, array $file, ?int $commentId = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null; // kein File gewählt
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload fehlgeschlagen.';
    if ((int)$file['size'] > 25 * 1024 * 1024) return 'Datei zu groß (max. 25 MB).';
    $orig = (string)$file['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'heic', 'heif', 'webp', 'gif'];
    if (!in_array($ext, $allowed, true)) return 'Dateityp „.' . $ext . '" ist nicht erlaubt (Foto oder PDF).';
    $dir = upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return 'Upload-Ordner konnte nicht angelegt werden.';
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return 'Datei konnte nicht gespeichert werden.';
    $mime = function_exists('mime_content_type') ? (mime_content_type($dir . '/' . $stored) ?: '') : '';
    db()->prepare('INSERT INTO expense_files(claim_id, comment_id, orig_name, stored_name, mime, size) VALUES(?,?,?,?,?,?)')
        ->execute([$claimId, $commentId, $orig, $stored, $mime, (int)$file['size']]);
    return null;
}

/**
 * Angeheftete Beleg-Dateien nach Ablauf löschen (Datei + Datensatz) – Doks sind
 * maximal 30 Tage gespeichert/herunterladbar. Gibt die Anzahl gelöschter zurück.
 */
function cleanup_expense_files(int $days = 30): int
{
    $cut = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $st = db()->prepare('SELECT id, stored_name FROM expense_files WHERE created_at < ?');
    $st->execute([$cut]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        @unlink(upload_dir() . '/' . basename((string)$r['stored_name'])); // basename schützt vor Pfad-Tricks
        db()->prepare('DELETE FROM expense_files WHERE id = ?')->execute([(int)$r['id']]);
        // NUR den Merker abräumen, NICHT die Datei in der Nextcloud: das hier ist die
        // Aufbewahrungsfrist der App-Kopien – das Archiv soll gerade erhalten bleiben.
        db()->prepare("DELETE FROM nc_files WHERE kind = 'expense_att' AND ref_id = ?")->execute([(int)$r['id']]);
    }
    return count($rows);
}

/** Dateiname der ausgefüllten Belegblatt-DOCX. */
function expense_claim_filename(array $c): string
{
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '-', (string)$c['name']);
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $date = ($t = strtotime((string)$c['created_at'])) ? date('Y-m-d', $t) : '';
    return trim('Belegblatt_' . $name . '_' . $date . '_Nr' . (int)$c['id'], '_') . '.docx';
}

/** Die <w:tr>-Zeile mit ((BD)) in der Vorlage je Beleg-Position klonen. */
function expense_clone_rows(string $doc, array $items): string
{
    $pos = strpos($doc, '((BD))');
    if ($pos === false) return $doc;
    $head = substr($doc, 0, $pos);
    $start = strrpos($head, '<w:tr ');
    $startPlain = strrpos($head, '<w:tr>');
    if ($startPlain !== false && ($start === false || $startPlain > $start)) $start = $startPlain;
    $end = strpos($doc, '</w:tr>', $pos);
    if ($start === false || $end === false) return $doc;
    $end += strlen('</w:tr>');
    $tpl = substr($doc, $start, $end - $start);
    $rows = '';
    foreach (array_values($items) as $k => $it) {
        $d = ($t = strtotime((string)($it['d'] ?? ''))) ? date('d.m.Y', $t) : (string)($it['d'] ?? '');
        $rows .= str_replace(
            ['((BN))', '((BD))', '((BS))', '((BB))'],
            [docx_escape((string)($k + 1)), docx_escape($d), docx_escape((string)($it['s'] ?? '')), docx_escape(euro((int)($it['b'] ?? 0)))],
            $tpl
        );
    }
    return substr($doc, 0, $start) . $rows . substr($doc, $end);
}

/** Eingereichtes Belegblatt als ausgefüllte DOCX (Vorlage bevorzugt, sonst schlichte Fassung). */
function expense_claim_docx(array $c): string
{
    $items = expense_items($c);
    $src = (string)($c['source'] ?? 'asta');
    $tpl = docx_template_path('belegblatt');
    if ($tpl && class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'beleg');
        if ($tmp !== false && @copy($tpl, $tmp)) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true && ($doc = $zip->getFromName('word/document.xml')) !== false) {
                $doc = docx_heal_placeholders((string)$doc, ['NAME', 'STRASSE', 'PLZORT', 'BANK', 'BIC', 'IBAN', 'VA', 'VS', 'VP', 'PROJEKT', 'ANLASS', 'BN', 'BD', 'BS', 'BB', 'SUMME', 'DATUM']);
                $doc = expense_clone_rows($doc, $items);
                $rep = [
                    '((NAME))' => $c['name'], '((STRASSE))' => $c['street'], '((PLZORT))' => $c['city'],
                    '((BANK))' => $c['bank'], '((BIC))' => $c['bic'], '((IBAN))' => $c['iban'],
                    '((VA))' => $src === 'asta' ? '☒' : '☐',
                    '((VS))' => $src === 'stupa' ? '☒' : '☐',
                    '((VP))' => $src === 'projekt' ? '☒' : '☐',
                    '((PROJEKT))' => $src === 'projekt' ? (string)$c['source_note'] : '',
                    '((ANLASS))' => $c['purpose'],
                    '((SUMME))' => euro((int)$c['total_cents']),
                    '((DATUM))' => ($t = strtotime((string)$c['created_at'])) ? date('d.m.Y', $t) : '',
                ];
                foreach ($rep as $k => $v) $doc = str_replace($k, docx_escape((string)$v), $doc);
                $zip->deleteName('word/document.xml');
                $zip->addFromString('word/document.xml', $doc);
                $zip->close();
                $bin = (string)file_get_contents($tmp);
                @unlink($tmp);
                if ($bin !== '') return $bin;
            } else {
                $zip->close();
                @unlink($tmp);
            }
        } elseif ($tmp !== false) {
            @unlink($tmp);
        }
    }
    // Fallback ohne Vorlage: schlichte, vollständige Fassung
    $lbl = ['asta' => 'AStA', 'stupa' => 'StuPa', 'projekt' => 'Projekt/Sonstiges'][$src] ?? $src;
    $body = docx_paragraph('Belegblatt für Auslagen', true, 16)
        . docx_paragraph('')
        . docx_paragraph('Name, Vorname: ' . $c['name'])
        . docx_paragraph('Straße und Hausnummer: ' . $c['street'])
        . docx_paragraph('PLZ und Ort: ' . $c['city'])
        . docx_paragraph('Bankinstitut: ' . $c['bank'])
        . docx_paragraph('BIC: ' . $c['bic'])
        . docx_paragraph('IBAN: ' . $c['iban'])
        . docx_paragraph('Beantragt von: ' . $lbl . ($src === 'projekt' && $c['source_note'] !== '' ? ' – ' . $c['source_note'] : ''))
        . docx_paragraph('Für (Veranstaltung/Projekt/…): ' . $c['purpose'])
        . docx_paragraph('');
    foreach (array_values($items) as $k => $it) {
        $d = ($t = strtotime((string)($it['d'] ?? ''))) ? date('d.m.Y', $t) : (string)($it['d'] ?? '');
        $body .= docx_paragraph(($k + 1) . '. ' . $d . ' – ' . (string)($it['s'] ?? '') . ' – ' . euro((int)($it['b'] ?? 0)));
    }
    $body .= docx_paragraph('')
        . docx_paragraph('Summe: ' . euro((int)$c['total_cents']), true)
        . docx_paragraph('')
        // Ortszeile der Auszahlungsaufforderung: Der Ort steht in den Einstellungen; ohne
        // Eintrag bleibt nur das Datum stehen.
        . docx_paragraph(trim(org_ort() . ', den ' . (($t = strtotime((string)$c['created_at'])) ? date('d.m.Y', $t) : ''), ' ,'))
        . docx_paragraph('Unterschrift Antragssteller*in: ______________________________');
    return docx_package($body);
}

// ---------------------------------------------------------------------------
// Belegblätter in die Nextcloud archivieren
//
// Ablage: <Belegblatt-Ordner>/<JAHR>/VORNAME-DATUM.pdf, Anhänge daneben als
// VORNAME-DATUM-BelegN.<ext>. Der Ordner steht BEWUSST getrennt vom geteilten
// AStA-App-Ordner: auf einem Belegblatt stehen Anschrift und IBAN, das geht nur
// die Finanzen an. Freigabe in der Nextcloud bitte entsprechend eng halten.
//
// PDF: Die App erzeugt DOCX. Gewandelt wird über Microsoft Graph (dieselbe
// Technik wie bei der Protokoll-Veröffentlichung): DOCX in die Teams-Bibliothek,
// als PDF zurückholen, Zwischendatei löschen. Ohne Teams-Anbindung keine PDF.
//
// Ersetzen statt Sammeln: Die abgelegten Pfade merkt sich nc_files (kind
// 'expense' bzw. 'expense_att'). Bei jeder Änderung wird die alte Datei
// überschrieben – und falls sich der Name geändert hat, die alte gelöscht.
// Wird ein Belegblatt in der App gelöscht, bleibt die Nextcloud-Datei liegen.
// ---------------------------------------------------------------------------

/** Zwischenordner in der Teams-Bibliothek für die PDF-Wandlung (wird sofort wieder geleert). */
const EXPENSE_PDF_TMP_FOLDER = 'AStA-App/_PDF-Wandlung';

/** Soll überhaupt archiviert werden? (Schalter UND eingerichtete Nextcloud) */
function expense_nc_enabled(): bool
{
    return setting_get('nc_expense_enabled', '0') === '1' && nc_configured();
}

/** Basisordner der Belegblatt-Ablage, relativ zur WebDAV-Wurzel des App-Kontos. */
function expense_nc_base(): string
{
    $f = trim(str_replace('\\', '/', (string)setting_get('nc_expense_folder', 'Referate/Finanzen/Belegblätter')), '/');
    return $f;
}

/** Zielordner eines Belegblatts: Basisordner + Jahr aus dem Einreichdatum. */
function expense_nc_folder(array $c): string
{
    $t = strtotime((string)$c['created_at']);
    return trim(expense_nc_base() . '/' . ($t ? date('Y', $t) : date('Y')), '/');
}

/**
 * Vorname aus dem Namensfeld des Belegblatts. Dort steht „Nachname, Vorname"
 * (so das Formular), manche schreiben aber „Vorname Nachname" – beides abfangen.
 */
function expense_first_name(array $c): string
{
    $name = trim((string)($c['name'] ?? ''));
    if ($name === '') return 'Unbekannt';
    $first = str_contains($name, ',')
        ? trim((string)substr($name, strpos($name, ',') + 1))   // „Nachname, Vorname"
        : trim((string)strtok($name, ' '));                      // „Vorname Nachname"
    if ($first === '') $first = $name;
    // Für Dateinamen entschärfen: keine Pfadzeichen, keine Leerzeichen-Kaskaden
    $first = (string)preg_replace('/[\\\\\/:*?"<>|]+/', '-', $first);
    $first = trim((string)preg_replace('/\s+/', ' ', $first));
    return $first !== '' ? $first : 'Unbekannt';
}

/**
 * Dateiname ohne Endung: VORNAME-DATUM. Reichen zwei Personen mit gleichem
 * Vornamen am selben Tag ein, bekommt die zweite „-Nr<ID>" angehängt – sonst
 * überschriebe die eine die Datei der anderen.
 */
function expense_nc_basename(array $c): string
{
    $t = strtotime((string)$c['created_at']);
    $base = expense_first_name($c) . '-' . ($t ? date('Y-m-d', $t) : date('Y-m-d'));
    $folder = expense_nc_folder($c);
    $st = db()->prepare("SELECT 1 FROM nc_files WHERE kind = 'expense' AND ref_id != ? AND path = ?");
    $st->execute([(int)$c['id'], $folder . '/' . $base . '.pdf']);
    return $st->fetchColumn() ? $base . '-Nr' . (int)$c['id'] : $base;
}

/**
 * Belegblatt als PDF-Bytes. Wandelt das erzeugte DOCX über Graph (Upload in einen
 * Zwischenordner der Teams-Bibliothek, als PDF zurückholen, Zwischendatei löschen).
 * Ohne Teams-Anbindung gibt es keine PDF – dann kommt null mit klarer Meldung.
 */
function expense_pdf_bytes(array $c, ?string &$err = null): ?string
{
    if (!graph_configured()) { $err = 'Für die PDF-Wandlung wird die Teams-Anbindung gebraucht (Verwaltung → Uploads und Automationen).'; return null; }
    $driveId = (string)setting_get('graph_drive_id', '');
    if ($driveId === '') { $err = 'Keine Dokumentbibliothek gewählt – ohne sie kann Graph nicht wandeln.'; return null; }
    if (!class_exists('ZipArchive')) { $err = 'Word-Export nicht möglich (ZipArchive fehlt auf dem Server).'; return null; }

    $docx = expense_claim_docx($c);
    $tmpName = 'Beleg_' . (int)$c['id'] . '_' . bin2hex(random_bytes(4)) . '.docx';
    $item = graph_upload_bytes($driveId, EXPENSE_PDF_TMP_FOLDER, $tmpName, $docx, $err);
    if (!$item || empty($item['id'])) { if (!$err) $err = 'Zwischendatei konnte nicht abgelegt werden.'; return null; }

    $pdf = graph_download_file((string)($item['parentReference']['driveId'] ?? $driveId), (string)$item['id'], 'pdf', $err);
    // Zwischendatei in JEDEM Fall wieder entfernen – sie enthält Anschrift und IBAN
    $delErr = null;
    graph_delete_item((string)($item['parentReference']['driveId'] ?? $driveId), (string)$item['id'], $delErr);
    if ($pdf === null) return null;
    if (strlen($pdf) < 100) { $err = 'Die PDF-Wandlung lieferte keine gültige Datei.'; return null; }
    return $pdf;
}

/** Gemerkter Ablage-Pfad ('' wenn noch nichts abgelegt wurde). */
function expense_nc_path(string $kind, int $refId): string
{
    $st = db()->prepare('SELECT path FROM nc_files WHERE kind = ? AND ref_id = ?');
    $st->execute([$kind, $refId]);
    return (string)($st->fetchColumn() ?: '');
}

/** Ablage-Pfad merken (überschreibt den alten Eintrag derselben Datei). */
function expense_nc_remember(string $kind, int $refId, string $path, string $name): void
{
    db()->prepare('INSERT INTO nc_files(kind, ref_id, path, file_id, name) VALUES(?,?,?,?,?)
                   ON CONFLICT(kind, ref_id) DO UPDATE SET path = excluded.path, name = excluded.name')
        ->execute([$kind, $refId, $path, '', $name]);
}

/**
 * Belegblatt (und Anhänge) in der Nextcloud ablegen bzw. ersetzen.
 * Wird beim Einreichen und bei jeder Änderung aufgerufen. Rückgabe: true = abgelegt,
 * false = nichts passiert oder Fehler (dann steht er in $err).
 */
function expense_nc_sync(int $claimId, ?string &$err = null): bool
{
    if (!expense_nc_enabled()) return false;   // ausgeschaltet ist kein Fehler
    $c = expense_claim_get($claimId);
    if (!$c) { $err = 'Belegblatt nicht gefunden.'; return false; }

    $pdf = expense_pdf_bytes($c, $err);
    if ($pdf === null) return false;

    $folder = expense_nc_folder($c);
    $base   = expense_nc_basename($c);
    $target = $folder . '/' . $base . '.pdf';

    if (!dav_put('nextcloud', $target, $pdf, $err)) return false;
    // Hieß die Datei vorher anders (Vorname korrigiert), die alte entfernen –
    // sonst lägen zwei Fassungen desselben Vorgangs im Ordner.
    $old = expense_nc_path('expense', $claimId);
    if ($old !== '' && $old !== $target) { $e = null; dav_delete('nextcloud', $old, $e); }
    expense_nc_remember('expense', $claimId, $target, $base . '.pdf');

    // Anhänge daneben legen: VORNAME-DATUM-Beleg1.<ext>, -Beleg2.<ext>, …
    $n = 0;
    foreach (expense_files_of($claimId) as $f) {
        $n++;
        $src = upload_dir() . '/' . basename((string)$f['stored_name']);
        if (!is_file($src)) continue;
        $ext = strtolower((string)pathinfo((string)$f['orig_name'], PATHINFO_EXTENSION));
        $attName = $base . '-Beleg' . $n . ($ext !== '' ? '.' . $ext : '');
        $attPath = $folder . '/' . $attName;
        $bytes = file_get_contents($src);
        if ($bytes === false) continue;
        $attErr = null;
        if (!dav_put('nextcloud', $attPath, $bytes, $attErr)) { $err = $attErr; continue; }
        $oldAtt = expense_nc_path('expense_att', (int)$f['id']);
        if ($oldAtt !== '' && $oldAtt !== $attPath) { $e = null; dav_delete('nextcloud', $oldAtt, $e); }
        expense_nc_remember('expense_att', (int)$f['id'], $attPath, $attName);
    }
    return true;
}

// ---------------------------------------------------------------------------
// Auszahlungsaufforderungen des StuPa (getrennt von den AStA-Belegblättern)
//
// Das StuPa-Präsidium hat ein eigenes Login mit eigener Tabelle (stupa_users) und einen
// eigenen Bereich unter stupa/ – kein Mitgliedskonto, keine App-Navigation. Der Riegel sitzt
// in stupa_require_login().
// Finanzen sieht die Aufforderungen in der App, aber in einem eigenen Bereich:
// es sind keine Auslagen von AStA-Mitgliedern, sondern Anweisungen des Parlaments.
// ---------------------------------------------------------------------------

/**
 * Verwendungszweck einer Protokollgeld-Anfrage: „Protokoll 7. ordentliche Sitzung".
 * Eine Funktion, damit der Text bei jeder Anfrage identisch ist – die Vorschau im
 * Formular bildet dieselbe Regel nach, gespeichert wird aber immer diese hier.
 */
function stupa_protokoll_purpose(int $nr, string $art): string
{
    if ($nr < 1) return '';
    $art = $art === 'außerordentliche' || $art === 'ao' ? 'außerordentliche' : 'ordentliche';
    return 'Protokoll ' . $nr . '. ' . $art . ' Sitzung';
}

// ---- Zugänge des StuPa-Präsidiums (eigene Konten, kein Eintrag in members) ----

const STUPA_TOKEN_MINUTES = 60;   // Gültigkeit eines Anmelde-Links

function stupa_users_all(): array
{
    return db()->query('SELECT * FROM stupa_users ORDER BY name COLLATE NOCASE')->fetchAll();
}

function stupa_user_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM stupa_users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Zugang anlegen. Rückgabe: ID oder 0 (unvollständig bzw. Adresse schon vergeben). */
function stupa_user_add(string $name, string $email): int
{
    $name = trim($name);
    $email = trim($email);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
    try {
        db()->prepare('INSERT INTO stupa_users(name, email) VALUES(?,?)')->execute([$name, $email]);
    } catch (\Throwable $e) { return 0; }   // UNIQUE auf der Adresse
    return (int)db()->lastInsertId();
}

function stupa_user_update(int $id, string $name, string $email, bool $active): bool
{
    $name = trim($name);
    $email = trim($email);
    if ($id <= 0 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    try {
        db()->prepare('UPDATE stupa_users SET name = ?, email = ?, active = ? WHERE id = ?')
           ->execute([$name, $email, $active ? 1 : 0, $id]);
    } catch (\Throwable $e) { return false; }
    return true;
}

/** Zugang löschen. Übermittelte Aufforderungen bleiben (Finanz-Historie). */
function stupa_user_delete(int $id): void
{
    db()->prepare('DELETE FROM stupa_users WHERE id = ?')->execute([$id]);
}

/**
 * Anmelde-Link erzeugen und verschicken. Gibt true zurück, wenn die Mail raus ist.
 * Der Klartext-Token verlässt die Funktion nie – gespeichert wird nur sein Hash.
 */
function stupa_login_link_send(int $userId, ?string &$err = null): bool
{
    $u = stupa_user_get($userId);
    if (!$u || !(int)$u['active']) { $err = 'Zugang nicht gefunden oder gesperrt.'; return false; }
    $token = bin2hex(random_bytes(24));
    db()->prepare("INSERT INTO stupa_tokens(user_id, token_hash, expires_at)
                   VALUES(?,?, datetime('now','localtime','+" . STUPA_TOKEN_MINUTES . " minutes'))")
       ->execute([$userId, hash('sha256', $token)]);
    $link = app_url('stupa/login.php?t=' . $token);
    $ok = send_mail((string)$u['email'], 'Anmeldung Auszahlungsaufforderungen (AStA-App)',
        "Hallo,\n\nmit diesem Link könnt ihr Auszahlungsaufforderungen an die Finanzreferentin übermitteln:\n\n"
        . $link . "\n\nDer Link gilt " . STUPA_TOKEN_MINUTES . " Minuten und lässt sich nur einmal verwenden.\n"
        . "Wenn ihr das nicht angefordert habt, ignoriert diese Mail einfach.\n");
    if (!$ok) { $err = 'Die Mail konnte nicht verschickt werden.'; return false; }
    return true;
}

/**
 * Anmelde-Token einlösen. Rückgabe: Konto-Zeile oder null. Der Token wird dabei
 * entwertet (einmalig), abgelaufene und schon benutzte werden abgewiesen.
 */
function stupa_token_consume(string $token): ?array
{
    $token = trim($token);
    if ($token === '') return null;
    $st = db()->prepare("SELECT * FROM stupa_tokens WHERE token_hash = ? AND used_at IS NULL
                         AND expires_at >= datetime('now','localtime')");
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row) return null;
    db()->prepare("UPDATE stupa_tokens SET used_at = datetime('now','localtime') WHERE id = ?")->execute([(int)$row['id']]);
    $u = stupa_user_get((int)$row['user_id']);
    if (!$u || !(int)$u['active']) return null;
    db()->prepare("UPDATE stupa_users SET last_login_at = datetime('now','localtime') WHERE id = ?")->execute([(int)$u['id']]);
    return $u;
}

/** Alte Token aufräumen (abgelaufen oder benutzt) – wird beim Anmelden nebenbei erledigt. */
function stupa_tokens_cleanup(): void
{
    db()->exec("DELETE FROM stupa_tokens WHERE used_at IS NOT NULL OR expires_at < datetime('now','localtime','-1 day')");
}

// ---- Belege und Rückfragen zu Auszahlungsaufforderungen ----

/** Beleg an eine Aufforderung hängen. Rückgabe: null = ok, sonst Fehlertext. */
function stupa_file_add(int $claimId, array $file, ?int $commentId = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null; // nichts gewählt
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload fehlgeschlagen.';
    if ((int)$file['size'] > 25 * 1024 * 1024) return 'Datei zu groß (max. 25 MB).';
    $orig = (string)$file['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'heic', 'heif', 'webp', 'gif'];
    if (!in_array($ext, $allowed, true)) return 'Dateityp „.' . $ext . '" ist nicht erlaubt (Foto oder PDF).';
    $dir = upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return 'Upload-Ordner konnte nicht angelegt werden.';
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return 'Datei konnte nicht gespeichert werden.';
    $mime = function_exists('mime_content_type') ? (mime_content_type($dir . '/' . $stored) ?: '') : '';
    db()->prepare('INSERT INTO stupa_files(claim_id, comment_id, orig_name, stored_name, mime, size) VALUES(?,?,?,?,?,?)')
        ->execute([$claimId, $commentId, $orig, $stored, $mime, (int)$file['size']]);
    return null;
}

/** Belege einer Aufforderung (nur die aus der Einreichung, nicht die an Nachrichten). */
function stupa_files_of(int $claimId): array
{
    $st = db()->prepare('SELECT * FROM stupa_files WHERE claim_id = ? AND (comment_id IS NULL OR comment_id = 0) ORDER BY id');
    $st->execute([$claimId]);
    return $st->fetchAll();
}

/** Belege, die an einer bestimmten Nachricht hängen. */
function stupa_comment_files(int $commentId): array
{
    $st = db()->prepare('SELECT * FROM stupa_files WHERE comment_id = ? ORDER BY id');
    $st->execute([$commentId]);
    return $st->fetchAll();
}

function stupa_file_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM stupa_files WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Nachricht anlegen. $fromStupa entscheidet, worauf $authorId zeigt. Rückgabe: neue ID. */
function stupa_comment_add(int $claimId, bool $fromStupa, int $authorId, string $body): int
{
    $body = trim($body);
    if ($claimId <= 0) return 0;
    db()->prepare('INSERT INTO stupa_comments(claim_id, from_stupa, author_id, body) VALUES(?,?,?,?)')
       ->execute([$claimId, $fromStupa ? 1 : 0, $authorId, $body]);
    return (int)db()->lastInsertId();
}

function stupa_comments_of(int $claimId): array
{
    $st = db()->prepare('SELECT * FROM stupa_comments WHERE claim_id = ? ORDER BY id');
    $st->execute([$claimId]);
    return $st->fetchAll();
}

function stupa_comment_count(int $claimId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM stupa_comments WHERE claim_id = ?');
    $st->execute([$claimId]);
    return (int)$st->fetchColumn();
}

/** Anzeigename einer Nachricht – NIE ohne from_stupa nachschlagen (zwei Nummernkreise!). */
function stupa_comment_author(array $c): string
{
    if (!empty($c['from_stupa'])) {
        $u = stupa_user_get((int)$c['author_id']);
        return $u ? (string)$u['name'] : 'StuPa-Präsidium';
    }
    $m = (int)$c['author_id'] > 0 ? member_get((int)$c['author_id']) : null;
    return $m ? short_name((string)$m['name']) . ' (Finanzen)' : 'Finanzen';
}

/** Benachrichtigungs-Wunsch setzen (Häkchen beim Einreichen, Standard aus). */
function stupa_claim_set_notify(int $claimId, bool $wants): void
{
    db()->prepare('UPDATE stupa_claims SET notify_done = ? WHERE id = ?')->execute([$wants ? 1 : 0, $claimId]);
}

/** Finanzen über eine NEUE Aufforderung informieren (Dashboard-Mitteilung, wie bei Belegblättern). */
function stupa_notify_finance(int $claimId): void
{
    $c = stupa_claim_get($claimId);
    if (!$c) return;
    try {
        foreach (db()->query("SELECT id FROM members WHERE active = 1 AND role = 'finanzen'")->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            dm_send((int)$fid, 'StuPa-Präsidium',
                stupa_claim_notice($c),
                null, false, false, 'dm_finanzen', 'finanzen.php#stupa');
        }
    } catch (\Throwable $e) { /* eine fehlende Mitteilung darf die Einreichung nie kippen */ }
}

/** Finanzen über eine RÜCKFRAGE des Präsidiums informieren. */
function stupa_notify_finance_comment(array $claim, string $excerpt): void
{
    $excerpt = mb_strlen($excerpt) > 140 ? mb_substr($excerpt, 0, 140) . '…' : $excerpt;
    try {
        foreach (db()->query("SELECT id FROM members WHERE active = 1 AND role = 'finanzen'")->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            dm_send((int)$fid, 'StuPa-Präsidium',
                'Nachricht zur Auszahlung „' . stupa_claim_label((int)$claim['id']) . '": ' . $excerpt,
                null, false, false, 'dm_finanzen', 'finanzen.php#stupa');
        }
    } catch (\Throwable $e) { /* siehe oben */ }
}

/** Mail ans Präsidium – der einzige Weg dorthin: es hat kein Dashboard und kein Push. */
function stupa_notify_presidium(array $claim, string $subject, string $body): bool
{
    $u = stupa_user_get((int)$claim['created_by']);
    if (!$u || trim((string)$u['email']) === '') return false;
    return send_mail((string)$u['email'], $subject,
        $body . "\n\nZur Übersicht: " . app_url('stupa/') . "\n");
}

/** Zuletzt benutzter Topf fürs Protokollgeld – Startwert 3115, danach das, was zuletzt gewählt wurde. */
function stupa_protokoll_fund(): string
{
    return trim((string)setting_get('stupa_protokoll_fund', '3115'));
}

/** Merkt sich einen abweichenden Topf als neuen Standard (leere Eingabe ändert nichts). */
function stupa_protokoll_fund_set(string $fund): void
{
    $fund = trim($fund);
    if ($fund !== '' && $fund !== stupa_protokoll_fund()) setting_set('stupa_protokoll_fund', $fund);
}

/** Die Posten eines Blattes in Reihenfolge. */
function stupa_positions_of(int $claimId): array
{
    $st = db()->prepare('SELECT * FROM stupa_positions WHERE claim_id = ? ORDER BY sort, id');
    $st->execute([$claimId]);
    return $st->fetchAll();
}

/** Ist ein Posten vollständig? (Alle Pflichtfelder + Betrag > 0.) */
function stupa_position_valid(array $p): bool
{
    foreach (['applicant', 'purpose', 'account_holder', 'iban'] as $pflicht) {
        if (trim((string)($p[$pflicht] ?? '')) === '') return false;
    }
    return (int)($p['amount_cents'] ?? 0) > 0;
}

/** Gesamtsumme eines Blattes (ID oder bereits geladene Posten). */
function stupa_claim_total(int|array $claim): int
{
    $rows = is_array($claim) ? $claim : stupa_positions_of($claim);
    $sum = 0;
    foreach ($rows as $p) $sum += (int)$p['amount_cents'];
    return $sum;
}

/**
 * Kurzbezeichnung eines Blattes für Listen, Betreffzeilen und Mitteilungen:
 * der Verwendungszweck des ersten Postens, bei mehreren mit Zähler.
 */
function stupa_claim_label(int|array $claim): string
{
    $rows = is_array($claim) ? $claim : stupa_positions_of($claim);
    if (!$rows) return 'Auszahlungsaufforderung';
    $erste = trim((string)$rows[0]['purpose']);
    $n = count($rows);
    return $n > 1 ? $erste . ' (+' . ($n - 1) . ' weitere)' : $erste;
}

/**
 * Eine Auszahlungsaufforderung mit 1..n Posten anlegen. Rückgabe: neue ID (0 = nichts Gültiges dabei).
 * Unvollständige Posten werden übersprungen – die Seite prüft vorher und meldet sie einzeln.
 */
function stupa_claim_add(int $byId, array $positions): int
{
    $gueltig = array_values(array_filter($positions, 'stupa_position_valid'));
    if (!$gueltig) return 0;
    db()->prepare('INSERT INTO stupa_claims(created_by, applicant, purpose, account_holder, iban)
                   VALUES(?, "", "", "", "")')->execute([$byId]);
    $id = (int)db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO stupa_positions(claim_id, applicant, institution, purpose,
                                                      amount_cents, account_holder, fund, iban, sort)
                          VALUES(?,?,?,?,?,?,?,?,?)');
    foreach ($gueltig as $i => $p) {
        $ins->execute([$id, trim((string)$p['applicant']), trim((string)($p['institution'] ?? '')),
                       trim((string)$p['purpose']), (int)$p['amount_cents'], trim((string)$p['account_holder']),
                       trim((string)($p['fund'] ?? '')), trim((string)$p['iban']), $i]);
    }
    return $id;
}

function stupa_claim_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM stupa_claims WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Offene Aufforderungen (älteste zuerst – so lange offen, bis die Überweisung raus ist). */
function stupa_claims_open(): array
{
    return db()->query("SELECT * FROM stupa_claims WHERE status = 'open' ORDER BY created_at, id")->fetchAll();
}

/** Zuletzt erledigte Aufforderungen (fürs eingeklappte Archiv). */
function stupa_claims_done(int $limit = 30): array
{
    $st = db()->prepare("SELECT * FROM stupa_claims WHERE status = 'done' ORDER BY done_at DESC, id DESC LIMIT ?");
    $st->execute([$limit]);
    return $st->fetchAll();
}

/** Alle Aufforderungen (Ansicht des StuPa – es sieht seine eigenen, inkl. Stand). */
function stupa_claims_all(int $limit = 100): array
{
    $st = db()->prepare('SELECT * FROM stupa_claims ORDER BY created_at DESC, id DESC LIMIT ?');
    $st->execute([$limit]);
    return $st->fetchAll();
}

/** Erledigt-Haken setzen bzw. zurücknehmen (Finanzen). */
function stupa_claim_set_done(int $id, bool $done, int $byId): void
{
    db()->prepare($done
        ? "UPDATE stupa_claims SET status = 'done', done_at = datetime('now','localtime'), done_by = ? WHERE id = ?"
        : "UPDATE stupa_claims SET status = 'open', done_at = NULL, done_by = ? WHERE id = ?")
       ->execute([$byId ?: null, $id]);
}

/** Anzahl offener Aufforderungen – für die Finanzen-Kachel. */
function stupa_claims_open_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM stupa_claims WHERE status = 'open'")->fetchColumn();
}

/**
 * Die <w:tr>-Zeile, die $anchor enthält, je Datensatz klonen (wie beim Belegblatt).
 * $rows ist eine Liste von [Platzhalter => Wert]; leere Liste lässt die Vorlagenzeile leer stehen.
 */
function docx_clone_row(string $doc, string $anchor, array $rows): string
{
    $pos = strpos($doc, $anchor);
    if ($pos === false) return $doc;
    $head = substr($doc, 0, $pos);
    $start = strrpos($head, '<w:tr ');
    $startPlain = strrpos($head, '<w:tr>');
    if ($startPlain !== false && ($start === false || $startPlain > $start)) $start = $startPlain;
    $end = strpos($doc, '</w:tr>', $pos);
    if ($start === false || $end === false) return $doc;
    $end += strlen('</w:tr>');
    $tpl = substr($doc, $start, $end - $start);
    $out = '';
    foreach ($rows as $r) {
        $z = $tpl;
        foreach ($r as $k => $v) $z = str_replace($k, docx_text((string)$v), $z);
        $out .= $z;
    }
    if ($out === '') { // ohne Daten: Platzhalter leeren, Zeile aber stehen lassen
        $out = $tpl;
        foreach (['((AA))', '((AZ))', '((AB))', '((AK))', '((AT))', '((AKI))', '((AIB))'] as $k) {
            $out = str_replace($k, '', $out);
        }
    }
    return substr($doc, 0, $start) . $out . substr($doc, $end);
}

/**
 * Kopfangaben des Formulars, die sich jede Legislatur ändern – deshalb Einstellung
 * statt fest in der Word-Vorlage: Nummer des Parlaments und die Namen des Präsidiums.
 */
function stupa_legislatur(): string
{
    return trim((string)setting_get('stupa_legislatur', '38'));
}

function stupa_legislatur_set(string $nr): void
{
    $nr = trim($nr);
    // Nur die nackte Zahl merken – „38." mit Punkt käme im Formular doppelt gepunktet raus
    $nr = trim(preg_replace('/[^0-9]/', '', $nr) ?? '');
    if ($nr !== '') setting_set('stupa_legislatur', $nr);
}

/** Namen des Präsidiums, ein Name je Zeile (im Formular werden daraus Zeilenumbrüche). */
function stupa_praesidium(): string
{
    return trim((string)setting_get('stupa_praesidium', "Hannah Hausmann und\nNoemi Michel"));
}

function stupa_praesidium_set(string $namen): void
{
    // Leerzeilen raus, damit im Formular kein Loch entsteht
    $zeilen = array_values(array_filter(array_map('trim', preg_split('/\R/', $namen) ?: []), fn($z) => $z !== ''));
    setting_set('stupa_praesidium', implode("\n", array_slice($zeilen, 0, 6)));
}

/** Meldungstext für Finanzen: ein Posten wird benannt, mehrere werden gezählt. */
function stupa_claim_notice(array $c): string
{
    $pos = stupa_positions_of((int)$c['id']);
    $summe = euro(stupa_claim_total($pos));
    if (count($pos) === 1) {
        return 'Neue Auszahlungsaufforderung: „' . $pos[0]['purpose'] . '" über ' . $summe
            . ' für ' . $pos[0]['applicant'] . '.';
    }
    return 'Neue Auszahlungsaufforderung mit ' . count($pos) . ' Posten über zusammen ' . $summe . '.';
}

/** Dateiname der ausgefüllten Auszahlungsaufforderung. */
function stupa_auszahlung_filename(array $c): string
{
    $date = ($t = strtotime((string)$c['created_at'])) ? date('Y-m-d', $t) : '';
    return trim('Auszahlungsaufforderung_' . $date . '_Nr' . (int)$c['id'], '_') . '.docx';
}

/**
 * Ein Blatt als ausgefüllte DOCX. Seite 1 trägt die Posten ohne Kontodaten,
 * Seite 2 den Anhang mit Kontoinhaber:in und IBAN – dieselbe Trennung wie im Original.
 */
function stupa_auszahlung_docx(array $c): string
{
    $pos = stupa_positions_of((int)$c['id']);
    $tpl = docx_template_path('auszahlung');
    if ($tpl && class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'ausz');
        if ($tmp !== false && @copy($tpl, $tmp)) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true && ($doc = $zip->getFromName('word/document.xml')) !== false) {
                // Die Legislatur-Zeile sitzt in der KOPFZEILE – eigener Dateiteil, eigener Durchlauf
                if (($hdr = $zip->getFromName('word/header1.xml')) !== false) {
                    $hdr = docx_heal_placeholders((string)$hdr, ['LEG']);
                    $hdr = str_replace('((LEG))', docx_escape(stupa_legislatur()), $hdr);
                    $zip->deleteName('word/header1.xml');
                    $zip->addFromString('word/header1.xml', $hdr);
                }
                $doc = docx_heal_placeholders((string)$doc, ['AA', 'AZ', 'AB', 'AK', 'AT', 'AKI', 'AIB', 'PRAESIDIUM']);
                $doc = str_replace('((PRAESIDIUM))', docx_text(stupa_praesidium()), $doc);
                $blatt = $anhang = [];
                foreach ($pos as $p) {
                    $wer = trim((string)$p['applicant']);
                    if (trim((string)($p['institution'] ?? '')) !== '') $wer .= "\n" . trim((string)$p['institution']);
                    $blatt[] = ['((AA))' => $wer, '((AZ))' => (string)$p['purpose'],
                                '((AB))' => euro((int)$p['amount_cents']),
                                '((AK))' => (string)$p['account_holder'], '((AT))' => (string)($p['fund'] ?? '')];
                    $anhang[] = ['((AKI))' => (string)$p['account_holder'], '((AIB))' => (string)$p['iban']];
                }
                $doc = docx_clone_row($doc, '((AZ))', $blatt);
                $doc = docx_clone_row($doc, '((AIB))', $anhang);
                $zip->deleteName('word/document.xml');
                $zip->addFromString('word/document.xml', $doc);
                $zip->close();
                $bin = (string)file_get_contents($tmp);
                @unlink($tmp);
                if ($bin !== '') return $bin;
            } else {
                $zip->close();
                @unlink($tmp);
            }
        }
    }
    // Ohne Vorlage: schlichte Fassung, damit der Download nie ins Leere läuft
    $body = docx_paragraph('Auszahlungsaufforderung', true, 16);
    foreach ($pos as $i => $p) {
        $body .= docx_paragraph(($i + 1) . '. ' . $p['purpose'] . ' – ' . euro((int)$p['amount_cents']), true);
        $body .= docx_paragraph('Antragsteller:in: ' . $p['applicant']
            . (trim((string)($p['institution'] ?? '')) !== '' ? ' (' . $p['institution'] . ')' : ''));
        $body .= docx_paragraph('Kontoinhaber:in: ' . $p['account_holder'] . ' · IBAN: ' . $p['iban']
            . (trim((string)($p['fund'] ?? '')) !== '' ? ' · Topf: ' . $p['fund'] : ''));
    }
    $body .= docx_paragraph('Summe: ' . euro(stupa_claim_total($pos)), true);
    return docx_package($body);
}

/** Herkunfts-Kennzeichen ('' = selbst eingereicht, sonst fertiges HTML). */
function expense_origin_badge(array $c): string
{
    if (empty($c['by_finance'])) return '';
    return '<span class="pill pill-info" title="Finanzen hat diesen Beleg stellvertretend erfasst'
        . ' – er wurde nicht über die App eingereicht"><i class="ti ti-file-pencil"></i> von Finanzen erfasst</span>';
}

/**
 * Darf diese Person das Belegblatt ändern oder löschen?
 * Einreicher:in solange es offen ist, Finanzen/Vorsitz immer – nach der Überweisung
 * soll niemand mehr still am eigenen Beleg drehen.
 */
function expense_can_edit(?array $c, ?array $me): bool
{
    if (!$c || !$me) return false;
    if (can_finance()) return true;
    return (int)$c['member_id'] === (int)$me['id'] && (string)$c['status'] !== 'done';
}

/** Belegblatt ändern. Danach expense_nc_sync() aufrufen – die Ablage wird ersetzt, nicht ergänzt. */
function expense_claim_update(int $id, array $data, array $items): bool
{
    if ($id <= 0) return false;
    $total = 0;
    foreach ($items as $it) $total += (int)($it['b'] ?? 0);
    $st = db()->prepare('UPDATE expense_claims SET name=?, street=?, city=?, bank=?, bic=?, iban=?,
                             source=?, source_note=?, purpose=?, items=?, total_cents=?, by_finance=? WHERE id=?');
    $st->execute([
        (string)$data['name'], (string)$data['street'], (string)$data['city'],
        (string)$data['bank'], (string)$data['bic'], (string)$data['iban'],
        (string)$data['source'], (string)$data['source_note'], (string)$data['purpose'],
        json_encode(array_values($items), JSON_UNESCAPED_UNICODE), $total,
        !empty($data['by_finance']) ? 1 : 0, $id,
    ]);
    return $st->rowCount() > 0;
}

/**
 * Einen angehefteten Beleg löschen: lokale Datei, Datenbankzeile UND die Kopie in der
 * Nextcloud. Letzteres ist wichtig, weil die Anhänge dort durchnummeriert sind
 * (-Beleg1, -Beleg2 …): bliebe die Datei liegen, hätte man nach dem nächsten Speichern
 * eine Dublette mit verrutschter Nummer im Archiv.
 */
function expense_file_delete(int $fileId, bool $withArchive = true): void
{
    if ($fileId <= 0) return;
    $st = db()->prepare('SELECT stored_name FROM expense_files WHERE id = ?');
    $st->execute([$fileId]);
    if (($sn = $st->fetchColumn()) !== false) {
        @unlink(upload_dir() . '/' . basename((string)$sn)); // basename schützt vor Pfad-Tricks
    }
    db()->prepare('DELETE FROM expense_files WHERE id = ?')->execute([$fileId]);
    if ($withArchive) {
        expense_nc_drop('expense_att', $fileId);
    } else {
        // Archivkopie behalten, nur den Merker abräumen
        db()->prepare("DELETE FROM nc_files WHERE kind = 'expense_att' AND ref_id = ?")->execute([$fileId]);
    }
}

/** Gemerkte Nextcloud-Datei entfernen (dort löschen, Merker abräumen). */
function expense_nc_drop(string $kind, int $refId): void
{
    $path = expense_nc_path($kind, $refId);
    if ($path !== '' && nc_configured()) { $e = null; dav_delete('nextcloud', $path, $e); }
    db()->prepare('DELETE FROM nc_files WHERE kind = ? AND ref_id = ?')->execute([$kind, $refId]);
}

/**
 * Belegblatt in der App löschen – mitsamt Anhängen und Nachrichten.
 * $withArchive entscheidet über die Nextcloud (Häkchen im Lösch-Dialog):
 *   false (Standard) = PDF und Belege bleiben dort liegen, es ist das Archiv;
 *   true             = alles auch in der Nextcloud löschen (landet dort im Papierkorb).
 * Der Merker in nc_files geht in beiden Fällen weg, damit ein späteres Belegblatt
 * denselben Dateinamen benutzen darf.
 */
function expense_claim_delete(int $id, bool $withArchive = false): void
{
    if ($id <= 0) return;
    // Alle Dateien des Vorgangs – auch die an Nachrichten hängenden, die stehen
    // nicht in expense_files_of()
    $st = db()->prepare('SELECT id FROM expense_files WHERE claim_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $f) expense_file_delete((int)$f['id'], $withArchive);
    db()->prepare('DELETE FROM expense_comments WHERE claim_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM expense_claims WHERE id = ?')->execute([$id]);
    if ($withArchive) {
        expense_nc_drop('expense', $id);
    } else {
        db()->prepare("DELETE FROM nc_files WHERE kind = 'expense' AND ref_id = ?")->execute([$id]);
    }
}

/** Attribute fürs Häkchen „auch in der Nextcloud löschen" ('' wenn dort nichts liegt). */
function expense_delete_check_attr(int $claimId): string
{
    if (expense_nc_path('expense', $claimId) === '') return '';
    return ' data-confirm-check="Auch die PDF (und die Belege) in der Nextcloud löschen –'
        . ' sie landen dort im Papierkorb" data-confirm-check-name="nc_too"';
}

/** Dateiname: „Protokoll_07. Ordentliche_24.06.2026.docx". */
function protokoll_filename(array $m): string
{
    $kind = (string)($m['kind'] ?? 'ordentlich');
    $lbl  = $kind === 'ausserordentlich' ? 'Außerordentliche' : ($kind === 'sonstige' ? 'Sitzung' : 'Ordentliche');
    $n    = meeting_number($m);
    $date = ($t = strtotime((string)($m['starts_at'] ?? ''))) ? date('d.m.Y', $t) : '';
    $prefix = $n ? 'Protokoll_' . sprintf('%02d', $n) . '. ' . $lbl : 'Protokoll_' . $lbl;
    return $prefix . '_' . $date . '.docx';
}

/** Namen der Vorsitz-Personen (Referat „Vorsitz" oder Rolle vorsitz), „Vorname Nachname". */
function protokoll_vorsitz_names(): array
{
    $rows = db()->query("SELECT name FROM members WHERE active = 1 AND role = 'vorsitz' ORDER BY sort, name COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_map('strval', $rows)));
}

/** Absatz im Vorlagen-Stil (Arial). $sz = halbe Punkt (24 = 12pt). */
function docx_para_arial(string $text, int $sz = 24, bool $bold = false, int $indent = 0): string
{
    $rpr = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>' . ($bold ? '<w:b/><w:bCs/>' : '') . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr>';
    $ppr = '<w:pPr>' . ($indent ? '<w:ind w:left="' . $indent . '"/>' : '') . $rpr . '</w:pPr>';
    return '<w:p>' . $ppr . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . docx_escape($text) . '</w:t></w:r></w:p>';
}

/** Zeit-Zusatz aus dem TO-Feld, z. B. „ (5 min)". */
function protokoll_time_suffix($t): string
{
    $t = trim((string)$t);
    if ($t === '') return '';
    return is_numeric($t) ? ' (' . (int)$t . ' min)' : ' (' . $t . ')';
}

/** Tagesordnungs-Übersicht (alle TOPs inkl. intern, mit Zeiten) als OOXML. */
function protokoll_overview_xml(array $m): string
{
    $out = docx_para_arial('Tagesordnungspunkte', 28, true);
    $maj = 0; $sub = 0;
    foreach (merged_agenda($m) as $it) {
        if (!empty($it['internal'])) {            // interner Teil gehört nicht in die öffentliche TO-Übersicht
            $maj++;
            $out .= docx_para_arial('TOP ' . $maj . ': Interner Teil (nicht öffentlich nach §10 der GO)', 24, true);
            break;
        }
        if (!empty($it['sub'])) {
            $sub++;
            $out .= docx_para_arial($maj . '.' . $sub . ' ' . (string)$it['text'] . protokoll_time_suffix($it['time'] ?? ''), 24, false, 425);
        } else {
            $maj++; $sub = 0;
            $out .= docx_para_arial('TOP ' . $maj . ': ' . (string)$it['text'] . protokoll_time_suffix($it['time'] ?? ''), 24, true);
        }
    }
    return $out;
}

/** Protokoll-Gerüst (öffentlicher Teil): jeder TOP mit Platz für Ergebnisse, Berichte vorausgefüllt. */
function protokoll_skeleton_xml(array $m): string
{
    $reps = reports_for_meeting((int)$m['id']);
    $out = ''; $maj = 0; $sub = 0; $reportsDone = false;
    foreach (merged_agenda($m) as $it) {
        $internal = !empty($it['internal']);
        $title = (string)$it['text'];
        // Der „Berichte der …Referent:innen"-Punkt: dort kommen die Berichte rein (Unterpunkt bevorzugt)
        $reportsHere = !$reportsDone && preg_match('/referent|referate/i', $title);
        if (!empty($it['sub'])) {
            if ($internal) continue;
            $sub++;
            $out .= docx_para_arial($maj . '.' . $sub . ' ' . $title, 24, true, 425);
            if ($reportsHere) { $out .= protokoll_reports_block($reps, 850); $reportsDone = true; }
            else { $sp = protokoll_special_content($title, 850, $m); $out .= $sp !== '' ? $sp : docx_para_arial('', 24, false, 850); }
            continue;
        }
        $maj++; $sub = 0;                 // Nummerierung läuft mit (auch über interne TOPs)
        if ($internal) continue;          // interner Teil gehört nicht ins öffentliche Protokoll
        $out .= docx_para_arial('TOP ' . $maj . ': ' . $title, 24, true);
        if ($reportsHere) { $out .= protokoll_reports_block($reps, 425); $reportsDone = true; }
        else { $sp = protokoll_special_content($title, 425, $m); $out .= $sp !== '' ? $sp : docx_para_arial(''); }
    }
    $out .= docx_para_arial('');
    $out .= docx_para_arial('_______________ Ende des öffentlichen Teils um xx:xx Uhr __________________');
    $out .= docx_para_arial('TOP [Y]: Interner Teil //(nicht öffentlich, nach §10 der GO)', 24, true);
    $out .= docx_para_arial('•  Geöffnet um xx:xx Uhr', 24, false, 425);
    $out .= docx_para_arial('•  Geschlossen um xx:xx Uhr', 24, false, 425);
    return $out;
}

/**
 * Welche Protokolle stehen in dieser Sitzung zur Genehmigung an? Liefert Beschriftungen wie
 * „12. Ordentlichen Sitzung vom 15.09.2026" – für die Vorlage und die Anzeige.
 */
function protokoll_pending_approvals(array $m): array
{
    if (empty($m['id'])) return [];
    $st = db()->prepare("SELECT s.* FROM vote_items v JOIN meetings s ON s.id = v.ref_meeting_id
                         WHERE v.meeting_id = ? AND v.kind = 'protocol' ORDER BY s.starts_at");
    $st->execute([(int)$m['id']]);
    $out = [];
    foreach ($st->fetchAll() as $src) {
        $out[] = meeting_label($src) . ' vom ' . fmt_date(substr((string)$src['starts_at'], 0, 10));
    }
    return $out;
}

/** Abstimmungszeile zum Ausfüllen (nur Zahlen eintragen). */
function protokoll_vote_line(int $indent): string
{
    return docx_para_arial('Ja:        Enthaltung:        Nein:', 24, false, $indent);
}

/**
 * Standard-Inhalt unter bestimmten TOPs (anhand des Titels). Leerer String = nichts Spezielles.
 * $m = die Sitzung, für die die Vorlage entsteht – daraus kommen die konkret anstehenden Protokolle.
 */
function protokoll_special_content(string $title, int $indent, array $m = []): string
{
    if (preg_match('/beschlussf[aä]higkeit/iu', $title) || preg_match('/(beschluss|genehmigung).*tagesordnung/iu', $title)) {
        return protokoll_vote_line($indent); // Beschlussfähigkeit / Beschluss der Tagesordnung
    }
    if (preg_match('/beauftragt/iu', $title)) { // Wahl der Beauftragten
        $out = '';
        foreach (['Wording', 'Awareness', 'Zeit & Redundanz'] as $rolle) $out .= docx_para_arial('•  ' . $rolle . ':', 24, false, $indent);
        return $out;
    }
    if (preg_match('/protokoll/iu', $title)) { // Beschluss der Protokolle
        // Welche Protokolle hier anstehen, weiß die App bereits – sie hat die Gegenstände selbst
        // angelegt. Also die echten Sitzungen hinschreiben statt „x. ordentliche vom dd.mm.yyyy".
        $out = '';
        foreach (protokoll_pending_approvals($m) as $z) {
            $out .= docx_para_arial('•  Protokoll der ' . $z . ' angenommen', 24, false, $indent)
                . protokoll_vote_line($indent);
        }
        if ($out !== '') return $out;
        return docx_para_arial('•  Protokoll der x. ordentlichen AStA-Sitzung vom dd.mm.yyyy angenommen', 24, false, $indent)
            . protokoll_vote_line($indent);
    }
    return '';
}

/** Berichte je Referat (Name fett + Stichpunkte) als OOXML, eingerückt ab $indent (Twips). */
function protokoll_reports_block(array $reps, int $indent): string
{
    $out = '';
    foreach (referate_list() as $ref) {
        $out .= docx_para_arial($ref . ':', 24, true, $indent);
        $any = false;
        foreach (preg_split('/\r\n|\r|\n/', (string)($reps[$ref] ?? '')) as $ln) {
            $ln = trim($ln);
            if ($ln !== '') { $out .= docx_para_arial('•  ' . $ln, 24, false, $indent + 425); $any = true; }
        }
        if (!$any) $out .= docx_para_arial('BERICHT FEHLT', 24, true, $indent + 425); // kein Bericht eingetragen
    }
    return $out;
}

/** Merge der Sitzungsdaten in die Protokoll-Vorlage (.docx). Gibt Binärinhalt zurück oder null bei Problem. */
/**
 * Trägt Abgemeldete in die „Abwesende AStArier*innen"-Tabelle der Word-Vorlage ein.
 * Struktur der Vorlage: Kopfzeile + 5 Zeilen à 6 Zellen (Nr | Name | x | Nr | Name | x).
 * Befüllt erst die linke Spalte (Plätze 1–5), dann die rechte (6–10); weitere werden ignoriert.
 * Das vorgedruckte „x" (= entschuldigt) bleibt NUR bei Plätzen mit eingetragenem Namen stehen –
 * bei allen leeren Plätzen wird es entfernt (unentschuldigte trägt die Protokollant:in von Hand ein).
 */
function protokoll_fill_absent_table(string $doc, array $names): string
{
    $pos = strpos($doc, 'Abwesende');
    if ($pos === false) return $doc; // Vorlage ohne die Tabelle → nichts tun
    $tblStart = strrpos(substr($doc, 0, $pos), '<w:tbl>');
    $tblEndAt = strpos($doc, '</w:tbl>', $pos);
    if ($tblStart === false || $tblEndAt === false) return $doc;
    $tblEnd = $tblEndAt + strlen('</w:tbl>');
    $tbl = substr($doc, $tblStart, $tblEnd - $tblStart);

    // Platz k (0-basiert): Zeile 1+(k%5); Name-Zelle 1 / x-Zelle 2 (links) bzw. 4 / 5 (rechts)
    $names = array_values($names);
    $byRow = []; // [zeile][zelle] = ['name' => …] | ['clearx' => true]
    for ($k = 0; $k < 10; $k++) {
        $row = 1 + ($k % 5);
        $nameCell = $k < 5 ? 1 : 4;
        if (isset($names[$k])) $byRow[$row][$nameCell] = ['name' => $names[$k]];
        else                   $byRow[$row][$nameCell + 1] = ['clearx' => true];
    }

    $ri = -1;
    $newTbl = preg_replace_callback('/<w:tr\b.*?<\/w:tr>/s', function ($tr) use (&$ri, $byRow) {
        $ri++;
        if (empty($byRow[$ri])) return $tr[0];
        $cells = $byRow[$ri]; $ci = -1;
        return preg_replace_callback('/<w:tc\b.*?<\/w:tc>/s', function ($tc) use (&$ci, $cells) {
            $ci++;
            if (!isset($cells[$ci])) return $tc[0];
            if (!empty($cells[$ci]['clearx'])) { // vorgedrucktes „x" leeren (Formatierung/Run bleibt)
                return preg_replace('/(<w:t[^>]*>)\s*x\s*(<\/w:t>)/i', '$1$2', $tc[0]);
            }
            $run = '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="22"/></w:rPr>'
                 . '<w:t xml:space="preserve">' . docx_escape((string)$cells[$ci]['name']) . '</w:t></w:r>';
            $p = strpos($tc[0], '</w:p>'); // Name in den ersten Absatz der Zelle
            return $p === false ? $tc[0] : substr($tc[0], 0, $p) . $run . substr($tc[0], $p);
        }, $tr[0]);
    }, $tbl);

    return substr($doc, 0, $tblStart) . $newTbl . substr($doc, $tblEnd);
}

function merge_protokoll_template(array $m, string $tplFile): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'pdocx');
    if (!@copy($tplFile, $tmp)) { @unlink($tmp); return null; }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }
    $doc = $zip->getFromName('word/document.xml');
    if ($doc === false) { $zip->close(); @unlink($tmp); return null; }

    // 1) Über Runs zerlegte Platzhalter „heilen" (nur bekannte Tokens)
    $doc = docx_heal_placeholders((string)$doc, ['Sitzungsdatum', 'VORSITZ', 'RefX', 'Tagesordnungspunkte', 'TO-2']);

    // 2) Einfache Tokens
    $date = ($t = strtotime((string)($m['starts_at'] ?? ''))) ? date('d.m.Y', $t) : '';
    $doc = str_replace('((Sitzungsdatum))', docx_escape($date), $doc);
    $doc = str_replace('((VORSITZ))', docx_escape(implode(', ', protokoll_vorsitz_names())), $doc);

    // 3) ((RefX)) der Reihe nach mit Mitgliedsnamen füllen (eine pro Slot)
    $names = array_map(fn($mm) => (string)$mm['name'], members_all());
    $i = 0;
    $doc = preg_replace_callback('/\(\(RefX\)\)/', function () use (&$i, $names) { $n = $names[$i] ?? ''; $i++; return docx_escape($n); }, $doc);

    // 3b) Abgemeldete (entschuldigt) in die „Abwesende"-Tabelle eintragen – das vorgedruckte „x" bleibt
    $doc = protokoll_fill_absent_table($doc, meeting_rsvp_lists((int)$m['id'])['abgemeldet']);

    // 4) Bereich von ((Tagesordnungspunkte)) bis zum finalen <w:sectPr> ersetzen:
    //    TO-Übersicht + Protokoll-Gerüst (öffentlicher Teil) mit vorausgefüllten Berichten
    $posTO = strpos($doc, '((Tagesordnungspunkte))');
    $posSect = strrpos($doc, '<w:sectPr');
    if ($posTO !== false && $posSect !== false && $posSect > $posTO) {
        $pStart = strrpos(substr($doc, 0, $posTO), '<w:p ');
        if ($pStart !== false) {
            // Seitenumbruch zwischen TO-Übersicht und Protokoll-Gerüst: Protokoll beginnt auf neuer Seite
            $pageBreak = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            $doc = substr($doc, 0, $pStart) . protokoll_overview_xml($m) . $pageBreak . protokoll_skeleton_xml($m) . substr($doc, $posSect);
        }
    }

    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', $doc);
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Protokollvorlage als .docx: nutzt die hochgeladene Vorlage (Merge); sonst eine einfache Standardstruktur. */
function build_protokoll_docx(array $m): string
{
    $tpl = docx_template_path('protokoll');
    if ($tpl) {
        $merged = merge_protokoll_template($m, $tpl);
        if ($merged !== null && $merged !== '') return $merged;
    }
    return build_protokoll_docx_fallback($m);
}

/** Fallback ohne Vorlagendatei: Kopf + Tagesordnung + Berichte-Tabelle, aus dem Code erzeugt. */
function build_protokoll_docx_fallback(array $m): string
{
    $body  = docx_paragraph(meeting_label($m), true, 16);
    $when  = invite_datetime_de($m);
    if ($when !== '') $body .= docx_paragraph($when);
    if (trim((string)($m['location'] ?? '')) !== '') $body .= docx_paragraph('Raum: ' . $m['location']);
    $abg = meeting_rsvp_lists((int)$m['id'])['abgemeldet'];
    $body .= docx_paragraph('') . docx_paragraph('Sitzungsleitung: ') . docx_paragraph('Protokoll: ') . docx_paragraph('Anwesend: ');
    $body .= docx_paragraph('Abwesend (entschuldigt): ' . implode(', ', $abg)) . docx_paragraph('');
    $body .= docx_paragraph('Tagesordnung', true, 14);
    $maj = 0; $min = 0;
    foreach (merged_agenda($m) as $it) {
        if (!empty($it['internal'])) { // interner Teil gehört nicht ins öffentliche Protokoll → Sammel-TOP
            $maj++;
            $body .= docx_paragraph('TOP ' . $maj . ': Interner Teil (nicht öffentlich nach §10 der GO)', true) . docx_paragraph('');
            break;
        }
        if (!empty($it['sub'])) { $min++; $body .= docx_paragraph('     ' . $maj . '.' . $min . '  ' . (string)$it['text'], true); }
        else { $maj++; $min = 0; $body .= docx_paragraph('TOP ' . $maj . ': ' . (string)$it['text'], true); }
        $body .= docx_paragraph('');
    }
    $body .= docx_paragraph('') . docx_paragraph('Berichte der Referate', true, 14);
    $reps = reports_for_meeting((int)$m['id']);
    $rows = []; $nr = 1;
    foreach (referate_list() as $ref) $rows[] = ['nr' => $nr++, 'referat' => $ref, 'content' => (string)($reps[$ref] ?? '')];
    $body .= docx_reports_table($rows);
    return docx_package($body);
}

// ---------------------------------------------------------------------------
// Internes Protokoll (nicht-öffentlicher Teil) – deutlich kleiner als das öffentliche
// ---------------------------------------------------------------------------

/** Dateiname, z. B. „Interner Teil 06. Ordentliche Sitzung 10.06.2026.docx". */
function protokoll_intern_filename(array $m): string
{
    $kind = (string)($m['kind'] ?? 'ordentlich');
    $lbl  = $kind === 'ausserordentlich' ? 'Außerordentliche' : ($kind === 'sonstige' ? 'Sitzung' : 'Ordentliche');
    $n    = meeting_number($m);
    $date = ($t = strtotime((string)($m['starts_at'] ?? ''))) ? date('d.m.Y', $t) : '';
    $prefix = $n ? 'Interner Teil ' . sprintf('%02d', $n) . '. ' . $lbl . ' Sitzung' : 'Interner Teil ' . $lbl . ' Sitzung';
    return trim($prefix . ' ' . $date) . '.docx';
}

/**
 * Interne TOPs mit ihrer TO-Nummer – exakt wie in der Tagesordnungs-Übersicht nummeriert
 * (Haupt-TOP fortlaufend, Unterpunkt N.M). Interne TOPs setzen die öffentliche Nummerierung fort
 * (z. B. öffentlich bis TOP 5 → interne TOPs sind TOP 6, TOP 7 …).
 * @return array<int,array{num:string,text:string}>
 */
function protokoll_intern_items(array $m): array
{
    $maj = 0; $sub = 0; $out = [];
    foreach (merged_agenda($m) as $it) {
        if (!empty($it['sub'])) {
            $sub++;
            if (!empty($it['internal'])) $out[] = ['num' => $maj . '.' . $sub, 'text' => (string)$it['text']];
        } else {
            $maj++; $sub = 0;
            if (!empty($it['internal'])) $out[] = ['num' => (string)$maj, 'text' => (string)$it['text']];
        }
    }
    if (!$out) $out[] = ['num' => (string)($maj + 1), 'text' => '']; // kein interner TOP: leerer Platzhalter mit nächster Nummer
    return $out;
}

/** Abschnitt mit den internen TOPs (Überschrift in TO-Nummerierung + leerer Stichpunkt) als OOXML. */
function protokoll_intern_section_xml(array $m): string
{
    $out = '';
    foreach (protokoll_intern_items($m) as $it) {
        $out .= docx_para_arial('TOP ' . $it['num'] . ': ' . $it['text'], 24, true);
        $out .= docx_para_arial('•  ', 24, false, 425); // leerer Stichpunkt zum Ausfüllen
    }
    return $out;
}

/** Merge der Sitzungsdaten in die interne Protokoll-Vorlage (.docx). Null bei Problem. */
function merge_protokoll_intern_template(array $m, string $tplFile): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'pidocx');
    if (!@copy($tplFile, $tmp)) { @unlink($tmp); return null; }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }
    $doc = $zip->getFromName('word/document.xml');
    if ($doc === false) { $zip->close(); @unlink($tmp); return null; }

    // 1) Datum einsetzen (die Platzhalter stehen je in einem eigenen Run – kein „Heilen" nötig)
    $date = ($t = strtotime((string)($m['starts_at'] ?? ''))) ? date('d.m.Y', $t) : '';
    $doc = str_replace(['dd.mm.202j', 'dd.mm.20jj'], docx_escape($date), $doc);

    // 2) Den Platzhalter-Absatz „TOP 8.1: " durch die echten internen TOPs (korrekt nummeriert) ersetzen
    $done = false;
    $doc = preg_replace_callback('/<w:p\b[^>]*>.*?<\/w:p>/s', function ($mm) use ($m, &$done) {
        if (!$done && strpos($mm[0], '8.1') !== false && strpos($mm[0], 'TOP') !== false) {
            $done = true;
            return protokoll_intern_section_xml($m);
        }
        return $mm[0];
    }, $doc);

    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', $doc);
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Fallback ohne Vorlagendatei: gleiche Struktur aus dem Code (Arial, ohne Logo). */
function build_protokoll_intern_docx_fallback(array $m): string
{
    $date = ($t = strtotime((string)($m['starts_at'] ?? ''))) ? date('d.m.Y', $t) : '';
    $body  = docx_para_arial('AStA-Sitzungsprotokoll vom ' . $date);
    $body .= docx_para_arial('');
    $body .= docx_para_arial('Beginn interner Teil: x Uhr');
    $body .= docx_para_arial('');
    $body .= protokoll_intern_section_xml($m);
    $body .= docx_para_arial('');
    $body .= docx_para_arial('Ende interner Teil: x Uhr');
    return docx_package($body);
}

/** Internes Protokoll als .docx: nutzt die (Frontend-)Vorlage (Merge); sonst Standardstruktur aus dem Code. */
function build_protokoll_intern_docx(array $m): string
{
    $tpl = docx_template_path('protokoll_intern');
    if ($tpl) {
        $merged = merge_protokoll_intern_template($m, $tpl);
        if ($merged !== null && $merged !== '') return $merged;
    }
    return build_protokoll_intern_docx_fallback($m);
}

/** Aktuell laufende, nicht abgesagte Sitzung (jetzt zwischen Start und Ende; ohne Ende: +4 h). */
function current_live_meeting(): ?array
{
    $now = date('Y-m-d H:i:s');
    foreach (db()->query("SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa' ORDER BY starts_at")->fetchAll() as $m) {
        $start = (string)$m['starts_at'];
        $from = date('Y-m-d H:i:s', strtotime($start . ' -15 minutes')); // 15 Min vor Beginn …
        $until = substr($start, 0, 10) . ' 23:59:59';                     // … bis Tagesende
        if ($now >= $from && $now <= $until) return $m;
    }
    return null;
}

// ---------------------------------------------------------------------------
// E-Mail & Erinnerungen
// ---------------------------------------------------------------------------
function base_url(): string
{
    return rtrim((string)setting_get('base_url', ''), '/');
}

/** Absolute URL zu einem App-Pfad (z. B. 'event.php?id=3'). */
function app_url(string $path): string
{
    $b = base_url();
    return $b ? $b . '/' . ltrim($path, '/') : $path;
}

/**
 * Mappt einen internen App-Pfad auf den öffentlichen Teilen-/Vorschau-Link `s.php`, der eine
 * Linkvorschau (Open Graph) liefert. So zeigen geteilte Links in Teams & Co. Titel/Datum/Ort.
 * Unbekannte Pfade werden unverändert (absolut) zurückgegeben.
 */
function share_url(string $path): string
{
    if (preg_match('#^(event|meeting|gettogether)\.php\?id=(\d+)#', $path, $m)) {
        $type = ['event' => 'event', 'meeting' => 'meeting', 'gettogether' => 'gt'][$m[1]];
        return app_url('s.php?t=' . $type . '&id=' . (int)$m[2]);
    }
    return app_url($path);
}

/**
 * Vorschau-Daten für `s.php`: Titel, Beschreibung (Datum · Ort) und der echte Zielpfad – oder
 * null, wenn das geteilte Objekt nicht (mehr) existiert bzw. (noch) nicht veröffentlicht ist.
 */
function share_preview(string $type, int $id): ?array
{
    if ($id <= 0) return null;
    switch ($type) {
        case 'event':
            $e = event_get($id);
            if (!$e || (int)($e['draft'] ?? 0) !== 0) return null;
            $parts = [];
            if (($r = fmt_event_range($e['starts_at'] ?? null, $e['ends_at'] ?? null)) !== '') $parts[] = $r;
            $locs = [];
            foreach (slots_grouped(event_slots($id)) as $s) { $l = trim((string)$s['location']); if ($l !== '' && !in_array($l, $locs, true)) $locs[] = $l; }
            if ($locs) $parts[] = implode(' · ', $locs);
            if (!empty($e['important'])) $parts[] = 'extrem wichtig';
            return ['title' => (string)$e['title'], 'desc' => implode(' · ', $parts), 'target' => 'event.php?id=' . $id, 'emoji' => '📅'];
        case 'meeting':
            $st = db()->prepare('SELECT * FROM meetings WHERE id = ? AND draft = 0 AND cancelled = 0');
            $st->execute([$id]);
            $m = $st->fetch();
            if (!$m) return null;
            $parts = [fmt_slot($m['starts_at'], meeting_ends_at($m))];
            if (($loc = trim((string)$m['location'])) !== '') $parts[] = $loc;
            return ['title' => meeting_label($m), 'desc' => implode(' · ', $parts), 'target' => 'meeting.php?id=' . $id, 'emoji' => '🏛️'];
        case 'gt':
            $g = gettogether_get($id);
            if (!$g) return null;
            $parts = [fmt_slot($g['starts_at'], $g['ends_at'] ?? null)];
            if (($loc = trim((string)$g['location'])) !== '') $parts[] = $loc;
            return ['title' => (string)$g['title'], 'desc' => implode(' · ', $parts), 'target' => 'gettogether.php?id=' . $id, 'emoji' => '🎉'];
    }
    return null;
}

/** Kleiner „Teilen"-Button (Icon): kopiert den öffentlichen Vorschau-Link bzw. nutzt die Web-Share-API. */
function share_button(string $path, string $label = 'Link teilen', bool $asBtn = false): string
{
    $url = share_url($path);
    // $asBtn: in Button-Leisten als normaler ".btn secondary small" (passt zu Bearbeiten/Papierkorb);
    // sonst der dezente runde Icon-Knopf (.teilen-btn) für Titelkacheln/Event-Karten.
    $cls = $asBtn ? 'btn secondary small js-teilen' : 'teilen-btn js-teilen';
    return '<button type="button" class="' . $cls
        . '" data-url="' . h($url) . '" data-title="' . h($label) . '" aria-label="' . h($label)
        . '" title="' . h($label) . '"><i class="ti ti-share"></i></button>';
}

function mail_from(): string
{
    $f = trim((string)setting_get('mail_from', ''));
    if ($f === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $f = 'asta-planer@' . preg_replace('/^www\./', '', $host);
    }
    // Ins gemeinsame Konto spiegeln: Umfragen, externe Events und der Terminplaner binden lib.php
    // nie ein (öffentliche Seiten), müssen aber DIESELBE Absenderadresse benutzen – siehe
    // mail_pool_absender(). Der Merker begrenzt nur das Schreiben auf einmal je Aufruf; der
    // zurückgegebene Wert wird jedes Mal frisch gelesen, ändert ihn also jemand mitten im
    // Vorgang, gilt sofort der neue.
    static $gespiegelt = false;
    if (!$gespiegelt) {
        $gespiegelt = true;
        if (mail_pool_absender() !== $f) mail_pool_absender_set($f);
    }
    return $f;
}

/**
 * Kopfzeilen dürfen keine Zeilenumbrüche enthalten – sonst könnte ein Wert mit "\r\n" weitere
 * Header (etwa ein zusätzliches Bcc) einschmuggeln. Alles ab dem ersten Umbruch fliegt raus.
 */
function mail_header_safe(string $v): string
{
    return trim((string)preg_replace('/[\r\n].*/s', '', $v));
}

/**
 * Anzeigename für eine From-Zeile aufbereiten: Nicht-ASCII wird MIME-kodiert (sonst zeigen
 * Mailprogramme Buchstabensalat), reines ASCII kommt in Anführungszeichen – damit Zeichen wie
 * ":" in „AStA Pat:innenprogramm" den Header nicht zerlegen.
 */
function mail_header_name(string $name): string
{
    $name = mail_header_safe($name);
    if ($name === '') return '';
    if (preg_match('/[^\x20-\x7E]/', $name)) return '=?UTF-8?B?' . base64_encode($name) . '?=';
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
}

/**
 * $replyTo: wohin Antworten gehen sollen, wenn das NICHT die Absenderadresse ist (das
 * Pat:innenprogramm schickt z. B. von der App-Adresse, will Antworten aber bei den
 * Verantwortlichen haben). $fromName ersetzt den Anzeigenamen „AStA-App".
 * $cc: weitere Empfänger:innen (kommagetrennt) im CC – gedacht für die Gruppen-Mail des
 * Pat:innenprogramms, bei der ALLE einander sehen SOLLEN (ein „Allen antworten" erreicht
 * die ganze Gruppe). Für alles andere gilt weiter: eine Mail je Person, kein CC.
 */
function send_mail(string $to, string $subject, string $body, bool $html = false, ?string $from = null,
                   ?string $replyTo = null, ?string $fromName = null, string $quelle = 'app', string $cc = ''): bool
{
    // Der Absender ist NICHT verhandelbar: Es verschickt immer das eine Postfach, das beim Hoster
    // dafür eingerichtet ist (mail_pool_absender()). Ein Aufrufer, der eine andere Adresse
    // mitgibt, meint in Wahrheit „dorthin sollen die Antworten" – genau dahin wandert sie.
    $gewuenscht = $from !== null ? mail_header_safe($from) : '';
    $from  = mail_from();
    $reply = $replyTo !== null && trim($replyTo) !== '' ? mail_header_safe($replyTo) : $gewuenscht;
    if ($reply === '' || !filter_var($reply, FILTER_VALIDATE_EMAIL)) $reply = $from;
    $name = mail_header_name($fromName !== null ? $fromName : 'AStA-App');

    // CC säubern: jede Adresse einzeln durch den Kopfzeilen-Filter und die Prüfung –
    // was nicht besteht, fliegt still raus, statt den ganzen Versand zu kippen.
    $ccListe = [];
    foreach ($cc === '' ? [] : explode(',', $cc) as $adr) {
        $adr = mail_header_safe($adr);
        if ($adr !== '' && filter_var($adr, FILTER_VALIDATE_EMAIL)) $ccListe[] = $adr;
    }

    $headers = [
        'From: ' . ($name !== '' ? $name . ' <' . $from . '>' : $from),
        'Reply-To: ' . $reply,
        'Content-Type: text/' . ($html ? 'html' : 'plain') . '; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: AStA-App',
    ];
    if ($ccListe) $headers[] = 'Cc: ' . implode(', ', $ccListe);
    // Betreff für Nicht-ASCII korrekt kodieren
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $ok = @mail($to, $subjectEnc, $body, implode("\r\n", $headers));
    // Ins gemeinsame Mail-Konto eintragen. App-Mails werden NIE gebremst – sie zählen nur mit,
    // damit die Rundmails wissen, wie viel vom Tageskontingent noch übrig ist.
    // CC-Empfänger:innen zählen einzeln: Der Hoster rechnet je Empfänger:in, nicht je mail()-Aufruf.
    if ($ok) for ($i = 0; $i <= count($ccListe); $i++) mail_pool_note($quelle);
    return $ok;
}

// ---------------------------------------------------------------------------
// Editierbare System-Mailtexte (Verwaltung → Mailtexte). Platzhalter in {{…}}.
// ---------------------------------------------------------------------------
/** Registry aller System-Mailvorlagen: Schlüssel => [label, subject, body, vars]. */
function mail_templates(): array
{
    return [
        'login' => ['label' => 'Login-Link', 'subject' => 'Dein Login-Link',
            'body' => "Hallo,\n\nhier ist dein Login-Link für die AStA-App:\n\n{{LOGIN_LINK}}\n\nHast du die App auf dem Handy installiert und der Link öffnet sich nur im Browser statt in der App?\nDann gib in der App unter „Anmelden“ → „Code eingeben“ stattdessen diesen Code ein:\n\n{{LOGIN_CODE}}\n\nLink und Code sind 30 Minuten gültig und nur einmal verwendbar.\nWenn du das nicht warst, kannst du diese Mail ignorieren.\n\nViele Grüße\nDeine AStA-App",
            'vars' => ['{{LOGIN_LINK}}' => 'Login-Link', '{{LOGIN_CODE}}' => 'Login-Code zum Eintippen in der App']],
        'dm' => ['label' => 'Neue Nachricht / Ankündigung', 'subject' => 'Neue Nachricht – AStA-App',
            'body' => "Hallo {{VORNAME}},\n\n{{KOPF}}\n\n{{NACHRICHT}}\n\nDu findest sie auch auf deinem Dashboard:\n{{LINK}}\n\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{KOPF}}' => '„Nachricht/Ankündigung von …"', '{{NACHRICHT}}' => 'Nachrichtentext', '{{LINK}}' => 'Dashboard-Link']],
        'assignment' => ['label' => 'Einteilung / Arbeitsplan', 'subject' => 'Dein Arbeitsplan: {{EVENT}}',
            'body' => "Hallo {{VORNAME}},\n\ndu bist beim AStA-Event \"{{EVENT}}\" für folgende Schicht(en) eingeteilt:\n\n{{SCHICHTEN}}\n\nDetails & Kalender:\n{{LINK}}\n\nDanke dir und viele Grüße\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{EVENT}}' => 'Event-Titel', '{{SCHICHTEN}}' => 'Liste der Schichten', '{{LINK}}' => 'Event-Link']],
        'unassignment' => ['label' => 'Aus Einteilung gestrichen', 'subject' => '{{EVENT}}: du bist nicht mehr eingeteilt',
            'body' => "Hallo {{VORNAME}},\n\ndie Einteilung beim AStA-Event \"{{EVENT}}\" hat sich geändert – du bist dort jetzt **nicht mehr eingeteilt**.\n\nFalls das ein Versehen ist, melde dich bitte bei der Orga.\n\nDetails:\n{{LINK}}\n\nViele Grüße\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{EVENT}}' => 'Event-Titel', '{{LINK}}' => 'Event-Link']],
        'shift_reminder' => ['label' => 'Schicht-Erinnerung', 'subject' => 'Erinnerung: {{EVENT}}',
            'body' => "Hallo {{VORNAME}},\n\nkleine Erinnerung an deinen Einsatz:\n\n• {{EVENT}}\n  {{SCHICHT}}\n\nDanke und viele Grüße\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{EVENT}}' => 'Event-Titel', '{{SCHICHT}}' => 'Schicht (Zeit/Ort)']],
        'report_reminder' => ['label' => 'Bericht-Erinnerung', 'subject' => 'Bericht fehlt noch – {{SITZUNG}}',
            'body' => "Hallo {{VORNAME}},\n\nfür die {{SITZUNG}} am {{DATUM}} fehlt noch dein Bericht (Referat {{REFERAT}}).\n\nHier eintragen:\n{{LINK}}\n\nDanke!\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{SITZUNG}}' => 'Sitzung', '{{DATUM}}' => 'Datum', '{{REFERAT}}' => 'Referat', '{{LINK}}' => 'Bericht-Link']],
        'voteitem_reminder' => ['label' => 'Abstimmungsgegenstände-Erinnerung', 'subject' => 'Noch ungelesen: Abstimmungsgegenstände – {{SITZUNG}}',
            'body' => "Hallo {{VORNAME}},\n\nfür die {{SITZUNG}} am {{DATUM}} hast du noch {{ANZAHL}} Abstimmungsgegenstand/-gegenstände nicht gelesen.\nBitte schau sie dir vor der Sitzung an, damit du vorbereitet abstimmen kannst.\n\nHier ansehen:\n{{LINK}}\n\nDanke!\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{SITZUNG}}' => 'Sitzung', '{{DATUM}}' => 'Datum', '{{ANZAHL}}' => 'Anzahl ungelesener Gegenstände', '{{LINK}}' => 'Sitzungs-Link']],
        'swap_offer' => ['label' => 'Tausch-Vorschlag (Schichtbörse)', 'subject' => 'Tausch-Vorschlag für deine Schicht – {{EVENT}}',
            'body' => "Hallo {{VORNAME}},\n\n{{ANBIETER}} möchte mit dir eine Schicht tauschen ({{EVENT}}):\nDu gibst {{DEINE_SCHICHT}} ab und bekommst dafür {{ANGEBOTENE_SCHICHT}}.\n\nDer Tausch passiert erst, wenn DU zustimmst. In der Schichtbörse kannst du annehmen oder ablehnen:\n{{LINK}}\n\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{EVENT}}' => 'Event-Titel', '{{ANBIETER}}' => 'Vorschlagende Person', '{{DEINE_SCHICHT}}' => 'Deine Schicht (gibst du ab)', '{{ANGEBOTENE_SCHICHT}}' => 'Gegen-Schicht (bekommst du)', '{{LINK}}' => 'Schichtbörse-Link']],
        'vote_reminder' => ['label' => 'Abstimmungs-Erinnerung', 'subject' => 'Erinnerung: {{EVENT}}',
            'body' => "Hallo {{VORNAME}},\n\nfür das AStA-Event \"{{EVENT}}\" fehlt noch deine Rückmeldung.\n{{FRISTHINWEIS}}\n\nHier eintragen:\n{{LINK}}\n\nDanke und viele Grüße\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{EVENT}}' => 'Event-Titel', '{{FRISTHINWEIS}}' => 'Hinweis zur Frist', '{{LINK}}' => 'Event-Link']],
        'maybe_reminder' => ['label' => '„Evtl."-Erinnerung', 'subject' => 'Bitte „Evtl." anpassen – {{EVENT}}',
            'body' => "Hallo {{VORNAME}},\n\nbei „{{EVENT}}“ hast du bei mindestens einer Schicht „Evtl.“ angegeben.\nDie Abstimmungsfrist endet morgen ({{FRIST}}). Bitte leg dich bis dahin auf Ja oder Nein fest – sonst wird „Evtl.“ automatisch zu „Nein“.\n\nHier anpassen:\n{{LINK}}\n\nDanke!\nDeine AStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{EVENT}}' => 'Event-Titel', '{{FRIST}}' => 'Fristdatum', '{{LINK}}' => 'Event-Link']],
        'invite_reminder' => ['label' => 'Einladungs-Reminder (Sekretariat)', 'subject' => 'Einladung vorbereiten – {{SITZUNG}}',
            'body' => "Hallo {{VORNAME}},\n\ndie Einladung zur „{{SITZUNG}}“ ({{DATUM}}) sollte bald raus – geplant für den {{STICHTAG}}.\n\nBitte kümmere dich um den Versand (Teams-Link eintragen, Einladung verschicken bzw. freigeben):\n{{LINK}}\n\nAStA-App",
            'vars' => ['{{VORNAME}}' => 'Vorname', '{{SITZUNG}}' => 'Sitzung', '{{DATUM}}' => 'Datum', '{{STICHTAG}}' => 'Versand-Stichtag', '{{LINK}}' => 'Sitzungs-Link']],
    ];
}

function mail_tpl_subject(string $key): string
{
    $d = mail_templates()[$key] ?? null; if (!$d) return '';
    $s = (string)setting_get('mailtpl_' . $key . '_subject', '');
    return trim($s) !== '' ? $s : $d['subject'];
}
function mail_tpl_body(string $key): string
{
    $d = mail_templates()[$key] ?? null; if (!$d) return '';
    $b = (string)setting_get('mailtpl_' . $key . '_body', '');
    return trim($b) !== '' ? $b : $d['body'];
}
/** Versandformat einer Vorlage: 'html' oder 'plain' (Standard plain). */
function mail_tpl_format(string $key): string
{
    return (string)setting_get('mailtpl_' . $key . '_format', '') === 'html' ? 'html' : 'plain';
}

/** Reinen Text als sicheres HTML aufbereiten (Zeilenumbrüche -> <br>); fertiges HTML bleibt unverändert. */
function mail_body_html(string $body): string
{
    if (strip_tags($body) !== $body) return $body; // enthält bereits HTML-Tags
    return '<div style="font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2b30;line-height:1.55">'
        . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</div>';
}

/** Mailvorlage mit Platzhaltern füllen und – je nach Format – als HTML oder Plaintext senden. */
function mail_tpl_send(string $key, string $to, array $vars, ?string $from = null): bool
{
    $subject = strtr(mail_tpl_subject($key), $vars);
    $body = strtr(mail_tpl_body($key), $vars);
    $html = mail_tpl_format($key) === 'html';
    return send_mail($to, $subject, $html ? mail_body_html($body) : $body, $html, $from);
}

/**
/** Offene Events (Frist läuft noch), bei denen das Mitglied mindestens ein „Evtl." stehen hat. */
function events_with_my_maybe(int $memberId): array
{
    $st = db()->prepare(
        "SELECT e.* FROM events e
         WHERE e.closed = 0 AND e.draft = 0 AND e.deadline IS NOT NULL AND e.deadline <> '' AND e.deadline >= date('now','localtime')
           AND EXISTS (SELECT 1 FROM responses r JOIN event_slots s ON s.id = r.slot_id
                       WHERE s.event_id = e.id AND r.member_id = :me AND r.status = 'maybe')
         ORDER BY e.deadline"
    );
    $st->execute([':me' => $memberId]);
    return $st->fetchAll();
}

/** Einen Tag vor Fristende: Mitglieder mit „Evtl." erinnern, dass es sonst zu „Nein" wird. */
function send_maybe_reminders(bool $dryRun = false): int
{
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $events = db()->prepare("SELECT * FROM events WHERE closed = 0 AND draft = 0 AND deadline = ?");
    $events->execute([$tomorrow]);
    $sent = 0;
    foreach ($events->fetchAll() as $e) {
        $st = db()->prepare(
            "SELECT DISTINCT m.* FROM members m
             JOIN responses r ON r.member_id = m.id
             JOIN event_slots s ON s.id = r.slot_id
             WHERE s.event_id = ? AND r.status = 'maybe' AND m.active = 1 AND m.email <> ''"
        );
        $st->execute([(int)$e['id']]);
        $chk = db()->prepare('SELECT 1 FROM reminder_log WHERE event_id=? AND member_id=? AND sent_on=?');
        $today = date('Y-m-d');
        foreach ($st->fetchAll() as $m) {
            $chk->execute([(int)$e['id'], (int)$m['id'], $today]);
            if ($chk->fetchColumn()) continue;
            if ($dryRun) { $sent++; continue; } // nur zählen, nichts senden/loggen
            if (notify_deliver((int)$m['id'], 'maybe_deadline', fn() => mail_tpl_send('maybe_reminder', member_mail($m), [
                    '{{VORNAME}}' => first_name($m['name']),
                    '{{EVENT}}' => (string)$e['title'],
                    '{{FRIST}}' => fmt_date($e['deadline']),
                    '{{LINK}}' => app_url('event.php?id=' . (int)$e['id'] . '#abstimmung'),
                ]), 'Frist morgen: ' . (string)$e['title'],
                    'Du stehst noch auf „Vielleicht" – bitte bis ' . fmt_date($e['deadline']) . ' entscheiden, sonst wird daraus ein Nein.',
                    app_url('event.php?id=' . (int)$e['id'] . '#abstimmung'))) {
                db()->prepare('INSERT OR IGNORE INTO reminder_log(event_id, member_id, sent_on) VALUES(?,?,?)')->execute([(int)$e['id'], (int)$m['id'], $today]);
                $sent++;
            }
        }
    }
    return $sent;
}

/** Nach Fristablauf offen gebliebene „Evtl." auf „Nein" setzen. Idempotent. */
function convert_expired_maybes(): int
{
    $st = db()->prepare(
        "UPDATE responses SET status = 'no'
         WHERE status = 'maybe' AND slot_id IN (
             SELECT s.id FROM event_slots s JOIN events e ON e.id = s.event_id
             WHERE e.deadline IS NOT NULL AND e.deadline <> '' AND e.deadline < date('now','localtime')
         )"
    );
    $st->execute();
    return $st->rowCount();
}

/**
 * Räumt „Hintergrund-Rauschen" auf (täglich per Cron): alte Dashboard-Nachrichten,
 * Erinnerungs-Logs und abgelaufene Login-Tokens. Inhaltliche Daten (Events, Sitzungen,
 * Rückmeldungen, Berichte, Abwesenheiten) bleiben unberührt. Gibt [bereich => anzahl] zurück.
 */
function cleanup_old_data(int $days = 90): array
{
    $cut = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $d = db();
    $del = function (string $sql, array $p = []) use ($d) { $st = $d->prepare($sql); $st->execute($p); return $st->rowCount(); };
    return [
        'messages'     => $del('DELETE FROM dashboard_messages WHERE created_at < ?', [$cut]),
        'reminder_log' => $del('DELETE FROM reminder_log WHERE sent_on < ?', [$cut])
                        + $del('DELETE FROM umlauf_reminder_log WHERE sent_on < ?', [$cut])
                        + $del('DELETE FROM poll_reminder_log WHERE sent_on < ?', [$cut]),
        'report_rem'   => $del('DELETE FROM report_reminders WHERE sent_on < ?', [$cut]),
        'voteitem_rem' => $del('DELETE FROM voteitem_reminders WHERE sent_on < ?', [$cut]),
        'shift_rem'    => $del('DELETE FROM shift_reminders WHERE sent_at < ?', [$cut]),
        'tokens'       => $del("DELETE FROM remember_tokens WHERE expires_at < datetime('now','localtime')")
                        + $del("DELETE FROM login_tokens WHERE expires_at < datetime('now','localtime')")
                        + $del("DELETE FROM stepup_tokens WHERE expires_at < datetime('now','localtime')"),
    ];
}

/**
 * Verschickt fällige Erinnerungen für ein Event an Mitglieder ohne Abstimmung.
 * Sendet höchstens einmal pro Tag und Person. Gibt eine Liste der Empfänger zurück.
 *
 * @param bool $force  true = unabhängig vom Vorlauf-Fenster senden (Admin-Button)
 * @return array{sent:string[], skipped:int}
 */
function send_event_reminders(array $event, bool $force = false, bool $dryRun = false): array
{
    $eventId = (int)$event['id'];
    $today = date('Y-m-d');
    $sent = [];
    $skipped = 0;

    if (event_locked($event)) return ['sent' => $sent, 'skipped' => 0];

    $deadlineTxt = $event['deadline'] ? fmt_date($event['deadline']) : '—';
    $url = app_url('event.php?id=' . $eventId);
    $daysLeft = $event['deadline'] ? days_until_d((string)$event['deadline']) : null; // für das persönliche Fenster

    foreach (members_without_vote($eventId) as $m) {
        // persönliches Erinnerungs-Fenster: erst ab X Tagen vor der Frist (Mitteilungs-Register)
        if (!$force && $daysLeft !== null && $daysLeft > notify_pref((int)$m['id'], 'vote_open')['timing']) { $skipped++; continue; }
        // schon heute erinnert? (beim Admin-Button mit $force übersprungen)
        if (!$force) {
            $chk = db()->prepare('SELECT 1 FROM reminder_log WHERE event_id=? AND member_id=? AND sent_on=?');
            $chk->execute([$eventId, (int)$m['id'], $today]);
            if ($chk->fetchColumn()) { $skipped++; continue; }
        }
        if ($dryRun) { $sent[] = $m['name']; continue; } // nur sammeln, nichts senden/loggen

        $fristHinweis = $event['deadline'] ? 'Bitte trag dich bis zum ' . $deadlineTxt . ' ein.' : 'Bitte trag dich ein, wann du Zeit hast.';
        if (notify_deliver((int)$m['id'], 'vote_open', fn() => mail_tpl_send('vote_reminder', member_mail($m), [
                '{{VORNAME}}' => first_name($m['name']),
                '{{EVENT}}' => (string)$event['title'],
                '{{FRISTHINWEIS}}' => $fristHinweis,
                '{{LINK}}' => $url,
            ]), 'Abstimmung offen: ' . (string)$event['title'], $fristHinweis, $url)) {
            db()->prepare('INSERT OR IGNORE INTO reminder_log(event_id, member_id, sent_on) VALUES(?,?,?)')
               ->execute([$eventId, (int)$m['id'], $today]);
            $sent[] = $m['name'] . ' <' . $m['email'] . '>';
        }
    }
    return ['sent' => $sent, 'skipped' => $skipped];
}

// ---------------------------------------------------------------------------
// Mitteilungs-Register: alle Benachrichtigungs-Typen deklarativ an einem Ort.
// Je Typ: Gruppe, Label, Beschreibung, Kanäle und optional wählbarer Vorlauf (timing, in Tagen).
// mail-Modi:
//   forced   – Pflicht, nicht abschaltbar (aktuell von keinem Typ genutzt, bleibt für echte Grundpflichten)
//   push_opt – Pflicht, ABER abschaltbar, solange die Person diesen Typ per Push auf einem
//              angemeldeten Gerät bekommt. Fällt der Push weg, ist die Mail sofort wieder an.
//   opt      – frei wählbar; sender – entscheidet die absendende Person; none – gibt es nicht
// push: an/aus wählbar (push_default => false macht einen Typ zum Opt-in).
// Persönliche Abweichungen vom Standard liegen in notify_prefs; eine neue
// Mitteilung anzulegen heißt: hier eine Zeile ergänzen + notify_pref() nutzen.
// ---------------------------------------------------------------------------
function notify_types(): array
{
    return [
        // --- Events & Schichten ---
        'vote_open' => ['group' => 'events', 'label' => 'Abstimmung offen', 'icon' => 'ti-checklist',
            'desc' => 'Solange du bei einem Event mit Frist noch nicht abgestimmt hast (täglich, ab Beginn deines Fensters).',
            'mail' => 'push_opt', 'push' => true,
            'timing' => [1, 2, 3, 5, 7], 'timing_default' => 0, 'timing_label' => 'Tage vor der Frist'], // 0 = globales Setting
        'maybe_deadline' => ['group' => 'events', 'label' => '„Vielleicht"-Frist', 'icon' => 'ti-question-mark',
            'desc' => 'Am Tag vor Fristende, wenn du noch auf „Vielleicht" stehst (wird sonst zu „Nein").',
            'mail' => 'push_opt', 'push' => true],
        'shift_reminder' => ['group' => 'events', 'label' => 'Einsatz-Erinnerung', 'icon' => 'ti-clock-bolt',
            'desc' => 'Vor jeder eingeteilten Schicht.',
            'mail' => 'opt', 'push' => true, 'master' => 'shift',
            'timing' => [1, 2, 3, 7], 'timing_default' => 1, 'timing_label' => 'Tage vorher'], // timing liegt in members.reminder_lead_days
        'assignment' => ['group' => 'events', 'label' => 'Ein-/Ausplanung', 'icon' => 'ti-calendar-user',
            'desc' => 'Sobald du zu einem Termin eingeplant oder wieder ausgeplant wirst.',
            'mail' => 'opt', 'push' => true, 'master' => 'assign'],
        'gt_new' => ['group' => 'events', 'label' => 'Neues Get-Together', 'icon' => 'ti-confetti',
            'desc' => 'Sobald ein Get-Together angelegt wird – mit der Bitte, kurz zu- oder abzusagen. Mitkommen bleibt freiwillig.',
            'mail' => 'opt', 'push' => true],
        // --- Sitzungen ---
        'report_due' => ['group' => 'meetings', 'label' => 'Bericht fällig', 'icon' => 'ti-file-text',
            'desc' => 'Wenn vor einer berichtspflichtigen Sitzung noch kein Bericht deines Referats steht.',
            'mail' => 'push_opt', 'push' => true,
            'timing' => [1, 2, 3], 'timing_default' => 1, 'timing_label' => 'Tage vor der Sitzung'],
        'voteitems_unread' => ['group' => 'meetings', 'label' => 'Abstimmungsgegenstände lesen', 'icon' => 'ti-eyeglass',
            'desc' => 'Wenn du vor einer Sitzung noch ungelesene Abstimmungsgegenstände hast.',
            'mail' => 'push_opt', 'push' => true,
            'timing' => [1, 2, 3, 5, 7], 'timing_default' => 2, 'timing_label' => 'Tage vor der Sitzung'],
        'umlauf_new' => ['group' => 'meetings', 'label' => 'Neuer Umlaufbeschluss', 'icon' => 'ti-mailbox',
            'desc' => 'Sobald jemand ein Umlaufverfahren startet (Abstimmen ist verpflichtend).',
            'mail' => 'push_opt', 'push' => true],
        'poll_new' => ['group' => 'meetings', 'label' => 'Neue Abstimmung', 'icon' => 'ti-list-check',
            'desc' => 'Sobald jemand eine normale Abstimmung/Umfrage startet.',
            'mail' => 'opt', 'push' => true],
        'umlauf_reminder' => ['group' => 'meetings', 'label' => 'Abstimm-Erinnerung', 'icon' => 'ti-alarm',
            'desc' => 'Solange du bei einem Umlaufverfahren oder einer verpflichtenden Abstimmung noch nicht abgestimmt hast (täglich, ab Beginn deines Fensters).',
            'mail' => 'push_opt', 'push' => true,
            'timing' => [1, 2, 3, 5], 'timing_default' => 2, 'timing_label' => 'Tage vor der Frist'],
        'umlauf_result' => ['group' => 'meetings', 'label' => 'Abstimm-Ergebnis', 'icon' => 'ti-checkbox',
            'desc' => 'Sobald ein Umlaufverfahren oder eine verpflichtende Abstimmung abgeschlossen ist – Ergebnis samt Zählung.',
            'mail' => 'opt', 'push' => true],
        // --- Nachrichten & Börse (Dashboard-Nachrichten; Mail bestimmt ggf. die absendende Person) ---
        'swap_offer' => ['group' => 'messages', 'label' => 'Börse: Tausch-Vorschlag per Mail', 'icon' => 'ti-mail-fast',
            'desc' => 'Zusätzliche Mail, wenn dir jemand einen Tausch vorschlägt (der Push dazu läuft über „Schicht- & Tauschbörse").',
            'mail' => 'opt', 'push' => false],
        'dm_vorsitz' => ['group' => 'messages', 'label' => 'Nachrichten & Ankündigungen', 'icon' => 'ti-message',
            'desc' => 'Persönliche Nachrichten und Ankündigungen (z. B. von Vorsitz oder Orga).',
            'mail' => 'sender', 'push' => true],
        'dm_boerse' => ['group' => 'messages', 'label' => 'Schicht- & Tauschbörse', 'icon' => 'ti-arrows-exchange',
            'desc' => 'Vorschläge, Übernahmen und abgeschlossene Tausche.',
            'mail' => 'none', 'push' => true],
        'dm_plan' => ['group' => 'messages', 'label' => 'Arbeitsplan', 'icon' => 'ti-calendar-cog',
            'desc' => 'Wenn die Orga deine Einteilung anfasst (z. B. Streichung aus einer Schicht).',
            'mail' => 'none', 'push' => true],
        'dm_bugs' => ['group' => 'messages', 'label' => 'Bug-Reports', 'icon' => 'ti-bug',
            'desc' => 'Antworten und Status-Änderungen zu Fehlermeldungen.',
            'mail' => 'none', 'push' => true],
        'dm_feedback' => ['group' => 'messages', 'label' => 'Feedback', 'icon' => 'ti-message-2',
            'desc' => 'Antworten und Bearbeitungsstände zu deinen Rückmeldungen (bzw. neue Rückmeldungen, wenn du Technik machst).',
            'mail' => 'none', 'push' => true],
        'dm_score' => ['group' => 'messages', 'label' => 'Score', 'icon' => 'ti-trophy',
            'desc' => 'Glückwünsche und Hinweise rund um Event- und Basis-Score.',
            'mail' => 'none', 'push' => true],
        'dm_sitzung' => ['group' => 'messages', 'label' => 'Sitzungs-Teilnahme', 'icon' => 'ti-door-exit',
            'desc' => 'Ab-/Rückmeldungen zu Sitzungen (bekommt vor allem der Vorsitz).',
            'mail' => 'none', 'push' => true],
        'dm_finanzen' => ['group' => 'messages', 'label' => 'Finanzen & Auslagen', 'icon' => 'ti-coins',
            'desc' => 'Rückfragen und Antworten zu deinen Belegblättern (bzw. neue Nachrichten, wenn du Finanzen machst).',
            'mail' => 'none', 'push' => true],
        'dm_profil' => ['group' => 'messages', 'label' => 'Profil-Pinnwand', 'icon' => 'ti-pin',
            'desc' => 'Wenn dir jemand etwas Nettes auf deine Profil-Pinnwand schreibt.',
            'mail' => 'none', 'push' => true],
        // NUR Admin-Rolle (Register blendet den Eintrag für alle anderen aus – 'role'-Gate)
        'dm_pin_watch' => ['group' => 'messages', 'label' => 'Pinnwand-Wache (alle Profile)', 'icon' => 'ti-eye-check',
            'desc' => 'Push, sobald irgendwo auf eine Profil-Pinnwand geschrieben wird (auch auf fremde) – zum Mitlesen als Admin. Der Push führt direkt zur Pinnwand der betroffenen Person. Anonyme Einträge bleiben anonym. Standardmäßig aus.',
            'mail' => 'none', 'push' => true, 'push_default' => false, 'role' => 'admin'],
        'dm_waslaeuft' => ['group' => 'messages', 'label' => 'was.läuft: Neues zu tun', 'icon' => 'ti-calendar-star',
            'desc' => 'Wenn im Veranstaltungsportal eine Einreichung wartet oder ein Beitrag gemeldet wurde. '
                    . 'Bekommen nur die Referate, die als zuständig eingetragen sind.',
            'mail' => 'opt', 'push' => true],
        'dm_kudos' => ['group' => 'messages', 'label' => 'Props 🙌', 'icon' => 'ti-hand-love-you',
            'desc' => 'Push, wenn dir jemand (anonym) Props aus dem Mitglieder-Tab gibt. Ansonsten siehst du es als Toast beim nächsten Öffnen. Standardmäßig aus.',
            'mail' => 'none', 'push' => true, 'push_default' => false], // Opt-in: Push verfügbar, aber ab Werk AUS
        // --- App & Basis-Score ---
        'app_inactive' => ['group' => 'app', 'label' => 'Inaktivitäts-Erinnerung', 'icon' => 'ti-zzz',
            'desc' => 'Push, wenn du die App mehrere Tage nicht geöffnet hast – bevor dir die Woche als „App nicht geöffnet" in den Basis-Score zählt. Standardmäßig aus.',
            'mail' => 'none', 'push' => true, 'push_default' => false, // Opt-in: Push verfügbar, aber ab Werk AUS
            'timing' => [3, 4, 5, 6], 'timing_default' => 5, 'timing_label' => 'Tage ohne App-Öffnung'],
    ];
}

/** Gruppen-Reihenfolge + Labels für die Einstellungs-Seite. */
function notify_groups(): array
{
    return ['events' => 'Events & Schichten', 'meetings' => 'Sitzungen', 'messages' => 'Nachrichten & Börse', 'app' => 'App & Basis-Score'];
}

/**
 * Zustellung einer Pflicht-Erinnerung („push_opt"): Push raus – und die Mail entweder, weil die
 * Person sie behalten hat, ODER weil der Push auf KEINEM Gerät angekommen ist.
 *
 * Das schließt die einzige Lücke des Modells: Ein Abo kann still ablaufen (Gerät zurückgesetzt,
 * App gelöscht). push_send() räumt solche Abos erst beim Sendeversuch weg – ohne dieses Netz
 * wäre genau die Erinnerung verloren, bei der das auffällt. Rückgabe: wurde zugestellt?
 * ($mail liefert das Ergebnis des Mailversands, wird nur bei Bedarf aufgerufen.)
 */
function notify_deliver(int $memberId, string $type, callable $mail, string $pushTitle, string $pushBody, string $pushUrl = ''): bool
{
    $pref = notify_pref($memberId, $type);
    if ($pref['mail']) {
        // Mail behalten (Normalfall): erst die Mail, Push nur obendrauf.
        // Wichtig für die Reihenfolge: Scheitert die Mail, wird NICHT geloggt und der nächste Lauf
        // versucht es erneut – dann darf auch noch kein Push draußen sein, sonst kommt er doppelt.
        if (!$mail()) return false;
        if ($pref['push']) push_send($memberId, $pushTitle, $pushBody, $pushUrl);
        return true;
    }
    // Mail abgewählt: Push ist der Kanal – kommt er auf KEINEM Gerät an, geht die Mail doch raus.
    // (Nur bei Typen, die überhaupt eine Mail kennen – sonst gibt es nichts aufzufangen.)
    $pushed = $pref['push'] ? push_send($memberId, $pushTitle, $pushBody, $pushUrl) : 0;
    if ($pushed > 0) return true;
    return (notify_types()[$type]['mail'] ?? 'none') === 'none' ? false : (bool)$mail();
}

/** Alle gespeicherten Abweichungen, einmal pro Request geladen (member_id => type => row). */
function notify_prefs_cache(bool $reset = false): array
{
    static $cache = null;
    if ($reset) { $cache = null; return []; }
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM notify_prefs')->fetchAll() as $r) {
            $cache[(int)$r['member_id']][(string)$r['type']] = $r;
        }
    }
    return $cache;
}

/**
 * Effektive Einstellung einer Person für einen Mitteilungs-Typ.
 * Rückgabe: ['mail' => bool, 'push' => bool, 'timing' => int]
 * – forced-/sender-Mail ist immer true, nicht verfügbare Kanäle immer false.
 */
function notify_pref(int $memberId, string $type): array
{
    $def = notify_types()[$type] ?? null;
    if (!$def) return ['mail' => false, 'push' => false, 'timing' => 0];
    $row = notify_prefs_cache()[$memberId][$type] ?? [];
    // Push zuerst: „push_opt" (Pflicht-Mail, die sich per Push ersetzen lässt) hängt davon ab.
    // Ohne gespeicherte Abweichung gilt push_default (fehlt es: an) – so gibt es Opt-in-Typen, die ab Werk aus sind
    $pushDefault = !array_key_exists('push_default', $def) || !empty($def['push_default']);
    $push = !empty($def['push']) && (!isset($row['push']) || $row['push'] === null ? $pushDefault : (int)$row['push'] === 1);
    $mailOff = isset($row['mail']) && $row['mail'] !== null && (int)$row['mail'] === 0;
    $mail = match ($def['mail']) {
        'forced', 'sender' => true,
        'opt'              => !$mailOff,
        // Grundsätzlich Pflicht – abschaltbar NUR, solange die Person diesen Typ per Push bekommt
        // UND wirklich ein Gerät angemeldet hat. Fällt eines davon weg (Push aus, letztes Gerät
        // abgemeldet, Abo vom Push-Dienst verworfen), ist die Mail sofort wieder an. Niemand
        // verliert die Erinnerung dadurch, dass ein Kanal still verstummt.
        'push_opt'         => !($mailOff && $push && has_push_device($memberId)),
        default            => false,
    };
    $timingDefault = (int)($def['timing_default'] ?? 0);
    if ($type === 'vote_open' && $timingDefault === 0) $timingDefault = max(1, (int)setting_get('reminder_lead_days', '3'));
    $timing = isset($row['timing']) && $row['timing'] !== null ? (int)$row['timing'] : $timingDefault;
    if (!empty($def['timing']) && !in_array($timing, $def['timing'], true)) $timing = $timingDefault;
    return ['mail' => $mail, 'push' => $push, 'timing' => $timing];
}

/** Speichert eine Abweichung (field: mail|push|timing); validiert gegen das Register. */
function notify_pref_set(int $memberId, string $type, string $field, int $value): bool
{
    $def = notify_types()[$type] ?? null;
    if (!$def || $memberId <= 0) return false;
    // Rollen-gebundene Typen (z. B. Pinnwand-Wache = nur Admin) dürfen andere gar nicht setzen
    if (!empty($def['role']) && (string)(member_get($memberId)['role'] ?? '') !== $def['role']) return false;
    // Absender-/Nicht-Mail-Typen sind nie umstellbar. „push_opt" nur, wenn die Person auch
    // wirklich ein Push-Gerät hat – sonst könnte man sich hier lautlos abmelden.
    if ($field === 'mail' && !in_array($def['mail'], ['opt', 'push_opt'], true)) return false;
    if ($field === 'mail' && $def['mail'] === 'push_opt' && $value === 0 && !has_push_device($memberId)) return false;
    if ($field === 'push' && empty($def['push'])) return false;
    if ($field === 'timing' && (empty($def['timing']) || !in_array($value, $def['timing'], true))) return false;
    if (!in_array($field, ['mail', 'push', 'timing'], true)) return false;
    db()->prepare("INSERT INTO notify_prefs(member_id, type, $field) VALUES(?,?,?)
                   ON CONFLICT(member_id, type) DO UPDATE SET $field = excluded.$field")
        ->execute([$memberId, $type, $value]);
    notify_prefs_cache(true);
    return true;
}

/** Setzt alle Mitteilungs-Abweichungen einer Person zurück auf Standard. */
function notify_prefs_reset(int $memberId): void
{
    db()->prepare('DELETE FROM notify_prefs WHERE member_id = ?')->execute([$memberId]);
    notify_prefs_cache(true);
}

// ---------------------------------------------------------------------------
// Web Push (PWA-Benachrichtigungen)
// Die Mathematik (VAPID/ES256, aes128gcm) liegt seit dem was.läuft-Push im
// GEMEINSAMEN Kern push-core.php – wie beim dav_*-Kern teilen sich App und
// öffentlicher Bereich den Code, aber NICHT die Schlüssel und Abos: Hier stehen
// nur noch die App-Seiten davon (Speicherung in settings/push_subscriptions).
// ---------------------------------------------------------------------------

require_once __DIR__ . '/push-core.php';

/** VAPID-Schlüsselpaar der App – wird beim ersten Bedarf erzeugt und in settings abgelegt. */
function push_vapid(): ?array
{
    if (!push_available()) return null;
    $pem = setting_get('push_vapid_pem');
    $pub = setting_get('push_vapid_pub');
    if (!$pem || !$pub) {
        $paar = push_core_keypair();
        if (!$paar) return null;
        [$pem, $pub] = [$paar['pem'], $paar['pub']];
        setting_set('push_vapid_pem', $pem);
        setting_set('push_vapid_pub', $pub);
    }
    return ['pem' => $pem, 'pub' => $pub, 'sub' => 'mailto:' . org_kontakt_mail()];
}

/** Abos einer Person (ein Eintrag je Gerät/Browser). */
function push_subscriptions_for(int $memberId): array
{
    $st = db()->prepare('SELECT * FROM push_subscriptions WHERE member_id = ?');
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/**
 * Hat die Person mindestens ein angemeldetes Push-Gerät? Einmal pro Request geladen –
 * notify_pref() fragt das in Crons für JEDES Mitglied ab, eine Abfrage pro Aufruf wäre teuer.
 * $reset leert den Zwischenspeicher (nach An-/Abmelden eines Geräts im selben Request).
 */
function has_push_device(int $memberId, bool $reset = false): bool
{
    static $ids = null;
    if ($reset) { $ids = null; return false; }
    if ($ids === null) {
        $ids = [];
        foreach (db()->query('SELECT DISTINCT member_id FROM push_subscriptions')->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            $ids[(int)$mid] = true;
        }
    }
    return $memberId > 0 && isset($ids[$memberId]);
}

/** Speichert/erneuert ein Abo (Endpoint ist eindeutig; Kontowechsel am Gerät übernimmt es). */
function push_subscription_save(int $memberId, string $endpoint, string $p256dh, string $auth): bool
{
    if ($memberId <= 0 || !str_starts_with($endpoint, 'https://') || strlen($endpoint) > 1000) return false;
    if (strlen(b64u_decode($p256dh)) !== 65 || strlen(b64u_decode($auth)) !== 16) return false;
    db()->prepare('INSERT INTO push_subscriptions(member_id, endpoint, p256dh, auth) VALUES(?,?,?,?)
                   ON CONFLICT(endpoint) DO UPDATE SET member_id = excluded.member_id,
                       p256dh = excluded.p256dh, auth = excluded.auth')
        ->execute([$memberId, $endpoint, $p256dh, $auth]);
    has_push_device(0, true); // Zwischenspeicher leeren: ab jetzt gilt „hat Push-Gerät"
    return true;
}

function push_subscription_delete(string $endpoint, ?int $memberId = null): void
{
    if ($memberId !== null) {
        db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND member_id = ?')->execute([$endpoint, $memberId]);
    } else {
        db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$endpoint]);
    }
    has_push_device(0, true); // war es das letzte Gerät, greift sofort wieder die Pflicht-Mail
}

/** POSTet eine fertige JSON-Payload verschlüsselt an EIN Abo. Rückgabe: HTTP-Code (0 = Transportfehler). */
function push_send_to(array $sub, string $json): int
{
    $vapid = push_vapid();
    if (!$vapid) return 0;
    return push_core_deliver($sub, $json, $vapid);
}

/**
 * Push an alle Geräte einer Person – zusätzlich zu Mail/Dashboard, niemals stattdessen.
 * Fehler brechen den Aufrufer nie (try/catch); abgelaufene Abos (404/410) entsorgen sich
 * dabei selbst. Rückgabe: Anzahl erfolgreich angenommener Nachrichten.
 */
function push_send(int $memberId, string $title, string $body, string $url = ''): int
{
    $ok = 0;
    try {
        if ($memberId <= 0 || !push_available()) return 0;
        $subs = push_subscriptions_for($memberId);
        if (!$subs) return 0;
        if (mb_strlen($body) > 180) $body = mb_substr($body, 0, 177) . '…';
        $json = (string)json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url !== '' ? $url : app_url('dashboard.php'),
        ], JSON_UNESCAPED_UNICODE);
        foreach ($subs as $s) {
            $code = push_send_to($s, $json);
            if ($code === 404 || $code === 410) { push_subscription_delete((string)$s['endpoint']); continue; }
            if ($code >= 200 && $code < 300) $ok++;
        }
    } catch (\Throwable $e) {
        error_log('push_send fehlgeschlagen: ' . $e->getMessage());
    }
    return $ok;
}

// ---------------------------------------------------------------------------
// Dienstplan-Tabelle: Schichten als Zeilen (Y), Uhrzeiten als Spalten (X),
// Namen in den Zellen (als Balken über die Schicht-Dauer). $cell(slot)->HTML.
// ---------------------------------------------------------------------------
function shift_roster_html(array $slots, callable $cell): string
{
    if (!$slots) return '';
    $slots = slots_grouped($slots); // chronologisch, gleichnamige Schichten zusammen

    $minH = 24; $maxH = 0; $hasTimed = false;
    $endHour = function (array $s, DateTime $st): int {
        $e = dt($s['ends_at']);
        if (!$e) return (int)$st->format('G') + 1;
        if ($e->format('Y-m-d') !== $st->format('Y-m-d')) return 24; // über Mitternacht → Tagesende
        return (int)$e->format('G') + ((int)$e->format('i') > 0 ? 1 : 0);
    };
    foreach ($slots as $s) {
        $st = dt($s['starts_at']);
        if (!$st || $st->format('H:i') === '00:00') continue;
        $hasTimed = true;
        $minH = min($minH, (int)$st->format('G'));
        $maxH = max($maxH, $endHour($s, $st));
    }
    if (!$hasTimed) { $minH = 8; $maxH = 18; }
    $minH = max(0, $minH); $maxH = min(24, $maxH); if ($maxH <= $minH) $maxH = $minH + 1;
    $cols = $maxH - $minH;

    $multiDay = count(array_unique(array_map(fn($s) => substr((string)$s['starts_at'], 0, 10), $slots))) > 1;

    ob_start();
    ?>
    <div class="roster-wrap"><table class="roster">
      <thead><tr><th class="r-label">Schicht</th>
        <?php for ($h = $minH; $h < $maxH; $h++): ?><th class="r-hour"><?= $h ?></th><?php endfor; ?>
      </tr></thead>
      <tbody>
      <?php $lastDay = null; foreach ($slots as $s):
          $day = substr((string)$s['starts_at'], 0, 10);
          if ($multiDay && $day !== $lastDay): $lastDay = $day; ?>
            <tr class="day-row"><td colspan="<?= $cols + 1 ?>"><?= h(fmt_event_range($day, null)) ?></td></tr>
          <?php endif;
          $st = dt($s['starts_at']);
          $timed = $st && $st->format('H:i') !== '00:00';
          if ($timed) { $sh = (int)$st->format('G'); $eh = $endHour($s, $st); }
          else        { $sh = $minH; $eh = $maxH; } // ganztägig → ganze Breite
          $sh = max($minH, min($sh, $maxH - 1)); $eh = max($sh + 1, min($eh, $maxH));
          $pre = $sh - $minH; $span = $eh - $sh; $post = $maxH - $eh;
          $eObj = dt($s['ends_at']);
          $timeStr = $timed ? $st->format('H:i') . ($eObj ? '–' . $eObj->format('H:i') : '') : 'ganztägig';
      ?>
        <tr>
          <td class="r-label"><strong><?= h($s['label'] ?: 'Schicht') ?></strong><span class="r-time"><?= h($timeStr) ?></span></td>
          <?php for ($i = 0; $i < $pre; $i++): ?><td class="r-empty"></td><?php endfor; ?>
          <td class="r-bar" colspan="<?= $span ?>"><?= $cell($s) ?></td>
          <?php for ($i = 0; $i < $post; $i++): ?><td class="r-empty"></td><?php endfor; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Markdown (kleiner Renderer für die In-App-Anleitung – Teilmenge: Überschriften,
// Listen, **fett**, `code`, Code-Blöcke, Links, Zitate, Trennlinien)
// ---------------------------------------------------------------------------
function md_inline(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace_callback('/`([^`]+)`/', fn($m) => '<code>' . $m[1] . '</code>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = $m[2];
        // Erlaubt: externe Adressen, absolute Pfade und App-Seiten (auch „admin/x.php")
        if (!preg_match('#^(https?:|mailto:|/|\.|[\w.-]+(?:/[\w.-]+)*\.php)#', $url)) return $m[0];
        $extern = (bool)preg_match('#^(https?:|mailto:)#i', $url);
        // Interne Seitenlinks relativ zur AUFRUFENDEN Seite auflösen: dieselbe Anleitung wird
        // im Wurzelverzeichnis (info.php) und eine Ebene tiefer (admin/help.php) gerendert.
        if (!$extern && $url[0] !== '/' && $url[0] !== '.') $url = base() . $url;
        // Interne Links bleiben im selben Tab – ein neuer Tab würde die installierte App verlassen.
        return '<a href="' . $url . '"' . ($extern ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
    }, $s);
    return $s;
}

function render_markdown(string $md): string
{
    $lines = explode("\n", str_replace("\r\n", "\n", $md));
    $html = ''; $para = []; $inUl = false; $inOl = false; $inCode = false;
    $flushPara = function () use (&$para, &$html) {
        if ($para) { $html .= '<p>' . md_inline(implode(' ', $para)) . '</p>'; $para = []; }
    };
    $closeLists = function () use (&$inUl, &$inOl, &$html) {
        if ($inUl) { $html .= '</ul>'; $inUl = false; }
        if ($inOl) { $html .= '</ol>'; $inOl = false; }
    };
    foreach ($lines as $line) {
        if (preg_match('/^```/', trim($line))) {
            if ($inCode) { $html .= '</code></pre>'; $inCode = false; }
            else { $flushPara(); $closeLists(); $html .= '<pre><code>'; $inCode = true; }
            continue;
        }
        if ($inCode) { $html .= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n"; continue; }
        $t = trim($line);
        if ($t === '') { $flushPara(); $closeLists(); continue; }
        if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
            $flushPara(); $closeLists();
            $lvl = min(6, strlen($m[1]) + 1); // # → h2 (Seite hat schon ein h1)
            $html .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>";
        } elseif (preg_match('/^(-{3,}|\*{3,})$/', $t)) {
            $flushPara(); $closeLists(); $html .= '<hr>';
        } elseif (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $flushPara(); $closeLists(); $html .= '<blockquote>' . md_inline($m[1]) . '</blockquote>';
        } elseif (preg_match('/^\d+\.\s+(.*)$/', $t, $m)) {
            $flushPara(); if ($inUl) { $html .= '</ul>'; $inUl = false; }
            if (!$inOl) { $html .= '<ol>'; $inOl = true; }
            $html .= '<li>' . md_inline($m[1]) . '</li>';
        } elseif (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            $flushPara(); if ($inOl) { $html .= '</ol>'; $inOl = false; }
            if (!$inUl) { $html .= '<ul>'; $inUl = true; }
            $html .= '<li>' . md_inline($m[1]) . '</li>';
        } else {
            $para[] = $t;
        }
    }
    if ($inCode) $html .= '</code></pre>';
    $flushPara(); $closeLists();
    return $html;
}

// ---------------------------------------------------------------------------
// Layout
// ---------------------------------------------------------------------------
function page_header(string $title, bool $admin = false): void
{
    // Doppelt gemoppelt mit Absicht: Das meta-Element im Kopf greift bei HTML, die Kopfzeile
    // auch bei allem anderen, was diese Funktion umgibt. headers_sent() als Bremse, falls eine
    // Seite schon etwas ausgegeben hat.
    if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow');
    $b = base();
    // Props-Toast beim nächsten Öffnen: ungesehene erhaltene Props → freundlicher Toast (markiert sie zugleich als gesehen).
    if (($kMe = current_member())) {
        $kN = kudos_take_unseen((int)$kMe['id']);
        if ($kN > 0) flash($kN === 1
            ? 'Du hast Props bekommen 🙌 — jemand schätzt deine Arbeit!'
            : 'Du hast ' . $kN . ' neue Props bekommen 🙌 — schön, dass deine Arbeit gesehen wird!', 'success');
    }
    $flashes = take_flash();
    // Feier-Moment der vorigen Aktion einsammeln (überlebt genau EINE Weiterleitung). Muss hier
    // oben stehen: Die Seiten fragen ihn danach über puste_klasse() ab, um ihr Ziel zu markieren.
    $GLOBALS['ASTA_PUSTE'] = (string)($_SESSION['puste'] ?? '');
    unset($_SESSION['puste']);
    // Skins zentral aus der Registry: freigeschaltete werden in Glühbirne, Menü, „Mehr"-Sheet und Bootstrap-Script gerendert
    $skinsAll = app_skins();
    $skinsOn  = [];
    foreach ($skinsAll as $sk => $sd) { if (skin_unlocked($sk)) $skinsOn[$sk] = $sd; }
    $extraSkins = !empty($skinsOn); // ab 4 Modi: Direktwahl-Menü statt Durchklicken
    $skinsJs = [];
    foreach ($skinsAll as $sk => $sd) $skinsJs[$sk] = ['base' => $sd['base'], 'tc' => $sd['tc'], 'on' => isset($skinsOn[$sk])];
    $skinTitle = implode('', array_map(fn($sd) => ' / ' . $sd['label'] . ' ' . $sd['emoji'], $skinsOn));
    $skinAria  = implode('', array_map(fn($sd) => ', ' . $sd['label'], $skinsOn));
    $lbKey = lb_chip_info()['key'];
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover"><!-- viewport-fit=cover: sonst liefert env(safe-area-inset-*) immer 0 und die Tab-Leiste klebt im Home-Gesten-Bereich; user-scalable=no: App-Gefühl ohne Pinch-Zoom (greift v. a. in der installierten PWA, im Safari-Tab bleibt Zoom aus Accessibility-Gründen möglich) -->
<meta name="theme-color" content="#ffffff"><!-- wird vom Theme-Bootstrap direkt passend gesetzt (hell/dunkel/royal) -->
<script>(function(){try{var SK=<?= json_encode($skinsJs, JSON_UNESCAPED_SLASHES) ?>;window.ASTA_SKINS=SK;var m=localStorage.getItem('asta-theme-mode')||localStorage.getItem('asta-theme')||'system';if(SK[m]&&!SK[m].on)m='dark';if(m!=='light'&&m!=='dark'&&m!=='system'&&!SK[m])m='system';var d=document.documentElement;d.classList.add('js');d.setAttribute('data-theme-mode',m);if(SK[m]){d.setAttribute('data-theme',SK[m].base);d.setAttribute('data-skin',m);}else{d.removeAttribute('data-skin');d.setAttribute('data-theme',(m==='system')?((window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light'):m);}var sk=d.getAttribute('data-skin');var tc=(sk&&SK[sk])?SK[sk].tc:(d.getAttribute('data-theme')==='dark'?'#111518':'#ffffff');var mt=document.querySelector('meta[name="theme-color"]');if(mt)mt.setAttribute('content',tc);}catch(e){}})();</script>
<?php if ($lbKey !== ''): ?>
<script>(function(){try{if(localStorage.getItem('asta-lb-tuck')===<?= json_encode($lbKey) ?>)document.documentElement.setAttribute('data-lb-tuck','1');}catch(e){}})();</script><!-- vor dem ersten Paint: sonst blitzt der weggeklickte Hinweis kurz auf -->
<?php endif; ?>
<?php /* Die App gehoert in keinen Suchindex. Nach aussen sichtbar ist ohnehin nur die
         Anmeldeseite – und „AStA-Planer: Anmelden" als Google-Treffer nuetzt niemandem und
         verrät nur, dass es das gibt. Die oeffentlichen Bereiche (was.laeuft, Terminplaner,
         Umfragen, Pat:innenprogramm, Anmeldungen) haben eigene Koepfe und sind davon nicht
         betroffen; sie entscheiden selbst, was indexiert wird.
         BEWUSST nicht ueber die robots.txt gesperrt: Wer nicht crawlen darf, liest dieses
         noindex nie – und eine verlinkte Adresse landet dann trotzdem im Index. */ ?>
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
<!-- Fonts vorladen: sonst poppen Icons/Headlines nach dem ersten Paint rein und die Seite „wackelt" -->
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= $b ?>assets/tabler/fonts/tabler-icons.woff2?v3.31.0">
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= $b ?>assets/fonts/outfit-latin.woff2">
<link rel="stylesheet" href="<?= $b . asset_v('assets/flatpickr/flatpickr.min.css') ?>">
<link rel="stylesheet" href="<?= $b . asset_v('assets/tabler/tabler-icons.min.css') ?>">
<link rel="stylesheet" href="<?= $b . asset_v('assets/style.css') ?>">
<link rel="icon" type="image/png" href="<?= $b . brand_url('logo-header') ?>"><!-- transparentes Logo als Browser-Favicon -->
<link rel="manifest" href="<?= $b ?>manifest.php">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h(APP_NAME) ?>">
<link rel="apple-touch-icon" href="<?= $b . brand_url('icon-180') ?>">
<?php /* Kopf-Logo als Data-URI IN DIE SEITE, nicht als eigene Anfrage: Es ist damit Teil des
         ERSTEN Bildes und kann nicht nachträglich aufpoppen (siehe .brand-logo-img in
         style.css – dort steht der Platzhalter, den diese Zeile überschreibt). */
      $markeCss = brand_data_uri('logo-app');
      if ($markeCss !== ''): ?>
<style>.brand-logo-img{background-image:url('<?= $markeCss ?>')}</style>
<?php endif; ?>
</head>
<?php $bodyClass = trim(($admin ? 'admin ' : '') . ($GLOBALS['ASTA_BODYCLASS'] ?? '')); ?>
<body<?= $bodyClass ? ' class="' . h($bodyClass) . '"' : '' ?>>
<header class="topbar">
  <div class="brand-wrap">
    <a class="brand" href="<?= $b ?><?= is_logged_in() ? 'dashboard.php' : 'login.php' ?>">
      <?php // Cute: das AStA-Logo trägt das eigene KOPF-Accessoire mit (nur eingeloggt & Kopf-Slot belegt)
            $brandDeco = '';
            if (($bdid = (int)($_SESSION['member_id'] ?? 0)) > 0) $brandDeco = member_equipped_decos($bdid)['head'] ?? ''; ?>
      <span class="brand-logo"><span class="brand-logo-img" role="img" aria-label="<?= h(org_name_kurz()) ?>"></span><?php if ($brandDeco !== ''): ?><span class="brand-acc" aria-hidden="true"><?= avatar_deco_svg($brandDeco) ?></span><?php endif; ?></span>
      <span>AStA-App</span>
    </a>
    <span class="theme-switch">
    <button type="button" class="bulb" onclick="astaThemeBulb(this)"<?php foreach ($skinsOn as $sk => $sd): ?> data-<?= $sk ?>="1"<?php endforeach; ?><?= $extraSkins ? ' aria-haspopup="true" aria-expanded="false"' : '' ?> aria-label="Design umschalten: System, Hell, Dunkel<?= h($skinAria) ?>" title="Design umschalten: System / Hell / Dunkel<?= h($skinTitle) ?>">
      <span class="mode-system"><i class="ti ti-bulb"></i></span>
      <span class="mode-light"><i class="ti ti-bulb"></i></span>
      <span class="mode-dark"><i class="ti ti-bulb-off"></i></span>
      <?php foreach ($skinsOn as $sk => $sd): ?><span class="mode-<?= $sk ?>"><i class="ti <?= $sd['icon'] ?>"></i></span><?php endforeach; ?>
      <span class="bulb-label"><?= $extraSkins ? 'Design ▾' : 'Design?' ?></span>
    </button>
    <?php if ($extraSkins): // ab 4 Modi: Direktwahl-Popover statt Durchklicken ?>
    <div class="theme-menu" role="menu" hidden>
      <button type="button" class="tm-item" data-mode="system" role="menuitem" onclick="astaPickTheme('system')"><i class="ti ti-bulb"></i> System</button>
      <button type="button" class="tm-item" data-mode="light" role="menuitem" onclick="astaPickTheme('light')"><i class="ti ti-bulb"></i> Hell</button>
      <button type="button" class="tm-item" data-mode="dark" role="menuitem" onclick="astaPickTheme('dark')"><i class="ti ti-bulb-off"></i> Dunkel</button>
      <?php foreach ($skinsOn as $sk => $sd): ?><button type="button" class="tm-item" data-mode="<?= $sk ?>" role="menuitem" onclick="astaPickTheme('<?= $sk ?>')"><i class="ti <?= $sd['icon'] ?>"></i> <?= h($sd['label']) ?></button><?php endforeach; ?>
    </div>
    <?php endif; ?>
    </span>
<?php // Eingeklappter Streak-Pausen-Hinweis: in der Titelleiste rechts neben dem Design-Umschalter.
          // Auf dem Handy blendet CSS diesen aus – dort sitzt er oben rechts im Hero (siehe dashboard.php). ?>
    <?= lb_chip_html('bar') ?>
  </div>
  <?php if (is_logged_in()): ?>
    <button type="button" class="nav-toggle" aria-label="Menü öffnen" aria-expanded="false" aria-controls="mainnav">
      <i class="ti ti-menu-2"></i>
    </button>
  <?php endif; ?>
  <?php
    // Menüpunkte jenseits der drei Haupt-Tabs – EINMAL definiert und sowohl in der
    // Topbar-Navigation als auch im „Mehr"-Bottom-Sheet der Tab-Leiste genutzt (kein Drift).
    $navMore = [];
    $cmNav = is_logged_in() ? current_member() : null;
    if (is_logged_in()) {
        if (can_manage_meetings()) {
            $navMore[] = ['admin/meetings.php', 'ti-gavel', 'Sitzungen'];
        } elseif ($cmNav && trim((string)($cmNav['referat'] ?? '')) !== '') {
            // Mitglieder (alle haben ein Referat): kommende Sitzungen (nur ansehen/teilen) + eigener Referat-Bericht
            $navMore[] = ['report.php', 'ti-gavel', 'Sitzungen & Berichte'];
        }
        if (current_role() === 'finanzen') $navMore[] = ['finanzen.php', 'ti-coins', 'Finanzen']; // eigener Titelleisten-Punkt für die Rolle
        // Abstimmungen & Umlaufverfahren: für alle Mitglieder, in der Topbar nur als Icon (im „Mehr"-Sheet beschriftet)
        $navMore[] = ['umlauf.php', 'ti-mailbox', 'Abstimmungen', true];
        // Mitgliederliste mit Nutzerprofilen: für alle, in der Topbar nur als Icon
        $navMore[] = ['mitglieder.php', 'ti-users-group', 'Mitglieder', true];
        // Abwesenheit: Mitglieder mit Text, Vorsitz/Admin nur als Icon (4. Feld = Icon-only in der
        // Topbar – im aufgeklappten Hamburger-Menü und im „Mehr"-Sheet trotzdem beschriftet),
        // bewusst ZWISCHEN Sitzungen und Verwaltung.
        $navMore[] = ['absence.php', 'ti-plane-departure', 'Abwesenheit', can_admin()];
        // Auslagen (finanzen.php, Einreichen): Finanzen-Rolle hat ihren eigenen Punkt; bei Vorsitz/Admin
        // sitzt das Icon ZWISCHEN Abwesenheit und Verwaltung, für alle anderen weiter hinten bei den Icons.
        if (can_admin() && current_role() !== 'finanzen') $navMore[] = ['finanzen.php', 'ti-receipt-2', 'Auslagen', true];
        // Pat:innenprogramm-Verantwortliche: eigener Titelleisten-Punkt – ihr einziger Zugang,
        // mehr Verwaltung sehen sie nicht. (Admin/Vorsitz kommen über die Verwaltung dorthin.)
        if (is_pat_manager() && !can_admin()) $navMore[] = ['admin/paten.php', 'ti-heart-handshake', 'Pat:innen'];
        // was.läuft-Verantwortliche: gleiche Regel wie beim Pat:innenprogramm. Ihr Recht greift
        // zwar (wl_can_manage), aber die Kachel liegt in der Verwaltung – und die schickt jedes
        // Mitglied ohne Orga-Rolle zurück aufs Dashboard. Ohne diesen Punkt wäre die Seite für
        // sie nur über die getippte Adresse erreichbar. Bei Vorsitz/Admin bleibt er weg, die
        // kommen über die Verwaltung hin und hätten ihn sonst doppelt.
        if (wl_can_manage() && !can_admin()) $navMore[] = ['veranstaltungen.php', 'ti-calendar-star', 'was.läuft'];
        // Umfragen-Zuständige (Referat je Umfrage): je zuständiger Umfrage ein eigener Punkt
        // MIT DEREN TITEL – wie bei Pat:innen/was.läuft ihr einziger Zugang, denn die
        // Verwaltung selbst weist Mitglieder ohne Orga-Rolle ab. Vorsitz/Admin kommen über
        // die Verwaltung hin und bekommen die Punkte nicht doppelt.
        if (!can_admin()) {
            foreach (umfrage_zustaendig_fuer_mich() as $uzp) {
                $uzTitel = trim((string)$uzp['title']);
                if (mb_strlen($uzTitel) > 24) $uzTitel = mb_substr($uzTitel, 0, 23) . '…';
                $navMore[] = ['admin/umfragen.php?edit=' . (int)$uzp['id'], 'ti-chart-donut', $uzTitel];
            }
        }
        if (can_admin()) $navMore[] = ['admin/index.php', 'ti-settings', 'Verwaltung'];
    }
  ?>
  <nav id="mainnav">
    <?php if (is_logged_in()): ?>
      <a href="<?= $b ?>dashboard.php"><i class="ti ti-layout-dashboard"></i>Dashboard</a>
      <a href="<?= $b ?>index.php"><i class="ti ti-calendar-month"></i>Kalender</a>
      <a href="<?= $b ?>events.php"><i class="ti ti-calendar-event"></i>Events</a>
      <?php foreach ($navMore as $nm): [$nmHref, $nmIcon, $nmLabel] = $nm; ?>
        <?php if (!empty($nm[3])): // Icon-only in der Topbar, Beschriftung nur im Hamburger ?>
          <a class="nav-ic" href="<?= $b . $nmHref ?>" title="<?= h($nmLabel) ?>" aria-label="<?= h($nmLabel) ?>"><i class="ti <?= $nmIcon ?>"></i><span class="nav-ic-lbl"><?= h($nmLabel) ?></span></a>
        <?php else: ?>
          <a href="<?= $b . $nmHref ?>"><i class="ti <?= $nmIcon ?>"></i><?= h($nmLabel) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (current_role() !== 'finanzen' && !can_admin()): // Vorsitz/Admin haben Auslagen schon oben zwischen Abwesenheit und Verwaltung ?><a class="nav-ic" href="<?= $b ?>finanzen.php" title="Auslagen einreichen" aria-label="Auslagen einreichen"><i class="ti ti-receipt-2"></i><span class="nav-ic-lbl">Auslagen</span></a><?php endif; ?>
      <a class="nav-ic" href="<?= $b ?>achievements.php" title="Achievements" aria-label="Achievements"><i class="ti ti-trophy"></i><span class="nav-ic-lbl">Achievements</span></a>
      <?php if ($cmNav): // eigener Name → Dropdown: Mein Profil / Wichtige Infos & Anleitung / Konto wechseln / Abmelden ?>
        <details class="nav-user">
          <summary class="navuser"><i class="ti ti-user-circle"></i> <?= h(first_name((string)$cmNav['name'])) ?> <i class="ti ti-chevron-down nav-user-chev"></i></summary>
          <div class="nav-user-menu">
            <a href="<?= $b ?>profil.php"><i class="ti ti-user-circle"></i> Mein Profil</a>
            <a href="<?= $b ?>info.php"><i class="ti ti-info-circle"></i> Wichtige Infos &amp; Anleitung</a>
            <a href="<?= $b ?>wegweiser.php"><i class="ti ti-directions"></i> Wegweiser</a>
            <?php if (count(shared_members()) > 1): ?><a href="<?= $b ?>login.php?switch=1"><i class="ti ti-switch-horizontal"></i> Konto wechseln</a><?php endif; ?>
            <a class="nav-user-out" href="<?= $b ?>logout.php"><i class="ti ti-logout"></i> Abmelden</a>
          </div>
        </details>
      <?php else: // Technik-Login hat kein Namens-Dropdown → Abmelden bleibt als eigener Link ?>
        <a class="navuser" href="<?= $b ?>info.php" title="Wichtige Infos &amp; Anleitung"><i class="ti ti-user-circle"></i> Technik-Login</a>
        <a href="<?= $b ?>logout.php"><i class="ti ti-logout"></i>Abmelden</a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>
</header>
<?php if (is_logged_in()):
    // Bottom-Tab-Bar (nur schmale Screens/PWA, per CSS eingeblendet). Auf Admin-Seiten
    // ist kein Tab „aktiv" (basename wäre dort mehrdeutig, z. B. admin/index.php).
    $tbSn  = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $tbCur = str_contains($tbSn, '/admin/') ? '' : basename($tbSn); ?>
<nav class="tabbar" aria-label="Schnellnavigation">
  <a href="<?= $b ?>dashboard.php"<?= $tbCur === 'dashboard.php' ? ' class="active"' : '' ?>><i class="ti ti-layout-dashboard"></i><span>Dashboard</span></a>
  <a href="<?= $b ?>index.php"<?= $tbCur === 'index.php' ? ' class="active"' : '' ?>><i class="ti ti-calendar-month"></i><span>Kalender</span></a>
  <a href="<?= $b ?>events.php"<?= in_array($tbCur, ['events.php', 'event.php'], true) ? ' class="active"' : '' ?>><i class="ti ti-calendar-event"></i><span>Events</span></a>
  <button type="button" class="tab-more" aria-label="Menü öffnen" aria-expanded="false" aria-controls="navsheet"><i class="ti ti-menu-2"></i><span>Mehr</span></button>
</nav>
<!-- „Mehr": Bottom-Sheet mit den restlichen Menüpunkten (fährt über der Tab-Leiste hoch) -->
<div class="navsheet" id="navsheet" hidden>
  <div class="navsheet-backdrop"></div>
  <div class="navsheet-panel" role="dialog" aria-label="Weitere Seiten">
    <div class="navsheet-head">
      <strong><i class="ti ti-compass"></i> Mehr</strong>
      <button type="button" class="navsheet-x" aria-label="Menü schließen"><i class="ti ti-x"></i></button>
    </div>
    <div class="navsheet-grid">
      <?php foreach ($navMore as [$nmHref, $nmIcon, $nmLabel]): ?>
        <a href="<?= $b . $nmHref ?>"><i class="ti <?= $nmIcon ?>"></i> <?= h($nmLabel) ?></a>
      <?php endforeach; ?>
      <?php if (current_role() !== 'finanzen' && !can_admin()): // Vorsitz/Admin haben Auslagen schon über navMore ?><a href="<?= $b ?>finanzen.php"><i class="ti ti-receipt-2"></i> Auslagen</a><?php endif; ?>
      <a href="<?= $b ?>achievements.php"><i class="ti ti-trophy"></i> Achievements</a>
      <a href="<?= $b ?>erinnerungen.php"><i class="ti ti-bell"></i> Erinnerungen</a>
      <?php if ($cmNav): ?><a href="<?= $b ?>profil.php"><i class="ti ti-user-circle"></i> Mein Profil</a><?php endif; ?>
      <a href="<?= $b ?>info.php"><i class="ti ti-info-circle"></i> Infos &amp; Anleitung</a>
      <a href="<?= $b ?>wegweiser.php"><i class="ti ti-directions"></i> Wegweiser</a>
      <?php if (count(shared_members()) > 1): ?><a href="<?= $b ?>login.php?switch=1"><i class="ti ti-switch-horizontal"></i> Konto wechseln</a><?php endif; ?>
      <!-- Design-Umschalter: in der PWA ist die Topbar (mit der Glühbirne) ausgeblendet.
           Statt Durchschalten öffnet sich hier ein Direktwahl-Menü nach OBEN mit allen Designs. -->
      <div class="ns-theme-wrap">
        <button type="button" class="ns-theme" aria-haspopup="true" aria-expanded="false" onclick="astaNsTheme(this)">
          <span class="mode-system"><i class="ti ti-bulb"></i> Design: System</span>
          <span class="mode-light"><i class="ti ti-bulb"></i> Design: Hell</span>
          <span class="mode-dark"><i class="ti ti-bulb-off"></i> Design: Dunkel</span>
          <?php foreach ($skinsOn as $sk => $sd): ?><span class="mode-<?= $sk ?>"><i class="ti <?= $sd['icon'] ?>"></i> Design: <?= h($sd['label']) ?></span><?php endforeach; ?>
          <i class="ti ti-chevron-up ns-theme-caret" aria-hidden="true"></i>
        </button>
        <div class="ns-theme-menu" role="menu" hidden>
          <button type="button" class="tm-item" data-mode="system" role="menuitem" onclick="astaPickTheme('system')"><i class="ti ti-bulb"></i> System</button>
          <button type="button" class="tm-item" data-mode="light" role="menuitem" onclick="astaPickTheme('light')"><i class="ti ti-bulb"></i> Hell</button>
          <button type="button" class="tm-item" data-mode="dark" role="menuitem" onclick="astaPickTheme('dark')"><i class="ti ti-bulb-off"></i> Dunkel</button>
          <?php foreach ($skinsOn as $sk => $sd): ?><button type="button" class="tm-item" data-mode="<?= $sk ?>" role="menuitem" onclick="astaPickTheme('<?= $sk ?>')"><i class="ti <?= $sd['icon'] ?>"></i> <?= h($sd['label']) ?></button><?php endforeach; ?>
        </div>
      </div>
      <a class="ns-out" href="<?= $b ?>logout.php"><i class="ti ti-logout"></i> Abmelden</a>
    </div>
  </div>
</div>
<?php endif; ?>
<main>
<?php if (is_owner() && !current_member()): ?>
  <div class="techbanner">
    <i class="ti ti-alert-triangle-filled"></i>
    <div><strong>Du bist im Technik-Login.</strong> Dieser Zugang ist nur für <strong>Notfälle und die Ersteinrichtung</strong> gedacht – nicht für den Alltag. Melde dich für die normale Nutzung mit deinem <a href="<?= $b ?>login.php">persönlichen Login-Link</a> an.</div>
  </div>
<?php endif; ?>
<?php if ($flashes): ?>
  <div class="toasts" aria-live="polite"><!-- Flash-Meldungen als schwebende Toasts (Auto-Ausblendung in app.js) -->
  <?php foreach ($flashes as $f):
      $tIcon = ['success' => 'ti-circle-check', 'error' => 'ti-alert-circle', 'info' => 'ti-info-circle'][$f['type']] ?? 'ti-info-circle';
      $tTtl  = $f['type'] === 'error' ? 9000 : 5500; // Fehler länger stehen lassen ?>
    <div class="toast toast-<?= h($f['type']) ?>" data-ttl="<?= $tTtl ?>">
      <i class="ti <?= $tIcon ?>"></i>
      <div class="toast-msg"><?= h($f['msg']) ?></div>
      <button type="button" class="toast-x" aria-label="Meldung schließen"><i class="ti ti-x"></i></button>
      <span class="toast-bar"></span>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if (is_logged_in()): $achUnlocks = take_ach_unlocks(); $streakGain = take_streak_gain(); $propsReset = take_props_reset((int)($_SESSION['member_id'] ?? 0));
  // Testknopf aus der Diagnose: zeigt die Animation einmal, ohne den Monats-Merker zu verbrauchen
  if (!empty($_SESSION['props_demo'])) { unset($_SESSION['props_demo']); $propsReset = true; } ?>
  <?php // „Pretty in Pink": Server kennt Kopfschmuck + rosa Palette; ob der Pink-Skin AKTIV ist, weiß nur der
        // Client (localStorage) – daher Flag ausgeben, app.js prüft data-skin und feuert das Achievement.
        $pinkReady = ($pid = (int)($_SESSION['member_id'] ?? 0)) > 0
            && pinkdream_head_ok($pid)
            && in_array(member_palette($pid), ['cuteness', 'glitzer'], true)
            && !in_array('pinkdream', member_achievement_codes($pid), true);
        // Einmalig automatisch anzuwendender Skin (Sommerkönig-Unlock oder Skin-Geschenk) – app.js aktiviert ihn genau einmal und leert das Feld.
        $autoSkin = ($pid > 0 && ($ps = member_pending_skin($pid)) !== '' && skin_unlocked($ps)) ? $ps : ''; ?>
  <script>window.ASTA_CSRF=<?= json_encode(csrf_token()) ?>;window.ASTA_ACH_EQUIP=<?= json_encode($b . 'achievements.php') ?>;<?php if ($autoSkin): ?>window.ASTA_AUTO_SKIN=<?= json_encode($autoSkin) ?>;<?php endif; ?><?php if (puste_moment() !== ''): ?>window.ASTA_PUSTE=<?= json_encode(puste_moment(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;<?php endif; ?><?php if ($pinkReady): ?>window.ASTA_PINK_READY=1;<?php endif; ?><?php if ($achUnlocks): ?>window.ASTA_ACH_UNLOCKS=<?= json_encode($achUnlocks, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;<?php endif; ?><?php if ($propsReset): ?>window.ASTA_PROPS_RESET=1;window.ASTA_PROPS_LEFT=<?= (int)kudos_quota_left((int)($_SESSION['member_id'] ?? 0)) ?>;<?php endif; ?><?php if ($streakGain > 0): $flameStyle = member_flame_style((int)($_SESSION['member_id'] ?? 0)); ?>window.ASTA_STREAK_GAIN=<?= (int)$streakGain ?>;window.ASTA_FLAME_SVG=<?= json_encode(flame_svg($flameStyle), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;window.ASTA_FLAME_STYLE=<?= json_encode($flameStyle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;window.ASTA_FLAME_AURA=<?= json_encode(streak_aura_html($flameStyle, 5), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;<?php endif; ?><?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'dashboard.php' && !member_onboarded((int)($_SESSION['member_id'] ?? 0))): ?>window.ASTA_TOUR_AUTO=1;<?php endif; ?><?php if (current_member()): ?>window.ASTA_TOUR_PAGES=<?= json_encode(tour_pages(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;window.ASTA_TOUR_FLAGS=<?= json_encode(tour_flags(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;window.ASTA_BASE=<?= json_encode($b, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;<?php endif; ?></script>
<?php endif; ?>
<?php
}

function page_footer(): void
{
    $b = base();
    ?>
</main>
<footer class="foot">
  <?= h(org_name_kurz()) ?> · <?= h(APP_NAME) ?><br>
  <?php // Herzchen: schlägt beim Darüberfahren einmal und lässt ein kleines Herz aufsteigen (reines CSS) ?>
  Mit <span class="foot-herz"><i class="ti ti-heart-filled"></i></span> von Boj Petersen ·
  <?php if (is_logged_in()): ?>
    <button type="button" class="foot-link" onclick="var d=document.getElementById('fbModal');d.showModal&&d.showModal()"><i class="ti ti-message-2"></i> Feedback</button>
    · <a class="foot-link" href="<?= $b ?>bug.php"><i class="ti ti-bug"></i> Fehler melden</a>
  <?php else: ?>
    <a href="mailto:<?= h(org_kontakt_mail()) ?>?subject=Feedback%20AStA-App">Feedback</a>
  <?php endif; ?>
</footer>
<?php if (is_logged_in()): ?>
<dialog id="fbModal" class="fb-modal">
  <form method="post" action="<?= $b ?>feedback.php" class="fb-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="fb_add">
    <input type="hidden" name="from" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
    <h3 style="margin:0 0 .2rem"><i class="ti ti-message-2" style="color:var(--petrol)"></i> Feedback ans Technik-Referat</h3>
    <p class="small muted" style="margin:0 0 .7rem">Was läuft gut, was nervt, was fehlt? Geht direkt ans Technik-Referat – als Eintrag, den ihr beide weiterverfolgen könnt.</p>
    <div class="fb-kinds">
      <?php foreach (feedback_kinds() as $fk => $fkk): ?>
        <label class="fb-kind">
          <input type="radio" name="kind" value="<?= h($fk) ?>"<?= $fk === 'idee' ? ' checked' : '' ?>>
          <span><i class="ti <?= h($fkk['icon']) ?>"></i> <?= h($fkk['label']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php /* autofocus gehört auf das Textfeld: Ohne das setzt der Browser den Fokus beim Öffnen
             auf das ERSTE bedienbare Element – den Auswahlkreis „Lob". Der trüge dann den
             Fokusring, während „Idee" als Vorauswahl gefüllt ist: zwei hervorgehobene Knöpfe,
             die Verschiedenes bedeuten. Nebenbei steht der Cursor gleich dort, wo man
             ohnehin hinwill. */ ?>
    <textarea name="message" rows="5" required autofocus placeholder="Dein Feedback …" style="width:100%;margin-top:.7rem"></textarea>
    <p class="small muted" style="margin:.5rem 0 0"><a href="<?= $b ?>feedback.php">Meine Rückmeldungen &amp; Antworten ansehen</a></p>
    <div class="btn-row" style="margin-top:.8rem;justify-content:flex-end">
      <button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Abbrechen</button>
      <button type="submit" class="btn"><i class="ti ti-send"></i> Senden</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<script>if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('<?= $b ?>sw.js').catch(function () {}); }); }</script>
<script src="<?= $b . asset_v('assets/app.js') ?>"></script>
<?php /* Doppel-Submit-Schutz für alle POST-Formulare – NACH app.js,
         damit sein submit-Listener nach den data-confirm-/data-ajax-Handlern läuft und
         deren preventDefault sieht. */ ?>
<script src="<?= $b . asset_v('assets/doppelklick.js') ?>"></script>
<script src="<?= $b . asset_v('assets/flatpickr/flatpickr.min.js') ?>"></script>
<script src="<?= $b . asset_v('assets/flatpickr/de.js') ?>"></script>
<script>
(function(){
  if (!window.flatpickr) return;
  flatpickr.localize(flatpickr.l10ns.de);
  // Ein einzelnes Feld initialisieren (auch für dynamisch nachgeladene Inputs nutzbar)
  window.astaInitFlatpickr = function(el){
    if (!el || el._flatpickr) return;
    // In einem <dialog> (Top-Layer) den Kalender INLINE rendern, sonst läge er hinter dem Modal
    var st = !!(el.closest && el.closest('dialog'));
    if (el.classList.contains('fp-datetime')) {
      flatpickr(el, {enableTime:true, time_24hr:true, dateFormat:'Y-m-d H:i', static:st,
        altInput:true, altFormat:'D, d.m.Y · H:i', minuteIncrement:15, minDate: el.dataset.min || null});
    } else if (el.classList.contains('fp-date')) {
      flatpickr(el, {dateFormat:'Y-m-d', altInput:true, altFormat:'D, d.m.Y', static:st, minDate: el.dataset.min || null});
    } else if (el.classList.contains('fp-time')) {
      flatpickr(el, {enableTime:true, noCalendar:true, time_24hr:true, dateFormat:'H:i', static:st, minuteIncrement:15});
    }
  };
  document.querySelectorAll('.fp-datetime, .fp-date, .fp-time').forEach(window.astaInitFlatpickr);
})();
</script>
<script>
(function(){
  // Teilen-Buttons: Web-Share-API (mobil) bzw. Link in die Zwischenablage kopieren.
  document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('.js-teilen') : null;
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    var url = b.dataset.url || location.href;
    try { url = new URL(url, location.href).href; } catch(_) {}
    if (navigator.share) { navigator.share({title: b.dataset.title || document.title, url: url}).catch(function(){}); return; }
    var ok = function(){ b.classList.add('copied'); setTimeout(function(){ b.classList.remove('copied'); }, 1600); };
    if (navigator.clipboard) { navigator.clipboard.writeText(url).then(ok, ok); }
    else { try { var t=document.createElement('input'); t.value=url; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); ok(); } catch(_) { ok(); } }
  });
})();
</script>
</body>
</html>
<?php
}
