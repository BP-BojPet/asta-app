<?php
declare(strict_types=1);
/**
 * Leitet auf die Kontaktadresse dieser Installation weiter – für den Feedback-Knopf der
 * Redeliste. Die liegt als .html auf der Platte und kann keine Einstellung lesen; ein fest
 * eingetragener Empfänger wäre aber genau die Art Angabe, die nicht ins Programm gehört.
 *
 * EIGENSTÄNDIG wie die übrigen Redelisten-Dateien: keine lib.php (die würde den Fehler-
 * Handler der App starten), kein Schreibzugriff. Gelesen wird genau ein Einstellungswert,
 * und der ist ohnehin öffentlich – er steht in der Fußzeile jeder Seite.
 */
$mail = '';
try {
    $db = new PDO('sqlite:' . __DIR__ . '/data/asta.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $st = $db->prepare("SELECT value FROM settings WHERE key = 'org_kontakt_mail'");
    $st->execute();
    $mail = trim((string)$st->fetchColumn());
} catch (\Throwable $e) {
    $mail = '';
}

if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Für diese Installation ist keine Kontaktadresse hinterlegt.\n");
}

header('Location: mailto:' . rawurlencode($mail) . '?subject=' . rawurlencode('Feedback Redeliste'), true, 302);
