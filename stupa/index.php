<?php
/**
 * Auszahlungsaufforderungen des StuPa-Präsidiums – die einzige Seite dieses Bereichs.
 *
 * Eigener Zugang, eigene Session, KEIN Eintrag in der Mitgliedertabelle (Begründung in
 * stupa-lib.php). Die Aufforderungen landen in derselben Datenbank, damit Finanzen sie
 * in der App sieht – getrennt ist der Zugang, nicht die Ablage.
 */
require __DIR__ . '/stupa-lib.php';
$user = stupa_require_login();
$me = null;                                  // hier gibt es kein Mitglied – bewusst

/** Hochgeladene belege[]-Dateien an eine Aufforderung (oder Nachricht) hängen → [Anzahl, Fehler]. */
function stupa_collect_uploads(int $claimId, ?int $commentId = null): array
{
    $n = 0; $errs = [];
    if (!empty($_FILES['belege']) && is_array($_FILES['belege']['name'])) {
        foreach (array_keys($_FILES['belege']['name']) as $k) {
            $f = ['name' => $_FILES['belege']['name'][$k], 'type' => $_FILES['belege']['type'][$k],
                  'tmp_name' => $_FILES['belege']['tmp_name'][$k], 'error' => $_FILES['belege']['error'][$k],
                  'size' => $_FILES['belege']['size'][$k]];
            $e = stupa_file_add($claimId, $f, $commentId);
            if ($e !== null) $errs[] = $f['name'] . ': ' . $e;
            elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $n++;
        }
    }
    return [$n, $errs];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'stupa_add') {
        // Ein Blatt kann mehrere Posten tragen – genau die landen dann zusammen auf einem
        // Formular. Wer nur einen einreicht, bekommt schlicht ein Blatt mit einer Zeile.
        $feld = fn(string $n, int $i) => trim((string)($_POST[$n][$i] ?? ''));
        $anzahl = max(1, count((array)($_POST['applicant'] ?? [1])));
        $posten = []; $err = ''; $leer = 0;
        for ($i = 0; $i < $anzahl && $err === ''; $i++) {
            $roh = ['applicant' => $feld('applicant', $i), 'institution' => $feld('institution', $i),
                    'purpose' => $feld('purpose', $i), 'account_holder' => $feld('account_holder', $i),
                    'fund' => $feld('fund', $i), 'iban' => $feld('iban', $i)];
            $betragText = $feld('amount', $i);
            // Komplett leere Zeilen einfach überspringen (jemand hat einen Posten geöffnet und doch nicht gebraucht)
            if ($betragText === '' && implode('', $roh) === '') { $leer++; continue; }
            $amount = euro_parse($betragText);
            $wo = $anzahl > 1 ? ' (Posten ' . ($i + 1) . ')' : '';
            if ($roh['applicant'] === '')            $err = 'Bitte die:den Antragssteller:in angeben' . $wo . '.';
            elseif ($roh['purpose'] === '')          $err = 'Bitte den Verwendungszweck angeben' . $wo . '.';
            elseif ($amount === null || $amount <= 0) $err = 'Bitte einen gültigen Betrag angeben (z. B. 250,00)' . $wo . '.';
            elseif ($roh['account_holder'] === '')   $err = 'Bitte die:den Kontoinhaber:in angeben' . $wo . '.';
            elseif ($roh['iban'] === '')             $err = 'Bitte die IBAN angeben – ohne sie kann nichts überwiesen werden' . $wo . '.';
            else { $roh['amount_cents'] = (int)$amount; $posten[] = $roh; }
        }
        if ($err === '' && !$posten) $err = 'Bitte mindestens einen Posten ausfüllen.';
        if ($err !== '') {
            flash($err, 'error');
        } elseif ($neu = stupa_claim_add((int)$user['id'], $posten)) {
            [$nF, $fErr] = stupa_collect_uploads($neu);
            stupa_claim_set_notify($neu, !empty($_POST['notify_done']));
            stupa_notify_finance($neu);
            $n = count($posten);
            flash(($n > 1 ? $n . ' Posten übermittelt (zusammen ' : 'Auszahlungsaufforderung übermittelt (')
                . euro(stupa_claim_total($neu)) . ($nF ? ', ' . $nF . ' Beleg(e)' : '')
                . '). Die Finanzreferentin sieht sie jetzt in der App.', 'success');
            if ($fErr) flash('Nicht alle Belege konnten angehängt werden: ' . implode(' · ', $fErr), 'error');
        } else {
            flash('Die Aufforderung konnte nicht gespeichert werden.', 'error');
        }
        redirect('index.php');
    }

    if (($_POST['action'] ?? '') === 'stupa_protokoll') {
        $nr  = (int)($_POST['sitzung_nr'] ?? 0);
        $art = ($_POST['sitzung_art'] ?? '') === 'ao' ? 'außerordentliche' : 'ordentliche';
        $amount = euro_parse(trim((string)($_POST['amount'] ?? '')));
        $d = [
            'applicant'      => trim((string)($_POST['applicant'] ?? '')),
            'institution'    => '',
            // Verwendungszweck entsteht aus der Sitzung – nichts zu tippen, nichts uneinheitlich
            'purpose'        => stupa_protokoll_purpose($nr, $art),
            'amount_cents'   => (int)($amount ?? 0),
            'account_holder' => trim((string)($_POST['account_holder'] ?? '')),
            'fund'           => trim((string)($_POST['fund'] ?? '')),
            'iban'           => trim((string)($_POST['iban'] ?? '')),
        ];
        $err = '';
        if ($nr < 1)                     $err = 'Bitte die Sitzung auswählen.';
        elseif ($d['applicant'] === '')  $err = 'Bitte angeben, wer das Protokoll geschrieben hat.';
        elseif ($amount === null || $amount <= 0) $err = 'Bitte einen gültigen Betrag angeben (z. B. 25,00).';
        elseif ($d['account_holder'] === '') $err = 'Bitte die:den Kontoinhaber:in angeben.';
        elseif ($d['iban'] === '')       $err = 'Bitte die IBAN angeben – ohne sie kann nichts überwiesen werden.';
        if ($err !== '') {
            flash($err, 'error');
        } elseif ($neu = stupa_claim_add((int)$user['id'], [$d])) { // Protokollgeld bleibt bewusst ein Posten
            [$nF, $fErr] = stupa_collect_uploads($neu);
            stupa_claim_set_notify($neu, !empty($_POST['notify_done']));
            stupa_notify_finance($neu);
            if ($fErr) flash('Nicht alle Belege konnten angehängt werden: ' . implode(' · ', $fErr), 'error');
            // Abweichender Topf wird zum neuen Standard – beim nächsten Mal steht er schon drin
            $vorher = stupa_protokoll_fund();
            stupa_protokoll_fund_set($d['fund']);
            flash('Protokollgeld angefordert: „' . $d['purpose'] . '" (' . euro($d['amount_cents']) . ').'
                . ($d['fund'] !== '' && $d['fund'] !== $vorher ? ' Topf ' . $d['fund'] . ' ist ab jetzt voreingestellt.' : ''), 'success');
        } else {
            flash('Die Anfrage konnte nicht gespeichert werden.', 'error');
        }
        redirect('index.php');
    }

    if (($_POST['action'] ?? '') === 'stupa_kopf') {
        // Kopfangaben des Formulars – ändern sich jede Legislatur, deshalb hier statt in Word
        stupa_legislatur_set((string)($_POST['legislatur'] ?? ''));
        stupa_praesidium_set((string)($_POST['praesidium'] ?? ''));
        flash('Angaben fürs Formular gespeichert.', 'success');
        redirect('index.php#kopf');
    }

    if (($_POST['action'] ?? '') === 'stupa_reply') {
        $cid = (int)($_POST['claim_id'] ?? 0);
        $claim = stupa_claim_get($cid);
        $body = trim((string)($_POST['body'] ?? ''));
        $hatDatei = !empty($_FILES['belege']['name'][0] ?? '');
        if (!$claim || (int)$claim['created_by'] !== (int)$user['id']) {
            flash('Vorgang nicht gefunden.', 'error');
        } elseif ($body === '' && !$hatDatei) {
            flash('Bitte etwas schreiben (oder eine Datei anhängen).', 'error');
        } else {
            $kid = stupa_comment_add($cid, true, (int)$user['id'], $body);
            [$nF, $fErr] = stupa_collect_uploads($cid, $kid);
            stupa_notify_finance_comment($claim, $body !== '' ? $body : $nF . ' Datei(en) angehängt.');
            flash('Nachricht an Finanzen geschickt.', 'success');
            if ($fErr) flash('Nicht alle Dateien konnten angehängt werden: ' . implode(' · ', $fErr), 'error');
        }
        redirect('index.php#v' . $cid);
    }
}

