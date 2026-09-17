<?php
/**
 * „was.läuft" – das öffentliche Veranstaltungsportal. Datenbank und Kernfunktionen.
 *
 * EIGENE SQLite-Datei, wie bei Pat:innenprogramm, Umfragen, externen Events und Terminplaner:
 * Der öffentliche Bereich kommt nie in die Nähe der Mitgliederdatenbank, und diese Datei bindet
 * die `lib.php` NIE ein. Was hier nicht steht, sind die Rechte – `wl_can_manage()` liegt in der
 * lib.php, weil Rollen Sache der internen App sind. Diese Datei kennt nur Daten.
 *
 * Warum „wl_" und nicht „portal_": Die Marke heißt was.läuft, der Ordner heißt für Studis lesbar
 * `veranstaltungen/`, und die Funktionen tragen wie überall sonst ein kurzes Kürzel (tplan_, pat_,
 * extern_). Drei Namen für dieselbe Sache klingt nach zu viel – ist aber Absicht: die Marke steht
 * auf der Seite, der Ordner in der Adresszeile, das Kürzel im Code.
 *
 * Zwei Sorten Inhalt in EINER Tabelle (`items`, Feld `kind`):
 *  - 'event' – hat ein Datum und ist danach vorbei (Party, Vortrag, Turnier).
 *  - 'kurs'  – hat einen Rhythmus statt eines Datums und läuft über Wochen (Sprachkurs, Chor).
 * Getrennte Tabellen wären sauberer auf dem Papier, aber Filter, Bilder, Freigabe, Veranstalter
 * und Löschfristen sind bei beiden identisch – das wäre zweimal derselbe Code mit einem Feld
 * Unterschied. Wo sie sich unterscheiden, unterscheidet sie das `kind`.
 *
 * ACHTUNG beim Hochladen: data/wl.sqlite und data/wl-bilder/ darf der rsync NIE mitnehmen.
 */

declare(strict_types=1);


// Marke dieser Installation (Logo, App-Symbol) – eigenständig, ohne lib.php.
require_once __DIR__ . '/brand-core.php';

// Zeitzone wie in lib.php – und AUS DEMSELBEN GRUND doppelt (PHP und C-Library, auf die sich
// SQLites date('now','localtime') stützt). Sie muss hier eigens stehen: Der öffentliche Teil
// bindet lib.php bewusst nicht ein, lief damit in der Server-Vorgabe (oft UTC) und war im
// Sommer zwei Stunden versetzt – „heute" begann dann um 2 Uhr nachts.
putenv('TZ=Europe/Berlin');
date_default_timezone_set('Europe/Berlin');

// Gemeinsames Mail-Konto aller Bereiche (eigenständig, ohne lib.php) – für spätere Benachrichtigungen
// an Veranstalter („dein Beitrag ist freigegeben").
require_once __DIR__ . '/mail-pool.php';
// Web-Push-Kern (eigenständig, ohne lib.php): dieselbe Mathematik wie in der App, aber
// EIGENE Schlüssel und EIGENE Abo-Tabellen hier in der wl-Datenbank (siehe wl_push_*).
require_once __DIR__ . '/push-core.php';

const WL_DB_FILE    = __DIR__ . '/data/wl.sqlite';
const WL_IMG_DIR    = __DIR__ . '/data/wl-bilder';
const WL_TOKEN_LEN  = 24;    // Bytes für Einreich- und Bearbeiten-Links
const WL_KEEP_DAYS  = 60;    // Vorgabe: abgelaufene Beiträge so lange aufheben, dann löschen

// -----------------------------------------------------------------------------------------------
// Datenbank
// -----------------------------------------------------------------------------------------------

