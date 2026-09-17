<?php
/**
 * Pat:innenprogramm – gemeinsame Datenschicht (EIGENE SQLite-Datei).
 *
 * BEWUSST OHNE lib.php: Diese Datei wird von der ÖFFENTLICHEN Anmeldeseite (pat/) genauso
 * eingebunden wie von der internen Verwaltung (admin/paten.php). Der öffentliche Teil bekommt
 * dadurch eine PDO-Verbindung, die die App-Datenbank (asta.sqlite mit members, login_tokens,
 * remember_tokens, push_subscriptions) strukturell NICHT kennt. Selbst ein Fehler im öffentlichen
 * Anmeldeformular kann dort also nichts erreichen – die Trennung ist keine Frage der Disziplin,
 * sondern der Verbindung.
 *
 * Regeln für diese Datei:
 *   • Nur Funktionen mit Präfix pat_* definieren – sie wird zusammen mit lib.php geladen.
 *   • Keine Ausgabe, keine Session, kein Login. Reine Datenschicht.
 *   • Immer per require_once einbinden.
 *
 * Warum eine eigene Einstellungstabelle (pat_settings)? Die öffentliche Seite braucht die
 * Basis-URL (für Linkvorschau-Meta) und ihre Texte, darf dafür aber nicht in asta.sqlite schauen.
 * Die Verwaltung spiegelt die Basis-URL beim Öffnen herüber – Datenfluss also nur nach innen.
 */

declare(strict_types=1);


// Marke dieser Installation (Logo, App-Symbol) – eigenständig, ohne lib.php.
require_once __DIR__ . '/brand-core.php';

// Gemeinsames Mail-Konto aller Bereiche (eigenständig, ohne lib.php).
require_once __DIR__ . '/mail-pool.php';

if (!defined('PAT_DB_FILE')) {
    define('PAT_DB_FILE', __DIR__ . '/data/pat.sqlite');
}

/** Verbindung zur Pat:innenprogramm-Datenbank (Schema wird bei Bedarf angelegt). */
function pat_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(PAT_DB_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . PAT_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');  // öffentliche Leser blockieren die Verwaltung nicht
    $pdo->exec('PRAGMA busy_timeout = 4000'); // kurz auf einen Lock warten statt sofort scheitern
    pat_schema($pdo);
    return $pdo;
}

/** Schema anlegen/ergänzen. Idempotent, läuft bei jedem Verbindungsaufbau. */
function pat_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS pat_rounds (
        id           INTEGER PRIMARY KEY,
        term         TEXT    NOT NULL,                  -- 'wise' | 'sose'
        year         INTEGER NOT NULL,                  -- Startjahr: WiSe 2026/27 -> 2026, SoSe 2026 -> 2026
        slug         TEXT    NOT NULL UNIQUE,           -- öffentlicher Schlüssel im Studi-Link
        status       TEXT    NOT NULL DEFAULT 'draft',  -- draft | open | closed | archived
        group_min    INTEGER NOT NULL DEFAULT 3,        -- Wunsch-Gruppengröße (Erstis je Pat:in)
        group_max    INTEGER NOT NULL DEFAULT 6,
        delete_after TEXT,                              -- Löschtag (YYYY-MM-DD); Cron räumt dann alles ab
        -- Zeitplan (alle YYYY-MM-DD, alle optional). Steht auf der Studi-Seite und in den
        -- Texten, damit niemand raten muss, wann es weitergeht.
        signup_from  TEXT,                              -- Anmeldung ab
        signup_to    TEXT,                              -- Anmeldeschluss
        match_by     TEXT,                              -- bis dann ist die Einteilung da
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        opened_at  TEXT,
        closed_at  TEXT
    )");
    // Pro Semester genau ein Programm – verhindert versehentliche Doppelanlage.
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS pat_rounds_term_year ON pat_rounds(term, year)');
    $rc = array_column($pdo->query('PRAGMA table_info(pat_rounds)')->fetchAll(), 'name');
    if (!in_array('group_min', $rc, true)) $pdo->exec('ALTER TABLE pat_rounds ADD COLUMN group_min INTEGER NOT NULL DEFAULT 3');
    if (!in_array('group_max', $rc, true)) $pdo->exec('ALTER TABLE pat_rounds ADD COLUMN group_max INTEGER NOT NULL DEFAULT 6');
    // Löschfrist: an diesem Tag räumt der Cron das ganze Programm samt Personendaten ab.
    if (!in_array('delete_after', $rc, true)) $pdo->exec('ALTER TABLE pat_rounds ADD COLUMN delete_after TEXT');
    // Zeitplan (nachgerüstet)
    if (!in_array('signup_from', $rc, true)) $pdo->exec('ALTER TABLE pat_rounds ADD COLUMN signup_from TEXT');
    if (!in_array('signup_to', $rc, true))   $pdo->exec('ALTER TABLE pat_rounds ADD COLUMN signup_to TEXT');
    if (!in_array('match_by', $rc, true))    $pdo->exec('ALTER TABLE pat_rounds ADD COLUMN match_by TEXT');

    $pdo->exec('CREATE TABLE IF NOT EXISTS pat_settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');

    // Studiengangsliste (Verwaltung pflegt sie, Erstis wählen daraus): je Abschluss eine eigene
    // Liste – derselbe Studiengang darf als Bachelor UND Master existieren (zwei Zeilen).
    // ZWEI EBENEN: parent_id = 0 → Studiengang (z. B. „Gymnasiallehramt"); parent_id > 0 →
    // Unterpunkt dazu (z. B. Erstfach „Deutsch"). sub_label am Studiengang benennt die
    // Unterauswahl („Erstfach") und erscheint als Frage im Anmelde-Assistenten.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pat_courses (
        id         INTEGER PRIMARY KEY,
        degree     TEXT    NOT NULL,               -- 'bachelor' | 'master'
        name       TEXT    NOT NULL,
        parent_id  INTEGER NOT NULL DEFAULT 0,
        sub_label  TEXT    NOT NULL DEFAULT '',
        active     INTEGER NOT NULL DEFAULT 1,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    // Migration für Bestände von vor den Unterpunkten (die kurzlebige category-Spalte
    // bleibt, falls vorhanden, einfach ungenutzt stehen)
    $cols = array_column($pdo->query('PRAGMA table_info(pat_courses)')->fetchAll(), 'name');
    if (!in_array('parent_id', $cols, true)) {
        $pdo->exec("ALTER TABLE pat_courses ADD COLUMN parent_id INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('sub_label', $cols, true)) {
        $pdo->exec("ALTER TABLE pat_courses ADD COLUMN sub_label TEXT NOT NULL DEFAULT ''");
    }
    // Eindeutigkeit gilt je Ebene: „Deutsch" darf unter Gymnasial- UND Realschullehramt stehen.
    $pdo->exec('DROP INDEX IF EXISTS pat_courses_unique');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS pat_courses_uni2 ON pat_courses(degree, parent_id, name COLLATE NOCASE)');

    // Anmeldungen. course_label ist die EINGEFRORENE Anzeige („Gymnasiallehramt – Deutsch") zum
    // Zeitpunkt der Anmeldung – spätere Umbenennungen in der Studiengangsliste ändern nichts
    // an bestehenden Anmeldungen. course_id NULL = Freitext (wird von Hand zugeordnet).
    $pdo->exec("CREATE TABLE IF NOT EXISTS pat_signups (
        id           INTEGER PRIMARY KEY,
        round_id     INTEGER NOT NULL REFERENCES pat_rounds(id) ON DELETE CASCADE,
        role         TEXT    NOT NULL,               -- 'ersti' | 'pate'
        degree       TEXT    NOT NULL,               -- 'bachelor' | 'master'
        course_id    INTEGER,
        -- Bei frei eingetragenem Unterpunkt: der Studiengang, unter dem er eingetragen wurde.
        -- Ohne das ginge die Zugehörigkeit zur Kategorie verloren: Gymnasiallehramt + Latein
        -- wäre dann nur noch Latein und fände keine Lehramts-Pat:in mehr.
        course_parent_id INTEGER,
        course_free  TEXT    NOT NULL DEFAULT '',
        course_label TEXT    NOT NULL,
        first_name   TEXT    NOT NULL,
        last_name    TEXT    NOT NULL,
        email        TEXT    NOT NULL,
        about        TEXT    NOT NULL DEFAULT '',
        status       TEXT    NOT NULL DEFAULT 'new',
        pate_id      INTEGER,                           -- nur bei Erstis: Anmeldung der zugeteilten Pat:in
        created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $sc = array_column($pdo->query('PRAGMA table_info(pat_signups)')->fetchAll(), 'name');
    if (!in_array('pate_id', $sc, true)) $pdo->exec('ALTER TABLE pat_signups ADD COLUMN pate_id INTEGER');
    if (!in_array('course_parent_id', $sc, true)) $pdo->exec('ALTER TABLE pat_signups ADD COLUMN course_parent_id INTEGER');
    // Eine Anmeldung pro Postfach, Rolle und Semester (dieselbe Person darf theoretisch
    // Pat:in im Master und … nein – aber Tippfehler-Doppelklicks fängt das sicher ab).
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS pat_signups_unique ON pat_signups(round_id, role, email COLLATE NOCASE)');

    // Weitere Fächer, die eine Pat:in studiert.
    //
    // Im Lehramt studiert fast jede:r mehrere Fächer – hier stehen die neben dem Erstfach.
    // Nur für Pat:innen und AUSSCHLIESSLICH Unterpunkte desselben Studiengangs, den sie gewählt
    // hat – die Studiengangsgrenze wird nie überschritten. Eigene Tabelle statt einer Spalte mit
    // Liste, weil die Einteilung darin nachschlägt und ein Fach beim Löschen aus der
    // Studiengangsliste einfach mit verschwinden soll.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pat_signup_subjects (
        signup_id INTEGER NOT NULL REFERENCES pat_signups(id) ON DELETE CASCADE,
        course_id INTEGER NOT NULL REFERENCES pat_courses(id) ON DELETE CASCADE,
        PRIMARY KEY (signup_id, course_id)
    )");

    // Mail-Warteschlange. Anders als die Rundmail (die der AStA von Hand verschickt) sind die
    // Einteilungs-Mails PERSÖNLICH – je Person eine eigene. Hunderte davon in einem einzigen
    // Aufruf zu verschicken läuft in Zeitlimits und in die Versandgrenzen des Hosters; deshalb
    // erst einstellen, dann in kleinen Schüben senden und je Empfänger:in festhalten, was war.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pat_mailqueue (
        id         INTEGER PRIMARY KEY,
        round_id   INTEGER NOT NULL REFERENCES pat_rounds(id) ON DELETE CASCADE,
        signup_id  INTEGER,
        kind       TEXT    NOT NULL DEFAULT 'assign',
        to_email   TEXT    NOT NULL,
        subject    TEXT    NOT NULL,
        body       TEXT    NOT NULL,
        -- queued | sending | sent | failed. 'sending' heißt: ein Versandlauf hat diese Mail
        -- beansprucht (siehe pat_mail_run) – damit ein zweiter Lauf sie nicht auch verschickt.
        status     TEXT    NOT NULL DEFAULT 'queued',
        tries      INTEGER NOT NULL DEFAULT 0,
        last_error TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        claimed_at TEXT,                                 -- wann beansprucht (gegen hängende Läufe)
        sent_at    TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS pat_mailqueue_open ON pat_mailqueue(status, id)');
    $mc = array_column($pdo->query('PRAGMA table_info(pat_mailqueue)')->fetchAll(), 'name');
    if (!in_array('claimed_at', $mc, true)) $pdo->exec('ALTER TABLE pat_mailqueue ADD COLUMN claimed_at TEXT');
    // CC (kommagetrennt) für die Gruppen-Mail: EINE Mail je Gruppe, Pat:in im An, Erstis im CC –
    // alle sehen einander, „Allen antworten" erreicht die ganze Gruppe.
    if (!in_array('cc', $mc, true)) $pdo->exec("ALTER TABLE pat_mailqueue ADD COLUMN cc TEXT NOT NULL DEFAULT ''");

    // Fragen aus dem Hilfe-Formular der öffentlichen Seiten.
    //
    // BEWUSST eine eigene Tabelle statt pat_mailqueue: eine Frage hat eine Absenderin, an die
    // geantwortet werden muss – dafür hat die Warteschlange keine Spalte. Außerdem darf man
    // auch fragen, wenn gerade GAR KEIN Programm läuft; round_id bleibt dann leer. Und die
    // Frage soll in der Verwaltung sichtbar bleiben, selbst wenn der Mailversand scheitert.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pat_messages (
        id         INTEGER PRIMARY KEY,
        -- Leer erlaubt: gefragt wird auch, wenn gerade kein Programm läuft. Wird das Programm
        -- später gelöscht, bleibt die Frage stehen und verliert nur den Bezug.
        round_id   INTEGER REFERENCES pat_rounds(id) ON DELETE SET NULL,
        name       TEXT    NOT NULL DEFAULT '',    -- freiwillig
        email      TEXT    NOT NULL,               -- Pflicht, sonst kann niemand antworten
        body       TEXT    NOT NULL,
        page       TEXT    NOT NULL DEFAULT '',    -- von welcher Seite aus gefragt wurde
        -- neu | gesendet | fehler
        status     TEXT    NOT NULL DEFAULT 'neu',
        tries      INTEGER NOT NULL DEFAULT 0,
        last_error TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        sent_at    TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS pat_messages_open ON pat_messages(status, id)');
}

// ---------------------------------------------------------------------------
// Einstellungen (eigene Tabelle, damit die öffentliche Seite autark bleibt)
// ---------------------------------------------------------------------------

/**
 * Rohwert einer Einstellung – null, wenn NICHTS gespeichert ist.
 *
 * Der Unterschied ist wichtig: „nichts gespeichert" heißt Standard verwenden, ein
 * „bewusst leer gespeichert" heißt bei den Texten dagegen „dieses Element weglassen".
 * Beides wäre ohne diese Unterscheidung nicht auseinanderzuhalten.
 */
