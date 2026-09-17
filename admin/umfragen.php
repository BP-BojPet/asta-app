<?php
/**
 * Verwaltung der hochschulöffentlichen Umfragen (Vorsitz & Admin).
 *
 * Diese Seite ist die einzige Stelle, an der Umfragen entstehen. Sie greift auf BEIDE
 * Datenbanken zu: auf die App (für Rechte und die Voreinstellungen aus den App-Einstellungen)
 * und auf die getrennte Umfrage-Datenbank. Der öffentliche Bereich kennt nur letztere.
 */
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_login();
require_once __DIR__ . '/../umfrage-db.php';

// Zugang: Vorsitz/Admin voll – sonst Mitglieder, deren REFERAT an mindestens einer Umfrage
// als zuständig eingetragen ist (je Umfrage pflegbar, unten bei den Eckdaten). Die sehen und
// betreuen NUR ihre Umfragen; Anlegen und Einstellungen bleiben bei Vorsitz/Admin.
$umAdmin = can_admin();
$umMeine = $umAdmin ? null : umfrage_zustaendig_fuer_mich(); // null = alle
if ($umMeine !== null && !$umMeine) { http_response_code(403); exit('Kein Zugriff.'); }
/** Darf die aktuelle Person DIESE Umfrage betreuen? */
$umDarf = function (int $pollId) use ($umMeine): bool {
    if ($umMeine === null) return true;
    foreach ($umMeine as $p) if ((int)$p['id'] === $pollId) return true;
    return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $cm     = current_member();
    $pid    = (int)($_POST['poll_id'] ?? 0);

    // Zuständigkeits-Wächter: Jede Aktion an einer Umfrage braucht das Recht an GENAU dieser
    // Umfrage; Anlegen und die Einstellungs-Aktionen unten bleiben Vorsitz/Admin vorbehalten.
    $umNur = function (int $pollId) use ($umDarf): void {
        if (!$umDarf($pollId)) { http_response_code(403); exit('Kein Zugriff auf diese Umfrage.'); }
    };
    // Fragen-Aktionen tragen die Umfrage als poll_id mit – die Frage muss auch WIRKLICH dazu
    // gehören, sonst ließe sich über eine fremde Fragen-ID an fremden Umfragen arbeiten.
    $umFrageZu = function (int $qid) use ($pid): void {
        if ($qid > 0 && (int)((umfrage_question_get($qid)['poll_id'] ?? 0)) !== $pid) {
            http_response_code(403); exit('Diese Frage gehört nicht zu dieser Umfrage.');
        }
    };
    if (in_array($action, ['poll_status', 'poll_delete', 'q_save', 'q_delete', 'q_copy', 'q_move'], true)) $umNur($pid);
    if (in_array($action, ['q_save', 'q_delete', 'q_copy', 'q_move'], true)) $umFrageZu((int)($_POST['id'] ?? 0));
    if (in_array($action, ['settings_save', 'settings_from_app', 'test_mail'], true) && !$umAdmin) {
        http_response_code(403); exit('Kein Zugriff.');
    }

    if ($action === 'poll_save') {
        $sid = (int)($_POST['id'] ?? 0);
        if ($sid === 0 && !$umAdmin) { http_response_code(403); exit('Neue Umfragen legen Vorsitz und Admin an.'); }
        if ($sid > 0) $umNur($sid);
        $r = umfrage_save($sid, $_POST, (int)($cm['id'] ?? 0));
        // Zuständige Referate: nur Vorsitz/Admin vergeben sie (sonst könnte sich ein Referat
        // selbst überall eintragen); das Formular zeigt die Kästchen auch nur ihnen – bei
        // allen anderen bleibt der gespeicherte Stand schlicht stehen. Der Marker referate_da
        // ist nötig, weil ein Formular mit LAUTER abgewählten Kästchen gar kein referate[]
        // schickt – ohne ihn ließe sich die Zuständigkeit nie wieder komplett entfernen.
        if ($r['ok'] && $umAdmin && isset($_POST['referate_da'])) {
            // Referate ODER Personen – der Baustein liefert beides, zust_form_speichern()
            // entscheidet anhand des mitgeschickten Modus und leert die jeweils andere Seite.
            zust_form_speichern('umfrage:' . (int)$r['id'], $_POST);
        }
        flash($r['msg'], $r['typ']);
        redirect($r['ok'] ? 'umfragen.php?edit=' . $r['id'] : 'umfragen.php');
    }
    if ($action === 'poll_status') {
        $r = umfrage_set_status($pid, (string)($_POST['status'] ?? ''));
        flash($r['msg'], $r['ok'] ? 'success' : 'error');
        redirect('umfragen.php?edit=' . $pid);
    }
    if ($action === 'poll_delete') {
        umfrage_delete($pid);
        flash('Umfrage samt Fragen und Stimmen gelöscht.', 'success');
        redirect('umfragen.php');
    }
    if ($action === 'q_save') {
        $r = umfrage_question_save($pid, (int)($_POST['id'] ?? 0), $_POST);
        $meldung = (string)$r['msg'];
        $bErr = null;
        // Das Bild erst NACH der Frage: Bei einer neuen gibt es vorher keine Zeile, an der es
        // hängen könnte. Scheitert der Upload, bleibt die Frage trotzdem gespeichert – die
        // Meldung sagt dann, woran es lag.
        if (!empty($r['ok'])) {
            $qid = (int)$r['id'];
            if (!empty($_POST['image_delete'])) umfrage_question_bild_weg($qid);
            $datei = umfrage_bild_speichern((array)($_FILES['image'] ?? []), $bErr);
            if ($datei !== '') {
                umfrage_question_bild_setzen($qid, $datei, (string)($_POST['image_alt'] ?? ''));
                $meldung .= ' Bild übernommen.';
            } elseif ($bErr !== null) {
                $meldung .= ' ABER: ' . $bErr;
            } elseif (empty($_POST['image_delete'])) {
                // Kein neues Bild hochgeladen: nur die Beschreibung nachziehen.
                $qAlt = umfrage_question_get($qid);
                if ($qAlt && trim((string)($qAlt['image'] ?? '')) !== '') {
                    umfrage_question_bild_setzen($qid, (string)$qAlt['image'], (string)($_POST['image_alt'] ?? ''));
                }
            }
        }
        flash($meldung, !empty($r['ok']) && $bErr === null ? 'success' : 'error');
        redirect('umfragen.php?edit=' . $pid . '#fragen');
    }
    if ($action === 'preview_new') {
        umfrage_preview_token((int)($_POST['id'] ?? 0), true);
        flash('Neuer Vorschau-Link erzeugt – der alte funktioniert nicht mehr.', 'success');
        redirect('umfragen.php?edit=' . $pid);
    }
    if ($action === 'q_delete') {
        umfrage_question_delete((int)($_POST['id'] ?? 0));
        flash('Frage gelöscht.', 'success');
        redirect('umfragen.php?edit=' . $pid . '#fragen');
    }
    if ($action === 'q_copy') {
        $nid = umfrage_question_copy((int)($_POST['id'] ?? 0));
        flash($nid ? 'Frage verdoppelt – die Kopie steht ganz unten.' : 'Frage nicht gefunden.', $nid ? 'success' : 'error');
        redirect('umfragen.php?edit=' . $pid . '#fragen');
    }
    if ($action === 'q_move') {
        umfrage_question_move((int)($_POST['id'] ?? 0), ($_POST['dir'] ?? '') === 'up' ? -1 : 1);
        redirect('umfragen.php?edit=' . $pid . '#fragen');
    }
    if ($action === 'settings_save') {
        foreach (['base_url', 'from_email', 'from_name', 'domains', 'imprint_url', 'privacy_url', 'cap_day', 'cap_hour'] as $k) {
            umfrage_setting_set($k, trim((string)($_POST[$k] ?? '')));
        }
        foreach (array_keys(umfrage_texts()) as $k) {
            if (isset($_POST['text_' . $k])) umfrage_setting_set('text_' . $k, trim((string)$_POST['text_' . $k]));
            // Englische Fassung: leer lassen heißt „dann eben auf Deutsch verschicken".
            if (isset($_POST['text_' . $k . '_en'])) umfrage_setting_set('text_' . $k . '_en', trim((string)$_POST['text_' . $k . '_en']));
        }
        flash('Einstellungen gespeichert.', 'success');
        redirect('umfragen.php?t=einstellungen');
    }
    if ($action === 'settings_from_app') {
        // Basis-Adresse und Absender aus der App übernehmen – die stehen dort schon gepflegt.
        umfrage_setting_set('base_url', rtrim(base_url(), '/'));
        umfrage_setting_set('from_email', mail_from());
        if (trim(umfrage_setting_get('from_name', '')) === '') umfrage_setting_set('from_name', umfrage_traeger());
        flash('Basis-Adresse und Absender aus den App-Einstellungen übernommen.', 'success');
        redirect('umfragen.php?t=einstellungen');
    }
    if ($action === 'test_mail') {
        $to = current_member() ? member_mail(current_member()) : '';
        if ($to === '') { flash('Dafür braucht es ein Konto mit E-Mail-Adresse.', 'error'); redirect('umfragen.php?t=einstellungen'); }
        $ok = umfrage_mail($to, 'Testmail: Umfragen', "Wenn du das liest, kann der Umfrage-Bereich Mails verschicken.\n\n(ausgelöst über Verwaltung → Umfragen)");
        if ($ok) umfrage_mail_note(); // zählt mit: sonst zeigte das Kontingent weniger, als wirklich rausging
        flash($ok ? 'Testmail an ' . $to . ' übergeben.' : 'Versand fehlgeschlagen – ist eine gültige Absender-Adresse hinterlegt?', $ok ? 'success' : 'error');
        redirect('umfragen.php?t=einstellungen');
    }
    redirect('umfragen.php');
}

