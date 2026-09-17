<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin();

// AsT verschenken (Vorsitz + Admin): Buchung + feierliche Dashboard-Ankündigung an die Person
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ast_grant') {
    check_csrf();
    $cm = current_member();
    $amt = (int)($_POST['amount'] ?? 0);
    if (ast_grant((int)($_POST['member_id'] ?? 0), $amt, (string)($_POST['reason'] ?? ''), $cm ? (int)$cm['id'] : 0)) {
        flash(number_format($amt, 0, ',', '.') . ' AsT verschenkt – die Person bekommt eine Feier-Nachricht auf ihr Dashboard. 🎉', 'success');
    } else {
        flash('Verschenken fehlgeschlagen – aktive Person wählen und einen Betrag zwischen 1 und 100.000 AsT angeben.', 'error');
    }
    redirect('achievements.php#ast');
}

$catalog = achievements_catalog();
$tiers   = achievement_tier_meta();
$skins   = app_skins();
$pals    = avatar_palettes();
$total   = count($catalog);

// Aktive Mitglieder samt Ausrüstung (Schmuck/Farbe/Streak-Stil sind Server-Zustand)
$members = db()->query('SELECT id, name, referat, avatar_decos, avatar_palette, avatar_ink, flame_style FROM members WHERE active = 1 ORDER BY name COLLATE NOCASE')->fetchAll();
$activeIds = array_flip(array_map(fn($m) => (int)$m['id'], $members));

// Achievements aller (aktiven) Mitglieder in einer Query einsammeln
$byMember = []; $byCode = [];
foreach (db()->query('SELECT member_id, code FROM member_achievements')->fetchAll() as $r) {
    $mid = (int)$r['member_id']; $code = (string)$r['code'];
    if (!isset($activeIds[$mid]) || !isset($catalog[$code])) continue; // Inaktive/entfernte Codes zählen nicht
    $byMember[$mid][] = $code;
    $byCode[$code] = ($byCode[$code] ?? 0) + 1;
}

// Labels für Schmuck: Katalog-Belohnungen + Rollen-Schmuck Geldsack + Namens-Schmuck
// Namens-Schmuck (Tasse) + alles Kaufbare aus dem AsT-Sortiment
$decoLabels = ['moneybag' => 'Geldsack 💰',
               'tasse'    => deco_tasse_label((string)setting_get('deco_tasse_name', 'Lotta Schwarz'))];
foreach (shop_items() as $sk => $si) {
    if (($si['type'] ?? '') === 'deco') $decoLabels[$sk] = (string)$si['label'];
}
foreach ($catalog as $code => $a) {
    $rs = (array)($a['rewards'] ?? []); if (!empty($a['reward'])) $rs[] = $a['reward'];
    foreach ($rs as $rw) {
        if (($rw['type'] ?? '') === 'deco') $decoLabels[$rw['key']] = $rw['label'];
    }
}

// Streak-Stile: ALLE aus der Registry (verdiente + kaufbare). Ob ein gewählter Stil wirklich
// zählt, entscheidet member_flame_style() – dieselbe Prüfung wie überall in der App
// (Achievement noch gehalten bzw. gekauft, sonst Rückfall auf die Flamme).
$styleLabels = [
    'heart'      => 'Herz 💗 (Pretty in Pink)',
    'bolt'       => 'Blitz ⚡ (50er-Streak)',
    'star'       => 'Stern ⭐ (Spitzenklasse)',
    'coffee'     => 'Kaffee ☕ (Touch Grass)',
    'flower'     => 'Sempervivium 🌼 (Jahresringe)',
    'mond'       => 'Mondphasen 🌙 (Mondsucht)',
    'schein'     => 'Heiligenschein 😇 (Technik-Engel)',
    'rakete'     => 'Rakete 🚀 (gekauft)',
    'diamant'    => 'Diamant 💎 (gekauft)',
    'regenbogen' => 'Regenbogen 🌈 (gekauft)',
];
$styleEq = [];
foreach ($styleLabels as $sk => $lbl) $styleEq[$sk] = preg_replace('/^(\S+) (\S+) \(.*\)$/u', '$1-Streak $2', $lbl); // kurz für „Ausgerüstet"
$effStyle = fn(array $m): string => member_flame_style((int)$m['id']); // effektiver Stil oder '' (Flamme)

