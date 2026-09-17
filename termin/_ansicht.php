<?php
/**
 * Bausteine der Terminumfrage-Ansicht.
 *
 * Teilnahme-Seite und Verwaltungs-Seite zeigen im Kern dasselbe – Kopf, Stimmzettel, Ergebnis.
 * Der Unterschied ist nur, wie viel man sehen und anfassen darf. Deshalb steht das Aussehen
 * EINMAL hier: Sonst wäre jede Änderung am Ergebnis zwei Änderungen, und eine davon würde
 * früher oder später vergessen.
 *
 * Keine eigene Seite: Wer die Datei direkt aufruft, bekommt nichts zu sehen. Geprüft wird
 * ausdrücklich, ob SIE der Einstiegspunkt ist – ein einfaches „ist tplan_head() da?" würde
 * auch den Selbsttest abwürgen, der hier nur tp_w() nachschlagen will.
 */
if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) { http_response_code(404); exit; }

/**
 * Breite eines Balkenstücks als KLASSE, nicht als style="width:…".
 *
 * Der öffentliche Bereich läuft unter einer Content-Security-Policy ohne 'unsafe-inline' –
 * ein Inline-Stil wird stillschweigend verworfen, der Balken bliebe leer. Deshalb gibt es in
 * termin.css die Stufen tp-w0 bis tp-w100 in Zweierschritten; feiner kann kein Auge das
 * bei einem 8 Pixel hohen Balken unterscheiden.
 */
function tp_w(int $prozent): string
{
    return 'tp-w' . max(0, min(100, (int)round($prozent / 2) * 2));
}

/**
 * Der Kopf einer Terminumfrage – als randloses Band über die volle Fensterbreite, dieselbe
 * Sprache wie die Startseite: Eine Karte in schmaler Spalte ließe den Übergang von der
 * Startseite wie zwei verschiedene Seiten wirken.
 */
function tp_kopf(array $poll, int $anzahl, bool $offen, bool $orga = false): void
{
    $frist = trim((string)$poll['deadline']);
    ?>
    <section class="pat-hero sub tp-hero">
      <div class="pat-in">
      <h1><?= h($poll['title']) ?></h1>
      <div class="tp-chips">
        <?php if ((int)$poll['final_option'] > 0): ?>
          <span class="tp-chip ok"><i class="ti ti-calendar-check"></i> Termin steht fest</span>
        <?php elseif ($offen): ?>
          <span class="tp-chip ok"><i class="ti ti-player-play"></i> läuft</span>
        <?php elseif (tplan_deadline_passed($poll)): ?>
          <span class="tp-chip"><i class="ti ti-clock-off"></i> Frist abgelaufen</span>
        <?php else: ?>
          <span class="tp-chip"><i class="ti ti-lock"></i> geschlossen</span>
        <?php endif; ?>
        <span class="tp-chip"><i class="ti ti-users"></i> <?= $anzahl ?> <?= $anzahl === 1 ? 'Rückmeldung' : 'Rückmeldungen' ?></span>
        <?php if (trim((string)$poll['organizer']) !== ''): ?>
          <span class="tp-chip"><i class="ti ti-user"></i> <?= h($poll['organizer']) ?></span>
        <?php endif; ?>
        <?php if (trim((string)$poll['place']) !== ''): ?>
          <span class="tp-chip"><i class="ti ti-map-pin"></i> <?= h($poll['place']) ?></span>
        <?php endif; ?>
        <?php if ($frist !== ''): ?>
          <span class="tp-chip<?= $offen ? ' akzent' : '' ?>"><i class="ti ti-clock"></i> Antworten bis <?= h(tplan_dt($frist)) ?></span>
        <?php endif; ?>
      </div>
      <?php if (trim((string)$poll['intro']) !== ''): ?>
        <div class="um-intro tp-intro"><?= nl2br(h(trim((string)$poll['intro']))) ?></div>
      <?php endif; ?>
      <?php if ($orga): ?>
        <p class="um-klein tp-orga-hinweis"><i class="ti ti-eye"></i> Du siehst diese Seite als Ersteller:in – mit allen Namen und Angaben.</p>
      <?php endif; ?>
      </div>
    </section>
    <?php
}