// Verzeichnis und Token beendeter Umfragen laufen hier ab – ein eigener Cron wäre dafür zu viel.
umfrage_prune();

$editId = (int)($_GET['edit'] ?? 0);
$edit   = $editId ? umfrage_get($editId) : null;
$resId  = (int)($_GET['results'] ?? 0);
$res    = $resId ? umfrage_get($resId) : null;
// Zuständigkeits-Wächter auch beim ANSEHEN: fremde Umfragen gibt es für Referats-Zugänge nicht.
if ($edit && !$umDarf((int)$edit['id'])) { http_response_code(403); exit('Kein Zugriff auf diese Umfrage.'); }
if ($res && !$umDarf((int)$res['id']))   { http_response_code(403); exit('Kein Zugriff auf diese Umfrage.'); }

// ---- CSV-Ausgabe (vor jeder HTML-Ausgabe) ----
if ($res && !empty($_GET['export'])) {
    $rows = umfrage_results((int)$res['id'], true);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="umfrage-' . preg_replace('~[^a-z0-9-]~', '', (string)$res['slug']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel die Umlaute versteht
    fputcsv($out, ['Frage', 'Typ', 'Antwort', 'Anzahl', 'Anteil %'], ';');
    foreach ($rows as $e) {
        $q = $e['frage'];
        if ($e['typ'] === 'text') {
            foreach ($e['texte'] as $t) fputcsv($out, [$q['title'], 'Freitext', $t, '', ''], ';');
        } else {
            foreach ($e['zeilen'] as $z) fputcsv($out, [$q['title'], $e['typ'], $z['label'], $z['n'], $z['pct']], ';');
        }
    }
    fclose($out);
    exit;
}

page_header('Umfragen', true);

/** Statusplakette einer Umfrage. */
function um_pill(array $p): string
{
    if ((string)$p['status'] === 'draft')  return '<span class="pill pill-info">Entwurf</span>';
    if ((string)$p['status'] === 'closed') return '<span class="pill pill-bad">geschlossen</span>';
    if (umfrage_is_open($p)) return '<span class="pill pill-ok">läuft</span>';
    $jetzt = date('Y-m-d H:i:s');
    if (trim((string)$p['starts_at']) !== '' && $p['starts_at'] > $jetzt) return '<span class="pill pill-warn">startet später</span>';
    return '<span class="pill pill-warn">Frist abgelaufen</span>';
}
?>
<?php if ($umAdmin): ?>
  <p class="small"><a href="index.php">‹ Verwaltung</a></p>
<?php else: // Referats-Zugang: die Verwaltung würde sie abweisen – zurück geht es aufs Dashboard ?>
  <p class="small"><a href="../dashboard.php">‹ Dashboard</a></p>
<?php endif; ?>
<div class="events-toolbar">
  <h1><i class="ti ti-chart-donut" style="color:var(--petrol)"></i> Umfragen</h1>
  <?php if ($edit || $res): ?><a class="btn secondary" href="umfragen.php"><i class="ti ti-list"></i> Alle Umfragen</a><?php endif; ?>
</div>

