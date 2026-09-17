<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();

// ---- Aktionen (Anbieten / Zurückziehen / Tauschen) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$me) {
        flash('Mit dem Technik-Login kannst du nicht tauschen – melde dich als Mitglied an.', 'error');
        redirect('tauschboerse.php');
    }
    $memberId = (int)$me['id'];
    $action = $_POST['action'] ?? '';
    $anchor = '';
    if ($action === 'swap_offer') {
        $slot = event_slot_get((int)($_POST['slot_id'] ?? 0));
        $ev = $slot ? event_get((int)$slot['event_id']) : null;
        $mode = ($_POST['mode'] ?? '') === 'giveaway' ? 'giveaway' : 'swap';
        if ($ev) { $anchor = '#ev' . (int)$ev['id']; $r = shift_swap_offer($ev, (int)$slot['id'], $memberId, $mode); flash($r['msg'], $r['ok'] ? 'success' : 'error'); }
    } elseif ($action === 'giveaway_take') {
        $swapId = (int)($_POST['swap_id'] ?? 0);
        $row = db()->prepare('SELECT event_id FROM shift_swaps WHERE id = ?'); $row->execute([$swapId]);
        $ev = ($eid = (int)$row->fetchColumn()) ? event_get($eid) : null;
        if ($ev) { $anchor = '#ev' . (int)$ev['id']; $r = shift_giveaway_take($ev, $swapId, $memberId); flash($r['msg'], $r['ok'] ? 'success' : 'error'); }
        else flash('Diese Schicht ist nicht mehr verfügbar.', 'error');
    } elseif ($action === 'swap_cancel') {
        $swapId = (int)($_POST['swap_id'] ?? 0);
        $row = db()->prepare('SELECT event_id FROM shift_swaps WHERE id = ?'); $row->execute([$swapId]);
        $anchor = ($eid = (int)$row->fetchColumn()) ? '#ev' . $eid : '';
        shift_swap_cancel($swapId, $memberId);
        flash('Angebot zurückgezogen.', 'success');
    } elseif ($action === 'swap_propose') {
        $swapId = (int)($_POST['swap_id'] ?? 0);
        $row = db()->prepare('SELECT event_id FROM shift_swaps WHERE id = ?'); $row->execute([$swapId]);
        $ev = ($eid = (int)$row->fetchColumn()) ? event_get($eid) : null;
        if ($ev) { $anchor = '#ev' . (int)$ev['id']; $r = swap_propose($ev, $swapId, (int)($_POST['take_slot_id'] ?? 0), $memberId); flash($r['msg'], $r['ok'] ? 'success' : 'error'); }
    } elseif ($action === 'proposal_withdraw') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        if ($p = swap_proposal_get($pid)) { $row = db()->prepare('SELECT event_id FROM shift_swaps WHERE id = ?'); $row->execute([(int)$p['swap_id']]); $anchor = ($eid = (int)$row->fetchColumn()) ? '#ev' . $eid : ''; }
        flash(swap_proposal_withdraw($pid, $memberId) ? 'Vorschlag zurückgezogen.' : 'Vorschlag nicht gefunden.', 'success');
    } elseif ($action === 'proposal_accept') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        $p = swap_proposal_get($pid); $ev = null;
        if ($p) { $row = db()->prepare('SELECT event_id FROM shift_swaps WHERE id = ?'); $row->execute([(int)$p['swap_id']]); $eid = (int)$row->fetchColumn(); $anchor = $eid ? '#ev' . $eid : ''; $ev = $eid ? event_get($eid) : null; }
        if ($ev) { $r = swap_proposal_accept($ev, $pid, $memberId); flash($r['msg'], $r['ok'] ? 'success' : 'error'); }
        else flash('Diese Anfrage ist nicht mehr verfügbar.', 'error');
    } elseif ($action === 'proposal_decline') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        if ($p = swap_proposal_get($pid)) { $row = db()->prepare('SELECT event_id FROM shift_swaps WHERE id = ?'); $row->execute([(int)$p['swap_id']]); $anchor = ($eid = (int)$row->fetchColumn()) ? '#ev' . $eid : ''; }
        flash(swap_proposal_decline($pid, $memberId) ? 'Vorschlag abgelehnt.' : 'Vorschlag nicht gefunden.', 'success');
    } elseif ($action === 'etf_buy') {
        $anchor = '#depot';
        $r = etf_invest($memberId, (string)($_POST['plan'] ?? ''), (int)($_POST['amount'] ?? 0));
        flash($r['msg'], $r['ok'] ? 'success' : 'error');
    } elseif ($action === 'etf_sell') {
        $anchor = '#depot';
        $r = etf_sell($memberId, (string)($_POST['plan'] ?? ''));
        flash($r['msg'], $r['ok'] ? 'success' : 'error');
    }
    redirect('tauschboerse.php' . $anchor);
}

