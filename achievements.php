<?php
require __DIR__ . '/lib.php';
db();
require_login();

$me   = current_member();
if (!$me) { redirect('dashboard.php'); } // Technik-Login hat kein persönliches Konto → keine Achievements
$meId = (int)$me['id'];

// Avatar-Schmuck ausrüsten / entfernen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'equip_deco') {
    check_csrf();
    $key = trim((string)($_POST['deco'] ?? ''));
    // Locker-Klick (toggle=1) legt ein bereits getragenes Stück wieder ab; der „Ausprobieren"-Knopf
    // aus dem Toast rüstet immer aus. Slots (Kopf/Hand/Hals/Gesicht) sind kombinierbar.
    $isToggle = ($_POST['toggle'] ?? '') === '1';
    if ($key !== '' && $isToggle && in_array($key, member_equipped_decos($meId), true)) {
        $ok = member_unequip_deco($meId, $key);
        $doneMsg = 'Schmuckstück abgelegt.';
    } else {
        $ok = member_equip_deco($meId, $key);
        $doneMsg = $key === '' ? 'Aller Avatar-Schmuck abgelegt.' : 'Ausgerüstet – andere Trage-Positionen bleiben an.';
    }
    if ($ok) prinz_swap_tick($meId); // Prinzessin/Prinz: >4 Wechsel in einer Minute
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') { // „Ausprobieren" aus dem Toast: still 204, UI erledigt das JS
        http_response_code($ok ? 204 : 400);
        exit;
    }
    flash($ok ? $doneMsg : 'Dieses Schmuckstück ist (noch) nicht freigeschaltet.', $ok ? 'success' : 'error');
    redirect('achievements.php');
}

// Avatar-Farbe (Palette) wählen / zurücksetzen
// Schriftfarbe der Initialen. Ohne Freischaltung – reine Geschmacks- und Lesbarkeitsfrage.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'equip_ink') {
    check_csrf();
    $ok = member_set_ink($meId, (string)($_POST['ink'] ?? ''));
    flash($ok ? 'Schriftfarbe gespeichert.' : 'Diese Schriftfarbe gibt es nicht.', $ok ? 'success' : 'error');
    redirect('achievements.php#locker');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'equip_palette') {
    check_csrf();
    $ok = member_set_palette($meId, (string)($_POST['palette'] ?? ''));
    if ($ok) prinz_swap_tick($meId); // Farbwechsel zählen mit für Prinzessin/Prinz
    flash($ok ? 'Avatar-Farbe gespeichert.' : 'Diese Farbe ist (noch) nicht freigeschaltet.', $ok ? 'success' : 'error');
    redirect('achievements.php');
}

// Streak-Stil: Flamme oder Herz („Pretty in Pink"-Belohnung; member_set_flame_style validiert die Freischaltung)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'flame_style') {
    check_csrf();
    $ok = member_set_flame_style($meId, (string)($_POST['style'] ?? ''));
    flash($ok ? 'Streak-Stil gespeichert.' : 'Dieser Streak-Stil ist (noch) nicht freigeschaltet.', $ok ? 'success' : 'error');
    redirect('achievements.php');
}

// Kaufbarer Schmuck: kaufen (AsT aus dem Schicht-Depot) oder als Royal beschlagnahmen (alle 3 Monate eine Sache).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['shop_buy', 'shop_claim'], true)) {
    check_csrf();
    $item = trim((string)($_POST['item'] ?? ''));
    // Drei Wege zum selben Stück; welcher es war, sagt „pay" aus dem Dialog. „shop_claim" bleibt
    // als eigene Aktion bestehen (alte Links/Formulare), zahlt aber ebenfalls über die Krone.
    $weg = (string)($_POST['pay'] ?? '');
    if ($_POST['action'] === 'shop_claim') $weg = 'royal';
    $r = $weg === 'royal' ? shop_claim($meId, $item)
       : ($weg === 'props' ? shop_buy_props($meId, $item) : shop_buy($meId, $item));
    flash($r['msg'], $r['ok'] ? 'success' : 'error');
    redirect('achievements.php#locker');
}

// Sommer verschenken: König:in des Sommers darf bis zu zwei Helfer:innen den „Hochsommer"-Skin schenken (mit Nachricht).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gift_sommer') {
    check_csrf();
    $targets = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['to'] ?? [])))));
    $msgs = (array)($_POST['msg'] ?? []); // pro Empfänger:in eine eigene Nachricht: msg[<member-id>]
    $quota = sommer_gift_quota($meId);
    if ($quota < 1) {
        flash('Du hast keine Sommer-Geschenke mehr übrig.', 'error');
    } elseif (!$targets) {
        flash('Bitte wähle mindestens eine Person aus.', 'error');
    } else {
        $targets = array_slice($targets, 0, $quota); // nie mehr verschenken als das Kontingent hergibt
        $sent = 0;
        foreach ($targets as $tid) if (sommer_gift($meId, $tid, trim((string)($msgs[$tid] ?? '')))) $sent++;
        flash($sent > 0
            ? ($sent === 1 ? 'Du hast den Hochsommer verschenkt. ☀️' : "Du hast den Hochsommer an $sent Personen verschenkt. ☀️")
            : 'Das hat nicht geklappt – vielleicht haben die Ausgewählten den Sommer schon.', $sent > 0 ? 'success' : 'error');
    }
    redirect('achievements.php');
}

