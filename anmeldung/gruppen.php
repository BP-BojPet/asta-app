<?php
/**
 * Öffentliche Gruppenliste – nur wenn die Veranstaltung das ausdrücklich erlaubt.
 *
 * Diese Seite ist das Ziel der Einstellung „Alle Gruppen öffentlich einsehbar".
 * Ob Namen dabeistehen, ist eine zweite Entscheidung –
 * eine Namensliste im Netz will man nicht aus Versehen veröffentlichen.
 */
require __DIR__ . '/anmeldung-lib.php';

$slug = trim(anm_param($_GET['e'] ?? ''));
$ev = $slug !== '' ? extern_event_by_slug($slug) : null;
if ($ev && in_array((string)$ev['status'], ['draft', 'archived'], true)) $ev = null;

// Ohne die Freigabe gibt es diese Seite schlicht nicht – auch nicht als „kein Zugriff".
if (!$ev || (string)$ev['show_groups'] !== 'public') {
    http_response_code(404);
    anm_head('Nicht gefunden', '', true);
    echo '<div class="card"><h1>Diese Seite gibt es nicht</h1>'
       . '<p>Für diese Veranstaltung wird die Einteilung nicht öffentlich gezeigt.</p></div>';
    anm_foot();
    exit;
}

$gruppen  = extern_groups_of((int)$ev['id']);
$belegung = extern_group_load((int)$ev['id']);
$plan     = extern_rotation_on($ev) ? extern_rotation_of((int)$ev['id']) : [];
$mitNamen = (int)$ev['show_names'] === 1;

// Stehen Namen drauf, hat die Seite in keiner Suchmaschine etwas verloren.
anm_head($ev['title'] . ' – Einteilung', 'Die Gruppen-Einteilung für ' . $ev['title'], $mitNamen);
?>
<div class="card um-kopf">
  <h1><?= h($ev['title']) ?></h1>
  <p class="um-frist">
    <?php if (trim((string)$ev['starts_at']) !== ''): ?><i class="ti ti-calendar-event"></i> <?= h(anm_dt((string)$ev['starts_at'])) ?><?php
      if (trim((string)$ev['place']) !== ''): ?> · <?= h($ev['place']) ?><?php endif; endif; ?>
  </p>
  <p class="um-klein">Hier steht die Einteilung in Gruppen.<?= $mitNamen ? '' : ' Namen werden nicht angezeigt – deine eigene Gruppe siehst du über den Link in deiner Bestätigungsmail.' ?></p>
</div>

<?php if (!$gruppen): ?>
  <div class="card"><p class="empty"><i class="ti ti-users-group"></i> Die Einteilung steht noch nicht fest.</p></div>
<?php else: ?>
  <div class="an-gruppenliste">
    <?php foreach ($gruppen as $g): $gid = (int)$g['id']; $b = $belegung[$gid] ?? ['personen' => 0, 'signups' => []]; ?>
      <div class="card an-gruppe">
        <h2><?= h($g['name']) ?></h2>
        <p class="um-klein">
          <?php if (trim((string)$g['place']) !== ''): ?><i class="ti ti-map-pin"></i> <?= h($g['place']) ?><br><?php endif; ?>
          <?php if (trim((string)$g['starts_at']) !== ''): ?><i class="ti ti-clock"></i> ab <?= h(substr((string)$g['starts_at'], 11, 5)) ?> Uhr<br><?php endif; ?>
          <i class="ti ti-users"></i> <?= (int)$b['personen'] ?> <?= (int)$b['personen'] === 1 ? 'Person' : 'Personen' ?>
        </p>
        <?php if ($mitNamen && $b['signups']): ?>
          <ul class="an-namen">
            <?php foreach ($b['signups'] as $s): ?>
              <li><?= h((string)$s['name']) ?><?php if ((int)$s['party_size'] > 1): ?> <span class="um-klein">+<?= (int)$s['party_size'] - 1 ?></span><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php $fp = $plan ? extern_rotation_for_group($gid) : []; if ($fp): ?>
          <h3 class="an-plan-titel"><i class="ti ti-route"></i> Fahrplan</h3>
          <ol class="an-plan">
            <?php foreach ($fp as $p): ?>
              <li><?php if (trim((string)$p['starts_at']) !== ''): ?><span class="an-plan-zeit"><?= h(date('H:i', strtotime((string)$p['starts_at']))) ?></span><?php endif; ?>
                <span><strong><?= h((string)$p['station_name']) ?></strong><?php if (trim((string)$p['station_place']) !== ''): ?> · <?= h((string)$p['station_place']) ?><?php endif; ?></span></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <p class="um-klein an-m0"><a href="index.php?e=<?= h(rawurlencode((string)$ev['slug'])) ?>">Zur Veranstaltung</a></p>
</div>
<?php
anm_foot();