/** Der festgelegte Termin, ganz oben und nicht zu übersehen. */
function tp_final_karte(array $poll, array $o): void
{
    ?>
    <div class="card tp-final">
      <p class="tp-final-k"><i class="ti ti-calendar-check"></i> Der Termin steht fest</p>
      <p class="tp-final-d"><?= h(tplan_weekday((string)$o['day'])) ?>, <?= h(tplan_option_date($o)) ?></p>
      <p class="tp-final-z"><?= h(tplan_option_time($o)) ?><?php if (trim((string)$o['label']) !== ''): ?> · <?= h($o['label']) ?><?php endif; ?></p>
      <?php if (trim((string)$poll['place']) !== ''): ?><p class="tp-final-o"><i class="ti ti-map-pin"></i> <?= h($poll['place']) ?></p><?php endif; ?>
      <?php if (trim((string)$poll['final_note']) !== ''): ?><p class="tp-final-n"><?= nl2br(h((string)$poll['final_note'])) ?></p><?php endif; ?>
      <p class="tp-final-btn">
        <a class="btn secondary" href="ics.php?t=<?= h(rawurlencode((string)$poll['slug'])) ?>"><i class="ti ti-calendar-plus"></i> In meinen Kalender</a>
      </p>
    </div>
    <?php
}

