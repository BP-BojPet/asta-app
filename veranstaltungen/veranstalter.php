<?php
/**
 * Veranstalter: ohne ?id die Übersicht aller aktiven Gruppen, mit ?id die Visitenkarte –
 * Profil (aus einreichen.php gepflegt), kommende Termine und der Abo-Knopf. Der Sinn der
 * Seite ist genau dieser Knopf: Wer eine Gruppe mag, abonniert sie und erfährt ab dann
 * von selbst, wenn sie etwas Neues einträgt.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard(true)) exit;   // im Vorabstart offen: hier entstehen die Beiträge fürs Startdatum

$id = (int)wl_param($_GET['id'] ?? '0');
$kannPush = push_available() && wl_push_vapid();

// ------------------------------------------------------------------ Visitenkarte
if ($id > 0) {
    $org = wl_org($id);
    if (!$org || (int)$org['active'] !== 1) {
        http_response_code(404);
        wl_head('Nicht gefunden', '', '', true);
        wl_nav();
        echo '<div class="wl-det"><h1>Diese Gruppe gibt es hier nicht</h1>'
           . '<div class="wl-btns"><a class="wl-btn p" href="veranstalter.php">Alle Veranstalter</a></div></div>';
        wl_foot();
        exit;
    }
    $items = wl_items_public(['org' => $id], 60);
    $GLOBALS['wl_push'] = $kannPush;
    wl_head((string)$org['name'], mb_substr(trim((string)($org['profil'] ?? '')), 0, 180));
    wl_nav();
    $art = wl_org_arten()[(string)$org['art']] ?? null;
    $web = trim((string)($org['web'] ?? ''));
    $insta = wl_org_insta_url($org);
    ?>
<?php // Die eigene Seite bekommt einen Bearbeiten-Knopf: Profil und Veranstalter-Seite sind
      // EINE Sache – bearbeitet wird die Seite, nicht ein zweites Ding.
      $meins = (int)($_SESSION['wl_org_id'] ?? 0) === $id; ?>
<div class="wl-det wl-vst">
  <div class="wl-vst-kopf">
    <?= wl_org_avatar($org, 'gross') ?>
    <div>
      <h1><?= h((string)$org['name']) ?></h1>
      <div class="wl-org-m"><?= h((string)($art['label'] ?? 'Veranstalter')) ?></div>
    </div>
    <?php if ($meins): ?>
      <a class="wl-btn wl-vst-edit" href="einreichen.php?t=profil">Bearbeiten</a>
    <?php elseif ($kannPush): ?>
      <button type="button" class="wl-btn p wl-push-btn" data-art="follow" data-org="<?= $id ?>" data-cat="" aria-pressed="false">
        <span class="pa">+ Abonnieren</span><span class="pb">✓ Abonniert</span>
      </button>
    <?php endif; ?>
  </div>

  <?= wl_absaetze((string)($org['profil'] ?? ''), 'wl-text') ?>

  <?php if ($web !== '' || $insta !== ''): ?>
    <div class="wl-btns">
      <?php if ($web !== ''): ?><a class="wl-btn" href="<?= h($web) ?>" rel="noopener">Website ↗</a><?php endif; ?>
      <?php if ($insta !== ''): ?><a class="wl-btn" href="<?= h($insta) ?>" rel="noopener">Instagram ↗</a><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($kannPush): ?>
    <p class="wl-push-hint">Abonnieren heißt: eine Mitteilung aufs Gerät, sobald
      <?= h((string)$org['name']) ?> etwas Neues einträgt. Verwalten unter
      <a href="abos.php">Erinnerungen &amp; Abos</a>.</p>
  <?php endif; ?>

  <h2 class="wl-vst-h"><?= $items ? 'Demnächst' : 'Gerade nichts eingetragen' ?></h2>
</div>
<?php if ($items): ?>
  <section class="wl-sect"><div class="wl-in">
    <div class="wl-grid">
      <?php foreach ($items as $it) wl_kachel($it); ?>
    </div>
  </div></section>
<?php endif; ?>
<?php
    wl_foot();
    exit;
}

// ------------------------------------------------------------------ Übersicht
$orgs = wl_orgs_all();
// Wie viel demnächst je Gruppe läuft – EINE Abfrage, nicht eine pro Kachel.
$zahlen = [];
foreach (wl_db()->query("SELECT org_id, COUNT(*) AS n FROM items
    WHERE status = 'live' AND (CASE WHEN kind = 'kurs'
        THEN (bis_datum = '' OR date(bis_datum) >= date('now','localtime'))
        ELSE date(CASE WHEN ends_at <> '' THEN ends_at ELSE starts_at END) >= date('now','localtime') END)
    GROUP BY org_id")->fetchAll() as $z) {
    $zahlen[(int)$z['org_id']] = (int)$z['n'];
}
$GLOBALS['wl_push'] = $kannPush;
wl_head('Veranstalter', 'Die Gruppen hinter den Veranstaltungen – abonnieren und nichts mehr verpassen.');
wl_nav();
?>
<div class="wl-det wl-vst">
  <h1>Veranstalter</h1>
  <p class="wl-text">Das sind die Gruppen, die hier eintragen. Tipp auf einen Namen für Profil
    und Termine – oder abonniere direkt und bekomme eine Mitteilung, sobald es etwas Neues gibt.</p>
</div>
<section class="wl-sect"><div class="wl-in">
  <div class="wl-vst-grid">
    <?php foreach ($orgs as $o): $art = wl_org_arten()[(string)$o['art']] ?? null; ?>
      <div class="wl-vst-card">
        <?= wl_org_avatar($o) ?>
        <div class="wl-vst-mitte">
          <a class="wl-vst-name" href="veranstalter.php?id=<?= (int)$o['id'] ?>"><?= h((string)$o['name']) ?></a>
          <div class="wl-org-m">
            <?= h((string)($art['label'] ?? 'Veranstalter')) ?>
            <?php $n = $zahlen[(int)$o['id']] ?? 0; if ($n > 0): ?>
              · <?= $n ?> demnächst
            <?php endif; ?>
          </div>
        </div>
        <?php if ($kannPush): ?>
          <button type="button" class="wl-btn s wl-push-btn" data-art="follow" data-org="<?= (int)$o['id'] ?>" data-cat="" aria-pressed="false">
            <span class="pa">+ Abo</span><span class="pb">✓</span>
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$orgs): ?><p class="wl-text">Noch keine Veranstalter eingetragen.</p><?php endif; ?>
</div></section>
<?php wl_foot(); ?>
