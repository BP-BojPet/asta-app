<?php
/**
 * Externes Event einrichten – der Assistent.
 *
 * Sechs Schritte statt einer ewig scrollenden Seite. Jeder Schritt speichert nur SEINE Felder
 * (siehe $teil in extern_event_save) – sonst würde Schritt 2 die Einstellungen aus Schritt 4
 * mit Standardwerten überbügeln, nur weil deren Felder im Formular gar nicht vorkommen.
 *
 * Nach dem Veröffentlichen bleiben dieselben Schritte als Reiter zum Nachbearbeiten.
 */
require __DIR__ . '/lib.php';
db();
require_login();
require_once __DIR__ . '/extern-db.php';

$me = current_member();
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$ev = $id > 0 ? extern_event_get($id) : null;
if (!$ev || !extern_can_manage($ev)) {
    flash('Diese Veranstaltung gibt es nicht, oder sie wird von einem anderen Referat betreut.', 'error');
    redirect('extern.php');
}

/** Die Schritte des Assistenten: Schlüssel => [Nummer, Beschriftung, Symbol]. */
function ex_schritte(): array
{
    return [
        'basis'        => [1, 'Grunddaten',   'ti-info-circle'],
        'anmeldung'    => [2, 'Anmeldung',    'ti-user-plus'],
        'formular'     => [3, 'Formular',     'ti-forms'],
        'gruppen'      => [4, 'Gruppen',      'ti-users-group'],
        'sichtbarkeit' => [5, 'Sichtbarkeit', 'ti-eye'],
        'fertig'       => [6, 'Prüfen',       'ti-checkup-list'],
    ];
}

$schrittKeys = array_keys(ex_schritte());
$nr = max(1, min(count($schrittKeys), (int)($_GET['schritt'] ?? $_POST['schritt'] ?? 1)));
$schritt = $schrittKeys[$nr - 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $weiter = 'extern-event.php?id=' . $id . '&schritt=' . $nr;

    if ($action === 'save_step') {
        $r = extern_event_save($id, $_POST, (int)($me['id'] ?? 0), $schritt);
        flash($r['msg'], $r['typ']);
        // Weiter zum nächsten Schritt, aber nur wenn gespeichert wurde
        redirect($r['ok'] && $nr < count($schrittKeys) && empty($_POST['bleiben'])
            ? 'extern-event.php?id=' . $id . '&schritt=' . ($nr + 1) : $weiter);
    }
    if ($action === 'field_save') {
        $r = extern_field_save($id, (int)($_POST['field_id'] ?? 0), $_POST);
        flash($r['msg'], !empty($r['ok']) ? 'success' : 'error');
        redirect($weiter . '#formular');
    }
    if ($action === 'field_delete') { extern_field_delete((int)($_POST['field_id'] ?? 0)); flash('Feld gelöscht.', 'success'); redirect($weiter . '#formular'); }
    if ($action === 'field_move')   { extern_field_move((int)($_POST['field_id'] ?? 0), ($_POST['dir'] ?? '') === 'up' ? -1 : 1); redirect($weiter . '#formular'); }
    if ($action === 'group_save') {
        $r = extern_group_save($id, (int)($_POST['group_id'] ?? 0), $_POST);
        flash($r['msg'], !empty($r['ok']) ? 'success' : 'error');
        redirect($weiter . '#gruppen');
    }
    if ($action === 'group_delete') { extern_group_delete((int)($_POST['group_id'] ?? 0)); flash('Gruppe gelöscht – die Leute sind wieder unverteilt.', 'success'); redirect($weiter . '#gruppen'); }
    if ($action === 'group_move')   { extern_group_move((int)($_POST['group_id'] ?? 0), ($_POST['dir'] ?? '') === 'up' ? -1 : 1); redirect($weiter . '#gruppen'); }
    if ($action === 'station_save') {
        $r = extern_station_save($id, (int)($_POST['station_id'] ?? 0), $_POST);
        flash($r['msg'], !empty($r['ok']) ? 'success' : 'error');
        redirect($weiter . '#stationen');
    }
    if ($action === 'station_delete') { extern_station_delete((int)($_POST['station_id'] ?? 0)); flash('Station gelöscht – der Plan muss neu erzeugt werden.', 'success'); redirect($weiter . '#stationen'); }
    if ($action === 'station_move')   { extern_station_move((int)($_POST['station_id'] ?? 0), ($_POST['dir'] ?? '') === 'up' ? -1 : 1); redirect($weiter . '#stationen'); }
    if ($action === 'status') {
        $r = extern_event_status($id, (string)($_POST['status'] ?? ''));
        flash($r['msg'], $r['ok'] ? 'success' : 'error');
        redirect($weiter);
    }
    if ($action === 'delete') {
        extern_event_delete($id);
        flash('Veranstaltung samt Anmeldungen gelöscht.', 'success');
        redirect('extern.php');
    }
    redirect($weiter);
}

