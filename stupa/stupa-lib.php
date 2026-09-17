<?php
/**
 * Gemeinsamer Unterbau des StuPa-Bereichs.
 *
 * WARUM EIGENER BEREICH: Das Präsidium ist kein AStA-Mitglied. Als Eintrag in `members` liefe
 * das Konto in allem mit, was Mitglieder abfragt – Anwesenheitszahlen, Score,
 * Mitteilungs-Einstellungen, Erfolge. Über 30 Abfragen greifen direkt auf die Tabelle zu; jede
 * einzelne müsste die Ausnahme kennen. Hier gibt es die Zeile deshalb gar nicht.
 *
 * EIGENE SESSION: Wir starten die Session VOR lib.php unter eigenem Namen und mit Pfad
 * /stupa/ – lib.php startet dann keine zweite (es prüft session_status()). Dadurch ist ein
 * StuPa-Login technisch kein Mitglieds-Login: $_SESSION['member_id'] existiert hier nie,
 * und das Mitglieder-Cookie wird in diesem Verzeichnis gar nicht erst mitgeschickt.
 *
 * GEMEINSAME DATENBANK: bewusst – die Aufforderungen müssen bei Finanzen ankommen.
 * Getrennt ist der Zugang, nicht die Ablage.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/stupa/index.php'))), '/') . '/';
    // Vom Browser vorgegebene Sitzungs-Kennungen nicht übernehmen: Müll erzeugt sonst
    // Warnungen und eine leere Sitzung, und eine vorgegebene Kennung wäre angreifbar.
    @ini_set('session.use_strict_mode', '1');
    session_name('asta_stupa_sid');
    session_set_cookie_params([
        'path'     => $dir,                 // gilt NUR im StuPa-Verzeichnis
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
    ]);
    session_start();
}

require_once __DIR__ . '/../lib.php';
db();

/** Cache-Buster für die Stylesheets (dieselbe Idee wie in der App). */
const STUPA_ASSET_V = '1';

/** Angemeldeter StuPa-Zugang (oder null). */
function stupa_current(): ?array
{
    $id = (int)($_SESSION['stupa_user'] ?? 0);
    if ($id <= 0) return null;
    $u = stupa_user_get($id);
    if (!$u || !(int)$u['active']) { unset($_SESSION['stupa_user']); return null; }
    return $u;
}

/** Ohne Anmeldung geht es zur Anmeldeseite. */
function stupa_require_login(): array
{
    $u = stupa_current();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

/** Kopf der StuPa-Seiten: schlank, ohne App-Navigation. */
function stupa_header(string $title, ?array $user = null): void
{
    $asset = fn(string $p) => '../' . $p . '?v=' . STUPA_ASSET_V;
    ?><!doctype html>
<html lang="de" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title) ?> · StuPa</title>
<link rel="stylesheet" href="<?= h($asset('assets/tabler/tabler-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= h($asset('assets/style.css')) ?>">
<link rel="icon" type="image/png" href="<?= h(brand_url('logo-header', '../')) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><img src="<?= h(brand_url('logo-header', '../')) ?>" alt="" height="34"><span>StuPa · Auszahlungen</span></a>
  <nav id="mainnav">
    <?php if ($user): ?>
      <span class="navuser"><i class="ti ti-building-bank"></i> <?= h($user['name']) ?></span>
      <a href="logout.php"><i class="ti ti-logout"></i>Abmelden</a>
    <?php endif; ?>
  </nav>
</header>
<main>
<?php $flashes = take_flash(); if ($flashes): ?>
  <div class="toasts" aria-live="polite">
  <?php foreach ($flashes as $f):
      $tIcon = ['success' => 'ti-circle-check', 'error' => 'ti-alert-circle', 'info' => 'ti-info-circle'][$f['type']] ?? 'ti-info-circle'; ?>
    <div class="toast toast-<?= h($f['type']) ?>" data-ttl="<?= $f['type'] === 'error' ? 9000 : 5500 ?>">
      <i class="ti <?= $tIcon ?>"></i>
      <div class="toast-msg"><?= h($f['msg']) ?></div>
      <button type="button" class="toast-x" aria-label="Meldung schließen"><i class="ti ti-x"></i></button>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
}

function stupa_footer(): void
{
    ?>
</main>
<footer class="foot"><span class="muted small"><?= h(org_name_kurz()) ?> · Auszahlungsaufforderungen des StuPa</span></footer>
<script src="../assets/app.js?v=<?= STUPA_ASSET_V ?>"></script>
</body>
</html>
<?php
}

