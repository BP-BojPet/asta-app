<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Vorsitz & Admin (Sekretariat hat die Übersicht im eigenen Dashboard)

// Einladungs-Versandmodus umschalten (Text ausgeben ⇄ automatischer Mailversand)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_invite_mode') {
    check_csrf();
    setting_set('invite_mode', ($_POST['invite_mode'] ?? 'text') === 'auto' ? 'auto' : 'text');
    flash('Einladungs-Versandmodus gespeichert.', 'success');
    redirect('sekretariat.php');
}

$mode = invite_mode();

page_header('Sekretariatsaufgaben', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-id-badge-2" style="color:var(--petrol)"></i> Sekretariatsaufgaben</h1>
  <a class="btn secondary" href="meetings.php"><i class="ti ti-gavel"></i> Sitzungen</a>
</div>
<p class="muted">Überblick über Bericht-Status, eingereichte TOPs und offene Einladungen zur nächsten Sitzung – für den Fall, dass das Sekretariat Unterstützung braucht. Die Sekki-Einstellungen (Reminder &amp; Fristen) findest du unter <strong>Sitzungen → Einladungen</strong>.</p>

<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-mail-forward"></i> Einladungs-Versand</div>
  <p class="small muted" style="margin-top:0">Wie werden Sitzungseinladungen verschickt? Aktuell: <strong><?= $mode === 'auto' ? 'Automatischer Mailversand an den Verteiler' : 'Nur Text ausgeben (Sekki versendet selbst)' ?></strong>.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_invite_mode">
    <label class="inline" style="display:flex;gap:.5rem;align-items:flex-start;margin-top:.4rem">
      <input type="radio" name="invite_mode" value="text" <?= $mode !== 'auto' ? 'checked' : '' ?> style="margin-top:.25rem">
      <span><strong>Nur Text ausgeben</strong> <span class="pill pill-info">Standard</span><br><span class="small muted">Auf der Sitzungsseite gibt es den fertigen Einladungstext zum Kopieren; das Sekretariat versendet ihn selbst über das Verteiler-Webinterface und markiert die Einladung danach von Hand als „versendet". Kein automatischer Mailversand.</span></span>
    </label>
    <label class="inline" style="display:flex;gap:.5rem;align-items:flex-start;margin-top:.7rem">
      <input type="radio" name="invite_mode" value="auto" <?= $mode === 'auto' ? 'checked' : '' ?> style="margin-top:.25rem">
      <span><strong>Automatischer Mailversand an den Verteiler</strong><br><span class="small muted">Die App verschickt die freigegebene Einladung am Stichtag automatisch an die hinterlegte Verteiler-Adresse (<?= app_place('a_sitzungen', 'Sitzungen → Einladungen', 'einladungen') ?>). Funktioniert nur, wenn der Verteiler per E-Mail erreichbar ist.</span></span>
    </label>
    <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Versandmodus speichern</button></div>
  </form>
</div>

<div class="card">
  <?= sekretariat_overview_html() ?>
</div>
<?php
page_footer();
