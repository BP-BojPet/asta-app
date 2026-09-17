<?php
/**
 * Externe Events – Übersicht.
 *
 * Öffentliche Anmeldungen für Studierende (Kneipentour, O-Woche, Fahrten) samt
 * Einteilung in Gruppen. Bewusst NICHT in der Verwaltung, sondern beim Events-Tab: Das
 * betreuen die Referate selbst, nicht der Vorsitz.
 *
 * Wer hier hereindarf, entscheidet extern_can_manage() – Vorsitz/Admin, global
 * freigeschaltete Referate, oder das je Veranstaltung eingetragene Referat.
 */
require __DIR__ . '/lib.php';
db();
require_login();
require_once __DIR__ . '/extern-db.php';

$me = current_member();
if (!extern_can_manage(null)) {
    flash('Externe Events betreut der Vorsitz und die dafür freigeschalteten Referate.', 'error');
    redirect('events.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        // Beim Anlegen gilt: Wer nicht alles darf, betreut es mit dem eigenen Referat.
        $post = $_POST;
        if (!can_admin() && !in_array(trim((string)($me['referat'] ?? '')), extern_referate(), true)) {
            $post['referat'] = trim((string)($me['referat'] ?? ''));
        }
        $r = extern_event_save(0, $post, (int)($me['id'] ?? 0), 'basis');
        flash($r['msg'], $r['typ']);
        redirect($r['ok'] ? 'extern-event.php?id=' . $r['id'] . '&schritt=2' : 'extern.php');
    }

    if ($action === 'settings' && can_admin()) {
        zust_form_speichern('extern', $_POST);
        foreach (['base_url', 'from_email', 'from_name', 'cap_day', 'cap_hour'] as $k) {
            extern_setting_set($k, trim((string)($_POST[$k] ?? '')));
        }
        flash('Einstellungen gespeichert.', 'success');
        redirect('extern.php?t=einstellungen');
    }

    if ($action === 'settings_from_app' && can_admin()) {
        extern_setting_set('base_url', rtrim(base_url(), '/'));
        extern_setting_set('from_email', mail_from());
        if (trim(extern_setting_get('from_name', '')) === '') extern_setting_set('from_name', extern_traeger());
        flash('Basis-Adresse und Absender aus den App-Einstellungen übernommen.', 'success');
        redirect('extern.php?t=einstellungen');
    }
    redirect('extern.php');
}

// Sichtbar ist, was man betreuen darf: alles (Vorsitz/Admin, global) oder das eigene Referat.
$alleSehen = can_admin() || in_array(trim((string)($me['referat'] ?? '')), extern_referate(), true);
$events = $alleSehen ? extern_events_all() : extern_events_all([trim((string)($me['referat'] ?? ''))]);

page_header('Externe Events');

// Reiter wie überall in der Verwaltung (wl-adm-tabs). Die Einstellungen sieht nur der
// Admin – für alle anderen bleibt die Seite ohne Reiterleiste eine einzige Liste.
$eTabs = ['events' => ['label' => 'Veranstaltungen', 'icon' => 'ti-ticket']];
if (can_admin()) $eTabs['einstellungen'] = ['label' => 'Einstellungen', 'icon' => 'ti-settings'];
$eTab = (string)($_GET['t'] ?? '');
if (!isset($eTabs[$eTab])) $eTab = 'events';
?>
<p class="small"><a href="events.php">‹ Events</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-ticket" style="color:var(--petrol)"></i> Externe Events</h1>
</div>
<p class="small muted" style="margin:-.4rem 0 1rem">Anmeldungen für Studierende – mit frei zusammengestelltem Formular, Warteliste und Einteilung in Gruppen.</p>

<?php if (count($eTabs) > 1): ?>
<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($eTabs as $tk => $td): ?>
    <a<?= $tk === $eTab ? ' class="on" aria-current="page"' : '' ?> href="extern.php?t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($eTab === 'events'): ?>
<?php if (!$events): ?>
  <div class="card"><p class="empty"><i class="ti ti-ticket"></i> Noch keine Veranstaltung angelegt.</p></div>
