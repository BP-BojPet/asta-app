<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin();

// Props anpassen – bewusst NUR für die Admin-Rolle (nicht Vorsitz). Muss VOR dem Score-Handler stehen,
// weil der alles über can_edit_score() (= Vorsitz) abriegelt.
$canTuneProps = current_role() === 'admin';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'props_set') {
    check_csrf();
    if (!$canTuneProps) { flash('Props darf nur die Admin-Rolle anpassen.', 'error'); redirect('score.php?t=props'); }
    $mid  = (int)($_POST['member_id'] ?? 0);
    $name = short_name((string)(member_get($mid)['name'] ?? ''));
    $mode = (string)($_POST['mode'] ?? 'set');
    // +1/−1 rechnen vom aktuellen Stand, „set" nimmt den Zahlenwert aus dem Feld
    $val = match ($mode) {
        'plus'  => kudos_received_total($mid) + 1,
        'minus' => max(0, kudos_received_total($mid) - 1),
        default => max(0, (int)($_POST['value'] ?? 0)),
    };
    if ($mid > 0 && kudos_admin_set($mid, $val)) {
        flash($mode === 'plus'
            ? '+1 Prop für ' . $name . ' – die Person bekommt den normalen Hinweis (jetzt ' . $val . ').'
            : 'Props von ' . $name . ' auf ' . $val . ' gesetzt.', 'success');
    } else {
        flash('Bitte eine gültige Person und einen Wert ≥ 0 wählen.', 'error');
    }
    redirect('score.php?t=props');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if (!can_edit_score()) {
        flash('Nur der Vorsitz darf den Score bearbeiten.', 'error');
        redirect('score.php');
    }
    if ($action === 'reset_score') {
        setting_set('score_since', date('Y-m-d'));
        db()->exec('UPDATE members SET score_adjust = 0');
        flash('Eventscore zurückgesetzt – ab heute wird neu gezählt, manuelle Korrekturen entfernt.', 'success');
    } elseif ($action === 'adjust') {
        $id = (int)($_POST['id'] ?? 0);
        $dir = ((int)($_POST['dir'] ?? 0)) >= 0 ? 1 : -1;
        if (member_get($id)) {
            db()->prepare('UPDATE members SET score_adjust = score_adjust + ? WHERE id = ?')->execute([$dir, $id]);
        }
    } elseif ($action === 'basis_adjust') {
        $id = (int)($_POST['id'] ?? 0);
        $dir = ((int)($_POST['dir'] ?? 0)) >= 0 ? 1 : -1;
        if (member_get($id)) {
            db()->prepare('UPDATE members SET basis_adjust = basis_adjust + ? WHERE id = ?')->execute([$dir, $id]);
        }
        redirect('score.php?t=basis');
    } elseif ($action === 'reset_basis') {
        setting_set('basis_since', date('Y-m-d'));
        db()->exec('UPDATE members SET basis_adjust = 0');
        flash('Basis-Score zurückgesetzt – ab heute wird neu gezählt, manuelle Korrekturen entfernt.', 'success');
        redirect('score.php?t=basis');
    } elseif ($action === 'save_noshows') {
        $mid = (int)($_POST['meeting_id'] ?? 0);
        $chk = db()->prepare("SELECT 1 FROM meetings WHERE id = ? AND kind != 'stupa'"); $chk->execute([$mid]);
        if ($chk->fetchColumn()) {
            meeting_noshows_set($mid, array_map('intval', (array)($_POST['noshow'] ?? [])));
            flash('Unentschuldigt-gefehlt-Liste gespeichert.', 'success');
        }
        redirect('score.php?t=basis&noshow=' . $mid . '#noshow');
    }
    redirect('score.php');
}

$scores = member_scores();
$avg = score_average($scores);
$since = score_since();

// Mitglieder nach Score absteigend
$members = members_all();
usort($members, fn($a, $b) => ($scores[(int)$b['id']] ?? 0) <=> ($scores[(int)$a['id']] ?? 0));

