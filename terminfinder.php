<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;

$id = (int)($_GET['id'] ?? 0);
$poll = date_poll_get($id);
if (!$poll) { http_response_code(404); page_header('Nicht gefunden'); echo '<p>Terminabstimmung nicht gefunden.</p>'; page_footer(); exit; }
$canManage = date_poll_can_manage($poll, $me);
$isAdmin = $me && !empty($me['is_admin']);
$closed = !empty($poll['closed']);
$votingOpen = date_poll_voting_open($poll);
$deadlinePassed = date_poll_deadline_passed($poll);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'vote' && $me) {
        if (!$votingOpen) { flash($deadlinePassed ? 'Die Frist zum Abstimmen ist abgelaufen.' : 'Diese Abstimmung ist geschlossen.', 'error'); redirect('terminfinder.php?id=' . $id); }
        $votes = [];
        foreach ((array)($_POST['v'] ?? []) as $oid => $v) $votes[(int)$oid] = (string)$v;
        date_poll_save_votes($id, $meId, $votes);
        flash('Danke – deine Verfügbarkeit ist gespeichert.', 'success');
        redirect('terminfinder.php?id=' . $id . '#ergebnis');
    }

    // Nur Admin: Stimme für eine andere Person eintragen (Übertragung aus alten Tools) – auch nach Fristablauf.
    if ($action === 'vote_for' && $isAdmin) {
        $target = (int)($_POST['member_id'] ?? 0);
        if ($target <= 0 || !member_get($target)) { flash('Bitte eine gültige Person wählen.', 'error'); redirect('terminfinder.php?id=' . $id . '#adminvote'); }
        $votes = [];
        foreach ((array)($_POST['av'] ?? []) as $oid => $v) $votes[(int)$oid] = (string)$v;
        date_poll_save_votes($id, $target, $votes);
        flash('Stimme für ' . short_name((string)(member_get($target)['name'] ?? '')) . ' gespeichert.', 'success');
        redirect('terminfinder.php?id=' . $id . '&voter=' . $target . '#adminvote');
    }

    if ($action === 'add_option' && $canManage) {
        $s = norm_dtl((string)($_POST['starts_at'] ?? ''));
        if ($s) { date_poll_add_option($id, $s); flash('Datumsvorschlag hinzugefügt.', 'success'); }
        else flash('Bitte Datum/Uhrzeit angeben.', 'error');
        redirect('terminfinder.php?id=' . $id);
    }

    if ($action === 'remove_option' && $canManage) {
        $oid = (int)($_POST['option_id'] ?? 0);
        // nur eigene Optionen dieser Abstimmung entfernen
        $chk = db()->prepare('SELECT 1 FROM date_poll_options WHERE id = ? AND poll_id = ?');
        $chk->execute([$oid, $id]);
        if ($chk->fetchColumn()) { date_poll_remove_option($oid); flash('Datumsvorschlag entfernt.', 'success'); }
        redirect('terminfinder.php?id=' . $id);
    }

    if ($action === 'toggle_closed' && $canManage) {
        date_poll_set_closed($id, !$closed);
        flash($closed ? 'Abstimmung wieder geöffnet.' : 'Abstimmung geschlossen – das Ergebnis bleibt sichtbar.', 'success');
        redirect('terminfinder.php?id=' . $id);
    }

    if ($action === 'delete' && $canManage) {
        date_poll_delete($id, $me);
        flash('Terminabstimmung gelöscht.', 'success');
        redirect('index.php#terminabstimmungen');
    }
}

$options = date_poll_options($id);
$results = date_poll_results($id);
$myVotes = $meId ? date_poll_member_votes($id, $meId) : [];
$iVoted  = $meId ? date_poll_has_voted($id, $meId) : false;
$voterIds = date_poll_voter_ids($id);
$voterN = count($voterIds);
$activeN = active_member_count();
$creator = member_get((int)$poll['created_by']);

