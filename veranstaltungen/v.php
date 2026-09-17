<?php
/** Eine Veranstaltung oder ein Kurs im Detail. */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard()) exit;

$id = (int)wl_param($_GET['id'] ?? '0');
$it = wl_item($id);

// VORSCHAU für die Freigabe: Der geheime Bearbeitungs-Token des Beitrags öffnet ihn auch
// unveröffentlicht – die Verwaltung hängt ihn an ihren Vorschau-Link (?schau=…). Ohne oder
// mit falschem Token bleibt alles Unveröffentlichte „gibt es nicht".
$schau = wl_param($_GET['schau'] ?? '');
$vorschau = $it && $schau !== '' && trim((string)($it['edit_token'] ?? '')) !== ''
         && hash_equals((string)$it['edit_token'], $schau) && (string)$it['status'] !== 'live';

// Nur Freigegebenes ist öffentlich. Alles andere sieht aus wie „gibt es nicht" – wer einen
// eingereichten Beitrag prüfen will, tut das in der App oder über seinen Bearbeiten-Link.
if (!$it || ((string)$it['status'] !== 'live' && !$vorschau)) {
    http_response_code(404);
    wl_head('Nicht gefunden', '', '', true);
    wl_nav();
    echo '<div class="wl-det"><h1>' . h(wl_t('nicht_gefunden')) . '</h1>'
       . '<p class="wl-text">' . h(wl_t('nicht_gef_text')) . '</p>'
       . '<div class="wl-btns"><a class="wl-btn p" href="index.php">' . h(wl_t('btn_uebersicht')) . '</a></div></div>';
    wl_foot();
    exit;
}

// ------------------------------------------------------------------ Beitrag melden
// Wer fremde Inhalte öffentlich zeigt, muss einen Weg anbieten, auf dem Leute etwas
// beanstanden können – und der Weg endet nicht in einem Postfach, sondern im Reiter
// „Meldungen" der Verwaltung. Bewusst OHNE Konto und ohne Pflichtangaben: Wer eine falsche
// Uhrzeit sieht, soll das in zehn Sekunden loswerden können.
$meldeOk = false;
$meldeFehler = '';
$meldeAuf = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'melden') {
    wl_check_csrf();
    $meldeAuf = true;
    $r = wl_report_add($id, wl_param($_POST['grund'] ?? ''), wl_param($_POST['text'] ?? ''),
                       wl_param($_POST['mail'] ?? ''));
    if ($r['ok']) { $meldeOk = true; $meldeAuf = false; }
    else $meldeFehler = $r['msg'];
}

$org  = wl_org((int)($it['org_id'] ?? 0));
$img  = wl_image((int)($it['image_id'] ?? 0));
$kurs = ($it['kind'] ?? 'event') === 'kurs';
$vorbei = wl_item_vorbei($it);
// Abgesagt heißt: Der Beitrag bleibt sichtbar (wer davon wusste, soll die Absage sehen),
// aber alles Handlungsauffordernde – Anmeldung, Kalender, Wecker – verschwindet wie bei Vorbei.
$abgesagt = (int)($it['abgesagt'] ?? 0) === 1;

// Aufruf-Zähler, der Rückkanal an die Veranstalter: reines
// Hochzählen, keine Personenbezüge – EINMAL je Sitzung (die Sitzung läuft auf allen
// wl-Seiten ohnehin, es kommt kein neues Cookie dazu), nicht in der Freigabe-Vorschau,
// und Suchmaschinen-Crawler zählen nicht als Publikum.
if (!$vorschau && (string)$it['status'] === 'live' && !wl_ist_bot()
    && empty($_SESSION['wl_gesehen'][$id])) {
    $_SESSION['wl_gesehen'][$id] = 1;
    wl_db()->prepare('UPDATE items SET aufrufe = aufrufe + 1 WHERE id = ?')->execute([$id]);
}

