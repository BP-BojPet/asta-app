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
    }
    redirect('tauschboerse.php' . $anchor);
}

$memberId = $me ? (int)$me['id'] : 0;
$events = member_swap_events($memberId);

$shortWhen = fn($start) => ($t = strtotime((string)$start)) ? date('d.m. H:i', $t) : '';

$myProps = swap_proposals_by_member($memberId); // [swap_id => proposal] – meine offenen Vorschläge
$boards = [];
$incomingTotal = 0;
$freeTotal = 0;                                  // abgegebene Schichten anderer, die man übernehmen kann
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
        } elseif (($sw['mode'] ?? 'swap') === 'giveaway') {
            $freeTotal++;
        }
    }
    $incomingTotal += count($incoming);
    $tradable = array_values(array_filter($mine, fn($s) => empty($s['locked'])));
    $boards[] = ['e' => $e, 'eid' => $eid, 'mine' => $mine, 'offers' => $offers, 'offeredMap' => $offeredMap, 'offeredMode' => $offeredMode, 'tradable' => $tradable, 'incoming' => $incoming];
}

// Guthaben: woher die AsT kommen und wofür sie weg sind (Rechnung siehe ast_balance)
$ast = fn($v) => number_format((int)$v, 0, ',', '.') . ' AsT';
$verdienst = ast_verdienst($memberId);
$altbestand = 0; $geschenkt = 0;
if ($memberId > 0) {
    $gs = db()->prepare('SELECT reason, COALESCE(SUM(amount), 0) AS n FROM ast_grants WHERE member_id = ? GROUP BY reason = ?');
    $gs->execute([$memberId, AST_ALTBESTAND_GRUND]);
    foreach ($gs->fetchAll() as $g) {
        if ((string)$g['reason'] === AST_ALTBESTAND_GRUND) $altbestand += (int)$g['n']; else $geschenkt += (int)$g['n'];
    }
}
$ausgegeben = ast_spent($memberId);
$guthaben = ast_balance($memberId);

page_header('Schichtbörse');
?>
<p class="small"><a href="events.php">‹ Alle Events</a></p>

<div class="section-title" style="margin-top:0"><i class="ti ti-arrows-exchange"></i> Schichtbörse</div>
<div class="card boerse-intro">
  <?php $swLead = swap_lead_days(); ?>
  <p style="margin:0 0 .7rem">Du kannst eine Schicht nicht übernehmen? Hier wirst du sie los – und kannst anderen eine abnehmen.</p>
  <ul class="boerse-wege">
    <li><i class="ti ti-arrows-exchange"></i><span><strong>Tauschen:</strong> Du bietest eine Schicht an, jemand schlägt eine eigene dafür vor. Getauscht wird erst, wenn du zustimmst.</span></li>
    <li><i class="ti ti-gift"></i><span><strong>Abgeben:</strong> Du gibst eine Schicht frei. Wer sie übernimmt, hat sie sofort – du bist sie los.</span></li>
    <li><i class="ti ti-hand-grab"></i><span><strong>Übernehmen:</strong> Eine abgegebene Schicht ohne Gegen-Schicht abnehmen – dafür gibt es <strong>+<?= h($ast(AST_BONUS_ABNAHME)) ?></strong>.</span></li>
  </ul>
  <p class="small muted" style="margin:.7rem 0 0">Überschneidungen prüft die App selbst. Offen ist die Börse, sobald der Arbeitsplan steht, bis <?= (int)$swLead ?> Tag<?= $swLead === 1 ? '' : 'e' ?> vor Event-Beginn (die Orga kann sie länger offen halten).</p>
</div>

<?php if (!$me): ?>
  <div class="card"><p class="empty"><i class="ti ti-user-off"></i> Die Börse ist nur für eingeloggte Mitglieder. Mit dem Technik-Login kannst du nicht tauschen.</p></div>
  <?php page_footer(); exit; ?>
<?php endif; ?>

<div class="boerse-stats">
  <?php if ($incomingTotal > 0): ?>
    <div class="bstat bstat-hot"><span class="bstat-n"><?= $incomingTotal ?></span><span class="bstat-l">Tausch-Anfrage<?= $incomingTotal === 1 ? '' : 'n' ?> an dich – unten beim Event</span></div>
  <?php endif; ?>
  <div class="bstat"><span class="bstat-n"><?= $freeTotal ?></span><span class="bstat-l">Schicht<?= $freeTotal === 1 ? '' : 'en' ?> zu haben</span></div>
  <a class="bstat bstat-link" href="#guthaben"><span class="bstat-n"><?= h($ast($guthaben)) ?></span><span class="bstat-l">dein Guthaben</span></a>
