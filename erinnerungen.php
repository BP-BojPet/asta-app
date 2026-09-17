<?php
// Persönliche Erinnerungs- & Mitteilungs-Einstellungen. Die Seite rendert sich
// komplett aus dem Mitteilungs-Register (notify_types) – neue Typen erscheinen
// hier automatisch. Abweichungen vom Standard liegen in notify_prefs.
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();

if (!$me) {
    page_header('Erinnerungen');
    echo '<div class="flash flash-info">Mit dem Technik-Login gibt es keine persönlichen Erinnerungen.</div>';
    page_footer();
    exit;
}

// Schalter/Chips posten per fetch (app.js, data-ajax): dann 204 statt Flash+Redirect –
// die Seite lädt nicht neu, die UI kippt lokal, die Bestätigung kommt als Toast.
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

// Master-Schalter (an/aus) – Einsatz-Erinnerung und Ein-/Ausplanung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_reminder') {
    check_csrf();
    if (($_POST['which'] ?? '') === 'shift') {
        $new = (int)($me['reminder_lead_days'] ?? 0) > 0 ? null : 1; // an = 1 Tag vorher, aus = NULL
        db()->prepare('UPDATE members SET reminder_lead_days = ? WHERE id = ?')->execute([$new, (int)$me['id']]);
        if (!$isAjax) flash($new ? 'Einsatz-Erinnerung eingeschaltet.' : 'Einsatz-Erinnerung ausgeschaltet.', 'success');
    } elseif (($_POST['which'] ?? '') === 'status') {
        db()->prepare('UPDATE members SET notify_assignment = 1 - notify_assignment WHERE id = ?')->execute([(int)$me['id']]);
        if (!$isAjax) flash((int)$me['notify_assignment'] === 1 ? 'Einplan-Benachrichtigung ausgeschaltet.' : 'Einplan-Benachrichtigung eingeschaltet.', 'success');
    }
    if ($isAjax) { http_response_code(204); exit; }
    redirect('erinnerungen.php');
}

// Kanal (mail/push) für einen Typ umschalten – validiert notify_pref_set gegen das Register
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_pref') {
    check_csrf();
    $ok = notify_pref_set((int)$me['id'], (string)($_POST['type'] ?? ''), (string)($_POST['field'] ?? ''), (int)($_POST['value'] ?? 0));
    if ($isAjax) { http_response_code($ok ? 204 : 400); exit; }
    if ($ok) flash('Einstellung gespeichert.', 'success');
    redirect('erinnerungen.php');
}

// Vorlauf (timing) für einen Typ setzen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_timing') {
    check_csrf();
    $ok = notify_pref_set((int)$me['id'], (string)($_POST['type'] ?? ''), 'timing', (int)($_POST['value'] ?? 0));
    if ($isAjax) { http_response_code($ok ? 204 : 400); exit; }
    if ($ok) flash('Vorlauf gespeichert.', 'success');
    redirect('erinnerungen.php');
}

// Vorlauf der Einsatz-Erinnerung (liegt historisch in members.reminder_lead_days)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_lead') {
    check_csrf();
    $v = (int)($_POST['value'] ?? 0);
    $allowed = notify_types()['shift_reminder']['timing'];
    $ok = in_array($v, $allowed, true);
    if ($ok) db()->prepare('UPDATE members SET reminder_lead_days = ? WHERE id = ?')->execute([$v, (int)$me['id']]);
    if ($isAjax) { http_response_code($ok ? 204 : 400); exit; }
    if ($ok) flash('Vorlauf gespeichert.', 'success');
    redirect('erinnerungen.php');
}

// Alle Mitteilungs-Abweichungen zurück auf Standard (Master-Schalter bleiben)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_prefs') {
    check_csrf();
    notify_prefs_reset((int)$me['id']);
    flash('Alle Mitteilungs-Einstellungen stehen wieder auf Standard.', 'success');
    redirect('erinnerungen.php');
}

