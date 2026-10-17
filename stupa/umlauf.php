<?php
/**
 * Umlaufverfahren des StuPa – die Seite des Präsidiums: Mitgliederliste pflegen, Umläufe
 * starten, verfolgen und auswerten. Daten und Logik in stupa-umlauf-db.php (eigene Datei,
 * eigene Datenbank – in der App des AStA gibt es keine Seite dafür). Abgestimmt wird über
 * den persönlichen Link auf stupa/abstimmen.php.
 */
require __DIR__ . '/stupa-lib.php';
require_once __DIR__ . '/../stupa-umlauf-db.php';
$user = stupa_require_login();
// Absender und Mail-Rahmen ins gemeinsame Mail-Konto spiegeln: stupa-umlauf-db.php verschickt ohne
// lib.php und liest beides von dort. Auf einer frischen Installation stünde sonst noch nichts drin.
mail_from();
mail_rahmen_app();
su_maintain();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $ziel = 'umlauf.php';
    switch ($a) {
        case 'member_add':
            $e = su_member_save(0, (string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''));
            flash($e === '' ? 'Mitglied eingetragen.' : $e, $e === '' ? 'success' : 'error');
            $ziel .= '#mitglieder';
            break;
        case 'member_import':
            [$n, $fehler] = su_members_import((string)($_POST['liste'] ?? ''));
            flash($n . ' Mitglied' . ($n === 1 ? '' : 'er') . ' eingetragen.' . ($fehler ? ' Nicht übernommen: ' . implode('; ', array_slice($fehler, 0, 5)) . (count($fehler) > 5 ? ' …' : '') : ''),
                $fehler && !$n ? 'error' : 'success');
            $ziel .= '#mitglieder';
            break;
        case 'member_save':
            $e = su_member_save($id, (string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''), !empty($_POST['active']));
            flash($e === '' ? 'Gespeichert.' : $e, $e === '' ? 'success' : 'error');
            $ziel .= '#mitglieder';
            break;
        case 'member_delete':
            su_member_delete($id);
            flash('Von der Liste genommen. Laufende Umläufe behalten die Person als stimmberechtigt.', 'success');
            $ziel .= '#mitglieder';
            break;
        case 'vote_create':
            $frist = str_replace('T', ' ', trim((string)($_POST['deadline'] ?? '')));
            $r = su_vote_create([
                'title' => $_POST['title'] ?? '', 'description' => $_POST['description'] ?? '', 'deadline' => substr($frist, 0, 16),
                'secret' => ($_POST['visibility'] ?? '') === 'secret', 'rule' => $_POST['rule'] ?? 'simple',
                'quorum_pct' => (int)($_POST['quorum_pct'] ?? 0), 'result_mail' => ($_POST['result'] ?? '') === 'alle',
                'remind_auto' => ($_POST['remind'] ?? '') === 'auto',
            ], (int)$user['id'], app_url('stupa/'), (string)$user['email']);
            if ($r['error'] !== '') { flash($r['error'], 'error'); $ziel .= '#neu'; break; }
            flash('Umlauf gestartet – ' . $r['mails'] . ' Mail' . ($r['mails'] === 1 ? '' : 's') . ' mit persönlichem Link verschickt.', 'success');
            $ziel .= '?id=' . $r['id'];
            break;
        case 'vote_remind':
            $n = su_send_links($id, 'erinnerung');
            flash($n . ' Erinnerung' . ($n === 1 ? '' : 'en') . ' verschickt.', 'success');
            $ziel .= '?id=' . $id;
            break;
        case 'vote_close':
            flash(su_close($id) ? 'Ausgewertet.' : 'Auswerten nicht möglich – der Umlauf ist nicht mehr offen.', 'success');
            $ziel .= '?id=' . $id;
            break;
        case 'vote_withdraw':
            flash(su_withdraw($id) ? 'Zurückgezogen – es gibt kein Ergebnis.' : 'Zurückziehen nicht möglich.', 'success');
            $ziel .= '?id=' . $id;
            break;
        case 'vote_delete':
            su_vote_delete($id);
            flash('Umlauf gelöscht.', 'success');
            break;
    }
    header('Location: ' . $ziel);
    exit;
}