// Nutzungs-Statistik: was ist gerade ausgerüstet? (Schmuck ist eine Komma-Liste – ein Stück je Slot)
// Dazu je App-Skin: wie viele haben ihn freigeschaltet? (frei für alle · Royal-Status ·
// verdiente/geschenkte Skins via member_reward_skins · Namens-Geheimskins)
$decoUse = []; $palUse = []; $styleUse = []; $decoWearers = 0;
$skinUnlocks = array_fill_keys(array_keys($skins), 0);
foreach ($members as $m) {
    $mid = (int)$m['id'];
    $worn = array_filter(array_map('trim', explode(',', (string)$m['avatar_decos'])));
    if ($worn) $decoWearers++;
    foreach ($worn as $d) $decoUse[$d] = ($decoUse[$d] ?? 0) + 1;
    if (($p = trim((string)$m['avatar_palette'])) !== '' && isset($pals[$p])) $palUse[$p] = ($palUse[$p] ?? 0) + 1;
    if (($sk = $effStyle($m)) !== '') $styleUse[$sk] = ($styleUse[$sk] ?? 0) + 1;
    $rSkins = member_reward_skins($mid);
    foreach ($skins as $k => $s) {
        if (!empty($s['frei']) || isset($rSkins[$k])
            || ($k === 'royal' && in_array('spitze', $byMember[$mid] ?? [], true))
            || member_first_name_is($mid, (string)(skin_secret()[$k] ?? ''))) $skinUnlocks[$k]++;
    }
}
arsort($decoUse); arsort($palUse); arsort($skinUnlocks);
$styleSpecial = array_sum($styleUse); // Mitglieder mit einem Sonder-Streak-Stil (nicht Flamme)

// Achievement-Ranking + nie geholte
$rank = $byCode; arsort($rank);
$never = array_diff_key($catalog, $byCode);
$sumEarned = array_sum($byCode);
$avg = $members ? $sumEarned / count($members) : 0;

// Mitglieder nach Anzahl sortieren (interessanteste zuerst), Gleichstand alphabetisch
usort($members, function ($a, $b) use ($byMember) {
    $ca = count($byMember[(int)$a['id']] ?? []); $cb = count($byMember[(int)$b['id']] ?? []);
    return $cb <=> $ca ?: strcasecmp((string)$a['name'], (string)$b['name']);
});