// Wie viel macht dieser Veranstalter sonst noch? Eine Zahl, die Vertrauen schafft – und den
// Anreiz, den Namen anzuklicken.
$weitere = 0;
if ($org) {
    $s = wl_db()->prepare("SELECT COUNT(*) FROM items WHERE org_id = ? AND status = 'live' AND id <> ?");
    $s->execute([(int)$org['id'], $id]);
    $weitere = (int)$s->fetchColumn();
}

$beschr = trim((string)$it['teaser']) !== ''
    ? (string)$it['teaser']
    : mb_substr(trim((string)$it['text']), 0, 180);

// „Erinnere mich" gibt es nur, wenn es etwas zu erinnern gibt: künftiger Termin, Push am Server.
$erinnerbar = !$kurs && !$vorbei && !$abgesagt && push_available() && wl_push_vapid() && wl_remind_at($it) !== '';
$GLOBALS['wl_push'] = $erinnerbar || $org !== null;

wl_head((string)$it['title'], $beschr, (string)($img['file'] ?? ''));
wl_nav($kurs ? 'kurse' : 'events');

// Kleine Inline-Symbole (Linien-Stil, stroke=currentColor) – die öffentlichen Seiten laden
// bewusst keine Symbol-Schrift, deshalb liegen die Pfade hier.
$ico = function (string $d): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
};
$icoKal    = $ico('<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M16 3v4M8 3v4M4 11h16"/>');
$icoPin    = $ico('<path d="M12 21s-6.2-5.2-6.2-10.2a6.2 6.2 0 1 1 12.4 0C18.2 15.8 12 21 12 21z"/><circle cx="12" cy="10.6" r="2.4"/>');
$icoTicket = $ico('<path d="M15 5v2m0 4v2m0 4v2"/><path d="M5 5h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4V7a2 2 0 0 1 2-2z"/>');
$icoWied   = $ico('<path d="M4 12V9a3 3 0 0 1 3-3h13"/><path d="m17 3 3 3-3 3"/><path d="M20 12v3a3 3 0 0 1-3 3H4"/><path d="m7 21-3-3 3-3"/>');
$icoCheck  = $ico('<circle cx="12" cy="12" r="9"/><path d="m8.5 12.2 2.3 2.3 4.7-4.8"/>');
$icoPlus   = $ico('<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M16 3v4M8 3v4M4 11h16M12 14.5v4M10 16.5h4"/>');
$icoGlocke = $ico('<path d="M10 5.2a2 2 0 1 1 4 0 7 7 0 0 1 4 6.3v2.6a3.5 3.5 0 0 0 1.8 3H4.2a3.5 3.5 0 0 0 1.8-3v-2.6a7 7 0 0 1 4-6.3z"/><path d="M9.5 17.5v.5a2.5 2.5 0 0 0 5 0v-.5"/>');
$icoStill  = $ico('<path d="M10 5.2a2 2 0 1 1 4 0 7 7 0 0 1 4 6.3v2.6a3.5 3.5 0 0 0 1.8 3H4.2a3.5 3.5 0 0 0 1.8-3v-2.6a7 7 0 0 1 4-6.3z"/><path d="M9.5 17.5v.5a2.5 2.5 0 0 0 5 0v-.5"/><path d="m4 4 16 16"/>');
$icoTeilen = $ico('<circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="6" r="2.6"/><circle cx="18" cy="18" r="2.6"/><path d="m8.3 10.9 7.4-3.6M8.3 13.1l7.4 3.6"/>');
$icoExt    = $ico('<path d="M7 17 17 7"/><path d="M9 7h8v8"/>');
?>

<?php /* Ohne eigenes Bild: dasselbe Standard-Foto wie auf der Kachel (gleiche ID, gleiche
         Ableitung) – wer klickt, landet nicht plötzlich vor einer anderen Fläche. Der
         Farbverlaufs-Platzhalter bleibt nur als letzte Rückfalllinie. */
      $std = $img ? null : wl_item_std($it); ?>
