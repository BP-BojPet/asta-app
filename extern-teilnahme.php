<?php
/**
 * Externes Event betreuen: Anmeldungen ansehen und die Gruppen einteilen.
 *
 * Getrennt vom Einrichtungs-Assistenten: Der ist einmal dran, diese Seite begleitet die
 * Veranstaltung bis zum Termin.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $ziel = 'extern-teilnahme.php?id=' . $id;

    if ($action === 'assign') {
        $r = extern_assign_auto($id, !empty($_POST['only_new']));
        // Die Hinweise gehören zur Meldung – wer sie erst suchen muss, übersieht sie.
        $txt = (string)$r['msg'];
        foreach ((array)$r['probleme'] as $p) $txt .= ' · ' . $p;
        flash($txt, !empty($r['ok']) && (int)$r['offen'] === 0 ? 'success' : 'error');
        redirect($ziel . '#einteilung');
    }
    if ($action === 'assign_clear') {
        $n = extern_assign_clear($id);
        flash($n . ' Zuteilungen aufgehoben.', 'success');
        redirect($ziel . '#einteilung');
    }
    if ($action === 'groups_auto') {
        $r = extern_groups_auto($id);
        flash((string)$r['msg'], !empty($r['ok']) ? 'success' : 'error');
        redirect($ziel . '#einteilung');
    }
    if ($action === 'rot_build') {
        $r = extern_rotation_build($id);
        $txt = (string)$r['msg'];
        foreach ((array)$r['hinweise'] as $hh) $txt .= ' · ' . $hh;
        flash($txt, !empty($r['ok']) && !$r['hinweise'] ? 'success' : 'error');
        redirect($ziel . '#rotation');
    }
    if ($action === 'rot_clear') {
        extern_rotation_clear($id);
        flash('Rotationsplan gelöscht.', 'success');
        redirect($ziel . '#rotation');
    }
    if ($action === 'send') {
        $wen = (string)($_POST['wen'] ?? '');
        $gid = (int)($_POST['group_id'] ?? 0);
        $empf = extern_recipients($id, $wen, $gid);
        $r = extern_send_bulk($ev, $empf, (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''),
            $wen === 'assigned' ? 'assign' : 'info');
        flash((string)$r['msg'], !empty($r['ok']) ? 'success' : 'error');
        redirect($ziel . '#nachricht');
    }
    if ($action === 'move') {
        extern_assign_set((int)($_POST['signup_id'] ?? 0), (int)($_POST['group_id'] ?? 0))
            ? flash('Verschoben.', 'success')
            : flash('Das ging nicht.', 'error');
        redirect($ziel . '#einteilung');
    }
    redirect($ziel);
}

// CSV-Ausgabe muss VOR jeder HTML-Ausgabe passieren
if (!empty($_GET['export'])) { extern_export_csv($id); exit; }

$zahlen  = extern_counts($id);
$gruppen = extern_groups_of($id);
$belegung = extern_group_load($id);
$felder  = extern_fields_of($id);
$blocks  = extern_blocks($id);
$mehrPersonen = array_values(array_filter($blocks, fn($b) => $b['size'] > 1));

page_header('Betreuen – ' . $ev['title']);
?>
<p class="small"><a href="extern.php">‹ Externe Events</a> · <a href="extern-event.php?id=<?= $id ?>">Einrichtung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-users-group" style="color:var(--petrol)"></i> <?= h($ev['title']) ?></h1>
  <div class="btn-row">
    <a class="btn secondary" href="extern-teilnahme.php?id=<?= $id ?>&amp;export=1"><i class="ti ti-file-spreadsheet"></i> Als CSV</a>
    <a class="btn secondary" href="extern-event.php?id=<?= $id ?>&amp;schritt=6"><i class="ti ti-settings"></i> Einrichtung</a>
  </div>
</div>

<div class="tiles">
  <div class="tile"><i class="ti ti-user-check"></i><div class="num"><?= (int)$zahlen['personen'] ?></div><div class="lbl">Personen dabei</div></div>
  <div class="tile"><i class="ti ti-hourglass"></i><div class="num"><?= (int)$zahlen['warteliste'] ?></div><div class="lbl">Warteliste</div></div>
  <div class="tile"><i class="ti ti-mail"></i><div class="num"><?= (int)$zahlen['offen'] ?></div><div class="lbl">unbestätigt</div></div>
  <div class="tile"><i class="ti ti-door-exit"></i><div class="num"><?= (int)$zahlen['abgemeldet'] ?></div><div class="lbl">abgemeldet</div></div>
</div>

<?php
// ---- Anschauliche Zahlen (gleicher Bausatz wie bei den Umfragen) ----------------
// Anmelde-Eingänge je Tag: hier gibt es echte Zeitstempel – anders als in der Umfrage-Urne
// ist das kein Anonymitäts-Thema, die Namen stehen ohnehin in der Liste darunter.
$st = extern_db()->prepare("SELECT date(created_at) AS tag, COUNT(*) AS n FROM signups WHERE event_id = ? GROUP BY date(created_at)");
$st->execute([$id]);
$verlaufRoh = [];
foreach ($st->fetchAll() as $r) $verlaufRoh[(string)$r['tag']] = (int)$r['n'];
$verlaufBild = chart_verlauf_html(chart_tage_fuellen($verlaufRoh));

// Gruppen-Füllstände: belegt gegen Kapazität (ohne Kapazität: gegen die vollste Gruppe).
$fuellstand = [];
if ($gruppen) {
    $belegtJe = [];
    foreach (extern_signups_of($id, 'confirmed') as $s) {
        $gid = (int)($s['group_id'] ?? 0);
        if ($gid > 0) $belegtJe[$gid] = ($belegtJe[$gid] ?? 0) + max(1, (int)$s['party_size']);
    }
    $maxBelegt = max(1, $belegtJe ? max($belegtJe) : 1);
    foreach ($gruppen as $g) {
        $b = (int)($belegtJe[(int)$g['id']] ?? 0);
        $basis = (int)$g['capacity'] > 0 ? (int)$g['capacity'] : $maxBelegt;
        $fuellstand[] = ['label' => (string)$g['name'] . ((int)$g['capacity'] > 0 ? ' (' . $b . '/' . (int)$g['capacity'] . ')' : ''),
                         'n' => $b, 'pct' => (int)round($b * 100 / max(1, $basis))];
    }
}

// Antwort-Verteilungen der Auswahl-Felder – wer dabei ist (bestätigt + Warteliste) zählt.
$feldBilder = [];
$aktiveIds = array_map(fn($s) => (int)$s['id'],
    array_filter(extern_signups_of($id), fn($s) => in_array((string)$s['status'], ['confirmed', 'waitlist', 'pending'], true)));
if ($aktiveIds) {
    $inListe = implode(',', $aktiveIds);
    foreach ($felderAlle = extern_fields_of($id) as $f) {
        if (!in_array((string)$f['type'], ['single', 'multi', 'bool'], true)) continue;
        if ((string)$f['type'] === 'bool') {
            $st = extern_db()->query('SELECT COUNT(*) FROM answers WHERE field_id = ' . (int)$f['id']
                . " AND value_text = '1' AND signup_id IN ($inListe)");
            $ja = (int)$st->fetchColumn();
            $ges = count($aktiveIds);
            $feldBilder[] = ['label' => (string)$f['label'], 'zeilen' => [
                ['label' => 'Ja', 'n' => $ja, 'pct' => (int)round($ja * 100 / max(1, $ges))],
                ['label' => 'Nein / leer', 'n' => $ges - $ja, 'pct' => (int)round(($ges - $ja) * 100 / max(1, $ges))],
            ]];
            continue;
        }
        $zeilen = [];
        $summe = 0;
        foreach ((array)$f['options'] as $o) {
            $st = extern_db()->query('SELECT COUNT(*) FROM answers WHERE option_id = ' . (int)$o['id'] . " AND signup_id IN ($inListe)");
            $n = (int)$st->fetchColumn();
            $summe += $n;
            $zeilen[] = ['label' => (string)$o['label'] . ((int)$o['capacity'] > 0 ? ' (' . $n . '/' . (int)$o['capacity'] . ')' : ''), 'n' => $n, 'pct' => 0];
        }
        foreach ($zeilen as &$z) $z['pct'] = (int)round($z['n'] * 100 / max(1, $summe));
        unset($z);
        if ($zeilen) $feldBilder[] = ['label' => (string)$f['label'], 'zeilen' => $zeilen];
    }
}
?>
<?php if ($verlaufBild !== '' || $fuellstand || $feldBilder): ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-chart-bar"></i> Auf einen Blick</div>
    <?php if ($verlaufBild !== ''): ?>
      <p class="small muted" style="margin:0 0 .2rem">Anmeldungen je Tag</p>
      <?= $verlaufBild ?>
    <?php endif; ?>
    <?php if ($fuellstand): ?>
      <p class="small muted" style="margin:.8rem 0 0">Gruppen-Füllstand</p>
      <?= chart_balken_html($fuellstand) ?>
    <?php endif; ?>
    <?php foreach ($feldBilder as $fb): ?>
      <p class="small muted" style="margin:.8rem 0 0"><?= h($fb['label']) ?></p>
      <?= chart_balken_html($fb['zeilen']) ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ((string)$ev['group_mode'] === 'none'): ?>
  <div class="card"><p class="empty"><i class="ti ti-users-group"></i> Für diese Veranstaltung ist keine Einteilung eingestellt.
    <a href="extern-event.php?id=<?= $id ?>&amp;schritt=4">In der Einrichtung ändern</a>, wenn du das möchtest.</p></div>
<?php else: ?>
  <div class="section-title" id="einteilung"><i class="ti ti-arrows-shuffle"></i> Einteilung</div>
  <div class="card">
    <p class="small muted" style="margin-top:0">
      Verteilt wird so: <strong>feste Blöcke zuerst</strong> – wer denselben Code hat oder gemeinsam angemeldet ist,
      bleibt zusammen – und zwar absteigend nach Größe, weil die großen sonst keinen Platz mehr finden.
      Danach füllen Einzelne die jeweils kleinste Gruppe auf<?= (int)$ev['mix_field_id'] > 0 ? ', bei Gleichstand nach dem Mischkriterium' : '' ?>.
      Was nirgends passt, bleibt liegen und wird gemeldet – lieber das, als einen Freundeskreis auseinanderzureißen.
    </p>
    <?php if ($mehrPersonen): ?>
      <p class="small muted"><i class="ti ti-users"></i> Zusammenhängend: <?= count($mehrPersonen) ?> Block<?= count($mehrPersonen) === 1 ? '' : 'ock' ?>
        (<?= implode(', ', array_map(fn($b) => $b['size'] . 'er', array_slice($mehrPersonen, 0, 8))) ?><?= count($mehrPersonen) > 8 ? ' …' : '' ?>)</p>
    <?php endif; ?>
    <div class="btn-row">
      <?php if ((string)$ev['group_mode'] === 'auto'): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="groups_auto"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn secondary small" type="submit"><i class="ti ti-layout-grid-add"></i> Gruppen anlegen</button></form>
      <?php endif; ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="only_new" value="1">
        <button class="btn small" type="submit"<?= !$gruppen ? ' disabled title="Erst Gruppen anlegen"' : '' ?>><i class="ti ti-user-plus"></i> Offene einteilen</button></form>
      <form method="post" style="display:inline" data-confirm="Alles neu verteilen? Von Hand gesetzte Zuordnungen gehen dabei verloren." data-confirm-ok="Neu verteilen">
        <?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn secondary small" type="submit"<?= !$gruppen ? ' disabled' : '' ?>><i class="ti ti-arrows-shuffle"></i> Alles neu verteilen</button></form>
      <form method="post" style="display:inline" data-confirm="Alle Zuteilungen aufheben?" data-confirm-danger data-confirm-ok="Aufheben">
        <?= csrf_field() ?><input type="hidden" name="action" value="assign_clear"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn secondary small" type="submit">Einteilung aufheben</button></form>
    </div>
  </div>

  <?php if (!$gruppen): ?>
    <div class="card"><p class="empty"><i class="ti ti-layout-grid-add"></i> Noch keine Gruppen.
      <?= (string)$ev['group_mode'] === 'auto' ? 'Der Knopf oben legt sie aus der Wunschgröße an.' : '<a href="extern-event.php?id=' . $id . '&amp;schritt=4">In der Einrichtung anlegen</a>.' ?></p></div>
  <?php else: ?>
    <div class="ex-gruppen">
      <?php foreach ($belegung as $gid => $g): if ($gid === 0 && !$g['signups']) continue; ?>
        <div class="card ex-gruppe<?= $gid === 0 ? ' ex-offen' : '' ?>">
          <div class="ex-gruppe-kopf">
            <strong><?= $gid === 0 ? '<i class="ti ti-alert-triangle"></i> Noch nicht eingeteilt' : h($g['gruppe']['name']) ?></strong>
            <span class="pill<?= $gid > 0 && (int)$g['gruppe']['capacity'] > 0 && $g['personen'] > (int)$g['gruppe']['capacity'] ? ' pill-bad' : '' ?>">
              <?= (int)$g['personen'] ?><?= $gid > 0 && (int)$g['gruppe']['capacity'] > 0 ? ' / ' . (int)$g['gruppe']['capacity'] : '' ?>
            </span>
          </div>
          <?php if ($gid > 0): $gg = $g['gruppe']; ?>
            <p class="small muted" style="margin:.2rem 0 .5rem">
              <?php if (trim((string)$gg['place']) !== ''): ?><i class="ti ti-map-pin"></i> <?= h($gg['place']) ?><?php endif; ?>
              <?php if (trim((string)$gg['starts_at']) !== ''): ?> · <?= h(substr((string)$gg['starts_at'], 11, 5)) ?> Uhr<?php endif; ?>
              <?php $l = (int)$gg['leader_id'] ? member_get((int)$gg['leader_id']) : null; if ($l): ?> · <?= member_link($l) ?><?php endif; ?>
            </p>
          <?php endif; ?>
          <?php if (!$g['signups']): ?>
            <p class="empty small" style="margin:0">Noch niemand.</p>
          <?php else: ?>
            <ul class="ex-liste">
              <?php foreach ($g['signups'] as $s): ?>
                <li>
                  <span class="ex-name"><?= h($s['name']) ?><?php
                    if ((int)$s['party_size'] > 1): ?> <span class="muted small">+<?= (int)$s['party_size'] - 1 ?></span><?php endif;
                    if (trim((string)$s['code']) !== ''): ?> <span class="ex-code" title="Freundeskreis – bleibt zusammen"><?= h($s['code']) ?></span><?php endif; ?></span>
                  <form method="post" class="ex-move">
                    <?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="signup_id" value="<?= (int)$s['id'] ?>">
                    <select name="group_id" onchange="this.form.submit()" aria-label="Verschieben">
                      <option value="0"<?= $gid === 0 ? ' selected' : '' ?>>– unverteilt –</option>
                      <?php foreach ($gruppen as $gw): ?>
                        <option value="<?= (int)$gw['id'] ?>"<?= (int)$gw['id'] === $gid ? ' selected' : '' ?>><?= h($gw['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php /* Der Knopf ist der Weg ohne JavaScript – mit JS greift schon das
                             onchange oben, dann ist er einfach überflüssig. */ ?>
                    <button class="btn secondary small ex-go" type="submit" title="Übernehmen"><i class="ti ti-check"></i></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="small muted"><i class="ti ti-info-circle"></i> Beim Verschieben von Hand wandert <strong>nur diese eine Anmeldung</strong> – ein Freundeskreis (erkennbar am Code) bleibt sonst nicht zusammen. Beim nächsten „Alles neu verteilen" wird er wieder zusammengeführt.</p>
  <?php endif; ?>