/* REITER: Drei Dinge, die selten zusammen gebraucht werden – die Ranglisten,
   das Nachtragen von Fehlzeiten und die vertraulichen Props. Abschnitte sind NICHT verschoben,
   nur in ihren Reiter gehüllt. „Props" gibt es nur für die Admin-Rolle; der Reiter erscheint
   deshalb auch nur dort, und wer ihn ohne Recht aufruft, landet auf dem Eventscore. */
$tabs = ['event' => ['label' => 'Eventscore', 'icon' => 'ti-trophy'],
         'basis' => ['label' => 'Basis-Score', 'icon' => 'ti-checklist']];
if (current_role() === 'admin') $tabs['props'] = ['label' => 'Props', 'icon' => 'ti-hand-love-you'];
$tab = (string)($_GET['t'] ?? '');
if (!isset($tabs[$tab])) $tab = 'event';

page_header('Scores', true);
/* Gilt in JEDEM Reiter (Eventscore wie Basis-Score) – deshalb hier oben und nicht im ersten
   Abschnitt. */
$canEdit = can_edit_score();
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<h1><i class="ti ti-trophy"></i> Scores</h1>
<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($tabs as $tk => $td): ?>
    <a<?= $tk === $tab ? ' class="on" aria-current="page"' : '' ?> href="score.php?t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'event'): ?>
<div class="section-title" style="margin-top:.4rem"><i class="ti ti-trophy"></i> Eventscore</div>
<p class="muted">
  Punkte je Mitglied über alle Events mit abgelaufener Abstimmungsfrist<?= $since ? ' seit ' . fmt_date($since) : '' ?>.
  Pro zugeteilter Schicht +1 (über 2&nbsp;Std. +2), nicht abgestimmt −2, verfügbar-aber-nicht-eingeteilt 0.
  Schnitt aktuell: <strong><?= number_format($avg, 1, ',', '') ?></strong> Punkte.
  Bei der Zufallseinteilung werden Mitglieder mit niedrigerem Score bevorzugt.
</p>

<?php $roleLabels = ['member' => 'Mitglied', 'sekretariat' => 'Sekretariat', 'admin' => 'Admin', 'vorsitz' => 'Vorsitz']; ?>
<div class="card">
  <table class="list">
    <thead><tr><th>Mitglied</th><th>Rolle</th><th style="text-align:right">Punkte</th><th>Einordnung</th><?php if ($canEdit): ?><th style="text-align:right">Korrektur</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($members as $m): $sc = $scores[(int)$m['id']] ?? 0; $band = score_band_for((int)$m['id'], $scores);
        $pill = ['spitze' => 'pill-spitze', 'gut' => 'pill-ok', 'okay' => 'pill-info', 'ausbaufaehig' => 'pill-bad'][$band];
        $adj = (int)($m['score_adjust'] ?? 0); ?>
      <tr>
        <td><?= h($m['name']) ?></td>
        <td class="muted small"><?= h($roleLabels[$m['role'] ?? 'member'] ?? 'Mitglied') ?></td>
        <td style="text-align:right;font-weight:700"><?= $sc ?><?php if ($adj): ?> <span class="muted small">(<?= $adj > 0 ? '+' . $adj : $adj ?>)</span><?php endif; ?></td>
        <td><span class="pill <?= $pill ?>"><?= h(score_band_label($band)) ?></span></td>
        <?php if ($canEdit): ?>
          <td style="text-align:right">
            <div class="btn-row" style="justify-content:flex-end;gap:.3rem">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="dir" value="-1"><button class="btn secondary small" type="submit" title="−1 Punkt">−</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="dir" value="1"><button class="btn secondary small" type="submit" title="+1 Punkt">+</button></form>
            </div>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$members): ?><p class="muted">Noch keine Mitglieder.</p><?php endif; ?>
  <p class="small muted">Die Korrektur (+/−) ist ein manueller Zuschlag und wird beim Score mit eingerechnet (in Klammern angezeigt).</p>
</div>