<?php if ($res): // ================= Ergebnis-Ansicht =================
  $zahlen = umfrage_counts((int)$res['id']);
  $rows   = umfrage_results((int)$res['id'], true); ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-chart-bar"></i> <?= h($res['title']) ?> <?= um_pill($res) ?></div>
    <p class="small muted" style="margin:0">
      <strong><?= (int)$zahlen['stimmen'] ?></strong> gezählte Stimmen ·
      <strong><?= (int)$zahlen['wartend'] ?></strong> noch unbestätigt ·
      <?= umfrage_results_public($res) ? 'Ergebnis ist öffentlich sichtbar' : 'Ergebnis ist noch NICHT öffentlich' ?>
    </p>
    <div class="btn-row" style="margin-top:.8rem">
      <a class="btn secondary small" href="umfragen.php?results=<?= (int)$res['id'] ?>&amp;export=1"><i class="ti ti-file-spreadsheet"></i> Als CSV herunterladen</a>
      <a class="btn secondary small" href="umfragen.php?edit=<?= (int)$res['id'] ?>"><i class="ti ti-edit"></i> Bearbeiten</a>
    </div>
  </div>
  <?php // Beteiligung über die Zeit – aus der Urne, die ohnehin nur Daten kennt.
        $verlauf = umfrage_verlauf((int)$res['id']);
        $verlaufBild = umfrage_verlauf_html($verlauf); ?>
  <?php if ($verlaufBild !== ''): ?>
    <div class="card">
      <div class="section-title" style="margin-top:0;font-size:1.02rem"><i class="ti ti-timeline"></i> Beteiligung über die Zeit</div>
      <?= $verlaufBild ?>
      <p class="small muted" style="margin:.3rem 0 0">Stimmen je Tag, vom ersten bis zum letzten Eingang. Zeiten speichert die Urne bewusst nicht.</p>
    </div>
  <?php endif; ?>
  <?php if ($zahlen['stimmen'] === 0): ?>
    <div class="card"><p class="empty"><i class="ti ti-mood-empty"></i> Noch keine Stimmen.</p></div>
  <?php else: $lfd = 0; foreach ($rows as $e): $q = $e['frage']; ?>
    <?php if ($e['typ'] === 'info'): ?>
      <div class="section-title" style="font-size:1.02rem"><i class="ti ti-section-sign"></i> <?= h($q['title']) ?></div>
      <?php continue; ?>
    <?php endif; $lfd++; ?>
    <div class="card">
      <div class="section-title" style="margin-top:0;font-size:1.02rem"><?= $lfd ?>. <?= h($q['title']) ?></div>
      <?php if ($e['typ'] === 'text'): ?>
        <?php if (!$e['texte']): ?><p class="empty small" style="margin:0">Keine Antworten.</p>
        <?php else: ?>
          <p class="small muted" style="margin:0 0 .4rem"><?= count($e['texte']) ?> Antworten<?= (int)$res['free_public'] === 1 ? '' : ' · öffentlich ausgeblendet' ?></p>
          <table class="list"><tbody>
            <?php foreach ($e['texte'] as $t): ?><tr><td><?= nl2br(h($t)) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      <?php elseif ($e['typ'] === 'scale'): ?>
        <?php if ($e['schnitt'] !== null): ?>
          <p class="small muted" style="margin:0 0 .4rem">Mittelwert <strong><?= h(number_format((float)$e['schnitt'], 2, ',', '')) ?></strong>
            · <?= (int)$e['abgegeben'] ?> Antworten</p>
        <?php endif; ?>
        <?= umfrage_skala_html($e['zeilen'], $e['schnitt'] !== null ? (float)$e['schnitt'] : null, (int)$q['scale_min']) ?>
      <?php else: ?>
        <?php if ($e['typ'] === 'multi'): ?>
          <p class="small muted" style="margin:0 0 .4rem">Mehrfachauswahl – Anteile beziehen sich auf die Stimmzettel.</p>
        <?php endif; ?>
        <?= umfrage_balken_html($e['zeilen']) ?>
        <?php if (!empty($e['sonstige'])): ?>
          <details class="small" style="margin-top:.5rem"><summary class="muted">„Sonstiges"-Antworten (<?= count($e['sonstige']) ?>)</summary>
            <table class="list"><tbody>
              <?php foreach ($e['sonstige'] as $t): ?><tr><td><?= h($t) ?></td></tr><?php endforeach; ?>
            </tbody></table>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>

