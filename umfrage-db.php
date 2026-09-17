<?php
/**
 * Hochschulöffentliche Umfragen – Datenbank und Kernfunktionen.
 *
 * EIGENE SQLite-Datei, getrennt von der App-Datenbank. Zwei Gründe: Der öffentliche Bereich
 * kommt den Mitgliederdaten nicht nahe, und die Urne liegt physisch abseits von allem, was
 * Personen zuzuordnen wäre.
 *
 * Das Verfahren in einem Satz: Erst abstimmen, dann die Hochschul-Adresse bestätigen – die
 * Stimme zählt mit dem Klick auf den Bestätigungslink.
 *
 * Warum diese Reihenfolge: Wer gerade motiviert ist, stimmt jetzt ab. Schickt man die Leute
 * zuerst ins Postfach, springt ein guter Teil ab.
 *
 * Die Trennung „geheim UND nur einmal" trägt der ganze Aufbau:
 *   - `voters` merkt sich NUR einen Hash der Adresse (mit einem Salz, das zu genau dieser
 *     Umfrage gehört) und ein Ja/Nein. Kein Zeitstempel: Ein sekundengenaues „hat abgestimmt
 *     um 14:03:12" wäre zusammen mit einem Stimmzettel von 14:03:12 die Aufhebung der
 *     Anonymität. Aus demselben Grund trägt `ballots` nur das DATUM.
 *   - `pending` ist die einzige Stelle, an der Adress-Merkmal und Antworten zusammenliegen –
 *     die Antworten aber VERSCHLÜSSELT, mit einem Schlüssel, der nur im Link in der Mail
 *     steht. Ohne diesen Link kann sie niemand lesen, auch nicht mit Datenbankzugriff.
 *   - `ballots`/`answers` haben keinerlei Verweis auf `voters` oder `pending`.
 *
 * Reihenfolgen verraten hier auch nichts: `pending` entsteht beim Abstimmen, die Zeile wird
 * beim Bestätigen gelöscht – in der Urne steht nur ein Datum.
 *
 * ACHTUNG beim Hochladen: data/umfrage.sqlite darf der rsync NIE mitnehmen (wie asta.sqlite
 * und pat.sqlite) – sonst überschreibt eine leere lokale Datei die echten Stimmen.
 */

declare(strict_types=1);


// Marke dieser Installation (Logo, App-Symbol) – eigenständig, ohne lib.php.
require_once __DIR__ . '/brand-core.php';

// Gemeinsames Mail-Konto aller Bereiche (eigenständig, ohne lib.php).
require_once __DIR__ . '/mail-pool.php';
// Gemeinsamer Grafik-Bausatz (eigenständig, ohne lib.php).
require_once __DIR__ . '/chart-core.php';

const UMFRAGE_DB_FILE       = __DIR__ . '/data/umfrage.sqlite';
const UMFRAGE_PENDING_DAYS   = 3;    // wie lange eine unbestätigte Stimme NACH Fristende gilt
const UMFRAGE_RATE_PER_HOUR = 5;    // Link-Anforderungen je Besuchssitzung und Stunde
const UMFRAGE_KEEP_DAYS     = 90;   // danach Verzeichnis + Reste einer beendeten Umfrage löschen

/** Standard-Endungen, solange in den Einstellungen nichts anderes steht. */
// Ohne Eintrag keine Vorgabe: Welche Mail-Endungen als hochschulzugehörig gelten, weiß nur
// die Hochschule selbst. Die Liste steht in der Verwaltung unter Umfragen.
const UMFRAGE_DOMAINS_DEFAULT = '';

function umfrage_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(UMFRAGE_DB_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . UMFRAGE_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 4000');
    umfrage_schema($pdo);
    return $pdo;
}

