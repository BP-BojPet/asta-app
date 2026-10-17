<?php
/**
 * Raumbelegung: Wochenübersicht aller Räume, buchen, und für Admin, Vorsitz und Sekretariat die
 * Pflege von Räumen und festen Zeiten. Ansicht und Formulare kommen aus lib.php
 * (raum_wochenansicht / raum_post_verarbeiten) – dieselben nutzt der StuPa-Bereich.
 */
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;
$verwalter = raum_kann_verwalten();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$me) {
        flash('Mit dem Technik-Login kannst du keine Räume buchen – melde dich als Mitglied an.', 'error');
    } elseif ($r = raum_post_verarbeiten('asta', $meId, $verwalter)) {
        flash($r[0], $r[1]);
    }
    redirect('raeume.php?w=' . raum_woche_nach_post());
}

$woche = raum_woche_nach_post();

page_header('Raumbelegung');
?>
<div class="section-title" style="margin-top:0"><i class="ti ti-door"></i> Raumbelegung</div>
<p class="small muted" style="margin:-.3rem 0 .8rem">Wann welcher Raum belegt ist – und frei buchen, was frei ist. Das StuPa-Präsidium sieht und bucht über seinen eigenen Zugang mit.</p>

<?= raum_wochenansicht(['woche' => $woche, 'self' => 'raeume.php', 'seite' => 'asta', 'von_id' => $meId, 'verwalter' => $verwalter, 'fp' => true]) ?>

<?php if ($verwalter): $tage = raum_wochentage(); ?>
  <details class="collapse-card" id="verwalten">
  <summary class="section-title"><i class="ti ti-settings"></i> Räume &amp; feste Zeiten<i class="ti ti-chevron-down collapse-chev"></i></summary>
  <div class="card">
    <h3 class="raum-h3">Räume</h3>
    <?php foreach (raum_alle(false) as $r): ?>
      <form method="post" class="raum-formular">
        <?= csrf_field() ?><input type="hidden" name="action" value="raum_speichern"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <label>Name<input type="text" name="name" value="<?= h((string)$r['name']) ?>" required maxlength="80"></label>
        <label class="raum-anlass">Beschreibung<input type="text" name="info" value="<?= h((string)$r['info']) ?>" maxlength="300" placeholder="z. B. Gebäude, Plätze, Schlüssel"></label>
        <input type="hidden" name="active" value="0">
        <label class="raum-fest"><input type="checkbox" name="active" value="1"<?= !empty($r['active']) ? ' checked' : '' ?>> buchbar</label>
        <button class="btn secondary small" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
      </form>
      <form method="post" class="raum-loeschen" data-confirm="„<?= h((string)$r['name']) ?>" samt aller Buchungen und festen Zeiten löschen? Wer dort noch etwas gebucht hat, bekommt eine Mail." data-confirm-danger data-confirm-ok="Löschen">
        <?= csrf_field() ?><input type="hidden" name="action" value="raum_entfernen"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn danger small" type="submit"><i class="ti ti-trash"></i> Raum löschen</button>
      </form>
    <?php endforeach; ?>
    <form method="post" class="raum-formular" style="margin-top:.8rem">
      <?= csrf_field() ?><input type="hidden" name="action" value="raum_speichern"><input type="hidden" name="id" value="0">
      <label>Neuer Raum<input type="text" name="name" required maxlength="80" placeholder="Name"></label>
      <label class="raum-anlass">Beschreibung<input type="text" name="info" maxlength="300" placeholder="optional"></label>
      <button class="btn small" type="submit"><i class="ti ti-plus"></i> Raum anlegen</button>
    </form>

    <?php $raeumeAktiv = raum_alle(); if ($raeumeAktiv): ?>
      <h3 class="raum-h3">Feste Zeiten (jede Woche)</h3>
      <?php $serien = raum_serien(); ?>
      <?php if (!$serien): ?><p class="small muted" style="margin:0 0 .6rem">Noch keine festen Zeiten.</p><?php endif; ?>
      <ul class="raum-serien">
        <?php foreach ($serien as $sr): ?>
          <li><strong><?= h((string)$sr['room_name']) ?></strong> · <?= h($tage[(int)$sr['weekday']] ?? '') ?> <?= h($sr['time_from'] . '–' . $sr['time_to']) ?> Uhr ·
            <?= h((string)$sr['title']) ?> <span class="muted small">(<?= h(fmt_date((string)$sr['date_from'])) ?> bis <?= h(fmt_date((string)$sr['date_to'])) ?>)</span>
            <form method="post" class="raum-x" data-confirm="Diese feste Zeit löschen? Alle ihre Termine werden wieder frei." data-confirm-danger data-confirm-ok="Löschen">
              <?= csrf_field() ?><input type="hidden" name="action" value="serie_loeschen"><input type="hidden" name="id" value="<?= (int)$sr['id'] ?>">
              <button type="submit" class="raum-x-btn" title="Löschen" aria-label="Löschen"><i class="ti ti-trash"></i></button>
            </form></li>
        <?php endforeach; ?>
      </ul>
      <form method="post" class="raum-formular">
        <?= csrf_field() ?><input type="hidden" name="action" value="serie_anlegen">
        <label>Raum<select name="room_id"><?php foreach ($raeumeAktiv as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option><?php endforeach; ?></select></label>
        <label>Wochentag<select name="weekday"><?php foreach ($tage as $k => $t): ?><option value="<?= $k ?>"><?= h($t) ?></option><?php endforeach; ?></select></label>
        <label>von<input type="text" class="fp-time" name="von" required placeholder="10:00"></label>
        <label>bis<input type="text" class="fp-time" name="bis" required placeholder="12:00"></label>
        <label>ab<input type="text" class="fp-date" name="tag_von" required placeholder="erster Tag"></label>
        <label>bis einschließlich<input type="text" class="fp-date" name="tag_bis" required placeholder="letzter Tag"></label>
        <label class="raum-anlass">Anlass<input type="text" name="titel" required maxlength="120" placeholder="z. B. Sprechstunde"></label>
        <button class="btn small" type="submit"><i class="ti ti-repeat"></i> Feste Zeit eintragen</button>
      </form>
      <p class="small muted" style="margin:.5rem 0 0">Einzelne Termine einer festen Zeit lässt du oben in der Wochenansicht über <i class="ti ti-calendar-off"></i> ausfallen. Einmalige feste Termine trägst du unter „Raum buchen" mit dem Häkchen „als feste Belegung" ein.</p>
    <?php endif; ?>
  </div>
  </details>
<?php endif; ?>
<?php
page_footer();
