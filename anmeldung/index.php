<?php
/**
 * Öffentliche Anmeldeseite einer Veranstaltung.
 *
 * Name und Mailadresse sind fest, alles andere hat das Referat im Formularbau
 * zusammengestellt. Ob es eine Bestätigungsmail, eine Warteliste, einen Code für
 * Freundeskreise oder eine Sammelanmeldung gibt, entscheidet die Veranstaltung.
 */
require __DIR__ . '/anmeldung-lib.php';

$slug = trim(anm_param($_GET['e'] ?? ''));
$ev = $slug !== '' ? extern_event_by_slug($slug) : null;
if ($ev && in_array((string)$ev['status'], ['draft', 'archived'], true)) $ev = null;

if (!$ev) {
    http_response_code(404);
    anm_head('Veranstaltung nicht gefunden', '', true);
    echo '<div class="card"><h1>Diese Veranstaltung gibt es nicht</h1>'
       . '<p>Vielleicht ist der Link nicht vollständig kopiert worden, oder die Anmeldung wurde entfernt.</p></div>';
    anm_foot();
    exit;
}

$offen  = extern_reg_open($ev);
$felder = extern_fields_of((int)$ev['id']);
$frei   = extern_free_seats($ev);
$fertig = null;      // ['status' => …, 'token' => …] nach erfolgreicher Anmeldung
$fehler = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    anm_check_csrf();
    if (!empty($_POST['website'])) {                 // Honigtopf
        $fertig = ['status' => 'confirmed', 'token' => ''];
    } elseif (!$offen) {
        $fehler = 'Für diese Veranstaltung läuft gerade keine Anmeldung.';
    } elseif (!anm_rate_ok()) {
        $fehler = 'Von hier kamen gerade sehr viele Anmeldungen. Bitte in einer Stunde noch einmal versuchen.';
    } else {
        $r = extern_signup_add($ev, $_POST);
        if (!$r['ok']) {
            $fehler = (string)$r['msg'];
        } else {
            $fertig = $r;
            $signup = extern_signup_get((int)$r['signup_id']);
            $link = extern_url($ev, 'meine', (string)$r['token']);
            $vars = extern_vars($ev, $signup, $link);
            // Welche Mail passt zur Lage? Bestätigung, Warteliste oder direkt willkommen.
            $art = $r['status'] === 'waitlist' ? 'waitlist' : ($r['status'] === 'pending' ? 'confirm' : 'welcome');
            if ($link !== '') {
                extern_mail_send((int)$ev['id'], (int)$r['signup_id'], $art, (string)$signup['email'],
                    strtr(extern_text($art . '_subject'), $vars), strtr(extern_text($art . '_body'), $vars));
            } else {
                anm_log('Basis-Adresse fehlt – keine Mail für Anmeldung ' . (int)$r['signup_id']);
            }
        }
    }
}

anm_head($ev['title'], mb_substr(trim((string)$ev['intro']), 0, 160));
?>
<?php /* Der Kopf als Bühne: Eckdaten als CHIPS statt Fließtext mit <br> – Datum, Ort und
         Anmeldestand sind auf einen Blick zu erfassen, der Zustand färbt seinen Chip.
         Der Platz-Stand ist ein BALKEN (um-g-Bausteine aus style.css, SVG-Attribute –
         die CSP hier kennt kein unsafe-inline). */ ?>