<?php if ($canEdit): ?>
<div class="card">
  <h2>Zurücksetzen</h2>
  <p class="muted small">Setzt den Zählbeginn auf heute und entfernt manuelle Korrekturen – ältere Events fließen dann nicht mehr in den Score ein (z. B. zu Beginn einer neuen Legislatur). Bisheriger Zählbeginn: <strong><?= $since ? fmt_date($since) : 'Anfang' ?></strong>.</p>
  <form method="post" data-confirm="Eventscore wirklich zurücksetzen? Ab heute wird neu gezählt, ältere Events und manuelle Korrekturen entfallen." data-confirm-danger data-confirm-ok="Zurücksetzen" data-confirm-title="Score zurücksetzen">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_score">
    <button class="btn danger" type="submit"><i class="ti ti-refresh"></i> Score zurücksetzen</button>
  </form>
</div>
<?php else: ?>
<p class="muted small">Bearbeiten und Zurücksetzen des Scores kann nur der <strong>Vorsitz</strong>.</p>
<?php endif; ?>

<?php endif; ?>

<?php if ($tab === 'basis'): ?>
<?php
// ===================== Basis-Score =====================
// ACHTUNG: Dieser Block gehört IN den Reiter, nicht in den Eventscore-Zweig – sonst sind
// $basisSince & Co. hier nicht definiert und die Seite bricht mitten im Rendern ab
// (fmt_date(null)). Umbau-Regel: Compute-Blöcke liegen im selben Reiter wie ihre Ausgabe
// oder auf oberster Ebene.
$basis = basis_scores();
$basisSince = basis_since();
$bMembers = members_all();
usort($bMembers, fn($a, $b) => ($basis[(int)$a['id']]['total'] ?? 0) <=> ($basis[(int)$b['id']]['total'] ?? 0)); // schlechteste zuerst
$bPillMap = ['einwandfrei' => 'pill-ok', 'ausbaufaehig' => 'pill-warn', 'gespraech' => 'pill-bad'];
?>
<div class="section-title" id="basis"><i class="ti ti-checklist"></i> Basis-Score</div>
<p class="muted">
  Misst die Grundpflichten seit <strong><?= fmt_date($basisSince) ?></strong> – nur Minuspunkte, <strong>0 = Einwandfrei</strong>, ab <strong>−3 Schlecht</strong>, ab <strong>−6 Gesprächsbedarf</strong>:
  Bericht vergessen −1 · unentschuldigt gefehlt −2 · Eventscore „Ausbaufähig" −1 · App eine Woche nicht geöffnet −1 (Abwesenheits-Wochen entschuldigt) ·
  Wichtige Info nicht binnen 7 Tagen abgehakt −1 · Abstimmungsgegenstände einer Sitzung nicht gelesen −1. Dazu die manuelle Korrektur (±).
</p>
<div class="card">
  <table class="list">
    <thead><tr><th>Mitglied</th><th style="text-align:right">Punkte</th><th>Einordnung</th><th>Abzüge</th><?php if ($canEdit): ?><th style="text-align:right">Korrektur</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($bMembers as $m): $bd = $basis[(int)$m['id']] ?? ['total' => 0, 'rows' => [], 'adjust' => 0];
        $band = basis_band((int)$bd['total']); ?>
      <tr>
        <td><?= h($m['name']) ?></td>
        <td style="text-align:right;font-weight:700"><?= (int)$bd['total'] ?><?php if ($bd['adjust']): ?> <span class="muted small">(<?= $bd['adjust'] > 0 ? '+' . $bd['adjust'] : $bd['adjust'] ?>)</span><?php endif; ?></td>
        <td><span class="pill <?= $bPillMap[$band] ?>"><?= h(basis_band_label($band)) ?></span></td>
        <td class="small muted">
          <?php if (!$bd['rows']): ?>—<?php else: ?>
            <details style="display:inline"><summary style="cursor:pointer"><?= count($bd['rows']) ?> Posten</summary>
              <ul style="margin:.3rem 0 0;padding-left:1.1rem">
                <?php foreach ($bd['rows'] as $r): ?><li><?= h($r['reason']) ?> (<?= (int)$r['points'] ?>)</li><?php endforeach; ?>
              </ul>
            </details>
          <?php endif; ?>
        </td>
        <?php if ($canEdit): ?>
          <td style="text-align:right">
            <div class="btn-row" style="justify-content:flex-end;gap:.3rem">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="basis_adjust"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="dir" value="-1"><button class="btn secondary small" type="submit" title="−1 Punkt">−</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="basis_adjust"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="dir" value="1"><button class="btn secondary small" type="submit" title="+1 Punkt">+</button></form>
            </div>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="small muted">Die Korrektur (+/−) ist der manuelle Vorsitz-Zuschlag und wird eingerechnet (in Klammern angezeigt). Mitglieder sehen ihren eigenen Basis-Score (Wert + Einordnung) auf ihrer Score-Seite.</p>
