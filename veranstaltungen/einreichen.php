<?php
/**
 * Einreichen – für Hochschulgruppen, Fachschaften, den AStA und Partner.
 *
 * MAILBASIERTER LOGIN (Mailadresse + Passwort): Die Verwaltung legt die Gruppe an, die Zugangs-Mail mit dem
 * Passwort-Link geht automatisch raus, und die Gruppe legt ihr Passwort SELBST fest –
 * „Passwort vergessen?" läuft über denselben Link in Selbstbedienung. Die Anmeldung liegt in
 * der Sitzung – der „+ Eintragen"-Knopf führt angemeldete Gruppen direkt zum Formular.
 * Der Vorteil gegenüber einem offenen Formular ist nicht nur weniger Spam: Jede Einreichung
 * ist zweifelsfrei EINER Gruppe zugeordnet, ohne dass jemand seinen Namen tippen müsste.
 *
 * Ohne Anmeldung zeigt die Seite den Login und die Zugangsanfrage für neue Gruppen.
 */

declare(strict_types=1);

require __DIR__ . '/wl-lib.php';

if (wl_offline_guard(true)) exit;   // im Vorabstart offen: hier entstehen die Beiträge fürs Startdatum

$org = wl_login_org();

$fertig = null;
$fehler = '';
$vorbelegt = [];

// Rückmeldungen der Seite OHNE Anmeldung (Anmelden / Passwort / Zugang anfragen)
$loginFehler   = '';
$loginAlt      = '';
$resetOk       = false;
$anfrageOk     = false;
$anfrageFehler = '';
$anfrageAlt    = [];
$neupassFehler = '';
$neupassOk     = false;

// ------------------------------------------------------------------ Anmelden (Mail + Passwort)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'login') {
    wl_check_csrf();
    $loginAlt = wl_param($_POST['mail'] ?? '');
    if (!wl_rate_ok('login', 10)) {
        $loginFehler = 'Das waren gerade viele Versuche. Bitte warte einen Moment.';
    } else {
        $grund = '';
        $treffer = wl_org_anmelden($loginAlt, wl_param($_POST['passwort'] ?? ''), $grund);
        if ($treffer) {
            session_regenerate_id(true);
            $_SESSION['wl_org_id'] = (int)$treffer['id'];
            header('Location: einreichen.php');
            exit;
        }
        // EINE Antwort für alles – ob es die Adresse gibt, verrät die Seite nicht. Auch die
        // Sperr-Meldung sagt nichts darüber aus: Sie erscheint genauso, wenn von diesem Gerät
        // zu viele Versuche kamen, ganz gleich zu welcher Adresse.
        $loginFehler = $grund === 'gesperrt'
            ? 'Zu viele Fehlversuche. Bitte wartet 15 Minuten – oder setzt euer Passwort über '
              . '„Passwort vergessen?" neu.'
            : 'Anmeldung fehlgeschlagen – Mailadresse oder Passwort stimmen nicht. '
              . 'Über „Passwort vergessen?" kommt ihr wieder rein.';
    }
}

// ------------------------------------------------------------------ Passwort vergessen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'reset') {
    wl_check_csrf();
    if (wl_rate_ok('reset', 5)) wl_org_reset_start(wl_param($_POST['mail'] ?? ''));
    // Die Antwort ist IMMER dieselbe – auch bei unbekannter Adresse oder gerissener Bremse:
    // Diese Seite ist keine Auskunft darüber, welche Adressen einen Zugang haben.
    $resetOk = true;
}

// ------------------------------------------------------------------ Neues Passwort setzen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'neupass') {
    wl_check_csrf();
    $rOrg = wl_org_by_reset(wl_param($_POST['token'] ?? ''));
    $p1 = wl_param($_POST['pass1'] ?? '');
    $p2 = wl_param($_POST['pass2'] ?? '');
    if (!$rOrg) {
        $neupassFehler = 'Dieser Link ist abgelaufen oder wurde schon benutzt. '
                       . 'Fordert über „Passwort vergessen?" einfach einen neuen an.';
    } elseif (mb_strlen($p1) < 8) {
        $neupassFehler = 'Das Passwort braucht mindestens 8 Zeichen.';
    } elseif ($p1 !== $p2) {
        $neupassFehler = 'Die beiden Eingaben sind nicht gleich.';
    } else {
        wl_org_passwort_setzen((int)$rOrg['id'], $p1);
        $neupassOk = true;
    }
}

// Kommt jemand mit einem Passwort-Link? Dann zeigt die Seite das Setz-Formular.
$resetOrg = null;
if (!$neupassOk && wl_param($_GET['reset'] ?? '') !== '') {
    $resetOrg = wl_org_by_reset(wl_param($_GET['reset'] ?? ''));
    if (!$resetOrg && $neupassFehler === '') {
        $neupassFehler = 'Dieser Link ist abgelaufen oder wurde schon benutzt. '
                       . 'Fordert über „Passwort vergessen?" einfach einen neuen an.';
    }
}

// ------------------------------------------------------------------ Abmelden
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'abmelden') {
    wl_check_csrf();
    unset($_SESSION['wl_org_id']);
    header('Location: einreichen.php');
    exit;
}

// ------------------------------------------------------------------ Öffentliches Profil
$profilOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'profil' && $org) {
    wl_check_csrf();
    wl_org_profil_save((int)$org['id'], wl_param($_POST['profil'] ?? ''),
        wl_param($_POST['web'] ?? ''), wl_param($_POST['insta'] ?? ''));
    $org = wl_org((int)$org['id']);
    $profilOk = true;
}