// Privat-Mail für Erinnerungen setzen/leeren (leer = hinterlegte Adresse)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notify_email') {
    check_csrf();
    $ne = trim((string)($_POST['notify_email'] ?? ''));
    if ($ne !== '' && !filter_var($ne, FILTER_VALIDATE_EMAIL)) {
        flash('Bitte eine gültige E-Mail-Adresse eingeben – oder das Feld leer lassen.', 'error');
    } else {
        db()->prepare('UPDATE members SET notify_email = ? WHERE id = ?')->execute([$ne, (int)$me['id']]);
        flash($ne !== '' ? 'Erinnerungen gehen jetzt an ' . $ne . '.' : 'Erinnerungen gehen wieder an deine hinterlegte Adresse.', 'success');
    }
    redirect('erinnerungen.php');
}

// Push: alle Geräte dieses Kontos abmelden (Server-Seite; Browser-Abos laufen dadurch ins Leere)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'push_clear') {
    check_csrf();
    db()->prepare('DELETE FROM push_subscriptions WHERE member_id = ?')->execute([(int)$me['id']]);
    has_push_device(0, true); // Zwischenspeicher leeren: Pflicht-Mails gelten ab sofort wieder
    flash('Alle Push-Abos deines Kontos wurden gelöscht.', 'success');
    redirect('erinnerungen.php');
}

$lead         = (int)($me['reminder_lead_days'] ?? 0);
$notifyAssign = (int)($me['notify_assignment'] ?? 1);
$notifyMail   = trim((string)($me['notify_email'] ?? ''));
$pushVapid    = push_available() ? push_vapid() : null;
$pushDevices  = count(push_subscriptions_for((int)$me['id']));
$customCount  = count(notify_prefs_cache()[(int)$me['id']] ?? []);

/**
 * Klickbarer Mail-/Push-Kanal-Chip (sofort speichernd).
 * $reload = true schickt klassisch ab (mit Neuladen) statt per fetch: nötig beim Push-Schalter
 * der „Pflicht, außer per Push"-Typen, weil sich dadurch AUCH der Mail-Chip daneben ändert –
 * die fetch-Variante kippt nur den geklickten Chip und würde daneben einen falschen Stand zeigen.
 */
function chan_chip(string $type, string $field, bool $on, string $label, bool $reload = false): void
{
    $icon = $field === 'mail' ? 'ti-mail' : 'ti-device-mobile-message';
    ?><form method="post" style="display:inline"<?= $reload ? '' : ' data-ajax="1"' ?>><?= csrf_field() ?>
      <input type="hidden" name="action" value="set_pref"><input type="hidden" name="type" value="<?= h($type) ?>">
      <input type="hidden" name="field" value="<?= h($field) ?>"><input type="hidden" name="value" value="<?= $on ? 0 : 1 ?>">
      <button type="submit" class="chan-chip <?= $on ? 'on' : '' ?>" title="<?= $label ?> für diese Art <?= $on ? 'ausschalten' : 'einschalten' ?>">
        <i class="ti <?= $icon ?>"></i> <?= h($label) ?> <i class="ti <?= $on ? 'ti-check' : 'ti-x' ?>"></i>
      </button>
    </form><?php
}

/** Vorlauf-Auswahl (sofort speichernd); $action = set_timing (Register) oder set_lead (Einsatz). */
function timing_select(string $action, string $type, array $options, int $current, string $label): void
{
    ?><form method="post" class="timing-form" data-ajax="1"><?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= h($action) ?>"><input type="hidden" name="type" value="<?= h($type) ?>">
      <label class="timing-label"><i class="ti ti-clock"></i> <?= h($label) ?>:
        <select name="value" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"><!-- requestSubmit feuert das submit-Event -> Scroll-Merker greift -->
          <?php foreach ($options as $o): ?>
            <option value="<?= $o ?>"<?= $o === $current ? ' selected' : '' ?>><?= $o ?> Tag<?= $o === 1 ? '' : 'e' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form><?php
}