<div class="card an-hero">
  <h1><?= h($ev['title']) ?></h1>
  <?php if (trim((string)$ev['intro']) !== ''): ?><div class="um-intro"><?= nl2br(h(trim((string)$ev['intro']))) ?></div><?php endif; ?>
  <div class="an-chips">
    <?php if (trim((string)$ev['starts_at']) !== ''): ?>
      <span class="an-chip"><i class="ti ti-calendar-event"></i> <?= h(anm_dt((string)$ev['starts_at'])) ?></span>
    <?php endif; ?>
    <?php if (trim((string)$ev['place']) !== ''): ?>
      <span class="an-chip"><i class="ti ti-map-pin"></i> <?= h($ev['place']) ?></span>
    <?php endif; ?>
    <?php if ($offen && trim((string)$ev['reg_until']) !== ''): ?>
      <span class="an-chip an-chip-ok"><i class="ti ti-clock"></i> Anmeldung bis <?= h(anm_dt((string)$ev['reg_until'])) ?></span>
    <?php elseif ($offen): ?>
      <span class="an-chip an-chip-ok"><i class="ti ti-clock"></i> Anmeldung läuft</span>
    <?php else: ?>
      <span class="an-chip an-chip-zu"><i class="ti ti-lock"></i> Anmeldung geschlossen</span>
    <?php endif; ?>
  </div>
  <?php if ($offen && (int)$ev['show_count'] === 1 && $frei !== null): $belegt = max(0, (int)$ev['capacity'] - (int)$frei);
        $pct = (int)$ev['capacity'] > 0 ? (int)round($belegt * 100 / (int)$ev['capacity']) : 0; ?>
    <div class="an-plaetze">
      <svg class="um-g-balken" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
        <rect class="um-g-grund" x="0" y="0" width="100" height="10" rx="2"></rect>
        <rect class="um-g-wert" x="0" y="0" width="<?= max(0.6, min(100, $pct)) ?>" height="10" rx="2"></rect>
      </svg>
      <span class="an-plaetze-t"><?= $frei > 0
          ? '<strong>' . (int)$frei . '</strong> von ' . (int)$ev['capacity'] . ' Plätzen frei'
          : 'Ausgebucht' . ((int)$ev['waitlist'] === 1 ? ' – Warteliste offen' : '') ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ((string)$ev['show_groups'] === 'public' && extern_groups_of((int)$ev['id'])): ?>
  <div class="card"><p class="um-klein an-m0"><i class="ti ti-users-group"></i>
    <a href="gruppen.php?e=<?= h(rawurlencode((string)$ev['slug'])) ?>">Die Gruppen-Einteilung ansehen</a></p></div>
<?php endif; ?>

