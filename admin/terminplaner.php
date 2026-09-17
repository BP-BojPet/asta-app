<?php
/**
 * Verwaltung des öffentlichen Terminplaners (Vorsitz & Admin).
 *
 * Der Terminplaner läuft ohne uns: Wer einen Termin sucht, legt selbst eine Umfrage an. Diese
 * Seite ist deshalb keine Bedienung, sondern eine Aufsicht – Einstellungen, ein Blick auf das,
 * was läuft, ein Notausschalter und die Möglichkeit, etwas zu löschen, das dort nicht hingehört.
 *
 * Sie greift auf BEIDE Datenbanken zu: auf die App (Rechte, Voreinstellungen) und auf die
 * getrennte Terminplaner-Datenbank. Der öffentliche Bereich kennt nur letztere.
 */
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Admin & Vorsitz
require_once __DIR__ . '/../termin-db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'mode_set') {
        $m = (string)($_POST['mode'] ?? 'on');
        if (!in_array($m, ['on', 'readonly', 'off'], true)) $m = 'on';
        tplan_setting_set('mode', $m);
        flash(match ($m) {
            'on'       => 'Der Terminplaner läuft.',
            'readonly' => 'Neue Terminumfragen sind gestoppt. Laufende bleiben erreichbar.',
            default    => 'Der Terminplaner ist abgeschaltet – auch bestehende Links führen ins Leere.',
        }, 'success');
        redirect('terminplaner.php');
    }
    if ($action === 'settings_save') {
        foreach (['base_url', 'from_email', 'from_name', 'imprint_url', 'privacy_url', 'feedback_email',
                  'cap_day', 'max_options', 'max_entries', 'max_new_per_hour', 'keep_days'] as $k) {
            tplan_setting_set($k, trim((string)($_POST[$k] ?? '')));
        }
        tplan_setting_set('mail_links', empty($_POST['mail_links']) ? '0' : '1');
        foreach (array_keys(tplan_texts()) as $k) {
            if (isset($_POST['text_' . $k])) tplan_setting_set('text_' . $k, trim((string)$_POST['text_' . $k]));
        }
        flash('Einstellungen gespeichert.', 'success');
        redirect('terminplaner.php?t=einstellungen');
    }
    if ($action === 'settings_from_app') {
        tplan_setting_set('base_url', rtrim(base_url(), '/'));
        tplan_setting_set('from_email', mail_from());
        if (trim(tplan_setting_get('from_name', '')) === '') tplan_setting_set('from_name', tplan_traeger());
        flash('Basis-Adresse und Absender aus den App-Einstellungen übernommen.', 'success');
        redirect('terminplaner.php?t=einstellungen');
    }
    if ($action === 'test_mail') {
        $to = current_member() ? member_mail(current_member()) : '';
        if ($to === '') { flash('Dafür braucht es ein Konto mit E-Mail-Adresse.', 'error'); redirect('terminplaner.php?t=einstellungen'); }
        // Bewusst am Schalter „Links per Mail" vorbei: Der Test soll beantworten, ob der
        // Versand technisch funktioniert – auch und gerade dann, wenn er gerade aus ist.
        $ok = tplan_mail($to, 'Testmail: Terminplaner', "Wenn du das liest, kann der Terminplaner Mails verschicken.\n\n(ausgelöst über Verwaltung → Terminplaner)");
        flash($ok ? 'Testmail an ' . $to . ' übergeben.' : 'Versand fehlgeschlagen – ist eine gültige Absender-Adresse hinterlegt?', $ok ? 'success' : 'error');
        redirect('terminplaner.php?t=einstellungen');
    }
    if ($action === 'delete_poll') {
        tplan_delete((int)($_POST['poll_id'] ?? 0));
        flash('Terminumfrage samt Antworten gelöscht.', 'success');
        redirect('terminplaner.php');
    }
    if ($action === 'prune') {
        $n = tplan_prune();
        flash($n > 0 ? $n . ' alte Terminumfrage(n) gelöscht.' : 'Nichts zu löschen – alles noch in der Frist.', 'success');
        redirect('terminplaner.php');
    }
    redirect('terminplaner.php');
}

// Aufräumen läuft hier mit: ein eigener Cron wäre für ein paar Zeilen zu viel, und ohne
// Besuch dieser Seite passiert im Terminplaner ohnehin nichts Kritisches.
tplan_prune();

$stats = tplan_stats();
$lim   = tplan_limits();
$mode  = tplan_mode();
$liste = tplan_list(200);

