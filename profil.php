<?php
/**
 * Nutzerprofil: „Wer ist das eigentlich?" – für alle angemeldeten Mitglieder sichtbar.
 * Zeigt Avatar in voller Montur, Pronomen, Sonderfunktion (App-Rolle), Referat mit
 * persönlicher Beschreibung, „Über mich", E-Mail sowie die öffentlich Sichtbaren aus dem
 * Gamification-Teil (freigeschaltete sichtbare Achievements + laufende Streak). Geheime
 * Achievements und Scores bleiben privat. Die eigenen Texte pflegt jede:r hier selbst;
 * profil.php ohne id öffnet das eigene Profil. Verlinkt aus allen Namens-/Chip-Stellen
 * (member_link()/member_chip_link()) und der Mitgliederliste (mitglieder.php).
 */
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) $id = $meId; // ohne id: das eigene Profil (Technik-Login hat keins → 404 unten)
$p = $id > 0 ? member_get($id) : null;
$isOwn = $p && $meId > 0 && $meId === (int)$p['id'];
// Inaktive (ehemalige) Mitglieder haben kein öffentliches Profil – nur Vorsitz/Admin sieht es noch
if ($p && empty($p['active']) && !$isOwn && !can_admin()) $p = null;
if (!$p) { http_response_code(404); page_header('Profil'); echo '<p class="empty"><i class="ti ti-user-off"></i> Dieses Profil gibt es nicht (mehr).</p>'; page_footer(); exit; }

// Wer die betroffene Pinnwand direkt ansieht (z. B. per Push-Klick aufs profil.php?id=…#pinnwand), hat den
// zugehörigen Dashboard-Hinweis gesehen → als gelesen markieren. Nicht während des Rundgangs (asta_tour-Cookie).
if ($me && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_COOKIE['asta_tour'])) {
    dm_mark_read_for_link($meId, 'profil.php?id=' . (int)$p['id'] . '#pinnwand');
    if ($isOwn) dm_mark_read_for_link($meId, 'profil.php#pinnwand'); // migrierter Alt-Link ohne id
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile_texts' && $isOwn) {
    check_csrf();
    member_save_profile_texts($meId, (string)($_POST['referat_desc'] ?? ''), (string)($_POST['about_me'] ?? ''));
    flash('Profil gespeichert.', 'success');
    redirect('profil.php');
}

// Pronomen speichern (festes Set, siehe pronoun_first_options()/pronoun_second_options())
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pronouns' && $isOwn) {
    check_csrf();
    $pr = trim((string)($_POST['pron_first'] ?? '')) . '/' . trim((string)($_POST['pron_second'] ?? ''));
    if (!pronoun_valid($pr)) { flash('Bitte beide Teile der Pronomen wählen.', 'error'); redirect('profil.php#pronomen'); }
    db()->prepare('UPDATE members SET pronouns = ? WHERE id = ?')->execute([$pr, $meId]);
    flash('Pronomen gespeichert.', 'success');
    redirect('profil.php');
}

// Geburtstag speichern (freiwillig, nur Tag + Monat – „–/–" löscht den Eintrag wieder)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_birthday' && $isOwn) {
    check_csrf();
    $bd = (int)($_POST['bday_day'] ?? 0); $bm = (int)($_POST['bday_month'] ?? 0);
    if (($bd > 0) !== ($bm > 0)) { flash('Bitte Tag UND Monat wählen (oder beides auf „–" zum Entfernen).', 'error'); redirect('profil.php#pronomen'); }
    member_set_birthday($meId, $bd, $bm)
        ? flash($bd > 0 ? 'Geburtstag gespeichert. 🎂' : 'Geburtstag entfernt.', 'success')
        : flash('Das ist kein gültiges Datum.', 'error');
    redirect('profil.php#pronomen');
}

// Telefonnummer speichern (freiwillig; leere Nummer entfernt Nummer UND Spielregel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_phone' && $isOwn) {
    check_csrf();
    $tel = (string)($_POST['phone'] ?? '');
    member_set_phone($meId, $tel, (string)($_POST['phone_note'] ?? ''))
        ? flash(trim($tel) === '' ? 'Telefonnummer entfernt.' : 'Telefonnummer gespeichert – sie bleibt für andere verborgen, bis sie deiner Bitte zustimmen.', 'success')
        : flash('Das sieht nicht nach einer Telefonnummer aus. Erlaubt sind Ziffern, Leerzeichen und + ( ) / - .', 'error');
    redirect('profil.php#telefon');
}

// „Keine Nummer hinterlegen" – der zweite Knopf statt Speichern. Eine vollwertige Antwort:
// räumt Nummer und Bitte weg, steht sichtbar im Profil und nimmt den Hinweis vom Dashboard.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_phone_none' && $isOwn) {
    check_csrf();
    member_set_phone($meId, '', '', true);
    flash('Notiert: Du möchtest keine Telefonnummer hinterlegen. Das steht so in deinem Profil – der Hinweis auf dem Dashboard ist damit erledigt.', 'success');
    redirect('profil.php#telefon');
}