<?php endif; ?>

<?php if ((int)$ev['rot_rounds'] > 0): $plan = extern_rotation_of($id); $stationen = extern_stations_of($id); ?>
  <div class="section-title" id="rotation"><i class="ti ti-route"></i> Rotationsplan</div>
  <div class="card">
    <p class="small muted" style="margin-top:0">Jede Gruppe startet an „ihrer" Station und zieht je Runde eine weiter. Gibt es mindestens so viele Stationen wie Gruppen, trifft in keiner Runde eine Gruppe auf die andere.</p>
    <p class="small muted"><?= count($gruppen) ?> Gruppen · <?= count($stationen) ?> Stationen · <?= (int)$ev['rot_rounds'] ?> Runden à <?= (int)$ev['rot_minutes'] ?> Minuten<?php
      if (trim((string)$ev['rot_start']) !== ''): ?> ab <?= h(substr((string)$ev['rot_start'], 11, 5)) ?> Uhr<?php endif; ?></p>
    <div class="btn-row">
      <form method="post" style="display:inline"<?= $plan ? ' data-confirm="Plan neu erzeugen? Der bisherige wird ersetzt." data-confirm-ok="Neu erzeugen"' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="rot_build"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn small" type="submit"<?= !$gruppen || !$stationen ? ' disabled title="Erst Gruppen und Stationen anlegen"' : '' ?>><i class="ti ti-route"></i> Plan erzeugen</button></form>
      <?php if ($plan): ?>
        <form method="post" style="display:inline" data-confirm="Rotationsplan löschen?" data-confirm-danger data-confirm-ok="Löschen">
          <?= csrf_field() ?><input type="hidden" name="action" value="rot_clear"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn secondary small" type="submit">Plan löschen</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($plan): ?>
    <div class="card" style="padding:.4rem .2rem">
      <table class="list">
        <thead><tr><th>Runde</th><?php foreach ($gruppen as $g): ?><th><?= h($g['name']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($plan as $runde => $zeilen): $nachGruppe = [];
              foreach ($zeilen as $z) if ($z['gruppe']) $nachGruppe[(int)$z['gruppe']['id']] = $z; ?>
          <tr>
            <td><strong><?= (int)$runde ?>.</strong><?php $z0 = $zeilen[0] ?? null;
              if ($z0 && trim((string)$z0['zeit']) !== ''): ?><br><span class="muted small"><?= h(date('H:i', strtotime((string)$z0['zeit']))) ?> Uhr</span><?php endif; ?></td>
            <?php foreach ($gruppen as $g): $z = $nachGruppe[(int)$g['id']] ?? null; ?>
              <td class="small"><?= $z && $z['station'] ? h($z['station']['name']) : '–' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small muted"><i class="ti ti-info-circle"></i> In der Einteilungs-Mail fügt der Platzhalter <code>{{ROTATION}}</code> jeder Gruppe ihren eigenen Fahrplan ein.</p>
  <?php endif; ?>
<?php endif; ?>

<div class="section-title" id="nachricht"><i class="ti ti-mail-forward"></i> Nachricht schreiben</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Geht über dieselbe Warteschlange wie die Bestätigungen. Reicht das Kontingent gerade nicht, holt der Cron den Rest nach – niemand geht verloren.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field-row">
      <div><label for="wen">An wen?</label>
        <select name="wen" id="wen">
          <?php foreach (extern_recipient_groups() as $k => $lbl):
                  if ($k === 'group' && !$gruppen) continue;
                  if ($k === 'assigned' && (string)$ev['group_mode'] === 'none') continue;
                  $n = $k === 'group' ? 0 : count(extern_recipients($id, $k)); ?>
            <option value="<?= h($k) ?>"><?= h($lbl) ?><?= $k !== 'group' ? ' (' . $n . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($gruppen): ?>
        <div><label for="group_id">… falls „eine bestimmte Gruppe"</label>
          <select name="group_id" id="group_id">
            <?php foreach ($gruppen as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
    </div>
    <label for="subject" style="margin-top:.6rem">Betreff</label>
    <input type="text" name="subject" id="subject" required value="<?= h(extern_text('assign_subject')) ?>">
    <label for="body">Text</label>
    <textarea name="body" id="body" rows="10" required><?= h(extern_text('assign_body')) ?></textarea>
    <p class="small muted" style="margin:.2rem 0 0">Platzhalter: <code>{{NAME}}</code>, <code>{{TITEL}}</code>, <code>{{TERMIN_SATZ}}</code>,
      <code>{{GRUPPE}}</code>, <code>{{TREFFPUNKT}}</code>, <code>{{GRUPPENZEIT}}</code>, <code>{{GRUPPE_SATZ}}</code> (Gruppe, Treffpunkt und Zeit am Stück), <code>{{LINK}}</code>.</p>
    <p class="small warn-line" style="margin:.4rem 0 0"><i class="ti ti-info-circle"></i> Steht <code>{{LINK}}</code> im Text, bekommt jede Person einen <strong>frischen</strong> Selbstbedienungs-Link – ältere Links aus früheren Mails gelten dann nicht mehr.</p>
    <div class="btn-row" style="margin-top:.9rem">
      <button class="btn" type="submit" data-puste="zusage"><i class="ti ti-send"></i> Nachricht verschicken</button>
    </div>
  </form>
</div>

<div class="section-title" id="anmeldungen"><i class="ti ti-list-details"></i> Anmeldungen <span class="count"><?= (int)$zahlen['anmeldungen'] + (int)$zahlen['warteliste'] ?></span></div>
<?php $alle = extern_signups_of($id); if (!$alle): ?>
  <div class="card"><p class="empty"><i class="ti ti-user-off"></i> Noch niemand angemeldet.</p></div>
<?php else: ?>
  <div class="card" style="padding:.4rem .2rem">
    <table class="list stack-sm">
      <thead><tr><th>Name</th><th>Status</th><th>Angaben</th><th>Gruppe</th></tr></thead>
      <tbody>
      <?php foreach ($alle as $s): $an = extern_answers_of((int)$s['id']);
            $txt = [];
            foreach ($felder as $f) {
              $teile = [];
              foreach ($an as $a) {
                if ((int)$a['field_id'] !== (int)$f['id']) continue;
                if ($a['option_id'] !== null) {
                  foreach ($f['options'] as $o) if ((int)$o['id'] === (int)$a['option_id']) $teile[] = (string)$o['label'];
                } elseif ((string)$a['value_text'] !== '') { $teile[] = (string)$a['value_text']; }
                elseif ($a['value_num'] !== null) { $teile[] = (string)(int)$a['value_num']; }
              }
              if ($teile) $txt[] = $f['label'] . ': ' . implode(', ', $teile);
            } ?>
        <tr>
          <td><?= h($s['name']) ?><?php if ((int)$s['party_size'] > 1): ?> <span class="muted small">(<?= (int)$s['party_size'] ?> Personen<?= trim((string)$s['party_names']) !== '' ? ': ' . h($s['party_names']) : '' ?>)</span><?php endif; ?>
            <br><span class="muted small"><a href="mailto:<?= h($s['email']) ?>"><?= h($s['email']) ?></a></span></td>
          <td>
            <?php $stt = (string)$s['status']; ?>
            <?php if ($stt === 'confirmed'): ?><span class="pill pill-ok">dabei</span>
            <?php elseif ($stt === 'waitlist'): ?><span class="pill pill-warn">Warteliste</span>
            <?php elseif ($stt === 'pending'): ?><span class="pill pill-info">unbestätigt</span>
            <?php else: ?><span class="pill pill-bad">abgemeldet</span><?php endif; ?>
          </td>
          <td class="muted small"><?= $txt ? h(implode(' · ', $txt)) : '–' ?></td>
          <td class="muted small"><?php $gz = (int)$s['group_id'] ? extern_group_get((int)$s['group_id']) : null; echo $gz ? h($gz['name']) : '–'; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php
page_footer();