<div class="wl-det-hero<?= ($img || $std) ? '' : ' ' . h(wl_placeholder_class($id)) ?>">
  <?php if ($img): ?>
    <img src="<?= h(wl_img_url((string)$img['file'], true)) ?>" alt="" width="1600" height="610">
  <?php elseif ($std): ?>
    <img src="<?= h(wl_standard_url($std, true)) ?>" alt="" width="1600" height="610">
  <?php endif; ?>
  <a class="wl-back" href="<?= $kurs ? 'kurse.php' : 'index.php' ?>"><?= h(wl_t('btn_zurueck')) ?></a>
</div>

<?php /* .wl-det-2s = die Desktop-Zweispaltigkeit (Grid-Areas in wl.css). Der 404-Zweig
         oben bleibt bewusst einspaltig: freie Kinder ohne Area rutschten sonst in die
         erste leere Zelle (vor den Titel). */ ?>
<article class="wl-det wl-det-2s">
  <?php // Etikett, kein Filter – deshalb .wl-badge mit getönter Fläche statt .wl-chip. ?>
  <span class="wl-badge <?= h(wl_cat_css((string)$it['cat'])) ?>"><?= h(wl_cat_label((string)$it['cat'])) ?></span>
  <?php /* Englische Kurzfassung (optional, siehe wl_en_an()): Verlangt der Browser Englisch
           und hat die Gruppe etwas hinterlegt, steht es OBEN und das Deutsche darunter –
           nicht umgekehrt. Wer die Seite auf Deutsch aufruft, merkt von alldem nichts. */
        $enTitel  = trim((string)($it['title_en'] ?? ''));
        $enTeaser = trim((string)($it['teaser_en'] ?? ''));
        $enOben   = wl_will_englisch() && $enTitel !== ''; ?>
  <h1><?= h($enOben ? $enTitel : (string)$it['title']) ?></h1>
  <?php if ($enOben): ?>
    <p class="wl-det-zweit" lang="de"><?= h((string)$it['title']) ?></p>
  <?php endif; ?>

  <?php wl_demo_note(); ?>

  <?php if ($vorschau): ?>
    <p class="wl-note warn">Vorschau: Dieser Beitrag ist noch nicht öffentlich – so wird er
       nach der Freigabe aussehen.</p>
  <?php endif; ?>

  <?php if ($abgesagt): ?>
    <p class="wl-note bad"><strong><?= h(wl_t('abgesagt')) ?></strong> <?= h(wl_t('abgesagt_text')) ?></p>
  <?php elseif ($vorbei): ?>
    <p class="wl-note warn"><?= h(wl_t($kurs ? 'vorbei_kurs' : 'vorbei_event')) ?></p>
  <?php endif; ?>

  <?php /* Ruhige Fakten-ZEILEN (Symbol + Wert), KEINE Chips: Unterhalb des Bilds wirken die
           wie Streuware. Kleinkram (barrierefrei, ab X, offenes Ende, Einstieg) sammelt sich
           in EINER Extras-Zeile, statt je ein eigenes Etikett zu bekommen. */ ?>
  <div class="wl-df-list">
    <?php if ($kurs): ?>
      <div class="wl-df"><?= $icoWied ?><span><b><?= h((string)$it['rhythmus']) ?></b><?php
        if ((int)$it['termine'] > 0): ?> · <?= (int)$it['termine'] ?> <?= h(wl_ist_en() ? 'dates' : 'Termine') ?><?php endif; ?></span></div>
      <?php if (trim((string)$it['von_datum']) !== ''): ?>
        <div class="wl-df"><?= $icoKal ?><span>ab <?= h(date('j.n.Y', (int)strtotime((string)$it['von_datum']))) ?><?php
          if (trim((string)$it['bis_datum']) !== ''): ?> bis <?= h(date('j.n.Y', (int)strtotime((string)$it['bis_datum']))) ?><?php endif; ?></span></div>
      <?php endif; ?>
    <?php else: ?>
      <div class="wl-df"><?= $icoKal ?><span><b><?= h(wl_datum_lang((string)$it['starts_at'], (string)$it['ends_at'])) ?></b></span></div>
    <?php endif; ?>

    <?php if (trim((string)$it['ort']) !== ''): ?>
      <div class="wl-df"><?= $icoPin ?><span><?= h((string)$it['ort']) ?><?php
        if (trim((string)$it['adresse']) !== ''): ?>, <?= h((string)$it['adresse']) ?><?php endif; ?></span></div>
    <?php endif; ?>

    <?php $preis = wl_preis_text($it); if ($preis !== ''): ?>
      <div class="wl-df"><?= $icoTicket ?><span><b><?= h($preis) ?></b></span></div>
    <?php endif; ?>

    <?php /* Studi-Rabatt: nur wenn wirklich ein Preis dransteht – ein Rabatt
             auf „Eintritt frei" wäre Unsinn, selbst wenn im Feld etwas stünde. */
    $rabatt = trim((string)($it['studi_rabatt'] ?? ''));
    if ($rabatt !== '' && trim((string)($it['preis'] ?? '')) !== ''): ?>
      <div class="wl-df"><?= $ico('<circle cx="12" cy="12" r="9"/><path d="m9 15 6-6"/><path d="M9.5 9.5h.01M14.5 14.5h.01"/>') ?><span><b><?= h(wl_ist_en() ? 'For students:' : 'Für Studis:') ?></b> <?= h($rabatt) ?></span></div>
    <?php endif; ?>

    <?php /* „Nur für Studierende": bewusst eine EIGENE Zeile und nicht in der
             Extras-Sammelzeile unten – das ist keine Randnotiz wie „barrierefrei", sondern
             entscheidet, ob man überhaupt hingehen kann. */
    if ((int)($it['nur_studis'] ?? 0) === 1): ?>
      <div class="wl-df"><?= $ico('<path d="M22 9 12 5 2 9l10 4 10-4v6"/><path d="M6 10.6V16c0 1 2.7 3 6 3s6-2 6-3v-5.4"/>') ?><span><b><?= h(wl_t('nur_studis')) ?></b> · <?= h(wl_t('nur_studis_hint')) ?></span></div>
    <?php endif; ?>

    <?php
    $extras = [];
    if (!$kurs && trim((string)$it['ends_at']) === '') $extras[] = wl_t('offenes_ende');
    if ($kurs && (int)$it['einstieg'] === 1)           $extras[] = wl_t('einstieg');
    if ((int)$it['barrierefrei'] === 1)                $extras[] = wl_t('barrierefrei');
    if ((int)$it['ab_alter'] > 0)                      $extras[] = wl_ist_en()
        ? (int)$it['ab_alter'] . '+'
        : 'ab ' . (int)$it['ab_alter'] . ' Jahren';
    if ($extras): ?>
      <div class="wl-df"><?= $icoCheck ?><span><?= h(implode(' · ', $extras)) ?></span></div>
    <?php endif; ?>
  </div>

  <?php /* EIN Hauptknopf, der Rest tritt zurück: Anmeldung schlägt Kalender schlägt Mehr
           Infos – sonst stehen hier bis zu fünf gleichlaute Knöpfe nebeneinander. Die Reihe
           steht VOR dem Fließtext, damit sie
           ohne Scrollen sichtbar ist; bei Vergangenem entfallen Anmeldung/Kalender/Wecker. */
        $anm     = ($vorbei || $abgesagt) ? '' : trim((string)$it['anmeldung_url']);
        $info    = trim((string)$it['url']);
        $primaer = $anm !== '' ? 'anm' : (!$kurs && !$vorbei && !$abgesagt ? 'kal' : ($info !== '' ? 'info' : '')); ?>
  <div class="wl-btns wl-det-akt">
    <?php if ($anm !== ''): ?>
      <a class="wl-btn p" href="<?= h($anm) ?>" rel="noopener"><?= $icoExt ?><?= h(wl_t('btn_anmeldung')) ?></a>
    <?php endif; ?>
    <?php if (!$kurs && !$vorbei && !$abgesagt): ?>
      <a class="wl-btn<?= $primaer === 'kal' ? ' p' : '' ?>" href="ics.php?id=<?= $id ?>"><?= $icoPlus ?><?= h(wl_t('btn_kalender')) ?></a>
    <?php endif; ?>
    <?php if ($erinnerbar): ?>
      <?php /* Mitteilung 2 Std. vor Beginn (ohne Uhrzeit: am Tag um 9) – siehe wl_remind_at().
               Zwei Spans, das Stylesheet blendet je nach .on die passende Beschriftung ein. */ ?>
      <button type="button" class="wl-btn wl-push-btn" data-art="remind" data-item="<?= $id ?>" aria-pressed="false">
        <span class="pa"><?= $icoGlocke ?><?= h(wl_t('btn_erinnern')) ?></span><span class="pb"><?= $icoStill ?><?= h(wl_t('btn_erinn_aus')) ?></span>
      </button>
    <?php endif; ?>
    <?php if ($info !== ''): ?>
      <a class="wl-btn<?= $primaer === 'info' ? ' p' : '' ?>" href="<?= h($info) ?>" rel="noopener"><?= $icoExt ?><?= h(wl_t('btn_infos')) ?></a>
    <?php endif; ?>
    <?php /* Weitersagen: Wo das Gerät ein Teilen-Blatt hat (Handys, auch mancher Desktop),
             öffnet der Knopf es DIREKT (navigator.share, Skript unten) – ein Tipp, fertig.
             Ohne Teilen-Blatt führt er zur Teilen-Seite mit Material und QR. */ ?>
    <a class="wl-btn" id="wl-teilen" href="teilen.php?e=<?= $id ?>"
       data-titel="<?= h((string)$it['title']) ?>"
       data-text="<?= h((string)$it['title'] . ($kurs ? '' : ' – ' . wl_datum_lang((string)$it['starts_at'], (string)$it['ends_at']))) ?>"
       data-url="<?= h(wl_share_base() . '/v.php?id=' . $id) ?>"><?= $icoTeilen ?><?= h(wl_t('btn_teilen')) ?></a>
  </div>

  <?php /* Der Kalender-Knopf ist der direkte ics-Link und bleibt es – ein Desktop-MENÜ mit
           Google/Outlook/Apple im Dialog kostet nur einen Klick mehr. */ ?>
  <?php if (trim((string)$it['text']) !== ''): ?>
    <p class="wl-text"><?= h((string)$it['text']) ?></p>
  <?php endif; ?>
  <?php /* Der englische Zweizeiler steht als eigener Block dabei – NICHT statt des deutschen
           Textes: Übersetzt wird nur die Kurzfassung, alles Weitere bleibt auf Deutsch. Er
           erscheint auch dann, wenn es gar keinen deutschen Fließtext gibt. */
        if (wl_en_an() && $enTeaser !== ''): ?>
    <p class="wl-text wl-text-en" lang="en"><strong><?= h($enTitel !== '' ? $enTitel : (string)$it['title']) ?></strong> – <?= h($enTeaser) ?></p>
  <?php endif; ?>

  <?php if ($img && trim((string)$img['credit']) !== ''): ?>
    <p class="wl-credit">Bild: <?= h((string)$img['credit']) ?></p>
  <?php elseif ($std): ?>
    <?php /* Beim Standard-Bild ist die Zeile PFLICHT, nicht Höflichkeit: Zwei der Aufnahmen
             stehen unter CC BY, und die verlangt die Namensnennung dort, wo das Bild zu sehen
             ist. Der Link führt zum Fundort – dort steht die Lizenz zum Nachlesen. */ ?>
    <p class="wl-credit">Bild: <a href="<?= h((string)$std['quelle']) ?>" rel="noopener"><?= h((string)$std['credit']) ?></a></p>
  <?php endif; ?>

  <?php if ($org): ?>
    <div class="wl-org">
      <?= wl_org_avatar($org) ?>
      <div>
        <?php /* Der Name führt zur Visitenkarte des Veranstalters – dort stehen Profil,
                 kommende Termine und der Abo-Knopf noch einmal. */ ?>
        <strong><a href="veranstalter.php?id=<?= (int)$org['id'] ?>"><?= h((string)$org['name']) ?></a></strong>
        <div class="wl-org-m">
          <?= h((string)(wl_org_arten()[(string)$org['art']]['label'] ?? 'Veranstalter')) ?>
          <?php if ($weitere > 0): ?>
            · <a href="index.php?org=<?= (int)$org['id'] ?>"><?= $weitere ?> weitere <?= $weitere === 1 ? 'Veranstaltung' : 'Veranstaltungen' ?></a>
          <?php endif; ?>
        </div>
      </div>
      <button type="button" class="wl-btn s wl-push-btn" data-art="follow" data-org="<?= (int)$org['id'] ?>" data-cat="" aria-pressed="false">
        <span class="pa">+ Abonnieren</span><span class="pb">✓ Abonniert</span>
      </button>
    </div>
    <p class="wl-push-hint">Abonnieren heißt: eine Mitteilung, sobald diese Gruppe etwas Neues einträgt.
      Verwalten lässt sich alles unter <a href="abos.php">Erinnerungen &amp; Abos</a>.</p>
  <?php endif; ?>
