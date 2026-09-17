<?php
/**
 * Pat:innenprogramm – Bootstrap des ÖFFENTLICHEN Teils.
 *
 * Diese Datei bindet lib.php ABSICHTLICH NICHT ein. Damit ist auf den öffentlichen Seiten
 * keine einzige App-Funktion geladen – kein login_member(), kein require_login(), keine
 * Verbindung zur App-Datenbank. Was hier nicht existiert, kann auch nicht missbraucht werden.
 *
 * Eigene Sitzung: eigener Cookie-Name und eigener Pfad. Ein Studi-Besuch kann die Sitzung eines
 * angemeldeten Mitglieds also nicht anfassen (und umgekehrt).
 *
 * Aussehen: die öffentlichen Seiten verlinken die ECHTE assets/style.css der App (statische
 * Datei, kein PHP) und bringen nur ein kleines pat.css für die Eigenheiten des öffentlichen
 * Bereichs mit. Es gibt also keine zweite Designsprache zu pflegen. Verfügbar sind bewusst nur
 * Hell/Dunkel/System – die freigespielten Skins bleiben in der App.
 */

declare(strict_types=1);

require_once __DIR__ . '/../pat-db.php';

// ---------------------------------------------------------------------------
// Fehlerbehandlung: nie etwas an Besucher:innen ausgeben, alles ins App-Log
// (dieselbe Datei wie die App → die Diagnose-Seite sieht auch Fehler von hier).
// ---------------------------------------------------------------------------
const PAT_LOG_FILE = __DIR__ . '/../data/error.log';

function pat_log(string $msg): void
{
    $where = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
    @file_put_contents(PAT_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] PAT: ' . trim($msg)
        . ' | ' . trim($where) . "\n", FILE_APPEND | LOCK_EX);
    if (@filesize(PAT_LOG_FILE) > 1048576) {
        $l = @file(PAT_LOG_FILE);
        if ($l) @file_put_contents(PAT_LOG_FILE, implode('', array_slice($l, -500)));
    }
}