/* Nummer herausgeben – erst NACH der Zusage im Dialog. Bewusst ein eigener Aufruf statt einer
   versteckten Stelle im HTML: Was nie mitgeschickt wird, kann man auch nicht im Quelltext
   nachschlagen. Nur für angemeldete Mitglieder, nur mit gültigem CSRF-Token. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'phone_reveal' && $me) {
    check_csrf();
    header('Content-Type: application/json; charset=utf-8');
    $tel = member_phone($p);
    echo json_encode(['ok' => $tel !== '', 'phone' => $tel, 'dial' => phone_dial($tel)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Pinnwand: netten Eintrag hinterlassen (nur auf fremden Profilen; wählbar anonym oder mit Namen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pin_add' && $me && !$isOwn) {
    check_csrf();
    if (profile_post_add((int)$p['id'], $meId, (string)($_POST['body'] ?? ''), ($_POST['signed'] ?? '') === 'anon')) {
        flash('Danke – dein Eintrag steht auf der Pinnwand.', 'success');
    } else {
        flash('Eintrag nicht möglich – bitte einen Text eingeben.', 'error');
    }
    redirect('profil.php?id=' . (int)$p['id'] . '#pinnwand');
}
// Pinnwand: auf einen Eintrag antworten (nur die zwei Beteiligten – Profil-Inhaber:in und Autor:in)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pin_reply' && $me) {
    check_csrf();
    if (profile_post_reply((int)($_POST['parent_id'] ?? 0), $meId, (string)($_POST['body'] ?? ''))) {
        flash('Antwort gepostet.', 'success');
    } else {
        flash('Antworten dürfen nur die beiden Beteiligten (mit Text).', 'error');
    }
    redirect('profil.php?id=' . (int)$p['id'] . '#pinnwand');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pin_delete' && $me) {
    check_csrf();
    if (profile_post_delete((int)($_POST['post_id'] ?? 0), $me)) flash('Eintrag entfernt.', 'success');
    else flash('Entfernen dürfen nur Autor:in, Profil-Inhaber:in und Admin.', 'error');
    redirect('profil.php?id=' . (int)$p['id'] . '#pinnwand');
}
// Props geben (anonym; nur auf FREMDEN Profilen). PRG gegen Doppel-POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'give_kudo' && $me && !$isOwn) {
    check_csrf();
    if (kudos_give($meId, (int)$p['id'])) flash('Props gesendet 🙌 – anonym, ganz ohne deinen Namen.', 'success');
    else flash('Das ging nicht: dein Monatskontingent ist aufgebraucht oder diese Person hat diesen Monat schon Props von dir.', 'error');
    redirect('profil.php?id=' . (int)$p['id']);
}

$pid = (int)$p['id'];
// Props-Status für den beschrifteten Button im Kopf (nur auf fremden Profilen relevant)
$kQuota = ($me && !$isOwn) ? kudos_quota_left($meId) : 0;
$kGiven = ($me && !$isOwn) && in_array($pid, kudos_given_ids($meId), true);
$codes = member_achievement_codes($pid);
$royal = in_array('spitze', $codes, true);
$catalog = achievements_catalog();
$tiers = achievement_tier_meta();
$earnedVisible = []; $secretCount = 0;
foreach ($codes as $c) {
    if (!isset($catalog[$c])) continue;
    if (!empty($catalog[$c]['hidden'])) { $secretCount++; continue; } // geheim bleibt geheim – nur die Anzahl
    $earnedVisible[$c] = $catalog[$c];
}
$streak = member_streak($pid);
$stCur = (int)$streak['current'];
$stTier = streak_tier($stCur);
$stStyle = member_flame_style($pid); // '' = Flamme, sonst ein Key aus flame_styles_all() – ändert auch das Wording
$referat = trim((string)($p['referat'] ?? ''));
$refDesc = trim((string)($p['referat_desc'] ?? ''));
$aboutMe = trim((string)($p['about_me'] ?? ''));
$mail = trim((string)($p['email'] ?? ''));
$joined = trim((string)($p['joined_at'] ?? ''));
// Royaler Titel wie in der Hall of Fame (King/Queen je nach erstem Pronomen)
$p0 = strtolower(explode('/', trim((string)($p['pronouns'] ?? '')))[0]);
$royalTitle = $royal ? ($p0 === 'er' ? 'King ' : ($p0 === 'sie' ? 'Queen ' : '')) : '';

page_header('Profil – ' . short_name((string)$p['name']));
?>
<p class="small"><a href="mitglieder.php">‹ Alle Mitglieder</a></p>

<?php /* Die Streak-Aura gehört auch hierher: Auf einem Profil SIEHT man, wie weit jemand
         ist – dort sagt sie also genau das, was man wissen will.
         Gerechnet wird sie mit den Werten der angezeigten Person ($stStyle/$stTier, nicht den
         eigenen); unter Stufe 4 liefern beide Helfer leere Zeichenketten. */ ?>
