<?php
/**
 * Cron für das Veranstaltungsportal was.läuft – im VIERTELSTUNDEN-Takt.
 *
 * Was er tut, in dieser Reihenfolge:
 *  1. PUSH-VERSAND (jeder Lauf): fällige „Erinnere mich"-Mitteilungen und Neuigkeiten an
 *     Abonnenten (neue Live-Beiträge, abgeleitet aus items.push_done = 0). Deshalb der enge
 *     Takt – eine Erinnerung „2 Stunden vorher" darf nicht erst am Abend kommen.
 *  2. AUFRÄUMEN (nur beim ersten Lauf des Tages): Beiträge, die lange vorbei sind, samt
 *     Bildern löschen; verwaiste Bilddateien wegräumen. Archivbilder bleiben immer.
 *
 * Alle 15 Minuten einrichten (Mittwald-Cron):
 *   CLI:  php /pfad/zur/app/cron_waslaeuft.php
 *   Web:  https://deine-domain.de/planer/cron_waslaeuft.php?key=DEIN_CRON_KEY
 *
 * Läuft er seltener, geht nichts kaputt – Erinnerungen kommen dann nur gröber getaktet.
 * Warum löschen? Weil eine Veranstaltungsseite sonst zur Halde wird: Nach zwei Jahren lägen
 * dort tausend vergangene Partys und ein paar Gigabyte Bilder, die niemand mehr ansieht.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
db();
require_once __DIR__ . '/wl-db.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = setting_get('cron_key', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Zugriff verweigert. Gültigen ?key=… anhängen (siehe Verwaltung).\n");
    }
}

wl_db();

// ---- 1) Push-Versand (jeder Lauf) -------------------------------------------------------------
$push = wl_push_cron();

// ---- 1b) Die zuständigen Referate benachrichtigen (jeder Lauf) --------------------------------
// Eingereicht und gemeldet wird auf der ÖFFENTLICHEN Seite, und die kennt die Stammliste nicht
// (kein lib.php – das ist Absicht und bleibt so). Deshalb sagt der Cron Bescheid: Er sieht beide
// Welten. Gebündelt je Lauf – drei Einreichungen in einer Viertelstunde sind EINE Mitteilung,
// nicht drei. Empfänger sind NUR die eingetragenen Referate (wl_zustaendige_ids), nicht alle,
// die Zugriff hätten.
$neues = wl_staff_neues_abholen();
$gesagt = 0;
$etwas = $neues['einreichungen'] > 0 || $neues['aenderungen'] > 0 || $neues['meldungen'] > 0;
if ($etwas) {
    $teile = [];
    if ($neues['einreichungen'] > 0) {
        $teile[] = $neues['einreichungen'] === 1
            ? 'Eine neue Einreichung wartet auf Freigabe.'
            : $neues['einreichungen'] . ' neue Einreichungen warten auf Freigabe.';
    }
    if ($neues['aenderungen'] > 0) {
        $teile[] = $neues['aenderungen'] === 1
            ? 'Eine Änderung an einem veröffentlichten Beitrag wartet.'
            : $neues['aenderungen'] . ' Änderungen an veröffentlichten Beiträgen warten.';
    }
    if ($neues['meldungen'] > 0) {
        $teile[] = $neues['meldungen'] === 1
            ? 'Ein Beitrag wurde gemeldet.'
            : $neues['meldungen'] . ' Beiträge wurden gemeldet.';
    }
    // Das Ziel ist der Reiter, an dem die Arbeit liegt: Einreichungen und Änderungen liegen
    // beide im Freigabe-Stapel, Meldungen haben ihren eigenen Reiter.
    $ziel = ($neues['einreichungen'] > 0 || $neues['aenderungen'] > 0)
        ? 'veranstaltungen.php?t=beitraege' : 'veranstaltungen.php?t=meldungen';
    $text = 'was.läuft: ' . implode(' ', $teile);
    foreach (wl_zustaendige_ids() as $mid) {
        if (dm_send($mid, 'was.läuft', $text, null, false, false, 'dm_waslaeuft', $ziel)) $gesagt++;
    }
}

// ---- 2) Aufräumen (einmal täglich reicht) -----------------------------------------------------
// Der Takt-Merker liegt in den wl-Einstellungen: Der erste Lauf eines Tages räumt auf, die
// übrigen 95 überspringen den Teil. So bleibt der Cron EIN Eintrag beim Hoster.
$text = sprintf("was.läuft Push: %d Erinnerungen, %d Neuigkeiten, %d tote Geräte.\n",
    $push['erinnerungen'], $push['neu'], $push['tot']);
if ($etwas) {
    $text .= sprintf("An die zuständigen Referate: %d Einreichungen, %d Änderungen, %d Meldungen → %d Mitteilungen.\n",
        $neues['einreichungen'], $neues['aenderungen'], $neues['meldungen'], $gesagt);
} elseif (!wl_referate()) {
    $text .= "Hinweis: Es ist KEIN Referat als zuständig eingetragen – es geht niemand Bescheid.\n";
}

if (wl_setting('cleanup_tag', '') !== date('Y-m-d')) {
    wl_setting_set('cleanup_tag', date('Y-m-d'));

    // Tages-Salz wechseln und dabei die Merker von gestern wegräumen. Bisher hing das am ersten
    // Besuch des Tages – blieb er aus (nachts, oder weil das Portal zu ist), lagen die Merker
    // weiter da. Sie sind zwar nicht zurückrechenbar, aber ein Datenrest bleibt ein Datenrest.
    wl_tages_salz();

    $frist = max(7, min(365, (int)wl_setting('keep_days', (string)WL_KEEP_DAYS)));
    $weg   = wl_prune($frist);

    // Verwaiste Bilddateien: eine Zeile in `images` ohne Datei ist harmlos (die Anzeige lässt das
    // Bild dann weg), eine Datei ohne Zeile kostet dagegen dauerhaft Platz. Aufgeräumt wird nur
    // der zweite Fall – und nur bei Dateien, die älter als einen Tag sind, damit ein Upload, der
    // genau jetzt läuft, nicht unter den Händen weggeräumt wird.
    $geloescht = 0;
    $bekannt = [];
    foreach (wl_db()->query('SELECT file FROM images')->fetchAll() as $r) $bekannt[(string)$r['file']] = true;
    foreach (glob(WL_IMG_DIR . '/*.jpg') ?: [] as $pfad) {
        $name = basename($pfad, '.jpg');
        if (str_ends_with($name, '_k')) $name = substr($name, 0, -2);
        if (isset($bekannt[$name])) continue;
        if ((int)@filemtime($pfad) > time() - 86400) continue;
        if (@unlink($pfad)) $geloescht++;
    }

    $waisen = wl_image_waisen();
    $text .= sprintf(
        "Aufgeräumt: %d Beiträge älter als %d Tage gelöscht, %d verwaiste Bilddateien entfernt.\n"
        . "Bestand: %d öffentlich, %d warten, %d Archivbilder. Offene Waisen: %d Dateien / %d Einträge.\n",
        $weg, $frist, $geloescht,
        wl_count('live'), wl_count('pending'),
        (int)wl_db()->query('SELECT COUNT(*) FROM images WHERE stock = 1')->fetchColumn(),
        (int)$waisen['dateien_ohne_eintrag'], (int)$waisen['eintraege_ohne_datei']
    );
}

cron_stamp('waslaeuft'); // Herzschlag für den Selbsttest
echo $text;