error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    if (in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    pat_log('PHP-Warnung: ' . $str . ' in ' . $file . ':' . $line);
    return true;
});
set_exception_handler(function (\Throwable $e) {
    pat_log('Unbehandelte Exception ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    // Ohne style="…" – die Seiten laufen unter einer CSP ohne 'unsafe-inline'.
    echo '<p>Es ist ein Fehler aufgetreten. Bitte später erneut versuchen '
        . 'oder eine Mail an den AStA schreiben.</p>';
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        pat_log('FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

// ---------------------------------------------------------------------------
// Eigene Sitzung (getrennt von der App-Sitzung)
// ---------------------------------------------------------------------------

/** Läuft die Anfrage über HTTPS? (Reverse-Proxy-Header wird mitbedacht.) */
function pat_is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/pat/index.php'))), '/') . '/';
    // Vom Browser vorgegebene Sitzungs-Kennungen nicht übernehmen: Müll erzeugt sonst
    // Warnungen und eine leere Sitzung, und eine vorgegebene Kennung wäre angreifbar.
    @ini_set('session.use_strict_mode', '1');
    session_name('asta_pat_sid');
    session_set_cookie_params([
        'path'     => $dir,        // gilt nur im öffentlichen Verzeichnis
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => pat_is_https(),
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// Helfer
// ---------------------------------------------------------------------------

/** HTML-Escaping. Guard, falls diese Datei je in einem Kontext mit lib.php landet. */
if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Einen Parameter aus $_GET/$_POST als Zeichenkette lesen.
 *
 * Nötig, weil `?p[]=x` ein ARRAY liefert: ein blindes (string)-Cast wirft dann „Array to string
 * conversion" ins Fehler-Log (gesehen bei ?p[]=a&p[]=b). Alles, was keine Zeichenkette und keine
 * Zahl ist, gilt hier schlicht als nicht angegeben – die Seiten behandeln das ohnehin schon.
 */
function pat_param($v): string
{
    if (is_string($v)) return $v;
    if (is_int($v) || is_float($v)) return (string)$v;
    return '';
}

/** Mehrzeiligen Klartext sicher als Absätze ausgeben (kein HTML aus der Datenbank). */
function pat_paragraphs(string $text): string
{
    $out = '';
    foreach (preg_split('/\n{2,}/', trim($text)) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $out .= '<p>' . nl2br(h($p)) . '</p>';
    }
    return $out;
}

/**
 * Fertiges HTML für einen pflegbaren Text, in dem {{DATENSCHUTZ}} zum Link auf die
 * Datenschutzerklärung wird.
 *
 * Erst escapen, dann den Platzhalter ersetzen – so kann aus dem Text selbst niemals Markup
 * entstehen, der Link aber schon. Ist keine Adresse hinterlegt, bleibt schlicht das Wort
 * stehen, statt einen Link ins Nichts zu bauen.
 */
function pat_text_privacy_html(string $text): string
{
    $url  = trim(pat_setting_get('privacy_url', ''));
    $word = 'Datenschutzerklärung';
    // Nur http(s): FILTER_VALIDATE_URL allein lässt z. B. „javascript://x%0Aalert(1)" durch.
    // Beim Speichern wird das schon geprüft – die Ausgabe verlässt sich trotzdem nicht darauf.
    if (!preg_match('~^https?://~i', $url)) $url = '';
    $rep  = ($url !== '' && filter_var($url, FILTER_VALIDATE_URL))
        ? '<a href="' . h($url) . '" target="_blank" rel="noopener">' . h($word) . '</a>'
        : h($word);
    return str_replace('{{DATENSCHUTZ}}', $rep, nl2br(h(trim($text))));
}

/** Asset-URL mit Versionsstempel (gegen veraltete Browser-Caches). */
function pat_asset(string $rel): string
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

/** CSRF-Token dieser (öffentlichen) Sitzung. */
function pat_csrf_token(): string
{
    if (empty($_SESSION['pat_csrf'])) $_SESSION['pat_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['pat_csrf'];
}

function pat_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(pat_csrf_token()) . '">';
}

/** CSRF prüfen – bei Verstoß Abbruch ohne Details. */
function pat_check_csrf(): void
{
    $t = pat_param($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals(pat_csrf_token(), $t)) {
        http_response_code(400);
        exit('Ungültige Anfrage. Bitte die Seite neu laden und noch einmal versuchen.');
    }
}

/** Einmal-Zufallswert für die Content-Security-Policy (erlaubt genau unsere zwei Skripte). */
function pat_nonce(): string
{
    static $n = null;
    if ($n === null) $n = base64_encode(random_bytes(16));
    return $n;
}

// ---------------------------------------------------------------------------
// Seitengerüst
// ---------------------------------------------------------------------------

/**
 * Rückkehr-Ziel für das Hilfe-Formular säubern.
 *
 * Der Wert kommt aus der Adresszeile, also von außen. Erlaubt sind AUSSCHLIESSLICH die beiden
 * eigenen Seiten mit ihrer Abfrage – damit kann niemand über ?von=… auf eine fremde Adresse
 * weiterleiten lassen. Alles andere wird zu ''.
 */
function pat_help_back(string $roh): string
{
    $roh = trim($roh);
    if ($roh === '' || strlen($roh) > 300) return '';
    if (!preg_match('~^(index|anmeldung)\.php(\?[^\s#]*)?$~', $roh, $m)) return '';
    $datei  = $m[1] . '.php';
    $abfrage = '';
    if (!empty($m[2])) {
        // Abfrage neu zusammensetzen statt durchreichen: so kann dort nichts stehen,
        // was wir nicht selbst geschrieben haben.
        parse_str(substr($m[2], 1), $teile);
        $rein = [];
        foreach (['p', 'r', 'd', 'c', 's', 'f'] as $k) {
            if (isset($teile[$k]) && is_string($teile[$k]) && $teile[$k] !== '') $rein[$k] = $teile[$k];
        }
        if ($rein) $abfrage = '?' . http_build_query($rein);
    }
    return $datei . $abfrage;
}

/**
 * Das Hilfe-Formular. EINE Quelle für beide Orte – die eigene Seite hilfe.php und den Dialog,
 * den der schwebende Knopf öffnet. Zwei Abschriften desselben Formulars wären genau die Art
 * von Doppelung, die irgendwann auseinanderläuft.
 *
 * $alt füllt die Felder nach einem Fehler wieder, damit niemand alles neu tippen muss.
 */
function pat_help_form(string $von, int $roundId, array $alt = [], string $idPräfix = 'hf'): string
{
    $w = fn (string $k) => h((string)($alt[$k] ?? ''));
    $id = fn (string $k) => h($idPräfix . '_' . $k);
    return '<form class="pat-form" method="post" action="hilfe.php" novalidate>'
        . pat_csrf_field()
        . '<input type="hidden" name="action" value="frage">'
        . '<input type="hidden" name="von" value="' . h($von) . '">'
        . '<input type="hidden" name="round" value="' . (int)$roundId . '">'
        // Honigtopf: für Menschen (und Screenreader) unsichtbar, Bots füllen ihn trotzdem
        . '<div class="pat-hp" aria-hidden="true"><label>Website'
        . '<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
        . '<div class="pat-field"><label for="' . $id('name') . '">Dein Name'
        . '<span class="pat-opt">freiwillig</span></label>'
        . '<input type="text" id="' . $id('name') . '" name="name" maxlength="120" '
        . 'autocomplete="name" value="' . $w('name') . '"></div>'
        . '<div class="pat-field"><label for="' . $id('email') . '">Deine E-Mail-Adresse</label>'
        . '<p class="pat-form-note">Brauchen wir, um dir antworten zu können. Sonst nichts.</p>'
        . '<input type="email" id="' . $id('email') . '" name="email" maxlength="190" required '
        . 'autocomplete="email" value="' . $w('email') . '"></div>'
        . '<div class="pat-field"><label for="' . $id('body') . '">Deine Frage</label>'
        . '<textarea id="' . $id('body') . '" name="body" maxlength="5000" required '
        . 'rows="6">' . $w('body') . '</textarea></div>'
        . '<button type="submit" class="pat-submit">'
        . '<i class="ti ti-send" aria-hidden="true"></i> Frage abschicken</button>'
        . '</form>';
}

/**
 * Kopf der öffentlichen Seite inklusive Linkvorschau (Open Graph / Twitter Card).
 * $canonical/$image bleiben leer, wenn in der Verwaltung keine Basis-URL hinterlegt ist –
 * dann gibt es eben keine Vorschau, aber auch keine geratene Adresse.
 */
function pat_head(string $title, string $desc = '', string $canonical = '', bool $noindex = false, string $mainClass = ''): void
{
    $nonce = pat_nonce();
    $base  = rtrim(pat_setting_get('base_url', ''), '/');
    $image = $base !== '' ? $base . '/' . brand_url('icon-512') : '';
    // $title wird 1:1 als og:title verwendet (das ist die Zeile in der Linkvorschau),
    // im <title> hängen wir nur den Absender an.
    $full  = pat_traeger();

    // Strikte Sicherheitsregeln: nichts von fremden Servern, keine Einbettung, kein Referrer.
    // connect-src 'self': der Hilfe-Dialog schickt die Frage per fetch an hilfe.php, damit eine
    // halb ausgefüllte Anmeldung nicht verlorengeht. Ohne diese Zeile griffe default-src 'none'
    // und der Versand wäre still blockiert. 'self' heißt: nur an diesen Server, sonst nirgendwohin.
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; "
        . "style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    if ($noindex) header('X-Robots-Tag: noindex, nofollow');
    // Ohne Cache-Vorgabe entscheidet jeder Browser selbst, wie lange er die Seite behält – und
    // manche behalten sie lange. Da im HTML die versionierte CSS-Adresse steht, veraltet mit
    // der Seite auch das Aussehen. Deshalb: immer nachfragen.
    // (no-cache heißt „vor dem Wiederverwenden prüfen", nicht „nie speichern" – der
    // Zurück-Knopf funktioniert weiter.)
    header('Cache-Control: no-cache, must-revalidate');
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<!-- Zoom bleibt erlaubt: das hier lesen fremde Menschen auf fremden Geräten. -->
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<script nonce="<?= h($nonce) ?>">(function(){try{var k='pat-theme',m=localStorage.getItem(k)||'system';if(m!=='light'&&m!=='dark')m='system';var d=document.documentElement;d.setAttribute('data-theme-mode',m);d.setAttribute('data-theme',m==='system'?((window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light'):m);var mt=document.querySelector('meta[name="theme-color"]');if(mt)mt.setAttribute('content',d.getAttribute('data-theme')==='dark'?'#111518':'#ffffff');}catch(e){}})();</script>
<title><?= h($title) ?> · <?= h($full) ?></title>
<?php if ($noindex): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php if ($desc !== ''): ?>
<meta name="description" content="<?= h($desc) ?>">
<?php endif; ?>
<?php /* Schmuck im Kopfband: Er steht in den Einstellungen, nicht im Stylesheet – dort
         lagen die Buchstabenformen aus dem Logo der Hochschule, und die gehören nicht in ein
         Programm, das weitergegeben wird. Ohne Eintrag bleibt das Band schlicht. */
      pat_hero_deko_uebernehmen();
      $patDeko = pat_hero_deko();
      if ($patDeko !== ''): ?>
<style>.pat-hero{--rptu:<?= $patDeko ?>}</style>
<?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h(pat_traeger()) ?>">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:locale" content="de_DE">
<?php if ($desc !== ''): ?>
<meta property="og:description" content="<?= h($desc) ?>">
<?php endif; ?>
<?php if ($canonical !== ''): ?>
<meta property="og:url" content="<?= h($canonical) ?>">
<link rel="canonical" href="<?= h($canonical) ?>">
<?php endif; ?>
<?php if ($image !== ''): ?>
<meta property="og:image" content="<?= h($image) ?>">
<meta name="twitter:image" content="<?= h($image) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= h($title) ?>">
<?php if ($desc !== ''): ?>
<meta name="twitter:description" content="<?= h($desc) ?>">
<?php endif; ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= h(pat_asset('assets/tabler/fonts/tabler-icons.woff2')) ?>">
<link rel="stylesheet" href="<?= h(pat_asset('assets/tabler/tabler-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= h(pat_asset('assets/style.css')) ?>">
<link rel="stylesheet" href="<?= h(pat_asset('assets/pat.css')) ?>">
<link rel="icon" type="image/png" href="<?= h(pat_asset('assets/logo-header.png')) ?>">
</head>
<body class="pat-body<?= $mainClass !== '' ? ' ' . h($mainClass) : '' ?>"><!-- „wide": Einstiegsseite mit den zwei großen Kacheln (Leiste, Inhalt und Fuß gemeinsam breiter) -->
<header class="pat-top">
  <div class="pat-top-in">
    <div class="pat-brand">
      <img class="pat-logo" src="<?= h(pat_asset('assets/logo-header.png')) ?>" alt="<?= h(pat_traeger()) ?>" width="150" height="40">
      <span class="pat-brand-x">
        <strong><?= h(pat_traeger()) ?></strong>
        <small>Pat:innenprogramm</small>
      </span>
    </div>
    <div class="pat-theme" role="group" aria-label="Darstellung">
      <button type="button" data-mode="light"  aria-pressed="false" title="Hell"><i class="ti ti-sun" aria-hidden="true"></i><span class="pat-sr">Hell</span></button>
      <button type="button" data-mode="dark"   aria-pressed="false" title="Dunkel"><i class="ti ti-moon" aria-hidden="true"></i><span class="pat-sr">Dunkel</span></button>
      <button type="button" data-mode="system" aria-pressed="false" title="Wie das Gerät"><i class="ti ti-device-laptop" aria-hidden="true"></i><span class="pat-sr">Automatisch</span></button>
    </div>
  </div>
</header>
<main class="pat-main">
<?php
}

/**
 * Fuß der öffentlichen Seite (Impressum/Datenschutz aus der Verwaltung, falls hinterlegt).
 * $programLabel (z. B. „WiSe 2026/27") füllt {{PROGRAMM}} in den Hilfe-Texten.
 * $mitHilfe schaltet Knopf und Hilfe-Zeile ab – auf hilfe.php selbst wären sie albern.
 * $roundId hängt die Frage in der Verwaltung an das richtige Programm.
 */
function pat_foot(string $programLabel = '', bool $mitHilfe = true, int $roundId = 0): void
{
    $imprint = trim(pat_setting_get('imprint_url', ''));
    $privacy = trim(pat_setting_get('privacy_url', ''));

    // Hilfe gibt es an EINER Stelle: dem mitschwebenden Knopf unten rechts. Er ist von jedem
    // Punkt der Seite aus mit einem Griff da – eine zusätzliche Zeile im Fuß wäre nur dasselbe
    // Angebot ein zweites Mal, weiter unten und schlechter erreichbar.
    // Bedingung: die Verwaltung hat eine Paten-Mail hinterlegt (sonst käme die Frage nirgends
    // an) UND die Beschriftung wurde nicht bewusst geleert.
    $helpMail  = trim(pat_setting_get('paten_email', ''));
    $helpLabel = trim(pat_text_fill(pat_text('help_label'), ['{{PROGRAMM}}' => $programLabel]));
    $showHelp  = $mitHilfe && $helpLabel !== '' && $helpMail !== ''
              && filter_var($helpMail, FILTER_VALIDATE_EMAIL);

    // Von welcher Seite aus gefragt wird – damit es hinterher wieder dorthin zurückgeht.
    // Durch denselben Filter wie der Wert aus der Adresszeile, damit hier keine zweite,
    // laxere Regel entsteht.
    $hier   = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $abfrage = (string)($_SERVER['QUERY_STRING'] ?? '');
    $von    = pat_help_back($hier . ($abfrage !== '' ? '?' . $abfrage : ''));
    $hilfeHref = 'hilfe.php' . ($von !== '' ? '?von=' . rawurlencode($von) : '?von=')
               . ($roundId > 0 ? '&r=' . $roundId : '');

    $hRunde = $roundId > 0 ? pat_round_get($roundId) : null;
    $hText  = function (string $key) use ($hRunde, $programLabel): string {
        $vars = $hRunde ? pat_round_vars($hRunde) : ['{{PROGRAMM}}' => $programLabel];
        return trim(pat_text_fill(pat_text($key), $vars));
    };
    ?>
</main>
<?php if ($showHelp): ?>
<a class="pat-help" href="<?= h($hilfeHref) ?>">
  <i class="ti ti-message-circle-heart" aria-hidden="true"></i> <?= h($helpLabel) ?>
</a>
<?php // Derselbe Knopf öffnet mit JavaScript stattdessen diesen Dialog. Grund: wer mitten in
      // der Anmeldung steckt, soll durch eine Frage nicht seine Eingaben verlieren. Ohne
      // JavaScript bleibt der Link oben und führt schlicht auf die Seite. ?>
<dialog class="pat-dialog" id="patHilfe" aria-labelledby="patHilfeT">
  <div class="pat-dialog-in">
    <button type="button" class="pat-dialog-x" data-schliessen aria-label="Schließen">
      <i class="ti ti-x" aria-hidden="true"></i>
    </button>
    <h2 class="pat-dialog-t" id="patHilfeT">
      <?= h($hText('help_form_title') !== '' ? $hText('help_form_title') : 'Schreib uns') ?>
    </h2>
    <?php if ($hText('help_form_lead') !== ''): ?>
      <div class="pat-prose pat-lead"><?= pat_paragraphs($hText('help_form_lead')) ?></div>
    <?php endif; ?>
    <div class="pat-note err pat-dialog-fehler" role="alert" hidden>
      <i class="ti ti-alert-triangle" aria-hidden="true"></i>
      <div class="pat-note-b"></div>
    </div>
    <?= pat_help_form($von, $roundId, [], 'hd') ?>
    <div class="pat-done pat-dialog-fertig" hidden>
      <span class="pat-done-ico"><i class="ti ti-mail-check" aria-hidden="true"></i></span>
      <p class="pat-done-t">Danke für deine Frage!</p>
      <?php if ($hText('help_form_done') !== ''): ?>
        <div class="pat-done-b"><?= pat_paragraphs($hText('help_form_done')) ?></div>
      <?php endif; ?>
      <button type="button" class="btn-schliessen pat-submit" data-schliessen>Schließen</button>
    </div>
  </div>
</dialog>
<?php endif; ?>
<footer class="pat-foot">
  <span><strong><?= h(pat_traeger()) ?></strong> · Allgemeiner Studierendenausschuss</span>
  <?php if ($imprint !== '' && preg_match('~^https?://~', $imprint)): ?>
    · <a href="<?= h($imprint) ?>">Impressum</a>
  <?php endif; ?>
  <?php if ($privacy !== '' && preg_match('~^https?://~', $privacy)): ?>
    · <a href="<?= h($privacy) ?>">Datenschutz</a>
  <?php endif; ?>
  <span class="pat-foot-line">
    Mit <i class="ti ti-heart-filled pat-heart" aria-hidden="true"></i> von Boj Petersen ·
    <a href="mailto:<?= h(pat_setting_get('kontakt_mail', '')) ?>?subject=Feedback%20Pat%3Ainnenprogramm">Feedback</a> ·
    <a href="mailto:<?= h(pat_setting_get('kontakt_mail', '')) ?>?subject=Fehler%20Pat%3Ainnenprogramm">Fehler melden</a>
  </span>
</footer>
<script nonce="<?= h(pat_nonce()) ?>">
(function(){
  var k = 'pat-theme', d = document.documentElement;
  var btns = Array.prototype.slice.call(document.querySelectorAll('.pat-theme button'));
  function apply(m, save){
    if (m !== 'light' && m !== 'dark') m = 'system';
    d.setAttribute('data-theme-mode', m);
    var dark = m === 'system'
      ? (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)
      : m === 'dark';
    d.setAttribute('data-theme', dark ? 'dark' : 'light');
    var mt = document.querySelector('meta[name="theme-color"]');
    if (mt) mt.setAttribute('content', dark ? '#111518' : '#ffffff');
    if (save) { try { localStorage.setItem(k, m); } catch (e) {} }
    btns.forEach(function(b){ b.setAttribute('aria-pressed', String(b.getAttribute('data-mode') === m)); });
  }
  btns.forEach(function(b){
    b.addEventListener('click', function(){ apply(b.getAttribute('data-mode'), true); });
  });
  apply(d.getAttribute('data-theme-mode') || 'system', false);

  // Einwilligung: Klick irgendwo auf den Text kreuzt das Kästchen an. Das kann keine
  // Beschriftung mit for-Bezug übernehmen, weil Firefox darin den Klick auf den
  // Datenschutz-Link verschluckt. Also von Hand – und dabei zwei Fälle auslassen:
  // Klicks auf den Link selbst und Klicks, mit denen jemand Text markiert hat.
  var box = document.getElementById('privacy'), txt = document.getElementById('privacyTxt');
  if (box && txt) {
    txt.classList.add('is-toggle');   // erst jetzt Zeigefinger: ohne JS klickt nur das Kästchen
    txt.addEventListener('click', function(e){
      if (e.target && e.target.closest && e.target.closest('a')) return;
      var sel = window.getSelection && window.getSelection();
      if (sel && String(sel).length > 2) return;
      box.checked = !box.checked;
    });
  }
  // Hilfe-Dialog. Der Knopf ist und bleibt ein echter Link auf hilfe.php – erst hier wird er
  // umgebogen. Wer kein JavaScript hat, landet also einfach auf der Seite und kann dort fragen.
  var hDlg = document.getElementById('patHilfe');
  if (hDlg && typeof hDlg.showModal === 'function') {
    var hKnopf  = document.querySelector('.pat-help'),
        hForm   = hDlg.querySelector('form'),
        hFehler = hDlg.querySelector('.pat-dialog-fehler'),
        hFertig = hDlg.querySelector('.pat-dialog-fertig');

    var zeige = function(e){
      if (e) e.preventDefault();
      hFehler.hidden = true; hFertig.hidden = true; hForm.hidden = false;
      hDlg.showModal();
      var erstes = hForm.querySelector('input[name="name"]');
      if (erstes) erstes.focus();
    };
    if (hKnopf) hKnopf.addEventListener('click', zeige);

    Array.prototype.forEach.call(hDlg.querySelectorAll('[data-schliessen]'), function(b){
      b.addEventListener('click', function(){ hDlg.close(); });
    });
    // Klick auf den Hintergrund schließt ebenfalls (der Dialog selbst füllt nur die Mitte)
    hDlg.addEventListener('click', function(e){ if (e.target === hDlg) hDlg.close(); });

    hForm.addEventListener('submit', function(e){
      if (!hForm.reportValidity()) return;      // Browser-Prüfung zuerst
      e.preventDefault();
      var knopf = hForm.querySelector('button[type="submit"]');
      if (knopf) { knopf.disabled = true; knopf.classList.add('laeuft'); }
      fetch(hForm.action, {
        method: 'POST',
        body: new FormData(hForm),
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function(r){ return r.json(); }).then(function(d){
        if (d && d.ok) {
          hForm.hidden = true; hFehler.hidden = true; hFertig.hidden = false;
          hForm.reset();
        } else {
          hFehler.querySelector('.pat-note-b').textContent =
            (d && d.error) ? d.error : 'Das hat gerade nicht geklappt. Bitte noch einmal versuchen.';
          hFehler.hidden = false;
        }
      }).catch(function(){
        // Netz weg oder Antwort unlesbar: nicht raten, sondern das Formular ganz normal
        // abschicken. Dann übernimmt hilfe.php – die Frage geht auf keinen Fall verloren.
        hForm.submit();
      }).finally(function(){
        if (knopf) { knopf.disabled = false; knopf.classList.remove('laeuft'); }
      });
    });
  }

  // Systemwechsel (hell/dunkel) live mitgehen, solange „Automatisch" gewählt ist
  try {
    var mq = matchMedia('(prefers-color-scheme: dark)');
    var onChange = function(){ var s = 'system'; try { s = localStorage.getItem(k) || 'system'; } catch (e) {} if (s === 'system') apply('system', false); };
    if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
  } catch (e) {}
})();
</script>
<?php /* Doppel-Submit-Schutz: ganz am Ende, damit sein submit-Listener
         nach allen Seiten-Handlern läuft und deren preventDefault sieht. */ ?>
<script nonce="<?= h(pat_nonce()) ?>" src="<?= h(pat_asset('assets/doppelklick.js')) ?>"></script>
</body>
</html>
<?php
}
