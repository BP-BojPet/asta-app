<?php
/**
 * Selbstbedienung: eigene Anmeldung ansehen, bestätigen, ändern, abmelden.
 *
 * EIN Link für alles – er kommt aus der Bestätigungsmail. Steht die Anmeldung noch auf
 * „unbestätigt", zählt schon das Öffnen dieser Seite als Bestätigung: Genau dazu war der
 * Klick in der Mail ja gedacht, ein zweiter Knopf wäre nur eine Hürde mehr.
 */
require __DIR__ . '/anmeldung-lib.php';

$token  = trim(anm_param($_GET['t'] ?? ($_POST['t'] ?? '')));
$signup = $token !== '' ? extern_signup_by_token($token) : null;
$ev     = $signup ? extern_event_get((int)$signup['event_id']) : null;

if (!$signup || !$ev) {
    http_response_code(410);
    anm_head('Link nicht gültig', '', true);
    echo '<div class="card"><h1>Dieser Link gilt nicht mehr</h1>'
       . '<p>Vielleicht wurde die Anmeldung gelöscht oder die Veranstaltung ist vorbei. '
       . 'Bei Fragen wende dich einfach an den AStA.</p></div>';
    anm_foot();
    exit;
}

$meldung = '';
$typ = 'ok';

// Ein Klick aus der Mail bestätigt sofort – dafür ist der Link da.
if ((string)$signup['status'] === 'pending' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $r = extern_signup_confirm($signup);
    $signup = extern_signup_get((int)$signup['id']);
    $meldung = (string)$r['msg'];
    if ($r['ok'] && (string)$signup['status'] === 'confirmed') {
        $link = extern_url($ev, 'meine', $token);
        $vars = extern_vars($ev, $signup, $link);
        extern_mail_send((int)$ev['id'], (int)$signup['id'], 'welcome', (string)$signup['email'],
            strtr(extern_text('welcome_subject'), $vars), strtr(extern_text('welcome_body'), $vars));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    anm_check_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update') {
        $r = extern_signup_update($ev, $signup, $_POST);
        $meldung = (string)$r['msg']; $typ = $r['ok'] ? 'ok' : 'fehler';
        $signup = extern_signup_get((int)$signup['id']);
    }

    if ($action === 'cancel') {
        $r = extern_signup_cancel($ev, $signup);
        $meldung = (string)$r['msg']; $typ = $r['ok'] ? 'ok' : 'fehler';
        $signup = extern_signup_get((int)$signup['id']);
        // Wer nachgerückt ist, erfährt es sofort – sonst wartet jemand ohne Grund weiter.
        foreach ((array)($r['nachrücker'] ?? []) as $nach) {
            $nlink = extern_url($ev, 'meine', '');   // ohne Token: der Link steht in ihrer eigenen Mail
            $vars = extern_vars($ev, $nach, $nlink);
            extern_mail_send((int)$ev['id'], (int)$nach['id'], 'promoted', (string)$nach['email'],
                strtr(extern_text('promoted_subject'), $vars), strtr(extern_text('promoted_body'), $vars));
        }
    }
}

$felder = extern_fields_of((int)$ev['id']);
$antw = [];
foreach (extern_answers_of((int)$signup['id']) as $a) {
    $fid = (int)$a['field_id'];
    if ($a['option_id'] !== null) { $antw[$fid][] = (int)$a['option_id']; }
    elseif ($a['value_num'] !== null && (string)$a['value_text'] === '') { $antw[$fid] = (int)$a['value_num']; }
    elseif ((string)$a['value_text'] === 'ja' || (string)$a['value_text'] === 'nein') { $antw[$fid] = (string)$a['value_text'] === 'ja' ? 1 : 0; }
    else { $antw[$fid] = (string)$a['value_text']; }
}

$status = (string)$signup['status'];
$gruppe = (int)$signup['group_id'] > 0 ? extern_group_get((int)$signup['group_id']) : null;
$zeigeGruppe = $gruppe && in_array((string)$ev['show_groups'], ['signed', 'public'], true);

anm_head('Meine Anmeldung – ' . $ev['title'], '', true);
?>
<div class="card um-kopf">
  <h1><?= h($ev['title']) ?></h1>
  <p class="um-frist">
    <?php if (trim((string)$ev['starts_at']) !== ''): ?><i class="ti ti-calendar-event"></i> <?= h(anm_dt((string)$ev['starts_at'])) ?><?php
      if (trim((string)$ev['place']) !== ''): ?> · <?= h($ev['place']) ?><?php endif; endif; ?>
  </p>
  <p class="an-status">
    <?php if ($status === 'confirmed'): ?><span class="pill pill-ok"><i class="ti ti-circle-check"></i> angemeldet</span>
    <?php elseif ($status === 'waitlist'): ?><span class="pill pill-warn"><i class="ti ti-hourglass"></i> auf der Warteliste</span>
    <?php elseif ($status === 'cancelled'): ?><span class="pill pill-bad"><i class="ti ti-x"></i> abgemeldet</span>
    <?php else: ?><span class="pill pill-info"><i class="ti ti-mail"></i> noch nicht bestätigt</span><?php endif; ?>
    <?php if ((int)$signup['party_size'] > 1): ?> · <?= (int)$signup['party_size'] ?> Personen<?php endif; ?>
  </p>
</div>

<?php if ($meldung !== ''): ?>
  <div class="card <?= $typ === 'ok' ? 'um-ok' : 'um-fehlerkarte' ?>">
    <p class="an-m0<?= $typ === 'ok' ? '' : ' um-fehler' ?>"><i class="ti ti-<?= $typ === 'ok' ? 'circle-check' : 'alert-triangle' ?>"></i> <?= h($meldung) ?></p>
  </div>