</div>

<?php if (!$boards): ?>
  <div class="card"><p class="empty"><i class="ti ti-mood-empty"></i> Gerade ist für dich keine Börse offen. Sie öffnet, sobald es einen <strong>veröffentlichten Arbeitsplan</strong> gibt, in dem du <strong>eingeteilt</strong> bist, und das Event noch <strong>weit genug entfernt</strong> ist (oder die Orga sie offen hält).</p></div>
<?php endif; ?>

<?php foreach ($boards as $b): $e = $b['e']; $eid = $b['eid'];
    $range = fmt_event_range($e['starts_at'] ?? null, $e['ends_at'] ?? null);
    $othersOffers = array_values(array_filter($b['offers'], fn($sw) => (int)$sw['offered_by'] !== $memberId));
?>
  <section class="boerse-floor" id="ev<?= $eid ?>">
    <header class="floor-head">
      <a class="floor-name" href="event.php?id=<?= $eid ?>"><?= h($e['title']) ?></a>
      <?php if ($range): ?><span class="floor-date"><i class="ti ti-calendar-event"></i> <?= h($range) ?></span><?php endif; ?>
    </header>

    <?php if ($b['incoming']): ?>
      <div class="swap-requests">
        <div class="swap-requests-h"><i class="ti ti-inbox"></i> Tausch-Anfragen an dich <span class="sr-count"><?= count($b['incoming']) ?></span></div>
        <ul class="boerse-list">
          <?php foreach ($b['incoming'] as $p): $ofs = $p['offer']; ?>
            <li class="boerse-row offer">
              <span class="brow-when"><strong><a class="member-link" href="<?= h(member_profile_url((int)$p['proposer_id'])) ?>"><?= h(first_name($p['proposer_name'])) ?></a></strong> würde
                <strong><?= h(fmt_slot($p['starts_at'], $p['ends_at'])) ?></strong><?= $p['label'] ? ' (' . h($p['label']) . ')' : '' ?>
                gegen deine
                <strong><?= h(fmt_slot($ofs['starts_at'], $ofs['ends_at'])) ?></strong><?= $ofs['label'] ? ' (' . h($ofs['label']) . ')' : '' ?> tauschen
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

    <div class="floor-cols">
      <!-- Eigene Positionen -->
      <div class="floor-col">
        <h3 class="floor-col-h"><i class="ti ti-user"></i> Deine Schichten</h3>
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
                  <span class="brow-tag live"><i class="ti ti-<?= $isGive ? 'gift' : 'arrows-exchange' ?>"></i> <?= $isGive ? 'abgegeben' : 'zum Tausch angeboten' ?></span>
                  <button class="btn small secondary" type="submit"><i class="ti ti-x"></i> Zurückziehen</button>
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

      <!-- Angebote der anderen -->
      <div class="floor-col">
        <h3 class="floor-col-h"><i class="ti ti-users"></i> Angebote der anderen</h3>
        <?php if (!$othersOffers): ?>
          <p class="small muted boerse-empty">Gerade bietet hier niemand eine Schicht an.</p>
        <?php else: ?>
          <ul class="boerse-list">
            <?php foreach ($othersOffers as $sw):
              $isGive = ($sw['mode'] ?? 'swap') === 'giveaway';
              $giveable = array_values(array_filter($b['tradable'], fn($s) => (int)$s['id'] !== (int)$sw['slot_id'])); ?>
              <li class="boerse-row offer<?= $isGive ? ' giveaway' : '' ?>">
                <span class="brow-when"><strong><a class="member-link" href="<?= h(member_profile_url((int)$sw['offered_by'])) ?>"><?= h(first_name($sw['offerer_name'])) ?></a></strong> <?= $isGive ? 'gibt ab:' : 'möchte tauschen:' ?>
                  <?= h(fmt_slot($sw['starts_at'], $sw['ends_at'])) ?>
                  <?php if ($sw['label']): ?><span class="muted">(<?= h($sw['label']) ?>)</span><?php endif; ?>
                  <?php if (trim((string)$sw['location']) !== ''): ?><span class="muted">· <?= h($sw['location']) ?></span><?php endif; ?>
                  <?php if ($isGive): ?><span class="brow-tag give"><i class="ti ti-coins"></i> +<?= h($ast(AST_BONUS_ABNAHME)) ?></span><?php endif; ?>
                </span>
                <?php if ($isGive): ?>
                  <form method="post" class="brow-act" data-confirm="Diese Schicht jetzt übernehmen? Sie kommt fest in deinen Plan, eine Gegen-Schicht gibst du nicht ab. Ist sie vorbei, gibt es <?= h($ast(AST_JE_SCHICHT + AST_BONUS_ABNAHME)) ?>." data-confirm-title="Schicht übernehmen" data-confirm-ok="Übernehmen">
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
                  <span class="brow-tag muted">du hast hier keine Schicht zum Tauschen</span>
                <?php else: ?>
                  <form method="post" class="brow-act brow-accept">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="swap_propose">
                    <input type="hidden" name="swap_id" value="<?= (int)$sw['id'] ?>">
                    <label class="brow-give">dafür gibst du:
                      <select name="take_slot_id" required>
                        <?php foreach ($giveable as $g): ?>
                          <option value="<?= (int)$g['id'] ?>"><?= h($shortWhen($g['starts_at'])) ?><?= $g['label'] ? ' (' . h($g['label']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <button class="btn small buy" type="submit"><i class="ti ti-send"></i> Tausch vorschlagen</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

  </section>
<?php endforeach; ?>

<div class="section-title" id="guthaben" style="margin-top:2rem"><i class="ti ti-coins"></i> Dein Guthaben</div>
<div class="card ast-konto">
  <div class="ast-konto-summe"><span><?= h($ast($guthaben)) ?></span> verfügbar
    <a class="btn secondary small" href="achievements.php#locker"><i class="ti ti-shopping-bag"></i> Zum Belohnungs-Locker</a></div>
  <table class="list ast-konto-tab">
    <tbody>
      <tr><td>Startguthaben</td><td><?= h($ast(AST_STARTGUTHABEN)) ?></td></tr>
      <tr><td>Geleistete Schichten <span class="muted small"><?= (int)$verdienst['schichten'] ?> × <?= h($ast(AST_JE_SCHICHT)) ?></span></td><td><?= h($ast($verdienst['schichten'] * AST_JE_SCHICHT)) ?></td></tr>
      <tr><td>Aus der Börse übernommen <span class="muted small"><?= (int)$verdienst['abnahmen'] ?> × <?= h($ast(AST_BONUS_ABNAHME)) ?></span></td><td><?= h($ast($verdienst['abnahmen'] * AST_BONUS_ABNAHME)) ?></td></tr>
      <tr><td>Kurzfristig eingesprungen <span class="muted small"><?= (int)$verdienst['einspringen'] ?> × <?= h($ast(AST_BONUS_EINSPRINGEN)) ?></span></td><td><?= h($ast($verdienst['einspringen'] * AST_BONUS_EINSPRINGEN)) ?></td></tr>
      <?php if ($altbestand > 0): ?><tr><td>Übernommen aus der alten Börse</td><td><?= h($ast($altbestand)) ?></td></tr><?php endif; ?>
      <?php if ($geschenkt > 0): ?><tr><td>Geschenkt bekommen</td><td><?= h($ast($geschenkt)) ?></td></tr><?php endif; ?>
      <?php if ($ausgegeben > 0): ?><tr><td>Im Locker ausgegeben</td><td>−<?= h($ast($ausgegeben)) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="small muted" style="margin:.7rem 0 0"><strong>So verdienst du AsT:</strong> Jede Schicht, die du leistest, bringt <?= h($ast(AST_JE_SCHICHT)) ?>.
    Übernimmst du eine abgegebene Schicht aus der Börse oder springst kurzfristig in eine offene Schicht ein, kommen <?= h($ast(AST_BONUS_ABNAHME)) ?> dazu.
    Gutgeschrieben wird, wenn die Schicht vorbei ist. Ein Tausch bringt nichts – du arbeitest ja gleich viel.</p>
</div>

<?php page_footer(); ?>
