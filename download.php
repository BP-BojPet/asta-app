<?php
require __DIR__ . '/lib.php';
db();
require_login(); // Dokumente nur für angemeldete Mitglieder

// ---------------------------------------------------------------------------
// Diagnose-Downloads (verlinkt auf Verwaltung → Diagnose): winzige Testdateien,
// um die Datei-Vorschau auf iOS/PWA gezielt zu prüfen – trennt sauber zwischen
// „Session fehlt im In-App-Browser", „inline kommt nicht an" und „Format X
// wird nicht gerendert". Nur für Angemeldete (require_login oben).
// ---------------------------------------------------------------------------
if (isset($_GET['diag'])) {
    // Winziges, valides PDF (eine Seite, eine Zeile) – ohne externe Bibliothek
    $diagPdf = function (): string {
        $stream = 'BT /F1 16 Tf 24 60 Td (AStA Datei-Test: PDF-Vorschau OK) Tj ET';
        $objs = [
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 340 120]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>',
            4 => "<</Length " . strlen($stream) . ">>stream\n" . $stream . "\nendstream",
            5 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];
        $pdf = "%PDF-1.4\n"; $ofs = [];
        foreach ($objs as $n => $o) { $ofs[$n] = strlen($pdf); $pdf .= "$n 0 obj\n$o\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($n = 1; $n <= 5; $n++) $pdf .= sprintf("%010d 00000 n \n", $ofs[$n]);
        return $pdf . "trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n$xref\n%%EOF";
    };
    switch ((string)$_GET['diag']) {
        case 'session': // zeigt, ob die Session im (In-App-)Browser-Fenster ankommt
            $me = current_member();
            header_remove('Pragma'); header_remove('Expires');
            header('Cache-Control: private, max-age=0');
            header('Content-Type: text/plain; charset=utf-8');
            exit("AStA Datei-Test: Session OK – angemeldet als " . ($me['name'] ?? 'Technik-Login') . ' (' . date('H:i:s') . ")\n");
        case 'txt':
            file_delivery_headers('text/plain', 'asta-datei-test.txt');
            exit("AStA Datei-Test: inline-Auslieferung von Text funktioniert.\n");
        case 'pdf':
            $bin = $diagPdf();
            file_delivery_headers('application/pdf', 'asta-datei-test.pdf', strlen($bin));
            exit($bin);
        case 'docx':
            $bin = docx_package(docx_paragraph('AStA Datei-Test: DOCX-Vorschau funktioniert.', true, 14));
            file_delivery_headers('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'asta-datei-test.docx', strlen($bin));
            exit($bin);
    }
    http_response_code(404);
    exit('Unbekannter Diagnose-Typ.');
}

// Vier Quellen: Dokumente der „Wichtigen Infos" (?file=), Abstimmungsgegenstände (?vfile=),
// Anhänge von Umlaufbeschlüssen/Abstimmungen (?ufile=) und angeheftete Belege der
// Belegblätter (?efile= – nur Einreicher:in selbst oder Finanzen).
if (isset($_GET['sfile'])) {
    $st = db()->prepare('SELECT * FROM stupa_files WHERE id = ?');
    $st->execute([(int)$_GET['sfile']]);
} elseif (isset($_GET['efile'])) {
    $st = db()->prepare('SELECT * FROM expense_files WHERE id = ?');
    $st->execute([(int)$_GET['efile']]);
} elseif (isset($_GET['vfile'])) {
    $st = db()->prepare('SELECT * FROM vote_item_files WHERE id = ?');
    $st->execute([(int)$_GET['vfile']]);
} elseif (isset($_GET['ufile'])) {
    $st = db()->prepare('SELECT * FROM umlauf_files WHERE id = ?');
    $st->execute([(int)$_GET['ufile']]);
} else {
    $st = db()->prepare('SELECT * FROM info_files WHERE id = ?');
    $st->execute([(int)($_GET['file'] ?? 0)]);
}
$f = $st->fetch();
if (!$f) { http_response_code(404); exit('Datei nicht gefunden.'); }

// Belege des StuPa: auf DIESEM Weg nur für Finanzen/Vorsitz/Admin. Das Präsidium hat
// kein Mitglieds-Konto und lädt über stupa/download.php mit seiner eigenen Sitzung.
if (isset($_GET['sfile']) && !can_finance()) {
    http_response_code(403);
    exit('Keine Berechtigung für diesen Beleg.');
}

// Belege sind privat: nur Einreicher:in selbst oder Finanzen/Vorsitz/Admin
if (isset($_GET['efile'])) {
    $claim = expense_claim_get((int)$f['claim_id']);
    $me = current_member();
    if (!$claim || (!can_finance() && (int)$claim['member_id'] !== (int)($me['id'] ?? 0))) {
        http_response_code(403);
        exit('Keine Berechtigung für diesen Beleg.');
    }
}

$path = upload_dir() . '/' . basename((string)$f['stored_name']); // basename schützt vor Pfad-Tricks
if (!is_file($path)) { http_response_code(404); exit('Datei ist nicht mehr vorhanden.'); }

$name = (string)$f['orig_name'];

// Liegt die Datei in einer Ablage (Teams ODER Nextcloud), ist DEREN Stand führend: aktuelle
// Fassung von dort ausliefern – so sehen alle die letzten Bearbeitungen. Fallback: lokale Kopie.
// Achtung: Belege (efile/sfile) haben KEINEN Ablage-Spiegel. Sie müssen hier '' ergeben –
// sonst würde ihre ID gegen die Info-Spiegel aufgelöst und fremder Inhalt ausgeliefert.
$tfKind = isset($_GET['vfile']) ? 'vfile'
    : (isset($_GET['ufile']) ? 'ufile'
    : ((isset($_GET['efile']) || isset($_GET['sfile'])) ? '' : 'info'));
if ($tfKind !== '' && mirror_store($tfKind, (int)$f['id']) !== '') {
    $bytes = mirror_current($tfKind, (int)$f['id']);
    if ($bytes !== null && $bytes !== '') {
        file_delivery_headers($f['mime'] !== '' ? (string)$f['mime'] : 'application/octet-stream', $name, strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }
}
file_delivery_headers($f['mime'] !== '' ? (string)$f['mime'] : 'application/octet-stream', $name, (int)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