// Namen je Option/Stimme für die Detailanzeige
$voteNames = []; // option_id => ['yes'=>[names], 'maybe'=>[], 'no'=>[]]
if ($options) {
    $vn = db()->prepare(
        'SELECT v.option_id, v.vote, m.id AS mid, m.name FROM date_poll_votes v
         JOIN date_poll_options o ON o.id = v.option_id
         JOIN members m ON m.id = v.member_id
         WHERE o.poll_id = ? ORDER BY m.name COLLATE NOCASE'
    );
    $vn->execute([$id]);
    // Namen als Profil-Links (member_link kürzt selbst auf den Kurznamen)
    foreach ($vn->fetchAll() as $r) $voteNames[(int)$r['option_id']][(string)$r['vote']][] = member_link(['id' => (int)$r['mid'], 'name' => (string)$r['name']]);
}
$topYes = $results ? (int)$results[0]['yes'] : 0; // führende „Ja"-Zahl (für Sieger-Markierung)

// Admin-Fremdabstimmung: vorausgewählte Person + deren bisherige Stimmen zum Vorbefüllen
$adminVoterId = $isAdmin ? (int)($_GET['voter'] ?? 0) : 0;
$adminVoter = $adminVoterId ? member_get($adminVoterId) : null;
$adminVotes = $adminVoter ? date_poll_member_votes($id, $adminVoterId) : [];

page_header('Terminfinder – ' . $poll['title']);
?>
<p class="small"><a href="index.php#terminabstimmungen">‹ Kalender / Terminabstimmungen</a></p>

<div class="card event-hero">
  <div class="hero-head">
    <h1 class="event-title">
      <i class="ti ti-calendar-search" style="color:var(--petrol)"></i> <?= h($poll['title']) ?>
      <?php if ($closed): ?><span class="badge badge-closed">geschlossen</span>
      <?php elseif ($deadlinePassed): ?><span class="badge badge-closed">Frist abgelaufen</span>
      <?php else: ?><span class="badge badge-event">läuft</span><?php endif; ?>
    </h1>
    <?= share_button('terminfinder.php?id=' . $id, 'Abstimmungs-Link teilen') ?>
  </div>
  <div class="event-facts">
    <?php if (trim((string)$poll['location']) !== ''): ?><span class="fact"><i class="ti ti-map-pin"></i> <?= h($poll['location']) ?></span><?php endif; ?>
    <span class="fact"><i class="ti ti-users"></i> <?= $voterN ?> von <?= max($voterN, $activeN) ?> haben abgestimmt</span>
    <?php if (trim((string)($poll['deadline'] ?? '')) !== ''): ?><span class="fact<?= $deadlinePassed ? '' : ' fact-accent' ?>"><i class="ti ti-clock"></i> Abstimmen bis <?= h(fmt_slot((string)$poll['deadline'], null)) ?></span><?php endif; ?>
    <?php if ($creator): ?><span class="fact"><i class="ti ti-user"></i> angelegt von <?= member_link($creator) ?></span><?php endif; ?>
  </div>
  <?php if (trim((string)$poll['description']) !== ''): ?><p class="event-desc"><?= h($poll['description']) ?></p><?php endif; ?>
  <p class="small muted" style="margin:.4rem 0 0"><i class="ti ti-info-circle"></i> Der Terminfinder sammelt nur die Verfügbarkeiten und zeigt das Ergebnis – er legt <strong>keine</strong> Sitzung an. Die Entscheidung trefft ihr selbst.</p>
</div>

<?php if ($me && $votingOpen && !$iVoted): ?>
  <div class="attention" style="margin-bottom:1rem"><div class="section-title" style="margin-top:0"><i class="ti ti-alert-triangle"></i> Bitte abstimmen</div>
  <p class="note small" style="margin:0">Diese Abstimmung ist <strong>für alle verpflichtend</strong> – trag unten kurz ein, wann du kannst<?= trim((string)($poll['deadline'] ?? '')) !== '' ? ' (bis ' . h(fmt_slot((string)$poll['deadline'], null)) . ')' : '' ?>. Du kannst deine Antwort bis dahin jederzeit ändern.</p></div>
<?php endif; ?>