<?php if ($fertig): $st = (string)$fertig['status']; ?>
  <div class="card um-ok">
    <?php if ($st === 'waitlist'): ?>
      <h2><i class="ti ti-hourglass"></i> Du stehst auf der Warteliste</h2>
      <p>Die Plätze sind gerade vergeben. Du rückst <strong>automatisch nach</strong>, sobald jemand absagt – wir schreiben dir dann.</p>
    <?php elseif ($st === 'pending'): ?>
      <h2><i class="ti ti-mail-fast"></i> Fast geschafft – bitte bestätigen</h2>
      <p><strong>Deine Anmeldung zählt erst, wenn du den Link in der Mail anklickst.</strong>
         Sieh kurz in dein Postfach (und im Zweifel in den Spam-Ordner).</p>
    <?php else: ?>
      <h2><i class="ti ti-circle-check"></i> Angemeldet!</h2>
      <p>Schön, dass du dabei bist. Eine Mail mit allen Angaben ist unterwegs.</p>
    <?php endif; ?>
    <?php if (!empty($fertig['token'])): $sg = extern_signup_get((int)$fertig['signup_id']);
          if ((int)$ev['allow_code'] === 1 && trim((string)$sg['code']) !== ''): ?>
      <p class="an-code">Dein Code zum Weitergeben: <strong><?= h($sg['code']) ?></strong></p>
      <p class="um-klein">Wer ihn bei seiner eigenen Anmeldung eingibt, landet mit dir in derselben Gruppe.</p>
    <?php endif; ?>
      <?php if ((int)$ev['selfservice'] === 1): ?>
        <p><a class="btn secondary" href="meine.php?t=<?= h((string)$fertig['token']) ?>"><i class="ti ti-user-check"></i> Meine Anmeldung ansehen</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php elseif ($offen): ?>
  <?php if ($fehler !== ''): ?>
    <div class="card um-fehlerkarte"><p class="um-fehler"><i class="ti ti-alert-triangle"></i> <?= h($fehler) ?></p></div>
  <?php endif; ?>
  <?php if ($frei !== null && $frei <= 0 && (int)$ev['waitlist'] === 1): ?>
    <div class="card"><p class="um-klein an-m0"><i class="ti ti-hourglass"></i> Alle Plätze sind vergeben – deine Anmeldung kommt auf die <strong>Warteliste</strong> und rückt bei Absagen automatisch nach.</p></div>
  <?php endif; ?>
  <form method="post">
    <?= anm_csrf_field() ?>
    <div class="card">
      <h2><i class="ti ti-user"></i> Du</h2>
      <div class="an-feld">
        <label for="name">Name <span class="an-pflicht">*</span></label>
        <input type="text" name="name" id="name" required maxlength="120" value="<?= h(anm_param($_POST['name'] ?? '')) ?>">
      </div>
      <div class="an-feld">
        <label for="email">Mailadresse <span class="an-pflicht">*</span></label>
        <input type="email" name="email" id="email" required maxlength="190" autocomplete="email" inputmode="email" value="<?= h(anm_param($_POST['email'] ?? '')) ?>">
        <p class="um-klein">Dorthin geht die Bestätigung – und später alles Weitere zur Veranstaltung.</p>
      </div>
      <?php if ((int)$ev['allow_party'] === 1): ?>
        <div class="an-feld">
          <label for="party_size">Wie viele Personen meldest du an?</label>
          <input type="number" name="party_size" id="party_size" min="1" max="<?= (int)$ev['party_max'] ?>" value="<?= (int)(anm_param($_POST['party_size'] ?? '1') ?: 1) ?>">
          <p class="um-klein">Höchstens <?= (int)$ev['party_max'] ?>. Alle zählen als Plätze und bleiben in derselben Gruppe.</p>
        </div>
        <div class="an-feld">
          <label for="party_names">Wer kommt mit? (Namen, optional)</label>
          <textarea name="party_names" id="party_names" rows="2" maxlength="1000"><?= h(anm_param($_POST['party_names'] ?? '')) ?></textarea>
        </div>
      <?php endif; ?>
      <?php if ((int)$ev['allow_code'] === 1): ?>
        <div class="an-feld">
          <label for="code">Code von Freund:innen (optional)</label>
          <input type="text" name="code" id="code" maxlength="10" value="<?= h(anm_param($_POST['code'] ?? '')) ?>" placeholder="z. B. K7M2QP" class="an-upper">
          <p class="um-klein">Hast du einen Code bekommen, trag ihn ein – dann kommt ihr in dieselbe Gruppe. Ohne Code bekommst du einen eigenen zum Weitergeben.</p>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($felder): ?>
      <div class="card">
        <h2><i class="ti ti-forms"></i> Angaben</h2>
        <?php foreach ($felder as $f) anm_field($f, $_POST['f'][(int)$f['id']] ?? null); ?>
      </div>
    <?php endif; ?>

    <div class="card um-absenden-karte">
      <div class="um-hp" aria-hidden="true">
        <label for="website">Bitte frei lassen</label>
        <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
      </div>
      <p class="um-klein">Mit dem Absenden meldest du dich verbindlich an. Abmelden geht<?= trim((string)$ev['cancel_until']) !== '' ? ' bis zum ' . h(anm_dt((string)$ev['cancel_until'])) : ' später jederzeit' ?> über den Link in deiner Mail.</p>
      <button class="btn" type="submit"><i class="ti ti-send"></i> <?= $frei !== null && $frei <= 0 ? 'Auf die Warteliste' : 'Verbindlich anmelden' ?></button>
    </div>
  </form>
<?php endif; ?>
<?php
anm_foot();