<?php elseif ($edit): // ================= Bearbeiten =================
  $fragen = umfrage_questions((int)$edit['id']);
  $zahlen = umfrage_counts((int)$edit['id']);
  /* Der öffentliche Link hängt an der Basis-Adresse der UMFRAGEN-Einstellungen – und die kann
     leer oder veraltet sein, während die App ihre eigene längst kennt. Dann zeigt der Link auf
     die falsche Adresse und der Klick endet auf einem fremden 404 („die Umfrage gibt es
     nicht"). Deshalb: Leere Basis-Adresse heilt sich hier selbst aus der App, eine ABWEICHENDE
     wird laut gemeldet statt still einen kaputten Link anzuzeigen. */
  $appBasis = rtrim(base_url(), '/');
  if (trim(umfrage_setting_get('base_url', '')) === '' && $appBasis !== '') {
      umfrage_setting_set('base_url', $appBasis);
  }
  $link = umfrage_url($edit);
  $basisWeicht = $appBasis !== '' && rtrim(umfrage_setting_get('base_url', ''), '/') !== $appBasis;
  $qEdit  = ($qid = (int)($_GET['frage'] ?? 0)) ? umfrage_question_get($qid) : null;
  if ($qEdit && (int)$qEdit['poll_id'] !== (int)$edit['id']) $qEdit = null;
  // Nummer für die Live-Vorschau: die Stelle, an der die Frage im fertigen Bogen steht.
  // Zwischentexte tragen keine Nummer und zählen deshalb nicht mit (wie umfrage/index.php).
  // Ohne bearbeitete Frage läuft die Schleife durch und liefert die nächste freie Nummer.
  $vorschauNr = 1;
  foreach ($fragen as $qz) {
      if ($qEdit && (int)$qz['id'] === (int)$qEdit['id']) break;   // an der eigenen Stelle aufhören
      if ((string)$qz['type'] !== 'info') $vorschauNr++;
  } ?>

  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-adjustments"></i> <?= h($edit['title']) ?> <?= um_pill($edit) ?></div>
    <?php if ($link !== ''): ?>
      <p class="small" style="margin:0 0 .6rem"><i class="ti ti-link"></i> Öffentlicher Link:
        <a href="<?= h($link) ?>" target="_blank" rel="noopener"><code><?= h($link) ?></code></a></p>
      <?php if ($basisWeicht): ?>
        <form method="post" style="margin:0 0 .6rem">
          <?= csrf_field() ?><input type="hidden" name="action" value="settings_from_app">
          <p class="small warn-line" style="margin:0">
            <i class="ti ti-alert-triangle"></i> Die <strong>Basis-Adresse</strong> der Umfragen
            (<code><?= h(umfrage_setting_get('base_url', '')) ?></code>) weicht von der App ab
            (<code><?= h($appBasis) ?></code>) – dieser Link führt dann ins Leere.
            <button class="btn secondary small" type="submit">Aus der App übernehmen</button>
          </p>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="small warn-line" style="margin:0 0 .6rem"><i class="ti ti-alert-triangle"></i> Ohne <strong>Basis-Adresse</strong> in den Einstellungen unten kann kein Link gebaut und keine Mail verschickt werden.</p>
    <?php endif; ?>
    <?php /* Vorschau: der einzige Weg, einen ENTWURF zu sehen – die öffentliche Seite weist
             Entwürfe sonst ab, und Rollen kennt sie bewusst nicht. Deshalb ein geheimer Link. */
          $vLink = umfrage_preview_url($edit); if ($vLink !== ''): ?>
      <p class="small" style="margin:0 0 .6rem"><i class="ti ti-eye"></i> <strong>Vorschau-Link</strong> (geheim, funktioniert auch im Entwurf – hier kann man den Bogen gefahrlos durchklicken, abgeschickt wird nichts):<br>
        <a href="<?= h($vLink) ?>" target="_blank" rel="noopener"><code><?= h($vLink) ?></code></a>
        <form method="post" style="display:inline" data-confirm="Neuen Vorschau-Link erzeugen? Der bisherige funktioniert danach nicht mehr." data-confirm-ok="Neu erzeugen">
          <?= csrf_field() ?><input type="hidden" name="action" value="preview_new"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
          <button class="btn secondary small" type="submit"><i class="ti ti-refresh"></i> Neu</button>
        </form>
      </p>
    <?php endif; ?>
    <p class="small muted" style="margin:0"><?= (int)$zahlen['stimmen'] ?> gezählte Stimmen ·
      <?= (int)$zahlen['wartend'] ?> abgegeben, aber noch nicht bestätigt</p>
    <?php
      // Läuft die Warteschlange noch rechtzeitig leer? Wenn nicht, muss das VOR dem Fristende
      // auffallen – sonst gehen Stimmen verloren, ohne dass es jemand merkt.
      $wartendMail = umfrage_queue_len();
      $noetig  = umfrage_days_needed($wartendMail);
      $endeTs  = trim((string)$edit['ends_at']) !== '' ? strtotime((string)$edit['ends_at']) : 0;
      $tageBis = $endeTs ? (int)floor(($endeTs - time()) / 86400) : null;
      if ($wartendMail > 0):
        $eng = $tageBis !== null && $noetig > $tageBis;
    ?>
      <p class="small <?= $eng ? 'warn-line' : 'muted' ?>" style="margin:.5rem 0 0">
        <i class="ti ti-<?= $eng ? 'alert-triangle' : 'mail-forward' ?>"></i>
        <strong><?= (int)$wartendMail ?></strong> Bestätigungsmails warten auf den Versand.
        <?php if ($noetig === 0): ?>Sie gehen heute noch raus.
        <?php else: ?>Bei <?= (int)umfrage_mail_cap_day() ?> Mails am Tag dauert das noch <strong><?= (int)$noetig ?> Tag<?= $noetig > 1 ? 'e' : '' ?></strong>.<?php endif; ?>
        <?php if ($eng): ?>
          <strong>Die Umfrage endet vorher</strong> – diese Stimmen könnten verfallen. Setz das Tageskontingent hoch
          oder verlänger die Frist. Solange die Schlange zu lang ist, nimmt die Seite keine neuen Stimmen mehr an.
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <div class="btn-row" style="margin-top:.8rem">
      <?php foreach ([['open', 'ti-player-play', 'Öffnen'], ['closed', 'ti-lock', 'Schließen'], ['draft', 'ti-pencil', 'Zurück in den Entwurf']] as [$s, $ic, $lbl]): ?>
        <?php if ((string)$edit['status'] !== $s): ?>
          <form method="post" style="display:inline"<?= $s === 'closed' ? ' data-confirm="Umfrage schließen? Danach kann niemand mehr abstimmen und das Ergebnis wird öffentlich." data-confirm-ok="Schließen"' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="action" value="poll_status"><input type="hidden" name="poll_id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>">
            <button class="btn secondary small" type="submit"><i class="ti <?= $ic ?>"></i> <?= $lbl ?></button>
          </form>
        <?php endif; ?>
      <?php endforeach; ?>
      <a class="btn secondary small" href="umfragen.php?results=<?= (int)$edit['id'] ?>"><i class="ti ti-chart-bar"></i> Ergebnis</a>
    </div>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-edit"></i> Eckdaten</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="poll_save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <label for="title">Titel</label>
      <input type="text" name="title" id="title" value="<?= h($edit['title']) ?>" required>
      <label for="intro">Erläuterung (steht über dem Formular)</label>
      <textarea name="intro" id="intro" rows="4" placeholder="Worum geht es, wofür wird das Ergebnis gebraucht?"><?= h($edit['intro']) ?></textarea>

      <?php /* Englisch ist überall freiwillig und feldweise: Was leer bleibt, erscheint auf
               Deutsch. Erst wenn irgendwo etwas steht, bekommt die öffentliche Seite ihren
               Sprachumschalter. */ ?>
      <details class="collapse-card" style="margin-top:.6rem"<?= trim((string)($edit['title_en'] ?? '')) !== '' || trim((string)($edit['intro_en'] ?? '')) !== '' ? ' open' : '' ?>>
        <summary class="small" style="cursor:pointer"><i class="ti ti-language"></i> Englische Fassung <span class="muted">(optional)</span></summary>
        <div style="margin-top:.5rem">
          <label for="title_en">Title (English)</label>
          <input type="text" name="title_en" id="title_en" value="<?= h((string)($edit['title_en'] ?? '')) ?>">
          <label for="intro_en">Introduction (English)</label>
          <textarea name="intro_en" id="intro_en" rows="4"><?= h((string)($edit['intro_en'] ?? '')) ?></textarea>
          <p class="small muted" style="margin:.2rem 0 0">Leer gelassene Felder erscheinen weiterhin auf Deutsch. Die einzelnen Fragen übersetzt du unten je Frage.</p>
        </div>
      </details>
      <div class="field-row">
        <div><label for="starts_at">Beginn (optional)</label><input type="text" class="fp-datetime" name="starts_at" id="starts_at" value="<?= h(substr((string)$edit['starts_at'], 0, 16)) ?>" placeholder="Datum &amp; Uhrzeit wählen"></div>
        <div><label for="ends_at">Ende (optional)</label><input type="text" class="fp-datetime" name="ends_at" id="ends_at" value="<?= h(substr((string)$edit['ends_at'], 0, 16)) ?>" placeholder="Datum &amp; Uhrzeit wählen"></div>
      </div>
      <p class="small muted" style="margin:.2rem 0 .6rem">Ohne Ende läuft die Umfrage, bis du sie von Hand schließt. Das Ergebnis wird erst nach dem Ende öffentlich. Zum Leeren eines Feldes den Inhalt löschen.</p>
      <label for="domains">Erlaubte Mail-Endungen <span class="small muted">– leer = Standard aus den Einstellungen</span></label>
      <input type="text" name="domains" id="domains" value="<?= h($edit['domains']) ?>" placeholder="<?= h(implode(', ', umfrage_domains())) ?>">
      <?php if ((string)$edit['status'] === 'draft'): ?>
        <label for="slug" style="margin-top:.6rem">Link-Schlüssel</label>
        <input type="text" name="slug" id="slug" value="<?= h($edit['slug']) ?>">
        <p class="small muted" style="margin:.2rem 0 0">Sobald die Umfrage geöffnet ist, bleibt der Schlüssel unverändert – er steckt dann schon in verteilten Links.</p>
      <?php endif; ?>
      <label class="wl-sw"><input type="checkbox" name="free_public" value="1" <?= (int)$edit['free_public'] === 1 ? 'checked' : '' ?>>
        <span class="wl-sw-track" aria-hidden="true"></span>
        <span class="wl-sw-txt"><strong>Freitext-Antworten auch öffentlich zeigen</strong>
          <span>Vorsicht: In Freitexten stehen manchmal Namen. Intern siehst du sie in jedem Fall.</span></span></label>
      <?php if ($umAdmin): ?>
        <label style="margin-top:.6rem">Wer betreut diese Umfrage mit?</label>
        <input type="hidden" name="referate_da" value="1">
        <?= zust_picker_html('umfrage:' . (int)$edit['id']) ?>
        <p class="small muted" style="margin:.3rem 0 0">Die Eingetragenen bekommen die Umfrage
          als eigenen Punkt in ihrer Titelleiste und können sie hier komplett betreuen –
          Fragen, Öffnen/Schließen, Ergebnis. Neue Umfragen und die Einstellungen bleiben bei Vorsitz/Admin.</p>
      <?php else: /* Wer die Umfrage nur betreut, sieht die Zuständigkeit, ändert sie aber nicht. */ ?>
        <?php $uZust = zust_text('umfrage:' . (int)$edit['id']); ?>
        <p class="small muted" style="margin:.6rem 0 0"><i class="ti ti-users"></i> Zuständig: <?= h($uZust) ?> <span class="muted">(vergibt der Vorsitz)</span></p>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button></div>
    </form>
  </div>

  <div class="section-title" id="fragen"><i class="ti ti-help-circle"></i> Fragen <span class="count"><?= count($fragen) ?></span></div>
  <?php if ($fragen): ?>
    <div class="card" style="padding:.4rem .2rem">
      <table class="list">
        <thead><tr><th>Frage</th><th>Art</th><th>Antworten</th><th></th></tr></thead>
        <tbody>
        <?php $lfd = 0; foreach ($fragen as $idx => $q): $istInfo = (string)$q['type'] === 'info';
              if (!$istInfo) $lfd++; ?>
          <tr<?= $istInfo ? ' class="um-zeile-info"' : '' ?>>
            <td>
              <?php if ($istInfo): ?><i class="ti ti-section-sign muted"></i>
              <?php else: ?><span class="muted small"><?= $lfd ?>.</span><?php endif; ?>
              <a href="umfragen.php?edit=<?= (int)$edit['id'] ?>&amp;frage=<?= (int)$q['id'] ?>#frageform"><?= h($q['title']) ?></a>
              <?php if (!$istInfo && (int)$q['required'] !== 1): ?> <span class="pill pill-info" style="font-size:.7rem">freiwillig</span><?php endif; ?>
              <?php if ((int)($q['allow_other'] ?? 0) === 1): ?> <span class="pill pill-info" style="font-size:.7rem">+ Sonstiges</span><?php endif; ?>
            </td>
            <td class="muted small"><?= h(umfrage_question_types()[(string)$q['type']] ?? $q['type']) ?></td>
            <td class="muted small">
              <?php if (in_array((string)$q['type'], ['single', 'multi'], true)):
                  // Die Möglichkeiten gleich zeigen statt nur zu zählen – dafür ist die Spalte da.
                  $labels = array_map(fn($o) => (string)$o['label'], $q['options']);
                  $kurz = implode(' · ', array_slice($labels, 0, 4));
                  if (count($labels) > 4) $kurz .= ' · … (' . count($labels) . ')'; ?>
                <?= h($kurz) ?>
              <?php elseif ((string)$q['type'] === 'scale'): ?>
                <?= (int)$q['scale_min'] ?>–<?= (int)$q['scale_max'] ?>
              <?php else: ?>–<?php endif; ?>
            </td>
            <td style="text-align:right">
              <div class="btn-row" style="justify-content:flex-end">
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="q_move"><input type="hidden" name="poll_id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="dir" value="up">
                  <button class="btn secondary small" type="submit" title="Nach oben"<?= $idx === 0 ? ' disabled' : '' ?>><i class="ti ti-chevron-up"></i></button></form>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="q_move"><input type="hidden" name="poll_id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="dir" value="down">
                  <button class="btn secondary small" type="submit" title="Nach unten"<?= $idx === count($fragen) - 1 ? ' disabled' : '' ?>><i class="ti ti-chevron-down"></i></button></form>
                <a class="btn secondary small" href="umfragen.php?edit=<?= (int)$edit['id'] ?>&amp;frage=<?= (int)$q['id'] ?>#frageform">Bearbeiten</a>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="q_copy"><input type="hidden" name="poll_id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                  <button class="btn secondary small" type="submit" title="Verdoppeln"><i class="ti ti-copy"></i></button></form>
                <form method="post" data-confirm="Diese Frage löschen? Bereits abgegebene Antworten darauf verschwinden mit." data-confirm-danger data-confirm-ok="Löschen">
                  <?= csrf_field() ?><input type="hidden" name="action" value="q_delete"><input type="hidden" name="poll_id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                  <button class="btn danger small" type="submit">Löschen</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="card"><p class="empty"><i class="ti ti-help-circle"></i> Noch keine Frage – leg unten die erste an.</p></div>
  <?php endif; ?>

  <?php /* Der Fragen-Editor: Die Art wählt man als KACHEL mit Symbol statt im Aufklappmenü,
           danach zeigt das Formular NUR die Felder, die zu dieser Art gehören – Skala-Felder,
           Antwortmöglichkeiten und Max-Antworten stehen nie gleichzeitig da. Dazu Skala-
           Schnellwahlen, das „Sonstiges"-Feld und eine LIVE-VORSCHAU, die die Frage genau so
           zeigt, wie Teilnehmende sie sehen werden. */ ?>
  <div class="card" id="frageform">
    <div class="section-title" style="margin-top:0"><i class="ti <?= $qEdit ? 'ti-edit' : 'ti-plus' ?>"></i> <?= $qEdit ? 'Frage bearbeiten' : 'Neue Frage' ?></div>
    <?php if ($zahlen['stimmen'] > 0): ?>
      <div class="flash flash-info">Es liegen bereits <strong><?= (int)$zahlen['stimmen'] ?> Stimmen</strong> vor. Antwortmöglichkeiten mit <strong>gleicher Beschriftung</strong> behalten ihre Stimmen; wer eine Beschriftung ändert, legt damit eine neue Möglichkeit an und die alten Stimmen fallen weg.</div>
    <?php endif; ?>
    <form method="post" id="qform" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="q_save"><input type="hidden" name="poll_id" value="<?= (int)$edit['id'] ?>">
      <?php if ($qEdit): ?><input type="hidden" name="id" value="<?= (int)$qEdit['id'] ?>"><?php endif; ?>

      <label>Art</label>
      <div class="wahlkarten">
        <?php $typInfo = [
            'single' => ['ti-circle-dot',    'Einfachauswahl', 'eine Antwort'],
            'multi'  => ['ti-checkbox',      'Mehrfachauswahl', 'mehrere Antworten'],
            'scale'  => ['ti-adjustments-horizontal', 'Skala', 'von … bis …'],
            'text'   => ['ti-align-left',    'Freitext', 'eigene Worte'],
            'info'   => ['ti-section-sign',  'Zwischentext', 'gliedert, fragt nichts'],
        ]; $typAktiv = (string)($qEdit['type'] ?? 'single'); ?>
        <?php foreach ($typInfo as $k => [$ic, $name, $kurz]): ?>
          <label class="wahlkarte">
            <input type="radio" name="type" value="<?= h($k) ?>" <?= $typAktiv === $k ? 'checked' : '' ?>>
            <i class="ti <?= h($ic) ?>"></i><strong><?= h($name) ?></strong><span><?= h($kurz) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <label for="qtitle" data-frage="Frage" data-info="Überschrift">Frage</label>
      <input type="text" name="title" id="qtitle" value="<?= h((string)($qEdit['title'] ?? '')) ?>" required placeholder="z. B. Wie zufrieden bist du mit den Öffnungszeiten?">
      <label for="qhelp" data-frage="Erläuterung (optional)" data-info="Text unter der Überschrift (optional)">Erläuterung (optional)</label>
      <textarea name="help" id="qhelp" rows="2"><?= h((string)($qEdit['help'] ?? '')) ?></textarea>

      <div data-teil="wahl">
        <label for="qopts">Antwortmöglichkeiten <span class="small muted">– eine je Zeile</span></label>
        <textarea name="options" id="qopts" rows="5" placeholder="Ja&#10;Nein&#10;Weiß nicht"><?= h(implode("\n", array_map(fn($o) => (string)$o['label'], (array)($qEdit['options'] ?? [])))) ?></textarea>
        <label class="wl-sw slim"><input type="checkbox" name="allow_other" id="qother" value="1" <?= (int)($qEdit['allow_other'] ?? 0) === 1 ? 'checked' : '' ?>>
          <span class="wl-sw-track" aria-hidden="true"></span>
          <span class="wl-sw-txt"><strong>„Sonstiges: …"-Feld anbieten</strong>
            <span>Teilnehmende können eine eigene Antwort eintippen; sie erscheint im Ergebnis als Liste.</span></span></label>
        <div data-teil="max" style="max-width:220px">
          <label for="qmax">Höchstens wählbar <span class="small muted">(0 = beliebig)</span></label>
          <input type="number" name="max_choices" id="qmax" min="0" value="<?= (int)($qEdit['max_choices'] ?? 0) ?>">
        </div>
      </div>

      <div data-teil="skala">
        <label>Schnellwahl</label>
        <div class="btn-row" style="margin:0 0 .5rem">
          <?php foreach ([['1–5 Zustimmung', 1, 5, 'stimme gar nicht zu', 'stimme voll zu'],
                          ['1–10', 1, 10, '', ''], ['0–10', 0, 10, 'gar nicht', 'voll und ganz'],
                          ['Schulnoten 1–6', 1, 6, 'sehr gut', 'ungenügend']] as [$pl, $pmin, $pmax, $plo, $phi]): ?>
            <button class="btn secondary small um-preset" type="button" data-min="<?= $pmin ?>" data-max="<?= $pmax ?>"
                    data-lo="<?= h($plo) ?>" data-hi="<?= h($phi) ?>"><?= h($pl) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="field-row">
          <div style="flex:0 1 110px"><label for="smin">Skala von</label><input type="number" name="scale_min" id="smin" value="<?= (int)($qEdit['scale_min'] ?? 1) ?>"></div>
          <div style="flex:0 1 110px"><label for="smax">bis</label><input type="number" name="scale_max" id="smax" value="<?= (int)($qEdit['scale_max'] ?? 5) ?>"></div>
          <div><label for="slo">Beschriftung unten</label><input type="text" name="scale_lo" id="slo" value="<?= h((string)($qEdit['scale_lo'] ?? '')) ?>" placeholder="stimme gar nicht zu"></div>
          <div><label for="shi">Beschriftung oben</label><input type="text" name="scale_hi" id="shi" value="<?= h((string)($qEdit['scale_hi'] ?? '')) ?>" placeholder="stimme voll zu"></div>
        </div>
      </div>

      <div data-teil="pflicht">
        <label class="wl-sw slim"><input type="checkbox" name="optional" id="qopt" value="1" <?= isset($qEdit) && (int)$qEdit['required'] !== 1 ? 'checked' : '' ?>>
          <span class="wl-sw-track" aria-hidden="true"></span>
          <span class="wl-sw-txt"><strong>Diese Frage darf übersprungen werden</strong></span></label>
      </div>

      <?php /* Bild zur Frage: liegt in umfrage/bilder/ und wird beim Hochladen auf 1200 px
               gerechnet – das wirft auch die EXIF-Daten samt Ortsangabe weg. */
            $qBild = trim((string)($qEdit['image'] ?? '')); ?>
      <details class="collapse-card" style="margin-top:.6rem"<?= $qBild !== '' ? ' open' : '' ?>>
        <summary class="small" style="cursor:pointer"><i class="ti ti-photo"></i> Bild zur Frage <span class="muted">(optional)</span></summary>
        <div style="margin-top:.5rem">
          <?php if ($qBild !== ''): ?>
            <p style="margin:0 0 .4rem"><img src="<?= h('../umfrage/' . umfrage_bild_url($qBild)) ?>" alt="" style="max-width:260px;border-radius:10px;border:1px solid var(--line)"></p>
            <label class="wl-sw slim"><input type="checkbox" name="image_delete" value="1">
              <span class="wl-sw-track" aria-hidden="true"></span>
              <span class="wl-sw-txt"><strong>Bild entfernen</strong></span></label>
          <?php endif; ?>
          <label for="qimg"><?= $qBild !== '' ? 'Anderes Bild hochladen' : 'Bild hochladen' ?> <span class="small muted">– JPG, PNG oder WEBP, höchstens 12 MB</span></label>
          <input type="file" name="image" id="qimg" accept="image/jpeg,image/png,image/webp">
          <label for="qimgalt" style="margin-top:.4rem">Bildbeschreibung <span class="small muted">– für Menschen, die das Bild nicht sehen können</span></label>
          <input type="text" name="image_alt" id="qimgalt" maxlength="200" value="<?= h((string)($qEdit['image_alt'] ?? '')) ?>" placeholder="z. B. Grundriss des Aufenthaltsraums">
        </div>
      </details>

      <?php /* Englisch je Frage – dieselbe Regel wie oben: leer = bleibt deutsch. */ ?>
      <details class="collapse-card" style="margin-top:.6rem"<?= trim((string)($qEdit['title_en'] ?? '')) !== '' ? ' open' : '' ?>>
        <summary class="small" style="cursor:pointer"><i class="ti ti-language"></i> Englische Fassung <span class="muted">(optional)</span></summary>
        <div style="margin-top:.5rem">
          <label for="qtitle_en" data-frage-en="Question (English)" data-info-en="Heading (English)">Question (English)</label>
          <input type="text" name="title_en" id="qtitle_en" value="<?= h((string)($qEdit['title_en'] ?? '')) ?>">
          <label for="qhelp_en">Explanation (English)</label>
          <textarea name="help_en" id="qhelp_en" rows="2"><?= h((string)($qEdit['help_en'] ?? '')) ?></textarea>
          <div data-teil="wahl">
            <label for="qopts_en">Answer options (English) <span class="small muted">– Zeile für Zeile in derselben Reihenfolge wie oben</span></label>
            <textarea name="options_en" id="qopts_en" rows="5"><?= h(implode("\n", array_map(fn($o) => (string)($o['label_en'] ?? ''), (array)($qEdit['options'] ?? [])))) ?></textarea>
          </div>
          <div data-teil="skala" class="field-row">
            <div><label for="slo_en">Label (low end)</label><input type="text" name="scale_lo_en" id="slo_en" value="<?= h((string)($qEdit['scale_lo_en'] ?? '')) ?>"></div>
            <div><label for="shi_en">Label (high end)</label><input type="text" name="scale_hi_en" id="shi_en" value="<?= h((string)($qEdit['scale_hi_en'] ?? '')) ?>"></div>
          </div>
        </div>
      </details>

      <?php /* Die Vorschau zeigt die Frage, wie sie auf der öffentlichen Seite aussehen wird –
               nachgebaut vom Skript unten, bei jeder Eingabe frisch. */ ?>
      <div class="um-vorschau">
        <div class="small muted" style="margin-bottom:.3rem"><i class="ti ti-eye"></i> So sehen es die Teilnehmenden:</div>
        <div id="qpreview" class="um-vorschau-blatt"></div>
      </div>

      <div class="btn-row" style="margin-top:.9rem">
        <button class="btn" type="submit"><i class="ti <?= $qEdit ? 'ti-device-floppy' : 'ti-plus' ?>"></i> <?= $qEdit ? 'Speichern' : 'Frage hinzufügen' ?></button>
        <?php if ($qEdit): ?><a class="btn secondary" href="umfragen.php?edit=<?= (int)$edit['id'] ?>#fragen">Fertig</a><?php endif; ?>
      </div>
    </form>
  </div>

  <script>
  (function () {
    'use strict';
    var form = document.getElementById('qform');
    if (!form) return;
    var FRAGE_NR = <?= (int)$vorschauNr ?>;   // Stelle im fertigen Bogen, aus PHP gerechnet
    var teile = { wahl: ['single', 'multi'], max: ['multi'], skala: ['scale'], pflicht: ['single', 'multi', 'scale', 'text'] };

    function typ() {
      var t = form.querySelector('input[name="type"]:checked');
      return t ? t.value : 'single';
    }

    /* Nur die Felder der gewählten Art zeigen; Beschriftungen wechseln beim Zwischentext mit. */
    function zeige() {
      var t = typ();
      Object.keys(teile).forEach(function (name) {
        form.querySelectorAll('[data-teil="' + name + '"]').forEach(function (el) {
          el.hidden = teile[name].indexOf(t) === -1;
        });
      });
      form.querySelectorAll('label[data-frage]').forEach(function (l) {
        l.textContent = t === 'info' ? l.dataset.info : l.dataset.frage;
      });
      male();
    }

    /* Die Live-Vorschau: derselbe Aufbau wie auf der öffentlichen Seite, nur ohne Funktion. */
    function male() {
      var ziel = document.getElementById('qpreview');
      ziel.textContent = '';
      var t = typ();
      var titel = document.getElementById('qtitle').value.trim() || (t === 'info' ? 'Überschrift …' : 'Deine Frage …');
      var h2 = document.createElement(t === 'info' ? 'h3' : 'h4');
      if (t !== 'info') {
        var nr = document.createElement('span'); nr.className = 'um-p-nr'; nr.textContent = String(FRAGE_NR);
        h2.appendChild(nr);
      }
      h2.appendChild(document.createTextNode(' ' + titel));
      if (t !== 'info' && document.getElementById('qopt').checked) {
        var f = document.createElement('em'); f.className = 'um-p-frei'; f.textContent = 'freiwillig';
        h2.appendChild(f);
      }
      ziel.appendChild(h2);
      var help = document.getElementById('qhelp').value.trim();
      if (help) { var p = document.createElement('p'); p.className = 'small muted'; p.textContent = help; ziel.appendChild(p); }

      if (t === 'single' || t === 'multi') {
        var zeilen = document.getElementById('qopts').value.split('\n').map(function (z) { return z.trim(); }).filter(Boolean);
        if (!zeilen.length) zeilen = ['Erste Möglichkeit', 'Zweite Möglichkeit'];
        var box = document.createElement('div'); box.className = 'um-p-wahl';
        zeilen.forEach(function (z) {
          var l = document.createElement('label');
          var i = document.createElement('input'); i.type = t === 'single' ? 'radio' : 'checkbox'; i.disabled = true;
          l.appendChild(i); l.appendChild(document.createTextNode(' ' + z));
          box.appendChild(l);
        });
        if (document.getElementById('qother').checked) {
          var l2 = document.createElement('label');
          var i2 = document.createElement('input'); i2.type = t === 'single' ? 'radio' : 'checkbox'; i2.disabled = true;
          l2.appendChild(i2); l2.appendChild(document.createTextNode(' Sonstiges: '));
          var tx = document.createElement('input'); tx.type = 'text'; tx.disabled = true; tx.placeholder = 'eigene Antwort …'; tx.className = 'um-p-sonst';
          l2.appendChild(tx);
          box.appendChild(l2);
        }
        ziel.appendChild(box);
        var max = parseInt(document.getElementById('qmax').value, 10) || 0;
        if (t === 'multi' && max > 0) {
          var m = document.createElement('p'); m.className = 'small muted'; m.textContent = 'Höchstens ' + max + ' Antworten.';
          ziel.appendChild(m);
        }
      } else if (t === 'scale') {
        var von = parseInt(document.getElementById('smin').value, 10) || 1;
        var bis = parseInt(document.getElementById('smax').value, 10) || 5;
        if (bis <= von) bis = von + 4;
        if (bis - von > 10) bis = von + 10;
        var reihe = document.createElement('div'); reihe.className = 'um-p-skala';
        var lo = document.getElementById('slo').value.trim();
        if (lo) { var e1 = document.createElement('em'); e1.textContent = lo; reihe.appendChild(e1); }
        for (var v = von; v <= bis; v++) {
          var s = document.createElement('span'); s.textContent = v; reihe.appendChild(s);
        }
        var hi = document.getElementById('shi').value.trim();
        if (hi) { var e2 = document.createElement('em'); e2.textContent = hi; reihe.appendChild(e2); }
        ziel.appendChild(reihe);
      } else if (t === 'text') {
        var ta = document.createElement('textarea'); ta.rows = 3; ta.disabled = true; ta.placeholder = 'Deine Antwort …';
        ziel.appendChild(ta);
      }
    }

    form.addEventListener('input', male);
    form.querySelectorAll('input[name="type"]').forEach(function (r) { r.addEventListener('change', zeige); });
    form.querySelectorAll('.um-preset').forEach(function (b) {
      b.addEventListener('click', function () {
        document.getElementById('smin').value = b.dataset.min;
        document.getElementById('smax').value = b.dataset.max;
        document.getElementById('slo').value = b.dataset.lo;
        document.getElementById('shi').value = b.dataset.hi;
        male();
      });
    });
    zeige();
  })();
  </script>

  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-trash"></i> Umfrage löschen</div>
    <p class="small muted" style="margin-top:0">Entfernt Fragen, Verzeichnis und <strong>alle abgegebenen Stimmen</strong>. Nicht rückgängig zu machen.</p>
    <form method="post" data-confirm="Diese Umfrage mit allen Stimmen endgültig löschen?" data-confirm-danger data-confirm-ok="Endgültig löschen">
      <?= csrf_field() ?><input type="hidden" name="action" value="poll_delete"><input type="hidden" name="poll_id" value="<?= (int)$edit['id'] ?>">
      <button class="btn danger" type="submit">Umfrage löschen</button>
    </form>
  </div>