$felder  = extern_fields_of($id);
$gruppen = extern_groups_of($id);
$zahlen  = extern_counts($id);
/* Der öffentliche Link hängt an der Basis-Adresse der EXTERN-Einstellungen. Wie bei den
   Umfragen: Eine leere heilt sich aus der App, eine ABWEICHENDE wird beim
   Link laut gemeldet – sonst verteilt jemand einen Link, der auf einem fremden 404 endet. */
$appBasis = rtrim(base_url(), '/');
if (trim(extern_setting_get('base_url', '')) === '' && $appBasis !== '') {
    extern_setting_set('base_url', $appBasis);
}
$basis   = rtrim(extern_setting_get('base_url', ''), '/');
$link    = $basis !== '' ? $basis . '/anmeldung/?e=' . rawurlencode((string)$ev['slug']) : '';
$basisWeicht = $appBasis !== '' && $basis !== $appBasis;

page_header('Einrichten – ' . $ev['title']);
?>
<p class="small"><a href="extern.php">‹ Externe Events</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-ticket" style="color:var(--petrol)"></i> <?= h($ev['title']) ?></h1>
  <div class="btn-row">
    <span class="pill <?= (string)$ev['status'] === 'open' ? 'pill-ok' : ((string)$ev['status'] === 'draft' ? 'pill-info' : 'pill-warn') ?>"><?= h(extern_statuses()[(string)$ev['status']] ?? '') ?></span>
    <a class="btn secondary small" href="extern-teilnahme.php?id=<?= $id ?>"><i class="ti ti-users-group"></i> Anmeldungen &amp; Einteilung</a>
  </div>
</div>

<?php // Schrittleiste: zeigt, wo man ist, und lässt zurückspringen ?>
<nav class="wz" aria-label="Einrichtungs-Schritte">
  <?php foreach (ex_schritte() as $key => [$n, $label, $ic]): ?>
    <a class="wz-s<?= $n === $nr ? ' on' : ($n < $nr ? ' done' : '') ?>" href="extern-event.php?id=<?= $id ?>&amp;schritt=<?= $n ?>">
      <span class="wz-n"><?= $n ?></span><i class="ti <?= $ic ?>"></i><span class="wz-l"><?= h($label) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($schritt === 'basis'): ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-info-circle"></i> Grunddaten</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_step"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="<?= $nr ?>">
      <label for="title">Titel</label>
      <input type="text" name="title" id="title" value="<?= h($ev['title']) ?>" required>
      <label for="intro">Beschreibung <span class="small muted">– steht über dem Anmeldeformular</span></label>
      <textarea name="intro" id="intro" rows="5" placeholder="Worum geht es, was erwartet die Leute, was kostet es?"><?= h($ev['intro']) ?></textarea>
      <div class="field-row">
        <div><label for="place">Ort</label><input type="text" name="place" id="place" value="<?= h($ev['place']) ?>" placeholder="z. B. Treffpunkt Rathausplatz"></div>
        <div><label for="starts_at">Beginn</label><input type="text" class="fp-datetime" name="starts_at" id="starts_at" value="<?= h(substr((string)$ev['starts_at'], 0, 16)) ?>"></div>
        <div><label for="ends_at">Ende</label><input type="text" class="fp-datetime" name="ends_at" id="ends_at" value="<?= h(substr((string)$ev['ends_at'], 0, 16)) ?>"></div>
      </div>
      <label for="referat" style="margin-top:.6rem">Betreuendes Referat</label>
      <select name="referat" id="referat" <?= extern_can_manage(null) && !can_admin() && !in_array(trim((string)($me['referat'] ?? '')), extern_referate(), true) ? 'disabled' : '' ?>>
        <option value="">– keins –</option>
        <?php foreach (referate_list() as $r): ?><option value="<?= h($r) ?>" <?= (string)$ev['referat'] === $r ? 'selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?>
      </select>
      <p class="small muted" style="margin:.2rem 0 0">Dieses Referat darf die Veranstaltung betreuen. Änderst du das, verlierst du selbst womöglich den Zugriff.</p>
      <?php if ((string)$ev['status'] === 'draft'): ?>
        <label for="slug" style="margin-top:.6rem">Link-Schlüssel</label>
        <input type="text" name="slug" id="slug" value="<?= h($ev['slug']) ?>">
        <p class="small muted" style="margin:.2rem 0 0">Nach dem Öffnen bleibt er unverändert – er steckt dann in verteilten Links.</p>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-arrow-right"></i> Speichern und weiter</button>
        <button class="btn secondary" type="submit" name="bleiben" value="1">Nur speichern</button></div>
    </form>
  </div>

