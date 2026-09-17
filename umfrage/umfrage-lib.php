<?php
/**
 * Umfragen – Bootstrap des ÖFFENTLICHEN Teils.
 *
 * Wie beim Pat:innenprogramm wird die lib.php der App ABSICHTLICH NICHT eingebunden: keine
 * App-Funktion, keine Verbindung zur Mitgliederdatenbank, eigene Sitzung mit eigenem Cookie.
 * Was hier nicht existiert, kann auch nicht missbraucht werden.
 *
 * Aussehen: die echte assets/style.css der App plus ein kleines umfrage.css. Es gibt also
 * keine zweite Designsprache zu pflegen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../umfrage-db.php';

const UMFRAGE_LOG_FILE = __DIR__ . '/../data/error.log';

function umfrage_log(string $msg): void
{
    $where = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
    @file_put_contents(UMFRAGE_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] UMFRAGE: ' . trim($msg)
        . ' | ' . trim($where) . "\n", FILE_APPEND | LOCK_EX);
}

error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    if (in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    umfrage_log('PHP-Warnung: ' . $str . ' in ' . $file . ':' . $line);
    return true;
});
set_exception_handler(function (\Throwable $e) {
    umfrage_log('Unbehandelte Exception ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<p>Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.</p>';
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        umfrage_log('FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

/** Läuft die Anfrage über HTTPS? (Reverse-Proxy-Header wird mitbedacht.) */
function umfrage_is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/umfrage/index.php'))), '/') . '/';
    // Vom Browser vorgegebene Sitzungs-Kennungen nicht übernehmen: Müll erzeugt sonst
    // Warnungen und eine leere Sitzung, und eine vorgegebene Kennung wäre angreifbar.
    @ini_set('session.use_strict_mode', '1');
    session_name('asta_umfrage_sid');
    session_set_cookie_params([
        'path' => $dir, 'httponly' => true, 'samesite' => 'Lax', 'secure' => umfrage_is_https(),
    ]);
    session_start();
}

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Parameter als Zeichenkette lesen – `?u[]=x` liefert sonst ein Array. */
function umfrage_param($v): string
{
    if (is_string($v)) return $v;
    if (is_int($v) || is_float($v)) return (string)$v;
    return '';
}

function umfrage_csrf_token(): string
{
    if (empty($_SESSION['umfrage_csrf'])) $_SESSION['umfrage_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['umfrage_csrf'];
}

function umfrage_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(umfrage_csrf_token()) . '">';
}

function umfrage_check_csrf(): void
{
    $t = umfrage_param($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals(umfrage_csrf_token(), $t)) {
        http_response_code(400);
        exit('Ungültige Anfrage. Bitte die Seite neu laden und noch einmal versuchen.');
    }
}

/**
 * Bremse gegen Missbrauch: Ohne Begrenzung könnte jemand fremde Adressen eintippen und
 * Leute mit Mails zuschütten. Zählt je Besuchssitzung und Stunde.
 */
function umfrage_rate_ok(): bool
{
    $jetzt = time();
    $liste = array_values(array_filter((array)($_SESSION['umfrage_hits'] ?? []), fn($t) => $t > $jetzt - 3600));
    if (count($liste) >= UMFRAGE_RATE_PER_HOUR) { $_SESSION['umfrage_hits'] = $liste; return false; }
    $liste[] = $jetzt;
    $_SESSION['umfrage_hits'] = $liste;
    return true;
}

function umfrage_asset(string $rel): string
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

function umfrage_nonce(): string
{
    static $n = null;
    if ($n === null) $n = base64_encode(random_bytes(16));
    return $n;
}

/** Datum/Uhrzeit menschlich (die App-Helfer stehen hier nicht zur Verfügung). */
function umfrage_dt(string $sql): string
{
    $t = strtotime($sql);
    if (!$t) return '';
    return date('d.m.Y', $t) . (date('H:i', $t) === '23:59' ? '' : ', ' . date('H:i', $t) . ' Uhr');
}

// ---------------------------------------------------------------------------
// Seitengerüst
// ---------------------------------------------------------------------------

function umfrage_head(string $title, string $desc = '', bool $noindex = false): void
{
    $nonce = umfrage_nonce();
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; "
        . "style-src 'self'; img-src 'self' data:; font-src 'self'; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-cache, must-revalidate');
    // Der Stimmzettel steckt hinter einem Einmal-Link – der gehört in keinen Suchindex.
    if ($noindex) header('X-Robots-Tag: noindex, nofollow');
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<script nonce="<?= h($nonce) ?>">(function(){try{var k='pat-theme',m=localStorage.getItem(k)||'system';if(m!=='light'&&m!=='dark')m='system';var d=document.documentElement;d.setAttribute('data-theme-mode',m);d.setAttribute('data-theme',m==='system'?((window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light'):m);var mt=document.querySelector('meta[name="theme-color"]');if(mt)mt.setAttribute('content',d.getAttribute('data-theme')==='dark'?'#111518':'#ffffff');}catch(e){}})();</script>
<title><?= h($title) ?> · <?= h(umfrage_traeger()) ?></title>
<?php if ($noindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if ($desc !== ''): ?><meta name="description" content="<?= h($desc) ?>"><?php endif; ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= h(umfrage_asset('assets/tabler/fonts/tabler-icons.woff2')) ?>">
<link rel="stylesheet" href="<?= h(umfrage_asset('assets/tabler/tabler-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= h(umfrage_asset('assets/style.css')) ?>">
<link rel="stylesheet" href="<?= h(umfrage_asset('assets/pat.css')) ?>">
<link rel="stylesheet" href="<?= h(umfrage_asset('assets/umfrage.css')) ?>">
<link rel="icon" type="image/png" href="<?= h(umfrage_asset('assets/logo-header.png')) ?>">
</head>
<body class="pat-body">
<header class="pat-top">
  <div class="pat-top-in">
    <div class="pat-brand">
      <img class="pat-logo" src="<?= h(umfrage_asset('assets/logo-header.png')) ?>" alt="<?= h(umfrage_traeger()) ?>" width="150" height="40">
      <span class="pat-brand-x"><strong><?= h(umfrage_traeger()) ?></strong><small>Umfrage</small></span>
    </div>
  </div>
</header>
<main class="pat-main">
<?php
}

function umfrage_foot(): void
{
    $imprint = trim(umfrage_setting_get('imprint_url', ''));
    $privacy = trim(umfrage_setting_get('privacy_url', ''));
    $ok = fn(string $u) => preg_match('~^https?://~i', $u) && filter_var($u, FILTER_VALIDATE_URL);
    ?>
</main>
<footer class="pat-foot">
  <p><?= h(umfrage_traeger()) ?>
    <?php if ($ok($imprint)): ?> · <a href="<?= h($imprint) ?>" target="_blank" rel="noopener">Impressum</a><?php endif; ?>
    <?php if ($ok($privacy)): ?> · <a href="<?= h($privacy) ?>" target="_blank" rel="noopener">Datenschutz</a><?php endif; ?>
  </p>
  <p class="pat-foot-line">Mit <i class="ti ti-heart-filled pat-heart" aria-hidden="true"></i> von Boj Petersen</p>
</footer>
<?php /* Doppel-Submit-Schutz: ganz am Ende, damit sein submit-Listener
         nach allen Seiten-Handlern läuft und deren preventDefault sieht. */ ?>
<script nonce="<?= h(umfrage_nonce()) ?>" src="<?= h(umfrage_asset('assets/doppelklick.js')) ?>"></script>
</body>
</html>
<?php
}
