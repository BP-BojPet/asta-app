<?php
/**
 * Erinnerungen & Abos – die Verwaltungsseite für die Push-Mitteilungen DIESES Geräts.
 *
 * Es gibt kein Konto: Was hier steht, gehört zum Browser, in dem die Seite gerade offen ist.
 * Die Listen baut assets/wl-push.js aus dem Serverzustand (push.php?was=state) – serverseitig
 * lässt sich nichts rendern, weil erst das Skript weiß, welches Gerät fragt.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard()) exit;

$GLOBALS['wl_push'] = true;
$kannPush = push_available() && wl_push_vapid();

wl_head('Erinnerungen & Abos', 'Mitteilungen zu Veranstaltungen in ' . wl_ort() . ' – ohne Konto, direkt aufs Gerät.', '', true);
wl_nav();
?>

<div class="wl-det wl-abo-seite">
  <h1>Erinnerungen &amp; Abos</h1>
  <p class="wl-text">Lass dich erinnern, bevor etwas losgeht – oder abonniere Veranstalter und
    Kategorien und erfahre sofort, wenn etwas Neues eingetragen wird. Ganz ohne Konto:
    Die Mitteilungen gehören zu <strong>diesem Gerät</strong>, gespeichert wird nur die
    Push-Adresse deines Browsers. Abbestellen geht jederzeit hier.</p>

  <?php if (!$kannPush): ?>
    <p class="wl-note warn">Push-Mitteilungen stehen auf diesem Server gerade nicht bereit.</p>
  <?php else: ?>
    <div id="wl-abos" data-cats="<?= h((string)json_encode(array_map(static fn ($c) => $c['label'], wl_cats()), JSON_UNESCAPED_UNICODE)) ?>">
      <p id="wl-abos-leer" class="wl-text">Auf diesem Gerät ist noch nichts eingerichtet.
        Der schnellste Weg: bei einer Veranstaltung auf <em>🔔 Erinnere mich</em> tippen –
        oder gleich hier ein Abo anlegen.</p>

      <h2 class="wl-abo-h">Neues Abo</h2>
      <p class="wl-text">„Alle Veranstalter" + eine Kategorie ergibt zum Beispiel: eine Mitteilung
        zu jeder neuen Party. Ein Veranstalter + „alles" meldet alles von dieser Gruppe.</p>
      <div class="wl-abo-form">
        <select id="wl-abo-org">
          <option value="0">Alle Veranstalter</option>
          <?php foreach (wl_orgs_all() as $o): ?>
            <option value="<?= (int)$o['id'] ?>"><?= h((string)$o['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="wl-abo-cat">
          <option value="">Alle Kategorien</option>
          <?php foreach (wl_cats() as $ck => $cd): ?>
            <option value="<?= h($ck) ?>"><?= h((string)$cd['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="wl-btn p" id="wl-abo-neu">Abonnieren</button>
      </div>

      <h2 class="wl-abo-h">Deine Erinnerungen</h2>
      <ul class="wl-abo-liste" id="wl-erinnerungen"></ul>

      <h2 class="wl-abo-h">Deine Abos</h2>
      <ul class="wl-abo-liste" id="wl-follows"></ul>

      <p class="wl-text wl-abo-fuss">
        <button type="button" class="wl-abo-weg" id="wl-abo-aus">Alles auf diesem Gerät abschalten</button>
        – entfernt sämtliche Erinnerungen, Abos und die gespeicherte Push-Adresse.
      </p>
    </div>
  <?php endif; ?>
</div>

<?php wl_foot(); ?>
