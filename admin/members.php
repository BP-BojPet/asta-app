<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    // ---- Mitglieder ----
    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $referat = trim((string)($_POST['referat'] ?? ''));
        if ($name === '') {
            flash('Bitte einen Namen angeben.', 'error');
        } elseif ($referat === '') {
            flash('Bitte ein Referat wählen – ohne Referat kann kein Mitglied angelegt werden.', 'error');
        } else {
            // Startpunkte = aktueller Gruppenschnitt (vor dem Anlegen berechnet): Neue starten im „Okay"-Band statt bei 0 im Roten
            $startScore = max(0, (int)round(score_average(member_scores())));
            db()->prepare('INSERT INTO members(name, email, referat, joined_at, score_start) VALUES(?,?,?,?,?)')->execute([$name, $email, $referat, date('Y-m-d'), $startScore]);
            flash('Mitglied „' . $name . '" hinzugefügt' . ($startScore > 0 ? ' – startet mit ' . $startScore . ' Punkten (Gruppenschnitt) im Eventscore.' : '.'), 'success');
        }
    } elseif ($action === 'rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = (string)($_POST['role'] ?? 'member');
        if (!in_array($role, ['member', 'sekretariat', 'finanzen', 'admin', 'vorsitz'], true)) $role = 'member';
        $referat = trim((string)($_POST['referat'] ?? ''));
        if ($id && $name !== '' && $referat !== '') {
            db()->prepare('UPDATE members SET name=?, email=?, role=?, is_admin=?, referat=? WHERE id=?')
                ->execute([$name, $email, $role, in_array($role, ['admin', 'vorsitz'], true) ? 1 : 0, $referat, $id]);
            flash('Mitglied gespeichert.', 'success');
        } elseif ($referat === '') {
            flash('Bitte ein Referat wählen – jedes Mitglied braucht eins.', 'error');
        }
    } elseif ($action === 'toggle') {
        // Beim Deaktivieren den Tag stempeln, beim Reaktivieren löschen: Daran hängt die
        // Aufräum-Erinnerung des Vorsitzes (Dashboard, ab 14 Tagen – Deaktivierung ist Übergang).
        db()->prepare("UPDATE members SET active = 1 - active,
            deactivated_on = CASE WHEN active = 1 THEN date('now','localtime') ELSE '' END
            WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($action === 'invite') {
        $m = member_get((int)($_POST['id'] ?? 0));
        if ($m && trim($m['email']) !== '') {
            send_login_link($m['email'])
                ? flash('Login-Link an ' . $m['email'] . ' geschickt.', 'success')
                : flash('Konnte keine Mail senden.', 'error');
        } else {
            flash('Dieses Mitglied hat keine E-Mail-Adresse hinterlegt.', 'error');
        }
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM members WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Mitglied gelöscht – Persönliches ist weg, Erstelltes (Events, Umläufe, Infos, Belegblätter …) bleibt erhalten.', 'success');

    // ---- Referate ----
    } elseif ($action === 'stupa_add_login') {
        if (stupa_user_add((string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? '')) > 0) {
            flash('StuPa-Zugang angelegt. Mit „Anmelde-Link" schickst du die Einladung los.', 'success');
        } else {
            flash('Bitte Bezeichnung und eine gültige, noch nicht vergebene E-Mail angeben.', 'error');
        }
        redirect('members.php?t=stupa');
    } elseif ($action === 'stupa_user_save') {
        if (stupa_user_update((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''), !empty($_POST['active']))) {
            flash('Zugang gespeichert.', 'success');
        } else {
            flash('Bitte Bezeichnung und eine gültige E-Mail angeben.', 'error');
        }
        redirect('members.php?t=stupa');
    } elseif ($action === 'stupa_user_link') {
        $err = null;
        flash(stupa_login_link_send((int)($_POST['id'] ?? 0), $err)
            ? 'Anmelde-Link verschickt – er gilt 60 Minuten.'
            : 'Link konnte nicht verschickt werden' . ($err ? ': ' . $err : '.'), $err ? 'error' : 'success');
        redirect('members.php?t=stupa');
    } elseif ($action === 'stupa_user_delete') {
        stupa_user_delete((int)($_POST['id'] ?? 0));
        flash('Zugang gelöscht. Bereits übermittelte Aufforderungen bleiben erhalten.', 'success');
        redirect('members.php?t=stupa');
    } elseif ($action === 'ref_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $sort = (int)($_POST['sort'] ?? 0);
        if ($name !== '') {
            db()->prepare('INSERT INTO referate(name, sort) VALUES(?,?)')->execute([$name, $sort]);
            flash('Referat hinzugefügt.', 'success');
        }
    } elseif ($action === 'ref_save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $sort = (int)($_POST['sort'] ?? 0);
        $st = db()->prepare('SELECT name FROM referate WHERE id=?'); $st->execute([$id]); $oldName = (string)$st->fetchColumn();
        if ($id && $name !== '') {
            db()->prepare('UPDATE referate SET name=?, sort=? WHERE id=?')->execute([$name, $sort, $id]);
            if ($oldName !== '' && $oldName !== $name) {
                // Umbenennung auf Mitglieder und bestehende Berichte durchziehen
                db()->prepare('UPDATE members SET referat=? WHERE referat=?')->execute([$name, $oldName]);
                db()->prepare('UPDATE reports SET referat=? WHERE referat=?')->execute([$name, $oldName]);
            }
            flash('Referat gespeichert.', 'success');
        }
    } elseif ($action === 'ref_delete') {
        db()->prepare('DELETE FROM referate WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Referat aus der Liste gelöscht. Mitglieder mit diesem Referat behalten ihre Zuordnung, bis du sie änderst.', 'info');
    }
    redirect('members.php');
}

$members  = members_all(true);
$stupaAccounts = stupa_users_all();
$referate = referate_all();
$refNames = array_map(fn($r) => (string)$r['name'], $referate);

/** Referat-Auswahl als Dropdown (mit Platzhalter; aktueller Wert bleibt erhalten, auch wenn er nicht in der Liste steht). */
function referat_select(string $fid, string $current, array $refNames): void
{
    echo '<select form="' . h($fid) . '" name="referat" aria-label="Referat" required>';
    echo '<option value="">— Referat wählen —</option>';
    $known = false;
    foreach ($refNames as $r) {
        $sel = ($r === $current) ? ' selected' : '';
        if ($sel) $known = true;
        echo '<option value="' . h($r) . '"' . $sel . '>' . h($r) . '</option>';
    }
    if ($current !== '' && !$known) {
        echo '<option value="' . h($current) . '" selected>' . h($current) . ' (nicht in Liste)</option>';
    }
    echo '</select>';
}

/* REITER: Drei Dinge auf einer Seite, die nichts miteinander zu tun haben –
   die Mitglieder selbst, die Referats-Liste für die Berichte und die StuPa-Zugänge (die gar
   keine Mitglieder sind). Abschnitte sind NICHT verschoben, nur gehüllt. */
$tabs = [
    'mitglieder' => ['label' => 'Mitglieder', 'icon' => 'ti-users'],
    'referate'   => ['label' => 'Referate',   'icon' => 'ti-list-numbers'],
    'stupa'      => ['label' => 'StuPa-Zugänge', 'icon' => 'ti-building-bank'],
];
$tab = (string)($_GET['t'] ?? '');
if (!isset($tabs[$tab])) $tab = 'mitglieder';

page_header('Stammliste', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar"><h1><i class="ti ti-users" style="color:var(--petrol)"></i> Stammliste</h1></div>
<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($tabs as $tk => $td): ?>
    <a<?= $tk === $tab ? ' class="on" aria-current="page"' : '' ?> href="members.php?t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?>
      <?php if ($tk === 'mitglieder'): ?><span class="count"><?= count($members) ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'mitglieder'): ?>
<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-user-plus"></i> Mitglied hinzufügen</div>
  <form method="post" class="field-row" style="align-items:flex-end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div><label for="name">Name</label><input type="text" name="name" id="name" required></div>
    <div><label for="email">E-Mail (für Login &amp; Erinnerungen)</label><input type="email" name="email" id="email" placeholder="<?= h(org_mail_beispiel('name')) ?>"></div>
    <div>
      <label for="add_ref">Referat</label>
      <select name="referat" id="add_ref" required>
        <option value="">— Referat wählen —</option>
        <?php foreach ($refNames as $r): ?><option value="<?= h($r) ?>"><?= h($r) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto"><button class="btn" type="submit"><i class="ti ti-plus"></i> Hinzufügen</button></div>
  </form>
  <p class="small muted" style="margin-bottom:0">Mehrere Personen dürfen dieselbe E-Mail teilen – beim Login wählt man dann aus, wer man ist. Ohne E-Mail kann sich die Person nicht anmelden. Die Rolle stellst du in der Liste ein.</p>
</div>

<div class="section-title"><i class="ti ti-list-details"></i> Mitglieder <span class="count"><?= count($members) ?></span></div>
<?php if (!$members): ?>
  <div class="card"><p class="empty"><i class="ti ti-info-circle"></i> Noch keine Mitglieder angelegt.</p></div>
<?php else: ?>
<div class="card" style="padding:.4rem .2rem">
<table class="list">
  <thead><tr><th>Name &amp; E-Mail</th><th>Referat</th><th>Rolle</th><th>Status</th><th style="text-align:right">Aktionen</th></tr></thead>
  <tbody>
  <?php foreach ($members as $m): $fid = 'm' . (int)$m['id']; $role = $m['role'] ?? 'member'; ?>
    <tr<?= $m['active'] ? '' : ' style="opacity:.55"' ?>>
      <td>
        <div class="btn-row" style="gap:.4rem">
          <input type="text" form="<?= $fid ?>" name="name" value="<?= h($m['name']) ?>" style="max-width:150px" aria-label="Name">
          <input type="email" form="<?= $fid ?>" name="email" value="<?= h($m['email'] ?? '') ?>" style="max-width:200px" placeholder="E-Mail" aria-label="E-Mail">
        </div>
      </td>
      <td><?php referat_select($fid, (string)($m['referat'] ?? ''), $refNames); ?></td>
      <td>
        <select form="<?= $fid ?>" name="role" aria-label="Rolle">
          <option value="member" <?= $role==='member'?'selected':'' ?>>Mitglied</option>
          <option value="sekretariat" <?= $role==='sekretariat'?'selected':'' ?>>Sekretariat</option>
          <option value="finanzen" <?= $role==='finanzen'?'selected':'' ?>>Finanzen</option>
          <option value="admin" <?= $role==='admin'?'selected':'' ?>>Admin</option>
          <option value="vorsitz" <?= $role==='vorsitz'?'selected':'' ?>>Vorsitz</option>
        </select>
      </td>
      <td><?= $m['active'] ? '<span class="amp amp-ok">aktiv</span>' : '<span class="amp amp-bad">inaktiv</span>' ?></td>
      <td style="text-align:right">
        <div class="btn-row" style="justify-content:flex-end">
          <form id="<?= $fid ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn small" type="submit">Speichern</button></form>
          <?php if (trim((string)$m['email']) !== ''): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="invite"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn secondary small" type="submit" title="Login-Link per E-Mail senden">Login-Link</button></form>
          <?php endif; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn secondary small" type="submit"><?= $m['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
          <form method="post" data-confirm="Mitglied wirklich löschen? Persönliches (Stimmen, Rückmeldungen, Einteilungen, Erfolge, eigene Pinnwand) geht verloren – Erstelltes (Events, Umläufe, Abstimmungen, Infos, Belegblätter, Pinnwand-Einträge bei anderen) bleibt erhalten." data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn danger small" type="submit">Löschen</button></form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="small muted">
  <strong>Rollen:</strong> <em>Mitglied</em> – Events/Abstimmen/Abwesenheit · <em>Sekretariat</em> – zusätzlich Sitzungen · <em>Finanzen</em> – zusätzlich Belegblätter abarbeiten (Finanzen-Seite + Dashboard-Kachel) · <em>Admin</em> – alles · <em>Vorsitz</em> – wie Admin + Eventscore.
  Inaktive Mitglieder erscheinen nicht mehr in Auswahllisten, bleiben aber in alten Auswertungen.
</p>
<p class="small" style="margin-top:-.4rem"><i class="ti ti-info-circle" style="color:var(--petrol)"></i>
  <strong>Wichtig für die Zuordnung:</strong> Das <strong>Technik-Referat</strong> sollte immer die Rolle <strong>Admin</strong> haben (daran hängen u. a. die Bug-Report-Verwaltung und -Benachrichtigungen), und der <strong>Vorsitz</strong> die Rolle <strong>Vorsitz</strong> (z. B. für Eventscore und die Vorsitz-Nennung im Protokoll). Diese Funktionen hängen an der <strong>Rolle</strong>, nicht am Referatsnamen.
</p>
<?php endif; ?>

<?php endif; /* Ende Reiter „Mitglieder" */ ?>

<?php if ($tab === 'referate'): ?>
<div class="section-title"><i class="ti ti-list-numbers"></i> Referate (für Berichte)</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Diese Liste bestimmt, welche Referate im Word-Bericht erscheinen und <strong>in welcher Reihenfolge</strong> (kleinste Nummer zuerst). Mitglieder wählen ihr Referat oben aus dieser Liste. Beim Umbenennen werden Mitglieder und bestehende Berichte automatisch mitgezogen.</p>
  <?php if ($referate): ?>
    <table class="list">
      <thead><tr><th style="width:5rem">Nr.</th><th>Referat</th><th style="text-align:right"></th></tr></thead>
      <tbody>
      <?php foreach ($referate as $r): $rfid = 'r' . (int)$r['id']; ?>
        <tr>
          <td><input type="number" form="<?= $rfid ?>" name="sort" value="<?= (int)$r['sort'] ?>" min="0" style="width:4rem" aria-label="Reihenfolge"></td>
          <td><input type="text" form="<?= $rfid ?>" name="name" value="<?= h($r['name']) ?>" style="max-width:360px;width:100%" aria-label="Referat-Name"></td>
          <td style="text-align:right">
            <div class="btn-row" style="justify-content:flex-end">
              <form id="<?= $rfid ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ref_save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn small" type="submit">Speichern</button></form>
              <form method="post" data-confirm="Referat „<?= h($r['name']) ?>“ aus der Liste löschen? Mitglieder behalten ihre Zuordnung, bis du sie änderst." data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?><input type="hidden" name="action" value="ref_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn danger small" type="submit">Löschen</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted small">Noch keine Referate angelegt.</p>
  <?php endif; ?>
  <h3 style="margin:1rem 0 .4rem">Referat hinzufügen</h3>
  <form method="post" class="field-row" style="align-items:flex-end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="ref_add">
    <div style="flex:0 0 90px"><label for="ref_sort">Nr.</label><input type="number" name="sort" id="ref_sort" min="0" value="<?= count($referate) + 1 ?>" style="max-width:90px"></div>
    <div style="flex:1 1 260px"><label for="ref_name">Referat</label><input type="text" name="name" id="ref_name" placeholder="z. B. Soziales" required></div>
    <div style="flex:0 0 auto"><button class="btn secondary" type="submit"><i class="ti ti-plus"></i> Referat hinzufügen</button></div>
  </form>
</div>

<?php endif; ?>

<?php if ($tab === 'stupa'): ?>
<div class="card" id="stupa-login">
  <div class="section-title" style="margin-top:0"><i class="ti ti-building-bank"></i> StuPa-Zugänge (Präsidium)</div>
  <p class="help" style="margin-top:0">Zugänge für das <strong>StuPa-Präsidium</strong>. Sie führen ausschließlich in den
    eigenen Bereich <a href="../stupa/">Auszahlungsaufforderungen</a> – kein Dashboard, kein Kalender, keine Mitgliederdaten.
    Es sind <strong>keine Mitglieder-Konten</strong>: sie stehen in einer eigenen Tabelle und tauchen deshalb in
    Score, Einteilung, Stammliste und Mitteilungen gar nicht erst auf.</p>
  <p class="help">Angemeldet wird sich mit einem <strong>Anmelde-Link</strong> an die hinterlegte Adresse –
    er gilt 60 Minuten und lässt sich einmal verwenden. Kein Kennwort, das jemand weitergeben könnte.</p>
  <?php if ($stupaAccounts): ?>
    <table class="list">
      <thead><tr><th>Bezeichnung</th><th>E-Mail</th><th>Aktiv</th><th>Zuletzt angemeldet</th><th style="text-align:right">Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($stupaAccounts as $sa): $sfid = 'stupa' . (int)$sa['id']; ?>
        <tr>
          <td><form method="post" id="<?= $sfid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="stupa_user_save"><input type="hidden" name="id" value="<?= (int)$sa['id'] ?>"></form>
            <input type="text" form="<?= $sfid ?>" name="name" value="<?= h($sa['name']) ?>" aria-label="Bezeichnung"></td>
          <td><input type="email" form="<?= $sfid ?>" name="email" value="<?= h($sa['email']) ?>" aria-label="E-Mail"></td>
          <td><label class="wl-sw slim"><input type="checkbox" form="<?= $sfid ?>" name="active" value="1"<?= (int)$sa['active'] ? ' checked' : '' ?>>
            <span class="wl-sw-track" aria-hidden="true"></span>
            <span class="wl-sw-txt"><strong>aktiv</strong></span></label></td>
          <td class="muted small"><?= $sa['last_login_at'] ? h(fmt_date(substr((string)$sa['last_login_at'], 0, 10))) : 'noch nie' ?></td>
          <td style="text-align:right">
            <button class="btn small" form="<?= $sfid ?>" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="stupa_user_link"><input type="hidden" name="id" value="<?= (int)$sa['id'] ?>">
              <button class="btn secondary small" type="submit" title="Anmelde-Link per E-Mail senden"><i class="ti ti-mail"></i> Anmelde-Link</button>
            </form>
            <form method="post" style="display:inline" data-confirm="Diesen Zugang löschen? Bereits übermittelte Aufforderungen bleiben erhalten." data-confirm-danger data-confirm-ok="Löschen">
              <?= csrf_field() ?><input type="hidden" name="action" value="stupa_user_delete"><input type="hidden" name="id" value="<?= (int)$sa['id'] ?>">
              <button class="btn danger small" type="submit"><i class="ti ti-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="empty" style="margin:.4rem 0"><i class="ti ti-user-off"></i> Noch kein StuPa-Zugang angelegt.</p>
  <?php endif; ?>
  <form method="post" class="field-row" style="align-items:flex-end;margin-top:.6rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="stupa_add_login">
    <div><label for="sl_name">Bezeichnung</label><input type="text" name="name" id="sl_name" placeholder="StuPa-Präsidium" required></div>
    <div><label for="sl_mail">E-Mail (für den Anmelde-Link)</label><input type="email" name="email" id="sl_mail" placeholder="<?= h(org_mail_beispiel('praesidium')) ?>" required></div>
    <div><button class="btn" type="submit"><i class="ti ti-user-plus"></i> Zugang anlegen</button></div>
  </form>
</div>
<?php endif; ?>
<?php
page_footer();