// Client-ausgelöstes Achievement (feste Whitelist) – z. B. „flashbang" beim Umschalten in den Hellmodus nachts.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fire') {
    check_csrf();
    $code = (string)($_POST['code'] ?? '');
    if (in_array($code, ['flashbang', 'chameleon'], true)) achievement_unlock($meId, $code);
    if ($code === 'pinkdream') { // Client meldet aktiven Pink-Skin; Kopfschmuck + rosa Farbe prüft der Server nach
        if (pinkdream_head_ok($meId) && in_array(member_palette($meId), ['cuteness', 'glitzer'], true)) {
            achievement_unlock($meId, 'pinkdream');
        }
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') { // Toast-Daten direkt zurück, damit sie ohne Reload erscheinen
        header('Content-Type: application/json');
        echo json_encode(['unlocks' => take_ach_unlocks()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect('achievements.php');
}

// Beim Öffnen fällige Achievements gleich mitnehmen (idempotent, Toast bei Neuem)
achievements_evaluate($meId);

$catalog  = achievements_catalog();
$earned   = array_flip(member_achievement_codes($meId));
$tiers    = achievement_tier_meta();
$streak   = member_streak($meId);
$decos    = member_reward_decos($meId);      // key => Label (freigeschaltet)
$skins    = member_reward_skins($meId);
$equipped = member_equipped_decos($meId);   // slot => key (mehrere Positionen gleichzeitig möglich)
$palettes  = member_palettes($meId);         // wählbare Avatar-Farbverläufe
$myPalette = member_palette($meId);
$myGrad    = member_avatar_gradient($me);    // aktueller Verlauf für alle Vorschau-Bubbles
$myPalClass = member_avatar_class($me);      // legendäre Paletten animieren auch die Vorschau (pal-<key>), Verlaufs-Ink hängt mit dran (ink-<key>)
$myInk     = member_ink($meId);              // Schriftfarbe der Initialen ('' = Vorgabe)
$inkVar    = fn(string $k) => $k !== '' && isset(avatar_inks()[$k]) ? ';--av-ink:' . avatar_inks()[$k]['color'] : '';
// Für den Schriftfarben-Wähler: NUR die Paletten-Klasse, ohne meine aktuelle Ink-Klasse – jede
// Kachel zeigt ja eine ANDERE Kandidatin, meine Verlaufs-Schrift würde alle Vorschauen überdecken.
$palOnlyClass = member_avatar_class(['avatar_palette' => $myPalette]);
$inkClass  = fn(string $k) => !empty(avatar_inks()[$k]['grad']) ? ' ink-' . $k : '';
// Kaufbarer Schmuck: bezahlt mit dem Depotwert (AsT) des Schicht-Depots aus der Tauschbörse.
// Die Stücke stehen ganz normal im Avatar-Schmuck-Locker, nur eben mit Preisschild.
$astKonto   = ast_balance($meId);
$propsFrei  = props_available($meId);   // erhaltene minus schon eingelöste Props
$asTaler    = fn (int $v) => number_format($v, 0, ',', '.') . ' AsT';
$royalJetzt = royal_unlocked() && !royal_test_active(); // Beschlagnahmen nur für ECHTE Royals
$claimFrei  = $royalJetzt && royal_claim_free($meId);
$claimAb    = royal_claim_last($meId) !== '' ? date('d.m.Y', strtotime(royal_claim_last($meId) . ' +3 months')) : '';
// Freigeschaltete Sonder-Streak-Stile (Stil-Key => true). Die Prüfung steckt in
// flame_style_allowed(), damit hier dieselbe Regel gilt wie beim Speichern und beim Anzeigen –
// inklusive der geheimen Namens-Freischaltung, die nirgends angekündigt wird.
$streakStyleAvail = [];
$shopFlames = flame_styles_shop();
foreach (flame_styles_all() as $sk) if (flame_style_allowed($meId, $sk)) $streakStyleAvail[$sk] = true;
$streakStyle  = member_flame_style($meId); // '' Flamme | heart | bolt | star | coffee | flower
// Optik/Text je Stil für den Umschalter im Locker
$streakStyleMeta = [
    ''       => ['label' => 'Flamme',   'title' => 'Klassische Flamme'],
    'heart'  => ['label' => 'Herz 💗',   'title' => 'Schlagendes Herz (Pretty in Pink)'],
    'bolt'   => ['label' => 'Blitz ⚡',   'title' => 'Zuckender Blitz (Hochspannung)'],
    'star'   => ['label' => 'Stern ⭐',   'title' => 'Funkelnder Stern (Sternstunde)'],
    'rakete' => ['label' => 'Rakete 🚀',  'title' => '„To the moon" – der Schub wächst mit der Streak'],
    'diamant' => ['label' => 'Diamant 💎', 'title' => 'Vom matten Stein zum funkelnden Brillanten'],
    'coffee' => ['label' => 'Kaffee ☕',  'title' => 'Dampfender Kaffee (Koffein-Modus)'],
    // „Sempervivum" (semper vivum – immer lebendig) ist die Hauswurz: die Pflanze, der man
    // nachsagt, sie sei nicht totzukriegen. Genau das ist eine Streak.
    // ACHTUNG, das zusätzliche i in „Semperviv-I-um" ist ABSICHT und KEIN Tippfehler: es steckt
    // ein Gruß an Vivien darin, auf deren Wunsch der Stil entstanden ist. Nicht „korrigieren" –
    // der Selbsttest wacht darüber, weil genau das schon einmal passiert ist.
    // Ohne Emoji: der Name ist der längste im Umschalter, und über der Beschriftung steht ohnehin
    // schon eine Blume – das 🌸 hätte nur eine zweite Zeile erzwungen.
    'flower' => ['label' => 'Sempervivium', 'title' => 'Sempervivium – „immer lebendig" (Jahresringe). Wird jeden Tag gegossen und geht mit jeder Stufe weiter auf: vom Samen im Boden bis zur vollen Blüte.'],
    // Der Technik-Engel: ein liegender Ring statt einer Flamme. Kurz „Schein", weil
    // „Heiligenschein" in der Kachel eine zweite Zeile erzwingt.
    'schein' => ['label' => 'Schein 😇', 'title' => 'Heiligenschein (Technik-Engel) – er kippt sacht und wirft ab Stufe 3 Strahlen'],
    'mond'   => ['label' => 'Mondphasen 🌙', 'title' => 'Der Mond wandert mit deiner Serie durch seine Phasen – von der schmalen Sichel bis zum Vollmond mit Hof.'],
    'regenbogen' => ['label' => 'Regenbogen 🌈', 'title' => 'Ein Bogen, der Farben sammelt: erst ein einzelner Streifen, mit jeder Stufe kommen welche dazu – und ganz oben steht der zweite Bogen daneben.'],
];
$royalOn  = royal_unlocked();                // Royal-Design hängt am „spitze"-Achievement
$total    = count($catalog);
$done     = count(array_intersect_key($catalog, $earned));

$parts    = preg_split('/\s+/', trim($me['name']));
$initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));