<div class="card event-hero profil-hero<?= streak_aura_class($stStyle, $stTier) ?>">
  <?= streak_aura_html($stStyle, $stTier) ?>
  <div class="profil-head">
    <?php /* Derselbe Status-Ring wie im Dashboard-Hero: der Royal-Puls der Spitzenklasse, und
         sonst keiner. Gerechnet für die ANGEZEIGTE Person. */ ?>
        <span class="profil-av"><?= avatar_bubble($p, avatar_ring_class($pid)) ?></span>
    <div class="profil-id">
      <h1 class="event-title" style="margin:0"><?php if ($royal): ?><i class="ti ti-crown" style="color:#d9971a" title="Spitzenklasse 👑"></i> <?php endif; ?><?= h($royalTitle . (string)$p['name']) ?></h1>
      <div class="event-facts" style="margin-top:.35rem">
        <?php if (trim((string)($p['pronouns'] ?? '')) !== ''): ?><span class="fact"><i class="ti ti-speakerphone"></i> <?= h((string)$p['pronouns']) ?></span><?php endif; ?>
        <?php if ($referat !== ''): ?><span class="fact"><i class="ti ti-briefcase"></i> Referat <?= h($referat) ?></span><?php endif; ?>
        <?php if ($joined !== ''): ?><span class="fact"><i class="ti ti-calendar-heart"></i> im AStA seit <?= h(fmt_date($joined)) ?></span><?php endif; ?>
        <?php $bdayLabel = birthday_label((string)($p['birthday'] ?? '')); if ($bdayLabel !== ''): ?><span class="fact">🎂 <?= h($bdayLabel) ?></span><?php endif; ?>
        <?php $curAbs = member_current_absence($pid); if ($curAbs): ?><span class="fact">🌴 abwesend bis <?= h(fmt_date((string)$curAbs['ends_at'])) ?></span><?php endif; ?>
        <?php if (empty($p['active'])): ?><span class="fact"><i class="ti ti-user-off"></i> nicht mehr aktiv</span><?php endif; ?>
      </div>
      <?php $badge = member_role_badges($p); if ($badge !== ''): ?><div style="margin-top:.5rem"><?= $badge ?></div><?php endif; ?>
    </div>
    <?php if ($mail !== '' || ($me && !$isOwn) || member_phone($p) !== '' || member_phone_optout($p)): ?>
    <div class="profil-actions">
      <?php if ($mail !== ''): ?>
        <a class="btn secondary small profil-mail" href="mailto:<?= h($mail) ?>"><i class="ti ti-mail"></i> E-Mail schreiben</a>
      <?php endif; ?>
      <?php /* Telefonnummer: Auf fremden Profilen steht hier NUR ein Knopf – die Nummer selbst
               steckt nicht im Quelltext, sie wird erst nach der Zusage im Dialog geholt. */ ?>
      <?php if ($me && !$isOwn && member_phone($p) !== ''): ?>
        <button type="button" class="btn secondary small profil-tel" data-tel-id="<?= (int)$p['id'] ?>"
                data-tel-name="<?= h(first_name((string)$p['name'])) ?>" data-tel-note="<?= h(member_phone_note($p)) ?>">
          <i class="ti ti-phone"></i> Telefonnummer
        </button>
      <?php elseif ($isOwn && member_phone($p) !== ''): ?>
        <a class="btn secondary small profil-tel" href="tel:<?= h(phone_dial(member_phone($p))) ?>" title="Deine eigene Nummer – andere sehen sie erst nach Zustimmung"><i class="ti ti-phone"></i> <?= h(member_phone($p)) ?></a>
      <?php elseif (member_phone_optout($p)): ?>
        <?php /* Die bewusste Absage gehört sichtbar ins Profil – sonst fragt trotzdem jemand nach. */ ?>
        <span class="btn secondary small profil-tel" style="cursor:default" title="Diese Person möchte nicht telefonisch kontaktiert werden"><i class="ti ti-phone-off"></i> Lieber nicht telefonisch</span>
      <?php endif; ?>
      <?php if ($me && !$isOwn): ?>
        <?php if ($kGiven): ?>
          <button class="btn secondary small profil-props" disabled title="Diesen Monat schon Props gegeben"><i class="ti ti-check"></i> Props gegeben 🙌</button>
        <?php elseif ($kQuota > 0): ?>
          <form method="post" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="give_kudo">
            <button type="submit" class="btn secondary small profil-props" title="Anonym · 2 Props pro Monat"><span aria-hidden="true">🙌</span> Props geben</button>
          </form>
        <?php else: ?>
          <button class="btn secondary small profil-props" disabled title="Dein Monatskontingent an Props ist aufgebraucht"><span aria-hidden="true">🙌</span> Props aufgebraucht</button>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isOwn): ?>
  <div class="card" style="border-left:4px solid var(--petrol)">
    <p class="small" style="margin:0"><i class="ti ti-eye" style="color:var(--petrol)"></i> So sehen die anderen dein Profil. <strong>Texte und Pronomen</strong> bearbeitest du unten,
    <strong>Schmuck &amp; Avatar-Farbe</strong> auf der <a href="achievements.php">Achievements-Seite</a>.</p>
    <?php $offenHier = profile_todo($p); if ($offenHier): ?>
      <p class="small muted" style="margin:.4rem 0 0"><i class="ti ti-pencil" style="color:var(--amber)"></i> Noch offen:
        <?php foreach ($offenHier as $i => $po): ?><?= $i ? ' · ' : '' ?><a href="#<?= h($po['anchor']) ?>"><?= h($po['label']) ?></a><?php endforeach; ?>. Freiwillig – zählt in keine Aufgabe und keinen Score.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($refDesc !== '' || $aboutMe !== '' || $isOwn): ?>
  <?php
    // Auf dem EIGENEN Profil führt neben jeder Überschrift ein Knopf nach unten zum passenden
    // Feld (und setzt den Schreibcursor hinein) – die Texte stehen oben, bearbeitet werden sie
    // ganz unten; ohne den Weg dorthin sucht man.
    $bearbeiten = fn(string $ziel) => $isOwn
        ? '<a class="btn secondary small sec-edit" href="#' . $ziel . '" data-fokus="' . $ziel . '"><i class="ti ti-pencil"></i> Bearbeiten</a>'
        : '';
  ?>
  <?php if ($referat !== '' && ($refDesc !== '' || $isOwn)): ?>
    <div class="section-title"><i class="ti ti-briefcase"></i> Was ich im Referat <?= h($referat) ?> mache<?= $bearbeiten('pf_ref') ?></div>
    <div class="card">
      <?php if ($refDesc !== ''): ?><p style="margin:0;white-space:pre-line"><?= h($refDesc) ?></p>
      <?php else: ?><p class="empty small" style="margin:0">Noch keine Beschreibung – erzähl unten kurz, worum sich dein Referat kümmert.</p><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($aboutMe !== '' || $isOwn): ?>
    <div class="section-title"><i class="ti ti-user-heart"></i> Über <?= $isOwn ? 'mich' : h(first_name((string)$p['name'])) ?><?= $bearbeiten('pf_about') ?></div>
    <div class="card">
      <?php if ($aboutMe !== ''): ?><p style="margin:0;white-space:pre-line"><?= h($aboutMe) ?></p>
      <?php else: ?><p class="empty small" style="margin:0">Noch nichts eingetragen.</p><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="section-title"><i class="ti ti-trophy"></i> Erfolge</div>
