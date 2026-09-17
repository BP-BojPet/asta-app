<?php
/**
 * Externe Events – Bootstrap des ÖFFENTLICHEN Teils.
 *
 * Wie bei Pat:innenprogramm und Umfragen: Die lib.php der App wird ABSICHTLICH NICHT
 * eingebunden. Eigene Sitzung, eigener Cookie, eigene Fehlerbehandlung.
 */

declare(strict_types=1);

require_once __DIR__ . '/../extern-db.php';

const ANM_LOG_FILE = __DIR__ . '/../data/error.log';

function anm_log(string $msg): void
{
    $where = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
    @file_put_contents(ANM_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ANMELDUNG: ' . trim($msg)
        . ' | ' . trim($where) . "\n", FILE_APPEND | LOCK_EX);
}

error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    if (in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    anm_log('PHP-Warnung: ' . $str . ' in ' . $file . ':' . $line);
    return true;
});
set_exception_handler(function (\Throwable $e) {
    anm_log('Unbehandelte Exception ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<p>Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.</p>';
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        anm_log('FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

function anm_is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/anmeldung/index.php'))), '/') . '/';
    // Vom Browser vorgegebene Sitzungs-Kennungen nicht übernehmen: Müll erzeugt sonst
    // Warnungen und eine leere Sitzung, und eine vorgegebene Kennung wäre angreifbar.
    @ini_set('session.use_strict_mode', '1');
    session_name('asta_anm_sid');
    session_set_cookie_params(['path' => $dir, 'httponly' => true, 'samesite' => 'Lax', 'secure' => anm_is_https()]);
    session_start();
}

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function anm_param($v): string
{
    if (is_string($v)) return $v;
    if (is_int($v) || is_float($v)) return (string)$v;
    return '';
}

function anm_csrf_token(): string
{
    if (empty($_SESSION['anm_csrf'])) $_SESSION['anm_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['anm_csrf'];
}

function anm_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(anm_csrf_token()) . '">';
}

function anm_check_csrf(): void
{
    $t = anm_param($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals(anm_csrf_token(), $t)) {
        http_response_code(400);
        exit('Ungültige Anfrage. Bitte die Seite neu laden und noch einmal versuchen.');
    }
}

/** Bremse gegen Massenanmeldungen aus einer Sitzung. */
function anm_rate_ok(int $proStunde = 5): bool
{
    $jetzt = time();
    $liste = array_values(array_filter((array)($_SESSION['anm_hits'] ?? []), fn($t) => $t > $jetzt - 3600));
    if (count($liste) >= $proStunde) { $_SESSION['anm_hits'] = $liste; return false; }
    $liste[] = $jetzt;
    $_SESSION['anm_hits'] = $liste;
    return true;
}

function anm_asset(string $rel): string
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

function anm_nonce(): string
{
    static $n = null;
    if ($n === null) $n = base64_encode(random_bytes(16));
    return $n;
}

/** Datum/Uhrzeit menschlich. */
function anm_dt(string $sql, bool $mitZeit = true): string
{
    $t = strtotime($sql);
    if (!$t) return '';
    return date('d.m.Y', $t) . ($mitZeit && date('H:i', $t) !== '00:00' ? ', ' . date('H:i', $t) . ' Uhr' : '');
}

function anm_head(string $title, string $desc = '', bool $noindex = false): void
{
    $nonce = anm_nonce();
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; "
        . "style-src 'self'; img-src 'self' data:; font-src 'self'; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-cache, must-revalidate');
    if ($noindex) header('X-Robots-Tag: noindex, nofollow');
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<script nonce="<?= h($nonce) ?>">(function(){try{var k='pat-theme',m=localStorage.getItem(k)||'system';if(m!=='light'&&m!=='dark')m='system';var d=document.documentElement;d.setAttribute('data-theme-mode',m);d.setAttribute('data-theme',m==='system'?((window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light'):m);var mt=document.querySelector('meta[name="theme-color"]');if(mt)mt.setAttribute('content',d.getAttribute('data-theme')==='dark'?'#111518':'#ffffff');}catch(e){}})();</script>
<title><?= h($title) ?> · <?= h(extern_traeger()) ?></title>
<?php if ($noindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if ($desc !== ''): ?><meta name="description" content="<?= h($desc) ?>">
<meta property="og:description" content="<?= h($desc) ?>"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h(extern_traeger()) ?>">
<meta property="og:title" content="<?= h($title) ?>">
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= h(anm_asset('assets/tabler/fonts/tabler-icons.woff2')) ?>">
<link rel="stylesheet" href="<?= h(anm_asset('assets/tabler/tabler-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= h(anm_asset('assets/style.css')) ?>">
<link rel="stylesheet" href="<?= h(anm_asset('assets/pat.css')) ?>">
<link rel="stylesheet" href="<?= h(anm_asset('assets/umfrage.css')) ?>">
<link rel="stylesheet" href="<?= h(anm_asset('assets/anmeldung.css')) ?>">
<link rel="icon" type="image/png" href="<?= h(anm_asset('assets/logo-header.png')) ?>">
</head>
<body class="pat-body">
<header class="pat-top">
  <div class="pat-top-in">
    <div class="pat-brand">
      <img class="pat-logo" src="<?= h(anm_asset('assets/logo-header.png')) ?>" alt="<?= h(extern_traeger()) ?>" width="150" height="40">
      <span class="pat-brand-x"><strong><?= h(extern_traeger()) ?></strong><small>Anmeldung</small></span>
    </div>
  </div>
</header>
<main class="pat-main">
<?php
}

function anm_foot(): void
{
    $imprint = trim(extern_setting_get('imprint_url', ''));
    $privacy = trim(extern_setting_get('privacy_url', ''));
    $ok = fn(string $u) => preg_match('~^https?://~i', $u) && filter_var($u, FILTER_VALIDATE_URL);
    ?>
</main>
<footer class="pat-foot">
  <p><?= h(extern_traeger()) ?>
    <?php if ($ok($imprint)): ?> · <a href="<?= h($imprint) ?>" target="_blank" rel="noopener">Impressum</a><?php endif; ?>
    <?php if ($ok($privacy)): ?> · <a href="<?= h($privacy) ?>" target="_blank" rel="noopener">Datenschutz</a><?php endif; ?>
  </p>
  <p class="pat-foot-line">Mit <i class="ti ti-heart-filled pat-heart" aria-hidden="true"></i> von Boj Petersen</p>
</footer>
<?php /* Doppel-Submit-Schutz: ganz am Ende, damit sein submit-Listener
         nach allen Seiten-Handlern läuft und deren preventDefault sieht. */ ?>
<script nonce="<?= h(anm_nonce()) ?>" src="<?= h(anm_asset('assets/doppelklick.js')) ?>"></script>
</body>
</html>
<?php
}

/**
 * Ein Formularfeld ausgeben. $vor = bisheriger Wert (beim Ändern oder nach einem Fehler),
 * $frei = ob die Antwortmöglichkeiten noch Platz haben.
 */
function anm_field(array $f, $vor = null): void
{
    $fid = (int)$f['id'];
    $typ = (string)$f['type'];
    ?>
    <div class="an-feld">
      <label for="f<?= $fid ?>"><?= h($f['label']) ?><?php if ((int)$f['required'] === 1): ?> <span class="an-pflicht">*</span><?php endif; ?></label>
      <?php if (trim((string)$f['help']) !== ''): ?><p class="um-klein an-hilfe"><?= h($f['help']) ?></p><?php endif; ?>
      <?php if ($typ === 'single' || $typ === 'multi'): $gewaehlt = array_map('intval', (array)$vor); ?>
        <div class="um-wahl">
          <?php foreach ($f['options'] as $o): $oid = (int)$o['id'];
                $voll = !extern_option_free($o) && !in_array($oid, $gewaehlt, true); ?>
            <label class="um-opt-l<?= $voll ? ' an-voll' : '' ?>">
              <input type="<?= $typ === 'single' ? 'radio' : 'checkbox' ?>" name="f[<?= $fid ?>]<?= $typ === 'multi' ? '[]' : '' ?>"
                     value="<?= $oid ?>" <?= in_array($oid, $gewaehlt, true) ? 'checked' : '' ?> <?= $voll ? 'disabled' : '' ?>>
              <span><?= h($o['label']) ?><?php if ((int)$o['capacity'] > 0): ?>
                <span class="um-klein"><?= $voll ? '· ausgebucht' : '· noch ' . max(0, (int)$o['capacity'] - extern_option_taken($oid)) . ' frei' ?></span>
              <?php endif; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php elseif ($typ === 'bool'): ?>
        <label class="um-opt-l"><input type="checkbox" name="f[<?= $fid ?>]" value="1" <?= !empty($vor) ? 'checked' : '' ?>><span>Ja</span></label>
      <?php elseif ($typ === 'number'): ?>
        <input type="number" name="f[<?= $fid ?>]" id="f<?= $fid ?>" value="<?= h(is_array($vor) ? '' : (string)$vor) ?>"
               <?= $f['num_min'] !== null ? 'min="' . (int)$f['num_min'] . '"' : '' ?> <?= $f['num_max'] !== null ? 'max="' . (int)$f['num_max'] . '"' : '' ?>>
      <?php elseif ($typ === 'textarea'): ?>
        <textarea name="f[<?= $fid ?>]" id="f<?= $fid ?>" rows="4" maxlength="2000"><?= h(is_array($vor) ? '' : (string)$vor) ?></textarea>
      <?php else: ?>
        <input type="text" name="f[<?= $fid ?>]" id="f<?= $fid ?>" maxlength="2000" value="<?= h(is_array($vor) ? '' : (string)$vor) ?>">
      <?php endif; ?>
    </div>
    <?php
}