/** Ein Terminvorschlag als Zeile: links wann, rechts die eigene Antwort. */
function tp_stimmzettel(array $poll, array $optionen, ?array $entry, ?array $gerade = null): void
{
    $eid = $entry ? (int)$entry['id'] : 0;
    $letzterTag = '';
    ?>
    <div class="tp-liste">
      <?php foreach ($optionen as $o): $oid = (int)$o['id'];
            // $gerade sind die eben abgeschickten Kreuze. Sie haben Vorrang, wenn das Speichern
            // scheiterte: Sonst verliert man beim Hinweis „dieser Termin ist voll" alle anderen
            // Antworten gleich mit und muss von vorn anfangen.
            $meine = $gerade !== null
                ? (string)($gerade[$oid] ?? 'no')
                : ($entry ? (string)($entry['votes'][$oid] ?? 'no') : 'no');
            $kap   = (int)$o['capacity'];
            $belegt = $kap > 0 ? tplan_option_yes($oid, $eid) : 0;
            $voll  = $kap > 0 && $belegt >= $kap && $meine !== 'yes';
            $tag   = (string)$o['day'];
      ?>
        <?php if ($tag !== $letzterTag): $letzterTag = $tag; ?>
          <div class="tp-tag"><span class="tp-tag-wd"><?= h(tplan_weekday($tag)) ?></span> <?= h(tplan_option_date($o)) ?>
            <?php if (tplan_option_past($o)): ?><span class="um-klein">· liegt in der Vergangenheit</span><?php endif; ?></div>
        <?php endif; ?>
        <div class="tp-zeile<?= $voll ? ' ist-voll' : '' ?>">
          <div class="tp-zeile-w">
            <span class="tp-zeit"><?= h(tplan_option_time($o)) ?></span>
            <?php if (trim((string)$o['label']) !== ''): ?><span class="tp-note"><?= h($o['label']) ?></span><?php endif; ?>
            <?php if ($kap > 0): ?>
              <span class="tp-kap<?= $voll ? ' voll' : '' ?>"><i class="ti ti-user-check"></i>
                <?= $voll ? 'ausgebucht' : 'noch ' . max(0, $kap - $belegt) . ' von ' . $kap . ' frei' ?></span>
            <?php endif; ?>
          </div>
          <span class="statuspick tp-pick">
            <label><input type="radio" name="v[<?= $oid ?>]" value="yes" <?= $meine === 'yes' ? 'checked' : '' ?> <?= $voll ? 'disabled' : '' ?>><span class="s-yes">Ja</span></label>
            <?php if (!empty($poll['allow_maybe'])): ?>
              <label><input type="radio" name="v[<?= $oid ?>]" value="maybe" <?= $meine === 'maybe' ? 'checked' : '' ?>><span class="s-maybe">Evtl.</span></label>
            <?php endif; ?>
            <label><input type="radio" name="v[<?= $oid ?>]" value="no" <?= $meine === 'no' || $voll ? 'checked' : '' ?>><span class="s-no">Nein</span></label>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Das Ergebnis. In der Verwaltung ($orga) kommen die Namen immer mit und je Vorschlag der
 * Knopf zum Festlegen – genau dort, wo man den Gewinner sieht.
 */
function tp_ergebnis(array $poll, array $res, array $best, int $anzahl, ?array $final, bool $orga = false): void
{
    if (!$res) return;
    $namenZeigen = $orga || !empty($poll['show_names']);
    $finalId = $final ? (int)$final['id'] : 0;
    ?>
    <div class="section-title" id="ergebnis"><i class="ti ti-chart-bar"></i> Ergebnis
      <span class="muted small tp-sub">– <?= $anzahl ?> <?= $anzahl === 1 ? 'Rückmeldung' : 'Rückmeldungen' ?></span></div>
    <div class="card">
      <?php if ($anzahl === 0): ?>
        <p class="empty"><i class="ti ti-mood-empty"></i> Noch hat niemand geantwortet.</p>
      <?php else: ?>
        <ul class="tp-erg">
          <?php foreach ($res as $r): $o = $r['option']; $oid = (int)$o['id'];
                $fuehrt = in_array($oid, $best, true); ?>
            <li class="tp-erg-i<?= $fuehrt ? ' fuehrt' : '' ?><?= $finalId === $oid ? ' ist-final' : '' ?>">
              <div class="tp-erg-k">
                <span class="tp-erg-d">
                  <?php if ($finalId === $oid): ?><i class="ti ti-calendar-check" title="Festgelegter Termin"></i>
                  <?php elseif ($fuehrt): ?><i class="ti ti-crown" title="Aktuell die meisten Zusagen"></i><?php endif; ?>
                  <?= h(tplan_weekday((string)$o['day'])) ?>, <?= h(tplan_option_date($o)) ?>
                  <span class="tp-erg-z"><?= h(tplan_option_time($o)) ?></span>
                  <?php if (trim((string)$o['label']) !== ''): ?><span class="tp-note"><?= h($o['label']) ?></span><?php endif; ?>
                </span>
                <span class="tp-erg-n">
                  <span class="pc pc-yes" title="Ja"><i class="ti ti-check"></i> <?= (int)$r['yes'] ?></span>
                  <?php if (!empty($poll['allow_maybe'])): ?><span class="pc pc-maybe" title="Vielleicht"><i class="ti ti-help"></i> <?= (int)$r['maybe'] ?></span><?php endif; ?>
                  <span class="pc pc-no" title="Nein"><i class="ti ti-x"></i> <?= (int)$r['no'] ?></span>
                </span>
              </div>
              <div class="tp-bar" aria-hidden="true">
                <span class="tp-b-yes <?= tp_w((int)$r['pct']['yes']) ?>"></span>
                <?php if (!empty($poll['allow_maybe'])): ?><span class="tp-b-maybe <?= tp_w((int)$r['pct']['maybe']) ?>"></span><?php endif; ?>
                <span class="tp-b-no <?= tp_w((int)$r['pct']['no']) ?>"></span>
              </div>
              <?php if ($r['frei'] !== null): ?>
                <p class="um-klein tp-erg-kap"><?= $r['voll'] ? 'ausgebucht' : (int)$r['frei'] . ' von ' . (int)$o['capacity'] . ' Plätzen frei' ?></p>
              <?php endif; ?>
              <?php if ($namenZeigen && ($r['namen']['yes'] || $r['namen']['maybe'])): ?>
                <p class="tp-wer um-klein">
                  <?php if ($r['namen']['yes']): ?><span class="tp-wer-j"><i class="ti ti-check"></i> <?= h(implode(', ', $r['namen']['yes'])) ?></span><?php endif; ?>
                  <?php if ($r['namen']['maybe']): ?><span class="tp-wer-e"><i class="ti ti-help"></i> <?= h(implode(', ', $r['namen']['maybe'])) ?></span><?php endif; ?>
                </p>
              <?php endif; ?>
              <?php if ($orga): ?>
                <div class="tp-erg-akt">
                  <?php if ($finalId === $oid): ?>
                    <form method="post"><?= tplan_csrf_field() ?>
                      <input type="hidden" name="action" value="finalize"><input type="hidden" name="option_id" value="0">
                      <button class="btn secondary small" type="submit"><i class="ti ti-arrow-back-up"></i> Festlegung zurücknehmen</button>
                    </form>
                  <?php else: ?>
                    <form method="post"><?= tplan_csrf_field() ?>
                      <input type="hidden" name="action" value="finalize"><input type="hidden" name="option_id" value="<?= $oid ?>">
                      <button class="btn secondary small" type="submit"><i class="ti ti-calendar-check"></i> Diesen Termin festlegen</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$namenZeigen): ?>
          <p class="um-klein"><i class="ti ti-eye-off"></i> Bei dieser Umfrage werden nur Zahlen gezeigt, keine Namen.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * Die Tabelle mit allen Antworten – eingeklappt, weil sie auf schmalen Bildschirmen seitwärts
 * geschoben werden muss. Wer sie braucht, findet sie; wer nur antworten will, sieht sie nicht.
 */
function tp_matrix(array $poll, array $optionen, array $eintraege, bool $orga = false): void
{
    if (!$eintraege || !$optionen) return;
    if (!$orga && empty($poll['show_names'])) return;
    $zeichen = ['yes' => ['ti-check', 'ja', 'Ja'], 'maybe' => ['ti-help', 'evtl', 'Vielleicht'], 'no' => ['ti-x', 'nein', 'Nein']];
    ?>
    <details class="tp-tabelle">
      <summary><i class="ti ti-table"></i> Tabelle aller Antworten <span class="um-klein">(<?= count($eintraege) ?>)</span></summary>
      <div class="tp-scroll">
        <table class="tp-matrix">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <?php foreach ($optionen as $o): ?>
                <th scope="col"><span class="tp-m-wd"><?= h(tplan_weekday((string)$o['day'])) ?></span>
                  <span class="tp-m-d"><?= h(date('d.m.', (int)strtotime((string)$o['day'] . ' 12:00'))) ?></span>
                  <span class="tp-m-z"><?= h(trim((string)$o['t_from']) !== '' ? (string)$o['t_from'] : '–') ?></span></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($eintraege as $e): ?>
              <tr>
                <th scope="row"><?= h((string)$e['name']) ?>
                  <?php if (trim((string)$e['comment']) !== ''): ?><span class="tp-m-k" title="<?= h((string)$e['comment']) ?>"><i class="ti ti-message-2"></i></span><?php endif; ?>
                </th>
                <?php foreach ($optionen as $o): $w = (string)($e['votes'][(int)$o['id']] ?? 'no');
                      $z = $zeichen[$w] ?? $zeichen['no']; ?>
                  <td class="tp-m-<?= h($z[1]) ?>"><i class="ti <?= h($z[0]) ?>" title="<?= h($z[2]) ?>"></i><span class="pat-sr"><?= h($z[2]) ?></span></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php $mitK = array_filter($eintraege, fn($e) => trim((string)$e['comment']) !== ''); ?>
      <?php if ($mitK): ?>
        <ul class="tp-komm">
          <?php foreach ($mitK as $e): ?>
            <li><strong><?= h((string)$e['name']) ?>:</strong> <?= h((string)$e['comment']) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </details>
    <?php
}
