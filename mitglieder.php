<?php
/**
 * Mitgliederliste („Wer ist wer im AStA"): Ansprechpersonen (Sonderfunktionen) oben, darunter alle
 * übrigen aktiven Mitglieder in EINER Liste – ohne Trenn-Überschriften je Referat (die zogen die
 * Seite in die Länge); das Referat steht auf der Karte. Sortiert nach der Referat-Liste der
 * Verwaltung, damit Kolleg:innen beieinanderstehen; Ansprechpersonen stehen nicht doppelt drin.
 * Status-Badges direkt auf den Karten: 🌴 abwesend, 🎂 heute Geburtstag, „Neu dabei" (< 90 Tage),
 * 🎉 am AStA-Jahrestag. Jede Karte führt zum Nutzerprofil (profil.php). Bewusst ohne Scores/Streaks.
 */
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();
$meId = $me ? (int)$me['id'] : 0;

// „Props" geben (anonym; 2 pro Monat, höchstens 1 je Person). PRG gegen Doppel-POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'give_kudo' && $meId) {
    check_csrf();
    if (kudos_give($meId, (int)($_POST['to_id'] ?? 0))) {
        flash('Props gesendet 🙌 – anonym, ganz ohne deinen Namen.', 'success');
    } else {
        flash('Das ging nicht: entweder ist dein Monatskontingent aufgebraucht oder du hast dieser Person diesen Monat schon Props gegeben.', 'error');
    }
    redirect('mitglieder.php');
}
$myQuota  = kudos_quota_left($meId);   // verbleibende Props diesen Monat
$givenIds = kudos_given_ids($meId);    // wem ich diesen Monat schon Props gab

$members = members_all(); // nur Aktive, sortiert nach sort/name

// Ansprechpersonen: die Sonderfunktionen in fester Reihenfolge
$roleOrder = ['vorsitz' => 0, 'sekretariat' => 1, 'finanzen' => 2, 'admin' => 3];
$contacts = array_values(array_filter($members, fn($m) => isset($roleOrder[(string)($m['role'] ?? 'member')])));
usort($contacts, fn($a, $b) => ($roleOrder[(string)$a['role']] <=> $roleOrder[(string)$b['role']]) ?: strcasecmp((string)$a['name'], (string)$b['name']));

// Alle übrigen Mitglieder in EINER Liste – ohne Trenn-Überschriften je Referat, die zogen die
// Seite unnötig in die Länge. Das Referat steht stattdessen auf jeder Karte. Sortiert bleibt es
// nach der Referat-Liste der Verwaltung, damit Referats-Kolleg:innen beieinanderstehen.
$contactIds = array_map(fn($c) => (int)$c['id'], $contacts);
$byReferat = [];
foreach ($members as $m) $byReferat[trim((string)($m['referat'] ?? ''))][] = $m;
$rest = [];
$nReferate = 0;
foreach (referate_all() as $r) {
    $nm = trim((string)$r['name']);
    if (empty($byReferat[$nm])) continue;
    $nReferate++;
    foreach ($byReferat[$nm] as $m) $rest[] = $m;
    unset($byReferat[$nm]);
}
foreach ($byReferat as $nm => $list) {           // Referat (noch) nicht in der Liste → trotzdem zeigen
    if ($nm === '') continue;
    $nReferate++;
    foreach ($list as $m) $rest[] = $m;
}
foreach ($byReferat[''] ?? [] as $m) $rest[] = $m; // „ohne Referat" ganz ans Ende
// Ansprechpersonen stehen schon oben – ohne die Überschriften wirkten sie hier nur wie Dubletten
$rest = array_values(array_filter($rest, fn($m) => !in_array((int)$m['id'], $contactIds, true)));

// Karten-Renderer: die Kachel selbst steckt in member_card_html() (lib.php), damit die Vorschau
// in der Profil-Bearbeitung garantiert dieselbe zeigt. Props-Stand einmal übergeben statt je Kachel.
$memberCard = function (array $m, bool $showReferat = false) use ($meId, $givenIds, $myQuota): void {
    echo member_card_html($m, ['referat' => $showReferat, 'viewer' => $meId,
                               'given' => $givenIds, 'quota' => $myQuota]);
};

page_header('Mitglieder');
?>
<div class="events-toolbar">
  <h1><i class="ti ti-users-group" style="color:var(--petrol)"></i> Mitglieder</h1>
  <?php if ($meId): ?><a class="btn secondary" href="profil.php"><i class="ti ti-user-circle"></i> Mein Profil</a><?php endif; ?>
</div>
<div class="card">
  <p class="small muted" style="margin:0">Wer ist wer im AStA? <strong><?= count($members) ?> Mitglieder</strong> in <strong><?= $nReferate ?> Referaten</strong> – ein Klick öffnet das <strong>Profil</strong> mit Referatsbeschreibung, „Über mich", Pinnwand und E-Mail. Deine eigenen Texte pflegst du in deinem Profil.</p>
  <?php if ($meId): ?>
    <p class="small muted" style="margin:.55rem 0 0"><span aria-hidden="true">🙌</span> <strong>Props</strong> sind kleine, <strong>anonyme</strong> Dankeschöns: Tippe das 🙌 auf einer Karte, um jemandem zu zeigen, dass du seine Arbeit schätzt. Du hast <strong><?= (int)$myQuota ?> von <?= KUDOS_PER_MONTH ?></strong> Props diesen Monat übrig (höchstens eins pro Person). Die beschenkte Person bekommt nur einen Hinweis – <strong>nie</strong>, von wem.</p>
  <?php endif; ?>
</div>

<?php if ($contacts): ?>
  <div class="section-title"><i class="ti ti-star"></i> Ansprechpersonen</div>
  <div class="cardgrid cardgrid-tiles">
    <?php foreach ($contacts as $c) $memberCard($c, true); ?>
  </div>
<?php endif; ?>

<?php if ($rest): ?>
  <div class="section-title"><i class="ti ti-users-group"></i> <?= $contacts ? 'Weitere Mitglieder' : 'Mitglieder' ?> <span class="count"><?= count($rest) ?></span></div>
  <div class="cardgrid cardgrid-tiles">
    <?php foreach ($rest as $m) $memberCard($m, true); ?>
  </div>
<?php endif; ?>
<?php
page_footer();