$memberId = $me ? (int)$me['id'] : 0;
$events = member_swap_events($memberId);

// Tickersymbol aus dem Eventtitel (Großbuchstaben, max. 5)
$sym = function (string $title): string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $title));
    return $s !== '' ? substr($s, 0, 5) : 'AStA';
};
$shortWhen = fn($start) => ($t = strtotime((string)$start)) ? date('d.m. H:i', $t) : '';

// Boards + Tickerband vorbereiten
$myProps = swap_proposals_by_member($memberId); // [swap_id => proposal] – meine offenen Vorschläge
$boards = [];
$tickerItems = [];
$incomingTotal = 0;
foreach ($events as $e) {
    $eid = (int)$e['id'];
    $mine   = member_assigned_slots($eid, $memberId);
    $offers = shift_swaps_open($eid);
    $offeredMap = [];                       // slot_id => swap_id (eigene offene Angebote)
    $offeredMode = [];                      // slot_id => 'swap'|'giveaway' (Modus des eigenen Angebots)
    $incoming = [];                         // Vorschläge AN dich (auf deine Angebote in diesem Event)
    foreach ($offers as $sw) {
        if ((int)$sw['offered_by'] === $memberId) {
            $offeredMap[(int)$sw['slot_id']] = (int)$sw['id'];
            $offeredMode[(int)$sw['slot_id']] = ($sw['mode'] ?? 'swap') === 'giveaway' ? 'giveaway' : 'swap';
            foreach (swap_proposals_for_offer((int)$sw['id']) as $p) { $p['offer'] = $sw; $incoming[] = $p; }
        }
    }
    $incomingTotal += count($incoming);
    $tradable = array_values(array_filter($mine, fn($s) => empty($s['locked'])));
    $boards[] = ['e' => $e, 'eid' => $eid, 'mine' => $mine, 'offers' => $offers, 'offeredMap' => $offeredMap, 'offeredMode' => $offeredMode, 'tradable' => $tradable, 'incoming' => $incoming];
    foreach ($offers as $sw) {
        $tickerItems[] = ['sym' => $sym((string)$e['title']), 'when' => $shortWhen($sw['starts_at']), 'mine' => (int)$sw['offered_by'] === $memberId, 'give' => ($sw['mode'] ?? 'swap') === 'giveaway'];
    }
}
$totalOffers = count($tickerItems);

