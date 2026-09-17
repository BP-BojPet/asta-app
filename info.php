<?php
require __DIR__ . '/lib.php';
db();
require_login();

$me = current_member();
// (Pronomen-Wahl und Konto-Wechsel sind ins Nutzerprofil bzw. Namens-Dropdown gewandert –
// diese Seite ist seither ein reines „Wichtige Infos & Anleitung"-Ding.)

// Mitglieder sehen die Infos für alle + die ihres Referats; Technik-Login/Owner (kein Mitglied) sieht alles
$infos = $me ? infos_all(trim((string)($me['referat'] ?? ''))) : infos_all();
$infoReads = $me ? info_reads_for((int)$me['id']) : [];

// Während des geführten Rundgangs (Cookie asta_tour, von app.js gesetzt) wird der Nutzer nur
// zur Ansicht HIERHER geleitet – dieser Besuch darf die Infos NICHT als gelesen markieren.
$tourVisit = !empty($_COOKIE['asta_tour']);

// Ungelesene Infos gelten mit dem Seitenbesuch AUTOMATISCH als gelesen (kein Extra-Klick;
// zählt für Basis-Score und Achievements). Für DIESEN Aufruf bleiben sie als
// „NEU" markiert, damit man sofort sieht, was es Neues gibt.
$justRead = [];
if ($me && !$tourVisit) {
    foreach ($infos as $i) {
        if (!isset($infoReads[(int)$i['id']])) {
            info_mark_read((int)$i['id'], (int)$me['id']);
            $justRead[(int)$i['id']] = true;
        }
    }
}
$memberMd = readme_member_section();
$sekkiMd = can_manage_meetings() ? readme_secretariat_section() : ''; // nur Sekretariat/Vorsitz/Admin
// Pat:innen-Verantwortliche kommen nicht in die Verwaltung – ohne das hier sähen sie
// die Anleitung zu ihrem eigenen Bereich nirgends.
$patMd = (is_pat_manager() || can_admin()) ? readme_pat_section() : '';

if ($me && !$tourVisit) mark_infos_seen((int)$me['id']); // Besuch merken -> Dashboard-Hinweis verschwindet (im Rundgang unterdrückt)

page_header('Infos & Anleitung');
?>
<div class="events-toolbar">
  <h1><i class="ti ti-info-circle" style="color:var(--petrol)"></i> Wichtige Infos &amp; Anleitung</h1>
  <?php if (can_admin()): ?><a class="btn secondary" href="admin/infos.php"><i class="ti ti-edit"></i> Infos verwalten</a><?php endif; ?>
</div>

<div class="card" style="display:flex;flex-wrap:wrap;align-items:center;gap:.8rem">
  <div style="flex:1;min-width:220px">
    <div class="section-title" style="margin-top:0"><i class="ti ti-route"></i> Geführter Rundgang</div>
    <p class="small muted" style="margin:0">Neu hier – oder mal wieder Lust auf eine Auffrischung? Der kurze Rundgang zeigt dir Schritt für Schritt die wichtigsten Ecken der App (Streak, Scores, Navigation, Profil).</p>
  </div>
  <a class="btn" href="dashboard.php?tour=1"><i class="ti ti-player-play"></i> Rundgang starten</a>
</div>

<?php if ($infos): ?>
  <div class="section-title"><i class="ti ti-pin"></i> Wichtige Informationen</div>
  <?php foreach ($infos as $i): ?>
    <div class="card">
      <div class="section-title" style="margin-top:0;font-size:1.05rem"><?= h($i['title']) ?><?php if (isset($justRead[(int)$i['id']])): ?> <span class="pill pill-important" style="font-size:.7rem;font-weight:700">NEU</span><?php endif; ?><?php if (trim((string)($i['referat'] ?? '')) !== ''): ?> <span class="pill pill-info" style="font-size:.7rem;font-weight:600" title="Nur für dieses Referat sichtbar"><i class="ti ti-users-group" style="font-size:.85em"></i> Referat <?= h($i['referat']) ?></span><?php endif; ?></div>
      <div class="clamp">
        <?php if (trim((string)$i['body']) !== ''): ?><div class="markdown"><?= render_markdown((string)$i['body']) ?></div><?php endif; ?>
        <?php if ($i['files']): ?>
          <div class="info-files">
            <?php foreach ($i['files'] as $f): ?>
              <a class="info-file" href="download.php?file=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">
                <i class="ti ti-file-download"></i> <span><?= h($f['orig_name']) ?></span>
                <span class="muted small">· <?= h(human_filesize((int)$f['size'])) ?></span>
              </a>
              <?= mirror_open_button('info', (int)$f['id']) ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <button type="button" class="clamp-toggle" hidden><i class="ti ti-chevron-down"></i> Mehr anzeigen</button>
      <?php if ($me): $readAt = $infoReads[(int)$i['id']] ?? ''; ?>
        <?php if (isset($justRead[(int)$i['id']])): ?>
          <p class="small muted" style="margin:.6rem 0 0"><i class="ti ti-sparkles" style="color:var(--petrol)"></i> Neu für dich – gilt mit diesem Besuch automatisch als gelesen.</p>
        <?php elseif ($readAt !== ''): ?>
          <p class="small muted" style="margin:.6rem 0 0"><i class="ti ti-circle-check" style="color:var(--green)"></i> Gelesen am <?= h(fmt_date(substr($readAt, 0, 10))) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($sekkiMd !== ''): ?>
  <div class="section-title"><i class="ti ti-id-badge-2"></i> Fürs Sekretariat</div>
  <div class="card">
    <div class="clamp"><div class="markdown"><?= render_markdown($sekkiMd) ?></div></div>
    <button type="button" class="clamp-toggle" hidden><i class="ti ti-chevron-down"></i> Mehr anzeigen</button>
  </div>
<?php endif; ?>

<?php if ($patMd !== ''): ?>
  <div class="section-title"><i class="ti ti-heart-handshake"></i> Fürs Pat:innenprogramm</div>
  <div class="card">
    <div class="clamp"><div class="markdown"><?= render_markdown($patMd) ?></div></div>
    <button type="button" class="clamp-toggle" hidden><i class="ti ti-chevron-down"></i> Mehr anzeigen</button>
  </div>
<?php endif; ?>

<?php if ($memberMd !== ''): ?>
  <div class="section-title"><i class="ti ti-book-2"></i> So funktioniert die AStA-App</div>
  <div class="card">
    <div class="clamp"><div class="markdown"><?= render_markdown($memberMd) ?></div></div>
    <button type="button" class="clamp-toggle" hidden><i class="ti ti-chevron-down"></i> Mehr anzeigen</button>
  </div>
<?php endif; ?>

<?php if (!$infos && $memberMd === ''): ?>
  <div class="card"><p class="empty"><i class="ti ti-info-circle"></i> Hier gibt es aktuell noch keine Infos.</p></div>
<?php endif; ?>
<?php
page_footer();
