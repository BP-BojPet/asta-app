<?php
/**
 * Externe Events (öffentliche Anmeldungen) – Datenbank und Kernfunktionen.
 *
 * EIGENE SQLite-Datei, wie beim Pat:innenprogramm und den Umfragen: Anmeldedaten von
 * Studierenden haben in der Mitgliederdatenbank nichts zu suchen, und der öffentliche
 * Bereich kommt so nie in ihre Nähe.
 *
 * Was hier NICHT steht: die Rechte. `extern_can_manage()` liegt in der lib.php, weil
 * Rollen eine Sache der internen App sind – diese Datei kennt nur Daten.
 *
 * Grundgedanke des Moduls: Fast alles ist eine OPTION. Ob es Gruppen gibt, ob man sich
 * zu mehreren anmelden kann, ob ein Code Freundeskreise zusammenhält, wer die Einteilung
 * sehen darf – all das entscheidet ihr je Veranstaltung, nicht der Code.
 *
 * ACHTUNG beim Hochladen: data/extern.sqlite darf der rsync NIE mitnehmen.
 */

declare(strict_types=1);


// Marke dieser Installation (Logo, App-Symbol) – eigenständig, ohne lib.php.
require_once __DIR__ . '/brand-core.php';

// Gemeinsames Mail-Konto aller Bereiche (eigenständig, ohne lib.php).
require_once __DIR__ . '/mail-pool.php';
// Gemeinsamer Grafik-Bausatz (eigenständig, ohne lib.php) – für die Teilnahme-Ansicht.
require_once __DIR__ . '/chart-core.php';

const EXTERN_DB_FILE      = __DIR__ . '/data/extern.sqlite';
const EXTERN_KEEP_DAYS    = 90;   // Vorgabe: Anmeldedaten so lange nach dem Termin aufheben
const EXTERN_TOKEN_LEN    = 32;   // Bytes für den Selbstbedienungs-Link

function extern_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(EXTERN_DB_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . EXTERN_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 4000');
    extern_schema($pdo);
    return $pdo;
}