$claims = stupa_claims_all();
$offen  = 0;
foreach ($claims as $c) if ((string)$c['status'] !== 'done') $offen++;

stupa_header('Auszahlungsaufforderungen', $user);
?>
<div class="section-title" style="margin-top:0"><i class="ti ti-building-bank"></i> Auszahlungsaufforderungen des StuPa</div>

<div class="card">
  <p class="small muted" style="margin-top:0">Hier fordert das Präsidium eine Auszahlung aus einem StuPa-Topf an.
    Die Aufforderung geht direkt an die <strong>Finanzreferentin</strong> und erscheint dort in einer eigenen Liste,
    getrennt von den Auslagen des AStA. Den Stand siehst du unten.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="stupa_add">
    <div id="spPosten">
      <div class="sp-posten">
        <div class="sp-posten-kopf">
          <strong class="sp-nr">Posten 1</strong>
          <button type="button" class="btn secondary small sp-weg" title="Diesen Posten entfernen"><i class="ti ti-trash"></i></button>
        </div>
        <div class="field-row">
          <div style="flex:1;min-width:240px">
            <label>Antragssteller:in</label>
            <input type="text" name="applicant[]" required placeholder="Name der Person" autocomplete="off">
          </div>
          <div style="flex:1;min-width:240px">
            <label>Institution <span class="muted">(falls zutreffend)</span></label>
            <input type="text" name="institution[]" placeholder="z. B. Fachschaft, Referat, Initiative" autocomplete="off">
          </div>
        </div>
        <label>Verwendungszweck</label>
        <input type="text" name="purpose[]" required placeholder="Wofür ist die Auszahlung?" autocomplete="off">
        <div class="field-row">
          <div style="flex:1;min-width:160px">
            <label>Geldbetrag</label>
            <input type="text" name="amount[]" required inputmode="decimal" placeholder="250,00" autocomplete="off">
          </div>
          <div style="flex:1;min-width:220px">
            <label>Topf</label>
            <input type="text" name="fund[]" placeholder="aus welchem Topf?" autocomplete="off">
          </div>
        </div>
        <div class="field-row">
          <div style="flex:1;min-width:240px">
            <label>Kontoinhaber:in</label>
            <input type="text" name="account_holder[]" required placeholder="wie auf dem Konto" autocomplete="off">
          </div>
          <div style="flex:2;min-width:260px">
            <label>IBAN</label>
            <input type="text" name="iban[]" required placeholder="DE.." autocomplete="off">
          </div>
        </div>
      </div>
    </div>
    <div class="btn-row" style="margin-top:.7rem">
      <button type="button" class="btn secondary small" id="spMehr"><i class="ti ti-plus"></i> Weiteren Posten hinzufügen</button>
      <span class="small muted" id="spHinweis">Mehrere Posten kommen zusammen auf <strong>ein</strong> Formular.</span>
    </div>
    <div style="margin-top:.9rem">
      <label for="sp_files"><i class="ti ti-paperclip"></i> Belege anhängen (Fotos/PDF, optional)</label>
      <input type="file" id="sp_files" name="belege[]" multiple accept=".pdf,.png,.jpg,.jpeg,.heic,.heif,.webp,.gif,image/*,application/pdf">
    </div>
    <label class="auto-toggle" style="margin-top:.6rem"><input type="checkbox" name="notify_done" value="1">
      Mail an uns, sobald die Auszahlung erledigt ist</label>
    <div class="btn-row" style="margin-top:1rem">
      <button class="btn" type="submit"><i class="ti ti-send"></i> Aufforderung übermitteln</button>
    </div>
  </form>
  <script>
  (function () {
    // Posten hinzufügen/entfernen. Der erste Block ist die Vorlage; alle Felder heißen […][],
    // die Reihenfolge im Formular bestimmt also die Reihenfolge auf dem Blatt.
    var box = document.getElementById('spPosten'), mehr = document.getElementById('spMehr');
    if (!box || !mehr) return;
    var tpl = box.firstElementChild.cloneNode(true);
    function nummerieren() {
      var bl = box.querySelectorAll('.sp-posten');
      for (var i = 0; i < bl.length; i++) {
        bl[i].querySelector('.sp-nr').textContent = 'Posten ' + (i + 1);
      }
      // Bei nur einem Posten gibt es nichts zu entfernen – dann bleibt der Knopf weg
      box.classList.toggle('sp-einzeln', bl.length < 2);
    }
    mehr.addEventListener('click', function () {
      var neu = tpl.cloneNode(true);
      neu.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      box.appendChild(neu);
      nummerieren();
      var erstes = neu.querySelector('input');
      if (erstes) erstes.focus();
    });
    box.addEventListener('click', function (e) {
      var weg = e.target.closest('.sp-weg');
      if (!weg || box.querySelectorAll('.sp-posten').length < 2) return;
      weg.closest('.sp-posten').remove();
      nummerieren();
    });
    nummerieren();
  })();
  </script>