/** Schema anlegen/ergänzen. Idempotent, läuft bei jedem Verbindungsaufbau. */
function umfrage_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS umfrage_settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS polls (
        id            INTEGER PRIMARY KEY,
        slug          TEXT    NOT NULL UNIQUE,          -- öffentlicher Schlüssel im Link
        title         TEXT    NOT NULL,
        intro         TEXT    NOT NULL DEFAULT '',      -- Erläuterung über dem Formular
        status        TEXT    NOT NULL DEFAULT 'draft', -- draft | open | closed
        starts_at     TEXT    NOT NULL DEFAULT '',      -- leer = ab sofort
        ends_at       TEXT    NOT NULL DEFAULT '',      -- leer = ohne Frist (dann von Hand schließen)
        domains       TEXT    NOT NULL DEFAULT '',      -- leer = Liste aus den Einstellungen
        free_public   INTEGER NOT NULL DEFAULT 0,       -- Freitexte auch öffentlich zeigen?
        salt          TEXT    NOT NULL,                 -- Salz für die Adress-Hashes DIESER Umfrage
        created_by    INTEGER,                          -- Mitglieds-ID der App, rein informativ
        created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS questions (
        id          INTEGER PRIMARY KEY,
        poll_id     INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        sort        INTEGER NOT NULL DEFAULT 0,
        type        TEXT    NOT NULL DEFAULT 'single',  -- single | multi | text | scale
        title       TEXT    NOT NULL,
        help        TEXT    NOT NULL DEFAULT '',
        required    INTEGER NOT NULL DEFAULT 1,
        max_choices INTEGER NOT NULL DEFAULT 0,         -- multi: 0 = beliebig viele
        scale_min   INTEGER NOT NULL DEFAULT 1,
        scale_max   INTEGER NOT NULL DEFAULT 5,
        scale_lo    TEXT    NOT NULL DEFAULT '',        -- Beschriftung des unteren Endes
        scale_hi    TEXT    NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS options (
        id          INTEGER PRIMARY KEY,
        question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
        sort        INTEGER NOT NULL DEFAULT 0,
        label       TEXT    NOT NULL
    )");

    // ---- Verzeichnis: wer war schon dran? NUR Hash, KEIN Zeitstempel ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS voters (
        id         INTEGER PRIMARY KEY,
        poll_id    INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        mail_hash  TEXT    NOT NULL,
        used       INTEGER NOT NULL DEFAULT 0,
        UNIQUE(poll_id, mail_hash)
    )");

    // ---- Wartende Stimmen: abgegeben, aber noch nicht bestätigt ----
    // Hier liegt zwangsläufig beides zusammen – Adress-Merkmal und Antworten. Deshalb sind die
    // Antworten VERSCHLÜSSELT, und der Schlüssel steckt ausschließlich im Bestätigungslink in
    // der Mail. Ohne diesen Link ist `payload` auch mit vollem Datenbankzugriff unlesbar.
    // Mit der Bestätigung wandert die Stimme in die Urne und diese Zeile verschwindet.
    //
    // Ausnahme `mail_token`: Geht die Mail sofort raus – der Normalfall –, verlässt das Token
    // den Arbeitsspeicher nie. Nur wenn das Kontingent gerade erschöpft ist, muss es bis zum
    // Versand hier liegen, sonst ließe sich der Link später nicht mehr bauen. Solange das so
    // ist, wäre die wartende Stimme mit Datenbankzugriff lesbar; mit dem Versand wird das
    // Token gelöscht und die Versiegelung gilt wieder. Der Stau ist damit die einzige Lücke –
    // und die bewusste Alternative wäre gewesen, Stimmen bei vollem Kontingent wegzuwerfen.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending (
        id         INTEGER PRIMARY KEY,
        poll_id    INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        voter_id   INTEGER NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
        token_hash TEXT    NOT NULL,
        payload    TEXT    NOT NULL,                 -- verschlüsselte Antworten
        email      TEXT    NOT NULL DEFAULT '',      -- nur bis die Mail draußen ist
        mail_token TEXT    NOT NULL DEFAULT '',      -- NUR im Stau: siehe Kommentar unten
        mailed     INTEGER NOT NULL DEFAULT 0,       -- 0 = wartet auf Versand
        expires_at TEXT    NOT NULL,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // ---- Versand-Zähler: NUR Zeitpunkte, kein Bezug zu irgendwem ----
    // Grundlage für Kontingent und Prognose. Verrät nichts: Die Urne kennt ohnehin nur Daten.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_log (
        id      INTEGER PRIMARY KEY,
        sent_at TEXT NOT NULL
    )");

    // ---- Urne: kein Verweis auf Verzeichnis oder Token, nur das Datum ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS ballots (
        id         INTEGER PRIMARY KEY,
        poll_id    INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        created_on TEXT    NOT NULL DEFAULT (date('now','localtime'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS answers (
        id          INTEGER PRIMARY KEY,
        ballot_id   INTEGER NOT NULL REFERENCES ballots(id) ON DELETE CASCADE,
        question_id INTEGER NOT NULL,
        option_id   INTEGER,
        value_num   INTEGER,
        value_text  TEXT NOT NULL DEFAULT ''
    )");

    // Nachrüsten: „CREATE TABLE IF NOT EXISTS" lässt eine bestehende Tabelle unangetastet.
    // Neue Spalten müssen deshalb einzeln ergänzt werden, sonst fehlen sie auf jeder Datenbank,
    // die es schon gibt.
    umfrage_add_columns($pdo, 'pending', [
        'mail_token' => "TEXT NOT NULL DEFAULT ''",
        'email'      => "TEXT NOT NULL DEFAULT ''",
        'mailed'     => "INTEGER NOT NULL DEFAULT 0",
    ]);
    umfrage_add_columns($pdo, 'voters', ['used' => "INTEGER NOT NULL DEFAULT 0"]);
    // Wann wurde geschlossen? Braucht umfrage_prune(): 90 Tage NACH dem Ende aufräumen –
    // ohne Stempel wüsste bei einer Umfrage ohne Frist niemand, wann das Ende war.
    umfrage_add_columns($pdo, 'polls', ['closed_at' => "TEXT NOT NULL DEFAULT ''"]);
    // „Sonstiges: …" bei Auswahlfragen: Die Frage erlaubt eine eigene Antwort
    // neben den vorgegebenen; in der Urne ist so eine Antwort am Merker zu erkennen
    // (option_id bleibt leer, der Text steht in value_text).
    umfrage_add_columns($pdo, 'questions', ['allow_other' => "INTEGER NOT NULL DEFAULT 0"]);
    umfrage_add_columns($pdo, 'answers', ['is_other' => "INTEGER NOT NULL DEFAULT 0"]);
    // Zuständige Referate je Umfrage: |-getrennte Referatsnamen der App. Wer in so
    // einem Referat ist, betreut GENAU diese Umfrage mit (eigener Titelleisten-Punkt in der App);
    // geprüft wird gegen die App-Referatsliste in der Verwaltung – diese Datei bleibt lib-frei.
    umfrage_add_columns($pdo, 'polls', ['referate' => "TEXT NOT NULL DEFAULT ''"]);
    // Englische Zweitfassung: rein optional und je Feld. Leer heißt „nicht übersetzt" – gibt es
    // NIRGENDS eine Übersetzung, erscheint auf der öffentlichen Seite auch kein Umschalter.
    // Der Umweg über eigene Spalten (statt einer Übersetzungstabelle) ist Absicht: eine Umfrage
    // hat genau zwei Sprachen, und so bleibt jede Abfrage eine einzige Zeile.
    umfrage_add_columns($pdo, 'polls', [
        'title_en' => "TEXT NOT NULL DEFAULT ''",
        'intro_en' => "TEXT NOT NULL DEFAULT ''",
    ]);
    umfrage_add_columns($pdo, 'questions', [
        'title_en'    => "TEXT NOT NULL DEFAULT ''",
        'help_en'     => "TEXT NOT NULL DEFAULT ''",
        'scale_lo_en' => "TEXT NOT NULL DEFAULT ''",
        'scale_hi_en' => "TEXT NOT NULL DEFAULT ''",
    ]);
    umfrage_add_columns($pdo, 'options', ['label_en' => "TEXT NOT NULL DEFAULT ''"]);
    // Bild je Frage: nur der DATEINAME liegt hier, die Datei selbst in umfrage/bilder/.
    umfrage_add_columns($pdo, 'questions', [
        'image'     => "TEXT NOT NULL DEFAULT ''",
        'image_alt' => "TEXT NOT NULL DEFAULT ''",   // Bildbeschreibung für Screenreader
    ]);
    // Geheimer Vorschau-Schlüssel: macht den ENTWURF für alle sichtbar, die den Link haben –
    // ohne Anmeldung, denn dieser Bereich kennt die App-Rollen bewusst nicht.
    umfrage_add_columns($pdo, 'polls', ['preview_token' => "TEXT NOT NULL DEFAULT ''"]);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_q_poll ON questions(poll_id, sort)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_o_q ON options(question_id, sort)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_a_b ON answers(ballot_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_a_q ON answers(question_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_p_hash ON pending(token_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_p_mail ON pending(mailed, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ml_sent ON mail_log(sent_at)');

}

/** Fehlende Spalten ergänzen – SQLite kennt kein „ADD COLUMN IF NOT EXISTS". */
function umfrage_add_columns(PDO $pdo, string $tabelle, array $spalten): void
{
    // Weißliste: Der Tabellenname landet unescaped in der Abfrage.
    if (!in_array($tabelle, ['polls', 'questions', 'options', 'voters', 'pending', 'ballots',
                             'answers', 'mail_log'], true)) return;
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

function umfrage_setting_get(string $key, string $default = ''): string
{
    $st = umfrage_db()->prepare('SELECT value FROM umfrage_settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

/** Trägername, wie er im Auftritt der Umfragen erscheint (Einstellung, Vorgabe wie bisher). */
function umfrage_traeger(): string
{
    return umfrage_setting_get('traeger_name', 'Studierendenvertretung');
}

function umfrage_setting_set(string $key, string $value): void
{
    umfrage_db()->prepare('INSERT INTO umfrage_settings(key, value) VALUES(?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

/**
 * Pflegbare Texte des öffentlichen Bereichs. Der Hinweis zur Adresse ist bewusst ein Text und
 * keine feste Zeile: Wer teilnehmen darf, ändert sich eher als der Code.
 */
function umfrage_texts(): array
{
    return [
        'mail_hint' => ['label' => 'Hinweis zur Adresse (unter dem Eingabefeld)',
            'default' => "Abstimmen können alle Mitglieder der Hochschule – Studierende wie Mitarbeitende. "
                . "Der Standard ist die Adresse der Hochschule; ältere Adressen funktionieren, "
                . "solange sie oben in der Liste stehen."],
        'mail_subject' => ['label' => 'Betreff der Bestätigungsmail', 'default' => 'Bitte bestätige deine Stimme'],
        'mail_body' => ['label' => 'Text der Bestätigungsmail',
            'default' => "Hallo,\n\ndu hast bei der Umfrage „{{TITEL}}“ abgestimmt. Mit einem Klick auf diesen "
                . "Link zählt deine Stimme:\n\n{{LINK}}\n\nOhne diesen Klick wird sie NICHT gezählt.\n\n"
                . "Bis dahin liegt deine Stimme verschlüsselt bereit – der Schlüssel steckt nur in dieser Mail, "
                . "lesen kann sie also niemand. Nach dem Bestätigen wird die Verbindung zwischen deiner Adresse "
                . "und deinen Antworten gelöscht.{{FRIST_SATZ}}\n\nWenn du das nicht warst, kannst du diese Mail "
                . "einfach löschen – dann passiert nichts.\n\nViele Grüße\nDein " . umfrage_traeger()],
        'done' => ['label' => 'Text nach dem Abstimmen',
            'default' => "Deine Stimme ist gezählt und ab jetzt nicht mehr veränderbar."],
    ];
}

function umfrage_text(string $key, string $lang = 'de'): string
{
    $reg = umfrage_texts();
    if (!isset($reg[$key])) return '';
    if ($lang === 'en') {
        // Englisch nur, wenn wirklich gepflegt – sonst lieber der deutsche Text als eine Lücke.
        $en = trim(umfrage_setting_get('text_' . $key . '_en', ''));
        if ($en !== '') return $en;
    }
    return umfrage_setting_get('text_' . $key, (string)$reg[$key]['default']);
}

// ---------------------------------------------------------------------------
// Zweisprachigkeit
//
// Gepflegt wird je Feld eine englische Zweitfassung (Spalten `*_en`). Fehlt sie, erscheint
// überall der deutsche Text – eine halb übersetzte Umfrage ist immer noch benutzbar. Der
// Umschalter auf der öffentlichen Seite taucht nur auf, wenn es überhaupt etwas zu schalten gibt.
// ---------------------------------------------------------------------------

/** Sprache eines Feldes: englische Fassung, wenn gepflegt – sonst die deutsche. */
function um_feld(array $row, string $feld, string $lang = 'de'): string
{
    if ($lang === 'en') {
        $en = trim((string)($row[$feld . '_en'] ?? ''));
        if ($en !== '') return $en;
    }
    return (string)($row[$feld] ?? '');
}

/** Gibt es zu dieser Umfrage überhaupt eine englische Fassung? (Dann nur erscheint der Umschalter.) */
function umfrage_hat_en(array $poll, array $fragen): bool
{
    if (trim((string)($poll['title_en'] ?? '')) !== '' || trim((string)($poll['intro_en'] ?? '')) !== '') return true;
    foreach ($fragen as $q) {
        foreach (['title_en', 'help_en', 'scale_lo_en', 'scale_hi_en'] as $f) {
            if (trim((string)($q[$f] ?? '')) !== '') return true;
        }
        foreach ((array)($q['options'] ?? []) as $o) {
            if (trim((string)($o['label_en'] ?? '')) !== '') return true;
        }
    }
    return false;
}

/**
 * Das feste Gerüst der öffentlichen Seite (Knöpfe, Hinweise). Anders als die Inhalte wird das
 * NICHT gepflegt – es ändert sich nicht je Umfrage und stünde sonst zehnmal zur Übersetzung an.
 */
function umfrage_ui(string $key, string $lang = 'de'): array|string
{
    $t = [
        'lauft_bis'   => ['Abstimmen ist möglich bis', 'You can vote until'],
        'lauft'       => ['Die Umfrage läuft.', 'The survey is running.'],
        'beendet'     => ['Diese Umfrage ist beendet.', 'This survey has ended.'],
        'freiwillig'  => ['freiwillig', 'optional'],
        'antwort'     => ['Deine Antwort …', 'Your answer …'],
        'eigene'      => ['eigene Antwort …', 'your own answer …'],
        'sonstiges'   => ['Sonstiges:', 'Other:'],
        'mailadresse' => ['Mailadresse', 'Email address'],
        'endungen'    => ['Erlaubte Endungen:', 'Allowed domains:'],
        'absenden'    => ['Abstimmen und Link anfordern', 'Submit and request link'],
        'frei_lassen' => ['Bitte frei lassen', 'Please leave empty'],
        'keine_fragen' => ['Diese Umfrage hat noch keine Fragen.', 'This survey has no questions yet.'],
        'warum_mail'  => ['Damit jede Person genau einmal abstimmt, schicken wir dir einen Bestätigungslink. Deine Stimme zählt erst mit dem Klick darauf.',
                          'To make sure everyone votes exactly once, we send you a confirmation link. Your vote only counts once you click it.'],
        'danke_titel' => ['Fast geschafft – bitte bestätigen', 'Almost done – please confirm'],
        'ergebnis'    => ['Ergebnis ansehen', 'View results'],
        'sprache'     => ['English', 'Deutsch'],   // Beschriftung des Umschalters: zeigt die ANDERE Sprache
        'pflicht'     => ['Bitte beantworte diese Frage.', 'Please answer this question.'],
    ];
    $paar = $t[$key] ?? null;
    if (!$paar) return '';
    return $lang === 'en' ? $paar[1] : $paar[0];
}

// ---------------------------------------------------------------------------
// Mailversand
//
// Bewusst eigene, winzige Fassung statt send_mail() aus der lib.php: Der öffentliche Bereich
// bindet die App-Bibliothek nicht ein – was dort nicht geladen ist, kann auch nicht missbraucht
// werden. Der Preis sind ein paar Zeilen Kopfzeilen-Bau, die es zweimal gibt.
//
// Anders als beim Hilfe-Formular des Pat:innenprogramms geht die Mail SOFORT raus und nicht
// über den Cron: Ein Anmelde-Link, der erst in fünf Minuten ankommt, ist kaum brauchbar.
// Genau so macht es auch der Login-Link der App.
// ---------------------------------------------------------------------------

function umfrage_mail(string $to, string $subject, string $body): bool
{
    // Absender ist die EINE Adresse der App (mail_pool_absender()) – die eingestellte Adresse
    // dieses Bereichs ist die ANTWORTADRESSE. Solange die App den Absender noch nicht einmal
    // gespiegelt hat (frische Installation), springt die eingestellte Adresse ein.
    $reply = trim(umfrage_setting_get('from_email', ''));
    $from  = mail_pool_absender();
    if ($from === '') $from = $reply;
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    if ($reply === '' || !filter_var($reply, FILTER_VALIDATE_EMAIL)) $reply = $from;
    $name = trim(umfrage_setting_get('from_name', umfrage_traeger()));
    // Zeilenumbrüche in Kopfzeilen wären eine Einladung, weitere Empfänger unterzuschieben
    $name = trim(str_replace(["\r", "\n"], '', $name));
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
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

/** Öffentliche Adresse einer Umfrage (Basis-URL aus den Einstellungen). */
function umfrage_url(array $poll, string $token = ''): string
{
    $basis = rtrim(umfrage_setting_get('base_url', ''), '/');
    if ($basis === '') return '';
    return $token !== ''
        ? $basis . '/umfrage/bestaetigen.php?t=' . $token
        : $basis . '/umfrage/?u=' . rawurlencode((string)$poll['slug']);
}

/** Platzhalter in einem pflegbaren Text ersetzen. */
function umfrage_text_fill(string $text, array $vars): string
{
    return strtr($text, $vars);
}

// ---------------------------------------------------------------------------
// Adressen
// ---------------------------------------------------------------------------

/** Erlaubte Endungen: je Umfrage, sonst die allgemeine Einstellung, sonst der Standard. */
function umfrage_domains(?array $poll = null): array
{
    $roh = $poll !== null ? trim((string)($poll['domains'] ?? '')) : '';
    if ($roh === '') $roh = umfrage_setting_get('domains', UMFRAGE_DOMAINS_DEFAULT);
    $out = [];
    foreach (preg_split('~[\s,;]+~', mb_strtolower($roh)) as $d) {
        $d = trim($d, " \t\n\r\0\x0B.@");
        if ($d !== '' && str_contains($d, '.') && !in_array($d, $out, true)) $out[] = $d;
    }
    // Leere Liste heißt „nichts hinterlegt" – und dann geht KEINE Adresse durch. Das ist
    // Absicht: Eine Umfrage, die jede beliebige Adresse annimmt, wäre schlimmer als eine,
    // die niemanden einlässt. Die Verwaltung meldet den Zustand im Selbsttest.
    return $out;
}

/**
 * Adresse prüfen und in die Form bringen, aus der der Hash entsteht.
 * '' = nicht verwendbar. Ein „+Zusatz" im vorderen Teil fliegt raus – sonst wären
 * name+1@…, name+2@… beliebig viele Stimmen aus demselben Postfach.
 */
function umfrage_mail_normalize(string $roh, ?array $poll = null): string
{
    $m = mb_strtolower(trim($roh));
    if ($m === '' || mb_strlen($m) > 190 || !filter_var($m, FILTER_VALIDATE_EMAIL)) return '';
    [$lokal, $domain] = explode('@', $m, 2);
    if (($p = strpos($lokal, '+')) !== false) $lokal = substr($lokal, 0, $p);
    if ($lokal === '') return '';
    // Ohne hinterlegte Endungen bleibt $erlaubt false – bewusst zu statt offen.
    $erlaubt = false;
    foreach (umfrage_domains($poll) as $d) {
        if ($domain === $d || str_ends_with($domain, '.' . $d)) { $erlaubt = true; break; }
    }
    return $erlaubt ? $lokal . '@' . $domain : '';
}

/** Adress-Hash für GENAU diese Umfrage (eigenes Salz → über Umfragen hinweg nicht vergleichbar). */
function umfrage_mail_hash(array $poll, string $mailNormalisiert): string
{
    return hash('sha256', (string)$poll['salt'] . '|' . $mailNormalisiert);
}

// ---------------------------------------------------------------------------
// Umfragen lesen
// ---------------------------------------------------------------------------

function umfrage_all(): array
{
    return umfrage_db()->query('SELECT * FROM polls ORDER BY id DESC')->fetchAll();
}

/** Zuständige Referate einer Umfrage (aus der |-Liste; leere Einträge fallen raus). */
function umfrage_referate(array $poll): array
{
    return array_values(array_filter(array_map('trim', explode('|', (string)($poll['referate'] ?? '')))));
}

/** Zuständige Referate setzen. Die Prüfung gegen die App-Referatsliste macht der Aufrufer
 *  (Verwaltung) – hier gibt es die App-Datenbank bewusst nicht. */
function umfrage_referate_set(int $id, array $referate): void
{
    $sauber = [];
    foreach ($referate as $r) {
        $r = trim((string)$r);
        if ($r !== '' && !str_contains($r, '|') && !in_array($r, $sauber, true)) $sauber[] = $r;
    }
    umfrage_db()->prepare('UPDATE polls SET referate = ? WHERE id = ?')->execute([implode('|', $sauber), $id]);
}

/** Alle Umfragen, für die dieses Referat als zuständig eingetragen ist (neueste zuerst). */
function umfrage_polls_of_referat(string $ref): array
{
    $ref = trim($ref);
    if ($ref === '') return [];
    return array_values(array_filter(umfrage_all(),
        fn (array $p) => in_array($ref, umfrage_referate($p), true)));
}

function umfrage_get(int $id): ?array
{
    $st = umfrage_db()->prepare('SELECT * FROM polls WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function umfrage_by_slug(string $slug): ?array
{
    $st = umfrage_db()->prepare('SELECT * FROM polls WHERE slug = ?');
    $st->execute([trim($slug)]);
    return $st->fetch() ?: null;
}

/** Läuft die Umfrage gerade? (Status offen UND innerhalb des Zeitfensters) */
function umfrage_is_open(array $poll): bool
{
    if ((string)$poll['status'] !== 'open') return false;
    $jetzt = date('Y-m-d H:i:s');
    if (trim((string)$poll['starts_at']) !== '' && $poll['starts_at'] > $jetzt) return false;
    if (trim((string)$poll['ends_at'])   !== '' && $poll['ends_at']   < $jetzt) return false;
    return true;
}

/** Ergebnisse sind erst NACH dem Ende öffentlich – vorher würden sie die Abstimmung beeinflussen. */
function umfrage_results_public(array $poll): bool
{
    if ((string)$poll['status'] === 'closed') return true;
    $ende = trim((string)$poll['ends_at']);
    return $ende !== '' && $ende < date('Y-m-d H:i:s');
}

/** Alle Fragen einer Umfrage, jede mit ihren Antwortmöglichkeiten. */
function umfrage_questions(int $pollId): array
{
    $st = umfrage_db()->prepare('SELECT * FROM questions WHERE poll_id = ? ORDER BY sort, id');
    $st->execute([$pollId]);
    $fragen = $st->fetchAll();
    if (!$fragen) return [];
    $ids = implode(',', array_map(fn($q) => (int)$q['id'], $fragen));
    $opt = umfrage_db()->query('SELECT * FROM options WHERE question_id IN (' . $ids . ') ORDER BY sort, id')->fetchAll();
    $byQ = [];
    foreach ($opt as $o) $byQ[(int)$o['question_id']][] = $o;
    foreach ($fragen as &$q) $q['options'] = $byQ[(int)$q['id']] ?? [];
    return $fragen;
}

/** Drei Zahlen für die Verwaltung – mehr weiß die App über die Beteiligung nicht. */
function umfrage_counts(int $pollId): array
{
    $q = function (string $sql) use ($pollId): int {
        $st = umfrage_db()->prepare($sql);
        $st->execute([$pollId]);
        return (int)$st->fetchColumn();
    };
    return [
        'stimmen'  => $q('SELECT COUNT(*) FROM ballots WHERE poll_id = ?'),       // bestätigt = gezählt
        'wartend'  => $q('SELECT COUNT(*) FROM pending WHERE poll_id = ?'),       // abgegeben, noch nicht bestätigt
        'adressen' => $q('SELECT COUNT(*) FROM voters WHERE poll_id = ?'),        // Adressen, die je teilgenommen haben
        'links'    => $q('SELECT COUNT(*) FROM voters WHERE poll_id = ?'),        // Altname, bleibt für die Ergebnisseite
    ];
}

// ---------------------------------------------------------------------------
// Umfragen schreiben (nur aus der Verwaltung)
// ---------------------------------------------------------------------------

/** Aus einem Titel einen brauchbaren Link-Schlüssel machen. */
function umfrage_slugify(string $roh): string
{
    $s = mb_strtolower(trim($roh));
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?? '';
    return trim((string)$s, '-');
}

/** Freien Schlüssel finden (hängt bei Bedarf -2, -3 … an). */
function umfrage_slug_free(string $wunsch, int $exceptId = 0): string
{
    $basis = umfrage_slugify($wunsch);
    if ($basis === '') $basis = 'umfrage';
    $basis = mb_substr($basis, 0, 60);
    $kandidat = $basis; $n = 1;
    while (true) {
        $st = umfrage_db()->prepare('SELECT id FROM polls WHERE slug = ? AND id <> ?');
        $st->execute([$kandidat, $exceptId]);
        if (!$st->fetch()) return $kandidat;
        $kandidat = $basis . '-' . (++$n);
    }
}

/**
 * Umfrage anlegen ($id = 0) oder ändern.
 * Rückgabe: ['ok' => bool, 'id' => int, 'msg' => string, 'typ' => 'success'|'error']
 */
function umfrage_save(int $id, array $post, int $memberId): array
{
    $titel = trim((string)($post['title'] ?? ''));
    if ($titel === '') return ['ok' => false, 'id' => $id, 'msg' => 'Bitte einen Titel angeben.', 'typ' => 'error'];

    $von = trim((string)($post['starts_at'] ?? ''));
    $bis = trim((string)($post['ends_at'] ?? ''));
    foreach ([$von, $bis] as $d) {
        if ($d !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$~', $d)) {
            return ['ok' => false, 'id' => $id, 'msg' => 'Datum bitte als JJJJ-MM-TT (optional mit Uhrzeit).', 'typ' => 'error'];
        }
    }
    $norm = function (string $d): string {
        if ($d === '') return '';
        $d = str_replace('T', ' ', $d);
        if (strlen($d) === 10) $d .= ' 23:59:59';
        if (strlen($d) === 16) $d .= ':00';
        return $d;
    };
    $von = $norm($von); $bis = $norm($bis);
    if ($von !== '' && $bis !== '' && $von > $bis) {
        return ['ok' => false, 'id' => $id, 'msg' => 'Das Ende liegt vor dem Beginn.', 'typ' => 'error'];
    }

    $intro   = mb_substr(trim((string)($post['intro'] ?? '')), 0, 4000);
    $domains = mb_substr(trim((string)($post['domains'] ?? '')), 0, 300);
    $frei    = !empty($post['free_public']) ? 1 : 0;
    $titelEn = mb_substr(trim((string)($post['title_en'] ?? '')), 0, 300);
    $introEn = mb_substr(trim((string)($post['intro_en'] ?? '')), 0, 4000);

    if ($id <= 0) {
        $slug = umfrage_slug_free((string)($post['slug'] ?? '') !== '' ? (string)$post['slug'] : $titel);
        umfrage_db()->prepare('INSERT INTO polls(slug, title, intro, title_en, intro_en, status, starts_at, ends_at, domains, free_public, salt, preview_token, created_by)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$slug, $titel, $intro, $titelEn, $introEn, 'draft', $von, $bis, $domains, $frei,
                       bin2hex(random_bytes(16)), bin2hex(random_bytes(12)), $memberId ?: null]);
        return ['ok' => true, 'id' => (int)umfrage_db()->lastInsertId(), 'msg' => 'Umfrage angelegt – jetzt die Fragen zusammenstellen.', 'typ' => 'success'];
    }
    $alt = umfrage_get($id);
    if (!$alt) return ['ok' => false, 'id' => 0, 'msg' => 'Umfrage nicht gefunden.', 'typ' => 'error'];
    // Der Schlüssel steckt in bereits verteilten Links – nach dem Öffnen bleibt er, wie er ist.
    $slug = (string)$alt['slug'];
    if ((string)$alt['status'] === 'draft' && trim((string)($post['slug'] ?? '')) !== '') {
        $slug = umfrage_slug_free((string)$post['slug'], $id);
    }
    umfrage_db()->prepare('UPDATE polls SET slug=?, title=?, intro=?, title_en=?, intro_en=?, starts_at=?, ends_at=?, domains=?, free_public=? WHERE id=?')
        ->execute([$slug, $titel, $intro, $titelEn, $introEn, $von, $bis, $domains, $frei, $id]);
    return ['ok' => true, 'id' => $id, 'msg' => 'Gespeichert.', 'typ' => 'success'];
}

/** Status setzen. Öffnen geht nur mit mindestens einer Frage. */
function umfrage_set_status(int $id, string $status): array
{
    if (!in_array($status, ['draft', 'open', 'closed'], true)) return ['ok' => false, 'msg' => 'Unbekannter Status.'];
    $p = umfrage_get($id);
    if (!$p) return ['ok' => false, 'msg' => 'Umfrage nicht gefunden.'];
    if ($status === 'open' && !umfrage_questions($id)) {
        return ['ok' => false, 'msg' => 'Ohne Fragen lässt sich die Umfrage nicht öffnen.'];
    }
    umfrage_db()->prepare('UPDATE polls SET status = ?, closed_at = ? WHERE id = ?')
        ->execute([$status, $status === 'closed' ? date('Y-m-d H:i:s') : '', $id]);
    return ['ok' => true, 'msg' => match ($status) {
        'open'   => 'Umfrage ist offen – der Link kann verteilt werden.',
        'closed' => 'Umfrage geschlossen. Die Ergebnisse sind jetzt öffentlich.',
        default  => 'Zurück in den Entwurf – öffentlich ist die Umfrage damit nicht mehr erreichbar.',
    }];
}

function umfrage_delete(int $id): void
{
    // Erst die Dateien: Die Fremdschlüssel-Kaskade räumt gleich die Fragen-Zeilen ab, und
    // danach wüsste niemand mehr, welche Bilder zu dieser Umfrage gehörten.
    umfrage_bilder_der_umfrage_loeschen($id);
    umfrage_db()->prepare('DELETE FROM polls WHERE id = ?')->execute([$id]);
}

// ---------------------------------------------------------------------------
// Fragen
// ---------------------------------------------------------------------------

function umfrage_question_types(): array
{
    return [
        'single' => 'Einfachauswahl (eine Antwort)',
        'multi'  => 'Mehrfachauswahl (mehrere Antworten)',
        'scale'  => 'Skala (Zustimmung von … bis …)',
        'text'   => 'Freitext',
        // Keine Frage, sondern Struktur: eine Zwischenüberschrift mit optionalem Text,
        // die einen langen Bogen in Abschnitte teilt. Taucht im Ergebnis nicht auf.
        'info'   => 'Zwischentext (gliedert, fragt nichts)',
    ];
}

function umfrage_question_get(int $id): ?array
{
    $st = umfrage_db()->prepare('SELECT * FROM questions WHERE id = ?');
    $st->execute([$id]);
    $q = $st->fetch();
    if (!$q) return null;
    $o = umfrage_db()->prepare('SELECT * FROM options WHERE question_id = ? ORDER BY sort, id');
    $o->execute([$id]);
    $q['options'] = $o->fetchAll();
    return $q;
}

/**
 * Frage anlegen/ändern samt Antwortmöglichkeiten (eine je Zeile im Textfeld).
 * Bestehende Möglichkeiten werden anhand ihrer Beschriftung wiederverwendet, damit bereits
 * abgegebene Stimmen ihre Zuordnung behalten.
 */
function umfrage_question_save(int $pollId, int $id, array $post): array
{
    $typ = (string)($post['type'] ?? 'single');
    if (!isset(umfrage_question_types()[$typ])) $typ = 'single';
    $titel = trim((string)($post['title'] ?? ''));
    if ($titel === '') return ['ok' => false, 'msg' => $typ === 'info' ? 'Bitte die Überschrift eintragen.' : 'Bitte die Frage eintragen.'];

    $zeilen = [];
    foreach (preg_split('~\r?\n~', (string)($post['options'] ?? '')) as $z) {
        $z = trim($z);
        if ($z !== '' && !in_array($z, $zeilen, true)) $zeilen[] = mb_substr($z, 0, 200);
    }
    // Englische Möglichkeiten: NICHT gefiltert, denn die Zeilennummer ist die Zuordnung –
    // eine leere Zeile heißt „diese eine bleibt deutsch".
    $zeilenEn = array_map('trim', preg_split('~\r?\n~', (string)($post['options_en'] ?? '')));
    if (in_array($typ, ['single', 'multi'], true) && count($zeilen) < 2) {
        return ['ok' => false, 'msg' => 'Für eine Auswahl braucht es mindestens zwei Antwortmöglichkeiten.'];
    }
    if (!in_array($typ, ['single', 'multi'], true)) $zeilen = [];   // fremde Reste nie mitschleppen

    $smin = (int)($post['scale_min'] ?? 1);
    $smax = (int)($post['scale_max'] ?? 5);
    if ($smax <= $smin) { $smin = 1; $smax = 5; }
    if ($smax - $smin > 10) $smax = $smin + 10;   // mehr als 11 Stufen kann niemand sinnvoll wählen

    $felder = [
        $pollId, (int)($post['sort'] ?? 0), $typ, mb_substr($titel, 0, 300),
        mb_substr(trim((string)($post['help'] ?? '')), 0, 500),
        // Ein Zwischentext ist nie Pflicht – er fragt ja nichts.
        ($typ === 'info' || !empty($post['optional'])) ? 0 : 1,
        max(0, (int)($post['max_choices'] ?? 0)),
        $smin, $smax,
        mb_substr(trim((string)($post['scale_lo'] ?? '')), 0, 60),
        mb_substr(trim((string)($post['scale_hi'] ?? '')), 0, 60),
        in_array($typ, ['single', 'multi'], true) && !empty($post['allow_other']) ? 1 : 0,
        mb_substr(trim((string)($post['title_en'] ?? '')), 0, 300),
        mb_substr(trim((string)($post['help_en'] ?? '')), 0, 500),
        mb_substr(trim((string)($post['scale_lo_en'] ?? '')), 0, 60),
        mb_substr(trim((string)($post['scale_hi_en'] ?? '')), 0, 60),
    ];

    if ($id <= 0) {
        $st = umfrage_db()->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM questions WHERE poll_id = ?');
        $st->execute([$pollId]);
        $felder[1] = (int)$st->fetchColumn();
        umfrage_db()->prepare('INSERT INTO questions(poll_id, sort, type, title, help, required, max_choices, scale_min, scale_max, scale_lo, scale_hi, allow_other,
                                                     title_en, help_en, scale_lo_en, scale_hi_en)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($felder);
        $id = (int)umfrage_db()->lastInsertId();
    } else {
        $vorhanden = umfrage_question_get($id);
        if (!$vorhanden || (int)$vorhanden['poll_id'] !== $pollId) return ['ok' => false, 'msg' => 'Frage nicht gefunden.'];
        $felder[] = $id;
        array_shift($felder); // poll_id bleibt, wie es ist
        umfrage_db()->prepare('UPDATE questions SET sort=?, type=?, title=?, help=?, required=?, max_choices=?, scale_min=?, scale_max=?, scale_lo=?, scale_hi=?, allow_other=?,
                                                    title_en=?, help_en=?, scale_lo_en=?, scale_hi_en=? WHERE id=?')
            ->execute($felder);
    }
    umfrage_options_sync($id, $zeilen, $zeilenEn);
    return ['ok' => true, 'msg' => 'Frage gespeichert.', 'id' => $id];
}

/** Frage samt Antwortmöglichkeiten verdoppeln – ans Ende der Reihenfolge. */
function umfrage_question_copy(int $id): ?int
{
    $q = umfrage_question_get($id);
    if (!$q) return null;
    $r = umfrage_question_save((int)$q['poll_id'], 0, [
        'type' => (string)$q['type'], 'title' => (string)$q['title'] . ' (Kopie)',
        'help' => (string)$q['help'], 'optional' => (int)$q['required'] === 1 ? '' : '1',
        'max_choices' => (int)$q['max_choices'], 'scale_min' => (int)$q['scale_min'],
        'scale_max' => (int)$q['scale_max'], 'scale_lo' => (string)$q['scale_lo'],
        'scale_hi' => (string)$q['scale_hi'], 'allow_other' => (int)($q['allow_other'] ?? 0) === 1 ? '1' : '',
        'options' => implode("\n", array_map(fn($o) => (string)$o['label'], (array)$q['options'])),
        'options_en' => implode("\n", array_map(fn($o) => (string)($o['label_en'] ?? ''), (array)$q['options'])),
        'title_en' => (string)($q['title_en'] ?? ''), 'help_en' => (string)($q['help_en'] ?? ''),
        'scale_lo_en' => (string)($q['scale_lo_en'] ?? ''), 'scale_hi_en' => (string)($q['scale_hi_en'] ?? ''),
    ]);
    if (empty($r['ok'])) return null;
    $neu = (int)$r['id'];
    // Bild mitnehmen – aber als EIGENE Datei. Zwei Fragen dürfen sich keine teilen, sonst
    // reißt das Löschen der einen der anderen das Bild weg.
    $bild = trim((string)($q['image'] ?? ''));
    if ($bild !== '' && is_file(umfrage_bild_dir() . '/' . basename($bild))) {
        $kopie = 'f' . date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.jpg';
        if (@copy(umfrage_bild_dir() . '/' . basename($bild), umfrage_bild_dir() . '/' . $kopie)) {
            umfrage_db()->prepare('UPDATE questions SET image = ?, image_alt = ? WHERE id = ?')
               ->execute([$kopie, (string)($q['image_alt'] ?? ''), $neu]);
        }
    }
    return $neu;
}

/** Bild einer Frage setzen oder entfernen. Ein vorhandenes wird dabei von der Platte geputzt. */
function umfrage_question_bild_setzen(int $questionId, string $datei, string $alt): void
{
    $q = umfrage_question_get($questionId);
    if (!$q) return;
    $vorher = trim((string)($q['image'] ?? ''));
    if ($datei !== '' && $vorher !== '' && $vorher !== $datei) umfrage_bild_loeschen($vorher);
    umfrage_db()->prepare('UPDATE questions SET image = ?, image_alt = ? WHERE id = ?')
       ->execute([$datei, mb_substr(trim($alt), 0, 200), $questionId]);
}

/** Bild einer Frage entfernen (Datei + Eintrag). */
function umfrage_question_bild_weg(int $questionId): void
{
    $q = umfrage_question_get($questionId);
    if (!$q) return;
    if (trim((string)($q['image'] ?? '')) !== '') umfrage_bild_loeschen((string)$q['image']);
    umfrage_db()->prepare("UPDATE questions SET image = '', image_alt = '' WHERE id = ?")->execute([$questionId]);
}

/** Antwortmöglichkeiten abgleichen: gleiche Beschriftung = derselbe Eintrag (Stimmen bleiben gültig). */
function umfrage_options_sync(int $questionId, array $labels, array $labelsEn = []): void
{
    $st = umfrage_db()->prepare('SELECT * FROM options WHERE question_id = ?');
    $st->execute([$questionId]);
    $alt = [];
    foreach ($st->fetchAll() as $o) $alt[(string)$o['label']] = (int)$o['id'];

    $behalten = [];
    foreach ($labels as $i => $label) {
        // Die englischen Zeilen stehen ZEILENWEISE parallel: dritte Zeile deutsch = dritte
        // Zeile englisch. Fehlt sie, bleibt die Möglichkeit eben deutsch.
        $en = mb_substr(trim((string)($labelsEn[$i] ?? '')), 0, 200);
        if (isset($alt[$label])) {
            umfrage_db()->prepare('UPDATE options SET sort = ?, label_en = ? WHERE id = ?')->execute([$i, $en, $alt[$label]]);
            $behalten[] = $alt[$label];
        } else {
            umfrage_db()->prepare('INSERT INTO options(question_id, sort, label, label_en) VALUES(?,?,?,?)')->execute([$questionId, $i, $label, $en]);
            $behalten[] = (int)umfrage_db()->lastInsertId();
        }
    }
    $weg = array_diff(array_values($alt), $behalten);
    foreach ($weg as $oid) umfrage_db()->prepare('DELETE FROM options WHERE id = ?')->execute([$oid]);
}

function umfrage_question_delete(int $id): void
{
    $q = umfrage_question_get($id);
    if ($q && trim((string)($q['image'] ?? '')) !== '') umfrage_bild_loeschen((string)$q['image']);
    umfrage_db()->prepare('DELETE FROM questions WHERE id = ?')->execute([$id]);
}

/** Frage in der Reihenfolge verschieben ($dir < 0 = nach oben); normalisiert auf 0..n-1. */
function umfrage_question_move(int $id, int $dir): void
{
    $q = umfrage_question_get($id);
    if (!$q) return;
    $st = umfrage_db()->prepare('SELECT id FROM questions WHERE poll_id = ? ORDER BY sort, id');
    $st->execute([(int)$q['poll_id']]);
    $ids = array_map(fn($r) => (int)$r['id'], $st->fetchAll());
    $pos = array_search($id, $ids, true);
    if ($pos === false) return;
    $swap = $pos + ($dir < 0 ? -1 : 1);
    if ($swap < 0 || $swap >= count($ids)) return;
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    $up = umfrage_db()->prepare('UPDATE questions SET sort = ? WHERE id = ?');
    foreach ($ids as $i => $qid) $up->execute([$i, $qid]);
}

// ---------------------------------------------------------------------------
// Teilnahme: Link anfordern, Token einlösen, Stimme abgeben
// ---------------------------------------------------------------------------

/* ---------------------------------------------------------------------------
 * Verschlüsselung der wartenden Antworten.
 *
 * Der Schlüssel wird aus dem Bestätigungs-Token abgeleitet – und das steht NUR im Link in der
 * Mail, nie in der Datenbank (dort liegt nur sein Hash). Wer die Datenbank hat, aber nicht den
 * Link, sieht unlesbares Zeug. Genau dasselbe Verfahren nutzt die App schon für Push-Nachrichten.
 * ------------------------------------------------------------------------- */

function umfrage_seal(string $token, array $daten): string
{
    $key = hash('sha256', 'umfrage-stimme|' . $token, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt((string)json_encode($daten), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return '';
    return base64_encode($iv . $tag . $ct);
}

function umfrage_unseal(string $token, string $blob): ?array
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $key = hash('sha256', 'umfrage-stimme|' . $token, true);
    $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($json === false) return null;
    $d = json_decode($json, true);
    return is_array($d) ? $d : null;
}

// ---------------------------------------------------------------------------
// Mail-Kontingent und Prognose
//
// Es gibt EIN gemeinsames Konto für alle Bereiche (mail-pool.php). Der Umfrage-Versand
// nimmt sich daraus, was übrig ist, nachdem App, Pat:innenprogramm und externe Events
// ihren Verbrauch eingetragen haben – nicht mehr eine feste eigene Scheibe, die verfällt,
// wenn gerade keine Umfrage läuft. Ein eigener Deckel ist trotzdem einstellbar (0 = keiner),
// falls eine große Umfrage nicht den ganzen Topf leerziehen soll.
// ---------------------------------------------------------------------------

function umfrage_mail_cap_day(): int  { return max(0, (int)umfrage_setting_get('cap_day', '0')); }
/**
 * Freiwillige Stundenbremse. 0 = keine, und das ist die Vorgabe.
 *
 * Die Grenze des Hosters gilt je 24 Stunden, nicht je Stunde – eine Stundenbremse ist eine
 * eigene Erfindung und bewirkt vor allem, dass eine Mail auf den nächsten Cron-Lauf wartet,
 * obwohl Kontingent da wäre. Wer sie doch will (etwa gegen Spam-Filter, die Schübe nicht
 * mögen), trägt eine Zahl ein.
 */
function umfrage_mail_cap_hour(): int { return max(0, (int)umfrage_setting_get('cap_hour', '0')); }

/** Wie viele Umfrage-Mails gingen in den letzten $stunden Stunden raus? */
function umfrage_mail_sent(int $stunden): int
{
    $st = umfrage_db()->prepare('SELECT COUNT(*) FROM mail_log WHERE sent_at >= ?');
    $st->execute([date('Y-m-d H:i:s', time() - $stunden * 3600)]);
    return (int)$st->fetchColumn();
}

/** Was pro Tag höchstens für Umfragen drin ist – Grundlage der Prognosen. */
function umfrage_mail_day_allow(): int
{
    $deckel = umfrage_mail_cap_day();
    return $deckel > 0 ? min($deckel, mail_pool_day_allow()) : mail_pool_day_allow();
}

/** Wie viele Mails dürfen JETZT noch raus? (0 = der Topf ist gerade leer) */
function umfrage_mail_budget(): int
{
    $frei = mail_pool_budget('umfrage', umfrage_mail_cap_day());
    $proStunde = umfrage_mail_cap_hour();
    if ($proStunde > 0) $frei = min($frei, $proStunde - umfrage_mail_sent(1));
    return max(0, $frei);
}

/**
 * Wie viele Bestätigungsmails passen bis zum Fristende überhaupt noch raus?
 * Bewusst vorsichtig gerechnet: das, was jetzt noch geht, plus je vollem Resttag ein
 * Tageskontingent. Ohne Frist gibt es keine Obergrenze.
 */
function umfrage_capacity_until(string $ende): int
{
    $ende = trim($ende);
    if ($ende === '') return PHP_INT_MAX;
    $sek = strtotime($ende) - time();
    if ($sek <= 0) return 0;
    return umfrage_mail_budget() + (int)floor($sek / 86400) * umfrage_mail_day_allow();
}

/** Wie viele weitere 24-Stunden-Fenster braucht die Warteschlange? (0 = passt noch heute) */
function umfrage_days_needed(int $wartend): int
{
    $heute = umfrage_mail_budget();
    if ($wartend <= $heute) return 0;
    return (int)ceil(($wartend - $heute) / umfrage_mail_day_allow());
}

/** Wie viele Mails warten insgesamt noch auf den Versand? */
function umfrage_queue_len(): int
{
    return (int)umfrage_db()->query('SELECT COUNT(*) FROM pending WHERE mailed = 0')->fetchColumn();
}

/**
 * Ehrliche Auskunft, wann die Bestätigungsmail voraussichtlich rausgeht.
 * $vorMir = wie viele Mails vor dieser noch in der Schlange stehen.
 */
function umfrage_mail_eta(int $vorMir): string
{
    if ($vorMir <= 0 && umfrage_mail_budget() > 0) return 'in den nächsten Minuten';

    // Was heute noch geht, richtet sich nach umfrage_mail_day_allow() – NICHT nach dem eigenen
    // Deckel allein: Der ist standardmäßig 0 („kein eigener Deckel"), und damit käme hier 0
    // heraus – jede wartende Mail würde auf „voraussichtlich morgen" vertröstet, obwohl der
    // Topf randvoll ist.
    $restHeute = max(0, umfrage_mail_day_allow() - umfrage_mail_sent(24));
    if ($vorMir >= $restHeute) {
        // Das Tageskontingent reicht nicht mehr – ehrlich sagen, dass es morgen wird.
        return 'voraussichtlich morgen';
    }
    // Ohne Stundenbremse (der Normalfall) hängt die Wartezeit nur noch am Cron-Lauf, und der
    // kommt stündlich. Länger als eine Stunde kann es dann gar nicht dauern.
    $proStunde = umfrage_mail_cap_hour();
    if ($proStunde <= 0) return 'spätestens in einer Stunde';

    $stunden = (int)floor($vorMir / $proStunde);
    if ($stunden <= 0) return 'in etwa einer Stunde';
    if ($stunden === 1) return 'in etwa zwei Stunden';
    return 'in etwa ' . ($stunden + 1) . ' Stunden';
}

// ---------------------------------------------------------------------------
// Abstimmen (Schritt 1) und Bestätigen (Schritt 2)
// ---------------------------------------------------------------------------

/**
 * Die Antworten prüfen und in eine schlanke Form bringen. Gibt ['ok'=>bool, 'msg'=>…, 'daten'=>…].
 * Bewusst getrennt vom Speichern: Was nicht durch diese Prüfung kommt, wird gar nicht erst
 * verschlüsselt abgelegt.
 */
function umfrage_answers_check(array $poll, array $antworten, array $sonstiges = []): array
{
    $fragen = umfrage_questions((int)$poll['id']);
    if (!$fragen) return ['ok' => false, 'msg' => 'Diese Umfrage hat keine Fragen.', 'daten' => []];
    $sauber = [];
    foreach ($fragen as $q) {
        $qid = (int)$q['id'];
        $roh = $antworten[$qid] ?? null;
        $typ = (string)$q['type'];
        $pflicht = (int)$q['required'] === 1;
        $gueltig = array_map(fn($o) => (int)$o['id'], $q['options']);
        // „Sonstiges: …" – im Formular ist es der Wert 'x' plus ein eigenes Textfeld.
        $andereErlaubt = (int)($q['allow_other'] ?? 0) === 1 && in_array($typ, ['single', 'multi'], true);
        $andererText   = mb_substr(trim((string)(is_array($sonstiges[$qid] ?? null) ? '' : ($sonstiges[$qid] ?? ''))), 0, 200);

        if ($typ === 'info') continue;   // gliedert nur, fragt nichts

        if ($typ === 'single') {
            $rohWert = is_array($roh) ? '' : (string)$roh;
            if ($andereErlaubt && $rohWert === 'x') {
                $sauber[] = ['q' => $qid, 'o' => null, 'n' => null, 't' => $andererText, 'x' => 1];
                continue;
            }
            $wahl = (int)$rohWert;
            if ($wahl === 0) { if ($pflicht) return ['ok' => false, 'msg' => 'Bitte alle Pflichtfragen beantworten.', 'daten' => []]; continue; }
            if (!in_array($wahl, $gueltig, true)) return ['ok' => false, 'msg' => 'Ungültige Auswahl.', 'daten' => []];
            $sauber[] = ['q' => $qid, 'o' => $wahl, 'n' => null, 't' => ''];
        } elseif ($typ === 'multi') {
            $rohListe = (array)$roh;
            $andere = $andereErlaubt && in_array('x', array_map('strval', $rohListe), true);
            $wahlen = array_values(array_unique(array_map('intval', $rohListe)));
            $wahlen = array_values(array_filter($wahlen, fn($x) => in_array($x, $gueltig, true)));
            if (!$wahlen && !$andere) { if ($pflicht) return ['ok' => false, 'msg' => 'Bitte alle Pflichtfragen beantworten.', 'daten' => []]; continue; }
            $max = (int)$q['max_choices'];
            if ($max > 0 && count($wahlen) + ($andere ? 1 : 0) > $max) return ['ok' => false, 'msg' => 'Bei „' . $q['title'] . '" sind höchstens ' . $max . ' Antworten erlaubt.', 'daten' => []];
            foreach ($wahlen as $w) $sauber[] = ['q' => $qid, 'o' => $w, 'n' => null, 't' => ''];
            if ($andere) $sauber[] = ['q' => $qid, 'o' => null, 'n' => null, 't' => $andererText, 'x' => 1];
        } elseif ($typ === 'scale') {
            $wert = is_array($roh) ? null : (is_numeric($roh) ? (int)$roh : null);
            if ($wert === null) { if ($pflicht) return ['ok' => false, 'msg' => 'Bitte alle Pflichtfragen beantworten.', 'daten' => []]; continue; }
            if ($wert < (int)$q['scale_min'] || $wert > (int)$q['scale_max']) return ['ok' => false, 'msg' => 'Ungültiger Wert.', 'daten' => []];
            $sauber[] = ['q' => $qid, 'o' => null, 'n' => $wert, 't' => ''];
        } else {
            $txt = is_array($roh) ? '' : mb_substr(trim((string)$roh), 0, 2000);
            if ($txt === '') { if ($pflicht) return ['ok' => false, 'msg' => 'Bitte alle Pflichtfragen beantworten.', 'daten' => []]; continue; }
            $sauber[] = ['q' => $qid, 'o' => null, 'n' => null, 't' => $txt];
        }
    }
    return ['ok' => true, 'msg' => '', 'daten' => $sauber];
}

/** Bis wann darf eine unbestätigte Stimme liegen bleiben? Fristende + 3 Tage (sonst 14 Tage). */
function umfrage_pending_until(array $poll): string
{
    $ende = trim((string)$poll['ends_at']);
    $ts = $ende !== '' ? strtotime($ende) : 0;
    return $ts ? date('Y-m-d H:i:s', $ts + UMFRAGE_PENDING_DAYS * 86400)
               : date('Y-m-d H:i:s', time() + 14 * 86400);   // ohne Frist: 14 Tage
}

/**
 * Schritt 1: Stimme entgegennehmen und Bestätigungsmail einreihen.
 *
 * Nach außen gibt es IMMER dieselbe Antwort – auch bei fremder Endung oder wenn die Adresse
 * schon bestätigt hat. Sonst wäre das Formular ein Werkzeug, um das Abstimmverhalten
 * einzelner Leute abzufragen. Die Felder 'gesendet'/'grund' sind nur fürs Log.
 */
function umfrage_vote_start(array $poll, string $mailRoh, array $antworten, array $sonstiges = []): array
{
    if (!umfrage_is_open($poll)) return ['ok' => false, 'msg' => 'Diese Umfrage nimmt gerade keine Stimmen an.', 'eta' => ''];

    $pruef = umfrage_answers_check($poll, $antworten, $sonstiges);
    if (!$pruef['ok']) return ['ok' => false, 'msg' => $pruef['msg'], 'eta' => ''];

    // Reicht das Kontingent bis zum Fristende nicht mehr für diese Mail, wird die Stimme gar
    // nicht erst angenommen. Sie anzunehmen wäre die unfreundlichere Variante: Die Person
    // wartet auf eine Bestätigung, die zu spät oder nie kommt, und ihre Stimme zählt trotzdem nicht.
    if (umfrage_queue_len() >= umfrage_capacity_until((string)$poll['ends_at'])) {
        return ['ok' => false, 'eta' => '', 'msg' => 'Gerade stimmen sehr viele Leute gleichzeitig ab – '
            . 'die Bestätigungsmail käme nicht mehr rechtzeitig vor dem Ende der Umfrage an. '
            . 'Bitte versuch es in ein paar Stunden noch einmal.'];
    }

    $mail = umfrage_mail_normalize($mailRoh, $poll);
    // Ab hier wird nach außen nicht mehr unterschieden – die Antwort ist immer dieselbe.
    $eta = umfrage_mail_eta(umfrage_queue_len());
    if ($mail === '') return ['ok' => true, 'msg' => '', 'eta' => $eta, 'grund' => 'domain'];

    $hash = umfrage_mail_hash($poll, $mail);
    $st = umfrage_db()->prepare('SELECT * FROM voters WHERE poll_id = ? AND mail_hash = ?');
    $st->execute([(int)$poll['id'], $hash]);
    $voter = $st->fetch();
    if ($voter && (int)$voter['used'] === 1) return ['ok' => true, 'msg' => '', 'eta' => $eta, 'grund' => 'schon'];

    if (!$voter) {
        umfrage_db()->prepare('INSERT INTO voters(poll_id, mail_hash, used) VALUES(?,?,0)')->execute([(int)$poll['id'], $hash]);
        $voterId = (int)umfrage_db()->lastInsertId();
    } else {
        $voterId = (int)$voter['id'];
        // Noch nicht bestätigt und noch einmal abgestimmt? Dann gilt die neue Stimme – die alte
        // wartende Zeile fliegt raus. So kann man es sich bis zur Bestätigung anders überlegen.
        umfrage_db()->prepare('DELETE FROM pending WHERE voter_id = ?')->execute([$voterId]);
    }

    $token = bin2hex(random_bytes(32));
    $blob  = umfrage_seal($token, $pruef['daten']);
    if ($blob === '') return ['ok' => false, 'msg' => 'Die Stimme konnte nicht gesichert werden. Bitte noch einmal versuchen.', 'eta' => ''];

    // Reicht das Kontingent, geht die Mail gleich raus und das Token wird NICHT abgelegt.
    // Nur im Stau muss es bis zum Versand mit – siehe Kommentar am Schema.
    $sofort = umfrage_mail_budget() > 0;
    umfrage_db()->prepare('INSERT INTO pending(poll_id, voter_id, token_hash, payload, email, mail_token, mailed, expires_at) VALUES(?,?,?,?,?,?,0,?)')
        ->execute([(int)$poll['id'], $voterId, hash('sha256', $token), $blob, $mail,
                   $sofort ? '' : $token, umfrage_pending_until($poll)]);

    return ['ok' => true, 'msg' => '', 'eta' => $sofort ? 'in den nächsten Minuten' : $eta, 'grund' => 'wartet',
            'pending_id' => (int)umfrage_db()->lastInsertId(), 'token' => $token, 'sofort' => $sofort];
}

/**
 * Wartende Bestätigungsmails verschicken, solange das Kontingent reicht.
 * $mailer bekommt (Adresse, Token) und gibt true/false zurück.
 */
function umfrage_queue_run(callable $mailer, int $max = 120): array
{
    $budget = min($max, umfrage_mail_budget());
    if ($budget <= 0) return ['sent' => 0, 'failed' => 0, 'left' => umfrage_queue_len()];

    $st = umfrage_db()->prepare('SELECT * FROM pending WHERE mailed = 0 AND expires_at >= ? ORDER BY id LIMIT ?');
    $st->execute([date('Y-m-d H:i:s'), $budget]);
    $sent = 0; $failed = 0;
    foreach ($st->fetchAll() as $row) {
        $mail = trim((string)$row['email']);
        if ($mail === '') { // ohne Adresse ist nichts zu tun – Zeile als erledigt markieren
            umfrage_db()->prepare('UPDATE pending SET mailed = 1 WHERE id = ?')->execute([(int)$row['id']]);
            continue;
        }
        $token = trim((string)$row['mail_token']);
        if ($token === '') { // ohne Token lässt sich kein Link bauen – nicht liegen lassen
            umfrage_db()->prepare('UPDATE pending SET mailed = 1 WHERE id = ?')->execute([(int)$row['id']]);
            continue;
        }
        $ok = (bool)$mailer($mail, $token);
        if ($ok) {
            umfrage_pending_mailed((int)$row['id']);
            $sent++;
        } else {
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'left' => umfrage_queue_len()];
}

/** Einen erfolgten Versand im Zähler vermerken (nur der Zeitpunkt, sonst nichts). */
function umfrage_mail_note(): void
{
    umfrage_db()->prepare('INSERT INTO mail_log(sent_at) VALUES(?)')->execute([date('Y-m-d H:i:s')]);
    // Alles älter als zwei Tage wird für Kontingent und Prognose nicht mehr gebraucht.
    umfrage_db()->prepare('DELETE FROM mail_log WHERE sent_at < ?')->execute([date('Y-m-d H:i:s', time() - 172800)]);
    // Zusätzlich ins gemeinsame Konto: Daran rechnen die anderen Bereiche mit.
    mail_pool_note('umfrage');
}

/** Eine wartende Zeile als verschickt markieren (wenn die Mail direkt beim Abstimmen rausging). */
function umfrage_pending_mailed(int $pendingId): void
{
    // Adresse UND Token verschwinden mit dem Versand – ab hier ist die wartende Stimme versiegelt.
    umfrage_db()->prepare("UPDATE pending SET mailed = 1, email = '', mail_token = '' WHERE id = ?")->execute([$pendingId]);
    umfrage_mail_note();
}

/**
 * Schritt 2: Bestätigen. Der Token aus dem Link entschlüsselt die wartenden Antworten,
 * die Stimme wandert in die Urne, die wartende Zeile verschwindet.
 */
function umfrage_confirm(string $token): array
{
    $token = trim($token);
    if ($token === '' || !preg_match('~^[0-9a-f]{64}$~', $token)) return ['ok' => false, 'msg' => 'Dieser Link ist nicht lesbar.'];

    $st = umfrage_db()->prepare('SELECT * FROM pending WHERE token_hash = ? AND expires_at >= ?');
    $st->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'msg' => 'Dieser Bestätigungslink gilt nicht mehr – entweder wurde er schon benutzt, oder die Frist ist abgelaufen.'];

    $poll = umfrage_get((int)$row['poll_id']);
    if (!$poll) return ['ok' => false, 'msg' => 'Diese Umfrage gibt es nicht mehr.'];
    // Nach Fristende darf nichts mehr dazukommen – sonst stimmte das veröffentlichte Ergebnis nicht.
    if (!umfrage_is_open($poll)) return ['ok' => false, 'msg' => 'Die Umfrage ist inzwischen beendet – diese Stimme kann nicht mehr gezählt werden.', 'poll' => $poll];

    $daten = umfrage_unseal($token, (string)$row['payload']);
    if ($daten === null) return ['ok' => false, 'msg' => 'Die gespeicherte Stimme lässt sich nicht mehr lesen. Bitte noch einmal abstimmen.', 'poll' => $poll];

    $db = umfrage_db();
    try {
        $db->beginTransaction();
        // Verzeichnis zuerst abhaken – und nur, wenn dort noch nichts steht. Damit erzeugt auch
        // ein doppelt geklickter Link keine zweite Stimme.
        $ok = $db->prepare('UPDATE voters SET used = 1 WHERE id = ? AND used = 0');
        $ok->execute([(int)$row['voter_id']]);
        if ($ok->rowCount() !== 1) { $db->rollBack(); return ['ok' => false, 'msg' => 'Für diese Adresse wurde bereits eine Stimme gezählt.', 'poll' => $poll]; }

        $db->prepare('INSERT INTO ballots(poll_id) VALUES(?)')->execute([(int)$poll['id']]);
        $ballot = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO answers(ballot_id, question_id, option_id, value_num, value_text, is_other) VALUES(?,?,?,?,?,?)');
        foreach ($daten as $a) {
            $ins->execute([$ballot, (int)($a['q'] ?? 0), isset($a['o']) ? (int)$a['o'] : null,
                           isset($a['n']) ? (int)$a['n'] : null, (string)($a['t'] ?? ''), (int)($a['x'] ?? 0)]);
        }
        // Der Faden zwischen Person und Stimme wird hier gekappt – ab jetzt gibt es ihn nicht mehr.
        $db->prepare('DELETE FROM pending WHERE id = ?')->execute([(int)$row['id']]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => 'Die Stimme konnte nicht gezählt werden. Bitte den Link noch einmal öffnen.', 'poll' => $poll];
    }
    return ['ok' => true, 'msg' => umfrage_text('done'), 'poll' => $poll];
}

// ---------------------------------------------------------------------------
// Auswertung
// ---------------------------------------------------------------------------

/**
 * Ergebnis je Frage: Auswahl mit Anzahl und Anteil, Skala mit Mittelwert und Verteilung,
 * Freitexte als Liste. $mitFreitext steuert, ob die Texte mitkommen (öffentlich ggf. nicht).
 */
function umfrage_results(int $pollId, bool $mitFreitext = true): array
{
    $fragen = umfrage_questions($pollId);
    $gesamt = umfrage_counts($pollId)['stimmen'];
    $out = [];
    foreach ($fragen as $q) {
        $qid = (int)$q['id'];
        $typ = (string)$q['type'];
        $eintrag = ['frage' => $q, 'typ' => $typ, 'gesamt' => $gesamt];

        if ($typ === 'info') {
            // Zwischentexte strukturieren auch das Ergebnis – gezählt wird an ihnen nichts.
            $out[] = $eintrag;
            continue;
        }

        if ($typ === 'single' || $typ === 'multi') {
            $st = umfrage_db()->prepare('SELECT option_id, COUNT(*) AS n FROM answers WHERE question_id = ? AND option_id IS NOT NULL GROUP BY option_id');
            $st->execute([$qid]);
            $zahl = [];
            foreach ($st->fetchAll() as $r) $zahl[(int)$r['option_id']] = (int)$r['n'];
            // „Sonstiges"-Antworten: eigene Zeile plus die eingetippten Texte.
            $st = umfrage_db()->prepare("SELECT value_text FROM answers WHERE question_id = ? AND is_other = 1 ORDER BY id");
            $st->execute([$qid]);
            $andereTexte = array_values(array_filter(array_map(fn($r) => trim((string)$r['value_text']), $st->fetchAll()), fn($t) => $t !== ''));
            $st = umfrage_db()->prepare('SELECT COUNT(*) FROM answers WHERE question_id = ? AND is_other = 1');
            $st->execute([$qid]);
            $andereN = (int)$st->fetchColumn();
            $summe = array_sum($zahl) + $andereN;
            $zeilen = [];
            foreach ($q['options'] as $o) {
                $n = $zahl[(int)$o['id']] ?? 0;
                // Bei Mehrfachauswahl ist der Anteil auf die Stimmzettel bezogen, nicht auf die Kreuze
                $basis = $typ === 'multi' ? max(1, $gesamt) : max(1, $summe);
                $zeilen[] = ['label' => (string)$o['label'], 'n' => $n, 'pct' => (int)round($n * 100 / $basis)];
            }
            if ((int)($q['allow_other'] ?? 0) === 1) {
                $basis = $typ === 'multi' ? max(1, $gesamt) : max(1, $summe);
                $zeilen[] = ['label' => 'Sonstiges', 'n' => $andereN, 'pct' => (int)round($andereN * 100 / $basis)];
            }
            $eintrag['zeilen'] = $zeilen;
            $eintrag['abgegeben'] = $summe;
            $eintrag['sonstige'] = $mitFreitext ? $andereTexte : [];
        } elseif ($typ === 'scale') {
            $st = umfrage_db()->prepare('SELECT value_num, COUNT(*) AS n FROM answers WHERE question_id = ? AND value_num IS NOT NULL GROUP BY value_num');
            $st->execute([$qid]);
            $verteilung = []; $summe = 0; $n = 0;
            foreach ($st->fetchAll() as $r) {
                $verteilung[(int)$r['value_num']] = (int)$r['n'];
                $summe += (int)$r['value_num'] * (int)$r['n'];
                $n += (int)$r['n'];
            }
            $zeilen = [];
            for ($v = (int)$q['scale_min']; $v <= (int)$q['scale_max']; $v++) {
                $c = $verteilung[$v] ?? 0;
                $zeilen[] = ['label' => (string)$v, 'n' => $c, 'pct' => (int)round($c * 100 / max(1, $n))];
            }
            $eintrag['zeilen'] = $zeilen;
            $eintrag['abgegeben'] = $n;
            $eintrag['schnitt'] = $n > 0 ? round($summe / $n, 2) : null;
        } else {
            $st = umfrage_db()->prepare('SELECT value_text FROM answers WHERE question_id = ? AND value_text <> \'\' ORDER BY id');
            $st->execute([$qid]);
            $texte = array_map(fn($r) => (string)$r['value_text'], $st->fetchAll());
            $eintrag['abgegeben'] = count($texte);
            $eintrag['texte'] = $mitFreitext ? $texte : [];
        }
        $out[] = $eintrag;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Aufräumen
// ---------------------------------------------------------------------------

/**
 * Verzeichnis und Token beendeter Umfragen nach der Schonfrist löschen.
 * Die Stimmen bleiben – sie sind anonym und niemandem mehr zuzuordnen.
 */
function umfrage_prune(): int
{
    $grenze = date('Y-m-d H:i:s', time() - UMFRAGE_KEEP_DAYS * 86400);
    // Aufgeräumt wird erst, wenn das ENDE der Umfrage 90 Tage her ist – NICHT nach
    // „status = 'closed' OR ends_at älter als 90 Tage": Damit verlöre eine Umfrage ihr
    // Verzeichnis und die WARTENDEN Stimmen in der Sekunde des Schließens, die Schonfrist
    // fürs Bestätigen wäre wirkungslos. Als Zeitpunkt
    // des Endes gilt closed_at (beim Schließen gestempelt), sonst die Frist, sonst das Anlegen.
    $alt = umfrage_db()->prepare("SELECT id FROM polls WHERE
        (status = 'closed' AND COALESCE(NULLIF(closed_at, ''), NULLIF(ends_at, ''), created_at) < ?)
        OR (status <> 'draft' AND ends_at <> '' AND ends_at < ?)");
    $alt->execute([$grenze, $grenze]);
    $n = 0;
    foreach ($alt->fetchAll() as $r) {
        $pid = (int)$r['id'];
        $d1 = umfrage_db()->prepare('DELETE FROM pending WHERE poll_id = ?'); $d1->execute([$pid]);
        $d2 = umfrage_db()->prepare('DELETE FROM voters WHERE poll_id = ?'); $d2->execute([$pid]);
        $n += $d1->rowCount() + $d2->rowCount();
    }
    // Nie bestätigte Stimmen: nach Fristende + Schonfrist verfallen sie endgültig.
    $d = umfrage_db()->prepare('DELETE FROM pending WHERE expires_at < ?');
    $d->execute([date('Y-m-d H:i:s')]);
    // Bilddateien ohne Frage dahinter – siehe umfrage_bild_waisen().
    return $n + $d->rowCount() + umfrage_bild_waisen();
}

// ---------------------------------------------------------------------------
// Bilder an Fragen
//
// Die Datei liegt in umfrage/bilder/, in der Datenbank steht nur ihr NAME. Hochgeladenes wird
// immer neu gerechnet (JPEG, längste Kante 1200 px): Das wirft EXIF samt GPS weg, deckelt die
// Größe und macht aus einer getarnten Datei ein echtes Bild. Gelöscht wird an vier Stellen –
// Bild ersetzt, Frage gelöscht, Umfrage gelöscht, und als Netz der Waisen-Lauf im Aufräumen.
// ---------------------------------------------------------------------------

const UMFRAGE_BILD_MAX_KANTE = 1200;
const UMFRAGE_BILD_MAX_BYTES = 12582912;   // 12 MB Rohdatei – nach dem Rechnen bleibt viel weniger

function umfrage_bild_dir(): string { return __DIR__ . '/umfrage/bilder'; }

/**
 * Geheimer Vorschau-Link: zeigt auch den ENTWURF, damit man den fertigen Bogen einmal
 * durchklicken kann, bevor er an die Hochschule geht. Das Token wird bei Bedarf nachgelegt
 * (Umfragen von vor dieser Funktion haben noch keins) und lässt sich neu würfeln, falls der
 * Link zu weit gestreut wurde. Abgestimmt wird in der Vorschau nichts – das regelt die Seite.
 */
function umfrage_preview_token(int $pollId, bool $neu = false): string
{
    $p = umfrage_get($pollId);
    if (!$p) return '';
    $t = trim((string)($p['preview_token'] ?? ''));
    if ($t === '' || $neu) {
        $t = bin2hex(random_bytes(12));
        umfrage_db()->prepare('UPDATE polls SET preview_token = ? WHERE id = ?')->execute([$t, $pollId]);
    }
    return $t;
}

/** Adresse der Vorschau ('' ohne Basis-Adresse in den Einstellungen). */
function umfrage_preview_url(array $poll): string
{
    $basis = rtrim(umfrage_setting_get('base_url', ''), '/');
    if ($basis === '') return '';
    $t = umfrage_preview_token((int)$poll['id']);
    return $basis . '/umfrage/?u=' . rawurlencode((string)$poll['slug']) . '&vorschau=' . rawurlencode($t);
}

/** Öffentliche Adresse eines Fragen-Bildes (relativ zur Umfrage-Seite). */
function umfrage_bild_url(string $datei): string
{
    $datei = basename(trim($datei));
    return $datei === '' ? '' : 'bilder/' . rawurlencode($datei);
}

/**
 * Hochgeladenes Bild annehmen: prüfen, verkleinern, ablegen. Rückgabe: Dateiname oder ''.
 * Der Name wird gewürfelt – ein hochgeladener Name darf nie in einen Pfad geraten.
 */
function umfrage_bild_speichern(array $file, ?string &$err = null): string
{
    $err = null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) { $err = 'Das Bild kam nicht vollständig an (zu groß?).'; return ''; }
    if ((int)($file['size'] ?? 0) > UMFRAGE_BILD_MAX_BYTES) { $err = 'Das Bild ist zu groß (höchstens 12 MB).'; return ''; }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) { $err = 'Keine gültige Datei empfangen.'; return ''; }
    $g = @getimagesize($tmp);
    if (!is_array($g) || !in_array((string)($g['mime'] ?? ''), ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $err = 'Nur JPG, PNG oder WEBP sind möglich.';
        return '';
    }
    $dir = umfrage_bild_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $err = 'Der Bilder-Ordner lässt sich nicht anlegen.'; return ''; }
    // Der Ordner MUSS öffentlich lesbar sein – die Bilder werden ja ausgeliefert. Ausgeführt
    // werden darf dort nichts: Wir legen nur fertig gerechnete JPEGs ab, aber falls doch je
    // etwas anderes hineingerät, soll der Server es als Datei behandeln und nicht als Programm.
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "php_flag engine off\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    $name = 'f' . date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.jpg';
    if (!umfrage_bild_rechnen($tmp, $dir . '/' . $name, (string)$g['mime'])) {
        @unlink($dir . '/' . $name);
        $err = 'Das Bild konnte nicht umgewandelt werden.';
        return '';
    }
    return $name;
}

/** Verkleinern und als JPEG schreiben – Imagick, sonst GD. true bei Erfolg. */
function umfrage_bild_rechnen(string $quelle, string $ziel, string $mime): bool
{
    $max = UMFRAGE_BILD_MAX_KANTE;
    try {
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            $im = new Imagick();
            $im->readImage($quelle);
            $im = $im->coalesceImages();          // animierte Vorlagen: nur das erste Bild
            $im->setIteratorIndex(0);
            $im->autoOrient();
            $b = $im->getImageWidth(); $h = $im->getImageHeight();
            if (max($b, $h) > $max) $im->resizeImage($b >= $h ? $max : 0, $b >= $h ? 0 : $max, Imagick::FILTER_LANCZOS, 1);
            $im->stripImage();                    // EXIF weg, inklusive GPS
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(82);
            $im->setImageBackgroundColor('white');
            $im = $im->flattenImages();           // PNG mit Transparenz sonst schwarz
            $ok = (bool)$im->writeImage($ziel);
            $im->clear();
            return $ok;
        }
        $bild = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($quelle),
            'image/png'  => @imagecreatefrompng($quelle),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($quelle) : false,
            default      => false,
        };
        if (!$bild) return false;
        $b = imagesx($bild); $h = imagesy($bild);
        $f = max($b, $h) > $max ? $max / max($b, $h) : 1.0;
        $nb = max(1, (int)round($b * $f)); $nh = max(1, (int)round($h * $f));
        $neu = imagecreatetruecolor($nb, $nh);
        imagefilledrectangle($neu, 0, 0, $nb, $nh, imagecolorallocate($neu, 255, 255, 255));
        imagecopyresampled($neu, $bild, 0, 0, 0, 0, $nb, $nh, $b, $h);
        return (bool)imagejpeg($neu, $ziel, 82);   // GD schreibt von sich aus kein EXIF mit
    } catch (\Throwable $e) {
        return false;
    }
}

/** Bilddatei entfernen (stumm, wenn sie schon weg ist). */
function umfrage_bild_loeschen(string $datei): void
{
    $datei = basename(trim($datei));
    if ($datei === '') return;
    @unlink(umfrage_bild_dir() . '/' . $datei);
}

/** Alle Bilder EINER Umfrage wegräumen – vor dem Löschen der Zeilen aufzurufen. */
function umfrage_bilder_der_umfrage_loeschen(int $pollId): int
{
    $st = umfrage_db()->prepare("SELECT image FROM questions WHERE poll_id = ? AND image <> ''");
    $st->execute([$pollId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { umfrage_bild_loeschen((string)$d); $n++; }
    return $n;
}

/**
 * Netz gegen Dateileichen: Was im Ordner liegt, aber an keiner Frage mehr hängt, fliegt raus.
 * Nötig, weil ein Absturz zwischen Upload und Speichern eine Datei ohne Zeile hinterlässt.
 */
function umfrage_bild_waisen(): int
{
    $dir = umfrage_bild_dir();
    if (!is_dir($dir)) return 0;
    $bekannt = [];
    foreach (umfrage_db()->query("SELECT image FROM questions WHERE image <> ''")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $bekannt[basename((string)$d)] = true;
    }
    $n = 0;
    foreach ((array)@scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess' || isset($bekannt[$f])) continue;
        if (!is_file($dir . '/' . $f)) continue;
        // Schonfrist: eine Datei, die gerade erst hochgeladen wurde, gehört vielleicht zu einem
        // Formular, das noch offen ist. Erst ab einem Tag ohne Zeile gilt sie als Waise.
        if (time() - (int)@filemtime($dir . '/' . $f) < 86400) continue;
        if (@unlink($dir . '/' . $f)) $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Ergebnis-Grafiken
// ---------------------------------------------------------------------------

/* Die drei Zeichen-Bausteine wohnen seit den externen Events im GEMEINSAMEN Kern
   chart-core.php – hier bleiben dünne Reste unter den alten Namen, damit Aufrufer
   (und der Selbsttest) nichts merken. */

function umfrage_balken_html(array $zeilen): string { return chart_balken_html($zeilen); }
function umfrage_skala_html(array $zeilen, ?float $schnitt, int $min): string { return chart_saeulen_html($zeilen, $schnitt, $min); }
function umfrage_verlauf_html(array $tage): string { return chart_verlauf_html($tage); }

/** Beteiligung je Tag (aus der Urne – dort steht ohnehin nur das Datum). */
function umfrage_verlauf(int $pollId): array
{
    $st = umfrage_db()->prepare('SELECT created_on AS tag, COUNT(*) AS n FROM ballots WHERE poll_id = ? GROUP BY created_on ORDER BY created_on');
    $st->execute([$pollId]);
    $roh = [];
    foreach ($st->fetchAll() as $r) $roh[(string)$r['tag']] = (int)$r['n'];
    return chart_tage_fuellen($roh);
}
