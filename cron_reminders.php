<?php
/**
 * Erinnerungs-Cron: an Mitglieder ohne Abstimmung erinnern, solange die Frist
 * näher rückt. Per Mittwald-Cron täglich aufrufen, z. B.:
 *
 *   CLI:  php /pfad/zur/app/cron_reminders.php
 *   Web:  https://deine-domain.de/planer/cron_reminders.php?key=DEIN_CRON_KEY
 *
 * Sendet pro Mitglied höchstens eine Mail pro Tag und Event.
 */
require __DIR__ . '/lib.php';
db();

$isCli = (PHP_SAPI === 'cli');

// Zugriffsschutz für den Web-Aufruf
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = setting_get('cron_key', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Zugriff verweigert. Gültigen ?key=… anhängen (siehe Verwaltung).\n");
    }
}

$leadDays = max(0, (int)setting_get('reminder_lead_days', '3')); // globaler Standard-Vorlauf
$maxWindow = max(7, $leadDays); // Maximum der persönlich wählbaren Fenster – gefiltert wird pro Person in send_event_reminders()
$today = new DateTime('today');

$events = db()->query("SELECT * FROM events WHERE closed = 0 AND draft = 0 AND deadline IS NOT NULL AND deadline <> ''")->fetchAll();

$totalSent = 0;
$lines = [];
foreach ($events as $e) {
    $deadline = DateTime::createFromFormat('Y-m-d', $e['deadline']);
    if (!$deadline) continue;
    $deadline->setTime(0, 0);
    $daysLeft = (int)$today->diff($deadline)->format('%r%a');
    // nur innerhalb des Vorlauf-Fensters und vor/bis zur Frist erinnern
    if ($daysLeft < 0 || $daysLeft > $maxWindow) continue;

    $res = send_event_reminders($e);
    $totalSent += count($res['sent']);
    if ($res['sent']) {
        $lines[] = '"' . $e['title'] . '" (Frist in ' . $daysLeft . ' Tag(en)): '
            . count($res['sent']) . ' Erinnerung(en) an ' . implode(', ', $res['sent']);
    }
}

// Persönliche Schicht-Erinnerungen (von Mitgliedern selbst aktiviert)
$shiftReminders = send_shift_reminders();

// Berichts-Erinnerungen (1 Tag vor berichtspflichtiger Sitzung an Referate ohne Bericht)
$reportReminders = send_report_reminders();

// Abstimmungsgegenstände-Erinnerungen (2 Tage vor der Sitzung an Mitglieder mit ungelesenen Gegenständen)
$voteItemReminders = send_voteitem_reminders();

// „Evtl."-Erinnerungen (1 Tag vor Fristende) + abgelaufene „Evtl." auf „Nein" setzen
$maybeReminders = send_maybe_reminders();
$maybeConverted = convert_expired_maybes();

// Umlaufverfahren + verpflichtende Abstimmungen: abgelaufene Fristen auswerten
// (verschickt Ergebnis), dann Säumige erinnern
$umlaufClosed = umlauf_maintain() + poll_maintain();
$umlaufReminders = umlauf_send_reminders() + poll_send_reminders();

// Inaktivitäts-Push (Opt-in, ab Werk aus): App seit X Tagen nicht geöffnet → 1 Erinnerung je Phase
$inactivityReminders = send_inactivity_reminders();

// „Sommer verschenken"-Push: König:innen des Sommers 7 Tage nach Freischaltung erinnern, wenn noch nichts verschenkt (einmalig, nur Push)
$sommerReminders = send_sommer_gift_reminders();

// Sitzungseinladungen: Sekretariat an offene Einladungen erinnern + freigegebene am Stichtag automatisch verschicken
$inviteReminders = send_invite_reminders();
$invitesSent = send_meeting_invites();

// Hintergrund-Rauschen aufräumen (Nachrichten, Erinnerungs-Logs, abgelaufene Tokens) – älter als 90 Tage
$cleaned = cleanup_old_data(90);
$cleanedTotal = array_sum($cleaned);

// Abstimmungs-Uploads löschen sich nach 40 Tagen selbst (Texte der Abstimmungsgegenstände bleiben)
$voteFilesDeleted = cleanup_vote_item_files(40);

// Angeheftete Beleg-Doks (Finanzen) löschen sich nach 30 Tagen selbst (Belegblatt-Daten bleiben)
$expenseFilesDeleted = cleanup_expense_files(30);

// Streak-Pause pflegen: in der vorlesungsfreien Zeit friert die Streak ein, zum Semesterstart wird sie überbrückt
$streakBreak = streak_maintain_break();

// Pat:innenprogramm: Löschfristen vollstrecken – fällige Programme werden KOMPLETT abgeräumt
// (Anmeldungen, Personendaten, Mail-Warteschlange); laufende Anmeldungen bleiben unangetastet.
// BEWUSST hier und nicht im Automationen-Cron: der wird nur eingerichtet, wenn die
// Berichte-Dok-Automation läuft – die Löschfristen dürfen davon nicht abhängen.
$patPurge = [];
try {
    require_once __DIR__ . '/pat-db.php';
    $patPurge = pat_purge_due(); // die Löschungen stehen unten in der Cron-Ausgabe ($summary)
} catch (\Throwable $e) {
    app_log_error('Pat:innenprogramm-Löschfristen (Cron): ' . $e->getMessage());
}

$invitesSentN = count($invitesSent);
$summary = date('Y-m-d H:i') . " · $totalSent Abstimmungs-, $shiftReminders Schicht-, $reportReminders Berichts-, $voteItemReminders Abstimmungsgegenstände-, $maybeReminders Evtl.-, $umlaufReminders Umlauf-, $inactivityReminders Inaktivitäts-, $sommerReminders Sommer-Geschenk-Erinnerung(en) verschickt; $umlaufClosed Umlauf/Umläufe ausgewertet; $inviteReminders Einladungs-Reminder, $invitesSentN Einladung(en) versendet; $maybeConverted Evtl.→Nein; $cleanedTotal Altdaten aufgeräumt (Nachrichten: {$cleaned['messages']}); $voteFilesDeleted Abstimmungs-Upload(s) (>40 Tage) und $expenseFilesDeleted Beleg-Dok(s) (>30 Tage) gelöscht; Streak-Pause: $streakBreak.";
foreach ($patPurge as $pp) $summary .= "\nPat:innenprogramm gelöscht (Löschfrist): {$pp['label']} mit {$pp['signups']} Anmeldungen.";
if ($invitesSent) $summary .= "\nEinladungen verschickt: " . implode(', ', $invitesSent);
if ($lines) $summary .= "\n" . implode("\n", $lines);

cron_stamp('reminders'); // Herzschlag: Selbsttest/Dashboard warnen, wenn dieser Stempel >26 h alt ist

echo $summary . "\n";