</div>

<div class="section-title"><i class="ti ti-notebook"></i> Protokollgeld anfordern</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Für das Schreiben eines Sitzungsprotokolls. Den <strong>Verwendungszweck</strong>
    baut die App aus der gewählten Sitzung – so heißt er bei jeder Anfrage gleich.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="stupa_protokoll">
    <div class="field-row">
      <div style="min-width:130px">
        <label for="pg_nr">Sitzung</label>
        <select name="sitzung_nr" id="pg_nr" required>
          <option value="">— Nummer —</option>
          <?php for ($i = 1; $i <= 40; $i++): ?><option value="<?= $i ?>"><?= $i ?>.</option><?php endfor; ?>
        </select>
      </div>
      <div style="min-width:200px">
        <label for="pg_art">Art der Sitzung</label>
        <select name="sitzung_art" id="pg_art">
          <option value="o">ordentliche Sitzung</option>
          <option value="ao">außerordentliche Sitzung</option>
        </select>
      </div>
      <div style="flex:1;min-width:240px">
        <label for="pg_applicant">Protokoll geschrieben von</label>
        <input type="text" id="pg_applicant" name="applicant" required placeholder="Name der Person" autocomplete="off">
      </div>
    </div>
    <p class="small muted" style="margin:.1rem 0 .6rem"><i class="ti ti-arrow-narrow-right"></i>
      Verwendungszweck: <strong id="pgPreview">Protokoll 1. ordentliche Sitzung</strong></p>
    <div class="field-row">
      <div style="flex:1;min-width:160px">
        <label for="pg_amount">Geldbetrag</label>
        <input type="text" id="pg_amount" name="amount" required inputmode="decimal" placeholder="25,00" autocomplete="off">
      </div>
      <div style="flex:1;min-width:220px">
        <label for="pg_fund">Topf</label>
        <input type="text" id="pg_fund" name="fund" value="<?= h(stupa_protokoll_fund()) ?>" autocomplete="off">
        <p class="small muted" style="margin:.25rem 0 0">Änderst du den Topf, gilt er künftig als Voreinstellung.</p>
      </div>
    </div>
    <div class="field-row">
      <div style="flex:1;min-width:240px">
        <label for="pg_holder">Kontoinhaber:in</label>
        <input type="text" id="pg_holder" name="account_holder" required placeholder="wie auf dem Konto" autocomplete="off">
      </div>
      <div style="flex:2;min-width:260px">
        <label for="pg_iban">IBAN</label>
        <input type="text" id="pg_iban" name="iban" required placeholder="DE.." autocomplete="off">
      </div>
    </div>
    <div style="margin-top:.9rem">
      <label for="pg_files"><i class="ti ti-paperclip"></i> Belege anhängen (Fotos/PDF, optional)</label>
      <input type="file" id="pg_files" name="belege[]" multiple accept=".pdf,.png,.jpg,.jpeg,.heic,.heif,.webp,.gif,image/*,application/pdf">
    </div>
    <label class="auto-toggle" style="margin-top:.6rem"><input type="checkbox" name="notify_done" value="1">
      Mail an uns, sobald die Auszahlung erledigt ist</label>
    <div class="btn-row" style="margin-top:1rem">
      <button class="btn" type="submit"><i class="ti ti-send"></i> Protokollgeld anfordern</button>
    </div>
  </form>
  <script>
  (function () {
    // Vorschau des Verwendungszwecks – dieselbe Regel wie serverseitig, nur zum Mitlesen
    var nr = document.getElementById('pg_nr'), art = document.getElementById('pg_art'), out = document.getElementById('pgPreview');
    if (!nr || !art || !out) return;
    function upd() {
      var n = parseInt(nr.value, 10);
      out.textContent = 'Protokoll ' + (n > 0 ? n : 1) + '. '
        + (art.value === 'ao' ? 'außerordentliche' : 'ordentliche') + ' Sitzung';
    }
    nr.addEventListener('change', upd); art.addEventListener('change', upd); upd();
  })();
  </script>
