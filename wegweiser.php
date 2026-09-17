<?php
/**
 * Wegweiser – „wo finde ich was und wen?".
 *
 * Ein reines Verzeichnis: unsere eigenen Dienste (Teams, Nextcloud, OLAT, Webseite …),
 * die Anlaufstellen an der Uni und die Menschen außerhalb, mit denen wir zu tun haben.
 * Gelesen und durchsucht wird hier von allen. Mitglieder legen unten unter „Deine Einträge"
 * auch eigene an und pflegen NUR diese; Vorsitz/Admin sehen den Abschnitt nicht – ihr
 * Werkzeug ist admin/wegweiser.php, das ohnehin alles kann.
 */
require __DIR__ . '/lib.php';
db();
require_login();

$me = current_member();
$meId = $me ? (int)$me['id'] : 0;

/* Eigene Einträge: Jede:r darf etwas beitragen – aber nur das eigene wieder ändern. Die
   Rechteprüfung sitzt in wegweiser_can_edit(); die Verwaltung darf zusätzlich alles. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me) {
    check_csrf();
    $wAction = (string)($_POST['action'] ?? '');

    if ($wAction === 'ww_add') {
        $r = wegweiser_save(0, $_POST, $meId);
        flash($r['msg'], $r['typ']);
        redirect($r['ok'] ? 'wegweiser.php#meine' : 'wegweiser.php?neu=1');
    }

    if ($wAction === 'ww_save' || $wAction === 'ww_delete') {
        $wId = (int)($_POST['id'] ?? 0);
        $wE  = wegweiser_get($wId);
        if (!wegweiser_can_edit($wE, $me)) {
            flash('Diesen Eintrag darf nur ändern, wer ihn angelegt hat (oder der Vorsitz).', 'error');
            redirect('wegweiser.php#meine');
        }
        if ($wAction === 'ww_delete') {
            db()->prepare('DELETE FROM wegweiser WHERE id = ?')->execute([$wId]);
            flash('Eintrag gelöscht.', 'success');
            redirect('wegweiser.php#meine');
        }
        $r = wegweiser_save($wId, $_POST, $meId);
        flash($r['msg'], $r['typ']);
        redirect($r['ok'] ? 'wegweiser.php#meine' : 'wegweiser.php?edit=' . $wId);
    }
}

// Bearbeiten-Ansicht: nur für eigene Einträge (Verwaltung nutzt ihre eigene Seite)
$wEdit = null;
if (($wEditId = (int)($_GET['edit'] ?? 0)) > 0) {
    $wEdit = wegweiser_get($wEditId);
    if (!wegweiser_can_edit($wEdit, $me)) $wEdit = null;
}
$wMine = wegweiser_mine($meId);
// Mitglieder sehen die Einträge für alle + die ihres Referats; Technik-Login (kein Mitglied) sieht alles
$gruppen = $me ? wegweiser_all(trim((string)($me['referat'] ?? ''))) : wegweiser_all();
$anzahl = array_sum(array_map('count', $gruppen));

page_header('Wegweiser');
?>
<div class="events-toolbar">
  <h1><i class="ti ti-directions" style="color:var(--petrol)"></i> Wegweiser</h1>
  <div class="btn-row">
    <?php if ($me && !can_admin()): ?><a class="btn secondary" href="wegweiser.php?neu=1#meine"><i class="ti ti-plus"></i> Eintrag hinzufügen</a><?php endif; ?>
    <?php if (can_admin()): ?><a class="btn secondary" href="admin/wegweiser.php"><i class="ti ti-edit"></i> Wegweiser pflegen</a><?php endif; ?>
  </div>
</div>
<p class="small muted" style="margin:-.4rem 0 1rem">Alle unsere Dienste, Anlaufstellen und Ansprechpartner:innen an einem Ort – damit niemand mehr fragen muss, wo eigentlich was liegt.</p>

<?php if ($anzahl === 0): ?>
  <div class="card"><p class="empty"><i class="ti ti-directions"></i> Hier ist noch nichts eingetragen.<?php if (can_admin()): ?> Über <a href="admin/wegweiser.php">Wegweiser pflegen</a> geht’s los.<?php elseif ($me): ?> Leg über <a href="wegweiser.php?neu=1#meine">Eintrag hinzufügen</a> den ersten an.<?php endif; ?></p></div>
<?php else: ?>
  <div class="card ww-searchbar">
    <i class="ti ti-search"></i>
    <input type="search" id="wwSearch" aria-label="Im Wegweiser suchen" placeholder="Suchen – Name, Stichwort, Adresse …" autocomplete="off">
    <button type="button" class="ww-clear" hidden aria-label="Suche zurücksetzen"><i class="ti ti-x"></i></button>
  </div>
  <p class="small muted ww-empty" hidden><i class="ti ti-mood-search"></i> Nichts gefunden. Andere Schreibweise probieren?</p>

  <?php foreach ($gruppen as $label => $eintraege): ?>
    <div class="ww-group">
      <div class="section-title"><i class="ti ti-point"></i> <?= h($label) ?> <span class="count"><?= count($eintraege) ?></span></div>
      <div class="ww-grid">
        <?php foreach ($eintraege as $e):
            $href = wegweiser_href($e);
            $url = trim((string)$e['url']);
            $mail = trim((string)$e['email']);
            $tel = trim((string)$e['phone']);
            $note = trim((string)$e['note']);
            $refs = wegweiser_referate((string)($e['referat'] ?? ''));
            // Zweitwege nur zeigen, wenn sie NICHT schon das Hauptziel sind
            $extra = [];
            if ($mail !== '' && $href !== 'mailto:' . $mail) $extra[] = ['mailto:' . $mail, 'ti-mail', $mail];
            $telHref = wegweiser_tel($tel);
            if ($telHref !== '' && !str_starts_with($href, 'tel:')) $extra[] = ['tel:' . $telHref, 'ti-phone', $tel];
            // Suchtext: alles, wonach jemand tippen könnte – in einem Attribut, klein geschrieben
            $such = mb_strtolower(trim($e['title'] . ' ' . $note . ' ' . $url . ' ' . $mail . ' ' . $tel . ' ' . $label));
            $extern = $url !== '';
        ?>
          <div class="ww-item<?= $extra ? ' has-extra' : '' ?>" data-ww="<?= h($such) ?>">
            <i class="ti <?= h(wegweiser_icon($e)) ?> ww-ic"></i>
            <div class="ww-body">
              <div class="ww-head">
                <?php if ($href !== ''): ?>
                  <?php /* Regel in der ganzen App: Links, die AUS der App herausführen, öffnen ein
                          neues Fenster (die App bleibt stehen), interne Links nicht. Der Pfeil am
                          Titel kündigt genau das an. E-Mail und Telefon bleiben ohne – die übernimmt
                          ohnehin ein anderes Programm. */ ?>
                  <a class="ww-title<?= $extra ? '' : ' ww-stretch' ?>" href="<?= h($href) ?>"<?= $extern ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= h($e['title']) ?><?php if ($extern): ?><i class="ti ti-external-link ww-ext"></i><?php endif; ?></a>
                <?php else: ?>
                  <span class="ww-title"><?= h($e['title']) ?></span>
                <?php endif; ?>
                <?php foreach ($refs as $ri => $ref): ?><span class="pill pill-info" style="font-size:.68rem;font-weight:600" title="<?= count($refs) > 1 ? 'Nur für diese Referate sichtbar' : 'Nur für dieses Referat sichtbar' ?>"><?php if ($ri === 0): ?><i class="ti ti-users-group" style="font-size:.85em"></i> <?php endif; ?><?= h($ref) ?></span><?php endforeach; ?>
              </div>
              <?php if ($note !== ''): ?><p class="ww-note"><?= h($note) ?></p><?php endif; ?>
              <?php if ($url !== ''): $host = wegweiser_host($url); if ($host !== ''): ?>
                <p class="ww-host"><?= h($host) ?></p>
              <?php endif; endif; ?>
              <?php if ($extra): ?>
                <div class="ww-extra">
                  <?php foreach ($extra as [$eh, $ei, $et]): ?>
                    <a class="ww-chip" href="<?= h($eh) ?>"><i class="ti <?= h($ei) ?>"></i> <?= h($et) ?></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php /* „Deine Einträge" gibt es NICHT für Vorsitz/Admin: Deren Verwaltungsseite kann alles,
         was hier steht – und mehr (fremde Einträge, Reihenfolge). Zwei Wege nebeneinander wären
         nur verwirrend. Die POST-Handler oben bleiben trotzdem für alle gültig. */ ?>