// Satire: das Schicht-Depot (der Depotwert lässt sich im Belohnungs-Locker ausgeben)
$depot = swap_market_stats($memberId);
$depotAusgegeben = ast_spent($memberId);
$depotFrei = ast_balance($memberId);
// Sparpläne: heutiger Kurs, Tagesbewegung und die eigene Position je Plan
$etfPos = etf_positions($memberId);
// Der Depotwert ist alles, worüber du verfügst: das freie Guthaben PLUS das, was gerade in
// Sparplänen steckt. Angelegtes Geld ist ja nicht weg, nur gebunden. Bewusst NICHT der
// ASX-Kurswert: Der steckt schon im freien Guthaben (er IST die Kaufkraft, siehe ast_balance)
// und würde hier ein zweites Mal zählen – dann ergäbe „Depotwert minus Sparpläne" nicht das
// Verfügbare, und die Zahl wäre für nichts zu gebrauchen.
$depotGesamt = (float)$depotFrei + array_sum(array_column($etfPos, 'wert'));
$etfRows = [];
foreach (etf_plans() as $pk => $pd) {
    $kurs = etf_index($pk);
    $gestern = etf_index($pk, date('Y-m-d', strtotime('-1 day')));
    $etfRows[$pk] = $pd + ['kurs' => $kurs,
        'chg' => $gestern > 0 ? ($kurs - $gestern) / $gestern * 100 : 0.0,
        'einzahlung' => (int)$etfPos[$pk]['einzahlung'], 'wert' => (int)$etfPos[$pk]['wert']];
}
$money = fn($v) => number_format((float)$v, 2, ',', '.') . ' AsT';

page_header('Schichtbörse');
?>
<p class="small"><a href="events.php">‹ Alle Events</a></p>

<div class="card boerse-hero">
  <svg class="boerse-chart" viewBox="0 0 360 120" preserveAspectRatio="none" aria-hidden="true">
    <polyline class="bc-line" points="0,86 40,70 80,80 120,52 160,60 200,34 240,46 280,24 320,32 360,12" fill="none"/>
    <g class="bc-sticks">
      <line x1="40"  y1="58"  x2="40"  y2="92"/><rect x="35"  y="64" width="10" height="20"/>
      <line x1="120" y1="40"  x2="120" y2="78"/><rect x="115" y="48" width="10" height="22" class="bc-down"/>
      <line x1="200" y1="22"  x2="200" y2="60"/><rect x="195" y="30" width="10" height="22"/>
      <line x1="280" y1="14"  x2="280" y2="50"/><rect x="275" y="20" width="10" height="20"/>
      <line x1="330" y1="8"   x2="330" y2="44"/><rect x="325" y="14" width="10" height="22" class="bc-down"/>
    </g>
  </svg>
  <div class="boerse-hero-in">
    <div class="boerse-kicker"><i class="ti ti-chart-candle"></i> AStA Schichtbörse</div>
    <h1>Schichten handeln</h1>
    <?php $swLead = swap_lead_days(); ?>
    <p>Biete eine dir zugeteilte Schicht zum <strong>1:1-Tausch</strong> an, oder <strong>gib sie einfach ab</strong> – dann kann sie jede:r ohne Gegen-Schicht übernehmen. Beim Tausch wird erst getauscht, wenn die andere Person <strong>zustimmt</strong>; eine Abgabe geht <strong>sofort</strong>. Automatisch auf Überschneidungen geprüft, standardmäßig geöffnet bis <strong><?= (int)$swLead ?> Tag<?= $swLead === 1 ? '' : 'e' ?></strong> vor Event-Start (die Orga kann eine Börse aber auch offen halten).</p>
  </div>
  <div class="boerse-ticker" aria-hidden="true">
    <div class="boerse-ticker-track">
      <?php
      $render_ticker = function () use ($tickerItems) {
          if (!$tickerItems) { echo '<span class="tk-item"><span class="tk-flat">●</span> Noch keine offenen Angebote – biete als Erste:r eine Schicht an!</span>'; return; }
          foreach ($tickerItems as $it) {
              $tag = $it['mine'] ? ($it['give'] ? 'DEINE ABGABE' : 'DEIN ANGEBOT') : ($it['give'] ? 'ABGABE' : 'TAUSCH');
              echo '<span class="tk-item"><span class="tk-up">▲</span> <b>' . h($it['sym']) . '</b> '
                 . h($it['when']) . ' <span class="tk-tag">' . $tag . '</span></span>';
          }
      };
      // zweimal ausgeben, damit das Band nahtlos durchläuft
      $render_ticker(); $render_ticker();
      ?>
    </div>
  </div>
</div>