<?php elseif ($schritt === 'anmeldung'): ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-user-plus"></i> Anmeldung</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_step"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="<?= $nr ?>">
      <div class="field-row">
        <div><label for="reg_from">Anmeldung ab</label><input type="text" class="fp-datetime" name="reg_from" id="reg_from" value="<?= h(substr((string)$ev['reg_from'], 0, 16)) ?>"></div>
        <div><label for="reg_until">Anmeldeschluss</label><input type="text" class="fp-datetime" name="reg_until" id="reg_until" value="<?= h(substr((string)$ev['reg_until'], 0, 16)) ?>"></div>
        <div><label for="cancel_until">Abmelden möglich bis</label><input type="text" class="fp-datetime" name="cancel_until" id="cancel_until" value="<?= h(substr((string)$ev['cancel_until'], 0, 16)) ?>"></div>
      </div>
      <p class="small muted" style="margin:.2rem 0 .8rem">Leer heißt jeweils: keine Beschränkung. Ohne Abmeldefrist geht das bis zum Termin.</p>
      <div class="field-row">
        <div style="flex:0 1 160px"><label for="capacity">Plätze</label><input type="number" name="capacity" id="capacity" min="0" value="<?= (int)$ev['capacity'] ?>"></div>
        <div><p class="small muted" style="margin:1.6rem 0 0">0 = unbegrenzt. Gezählt werden <strong>Personen</strong>: Eine Sammelanmeldung zu viert belegt vier Plätze.</p></div>
      </div>
      <label class="inline" style="margin-top:.7rem"><input type="checkbox" name="waitlist" value="1" <?= (int)$ev['waitlist'] === 1 ? 'checked' : '' ?>> <strong>Warteliste</strong>, wenn die Plätze voll sind (rückt bei Absagen automatisch nach)</label><br>
      <label class="inline" style="margin-top:.4rem"><input type="checkbox" name="confirm_mail" value="1" <?= (int)$ev['confirm_mail'] === 1 ? 'checked' : '' ?>> Anmeldung per <strong>Mail bestätigen</strong> lassen</label><br>
      <label class="inline" style="margin-top:.4rem"><input type="checkbox" name="selfservice" value="1" <?= (int)$ev['selfservice'] === 1 ? 'checked' : '' ?>> <strong>Selbstbedienung</strong>: Angaben ändern und abmelden über den eigenen Link</label>
      <p class="small muted" style="margin:.5rem 0 0">Die Bestätigung hält Unfug draußen, und derselbe Link ist danach die Selbstbedienung – das erspart die meisten Rückfragen. Ohne Bestätigung zählt die Anmeldung sofort.</p>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-arrow-right"></i> Speichern und weiter</button>
        <button class="btn secondary" type="submit" name="bleiben" value="1">Nur speichern</button></div>
    </form>
  </div>