function pat_setting_raw(string $key): ?string
{
    $st = pat_db()->prepare('SELECT value FROM pat_settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function pat_setting_get(string $key, string $default = ''): string
{
    return pat_setting_raw($key) ?? $default;
}

/**
 * Schmuck im Kopfband des Pat:innenprogramms – der CSS-Wert für --rptu.
 *
 * Warum als Einstellung und nicht im Stylesheet: Dort standen die Buchstabenformen aus dem
 * Logo der Hochschule. Die gehören der Hochschule, nicht diesem Programm, und dürfen bei
 * einer Weitergabe nicht mitgehen. Wer eigenen Schmuck will, trägt hier einen
 * background-image-Wert ein; leer heißt schlicht kein Schmuck.
 */
function pat_hero_deko(): string
{
    return trim(pat_setting_get('hero_deko', ''));
}

/**
 * Einmalige Übernahme: Solange der Schmuck noch im Stylesheet steht, wird er von dort in die
 * Einstellungen geholt. Danach kann er aus dem Stylesheet verschwinden, ohne dass sich für
 * diese Installation etwas ändert.
 */
function pat_hero_deko_uebernehmen(): void
{
    if (pat_setting_raw('hero_deko') !== null) return;
    $css = (string)@file_get_contents(__DIR__ . '/assets/pat.css');
    if (!preg_match('~--rptu:\s*((?:url\("data:image/svg\+xml,[^"]*"\)[^;]*?)+);~s', $css, $t)) return;
    $wert = trim(preg_replace('~\s+~', ' ', $t[1]));
    if ($wert !== '' && stripos($wert, 'none') !== 0) pat_setting_set('hero_deko', $wert);
}

/** Trägername, wie er im Auftritt des Pat:innenprogramms erscheint (Einstellung, Vorgabe wie bisher). */
function pat_traeger(): string
{
    return pat_setting_get('traeger_name', 'Studierendenvertretung');
}

function pat_setting_set(string $key, string $value): void
{
    pat_db()->prepare('INSERT INTO pat_settings(key, value) VALUES(?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

/** Einstellung ganz entfernen → ab dann gilt wieder der Standard. */
function pat_setting_delete(string $key): void
{
    pat_db()->prepare('DELETE FROM pat_settings WHERE key = ?')->execute([$key]);
}

// ---------------------------------------------------------------------------
// Semester & Programme („Runden")
// ---------------------------------------------------------------------------

/** Die beiden Semesterarten. */
function pat_terms(): array
{
    return ['wise' => 'Wintersemester', 'sose' => 'Sommersemester'];
}

/** Anzeigename aus Semesterart + Startjahr: „WiSe 2026/27" bzw. „SoSe 2026". */
function pat_label(string $term, int $year): string
{
    return $term === 'wise'
        ? 'WiSe ' . $year . '/' . substr((string)($year + 1), -2)
        : 'SoSe ' . $year;
}

/** Anzeigename eines Programm-Datensatzes. */
function pat_round_label(array $r): string
{
    return pat_label((string)$r['term'], (int)$r['year']);
}

/** Status als deutsches Wort. */
function pat_status_label(string $status): string
{
    return match ($status) {
        'draft'    => 'Entwurf',
        'open'     => 'Anmeldung offen',
        'closed'   => 'Anmeldung geschlossen',
        'archived' => 'archiviert',
        default    => $status,
    };
}

/**
 * Welches Semester liegt als nächstes an? (Vorbelegung im Anlege-Formular.)
 *  Apr–Sep  -> WiSe dieses Jahres        (Anmeldung läuft über den Sommer)
 *  Okt–Dez  -> SoSe des nächsten Jahres
 *  Jan–Mär  -> SoSe dieses Jahres
 */
function pat_suggest_next(): array
{
    $m = (int)date('n');
    $y = (int)date('Y');
    if ($m >= 4 && $m <= 9)  return ['term' => 'wise', 'year' => $y];
    if ($m >= 10)            return ['term' => 'sose', 'year' => $y + 1];
    return ['term' => 'sose', 'year' => $y];
}

/**
 * Öffentlicher Schlüssel für den Studi-Link: lesbarer Teil + 6 Zufallszeichen.
 * Der Link wird per Rundmail an hunderte Studierende verteilt, ist also kein Geheimnis –
 * der Zufallsanteil verhindert aber, dass sich Programme anderer Semester erraten lassen,
 * und erlaubt es, einen versehentlich falsch verteilten Link zu erneuern.
 * Alphabet ohne Verwechsler (0/O, 1/l/I).
 */
function pat_new_slug(string $term, int $year): string
{
    $abc = '23456789abcdefghjkmnpqrstuvwxyz';
    $rnd = '';
    for ($i = 0; $i < 6; $i++) $rnd .= $abc[random_int(0, strlen($abc) - 1)];
    return $term . $year . '-' . $rnd;
}

/** Alle Programme, neuestes Semester zuerst. */
function pat_rounds_all(): array
{
    // 'wise' > 'sose' alphabetisch – bei DESC steht das Wintersemester eines Jahres also
    // vor dem Sommersemester, was der zeitlichen Reihenfolge entspricht.
    return pat_db()->query('SELECT * FROM pat_rounds ORDER BY year DESC, term DESC')->fetchAll();
}

function pat_round_get(int $id): ?array
{
    $st = pat_db()->prepare('SELECT * FROM pat_rounds WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Programm über den öffentlichen Schlüssel finden (der einzige Lesezugriff der öffentlichen Seite). */
function pat_round_by_slug(string $slug): ?array
{
    if (!pat_slug_valid($slug)) return null;
    $st = pat_db()->prepare('SELECT * FROM pat_rounds WHERE slug = ?');
    $st->execute([$slug]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Form eines Schlüssels prüfen, bevor er in eine Abfrage geht. */
function pat_slug_valid(string $slug): bool
{
    return (bool)preg_match('/^[a-z]{4}\d{4}-[a-z0-9]{6}$/', $slug);
}

/** Genau ein Programm mit offener Anmeldung? (Für den Aufruf von pat/ ohne Schlüssel.) */
function pat_round_single_open(): ?array
{
    $rows = pat_db()->query("SELECT * FROM pat_rounds WHERE status = 'open'")->fetchAll();
    return count($rows) === 1 ? $rows[0] : null;
}

/**
 * Neues Programm anlegen. Gibt ['ok'=>bool, 'id'=>int, 'error'=>string] zurück.
 * Jedes Semester ist ein eigener Datensatz mit eigenem Link – nichts wird geteilt.
 */
function pat_round_create(string $term, int $year): array
{
    if (!isset(pat_terms()[$term])) return ['ok' => false, 'id' => 0, 'error' => 'Unbekannte Semesterart.'];
    if ($year < 2020 || $year > 2100)  return ['ok' => false, 'id' => 0, 'error' => 'Jahr außerhalb des sinnvollen Bereichs.'];

    $st = pat_db()->prepare('SELECT id FROM pat_rounds WHERE term = ? AND year = ?');
    $st->execute([$term, $year]);
    if ($st->fetchColumn() !== false) {
        return ['ok' => false, 'id' => 0, 'error' => 'Für ' . pat_label($term, $year) . ' gibt es schon ein Programm.'];
    }

    // Schlüsselkollision ist praktisch ausgeschlossen, wird aber sauber abgefangen.
    for ($try = 0; $try < 5; $try++) {
        try {
            $ins = pat_db()->prepare('INSERT INTO pat_rounds(term, year, slug) VALUES(?,?,?)');
            $ins->execute([$term, $year, pat_new_slug($term, $year)]);
            return ['ok' => true, 'id' => (int)pat_db()->lastInsertId(), 'error' => ''];
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE')) throw $e;
        }
    }
    return ['ok' => false, 'id' => 0, 'error' => 'Konnte keinen eindeutigen Link erzeugen – bitte erneut versuchen.'];
}

/** Standard-Löschfrist: ein halbes Jahr – ein Semester läuft, danach ist der Zweck erfüllt. */
const PAT_DELETE_MONTHS = 6;

/** Status setzen (mit Zeitstempeln für Öffnen/Schließen). */
function pat_round_set_status(int $id, string $status): bool
{
    if (!in_array($status, ['draft', 'open', 'closed', 'archived'], true)) return false;
    $r = pat_round_get($id);
    if (!$r) return false;

    $sql = 'UPDATE pat_rounds SET status = ?';
    if ($status === 'open')   $sql .= ", opened_at = datetime('now','localtime'), closed_at = NULL";
    if ($status === 'closed') $sql .= ", closed_at = datetime('now','localtime')";
    $sql .= ' WHERE id = ?';
    pat_db()->prepare($sql)->execute([$status, $id]);

    // Beim ERSTEN Öffnen die Löschfrist setzen – ab da liegen echte Personendaten in der DB.
    if ($status === 'open' && trim((string)($r['delete_after'] ?? '')) === '') {
        pat_round_set_delete_after($id, date('Y-m-d', strtotime('+' . PAT_DELETE_MONTHS . ' months')));
    }
    return true;
}

/** Löschtag setzen ('' entfernt die Frist – dann wird nie automatisch gelöscht). */
function pat_round_set_delete_after(int $id, string $date): bool
{
    if (!pat_round_get($id)) return false;
    $date = trim($date);
    if ($date === '') {
        pat_db()->prepare('UPDATE pat_rounds SET delete_after = NULL WHERE id = ?')->execute([$id]);
        return true;
    }
    if (!pat_valid_date($date)) return false;
    pat_db()->prepare('UPDATE pat_rounds SET delete_after = ? WHERE id = ?')->execute([$date, $id]);
    return true;
}

/**
 * Ist das ein echter Kalendertag im Format YYYY-MM-DD?
 *
 * Nur auf das Format zu prüfen genügt nicht: strtotime('2026-02-30') liefert klaglos den
 * 2. März – gespeichert würde dann ein anderer Tag als eingegeben.
 */
function pat_valid_date(string $ymd): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * Zeitplan des Programms setzen: Anmeldung von/bis und „Einteilung bis".
 * Leere Angaben löschen das jeweilige Datum. Die Reihenfolge muss stimmen – ein
 * Anmeldeschluss vor dem Anmeldestart wäre für die Studierenden schlicht Unsinn.
 * Gibt ['ok','error'] zurück.
 */
function pat_round_set_schedule(int $id, string $from, string $to, string $matchBy): array
{
    if (!pat_round_get($id)) return ['ok' => false, 'error' => 'Programm nicht gefunden.'];
    $clean = [];
    foreach (['from' => $from, 'to' => $to, 'match' => $matchBy] as $k => $v) {
        $v = trim($v);
        if ($v === '') { $clean[$k] = null; continue; }
        if (!pat_valid_date($v)) return ['ok' => false, 'error' => 'Bitte gültige Datumsangaben wählen.'];
        $clean[$k] = $v;
    }
    if ($clean['from'] && $clean['to'] && $clean['from'] > $clean['to']) {
        return ['ok' => false, 'error' => 'Der Anmeldeschluss liegt vor dem Anmeldestart.'];
    }
    if ($clean['to'] && $clean['match'] && $clean['to'] > $clean['match']) {
        return ['ok' => false, 'error' => 'Die Einteilung kann nicht vor dem Anmeldeschluss fertig sein.'];
    }
    if (!$clean['to'] && $clean['from'] && $clean['match'] && $clean['from'] > $clean['match']) {
        return ['ok' => false, 'error' => 'Die Einteilung kann nicht vor dem Anmeldestart fertig sein.'];
    }
    pat_db()->prepare('UPDATE pat_rounds SET signup_from = ?, signup_to = ?, match_by = ? WHERE id = ?')
        ->execute([$clean['from'], $clean['to'], $clean['match'], $id]);
    return ['ok' => true, 'error' => ''];
}

/** Ein Datum aus der Datenbank für Menschen: '2026-10-15' -> '15.10.2026'; leer bleibt leer. */
function pat_date(?string $ymd): string
{
    $ymd = trim((string)$ymd);
    if ($ymd === '') return '';
    $t = strtotime($ymd);
    return $t === false ? '' : date('d.m.Y', $t);
}

/**
 * Platzhalter, die in JEDEM Text des Programms gelten – wie {{PROGRAMM}} auch.
 *
 * Nicht gesetzte Daten werden zu einem LEEREN String: ein Satz wie „Anmeldeschluss ist
 * {{ANMELDUNG_BIS}}" darf dann zwar seltsam aussehen, aber niemals „{{ANMELDUNG_BIS}}" oder
 * ein falsches Datum an die Studierenden schicken. Die Verwaltung weist darauf hin.
 */
function pat_round_vars(array $round): array
{
    return [
        '{{PROGRAMM}}'       => pat_round_label($round),
        '{{ANMELDUNG_VON}}'  => pat_date($round['signup_from'] ?? null),
        '{{ANMELDUNG_BIS}}'  => pat_date($round['signup_to'] ?? null),
        '{{EINTEILUNG_BIS}}' => pat_date($round['match_by'] ?? null),
    ];
}

/**
 * Zeitplan für die Anzeige: nur die WIRKLICH gesetzten Termine, jeder mit Beschriftung, Datum
 * und Symbol. Fehlende Daten fallen raus, statt eine halbe Zeile stehen zu lassen – deshalb hier
 * strukturiert und nicht als Textbaustein mit Platzhaltern.
 * Gibt eine Liste aus ['label','date','icon'] zurück (leer = nichts anzuzeigen).
 */
function pat_schedule_items(array $round): array
{
    $map = [
        ['signup_from', 'sched_from_label',  'ti-calendar-plus'],
        ['signup_to',   'sched_to_label',    'ti-calendar-x'],
        ['match_by',    'sched_match_label', 'ti-users-group'],
    ];
    $out = [];
    foreach ($map as [$col, $textKey, $icon]) {
        $d = pat_date($round[$col] ?? null);
        $l = trim(pat_text($textKey));
        // Leere Beschriftung = bewusst ausgeblendet (siehe „leer gespeichert" weiter oben).
        if ($d === '' || $l === '') continue;
        $out[] = ['label' => $l, 'date' => $d, 'icon' => $icon];
    }
    return $out;
}

/**
 * Steht der Anmeldeschluss in der Vergangenheit, während die Anmeldung noch offen ist?
 * Dann sagt die Studi-Seite etwas anderes als die Wirklichkeit – die Verwaltung warnt.
 */
function pat_signup_overdue(array $round): bool
{
    $to = trim((string)($round['signup_to'] ?? ''));
    return $to !== '' && (string)($round['status'] ?? '') === 'open' && $to < date('Y-m-d');
}

/** Tage bis zur Löschung (negativ = überfällig, null = keine Frist gesetzt). */
function pat_delete_days_left(array $round): ?int
{
    $d = trim((string)($round['delete_after'] ?? ''));
    if ($d === '') return null;
    $t = strtotime($d . ' 00:00:00');
    if ($t === false) return null;
    return (int)floor(($t - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
}

/**
 * Fällige Programme abräumen – KOMPLETT, samt Anmeldungen und Mail-Warteschlange
 * (Fremdschlüssel-Kaskade). Programme mit laufender Anmeldung bleiben unangetastet,
 * auch wenn ihr Datum erreicht ist: was noch benutzt wird, wird nicht gelöscht.
 * Gibt je gelöschtem Programm ['label','signups'] zurück.
 */
function pat_purge_due(): array
{
    $today = date('Y-m-d');
    $st = pat_db()->prepare("SELECT * FROM pat_rounds
        WHERE delete_after IS NOT NULL AND delete_after <> '' AND delete_after <= ? AND status <> 'open'");
    $st->execute([$today]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $c = pat_db()->prepare('SELECT COUNT(*) FROM pat_signups WHERE round_id = ?');
        $c->execute([(int)$r['id']]);
        $n = (int)$c->fetchColumn();
        if (pat_round_delete((int)$r['id'])) {
            $out[] = ['label' => pat_round_label($r), 'signups' => $n];
        }
    }
    return $out;
}

/** Wunsch-Gruppengröße eines Programms setzen (Untergrenze/Obergrenze Erstis je Pat:in). */
function pat_round_set_groupsize(int $id, int $min, int $max): bool
{
    if (!pat_round_get($id)) return false;
    if ($min < 1 || $max < $min || $max > 50) return false;
    pat_db()->prepare('UPDATE pat_rounds SET group_min = ?, group_max = ? WHERE id = ?')->execute([$min, $max, $id]);
    return true;
}

/** Studi-Link erneuern (macht den alten Link ungültig). */
function pat_round_regen_slug(int $id): bool
{
    $r = pat_round_get($id);
    if (!$r) return false;
    for ($try = 0; $try < 5; $try++) {
        try {
            pat_db()->prepare('UPDATE pat_rounds SET slug = ? WHERE id = ?')
                ->execute([pat_new_slug((string)$r['term'], (int)$r['year']), $id]);
            return true;
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE')) throw $e;
        }
    }
    return false;
}

/** Programm samt Anmeldungen und Mail-Warteschlange löschen (Fremdschlüssel-Kaskade). */
function pat_round_delete(int $id): bool
{
    if (!pat_round_get($id)) return false;
    pat_db()->prepare('DELETE FROM pat_rounds WHERE id = ?')->execute([$id]);
    return true;
}

// ---------------------------------------------------------------------------
// Studiengänge
// ---------------------------------------------------------------------------

/** Die beiden Abschluss-Arten, aus denen Erstis zuerst wählen. */
function pat_degrees(): array
{
    return ['bachelor' => 'Bachelor', 'master' => 'Master'];
}

/** Studiengänge (nur die OBERSTE Ebene), alphabetisch; optional auf einen Abschluss gefiltert. */
function pat_courses(?string $degree = null, bool $activeOnly = true): array
{
    $where = ['parent_id = 0'];
    $params = [];
    if ($activeOnly) $where[] = 'active = 1';
    if ($degree !== null) {
        if (!isset(pat_degrees()[$degree])) return [];
        $where[] = 'degree = ?';
        $params[] = $degree;
    }
    $sql = 'SELECT * FROM pat_courses WHERE ' . implode(' AND ', $where)
        . ' ORDER BY degree, name COLLATE NOCASE';
    $st = pat_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Unterpunkte eines Studiengangs (z. B. die Erstfächer), alphabetisch. */
function pat_course_children(int $parentId, bool $activeOnly = true): array
{
    if ($parentId <= 0) return [];
    $sql = 'SELECT * FROM pat_courses WHERE parent_id = ?'
        . ($activeOnly ? ' AND active = 1' : '')
        . ' ORDER BY name COLLATE NOCASE';
    $st = pat_db()->prepare($sql);
    $st->execute([$parentId]);
    return $st->fetchAll();
}

/** Gemeinsamer Puffer für pat_course_get() (per Referenz, damit er leerbar bleibt). */
function &pat_course_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Puffer leeren – nach jeder Änderung an der Studiengangsliste. */
function pat_course_forget(): void
{
    $c =& pat_course_cache();
    $c = [];
}

/**
 * Studiengang je ID. Gepuffert, weil die Einteilung diese Abfrage sehr oft braucht:
 * bei 300 Erstis × 60 Pat:innen wären es sonst zehntausende Einzelabfragen.
 */
function pat_course_get(int $id): ?array
{
    if ($id <= 0) return null;
    $cache =& pat_course_cache();
    if (array_key_exists($id, $cache)) return $cache[$id];
    $st = pat_db()->prepare('SELECT * FROM pat_courses WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    return $cache[$id] = ($c ?: null);
}

/**
 * Studiengang (parentId 0) oder Unterpunkt (parentId > 0, z. B. Erstfach) anlegen.
 * Gibt ['ok','id','error'] zurück. Unterpunkte erben den Abschluss ihres Studiengangs;
 * mehr als zwei Ebenen gibt es bewusst nicht.
 */
function pat_course_add(string $degree, string $name, int $parentId = 0): array
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if (!isset(pat_degrees()[$degree])) return ['ok' => false, 'id' => 0, 'error' => 'Unbekannter Abschluss.'];
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        return ['ok' => false, 'id' => 0, 'error' => 'Bitte einen Namen mit 2–120 Zeichen angeben.'];
    }
    if ($parentId < 0) return ['ok' => false, 'id' => 0, 'error' => 'Ungültiger Studiengang.'];
    if ($parentId > 0) {
        $p = pat_course_get($parentId);
        if (!$p || (int)$p['parent_id'] !== 0) {
            return ['ok' => false, 'id' => 0, 'error' => 'Unterpunkte gehen nur direkt unter einem Studiengang.'];
        }
        if ((string)$p['degree'] !== $degree) {
            return ['ok' => false, 'id' => 0, 'error' => 'Der Unterpunkt muss zum Abschluss des Studiengangs passen.'];
        }
    }
    try {
        pat_db()->prepare('INSERT INTO pat_courses(degree, name, parent_id) VALUES(?, ?, ?)')
            ->execute([$degree, $name, $parentId]);
        pat_course_forget();
        return ['ok' => true, 'id' => (int)pat_db()->lastInsertId(), 'error' => ''];
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            return ['ok' => false, 'id' => 0, 'error' => '„' . $name . '" gibt es dort schon.'];
        }
        throw $e;
    }
}

/** Bezeichnung der Unterauswahl eines Studiengangs setzen (z. B. „Erstfach"). */
function pat_course_sublabel_set(int $id, string $label): bool
{
    $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
    $c = pat_course_get($id);
    if (!$c || (int)$c['parent_id'] !== 0 || mb_strlen($label) > 40) return false;
    pat_db()->prepare('UPDATE pat_courses SET sub_label = ? WHERE id = ?')->execute([$label, $id]);
    pat_course_forget();
    return true;
}

/** Löschen – ein Studiengang nimmt seine Unterpunkte mit. */
function pat_course_delete(int $id): bool
{
    if (!pat_course_get($id)) return false;
    pat_db()->prepare('DELETE FROM pat_courses WHERE id = ? OR parent_id = ?')->execute([$id, $id]);
    pat_course_forget();
    return true;
}

// ---------------------------------------------------------------------------
// Anmeldungen
// ---------------------------------------------------------------------------

/**
 * Anmeldung speichern. $d: round_id, role, degree, course_id (0/null = Freitext), course_free,
 * course_label, first_name, last_name, email, about. Gibt ['ok','id','error','dupe'] zurück –
 * 'dupe' = mit dieser Adresse gibt es die Anmeldung schon (die Seite zeigt dafür ihren
 * pflegbaren Text). Validiert ALLES selbst – der öffentliche Aufrufer wird nicht verlassen.
 */
function pat_signup_create(array $d): array
{
    $no = fn (string $err, bool $dupe = false) => ['ok' => false, 'id' => 0, 'error' => $err, 'dupe' => $dupe];
    $norm = fn ($v) => trim((string)preg_replace('/\s+/u', ' ', (string)$v));

    $round = pat_round_get((int)($d['round_id'] ?? 0));
    if (!$round || (string)$round['status'] !== 'open') return $no('Die Anmeldung ist nicht (mehr) geöffnet.');
    $role   = (string)($d['role'] ?? '');
    $degree = (string)($d['degree'] ?? '');
    if (!in_array($role, ['ersti', 'pate'], true)) return $no('Unbekannte Rolle.');
    if (!isset(pat_degrees()[$degree])) return $no('Unbekannter Abschluss.');

    $courseId   = (int)($d['course_id'] ?? 0);
    $courseFree = $norm($d['course_free'] ?? '');
    $parentId   = (int)($d['course_parent_id'] ?? 0);
    if ($courseId > 0) {
        $c = pat_course_get($courseId);
        if (!$c || (int)$c['active'] !== 1 || (string)$c['degree'] !== $degree) return $no('Ungültiger Studiengang.');
        $parentId = 0; // aus der Liste gewählt – die Kategorie steckt schon im Studiengang selbst
    } elseif ($courseFree === '' || mb_strlen($courseFree) > 120) {
        return $no('Bitte einen Studiengang wählen oder eintragen.');
    } elseif ($parentId > 0) {
        // Freitext UNTER einem Studiengang: die Zugehörigkeit zur Kategorie festhalten.
        $p = pat_course_get($parentId);
        if (!$p || (int)$p['parent_id'] !== 0 || (string)$p['degree'] !== $degree) $parentId = 0;
    }
    $courseLabel = $norm($d['course_label'] ?? '');
    if ($courseLabel === '' || mb_strlen($courseLabel) > 200) return $no('Ungültige Studiengangs-Angabe.');

    $first = $norm($d['first_name'] ?? '');
    $last  = $norm($d['last_name'] ?? '');
    if ($first === '' || $last === '' || mb_strlen($first) > 60 || mb_strlen($last) > 60) {
        return $no('Bitte Vor- und Nachnamen angeben (jeweils bis 60 Zeichen).');
    }
    $email = trim((string)($d['email'] ?? ''));
    if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $email)) {
        return $no('Bitte eine gültige E-Mail-Adresse angeben.');
    }
    $about = trim((string)($d['about'] ?? ''));
    if (mb_strlen($about) > 2000) return $no('„Über mich" darf höchstens 2000 Zeichen lang sein.');
    if ($role === 'pate' && $about === '') return $no('Bitte stell dich kurz vor – das Feld „Über mich" gehört bei Pat:innen dazu.');

    try {
        pat_db()->prepare('INSERT INTO pat_signups(round_id, role, degree, course_id, course_parent_id,
                course_free, course_label, first_name, last_name, email, about)
            VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int)$round['id'], $role, $degree, $courseId > 0 ? $courseId : null,
                $parentId > 0 ? $parentId : null,
                $courseFree, $courseLabel, $first, $last, $email, $about]);
        $newId = (int)pat_db()->lastInsertId();
        // Weitere Fächer nur bei Pat:innen – und nur, was pat_signup_extras_set durchlässt.
        if ($role === 'pate' && !empty($d['extra_ids'])) {
            pat_signup_extras_set($newId, (array)$d['extra_ids']);
        }
        return ['ok' => true, 'id' => $newId, 'error' => '', 'dupe' => false];
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return $no('dupe', true);
        throw $e;
    }
}

/** Anmeldezahlen eines Programms: ['ersti' => n, 'pate' => n]. */
function pat_signup_counts(int $roundId): array
{
    $out = ['ersti' => 0, 'pate' => 0];
    $st = pat_db()->prepare('SELECT role, COUNT(*) n FROM pat_signups WHERE round_id = ? GROUP BY role');
    $st->execute([$roundId]);
    foreach ($st->fetchAll() as $row) {
        if (isset($out[$row['role']])) $out[$row['role']] = (int)$row['n'];
    }
    return $out;
}

/**
 * Weitere Fächer an Anmeldungen hängen – in EINER Abfrage für den ganzen Stapel.
 *
 * Jede Zeile bekommt 'extra_ids' (Liste von Studiengangs-IDs). Das geschieht schon beim Laden,
 * damit die Einteilung nie mitten in ihrer Doppelschleife nachschlagen muss: Pro Paarung in
 * die Datenbank zu gehen kostet Sekunden (dieselbe Regel wie bei pat_course_get()).
 */
function pat_extras_attach(array $rows): array
{
    foreach ($rows as $i => $r) $rows[$i]['extra_ids'] = [];
    $ids = array_values(array_filter(array_map(fn ($r) => (int)($r['id'] ?? 0), $rows)));
    if (!$ids) return $rows;
    $byId = [];
    foreach ($rows as $i => $r) $byId[(int)$r['id']] = $i;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = pat_db()->prepare("SELECT signup_id, course_id FROM pat_signup_subjects WHERE signup_id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $i = $byId[(int)$row['signup_id']] ?? null;
        if ($i !== null) $rows[$i]['extra_ids'][] = (int)$row['course_id'];
    }
    return $rows;
}

/** Alle Anmeldungen eines Programms, optional auf eine Rolle gefiltert. */
function pat_signups_of(int $roundId, ?string $role = null): array
{
    $sql = 'SELECT * FROM pat_signups WHERE round_id = ?';
    $p = [$roundId];
    if ($role !== null) { $sql .= ' AND role = ?'; $p[] = $role; }
    $sql .= ' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE';
    $st = pat_db()->prepare($sql);
    $st->execute($p);
    return pat_extras_attach($st->fetchAll());
}

function pat_signup_get(int $id): ?array
{
    if ($id <= 0) return null;
    $st = pat_db()->prepare('SELECT * FROM pat_signups WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) return null;
    return pat_extras_attach([$s])[0];
}

/**
 * Welche weiteren Fächer darf diese Anmeldung überhaupt angeben?
 *
 * Ausschließlich die Unterpunkte DESSELBEN Studiengangs – die Studiengangsgrenze wird nie
 * überschritten. Das eigene Fach ist raus, es steckt schon in der Hauptwahl. Wer einen
 * Studiengang ohne Unterpunkte gewählt hat, bekommt eine leere Liste (und damit keinen Schritt).
 */
function pat_extras_choices(array $signup): array
{
    $top = pat_signup_top_course($signup);
    if ($top <= 0) return [];
    $own = (int)($signup['course_id'] ?? 0);
    return array_values(array_filter(
        pat_course_children($top),
        fn (array $k) => (int)$k['id'] !== $own
    ));
}

/**
 * Weitere Fächer einer Pat:in setzen (ersetzt die bisherigen). Alles, was nicht unter ihrem
 * eigenen Studiengang liegt, wird stillschweigend verworfen – die Prüfung gehört hierher und
 * nicht in die Seite, damit auch eine gebastelte Adresse nichts Fremdes hereinträgt.
 * Gibt die Zahl der tatsächlich gespeicherten Fächer zurück.
 */
function pat_signup_extras_set(int $signupId, array $courseIds): int
{
    $s = pat_signup_get($signupId);
    if (!$s) return 0;
    $erlaubt = [];
    foreach (pat_extras_choices($s) as $k) $erlaubt[(int)$k['id']] = true;
    $ids = [];
    foreach ($courseIds as $cid) {
        $cid = (int)$cid;
        if (isset($erlaubt[$cid])) $ids[$cid] = true;
    }
    pat_db()->prepare('DELETE FROM pat_signup_subjects WHERE signup_id = ?')->execute([$signupId]);
    if ($ids) {
        $ins = pat_db()->prepare('INSERT INTO pat_signup_subjects(signup_id, course_id) VALUES(?, ?)');
        foreach (array_keys($ids) as $cid) $ins->execute([$signupId, $cid]);
    }
    return count($ids);
}

/** Namen der weiteren Fächer einer Anmeldung, alphabetisch – für Anzeige, Tabelle und CSV. */
function pat_signup_extra_labels(array $signup): array
{
    $out = [];
    foreach ((array)($signup['extra_ids'] ?? []) as $cid) {
        $c = pat_course_get((int)$cid);
        if ($c) $out[] = (string)$c['name'];
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/** Anmeldung löschen. Eine Pat:in nimmt ihre Gruppe nicht mit – deren Erstis werden frei. */
function pat_signup_delete(int $id): bool
{
    $s = pat_signup_get($id);
    if (!$s) return false;
    if ((string)$s['role'] === 'pate') {
        pat_db()->prepare('UPDATE pat_signups SET pate_id = NULL WHERE pate_id = ?')->execute([$id]);
    }
    pat_db()->prepare('DELETE FROM pat_signups WHERE id = ?')->execute([$id]);
    return true;
}

// ---------------------------------------------------------------------------
// Einteilung in Gruppen
// ---------------------------------------------------------------------------

/**
 * Oberste Studiengangs-Ebene einer Anmeldung. Bei einem Unterpunkt ist das sein Studiengang,
 * bei einem FREI eingetragenen Unterpunkt der Studiengang, unter dem er eingetragen wurde –
 * so bleiben „Gymnasiallehramt – Latein" und „Gymnasiallehramt – Deutsch" verwandt.
 */
function pat_signup_top_course(array $s): int
{
    $cid = (int)($s['course_id'] ?? 0);
    if ($cid <= 0) return (int)($s['course_parent_id'] ?? 0);
    $c = pat_course_get($cid);
    if (!$c) return 0;
    return (int)$c['parent_id'] > 0 ? (int)$c['parent_id'] : $cid;
}

/**
 * Fachname einer Anmeldung, klein geschrieben – für den Vergleich ÜBER Abschlussgrenzen hinweg.
 * „Psychologie" im Bachelor und im Master sind zwei getrennte Zeilen der Studiengangsliste;
 * ohne diesen Vergleich wären sie füreinander so fremd wie zwei beliebige Fächer.
 */
function pat_signup_subject(array $s): string
{
    $cid = (int)($s['course_id'] ?? 0);
    if ($cid > 0) {
        $c = pat_course_get(pat_signup_top_course($s));
        if ($c) return mb_strtolower(trim((string)$c['name']));
    }
    return mb_strtolower(trim((string)($s['course_free'] ?? '')));
}

/**
 * Vergleichs-Merkmale einer Anmeldung EINMAL bestimmen. Die Einteilung vergleicht jede:n
 * Ersti mit jeder Pat:in – ohne diese Vorberechnung stünde in der inneren Schleife je Paarung
 * eine Datenbankabfrage (bei 300 × 60 waren das messbare Sekunden).
 */
function pat_match_key(array $s): array
{
    return [
        'cid'  => (int)($s['course_id'] ?? 0),
        'top'  => pat_signup_top_course($s),   // deckt auch Freitext unter einem Studiengang ab
        'subj' => pat_signup_subject($s),
        'deg'  => (string)($s['degree'] ?? ''),
        // Weitere Fächer der Pat:in als Nachschlagewerk (Fach-ID => true), damit die innere
        // Schleife der Einteilung nur ein isset() braucht statt eine Suche in einer Liste.
        'extra' => array_fill_keys(array_map('intval', (array)($s['extra_ids'] ?? [])), true),
    ];
}

/**
 * Ab dieser Stufe gibt es fachlich eine Gemeinsamkeit. Darunter (nur gleicher Abschluss oder
 * gar nichts) wird NICHT automatisch zugeteilt – lieber eine sichtbare Lücke, die jemand von
 * Hand schließt, als eine stille Fehlzuteilung.
 */
const PAT_MATCH_MIN = 2;

/** Passgenauigkeit aus zwei vorberechneten Merkmalssätzen. */
function pat_match_score_keys(array $e, array $p): int
{
    if ($e['cid'] > 0 && $e['cid'] === $p['cid']) return 6;
    // Das Fach des Erstis steht in der Zweitfach-Liste der Pat:in. Weil dort nur Unterpunkte
    // des eigenen Studiengangs stehen dürfen, ist der Studiengang damit automatisch derselbe –
    // der Treffer ist also immer mindestens so gut wie die Stufe darunter.
    if ($e['cid'] > 0 && isset($p['extra'][$e['cid']])) return 5;
    if ($e['top'] > 0 && $e['top'] === $p['top']) return 4;
    $sameDegree  = $e['deg'] === $p['deg'];
    $sameSubject = $e['subj'] !== '' && $e['subj'] === $p['subj'];
    if ($sameSubject) return $sameDegree ? 3 : 2;
    return $sameDegree ? 1 : 0;
}

/**
 * Passgenauigkeit zwischen Ersti und Pat:in – je höher, desto besser. Die Skala ist bewusst
 * monoton „fachlich": ab PAT_MATCH_MIN gibt es eine echte Gemeinsamkeit, darunter nicht.
 *   6 exakt dieselbe Auswahl (inkl. Erstfach)
 *   5 das Fach des Erstis ist eines der weiteren Fächer der Pat:in
 *   4 gleicher Studiengang (auch bei frei eingetragenem Unterpunkt)
 *   3 gleiches Fach, gleicher Abschluss
 *   2 gleiches Fach, anderer Abschluss      ← Untergrenze für die automatische Zuteilung
 *   1 nur gleicher Abschluss (fachfremd)
 *   0 nichts gemeinsam
 */
function pat_match_score(array $ersti, array $pate): int
{
    return pat_match_score_keys(pat_match_key($ersti), pat_match_key($pate));
}

/**
 * Klartext zu einer Passgenauigkeit: Wo wurde ein Kompromiss gemacht?
 *
 * Die Automatik teilt notfalls unterhalb der Wunschgenauigkeit zu (ein Lehramts-Ersti mit
 * Erstfach Deutsch landet bei einer Lehramts-Pat:in mit Englisch, wenn es keine Deutsch-Pat:in
 * gibt). Das ist gewollt, soll aber SICHTBAR sein – deshalb hier zentral beschrieben, damit
 * Gruppenansicht, Tabelle und CSV nicht auseinanderlaufen.
 *
 * 'level': ''  = kein Kompromiss (exakt)
 *          'info' = im selben Studiengang verschoben
 *          'warn' = anderer Abschluss – Untergrenze der Automatik
 *          'bad'  = fachfremd, kann nur von Hand entstanden sein
 * Gibt ['text','title','level'] zurück; 'text' ist bei level '' leer.
 */
function pat_match_note(int $score): array
{
    switch ($score) {
        case 6: return ['text' => '', 'title' => 'Genau die gewählte Kombination', 'level' => ''];
        case 5: return ['text' => 'über Zweitfach', 'level' => 'info',
            'title' => 'Nicht das Erstfach der Pat:in, aber eines ihrer weiteren Fächer'];
        case 4: return ['text' => 'anderer Unterpunkt', 'level' => 'info',
            'title' => 'Gleicher Studiengang, aber ein anderes Erstfach bzw. anderer Unterpunkt'];
        case 3: return ['text' => 'gleiches Fach, andere Auswahl', 'level' => 'info',
            'title' => 'Gleiches Fach und gleicher Abschluss, aber nicht derselbe Eintrag (etwa frei eingetragen)'];
        case 2: return ['text' => 'anderer Abschluss', 'level' => 'warn',
            'title' => 'Gleiches Fach, aber Bachelor/Master unterschiedlich'];
        case 1: return ['text' => 'fachfremd', 'level' => 'bad',
            'title' => 'Nur der Abschluss stimmt überein – von Hand gesetzt'];
        default: return ['text' => 'fachfremd', 'level' => 'bad',
            'title' => 'Keine fachliche Gemeinsamkeit – von Hand gesetzt'];
    }
}

/**
 * Kompromisse eines Programms zählen: wie viele Erstis sitzen NICHT in ihrer exakten Auswahl?
 * Gibt ['exakt','info','warn','bad','summe'] über alle zugeteilten Erstis zurück
 * ($groups ist das Ergebnis von pat_groups_of()).
 */
function pat_match_overview(array $groups): array
{
    $out = ['exakt' => 0, 'info' => 0, 'warn' => 0, 'bad' => 0, 'summe' => 0];
    foreach ($groups['groups'] ?? [] as $grp) {
        foreach ($grp['erstis'] as $e) {
            $lvl = pat_match_note(pat_match_score($e, $grp['pate']))['level'];
            $out[$lvl === '' ? 'exakt' : $lvl]++;
            $out['summe']++;
        }
    }
    return $out;
}

/** Eine Person von Hand zuteilen ($pateId = null → aus der Gruppe nehmen). */
function pat_assign_set(int $erstiId, ?int $pateId): bool
{
    $e = pat_signup_get($erstiId);
    if (!$e || (string)$e['role'] !== 'ersti') return false;
    if ($pateId !== null) {
        $p = pat_signup_get($pateId);
        if (!$p || (string)$p['role'] !== 'pate' || (int)$p['round_id'] !== (int)$e['round_id']) return false;
    }
    pat_db()->prepare('UPDATE pat_signups SET pate_id = ? WHERE id = ?')->execute([$pateId, $erstiId]);
    return true;
}

/** Ganze Einteilung eines Programms zurücksetzen. Gibt die Zahl gelöster Zuteilungen zurück. */
function pat_assign_clear(int $roundId): int
{
    $st = pat_db()->prepare('UPDATE pat_signups SET pate_id = NULL WHERE round_id = ? AND pate_id IS NOT NULL');
    $st->execute([$roundId]);
    return $st->rowCount();
}

/**
 * Automatische Einteilung. Die Gruppengröße ist ein WUNSCH, kein Gesetz: Grundlage ist die
 * Wunschspanne des Programms (group_min/group_max), aber die Verteilung passt sich dem
 * tatsächlichen Angebot an – gibt es zu wenige Pat:innen, werden die Gruppen größer als
 * group_max (statt Erstis übrig zu lassen); gibt es viele, bleiben sie kleiner.
 *
 * $onlyNew = true lässt bestehende (auch von Hand gesetzte) Zuteilungen unangetastet und
 * verteilt nur die noch freien Erstis – das ist der Nachrücker-Fall.
 *
 * Gibt ['ok','assigned','cap','note','error'] zurück.
 */
function pat_assign_auto(int $roundId, bool $onlyNew = false): array
{
    $round = pat_round_get($roundId);
    if (!$round) return ['ok' => false, 'assigned' => 0, 'unmatched' => 0, 'cap' => 0, 'note' => '', 'error' => 'Programm nicht gefunden.'];

    $pates  = pat_signups_of($roundId, 'pate');
    $erstis = pat_signups_of($roundId, 'ersti');
    if (!$pates)  return ['ok' => false, 'assigned' => 0, 'unmatched' => 0, 'cap' => 0, 'note' => '', 'error' => 'Es sind noch keine Pat:innen angemeldet.'];
    if (!$erstis) return ['ok' => false, 'assigned' => 0, 'unmatched' => 0, 'cap' => 0, 'note' => '', 'error' => 'Es sind noch keine Erstsemester angemeldet.'];

    // Aktuelle Belegung: beim Neu-Verteilen zählt nur, was stehen bleiben soll.
    $size = [];
    foreach ($pates as $p) $size[(int)$p['id']] = 0;
    $todo = [];
    foreach ($erstis as $e) {
        $pid = (int)($e['pate_id'] ?? 0);
        if ($onlyNew && $pid > 0 && isset($size[$pid])) { $size[$pid]++; continue; }
        $todo[] = $e;
    }
    if (!$todo) return ['ok' => true, 'assigned' => 0, 'unmatched' => 0, 'cap' => 0, 'note' => 'Alle Erstsemester waren schon eingeteilt.', 'error' => ''];

    // Zielgröße: Wunschspanne, aber dem Angebot angepasst.
    $min  = max(1, (int)$round['group_min']);
    $max  = max($min, (int)$round['group_max']);
    $base = (int)ceil(count($erstis) / count($pates));
    $cap  = max($min, min($max, $base));
    $note = '';
    if ($base > $max) {
        $cap  = $base; // zu wenige Pat:innen → größere Gruppen statt übrig gebliebener Erstis
        $note = 'Für ' . count($erstis) . ' Erstsemester stehen nur ' . count($pates) . ' Pat:innen bereit – '
              . 'die Gruppen wurden auf bis zu ' . $cap . ' vergrößert (Wunsch war maximal ' . $max . ').';
    } elseif ($base < $min) {
        $note = 'Es sind mehr Pat:innen als nötig da – die Gruppen bleiben mit ' . $cap
              . ' kleiner als die Wunsch-Untergrenze von ' . $min . '.';
    }

    // Vergleichs-Merkmale einmal vorberechnen (statt je Paarung neu aus der Datenbank).
    $pKey = [];
    foreach ($pates as $p) $pKey[(int)$p['id']] = pat_match_key($p);
    $eKey = [];
    foreach ($todo as $e) $eKey[(int)$e['id']] = pat_match_key($e);

    // Seltene Studiengänge zuerst: sonst sind die passenden Pat:innen schon voll.
    $rarity = [];
    foreach ($todo as $e) {
        $n = 0;
        foreach ($pKey as $pk) if (pat_match_score_keys($eKey[(int)$e['id']], $pk) >= 4) $n++;
        $rarity[(int)$e['id']] = $n;
    }
    usort($todo, fn (array $a, array $b) => $rarity[(int)$a['id']] <=> $rarity[(int)$b['id']]);

    $assigned = 0; $unmatched = 0;
    $upd = pat_db()->prepare('UPDATE pat_signups SET pate_id = ? WHERE id = ?');
    pat_db()->beginTransaction();
    try {
        foreach ($todo as $e) {
            $ek = $eKey[(int)$e['id']];
            $best = null; $bestKey = null; $bestScore = 0;
            foreach ($pKey as $pid => $pk) {
                $sc   = pat_match_score_keys($ek, $pk);
                $full = $size[$pid] >= $cap ? 1 : 0;             // volle Gruppen erst als letzte Wahl
                $key  = [$full, -$sc, $size[$pid], $pid];
                if ($bestKey === null || $key < $bestKey) { $bestKey = $key; $best = $pid; $bestScore = $sc; }
            }
            // Fachlich nichts gemeinsam? Dann lieber ohne Gruppe lassen – das fällt in der
            // Verwaltung auf und wird von Hand entschieden, statt still falsch zuzuteilen.
            if ($best === null || $bestScore < PAT_MATCH_MIN) {
                $upd->execute([null, (int)$e['id']]);
                $unmatched++;
                continue;
            }
            $upd->execute([$best, (int)$e['id']]);
            $size[$best]++;
            $assigned++;
        }
        pat_db()->commit();
    } catch (\Throwable $ex) {
        pat_db()->rollBack();
        throw $ex;
    }
    if ($unmatched > 0) {
        $note = trim($note . ' ' . $unmatched . ' Erstsemester wurden bewusst NICHT zugeteilt – '
            . 'zu ihrem Fach gibt es keine passende Pat:in. Sie stehen unter „Ohne Gruppe" und '
            . 'wollen von Hand entschieden werden.');
    }
    return ['ok' => true, 'assigned' => $assigned, 'unmatched' => $unmatched,
        'cap' => $cap, 'note' => $note, 'error' => ''];
}

/** Gruppen eines Programms: je Pat:in ihre Erstis, plus die noch nicht Zugeteilten. */
function pat_groups_of(int $roundId): array
{
    $groups = [];
    foreach (pat_signups_of($roundId, 'pate') as $p) {
        $groups[(int)$p['id']] = ['pate' => $p, 'erstis' => []];
    }
    $free = [];
    foreach (pat_signups_of($roundId, 'ersti') as $e) {
        $pid = (int)($e['pate_id'] ?? 0);
        if ($pid > 0 && isset($groups[$pid])) $groups[$pid]['erstis'][] = $e;
        else $free[] = $e;
    }
    return ['groups' => $groups, 'free' => $free];
}

/** Kennzahlen eines Programms für die Verwaltungsseite. */
function pat_round_stats(int $roundId): array
{
    $g = pat_groups_of($roundId);
    $sizes = array_map(fn ($x) => count($x['erstis']), $g['groups']);
    $erstis = array_sum($sizes) + count($g['free']);
    return [
        'pates'      => count($g['groups']),
        'erstis'     => $erstis,
        'assigned'   => array_sum($sizes),
        'free'       => count($g['free']),
        'empty'      => count(array_filter($sizes, fn ($n) => $n === 0)),
        'size_min'   => $sizes ? min($sizes) : 0,
        'size_max'   => $sizes ? max($sizes) : 0,
    ];
}

// ---------------------------------------------------------------------------
// Einteilungs-Mails (Warteschlange)
// ---------------------------------------------------------------------------

/**
 * Einteilungs-Mails für ein Programm zusammenstellen und einstellen.
 * $role: 'ersti' | 'pate' | 'both'. Verschickt wird hier NICHTS – das macht pat_mail_run()
 * in kleinen Schüben. Bereits wartende Mails DERSELBEN ART werden vorher verworfen, damit ein
 * zweiter Klick keine Dubletten erzeugt (schon versandte bleiben stehen); wartende Absagen
 * bleiben unangetastet – sonst würde ein Klick den jeweils anderen Vorgang stillschweigend
 * abräumen.
 * Gibt ['ok','queued','skipped','error'] zurück; skipped = ohne Gruppe, bekommt nichts.
 */
function pat_mail_queue_assignments(int $roundId, string $role = 'both'): array
{
    $round = pat_round_get($roundId);
    if (!$round) return ['ok' => false, 'queued' => 0, 'skipped' => 0, 'error' => 'Programm nicht gefunden.'];
    if (!in_array($role, ['ersti', 'pate', 'both'], true)) {
        return ['ok' => false, 'queued' => 0, 'skipped' => 0, 'error' => 'Unbekannte Rolle.'];
    }
    // Zeitplan-Platzhalter gelten in JEDEM Text – siehe pat_round_vars().
    $base = pat_round_vars($round);
    $g = pat_groups_of($roundId);

    // Die Gruppen-Mail (EINE je Gruppe, alle im An/CC) hängt an 'both': Wer gezielt nur eine
    // Rolle neu einreiht, wiederholt damit nicht ungefragt die gemeinsame Kennenlern-Mail.
    pat_mail_forget_queued($roundId, $role === 'both' ? ['assign_pate', 'assign_ersti', 'group']
        : ($role === 'pate' ? ['assign_pate'] : ['assign_ersti']));
    $ins = pat_db()->prepare('INSERT INTO pat_mailqueue(round_id, signup_id, kind, to_email, cc, subject, body) VALUES(?,?,?,?,?,?,?)');
    $queued = 0; $skipped = count($g['free']);

    // EIN Listenbau für alle drei Mails (Pat:in, Ersti-{{GRUPPE}}, Gruppen-Mail) – sonst
    // driften die Formate auseinander. $rolle ergänzt hinter dem Studiengang einen Zusatz.
    $zeile = function (array $s, string $rolle = ''): string {
        $z = '• ' . $s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['email'] . ')'
           . ' – ' . $s['course_label'] . ($rolle !== '' ? ' – ' . $rolle : '') . "\n";
        if (trim((string)$s['about']) !== '') $z .= '  ' . trim((string)$s['about']) . "\n";
        return $z . "\n";
    };

    foreach ($g['groups'] as $grp) {
        $p = $grp['pate'];
        if ($role !== 'ersti') {
            if (!$grp['erstis']) {                     // leere Gruppe: nichts zu melden
                $skipped++;
            } else {
                $liste = '';
                foreach ($grp['erstis'] as $e) $liste .= $zeile($e);
                $vars = $base + ['{{VORNAME}}' => (string)$p['first_name'],
                    '{{ANZAHL}}' => (string)count($grp['erstis']), '{{LISTE}}' => rtrim($liste)];
                $ins->execute([$roundId, (int)$p['id'], 'assign_pate', (string)$p['email'], '',
                    pat_text_fill(pat_text('mail_pate_subject'), $vars),
                    pat_text_fill(pat_text('mail_pate_body'), $vars)]);
                $queued++;
            }
        }
        if ($role !== 'pate') {
            foreach ($grp['erstis'] as $e) {
                // {{GRUPPE}}: die ANDEREN Erstis der Gruppe – die ganze Gruppe lernt sich
                // kennen, jede:r bekommt die Kontaktdaten aller. Die Pat:in
                // steht schon oben in der Mail und taucht hier nicht doppelt auf.
                $gruppe = '';
                foreach ($grp['erstis'] as $e2) {
                    if ((int)$e2['id'] !== (int)$e['id']) $gruppe .= $zeile($e2);
                }
                if ($gruppe === '') $gruppe = '(Diesmal bist du das einzige Erstsemester deiner Gruppe.)';
                $vars = $base + ['{{VORNAME}}' => (string)$e['first_name'],
                    '{{PATIN}}' => $p['first_name'] . ' ' . $p['last_name'],
                    '{{PATIN_MAIL}}' => (string)$p['email'],
                    '{{PATIN_UEBER}}' => trim((string)$p['about']),
                    '{{PATIN_FACH}}' => (string)$p['course_label'],
                    '{{GRUPPE}}' => rtrim($gruppe)];
                $ins->execute([$roundId, (int)$e['id'], 'assign_ersti', (string)$e['email'], '',
                    pat_text_fill(pat_text('mail_ersti_subject'), $vars),
                    pat_text_fill(pat_text('mail_ersti_body'), $vars)]);
                $queued++;
            }
        }
        // Gemeinsame Kennenlern-Mail: EINE je Gruppe, Pat:in im An, alle Erstis im CC – jede:r
        // sieht alle Adressen, und „Allen antworten" startet den Gruppen-Faden. Ein leerer
        // Vorlagen-Text schaltet sie ab (Register-Philosophie: leer = weglassen).
        if ($role === 'both' && $grp['erstis'] && trim(pat_text('mail_gruppe_body')) !== '') {
            $alle = $zeile($p, 'eure Pat:in');
            foreach ($grp['erstis'] as $e) $alle .= $zeile($e);
            $vars = $base + ['{{PATIN}}' => $p['first_name'] . ' ' . $p['last_name'],
                '{{ANZAHL}}' => (string)count($grp['erstis']), '{{LISTE}}' => rtrim($alle)];
            $cc = implode(', ', array_map(fn ($e) => (string)$e['email'], $grp['erstis']));
            $ins->execute([$roundId, (int)$p['id'], 'group', (string)$p['email'], $cc,
                pat_text_fill(pat_text('mail_gruppe_subject'), $vars),
                pat_text_fill(pat_text('mail_gruppe_body'), $vars)]);
            $queued++;
        }
    }
    return ['ok' => true, 'queued' => $queued, 'skipped' => $skipped, 'error' => ''];
}

/**
 * So lange darf eine beanspruchte Mail ('sending') stehen bleiben, bevor der nächste Lauf sie
 * wieder freigibt. Muss deutlich über dem Zeitbudget eines Laufs liegen (Standard 20 s) und
 * deutlich unter dem Cron-Takt (1 h), damit nichts liegen bleibt und nichts doppelt rausgeht.
 */
const PAT_MAIL_CLAIM_MINUTES = 10;

/**
 * Wartende Mails bestimmter Arten eines Programms verwerfen (schon versandte bleiben stehen).
 * Getrennt nach `kind`, damit „Einteilung vorbereiten" und „Absagen vorbereiten" sich nicht
 * gegenseitig aus der Warteschlange werfen.
 */
function pat_mail_forget_queued(int $roundId, array $kinds): int
{
    if (!$kinds) return 0;
    $in = implode(',', array_fill(0, count($kinds), '?'));
    // 'sending' bleibt stehen: diese Mails sind gerade unterwegs, die reißt man nicht weg.
    $st = pat_db()->prepare("DELETE FROM pat_mailqueue WHERE round_id = ? AND status = 'queued' AND kind IN ($in)");
    $st->execute(array_merge([$roundId], array_values($kinds)));
    return $st->rowCount();
}

/**
 * Wer würde eine ABSAGE bekommen? Gibt ['pates' => [...], 'erstis' => [...]] mit den vollen
 * Anmeldezeilen zurück – dieselbe Quelle für die Vorschau im Bestätigungsdialog und für das
 * tatsächliche Einreihen, damit die Liste nicht das eine zeigt und das andere verschickt.
 *
 *   • Pat:innen mit LEERER Gruppe – haben sich gemeldet, werden aber nicht gebraucht.
 *   • Erstsemester OHNE Zuteilung – für sie war keine fachlich passende Pat:in da
 *     (die Automatik teilt unterhalb von PAT_MATCH_MIN bewusst nicht zu).
 */
function pat_rejection_lists(int $roundId): array
{
    $g = pat_groups_of($roundId);
    $pates = [];
    foreach ($g['groups'] as $grp) if (!$grp['erstis']) $pates[] = $grp['pate'];
    return ['pates' => $pates, 'erstis' => array_values($g['free'])];
}

/**
 * Absagen in die Warteschlange stellen. Gleicher Apparat wie die Einteilungs-Mails (Kontingent,
 * Cron, Fehlversuche) – nur andere Vorlagen und Empfänger. $role: 'ersti' | 'pate' | 'both'.
 * Gibt ['ok','queued','error'] zurück.
 */
function pat_mail_queue_rejections(int $roundId, string $role = 'both'): array
{
    $round = pat_round_get($roundId);
    if (!$round) return ['ok' => false, 'queued' => 0, 'error' => 'Programm nicht gefunden.'];
    if (!in_array($role, ['ersti', 'pate', 'both'], true)) {
        return ['ok' => false, 'queued' => 0, 'error' => 'Unbekannte Rolle.'];
    }
    // Zeitplan-Platzhalter gelten in JEDEM Text – siehe pat_round_vars().
    $base = pat_round_vars($round);
    $lists = pat_rejection_lists($roundId);

    pat_mail_forget_queued($roundId, $role === 'both' ? ['reject_pate', 'reject_ersti']
        : ($role === 'pate' ? ['reject_pate'] : ['reject_ersti']));
    $ins = pat_db()->prepare('INSERT INTO pat_mailqueue(round_id, signup_id, kind, to_email, subject, body) VALUES(?,?,?,?,?,?)');
    $queued = 0;

    if ($role !== 'ersti') {
        foreach ($lists['pates'] as $p) {
            $vars = $base + ['{{VORNAME}}' => (string)$p['first_name'],
                '{{FACH}}' => (string)$p['course_label']];
            $ins->execute([$roundId, (int)$p['id'], 'reject_pate', (string)$p['email'],
                pat_text_fill(pat_text('mail_rej_pate_subject'), $vars),
                pat_text_fill(pat_text('mail_rej_pate_body'), $vars)]);
            $queued++;
        }
    }
    if ($role !== 'pate') {
        foreach ($lists['erstis'] as $e) {
            $vars = $base + ['{{VORNAME}}' => (string)$e['first_name'],
                '{{FACH}}' => (string)$e['course_label']];
            $ins->execute([$roundId, (int)$e['id'], 'reject_ersti', (string)$e['email'],
                pat_text_fill(pat_text('mail_rej_ersti_subject'), $vars),
                pat_text_fill(pat_text('mail_rej_ersti_body'), $vars)]);
            $queued++;
        }
    }
    return ['ok' => true, 'queued' => $queued, 'error' => ''];
}

/**
 * Absender, Antwortadresse und Anzeigename für die Einteilungs-Mails bestimmen.
 *
 * Die App-Adresse kommt von außen herein ($appFrom) – so bleibt diese Datei frei von lib.php.
 * Zurück kommen ['from','reply','name']; verschickt wird hier nichts.
 *
 * Der Absender ist IMMER die App-Adresse, auch wenn die Paten-Mail auf derselben Domain liegt.
 * Verschickt wird ohnehin aus dem einen Postfach, das beim Hoster dafür eingerichtet ist – eine
 * andere Adresse in der From-Zeile würde nur behaupten, es käme woandersher (siehe
 * mail_pool_absender()). Die Antworten der Erstsemester sollen trotzdem bei den Verantwortlichen
 * ankommen und nicht im App-Postfach: dafür ist die Antwortadresse da, und die ist die Paten-Mail.
 * Der Anzeigename sagt zusätzlich, wer schreibt („AStA Pat:innenprogramm").
 */
function pat_mail_sender(string $appFrom): array
{
    $appFrom = trim($appFrom);
    if ($appFrom === '') $appFrom = mail_pool_absender();
    $name    = trim(pat_text('mail_from_name'));
    $paten   = trim(pat_setting_get('paten_email', ''));
    $out     = ['from' => $appFrom, 'reply' => $appFrom, 'name' => $name];

    if ($paten === '' || !filter_var($paten, FILTER_VALIDATE_EMAIL)) return $out;
    $out['reply'] = $paten;
    return $out;
}

/**
 * Wie viele Einteilungs-Mails sind in den letzten 24 Stunden tatsächlich rausgegangen?
 *
 * Hintergrund: Der Hoster lässt eine feste Zahl E-Mails je 24 Stunden durch (bei Mittwald 3000 im
 * freigeschaltet, davor 400). Aus
 * derselben Adresse gehen auch Login-Links, Mitteilungen, Umfragen und externe Events –
 * alle tragen in dasselbe Konto ein (mail-pool.php), und das Pat:innenprogramm nimmt sich,
 * was übrig ist. Gerechnet wird in einem GLEITENDEN Fenster, nicht in Kalendertagen: Wer um
 * 23 Uhr 300 Mails verschickt, darf um 0:01 nicht gleich 300 weitere.
 */
function pat_mail_sent_last24(): int
{
    return (int)pat_db()->query("SELECT COUNT(*) FROM pat_mailqueue
        WHERE status = 'sent' AND sent_at >= datetime('now','localtime','-24 hours')")->fetchColumn();
}

/**
 * Wie viele dürfen jetzt noch raus? Das ist der freie Rest im gemeinsamen Konto –
 * $dayCap ist nur ein freiwilliger eigener Deckel obendrauf (0 = keiner).
 */
function pat_mail_budget_left(int $dayCap = 0): int
{
    return mail_pool_budget('pat', max(0, $dayCap));
}

/** Was pro Tag höchstens fürs Pat:innenprogramm drin ist – Grundlage der Prognose. */
function pat_mail_day_allow(int $dayCap = 0): int
{
    return $dayCap > 0 ? min($dayCap, mail_pool_day_allow()) : mail_pool_day_allow();
}

/**
 * Wann wäre die Warteschlange voraussichtlich leer? Gibt die Zahl der nötigen weiteren
 * 24-Stunden-Fenster zurück (0 = heute noch), damit die Verwaltung ehrlich sagen kann,
 * wie lange es dauert.
 */
function pat_mail_days_needed(int $queued, int $dayCap = 0): int
{
    $heute = pat_mail_budget_left($dayCap);
    if ($queued <= $heute) return 0;
    return (int)ceil(($queued - $heute) / pat_mail_day_allow($dayCap));
}

/**
 * Zahlen der Warteschlange eines Programms.
 * 'sending' (gerade beansprucht, siehe pat_mail_run) zählt zu 'queued' – die Mail ist noch nicht
 * draußen, und für die Verwaltung ist der Unterschied belanglos.
 */
function pat_mail_stats(int $roundId): array
{
    $out = ['queued' => 0, 'sent' => 0, 'failed' => 0];
    $st = pat_db()->prepare('SELECT status, COUNT(*) n FROM pat_mailqueue WHERE round_id = ? GROUP BY status');
    $st->execute([$roundId]);
    foreach ($st->fetchAll() as $r) {
        $key = (string)$r['status'] === 'sending' ? 'queued' : (string)$r['status'];
        if (isset($out[$key])) $out[$key] += (int)$r['n'];
    }
    return $out;
}

/**
 * Wartende Mails in einem SCHUB verschicken.
 *
 * $sender bekommt (to, subject, body) und gibt true/false zurück – so bleibt diese Datei frei
 * von lib.php: den echten Versand reicht die aufrufende (interne) Seite herein.
 * $limit begrenzt den Schub; das ist der Schutz gegen Zeitlimits und die Versandgrenzen des
 * Hosters. Fehlgeschlagene Mails werden nach 3 Versuchen als 'failed' abgelegt statt endlos
 * wiederholt. Gibt ['sent','failed','left'] zurück.
 *
 * WETTLAUF: Der Cron läuft stündlich, und jemand kann gleichzeitig „Alle verschicken" klicken.
 * Ohne Absicherung lesen beide Läufe dieselben Zeilen und verschicken sie DOPPELT (gemessen:
 * 710 Versendungen für 355 Mails). Deshalb wird jede Mail vor dem Senden per UPDATE auf
 * 'sending' beansprucht; nur wer die Zeile ergattert, verschickt sie. Bleibt ein Lauf hängen,
 * gibt der nächste die Beanspruchung nach PAT_MAIL_CLAIM_MINUTES wieder frei.
 */
function pat_mail_run(int $limit, callable $sender, ?int $roundId = null, int $maxSeconds = 0): array
{
    $limit = max(1, min(5000, $limit));
    $until = $maxSeconds > 0 ? microtime(true) + $maxSeconds : 0.0;

    // Hängengebliebene Beanspruchungen freigeben: wenn ein Lauf abstürzt (Zeitlimit, Neustart),
    // bliebe die Mail sonst für immer auf 'sending' stehen und würde nie verschickt.
    pat_db()->exec("UPDATE pat_mailqueue SET status='queued', claimed_at=NULL
        WHERE status='sending' AND (claimed_at IS NULL
            OR claimed_at < datetime('now','localtime','-" . PAT_MAIL_CLAIM_MINUTES . " minutes'))");

    $sql = "SELECT * FROM pat_mailqueue WHERE status = 'queued'";
    $p = [];
    if ($roundId !== null) { $sql .= ' AND round_id = ?'; $p[] = $roundId; }
    $sql .= ' ORDER BY id LIMIT ' . $limit;
    $st = pat_db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();

    // Beansprucht eine Mail für DIESEN Lauf. Gibt false zurück, wenn ein anderer Lauf schneller
    // war – dann überspringen wir sie, statt sie ein zweites Mal zu verschicken.
    $claim = pat_db()->prepare("UPDATE pat_mailqueue SET status='sending', tries=tries+1,
        claimed_at=datetime('now','localtime') WHERE id=? AND status='queued'");

    $ok = 0; $bad = 0;
    foreach ($rows as $m) {
        // Zeitbudget: lieber sauber aufhören und den Rest liegen lassen, als mitten im
        // Versand ins Zeitlimit von PHP zu laufen (dann wüsste niemand, wie weit es kam).
        if ($until > 0.0 && microtime(true) >= $until) break;
        // Wettlauf-Schutz: nur wer die Zeile hier ergattert, verschickt sie. Sonst bekämen
        // Studierende ihre Mail doppelt, wenn Cron-Lauf und „Alle verschicken" sich überlappen.
        $claim->execute([(int)$m['id']]);
        if ($claim->rowCount() !== 1) continue;
        $good = false;
        try {
            // 4. Argument: CC der Gruppen-Mail (bei allen anderen Arten leer). PHP ignoriert
            // überzählige Argumente an eigene Funktionen – ältere Sender mit drei Parametern
            // laufen also unverändert weiter.
            $good = (bool)$sender((string)$m['to_email'], (string)$m['subject'], (string)$m['body'],
                (string)($m['cc'] ?? ''));
        } catch (\Throwable $e) {
            $good = false;
        }
        // tries wurde beim Beanspruchen schon erhöht
        $tries = (int)$m['tries'] + 1;
        if ($good) {
            pat_db()->prepare("UPDATE pat_mailqueue SET status='sent', last_error='',
                claimed_at=NULL, sent_at=datetime('now','localtime') WHERE id=?")->execute([(int)$m['id']]);
            $ok++;
        } else {
            pat_db()->prepare('UPDATE pat_mailqueue SET status=?, last_error=?, claimed_at=NULL WHERE id=?')
                ->execute([$tries >= 3 ? 'failed' : 'queued', 'Versand fehlgeschlagen', (int)$m['id']]);
            $bad++;
        }
    }
    // 'sending' zählt als offen: die Mail ist noch nicht draußen.
    $c = pat_db()->prepare("SELECT COUNT(*) FROM pat_mailqueue WHERE status IN ('queued','sending')"
        . ($roundId !== null ? ' AND round_id = ?' : ''));
    $c->execute($roundId !== null ? [$roundId] : []);
    return ['sent' => $ok, 'failed' => $bad, 'left' => (int)$c->fetchColumn()];
}

/** Warteschlange aufräumen: 'queued' (Abbruch), 'failed' (erneut versuchen) oder 'all'. */
function pat_mail_clear(int $roundId, string $what = 'queued'): int
{
    if ($what === 'failed') { // zurück in die Warteschlange statt löschen
        $st = pat_db()->prepare("UPDATE pat_mailqueue SET status='queued', tries=0 WHERE round_id=? AND status='failed'");
        $st->execute([$roundId]);
        return $st->rowCount();
    }
    $sql = 'DELETE FROM pat_mailqueue WHERE round_id = ?' . ($what === 'all' ? '' : " AND status='queued'");
    $st = pat_db()->prepare($sql);
    $st->execute([$roundId]);
    return $st->rowCount();
}

// ---------------------------------------------------------------------------
// Fragen aus dem Hilfe-Formular
//
// Die öffentliche Seite verschickt selbst KEINE Mail – sie kann es nicht einmal, weil dort
// bewusst keine App-Funktion geladen ist. Sie legt die Frage nur ab; der Versand-Cron holt sie
// und schickt sie mit der Adresse der fragenden Person als Antwortadresse an den AStA.
// Nebeneffekt, der uns entgegenkommt: scheitert der Versand, ist die Frage trotzdem da und in
// der Verwaltung zu sehen. Bei einem mailto:-Link wäre sie einfach weg gewesen.
// ---------------------------------------------------------------------------

/** So viele Fragen darf eine Sitzung je Stunde absetzen. */
const PAT_MSG_PER_HOUR = 5;
/** Erledigte Fragen werden nach so vielen Tagen gelöscht (sie enthalten Name und Adresse). */
const PAT_MSG_KEEP_DAYS = 120;

/**
 * Frage entgegennehmen. Gibt ['ok' => bool, 'error' => string] zurück; der Fehlertext ist
 * für die fragende Person gedacht und daher in Alltagssprache.
 *
 * Gebremst wird über die SITZUNG, nicht über die IP-Adresse: eine IP zu speichern wäre für
 * ein Kontaktformular unverhältnismäßig. Das genügt hier, weil ohne gültige Sitzung schon
 * die CSRF-Prüfung nicht durchkommt – wer die Bremse umgehen will, muss also mitspielen.
 */
function pat_message_add(array $d): array
{
    $no   = fn (string $err) => ['ok' => false, 'error' => $err];
    $norm = fn ($v) => trim((string)preg_replace('/\s+/u', ' ', (string)$v));

    $email = mb_strtolower($norm($d['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        return $no('Bitte eine E-Mail-Adresse angeben, unter der wir dich erreichen – sonst können wir nicht antworten.');
    }
    $name = $norm($d['name'] ?? '');
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

    // Nur Zeilenenden vereinheitlichen: der Text darf Absätze behalten.
    $body = trim((string)preg_replace('/\R/u', "\n", (string)($d['body'] ?? '')));
    if (mb_strlen($body) < 10) return $no('Schreib uns bitte noch ein, zwei Sätze mehr – so wissen wir nicht, worum es geht.');
    if (mb_strlen($body) > 5000) return $no('Das ist etwas viel für dieses Formular. Fasse es bitte kürzer – wir fragen dann nach.');

    // Programm nur festhalten, wenn es das wirklich gibt – die Nummer kommt aus der Seite.
    $round = (int)($d['round_id'] ?? 0);
    if ($round > 0 && !pat_round_get($round)) $round = 0;

    $st = pat_db()->prepare('INSERT INTO pat_messages (round_id, name, email, body, page) VALUES (?,?,?,?,?)');
    $st->execute([$round > 0 ? $round : null, $name, $email, $body,
                  mb_substr($norm($d['page'] ?? ''), 0, 120)]);
    return ['ok' => true, 'error' => ''];
}

/**
 * Darf diese Sitzung gerade noch eine Frage absetzen? Zählt in der Sitzung mit, nicht in der
 * Datenbank – die Bremse soll niemanden protokollieren.
 */
function pat_message_rate_ok(bool $zaehlen = true): bool
{
    $jetzt = time();
    $liste = array_values(array_filter((array)($_SESSION['pat_msg_times'] ?? []),
        fn ($t) => is_int($t) && $t > $jetzt - 3600));
    if (count($liste) >= PAT_MSG_PER_HOUR) {
        $_SESSION['pat_msg_times'] = $liste;
        return false;
    }
    if ($zaehlen) $liste[] = $jetzt;
    $_SESSION['pat_msg_times'] = $liste;
    return true;
}

/** Noch nicht verschickte Fragen, älteste zuerst. */
function pat_messages_pending(int $limit = 20): array
{
    $st = pat_db()->prepare("SELECT * FROM pat_messages WHERE status = 'neu' ORDER BY id LIMIT ?");
    $st->execute([max(1, $limit)]);
    return $st->fetchAll();
}

/** Ergebnis eines Versuchs festhalten. Ein Fehlschlag bleibt 'neu' – der nächste Lauf probiert
 *  es erneut; erst nach fünf Versuchen geben wir auf, damit die Schleife nicht ewig läuft. */
function pat_message_mark(int $id, bool $ok, string $error = ''): void
{
    if ($ok) {
        $st = pat_db()->prepare("UPDATE pat_messages SET status='gesendet', tries=tries+1,
                                 last_error='', sent_at=datetime('now','localtime') WHERE id=?");
        $st->execute([$id]);
        return;
    }
    $st = pat_db()->prepare("UPDATE pat_messages
                             SET status = CASE WHEN tries + 1 >= 5 THEN 'fehler' ELSE 'neu' END,
                                 tries = tries + 1, last_error = ? WHERE id = ?");
    $st->execute([mb_substr($error, 0, 300), $id]);
}

/** Die letzten Fragen für die Verwaltung. */
function pat_messages_recent(int $limit = 50): array
{
    $st = pat_db()->prepare('SELECT * FROM pat_messages ORDER BY id DESC LIMIT ?');
    $st->execute([max(1, $limit)]);
    return $st->fetchAll();
}

/** Aufgegebene Frage zurück in die Schlange (nachdem der Mailversand wieder läuft). */
function pat_message_retry(int $id): bool
{
    $st = pat_db()->prepare("UPDATE pat_messages SET status='neu', tries=0, last_error='' WHERE id=?");
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

/** Frage löschen – etwa wenn jemand um Löschung seiner Daten bittet. */
function pat_message_delete(int $id): bool
{
    $st = pat_db()->prepare('DELETE FROM pat_messages WHERE id = ?');
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

/** Wie viele Fragen warten noch auf eine Antwort-Mail? */
function pat_messages_open_count(): int
{
    return (int)pat_db()->query("SELECT COUNT(*) FROM pat_messages WHERE status IN ('neu','fehler')")->fetchColumn();
}

/**
 * Alte, erledigte Fragen wegräumen. Sie enthalten Name und Adresse – die bleiben nicht
 * für immer liegen, nur weil sie niemand mehr anschaut. Offene und fehlgeschlagene bleiben
 * stehen: die sind noch unbeantwortet.
 */
function pat_messages_prune(int $days = PAT_MSG_KEEP_DAYS): int
{
    $st = pat_db()->prepare("DELETE FROM pat_messages
                             WHERE status = 'gesendet' AND sent_at IS NOT NULL
                               AND sent_at < datetime('now','localtime','-' || ? || ' days')");
    $st->execute([max(1, $days)]);
    return $st->rowCount();
}

// ---------------------------------------------------------------------------
// Testdaten (Diagnose-Werkzeug – NIE im normalen Betrieb aufrufen)
// ---------------------------------------------------------------------------

/**
 * Alle Testdaten laufen über diese Mail-Endung. `.invalid` ist per RFC dauerhaft
 * unauflösbar: selbst wenn jemand versehentlich die Einteilungs-Mails abschickt,
 * kann keine davon bei einem echten Menschen landen.
 */
const PAT_TEST_DOMAIN = '@pat-test.invalid';

/** Ist das eine Testanmeldung? (Zum Erkennen und gezielten Wegräumen.) */
function pat_is_test_signup(array $s): bool
{
    return str_ends_with(mb_strtolower((string)($s['email'] ?? '')), PAT_TEST_DOMAIN);
}

/**
 * Großen Testdatensatz erzeugen: nutzt die WIRKLICH eingetragenen Studiengänge des Programms
 * und streut bewusst Grenzfälle ein, damit die Einteilung etwas zu beißen hat:
 *
 *   • Erstis in einem Fach, das KEINE Pat:in studiert (bleiben fachfremd zugeteilt)
 *   • ein Fach, das NUR über das Zweitfach einer Pat:in abgedeckt ist (Hinweis „über Zweitfach")
 *   • eine Pat:in in einem Fach, das kein Ersti hat (Gruppe bleibt leer)
 *   • frei eingetragene Studiengänge („wird von Hand zugeordnet")
 *   • Bachelor/Master über Kreuz, sehr lange Namen, Umlaute, Apostrophe,
 *     ein 2000-Zeichen-„Über mich", HTML/Script-Versuche in Namen (Escaping-Probe)
 *   • dieselbe Adresse einmal als Ersti und einmal als Pat:in (erlaubt)
 *
 * Gibt ['ok','erstis','pates','notes','error'] zurück.
 */
function pat_test_seed(int $roundId, int $erstis = 300, int $pates = 55): array
{
    $no = fn (string $e) => ['ok' => false, 'erstis' => 0, 'pates' => 0, 'notes' => [], 'error' => $e];
    $round = pat_round_get($roundId);
    if (!$round) return $no('Programm nicht gefunden.');
    if ((string)$round['status'] !== 'open') return $no('Bitte zuerst die Anmeldung öffnen – sonst nimmt die Anmeldelogik nichts an.');
    $erstis = max(1, min(2000, $erstis));
    $pates  = max(1, min(500, $pates));

    // Studiengänge des Programms einsammeln (Unterpunkte zählen als eigene Auswahl)
    $picks = [];
    foreach (pat_degrees() as $deg => $_) {
        foreach (pat_courses($deg) as $c) {
            $kids = pat_course_children((int)$c['id']);
            if ($kids) {
                $alle = array_map(fn ($x) => (int)$x['id'], $kids);
                foreach ($kids as $k) $picks[] = ['deg' => $deg, 'id' => (int)$k['id'],
                    'label' => $c['name'] . ' – ' . $k['name'],
                    // Die übrigen Fächer desselben Studiengangs – daraus zieht der Seed die
                    // weiteren Fächer der Pat:innen (mehr darf es dort ohnehin nicht geben).
                    'geschwister' => array_values(array_diff($alle, [(int)$k['id']]))];
            } else {
                $picks[] = ['deg' => $deg, 'id' => (int)$c['id'], 'label' => (string)$c['name'],
                    'geschwister' => []];
            }
        }
    }
    if (!$picks) return $no('Es sind noch keine Studiengänge hinterlegt – ohne die gibt es nichts zu verteilen.');

    $vor = ['Lena','Jonas','Emma','Luca','Mia','Finn','Hannah','Paul','Sophie','Ben','Lea','Noah',
            'Marie','Elias','Anna','Leon','Clara','Tim','Nele','Jan','Zoé','Yannick','Ida','Moritz'];
    $nach = ['Müller','Schmidt','Weber','Fischer','Wagner','Becker','Hoffmann','Schäfer','Koch',
             'Bauer','Richter','Klein','Wolf','Neumann','Schwarz','Zimmermann','Braun','Krüger'];
    $vorstellung = ['Ich freue mich aufs Studium!','Komme aus der Pfalz und mag Wandern.',
              'Spiele Volleyball und koche gern.','Bin über Umwege hier gelandet.',
              'Zweitstudium, freue mich auf neue Leute.','Mag Filme, Bier und lange Nächte.'];
    $notes = [];
    $i = 0;
    $mk = function (string $role, string $deg, int $cid, string $free, string $label,
                    string $first, string $last, string $about, array $weitere = []) use ($roundId, &$i): bool {
        $i++;
        $res = pat_signup_create([
            'round_id' => $roundId, 'role' => $role, 'degree' => $deg,
            'course_id' => $cid, 'course_free' => $free, 'course_label' => $label,
            'extra_ids' => $weitere,
            'first_name' => $first, 'last_name' => $last,
            'email' => 'test' . $i . PAT_TEST_DOMAIN, 'about' => $about,
        ]);
        return (bool)$res['ok'];
    };
    $nP = 0; $nE = 0;

    // Ein Fach wird BEWUSST für den Zweitfach-Fall reserviert: keine einzige Pat:in hat es als
    // eigenes Fach, dafür studiert es eine als weiteres. Ohne diese Reservierung findet jeder
    // Ersti eine exakte Übereinstimmung, und die Stufe „über Zweitfach" käme im Testdatensatz
    // schlicht nie vor – man sähe den Hinweis in der Verwaltung also nie.
    $reserviert = null;
    $patePicks  = $picks;
    if (count($picks) > 1) {
        $mitGeschwistern = array_values(array_filter($picks, fn ($p) => $p['geschwister'] !== []));
        if ($mitGeschwistern) {
            $reserviert = $mitGeschwistern[array_rand($mitGeschwistern)];
            $patePicks = array_values(array_filter($picks,
                fn ($p) => (int)$p['id'] !== (int)$reserviert['id']));
            if (!$patePicks) { $reserviert = null; $patePicks = $picks; }
        }
    }

    // --- Pat:innen: gleichmäßig über die vorhandenen Fächer -------------------
    $mitWeiteren = 0;
    for ($k = 0; $k < $pates; $k++) {
        $p = $patePicks[$k % count($patePicks)];
        // Etwa zwei Drittel studieren ein bis zwei weitere Fächer ihres Studiengangs – damit die
        // Einteilung im Test auch wirklich über diese Stufe entscheiden muss.
        $weitere = [];
        if ($p['geschwister'] && random_int(0, 2) > 0) {
            $g = $p['geschwister'];
            shuffle($g);
            $weitere = array_slice($g, 0, random_int(1, min(2, count($g))));
        }
        if ($mk('pate', $p['deg'], $p['id'], '', $p['label'],
            $vor[array_rand($vor)], $nach[array_rand($nach)], $vorstellung[array_rand($vorstellung)], $weitere)) {
            $nP++;
            if ($weitere) $mitWeiteren++;
        }
    }

    // --- Grenzfall: die EINZIGE, die das reservierte Fach abdeckt – als Zweitfach -----
    if ($reserviert !== null) {
        // Ihr eigenes Fach ist ein Geschwister-Eintrag, das reservierte kommt als weiteres dazu.
        $nachbarn = array_values(array_filter($picks,
            fn ($p) => in_array((int)$p['id'], $reserviert['geschwister'], true)));
        $eigen = $nachbarn ? $nachbarn[array_rand($nachbarn)] : null;
        if ($eigen && $mk('pate', $eigen['deg'], $eigen['id'], '', $eigen['label'],
            'Zwea', 'Zweitfach', 'Ich studiere zwei Fächer – das zweite deckt sonst niemand ab.',
            [(int)$reserviert['id']])) {
            $nP++; $mitWeiteren++;
            $notes[] = 'ein Fach (' . $reserviert['label'] . '), das NUR über ein Zweitfach abgedeckt ist – '
                . 'dessen Erstis tragen in der Einteilung den Hinweis „über Zweitfach"';
        }
    }
    if ($mitWeiteren > 0) $notes[] = $mitWeiteren . ' Pat:innen mit weiteren Fächern';

    // --- Grenzfall: Pat:in in einem Fach, das (fast) niemand studiert ---------
    $rare = $picks[array_rand($picks)];
    if ($mk('pate', $rare['deg'], 0, 'Papyrologie (Testfach ohne Erstis)', 'Papyrologie (Testfach ohne Erstis)',
        'Solveig', 'Sonderfall', 'Meine Gruppe sollte leer bleiben.')) { $nP++; $notes[] = 'eine Pat:in ohne passende Erstis (Gruppe bleibt leer)'; }

    // --- Erstsemester --------------------------------------------------------
    for ($k = 0; $k < $erstis; $k++) {
        $p = $picks[array_rand($picks)];
        $about = random_int(0, 2) === 0 ? $vorstellung[array_rand($vorstellung)] : '';
        if ($mk('ersti', $p['deg'], $p['id'], '', $p['label'],
            $vor[array_rand($vor)], $nach[array_rand($nach)], $about)) $nE++;
    }

    // --- Grenzfälle --------------------------------------------------------
    $any = $picks[0];
    $edge = [
        // Fach, das keine Pat:in anbietet → muss fachfremd untergebracht werden
        ['ersti', $any['deg'], 0, 'Ägyptologie (Testfach ohne Pat:in)', 'Ägyptologie (Testfach ohne Pat:in)',
            'Ötzi', 'Ohnepate', '', 'Erstis in einem Fach ohne Pat:in'],
        ['ersti', $any['deg'], 0, 'Ägyptologie (Testfach ohne Pat:in)', 'Ägyptologie (Testfach ohne Pat:in)',
            'Ännchen', 'Ohnepate', '', ''],
        // frei eingetragen → „wird von Hand zugeordnet"
        ['ersti', $any['deg'], 0, 'Irgendwas mit Medien', 'Irgendwas mit Medien',
            'Frieda', 'Freitext', 'Habe meinen Studiengang nicht gefunden.', 'frei eingetragene Studiengänge'],
        // sehr langer Name + Sonderzeichen
        ['ersti', $any['deg'], $any['id'], '', $any['label'],
            'Maximiliane-Charlotte', "O'Brien-Şahin von Übelacker", '', 'lange Namen, Apostrophe und Umlaute'],
        // Escaping-Probe (darf nirgends als HTML ankommen)
        ['ersti', $any['deg'], $any['id'], '', $any['label'],
            '<script>alert(1)</script>', '<b>Fett</b>', '<img src=x onerror=alert(1)>', 'HTML/Script in Namen (Escaping-Probe)'],
        // maximal langes „Über mich"
        ['pate', $any['deg'], $any['id'], '', $any['label'],
            'Lang', 'Redner', str_repeat('Ich erzähle sehr ausführlich von mir. ', 50), 'ein 2000-Zeichen-„Über mich"'],
    ];
    foreach ($edge as $e) {
        $ok = $mk($e[0], $e[1], (int)$e[2], (string)$e[3], (string)$e[4], (string)$e[5], (string)$e[6], mb_substr((string)$e[7], 0, 2000));
        if ($ok) { $e[0] === 'pate' ? $nP++ : $nE++; }
        if ($ok && (string)$e[8] !== '') $notes[] = (string)$e[8];
    }

    // Bachelor/Master über Kreuz, falls es beide Abschlüsse gibt
    $ba = array_values(array_filter($picks, fn ($p) => $p['deg'] === 'bachelor'));
    $ma = array_values(array_filter($picks, fn ($p) => $p['deg'] === 'master'));
    if ($ba && $ma) {
        if ($mk('ersti', 'master', $ma[0]['id'], '', $ma[0]['label'], 'Meike', 'Masterwechsel', '')) {
            $nE++; $notes[] = 'Bachelor/Master über Kreuz';
        }
    }

    // Dieselbe Adresse einmal als Ersti und einmal als Pat:in (ist erlaubt)
    $dup = 'doppelrolle' . PAT_TEST_DOMAIN;
    foreach (['ersti', 'pate'] as $role) {
        $r = pat_signup_create(['round_id' => $roundId, 'role' => $role, 'degree' => $any['deg'],
            'course_id' => $any['id'], 'course_free' => '', 'course_label' => $any['label'],
            'first_name' => 'Doppel', 'last_name' => 'Rolle', 'email' => $dup,
            'about' => 'Ich bin beides – das muss gehen.']);
        if ($r['ok']) { $role === 'pate' ? $nP++ : $nE++; }
    }
    $notes[] = 'dieselbe Adresse als Ersti UND Pat:in';

    return ['ok' => true, 'erstis' => $nE, 'pates' => $nP, 'notes' => $notes, 'error' => ''];
}

/** Testdaten eines Programms wieder entfernen (erkennt sie an der Mail-Endung). */
function pat_test_purge(int $roundId): int
{
    $st = pat_db()->prepare('SELECT id FROM pat_signups WHERE round_id = ? AND email LIKE ?');
    $st->execute([$roundId, '%' . PAT_TEST_DOMAIN]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    if (!$ids) return 0;
    $in = implode(',', $ids);
    pat_db()->exec('UPDATE pat_signups SET pate_id = NULL WHERE pate_id IN (' . $in . ')');
    pat_db()->exec('DELETE FROM pat_signups WHERE id IN (' . $in . ')');
    pat_db()->prepare('DELETE FROM pat_mailqueue WHERE round_id = ? AND to_email LIKE ?')
        ->execute([$roundId, '%' . PAT_TEST_DOMAIN]);
    return count($ids);
}

/** Wie viele Testanmeldungen stecken in diesem Programm? */
function pat_test_count(int $roundId): int
{
    $st = pat_db()->prepare('SELECT COUNT(*) FROM pat_signups WHERE round_id = ? AND email LIKE ?');
    $st->execute([$roundId, '%' . PAT_TEST_DOMAIN]);
    return (int)$st->fetchColumn();
}

// ---------------------------------------------------------------------------
// Links & Texte
// ---------------------------------------------------------------------------

/**
 * Öffentlicher Studi-Link eines Programms. Basis-URL kommt aus pat_settings – die spiegelt
 * die Verwaltung aus den App-Einstellungen herüber, damit die öffentliche Seite für ihre
 * Linkvorschau-Meta nicht auf den (fälschbaren) Host-Header angewiesen ist.
 */
function pat_public_url(string $slug): string
{
    $base = rtrim(pat_setting_get('base_url', ''), '/');
    return $base === '' ? '' : $base . '/pat/?p=' . rawurlencode($slug);
}

/**
 * Bereiche, in die die Verwaltungsseite die Texte gruppiert (Reihenfolge = Reihenfolge im Formular).
 */
function pat_text_groups(): array
{
    return [
        'hero'   => ['label' => 'Kopfbereich der Anmeldeseite', 'icon' => 'ti-layout-navbar'],
        'ersti'  => ['label' => 'Kachel links – Erstsemester', 'icon' => 'ti-school'],
        'pate'   => ['label' => 'Kachel rechts – Pat:innen', 'icon' => 'ti-users-group'],
        'more'   => ['label' => 'Unter den Kacheln', 'icon' => 'ti-arrow-down'],
        'wizard' => ['label' => 'Anmelde-Schritte (Abschluss & Studiengang)', 'icon' => 'ti-forms'],
        'form'   => ['label' => 'Angaben-Formular & Abschluss', 'icon' => 'ti-id'],
        'help'   => ['label' => 'Hilfe-Knopf', 'icon' => 'ti-message-circle-heart'],
        'closed' => ['label' => 'Wenn die Anmeldung geschlossen ist', 'icon' => 'ti-lock'],
        'mail'   => ['label' => 'Rundmail an die Studierenden', 'icon' => 'ti-mail'],
        'amail'  => ['label' => 'Mails zur Einteilung', 'icon' => 'ti-mail-forward'],
        'rmail'  => ['label' => 'Absagen', 'icon' => 'ti-mail-x'],
    ];
}

/**
 * REGISTER ALLER TEXTE der öffentlichen Seite und der Rundmail.
 *
 * Aus diesem Register bauen sich BEIDE Seiten auf: die öffentliche Anmeldeseite liest die Werte,
 * die Verwaltungsseite rendert ihr Formular komplett daraus (Speichern und Zurücksetzen laufen
 * ebenfalls darüber). Ein neuer Text ist damit eine Zeile hier – und keine Änderung an zwei
 * Dateien, die auseinanderlaufen können.
 *
 * type: 'line' = einzeilig · 'text' = mehrere Absätze (Leerzeile = neuer Absatz)
 *       'tile' = erste Zeile Einleitungssatz, jede weitere Zeile ein Punkt mit Häkchen
 * In JEDEM Text darf {{PROGRAMM}} stehen (z. B. „WiSe 2026/27"); leere Felder blenden das
 * jeweilige Element auf der Seite aus.
 */
function pat_text_fields(): array
{
    return [
        'hero_kicker' => ['group' => 'hero', 'type' => 'line', 'label' => 'Absender-Zeile über der Überschrift',
            'hint' => 'Kleine Zeile in Großbuchstaben – macht klar, von wem das Angebot kommt.',
            'default' => 'Dein AStA für dich'],
        'hero_title' => ['group' => 'hero', 'type' => 'line', 'label' => 'Überschrift',
            'default' => 'Komm nicht allein ins Studium.'],
        'hero_lead' => ['group' => 'hero', 'type' => 'text', 'label' => 'Satz darunter',
            'default' => 'Beim Pat:innenprogramm des AStA gehst du die ersten Wochen in einer '
                . 'kleinen Gruppe – mit jemandem, der dein Fach schon kennt. Melde dich in zwei Minuten an.'],

        'sched_from_label' => ['group' => 'hero', 'type' => 'line', 'label' => 'Termin-Leiste: „Anmeldung ab"',
            'hint' => 'Die drei Termine des Programms stehen als kleine Leiste unter dem Kopfbereich '
                . '(und auf der Danke-Seite). Angezeigt wird nur, was in der Programmseite unter '
                . '„Zeitplan" auch eingetragen ist; eine leere Beschriftung blendet den Termin aus.',
            'default' => 'Anmeldung ab'],
        'sched_to_label' => ['group' => 'hero', 'type' => 'line', 'label' => 'Termin-Leiste: „Anmeldeschluss"',
            'default' => 'Anmeldung bis'],
        'sched_match_label' => ['group' => 'hero', 'type' => 'line', 'label' => 'Termin-Leiste: „Einteilung bis"',
            'default' => 'Deine Gruppe steht bis'],

        'tile_ersti_title' => ['group' => 'ersti', 'type' => 'line', 'label' => 'Überschrift der Kachel',
            'default' => 'Ich fange neu an'],
        'tile_ersti' => ['group' => 'ersti', 'type' => 'tile', 'label' => 'Inhalt',
            'default' => "Du startest gerade dein Studium.\n"
                . "Du kommst in eine kleine Gruppe mit 3–6 anderen Erstsemestern – ihr lernt euch alle kennen.\n"
                . "Eine Pat:in aus deinem Studiengang zeigt euch, wie hier alles läuft.\n"
                . "Fragen zu Stundenplan, Prüfungen, Wohnen oder Feiern – alles erlaubt."],
        'tile_ersti_cta' => ['group' => 'ersti', 'type' => 'line', 'label' => 'Text auf dem Knopf',
            'default' => 'Als Erstsemester anmelden'],

        'tile_pate_title' => ['group' => 'pate', 'type' => 'line', 'label' => 'Überschrift der Kachel',
            'default' => 'Ich möchte Pat:in werden'],
        'tile_pate' => ['group' => 'pate', 'type' => 'tile', 'label' => 'Inhalt',
            'default' => "Du studierst schon länger und gibst weiter, was du weißt.\n"
                . "Du begleitest 3–6 Erstsemester aus deinem eigenen Studiengang.\n"
                . "Wie viele du übernimmst, entscheidest du selbst.\n"
                . "Ein Kennenlernen zum Semesterstart – danach so viel, wie ihr mögt."],
        'tile_pate_cta' => ['group' => 'pate', 'type' => 'line', 'label' => 'Text auf dem Knopf',
            'default' => 'Als Pat:in anmelden'],

        'duo_note' => ['group' => 'more', 'type' => 'line', 'label' => 'Hinweiszeile direkt unter den Kacheln',
            'hint' => 'Steht mittig mit einem Haken-Symbol davor.',
            'default' => 'Zwei Minuten, kein Konto nötig – und du kannst dich jederzeit wieder abmelden.'],
        'more_title' => ['group' => 'more', 'type' => 'line', 'label' => 'Überschrift des Info-Abschnitts',
            'default' => 'Worum geht es?'],
        'public_intro' => ['group' => 'more', 'type' => 'text', 'label' => 'Info-Abschnitt',
            'hint' => 'Für die, die mehr wissen wollen.',
            'default' => "Das Pat:innenprogramm des AStA bringt neue Studierende mit erfahrenen "
                . "Studierenden zusammen. In kleinen Gruppen bekommst du Antworten auf all die Fragen, "
                . "die im Vorlesungsverzeichnis nicht stehen – vom Stundenplan über die richtigen Räume "
                . "bis zu den Kneipen, in denen man abends landet.\n\n"
                . "Und du bekommst nicht nur eine Pat:in: Die ganze Gruppe lernt sich kennen. Zur "
                . "Einteilung bekommt ihr die Kontakte aller aus eurer Gruppe und eine gemeinsame "
                . "Start-Mail – so startet niemand allein ins Semester.\n\n"
                . "Die Anmeldung dauert zwei Minuten. Sag uns einfach, ob du neu anfängst oder ob du "
                . "andere begleiten möchtest."],

        'wiz_ersti_title' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Seitentitel Erstsemester',
            'default' => 'Anmeldung als Erstsemester'],
        'wiz_pate_title' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Seitentitel Pat:innen',
            'default' => 'Anmeldung als Pat:in'],
        'wiz_degree_q' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Frage nach dem Abschluss',
            'default' => 'Studierst du im Bachelor oder im Master?'],
        'wiz_bachelor_desc' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Beschreibung Bachelor-Kachel',
            'default' => 'Du beginnst dein erstes Studium – z. B. B.A., B.Sc. oder B.Ed. (Lehramt).'],
        'wiz_master_desc' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Beschreibung Master-Kachel',
            'default' => 'Du hast deinen Bachelor in der Tasche und startest jetzt im Master.'],
        'wiz_course_q' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Frage nach dem Studiengang',
            'default' => 'Und welchen Studiengang studierst du?'],
        'wiz_course_empty' => ['group' => 'wizard', 'type' => 'text', 'label' => 'Hinweis, wenn (noch) keine Studiengänge hinterlegt sind',
            'default' => 'Für diese Auswahl sind noch keine Studiengänge hinterlegt. Schau bald noch '
                . 'einmal vorbei – oder schreib dem AStA kurz eine Mail.'],
        'wiz_p_degree_q' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Frage nach dem Abschluss (Pat:innen)',
            'default' => 'Möchtest du Erstis im Bachelor oder im Master begleiten?'],
        'wiz_p_bachelor_desc' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Beschreibung Bachelor-Kachel (Pat:innen)',
            'default' => 'Du begleitest Erstis, die ihr erstes Studium beginnen – z. B. B.A., B.Sc. oder B.Ed.'],
        'wiz_p_master_desc' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Beschreibung Master-Kachel (Pat:innen)',
            'default' => 'Du begleitest Erstis, die neu in einen Master starten.'],
        'wiz_p_course_q' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Frage nach dem Studiengang (Pat:innen)',
            'default' => 'Und in welchem Studiengang kennst du dich aus?'],
        'wiz_sub_q' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Frage nach dem Unterpunkt',
            'hint' => '{{LABEL}} wird zur Bezeichnung der Unterauswahl des Studiengangs '
                . '(z. B. „Erstfach", pflegbar bei den Studiengängen).',
            'default' => 'Und dein {{LABEL}}?'],
        'wiz_extra_q' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Weitere Fächer: Frage an die Pat:innen',
            'hint' => 'Eigener Schritt NUR für Pat:innen, direkt nach ihrer eigenen Auswahl: welche weiteren '
                . 'Fächer desselben Studiengangs studieren sie (im Lehramt hat ja fast jede:r mehrere)? '
                . 'Erstsemester werden bevorzugt einer Pat:in mit ihrem Fach zugeteilt. {{LABEL}} wird zur '
                . 'Bezeichnung der Unterauswahl. Leer = der Schritt entfällt ganz.',
            'default' => 'Welche Fächer studierst du außerdem?'],
        'wiz_extra_hint' => ['group' => 'wizard', 'type' => 'text', 'label' => 'Weitere Fächer: Text unter der Frage',
            'default' => "Hak alle Fächer an, die du neben deinem {{LABEL}} studierst.\n\n"
                . 'Wir teilen Erstsemester bevorzugt einer Pat:in zu, die ihr Fach ebenfalls studiert. '
                . 'Du musst nichts auswählen – ohne Angabe zählt einfach dein eigenes Fach.'],
        'wiz_extra_recap' => ['group' => 'wizard', 'type' => 'line', 'label' => 'Weitere Fächer: Vermerk in der Zusammenfassung',
            'default' => 'Du studierst außerdem:'],
        'wiz_other_title' => ['group' => 'wizard', 'type' => 'line', 'label' => '„Nicht dabei"-Kasten: Überschrift',
            'hint' => 'Kasten unter der Studiengangs-Auswahl, in dem man den eigenen Studiengang '
                . 'frei eintragen kann. Leer = kein Kasten (dann geht es nur über die Liste).',
            'default' => 'Dein Studiengang ist nicht dabei?'],
        'wiz_other_hint' => ['group' => 'wizard', 'type' => 'text', 'label' => '„Nicht dabei"-Kasten: Text',
            'default' => 'Kein Problem – trag ihn hier einfach selbst ein. Wir ordnen dich dann '
                . 'von Hand einer passenden Gruppe zu.'],
        'wiz_other_sub_title' => ['group' => 'wizard', 'type' => 'line', 'label' => '„Nicht dabei"-Kasten: Überschrift auf der Unterpunkt-Ebene',
            'default' => 'Deine Richtung ist nicht dabei?'],
        'wiz_other_recap' => ['group' => 'wizard', 'type' => 'line', 'label' => '„Nicht dabei": Vermerk in der Zusammenfassung',
            'default' => 'wird von Hand zugeordnet'],

        'form_q' => ['group' => 'form', 'type' => 'line', 'label' => 'Überschrift über dem Formular',
            'default' => 'Und wer bist du?'],
        'form_email_note_ersti' => ['group' => 'form', 'type' => 'text', 'label' => 'Hinweis zur Mailadresse (Erstsemester)',
            'default' => 'Deine Mailadresse nutzt der AStA nur, um dich zu erreichen, falls es nötig ist – '
                . 'ansonsten geben wir sie ausschließlich an deine Gruppe weiter: an deine:n Pat:in und '
                . 'die anderen Erstis, damit ihr euch gegenseitig erreichen könnt.'],
        'form_email_note_pate' => ['group' => 'form', 'type' => 'text', 'label' => 'Hinweis zur Mailadresse (Pat:innen)',
            'default' => 'Wichtig: Diese Adresse geben wir an die Erstis weiter, die dir zugeteilt werden – '
                . 'so kann deine Gruppe dich erreichen.'],
        'form_email2_note' => ['group' => 'form', 'type' => 'line', 'label' => 'Hinweis zur Wiederholung der Mailadresse',
            'default' => 'Sicher ist sicher – ein Tippfehler hier, und wir erreichen dich nicht.'],
        'form_email_mismatch' => ['group' => 'form', 'type' => 'text', 'label' => 'Meldung, wenn die beiden Adressen nicht übereinstimmen',
            'default' => 'Die beiden E-Mail-Adressen stimmen nicht überein. Bitte schau noch einmal '
                . 'drüber – so stellen wir sicher, dass wir dich später auch wirklich erreichen.'],
        'form_about_note_ersti' => ['group' => 'form', 'type' => 'line', 'label' => 'Hinweis zu „Über mich" (Erstsemester, freiwillig)',
            'default' => 'Wenn du magst, erzähl kurz etwas über dich – deine Gruppe bekommt das zu lesen. Freiwillig.'],
        'form_about_note_pate' => ['group' => 'form', 'type' => 'line', 'label' => 'Hinweis zu „Über mich" (Pat:innen, Pflicht)',
            'default' => 'Stell dich kurz vor – ein paar Sätze reichen.'],
        'form_privacy_ersti' => ['group' => 'form', 'type' => 'text',
            'label' => 'Datenschutz-Häkchen: Text (Erstsemester)',
            'hint' => 'Pflicht-Häkchen direkt über dem Absende-Knopf – ohne Häkchen wird nichts '
                . 'gespeichert. {{MONATE}} wird zur Löschfrist, {{PROGRAMM}} zum Semester, '
                . '{{DATENSCHUTZ}} zum Link auf die Datenschutzerklärung (ist unten bei „Kontakt & '
                . 'Rechtliches" keine hinterlegt, bleibt schlicht das Wort stehen). Ein leerer Text '
                . 'lässt das Häkchen weg – dann fehlt aber die Einwilligung.',
            'default' => 'Ich bin damit einverstanden, dass der ' . pat_traeger() . ' meinen Namen, meine '
                . 'E-Mail-Adresse, meinen Studiengang und meinen freiwilligen Vorstellungstext '
                . 'speichert, um mich im Pat:innenprogramm einer Gruppe zuzuordnen, und diese Angaben '
                . 'dafür an meine Gruppe weitergibt – an meine Pat:in und an die anderen Erstsemester '
                . 'meiner Gruppe. Spätestens {{MONATE}} Monate nach '
                . 'Semesterstart werden alle Angaben automatisch gelöscht. Ich kann meine '
                . 'Einwilligung jederzeit widerrufen und meine Löschung verlangen – eine Mail an die '
                . 'Adresse am Seitenende genügt. Mehr dazu: {{DATENSCHUTZ}}'],
        'form_privacy_pate' => ['group' => 'form', 'type' => 'text',
            'label' => 'Datenschutz-Häkchen: Text (Pat:innen)',
            'hint' => 'Wie beim Erstsemester-Text, nur für Pat:innen – hier geht die Adresse an '
                . 'MEHRERE Erstsemester, das sollte drinstehen.',
            'default' => 'Ich bin damit einverstanden, dass der ' . pat_traeger() . ' meinen Namen, meine '
                . 'E-Mail-Adresse, meinen Studiengang und meinen Vorstellungstext speichert, um mir '
                . 'im Pat:innenprogramm eine Gruppe zuzuordnen, und diese Angaben dafür an die mir '
                . 'zugeteilten Erstsemester weitergibt. Spätestens {{MONATE}} Monate nach '
                . 'Semesterstart werden alle Angaben automatisch gelöscht. Ich kann meine '
                . 'Einwilligung jederzeit widerrufen und meine Löschung verlangen – eine Mail an die '
                . 'Adresse am Seitenende genügt. Mehr dazu: {{DATENSCHUTZ}}'],
        'form_privacy_error' => ['group' => 'form', 'type' => 'text',
            'label' => 'Meldung, wenn das Datenschutz-Häkchen fehlt',
            'default' => 'Ohne dein Einverständnis zur Datenverarbeitung können wir deine Anmeldung '
                . 'leider nicht speichern. Setz bitte das Häkchen über dem Knopf.'],
        'form_privacy' => ['group' => 'form', 'type' => 'text', 'label' => 'Datenschutz-Zeile unter dem Formular',
            'hint' => 'Kleine Zeile UNTER dem Absende-Knopf – gedacht für die verantwortliche Stelle '
                . 'und den Weg zur Auskunft. Die Einwilligung selbst steht im Häkchen darüber; leer '
                . 'lassen, wenn das genügt. {{MONATE}} wird zur Löschfrist.',
            'default' => 'Verantwortlich für diese Daten ist der ' . pat_traeger() . '. Fragen zu deinen '
                . 'gespeicherten Angaben, Auskunft oder Löschung? Schreib uns an die Adresse am '
                . 'Seitenende.'],
        'form_dupe' => ['group' => 'form', 'type' => 'text', 'label' => 'Meldung bei doppelter Anmeldung',
            'default' => 'Mit dieser E-Mail-Adresse bist du für dieses Semester schon angemeldet. '
                . 'Wenn etwas nicht stimmt, schreib uns einfach an die Adresse am Seitenende.'],
        'done_title' => ['group' => 'form', 'type' => 'line', 'label' => 'Abschluss-Seite: Überschrift',
            'default' => 'Geschafft – du bist dabei!'],
        'done_body' => ['group' => 'form', 'type' => 'text', 'label' => 'Abschluss-Seite: Text',
            'default' => 'Danke für deine Anmeldung zum Pat:innenprogramm im {{PROGRAMM}}! '
                . 'Wir melden uns per Mail bei dir, sobald die Gruppen eingeteilt sind.'],

        'mail_from_name' => ['group' => 'amail', 'type' => 'line', 'label' => 'Absender-Name',
            'hint' => 'Steht im Postfach der Studierenden vor der Adresse. Leer lassen zeigt nur die '
                . 'nackte Mailadresse. Antworten gehen immer an die Paten-Mail (unten bei „Kontakt & '
                . 'Rechtliches“); liegt die auf derselben Domain wie die Absender-Adresse der App '
                . '(Verwaltung → Allgemein), wird auch direkt von ihr verschickt.',
            'default' => 'AStA Pat:innenprogramm'],
        'mail_ersti_subject' => ['group' => 'amail', 'type' => 'line', 'label' => 'An Erstsemester – Betreff',
            'default' => 'Deine Pat:in für das {{PROGRAMM}} steht fest'],
        'mail_ersti_body' => ['group' => 'amail', 'type' => 'text', 'label' => 'An Erstsemester – Text', 'mono' => true,
            'hint' => 'Platzhalter: {{VORNAME}} · {{PROGRAMM}} · {{PATIN}} · {{PATIN_MAIL}} · '
                . '{{PATIN_FACH}} · {{PATIN_UEBER}} (deren „Über mich") · {{GRUPPE}} (die anderen '
                . 'Erstsemester der Gruppe mit Mailadresse, Studiengang und „Über mich")',
            'default' => "Hallo {{VORNAME}},\n\n"
                . "schön, dass du beim Pat:innenprogramm dabei bist! Für das {{PROGRAMM}} ist "
                . "deine Pat:in:\n\n"
                . "{{PATIN}} ({{PATIN_FACH}})\nE-Mail: {{PATIN_MAIL}}\n\n"
                . "{{PATIN_UEBER}}\n\n"
                . "Und du bist nicht allein: Zu deiner Gruppe gehören außerdem\n\n"
                . "{{GRUPPE}}\n\n"
                . "Ihr bekommt zusätzlich eine gemeinsame Mail an die ganze Gruppe – antworte "
                . "dort einfach allen und stell dich kurz vor.\n\n"
                . "Meldet euch einfach direkt – deine Pat:in weiß Bescheid und freut sich auf euch.\n\n"
                . "Viele Grüße\nDein " . pat_traeger()],
        'mail_pate_subject' => ['group' => 'amail', 'type' => 'line', 'label' => 'An Pat:innen – Betreff',
            'default' => 'Deine Gruppe für das {{PROGRAMM}}'],
        'mail_pate_body' => ['group' => 'amail', 'type' => 'text', 'label' => 'An Pat:innen – Text', 'mono' => true,
            'hint' => 'Platzhalter: {{VORNAME}} · {{PROGRAMM}} · {{ANZAHL}} · {{LISTE}} '
                . '(Namen, Mailadressen, Studiengang und „Über mich" deiner Erstis)',
            'default' => "Hallo {{VORNAME}},\n\n"
                . "danke, dass du im {{PROGRAMM}} Pat:in bist! Dir sind {{ANZAHL}} Erstsemester "
                . "zugeteilt:\n\n"
                . "{{LISTE}}\n\n"
                . "Bitte melde dich in den nächsten Tagen einmal bei ihnen – für die meisten bist "
                . "du der erste Kontakt an der Uni.\n\n"
                . "Viele Grüße\nDein " . pat_traeger()],
        'mail_gruppe_subject' => ['group' => 'amail', 'type' => 'line', 'label' => 'An die ganze Gruppe – Betreff',
            'default' => 'Eure Gruppe im {{PROGRAMM}} – lernt euch kennen!'],
        'mail_gruppe_body' => ['group' => 'amail', 'type' => 'text', 'label' => 'An die ganze Gruppe – Text', 'mono' => true,
            'hint' => 'EINE Mail je Gruppe: Pat:in im An-Feld, alle Erstsemester im CC – jede:r '
                . 'sieht alle Adressen, und „Allen antworten" erreicht die ganze Gruppe. Leer '
                . 'lassen schaltet die Gruppen-Mail ab. Platzhalter: {{PROGRAMM}} · {{PATIN}} · '
                . '{{ANZAHL}} (Erstsemester) · {{LISTE}} (alle Mitglieder der Gruppe inkl. Pat:in)',
            'default' => "Hallo zusammen,\n\n"
                . "ihr seid im {{PROGRAMM}} eine Gruppe! {{PATIN}} begleitet euch als Pat:in, "
                . "und ihr {{ANZAHL}} Erstis startet gemeinsam. Das seid ihr:\n\n"
                . "{{LISTE}}\n\n"
                . "Diese Mail geht an euch alle. Der einfachste Anfang: Antwortet „Allen\" und "
                . "stellt euch kurz vor – dann ist das Eis gebrochen. Erstes Treffen, Fragen, "
                . "Termine: Das macht ihr ab jetzt direkt untereinander aus.\n\n"
                . "Viel Spaß miteinander!\nDein " . pat_traeger()],

        'mail_rej_ersti_subject' => ['group' => 'rmail', 'type' => 'line',
            'label' => 'An Erstsemester ohne Pat:in – Betreff',
            'default' => 'Pat:innenprogramm {{PROGRAMM}} – leider ohne Pat:in'],
        'mail_rej_ersti_body' => ['group' => 'rmail', 'type' => 'text', 'mono' => true,
            'label' => 'An Erstsemester ohne Pat:in – Text',
            'hint' => 'Geht an alle Erstsemester, die unter „Ohne Gruppe" stehen. Platzhalter: '
                . '{{VORNAME}} · {{PROGRAMM}} · {{FACH}} (ihr Studiengang)',
            'default' => "Hallo {{VORNAME}},\n\n"
                . "danke, dass du dich für das Pat:innenprogramm im {{PROGRAMM}} angemeldet hast. "
                . "Leider müssen wir dir absagen: In deinem Studiengang haben sich diesmal nicht "
                . "genug Pat:innen gemeldet, und wir wollten dich nicht jemandem zuteilen, der "
                . "fachlich gar nichts mit deinem Studium zu tun hat.\n\n"
                . "Das heißt aber nicht, dass du mit deinen Fragen allein bleibst: Schreib uns "
                . "einfach, wenn du beim Studienstart irgendwo hängst – wir helfen weiter und "
                . "kennen fast immer jemanden aus deinem Fach.\n\n"
                . "Einen guten Start ins Studium!\nDein " . pat_traeger()],
        'mail_rej_pate_subject' => ['group' => 'rmail', 'type' => 'line',
            'label' => 'An Pat:innen ohne Gruppe – Betreff',
            'default' => 'Pat:innenprogramm {{PROGRAMM}} – diesmal ohne Gruppe'],
        'mail_rej_pate_body' => ['group' => 'rmail', 'type' => 'text', 'mono' => true,
            'label' => 'An Pat:innen ohne Gruppe – Text',
            'hint' => 'Geht an alle Pat:innen, deren Gruppe leer geblieben ist. Platzhalter: '
                . '{{VORNAME}} · {{PROGRAMM}} · {{FACH}} (ihr Studiengang)',
            'default' => "Hallo {{VORNAME}},\n\n"
                . "vielen Dank, dass du dich für das {{PROGRAMM}} als Pat:in gemeldet hast! "
                . "Leider konnten wir dir diesmal niemanden zuteilen – in deinem Fach haben sich "
                . "weniger Erstsemester angemeldet, als es Pat:innen gibt.\n\n"
                . "Das ist ausdrücklich kein Nein für die Zukunft: Zum nächsten Semester fragen "
                . "wir gerne wieder an. Und falls doch noch jemand nachrückt, melden wir uns bei dir.\n\n"
                . "Danke für deine Bereitschaft!\nDein " . pat_traeger()],

        'help_label' => ['group' => 'help', 'type' => 'line', 'label' => 'Beschriftung des Knopfes',
            'hint' => 'Steht auf dem mitschwebenden Knopf unten rechts, der das Formular öffnet. '
                . 'Muss kurz genug für einen Knopf sein. Ohne Paten-Mail (unten bei „Kontakt & '
                . 'Rechtliches") oder mit leerer Beschriftung erscheint kein Knopf – dann kann '
                . 'auch niemand fragen.',
            'default' => 'Brauchst du Hilfe?'],
        'help_who' => ['group' => 'help', 'type' => 'line', 'label' => 'Wer dahintersteckt',
            'hint' => 'Steht klein unter dem Formular. Am schönsten mit Namen – „Das machen Lotta '
                . 'und Jonas aus dem Referat …". Leer lassen blendet den Satz aus.',
            'default' => 'Dahinter stecken Studierende aus dem AStA, die das neben dem Studium machen.'],
        'help_subject' => ['group' => 'help', 'type' => 'line', 'label' => 'Betreff der Mail an euch',
            'hint' => 'Mit diesem Betreff landet eine Frage in eurem Postfach. Der Name der '
                . 'fragenden Person wird angehängt, sofern sie einen angegeben hat.',
            'default' => 'Frage zum Pat:innenprogramm {{PROGRAMM}}'],
        'help_form_title' => ['group' => 'help', 'type' => 'line', 'label' => 'Formular: Überschrift',
            'default' => 'Schreib uns'],
        'help_form_lead' => ['group' => 'help', 'type' => 'text', 'label' => 'Formular: Text darüber',
            'hint' => 'Hier gehört hin, wie lange eine Antwort ungefähr dauert – das ist die Frage, '
                . 'die sich beim Abschicken jede:r stellt.',
            'default' => 'Frag einfach, es gibt keine dummen Fragen. Wir antworten dir an die '
                . 'Adresse, die du hier einträgst – meistens innerhalb von ein, zwei Tagen.'],
        'help_form_done' => ['group' => 'help', 'type' => 'text', 'label' => 'Formular: Text nach dem Abschicken',
            'default' => 'Deine Frage ist bei uns angekommen. Wir melden uns bei dir – schau '
                . 'ruhig auch mal in den Spam-Ordner, falls es länger dauert.'],

        'closed_title' => ['group' => 'closed', 'type' => 'line', 'label' => 'Überschrift',
            'default' => 'Die Anmeldung ist geschlossen'],
        'closed_body' => ['group' => 'closed', 'type' => 'text', 'label' => 'Text',
            'default' => 'Für das {{PROGRAMM}} nehmen wir keine Anmeldungen mehr an. Wenn du trotzdem '
                . 'noch mitmachen möchtest, schreib dem AStA einfach eine Mail – manchmal geht noch was.'],
        'gone_body' => ['group' => 'closed', 'type' => 'text',
            'label' => 'Text bei ungültigem Link / wenn gar keine Anmeldung läuft',
            'default' => 'Zurzeit ist keine Anmeldung zum Pat:innenprogramm geöffnet. Sobald das '
                . 'nächste Semester startet, verschickt der AStA einen neuen Anmeldelink an alle Studierenden.'],

        'invite_subject' => ['group' => 'mail', 'type' => 'line', 'label' => 'Betreff',
            'default' => 'Pat:innenprogramm {{PROGRAMM}} – jetzt anmelden'],
        'invite_body' => ['group' => 'mail', 'type' => 'text', 'label' => 'Text', 'mono' => true,
            'hint' => 'Hier gehört zusätzlich {{LINK}} hinein – der Studi-Link des Semesters.',
            'default' => "Liebe Studierende,\n\n"
                . "für das {{PROGRAMM}} startet das Pat:innenprogramm des AStA: Neue Studierende werden "
                . "in kleinen Gruppen von erfahrenen Studierenden begleitet.\n\n"
                . "Hier könnt ihr euch anmelden – als Erstsemester oder als Pat:in:\n{{LINK}}\n\n"
                . "Viele Grüße\nEuer " . pat_traeger()],
    ];
}

/**
 * Text aus der Verwaltung, sonst der Standard aus dem Register.
 *
 * Ein in der Verwaltung GESPEICHERTES leeres Feld bleibt leer (die Seite lässt das Element dann
 * weg) – nur wenn gar nichts gespeichert ist bzw. nach „Auf Standard zurücksetzen" greift der
 * Standard. Deshalb pat_setting_raw() statt pat_setting_get().
 */
function pat_text(string $key): string
{
    $raw = pat_setting_raw('text_' . $key);
    if ($raw !== null) return trim($raw);
    return trim((string)(pat_text_fields()[$key]['default'] ?? ''));
}

/** Platzhalter in einem Text ersetzen. */
function pat_text_fill(string $text, array $vars): string
{
    return strtr($text, $vars);
}

/**
 * Kachel-Text aufteilen: erste Zeile = Einleitungssatz, alle weiteren = Listenpunkte.
 * Gibt ['lead' => string, 'points' => string[]] zurück (leere Zeilen fallen weg).
 */
function pat_tile_text(string $key): array
{
    $lines = [];
    foreach (preg_split('/\R/', pat_text($key)) as $l) {
        $l = trim($l);
        if ($l !== '') $lines[] = $l;
    }
    return ['lead' => array_shift($lines) ?? '', 'points' => $lines];
}
