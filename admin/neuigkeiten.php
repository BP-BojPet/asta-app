<?php
/**
 * Splash-Baukasten: „Neu in der App" und „Neu im StuPa-Bereich" zusammenstellen, ansehen,
 * veröffentlichen. Die Regeln (wer ihn wann sieht) stehen in lib.php bei splash_ziele().
 *
 * Jeder Knopf im Editor speichert den ganzen Stand mit (op = speichern | vorschau | add |
 * del-N | up-N | down-N) – so geht beim Umsortieren nichts verloren, und es braucht kein JS.
 */
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Admin & Vorsitz

$cm = current_member();
$meId = (int)($cm['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'neu') {
        $ziel = isset(splash_ziele()[$_POST['ziel'] ?? '']) ? (string)$_POST['ziel'] : 'app';
        redirect('neuigkeiten.php?id=' . splash_speichern(0, $ziel, '', splash_leer($ziel), $meId));
    }

    $s = $id ? splash_get($id) : null;
    if (!$s) { flash('Diesen Splash gibt es nicht mehr.', 'error'); redirect('neuigkeiten.php'); }

    if ($action === 'speichern') {
        $in = (array)($_POST['s'] ?? []);
        $punkte = array_values((array)($_POST['p'] ?? []));
        $op = (string)($_POST['op'] ?? 'speichern');
        $anker = '';
        if ($op === 'add' && count($punkte) < SPLASH_MAX_PUNKTE) {
            $punkte[] = splash_punkt_leer();
            $anker = '#punkt-' . (count($punkte) - 1);
        } elseif (preg_match('~^(del|up|down)-(\d+)$~', $op, $m) && isset($punkte[(int)$m[2]])) {
            $i = (int)$m[2];
            if ($m[1] === 'del') {
                array_splice($punkte, $i, 1);
                $anker = '#punkte';
            } else {
                $j = $m[1] === 'up' ? $i - 1 : $i + 1;
                if (isset($punkte[$j])) { [$punkte[$i], $punkte[$j]] = [$punkte[$j], $punkte[$i]]; $i = $j; }
                $anker = '#punkt-' . $i;
            }
        }
        $in['punkte'] = $punkte;
        splash_speichern($id, (string)$s['ziel'], (string)($_POST['name'] ?? ''), $in, $meId);
        if ($op === 'speichern') flash($s['status'] === 'live' ? 'Gespeichert – gilt sofort für alle, die ihn noch nicht gesehen haben.' : 'Entwurf gespeichert.', 'success');
        redirect('neuigkeiten.php?id=' . $id . ($op === 'vorschau' ? '&vorschau=1' : '') . $anker);
    }
    if ($action === 'veroeffentlichen') {
        $err = splash_veroeffentlichen($id);
        flash($err !== '' ? $err : 'Veröffentlicht – beim nächsten Öffnen sehen ihn alle einmal am Rechner und einmal am Handy.', $err !== '' ? 'error' : 'success');
        redirect('neuigkeiten.php' . ($err !== '' ? '?id=' . $id : ''));
    }
    if ($action === 'erneut') {
        splash_erneut_zeigen($id);
        flash('Alle sehen ihn beim nächsten Öffnen noch einmal.', 'success');
        redirect('neuigkeiten.php');
    }
    if ($action === 'zurueckziehen') {
        splash_zurueckziehen($id);
        flash('Zurückgezogen – niemand bekommt ihn mehr zu sehen.', 'success');
        redirect('neuigkeiten.php');
    }
    if ($action === 'kopieren') {
        $neu = splash_kopieren($id, $meId);
        flash('Als neuer Entwurf übernommen.', 'success');
        redirect('neuigkeiten.php?id=' . $neu);
    }
    if ($action === 'loeschen') {
        flash(splash_loeschen($id) ? 'Gelöscht.' : 'Ein laufender Splash lässt sich nicht löschen – erst zurückziehen.', 'success');
        redirect('neuigkeiten.php');
    }
    if ($action === 'mir_zeigen' && $meId > 0 && $s['ziel'] === 'app') {
        db()->prepare("UPDATE members SET news_seen = '', news_seen_mobil = '' WHERE id = ?")->execute([$meId]);
        flash('Beim nächsten Seitenaufruf siehst du ihn noch einmal – hier und am Handy.', 'success');
        redirect('../dashboard.php');
    }
    redirect('neuigkeiten.php');
}

$edit = isset($_GET['id']) ? splash_get((int)$_GET['id']) : null;
$ziele = splash_ziele();
$statusPill = static fn(string $st): string => [
    'live'    => '<span class="pill pill-ok">läuft</span>',
    'entwurf' => '<span class="pill pill-info">Entwurf</span>',
    'archiv'  => '<span class="pill">früher</span>',
][$st] ?? '';

