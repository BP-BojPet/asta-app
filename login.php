<?php
require __DIR__ . '/lib.php';
db();

// Schon eingeloggt und kein Benutzerwechsel gewünscht → ab ins Dashboard
// (nur bei GET; POSTs wie „choose" müssen durchlaufen)
if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'GET'
    && !isset($_GET['switch']) && !isset($_GET['choose']) && !isset($_GET['token'])) {
    redirect('dashboard.php');
}

function finish_login(int $memberId, string $email): never
{
    login_member($memberId);
    set_remember($email, $memberId);
    $target = $_SESSION['after_login'] ?? '';
    unset($_SESSION['after_login']);
    if (!$target || str_contains($target, 'login.php') || str_contains($target, 'logout')) {
        $target = 'dashboard.php';
    }
    redirect($target);
}

// --- Login-Link per Token einlösen ---
if (isset($_GET['token'])) {
    $email = consume_login_token((string)$_GET['token']);
    if (!$email) {
        flash('Der Login-Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.', 'error');
        redirect('login.php');
    }
    $_SESSION['auth_email'] = $email;
    $members = members_by_email($email);
    if (count($members) === 1) {
        finish_login((int)$members[0]['id'], $email);
    }
    redirect('login.php?choose=1'); // mehrere Personen teilen das Postfach
}

// --- Login per Kurzcode aus der Mail (für installierte Apps, wo der Link in Safari aufgeht) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'code') {
    check_csrf();
    $email = consume_login_code((string)($_POST['code'] ?? ''));
    if ($email) {
        $_SESSION['auth_email'] = $email;
        $members = members_by_email($email);
        if (count($members) === 1) {
            finish_login((int)$members[0]['id'], $email);
        }
        redirect('login.php?choose=1');
    }
    flash('Der Code ist ungültig oder abgelaufen. Bitte fordere einen neuen Login-Link an.', 'error');
    redirect('login.php');
}

// --- Benutzer auswählen / wechseln (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'choose') {
    check_csrf();
    $email = $_SESSION['auth_email'] ?? '';
    $memberId = (int)($_POST['member_id'] ?? 0);
    $m = member_get($memberId);
    if ($email && $m && strcasecmp(trim($m['email']), trim($email)) === 0) {
        finish_login($memberId, $email);
    }
    flash('Auswahl ungültig.', 'error');
    redirect('login.php?choose=1');
}

// --- Login-Link anfordern (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    check_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    send_login_link($email); // Ergebnis bewusst nicht verraten
    flash('Falls diese E-Mail hinterlegt ist, haben wir dir gerade einen Login-Link geschickt. Schau in dein Postfach.', 'success');
    redirect('login.php');
}

// --- Auswahl-Seite anzeigen (nach Token oder „Benutzer wechseln") ---
$showChooser = isset($_GET['choose']) || isset($_GET['switch']);
$choices = $showChooser ? shared_members() : [];
if ($showChooser && !$choices) {
    // kein verifiziertes Postfach in der Sitzung → zurück zum Login
    redirect('login.php');
}

page_header('Anmelden');
?>
<div style="max-width:440px;margin:1rem auto">
<?php if ($showChooser): ?>
  <h1>Wer bist du?</h1>
  <p class="muted">Mit diesem Postfach sind mehrere Personen hinterlegt. Wähle aus, als wer du dich anmelden möchtest.</p>
  <div class="card">
    <?php foreach ($choices as $m): ?>
      <form method="post" action="login.php" style="margin:.3rem 0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="choose">
        <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
        <button class="btn" type="submit" style="width:100%;text-align:left">
          <?= h($m['name']) ?><?php if (!empty($m['is_admin'])): ?> <span class="badge badge-meeting" style="float:right">Orga</span><?php endif; ?>
        </button>
      </form>
    <?php endforeach; ?>
  </div>
  <?php if (!is_logged_in()): ?>
    <p class="small"><a href="login.php">Andere E-Mail verwenden</a></p>
  <?php endif; ?>
<?php else: ?>
  <h1>Anmelden</h1>
  <p class="muted">Gib deine hinterlegte E-Mail-Adresse ein. Wir schicken dir einen Login-Link – kein Passwort nötig.</p>
  <div class="card">
    <form method="post" action="login.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="request">
      <label for="email">E-Mail-Adresse</label>
      <input type="email" name="email" id="email" autofocus required placeholder="<?= h(org_mail_beispiel('name')) ?>">
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit">Login-Link schicken</button></div>
    </form>
  </div>
  <details class="card" id="codeBox" style="margin-top:.8rem">
    <summary style="cursor:pointer;font-weight:600"><i class="ti ti-device-mobile"></i> App installiert? Code eingeben</summary>
    <p class="small muted" style="margin:.5rem 0">Wenn der Login-Link sich im Browser statt in der installierten App öffnet: Fordere oben den Login-Link an und gib den <strong>Code aus der E-Mail</strong> hier ein.</p>
    <form method="post" action="login.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="code">
      <label for="code">Login-Code</label>
      <input type="text" name="code" id="code" inputmode="latin" autocapitalize="characters" autocomplete="one-time-code" placeholder="XXXX-XXXX" required>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit">Mit Code anmelden</button></div>
    </form>
  </details>
  <p class="small muted">Noch keinen Zugang? Eine:n Organisator:in bitten, dich in der Stammliste anzulegen.</p>
  <p class="small">
    <a href="admin/index.php">Technik-/Verwaltungs-Login</a>
    <span class="muted"> · </span>
    <a href="stupa/login.php">StuPa-Präsidium: Auszahlungen</a>
  </p>
  <script>
    // In der installierten App (Standalone) das Code-Feld aufklappen – dort öffnet der Mail-Link
    // sich im Browser, nicht in der App; der Code ist dann der Weg hinein.
    (function () {
      var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
      var box = document.getElementById('codeBox');
      if (standalone && box) box.open = true;
    })();
  </script>
<?php endif; ?>
</div>
<?php
page_footer();