page_header('Achievements-Übersicht', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-trophy" style="color:var(--petrol)"></i> Achievements-Übersicht</h1>
</div>
<p class="muted">Wer hat welche Erfolge, und welche Belohnungen werden getragen? Achievements haben keinen Einfluss auf Einteilung oder Score. Auch <strong>geheime</strong> Achievements sind hier sichtbar (🤫-Markierung) – nicht spoilern!</p>

<div class="card" id="ast">
  <div class="section-title" style="margin-top:0"><i class="ti ti-coin"></i> AsT verschenken</div>
  <p class="small muted" style="margin-top:0">Legt der Person AsT ins <a href="../tauschboerse.php#depot">Schicht-Depot</a> – als Anerkennung, Preis oder einfach so. Sie bekommt eine <strong>Feier-Nachricht</strong> auf ihr Dashboard (mit deinem Namen und dem Anlass) und kann sich damit im Belohnungs-Locker etwas gönnen.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="ast_grant">
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
      <div>
        <label for="ast_member" class="small">Person</label>
        <select name="member_id" id="ast_member" required style="min-width:12rem">
          <option value="">– wählen –</option>
          <?php foreach (db()->query("SELECT id, name FROM members WHERE active = 1 ORDER BY name COLLATE NOCASE")->fetchAll() as $am): ?>
            <option value="<?= (int)$am['id'] ?>"><?= h((string)$am['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="ast_amount" class="small">Betrag (AsT)</label>
        <input type="number" name="amount" id="ast_amount" min="1" max="100000" required style="width:8rem" placeholder="z. B. 500">
      </div>
      <div style="flex:1;min-width:14rem">
        <label for="ast_reason" class="small">Anlass <span class="muted">(steht in der Nachricht)</span></label>
        <input type="text" name="reason" id="ast_reason" maxlength="160" placeholder="z. B. für die O-Wochen-Orga 💪">
      </div>
      <button class="btn" type="submit"><i class="ti ti-gift"></i> Verschenken</button>
    </div>
  </form>
</div>

<div class="tiles" style="margin-bottom:1rem">
  <div class="tile accent-green"><i class="ti ti-trophy"></i>
    <div class="num"><?= number_format($avg, 1, ',', '.') ?></div><div class="lbl">Erfolge pro Kopf (Ø von <?= $total ?>)</div></div>
  <div class="tile"><i class="ti ti-mood-smile"></i>
    <div class="num"><?= $decoWearers ?></div><div class="lbl">tragen Avatar-Schmuck (<?= array_sum($decoUse) ?> Stücke)</div></div>
  <div class="tile"><i class="ti ti-color-swatch"></i>
    <div class="num"><?= count($palUse) ? array_sum($palUse) : 0 ?></div><div class="lbl">haben eine Avatar-Farbe gewählt</div></div>
  <div class="tile"><i class="ti ti-sparkles"></i>
    <div class="num"><?= $styleSpecial ?></div><div class="lbl">Sonder-Streak-Stil aktiv ✨</div></div>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-users"></i> Wer hat was</div>
  <div class="matrix-wrap">
    <table class="achadm-table">
      <thead><tr><th>Mitglied</th><th>Erfolge</th><th>Ausgerüstet</th><th>Achievements</th></tr></thead>
      <tbody>
      <?php foreach ($members as $m): $mid = (int)$m['id']; $codes = $byMember[$mid] ?? [];
          $eq = [];
          $accs = ''; $seen = []; // getragener Schmuck AM Avatar (max. eins je Slot – wie avatar_chip)
          foreach (array_filter(array_map('trim', explode(',', (string)$m['avatar_decos']))) as $d) {
              $eq[] = $decoLabels[$d] ?? $d;
              if (avatar_deco_svg($d) !== '') $seen[avatar_deco_slot($d)] = $d;
          }
          foreach ($seen as $slot => $d) $accs .= '<span class="avatar-acc acc-' . $slot . ' acc-k-' . h($d) . '">' . avatar_deco_svg($d) . '</span>';
          if (($p = trim((string)$m['avatar_palette'])) !== '' && isset($pals[$p])) $eq[] = $pals[$p]['label'];
          if (($sk = $effStyle($m)) !== '') $eq[] = $styleEq[$sk] ?? $sk; ?>
        <tr>
          <td><span class="achadm-who"><span class="avatar-mini<?= member_avatar_class($m) ?>" style="<?= h(member_avatar_style($m)) ?>;background:<?= h(member_avatar_gradient($m)) ?>"><span class="av-ini"><?= h(mb_substr(first_name((string)$m['name']), 0, 1) ?: '?') ?></span><?= $accs ?></span><span class="achadm-who-name"><?= h($m['name']) ?></span></span></td>
          <td><strong><?= count($codes) ?></strong><span class="muted small">/<?= $total ?></span></td>
          <td class="small"><?= $eq ? h(implode(' · ', $eq)) : '<span class="muted">–</span>' ?></td>
          <td>
            <?php if (!$codes): ?><span class="muted small">noch keine</span>
            <?php else: ?>
            <div class="achadm-chips">
              <?php foreach ($catalog as $code => $a): if (!in_array($code, $codes, true)) continue;
                  $col = $tiers[$a['tier']]['color'] ?? '#888'; ?>
                <span class="achadm-chip" style="--achc:<?= h($col) ?>" title="<?= h($a['title'] . (!empty($a['hidden']) ? ' (geheim)' : '') . ' – ' . $a['desc']) ?>"><i class="ti <?= h($a['icon']) ?>"></i></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted" style="margin:.4rem 0 0">Chips in der Stufenfarbe (Bronze/Silber/Gold/Legendär) – Titel &amp; Kriterium per Tooltip. Dynamische Status-Achievements (Spitzenklasse, Tragende Säule) können wieder verschwinden.</p>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-chart-bar"></i> Beliebteste Belohnungen</div>
  <div class="grid-2">
    <div>
      <div class="small" style="font-weight:700;margin-bottom:.3rem"><i class="ti ti-mood-smile"></i> Getragener Avatar-Schmuck</div>
      <?php if (!$decoUse): ?><p class="empty">Niemand trägt gerade Schmuck.</p><?php else: ?>
        <?php foreach ($decoUse as $k => $n): ?>
          <div class="achadm-bar"><span><?= h($decoLabels[$k] ?? $k) ?></span><strong><?= $n ?>×</strong></div>
        <?php endforeach; ?>
      <?php endif; ?>
      <div class="small" style="font-weight:700;margin:.8rem 0 .3rem"><i class="ti ti-flame"></i> Streak-Stil</div>
      <div class="achadm-bar"><span>Flamme 🔥</span><strong><?= count($members) - $styleSpecial ?>×</strong></div>
      <?php foreach ($styleLabels as $sk => $lbl): ?>
        <div class="achadm-bar"><span><?= $lbl ?></span><strong><?= $styleUse[$sk] ?? 0 ?>×</strong></div>
      <?php endforeach; ?>
    </div>
    <div>
      <div class="small" style="font-weight:700;margin-bottom:.3rem"><i class="ti ti-color-swatch"></i> Gewählte Avatar-Farben</div>
      <div class="achadm-bar"><span>Auto (Namens-Verlauf)</span><strong><?= count($members) - ($palUse ? array_sum($palUse) : 0) ?>×</strong></div>
      <?php foreach ($palUse as $k => $n): ?>
        <div class="achadm-bar"><span><?= h($pals[$k]['label']) ?></span><strong><?= $n ?>×</strong></div>
      <?php endforeach; ?>
      <div class="small" style="font-weight:700;margin:.8rem 0 .3rem"><i class="ti ti-palette"></i> Freigeschaltete App-Skins</div>
      <?php foreach ($skinUnlocks as $k => $n): $s = $skins[$k]; ?>
        <div class="achadm-bar"><span><i class="ti <?= h($s['icon']) ?>"></i> <?= h($s['label']) ?><?= !empty($s['frei']) ? ' <span class="muted">(frei für alle)</span>' : '' ?></span><strong><?= $n ?>×</strong></div>
      <?php endforeach; ?>
      <p class="small muted" style="margin:.3rem 0 0">Welcher Skin gerade <em>aktiv</em> ist, entscheidet jedes Gerät lokal (Glühbirne) – das lässt sich serverseitig nicht zählen; gezählt sind Freischaltungen.</p>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-list-numbers"></i> Achievement-Ranking</div>
  <div class="grid-2">
    <div>
      <div class="small" style="font-weight:700;margin-bottom:.3rem">Am häufigsten geholt</div>
      <?php $i = 0; foreach ($rank as $code => $n): $a = $catalog[$code]; if (++$i > 15) break;
          $col = $tiers[$a['tier']]['color'] ?? '#888'; ?>
        <div class="achadm-bar"><span><i class="ti <?= h($a['icon']) ?>" style="color:<?= h($col) ?>"></i> <?= h($a['title']) ?><?= !empty($a['hidden']) ? ' 🤫' : '' ?></span><strong><?= $n ?>×</strong></div>
      <?php endforeach; ?>
      <?php if (!$rank): ?><p class="empty">Noch keine Achievements vergeben.</p><?php endif; ?>
    </div>
    <div>
      <div class="small" style="font-weight:700;margin-bottom:.3rem">Noch nie geholt (<?= count($never) ?>)</div>
      <?php foreach ($never as $code => $a): $col = $tiers[$a['tier']]['color'] ?? '#888'; ?>
        <div class="achadm-bar"><span><i class="ti <?= h($a['icon']) ?>" style="color:<?= h($col) ?>"></i> <?= h($a['title']) ?><?= !empty($a['hidden']) ? ' 🤫' : '' ?></span><span class="muted small"><?= h($tiers[$a['tier']]['label'] ?? '') ?></span></div>
      <?php endforeach; ?>
      <?php if (!$never): ?><p class="empty">Alles wurde schon mindestens einmal geholt. 🎉</p><?php endif; ?>
    </div>
  </div>
</div>
<?php page_footer(); ?>