page_header('Terminplaner', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>

<div class="card">
  <h1 style="margin-top:0"><i class="ti ti-calendar-search" style="color:var(--petrol)"></i> Terminplaner</h1>
  <p class="small muted" style="margin-top:0">Das öffentliche Terminwerkzeug – wie die externe Redeliste ein Angebot,
    das ohne Konto funktioniert und <strong>für alle</strong> kostenfrei ist, nicht nur für Leute an der Hochschule.
    Wer eine Umfrage anlegt, verwaltet sie über den eigenen Verwaltungs-Link selbst; hier steht nur, was für alle gilt.</p>
  <p class="small" style="margin-bottom:0">
    <a class="btn secondary small" href="../termin/" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> Öffentliche Seite ansehen</a>
  </p>
</div>

<?php if (tplan_base_url() === ''): ?>
  <div class="card" style="border-left:3px solid var(--red)">
    <p style="margin:0"><i class="ti ti-alert-triangle" style="color:var(--red)"></i>
      <strong>Es fehlt die Basis-Adresse.</strong> Ohne sie kann der Terminplaner die beiden Links nicht anzeigen –
      neue Umfragen sind deshalb gesperrt, egal was der Betriebs-Schalter sagt. Ein Klick auf
      <strong>„Aus den App-Einstellungen übernehmen"</strong> im Reiter <a href="terminplaner.php?t=einstellungen">Einstellungen</a> reicht.</p>
  </div>
<?php endif; ?>

<?php
$tTabs = ['betrieb' => ['label' => 'Betrieb & Übersicht', 'icon' => 'ti-toggle-left'],
          'einstellungen' => ['label' => 'Einstellungen', 'icon' => 'ti-settings']];
$tTab = (string)($_GET['t'] ?? '');
if (!isset($tTabs[$tTab])) $tTab = 'betrieb';
?>
<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($tTabs as $tk => $td): ?>
    <a<?= $tk === $tTab ? ' class="on" aria-current="page"' : '' ?> href="terminplaner.php?t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tTab === 'betrieb'): ?>
<div class="section-title"><i class="ti ti-toggle-left"></i> Betrieb</div>
<div class="card">
  <form method="post" class="btn-row" style="flex-wrap:wrap;gap:.5rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="mode_set">
    <span class="statuspick">
      <label><input type="radio" name="mode" value="on" <?= $mode === 'on' ? 'checked' : '' ?>><span class="s-yes">Läuft</span></label>
      <label><input type="radio" name="mode" value="readonly" <?= $mode === 'readonly' ? 'checked' : '' ?>><span class="s-maybe">Keine neuen</span></label>
      <label><input type="radio" name="mode" value="off" <?= $mode === 'off' ? 'checked' : '' ?>><span class="s-no">Aus</span></label>
    </span>
    <button class="btn secondary small" type="submit"><i class="ti ti-device-floppy"></i> Übernehmen</button>
  </form>
  <p class="small muted" style="margin:.7rem 0 0"><strong>Keine neuen</strong> ist der sanfte Notausschalter: Laufende Umfragen
    funktionieren weiter, es kommt nur nichts Neues dazu. <strong>Aus</strong> nimmt auch bestehende Links vom Netz –
    das ist für den Fall gedacht, dass etwas grob missbraucht wird.</p>
</div>

<div class="section-title"><i class="ti ti-chart-bar"></i> Was gerade läuft</div>
<div class="event-cards" style="margin-bottom:1.2rem">
  <div class="event-card">
    <div class="ec-title"><i class="ti ti-calendar-search"></i> <?= (int)$stats['polls'] ?> Terminumfragen</div>
    <div class="ec-meta"><span><?= (int)$stats['offen'] ?> offen · <?= (int)$stats['woche'] ?> neu in 7 Tagen</span></div>
  </div>
  <div class="event-card">
    <div class="ec-title"><i class="ti ti-users"></i> <?= (int)$stats['entries'] ?> Rückmeldungen</div>
    <div class="ec-meta"><span>auf <?= (int)$stats['options'] ?> Terminvorschläge</span></div>
  </div>
</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Automatisch gelöscht werden Umfragen, die <?= (int)$lim['keep_days'] ?> Tage
    lang niemand mehr angefasst hat, und solche ohne jede Rückmeldung nach <?= (int)TERMIN_ABANDON_DAYS ?> Tagen.
    Das läuft bei jedem Aufruf dieser Seite mit.</p>
  <form method="post" style="margin-top:.6rem"><?= csrf_field() ?><input type="hidden" name="action" value="prune">
    <button class="btn secondary small" type="submit"><i class="ti ti-eraser"></i> Jetzt aufräumen</button></form>
</div>

<div class="section-title"><i class="ti ti-list"></i> Alle Terminumfragen</div>
<div class="card">
  <?php if (!$liste): ?>
    <p class="empty"><i class="ti ti-calendar-off"></i> Noch hat niemand eine Terminumfrage angelegt.</p>
  <?php else: ?>
    <p class="small muted" style="margin-top:0"><i class="ti ti-eye-off"></i> Bewusst ohne Antworten und Namen: Diese Seite ist
      die Aufsicht über den Dienst, keine Einsicht in fremde Terminabsprachen. Löschen geht trotzdem – für den Fall,
      dass jemand den Terminplaner für etwas benutzt, das dort nicht hingehört.</p>
    <table class="list">
        <thead><tr><th>Titel</th><th>Angelegt</th><th>Zuletzt</th><th style="text-align:right">Antworten</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($liste as $p): $offen = tplan_is_open($p); ?>
          <tr>
            <td>
              <strong><?= h(mb_substr((string)$p['title'], 0, 70)) ?></strong>
              <?php if (trim((string)$p['organizer']) !== ''): ?><br><span class="small muted"><?= h((string)$p['organizer']) ?></span><?php endif; ?>
              <br><a class="small" href="../termin/t.php?t=<?= h(rawurlencode((string)$p['slug'])) ?>" target="_blank" rel="noopener">Teilnahmeseite ansehen</a>
            </td>
            <td class="small"><?= h(date('d.m.Y', (int)strtotime((string)$p['created_at']))) ?></td>
            <td class="small"><?= trim((string)$p['touched_at']) !== '' ? h(date('d.m.Y', (int)strtotime((string)$p['touched_at']))) : '–' ?></td>
            <td style="text-align:right"><?= (int)$p['n'] ?></td>
            <td>
              <?php if ((int)$p['final_option'] > 0): ?><span class="pill pill-ok">Termin steht</span>
              <?php elseif ($offen): ?><span class="pill pill-ok">läuft</span>
              <?php elseif (tplan_deadline_passed($p)): ?><span class="pill pill-warn">Frist vorbei</span>
              <?php else: ?><span class="pill pill-bad">geschlossen</span><?php endif; ?>
            </td>
            <td style="text-align:right">
              <form method="post" data-confirm="Diese fremde Terminumfrage samt allen Antworten löschen?" data-confirm-danger data-confirm-ok="Löschen">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_poll"><input type="hidden" name="poll_id" value="<?= (int)$p['id'] ?>">
                <button class="btn danger small" type="submit" aria-label="Löschen"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($tTab === 'einstellungen'): ?>
<div class="section-title" id="einstellungen"><i class="ti ti-settings"></i> Einstellungen</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Ohne <strong>Basis-Adresse</strong> kann der Terminplaner keine Links anzeigen –
    das ist die einzige Angabe, ohne die er nicht funktioniert.</p>
  <div class="btn-row" style="margin-bottom:.8rem">
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="settings_from_app">
      <button class="btn secondary small" type="submit"><i class="ti ti-copy"></i> Aus den App-Einstellungen übernehmen</button></form>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="test_mail">
      <button class="btn secondary small" type="submit"><i class="ti ti-mail-fast"></i> Testmail an mich</button></form>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="settings_save">
    <div class="field-row">
      <div><label for="base_url">Basis-Adresse</label><input type="text" name="base_url" id="base_url" value="<?= h(tplan_setting_get('base_url', '')) ?>" placeholder="<?= h(org_url() !== '' ? org_url() . '/planer' : 'https://beispiel.de/planer') ?>"></div>
    </div>
    <div class="field-row">
      <div><label for="from_email">Antwort-Adresse</label><input type="email" name="from_email" id="from_email" value="<?= h(tplan_setting_get('from_email', '')) ?>"><p class="small muted" style="margin:.25rem 0 0">Verschickt wird immer aus <strong><?= h(mail_pool_absender()) ?></strong> – das ist das einzige Postfach, das der Hoster dafür freigegeben hat. Was hier steht, ist die Adresse, an die Antworten gehen.</p></div>
      <div><label for="from_name">Absender-Name</label><input type="text" name="from_name" id="from_name" value="<?= h(tplan_setting_get('from_name', tplan_traeger())) ?>"></div>
    </div>
    <div class="field-row">
      <div><label for="imprint_url">Impressum (Adresse)</label><input type="text" name="imprint_url" id="imprint_url" value="<?= h(tplan_setting_get('imprint_url', '')) ?>"></div>
      <div><label for="privacy_url">Datenschutz (Adresse)</label><input type="text" name="privacy_url" id="privacy_url" value="<?= h(tplan_setting_get('privacy_url', '')) ?>"></div>
    </div>
    <div class="field-row">
      <div><label for="feedback_email">Rückmeldungen gehen an</label><input type="email" name="feedback_email" id="feedback_email" value="<?= h(tplan_setting_get('feedback_email', '')) ?>" placeholder="<?= h(tplan_setting_get('from_email', 'wie Absender-Adresse')) ?>"></div>
    </div>
    <p class="small muted" style="margin:.2rem 0 .8rem">Im Fuß jeder öffentlichen Seite steht ein Knopf
      <strong>„Rückmeldung geben oder Fehler melden"</strong>. Er öffnet das Mailprogramm mit vorbereitetem Betreff –
      kein Formular, weil es hier keine Konten gibt und wir sonst noch eine Stelle hätten, an der Fremde uns Text
      schicken können. Leer heißt: Die <strong>Absender-Adresse</strong> von oben wird benutzt. Ist auch die leer,
      erscheint der Knopf nicht.</p>

    <div class="section-title" style="font-size:1rem"><i class="ti ti-mail"></i> Mail</div>
    <label class="wl-sw"><input type="checkbox" name="mail_links" value="1" <?= tplan_setting_get('mail_links', '1') === '1' ? 'checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Mailversand des Terminplaners erlauben</strong></span></label>
    <div class="field-row">
      <div style="flex:0 1 190px"><label for="cap_day">Eigener Deckel je 24 h</label><input type="number" name="cap_day" id="cap_day" min="0" value="<?= (int)tplan_mail_cap_day() ?>"><?= mail_hint('termin_cap_day', tplan_mail_cap_day()) ?></div>
    </div>
    <?php mail_budget_card(); ?>
    <p class="small muted" style="margin:.4rem 0 .8rem">Der Terminplaner kennt <strong>genau zwei</strong> Mails, beide
      nur auf ausdrücklichen Wunsch: die mit den zwei Links an die anlegende Person, und die <strong>Bestätigung an
      Teilnehmende</strong>, wenn diese eine Adresse angeben. Letztere trägt den persönlichen Änderungs-Link – sie ist
      der Grund, warum jemand seine Antwort auch zwei Wochen später und von einem anderen Gerät aus noch ändern kann,
      ganz ohne Konto. Sie geht <strong>einmal</strong> raus, nicht bei jeder Korrektur. Keine Erinnerungen, keine Werbung.
      Ohne diesen Haken verschickt der Bereich <strong>gar nichts</strong> – die Links stehen dann nur auf der Seite.</p>

    <div class="section-title" style="font-size:1rem"><i class="ti ti-ruler"></i> Grenzen</div>
    <div class="field-row">
      <div style="flex:0 1 200px"><label for="max_options">Terminvorschläge je Umfrage</label><input type="number" name="max_options" id="max_options" min="2" max="60" value="<?= (int)$lim['options'] ?>"></div>
      <div style="flex:0 1 200px"><label for="max_entries">Rückmeldungen je Umfrage</label><input type="number" name="max_entries" id="max_entries" min="5" max="2000" value="<?= (int)$lim['entries'] ?>"></div>
    </div>
    <div class="field-row">
      <div style="flex:0 1 220px"><label for="max_new_per_hour">Neue Umfragen je Stunde</label><input type="number" name="max_new_per_hour" id="max_new_per_hour" min="1" max="500" value="<?= (int)$lim['per_hour'] ?>"></div>
      <div style="flex:0 1 200px"><label for="keep_days">Aufbewahrung (Tage)</label><input type="number" name="keep_days" id="keep_days" min="7" max="730" value="<?= (int)$lim['keep_days'] ?>"></div>
    </div>
    <p class="small muted" style="margin:.2rem 0 .8rem">Die Umfragen entstehen ohne unser Zutun – die Zahlen hier sind die
      Bremse gegen Missbrauch. „Neue Umfragen je Stunde" gilt für den ganzen Dienst, nicht je Person; zusätzlich bremst
      der öffentliche Bereich jede Besuchssitzung einzeln.</p>

    <div class="section-title" style="font-size:1rem"><i class="ti ti-text-caption"></i> Texte</div>
    <?php foreach (tplan_texts() as $k => $meta): ?>
      <label for="t_<?= h($k) ?>"><?= h($meta['label']) ?></label>
      <textarea name="text_<?= h($k) ?>" id="t_<?= h($k) ?>" rows="<?= $k === 'mail_body' ? 9 : 3 ?>"><?= h(tplan_text($k)) ?></textarea>
    <?php endforeach; ?>
    <p class="small muted" style="margin:.2rem 0 0">In der Link-Mail stehen <code>{{TITEL}}</code>, <code>{{LINK}}</code>
      (Teilnahme) und <code>{{ADMIN}}</code> (Verwaltung) zur Verfügung.</p>
    <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Einstellungen speichern</button></div>
  </form>
</div>
<?php endif; ?>
<?php
page_footer();