</div>

<?php if ($canEdit): ?>
<?php
// Unentschuldigt gefehlt nachtragen (Kriterium 2): vergangene Sitzungen seit Zählbeginn
$pastMeetings = db()->prepare("SELECT * FROM meetings WHERE cancelled = 0 AND draft = 0 AND kind != 'stupa'
                               AND date(starts_at) >= ? AND datetime(starts_at) < datetime('now','localtime')
                               ORDER BY starts_at DESC LIMIT 12");
$pastMeetings->execute([$basisSince]);
$pastMeetings = $pastMeetings->fetchAll();
$selNoshow = (int)($_GET['noshow'] ?? 0);
$selMeeting = null;
foreach ($pastMeetings as $pm) if ((int)$pm['id'] === $selNoshow) { $selMeeting = $pm; break; }
if (!$selMeeting && $pastMeetings) $selMeeting = $pastMeetings[0];
$noshowSet = $selMeeting ? meeting_noshows((int)$selMeeting['id']) : [];
$rsvpAbg = $selMeeting ? meeting_rsvp_lists((int)$selMeeting['id'])['abgemeldet'] : [];
?>
<div class="card" id="noshow">
  <div class="section-title" style="margin-top:0"><i class="ti ti-user-x" style="color:var(--red)"></i> Unentschuldigt gefehlt nachtragen</div>
  <?php if (!$pastMeetings): ?>
    <p class="empty"><i class="ti ti-info-circle"></i> Noch keine vergangene Sitzung seit dem Zählbeginn.</p>
  <?php else: ?>
    <form method="get" style="margin-bottom:.7rem">
      <label for="noshow-sel" class="small">Sitzung</label>
      <select id="noshow-sel" name="noshow" onchange="this.form.submit()">
        <?php foreach ($pastMeetings as $pm): ?>
          <option value="<?= (int)$pm['id'] ?>" <?= $selMeeting && (int)$pm['id'] === (int)$selMeeting['id'] ? 'selected' : '' ?>><?= h(meeting_label($pm)) ?> am <?= fmt_date(substr((string)$pm['starts_at'], 0, 10)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($rsvpAbg): ?><p class="small muted" style="margin:0 0 .5rem"><i class="ti ti-door-exit"></i> Abgemeldet (entschuldigt) waren: <?= h(implode(', ', $rsvpAbg)) ?></p><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_noshows">
      <input type="hidden" name="meeting_id" value="<?= (int)$selMeeting['id'] ?>">
      <div class="wl-chips" style="margin:.3rem 0 .8rem">
        <?php foreach ($members as $m): ?>
          <label class="wl-chk">
            <input type="checkbox" name="noshow[]" value="<?= (int)$m['id'] ?>" <?= in_array((int)$m['id'], $noshowSet, true) ? 'checked' : '' ?>>
            <span><i class="ti ti-check"></i><?= h($m['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
      <span class="small muted" style="margin-left:.5rem">Angehakte gelten für diese Sitzung als unentschuldigt gefehlt (−2 im Basis-Score).</span>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Basis-Score zurücksetzen</h2>
  <p class="muted small">Setzt den Zählbeginn des Basis-Scores auf heute und entfernt manuelle Korrekturen (z. B. zu Beginn einer neuen Legislatur). Bisheriger Zählbeginn: <strong><?= fmt_date($basisSince) ?></strong>.</p>
  <form method="post" data-confirm="Basis-Score wirklich zurücksetzen? Ab heute wird neu gezählt, manuelle Korrekturen entfallen." data-confirm-danger data-confirm-ok="Zurücksetzen" data-confirm-title="Basis-Score zurücksetzen">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_basis">
    <button class="btn danger" type="submit"><i class="ti ti-refresh"></i> Basis-Score zurücksetzen</button>
  </form>
</div>
<?php endif; ?>

<?php endif; /* Ende Reiter „Basis-Score" – das Nachtragen der Fehlzeiten gehört dazu */ ?>

<?php if ($tab === 'props' && current_role() === 'admin'): $kd = kudos_admin_overview(); ?>
<div class="section-title" id="props" style="margin-top:1.3rem"><i class="ti ti-hand-love-you"></i> Props <span class="muted" style="font-weight:400;font-size:.8rem">· vertraulich, nur Admin</span></div>
<div class="card">
  <p class="small muted" style="margin:0 0 .7rem">Anonyme <strong>Props</strong> aus dem Mitglieder-Tab. <strong>Nur du als Admin</strong> siehst hier, wer wie viele bekommen hat – ausgeklappt auch von wem. Die Mitglieder selbst erfahren das nie; bitte vertraulich behandeln.</p>
  <?php if (!$kd): ?>
    <p class="small muted" style="margin:0">Bisher wurden keine Props vergeben.</p>
  <?php else: ?>
    <?php foreach ($kd as $row): ?>
      <details class="kudo-adm">
        <summary><span class="kudo-adm-n"><?= (int)$row['count'] ?> 🙌</span> <?= h((string)$row['name']) ?></summary>
        <ul class="kudo-adm-list">
          <?php foreach ($row['givers'] as $g): ?>
            <li><?= h((string)$g['name']) ?><?php if ((int)$g['count'] > 1): ?> <span class="muted">×<?= (int)$g['count'] ?></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card" id="props-anpassen">
  <div class="section-title" style="margin-top:0"><i class="ti ti-adjustments"></i> Props anpassen</div>
  <p class="small muted" style="margin-top:0">Wie bei den Streaks: die <strong>Anzahl</strong> der Props einer Person anpassen. <strong>+1</strong> gibt einen Prop dazu – für die Person <strong>nicht von einem echten Prop unterscheidbar</strong> (sie bekommt den normalen 🙌-Hinweis; in der Auswertung oben steht er als <em>System</em> statt als Name). <strong>−1</strong> bzw. ein fester Wert nimmt welche weg – das passiert <strong>still</strong> (keine Mitteilung), und es werden zuerst System-Props abgebaut, damit <strong>echte Props von Mitgliedern erhalten bleiben</strong>.</p>
  <form method="post" class="btn-row" style="align-items:flex-end;gap:.6rem;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="props_set">
    <label style="margin:0">Person
      <select name="member_id" required>
        <option value="">– wählen –</option>
        <?php foreach (members_all() as $pm): ?><option value="<?= (int)$pm['id'] ?>"><?= h(short_name((string)$pm['name'])) ?> (<?= kudos_received_total((int)$pm['id']) ?> 🙌)</option><?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit" name="mode" value="plus" title="Einen Prop dazugeben – die Person bekommt den normalen Hinweis"><i class="ti ti-plus"></i> 1 Prop</button>
    <button class="btn secondary" type="submit" name="mode" value="minus" title="Einen Prop abziehen (still, ohne Mitteilung)"><i class="ti ti-minus"></i> 1 Prop</button>
    <span class="muted small">oder</span>
    <label style="margin:0">Gesamt auf <input type="number" name="value" value="0" min="0" max="999" style="width:5.5rem"></label>
    <button class="btn secondary" type="submit" name="mode" value="set"><i class="ti ti-adjustments"></i> Setzen</button>
  </form>
</div>
<?php endif; ?>
<?php
page_footer();