<?php endif; ?>

<?php if ($zeigeGruppe): ?>
  <div class="card an-gruppe">
    <h2><i class="ti ti-users-group"></i> Deine Gruppe: <?= h($gruppe['name']) ?></h2>
    <?php if (trim((string)$gruppe['place']) !== ''): ?><p><i class="ti ti-map-pin"></i> Treffpunkt: <strong><?= h($gruppe['place']) ?></strong></p><?php endif; ?>
    <?php if (trim((string)$gruppe['starts_at']) !== ''): ?><p><i class="ti ti-clock"></i> Los geht es um <strong><?= h(substr((string)$gruppe['starts_at'], 11, 5)) ?> Uhr</strong></p><?php endif; ?>
    <?php if (trim((string)$gruppe['note']) !== ''): ?><p class="um-klein"><?= nl2br(h((string)$gruppe['note'])) ?></p><?php endif; ?>
    <?php $fahrplan = extern_rotation_for_group((int)$gruppe['id']); if ($fahrplan): ?>
      <h3 class="an-plan-titel"><i class="ti ti-route"></i> Euer Fahrplan</h3>
      <ol class="an-plan">
        <?php foreach ($fahrplan as $p): ?>
          <li>
            <?php if (trim((string)$p['starts_at']) !== ''): ?><span class="an-plan-zeit"><?= h(date('H:i', strtotime((string)$p['starts_at']))) ?> Uhr</span><?php endif; ?>
            <span><strong><?= h((string)$p['station_name']) ?></strong><?php
              if (trim((string)$p['station_place']) !== ''): ?> · <?= h((string)$p['station_place']) ?><?php endif; ?>
              <?php if (trim((string)$p['station_note']) !== ''): ?><br><span class="um-klein"><?= h((string)$p['station_note']) ?></span><?php endif; ?></span>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ((string)$ev['show_groups'] === 'public' && extern_groups_of((int)$ev['id'])): ?>
  <div class="card"><p class="um-klein an-m0"><i class="ti ti-users-group"></i>
    <a href="gruppen.php?e=<?= h(rawurlencode((string)$ev['slug'])) ?>">Alle Gruppen ansehen</a></p></div>
<?php endif; ?>

<?php if ((int)$ev['allow_code'] === 1 && trim((string)$signup['code']) !== '' && $status !== 'cancelled'): ?>
  <div class="card">
    <p class="an-code an-m0">Dein Code: <strong><?= h($signup['code']) ?></strong></p>
    <p class="um-klein an-mt-s">Gib ihn weiter – wer ihn bei seiner Anmeldung einträgt, landet mit dir in derselben Gruppe.</p>
  </div>
<?php endif; ?>

<?php if ($status !== 'cancelled' && (int)$ev['selfservice'] === 1 && extern_reg_open($ev)): ?>
  <form method="post">
    <?= anm_csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="t" value="<?= h($token) ?>">
    <div class="card">
      <h2><i class="ti ti-edit"></i> Angaben ändern</h2>
      <div class="an-feld">
        <label for="name">Name</label>
        <input type="text" name="name" id="name" required maxlength="120" value="<?= h((string)$signup['name']) ?>">
      </div>
      <?php if ((int)$ev['allow_party'] === 1): ?>
        <div class="an-feld">
          <label for="party_size">Anzahl Personen</label>
          <input type="number" name="party_size" id="party_size" min="1" max="<?= (int)$ev['party_max'] ?>" value="<?= (int)$signup['party_size'] ?>">
        </div>
        <div class="an-feld">
          <label for="party_names">Wer kommt mit?</label>
          <textarea name="party_names" id="party_names" rows="2" maxlength="1000"><?= h((string)$signup['party_names']) ?></textarea>
        </div>
      <?php endif; ?>
      <?php foreach ($felder as $f) anm_field($f, $antw[(int)$f['id']] ?? null); ?>
      <div class="btn-row an-mt-m"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Änderungen speichern</button></div>
    </div>
  </form>
<?php elseif ($status !== 'cancelled' && (int)$ev['selfservice'] === 1): ?>
  <div class="card"><p class="um-klein an-m0"><i class="ti ti-lock"></i> Die Anmeldefrist ist vorbei – Änderungen sind nicht mehr möglich. Bei Fragen wende dich an den AStA.</p></div>
<?php endif; ?>

<?php if ($status !== 'cancelled'): ?>
  <div class="card">
    <h2><i class="ti ti-door-exit"></i> Doch nicht dabei?</h2>
    <?php if (extern_cancel_open($ev)): ?>
      <p class="um-klein">Bitte melde dich ab, wenn du nicht kommst – dann rückt jemand von der Warteliste nach.</p>
      <form method="post" data-confirm="Wirklich abmelden? Dein Platz geht dann an die nächste Person auf der Warteliste." data-confirm-danger data-confirm-ok="Abmelden">
        <?= anm_csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="t" value="<?= h($token) ?>">
        <button class="btn secondary" type="submit"><i class="ti ti-door-exit"></i> Abmelden</button>
      </form>
    <?php else: ?>
      <p class="um-klein an-m0">Die Abmeldefrist ist vorbei. Wenn du nicht kommen kannst, schreib bitte kurz dem AStA.</p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card">
    <p class="um-klein an-m0">Du bist abgemeldet.
      <?php if (extern_reg_open($ev)): ?>Wenn du es dir anders überlegst, kannst du dich <a href="index.php?e=<?= h(rawurlencode((string)$ev['slug'])) ?>">neu anmelden</a>.<?php endif; ?></p>
  </div>
<?php endif; ?>
<?php
anm_foot();