// Symbol-Auswahl als Kacheln in einem Klapp-Abschnitt; die Zusammenfassung zeigt das gewählte.
$iconWahl = static function (string $name, string $aktuell, string $label): string {
    $o = '<details class="sp-icon-wahl"><summary><span class="sp-icon-jetzt"><i class="ti ti-' . h($aktuell) . '" aria-hidden="true"></i></span> '
       . h($label) . ' ändern</summary><div class="ban-icons" role="radiogroup" aria-label="' . h($label) . '">';
    foreach (splash_icons() as $ik => $il) {
        $o .= '<label title="' . h($il) . '"><input type="radio" name="' . h($name) . '" value="' . h($ik) . '" aria-label="' . h($il) . '"'
            . ($ik === $aktuell ? ' checked' : '') . '><span><i class="ti ti-' . h($ik) . '" aria-hidden="true"></i></span></label>';
    }
    return $o . '</div></details>';
};

page_header('Neuigkeiten-Splash', true);
?>
<p class="small"><a href="<?= $edit ? 'neuigkeiten.php' : 'index.php' ?>">‹ <?= $edit ? 'Alle Splashs' : 'Verwaltung' ?></a></p>

<?php if (!$edit): ?>
<div class="events-toolbar">
  <h1><i class="ti ti-sparkles" style="color:var(--petrol)"></i> Neuigkeiten-Splash</h1>
</div>
<p class="muted" style="max-width:68ch">Nach einem Update erklärt ein Splash in ein paar Punkten, was neu ist. In der <strong>App</strong>
  sehen ihn alle Bestandsmitglieder einmal am Rechner und einmal am Handy; wer neu dazukommt, lernt die Neuerungen im Rundgang
  kennen und bekommt ihn nicht. Im <strong>StuPa-Bereich</strong> sehen ihn alle Zugänge. Je Bereich läuft immer höchstens einer.</p>

<?php $alle = splash_alle(); ?>
<?php foreach ($ziele as $zk => $zl): $live = splash_live($zk); ?>
  <div class="section-title"><i class="ti <?= $zk === 'stupa' ? 'ti-building-community' : 'ti-device-mobile' ?>"></i> <?= h($zl) ?></div>
  <div class="card">
    <?php if ($live): $z = splash_gesehen_zahlen($live); ?>
      <div class="sp-zeile">
        <div>
          <?= $statusPill('live') ?> <strong><?= h($live['inhalt']['titel']) ?></strong>
          <?php if ($live['name'] !== ''): ?><span class="muted small">· <?= h($live['name']) ?></span><?php endif; ?>
          <div class="small muted">seit <?= h(fmt_date(substr((string)$live['published_at'], 0, 10))) ?> ·
            gesehen von <?= $z['desktop'] ?> von <?= $z['gesamt'] ?> am Rechner, <?= $z['mobil'] ?> von <?= $z['gesamt'] ?> am Handy</div>
        </div>
        <div class="btn-row">
          <a class="btn secondary small" href="neuigkeiten.php?id=<?= (int)$live['id'] ?>"><i class="ti ti-pencil"></i> Bearbeiten</a>
          <?php if ($zk === 'app' && $meId > 0): ?>
            <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="mir_zeigen"><input type="hidden" name="id" value="<?= (int)$live['id'] ?>">
              <button class="btn secondary small" type="submit"><i class="ti ti-eye"></i> Mir noch einmal zeigen</button></form>
          <?php endif; ?>
          <form method="post" style="margin:0" data-confirm="Allen noch einmal zeigen? Auch wer ihn schon gesehen hat, bekommt ihn beim nächsten Öffnen wieder." data-confirm-ok="Noch einmal zeigen">
            <?= csrf_field() ?><input type="hidden" name="action" value="erneut"><input type="hidden" name="id" value="<?= (int)$live['id'] ?>">
            <button class="btn secondary small" type="submit"><i class="ti ti-refresh"></i> Allen noch einmal zeigen</button></form>
          <form method="post" style="margin:0" data-confirm="Zurückziehen? Wer ihn noch nicht gesehen hat, bekommt ihn dann nicht mehr." data-confirm-ok="Zurückziehen">
            <?= csrf_field() ?><input type="hidden" name="action" value="zurueckziehen"><input type="hidden" name="id" value="<?= (int)$live['id'] ?>">
            <button class="btn secondary small" type="submit"><i class="ti ti-player-stop"></i> Zurückziehen</button></form>
        </div>
      </div>
    <?php else: ?>
      <p class="small muted" style="margin:0 0 .6rem">Gerade läuft hier kein Splash.</p>
    <?php endif; ?>
    <form method="post" style="margin:.8rem 0 0"><?= csrf_field() ?><input type="hidden" name="action" value="neu"><input type="hidden" name="ziel" value="<?= h($zk) ?>">
      <button class="btn" type="submit"><i class="ti ti-plus"></i> Neuen Splash bauen</button></form>
  </div>
  <?php $rest = array_filter($alle, fn($s) => $s['ziel'] === $zk && $s['status'] !== 'live'); if ($rest): ?>
  <div class="card sp-liste">
    <?php foreach ($rest as $s): ?>
      <div class="sp-zeile">
        <div>
          <?= $statusPill((string)$s['status']) ?> <strong><?= h($s['inhalt']['titel'] !== '' ? $s['inhalt']['titel'] : 'Ohne Überschrift') ?></strong>
          <?php if ($s['name'] !== ''): ?><span class="muted small">· <?= h($s['name']) ?></span><?php endif; ?>
          <div class="small muted"><?= $s['status'] === 'archiv' && $s['published_at'] !== ''
              ? 'lief ab ' . h(fmt_date(substr((string)$s['published_at'], 0, 10)))
              : 'zuletzt bearbeitet ' . h(fmt_date(substr((string)$s['updated_at'], 0, 10))) ?></div>
        </div>
        <div class="btn-row">
          <a class="btn secondary small" href="neuigkeiten.php?id=<?= (int)$s['id'] ?>"><i class="ti ti-pencil"></i> <?= $s['status'] === 'entwurf' ? 'Weiterbauen' : 'Ansehen' ?></a>
          <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="kopieren"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn secondary small" type="submit"><i class="ti ti-copy"></i> Als Vorlage nehmen</button></form>
          <form method="post" style="margin:0" data-confirm="Diesen Splash löschen?" data-confirm-ok="Löschen" data-confirm-danger>
            <?= csrf_field() ?><input type="hidden" name="action" value="loeschen"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn secondary small" type="submit" aria-label="Löschen"><i class="ti ti-trash"></i></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endforeach; ?>

