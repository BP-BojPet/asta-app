<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Vorsitz & Admin

// Streaks manuell anpassen – bewusst NUR für die Admin-Rolle (nicht Vorsitz)
$canTuneStreaks = current_role() === 'admin';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canTuneStreaks) {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'streak_set_one') {
        $mid = (int)($_POST['member_id'] ?? 0);
        $val = max(0, (int)($_POST['value'] ?? 0));
        if (streak_admin_set($mid, $val)) {
            flash('Streak von ' . h(short_name((string)(member_get($mid)['name'] ?? ''))) . ' auf ' . $val . ' gesetzt.', 'success');
        } else {
            flash('Bitte eine gültige Person und einen Wert ≥ 0 wählen.', 'error');
        }
        redirect('activity.php?t=streaks');
    }
    if ($action === 'streak_all_delta') {
        $delta = (int)($_POST['delta'] ?? 0);
        if ($delta === 0) { flash('Bitte eine Veränderung ungleich 0 angeben.', 'error'); redirect('activity.php?t=streaks'); }
        $n = 0;
        foreach (members_all() as $mm) {
            $cur = member_streak((int)$mm['id'])['current']; // 0, wenn die Kette tot ist
            if (streak_admin_set((int)$mm['id'], max(0, $cur + $delta))) $n++;
        }
        flash(($delta > 0 ? '+' : '') . $delta . ' Flamme(n) für ' . $n . ' Mitglieder verrechnet.', 'success');
        redirect('activity.php?t=streaks');
    }
}

// Betrachtungsfenster (Wochen) – umschaltbar
$windowOpts = [4, 6, 8, 12, 26, 52];
$window = (int)($_GET['w'] ?? 12);
if (!in_array($window, $windowOpts, true)) $window = 12;
$st = activity_stats($window);

$activeMembers = count($st['members']);
$maxWeek = max(1, max($st['per_week'] ?: [0]));
$today = date('Y-m-d');

// Push-Geräte (Web-Push-Abos je Mitglied) und PWA-Nutzung (Tagesstempel aus dem Standalone-Modus)
$pushCounts = []; $pwaSeen = [];
try {
    foreach (db()->query('SELECT member_id, COUNT(*) c FROM push_subscriptions GROUP BY member_id')->fetchAll() as $r) {
        $pushCounts[(int)$r['member_id']] = (int)$r['c'];
    }
    foreach (db()->query("SELECT id, pwa_last_seen FROM members WHERE pwa_last_seen <> ''")->fetchAll() as $r) {
        $pwaSeen[(int)$r['id']] = (string)$r['pwa_last_seen'];
    }
} catch (\Throwable $e) {}
// Streak-Stil je Mitglied: zeigt in der Tabelle statt der Flamme das passende Icon samt Farbwelt
$streakStyleIds = [];
try {
    foreach (db()->query("SELECT id FROM members WHERE flame_style <> ''")->fetchAll() as $r) {
        // Effektiver Stil über die zentrale Prüfung – nur die kennt auch die GEKAUFTEN Stile
        // (Rakete/Diamant/Regenbogen); '' heißt Flamme bzw. nicht (mehr) freigeschaltet.
        $fs = member_flame_style((int)$r['id']);
        if ($fs !== '') $streakStyleIds[(int)$r['id']] = $fs;
    }
} catch (\Throwable $e) {}
$activeIds = array_map(fn($m) => (int)$m['id'], $st['members']);
$pushMembers = count(array_intersect($activeIds, array_keys($pushCounts)));
$pwaRecent = 0; // PWA in den letzten 30 Tagen genutzt
foreach ($activeIds as $aid) {
    $seen = $pwaSeen[$aid] ?? '';
    if ($seen !== '' && (strtotime($today) - strtotime($seen)) <= 30 * 86400) $pwaRecent++;
}

// „zuletzt aktiv"-Text + Einordnung (frisch / im Fenster / lange her / nie)
$lastInfo = function (string $last) use ($today): array {
    if ($last === '') return ['txt' => 'nie', 'cls' => 'bad'];
    $days = (int)floor((strtotime($today) - strtotime($last)) / 86400);
    if ($days <= 0) $txt = 'heute';
    elseif ($days === 1) $txt = 'gestern';
    else $txt = 'vor ' . $days . ' Tagen';
    $cls = $days <= 7 ? 'ok' : ($days <= 31 ? '' : 'bad');
    return ['txt' => $txt, 'cls' => $cls, 'days' => $days];
};