<?php else: // ================= Übersicht + Einstellungen ================= ?>
  <div class="flash flash-info">
    Umfragen laufen <strong>außerhalb der App</strong>: Wer abstimmen will, weist über einen Einmal-Link an die
    Hochschul-Adresse nach, dass er/sie an dieses Postfach kommt. <strong>Stimme und Adresse werden getrennt
    gespeichert</strong> – auch ihr könnt nicht sehen, wer wie abgestimmt hat. Für <strong>Wahlen</strong> ist das
    Verfahren ausdrücklich nicht gedacht.
  </div>

  <?php
  // Reiter des Basis-Blicks (Ergebnis-/Bearbeiten-Ansichten haben ihre eigene, kurze Struktur).
  // Referats-Zugänge sehen nur ihre Umfragen – ohne Einstellungen-Reiter und ohne Anlegen.
  $uTabs = ['umfragen' => ['label' => 'Umfragen', 'icon' => 'ti-chart-donut']];
  if ($umAdmin) $uTabs['einstellungen'] = ['label' => 'Einstellungen', 'icon' => 'ti-settings'];
  $uTab = (string)($_GET['t'] ?? '');
  if (!isset($uTabs[$uTab])) $uTab = 'umfragen';
  ?>
  <?php if ($umAdmin): ?>
  <nav class="wl-adm-tabs" aria-label="Bereiche">
    <?php foreach ($uTabs as $tk => $td): ?>
      <a<?= $tk === $uTab ? ' class="on" aria-current="page"' : '' ?> href="umfragen.php?t=<?= h($tk) ?>">
        <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if ($uTab === 'umfragen'): ?>
  <?php if ($umAdmin): // Anlegen bleibt bei Vorsitz/Admin – Referats-Zugänge betreuen Bestehendes ?>
  <div class="card">
    <div class="section-title" style="margin-top:0"><i class="ti ti-plus"></i> Neue Umfrage</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="poll_save"><input type="hidden" name="id" value="0">
      <label for="ntitle">Titel</label>
      <input type="text" name="title" id="ntitle" required placeholder="z. B. Öffnungszeiten des AStA-Büros">
      <label for="nintro">Erläuterung (optional)</label>
      <textarea name="intro" id="nintro" rows="3" placeholder="Worum geht es, wofür wird das Ergebnis gebraucht?"></textarea>
      <div class="field-row">
        <div><label for="nstart">Beginn (optional)</label><input type="text" class="fp-datetime" name="starts_at" id="nstart" placeholder="Datum &amp; Uhrzeit wählen"></div>
        <div><label for="nend">Ende (optional)</label><input type="text" class="fp-datetime" name="ends_at" id="nend" placeholder="Datum &amp; Uhrzeit wählen"></div>
      </div>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-plus"></i> Anlegen</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php // Referats-Zugänge sehen NUR ihre zuständigen Umfragen ($umMeine), Vorsitz/Admin alle.
        $alle = $umMeine === null ? umfrage_all() : $umMeine; ?>
  <div class="section-title"><i class="ti ti-list-details"></i> <?= $umMeine === null ? 'Vorhandene Umfragen' : 'Deine Umfragen' ?> <span class="count"><?= count($alle) ?></span></div>
  <?php if (!$alle): ?>
    <div class="card"><p class="empty"><i class="ti ti-chart-donut"></i> Noch keine Umfrage angelegt.</p></div>
  <?php else: ?>
    <div class="card" style="padding:.4rem .2rem">
      <table class="list">
        <thead><tr><th>Titel</th><th>Status</th><th>Gezählt</th><th>Frist</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($alle as $p): $z = umfrage_counts((int)$p['id']); ?>
          <tr>
            <td><a href="umfragen.php?edit=<?= (int)$p['id'] ?>"><?= h($p['title']) ?></a></td>
            <td><?= um_pill($p) ?></td>
            <td class="muted small"><?= (int)$z['stimmen'] ?><?= (int)$z['wartend'] > 0 ? ' <span class="muted">(+' . (int)$z['wartend'] . ' offen)</span>' : '' ?></td>
            <td class="muted small"><?= trim((string)$p['ends_at']) !== '' ? h(fmt_date(substr((string)$p['ends_at'], 0, 10))) : '–' ?></td>
            <td style="text-align:right"><a class="btn secondary small" href="umfragen.php?results=<?= (int)$p['id'] ?>">Ergebnis</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php endif; ?>

  <?php if ($uTab === 'einstellungen'): ?>
  <div class="section-title" id="einstellungen"><i class="ti ti-settings"></i> Einstellungen</div>
  <div class="card">
    <p class="small muted" style="margin-top:0">Diese Angaben gelten für alle Umfragen. Ohne <strong>Basis-Adresse</strong> und <strong>Absender</strong> kann kein Link verschickt werden.</p>
    <div class="btn-row" style="margin-bottom:.8rem">
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="settings_from_app">
        <button class="btn secondary small" type="submit"><i class="ti ti-copy"></i> Aus den App-Einstellungen übernehmen</button></form>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="test_mail">
        <button class="btn secondary small" type="submit"><i class="ti ti-mail-fast"></i> Testmail an mich</button></form>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings_save">
      <div class="field-row">
        <div><label for="base_url">Basis-Adresse</label><input type="text" name="base_url" id="base_url" value="<?= h(umfrage_setting_get('base_url', '')) ?>" placeholder="<?= h(org_url() !== '' ? org_url() . '/planer' : 'https://beispiel.de/planer') ?>"></div>
        <div><label for="domains_s">Erlaubte Mail-Endungen</label><input type="text" name="domains" id="domains_s" value="<?= h(umfrage_setting_get('domains', UMFRAGE_DOMAINS_DEFAULT)) ?>" placeholder="<?= h(UMFRAGE_DOMAINS_DEFAULT) ?>"></div>
      </div>
      <p class="small muted" style="margin:.2rem 0 .6rem">Endungen mit Komma trennen. Unterdomains sind automatisch mit dabei – <code>rptu.de</code> deckt also auch <code>stud.rptu.de</code> ab.</p>
      <div class="field-row">
        <div><label for="from_email">Antwort-Adresse</label><input type="email" name="from_email" id="from_email" value="<?= h(umfrage_setting_get('from_email', '')) ?>"><p class="small muted" style="margin:.25rem 0 0">Verschickt wird immer aus <strong><?= h(mail_pool_absender()) ?></strong> – das ist das einzige Postfach, das der Hoster dafür freigegeben hat. Was hier steht, ist die Adresse, an die Antworten gehen.</p></div>
        <div><label for="from_name">Absender-Name</label><input type="text" name="from_name" id="from_name" value="<?= h(umfrage_setting_get('from_name', umfrage_traeger())) ?>"></div>
      </div>
      <div class="field-row">
        <div style="flex:0 1 190px"><label for="cap_day">Eigener Deckel je 24 h</label><input type="number" name="cap_day" id="cap_day" min="0" value="<?= (int)umfrage_mail_cap_day() ?>"><?= mail_hint('cap_day', umfrage_mail_cap_day()) ?></div>
        <div style="flex:0 1 190px"><label for="cap_hour">Bremse: Mails je Stunde</label><input type="number" name="cap_hour" id="cap_hour" min="0" value="<?= (int)umfrage_mail_cap_hour() ?>"><?= mail_hint('cap_hour', umfrage_mail_cap_hour()) ?></div>
      </div>
      <?php mail_budget_card(); ?>
      <p class="small muted" style="margin:.4rem 0 .8rem"><strong>0</strong> heißt bei beiden Feldern: keine zusätzliche Bremse –
        Umfragen nehmen sich, was der gemeinsame Topf gerade hergibt, und verschicken sofort. Seit der Hoster 3000 Mails
        je 24 Stunden zulässt, ist das der Normalfall; die Stundenbremse braucht es nur, wenn ein Spam-Filter Schübe
        nicht mag.
        Warten gerade in der Umfrage-Warteschlange: <strong><?= (int)umfrage_queue_len() ?></strong>.</p>
      <div class="field-row">
        <div><label for="imprint_url">Impressum (Adresse)</label><input type="text" name="imprint_url" id="imprint_url" value="<?= h(umfrage_setting_get('imprint_url', '')) ?>"></div>
        <div><label for="privacy_url">Datenschutz (Adresse)</label><input type="text" name="privacy_url" id="privacy_url" value="<?= h(umfrage_setting_get('privacy_url', '')) ?>"></div>
      </div>
      <div class="section-title" style="font-size:1rem"><i class="ti ti-text-caption"></i> Texte</div>
      <?php foreach (umfrage_texts() as $k => $meta): ?>
        <label for="t_<?= h($k) ?>"><?= h($meta['label']) ?></label>
        <textarea name="text_<?= h($k) ?>" id="t_<?= h($k) ?>" rows="<?= $k === 'mail_body' ? 8 : 3 ?>"><?= h(umfrage_text($k)) ?></textarea>
        <details<?= trim(umfrage_setting_get('text_' . $k . '_en', '')) !== '' ? ' open' : '' ?>>
          <summary class="small muted" style="cursor:pointer"><i class="ti ti-language"></i> English</summary>
          <textarea name="text_<?= h($k) ?>_en" id="t_<?= h($k) ?>_en" rows="<?= $k === 'mail_body' ? 8 : 3 ?>" placeholder="leer = auf Deutsch"><?= h(umfrage_setting_get('text_' . $k . '_en', '')) ?></textarea>
        </details>
      <?php endforeach; ?>
      <p class="small muted" style="margin:.2rem 0 0">In der Bestätigungsmail stehen <code>{{TITEL}}</code>, <code>{{LINK}}</code> und <code>{{FRIST_SATZ}}</code> zur Verfügung.</p>
      <div class="btn-row" style="margin-top:.9rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Einstellungen speichern</button></div>
    </form>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php
page_footer();