// Sortierung fürs Gitter: freigeschaltet zuerst, dann nach Stufe; Geheimes ohne Freischaltung ans Ende.
$tierOrder = ['bronze' => 0, 'silber' => 1, 'gold' => 2, 'legende' => 3];
$codes = array_keys($catalog);
usort($codes, function ($a, $b) use ($catalog, $earned, $tierOrder) {
    $ea = isset($earned[$a]); $eb = isset($earned[$b]);
    if ($ea !== $eb) return $ea ? -1 : 1;
    $ha = !empty($catalog[$a]['hidden']) && !$ea; $hb = !empty($catalog[$b]['hidden']) && !$eb;
    if ($ha !== $hb) return $ha ? 1 : -1;
    return ($tierOrder[$catalog[$a]['tier']] ?? 9) <=> ($tierOrder[$catalog[$b]['tier']] ?? 9);
});

// ---- Hall of Fame: Royals (Spitzenklasse) + längste Streaks (für alle sichtbar) ----
$hofRoyals = []; $hofStreaks = [];
try {
    $hofRoyals = db()->query("SELECT m.id, m.name, m.pronouns, m.avatar_decos, m.avatar_palette, m.avatar_ink
        FROM member_achievements ma JOIN members m ON m.id = ma.member_id
        WHERE ma.code = 'spitze' AND m.active = 1 ORDER BY m.name COLLATE NOCASE")->fetchAll();
    // Längste laufende Streaks (ab 3 Tagen – vorher glimmt ja nur die Glut)
    foreach (db()->query('SELECT id, name, pronouns, avatar_decos, avatar_palette, avatar_ink, streak_current, streak_last_day, flame_style FROM members WHERE active = 1')->fetchAll() as $r) {
        $cur = streak_alive_for((int)$r['id'], (string)($r['streak_last_day'] ?? '')) ? (int)$r['streak_current'] : 0;
        if ($cur >= 3) { $r['cur'] = $cur; $hofStreaks[] = $r; }
    }
    usort($hofStreaks, fn($x, $y) => ($y['cur'] <=> $x['cur']) ?: strcasecmp((string)$x['name'], (string)$y['name']));
    // Es zählen WERTE, nicht Plätze: Alle mit dem höchsten und alle
    // mit dem zweithöchsten Streak-Wert stehen in der Hall of Fame – egal, wie viele das sind.
    // Nach PLÄTZEN käme bei vielen Gleichauf-Spitzenreitern nie jemand Zweites zum Zug.
    $hofWerte = array_values(array_unique(array_map(fn($r) => (int)$r['cur'], $hofStreaks))); // absteigend, wie sortiert
    if (count($hofWerte) > 2) {
        $cut = (int)$hofWerte[1];
        $hofStreaks = array_values(array_filter($hofStreaks, fn($r) => (int)$r['cur'] >= $cut));
    }
} catch (\Throwable $e) {}
$hofRoyalIds = array_flip(array_map(fn($r) => (int)$r['id'], $hofRoyals));

// ---- Sommer verschenken: nur für König:innen des Sommers mit Rest-Kontingent ----
$sommerQuota   = sommer_gift_quota($meId);
$sommerPreview = $sommerQuota < 1 && test_unlock_all(); // Admin/Technik-Testvorschau (echtes Schenken blockt der Server bei Quota 0)
$sommerShow    = $sommerQuota > 0 || $sommerPreview;
$sommerGiftMax = $sommerQuota > 0 ? $sommerQuota : 2;    // Anzeige-/Auswahl-Limit (im Vorschaumodus 2)
$sommerCandidates = $sommerShow ? sommer_gift_candidates($meId) : [];
$sommerGifted = $sommerShow ? sommer_gifted_list() : []; // Transparenz: wer hat von wem schon geschenkt bekommen

page_header('Achievements');
?>
<p class="small"><a href="dashboard.php">‹ Dashboard</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-trophy" style="color:var(--petrol)"></i> Achievements</h1>
  <a class="btn secondary small" href="#locker"><i class="ti ti-shirt"></i> Anpassen</a><!-- Anker: direkt zum Belohnungs-Locker springen -->
</div>
<p class="muted">Sammle Erfolge, halte deine Streak am Leben und schalte kleine optische Belohnungen frei. Alles rein zum Spaß – kein Einfluss auf Einteilung oder Score.</p>

<div class="duo">
  <!-- Streak -->
  <?php $sc = (int)$streak['current']; $sTier = streak_tier_shown($sc); // brennt ab 3 Tagen, Stufen wie im Hero ?>
  <div class="card ach-streak<?= streak_aura_class($streakStyle, $sTier) ?>">
    <?= streak_aura_html($streakStyle, $sTier) ?>
    <div class="ach-flame st<?= $sTier ?><?= $sTier ? ' lit' : '' ?><?= $streakStyle ? ' ' . h($streakStyle) : '' ?>"><?= flame_svg($streakStyle) ?></div>
    <div>
      <div class="ach-streak-num"><?= (int)$streak['current'] ?> <span>Tag<?= $streak['current'] === 1 ? '' : 'e' ?> Streak</span></div>
      <?php $giveWord = ['' => 'eine Flamme', 'heart' => 'ein flammendes Herz', 'bolt' => 'einen Blitz', 'star' => 'einen Stern', 'coffee' => 'eine dampfende Tasse', 'rakete' => 'einen Schub', 'diamant' => 'ein Karat', 'flower' => 'einen Gießtag', 'regenbogen' => 'einen Lichtblick', 'mond' => 'eine Nacht', 'schein' => 'einen Tag im Licht'][$streakStyle] ?? 'eine Flamme'; ?>
      <div class="small muted">Beste Serie: <strong><?= (int)$streak['best'] ?></strong> Tage · Jeder Tag mit App-Besuch gibt <?= $giveWord ?>. Die Kette reißt erst, wenn du 5 Tage in Folge nicht reinschaust.</div>
    </div>
  </div>
  <!-- Fortschritt -->
  <div class="card ach-progress-card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-trophy"></i> Fortschritt</div>
    <div class="ach-progress-num"><?= $done ?> <span>/ <?= $total ?> freigeschaltet</span></div>
    <div class="ach-progress"><span style="width:<?= $total ? round($done / $total * 100) : 0 ?>%"></span></div>
    <?php $secretLeft = 0; foreach ($catalog as $c => $d) { if (!empty($d['hidden']) && !isset($earned[$c])) $secretLeft++; } ?>
    <?php if ($secretLeft): ?><div class="small muted" style="margin-top:.5rem"><i class="ti ti-lock"></i> <?= $secretLeft ?> geheime<?= $secretLeft === 1 ? 's' : '' ?> Achievement<?= $secretLeft === 1 ? '' : 's' ?> warten darauf, entdeckt zu werden.</div><?php endif; ?>
  </div>
</div>

<?php if ($hofRoyals || $hofStreaks): ?>
<div class="card hof-card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-sparkles" style="color:#d9971a"></i> Hall of Fame</div>
  <div class="hof-grid">
    <?php if ($hofRoyals): ?>
    <div class="hof-panel hof-royal">
      <div class="hof-head"><i class="ti ti-crown"></i> Spitzenklasse <span class="hof-n"><?= count($hofRoyals) ?></span></div>
      <div class="hof-people">
        <?php foreach ($hofRoyals as $r) echo member_chip_link($r, true); ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($hofStreaks): ?>
    <div class="hof-panel hof-streak">
      <div class="hof-head"><i class="ti ti-flame"></i> Längste Streaks <span class="hof-n"><?= count($hofStreaks) ?></span></div>
      <div class="hof-people">
        <?php foreach ($hofStreaks as $r): $cur = (int)$r['cur'];
            $tier = max(1, streak_tier($cur)); // in der Hall of Fame steht nie eine kalte Streak
            $fs = trim((string)($r['flame_style'] ?? '')); if (!in_array($fs, flame_styles_all(), true)) $fs = ''; // nur bekannte Stile (verdient + gekauft)
            $sfx = ' <span class="act-streak t' . $tier . ($fs ? ' ' . $fs . 'line' : '') . '" style="font-size:.85rem">' . flame_style_mini($fs) . $cur . '</span>';
            echo member_chip_link($r, isset($hofRoyalIds[(int)$r['id']]), '', $sfx);
        endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <p class="small muted" style="margin:.7rem 0 0">Wer hier steht, hat es sich verdient – Status-Plätze wie die Spitzenklasse können wieder wandern. 👑</p>
</div>
<?php endif; ?>

<?php if ($sommerShow): ?>
<div class="card gift-card" id="sommer-gift">
  <div class="gift-sun" aria-hidden="true"><span class="gift-sun-disc"></span></div>
  <div class="section-title" style="margin-top:0"><i class="ti ti-sun" style="color:#f2a900"></i> Den Sommer verschenken</div>
  <?php if ($sommerPreview): ?><div class="flash flash-info" style="margin:.2rem 0 .7rem"><i class="ti ti-flask"></i> <strong>Testvorschau</strong> (Testmodus) – so sieht die Karte aus. Es wird hier <strong>nichts wirklich verschenkt</strong>.</div><?php endif; ?>
  <p class="small muted" style="margin:.1rem 0 .8rem">Als König:in des Sommers darfst du <strong><?= $sommerGiftMax ?></strong> <?= $sommerGiftMax === 1 ? 'Person' : 'Personen' ?>, die besonders toll geholfen haben, das App-Design „Hochsommer" schenken. Es wird bei ihnen einmalig automatisch aktiviert – zusammen mit deiner persönlichen Nachricht.</p>
  <?php if (!$sommerCandidates): ?>
    <p class="small muted">Aktuell gibt es niemanden, der/die den Sommer noch nicht hat. 🌻</p>
  <?php else: ?>
  <form method="post" id="gift-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="gift_sommer">
    <div class="gift-people" data-max="<?= $sommerGiftMax ?>">
      <?php foreach ($sommerCandidates as $c): ?>
        <label class="gift-pick">
          <input type="checkbox" name="to[]" value="<?= (int)$c['id'] ?>" data-name="<?= h(short_name((string)$c['name'])) ?>"<?= $sommerPreview ? ' disabled' : '' ?>>
          <?= avatar_chip($c, false, ' gift-pick-av') ?>
          <i class="ti ti-check gift-pick-check"></i>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="gift-hint small muted" style="margin:.5rem 0 .7rem">Wähle bis zu <?= $sommerGiftMax ?> <?= $sommerGiftMax === 1 ? 'Person' : 'Personen' ?> – jede bekommt ihre <strong>eigene</strong> Nachricht.</div>
    <div id="gift-messages" class="gift-msgs"><?php // je ausgewählter Person ein eigenes Nachrichtenfeld (msg[<id>]) – von app.js befüllt ?></div>
    <div style="margin-top:.7rem"><button class="btn" type="submit"<?= $sommerPreview ? ' disabled title="Testvorschau – im Testmodus deaktiviert"' : '' ?>><i class="ti ti-gift"></i> Sommer verschenken</button></div>
  </form>
  <?php endif; ?>
  <?php if ($sommerGifted): ?>
    <div class="gift-given small muted">
      <div style="font-weight:600;margin-bottom:.25rem"><i class="ti ti-info-circle"></i> Schon verschenkt – doppelt geht nicht:</div>
      <?php foreach ($sommerGifted as $g): ?>
        <div><strong><?= h(short_name((string)$g['to_name'])) ?></strong> hat von <?= h(short_name((string)$g['from_name'])) ?> geschenkt bekommen</div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="section-title"><i class="ti ti-award"></i> Erfolge</div>
<div class="ach-grid">
  <?php foreach ($codes as $code): $a = $catalog[$code];
      $isEarned = isset($earned[$code]);
      $isSecret = !empty($a['hidden']) && !$isEarned;
      $tm = $tiers[$a['tier']] ?? ['label' => '', 'color' => 'var(--muted)']; ?>
    <div class="ach-card <?= $isEarned ? 'earned' : ($isSecret ? 'secret' : 'locked') ?>" style="--tier:<?= h($tm['color']) ?>">
      <div class="ach-ic"><i class="ti <?= $isSecret ? 'ti-help-circle' : h($a['icon']) ?>"></i></div>
      <div class="ach-body">
        <div class="ach-title"><?= $isSecret ? 'Geheimes Achievement' : h($a['title']) ?></div>
        <div class="ach-desc small muted"><?= $isSecret ? 'Mach weiter – vielleicht entdeckst du es von selbst.' : h($a['desc']) ?></div>
        <div class="ach-meta">
          <span class="ach-tier" style="color:<?= h($tm['color']) ?>"><i class="ti ti-diamond"></i> <?= h($tm['label']) ?></span>
          <?php if ($isEarned): ?><span class="ach-done"><i class="ti ti-circle-check-filled"></i> Freigeschaltet</span>
          <?php elseif (!$isSecret): ?><span class="ach-todo"><i class="ti ti-lock"></i> Offen</span><?php endif; ?>
          <?php if (!$isSecret && !empty($a['reward']['label'])): ?><span class="ach-reward"><i class="ti ti-gift"></i> <?= h($a['reward']['label']) ?></span><?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php /* Der Kontostand gehört in die Überschrift: Seit man mit AsT UND mit Props kaufen kann,
         ist die erste Frage vor jedem Klick „habe ich genug?" – die Antwort soll man sehen,
         ohne erst den Fließtext zu lesen oder in die Tauschbörse zu wechseln. */ ?>
<div class="section-title"><i class="ti ti-sparkles"></i> Belohnungen
  <span class="ach-konto">
    <a class="ach-konto-teil" href="tauschboerse.php#depot" title="Dein Schicht-Depot in der Tauschbörse"><i class="ti ti-coins"></i> <strong><?= h($asTaler($astKonto)) ?></strong></a>
    <span class="ach-konto-teil<?= $propsFrei >= PROPS_PREIS ? ' ach-konto-an' : '' ?>" title="Je <?= PROPS_PREIS ?> Props kannst du gegen ein beliebiges Stück eintauschen"><span aria-hidden="true">🙌</span> <strong><?= (int)$propsFrei ?></strong> Props<span class="ach-konto-klein"> (<?= PROPS_PREIS ?> = 1 Stück)</span></span>
  </span>
</div>
<?php if (test_unlock_all() || test_streak_aura()): // Diagnose-Testmodus: sonst rätselt man, ob er überhaupt greift ?>
  <div class="flash flash-info" style="margin-bottom:.6rem"><i class="ti ti-flask"></i>
    <strong>Testmodus aktiv:</strong>
    <?php if (test_unlock_all()): ?>Schmuck, Farben, Skins und Streak-Stile sind gerade
    <em>alle</em> auswählbar – auch die kaufbaren, deren Preisschilder deshalb verschwunden sind.<?php endif; ?>
    <?php if (test_streak_aura()): ?>Die <strong><?= test_streak_aura() === 4 ? '50' : '100' ?>-Tage-Stufe</strong> wird vorgeführt: Streak-Karte und
    Begrüßungskarte strahlen, obwohl deine Streak noch nicht so weit ist. Echte Erfolge vergibt das nicht.<?php endif; ?>
    Beenden in der <a href="admin/errorlog.php#tests">Diagnose</a>.</div>
<?php endif; ?>
<div class="card" id="locker" style="scroll-margin-top:80px"><!-- Anker-Ziel des „Anpassen"-Buttons; scroll-margin hält Abstand zur fixen Topbar -->
  <div class="section-title" style="margin-top:0;font-size:1.02rem"><i class="ti ti-mood-smile"></i> Avatar-Schmuck</div>
  <p class="small muted" style="margin-top:0">Rüste freigeschaltete Schmuckstücke aus – je Trage-Position eins (Kopf, Hand, Hals, Gesicht – besondere Stücke sitzen „Hinter dir" oder „Unter dir"), und die Positionen lassen sich <strong>kombinieren</strong>. Erneuter Klick legt ein Stück wieder ab. Dein Kopf-Accessoire trägt übrigens auch das AStA-Logo oben links. 🎀
    Alles mit <strong>Preisschild</strong> (auch unten bei Farben und Streak-Stilen) kannst du kaufen – ein Klick auf die Kachel fragt, womit: in AsT aus deinem <a href="tauschboerse.php#depot">Schicht-Depot</a> (<?= h($asTaler($astKonto)) ?> verfügbar)<?php if ($propsFrei >= PROPS_PREIS): ?>, oder für <strong><?= PROPS_PREIS ?> Props</strong> 🙌 – du hast <?= (int)$propsFrei ?><?php endif; ?>.<?php if ($royalJetzt): ?> Als Royal darfst du alle 3 Monate ein Stück kostenlos <strong>beschlagnahmen</strong><?= $claimFrei ? ' – die Krone an der Kachel zeigt, wo das geht.' : ' – die nächste ab dem ' . h($claimAb) . '.' ?><?php endif; ?></p>
  <div class="deco-locker">
    <!-- „Keins" -->
    <form method="post" action="achievements.php" class="deco-opt<?= !$equipped ? ' active' : '' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="equip_deco"><input type="hidden" name="deco" value="">
      <button type="submit" title="Alle Trage-Positionen leeren">
        <span class="deco-av<?= $myPalClass ?>" style="--av:<?= h($myGrad) ?><?= h($inkVar($myInk)) ?>"><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span></span>
        <span class="small">Alles ablegen</span>
      </button>
    </form>
    <?php $slotNames = avatar_slot_labels(); foreach ($decos as $key => $label): $on = in_array($key, $equipped, true); ?>
      <form method="post" action="achievements.php" class="deco-opt<?= $on ? ' active' : '' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="equip_deco"><input type="hidden" name="deco" value="<?= h($key) ?>"><input type="hidden" name="toggle" value="1">
        <button type="submit" title="<?= h($label) ?> – Position: <?= h($slotNames[avatar_deco_slot($key)] ?? '?') ?>. <?= $on ? 'Klick legt es ab.' : 'Kombinierbar mit anderen Positionen.' ?>">
          <span class="deco-av<?= $myPalClass ?>" style="--av:<?= h($myGrad) ?><?= h($inkVar($myInk)) ?>"><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span><span class="avatar-acc acc-<?= avatar_deco_slot($key) ?> acc-k-<?= h($key) ?>"><?= avatar_deco_svg($key) ?></span></span>
          <span class="small"><?= h($label) ?></span>
        </button>
      </form>
    <?php endforeach; ?>
    <?php // Kaufbare Stücke (noch nicht gekauft): gleiche Kachel, nur mit Preisschild.
          // Klick = Kaufen; das kleine 👑 oben rechts ist die kostenlose Royal-Beschlagnahme.
          foreach (shop_items() as $sk => $si): if (($si['type'] ?? '') !== 'deco' || isset($decos[$sk])) continue; $preis = (int)$si['price']; ?>
      <div class="deco-opt shop-opt">
        <form method="post" action="achievements.php" data-kauf="<?= h(kauf_daten($si['label'], $preis, $astKonto, $propsFrei, $claimFrei)) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="shop_buy"><input type="hidden" name="item" value="<?= h($sk) ?>">
          <button type="submit" title="<?= h($si['label']) ?> – Position: <?= h($slotNames[avatar_deco_slot($sk)] ?? '?') ?>. Klick öffnet die Bezahl-Auswahl.">
            <span class="deco-av<?= $myPalClass ?>" style="--av:<?= h($myGrad) ?><?= h($inkVar($myInk)) ?>"><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span><span class="avatar-acc acc-<?= avatar_deco_slot($sk) ?> acc-k-<?= h($sk) ?>"><?= avatar_deco_svg($sk) ?></span></span>
            <span class="small"><?= h($si['label']) ?></span>
            <span class="shop-price<?= kauf_unmoeglich($preis, $astKonto, $propsFrei, $claimFrei) ? ' shop-price-zu' : '' ?>"><i class="ti ti-shopping-cart"></i> Kaufen: <?= h($asTaler($preis)) ?></span>
          </button>
        </form>
        <?= kauf_marken($propsFrei >= PROPS_PREIS, $claimFrei) ?>
      </div>
    <?php endforeach; ?>
    <?= locker_versteckt_html(locker_versteckt($meId, 'deco'), 'Schmuckstücke') ?>
  </div>
  <?php if (!$decos): ?><p class="empty" style="margin-top:.6rem"><i class="ti ti-lock"></i> Noch kein Avatar-Schmuck freigeschaltet. Erfolge mit einem <i class="ti ti-gift"></i>-Symbol bringen welchen.</p><?php endif; ?>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-color-swatch"></i> Avatar-Farbe</div>
  <p class="small muted" style="margin-top:0">Statt der automatischen Farbe aus deinem Namen kannst du einen Verlauf wählen – besondere schaltest du über Achievements frei.</p>
  <div class="deco-locker">
    <!-- Auto (Namens-Verlauf) -->
    <form method="post" action="achievements.php" class="deco-opt<?= $myPalette === '' ? ' active' : '' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="equip_palette"><input type="hidden" name="palette" value="">
      <button type="submit" title="Automatische Farbe aus deinem Namen">
        <span class="deco-av" style="--av:<?= h(avatar_gradient($me['name'])) ?><?= h($inkVar($myInk)) ?>"><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span></span>
        <span class="small">Auto</span>
      </button>
    </form>
    <?php /* Reihenfolge: eigene, dann kaufbare, zuletzt verschlossene (avatar_palettes_sorted).
             Schmuck und Streak-Stile stehen im Locker schon so. */ ?>
    <?php foreach (avatar_palettes_sorted($meId) as $key => $p): $free = isset($palettes[$key]);
        $isLeg = ($p['tier'] ?? '') === 'legende'; // ✨-Badge nur für legendäre
        $palCls = !empty($p['anim']) ? ' pal-' . h($key) : ''; // alle Special-Farben sind animiert (auch die Vorschau)
        $legBadge = $isLeg ? ' <span class="pal-leg" title="Legendäre Farbe – animiert"><i class="ti ti-sparkles"></i></span>' : ''; ?>
      <?php if ($free): ?>
        <form method="post" action="achievements.php" class="deco-opt<?= $myPalette === $key ? ' active' : '' ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="equip_palette"><input type="hidden" name="palette" value="<?= h($key) ?>">
          <button type="submit" title="<?= h($p['label']) ?><?= $isLeg ? ' (legendär, animiert)' : '' ?>">
            <span class="deco-av<?= $palCls ?>" style="--av:<?= h($p['grad']) ?><?= h($inkVar($myInk)) ?>"><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span></span>
            <span class="small"><?= h($p['label']) ?><?= $legBadge ?></span>
          </button>
        </form>
      <?php elseif (!empty($p['shop']) && isset(shop_items()[$key])): $pPreis = (int)shop_items()[$key]['price']; ?>
        <div class="deco-opt shop-opt">
          <form method="post" action="achievements.php" data-kauf="<?= h(kauf_daten($p['label'], $pPreis, $astKonto, $propsFrei, $claimFrei)) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="shop_buy"><input type="hidden" name="item" value="<?= h($key) ?>">
            <button type="submit" title="<?= h($p['label']) ?> – Klick öffnet die Bezahl-Auswahl.">
              <span class="deco-av<?= $palCls ?>" style="--av:<?= h($p['grad']) ?><?= h($inkVar($myInk)) ?>"><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span></span>
              <span class="small"><?= h($p['label']) ?></span>
              <span class="shop-price<?= kauf_unmoeglich($pPreis, $astKonto, $propsFrei, $claimFrei) ? ' shop-price-zu' : '' ?>"><i class="ti ti-shopping-cart"></i> Kaufen: <?= h($asTaler($pPreis)) ?></span>
            </button>
          </form>
          <?= kauf_marken($propsFrei >= PROPS_PREIS, $claimFrei) ?>
        </div>
      <?php elseif (!empty($catalog[$p['ach']]['hidden'])): // geheim: Titel wäre ein Spoiler – zählt in die Schloss-Kachel ?>
      <?php else: ?>
        <div class="deco-opt locked" title="Freischalten über: <?= h($catalog[$p['ach']]['title'] ?? $p['ach']) ?>">
          <span class="deco-av deco-locked<?= $palCls ?>" style="--av:<?= h($p['grad']) ?>"><i class="ti ti-lock"></i></span>
          <span class="small muted"><?= h($p['label']) ?><?= $legBadge ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <?= locker_versteckt_html(locker_versteckt($meId, 'palette'), 'Farben') ?>
  </div>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-typography"></i> Farbe der Initialen</div>
  <p class="small muted" style="margin-top:0">Die Buchstaben in deinem Avatar. <strong>Alle frei wählbar</strong> – das ist keine Belohnung,
    sondern eine Frage von Geschmack und Lesbarkeit: Auf einem dunklen Verlauf liest sich hell besser, auf einem hellen dunkel.</p>
  <div class="deco-locker">
    <form method="post" action="achievements.php" class="deco-opt<?= $myInk === '' ? ' active' : '' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="equip_ink"><input type="hidden" name="ink" value="">
      <button type="submit" title="Vorgabe">
        <span class="deco-av<?= $palOnlyClass ?>" style="--av:<?= h($myGrad) ?>"><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span></span>
        <span class="small">Vorgabe</span>
      </button>
    </form>
    <?php foreach (avatar_inks() as $key => $tinte): ?>
      <form method="post" action="achievements.php" class="deco-opt<?= $myInk === $key ? ' active' : '' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="equip_ink"><input type="hidden" name="ink" value="<?= h($key) ?>">
        <button type="submit" title="<?= h($tinte['label']) ?>">
          <?php /* Vorschau auf dem EIGENEN Verlauf – nur so sieht man, ob die Farbe darauf lesbar ist. */ ?>
          <span class="deco-av<?= $palOnlyClass . $inkClass($key) ?>" style="--av:<?= h($myGrad) ?>;--av-ink:<?= h($tinte['color']) ?>"><span class="av-ini"><?= h($initials) ?: '<i class="ti ti-user"></i>' ?></span></span>
          <span class="small"><?= h($tinte['label']) ?></span>
        </button>
      </form>
    <?php endforeach; ?>
  </div>

  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-palette"></i> App-Skins</div>
  <div class="skin-locker">
    <?php /* Pride gehört ALLEN (frei-Flag in app_skins) und steht deshalb immer hier –
             ohne Schloss, ohne Bedingung. Die übrigen Skins sind Belohnungen. */ ?>
    <div class="skin-chip"><i class="ti ti-rainbow"></i> Pride – für alle da, über die <i class="ti ti-bulb"></i> Glühbirne</div>
    <?php if ($royalOn): ?><div class="skin-chip"><i class="ti ti-crown"></i> Royal – über die <i class="ti ti-bulb"></i> Glühbirne</div><?php endif; ?>
    <?php foreach ($skins as $key => $label): $ic = app_skins()[$key]['icon'] ?? 'ti-bulb'; ?>
      <div class="skin-chip"><i class="ti <?= h($ic) ?>"></i> <?= h($label) ?> – über die <i class="ti ti-bulb"></i> Glühbirne</div>
    <?php endforeach; ?>
    <?php $skinGeheim = locker_versteckt($meId, 'skin'); if ($skinGeheim): ?>
      <div class="skin-chip skin-chip-zu" title="Diese Designs bekommt man über Achievements – welche es sind, bleibt eine Überraschung."><i class="ti ti-lock"></i> noch <strong><?= (int)$skinGeheim ?></strong> versteckte</div>
    <?php endif; ?>
  </div>
  <?php if (!$skins && !$royalOn): ?>
    <p class="empty" style="margin-top:.6rem"><i class="ti ti-lock"></i> Weitere App-Skins sind Belohnungen – z. B. <strong>TrueBlack</strong> als geheime nächtliche. 🖤</p>
  <?php endif; ?>

  <?php if ($streakStyleAvail || $shopFlames): // freigeschaltete Sonder-Stile + Kaufbares (mit Preisschild immer sichtbar) ?>
  <div class="section-title" style="font-size:1.02rem"><i class="ti ti-flame"></i> Streak-Stil</div>
  <p class="small muted" style="margin-top:0">Deine Streak kann als klassische Flamme brennen – oder in einem der freigeschalteten Stile.</p>
  <div class="deco-locker">
    <?php foreach (array_merge([''], array_keys($streakStyleAvail)) as $sk):
        $m = $streakStyleMeta[$sk] ?? ['label' => $sk, 'title' => $sk]; $on = $streakStyle === $sk; ?>
    <form method="post" action="achievements.php" class="deco-opt<?= $on ? ' active' : '' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="flame_style"><input type="hidden" name="style" value="<?= h($sk) ?>">
      <button type="submit" title="<?= h($m['title']) ?>">
        <span class="st2 lit flame-wahl<?= $sk ? ' ' . h($sk) : '' ?>"><?= flame_svg($sk) ?></span>
        <span class="small"><?= h($m['label']) ?></span>
      </button>
    </form>
    <?php endforeach; ?>
    <?php // Kaufbare Stile (noch nicht gekauft): gleiche Kachel, mit Preisschild und 👑.
          foreach ($shopFlames as $sk): if (isset($streakStyleAvail[$sk])) continue;
              $si = shop_items()[$sk]; $preis = (int)$si['price'];
              $m = $streakStyleMeta[$sk] ?? ['label' => $sk, 'title' => $sk]; ?>
      <div class="deco-opt shop-opt">
        <form method="post" action="achievements.php" data-kauf="<?= h(kauf_daten($m['label'], $preis, $astKonto, $propsFrei, $claimFrei)) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="shop_buy"><input type="hidden" name="item" value="<?= h($sk) ?>">
          <button type="submit" title="<?= h($m['title']) ?> Klick öffnet die Bezahl-Auswahl.">
            <span class="st2 lit flame-wahl <?= h($sk) ?>"><?= flame_svg($sk) ?></span>
            <span class="small"><?= h($m['label']) ?></span>
            <span class="shop-price<?= kauf_unmoeglich($preis, $astKonto, $propsFrei, $claimFrei) ? ' shop-price-zu' : '' ?>"><i class="ti ti-shopping-cart"></i> Kaufen: <?= h($asTaler($preis)) ?></span>
          </button>
        </form>
        <?= kauf_marken($propsFrei >= PROPS_PREIS, $claimFrei) ?>
      </div>
    <?php endforeach; ?>
    <?= locker_versteckt_html(locker_versteckt($meId, 'flame'), 'Streak-Stile') ?>
  </div>
  <?php endif; ?>
</div>

<?php
page_footer();
