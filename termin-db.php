<?php
/**
 * Öffentlicher Terminplaner – Datenbank und Kernfunktionen.
 *
 * Das Gegenstück zur externen Redeliste: ein Werkzeug, das der AStA kostenfrei für alle
 * anbietet – Fachschaften, Hochschulgruppen, Gremien, wer auch immer einen Termin sucht.
 * Vorbild ist unser interner Terminfinder; die Elemente, die aus den externen Events
 * kommen, sind der Ort, die Höchstzahl je Termin und der Kalender-Export.
 *
 * EIGENE SQLite-Datei, wie bei Pat:innenprogramm, Umfragen und externen Events. Die lib.php
 * der App wird ABSICHTLICH NICHT eingebunden: Dieser Bereich steht wildfremden Menschen offen
 * und kommt den Mitgliederdaten deshalb gar nicht erst nahe.
 *
 * OHNE KONTO. Wer eine Terminumfrage anlegt, bekommt zwei Links:
 *   - den Teilnahme-Link (`slug`) zum Weitergeben,
 *   - den Verwaltungs-Link (`admin_key`), der allein zum Ändern berechtigt.
 * Beide sind lange Zufallszeichen. Es gibt bewusst kein Passwort und keine Registrierung –
 * genau daran scheitert die Konkurrenz an der Uni regelmäßig im Alltag.
 *
 * Sparsam mit Daten: Teilnehmende geben einen Namen an, sonst nichts. Eine Mailadresse wird
 * nur abgefragt, wenn die anlegende Person das ausdrücklich einstellt. Alles zusammen
 * verschwindet automatisch, wenn die Umfrage lange nicht mehr angefasst wurde.
 *
 * WARUM tplan_ UND NICHT termin_: Diese Datei ist die einzige des Terminplaners, die zusammen
 * mit der lib.php geladen wird – auf admin/terminplaner.php. Dort gibt es längst termin_get(),
 * termin_create() und termin_delete() für die geteilten eigenen Termine im Kalender. Gleiche
 * Namen sind in PHP kein Schönheitsfehler, sondern ein sofortiger Fatal-Error – die Seite
 * bliebe komplett weiß. Der Dateiname bleibt termin-db.php (er steht im README und
 * in der Deploy-Anleitung), die Funktionen heißen tplan_. Der Selbsttest hält das fest.
 *
 * ACHTUNG beim Hochladen: data/termin.sqlite darf der rsync NIE mitnehmen (wie asta.sqlite,
 * pat.sqlite, umfrage.sqlite und extern.sqlite) – sonst überschreibt eine leere lokale Datei
 * die echten Daten.
 */

declare(strict_types=1);


// Marke dieser Installation (Logo, App-Symbol) – eigenständig, ohne lib.php.
require_once __DIR__ . '/brand-core.php';

// Gemeinsames Mail-Konto aller Bereiche (eigenständig, ohne lib.php).
require_once __DIR__ . '/mail-pool.php';

/*
 * Der Pfad ist überschreibbar, damit Prüfläufe NIEMALS die echte Datei anfassen: Wer vorher
 * TERMIN_DB_FILE definiert, bekommt seine eigene Wegwerf-Datenbank. Im Betrieb definiert das
 * niemand – dann gilt diese Zeile. Eine leere Testdatei, die per rsync auf dem Server landet,
 * würde sonst echte Daten überschreiben.
 */
if (!defined('TERMIN_DB_FILE')) define('TERMIN_DB_FILE', __DIR__ . '/data/termin.sqlite');

/** Ohne jede Rückmeldung verwaiste Umfragen: die räumen wir früher weg als benutzte. */
const TERMIN_ABANDON_DAYS = 30;

function tplan_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(TERMIN_DB_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . TERMIN_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 4000');
    tplan_schema($pdo);
    return $pdo;
}

