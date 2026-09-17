<?php
/**
 * Versand-Cron für die Einteilungs-Mails des Pat:innenprogramms.
 *
 * Warum ein eigener, HÄUFIGER Cron statt des täglichen?
 *
 * Er ist das NETZ, nicht der Weg: „Alle verschicken" bringt die Warteschlange normalerweise
 * in einem Rutsch raus – ein Semester mit 300 Erstsemestern und 55 Pat:innen sind rund 355
 * persönliche Mails, und dafür reichen die **3000 je 24 Stunden** des Hosters. Der Cron holt nur noch, was
 * das Zeitbudget dieses einen Klicks nicht mehr geschafft hat, und wiederholt fehlgeschlagene
 * Zustellungen. Er bleibt trotzdem stündlich eingerichtet – ohne ihn bliebe genau das liegen.
 *
 * Stündlich einrichten (Mittwald-Cron):
 *   CLI:  php /pfad/zur/app/cron_patmail.php
 *   Web:  https://deine-domain.de/planer/cron_patmail.php?key=DEIN_CRON_KEY
 *
 * Ist nichts in der Warteschlange, kostet der Lauf praktisch nichts.
 */

require __DIR__ . '/lib.php';
db();
require_once __DIR__ . '/pat-db.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = setting_get('cron_key', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Zugriff verweigert. Gültigen ?key=… anhängen (siehe Verwaltung).\n");
    }
}

// Tageskontingent des Pat:innenprogramms (Rest des Hoster-Limits bleibt für Login-Links,
// Erinnerungen und alles andere, was die App sonst noch verschickt).
$dayCap  = max(0, min(3000, (int)setting_get('pat_mail_day_cap', '0')));
$perRun  = max(1, min(3000, (int)setting_get('pat_mail_per_run', '250')));
$seconds = max(5, min(300, (int)setting_get('pat_mail_seconds', '120')));

// --- Fragen aus dem Hilfe-Formular: VOR der Einteilungs-Warteschlange ---------
// Eine wartende Frage ist ein Mensch, der auf Antwort hofft – die darf nicht hinter 300
// Einteilungs-Mails in der Reihe stehen. Es sind wenige, deshalb kostet der Vortritt nichts.
// Absender ist – wie überall – die App-Adresse; die ANTWORTADRESSE ist die der fragenden Person:
// ein „Antworten" im Postfach der Pat:innen geht damit direkt an sie zurück.
$fragen = ['gesendet' => 0, 'fehler' => 0];
$patenMail = trim(pat_setting_get('paten_email', ''));
if ($patenMail !== '' && filter_var($patenMail, FILTER_VALIDATE_EMAIL)) {
    $snd = pat_mail_sender(mail_from());
    foreach (pat_messages_pending(20) as $m) {
        $wer  = trim((string)$m['name']);
        $rnd  = $m['round_id'] ? pat_round_get((int)$m['round_id']) : null;
        // Betreff aus dem pflegbaren Text; der Name kommt hinten dran, damit man im Postfach
        // schon in der Übersicht sieht, wer schreibt.
        $betr = trim((string)preg_replace('/\s+/', ' ', pat_text_fill(pat_text('help_subject'),
            ['{{PROGRAMM}}' => $rnd ? pat_round_label($rnd) : ''])));
        if ($betr === '') $betr = 'Frage zum Pat:innenprogramm';
        if ($wer !== '') $betr .= ' – von ' . $wer;
        $text = ($wer !== '' ? $wer : 'Jemand') . ' hat über das Formular auf der Studi-Seite gefragt:'
              . "\n\n" . str_repeat('–', 40) . "\n" . (string)$m['body'] . "\n" . str_repeat('–', 40)
              . "\n\nAntworten geht direkt an: " . (string)$m['email']
              . "\nGefragt am " . (string)$m['created_at']
              . ((string)$m['page'] !== '' ? ' auf ' . (string)$m['page'] : '') . "\n";
        try {
            $ok = send_mail($patenMail, $betr, $text, false, $snd['from'], (string)$m['email'], $snd['name']);
            pat_message_mark((int)$m['id'], (bool)$ok, $ok ? '' : 'Versand meldete Fehlschlag');
            $ok ? $fragen['gesendet']++ : $fragen['fehler']++;
        } catch (\Throwable $e) {
            pat_message_mark((int)$m['id'], false, $e->getMessage());
            $fragen['fehler']++;
        }
    }
}
// Erledigte Fragen enthalten Name und Adresse – die liegen nicht ewig herum.
$weg = pat_messages_prune();
mail_pool_prune();   // alte Striche im gemeinsamen Mail-Konto wegräumen

$budget = pat_mail_budget_left($dayCap);
$limit  = min($perRun, $budget);

$res = ['sent' => 0, 'failed' => 0, 'left' => 0];
if ($limit > 0) {
    // Absender/Antwortadresse: siehe pat_mail_sender() – Antworten der Erstsemester gehören zu
    // den Verantwortlichen des Programms, nicht in das allgemeine App-Postfach.
    $snd = pat_mail_sender(mail_from());
    // $cc: die Gruppen-Mail trägt alle Erstis der Gruppe im CC (bei allen anderen Arten leer).
    $res = pat_mail_run($limit, fn (string $to, string $s, string $b, string $cc = '')
        => send_mail($to, $s, $b, false, $snd['from'], $snd['reply'], $snd['name'], 'pat', $cc), null, $seconds);
} else {
    $c = pat_db()->query("SELECT COUNT(*) FROM pat_mailqueue WHERE status = 'queued'");
    $res['left'] = (int)$c->fetchColumn();
}

cron_stamp('patmail'); // Herzschlag für den Selbsttest

echo date('Y-m-d H:i') . ' · Pat:innenprogramm-Mails: ' . $res['sent'] . ' verschickt, '
    . $res['failed'] . " fehlgeschlagen\n";
if ($fragen['gesendet'] || $fragen['fehler'] || pat_messages_open_count()) {
    echo '  · Fragen aus dem Hilfe-Formular: ' . $fragen['gesendet'] . ' weitergeleitet, '
        . $fragen['fehler'] . ' fehlgeschlagen, ' . pat_messages_open_count() . " noch offen\n";
}
if ($weg > 0) echo '  · ' . $weg . " alte, erledigte Frage(n) gelöscht\n";
echo '  · in den letzten 24 h: ' . pat_mail_sent_last24() . ' von ' . $dayCap . " (Kontingent)\n";
echo '  · noch in der Warteschlange: ' . $res['left'] . "\n";
if ($res['left'] > 0 && $limit <= 0) {
    echo "  · Kontingent für dieses 24-Stunden-Fenster aufgebraucht – der nächste Lauf macht weiter.\n";
}
