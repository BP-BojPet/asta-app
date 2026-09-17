<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();

$id = (int)($_GET['id'] ?? 0);
$event = event_get($id);
if (!$event) { http_response_code(404); page_header('Nicht gefunden'); echo '<p>Event nicht gefunden.</p>'; page_footer(); exit; }
if (!can_see_event_draft($event)) { // Entwürfe sind nur für die Besitzer:innen sichtbar
    http_response_code(404); page_header('Nicht gefunden'); echo '<p>Event nicht gefunden.</p>'; page_footer(); exit;
}

// Reihenfolge: chronologisch, aber gleichnamige Schichten zusammenhalten (siehe slots_grouped()).
$slots = slots_grouped(event_slots($id));

$canManage = can_manage_event($event);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // --- Externe Helfer pro Schicht (nur Orga) – bewusst auch nach Fristende, da hier eingeteilt wird ---
    if (($_POST['action'] ?? '') === 'save_externals') {
        if (!$canManage) { flash('Externe Helfer darf nur der/die Event-Organisator:in pflegen.', 'error'); redirect('event.php?id=' . $id); }
        $upd = db()->prepare('UPDATE event_slots SET external_helpers = ? WHERE id = ? AND event_id = ?');
        foreach ($slots as $s) {
            $names = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($_POST['external'][(int)$s['id']] ?? ''))), fn($n) => $n !== ''));
            $upd->execute([implode("\n", $names), (int)$s['id'], $id]);
        }
        flash('Externe Helfer gespeichert.', 'success');
        redirect('event.php?id=' . $id . '#externe');
    }

    // --- Offene Schicht selbst übernehmen (nur nach Veröffentlichung, ohne Zeitkonflikt) ---
    if (($_POST['action'] ?? '') === 'claim_slot') {
        if (!$me) {
            flash('Mit dem Technik-Login kannst du dich nicht eintragen – melde dich als Mitglied an.', 'error');
            redirect('event.php?id=' . $id . '#offene-schichten');
        }
        $r = claim_open_slot($event, (int)($_POST['slot_id'] ?? 0), (int)$me['id']);
        flash($r['msg'], $r['ok'] ? 'success' : 'error');
        redirect('event.php?id=' . $id . '#offene-schichten');
    }

    // --- RSVP des eingeloggten Mitglieds (Einteilung läuft über admin/events.php) ---
    if (!$me) {
        flash('Mit dem Technik-Login kannst du nicht abstimmen – melde dich als Mitglied an.', 'error');
        redirect('event.php?id=' . $id);
    }
    if (event_locked($event)) {
        flash('Die Abstimmung ist beendet – keine Änderungen mehr möglich.', 'error');
        redirect('event.php?id=' . $id);
    }
    if (!empty($event['plan_locked'])) {
        flash('Der Arbeitsplan steht bereits – bitte wende dich an den/die Event-Organisator:in, wenn sich an deiner Einteilung etwas ändern soll.', 'error');
        redirect('event.php?id=' . $id);
    }
    $memberId = (int)$me['id'];
    $statuses = $_POST['status'] ?? [];   // [slot_id => yes|no|maybe]
    $comment  = trim((string)($_POST['comment'] ?? ''));
    $up = db()->prepare(
        'INSERT INTO responses(slot_id, member_id, status, comment, updated_at)
         VALUES(:s, :m, :st, :c, datetime(\'now\',\'localtime\'))
         ON CONFLICT(slot_id, member_id) DO UPDATE SET
            status = excluded.status, comment = excluded.comment, updated_at = excluded.updated_at'
    );
    foreach ($slots as $s) {
        $st = $statuses[$s['id']] ?? 'no';
        if (!in_array($st, ['yes', 'no', 'maybe'], true)) $st = 'no';
        $up->execute([':s' => (int)$s['id'], ':m' => $memberId, ':st' => $st, ':c' => $comment]);
    }
    // Zusatz-Merkmal (falls das Event eine Zusatz-Abfrage hat)
    if (event_attr_labels($event)) {
        db()->prepare('INSERT INTO event_member_attr(event_id, member_id, has_attr, has_attr2, has_attr3) VALUES(?,?,?,?,?)
            ON CONFLICT(event_id, member_id) DO UPDATE SET has_attr = excluded.has_attr, has_attr2 = excluded.has_attr2, has_attr3 = excluded.has_attr3')
            ->execute([$id, $memberId, isset($_POST['has_attr']) ? 1 : 0, isset($_POST['has_attr2']) ? 1 : 0, isset($_POST['has_attr3']) ? 1 : 0]);
    }
    flash('Deine Rückmeldung wurde gespeichert. Danke!', 'success');
    redirect('event.php?id=' . $id);
}

$responses = event_responses($id);
$members = members_all();

// Mitglieder ohne aktiven Status, die trotzdem geantwortet haben, ergänzen
$shownIds = array_column($members, 'id');
foreach ($responses as $slotResp) {
    foreach ($slotResp as $mid => $_) {
        if (!in_array($mid, $shownIds, true)) {
            $m = member_get((int)$mid);
            if ($m) { $members[] = $m; $shownIds[] = (int)$mid; }
        }
    }
}

// Pro Slot Auswertung
$slotStats = [];
foreach ($slots as $s) {
    $yes = $maybe = 0;
    foreach ($responses[(int)$s['id']] ?? [] as $r) {
        if ($r['status'] === 'yes') $yes++;
        elseif ($r['status'] === 'maybe') $maybe++;
    }
    $slotStats[(int)$s['id']] = ['yes' => $yes, 'maybe' => $maybe];
}
$totalNeed = array_sum(array_map(fn($s) => (int)$s['target_helpers'], $slots));

// Einteilung / Arbeitsplan
$assign = event_assign_status($id);               // [slot_id => [member_id => bool notified]]
$attrLabels = event_attr_labels($event);           // [idx => Label] der aktiven Merkmale
$attrHoldersAll = event_attr_holders_all($event);  // [idx => [member_id => true]]
$attrHolders = $attrHoldersAll[1] ?? [];           // Merkmal 1 (Abwärtskompat.)
$reqLabel = trim((string)($event['req_label'] ?? ''));
// Merkmal-Badges eines Mitglieds (HTML, alle Merkmale die es hat).
$attrBadges = function (int $mid) use ($attrLabels, $attrHoldersAll): string {
    $out = '';
    foreach ($attrLabels as $ai => $alab) if (!empty($attrHoldersAll[$ai][$mid])) {
        $out .= ' <i class="ti ti-discount-check-filled attr-badge a' . (int)$ai . '" title="' . h($alab) . '"></i>';
    }
    return $out;
};
$mname = [];
foreach (members_all(true) as $m) $mname[(int)$m['id']] = $m['name'];
$maxShifts = (int)($event['max_shifts_per_member'] ?? 0);
$planLocked = !empty($event['plan_locked']);
$hasAssignments = (bool)array_filter($assign);
// Schichten, für die das eingeloggte Mitglied eingeteilt ist
$myAssignedSlots = [];
if ($me) {
    foreach ($slots as $s) {
        if (isset($assign[(int)$s['id']][(int)$me['id']])) $myAssignedSlots[] = $s;
    }
}

// Schichttauschbörse: hier nur ein Hinweis-Link – die Börse selbst ist eine eigene Seite (tauschboerse.php)
$swapOpen = $me && $myAssignedSlots && swap_market_open($event);

// Offen gebliebene Schichten zum Selbst-Eintragen (nur nach Veröffentlichung, künftige & unterbesetzte)
$openSlots = ($me && $planLocked && empty($event['closed'])) ? event_open_slots($id) : [];

// Anzeige-Helfer: Orte der Schichten (eindeutig) + Ersteller
$locs = [];
foreach ($slots as $s) { $l = trim((string)$s['location']); if ($l !== '' && !in_array($l, $locs, true)) $locs[] = $l; }
$creator = event_owner_names($event);
$range = fmt_event_range($event['starts_at'] ?? null, $event['ends_at'] ?? null);
$slotCount = count($slots);

// Bei langen, mehrtägigen Events nach Tagen gruppieren (Abstimmung + Meine Rückmeldung)
$slotDays = [];
foreach ($slots as $s) $slotDays[substr((string)$s['starts_at'], 0, 10)] = true;
$groupByDay = $slotCount > 5 && count($slotDays) > 1;
$multiDay = count($slotDays) > 1; // mehrtägiges Event → Tages-Separatoren in Termine/Arbeitsplan

page_header($event['title']);
?>
<p class="small"><a href="events.php">‹ Alle Events</a></p>

<div class="card event-hero">
  <div class="hero-head">
    <h1 class="event-title">
      <?= h($event['title']) ?>
      <?php if (!empty($event['draft'])): ?><span class="badge badge-draft">Entwurf</span><?php endif; ?>
      <?php if ($event['closed']): ?><span class="badge badge-closed">abgeschlossen</span>
      <?php elseif (deadline_passed($event['deadline'] ?? null)): ?><span class="badge badge-closed">Frist abgelaufen</span><?php endif; ?>
    </h1>
    <?= share_button('event.php?id=' . $id, 'Event-Link teilen') ?>
  </div>
  <?php if (!empty($event['draft'])): ?>
    <div class="flash flash-info" style="margin:.4rem 0 0"><i class="ti ti-eye-off"></i> <strong>Entwurf</strong> – nur für dich sichtbar.<?php if ($canManage): ?> Zum Freigeben <a href="admin/events.php?edit=<?= $id ?>">verwalten &amp; veröffentlichen</a>.<?php endif; ?></div>
  <?php endif; ?>
  <div class="event-facts">
    <?php if ($range): ?><span class="fact"><i class="ti ti-calendar-event"></i> <?= h($range) ?></span><?php endif; ?>
    <?php if ($locs): ?><span class="fact"><i class="ti ti-map-pin"></i> <?= h(implode(' · ', $locs)) ?></span><?php endif; ?>
    <?php if ($totalNeed > 0): ?><span class="fact"><i class="ti ti-users"></i> <?= $totalNeed ?> Helfer:innen gesucht</span><?php endif; ?>
    <?php if ($slotCount): ?><span class="fact"><i class="ti ti-clock-hour-4"></i> <?= $slotCount ?> Termin<?= $slotCount === 1 ? '' : 'e' ?></span><?php endif; ?>
    <?php if (!empty($event['deadline'])): ?>
      <span class="fact<?= deadline_passed($event['deadline']) ? '' : ' fact-accent' ?>"><i class="ti ti-hourglass"></i> Abstimmen bis <?= fmt_date($event['deadline']) ?></span>
    <?php endif; ?>
  </div>
  <?php if ($event['description']): ?><p class="event-desc"><?= h($event['description']) ?></p><?php endif; ?>
  <?php if ($creator || $canManage || !event_locked($event)): ?>
    <div class="event-byline">
      <span class="byline-meta">
        <?php if ($creator): ?><i class="ti ti-users"></i> Orga: <strong><?= h($creator) ?></strong><?php endif; ?>
        <?php if ($canManage): ?><?= $creator ? ' · ' : '' ?><a href="admin/events.php?edit=<?= $id ?>"><i class="ti ti-edit"></i> verwalten</a><?php endif; ?>
      </span>
      <?php if (!event_locked($event)): ?>
        <a class="btn small" href="#meine-rueckmeldung"><i class="ti ti-pencil"></i> Jetzt eintragen</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($canManage && $planLocked): $overlaps = plan_overlaps($id); ?>
  <details class="card collapse-card" id="ueberschneidungen"<?= $overlaps ? ' open' : '' ?>>
    <summary class="section-title">
      <i class="ti ti-<?= $overlaps ? 'alert-triangle' : 'calendar-check' ?>" style="color:var(--<?= $overlaps ? 'red' : 'green' ?>)"></i>
      Überschneidungen im Arbeitsplan<?php if ($overlaps): ?> <span class="pill pill-bad"><?= count($overlaps) ?></span><?php endif; ?>
      <span class="small muted" style="font-weight:400">· nur für die Orga</span>
      <i class="ti ti-chevron-down collapse-chev"></i>
    </summary>
    <?php if (!$overlaps): ?>
      <p class="small muted" style="margin:.4rem 0 0"><i class="ti ti-check" style="color:var(--green)"></i> Keine Doppelbelegungen – niemand ist zwei zeitgleichen Schichten zugeteilt.</p>
    <?php else: ?>
      <p class="small muted" style="margin:.2rem 0 .8rem">Diese Personen sind zwei Schichten zugeteilt, die sich zeitlich überschneiden. Bitte im <a href="admin/events.php?edit=<?= $id ?>">Arbeitsplan</a> korrigieren.</p>
      <ul class="overlap-list">
        <?php foreach ($overlaps as $o): ?>
          <li class="overlap-item">
            <span class="overlap-name"><i class="ti ti-user-exclamation"></i> <?= h($o['name']) ?></span>
            <span class="overlap-slots">
              <span class="overlap-slot"><span class="ov-time"><?= h(fmt_slot((string)$o['a']['starts_at'], $o['a']['ends_at'] !== '' ? $o['a']['ends_at'] : null)) ?></span><?php if ((string)$o['a']['label'] !== ''): ?> <span class="ov-label"><?= h($o['a']['label']) ?></span><?php endif; ?></span>
              <i class="ti ti-arrows-cross ov-x"></i>
              <span class="overlap-slot"><span class="ov-time"><?= h(fmt_slot((string)$o['b']['starts_at'], $o['b']['ends_at'] !== '' ? $o['b']['ends_at'] : null)) ?></span><?php if ((string)$o['b']['label'] !== ''): ?> <span class="ov-label"><?= h($o['b']['label']) ?></span><?php endif; ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </details>
<?php endif; ?>

<?php if ($canManage && $planLocked): $fair = event_fairness_stats($event); if ($fair['count']): ?>
  <?php
    $fairBand = function (float $devMin): array {
        // devMin = Abweichung der Arbeitszeit (Minuten) vom Schnitt
        if ($devMin > 120)  return ['viel-ueber', 'deutlich über ⌀'];
        if ($devMin > 45)   return ['ueber', 'über ⌀'];
        if ($devMin < -120) return ['viel-unter', 'deutlich unter ⌀'];
        if ($devMin < -45)  return ['unter', 'unter ⌀'];
        return ['fair', 'ausgeglichen'];
    };
    $devFmt = function (float $dMin): string {
        $m = (int)round($dMin);
        if (abs($m) < 5) return '±0';
        return ($m > 0 ? '+' : '−') . fmt_duration_min(abs($m));
    };
  ?>
  <details class="card collapse-card fairteilung" id="fairteilung" open>
    <summary class="section-title"><i class="ti ti-scale"></i> Fairteilung <span class="small muted" style="font-weight:400">· nur für die Orga</span><i class="ti ti-chevron-down collapse-chev"></i></summary>
    <p class="small muted" style="margin:.2rem 0 .8rem">Verteilung der Schichten &amp; Arbeitszeit über die eingeteilten Mitglieder – sortiert nach Arbeitszeit.</p>
    <div class="fair-summary">
      <div class="fair-kpi"><span class="fair-kpi-val"><?= (int)$fair['count'] ?></span><span class="fair-kpi-lab">Mitglieder</span></div>
      <div class="fair-kpi"><span class="fair-kpi-val"><?= (int)$fair['total_shifts'] ?></span><span class="fair-kpi-lab">Schichten gesamt</span></div>
      <div class="fair-kpi"><span class="fair-kpi-val"><?= str_replace('.', ',', (string)round($fair['avg_shifts'], 1)) ?></span><span class="fair-kpi-lab">⌀ Schichten/Person</span></div>
      <div class="fair-kpi"><span class="fair-kpi-val"><?= h(fmt_duration_min((int)round($fair['total_minutes']))) ?></span><span class="fair-kpi-lab">Arbeitszeit gesamt</span></div>
      <div class="fair-kpi"><span class="fair-kpi-val"><?= h(fmt_duration_min((int)round($fair['avg_minutes']))) ?></span><span class="fair-kpi-lab">⌀ pro Person</span></div>
    </div>
    <div class="fair-table-wrap">
      <div class="fair-list">
        <div class="fair-grid fair-head">
          <span class="fc-rank">#</span>
          <span>Mitglied</span>
          <span>Schichten</span>
          <span>Arbeitszeit</span>
          <span class="fc-num" title="Für wie viele Schichten hat sich die Person eingetragen (Ja)">Eingetragen</span>
          <span class="fc-num" title="Abweichung der Arbeitszeit vom Schnitt">vs ⌀</span>
          <span>Fairness</span>
        </div>
        <?php $rank = 1; foreach ($fair['rows'] as $r):
          [$bandCls, $bandLab] = $fairBand((float)$r['min_dev']);
          $shiftPct = $fair['max_shifts'] > 0 ? round($r['shifts'] / $fair['max_shifts'] * 100) : 0;
          $minPct   = $fair['max_minutes'] > 0 ? round($r['minutes'] / $fair['max_minutes'] * 100) : 0;
        ?>
          <details class="fair-row">
            <summary class="fair-grid">
              <span class="fc-rank"><?= $rank++ ?></span>
              <span class="fair-name"><i class="ti ti-chevron-right fair-chev"></i><?= h($r['name']) ?></span>
              <span class="fair-bar-cell">
                <span class="fair-bar"><span class="fair-bar-fill shifts" style="width:<?= $shiftPct ?>%"></span></span>
                <span class="fair-num"><?= (int)$r['shifts'] ?></span>
              </span>
              <span class="fair-bar-cell">
                <span class="fair-bar"><span class="fair-bar-fill mins" style="width:<?= $minPct ?>%"></span></span>
                <span class="fair-num"><?= h(fmt_duration_min((int)$r['minutes'])) ?><?= $r['open_end'] ? ' <span class="fair-star" title="Schicht(en) ohne Endzeit – Arbeitszeit unvollständig">*</span>' : '' ?></span>
              </span>
              <span class="fc-num fair-signed"><?= (int)$r['signed_up'] ?></span>
              <span class="fc-num fair-dev"><?= h($devFmt((float)$r['min_dev'])) ?></span>
              <span><span class="fair-badge <?= $bandCls ?>"><?= $bandLab ?></span></span>
            </summary>
            <div class="fair-shifts">
              <?php if (!$r['slots']): ?>
                <p class="small muted" style="margin:0">Keine Schichten.</p>
              <?php else: ?>
                <ul class="fair-shiftlist">
                  <?php foreach ($r['slots'] as $sl): ?>
                    <li>
                      <i class="ti ti-clock-hour-4"></i>
                      <span class="fs-time"><?= h(fmt_slot($sl['starts_at'], $sl['ends_at'] !== '' ? $sl['ends_at'] : null)) ?></span>
                      <?php if ($sl['label'] !== ''): ?><span class="fs-label"><?= h($sl['label']) ?></span><?php endif; ?>
                      <?php if ($sl['location'] !== ''): ?><span class="fs-loc"><i class="ti ti-map-pin"></i> <?= h($sl['location']) ?></span><?php endif; ?>
                      <span class="fs-dur"><?= $sl['minutes'] > 0 ? h(fmt_duration_min((int)$sl['minutes'])) : '' ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($fair['open_end_total'] > 0): ?>
      <p class="small muted" style="margin:.6rem 0 0"><span class="fair-star">*</span> <?= (int)$fair['open_end_total'] ?> Schicht-Zuteilung(en) ohne Endzeit – deren Arbeitszeit fehlt in der Summe.</p>
    <?php endif; ?>
  </details>
<?php endif; endif; ?>

<?php // ===== Fairteilung Lite: eigener Anteil vs. Schnitt – für normale Mitglieder =====
if ($me && $planLocked && !$canManage):
    $fairL = event_fairness_stats($event);
    if ($fairL['count']):
        $mineRow = null;
        foreach ($fairL['rows'] as $fr) if ((int)$fr['member_id'] === (int)$me['id']) { $mineRow = $fr; break; }
        $myShiftsN = (int)($mineRow['shifts'] ?? 0);
        $myMinutes = (int)($mineRow['minutes'] ?? 0);
        $devL = $myMinutes - (float)$fairL['avg_minutes'];
        // Empfehlung bewusst unempfindlich: erst ab ±2 h Arbeitszeit-Abweichung vom Schnitt wird etwas geraten
        if ($devL > 120) {
            $adviceIcon = 'ti-heart'; $adviceColor = 'var(--petrol)';
            $advice = 'Du übernimmst deutlich mehr als der Schnitt – stark, danke dir! Falls es dir zu viel wird, kannst du Schichten in der <a href="tauschboerse.php">Schichtbörse</a> tauschen oder abgeben.';
        } elseif ($devL < -120) {
            $adviceIcon = 'ti-hand-grab'; $adviceColor = 'var(--amber)';
            $advice = 'Du liegst deutlich unter dem Schnitt. Schau bitte bei den <a href="#offene-schichten">offenen Schichten</a> oder in der <a href="tauschboerse.php">Schichtbörse</a> vorbei.';
        } else {
            $adviceIcon = 'ti-circle-check'; $adviceColor = 'var(--green)';
            $advice = 'Dein Anteil liegt im Bereich des Schnitts – alles gut, hier gibt es nichts zu tun.';
        }
    ?>
  <div class="card" id="fairteilung-lite">
    <div class="section-title" style="margin-top:0"><i class="ti ti-scale"></i> Dein Anteil an diesem Event</div>
    <div class="fair-summary">
      <div class="fair-kpi"><span class="fair-kpi-val"><?= $myShiftsN ?></span><span class="fair-kpi-lab">Deine Schichten</span></div>
      <div class="fair-kpi"><span class="fair-kpi-val"><?= str_replace('.', ',', (string)round((float)$fairL['avg_shifts'], 1)) ?></span><span class="fair-kpi-lab">⌀ Schichten/Person</span></div>
      <div class="fair-kpi"><span class="fair-kpi-val"><?= h(fmt_duration_min($myMinutes)) ?></span><span class="fair-kpi-lab">Deine Arbeitszeit</span></div>
      <div class="fair-kpi"><span class="fair-kpi-val"><?= h(fmt_duration_min((int)round((float)$fairL['avg_minutes']))) ?></span><span class="fair-kpi-lab">⌀ pro Person</span></div>
    </div>
    <p class="small" style="margin:.7rem 0 0;display:flex;align-items:flex-start;gap:.4rem"><i class="ti <?= $adviceIcon ?>" style="color:<?= $adviceColor ?>;margin-top:.15rem"></i> <span><?= $advice ?></span></p>
  </div>
<?php endif; endif; ?>

<?php if (event_locked($event)): ?>
  <div class="flash flash-info">Die Abstimmung ist beendet. Du kannst die Ergebnisse ansehen, aber nichts mehr ändern.</div>
<?php endif; ?>
<?php if ($myAssignedSlots): ?>
  <div class="flash flash-success"><i class="ti ti-calendar-check"></i> Du bist eingeteilt:
    <?= implode(' · ', array_map(fn($s) => fmt_slot($s['starts_at'], $s['ends_at']) . ($s['location'] ? ', ' . h($s['location']) : '') . ($s['label'] ? ' (' . h($s['label']) . ')' : ''), $myAssignedSlots)) ?>
  </div>
<?php endif; ?>

<?php if ($swapOpen): ?>
  <a class="card boerse-hint" href="tauschboerse.php#ev<?= $id ?>">
    <span class="boerse-hint-ic"><i class="ti ti-arrows-exchange"></i></span>
    <span class="boerse-hint-txt"><strong><?= app_place('tauschboerse', 'Schichtbörse') ?> ist offen.</strong> Eine deiner Schichten zum Tausch anbieten oder eine angebotene übernehmen.</span>
    <span class="boerse-hint-go">Zur Börse <i class="ti ti-chevron-right"></i></span>
  </a>
<?php endif; ?>

<?php if ($openSlots): ?>
  <div class="section-title" id="offene-schichten"><i class="ti ti-hand-click"></i> Offene Schichten – kurzfristig einspringen</div>
  <div class="card open-slots">
    <p class="small muted" style="margin:0 0 .7rem">Diese Schichten sind nach der Einteilung noch unterbesetzt. Du kannst dich selbst eintragen, solange du in der Zeit keinen anderen Einsatz hast.</p>
    <ul class="boerse-list">
      <?php foreach ($openSlots as $s): $sid = (int)$s['id'];
        $mine = isset($assign[$sid][(int)$me['id']]);
        $conflict = !$mine && member_time_conflict((int)$me['id'], $s, $sid); ?>
        <li class="boerse-row">
          <span class="brow-when"><i class="ti ti-clock"></i> <?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?>
            <?php if ($s['label']): ?><span class="muted">(<?= h($s['label']) ?>)</span><?php endif; ?>
            <?php if (trim((string)$s['location']) !== ''): ?><span class="muted">· <?= h($s['location']) ?></span><?php endif; ?>
            <span class="pill pill-warn" style="font-size:.7rem;margin-left:.3rem">noch <?= (int)$s['remaining'] ?> gesucht</span>
          </span>
          <?php if ($mine): ?>
            <span class="brow-tag live"><i class="ti ti-check"></i> du bist dabei</span>
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
<?php endif; ?>

<?php if (!$slots): ?>
  <p class="muted">Für dieses Event wurden noch keine Termine angelegt.</p>
  <?php page_footer(); exit; ?>
<?php endif; ?>

<?php if ($planLocked): // ===== Plan steht: Arbeitsplan (Druck-Layout) prominent, Abstimmung eingeklappt ===== ?>
  <div class="section-title" id="arbeitsplan"><i class="ti ti-clipboard-check"></i> Arbeitsplan – wer ist eingeteilt</div>
  <div class="card plan-print plan-print-embed"><?= plan_print_html($event) ?></div>
<?php endif; ?>

<?php if ($planLocked): ?>
  <details class="card abst-collapse" id="abstimmung" style="margin-bottom:1.1rem">
    <summary><i class="ti ti-checkup-list"></i> Wer kann wann (Abstimmungsübersicht) <span class="small muted" style="font-weight:400">– zum Aufklappen</span></summary>
    <div class="abst-body">
<?php else: ?>
  <div class="section-title" id="abstimmung"><i class="ti ti-checkup-list"></i> Abstimmung – wer kann wann?</div>
<?php endif; ?>
<?php if ($attrLabels): ?>
  <p class="small muted" style="margin:-.4rem 0 .7rem">Merkmale: <?php $leg = []; foreach ($attrLabels as $ai => $alab) $leg[] = '<i class="ti ti-discount-check-filled attr-badge a' . (int)$ai . '"></i> ' . h($alab); echo implode(' &nbsp; ', $leg); ?></p>
<?php endif; ?>
<?php if (count($slots) >= 5): // ab 5 Schichten: Dienstplan-Tabelle ?>
<?php
$rosterCell = function ($s) use ($responses, $mname, $me, $attrBadges) {
    $sid = (int)$s['id'];
    $yes = $maybe = [];
    foreach ($responses[$sid] ?? [] as $mid => $r) {
        $ab = member_absent_on((int)$mid, $s['starts_at']);
        $badge = $attrBadges((int)$mid);
        $nm = member_link(['id' => (int)$mid, 'name' => $mname[$mid] ?? ('#' . $mid)]) . ($me && (int)$mid === (int)$me['id'] ? ' (du)' : '') . ($ab && $r['status'] === 'yes' ? ' 🌴' : '') . $badge;
        if ($r['status'] === 'yes') $yes[] = $nm;
        elseif ($r['status'] === 'maybe') $maybe[] = $nm;
    }
    if (!$yes && !$maybe) return '<span class="muted">–</span>';
    $out = '';
    foreach ($yes as $n)   $out .= '<div class="r-yes">' . $n . '</div>';
    foreach ($maybe as $n) $out .= '<div class="r-maybe">' . $n . ' <span class="r-tag">evtl.</span></div>';
    return $out;
};
echo shift_roster_html($slots, $rosterCell);
?>
<p class="small muted">Schichten als Zeilen, Uhrzeit als Spalten. <span class="r-yes">Grün</span> = Zusage (Ja), <span class="r-maybe">gelb</span> = evtl. · 🌴 = trotz Abwesenheit zugesagt.</p>
<?php else: // bis 7 Schichten: klassische Matrix ?>
<div class="matrix-wrap">
<table class="matrix">
  <thead>
    <tr>
      <th class="name-col">Name</th>
      <?php foreach ($slots as $s): [$day, $time] = fmt_slot_short($s['starts_at']); ?>
        <th>
          <span class="slot-day"><?= h($day) ?></span>
          <?php if ($time): ?><span class="slot-time"><?= h($time) ?></span><?php endif; ?>
          <?php if ($s['label']): ?><span class="slot-time"><?= h($s['label']) ?></span><?php endif; ?>
        </th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($members as $m): $mid = (int)$m['id']; $isSelf = $me && (int)$me['id'] === $mid; ?>
      <tr<?= $isSelf ? ' class="row-self"' : '' ?>>
        <td class="name-col"><?= member_link($m) ?><?= $isSelf ? ' <span class="small muted">(du)</span>' : '' ?><?= $attrBadges($mid) ?></td>
        <?php foreach ($slots as $s):
            $r = $responses[(int)$s['id']][$mid] ?? null;
            $status = $r['status'] ?? null;
            $absent = member_absent_on($mid, $s['starts_at']);
            $cls = 'cell-no'; $sym = '·';
            if ($status === 'yes')   { $cls = 'cell-yes';   $sym = '✓'; }
            elseif ($status === 'maybe') { $cls = 'cell-maybe'; $sym = '?'; }
            elseif ($status === 'no' && $r) { $cls = 'cell-no'; $sym = '✕'; }
            $conflict = $absent && $status === 'yes';
            $title = $absent ? 'Mitglied ist an diesem Tag als abwesend eingetragen' : '';
            if ($conflict) { $cls .= ' cell-conflict'; $title = 'Konflikt: trotz Abwesenheit zugesagt!'; }
            elseif ($absent && $status !== 'yes') { $sym = $sym === '·' ? '🌴' : $sym; }
        ?>
          <td class="<?= $cls ?>" title="<?= h($title) ?>"><?= $sym ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="tally">
      <td class="name-col">Zusagen</td>
      <?php foreach ($slots as $s):
        $st = $slotStats[(int)$s['id']];
        $tt = (int)$s['target_helpers'];
        $ext = count(slot_external_names($s));
        $ttEff = max(0, $tt - $ext); // verbleibender Mitglieder-Bedarf nach Externen
        if ($tt > 0) {
            if ($ttEff === 0) { $cls = 'amp-ok'; $txt = '✓'; }
            else { $cls = $st['yes'] >= $ttEff ? 'amp-ok' : ($st['yes'] > 0 ? 'amp-warn' : 'amp-bad'); $txt = $st['yes'] . '/' . $ttEff; }
        } else {
            $cls = $st['yes'] > 0 ? 'amp-ok' : 'amp-bad';
            $txt = (string)$st['yes'];
        }
      ?>
        <td>
          <span class="amp <?= $cls ?>"><?= $txt ?></span>
          <?php if ($ext): ?><br><span class="slot-time">+<?= $ext ?> extern</span><?php endif; ?>
          <?php if ($st['maybe']): ?><br><span class="slot-time">+<?= $st['maybe'] ?> evtl.</span><?php endif; ?>
        </td>
      <?php endforeach; ?>
    </tr>
  </tfoot>
</table>
</div>
<p class="small muted">✓ = Zusage · ? = vielleicht · ✕ = Absage · 🌴 = an dem Tag abwesend · <span class="warn-line">roter Rahmen</span> = trotz Abwesenheit zugesagt</p>
<?php endif; ?>
<?php if ($planLocked): ?>
    </div>
  </details>
<?php endif; ?>

<?php if ($planLocked && $me && !event_locked($event)): ?>
<div class="flash flash-info" style="margin-top:1.4rem"><i class="ti ti-lock"></i> Der Arbeitsplan steht bereits. Deine Rückmeldung kannst du nicht mehr selbst ändern – wende dich an den/die Event-Organisator:in, wenn sich etwas ändern soll.</div>
<?php elseif (!event_locked($event) && $me):
    $meId = (int)$me['id'];
    $myComment = ''; $iResponded = false;
    foreach ($responses as $sr) { if (isset($sr[$meId])) { $iResponded = true; if ($myComment === '') $myComment = $sr[$meId]['comment']; } }
?>
<details id="meine-rueckmeldung" class="card meine-rueckmeldung"<?= $iResponded ? '' : ' open' ?> style="margin-top:1.4rem">
  <summary><i class="ti ti-pencil"></i> Meine Rückmeldung <?php if ($iResponded): ?><span class="pill pill-ok" style="font-size:.7rem"><i class="ti ti-check"></i> abgegeben</span> <span class="small muted" style="font-weight:400">– zum Ändern aufklappen</span><?php endif; ?></summary>
  <form method="post" action="event.php?id=<?= $id ?>">
    <?= csrf_field() ?>
    <table class="list">
      <tbody>
      <?php $lastDay = null; foreach ($slots as $s):
          $cur = $responses[(int)$s['id']][$meId]['status'] ?? 'no';
          $absent = member_absent_on($meId, $s['starts_at']);
          $day = substr((string)$s['starts_at'], 0, 10);
          if ($groupByDay && $day !== $lastDay): $lastDay = $day; ?>
            <tr class="day-row"><td colspan="2"><?= h(fmt_event_range($day, null)) ?></td></tr>
          <?php endif;
          $tt = dt($s['starts_at']); $te = dt($s['ends_at']);
          $timeStr = ($tt && $tt->format('H:i') !== '00:00') ? $tt->format('H:i') . ($te ? '–' . $te->format('H:i') : '') : 'ganztägig';
      ?>
        <tr>
          <td>
            <?= $groupByDay ? h($timeStr) : h(fmt_slot($s['starts_at'], $s['ends_at'])) ?>
            <?php if ($s['label']): ?><span class="muted">· <?= h($s['label']) ?></span><?php endif; ?>
            <?php if ($absent): ?><br><span class="warn-line small">🌴 Du bist an dem Tag als abwesend eingetragen</span><?php endif; ?>
          </td>
          <td style="text-align:right">
            <span class="statuspick">
              <label><input type="radio" name="status[<?= (int)$s['id'] ?>]" value="yes" <?= $cur==='yes'?'checked':'' ?>><span class="s-yes">Ja</span></label>
              <label><input type="radio" name="status[<?= (int)$s['id'] ?>]" value="maybe" <?= $cur==='maybe'?'checked':'' ?>><span class="s-maybe">Evtl.</span></label>
              <label><input type="radio" name="status[<?= (int)$s['id'] ?>]" value="no" <?= $cur==='no'?'checked':'' ?>><span class="s-no">Nein</span></label>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php foreach ($attrLabels as $ai => $alab): ?>
      <label class="inline" style="margin:.2rem 0 .6rem;display:block"><input type="checkbox" name="<?= attr_has_col((int)$ai) ?>" <?= !empty($attrHoldersAll[$ai][$meId]) ? 'checked' : '' ?>> <?= h($alab) ?> <i class="ti ti-discount-check-filled attr-badge a<?= (int)$ai ?>"></i></label>
    <?php endforeach; ?>
    <label for="comment">Kommentar (optional)</label>
    <input type="text" name="comment" id="comment" value="<?= h($myComment) ?>" placeholder="z. B. nur bis 17 Uhr">
    <div class="btn-row" style="margin-top:.9rem">
      <button class="btn" type="submit">Speichern</button>
    </div>
  </form>
</details>
<?php elseif (!event_locked($event) && !$me): ?>
<div class="flash flash-info" style="margin-top:1.4rem">Mit dem Technik-Login kannst du nur ansehen. Zum Abstimmen als Mitglied anmelden.</div>
<?php endif; ?>

<?php if ($canManage): $anyExt = false; foreach ($slots as $s) if (slot_external_names($s)) { $anyExt = true; break; } ?>
<details id="externe" class="card"<?= $anyExt ? ' open' : '' ?> style="margin-top:1.4rem">
  <summary><i class="ti ti-user-plus"></i> Externe Helfer <span class="small muted" style="font-weight:400">– nur für Orga; zählen mit, ohne Mitglied zu sein</span></summary>
  <form method="post" action="event.php?id=<?= $id ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_externals">
    <p class="small muted" style="margin:.5rem 0 .2rem">Personen, die keine Mitglieder sind (kein Login, keine Abstimmung, kein Eventscore). Eine Person pro Zeile. Sie senken den <strong>Mitglieder-Bedarf</strong> der Schicht (sichtbar in „Wer kann wann" und bei der Einteilung) und erscheinen im Plan als „(extern)".</p>
    <table class="list">
      <tbody>
      <?php foreach ($slots as $s): $sid = (int)$s['id']; $tt = (int)$s['target_helpers']; ?>
        <tr>
          <td style="white-space:nowrap;vertical-align:top">
            <strong><?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?></strong>
            <?php if ($s['label']): ?><br><span class="muted small"><?= h($s['label']) ?></span><?php endif; ?>
            <?php if ($tt > 0): ?><br><span class="muted small"><?= $tt ?> gesucht</span><?php endif; ?>
          </td>
          <td><textarea name="external[<?= $sid ?>]" rows="2" placeholder="z. B. Max Mustermann" style="width:100%;font-size:.85rem;line-height:1.4"><?= h(implode("\n", slot_external_names($s))) ?></textarea></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="btn-row" style="margin-top:.6rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Externe speichern</button></div>
  </form>
</details>
<?php endif; ?>

<?php // ===== Ganz unten: Termine (vor Plan-Veröffentlichung) bzw. einfache Eingeteilt-Liste (danach) ===== ?>
<?php if (!$planLocked): ?>
<div class="section-title"><i class="ti ti-calendar-time"></i> Termine</div>
<div class="card">
  <?php $lastDay = null; foreach ($slots as $s):
      $day = substr((string)$s['starts_at'], 0, 10);
      if ($multiDay && $day !== $lastDay): $lastDay = $day; ?>
        <div class="plan-day"><i class="ti ti-calendar-event"></i> <?= h(fmt_event_range($day, null)) ?></div>
      <?php endif; ?>
    <div class="plan-line">
      <div class="plan-line-head">
        <?php if ($s['label']): ?><strong><?= h($s['label']) ?></strong> <span class="muted"><?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?></span>
        <?php else: ?><strong><?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?></strong><?php endif; ?>
        <?php if ($s['location']): ?><span class="muted"><i class="ti ti-map-pin" style="font-size:.9em"></i> <?= h($s['location']) ?></span><?php endif; ?>
        <?php if ((int)$s['target_helpers'] > 0): ?><span class="pill pill-info" style="margin-left:auto"><i class="ti ti-users"></i> <?= (int)$s['target_helpers'] ?> gesucht</span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: // Plan steht: einfache Eingeteilt-Liste ganz unten (der ausführliche Druck-Plan steht oben) ?>
<div class="section-title"><i class="ti ti-list-check"></i> Eingeteilt – Kurzübersicht</div>
<div class="card">
  <?php $lastDay = null; foreach ($slots as $s): $a = $assign[(int)$s['id']] ?? []; $ext = slot_external_names($s);
      $day = substr((string)$s['starts_at'], 0, 10);
      if ($multiDay && $day !== $lastDay): $lastDay = $day; ?>
        <div class="plan-day"><i class="ti ti-calendar-event"></i> <?= h(fmt_event_range($day, null)) ?></div>
      <?php endif; ?>
    <div class="plan-line">
      <div class="plan-line-head">
        <?php if ($s['label']): ?><strong><?= h($s['label']) ?></strong> <span class="muted"><?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?></span>
        <?php else: ?><strong><?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?></strong><?php endif; ?>
        <?php if ($s['location']): ?><span class="muted"><i class="ti ti-map-pin" style="font-size:.9em"></i> <?= h($s['location']) ?></span><?php endif; ?>
      </div>
      <?php if ($a || $ext): ?>
        <div class="plan-people"><?php
          $people = array_map(fn($mid) => member_link(['id' => (int)$mid, 'name' => $mname[$mid] ?? ('#' . $mid)]), array_keys($a));
          foreach ($ext as $n) $people[] = h($n) . ' <span class="muted small">(extern)</span>';
          echo implode(', ', $people);
        ?></div>
      <?php else: ?>
        <div class="plan-people muted">— niemand eingeteilt</div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(function () {
  var d = document.getElementById('meine-rueckmeldung');
  if (!d) return;
  var open = function () { d.open = true; };
  if (location.hash === '#meine-rueckmeldung') open();
  document.querySelectorAll('a[href="#meine-rueckmeldung"]').forEach(function (a) {
    a.addEventListener('click', open);
  });
})();
</script>

<?php
page_footer();