<div class="card">
  <?php if ($stCur >= 3 || $earnedVisible || $secretCount): ?>
    <?php if ($stCur >= 3): ?>
      <p style="margin:0 0 <?= $earnedVisible ? '.8rem' : '0' ?>">
        <span class="profil-flame st<?= $stTier ?><?= $stTier ? ' lit' : '' ?><?= $stStyle ? ' ' . h($stStyle) : '' ?>"><?= flame_svg($stStyle) ?></span>
        <strong><?= $stCur ?></strong> <?= h(flame_style_word($stStyle, $stCur)) ?> aktuelle Streak<?php if ((int)$streak['best'] > $stCur): ?> <span class="muted small">(Bestmarke: <?= (int)$streak['best'] ?>)</span><?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($earnedVisible): ?>
      <div class="profil-achs">
        <?php foreach ($earnedVisible as $c => $a): $tm = $tiers[(string)$a['tier']] ?? ['color' => 'var(--muted)']; ?>
          <span class="pill profil-ach" style="--tier:<?= h((string)$tm['color']) ?>" title="<?= h((string)$a['desc']) ?>"><i class="ti <?= h((string)$a['icon']) ?>"></i> <?= h((string)$a['title']) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($secretCount): ?><p class="small muted" style="margin:.6rem 0 0"><i class="ti ti-lock"></i> Dazu <?= $secretCount ?> geheime<?= $secretCount === 1 ? 's' : '' ?> Achievement<?= $secretCount === 1 ? '' : 's' ?> entdeckt.</p><?php endif; ?>
  <?php else: ?>
    <p class="empty" style="margin:0"><i class="ti ti-seedling"></i> Hier wächst noch was – die ersten Erfolge kommen bestimmt.</p>
  <?php endif; ?>
  <?php if ($isOwn): $next = member_next_achievement($meId); ?>
    <?php if ($next): $ntm = $tiers[$next['tier']] ?? ['color' => 'var(--muted)']; ?>
      <p class="small" style="margin:.8rem 0 0">
        <i class="ti ti-target-arrow" style="color:var(--petrol)"></i> <strong>Dein nächstes Achievement</strong> <span class="muted">(nur für dich sichtbar)</span>:
        <span class="pill profil-ach" style="--tier:<?= h((string)$ntm['color']) ?>"><i class="ti <?= h($next['icon']) ?>"></i> <?= h($next['title']) ?></span>
        <strong><?= (int)$next['current'] ?> / <?= (int)$next['target'] ?></strong> – noch <?= (int)$next['target'] - (int)$next['current'] ?> bis zur Freischaltung.
      </p>
    <?php endif; ?>
    <?php $myProps = kudos_received_total($meId); $propsFrei = props_available($meId); if ($myProps > 0): ?>
      <?php /* Kopfzahl ist das Geschenk, nicht der Kontostand: „bekommen" ist die Anerkennung, „ausgegeben"
               nur eine Fußnote – und die auch erst, wenn wirklich schon etwas eingelöst wurde. */ ?>
      <p class="small" style="margin:.6rem 0 0"><span aria-hidden="true">🙌</span> Du hast insgesamt <strong><?= (int)$myProps ?> Props</strong> bekommen<?php if ($propsFrei !== $myProps): ?>
        <span class="muted">(<?= (int)$myProps - (int)$propsFrei ?> von <?= (int)$myProps ?> ausgegeben)</span><?php else: ?>
        <span class="muted">(nur für dich sichtbar – anonym, du erfährst nie, von wem)</span><?php endif; ?>.
        <?php if ($propsFrei >= PROPS_PREIS): ?><br><span class="muted">Für je <?= PROPS_PREIS ?> kannst du dir im <a href="achievements.php#locker">Belohnungs-Locker</a> etwas aussuchen.</span><?php endif; ?></p>
    <?php endif; ?>
    <p class="small muted" style="margin:.6rem 0 0">Alle Details, Belohnungen und dein Schmuck: <a href="achievements.php">Achievements-Seite</a>. Scores und geheime Achievements sind hier bewusst nicht öffentlich.</p>
  <?php endif; ?>
