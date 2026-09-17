<?php
/**
 * Anmeldung des StuPa-Präsidiums – per Einmal-Link an die hinterlegte Adresse.
 * Bewusst kein Kennwort: nichts, was jemand aufschreiben, teilen oder wiederverwenden kann.
 *
 * Gegen Ausprobieren: Die Seite verrät NIE, ob es eine Adresse gibt (immer dieselbe Antwort),
 * und pro Adresse geht höchstens alle 60 Sekunden eine Mail raus.
 */
require __DIR__ . '/stupa-lib.php';

if (stupa_current()) { header('Location: index.php'); exit; }

// Link aus der Mail einlösen
if (isset($_GET['t'])) {
    stupa_tokens_cleanup();
    $u = stupa_token_consume((string)$_GET['t']);
    if ($u) {
        session_regenerate_id(true);          // Session-Fixierung vorbeugen
        $_SESSION['stupa_user'] = (int)$u['id'];
        flash('Angemeldet als ' . $u['name'] . '.', 'success');
        header('Location: index.php');
        exit;
    }
    flash('Dieser Anmelde-Link ist abgelaufen oder wurde schon benutzt. Fordere einen neuen an.', 'error');
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $letzte = (int)($_SESSION['stupa_mail_at'] ?? 0);
    if (time() - $letzte < 60) {
        flash('Gerade wurde schon ein Link angefordert – bitte kurz warten und im Postfach nachsehen.', 'error');
    } else {
        $_SESSION['stupa_mail_at'] = time();
        $st = db()->prepare('SELECT id FROM stupa_users WHERE email = ? AND active = 1');
        $st->execute([$email]);
        if ($uid = (int)($st->fetchColumn() ?: 0)) {
            $err = null;
            if (!stupa_login_link_send($uid, $err)) app_log_error('StuPa-Anmeldelink: ' . (string)$err);
        }
        // Immer dieselbe Rückmeldung – sonst ließe sich herausfinden, welche Adressen existieren
        flash('Wenn diese Adresse hinterlegt ist, ist der Anmelde-Link unterwegs. Er gilt 60 Minuten.', 'success');
    }
    header('Location: login.php');
    exit;
}

stupa_header('Anmelden');
?>
<div class="section-title" style="margin-top:0"><i class="ti ti-building-bank"></i> Auszahlungsaufforderungen</div>
<div class="card" style="max-width:520px">
  <p class="small muted" style="margin-top:0">Bereich des <strong>StuPa-Präsidiums</strong>. Trag die hinterlegte
    Adresse ein – wir schicken einen Anmelde-Link, der 60 Minuten gilt und einmal verwendet werden kann.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label for="email">E-Mail-Adresse</label>
    <input type="email" id="email" name="email" required autocomplete="email" placeholder="praesidium@…">
    <div class="btn-row" style="margin-top:.9rem">
      <button class="btn" type="submit"><i class="ti ti-mail"></i> Anmelde-Link schicken</button>
    </div>
  </form>
  <p class="small muted" style="margin-bottom:0">Keine Adresse hinterlegt? Der AStA-Vorsitz legt den Zugang an.</p>
</div>
<?php
stupa_footer();