<?php elseif ($schritt === 'formular'): $fEdit = ($fid = (int)($_GET['feld'] ?? 0)) ? extern_field_get($fid) : null;
      if ($fEdit && (int)$fEdit['event_id'] !== $id) $fEdit = null; ?>
  <div class="card" id="formular">
    <div class="section-title" style="margin-top:0"><i class="ti ti-forms"></i> Formular <span class="count"><?= count($felder) ?></span></div>
    <p class="small muted" style="margin-top:0"><strong>Name und Mailadresse</strong> werden immer abgefragt – alles andere stellst du hier zusammen.</p>
    <?php if (!$felder): ?>
      <p class="empty small" style="margin:0">Noch keine Felder. Ohne Zusatzfelder reicht die Anmeldung mit Name und Mailadresse völlig aus.</p>
    <?php else: ?>
      <table class="list">
        <thead><tr><th>Feld</th><th>Art</th><th>Pflicht</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($felder as $i => $f): ?>
          <tr>
            <td><a href="extern-event.php?id=<?= $id ?>&amp;schritt=3&amp;feld=<?= (int)$f['id'] ?>#formular"><?= h($f['label']) ?></a>
              <?php if (extern_field_has_options((string)$f['type'])): ?>
                <span class="muted small">· <?= count($f['options']) ?> Möglichkeiten<?php
                  $mitKontingent = array_filter($f['options'], fn($o) => (int)$o['capacity'] > 0);
                  if ($mitKontingent): ?>, <?= count($mitKontingent) ?> mit Kontingent<?php endif; ?></span>
              <?php endif; ?></td>
            <td class="muted small"><?= h(extern_field_types()[(string)$f['type']] ?? $f['type']) ?></td>
            <td class="muted small"><?= (int)$f['required'] === 1 ? 'ja' : '–' ?></td>
            <td style="text-align:right">
              <div class="btn-row" style="justify-content:flex-end">
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="field_move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="3"><input type="hidden" name="field_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="dir" value="up">
                  <button class="btn secondary small" type="submit" title="Nach oben"<?= $i === 0 ? ' disabled' : '' ?>><i class="ti ti-chevron-up"></i></button></form>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="field_move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="3"><input type="hidden" name="field_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="dir" value="down">
                  <button class="btn secondary small" type="submit" title="Nach unten"<?= $i === count($felder) - 1 ? ' disabled' : '' ?>><i class="ti ti-chevron-down"></i></button></form>
                <form method="post" data-confirm="Dieses Feld löschen? Bereits gegebene Antworten darauf verschwinden mit." data-confirm-danger data-confirm-ok="Löschen">
                  <?= csrf_field() ?><input type="hidden" name="action" value="field_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="3"><input type="hidden" name="field_id" value="<?= (int)$f['id'] ?>">
                  <button class="btn danger small" type="submit">Löschen</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti <?= $fEdit ? 'ti-edit' : 'ti-plus' ?>"></i> <?= $fEdit ? 'Feld bearbeiten' : 'Neues Feld' ?></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="field_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="3">
      <?php if ($fEdit): ?><input type="hidden" name="field_id" value="<?= (int)$fEdit['id'] ?>"><?php endif; ?>
      <label for="flabel">Beschriftung</label>
      <input type="text" name="label" id="flabel" value="<?= h((string)($fEdit['label'] ?? '')) ?>" required placeholder="z. B. Fachbereich, Essenswunsch, Ich bringe mit …">
      <label for="fhelp">Erläuterung (optional)</label>
      <input type="text" name="help" id="fhelp" value="<?= h((string)($fEdit['help'] ?? '')) ?>">
      <div class="field-row">
        <div><label for="ftype">Art</label>
          <select name="type" id="ftype">
            <?php foreach (extern_field_types() as $k => $lbl): ?>
              <option value="<?= h($k) ?>" <?= (string)($fEdit['type'] ?? 'text') === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:0 1 120px"><label for="nmin">Zahl von</label><input type="number" name="num_min" id="nmin" value="<?= $fEdit && $fEdit['num_min'] !== null ? (int)$fEdit['num_min'] : '' ?>"></div>
        <div style="flex:0 1 120px"><label for="nmax">bis</label><input type="number" name="num_max" id="nmax" value="<?= $fEdit && $fEdit['num_max'] !== null ? (int)$fEdit['num_max'] : '' ?>"></div>
      </div>
      <label for="fopts">Antwortmöglichkeiten <span class="small muted">– eine je Zeile, nur bei Ein-/Mehrfachauswahl</span></label>
      <textarea name="options" id="fopts" rows="5" placeholder="Alte Kanzlei | 20&#10;Zum Rebstock | 25&#10;Ist mir egal"><?= h(implode("\n", array_map(
        fn($o) => (string)$o['label'] . ((int)$o['capacity'] > 0 ? ' | ' . (int)$o['capacity'] : ''), (array)($fEdit['options'] ?? [])))) ?></textarea>
      <p class="small muted" style="margin:.2rem 0 0">Ein <code>| 20</code> am Zeilenende setzt ein <strong>Kontingent</strong>: Ist die Möglichkeit voll, kann sie niemand mehr wählen. Ohne Angabe ist sie unbegrenzt.</p>
      <label class="inline" style="margin-top:.7rem"><input type="checkbox" name="required" value="1" <?= $fEdit && (int)$fEdit['required'] === 1 ? 'checked' : '' ?>> Pflichtfeld</label>
      <div class="btn-row" style="margin-top:.9rem">
        <button class="btn" type="submit"><i class="ti <?= $fEdit ? 'ti-device-floppy' : 'ti-plus' ?>"></i> <?= $fEdit ? 'Speichern' : 'Feld hinzufügen' ?></button>
        <?php if ($fEdit): ?><a class="btn secondary" href="extern-event.php?id=<?= $id ?>&amp;schritt=3#formular">Fertig</a><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="btn-row" style="margin:.4rem 0 1rem"><a class="btn" href="extern-event.php?id=<?= $id ?>&amp;schritt=4"><i class="ti ti-arrow-right"></i> Weiter zu den Gruppen</a></div>