<?php else: ?>
  <div class="card" style="padding:.4rem .2rem">
    <table class="list">
      <thead><tr><th>Veranstaltung</th><th>Status</th><th>Angemeldet</th><th>Termin</th><th>Referat</th></tr></thead>
      <tbody>
      <?php foreach ($events as $e): $z = extern_counts((int)$e['id']); $frei = extern_free_seats($e); ?>
        <tr>
          <td><a href="extern-teilnahme.php?id=<?= (int)$e['id'] ?>"><?= h($e['title']) ?></a>
            <a class="muted small" href="extern-event.php?id=<?= (int)$e['id'] ?>" title="Einrichtung"><i class="ti ti-settings"></i></a></td>
          <td>
            <?php $st = (string)$e['status']; ?>
            <?php if ($st === 'open' && extern_reg_open($e)): ?><span class="pill pill-ok">Anmeldung offen</span>
            <?php elseif ($st === 'draft'): ?><span class="pill pill-info">Entwurf</span>
            <?php elseif ($st === 'archived'): ?><span class="pill">Archiv</span>
            <?php else: ?><span class="pill pill-warn"><?= h(extern_statuses()[$st] ?? $st) ?></span><?php endif; ?>
          </td>
          <td class="muted small"><?= (int)$z['personen'] ?><?= $frei !== null ? ' / ' . ((int)$e['capacity']) : '' ?><?php
            if ($z['warteliste'] > 0): ?> <span class="muted">(+<?= (int)$z['warteliste'] ?> Warteliste)</span><?php endif; ?></td>
          <td class="muted small"><?= trim((string)$e['starts_at']) !== '' ? h(fmt_date(substr((string)$e['starts_at'], 0, 10))) : '–' ?></td>
          <td class="muted small"><?= h($e['referat']) ?: '–' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="section-title"><i class="ti ti-plus"></i> Neue Veranstaltung</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Titel genügt zum Anlegen – alles Weitere führt dich der Einrichtungs-Assistent Schritt für Schritt durch.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label for="title">Titel</label>
    <input type="text" name="title" id="title" required placeholder="z. B. Kneipentour zum Semesterstart">
    <?php if ($alleSehen): ?>
      <label for="referat" style="margin-top:.6rem">Betreuendes Referat</label>
      <select name="referat" id="referat">
        <option value="">– bitte wählen –</option>
        <?php foreach (referate_list() as $r): ?>
          <option value="<?= h($r) ?>" <?= trim((string)($me['referat'] ?? '')) === $r ? 'selected' : '' ?>><?= h($r) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="small muted" style="margin:.2rem 0 0">Dieses Referat darf die Veranstaltung betreuen – zusätzlich zu Vorsitz, Admin und den grundsätzlich freigeschalteten Referaten.</p>
    <?php else: ?>
      <p class="small muted" style="margin:.4rem 0 0">Betreut wird sie von deinem Referat <strong><?= h((string)($me['referat'] ?? '')) ?></strong>.</p>
    <?php endif; ?>
    <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-plus"></i> Anlegen und einrichten</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($eTab === 'einstellungen' && can_admin()): ?>
  <div class="section-title" id="einstellungen"><i class="ti ti-settings"></i> Einstellungen</div>
  <div class="card">
    <p class="small muted" style="margin-top:0">Gelten für alle externen Events. Ohne Basis-Adresse und Absender kann keine Bestätigungsmail rausgehen.</p>
    <div class="btn-row" style="margin-bottom:.8rem">
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="settings_from_app">
        <button class="btn secondary small" type="submit"><i class="ti ti-copy"></i> Aus den App-Einstellungen übernehmen</button></form>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <label>Wer betreut die externen Events grundsätzlich?</label>
      <?= zust_picker_html('extern') ?>
      <p class="small muted" style="margin:.4rem 0 .8rem">Wer hier steht, sieht <strong>alle</strong>
        externen Events und kann neue anlegen. Alle anderen betreuen nur die Veranstaltungen, bei
        denen ihr Referat eingetragen ist. Vorsitz und Admin sehen ohnehin alles.</p>
      <div class="field-row">
        <div><label for="base_url">Basis-Adresse</label><input type="text" name="base_url" id="base_url" value="<?= h(extern_setting_get('base_url', '')) ?>" placeholder="<?= h(org_url() !== '' ? org_url() . '/planer' : 'https://beispiel.de/planer') ?>"></div>
        <div><label for="from_email">Antwort-Adresse</label><input type="email" name="from_email" id="from_email" value="<?= h(extern_setting_get('from_email', '')) ?>"><p class="small muted" style="margin:.25rem 0 0">Verschickt wird immer aus <strong><?= h(mail_pool_absender()) ?></strong> – das ist das einzige Postfach, das der Hoster dafür freigegeben hat. Was hier steht, ist die Adresse, an die Antworten gehen.</p></div>
        <div><label for="from_name">Absender-Name</label><input type="text" name="from_name" id="from_name" value="<?= h(extern_setting_get('from_name', extern_traeger())) ?>"></div>
      </div>
      <div class="field-row">
        <div style="flex:0 1 190px"><label for="cap_day">Eigener Deckel je 24 h</label><input type="number" name="cap_day" id="cap_day" min="0" value="<?= (int)extern_mail_cap_day() ?>"><?= mail_hint('cap_day', extern_mail_cap_day()) ?></div>
        <div style="flex:0 1 190px"><label for="cap_hour">Bremse: Mails je Stunde</label><input type="number" name="cap_hour" id="cap_hour" min="0" value="<?= (int)extern_mail_cap_hour() ?>"><?= mail_hint('cap_hour', extern_mail_cap_hour()) ?></div>
      </div>
      <?php mail_budget_card(); ?>
      <p class="small muted" style="margin:.4rem 0 .8rem"><strong>0</strong> heißt bei beiden Feldern: keine zusätzliche
        Bremse – externe Events nehmen sich, was der gemeinsame Topf gerade hergibt, und verschicken sofort. Seit der
        Hoster 3000 Mails je 24 Stunden zulässt, ist das der Normalfall. <code>cron_extern.php</code> ist nur noch das
        Netz darunter: für den seltenen Fall, dass der Topf gerade leer war, und für Zustellungen, die beim ersten
        Versuch scheitern.</p>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Einstellungen speichern</button></div>
    </form>
  </div>
<?php endif; ?>
<?php
page_footer();
