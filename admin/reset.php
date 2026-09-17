<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // nur Admins

/**
 * Löschaktionen. Die settings-Tabelle (Technik-Login, Mail-/Cron-Konfiguration)
 * wird NIE angefasst – der Owner-Login bleibt immer erhalten.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $d = db();
    switch ($action) {
        case 'del_responses':
            $d->exec('DELETE FROM responses');
            flash('Alle Rückmeldungen (Abstimmungen) gelöscht.', 'success');
            break;
        case 'del_events':
            $d->exec('DELETE FROM events');      // event_slots + responses per Cascade
            $d->exec('DELETE FROM reminder_log');
            flash('Alle Events inkl. Termine und Rückmeldungen gelöscht.', 'success');
            break;
        case 'del_meetings':
            $d->exec('DELETE FROM meetings');
            flash('Alle Sitzungen gelöscht.', 'success');
            break;
        case 'del_legislatures':
            // Die Zugehörigkeit steht nirgends gespeichert – sie ergibt sich aus dem Datum
            // (legislature_for()). Ohne jede Periode gibt es folglich gar keine Nummern mehr.
            $d->exec('DELETE FROM legislatures');
            flash('Alle Legislaturperioden gelöscht – die Sitzungen bleiben, tragen aber keine Nummern mehr.', 'success');
            break;
        case 'del_absences':
            $d->exec('DELETE FROM absences');
            flash('Alle Abwesenheiten gelöscht.', 'success');
            break;
        case 'del_drafts':
            $d->exec('DELETE FROM events WHERE draft = 1');   // event_slots/responses per Cascade
            $d->exec('DELETE FROM meetings WHERE draft = 1');
            flash('Alle Entwürfe (Events und Sitzungen) gelöscht.', 'success');
            break;
        case 'del_templates':
            $d->exec('DELETE FROM event_templates');
            flash('Alle Event-Vorlagen gelöscht.', 'success');
            break;
        case 'del_uploads':
            // Physische Dateien entfernen ...
            $dir = upload_dir();
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $p) { if (is_file($p)) @unlink($p); }
            }
            $d->exec('DELETE FROM info_files');       // ... und die Verknüpfungen (Info-Texte bleiben)
            $d->exec('DELETE FROM vote_item_files');  // auch Abstimmungs-Uploads (Abstimmungs-Texte bleiben)
            $d->exec('DELETE FROM expense_files');    // auch Beleg-Doks (Belegblatt-Daten/Nachrichten bleiben)
            flash('Alle hochgeladenen Dokumente gelöscht.', 'success');
            break;
        case 'del_expenses':
            // Physische Beleg-Doks entfernen, dann die Belegblätter (Dateien + Nachrichten per Cascade)
            foreach ($d->query('SELECT stored_name FROM expense_files')->fetchAll(PDO::FETCH_COLUMN) as $sn) {
                @unlink(upload_dir() . '/' . basename((string)$sn));
            }
            $d->exec('DELETE FROM expense_claims');
            flash('Alle Belegblätter (Finanzen) inkl. angehefteter Doks und Nachrichten gelöscht.', 'success');
            break;
        case 'del_members':
            $d->exec('DELETE FROM members');     // responses + absences per Cascade
            $d->exec('DELETE FROM remember_tokens');
            $d->exec('DELETE FROM login_tokens');
            flash('Alle Mitglieder gelöscht. Der Technik-/Owner-Login bleibt bestehen – damit kannst du neue Mitglieder anlegen.', 'success');
            break;
        case 'del_bugs':
            $d->exec('DELETE FROM bug_reports'); // bug_comments per Cascade
            flash('Alle Bug-Reports inkl. Kommentare gelöscht.', 'success');
            break;
        case 'del_feedback':
            $d->exec('DELETE FROM feedback_items'); // feedback_comments per Cascade
            flash('Alle Rückmeldungen inkl. Verlauf gelöscht.', 'success');
            break;
        case 'del_swaps':
            $d->exec('DELETE FROM swap_proposals');
            $d->exec('DELETE FROM shift_swaps');   // Angebote, Vorschläge & Tausch-Historie (Einteilung bleibt)
            flash('Schichtbörse zurückgesetzt: Angebote, Vorschläge und Tausch-Historie gelöscht. Die Einteilung bleibt unverändert; die (wertlosen) Depots stehen damit wieder bei 0.', 'success');
            break;
        case 'del_gettogethers':
            $d->exec('DELETE FROM gettogethers');  // gettogether_rsvp per Cascade
            flash('Alle AStA Get-Togethers inkl. Rückmeldungen gelöscht.', 'success');
            break;
        case 'del_all':
            foreach ($d->query('SELECT stored_name FROM expense_files')->fetchAll(PDO::FETCH_COLUMN) as $sn) {
                @unlink(upload_dir() . '/' . basename((string)$sn)); // physische Beleg-Doks
            }
            foreach (['swap_proposals', 'shift_swaps', 'gettogethers', 'expense_claims', 'responses', 'event_slots', 'events', 'reminder_log', 'meetings',
                      'legislatures', 'absences', 'members', 'remember_tokens', 'login_tokens', 'bug_reports', 'feedback_items'] as $t) {
                $d->exec('DELETE FROM ' . $t); // bug_comments per Cascade über bug_reports, Beleg-Doks/Nachrichten über expense_claims
            }
            flash('Alle Inhalte zurückgesetzt. Einstellungen und Technik-Login bleiben erhalten.', 'success');
            break;

        // ---- Nebendatenbanken. Sie liegen NICHT in asta.sqlite, deshalb eigene Aktionen.
        //      „Alles zurücksetzen" fasst sie bewusst nicht an: Eine öffentliche Anmeldung
        //      wegzuwerfen, weil man die interne Stammliste leeren wollte, wäre eine böse
        //      Überraschung. ----
        case 'del_pat':
            if (side_db_exists('pat')) {
                require_once __DIR__ . '/../pat-db.php';
                foreach (['pat_messages', 'pat_mailqueue', 'pat_signups', 'pat_courses', 'pat_rounds'] as $t) {
                    pat_db()->exec('DELETE FROM ' . $t);
                }
                flash('Pat:innenprogramm geleert: Durchgänge, Anmeldungen, Studiengänge, Mail-Warteschlange und Fragen.', 'success');
            }
            break;
        case 'del_umfragen':
            if (side_db_exists('umfrage')) {
                require_once __DIR__ . '/../umfrage-db.php';
                foreach (umfrage_all() as $u) umfrage_delete((int)$u['id']);
                umfrage_db()->exec('DELETE FROM mail_log');
                flash('Alle Umfragen samt Verzeichnis, wartenden Stimmen und Urne gelöscht.', 'success');
            }
            break;
        case 'del_extern':
            if (side_db_exists('extern')) {
                require_once __DIR__ . '/../extern-db.php';
                foreach (extern_events_all() as $e) extern_event_delete((int)$e['id']);
                extern_db()->exec('DELETE FROM mailqueue');
                extern_db()->exec('DELETE FROM mail_log');
                flash('Alle externen Events samt Anmeldungen, Gruppen und Fahrplänen gelöscht.', 'success');
            }
            break;
        case 'del_termine':
            if (side_db_exists('termin')) {
                require_once __DIR__ . '/../termin-db.php';
                // Options/Entries/Votes hängen per ON DELETE CASCADE an den Umfragen.
                tplan_db()->exec('DELETE FROM polls');
                flash('Alle Terminumfragen samt Vorschlägen und Rückmeldungen gelöscht.', 'success');
            }
            break;
        case 'del_mailpool':
            mail_pool_db()->exec('DELETE FROM sends');
            flash('Mail-Konto geleert. Achtung: Das Tageskontingent steht damit wieder auf voll, obwohl die Mails beim Hoster längst gezählt sind.', 'success');
            break;
        // Altlast aus einem Zwischenstand der Umfragen: Diese Tabelle gibt es im Programm
        // nicht mehr, auf einer älteren Datenbank kann sie aber noch mit Klartext-Adressen
        // liegen. Sie wird nur auf ausdrücklichen Klick entfernt.
        case 'del_umfrage_tokens':
            if (side_db_exists('umfrage')) {
                require_once __DIR__ . '/../umfrage-db.php';
                umfrage_db()->exec('DROP TABLE IF EXISTS tokens');
                flash('Die alte tokens-Tabelle wurde entfernt.', 'success');
            }
            break;
    }
    redirect('reset.php');
}

/** Liegt die Datei einer Nebendatenbank überhaupt schon da?
 *  Bewusst nur nachsehen statt öffnen: Ein Verbindungsaufbau würde die Datei ANLEGEN, und eine
 *  frisch entstandene leere Datenbank hat schon einmal beim Hochladen echte Daten überschrieben. */
