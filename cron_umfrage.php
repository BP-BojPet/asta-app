<?php
/**
 * Umfragen: wartende Bestätigungsmails nachschicken und Altes aufräumen.
 *
 * Der Normalfall braucht diesen Cron NICHT – solange das Kontingent reicht, geht jede Mail
 * sofort beim Abstimmen raus. Hier laufen nur die Fälle auf, in denen das Kontingent gerade
 * erschöpft war oder der Versand einmal klemmte.
 *
 * Aufruf per Mittwald-Cron, sinnvoll alle 10–15 Minuten:
 *   php /html/wp-Asta/planer/cron_umfrage.php
 */
require __DIR__ . '/lib.php';
db();
require_once __DIR__ . '/umfrage-db.php';

// Absender kommt aus den Umfrage-Einstellungen; ohne den geht gar nichts.
$res = umfrage_queue_run(function (string $mail, string $token): bool {
    $poll = null;
    // Zu welcher Umfrage gehört das? Über den Hash des Tokens – der steht in der wartenden Zeile.
    $st = umfrage_db()->prepare('SELECT poll_id FROM pending WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    if ($pid = (int)$st->fetchColumn()) $poll = umfrage_get($pid);
    if (!$poll) return false;

    $link = umfrage_url($poll, $token);
    if ($link === '') { app_log_error('Umfrage-Cron: Basis-Adresse fehlt, Link nicht baubar.'); return false; }
    $vars = [
        '{{TITEL}}' => (string)$poll['title'],
        '{{LINK}}'  => $link,
        '{{FRIST_SATZ}}' => trim((string)$poll['ends_at']) !== ''
            ? "\n\nZeit zum Bestätigen hast du bis zum Ende der Umfrage."
            : '',
    ];
    return umfrage_mail($mail, umfrage_text_fill(umfrage_text('mail_subject'), $vars),
                              umfrage_text_fill(umfrage_text('mail_body'), $vars));
});

$weg = umfrage_prune();
mail_pool_prune();   // alte Striche im gemeinsamen Mail-Konto wegräumen

cron_stamp('umfrage'); // Herzschlag für den Selbsttest

echo date('Y-m-d H:i') . ' · Umfragen: ' . $res['sent'] . ' Bestätigungsmails verschickt, '
    . $res['failed'] . ' fehlgeschlagen, ' . $res['left'] . " warten noch\n";
echo '  · Kontingent: ' . umfrage_mail_sent(24) . ' von ' . umfrage_mail_cap_day()
    . " Mails in den letzten 24 h\n";
if ($weg > 0) echo '  · aufgeräumt: ' . $weg . " abgelaufene Einträge\n";
