<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;

// Offene Schicht direkt von der Events-Seite übernehmen (gleiche Logik wie auf der Event-Detailseite)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'gt_rsvp') {
        if ($me) gettogether_rsvp_set((int)($_POST['gt_id'] ?? 0), $meId, (string)($_POST['status'] ?? ''));
        redirect('events.php#gettogethers');
    }
    if (($_POST['action'] ?? '') === 'claim_slot') {
        if (!$me) {
            flash('Mit dem Technik-Login kannst du dich nicht eintragen – melde dich als Mitglied an.', 'error');
            redirect('events.php#einspringen');
        }
        $slot = event_slot_get((int)($_POST['slot_id'] ?? 0));
        $ev = $slot ? event_get((int)$slot['event_id']) : null;
        if ($ev) { $r = claim_open_slot($ev, (int)$slot['id'], $meId); flash($r['msg'], $r['ok'] ? 'success' : 'error'); }
        redirect('events.php#einspringen');
    }
}

$today = date('Y-m-d');
$soon  = date('Y-m-d', strtotime('+30 days'));

// Alle Events mit Kennzahlen + persönlicher Relevanz
$st = db()->prepare(
    "SELECT e.*,
        (SELECT COUNT(*) FROM event_slots s WHERE s.event_id = e.id) AS n_slots,
        (SELECT COUNT(*) FROM assignments a JOIN event_slots s ON s.id = a.slot_id WHERE s.event_id = e.id) AS n_assigned,
        (SELECT GROUP_CONCAT(DISTINCT NULLIF(s.location, '')) FROM event_slots s WHERE s.event_id = e.id) AS locations,
        EXISTS(SELECT 1 FROM responses r JOIN event_slots s ON s.id = r.slot_id WHERE s.event_id = e.id AND r.member_id = :me) AS responded,
        EXISTS(SELECT 1 FROM assignments a JOIN event_slots s ON s.id = a.slot_id WHERE s.event_id = e.id AND a.member_id = :me) AS mine,
        (SELECT COUNT(DISTINCT r.member_id) FROM responses r JOIN event_slots s ON s.id = r.slot_id
           JOIN members m ON m.id = r.member_id AND m.active = 1 WHERE s.event_id = e.id) AS n_voted
     FROM events e
     WHERE " . visible_drafts_sql('e.', true)
);
$st->execute([':me' => $meId]);
$all = $st->fetchAll();

// Gesamtzahl aktiver Mitglieder (Nenner für „Z/Y abgestimmt")
$memberTotal = (int)db()->query("SELECT COUNT(*) FROM members WHERE active = 1")->fetchColumn();

// In Buckets einsortieren – nach dem Event-Datum (mehrtägig berücksichtigt).
// „Brauchen deine Rückmeldung": Abstimmung läuft noch (Frist offen, Plan noch nicht veröffentlicht) – kommt ganz nach oben.
$past = $needsVote = $upcoming = $planned = [];
foreach ($all as $e) {
    $start = $e['starts_at'] ?: null;
    $end   = $e['ends_at'] ?: $start;
    $votingOpen = empty($e['draft']) && empty($e['closed']) && empty($e['plan_locked'])
        && (int)$e['n_slots'] > 0 && !deadline_passed($e['deadline'] ?? null);
    if ($end && $end < $today)            $past[] = $e;
    elseif ($votingOpen)                  $needsVote[] = $e;
    elseif ($start && $start <= $soon)    $upcoming[] = $e;
    else                                  $planned[] = $e;
}
// Sortierung: anstehend/geplant aufsteigend, vergangen absteigend
$byStartAsc  = fn($a, $b) => strcmp((string)($a['starts_at'] ?? ''), (string)($b['starts_at'] ?? ''));
$byStartDesc = fn($a, $b) => strcmp((string)($b['starts_at'] ?? ''), (string)($a['starts_at'] ?? ''));
// „Brauchen Rückmeldung": dringendste Frist zuerst (ohne Frist ans Ende), dann nach Start
usort($needsVote, function ($a, $b) use ($byStartAsc) {
    $da = trim((string)($a['deadline'] ?? '')) ?: '9999-12-31';
    $db = trim((string)($b['deadline'] ?? '')) ?: '9999-12-31';
    return strcmp($da, $db) ?: $byStartAsc($a, $b);
});
usort($upcoming, $byStartAsc);
usort($planned, $byStartAsc);
usort($past, $byStartDesc);