/** Schema anlegen/ergänzen. Idempotent, läuft bei jedem Verbindungsaufbau. */
function extern_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS extern_settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id             INTEGER PRIMARY KEY,
        slug           TEXT    NOT NULL UNIQUE,        -- öffentlicher Schlüssel im Link
        title          TEXT    NOT NULL,
        intro          TEXT    NOT NULL DEFAULT '',
        place          TEXT    NOT NULL DEFAULT '',
        starts_at      TEXT    NOT NULL DEFAULT '',    -- Termin der Veranstaltung
        ends_at        TEXT    NOT NULL DEFAULT '',
        referat        TEXT    NOT NULL DEFAULT '',    -- betreuendes Referat
        status         TEXT    NOT NULL DEFAULT 'draft',  -- draft | open | closed | archived

        -- Anmeldung
        reg_from       TEXT    NOT NULL DEFAULT '',    -- leer = ab sofort
        reg_until      TEXT    NOT NULL DEFAULT '',    -- leer = bis von Hand geschlossen
        capacity       INTEGER NOT NULL DEFAULT 0,     -- 0 = unbegrenzt
        waitlist       INTEGER NOT NULL DEFAULT 1,
        cancel_until   TEXT    NOT NULL DEFAULT '',    -- bis wann darf man sich abmelden
        confirm_mail   INTEGER NOT NULL DEFAULT 1,     -- Anmeldung per Mail bestätigen lassen
        selfservice    INTEGER NOT NULL DEFAULT 1,     -- ändern/abmelden über den eigenen Link

        -- Gruppen
        group_mode     TEXT    NOT NULL DEFAULT 'none',   -- none | fixed | auto
        group_size     INTEGER NOT NULL DEFAULT 0,        -- Wunschgröße (bei auto)
        allow_code     INTEGER NOT NULL DEFAULT 0,        -- Freundeskreis über gemeinsamen Code
        allow_party    INTEGER NOT NULL DEFAULT 0,        -- mehrere Personen auf einmal anmelden
        party_max      INTEGER NOT NULL DEFAULT 4,
        mix_field_id   INTEGER,                           -- Mischkriterium (ein Formularfeld)

        -- Sichtbarkeit
        show_groups    TEXT    NOT NULL DEFAULT 'signed',  -- never | signed | public
        show_names     INTEGER NOT NULL DEFAULT 0,         -- Namen in der Gruppenliste zeigen
        show_count     INTEGER NOT NULL DEFAULT 1,         -- freie Plätze auf der Anmeldeseite

        -- Rotation: 0 = aus. Sonst Anzahl Runden, Startzeit und Minuten je Runde.
        rot_rounds     INTEGER NOT NULL DEFAULT 0,
        rot_start      TEXT    NOT NULL DEFAULT '',
        rot_minutes    INTEGER NOT NULL DEFAULT 60,

        keep_days      INTEGER NOT NULL DEFAULT " . EXTERN_KEEP_DAYS . ",
        salt           TEXT    NOT NULL,
        created_by     INTEGER,
        created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // ---- Frei zusammensetzbares Formular ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS fields (
        id        INTEGER PRIMARY KEY,
        event_id  INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        sort      INTEGER NOT NULL DEFAULT 0,
        type      TEXT    NOT NULL DEFAULT 'text',   -- text|textarea|single|multi|bool|number
        label     TEXT    NOT NULL,
        help      TEXT    NOT NULL DEFAULT '',
        required  INTEGER NOT NULL DEFAULT 0,
        num_min   INTEGER,
        num_max   INTEGER
    )");

    // Kontingent je Antwortmöglichkeit: „Workshop A (20 Plätze)". 0 = unbegrenzt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS field_opts (
        id        INTEGER PRIMARY KEY,
        field_id  INTEGER NOT NULL REFERENCES fields(id) ON DELETE CASCADE,
        sort      INTEGER NOT NULL DEFAULT 0,
        label     TEXT    NOT NULL,
        capacity  INTEGER NOT NULL DEFAULT 0
    )");

    // ---- Gruppen ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS groups (
        id         INTEGER PRIMARY KEY,
        event_id   INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        sort       INTEGER NOT NULL DEFAULT 0,
        name       TEXT    NOT NULL,
        capacity   INTEGER NOT NULL DEFAULT 0,        -- 0 = unbegrenzt
        place      TEXT    NOT NULL DEFAULT '',       -- Treffpunkt
        starts_at  TEXT    NOT NULL DEFAULT '',
        note       TEXT    NOT NULL DEFAULT '',
        leader_id  INTEGER                            -- betreuendes Mitglied (ID aus der App)
    )");

    // ---- Rotationsplan (Option): Stationen, die die Gruppen der Reihe nach besuchen ----
    // Für Kneipentouren, Stationenläufe, Rallyes: nicht eine Gruppe pro Ort, sondern ein
    // Fahrplan. Aus ist der Normalfall – die Rotation greift erst mit rot_rounds > 0.
    $pdo->exec("CREATE TABLE IF NOT EXISTS stations (
        id        INTEGER PRIMARY KEY,
        event_id  INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        sort      INTEGER NOT NULL DEFAULT 0,
        name      TEXT    NOT NULL,
        place     TEXT    NOT NULL DEFAULT '',
        note      TEXT    NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rotation (
        id         INTEGER PRIMARY KEY,
        event_id   INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        round_no   INTEGER NOT NULL,
        group_id   INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
        station_id INTEGER NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
        starts_at  TEXT    NOT NULL DEFAULT ''
    )");

    // ---- Anmeldungen ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS signups (
        id           INTEGER PRIMARY KEY,
        event_id     INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        name         TEXT    NOT NULL,
        email        TEXT    NOT NULL,
        token_hash   TEXT    NOT NULL DEFAULT '',     -- Selbstbedienung (nur der Hash)
        status       TEXT    NOT NULL DEFAULT 'pending', -- pending|confirmed|waitlist|cancelled
        party_size   INTEGER NOT NULL DEFAULT 1,      -- Sammelanmeldung: Anzahl Personen
        party_names  TEXT    NOT NULL DEFAULT '',     -- Begleitung als Freitext
        code         TEXT    NOT NULL DEFAULT '',     -- gemeinsamer Code (Freundeskreis)
        group_id     INTEGER REFERENCES groups(id) ON DELETE SET NULL,
        note         TEXT    NOT NULL DEFAULT '',     -- interne Notiz der Orga
        created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        confirmed_at TEXT,
        cancelled_at TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS answers (
        id         INTEGER PRIMARY KEY,
        signup_id  INTEGER NOT NULL REFERENCES signups(id) ON DELETE CASCADE,
        field_id   INTEGER NOT NULL,
        option_id  INTEGER,
        value_text TEXT NOT NULL DEFAULT '',
        value_num  INTEGER
    )");

    // ---- Mailversand: Warteschlange + Zähler fürs Kontingent (wie bei den Umfragen) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS mailqueue (
        id         INTEGER PRIMARY KEY,
        event_id   INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        signup_id  INTEGER REFERENCES signups(id) ON DELETE CASCADE,
        kind       TEXT    NOT NULL,                 -- confirm | assign | info | reminder
        subject    TEXT    NOT NULL DEFAULT '',
        body       TEXT    NOT NULL DEFAULT '',
        email      TEXT    NOT NULL DEFAULT '',
        status     TEXT    NOT NULL DEFAULT 'queued', -- queued | sent | failed
        tries      INTEGER NOT NULL DEFAULT 0,
        last_error TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_log (
        id      INTEGER PRIMARY KEY,
        sent_at TEXT NOT NULL
    )");

    // Nachrüsten: „CREATE TABLE IF NOT EXISTS" fasst eine Tabelle, die es schon gibt, NICHT mehr
    // an. Neue Spalten kommen deshalb hier dazu – sonst läuft alles nur auf einer frischen
    // Datenbank, und auf der bestehenden endet jeder Speichern-Klick in „no such column".
    extern_add_columns($pdo, 'events', [
        'rot_rounds'  => "INTEGER NOT NULL DEFAULT 0",
        'rot_start'   => "TEXT NOT NULL DEFAULT ''",
        'rot_minutes' => "INTEGER NOT NULL DEFAULT 60",
    ]);
    // Erst nachrüsten, dann Indizes: Ein Index auf einer fehlenden Spalte scheitert.

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_f_event ON fields(event_id, sort)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fo_field ON field_opts(field_id, sort)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_g_event ON groups(event_id, sort)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_st_event ON stations(event_id, sort)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rot_event ON rotation(event_id, round_no)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rot_group ON rotation(group_id, round_no)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_s_event ON signups(event_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_s_token ON signups(token_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_s_code ON signups(event_id, code)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_a_signup ON answers(signup_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mq_status ON mailqueue(status, id)');

}

/**
 * Fehlende Spalten ergänzen. SQLite kann kein „ADD COLUMN IF NOT EXISTS", also erst nachsehen.
 * $spalten: Name => SQL-Definition (ohne den Namen). Nur Vorgabewerte ohne Unterabfragen –
 * mehr lässt SQLite bei ADD COLUMN ohnehin nicht zu.
 */
function extern_add_columns(PDO $pdo, string $tabelle, array $spalten): void
{
    // Weißliste: Der Tabellenname landet unescaped in der Abfrage.
    if (!in_array($tabelle, ['events', 'fields', 'field_opts', 'groups', 'stations', 'rotation',
                             'signups', 'answers', 'mailqueue', 'mail_log'], true)) return;
    $da = [];
    foreach ($pdo->query('PRAGMA table_info(' . $tabelle . ')')->fetchAll() as $c) $da[(string)$c['name']] = true;
    foreach ($spalten as $name => $def) {
        if (isset($da[$name]) || !preg_match('~^[a-z_][a-z0-9_]*$~', (string)$name)) continue;
        try {
            $pdo->exec('ALTER TABLE ' . $tabelle . ' ADD COLUMN ' . $name . ' ' . $def);
        } catch (\Throwable $e) { /* schon da oder nicht nachrüstbar – der Selbsttest meldet es */ }
    }
}

// ---------------------------------------------------------------------------
// Einstellungen (modulweit)
// ---------------------------------------------------------------------------

function extern_setting_get(string $key, string $default = ''): string
{
    $st = extern_db()->prepare('SELECT value FROM extern_settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

/** Trägername, wie er im Auftritt der öffentlichen Anmeldung erscheint (Einstellung, Vorgabe wie bisher). */
function extern_traeger(): string
{
    return extern_setting_get('traeger_name', 'Studierendenvertretung');
}

function extern_setting_set(string $key, string $value): void
{
    extern_db()->prepare('INSERT INTO extern_settings(key, value) VALUES(?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

// ---------------------------------------------------------------------------
// Register: Was es an Auswahlmöglichkeiten gibt
// ---------------------------------------------------------------------------

/** Feldarten des Anmeldeformulars. */
function extern_field_types(): array
{
    return [
        'text'     => 'Kurzer Text (eine Zeile)',
        'textarea' => 'Langer Text (mehrere Zeilen)',
        'single'   => 'Einfachauswahl (eine Antwort)',
        'multi'    => 'Mehrfachauswahl (mehrere Antworten)',
        'bool'     => 'Ja/Nein (Häkchen)',
        'number'   => 'Zahl',
    ];
}

/** Feldarten, bei denen es Antwortmöglichkeiten gibt (und damit auch Kontingente). */
function extern_field_has_options(string $type): bool
{
    return in_array($type, ['single', 'multi'], true);
}

function extern_group_modes(): array
{
    return [
        'none'  => 'Keine Gruppen',
        'fixed' => 'Feste Gruppen – ich lege sie selbst an',
        'auto'  => 'Anzahl aus der Wunschgröße errechnen',
    ];
}

function extern_visibility_modes(): array
{
    return [
        'never'  => 'Niemand – die Einteilung bleibt intern',
        'signed' => 'Nur Angemeldete sehen ihre eigene Gruppe',
        'public' => 'Alle Gruppen öffentlich einsehbar',
    ];
}

function extern_statuses(): array
{
    return ['draft' => 'Entwurf', 'open' => 'Anmeldung offen', 'closed' => 'Anmeldung geschlossen', 'archived' => 'Archiviert'];
}

// ---------------------------------------------------------------------------
// Veranstaltungen lesen
// ---------------------------------------------------------------------------

/**
 * Alle Veranstaltungen. $referate = null → alles (Vorsitz/Admin oder global freigeschaltet);
 * sonst nur die, die eines der genannten Referate betreut.
 */
function extern_events_all(?array $referate = null): array
{
    if ($referate === null) return extern_db()->query('SELECT * FROM events ORDER BY id DESC')->fetchAll();
    if (!$referate) return [];
    $platz = implode(',', array_fill(0, count($referate), '?'));
    $st = extern_db()->prepare('SELECT * FROM events WHERE referat IN (' . $platz . ') ORDER BY id DESC');
    $st->execute(array_values($referate));
    return $st->fetchAll();
}

function extern_event_get(int $id): ?array
{
    $st = extern_db()->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function extern_event_by_slug(string $slug): ?array
{
    $st = extern_db()->prepare('SELECT * FROM events WHERE slug = ?');
    $st->execute([trim($slug)]);
    return $st->fetch() ?: null;
}

/** Nimmt die Veranstaltung gerade Anmeldungen an? (Status offen UND im Zeitfenster) */
function extern_reg_open(array $e): bool
{
    if ((string)$e['status'] !== 'open') return false;
    $jetzt = date('Y-m-d H:i:s');
    if (trim((string)$e['reg_from'])  !== '' && $e['reg_from']  > $jetzt) return false;
    if (trim((string)$e['reg_until']) !== '' && $e['reg_until'] < $jetzt) return false;
    return true;
}

/** Darf man sich noch abmelden? (Ohne Frist: solange die Veranstaltung nicht vorbei ist.) */
function extern_cancel_open(array $e): bool
{
    $jetzt = date('Y-m-d H:i:s');
    $frist = trim((string)$e['cancel_until']);
    if ($frist !== '') return $frist >= $jetzt;
    $termin = trim((string)$e['starts_at']);
    return $termin === '' || $termin >= $jetzt;
}

/** Zahlen einer Veranstaltung – die Grundlage für Plätze, Warteliste und die Übersicht. */
function extern_counts(int $eventId): array
{
    $q = function (string $sql) use ($eventId): int {
        $st = extern_db()->prepare($sql);
        $st->execute([$eventId]);
        return (int)$st->fetchColumn();
    };
    // Plätze zählen PERSONEN, nicht Anmeldungen: Eine Sammelanmeldung zu viert belegt vier.
    return [
        'personen'   => $q("SELECT COALESCE(SUM(party_size), 0) FROM signups WHERE event_id = ? AND status = 'confirmed'"),
        // „belegt" zählt auch die noch nicht bestätigten mit: Sonst könnten zwei Leute denselben
        // letzten Platz zugesagt bekommen, und eine:r fällt beim Bestätigen wieder raus.
        'belegt'     => $q("SELECT COALESCE(SUM(party_size), 0) FROM signups WHERE event_id = ? AND status IN ('confirmed','pending')"),
        'anmeldungen'=> $q("SELECT COUNT(*) FROM signups WHERE event_id = ? AND status = 'confirmed'"),
        'warteliste' => $q("SELECT COALESCE(SUM(party_size), 0) FROM signups WHERE event_id = ? AND status = 'waitlist'"),
        'offen'      => $q("SELECT COUNT(*) FROM signups WHERE event_id = ? AND status = 'pending'"),
        'abgemeldet' => $q("SELECT COUNT(*) FROM signups WHERE event_id = ? AND status = 'cancelled'"),
    ];
}

/** Freie Plätze (null = unbegrenzt). */
function extern_free_seats(array $e): ?int
{
    $cap = (int)$e['capacity'];
    if ($cap <= 0) return null;
    return max(0, $cap - extern_counts((int)$e['id'])['belegt']);
}

// ---------------------------------------------------------------------------
// Veranstaltungen schreiben
// ---------------------------------------------------------------------------

function extern_slugify(string $roh): string
{
    $s = mb_strtolower(trim($roh));
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return trim((string)preg_replace('~[^a-z0-9]+~', '-', $s), '-');
}

function extern_slug_free(string $wunsch, int $exceptId = 0): string
{
    $basis = mb_substr(extern_slugify($wunsch) ?: 'anmeldung', 0, 60);
    $kandidat = $basis; $n = 1;
    while (true) {
        $st = extern_db()->prepare('SELECT id FROM events WHERE slug = ? AND id <> ?');
        $st->execute([$kandidat, $exceptId]);
        if (!$st->fetch()) return $kandidat;
        $kandidat = $basis . '-' . (++$n);
    }
}

/** Datum aus dem Formular in die Speicherform bringen ('' bleibt ''). */
function extern_dt_norm(string $d): string
{
    $d = trim(str_replace('T', ' ', $d));
    if ($d === '') return '';
    if (!preg_match('~^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$~', $d)) return '';
    if (strlen($d) === 10) $d .= ' 00:00:00';
    if (strlen($d) === 16) $d .= ':00';
    return $d;
}

/**
 * Veranstaltung anlegen ($id = 0) oder ändern. $teil begrenzt das Speichern auf einen
 * Assistenten-Schritt – so überschreibt Schritt 2 nicht die Felder aus Schritt 4.
 * Rückgabe: ['ok' => bool, 'id' => int, 'msg' => string, 'typ' => 'success'|'error']
 */
function extern_event_save(int $id, array $post, int $memberId, string $teil = 'alles'): array
{
    $alt = $id > 0 ? extern_event_get($id) : null;
    if ($id > 0 && !$alt) return ['ok' => false, 'id' => 0, 'msg' => 'Veranstaltung nicht gefunden.', 'typ' => 'error'];

    $setz = [];   // Spalte => Wert
    $fehler = '';

    if ($teil === 'alles' || $teil === 'basis') {
        $titel = trim((string)($post['title'] ?? ''));
        if ($titel === '') return ['ok' => false, 'id' => $id, 'msg' => 'Bitte einen Titel angeben.', 'typ' => 'error'];
        $ref = trim((string)($post['referat'] ?? ''));
        $setz['title']     = mb_substr($titel, 0, 200);
        $setz['intro']     = mb_substr(trim((string)($post['intro'] ?? '')), 0, 5000);
        $setz['place']     = mb_substr(trim((string)($post['place'] ?? '')), 0, 200);
        $setz['starts_at'] = extern_dt_norm((string)($post['starts_at'] ?? ''));
        $setz['ends_at']   = extern_dt_norm((string)($post['ends_at'] ?? ''));
        $setz['referat']   = $ref;
    }

    if ($teil === 'alles' || $teil === 'anmeldung') {
        $von = extern_dt_norm((string)($post['reg_from'] ?? ''));
        $bis = extern_dt_norm((string)($post['reg_until'] ?? ''));
        if ($von !== '' && $bis !== '' && $von > $bis) $fehler = 'Das Anmeldeende liegt vor dem Beginn.';
        $setz['reg_from']     = $von;
        $setz['reg_until']    = $bis;
        $setz['capacity']     = max(0, (int)($post['capacity'] ?? 0));
        $setz['waitlist']     = !empty($post['waitlist']) ? 1 : 0;
        $setz['cancel_until'] = extern_dt_norm((string)($post['cancel_until'] ?? ''));
        $setz['confirm_mail'] = !empty($post['confirm_mail']) ? 1 : 0;
        $setz['selfservice']  = !empty($post['selfservice']) ? 1 : 0;
    }

    if ($teil === 'alles' || $teil === 'gruppen') {
        $modus = (string)($post['group_mode'] ?? 'none');
        if (!isset(extern_group_modes()[$modus])) $modus = 'none';
        $setz['group_mode']  = $modus;
        $setz['group_size']  = max(0, (int)($post['group_size'] ?? 0));
        $setz['allow_code']  = !empty($post['allow_code']) ? 1 : 0;
        $setz['allow_party'] = !empty($post['allow_party']) ? 1 : 0;
        $setz['party_max']   = max(1, min(20, (int)($post['party_max'] ?? 4)));
        $mix = (int)($post['mix_field_id'] ?? 0);
        $setz['mix_field_id'] = $mix > 0 ? $mix : null;
        if ($modus === 'auto' && $setz['group_size'] < 2) $fehler = 'Für errechnete Gruppen braucht es eine Wunschgröße ab 2.';
        // Rotation: 0 schaltet sie ab. Mehr als 12 Runden ist kein Abend mehr.
        $setz['rot_rounds']  = max(0, min(12, (int)($post['rot_rounds'] ?? 0)));
        $setz['rot_start']   = extern_dt_norm((string)($post['rot_start'] ?? ''));
        $setz['rot_minutes'] = max(10, min(600, (int)($post['rot_minutes'] ?? 60)));
    }

    if ($teil === 'alles' || $teil === 'sichtbarkeit') {
        $sicht = (string)($post['show_groups'] ?? 'signed');
        if (!isset(extern_visibility_modes()[$sicht])) $sicht = 'signed';
        $setz['show_groups'] = $sicht;
        $setz['show_names']  = !empty($post['show_names']) ? 1 : 0;
        $setz['show_count']  = !empty($post['show_count']) ? 1 : 0;
        $setz['keep_days']   = max(7, min(730, (int)($post['keep_days'] ?? EXTERN_KEEP_DAYS)));
    }

    if ($fehler !== '') return ['ok' => false, 'id' => $id, 'msg' => $fehler, 'typ' => 'error'];

    if ($id <= 0) {
        $setz['slug'] = extern_slug_free((string)($post['slug'] ?? '') !== '' ? (string)$post['slug'] : (string)$setz['title']);
        $setz['status'] = 'draft';
        $setz['salt'] = bin2hex(random_bytes(16));
        $setz['created_by'] = $memberId ?: null;
        $spalten = array_keys($setz);
        extern_db()->prepare('INSERT INTO events(' . implode(',', $spalten) . ') VALUES(' . implode(',', array_fill(0, count($spalten), '?')) . ')')
            ->execute(array_values($setz));
        return ['ok' => true, 'id' => (int)extern_db()->lastInsertId(), 'msg' => 'Veranstaltung angelegt.', 'typ' => 'success'];
    }

    // Der Schlüssel steckt nach dem Öffnen in verteilten Links – dann bleibt er, wie er ist.
    if ((string)$alt['status'] === 'draft' && trim((string)($post['slug'] ?? '')) !== '') {
        $setz['slug'] = extern_slug_free((string)$post['slug'], $id);
    }
    if (!$setz) return ['ok' => true, 'id' => $id, 'msg' => 'Nichts zu speichern.', 'typ' => 'success'];
    $sql = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($setz)));
    $werte = array_values($setz);
    $werte[] = $id;
    extern_db()->prepare('UPDATE events SET ' . $sql . ' WHERE id = ?')->execute($werte);
    return ['ok' => true, 'id' => $id, 'msg' => 'Gespeichert.', 'typ' => 'success'];
}

/** Status setzen. Öffnen geht nur mit Titel und – falls Gruppen fest sind – mit Gruppen. */
function extern_event_status(int $id, string $status): array
{
    if (!isset(extern_statuses()[$status])) return ['ok' => false, 'msg' => 'Unbekannter Status.'];
    $e = extern_event_get($id);
    if (!$e) return ['ok' => false, 'msg' => 'Veranstaltung nicht gefunden.'];
    if ($status === 'open') {
        if (trim((string)$e['title']) === '') return ['ok' => false, 'msg' => 'Ohne Titel lässt sich nichts öffnen.'];
        if ((string)$e['group_mode'] === 'fixed' && !extern_groups_of($id)) {
            return ['ok' => false, 'msg' => 'Es sind feste Gruppen eingestellt, aber noch keine angelegt.'];
        }
    }
    extern_db()->prepare('UPDATE events SET status = ? WHERE id = ?')->execute([$status, $id]);
    return ['ok' => true, 'msg' => match ($status) {
        'open'     => 'Die Anmeldung ist offen – der Link kann verteilt werden.',
        'closed'   => 'Anmeldung geschlossen.',
        'archived' => 'Veranstaltung archiviert.',
        default    => 'Zurück in den Entwurf – öffentlich ist die Anmeldung damit nicht mehr erreichbar.',
    }];
}

function extern_event_delete(int $id): void
{
    extern_db()->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
}

// ---------------------------------------------------------------------------
// Formularfelder
// ---------------------------------------------------------------------------

function extern_fields_of(int $eventId): array
{
    $st = extern_db()->prepare('SELECT * FROM fields WHERE event_id = ? ORDER BY sort, id');
    $st->execute([$eventId]);
    $felder = $st->fetchAll();
    if (!$felder) return [];
    $ids = implode(',', array_map(fn($f) => (int)$f['id'], $felder));
    $opt = extern_db()->query('SELECT * FROM field_opts WHERE field_id IN (' . $ids . ') ORDER BY sort, id')->fetchAll();
    $byF = [];
    foreach ($opt as $o) $byF[(int)$o['field_id']][] = $o;
    foreach ($felder as &$f) $f['options'] = $byF[(int)$f['id']] ?? [];
    return $felder;
}

function extern_field_get(int $id): ?array
{
    $st = extern_db()->prepare('SELECT * FROM fields WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) return null;
    $o = extern_db()->prepare('SELECT * FROM field_opts WHERE field_id = ? ORDER BY sort, id');
    $o->execute([$id]);
    $f['options'] = $o->fetchAll();
    return $f;
}

/**
 * Feld anlegen/ändern. Antwortmöglichkeiten kommen als Zeilen; ein „| 20" am Ende setzt
 * das Kontingent: „Workshop A | 20".
 */
function extern_field_save(int $eventId, int $id, array $post): array
{
    $typ = (string)($post['type'] ?? 'text');
    if (!isset(extern_field_types()[$typ])) $typ = 'text';
    $label = trim((string)($post['label'] ?? ''));
    if ($label === '') return ['ok' => false, 'msg' => 'Bitte eine Beschriftung angeben.'];

    $zeilen = [];
    foreach (preg_split('~\r?\n~', (string)($post['options'] ?? '')) as $z) {
        $z = trim($z);
        if ($z === '') continue;
        $kontingent = 0;
        if (preg_match('~^(.*?)\s*\|\s*(\d+)$~', $z, $m)) { $z = trim($m[1]); $kontingent = (int)$m[2]; }
        if ($z !== '') $zeilen[] = ['label' => mb_substr($z, 0, 200), 'capacity' => $kontingent];
    }
    if (extern_field_has_options($typ) && count($zeilen) < 2) {
        return ['ok' => false, 'msg' => 'Für eine Auswahl braucht es mindestens zwei Antwortmöglichkeiten.'];
    }

    $werte = [
        (int)($post['sort'] ?? 0), $typ, mb_substr($label, 0, 200),
        mb_substr(trim((string)($post['help'] ?? '')), 0, 500),
        !empty($post['required']) ? 1 : 0,
        ($post['num_min'] ?? '') === '' ? null : (int)$post['num_min'],
        ($post['num_max'] ?? '') === '' ? null : (int)$post['num_max'],
    ];

    if ($id <= 0) {
        $st = extern_db()->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM fields WHERE event_id = ?');
        $st->execute([$eventId]);
        $werte[0] = (int)$st->fetchColumn();
        array_unshift($werte, $eventId);
        extern_db()->prepare('INSERT INTO fields(event_id, sort, type, label, help, required, num_min, num_max) VALUES(?,?,?,?,?,?,?,?)')
            ->execute($werte);
        $id = (int)extern_db()->lastInsertId();
    } else {
        $vorhanden = extern_field_get($id);
        if (!$vorhanden || (int)$vorhanden['event_id'] !== $eventId) return ['ok' => false, 'msg' => 'Feld nicht gefunden.'];
        $werte[] = $id;
        extern_db()->prepare('UPDATE fields SET sort=?, type=?, label=?, help=?, required=?, num_min=?, num_max=? WHERE id=?')
            ->execute($werte);
    }
    extern_field_opts_sync($id, $zeilen);
    return ['ok' => true, 'msg' => 'Feld gespeichert.', 'id' => $id];
}

/**
 * Antwortmöglichkeiten abgleichen. Gleiche Beschriftung = derselbe Eintrag, damit bereits
 * abgegebene Antworten ihre Zuordnung behalten.
 */
function extern_field_opts_sync(int $fieldId, array $zeilen): void
{
    $st = extern_db()->prepare('SELECT * FROM field_opts WHERE field_id = ?');
    $st->execute([$fieldId]);
    $alt = [];
    foreach ($st->fetchAll() as $o) $alt[(string)$o['label']] = (int)$o['id'];

    $behalten = [];
    foreach ($zeilen as $i => $z) {
        if (isset($alt[$z['label']])) {
            extern_db()->prepare('UPDATE field_opts SET sort = ?, capacity = ? WHERE id = ?')
                ->execute([$i, $z['capacity'], $alt[$z['label']]]);
            $behalten[] = $alt[$z['label']];
        } else {
            extern_db()->prepare('INSERT INTO field_opts(field_id, sort, label, capacity) VALUES(?,?,?,?)')
                ->execute([$fieldId, $i, $z['label'], $z['capacity']]);
            $behalten[] = (int)extern_db()->lastInsertId();
        }
    }
    foreach (array_diff(array_values($alt), $behalten) as $oid) {
        extern_db()->prepare('DELETE FROM field_opts WHERE id = ?')->execute([$oid]);
    }
}

function extern_field_delete(int $id): void
{
    extern_db()->prepare('DELETE FROM fields WHERE id = ?')->execute([$id]);
}

/** Feld in der Reihenfolge verschieben ($dir < 0 = nach oben); normalisiert auf 0..n-1. */
function extern_field_move(int $id, int $dir): void
{
    $f = extern_field_get($id);
    if (!$f) return;
    extern_reorder('fields', 'event_id', (int)$f['event_id'], $id, $dir);
}

// ---------------------------------------------------------------------------
// Gruppen
// ---------------------------------------------------------------------------

function extern_groups_of(int $eventId): array
{
    $st = extern_db()->prepare('SELECT * FROM groups WHERE event_id = ? ORDER BY sort, id');
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function extern_group_get(int $id): ?array
{
    $st = extern_db()->prepare('SELECT * FROM groups WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function extern_group_save(int $eventId, int $id, array $post): array
{
    $name = trim((string)($post['name'] ?? ''));
    if ($name === '') return ['ok' => false, 'msg' => 'Bitte einen Namen für die Gruppe angeben.'];
    $werte = [
        mb_substr($name, 0, 120),
        max(0, (int)($post['capacity'] ?? 0)),
        mb_substr(trim((string)($post['place'] ?? '')), 0, 200),
        extern_dt_norm((string)($post['starts_at'] ?? '')),
        mb_substr(trim((string)($post['note'] ?? '')), 0, 500),
        ($post['leader_id'] ?? '') === '' ? null : (int)$post['leader_id'],
    ];
    if ($id <= 0) {
        $st = extern_db()->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM groups WHERE event_id = ?');
        $st->execute([$eventId]);
        array_unshift($werte, (int)$st->fetchColumn());
        array_unshift($werte, $eventId);
        extern_db()->prepare('INSERT INTO groups(event_id, sort, name, capacity, place, starts_at, note, leader_id) VALUES(?,?,?,?,?,?,?,?)')
            ->execute($werte);
        return ['ok' => true, 'msg' => 'Gruppe angelegt.', 'id' => (int)extern_db()->lastInsertId()];
    }
    $g = extern_group_get($id);
    if (!$g || (int)$g['event_id'] !== $eventId) return ['ok' => false, 'msg' => 'Gruppe nicht gefunden.'];
    $werte[] = $id;
    extern_db()->prepare('UPDATE groups SET name=?, capacity=?, place=?, starts_at=?, note=?, leader_id=? WHERE id=?')->execute($werte);
    return ['ok' => true, 'msg' => 'Gruppe gespeichert.', 'id' => $id];
}

function extern_group_delete(int $id): void
{
    // Die Zuordnung der Anmeldungen fällt per ON DELETE SET NULL von selbst weg –
    // die Leute bleiben angemeldet, sie sind nur wieder unverteilt.
    extern_db()->prepare('DELETE FROM groups WHERE id = ?')->execute([$id]);
}

function extern_group_move(int $id, int $dir): void
{
    $g = extern_group_get($id);
    if (!$g) return;
    extern_reorder('groups', 'event_id', (int)$g['event_id'], $id, $dir);
}

/** Gemeinsames Verschieben in der Reihenfolge – für Felder und Gruppen dasselbe Muster. */
function extern_reorder(string $tabelle, string $elternSpalte, int $elternId, int $id, int $dir): void
{
    // Weißliste für BEIDE Namen: Sie landen unescaped in der Abfrage. Fehlt hier eine Tabelle,
    // tut das Verschieben stillschweigend nichts – genau so ist „stations" beim Bau aufgefallen.
    if (!in_array($tabelle, ['fields', 'groups', 'stations'], true)) return;
    if (!in_array($elternSpalte, ['event_id'], true)) return;
    $st = extern_db()->prepare('SELECT id FROM ' . $tabelle . ' WHERE ' . $elternSpalte . ' = ? ORDER BY sort, id');
    $st->execute([$elternId]);
    $ids = array_map(fn($r) => (int)$r['id'], $st->fetchAll());
    $pos = array_search($id, $ids, true);
    if ($pos === false) return;
    $swap = $pos + ($dir < 0 ? -1 : 1);
    if ($swap < 0 || $swap >= count($ids)) return;
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    $up = extern_db()->prepare('UPDATE ' . $tabelle . ' SET sort = ? WHERE id = ?');
    foreach ($ids as $i => $rid) $up->execute([$i, $rid]);
}

// ---------------------------------------------------------------------------
// Mailversand + Kontingent (gleiches Muster wie bei den Umfragen)
//
// Eigene kleine Fassung statt send_mail() aus der lib.php: Der öffentliche Bereich bindet
// die App-Bibliothek nicht ein. Das Kontingent kommt aus dem gemeinsamen Konto
// (mail-pool.php): Wir nehmen, was übrig ist, nachdem alle anderen Bereiche ihren
// Verbrauch eingetragen haben. Ein eigener Deckel ist möglich (0 = keiner).
// ---------------------------------------------------------------------------

function extern_mail_cap_day(): int  { return max(0, (int)extern_setting_get('cap_day', '0')); }
/** Freiwillige Stundenbremse, 0 = keine (Vorgabe). Siehe umfrage_mail_cap_hour(). */
function extern_mail_cap_hour(): int { return max(0, (int)extern_setting_get('cap_hour', '0')); }

function extern_mail_sent(int $stunden): int
{
    $st = extern_db()->prepare('SELECT COUNT(*) FROM mail_log WHERE sent_at >= ?');
    $st->execute([date('Y-m-d H:i:s', time() - $stunden * 3600)]);
    return (int)$st->fetchColumn();
}

function extern_mail_budget(): int
{
    $frei = mail_pool_budget('extern', extern_mail_cap_day());
    $proStunde = extern_mail_cap_hour();
    if ($proStunde > 0) $frei = min($frei, $proStunde - extern_mail_sent(1));
    return max(0, $frei);
}

/** Was pro Tag höchstens für externe Events drin ist – Grundlage der Prognosen. */
function extern_mail_day_allow(): int
{
    $deckel = extern_mail_cap_day();
    return $deckel > 0 ? min($deckel, mail_pool_day_allow()) : mail_pool_day_allow();
}

function extern_mail_note(): void
{
    extern_db()->prepare('INSERT INTO mail_log(sent_at) VALUES(?)')->execute([date('Y-m-d H:i:s')]);
    extern_db()->prepare('DELETE FROM mail_log WHERE sent_at < ?')->execute([date('Y-m-d H:i:s', time() - 172800)]);
    // Zusätzlich ins gemeinsame Konto: Daran rechnen die anderen Bereiche mit.
    mail_pool_note('extern');
}

function extern_mail(string $to, string $subject, string $body): bool
{
    // Absender ist die EINE Adresse der App (mail_pool_absender()) – die eingestellte Adresse
    // dieses Bereichs ist die ANTWORTADRESSE.
    $reply = trim(extern_setting_get('from_email', ''));
    $from  = mail_pool_absender();
    if ($from === '') $from = $reply;
    $to    = trim(str_replace(["\r", "\n"], '', $to));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if ($reply === '' || !filter_var($reply, FILTER_VALIDATE_EMAIL)) $reply = $from;
    $name = trim(str_replace(["\r", "\n"], '', extern_setting_get('from_name', extern_traeger())));
    $headers = [
        'From: ' . ($name !== '' ? $name . ' <' . $from . '>' : $from),
        'Reply-To: ' . $reply,
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: AStA-App',
        'Auto-Submitted: auto-generated',
    ];
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

/**
 * Mail einreihen und – wenn das Kontingent reicht – gleich verschicken.
 * Anders als bei den Umfragen darf sie ruhig warten: Hier hängt keine Anmeldung daran,
 * die verfällt. Die Bestätigung kommt eben eine Stunde später.
 */
function extern_mail_send(int $eventId, ?int $signupId, string $kind, string $to, string $subject, string $body): bool
{
    extern_db()->prepare('INSERT INTO mailqueue(event_id, signup_id, kind, subject, body, email) VALUES(?,?,?,?,?,?)')
        ->execute([$eventId, $signupId, $kind, $subject, $body, $to]);
    $qid = (int)extern_db()->lastInsertId();
    if (extern_mail_budget() <= 0) return false;               // wartet auf den Cron
    $ok = extern_mail($to, $subject, $body);
    extern_db()->prepare("UPDATE mailqueue SET status = ?, tries = tries + 1, email = CASE WHEN ? = 'sent' THEN '' ELSE email END WHERE id = ?")
        ->execute([$ok ? 'sent' : 'queued', $ok ? 'sent' : 'queued', $qid]);
    if ($ok) extern_mail_note();
    return $ok;
}

/** Wartende Mails abarbeiten (Cron). Gibt ['sent','failed','left'] zurück. */
function extern_queue_run(int $max = 120): array
{
    $budget = min($max, extern_mail_budget());
    $offen = fn() => (int)extern_db()->query("SELECT COUNT(*) FROM mailqueue WHERE status = 'queued'")->fetchColumn();
    if ($budget <= 0) return ['sent' => 0, 'failed' => 0, 'left' => $offen()];

    $st = extern_db()->prepare("SELECT * FROM mailqueue WHERE status = 'queued' AND tries < 5 ORDER BY id LIMIT ?");
    $st->execute([$budget]);
    $sent = 0; $failed = 0;
    foreach ($st->fetchAll() as $m) {
        $ok = extern_mail((string)$m['email'], (string)$m['subject'], (string)$m['body']);
        if ($ok) {
            extern_db()->prepare("UPDATE mailqueue SET status = 'sent', email = '', tries = tries + 1 WHERE id = ?")->execute([(int)$m['id']]);
            extern_mail_note();
            $sent++;
        } else {
            // Nach fünf Versuchen aufgeben – sonst blockiert eine kaputte Adresse die Schlange.
            extern_db()->prepare("UPDATE mailqueue SET tries = tries + 1, status = CASE WHEN tries + 1 >= 5 THEN 'failed' ELSE 'queued' END, last_error = ? WHERE id = ?")
                ->execute(['Versand fehlgeschlagen', (int)$m['id']]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'left' => $offen()];
}

/** Öffentliche Adressen des Moduls. */
function extern_url(array $e, string $seite = '', string $token = ''): string
{
    $basis = rtrim(extern_setting_get('base_url', ''), '/');
    if ($basis === '') return '';
    if ($seite === '') return $basis . '/anmeldung/?e=' . rawurlencode((string)$e['slug']);
    return $basis . '/anmeldung/' . $seite . '.php?t=' . $token;
}

/** Pflegbare Texte der Mails. {{…}} wird ersetzt. */
function extern_texts(): array
{
    return [
        'confirm_subject' => ['label' => 'Betreff der Bestätigungsmail', 'default' => 'Bitte bestätige deine Anmeldung: {{TITEL}}'],
        'confirm_body' => ['label' => 'Text der Bestätigungsmail', 'default' =>
            "Hallo {{NAME}},\n\ndu hast dich für „{{TITEL}}\" angemeldet. Mit einem Klick auf diesen Link "
            . "wird deine Anmeldung gültig:\n\n{{LINK}}\n\nOhne diesen Klick zählt sie NICHT.{{TERMIN_SATZ}}\n\n"
            . "Über denselben Link kannst du später deine Angaben ändern oder dich abmelden.\n\n"
            . "Viele Grüße\nDein " . extern_traeger()],
        'welcome_subject' => ['label' => 'Betreff nach der Bestätigung', 'default' => 'Deine Anmeldung steht: {{TITEL}}'],
        'welcome_body' => ['label' => 'Text nach der Bestätigung', 'default' =>
            "Hallo {{NAME}},\n\nschön, dass du dabei bist – deine Anmeldung für „{{TITEL}}\" ist bestätigt.{{TERMIN_SATZ}}{{CODE_SATZ}}\n\n"
            . "Hier kannst du jederzeit deine Angaben ändern oder dich abmelden:\n{{LINK}}\n\n"
            . "Viele Grüße\nDein " . extern_traeger()],
        'waitlist_subject' => ['label' => 'Betreff Warteliste', 'default' => 'Du stehst auf der Warteliste: {{TITEL}}'],
        'waitlist_body' => ['label' => 'Text Warteliste', 'default' =>
            "Hallo {{NAME}},\n\n„{{TITEL}}\" ist gerade ausgebucht – du stehst auf der Warteliste und rückst "
            . "automatisch nach, sobald jemand absagt. Wir melden uns dann bei dir.\n\n"
            . "Hier kannst du deinen Platz auf der Warteliste auch wieder freigeben:\n{{LINK}}\n\n"
            . "Viele Grüße\nDein " . extern_traeger()],
        'assign_subject' => ['label' => 'Betreff der Einteilungs-Mail', 'default' => 'Deine Gruppe für {{TITEL}}'],
        'assign_body' => ['label' => 'Text der Einteilungs-Mail', 'default' =>
            "Hallo {{NAME}},\n\nes ist so weit – hier ist deine Einteilung für „{{TITEL}}\":\n\n{{GRUPPE_SATZ}}{{TERMIN_SATZ}}\n\n{{ROTATION}}\n\n"
            . "Deine Anmeldung und deine Gruppe kannst du hier jederzeit nachsehen:\n{{LINK}}\n\n"
            . "Bis dann!\nDein " . extern_traeger()],
        'promoted_subject' => ['label' => 'Betreff beim Nachrücken', 'default' => 'Ein Platz ist frei geworden: {{TITEL}}'],
        'promoted_body' => ['label' => 'Text beim Nachrücken', 'default' =>
            "Hallo {{NAME}},\n\ngute Nachricht: Bei „{{TITEL}}\" ist ein Platz frei geworden und du bist "
            . "nachgerückt – deine Anmeldung steht.{{TERMIN_SATZ}}\n\n"
            . "Wenn es doch nicht passt, melde dich bitte hier wieder ab, damit die Nächste nachrücken kann:\n{{LINK}}\n\n"
            . "Viele Grüße\nDein " . extern_traeger()],
    ];
}

function extern_text(string $key): string
{
    $reg = extern_texts();
    return isset($reg[$key]) ? extern_setting_get('text_' . $key, (string)$reg[$key]['default']) : '';
}

/** Platzhalter einer Anmeldung füllen. */
function extern_vars(array $e, array $signup, string $link): array
{
    $termin = trim((string)$e['starts_at']);
    return [
        '{{TITEL}}' => (string)$e['title'],
        '{{NAME}}'  => (string)$signup['name'],
        '{{LINK}}'  => $link,
        '{{ORT}}'   => (string)$e['place'],
        '{{TERMIN_SATZ}}' => $termin !== ''
            ? "\n\nTermin: " . date('d.m.Y', strtotime($termin)) . ', ' . date('H:i', strtotime($termin)) . ' Uhr'
              . (trim((string)$e['place']) !== '' ? ' · ' . $e['place'] : '') . '.'
            : '',
        '{{CODE_SATZ}}' => (int)$e['allow_code'] === 1 && trim((string)$signup['code']) !== ''
            ? "\n\nDein Code zum Weitergeben: " . $signup['code']
              . "\nWer ihn bei seiner eigenen Anmeldung eingibt, landet mit dir in derselben Gruppe."
            : '',
    ];
}

// ---------------------------------------------------------------------------
// Anmelden
// ---------------------------------------------------------------------------

/** Kurzer, gut vorlesbarer Code (keine Verwechslungszeichen wie O/0 oder I/1). */
function extern_code_new(): string
{
    $zeichen = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 6; $i++) $c .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    return $c;
}

/** Gibt es diesen Code in dieser Veranstaltung schon? (Dann schließt man sich ihm an.) */
function extern_code_exists(int $eventId, string $code): bool
{
    $st = extern_db()->prepare("SELECT 1 FROM signups WHERE event_id = ? AND code = ? AND status <> 'cancelled' LIMIT 1");
    $st->execute([$eventId, mb_strtoupper(trim($code))]);
    return (bool)$st->fetchColumn();
}

/** Wie oft wurde eine Antwortmöglichkeit schon gewählt? (Für das Kontingent je Option.) */
function extern_option_taken(int $optionId): int
{
    $st = extern_db()->prepare("SELECT COALESCE(SUM(s.party_size), 0) FROM answers a
        JOIN signups s ON s.id = a.signup_id
        WHERE a.option_id = ? AND s.status IN ('confirmed','pending','waitlist')");
    $st->execute([$optionId]);
    return (int)$st->fetchColumn();
}

/** Ist eine Antwortmöglichkeit noch zu haben? */
function extern_option_free(array $opt, int $braucht = 1): bool
{
    $cap = (int)$opt['capacity'];
    if ($cap <= 0) return true;
    return extern_option_taken((int)$opt['id']) + $braucht <= $cap;
}

/**
 * Antworten prüfen. Gibt ['ok', 'msg', 'daten'] zurück – erst wenn alles stimmt, wird
 * überhaupt etwas geschrieben.
 */
function extern_answers_check(array $e, array $post, int $personen = 1, int $exceptSignup = 0): array
{
    $felder = extern_fields_of((int)$e['id']);
    $sauber = [];
    foreach ($felder as $f) {
        $fid = (int)$f['id'];
        $roh = $post['f'][$fid] ?? null;
        $typ = (string)$f['type'];
        $pflicht = (int)$f['required'] === 1;
        $gueltig = [];
        foreach ($f['options'] as $o) $gueltig[(int)$o['id']] = $o;

        $leer = fn() => ['ok' => false, 'msg' => 'Bitte „' . $f['label'] . '" ausfüllen.', 'daten' => []];

        if ($typ === 'single' || $typ === 'multi') {
            $wahlen = $typ === 'single'
                ? (is_array($roh) ? [] : array_filter([(int)$roh]))
                : array_values(array_unique(array_map('intval', (array)$roh)));
            $wahlen = array_values(array_filter($wahlen, fn($x) => isset($gueltig[$x])));
            if (!$wahlen) { if ($pflicht) return $leer(); continue; }
            foreach ($wahlen as $w) {
                // Kontingent: Wer schon drinsteht, belegt seinen Platz nicht doppelt.
                $braucht = $personen;
                if ($exceptSignup > 0) {
                    $st = extern_db()->prepare('SELECT 1 FROM answers WHERE signup_id = ? AND option_id = ?');
                    $st->execute([$exceptSignup, $w]);
                    if ($st->fetchColumn()) $braucht = 0;
                }
                if ($braucht > 0 && !extern_option_free($gueltig[$w], $braucht)) {
                    return ['ok' => false, 'msg' => '„' . $gueltig[$w]['label'] . '" ist leider schon ausgebucht.', 'daten' => []];
                }
                $sauber[] = ['f' => $fid, 'o' => $w, 't' => '', 'n' => null];
            }
        } elseif ($typ === 'bool') {
            $ja = !empty($roh);
            if (!$ja && $pflicht) return $leer();
            $sauber[] = ['f' => $fid, 'o' => null, 't' => $ja ? 'ja' : 'nein', 'n' => $ja ? 1 : 0];
        } elseif ($typ === 'number') {
            if ($roh === null || $roh === '' || is_array($roh)) { if ($pflicht) return $leer(); continue; }
            if (!is_numeric($roh)) return ['ok' => false, 'msg' => '„' . $f['label'] . '" braucht eine Zahl.', 'daten' => []];
            $n = (int)$roh;
            if ($f['num_min'] !== null && $n < (int)$f['num_min']) return ['ok' => false, 'msg' => '„' . $f['label'] . '" ist zu klein.', 'daten' => []];
            if ($f['num_max'] !== null && $n > (int)$f['num_max']) return ['ok' => false, 'msg' => '„' . $f['label'] . '" ist zu groß.', 'daten' => []];
            $sauber[] = ['f' => $fid, 'o' => null, 't' => '', 'n' => $n];
        } else {
            $txt = is_array($roh) ? '' : mb_substr(trim((string)$roh), 0, 2000);
            if ($txt === '') { if ($pflicht) return $leer(); continue; }
            $sauber[] = ['f' => $fid, 'o' => null, 't' => $txt, 'n' => null];
        }
    }
    return ['ok' => true, 'msg' => '', 'daten' => $sauber];
}

/** Antworten einer Anmeldung ersetzen. */
function extern_answers_write(int $signupId, array $daten): void
{
    extern_db()->prepare('DELETE FROM answers WHERE signup_id = ?')->execute([$signupId]);
    $ins = extern_db()->prepare('INSERT INTO answers(signup_id, field_id, option_id, value_text, value_num) VALUES(?,?,?,?,?)');
    foreach ($daten as $a) $ins->execute([$signupId, (int)$a['f'], $a['o'], (string)$a['t'], $a['n']]);
}

function extern_answers_of(int $signupId): array
{
    $st = extern_db()->prepare('SELECT * FROM answers WHERE signup_id = ?');
    $st->execute([$signupId]);
    return $st->fetchAll();
}

/**
 * Anmeldung entgegennehmen.
 * Rückgabe: ['ok', 'msg', 'status', 'token', 'signup_id'].
 */
function extern_signup_add(array $e, array $post): array
{
    if (!extern_reg_open($e)) return ['ok' => false, 'msg' => 'Für diese Veranstaltung läuft gerade keine Anmeldung.'];

    $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 120);
    $mail = mb_strtolower(trim((string)($post['email'] ?? '')));
    if ($name === '') return ['ok' => false, 'msg' => 'Bitte deinen Namen angeben.'];
    if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'msg' => 'Bitte eine gültige Mailadresse angeben.'];

    // Dieselbe Adresse nicht zweimal – sonst sind Absagen und Nachrücken nicht mehr zuzuordnen.
    $st = extern_db()->prepare("SELECT id FROM signups WHERE event_id = ? AND email = ? AND status <> 'cancelled'");
    $st->execute([(int)$e['id'], $mail]);
    if ($st->fetchColumn()) return ['ok' => false, 'msg' => 'Mit dieser Adresse gibt es schon eine Anmeldung. Über den Link in deiner Bestätigungsmail kannst du sie ändern.'];

    $personen = 1;
    $begleitung = '';
    if ((int)$e['allow_party'] === 1) {
        $personen = max(1, min((int)$e['party_max'], (int)($post['party_size'] ?? 1)));
        $begleitung = mb_substr(trim((string)($post['party_names'] ?? '')), 0, 1000);
    }

    $pruef = extern_answers_check($e, $post, $personen);
    if (!$pruef['ok']) return ['ok' => false, 'msg' => $pruef['msg']];

    // Code: entweder einem bestehenden anschließen oder einen eigenen bekommen
    $code = '';
    if ((int)$e['allow_code'] === 1) {
        $eingabe = mb_strtoupper(trim((string)($post['code'] ?? '')));
        if ($eingabe !== '') {
            if (!extern_code_exists((int)$e['id'], $eingabe)) {
                return ['ok' => false, 'msg' => 'Diesen Code gibt es hier nicht. Bitte prüfen – oder das Feld leer lassen, dann bekommst du einen eigenen.'];
            }
            $code = $eingabe;
        } else {
            do { $code = extern_code_new(); } while (extern_code_exists((int)$e['id'], $code));
        }
    }

    // Platz oder Warteliste?
    $frei = extern_free_seats($e);
    $warte = $frei !== null && $frei < $personen;
    if ($warte && (int)$e['waitlist'] !== 1) {
        return ['ok' => false, 'msg' => 'Die Veranstaltung ist leider ausgebucht.'];
    }
    $status = $warte ? 'waitlist' : ((int)$e['confirm_mail'] === 1 ? 'pending' : 'confirmed');

    $token = bin2hex(random_bytes(EXTERN_TOKEN_LEN));
    extern_db()->prepare('INSERT INTO signups(event_id, name, email, token_hash, status, party_size, party_names, code, confirmed_at)
        VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$e['id'], $name, $mail, hash('sha256', $token), $status, $personen, $begleitung, $code,
                   $status === 'confirmed' ? date('Y-m-d H:i:s') : null]);
    $sid = (int)extern_db()->lastInsertId();
    extern_answers_write($sid, $pruef['daten']);

    return ['ok' => true, 'msg' => '', 'status' => $status, 'token' => $token, 'signup_id' => $sid];
}

function extern_signup_get(int $id): ?array
{
    $st = extern_db()->prepare('SELECT * FROM signups WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Anmeldung über den Selbstbedienungs-Link finden. */
function extern_signup_by_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !preg_match('~^[0-9a-f]{' . (EXTERN_TOKEN_LEN * 2) . '}$~', $token)) return null;
    $st = extern_db()->prepare('SELECT * FROM signups WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

/** Anmeldung bestätigen (Klick auf den Link in der Mail). */
function extern_signup_confirm(array $signup): array
{
    if ((string)$signup['status'] === 'cancelled') return ['ok' => false, 'msg' => 'Diese Anmeldung wurde bereits zurückgezogen.'];
    if ((string)$signup['status'] !== 'pending')   return ['ok' => true,  'msg' => 'Diese Anmeldung war schon bestätigt.'];
    extern_db()->prepare("UPDATE signups SET status = 'confirmed', confirmed_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), (int)$signup['id']]);
    return ['ok' => true, 'msg' => 'Deine Anmeldung ist bestätigt.'];
}

/** Angaben nachträglich ändern (Selbstbedienung). */
function extern_signup_update(array $e, array $signup, array $post): array
{
    if ((int)$e['selfservice'] !== 1) return ['ok' => false, 'msg' => 'Für diese Veranstaltung lassen sich Angaben nicht selbst ändern.'];
    if ((string)$signup['status'] === 'cancelled') return ['ok' => false, 'msg' => 'Diese Anmeldung ist zurückgezogen.'];
    if (!extern_reg_open($e)) return ['ok' => false, 'msg' => 'Die Anmeldefrist ist vorbei – Änderungen sind nicht mehr möglich.'];

    $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 120);
    if ($name === '') return ['ok' => false, 'msg' => 'Bitte deinen Namen angeben.'];

    $personen = (int)$signup['party_size'];
    $begleitung = (string)$signup['party_names'];
    if ((int)$e['allow_party'] === 1) {
        $neu = max(1, min((int)$e['party_max'], (int)($post['party_size'] ?? $personen)));
        // Mehr Personen brauchen mehr Platz – das muss durch dieselbe Prüfung wie eine Neuanmeldung.
        $frei = extern_free_seats($e);
        if ($frei !== null && $neu > $personen && ($neu - $personen) > $frei) {
            return ['ok' => false, 'msg' => 'So viele zusätzliche Plätze sind nicht mehr frei.'];
        }
        $personen = $neu;
        $begleitung = mb_substr(trim((string)($post['party_names'] ?? '')), 0, 1000);
    }

    $pruef = extern_answers_check($e, $post, $personen, (int)$signup['id']);
    if (!$pruef['ok']) return ['ok' => false, 'msg' => $pruef['msg']];

    extern_db()->prepare('UPDATE signups SET name = ?, party_size = ?, party_names = ? WHERE id = ?')
        ->execute([$name, $personen, $begleitung, (int)$signup['id']]);
    extern_answers_write((int)$signup['id'], $pruef['daten']);
    return ['ok' => true, 'msg' => 'Deine Angaben sind gespeichert.'];
}

/**
 * Abmelden. Danach rückt von der Warteliste nach, wer als Nächstes passt.
 * Gibt zusätzlich zurück, wer nachgerückt ist – die Seite kann dann die Mail auslösen.
 */
function extern_signup_cancel(array $e, array $signup): array
{
    if ((string)$signup['status'] === 'cancelled') return ['ok' => true, 'msg' => 'Du warst bereits abgemeldet.', 'nachrücker' => []];
    if (!extern_cancel_open($e)) return ['ok' => false, 'msg' => 'Die Abmeldefrist ist vorbei – bitte meld dich direkt beim AStA.', 'nachrücker' => []];
    extern_db()->prepare("UPDATE signups SET status = 'cancelled', cancelled_at = ?, group_id = NULL WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), (int)$signup['id']]);
    return ['ok' => true, 'msg' => 'Du bist abgemeldet. Schade – vielleicht beim nächsten Mal!', 'nachrücker' => extern_waitlist_promote($e)];
}

/**
 * Warteliste nachrücken lassen: der Reihe nach, solange der Platz reicht.
 * Wer nicht mehr hineinpasst (Vierergruppe, zwei Plätze frei), wird ÜBERSPRUNGEN und behält
 * seinen Platz in der Reihe – sonst rückt eine große Gruppe nie nach.
 */
function extern_waitlist_promote(array $e): array
{
    if ((int)$e['waitlist'] !== 1) return [];
    $cap = (int)$e['capacity'];
    if ($cap <= 0) return [];                      // ohne Begrenzung gibt es keine Warteliste

    $st = extern_db()->prepare("SELECT * FROM signups WHERE event_id = ? AND status = 'waitlist' ORDER BY id");
    $st->execute([(int)$e['id']]);
    $nachgerückt = [];
    foreach ($st->fetchAll() as $w) {
        $frei = $cap - extern_counts((int)$e['id'])['belegt'];
        if ($frei <= 0) break;
        if ((int)$w['party_size'] > $frei) continue;   // passt (noch) nicht – Reihenfolge bleibt
        extern_db()->prepare("UPDATE signups SET status = 'confirmed', confirmed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), (int)$w['id']]);
        $nachgerückt[] = extern_signup_get((int)$w['id']);
    }
    return $nachgerückt;
}

/** Anmeldungen einer Veranstaltung (für Listen und die Einteilung). */
function extern_signups_of(int $eventId, ?string $status = null): array
{
    if ($status === null) {
        $st = extern_db()->prepare('SELECT * FROM signups WHERE event_id = ? ORDER BY id');
        $st->execute([$eventId]);
    } else {
        $st = extern_db()->prepare('SELECT * FROM signups WHERE event_id = ? AND status = ? ORDER BY id');
        $st->execute([$eventId, $status]);
    }
    return $st->fetchAll();
}

/**
 * Aufräumen: Anmeldedaten nach der Aufbewahrungsfrist löschen, und unbestätigte
 * Anmeldungen, die nach 48 Stunden niemand bestätigt hat – die halten sonst Plätze besetzt.
 */
function extern_prune(): int
{
    $n = 0;
    $alt = extern_db()->prepare("DELETE FROM signups WHERE status = 'pending' AND created_at < ?");
    $alt->execute([date('Y-m-d H:i:s', time() - 48 * 3600)]);
    $n += $alt->rowCount();

    foreach (extern_db()->query('SELECT id, starts_at, created_at, keep_days FROM events')->fetchAll() as $e) {
        // Ohne Termin zählt das Anlegedatum. Sonst lägen die Anmeldedaten einer Veranstaltung
        // ohne Datum für immer herum – genau das soll die Frist ja verhindern.
        $bezug = trim((string)$e['starts_at']) !== '' ? (string)$e['starts_at'] : (string)$e['created_at'];
        if (trim($bezug) === '') continue;
        $grenze = strtotime($bezug) + max(7, (int)$e['keep_days']) * 86400;
        if (time() < $grenze) continue;
        // Die Zahlen bleiben (Anzahl Anmeldungen), die Personen verschwinden.
        $d = extern_db()->prepare('DELETE FROM signups WHERE event_id = ?');
        $d->execute([(int)$e['id']]);
        $n += $d->rowCount();
        extern_db()->prepare('DELETE FROM mailqueue WHERE event_id = ?')->execute([(int)$e['id']]);
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Einteilung
//
// Das Verfahren, in einem Satz: feste Blöcke zuerst und absteigend nach Größe, dann Einzelne
// in die jeweils kleinste Gruppe. Was nirgends passt, wird GEMELDET statt zerrissen.
// ---------------------------------------------------------------------------

/**
 * Unteilbare Blöcke bilden: Wer denselben Code hat, gehört zusammen; eine Sammelanmeldung
 * ist ohnehin ein Block. Alle anderen sind Blöcke der Größe eins.
 * Nur bestätigte Anmeldungen – auf der Warteliste wird niemand eingeteilt.
 */
function extern_blocks(int $eventId): array
{
    $blocks = [];
    foreach (extern_signups_of($eventId, 'confirmed') as $s) {
        $code = trim((string)$s['code']);
        $key  = $code !== '' ? 'c:' . $code : 's:' . (int)$s['id'];
        if (!isset($blocks[$key])) $blocks[$key] = ['key' => $key, 'signups' => [], 'size' => 0, 'group_id' => null, 'gemischt' => false];
        $blocks[$key]['signups'][] = $s;
        $blocks[$key]['size'] += max(1, (int)$s['party_size']);
        $gid = (int)$s['group_id'] ?: null;
        if ($blocks[$key]['group_id'] === null && count($blocks[$key]['signups']) === 1) {
            $blocks[$key]['group_id'] = $gid;
        } elseif ($blocks[$key]['group_id'] !== $gid) {
            // Von Hand auseinandergezogen: dann gilt der Block als unverteilt.
            $blocks[$key]['group_id'] = null;
            $blocks[$key]['gemischt'] = true;
        }
    }
    return array_values($blocks);
}

/** Gruppen mit ihrer Belegung und den zugeteilten Anmeldungen. */
function extern_group_load(int $eventId): array
{
    $out = [];
    foreach (extern_groups_of($eventId) as $g) $out[(int)$g['id']] = ['gruppe' => $g, 'personen' => 0, 'signups' => []];
    $out[0] = ['gruppe' => null, 'personen' => 0, 'signups' => []];   // Sammelstelle für Unverteilte
    foreach (extern_signups_of($eventId, 'confirmed') as $s) {
        $gid = (int)$s['group_id'];
        if (!isset($out[$gid])) $gid = 0;
        $out[$gid]['personen'] += max(1, (int)$s['party_size']);
        $out[$gid]['signups'][] = $s;
    }
    return $out;
}

/** Gruppen aus der Wunschgröße anlegen (Modus „automatisch"). */
function extern_groups_auto(int $eventId): array
{
    $e = extern_event_get($eventId);
    if (!$e || (string)$e['group_mode'] !== 'auto') return ['ok' => false, 'msg' => 'Dafür muss der Modus auf „errechnen" stehen.'];
    $wunsch = max(2, (int)$e['group_size']);
    $personen = extern_counts($eventId)['personen'];
    if ($personen < 1) return ['ok' => false, 'msg' => 'Noch niemand angemeldet – es gibt nichts zu verteilen.'];
    $anzahl = max(1, (int)ceil($personen / $wunsch));
    $vorhanden = count(extern_groups_of($eventId));
    // Bewusst OHNE Kapazität je Gruppe: Die Zahl der Gruppen steuert die Größe, den Rest
    // macht der Ausgleich. Feste Obergrenzen würden hier nur dazu führen, dass Blöcke
    // „nirgends passen", obwohl überall Platz wäre.
    for ($i = $vorhanden + 1; $i <= $anzahl; $i++) {
        extern_group_save($eventId, 0, ['name' => 'Gruppe ' . $i, 'capacity' => 0]);
    }
    $neu = $anzahl - $vorhanden;
    return ['ok' => true, 'msg' => $neu > 0
        ? $neu . ' Gruppe' . ($neu > 1 ? 'n' : '') . ' angelegt (' . $personen . ' Personen, Wunschgröße ' . $wunsch . ').'
        : 'Es sind schon genug Gruppen da (' . $vorhanden . ' für ' . $personen . ' Personen).'];
}

/**
 * Automatisch einteilen.
 * $onlyNew = true lässt bestehende Zuteilungen stehen und verteilt nur, was noch offen ist.
 * Rückgabe: ['ok', 'verteilt', 'offen', 'msg', 'probleme' => [Klartext-Hinweise]]
 */
function extern_assign_auto(int $eventId, bool $onlyNew = false): array
{
    $e = extern_event_get($eventId);
    if (!$e) return ['ok' => false, 'verteilt' => 0, 'offen' => 0, 'msg' => 'Veranstaltung nicht gefunden.', 'probleme' => []];
    $gruppen = extern_groups_of($eventId);
    if (!$gruppen) return ['ok' => false, 'verteilt' => 0, 'offen' => 0, 'msg' => 'Es gibt noch keine Gruppen.', 'probleme' => []];

    $blocks = extern_blocks($eventId);
    if (!$blocks) return ['ok' => false, 'verteilt' => 0, 'offen' => 0, 'msg' => 'Es gibt noch keine bestätigten Anmeldungen.', 'probleme' => []];

    // Belegung vorbereiten
    $size = []; $cap = [];
    foreach ($gruppen as $g) { $size[(int)$g['id']] = 0; $cap[(int)$g['id']] = (int)$g['capacity']; }

    $todo = [];
    foreach ($blocks as $b) {
        if ($onlyNew && $b['group_id'] !== null && isset($size[$b['group_id']])) {
            $size[$b['group_id']] += $b['size'];
            continue;
        }
        $todo[] = $b;
    }
    if (!$todo) return ['ok' => true, 'verteilt' => 0, 'offen' => 0, 'msg' => 'Alle waren schon eingeteilt.', 'probleme' => []];

    // Mischkriterium: je Anmeldung die gewählte Antwort merken
    $mischFeld = (int)($e['mix_field_id'] ?? 0);
    $mischWert = [];
    if ($mischFeld > 0) {
        $st = extern_db()->prepare('SELECT signup_id, option_id FROM answers WHERE field_id = ? AND option_id IS NOT NULL');
        $st->execute([$mischFeld]);
        foreach ($st->fetchAll() as $a) $mischWert[(int)$a['signup_id']] = (int)$a['option_id'];
    }
    $mischZaehler = [];   // gruppe => [option_id => Anzahl]
    foreach ($gruppen as $g) $mischZaehler[(int)$g['id']] = [];

    // Große Blöcke zuerst: Wer zu viert ist und zuletzt drankommt, findet sonst keinen Platz mehr.
    usort($todo, fn($a, $b) => $b['size'] <=> $a['size']);

    $verteilt = 0; $probleme = []; $offen = 0;
    foreach ($todo as $b) {
        // Wo passt der Block überhaupt hinein?
        $moeglich = [];
        foreach ($gruppen as $g) {
            $gid = (int)$g['id'];
            if ($cap[$gid] > 0 && $size[$gid] + $b['size'] > $cap[$gid]) continue;
            $moeglich[] = $gid;
        }
        if (!$moeglich) {
            $offen += $b['size'];
            $probleme[] = ($b['size'] > 1 ? 'Ein Block mit ' . $b['size'] . ' Personen' : 'Eine Anmeldung')
                . ' passt in keine Gruppe (größter freier Platz: ' . extern_max_frei($gruppen, $size, $cap) . ').';
            continue;
        }

        // Auswahl: die kleinste Gruppe. Bei Gleichstand entscheidet – falls eingestellt –
        // das Mischkriterium: dorthin, wo die eigene Ausprägung am seltensten vorkommt.
        usort($moeglich, function (int $x, int $y) use ($size, $cap) {
            if ($size[$x] !== $size[$y]) return $size[$x] <=> $size[$y];
            $fx = $cap[$x] > 0 ? $cap[$x] - $size[$x] : PHP_INT_MAX;
            $fy = $cap[$y] > 0 ? $cap[$y] - $size[$y] : PHP_INT_MAX;
            return $fy <=> $fx;
        });
        $ziel = $moeglich[0];
        if ($mischFeld > 0 && count($b['signups']) === 1) {
            $opt = $mischWert[(int)$b['signups'][0]['id']] ?? 0;
            if ($opt > 0) {
                $kleinste = $size[$moeglich[0]];
                $gleich = array_values(array_filter($moeglich, fn($g) => $size[$g] === $kleinste));
                usort($gleich, fn($x, $y) => ($mischZaehler[$x][$opt] ?? 0) <=> ($mischZaehler[$y][$opt] ?? 0));
                $ziel = $gleich[0];
            }
        }

        foreach ($b['signups'] as $s) {
            extern_db()->prepare('UPDATE signups SET group_id = ? WHERE id = ?')->execute([$ziel, (int)$s['id']]);
            $opt = $mischWert[(int)$s['id']] ?? 0;
            if ($opt > 0) $mischZaehler[$ziel][$opt] = ($mischZaehler[$ziel][$opt] ?? 0) + 1;
        }
        $size[$ziel] += $b['size'];
        $verteilt += $b['size'];
    }

    $msg = $verteilt . ' Person' . ($verteilt === 1 ? '' : 'en') . ' eingeteilt.';
    if ($offen > 0) $msg .= ' ' . $offen . ' konnten nicht untergebracht werden.';
    return ['ok' => true, 'verteilt' => $verteilt, 'offen' => $offen, 'msg' => $msg, 'probleme' => array_slice($probleme, 0, 8)];
}

/** Größter freier Platz über alle Gruppen – für die ehrliche Fehlermeldung. */
function extern_max_frei(array $gruppen, array $size, array $cap): int
{
    $max = 0;
    foreach ($gruppen as $g) {
        $gid = (int)$g['id'];
        $frei = $cap[$gid] > 0 ? $cap[$gid] - $size[$gid] : PHP_INT_MAX;
        if ($frei > $max) $max = $frei;
    }
    return $max === PHP_INT_MAX ? 9999 : $max;
}

/** Eine Anmeldung von Hand verschieben (0 = wieder unverteilt). */
function extern_assign_set(int $signupId, int $groupId): bool
{
    $s = extern_signup_get($signupId);
    if (!$s) return false;
    if ($groupId > 0) {
        $g = extern_group_get($groupId);
        if (!$g || (int)$g['event_id'] !== (int)$s['event_id']) return false;
    }
    extern_db()->prepare('UPDATE signups SET group_id = ? WHERE id = ?')->execute([$groupId > 0 ? $groupId : null, $signupId]);
    return true;
}

/** Alle Zuteilungen einer Veranstaltung aufheben. */
function extern_assign_clear(int $eventId): int
{
    $st = extern_db()->prepare('UPDATE signups SET group_id = NULL WHERE event_id = ?');
    $st->execute([$eventId]);
    return $st->rowCount();
}

// ---------------------------------------------------------------------------
// Nachrichten an die Angemeldeten
// ---------------------------------------------------------------------------

/** Wen kann man anschreiben? */
function extern_recipient_groups(): array
{
    return [
        'confirmed' => 'Alle Angemeldeten',
        'waitlist'  => 'Nur die Warteliste',
        'pending'   => 'Nur die noch nicht Bestätigten',
        'group'     => 'Eine bestimmte Gruppe',
        'assigned'  => 'Alle mit Gruppe (Einteilung verschicken)',
    ];
}

/** Die passenden Anmeldungen zu einer Auswahl. */
function extern_recipients(int $eventId, string $wen, int $groupId = 0): array
{
    if ($wen === 'group' && $groupId > 0) {
        $st = extern_db()->prepare("SELECT * FROM signups WHERE event_id = ? AND group_id = ? AND status = 'confirmed' ORDER BY name COLLATE NOCASE");
        $st->execute([$eventId, $groupId]);
        return $st->fetchAll();
    }
    if ($wen === 'assigned') {
        $st = extern_db()->prepare("SELECT * FROM signups WHERE event_id = ? AND group_id IS NOT NULL AND status = 'confirmed' ORDER BY name COLLATE NOCASE");
        $st->execute([$eventId]);
        return $st->fetchAll();
    }
    if (!in_array($wen, ['confirmed', 'waitlist', 'pending'], true)) return [];
    return extern_signups_of($eventId, $wen);
}

/**
 * Neuen Selbstbedienungs-Link ausstellen.
 *
 * Nötig, weil von den alten Links nur der Hash gespeichert ist – wir können sie also nicht
 * noch einmal in eine Mail schreiben. Wer einen neuen bekommt, dessen alter gilt nicht mehr;
 * die Oberfläche sagt das dazu.
 */
function extern_token_reset(int $signupId): string
{
    $token = bin2hex(random_bytes(EXTERN_TOKEN_LEN));
    extern_db()->prepare('UPDATE signups SET token_hash = ? WHERE id = ?')->execute([hash('sha256', $token), $signupId]);
    return $token;
}

/** Platzhalter rund um die Gruppe einer Anmeldung. */
function extern_group_vars(array $signup): array
{
    $g = (int)$signup['group_id'] > 0 ? extern_group_get((int)$signup['group_id']) : null;
    return [
        '{{GRUPPE}}'     => $g ? (string)$g['name'] : '',
        '{{TREFFPUNKT}}' => $g ? (string)$g['place'] : '',
        '{{GRUPPENZEIT}}' => $g && trim((string)$g['starts_at']) !== '' ? substr((string)$g['starts_at'], 11, 5) . ' Uhr' : '',
        '{{ROTATION}}'   => $g ? extern_rotation_text((int)$g['id']) : '',
        '{{GRUPPE_SATZ}}' => $g
            ? 'Deine Gruppe: ' . $g['name']
              . (trim((string)$g['place']) !== '' ? "\nTreffpunkt: " . $g['place'] : '')
              . (trim((string)$g['starts_at']) !== '' ? "\nLos geht es um " . substr((string)$g['starts_at'], 11, 5) . ' Uhr' : '')
              . (trim((string)$g['note']) !== '' ? "\n" . $g['note'] : '')
            : 'Du bist noch keiner Gruppe zugeteilt.',
    ];
}

/**
 * Nachricht an mehrere Angemeldete einreihen.
 * Enthält der Text {{LINK}}, bekommt jede Person einen frischen Selbstbedienungs-Link –
 * ältere Links werden dadurch ungültig.
 * Rückgabe: ['ok', 'anzahl', 'sofort', 'msg']
 */
function extern_send_bulk(array $e, array $empfaenger, string $subject, string $body, string $kind = 'info'): array
{
    $subject = trim($subject);
    $body    = trim($body);
    if ($subject === '' || $body === '') return ['ok' => false, 'anzahl' => 0, 'sofort' => 0, 'msg' => 'Betreff und Text dürfen nicht leer sein.'];
    if (!$empfaenger) return ['ok' => false, 'anzahl' => 0, 'sofort' => 0, 'msg' => 'Für diese Auswahl gibt es niemanden.'];

    $brauchtLink = str_contains($body, '{{LINK}}') || str_contains($subject, '{{LINK}}');
    $sofort = 0;
    foreach ($empfaenger as $s) {
        $link = '';
        if ($brauchtLink) {
            $token = extern_token_reset((int)$s['id']);
            $link = extern_url($e, 'meine', $token);
        }
        $vars = array_merge(extern_vars($e, $s, $link), extern_group_vars($s));
        if (extern_mail_send((int)$e['id'], (int)$s['id'], $kind, (string)$s['email'], strtr($subject, $vars), strtr($body, $vars))) $sofort++;
    }
    $n = count($empfaenger);
    $wartet = $n - $sofort;
    return ['ok' => true, 'anzahl' => $n, 'sofort' => $sofort,
        'msg' => $n . ' Nachricht' . ($n === 1 ? '' : 'en') . ' vorbereitet – ' . $sofort . ' sofort verschickt'
            . ($wartet > 0 ? ', ' . $wartet . ' warten auf freies Kontingent (der Cron holt sie nach)' : '') . '.'];
}

/** Anmeldungen als CSV – für Listen, Namensschilder und alles, was sonst in Tabellen lebt. */
function extern_export_csv(int $eventId): void
{
    $e = extern_event_get($eventId);
    $felder = extern_fields_of($eventId);
    $gruppen = [];
    foreach (extern_groups_of($eventId) as $g) $gruppen[(int)$g['id']] = (string)$g['name'];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="anmeldungen-' . preg_replace('~[^a-z0-9-]~', '', (string)$e['slug']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM, damit Excel die Umlaute versteht

    $kopf = ['Name', 'E-Mail', 'Status', 'Personen', 'Begleitung', 'Code', 'Gruppe', 'Angemeldet am'];
    foreach ($felder as $f) $kopf[] = (string)$f['label'];
    fputcsv($out, $kopf, ';');

    $status = ['confirmed' => 'dabei', 'waitlist' => 'Warteliste', 'pending' => 'unbestätigt', 'cancelled' => 'abgemeldet'];
    foreach (extern_signups_of($eventId) as $s) {
        $an = extern_answers_of((int)$s['id']);
        $zeile = [
            (string)$s['name'], (string)$s['email'], $status[(string)$s['status']] ?? (string)$s['status'],
            (int)$s['party_size'], (string)$s['party_names'], (string)$s['code'],
            $gruppen[(int)$s['group_id']] ?? '', substr((string)$s['created_at'], 0, 16),
        ];
        foreach ($felder as $f) {
            $teile = [];
            foreach ($an as $a) {
                if ((int)$a['field_id'] !== (int)$f['id']) continue;
                if ($a['option_id'] !== null) {
                    foreach ($f['options'] as $o) if ((int)$o['id'] === (int)$a['option_id']) $teile[] = (string)$o['label'];
                } elseif ((string)$a['value_text'] !== '') { $teile[] = (string)$a['value_text']; }
                elseif ($a['value_num'] !== null) { $teile[] = (string)(int)$a['value_num']; }
            }
            $zeile[] = implode(', ', $teile);
        }
        fputcsv($out, $zeile, ';');
    }
    fclose($out);
}

// ---------------------------------------------------------------------------
// Rotationsplan (Option)
//
// Eine Kneipentour ist nicht „eine Gruppe pro Kneipe", sondern ein Fahrplan: Jede Gruppe
// zieht Runde für Runde weiter. Aus ist der Normalfall – die Rotation greift erst, wenn
// Runden eingestellt sind UND Stationen angelegt wurden.
// ---------------------------------------------------------------------------

function extern_rotation_on(array $e): bool
{
    return (int)$e['rot_rounds'] > 0 && extern_stations_of((int)$e['id']) !== [];
}

function extern_stations_of(int $eventId): array
{
    $st = extern_db()->prepare('SELECT * FROM stations WHERE event_id = ? ORDER BY sort, id');
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function extern_station_get(int $id): ?array
{
    $st = extern_db()->prepare('SELECT * FROM stations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function extern_station_save(int $eventId, int $id, array $post): array
{
    $name = trim((string)($post['name'] ?? ''));
    if ($name === '') return ['ok' => false, 'msg' => 'Bitte einen Namen für die Station angeben.'];
    $werte = [mb_substr($name, 0, 120), mb_substr(trim((string)($post['place'] ?? '')), 0, 200),
              mb_substr(trim((string)($post['note'] ?? '')), 0, 500)];
    if ($id <= 0) {
        $st = extern_db()->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM stations WHERE event_id = ?');
        $st->execute([$eventId]);
        array_unshift($werte, (int)$st->fetchColumn());
        array_unshift($werte, $eventId);
        extern_db()->prepare('INSERT INTO stations(event_id, sort, name, place, note) VALUES(?,?,?,?,?)')->execute($werte);
        return ['ok' => true, 'msg' => 'Station angelegt.'];
    }
    $vorhanden = extern_station_get($id);
    if (!$vorhanden || (int)$vorhanden['event_id'] !== $eventId) return ['ok' => false, 'msg' => 'Station nicht gefunden.'];
    $werte[] = $id;
    extern_db()->prepare('UPDATE stations SET name=?, place=?, note=? WHERE id=?')->execute($werte);
    return ['ok' => true, 'msg' => 'Station gespeichert.'];
}

function extern_station_delete(int $id): void
{
    // Der Plan wird dabei ungültig – die betroffenen Zeilen fallen per Fremdschlüssel weg.
    extern_db()->prepare('DELETE FROM stations WHERE id = ?')->execute([$id]);
}

function extern_station_move(int $id, int $dir): void
{
    $s = extern_station_get($id);
    if ($s) extern_reorder('stations', 'event_id', (int)$s['event_id'], $id, $dir);
}

/**
 * Plan erzeugen: klassisches Weiterrücken. Gruppe i startet an Station i und zieht je Runde
 * eine Station weiter – so besucht jede Gruppe lauter verschiedene Stationen, und solange es
 * mindestens so viele Stationen wie Gruppen gibt, treffen sich nie zwei in derselben.
 * Sind es weniger Stationen, geht das nicht auf; dann sagen wir das offen.
 */
function extern_rotation_build(int $eventId): array
{
    $e = extern_event_get($eventId);
    if (!$e) return ['ok' => false, 'msg' => 'Veranstaltung nicht gefunden.', 'hinweise' => []];
    $runden = (int)$e['rot_rounds'];
    if ($runden < 1) return ['ok' => false, 'msg' => 'Für diese Veranstaltung ist kein Rotationsplan eingestellt.', 'hinweise' => []];

    $gruppen  = extern_groups_of($eventId);
    $stationen = extern_stations_of($eventId);
    if (!$gruppen)   return ['ok' => false, 'msg' => 'Es gibt noch keine Gruppen.', 'hinweise' => []];
    if (!$stationen) return ['ok' => false, 'msg' => 'Es gibt noch keine Stationen.', 'hinweise' => []];

    $hinweise = [];
    $anzS = count($stationen);
    $anzG = count($gruppen);
    if ($runden > $anzS) {
        $hinweise[] = 'Es gibt nur ' . $anzS . ' Station' . ($anzS === 1 ? '' : 'en') . ', aber ' . $runden
            . ' Runden – ab Runde ' . ($anzS + 1) . ' wiederholen sich die Orte.';
    }
    if ($anzG > $anzS) {
        $hinweise[] = 'Mehr Gruppen (' . $anzG . ') als Stationen (' . $anzS . ') – in jeder Runde treffen sich '
            . 'mehrere Gruppen am selben Ort. Mit mindestens ' . $anzG . ' Stationen ginge es ohne Überschneidung auf.';
    }

    $startTs = trim((string)$e['rot_start']) !== '' ? strtotime((string)$e['rot_start']) : 0;
    $minuten = max(10, (int)$e['rot_minutes']);

    extern_db()->prepare('DELETE FROM rotation WHERE event_id = ?')->execute([$eventId]);
    $ins = extern_db()->prepare('INSERT INTO rotation(event_id, round_no, group_id, station_id, starts_at) VALUES(?,?,?,?,?)');
    for ($r = 0; $r < $runden; $r++) {
        $zeit = $startTs ? date('Y-m-d H:i:s', $startTs + $r * $minuten * 60) : '';
        foreach ($gruppen as $i => $g) {
            $station = $stationen[($i + $r) % $anzS];
            $ins->execute([$eventId, $r + 1, (int)$g['id'], (int)$station['id'], $zeit]);
        }
    }
    return ['ok' => true, 'msg' => 'Plan erzeugt: ' . $runden . ' Runden für ' . $anzG . ' Gruppen an ' . $anzS . ' Stationen.',
            'hinweise' => $hinweise];
}

function extern_rotation_clear(int $eventId): int
{
    $st = extern_db()->prepare('DELETE FROM rotation WHERE event_id = ?');
    $st->execute([$eventId]);
    return $st->rowCount();
}

/** Der ganze Plan, nach Runden sortiert: [runde => [ ['gruppe'=>…, 'station'=>…, 'zeit'=>…], … ]]. */
function extern_rotation_of(int $eventId): array
{
    $st = extern_db()->prepare('SELECT * FROM rotation WHERE event_id = ? ORDER BY round_no, id');
    $st->execute([$eventId]);
    $zeilen = $st->fetchAll();
    if (!$zeilen) return [];
    $g = []; foreach (extern_groups_of($eventId) as $x) $g[(int)$x['id']] = $x;
    $s = []; foreach (extern_stations_of($eventId) as $x) $s[(int)$x['id']] = $x;
    $out = [];
    foreach ($zeilen as $z) {
        $out[(int)$z['round_no']][] = [
            'gruppe'  => $g[(int)$z['group_id']] ?? null,
            'station' => $s[(int)$z['station_id']] ?? null,
            'zeit'    => (string)$z['starts_at'],
        ];
    }
    return $out;
}

/** Der Fahrplan EINER Gruppe – das, was in die Mail und auf die Selbstbedienungs-Seite gehört. */
function extern_rotation_for_group(int $groupId): array
{
    $st = extern_db()->prepare('SELECT r.*, s.name AS station_name, s.place AS station_place, s.note AS station_note
        FROM rotation r JOIN stations s ON s.id = r.station_id WHERE r.group_id = ? ORDER BY r.round_no');
    $st->execute([$groupId]);
    return $st->fetchAll();
}

/** Fahrplan als Klartext für Mails ({{ROTATION}}). */
function extern_rotation_text(int $groupId): string
{
    $plan = extern_rotation_for_group($groupId);
    if (!$plan) return '';
    $zeilen = [];
    foreach ($plan as $p) {
        $zeit = trim((string)$p['starts_at']) !== '' ? date('H:i', strtotime((string)$p['starts_at'])) . ' Uhr – ' : '';
        $zeilen[] = $zeit . $p['station_name'] . (trim((string)$p['station_place']) !== '' ? ' (' . $p['station_place'] . ')' : '');
    }
    return "Euer Fahrplan:\n" . implode("\n", $zeilen);
}