$rules = su_rules();
$detail = isset($_GET['id']) ? su_vote_get((int)$_GET['id']) : null;
stupa_header('Umlaufverfahren', $user);

// ---------------------------------------------------------------- Detail
if ($detail):
    $v = $detail; $vid = (int)$v['id'];
    $offen = su_vote_open($v);
    $geheim = !empty($v['secret']);
    $t = su_tally($v);
    $tokens = su_tokens($vid);
    $dabei = count(array_filter($tokens, static fn ($x) => $x['voted_at'] !== null));
    $status = (string)$v['status'];
?>
  <p class="small"><a href="umlauf.php">‹ Alle Umläufe</a></p>
  <div class="card">
    <h1 style="margin:0 0 .5rem;font-size:1.35rem"><i class="ti ti-mailbox" style="color:var(--petrol)"></i> <?= h((string)$v['title']) ?>
      <?php if ($status === 'withdrawn'): ?><span class="pill">zurückgezogen</span>
      <?php elseif ($status === 'closed'): ?><span class="pill <?= $v['result'] === 'accepted' ? 'pill-ok' : ($v['result'] === 'rejected' ? 'pill-bad' : 'pill-warn') ?>"><?= h(su_result_label($v['result'])) ?></span>
      <?php else: ?><span class="pill pill-ok">läuft</span><?php endif; ?></h1>
    <p class="small muted" style="margin:0 0 .5rem">
      <i class="ti ti-clock"></i> bis <?= h(su_fmt((string)$v['deadline'])) ?> ·
      <i class="ti ti-users"></i> <?= $dabei ?> von <?= (int)$v['eligible_count'] ?> abgestimmt ·
      <i class="ti ti-scale"></i> <?= h($rules[(string)$v['rule']][0] ?? '') ?><?= (int)$v['quorum_pct'] > 0 ? ', mind. ' . (int)$v['quorum_pct'] . ' % Beteiligung' : '' ?> ·
      <i class="ti ti-<?= $geheim ? 'eye-off' : 'eye' ?>"></i> <?= $geheim ? 'geheim' : 'namentlich' ?> ·
      Ergebnis <?= !empty($v['result_mail']) ? 'per Mail an alle' : 'nur fürs Präsidium' ?> ·
      Erinnerung <?= !empty($v['remind_auto']) ? 'automatisch 24 h vorher' : 'nur von Hand' ?></p>
    <?php if (trim((string)$v['description']) !== ''): ?><p style="white-space:pre-line;margin:0"><?= h((string)$v['description']) ?></p><?php endif; ?>
  </div>

  <div class="section-title"><i class="ti ti-chart-bar"></i> <?= $status === 'closed' ? 'Ergebnis' : 'Zwischenstand' ?></div>
  <div class="card">
    <?php if ($status === 'withdrawn'): ?>
      <p class="empty">Dieser Umlauf wurde zurückgezogen – es gibt kein Ergebnis.</p>
    <?php elseif ($offen && $geheim): ?>
      <p class="small" style="margin:0"><i class="ti ti-eye-off"></i> Geheim: Der Zwischenstand bleibt bis zum Ende verborgen. Abgestimmt haben bisher <strong><?= $dabei ?> von <?= (int)$v['eligible_count'] ?></strong>.</p>
    <?php else: $tot = max(1, $t['total']); ?>
      <div class="poll-bar" aria-hidden="true" style="margin-bottom:.6rem">
        <span class="pb-yes" style="width:<?= round($t['yes'] / $tot * 100) ?>%"></span>
        <span class="pb-maybe" style="width:<?= round($t['abstain'] / $tot * 100) ?>%"></span>
        <span class="pb-no" style="width:<?= round($t['no'] / $tot * 100) ?>%"></span>
      </div>
      <p class="small" style="margin:0">Ja: <strong><?= $t['yes'] ?></strong> · Nein: <strong><?= $t['no'] ?></strong> · Enthaltung: <strong><?= $t['abstain'] ?></strong>
        <span class="muted">· Beteiligung <?= $t['total'] ?> von <?= (int)$v['eligible_count'] ?></span></p>
    <?php endif; ?>
  </div>

  <div class="section-title"><i class="ti ti-users"></i> Stimmberechtigte <span class="count"><?= count($tokens) ?></span></div>
  <div class="card">
    <table class="list">
      <thead><tr><th>Name</th><th>Mail</th><th><?= $geheim ? 'Abgestimmt' : 'Stimme' ?></th></tr></thead>
      <tbody>
      <?php foreach ($tokens as $tk): ?>
        <tr><td><?= h((string)$tk['name']) ?></td><td class="small muted"><?= h((string)$tk['email']) ?></td>
          <td class="small"><?php if ($tk['voted_at'] === null): ?><span class="muted">noch nicht</span>
            <?php elseif ($geheim): ?><i class="ti ti-check" style="color:var(--green)"></i> ja
            <?php else: ?><strong><?= h(su_choice_label((string)$tk['choice'])) ?></strong><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($geheim): ?><p class="small muted" style="margin:.5rem 0 0">Geheim: Hier steht nur, <em>wer</em> abgestimmt hat – wie, weiß niemand.</p><?php endif; ?>
  </div>

  <div class="section-title"><i class="ti ti-settings"></i> Verwalten</div>
  <div class="card"><div class="btn-row" style="flex-wrap:wrap">
    <?php if ($offen && $dabei < count($tokens)): ?>
      <form method="post" data-confirm="Allen, die noch nicht abgestimmt haben (<?= count($tokens) - $dabei ?>), ihren Link noch einmal schicken?" data-confirm-ok="Erinnern">
        <?= csrf_field() ?><input type="hidden" name="action" value="vote_remind"><input type="hidden" name="id" value="<?= $vid ?>">
        <button class="btn secondary" type="submit"><i class="ti ti-bell-ringing"></i> Säumige erinnern</button></form>
    <?php endif; ?>
    <?php if ($status === 'open'): ?>
      <form method="post" data-confirm="Jetzt mit den bisherigen Stimmen auswerten?<?= !empty($v['result_mail']) ? ' Das Ergebnis geht per Mail an alle.' : '' ?>" data-confirm-ok="Auswerten">
        <?= csrf_field() ?><input type="hidden" name="action" value="vote_close"><input type="hidden" name="id" value="<?= $vid ?>">
        <button class="btn secondary" type="submit"><i class="ti ti-flag-check"></i> Jetzt auswerten</button></form>
      <form method="post" data-confirm="Diesen Umlauf zurückziehen? Es wird kein Ergebnis ermittelt." data-confirm-danger data-confirm-ok="Zurückziehen">
        <?= csrf_field() ?><input type="hidden" name="action" value="vote_withdraw"><input type="hidden" name="id" value="<?= $vid ?>">
        <button class="btn secondary" type="submit"><i class="ti ti-arrow-back-up"></i> Zurückziehen</button></form>
    <?php endif; ?>
    <form method="post" data-confirm="Diesen Umlauf samt aller Stimmen endgültig löschen?" data-confirm-danger data-confirm-ok="Löschen">
      <?= csrf_field() ?><input type="hidden" name="action" value="vote_delete"><input type="hidden" name="id" value="<?= $vid ?>">
      <button class="btn danger" type="submit"><i class="ti ti-trash"></i> Löschen</button></form>
  </div></div>
