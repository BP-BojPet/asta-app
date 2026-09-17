<?php
/**
 * „was.läuft" – Bootstrap des öffentlichen Bereichs.
 *
 * Wie bei Pat:innenprogramm, Umfragen, externen Events und Terminplaner: Die `lib.php` der App
 * wird ABSICHTLICH NICHT eingebunden. Eigene Sitzung, eigener Cookie, eigene Fehlerbehandlung,
 * strenge Content-Security-Policy.
 *
 * Aussehen: EIGENES Stylesheet (assets/wl.css), nicht die style.css der App. Das ist der einzige
 * öffentliche Bereich, der bewusst anders aussieht – er soll wie ein Stadtmagazin wirken und nicht
 * wie ein Verwaltungswerkzeug. Wer hier etwas gestaltet, gestaltet es in wl.css.
 *
 * WICHTIG: Unter dieser CSP gibt es kein 'unsafe-inline'. Ein style="…"-Attribut wird vom Browser
 * stillschweigend verworfen. Alles Gestalterische gehört ins Stylesheet – auch die Platzhalter-
 * farben für Beiträge ohne Bild (siehe wl_placeholder_class()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../wl-db.php';

const WL_LOG_FILE = __DIR__ . '/../data/error.log';

function wl_log(string $msg): void
{
    $where = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
    @file_put_contents(WL_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] WASLAEUFT: ' . trim($msg)
        . ' | ' . trim($where) . "\n", FILE_APPEND | LOCK_EX);
}

error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    if (in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    wl_log('PHP-Warnung: ' . $str . ' in ' . $file . ':' . $line);
    return true;
});
set_exception_handler(function (\Throwable $e) {
    wl_log('Unbehandelte Exception ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<p>Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.</p>';
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        wl_log('FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

function wl_is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/veranstaltungen/index.php'))), '/') . '/';
    // Vom Browser vorgegebene Sitzungs-Kennungen nicht übernehmen: Müll erzeugt sonst
    // Warnungen und eine leere Sitzung, und eine vorgegebene Kennung wäre angreifbar.
    @ini_set('session.use_strict_mode', '1');
    session_name('asta_wl_sid');
    session_set_cookie_params(['path' => $dir, 'httponly' => true, 'samesite' => 'Lax', 'secure' => wl_is_https()]);
    session_start();
}

// Ab hier gilt: Wir sind im ÖFFENTLICHEN Bereich. Nur hier darf wl_lang() Englisch antworten –
// die Verwaltung in der App und der Cron laden dieselbe wl-db.php und müssen deutsch bleiben.
$GLOBALS['wl_public'] = true;

// Umschalter aus der Fußzeile: ?lang=en|de merkt sich die Wahl in der Sitzung und schickt
// zurück auf dieselbe Seite (ohne den Parameter, sonst klebt er in jedem geteilten Link).
if (isset($_GET['lang'])) {
    $wunsch = wl_param($_GET['lang']);
    if ($wunsch === 'de' || $wunsch === 'en') $_SESSION['wl_lang'] = $wunsch;
    $ziel = strtok((string)($_SERVER['REQUEST_URI'] ?? 'index.php'), '?');
    $rest = $_GET; unset($rest['lang']);
    header('Location: ' . $ziel . ($rest ? '?' . http_build_query($rest) : ''));
    exit;
}

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Parameter als Zeichenkette lesen – `?cat[]=x` liefert sonst ein Array. */
function wl_param($v): string
{
    if (is_string($v)) return $v;
    if (is_int($v) || is_float($v)) return (string)$v;
    return '';
}

function wl_csrf_token(): string
{
    if (empty($_SESSION['wl_csrf'])) $_SESSION['wl_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['wl_csrf'];
}

function wl_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(wl_csrf_token()) . '">';
}

function wl_check_csrf(): void
{
    $t = wl_param($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals(wl_csrf_token(), $t)) {
        http_response_code(400);
        exit('Ungültige Anfrage. Bitte die Seite neu laden und noch einmal versuchen.');
    }
}

/** Bremse je Besuchssitzung – Einreichen ist teurer als Blättern, deshalb eigene Töpfe. */
function wl_rate_ok(string $topf, int $proStunde): bool
{
    $jetzt = time();
    $key = 'wl_hits_' . $topf;
    $liste = array_values(array_filter((array)($_SESSION[$key] ?? []), static fn ($t) => $t > $jetzt - 3600));
    if (count($liste) >= $proStunde) { $_SESSION[$key] = $liste; return false; }
    $liste[] = $jetzt;
    $_SESSION[$key] = $liste;
    return true;
}

/**
 * Sieht hier gerade jemand mehr als die Öffentlichkeit? Das ist der Fall, wenn das Portal zu ist
 * oder noch nicht gestartet hat und eine Veranstalter-Gruppe angemeldet ist. Drei Stellen hängen
 * daran: das Band in der Kopfleiste, der Wächter (der solche Besuche durchlässt) und der
 * Zwischenspeicher-Kopf (der eine solche Seite nirgends ablegen lässt).
 */
function wl_intern_sicht(): bool
{
    $modus = wl_mode();
    return ($modus === 'off' || $modus === 'pre') && wl_login_org() !== null;
}

/**
 * Band unter der Kopfleiste – siehe wl_intern_sicht().
 */
function wl_intern_band(): void
{
    if (!wl_intern_sicht()) return;
    $modus = wl_mode();
    $satz = wl_t($modus === 'pre' ? 'intern_pre' : 'intern_off');
    // Im Vorabstart gehört der Starttermin dazu: Er beantwortet die nächste Frage gleich mit.
    $start = $modus === 'pre' ? wl_launch_at() : '';
    if ($start !== '') {
        $zeit = wl_launch_time();
        $satz .= ' ' . str_replace(
            ['{datum}', '{zeit}'],
            [wl_datum_voll($start), $zeit !== '' ? substr($zeit, 0, 5) : ''],
            wl_t($zeit !== '' ? 'intern_ab_uhr' : 'intern_ab')
        );
    }
    echo '<div class="wl-intern"><div class="wl-in"><strong>' . h(wl_t('intern_titel')) . '</strong> '
       . h($satz) . '</div></div>';
}

/**
 * Die angemeldete Veranstalter-Gruppe – oder null. Stillgelegte Gruppen fliegen dabei raus: Wer
 * abgeschaltet wird, soll nicht bis zum Schließen des Browsers weiter angemeldet bleiben.
 */
function wl_login_org(): ?array
{
    $id = (int)($_SESSION['wl_org_id'] ?? 0);
    if ($id <= 0) return null;
    $org = wl_org($id);
    if (!$org || (int)$org['active'] !== 1) { unset($_SESSION['wl_org_id']); return null; }
    return $org;
}