// Offene Schichten zum Einspringen (aggregiert über alle veröffentlichten Events).
// Eigene Schichten werden mit angezeigt („du bist dabei"), der Zähler nennt aber nur
// die Plätze, in die man selbst noch einspringen KANN.
$openGroups = member_open_slot_events($meId);
$openSlotTotal = array_sum(array_map(fn($g) => count(array_filter($g['slots'], fn($s) => empty($s['mine']))), $openGroups));

// AStA Get-Togethers (interne Spaß-Treffen) – nur künftige
$gtUpcoming = gettogethers_upcoming();
$gtRsvp = gettogether_rsvp_map($meId); // [gt_id => 'yes'|'no']

/** Eine Event-Kachel rendern */
$card = function (array $e) use ($today, $memberTotal): void {
    $range = fmt_event_range($e['starts_at'] ?? null, $e['ends_at'] ?? null);
    $locked = !empty($e['closed']) || deadline_passed($e['deadline'] ?? null);
    $hs = event_help_stats((int)$e['id']);
    $locs = array_filter(array_map('trim', explode(',', (string)($e['locations'] ?? ''))));
    $desc = trim((string)($e['description'] ?? ''));
    ?>
    <div class="event-card-wrap<?= !empty($e['important']) ? ' wrap-important' : '' ?>">
    <a class="event-card<?= $e['mine'] ? ' is-mine' : '' ?><?= !empty($e['important']) ? ' is-important' : '' ?>" href="event.php?id=<?= (int)$e['id'] ?>">
      <div class="ec-title"><?= h($e['title']) ?></div>
      <div class="ec-meta">
        <span><i class="ti ti-calendar-event"></i> <?= $range ? h($range) : '<span class="muted">noch kein Datum</span>' ?></span>
        <?php if ($locs): ?><span><i class="ti ti-map-pin"></i> <?= h(implode(' · ', $locs)) ?></span><?php endif; ?>
        <?php if ((int)$e['n_slots'] > 0): ?><span><i class="ti ti-clock-hour-4"></i> <?= (int)$e['n_slots'] ?> Termin<?= (int)$e['n_slots'] === 1 ? '' : 'e' ?></span><?php endif; ?>
      </div>
      <?php if ($desc !== ''): ?><p class="ec-desc"><?= h(mb_strimwidth($desc, 0, 150, '…')) ?></p><?php endif; ?>
      <?php if ($hs['needed'] > 0): $pct = min(100, (int)round($hs['got'] / $hs['needed'] * 100)); $bc = $hs['got'] >= $hs['needed'] ? '' : ($hs['got'] > 0 ? 'warn' : 'bad'); ?>
        <div class="progress-row">
          <div class="progress <?= $bc ?>"><span style="width:<?= $pct ?>%"></span></div>
          <span class="small muted"><?= $hs['got'] ?>/<?= $hs['needed'] ?> besetzte Schichten</span>
        </div>
      <?php endif; ?>
      <?php if ((int)$e['n_slots'] > 0 && $memberTotal > 0): $vp = min(100, (int)round((int)$e['n_voted'] / $memberTotal * 100)); ?>
        <div class="progress-row">
          <div class="progress <?= (int)$e['n_voted'] >= $memberTotal ? '' : 'warn' ?>"><span style="width:<?= $vp ?>%"></span></div>
          <span class="small muted"><?= (int)$e['n_voted'] ?>/<?= $memberTotal ?> abgestimmt</span>
        </div>
      <?php endif; ?>
      <div class="ec-pills">
        <?php if (!empty($e['draft'])): ?>
          <span class="pill pill-warn"><i class="ti ti-eye-off"></i> Entwurf</span>
        <?php endif; ?>
        <?php if (!empty($e['important'])): ?>
          <span class="pill pill-important"><i class="ti ti-alert-triangle-filled"></i> Extrem wichtig</span>
        <?php endif; ?>
        <?php if (!empty($e['closed'])): ?>
          <span class="pill"><i class="ti ti-lock"></i> abgeschlossen</span>
        <?php elseif (deadline_passed($e['deadline'] ?? null)): ?>
          <span class="pill pill-warn"><i class="ti ti-clock-stop"></i> Frist abgelaufen</span>
        <?php elseif (!empty($e['deadline'])): ?>
          <span class="pill pill-info"><i class="ti ti-hourglass"></i> Abstimmen bis <?= dt($e['deadline'])->format('d.m.') ?></span>
        <?php endif; ?>
        <?php if (!empty($e['plan_locked']) && (int)$e['n_assigned'] > 0): ?>
          <span class="pill pill-ok"><i class="ti ti-clipboard-check"></i> Plan steht</span>
        <?php endif; ?>
        <?php if ((int)$e['n_slots'] === 0): ?>
          <span class="pill"><i class="ti ti-pencil"></i> in Planung</span>
        <?php endif; ?>
        <?php if ($e['mine']): ?>
          <span class="pill pill-ok"><i class="ti ti-calendar-check"></i> Du hilfst mit</span>
        <?php elseif (!$e['responded'] && !$locked && (int)$e['n_slots'] > 0): ?>
          <span class="pill pill-warn"><i class="ti ti-pencil"></i> noch nicht abgestimmt</span>
        <?php endif; ?>
      </div>
    </a>
    <?= share_button('event.php?id=' . (int)$e['id'], 'Event-Link teilen') ?>
    </div>
    <?php
};

