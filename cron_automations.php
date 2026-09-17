<?php
/**
 * Automations-Cron: erzeugt am Sitzungstag (ab der eingestellten Auslösezeit,
 * Standard 18:00 – nach der RSVP-Frist) die Berichte-Dok und lädt sie in die
 * konfigurierte Ablage (Teams/SharePoint oder OLAT).
 *
 * Ein täglicher Lauf genügt – am besten KURZ NACH der Auslösezeit (z. B. 18:01).
 * Ein häufigerer Takt schadet nicht (idempotent), ist aber nicht nötig.
 * Per Mittwald-Cron aufrufen:
 *
 *   CLI:  php /pfad/zur/app/cron_automations.php
 *   Web:  https://deine-domain.de/planer/cron_automations.php?key=DEIN_CRON_KEY
 *
 * Idempotent: pro Sitzung wird höchstens einmal erfolgreich hochgeladen.
 */
require __DIR__ . '/lib.php';
db();

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = setting_get('cron_key', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Zugriff verweigert. Gültigen ?key=… anhängen (siehe Verwaltung).\n");
    }
}

$res = run_report_doc_automation();

// Protokoll-Abstimmungen mit dem Sitzungskalender abgleichen: anlegen, wenn eine Folgesitzung
// dazugekommen ist, und umhängen, wenn die bisherige Zielsitzung abgesagt, gelöscht oder
// verschoben wurde. Passiert schon bei jeder Änderung an einer Sitzung – der Cron ist das Netz.
$protoAttached = protocol_sync_pending_votes();

cron_stamp('automations'); // Herzschlag: Selbsttest/Dashboard warnen, wenn dieser Stempel >26 h alt ist

echo date('Y-m-d H:i') . ' · Berichte-Dok-Automation: ' . $res['count'] . " hochgeladen.\n";
foreach ($res['lines'] as $l) echo '  - ' . $l . "\n";
echo '  · Protokoll-Abstimmungen angelegt oder umgehängt: ' . $protoAttached . "\n";