/**
 * Ist die Seite gerade zu? Dann hier aussteigen – aber mit einer richtigen Seite im Look des
 * Portals, nicht mit nacktem HTML. Wer hier landet, hat einen geteilten Link angeklickt und soll
 * sehen, WO er ist und dass es wiederkommt.
 *
 * Bewusst OHNE Kopfleiste und Fußzeile: Deren Links führen alle auf Seiten, die derselbe Wächter
 * ebenfalls abweist – eine Navigation, in der jeder Weg hierher zurückführt, ist eine Falle.
 * Und bewusst ohne wl_head(): Das setzt Vorschaubilder, Sprachlinks und die Vorratsliste des
 * Service Workers für eine Seite, die es gerade nicht gibt.
 *
 * 503 statt 200 ist keine Kosmetik: Suchmaschinen dürfen die Pause nicht als Löschung verstehen.
 */
function wl_offline_guard(bool $vorabOffen = false): bool
{
    $modus = wl_mode();
    if ($modus !== 'off' && $modus !== 'pre') return false;
    // Im Vorabstart bleiben Einreichen und Veranstalter-Bereich offen: Die Gruppen sollen sich
    // anmelden und ihre ersten Veranstaltungen eintragen können, damit am Starttag etwas dasteht.
    if ($modus === 'pre' && $vorabOffen) return false;
    // Wer angemeldet ist, sieht das ganze Portal – sonst könnten die Gruppen ihre eigenen
    // Beiträge nicht ansehen und nicht prüfen, wie sie am Starttag aussehen. Dass die Seite für
    // alle anderen zu ist, sagt ihnen das Band in der Kopfleiste (wl_nav).
    if (wl_login_org()) {
        // Was nur Angemeldete sehen dürfen, darf kein Zwischenspeicher weiterreichen: Sonst
        // liefert ein Proxy die Vorschau später an Leute aus, für die die Seite noch zu ist.
        if (!headers_sent()) header('Cache-Control: private, no-store');
        return false;
    }

    $vorab = $modus === 'pre';
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 3600');
    $mail = trim(wl_setting('kontakt_mail', ''));
    // Pflichtangaben gehören auch hierher: Die Seite ist öffentlich erreichbar, ob sie nun
    // Veranstaltungen zeigt oder nicht. Verlinkt wird nur, was eingetragen ist – ein Verweis auf
    // eine leere Adresse wäre schlimmer als keiner.
    $imp = wl_recht_url('impressum');
    $dat = wl_recht_url('datenschutz');

    /* Countdown nur, wenn ein Termin gesetzt ist. ZWEI Rechnungen, je nachdem, ob eine Uhrzeit
       dabeisteht:
         • ohne Uhrzeit – in ganzen TAGEN ab Mitternacht. „Noch 1 Tag" darf nicht davon abhängen,
           ob gerade Vormittag oder Abend ist.
         • mit Uhrzeit – auf die Minute. Am letzten Tag zählt die Seite dann Stunden und zuletzt
           Minuten herunter, statt einen ganzen Tag lang „Heute" zu sagen.
       $einheit sagt der Anzeige, welches Wort unter die Zahl gehört. */
    $start = $vorab ? wl_launch_at() : '';
    $zeit  = $start !== '' ? wl_launch_time() : '';
    $rest  = null;
    $einheit = 'tage';
    if ($start !== '') {
        if ($zeit !== '') {
            $jetzt = new DateTimeImmutable('now');
            $ziel  = new DateTimeImmutable($start . ' ' . $zeit);
            $minuten = (int)floor(($ziel->getTimestamp() - $jetzt->getTimestamp()) / 60);
            if ($minuten >= 1440)   { $rest = intdiv($minuten, 1440); $einheit = 'tage'; }
            elseif ($minuten >= 60) { $rest = intdiv($minuten, 60);   $einheit = 'std'; }
            elseif ($minuten >= 1)  { $rest = $minuten;               $einheit = 'min'; }
            else                    { $rest = 0; }
        } else {
            $heute = new DateTimeImmutable('today');
            $ziel  = new DateTimeImmutable($start);
            $rest  = (int)$heute->diff($ziel)->format('%r%a');
        }
    }
    ?>
<!DOCTYPE html>
<html lang="<?= h(wl_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h(wl_t($vorab ? 'vorab_titel' : 'zu_titel')) ?> · was.läuft</title>
<link rel="stylesheet" href="<?= h(wl_css_url()) ?>">
</head>
<body class="wl wl-zu">
  <main class="wl-zu-in">
    <?php /* Die Marke lebt hier: Der Punkt hüpft, „läuft" wippt leicht nach. Auf den übrigen
             Seiten macht das ein Skript beim Zeigen – hier läuft es endlos aus dem Stylesheet,
             weil es der einzige Bewegungsanlass der Seite ist. */ ?>
    <div class="wl-zu-marke is-lebt"><?= wl_marke() ?></div>
    <h1><?= h(wl_t($vorab ? 'vorab_titel' : 'zu_titel')) ?></h1>
    <p><?= h(wl_t($vorab ? 'vorab_text' : 'zu_text')) ?></p>
    <?php if ($rest !== null): ?>
      <?php /* Die Zahl IST die Seite. Deshalb steht sie riesig und in den Markenfarben da, und
               alles andere ordnet sich ihr unter – das kleine Kachel-Schild wäre hier eine
               Fußnote. Am Starttag tritt an ihre Stelle das Wort, eine „0" wäre die schlechteste
               Nachricht des Tages. */ ?>
      <div class="wl-vor<?= $rest <= 0 ? ' is-jetzt' : '' ?>">
        <?php /* Sechs Funken steigen hinter der Zahl auf, jeder mit eigenem Takt und Weg (die
                 Werte stehen als Klassen f1..f6 im Stylesheet, weil ein style="" unter der CSP
                 verworfen würde). Sie sind Deko und für Vorlesekram unsichtbar. */ ?>
        <span class="wl-vor-funken" aria-hidden="true">
          <i class="f1"></i><i class="f2"></i><i class="f3"></i><i class="f4"></i><i class="f5"></i><i class="f6"></i>
        </span>
        <?php if ($rest > 0): ?>
          <span class="wl-vor-zahl"><?= (int)$rest ?></span>
          <span class="wl-vor-wort"><?= h(match ($einheit) {
              'std' => $rest === 1 ? wl_t('vorab_std_1') : wl_t('vorab_std_wort'),
              'min' => $rest === 1 ? wl_t('vorab_min_1') : wl_t('vorab_min_wort'),
              default => $rest === 1 ? wl_t('vorab_tag_1') : wl_t('vorab_tage_wort'),
          }) ?></span>
        <?php else: ?>
          <span class="wl-vor-jetzt"><?= h(wl_t('vorab_heute')) ?></span>
        <?php endif; ?>
      </div>
      <p class="wl-vor-datum"><?= h(wl_t('vorab_start')) ?>: <strong><?= h(wl_datum_voll($start)) ?><?php
          if ($zeit !== '') { echo ', ' . h($zeit) . (wl_t('vorab_uhr') !== '' ? ' ' . h(wl_t('vorab_uhr')) : ''); }
      ?></strong></p>
    <?php endif; ?>
    <?php /* Im Vorabstart führt der Hauptweg zum EINTRAGEN: Die Seite ist für alle zu, für die
             Gruppen aber offen – ohne diesen Verweis müsste jemand die Adresse auswendig kennen.
             Ist die Seite geschlossen, gibt es ihn nicht: Dort weist der Wächter auch das
             Formular ab, und ein Knopf ins Leere ist schlimmer als keiner. */ ?>
    <div class="wl-zu-wege">
      <?php if ($vorab): ?>
        <a class="wl-cta" href="einreichen.php"><?= h(wl_t('vorab_eintragen')) ?></a>
      <?php endif; ?>
      <?php if ($mail !== ''): ?>
        <a class="<?= $vorab ? 'wl-zu-mail' : 'wl-cta' ?>" href="mailto:<?= h($mail) ?>"><?= h(wl_t('zu_kontakt')) ?></a>
      <?php endif; ?>
    </div>
    <?php /* Der Absender, klein und ganz unten: Die Seite gehört was.läuft, der AStA steht
             dahinter. Größer wäre es eine zweite Marke neben der ersten. */ ?>
    <a class="wl-zu-asta" href="<?= h(wl_traeger_url()) ?>">
      <img src="<?= h(brand_url('logo-420', '../')) ?>" alt="" aria-hidden="true" width="420" height="293">
      <span><?= h(wl_t('vorab_asta')) ?></span>
    </a>
    <?php if ($imp !== '' || $dat !== ''): ?>
      <nav class="wl-zu-recht" aria-label="<?= h(wl_t('f_recht')) ?>">
        <?php if ($imp !== ''): ?><a href="<?= h($imp) ?>"><?= h(wl_t('f_impressum')) ?></a><?php endif; ?>
        <?php if ($dat !== ''): ?><a href="<?= h($dat) ?>"><?= h(wl_t('f_datenschutz')) ?></a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>
</body>
</html>
    <?php
    return true;
}

