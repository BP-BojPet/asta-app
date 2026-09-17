<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Admin oder Vorsitz (bzw. Technik-Login)

$cm = current_member();
$sender = $cm ? (string)$cm['name'] : 'Verwaltung';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $mode = ($_POST['mode'] ?? 'specific') === 'all' ? 'all' : 'specific';
    $body = trim((string)($_POST['body'] ?? ''));
    $sendMail = isset($_POST['send_mail']);
    if ($body === '') {
        flash('Bitte eine Nachricht eingeben.', 'error');
        redirect('message.php');
    }
    if ($mode === 'all') {
        $n = 0;
        foreach (members_all() as $m) { dm_send((int)$m['id'], $sender, $body, null, $sendMail, true, 'dm_vorsitz'); $n++; }
        flash('Ankündigung an ' . $n . ' Mitglieder hinterlassen' . ($sendMail ? ' – auch per E-Mail.' : '.'), 'success');
    } else {
        $ids = array_map('intval', (array)($_POST['recipients'] ?? []));
        $valid = [];
        foreach (members_all() as $m) if (in_array((int)$m['id'], $ids, true)) $valid[] = (int)$m['id'];
        if (!$valid) { flash('Bitte mindestens eine Person auswählen.', 'error'); redirect('message.php'); }
        foreach ($valid as $rid) dm_send($rid, $sender, $body, null, $sendMail, false, 'dm_vorsitz');
        flash(count($valid) . ' Nachricht(en) hinterlassen' . ($sendMail ? ' – auch per E-Mail.' : '.'), 'success');
    }
    redirect('message.php');
}

page_header('Nachricht hinterlassen', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<h1><i class="ti ti-message-plus" style="color:var(--petrol)"></i> Nachricht hinterlassen</h1>
<div class="card">
  <p class="small muted" style="margin-top:0">Die Nachricht erscheint auf dem Dashboard der Empfänger:innen (über den offenen Aufgaben). Standardmäßig nur dort – auf Wunsch zusätzlich per E-Mail.</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="btn-row" style="gap:1.2rem;margin-bottom:.6rem">
      <label class="inline"><input type="radio" name="mode" value="specific" checked onclick="document.getElementById('recpick').style.display=''"> An ausgewählte Personen</label>
      <label class="inline"><input type="radio" name="mode" value="all" onclick="document.getElementById('recpick').style.display='none'"> <i class="ti ti-speakerphone"></i> Ankündigung an alle</label>
    </div>
    <div id="recpick">
      <label for="recipients">Empfänger:innen</label>
      <select name="recipients[]" id="recipients" multiple size="8" style="width:100%">
        <?php foreach (members_all() as $m): ?>
          <option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?><?= trim((string)$m['email']) === '' ? ' (keine E-Mail)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <p class="small muted">Mehrere mit Strg/Cmd bzw. langem Tippen auswählen.</p>
    </div>
    <label for="body">Nachricht</label>
    <textarea name="body" id="body" rows="4" required placeholder="Kurze Nachricht …"></textarea>
    <label class="wl-sw slim"><input type="checkbox" name="send_mail">
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Zusätzlich per E-Mail benachrichtigen</strong>
        <span>Standard: nur Dashboard.</span></span></label>
    <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit"><i class="ti ti-send"></i> Senden</button></div>
  </form>
</div>
<?php
page_footer();