function side_db_exists(string $modul): bool
{
    return is_file(__DIR__ . '/../data/' . $modul . '.sqlite');
}

/** Zahlen einer Nebendatenbank – oder null, wenn es sie noch gar nicht gibt. */
function side_db_counts(string $modul, array $abfragen): ?array
{
    if (!side_db_exists($modul)) return null;
    try {
        $pdo = new PDO('sqlite:' . __DIR__ . '/../data/' . $modul . '.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $out = [];
        foreach ($abfragen as $label => $sql) {
            try { $out[$label] = (int)$pdo->query($sql)->fetchColumn(); }
            catch (\Throwable $e) { $out[$label] = -1; }   // Tabelle (noch) nicht da
        }
        return $out;
    } catch (\Throwable $e) { return null; }
}

/** Gibt es in der Umfrage-Datenbank noch die abgeschaffte tokens-Tabelle? -1 = keine Datenbank. */
function umfrage_tokens_rest(): int
{
    if (!side_db_exists('umfrage')) return -1;
    try {
        $pdo = new PDO('sqlite:' . __DIR__ . '/../data/umfrage.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tokens'")->fetchColumn() === 0) return -1;
        return (int)$pdo->query('SELECT COUNT(*) FROM tokens')->fetchColumn();
    } catch (\Throwable $e) { return -1; }
}

$counts = [
    'Mitglieder'         => (int)db()->query('SELECT COUNT(*) FROM members')->fetchColumn(),
    'Events'             => (int)db()->query('SELECT COUNT(*) FROM events')->fetchColumn(),
    'Rückmeldungen'      => (int)db()->query('SELECT COUNT(*) FROM responses')->fetchColumn(),
    'Sitzungen'          => (int)db()->query('SELECT COUNT(*) FROM meetings')->fetchColumn(),
    'Legislaturen'       => (int)db()->query('SELECT COUNT(*) FROM legislatures')->fetchColumn(),
    'Abwesenheiten'      => (int)db()->query('SELECT COUNT(*) FROM absences')->fetchColumn(),
    'Entwürfe'           => (int)db()->query('SELECT (SELECT COUNT(*) FROM events WHERE draft=1) + (SELECT COUNT(*) FROM meetings WHERE draft=1)')->fetchColumn(),
    'Vorlagen'           => (int)db()->query('SELECT COUNT(*) FROM event_templates')->fetchColumn(),
    'Uploads (Dok.)'     => (int)db()->query('SELECT (SELECT COUNT(*) FROM info_files) + (SELECT COUNT(*) FROM vote_item_files) + (SELECT COUNT(*) FROM expense_files)')->fetchColumn(),
    'Belegblätter (Finanzen)' => (int)db()->query('SELECT COUNT(*) FROM expense_claims')->fetchColumn(),
    'Bug-Reports'        => (int)db()->query('SELECT COUNT(*) FROM bug_reports')->fetchColumn(),
    'Feedback'           => (int)db()->query('SELECT COUNT(*) FROM feedback_items')->fetchColumn(),
    'Börse (Angebote/Tausche)' => (int)db()->query('SELECT COUNT(*) FROM shift_swaps')->fetchColumn(),
    'Get-Togethers'      => (int)db()->query('SELECT COUNT(*) FROM gettogethers')->fetchColumn(),
];

// Dateigrößen (MB) für die datei-gestützten Zeilen – aus den gespeicherten Upload-Größen.
$uploadBytes = (int)db()->query('SELECT (SELECT COALESCE(SUM(size),0) FROM info_files)
    + (SELECT COALESCE(SUM(size),0) FROM vote_item_files)
    + (SELECT COALESCE(SUM(size),0) FROM expense_files)')->fetchColumn();
$sizes = ['Uploads (Dok.)' => $uploadBytes]; // nur Zeilen mit physischen Dateien bekommen eine Größe
$mb = fn(int $b) => number_format($b / 1048576, $b >= 1048576 ? 1 : 2, ',', '.') . ' MB';

/** Erzeugt einen Lösch-Button mit Sicherheitsabfrage. */
function del_button(string $action, string $label, string $confirm): void
{
    ?>
    <form method="post" data-confirm="<?= h($confirm) ?>" data-confirm-danger data-confirm-ok="Endgültig löschen" data-confirm-title="Daten löschen">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= h($action) ?>">
      <button class="btn danger" type="submit"><?= h($label) ?></button>
    </form>
    <?php
}

page_header('Daten löschen', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<h1>Gefahrenzone – Daten löschen</h1>
<div class="flash flash-error">
  Achtung: Diese Aktionen löschen Daten <strong>unwiderruflich</strong>. Lege bei Bedarf vorher eine
  Sicherung an (den ganzen Ordner <code>data/</code> kopieren – neben <code>asta.sqlite</code> liegen dort die
  eigenen Datenbanken der öffentlichen Bereiche). Der <strong>Technik-Login und alle
  Einstellungen bleiben immer erhalten.</strong>
</div>

<div class="card">
  <h2>Aktueller Bestand</h2>
  <table class="list">
    <thead><tr><th>Datentyp</th><th style="text-align:right">Anzahl</th><th style="text-align:right">Dateigröße</th></tr></thead>
    <tbody>
    <?php foreach ($counts as $k => $v): ?>
      <tr><td><?= h($k) ?></td><td style="text-align:right"><strong><?= $v ?></strong></td><td style="text-align:right" class="muted"><?= isset($sizes[$k]) ? h($mb($sizes[$k])) : '–' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
</div>

<div class="card">
  <h2>Einzeln löschen</h2>
  <div class="btn-row" style="gap:.6rem;flex-wrap:wrap">
    <?php
    del_button('del_responses', 'Alle Rückmeldungen', 'Wirklich ALLE Abstimmungen/Rückmeldungen löschen? Events und Termine bleiben.');
    del_button('del_events', 'Alle Events', 'Wirklich ALLE Events inkl. Termine und Rückmeldungen löschen?');
    del_button('del_meetings', 'Alle Sitzungen', 'Wirklich ALLE Sitzungen löschen?');
    del_button('del_legislatures', 'Alle Legislaturen', 'Wirklich ALLE Legislaturperioden löschen? Sitzungen bleiben, verlieren aber die Nummerierung.');
    del_button('del_absences', 'Alle Abwesenheiten', 'Wirklich ALLE Abwesenheiten löschen?');
    del_button('del_drafts', 'Alle Entwürfe', 'Wirklich ALLE Entwürfe (Events und Sitzungen) aller Personen löschen?');
    del_button('del_templates', 'Alle Vorlagen', 'Wirklich ALLE Event-Vorlagen löschen?');
    del_button('del_uploads', 'Alle Uploads (Dokumente)', 'Wirklich ALLE hochgeladenen Dokumente löschen? Info-Texte, Abstimmungs-Texte und Belegblatt-Daten bleiben, nur die Dateien werden entfernt.');
    del_button('del_expenses', 'Alle Belegblätter', 'Wirklich ALLE Belegblätter (Finanzen) inkl. angehefteter Doks und des Nachrichten-Verlaufs löschen?');
    del_button('del_members', 'Alle Mitglieder', 'Wirklich ALLE Mitglieder löschen? Damit kann sich niemand mehr per Login-Link anmelden, bis du neue anlegst. Der Technik-Login bleibt.');
    del_button('del_bugs', 'Alle Bug-Reports', 'Wirklich ALLE Bug-Reports inkl. Kommentare löschen?');
    del_button('del_feedback', 'Alle Rückmeldungen (Feedback)', 'Wirklich ALLE Feedback-Rückmeldungen inkl. Verlauf löschen?');
    del_button('del_swaps', 'Schichtbörse & Depots', 'Wirklich ALLE Börsen-Angebote, Tausch-Vorschläge und die Tausch-Historie löschen? Die Einteilung bleibt unverändert; die (wertlosen) Depot-Werte stehen danach wieder bei 0.');
    del_button('del_gettogethers', 'Alle Get-Togethers', 'Wirklich ALLE AStA Get-Togethers inkl. aller Ja/Nein-Rückmeldungen löschen?');
    ?>
  </div>
</div>

<?php
  // Die drei öffentlichen Bereiche liegen in eigenen Datenbanken. Ohne diesen Abschnitt sähe
  // die Gefahrenzone so aus, als gäbe es sie nicht – man kam an ihre Daten hier gar nicht ran.
  $neben = [
    'pat' => ['Pat:innenprogramm', [
      'Durchgänge'   => 'SELECT COUNT(*) FROM pat_rounds',
      'Anmeldungen'  => 'SELECT COUNT(*) FROM pat_signups',
      'Mails in der Warteschlange' => "SELECT COUNT(*) FROM pat_mailqueue WHERE status IN ('queued','sending')",
      'Fragen aus dem Formular'    => 'SELECT COUNT(*) FROM pat_messages',
    ]],
    'umfrage' => ['Umfragen', [
      'Umfragen'          => 'SELECT COUNT(*) FROM polls',
      'Abgegebene Stimmen'=> 'SELECT COUNT(*) FROM ballots',
      'Wartende Stimmen'  => 'SELECT COUNT(*) FROM pending',
      'Verzeichnis-Einträge' => 'SELECT COUNT(*) FROM voters',
    ]],
    'extern' => ['Externe Events', [
      'Veranstaltungen' => 'SELECT COUNT(*) FROM events',
      'Anmeldungen'     => 'SELECT COUNT(*) FROM signups',
      'Gruppen'         => 'SELECT COUNT(*) FROM groups',
      'Mails in der Warteschlange' => "SELECT COUNT(*) FROM mailqueue WHERE status = 'queued'",
    ]],
    'termin' => ['Terminplaner (öffentlich)', [
      'Terminumfragen'   => 'SELECT COUNT(*) FROM polls',
      'Terminvorschläge' => 'SELECT COUNT(*) FROM options',
      'Rückmeldungen'    => 'SELECT COUNT(*) FROM entries',
    ]],
  ];
  $dateiMb = function (string $modul) use ($mb): string {
    $f = __DIR__ . '/../data/' . $modul . '.sqlite';
    return is_file($f) ? $mb((int)filesize($f)) : '–';
  };
?>
<div class="card">
  <h2>Öffentliche Bereiche (eigene Datenbanken)</h2>
  <p class="muted small" style="margin-top:0">Pat:innenprogramm, Umfragen, externe Events und der Terminplaner liegen <strong>nicht</strong> in
    <code>data/asta.sqlite</code>, sondern jeweils in einer eigenen Datei. „Alles zurücksetzen" weiter unten fasst sie
    deshalb <strong>nicht</strong> an – eine laufende öffentliche Anmeldung soll nicht mit verschwinden, weil jemand die
    interne Stammliste leeren wollte.</p>
  <?php foreach ($neben as $modul => [$titel, $abfragen]): $z = side_db_counts($modul, $abfragen); ?>
    <div style="margin:.9rem 0 0">
      <strong><?= h($titel) ?></strong>
      <span class="muted small">· <code>data/<?= h($modul) ?>.sqlite</code> · <?= h($dateiMb($modul)) ?></span>
      <?php if ($z === null): ?>
        <p class="small muted" style="margin:.2rem 0 0"><i class="ti ti-circle-off"></i> Noch nicht in Benutzung – es gibt keine Datenbank dafür.</p>
      <?php else: ?>
        <table class="list" style="margin:.3rem 0 .5rem">
          <tbody>
          <?php foreach ($z as $k => $v): ?>
            <tr><td><?= h($k) ?></td><td style="text-align:right"><strong><?= $v < 0 ? '–' : $v ?></strong></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="btn-row" style="gap:.6rem;flex-wrap:wrap">
          <?php
            if ($modul === 'pat') del_button('del_pat', 'Pat:innenprogramm leeren', 'Wirklich ALLE Durchgänge, Anmeldungen, Studiengänge und Fragen des Pat:innenprogramms löschen? Die Einstellungen und Texte bleiben.');
            if ($modul === 'umfrage') del_button('del_umfragen', 'Alle Umfragen löschen', 'Wirklich ALLE Umfragen löschen – samt Ergebnissen, wartenden Stimmen und dem Verzeichnis, wer schon abgestimmt hat? Ergebnisse sind danach nicht wiederherstellbar.');
            if ($modul === 'extern') del_button('del_extern', 'Alle externen Events löschen', 'Wirklich ALLE externen Events löschen – samt Anmeldungen, Gruppen, Stationen und Fahrplänen? Die angemeldeten Studierenden bekommen davon keine Nachricht.');
            if ($modul === 'termin') del_button('del_termine', 'Alle Terminumfragen löschen', 'Wirklich ALLE Terminumfragen löschen – auch die von Fachschaften und Gruppen, die gerade laufen? Deren Links führen danach ins Leere, und niemand bekommt eine Nachricht darüber.');
          ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php $tokRest = umfrage_tokens_rest(); if ($tokRest >= 0): ?>
    <div class="flash flash-error" style="margin-top:1rem">
      <strong>Altlast gefunden.</strong> In <code>data/umfrage.sqlite</code> liegt noch die Tabelle <code>tokens</code>
      mit <strong><?= (int)$tokRest ?></strong> <?= $tokRest === 1 ? 'Zeile' : 'Zeilen' ?>.
      Sie stammt aus einem Zwischenstand der Umfragen und kann <strong>Mailadressen im Klartext</strong> enthalten.
      Das Programm benutzt sie nicht mehr – das Löschen ändert nichts an laufenden Umfragen.
      <div class="btn-row" style="margin-top:.6rem">
        <?php del_button('del_umfrage_tokens', 'Alte tokens-Tabelle entfernen', 'Die Tabelle „tokens" enthält ' . (int)$tokRest . ' Zeile(n) und wird vom Programm nicht mehr benutzt. Endgültig entfernen?'); ?>
      </div>
    </div>
  <?php endif; ?>

  <div style="margin:1.1rem 0 0">
    <strong>Gemeinsames Mail-Konto</strong>
    <span class="muted small">· <code>data/mailpool.sqlite</code> · <?= h($dateiMb('mailpool')) ?></span>
    <p class="small muted" style="margin:.2rem 0 .4rem">Eine reine Strichliste: je verschickter Mail ein Zeitpunkt und der Bereich,
      keine Adressen. Leeren setzt nur den Zähler zurück – beim Hoster sind die Mails trotzdem gezählt.</p>
    <div class="btn-row" style="gap:.6rem">
      <?php del_button('del_mailpool', 'Mail-Konto leeren', 'Der Zähler steht danach wieder auf null, obwohl der Hoster die Mails weiter mitzählt. Rundmails dürfen dann heute mehr verschicken, als eigentlich frei ist. Wirklich leeren?'); ?>
    </div>
  </div>
</div>

<div class="card">
  <h2>Alles zurücksetzen</h2>
  <p class="muted small">Löscht Mitglieder, Events, Sitzungen, Legislaturen, Abwesenheiten, alle Abstimmungen, Belegblätter (inkl. Beleg-Doks), Bug-Reports und Rückmeldungen. Einstellungen und Technik-Login bleiben erhalten.</p>
  <?php del_button('del_all', 'Alle Inhalte zurücksetzen', 'Wirklich ALLE Inhalte löschen (außer Einstellungen/Technik-Login)? Das kann nicht rückgängig gemacht werden!'); ?>
</div>

<?php
page_footer();