<?php
    stupa_footer();
    exit;
endif;

// ---------------------------------------------------------------- Übersicht
$alle = su_votes_all();
$mitglieder = su_members();
$aktiv = count(array_filter($mitglieder, static fn ($m) => !empty($m['active'])));
?>
<div class="section-title" style="margin-top:0"><i class="ti ti-mailbox"></i> Umlaufverfahren</div>
<p class="small muted" style="margin:-.3rem 0 .8rem">Beschlüsse außerhalb der Sitzung. Jedes Mitglied auf eurer Liste bekommt einen persönlichen Link per Mail und stimmt ohne Anmeldung ab. Die Umläufe sieht nur das Präsidium – der AStA nicht.</p>

<div class="card">
  <?php if (!$alle): ?>
    <p class="empty" style="margin:0"><i class="ti ti-mailbox-off"></i> Noch kein Umlauf. Unten startest du den ersten.</p>
  <?php else: ?>
    <table class="list">
      <thead><tr><th>Umlauf</th><th>Frist</th><th>Beteiligung</th><th style="text-align:right">Stand</th></tr></thead>
      <tbody>
      <?php foreach ($alle as $u):
          $dab = (int)su_db()->query('SELECT COUNT(*) FROM su_tokens WHERE vote_id = ' . (int)$u['id'] . ' AND voted_at IS NOT NULL')->fetchColumn(); ?>
        <tr>
          <td><a href="umlauf.php?id=<?= (int)$u['id'] ?>"><strong><?= h((string)$u['title']) ?></strong></a></td>
          <td class="small muted" style="white-space:nowrap"><?= h(su_fmt((string)$u['deadline'])) ?></td>
          <td class="small"><?= $dab ?> / <?= (int)$u['eligible_count'] ?></td>
          <td style="text-align:right">
            <?php if ((string)$u['status'] === 'open'): ?><span class="pill pill-ok">läuft</span>
            <?php elseif ((string)$u['status'] === 'withdrawn'): ?><span class="pill">zurückgezogen</span>
            <?php else: ?><span class="pill <?= $u['result'] === 'accepted' ? 'pill-ok' : ($u['result'] === 'rejected' ? 'pill-bad' : 'pill-warn') ?>"><?= h(su_result_label($u['result'])) ?></span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="section-title" id="neu"><i class="ti ti-plus"></i> Neuer Umlauf</div>