<?php if (!$me): ?>
  <div class="card"><p class="empty"><i class="ti ti-user-off"></i> Die Börse ist nur für eingeloggte Mitglieder. Mit dem Technik-Login kannst du nicht handeln.</p></div>
  <?php page_footer(); exit; ?>
<?php endif; ?>

<div class="boerse-stats">
  <div class="bstat"><span class="bstat-n"><?= count($boards) ?></span><span class="bstat-l">offene Märkte</span></div>
  <div class="bstat"><span class="bstat-n"><?= $totalOffers ?></span><span class="bstat-l">Angebote gelistet</span></div>
  <div class="bstat<?= $incomingTotal > 0 ? ' bstat-hot' : '' ?>"><span class="bstat-n"><?= $incomingTotal ?></span><span class="bstat-l">Anfragen an dich</span></div>
  <a class="bstat bstat-depot" href="#depot" title="Zum Depot">
    <span class="bstat-n"><?= h($money($depotGesamt)) ?></span>
    <span class="bstat-l">Depotwert <span class="bstat-chg <?= $depot['changePct'] >= 0 ? 'up' : 'down' ?>"><?= $depot['changePct'] >= 0 ? '▲' : '▼' ?> <?= number_format(abs($depot['changePct']), 2, ',', '.') ?>%</span></span>
  </a>
</div>

<?php if (!$boards): ?>
  <div class="card"><p class="empty"><i class="ti ti-mood-empty"></i> Für dich ist gerade keine Börse offen. Sobald ein <strong>veröffentlichter Arbeitsplan</strong> existiert, du dort <strong>eingeteilt</strong> bist und das Event noch <strong>weit genug entfernt</strong> ist (bzw. die Orga die Börse offen hält), kannst du hier handeln.</p></div>
<?php endif; ?>

<?php foreach ($boards as $b): $e = $b['e']; $eid = $b['eid'];
    $range = fmt_event_range($e['starts_at'] ?? null, $e['ends_at'] ?? null);
    $othersOffers = array_values(array_filter($b['offers'], fn($sw) => (int)$sw['offered_by'] !== $memberId));