// Sortierung: zuletzt aktiv zuerst (nie ganz nach unten)
usort($st['members'], function ($a, $b) {
    $la = $a['last_active_on'] ?: '0000-00-00';
    $lb = $b['last_active_on'] ?: '0000-00-00';
    return strcmp($lb, $la);
});

/* REITER: Die Statistik liest man, das Anpassen der Streaks ist ein
   Kulanz-Werkzeug – zwei ganz verschiedene Anlässe. Der zweite Reiter erscheint nur für die
   Admin-Rolle, die das darf; der Abschnitt selbst ist nicht verschoben, nur gehüllt. */
$tabs = ['statistik' => ['label' => 'Statistik', 'icon' => 'ti-chart-bar']];
if ($canTuneStreaks) $tabs['streaks'] = ['label' => 'Streaks anpassen', 'icon' => 'ti-flame'];
$tab = (string)($_GET['t'] ?? '');
if (!isset($tabs[$tab])) $tab = 'statistik';

page_header('Aktivitätsstatistik', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-activity-heartbeat" style="color:var(--petrol)"></i> Aktivitätsstatistik</h1>
</div>
<?php if (count($tabs) > 1): ?>
<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($tabs as $tk => $td): ?>
    <a<?= $tk === $tab ? ' class="on" aria-current="page"' : '' ?> href="activity.php?t=<?= h($tk) ?><?= $tk === 'statistik' ? '&amp;w=' . (int)$window : '' ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($tab === 'statistik'): ?>
<p class="muted">Wer öffnet die App wie regelmäßig? Erfasst wird pro Mitglied, in welchen <strong>Kalenderwochen</strong> die App geöffnet wurde (dasselbe fließt in den Basis-Score ein). Rein informativ – keine Bewertung.</p>

<div class="tiles" style="margin-bottom:1rem">
  <div class="tile accent-green">
    <i class="ti ti-calendar-week"></i>
    <div class="num"><?= (int)$st['this_week'] ?></div><div class="lbl">diese Woche aktiv<?= $activeMembers ? ' · ' . (int)round($st['this_week'] / $activeMembers * 100) . '%' : '' ?></div>
  </div>
  <div class="tile">
    <i class="ti ti-users"></i>
    <div class="num"><?= (int)$st['ever_active'] ?>/<?= (int)$activeMembers ?></div><div class="lbl">je aktiv gewesen</div>
  </div>
  <div class="tile">
    <i class="ti ti-history"></i>
    <div class="num"><?= (int)$window ?></div><div class="lbl">Wochen im Blick</div>
  </div>
  <div class="tile">
    <i class="ti ti-bell-ringing"></i>
    <div class="num"><?= $pushMembers ?>/<?= $activeMembers ?></div><div class="lbl">mit Push-Gerät</div>
  </div>
  <div class="tile">
    <i class="ti ti-device-mobile"></i>
    <div class="num"><?= $pwaRecent ?></div><div class="lbl">PWA genutzt (30 Tage)</div>
  </div>
</div>

<div class="btn-row" style="margin:-.4rem 0 1rem">
  <span class="small muted" style="align-self:center;margin-right:.2rem">Zeitraum:</span>
  <?php foreach ($windowOpts as $w): ?>
    <a class="btn small <?= $w === $window ? '' : 'secondary' ?>" href="activity.php?w=<?= $w ?>"><?= $w ?> Wochen</a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-chart-bar"></i> Aktive Mitglieder je Woche</div>
  <?php $labelEvery = (int)max(1, ceil($window / 13)); // bei vielen Wochen nur jedes n-te KW-Label zeigen ?>
  <div class="act-bars">
    <?php foreach ($st['weeks'] as $i => $wk): $c = (int)$st['per_week'][$wk]; $isNow = $i === count($st['weeks']) - 1;
        $showLabel = $isNow || ($i % $labelEvery === 0); ?>
      <div class="act-bar<?= $isNow ? ' now' : '' ?>" title="<?= h(iso_week_label($wk)) ?>: <?= $c ?> aktiv">
        <div class="n"><?= $showLabel ? $c : '' ?></div>
        <div class="b" style="height:<?= (int)round($c / $maxWeek * 100) ?>%"></div>
        <div class="k"><?= $showLabel ? h(preg_replace('/^KW /', '', iso_week_label($wk))) : '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="small muted" style="margin:.2rem 0 0">Zahl der Mitglieder, die die App in der jeweiligen Kalenderwoche mindestens einmal geöffnet haben. Grün = laufende Woche.</p>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-list-details"></i> Je Mitglied</div>
  <div style="overflow-x:auto">
    <table class="list">
      <thead>
        <tr>
          <th>Mitglied</th>
          <th>Zuletzt aktiv</th>
          <th style="text-align:center" title="Eingerichtete Push-Geräte (Web-Push-Abos)">Push</th>
          <th style="text-align:center" title="App als PWA installiert genutzt (Standalone-Modus; Stempel beim Öffnen)">PWA</th>
          <th style="text-align:center" title="Aktuelle Tages-Streak (Flammen; die Kette reißt erst nach 5 Tagen Pause)">Streak</th>
          <th style="text-align:center">Letzte <?= (int)$window ?> Wochen</th>
          <th style="text-align:right">Aktiv</th>
          <th style="text-align:right" title="Erfasste Wochen insgesamt">Gesamt</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($st['members'] as $m): $li = $lastInfo($m['last_active_on']); ?>
          <tr>
            <td><strong><?= h($m['name']) ?></strong></td>
            <td class="<?= $li['cls'] === 'ok' ? 'act-ok' : ($li['cls'] === 'bad' ? 'act-bad' : '') ?>"><?= h($li['txt']) ?></td>
            <td style="text-align:center">
              <?php $pc = (int)($pushCounts[(int)$m['id']] ?? 0); ?>
              <?php if ($pc > 0): ?><span class="act-ok" title="<?= $pc ?> Push-Gerät<?= $pc === 1 ? '' : 'e' ?> eingerichtet"><i class="ti ti-bell-ringing"></i><?= $pc > 1 ? ' ' . $pc : '' ?></span>
              <?php else: ?><span class="muted" title="Kein Push-Gerät eingerichtet">–</span><?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php $ps = $pwaSeen[(int)$m['id']] ?? '';
                    $pDays = $ps !== '' ? (int)floor((strtotime($today) - strtotime($ps)) / 86400) : -1; ?>
              <?php if ($ps === ''): ?><span class="muted" title="Noch nie im installierten Modus gesehen">–</span>
              <?php else: ?><span class="<?= $pDays <= 30 ? 'act-ok' : 'muted' ?>" title="Zuletzt als installierte App genutzt: <?= h(date('d.m.Y', strtotime($ps))) ?>"><i class="ti ti-device-mobile"></i></span><?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php $sc = (int)$m['streak']; $stStyle = $streakStyleIds[(int)$m['id']] ?? '';
                    if ($sc > 0): $sTier = max(1, streak_tier($sc)); ?>
                <span class="act-streak t<?= $sTier ?><?= $stStyle ? ' ' . h($stStyle) . 'line' : '' ?>" title="Streak: <?= $sc ?> <?= h(flame_style_word($stStyle, $sc)) ?> · Bestwert: <?= (int)$m['streak_best'] ?>"><?= flame_style_mini($stStyle) ?><?= $sc ?></span>
              <?php else: ?>
                <span class="muted" title="Keine laufende Streak<?= (int)$m['streak_best'] > 0 ? ' · Bestwert: ' . (int)$m['streak_best'] : '' ?>">–</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <span class="act-spark<?= $window > 26 ? ' dense' : '' ?>">
                <?php foreach ($st['weeks'] as $i => $wk): $on = !empty($m['active'][$wk]); $isNow = $i === count($st['weeks']) - 1; ?>
                  <span class="act-dot<?= $on ? ' on' : '' ?><?= $isNow ? ' now' : '' ?>" title="<?= h(iso_week_label($wk)) ?><?= $on ? ' · aktiv' : '' ?>"></span>
                <?php endforeach; ?>
              </span>
            </td>
            <td style="text-align:right"><strong><?= (int)$m['in_window'] ?></strong>/<?= (int)$window ?></td>
            <td style="text-align:right" class="muted"><?= (int)$m['total'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted" style="margin:.5rem 0 0"><span class="act-dot on" style="vertical-align:middle"></span> aktive Woche · Punkt mit Ring = laufende Woche. <i class="ti ti-flame" style="color:#e8681a;vertical-align:middle"></i> „Streak" = gesammelte Flammen (je mehr, desto heißer; die Kette reißt erst nach 5 Tagen Pause) – das Icon wechselt bei Mitgliedern, die ihren Streak-Stil umgestellt haben:
    <?php // Legende datengetrieben über die Stil-Registry – neue Stile erscheinen hier von selbst
      $legendName = ['heart' => 'Herz', 'bolt' => 'Blitz', 'star' => 'Stern', 'coffee' => 'Kaffee',
                     'flower' => 'Sempervivium', 'mond' => 'Mondphasen', 'schein' => 'Heiligenschein',
                     'rakete' => 'Rakete', 'diamant' => 'Diamant', 'regenbogen' => 'Regenbogen'];
      $legendColor = ['heart' => '#ef4b9c', 'bolt' => '#2f7ff0', 'star' => '#f0b600', 'coffee' => '#a5683c',
                      'flower' => '#9457e0', 'mond' => '#6d87d6', 'schein' => '#ddb022',
                      'rakete' => '#e85a14', 'diamant' => '#00a6e8', 'regenbogen' => '#9a36d4'];
      $legendTeile = [];
      foreach (flame_styles_all() as $lsk) {
          $legendTeile[] = '<i class="ti ' . h(flame_style_icon($lsk)) . '" style="color:' . h($legendColor[$lsk] ?? 'var(--muted)') . ';vertical-align:middle"></i> ' . h($legendName[$lsk] ?? $lsk);
      }
      echo implode(', ', $legendTeile); ?>. „Aktiv" = aktive Wochen im gewählten Zeitraum, „Gesamt" = alle je erfassten Wochen. <i class="ti ti-bell-ringing" style="vertical-align:middle"></i> „Push" = eingerichtete Push-Geräte (Zahl bei mehreren). <i class="ti ti-device-mobile" style="vertical-align:middle"></i> „PWA" = nutzt die App installiert (Standalone-Modus, Datum im Tooltip; grün = innerhalb 30 Tagen, grau = länger her). Der PWA-Stempel entsteht erst beim nächsten Öffnen nach diesem Update – die Spalte füllt sich also nach und nach.</p>
</div>

<?php endif; /* Ende Reiter „Statistik" */ ?>

<?php if ($tab === 'streaks' && $canTuneStreaks): ?>
<div class="section-title" id="streaks-anpassen" style="margin-top:.4rem"><i class="ti ti-flame"></i> Streaks anpassen <span class="muted small" style="font-weight:400">– nur Admin</span></div>
<div class="card">
  <p class="small muted" style="margin-top:0">Manuelle Korrektur der Tages-Streaks – z. B. als Kulanz nach technischen Problemen. Der <strong>Bestwert wird nie gesenkt</strong>; bei einem neuen Wert &gt; 0 lebt die Kette sofort weiter (die nächste Tages-Flamme zählt normal obendrauf), <strong>0 löscht die Kette</strong>.</p>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-users"></i> Alle Mitglieder</div>
  <form method="post" class="btn-row" style="align-items:flex-end;gap:.6rem;flex-wrap:wrap" data-confirm="Die Streaks ALLER aktiven Mitglieder um diesen Wert verändern?" data-confirm-ok="Anpassen">
    <?= csrf_field() ?><input type="hidden" name="action" value="streak_all_delta">
    <div style="min-width:160px"><label for="sa_delta" class="small">Veränderung (+/− Flammen)</label><input type="number" name="delta" id="sa_delta" step="1" required placeholder="z. B. 3 oder -1"></div>
    <button class="btn secondary" type="submit"><i class="ti ti-flame"></i> Für alle verrechnen</button>
  </form>
  <p class="small muted" style="margin:.4rem 0 0">Wird auf die aktuell <strong>lebende</strong> Streak angerechnet (tote Ketten zählen als 0); unter 0 fällt niemand.</p>

  <div class="section-title" style="font-size:1.02rem;margin-top:1rem"><i class="ti ti-user-edit"></i> Einzelnes Mitglied</div>
  <form method="post" class="btn-row" style="align-items:flex-end;gap:.6rem;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="streak_set_one">
    <div style="min-width:200px"><label for="sa_member" class="small">Person</label>
      <select name="member_id" id="sa_member" required>
        <option value="">— Person wählen —</option>
        <?php foreach ($st['members'] as $mm): ?>
          <option value="<?= (int)$mm['id'] ?>"><?= h($mm['name']) ?> (aktuell <?= (int)$mm['streak'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="min-width:140px"><label for="sa_value" class="small">Neuer Wert (0 = Kette löschen)</label><input type="number" name="value" id="sa_value" min="0" step="1" required></div>
    <button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Setzen</button>
  </form>
</div>
<?php endif; ?>
<?php
page_footer();