<div class="card">
  <?php if (!$aktiv): ?>
    <p class="empty" style="margin:0">Trag zuerst unten eure Mitglieder ein – sie bekommen dann ihren Link.</p>
  <?php else: ?>
  <form method="post" class="su-form" data-confirm="Umlauf starten und <?= $aktiv ?> Mails mit persönlichem Link verschicken?" data-confirm-ok="Starten">
    <?= csrf_field() ?><input type="hidden" name="action" value="vote_create">
    <label>Titel / Beschlussvorschlag<input type="text" name="title" required maxlength="200" placeholder="z. B. Zustimmung zum Haushaltsnachtrag"></label>
    <label>Begründung / Details (optional)<textarea name="description" rows="3"></textarea></label>
    <label>Abstimmen bis<input type="datetime-local" name="deadline" required min="<?= h(date('Y-m-d\TH:i')) ?>"></label>
    <fieldset><legend>Stimmen</legend>
      <label class="su-wahl"><input type="radio" name="visibility" value="open" checked> namentlich <span class="muted small">– Stimme bis zum Ende änderbar</span></label>
      <label class="su-wahl"><input type="radio" name="visibility" value="secret"> geheim <span class="muted small">– niemand sieht, wer wie stimmt; Stimme nicht änderbar</span></label></fieldset>
    <fieldset><legend>Angenommen bei …</legend>
      <?php foreach ($rules as $rk => [$rl, $rd]): ?>
        <label class="su-wahl"><input type="radio" name="rule" value="<?= h($rk) ?>"<?= $rk === 'simple' ? ' checked' : '' ?>> <strong><?= h($rl) ?></strong> <span class="muted small">– <?= h($rd) ?></span></label>
      <?php endforeach; ?></fieldset>
    <label>Mindestbeteiligung<select name="quorum_pct">
      <option value="0">keine – jedes Ergebnis zählt</option>
      <option value="50">mindestens die Hälfte (50 %)</option>
      <option value="67">mindestens zwei Drittel (67 %)</option>
      <option value="75">mindestens drei Viertel (75 %)</option></select></label>
    <fieldset><legend>Ergebnis</legend>
      <label class="su-wahl"><input type="radio" name="result" value="alle" checked> per Mail an alle Stimmberechtigten <span class="muted small">– und über ihren Link online</span></label>
      <label class="su-wahl"><input type="radio" name="result" value="praesidium"> nur fürs Präsidium <span class="muted small">– ihr gebt es selbst bekannt</span></label></fieldset>
    <fieldset><legend>Erinnerung</legend>
      <label class="su-wahl"><input type="radio" name="remind" value="auto" checked> automatisch 24 Stunden vor der Frist an alle, die noch fehlen</label>
      <label class="su-wahl"><input type="radio" name="remind" value="hand"> nur von Hand <span class="muted small">– über den Knopf beim Umlauf</span></label></fieldset>
    <p class="small muted" style="margin:0">Stimmberechtigt sind alle <strong><?= $aktiv ?></strong> aktiven Mitglieder der Liste. Jede:r bekommt sofort eine Mail mit dem persönlichen Link; Antworten darauf gehen an <?= h((string)$user['email']) ?>.</p>
    <div class="btn-row"><button class="btn" type="submit"><i class="ti ti-send"></i> Umlauf starten</button></div>
  </form>
  <?php endif; ?>