</div>

<?php $pins = profile_posts_of($pid); ?>
<div class="section-title" id="pinnwand"><i class="ti ti-pin"></i> Pinnwand<?= $pins ? ' <span class="count">' . count($pins) . '</span>' : '' ?> <span class="muted small" style="font-weight:400">– nette Worte von anderen</span></div>
<div class="card">
  <?php if (!$pins): ?>
    <p class="empty" style="margin:0"><i class="ti ti-mailbox-off"></i> Noch keine Einträge<?= $isOwn ? '' : ' – hinterlass doch die ersten netten Worte!' ?></p>
  <?php else: ?>
    <?php
      // Autor:innen-Chip (Anonym / Ehemaliges Mitglied / verlinkter Chip) – für Einträge UND Antworten
      $pinChip = function (array $row) {
          if (!empty($row['anonymous'])) {
              echo '<span class="gt-chip gt-chip-av"><span class="gt-av" style="--av:linear-gradient(135deg,#9aa5b1,#6b7683)">?</span> Anonym</span>';
          } elseif ($row['author_name'] === null) { // Autor:in inzwischen gelöscht – Eintrag bleibt
              echo '<span class="gt-chip gt-chip-av"><span class="gt-av" style="--av:linear-gradient(135deg,#9aa5b1,#6b7683)">–</span> Ehemaliges Mitglied</span>';
          } else {
              echo member_chip_link(['id' => (int)$row['author_id'], 'name' => (string)$row['author_name'],
                  'pronouns' => (string)($row['pronouns'] ?? ''), 'avatar_decos' => (string)($row['avatar_decos'] ?? ''),
                  'avatar_palette' => (string)($row['avatar_palette'] ?? '')]);
          }
      };
      ?>
    <?php foreach ($pins as $pin): $canDel = $isOwn || $meId === (int)$pin['author_id'] || can_admin();
          // Antworten dürfen nur die zwei Beteiligten: Profil-Inhaber:in und Autor:in des Eintrags
          $canReply = $me && ($isOwn || $meId === (int)$pin['author_id']); ?>
      <div class="pin-post">
        <div class="pin-head">
          <?php $pinChip($pin); ?>
          <span class="muted small"><?= h(fmt_date(substr((string)$pin['created_at'], 0, 10))) ?></span>
          <?php if ($canDel): ?>
            <form method="post" style="margin:0 0 0 auto" data-confirm="Diesen Pinnwand-Eintrag entfernen? Antworten darauf verschwinden mit." data-confirm-danger data-confirm-ok="Entfernen">
              <?= csrf_field() ?><input type="hidden" name="action" value="pin_delete"><input type="hidden" name="post_id" value="<?= (int)$pin['id'] ?>">
              <button class="btn danger small" type="submit" title="Eintrag entfernen" aria-label="Eintrag entfernen"><i class="ti ti-trash"></i></button>
            </form>
          <?php endif; ?>
        </div>
        <p class="pin-body" style="white-space:pre-line"><?= h((string)$pin['body']) ?></p>
        <?php foreach ($pin['replies'] as $rep): $canDelR = $isOwn || $meId === (int)$rep['author_id'] || can_admin(); ?>
          <div class="pin-reply">
            <div class="pin-head">
              <i class="ti ti-corner-down-right muted" aria-hidden="true"></i>
              <?php $pinChip($rep); ?>
              <span class="muted small"><?= h(fmt_date(substr((string)$rep['created_at'], 0, 10))) ?></span>
              <?php if ($canDelR): ?>
                <form method="post" style="margin:0 0 0 auto" data-confirm="Diese Antwort entfernen?" data-confirm-danger data-confirm-ok="Entfernen">
                  <?= csrf_field() ?><input type="hidden" name="action" value="pin_delete"><input type="hidden" name="post_id" value="<?= (int)$rep['id'] ?>">
                  <button class="btn danger small" type="submit" title="Antwort entfernen" aria-label="Antwort entfernen"><i class="ti ti-trash"></i></button>
                </form>
              <?php endif; ?>
            </div>
            <p class="pin-body" style="white-space:pre-line"><?= h((string)$rep['body']) ?></p>
          </div>
        <?php endforeach; ?>
        <?php if ($canReply): ?>
          <form method="post" class="pin-replyform">
            <?= csrf_field() ?><input type="hidden" name="action" value="pin_reply"><input type="hidden" name="parent_id" value="<?= (int)$pin['id'] ?>">
            <textarea name="body" rows="1" maxlength="500" required placeholder="Antworten …"></textarea>
            <button class="btn secondary small" type="submit"><i class="ti ti-corner-down-right"></i> Antworten</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($me && !$isOwn): ?>
    <form method="post" style="margin-top:<?= $pins ? '1rem' : '.8rem' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="pin_add">
      <label for="pin_body">Etwas Nettes für <?= h(first_name((string)$p['name'])) ?> <span class="muted small">– max. 500 Zeichen</span></label>
      <textarea name="body" id="pin_body" rows="3" maxlength="500" required placeholder="Danke, Lob, ein schöner Moment …"></textarea>
      <span class="statuspick" style="margin-top:.5rem">
        <label><input type="radio" name="signed" value="name" checked><span class="s-yes">Mit meinem Namen</span></label>
        <label><input type="radio" name="signed" value="anon"><span class="s-maybe">Anonym</span></label>
      </span>
      <div class="btn-row" style="margin-top:.7rem"><button class="btn" type="submit"><i class="ti ti-pin"></i> Auf die Pinnwand</button></div>
    </form>
  <?php elseif ($isOwn): ?>
    <p class="small muted" style="margin:<?= $pins ? '.8rem' : '.6rem' ?> 0 0"><i class="ti ti-info-circle"></i> Das ist deine Pinnwand – andere Mitglieder können dir hier nette Worte hinterlassen (mit Namen oder anonym). Einträge kannst du jederzeit entfernen.</p>
  <?php endif; ?>