// ------------------------------------------------------------------ Promo: Logo + Bilder
// Die Gruppe pflegt hier ihr LOGO (erscheint als Absender-Marke auf
// Veranstalter-Seite und Event-Detailseite) und ihre eigenen Bilder fürs Anlegen –
// höchstens WL_ORG_IMG_MAX Stück, der Deckel sitzt zentral in wl_image_store().
$promoOk = ''; $promoFehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'logo' && $org) {
    wl_check_csrf();
    if (!empty($_POST['weg'])) {
        wl_org_logo_setzen((int)$org['id'], 0);
        $promoOk = 'Logo entfernt – es gilt wieder der farbige Kurz-Punkt.';
    } else {
        $up = wl_image_store($_FILES['logo'] ?? [], ['org_id' => (int)$org['id'], 'logo' => 1, 'rights_ok' => 1]);
        if ($up['ok']) { wl_org_logo_setzen((int)$org['id'], (int)$up['id']); $promoOk = 'Logo gespeichert.'; }
        else $promoFehler = $up['msg'];
    }
    $org = wl_org((int)$org['id']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'bildneu' && $org) {
    wl_check_csrf();
    if (empty($_POST['rechte'])) {
        $promoFehler = 'Bitte bestätige, dass ihr die Rechte am Bild habt.';
    } else {
        $up = wl_image_store($_FILES['bild'] ?? [], ['org_id' => (int)$org['id'],
            'rights_ok' => 1, 'credit' => wl_param($_POST['credit'] ?? '')]);
        $promoOk = $up['ok'] ? 'Bild übernommen – ab sofort beim Anlegen auswählbar.' : '';
        if (!$up['ok']) $promoFehler = $up['msg'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'bildweg' && $org) {
    wl_check_csrf();
    $bid = (int)wl_param($_POST['bild_id'] ?? '0');
    $b = wl_image($bid);
    if (!$b || (int)($b['org_id'] ?? 0) !== (int)$org['id'] || (int)$b['stock'] === 1) {
        $promoFehler = 'Dieses Bild gehört nicht zu eurer Gruppe.';
    } elseif (wl_image_delete_if_unused($bid)) {
        $promoOk = 'Bild gelöscht.';
    } else {
        $promoFehler = 'Das Bild hängt noch an einem Beitrag – erst dort tauschen, dann löschen.';
    }
}

// ------------------------------------------------------------------ Kategorie vorschlagen
// Kommt per fetch aus dem Vorschlags-Dialog im Formular und antwortet
// mit JSON – so bleiben die Formular-Eingaben stehen. Der Vorschlag landet als Stapel in
// der Verwaltung; für den Beitrag selbst wählt die Gruppe weiter eine bestehende Kategorie.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'katwunsch') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$org) { echo json_encode(['ok' => false, 'msg' => 'Die Anmeldung ist abgelaufen – bitte neu anmelden.'], JSON_UNESCAPED_UNICODE); exit; }
    wl_check_csrf();
    if (!wl_rate_ok('katwunsch', 10)) {
        echo json_encode(['ok' => false, 'msg' => 'Das waren gerade viele Vorschläge – bitte warte einen Moment.'], JSON_UNESCAPED_UNICODE);
    } elseif (wl_cat_wunsch_add((int)$org['id'], wl_param($_POST['text'] ?? ''))) {
        echo json_encode(['ok' => true, 'msg' => 'Danke! Euer Vorschlag liegt jetzt beim AStA.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Beschreibt kurz, was für eine Kategorie ihr euch wünscht.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ------------------------------------------------------------------ Eigenen Beitrag löschen
// Die Gruppen räumen ihre Beiträge selbst ab – logischerweise NUR die
// eigenen: Die Prüfung hängt an org_id, nicht an dem, was im Formular steht.
$dashOk = ''; $dashFehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'beitragweg' && $org) {
    wl_check_csrf();
    $wegId = (int)wl_param($_POST['item_id'] ?? '0');
    $wegIt = wl_item($wegId);
    if (!$wegIt || (int)($wegIt['org_id'] ?? 0) !== (int)$org['id']) {
        $dashFehler = 'Dieser Beitrag gehört nicht zu eurer Gruppe.';
    } else {
        wl_item_delete($wegId);
        $dashOk = '„' . (string)$wegIt['title'] . '" ist gelöscht.';
    }
}

// ------------------------------------------------------------------ Absagen / Absage zurücknehmen
// Eine Absage ist ehrlicher als Löschen – der Beitrag bleibt mit Banderole
// stehen, und wer sich erinnern lassen wollte, bekommt beim nächsten Cron-Lauf eine
// Absage-Mitteilung statt vor verschlossener Tür zu stehen. Besitz-Prüfung wie beim Löschen
// über org_id, nicht über das Formular.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(wl_param($_POST['was'] ?? ''), ['absage', 'absagezurueck'], true) && $org) {
    wl_check_csrf();
    $abId = (int)wl_param($_POST['item_id'] ?? '0');
    $abIt = wl_item($abId);
    if (!$abIt || (int)($abIt['org_id'] ?? 0) !== (int)$org['id']) {
        $dashFehler = 'Dieser Beitrag gehört nicht zu eurer Gruppe.';
    } elseif (wl_param($_POST['was']) === 'absage') {
        wl_item_absage($abId, true);
        $dashOk = '„' . (string)$abIt['title'] . '" ist als abgesagt markiert. Der Beitrag bleibt '
                . 'mit Absage-Hinweis sichtbar; alle, die sich erinnern lassen wollten, bekommen '
                . 'in den nächsten Minuten eine Mitteilung.';
    } else {
        wl_item_absage($abId, false);
        $dashOk = 'Die Absage von „' . (string)$abIt['title'] . '" ist zurückgenommen – der Beitrag '
                . 'gilt wieder als geplant.';
    }
}

// ------------------------------------------------------------------ Zugang anfragen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === 'anfrage') {
    wl_check_csrf();
    $anfrageAlt = $_POST;
    // Das unsichtbare Feld füllen nur Maschinen aus. Die Antwort tut trotzdem freundlich –
    // ein Spam-Skript soll nicht lernen, woran es gescheitert ist.
    if (wl_param($_POST['website'] ?? '') !== '') {
        $anfrageOk = true; $anfrageAlt = [];
    } elseif (!wl_rate_ok('anfrage', 5)) {
        $anfrageFehler = 'Das waren gerade viele Anfragen. Bitte warte einen Moment.';
    } else {
        $r = wl_org_request_add(wl_param($_POST['gruppe'] ?? ''), wl_param($_POST['mail'] ?? ''),
                                wl_param($_POST['nachricht'] ?? ''));
        if ($r['ok']) { $anfrageOk = true; $anfrageAlt = []; }
        else $anfrageFehler = $r['msg'];
    }
}

// ------------------------------------------------------------------ Einreichung entgegennehmen
// Seit auch das BEARBEITEN eines bestehenden Beitrags (verstecktes Feld `bearb`):
// Wartendes wird direkt überschrieben; an Live-Beiträgen bleibt die bisherige Fassung online,
// die Änderung wandert in den Freigabe-Stapel (wl_item_edit_store). Gruppen mit auto_ok
// speichern auch live direkt – wer ohne Freigabe veröffentlichen darf, darf auch so ändern.
$fertigArt = 'neu';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && wl_param($_POST['was'] ?? '') === '') {
    wl_check_csrf();
    $bearbId = (int)wl_param($_POST['bearb'] ?? '0');
    $bearbIt = null;
    if ($bearbId > 0 && $org) {
        $bearbIt = wl_item($bearbId);
        // Auch Abgelehntes ist bearbeitbar: Überarbeiten setzt es unten
        // wieder auf „wartet" – die Begründung des AStA steht dabei überm Formular.
        if (!$bearbIt || (int)($bearbIt['org_id'] ?? 0) !== (int)$org['id']
            || !in_array((string)$bearbIt['status'], ['pending', 'live', 'rejected'], true)) {
            $bearbIt = null; $bearbId = 0;
            $fehler = 'Dieser Beitrag lässt sich nicht bearbeiten.';
        }
    }
    if ($fehler !== '') {
        // Besitz-/Zustandsfehler von oben – gar nicht erst weiterprüfen.
    } elseif (!$org) {
        $fehler = 'Die Anmeldung ist abgelaufen. Bitte meldet euch neu an.';
    } elseif (wl_mode() === 'soft' && $bearbId === 0) {
        // Der Sanft-Modus stoppt NEUES – Bestehendes korrigieren dürfen die Gruppen weiter.
        $fehler = 'Gerade werden keine neuen Beiträge angenommen. Bitte versuch es später noch einmal.';
    // Die eigentliche Sperre ist der Token – hier reicht nichts ein, wer nicht eingerichtet wurde.
    // Diese Bremse fängt nur ein durchgedrehtes Skript ab und darf deshalb großzügig sein: Eine
    // Fachschaft, die zu Semesterbeginn ihr ganzes Programm einträgt, kommt schnell auf 15 Beiträge
    // in einer Stunde, und die soll dabei nicht ausgesperrt werden.
    } elseif (!wl_rate_ok('einreichen', 30)) {
        $fehler = 'Das waren gerade viele Einreichungen. Bitte warte einen Moment.';
    } else {
        $vorbelegt = $_POST;
        // Die Bildwahl ist EIN Radio-Kreis: '0' = Automatik, 'std:<datei>' = gewähltes
        // Standard-Foto, sonst eine Archiv-Bild-ID. Ein Upload schlägt alles (unten).
        $bildWahl = (string)wl_param($_POST['image_id'] ?? '0');
        $stdBild  = '';
        $bildId   = 0;
        if (str_starts_with($bildWahl, 'std:')) {
            $stdBild = wl_std_by_file(substr($bildWahl, 4)) ? substr($bildWahl, 4) : '';
        } else {
            $bildId = (int)$bildWahl;
        }

        // Eigenes Bild? Dann muss die Rechtefrage beantwortet sein – ohne Häkchen kein Upload.
        // Archivbilder brauchen das nicht: die Rechte daran hat der AStA schon geklärt.
        if (!empty($_FILES['bild']) && (int)($_FILES['bild']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (empty($_POST['rechte'])) {
                $fehler = 'Bitte bestätige, dass du die Rechte am Bild hast – sonst können wir es nicht veröffentlichen.';
            } else {
                $up = wl_image_store($_FILES['bild'], [
                    'org_id'    => (int)$org['id'],
                    'rights_ok' => 1,
                    'credit'    => wl_param($_POST['credit'] ?? ''),
                ]);
                if (!$up['ok']) $fehler = $up['msg'];
                else { $bildId = $up['id']; $stdBild = ''; }
            }
        } elseif ($bildId > 0) {
            // Ausgewähltes Bild muss dem Archiv oder dieser Gruppe gehören – sonst könnte man
            // über eine fremde ID an ein Bild kommen, das nicht für einen bestimmt war.
            $erlaubt = false;
            foreach (wl_images_for_org((int)$org['id']) as $bild) {
                if ((int)$bild['id'] === $bildId) { $erlaubt = true; break; }
            }
            if (!$erlaubt) { $bildId = 0; }
        }

        // „Wer darf kommen?" ist eine PFLICHTFRAGE ohne Vorgabe – das required-Attribut an den
        // Kreisen ist nur Bequemlichkeit im Browser. Bewusst kein stilles „nein": Wer die Frage
        // nicht beantwortet, hat sie übersehen, und ein falsches „offen für alle" ärgert später
        // Leute an der Tür.
        if ($fehler === '' && !in_array((string)wl_param($_POST['nur_studis'] ?? ''), ['0', '1'], true)) {
            $fehler = 'Bitte sag noch, ob der Beitrag allen offensteht oder nur Studierenden.';
        }

        if ($fehler === '') {
            $daten = array_merge($_POST, [
                'org_id'   => (int)$org['id'],
                'image_id' => $bildId,
                'std_bild' => $stdBild,
            ]);
            if ($bearbIt !== null) {
                // BEARBEITEN: Live ohne Vertrauensvorschuss → Änderung in den Freigabe-Stapel,
                // die bisherige Fassung bleibt online. Alles andere wird direkt überschrieben;
                // ein ABGELEHNTER Beitrag geht dabei zurück auf „wartet" (frische Einreichung:
                // alte Begründung weg, neuer Zeitstempel für den Freigabe-Stapel).
                if ((string)$bearbIt['status'] === 'live' && (int)$org['auto_ok'] !== 1) {
                    $r = wl_item_edit_store($bearbId, $daten);
                    $fertigArt = 'bearb_wartend';
                } else {
                    $r = wl_item_save($daten, $bearbId);
                    $fertigArt = 'bearb_direkt';
                    if ($r['ok'] && (string)$bearbIt['status'] === 'rejected') {
                        wl_db()->prepare("UPDATE items SET status = 'pending', note = '',
                            submitted_at = datetime('now','localtime') WHERE id = ?")->execute([$bearbId]);
                        $fertigArt = 'bearb_wieder';
                    }
                }
            } else {
                // Zweites Netz gegen Doppelklick und Formular-NEULADEN (der Knopf-Schutz im
                // Browser ist nur die halbe Miete): Hat DIESE Gruppe denselben Beitrag
                // (Titel + Beginn bzw. Rhythmus) in den letzten zwei Minuten schon
                // eingereicht, IST das derselbe – dann zeigen wir den, statt einen
                // Zwilling anzulegen.
                $dopp = wl_db()->prepare("SELECT id FROM items
                    WHERE org_id = ? AND title = ? AND starts_at = ? AND rhythmus = ?
                      AND submitted_at >= datetime('now','localtime','-2 minutes')
                    ORDER BY id DESC LIMIT 1");
                $dopp->execute([
                    (int)$org['id'],
                    mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120),
                    ($_POST['kind'] ?? 'event') === 'kurs' ? ''
                        : trim(str_replace('T', ' ', (string)($_POST['starts_at'] ?? ''))),
                    ($_POST['kind'] ?? 'event') === 'kurs'
                        ? mb_substr(trim((string)($_POST['rhythmus'] ?? '')), 0, 80) : '',
                ]);
                $doppId = (int)$dopp->fetchColumn();
                $r = $doppId > 0
                    ? ['ok' => true, 'msg' => 'Schon da.', 'id' => $doppId]
                    : wl_item_save($daten);
            }
            if (!$r['ok']) {
                $fehler = $r['msg'];
                $fertigArt = 'neu';
            } else {
                // Vertrauensvorschuss: Manche Gruppen dürfen ohne Freigabe direkt veröffentlichen.
                if ($bearbIt === null && (int)$org['auto_ok'] === 1) wl_item_freigeben($r['id']);
                $fertig = wl_item($r['id']);
                $vorbelegt = [];
            }
        }
    }
}

// ------------------------------------------------------------------ Vorbefüllen (Bearbeiten)
// ?bearb=<id> lädt einen eigenen Beitrag ins Formular; das versteckte Feld hält die ID über
// den POST. Ein „Nochmal eintragen" mit ?kopie= gibt es bewusst NICHT – wiederkehrende
// Events trägt man neu ein.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $org && !$vorbelegt) {
    $vId = (int)wl_param($_GET['bearb'] ?? '0');
    if ($vId > 0) {
        $vIt = wl_item($vId);
        if ($vIt && (int)($vIt['org_id'] ?? 0) === (int)$org['id']
            && in_array((string)$vIt['status'], ['pending', 'live', 'rejected'], true)) {
            $vorbelegt = [
                'bearb'         => (string)$vId,
                'kind'          => (string)$vIt['kind'],
                'title'         => (string)$vIt['title'],
                'cat'           => (string)$vIt['cat'],
                'teaser'        => (string)$vIt['teaser'],
                'text'          => (string)$vIt['text'],
                'starts_at'     => (string)$vIt['starts_at'],
                'ends_at'       => (string)$vIt['ends_at'],
                'rhythmus'      => (string)$vIt['rhythmus'],
                'termine'       => (int)$vIt['termine'] > 0 ? (string)(int)$vIt['termine'] : '',
                'einstieg'      => (int)$vIt['einstieg'] === 1 ? '1' : '',
                'von_datum'     => (string)$vIt['von_datum'],
                'bis_datum'     => (string)$vIt['bis_datum'],
                'ort'           => (string)$vIt['ort'],
                'adresse'       => (string)$vIt['adresse'],
                'preis'         => (string)$vIt['preis'],
                'studi_rabatt'  => (string)($vIt['studi_rabatt'] ?? ''),
                'title_en'      => (string)($vIt['title_en'] ?? ''),
                'teaser_en'     => (string)($vIt['teaser_en'] ?? ''),
                'frei'          => (int)$vIt['frei'] === 1 ? '1' : '',
                'barrierefrei'  => (int)$vIt['barrierefrei'] === 1 ? '1' : '',
                // Beim Bearbeiten ist die Frage schon beantwortet – Bestand ohne Angabe
                // steht auf „offen für alle".
                'nur_studis'    => (int)($vIt['nur_studis'] ?? 0) === 1 ? '1' : '0',
                'ab_alter'      => (int)$vIt['ab_alter'] > 0 ? (string)(int)$vIt['ab_alter'] : '',
                'url'           => (string)$vIt['url'],
                'anmeldung_url' => (string)$vIt['anmeldung_url'],
                // Der Radio-Kreis der Bildwahl: gewähltes Standard-Foto > eigenes/Archiv-Bild >
                // Automatik. Das eigene Bild taucht im Archiv der Gruppe auf und bleibt so gewählt.
                'image_id'      => trim((string)($vIt['std_bild'] ?? '')) !== ''
                    ? 'std:' . (string)$vIt['std_bild']
                    : ((int)($vIt['image_id'] ?? 0) > 0 ? (string)(int)$vIt['image_id'] : '0'),
            ];
        }
    }
}

$w = static fn (string $k): string => h((string)($vorbelegt[$k] ?? ''));

// ------------------------------------------------------------------ Ansicht bestimmen
// Angemeldet ist die Seite der BEREICH der Gruppe („eine Art Dashboard
// für die Betreiber"): '' = Übersicht, 'neu' = Beitrag anlegen (nur auf aktiven Klick),
// 'profil' = öffentliches Profil. Formular-Fehler und Profil-Speichern ziehen ihre
// Ansicht selbst, damit niemand nach einem POST vor der falschen Seite steht.
$tab = wl_param($_GET['t'] ?? '');
if (!in_array($tab, ['', 'neu', 'profil', 'promo'], true)) $tab = '';
if ($fehler !== '' || $vorbelegt) $tab = 'neu';
if ($profilOk) $tab = 'profil';
if ($promoOk !== '' || $promoFehler !== '') $tab = 'promo';

$GLOBALS['wl_flatpickr'] = $org && $fertig === null && $tab === 'neu'; // Datums-Wähler nur fürs Formular
wl_head('Veranstaltung eintragen', 'Hochschulgruppen und Fachschaften tragen hier ihre Veranstaltungen und Kurse ein.', '', true, '', true);
wl_nav();
?>

<?php /* Die Formular-Ansicht braucht Platz für die klebende Vorschau-Spalte – nur sie
         wird breit, alle anderen Ansichten behalten die schmale Lese-Spalte. */ ?>
<div class="wl-form<?= ($org && $fertig === null && $tab === 'neu') ? ' wl-form-breit' : '' ?>">

<?php if ($fertig !== null && $fertigArt === 'bearb_wieder'): ?>
  <h1>Neu eingereicht</h1>
  <p class="wl-note ok"><strong><?= h((string)$fertig['title']) ?></strong> ist überarbeitet und
    liegt jetzt wieder beim AStA zur Freigabe – die alte Ablehnung ist damit vom Tisch.</p>
  <div class="wl-btns">
    <a class="wl-btn p" href="einreichen.php">Zur Übersicht</a>
  </div>

<?php elseif ($fertig !== null && $fertigArt === 'bearb_wartend'): ?>
  <h1>Änderung eingereicht</h1>
  <p class="wl-note ok">Eure Änderung an <strong><?= h((string)$fertig['title']) ?></strong> liegt jetzt
    beim AStA. Bis zur Freigabe bleibt die bisherige Fassung online – es gibt keine Lücke im
    Programm, und alle Links und Erinnerungen bleiben gültig.</p>
  <div class="wl-btns">
    <a class="wl-btn p" href="einreichen.php">Zur Übersicht</a>
    <a class="wl-btn" href="v.php?id=<?= (int)$fertig['id'] ?>">Bisherige Fassung ansehen</a>
  </div>

<?php elseif ($fertig !== null && $fertigArt === 'bearb_direkt'): ?>
  <h1>Gespeichert</h1>
  <?php if ((string)$fertig['status'] === 'live'): ?>
    <p class="wl-note ok"><strong><?= h((string)$fertig['title']) ?></strong> ist aktualisiert –
      die Änderungen stehen sofort öffentlich.</p>
    <div class="wl-btns">
      <a class="wl-btn p" href="v.php?id=<?= (int)$fertig['id'] ?>">Ansehen</a>
      <a class="wl-btn" href="einreichen.php">Zur Übersicht</a>
    </div>
  <?php else: ?>
    <p class="wl-note ok"><strong><?= h((string)$fertig['title']) ?></strong> ist aktualisiert und
      liegt weiter beim AStA zur Freigabe.</p>
    <div class="wl-btns">
      <a class="wl-btn p" href="einreichen.php">Zur Übersicht</a>
    </div>
  <?php endif; ?>

<?php elseif ($fertig !== null): ?>
  <h1>Danke — das war's!</h1>
  <?php if ((string)$fertig['status'] === 'live'): ?>
    <p class="wl-note ok">Dein Beitrag <strong><?= h((string)$fertig['title']) ?></strong> ist online.</p>
    <?php /* Der Moment mit dem größten Schwung: Direkt nach dem Eintragen gibt es das fertige
             Werbematerial – Insta-Bilder, QR und Text aus genau diesen Daten, ohne Doppelaufwand. */ ?>
    <div class="wl-btns">
      <a class="wl-btn p" href="teilen.php?e=<?= (int)$fertig['id'] ?>">Jetzt teilen – Bilder, QR &amp; Text</a>
      <a class="wl-btn" href="v.php?id=<?= (int)$fertig['id'] ?>">Ansehen</a>
      <a class="wl-btn" href="einreichen.php?t=neu">Noch etwas eintragen</a>
      <a class="wl-btn" href="einreichen.php">Zur Übersicht</a>
    </div>
  <?php else: ?>
    <p class="wl-note ok">Dein Beitrag <strong><?= h((string)$fertig['title']) ?></strong> liegt jetzt
      beim AStA zur Freigabe. Sobald er durch ist, steht er öffentlich auf was.läuft – und auf seiner
      Teilen-Seite wartet dann fertiges Werbematerial (Insta-Bilder, QR-Code, Text) aus euren Angaben.</p>
    <?= wl_absaetze(strtr(wl_text('danke_wartend'),
        ['{{MAIL}}' => trim((string)$org['mail']) !== '' ? (string)$org['mail'] : 'eurer hinterlegten Adresse']), 'wl-lead') ?>
    <div class="wl-btns">
      <a class="wl-btn p" href="einreichen.php?t=neu">Noch etwas eintragen</a>
      <a class="wl-btn" href="einreichen.php">Zur Übersicht</a>
    </div>
  <?php endif; ?>

<?php elseif (!$org): ?>
  <?php /* Die Seite hinter „+ Eintragen" ohne Anmeldung: EINE schmale Spalte, mittig.
           Oben die dunkle Login-Karte (die „Tür" – sie trägt die Bühnen-Lichter der Marke),
           „Passwort vergessen?" und „Ihr wollt dabei sein?" sind ZUGEKLAPPTE <details>
           darunter – erst beim Draufdrücken geht das jeweilige Formular auf. So konkurriert
           nichts mit dem Login, und die Seite ist kein Formularfriedhof.
           Die Anfrage landet als Stapel in der Verwaltung (Ticket statt Mail); die
           Passwort-Mail verschickt die Seite selbst über den gemeinsamen Mail-Topf. */ ?>
  <div class="wl-zugang">
  <h1>Veranstaltung eintragen</h1>
  <?= wl_absaetze(wl_text('zugang_lead'), 'wl-lead') ?>

  <?php if ($neupassOk): ?>
    <p class="wl-note ok">Passwort gesetzt! Ihr könnt euch jetzt anmelden.</p>
  <?php elseif ($neupassFehler !== ''): ?>
    <p class="wl-note bad"><?= h($neupassFehler) ?></p>
  <?php endif; ?>

  <?php if ($resetOrg): ?>
    <?php /* Ankunft über den Passwort-Link aus der Mail: nur das Setz-Formular, kein Login. */ ?>
    <div class="wl-tor wl-tor-dunkel">
      <h2>Passwort festlegen</h2>
      <p class="wl-hint">Für den Zugang von <strong><?= h((string)$resetOrg['name']) ?></strong>.
         Mindestens 8 Zeichen.</p>
      <form method="post" action="einreichen.php">
        <?= wl_csrf_field() ?>
        <input type="hidden" name="was" value="neupass">
        <input type="hidden" name="token" value="<?= h(wl_param($_GET['reset'] ?? '')) ?>">
        <div class="wl-tor-feld">
          <label for="n_pass1">Neues Passwort</label>
          <input type="password" name="pass1" id="n_pass1" minlength="8" maxlength="200" required
                 autocomplete="new-password">
        </div>
        <div class="wl-tor-feld">
          <label for="n_pass2">Noch einmal</label>
          <input type="password" name="pass2" id="n_pass2" minlength="8" maxlength="200" required
                 autocomplete="new-password">
        </div>
        <button class="wl-tor-knopf" type="submit">Passwort speichern</button>
      </form>
    </div>
  <?php else: ?>
    <div class="wl-tor wl-tor-dunkel">
      <h2>Anmelden</h2>
      <p class="wl-hint">Mit der Mailadresse eurer Gruppe.</p>
      <?php if ($loginFehler !== ''): ?><p class="wl-note bad"><?= h($loginFehler) ?></p><?php endif; ?>
      <form method="post" action="einreichen.php">
        <?= wl_csrf_field() ?>
        <input type="hidden" name="was" value="login">
        <div class="wl-tor-feld">
          <label for="l_mail">Mailadresse</label>
          <input type="email" name="mail" id="l_mail" maxlength="200" required
                 autocomplete="username" value="<?= h($loginAlt) ?>">
        </div>
        <div class="wl-tor-feld">
          <label for="l_pass">Passwort</label>
          <input type="password" name="passwort" id="l_pass" maxlength="200" required
                 autocomplete="current-password">
        </div>
        <button class="wl-tor-knopf" type="submit">Anmelden</button>
      </form>
    </div>

    <details class="wl-klapp"<?= $resetOk ? ' open' : '' ?>>
      <summary>Passwort vergessen?</summary>
      <?php if ($resetOk): ?>
        <p class="wl-note ok">Wenn die Adresse zu einem Zugang gehört, ist die Mail mit dem
           Passwort-Link unterwegs. Schaut auch in den Spam-Ordner.</p>
      <?php else: ?>
        <p class="wl-hint">Wir schicken euch einen Link an die Mailadresse eurer Gruppe —
           darüber legt ihr einfach ein neues Passwort fest.</p>
        <form method="post" action="einreichen.php">
          <?= wl_csrf_field() ?>
          <input type="hidden" name="was" value="reset">
          <div class="wl-field">
            <label for="r_mail">Mailadresse eurer Gruppe</label>
            <input type="email" name="mail" id="r_mail" maxlength="200" required>
          </div>
          <div class="wl-btns">
            <button class="wl-btn p" type="submit">Link schicken</button>
          </div>
        </form>
      <?php endif; ?>
    </details>

    <details class="wl-klapp"<?= ($anfrageOk || $anfrageFehler !== '') ? ' open' : '' ?>>
      <summary>Noch keinen Zugang? Dabei sein!</summary>
      <?php if ($anfrageOk): ?>
        <?= wl_absaetze(wl_text('anfrage_ok'), 'wl-note ok') ?>
      <?php else: ?>
        <?= wl_absaetze(wl_text('anfrage_hint'), 'wl-hint') ?>
        <?php if ($anfrageFehler !== ''): ?><p class="wl-note bad"><?= h($anfrageFehler) ?></p><?php endif; ?>
        <form method="post" action="einreichen.php">
          <?= wl_csrf_field() ?>
          <input type="hidden" name="was" value="anfrage">
          <?php /* Unsichtbares Feld gegen Spam-Skripte – Menschen sehen und füllen es nie. */ ?>
          <input class="wl-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
          <div class="wl-field">
            <label for="a_gruppe">Eure Gruppe *</label>
            <input type="text" name="gruppe" id="a_gruppe" maxlength="120" required
                   placeholder="HSG …, Fachschaft …, Verein …" value="<?= h((string)($anfrageAlt['gruppe'] ?? '')) ?>">
          </div>
          <div class="wl-field">
            <label for="a_mail">Mailadresse *</label>
            <input type="email" name="mail" id="a_mail" maxlength="200" required
                   value="<?= h((string)($anfrageAlt['mail'] ?? '')) ?>">
          </div>
          <div class="wl-field">
            <label for="a_nachricht">Was wollt ihr eintragen? (optional)</label>
            <textarea name="nachricht" id="a_nachricht" maxlength="1000" rows="2"><?= h((string)($anfrageAlt['nachricht'] ?? '')) ?></textarea>
          </div>
          <div class="wl-btns">
            <button class="wl-btn p" type="submit">Zugang anfragen</button>
          </div>
        </form>
      <?php endif; ?>
    </details>
  <?php endif; ?>

  <?php $kontakt = trim(wl_setting('kontakt_mail', '')); ?>
  <?php if ($kontakt !== ''): ?>
    <p class="wl-zugang-kontakt">Fragen? Schreibt uns:
      <a href="mailto:<?= h($kontakt) ?>"><?= h($kontakt) ?></a></p>
  <?php endif; ?>
  </div>

<?php elseif ($tab === 'profil'): ?>
  <?php /* Die Visitenkarte der Gruppe auf veranstalter.php – wer sie füllt, ist abonnierbar
           mit Gesicht. Alles freiwillig, ein leeres Feld verschwindet auf der Seite einfach. */ ?>
  <div class="wl-form-kopf">
    <h1>Euer öffentliches Profil</h1>
    <a class="wl-btn" href="einreichen.php">← Zur Übersicht</a>
  </div>
  <?php if ($profilOk): ?><p class="wl-note ok">Profil gespeichert.</p><?php endif; ?>
  <p class="wl-hint">So erscheint ihr auf eurer <a href="veranstalter.php?id=<?= (int)$org['id'] ?>">Veranstalter-Seite</a> –
    dort können Studis euch auch <strong>abonnieren</strong> und bekommen automatisch eine
    Mitteilung, sobald ihr etwas Neues eintragt.</p>
  <form method="post" action="einreichen.php">
    <?= wl_csrf_field() ?>
    <input type="hidden" name="was" value="profil">
    <div class="wl-field">
      <label for="p_profil">Wer seid ihr, was macht ihr? (öffentlich)</label>
      <textarea name="profil" id="p_profil" maxlength="600" rows="4"
        placeholder="2–3 Sätze über eure Gruppe …"><?= h((string)($org['profil'] ?? '')) ?></textarea>
    </div>
    <div class="wl-field">
      <label for="p_web">Website (optional)</label>
      <input type="text" inputmode="url" name="web" id="p_web" maxlength="300" placeholder="euer-verein.de"
             value="<?= h((string)($org['web'] ?? '')) ?>">
    </div>
    <div class="wl-field">
      <label for="p_insta">Instagram (optional, Handle oder Adresse)</label>
      <input type="text" name="insta" id="p_insta" maxlength="300" placeholder="@euregruppe"
             value="<?= h((string)($org['insta'] ?? '')) ?>">
    </div>
    <div class="wl-btns">
      <button class="wl-btn p" type="submit">Profil speichern</button>
    </div>
  </form>

<?php elseif ($tab === 'promo'): ?>
  <?php /* PROMO der Gruppe: Logo (Absender-Marke auf Veranstalter-Seite + Detailseite)
           und die eigenen Bilder fürs Anlegen – Deckel WL_ORG_IMG_MAX, zentral erzwungen. */
        $logo   = wl_image((int)($org['logo_id'] ?? 0));
        $bilder = wl_org_bilder((int)$org['id']); ?>
  <div class="wl-form-kopf">
    <h1>Promo</h1>
    <a class="wl-btn" href="einreichen.php">← Zur Übersicht</a>
  </div>
  <?php if ($promoOk !== ''): ?><p class="wl-note ok"><?= h($promoOk) ?></p><?php endif; ?>
  <?php if ($promoFehler !== ''): ?><p class="wl-note bad"><?= h($promoFehler) ?></p><?php endif; ?>

  <section class="wl-nb-abs">
    <h2 class="wl-nb-h">Euer Logo</h2>
    <p class="wl-hint">Erscheint als eure Absender-Marke auf der Veranstalter-Seite und bei euren
       Veranstaltungen. Ladet nur euer eigenes Logo hoch. Ohne Logo zeigt die Seite den farbigen
       Kurz-Punkt.</p>
    <div class="wl-logo-zeile">
      <?php if ($logo): ?>
        <img class="wl-logo-gross" src="<?= h(wl_img_url((string)$logo['file'])) ?>" alt="Euer Logo">
      <?php else: ?>
        <?= wl_org_avatar($org, 'gross') ?>
      <?php endif; ?>
      <?php if (wl_image_engine() !== ''): ?>
        <form method="post" enctype="multipart/form-data" action="einreichen.php" class="wl-logo-form">
          <?= wl_csrf_field() ?>
          <input type="hidden" name="was" value="logo">
          <input type="file" name="logo" accept="<?= h(implode(',', array_keys(wl_image_types()))) ?>" required>
          <div class="wl-btns">
            <button class="wl-btn p" type="submit"><?= $logo ? 'Logo ersetzen' : 'Logo hochladen' ?></button>
          </div>
        </form>
        <?php if ($logo): ?>
          <form method="post" action="einreichen.php">
            <?= wl_csrf_field() ?>
            <input type="hidden" name="was" value="logo">
            <input type="hidden" name="weg" value="1">
            <button class="wl-btn s" type="submit">Logo entfernen</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="wl-note warn">Bild-Uploads sind auf diesem Server gerade nicht möglich.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="wl-nb-abs">
    <h2 class="wl-nb-h">Eure Bilder <span class="wl-nb-zahl"><?= count($bilder) ?> von <?= WL_ORG_IMG_MAX ?></span></h2>
    <p class="wl-hint">Diese Bilder stehen euch beim Anlegen von Veranstaltungen und Kursen zur
       Auswahl – einmal hochladen, immer wieder nutzen. Ein Bild, das gerade an einem Beitrag
       hängt, lässt sich nicht löschen.</p>
    <?php if ($bilder): ?>
      <div class="wl-promo-bilder">
        <?php foreach ($bilder as $b): ?>
          <figure class="wl-promo-bild">
            <img src="<?= h(wl_img_url((string)$b['file'])) ?>" alt="<?= h((string)$b['credit']) ?>" loading="lazy" width="640" height="400">
            <form method="post" action="einreichen.php">
              <?= wl_csrf_field() ?>
              <input type="hidden" name="was" value="bildweg">
              <input type="hidden" name="bild_id" value="<?= (int)$b['id'] ?>">
              <button class="wl-btn s" type="submit">Löschen</button>
            </form>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="wl-hint">Noch keine eigenen Bilder – das erste kommt gleich hier drunter rein.</p>
    <?php endif; ?>

    <?php if (wl_image_engine() !== '' && count($bilder) < WL_ORG_IMG_MAX): ?>
      <form method="post" enctype="multipart/form-data" action="einreichen.php" class="wl-promo-neu">
        <?= wl_csrf_field() ?>
        <input type="hidden" name="was" value="bildneu">
        <div class="wl-field">
          <label for="pr_bild">Neues Bild hochladen</label>
          <input type="file" name="bild" id="pr_bild" accept="<?= h(implode(',', array_keys(wl_image_types()))) ?>" required>
          <p class="wl-hint">Höchstens <?= (int)round(WL_IMG_MAX_BYTES / 1048576) ?> MB. Wir verkleinern es
             automatisch und entfernen dabei versteckte Zusatzdaten wie den Aufnahmeort.</p>
        </div>
        <div class="wl-field">
          <label for="pr_credit">Bildnachweis (optional)</label>
          <input type="text" name="credit" id="pr_credit" maxlength="120" placeholder="Foto: Vorname Nachname">
        </div>
        <label class="wl-check">
          <input type="checkbox" name="rechte" value="1" required>
          <span><strong>Wir haben die Rechte an diesem Bild</strong> und dürfen es hier veröffentlichen lassen.</span>
        </label>
        <div class="wl-btns">
          <button class="wl-btn p" type="submit">Bild hochladen</button>
        </div>
      </form>
    <?php elseif (count($bilder) >= WL_ORG_IMG_MAX): ?>
      <p class="wl-note warn">Euer Kontingent ist voll (<?= WL_ORG_IMG_MAX ?> Bilder) – löscht erst
         welche, die ihr nicht mehr braucht.</p>
    <?php endif; ?>
  </section>

<?php elseif ($tab !== 'neu'): ?>
  <?php /* DAS DASHBOARD der Gruppe (erst die Übersicht, das Formular nur
           auf ausdrücklichen Klick – hier wird es künftig mehr geben als das Eintragen).
           Oben die Lage in drei Zahlen, dann die Wege als Karten, darunter die Beiträge –
           offen sichtbar, nicht mehr zugeklappt: Von jedem Live-Eintrag geht es direkt
           zum fertigen Werbematerial (Teilen-Seite). */
        $zahl = static function (string $sql, array $par) { $s = wl_db()->prepare($sql); $s->execute($par); return (int)$s->fetchColumn(); };
        $zLive = $zahl("SELECT COUNT(*) FROM items WHERE org_id = ? AND status = 'live'", [(int)$org['id']]);
        $zWart = $zahl("SELECT COUNT(*) FROM items WHERE org_id = ? AND status = 'pending'", [(int)$org['id']]);
        $zAbos = $zahl('SELECT COUNT(*) FROM push_follows WHERE org_id = ?', [(int)$org['id']]);
        // Der Rückkanal: Aufrufe zeigen, dass sich das Eintragen lohnt.
        $zAufr = $zahl('SELECT COALESCE(SUM(aufrufe), 0) FROM items WHERE org_id = ?', [(int)$org['id']]);
        // Abgelehntes steht MIT in der Liste – samt Begründung und dem Weg zurück:
        // Überarbeiten setzt es wieder auf „wartet".
        $meine = wl_db()->prepare("SELECT id, title, kind, status, starts_at, ends_at, bis_datum,
                   abgesagt, aufrufe, note,
                   (SELECT COUNT(*) FROM item_edits e WHERE e.item_id = items.id) AS wartet_edit,
                   (SELECT COUNT(*) FROM push_reminders r WHERE r.item_id = items.id) AS wecker
            FROM items
            WHERE org_id = ? AND status IN ('live','pending','rejected')
            ORDER BY status = 'rejected' DESC, status = 'pending' DESC, starts_at DESC, id DESC LIMIT 30");
        $meine->execute([(int)$org['id']]);
        $meine = $meine->fetchAll(); ?>
  <div class="wl-dash">
    <div class="wl-dash-kopf">
      <h1><?= h((string)$org['name']) ?></h1>
      <form method="post" action="einreichen.php">
        <?= wl_csrf_field() ?>
        <input type="hidden" name="was" value="abmelden">
        <button class="wl-btn s" type="submit">Abmelden</button>
      </form>
    </div>
    <p class="wl-hint">Euer Bereich auf was.läuft.</p>

    <div class="wl-dash-zahlen">
      <div class="wl-dz"><b><?= $zLive ?></b><span><?= $zLive === 1 ? 'Beitrag online' : 'Beiträge online' ?></span></div>
      <div class="wl-dz"><b><?= $zWart ?></b><span><?= $zWart === 1 ? 'wartet auf Freigabe' : 'warten auf Freigabe' ?></span></div>
      <div class="wl-dz"><b><?= $zAbos ?></b><span><?= $zAbos === 1 ? 'Abo auf eure Gruppe' : 'Abos auf eure Gruppe' ?></span></div>
      <div class="wl-dz"><b><?= $zAufr ?></b><span><?= $zAufr === 1 ? 'Aufruf eurer Beiträge' : 'Aufrufe eurer Beiträge' ?></span></div>
    </div>

    <div class="wl-dash-karten">
      <a class="wl-dk p" href="einreichen.php?t=neu">
        <strong>+ Veranstaltung oder Kurs anlegen</strong>
        <span>Termin, Bild, Beschreibung – nach der Freigabe steht euer Beitrag öffentlich.</span>
      </a>
      <?php /* Profil und Veranstalter-Seite sind EINE Sache, keine zwei Karten: Die Karte
               führt zur Seite, und DORT sitzt der Bearbeiten-Knopf. Daneben als eigener Weg:
               Promo (Logo + eigene Bilder). */ ?>
      <a class="wl-dk" href="veranstalter.php?id=<?= (int)$org['id'] ?>">
        <strong>Eure Veranstalter-Seite</strong>
        <span><?= trim((string)($org['profil'] ?? '')) !== ''
            ? 'So sehen euch die Studis – mit Bearbeiten-Knopf für euer Profil.'
            : 'Noch ohne Profiltext – über den Bearbeiten-Knopf dort stellt ihr euch vor.' ?></span>
      </a>
      <a class="wl-dk" href="einreichen.php?t=promo">
        <strong>Promo</strong>
        <span>Euer Logo und bis zu <?= WL_ORG_IMG_MAX ?> eigene Bilder für eure Beiträge.</span>
      </a>
    </div>

    <h2>Eure Beiträge</h2>
    <?php if ($dashOk !== ''): ?><p class="wl-note ok"><?= h($dashOk) ?></p><?php endif; ?>
    <?php if ($dashFehler !== ''): ?><p class="wl-note bad"><?= h($dashFehler) ?></p><?php endif; ?>
    <?php if ($meine): ?>
      <ul class="wl-meine">
        <?php foreach ($meine as $m): ?>
          <?php $mVorbei = wl_item_vorbei($m); $mAbges = (int)($m['abgesagt'] ?? 0) === 1; ?>
          <li>
            <span class="wl-meine-t"><strong><?= h((string)$m['title']) ?></strong>
              <?= (string)$m['status'] === 'pending' ? '<em>wartet auf Freigabe</em>' : '' ?>
              <?= (string)$m['status'] === 'rejected' ? '<em class="wl-meine-abges">nicht freigegeben</em>' : '' ?>
              <?= $mAbges ? '<em class="wl-meine-abges">abgesagt</em>' : '' ?>
              <?= (int)($m['wartet_edit'] ?? 0) > 0 ? '<em>Änderung wartet auf Freigabe</em>' : '' ?>
              <?php /* Der Rückkanal je Beitrag: Aufrufe + gesetzte „Erinnere mich". */ ?>
              <?php if ((string)$m['status'] === 'live'): ?>
                <em><?= (int)$m['aufrufe'] ?> <?= (int)$m['aufrufe'] === 1 ? 'Aufruf' : 'Aufrufe' ?><?php
                  if ((int)($m['wecker'] ?? 0) > 0): ?> · <?= (int)$m['wecker'] ?> <?= (int)$m['wecker'] === 1 ? 'Erinnerung gesetzt' : 'Erinnerungen gesetzt' ?><?php endif; ?></em>
              <?php endif; ?>
              <?php /* Die Begründung des AStA – genau hier, wo der Weg zurück beginnt. */ ?>
              <?php if ((string)$m['status'] === 'rejected' && trim((string)($m['note'] ?? '')) !== ''): ?>
                <span class="wl-meine-grund">Begründung: <?= h((string)$m['note']) ?></span>
              <?php endif; ?></span>
            <span class="wl-meine-a">
              <?php if ((string)$m['status'] === 'live'): ?>
                <a class="wl-btn p" href="teilen.php?e=<?= (int)$m['id'] ?>">Teilen</a>
                <a class="wl-btn" href="v.php?id=<?= (int)$m['id'] ?>">Ansehen</a>
              <?php endif; ?>
              <a class="wl-btn<?= (string)$m['status'] === 'rejected' ? ' p' : '' ?>" href="einreichen.php?bearb=<?= (int)$m['id'] ?>"><?=
                (string)$m['status'] === 'rejected' ? 'Überarbeiten &amp; neu einreichen' : 'Bearbeiten' ?></a>
              <?php /* Absagen statt löschen: Beitrag bleibt mit Banderole stehen, Erinnerte
                       bekommen eine Mitteilung. Rückfrage über den eigenen Dialog unten. */ ?>
              <?php if ((string)$m['status'] === 'live' && (string)$m['kind'] === 'event' && !$mVorbei): ?>
                <?php if (!$mAbges): ?>
                  <form method="post" action="einreichen.php" class="wl-beitrag-absage" data-titel="<?= h((string)$m['title']) ?>">
                    <?= wl_csrf_field() ?>
                    <input type="hidden" name="was" value="absage">
                    <input type="hidden" name="item_id" value="<?= (int)$m['id'] ?>">
                    <button class="wl-btn" type="submit">Absagen</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="einreichen.php">
                    <?= wl_csrf_field() ?>
                    <input type="hidden" name="was" value="absagezurueck">
                    <input type="hidden" name="item_id" value="<?= (int)$m['id'] ?>">
                    <button class="wl-btn" type="submit">Absage zurücknehmen</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
              <?php /* Papierkorb in Rot, gleiche Höhe wie die Nachbarn – die Rückfrage
                       stellt der eigene Dialog unten (kein Browser-confirm, wie in der App). */ ?>
              <form method="post" action="einreichen.php" class="wl-beitrag-weg" data-titel="<?= h((string)$m['title']) ?>">
                <?= wl_csrf_field() ?>
                <input type="hidden" name="was" value="beitragweg">
                <input type="hidden" name="item_id" value="<?= (int)$m['id'] ?>">
                <button class="wl-btn wl-weg-btn" type="submit" aria-label="Beitrag löschen" title="Löschen">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>
                  </svg>
                </button>
              </form>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>

      <dialog class="wl-dialog" id="wl-weg-dialog">
        <h3>Beitrag löschen?</h3>
        <p><strong id="wl-weg-titel"></strong> wird endgültig gelöscht – samt aller
           „Erinnere mich"-Einträge darauf. Das lässt sich nicht rückgängig machen.</p>
        <p class="wl-hint">Findet die Veranstaltung nur nicht statt? Dann lieber
           <strong>Absagen</strong> – der Beitrag bleibt mit Hinweis stehen und alle
           Erinnerten erfahren davon.</p>
        <div class="wl-btns">
          <button class="wl-btn" type="button" id="wl-weg-nein">Abbrechen</button>
          <button class="wl-btn wl-btn-rot" type="button" id="wl-weg-ja">Ja, löschen</button>
        </div>
      </dialog>
      <dialog class="wl-dialog" id="wl-absage-dialog">
        <h3>Veranstaltung absagen?</h3>
        <p><strong id="wl-absage-titel"></strong> wird als abgesagt markiert. Der Beitrag bleibt
           mit Absage-Hinweis sichtbar, und alle, die sich erinnern lassen wollten, bekommen
           eine Mitteilung. Ihr könnt die Absage jederzeit zurücknehmen.</p>
        <div class="wl-btns">
          <button class="wl-btn" type="button" id="wl-absage-nein">Abbrechen</button>
          <button class="wl-btn wl-btn-rot" type="button" id="wl-absage-ja">Ja, absagen</button>
        </div>
      </dialog>
      <script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>">
      (function () {
        'use strict';
        // Ein Rückfrage-Muster, zwei Dialoge: Das Formular wird angehalten, der Dialog fragt,
        // die Bestätigung schickt es wirklich ab (dataset.ok lässt den submit dann durch).
        function rueckfrage(formKlasse, dialogId, titelId, neinId, jaId) {
          var dialog = document.getElementById(dialogId);
          if (!dialog) return;
          var offen = null;
          document.querySelectorAll(formKlasse).forEach(function (f) {
            f.addEventListener('submit', function (e) {
              if (f.dataset.ok) return;
              e.preventDefault();
              offen = f;
              document.getElementById(titelId).textContent = '„' + f.dataset.titel + '"';
              dialog.showModal();
            });
          });
          document.getElementById(neinId).addEventListener('click', function () { dialog.close(); });
          document.getElementById(jaId).addEventListener('click', function () {
            dialog.close();
            if (offen) { offen.dataset.ok = '1'; offen.requestSubmit(); }
          });
        }
        rueckfrage('.wl-beitrag-weg', 'wl-weg-dialog', 'wl-weg-titel', 'wl-weg-nein', 'wl-weg-ja');
        rueckfrage('.wl-beitrag-absage', 'wl-absage-dialog', 'wl-absage-titel', 'wl-absage-nein', 'wl-absage-ja');
      })();
      </script>
    <?php else: ?>
      <p class="wl-hint">Noch keine Beiträge – legt mit eurem ersten los!</p>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php /* Bearbeiten nutzt DASSELBE Formular (verstecktes Feld `bearb`): Vorbefüllung und
           Live-Vorschau funktionieren unverändert, und es kann nie zwei Formulare geben,
           die auseinanderlaufen. Kopf und Abschick-Knopf sagen, was passiert. */
        $bearbAkt = 0; $bearbItAkt = null;
        if (!empty($vorbelegt['bearb'])) {
            $bearbItAkt = wl_item((int)$vorbelegt['bearb']);
            if ($bearbItAkt && (int)($bearbItAkt['org_id'] ?? 0) === (int)$org['id']
                && in_array((string)$bearbItAkt['status'], ['pending', 'live', 'rejected'], true)) {
                $bearbAkt = (int)$vorbelegt['bearb'];
            } else { $bearbItAkt = null; }
        }
        $liveStapel = $bearbItAkt !== null && (string)$bearbItAkt['status'] === 'live'
                   && (int)$org['auto_ok'] !== 1;
        $wiederEin  = $bearbItAkt !== null && (string)$bearbItAkt['status'] === 'rejected'; ?>
  <div class="wl-form-kopf">
    <h1><?= $wiederEin ? 'Überarbeiten & neu einreichen' : ($bearbAkt > 0 ? 'Beitrag bearbeiten' : 'Neuer Beitrag') ?></h1>
    <a class="wl-btn" href="einreichen.php">← Zur Übersicht</a>
  </div>
  <?php if ($wiederEin): ?>
    <p class="wl-lead">Ihr überarbeitet <strong><?= h((string)$bearbItAkt['title']) ?></strong> –
      mit dem Abschicken landet er wieder beim AStA zur Freigabe.</p>
    <?php if (trim((string)($bearbItAkt['note'] ?? '')) !== ''): ?>
      <p class="wl-note warn"><strong>Begründung des AStA:</strong>
        <?= h((string)$bearbItAkt['note']) ?></p>
    <?php endif; ?>
  <?php elseif ($bearbAkt > 0): ?>
    <?php if ($liveStapel): ?>
      <p class="wl-lead">Ihr ändert <strong><?= h((string)$bearbItAkt['title']) ?></strong>. Die
        bisherige Fassung bleibt online, bis der AStA eure Änderung freigibt – Links und
        gesetzte Erinnerungen bleiben dabei erhalten.</p>
    <?php else: ?>
      <p class="wl-lead">Ihr ändert <strong><?= h((string)$bearbItAkt['title']) ?></strong>.</p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($bearbAkt === 0): ?><?= wl_absaetze(wl_text('formular_lead'), 'wl-lead') ?><?php endif; ?>

  <?php if ($fehler !== ''): ?><p class="wl-note bad"><?= h($fehler) ?></p><?php endif; ?>
  <?php if (wl_mode() === 'soft' && $bearbAkt === 0): ?>
    <p class="wl-note warn">Gerade werden keine neuen Beiträge angenommen.</p>
  <?php endif; ?>

  <?php /* Nummerierte ABSCHNITTE statt einer Feld-Wurst, der Typ als zwei KACHELN statt
           Aufklappmenü, und sichtbar sind nur die Felder des gewählten Typs (data-nur +
           Skript; ohne Skript bleibt alles sichtbar). Die Live-Vorschau klebt ab 900px rechts
           NEBEN dem Formular (aside im form-Grid), nicht am Seitenende – man sieht beim
           Tippen zu. */ ?>
  <form class="wl-neu" method="post" enctype="multipart/form-data" action="einreichen.php">
    <?= wl_csrf_field() ?>
    <?php if ($bearbAkt > 0): ?><input type="hidden" name="bearb" value="<?= $bearbAkt ?>"><?php endif; ?>
    <div class="wl-nb-haupt">

    <section class="wl-nb-abs">
      <h2 class="wl-nb-h"><span>1</span>Was ist es?</h2>
      <?php $kindAlt = ($vorbelegt['kind'] ?? 'event') === 'kurs' ? 'kurs' : 'event'; ?>
      <div class="wl-typ">
        <label>
          <input type="radio" name="kind" value="event"<?= $kindAlt === 'event' ? ' checked' : '' ?>>
          <strong>Veranstaltung</strong>
          <span>Einmalig, mit Datum — Party, Vortrag, Turnier …</span>
        </label>
        <label>
          <input type="radio" name="kind" value="kurs"<?= $kindAlt === 'kurs' ? ' checked' : '' ?>>
          <strong>Kurs / regelmäßig</strong>
          <span>Wöchentlich &amp; Co. — landet im eigenen Kurse-Bereich.</span>
        </label>
      </div>
    </section>

    <section class="wl-nb-abs">
      <h2 class="wl-nb-h"><span>2</span>Worum geht es?</h2>
      <div class="wl-field">
        <label for="title">Titel *</label>
        <input type="text" name="title" id="title" maxlength="120" required value="<?= $w('title') ?>">
      </div>
      <div class="wl-field">
        <label>Kategorie *</label>
        <?php /* Pillen statt Aufklappmenü – Radios, eine ist immer gewählt. Die gestrichelte
                 Pille öffnet den Vorschlags-Dialog (unten, per fetch): Wünsche landen in der
                 Verwaltung, für den Beitrag muss
                 trotzdem eine BESTEHENDE Kategorie gewählt bleiben. */
              $katAlt = isset(wl_cats()[(string)($vorbelegt['cat'] ?? '')]) ? (string)$vorbelegt['cat'] : array_key_first(wl_cats()); ?>
        <div class="wl-kats">
          <?php foreach (wl_cats() as $ck => $cd): ?>
            <label class="wl-kat-pill">
              <input type="radio" name="cat" value="<?= h($ck) ?>"<?= $katAlt === $ck ? ' checked' : '' ?>>
              <span><?= h((string)$cd['icon']) ?> <?= h((string)$cd['label']) ?></span>
            </label>
          <?php endforeach; ?>
          <button type="button" class="wl-kat-neu" id="wl-kat-neu">+ Kategorie vorschlagen</button>
        </div>
      </div>
      <div class="wl-field">
        <label for="teaser">Eine Zeile für die Kachel</label>
        <input type="text" name="teaser" id="teaser" maxlength="160" value="<?= $w('teaser') ?>">
        <p class="wl-hint">Kurz und konkret. Bleibt es leer, nehmen wir den Anfang der Beschreibung.</p>
      </div>
      <?php /* Englische Kurzfassung – nur sichtbar, wenn der AStA sie in den Einstellungen
               freigeschaltet hat. Freiwillig: Wer nichts einträgt, hat nur Deutsch. */
            if (wl_en_an()): ?>
        <div class="wl-field">
          <label for="title_en">Titel auf Englisch <span class="wl-opt">(freiwillig)</span></label>
          <input type="text" name="title_en" id="title_en" maxlength="120" value="<?= $w('title_en') ?>">
        </div>
        <div class="wl-field">
          <label for="teaser_en">Die eine Zeile auf Englisch <span class="wl-opt">(freiwillig)</span></label>
          <input type="text" name="teaser_en" id="teaser_en" maxlength="160" value="<?= $w('teaser_en') ?>">
          <p class="wl-hint">Für internationale Studis. Wer auf Englisch surft, sieht diese Fassung
             zuerst – der Rest der Seite bleibt auf Deutsch.</p>
        </div>
      <?php endif; ?>
      <div class="wl-field">
        <label for="text">Beschreibung</label>
        <textarea name="text" id="text" maxlength="4000"><?= $w('text') ?></textarea>
      </div>
    </section>

    <section class="wl-nb-abs">
      <h2 class="wl-nb-h"><span>3</span>Wann &amp; wo?</h2>
      <?php // Beide Blöcke tragen wl-field: Daran hängt das Block-Layout der Labels. ?>
      <div class="wl-field" data-nur="event">
        <div class="wl-row">
          <div>
            <label for="starts_at">Beginn *</label>
            <input type="text" class="fp-datetime" name="starts_at" id="starts_at" placeholder="Datum &amp; Uhrzeit wählen" value="<?= $w('starts_at') ?>">
          </div>
          <div>
            <label for="ends_at">Ende (optional)</label>
            <input type="text" class="fp-datetime" name="ends_at" id="ends_at" placeholder="Datum &amp; Uhrzeit wählen" value="<?= $w('ends_at') ?>">
          </div>
        </div>
      </div>
      <div class="wl-field" data-nur="kurs">
        <div class="wl-row">
          <div>
            <label for="rhythmus">Rhythmus *</label>
            <input type="text" name="rhythmus" id="rhythmus" maxlength="80" placeholder="mittwochs 18:00" value="<?= $w('rhythmus') ?>">
          </div>
          <div>
            <label for="termine">Anzahl Termine</label>
            <input type="number" name="termine" id="termine" min="0" max="999" value="<?= $w('termine') ?>">
          </div>
        </div>
        <div class="wl-row">
          <div>
            <label for="von_datum">Kurs läuft ab</label>
            <input type="text" class="fp-date" name="von_datum" id="von_datum" placeholder="Datum wählen" value="<?= $w('von_datum') ?>">
          </div>
          <div>
            <label for="bis_datum">Kurs läuft bis</label>
            <input type="text" class="fp-date" name="bis_datum" id="bis_datum" placeholder="Datum wählen" value="<?= $w('bis_datum') ?>">
          </div>
        </div>
        <label class="wl-check"><input type="checkbox" name="einstieg" value="1"<?= !empty($vorbelegt['einstieg']) ? ' checked' : '' ?>>
          <span>Einstieg jederzeit möglich</span></label>
      </div>
      <div class="wl-row">
        <div class="wl-field">
          <label for="ort">Ort</label>
          <input type="text" name="ort" id="ort" maxlength="100" placeholder="Altes Kaufhaus" value="<?= $w('ort') ?>">
        </div>
        <div class="wl-field">
          <label for="adresse">Adresse</label>
          <input type="text" name="adresse" id="adresse" maxlength="160" placeholder="Rathausplatz 1" value="<?= $w('adresse') ?>">
        </div>
      </div>
    </section>

    <section class="wl-nb-abs">
      <h2 class="wl-nb-h"><span>4</span>Eintritt &amp; Details</h2>
      <?php /* Pflichtfrage OHNE Vorgabe: Wer einträgt, soll sich einmal
               entscheiden, statt dass ein unbemerktes Häkchen die Antwort gibt. Deshalb zwei
               gleichrangige Kacheln wie bei „Was ist es?" und kein Kästchen. Beim Bearbeiten
               steht die gespeicherte Antwort schon drin. */
            $studiAlt = (string)($vorbelegt['nur_studis'] ?? ''); ?>
      <div class="wl-field">
        <label>Wer darf kommen? *</label>
        <div class="wl-typ">
          <label>
            <input type="radio" name="nur_studis" value="0" required<?= $studiAlt === '0' ? ' checked' : '' ?>>
            <strong>Offen für alle</strong>
            <span>Jede und jeder kann kommen — auch ohne Studiausweis.</span>
          </label>
          <label>
            <input type="radio" name="nur_studis" value="1" required<?= $studiAlt === '1' ? ' checked' : '' ?>>
            <strong>Nur für Studierende</strong>
            <span>Einlass nur mit gültigem Studiausweis. Steht dann auf der Beitragsseite.</span>
          </label>
        </div>
      </div>
      <label class="wl-check"><input type="checkbox" name="frei" value="1"<?= (!$vorbelegt || !empty($vorbelegt['frei'])) ? ' checked' : '' ?>>
        <span><strong>Eintritt frei</strong> — bekommt eine eigene Marke auf der Kachel</span></label>
      <label class="wl-check"><input type="checkbox" name="barrierefrei" value="1"<?= !empty($vorbelegt['barrierefrei']) ? ' checked' : '' ?>>
        <span>Barrierefrei erreichbar</span></label>
      <div class="wl-row">
        <div class="wl-field">
          <label for="preis">Preis</label>
          <input type="text" name="preis" id="preis" maxlength="60" placeholder="3 €, Spende, 25 € für 6 Termine" value="<?= $w('preis') ?>">
          <p class="wl-hint">Leer lassen, wenn es nichts kostet.</p>
        </div>
        <?php /* Nur sichtbar, wenn ein Preis drinsteht (Skript unten) – ein Rabatt auf
                 „kostenlos" ergäbe keinen Sinn. Ohne Skript bleibt das Feld einfach da. */ ?>
        <div class="wl-field" id="f-rabatt"<?= trim((string)($vorbelegt['preis'] ?? '')) === '' && $vorbelegt ? ' hidden' : '' ?>>
          <label for="studi_rabatt">Studi-Rabatt (optional)</label>
          <input type="text" name="studi_rabatt" id="studi_rabatt" maxlength="120"
                 placeholder="3 € mit Studiausweis" value="<?= $w('studi_rabatt') ?>">
          <p class="wl-hint">Steht auf der Beitragsseite direkt unterm Preis.</p>
        </div>
      </div>
      <div class="wl-row">
        <div class="wl-field">
          <label for="ab_alter">Mindestalter</label>
          <input type="number" name="ab_alter" id="ab_alter" min="0" max="99" value="<?= $w('ab_alter') ?>">
          <p class="wl-hint">0 = keine Altersgrenze.</p>
        </div>
        <div></div>
      </div>
      <div class="wl-row">
        <div class="wl-field">
          <label for="url">Eure Seite (optional)</label>
          <input type="text" inputmode="url" name="url" id="url" placeholder="euer-verein.de/event" value="<?= $w('url') ?>">
        </div>
        <div class="wl-field">
          <label for="anmeldung_url">Anmeldung (optional)</label>
          <input type="text" inputmode="url" name="anmeldung_url" id="anmeldung_url" placeholder="euer-verein.de/anmeldung" value="<?= $w('anmeldung_url') ?>">
        </div>
      </div>
    </section>

    <?php $archiv = wl_images_for_org((int)$org['id']); ?>
    <fieldset class="wl-field wl-nb-abs">
      <legend class="wl-nb-h"><span>5</span>Bild</legend>
      <p class="wl-hint">Ohne eigenes Bild bekommt die Kachel ein Standard-Foto passend zur
         Kategorie — automatisch eines der drei, oder ihr sucht euch unten gezielt eines aus.
         Die Rechte an Standard- und Archiv-Bildern sind geklärt.</p>

      <?php /* Jede Wahl ist eine VORSCHAU der Kachel: dieselbe Bildfläche (3:2, runde Ecken)
               wie später auf der Startseite. Die erste Karte steht für den Automatik-Fall –
               „kein Bild" hieß in Wahrheit immer „Standard-Foto der Kategorie". Dahinter die
               drei Standard-Fotos der GEWÄHLTEN Kategorie, die sich auch von Hand wählen
               lassen; das Skript unten blendet sie je nach Kategorie
               um. Ohne Skript bleiben sie verborgen (hidden) – dann gilt die Automatik. */
            $wahlAlt = (string)($vorbelegt['image_id'] ?? '0'); ?>
      <div class="wl-picks">
        <label class="wl-pick">
          <input type="radio" name="image_id" value="0"<?= $wahlAlt === '' || $wahlAlt === '0' ? ' checked' : '' ?>>
          <span class="wl-pick-std">
            <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none"
                 stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="9" cy="10.2" r="1.6"/>
              <path d="M3.5 17.5 L9.5 12.5 L13.5 16 L16.5 13.5 L20.5 17"/>
            </svg>
            <strong>Standard-Foto</strong>
            <span>passend zur Kategorie, automatisch</span>
          </span>
        </label>
        <?php foreach (wl_standard_bilder() as $sKat => $sListe): foreach ($sListe as $sb): ?>
          <label class="wl-pick wl-pick-skat" data-cat="<?= h($sKat) ?>" hidden>
            <input type="radio" name="image_id" value="std:<?= h((string)$sb['file']) ?>"<?= $wahlAlt === 'std:' . (string)$sb['file'] ? ' checked' : '' ?>>
            <img src="<?= h(wl_standard_url($sb)) ?>" alt="<?= h((string)$sb['credit']) ?>" loading="lazy" width="640" height="400">
          </label>
        <?php endforeach; endforeach; ?>
        <?php foreach ($archiv as $bild): ?>
          <label class="wl-pick">
            <input type="radio" name="image_id" value="<?= (int)$bild['id'] ?>"<?= $wahlAlt === (string)(int)$bild['id'] ? ' checked' : '' ?>>
            <img src="<?= h(wl_img_url((string)$bild['file'])) ?>" alt="<?= h((string)$bild['credit']) ?>" loading="lazy" width="640" height="400">
          </label>
        <?php endforeach; ?>
      </div>

      <?php if (wl_image_engine() !== ''): ?>
        <div class="wl-field">
          <label for="bild">…oder ein eigenes hochladen</label>
          <input type="file" name="bild" id="bild" accept="<?= h(implode(',', array_keys(wl_image_types()))) ?>">
          <p class="wl-hint">Höchstens <?= (int)round(WL_IMG_MAX_BYTES / 1048576) ?> MB. Wir verkleinern es
             automatisch und entfernen dabei versteckte Zusatzdaten wie den Aufnahmeort.</p>
        </div>
        <div class="wl-field">
          <label for="credit">Bildnachweis (optional)</label>
          <input type="text" name="credit" id="credit" maxlength="120" placeholder="Foto: Vorname Nachname" value="<?= $w('credit') ?>">
        </div>
        <label class="wl-check">
          <input type="checkbox" name="rechte" value="1"<?= !empty($vorbelegt['rechte']) ? ' checked' : '' ?>>
          <span><strong>Ich habe die Rechte an diesem Bild</strong> und darf es hier veröffentlichen lassen.
            Bei einem fremden Bild aus dem Netz ist das fast nie der Fall — dann lieber keines nehmen
            oder eines aus dem Archiv.</span>
        </label>
      <?php else: ?>
        <p class="wl-note warn">Bild-Uploads sind auf diesem Server gerade nicht möglich.
           Wähl eines aus dem Archiv oder lass das Bild weg.</p>
      <?php endif; ?>
    </fieldset>

    </div><?php /* Ende wl-nb-haupt */ ?>

    <?php /* LIVE-VORSCHAU: dieselbe Kachel wie auf der Startseite, gefüttert aus den
             Formularfeldern. Gebaut aus den ECHTEN Kachel-Klassen (wl-card…), damit sie nie
             vom Original abweichen kann; nachgeführt per Skript unten (Nonce-CSP). Sitzt seit
             dem Redesign als klebende Spalte NEBEN dem Formular (am Handy darunter). */ ?>
    <aside class="wl-nb-seite" aria-label="Vorschau">
      <div class="wl-field">
        <label>Vorschau</label>
        <?php $orgCss = (string)(wl_org_arten()[(string)($org['art'] ?? 'hsg')]['css'] ?? 'o-hsg'); ?>
        <div class="wl-vorschau">
          <div class="wl-card">
            <div class="wl-card-img" id="pv-imgbox">
              <img id="pv-img" src="" alt="">
              <span class="wl-day" id="pv-day"><i id="pv-wt"></i><b id="pv-tag"></b><em id="pv-mon"></em></span>
              <span class="wl-day wl-day-kurs" id="pv-kursbadge">KURS</span>
              <span class="wl-free" id="pv-free">kostenlos</span>
            </div>
            <div class="wl-card-b">
              <div class="wl-card-cat" id="pv-cat"></div>
              <div class="wl-card-t" id="pv-title">Euer Titel</div>
              <div class="wl-card-m" id="pv-meta"></div>
              <div class="wl-card-by"><span class="wl-pip <?= h($orgCss) ?>"></span><?= h((string)$org['name']) ?></div>
            </div>
          </div>
        </div>
        <p class="wl-hint">Folgt euren Eingaben. Nur beim automatischen Standard-Foto
           entscheidet sich erst beim Veröffentlichen, welches der drei es wird.</p>
      </div>
    </aside>

    <div class="wl-nb-fuss wl-btns">
      <button class="wl-btn p" type="submit"><?= $bearbAkt > 0
          ? ($wiederEin ? 'Neu zur Freigabe einreichen'
             : ($liveStapel ? 'Änderung zur Freigabe schicken' : 'Änderungen speichern'))
          : 'Zur Freigabe abschicken' ?></button>
    </div>
  </form>

  <?php /* Vorschlags-Dialog AUSSERHALB des Formulars – seine Textarea darf beim Abschicken
           des Beitrags nicht mitreisen. Schickt per fetch (Handler oben, was=katwunsch). */ ?>
  <dialog class="wl-dialog" id="wl-kat-dialog">
    <h3>Neue Kategorie vorschlagen</h3>
    <p>Beschreibt kurz, was für eine Kategorie fehlt und was da hinein soll – der AStA schaut
       drüber. Für euren aktuellen Beitrag wählt ihr trotzdem eine der bestehenden.</p>
    <textarea id="wl-kat-text" maxlength="500" rows="4"
      placeholder="z. B. Games &amp; E-Sport – für Turniere, Pen &amp; Paper und LAN-Abende"></textarea>
    <p class="wl-note ok" id="wl-kat-ok" hidden></p>
    <p class="wl-note bad" id="wl-kat-bad" hidden></p>
    <div class="wl-btns">
      <button class="wl-btn" type="button" id="wl-kat-zu">Schließen</button>
      <button class="wl-btn p" type="button" id="wl-kat-los">Vorschlag schicken</button>
    </div>
  </dialog>

  <?php
    // Futter für die Vorschau: Kategorie-Beschriftungen/-Farben und je Kategorie das erste der
    // drei Standard-Fotos (welches wirklich gezogen wird, entscheidet die ID erst beim Anlegen).
    $pvCats = []; $pvStd = [];
    foreach (wl_cats() as $ck => $cd) {
        $pvCats[$ck] = ['label' => (string)$cd['label'], 'css' => (string)$cd['css']];
        $b = wl_standard_bild($ck, 0);
        if ($b) $pvStd[$ck] = wl_standard_url($b);
    }
  ?>
  <script nonce="<?= h((string)($GLOBALS['wl_nonce'] ?? '')) ?>">
  document.addEventListener('DOMContentLoaded', function () {
    var CATS = <?= json_encode($pvCats, JSON_UNESCAPED_UNICODE) ?>;
    var STD  = <?= json_encode($pvStd, JSON_UNESCAPED_SLASHES) ?>;
    var WD_K = ['SO','MO','DI','MI','DO','FR','SA'], MO_K = ['','JAN','FEB','MÄR','APR','MAI','JUN','JUL','AUG','SEP','OKT','NOV','DEZ'];
    var WD_L = ['So','Mo','Di','Mi','Do','Fr','Sa'], MO_L = ['','Jan','Feb','März','Apr','Mai','Juni','Juli','Aug','Sep','Okt','Nov','Dez'];
    var $ = function (id) { return document.getElementById(id); };
    if (!$('pv-imgbox')) return;

    function parse(v) { if (!v) return null; var d = new Date(v.replace(' ', 'T')); return isNaN(d) ? null : d; }
    function hm(d) { return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
    // Spiegel von wl_zeit_kurz()/wl_datum_lang(): gleiche Regeln, gleiche Kürzel.
    function zeitKurz(s, e) {
      var ds = parse(s); if (!ds) return '';
      var de = parse(e);
      if (de && de.toDateString() !== ds.toDateString()) {
        var out = WD_L[ds.getDay()] + ' ' + ds.getDate() + '. ' + MO_L[ds.getMonth() + 1];
        if (hm(ds) !== '00:00') out += ' · ' + hm(ds);
        return out;
      }
      if (hm(ds) === '00:00') return '';
      return hm(ds) + (de ? '–' + hm(de) : '');
    }

    var eigenesBild = '';   // data:-URL eines hochgeladenen Bilds (schlägt die Archiv-Auswahl, wie beim Speichern)
    function wert(name) { var el = document.querySelector('[name="' + name + '"]'); return el ? el.value.trim() : ''; }
    // Typ und Kategorie sind seit dem Redesign RADIO-Gruppen (Kacheln/Pillen) –
    // wert() fände nur den ersten Knopf, nicht den gedrückten.
    function art() { var r = document.querySelector('[name="kind"]:checked'); return r ? r.value : 'event'; }
    function kat() { var r = document.querySelector('[name="cat"]:checked'); return r ? r.value : ''; }

    function malen() {
      var kurs  = art() === 'kurs';
      var cat   = kat();
      var frei  = !!document.querySelector('[name="frei"]:checked');
      var preis = wert('preis');
      var freiMarke = frei && preis === '';

      $('pv-title').textContent = wert('title') || 'Euer Titel';
      var c = CATS[cat] || null;
      $('pv-cat').textContent = c ? c.label : '';
      $('pv-cat').className = 'wl-card-cat' + (c ? ' ' + c.css : '');

      // Datum-Schild bzw. KURS-Marke (Sichtbarkeit über die CSSOM, nicht übers hidden-Attribut)
      var ds = kurs ? null : parse(wert('starts_at'));
      $('pv-day').style.display = (!kurs && ds) ? '' : 'none';
      if (ds) { $('pv-wt').textContent = WD_K[ds.getDay()]; $('pv-tag').textContent = ('0' + ds.getDate()).slice(-2); $('pv-mon').textContent = MO_K[ds.getMonth() + 1]; }
      $('pv-kursbadge').style.display = kurs ? '' : 'none';
      $('pv-free').style.display = freiMarke ? '' : 'none';

      // Bild: eigenes Foto > gewähltes Archivbild > Standard-Foto der Kategorie
      var src = eigenesBild;
      if (!src) {
        var radio = document.querySelector('[name="image_id"]:checked');
        if (radio && radio.value !== '0') {
          var im = radio.closest('.wl-pick').querySelector('img');
          if (im) src = im.getAttribute('src');
        }
      }
      if (!src) src = STD[cat] || '';
      var pv = $('pv-img');
      if (src) { pv.src = src; pv.style.display = ''; } else { pv.style.display = 'none'; }

      var teile = [kurs ? wert('rhythmus') : zeitKurz(wert('starts_at'), wert('ends_at')),
                   wert('ort'), freiMarke ? '' : preis]
        .filter(function (t) { return t !== ''; });
      $('pv-meta').textContent = teile.join(' · ');
    }

    ['title', 'starts_at', 'ends_at', 'rhythmus', 'ort', 'preis'].forEach(function (n) {
      var el = document.querySelector('[name="' + n + '"]');
      if (el) { el.addEventListener('input', malen); el.addEventListener('change', malen); }
    });
    document.querySelectorAll('[name="frei"], [name="image_id"]').forEach(function (el) {
      el.addEventListener('change', malen);
    });
    // Typ-Weiche: Sichtbar sind nur die Felder des gewählten Typs (data-nur="event|kurs").
    // Ohne Skript bleiben beide Blöcke stehen – wie im alten Formular, nichts geht verloren.
    function artWechsel() {
      var a = art();
      document.querySelectorAll('[data-nur]').forEach(function (b) {
        b.hidden = b.getAttribute('data-nur') !== a;
      });
      malen();
    }
    document.querySelectorAll('[name="kind"]').forEach(function (el) {
      el.addEventListener('change', artWechsel);
    });
    artWechsel();
    var datei = document.querySelector('[name="bild"]');
    if (datei) datei.addEventListener('change', function () {
      eigenesBild = '';
      if (datei.files && datei.files[0]) {
        var r = new FileReader();
        r.onload = function () { eigenesBild = String(r.result); malen(); }; // data:-URL – erlaubt die CSP (img-src data:)
        r.readAsDataURL(datei.files[0]);
      } else { malen(); }
    });
    // Standard-Fotos zur Auswahl: sichtbar sind nur die drei der GEWÄHLTEN Kategorie
    // (alle 21 stehen im Markup, hidden). Wechselt die Kategorie, fällt eine dort
    // getroffene Wahl zurück auf die Automatik – ein verstecktes Häkchen wäre eine Falle.
    function stdFiltern() {
      var cat = kat();
      document.querySelectorAll('.wl-pick-skat').forEach(function (el) {
        var zeig = el.getAttribute('data-cat') === cat;
        el.hidden = !zeig;
        var r = el.querySelector('input');
        if (!zeig && r && r.checked) {
          var auto = document.querySelector('[name="image_id"][value="0"]');
          if (auto) auto.checked = true;
        }
      });
      malen();
    }
    document.querySelectorAll('[name="cat"]').forEach(function (el) {
      el.addEventListener('change', stdFiltern);
    });
    stdFiltern();
    // Kategorie-Vorschlag: eigener Dialog, schickt per fetch – die Formular-Eingaben
    // bleiben dabei unangetastet stehen.
    var kDia = document.getElementById('wl-kat-dialog');
    if (kDia) {
      document.getElementById('wl-kat-neu').addEventListener('click', function () { kDia.showModal(); });
      document.getElementById('wl-kat-zu').addEventListener('click', function () { kDia.close(); });
      document.getElementById('wl-kat-los').addEventListener('click', async function () {
        var t = document.getElementById('wl-kat-text');
        var ok = document.getElementById('wl-kat-ok'), bad = document.getElementById('wl-kat-bad');
        ok.hidden = bad.hidden = true;
        var form = new URLSearchParams();
        form.set('was', 'katwunsch');
        form.set('csrf', <?= json_encode(wl_csrf_token()) ?>);
        form.set('text', t.value);
        try {
          var r = await fetch('einreichen.php', { method: 'POST', body: form });
          var j = await r.json();
          (j.ok ? ok : bad).textContent = j.msg;
          (j.ok ? ok : bad).hidden = false;
          if (j.ok) t.value = '';
        } catch (_) {
          bad.textContent = 'Das hat gerade nicht geklappt – bitte später noch einmal.';
          bad.hidden = false;
        }
      });
    }
    // Studi-Rabatt nur zeigen, wenn ein Preis drinsteht – auf „kostenlos" gibt es nichts
    // zu rabattieren. Ohne Skript bleibt das Feld sichtbar, das ist der harmlose Rückfall.
    var preisEl = document.querySelector('[name="preis"]');
    var rabattF = document.getElementById('f-rabatt');
    function rabattZeigen() { rabattF.hidden = preisEl.value.trim() === ''; }
    if (preisEl && rabattF) { preisEl.addEventListener('input', rabattZeigen); rabattZeigen(); }
  });
  </script>
<?php endif; ?>

</div>

<?php wl_foot(); ?>