page_header('Events');
?>
<div class="events-toolbar">
  <h1><i class="ti ti-calendar-event" style="color:var(--petrol)"></i> Events</h1>
  <div class="btn-row">
    <a class="btn secondary" href="tauschboerse.php"><i class="ti ti-arrows-exchange"></i> Schichtbörse</a>
    <?php if (can_manage_gettogethers()): ?><a class="btn secondary" href="admin/gettogethers.php"><i class="ti ti-confetti"></i> Get-Togethers</a><?php endif; ?>
    <a class="btn" href="admin/events.php"><i class="ti ti-settings"></i> Events verwalten</a>
  </div>
</div>

<?php if (!$all): ?>
  <div class="card"><p class="empty"><i class="ti ti-info-circle"></i> Noch keine Events. Über „Events verwalten" das erste anlegen.</p></div>
<?php endif; ?>

<?php if ($needsVote): ?>
  <div class="section-title"><i class="ti ti-checkup-list"></i> Brauchen deine Rückmeldung <span class="count"><?= count($needsVote) ?></span></div>
  <div class="event-cards">
    <?php foreach ($needsVote as $e) $card($e); ?>
  </div>
<?php endif; ?>

<?php if ($openGroups): ?>
  <div class="section-title" id="einspringen"><i class="ti ti-hand-click"></i> Kurzfristig einspringen <span class="count"><?= $openSlotTotal ?></span></div>
  <p class="small muted" style="margin:-.4rem 0 .8rem">Schichten, die nach der Einteilung noch unterbesetzt sind. Du kannst direkt hier einspringen, solange du in der Zeit keinen anderen Einsatz hast. Schichten, in denen du schon drinsteckst, stehen mit „du bist dabei" dabei – sie suchen noch weitere Leute.</p>
  <?php foreach ($openGroups as $g): $e = $g['event']; $eid = (int)$e['id']; $range = fmt_event_range($e['starts_at'] ?? null, $e['ends_at'] ?? null); ?>
    <div class="card open-slots">
      <div class="es-head">
        <a class="es-title" href="event.php?id=<?= $eid ?>#offene-schichten"><?= h($e['title']) ?></a>
        <?php if ($range): ?><span class="muted small"><i class="ti ti-calendar-event"></i> <?= h($range) ?></span><?php endif; ?>
      </div>
      <ul class="boerse-list">
        <?php foreach ($g['slots'] as $s): $sid = (int)$s['id']; $conflict = empty($s['mine']) && member_time_conflict($meId, $s); ?>
          <li class="boerse-row">
            <span class="brow-when"><i class="ti ti-clock"></i> <?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?>
              <?php if ($s['label']): ?><span class="muted">(<?= h($s['label']) ?>)</span><?php endif; ?>
              <?php if (trim((string)$s['location']) !== ''): ?><span class="muted">· <?= h($s['location']) ?></span><?php endif; ?>
              <span class="pill pill-warn" style="font-size:.7rem;margin-left:.3rem">noch <?= (int)$s['remaining'] ?> gesucht</span>
            </span>
            <?php if (!empty($s['mine'])): ?>
              <span class="brow-tag live" title="Du bist in dieser Schicht eingeteilt – sie sucht noch weitere Leute"><i class="ti ti-check"></i> du bist dabei</span>
            <?php elseif ($conflict): ?>
              <span class="brow-tag muted" title="überschneidet sich mit einem deiner Einsätze"><i class="ti ti-alert-triangle"></i> Zeitkonflikt</span>
            <?php else: ?>
              <form method="post" class="brow-act">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="claim_slot">
                <input type="hidden" name="slot_id" value="<?= $sid ?>">
                <button class="btn small buy" type="submit"><i class="ti ti-hand-finger"></i> Eintragen</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($gtUpcoming): ?>
  <div class="section-title" id="gettogethers"><i class="ti ti-confetti" style="color:var(--violet)"></i> AStA Get-Togethers <span class="count"><?= count($gtUpcoming) ?></span></div>
  <p class="small muted" style="margin:-.4rem 0 .8rem">Interne Spaß-Treffen – komplett freiwillig. Sag kurz, ob du dabei bist; sagst du <strong>Nein</strong>, verschwindet das Treffen aus deinem Kalender.</p>
  <div class="event-cards">
    <?php foreach ($gtUpcoming as $g): $gid = (int)$g['id']; $st = $gtRsvp[$gid] ?? null; $c = gettogether_rsvp_counts($gid); ?>
      <div class="card gt-card<?= $st === 'no' ? ' gt-out' : '' ?>">
        <div class="gt-head">
          <strong><i class="ti ti-confetti"></i> <a href="gettogether.php?id=<?= $gid ?>" class="gt-title"><?= h($g['title']) ?></a></strong>
          <span class="pill pill-ok"><i class="ti ti-users"></i> <?= $c['yes'] ?> dabei</span>
          <?= share_button('gettogether.php?id=' . $gid, 'Get-Together-Link teilen') ?>
        </div>
        <div class="event-facts" style="margin:.3rem 0">
          <span class="fact"><i class="ti ti-calendar-event"></i> <?= h(fmt_slot($g['starts_at'], $g['ends_at'])) ?></span>
          <?php if (trim((string)$g['location']) !== ''): ?><span class="fact"><i class="ti ti-map-pin"></i> <?= h($g['location']) ?></span><?php endif; ?>
        </div>
        <?php if (trim((string)$g['description']) !== ''): ?><p class="small" style="margin:0 0 .5rem"><?= nl2br(h($g['description'])) ?></p><?php endif; ?>
        <p class="small" style="margin:0 0 .5rem"><a href="gettogether.php?id=<?= $gid ?>"><i class="ti ti-basket"></i> Wer kommt &amp; wer bringt was mit ›</a></p>
        <?php if ($me): ?>
          <div class="gt-rsvp">
            <span class="gt-q"><?= $st === null ? 'Bist du dabei?' : 'Deine Rückmeldung:' ?></span>
            <form method="post" class="gt-pick">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="gt_rsvp">
              <input type="hidden" name="gt_id" value="<?= $gid ?>">
              <button type="submit" name="status" value="yes" class="gt-btn gt-yes<?= $st === 'yes' ? ' on' : '' ?>"><i class="ti ti-check"></i> Dabei</button>
              <button type="submit" name="status" value="no" class="gt-btn gt-no<?= $st === 'no' ? ' on' : '' ?>"><i class="ti ti-x"></i> Nicht</button>
            </form>
            <?php if ($st === 'no'): ?><span class="small muted gt-hint"><i class="ti ti-calendar-off"></i> aus deinem Kalender ausgeblendet</span><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php /* Externe Events: öffentliche Anmeldungen. Nur für die, die sie betreuen dürfen –
         Vorsitz/Admin, global freigeschaltete Referate oder das betreuende Referat. */ ?>