</article>

<?php
// Strukturierte Daten für Suchmaschinen (schema.org/Event): Google
// zeigt Events mit diesen Angaben direkt in der Event-Suche – „was geht heute in Landau" ist
// genau die Anfrage, die diese Seite gewinnen will. Nur echte, öffentliche Events (keine
// Kurse – die haben kein startDate – und keine Freigabe-Vorschau). Der Block wird vom
// Browser nie ausgeführt, nur von Crawlern gelesen; JSON_HEX_TAG entschärft ein „</script>"
// in Nutzertexten.
if (!$kurs && !$vorschau):
    $ldBase = wl_base_url();
    $ldTz   = new DateTimeZone('Europe/Berlin');
    $ldIso  = static function (string $v) use ($ldTz): string {
        $d = date_create($v, $ldTz);
        return $d ? $d->format('c') : '';
    };
    $ld = [
        '@context'            => 'https://schema.org',
        '@type'               => 'Event',
        'name'                => (string)$it['title'],
        'startDate'           => $ldIso((string)$it['starts_at']),
        'eventStatus'         => 'https://schema.org/' . ($abgesagt ? 'EventCancelled' : 'EventScheduled'),
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location'            => [
            '@type'   => 'Place',
            'name'    => trim((string)$it['ort']) !== '' ? trim((string)$it['ort']) : wl_ort_lang(),
            'address' => array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => trim((string)$it['adresse']),
                'addressLocality' => wl_ort_lang(),
                'addressCountry'  => 'DE',
            ], static fn ($v) => $v !== ''),
        ],
    ];
    if (trim((string)$it['ends_at']) !== '') $ld['endDate'] = $ldIso((string)$it['ends_at']);
    if ($beschr !== '') $ld['description'] = $beschr;
    if ($org) {
        $ldOrg = ['@type' => 'Organization', 'name' => (string)$org['name']];
        if ($ldBase !== '') $ldOrg['url'] = $ldBase . '/veranstaltungen/veranstalter.php?id=' . (int)$org['id'];
        $ld['organizer'] = $ldOrg;
    }
    if ($ldBase !== '') {
        $ld['url'] = $ldBase . '/veranstaltungen/v.php?id=' . $id;
        if ($img)      $ld['image'] = [$ldBase . '/veranstaltungen/' . wl_img_url((string)$img['file'], true)];
        elseif ($std)  $ld['image'] = [$ldBase . '/assets/wl-standard/' . (string)$std['file']];
    }
    // Preis nur, wo er eindeutig ist: „Eintritt frei" wird zur 0, ein glatter Euro-Betrag zur
    // Zahl – Freitexte wie „Spende" oder Spannen lassen das Feld lieber weg als raten.
    $ldPreis = trim((string)$it['preis']);
    if ($ldPreis === '' && (int)$it['frei'] === 1) {
        $ld['offers'] = ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'];
    } elseif (preg_match('/^(\d+(?:[.,]\d{1,2})?)\s*€$/u', $ldPreis, $ldM)) {
        $ld['offers'] = ['@type' => 'Offer', 'price' => str_replace(',', '.', $ldM[1]), 'priceCurrency' => 'EUR'];
    }
    if (isset($ld['offers']) && $ldBase !== '') $ld['offers']['url'] = $ld['url'];