?>
  <section class="boerse-floor" id="ev<?= $eid ?>">
    <header class="floor-head">
      <span class="floor-sym"><?= h($sym((string)$e['title'])) ?></span>
      <a class="floor-name" href="event.php?id=<?= $eid ?>"><?= h($e['title']) ?></a>
      <?php if ($range): ?><span class="floor-date"><i class="ti ti-calendar-event"></i> <?= h($range) ?></span><?php endif; ?>
    </header>

    <div class="floor-cols">
      <!-- Eigene Positionen -->
      <div class="floor-col">
        <h3 class="floor-col-h"><i class="ti ti-wallet"></i> Deine Schichten</h3>
        <ul class="boerse-list">
          <?php foreach ($b['mine'] as $s): $sid = (int)$s['id']; ?>
            <li class="boerse-row">
              <span class="brow-when"><i class="ti ti-clock"></i> <?= h(fmt_slot($s['starts_at'], $s['ends_at'])) ?>
                <?php if ($s['label']): ?><span class="muted">(<?= h($s['label']) ?>)</span><?php endif; ?>
                <?php if (trim((string)$s['location']) !== ''): ?><span class="muted">· <?= h($s['location']) ?></span><?php endif; ?>
              </span>
              <?php if (!empty($s['locked'])): ?>
                <span class="brow-tag"><i class="ti ti-lock"></i> fixiert</span>
              <?php elseif (isset($b['offeredMap'][$sid])): $isGive = ($b['offeredMode'][$sid] ?? 'swap') === 'giveaway'; ?>
                <form method="post" class="brow-act">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="swap_cancel">
                  <input type="hidden" name="swap_id" value="<?= $b['offeredMap'][$sid] ?>">
                  <span class="brow-tag live"><i class="ti ti-broadcast"></i> <?= $isGive ? 'zur Abgabe' : 'zum Tausch' ?></span>
                  <button class="btn small secondary" type="submit"><i class="ti ti-x"></i> zurück</button>
                </form>
              <?php else: ?>
                <span class="brow-act">
                  <form method="post" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="swap_offer">
                    <input type="hidden" name="mode" value="swap">
                    <input type="hidden" name="slot_id" value="<?= $sid ?>">
                    <button class="btn small buy" type="submit" title="1:1 gegen eine andere Schicht tauschen"><i class="ti ti-arrows-exchange"></i> Tauschen</button>
                  </form>
                  <form method="post" style="margin:0" data-confirm="Diese Schicht zur Abgabe freigeben? Jede:r kann sie dann ohne Gegen-Schicht übernehmen – du bist sie damit los." data-confirm-title="Schicht abgeben" data-confirm-ok="Abgeben">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="swap_offer">
                    <input type="hidden" name="mode" value="giveaway">
                    <input type="hidden" name="slot_id" value="<?= $sid ?>">
                    <button class="btn small secondary" type="submit" title="Einfach abgeben – jede:r kann übernehmen"><i class="ti ti-gift"></i> Abgeben</button>
                  </form>
                </span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Order-Buch -->
      <div class="floor-col">
        <h3 class="floor-col-h"><i class="ti ti-clipboard-list"></i> Offene Order</h3>
        <?php if (!$othersOffers): ?>
          <p class="small muted boerse-empty">Niemand bietet hier gerade etwas an.</p>
        <?php else: ?>
          <ul class="boerse-list">
            <?php foreach ($othersOffers as $sw):
              $isGive = ($sw['mode'] ?? 'swap') === 'giveaway';
              $giveable = array_values(array_filter($b['tradable'], fn($s) => (int)$s['id'] !== (int)$sw['slot_id'])); ?>
              <li class="boerse-row offer<?= $isGive ? ' giveaway' : '' ?>">
                <span class="brow-when"><span class="brow-arrow">▲</span> <strong><a class="member-link" href="<?= h(member_profile_url((int)$sw['offered_by'])) ?>"><?= h(first_name($sw['offerer_name'])) ?></a></strong> <?= $isGive ? 'gibt ab:' : 'bietet' ?>
                  <?= h(fmt_slot($sw['starts_at'], $sw['ends_at'])) ?>
                  <?php if ($sw['label']): ?><span class="muted">(<?= h($sw['label']) ?>)</span><?php endif; ?>
                  <?php if (trim((string)$sw['location']) !== ''): ?><span class="muted">· <?= h($sw['location']) ?></span><?php endif; ?>
                  <?php if ($isGive): ?><span class="brow-tag give"><i class="ti ti-gift"></i> frei</span><?php endif; ?>
                </span>
                <?php if ($isGive): ?>
                  <form method="post" class="brow-act" data-confirm="Diese Schicht jetzt übernehmen? Sie kommt fest in deinen Plan (ohne Gegen-Schicht)." data-confirm-title="Schicht übernehmen" data-confirm-ok="Übernehmen">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="giveaway_take">
                    <input type="hidden" name="swap_id" value="<?= (int)$sw['id'] ?>">
                    <button class="btn small buy" type="submit" data-puste="tausch"><i class="ti ti-hand-grab"></i> Übernehmen</button>
                  </form>
                <?php elseif (isset($myProps[(int)$sw['id']])): $mp = $myProps[(int)$sw['id']]; ?>
                  <form method="post" class="brow-act">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="proposal_withdraw">
                    <input type="hidden" name="proposal_id" value="<?= (int)$mp['id'] ?>">
                    <span class="brow-tag live"><i class="ti ti-send"></i> vorgeschlagen</span>
                    <button class="btn small secondary" type="submit"><i class="ti ti-x"></i> zurück</button>
                  </form>
                <?php elseif (!$giveable): ?>
                  <span class="brow-tag muted">keine eigene Schicht zum Tausch</span>
                <?php else: ?>
                  <form method="post" class="brow-act brow-accept">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="swap_propose">
                    <input type="hidden" name="swap_id" value="<?= (int)$sw['id'] ?>">
                    <label class="brow-give">gib her:
                      <select name="take_slot_id" required>
                        <?php foreach ($giveable as $g): ?>
                          <option value="<?= (int)$g['id'] ?>"><?= h($shortWhen($g['starts_at'])) ?><?= $g['label'] ? ' (' . h($g['label']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <button class="btn small buy" type="submit"><i class="ti ti-send"></i> Vorschlagen</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($b['incoming']): ?>
      <div class="swap-requests">
        <div class="swap-requests-h"><i class="ti ti-inbox"></i> Anfragen an dich <span class="sr-count"><?= count($b['incoming']) ?></span></div>
        <ul class="boerse-list">
          <?php foreach ($b['incoming'] as $p): $ofs = $p['offer']; ?>
            <li class="boerse-row offer">
              <span class="brow-when"><span class="brow-arrow">▼</span> <strong><a class="member-link" href="<?= h(member_profile_url((int)$p['proposer_id'])) ?>"><?= h(first_name($p['proposer_name'])) ?></a></strong> bietet
                <?= h(fmt_slot($p['starts_at'], $p['ends_at'])) ?><?= $p['label'] ? ' (' . h($p['label']) . ')' : '' ?>
                <span class="muted">für deine</span>
                <?= h(fmt_slot($ofs['starts_at'], $ofs['ends_at'])) ?><?= $ofs['label'] ? ' (' . h($ofs['label']) . ')' : '' ?>
              </span>
              <span class="brow-act">
                <form method="post" style="margin:0" id="acc<?= (int)$p['id'] ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="proposal_accept">
                  <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn small buy" type="button" onclick="astaConfirm({title:'Tausch annehmen',icon:'ti-arrows-exchange',message:'Tausch verbindlich durchführen? Beide Einteilungen werden getauscht und die Event-Orga informiert.',buttons:[{label:'Abbrechen',class:'secondary'},{label:'Annehmen',class:'',onClick:function(){document.getElementById('acc<?= (int)$p['id'] ?>').submit();}}]})"><i class="ti ti-check"></i> Annehmen</button>
                </form>
                <form method="post" style="margin:0" data-confirm="Diesen Tausch-Vorschlag ablehnen?" data-confirm-title="Ablehnen" data-confirm-ok="Ablehnen" data-confirm-danger>
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="proposal_decline">
                  <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn small secondary" type="submit"><i class="ti ti-x"></i> Ablehnen</button>
                </form>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php
// ===== Satire: Schicht-Depot (wertlos, rein dekorativ) =====
$ser = $depot['series']; $n = count($ser);
$lo = min($ser); $hi = max($ser); $span = ($hi - $lo) ?: 1;
$lo -= $span * 0.12; $hi += $span * 0.12; $span = $hi - $lo;
$W = 760; $H = 240; $padL = 58; $padR = 14; $padT = 14; $padB = 24;
$plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
$px = fn($i) => $padL + ($n > 1 ? $i / ($n - 1) : 0) * $plotW;
$py = fn($v) => $padT + (1 - ($v - $lo) / $span) * $plotH;
$pts = [];
for ($i = 0; $i < $n; $i++) $pts[] = round($px($i), 1) . ',' . round($py($ser[$i]), 1);
$linePts = implode(' ', $pts);
$areaPts = round($padL, 1) . ',' . round($padT + $plotH, 1) . ' ' . $linePts . ' ' . round($padL + $plotW, 1) . ',' . round($padT + $plotH, 1);
$up = $depot['changePct'] >= 0;
$gridN = 4;
?>
<div class="section-title" id="depot" style="margin-top:2rem"><i class="ti ti-chart-histogram"></i> Dein Schicht-Depot</div>
<div class="card depot-card depot-<?= $up ? 'up' : 'down' ?>">
  <div class="depot-head">
    <div class="depot-id">
      <span class="depot-ticker">ASX</span>
      <div>
        <div class="depot-name">AStA Schicht-Aktie · <span class="depot-live"><span class="dl-dot"></span>Live</span></div>
        <div class="depot-sub"><?= h(org_name_kurz()) ?> Schicht-Index · Xetra-Schicht</div>
      </div>
    </div>
    <div class="depot-price">
      <div class="depot-kurs"><?= number_format($depot['kurs'], 2, ',', '.') ?> <span class="depot-cur">AsT</span></div>
      <div class="depot-chg <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= number_format(abs($depot['changePct']), 2, ',', '.') ?> % <span class="muted">heute</span></div>
    </div>
  </div>

  <div class="depot-kpis">
    <div class="dkpi"><span class="dkpi-l">Depotwert</span><span class="dkpi-v"><?= h($money($depotGesamt)) ?></span></div>
    <?php /* Zwei verschiedene Dinge, deshalb immer beide: oben alles im Depot (Aktien + Sparpläne),
             hier das, was gerade ausgegeben werden kann – Angelegtes fehlt darin. */ ?>
    <div class="dkpi"><span class="dkpi-l">Verfügbar</span><span class="dkpi-v"><?= h($money($depotFrei)) ?></span></div>
    <div class="dkpi"><span class="dkpi-l">Position</span><span class="dkpi-v"><?= number_format($depot['shares'], 0, ',', '.') ?> ASX</span></div>
    <div class="dkpi"><span class="dkpi-l">G/V seit Emission</span><span class="dkpi-v <?= $depot['gvPct'] >= 0 ? 'up' : 'down' ?>"><?= $depot['gvPct'] >= 0 ? '+' : '' ?><?= number_format($depot['gvPct'], 2, ',', '.') ?> %</span></div>
    <div class="dkpi"><span class="dkpi-l">Tageshoch / -tief</span><span class="dkpi-v"><?= number_format($depot['high'], 2, ',', '.') ?> / <?= number_format($depot['low'], 2, ',', '.') ?></span></div>
  </div>

  <div class="depot-chartwrap">
    <svg class="depot-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" preserveAspectRatio="none" role="img" aria-label="Kursverlauf ASX">
      <defs>
        <linearGradient id="depotFill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" class="df-top"/><stop offset="100%" class="df-bot"/>
        </linearGradient>
      </defs>
      <?php for ($g = 0; $g <= $gridN; $g++): $gy = $padT + $g / $gridN * $plotH; $gv = $hi - $g / $gridN * $span; ?>
        <line class="dc-grid" x1="<?= $padL ?>" y1="<?= round($gy, 1) ?>" x2="<?= $padL + $plotW ?>" y2="<?= round($gy, 1) ?>"/>
        <text class="dc-axis" x="<?= $padL - 8 ?>" y="<?= round($gy + 3, 1) ?>" text-anchor="end"><?= number_format($gv, 0, ',', '.') ?></text>
      <?php endfor; ?>
      <polygon class="dc-area" points="<?= h($areaPts) ?>" fill="url(#depotFill)"/>
      <polyline class="dc-line" points="<?= h($linePts) ?>" fill="none"/>
      <circle class="dc-dot" cx="<?= round($px($n - 1), 1) ?>" cy="<?= round($py($ser[$n - 1]), 1) ?>" r="3.5"/>
    </svg>
    <div class="depot-xaxis"><span>−30 Handelstage</span><span>−20</span><span>−10</span><span>heute</span></div>
  </div>

  <table class="list depot-holdings">
    <?php /* Ohne Spalte „Wert": Stück × Kurs wäre das, was man OHNE jeden Einkauf hätte – eine
             Zahl, die niemand braucht und die neben dem Depotwert wie ein Widerspruch aussieht.
             Was man hat, steht oben; hier steht, woher es kommt (Tausche und Kursstand). */ ?>
    <thead><tr><th>Position</th><th>Stück</th><th>Ausgabekurs</th><th>Kurs</th><th>G/V</th></tr></thead>
    <tbody>
      <tr>
        <td><strong>ASX</strong> <span class="muted small">AStA Schicht-Aktie</span></td>
        <td><?= number_format($depot['shares'], 0, ',', '.') ?></td>
        <td><?= number_format(ASX_BASE, 2, ',', '.') ?></td>
        <td><?= number_format($depot['kurs'], 2, ',', '.') ?></td>
        <td class="<?= $depot['gvPct'] >= 0 ? 'up' : 'down' ?>"><?= $depot['gvPct'] >= 0 ? '+' : '' ?><?= number_format($depot['gvPct'], 2, ',', '.') ?> %</td>
      </tr>
    </tbody>
  </table>

  <table class="list depot-holdings depot-etfs">
    <thead><tr><th>Sparplan</th><th>Kurs</th><th>Angelegt</th><th>Wert</th><th>G/V</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($etfRows as $pk => $er): $hat = $er['einzahlung'] > 0;
            $gvE = $hat ? ($er['wert'] - $er['einzahlung']) : 0;
            $gvPctE = $hat && $er['einzahlung'] > 0 ? $gvE / $er['einzahlung'] * 100 : 0.0; ?>
        <tr>
          <td><strong><?= h($er['label']) ?></strong> <span class="muted small"><?= h($er['risk']) ?></span></td>
          <td data-l="Kurs"><?= number_format($er['kurs'], 2, ',', '.') ?>
            <span class="small <?= $er['chg'] >= 0 ? 'up' : 'down' ?>"><?= $er['chg'] >= 0 ? '▲' : '▼' ?> <?= number_format(abs($er['chg']), 2, ',', '.') ?> %</span></td>
          <td class="muted small<?= $hat ? '' : ' etf-leer' ?>" data-l="Angelegt"><?= $hat ? number_format($er['einzahlung'], 0, ',', '.') : '–' ?></td>
          <td<?= $hat ? '' : ' class="etf-leer"' ?> data-l="Wert"><?= $hat ? h($money($er['wert'])) : '<span class="muted small">–</span>' ?></td>
          <td class="<?= $gvE >= 0 ? 'up' : 'down' ?><?= $hat ? '' : ' etf-leer' ?>" data-l="G/V"><?= $hat ? (($gvE >= 0 ? '+' : '') . number_format($gvPctE, 1, ',', '.') . ' %') : '' ?></td>
          <td class="depot-etf-aktion">
            <form method="post" data-confirm="Wirklich in „<?= h($er['label']) ?>" (<?= h($er['risk']) ?>) anlegen? Die Kurse würfelt der Markt – auch nach unten." data-confirm-ok="Anlegen">
              <?= csrf_field() ?><input type="hidden" name="action" value="etf_buy"><input type="hidden" name="plan" value="<?= h($pk) ?>">
              <input type="number" name="amount" min="100" step="100" placeholder="AsT" aria-label="Betrag in AsT" required>
              <button class="btn secondary small" type="submit">Anlegen</button>
            </form>
            <?php if ($hat): ?>
              <form method="post" data-confirm="„<?= h($er['label']) ?>" komplett zum heutigen Kurs verkaufen (<?= h($money($er['wert'])) ?>)?" data-confirm-ok="Verkaufen">
                <?= csrf_field() ?><input type="hidden" name="action" value="etf_sell"><input type="hidden" name="plan" value="<?= h($pk) ?>">
                <button class="btn secondary small" type="submit">Verkaufen</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="depot-disclaimer">Jeder vollzogene Tausch schreibt den Beteiligten <strong>100 ASX</strong> gut; der Kurs steigt mit jedem marktweiten Tausch. Die Sparplan-Kurse würfelt der Markt täglich neu aus – für alle gleich, nur nicht vorhersehbar. <strong>Hochspekulativ.</strong> Aktien ohne Gegenwert, ohne Stimmrecht, ohne Dividende und ohne Sinn – auszahlbar einzig in <a href="achievements.php#locker">Avatar-Schmuck</a>. Vergangene Tausch-Performance ist kein Indikator für künftige Schichten. Keine Anlageberatung. Marktdaten unverbindlich, fiktiv und mit 0 Sekunden Verzögerung frei erfunden.</p>
</div>

<?php page_footer(); ?>