<?php require_once __DIR__ . '/extern-db.php';
      if (extern_can_manage(null)):
        $exAlle = can_admin() || in_array(trim((string)($me['referat'] ?? '')), extern_referate(), true);
        $exListe = $exAlle ? extern_events_all() : extern_events_all([trim((string)($me['referat'] ?? ''))]);
        $exOffen = array_values(array_filter($exListe, fn($x) => extern_reg_open($x))); ?>
  <div class="section-title" id="externe"><i class="ti ti-ticket"></i> Externe Events
    <?= $exOffen ? '<span class="count">' . count($exOffen) . '</span>' : '' ?>
    <a class="btn secondary small sec-edit" href="extern.php"><i class="ti ti-settings"></i> Verwalten</a></div>
  <div class="card">
    <?php if (!$exListe): ?>
      <p class="empty small" style="margin:0"><i class="ti ti-ticket"></i> Noch keine öffentliche Anmeldung angelegt.
        <a href="extern.php">Jetzt eine einrichten</a> – für Kneipentour, Fahrten oder Workshops.</p>
    <?php else: ?>
      <table class="list">
        <tbody>
        <?php foreach (array_slice($exListe, 0, 6) as $x): $xz = extern_counts((int)$x['id']); ?>
          <tr>
            <td><a href="extern-teilnahme.php?id=<?= (int)$x['id'] ?>"><?= h($x['title']) ?></a>
              <?php if (extern_reg_open($x)): ?> <span class="pill pill-ok" style="font-size:.7rem">offen</span>
              <?php elseif ((string)$x['status'] === 'draft'): ?> <span class="pill pill-info" style="font-size:.7rem">Entwurf</span><?php endif; ?></td>
            <td class="muted small"><?= (int)$xz['personen'] ?> angemeldet<?= $xz['warteliste'] > 0 ? ', ' . (int)$xz['warteliste'] . ' auf der Warteliste' : '' ?></td>
            <td class="muted small"><?= trim((string)$x['starts_at']) !== '' ? h(fmt_date(substr((string)$x['starts_at'], 0, 10))) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($upcoming): ?>
  <div class="section-title"><i class="ti ti-flame"></i> Anstehend <span class="count"><?= count($upcoming) ?></span></div>
  <div class="event-cards">
    <?php foreach ($upcoming as $e) $card($e); ?>
  </div>
<?php endif; ?>

<?php if ($planned): ?>
  <div class="section-title"><i class="ti ti-calendar-plus"></i> Geplant <span class="count"><?= count($planned) ?></span></div>
  <div class="event-cards">
    <?php foreach ($planned as $e) $card($e); ?>
  </div>
<?php endif; ?>

<?php if ($past): ?>
  <details class="past-events">
    <summary><i class="ti ti-history"></i> Vergangene Events (<?= count($past) ?>)</summary>
    <div class="event-cards" style="margin-top:1rem">
      <?php foreach ($past as $e) $card($e); ?>
    </div>
  </details>
<?php endif; ?>
<?php
page_footer();