function wl_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(WL_DB_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . WL_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 4000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)");

    // Veranstalter: Hochschulgruppen, Fachschaften, der AStA selbst. Wer hier steht, darf einreichen.
    $pdo->exec("CREATE TABLE IF NOT EXISTS orgs (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL,
        kurz       TEXT    NOT NULL DEFAULT '',   -- 1–2 Zeichen für den Farbpunkt an der Kachel
        art        TEXT    NOT NULL DEFAULT 'hsg',-- asta | hsg | fachschaft | extern
        mail       TEXT    NOT NULL DEFAULT '',   -- Rückfragen + Bescheid bei Freigabe
        token      TEXT    NOT NULL UNIQUE,       -- dauerhafter Einreich-Link
        active     INTEGER NOT NULL DEFAULT 1,
        auto_ok    INTEGER NOT NULL DEFAULT 0,    -- 1 = Beiträge gehen ohne Freigabe live (Vertrauensvorschuss)
        note       TEXT    NOT NULL DEFAULT '',   -- interne Notiz, nie öffentlich
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // Bilder liegen als Datei auf der Platte, hier stehen nur die Angaben dazu.
    $pdo->exec("CREATE TABLE IF NOT EXISTS images (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        file       TEXT    NOT NULL,              -- Dateiname in data/wl-bilder (nie ein Pfad)
        w          INTEGER NOT NULL DEFAULT 0,
        h          INTEGER NOT NULL DEFAULT 0,
        bytes      INTEGER NOT NULL DEFAULT 0,
        credit     TEXT    NOT NULL DEFAULT '',   -- Bildnachweis, erscheint klein am Bild
        stock      INTEGER NOT NULL DEFAULT 0,    -- 1 = Archivbild, steht ALLEN Einreichenden offen
        org_id     INTEGER REFERENCES orgs(id) ON DELETE SET NULL,
        rights_ok  INTEGER NOT NULL DEFAULT 0,    -- hat die einreichende Person die Rechte bestätigt?
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // Veranstaltungen UND Kurse.
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        kind          TEXT    NOT NULL DEFAULT 'event',   -- event | kurs
        org_id        INTEGER REFERENCES orgs(id) ON DELETE CASCADE,
        title         TEXT    NOT NULL,
        slug          TEXT    NOT NULL DEFAULT '',        -- für schöne Adressen
        teaser        TEXT    NOT NULL DEFAULT '',        -- eine Zeile für die Kachel
        text          TEXT    NOT NULL DEFAULT '',        -- Beschreibung auf der Detailseite
        cat           TEXT    NOT NULL DEFAULT 'sonstiges',
        image_id      INTEGER REFERENCES images(id) ON DELETE SET NULL,
        -- Veranstaltung
        starts_at     TEXT    NOT NULL DEFAULT '',        -- 'Y-m-d H:i'
        ends_at       TEXT    NOT NULL DEFAULT '',        -- leer = offenes Ende
        -- Kurs
        rhythmus      TEXT    NOT NULL DEFAULT '',        -- „mittwochs 18:00\"
        termine       INTEGER NOT NULL DEFAULT 0,         -- Anzahl Termine, 0 = unbestimmt
        einstieg      INTEGER NOT NULL DEFAULT 0,         -- 1 = Einstieg jederzeit möglich
        von_datum     TEXT    NOT NULL DEFAULT '',        -- Zeitraum des Kurses
        bis_datum     TEXT    NOT NULL DEFAULT '',
        -- beides
        ort           TEXT    NOT NULL DEFAULT '',
        adresse       TEXT    NOT NULL DEFAULT '',
        preis         TEXT    NOT NULL DEFAULT '',        -- Freitext: „3 €\", „Spende\", leer = frei
        frei          INTEGER NOT NULL DEFAULT 1,         -- 1 = Eintritt frei (eigene Kachel-Marke)
        barrierefrei  INTEGER NOT NULL DEFAULT 0,
        nur_studis    INTEGER NOT NULL DEFAULT 0,         -- 1 = nur für Studierende
        ab_alter      INTEGER NOT NULL DEFAULT 0,         -- 0 = keine Altersgrenze
        url           TEXT    NOT NULL DEFAULT '',        -- Seite des Veranstalters
        anmeldung_url TEXT    NOT NULL DEFAULT '',        -- z. B. auf unsere eigene Anmeldung
        -- Ablauf
        status        TEXT    NOT NULL DEFAULT 'pending', -- pending | live | rejected
        featured      INTEGER NOT NULL DEFAULT 0,         -- 1 = groß im Karussell oben
        edit_token    TEXT    NOT NULL DEFAULT '',        -- Selbstbedienung für den Veranstalter
        note          TEXT    NOT NULL DEFAULT '',        -- Begründung bei Ablehnung (geht an den Veranstalter)
        submitted_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        decided_at    TEXT    NOT NULL DEFAULT ''
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_items_status ON items(status, kind, starts_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_items_org ON items(org_id)');

    // Banner: großflächige Werbung oben auf der Startseite, zeitlich begrenzt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS banners (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title      TEXT    NOT NULL DEFAULT '',
        subtitle   TEXT    NOT NULL DEFAULT '',
        image_id   INTEGER REFERENCES images(id) ON DELETE SET NULL,
        url        TEXT    NOT NULL DEFAULT '',
        von        TEXT    NOT NULL DEFAULT '',
        bis        TEXT    NOT NULL DEFAULT '',
        active     INTEGER NOT NULL DEFAULT 1,
        sort       INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // Zugangsanfragen vom „Eintragen"-Knopf: Gruppen, die dabei sein wollen, melden sich hier –
    // die Verwaltung sieht sie im Veranstalter-Bereich und legt daraus die Gruppe an. Erledigte
    // Anfragen werden GELÖSCHT, nicht markiert: Es sind fremde Kontaktdaten, und aufgehoben
    // werden muss davon nichts, sobald der Veranstalter angelegt (oder abgelehnt) ist.
    $pdo->exec("CREATE TABLE IF NOT EXISTS org_requests (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL,
        mail       TEXT    NOT NULL,
        nachricht  TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // Push-Mitteilungen: Geräte abonnieren OHNE Konto – die Push-Adresse des
    // Browsers ist die Identität (unerratbar, vom Push-Dienst vergeben). Gespeichert wird
    // nur, was der Versand braucht; kein Name, keine Mail, nichts Persönliches.
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subs (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        endpoint   TEXT    NOT NULL UNIQUE,
        p256dh     TEXT    NOT NULL,
        auth       TEXT    NOT NULL,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    // „Erinnere mich": eine Zeile je Gerät und Beitrag. remind_at wird beim Anlegen aus dem
    // Beginn ABGELEITET (wl_remind_at) und mitgespeichert, damit der Cron nur vergleichen muss;
    // verschiebt die Verwaltung den Termin, rechnet wl_item_save die offenen Zeilen neu.
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_reminders (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        sub_id    INTEGER NOT NULL REFERENCES push_subs(id) ON DELETE CASCADE,
        item_id   INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
        remind_at TEXT    NOT NULL,
        sent      INTEGER NOT NULL DEFAULT 0,
        UNIQUE(sub_id, item_id)
    )");
    // Abos: org_id 0 = alle Veranstalter, cat '' = alle Kategorien – eine Zeile (5, 'party')
    // heißt also „Partys DIESES Veranstalters". Kein Fremdschlüssel auf orgs, weil 0 ein
    // gültiger Wert ist; verwaiste Zeilen räumt der Cron ab.
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_follows (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        sub_id     INTEGER NOT NULL REFERENCES push_subs(id) ON DELETE CASCADE,
        org_id     INTEGER NOT NULL DEFAULT 0,
        cat        TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(sub_id, org_id, cat)
    )");

    // Kategorie-Vorschläge: Wünsche der Gruppen aus dem Einreich-Formular –
    // ein Stapel für die Verwaltung, KEINE Automatik (neue Kategorien brauchen wl_cats()
    // samt Standard-Fotos und bleiben deshalb eine bewusste Entscheidung).
    $pdo->exec("CREATE TABLE IF NOT EXISTS cat_wuensche (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        org_id     INTEGER REFERENCES orgs(id) ON DELETE SET NULL,
        text       TEXT    NOT NULL,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // Wartende Änderungen: Bearbeitet eine Gruppe einen LIVE-Beitrag, bleibt die
    // bisherige Fassung online und die neue liegt hier – GEPRÜFT und fertig zum Anwenden
    // (daten = das bereinigte Felder-Paket aus wl_item_felder als JSON). Eine Zeile je Beitrag:
    // Wer noch einmal ändert, ersetzt seinen eigenen Stand, es stapelt sich nichts.
    $pdo->exec("CREATE TABLE IF NOT EXISTS item_edits (
        item_id    INTEGER PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
        daten      TEXT    NOT NULL,
        eingereicht TEXT   NOT NULL DEFAULT (datetime('now','localtime')),
        staff_done INTEGER NOT NULL DEFAULT 0   -- wurde den zuständigen Referaten schon gemeldet?
    )");
    // Besucherzählung. BEWUSST SO KLEIN WIE MÖGLICH: In stats_days stehen nur
    // anonyme Tagessummen, in stats_seen nur die Merker des LAUFENDEN Tages – keine IP, keine
    // Adresse, kein Verlauf, kein Cookie. Siehe wl_stat_hit().
    $pdo->exec("CREATE TABLE IF NOT EXISTS stats_days (
        day      TEXT PRIMARY KEY,               -- YYYY-MM-DD
        visitors INTEGER NOT NULL DEFAULT 0,     -- verschiedene Besucher:innen an diesem Tag
        views    INTEGER NOT NULL DEFAULT 0      -- Seitenaufrufe an diesem Tag
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stats_seen (
        h   TEXT PRIMARY KEY,                    -- Tages-Hash, nicht zurückrechenbar
        day TEXT NOT NULL
    )");

    // Testdaten-Markierung. Nachgerüstet per ALTER TABLE, damit eine Datenbank, die es schon gibt,
    // nicht neu angelegt werden muss – frische Installationen bekommen die Spalte hierüber genauso.
    $spalten = static function (PDO $p, string $tab): array {
        try { return array_column($p->query('PRAGMA table_info(' . $tab . ')')->fetchAll(), 'name'); }
        catch (\Throwable $e) { return []; }
    };
    foreach (['orgs', 'items', 'images', 'banners'] as $tab) {
        $vorhanden = $spalten($pdo, $tab);
        if ($vorhanden && !in_array('demo', $vorhanden, true)) {
            $pdo->exec('ALTER TABLE ' . $tab . ' ADD COLUMN demo INTEGER NOT NULL DEFAULT 0');
        }
    }

    // Echter Login (mailbasiert): Veranstalter melden sich mit MAILADRESSE und
    // Passwort an, der Einreich-Link ist abgelöst. Das Passwort setzen sie selbst über einen
    // Mail-Link (reset_token/reset_bis) – derselbe Weg dient als Einladung beim Anlegen und
    // als „Passwort vergessen". (`login` wird nicht benutzt und bleibt nur stehen, weil SQLite
    // DROP COLUMN schlecht kann.)
    $orgSpalten = $spalten($pdo, 'orgs');
    foreach (['login' => "TEXT NOT NULL DEFAULT ''", 'pass_hash' => "TEXT NOT NULL DEFAULT ''",
              'reset_token' => "TEXT NOT NULL DEFAULT ''", 'reset_bis' => "TEXT NOT NULL DEFAULT ''",
              // Öffentliches Profil: kleine Visitenkarte auf veranstalter.php,
              // von der Gruppe selbst gepflegt (einreichen.php). Alles freiwillig.
              'profil' => "TEXT NOT NULL DEFAULT ''", 'web' => "TEXT NOT NULL DEFAULT ''",
              'insta' => "TEXT NOT NULL DEFAULT ''",
              // Logo der Gruppe (Promo-Seite): Verweis in die images-Tabelle.
              // Es erscheint als Absender-Marke auf Veranstalter-Seite und Event-Detailseite,
              // taucht aber NICHT im Bild-Wähler auf (wl_images_for_org klammert es aus).
              'logo_id' => "INTEGER"] as $sp => $def) {
        if ($orgSpalten && !in_array($sp, $orgSpalten, true)) {
            $pdo->exec('ALTER TABLE orgs ADD COLUMN ' . $sp . ' ' . $def);
        }
    }

    // Push-Merker an Beiträgen: 0 = „über diesen Beitrag wurden die Abos noch nicht informiert".
    // Der Cron leitet den Versand daraus AB (live + push_done=0) – kein Haken beim Freigeben
    // nötig, und Auto-Freigaben laufen über denselben Weg. WICHTIG bei der Nachrüstung: Was beim
    // Einführen der Spalte schon live ist, gilt als erledigt – sonst bekämen alle Abonnenten
    // beim ersten Cron-Lauf den kompletten Bestand als „neu" um die Ohren.
    $itemSpalten = $spalten($pdo, 'items');
    if ($itemSpalten && !in_array('push_done', $itemSpalten, true)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN push_done INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE items SET push_done = 1 WHERE status = 'live'");
    }

    // Gewähltes Standard-Foto: '' = Automatik (aus der ID abgeleitet), sonst der Dateiname
    // aus wl_standard_bilder() – im Einreich-Formular auswählbar.
    if ($itemSpalten && !in_array('std_bild', $itemSpalten, true)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN std_bild TEXT NOT NULL DEFAULT ''");
    }

    // Studi-Rabatt: Freitext der Gruppe („3 € mit Studiausweis"), gedacht für
    // kostenpflichtige Beiträge – die Detailseite zeigt ihn nur, wenn ein Preis dransteht.
    if ($itemSpalten && !in_array('studi_rabatt', $itemSpalten, true)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN studi_rabatt TEXT NOT NULL DEFAULT ''");
    }

    // Mitgeliefertes Banner-Bild: '' = Archiv-Bild bzw. kein Bild (image_id), sonst der
    // Dateiname aus wl_banner_bilder() – fünf breite Panorama-Motive,
    // die mit der App kommen und in der Verwaltung als Vorschau-Kacheln wählbar sind.
    $bannerSpalten = $spalten($pdo, 'banners');
    if ($bannerSpalten && !in_array('std_bild', $bannerSpalten, true)) {
        $pdo->exec("ALTER TABLE banners ADD COLUMN std_bild TEXT NOT NULL DEFAULT ''");
    }

    // Absage: Eine Absage ist Information, kein Nicht-Ereignis – der Beitrag bleibt
    // mit Banderole stehen (Status bleibt 'live', alle Listen-Abfragen funktionieren weiter),
    // und wer sich erinnern lassen wollte, bekommt EINE Absage-Mitteilung statt gar nichts.
    // absage_push_done merkt sich, dass sie raus ist (der Cron leitet den Versand daraus ab).
    if ($itemSpalten && !in_array('abgesagt', $itemSpalten, true)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN abgesagt INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE items ADD COLUMN absage_push_done INTEGER NOT NULL DEFAULT 0");
    }

    // Aufruf-Zähler: der Rückkanal an die Veranstalter – wer sieht, dass sein
    // Beitrag angeschaut wird, trägt das nächste Event wieder ein. Datenschutzfreundlich:
    // reines Hochzählen je Beitrag (v.php, einmal je Sitzung), keine Personenbezüge.
    if ($itemSpalten && !in_array('aufrufe', $itemSpalten, true)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN aufrufe INTEGER NOT NULL DEFAULT 0");
    }

    // Fehlversuche am Veranstalter-Login: Der Zähler hängt am KONTO und ist deshalb nicht
    // wegzuwerfen. Zählte er allein in der SITZUNG, bekäme jemand mit weggelassenem Cookie bei
    // jedem Versuch einen frischen Zähler und könnte Passwörter unbegrenzt durchprobieren.
    if ($orgSpalten && !in_array('fail_n', $orgSpalten, true)) {
        $pdo->exec("ALTER TABLE orgs ADD COLUMN fail_n INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE orgs ADD COLUMN fail_bis TEXT NOT NULL DEFAULT ''");
    }

    // Englische Kurzfassung (optional – die Betreiber schalten sie in den
    // Einstellungen frei). Nur Titel und Teaser: Wer den ganzen Text übersetzt, tut es
    // freiwillig im Beschreibungsfeld; die Kurzfassung ist das, was internationale Studis
    // brauchen, um zu entscheiden, ob sie hingehen.
    if ($itemSpalten && !in_array('title_en', $itemSpalten, true)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN title_en TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE items ADD COLUMN teaser_en TEXT NOT NULL DEFAULT ''");
    }

    // „Nur für Studierende" (Vorgabe): Beim Eintragen muss der Veranstalter
    // sagen, ob der Beitrag allen offensteht. Der Bestand bekommt 0 – das ist keine Annahme,
    // sondern der Stand der Dinge: ohne Einschränkung steht ein Beitrag allen offen.
    if ($itemSpalten && !in_array('nur_studis', $itemSpalten, true)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN nur_studis INTEGER NOT NULL DEFAULT 0");
    }

    // Merker „den Zuständigen schon gesagt". Wie bei push_done gilt bei der
    // Nachrüstung alles Vorhandene als erledigt – sonst bekämen die Referate beim ersten
    // Cron-Lauf den kompletten Altbestand als „neu" um die Ohren.
    if ($itemSpalten && !in_array('staff_done', $itemSpalten, true)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN staff_done INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE items SET staff_done = 1");
    }
    // Dasselbe an den wartenden Änderungen. ACHTUNG: Diese Nachrüstungen gehören hierher und
    // NICHT nach oben zu ihrem CREATE TABLE – der Helfer $spalten wird erst hier definiert.
    $editSpalten = $spalten($pdo, 'item_edits');
    if ($editSpalten && !in_array('staff_done', $editSpalten, true)) {
        $pdo->exec("ALTER TABLE item_edits ADD COLUMN staff_done INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE item_edits SET staff_done = 1");
    }

    /**
     * Bremse, die eine weggeworfene Sitzung überlebt.
     *
     * Der Schlüssel ist ein HASH aus Tages-Salz + Merkmal (Mailadresse oder Adresse des
     * Geräts) – gespeichert wird das Merkmal selbst nie, und mit dem täglichen Salzwechsel
     * ist der Hash am nächsten Tag ohnehin ein anderer. Aufgeräumt wird dabei gleich mit.
     */
    $pdo->exec("CREATE TABLE IF NOT EXISTS throttle (
        k    TEXT PRIMARY KEY,
        n    INTEGER NOT NULL DEFAULT 0,
        bis  TEXT    NOT NULL DEFAULT '',
        day  TEXT    NOT NULL DEFAULT ''
    )");

    // Gemeldete Beiträge: Wer fremde Inhalte öffentlich hostet, braucht einen Weg,
    // auf dem Leute etwas beanstanden können – und der muss in der Verwaltung ankommen, nicht
    // in einem Postfach, in das niemand schaut.
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id    INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
        grund      TEXT    NOT NULL DEFAULT 'sonstiges',
        text       TEXT    NOT NULL DEFAULT '',
        mail       TEXT    NOT NULL DEFAULT '',   -- freiwillig, nur für Rückfragen
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        done_at    TEXT    NOT NULL DEFAULT '',
        notiz      TEXT    NOT NULL DEFAULT '',
        staff_done INTEGER NOT NULL DEFAULT 0   -- wurde den zuständigen Referaten schon gemeldet?
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_reports_offen ON reports(done_at, created_at)');
    $repSpalten = $spalten($pdo, 'reports');
    if ($repSpalten && !in_array('staff_done', $repSpalten, true)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN staff_done INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE reports SET staff_done = 1");
    }

    if (!is_dir(WL_IMG_DIR)) @mkdir(WL_IMG_DIR, 0775, true);
    // Der Bilderordner darf nie direkt aus dem Web erreichbar sein – ausgeliefert wird über bild.php,
    // damit Größe und Typ kontrolliert bleiben und nichts Fremdes danebenliegen kann.
    $ht = WL_IMG_DIR . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");

    return $pdo;
}

function wl_setting(string $k, string $vorgabe = ''): string
{
    try {
        $s = wl_db()->prepare('SELECT v FROM settings WHERE k = ?');
        $s->execute([$k]);
        $v = $s->fetchColumn();
        return $v === false ? $vorgabe : (string)$v;
    } catch (\Throwable $e) { return $vorgabe; }
}

/* --- Träger dieser Installation ---------------------------------------------------------
 * was.läuft gehört einer Studierendenvertretung, und die steht klein unter der Marke.
 * Beides sind Einstellungen, gepflegt unter Verwaltung → Träger; im Programm steht nur ein
 * neutraler Rückfallwert. */

/** Name des Trägers, ausgeschrieben (Auszeichnung für Suchmaschinen, Alternativtexte). */
function wl_traeger_name(): string
{
    return wl_setting('traeger_name', 'Studierendenvertretung');
}

/* --- Der Ort -----------------------------------------------------------------------------
 * „was.läuft" ist kein ortsgebundener Name – die Frage stellt sich überall. Gebunden ist
 * allein der Ort, und der steht in drei Formen in den Einstellungen: schlicht („Landau"),
 * als Eigenschaftswort („Landauer Kulturszene") und ausgeschrieben für die Auszeichnung
 * („Landau in der Pfalz"). Gepflegt unter Verwaltung → Träger.
 */

/** Der Ort, schlicht – „… in Landau?". */
function wl_ort(): string
{
    return wl_setting('ort', '');
}

/** Der Ort als Eigenschaftswort – „die Landauer Kulturszene". Die Regel „Ort + er" trägt
 *  die meisten Namen (Koblenz → Koblenzer); wo nicht, steht der Wert in den Einstellungen. */
function wl_ort_adj(): string
{
    return wl_setting('ort_adj', wl_ort() . 'er');
}

/** Der Ort ausgeschrieben, wie ihn Suchmaschinen erwarten – „Landau in der Pfalz". */
function wl_ort_lang(): string
{
    return wl_setting('ort_lang', wl_ort());
}

/** Trägername in der Kurzform – so führt ihn die Fußzeile und der geteilte Text. */
function wl_traeger_kurz(): string
{
    return wl_setting('traeger_kurz', wl_traeger_name());
}

/** Web-Auftritt des Trägers, ohne Schluss-Schrägstrich. */
function wl_traeger_url(): string
{
    return rtrim(wl_setting('traeger_url', ''), '/');
}

/**
 * Namensraum der Kalender-Kennungen (UID) in den ICS-Dateien. NICHT kosmetisch: Die UID
 * identifiziert einen Termin in jedem abonnierten Kalender. Wird sie geändert, legen die
 * Kalender alle Termine ein ZWEITES Mal an. Deshalb steht hier die Vorgabe dieser
 * Installation fest – wer sie wirklich umstellen will, tut das bewusst in den Einstellungen.
 */
function wl_uid_domain(): string
{
    $ausUrl = (string)parse_url(wl_traeger_url(), PHP_URL_HOST);
    return wl_setting('uid_domain', $ausUrl !== '' ? $ausUrl : 'localhost');
}

/* --- Angaben für Impressum und Datenschutzerklärung ------------------------------------
 * Die Vorgabetexte weiter unten sind gutes, fertiges Material – aber sie nennen eine
 * Körperschaft, eine Anschrift und eine Aufsichtsbehörde. Das ist bei jedem Träger anders
 * und darf deshalb nicht im Programm stehen. Jede dieser Angaben ist eine Einstellung. */

/** Vollständige, rechtliche Bezeichnung des Trägers (für das Impressum). */
function wl_traeger_recht(): string
{
    return wl_setting('traeger_recht', wl_traeger_name());
}

/** Ladungsfähige Anschrift. */
function wl_traeger_anschrift(): string
{
    return wl_setting('traeger_anschrift', '');
}

/** Adresse für rechtliche Anfragen (Impressum, Verantwortliche). */
function wl_kontakt_recht(): string
{
    return wl_setting('kontakt_recht', wl_setting('kontakt_mail', ''));
}

/** Adresse für Datenschutz-Anfragen. */
function wl_kontakt_datenschutz(): string
{
    return wl_setting('kontakt_datenschutz', wl_kontakt_recht());
}

/** Zuständige Datenschutz-Aufsichtsbehörde, ein Satzteil („der Landesbeauftragte …, Ort"). */
function wl_aufsichtsbehoerde(): string
{
    return wl_setting('aufsichtsbehoerde', '');
}

/** Mehrere Angaben in EINE Zeile setzen, mit „ · " getrennt; Leeres fällt weg. */
function wl_recht_einzeiler(array $teile): string
{
    return implode(' · ', array_values(array_filter($teile, fn ($t) => trim((string)$t) !== '')));
}

/** Zeilen zu einem Textblock fügen und dabei leere Angaben auslassen. */
function wl_recht_zeilen(array $zeilen): string
{
    return implode("\n", array_values(array_filter($zeilen, fn ($z) => trim((string)$z) !== '' || $z === '')));
}

function wl_setting_set(string $k, string $v): void
{
    wl_db()->prepare('INSERT INTO settings(k, v) VALUES(?, ?)
        ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

/**
 * Wie wl_setting(), aber mit dem Unterschied, auf den es bei den Texten ankommt:
 * NULL heißt „nie gespeichert" (→ Standard aus dem Register), ein gespeichertes "" dagegen
 * „bewusst geleert" (→ das Element verschwindet von der Seite).
 */
function wl_setting_raw(string $k): ?string
{
    try {
        $s = wl_db()->prepare('SELECT v FROM settings WHERE k = ?');
        $s->execute([$k]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (\Throwable $e) { return null; }
}

/** Zeile löschen statt leeren – danach greift wieder der Standard aus dem Register. */
function wl_setting_delete(string $k): void
{
    wl_db()->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
}

/**
 * Betriebszustand: on = alles offen · soft = nichts Neues einreichbar · off = Seite zu ·
 * pre = Vorabstart (draußen steht „bald geht es los", die Gruppen tragen aber schon ein).
 *
 * „pre" ist bewusst KEIN Unterfall von „off": Dort ist alles zu, hier bleiben Einreichen und
 * Veranstalter-Bereich offen – sonst gäbe es zum Start nichts zu zeigen.
 */
function wl_mode(): string
{
    $m = wl_setting('mode', 'on');
    return in_array($m, ['on', 'soft', 'off', 'pre'], true) ? $m : 'on';
}

/** Geplanter Starttermin (Y-m-d) für den Vorabstart – leer erlaubt, dann ohne Datum. */
function wl_launch_at(): string
{
    $d = trim(wl_setting('launch_at', ''));
    return preg_match('~^\d{4}-\d{2}-\d{2}$~', $d) ? $d : '';
}

/**
 * Uhrzeit des Starts (H:i) – getrennt vom Datum gespeichert, weil sie freiwillig ist: Ein Datum
 * ohne Uhrzeit ist eine gültige Ansage („am 1. Oktober"), eine Uhrzeit ohne Datum nicht.
 */
function wl_launch_time(): string
{
    $t = trim(wl_setting('launch_time', ''));
    return preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $t) ? $t : '';
}

/** Demo-Modus: Die Seite läuft normal, sagt Besuchenden aber, dass sie noch nicht öffentlich ist.
 *  Bewusst NEBEN dem Betriebszustand und nicht als vierte Stufe darin: Der Zustand regelt, was
 *  GEHT (einreichen, überhaupt öffnen), der Demo-Modus nur, was DASTEHT. Beides lässt sich
 *  unabhängig kombinieren – etwa Demo an, Einreichungen zu. */
function wl_demo(): bool
{
    return wl_setting('demo', '0') === '1';
}

/** Öffentliche Basis-Adresse (für Links in Mails und die Linkvorschau). */
function wl_base_url(): string
{
    return rtrim(trim(wl_setting('base_url', '')), '/');
}

// -----------------------------------------------------------------------------------------------
// Registries – EINE Quelle für Kategorien und Veranstalter-Arten
// -----------------------------------------------------------------------------------------------

/**
 * Kategorien. Der Schlüssel steht in der Datenbank, alles andere hängt hier dran:
 * Beschriftung, Symbol und die CSS-Klasse für den farbigen Chip. Neue Kategorie = eine Zeile hier
 * plus eine Farbregel in assets/wl.css; der Selbsttest prüft, dass beides zusammenpasst.
 */
/**
 * Web-Adresse aus einer Eingabe machen. NIEMAND muss „https://www." tippen: „beispiel.de",
 * „www.beispiel.de/x" und „http://…" führen alle zur selben gültigen Adresse; das Schema
 * kommt automatisch davor. Zurück kommt '' , wenn daraus keine Adresse werden kann – und
 * ausdrücklich auch bei „javascript:", „data:" und Konsorten – die haben hier nichts verloren.
 */
function wl_url_norm(string $v, int $max = 300): string
{
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('~^https?://~i', $v)) {
        $rest = preg_replace('~^https?://~i', '', $v);
    } elseif (preg_match('~^[a-z][a-z0-9+.-]*:~i', $v)) {
        return '';                                   // fremdes Schema (javascript:, data:, mailto:…)
    } else {
        $rest = $v;
    }
    $rest = ltrim($rest, '/');
    // Vor dem ersten „/" muss ein Hostname stehen: mindestens ein Punkt, keine Leerzeichen.
    $host = strtok($rest, '/');
    if ($host === false || $host === '' || !preg_match('~^[A-Za-z0-9._-]+(:\d+)?$~', $host) || !str_contains($host, '.')) {
        return '';
    }
    $schema = preg_match('~^http://~i', $v) ? 'http://' : 'https://';
    return mb_substr($schema . $rest, 0, $max);
}

/**
 * Dieselbe Adresse zum ANZEIGEN und Aufdrucken: ohne Schema, ohne „www.", ohne Schluss-Schrägstrich.
 * „https://www.beispiel.de/was.laeuft/" wird zu „beispiel.de/was.laeuft" – das ist es, was
 * auf ein Plakat gehört und was Menschen abtippen.
 */
function wl_url_kurz(string $v): string
{
    $v = preg_replace('~^https?://~i', '', trim($v));
    $v = preg_replace('~^www\.~i', '', (string)$v);
    return rtrim((string)$v, '/');
}

/**
 * Die SCHÖNE öffentliche Adresse fürs Teilen und Drucken. Die Kurz-Weiterleitung
 * (RedirectMatch in der WP-.htaccess, live) reicht Unterpfade und
 * Parameter durch – Links wie …/was.laeuft/v.php?id=5 landen also richtig.
 * Die lange Adresse bleibt kanonisch und funktioniert immer.
 */
function wl_share_base(): string
{
    // Einstellung, weil die Weiterleitung bei jedem Träger anders heißt; die Vorgabe ist die
    // Adresse dieser Installation. Leer eingetragen ist erlaubt – dann fällt alles auf die
    // lange, kanonische Adresse zurück, die es ohnehin immer gibt.
    $kurz = trim(wl_setting('share_base', ''));
    return $kurz !== '' ? rtrim($kurz, '/') : rtrim(wl_base_url(), '/') . '/veranstaltungen';
}

function wl_cats(): array
{
    return [
        'party'    => ['label' => 'Party',     'en' => 'Party',            'icon' => '🎉', 'css' => 'c-party'],
        'kultur'   => ['label' => 'Kultur',    'en' => 'Arts & culture',   'icon' => '🎭', 'css' => 'c-kultur'],
        'sport'    => ['label' => 'Sport',     'en' => 'Sports',           'icon' => '🏐', 'css' => 'c-sport'],
        'bildung'  => ['label' => 'Bildung',   'en' => 'Learning',         'icon' => '📚', 'css' => 'c-bildung'],
        'ehrenamt' => ['label' => 'Ehrenamt',  'en' => 'Volunteering',     'icon' => '🤝', 'css' => 'c-ehrenamt'],
        'markt'    => ['label' => 'Markt & Tausch', 'en' => 'Market & swap','icon' => '🛍️', 'css' => 'c-markt'],
        'sonstiges' => ['label' => 'Sonstiges', 'en' => 'Other',           'icon' => '✨', 'css' => 'c-sonstiges'],
    ];
}

function wl_cat_label(string $key): string
{
    $c = wl_cats()[$key] ?? wl_cats()['sonstiges'];
    if (wl_ist_en() && trim((string)($c['en'] ?? '')) !== '') return (string)$c['en'];
    return (string)$c['label'];
}

function wl_cat_css(string $key): string
{
    return (string)(wl_cats()[$key]['css'] ?? 'c-sonstiges');
}

/* Die Wortmarke trägt fest den Verlauf Gelb → Koralle (assets/wl.css); umschaltbare Stile
   gibt es nicht. Eine `logo_stil`-Zeile im Bestand der Einstellungen darf liegenbleiben. */

/**
 * Bereiche, in die die Verwaltung die Texte gruppiert (Reihenfolge = Reihenfolge im Formular).
 */
function wl_text_groups(): array
{
    return [
        'start'   => ['label' => 'Startseite',                    'icon' => 'ti-home'],
        'kurse'   => ['label' => 'Kurse-Seite',                   'icon' => 'ti-repeat'],
        'zugang'  => ['label' => 'Eintragen – vor der Anmeldung', 'icon' => 'ti-door'],
        'formular' => ['label' => 'Eintragen – das Formular',     'icon' => 'ti-forms'],
        'ueber'   => ['label' => 'Über-Seite',                    'icon' => 'ti-info-circle'],
        'fuss'    => ['label' => 'Fußzeile',                      'icon' => 'ti-layout-bottombar'],
        'banner'  => ['label' => 'Banner für fremde Websites',    'icon' => 'ti-layout-navbar'],
        'recht'   => ['label' => 'Rechtliches',                   'icon' => 'ti-scale'],
    ];
}

/**
 * REGISTER DER TEXTE der öffentlichen Seiten – dasselbe Muster wie beim Pat:innenprogramm:
 * Die öffentlichen Seiten lesen die Werte, die Verwaltung rendert ihr Formular komplett aus
 * diesem Register (Speichern und Zurücksetzen ebenfalls). Ein neuer Text ist eine Zeile hier.
 *
 * Bewusst NICHT hier drin: die Marken-Sätze der beiden Überschriften („was.läuft … in Landau?")
 * – sie sind mit Rad-Animation und Zeilenumbruch-Logik verwachsen, ein frei getippter Satz
 * zerlegte beides. Ebenso alles, was Rückmeldung auf eine Eingabe ist (Fehlertexte).
 *
 * type: 'line' = einzeilig · 'text' = mehrere Absätze (Leerzeile = neuer Absatz)
 * Ein in der Verwaltung GESPEICHERTES leeres Feld blendet das Element aus; erst „Auf Standard
 * zurücksetzen" holt den Standard zurück (wl_setting_raw() unterscheidet die beiden Fälle).
 */
function wl_text_fields(): array
{
    return [
        // ---- Startseite ------------------------------------------------------------------
        'start_lead' => ['group' => 'start', 'type' => 'text', 'label' => 'Satz unter der Überschrift',
            'default' => 'Partys, Vorträge, Sport und Kurse — gesammelt von Hochschulgruppen, '
                . 'Fachschaften und dem AStA. Kostenlos, ohne Konto.'],
        'start_suche' => ['group' => 'start', 'type' => 'line', 'label' => 'Platzhalter im Suchfeld',
            'hint' => 'Der graue Beispieltext, solange nichts eingetippt ist.',
            'default' => '„Sprachcafé", „Volleyball", „Chor" …'],
        'start_naechstes' => ['group' => 'start', 'type' => 'line', 'label' => 'Überschrift der Terminliste',
            'default' => 'Als Nächstes'],
        'start_treffer' => ['group' => 'start', 'type' => 'line', 'label' => 'Überschrift bei aktivem Filter',
            'default' => 'Treffer'],
        'start_kurse_titel' => ['group' => 'start', 'type' => 'line', 'label' => 'Überschrift der Kurs-Leiste',
            'default' => 'Kurse & regelmäßige Angebote'],
        'start_leer' => ['group' => 'start', 'type' => 'text', 'label' => 'Wenn gar nichts eingetragen ist',
            'default' => 'Gerade ist nichts eingetragen. Schau später noch einmal vorbei – '
                . 'oder trag selbst etwas ein.'],
        'start_leer_filter' => ['group' => 'start', 'type' => 'text', 'label' => 'Wenn der Filter nichts findet',
            'default' => 'Dazu ist gerade nichts eingetragen. Versuch es mit weniger Filtern.'],
        'start_titel' => ['group' => 'start', 'type' => 'line', 'label' => 'Seitentitel für Suchmaschinen',
            'hint' => 'Steht im Browser-Tab und als Überschrift im Suchergebnis. „· was.läuft" hängt die Seite selbst an.',
            'default' => 'Veranstaltungen für Studierende in ' . wl_ort()],
        'start_meta' => ['group' => 'start', 'type' => 'text', 'label' => 'Beschreibung für Suchmaschinen und Linkvorschau',
            'hint' => 'Steht nicht auf der Seite selbst – Suchmaschinen zeigen sie unter dem Treffer, '
                . 'Messenger unter dem geteilten Link.',
            'default' => 'Partys, Vorträge, Sport und Kurse für Studierende in ' . wl_ort() . ' – gesammelt von '
                . 'Hochschulgruppen, Fachschaften und dem AStA. Kostenlos, ohne Konto.'],

        // ---- Kurse-Seite -----------------------------------------------------------------
        'kurse_lead' => ['group' => 'kurse', 'type' => 'text', 'label' => 'Satz unter der Überschrift',
            'default' => 'Sprachkurse, Chöre, Sport, Werkstätten — Angebote, die über Wochen laufen '
                . 'und bei denen man einfach mitmachen kann.'],
        'kurse_suche' => ['group' => 'kurse', 'type' => 'line', 'label' => 'Platzhalter im Suchfeld',
            'default' => 'Suchen — „Gebärdensprache", „Töpfern" …'],
        'kurse_leer' => ['group' => 'kurse', 'type' => 'text', 'label' => 'Wenn nichts gefunden wird',
            'default' => 'Dazu ist gerade nichts eingetragen.'],
        'kurse_meta' => ['group' => 'kurse', 'type' => 'text', 'label' => 'Beschreibung für Suchmaschinen und Linkvorschau',
            'default' => 'Sprachkurse, Chöre, Sport und andere regelmäßige Angebote für Studierende in ' . wl_ort() . '.'],

        // ---- Eintragen: die Seite vor der Anmeldung ---------------------------------------
        'zugang_lead' => ['group' => 'zugang', 'type' => 'text', 'label' => 'Erklärung über der Anmeldung',
            'default' => 'was.läuft wird von Hochschulgruppen, Fachschaften, dem AStA und Partnern wie '
                . 'Kulturzentren gefüllt — jede Gruppe mit eigenem Zugang.'],
        'anfrage_hint' => ['group' => 'zugang', 'type' => 'text', 'label' => 'Einladung unter „Noch keinen Zugang? Dabei sein!"',
            'default' => 'Hochschulgruppe, Fachschaft, Verein, Kulturort — sagt uns, wer ihr seid, '
                . 'dann richten wir euch einen Zugang ein. Einmal einrichten, immer eintragen.'],
        'anfrage_ok' => ['group' => 'zugang', 'type' => 'text', 'label' => 'Antwort nach abgeschickter Zugangsanfrage',
            'default' => 'Danke! Wir richten euch ein und melden uns an eurer Mailadresse — '
                . 'meistens innerhalb von ein bis zwei Werktagen.'],

        // ---- Eintragen: das Formular für angemeldete Gruppen ------------------------------
        'formular_lead' => ['group' => 'formular', 'type' => 'text', 'label' => 'Satz über dem Formular',
            'default' => 'Alles mit * brauchen wir. Der Rest hilft, ist aber freiwillig. '
                . 'Nach dem Abschicken schaut der AStA drüber, dann geht es online.'],
        'danke_wartend' => ['group' => 'formular', 'type' => 'text', 'label' => 'Nach dem Einreichen: was jetzt passiert',
            'hint' => '{{MAIL}} wird zur hinterlegten Mailadresse der Gruppe.',
            'default' => 'Das dauert meistens ein bis zwei Werktage. Wenn etwas nicht passt, '
                . 'meldet sich jemand bei euch unter {{MAIL}}.'],

        // ---- Über-Seite -------------------------------------------------------------------
        // Die Struktur der Seite (Riesen-Marke, Partner-Kacheln, Zahlen) steht im Code –
        // hier stehen nur die frei formulierten Sätze dazwischen. Leeren blendet aus.
        'ueber_meta' => ['group' => 'ueber', 'type' => 'line', 'label' => 'Beschreibung für Suchmaschinen',
            'default' => 'Was hinter was.läuft steckt: ein Angebot des ' . wl_traeger_kurz() . ' – gemeinsam '
                . 'mit Kultur, Stadt, Hochschulgruppen, Studierendenwerk und Fachschaften.'],
        'ueber_badge' => ['group' => 'ueber', 'type' => 'line', 'label' => 'Zeile über der großen Marke',
            'default' => 'Vom ' . wl_traeger_name()],
        'ueber_lead' => ['group' => 'ueber', 'type' => 'text', 'label' => 'Satz unter der großen Marke',
            'default' => 'Ein Kalender für alles, was in ' . wl_ort() . ' läuft: Partys, Kultur, Sport, Vorträge '
                . 'und Kurse – gesammelt an einem Ort, kostenlos und ohne Konto.'],
        'ueber_asta' => ['group' => 'ueber', 'type' => 'text', 'label' => 'Abschnitt „Von Studis, für Studis"',
            'default' => 'was.läuft ist ein Angebot des ' . wl_traeger_recht()
                . ' – von Studis, für Studis. Wir bauen und betreiben die Seite selbst: '
                . 'ohne Werbung, ohne Tracking, ohne Kontozwang.' . "\n\n"
                . 'Warum? Weil in ' . wl_ort() . ' mehr läuft, als man mitbekommt. Das Angebot war immer da – '
                . 'es stand nur nie an einem Ort.'],
        'ueber_partner' => ['group' => 'ueber', 'type' => 'text', 'label' => 'Einleitung über den Partner-Kacheln',
            'default' => 'Ein Kalender ist nur so gut wie das, was drinsteht. Deshalb holen wir alle '
                . 'an einen Tisch, bei denen in ' . wl_ort() . ' etwas läuft:'],
        'ueber_mitmachen' => ['group' => 'ueber', 'type' => 'text', 'label' => 'Abschnitt „Mitmachen" ganz unten',
            'default' => 'Du veranstaltest selbst etwas – als Gruppe, Fachschaft, Einrichtung oder '
                . 'Initiative? Trag es ein, wir kümmern uns um den Rest.'],

        // ---- Banner für fremde Websites ---------------------------------------------------
        // Was das eingesetzte HTML-Stück auf der Seite des Trägers sagt. Es fragt die Texte hier ab
        // (banner.php), deshalb wirkt jede Änderung dort, ohne die fremde Seite anzufassen.
        'banner_titel' => ['group' => 'banner', 'type' => 'line', 'label' => 'Überschrift',
            'hint' => 'Leer = das Banner bleibt aus, auch wenn der Schalter an ist.',
            'default' => 'Was läuft in ' . wl_ort() . '?'],
        'banner_satz' => ['group' => 'banner', 'type' => 'text', 'label' => 'Satz darunter',
            'default' => 'Partys, Vorträge, Sport und Kurse – gesammelt an einem Ort. Kostenlos, ohne Konto.'],
        'banner_chips' => ['group' => 'banner', 'type' => 'line', 'label' => 'Stichpunkte',
            'hint' => 'Mit Komma trennen. Erscheinen als kleine Pillen unter dem Satz – leer lassen, dann fallen sie weg.',
            'default' => 'Kostenlos, Ohne Konto, Von Gruppen für Studis, Immer aktuell'],
        'banner_knopf' => ['group' => 'banner', 'type' => 'line', 'label' => 'Beschriftung des Knopfes',
            'default' => 'Jetzt anschauen'],

        // ---- Rechtliches ------------------------------------------------------------------
        // Eigene, KURZE Fassungen für was.läuft: Die Seite tut weniger als die Hauptseite des
        // AStA, also darf hier auch weniger stehen. Leer lassen heißt „keine eigene Seite" –
        // dann führen die Verweise auf die Adressen aus den Einstellungen (imprint_url,
        // privacy_url), und es entsteht keine zweite Wahrheit.
        // Umbrüche über implode: In einfachen Anführungszeichen wäre \n zweimal ein Zeichen
        // und kein Absatz.
        'recht_impressum' => ['group' => 'recht', 'type' => 'text', 'label' => 'Impressum (eigene Fassung)',
            'hint' => 'Leer = kein eigenes Impressum; dann gilt die Adresse aus den Einstellungen. '
                . 'Eine Zeile, die nur aus einer Adresse besteht, wird auf der Seite zum Link.',
            'default' => wl_recht_zeilen([
                'Anbieter dieser Seite',
                wl_traeger_recht(),
                wl_traeger_anschrift(),
                '',
                'Vertretung',
                'Vertreten wird der Träger durch seinen Vorsitz. Wer das aktuell ist, steht in seinem vollständigen Impressum.',
                '',
                'Kontakt',
                wl_kontakt_recht(),
                '',
                'Verantwortlich für den Inhalt',
                'Das Referat für PR und Öffentlichkeitsarbeit des ' . wl_traeger_kurz() . '.',
                '',
                'Zu den Veranstaltungen',
                'Die einzelnen Beiträge stammen von Hochschulgruppen, Fachschaften und dem Träger selbst. Für Ort, Zeit, Preis und Inhalt einer Veranstaltung ist die Gruppe verantwortlich, die sie eingetragen hat. Wir prüfen vor der Freigabe, ob ein Beitrag hierher passt – nicht, ob jede Angabe stimmt. Fällt dir ein Fehler auf, schreib uns.',
                '',
                'Haftung für Links',
                'Beiträge verweisen teils auf Seiten der Gruppen. Auf deren Inhalte haben wir keinen Einfluss und übernehmen dafür keine Gewähr. Verantwortlich ist jeweils die Betreiberin oder der Betreiber der verlinkten Seite.',
                '',
                'Vollständiges Impressum',
                wl_setting('imprint_url', ''),
            ])],
        'recht_datenschutz' => ['group' => 'recht', 'type' => 'text', 'label' => 'Datenschutzerklärung (eigene Fassung)',
            'hint' => 'Leer = keine eigene Erklärung; dann gilt die Adresse aus den Einstellungen. '
                . 'Sie muss beschreiben, was die Seite WIRKLICH tut – bei Änderungen an der Technik mitziehen.',
            'default' => wl_recht_zeilen([
                'Kurz gesagt',
                'was.läuft kommt ohne Nutzerkonto, ohne Werbung und ohne Analyse-Dienste aus. Wer nur schaut, hinterlässt nichts, woraus sich eine Person erkennen ließe.',
                '',
                'Verantwortlich',
                wl_recht_einzeiler([wl_traeger_recht(), wl_traeger_anschrift()]),
                wl_recht_einzeiler([wl_kontakt_recht(), wl_kontakt_datenschutz() !== wl_kontakt_recht()
                    ? 'Fragen zum Datenschutz: ' . wl_kontakt_datenschutz() : '']),
                '',
                'Beim Aufruf der Seite',
                'Unser Hoster speichert wie üblich Server-Protokolle (IP-Adresse, Zeitpunkt, aufgerufene Adresse, Browser). Das ist technisch nötig, um die Seite auszuliefern und Angriffe zu erkennen. Rechtsgrundlage ist unser berechtigtes Interesse an einem sicheren Betrieb (Art. 6 Abs. 1 lit. f DSGVO).',
                '',
                'Cookie',
                'Wir setzen ein einziges Cookie („asta_wl_sid"). Es hält die Sitzung zusammen, sichert Formulare gegen Missbrauch und merkt sich die gewählte Sprache. Es ist technisch notwendig, enthält keine Kennung zu deiner Person und wird beim Schließen des Browsers ungültig.',
                '',
                'Besuchszahlen',
                'Wir zählen, wie viele Menschen die Seite an einem Tag benutzt haben. Dafür bilden wir aus deinem Zugriff eine Prüfsumme, die sich täglich ändert und nicht zurückgerechnet werden kann. Gespeichert werden nur diese Prüfsumme und Tageszahlen – keine IP-Adresse, kein Cookie, kein Verlauf, keine Profile.',
                '',
                'Erinnerungen aufs Handy',
                'Wenn du dich an eine Veranstaltung erinnern lässt oder eine Gruppe abonnierst, speichern wir die Adresse, unter der dein Gerät Benachrichtigungen empfängt, samt zugehöriger Schlüssel. Das passiert nur auf deine ausdrückliche Erlaubnis hin (Art. 6 Abs. 1 lit. a DSGVO). Du kannst sie jederzeit widerrufen: abbestellen in der Seite oder die Erlaubnis im Browser entziehen – dann löschen wir den Eintrag.',
                '',
                'Wenn ihr als Gruppe eintragt',
                'Für den Zugang speichern wir Name und Mailadresse der Gruppe sowie eure Beiträge samt Bildern. Grundlage ist die Erfüllung genau dieser Aufgabe (Art. 6 Abs. 1 lit. b und lit. e DSGVO). Vergangene Veranstaltungen räumt die Seite nach der eingestellten Frist von selbst weg.',
                '',
                'Was wir nicht tun',
                'Keine Analyse- oder Werbedienste, keine Schriften oder Skripte von fremden Servern, keine Weitergabe an Dritte, keine Verarbeitung außerhalb der EU. Was die Seite lädt, liegt auf demselben Server wie sie selbst.',
                '',
                'Deine Rechte',
                'Du hast das Recht auf Auskunft, Berichtigung, Löschung und Einschränkung der Verarbeitung (Art. 15 bis 18 DSGVO), auf Datenübertragbarkeit (Art. 20 DSGVO) und auf Widerspruch (Art. 21 DSGVO). Eine erteilte Einwilligung kannst du jederzeit für die Zukunft widerrufen (Art. 7 Abs. 3 DSGVO). Melde dich dafür bei ' . wl_kontakt_datenschutz() . '.',
                '',
                'Beschwerde',
                wl_aufsichtsbehoerde() !== ''
                    ? 'Du hast gemäß Art. 77 DSGVO das Recht, dich bei einer Aufsichtsbehörde zu beschweren. Zuständig ist ' . wl_aufsichtsbehoerde() . '.'
                    : 'Du hast gemäß Art. 77 DSGVO das Recht, dich bei der für uns zuständigen Datenschutz-Aufsichtsbehörde zu beschweren.',
                '',
                'Vollständige Datenschutzerklärung',
                wl_setting('privacy_url', ''),
            ])],

        // ---- Fußzeile ---------------------------------------------------------------------
        'fuss_text' => ['group' => 'fuss', 'type' => 'text', 'label' => 'Text unter der Marke',
            'default' => 'Der Veranstaltungskalender für Studierende in ' . wl_ort() . '. Zusammengestellt vom '
                . wl_traeger_kurz() . ' gemeinsam mit Hochschulgruppen und Fachschaften.'],
    ];
}

/**
 * Text aus der Verwaltung, sonst der Standard aus dem Register.
 * Gespeichertes Leerfeld bleibt leer (Element weglassen) – siehe wl_setting_raw().
 */
function wl_text(string $key): string
{
    $raw = wl_setting_raw('text_' . $key);
    if ($raw !== null) return trim($raw);
    return trim((string)(wl_text_fields()[$key]['default'] ?? ''));
}

/**
 * ---------------------------------------------------------------------------------------------
 * Sprache der öffentlichen Seite
 * ---------------------------------------------------------------------------------------------
 * Englisch bekommt NUR die feste Oberfläche: Navigation, Filter, Kategorien, Fakten an den
 * Kacheln, Knöpfe, Leer-Meldungen – und das Datum, weil „Do 7. Aug" für internationale Studis
 * schlicht nichts aussagt. Alles, was der AStA selbst schreibt (wl_text_fields), und alles,
 * was die Gruppen eintragen, bleibt deutsch: Eine maschinelle Übersetzung davon wäre schlechter
 * als gar keine. Für die Beiträge gibt es die freiwillige englische Kurzfassung (wl_en_an()).
 *
 * WICHTIG – die Sprache gilt ausschließlich im ÖFFENTLICHEN Bereich: wl-lib.php setzt dafür
 * $GLOBALS['wl_public']. Ohne diese Marke antwortet wl_lang() immer 'de'. Sonst bekäme die
 * Verwaltung in der App englische Kategorie-Namen (die Datei hier wird dort mitgeladen), und
 * der Cron schriebe englische Push-Texte an deutsche Abonnenten.
 */
function wl_lang(): string
{
    if (empty($GLOBALS['wl_public'])) return 'de';
    // Selbst gewählt schlägt Browser – sonst kämen deutsche Studis mit englischem Handy
    // nie wieder auf Deutsch zurück.
    $gewaehlt = (string)($_SESSION['wl_lang'] ?? '');
    if ($gewaehlt === 'de' || $gewaehlt === 'en') return $gewaehlt;
    $al = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($al === '') return 'de';
    return str_starts_with(trim(explode(',', $al)[0]), 'en') ? 'en' : 'de';
}

function wl_ist_en(): bool { return wl_lang() === 'en'; }

/**
 * Die feste Oberfläche in beiden Sprachen. EIN Register – wer eine Zeichenkette ändert, ändert
 * sie hier und nirgends sonst. Fehlt ein Schlüssel, kommt der Schlüssel selbst zurück (das
 * fällt auf, statt still zu verschwinden); fehlt nur die englische Fassung, gilt die deutsche.
 */
function wl_texte(): array
{
    return [
        // ---- Navigation und Fußzeile ----
        'nav_ueber'      => ['de' => 'Über',                  'en' => 'About'],
        'nav_events'     => ['de' => 'Veranstaltungen',       'en' => 'Events'],
        'nav_kurse'      => ['de' => 'Kurse',                 'en' => 'Courses'],
        'nav_eintragen'  => ['de' => '+ Eintragen',           'en' => '+ Add event'],
        'f_mitmachen'    => ['de' => 'Mitmachen',             'en' => 'Get involved'],
        'f_demnaechst'   => ['de' => 'Was demnächst läuft',   'en' => 'What is coming up'],
        'f_kurse'        => ['de' => 'Kurse & Angebote',      'en' => 'Courses & offers'],
        'f_veranstalter' => ['de' => 'Veranstalter',          'en' => 'Organisers'],
        'f_abos'         => ['de' => 'Erinnerungen & Abos',   'en' => 'Reminders & subscriptions'],
        'f_app'          => ['de' => 'Die was.läuft-App',     'en' => 'The was.läuft app'],
        'f_ueber'        => ['de' => 'Was ist was.läuft',     'en' => 'What is was.läuft'],
        'f_recht'        => ['de' => 'Rechtliches',           'en' => 'Legal'],
        'f_impressum'    => ['de' => 'Impressum',             'en' => 'Imprint'],
        'f_datenschutz'  => ['de' => 'Datenschutz',           'en' => 'Privacy'],
        'recht_fehlt'    => ['de' => 'Für diese Seite ist noch kein Text hinterlegt.',
                             'en' => 'No text has been entered for this page yet.'],
        'f_kontakt'      => ['de' => 'Kontakt',               'en' => 'Contact'],
        'sprache'        => ['de' => 'Sprache',               'en' => 'Language'],
        'nur_deutsch'    => ['de' => '',                      'en' => 'Listings are written by the organisers and are mostly in German.'],

        // ---- Zeitraum und Filter ----
        'zeit_heute'     => ['de' => 'Heute',                 'en' => 'Today'],
        'zeit_woche'     => ['de' => 'Diese Woche',           'en' => 'This week'],
        'zeit_alle'      => ['de' => 'Demnächst',             'en' => 'Coming up'],
        'zeit_we'        => ['de' => 'Wochenende',            'en' => 'This weekend'],
        'zeit_monat'     => ['de' => 'Diesen Monat',          'en' => 'This month'],
        'zuruecksetzen'  => ['de' => 'Alles zurücksetzen',    'en' => 'Reset all'],
        'filter_weg'     => ['de' => 'Filter zurücksetzen',   'en' => 'Clear filters'],
        'suchen_events'  => ['de' => 'Veranstaltungen durchsuchen', 'en' => 'Search events'],
        'suchen_kurse'   => ['de' => 'Kurse durchsuchen',     'en' => 'Search courses'],
        'app_install'    => ['de' => 'App installieren',      'en' => 'Install app'],
        'kategorie'      => ['de' => 'Kategorie',             'en' => 'Category'],
        'filter_frei'    => ['de' => 'Kostenlos',             'en' => 'Free'],
        'kostenlos'      => ['de' => 'kostenlos',             'en' => 'free'],
        'kurs_marke'     => ['de' => 'KURS',                  'en' => 'COURSE'],
        'empfohlen'      => ['de' => '★ Empfohlen',           'en' => '★ Featured'],
        'abgesagt_kurz'  => ['de' => 'Abgesagt',              'en' => 'Cancelled'],
        'filter_bfrei'   => ['de' => 'Barrierefrei',          'en' => 'Accessible'],
        'filter_offen'   => ['de' => 'Einstieg jederzeit',    'en' => 'Join anytime'],
        'filter_alle'    => ['de' => 'Alle',                  'en' => 'All'],
        'suche'          => ['de' => 'Suchen',                'en' => 'Search'],
        'suche_platz'    => ['de' => 'Suchen …',              'en' => 'Search …'],

        // ---- Fakten an Kachel und Detailseite ----
        'frei'           => ['de' => 'Eintritt frei',         'en' => 'Free entry'],
        'barrierefrei'   => ['de' => 'barrierefrei',          'en' => 'accessible'],
        'ab_alter'       => ['de' => 'ab %d',                 'en' => '%d+'],
        'offenes_ende'   => ['de' => 'offenes Ende',          'en' => 'open end'],
        'einstieg'       => ['de' => 'Einstieg jederzeit',    'en' => 'join anytime'],
        'termine_n'      => ['de' => '%d Termine',            'en' => '%d dates'],
        'ab_datum'       => ['de' => 'ab',                    'en' => 'from'],
        'bis_datum'      => ['de' => 'bis',                   'en' => 'until'],
        'studi_rabatt'   => ['de' => 'Studi-Rabatt',          'en' => 'student discount'],
        'nur_studis'     => ['de' => 'Nur für Studierende',   'en' => 'Students only'],
        'nur_studis_hint' => ['de' => 'Ohne Studiausweis kein Einlass.',
                              'en' => 'You need a valid student ID to attend.'],

        // ---- Zustände ----
        'vorab_titel'    => ['de' => 'Bald geht es los',      'en' => 'Coming soon'],
        'vorab_text'     => ['de' => 'was.läuft sammelt gerade seine ersten Veranstaltungen. Ab dann steht hier, was in Landau los ist – von Partys über Vorträge bis zu Kursen.',
                             'en' => 'was.läuft is collecting its first events right now. From then on this is where you will find what is going on in Landau – parties, talks, courses and more.'],
        'vorab_start'    => ['de' => 'Start',                 'en' => 'Launch'],
        'vorab_heute'    => ['de' => 'Heute geht es los!',    'en' => 'Launching today!'],
        'vorab_morgen'   => ['de' => 'Morgen geht es los!',   'en' => 'Launching tomorrow!'],
        'vorab_tage'     => ['de' => 'Noch %d Tage',          'en' => '%d days to go'],
        'vorab_tage_wort' => ['de' => 'Tage bis zum Start',  'en' => 'days to go'],
        'vorab_tag_1'    => ['de' => 'Tag bis zum Start',    'en' => 'day to go'],
        'vorab_std_wort' => ['de' => 'Stunden bis zum Start', 'en' => 'hours to go'],
        'vorab_std_1'    => ['de' => 'Stunde bis zum Start',  'en' => 'hour to go'],
        'vorab_min_wort' => ['de' => 'Minuten bis zum Start', 'en' => 'minutes to go'],
        'vorab_min_1'    => ['de' => 'Minute bis zum Start',  'en' => 'minute to go'],
        'vorab_uhr'      => ['de' => 'Uhr',                   'en' => ''],
        'vorab_eintragen' => ['de' => 'Ihr seid Veranstalter? Zum Login',
                              'en' => 'An organiser? Log in'],
        'vorab_asta'     => ['de' => 'Ein Angebot des ' . wl_traeger_name(),
                             'en' => 'Brought to you by ' . wl_traeger_kurz()],
        'zu_titel'       => ['de' => 'Gerade geschlossen',    'en' => 'Closed for now'],
        'zu_text'        => ['de' => 'was.läuft macht kurz Pause – die Seite ist vorübergehend abgeschaltet. Schau später noch einmal vorbei, dann steht hier wieder, was in Landau los ist.',
                             'en' => 'was.läuft is taking a short break – the site is temporarily switched off. Come back later and you will find out what is going on in Landau again.'],
        'zu_kontakt'     => ['de' => 'Du hast Fragen? Schreib uns',
                             'en' => 'Questions? Write to us'],
        'intern_titel'   => ['de' => 'Nur für euch sichtbar:', 'en' => 'Visible to you only:'],
        'intern_pre'     => ['de' => 'Das Portal ist noch nicht gestartet – ihr seht es, weil ihr angemeldet seid. Wer eure Links anklickt, landet solange auf der Countdown-Seite.',
                             'en' => 'The site has not launched yet – you can see it because you are logged in. Anyone else following your links will get the countdown page for now.'],
        'intern_off'     => ['de' => 'Das Portal ist gerade geschlossen – ihr seht es, weil ihr angemeldet seid. Alle anderen sehen den Pausen-Hinweis.',
                             'en' => 'The site is closed right now – you can see it because you are logged in. Everyone else sees the break notice.'],
        'intern_ab'      => ['de' => 'Öffentlich ab {datum}.', 'en' => 'Public from {datum}.'],
        'intern_ab_uhr'  => ['de' => 'Öffentlich ab {datum}, {zeit} Uhr.',
                             'en' => 'Public from {datum}, {zeit}.'],
        'demo'           => ['de' => 'Demo-Ansicht:',         'en' => 'Preview:'],
        'demo_text'      => ['de' => 'Diese Seite ist noch im Aufbau und noch nicht öffentlich. Schau dich gern um – Angaben können sich noch ändern.',
                             'en' => 'This site is still being built and is not public yet. Feel free to look around – details may still change.'],
        'abgesagt'       => ['de' => 'Abgesagt:',             'en' => 'Cancelled:'],
        'abgesagt_text'  => ['de' => 'Diese Veranstaltung findet nicht statt – der Veranstalter hat sie abgesagt.',
                             'en' => 'This event is not taking place – the organiser has cancelled it.'],
        'vorbei_event'   => ['de' => 'Diese Veranstaltung ist vorbei.',
                             'en' => 'This event is over.'],
        'vorbei_kurs'    => ['de' => 'Diese Reihe ist vorbei.',
                             'en' => 'This series is over.'],
        'leer'           => ['de' => 'Gerade ist nichts eingetragen.',
                             'en' => 'Nothing listed at the moment.'],
        'leer_filter'    => ['de' => 'Dazu ist gerade nichts eingetragen.',
                             'en' => 'Nothing matches that right now.'],

        // ---- Knöpfe ----
        'btn_zurueck'    => ['de' => '← Zurück',              'en' => '← Back'],
        'btn_uebersicht' => ['de' => 'Zur Übersicht',         'en' => 'To the overview'],
        'btn_kalender'   => ['de' => 'In den Kalender',       'en' => 'Add to calendar'],
        'btn_erinnern'   => ['de' => 'Erinnere mich',         'en' => 'Remind me'],
        'btn_teilen'     => ['de' => 'Teilen',                'en' => 'Share'],
        'btn_mehr'       => ['de' => 'Mehr dazu',             'en' => 'More'],
        'btn_anmeldung'  => ['de' => 'Zur Anmeldung',         'en' => 'Sign up'],
        'btn_infos'      => ['de' => 'Mehr Infos',            'en' => 'More info'],
        'btn_erinn_aus'  => ['de' => 'Erinnerung aus',        'en' => 'Reminder off'],
        'nicht_gefunden' => ['de' => 'Das gibt es hier nicht','en' => 'Nothing here'],
        'nicht_gef_text' => ['de' => 'Vielleicht ist die Veranstaltung vorbei oder der Link stimmt nicht.',
                             'en' => 'The event may be over, or the link is wrong.'],

        // ---- Melden ----
        'melden_auf'     => ['de' => 'Stimmt etwas nicht? Beitrag melden',
                             'en' => 'Something wrong? Report this listing'],
        'melden_was'     => ['de' => 'Worum geht es?',        'en' => 'What is it about?'],
        'melden_genau'   => ['de' => 'Was genau?',            'en' => 'What exactly?'],
        'melden_mail'    => ['de' => 'Eure Mailadresse',      'en' => 'Your email address'],
        'freiwillig'     => ['de' => '(freiwillig)',          'en' => '(optional)'],
        'melden_mail_h'  => ['de' => '(freiwillig, nur für Rückfragen)',
                             'en' => '(optional, only for follow-up questions)'],
        'melden_btn'     => ['de' => 'Melden',                'en' => 'Report'],
        'melden_danke'   => ['de' => 'Danke.',                'en' => 'Thank you.'],
        'melden_danke_t' => ['de' => 'Eure Meldung liegt jetzt beim AStA. Wenn ihr eine Mailadresse hinterlassen habt, meldet sich jemand bei Rückfragen.',
                             'en' => 'Your report has reached the AStA. If you left an email address, someone will get in touch if there are questions.'],
    ];
}

/** Eine Zeichenkette der festen Oberfläche in der aktuellen Sprache. */
function wl_t(string $key): string
{
    $e = wl_texte()[$key] ?? null;
    if ($e === null) return $key;                       // Tippfehler sollen auffallen
    $v = (string)($e[wl_lang()] ?? '');
    return $v !== '' ? $v : (string)$e['de'];
}

/** Arten von Veranstaltern – bestimmt die Farbe des Punkts an der Kachel. */
function wl_org_arten(): array
{
    return [
        'asta'       => ['label' => 'AStA',           'css' => 'o-asta'],
        'hsg'        => ['label' => 'Hochschulgruppe', 'css' => 'o-hsg'],
        'fachschaft' => ['label' => 'Fachschaft',      'css' => 'o-fs'],
        'extern'     => ['label' => 'Extern',          'css' => 'o-ext'],
    ];
}

/**
 * Platzhalter-Farbe für Beiträge OHNE Bild – abgeleitet aus der ID, nicht gespeichert.
 *
 * Wichtig für die Content-Security-Policy des öffentlichen Bereichs: Dort gilt
 * `default-src 'none'` und es gibt kein 'unsafe-inline'. Ein `style="background:…"` würde vom
 * Browser stillschweigend verworfen, die Kachel bliebe grau. Deshalb acht feste Klassen in der
 * CSS-Datei, und hier wird nur ausgerechnet, welche es wird. Gleiche ID = immer gleiche Farbe.
 */
function wl_placeholder_class(int $id): string
{
    return 'wl-ph-' . (($id % 8) + 1);
}

/**
 * Standard-Bilder je Kategorie – die Antwort auf „Beitrag ohne Bild".
 *
 * Die Dateien liegen in assets/wl-standard/ und werden MIT der App ausgeliefert: Sie gehören
 * zum Programm wie das Stylesheet, nicht zu den Nutzerdaten in data/ (das der rsync ohnehin
 * nie mitnimmt). Ein Beitrag ohne eigenes Bild bekommt automatisch eines aus seiner Kategorie –
 * abgeleitet aus der ID (wl_standard_bild()), nicht gespeichert: gleiche ID = immer dasselbe
 * Bild, und niemand muss etwas pflegen. Die Farbverlaufs-Platzhalter (wl_placeholder_class)
 * bleiben nur noch als letzte Rückfalllinie, falls eine Kategorie hier einmal leer wäre.
 *
 * LIZENZEN: Alles frei verwendbar – überwiegend CC0/Public Domain, drei Aufnahmen CC BY
 * (Namensnennung PFLICHT; sie steht als 'credit' dabei und die Detailseite zeigt sie an).
 * 'quelle' ist der Fundort, damit jede Angabe überprüfbar bleibt. Wer Bilder ergänzt, trägt
 * BEIDES ein – ein Bild ohne Herkunft fliegt beim nächsten Aufräumen raus, weil niemand mehr
 * belegen kann, dass wir es benutzen dürfen.
 */
function wl_standard_bilder(): array
{
    return [
        'party' => [
            ['file' => 'party-1.jpg', 'credit' => 'Anthony Delanoix (CC0)',
             'quelle' => 'https://stocksnap.io/photo/crowd-people-IUJP9OI22I'],
            ['file' => 'party-2.jpg', 'credit' => 'Nainoa Shizuru (CC0)',
             'quelle' => 'https://stocksnap.io/photo/concert-show-5FGWJW4Z5D'],
            ['file' => 'party-3.jpg', 'credit' => 'Olu Eletu (CC0)',
             'quelle' => 'https://stocksnap.io/photo/concert-show-FZE048NY32'],
            ['file' => 'party-4.jpg', 'credit' => 'Desi Mendoza (CC0)',
             'quelle' => 'https://stocksnap.io/photo/band-singer-2BE6B9D920'],
            ['file' => 'party-5.jpg', 'credit' => 'Ezra Jeffrey (CC0)',
             'quelle' => 'https://stocksnap.io/photo/people-crowd-HDS6BBHIRZ'],
            ['file' => 'party-6.jpg', 'credit' => 'Jesse Darland (CC0)',
             'quelle' => 'https://stocksnap.io/photo/people-man-191P2Z8N6I'],
            ['file' => 'party-7.jpg', 'credit' => 'Burst (CC0)',
             'quelle' => 'https://stocksnap.io/photo/concert-festival-4CNNMKE9IQ'],
            ['file' => 'party-8.jpg', 'credit' => 'rawpixel.com (CC0)',
             'quelle' => 'https://www.rawpixel.com/image/3295558/free-photo-image-concert-cc0-club'],
        ],
        'kultur' => [
            ['file' => 'kultur-1.jpg', 'credit' => 'tommybuddy (CC0)',
             'quelle' => 'https://commons.wikimedia.org/w/index.php?curid=59246587'],
            ['file' => 'kultur-2.jpg', 'credit' => 'rawpixel.com (CC0)',
             'quelle' => 'https://www.rawpixel.com/image/6111381'],
            ['file' => 'kultur-3.jpg', 'credit' => 'MPCROOYW GROAHMOIOE (CC0)',
             'quelle' => 'https://commons.wikimedia.org/w/index.php?curid=153728413'],
            ['file' => 'kultur-4.jpg', 'credit' => 'Igor Miske (CC0)',
             'quelle' => 'https://stocksnap.io/photo/people-man-606YAWY5OU'],
            ['file' => 'kultur-5.jpg', 'credit' => 'Rafael Miranda (CC0)',
             'quelle' => 'https://stocksnap.io/photo/thelouvre-paris-Z4SBGYA12R'],
            ['file' => 'kultur-6.jpg', 'credit' => 'Joe deSousa (CC0)',
             'quelle' => 'https://stocksnap.io/photo/marble-statue-2GPG4GIYD8'],
            ['file' => 'kultur-7.jpg', 'credit' => 'Adrianna Calvo (CC0)',
             'quelle' => 'https://stocksnap.io/photo/blackandwhite-photos-LYINBDMWER'],
            ['file' => 'kultur-8.jpg', 'credit' => 'Clem Onojeghuo (CC0)',
             'quelle' => 'https://stocksnap.io/photo/people-man-O4ST1KJ9MD'],
        ],
        'sport' => [
            ['file' => 'sport-1.jpg', 'credit' => 'rawpixel.com (CC0)',
             'quelle' => 'https://www.rawpixel.com/image/6040763'],
            ['file' => 'sport-2.jpg', 'credit' => 'Markus Spiske (CC0)',
             'quelle' => 'https://www.rawpixel.com/image/564020/rock-climbing-wall'],
            ['file' => 'sport-3.jpg', 'credit' => 'Olos88 (CC0)',
             'quelle' => 'https://commons.wikimedia.org/w/index.php?curid=85809919'],
            ['file' => 'sport-4.jpg', 'credit' => 'Chanan Greenblatt (CC0)',
             'quelle' => 'https://stocksnap.io/photo/running-fitness-RMDYXIJFJN'],
            ['file' => 'sport-5.jpg', 'credit' => 'David Marcu (CC0)',
             'quelle' => 'https://stocksnap.io/photo/running-fitness-SA83WAUPEP'],
            ['file' => 'sport-6.jpg', 'credit' => 'Curtis Mac Newton (CC0)',
             'quelle' => 'https://stocksnap.io/photo/brooklybridge-running-X2Q7LBUF6U'],
            ['file' => 'sport-7.jpg', 'credit' => 'The Lazy Artist Gallery (CC0)',
             'quelle' => 'https://stocksnap.io/photo/man-running-APW0W8GBMM'],
            ['file' => 'sport-8.jpg', 'credit' => 'Austris Augusts (CC0)',
             'quelle' => 'https://stocksnap.io/photo/track-race-YYH82Y0CS7'],
        ],
        'bildung' => [
            ['file' => 'bildung-1.jpg', 'credit' => 'Patrik Goethe (CC0)',
             'quelle' => 'https://stocksnap.io/photo/books-library-EB9B6BC1F6'],
            ['file' => 'bildung-2.jpg', 'credit' => 'Aleksi Tappura (CC0)',
             'quelle' => 'https://stocksnap.io/photo/library-books-C4001BDB1C'],
            ['file' => 'bildung-3.jpg', 'credit' => 'rawpixel.com (CC0)',
             'quelle' => 'https://www.rawpixel.com/image/5904024'],
            ['file' => 'bildung-4.jpg', 'credit' => 'cogdogblog (CC0)',
             'quelle' => 'https://www.flickr.com/photos/37996646802@N01/6288971'],
            ['file' => 'bildung-5.jpg', 'credit' => 'Matthew Henry (CC0)',
             'quelle' => 'https://stocksnap.io/photo/laptop-mac-OR7D4PANCK'],
            ['file' => 'bildung-6.jpg', 'credit' => 'Matthew Henry (CC0)',
             'quelle' => 'https://stocksnap.io/photo/laptop-apple-OYEIB7P371'],
            ['file' => 'bildung-7.jpg', 'credit' => 'WDnet Studio (CC0)',
             'quelle' => 'https://stocksnap.io/photo/laptop-pen-JHJH4PS68L'],
            ['file' => 'bildung-8.jpg', 'credit' => 'Tim Gouw (CC0)',
             'quelle' => 'https://stocksnap.io/photo/restaurant-people-L495ZQH5KK'],
        ],
        'ehrenamt' => [
            ['file' => 'ehrenamt-1.jpg', 'credit' => 'Cape Hatteras NPS (Public Domain)',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:Volunteers_participate_in_beach_debris_cleanup_-_52751597226.jpg'],
            ['file' => 'ehrenamt-2.jpg', 'credit' => 'Robbie Ian Morrison, CC BY 4.0',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:AufBuchen_planting_Schlachtensee_Berlin_01_Dec_2024_(1).jpg'],
            ['file' => 'ehrenamt-3.jpg', 'credit' => 'U.S. Navy / Robert Brazie (Public Domain)',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:Volunteers_assist_food_bank_DVIDS1099796.jpg'],
            ['file' => 'ehrenamt-4.jpg', 'credit' => 'U.S. Fish and Wildlife Service (Public Domain)',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:Chicago_Burnham_Corridor_Volunteers_planting_(13984713420).jpg'],
            ['file' => 'ehrenamt-5.jpg', 'credit' => 'U.S. Fish and Wildlife Service (Public Domain)',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:Volunteers_Planting_Trees_on_the_Ankeny_National_Wildlife_Refuge_-_DPLA_-_34d3d852428a6bd0c316855d00d1d8ea.jpg'],
            ['file' => 'ehrenamt-6.jpg', 'credit' => 'Joshua Tree National Park (Public Domain)',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:Volunteers_planting_(55066598042).jpg'],
            ['file' => 'ehrenamt-7.jpg', 'credit' => 'U.S. Department of Agriculture (CC0)',
             'quelle' => 'https://www.rawpixel.com/image/3306117/free-photo-image-summer-forest-adventure-beaverhead-deerlodge-national'],
            ['file' => 'ehrenamt-8.jpg', 'credit' => 'US EPA (Public Domain)',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:November_15,_2012_Volunteers_at_the_Food_Bank_of_Delaware_sort_through_donations._(8266408915).jpg'],
        ],
        'markt' => [
            ['file' => 'markt-1.jpg', 'credit' => 'Carol M. Highsmith (CC0)',
             'quelle' => 'https://www.rawpixel.com/image/8055074'],
            ['file' => 'markt-2.jpg', 'credit' => 'Mojmír Churavý (CC0)',
             'quelle' => 'https://commons.wikimedia.org/w/index.php?curid=185492420'],
            ['file' => 'markt-3.jpg', 'credit' => 'Hajotthu, CC BY 3.0',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:Flohmarkt_Waterlooplein07.jpg'],
            ['file' => 'markt-4.jpg', 'credit' => 'Lukas Budimaier (CC0)',
             'quelle' => 'https://stocksnap.io/photo/food-fruits-AWJD4WV6W1'],
            ['file' => 'markt-5.jpg', 'credit' => 'Leeroy (CC0)',
             'quelle' => 'https://stocksnap.io/photo/fruits-vegetables-AA39511B59'],
            ['file' => 'markt-6.jpg', 'credit' => 'Brad Stallcup (CC0)',
             'quelle' => 'https://stocksnap.io/photo/beans-farm-C7LDIUGZ9K'],
            ['file' => 'markt-7.jpg', 'credit' => 'Bruce Mars (CC0)',
             'quelle' => 'https://stocksnap.io/photo/vegetable-stall-KZ19NV9MVY'],
            ['file' => 'markt-8.jpg', 'credit' => 'Lisa Fotios (CC0)',
             'quelle' => 'https://stocksnap.io/photo/outdoor-vegetable-37DTDTYBW5'],
        ],
        'sonstiges' => [
            ['file' => 'sonstiges-1.jpg', 'credit' => 'Mshuang2 (CC0)',
             'quelle' => 'https://commons.wikimedia.org/w/index.php?curid=99767598'],
            ['file' => 'sonstiges-2.jpg', 'credit' => 'Daniel Montoya (CC0)',
             'quelle' => 'https://commons.wikimedia.org/w/index.php?curid=112713635'],
            ['file' => 'sonstiges-3.jpg', 'credit' => 'Helena Jacoba, CC BY 2.0',
             'quelle' => 'https://commons.wikimedia.org/wiki/File:Campfire_on_our_last_evening_(29002836840).jpg'],
            ['file' => 'sonstiges-4.jpg', 'credit' => 'Morgan Sessions (CC0)',
             'quelle' => 'https://stocksnap.io/photo/sparkler-hands-1C1A9389B4'],
            ['file' => 'sonstiges-5.jpg', 'credit' => 'Travel Adventures (CC0)',
             'quelle' => 'https://stocksnap.io/photo/outdoor-picnic-WSRRONVX8J'],
            ['file' => 'sonstiges-6.jpg', 'credit' => 'Ylanite Koppens (CC0)',
             'quelle' => 'https://stocksnap.io/photo/monopoly-boardgame-QDNG1EOEU4'],
            ['file' => 'sonstiges-7.jpg', 'credit' => 'Travel Coffee Book (CC0)',
             'quelle' => 'https://stocksnap.io/photo/balloons-people-79A4E09475'],
            ['file' => 'sonstiges-8.jpg', 'credit' => 'Mike Erskine (CC0)',
             'quelle' => 'https://stocksnap.io/photo/people-men-LIAQ9N16WK'],
        ],
    ];
}

/** Standard-Bild für einen Beitrag ohne eigenes Bild – aus der ID abgeleitet, nicht gespeichert. */
function wl_standard_bild(string $cat, int $id): ?array
{
    $liste = wl_standard_bilder()[$cat] ?? [];
    if (!$liste) return null;
    return $liste[$id % count($liste)];
}

/** Ein Standard-Bild über seinen Dateinamen finden – über ALLE Kategorien (die Wahl bleibt
 *  auch dann gültig, wenn die Kategorie des Beitrags später wechselt). */
function wl_std_by_file(string $file): ?array
{
    foreach (wl_standard_bilder() as $liste) {
        foreach ($liste as $b) {
            if ((string)$b['file'] === $file) return $b;
        }
    }
    return null;
}

/** DAS Standard-Bild eines Beitrags: erst die gewählte Datei (items.std_bild,
 *  im Einreich-Formular auswählbar), sonst die Automatik aus der ID. */
function wl_item_std(array $it): ?array
{
    $gewaehlt = wl_std_by_file(trim((string)($it['std_bild'] ?? '')));
    return $gewaehlt ?? wl_standard_bild((string)($it['cat'] ?? ''), (int)($it['id'] ?? 0));
}

/**
 * Mitgelieferte BANNER-Bilder: fünf breite Panorama-Motive (1600×500, Zuschnitt
 * passend zur Banner-Fläche der Startseite), damit ein Banner nicht erst ein Archiv-Bild
 * braucht. Gleiche Regeln wie bei wl_standard_bilder(): Dateien in assets/wl-standard/,
 * NUR CC0 (keine Namensnennungspflicht – auf dem Banner gibt es keinen Platz für einen
 * Bildnachweis), credit + quelle bleiben trotzdem Pflicht, damit alles belegbar ist.
 */
function wl_banner_bilder(): array
{
    return [
        ['file' => 'banner-1.jpg', 'name' => 'Konfetti',
         'credit' => 'Altered Reality (CC0)', 'quelle' => 'https://stocksnap.io/photo/birthday-confetti-GKYCLSYDLH'],
        ['file' => 'banner-2.jpg', 'name' => 'Lichtermeer',
         'credit' => 'Ryan Loughlin (CC0)', 'quelle' => 'https://stocksnap.io/photo/people-crowd-EXOX4OVUQJ'],
        ['file' => 'banner-3.jpg', 'name' => 'Bühne',
         'credit' => 'Marc-Antoine Dépelteau (CC0)', 'quelle' => 'https://stocksnap.io/photo/concert-stage-P7JJ4LKNK8'],
        ['file' => 'banner-4.jpg', 'name' => 'Feuerwerk',
         'credit' => 'Mike Enerio (CC0)', 'quelle' => 'https://stocksnap.io/photo/fireworks-lights-11JGOISGC7'],
        ['file' => 'banner-5.jpg', 'name' => 'Campus',
         'credit' => 'Vadim Sherbakov (CC0)', 'quelle' => 'https://stocksnap.io/photo/blue-sky-E1C34B4580'],
    ];
}

/** Ein Banner-Bild über seinen Dateinamen finden – null heißt: keins gewählt oder unbekannt. */
function wl_banner_std_by_file(string $file): ?array
{
    foreach (wl_banner_bilder() as $b) {
        if ((string)$b['file'] === $file) return $b;
    }
    return null;
}

// -----------------------------------------------------------------------------------------------
// Veranstalter
// -----------------------------------------------------------------------------------------------

function wl_token_new(): string
{
    return bin2hex(random_bytes(WL_TOKEN_LEN));
}

function wl_org_by_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !ctype_xdigit($token)) return null;
    $s = wl_db()->prepare('SELECT * FROM orgs WHERE token = ? AND active = 1');
    $s->execute([$token]);
    return $s->fetch() ?: null;
}

function wl_org(int $id): ?array
{
    if ($id <= 0) return null;
    $s = wl_db()->prepare('SELECT * FROM orgs WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function wl_orgs_all(bool $auchInaktive = false): array
{
    $sql = 'SELECT * FROM orgs';
    if (!$auchInaktive) $sql .= ' WHERE active = 1';
    return wl_db()->query($sql . ' ORDER BY art, name COLLATE NOCASE')->fetchAll();
}

/** Kürzel für den Farbpunkt: gepflegt, sonst der erste Buchstabe des Namens. */
function wl_org_kurz(array $org): string
{
    $k = trim((string)($org['kurz'] ?? ''));
    if ($k !== '') return mb_substr($k, 0, 2);
    return mb_strtoupper(mb_substr(trim((string)($org['name'] ?? '?')), 0, 1));
}

// -----------------------------------------------------------------------------------------------
// Anmeldung der Veranstalter (Mailadresse + Passwort, Passwort-Setzen per Mail-Link)
// -----------------------------------------------------------------------------------------------

/** Wie lange ein Passwort-Link gilt. Großzügig: Die Einladung liegt auch mal übers Wochenende. */
const WL_RESET_STUNDEN = 48;
/** Eigener Tagesdeckel für diesen Bereich – die Zugangs-Mails sind wenige, der gemeinsame
 *  Topf (an dem auch die Login-Links der App hängen) darf davon nie leerlaufen. */
/* WL_MAIL_CAP ist Geschichte – der Tagesdeckel ist die Einstellung
   `cap_day` (wl_mail_cap(), Vorgabe 300), gepflegt in der Verwaltung wie überall. */

function wl_org_passwort_setzen(int $id, string $passwort): void
{
    wl_db()->prepare("UPDATE orgs SET pass_hash = ?, reset_token = '', reset_bis = '' WHERE id = ?")
        ->execute([password_hash($passwort, PASSWORD_DEFAULT), $id]);
}

/** Ist die Mailadresse noch frei? Sie IST der Anmeldename, doppelt geht nicht. */
function wl_org_mail_frei(string $mail, int $ausserId = 0): bool
{
    $s = wl_db()->prepare("SELECT COUNT(*) FROM orgs WHERE LOWER(mail) = LOWER(?) AND mail <> '' AND id <> ?");
    $s->execute([trim($mail), $ausserId]);
    return (int)$s->fetchColumn() === 0;
}

/**
 * Kennung des anfragenden Geräts – nur als Zutat für Bremsen und die Besucherzählung, nie
 * gespeichert. Hinter einem Reverse-Proxy steht die echte Adresse im ersten Eintrag der Kette.
 */
function wl_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Tages-Salz für alles, was Merkmale nur HASHT statt sie zu speichern (Bremsen, Zählung). */
function wl_tages_salz(): string
{
    $tag = date('Y-m-d');
    $salz = wl_setting('stats_salt', '');
    if ($salz === '' || wl_setting('stats_salt_day', '') !== $tag) {
        $salz = bin2hex(random_bytes(16));
        wl_setting_set('stats_salt', $salz);
        wl_setting_set('stats_salt_day', $tag);
        wl_db()->prepare('DELETE FROM stats_seen WHERE day <> ?')->execute([$tag]);
        wl_db()->prepare('DELETE FROM throttle WHERE day <> ?')->execute([$tag]);
    }
    return $salz;
}

/**
 * Bremse, die das Wegwerfen der Sitzung überlebt (anders als wl_rate_ok()).
 *
 * Gezählt wird je Topf und Merkmal – beim Login das KONTO, beim Passwort-Link die ANGEFRAGTE
 * ADRESSE. Beides kann sich niemand aussuchen, deshalb ist die Bremse nicht zu umgehen, indem
 * man Cookies löscht. Reißt sie, gilt eine Sperrzeit; danach fängt der Zähler von vorn an.
 *
 * Rückgabe true = darf. Der Aufruf zählt den Versuch gleich mit.
 */
function wl_bremse(string $topf, string $merkmal, int $max, int $sperreSek = 900): bool
{
    try {
        $tag  = date('Y-m-d');
        $k    = substr(hash('sha256', wl_tages_salz() . '|' . $topf . '|' . mb_strtolower(trim($merkmal))), 0, 32);
        $jetzt = date('Y-m-d H:i:s');
        $s = wl_db()->prepare('SELECT n, bis FROM throttle WHERE k = ?');
        $s->execute([$k]);
        $r = $s->fetch();
        if ($r && (string)$r['bis'] !== '' && (string)$r['bis'] > $jetzt) return false;   // gesperrt
        $n = $r ? (int)$r['n'] + 1 : 1;
        if ($r && (string)$r['bis'] !== '' && (string)$r['bis'] <= $jetzt) $n = 1;        // Sperre abgelaufen
        $bis = $n > $max ? date('Y-m-d H:i:s', time() + $sperreSek) : '';
        wl_db()->prepare('INSERT INTO throttle(k, n, bis, day) VALUES(?,?,?,?)
            ON CONFLICT(k) DO UPDATE SET n = excluded.n, bis = excluded.bis, day = excluded.day')
            ->execute([$k, $n, $bis, $tag]);
        return $n <= $max;
    } catch (\Throwable $e) {
        return true;   // eine kaputte Bremse darf niemanden aussperren
    }
}

/** Bremse zurücksetzen – nach einer erfolgreichen Anmeldung zählt nichts mehr nach. */
function wl_bremse_frei(string $topf, string $merkmal): void
{
    try {
        $k = substr(hash('sha256', wl_tages_salz() . '|' . $topf . '|' . mb_strtolower(trim($merkmal))), 0, 32);
        wl_db()->prepare('DELETE FROM throttle WHERE k = ?')->execute([$k]);
    } catch (\Throwable $e) { /* egal */ }
}

/**
 * Anmeldeversuch mit Mailadresse. Eine Antwort für alle Fehlerfälle – ob es die Adresse gibt,
 * verrät die Seite nicht. Gegen Zeitmessung wird auch bei unbekannter Adresse ein Hash geprüft.
 *
 * Zwei Schlösser gegen das Durchprobieren, beide UNABHÄNGIG von der Sitzung:
 *   1. Am Konto (orgs.fail_n/fail_bis): nach WL_LOGIN_MAX Fehlversuchen 15 Minuten Pause.
 *   2. Am Gerät (wl_bremse) – greift auch, wenn jemand viele verschiedene Adressen durchprobiert.
 * Rückgabe: die Gruppe, oder null. `$grund` sagt dem Aufrufer, ob gesperrt wurde – die
 * ANGEZEIGTE Meldung ist trotzdem dieselbe wie bei falschem Passwort (sonst verriete die
 * Seite, welche Adressen es gibt).
 */
const WL_LOGIN_MAX = 8;

function wl_org_anmelden(string $mail, string $passwort, string &$grund = ''): ?array
{
    $grund = '';
    $mail = trim($mail);
    if (!wl_bremse('login-geraet', wl_client_ip(), 30, 900)) { $grund = 'gesperrt'; return null; }

    $s = wl_db()->prepare("SELECT * FROM orgs WHERE LOWER(mail) = LOWER(?) AND mail <> '' AND active = 1");
    $s->execute([$mail]);
    $org = $s->fetch() ?: null;

    // Konto gesperrt? Dann gar nicht erst prüfen – auch nicht mit dem richtigen Passwort.
    if ($org && (string)$org['fail_bis'] !== '' && (string)$org['fail_bis'] > date('Y-m-d H:i:s')) {
        $grund = 'gesperrt';
        return null;
    }
    $hash = $org !== null && (string)$org['pass_hash'] !== ''
        ? (string)$org['pass_hash']
        : password_hash('nur-zum-zeitausgleich', PASSWORD_DEFAULT);
    if (!password_verify($passwort, $hash) || $org === null) {
        if ($org !== null) {
            $n = (int)$org['fail_n'] + 1;
            wl_db()->prepare("UPDATE orgs SET fail_n = ?, fail_bis = ? WHERE id = ?")
                ->execute([$n, $n >= WL_LOGIN_MAX ? date('Y-m-d H:i:s', time() + 900) : '', (int)$org['id']]);
            if ($n >= WL_LOGIN_MAX) $grund = 'gesperrt';
        }
        return null;
    }
    wl_db()->prepare("UPDATE orgs SET fail_n = 0, fail_bis = '' WHERE id = ?")->execute([(int)$org['id']]);
    wl_bremse_frei('login-geraet', wl_client_ip());
    return $org;
}

/** Mail aus diesem Bereich – gleiches Muster wie beim Terminplaner: Absender ist die eine
 *  Adresse der App, die Kontaktadresse des Bereichs ist die Antwortadresse. */
/** Tagesdeckel für was.läuft-Mails (cap_day wie in den anderen Bereichen). Vorgabe 300 –
 * mehr Veranstalter-Post fällt an einem Tag realistisch nicht an. */
function wl_mail_cap(): int
{
    return max(0, min(3000, (int)wl_setting('cap_day', '300')));
}

function wl_mail(string $to, string $subject, string $body): bool
{
    if (mail_pool_budget('waslaeuft', wl_mail_cap()) < 1) return false;
    // ABWEICHUNG vom üblichen „Absender ist immer das eine Postfach der App": Für die
    // Veranstalter-Post darf hier die eigene was.läuft-Adresse der ABSENDER sein – das
    // Postfach existiert. Ohne Eintrag gilt weiter das gemeinsame Postfach; Antworten gehen
    // an die Kontakt-Adresse, sonst an den Absender.
    $from  = trim(wl_setting('from_email', ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) $from = mail_pool_absender();
    $reply = trim(wl_setting('kontakt_mail', ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) $from = $reply;
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    if ($reply === '' || !filter_var($reply, FILTER_VALIDATE_EMAIL)) $reply = $from;
    $name = trim(str_replace(["\r", "\n"], '', wl_setting('from_name', 'was.läuft')));
    $to = trim(str_replace(["\r", "\n"], '', $to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $headers = [
        'From: ' . ($name !== ''
            ? '=?UTF-8?B?' . base64_encode($name) . '?= <' . $from . '>' : $from),
        'Reply-To: ' . $reply,
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: AStA-App',
        'Auto-Submitted: auto-generated',
    ];
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    if ($ok) mail_pool_note('waslaeuft');
    return (bool)$ok;
}

/**
 * Passwort-Link verschicken – EIN Mechanismus für beides: die Einladung nach dem Anlegen und
 * das „Passwort vergessen" in Selbstbedienung. Rückgabe: ging die Mail raus?
 * Der Text sagt beides, damit niemand über eine „Einladung" stolpert, die er als Reset
 * angefordert hat (und umgekehrt).
 */
function wl_org_zugangsmail(int $orgId): bool
{
    $org = wl_org($orgId);
    if (!$org || (int)$org['active'] !== 1) return false;
    $mail = trim((string)$org['mail']);
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) return false;
    $basis = rtrim(wl_setting('base_url', ''), '/');
    if ($basis === '') return false;

    $token = wl_token_new();
    wl_db()->prepare('UPDATE orgs SET reset_token = ?, reset_bis = ? WHERE id = ?')
        ->execute([$token, date('Y-m-d H:i:s', time() + WL_RESET_STUNDEN * 3600), $orgId]);

    $link = $basis . '/veranstaltungen/einreichen.php?reset=' . rawurlencode($token);
    $body = 'Hallo ' . (string)$org['name'] . ",\n\n"
        . "über diesen Link legt ihr das Passwort für euren was.läuft-Zugang fest\n"
        . "(oder setzt es neu, falls es weg ist):\n\n"
        . $link . "\n\n"
        . 'Der Link gilt ' . WL_RESET_STUNDEN . " Stunden. Angemeldet wird sich danach mit dieser\n"
        . "Mailadresse und dem gewählten Passwort auf:\n"
        . $basis . "/veranstaltungen/einreichen.php\n\n"
        . "Falls ihr das nicht angefordert habt, könnt ihr diese Mail ignorieren –\n"
        . "der Zugang bleibt, wie er ist.\n\n"
        . "— " . wl_traeger_kurz() . " · was.läuft";
    return wl_mail($mail, 'was.läuft: Euer Zugang für ' . (string)$org['name'], $body);
}

/** „Passwort vergessen" von der öffentlichen Seite: still, wenn es die Adresse nicht gibt. */
function wl_org_reset_start(string $mail): void
{
    // Zwei Bremsen, beide unabhängig von der Sitzung: Ohne sie ließe sich das Postfach einer
    // Gruppe mit Zugangs-Mails fluten und nebenbei das Tageskontingent verbrennen – eine
    // Sitzungs-Bremse wäre mit einem gelöschten Cookie weg.
    // Die Seite verrät davon nichts: Sie antwortet immer gleich, ob gesendet wurde oder nicht.
    if (!wl_bremse('reset-adresse', trim($mail), 3, 3600)) return;
    if (!wl_bremse('reset-geraet', wl_client_ip(), 10, 3600)) return;
    $s = wl_db()->prepare("SELECT id FROM orgs WHERE LOWER(mail) = LOWER(?) AND mail <> '' AND active = 1");
    $s->execute([trim($mail)]);
    $id = (int)$s->fetchColumn();
    if ($id > 0) wl_org_zugangsmail($id);
}

/** Den Veranstalter zu einem gültigen (nicht abgelaufenen) Passwort-Link finden. */
function wl_org_by_reset(string $token): ?array
{
    if (!preg_match('~^[a-f0-9]{16,}$~', $token)) return null;
    $s = wl_db()->prepare("SELECT * FROM orgs WHERE reset_token = ? AND reset_token <> ''
        AND reset_bis >= ? AND active = 1");
    $s->execute([$token, date('Y-m-d H:i:s')]);
    return $s->fetch() ?: null;
}

// -----------------------------------------------------------------------------------------------
// Zugangsanfragen („Wir wollen dabei sein")
// -----------------------------------------------------------------------------------------------

/** Anfrage vom öffentlichen „Eintragen"-Knopf entgegennehmen. Rückgabe: ['ok', 'msg']. */
function wl_org_request_add(string $name, string $mail, string $nachricht): array
{
    $name = mb_substr(trim($name), 0, 120);
    $mail = mb_substr(trim($mail), 0, 200);
    $nachricht = mb_substr(trim($nachricht), 0, 1000);
    if ($name === '') return ['ok' => false, 'msg' => 'Bitte sagt uns, wie eure Gruppe heißt.'];
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'Die Mailadresse sieht nicht richtig aus – wir brauchen sie, um euch den Zugang zu schicken.'];
    }
    wl_db()->prepare('INSERT INTO org_requests(name, mail, nachricht) VALUES(?,?,?)')
        ->execute([$name, $mail, $nachricht]);
    return ['ok' => true, 'msg' => ''];
}

function wl_org_requests(): array
{
    return wl_db()->query('SELECT * FROM org_requests ORDER BY created_at')->fetchAll();
}

function wl_org_request(int $id): ?array
{
    $s = wl_db()->prepare('SELECT * FROM org_requests WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Erledigt heißt WEG: Es sind fremde Kontaktdaten, aufzuheben gibt es danach nichts. */
function wl_org_request_delete(int $id): void
{
    wl_db()->prepare('DELETE FROM org_requests WHERE id = ?')->execute([$id]);
}

/**
 * Veranstalter ENDGÜLTIG löschen (Verwaltung,) – samt allem, was dranhängt:
 * Beiträge über wl_item_delete() (räumt Erinnerungen und Bilddateien mit), eigene Bilder
 * und Logo (nur was nirgends mehr hängt – ein Banner-Bild etwa bleibt), Abos auf die
 * Gruppe. Kategorie-Vorschläge bleiben anonymisiert stehen (org_id ON DELETE SET NULL).
 */
function wl_org_delete(int $id): void
{
    $s = wl_db()->prepare('SELECT id FROM items WHERE org_id = ?');
    $s->execute([$id]);
    foreach ($s->fetchAll() as $it) wl_item_delete((int)$it['id']);

    wl_org_logo_setzen($id, 0);
    $s = wl_db()->prepare('SELECT id FROM images WHERE org_id = ? AND stock = 0');
    $s->execute([$id]);
    foreach ($s->fetchAll() as $b) wl_image_delete_if_unused((int)$b['id']);

    wl_db()->prepare('DELETE FROM push_follows WHERE org_id = ?')->execute([$id]);
    wl_db()->prepare('DELETE FROM orgs WHERE id = ?')->execute([$id]);
}

// -----------------------------------------------------------------------------------------------
// Kategorie-Vorschläge (aus dem Einreich-Formular; Stapel für die Verwaltung)
// -----------------------------------------------------------------------------------------------

function wl_cat_wunsch_add(int $orgId, string $text): bool
{
    $text = mb_substr(trim($text), 0, 500);
    if ($text === '') return false;
    wl_db()->prepare('INSERT INTO cat_wuensche(org_id, text) VALUES(?, ?)')
        ->execute([$orgId ?: null, $text]);
    return true;
}

function wl_cat_wuensche(): array
{
    return wl_db()->query('SELECT c.*, o.name AS org_name FROM cat_wuensche c
        LEFT JOIN orgs o ON o.id = c.org_id ORDER BY c.id DESC')->fetchAll();
}

function wl_cat_wunsch_delete(int $id): void
{
    wl_db()->prepare('DELETE FROM cat_wuensche WHERE id = ?')->execute([$id]);
}

// -----------------------------------------------------------------------------------------------
// Beiträge
// -----------------------------------------------------------------------------------------------

/**
 * Adress-tauglicher Titel („Semester-Opening im Alten Kaufhaus" → „semester-opening-im-alten-kaufhaus").
 * Umlaute werden ausgeschrieben, alles andere fliegt raus. Leerer Rest ergibt 'beitrag'.
 */
function wl_slug(string $titel): string
{
    $s = mb_strtolower(trim($titel));
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'à' => 'a', 'á' => 'a',
                    'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s === '' ? 'beitrag' : mb_substr($s, 0, 60);
}

function wl_item(int $id): ?array
{
    if ($id <= 0) return null;
    $s = wl_db()->prepare('SELECT * FROM items WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function wl_item_by_edit_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !ctype_xdigit($token)) return null;
    $s = wl_db()->prepare('SELECT * FROM items WHERE edit_token = ?');
    $s->execute([$token]);
    return $s->fetch() ?: null;
}

/**
 * Ist dieser Beitrag noch aktuell? Bewusst über das DATUM, nicht über die Uhrzeit: Eine Party um
 * 22 Uhr soll am selben Abend nicht schon um 22:01 aus der Liste fallen. Kurse laufen bis zu ihrem
 * Enddatum, und ein Kurs ohne Enddatum läuft weiter, bis ihn jemand beendet.
 */
function wl_item_vorbei(array $it): bool
{
    $heute = date('Y-m-d');
    if (($it['kind'] ?? 'event') === 'kurs') {
        $bis = substr(trim((string)($it['bis_datum'] ?? '')), 0, 10);
        return $bis !== '' && $bis < $heute;
    }
    $ende = trim((string)($it['ends_at'] ?? ''));
    $tag  = substr($ende !== '' ? $ende : (string)($it['starts_at'] ?? ''), 0, 10);
    return $tag !== '' && $tag < $heute;
}

/**
 * Öffentliche Liste. $filter: ['kind','cat','org','frei','barrierefrei','von','bis','suche','featured'].
 * Zurück kommen nur freigegebene, noch nicht abgelaufene Beiträge.
 */
function wl_items_public(array $filter = [], int $limit = 60): array
{
    $wo  = ["i.status = 'live'"];
    $par = [];

    $kind = (string)($filter['kind'] ?? '');
    if ($kind === 'event' || $kind === 'kurs') { $wo[] = 'i.kind = :kind'; $par[':kind'] = $kind; }

    $cat = (string)($filter['cat'] ?? '');
    if ($cat !== '' && isset(wl_cats()[$cat])) { $wo[] = 'i.cat = :cat'; $par[':cat'] = $cat; }

    $org = (int)($filter['org'] ?? 0);
    if ($org > 0) { $wo[] = 'i.org_id = :org'; $par[':org'] = $org; }

    if (!empty($filter['frei']))         $wo[] = 'i.frei = 1';
    if (!empty($filter['barrierefrei'])) $wo[] = 'i.barrierefrei = 1';
    if (!empty($filter['featured']))     $wo[] = 'i.featured = 1';

    // Zeitraum gilt nur für Veranstaltungen; Kurse haben kein Datum, an dem man sie einordnen könnte.
    $von = substr(trim((string)($filter['von'] ?? '')), 0, 10);
    $bis = substr(trim((string)($filter['bis'] ?? '')), 0, 10);
    if ($von !== '') { $wo[] = "(i.kind = 'kurs' OR date(i.starts_at) >= :von)"; $par[':von'] = $von; }
    if ($bis !== '') { $wo[] = "(i.kind = 'kurs' OR date(i.starts_at) <= :bis)"; $par[':bis'] = $bis; }

    $suche = trim((string)($filter['suche'] ?? ''));
    if ($suche !== '') {
        // % und _ sind in LIKE Platzhalter – ohne Maskierung liefert die Suche nach „100%"
        // alles und die nach „a_b" fast alles. ESCAPE macht sie zu gewöhnlichen Zeichen.
        $wo[] = "(i.title LIKE :q ESCAPE '\\' OR i.teaser LIKE :q ESCAPE '\\' OR i.text LIKE :q ESCAPE '\\'"
              . " OR i.ort LIKE :q ESCAPE '\\' OR o.name LIKE :q ESCAPE '\\')";
        $par[':q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $suche) . '%';
    }

    // „Noch nicht vorbei": Veranstaltungen ab heute, Kurse bis zu ihrem Enddatum.
    $wo[] = "(CASE WHEN i.kind = 'kurs'
                   THEN (i.bis_datum = '' OR date(i.bis_datum) >= date('now','localtime'))
                   ELSE date(CASE WHEN i.ends_at <> '' THEN i.ends_at ELSE i.starts_at END) >= date('now','localtime')
              END)";

    $sql = 'SELECT i.*, o.name AS org_name, o.kurz AS org_kurz, o.art AS org_art, im.file AS bild
            FROM items i
            LEFT JOIN orgs o ON o.id = i.org_id
            LEFT JOIN images im ON im.id = i.image_id
            WHERE ' . implode(' AND ', $wo) . "
            ORDER BY CASE WHEN i.kind = 'kurs' THEN 1 ELSE 0 END, i.starts_at, i.title COLLATE NOCASE
            LIMIT " . max(1, min(200, $limit));

    $s = wl_db()->prepare($sql);
    $s->execute($par);
    return $s->fetchAll();
}

/** Was oben groß im Karussell läuft: freigegeben, hervorgehoben, noch nicht vorbei. */
function wl_featured(int $limit = 6): array
{
    return wl_items_public(['featured' => 1], $limit);
}

/** Beiträge, die auf eine Entscheidung warten (für die App). */
function wl_pending(): array
{
    return wl_db()->query("SELECT i.*, o.name AS org_name, o.art AS org_art, im.file AS bild, im.rights_ok
        FROM items i LEFT JOIN orgs o ON o.id = i.org_id LEFT JOIN images im ON im.id = i.image_id
        WHERE i.status = 'pending' ORDER BY i.submitted_at")->fetchAll();
}

function wl_count(string $status): int
{
    $s = wl_db()->prepare('SELECT COUNT(*) FROM items WHERE status = ?');
    $s->execute([$status]);
    return (int)$s->fetchColumn();
}

/**
 * Formulardaten begrenzen und prüfen – der gemeinsame Kern von „direkt speichern"
 * (wl_item_save) und „Änderung erst einmal beiseitelegen" (wl_item_edit_store). Beide Wege
 * laufen durch DIESELBE Prüfung, damit eine wartende Änderung beim Anwenden nie an etwas
 * scheitern kann, das beim Einreichen durchgerutscht wäre.
 * Rückgabe: ['ok'=>bool, 'msg'=>string, 'felder'=>array] – felder sind fertige Spaltenwerte.
 */
function wl_item_felder(array $daten): array
{
    $kind = ((string)($daten['kind'] ?? 'event')) === 'kurs' ? 'kurs' : 'event';
    $titel = trim((string)($daten['title'] ?? ''));
    if ($titel === '') return ['ok' => false, 'msg' => 'Bitte einen Titel angeben.', 'felder' => []];
    if (mb_strlen($titel) > 120) $titel = mb_substr($titel, 0, 120);

    $cat = (string)($daten['cat'] ?? 'sonstiges');
    if (!isset(wl_cats()[$cat])) $cat = 'sonstiges';

    $kuerzen = static fn (string $v, int $max): string => mb_substr(trim($v), 0, $max);
    $datum   = static function (string $v): string {
        $v = trim(str_replace('T', ' ', $v));
        return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $v) ? $v : '';
    };
    // „www.foo.de" oder „foo.de/x" genügen – das Schema setzt wl_url_norm() davor (und wirft
    // alles raus, was keine Web-Adresse ist).
    $link = static fn (string $v): string => wl_url_norm($v);

    $f = [
        'kind'          => $kind,
        'title'         => $titel,
        'slug'          => wl_slug($titel),
        'teaser'        => $kuerzen((string)($daten['teaser'] ?? ''), 160),
        'text'          => $kuerzen((string)($daten['text'] ?? ''), 4000),
        'cat'           => $cat,
        'image_id'      => (int)($daten['image_id'] ?? 0) ?: null,
        // Gewähltes Standard-Foto: nur Dateinamen aus dem Register, alles andere fällt auf
        // die Automatik ('') zurück. Mit eigenem/Archiv-Bild ist die Wahl gegenstandslos.
        'std_bild'      => wl_std_by_file((string)($daten['std_bild'] ?? '')) ? (string)$daten['std_bild'] : '',
        'starts_at'     => $kind === 'event' ? $datum((string)($daten['starts_at'] ?? '')) : '',
        'ends_at'       => $kind === 'event' ? $datum((string)($daten['ends_at'] ?? '')) : '',
        'rhythmus'      => $kind === 'kurs' ? $kuerzen((string)($daten['rhythmus'] ?? ''), 80) : '',
        'termine'       => $kind === 'kurs' ? max(0, min(999, (int)($daten['termine'] ?? 0))) : 0,
        'einstieg'      => $kind === 'kurs' && !empty($daten['einstieg']) ? 1 : 0,
        'von_datum'     => $kind === 'kurs' ? substr($datum((string)($daten['von_datum'] ?? '')), 0, 10) : '',
        'bis_datum'     => $kind === 'kurs' ? substr($datum((string)($daten['bis_datum'] ?? '')), 0, 10) : '',
        'ort'           => $kuerzen((string)($daten['ort'] ?? ''), 100),
        'adresse'       => $kuerzen((string)($daten['adresse'] ?? ''), 160),
        // Nackte Zahl heißt Euro: „5" oder „7,50" bekommt automatisch sein „ €". Alles mit
        // Buchstaben oder Zeichen („Spende", „5 $", „3–5 €") bleibt unangetastet – die Regel
        // greift bewusst nur im eindeutigen Fall.
        'preis'         => preg_replace('/^(\d+(?:[.,]\d{1,2})?)$/', '$1 €',
                               $kuerzen((string)($daten['preis'] ?? ''), 60)),
        'studi_rabatt'  => $kuerzen((string)($daten['studi_rabatt'] ?? ''), 120),
        // Englische Kurzfassung – nur wenn die Betreiber sie freigeschaltet haben. Sonst
        // bliebe hier stehen, was einmal eingetragen wurde, und erschiene beim Wiedereinschalten
        // unversehens auf der Seite.
        'title_en'      => wl_en_an() ? $kuerzen((string)($daten['title_en'] ?? ''), 120) : '',
        'teaser_en'     => wl_en_an() ? $kuerzen((string)($daten['teaser_en'] ?? ''), 160) : '',
        'frei'          => !empty($daten['frei']) ? 1 : 0,
        'barrierefrei'  => !empty($daten['barrierefrei']) ? 1 : 0,
        'nur_studis'    => !empty($daten['nur_studis']) ? 1 : 0,
        'ab_alter'      => max(0, min(99, (int)($daten['ab_alter'] ?? 0))),
        'url'           => $link((string)($daten['url'] ?? '')),
        'anmeldung_url' => $link((string)($daten['anmeldung_url'] ?? '')),
    ];

    if ($kind === 'event' && $f['starts_at'] === '') {
        return ['ok' => false, 'msg' => 'Bitte Datum und Uhrzeit angeben.', 'felder' => []];
    }
    if ($kind === 'kurs' && $f['rhythmus'] === '') {
        return ['ok' => false, 'msg' => 'Bitte den Rhythmus angeben, z. B. „mittwochs 18:00".', 'felder' => []];
    }
    // Ein Ende vor dem Anfang ist keine Angabe, sondern ein Vertipper – lieber weglassen als anzeigen.
    if ($f['ends_at'] !== '' && $f['starts_at'] !== '' && $f['ends_at'] < $f['starts_at']) $f['ends_at'] = '';
    if ($f['bis_datum'] !== '' && $f['von_datum'] !== '' && $f['bis_datum'] < $f['von_datum']) $f['bis_datum'] = '';

    return ['ok' => true, 'msg' => '', 'felder' => $f];
}

/**
 * Beitrag anlegen oder ändern. $daten kommt roh aus dem Formular; wl_item_felder() begrenzt und
 * prüft. Rückgabe: ['ok'=>bool, 'msg'=>string, 'id'=>int].
 *
 * Der Status wird hier NICHT gesetzt – wer einreichen darf, entscheidet die aufrufende Seite
 * (öffentliches Formular: immer 'pending', außer der Veranstalter hat auto_ok; App: 'live').
 */
function wl_item_save(array $daten, ?int $id = null): array
{
    $p = wl_item_felder($daten);
    if (!$p['ok']) return ['ok' => false, 'msg' => $p['msg'], 'id' => 0];
    $f = $p['felder'];

    $pdo = wl_db();
    if ($id !== null && $id > 0) {
        $setzen = implode(', ', array_map(static fn ($k) => $k . ' = :' . $k, array_keys($f)));
        $s = $pdo->prepare('UPDATE items SET ' . $setzen . ' WHERE id = :id');
        $s->execute($f + ['id' => $id]);
        // Verschobener Beginn = verschobene Erinnerungen: offene „Erinnere mich"-Zeilen folgen
        // dem neuen Termin, statt zur alten Uhrzeit zu klingeln (abgeleitet statt gespeichert).
        wl_reminders_nachziehen($id);
        return ['ok' => true, 'msg' => 'Gespeichert.', 'id' => $id];
    }

    $f['org_id']     = (int)($daten['org_id'] ?? 0) ?: null;
    $f['status']     = 'pending';
    $f['edit_token'] = wl_token_new();
    $spalten = implode(', ', array_keys($f));
    $werte   = implode(', ', array_map(static fn ($k) => ':' . $k, array_keys($f)));
    $pdo->prepare("INSERT INTO items($spalten) VALUES($werte)")->execute($f);
    return ['ok' => true, 'msg' => 'Eingereicht.', 'id' => (int)$pdo->lastInsertId()];
}

// -----------------------------------------------------------------------------------------------
// Wartende Änderungen an Live-Beiträgen
// -----------------------------------------------------------------------------------------------
// Eine Gruppe darf ihre Beiträge nachträglich bearbeiten: Wartendes
// wird direkt überschrieben, an LIVE-Beiträgen bleibt die bisherige Fassung online, bis der
// AStA die Änderung freigibt – so bleibt der öffentliche Stand immer ein geprüfter, und ein
// Tippfehler zwingt niemanden mehr zu „löschen und neu einreichen" (was die gesetzten
// Erinnerungen und alle geteilten Links gekostet hätte).

/** Änderung prüfen und beiseitelegen. Rückgabe wie wl_item_save (id = Beitrag). */
function wl_item_edit_store(int $itemId, array $daten): array
{
    $p = wl_item_felder($daten);
    if (!$p['ok']) return ['ok' => false, 'msg' => $p['msg'], 'id' => 0];
    wl_db()->prepare("INSERT INTO item_edits(item_id, daten, eingereicht)
                      VALUES(?, ?, datetime('now','localtime'))
                      ON CONFLICT(item_id) DO UPDATE SET daten = excluded.daten,
                                                         eingereicht = excluded.eingereicht")
        ->execute([$itemId, json_encode($p['felder'], JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'msg' => 'Änderung eingereicht.', 'id' => $itemId];
}

/** Die wartende Änderung eines Beitrags – ['felder'=>array, 'eingereicht'=>string] oder null. */
function wl_item_edit(int $itemId): ?array
{
    $s = wl_db()->prepare('SELECT daten, eingereicht FROM item_edits WHERE item_id = ?');
    $s->execute([$itemId]);
    $r = $s->fetch();
    if (!$r) return null;
    $felder = json_decode((string)$r['daten'], true);
    if (!is_array($felder)) return null;
    return ['felder' => $felder, 'eingereicht' => (string)$r['eingereicht']];
}

/** Alle wartenden Änderungen für den Freigabe-Stapel der Verwaltung. */
function wl_item_edits_offen(): array
{
    $rows = wl_db()->query('SELECT e.item_id, e.daten, e.eingereicht, o.name AS org_name
        FROM item_edits e JOIN items i ON i.id = e.item_id
        LEFT JOIN orgs o ON o.id = i.org_id
        ORDER BY e.eingereicht')->fetchAll();
    $aus = [];
    foreach ($rows as $r) {
        $felder = json_decode((string)$r['daten'], true);
        if (!is_array($felder)) continue;
        $it = wl_item((int)$r['item_id']);
        if (!$it) continue;
        $aus[] = ['item' => $it, 'felder' => $felder,
                  'eingereicht' => (string)$r['eingereicht'], 'org_name' => (string)($r['org_name'] ?? '')];
    }
    return $aus;
}

function wl_item_edit_delete(int $itemId): void
{
    wl_db()->prepare('DELETE FROM item_edits WHERE item_id = ?')->execute([$itemId]);
}

/** Wartende Änderung anwenden: Felder auf den Beitrag schreiben, Erinnerungen nachziehen. */
function wl_item_edit_apply(int $itemId): bool
{
    $e = wl_item_edit($itemId);
    if (!$e) return false;
    // Geschrieben wird nur, was wl_item_felder heute als Spalte kennt – ein alter Stand mit
    // einem Feld, das es nicht mehr gibt, kann so keinen SQL-Fehler auslösen.
    $probe = wl_item_felder(['title' => 'x', 'kind' => 'event', 'starts_at' => '2999-01-01 00:00']);
    $f = array_intersect_key($e['felder'], $probe['felder']);
    if (!$f) return false;
    $setzen = implode(', ', array_map(static fn ($k) => $k . ' = :' . $k, array_keys($f)));
    wl_db()->prepare('UPDATE items SET ' . $setzen . ' WHERE id = :id')->execute($f + ['id' => $itemId]);
    wl_reminders_nachziehen($itemId);
    wl_item_edit_delete($itemId);
    return true;
}

/**
 * Absagen bzw. eine Absage zurücknehmen. Der Status bleibt 'live' – abgesagt ist eine
 * BANDEROLE, kein Verschwinden: Wer den Beitrag gesehen hat, soll auch die Absage sehen.
 * Beim Zurücknehmen wird der Push-Merker zurückgesetzt: Wird später erneut abgesagt,
 * bekommen die Erinnerten wieder Bescheid.
 */
function wl_item_absage(int $id, bool $an): void
{
    if ($an) {
        wl_db()->prepare('UPDATE items SET abgesagt = 1 WHERE id = ?')->execute([$id]);
    } else {
        wl_db()->prepare('UPDATE items SET abgesagt = 0, absage_push_done = 0 WHERE id = ?')->execute([$id]);
    }
}

/**
 * Bescheid an die Gruppe: Sie soll erfahren, was aus ihrer Einreichung geworden ist – eine
 * gespeicherte Ablehnungs-Begründung nützt niemandem, die sie nie zu sehen bekommt.
 * Vier Anlässe, ein Helfer: freigegeben, abgelehnt
 * (mit Begründung), Änderung übernommen, Änderung verworfen. Läuft über den gemeinsamen
 * Mail-Topf (wl_mail, Quelle „waslaeuft"); ohne hinterlegte Gruppen-Mail passiert nichts.
 * Rückgabe: Mail ist raus?
 */
function wl_item_bescheid(int $itemId, string $art, string $grund = ''): bool
{
    // Der Schalter aus der Verwaltung (Einstellungen → Mails an die Veranstalter). Die
    // Zugangs-/Passwort-Mails hängen bewusst NICHT daran – ohne die käme niemand mehr rein.
    if (wl_setting('bescheid_mails', '1') !== '1') return false;
    $it = wl_item($itemId);
    if (!$it) return false;
    $org = wl_org((int)($it['org_id'] ?? 0));
    if (!$org || trim((string)$org['mail']) === '') return false;

    $titel  = (string)$it['title'];
    // „Titel" einmal fertig gesetzt – das gerade Schluss-Zeichen bricht sonst die
    // doppelt-quotierten Mailtexte.
    $anf    = '„' . $titel . '"';
    $link   = wl_share_base() . '/v.php?id=' . $itemId;
    $teilen = wl_share_base() . '/teilen.php?e=' . $itemId;
    $login  = wl_share_base() . '/einreichen.php';
    $kontakt = trim(wl_setting('kontakt_mail', ''));
    $fuss = "\n\n— was.läuft, der Veranstaltungskalender für " . wl_ort()
          . ($kontakt !== '' ? "\nFragen? " . $kontakt : '');

    switch ($art) {
        case 'live':
            $betreff = 'was.läuft: „' . $titel . '" ist online';
            $text = "Gute Nachricht: Euer Beitrag " . $anf . " ist freigegeben und steht öffentlich.\n\n"
                  . "Ansehen:      " . $link . "\n"
                  . "Weitersagen:  " . $teilen . "\n\n"
                  . "Auf der Weitersagen-Seite wartet fertiges Werbematerial aus euren Angaben – "
                  . "Insta-Bilder, QR-Code, Web-Banner und Begleittext.";
            break;
        case 'abgelehnt':
            $betreff = 'was.läuft: „' . $titel . '" wurde nicht freigegeben';
            $text = "Euer Beitrag " . $anf . " wurde nicht freigegeben.\n\n"
                  . "Begründung des AStA:\n" . trim($grund) . "\n\n"
                  . "Ihr könnt den Beitrag in eurem Bereich überarbeiten und neu einreichen:\n" . $login;
            break;
        case 'edit_ok':
            $betreff = 'was.läuft: Eure Änderung an „' . $titel . '" ist übernommen';
            $text = "Eure Änderung an " . $anf . " ist freigegeben – der Beitrag zeigt jetzt "
                  . "den neuen Stand.\n\nAnsehen: " . $link;
            break;
        case 'edit_nein':
            $betreff = 'was.läuft: Eure Änderung an „' . $titel . '" wurde nicht übernommen';
            $text = "Eure Änderung an " . $anf . " wurde nicht übernommen – die bisherige "
                  . "Fassung bleibt online.\n\nAnsehen: " . $link . "\n\n"
                  . "Ihr könnt sie in eurem Bereich jederzeit erneut bearbeiten:\n" . $login;
            break;
        default:
            return false;
    }
    return wl_mail((string)$org['mail'], $betreff, $text . $fuss);
}

/** Freigeben. Rückgabe: hat sich etwas geändert? */
function wl_item_freigeben(int $id): bool
{
    $s = wl_db()->prepare("UPDATE items SET status = 'live', note = '',
                           decided_at = datetime('now','localtime') WHERE id = ? AND status <> 'live'");
    $s->execute([$id]);
    return $s->rowCount() > 0;
}

/** Ablehnen – mit Begründung, denn eine Ablehnung ohne Grund ist eine Zumutung. */
function wl_item_ablehnen(int $id, string $grund): bool
{
    $s = wl_db()->prepare("UPDATE items SET status = 'rejected', note = ?,
                           decided_at = datetime('now','localtime') WHERE id = ?");
    $s->execute([mb_substr(trim($grund), 0, 500), $id]);
    return $s->rowCount() > 0;
}

function wl_item_featured(int $id, bool $an): void
{
    wl_db()->prepare('UPDATE items SET featured = ? WHERE id = ?')->execute([$an ? 1 : 0, $id]);
}

/** Beitrag samt zugehörigem (nicht geteiltem) Bild löschen. Offene „Erinnere mich"-Zeilen
 *  gehen mit – sonst versuchte der Cron, an einen gelöschten Beitrag zu erinnern. */
function wl_item_delete(int $id): void
{
    $it = wl_item($id);
    if (!$it) return;
    $bild = (int)($it['image_id'] ?? 0);
    wl_db()->prepare('DELETE FROM push_reminders WHERE item_id = ?')->execute([$id]);
    wl_db()->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
    if ($bild > 0) wl_image_delete_if_unused($bild);
}

// -----------------------------------------------------------------------------------------------
// Banner
// -----------------------------------------------------------------------------------------------

/** Banner, die JETZT laufen – Zeitraum leer heißt „ab sofort" bzw. „ohne Ende". */
function wl_banners_active(): array
{
    return wl_db()->query("SELECT b.*, im.file AS bild FROM banners b
        LEFT JOIN images im ON im.id = b.image_id
        WHERE b.active = 1
          AND (b.von = '' OR date(b.von) <= date('now','localtime'))
          AND (b.bis = '' OR date(b.bis) >= date('now','localtime'))
        ORDER BY b.sort, b.id DESC")->fetchAll();
}

function wl_banners_all(): array
{
    return wl_db()->query('SELECT b.*, im.file AS bild FROM banners b
        LEFT JOIN images im ON im.id = b.image_id ORDER BY b.sort, b.id DESC')->fetchAll();
}

// -----------------------------------------------------------------------------------------------
// Aufräumen
// -----------------------------------------------------------------------------------------------

/**
 * Abgelaufene Beiträge nach der Frist löschen – samt ihrer Bilder, sofern die nicht anderweitig
 * gebraucht werden. Was noch läuft, bleibt; Archivbilder bleiben immer.
 */
function wl_prune(int $tage = WL_KEEP_DAYS): int
{
    $tage = max(7, $tage);
    $weg = 0;
    try {
        $alt = wl_db()->prepare("SELECT id FROM items
            WHERE (CASE WHEN kind = 'kurs'
                        THEN (bis_datum <> '' AND date(bis_datum) < date('now','localtime','-' || ? || ' day'))
                        ELSE date(CASE WHEN ends_at <> '' THEN ends_at ELSE starts_at END)
                             < date('now','localtime','-' || ? || ' day')
                   END)");
        $alt->execute([$tage, $tage]);
        foreach ($alt->fetchAll() as $r) { wl_item_delete((int)$r['id']); $weg++; }
    } catch (\Throwable $e) { /* Aufräumen darf nie eine Seite kosten */ }
    return $weg;
}

// -----------------------------------------------------------------------------------------------
// Bilder
// -----------------------------------------------------------------------------------------------

/**
 * Welcher Weg steht auf DIESEM Server zum Verkleinern zur Verfügung?
 *
 * Mittwald listet ImageMagick 7.1.1 (mit HEIC und AVIF) unter der installierten Software. Das sagt
 * aber nur, dass das PROGRAMM da ist – ob PHP die `imagick`-Erweiterung geladen hat, ist eine ganz
 * andere Frage, und `exec()` ist auf Shared Hosting oft gesperrt. Deshalb wird nichts angenommen,
 * sondern der Reihe nach geprüft:
 *   1. imagick  – die PHP-Erweiterung, kann am meisten (auch HEIC von iPhones)
 *   2. gd       – fast überall dabei, reicht für JPEG/PNG/WebP
 *   3. cli      – das Programm über exec(), falls die Erweiterungen fehlen
 *   4. ''       – nichts davon: Uploads werden abgelehnt, das Archiv funktioniert trotzdem
 * Die Diagnose zeigt das Ergebnis, damit man nicht raten muss.
 */
function wl_image_engine(): string
{
    static $engine = null;
    if ($engine !== null) return $engine;

    if (extension_loaded('imagick') && class_exists('Imagick'))      return $engine = 'imagick';
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) return $engine = 'gd';
    if (wl_cli_magick() !== '')                                       return $engine = 'cli';
    return $engine = '';
}

/** Pfad zum ImageMagick-Programm – '' wenn exec() gesperrt ist oder nichts gefunden wird. */
function wl_cli_magick(): string
{
    static $bin = null;
    if ($bin !== null) return $bin;
    $bin = '';
    if (!function_exists('exec')) return $bin;
    // exec() kann per disable_functions abgeschaltet sein, ohne dass function_exists das merkt.
    $aus = strtolower((string)@ini_get('disable_functions'));
    if ($aus !== '' && in_array('exec', array_map('trim', explode(',', $aus)), true)) return $bin;
    foreach (['magick', 'convert'] as $kandidat) {
        $pfad = [];
        @exec('command -v ' . escapeshellarg($kandidat) . ' 2>/dev/null', $pfad, $code);
        if ($code === 0 && !empty($pfad[0])) return $bin = trim((string)$pfad[0]);
    }
    return $bin;
}

/** Klartext für die Diagnose. */
function wl_image_engine_label(): string
{
    return match (wl_image_engine()) {
        'imagick' => 'PHP-Erweiterung imagick',
        'gd'      => 'PHP-Erweiterung GD',
        'cli'     => 'ImageMagick als Programm (' . wl_cli_magick() . ')',
        default   => 'keine – Bild-Uploads sind nicht möglich',
    };
}

const WL_IMG_MAX_BYTES = 12582912;  // 12 MB roh: heutige Handykameras liegen darüber, aber nicht weit
const WL_ORG_IMG_MAX   = 30;        // eigene Bilder je Veranstalter (ohne Logo)
const WL_IMG_GROSS     = 1600;      // längste Kante der großen Fassung (Detailseite, Banner)
const WL_IMG_KARTE     = 640;       // längste Kante der Kachel-Fassung

/** Erlaubte Uploads. HEIC nur mit imagick/cli – GD kann es nicht. */
function wl_image_types(): array
{
    $t = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (in_array(wl_image_engine(), ['imagick', 'cli'], true)) {
        $t['image/heic'] = 'heic';
        $t['image/heif'] = 'heif';
        $t['image/avif'] = 'avif';
    }
    return $t;
}

/**
 * Hochgeladenes Bild übernehmen: prüfen, verkleinern, in ZWEI Fassungen ablegen (groß + Kachel),
 * Zeile in `images` anlegen. Rückgabe: ['ok'=>bool,'msg'=>string,'id'=>int].
 *
 * Metadaten fliegen dabei raus. Das ist kein Detail: Handyfotos tragen oft GPS-Koordinaten, und ein
 * öffentlich abrufbares Bild mit der Wohnadresse der einreichenden Person wäre ein echter Schaden.
 */
function wl_image_store(array $file, array $opt = []): array
{
    $engine = wl_image_engine();
    if ($engine === '') return ['ok' => false, 'msg' => 'Auf diesem Server können Bilder nicht verarbeitet werden.', 'id' => 0];

    // Der Deckel je Veranstalter (WL_ORG_IMG_MAX, ohne Logo) sitzt HIER, nicht in den
    // Formularen – so gilt er auf jedem Upload-Weg (Promo-Seite wie Einreich-Formular).
    // Ein Logo-Tausch ('logo' im $opt) bleibt frei: Er ersetzt nur, sammelt nicht.
    $orgDeckel = (int)($opt['org_id'] ?? 0);
    if ($orgDeckel > 0 && empty($opt['logo'])) {
        $s = wl_db()->prepare('SELECT COUNT(*) FROM images WHERE org_id = ? AND stock = 0
                               AND id <> COALESCE((SELECT logo_id FROM orgs WHERE id = ?), 0)');
        $s->execute([$orgDeckel, $orgDeckel]);
        if ((int)$s->fetchColumn() >= WL_ORG_IMG_MAX) {
            return ['ok' => false, 'msg' => 'Euer Bild-Kontingent ist voll (' . WL_ORG_IMG_MAX
                . ' Bilder). Löscht auf der Promo-Seite erst welche, die ihr nicht mehr braucht.', 'id' => 0];
        }
    }

    $fehler = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fehler === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'msg' => 'Keine Datei gewählt.', 'id' => 0];
    if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'msg' => 'Das Bild ist zu groß (höchstens ' . round(WL_IMG_MAX_BYTES / 1048576) . ' MB).', 'id' => 0];
    }
    if ($fehler !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => 'Der Upload ist fehlgeschlagen.', 'id' => 0];

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        // is_uploaded_file schützt davor, dass jemand über einen anderen Weg einen Serverpfad
        // unterschiebt und wir eine beliebige Datei aus dem Dateisystem einlesen.
        return ['ok' => false, 'msg' => 'Der Upload ist fehlgeschlagen.', 'id' => 0];
    }
    if ((int)($file['size'] ?? 0) > WL_IMG_MAX_BYTES) {
        return ['ok' => false, 'msg' => 'Das Bild ist zu groß (höchstens ' . round(WL_IMG_MAX_BYTES / 1048576) . ' MB).', 'id' => 0];
    }

    // Der Typ kommt NIE aus dem Browser – der Dateiname und der gemeldete Typ sind frei erfindbar.
    $mime = wl_mime_of($tmp);
    $erlaubt = wl_image_types();
    if (!isset($erlaubt[$mime])) {
        return ['ok' => false, 'msg' => 'Nur JPEG, PNG oder WebP' .
            (isset($erlaubt['image/heic']) ? ' (und HEIC vom iPhone)' : '') . '.', 'id' => 0];
    }

    $name  = bin2hex(random_bytes(16));
    $gross = WL_IMG_DIR . '/' . $name . '.jpg';
    $karte = WL_IMG_DIR . '/' . $name . '_k.jpg';

    $masse = wl_image_convert($tmp, $gross, WL_IMG_GROSS, $engine);
    if ($masse === null) return ['ok' => false, 'msg' => 'Das Bild konnte nicht verarbeitet werden.', 'id' => 0];
    // Die Kachel-Fassung entsteht aus der großen, nicht aus dem Rohbild: einmal weniger dekodieren,
    // und was in der großen Fassung schon sauber aussieht, sieht es klein erst recht.
    if (wl_image_convert($gross, $karte, WL_IMG_KARTE, $engine) === null) {
        @copy($gross, $karte);   // schlimmstenfalls dieselbe Datei – lieber groß als gar kein Bild
    }

    try {
        wl_db()->prepare('INSERT INTO images(file, w, h, bytes, credit, stock, org_id, rights_ok)
                          VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$name, $masse[0], $masse[1], (int)@filesize($gross),
                mb_substr(trim((string)($opt['credit'] ?? '')), 0, 120),
                !empty($opt['stock']) ? 1 : 0,
                (int)($opt['org_id'] ?? 0) ?: null,
                !empty($opt['rights_ok']) ? 1 : 0]);
        return ['ok' => true, 'msg' => 'Bild übernommen.', 'id' => (int)wl_db()->lastInsertId()];
    } catch (\Throwable $e) {
        @unlink($gross); @unlink($karte);
        return ['ok' => false, 'msg' => 'Das Bild konnte nicht gespeichert werden.', 'id' => 0];
    }
}

/** Typ aus dem INHALT bestimmen, nicht aus dem Dateinamen. */
function wl_mime_of(string $pfad): string
{
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $m = (string)@finfo_file($fi, $pfad); @finfo_close($fi); if ($m !== '') return strtolower($m); }
    }
    $g = @getimagesize($pfad);
    if (is_array($g) && !empty($g['mime'])) return strtolower((string)$g['mime']);
    // HEIC erkennt getimagesize nicht – Signatur im Dateikopf nachsehen ('ftypheic' & Co.).
    $kopf = (string)@file_get_contents($pfad, false, null, 0, 32);
    if ($kopf !== '' && str_contains($kopf, 'ftyp')) {
        if (preg_match('/ftyp(heic|heix|hevc|mif1)/', $kopf)) return 'image/heic';
        if (str_contains($kopf, 'ftypavif')) return 'image/avif';
    }
    return '';
}

/**
 * Verkleinern und als JPEG schreiben. Zurück kommen [Breite, Höhe] oder null.
 * Kleiner als das Ziel wird NIE hochgerechnet – ein 400er Bild auf 1600 aufgeblasen sieht schlechter
 * aus als das Original und kostet nur Platz.
 */
function wl_image_convert(string $quelle, string $ziel, int $maxKante, string $engine): ?array
{
    try {
        if ($engine === 'imagick') {
            $im = new Imagick();
            $im->readImage($quelle);
            $im = $im->coalesceImages();          // animierte Vorlagen: nur das erste Bild zählt
            $im->setIteratorIndex(0);
            $im->autoOrient();                    // hochkant fotografiert bleibt hochkant
            $b = $im->getImageWidth(); $h = $im->getImageHeight();
            if (max($b, $h) > $maxKante) {
                $im->resizeImage($b >= $h ? $maxKante : 0, $b >= $h ? 0 : $maxKante, Imagick::FILTER_LANCZOS, 1);
            }
            $im->stripImage();                    // EXIF weg, inklusive GPS
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(82);
            $im->setImageBackgroundColor('white');
            $im = $im->flattenImages();           // PNG mit Transparenz sonst schwarz
            $im->writeImage($ziel);
            $masse = [$im->getImageWidth(), $im->getImageHeight()];
            $im->clear();
            return $masse;
        }

        if ($engine === 'gd') {
            $g = @getimagesize($quelle);
            if (!is_array($g)) return null;
            [$b, $h] = [(int)$g[0], (int)$g[1]];
            if ($b < 1 || $h < 1) return null;
            $bild = match ((string)$g['mime']) {
                'image/jpeg' => @imagecreatefromjpeg($quelle),
                'image/png'  => @imagecreatefrompng($quelle),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($quelle) : false,
                default      => false,
            };
            if (!$bild) return null;
            $f = max($b, $h) > $maxKante ? $maxKante / max($b, $h) : 1.0;
            $nb = max(1, (int)round($b * $f)); $nh = max(1, (int)round($h * $f));
            $neu = imagecreatetruecolor($nb, $nh);
            $weiss = imagecolorallocate($neu, 255, 255, 255);
            imagefilledrectangle($neu, 0, 0, $nb, $nh, $weiss);   // Transparenz auf Weiß legen
            imagecopyresampled($neu, $bild, 0, 0, 0, 0, $nb, $nh, $b, $h);
            $ok = imagejpeg($neu, $ziel, 82);                     // GD schreibt von sich aus kein EXIF mit
            // Kein imagedestroy(): seit PHP 8.0 wirkungslos, seit 8.5 meldet es eine Deprecation.
            // Der Speicher wird freigegeben, sobald die Variablen aus dem Gültigkeitsbereich fallen.
            return $ok ? [$nb, $nh] : null;
        }

        if ($engine === 'cli') {
            $bin = wl_cli_magick();
            if ($bin === '') return null;
            // Nur Pfade wandern in die Kommandozeile, und die kommen aus dem Code, nicht aus dem
            // Formular – trotzdem konsequent escapeshellarg, damit das auch so bleibt.
            $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($quelle . '[0]')
                 . ' -auto-orient -strip -background white -flatten'
                 . ' -resize ' . escapeshellarg($maxKante . 'x' . $maxKante . '>')
                 . ' -quality 82 ' . escapeshellarg('jpeg:' . $ziel) . ' 2>/dev/null';
            @exec($cmd, $aus, $code);
            if ($code !== 0 || !is_file($ziel)) return null;
            $g = @getimagesize($ziel);
            return is_array($g) ? [(int)$g[0], (int)$g[1]] : [0, 0];
        }
    } catch (\Throwable $e) { /* unten: null */ }
    return null;
}

function wl_image(int $id): ?array
{
    if ($id <= 0) return null;
    $s = wl_db()->prepare('SELECT * FROM images WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Das Archiv lizenzfreier Bilder – steht ALLEN Einreichenden zur Auswahl. */
function wl_stock_images(): array
{
    return wl_db()->query('SELECT * FROM images WHERE stock = 1 ORDER BY id DESC')->fetchAll();
}

/** Bilder, die diesem Veranstalter gehören, plus das Archiv – das darf er/sie auswählen.
 *  Das LOGO der Gruppe bleibt draußen: Es ist Absender-Marke, kein Veranstaltungsbild. */
function wl_images_for_org(int $orgId): array
{
    $s = wl_db()->prepare('SELECT * FROM images WHERE (stock = 1 OR org_id = ?)
        AND id <> COALESCE((SELECT logo_id FROM orgs WHERE id = ?), 0) ORDER BY stock, id DESC');
    $s->execute([$orgId, $orgId]);
    return $s->fetchAll();
}

/** Nur die EIGENEN Bilder der Gruppe (ohne Archiv, ohne Logo) – für die Promo-Seite. */
function wl_org_bilder(int $orgId): array
{
    $s = wl_db()->prepare('SELECT * FROM images WHERE org_id = ? AND stock = 0
        AND id <> COALESCE((SELECT logo_id FROM orgs WHERE id = ?), 0) ORDER BY id DESC');
    $s->execute([$orgId, $orgId]);
    return $s->fetchAll();
}

/** Logo einer Gruppe setzen (0 = entfernen). Das ALTE Logo-Bild wird abgeräumt, wenn es
 *  nirgends sonst hängt – ein Logo-Tausch soll keine Dateileichen sammeln. */
function wl_org_logo_setzen(int $orgId, int $imageId): void
{
    $alt = (int)(wl_org($orgId)['logo_id'] ?? 0);
    wl_db()->prepare('UPDATE orgs SET logo_id = ? WHERE id = ?')
        ->execute([$imageId > 0 ? $imageId : null, $orgId]);
    if ($alt > 0 && $alt !== $imageId) wl_image_delete_if_unused($alt);
}

/** Voller Pfad zu einer Bilddatei ('' wenn es sie nicht gibt). $gross=false liefert die Kachel. */
function wl_image_path(string $file, bool $gross = true): string
{
    // Der Dateiname steht so in der Datenbank, wie ihn wl_image_store() erzeugt hat: 32 Hex-Zeichen.
    // Trotzdem geprüft – ein Pfadanteil hier wäre ein Weg zu beliebigen Dateien auf dem Server.
    if (!preg_match('/^[0-9a-f]{32}$/', $file)) return '';
    $p = WL_IMG_DIR . '/' . $file . ($gross ? '' : '_k') . '.jpg';
    return is_file($p) ? $p : '';
}

/**
 * Bild löschen, wenn es nirgends mehr hängt. Archivbilder bleiben immer – sie gehören dem AStA
 * und sollen nicht verschwinden, nur weil eine Veranstaltung vorbei ist.
 */
function wl_image_delete_if_unused(int $id): bool
{
    $img = wl_image($id);
    if (!$img || (int)$img['stock'] === 1) return false;
    $s = wl_db()->prepare('SELECT COUNT(*) FROM items WHERE image_id = ?');
    $s->execute([$id]);
    if ((int)$s->fetchColumn() > 0) return false;
    $s = wl_db()->prepare('SELECT COUNT(*) FROM banners WHERE image_id = ?');
    $s->execute([$id]);
    if ((int)$s->fetchColumn() > 0) return false;

    foreach ([true, false] as $gross) {
        $p = wl_image_path((string)$img['file'], $gross);
        if ($p !== '') @unlink($p);
    }
    wl_db()->prepare('DELETE FROM images WHERE id = ?')->execute([$id]);
    return true;
}

/**
 * Bilddateien ohne Datenbankzeile (und umgekehrt) finden – für die Diagnose.
 * Rückgabe: ['dateien_ohne_eintrag' => int, 'eintraege_ohne_datei' => int].
 */
function wl_image_waisen(): array
{
    $bekannt = [];
    foreach (wl_db()->query('SELECT file FROM images')->fetchAll() as $r) $bekannt[(string)$r['file']] = true;
    $ohneEintrag = 0;
    foreach (glob(WL_IMG_DIR . '/*.jpg') ?: [] as $p) {
        $n = basename($p, '.jpg');
        if (str_ends_with($n, '_k')) $n = substr($n, 0, -2);
        if (!isset($bekannt[$n])) $ohneEintrag++;
    }
    $ohneDatei = 0;
    foreach (array_keys($bekannt) as $n) if (wl_image_path($n) === '') $ohneDatei++;
    return ['dateien_ohne_eintrag' => $ohneEintrag, 'eintraege_ohne_datei' => $ohneDatei];
}

// -----------------------------------------------------------------------------------------------
// Testdatensatz (nur für die Diagnose)
//
// Zweck: Eine leere Veranstaltungsseite kann man nicht beurteilen. Dieser Datensatz füllt sie mit
// allem, was vorkommen KANN – jede Kategorie, beide Inhaltstypen, jede Veranstalter-Art, mit und
// ohne Bild, kostenlos und kostenpflichtig, hervorgehoben, Banner, wartende und abgelehnte
// Beiträge, dazu ein paar Grenzfälle (sehr langer Titel, offenes Ende, Altersgrenze, vorbei).
//
// Alles trägt `demo = 1` und lässt sich damit vollständig wieder entfernen. Das ist der Grund für
// die Spalte: Ein Testdatensatz, den man nur mühsam wieder loswird, landet irgendwann versehentlich
// öffentlich – und das hier IST die öffentliche Seite.
//
// Nebeneffekt, der Absicht ist: Beim Anlegen laufen echte Bilder durch die echte Pipeline. Wer den
// Datensatz auf dem Server erzeugt, weiß danach, ob Bildverarbeitung dort funktioniert.
// -----------------------------------------------------------------------------------------------

/** Wie viele Testdaten liegen gerade herum? */
function wl_demo_counts(): array
{
    $z = static function (string $tab): int {
        try { return (int)wl_db()->query('SELECT COUNT(*) FROM ' . $tab . ' WHERE demo = 1')->fetchColumn(); }
        catch (\Throwable $e) { return 0; }
    };
    return ['orgs' => $z('orgs'), 'items' => $z('items'), 'images' => $z('images'), 'banners' => $z('banners')];
}

function wl_demo_vorhanden(): bool
{
    return array_sum(wl_demo_counts()) > 0;
}

/**
 * Ein Quellbild besorgen. ERSTE WAHL ist ein echtes Foto aus assets/wl-standard/: Der
 * Testdatensatz soll zeigen, wie die Seite wirklich aussieht. Nur wenn die Datei fehlt, wird
 * ein Verlauf gemalt – mit dem, was der Server hergibt. Zurück kommt ein Pfad in einer
 * temporären Datei oder ''.
 */
function wl_demo_bildquelle(string $datei, array $ton): string
{
    $ziel = tempnam(sys_get_temp_dir(), 'wldemo') . '.jpg';

    $foto = __DIR__ . '/assets/wl-standard/' . $datei;
    if ($datei !== '' && is_file($foto) && @copy($foto, $ziel)) return $ziel;

    [$r, $g, $b] = $ton;

    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(1400, 900);
        for ($y = 0; $y < 900; $y++) {
            $f = $y / 900;
            $c = imagecolorallocate($im, (int)($r * (1 - $f * .55)), (int)($g * (1 - $f * .5)), (int)($b * (1 - $f * .35)));
            imagefilledrectangle($im, 0, $y, 1400, $y + 1, $c);
        }
        // Ein paar helle Kreise, damit das Bild beim Verkleinern etwas zu tun hat und nicht wie
        // eine Farbfläche aussieht.
        for ($i = 0; $i < 14; $i++) {
            $hell = imagecolorallocatealpha($im, 255, 255, 255, random_int(90, 115));
            imagefilledellipse($im, random_int(0, 1400), random_int(0, 900), random_int(60, 320), random_int(60, 320), $hell);
        }
        imagejpeg($im, $ziel, 88);
        return is_file($ziel) ? $ziel : '';
    }

    if (extension_loaded('imagick') && class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->newPseudoImage(1400, 900, sprintf('gradient:rgb(%d,%d,%d)-rgb(%d,%d,%d)',
                $r, $g, $b, (int)($r * .4), (int)($g * .35), (int)($b * .6)));
            $im->setImageFormat('jpeg');
            $im->writeImage($ziel);
            return is_file($ziel) ? $ziel : '';
        } catch (\Throwable $e) { return ''; }
    }

    $bin = wl_cli_magick();
    if ($bin !== '') {
        $cmd = escapeshellarg($bin) . ' -size 1400x900 '
             . escapeshellarg(sprintf('gradient:rgb(%d,%d,%d)-rgb(%d,%d,%d)', $r, $g, $b, (int)($r * .4), (int)($g * .35), (int)($b * .6)))
             . ' ' . escapeshellarg('jpeg:' . $ziel) . ' 2>/dev/null';
        @exec($cmd, $aus, $code);
        if ($code === 0 && is_file($ziel)) return $ziel;
    }
    return '';
}

/** Ein Demo-Bild durch die echte Pipeline schicken. 0 = hat nicht geklappt. */
function wl_demo_bild(string $datei, array $ton, string $credit, bool $stock, bool $rechteOk = true): int
{
    if (wl_image_engine() === '') return 0;
    $quelle = wl_demo_bildquelle($datei, $ton);
    if ($quelle === '') return 0;

    $name  = bin2hex(random_bytes(16));
    $gross = WL_IMG_DIR . '/' . $name . '.jpg';
    $masse = wl_image_convert($quelle, $gross, WL_IMG_GROSS, wl_image_engine());
    if ($masse === null) { @unlink($quelle); return 0; }
    if (wl_image_convert($gross, WL_IMG_DIR . '/' . $name . '_k.jpg', WL_IMG_KARTE, wl_image_engine()) === null) {
        @copy($gross, WL_IMG_DIR . '/' . $name . '_k.jpg');
    }
    @unlink($quelle);

    wl_db()->prepare('INSERT INTO images(file, w, h, bytes, credit, stock, rights_ok, demo) VALUES(?,?,?,?,?,?,?,1)')
        ->execute([$name, $masse[0], $masse[1], (int)@filesize($gross), $credit, $stock ? 1 : 0, $rechteOk ? 1 : 0]);
    return (int)wl_db()->lastInsertId();
}

/**
 * Den Testdatensatz anlegen. Rückgabe: ['ok','msg','orgs','items','images','banners','bilder_ok'].
 * Wird zweimal aufgerufen, entsteht der Satz NICHT doppelt – vorher wird aufgeräumt.
 */
function wl_demo_seed(): array
{
    wl_db();
    wl_demo_purge();   // sauber neu, statt Schichten übereinander

    $pdo = wl_db();
    $heute = static fn (int $tage, string $zeit = '19:00'): string => date('Y-m-d', strtotime("+$tage days")) . ' ' . $zeit;

    // --- Veranstalter: eine je Art, damit alle vier Farbpunkte vorkommen -------------------------
    $org = static function (string $name, string $kurz, string $art, int $auto, string $mail) use ($pdo): int {
        // Passwort zufällig und NIRGENDS angezeigt – als Testdaten-Veranstalter kann und soll
        // sich niemand anmelden (die .invalid-Mailadressen bekämen ohnehin nie eine Zugangs-Mail).
        $pdo->prepare('INSERT INTO orgs(name, kurz, art, mail, token, auto_ok, note, demo, pass_hash) VALUES(?,?,?,?,?,?,?,1,?)')
            ->execute([$name, $kurz, $art, $mail, wl_token_new(), $auto,
                       'Testdatensatz – kann jederzeit entfernt werden',
                       password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        return (int)$pdo->lastInsertId();
    };
    $oAsta = $org('Studierendenvertretung (Test)', 'S', 'asta',     1, 'was.laeuft@wl-test.invalid');
    $oHsg  = $org('HSG Welcome (Test)',        'H',  'hsg',        0, 'welcome@wl-test.invalid');
    $oFs   = $org('Fachschaft Kultur (Test)',  'FK', 'fachschaft', 0, 'kultur@wl-test.invalid');
    $oExt  = $org('Kulturzentrum (Test)',      'KZ', 'extern',     0, 'kz@wl-test.invalid');

    // --- Bilder: drei fürs Archiv, drei an Beiträgen ---------------------------------------------
    $bilder = [];
    // Als Quellen dienen die Standard-Fotos (assets/wl-standard/) – der Testdatensatz soll die
    // Seite zeigen, wie sie wirklich aussieht. Die Farbtöne dahinter sind nur noch der Notnagel,
    // falls der Ordner auf einem Server fehlt; sie bleiben in der Palette der Seite.
    $bilder['archiv1'] = wl_demo_bild('party-2.jpg',     [255, 217, 61], 'Testbild – Studierendenvertretung', true);
    $bilder['archiv2'] = wl_demo_bild('markt-1.jpg',     [255, 138, 92], 'Testbild – freie Bilddatenbank', true);
    $bilder['archiv3'] = wl_demo_bild('sonstiges-1.jpg', [217, 242, 75], 'Testbild – eigenes Foto', true);
    $bilder['party']   = wl_demo_bild('party-1.jpg',     [255, 92, 114], 'Testbild – Party', false);
    $bilder['kurs']    = wl_demo_bild('sonstiges-2.jpg', [255, 176, 61], 'Testbild – Werkstatt', false);
    // Ein Banner-Bild wird NICHT mehr importiert: Die Banner nutzen die
    // mitgelieferten Motive (banners.std_bild, wl_banner_bilder()) – genau das sollen
    // die Testdaten zeigen, samt der Vorschau-Kacheln in der Verwaltung.
    // Bewusst OHNE bestätigte Rechte: So sieht man in der Freigabe den Warnhinweis, um den es geht.
    $bilder['ohneRechte'] = wl_demo_bild('kultur-3.jpg', [154, 176, 40], 'Testbild – Rechte ungeklärt', false, false);
    $bilderOk = count(array_filter($bilder));

    // --- Beiträge ---------------------------------------------------------------------------------
    $mach = static function (array $d) use ($pdo): int {
        $r = wl_item_save($d);
        if (!$r['ok']) return 0;
        $pdo->prepare('UPDATE items SET org_id = ?, status = ?, featured = ?, demo = 1, note = ? WHERE id = ?')
            ->execute([$d['org_id'], $d['status'] ?? 'live', $d['featured'] ?? 0, $d['note'] ?? '', $r['id']]);
        return $r['id'];
    };

    $n = 0;
    // Hervorgehoben, mit Bild, kostenlos, Altersgrenze, offenes Ende
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oAsta, 'title' => 'Semester-Opening im Alten Kaufhaus',
        'teaser' => 'Drei Floors, Studi-Preise, ab 22 Uhr', 'cat' => 'party', 'starts_at' => $heute(2, '22:00'),
        'ort' => 'Altes Kaufhaus', 'adresse' => 'Rathausplatz 1', 'frei' => 1, 'ab_alter' => 18,
        'image_id' => $bilder['party'], 'featured' => 1,
        'text' => "Das Semester fängt an, und wir feiern das ordentlich.\n\nDrei Floors, Getränke zu Studi-Preisen, Einlass ab 22 Uhr. Anmeldung braucht es keine – kommt einfach vorbei."]);

    // Hervorgehoben, ohne Bild (zeigt das automatische Standard-Foto der Kategorie)
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oFs, 'title' => 'Poetry Slam im Foyer',
        'teaser' => 'Sechs Slammende, ein Publikum, ein Sieger', 'cat' => 'kultur', 'starts_at' => $heute(5, '20:00'),
        'ends_at' => $heute(5, '23:00'), 'ort' => 'Campus-Foyer', 'preis' => '3 €', 'frei' => 0, 'featured' => 1,
        'text' => 'Bühne frei: Sechs Slammende treten gegeneinander an, das Publikum entscheidet.']);

    // Mit Anmeldung (Verweis auf unser eigenes Anmelde-Modul)
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oHsg, 'title' => 'Uni-Volleyball für alle',
        'teaser' => 'Ohne Verein, ohne Können, mit Spaß', 'cat' => 'sport', 'starts_at' => $heute(4, '14:00'),
        'ends_at' => $heute(4, '17:00'), 'ort' => 'Sporthalle', 'frei' => 1, 'barrierefrei' => 1,
        'anmeldung_url' => 'https://beispiel.de/planer/anmeldung/',
        'text' => 'Wir spielen locker, jede und jeder darf mitmachen. Hallenschuhe mitbringen.']);

    // Barrierefrei + eigene Seite
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oHsg, 'title' => 'Sprachcafé — Deutsch & Arabisch',
        'teaser' => 'Reden üben, Leute treffen, Tee trinken', 'cat' => 'bildung', 'starts_at' => $heute(3, '18:00'),
        'ends_at' => $heute(3, '20:00'), 'ort' => 'CIII 248', 'frei' => 1, 'barrierefrei' => 1,
        'url' => 'https://beispiel.de',
        'text' => 'Jede Woche treffen sich hier Leute, die Deutsch oder Arabisch üben wollen. Kein Kurs, kein Druck.']);

    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oExt, 'title' => 'Repair-Café: Bring dein kaputtes Ding',
        'teaser' => 'Reparieren statt wegwerfen', 'cat' => 'ehrenamt', 'starts_at' => $heute(9, '15:00'),
        'ends_at' => $heute(9, '18:00'), 'ort' => 'Kulturzentrum', 'frei' => 1,
        'text' => 'Toaster, Fahrrad, Hose – bring mit, was kaputt ist. Werkzeug und Hilfe sind da.']);

    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oFs, 'title' => 'Bücher- und Klamottenflohmarkt',
        'teaser' => 'Ausmisten und stöbern', 'cat' => 'markt', 'starts_at' => $heute(12, '11:00'),
        'ends_at' => $heute(12, '16:00'), 'ort' => 'Innenhof', 'frei' => 1, 'image_id' => $bilder['archiv2']]);

    // Grenzfall: sehr langer Titel (wird auf 120 Zeichen gekürzt)
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oExt,
        'title' => 'Vortrag mit einem außergewöhnlich langen Titel, der zeigt, wie die Kachel und die Detailseite mit viel Text umgehen, ohne aus dem Layout zu fallen',
        'cat' => 'sonstiges', 'starts_at' => $heute(16, '19:00'), 'ort' => 'Hörsaal 1', 'frei' => 1]);

    // Grenzfall: heute (prüft den „Heute"-Filter)
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oAsta, 'title' => 'Offene Sprechstunde des AStA',
        'teaser' => 'Fragen? Kommt vorbei.', 'cat' => 'bildung', 'starts_at' => $heute(0, '13:00'),
        'ends_at' => $heute(0, '15:00'), 'ort' => 'AStA-Büro', 'frei' => 1, 'barrierefrei' => 1]);

    // Grenzfall: am Wochenende (prüft den Wochenend-Filter)
    $sa = date('Y-m-d', strtotime('saturday this week'));
    if ($sa < date('Y-m-d')) $sa = date('Y-m-d', strtotime('saturday next week'));
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oExt, 'title' => 'Konzert: Drei Bands aus der Pfalz',
        'cat' => 'kultur', 'starts_at' => $sa . ' 20:00', 'ort' => 'Kulturzentrum', 'preis' => '8 € / 5 € ermäßigt',
        'frei' => 0, 'ab_alter' => 16, 'image_id' => $bilder['archiv1']]);

    // Studi-Rabatt + Preis als NACKTE Zahl: Die Detailseite zeigt die
    // „Für Studis"-Zeile, und wl_item_save hängt ans „12" von selbst ein „ €" an.
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oExt, 'title' => 'Kabarett-Abend im Gewölbekeller',
        'teaser' => 'Politisches Kabarett, zwei Stunden Programm', 'cat' => 'kultur',
        'starts_at' => $heute(9, '20:00'), 'ort' => 'Gewölbekeller', 'adresse' => 'Marktstraße 40',
        'preis' => '12', 'studi_rabatt' => '6 € mit Studi-Ausweis an der Abendkasse', 'frei' => 0]);

    // Nur für Studierende: zeigt die eigene Zeile auf der Detailseite. Bewusst ein
    // Beitrag OHNE Preis – die Einschränkung hat nichts mit dem Eintritt zu tun.
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oFs, 'title' => 'Ersti-Kneipentour der Fachschaft',
        'teaser' => 'Vier Kneipen, ein Abend, neue Leute', 'cat' => 'party',
        'starts_at' => $heute(6, '19:30'), 'ort' => 'Treffpunkt Rathausplatz', 'frei' => 1,
        'nur_studis' => 1, 'ab_alter' => 18,
        'text' => 'Wir starten am Rathausplatz und ziehen weiter. Studiausweis nicht vergessen!']);

    // GEWÄHLTES Standard-Foto (items.std_bild,): nicht die Automatik der
    // Kategorie, sondern eine bewusste Wahl aus dem Einreich-Formular.
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oAsta, 'title' => 'Spieleabend im AStA-Café',
        'teaser' => 'Brettspiele, Snacks, gute Gesellschaft', 'cat' => 'sonstiges',
        'starts_at' => $heute(6, '19:00'), 'ort' => 'AStA-Café', 'frei' => 1,
        'std_bild' => 'sonstiges-6.jpg']);

    // --- Kurse ------------------------------------------------------------------------------------
    $n += (bool)$mach(['kind' => 'kurs', 'org_id' => $oFs, 'title' => 'Töpfern für Anfänger:innen',
        'teaser' => 'Sechs Abende an der Scheibe', 'cat' => 'kultur', 'rhythmus' => 'mittwochs 18:00',
        'termine' => 6, 'preis' => '25 € für alle Termine', 'frei' => 0, 'ort' => 'Werkstatt',
        'von_datum' => date('Y-m-d'), 'bis_datum' => date('Y-m-d', strtotime('+90 days')),
        'image_id' => $bilder['kurs'],
        'text' => 'Ton, Scheibe, Brennofen – alles da. Vorkenntnisse braucht es keine.']);

    $n += (bool)$mach(['kind' => 'kurs', 'org_id' => $oHsg, 'title' => 'Gebärdensprache I',
        'teaser' => 'Semesterkurs, Einstieg jederzeit', 'cat' => 'bildung', 'rhythmus' => 'dienstags 16:00',
        'frei' => 1, 'einstieg' => 1, 'barrierefrei' => 1, 'ort' => 'CIII 130',
        'von_datum' => date('Y-m-d'), 'bis_datum' => date('Y-m-d', strtotime('+120 days'))]);

    $n += (bool)$mach(['kind' => 'kurs', 'org_id' => $oAsta, 'title' => 'Chor der Uni Landau',
        'teaser' => 'Offen für alle, auch ohne Noten', 'cat' => 'kultur', 'rhythmus' => 'montags 19:30',
        'frei' => 1, 'einstieg' => 1, 'ort' => 'Aula', 'featured' => 1]);

    $n += (bool)$mach(['kind' => 'kurs', 'org_id' => $oExt, 'title' => 'Lauftreff am Goethepark',
        'cat' => 'sport', 'rhythmus' => 'donnerstags 18:30', 'frei' => 1, 'einstieg' => 1, 'ort' => 'Goethepark']);

    // --- Was NICHT öffentlich ist -----------------------------------------------------------------
    // Zwei wartende Beiträge – einer davon mit Bild OHNE bestätigte Rechte, damit man den
    // Warnhinweis in der Freigabe sieht. Genau darum geht es dort.
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oHsg, 'title' => 'Filmabend: Dokumentarfilm über Wohnraum',
        'teaser' => 'Film und Gespräch', 'cat' => 'kultur', 'starts_at' => $heute(11, '19:30'),
        'ort' => 'Hörsaal 2', 'frei' => 1, 'status' => 'pending', 'image_id' => $bilder['ohneRechte']]);

    // Der zweite Wartende ist zugleich der Fall „nur für Studierende" IN DER FREIGABE: Nur an
    // einem wartenden Beitrag ist das Etikett dort überhaupt zu sehen. Und er ist ein KURS –
    // die Frage gilt für beide Inhaltstypen, nicht nur für Veranstaltungen.
    $n += (bool)$mach(['kind' => 'kurs', 'org_id' => $oExt, 'title' => 'Yoga am Morgen',
        'cat' => 'sport', 'rhythmus' => 'freitags 8:00', 'preis' => '5 € pro Termin', 'frei' => 0,
        'status' => 'pending', 'ort' => 'Kulturzentrum', 'nur_studis' => 1]);

    // Ein abgelehnter Beitrag – zeigt, wie die Begründung mitläuft
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oExt, 'title' => 'Kommerzielle Werbeveranstaltung',
        'cat' => 'sonstiges', 'starts_at' => $heute(20, '18:00'), 'ort' => 'Extern', 'frei' => 0,
        'status' => 'rejected', 'note' => 'Rein kommerziell – passt nicht auf eine Studi-Seite.']);

    // Ein Beitrag, der VORBEI ist: taucht öffentlich nicht auf und wird vom Cron irgendwann geräumt.
    $n += (bool)$mach(['kind' => 'event', 'org_id' => $oAsta, 'title' => 'Vergangene Veranstaltung (Test)',
        'cat' => 'party', 'starts_at' => date('Y-m-d', strtotime('-25 days')) . ' 20:00', 'ort' => 'Irgendwo', 'frei' => 1]);

    // --- Banner ------------------------------------------------------------------------------------
    // Beide mit MITGELIEFERTEM Motiv (banners.std_bild): der laufende zeigt das Konfetti,
    // der künftige das Feuerwerk – so sieht man beide Wege der Bildwahl in der Verwaltung.
    $pdo->prepare('INSERT INTO banners(title, subtitle, image_id, std_bild, url, von, bis, active, sort, demo) VALUES(?,?,?,?,?,?,?,1,1,1)')
        ->execute(['O-Woche 2026', 'Eine Woche Programm für alle Neuen, alles kostenlos', null, 'banner-1.jpg',
                   'https://beispiel.de', '', date('Y-m-d', strtotime('+30 days'))]);
    // Zweiter Banner mit Zeitraum in der Zukunft: läuft NOCH nicht – zeigt, dass die Steuerung greift.
    $pdo->prepare('INSERT INTO banners(title, subtitle, image_id, std_bild, url, von, bis, active, sort, demo) VALUES(?,?,?,?,?,?,?,1,2,1)')
        ->execute(['Sommerfest (läuft noch nicht)', 'Startet erst in zwei Wochen', null, 'banner-4.jpg', '',
                   date('Y-m-d', strtotime('+14 days')), date('Y-m-d', strtotime('+40 days'))]);

    $c = wl_demo_counts();
    return [
        'ok' => true,
        'orgs' => $c['orgs'], 'items' => $c['items'], 'images' => $c['images'], 'banners' => $c['banners'],
        'bilder_ok' => $bilderOk,
        'msg' => sprintf('%d Veranstalter, %d Beiträge, %d Bilder und %d Banner als Testdaten angelegt.',
            $c['orgs'], $c['items'], $c['images'], $c['banners']),
    ];
}

/** Alle Testdaten wieder entfernen – echte Beiträge bleiben unberührt. */
function wl_demo_purge(): array
{
    $pdo = wl_db();
    $weg = ['items' => 0, 'orgs' => 0, 'images' => 0, 'banners' => 0];
    try {
        // Erst die Bilddateien, solange die Namen noch in der Datenbank stehen.
        foreach ($pdo->query('SELECT file FROM images WHERE demo = 1')->fetchAll() as $r) {
            foreach ([true, false] as $gross) {
                $p = wl_image_path((string)$r['file'], $gross);
                if ($p !== '') @unlink($p);
            }
        }
        $lauf = static function (string $sql) use ($pdo): int {
            $s = $pdo->prepare($sql); $s->execute(); return $s->rowCount();
        };
        // Reihenfolge wegen der Fremdschlüssel: erst was auf anderes zeigt.
        $weg['banners'] = $lauf('DELETE FROM banners WHERE demo = 1');
        $weg['items']   = $lauf('DELETE FROM items WHERE demo = 1');
        $weg['orgs']    = $lauf('DELETE FROM orgs WHERE demo = 1');
        $weg['images']  = $lauf('DELETE FROM images WHERE demo = 1');
    } catch (\Throwable $e) { /* Aufräumen darf nie eine Seite kosten */ }
    return $weg;
}

// -----------------------------------------------------------------------------------------------
// Push-Mitteilungen: Erinnerungen („Erinnere mich") und Abos (Veranstalter/Kategorie).
// Kein Konto, kein Name – die Push-Adresse des Browsers ist die Identität. Die Mathematik
// kommt aus push-core.php; hier stehen Schlüssel-Ablage, Abo-Verwaltung und der Versand,
// den cron_waslaeuft.php im Viertelstunden-Takt anstößt.
// -----------------------------------------------------------------------------------------------

/**
 * „Do 7. Aug · 22:00" bzw. „Do 7. Aug · 22:00–02:00". Leeres Datum ergibt ''.
 * Wohnt hier (nicht in wl-lib.php), weil auch der Push-Versand im Cron die Zeile baut –
 * und der lädt die Seiten-Bibliothek nicht.
 */
function wl_datum_lang(string $start, string $ende = ''): string
{
    // Auf Englisch „Thu 7 Aug" statt „Do 7. Aug": Wochentag und Monat stehen fest im Code und
    // sind damit genau der Fall, der übersetzt gehört – ein Datum, das niemand lesen kann,
    // nützt auf einer Veranstaltungsseite gar nichts. Der Cron bleibt außen vor (wl_lang()
    // antwortet dort immer 'de', weil $GLOBALS['wl_public'] fehlt).
    $en = wl_ist_en();
    $wd = $en ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
              : ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    $mo = $en ? ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
              : ['', 'Jan', 'Feb', 'März', 'Apr', 'Mai', 'Juni', 'Juli', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    $ts = strtotime($start);
    if (!$ts) return '';
    $out = $wd[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ($en ? ' ' : '. ') . $mo[(int)date('n', $ts)];
    if (date('H:i', $ts) !== '00:00') {
        $out .= ' · ' . date('H:i', $ts);
        $te = $ende !== '' ? strtotime($ende) : false;
        if ($te && date('Y-m-d', $te) === date('Y-m-d', $ts)) $out .= '–' . date('H:i', $te);
    }
    return $out;
}

/**
 * Öffentliches Veranstalter-Profil speichern (einreichen.php, nur die eigene Gruppe).
 * Instagram nimmt Handle ODER volle Adresse entgegen; gespeichert wird, was kam –
 * wl_org_insta_url() macht beim Anzeigen einen Link daraus.
 */
function wl_org_profil_save(int $orgId, string $profil, string $web, string $insta): void
{
    $web = wl_url_norm($web);   // „www.verein.de" reicht – das Schema kommt automatisch davor
    $insta = trim(ltrim(trim($insta), '@'));
    if ($insta !== '' && !preg_match('~^(https?://|[A-Za-z0-9._]{1,30}$)~', $insta)) $insta = '';
    wl_db()->prepare('UPDATE orgs SET profil = ?, web = ?, insta = ? WHERE id = ?')
        ->execute([mb_substr(trim($profil), 0, 600), mb_substr($web, 0, 300), mb_substr($insta, 0, 300), $orgId]);
}

/** Instagram-Angabe (Handle oder Adresse) als anklickbare Adresse. '' wenn nichts da. */
function wl_org_insta_url(array $org): string
{
    $i = trim((string)($org['insta'] ?? ''));
    if ($i === '') return '';
    return preg_match('~^https?://~i', $i) ? $i : 'https://www.instagram.com/' . rawurlencode(ltrim($i, '@')) . '/';
}

/** VAPID-Schlüsselpaar von was.läuft – EIGENE Schlüssel, nicht die der App. */
function wl_push_vapid(): ?array
{
    if (!push_available()) return null;
    $pem = wl_setting('push_vapid_pem');
    $pub = wl_setting('push_vapid_pub');
    if ($pem === '' || $pub === '') {
        $paar = push_core_keypair();
        if (!$paar) return null;
        [$pem, $pub] = [$paar['pem'], $paar['pub']];
        wl_setting_set('push_vapid_pem', $pem);
        wl_setting_set('push_vapid_pub', $pub);
    }
    // Kontakt für die Push-Dienste – bewusst eine eigene Einstellung: Die Adresse aus der
    // Fußzeile ist für Besuchende, diese hier landet nur in einem Protokoll bei Google & Co.
    return ['pem' => $pem, 'pub' => $pub,
            'sub' => 'mailto:' . wl_setting('push_kontakt_mail', '')];
}

/** Gerät speichern/erneuern. Rückgabe: sub_id (0 = abgelehnt). */
/**
 * Zu welchen Gegenstellen darf eine Push-Adresse überhaupt zeigen?
 *
 * Ginge JEDE https-Adresse durch, ließe sich der Server als Bote für beliebige Ziele
 * einspannen – der Cron ruft die Adresse später auf – und die Tabelle mit Fantasie-Adressen
 * füllen. Diese Liste sind die Push-Dienste, die es tatsächlich gibt; ein neuer Browser
 * braucht hier einen Eintrag (im Fehlerfall: Abo lässt sich nicht anlegen, sonst passiert nichts).
 */
function wl_push_hosts(): array
{
    return ['push.services.mozilla.com', 'fcm.googleapis.com', 'android.googleapis.com',
            'notify.windows.com', 'push.apple.com'];
}

function wl_push_host_ok(string $endpoint): bool
{
    // Auch das Schema hier prüfen, nicht nur beim Aufrufer: Der Helfer soll für sich allein
    // eine ehrliche Antwort geben, wenn ihn später jemand anderes benutzt.
    if (strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') return false;
    $host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
    if ($host === '') return false;
    foreach (wl_push_hosts() as $erlaubt) {
        if ($host === $erlaubt || str_ends_with($host, '.' . $erlaubt)) return true;
    }
    return false;
}

function wl_push_sub_save(string $endpoint, string $p256dh, string $auth): int
{
    if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 1000) return 0;
    if (!wl_push_host_ok($endpoint)) return 0;
    if (strlen(b64u_decode($p256dh)) !== 65 || strlen(b64u_decode($auth)) !== 16) return 0;
    wl_db()->prepare('INSERT INTO push_subs(endpoint, p256dh, auth) VALUES(?,?,?)
                      ON CONFLICT(endpoint) DO UPDATE SET p256dh = excluded.p256dh, auth = excluded.auth')
        ->execute([$endpoint, $p256dh, $auth]);
    return (int)(wl_push_sub_by_endpoint($endpoint)['id'] ?? 0);
}

function wl_push_sub_by_endpoint(string $endpoint): ?array
{
    $s = wl_db()->prepare('SELECT * FROM push_subs WHERE endpoint = ?');
    $s->execute([$endpoint]);
    $r = $s->fetch();
    return $r ?: null;
}

/** Gerät samt Erinnerungen und Abos entsorgen (Fremdschlüssel räumen mit ab). */
function wl_push_sub_delete(int $subId): void
{
    wl_db()->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$subId]);
}

/**
 * Wann erinnert wird – ABGELEITET aus dem Beginn, nichts zum Einstellen: 2 Stunden vorher,
 * bei Terminen ohne Uhrzeit am selben Tag um 09:00. Liegt das schon hinter uns, der Beginn
 * aber noch vor uns, wird „sofort" daraus (der nächste Cron-Lauf stellt zu).
 * '' heißt: nicht erinnerbar (Kurs, vorbei, kaputtes Datum).
 */
function wl_remind_at(array $it): string
{
    if (($it['kind'] ?? 'event') !== 'event') return '';
    $ts = strtotime((string)($it['starts_at'] ?? ''));
    if (!$ts || $ts <= time()) return '';
    $ra = date('H:i', $ts) === '00:00' ? strtotime(date('Y-m-d', $ts) . ' 09:00') : $ts - 2 * 3600;
    return date('Y-m-d H:i', max($ra, time()));
}

/** „Erinnere mich" an/aus. Rückgabe: ['ok' => bool, 'remind_at' => 'Y-m-d H:i'|'']. */
function wl_reminder_toggle(int $subId, array $it, bool $an): array
{
    $itemId = (int)($it['id'] ?? 0);
    if ($subId <= 0 || $itemId <= 0) return ['ok' => false, 'remind_at' => ''];
    if (!$an) {
        wl_db()->prepare('DELETE FROM push_reminders WHERE sub_id = ? AND item_id = ?')->execute([$subId, $itemId]);
        return ['ok' => true, 'remind_at' => ''];
    }
    $ra = wl_remind_at($it);
    if ($ra === '') return ['ok' => false, 'remind_at' => ''];
    wl_db()->prepare('INSERT INTO push_reminders(sub_id, item_id, remind_at, sent) VALUES(?,?,?,0)
                      ON CONFLICT(sub_id, item_id) DO UPDATE SET remind_at = excluded.remind_at, sent = 0')
        ->execute([$subId, $itemId, $ra]);
    return ['ok' => true, 'remind_at' => $ra];
}

/** Nach einer Terminänderung: offene Erinnerungen auf den neuen Beginn stellen. */
function wl_reminders_nachziehen(int $itemId): void
{
    try {
        $it = wl_item($itemId);
        if (!$it) return;
        $ra = wl_remind_at($it);
        if ($ra === '') {
            // Beitrag ist jetzt in der Vergangenheit (oder ein Kurs geworden): Offenes verfällt.
            wl_db()->prepare('DELETE FROM push_reminders WHERE item_id = ? AND sent = 0')->execute([$itemId]);
            return;
        }
        wl_db()->prepare('UPDATE push_reminders SET remind_at = ? WHERE item_id = ? AND sent = 0')
            ->execute([$ra, $itemId]);
    } catch (\Throwable $e) { /* Erinnerungen dürfen das Speichern nie kosten */ }
}

/** Abo an/aus. org_id 0 = alle Veranstalter, cat '' = alle Kategorien. */
function wl_follow_toggle(int $subId, int $orgId, string $cat, bool $an): bool
{
    if ($subId <= 0) return false;
    if ($cat !== '' && !isset(wl_cats()[$cat])) return false;
    if ($orgId < 0) return false;
    if ($orgId > 0) {
        $o = wl_org($orgId);
        if (!$o || (int)$o['active'] !== 1) return false;
    }
    if (!$an) {
        wl_db()->prepare('DELETE FROM push_follows WHERE sub_id = ? AND org_id = ? AND cat = ?')
            ->execute([$subId, $orgId, $cat]);
        return true;
    }
    // Deckel gegen Unfug: mehr als 30 Abos braucht kein Gerät.
    $s = wl_db()->prepare('SELECT COUNT(*) FROM push_follows WHERE sub_id = ?');
    $s->execute([$subId]);
    if ((int)$s->fetchColumn() >= 30) return false;
    wl_db()->prepare('INSERT INTO push_follows(sub_id, org_id, cat) VALUES(?,?,?)
                      ON CONFLICT(sub_id, org_id, cat) DO NOTHING')->execute([$subId, $orgId, $cat]);
    return true;
}

/** Alles, was ein Gerät eingestellt hat – für die Knopf-Zustände und die Abo-Seite. */
function wl_push_state(int $subId): array
{
    $r = wl_db()->prepare("SELECT r.item_id, r.remind_at, i.title, i.starts_at
                           FROM push_reminders r JOIN items i ON i.id = r.item_id
                           WHERE r.sub_id = ? AND r.sent = 0 AND i.status = 'live'
                           ORDER BY i.starts_at");
    $r->execute([$subId]);
    $f = wl_db()->prepare('SELECT f.org_id, f.cat, o.name AS org_name
                           FROM push_follows f LEFT JOIN orgs o ON o.id = f.org_id
                           WHERE f.sub_id = ? ORDER BY o.name COLLATE NOCASE, f.cat');
    $f->execute([$subId]);
    return ['reminders' => $r->fetchAll(), 'follows' => $f->fetchAll()];
}

/** Versand an EIN Gerät mit den wl-Schlüsseln. Rückgabe: HTTP-Code (0 = Transportfehler). */
function wl_push_send_to(array $sub, string $json, int $ttl = 86400): int
{
    $vapid = wl_push_vapid();
    if (!$vapid) return 0;
    return push_core_deliver($sub, $json, $vapid, $ttl);
}

/** Payload bauen – Klick führt über die schöne Kurz-Adresse zum Beitrag. */
function wl_push_payload(string $titel, string $text, int $itemId): string
{
    if (mb_strlen($text) > 180) $text = mb_substr($text, 0, 177) . '…';
    return (string)json_encode([
        'title' => $titel,
        'body'  => $text,
        'url'   => wl_share_base() . '/v.php?id=' . $itemId,
    ], JSON_UNESCAPED_UNICODE);
}

/** Zahlen für Verwaltung und Diagnose. */
function wl_push_zahlen(): array
{
    try {
        $q = static fn (string $sql): int => (int)wl_db()->query($sql)->fetchColumn();
        return [
            'geraete'      => $q('SELECT COUNT(*) FROM push_subs'),
            'abos'         => $q('SELECT COUNT(*) FROM push_follows'),
            'erinnerungen' => $q('SELECT COUNT(*) FROM push_reminders WHERE sent = 0'),
        ];
    } catch (\Throwable $e) { return ['geraete' => 0, 'abos' => 0, 'erinnerungen' => 0]; }
}

/**
 * Der Versand-Lauf des Crons: fällige Erinnerungen, dann neue Live-Beiträge an passende Abos.
 * Alles ist aus dem Datenbestand ABGELEITET (remind_at fällig / push_done = 0) – der Lauf ist
 * dadurch wiederholbar: Was gesendet ist, wird markiert, was nicht dran ist, bleibt liegen.
 * $limit deckelt die Sendungen pro Lauf; der Rest kommt beim nächsten Viertelstunden-Takt dran.
 */
function wl_push_cron(int $limit = 400): array
{
    $aus = ['erinnerungen' => 0, 'neu' => 0, 'tot' => 0];
    if (!push_available() || !wl_push_vapid()) return $aus;
    $pdo = wl_db();
    $jetzt = date('Y-m-d H:i');

    // Verwaiste Abos (Veranstalter gelöscht) leise abräumen.
    $pdo->exec('DELETE FROM push_follows WHERE org_id <> 0 AND org_id NOT IN (SELECT id FROM orgs)');

    $sende = function (array $sub, string $json, int $ttl) use (&$aus): bool {
        $code = wl_push_send_to($sub, $json, $ttl);
        if ($code === 404 || $code === 410) { wl_push_sub_delete((int)$sub['id']); $aus['tot']++; return false; }
        return $code >= 200 && $code < 300;
    };

    // 0) Absagen (VOR den Erinnerungen, sonst könnte im selben Lauf noch ein „Gleich: …"
    //    rausgehen): Wer sich an einen abgesagten Beitrag erinnern lassen wollte, bekommt EINE
    //    Absage-Mitteilung – auch, wenn seine Erinnerung schon geklingelt hat: Gerade dann steht
    //    er sonst vor verschlossener Tür. Danach gelten alle Erinnerungen des Beitrags als
    //    erledigt, und absage_push_done verhindert eine zweite Runde.
    $abges = $pdo->query("SELECT i.id, i.title, i.starts_at, i.ends_at, i.ort FROM items i
                          WHERE i.status = 'live' AND i.abgesagt = 1 AND i.absage_push_done = 0
                            AND i.demo = 0 ORDER BY i.id LIMIT 20")->fetchAll();
    $abFertig = $pdo->prepare('UPDATE items SET absage_push_done = 1 WHERE id = ?');
    $abWer = $pdo->prepare('SELECT DISTINCT s.id, s.endpoint, s.p256dh, s.auth
                            FROM push_reminders r JOIN push_subs s ON s.id = r.sub_id
                            WHERE r.item_id = ?');
    foreach ($abges as $it) {
        $abWer->execute([(int)$it['id']]);
        $subs = $abWer->fetchAll();
        if ($aus['erinnerungen'] + $aus['neu'] + count($subs) > $limit) break;
        $text = wl_datum_lang((string)$it['starts_at'], (string)$it['ends_at']);
        if (trim((string)$it['ort']) !== '') $text .= ' · ' . trim((string)$it['ort']);
        $text = trim('Diese Veranstaltung wurde vom Veranstalter abgesagt. ' . $text);
        $json = wl_push_payload('Abgesagt: ' . (string)$it['title'], $text, (int)$it['id']);
        foreach ($subs as $sub) {
            if ($sende($sub, $json, 86400)) $aus['neu']++;
        }
        $pdo->prepare('UPDATE push_reminders SET sent = 1 WHERE item_id = ?')->execute([(int)$it['id']]);
        $abFertig->execute([(int)$it['id']]);
    }

    // 1) Fällige Erinnerungen. `sent` wird nach dem VERSUCH gesetzt – ein dauerhaft kaputter
    //    Endpoint soll nicht bei jedem Lauf erneut probiert werden (Doppel-Klingeln > Ausfall).
    $s = $pdo->prepare("SELECT r.id AS rid, s.id, s.endpoint, s.p256dh, s.auth,
                               i.id AS item_id, i.title, i.starts_at, i.ends_at, i.ort, i.status, i.kind,
                               i.abgesagt
                        FROM push_reminders r
                        JOIN push_subs s ON s.id = r.sub_id
                        JOIN items i ON i.id = r.item_id
                        WHERE r.sent = 0 AND r.remind_at <= ? ORDER BY r.remind_at LIMIT ?");
    $s->execute([$jetzt, $limit]);
    $fertig = $pdo->prepare('UPDATE push_reminders SET sent = 1 WHERE id = ?');
    foreach ($s->fetchAll() as $r) {
        $start = strtotime((string)$r['starts_at']);
        if ((string)$r['status'] === 'live' && (int)($r['abgesagt'] ?? 0) === 0 && $start && $start > time()) {
            $text = wl_datum_lang((string)$r['starts_at'], (string)$r['ends_at']);
            if (trim((string)$r['ort']) !== '') $text .= ' · ' . trim((string)$r['ort']);
            // Eine Erinnerung ist nach dem Beginn nichts mehr wert – so lange hebt der
            // Push-Dienst sie höchstens für ein offline-Gerät auf.
            $ok = $sende($r, wl_push_payload('Gleich: ' . (string)$r['title'], $text, (int)$r['item_id']),
                max(60, $start - time()));
            if ($ok) $aus['erinnerungen']++;
        }
        $fertig->execute([(int)$r['rid']]);
        if ($aus['erinnerungen'] + $aus['neu'] >= $limit) return $aus;
    }

    // 2) Neue Live-Beiträge an die Abos. Kurse zählen mit (neu im Programm); Events nur,
    //    solange sie noch bevorstehen. Demo-Daten nie.
    // Abgesagtes wird nicht als „Neu" ausgerufen – kommt die Absage zurück, holt der nächste
    // Lauf die Meldung nach (push_done bleibt bis dahin 0).
    $items = $pdo->query("SELECT i.*, o.name AS org_name FROM items i
                          LEFT JOIN orgs o ON o.id = i.org_id
                          WHERE i.status = 'live' AND i.push_done = 0 AND i.demo = 0 AND i.abgesagt = 0
                          ORDER BY i.decided_at LIMIT 20")->fetchAll();
    $done = $pdo->prepare('UPDATE items SET push_done = 1 WHERE id = ?');
    $wer  = $pdo->prepare("SELECT DISTINCT s.id, s.endpoint, s.p256dh, s.auth
                           FROM push_follows f JOIN push_subs s ON s.id = f.sub_id
                           WHERE (f.org_id = 0 OR f.org_id = ?) AND (f.cat = '' OR f.cat = ?)");
    foreach ($items as $it) {
        $istKurs = ($it['kind'] ?? 'event') === 'kurs';
        $start = strtotime((string)$it['starts_at']);
        if (!$istKurs && (!$start || $start <= time())) { $done->execute([(int)$it['id']]); continue; }
        $wer->execute([(int)($it['org_id'] ?? 0), (string)$it['cat']]);
        $subs = $wer->fetchAll();
        // Reicht das Sende-Budget nicht mehr für ALLE Abonnenten dieses Beitrags, bleibt er
        // unmarkiert liegen – halb benachrichtigt wäre schlechter als eine Viertelstunde später.
        if ($aus['erinnerungen'] + $aus['neu'] + count($subs) > $limit) break;
        $text = ($istKurs ? trim((string)$it['rhythmus']) : wl_datum_lang((string)$it['starts_at'], (string)$it['ends_at']));
        if (trim((string)$it['ort']) !== '') $text .= ' · ' . trim((string)$it['ort']);
        if (trim((string)($it['org_name'] ?? '')) !== '') $text .= ' · ' . trim((string)$it['org_name']);
        $json = wl_push_payload(($istKurs ? 'Neu im Programm: ' : 'Neu: ') . (string)$it['title'], $text, (int)$it['id']);
        foreach ($subs as $sub) {
            if ($sende($sub, $json, 86400)) $aus['neu']++;
        }
        $done->execute([(int)$it['id']]);
    }
    return $aus;
}

// -----------------------------------------------------------------------------------------------
// Besucherzählung – datensparsam, ohne Cookie, ohne Wiedererkennung über den Tag hinaus
//
// So wenig wie irgend möglich: Aus IP und Browser-Kennung wird zusammen mit einem TAGES-SALZ ein
// Hash gebildet. Das Salz ist Zufall und wird beim ersten Aufruf eines neuen Tages ERSETZT –
// danach lässt sich der gestrige Hash zu keiner IP mehr zurückrechnen, auch von uns nicht. Die
// Merker des Vortages werden beim Rotieren gelöscht; dauerhaft bleiben nur zwei Zahlen je Tag.
//
// Kein Cookie, kein localStorage, keine Adresse, kein Pfad, kein Referrer, kein Gerät, kein
// Standort – die Frage lautet „wie viele Menschen waren da", nicht „wer war das".
// -----------------------------------------------------------------------------------------------

/** Zählt den aktuellen Seitenaufruf. Läuft in wl_head(), also auf jeder öffentlichen Seite. */
/**
 * Ist die Anfrage eine Maschine? EINE Quelle für alle Zählungen – Seiten-Statistik und
 * Aufruf-Zähler je Beitrag müssen dasselbe Publikum meinen.
 */
function wl_ist_bot(): bool
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return true;
    return (bool)preg_match('~bot|crawl|spider|slurp|monitor|preview|headless|curl|wget|'
        . 'facebookexternalhit|whatsapp|telegram|embedly|quora|pinterest|feedfetcher~i', $ua);
}

function wl_stat_hit(): void
{
    static $schon = false;
    if ($schon) return;                                   // je Anfrage höchstens ein Aufruf
    $schon = true;
    if (wl_ist_bot()) return;                             // gefragt sind organische Besucher:innen
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;   // nur echte Seitenaufrufe
    if (isset($_GET['vorschau'])) return;                          // Vorschau aus der Verwaltung

    try {
        $tag = date('Y-m-d');
        // Tages-Salz rotieren (der Helfer räumt dabei alte Merker und Bremsen weg).
        $salz = wl_tages_salz();
        // Die Adresse wird NUR hier verwendet und nirgends gespeichert – auch nicht gekürzt.
        $h = substr(hash('sha256', $salz . '|' . wl_client_ip() . '|' . $ua), 0, 32);

        $neu = wl_db()->prepare('INSERT OR IGNORE INTO stats_seen(h, day) VALUES(?, ?)');
        $neu->execute([$h, $tag]);
        $istNeu = $neu->rowCount() === 1 ? 1 : 0;
        wl_db()->prepare('INSERT INTO stats_days(day, visitors, views) VALUES(?, ?, 1)
            ON CONFLICT(day) DO UPDATE SET visitors = visitors + ?, views = views + 1')
            ->execute([$tag, $istNeu, $istNeu]);
    } catch (\Throwable $e) { /* Zählen darf niemals eine Seite kosten */ }
}

/** Tageszahlen von $von bis $bis (beide YYYY-MM-DD, einschließlich): [day => [visitors, views]]. */
function wl_stats_range(string $von, string $bis): array
{
    try {
        $s = wl_db()->prepare('SELECT day, visitors, views FROM stats_days WHERE day BETWEEN ? AND ? ORDER BY day');
        $s->execute([$von, $bis]);
        $out = [];
        foreach ($s->fetchAll() as $r) $out[(string)$r['day']] = ['visitors' => (int)$r['visitors'], 'views' => (int)$r['views']];
        return $out;
    } catch (\Throwable $e) { return []; }
}

/** Summe über einen Zeitraum: ['visitors' => …, 'views' => …]. Ohne $von = seit Beginn.
 *  ACHTUNG bei den Besucher:innen: Tagessummen lassen sich nicht entdoppeln – wer an drei
 *  Tagen da war, zählt dreimal. Das ist der Preis der Datensparsamkeit und in der Anzeige
 *  auch so benannt („Besuche"). */
function wl_stats_summe(string $von = '', string $bis = ''): array
{
    try {
        $sql = 'SELECT COALESCE(SUM(visitors),0) v, COALESCE(SUM(views),0) p FROM stats_days';
        $p = [];
        if ($von !== '') { $sql .= ' WHERE day >= ?'; $p[] = $von; if ($bis !== '') { $sql .= ' AND day <= ?'; $p[] = $bis; } }
        $s = wl_db()->prepare($sql);
        $s->execute($p);
        $r = $s->fetch() ?: [];
        return ['visitors' => (int)($r['v'] ?? 0), 'views' => (int)($r['p'] ?? 0)];
    } catch (\Throwable $e) { return ['visitors' => 0, 'views' => 0]; }
}

/** Erster Tag mit Zahlen ('' wenn noch nie gezählt wurde) – für „seit …" in der Auswertung. */
function wl_stats_start(): string
{
    try { return (string)(wl_db()->query('SELECT MIN(day) FROM stats_days')->fetchColumn() ?: ''); }
    catch (\Throwable $e) { return ''; }
}


// -----------------------------------------------------------------------------------------------
// Englische Kurzfassung (optional) + gemeldete Beiträge
// -----------------------------------------------------------------------------------------------

/**
 * Dürfen Gruppen eine englische Kurzfassung eintragen? Aus, solange die Betreiber es nicht
 * einschalten – halbe Zweisprachigkeit ist schlimmer als gar keine, und ob sie die Pflege
 * leisten wollen, entscheiden sie selbst.
 */
function wl_en_an(): bool
{
    return wl_setting('en_felder', '0') === '1';
}

/**
 * Titel und Teaser in der Sprache, die zum Gerät passt: Englisch nur, wenn es freigeschaltet
 * ist, etwas Englisches hinterlegt wurde UND der Browser überhaupt Englisch verlangt. Sonst
 * bleibt es bei Deutsch – der Grundsprache dieser Seite.
 */
function wl_will_englisch(): bool
{
    if (!wl_en_an()) return false;
    $al = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($al === '') return false;
    // Erste Sprache der Wunschliste zählt: „en-GB,de;q=0.8" ja, „de,en;q=0.7" nein.
    $erste = trim(explode(',', $al)[0]);
    return str_starts_with($erste, 'en');
}

/** Gründe, aus denen etwas gemeldet werden kann. Kurz gehalten – eine lange Liste liest niemand. */
function wl_report_gruende(): array
{
    // In der Verwaltung sollen die Gründe IMMER deutsch stehen (wl_lang() antwortet dort
    // ohnehin 'de') – nur das öffentliche Formular übersetzt mit.
    $de = [
        'falsch'     => 'Angaben stimmen nicht (Termin, Ort, Preis)',
        'abgesagt'   => 'Findet nicht statt',
        'inhalt'     => 'Anstößiger oder diskriminierender Inhalt',
        'rechte'     => 'Bild- oder Urheberrechte verletzt',
        'spam'       => 'Werbung oder Spam',
        'sonstiges'  => 'Etwas anderes',
    ];
    if (!wl_ist_en()) return $de;
    return [
        'falsch'     => 'Details are wrong (date, place, price)',
        'abgesagt'   => 'Not taking place',
        'inhalt'     => 'Offensive or discriminatory content',
        'rechte'     => 'Violates image or copyright',
        'spam'       => 'Advertising or spam',
        'sonstiges'  => 'Something else',
    ];
}

/** Meldung entgegennehmen. Rückgabe ['ok', 'msg']. */
function wl_report_add(int $itemId, string $grund, string $text, string $mail = ''): array
{
    $it = wl_item($itemId);
    if (!$it || (string)$it['status'] !== 'live') return ['ok' => false, 'msg' => 'Diesen Beitrag gibt es nicht (mehr).'];
    if (!isset(wl_report_gruende()[$grund])) $grund = 'sonstiges';
    $text = mb_substr(trim($text), 0, 1000);
    $mail = mb_substr(trim($mail), 0, 200);
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'Die Mailadresse sieht nicht richtig aus – lasst sie sonst einfach leer.'];
    }
    // Bremse am Gerät, damit aus dem Melde-Knopf kein Ärgern-Werkzeug wird.
    if (!wl_bremse('melden', wl_client_ip(), 10, 3600)) {
        return ['ok' => false, 'msg' => 'Das waren gerade viele Meldungen. Bitte später noch einmal.'];
    }
    wl_db()->prepare('INSERT INTO reports(item_id, grund, text, mail) VALUES(?,?,?,?)')
        ->execute([$itemId, $grund, $text, $mail]);
    return ['ok' => true, 'msg' => ''];
}

/** Meldungen für die Verwaltung: offene zuerst, erledigte nur auf Wunsch. */
function wl_reports(bool $auchErledigte = false, int $limit = 200): array
{
    $sql = 'SELECT r.*, i.title AS item_title, i.status AS item_status, o.name AS org_name
            FROM reports r
            LEFT JOIN items i ON i.id = r.item_id
            LEFT JOIN orgs o ON o.id = i.org_id';
    if (!$auchErledigte) $sql .= " WHERE r.done_at = ''";
    $sql .= ' ORDER BY r.done_at = \'\' DESC, r.created_at DESC LIMIT ' . max(1, min(500, $limit));
    return wl_db()->query($sql)->fetchAll();
}

function wl_reports_offen(): int
{
    try { return (int)wl_db()->query("SELECT COUNT(*) FROM reports WHERE done_at = ''")->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
}

/** Erledigt-Haken samt Notiz – oder wieder aufmachen ($erledigt = false). */
function wl_report_done(int $id, bool $erledigt = true, string $notiz = ''): void
{
    wl_db()->prepare('UPDATE reports SET done_at = ?, notiz = ? WHERE id = ?')
        ->execute([$erledigt ? date('Y-m-d H:i:s') : '', mb_substr(trim($notiz), 0, 500), $id]);
}


/**
 * Was liegt seit der letzten Runde neu da? Für die Mitteilung an die zuständigen Referate.
 *
 * Drei Dinge landen im selben Stapel und werden deshalb auch zusammen gemeldet: neue
 * Einreichungen, ÄNDERUNGEN an bereits veröffentlichten Beiträgen (die warten genauso auf
 * eine Entscheidung) und Meldungen zu einem Beitrag.
 *
 * Rückgabe: ['einreichungen' => n, 'aenderungen' => n, 'meldungen' => n] – und die Merker sind
 * danach gesetzt, damit dieselbe Sache nie zweimal gemeldet wird.
 */
function wl_staff_neues_abholen(): array
{
    $out = ['einreichungen' => 0, 'aenderungen' => 0, 'meldungen' => 0];
    try {
        $s = wl_db()->query("SELECT COUNT(*) FROM items WHERE status = 'pending' AND staff_done = 0");
        $out['einreichungen'] = (int)$s->fetchColumn();
        // Nur Änderungen an Beiträgen, die es noch gibt – der JOIN verhindert, dass eine
        // verwaiste Zeile ewig eine Mitteilung auslöst.
        $s = wl_db()->query("SELECT COUNT(*) FROM item_edits e JOIN items i ON i.id = e.item_id
                             WHERE e.staff_done = 0");
        $out['aenderungen'] = (int)$s->fetchColumn();
        $s = wl_db()->query("SELECT COUNT(*) FROM reports WHERE done_at = '' AND staff_done = 0");
        $out['meldungen'] = (int)$s->fetchColumn();
        if ($out['einreichungen'] > 0) {
            wl_db()->exec("UPDATE items SET staff_done = 1 WHERE status = 'pending' AND staff_done = 0");
        }
        if ($out['aenderungen'] > 0) {
            wl_db()->exec("UPDATE item_edits SET staff_done = 1 WHERE staff_done = 0");
        }
        if ($out['meldungen'] > 0) {
            wl_db()->exec("UPDATE reports SET staff_done = 1 WHERE done_at = '' AND staff_done = 0");
        }
    } catch (\Throwable $e) { /* eine Mitteilung darf den Cron nie kippen */ }
    return $out;
}
