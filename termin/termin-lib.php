<?php
/**
 * Öffentlicher Terminplaner – Bootstrap.
 *
 * Wie bei Pat:innenprogramm, Umfragen und externen Events: Die lib.php der App wird
 * ABSICHTLICH NICHT eingebunden. Eigene Sitzung, eigener Cookie, eigene Fehlerbehandlung,
 * strenge Content-Security-Policy.
 *
 * Aussehen: die echte assets/style.css der App plus pat.css, umfrage.css und ein kleines
 * termin.css. Es gibt also keine zweite Designsprache zu pflegen.
 *
 * WICHTIG: Unter dieser CSP gibt es kein 'unsafe-inline'. Ein style="…"-Attribut wird vom
 * Browser stillschweigend verworfen – alles Gestalterische gehört nach assets/termin.css.
 */

declare(strict_types=1);

require_once __DIR__ . '/../termin-db.php';

const TERMIN_LOG_FILE = __DIR__ . '/../data/error.log';

function tplan_log(string $msg): void
{
    $where = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
    @file_put_contents(TERMIN_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] TERMIN: ' . trim($msg)
        . ' | ' . trim($where) . "\n", FILE_APPEND | LOCK_EX);
}

error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    if (in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    tplan_log('PHP-Warnung: ' . $str . ' in ' . $file . ':' . $line);
    return true;
});
set_exception_handler(function (\Throwable $e) {
    tplan_log('Unbehandelte Exception ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<p>Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.</p>';
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        tplan_log('FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

function tplan_is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/termin/index.php'))), '/') . '/';
    // Der Browser schickt die Sitzungs-Kennung – und auf einer öffentlichen Seite schicken
    // Scanner dort auch Müll hin. OHNE strict_mode versucht PHP, diesen Müll als Kennung zu
    // benutzen: zwei Warnungen im Log, und die Sitzung bleibt LEER (kein CSRF-Token, jedes
    // Absenden scheitert). Mit strict_mode verwirft PHP Unbekanntes still und vergibt eine
    // eigene Kennung – das verhindert nebenbei, dass jemand eine Kennung vorgeben kann.
    @ini_set('session.use_strict_mode', '1');
    session_name('asta_termin_sid');
    session_set_cookie_params(['path' => $dir, 'httponly' => true, 'samesite' => 'Lax', 'secure' => tplan_is_https()]);
    session_start();
}

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Parameter als Zeichenkette lesen – `?t[]=x` liefert sonst ein Array. */
function tplan_param($v): string
{
    if (is_string($v)) return $v;
    if (is_int($v) || is_float($v)) return (string)$v;
    return '';
}

function tplan_csrf_token(): string
{
    if (empty($_SESSION['tplan_csrf'])) $_SESSION['tplan_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['tplan_csrf'];
}

function tplan_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(tplan_csrf_token()) . '">';
}

function tplan_check_csrf(): void
{
    $t = tplan_param($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals(tplan_csrf_token(), $t)) {
        http_response_code(400);
        exit('Ungültige Anfrage. Bitte die Seite neu laden und noch einmal versuchen.');
    }
}

/**
 * Bremse je Besuchssitzung. Zwei getrennte Töpfe: Umfragen anlegen ist teurer als antworten,
 * deshalb bekommt jede Sache ihren eigenen Zähler.
 */
function tplan_rate_ok(string $topf, int $proStunde): bool
{
    $jetzt = time();
    $key = 'tplan_hits_' . $topf;
    $liste = array_values(array_filter((array)($_SESSION[$key] ?? []), fn($t) => $t > $jetzt - 3600));
    if (count($liste) >= $proStunde) { $_SESSION[$key] = $liste; return false; }
    $liste[] = $jetzt;
    $_SESSION[$key] = $liste;
    return true;
}

/* ---------------------------------------------------------------------------
 * Das Gedächtnis dieses Geräts – 30 Tage, in einem Cookie.
 *
 * Es gibt keine Konten, und die Sitzung ist mit dem Schließen des Browsers weg: Wer am Montag
 * eine Umfrage anlegt, fände sie am Dienstag nicht wieder, wenn die Mail fehlt. Der Cookie ist
 * das Sicherheitsnetz dafür – und nebenbei die Bequemlichkeit, dass der eigene Name beim
 * nächsten Mal schon im Feld steht.
 *
 * Drin steht NUR, was die Person selbst eingetippt hat, plus die Schlüssel ihrer eigenen Links.
 * Keine Titel: Die schlagen die Seiten frisch nach – so steht in einer alten Liste nie ein Titel,
 * den es längst nicht mehr gibt. Gelesen wird der Cookie ausschließlich hier auf dem Server
 * (httponly), und die Startseite hat einen Knopf, der ihn wieder löscht.
 * ------------------------------------------------------------------------- */

const TPLAN_COOKIE      = 'asta_tp_me';
const TPLAN_COOKIE_DAYS = 30;

/**
 * Der Inhalt des Cookies, einmal gelesen. Mit Argument gesetzt – so sieht ein Aufruf direkt
 * nach dem Schreiben schon den neuen Stand und nicht mehr den aus dem Browser-Cookie.
 */
function tplan_dev(?array $set = null): array
{
    static $d = null;
    if ($set !== null) return $d = $set;
    if ($d !== null) return $d;
    $roh = tplan_param($_COOKIE[TPLAN_COOKIE] ?? '');
    // Aufräumen macht tplan_dev_norm() in termin-db.php – dort kommt der Selbsttest daran.
    //
    // MERKSATZ zum Typtest: immer denselben Wert prüfen, den man danach benutzt.
    // `is_string($x['n'] ?? '') ? $x['n'] : ''` ist falsch – fehlt der Schlüssel, ist der
    // GEPRÜFTE Wert '' und damit ein String, der Zweig greift aber auf den rohen, nicht
    // vorhandenen $x['n'] zu. Ergebnis: null in mb_substr().
    return $d = tplan_dev_norm($roh !== '' ? json_decode($roh, true) : null);
}

function tplan_dev_read(): array { return tplan_dev(); }

function tplan_dev_write(array $d): void
{
    tplan_dev($d);
    // Nach dem ersten Byte Ausgabe geht kein Cookie mehr raus – dann still verzichten statt
    // eine Warnung ins Log zu schreiben. Alle Aufrufer setzen ihn vor der Ausgabe.
    if (headers_sent()) return;
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/termin/index.php'))), '/') . '/';
    setcookie(TPLAN_COOKIE, (string)json_encode($d, JSON_UNESCAPED_UNICODE), [
        'expires'  => time() + TPLAN_COOKIE_DAYS * 86400,
        'path'     => $dir,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => tplan_is_https(),
    ]);
}

/** Einen eigenen Link merken. $art: 'polls' (Verwaltungs-Schlüssel) oder 'entries' (slug|token). */
function tplan_remember(string $art, string $key, string $titel = ''): void
{
    $d = tplan_dev_read();
    if ($art === 'polls') {
        $d['p'] = array_values(array_unique(array_merge(array_filter($d['p'], 'is_string'), [$key])));
        if (count($d['p']) > 12) $d['p'] = array_slice($d['p'], -12);
    } elseif ($art === 'entries') {
        [$slug, $token] = array_pad(explode('|', $key, 2), 2, '');
        if ($slug === '' || $token === '') return;
        $d['e'][$slug] = $token;
        if (count($d['e']) > 12) $d['e'] = array_slice($d['e'], -12, null, true);
    } else {
        return;
    }
    tplan_dev_write($d);
}

/** Name und Adresse für das nächste Formular merken. */
function tplan_remember_me(string $name, string $mail): void
{
    $d = tplan_dev_read();
    $d['n'] = mb_substr(trim($name), 0, 80);
    $d['m'] = mb_substr(trim($mail), 0, 190);
    tplan_dev_write($d);
}

/** ['n' => Name, 'm' => Mailadresse] aus dem Cookie. */
function tplan_me(): array
{
    $d = tplan_dev_read();
    return ['n' => (string)$d['n'], 'm' => (string)$d['m']];
}

/**
 * Die gemerkten Schlüssel. 'polls' → Liste von Verwaltungs-Schlüsseln,
 * 'entries' → slug => Änderungs-Token. Titel schlagen die Seiten selbst nach.
 */
function tplan_mine(string $art): array
{
    $d = tplan_dev_read();
    if ($art === 'polls')   return array_reverse(array_values(array_filter($d['p'], 'is_string')));
    if ($art === 'entries') return array_reverse($d['e'], true);
    return [];
}

/** Alles vergessen – der Knopf dafür steht auf der Startseite. */
function tplan_forget(): void
{
    tplan_dev(['n' => '', 'm' => '', 'e' => [], 'p' => []]);
    if (headers_sent()) return;
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/termin/index.php'))), '/') . '/';
    setcookie(TPLAN_COOKIE, '', ['expires' => time() - 3600, 'path' => $dir,
        'httponly' => true, 'samesite' => 'Lax', 'secure' => tplan_is_https()]);
    $_COOKIE[TPLAN_COOKIE] = '';
}

/**
 * Eine Rückmeldung über die Weiterleitung hinweg mitnehmen.
 *
 * Jede schreibende Aktion endet mit einer Weiterleitung – sonst legt ein Neuladen der Seite
 * dieselbe Änderung noch einmal an. Die Meldung muss diesen Sprung überleben.
 */
function tplan_flash(string $msg, string $typ = 'success'): void
{
    $_SESSION['tplan_flash'] = ['m' => $msg, 't' => $typ === 'error' ? 'error' : 'success'];
}

function tplan_flash_take(): ?array
{
    $f = $_SESSION['tplan_flash'] ?? null;
    unset($_SESSION['tplan_flash']);
    return is_array($f) ? $f : null;
}

function tplan_asset(string $rel): string
{
    // Marken-Bilder laufen über brand.php: Sie liegen in data/branding/, sobald in der
    // Verwaltung eines hochgeladen wurde – sonst greift der Platzhalter in assets/.
    require_once __DIR__ . '/../brand-core.php';
    if (preg_match('~^assets/([a-z0-9-]+)\\.png$~', $rel, $t) && in_array($t[1], brand_namen(), true)) {
        return brand_url($t[1], '../');
    }
    $v = @filemtime(__DIR__ . '/../' . $rel);
    return '../' . $rel . ($v ? '?v=' . $v : '');
}

function tplan_nonce(): string
{
    static $n = null;
    if ($n === null) $n = base64_encode(random_bytes(16));
    return $n;
}

/** Datum/Uhrzeit menschlich (die App-Helfer stehen hier nicht zur Verfügung). */
function tplan_dt(string $sql): string
{
    $t = strtotime($sql);
    if (!$t) return '';
    return date('d.m.Y', $t) . (date('H:i', $t) === '23:59' ? '' : ', ' . date('H:i', $t) . ' Uhr');
}

// ---------------------------------------------------------------------------
// Seitengerüst
// ---------------------------------------------------------------------------

/**
 * $picker lädt zusätzlich das Aussehen des Datums-Wählers. Nur zwei Seiten brauchen ihn; die
 * Teilnahmeseite bekommen die meisten Leute zu sehen, und die soll nichts laden, was sie nicht
 * benutzt. Das Skript dazu kommt am Seitenende über tplan_picker_script().
 */
/**
 * Kanonische Adresse der aktuellen Seite – aus der eingestellten Basis plus Dateiname.
 * Ohne eingetragene Basis-Adresse gibt es keine: Ein Verweis auf „localhost" waere schlimmer
 * als keiner.
 */
function tplan_kanon(): string
{
    $b = tplan_base_url();
    if ($b === '') return '';
    $datei = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    if (!preg_match('~^[a-z0-9_.-]+\.php$~', $datei)) $datei = 'index.php';
    return $b . '/termin/' . ($datei === 'index.php' ? '' : $datei);
}

function tplan_head(string $title, string $desc = '', bool $noindex = false, string $bodyClass = 'pat-body', bool $picker = false): void
{
    $nonce = tplan_nonce();
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; "
        . "style-src 'self'; img-src 'self' data:; font-src 'self'; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-cache, must-revalidate');
    // Eine einzelne Terminumfrage gehört in keinen Suchindex – der Link ist der Schlüssel.
    if ($noindex) header('X-Robots-Tag: noindex, nofollow');
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<script nonce="<?= h($nonce) ?>">(function(){try{var k='pat-theme',m=localStorage.getItem(k)||'system';if(m!=='light'&&m!=='dark')m='system';var d=document.documentElement;d.setAttribute('data-theme-mode',m);d.setAttribute('data-theme',m==='system'?((window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light'):m);var mt=document.querySelector('meta[name="theme-color"]');if(mt)mt.setAttribute('content',d.getAttribute('data-theme')==='dark'?'#111518':'#ffffff');}catch(e){}})();</script>
<title><?= h($title) ?> · <?= h(tplan_traeger()) ?></title>
<?php if ($noindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if ($desc !== ''): ?><meta name="description" content="<?= h($desc) ?>">
<meta property="og:description" content="<?= h($desc) ?>"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h(tplan_traeger()) ?>">
<meta property="og:title" content="<?= h($title) ?>">
<?php /* Kanonische Adresse nur fuer die INDEXIERBAREN Seiten: Einzelne Terminumfragen tragen
         noindex, dort waere ein Verweis auf sich selbst bloss Ballast – und der Link zu einer
         Umfrage IST der Schluessel, der gehoert in keinen Index. */ ?>
<?php $tpKanon = $noindex ? '' : tplan_kanon(); ?>
<?php if ($tpKanon !== ''): ?>
<link rel="canonical" href="<?= h($tpKanon) ?>">
<meta property="og:url" content="<?= h($tpKanon) ?>">
<?php endif; ?>
<meta property="og:locale" content="de_DE">
<?php /* Teilen-Vorschau: ohne Bild geht ein Link in Messengern und Feeds unter. Das quadratische
         App-Symbol ergibt die kleine Karte; für die grosse braucht es ein eigenes 1200x630-Bild
         (Muster: assets/wl-og.png). Ohne eingetragene Basis-Adresse bleibt es weg – ein Bildverweis
         auf „localhost" waere schlimmer als keiner. */ ?>
<?php $tpBild = tplan_base_url() !== '' ? tplan_base_url() . '/' . brand_url('icon-512') : ''; ?>
<?php if ($tpBild !== ''): ?>
<meta property="og:image" content="<?= h($tpBild) ?>">
<meta name="twitter:image" content="<?= h($tpBild) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= h($title) ?>">
<?php if ($desc !== ''): ?><meta name="twitter:description" content="<?= h($desc) ?>"><?php endif; ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= h(tplan_asset('assets/tabler/fonts/tabler-icons.woff2')) ?>">
<link rel="stylesheet" href="<?= h(tplan_asset('assets/tabler/tabler-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= h(tplan_asset('assets/style.css')) ?>">
<link rel="stylesheet" href="<?= h(tplan_asset('assets/pat.css')) ?>">
<link rel="stylesheet" href="<?= h(tplan_asset('assets/umfrage.css')) ?>">
<?php if ($picker): ?><link rel="stylesheet" href="<?= h(tplan_asset('assets/flatpickr/flatpickr.min.css')) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= h(tplan_asset('assets/termin.css')) ?>">
<link rel="icon" type="image/png" href="<?= h(tplan_asset('assets/logo-header.png')) ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<header class="pat-top">
  <div class="pat-top-in">
    <a class="pat-brand tp-brand" href="index.php">
      <img class="pat-logo" src="<?= h(tplan_asset('assets/logo-header.png')) ?>" alt="<?= h(tplan_traeger()) ?>" width="150" height="40">
      <span class="pat-brand-x"><strong><?= h(tplan_traeger()) ?></strong><small>Terminplaner</small></span>
    </a>
  </div>
</header>
<main class="pat-main">
<?php
}

function tplan_foot(): void
{
    $imprint = trim(tplan_setting_get('imprint_url', ''));
    $privacy = trim(tplan_setting_get('privacy_url', ''));
    $ok = fn(string $u) => preg_match('~^https?://~i', $u) && filter_var($u, FILTER_VALIDATE_URL);

    /*
     * Rückmeldungen laufen über eine schlichte Mail statt über ein Formular: Hier gibt es keine
     * Konten, also auch niemanden, dem man antworten könnte – und ein weiteres Formular wäre
     * eine weitere Stelle, an der Fremde uns Text schicken können.
     *
     * Vorbelegt wird NUR der Betreff, ausdrücklich keine Adresse der Seite: Der Verwaltungs-Link
     * steht in der Adresszeile, und wer ihn versehentlich mitschickt, gibt seine Umfrage aus der
     * Hand. Auch KEIN vorgeschriebener Text: Der zerlegt den Link in manchen Mailprogrammen,
     * weil seine Zeilenumbrüche nach RFC 6068 als %0D%0A kodiert sein müssen und ein einzelnes
     * %0A nicht überall durchgeht. Kurz und ohne Text ist die Form, die zuverlässig ankommt.
     *
     * Und die Adresse steht zusätzlich als Text da: Nicht auf jedem Gerät ist ein Mailprogramm
     * eingerichtet, und dann passiert beim Klick auf einen mailto-Link schlicht nichts.
     */
    $fb = tplan_feedback_mail();
    $fbLink = '';
    if ($fb !== '') {
        // Der Adressteil bleibt unkodiert: Ein „%40" statt „@" verdauen nicht alle Mailprogramme.
        // Nur wenn die Adresse etwas außerhalb des gewohnten Zeichenvorrats enthält (technisch
        // erlaubt, praktisch nie), wird sie doch kodiert – sonst bräche sie den Link auf.
        $adr = preg_match('~^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$~', $fb) ? $fb : rawurlencode($fb);
        $fbLink = 'mailto:' . $adr . '?subject=' . rawurlencode('Terminplaner – Rückmeldung');
    }
    ?>
</main>
<footer class="pat-foot">
  <?php if ($fbLink !== ''): ?>
    <p class="tp-fb"><a class="btn secondary small" href="<?= h($fbLink) ?>"><i class="ti ti-message-report"></i> Rückmeldung geben oder Fehler melden</a></p>
    <p class="tp-fb-adr">Klappt der Knopf nicht, schreib direkt an <a href="<?= h($fbLink) ?>"><?= h($fb) ?></a>.</p>
  <?php endif; ?>
  <p><?= h(tplan_traeger()) ?>
    <?php if ($ok($imprint)): ?> · <a href="<?= h($imprint) ?>" target="_blank" rel="noopener">Impressum</a><?php endif; ?>
    <?php if ($ok($privacy)): ?> · <a href="<?= h($privacy) ?>" target="_blank" rel="noopener">Datenschutz</a><?php endif; ?>
  </p>
  <p class="pat-foot-line">Mit <i class="ti ti-heart-filled pat-heart" aria-hidden="true"></i> von Boj Petersen</p>
</footer>
<?php /* Doppel-Submit-Schutz: ganz am Ende, damit sein submit-Listener
         nach allen Seiten-Handlern läuft und deren preventDefault sieht. */ ?>
<script nonce="<?= h(tplan_nonce()) ?>" src="<?= h(tplan_asset('assets/doppelklick.js')) ?>"></script>
</body>
</html>
<?php
}

/** Kurzschluss, wenn der Dienst abgeschaltet ist. Gibt true zurück, wenn die Seite enden soll. */
function tplan_offline_guard(): bool
{
    if (tplan_service_on()) return false;
    http_response_code(503);
    tplan_head('Gerade nicht verfügbar', '', true, 'pat-body cinema');
    echo '<div class="pat-in"><div class="tp-col"><div class="card"><h1>Kurze Pause</h1>'
       . '<p>Der Terminplaner ist im Moment abgeschaltet. Bitte versuch es später noch einmal.</p></div></div></div>';
    tplan_foot();
    return true;
}

/**
 * Die Feineinstellungen einer Terminumfrage als Formularfelder.
 *
 * Sie stehen an ZWEI Stellen (beim Anlegen und beim Ändern) und dürfen dort nicht
 * auseinanderlaufen: Was hier fehlt, verliert man beim Bearbeiten stillschweigend.
 * $an(name, vorgabe) liefert den Zustand eines Kästchens, $v(name, vorgabe) den eines Feldes.
 */
function tplan_settings_fields(callable $an, callable $v, bool $mitZugang = true): void
{
    $ergebnis = (string)$v('show_results', 'always');
    $mail     = (string)$v('ask_mail', 'off');
    $edit     = (string)$v('edit_mode', 'all');
    if (!isset(tplan_edit_modes()[$edit])) $edit = 'all';
    ?>
    <div class="tp-set">
      <div class="tp-set-block">
        <h3>Antworten</h3>
        <label class="um-opt-l"><input type="checkbox" name="allow_maybe" value="1" <?= $an('allow_maybe', true) ? 'checked' : '' ?>>
          <span><strong>„Vielleicht" anbieten</strong><br><span class="um-klein">Drei Antworten statt nur Ja und Nein.</span></span></label>
        <label class="um-opt-l"><input type="checkbox" name="ask_comment" value="1" <?= $an('ask_comment', true) ? 'checked' : '' ?>>
          <span><strong>Bemerkungsfeld anbieten</strong><br><span class="um-klein">Für Hinweise wie „nur bis 16 Uhr".</span></span></label>
      </div>

      <?php if ($mitZugang): /* Beim Anlegen steht das als großer Umschalter über dem Formular –
                                dieselbe Sache an zwei Stellen wäre nur verwirrend. */ ?>
      <div class="tp-set-block">
        <h3>Wer darf Antworten ändern?</h3>
        <div class="um-wahl">
          <label class="um-opt-l"><input type="radio" name="edit_mode" value="all" <?= $edit === 'all' ? 'checked' : '' ?>>
            <span><strong>Alle dürfen jeden Eintrag ändern</strong><br><span class="um-klein">Der Normalfall: Tippfehler
              korrigieren, für jemanden nachtragen, der gerade nicht am Rechner ist.</span></span></label>
          <label class="um-opt-l"><input type="radio" name="edit_mode" value="own" <?= $edit === 'own' ? 'checked' : '' ?>>
            <span><strong>Nur die eigene Antwort</strong><br><span class="um-klein">Über den persönlichen Link, den jede
              Person nach dem Eintragen bekommt.</span></span></label>
          <label class="um-opt-l"><input type="radio" name="edit_mode" value="none" <?= $edit === 'none' ? 'checked' : '' ?>>
            <span><strong>Gar nicht</strong><br><span class="um-klein">Einmal eingetragen, bleibt es stehen.</span></span></label>
        </div>
      </div>
      <?php endif; ?>

      <div class="tp-set-block">
        <h3>Wer sieht das Ergebnis?</h3>
        <div class="um-wahl">
          <label class="um-opt-l"><input type="radio" name="show_results" value="always" <?= $ergebnis === 'always' ? 'checked' : '' ?>>
            <span><strong>Alle, sofort</strong><br><span class="um-klein">Der Normalfall – man sieht, wo sich etwas trifft.</span></span></label>
          <label class="um-opt-l"><input type="radio" name="show_results" value="after" <?= $ergebnis === 'after' ? 'checked' : '' ?>>
            <span><strong>Alle, aber erst am Ende</strong><br><span class="um-klein">Damit die ersten Antworten die späteren nicht lenken.</span></span></label>
          <label class="um-opt-l"><input type="radio" name="show_results" value="orga" <?= $ergebnis === 'orga' ? 'checked' : '' ?>>
            <span><strong>Nur ich</strong><br><span class="um-klein">Teilnehmende sehen ausschließlich ihre eigene Antwort.</span></span></label>
        </div>
        <label class="um-opt-l tp-set-sep"><input type="checkbox" name="show_names" value="1" <?= $an('show_names', true) ? 'checked' : '' ?>>
          <span><strong>Namen im Ergebnis zeigen</strong><br><span class="um-klein">Ohne Haken erscheinen nur Zahlen.</span></span></label>
      </div>

      <?php if ($mitZugang): ?>
      <div class="tp-set-block">
        <h3>Mailadresse der Teilnehmenden</h3>
        <div class="um-wahl">
          <label class="um-opt-l"><input type="radio" name="ask_mail" value="off" <?= $mail === 'off' ? 'checked' : '' ?>>
            <span><strong>Nicht fragen</strong><br><span class="um-klein">Empfohlen: Was man nicht erhebt, kann nicht verloren gehen.</span></span></label>
          <label class="um-opt-l"><input type="radio" name="ask_mail" value="optional" <?= $mail === 'optional' ? 'checked' : '' ?>>
            <span><strong>Freiwillig</strong></span></label>
          <label class="um-opt-l"><input type="radio" name="ask_mail" value="required" <?= $mail === 'required' ? 'checked' : '' ?>>
            <span><strong>Pflicht</strong></span></label>
        </div>
        <label class="um-opt-l tp-set-sep"><input type="checkbox" name="mail_confirm" value="1" <?= $an('mail_confirm', true) ? 'checked' : '' ?>>
          <span><strong>Bestätigung per Mail schicken</strong><br><span class="um-klein">Wer eine Adresse angibt, bekommt
            seine Antwort bestätigt – mit dem persönlichen Link zum Ändern. Damit geht das auch noch in zwei Wochen,
            von jedem Gerät aus.</span></span></label>
        <p class="um-klein">Sonst verschickt der Terminplaner <strong>nichts</strong> an Teilnehmende: keine Erinnerungen,
          keine Werbung. Die Adressen stehen in deiner Übersicht, damit du dich melden kannst.</p>
      </div>
      <?php endif; ?>

      <div class="tp-set-block">
        <h3>Höchstzahl</h3>
        <div class="tp-feld">
          <label for="max_entries">Rückmeldungen insgesamt</label>
          <input type="number" name="max_entries" id="max_entries" min="0" max="<?= (int)tplan_limits()['entries'] ?>"
                 placeholder="unbegrenzt" value="<?= h((string)$v('max_entries', '')) ?>">
          <p class="um-klein">Leer oder 0 heißt unbegrenzt (bis <?= (int)tplan_limits()['entries'] ?>).</p>
        </div>
      </div>
    </div>
    <?php
}

/**
 * Der große Umschalter: Wie kommen die Leute zu ihrer Antwort zurück?
 *
 * Steht beim Anlegen über allen Feineinstellungen, weil er die Umfrage grundsätzlicher prägt als
 * alles andere: Er entscheidet, ob überhaupt Mailadressen im Spiel sind.
 */
function tplan_zugang_wahl(string $aktuell = 'offen'): void
{
    if (!isset(tplan_zugang_modi()[$aktuell])) $aktuell = 'offen';
    ?>
    <div class="tp-zugang">
      <label class="tp-zug<?= $aktuell === 'offen' ? ' ist-an' : '' ?>">
        <input type="radio" name="zugang" value="offen" <?= $aktuell === 'offen' ? 'checked' : '' ?>>
        <span class="tp-zug-ico"><i class="ti ti-users-group"></i></span>
        <span class="tp-zug-t">Offene Liste</span>
        <span class="tp-zug-d">Keine Mailadressen. Alle sehen die Liste und dürfen jeden Eintrag ändern –
          für Tippfehler oder wenn jemand gerade nicht am Rechner sitzt.</span>
        <span class="tp-zug-e">Am schnellsten. Passt für Gruppen, die sich kennen.</span>
      </label>
      <label class="tp-zug<?= $aktuell === 'mail' ? ' ist-an' : '' ?>">
        <input type="radio" name="zugang" value="mail" <?= $aktuell === 'mail' ? 'checked' : '' ?>>
        <span class="tp-zug-ico"><i class="ti ti-mail-forward"></i></span>
        <span class="tp-zug-t">Persönlicher Link per Mail</span>
        <span class="tp-zug-d">Jede Person gibt ihre Mailadresse an und bekommt ihren eigenen Link.
          Damit ändert sie ihre Antwort – und nur die – auch Wochen später von jedem Gerät aus.</span>
        <span class="tp-zug-e">Verbindlicher. Passt für größere oder offene Runden.</span>
      </label>
    </div>
    <?php
}

/**
 * Ein Kasten mit einem Link zum Kopieren. Der Knopf braucht JavaScript; ohne funktioniert
 * das Feld trotzdem – man kann den Text markieren und von Hand kopieren.
 */
function tplan_copybox(string $id, string $label, string $url, string $hinweis = '', string $ton = ''): void
{
    ?>
    <div class="tp-copy<?= $ton !== '' ? ' ' . h($ton) : '' ?>">
      <label for="<?= h($id) ?>"><?= h($label) ?></label>
      <div class="tp-copy-row">
        <input type="text" id="<?= h($id) ?>" value="<?= h($url) ?>" readonly spellcheck="false">
        <button class="btn secondary tp-copy-btn" type="button" data-copy="<?= h($id) ?>"><i class="ti ti-copy"></i> Kopieren</button>
      </div>
      <?php if ($hinweis !== ''): ?><p class="um-klein"><?= h($hinweis) ?></p><?php endif; ?>
    </div>
    <?php
}

/**
 * Der Datums- und Uhrzeit-Wähler der App – derselbe wie überall sonst.
 *
 * Nicht die nackten Browser-Felder: Die sehen in jedem Browser anders aus, auf dem Desktop
 * meist unschön, und passen zu nichts anderem auf der Seite.
 *
 * Zur CSP: flatpickr läuft darunter problemlos. Positionen setzt er über die CSSOM
 * (`element.style.left = …`), und die erfasst `style-src` gar nicht; für seine eine
 * eingefügte Regel sucht er sich ein vorhandenes Stylesheet und legt nur im Notfall ein
 * eigenes an. Die beiden Skript-Dateien brauchen die Nonce – deshalb steht das hier und
 * nicht als loses <script> in den Seiten.
 *
 * Die Felder bleiben im Quelltext bewusst `type="date"` bzw. `type="time"`: flatpickr macht
 * beim Start selbst Textfelder daraus, und ohne JavaScript bleibt so wenigstens der
 * Browser-Wähler übrig statt eines Felds, in das man ein Datumsformat raten muss.
 */
function tplan_picker_script(): void
{
    $n = tplan_nonce();
    ?>
<script nonce="<?= h($n) ?>" src="<?= h(tplan_asset('assets/flatpickr/flatpickr.min.js')) ?>"></script>
<script nonce="<?= h($n) ?>" src="<?= h(tplan_asset('assets/flatpickr/de.js')) ?>"></script>
<script nonce="<?= h($n) ?>">
(function(){
  if (!window.flatpickr) return;
  flatpickr.localize(flatpickr.l10ns.de);
  // Einzeln aufrufbar, damit auch nachträglich eingefügte Zeilen einen Wähler bekommen.
  window.tplanPicker = function(el){
    if (!el || el._flatpickr) return;
    if (el.classList.contains('fp-date')) {
      flatpickr(el, {dateFormat:'Y-m-d', altInput:true, altFormat:'D, d.m.Y'});
    } else if (el.classList.contains('fp-time')) {
      flatpickr(el, {enableTime:true, noCalendar:true, time_24hr:true, dateFormat:'H:i', minuteIncrement:15});
    }
  };
  document.querySelectorAll('.fp-date, .fp-time').forEach(window.tplanPicker);
})();
</script>
    <?php
}

/** Das kleine Skript für die Kopier-Knöpfe. Einmal je Seite ausgeben, ganz am Ende. */
function tplan_copy_script(): void
{
    ?>
<script nonce="<?= h(tplan_nonce()) ?>">
(function(){
  document.querySelectorAll('.tp-copy-btn').forEach(function(b){
    b.addEventListener('click', function(){
      var f = document.getElementById(b.getAttribute('data-copy'));
      if (!f) return;
      f.select(); f.setSelectionRange(0, 99999);
      var fertig = function(){
        var alt = b.innerHTML;
        b.innerHTML = '<i class="ti ti-check"></i> Kopiert';
        b.classList.add('is-done');
        setTimeout(function(){ b.innerHTML = alt; b.classList.remove('is-done'); }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(f.value).then(fertig, function(){ try { document.execCommand('copy'); fertig(); } catch(e){} });
      } else {
        try { document.execCommand('copy'); fertig(); } catch(e){}
      }
    });
  });
})();
</script>
    <?php
}