<?php elseif ($schritt === 'gruppen'): $gEdit = ($gid = (int)($_GET['gruppe'] ?? 0)) ? extern_group_get($gid) : null;
      if ($gEdit && (int)$gEdit['event_id'] !== $id) $gEdit = null; ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-users-group"></i> Gruppen &amp; Einteilung</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_step"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="<?= $nr ?>">
      <label for="group_mode">Wie soll eingeteilt werden?</label>
      <select name="group_mode" id="group_mode">
        <?php foreach (extern_group_modes() as $k => $lbl): ?><option value="<?= h($k) ?>" <?= (string)$ev['group_mode'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
      </select>
      <div class="field-row" style="margin-top:.6rem">
        <div style="flex:0 1 190px"><label for="group_size">Wunsch-Gruppengröße</label><input type="number" name="group_size" id="group_size" min="0" value="<?= (int)$ev['group_size'] ?>"></div>
        <div><label for="mix_field_id">Mischkriterium</label>
          <select name="mix_field_id" id="mix_field_id">
            <option value="0">– keins –</option>
            <?php foreach ($felder as $f): if (!extern_field_has_options((string)$f['type'])) continue; ?>
              <option value="<?= (int)$f['id'] ?>" <?= (int)$ev['mix_field_id'] === (int)$f['id'] ? 'selected' : '' ?>><?= h($f['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="small muted" style="margin:.2rem 0 .8rem">Mit Mischkriterium landen beim Auffüllen nicht alle mit derselben Antwort in einer Gruppe – etwa nach Fachbereich gemischt. Auswählbar sind nur Felder mit festen Antwortmöglichkeiten.</p>
      <label class="inline"><input type="checkbox" name="allow_code" value="1" <?= (int)$ev['allow_code'] === 1 ? 'checked' : '' ?>> <strong>Gemeinsamer Code</strong>: Freundeskreise melden sich einzeln an und landen zusammen in einer Gruppe</label><br>
      <label class="inline" style="margin-top:.4rem"><input type="checkbox" name="allow_party" value="1" <?= (int)$ev['allow_party'] === 1 ? 'checked' : '' ?>> <strong>Sammelanmeldung</strong>: eine Person meldet mehrere an</label>
      <div class="field-row" style="margin-top:.4rem">
        <div style="flex:0 1 200px"><label for="party_max">Höchstens Personen je Sammelanmeldung</label><input type="number" name="party_max" id="party_max" min="1" max="20" value="<?= (int)$ev['party_max'] ?>"></div>
      </div>
      <p class="small muted" style="margin:.4rem 0 0">Beides darf gleichzeitig an sein – dann entscheiden die Anmeldenden selbst, was zu ihnen passt.</p>

      <div class="section-title" style="font-size:1rem"><i class="ti ti-route"></i> Rotationsplan (optional)</div>
      <p class="small muted" style="margin-top:0">Für Kneipentouren und Stationenläufe: Die Gruppen ziehen Runde für Runde weiter, statt den ganzen Abend an einem Ort zu bleiben. <strong>0 Runden = aus.</strong></p>
      <div class="field-row">
        <div style="flex:0 1 150px"><label for="rot_rounds">Runden</label><input type="number" name="rot_rounds" id="rot_rounds" min="0" max="12" value="<?= (int)$ev['rot_rounds'] ?>"></div>
        <div><label for="rot_start">Beginn der ersten Runde</label><input type="text" class="fp-datetime" name="rot_start" id="rot_start" value="<?= h(substr((string)$ev['rot_start'], 0, 16)) ?>"></div>
        <div style="flex:0 1 170px"><label for="rot_minutes">Minuten je Runde</label><input type="number" name="rot_minutes" id="rot_minutes" min="10" max="600" value="<?= (int)$ev['rot_minutes'] ?>"></div>
      </div>
      <p class="small muted" style="margin:.2rem 0 0">Am schönsten geht es auf, wenn es <strong>mindestens so viele Stationen wie Gruppen</strong> gibt – dann trifft in keiner Runde eine Gruppe auf die andere.</p>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-arrow-right"></i> Speichern und weiter</button>
        <button class="btn secondary" type="submit" name="bleiben" value="1">Nur speichern</button></div>
    </form>
  </div>

  <?php if ((string)$ev['group_mode'] === 'fixed'): ?>
    <div class="card" id="gruppen">
      <div class="section-title" style="margin-top:0"><i class="ti ti-list-details"></i> Gruppen <span class="count"><?= count($gruppen) ?></span></div>
      <?php if (!$gruppen): ?>
        <p class="empty small" style="margin:0">Noch keine Gruppe. Ohne mindestens eine lässt sich die Anmeldung nicht öffnen.</p>
      <?php else: ?>
        <table class="list">
          <thead><tr><th>Gruppe</th><th>Plätze</th><th>Treffpunkt</th><th>Leitung</th><th></th></tr></thead>
          <tbody>
          <?php $mitglieder = members_all(); foreach ($gruppen as $i => $g):
              $leiter = null;
              foreach ($mitglieder as $mm) if ((int)$mm['id'] === (int)$g['leader_id']) $leiter = $mm; ?>
            <tr>
              <td><a href="extern-event.php?id=<?= $id ?>&amp;schritt=4&amp;gruppe=<?= (int)$g['id'] ?>#gruppen"><?= h($g['name']) ?></a></td>
              <td class="muted small"><?= (int)$g['capacity'] > 0 ? (int)$g['capacity'] : '∞' ?></td>
              <td class="muted small"><?= h($g['place']) ?: '–' ?><?= trim((string)$g['starts_at']) !== '' ? ' · ' . h(substr((string)$g['starts_at'], 11, 5)) . ' Uhr' : '' ?></td>
              <td class="muted small"><?= $leiter ? member_link($leiter) : '–' ?></td>
              <td style="text-align:right">
                <div class="btn-row" style="justify-content:flex-end">
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="group_move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="4"><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="dir" value="up">
                    <button class="btn secondary small" type="submit" title="Nach oben"<?= $i === 0 ? ' disabled' : '' ?>><i class="ti ti-chevron-up"></i></button></form>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="group_move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="4"><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="dir" value="down">
                    <button class="btn secondary small" type="submit" title="Nach unten"<?= $i === count($gruppen) - 1 ? ' disabled' : '' ?>><i class="ti ti-chevron-down"></i></button></form>
                  <form method="post" data-confirm="Diese Gruppe löschen? Die zugeteilten Personen bleiben angemeldet und sind wieder unverteilt." data-confirm-danger data-confirm-ok="Löschen">
                    <?= csrf_field() ?><input type="hidden" name="action" value="group_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="4"><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                    <button class="btn danger small" type="submit">Löschen</button></form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="section-title" style="margin-top:0"><i class="ti <?= $gEdit ? 'ti-edit' : 'ti-plus' ?>"></i> <?= $gEdit ? 'Gruppe bearbeiten' : 'Neue Gruppe' ?></div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="group_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="4">
        <?php if ($gEdit): ?><input type="hidden" name="group_id" value="<?= (int)$gEdit['id'] ?>"><?php endif; ?>
        <div class="field-row">
          <div><label for="gname">Name</label><input type="text" name="name" id="gname" value="<?= h((string)($gEdit['name'] ?? '')) ?>" required placeholder="z. B. Gruppe 1 – Alte Kanzlei"></div>
          <div style="flex:0 1 130px"><label for="gcap">Plätze</label><input type="number" name="capacity" id="gcap" min="0" value="<?= (int)($gEdit['capacity'] ?? 0) ?>"></div>
        </div>
        <div class="field-row">
          <div><label for="gplace">Treffpunkt</label><input type="text" name="place" id="gplace" value="<?= h((string)($gEdit['place'] ?? '')) ?>"></div>
          <div><label for="gstart">Startzeit</label><input type="text" class="fp-datetime" name="starts_at" id="gstart" value="<?= h(substr((string)($gEdit['starts_at'] ?? ''), 0, 16)) ?>"></div>
          <div><label for="gleader">Leitung</label>
            <select name="leader_id" id="gleader">
              <option value="">– niemand –</option>
              <?php foreach (members_all() as $mm): ?>
                <option value="<?= (int)$mm['id'] ?>" <?= (int)($gEdit['leader_id'] ?? 0) === (int)$mm['id'] ? 'selected' : '' ?>><?= h(short_name((string)$mm['name'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <label for="gnote">Notiz (nur intern)</label>
        <input type="text" name="note" id="gnote" value="<?= h((string)($gEdit['note'] ?? '')) ?>">
        <div class="btn-row" style="margin-top:.9rem">
          <button class="btn" type="submit"><i class="ti <?= $gEdit ? 'ti-device-floppy' : 'ti-plus' ?>"></i> <?= $gEdit ? 'Speichern' : 'Gruppe hinzufügen' ?></button>
          <?php if ($gEdit): ?><a class="btn secondary" href="extern-event.php?id=<?= $id ?>&amp;schritt=4#gruppen">Fertig</a><?php endif; ?>
        </div>
      </form>
    </div>
    <div class="btn-row" style="margin:.4rem 0 1rem"><a class="btn" href="extern-event.php?id=<?= $id ?>&amp;schritt=5"><i class="ti ti-arrow-right"></i> Weiter zur Sichtbarkeit</a></div>
  <?php endif; ?>

  <?php if ((int)$ev['rot_rounds'] > 0): $stationen = extern_stations_of($id);
        $sEdit = ($sid = (int)($_GET['station'] ?? 0)) ? extern_station_get($sid) : null;
        if ($sEdit && (int)$sEdit['event_id'] !== $id) $sEdit = null; ?>
    <div class="card" id="stationen">
      <div class="section-title" style="margin-top:0"><i class="ti ti-route"></i> Stationen <span class="count"><?= count($stationen) ?></span></div>
      <p class="small muted" style="margin-top:0">Die Orte, die der Reihe nach besucht werden – bei einer Kneipentour die Kneipen. Die Reihenfolge bestimmt, wo welche Gruppe startet.</p>
      <?php if (!$stationen): ?>
        <p class="empty small" style="margin:0">Noch keine Station. Ohne mindestens eine lässt sich kein Plan erzeugen.</p>
      <?php else: ?>
        <table class="list">
          <thead><tr><th>Station</th><th>Ort</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($stationen as $i => $stn): ?>
            <tr>
              <td><a href="extern-event.php?id=<?= $id ?>&amp;schritt=4&amp;station=<?= (int)$stn['id'] ?>#stationen"><?= h($stn['name']) ?></a></td>
              <td class="muted small"><?= h($stn['place']) ?: '–' ?></td>
              <td style="text-align:right">
                <div class="btn-row" style="justify-content:flex-end">
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="station_move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="4"><input type="hidden" name="station_id" value="<?= (int)$stn['id'] ?>"><input type="hidden" name="dir" value="up">
                    <button class="btn secondary small" type="submit" title="Nach oben"<?= $i === 0 ? ' disabled' : '' ?>><i class="ti ti-chevron-up"></i></button></form>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="station_move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="4"><input type="hidden" name="station_id" value="<?= (int)$stn['id'] ?>"><input type="hidden" name="dir" value="down">
                    <button class="btn secondary small" type="submit" title="Nach unten"<?= $i === count($stationen) - 1 ? ' disabled' : '' ?>><i class="ti ti-chevron-down"></i></button></form>
                  <form method="post" data-confirm="Diese Station löschen? Der Rotationsplan muss danach neu erzeugt werden." data-confirm-danger data-confirm-ok="Löschen">
                    <?= csrf_field() ?><input type="hidden" name="action" value="station_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="4"><input type="hidden" name="station_id" value="<?= (int)$stn['id'] ?>">
                    <button class="btn danger small" type="submit">Löschen</button></form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <form method="post" style="margin-top:.8rem">
        <?= csrf_field() ?><input type="hidden" name="action" value="station_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="4">
        <?php if ($sEdit): ?><input type="hidden" name="station_id" value="<?= (int)$sEdit['id'] ?>"><?php endif; ?>
        <div class="field-row">
          <div><label for="sname"><?= $sEdit ? 'Station bearbeiten' : 'Neue Station' ?></label><input type="text" name="name" id="sname" value="<?= h((string)($sEdit['name'] ?? '')) ?>" required placeholder="z. B. Alte Kanzlei"></div>
          <div><label for="splace">Ort / Adresse</label><input type="text" name="place" id="splace" value="<?= h((string)($sEdit['place'] ?? '')) ?>"></div>
          <div><label for="snote">Notiz</label><input type="text" name="note" id="snote" value="<?= h((string)($sEdit['note'] ?? '')) ?>"></div>
        </div>
        <div class="btn-row" style="margin-top:.6rem">
          <button class="btn secondary" type="submit"><i class="ti <?= $sEdit ? 'ti-device-floppy' : 'ti-plus' ?>"></i> <?= $sEdit ? 'Speichern' : 'Station hinzufügen' ?></button>
          <?php if ($sEdit): ?><a class="btn secondary" href="extern-event.php?id=<?= $id ?>&amp;schritt=4#stationen">Fertig</a><?php endif; ?>
        </div>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($schritt === 'sichtbarkeit'): ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-eye"></i> Sichtbarkeit</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_step"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="<?= $nr ?>">
      <label for="show_groups">Wer darf die Einteilung sehen?</label>
      <select name="show_groups" id="show_groups">
        <?php foreach (extern_visibility_modes() as $k => $lbl): ?><option value="<?= h($k) ?>" <?= (string)$ev['show_groups'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
      </select>
      <label class="inline" style="margin-top:.7rem"><input type="checkbox" name="show_names" value="1" <?= (int)$ev['show_names'] === 1 ? 'checked' : '' ?>> <strong>Namen</strong> in der Gruppenliste zeigen</label>
      <?php if ((string)$ev['show_groups'] === 'public'): ?>
        <p class="small<?= (int)$ev['show_names'] === 1 ? ' warn-line' : ' muted' ?>" style="margin:.5rem 0 0">
          <i class="ti ti-world"></i> „Öffentlich" heißt wirklich öffentlich: Die Einteilung steht unter
          <code>anmeldung/gruppen.php?e=<?= h((string)$ev['slug']) ?></code> für jeden im Netz.
          <?php if ((int)$ev['show_names'] === 1): ?><strong>Mit Namen</strong> veröffentlichst du damit eine
          Teilnehmendenliste – überleg dir das gut; die Seite wird immerhin von Suchmaschinen ferngehalten.
          <?php else: ?>Ohne Namen stehen dort nur Gruppengrößen, Orte und der Fahrplan.<?php endif; ?>
        </p>
      <?php endif; ?>
      <p class="small muted" style="margin:.2rem 0 .6rem">Ohne Häkchen steht dort nur, wie viele Leute in der Gruppe sind. Eine öffentliche Namensliste im Netz ist eine bewusste Entscheidung – bei einer Kneipentour praktisch, sonst oft nicht nötig.</p>
      <label class="inline"><input type="checkbox" name="show_count" value="1" <?= (int)$ev['show_count'] === 1 ? 'checked' : '' ?>> <strong>Freie Plätze</strong> auf der Anmeldeseite anzeigen</label>
      <div class="field-row" style="margin-top:.8rem">
        <div style="flex:0 1 220px"><label for="keep_days">Daten löschen nach (Tagen)</label><input type="number" name="keep_days" id="keep_days" min="7" max="730" value="<?= (int)$ev['keep_days'] ?>"></div>
        <div><p class="small muted" style="margin:1.6rem 0 0">Nach dem Termin. Danach verschwinden Namen, Adressen und Antworten von selbst – die Zahlen bleiben.</p></div>
      </div>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-arrow-right"></i> Speichern und weiter</button>
        <button class="btn secondary" type="submit" name="bleiben" value="1">Nur speichern</button></div>
    </form>
  </div>

<?php else: // ---- Schritt 6: Prüfen & veröffentlichen ----
  $probleme = [];
  if (trim((string)$ev['title']) === '')  $probleme[] = 'Es fehlt ein Titel.';
  if (trim((string)$ev['referat']) === '') $probleme[] = 'Kein betreuendes Referat eingetragen – dann kann nur der Vorsitz die Veranstaltung betreuen.';
  if ($basis === '')                       $probleme[] = 'In den Einstellungen fehlt die Basis-Adresse – ohne sie gibt es keinen Link und keine Mail.';
  if (trim(extern_setting_get('from_email', '')) === '') $probleme[] = 'Es ist keine Absender-Adresse hinterlegt – Bestätigungsmails könnten nicht rausgehen.';
  if ((string)$ev['group_mode'] === 'fixed' && !$gruppen) $probleme[] = 'Feste Gruppen eingestellt, aber keine angelegt.';
  if ((string)$ev['group_mode'] === 'auto' && (int)$ev['group_size'] < 2) $probleme[] = 'Für errechnete Gruppen fehlt die Wunschgröße.';
  ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-checkup-list"></i> Prüfen</div>
    <?php if ($probleme): ?>
      <ul class="small" style="margin:0;padding-left:1.1rem">
        <?php foreach ($probleme as $p): ?><li class="warn-line"><?= h($p) ?></li><?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="small" style="margin:0"><i class="ti ti-circle-check" style="color:var(--green)"></i> Alles beisammen.</p>
    <?php endif; ?>
    <table class="list" style="margin-top:.8rem">
      <tbody>
        <tr><td>Termin</td><td><?= trim((string)$ev['starts_at']) !== '' ? h(fmt_date(substr((string)$ev['starts_at'], 0, 10))) . ', ' . h(substr((string)$ev['starts_at'], 11, 5)) . ' Uhr' : '–' ?><?= trim((string)$ev['place']) !== '' ? ' · ' . h($ev['place']) : '' ?></td></tr>
        <tr><td>Anmeldung</td><td><?= (int)$ev['capacity'] > 0 ? (int)$ev['capacity'] . ' Plätze' : 'unbegrenzt' ?><?= (int)$ev['waitlist'] === 1 ? ' · mit Warteliste' : '' ?><?= (int)$ev['confirm_mail'] === 1 ? ' · Bestätigung per Mail' : ' · ohne Bestätigung' ?></td></tr>
        <tr><td>Formular</td><td><?= count($felder) ?> Zusatzfeld<?= count($felder) === 1 ? '' : 'er' ?> neben Name und Mailadresse</td></tr>
        <tr><td>Gruppen</td><td><?= h(extern_group_modes()[(string)$ev['group_mode']] ?? '') ?><?= (string)$ev['group_mode'] === 'fixed' ? ' · ' . count($gruppen) . ' angelegt' : '' ?><?php
          if ((int)$ev['allow_code'] === 1): ?> · Code<?php endif; if ((int)$ev['allow_party'] === 1): ?> · Sammelanmeldung<?php endif; ?></td></tr>
        <tr><td>Sichtbarkeit</td><td><?= h(extern_visibility_modes()[(string)$ev['show_groups']] ?? '') ?><?= (int)$ev['show_names'] === 1 ? ' · mit Namen' : '' ?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-link"></i> Link &amp; Status</div>
    <?php if ($link !== ''): ?>
      <p class="small" style="margin:0 0 .6rem"><a href="<?= h($link) ?>" target="_blank" rel="noopener"><code><?= h($link) ?></code></a></p>
      <?php if ($basisWeicht): ?>
        <p class="small warn-line" style="margin:0 0 .6rem"><i class="ti ti-alert-triangle"></i>
          Die <strong>Basis-Adresse</strong> der externen Events (<code><?= h($basis) ?></code>) weicht von der App ab
          (<code><?= h($appBasis) ?></code>) – dieser Link führt dann ins Leere.
          In den <a href="extern.php?t=einstellungen">Einstellungen</a> per Klick aus der App übernehmen.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="small warn-line" style="margin:0 0 .6rem"><i class="ti ti-alert-triangle"></i> Ohne <strong>Basis-Adresse</strong> in den <a href="extern.php?t=einstellungen">Einstellungen</a> gibt es keinen öffentlichen Link und keine Mails.</p>
    <?php endif; ?>
    <div class="btn-row">
      <?php foreach ([['open', 'ti-player-play', 'Anmeldung öffnen'], ['closed', 'ti-lock', 'Anmeldung schließen'], ['draft', 'ti-pencil', 'Zurück in den Entwurf'], ['archived', 'ti-archive', 'Archivieren']] as [$s, $ic, $lbl]): ?>
        <?php if ((string)$ev['status'] !== $s): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="<?= $nr ?>"><input type="hidden" name="status" value="<?= $s ?>">
            <button class="btn <?= $s === 'open' ? '' : 'secondary' ?> small" type="submit"<?= $s === 'open' && $probleme ? ' disabled title="Erst die Punkte oben klären"' : '' ?>><i class="ti <?= $ic ?>"></i> <?= $lbl ?></button></form>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-trash"></i> Löschen</div>
    <p class="small muted" style="margin-top:0">Entfernt Formular, Gruppen und <strong>alle Anmeldungen</strong>. Nicht rückgängig zu machen.</p>
    <form method="post" data-confirm="Diese Veranstaltung mit allen Anmeldungen endgültig löschen?" data-confirm-danger data-confirm-ok="Endgültig löschen">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="schritt" value="<?= $nr ?>">
      <button class="btn danger" type="submit">Veranstaltung löschen</button>
    </form>
  </div>
<?php endif; ?>
<?php
page_footer();