</div>

<div class="section-title" id="kopf"><i class="ti ti-id-badge-2"></i> Angaben fürs Formular</div>
<div class="card">
  <p class="small muted" style="margin-top:0">Das stehen oben auf der Auszahlungsaufforderung, die Finanzen aus euren
    Angaben erzeugt. Zu Beginn einer neuen Legislatur einmal hier anpassen – die Word-Vorlage bleibt unangetastet.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="stupa_kopf">
    <div class="field-row">
      <div style="min-width:150px">
        <label for="kf_leg">Wievieltes Parlament?</label>
        <input type="text" id="kf_leg" name="legislatur" inputmode="numeric" value="<?= h(stupa_legislatur()) ?>"
               placeholder="38" autocomplete="off">
        <p class="small muted" style="margin:.25rem 0 0">Nur die Zahl – im Formular steht dann
          „<?= h(stupa_legislatur()) ?>. Studierendenparlament".</p>
      </div>
      <div style="flex:1;min-width:260px">
        <label for="kf_prae">Präsidium <span class="muted">(ein Name je Zeile)</span></label>
        <textarea id="kf_prae" name="praesidium" rows="3" placeholder="Vorname Nachname"><?= h(stupa_praesidium()) ?></textarea>
      </div>
    </div>
    <div class="btn-row" style="margin-top:.8rem">
      <button class="btn secondary" type="submit"><i class="ti ti-device-floppy"></i> Angaben speichern</button>
    </div>
  </form>