<?php else:
  $in = $edit['inhalt'];
  $ziel = (string)$edit['ziel'];
  $live = $edit['status'] === 'live';
  $fehlt = splash_fehlt($in);
  // Knopf-Ziele: Orte der App (nur die mit Seite) bzw. die Seiten des StuPa-Bereichs
  $ziele_knopf = ['' => 'Kein Knopf'];
  if ($ziel === 'app') {
      $ziele_knopf['menu'] = 'Namens-Menü öffnen';
      foreach (app_places() as $ok => $od) $ziele_knopf['ort:' . $ok] = (string)$od['label'];
  } else {
      foreach (splash_stupa_seiten() as $sk => $sl) $ziele_knopf['seite:' . $sk] = $sl;
  }
  $ziele_knopf['link'] = 'Eigene Adresse (unten eintragen)';
?>
<div class="events-toolbar">
  <h1><i class="ti ti-sparkles" style="color:var(--petrol)"></i> <?= h($in['titel'] !== '' ? $in['titel'] : 'Neuer Splash') ?></h1>
  <span><?= $statusPill((string)$edit['status']) ?> <span class="pill pill-info"><?= h($ziele[$ziel]) ?></span></span>
</div>
<?php if ($live): ?>
  <p class="small"><i class="ti ti-info-circle"></i> Dieser Splash läuft. Gespeicherte Änderungen sehen alle, die ihn noch nicht geöffnet haben;
    wer ihn schon kennt, sieht sie nur nach „Allen noch einmal zeigen“ in der Übersicht.</p>
<?php endif; ?>