// -----------------------------------------------------------------------------------------------
// Darstellung
// -----------------------------------------------------------------------------------------------

/** Adresse des Stylesheets mit Änderungszeit – sonst zeigen Browser tagelang die alte Fassung. */
function wl_css_url(): string
{
    $datei = __DIR__ . '/../assets/wl.css';
    $v = is_file($datei) ? (string)@filemtime($datei) : '1';
    return '../assets/wl.css?v=' . $v;
}

/** Adresse eines Bildes. $gross=false liefert die Kachel-Fassung. */
function wl_img_url(string $file, bool $gross = false): string
{
    return 'bild.php?f=' . urlencode($file) . ($gross ? '&g=1' : '');
}

/**
 * Adresse eines Standard-Bilds (wl_standard_bilder()). Die Dateien liegen in assets/wl-standard/
 * und kommen DIREKT vom Webserver – nicht durch bild.php, das nur den Upload-Ordner in data/
 * bedient. Der Pfad ist relativ wie der zum Stylesheet (die Seiten liegen in veranstaltungen/).
 */
function wl_standard_url(array $b, bool $gross = false): string
{
    $file = (string)$b['file'];
    // Groß-Fassung für die Detailseite, damit das Bild dort nicht zu Pixelbrei wird: Wo eine
    // <name>-g.jpg liegt (1600 px, aus großen Originalen), nimmt die Detailansicht die –
    // die Kacheln bleiben bei der kleinen. Ohne Groß-Fassung gilt weiter die eine Datei
    // (viele Quellen geben schlicht nicht mehr als 960 px her, s. wl_standard_bilder()).
    if ($gross) {
        $g = preg_replace('/\.jpg$/', '-g.jpg', $file);
        if (is_file(__DIR__ . '/../assets/wl-standard/' . $g)) $file = $g;
    }
    return '../assets/wl-standard/' . rawurlencode($file);
}

/**
 * Die Absender-Marke einer Gruppe: ihr LOGO (Promo-Seite, orgs.logo_id) – oder, solange
 * keines hinterlegt ist, der farbige Kurz-Punkt. Ein Helfer für alle Stellen,
 * damit Detailseite und Veranstalter-Seiten nie auseinanderlaufen. $extra reicht
 * Größen-Klassen durch (z. B. 'gross' auf der Visitenkarte).
 */
function wl_org_avatar(?array $org, string $extra = ''): string
{
    $art  = wl_org_arten()[(string)($org['art'] ?? '')] ?? null;
    $logo = $org ? wl_image((int)($org['logo_id'] ?? 0)) : null;
    if ($logo) {
        return '<img class="wl-dot wl-dot-logo' . ($extra !== '' ? ' ' . h($extra) : '')
             . '" src="' . h(wl_img_url((string)$logo['file'])) . '" alt="">';
    }
    return '<span class="wl-dot' . ($extra !== '' ? ' ' . h($extra) : '') . ' '
         . h((string)($art['css'] ?? 'o-hsg')) . '">' . h(wl_org_kurz($org ?? [])) . '</span>';
}

/** „Do 7. Aug · 22:00" bzw. „Do 7. Aug · 22:00–02:00". Leeres Datum ergibt ''. */
/**
 * Uhrzeit für die Kachel – OHNE das Datum.
 *
 * Auf der Kachel steht das Datum bereits im Block auf dem Bild. Es in der Zeile darunter zu
 * wiederholen („Di 4. Aug · 13:00–15:00" neben einem Block, auf dem „DI 04 AUG" steht) wäre
 * doppelt gemoppelt und machte die Zeile unnötig lang. Geht ein Termin über mehrere Tage,
 * zeigt der Block nur den Anfang; dann MUSS das volle Datum stehen bleiben, sonst fehlt das Ende.
 */
function wl_zeit_kurz(string $start, string $ende = ''): string
{
    $ts = strtotime($start);
    if (!$ts) return '';
    $te = $ende !== '' ? strtotime($ende) : false;
    if ($te && date('Y-m-d', $te) !== date('Y-m-d', $ts)) return wl_datum_lang($start, $ende);
    if (date('H:i', $ts) === '00:00') return '';
    return date('H:i', $ts) . ($te ? '–' . date('H:i', $te) : '');
}

/* wl_datum_lang() ist in die wl-db.php umgezogen: Der Push-Versand im Cron formatiert
   damit die Mitteilungstexte, und der Cron lädt diese Datei hier nicht. */

