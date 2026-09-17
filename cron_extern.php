<?php
/**
 * Externe Events: wartende Mails nachschicken und Altes aufräumen.
 *
 * Im Normalfall geht jede Mail sofort beim Anmelden raus – hier laufen nur die Fälle auf,
 * in denen das Kontingent gerade erschöpft war oder der Versand klemmte. Dazu das
 * Aufräumen: unbestätigte Anmeldungen nach 48 Stunden, Anmeldedaten nach der Frist.
 *
 * Aufruf per Mittwald-Cron, sinnvoll alle 10–15 Minuten:
 *   php /html/wp-Asta/planer/cron_extern.php
 */
require __DIR__ . '/lib.php';
db();
require_once __DIR__ . '/extern-db.php';

$res = extern_queue_run();
$weg = extern_prune();
mail_pool_prune();   // alte Striche im gemeinsamen Mail-Konto wegräumen

cron_stamp('extern');

echo date('Y-m-d H:i') . ' · Externe Events: ' . $res['sent'] . ' Mails verschickt, '
    . $res['failed'] . ' fehlgeschlagen, ' . $res['left'] . " warten noch\n";
echo '  · Kontingent: ' . extern_mail_sent(24) . ' von ' . extern_mail_cap_day() . " Mails in den letzten 24 h\n";
if ($weg > 0) echo '  · aufgeräumt: ' . $weg . " abgelaufene Anmeldungen\n";