<div class="section-title" id="ergebnis"><i class="ti ti-chart-bar"></i> Ergebnis <span class="muted small" style="font-weight:400">– nach Beliebtheit sortiert</span></div>
<div class="card">
  <?php if (!$results): ?>
    <p class="empty"><i class="ti ti-calendar-off"></i> Noch keine Datumsvorschläge.</p>
  <?php else: ?>
    <ul class="poll-results">
      <?php foreach ($results as $i => $r): $o = $r['option']; $oid = (int)$o['id'];
          $tot = max(1, $r['yes'] + $r['maybe'] + $r['no']);
          $isLead = $r['yes'] === $topYes && $topYes > 0;
          $yesNames = $voteNames[$oid]['yes'] ?? []; $maybeNames = $voteNames[$oid]['maybe'] ?? [];
      ?>
        <li class="poll-opt<?= $isLead ? ' poll-lead' : '' ?>">
          <div class="poll-opt-head">
            <span class="poll-date"><?php if ($isLead): ?><i class="ti ti-crown" title="Aktuell die meisten Zusagen"></i> <?php endif; ?><?= h(date_poll_option_label($o)) ?></span>
            <span class="poll-counts">
              <span class="pc pc-yes" title="Ja"><i class="ti ti-check"></i> <?= (int)$r['yes'] ?></span>
              <span class="pc pc-maybe" title="Vielleicht"><i class="ti ti-help"></i> <?= (int)$r['maybe'] ?></span>
              <span class="pc pc-no" title="Nein"><i class="ti ti-x"></i> <?= (int)$r['no'] ?></span>
            </span>
          </div>
          <div class="poll-bar" aria-hidden="true">
            <span class="pb-yes" style="width:<?= round($r['yes'] / $tot * 100) ?>%"></span>
            <span class="pb-maybe" style="width:<?= round($r['maybe'] / $tot * 100) ?>%"></span>
            <span class="pb-no" style="width:<?= round($r['no'] / $tot * 100) ?>%"></span>
          </div>
          <?php if ($yesNames || $maybeNames): ?>
            <div class="poll-who small muted">
              <?php if ($yesNames): ?><span><i class="ti ti-check" style="color:var(--green)"></i> <?= implode(', ', $yesNames) ?></span><?php endif; ?>
              <?php if ($maybeNames): ?><span style="margin-left:.6rem"><i class="ti ti-help" style="color:#b8860b"></i> <?= implode(', ', $maybeNames) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <form method="post" class="poll-optdel" data-confirm="Diesen Datumsvorschlag samt Stimmen entfernen?" data-confirm-danger data-confirm-ok="Entfernen">
              <?= csrf_field() ?><input type="hidden" name="action" value="remove_option"><input type="hidden" name="option_id" value="<?= $oid ?>">
              <button class="btn danger small" type="submit" title="Vorschlag entfernen" aria-label="Vorschlag entfernen"><i class="ti ti-trash"></i></button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<?php if ($me && $votingOpen && $options): ?>
  <div class="section-title" id="abstimmen"><i class="ti ti-checkbox"></i> Wann kannst du?</div>
  <div class="card">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="vote">
      <table class="list poll-vote">
        <tbody>
        <?php foreach ($options as $o): $oid = (int)$o['id']; $cur = $myVotes[$oid] ?? ''; ?>
          <tr>
            <td><?= h(date_poll_option_label($o)) ?></td>
            <td style="text-align:right">
              <span class="statuspick">
                <label><input type="radio" name="v[<?= $oid ?>]" value="yes" <?= $cur==='yes'?'checked':'' ?>><span class="s-yes">Ja</span></label>
                <label><input type="radio" name="v[<?= $oid ?>]" value="maybe" <?= $cur==='maybe'?'checked':'' ?>><span class="s-maybe">Evtl.</span></label>
                <label><input type="radio" name="v[<?= $oid ?>]" value="no" <?= ($cur==='no'||$cur==='')?'checked':'' ?>><span class="s-no">Nein</span></label>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit" data-puste="stimme"><i class="ti ti-device-floppy"></i> <?= $iVoted ? 'Verfügbarkeit aktualisieren' : 'Verfügbarkeit speichern' ?></button></div>
    </form>
  </div>
<?php elseif ($me && $options && !$votingOpen): ?>
  <p class="small muted"><i class="ti ti-lock"></i> <?= $deadlinePassed ? 'Die Frist zum Abstimmen ist abgelaufen.' : 'Die Abstimmung ist geschlossen.' ?> Oben siehst du das Endergebnis.<?= $isAdmin ? ' Als Admin kannst du unten weiterhin Stimmen übertragen.' : '' ?></p>
