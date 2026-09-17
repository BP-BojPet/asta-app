<?php
/**
 * Gemeinsames Mail-Konto.
 *
 * Der Hoster lässt eine feste Zahl Mails am Tag zu (bei Mittwald 3000 je Absenderkonto).
 * Es gibt EIN Konto, in das jede tatsächlich verschickte Mail eingetragen wird, egal aus
 * welchem Bereich – feste Scheiben je Bereich wären Unfug, weil die Bereiche nie gleichzeitig
 * laufen und eine ungenutzte Scheibe schlicht verfiele. Wer verschicken will, fragt, wie viel
 * im Topf noch drin ist, und rechnet damit an, was die anderen schon verbraucht haben.
 *
 * Bewusst eine eigene, winzige Datei mit eigener Datenbank und OHNE jede Abhängigkeit:
 * Der öffentliche Bereich (pat/, umfrage/, anmeldung/) bindet die lib.php nie ein, muss aber
 * denselben Kontostand sehen wie die Verwaltung.
 *
 * Zwei Sorten Mail, und der Unterschied ist der ganze Sinn der Reserve:
 *  - RUNDMAILS (Pat:innenprogramm, Umfragen, externe Events) fragen vorher und hören auf,
 *    bevor der Topf leer ist.
 *  - EINZELMAILS der App (Login-Links, Mitteilungen) tragen nur ein und gehen IMMER raus.
 *    Genau dafür bleibt die Reserve stehen: Ein Login-Link darf nie an einer Rundmail
 *    scheitern.
 */

const MAIL_POOL_FILE = __DIR__ . '/data/mailpool.sqlite';

/** Bereiche, die im Konto auftauchen – die Beschriftung gilt für alle Anzeigen. */
function mail_pool_quellen(): array
{
    return [
        'app'      => 'App (Login-Links, Mitteilungen)',
        'pat'      => 'Pat:innenprogramm',
        'umfrage'  => 'Umfragen',
        'extern'   => 'Externe Events',
        'termin'   => 'Terminplaner (öffentlich)',
        'waslaeuft' => 'was.läuft (Veranstalter-Zugänge)',
    ];
}