<?php if ($me && !can_admin()): // ---- Eigene Einträge: anlegen und pflegen ---- ?>
  <div class="section-title" id="meine"><i class="ti ti-user-edit"></i> Deine Einträge<?= $wMine ? ' <span class="count">' . count($wMine) . '</span>' : '' ?></div>

  <?php if ($wEdit): ?>
    <div class="card">
      <div class="section-title" style="margin-top:0;font-size:1.05rem"><i class="ti ti-edit"></i> Eintrag bearbeiten</div>
      <?php wegweiser_form($wEdit, 'wegweiser.php#meine'); ?>
    </div>
  <?php elseif (!empty($_GET['neu'])): ?>
    <div class="card">
      <div class="section-title" style="margin-top:0;font-size:1.05rem"><i class="ti ti-plus"></i> Neuer Eintrag</div>
      <p class="small muted" style="margin:0 0 .7rem">Trag ein, was deinem Referat (oder allen) hilft – einen Dienst, eine Anlaufstelle oder eine Ansprechperson. <strong>Ändern und löschen kannst du deine eigenen Einträge jederzeit</strong>; sonst darf das nur der Vorsitz.</p>
      <?php wegweiser_form(null, 'wegweiser.php#meine'); ?>
    </div>
  <?php endif; ?>

  <?php if (!$wMine): ?>
    <?php if (!$wEdit && empty($_GET['neu'])): ?>
      <div class="card"><p class="empty"><i class="ti ti-plus"></i> Du hast noch nichts eingetragen. <a href="wegweiser.php?neu=1#meine">Jetzt etwas hinzufügen</a> – zum Beispiel die Anlaufstellen, mit denen dein Referat regelmäßig zu tun hat.</p></div>
    <?php endif; ?>
  <?php else: ?>
    <div class="card" style="padding:.4rem .2rem">
      <table class="list">
        <thead><tr><th>Name</th><th>Rubrik</th><th>Sichtbar für</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($wMine as $wm): ?>
          <tr>
            <td><i class="ti <?= h(wegweiser_icon($wm)) ?>"></i> <?= h($wm['title']) ?></td>
            <td class="muted small"><?= h(wegweiser_group_label((string)$wm['grp'])) ?></td>
            <td><?= wegweiser_referat_pills((string)($wm['referat'] ?? '')) ?></td>
            <td style="text-align:right">
              <div class="btn-row" style="justify-content:flex-end">
                <a class="btn secondary small" href="wegweiser.php?edit=<?= (int)$wm['id'] ?>#meine">Bearbeiten</a>
                <form method="post" data-confirm="Diesen Eintrag löschen?" data-confirm-danger data-confirm-ok="Löschen">
                  <?= csrf_field() ?><input type="hidden" name="action" value="ww_delete"><input type="hidden" name="id" value="<?= (int)$wm['id'] ?>">
                  <button class="btn danger small" type="submit">Löschen</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php
page_footer();
