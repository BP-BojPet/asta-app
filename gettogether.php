<?php
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;

$id = (int)($_GET['id'] ?? 0);
$gt = gettogether_get($id);
if (!$gt) { http_response_code(404); page_header('Nicht gefunden'); echo '<p>Get-Together nicht gefunden.</p>'; page_footer(); exit; }
$isOrg = can_manage_gettogethers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    // Helfer: gehört ein Item zu diesem Get-Together?
    $ownItem = function (int $itemId) use ($id): bool {
        $it = gettogether_item_get($itemId);
        return $it && (int)$it['gettogether_id'] === $id;
    };
    if ($action === 'rsvp' && $me) {
        gettogether_rsvp_set($id, $meId, (string)($_POST['status'] ?? ''));
    } elseif ($action === 'add_need' && $isOrg) {
        gettogether_item_add($id, (string)($_POST['label'] ?? ''), true, null, $meId ?: null);
    } elseif ($action === 'add_bring' && $me) {
        gettogether_item_add($id, (string)($_POST['label'] ?? ''), false, $meId, $meId);
        if (gettogether_rsvp_status($id, $meId) === null) gettogether_rsvp_set($id, $meId, 'yes'); // wer mitbringt, ist dabei
    } elseif ($action === 'claim' && $me) {
        if ($ownItem((int)($_POST['item_id'] ?? 0))) {
            gettogether_item_claim((int)$_POST['item_id'], $meId);
            if (gettogether_rsvp_status($id, $meId) === null) gettogether_rsvp_set($id, $meId, 'yes');
        }
    } elseif ($action === 'release' && $me) {
        if ($ownItem((int)($_POST['item_id'] ?? 0))) gettogether_item_release((int)$_POST['item_id'], $meId, $isOrg);
    } elseif ($action === 'del_item' && $isOrg) {
        if ($ownItem((int)($_POST['item_id'] ?? 0))) gettogether_item_delete((int)$_POST['item_id']);
    }
    redirect('gettogether.php?id=' . $id);
}

$myStatus  = gettogether_rsvp_status($id, $meId);
$attendees = gettogether_attendees($id);
$maybes    = gettogether_attendees($id, 'maybe');
$items     = gettogether_items($id);
$counts    = gettogether_rsvp_counts($id);
$loc = trim((string)$gt['location']);

// Wer hält gerade die Spitzenklasse? (eine Query für alle – für royale Titel in der „Wer kommt"-Liste)
$spitzeIds = [];
try {
    $spitzeIds = array_flip(array_map('intval', array_column(
        db()->query("SELECT member_id FROM member_achievements WHERE code = 'spitze'")->fetchAll(), 'member_id')));
} catch (\Throwable $e) {}

/** Teilnahme-Chip: zentraler Renderer (lib.php) + Pronomen-Suffix und „me"-Markierung; klickbar → Nutzerprofil. */
$gtChip = function (array $a, string $extraCls = '') use ($meId, $spitzeIds): void {
    $self   = $meId && (int)$a['id'] === $meId;
    $pron   = trim((string)($a['pronouns'] ?? ''));
    $suffix = $pron !== '' ? ' <span class="muted small">(' . h($pron) . ')</span>' : '';
    echo member_chip_link($a, isset($spitzeIds[(int)$a['id']]), $extraCls . ($self ? ' me' : ''), $suffix);
};

page_header($gt['title']);
?>
<p class="small"><a href="events.php#gettogethers">‹ Alle Get-Togethers</a></p>

<div class="card event-hero gt-hero">
  <div class="hero-head">
    <h1 class="event-title"><i class="ti ti-confetti" style="color:var(--violet)"></i> <?= h($gt['title']) ?></h1>
    <?= share_button('gettogether.php?id=' . (int)$gt['id'], 'Get-Together-Link teilen') ?>
  </div>
  <div class="event-facts">
    <span class="fact"><i class="ti ti-calendar-event"></i> <?= h(fmt_slot($gt['starts_at'], $gt['ends_at'])) ?></span>
    <?php if ($loc !== ''): ?><span class="fact"><i class="ti ti-map-pin"></i> <?= h($loc) ?></span><?php endif; ?>
    <span class="fact"><i class="ti ti-users"></i> <?= $counts['yes'] ?> dabei<?= $counts['maybe'] ? ' · ' . $counts['maybe'] . ' vielleicht' : '' ?><?= $counts['no'] ? ' · ' . $counts['no'] . ' nicht' : '' ?></span>
  </div>
  <?php if (trim((string)$gt['description']) !== ''): ?><p class="event-desc"><?= nl2br(h($gt['description'])) ?></p><?php endif; ?>
  <p class="small muted" style="margin:.3rem 0 0">Internes Spaß-Treffen, freiwillig.<?php if ($isOrg): ?> Du kannst Bedarf festlegen (z. B. Snacks), den die Leute übernehmen.<?php endif; ?></p>
  <?php if ($isOrg): ?><div class="event-byline"><span class="byline-meta"><a href="admin/gettogethers.php"><i class="ti ti-edit"></i> verwalten</a></span></div><?php endif; ?>
</div>

