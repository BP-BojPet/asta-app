<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();

// --- Notfall-Link einlösen: bestätigt die Identität (Admin/Vorsitz) per Magic-Link ---
if (isset($_GET['token'])) {
    $mid = consume_stepup_token((string)$_GET['token']);
    $m = $mid ? member_get($mid) : null;
    if ($m && in_array((string)($m['role'] ?? ''), ['admin', 'vorsitz'], true)) {
        $_SESSION['techpw_reauth'] = time();
        $_SESSION['techpw_reauth_member'] = (int)$m['id'];
        flash('Identität bestätigt. Du kannst jetzt ein neues Technik-Passwort setzen.', 'success');
    } else {
        flash('Der Notfall-Link ist ungültig oder abgelaufen. Bitte neu anfordern.', 'error');
    }
    redirect('recover.php');
}

// Re-Auth gilt 15 Minuten und muss zu einem Admin/Vorsitz gehören
$reauthOk = !empty($_SESSION['techpw_reauth']) && (time() - (int)$_SESSION['techpw_reauth'] < 900);
$reauthMember = $reauthOk ? member_get((int)($_SESSION['techpw_reauth_member'] ?? 0)) : null;
$reauthOk = $reauthOk && $reauthMember && in_array((string)($reauthMember['role'] ?? ''), ['admin', 'vorsitz'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$reauthOk) {
        flash('Sitzung abgelaufen. Bitte den Notfall-Link erneut anfordern.', 'error');
        redirect('index.php');
    }
    $new = (string)($_POST['new'] ?? '');
    if (strlen($new) < 6) {
        flash('Neues Passwort muss mindestens 6 Zeichen haben.', 'error');
        redirect('recover.php');
    }
    setting_set('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
    unset($_SESSION['techpw_reauth'], $_SESSION['techpw_reauth_member']);
    flash('Technik-Passwort wurde neu gesetzt. Du kannst dich jetzt damit anmelden.', 'success');
    redirect('index.php');
}

page_header('Technik-Passwort zurücksetzen', true);
?>
<div class="events-toolbar">
  <h1><i class="ti ti-key" style="color:var(--petrol)"></i> Technik-Passwort zurücksetzen</h1>
</div>
<?php if (!$reauthOk): ?>
  <div class="card">
    <p class="empty"><i class="ti ti-mail"></i> Bitte zuerst über den <strong>Notfall-Link aus deiner E-Mail</strong> hierher kommen. Ist der Link abgelaufen, fordere in der <a href="index.php">Verwaltung</a> einen neuen an.</p>
  </div>
<?php else: ?>
  <div class="card" style="max-width:400px">
    <p class="muted small" style="margin-top:0">Identität als <strong><?= h($reauthMember['name']) ?></strong> per Magic-Link bestätigt. Lege jetzt ein neues Technik-/Verwaltungs-Passwort fest.</p>
    <form method="post" action="recover.php">
      <?= csrf_field() ?>
      <label for="new">Neues Technik-Passwort (min. 6 Zeichen)</label>
      <input type="password" name="new" id="new" required minlength="6" autofocus>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit">Passwort setzen</button></div>
    </form>
  </div>
<?php endif; ?>
<?php
page_footer();