</div>

<?php if ($isOwn): $pp = explode('/', trim((string)($p['pronouns'] ?? '')), 2); $pf = $pp[0] ?? ''; $ps = $pp[1] ?? '';
      $bdParts = preg_match('/^(\d{2})-(\d{2})$/', trim((string)($p['birthday'] ?? '')), $bm0) ? [(int)$bm0[2], (int)$bm0[1]] : [0, 0]; ?>
  <div class="section-title" id="pronomen"><i class="ti ti-speakerphone"></i> Meine Pronomen &amp; mein Geburtstag</div>
  <div class="card">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_pronouns">
      <label>Meine Pronomen <span class="small muted">(werden in Redeliste und Profil angezeigt)</span></label>
      <div class="btn-row" style="align-items:center;gap:.4rem">
        <select name="pron_first" aria-label="Pronomen (vorne)">
          <option value="">–</option>
          <?php foreach (pronoun_first_options() as $opt): ?><option value="<?= h($opt) ?>" <?= $pf === $opt ? 'selected' : '' ?>><?= h($opt) ?></option><?php endforeach; ?>
        </select>
        <span style="font-weight:700">/</span>
        <select name="pron_second" aria-label="Pronomen (hinten)">
          <option value="">–</option>
          <?php foreach (pronoun_second_options() as $opt): ?><option value="<?= h($opt) ?>" <?= $ps === $opt ? 'selected' : '' ?>><?= h($opt) ?></option><?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
      </div>
    </form>
    <form method="post" style="margin-top:.9rem">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_birthday">
      <label>Mein Geburtstag <span class="small muted">– freiwillig, nur Tag &amp; Monat (ohne Jahr); erscheint im Profil und am Tag selbst mit 🎂 auf Mitgliederliste und Dashboard</span></label>
      <div class="btn-row" style="align-items:center;gap:.4rem">
        <select name="bday_day" aria-label="Geburtstag (Tag)">
          <option value="0">–</option>
          <?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>" <?= $bdParts[0] === $d ? 'selected' : '' ?>><?= $d ?>.</option><?php endfor; ?>
        </select>
        <select name="bday_month" aria-label="Geburtstag (Monat)">
          <option value="0">–</option>
          <?php for ($mo = 1; $mo <= 12; $mo++): ?><option value="<?= $mo ?>" <?= $bdParts[1] === $mo ? 'selected' : '' ?>><?= h(MONTHS[$mo]) ?></option><?php endfor; ?>
        </select>
        <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
      </div>
    </form>
  </div>

  <div class="section-title" id="telefon"><i class="ti ti-phone"></i> Meine Telefonnummer</div>
  <div class="card">
    <p class="small muted" style="margin:0 0 .7rem">Freiwillig – wie der Geburtstag. Deine Nummer steht <strong>nirgends offen im Profil</strong>: Andere sehen nur einen Knopf und deine Bitte unten. Erst wer im Dialog zustimmt, bekommt die Nummer zu sehen.</p>
    <p class="small" style="margin:0 0 .9rem"><i class="ti ti-lock" style="color:var(--petrol)"></i> <strong>Es gilt außerdem für alle:</strong> Telefonnummern in dieser App sind ausschließlich AStA-intern und dürfen nicht weitergegeben werden – nicht an Externe, nicht in Gruppen oder Chats. Dieser Satz steht in jedem Dialog, auch ohne eigene Bitte.</p>
    <?php if (member_phone_optout($p)): ?>
      <p class="small" style="margin:0 0 .9rem"><span class="pill pill-ok"><i class="ti ti-check"></i> beantwortet</span> Im Profil steht: <strong>Lieber nicht telefonisch</strong>. Du kannst es jederzeit ändern – trag einfach eine Nummer ein und speichere.</p>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_phone">
      <label for="pf_phone">Telefonnummer <span class="small muted">– leer lassen und speichern entfernt sie wieder</span></label>
      <input type="tel" name="phone" id="pf_phone" value="<?= h(member_phone($p)) ?>" maxlength="40" placeholder="z. B. 0151 23456789" autocomplete="tel">
      <label for="pf_phone_note" style="margin-top:.6rem">Wofür dürfen die anderen sie nutzen?</label>
      <textarea name="phone_note" id="pf_phone_note" rows="3" maxlength="300" placeholder="z. B. Nur bei Notfällen oder während Events. Bitte kein WhatsApp, lieber anrufen."><?= h(trim((string)($p['phone_note'] ?? ''))) ?></textarea>
      <p class="small muted" style="margin:.3rem 0 0">Dieser Satz steht im Dialog, bevor jemand die Nummer sieht – schreib ruhig genau hin, was dir wichtig ist.</p>
      <?php /* Zweiter Knopf STATT Speichern: die bewusste Absage. Sie hängt an einem eigenen
               Formular (unten), damit die Rückfrage nur an ihr klebt und nicht am Speichern.
               Über das form-Merkmal steht der Knopf trotzdem in derselben Reihe. */ ?>
      <div class="btn-row" style="margin-top:.9rem">
        <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button>
        <button class="btn secondary" type="submit" form="phoneNoneForm"><i class="ti ti-phone-off"></i> Keine Nummer hinterlegen</button>
      </div>
      <p class="small muted" style="margin:.45rem 0 0">Keine Nummer angeben ist völlig in Ordnung – und zählt als beantwortet: Im Profil steht dann für die anderen, dass du telefonisch nicht erreichbar sein möchtest, und der Hinweis auf dem Dashboard verschwindet.</p>
    </form>
    <form method="post" id="phoneNoneForm" hidden<?= member_phone($p) !== '' ? ' data-confirm="Deine hinterlegte Nummer wird dabei gelöscht. Im Profil steht dann, dass du telefonisch nicht erreichbar sein möchtest." data-confirm-ok="Keine Nummer hinterlegen"' : '' ?>>
      <?= csrf_field() ?><input type="hidden" name="action" value="save_phone_none">
    </form>
  </div>

  <div class="section-title" id="bearbeiten"><i class="ti ti-pencil"></i> Meine Profil-Texte</div>
  <div class="card">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_profile_texts">
      <?php if ($referat !== ''): ?>
        <label for="pf_ref">Was ich im Referat <?= h($referat) ?> mache <span class="muted small">– kurz &amp; verständlich, max. 2000 Zeichen</span></label>
        <textarea name="referat_desc" id="pf_ref" rows="4" maxlength="2000" placeholder="Worum kümmert sich dein Referat? Womit kann man zu dir kommen?"><?= h($refDesc) ?></textarea>
      <?php else: ?>
        <input type="hidden" name="referat_desc" value="<?= h($refDesc) ?>">
        <p class="small muted" style="margin-top:0"><i class="ti ti-info-circle"></i> Du hast aktuell kein Referat hinterlegt – sobald eins eingetragen ist, kannst du hier beschreiben, was du dort machst.</p>
      <?php endif; ?>
      <label for="pf_about" style="margin-top:.6rem">Über mich <span class="muted small">– optional (z. B. Studiengang, Themen, Erreichbarkeit)</span></label>
      <textarea name="about_me" id="pf_about" rows="4" maxlength="2000" placeholder="Erzähl den anderen ein bisschen was über dich …"><?= h($aboutMe) ?></textarea>
      <div class="btn-row" style="margin-top:.8rem"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button></div>
    </form>
  </div>
  <?php
    // Vorschau der eigenen Kachel aus der Mitgliederliste – dieselbe Funktion, die auch die echte
    // Liste rendert (member_card_html), damit hier nichts Erfundenes steht.
    // Bewusst AUSSERHALB der Formular-Karte und in demselben Raster wie die Mitgliederliste
    // (.cardgrid.cardgrid-tiles): nur so rechnet der Browser exakt dieselbe Kachelbreite aus.
    // Innerhalb der Karte ist der Platz um deren Innenabstand schmaler – die Vorschau zeigte
    // dann einen anderen Zeilenumbruch als die echte Liste und löge genau da, wo man hinsieht.
  ?>
  <div class="mem-vorschau">
    <p class="small muted" style="margin:0 0 .5rem"><i class="ti ti-eye"></i> <strong>So sieht deine Kachel</strong> in der <a href="mitglieder.php">Mitgliederliste</a> aus – sie ändert sich beim Tippen mit:</p>
    <div class="cardgrid cardgrid-tiles" id="pf_vorschau">
      <?= member_card_html($p, ['referat' => true, 'viewer' => $meId, 'preview' => true]) ?>
    </div>
  </div>
  <script>
  (function () {
    // Vorschau live mitziehen. Dieselben zwei Regeln wie in member_card_html(): der Referatstext
    // hat Vorrang, „Über mich" rückt nur nach, wenn er fehlt – und Absätze werden zu einfachen
    // Zeilenumbrüchen zusammengezogen (member_card_text() in lib.php).
    var buehne = document.getElementById('pf_vorschau');
    if (!buehne) return;
    var ref = document.getElementById('pf_ref'), about = document.getElementById('pf_about');
    var zitat = buehne.querySelector('.mem-quote'), leer = buehne.querySelector('.mem-leer');
    if (!zitat || !leer) return;
    function zeilen(s) { return s.replace(/(?:[^\S\r\n]*(?:\r\n|[\r\n]))+[^\S\r\n]*/g, '\n').trim(); }
    function male() {
      var r = ref ? zeilen(ref.value) : '', a = about ? zeilen(about.value) : '';
      var t = r !== '' ? r : a, ausRef = r !== '';
      zitat.textContent = t;
      zitat.classList.toggle('mem-quote-ref', ausRef);
      zitat.classList.toggle('mem-quote-about', !ausRef);
      zitat.title = ausRef ? 'Was ich im Referat mache' : 'Über mich';
      zitat.hidden = t === '';
      leer.hidden = t !== '';
    }
    [ref, about].forEach(function (el) { if (el) el.addEventListener('input', male); });
    male();
  })();

  // „Bearbeiten" oben neben den Überschriften: der Link springt zum Feld, hier kommt der
  // Schreibcursor dazu – ans ENDE des Textes, damit man direkt weiterschreiben kann.
  // Bewusst eigenständig: soll auch dann laufen, wenn die Vorschau oben aussteigt.
  (function () {
    document.querySelectorAll('a[data-fokus]').forEach(function (a) {
      a.addEventListener('click', function () {
        var feld = document.getElementById(a.dataset.fokus);
        if (!feld) return;
        // erst springen lassen, dann fokussieren (preventScroll: sonst ruckelt es zweimal)
        setTimeout(function () {
          feld.focus({ preventScroll: true });
          try { feld.setSelectionRange(feld.value.length, feld.value.length); } catch (e) {}
        }, 0);
      });
    });
  })();
  </script>
<?php endif; ?>
<?php
page_footer();