<?php if ($me): ?>
  <div class="card">
    <div class="gt-rsvp">
      <span class="gt-q"><?= $myStatus === null ? 'Bist du dabei?' : 'Deine Rückmeldung:' ?></span>
      <form method="post" class="gt-pick">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rsvp">
        <button type="submit" name="status" value="yes" data-puste="zusage" class="gt-btn gt-yes<?= $myStatus === 'yes' ? ' on' : '' ?>"><i class="ti ti-check"></i> Dabei</button>
        <button type="submit" name="status" value="maybe" class="gt-btn gt-maybe<?= $myStatus === 'maybe' ? ' on' : '' ?>"><i class="ti ti-help"></i> Vielleicht</button>
        <button type="submit" name="status" value="no" class="gt-btn gt-no<?= $myStatus === 'no' ? ' on' : '' ?>"><i class="ti ti-x"></i> Nicht</button>
      </form>
      <?php if ($myStatus === 'no'): ?><span class="small muted gt-hint"><i class="ti ti-calendar-off"></i> aus deinem Kalender ausgeblendet</span><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="duo">
  <!-- Wer kommt -->
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-users" style="color:var(--violet)"></i> Wer kommt <span class="count"><?= count($attendees) ?></span></div>
    <?php if (!$attendees): ?>
      <p class="empty"><i class="ti ti-mood-smile"></i> Noch keine Zusagen.</p>
    <?php else: ?>
      <div class="gt-people gt-people-av">
        <?php foreach ($attendees as $a) $gtChip($a); ?>
      </div>
    <?php endif; ?>
    <?php if ($maybes): ?>
      <div class="gt-maybe-line"><i class="ti ti-help"></i> Vielleicht <span class="count"><?= count($maybes) ?></span></div>
      <div class="gt-people gt-people-av">
        <?php foreach ($maybes as $a) $gtChip($a, ' gt-chip-maybe'); ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Mitbringen -->
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-basket" style="color:var(--violet)"></i> Wer bringt was mit</div>
    <?php if (!$items): ?>
      <p class="empty" style="margin-bottom:.6rem"><i class="ti ti-basket-off"></i> Noch nichts auf der Liste.</p>
    <?php else: ?>
      <ul class="gt-items">
        <?php foreach ($items as $it): $iid = (int)$it['id']; $taken = $it['brought_by'] !== null;
            $mineItem = $taken && (int)$it['brought_by'] === $meId; ?>
          <li class="gt-item<?= $taken ? ' taken' : ' open' ?>">
            <span class="gti-label">
              <i class="ti <?= $taken ? 'ti-circle-check' : 'ti-circle-dashed' ?>"></i>
              <strong><?= h($it['label']) ?></strong>
              <?php if (!empty($it['is_need']) && !$taken): ?><span class="gti-tag">Bedarf</span><?php endif; ?>
              <?php if ($taken): $bRow = ['id' => (int)$it['brought_by'], 'name' => (string)$it['bringer_name'], 'pronouns' => (string)($it['bringer_pronouns'] ?? ''), 'avatar_decos' => (string)($it['bringer_decos'] ?? ''), 'avatar_palette' => (string)($it['bringer_palette'] ?? '')]; ?>
                <span class="gti-by">· <?= member_chip_link($bRow, isset($spitzeIds[(int)$it['brought_by']]), ' gt-chip-mini') ?> bringt’s mit</span><?php endif; ?>
            </span>
            <span class="gti-act">
              <?php if (!$taken && $me): ?>
                <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><input type="hidden" name="item_id" value="<?= $iid ?>"><button class="btn small buy" type="submit"><i class="ti ti-hand-finger"></i> Bring ich mit</button></form>
              <?php endif; ?>
              <?php if (($mineItem || $isOrg) && $me): ?>
                <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="release"><input type="hidden" name="item_id" value="<?= $iid ?>"><button class="btn small secondary" type="submit" title="<?= !empty($it['is_need']) ? 'wieder freigeben' : 'entfernen' ?>"><i class="ti ti-arrow-back-up"></i></button></form>
              <?php endif; ?>
              <?php if ($isOrg): ?>
                <form method="post" style="margin:0" data-confirm="Diesen Eintrag wirklich von der Liste löschen?" data-confirm-danger data-confirm-ok="Löschen" data-confirm-title="Eintrag löschen"><?= csrf_field() ?><input type="hidden" name="action" value="del_item"><input type="hidden" name="item_id" value="<?= $iid ?>"><button class="btn small danger" type="submit"><i class="ti ti-trash"></i></button></form>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($me): ?>
      <form method="post" class="gt-add">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_bring">
        <input type="text" name="label" placeholder="Ich bringe mit … (z. B. Chips, Cola)" required>
        <button class="btn small" type="submit"><i class="ti ti-plus"></i> Hinzufügen</button>
      </form>
    <?php endif; ?>
    <?php if ($isOrg): ?>
      <form method="post" class="gt-add gt-add-need">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_need">
        <input type="text" name="label" placeholder="Bedarf festlegen … (z. B. Grillgut, Becher)" required>
        <button class="btn small secondary" type="submit"><i class="ti ti-clipboard-plus"></i> Als Bedarf</button>
      </form>
      <p class="small muted" style="margin:.4rem 0 0">„Bedarf" steht offen auf der Liste, bis jemand auf „Bring ich mit" tippt.</p>
    <?php endif; ?>
  </div>
</div>

<?php page_footer(); ?>