function mail_pool_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(MAIL_POOL_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . MAIL_POOL_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 4000');
    // Nur Zeitpunkt und Bereich. Keine Adresse, kein Betreff – das Konto ist eine Strichliste.
    $pdo->exec('CREATE TABLE IF NOT EXISTS sends (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        source TEXT    NOT NULL,
        ts     INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_sends_ts ON sends(ts)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS pool_settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    return $pdo;
}

function mail_pool_setting(string $k, string $vorgabe = ''): string
{
    try {
        $st = mail_pool_db()->prepare('SELECT v FROM pool_settings WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? $vorgabe : (string)$v;
    } catch (\Throwable $e) { return $vorgabe; }
}

function mail_pool_setting_set(string $k, string $v): void
{
    mail_pool_db()->prepare('INSERT INTO pool_settings(k, v) VALUES(?, ?)
        ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

/**
 * DIE Absenderadresse der ganzen App.
 *
 * Es gibt genau eine, und das ist keine Kosmetik: Beim Hoster ist EIN Absenderkonto als
 * versendendes Postfach eingerichtet und freigeschaltet. Trägt ein Bereich eine andere Adresse
 * in die From-Zeile, verschickt weiterhin app@ – die Mail behauptet dann nur etwas anderes.
 * Das kostet Zustellbarkeit (SPF/DMARC sehen Absender und Postfach auseinanderlaufen) und
 * verschleiert, woher eine Mail wirklich kam.
 *
 * Antworten dürfen selbstverständlich woanders landen – dafür ist Reply-To da, und genau so
 * benutzen es die Bereiche: Absender app@, Antwort an die Verantwortlichen.
 *
 * Diese Datei ist der richtige Ort: Die öffentlichen Bereiche (pat/, umfrage/, termin/) binden
 * lib.php nie ein, brauchen aber dieselbe Adresse. lib.php spiegelt sie beim Lesen hierher.
 */
function mail_pool_absender(): string
{
    return trim(mail_pool_setting('from', ''));
}

function mail_pool_absender_set(string $adresse): void
{
    $adresse = trim(str_replace(["\r", "\n"], '', $adresse));
    if ($adresse === '' || !filter_var($adresse, FILTER_VALIDATE_EMAIL)) return;
    try { mail_pool_setting_set('from', $adresse); } catch (\Throwable $e) { /* nie den Versand kosten */ }
}

/** Was der Hoster am Tag zulässt. */
function mail_pool_limit(): int { return max(50, (int)mail_pool_setting('limit', '3000')); }

/** Was Rundmails NICHT anfassen dürfen – die Luft für Login-Links und Mitteilungen. */
function mail_pool_reserve(): int { return max(0, min(mail_pool_limit() - 10, (int)mail_pool_setting('reserve', '200'))); }

/** Eine tatsächlich verschickte Mail eintragen. Darf nie den Versand aufhalten. */
function mail_pool_note(string $quelle): void
{
    try {
        $q = array_key_exists($quelle, mail_pool_quellen()) ? $quelle : 'app';
        mail_pool_db()->prepare('INSERT INTO sends(source, ts) VALUES(?, ?)')->execute([$q, time()]);
    } catch (\Throwable $e) { /* Buchhaltung darf keine Mail kosten */ }
}

/** Verbrauch im gleitenden Fenster – gesamt oder für einen Bereich. */
function mail_pool_used(int $stunden = 24, ?string $quelle = null): int
{
    try {
        $ab = time() - max(1, $stunden) * 3600;
        if ($quelle === null) {
            $st = mail_pool_db()->prepare('SELECT COUNT(*) FROM sends WHERE ts >= ?');
            $st->execute([$ab]);
        } else {
            $st = mail_pool_db()->prepare('SELECT COUNT(*) FROM sends WHERE ts >= ? AND source = ?');
            $st->execute([$ab, $quelle]);
        }
        return (int)$st->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/**
 * Wie viele Rundmails dürfen JETZT noch raus? Das ist der ganze Topf minus Reserve minus
 * allem, was in den letzten 24 Stunden schon rausging – aus JEDEM Bereich, die App
 * eingerechnet. Hat gestern niemand gemailt, steht hier fast das volle Kontingent.
 */
function mail_pool_free(): int
{
    return max(0, mail_pool_limit() - mail_pool_reserve() - mail_pool_used(24));
}

/** Was einem Bereich pro Tag höchstens zusteht – für Prognosen („dauert noch X Tage"). */
function mail_pool_day_allow(): int
{
    return max(1, mail_pool_limit() - mail_pool_reserve());
}

/**
 * Der Kontostand für einen Rundmail-Bereich: der Topf, gedeckelt durch eine freiwillige
 * eigene Obergrenze. 0 als Obergrenze heißt „kein eigener Deckel" – dann gilt nur der Topf.
 */
function mail_pool_budget(string $quelle, int $eigenerDeckel = 0): int
{
    $frei = mail_pool_free();
    if ($eigenerDeckel > 0) $frei = min($frei, max(0, $eigenerDeckel - mail_pool_used(24, $quelle)));
    return max(0, $frei);
}

/** Zeilen für die Kontingent-Tabelle: je Bereich der Verbrauch der letzten 24 Stunden. */
function mail_pool_overview(): array
{
    $zeilen = [];
    foreach (mail_pool_quellen() as $k => $label) {
        $zeilen[] = ['quelle' => $k, 'label' => $label, 'sent' => mail_pool_used(24, $k)];
    }
    $used = mail_pool_used(24);
    return [
        'zeilen'  => $zeilen,
        'gesamt'  => $used,
        'limit'   => mail_pool_limit(),
        'reserve' => mail_pool_reserve(),
        'frei'    => mail_pool_free(),
        // Knapp wird es, wenn die Rundmails den Topf fast leer gezogen haben. Die Schwelle
        // hängt an der Hoster-Grenze: Bei 400 zugelassenen Mails wären 50 ein sinnvoller Rest,
        // bei 3000 ein Rundungsfehler.
        'eng'     => mail_pool_free() < max(50, (int)round(mail_pool_limit() * 0.05)),
    ];
}

/**
 * Empfohlene Werte für alle Mail-Einstellungen – an EINER Stelle.
 *
 * Warum das nötig ist: Die Vorgaben im Code greifen nur, solange noch nie jemand gespeichert
 * hat. Danach steht in der Datenbank eine Zahl, und die schlägt jede spätere Vorgabe – ändert
 * sich die Hoster-Grenze, bleiben überall die alten Zahlen stehen, ohne dass irgendwo sichtbar
 * wird, dass sie überholt sind. Die Verwaltungsseiten zeigen die Empfehlung deshalb neben
 * jedem Feld und sagen es, wenn der eingestellte Wert abweicht.
 *
 * Diese Datei ist der richtige Ort: Sie kennt die Hoster-Grenze und hängt von nichts ab.
 */
function mail_pool_empfehlung(): array
{
    return [
        // Gemeinsames Konto
        'limit'          => ['wert' => 3000, 'was' => 'was der Hoster je Absenderkonto zulässt'],
        'reserve'        => ['wert' => 200,  'was' => 'reichlich Luft für Login-Links; die sind ein paar am Tag, nicht ein paar hundert'],
        // Rundmail-Bereiche: 0 heißt „keine zusätzliche Bremse"
        'cap_day'        => ['wert' => 0,    'was' => 'kein eigener Tagesdeckel – der gemeinsame Topf reicht'],
        'cap_hour'       => ['wert' => 0,    'was' => 'keine Stundenbremse, damit nichts grundlos auf den Cron wartet'],
        'termin_cap_day' => ['wert' => 300,  'was' => 'der Terminplaner steht wildfremden Menschen offen – ein Deckel begrenzt den Schaden, falls jemand ihn ausnutzt'],
        // Pat:innenprogramm
        'pat_day_cap'    => ['wert' => 0,    'was' => 'kein eigener Deckel'],
        'pat_per_run'    => ['wert' => 250,  'was' => 'so viele schafft ein Lauf bequem'],
        'pat_seconds'    => ['wert' => 120,  'was' => 'Zeitbudget je Klick – begrenzt die Laufzeit, nicht das Kontingent'],
    ];
}

/** Alte Striche wegräumen. Zwei Tage reichen – gerechnet wird über 24 Stunden. */
function mail_pool_prune(): void
{
    try {
        mail_pool_db()->prepare('DELETE FROM sends WHERE ts < ?')->execute([time() - 172800]);
    } catch (\Throwable $e) { /* egal */ }
}