/** Schema anlegen/ergänzen. Idempotent, läuft bei jedem Verbindungsaufbau. */
function tplan_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS termin_settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS polls (
        id           INTEGER PRIMARY KEY,
        slug         TEXT    NOT NULL UNIQUE,          -- Teilnahme-Link
        admin_key    TEXT    NOT NULL UNIQUE,          -- Verwaltungs-Link
        title        TEXT    NOT NULL,
        intro        TEXT    NOT NULL DEFAULT '',
        place        TEXT    NOT NULL DEFAULT '',      -- Ort (aus den Events übernommen)
        organizer    TEXT    NOT NULL DEFAULT '',      -- wer fragt (frei gewählter Name)
        contact      TEXT    NOT NULL DEFAULT '',      -- Mailadresse NUR für den Link-Versand
        allow_maybe  INTEGER NOT NULL DEFAULT 1,       -- Vielleicht als dritte Antwort
        show_results TEXT    NOT NULL DEFAULT 'always',-- always | after | orga
        show_names   INTEGER NOT NULL DEFAULT 1,       -- Namen im Ergebnis oder nur Zahlen
        allow_edit   INTEGER NOT NULL DEFAULT 1,       -- ALT, wird nicht mehr gelesen (siehe edit_mode)
        edit_mode    TEXT    NOT NULL DEFAULT 'all',   -- all | own | none, siehe tplan_edit_modes()
        mail_confirm INTEGER NOT NULL DEFAULT 1,       -- Bestätigungsmail an Teilnehmende
        ask_mail     TEXT    NOT NULL DEFAULT 'off',   -- off | optional | required
        ask_comment  INTEGER NOT NULL DEFAULT 1,       -- Bemerkungsfeld anbieten
        max_entries  INTEGER NOT NULL DEFAULT 0,       -- 0 = keine Obergrenze
        deadline     TEXT    NOT NULL DEFAULT '',      -- leer = ohne Frist
        closed       INTEGER NOT NULL DEFAULT 0,       -- von Hand geschlossen
        final_option INTEGER NOT NULL DEFAULT 0,       -- festgelegter Termin
        final_note   TEXT    NOT NULL DEFAULT '',
        created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        touched_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS options (
        id       INTEGER PRIMARY KEY,
        poll_id  INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        day      TEXT    NOT NULL,                     -- JJJJ-MM-TT
        t_from   TEXT    NOT NULL DEFAULT '',          -- HH:MM, leer = ganztägig
        t_to     TEXT    NOT NULL DEFAULT '',
        label    TEXT    NOT NULL DEFAULT '',          -- freier Zusatz, etwa Vormittagsblock
        capacity INTEGER NOT NULL DEFAULT 0            -- 0 = unbegrenzt viele Zusagen
    )");

    // Eine Zeile je Person. `edit_token` steckt nur im persönlichen Änderungs-Link.
    $pdo->exec("CREATE TABLE IF NOT EXISTS entries (
        id         INTEGER PRIMARY KEY,
        poll_id    INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        name       TEXT    NOT NULL,
        email      TEXT    NOT NULL DEFAULT '',
        comment    TEXT    NOT NULL DEFAULT '',
        edit_token TEXT    NOT NULL,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (
        id        INTEGER PRIMARY KEY,
        entry_id  INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
        option_id INTEGER NOT NULL REFERENCES options(id) ON DELETE CASCADE,
        vote      TEXT    NOT NULL DEFAULT 'no',       -- yes | maybe | no
        UNIQUE(entry_id, option_id)
    )");

    // Nachrüsten VOR den Indizes: „CREATE TABLE IF NOT EXISTS" lässt eine bestehende Tabelle
    // unangetastet, und ein Index auf einer fehlenden Spalte bricht das ganze Schema ab.
    tplan_add_columns($pdo, 'polls', [
        'show_names'   => 'INTEGER NOT NULL DEFAULT 1',
        'ask_comment'  => 'INTEGER NOT NULL DEFAULT 1',
        'final_option' => 'INTEGER NOT NULL DEFAULT 0',
        'final_note'   => "TEXT NOT NULL DEFAULT ''",
        'touched_at'   => "TEXT NOT NULL DEFAULT ''",
        'edit_mode'    => "TEXT NOT NULL DEFAULT ''",
        'mail_confirm' => 'INTEGER NOT NULL DEFAULT 1',
    ]);
    /*
     * Nachgerüstete Zeilen haben edit_mode = '' und müssen ihr altes Verhalten behalten:
     * allow_edit = 1 hieß „nur die eigene Antwort", 0 hieß „gar nicht". Neue Umfragen bekommen
     * 'all' – dass jede:r jeden Eintrag korrigieren darf, ist der Normalfall unter Leuten, die
     * gemeinsam einen Termin suchen, und nicht die Ausnahme.
     */
    try {
        $pdo->exec("UPDATE polls SET edit_mode = CASE WHEN allow_edit = 1 THEN 'own' ELSE 'none' END
                    WHERE edit_mode = '' OR edit_mode NOT IN ('all','own','none')");
    } catch (\Throwable $e) { /* frische Datenbank: es gibt noch keine Zeilen */ }
    tplan_add_columns($pdo, 'options', ['capacity' => 'INTEGER NOT NULL DEFAULT 0']);
    tplan_add_columns($pdo, 'entries', ['email' => "TEXT NOT NULL DEFAULT ''"]);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_o_poll ON options(poll_id, day, t_from)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_e_poll ON entries(poll_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_e_token ON entries(edit_token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_v_entry ON votes(entry_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_v_option ON votes(option_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_p_touched ON polls(touched_at)');
}

/** Fehlende Spalten ergänzen – SQLite kennt kein „ADD COLUMN IF NOT EXISTS". */
function tplan_add_columns(PDO $pdo, string $tabelle, array $spalten): void
{
    // Weißliste: Der Tabellenname landet unescaped in der Abfrage.
    if (!in_array($tabelle, ['polls', 'options', 'entries', 'votes'], true)) return;
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
// Einstellungen
// ---------------------------------------------------------------------------

function tplan_setting_get(string $key, string $default = ''): string
{
    try {
        $st = tplan_db()->prepare('SELECT value FROM termin_settings WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (\Throwable $e) { return $default; }
}

/* --- Träger dieser Installation ---------------------------------------------------------
 * Der Terminplaner tritt unter dem Namen seines Trägers auf – in der Kopfzeile, im
 * Seitentitel, in den Mails. Der Name steht in den Einstellungen (Verwaltung → Träger);
 * im Programm steht nur ein neutraler Rückfallwert. */

/** Trägername, wie er im Auftritt erscheint. */
function tplan_traeger(): string
{
    return tplan_setting_get('traeger_name', 'Studierendenvertretung');
}

/** Web-Auftritt des Trägers, ohne Schluss-Schrägstrich. */
function tplan_traeger_url(): string
{
    return rtrim(tplan_setting_get('traeger_url', ''), '/');
}

function tplan_setting_set(string $key, string $value): void
{
    tplan_db()->prepare('INSERT INTO termin_settings(key, value) VALUES(?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

/**
 * Zahlen-Einstellungen an EINER Stelle, mit Vorgabe und Ober-/Untergrenze. So steht nirgends
 * sonst im Code eine Zahl, die jemand in der Verwaltung anders erwartet.
 */
/* Steht bewusst hier und nicht in termin/termin-lib.php: Die Verwaltung laedt nur diese
   Datei, und der Selbsttest kaeme an eine Funktion aus dem oeffentlichen Bereich nie heran. */
/**
 * Häufige Fragen als EINE Quelle: die Startseite baut daraus die aufklappbaren Kästen und
 * zugleich die FAQPage-Auszeichnung. Suchmaschinen verlangen, dass die ausgezeichnete Antwort
 * wortgleich auf der Seite steht – zwei getrennte Fassungen laufen genau da auseinander.
 */
function tplan_faq(): array
{
    return [
        [
            'f' => 'Ich habe meinen Verwaltungs-Link verloren.',
            'a' => 'Dann können wir leider nichts tun: Es gibt keine Konten und keine Passwörter, also auch '
                 . 'keinen Weg, dich als Ersteller:in zu erkennen. Wer beim Anlegen eine Mailadresse angibt, '
                 . 'bekommt beide Links zugeschickt – das ist die einzige Sicherung. Der Teilnahme-Link '
                 . 'funktioniert davon unabhängig weiter.',
        ],
        [
            'f' => 'Wer kann sehen, wer wann kann?',
            'a' => 'Standardmäßig alle, die den Teilnahme-Link haben. Beim Anlegen lässt sich einstellen, dass '
                 . 'nur Zahlen statt Namen erscheinen, dass das Ergebnis erst nach Ende der Frist sichtbar wird '
                 . 'oder dass es ausschließlich die anlegende Person sieht.',
        ],
        [
            'f' => 'Wie lange bleibt eine Umfrage bestehen?',
            'a' => 'Solange sie benutzt wird. Rührt sie ' . (int)tplan_limits()['keep_days'] . ' Tage lang '
                 . 'niemand mehr an, wird sie mit allen Antworten automatisch gelöscht. Umfragen ganz ohne '
                 . 'Rückmeldung verschwinden schon nach ' . (int)TERMIN_ABANDON_DAYS . ' Tagen.',
        ],
        [
            'f' => 'Wer darf das benutzen? Was kostet es?',
            'a' => 'Alle, und nichts. Es gibt keine Bedingung, keine Anmeldung und keine Obergrenze für die '
                 . 'Zahl der Umfragen – egal ob Fachschaft, Verein, Sportgruppe, Familie oder Freundeskreis. '
                 . 'Wir bitten nur darum, es nicht für Wahlen zu verwenden: Dafür braucht es ein Verfahren, '
                 . 'das sicherstellt, dass jede Person genau eine Stimme hat.',
        ],
    ];
}

function tplan_limits(): array
{
    $z = function (string $k, int $vorgabe, int $min, int $max): int {
        $v = (int)tplan_setting_get($k, (string)$vorgabe);
        return max($min, min($max, $v ?: $vorgabe));
    };
    return [
        'options'    => $z('max_options', 30, 2, 60),      // Terminvorschläge je Umfrage
        'entries'    => $z('max_entries', 300, 5, 2000),   // Rückmeldungen je Umfrage
        'per_hour'   => $z('max_new_per_hour', 30, 1, 500),// neue Umfragen insgesamt je Stunde
        'keep_days'  => $z('keep_days', 120, 7, 730),      // Aufbewahrung nach der letzten Änderung
    ];
}

/**
 * Betriebszustand. Drei Stufen statt eines An/Aus-Schalters: Wenn etwas aus dem Ruder läuft,
 * soll man neue Umfragen stoppen können, ohne die laufenden mitten im Betrieb abzuwürgen.
 */
function tplan_mode(): string
{
    $m = tplan_setting_get('mode', 'on');
    return in_array($m, ['on', 'readonly', 'off'], true) ? $m : 'on';
}

function tplan_service_on(): bool { return tplan_mode() !== 'off'; }

/**
 * Neue Umfragen brauchen ZUSÄTZLICH eine hinterlegte Basis-Adresse: Ohne sie lassen sich die
 * beiden Links nicht bauen, und eine Umfrage, die man niemandem schicken kann, ist schlimmer
 * als gar keine. Nach außen sieht das aus wie „gerade pausiert" – die Verwaltung sagt den
 * wahren Grund und hat den Knopf dafür.
 */
function tplan_new_allowed(): bool { return tplan_mode() === 'on' && tplan_base_url() !== ''; }

/** Pflegbare Texte des öffentlichen Bereichs. */
function tplan_texts(): array
{
    return [
        'start_lead' => ['label' => 'Text auf der Startseite (unter der Überschrift)',
            'default' => 'Einen gemeinsamen Termin finden – ohne Konto, ohne Werbung, ohne dass '
                . 'jemand eure Daten weiterverkauft. Der ' . tplan_traeger() . ' stellt das Werkzeug '
                . 'kostenfrei zur Verfügung: für alle, die es brauchen können.'],
        'privacy_note' => ['label' => 'Datenschutz-Hinweis (unter den Formularen)',
            'default' => 'Gespeichert wird nur, was ihr hier eintragt. Eine Mailadresse ist freiwillig und wird '
                . 'nur benutzt, um euch eure Links zu schicken – keine Erinnerungen, keine Werbung, keine Weitergabe. '
                . 'Dazu merkt sich euer Browser 30 Tage lang in einem Cookie, welche Umfragen und Antworten euch '
                . 'gehören und wie ihr heißt; auf der Startseite lässt sich das mit einem Klick wieder löschen. '
                . 'Die Umfrage selbst löscht sich automatisch, wenn sie längere Zeit nicht mehr benutzt wurde.'],
        'mail_subject' => ['label' => 'Betreff der Link-Mail', 'default' => 'Deine Terminumfrage: {{TITEL}}'],
        'confirm_subject' => ['label' => 'Betreff der Bestätigung an Teilnehmende',
            'default' => 'Deine Antwort zu „{{TITEL}}“'],
        'confirm_body' => ['label' => 'Text der Bestätigung an Teilnehmende',
            'default' => "Hallo {{NAME}},\n\ndeine Antwort zu „{{TITEL}}“ ist eingetragen. Danke!\n\n"
                . "Über diesen Link kannst du sie jederzeit ändern – ohne Konto, von jedem Gerät aus:\n{{LINK}}\n\n"
                . "Die Umfrage selbst mit dem aktuellen Stand findest du hier:\n{{UMFRAGE}}\n\n"
                . "Diese Mail kommt nur einmal. Erinnerungen oder Werbung verschicken wir nicht.\n\n"
                . "Viele Grüße\nDein " . tplan_traeger()],
        'mail_body' => ['label' => 'Text der Link-Mail',
            'default' => "Hallo,\n\ndeine Terminumfrage „{{TITEL}}“ steht bereit.\n\n"
                . "Diesen Link gibst du an alle weiter, die abstimmen sollen:\n{{LINK}}\n\n"
                . "Mit diesem Link verwaltest du die Umfrage – bitte NICHT weitergeben:\n{{ADMIN}}\n\n"
                . "Bewahr diese Mail gut auf: Ohne den Verwaltungs-Link können wir dir die Umfrage "
                . "nicht zurückgeben, weil es hier keine Konten und keine Passwörter gibt.\n\n"
                . "Viele Grüße\nDein " . tplan_traeger()],
    ];
}

function tplan_text(string $key): string
{
    $reg = tplan_texts();
    if (!isset($reg[$key])) return '';
    return tplan_setting_get('text_' . $key, (string)$reg[$key]['default']);
}

// ---------------------------------------------------------------------------
// Links
// ---------------------------------------------------------------------------

/**
 * Schlüssel für die Links. Ohne 0, 1, l, o und i – die Links werden vorgelesen, in Chatgruppen
 * kopiert und von Hand abgetippt, da ist jede Verwechslung eine Sackgasse. Das i muss mit raus,
 * auch wenn hier alles klein geschrieben ist: In vielen Schriften sind i, l und 1 kaum zu
 * unterscheiden. Der Selbsttest prüft genau diese fünf Zeichen.
 */
function tplan_key(int $laenge): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $laenge; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

function tplan_key_free(string $spalte, int $laenge): string
{
    if (!in_array($spalte, ['slug', 'admin_key'], true)) $spalte = 'slug';
    for ($i = 0; $i < 12; $i++) {
        $k = tplan_key($laenge);
        $st = tplan_db()->prepare('SELECT 1 FROM polls WHERE ' . $spalte . ' = ?');
        $st->execute([$k]);
        if (!$st->fetchColumn()) return $k;
    }
    return tplan_key($laenge + 6);   // Notnagel: länger ist praktisch immer frei
}

function tplan_base_url(): string { return rtrim(tplan_setting_get('base_url', ''), '/'); }

function tplan_url(array $poll): string
{
    $b = tplan_base_url();
    return $b === '' ? '' : $b . '/termin/t.php?t=' . rawurlencode((string)$poll['slug']);
}

function tplan_admin_url(array $poll): string
{
    $b = tplan_base_url();
    return $b === '' ? '' : $b . '/termin/verwalten.php?k=' . rawurlencode((string)$poll['admin_key']);
}

function tplan_edit_url(array $poll, string $token): string
{
    $b = tplan_base_url();
    return $b === '' ? '' : $b . '/termin/t.php?t=' . rawurlencode((string)$poll['slug']) . '&e=' . rawurlencode($token);
}

// ---------------------------------------------------------------------------
// Lesen
// ---------------------------------------------------------------------------

function tplan_get(int $id): ?array
{
    $st = tplan_db()->prepare('SELECT * FROM polls WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function tplan_by_slug(string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '' || !preg_match('~^[a-z0-9]{4,40}$~', $slug)) return null;
    $st = tplan_db()->prepare('SELECT * FROM polls WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function tplan_by_admin_key(string $key): ?array
{
    $key = trim($key);
    if ($key === '' || !preg_match('~^[a-z0-9]{8,60}$~', $key)) return null;
    $st = tplan_db()->prepare('SELECT * FROM polls WHERE admin_key = ?');
    $st->execute([$key]);
    return $st->fetch() ?: null;
}

function tplan_options(int $pollId): array
{
    $st = tplan_db()->prepare('SELECT * FROM options WHERE poll_id = ? ORDER BY day, CASE WHEN t_from = \'\' THEN 0 ELSE 1 END, t_from, id');
    $st->execute([$pollId]);
    return $st->fetchAll();
}

function tplan_option_get(int $id): ?array
{
    $st = tplan_db()->prepare('SELECT * FROM options WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Alle Rückmeldungen samt Stimmen (Stimmen als option_id => vote). */
function tplan_entries(int $pollId): array
{
    $st = tplan_db()->prepare('SELECT * FROM entries WHERE poll_id = ? ORDER BY id');
    $st->execute([$pollId]);
    $rows = $st->fetchAll();
    if (!$rows) return [];
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    $v = tplan_db()->query('SELECT entry_id, option_id, vote FROM votes WHERE entry_id IN (' . $ids . ')')->fetchAll();
    $byE = [];
    foreach ($v as $r) $byE[(int)$r['entry_id']][(int)$r['option_id']] = (string)$r['vote'];
    foreach ($rows as &$r) $r['votes'] = $byE[(int)$r['id']] ?? [];
    return $rows;
}

function tplan_entry_by_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !preg_match('~^[a-f0-9]{16,64}$~', $token)) return null;
    $st = tplan_db()->prepare('SELECT * FROM entries WHERE edit_token = ?');
    $st->execute([$token]);
    $e = $st->fetch();
    if (!$e) return null;
    $v = tplan_db()->prepare('SELECT option_id, vote FROM votes WHERE entry_id = ?');
    $v->execute([(int)$e['id']]);
    $e['votes'] = [];
    foreach ($v->fetchAll() as $r) $e['votes'][(int)$r['option_id']] = (string)$r['vote'];
    return $e;
}

/**
 * Eine Rückmeldung über ihre Nummer holen – für „jede:r darf jeden Eintrag ändern".
 * Die poll_id muss mitkommen: Sonst könnte man über eine fremde Nummer in einer anderen
 * Umfrage landen, deren Link man gar nicht hat.
 */
function tplan_entry_get(int $pollId, int $entryId): ?array
{
    $st = tplan_db()->prepare('SELECT * FROM entries WHERE id = ? AND poll_id = ?');
    $st->execute([$entryId, $pollId]);
    $e = $st->fetch();
    if (!$e) return null;
    $v = tplan_db()->prepare('SELECT option_id, vote FROM votes WHERE entry_id = ?');
    $v->execute([(int)$e['id']]);
    $e['votes'] = [];
    foreach ($v->fetchAll() as $r) $e['votes'][(int)$r['option_id']] = (string)$r['vote'];
    return $e;
}

function tplan_entry_count(int $pollId): int
{
    $st = tplan_db()->prepare('SELECT COUNT(*) FROM entries WHERE poll_id = ?');
    $st->execute([$pollId]);
    return (int)$st->fetchColumn();
}

// ---------------------------------------------------------------------------
// Zustand
// ---------------------------------------------------------------------------

function tplan_deadline_passed(array $poll): bool
{
    $d = trim((string)($poll['deadline'] ?? ''));
    return $d !== '' && $d < date('Y-m-d H:i:s');
}

/** Nimmt die Umfrage noch Antworten an? */
function tplan_is_open(array $poll): bool
{
    if (!tplan_service_on()) return false;
    if (!empty($poll['closed'])) return false;
    if ((int)($poll['final_option'] ?? 0) > 0) return false;   // Termin steht – fertig
    return !tplan_deadline_passed($poll);
}

/**
 * Darf das Ergebnis gezeigt werden? „after" hält es bis zum Ende zurück – das ist der Modus
 * für Abstimmungen, bei denen die ersten Antworten die späteren nicht lenken sollen.
 */
function tplan_results_visible(array $poll, bool $orga = false): bool
{
    if ($orga) return true;
    return match ((string)$poll['show_results']) {
        'orga'  => false,
        'after' => !tplan_is_open($poll),
        default => true,
    };
}

// ---------------------------------------------------------------------------
// Termine formatieren
// ---------------------------------------------------------------------------

function tplan_weekday(string $tag): string
{
    $t = strtotime($tag . ' 12:00');
    if (!$t) return '';
    return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', $t)];
}

function tplan_option_date(array $o): string
{
    $t = strtotime((string)$o['day'] . ' 12:00');
    return $t ? date('d.m.Y', $t) : (string)$o['day'];
}

function tplan_option_time(array $o): string
{
    $von = trim((string)$o['t_from']);
    $bis = trim((string)$o['t_to']);
    if ($von === '') return 'ganztägig';
    return $bis !== '' ? $von . '–' . $bis . ' Uhr' : 'ab ' . $von . ' Uhr';
}

/** Eine Zeile für Mail, Kalenderdatei und Aufzählungen. */
function tplan_option_label(array $o): string
{
    $s = tplan_weekday((string)$o['day']) . ', ' . tplan_option_date($o) . ', ' . tplan_option_time($o);
    if (trim((string)$o['label']) !== '') $s .= ' (' . trim((string)$o['label']) . ')';
    return $s;
}

/** Liegt der Vorschlag in der Vergangenheit? (Nur ein Hinweis, kein Verbot.) */
function tplan_option_past(array $o): bool
{
    $ende = (string)$o['day'] . ' ' . (trim((string)$o['t_to']) !== '' ? $o['t_to'] : (trim((string)$o['t_from']) !== '' ? $o['t_from'] : '23:59'));
    return strtotime($ende) !== false && strtotime($ende) < time();
}

// ---------------------------------------------------------------------------
// Eingaben prüfen
// ---------------------------------------------------------------------------

/** Datum aus einem <input type="date">. '' = ungültig. */
function tplan_norm_day(string $roh): string
{
    $roh = trim($roh);
    if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $roh, $m)) return '';
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $roh : '';
}

/** Uhrzeit aus einem <input type="time">. '' = keine Angabe. */
function tplan_norm_time(string $roh): string
{
    $roh = trim($roh);
    if ($roh === '') return '';
    if (!preg_match('~^(\d{1,2}):(\d{2})~', $roh, $m)) return '';
    $h = (int)$m[1]; $i = (int)$m[2];
    if ($h > 23 || $i > 59) return '';
    return sprintf('%02d:%02d', $h, $i);
}

/** Frist: Datum mit optionaler Uhrzeit → 'Y-m-d H:i:s'. '' = keine Frist. */
function tplan_norm_deadline(string $tag, string $zeit): string
{
    $d = tplan_norm_day($tag);
    if ($d === '') return '';
    $t = tplan_norm_time($zeit);
    return $d . ' ' . ($t !== '' ? $t . ':00' : '23:59:59');
}

/**
 * Den Inhalt des Geräte-Cookies auf eine feste Form bringen: vier Schlüssel, richtige Typen,
 * gekürzte Texte. Was fehlt oder nicht passt, wird still ersetzt – der Cookie kommt vom Gerät
 * und darf beliebigen Unsinn enthalten (auch gar nichts, beim ersten Besuch).
 *
 * Diese Funktion liegt bewusst HIER und nicht in termin/termin-lib.php: Sie ist reine Rechnerei
 * ohne Sitzung und ohne Cookie, und nur so kommt der Selbsttest an sie heran.
 */
function tplan_dev_norm(mixed $x): array
{
    if (!is_array($x)) $x = [];
    $text = static function (mixed $wert, int $max): string {
        return is_string($wert) ? mb_substr($wert, 0, $max) : '';
    };
    return [
        'n' => $text($x['n'] ?? null, 80),           // selbst eingetippter Name
        'm' => $text($x['m'] ?? null, 190),          // selbst eingetippte Adresse
        'e' => is_array($x['e'] ?? null) ? $x['e'] : [],   // slug => edit_token
        'p' => is_array($x['p'] ?? null) ? $x['p'] : [],   // Verwaltungs-Schlüssel
    ];
}

/**
 * Die Terminvorschläge aus dem Formular einsammeln.
 * Erwartet drei gleich lange Reihen (Tag, von, bis) plus optional Beschriftung und Höchstzahl.
 * Rückgabe: ['ok'=>bool, 'msg'=>string, 'liste'=>[[day,t_from,t_to,label,capacity], …]]
 */
function tplan_collect_options(array $post): array
{
    $tage  = (array)($post['o_day'] ?? []);
    $von   = (array)($post['o_from'] ?? []);
    $bis   = (array)($post['o_to'] ?? []);
    $lab   = (array)($post['o_label'] ?? []);
    $cap   = (array)($post['o_cap'] ?? []);
    $max   = tplan_limits()['options'];

    // Die Reihen kommen aus dem Formular und sind nicht zwingend gleich lang oder überhaupt
    // Zeichenketten (`?o_from[]=x&o_from[][]=y` liefert ein Array). Deshalb liest EIN Helfer
    // alle Felder – sonst fehlt die Absicherung irgendwann an genau einer Stelle.
    $feld = function (array $reihe, $i): string {
        $w = $reihe[$i] ?? '';
        return is_string($w) ? $w : (is_int($w) || is_float($w) ? (string)$w : '');
    };

    $liste = [];
    $gesehen = [];
    foreach ($tage as $i => $roh) {
        $day = tplan_norm_day(is_string($roh) ? $roh : '');
        if ($day === '') continue;                       // leere Zeilen sind einfach leer
        $f = tplan_norm_time($feld($von, $i));
        $t = tplan_norm_time($feld($bis, $i));
        if ($f === '' && $t !== '') { $f = $t; $t = ''; } // nur „bis" ohne „von" ergibt keinen Sinn
        if ($f !== '' && $t !== '' && $t <= $f) $t = '';  // Ende vor Beginn: Ende weglassen
        $l = mb_substr(trim($feld($lab, $i)), 0, 80);
        $c = max(0, min(9999, (int)$feld($cap, $i)));

        $schluessel = $day . '|' . $f . '|' . $t . '|' . $l;
        if (isset($gesehen[$schluessel])) continue;       // doppelte Vorschläge stillschweigend zusammenfassen
        $gesehen[$schluessel] = true;
        $liste[] = ['day' => $day, 't_from' => $f, 't_to' => $t, 'label' => $l, 'capacity' => $c];
        if (count($liste) >= $max) break;
    }
    if (!$liste) return ['ok' => false, 'msg' => 'Bitte mindestens einen Terminvorschlag mit Datum angeben.', 'liste' => []];
    return ['ok' => true, 'msg' => '', 'liste' => $liste];
}

/**
 * Die zwei Wege, auf denen jemand zu seiner Antwort zurückfindet.
 *
 * Das ist keine Feineinstellung, sondern die Grundentscheidung über den Charakter der Umfrage:
 * Entweder ist die Liste eine gemeinsame Tafel, an der alle korrigieren dürfen – dann braucht es
 * überhaupt keine Adressen. Oder jede Person bekommt ihren eigenen Link per Mail und ändert nur
 * ihre eigene Zeile – dann ist die Adresse Pflicht, sonst käme der Link nirgends an.
 *
 * Deshalb steht die Wahl beim Anlegen ganz oben und setzt drei Felder auf einmal. Wer die drei
 * Felder einzeln stellen will (etwa „gar nicht mehr änderbar", wenn eine Umfrage abgeschlossen
 * ist), findet sie später in der Verwaltung.
 */
function tplan_zugang_modi(): array
{
    return [
        'offen' => ['edit_mode' => 'all',  'ask_mail' => 'off',      'mail_confirm' => 0],
        'mail'  => ['edit_mode' => 'own',  'ask_mail' => 'required', 'mail_confirm' => 1],
    ];
}

/** Welcher der beiden Wege beschreibt diese Umfrage? (Für die Anzeige; sonst 'eigen'.) */
function tplan_zugang(array $poll): string
{
    foreach (tplan_zugang_modi() as $name => $soll) {
        if (tplan_edit_mode($poll) === $soll['edit_mode']
            && (string)($poll['ask_mail'] ?? '') === $soll['ask_mail']
            && (int)($poll['mail_confirm'] ?? 0) === $soll['mail_confirm']) return $name;
    }
    return 'eigen';   // von Hand in der Verwaltung zusammengestellt
}

/** Die Einstellungen einer Umfrage aus dem Formular lesen (beim Anlegen wie beim Ändern). */
function tplan_collect_settings(array $post): array
{
    $wahl = fn(string $k, array $erlaubt, string $vorgabe) =>
        in_array((string)($post[$k] ?? ''), $erlaubt, true) ? (string)$post[$k] : $vorgabe;

    $s = [
        'title'        => mb_substr(trim((string)($post['title'] ?? '')), 0, 160),
        'intro'        => mb_substr(trim((string)($post['intro'] ?? '')), 0, 4000),
        'place'        => mb_substr(trim((string)($post['place'] ?? '')), 0, 200),
        'organizer'    => mb_substr(trim((string)($post['organizer'] ?? '')), 0, 120),
        'allow_maybe'  => empty($post['allow_maybe']) ? 0 : 1,
        'show_results' => $wahl('show_results', ['always', 'after', 'orga'], 'always'),
        'show_names'   => empty($post['show_names']) ? 0 : 1,
        'edit_mode'    => $wahl('edit_mode', ['all', 'own', 'none'], 'all'),
        'mail_confirm' => empty($post['mail_confirm']) ? 0 : 1,
        'ask_mail'     => $wahl('ask_mail', ['off', 'optional', 'required'], 'off'),
        'ask_comment'  => empty($post['ask_comment']) ? 0 : 1,
        'max_entries'  => max(0, min(tplan_limits()['entries'], (int)($post['max_entries'] ?? 0))),
        'deadline'     => tplan_norm_deadline((string)($post['deadline_day'] ?? ''), (string)($post['deadline_time'] ?? '')),
    ];

    // Kam der große Umschalter mit (Anlege-Formular), gewinnt er über die Einzelfelder – die
    // stehen dort gar nicht erst im Formular.
    $modi = tplan_zugang_modi();
    $z = (string)($post['zugang'] ?? '');
    if (isset($modi[$z])) $s = array_merge($s, $modi[$z]);
    return $s;
}

// ---------------------------------------------------------------------------
// Anlegen und Ändern
// ---------------------------------------------------------------------------

/** Wie viele Umfragen sind in der letzten Stunde entstanden? (Bremse gegen Fluten) */
function tplan_recent_count(int $stunden = 1): int
{
    $st = tplan_db()->prepare('SELECT COUNT(*) FROM polls WHERE created_at >= ?');
    $st->execute([date('Y-m-d H:i:s', time() - $stunden * 3600)]);
    return (int)$st->fetchColumn();
}

/**
 * Neue Terminumfrage anlegen.
 * Rückgabe: ['ok'=>bool, 'msg'=>string, 'poll'=>?array]
 */
function tplan_create(array $post): array
{
    if (!tplan_new_allowed()) {
        return ['ok' => false, 'msg' => 'Neue Terminumfragen sind gerade nicht möglich. Bitte später noch einmal versuchen.', 'poll' => null];
    }
    if (tplan_recent_count(1) >= tplan_limits()['per_hour']) {
        // Nicht die Person beschuldigen: Von außen sieht ein Ansturm genauso aus wie Missbrauch.
        return ['ok' => false, 'msg' => 'Gerade werden sehr viele Terminumfragen gleichzeitig angelegt. Bitte versuch es in einer Stunde noch einmal.', 'poll' => null];
    }

    $s = tplan_collect_settings($post);
    if ($s['title'] === '') return ['ok' => false, 'msg' => 'Bitte gib der Terminumfrage einen Titel.', 'poll' => null];

    $opt = tplan_collect_options($post);
    if (!$opt['ok']) return ['ok' => false, 'msg' => $opt['msg'], 'poll' => null];

    $mail = trim((string)($post['contact'] ?? ''));
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'Die Mailadresse sieht nicht richtig aus. Du kannst das Feld auch leer lassen.', 'poll' => null];
    }

    $db = tplan_db();
    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO polls(slug, admin_key, title, intro, place, organizer, contact,
                allow_maybe, show_results, show_names, edit_mode, mail_confirm, ask_mail, ask_comment,
                max_entries, deadline, created_at, touched_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                tplan_key_free('slug', 10), tplan_key_free('admin_key', 22),
                $s['title'], $s['intro'], $s['place'], $s['organizer'], mb_substr($mail, 0, 190),
                $s['allow_maybe'], $s['show_results'], $s['show_names'], $s['edit_mode'], $s['mail_confirm'],
                $s['ask_mail'], $s['ask_comment'], $s['max_entries'], $s['deadline'],
                date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
            ]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO options(poll_id, day, t_from, t_to, label, capacity) VALUES(?,?,?,?,?,?)');
        foreach ($opt['liste'] as $o) $ins->execute([$id, $o['day'], $o['t_from'], $o['t_to'], $o['label'], $o['capacity']]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => 'Die Terminumfrage konnte nicht angelegt werden. Bitte noch einmal versuchen.', 'poll' => null];
    }
    return ['ok' => true, 'msg' => '', 'poll' => tplan_get($id)];
}

/** Einstellungen einer bestehenden Umfrage ändern (nur über den Verwaltungs-Link). */
function tplan_update(array $poll, array $post): array
{
    $s = tplan_collect_settings($post);
    if ($s['title'] === '') return ['ok' => false, 'msg' => 'Ohne Titel geht es nicht.'];
    tplan_db()->prepare('UPDATE polls SET title=?, intro=?, place=?, organizer=?, allow_maybe=?,
            show_results=?, show_names=?, edit_mode=?, mail_confirm=?, ask_mail=?, ask_comment=?, max_entries=?, deadline=?
        WHERE id=?')
        ->execute([$s['title'], $s['intro'], $s['place'], $s['organizer'], $s['allow_maybe'],
                   $s['show_results'], $s['show_names'], $s['edit_mode'], $s['mail_confirm'], $s['ask_mail'],
                   $s['ask_comment'], $s['max_entries'], $s['deadline'], (int)$poll['id']]);
    tplan_touch((int)$poll['id']);
    return ['ok' => true, 'msg' => 'Gespeichert.'];
}

/** Nachträglich einen Terminvorschlag ergänzen. */
function tplan_option_add(array $poll, array $post): array
{
    $opt = tplan_collect_options([
        'o_day' => [$post['day'] ?? ''], 'o_from' => [$post['t_from'] ?? ''],
        'o_to' => [$post['t_to'] ?? ''], 'o_label' => [$post['label'] ?? ''], 'o_cap' => [$post['capacity'] ?? 0],
    ]);
    if (!$opt['ok']) return ['ok' => false, 'msg' => 'Bitte ein gültiges Datum angeben.'];
    if (count(tplan_options((int)$poll['id'])) >= tplan_limits()['options']) {
        return ['ok' => false, 'msg' => 'Mehr als ' . tplan_limits()['options'] . ' Terminvorschläge sind nicht vorgesehen.'];
    }
    $o = $opt['liste'][0];
    tplan_db()->prepare('INSERT INTO options(poll_id, day, t_from, t_to, label, capacity) VALUES(?,?,?,?,?,?)')
        ->execute([(int)$poll['id'], $o['day'], $o['t_from'], $o['t_to'], $o['label'], $o['capacity']]);
    tplan_touch((int)$poll['id']);
    return ['ok' => true, 'msg' => 'Terminvorschlag hinzugefügt. Wer schon geantwortet hat, steht dort auf „Nein" – am besten kurz Bescheid geben.'];
}

function tplan_option_remove(array $poll, int $optionId): void
{
    tplan_db()->prepare('DELETE FROM options WHERE id = ? AND poll_id = ?')->execute([$optionId, (int)$poll['id']]);
    if ((int)$poll['final_option'] === $optionId) {
        tplan_db()->prepare("UPDATE polls SET final_option = 0, final_note = '' WHERE id = ?")->execute([(int)$poll['id']]);
    }
    tplan_touch((int)$poll['id']);
}

function tplan_set_closed(int $pollId, bool $zu): void
{
    tplan_db()->prepare('UPDATE polls SET closed = ? WHERE id = ?')->execute([$zu ? 1 : 0, $pollId]);
    tplan_touch($pollId);
}

/**
 * Den Termin festlegen. Das ist der Schritt, den die üblichen Werkzeuge nicht können: Am Ende
 * steht nicht nur eine Tabelle, sondern eine Entscheidung – mit Kalenderdatei für alle.
 */
function tplan_finalize(array $poll, int $optionId, string $notiz): array
{
    if ($optionId > 0) {
        $o = tplan_option_get($optionId);
        if (!$o || (int)$o['poll_id'] !== (int)$poll['id']) return ['ok' => false, 'msg' => 'Diesen Terminvorschlag gibt es hier nicht.'];
    }
    tplan_db()->prepare('UPDATE polls SET final_option = ?, final_note = ? WHERE id = ?')
        ->execute([max(0, $optionId), mb_substr(trim($notiz), 0, 500), (int)$poll['id']]);
    tplan_touch((int)$poll['id']);
    return ['ok' => true, 'msg' => $optionId > 0 ? 'Der Termin steht fest und wird allen angezeigt.' : 'Die Festlegung wurde zurückgenommen.'];
}

function tplan_delete(int $pollId): void
{
    tplan_db()->prepare('DELETE FROM polls WHERE id = ?')->execute([$pollId]);
}

function tplan_entry_delete(int $pollId, int $entryId): void
{
    tplan_db()->prepare('DELETE FROM entries WHERE id = ? AND poll_id = ?')->execute([$entryId, $pollId]);
    tplan_touch($pollId);
}

/** „Zuletzt benutzt" merken – daran hängt, wann die Umfrage automatisch verschwindet. */
function tplan_touch(int $pollId): void
{
    try {
        tplan_db()->prepare('UPDATE polls SET touched_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $pollId]);
    } catch (\Throwable $e) { /* Aufbewahrung ist kein Grund, eine Antwort scheitern zu lassen */ }
}

// ---------------------------------------------------------------------------
// Antworten
// ---------------------------------------------------------------------------

/**
 * Wer darf eine abgegebene Antwort ändern?
 *
 * `all` ist die Vorgabe: Wer gemeinsam einen Termin sucht, korrigiert auch mal für jemand
 * anderen etwas – „Tippfehler im Namen", „die kann doch am 12.". Von Böswilligen auszugehen
 * hieße, den Normalfall für den Ausnahmefall unbequem zu machen. Wem das zu offen ist,
 * stellt auf `own` oder `none` um.
 */
function tplan_edit_modes(): array
{
    return [
        'all'  => 'Alle dürfen jeden Eintrag ändern',
        'own'  => 'Nur die eigene Antwort (über den persönlichen Link)',
        'none' => 'Gar nicht – einmal eingetragen, bleibt es stehen',
    ];
}

function tplan_edit_mode(array $poll): string
{
    $m = (string)($poll['edit_mode'] ?? '');
    if (isset(tplan_edit_modes()[$m])) return $m;
    // Alte Zeile, die die Nachrüstung noch nicht gesehen hat.
    return empty($poll['allow_edit']) ? 'none' : 'own';
}

/** Darf DIESE Anfrage den Eintrag ändern? $eigen = über persönlichen Link oder Gerät erkannt. */
function tplan_may_edit(array $poll, bool $eigen): bool
{
    return match (tplan_edit_mode($poll)) {
        'all'  => true,
        'own'  => $eigen,
        default => false,
    };
}

function tplan_vote_values(array $poll): array
{
    return empty($poll['allow_maybe']) ? ['yes', 'no'] : ['yes', 'maybe', 'no'];
}

/** Wie viele Zusagen hat ein Terminvorschlag schon? ($ausser = eigene Zeile nicht mitzählen) */
function tplan_option_yes(int $optionId, int $ausser = 0): int
{
    $st = tplan_db()->prepare("SELECT COUNT(*) FROM votes WHERE option_id = ? AND vote = 'yes' AND entry_id <> ?");
    $st->execute([$optionId, $ausser]);
    return (int)$st->fetchColumn();
}

/**
 * Eine Rückmeldung speichern – neu oder als Änderung einer bestehenden ($entry).
 * Rückgabe: ['ok'=>bool, 'msg'=>string, 'entry'=>?array]
 */
function tplan_vote_save(array $poll, array $post, ?array $entry = null, bool $eigen = true): array
{
    if (!tplan_is_open($poll)) {
        return ['ok' => false, 'msg' => tplan_deadline_passed($poll)
            ? 'Die Frist ist abgelaufen – hier kann nichts mehr eingetragen werden.'
            : 'Diese Terminumfrage nimmt keine Antworten mehr an.', 'entry' => null];
    }
    if ($entry !== null && !tplan_may_edit($poll, $eigen)) {
        return ['ok' => false, 'entry' => null,
            'msg' => tplan_edit_mode($poll) === 'none'
                ? 'Antworten lassen sich bei dieser Umfrage nachträglich nicht ändern.'
                : 'Diese Antwort darf nur die Person selbst ändern – über ihren persönlichen Link.'];
    }

    $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 80);
    if ($name === '') return ['ok' => false, 'msg' => 'Bitte trag deinen Namen ein, damit klar ist, wer wann kann.', 'entry' => null];

    $mail = trim((string)($post['email'] ?? ''));
    if ((string)$poll['ask_mail'] === 'required' && $mail === '') {
        return ['ok' => false, 'msg' => 'Für diese Umfrage ist eine Mailadresse nötig.', 'entry' => null];
    }
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'Die Mailadresse sieht nicht richtig aus.', 'entry' => null];
    }
    if ((string)$poll['ask_mail'] === 'off') $mail = '';   // nicht gefragt heißt: wird nicht gespeichert
    $kommentar = empty($poll['ask_comment']) ? '' : mb_substr(trim((string)($post['comment'] ?? '')), 0, 500);

    $max = (int)$poll['max_entries'];
    if ($entry === null && $max > 0 && tplan_entry_count((int)$poll['id']) >= $max) {
        return ['ok' => false, 'msg' => 'Diese Terminumfrage ist voll – es sind schon ' . $max . ' Rückmeldungen da.', 'entry' => null];
    }
    if ($entry === null && tplan_entry_count((int)$poll['id']) >= tplan_limits()['entries']) {
        return ['ok' => false, 'msg' => 'Diese Terminumfrage hat die Höchstzahl an Rückmeldungen erreicht.', 'entry' => null];
    }

    $optionen = tplan_options((int)$poll['id']);
    if (!$optionen) return ['ok' => false, 'msg' => 'Diese Terminumfrage hat keine Terminvorschläge.', 'entry' => null];
    $erlaubt = tplan_vote_values($poll);
    $roh = (array)($post['v'] ?? []);

    $stimmen = [];
    foreach ($optionen as $o) {
        $oid = (int)$o['id'];
        $w = (string)($roh[$oid] ?? 'no');
        if (!in_array($w, $erlaubt, true)) $w = 'no';
        // Voll ist voll – aber wer schon zugesagt hatte, behält seinen Platz.
        if ($w === 'yes' && (int)$o['capacity'] > 0) {
            $belegt = tplan_option_yes($oid, $entry ? (int)$entry['id'] : 0);
            if ($belegt >= (int)$o['capacity']) {
                return ['ok' => false, 'entry' => null,
                    'msg' => 'Für „' . tplan_option_label($o) . '" sind schon alle Plätze vergeben. Bitte wähl dort etwas anderes.'];
            }
        }
        $stimmen[$oid] = $w;
    }

    $db = tplan_db();
    try {
        $db->beginTransaction();
        if ($entry === null) {
            $token = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO entries(poll_id, name, email, comment, edit_token, created_at, updated_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([(int)$poll['id'], $name, $mail, $kommentar, $token, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $eid = (int)$db->lastInsertId();
        } else {
            $eid = (int)$entry['id'];
            $token = (string)$entry['edit_token'];
            $db->prepare('UPDATE entries SET name=?, email=?, comment=?, updated_at=? WHERE id=?')
                ->execute([$name, $mail, $kommentar, date('Y-m-d H:i:s'), $eid]);
            $db->prepare('DELETE FROM votes WHERE entry_id = ?')->execute([$eid]);
        }
        $ins = $db->prepare('INSERT INTO votes(entry_id, option_id, vote) VALUES(?,?,?)');
        foreach ($stimmen as $oid => $w) $ins->execute([$eid, $oid, $w]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => 'Die Antwort konnte nicht gespeichert werden. Bitte noch einmal versuchen.', 'entry' => null];
    }
    tplan_touch((int)$poll['id']);

    $neu = tplan_entry_by_token($token);
    return ['ok' => true, 'msg' => $entry === null ? 'Danke – deine Antwort ist gespeichert.' : 'Deine Antwort wurde geändert.', 'entry' => $neu];
}

// ---------------------------------------------------------------------------
// Auswertung
// ---------------------------------------------------------------------------

/**
 * Ergebnis je Terminvorschlag: Zahlen, Namen und Belegung.
 * Die Namen kommen immer mit – ob sie gezeigt werden, entscheidet die Seite anhand von
 * `show_names`. So gibt es nur eine Rechenstelle.
 */
function tplan_results(array $poll): array
{
    $optionen = tplan_options((int)$poll['id']);
    if (!$optionen) return [];
    $eintraege = tplan_entries((int)$poll['id']);

    $out = [];
    foreach ($optionen as $o) {
        $oid = (int)$o['id'];
        $z = ['yes' => 0, 'maybe' => 0, 'no' => 0];
        $namen = ['yes' => [], 'maybe' => [], 'no' => []];
        foreach ($eintraege as $e) {
            $w = (string)($e['votes'][$oid] ?? 'no');
            if (!isset($z[$w])) $w = 'no';
            $z[$w]++;
            $namen[$w][] = (string)$e['name'];
        }
        $gesamt = max(1, $z['yes'] + $z['maybe'] + $z['no']);
        $kap = (int)$o['capacity'];
        $out[] = [
            'option' => $o,
            'yes' => $z['yes'], 'maybe' => $z['maybe'], 'no' => $z['no'],
            'namen' => $namen,
            'pct' => ['yes' => (int)round($z['yes'] * 100 / $gesamt),
                      'maybe' => (int)round($z['maybe'] * 100 / $gesamt),
                      'no' => (int)round($z['no'] * 100 / $gesamt)],
            'frei' => $kap > 0 ? max(0, $kap - $z['yes']) : null,
            'voll' => $kap > 0 && $z['yes'] >= $kap,
        ];
    }
    return $out;
}

/**
 * Die IDs der aktuell besten Vorschläge (es können mehrere gleichauf liegen).
 * Gewichtet wird nur nach Zusagen; „Vielleicht" entscheidet ausschließlich den Gleichstand –
 * ein Termin, an dem viele sicher können, schlägt einen, an dem viele vielleicht können.
 */
function tplan_best_ids(array $results): array
{
    $best = []; $topJa = -1; $topEvtl = -1;
    foreach ($results as $r) {
        if ($r['yes'] < 1) continue;
        if ($r['yes'] > $topJa || ($r['yes'] === $topJa && $r['maybe'] > $topEvtl)) {
            $topJa = $r['yes']; $topEvtl = $r['maybe']; $best = [(int)$r['option']['id']];
        } elseif ($r['yes'] === $topJa && $r['maybe'] === $topEvtl) {
            $best[] = (int)$r['option']['id'];
        }
    }
    return $best;
}

// ---------------------------------------------------------------------------
// Kalenderdatei
// ---------------------------------------------------------------------------

function tplan_ics_escape(string $s): string
{
    return addcslashes(str_replace(["\r\n", "\n", "\r"], '\\n', $s), ",;\\");
}

/** Der festgelegte Termin als .ics – damit er in einem Klick im eigenen Kalender steht. */
function tplan_ics(array $poll, array $o): string
{
    $host = preg_replace('~[^a-z0-9.\-]~i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost-landau';
    $von = trim((string)$o['t_from']);
    $bis = trim((string)$o['t_to']);
    $ganztag = $von === '';
    $start = (int)strtotime((string)$o['day'] . ' ' . $von);
    // Ohne Endzeit eine Stunde ansetzen: Ein Termin mit Länge null verschwindet in vielen Kalendern.
    $ende  = $bis !== '' ? (int)strtotime((string)$o['day'] . ' ' . $bis) : $start + 3600;

    $l = [];
    $l[] = 'BEGIN:VCALENDAR';
    $l[] = 'VERSION:2.0';
    $l[] = 'PRODID:-//' . str_replace(['//', "\r", "\n"], ' ', tplan_traeger()) . '//Terminplaner//DE';
    $l[] = 'CALSCALE:GREGORIAN';
    $l[] = 'METHOD:PUBLISH';
    $l[] = 'BEGIN:VEVENT';
    $l[] = 'UID:termin-' . (int)$poll['id'] . '-' . (int)$o['id'] . '@' . $host;
    $l[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if ($ganztag) {
        $l[] = 'DTSTART;VALUE=DATE:' . date('Ymd', (int)strtotime((string)$o['day']));
        // Ganztägig endet im Kalender am FOLGETAG – sonst fehlt der letzte Tag in vielen Apps.
        $l[] = 'DTEND;VALUE=DATE:' . date('Ymd', (int)strtotime((string)$o['day'] . ' +1 day'));
    } else {
        $l[] = 'DTSTART;TZID=Europe/Berlin:' . date('Ymd\THis', $start);
        $l[] = 'DTEND;TZID=Europe/Berlin:' . date('Ymd\THis', $ende);
    }
    $l[] = 'SUMMARY:' . tplan_ics_escape((string)$poll['title']);
    if (trim((string)$poll['place']) !== '') $l[] = 'LOCATION:' . tplan_ics_escape((string)$poll['place']);
    $text = trim((string)$poll['intro']);
    if (trim((string)$poll['final_note']) !== '') $text = trim($text . "\n\n" . (string)$poll['final_note']);
    if (trim((string)$o['label']) !== '') $text = trim($text . "\n\n" . (string)$o['label']);
    if ($text !== '') $l[] = 'DESCRIPTION:' . tplan_ics_escape($text);
    $l[] = 'END:VEVENT';
    $l[] = 'END:VCALENDAR';
    return implode("\r\n", $l) . "\r\n";
}

// ---------------------------------------------------------------------------
// Mail
//
// Der Terminplaner verschickt HÖCHSTENS EINE Mail je Umfrage: die mit den beiden Links, und
// auch die nur, wenn die anlegende Person eine Adresse einträgt. Keine Benachrichtigung bei
// jeder Antwort, keine Erinnerungen. Das ist Absicht – ein öffentliches Werkzeug, das jede:r
// benutzen kann, darf den gemeinsamen Mail-Topf nicht leerziehen, an dem auch die Login-Links
// der App hängen. Wer wissen will, wer schon geantwortet hat, ruft seinen Link auf.
// ---------------------------------------------------------------------------

function tplan_mail_cap_day(): int { return max(0, (int)tplan_setting_get('cap_day', '300')); }

/** Wie viele Mails darf dieser Bereich JETZT noch verschicken? (0 = gerade keine) */
function tplan_mail_budget(): int
{
    if (tplan_setting_get('mail_links', '1') !== '1') return 0;
    return mail_pool_budget('termin', tplan_mail_cap_day());
}

/**
 * Adresse für Rückmeldungen und Fehlermeldungen aus dem öffentlichen Bereich.
 *
 * Ohne eigene Angabe gilt die Absender-Adresse: Wer eine Umfrage anlegt, bekommt Post von
 * dort, also ist das die Adresse, die die Leute ohnehin schon von uns kennen. '' = keine
 * brauchbare hinterlegt, dann erscheint der Knopf gar nicht erst.
 */
function tplan_feedback_mail(): string
{
    $a = trim(tplan_setting_get('feedback_email', ''));
    if ($a === '') $a = trim(tplan_setting_get('from_email', ''));
    return filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : '';
}

function tplan_mail(string $to, string $subject, string $body): bool
{
    // Absender ist die EINE Adresse der App (mail_pool_absender()) – die eingestellte Adresse
    // dieses Bereichs ist die ANTWORTADRESSE.
    $reply = trim(tplan_setting_get('from_email', ''));
    $from  = mail_pool_absender();
    if ($from === '') $from = $reply;
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    if ($reply === '' || !filter_var($reply, FILTER_VALIDATE_EMAIL)) $reply = $from;
    $name = trim(str_replace(["\r", "\n"], '', tplan_setting_get('from_name', tplan_traeger())));
    $to   = trim(str_replace(["\r", "\n"], '', $to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $headers = [
        'From: ' . ($name !== '' ? $name . ' <' . $from . '>' : $from),
        'Reply-To: ' . $reply,
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: AStA-App',
        'Auto-Submitted: auto-generated',
    ];
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    if ($ok) mail_pool_note('termin');
    return (bool)$ok;
}

/**
 * Bestätigung an die teilnehmende Person – mit ihrem persönlichen Link zum Ändern.
 *
 * Das ist der Grund, warum es die Mail überhaupt gibt: Ohne Konto ist der Link in der Mail der
 * einzige Weg, die eigene Antwort in zwei Wochen von einem anderen Gerät aus noch zu ändern.
 * Der Cookie hilft nur auf diesem einen Rechner, und der Browser räumt ihn irgendwann weg.
 *
 * Verschickt wird NUR, wenn eine Adresse da ist und sie sich seit der letzten Mail geändert hat
 * (bei einer neuen Antwort ist $altMail leer). Sonst wäre jede Korrektur eine weitere Mail –
 * und wer eine fremde Adresse einträgt, könnte damit jemanden zuschütten.
 */
function tplan_mail_confirm(array $poll, array $entry, string $altMail = ''): bool
{
    if (empty($poll['mail_confirm']) || (string)$poll['ask_mail'] === 'off') return false;
    $to = trim((string)($entry['email'] ?? ''));
    if ($to === '' || $to === trim($altMail)) return false;
    if (tplan_mail_budget() <= 0) return false;

    $link = tplan_edit_url($poll, (string)($entry['edit_token'] ?? ''));
    if ($link === '') return false;
    $vars = [
        '{{TITEL}}' => (string)$poll['title'],
        '{{NAME}}'  => (string)($entry['name'] ?? ''),
        '{{LINK}}'  => $link,
        '{{UMFRAGE}}' => tplan_url($poll),
    ];
    return tplan_mail($to, strtr(tplan_text('confirm_subject'), $vars), strtr(tplan_text('confirm_body'), $vars));
}

/** Die Link-Mail an die anlegende Person. Scheitert sie, ist das kein Beinbruch: Die Links stehen auf der Seite. */
function tplan_mail_links(array $poll): bool
{
    $to = trim((string)$poll['contact']);
    if ($to === '' || tplan_mail_budget() <= 0) return false;
    $vars = [
        '{{TITEL}}' => (string)$poll['title'],
        '{{LINK}}'  => tplan_url($poll),
        '{{ADMIN}}' => tplan_admin_url($poll),
    ];
    if ($vars['{{LINK}}'] === '') return false;   // ohne Basis-Adresse wären die Links unbrauchbar
    return tplan_mail($to, strtr(tplan_text('mail_subject'), $vars), strtr(tplan_text('mail_body'), $vars));
}

// ---------------------------------------------------------------------------
// Übersicht und Aufräumen
// ---------------------------------------------------------------------------

/** Zahlen für die Verwaltung. */
function tplan_stats(): array
{
    $db = tplan_db();
    $n = function (string $sql, array $args = []) use ($db): int {
        $st = $db->prepare($sql);
        $st->execute($args);
        return (int)$st->fetchColumn();
    };
    $jetzt = date('Y-m-d H:i:s');
    return [
        'polls'   => $n('SELECT COUNT(*) FROM polls'),
        'offen'   => $n("SELECT COUNT(*) FROM polls WHERE closed = 0 AND final_option = 0 AND (deadline = '' OR deadline >= ?)", [$jetzt]),
        'entries' => $n('SELECT COUNT(*) FROM entries'),
        'options' => $n('SELECT COUNT(*) FROM options'),
        'woche'   => $n('SELECT COUNT(*) FROM polls WHERE created_at >= ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]),
    ];
}

/** Liste für die Verwaltung (neueste zuerst), mit Anzahl der Rückmeldungen. */
function tplan_list(int $limit = 200): array
{
    $st = tplan_db()->prepare('SELECT p.*, (SELECT COUNT(*) FROM entries e WHERE e.poll_id = p.id) AS n
        FROM polls p ORDER BY p.id DESC LIMIT ?');
    $st->execute([max(1, $limit)]);
    return $st->fetchAll();
}

/**
 * Aufräumen. Zwei Fälle:
 *  - benutzte Umfragen, die seit `keep_days` niemand mehr angefasst hat,
 *  - Umfragen ohne jede Rückmeldung, die älter als 30 Tage sind (Probeläufe, Tippfehler).
 * Rückgabe: Zahl der gelöschten Umfragen.
 */
function tplan_prune(): int
{
    $tage = tplan_limits()['keep_days'];
    $db = tplan_db();
    $weg = 0;
    try {
        $a = $db->prepare("DELETE FROM polls WHERE touched_at <> '' AND touched_at < ?");
        $a->execute([date('Y-m-d H:i:s', time() - $tage * 86400)]);
        $weg += $a->rowCount();

        $b = $db->prepare('DELETE FROM polls WHERE created_at < ? AND id NOT IN (SELECT poll_id FROM entries)');
        $b->execute([date('Y-m-d H:i:s', time() - TERMIN_ABANDON_DAYS * 86400)]);
        $weg += $b->rowCount();
    } catch (\Throwable $e) { /* Aufräumen darf nie eine Seite kaputt machen */ }
    return $weg;
}