</div>

<div class="section-title" id="mitglieder"><i class="ti ti-address-book"></i> Mitgliederliste <span class="count"><?= $aktiv ?></span></div>
<div class="card">
  <?php if ($mitglieder): ?>
    <table class="list su-mitglieder">
      <thead><tr><th>Name</th><th>Mail</th><th>aktiv</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($mitglieder as $m): $fid = 'm' . (int)$m['id']; ?>
        <tr>
          <td><input form="<?= $fid ?>" type="text" name="name" value="<?= h((string)$m['name']) ?>" required maxlength="100" aria-label="Name"></td>
          <td><input form="<?= $fid ?>" type="email" name="email" value="<?= h((string)$m['email']) ?>" required aria-label="Mail"></td>
          <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1"<?= !empty($m['active']) ? ' checked' : '' ?> aria-label="aktiv"></td>
          <td class="su-aktionen">
            <form method="post" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="member_save"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn secondary small" type="submit" title="Speichern" aria-label="Speichern"><i class="ti ti-device-floppy"></i></button></form>
            <form method="post" data-confirm="<?= h((string)$m['name']) ?> von der Liste nehmen?" data-confirm-danger data-confirm-ok="Entfernen"><?= csrf_field() ?><input type="hidden" name="action" value="member_delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn danger small" type="submit" title="Entfernen" aria-label="Entfernen"><i class="ti ti-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small muted" style="margin:.4rem 0 .8rem">Nicht aktiv = bleibt auf der Liste, bekommt aber bei neuen Umläufen keinen Link (etwa bei Rücktritt oder Vertretung).</p>
  <?php endif; ?>
  <form method="post" class="su-form su-zeile">
    <?= csrf_field() ?><input type="hidden" name="action" value="member_add">
    <label>Name<input type="text" name="name" required maxlength="100"></label>
    <label>Mail<input type="email" name="email" required></label>
    <button class="btn small" type="submit"><i class="ti ti-user-plus"></i> Eintragen</button>
  </form>
  <details style="margin-top:.6rem"><summary class="small" style="cursor:pointer;color:var(--petrol)">Mehrere auf einmal eintragen</summary>
    <form method="post" class="su-form" style="margin-top:.5rem">
      <?= csrf_field() ?><input type="hidden" name="action" value="member_import">
      <label>Eine Person je Zeile, z. B. „Kim Muster, kim@beispiel.de"<textarea name="liste" rows="5" placeholder="Kim Muster, kim@beispiel.de&#10;Alex Beispiel &lt;alex@beispiel.de&gt;"></textarea></label>
      <div class="btn-row"><button class="btn secondary small" type="submit"><i class="ti ti-list-check"></i> Übernehmen</button></div>
    </form></details>
</div>
<?php
stupa_footer();