/** Kurzform fürs Datums-Schild im Bild: ['DO', '07']. */
function wl_datum_schild(string $start): array
{
    $en = wl_ist_en();
    $wd = $en ? ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT']
              : ['SO', 'MO', 'DI', 'MI', 'DO', 'FR', 'SA'];
    $mo = $en ? ['', 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC']
              : ['', 'JAN', 'FEB', 'MÄR', 'APR', 'MAI', 'JUN', 'JUL', 'AUG', 'SEP', 'OKT', 'NOV', 'DEZ'];
    $ts = strtotime($start);
    if (!$ts) return ['', '', ''];
    // Der Monat gehört dazu: Eine nackte Zahl ist nur im Zusammenhang eindeutig – „12" allein
    // sagt nichts, „SA 12 JUL" schon.
    return [$wd[(int)date('w', $ts)], date('d', $ts), $mo[(int)date('n', $ts)]];
}

/**
 * Voll ausgeschriebenes Datum für die Vorab-Seite. Bewusst NICHT wl_datum_lang() aus wl-db.php:
 * Die heißt zwar „lang", liefert aber die KURZFORM für Kacheln („Do 1. Okt"). Hier soll der
 * Termin feiern, nicht in eine Ecke passen.
 */
function wl_datum_voll(string $d): string
{
    $ts = strtotime($d);
    if (!$ts) return '';
    $en = wl_ist_en();
    if ($en) return date('l, j F Y', $ts);
    $wd = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $mo = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
           'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    return $wd[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . '. '
         . $mo[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Ein Wort für den Preis: leer + frei = „frei", sonst der Text. */
function wl_preis_text(array $it): string
{
    $p = trim((string)($it['preis'] ?? ''));
    if ($p !== '') return $p;
    return (int)($it['frei'] ?? 0) === 1 ? wl_t('frei') : '';
}

/**
 * Seitenkopf. $noindex für Seiten, die nicht in Suchmaschinen gehören (Einreich-Formulare).
 * Anders als die übrigen öffentlichen Bereiche ist DIESE Seite ausdrücklich für Suchmaschinen
 * gedacht – wer „was läuft in Landau" sucht, soll hier landen.
 */
function wl_head(string $titel, string $beschreibung = '', string $bildDatei = '', bool $noindex = false): void
{
    wl_stat_hit();   // Besucherzählung (anonym, ohne Cookie – siehe wl-db.php)
    $nonce = bin2hex(random_bytes(16));
    $GLOBALS['wl_nonce'] = $nonce;

    $base  = wl_base_url();
    /* Kanonische Adresse aus dem laufenden Skript ableiten statt sie jeder Seite als Argument
       aufzuhalsen: Sie ist immer „Basis + /veranstaltungen/ + Dateiname", und die Startseite
       laesst den Dateinamen weg (sonst gaebe es die Startseite unter zwei Adressen). */
    $kanon = '';
    if ($base !== '') {
        $datei = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        if (!preg_match('~^[a-z0-9_.-]+\.php$~', $datei)) $datei = 'index.php';
        $kanon = $base . '/veranstaltungen/' . ($datei === 'index.php' ? '' : $datei);
        if ($datei === 'v.php' && isset($_GET['id'])) $kanon .= '?id=' . (int)$_GET['id'];
    }
    $bild  = ($bildDatei !== '' && $base !== '') ? $base . '/veranstaltungen/' . wl_img_url($bildDatei, true) : '';
    // Ohne eigenes Bild bekommt der geteilte Link das Marken-Vorschaubild (1200×630,
    // assets/wl-og.png) – ein Link ohne Bild geht in Messengern und Feeds unter.
    $ogStandard = $bild === '' && $base !== '';
    if ($ogStandard) $bild = $base . '/assets/wl-og.png';
    $voll  = $titel === '' ? 'was.läuft' : $titel . ' · was.läuft';

    // manifest-src und worker-src sind die PWA-Freigaben: Unter default-src 'none' dürfte der
    // Browser sonst weder das Manifest laden noch den Service Worker (sw.js) anmelden –
    // worker-src fällt auf script-src zurück, und dessen Einmal-Wert passt nie auf eine Datei.
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; "
        . "style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
        . "manifest-src 'self'; worker-src 'self'; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    if ($noindex) header('X-Robots-Tag: noindex, nofollow');
    // Die interne Vorschau eines geschlossenen Portals darf kein gemeinsamer Zwischenspeicher
    // weiterreichen – sonst bekommt sie später jemand zu sehen, für den die Seite noch zu ist.
    header('Cache-Control: ' . (wl_intern_sicht() ? 'private, no-store' : 'no-cache, must-revalidate'));
    ?><!DOCTYPE html>
<html lang="<?= h(wl_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($voll) ?></title>
<?php if ($beschreibung !== ''): ?><meta name="description" content="<?= h($beschreibung) ?>"><?php endif; ?>
<?php /* Kanonische Adresse: Die Listenseiten tragen Filter in der Adresse (?zeit=, ?kat=, ?q=),
         und jede Kombination waere fuer Suchmaschinen eine eigene Seite mit fast gleichem Inhalt.
         Der Verweis buendelt sie auf die eine saubere Adresse. Nur die Kennung der Detailseite
         gehoert dazu – sie ist der Inhalt, kein Filter. */ ?>
<?php if ($kanon !== ''): ?>
<link rel="canonical" href="<?= h($kanon) ?>">
<meta property="og:url" content="<?= h($kanon) ?>">
<?php /* Die zweite Sprache steht unter derselben Adresse (Umschalter per ?lang=), deshalb
         zeigen beide Alternates dorthin – x-default auf die deutsche Fassung. */ ?>
<link rel="alternate" hreflang="de" href="<?= h($kanon) ?>">
<link rel="alternate" hreflang="en" href="<?= h($kanon . (str_contains($kanon, '?') ? '&' : '?') . 'lang=en') ?>">
<link rel="alternate" hreflang="x-default" href="<?= h($kanon) ?>">
<?php endif; ?>
<meta property="og:locale" content="<?= wl_ist_en() ? 'en_GB' : 'de_DE' ?>">
<meta property="og:title" content="<?= h($titel === '' ? 'was.läuft' : $titel) ?>">
<meta property="og:description" content="<?= h($beschreibung) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="was.läuft">
<meta name="theme-color" content="#141220">
<?php if ($bild !== ''): ?>
<meta property="og:image" content="<?= h($bild) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php if ($ogStandard): ?><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><?php endif; ?>
<?php endif; ?>
<?php /* Das „w."-Icon der Marke: SVG für moderne Browser, PNG als Rückfall, 180er für den
         Home-Bildschirm (iOS rundet selbst ab, deshalb vollflächig eckig). */ ?>
<link rel="icon" type="image/svg+xml" href="../assets/wl-icon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/wl-icon-32.png">
<link rel="apple-touch-icon" href="../assets/wl-icon-180.png">
<?php /* PWA: Das Manifest macht die Seite installierbar („Zum Home-Bildschirm"), der Service
         Worker (sw.js) hält sie offline benutzbar. Alle Seiten liegen in veranstaltungen/,
         die relativen Pfade stimmen deshalb überall; iOS liest den App-Namen zusätzlich aus
         dem eigenen Meta-Feld. */ ?>
<link rel="manifest" href="manifest.php">
<meta name="apple-mobile-web-app-title" content="was.läuft">
<script nonce="<?= h($nonce) ?>">
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js');
</script>
<link rel="stylesheet" href="<?= h(wl_css_url()) ?>">
<?php if (!empty($GLOBALS['wl_push'])): ?>
<?php /* „Erinnere mich" + Abos: Seiten mit Push-Knöpfen setzen $GLOBALS['wl_push'] = true
         VOR wl_head() – wie beim flatpickr. Versioniert wie das Stylesheet. */ ?>
<script defer nonce="<?= h($nonce) ?>" src="../assets/wl-push.js?v=<?= h((string)@filemtime(__DIR__ . '/../assets/wl-push.js')) ?>"></script>
<?php endif; ?>
<?php if (!empty($GLOBALS['wl_flatpickr'])): ?>
<?php /* Derselbe Datums-Wähler wie in der App statt des Browser-Rohlings. Unter der strengen
         CSP brauchen auch die <script src>-Tags den Einmal-Wert; flatpickr positioniert seinen
         Kalender über die CSSOM (el.style.…) – das erlaubt die CSP, nur style="…"-Attribute
         nicht. Das Sende-Format 'Y-m-d H:i' nimmt wl_item_save() ohnehin entgegen. */ ?>
<link rel="stylesheet" href="../assets/flatpickr/flatpickr.min.css">
<script defer nonce="<?= h($nonce) ?>" src="../assets/flatpickr/flatpickr.min.js"></script>
<script defer nonce="<?= h($nonce) ?>" src="../assets/flatpickr/de.js"></script>
<script nonce="<?= h($nonce) ?>">
document.addEventListener('DOMContentLoaded', function () {
  if (!window.flatpickr) return;
  flatpickr.localize(flatpickr.l10ns.de);
  document.querySelectorAll('.fp-datetime').forEach(function (el) {
    flatpickr(el, {enableTime: true, time_24hr: true, dateFormat: 'Y-m-d H:i',
      altInput: true, altFormat: 'D, d.m.Y · H:i', minuteIncrement: 15});
  });
  document.querySelectorAll('.fp-date').forEach(function (el) {
    flatpickr(el, {dateFormat: 'Y-m-d', altInput: true, altFormat: 'D, d.m.Y'});
  });
});
</script>
<?php endif; ?>
</head>
<body class="wl">
<?php
}

/**
 * Die Wortmarke – EINE Quelle für Kopfleiste, Überschriften und Fußzeile.
 *
 * Aufbau: „was" (weiß) · „." (Limette) · das Verb (eigene Farbe, --wl-logo im Stylesheet).
 * Das Verb ist austauschbar, weil genau darin die Marke steckt: was.läuft, was.geht ab,
 * was.ist los – der feste Teil ist „was.", nicht das Wort dahinter.
 *
 * $animiert markiert die Stelle, an der die Wörter beim Laden durchwechseln (nur die Startseiten).
 */
function wl_marke(string $verb = 'läuft', bool $animiert = false): string
{
    if (!$animiert) {
        return '<span class="wl-mark"><span class="wl-mark-was">was</span><span class="wl-mark-dot">.</span>'
             . '<span class="wl-mark-w">' . h($verb) . '</span></span>';
    }

    // Ein Rad wie das Auswahlmenü am iPhone: Die Wörter stehen untereinander und rollen durch,
    // die Nachbarn sind oben und unten angeschnitten zu sehen.
    //
    // Zwei Dinge steuern den Aufbau:
    //  1. Der Satz dahinter darf sich nicht bewegen. Weil alle Wörter gleichzeitig im Kasten
    //     stehen, ist er automatisch so breit wie das längste – ganz gleich, welches gerade dran
    //     ist. Am Ende fährt die Breite in EINER Bewegung auf „läuft" zusammen.
    //  2. Ohne JavaScript (und bei „Ruhe bewahren") muss sofort „läuft" dastehen. Deshalb läuft
    //     die Spalte VERKEHRT HERUM (column-reverse): Das letzte Wort der Liste – „läuft" – steht
    //     dadurch oben und ist der Ruhezustand. Das Rad dreht sich also auf genau den Zustand zu,
    //     der ohne Skript ohnehin zu sehen wäre.
    // Im HTML steht NUR das Zielwort. Das Rad baut das Skript (index.php) dazu.
    //
    // Der Grund ist nicht Bequemlichkeit: Stünden alle Wörter im Quelltext, läse eine Suchmaschine
    // in der Überschrift „was.läuftgeht abist lossteht an…" – sichtbar wäre das nie, aber genau
    // diese Zeile ist das stärkste Signal dafür, wofür die Seite gefunden wird. Und wer sie sucht,
    // sucht „was läuft in Landau". Ohne Skript, ohne CSS und bei „Ruhe bewahren" steht hier
    // deshalb schlicht der fertige Satz.
    return '<span class="wl-mark wl-mark-anim" id="wl-marke"><span class="wl-mark-was">was</span><span class="wl-mark-dot">.</span>'
         . '<span class="wl-mark-w"><span class="wl-mark-live">' . h($verb) . '</span></span></span>';
}

/**
 * Die Wörter, die beim Laden durchlaufen, bevor „läuft" stehenbleibt.
 * Alle müssen sich in den Satz fügen: „was … diese Woche in Landau".
 */
function wl_marke_woerter(): array
{
    return ['geht ab', 'ist los', 'steht an', 'passiert', 'findet statt', 'läuft'];
}

/** Kopfleiste mit Marke und Navigation. $aktiv: 'events' | 'kurse' | ''. */
function wl_nav(string $aktiv = ''): void
{
    ?>
<header class="wl-top">
  <div class="wl-top-in wl-in">
    <a class="wl-logo" href="index.php"><?= wl_marke() ?></a>
    <nav class="wl-nav">
      <?php /* Trägt eine eigene Klasse, weil er auf sehr schmalen Geräten ausgeblendet wird –
               dort verdrängte er sonst die Marke aus der Zeile, und die Marke führt ohnehin
               zur selben Startseite (siehe wl.css). */ ?>
      <?php /* „Über" steht GANZ LINKS in der Leiste – und weiterhin
               nur am Desktop mit Luft (unter 900 px ausgeblendet, siehe wl.css); der
               Fußzeilen-Link „Was ist was.läuft" führt von überall hin. */ ?>
      <a class="wl-nav-ueber<?= $aktiv === 'ueber' ? ' on' : '' ?>" href="ueber.php"><?= h(wl_t('nav_ueber')) ?></a>
      <a class="wl-nav-events<?= $aktiv === 'events' ? ' on' : '' ?>" href="index.php"><?= h(wl_t('nav_events')) ?></a>
      <a<?= $aktiv === 'kurse' ? ' class="on"' : '' ?> href="kurse.php"><?= h(wl_t('nav_kurse')) ?></a>
      <a class="wl-cta" href="einreichen.php"><?= h(wl_t('nav_eintragen')) ?></a>
    </nav>
  </div>
</header>
<?php wl_intern_band(); ?>
<script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>">
(function () {
  'use strict';
  /* Der Punkt-Hüpfer der Kopfleisten-Marke. Die Tücke: Nach einem Klick auf die Marke lädt
     die Zielseite mit dem Zeiger NOCH ÜBER dem Logo – :hover griffe sofort wieder, und auch
     mouseenter feuert dann je nach Browser gleich beim Laden (Firefox) oder bei der ersten
     Bewegung (Safari, sogar beim WEGbewegen). Deshalb schaltet sich der Gruß erst SCHARF,
     wenn der Zeiger nachweislich einmal AUSSERHALB des Logos war – erst der nächste
     Eintritt ist ein echter Besuch und wird begrüßt. */
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var logo = document.querySelector('.wl-logo');
  if (!logo) return;
  var scharf = false, wecker = 0;
  document.addEventListener('mouseover', function (e) {
    if (!logo.contains(e.target)) scharf = true;
  });
  logo.addEventListener('mouseenter', function () {
    if (!scharf) return;
    logo.classList.remove('is-gruss'); void logo.offsetWidth;
    logo.classList.add('is-gruss');
    clearTimeout(wecker);
    wecker = setTimeout(function () { logo.classList.remove('is-gruss'); }, 450);
  });
})();
</script>
<?php
}

/**
 * Mehrzeiligen Text aus dem Register als Absätze ausgeben (Leerzeile = neuer Absatz).
 * Ein leerer Text ergibt NICHTS – so blendet ein geleertes Feld sein Element aus,
 * statt einen leeren Absatz zu hinterlassen.
 */
function wl_absaetze(string $text, string $klasse = ''): string
{
    $attr = $klasse !== '' ? ' class="' . h($klasse) . '"' : '';
    $out = '';
    foreach (preg_split('~\R{2,}~', trim($text)) ?: [] as $a) {
        $a = trim($a);
        if ($a !== '') $out .= '<p' . $attr . '>' . h($a) . '</p>' . "\n";
    }
    return $out;
}

/**
 * Der Hinweis des Demo-Modus – EIN Baustein für Startseite und Veranstaltungsseite, damit beide
 * denselben Satz zeigen. Bewusst die vorhandene Hinweiszeile (.wl-note warn) und kein eigenes
 * Band: Auf der Veranstaltungsseite steht er neben „Vorschau", „Abgesagt" und „vorbei", und drei
 * verschiedene Formen für dieselbe Art Aussage liest niemand als zusammengehörig.
 */
function wl_demo_note(bool $umbruch = false): void
{
    if (!wl_demo()) return;
    // Eigener Wortlaut aus den Einstellungen, sonst der eingebaute Satz. Leer bedeutet hier
    // bewusst „Standardsatz" und nicht „nichts": Ein leeres Feld darf den Demo-Modus nicht stumm
    // schalten – der Schalter stünde dann auf an, und draußen stünde nichts.
    $eigen = trim(wl_setting('demo_text', ''));
    $p = '<p class="wl-note warn"><strong>' . h(wl_t('demo')) . '</strong> '
       . h($eigen !== '' ? $eigen : wl_t('demo_text')) . '</p>';
    // Auf der Startseite steht der Hinweis frei zwischen den Abschnitten und braucht deshalb den
    // Seitenrahmen; auf der Veranstaltungsseite sitzt er schon in einem.
    echo $umbruch ? '<div class="wl-wrap wl-demo-hin">' . $p . '</div>' : $p;
}

/**
 * Rechtstext in Abschnitte setzen. Regel: Ein durch eine LEERZEILE getrennter Block ist ein
 * Abschnitt; seine erste Zeile ist die Überschrift, der Rest der Text. Das passt zu der Art,
 * wie man solche Texte ohnehin schreibt, und verlangt kein Auszeichnungswissen von den Leuten,
 * die sie in der Verwaltung pflegen.
 *
 * Steht eine Zeile allein da und ist eine Adresse oder Mailadresse, wird sie zum Link – sonst
 * müsste man Rechtstexte mit HTML füttern, und das will hier niemand tippen.
 */
function wl_recht_html(string $text): string
{
    $aus = '';
    foreach (preg_split('~\R{2,}~', trim($text)) ?: [] as $block) {
        $zeilen = array_values(array_filter(array_map('trim', preg_split('~\R~', trim($block)) ?: [])));
        if (!$zeilen) continue;
        $kopf = array_shift($zeilen);
        $aus .= '<h2>' . h($kopf) . '</h2>' . "\n";
        foreach ($zeilen as $z) {
            $aus .= '<p>' . wl_recht_zeile($z) . '</p>' . "\n";
        }
    }
    return $aus;
}

/** Eine Zeile, die NUR aus einer Adresse besteht, wird zum Link – sonst bleibt sie Text. */
function wl_recht_zeile(string $z): string
{
    if (preg_match('~^https?://\S+$~', $z)) {
        return '<a href="' . h($z) . '" rel="noopener">' . h($z) . '</a>';
    }
    if (preg_match('~^[^\s@]+@[^\s@]+\.[a-z]{2,}$~i', $z)) {
        return '<a href="mailto:' . h($z) . '">' . h($z) . '</a>';
    }
    return h($z);
}

/**
 * Wohin führen „Impressum" und „Datenschutz"? Auf die eigene Seite, sobald ein eigener Text
 * hinterlegt ist – sonst auf die Adresse aus den Einstellungen. Eine Stelle für beide Fälle,
 * damit Fußzeile, Vorab-Seite und Formulare nicht auseinanderlaufen.
 */
function wl_recht_url(string $was): string
{
    $eigen = trim(wl_text($was === 'impressum' ? 'recht_impressum' : 'recht_datenschutz'));
    if ($eigen !== '') return $was === 'impressum' ? 'impressum.php' : 'datenschutz.php';
    return trim(wl_setting($was === 'impressum' ? 'imprint_url' : 'privacy_url', ''));
}

function wl_foot(): void
{
    $imp = wl_recht_url('impressum');
    $dat = wl_recht_url('datenschutz');
    $mail = trim(wl_setting('kontakt_mail', ''));
    ?>
<?php /* Zwei benannte Spalten statt einer Linkzeile: „Impressum" neben „Veranstaltung eintragen"
         zu stellen, wirft Pflichtangaben und Einladung zum Mitmachen in denselben Topf.
         Die rechte Spalte kann leer bleiben, solange die Adressen nicht eingetragen sind – dann
         steht dort nichts, statt einer Überschrift ohne Inhalt. */
      $rechtlich = ($imp !== '' || $dat !== '' || $mail !== ''); ?>
<footer class="wl-foot">
  <div class="wl-foot-in">
    <div>
      <div class="wl-foot-brand"><?= wl_marke() ?></div>
      <?= wl_absaetze(wl_text('fuss_text')) ?>
    </div>
    <div class="wl-foot-col">
      <h3><?= h(wl_t('f_mitmachen')) ?></h3>
      <nav class="wl-foot-nav">
        <?php // Der Link VERSPRICHT „demnächst" und liefert es deshalb ausdrücklich (zeit=alle):
              // Die nackte Startseite entscheidet seit dem dynamischen Grundzustand selbst
              // zwischen Woche und Demnächst – hier soll das Wort aber stimmen.
              // Beide nur am HANDY (wl-foot-doppel): Am Desktop verdoppeln sie bloß die
              // Kopfleiste (raus); nach dem langen Handy-Scroll sind sie unten
              // dagegen der kürzeste Weg zurück in die Listen. ?>
        <a class="wl-foot-doppel" href="index.php?zeit=alle"><?= h(wl_t('f_demnaechst')) ?></a>
        <a class="wl-foot-doppel" href="kurse.php"><?= h(wl_t('f_kurse')) ?></a>
        <a href="veranstalter.php"><?= h(wl_t('f_veranstalter')) ?></a>
        <a href="abos.php"><?= h(wl_t('f_abos')) ?></a>
        <a href="app.php"><?= h(wl_t('f_app')) ?></a>
        <a href="einreichen.php"><?= h(wl_t('nav_eintragen') === '+ Eintragen' ? 'Veranstaltung eintragen' : 'Add an event') ?></a>
        <a href="ueber.php"><?= h(wl_t('f_ueber')) ?></a>
      </nav>
    </div>
    <?php if ($rechtlich): ?>
      <div class="wl-foot-col">
        <h3><?= h(wl_t('f_recht')) ?></h3>
        <nav class="wl-foot-nav">
          <?php if ($imp !== ''): ?><a href="<?= h($imp) ?>"><?= h(wl_t('f_impressum')) ?></a><?php endif; ?>
          <?php if ($dat !== ''): ?><a href="<?= h($dat) ?>"><?= h(wl_t('f_datenschutz')) ?></a><?php endif; ?>
          <?php if ($mail !== ''): ?><a href="mailto:<?= h($mail) ?>"><?= h(wl_t('f_kontakt')) ?></a><?php endif; ?>
        </nav>
      </div>
    <?php endif; ?>
  </div>
  <div class="wl-in wl-foot-end">
    <?php /* Die Handschrift-Zeile hängt am Copyright: Als eigenes drittes Element schob
             space-between sie am Desktop allein in die Mitte der Zeile. Ohne Symbol-Schrift
             ist das Herz dasselbe Inline-SVG wie in der Pille der Über-Seite. */ ?>
    <span>© <?= date('Y') ?> <?= h(wl_traeger_kurz()) ?> ·
      <span class="wl-foot-boj">Mit <span class="wl-foot-herz" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.3 10.55 20C5.4 15.4 2 12.3 2 8.5 2 5.4 4.4 3 7.5 3c1.7 0 3.4.8 4.5 2.1C13.1 3.8 14.8 3 16.5 3 19.6 3 22 5.4 22 8.5c0 3.8-3.4 6.9-8.55 11.5z"/></svg></span> von Boj Petersen</span></span>
    <?= wl_sprachknopf() ?>
    <?php /* Der Ehrlichkeits-Hinweis MUSS bleiben: Er sagt, dass nur die Oberfläche übersetzt
             ist und die Beiträge selbst deutsch bleiben. Auf
             Englisch steht er deshalb hier vor dem Gewähr-Satz, auf Deutsch braucht ihn niemand. */ ?>
    <span><?php if (wl_ist_en()): ?><span lang="en" class="wl-foot-hint-inline"><?= h(wl_t('nur_deutsch')) ?></span><br><?php endif; ?><?= wl_ist_en()
        ? 'All details without guarantee – the listing groups are responsible for their content.'
        : 'Angaben ohne Gewähr – für die Inhalte sind die eintragenden Gruppen verantwortlich.' ?></span>
  </div>
</footer>
<?php /* Doppel-Submit-Schutz: ganz am Ende, damit sein submit-Listener nach
         den Dialog-Handlern der Seiten läuft und deren preventDefault sieht. */ ?>
<script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>" src="../assets/doppelklick.js"></script>
</body>
</html>
<?php
}

/**
 * Der Sprachumschalter: EIN Knopf mit der Flagge der Sprache, in die er umschaltet.
 *
 * Er steht unten in der Mitte der Abschlusszeile – eine eigene Fußzeilen-Spalte mit Überschrift
 * wäre viel Platz für eine Kleinigkeit – und zeigt das ZIEL, nicht den Zustand: Wer Deutsch
 * liest, sieht die britische Flagge und „EN" und weiß, was ein Klick tut. Deshalb steht die
 * Erklärung auch in der jeweils ANDEREN Sprache im Titel.
 *
 * Die Flaggen sind Inline-SVG statt Emoji: Flaggen-Emoji rendern längst nicht überall (Windows
 * zeigt statt 🇬🇧 nur die Buchstaben „GB"), und der Knopf soll auf jedem Gerät wie ein Knopf
 * aussehen. Der Union Jack braucht dafür ein paar Pfade mehr, das ist der Preis.
 */
function wl_sprachknopf(): string
{
    $nachEn = !wl_ist_en();                    // gerade Deutsch → Klick führt nach Englisch
    $ziel   = $nachEn ? 'en' : 'de';
    $kuerzel = $nachEn ? 'EN' : 'DE';
    $titel  = $nachEn ? 'Switch to English' : 'Auf Deutsch umstellen';
    $flagge = $nachEn
        // Union Jack: blaues Feld, weiße + rote Diagonalen, darüber das weiße und rote Kreuz.
        ? '<svg viewBox="0 0 24 16" aria-hidden="true"><rect width="24" height="16" fill="#012169"/>'
          . '<path d="M0 0 L24 16 M24 0 L0 16" stroke="#fff" stroke-width="3.2"/>'
          . '<path d="M0 0 L24 16 M24 0 L0 16" stroke="#C8102E" stroke-width="1.8"/>'
          . '<path d="M12 0 V16 M0 8 H24" stroke="#fff" stroke-width="5.4"/>'
          . '<path d="M12 0 V16 M0 8 H24" stroke="#C8102E" stroke-width="3.2"/></svg>'
        // Schwarz-Rot-Gold, drei gleiche Balken.
        : '<svg viewBox="0 0 24 16" aria-hidden="true"><rect width="24" height="5.34" fill="#000"/>'
          . '<rect y="5.34" width="24" height="5.33" fill="#DD0000"/>'
          . '<rect y="10.67" width="24" height="5.33" fill="#FFCE00"/></svg>';
    // Die BISHERIGE Abfrage muss mit in den Link. Ein nacktes „?lang=en" ersetzt laut Standard
    // die komplette Abfrage-Zeichenkette – auf v.php?id=11 fiele damit das id weg, der
    // Umschalter landete auf einem v.php ohne alles, und die Seite meldete zu Recht „gibt es
    // hier nicht". Das trifft jede Seite mit Parametern, also auch Trefferlisten mit Kategorie
    // oder Suchbegriff.
    $abfrage = $_GET;
    $abfrage['lang'] = $ziel;
    return '<a class="wl-lang-knopf" href="?' . h(http_build_query($abfrage)) . '" hreflang="' . h($ziel) . '"'
         . ' title="' . h($titel) . '" aria-label="' . h($titel) . '">'
         . '<span class="wl-lang-flagge">' . $flagge . '</span>'
         . '<span class="wl-lang-kuerzel">' . h($kuerzel) . '</span>'
         . '</a>';
}

/**
 * Eine Kachel. Wird auf der Startseite, in der Kursliste und in den Trefferlisten benutzt –
 * damit sie überall gleich aussieht und gleich funktioniert, gibt es sie genau einmal.
 */
/**
 * Die Kategorie-Auswahl als AUFKLAPP-MENÜ statt als Reihe von sieben Knöpfen.
 *
 * Sieben gleich aussehende Chips nebeneinander sind zu viel: Sie füllen die halbe Leiste,
 * wollen einzeln gelesen werden und drängen die beiden Schalter an den Rand, die tatsächlich
 * am häufigsten gebraucht werden. Eingeklappt steht dort ein Feld – und wenn eine Kategorie
 * gewählt ist, steht sie samt ihrem Punkt darin, man sieht den Zustand also ohne Öffnen.
 *
 * Bewusst <details> und KEIN Skript: Die öffentlichen Seiten laufen unter einer strengen CSP,
 * und ein Auswahlfeld, das erst durch JavaScript aufgeht, wäre ohne JavaScript unbedienbar.
 * <details> kann der Browser von sich aus. Der Preis: Es schließt sich nicht beim Klick daneben.
 * Das ist verkraftbar, weil jeder Eintrag darin ohnehin die Seite wechselt.
 *
 * @param string   $aktiv Schlüssel der gewählten Kategorie ('' = alle)
 * @param callable $url   fn(string $kat): string – baut die Adresse für eine Kategorie
 */
function wl_kat_dropdown(string $aktiv, callable $url): void
{
    $cats = wl_cats();
    $da   = isset($cats[$aktiv]) ? $cats[$aktiv] : null;
    ?>
<details class="wl-drop<?= $da ? ' on' : '' ?>">
  <summary>
    <?php if ($da): ?>
      <span class="wl-cat-punkt <?= h((string)$da['css']) ?>"></span><?= h(wl_cat_label($aktiv)) ?>
    <?php else: ?>
      <?php /* BEWUSST nur das nackte Wort: Eine eigene Chip-Zeile wäre am Handy eine dritte
               Filterzeile, und alle Farbpunkte im Feld machen es so breit, dass die Leiste
               doch wieder umbricht. */ ?>
      <?= h(wl_t('kategorie')) ?>
    <?php endif; ?>
    <span class="wl-drop-pfeil" aria-hidden="true"></span>
  </summary>
  <div class="wl-drop-p">
    <a class="wl-drop-i<?= $da ? '' : ' on' ?>" href="<?= h($url('')) ?>"><?= h(wl_ist_en() ? 'All categories' : 'Alle Kategorien') ?></a>
    <?php foreach ($cats as $ck => $cd): ?>
      <a class="wl-drop-i<?= $aktiv === $ck ? ' on' : '' ?>" href="<?= h($url($aktiv === $ck ? '' : $ck)) ?>"
         <?= $aktiv === $ck ? ' aria-current="true"' : '' ?>>
        <span class="wl-cat-punkt <?= h((string)$cd['css']) ?>"></span><?= h(wl_cat_label($ck)) ?>
      </a>
    <?php endforeach; ?>
  </div>
</details>
<?php
}

function wl_kachel(array $it): void
{
    $id    = (int)$it['id'];
    $kurs  = ($it['kind'] ?? 'event') === 'kurs';
    $bild  = trim((string)($it['bild'] ?? ''));
    [$wt, $tag, $mon] = $kurs ? ['', '', ''] : wl_datum_schild((string)$it['starts_at']);
    $preis = wl_preis_text($it);
    $orgCss = (string)(wl_org_arten()[(string)($it['org_art'] ?? 'hsg')]['css'] ?? 'o-hsg');
    // Die Kategorie auf der Kachel bringt Farbe und sagt auf einen Blick, worum es geht –
    // gerade bei Beiträgen ohne Bild.
    $kat    = (string)($it['cat'] ?? '');
    $katD   = wl_cats()[$kat] ?? null;
    /* Ohne eigenes Bild bekommt die Kachel ein Standard-Foto passend zur Kategorie – ein
       Farbverlauf sieht nach Verwaltungswerkzeug aus, nicht nach Veranstaltung. Wählbar über
       items.std_bild; ohne Wahl aus der ID abgeleitet, gleiche ID = immer dasselbe Bild. Der
       Verlauf bleibt nur als letzte Rückfalllinie. */
    $std = $bild === '' ? wl_item_std($it) : null;
    ?>
<a class="wl-card" href="v.php?id=<?= $id ?>">
  <div class="wl-card-img<?= $bild === '' && !$std ? ' ' . h(wl_placeholder_class($id)) : '' ?>">
    <?php if ($bild !== ''): ?>
      <img src="<?= h(wl_img_url($bild)) ?>" alt="" loading="lazy" width="640" height="400">
    <?php elseif ($std): ?>
      <img src="<?= h(wl_standard_url($std)) ?>" alt="" loading="lazy" width="640" height="400">
    <?php endif; ?>
    <?php if (!$kurs && $tag !== ''): ?>
      <span class="wl-day"><i><?= h($wt) ?></i><b><?= h($tag) ?></b><em><?= h($mon) ?></em></span>
    <?php else: ?>
      <span class="wl-day wl-day-kurs"><?= h(wl_t('kurs_marke')) ?></span>
    <?php endif; ?>
    <?php /* Bei einer Absage weicht „kostenlos" – auf einem abgesagten Termin ist der
             Eintrittspreis keine Information mehr, der rote Chip ist die einzige, die zählt. */ ?>
    <?php if ((int)($it['frei'] ?? 0) === 1 && trim((string)($it['preis'] ?? '')) === ''
              && (int)($it['abgesagt'] ?? 0) !== 1): ?>
      <span class="wl-free"><?= h(wl_t('kostenlos')) ?></span>
    <?php endif; ?>
    <?php /* „Empfohlen" als Stern-Chip auf dem Bild: Die Liste bleibt chronologisch,
             Empfohlene stechen an Ort und Stelle heraus – nur der Chip, kein Rahmen. */ ?>
    <?php if ((int)($it['tipp'] ?? 0) === 1): ?>
      <span class="wl-card-tipp"><?= h(wl_t('empfohlen')) ?></span>
    <?php endif; ?>
    <?php if ((int)($it['abgesagt'] ?? 0) === 1): ?>
      <span class="wl-card-abges"><?= h(wl_t('abgesagt_kurz')) ?></span>
    <?php endif; ?>
  </div>
  <div class="wl-card-b">
    <?php if ($katD): ?>
      <div class="wl-card-cat <?= h((string)$katD['css']) ?>">
        <?= h(wl_cat_label((string)$it['cat'])) ?>
      </div>
    <?php endif; ?>
    <div class="wl-card-t"><?= h((string)$it['title']) ?></div>
    <div class="wl-card-m">
      <?php
        // Kein Datum mehr: Das steht schon im Block auf dem Bild (siehe wl_zeit_kurz()).
        // Alle Teile in EINE Liste, dann setzt implode die Trenner. Hinge am Preis ein festes
        // „· ", begänne die Zeile bei einem Termin ohne Uhrzeit und ohne Ort mit einem Trennpunkt.
        // Der Preis entfällt, wenn schon die Marke „kostenlos" auf dem Bild klebt.
        $freiMarke = (int)($it['frei'] ?? 0) === 1 && trim((string)($it['preis'] ?? '')) === '';
        $teile = array_filter([
            $kurs ? (string)$it['rhythmus'] : wl_zeit_kurz((string)$it['starts_at'], (string)$it['ends_at']),
            trim((string)$it['ort']),
            $freiMarke ? '' : $preis,
        ], static fn ($t) => trim((string)$t) !== '');
      ?>
      <?= h(implode(' · ', $teile)) ?>
    </div>
    <div class="wl-card-by">
      <?php // Punkt statt Quadrat mit Initialen: Zwei Buchstaben in dieser Größe las ohnehin niemand. ?>
      <span class="wl-pip <?= h($orgCss) ?>"></span>
      <?= h((string)($it['org_name'] ?? '')) ?>
    </div>
  </div>
</a>
<?php
}