page_header('Erinnerungen & Mitteilungen');
?>
<p class="small"><a href="dashboard.php">‹ Dashboard</a></p>
<h1><i class="ti ti-bell"></i> Erinnerungen &amp; Mitteilungen</h1>
<p class="small muted" style="margin:-.4rem 0 1rem">Je Art wählst du <strong>Mail und Push getrennt</strong> und – wo sinnvoll – <strong>wie früh</strong> erinnert wird. Push kommt nur auf Geräten an, auf denen du es unten aktiviert hast. Bei den <strong>Pflicht-Erinnerungen</strong> (Abstimmungen, Berichte, Umlauf) darfst du die <strong>Mail abschalten, sobald du sie per Push bekommst</strong> – fällt der Push weg (Push aus, letztes Gerät abgemeldet), ist die Mail automatisch wieder an. Ganz abbestellen lassen sie sich nicht.</p>

<?php foreach (notify_groups() as $gKey => $gLabel): ?>
<div class="section-title"><i class="ti <?= ['events' => 'ti-calendar-event', 'meetings' => 'ti-gavel', 'messages' => 'ti-message', 'app' => 'ti-device-mobile'][$gKey] ?>"></i> <?= h($gLabel) ?></div>
<div class="card chan-card">
  <?php foreach (notify_types() as $type => $def): if ($def['group'] !== $gKey) continue;
      // Rollen-gebundene Typen (z. B. Pinnwand-Wache nur für Admin) für alle anderen ausblenden
      if (!empty($def['role']) && current_role() !== $def['role']) continue;
      $pref     = notify_pref((int)$me['id'], $type);
      $master   = $def['master'] ?? null;
      $masterOn = $master === 'shift' ? $lead > 0 : ($master === 'assign' ? $notifyAssign === 1 : true); ?>
  <div class="chan-item">
    <div class="chan-head">
      <span class="chan-name"><i class="ti <?= h($def['icon']) ?>"></i> <?= h($def['label']) ?></span>
      <?php if ($master): ?>
        <form method="post" data-ajax="1">
          <?= csrf_field() ?><input type="hidden" name="action" value="toggle_reminder"><input type="hidden" name="which" value="<?= $master === 'shift' ? 'shift' : 'status' ?>">
          <button type="submit" class="belltoggle <?= $masterOn ? 'on' : '' ?>" style="width:auto">
            <i class="ti <?= $masterOn ? 'ti-bell-ringing' : 'ti-bell-off' ?>"></i> <?= $masterOn ? 'An' : 'Aus' ?>
          </button>
        </form>
      <?php else: ?>
        <span class="pill pill-info">immer aktiv</span>
      <?php endif; ?>
    </div>
    <p class="chan-desc"><?= h($def['desc']) ?></p>
    <?php /* Chips immer rendern; bei ausgeschaltetem Master nur versteckt – so kann
             app.js sie beim An/Aus-Kippen ohne Seiten-Reload ein-/ausblenden */ ?>
      <div class="chan-chips"<?= $masterOn ? '' : ' hidden' ?>>
        <?php if ($def['mail'] === 'forced'): ?>
          <span class="chan-chip static"><i class="ti ti-mail"></i> Mail: kommt immer</span>
        <?php elseif ($def['mail'] === 'push_opt'): ?>
          <?php if ($pushDevices > 0 && $pref['push']): // Push kommt an → Mail darf weg ?>
            <?php chan_chip($type, 'mail', $pref['mail'], 'Mail'); ?>
          <?php elseif ($pushDevices > 0): // Gerät da, aber Push für diese Art aus ?>
            <span class="chan-chip static" title="Diese Erinnerung darfst du nicht komplett abbestellen. Schalte Push für diese Art ein, dann kannst du die Mail abwählen."><i class="ti ti-mail"></i> Mail: Pflicht – oder Push an</span>
          <?php else: ?>
            <span class="chan-chip static" title="Diese Erinnerung darfst du nicht komplett abbestellen. Richte weiter unten Push auf einem Gerät ein, dann kannst du die Mail abwählen."><i class="ti ti-mail"></i> Mail: Pflicht – oder Push einrichten</span>
          <?php endif; ?>
        <?php elseif ($def['mail'] === 'sender'): ?>
          <span class="chan-chip static"><i class="ti ti-mail"></i> Mail: entscheidet Absender:in</span>
        <?php elseif ($def['mail'] === 'opt'): ?>
          <?php chan_chip($type, 'mail', $pref['mail'], 'Mail'); ?>
        <?php endif; ?>
        <?php if (!empty($def['push'])) chan_chip($type, 'push', $pref['push'], 'Push', $def['mail'] === 'push_opt'); ?>
        <?php if (!empty($def['timing'])):
            if ($type === 'shift_reminder') timing_select('set_lead', $type, $def['timing'], max(1, $lead), $def['timing_label']);
            else                            timing_select('set_timing', $type, $def['timing'], $pref['timing'], $def['timing_label']);
        endif; ?>
      </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if ($customCount > 0): ?>