<?php endif; ?>

<?php if ($isAdmin && $options): ?>
  <div class="section-title" id="adminvote"><i class="ti ti-user-plus"></i> Für jemanden abstimmen <span class="muted small" style="font-weight:400">– Übertragung aus altem Tool (nur Admin)</span></div>
  <div class="card">
    <p class="small muted" style="margin-top:0">Trag hier die Verfügbarkeit einer anderen Person ein (z. B. aus einem alten Umfrage-Tool). Das geht auch nach Ablauf der Frist. Bereits vorhandene Stimmen der Person werden beim Auswählen vorbefüllt.</p>
    <form method="get" style="margin-bottom:.8rem">
      <input type="hidden" name="id" value="<?= $id ?>">
      <label for="voter">Person</label>
      <select name="voter" id="voter" onchange="this.form.submit()">
        <option value="">— Person wählen —</option>
        <?php foreach (members_all(true) as $mm): ?>
          <option value="<?= (int)$mm['id'] ?>" <?= $adminVoterId === (int)$mm['id'] ? 'selected' : '' ?>><?= h($mm['name']) ?><?= empty($mm['active']) ? ' (inaktiv)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($adminVoter): ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="vote_for"><input type="hidden" name="member_id" value="<?= $adminVoterId ?>">
        <p class="small" style="margin:.2rem 0 .6rem"><i class="ti ti-user"></i> Stimme für <strong><?= h($adminVoter['name']) ?></strong><?= date_poll_has_voted($id, $adminVoterId) ? ' <span class="pill pill-ok">hat bereits abgestimmt</span>' : '' ?></p>
        <table class="list poll-vote"><tbody>
        <?php foreach ($options as $o): $oid = (int)$o['id']; $cur = $adminVotes[$oid] ?? ''; ?>
          <tr>
            <td><?= h(date_poll_option_label($o)) ?></td>
            <td style="text-align:right">
              <span class="statuspick">
                <label><input type="radio" name="av[<?= $oid ?>]" value="yes" <?= $cur==='yes'?'checked':'' ?>><span class="s-yes">Ja</span></label>
                <label><input type="radio" name="av[<?= $oid ?>]" value="maybe" <?= $cur==='maybe'?'checked':'' ?>><span class="s-maybe">Evtl.</span></label>
                <label><input type="radio" name="av[<?= $oid ?>]" value="no" <?= ($cur==='no'||$cur==='')?'checked':'' ?>><span class="s-no">Nein</span></label>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
        <div class="btn-row" style="margin-top:.8rem"><button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Stimme für <?= h(short_name($adminVoter['name'])) ?> speichern</button></div>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($canManage): ?>
  <details class="collapse-card">
  <summary class="section-title"><i class="ti ti-settings"></i> Abstimmung verwalten<i class="ti ti-chevron-down collapse-chev"></i></summary>
  <div class="card">
    <div class="section-title" style="margin-top:0;font-size:1.05rem"><i class="ti ti-calendar-plus"></i> Datumsvorschlag hinzufügen</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_option">
      <div class="btn-row" style="align-items:flex-end;gap:.6rem;flex-wrap:wrap">
        <div style="flex:1;min-width:200px"><label for="new_opt">Datum &amp; Uhrzeit</label><input type="text" class="fp-datetime" name="starts_at" id="new_opt" placeholder="Datum &amp; Uhrzeit wählen"></div>
        <button class="btn secondary" type="submit"><i class="ti ti-plus"></i> Hinzufügen</button>
      </div>
    </form>
    <div class="btn-row" style="margin-top:1rem;border-top:1px solid var(--line);padding-top:.9rem;flex-wrap:wrap">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_closed"><button class="btn secondary" type="submit"><i class="ti ti-<?= $closed ? 'lock-open' : 'lock' ?>"></i> <?= $closed ? 'Wieder öffnen' : 'Abstimmung schließen' ?></button></form>
      <form method="post" data-confirm="Diese Terminabstimmung mit allen Stimmen löschen?" data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger" type="submit"><i class="ti ti-trash"></i> Abstimmung löschen</button></form>
    </div>
  </div>
  </details>
<?php endif; ?>
<?php
page_footer();