?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<?php /* Melden: ein <details> statt eines eigenen Formulars auf eigener Seite – ohne Skript,
         damit es auch unter der strengen CSP und ohne JavaScript funktioniert. Klein und am
         Fuß der Seite: Es soll auffindbar sein, aber nicht wie eine Einladung wirken. */ ?>
<div class="wl-melden">
  <?php if ($meldeOk): ?>
    <p class="wl-note ok"><strong><?= h(wl_t('melden_danke')) ?></strong> <?= h(wl_t('melden_danke_t')) ?></p>
  <?php else: ?>
    <details<?= $meldeAuf ? ' open' : '' ?>>
      <summary><?= h(wl_t('melden_auf')) ?></summary>
      <?php if ($meldeFehler !== ''): ?><p class="wl-note bad"><?= h($meldeFehler) ?></p><?php endif; ?>
      <form method="post" action="v.php?id=<?= $id ?>#melden" id="melden" class="wl-melden-f">
        <?= wl_csrf_field() ?><input type="hidden" name="was" value="melden">
        <label for="m-grund"><?= h(wl_t('melden_was')) ?></label>
        <select name="grund" id="m-grund">
          <?php foreach (wl_report_gruende() as $k => $label): ?>
            <option value="<?= h($k) ?>"<?= wl_param($_POST['grund'] ?? '') === $k ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <label for="m-text"><?= h(wl_t('melden_genau')) ?> <span><?= h(wl_t('freiwillig')) ?></span></label>
        <textarea name="text" id="m-text" maxlength="1000" rows="3"><?= h(wl_param($_POST['text'] ?? '')) ?></textarea>
        <label for="m-mail"><?= h(wl_t('melden_mail')) ?> <span><?= h(wl_t('melden_mail_h')) ?></span></label>
        <input type="email" name="mail" id="m-mail" maxlength="200" value="<?= h(wl_param($_POST['mail'] ?? '')) ?>">
        <button class="wl-btn" type="submit"><?= h(wl_t('melden_btn')) ?></button>
      </form>
    </details>
  <?php endif; ?>
</div>

<script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>">
// Teilen ohne Umweg: Hat das Gerät ein Teilen-Blatt, öffnet der Knopf
// es direkt – der Link zur Teilen-Seite bleibt als Rückfall für alle anderen stehen.
document.addEventListener('DOMContentLoaded', function () {
  var t = document.getElementById('wl-teilen');
  if (!t || !navigator.share) return;
  t.addEventListener('click', function (e) {
    e.preventDefault();
    navigator.share({
      title: t.getAttribute('data-titel'),
      text:  t.getAttribute('data-text'),
      url:   t.getAttribute('data-url')
    }).catch(function () {});   // Abbrechen des Blatts ist kein Fehler
  });
});
</script>

<?php wl_foot(); ?>