</div>

<div class="section-title"><i class="ti ti-list-check"></i> Übermittelt<?= $offen ? ' <span class="badge-count">' . $offen . ' offen</span>' : '' ?></div>
<?php if (!$claims): ?>
  <div class="card"><p class="empty"><i class="ti ti-inbox"></i> Noch keine Auszahlungsaufforderung übermittelt.</p></div>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead><tr><th>Eingereicht</th><th>Antragssteller:in</th><th>Verwendungszweck</th><th style="text-align:right">Betrag</th><th style="text-align:right">Stand</th></tr></thead>
      <tbody>
      <?php foreach ($claims as $c): $pos = stupa_positions_of((int)$c['id']); ?>
        <tr>
          <td class="muted small" style="white-space:nowrap"><?= h(fmt_date(substr((string)$c['created_at'], 0, 10))) ?>
            <?php if (count($pos) > 1): ?><div class="muted small"><?= count($pos) ?> Posten</div><?php endif; ?></td>
          <td><?php foreach ($pos as $p): ?>
              <div style="margin-bottom:.2rem"><strong><?= h($p['applicant']) ?></strong>
                <?php if (trim((string)$p['institution']) !== ''): ?><div class="muted small"><?= h($p['institution']) ?></div><?php endif; ?></div>
            <?php endforeach; ?></td>
          <td class="small"><?php foreach ($pos as $p): ?>
              <div style="margin-bottom:.2rem"><?= h($p['purpose']) ?>
                <?php if (trim((string)$p['fund']) !== ''): ?><span class="muted">· Topf: <?= h($p['fund']) ?></span><?php endif; ?></div>
            <?php endforeach; ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php // Bei einem Posten ist der Betrag schon die Summe – dann keine zweite Zeile
              foreach ($pos as $p): ?><div style="margin-bottom:.2rem<?= count($pos) > 1 ? '' : ';font-weight:700' ?>"><?= euro((int)$p['amount_cents']) ?></div><?php endforeach; ?>
            <?php if (count($pos) > 1): ?>
              <div style="font-weight:700;border-top:1px solid var(--line);padding-top:.2rem"><?= euro(stupa_claim_total($pos)) ?></div>
            <?php endif; ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if ((string)$c['status'] === 'done'): ?>
              <span class="pill pill-ok"><i class="ti ti-check"></i> erledigt<?= $c['done_at'] ? ' · ' . h(fmt_date(substr((string)$c['done_at'], 0, 10))) : '' ?></span>
            <?php else: ?>
              <span class="pill pill-warn"><i class="ti ti-clock"></i> offen</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php $dateien = stupa_files_of((int)$c['id']); $verlauf = stupa_comments_of((int)$c['id']); ?>
        <tr id="v<?= (int)$c['id'] ?>">
          <td colspan="5" style="padding-top:0">
            <details<?= $verlauf ? ' open' : '' ?>>
              <summary class="small muted">Belege &amp; Rückfragen<?= $verlauf ? ' (' . count($verlauf) . ')' : '' ?><?= $dateien ? ' · ' . count($dateien) . ' Beleg(e)' : '' ?></summary>
              <?php if ($dateien): ?>
                <p class="small" style="margin:.5rem 0 0"><i class="ti ti-paperclip" style="color:var(--petrol)"></i>
                  <?= implode(' · ', array_map(fn($f) => '<a href="download.php?f=' . (int)$f['id'] . '" target="_blank" rel="noopener">' . h($f['orig_name']) . '</a>', $dateien)) ?></p>
              <?php endif; ?>
              <?php foreach ($verlauf as $k): $kf = stupa_comment_files((int)$k['id']); ?>
                <div class="card" style="margin:.5rem 0;padding:.6rem .8rem">
                  <div class="small muted"><strong><?= h(stupa_comment_author($k)) ?></strong> · <?= h(fmt_date(substr((string)$k['created_at'], 0, 10))) ?></div>
                  <?php if (trim((string)$k['body']) !== ''): ?><div style="white-space:pre-wrap"><?= h($k['body']) ?></div><?php endif; ?>
                  <?php if ($kf): ?><div class="small"><i class="ti ti-paperclip"></i>
                    <?= implode(' · ', array_map(fn($f) => '<a href="download.php?f=' . (int)$f['id'] . '" target="_blank" rel="noopener">' . h($f['orig_name']) . '</a>', $kf)) ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
              <form method="post" enctype="multipart/form-data" style="margin-top:.5rem">
                <?= csrf_field() ?><input type="hidden" name="action" value="stupa_reply"><input type="hidden" name="claim_id" value="<?= (int)$c['id'] ?>">
                <label for="r<?= (int)$c['id'] ?>" class="small">Rückfrage oder Ergänzung an Finanzen</label>
                <textarea id="r<?= (int)$c['id'] ?>" name="body" rows="2" placeholder="Nachricht …"></textarea>
                <input type="file" name="belege[]" multiple accept=".pdf,.png,.jpg,.jpeg,.heic,.heif,.webp,.gif,image/*,application/pdf" style="margin-top:.4rem">
                <div class="btn-row" style="margin-top:.5rem"><button class="btn secondary small" type="submit"><i class="ti ti-send"></i> Abschicken</button></div>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php
stupa_footer();
