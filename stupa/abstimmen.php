<?php
/**
 * Abstimmen im Umlaufverfahren des StuPa – über den persönlichen Link aus der Mail.
 *
 * ÖFFENTLICH und ohne Anmeldung: Der Schlüssel im Link IST der Ausweis. Deshalb bindet diese
 * Seite die lib.php der App NICHT ein (und auch nicht stupa-lib.php, die sie lädt) – nur
 * stupa-umlauf-db.php mit der eigenen Datenbank. Strenge Content-Security-Policy, kein Skript.
 */
declare(strict_types=1);
require_once __DIR__ . '/../stupa-umlauf-db.php';
require_once __DIR__ . '/../brand-core.php';

const SU_LOG = __DIR__ . '/../data/error.log';
error_reporting(E_ALL);
@ini_set('display_errors', '0');
set_error_handler(static function ($no, $str, $file, $line) {
    if (in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    @file_put_contents(SU_LOG, '[' . date('Y-m-d H:i:s') . '] STUPA-UMLAUF: PHP-Warnung: ' . $str . ' in ' . $file . ':' . $line . "\n", FILE_APPEND | LOCK_EX);
    return true;
});
set_exception_handler(static function (\Throwable $e) {
    @file_put_contents(SU_LOG, '[' . date('Y-m-d H:i:s') . '] STUPA-UMLAUF: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo '<p>Es ist ein Fehler aufgetreten. Bitte später noch einmal versuchen.</p>';
});

function su_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
su_maintain();
$meldung = ''; $fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fehler = su_cast($token, (string)($_POST['choice'] ?? ''));
    if ($fehler === '') { header('Location: abstimmen.php?t=' . rawurlencode($token) . '&ok=1', true, 303); exit; }
}
if (isset($_GET['ok'])) $meldung = 'Deine Stimme ist gespeichert. Danke!';
$f = su_token_find($token);

header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Umlaufverfahren · StuPa</title>
<link rel="stylesheet" href="../assets/tabler/tabler-icons.min.css">
<link rel="stylesheet" href="../assets/style.css">
<link rel="icon" type="image/png" href="<?= su_h(brand_url('logo-header', '../')) ?>">
</head>
<body class="su-body">
<main class="su-seite">
  <div class="su-marke"><img src="<?= su_h(brand_url('logo-header', '../')) ?>" alt="" height="32"> <span>StuPa · Umlaufverfahren</span></div>
<?php if (!$f): ?>
  <div class="card"><p class="empty"><i class="ti ti-link-off"></i> Dieser Abstimmungslink ist ungültig. Nimm bitte den Link aus deiner Mail – genau so, wie er dort steht.</p></div>
<?php else:
    [$t, $v] = $f;
    $offen = su_vote_open($v);
    $geheim = !empty($v['secret']);
    $regel = su_rules()[(string)$v['rule']] ?? ['', ''];
    $vorname = explode(' ', trim((string)$t['name']))[0];
?>
  <div class="card su-karte">
    <p class="small muted">Hallo <?= su_h($vorname) ?>, das Präsidium bittet um deine Stimme:</p>
    <h1 class="su-titel"><?= su_h((string)$v['title']) ?></h1>
    <?php if (trim((string)$v['description']) !== ''): ?><p class="su-text"><?= nl2br(su_h((string)$v['description'])) ?></p><?php endif; ?>
    <ul class="su-fakten">
      <li><i class="ti ti-clock"></i> Abstimmen bis <?= su_h(su_fmt((string)$v['deadline'])) ?></li>
      <li><i class="ti ti-scale"></i> <?= su_h($regel[0]) ?> <span class="muted">– <?= su_h($regel[1]) ?></span><?= (int)$v['quorum_pct'] > 0 ? ' · mindestens ' . (int)$v['quorum_pct'] . ' % Beteiligung' : '' ?></li>
      <li><i class="ti ti-<?= $geheim ? 'eye-off' : 'eye' ?>"></i> <?= $geheim ? 'Geheim – niemand sieht, wie du stimmst.' : 'Namentlich – das Präsidium sieht, wer wie stimmt.' ?></li>
    </ul>
  </div>

  <?php if ($meldung !== ''): ?><div class="su-hinweis su-ok"><i class="ti ti-circle-check"></i> <?= su_h($meldung) ?></div><?php endif; ?>
  <?php if ($fehler !== ''): ?><div class="su-hinweis su-fehler"><i class="ti ti-alert-circle"></i> <?= su_h($fehler) ?></div><?php endif; ?>

  <?php if ((string)$v['status'] === 'withdrawn'): ?>
    <div class="card"><p class="empty">Das Präsidium hat diesen Umlauf zurückgezogen.</p></div>
  <?php elseif ($offen && !($geheim && $t['voted_at'] !== null)): ?>
    <div class="card">
      <form method="post" class="su-abstimmen">
        <input type="hidden" name="t" value="<?= su_h($token) ?>">
        <p><strong><?= $t['voted_at'] !== null ? 'Deine Stimme: ' . su_h(su_choice_label((string)$t['choice'])) . ' – du kannst sie bis zur Frist ändern.' : 'Deine Stimme' ?></strong></p>
        <div class="su-wahlen">
          <?php foreach (['yes' => ['Ja', 'ti-check'], 'no' => ['Nein', 'ti-x'], 'abstain' => ['Enthaltung', 'ti-minus']] as $ck => [$cl, $ci]): ?>
            <label class="su-wahlknopf su-<?= $ck ?>"><input type="radio" name="choice" value="<?= $ck ?>" required<?= (string)$t['choice'] === $ck ? ' checked' : '' ?>><span><i class="ti <?= $ci ?>"></i> <?= $cl ?></span></label>
          <?php endforeach; ?>
        </div>
        <button class="btn" type="submit"><i class="ti ti-send"></i> <?= $t['voted_at'] !== null ? 'Stimme ändern' : 'Stimme abgeben' ?></button>
        <?php if ($geheim): ?><p class="small muted">Geheim heißt auch: Nach dem Abschicken lässt sich die Stimme nicht mehr ändern.</p><?php endif; ?>
      </form>
    </div>
  <?php elseif ($offen): ?>
    <div class="card"><p><i class="ti ti-circle-check"></i> Du hast abgestimmt. Das Ergebnis gibt es nach der Frist.</p></div>
  <?php elseif ((string)$v['status'] === 'closed' && !empty($v['result_mail'])): $z = su_tally($v); ?>
    <div class="card">
      <p><strong>Ergebnis: <?= su_h(su_result_label($v['result'])) ?></strong></p>
      <p class="small">Ja <?= $z['yes'] ?> · Nein <?= $z['no'] ?> · Enthaltung <?= $z['abstain'] ?> <span class="muted">· Beteiligung <?= $z['total'] ?> von <?= (int)$v['eligible_count'] ?></span></p>
      <?php if (!$geheim): $nm = su_names($v); ?>
        <ul class="su-namen small">
          <?php foreach (['yes' => 'Ja', 'no' => 'Nein', 'abstain' => 'Enthaltung'] as $ck => $cl): if ($nm[$ck]): ?>
            <li><strong><?= $cl ?>:</strong> <?= su_h(implode(', ', $nm[$ck])) ?></li>
          <?php endif; endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card"><p>Dieser Umlauf ist beendet. Das Ergebnis gibt das Präsidium bekannt.</p></div>
  <?php endif; ?>
<?php endif; ?>
  <p class="small muted su-fuss">Dieser Link gehört nur dir – bitte nicht weitergeben.</p>
</main>
</body>
</html>