<form method="post" class="sp-editor">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="speichern">
  <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

  <div class="section-title"><i class="ti ti-layout-navbar"></i> Kopf</div>
  <div class="card">
    <div class="field-row">
      <div><label for="sp_kicker">Kleine Zeile darüber</label>
        <input type="text" name="s[kicker]" id="sp_kicker" maxlength="40" value="<?= h($in['kicker']) ?>"></div>
      <div><label for="sp_titel">Überschrift</label>
        <input type="text" name="s[titel]" id="sp_titel" maxlength="80" value="<?= h($in['titel']) ?>" placeholder="z. B. Frisch eingeräumt"></div>
    </div>
    <span class="small" id="sp_kopf_lbl">Farbverlauf</span>
    <div class="sp-kopf-wahl" role="radiogroup" aria-labelledby="sp_kopf_lbl">
      <?php foreach (splash_kopf_stile() as $kk => $kl): ?>
        <label><input type="radio" name="s[kopf]" value="<?= h($kk) ?>"<?= $kk === $in['kopf'] ? ' checked' : '' ?>><span class="neu-kopf neu-kopf-<?= h($kk) ?>"><?= h($kl) ?></span></label>
      <?php endforeach; ?>
    </div>
    <?= $iconWahl('s[kopf_icon]', $in['kopf_icon'], 'Symbol im Kopf') ?>
    <label for="sp_name">Interner Name <span class="muted small">(nur hier in der Liste zu sehen)</span></label>
    <input type="text" name="name" id="sp_name" maxlength="80" value="<?= h((string)$edit['name']) ?>" placeholder="z. B. Update November">
  </div>

  <div class="section-title" id="punkte"><i class="ti ti-list-details"></i> Punkte <span class="count"><?= count($in['punkte']) ?></span></div>
  <?php foreach ($in['punkte'] as $i => $p): $n = 'p[' . $i . ']'; ?>
    <div class="card sp-punkt" id="punkt-<?= $i ?>">
      <div class="sp-punkt-kopf">
        <strong><span class="neu-<?= h($p['farbe']) ?>"><span class="neu-icon sp-mini"><i class="ti ti-<?= h($p['icon']) ?>"></i></span></span> Punkt <?= $i + 1 ?></strong>
        <span class="btn-row">
          <?php if ($i > 0): ?><button class="btn secondary small" type="submit" name="op" value="up-<?= $i ?>" aria-label="Nach oben"><i class="ti ti-arrow-up"></i></button><?php endif; ?>
          <?php if ($i < count($in['punkte']) - 1): ?><button class="btn secondary small" type="submit" name="op" value="down-<?= $i ?>" aria-label="Nach unten"><i class="ti ti-arrow-down"></i></button><?php endif; ?>
          <button class="btn secondary small" type="submit" name="op" value="del-<?= $i ?>" aria-label="Punkt entfernen"><i class="ti ti-trash"></i></button>
        </span>
      </div>
      <?= $iconWahl($n . '[icon]', $p['icon'], 'Symbol') ?>
      <span class="small">Farbe</span>
      <div class="sp-farb-wahl" role="radiogroup" aria-label="Farbe">
        <?php foreach (splash_farben() as $fk => $fl): ?>
          <label class="neu-<?= h($fk) ?>"><input type="radio" name="<?= $n ?>[farbe]" value="<?= h($fk) ?>"<?= $fk === $p['farbe'] ? ' checked' : '' ?>><span class="neu-icon"><i class="ti ti-<?= h($p['icon']) ?>" aria-hidden="true"></i></span><span class="small"><?= h($fl) ?></span></label>
        <?php endforeach; ?>
      </div>
      <label for="p<?= $i ?>_titel">Titel</label>
      <input type="text" name="<?= $n ?>[titel]" id="p<?= $i ?>_titel" maxlength="80" value="<?= h($p['titel']) ?>">
      <label for="p<?= $i ?>_text">Text <span class="muted small">– **so** wird fett, Adressen werden anklickbar</span></label>
      <textarea name="<?= $n ?>[text]" id="p<?= $i ?>_text" rows="3" maxlength="500"><?= h($p['text']) ?></textarea>
      <div class="field-row">
        <div><label for="p<?= $i ?>_ziel">Knopf führt zu</label>
          <select name="<?= $n ?>[ziel]" id="p<?= $i ?>_ziel">
            <?php foreach ($ziele_knopf as $zk => $zl): ?>
              <option value="<?= h($zk) ?>"<?= $zk === $p['ziel'] ? ' selected' : '' ?>><?= h($zl) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label for="p<?= $i ?>_knopf">Knopf-Text</label>
          <input type="text" name="<?= $n ?>[knopf]" id="p<?= $i ?>_knopf" maxlength="40" value="<?= h($p['knopf']) ?>" placeholder="z. B. Zu den Räumen"></div>
      </div>
      <label for="p<?= $i ?>_link">Eigene Adresse <span class="muted small">– eine Seite wie <code>tauschboerse.php#guthaben</code> oder <code>https://…</code></span></label>
      <input type="text" name="<?= $n ?>[link]" id="p<?= $i ?>_link" value="<?= h($p['link']) ?>">
      <details class="sp-orte"<?= $p['orte'] ? ' open' : '' ?>>
        <summary>Umgezogene Menüpunkte <span class="muted small">(optional, als kleine Liste unter dem Text)</span></summary>
        <?php foreach (array_merge($p['orte'], [['icon' => 'directions', 'name' => '', 'wo' => ''], ['icon' => 'directions', 'name' => '', 'wo' => '']]) as $j => $o): $on = $n . '[orte][' . $j . ']'; ?>
          <div class="sp-ort">
            <select name="<?= $on ?>[icon]" aria-label="Symbol">
              <?php foreach (splash_icons() as $ik => $il): ?><option value="<?= h($ik) ?>"<?= $ik === $o['icon'] ? ' selected' : '' ?>><?= h($il) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="<?= $on ?>[name]" maxlength="40" value="<?= h($o['name']) ?>" placeholder="Was" aria-label="Was">
            <input type="text" name="<?= $on ?>[wo]" maxlength="40" value="<?= h($o['wo']) ?>" placeholder="Wohin, z. B. unter deinem Namen" aria-label="Wohin">
          </div>
        <?php endforeach; ?>
        <p class="small muted" style="margin:.3rem 0 0">Leere Zeilen fallen weg; nach dem Speichern kommen zwei neue dazu.</p>
      </details>
    </div>
  <?php endforeach; ?>
  <?php if (count($in['punkte']) < SPLASH_MAX_PUNKTE): ?>
    <button class="btn secondary" type="submit" name="op" value="add"><i class="ti ti-plus"></i> Punkt hinzufügen</button>
  <?php endif; ?>

  <div class="section-title"><i class="ti ti-layout-bottombar"></i> Schluss</div>
  <div class="card">
    <label for="sp_ausserdem">Außerdem <span class="muted small">– eine Zeile für Kleinigkeiten, optional</span></label>
    <textarea name="s[ausserdem]" id="sp_ausserdem" rows="2" maxlength="300"><?= h($in['ausserdem']) ?></textarea>
    <label for="sp_fertig">Schließen-Knopf</label>
    <input type="text" name="s[fertig]" id="sp_fertig" maxlength="30" value="<?= h($in['fertig']) ?>" style="max-width:16rem">
  </div>

  <div class="btn-row sp-aktionen">
    <button class="btn" type="submit" name="op" value="vorschau"><i class="ti ti-eye"></i> Speichern &amp; Vorschau</button>
    <button class="btn secondary" type="submit" name="op" value="speichern"><i class="ti ti-device-floppy"></i> Speichern</button>
  </div>