<form method="post" style="margin:-.3rem 0 1.2rem"
      data-confirm="Wirklich alle Kanal- und Vorlauf-Anpassungen zurücksetzen? Die An/Aus-Schalter (Einsatz-Erinnerung, Ein-/Ausplanung) bleiben unverändert."
      data-confirm-title="Auf Standard zurücksetzen?" data-confirm-ok="Zurücksetzen">
  <?= csrf_field() ?><input type="hidden" name="action" value="reset_prefs">
  <button class="btn secondary small" type="submit"><i class="ti ti-restore"></i> Alle Anpassungen zurücksetzen (<?= $customCount ?>)</button>
</form>
<?php endif; ?>

<div class="section-title"><i class="ti ti-mail-forward"></i> Zustell-Adresse</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Erinnerungen gehen normalerweise an deine hinterlegte Adresse (<strong><?= h((string)($me['email'] ?? '')) ?></strong>). Hier kannst du stattdessen eine Privat-Adresse eintragen – leer lassen schaltet zurück.</p>
  <form method="post" class="btn-row" style="align-items:stretch">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_notify_email">
    <input type="email" name="notify_email" value="<?= h($notifyMail) ?>" placeholder="leer = hinterlegte Adresse" style="flex:1;min-width:220px">
    <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
  </form>
</div>

<?php if ($pushVapid): ?>
<div class="section-title"><i class="ti ti-device-mobile-message"></i> Push aufs Gerät</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Echte Push-Nachrichten wie von einer App – <strong>zusätzlich</strong> zu den E-Mails. Gilt <strong>pro Gerät</strong>; auf jedem Gerät einmal einschalten. Was gepusht wird, stellst du oben je Art ein.</p>
  <button type="button" class="belltoggle" id="push-toggle" hidden style="width:auto"
          data-vapid="<?= h($pushVapid['pub']) ?>" data-csrf="<?= h(csrf_token()) ?>">
    <i class="ti ti-bell-off"></i> <span class="pt-label">Push auf diesem Gerät</span>
  </button>
  <p class="small muted" id="push-unsupported" style="margin:.3rem 0 0">
    <i class="ti ti-info-circle"></i> Dieser Browser unterstützt hier kein Push. <strong>iPhone/iPad:</strong> Push geht nur aus der <strong>installierten App</strong> (Teilen → „Zum Home-Bildschirm", dann dort einschalten).
  </p>
  <?php if ($pushDevices > 0): ?>
    <div class="small muted" style="margin-top:.7rem"><i class="ti ti-devices"></i> Aktuell angemeldet: <strong><?= $pushDevices ?></strong> Gerät<?= $pushDevices === 1 ? '' : 'e' ?>.</div>
    <form method="post" style="margin-top:.4rem"
          data-confirm="Wirklich die Push-Abos ALLER deiner Geräte löschen? Du kannst Push danach auf jedem Gerät wieder einschalten."
          data-confirm-title="Push überall abschalten?" data-confirm-ok="Alle abmelden">
      <?= csrf_field() ?><input type="hidden" name="action" value="push_clear">
      <button class="btn secondary small" type="submit"><i class="ti ti-bell-x"></i> Alle Geräte abmelden</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
page_footer();