</form>

<div class="section-title"><i class="ti ti-send"></i> <?= $live ? 'Läuft' : 'Veröffentlichen' ?></div>
<div class="card">
  <p class="small" style="margin-top:0">
    <button class="btn secondary small" type="button" data-neu-vorschau="neuVorschau"><i class="ti ti-eye"></i> Vorschau öffnen</button>
    <span class="muted">zeigt den zuletzt gespeicherten Stand – so, wie ihn alle sehen.</span></p>
  <?php if ($live): ?>
    <p class="small muted" style="margin-bottom:0">Noch einmal zeigen oder zurückziehen geht in der <a href="neuigkeiten.php">Übersicht</a>.</p>
  <?php elseif ($fehlt !== ''): ?>
    <p class="small" style="margin-bottom:0"><i class="ti ti-alert-triangle" style="color:var(--amber)"></i> <?= h($fehlt) ?></p>
  <?php else:
      $altLive = splash_live($ziel); ?>
    <form method="post" style="margin:0" data-confirm="Jetzt veröffentlichen? <?= h($ziel === 'app' ? 'Alle Bestandsmitglieder' : 'Alle StuPa-Zugänge') ?> sehen ihn beim nächsten Öffnen einmal am Rechner und einmal am Handy.<?= $altLive ? ' Der laufende Splash „' . h($altLive['inhalt']['titel']) . '“ endet damit.' : '' ?>" data-confirm-ok="Veröffentlichen">
      <?= csrf_field() ?><input type="hidden" name="action" value="veroeffentlichen"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <p class="small muted" style="margin-top:0">Veröffentlicht wird der zuletzt gespeicherte Stand.<?= $ziel === 'app' ? ' Neue Mitglieder sehen ihn nicht – was neu ist, gehört deshalb auch in den Rundgang.' : '' ?></p>
      <button class="btn" type="submit"><i class="ti ti-send"></i> Veröffentlichen</button>
    </form>
  <?php endif; ?>
</div>
<?= app_neuigkeiten_html($ziel === 'stupa' ? '../stupa/' : '../', $in, $ziel, 'neuVorschau') ?>
<?php if (isset($_GET['vorschau'])): ?><script>window.ASTA_NEU_VORSCHAU = 'neuVorschau';</script><?php endif; ?>
<?php endif; ?>
<?php
page_footer();
