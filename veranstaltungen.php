<?php
/**
 * was.läuft – Verwaltung in der App.
 *
 * Hier wird freigegeben, was Hochschulgruppen und Fachschaften eingereicht haben; hier werden
 * Veranstalter angelegt (die Zugangs-Mail mit dem Passwort-Link geht dabei automatisch raus),
 * Banner gepflegt, das Archiv lizenzfreier Bilder gefüllt und die Texte der öffentlichen
 * Seiten geschrieben.
 *
 * Die Seite ist in REITER geteilt – sieben Abschnitte auf einer Rolle wären nicht zu bedienen,
 * und das Texte-Formular allein füllt einen davon. Der Kopf mit den Zahlen bleibt auf jedem
 * Reiter stehen: Er ist die Übersicht, die Reiter sind die Arbeit.
 *
 * Die öffentliche Seite liegt in `veranstaltungen/` und bindet die lib.php NIE ein. Diese Datei
 * ist die Gegenrichtung: Sie gehört zur App, kennt die Rollen und holt sich die Daten über
 * wl-db.php dazu.
 */

require __DIR__ . '/lib.php';
db();
require_once __DIR__ . '/wl-db.php';

if (!wl_can_manage()) { http_response_code(403); exit('Kein Zugriff.'); }

wl_db();
$self = 'veranstaltungen.php';

// Die Reiter. 'beitraege' ist der Standard – Freigeben ist die tägliche Arbeit, alles andere
// wird eingerichtet und dann nur noch selten angefasst.
$tabs = [
    'beitraege'     => ['label' => 'Beiträge',        'icon' => 'ti-inbox'],
    'orgs'          => ['label' => 'Veranstalter',    'icon' => 'ti-users-group'],
    'medien'        => ['label' => 'Bilder & Banner', 'icon' => 'ti-photo'],
    'texte'         => ['label' => 'Texte',           'icon' => 'ti-text-caption'],
    'promo'         => ['label' => 'Promo',           'icon' => 'ti-speakerphone'],
    'meldungen'     => ['label' => 'Meldungen',      'icon' => 'ti-flag'],
    'statistik'     => ['label' => 'Besuche',         'icon' => 'ti-chart-bar'],
    'einstellungen' => ['label' => 'Einstellungen',   'icon' => 'ti-settings'],
];

// Die Promo-Dateien (assets/wl-promo/): EINE Liste für Anzeige und ZIP-Download.
// Das Druck-Layout ist ein einziger Entwurf – die A-Reihe skaliert verlustfrei,
// deshalb sind Flyer, Plakate und Aufsteller dieselbe Zeichnung in vier Größen.
function wl_promo_dateien(): array
{
    // Die statischen Vorlagen liegen im Generator, eine Wort-Rad-Schleife gibt es nicht.
    // Das Website-Banner steht hier mit drin, damit es im Pressekit-ZIP landet – wer den Kit
    // weitergibt, gibt damit auch das Band für die eigene Seite weiter.
    return [
        'insta' => ['label' => 'Animation (Bildschirm aufnehmen)', 'dateien' => [
            'logo-shift.html' => 'Logo-Verwandlung: AStA → was.läuft (Schleife, 7 s)',
        ]],
        'web' => ['label' => 'Für Websites', 'dateien' => [
            'banner-website.html' => 'Banner zum Einsetzen (WordPress/Elementor, wegklickbar)',
        ]],
    ];
}

/**
 * Inhalt einer Promo-Vorlage – mit eingesetzter Marke. Die Vorlagen tragen Platzhalter statt
 * eines eingebetteten Logos: Das Logo gehört dem Träger, nicht dem Programm, und läge sonst
 * als 78-KB-Blob in einer Datei, die mit ausgeliefert wird. Beim Abruf wird es aus
 * data/branding/ eingesetzt – so bleibt die heruntergeladene Datei trotzdem eigenständig.
 */
function wl_promo_inhalt(string $datei): ?string
{
    $erlaubt = [];
    foreach (wl_promo_dateien() as $g) $erlaubt = array_merge($erlaubt, array_keys($g['dateien']));
    if (!in_array($datei, $erlaubt, true)) return null;
    $voll = __DIR__ . '/assets/wl-promo/' . $datei;
    if (!is_file($voll)) return null;
    return strtr((string)file_get_contents($voll), [
        '{{LOGO}}'         => brand_data_uri('logo'),
        '{{LOGO_VERLAUF}}' => brand_data_uri('logo-verlauf'),
        '{{ORT}}'          => wl_ort(),
    ]);
}

// Die Sprüche für den Vorlagen-Generator – Aufhänger, keine Beschreibungen. Der erste ist
// der Standard der ausgelieferten Vorlagen; im Generator lässt sich jeder gegen einen
// eigenen Text tauschen.
function wl_promo_sprueche(): array
{
    return [
        'Keine Ahnung, was du am Wochenende machen sollst?',
        'In ' . wl_ort() . ' ist nie was los? Doch. Hier steht, wo.',
        'Schon wieder erst hinterher davon erfahren?',
        'Party? Kino? Vortrag? Ja.',
        'Was geht heute in ' . wl_ort() . '?',
        'Nie wieder „Das wusste ich nicht".',
        'Dein Semester kann mehr als Mensa und Bib.',
        'Alles, was in ' . wl_ort() . ' läuft. An einem Ort.',
    ];
}

// -----------------------------------------------------------------------------------------------
// Aktionen
// -----------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    // Bescheid-Mails: Die Gruppe erfährt automatisch, was aus ihrer Einreichung wurde –
    // eine Ablehnungs-Begründung nützt niemandem, der sie nie zu sehen bekommt.
    // Der Zusatz in der Meldung sagt ehrlich, ob die Mail rausging (kein Topf,
    // keine Gruppen-Mail) – nie behaupten, was nicht passiert ist.
    $mailZusatz = static fn (bool $ok): string => $ok
        ? ' Die Gruppe bekommt eine Bescheid-Mail.'
        : (wl_setting('bescheid_mails', '1') !== '1'
            ? ' (Keine Bescheid-Mail – in den Einstellungen abgeschaltet.)'
            : ' (Keine Bescheid-Mail – Gruppe ohne Mailadresse oder Mail-Kontingent erschöpft.)');

    if ($action === 'freigeben') {
        $neu = wl_item_freigeben($id);
        flash('Beitrag ist online.' . ($neu ? $mailZusatz(wl_item_bescheid($id, 'live')) : ''), 'success');
        redirect($self . '?t=beitraege');
    }

    if ($action === 'ablehnen') {
        $grund = trim((string)($_POST['grund'] ?? ''));
        if ($grund === '') {
            flash('Bitte eine kurze Begründung angeben – eine Ablehnung ohne Grund ist für die Gruppe nicht nachvollziehbar.', 'error');
            redirect($self . '?t=beitraege');
        }
        wl_item_ablehnen($id, $grund);
        flash('Beitrag abgelehnt.' . $mailZusatz(wl_item_bescheid($id, 'abgelehnt', $grund)), 'success');
        redirect($self . '?t=beitraege');
    }

    if ($action === 'featured') {
        wl_item_featured($id, (string)($_POST['an'] ?? '') === '1');
        redirect($self . '?t=beitraege#live');
    }

    // Wartende Änderung an einem Live-Beitrag (die Gruppen bearbeiten selbst):
    // Übernehmen schreibt die geprüften Felder auf den Beitrag und zieht offene Erinnerungen
    // nach; Verwerfen lässt die bisherige Fassung einfach stehen.
    if ($action === 'edit_apply') {
        flash(wl_item_edit_apply($id)
            ? 'Änderung übernommen – der Beitrag ist aktualisiert.' . $mailZusatz(wl_item_bescheid($id, 'edit_ok'))
            : 'Da lag keine Änderung mehr vor.', 'success');
        redirect($self . '?t=beitraege');
    }
    if ($action === 'edit_verwerfen') {
        // Auch das Verwerfen bekommt Bescheid – sonst verschwände die Änderung für die
        // Gruppe wortlos, und sie wartete vergeblich auf ihren neuen Stand.
        $gabEs = wl_item_edit($id) !== null;
        wl_item_edit_delete($id);
        flash('Änderung verworfen – der Beitrag bleibt, wie er ist.'
            . ($gabEs ? $mailZusatz(wl_item_bescheid($id, 'edit_nein')) : ''), 'success');
        redirect($self . '?t=beitraege');
    }

    // Absage auch aus der Verwaltung (z. B. wenn eine Gruppe anruft): gleiche Mechanik wie im
    // Veranstalter-Dashboard – Banderole statt Löschen, Absage-Mitteilung an die Erinnerten.
    if ($action === 'item_absage') {
        $an = (string)($_POST['an'] ?? '') === '1';
        wl_item_absage($id, $an);
        flash($an ? 'Als abgesagt markiert – die Erinnerten bekommen beim nächsten Cron-Lauf eine Mitteilung.'
                  : 'Absage zurückgenommen.', 'success');
        redirect($self . '?t=beitraege#live');
    }

    if ($action === 'item_delete') {
        wl_item_delete($id);
        flash('Beitrag gelöscht.', 'success');
        redirect($self . '?t=beitraege#live');
    }

    // ---- Veranstalter -------------------------------------------------------------------------
    if ($action === 'org_save') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { flash('Bitte einen Namen angeben.', 'error'); redirect($self . '?t=orgs'); }
        // Die Mailadresse IST der Anmeldename – ohne sie gibt es keinen Zugang, doppelt geht nicht.
        $mail = trim((string)($_POST['mail'] ?? ''));
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            flash('Bitte eine gültige Mailadresse angeben – damit meldet sich die Gruppe an.', 'error');
            redirect($self . '?t=orgs');
        }
        if (!wl_org_mail_frei($mail, $id)) {
            flash('Diese Mailadresse gehört schon einem anderen Veranstalter.', 'error');
            redirect($self . '?t=orgs');
        }
        $art  = (string)($_POST['art'] ?? 'hsg');
        if (!isset(wl_org_arten()[$art])) $art = 'hsg';
        $kurz = mb_substr(trim((string)($_POST['kurz'] ?? '')), 0, 2);
        $auto = !empty($_POST['auto_ok']) ? 1 : 0;
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300);

        if ($id > 0) {
            wl_db()->prepare('UPDATE orgs SET name=?, kurz=?, art=?, mail=?, auto_ok=?, note=? WHERE id=?')
                ->execute([$name, $kurz, $art, $mail, $auto, $note, $id]);
            flash('Veranstalter gespeichert.', 'success');
        } else {
            wl_db()->prepare('INSERT INTO orgs(name, kurz, art, mail, token, auto_ok, note) VALUES(?,?,?,?,?,?,?)')
                ->execute([$name, $kurz, $art, $mail, wl_token_new(), $auto, $note]);
            $neuId = (int)wl_db()->lastInsertId();
            // Kam der Veranstalter aus einer Zugangsanfrage, ist die damit erledigt – und
            // erledigt heißt gelöscht: fremde Kontaktdaten, aufzuheben gibt es nichts mehr.
            $anfrageId = (int)($_POST['anfrage_id'] ?? 0);
            if ($anfrageId > 0) wl_org_request_delete($anfrageId);
            // Mailbasiert: Die Gruppe legt ihr Passwort selbst fest – die Einladung mit dem
            // Link geht direkt raus. Klappt das nicht (Basis-Adresse fehlt, Topf leer),
            // sagt die Meldung, was zu tun ist – der Knopf „Zugang-Mail" holt es nach.
            if (wl_org_zugangsmail($neuId)) {
                flash('Veranstalter angelegt – die Zugangs-Mail an ' . $mail . ' ist unterwegs. '
                    . 'Darüber legt die Gruppe ihr Passwort selbst fest.', 'success');
            } else {
                flash('Veranstalter angelegt, aber die Zugangs-Mail ging nicht raus '
                    . '(Basis-Adresse fehlt oder das Mail-Kontingent ist erschöpft). '
                    . 'Später „Zugang-Mail" in der Liste drücken.', 'error');
            }
        }
        redirect($self . '?t=orgs');
    }

    if ($action === 'org_active') {
        wl_db()->prepare('UPDATE orgs SET active = 1 - active WHERE id = ?')->execute([$id]);
        redirect($self . '?t=orgs');
    }

    // Anfrage ablehnen/abräumen ohne Anlegen – löschen statt markieren (fremde Kontaktdaten).
    if ($action === 'request_del') {
        wl_org_request_delete($id);
        flash('Anfrage entfernt.', 'success');
        redirect($self . '?t=orgs');
    }
    if ($action === 'katwunsch_del') {
        wl_cat_wunsch_delete($id);
        flash('Kategorie-Vorschlag entfernt.', 'success');
        redirect($self . '?t=orgs');
    }

    if ($action === 'org_del') {
        $oWeg = wl_org($id);
        wl_org_delete($id);
        flash(($oWeg ? '„' . (string)$oWeg['name'] . '" ist' : 'Veranstalter') . ' endgültig gelöscht – samt Beiträgen, Bildern und Abos.', 'success');
        redirect($self . '?t=orgs');
    }
    if ($action === 'org_zugangsmail') {
        // Passwort-Link an die Gruppe – für die erste Einrichtung wie für „Passwort weg".
        // Das alte Passwort bleibt gültig, bis über den Link ein neues gesetzt wird.
        if (wl_org_zugangsmail($id)) flash('Zugangs-Mail ist unterwegs.', 'success');
        else flash('Die Zugangs-Mail ging nicht raus – fehlt die Basis-Adresse oder die '
            . 'Mailadresse des Veranstalters, oder das Mail-Kontingent ist erschöpft.', 'error');
        redirect($self . '?t=orgs');
    }

    // ---- Bildarchiv ---------------------------------------------------------------------------
    if ($action === 'stock_add') {
        if (empty($_FILES['bild'])) { flash('Keine Datei gewählt.', 'error'); redirect($self . '?t=medien'); }
        $r = wl_image_store($_FILES['bild'], [
            'stock'     => 1,
            'rights_ok' => 1,          // Archivbilder stellt der AStA ein, die Rechte sind geklärt
            'credit'    => (string)($_POST['credit'] ?? ''),
        ]);
        flash($r['ok'] ? 'Bild ins Archiv aufgenommen.' : $r['msg'], $r['ok'] ? 'success' : 'error');
        redirect($self . '?t=medien');
    }

    if ($action === 'stock_del') {
        $img = wl_image($id);
        if ($img) {
            // Als Archivbild schützt es sich selbst gegen das Aufräumen – hier ist das Löschen gewollt.
            wl_db()->prepare('UPDATE images SET stock = 0 WHERE id = ?')->execute([$id]);
            if (!wl_image_delete_if_unused($id)) {
                wl_db()->prepare('UPDATE images SET stock = 1 WHERE id = ?')->execute([$id]);
                flash('Das Bild wird noch verwendet – erst die Beiträge ändern.', 'error');
            } else {
                flash('Bild gelöscht.', 'success');
            }
        }
        redirect($self . '?t=medien');
    }

    // ---- Banner -------------------------------------------------------------------------------
    if ($action === 'banner_save') {
        $titel = trim((string)($_POST['title'] ?? ''));
        if ($titel === '') { flash('Bitte einen Titel angeben.', 'error'); redirect($self . '?t=medien#banner'); }
        // Die Bildwahl ist EIN Radio-Kreis (wie beim Einreich-Formular): '0' = kein Bild,
        // 'std:<datei>' = mitgeliefertes Banner-Motiv, sonst die id eines Archiv-Bilds.
        $bildWahl = trim((string)($_POST['bild'] ?? '0'));
        $bildId = null;
        $stdBanner = '';
        if (str_starts_with($bildWahl, 'std:')) {
            $stdBanner = wl_banner_std_by_file(substr($bildWahl, 4)) ? substr($bildWahl, 4) : '';
        } else {
            $bildId = (int)$bildWahl ?: null;
        }
        $url = wl_url_norm((string)($_POST['url'] ?? ''));   // „foo.de/x" genügt
        $datum = static function (string $v): string {
            $v = trim($v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        };
        $f = [$titel, mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 160), $bildId, $stdBanner, $url,
              $datum((string)($_POST['von'] ?? '')), $datum((string)($_POST['bis'] ?? '')),
              !empty($_POST['active']) ? 1 : 0, (int)($_POST['sort'] ?? 0)];
        if ($id > 0) {
            wl_db()->prepare('UPDATE banners SET title=?, subtitle=?, image_id=?, std_bild=?, url=?, von=?, bis=?, active=?, sort=? WHERE id=?')
                ->execute(array_merge($f, [$id]));
            flash('Banner gespeichert.', 'success');
        } else {
            wl_db()->prepare('INSERT INTO banners(title, subtitle, image_id, std_bild, url, von, bis, active, sort) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute($f);
            flash('Banner angelegt.', 'success');
        }
        redirect($self . '?t=medien#banner');
    }

    if ($action === 'banner_del') {
        wl_db()->prepare('DELETE FROM banners WHERE id = ?')->execute([$id]);
        flash('Banner gelöscht.', 'success');
        redirect($self . '?t=medien#banner');
    }

    // ---- Texte --------------------------------------------------------------------------------
    // Speichern und Zurücksetzen laufen über das Register (wl_text_fields()) – neue Textfelder
    // brauchen hier also nie eine Anpassung. Dasselbe Muster wie beim Pat:innenprogramm.
    if ($action === 'save_texts') {
        foreach (array_keys(wl_text_fields()) as $k) {
            if (!array_key_exists('t_' . $k, $_POST)) continue; // fehlt im Formular → nicht anfassen
            wl_setting_set('text_' . $k, trim((string)$_POST['t_' . $k]));
        }
        flash('Texte gespeichert – auf der öffentlichen Seite sofort sichtbar.', 'success');
        redirect($self . '?t=texte');
    }

    if ($action === 'reset_texts') {
        // Zeilen löschen (nicht auf "" setzen): ein gespeichertes Leerfeld bedeutet
        // „Element weglassen", eine fehlende Zeile dagegen „Standard verwenden".
        foreach (array_keys(wl_text_fields()) as $k) {
            wl_setting_delete('text_' . $k);
        }
        flash('Texte auf den Standard zurückgesetzt.', 'success');
        redirect($self . '?t=texte');
    }

    // ---- Einstellungen ------------------------------------------------------------------------
    if ($action === 'banner_web') {
        wl_setting_set('banner_web', empty($_POST['banner_web']) ? '0' : '1');
        flash(empty($_POST['banner_web'])
            ? 'Das Banner ist jetzt aus – die Website blendet es beim nächsten Aufruf aus.'
            : 'Das Banner ist an. Es erscheint auf der Website in bis zu fünf Minuten (Zwischenspeicher).', 'success');
        redirect($self . '?t=promo');
    }

    if ($action === 'settings') {
        $mode = (string)($_POST['mode'] ?? 'on');
        wl_setting_set('mode', in_array($mode, ['on', 'soft', 'off', 'pre'], true) ? $mode : 'on');
        // Starttermin: leer erlaubt (dann steht die Seite ohne Datum da). Ein Datum in falscher
        // Form wird verworfen statt gespeichert – sonst stünde draußen „Start: 1. Jan 1970".
        $launch = trim((string)($_POST['launch_at'] ?? ''));
        wl_setting_set('launch_at', preg_match('~^\d{4}-\d{2}-\d{2}$~', $launch) ? $launch : '');
        $lzeit = trim((string)($_POST['launch_time'] ?? ''));
        wl_setting_set('launch_time', preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $lzeit) ? $lzeit : '');
        wl_setting_set('demo', empty($_POST['demo']) ? '0' : '1');
        wl_setting_set('demo_text', trim((string)($_POST['demo_text'] ?? '')));
        wl_setting_set('kontakt_mail', trim((string)($_POST['kontakt_mail'] ?? '')));
        // Adressen: „beispiel.de/planer" reicht, wl_url_norm() setzt das Schema davor.
        foreach (['base_url', 'imprint_url', 'privacy_url'] as $k) {
            wl_setting_set($k, wl_url_norm((string)($_POST[$k] ?? '')));
        }
        wl_setting_set('keep_days', (string)max(7, min(365, (int)($_POST['keep_days'] ?? WL_KEEP_DAYS))));
        // Mails an die Veranstalter: dasselbe Muster wie bei Umfragen, externen
        // Events und Terminplaner – Absender-Adresse, Absender-Name, Tagesdeckel, Schalter.
        // Besonderheit hier: Die Absender-Adresse DARF die eigene was.läuft-Adresse sein.
        wl_setting_set('bescheid_mails', empty($_POST['bescheid_mails']) ? '0' : '1');
        wl_setting_set('en_felder', empty($_POST['en_felder']) ? '0' : '1');
        $fromMail = trim((string)($_POST['from_email'] ?? ''));
        wl_setting_set('from_email', filter_var($fromMail, FILTER_VALIDATE_EMAIL) ? $fromMail : '');
        wl_setting_set('from_name', trim((string)($_POST['from_name'] ?? '')));
        wl_setting_set('cap_day', (string)max(10, min(3000, (int)($_POST['cap_day'] ?? 300))));
        zust_form_speichern('waslaeuft', $_POST);
        flash('Einstellungen gespeichert.', 'success');
        redirect($self . '?t=einstellungen');
    }

    if ($action === 'test_mail') {
        $to = current_member() ? member_mail(current_member()) : '';
        if ($to === '') {
            flash('Dafür braucht es ein Konto mit E-Mail-Adresse.', 'error');
            redirect($self . '?t=einstellungen');
        }
        // Bewusst am Bescheid-Schalter vorbei: Der Test beantwortet, ob der Versand technisch
        // funktioniert – auch und gerade dann, wenn die Bescheide gerade aus sind.
        $ok = wl_mail($to, 'Testmail: was.läuft',
            "Wenn du das liest, kann was.läuft Mails verschicken.\n\n(ausgelöst über Verwaltung → was.läuft → Einstellungen)");
        flash($ok ? 'Testmail an ' . $to . ' übergeben.'
                  : 'Versand fehlgeschlagen – gibt es eine gültige Absender-Adresse, und ist noch Kontingent im Topf?',
              $ok ? 'success' : 'error');
        redirect($self . '?t=einstellungen');
    }

    if ($action === 'settings_from_app') {
        wl_setting_set('base_url', rtrim(base_url(), '/'));
        if (trim(wl_setting('kontakt_mail', '')) === '') wl_setting_set('kontakt_mail', mail_from());
        // Wie bei den Umfragen: Absender aus der App vorbelegen, wenn noch nichts dasteht.
        if (trim(wl_setting('from_email', '')) === '') wl_setting_set('from_email', mail_from());
        if (trim(wl_setting('from_name', '')) === '') wl_setting_set('from_name', 'was.läuft');
        flash('Basis-Adresse und Absender aus den App-Einstellungen übernommen.', 'success');
        redirect($self . '?t=einstellungen');
    }

    // Gemeldete Beiträge: erledigt haken (mit Notiz, was ihr gemacht habt) oder wieder öffnen.
    if ($action === 'melde_done' || $action === 'melde_auf') {
        wl_report_done($id, $action === 'melde_done', (string)($_POST['notiz'] ?? ''));
        flash($action === 'melde_done' ? 'Meldung als erledigt abgehakt.' : 'Meldung wieder geöffnet.', 'success');
        redirect($self . '?t=meldungen');
    }
}

// -----------------------------------------------------------------------------------------------
// Daten fürs Anzeigen
// -----------------------------------------------------------------------------------------------
$wartend = wl_pending();
$aenderungen = wl_item_edits_offen();
$orgs    = wl_orgs_all(true);
$stock   = wl_stock_images();
$banner  = wl_banners_all();
$anfragen = wl_org_requests();
$meldungenOffen = wl_reports_offen();
$editOrg = null;
if (($_GET['org'] ?? '') !== '') $editOrg = wl_org((int)$_GET['org']);
// „Übernehmen" an einer Zugangsanfrage: füllt das Anlege-Formular mit Name und Mail vor;
// beim Speichern räumt org_save die Anfrage über die mitgegebene anfrage_id ab.
$vorlage = null;
if (($_GET['anfrage'] ?? '') !== '' && !$editOrg) $vorlage = wl_org_request((int)$_GET['anfrage']);
$editBan = null;
if (($_GET['banner'] ?? '') !== '') {
    $s = wl_db()->prepare('SELECT * FROM banners WHERE id = ?');
    $s->execute([(int)$_GET['banner']]);
    $editBan = $s->fetch() ?: null;
}

// Welcher Reiter? Bearbeiten-Links (?org=…, ?banner=…) ziehen auf ihren Reiter, damit ein
// gemerkter oder geteilter Link immer dort landet, wo sein Formular steht.
$tab = (string)($_GET['t'] ?? '');
if ($editOrg || $vorlage) $tab = 'orgs';
if ($editBan)             $tab = 'medien';
if (!isset($tabs[$tab]))  $tab = 'beitraege';

// Einzelne Promo-Vorlage ansehen oder herunterladen – immer über PHP, nie als statische
// Datei: Erst hier wird die Marke eingesetzt.
if (isset($_GET['promo'])) {
    $pDatei = (string)$_GET['promo'];
    $pInhalt = wl_promo_inhalt($pDatei);
    if ($pInhalt === null) { http_response_code(404); exit('Nicht gefunden'); }
    header('Content-Type: text/html; charset=utf-8');
    if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="' . basename($pDatei) . '"');
    echo $pInhalt;
    exit;
}

// Das ganze Pressekit als EIN Download: alle Promo-Vorlagen plus die Marken-Dateien.
if ($tab === 'promo' && isset($_GET['zip'])) {
    if (!class_exists('ZipArchive')) {
        flash('Auf diesem Server fehlt die ZIP-Erweiterung – bitte die Dateien einzeln herunterladen.');
        redirect($self . '?t=promo');
    }
    $zipPfad = tempnam(sys_get_temp_dir(), 'wlpromo');
    $zip = new ZipArchive();
    $zip->open($zipPfad, ZipArchive::OVERWRITE);
    foreach (wl_promo_dateien() as $gruppe) {
        foreach ($gruppe['dateien'] as $datei => $egal) {
            $inhalt = wl_promo_inhalt($datei);
            if ($inhalt !== null) $zip->addFromString('waslaeuft-pressekit/' . $datei, $inhalt);
        }
    }
    // Marken-Dateien: was.läuft gehört zum Angebot und liegt in assets/, das Träger-Logo
    // dagegen in data/branding/ – brand_pfad() kennt beide Wege.
    foreach (['wl-icon.svg', 'wl-icon-180.png', 'wl-og.png'] as $m) {
        $voll = __DIR__ . '/assets/' . $m;
        if (is_file($voll)) $zip->addFile($voll, 'waslaeuft-pressekit/marke/' . $m);
    }
    foreach (['logo', 'logo-verlauf'] as $m) {
        $voll = brand_pfad($m);
        if ($voll !== null) $zip->addFile($voll, 'waslaeuft-pressekit/marke/' . $m . '.png');
    }
    $zip->addFromString('waslaeuft-pressekit/farben-und-regeln.txt',
        "was.läuft – Marke in Kürze\n"
        . "==========================\n\n"
        . 'Wortmarke: „was" in Weiß · Punkt in Limette · „läuft" im Verlauf Gelb → Koralle.' . "\n"
        . "Die Marke steht bevorzugt auf dunklem Grund (Tinte).\n\n"
        . "Farben:\n"
        . "  Tinte (Grund)   #17141c\n"
        . "  Koralle         #ff5c72\n"
        . "  Gelb            #ffd93d\n"
        . "  Limette         #d9f24b\n"
        . "  Verlauf Marke   92°, #ffd93d → #ff5c72 (Stopp bei 75 %)\n\n"
        . "Schrift: kräftige serifenlose Systemschrift (z. B. Inter/Segoe/Helvetica, fett).\n"
        . "Adresse: " . rtrim(wl_base_url(), '/') . "/veranstaltungen/\n"
        . "Der QR-Code in den Vorlagen führt genau dorthin.\n");
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="waslaeuft-pressekit.zip"');
    header('Content-Length: ' . filesize($zipPfad));
    readfile($zipPfad);
    unlink($zipPfad);
    exit;
}

$live = wl_db()->query("SELECT i.*, o.name AS org_name, im.file AS bild
    FROM items i LEFT JOIN orgs o ON o.id = i.org_id LEFT JOIN images im ON im.id = i.image_id
    WHERE i.status = 'live' ORDER BY i.featured DESC, i.starts_at, i.title LIMIT 200")->fetchAll();

$basis = rtrim(wl_setting('base_url', ''), '/');
$portal = $basis !== '' ? $basis . '/veranstaltungen/' : '';

page_header('was.läuft', true);
?>
<p class="small"><a href="admin/index.php">‹ Verwaltung</a></p>

<div class="events-toolbar">
  <h1><i class="ti ti-calendar-star" style="color:var(--petrol)"></i> <?= wl_marke_app() ?></h1>
  <?php if ($portal !== ''): ?>
    <a class="btn secondary" href="<?= h($portal) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> Seite ansehen</a>
  <?php endif; ?>
</div>

<p class="small muted">Das öffentliche Veranstaltungsportal für Studierende. Hochschulgruppen und
  Fachschaften tragen selbst ein, ihr gebt frei. <strong>Alles, was ihr hier freigebt, steht sofort
  öffentlich im Netz.</strong></p>

<?php if (wl_mode() !== 'on' || wl_demo()): ?>
  <p class="note"><i class="ti ti-alert-triangle"></i>
    <?php if (wl_mode() !== 'on'): ?>
      Betriebszustand: <strong><?= match (wl_mode()) {
          'off' => 'Geschlossen – die Seite ist für alle zu',
          'pre' => 'Vorabstart – draußen steht „bald geht es los", Gruppen können schon eintragen'
                   . (wl_launch_at() !== '' && wl_launch_at() <= date('Y-m-d')
                      ? '. Der Starttermin ist da – Zeit, auf „Läuft" umzustellen' : ''),
          default => 'Aufnahmestopp – niemand kann Neues einreichen',
      } ?></strong>.
    <?php endif; ?>
    <?php if (wl_demo()): ?>
      <strong>Demo-Modus</strong> ist an – Besuchende lesen auf der Startseite und über jeder
      Veranstaltung, dass die Seite noch nicht öffentlich ist.
    <?php endif; ?>
    Umstellen bei <a href="<?= h($self) ?>?t=einstellungen">Einstellungen</a>.</p>
<?php endif; ?>

<?php /* Die Zahlen sind der Überblick und bleiben deshalb auf JEDEM Reiter stehen – als Links:
         Wer „3 warten" liest, will dorthin, nicht erst den passenden Reiter suchen. */ ?>
<div class="wl-adm-stats">
  <a href="<?= h($self) ?>?t=beitraege"><strong><?= count($wartend) ?></strong><span>warten</span></a>
  <a href="<?= h($self) ?>?t=beitraege#live"><strong><?= wl_count('live') ?></strong><span>öffentlich</span></a>
  <div><strong><?= wl_count('rejected') ?></strong><span>abgelehnt</span></div>
  <a href="<?= h($self) ?>?t=orgs"><strong><?= count(array_filter($orgs, static fn ($o) => (int)$o['active'] === 1)) ?></strong><span>Veranstalter</span></a>
</div>

<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($tabs as $tk => $td): ?>
    <a<?= $tk === $tab ? ' class="on" aria-current="page"' : '' ?> href="<?= h($self) ?>?t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?>
      <?php if ($tk === 'beitraege' && ($wartend || $aenderungen)): ?><span class="count"><?= count($wartend) + count($aenderungen) ?></span><?php endif; ?>
      <?php if ($tk === 'orgs' && $anfragen): ?><span class="count"><?= count($anfragen) ?></span><?php endif; ?>
      <?php if ($tk === 'meldungen' && $meldungenOffen): ?><span class="count"><?= $meldungenOffen ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'beitraege'): ?>

<!-- ================= Freigabe ================= -->
<div class="section-title" id="freigabe"><i class="ti ti-inbox"></i> Freigabe
  <span class="count"><?= count($wartend) ?></span></div>

<?php if (!$wartend): ?>
  <div class="card"><p class="muted small" style="margin:0">Nichts zu tun – alles freigegeben.</p></div>
<?php else: ?>
  <?php foreach ($wartend as $w): $kurs = ($w['kind'] ?? 'event') === 'kurs'; ?>
    <div class="card wl-adm-sub">
      <div class="wl-adm-row">
        <?php if (trim((string)($w['bild'] ?? '')) !== ''): ?>
          <img class="wl-adm-img" src="veranstaltungen/bild.php?f=<?= h((string)$w['bild']) ?>" alt="" width="120" height="80">
        <?php endif; ?>
        <div class="wl-adm-b">
          <strong><?= h((string)$w['title']) ?></strong>
          <div class="small muted">
            <?= h((string)($w['org_name'] ?? 'ohne Veranstalter')) ?> ·
            <?= h($kurs ? (string)$w['rhythmus'] : (string)$w['starts_at']) ?>
            <?php if (trim((string)$w['ort']) !== ''): ?> · <?= h((string)$w['ort']) ?><?php endif; ?>
            · eingereicht <?= h((string)$w['submitted_at']) ?>
            <?php /* Zugangsbeschränkung gehört in die Freigabe-Ansicht: Wer freigibt, soll sie
                     sehen, ohne erst die Vorschau zu öffnen. */
                  if ((int)($w['nur_studis'] ?? 0) === 1): ?>
              <span class="badge badge-draft">nur für Studierende</span>
            <?php endif; ?>
          </div>
          <?php if (trim((string)$w['teaser']) !== ''): ?>
            <div class="small" style="margin-top:.3rem"><?= h((string)$w['teaser']) ?></div>
          <?php endif; ?>
          <?php if (trim((string)($w['bild'] ?? '')) !== '' && (int)($w['rights_ok'] ?? 0) !== 1): ?>
            <p class="note small wl-adm-warn" style="margin:.45rem 0 0"><i class="ti ti-alert-triangle"></i>
              <strong>Bildrechte nicht bestätigt.</strong> Bitte vor der Freigabe klären – bei einem
              fremden Foto haftet der AStA als Verbreiter, nicht die einreichende Gruppe.</p>
          <?php endif; ?>
        </div>
      </div>
      <div class="btn-row" style="margin-top:.7rem">
        <form method="post"><?= csrf_field() ?>
          <input type="hidden" name="action" value="freigeben"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
          <button class="btn" type="submit"><i class="ti ti-check"></i> Freigeben</button>
        </form>
        <?php /* Unveröffentlichtes zeigt v.php nur mit dem Beitrags-Token (?schau=…) –
                 ohne ihn lief die Vorschau in die „gibt es nicht"-Seite. */ ?>
        <a class="btn secondary" href="veranstaltungen/v.php?id=<?= (int)$w['id'] ?>&amp;schau=<?= h((string)($w['edit_token'] ?? '')) ?>" target="_blank" rel="noopener">Vorschau</a>
        <?php /* Feld + Knopf als EINE Einheit rechts – bricht als Ganzes um, nichts verrutscht. */ ?>
        <form method="post" class="wl-adm-reject"><?= csrf_field() ?>
          <input type="hidden" name="action" value="ablehnen"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
          <input type="text" name="grund" placeholder="Begründung für die Gruppe" required>
          <button class="btn danger" type="submit">Ablehnen</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($aenderungen): ?>
<!-- ================= Wartende Änderungen ================= -->
<?php /* Bearbeitet eine Gruppe einen LIVE-Beitrag, bleibt die alte Fassung online und die
         Änderung landet hier – als DIFF: Nur was sich unterscheidet, steht da, alt → neu.
         Übernehmen schreibt die (schon beim Einreichen geprüften) Felder auf den Beitrag. */ ?>
<div class="section-title" id="aenderungen"><i class="ti ti-pencil"></i> Änderungen an Live-Beiträgen
  <span class="count"><?= count($aenderungen) ?></span></div>
<?php
$aeFelder = ['kind' => 'Typ', 'title' => 'Titel', 'cat' => 'Kategorie', 'teaser' => 'Kachel-Zeile',
             'text' => 'Beschreibung', 'starts_at' => 'Beginn', 'ends_at' => 'Ende',
             'rhythmus' => 'Rhythmus', 'termine' => 'Termine', 'einstieg' => 'Einstieg jederzeit',
             'von_datum' => 'Kurs ab', 'bis_datum' => 'Kurs bis', 'ort' => 'Ort',
             'adresse' => 'Adresse', 'preis' => 'Preis', 'studi_rabatt' => 'Studi-Rabatt',
             'frei' => 'Eintritt frei', 'barrierefrei' => 'Barrierefrei',
             'nur_studis' => 'Nur für Studierende', 'ab_alter' => 'Mindestalter',
             'url' => 'Link', 'anmeldung_url' => 'Anmeldung'];
$aeWert = static function (string $k, $v): string {
    if ($k === 'kind') return (string)$v === 'kurs' ? 'Kurs' : 'Veranstaltung';
    if (in_array($k, ['frei', 'barrierefrei', 'einstieg', 'nur_studis'], true)) return (int)$v === 1 ? 'ja' : 'nein';
    if ($k === 'cat') return wl_cat_label((string)$v);
    if (in_array($k, ['termine', 'ab_alter'], true)) return (int)$v > 0 ? (string)(int)$v : '—';
    $v = trim((string)$v);
    if ($v === '') return '—';
    return mb_strlen($v) > 60 ? mb_substr($v, 0, 57) . '…' : $v;
};
foreach ($aenderungen as $ae): $aeIt = $ae['item'];
    $diff = [];
    foreach ($aeFelder as $fk => $fl) {
        $alt = $aeWert($fk, $aeIt[$fk] ?? '');
        $neu = $aeWert($fk, $ae['felder'][$fk] ?? '');
        if ($alt !== $neu) $diff[] = ['l' => $fl, 'a' => $alt, 'n' => $neu];
    }
    // Die Bildwahl in EINER Zeile statt nackter IDs – welches Bild es ist, zeigt die Vorschau.
    $bildAlt = (int)($aeIt['image_id'] ?? 0) . '|' . trim((string)($aeIt['std_bild'] ?? ''));
    $bildNeu = (int)($ae['felder']['image_id'] ?? 0) . '|' . trim((string)($ae['felder']['std_bild'] ?? ''));
    if ($bildAlt !== $bildNeu) $diff[] = ['l' => 'Bild', 'a' => 'bisheriges', 'n' => 'neue Wahl'];
?>
  <div class="card wl-adm-sub">
    <div class="wl-adm-b">
      <strong><?= h((string)$aeIt['title']) ?></strong>
      <div class="small muted">
        <?= h($ae['org_name'] !== '' ? $ae['org_name'] : 'ohne Veranstalter') ?>
        · Änderung eingereicht <?= h($ae['eingereicht']) ?>
      </div>
      <?php if ($diff): ?>
        <ul class="small" style="margin:.45rem 0 0; padding-left:1.1rem">
          <?php foreach ($diff as $d): ?>
            <li><strong><?= h($d['l']) ?>:</strong> <?= h($d['a']) ?> <span class="muted">→</span> <strong><?= h($d['n']) ?></strong></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="small muted" style="margin:.45rem 0 0">Keine inhaltliche Abweichung mehr –
          Übernehmen oder Verwerfen läuft auf dasselbe hinaus.</p>
      <?php endif; ?>
    </div>
    <div class="btn-row" style="margin-top:.7rem">
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_apply"><input type="hidden" name="id" value="<?= (int)$aeIt['id'] ?>">
        <button class="btn" type="submit"><i class="ti ti-check"></i> Änderung übernehmen</button>
      </form>
      <a class="btn secondary" href="veranstaltungen/v.php?id=<?= (int)$aeIt['id'] ?>" target="_blank" rel="noopener">Bisherige Fassung</a>
      <form method="post" data-confirm="Die Änderung verwerfen? Der Beitrag bleibt, wie er ist – die Gruppe erfährt davon nichts Automatisches." data-confirm-ok="Verwerfen"><?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_verwerfen"><input type="hidden" name="id" value="<?= (int)$aeIt['id'] ?>">
        <button class="btn danger" type="submit">Verwerfen</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ================= Öffentliche Beiträge ================= -->
<div class="section-title" id="live"><i class="ti ti-world"></i> Öffentlich
  <span class="count"><?= count($live) ?></span></div>
<div class="card">
  <p class="small muted" style="margin-top:0"><strong>Empfohlen</strong> hebt einen Beitrag in der
    Terminliste mit einem Stern-Chip hervor – die Reihenfolge bleibt chronologisch.
    Empfohlene Kurse ziehen im Kurs-Streifen nach vorn.
    Sparsam einsetzen – sind alle empfohlen, ist es keiner.</p>
  <?php if (!$live): ?>
    <p class="muted small" style="margin:0">Noch nichts öffentlich.</p>
  <?php else: ?>
    <?php foreach ($live as $l): $kurs = ($l['kind'] ?? 'event') === 'kurs'; ?>
      <div class="wl-adm-ent">
        <div class="wl-adm-ent-b">
          <a href="veranstaltungen/v.php?id=<?= (int)$l['id'] ?>" target="_blank" rel="noopener"><strong><?= h((string)$l['title']) ?></strong></a>
          <?php if ((int)$l['featured'] === 1): ?> <span class="pill"><i class="ti ti-star-filled"></i> empfohlen</span><?php endif; ?>
          <?php if ((int)($l['abgesagt'] ?? 0) === 1): ?> <span class="pill pill-bad"><i class="ti ti-calendar-x"></i> abgesagt</span><?php endif; ?>
          <div class="small muted">
            <?= h($kurs ? 'Kurs · ' . (string)$l['rhythmus'] : (string)$l['starts_at']) ?>
            · <?= h((string)($l['org_name'] ?? '')) ?>
          </div>
        </div>
        <div class="wl-adm-ent-a">
          <?php /* Direkt zum fertigen Werbematerial des Beitrags (Insta-Bilder, QR, Text). */ ?>
          <a class="btn secondary small" href="veranstaltungen/teilen.php?e=<?= (int)$l['id'] ?>" target="_blank" rel="noopener"><i class="ti ti-share-3"></i> Teilen</a>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="featured"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <input type="hidden" name="an" value="<?= (int)$l['featured'] === 1 ? '0' : '1' ?>">
            <button class="btn secondary small" type="submit"><?= (int)$l['featured'] === 1 ? 'Nicht mehr empfehlen' : 'Empfehlen' ?></button>
          </form>
          <?php /* Absagen statt Löschen, auch von hier (wenn die Gruppe z. B. anruft):
                   Banderole bleibt, die Erinnerten bekommen eine Mitteilung. Nur Events –
                   ein Kurs hat keinen Termin, den man absagen könnte. */ ?>
          <?php if (!$kurs): ?>
            <?php if ((int)($l['abgesagt'] ?? 0) !== 1): ?>
              <form method="post" data-confirm="Diesen Beitrag als abgesagt markieren? Er bleibt mit Absage-Hinweis sichtbar; alle mit „Erinnere mich" bekommen eine Mitteilung." data-confirm-ok="Absagen"><?= csrf_field() ?>
                <input type="hidden" name="action" value="item_absage"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <input type="hidden" name="an" value="1">
                <button class="btn secondary small" type="submit">Absagen</button>
              </form>
            <?php else: ?>
              <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="action" value="item_absage"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <input type="hidden" name="an" value="0">
                <button class="btn secondary small" type="submit">Absage zurücknehmen</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" data-confirm="Diesen Beitrag löschen? Er verschwindet sofort von der öffentlichen Seite." data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?>
            <input type="hidden" name="action" value="item_delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn danger small" type="submit">Löschen</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php if ($tab === 'orgs'): ?>

<!-- ================= Veranstalter ================= -->
<div class="section-title" id="orgs"><i class="ti ti-users-group"></i> Veranstalter
  <span class="count"><?= count($orgs) ?></span></div>

<?php if ($anfragen): ?>
  <?php /* Zugangsanfragen vom öffentlichen „Eintragen"-Knopf. „Übernehmen" füllt das
           Anlege-Formular unten vor; beim Speichern verschwindet die Anfrage von selbst –
           und die Zugangs-Mail mit dem Passwort-Link geht automatisch an die Gruppe raus. */ ?>
  <div class="card wl-adm-sub">
    <p class="small" style="margin-top:0"><strong><i class="ti ti-mailbox"></i>
      Zugangsanfragen (<?= count($anfragen) ?>)</strong> – Gruppen, die über die öffentliche Seite
      dabei sein wollen. Übernehmen legt sie als Veranstalter an; die Zugangs-Mail mit dem
      Passwort-Link geht dabei automatisch an die Gruppe.</p>
    <?php foreach ($anfragen as $a): ?>
      <div class="wl-adm-ent">
        <div class="wl-adm-ent-b">
          <strong><?= h((string)$a['name']) ?></strong>
          <div class="small muted">
            <a href="mailto:<?= h((string)$a['mail']) ?>"><?= h((string)$a['mail']) ?></a>
            · <?= h((string)$a['created_at']) ?>
          </div>
          <?php if (trim((string)$a['nachricht']) !== ''): ?>
            <div class="small" style="margin-top:.25rem"><?= h((string)$a['nachricht']) ?></div>
          <?php endif; ?>
        </div>
        <div class="wl-adm-ent-a">
          <a class="btn secondary small" href="?anfrage=<?= (int)$a['id'] ?>#orgform">Übernehmen</a>
          <form method="post" data-confirm="Diese Anfrage entfernen, ohne die Gruppe anzulegen?" data-confirm-ok="Entfernen"><?= csrf_field() ?>
            <input type="hidden" name="action" value="request_del"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="btn danger small" type="submit">Entfernen</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php $katWuensche = wl_cat_wuensche(); if ($katWuensche): ?>
  <?php /* Kategorie-Vorschläge aus dem Einreich-Formular. BEWUSST ohne Automatik: Eine
           neue Kategorie braucht einen Eintrag in wl_cats() (wl-db.php) samt drei
           Standard-Fotos in assets/wl-standard/ – das bleibt eine Hand-Entscheidung. */ ?>
  <div class="card wl-adm-sub">
    <p class="small" style="margin-top:0"><strong><i class="ti ti-category-plus"></i>
      Kategorie-Vorschläge (<?= count($katWuensche) ?>)</strong> – Wünsche der Gruppen aus dem
      Einreich-Formular. Zum Umsetzen braucht es einen Eintrag in <code>wl_cats()</code>
      (wl-db.php) samt drei Standard-Fotos; Entfernen räumt den Vorschlag ab.</p>
    <?php foreach ($katWuensche as $kw): ?>
      <div class="wl-adm-ent">
        <div class="wl-adm-ent-b">
          <strong><?= h((string)($kw['org_name'] ?? '') ?: 'Gruppe gelöscht') ?></strong>
          <div class="small muted"><?= h((string)$kw['created_at']) ?></div>
          <div class="small" style="margin-top:.25rem"><?= h((string)$kw['text']) ?></div>
        </div>
        <div class="wl-adm-ent-a">
          <form method="post" data-confirm="Diesen Kategorie-Vorschlag entfernen?" data-confirm-ok="Entfernen"><?= csrf_field() ?>
            <input type="hidden" name="action" value="katwunsch_del"><input type="hidden" name="id" value="<?= (int)$kw['id'] ?>">
            <button class="btn danger small" type="submit">Entfernen</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <p class="small muted" style="margin-top:0">Jede Gruppe meldet sich mit ihrer
    <strong>Mailadresse und einem Passwort</strong> auf der Einreich-Seite an. Das Passwort legt
    die Gruppe <strong>selbst</strong> fest: Beim Anlegen geht automatisch eine Zugangs-Mail mit
    dem Link dafür raus. <strong>Zugang-Mail</strong> schickt den Link erneut – für „Passwort
    weg" oder wenn die erste Mail unterging (das können die Gruppen auch selbst über „Passwort
    vergessen?" auf der Einreich-Seite).</p>

  <?php foreach ($orgs as $o): ?>
    <div class="wl-adm-ent<?= (int)$o['active'] === 0 ? ' still' : '' ?>">
      <div class="wl-adm-ent-b">
        <strong><?= h((string)$o['name']) ?></strong>
        <span class="pill"><?= h((string)(wl_org_arten()[(string)$o['art']]['label'] ?? '?')) ?></span>
        <?php if ((int)$o['auto_ok'] === 1): ?><span class="pill"><i class="ti ti-bolt"></i> ohne Freigabe</span><?php endif; ?>
        <?php if ((int)$o['active'] === 0): ?><span class="pill">stillgelegt</span><?php endif; ?>
        <div class="small muted">
          <?php if (trim((string)$o['mail']) !== ''): ?>
            Anmeldung: <strong><?= h((string)$o['mail']) ?></strong>
            <?php if (trim((string)($o['pass_hash'] ?? '')) === ''): ?>
              · <i class="ti ti-alert-triangle"></i> Passwort noch nicht gesetzt
            <?php endif; ?>
          <?php else: ?>
            <i class="ti ti-alert-triangle"></i> keine Mailadresse – über „Bearbeiten" nachtragen,
            vorher kann sich die Gruppe nicht anmelden
          <?php endif; ?>
          <?php if (trim((string)$o['note']) !== ''): ?> · <?= h((string)$o['note']) ?><?php endif; ?>
        </div>
      </div>
      <div class="wl-adm-ent-a">
        <a class="btn secondary small" href="?org=<?= (int)$o['id'] ?>#orgform">Bearbeiten</a>
        <form method="post" data-confirm="Zugangs-Mail mit Passwort-Link an die Gruppe schicken?" data-confirm-ok="Schicken"><?= csrf_field() ?>
          <input type="hidden" name="action" value="org_zugangsmail"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button class="btn secondary small" type="submit">Zugang-Mail</button>
        </form>
        <form method="post"><?= csrf_field() ?>
          <input type="hidden" name="action" value="org_active"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button class="btn secondary small" type="submit"><?= (int)$o['active'] === 1 ? 'Stilllegen' : 'Aktivieren' ?></button>
        </form>
        <form method="post" data-confirm="„<?= h((string)$o['name']) ?>" ENDGÜLTIG löschen? Alle Beiträge, Bilder, das Logo und die Abos auf die Gruppe gehen mit – das lässt sich nicht rückgängig machen. (Nur vorübergehend raus? Dann lieber Stilllegen.)" data-confirm-ok="Endgültig löschen"><?= csrf_field() ?>
          <input type="hidden" name="action" value="org_del"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button class="btn danger small" type="submit">Löschen</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <form method="post" class="wl-adm-form" id="orgform" style="scroll-margin-top:80px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="org_save">
    <?php if ($editOrg): ?><input type="hidden" name="id" value="<?= (int)$editOrg['id'] ?>"><?php endif; ?>
    <p class="wl-adm-form-h"><i class="ti <?= $editOrg ? 'ti-pencil' : 'ti-user-plus' ?>"></i>
      <?= $editOrg ? 'Veranstalter bearbeiten: ' . h((string)$editOrg['name']) : 'Neuen Veranstalter anlegen' ?></p>
    <?php if ($vorlage): ?>
      <input type="hidden" name="anfrage_id" value="<?= (int)$vorlage['id'] ?>">
      <p class="small muted" style="margin:.3rem 0 0">Vorbefüllt aus der Anfrage von
        <strong><?= h((string)$vorlage['name']) ?></strong> – beim Anlegen wird sie abgeräumt.</p>
    <?php endif; ?>
    <div class="field-row">
      <div><label for="o_name">Name</label>
        <input type="text" name="name" id="o_name" required value="<?= h((string)($editOrg['name'] ?? $vorlage['name'] ?? '')) ?>" placeholder="HSG Welcome"></div>
      <div style="flex:0 1 110px"><label for="o_kurz">Kürzel</label>
        <input type="text" name="kurz" id="o_kurz" maxlength="2" value="<?= h((string)($editOrg['kurz'] ?? '')) ?>" placeholder="H"></div>
      <div style="flex:0 1 190px"><label for="o_art">Art</label>
        <select name="art" id="o_art">
          <?php foreach (wl_org_arten() as $ak => $ad): ?>
            <option value="<?= h($ak) ?>"<?= (string)($editOrg['art'] ?? 'hsg') === $ak ? ' selected' : '' ?>><?= h((string)$ad['label']) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="field-row">
      <div><label for="o_mail">Mailadresse (Anmeldename + Rückfragen)</label>
        <input type="email" name="mail" id="o_mail" required value="<?= h((string)($editOrg['mail'] ?? $vorlage['mail'] ?? '')) ?>"></div>
      <div><label for="o_note">Interne Notiz</label>
        <input type="text" name="note" id="o_note" maxlength="300" value="<?= h((string)($editOrg['note'] ?? '')) ?>" placeholder="Ansprechperson, Absprachen …"></div>
    </div>
    <label class="wl-sw">
      <input type="checkbox" name="auto_ok" value="1"<?= (int)($editOrg['auto_ok'] ?? 0) === 1 ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Ohne Freigabe veröffentlichen</strong>
        <span>Beiträge dieser Gruppe gehen sofort online – nur für Gruppen, denen ihr das
          wirklich zutraut (z. B. euch selbst).</span></span>
    </label>
    <div class="btn-row" style="margin-top:.9rem">
      <button class="btn" type="submit"><i class="ti <?= $editOrg ? 'ti-device-floppy' : 'ti-plus' ?>"></i>
        <?= $editOrg ? 'Speichern' : 'Veranstalter anlegen' ?></button>
      <?php if ($editOrg): ?><a class="btn secondary" href="<?= h($self) ?>?t=orgs">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</div>

<?php endif; ?>
<?php if ($tab === 'medien'): ?>

<!-- ================= Bildarchiv ================= -->
<div class="section-title" id="bilder"><i class="ti ti-photo"></i> Bildarchiv
  <span class="count"><?= count($stock) ?></span></div>
<div class="card">
  <p class="small muted" style="margin-top:0">Diese Bilder stehen <strong>allen</strong> Einreichenden
    zur Auswahl. Gedacht für Gruppen, die kein eigenes Foto haben – damit sie keines aus dem Netz
    ziehen. Nimm nur Bilder auf, deren Rechte wirklich geklärt sind: freie Bilddatenbanken, eigene
    Fotos, oder Material mit schriftlicher Erlaubnis.</p>

  <p class="small muted">Bildverarbeitung auf diesem Server: <strong><?= h(wl_image_engine_label()) ?></strong>.
    <?php if (wl_image_engine() === ''): ?>
      Ohne sie sind Uploads nicht möglich – bitte beim Hoster nachsehen.
    <?php else: ?>
      Hochgeladene Bilder werden automatisch verkleinert; versteckte Zusatzdaten wie der Aufnahmeort
      werden dabei entfernt.
    <?php endif; ?>
  </p>

  <?php if ($stock): ?>
    <div class="wl-adm-picks">
      <?php foreach ($stock as $b): ?>
        <div class="wl-adm-pick">
          <img src="veranstaltungen/bild.php?f=<?= h((string)$b['file']) ?>" alt="" width="220" height="140" loading="lazy">
          <div class="small muted"><?= h((string)$b['credit'] ?: 'ohne Nachweis') ?></div>
          <form method="post" data-confirm="Dieses Archivbild löschen?" data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?>
            <input type="hidden" name="action" value="stock_del"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="btn danger small" type="submit">Löschen</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (wl_image_engine() !== ''): ?>
    <form method="post" enctype="multipart/form-data" class="wl-adm-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stock_add">
      <p class="wl-adm-form-h"><i class="ti ti-photo-plus"></i> Bild ins Archiv aufnehmen</p>
      <div class="field-row">
        <div><label for="s_bild">Bilddatei</label>
          <input type="file" name="bild" id="s_bild" accept="<?= h(implode(',', array_keys(wl_image_types()))) ?>" required></div>
        <div><label for="s_credit">Bildnachweis</label>
          <input type="text" name="credit" id="s_credit" maxlength="120" placeholder="Foto: Unsplash / Vorname Nachname"></div>
      </div>
      <div class="btn-row" style="margin-top:.7rem"><button class="btn" type="submit"><i class="ti ti-plus"></i> Ins Archiv aufnehmen</button></div>
    </form>
  <?php endif; ?>
</div>

<!-- ================= Banner ================= -->
<div class="section-title" id="banner"><i class="ti ti-layout-navbar"></i> Banner
  <span class="count"><?= count($banner) ?></span></div>
<div class="card">
  <p class="small muted" style="margin-top:0">Großflächige Werbung ganz oben auf der Startseite –
    für Kampagnen wie die O-Woche. Ohne Zeitraum läuft ein Banner sofort und ohne Ende.</p>

  <?php foreach ($banner as $b): ?>
    <div class="wl-adm-ent<?= (int)$b['active'] === 0 ? ' still' : '' ?>">
      <div class="wl-adm-ent-b">
        <strong><?= h((string)$b['title']) ?></strong>
        <?php if ((int)$b['active'] === 0): ?><span class="pill">aus</span><?php endif; ?>
        <?php /* Untertitel und Zeitraum nur mit Trenner dazwischen, wenn es BEIDE gibt –
                 sonst steht bei leerem Untertitel ein verlorener Punkt vor dem Datum. */ ?>
        <?php $bTeile = [];
              if (trim((string)$b['subtitle']) !== '') $bTeile[] = (string)$b['subtitle'];
              if (trim((string)$b['von']) !== '' || trim((string)$b['bis']) !== '') {
                  $bTeile[] = (trim((string)$b['von']) !== '' ? fmt_date((string)$b['von']) : 'sofort')
                            . ' bis ' . (trim((string)$b['bis']) !== '' ? fmt_date((string)$b['bis']) : 'ohne Ende');
              } else {
                  $bTeile[] = 'läuft ohne Zeitraum';
              } ?>
        <div class="small muted"><?= h(implode(' · ', $bTeile)) ?></div>
      </div>
      <div class="wl-adm-ent-a">
        <a class="btn secondary small" href="?banner=<?= (int)$b['id'] ?>#bannerform">Bearbeiten</a>
        <form method="post" data-confirm="Banner löschen?" data-confirm-danger data-confirm-ok="Löschen"><?= csrf_field() ?>
          <input type="hidden" name="action" value="banner_del"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="btn danger small" type="submit">Löschen</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <form method="post" class="wl-adm-form" id="bannerform" style="scroll-margin-top:80px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="banner_save">
    <?php if ($editBan): ?><input type="hidden" name="id" value="<?= (int)$editBan['id'] ?>"><?php endif; ?>
    <p class="wl-adm-form-h"><i class="ti <?= $editBan ? 'ti-pencil' : 'ti-plus' ?>"></i>
      <?= $editBan ? 'Banner bearbeiten: ' . h((string)$editBan['title']) : 'Neuen Banner anlegen' ?></p>
    <div class="field-row">
      <div><label for="b_title">Titel</label>
        <input type="text" name="title" id="b_title" required value="<?= h((string)($editBan['title'] ?? '')) ?>" placeholder="O-Woche 2026"></div>
      <div><label for="b_sub">Unterzeile</label>
        <input type="text" name="subtitle" id="b_sub" maxlength="160" value="<?= h((string)($editBan['subtitle'] ?? '')) ?>"></div>
    </div>
    <div class="field-row">
      <div><label for="b_url">Ziel-Adresse</label>
        <input type="text" inputmode="url" name="url" id="b_url" value="<?= h((string)($editBan['url'] ?? '')) ?>" placeholder="beispiel.de/…"></div>
      <div style="flex:0 1 170px"><label for="b_von">Von</label>
        <input type="text" class="fp-date" name="von" id="b_von" value="<?= h((string)($editBan['von'] ?? '')) ?>"></div>
      <div style="flex:0 1 170px"><label for="b_bis">Bis</label>
        <input type="text" class="fp-date" name="bis" id="b_bis" value="<?= h((string)($editBan['bis'] ?? '')) ?>"></div>
    </div>
    <?php /* Bildwahl mit VORSCHAU: mitgelieferte Banner-Motive
             (assets/wl-standard/banner-*.jpg, wl_banner_bilder()) und Archiv-Bilder als
             anklickbare Kacheln – man sieht, was man festlegt. */ ?>
    <div class="field-row">
      <div><label>Bild</label>
        <?php $banWahl = trim((string)($editBan['std_bild'] ?? '')) !== ''
                  ? 'std:' . (string)$editBan['std_bild']
                  : (string)(int)($editBan['image_id'] ?? 0); ?>
        <div class="wl-adm-bildwahl">
          <label class="wl-bildopt">
            <input type="radio" name="bild" value="0"<?= $banWahl === '0' ? ' checked' : '' ?>>
            <span class="wl-bildopt-b wl-bildopt-leer">Kein Bild</span>
            <span class="small muted">Marken-Lichter</span>
          </label>
          <?php foreach (wl_banner_bilder() as $bb): ?>
            <label class="wl-bildopt">
              <input type="radio" name="bild" value="std:<?= h((string)$bb['file']) ?>"<?= $banWahl === 'std:' . (string)$bb['file'] ? ' checked' : '' ?>>
              <span class="wl-bildopt-b"><img src="assets/wl-standard/<?= h((string)$bb['file']) ?>" alt="" loading="lazy"></span>
              <span class="small muted"><?= h((string)$bb['name']) ?> · mitgeliefert</span>
            </label>
          <?php endforeach; ?>
          <?php foreach ($stock as $b2): ?>
            <label class="wl-bildopt">
              <input type="radio" name="bild" value="<?= (int)$b2['id'] ?>"<?= $banWahl === (string)(int)$b2['id'] ? ' checked' : '' ?>>
              <span class="wl-bildopt-b"><img src="veranstaltungen/bild.php?f=<?= h((string)$b2['file']) ?>" alt="" loading="lazy"></span>
              <span class="small muted"><?= h((string)($b2['credit'] ?: 'Archiv-Bild #' . $b2['id'])) ?></span>
            </label>
          <?php endforeach; ?>
        </div></div>
    </div>
    <div class="field-row">
      <div style="flex:0 1 130px"><label for="b_sort">Reihenfolge</label>
        <input type="number" name="sort" id="b_sort" value="<?= (int)($editBan['sort'] ?? 0) ?>"></div>
    </div>
    <label class="wl-sw">
      <input type="checkbox" name="active" value="1"<?= (!$editBan || (int)$editBan['active'] === 1) ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Aktiv</strong>
        <span>Ausgeschaltet bleibt der Banner vorbereitet, erscheint aber nicht auf der Seite.</span></span>
    </label>
    <div class="btn-row" style="margin-top:.9rem">
      <button class="btn" type="submit"><i class="ti <?= $editBan ? 'ti-device-floppy' : 'ti-plus' ?>"></i>
        <?= $editBan ? 'Speichern' : 'Banner anlegen' ?></button>
      <?php if ($editBan): ?><a class="btn secondary" href="<?= h($self) ?>?t=medien#banner">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</div>

<?php endif; ?>
<?php if ($tab === 'texte'): ?>

<!-- ================= Texte ================= -->
<div class="section-title" id="texte"><i class="ti ti-text-caption"></i> Texte</div>
<div class="card">
  <p class="small muted" style="margin:0 0 .3rem">Die Sätze der öffentlichen Seiten – Startseite,
    Kurse, Eintragen, Fußzeile. Was hier steht, steht sofort draußen.</p>
  <ul class="small muted" style="margin:0 0 1rem;padding-left:1.2rem">
    <li>Ein <strong>leeres Feld blendet das Element aus</strong> – so wird man Dinge auch los.</li>
    <li>Bei mehrzeiligen Feldern ist eine <strong>Leerzeile ein neuer Absatz</strong>.</li>
    <li>Die Marken-Sätze der großen Überschriften („was.läuft … in Landau?") stehen bewusst
      nicht hier: Sie sind mit der Wort-Animation verwachsen.</li>
  </ul>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_texts">

    <?php foreach (wl_text_groups() as $gKey => $g): ?>
      <div class="section-title" style="font-size:1rem;margin:1.3rem 0 .5rem">
        <i class="ti <?= h($g['icon']) ?>"></i> <?= h($g['label']) ?>
      </div>
      <?php foreach (wl_text_fields() as $fKey => $f):
          if ($f['group'] !== $gKey) continue;
          $val  = wl_text($fKey);
          $fid  = 't_' . $fKey;
          $rows = max(2, substr_count($val, "\n") + 2);
      ?>
        <label for="<?= h($fid) ?>"><?= h($f['label']) ?></label>
        <?php if (!empty($f['hint'])): ?>
          <p class="small muted" style="margin:.15rem 0 .3rem"><?= h($f['hint']) ?></p>
        <?php endif; ?>
        <?php if ($f['type'] === 'line'): ?>
          <input type="text" name="<?= h($fid) ?>" id="<?= h($fid) ?>" value="<?= h($val) ?>" style="width:100%">
        <?php else: ?>
          <textarea name="<?= h($fid) ?>" id="<?= h($fid) ?>" rows="<?= (int)$rows ?>" style="width:100%"><?= h($val) ?></textarea>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="btn-row" style="margin-top:1rem">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Alle Texte speichern</button>
    </div>
  </form>
  <form method="post" data-confirm="Alle Texte auf den Standard zurücksetzen? Eigene Formulierungen gehen verloren." data-confirm-ok="Zurücksetzen" style="margin-top:.5rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_texts">
    <button class="btn secondary small" type="submit"><i class="ti ti-restore"></i> Auf Standard zurücksetzen</button>
  </form>
</div>

<?php endif; ?>
<?php if ($tab === 'promo'): ?>

<?php /* ---- Promo & Pressekit -------------------------------------------------------------
         Alles, was man zum Bewerben der Seite braucht: Marke und Farben zum Nachschlagen,
         fertige Druck- und Insta-Vorlagen (ein Entwurf, die A-Reihe skaliert verlustfrei),
         ein lebender QR-Code und die Pressetexte. Der ZIP-Knopf bündelt das Kit. */ ?>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-package"></i> Pressekit</div>
  <p class="small muted" style="margin-top:0">Alles in einem Rutsch: die Vorlagen unten, die Marken-Dateien
    und ein Farben-Spickzettel. Zum Weitergeben an Pressestellen, Kooperationspartner oder die Stadt.</p>
  <p class="small muted">Und für einzelne Beiträge: Jeder Live-Beitrag hat eine eigene
    <strong>Teilen-Seite</strong> mit fertigen Insta-Bildern, QR-Code und Begleittext aus seinen Daten –
    erreichbar über <em>Teilen</em> am Beitrag (hier in der Liste, auf der Veranstaltungsseite und
    direkt nach dem Eintragen). Veranstalter bewerben ihr Event damit ohne Doppelaufwand.</p>
  <a class="btn" href="<?= h($self) ?>?t=promo&amp;zip=1"><i class="ti ti-file-zip"></i> Pressekit herunterladen (ZIP)</a>
</div>

<?php /* ---- Banner für die eigene Website ---------------------------------------------------
         Ein Stück HTML zum Einsetzen (WordPress/Elementor: Widget „HTML"). Die Vorlage liegt als
         Datei im Promo-Ordner und wandert damit auch ins Pressekit – hier wird nur die Adresse
         eingesetzt, damit niemand sie von Hand ersetzen muss (und dabei vergisst).
         Ohne eingetragene Basis-Adresse gibt es nichts zu kopieren: Ein Banner, das auf
         „WLB_URL" zeigt, wäre schlimmer als keines. */ ?>
<?php
$bannerDatei = __DIR__ . '/assets/wl-promo/banner-website.html';
$bannerBasis = wl_base_url();
$bannerCode  = is_file($bannerDatei) ? (string)file_get_contents($bannerDatei) : '';
if ($bannerCode !== '' && $bannerBasis !== '') {
    $bannerCode = str_replace('WLB_URL', $bannerBasis . '/veranstaltungen/banner.php', $bannerCode);
}
?>
<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-layout-navbar"></i> Banner für eure Website</div>
  <p class="small muted" style="margin-top:0">Ein fettes Band für die Startseite von
    der eigenen Website – mit Marke, einem Satz und einem Knopf. Besuchende können es über das
    <strong>×</strong> wegklicken; dann bleibt es auf ihrem Gerät <strong>drei Wochen</strong>
    verschwunden. Es lädt nichts von außen: kein Plugin, keine Schrift, kein fremdes Skript. <strong>Der Code muss nur einmal eingesetzt werden</strong> – an, aus und die Texte steuerst du danach von hier aus.</p>

  <?php if ($bannerBasis === ''): ?>
    <p class="note"><i class="ti ti-alert-triangle"></i> Dafür fehlt die <strong>Basis-Adresse</strong>
      – trag sie bei <a href="<?= h($self) ?>?t=einstellungen">Einstellungen</a> ein, dann steht
      hier der fertige Code.</p>
  <?php else: ?>
    <?php /* Der Schalter steht VOR der Anleitung: Wer das Banner schon eingesetzt hat, kommt
             nur noch wegen ihm hierher – die Einbau-Anleitung liest man genau einmal. */ ?>
    <form method="post" style="margin-bottom:1rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="banner_web">
      <label class="wl-sw" style="margin:0">
        <input type="checkbox" name="banner_web" value="1"<?= wl_setting('banner_web', '0') === '1' ? ' checked' : '' ?>
               onchange="this.form.submit()">
        <span class="wl-sw-track" aria-hidden="true"></span>
        <span class="wl-sw-txt"><strong>Banner auf der Website zeigen</strong>
          <em>Wirkt sofort für alle, die die Seite neu laden – spätestens nach fünf Minuten
            (die Website merkt sich die Antwort so lange). Die Überschrift und die beiden Texte
            stehen unter <a href="<?= h($self) ?>?t=texte">Texte → Banner für fremde Websites</a>;
            eine leere Überschrift lässt das Banner ebenfalls aus.</em></span>
      </label>
      <noscript><button class="btn secondary small" type="submit" style="margin-top:.5rem">Umschalten</button></noscript>
    </form>

    <p class="small" style="margin-bottom:.3rem"><strong>So kommt es auf die Seite:</strong>
      In WordPress die Startseite mit Elementor bearbeiten → ganz oben einen Abschnitt einfügen →
      Widget <strong>„HTML"</strong> hineinziehen → den Code unten hineinkopieren → speichern.</p>
    <?php /* Eingeklappt: Der Code ist lang, wird genau einmal gebraucht und stünde sonst
             zwischen dem Schalter und allem anderen im Weg. */ ?>
    <details class="collapse-card" style="border:1px solid var(--line);border-radius:10px;padding:.7rem .85rem">
      <summary style="display:flex;align-items:center;gap:.5rem;font-weight:600">
        <i class="ti ti-code"></i> Code zum Einsetzen
        <i class="ti ti-chevron-down collapse-chev" style="margin-left:auto;transition:transform .15s ease"></i>
      </summary>
      <div style="margin-top:.7rem">
        <textarea id="promo-banner" class="wl-promo-text" readonly rows="10"><?= h($bannerCode) ?></textarea>
        <div class="btn-row" style="margin-top:.4rem">
          <button class="btn secondary" type="button" data-kopier="promo-banner"><i class="ti ti-copy"></i> Kopieren</button>
          <a class="btn secondary" href="<?= h($self) ?>?promo=banner-website.html&amp;dl=1" download><i class="ti ti-download"></i> Als Datei</a>
        </div>
      </div>
    </details>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-palette"></i> Marke &amp; Farben</div>
  <p class="small muted" style="margin-top:0">Die Wortmarke: <strong>was</strong> in Weiß, der Punkt in Limette,
    <strong>läuft</strong> im Verlauf Gelb → Koralle – bevorzugt auf dunklem Grund. Schrift: eine kräftige
    serifenlose (fett), auf der Seite die Systemschrift.</p>
  <div class="wl-promo-farben">
    <span><i style="background:#17141c"></i>Tinte · #17141c</span>
    <span><i style="background:#ffd93d"></i>Gelb · #ffd93d</span>
    <span><i style="background:#ff5c72"></i>Koralle · #ff5c72</span>
    <span><i style="background:#d9f24b"></i>Limette · #d9f24b</span>
    <span><i style="background:linear-gradient(92deg,#ffd93d,#ff5c72 75%)"></i>Marken-Verlauf</span>
  </div>
  <div class="btn-row" style="margin-top:.8rem">
    <a class="btn secondary" href="assets/wl-icon.svg" download><i class="ti ti-download"></i> Marken-Icon (SVG)</a>
    <a class="btn secondary" href="assets/wl-og.png" download><i class="ti ti-download"></i> Vorschaubild (1200 × 630)</a>
    <a class="btn secondary" href="<?= h(brand_url('logo')) ?>" download><i class="ti ti-download"></i> AStA-Logo</a>
    <a class="btn secondary" href="<?= h(brand_url('logo-verlauf')) ?>" download><i class="ti ti-download"></i> AStA-Logo im was.läuft-Verlauf</a>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-qrcode"></i> QR-Code</div>
  <p class="small muted" style="margin-top:0">Führt über die Kurz-Adresse auf die Startseite.
    In den Druck- und Insta-Vorlagen ist derselbe Code bereits eingebaut.</p>
  <div class="wl-promo-qr">
    <div id="promo-qr"></div>
    <div>
      <p class="small" style="margin:0 0 .6rem"><strong><?= h(wl_url_kurz(wl_share_base())) ?></strong></p>
      <button class="btn secondary" type="button" id="promo-qr-dl"><i class="ti ti-download"></i> Als Bild herunterladen</button>
    </div>
  </div>
</div>

<?php /* Das Logo als Data-URI, damit der Vorlagen-Generator es ins Bild zeichnen kann, ohne
           eine zweite Anfrage abzuwarten. Quelle ist die Marke dieser Installation. */ ?>
<?php $logoKlein = brand_data_uri('logo-420'); ?>
<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-wand"></i> Vorlagen-Generator</div>
  <p class="small muted" style="margin-top:0">Spruch wählen oder selbst schreiben, Format aussuchen, herunterladen –
    als druckfertiges PNG oder als SVG für die Druckerei. Große Formate tragen automatisch mehr Infos.</p>
  <div class="wl-promo-gen">
    <div class="wl-promo-gen-form">
      <label class="small" for="pg-spruch"><strong>Spruch</strong></label>
      <select id="pg-spruch">
        <?php foreach (wl_promo_sprueche() as $i => $sp): ?>
          <option value="<?= $i ?>"><?= h($sp) ?></option>
        <?php endforeach; ?>
        <option value="eigen">Eigener Text …</option>
      </select>
      <input type="text" id="pg-eigen" maxlength="90" placeholder="Dein Spruch (kurz und knackig)" hidden>
      <label class="small" for="pg-format"><strong>Format</strong></label>
      <select id="pg-format">
        <option value="story">Insta-Story (1080 × 1920)</option>
        <option value="post">Insta-Post (1080 × 1080)</option>
        <option value="flyer">Flyer A6 (druckfertig)</option>
        <option value="a4">Plakat A4 (druckfertig, mit Infos)</option>
        <option value="a3">Plakat A3 (druckfertig, mit Infos)</option>
        <option value="a1">Aufsteller / Gehweg-Stopper A1 (mit Infos)</option>
      </select>
      <label class="small" for="pg-url"><strong>Gedruckte Adresse</strong></label>
      <input type="text" id="pg-url" value="<?= h(wl_url_kurz(wl_share_base())) ?>">
      <details class="wl-promo-gen-mehr">
        <summary class="small"><strong>Alle Texte bearbeiten</strong> (leeres Feld = Zeile weglassen)</summary>
        <label class="small" for="pg-unter">Untertitel</label>
        <input type="text" id="pg-unter" value="Der Veranstaltungskalender für <?= h(wl_ort()) ?>">
        <label class="small" for="pg-kat">Kategorien-Zeile</label>
        <input type="text" id="pg-kat" value="Partys · Kultur · Sport · Vorträge · Kurse — kostenlos, ohne Konto">
        <label class="small" for="pg-fuss">Fußzeile</label>
        <input type="text" id="pg-fuss" value="Ein Angebot deines AStA · von Studis für Studis">
        <label class="small" for="pg-cta">Aufruf-Zeile (Flyer/Plakat)</label>
        <input type="text" id="pg-cta" value="Du veranstaltest? Trag dein Event kostenlos ein.">
        <label class="small" for="pg-info">Info-Zeilen (nur Plakat/Aufsteller, eine je Zeile)</label>
        <textarea id="pg-info" rows="3">Alles an einem Ort: Partys · Kultur · Sport · Vorträge · Kurse
kostenlos · werbefrei · ohne Konto
mit Stadt, Studierendenwerk, Fachschaften &amp; Hochschulgruppen</textarea>
      </details>
      <p class="small muted" style="margin:.2rem 0 0">Der QR-Code zeigt immer auf die echte Seite und funktioniert
        sofort – die gedruckte Kurz-Adresse braucht die Weiterleitung (Kasten unten).</p>
      <div class="btn-row" style="margin-top:.7rem">
        <button class="btn" type="button" id="pg-png"><i class="ti ti-download"></i> PNG herunterladen</button>
        <button class="btn secondary" type="button" id="pg-svg"><i class="ti ti-download"></i> SVG (Druckerei)</button>
      </div>
    </div>
    <div class="wl-promo-gen-schau"><img id="pg-schau" alt="Vorschau der Vorlage"></div>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-link"></i> Kurz-Adresse einrichten</div>
  <p class="small muted" style="margin-top:0">Fürs Gedruckte soll <strong><?= h(wl_url_kurz(wl_share_base())) ?></strong> reichen.
    Das ist eine Ein-Zeilen-Weiterleitung auf der Hauptseite:</p>
  <ol class="small" style="margin:.3rem 0 .6rem 1.2rem; line-height:1.7">
    <li>Mittwald → Dateiverwaltung (oder SFTP) → in den Ordner der Hauptseite (<code>html/wp-Asta</code>) → Datei <code>.htaccess</code> öffnen.</li>
    <li>GANZ OBEN, noch vor der Zeile <code># BEGIN WordPress</code>, diese eine Zeile einfügen:</li>
  </ol>
  <pre class="wl-promo-code">RedirectMatch 301 ^/was\.?laeuft(/.*)?$ <?= h(rtrim(wl_base_url(), '/')) ?>/veranstaltungen$1</pre>
  <p class="small muted" style="margin:.5rem 0 0">Speichern – fertig. Danach führen <strong><?= h(wl_url_kurz(wl_share_base())) ?></strong>
    und <strong>…/waslaeuft</strong> (falls jemand den Punkt weglässt) auf die Seite. WordPress stört das nicht,
    die Zeile greift vor dessen Regeln.</p>
</div>

<?php foreach (wl_promo_dateien() as $gk => $gruppe): ?>
<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti <?= $gk === 'druck' ? 'ti-printer' : 'ti-brand-instagram' ?>"></i> <?= h($gruppe['label']) ?></div>
  <?php if ($gk === 'druck'): ?>
    <p class="small muted" style="margin-top:0">Ein Entwurf, vier Größen – die A-Reihe skaliert verlustfrei, der QR-Code
      ist als echte Vektorgrafik eingebaut. Fürs Drucken: Datei im Browser öffnen und über <em>Drucken</em> als PDF
      sichern, oder die SVG direkt an die Druckerei geben (randlos, ohne Beschnittzugabe angelegt).</p>
  <?php else: ?>
    <p class="small muted" style="margin-top:0">Schleifen zum Mitschneiden: Datei öffnen, Fenster auf die angegebene
      Größe bringen, Bildschirmaufnahme laufen lassen. <em>Mit euren Texten</em> baut die Schleife mit dem Spruch und
      den Texten aus dem Generator oben – heraus fällt eine eigene Datei.</p>
  <?php endif; ?>
  <?php foreach ($gruppe['dateien'] as $datei => $label): $voll = __DIR__ . '/assets/wl-promo/' . $datei; ?>
    <div class="wl-adm-ent">
      <div class="wl-adm-ent-b">
        <strong><?= h($label) ?></strong>
        <span class="small muted"><?= h($datei) ?><?= is_file($voll) ? ' · ' . number_format(filesize($voll) / 1024, 0, ',', '.') . ' kB' : ' · FEHLT (Ordner assets/wl-promo hochladen)' ?></span>
      </div>
      <div class="wl-adm-ent-a">
        <a class="btn secondary" href="<?= h($self) ?>?promo=<?= h(rawurlencode($datei)) ?>" target="_blank" rel="noopener"><i class="ti ti-eye"></i> Ansehen</a>
        <a class="btn secondary" href="<?= h($self) ?>?promo=<?= h(rawurlencode($datei)) ?>&amp;dl=1" download><i class="ti ti-download"></i> Herunterladen</a>
        <button class="btn small" type="button" data-anim="<?= h($datei) ?>"><i class="ti ti-wand"></i> Mit euren Texten</button>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="card" style="margin-bottom:1.1rem">
  <div class="section-title" style="margin-top:0"><i class="ti ti-news"></i> Pressetexte</div>
  <p class="small muted" style="margin-top:0">Zum Kopieren – kurz für Ankündigungen und Bildunterschriften,
    lang für Pressemitteilungen und Kooperationsanfragen.</p>
  <label class="small" for="promo-kurz"><strong>Kurz</strong></label>
  <textarea id="promo-kurz" class="wl-promo-text" readonly rows="3">was.läuft ist der neue Veranstaltungskalender für Studierende in <?= h(wl_ort()) ?>: Partys, Kultur, Sport, Vorträge und Kurse – gesammelt an einem Ort, kostenlos und ohne Konto. Ein Angebot des <?= h(wl_traeger_kurz()) ?>: <?= h(wl_url_kurz(wl_share_base())) ?></textarea>
  <div class="btn-row" style="margin:.4rem 0 .9rem"><button class="btn secondary" type="button" data-kopier="promo-kurz"><i class="ti ti-copy"></i> Kopieren</button></div>
  <label class="small" for="promo-lang"><strong>Lang</strong></label>
  <textarea id="promo-lang" class="wl-promo-text" readonly rows="8">Was läuft eigentlich in <?= h(wl_ort()) ?>? Ab sofort gibt es darauf eine Antwort: was.läuft, der Veranstaltungskalender für Studierende – ein Angebot des <?= h(wl_traeger_recht() !== wl_traeger_kurz() ? wl_traeger_recht() : wl_traeger_kurz()) ?>.

Auf <?= h(wl_url_kurz(wl_share_base())) ?> sammeln Hochschulgruppen, Fachschaften und Betreiber kulturrelevanter Einrichtungen ihr Programm an einem Ort: Partys, Kultur, Sport, Vorträge und regelmäßige Kurse. Die Seite ist kostenlos, werbefrei und ohne Konto nutzbar; wer selbst etwas veranstaltet, trägt es über einen eigenen Zugang ein – der Träger gibt frei.

Der Träger arbeitet dafür mit der Stadt <?= h(wl_ort()) ?>, dem Studierendenwerk, den Fachschaften und der <?= h(wl_ort_adj()) ?> Kulturszene zusammen. Kontakt für Veranstalter: über den Knopf „+ Eintragen" auf der Seite.</textarea>
  <div class="btn-row" style="margin-top:.4rem"><button class="btn secondary" type="button" data-kopier="promo-lang"><i class="ti ti-copy"></i> Kopieren</button></div>
</div>

<script src="<?= base() . asset_v('assets/qrcode.min.js') ?>"></script>
<script>
(function () {
  var ziel = <?= json_encode(wl_share_base() . '/') ?>;
  var box = document.getElementById('promo-qr');
  if (box && window.QRCode) new QRCode(box, { text: ziel, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
  var dl = document.getElementById('promo-qr-dl');
  if (dl) dl.addEventListener('click', function () {
    var c = box ? box.querySelector('canvas') : null;
    var img = box ? box.querySelector('img') : null;
    var quelle = c ? c.toDataURL('image/png') : (img ? img.src : '');
    if (!quelle) return;
    var a = document.createElement('a');
    a.href = quelle; a.download = 'waslaeuft-qr.png';
    document.body.appendChild(a); a.click(); a.remove();
  });
  document.querySelectorAll('[data-kopier]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var feld = document.getElementById(btn.getAttribute('data-kopier'));
      if (!feld) return;
      feld.select();
      var fertig = function () {
        var alt = btn.innerHTML;
        btn.innerHTML = '<i class="ti ti-check"></i> Kopiert';
        setTimeout(function () { btn.innerHTML = alt; }, 1500);
      };
      if (navigator.clipboard) navigator.clipboard.writeText(feld.value).then(fertig, function () { document.execCommand('copy'); fertig(); });
      else { document.execCommand('copy'); fertig(); }
    });
  });

  // ---- Vorlagen-Generator ---------------------------------------------------------------------
  // Baut die Vorlage als SVG-Zeichenkette (eine Layout-Quelle für alles) und rendert daraus
  // die Vorschau, das Druck-PNG und die Druckerei-SVG. Der QR steckt als echte Vektor-Module
  // drin (Matrix aus der QR-Bibliothek), das AStA-Logo als eingebettetes Original.
  var SPRUECHE = <?= json_encode(wl_promo_sprueche(), JSON_UNESCAPED_UNICODE) ?>;
  var LOGO = <?= json_encode($logoKlein) ?>;
  var FSS = "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif";
  var FMT = {
    story: { art: 'social', vw: 1080, vh: 1920, px: [1080, 1920] },
    post:  { art: 'social', vw: 1080, vh: 1080, px: [1080, 1080] },
    flyer: { art: 'flyer',  vw: 1080, vh: 1527, mm: [105, 148], px: [1240, 1748] },
    a4:    { art: 'plakat', vw: 1080, vh: 1527, mm: [210, 297], px: [2480, 3508] },
    a3:    { art: 'plakat', vw: 1080, vh: 1527, mm: [297, 420], px: [2923, 4134] },
    a1:    { art: 'plakat', vw: 1080, vh: 1527, mm: [594, 841], px: [2806, 3974] }
  };
  var mess = document.createElement('canvas').getContext('2d');
  var qrMatrix = null;
  if (window.QRCode) {
    var qLager = document.createElement('div');
    var q = new QRCode(qLager, { text: ziel, correctLevel: QRCode.CorrectLevel.M });
    var mod = q._oQRCode, nMod = mod.getModuleCount();
    qrMatrix = [];
    for (var r = 0; r < nMod; r++) { var z = []; for (var c = 0; c < nMod; c++) z.push(mod.isDark(r, c)); qrMatrix.push(z); }
  }
  function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  // Alle dunklen QR-Module als EIN Pfad – für die SVG-Vorlagen und die Animation.
  function qrPfad(qx, qy, qg) {
    if (!qrMatrix) return '';
    var m = qg / qrMatrix.length, p = '';
    for (var r = 0; r < qrMatrix.length; r++) {
      var c = 0;
      while (c < qrMatrix.length) {
        if (qrMatrix[r][c]) {
          var lauf = 1;
          while (c + lauf < qrMatrix.length && qrMatrix[r][c + lauf]) lauf++;
          p += 'M' + (qx + c * m).toFixed(2) + ' ' + (qy + r * m).toFixed(2) + 'h' + (lauf * m).toFixed(2) + 'v' + m.toFixed(2) + 'h-' + (lauf * m).toFixed(2) + 'z';
          c += lauf;
        } else c++;
      }
    }
    return p;
  }
  function breite(t, fs, gw) { mess.font = (gw || 700) + ' ' + fs + 'px ' + FSS.replace(/"/g, ''); return mess.measureText(t).width; }
  function umbruch(t, fs, gw, maxW, maxZ) {
    var w = String(t).split(/\s+/), aus = [], zeile = '';
    for (var i = 0; i < w.length; i++) {
      var probe = zeile === '' ? w[i] : zeile + ' ' + w[i];
      if (breite(probe, fs, gw) <= maxW || zeile === '') zeile = probe;
      else { aus.push(zeile); zeile = w[i]; if (aus.length === maxZ - 1) { zeile = w.slice(i).join(' '); break; } }
    }
    if (zeile !== '') aus.push(zeile);
    return aus;
  }
  function svgBau(fkey, W0) {
    var spruch = W0.spruch, urlText = W0.url;
    var f = FMT[fkey], W = f.vw, H = f.vh, T = [];
    T.push('<defs><radialGradient id="pl1" cx="8%" cy="105%" r="65%"><stop offset="0" stop-color="#ffd93d" stop-opacity=".16"/><stop offset="1" stop-color="#ffd93d" stop-opacity="0"/></radialGradient>'
      + '<radialGradient id="pl2" cx="92%" cy="-5%" r="70%"><stop offset="0" stop-color="#ff5c72" stop-opacity=".22"/><stop offset="1" stop-color="#ff5c72" stop-opacity="0"/></radialGradient></defs>');
    T.push('<rect width="' + W + '" height="' + H + '" fill="#17141c"/>');
    T.push('<rect width="' + W + '" height="' + H + '" fill="url(#pl1)"/><rect width="' + W + '" height="' + H + '" fill="url(#pl2)"/>');
    function txt(t, y, fs, farbe, gw) { T.push('<text x="540" y="' + y + '" text-anchor="middle" font-family="' + FSS + '" font-weight="' + (gw || 700) + '" font-size="' + fs + '" fill="' + farbe + '">' + esc(t) + '</text>'); }
    function block(t, y, fs, lh, maxW, maxZ, farbe, gw) {
      var zn = umbruch(t, fs, gw, maxW, maxZ);
      zn.forEach(function (z, i) { txt(z, y + i * lh, fs, farbe, gw); });
      return y + (zn.length - 1) * lh;
    }
    function logo(y, w) {
      if (LOGO === '') return y;
      var h = Math.round(w * 293 / 420);
      T.push('<image x="' + (540 - w / 2) + '" y="' + y + '" width="' + w + '" height="' + h + '" href="' + LOGO + '"/>');
      return y + h;
    }
    function marke(y, fs) {
      var bWas = breite('was', fs, 800), bDot = breite('.', fs, 800), bW = breite('läuft', fs, 800);
      var links = 540 - (bWas + bDot + bW) / 2;
      T.push('<linearGradient id="pvw" gradientUnits="userSpaceOnUse" x1="' + (links + bWas + bDot) + '" y1="0" x2="' + (links + bWas + bDot + bW) + '" y2="0">'
        + '<stop offset="0" stop-color="#ffd93d"/><stop offset=".75" stop-color="#ff5c72"/></linearGradient>');
      T.push('<text x="' + links + '" y="' + y + '" font-family="' + FSS + '" font-weight="800" font-size="' + fs + '">'
        + '<tspan fill="#ffffff">was</tspan><tspan fill="#d9f24b">.</tspan><tspan fill="url(#pvw)">läuft</tspan></text>');
    }
    function qrKarte(y, g) {
      if (!qrMatrix) return;
      T.push('<rect x="' + (540 - g / 2) + '" y="' + y + '" width="' + g + '" height="' + g + '" rx="' + g * .08 + '" fill="#ffffff"/>');
      var qg = g * .82;
      T.push('<path d="' + qrPfad(540 - qg / 2, y + (g - qg) / 2, qg) + '" fill="#17141c"/>');
    }
    if (fkey === 'story') {
      logo(90, 250);
      block(spruch, 470, 92, 108, 900, 3, '#ffffff', 800);
      marke(870, 160);
      if (W0.unter) txt(W0.unter, 946, 46, '#d9f24b', 800);
      if (W0.kat) txt(W0.kat, 1010, 33, '#cdc6bb', 400);
      qrKarte(1300, 330);
      txt(urlText, 1698, 42, '#ffffff', 800);
      if (W0.fuss) txt(W0.fuss, 1756, 30, '#cdc6bb', 400);
    } else if (fkey === 'post') {
      logo(56, 190);
      block(spruch, 300, 64, 78, 900, 2, '#ffffff', 800);
      marke(510, 116);
      if (W0.unter) txt(W0.unter, 572, 38, '#d9f24b', 800);
      if (W0.kat) txt(W0.kat, 622, 29, '#cdc6bb', 400);
      qrKarte(680, 240);
      txt(urlText, 984, 34, '#ffffff', 800);
      if (W0.fuss) txt(W0.fuss, 1032, 26, '#cdc6bb', 400);
    } else if (f.art === 'flyer') {
      logo(64, 190);
      block(spruch, 320, 74, 88, 920, 3, '#ffffff', 800);
      marke(700, 132);
      if (W0.unter) txt(W0.unter, 764, 42, '#d9f24b', 800);
      if (W0.kat) txt(W0.kat, 820, 29, '#cdc6bb', 400);
      qrKarte(890, 320);
      txt(urlText, 1290, 40, '#ffffff', 800);
      if (W0.fuss) txt(W0.fuss, 1346, 27, '#cdc6bb', 400);
      if (W0.cta) txt(W0.cta, 1440, 27, '#ffffff', 700);
    } else {
      logo(50, 175);
      block(spruch, 290, 82, 96, 940, 3, '#ffffff', 800);
      marke(640, 150);
      if (W0.unter) txt(W0.unter, 712, 46, '#d9f24b', 800);
      (W0.info || []).forEach(function (z, i) { txt(z, 782 + i * 46, 31, '#cdc6bb', 400); });
      qrKarte(940, 310);
      txt(urlText, 1332, 44, '#ffffff', 800);
      if (W0.cta) txt(W0.cta, 1402, 30, '#ffffff', 700);
      if (W0.fuss) txt(W0.fuss, 1452, 26, '#cdc6bb', 400);
    }
    var mass = f.mm ? ' width="' + f.mm[0] + 'mm" height="' + f.mm[1] + 'mm"' : ' width="' + W + '" height="' + H + '"';
    return '<svg xmlns="http://www.w3.org/2000/svg"' + mass + ' viewBox="0 0 ' + W + ' ' + H + '">' + T.join('') + '</svg>';
  }
  var pgSpruch = document.getElementById('pg-spruch'), pgEigen = document.getElementById('pg-eigen'),
      pgFormat = document.getElementById('pg-format'), pgUrl = document.getElementById('pg-url'),
      pgSchau = document.getElementById('pg-schau');
  function pgWerte() {
    var s = pgSpruch.value === 'eigen'
      ? (pgEigen.value.trim() || SPRUECHE[0])
      : SPRUECHE[parseInt(pgSpruch.value, 10)] || SPRUECHE[0];
    var feld = function (id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
    return { spruch: s, format: pgFormat.value, url: pgUrl.value.trim() || <?= json_encode(wl_url_kurz(wl_share_base())) ?>,
      unter: feld('pg-unter'), kat: feld('pg-kat'), fuss: feld('pg-fuss'), cta: feld('pg-cta'),
      info: feld('pg-info').split('\n').map(function (z) { return z.trim(); }).filter(Boolean).slice(0, 3) };
  }
  function pgDatenUri() {
    var w = pgWerte();
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgBau(w.format, w));
  }
  function pgZeigen() {
    if (!pgSchau) return;
    pgEigen.hidden = pgSpruch.value !== 'eigen';
    pgSchau.src = pgDatenUri();
  }
  if (pgSpruch) {
    [pgSpruch, pgEigen, pgFormat, pgUrl,
     document.getElementById('pg-unter'), document.getElementById('pg-kat'),
     document.getElementById('pg-fuss'), document.getElementById('pg-cta'),
     document.getElementById('pg-info')].forEach(function (el) {
      if (!el) return;
      el.addEventListener('input', pgZeigen);
      el.addEventListener('change', pgZeigen);
    });
    pgZeigen();
    document.getElementById('pg-svg').addEventListener('click', function () {
      var a = document.createElement('a');
      a.href = pgDatenUri();
      a.download = 'waslaeuft-' + pgWerte().format + '.svg';
      document.body.appendChild(a); a.click(); a.remove();
    });
    /* Animationen mit den Generator-Texten: Die Schleifen-HTML wird geladen, die
       benannten Textstellen (.frage/.kal/.kat/.url/.klein bzw. .unter) und der QR-Pfad
       werden ersetzt, heraus fällt eine eigenständige Datei. Leeres Feld = Zeile weg. */
    document.querySelectorAll('[data-anim]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var datei = btn.getAttribute('data-anim');
        var w = pgWerte();
        fetch(<?= json_encode($self) ?> + '?promo=' + encodeURIComponent(datei)).then(function (r) { return r.text(); }).then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var setz = function (sel, wert) {
            var el = doc.querySelector(sel);
            if (!el) return;
            if (wert === '') el.remove(); else el.textContent = wert;
          };
          setz('.unter', w.unter);                     // Logo-Verwandlung: Untertitel
          var qp = doc.querySelector('.karte path');
          if (qp) qp.setAttribute('d', qrPfad(31, 31, 282));
          var blob = new Blob(['<!DOCTYPE html>\n' + doc.documentElement.outerHTML], { type: 'text/html' });
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = datei.replace('.html', '') + '-eigene.html';
          document.body.appendChild(a); a.click(); a.remove();
          setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
        });
      });
    });
    document.getElementById('pg-png').addEventListener('click', function () {
      var f = FMT[pgWerte().format];
      var img = new Image();
      img.onload = function () {
        var c = document.createElement('canvas');
        c.width = f.px[0]; c.height = f.px[1];
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        var a = document.createElement('a');
        a.href = c.toDataURL('image/png');
        a.download = 'waslaeuft-' + pgWerte().format + '.png';
        document.body.appendChild(a); a.click(); a.remove();
      };
      img.src = pgDatenUri();
    });
  }
})();
</script>

<?php endif; ?>

<?php if ($tab === 'meldungen'): ?>
<?php /* Der Weg, den die Melde-Funktion auf der öffentlichen Detailseite nimmt: Was dort
         gemeldet wird, landet HIER – nicht in einem Postfach. Offene stehen oben, Erledigtes
         bleibt mit Notiz stehen, damit später nachvollziehbar ist, was entschieden wurde. */ ?>
<div class="card">
  <div class="section-title" style="margin-top:0"><i class="ti ti-flag"></i> Gemeldete Beiträge</div>
  <p class="small muted" style="margin-top:0">Auf jeder Beitragsseite steht unten ein leiser
     „Beitrag melden"-Aufklapper. Wer ihn benutzt, braucht kein Konto – die Mailadresse ist
     freiwillig und nur für Rückfragen da. <strong>Bitte zeitnah anschauen:</strong> Ihr zeigt
     hier fremde Inhalte öffentlich, und eine Meldung ist der einzige Weg, auf dem euch jemand
     auf ein Problem hinweisen kann.</p>

  <?php $melde = wl_reports(true); $offenN = 0; foreach ($melde as $mm) if ((string)$mm['done_at'] === '') $offenN++; ?>
  <?php if (!$melde): ?>
    <p class="empty"><i class="ti ti-mood-smile"></i> Es liegt nichts vor. </p>
  <?php else: ?>
    <p class="small muted"><?= $offenN ?> offen · <?= count($melde) - $offenN ?> erledigt</p>
    <?php foreach ($melde as $m): $offen = (string)$m['done_at'] === ''; ?>
      <div class="wl-adm-ent<?= $offen ? '' : ' still' ?>">
        <div class="wl-adm-ent-b">
          <strong><?= h(wl_report_gruende()[(string)$m['grund']] ?? (string)$m['grund']) ?></strong>
          <div class="small muted">
            zu „<?= h((string)($m['item_title'] ?? '—')) ?>"<?php if (!empty($m['org_name'])): ?>
              · <?= h((string)$m['org_name']) ?><?php endif; ?>
            · <?= h(date('j.n.Y, H:i', (int)strtotime((string)$m['created_at']))) ?>
            <?php if ((string)($m['item_status'] ?? '') !== 'live'): ?> · <em>Beitrag ist nicht mehr online</em><?php endif; ?>
          </div>
          <?php if (trim((string)$m['text']) !== ''): ?>
            <p class="small" style="margin:.35rem 0 0;white-space:pre-line"><?= h((string)$m['text']) ?></p>
          <?php endif; ?>
          <?php if (trim((string)$m['mail']) !== ''): ?>
            <div class="small muted">Rückfragen an <a href="mailto:<?= h((string)$m['mail']) ?>"><?= h((string)$m['mail']) ?></a></div>
          <?php endif; ?>
          <?php if (!$offen && trim((string)$m['notiz']) !== ''): ?>
            <div class="small muted"><i class="ti ti-check"></i> <?= h((string)$m['notiz']) ?>
              (<?= h(date('j.n.Y', (int)strtotime((string)$m['done_at']))) ?>)</div>
          <?php endif; ?>
        </div>
        <div class="wl-adm-ent-a">
          <?php if ((int)$m['item_id'] > 0): ?>
            <a class="btn secondary small" href="veranstaltungen/v.php?id=<?= (int)$m['item_id'] ?>" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> Ansehen</a>
          <?php endif; ?>
          <?php if ($offen): ?>
            <form method="post" class="wl-adm-reject">
              <?= csrf_field() ?><input type="hidden" name="action" value="melde_done">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <input type="text" name="notiz" maxlength="500" placeholder="Was habt ihr gemacht? (freiwillig)">
              <button class="btn small" type="submit"><i class="ti ti-check"></i> Erledigt</button>
            </form>
          <?php else: ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="melde_auf">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn secondary small" type="submit"><i class="ti ti-rotate"></i> Wieder öffnen</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'statistik'): ?>

<!-- ================= Besuche ================= -->
<?php
/* Besucherzählung: Die Zahlen kommen fertig aus stats_days (zwei Spalten je Tag, sonst nichts).
   Hier wird nur noch gerechnet und gezeichnet – keine Personendaten in Sicht, weil es keine gibt. */
$stWunsch = (int)($_GET['tage'] ?? 30);
$stTage   = in_array($stWunsch, [7, 30, 90, 365], true) ? $stWunsch : 30;
$stBis   = date('Y-m-d');
$stVon   = date('Y-m-d', strtotime('-' . ($stTage - 1) . ' days'));
$stDaten = wl_stats_range($stVon, $stBis);
$stStart = wl_stats_start();
$stGesamt = wl_stats_summe();
$stZeit   = wl_stats_summe($stVon, $stBis);
$stHeute  = $stDaten[$stBis] ?? ['visitors' => 0, 'views' => 0];
// Lückenlose Reihe: Tage ohne Besuch sind eine Aussage, kein fehlender Wert.
$stReihe = [];
for ($i = 0; $i < $stTage; $i++) {
    $d = date('Y-m-d', strtotime($stVon . ' +' . $i . ' days'));
    $stReihe[$d] = $stDaten[$d] ?? ['visitors' => 0, 'views' => 0];
}
$stMax = max(1, ...array_column($stReihe, 'visitors') ?: [1]);
$stSchnitt = $stTage > 0 ? $stZeit['visitors'] / $stTage : 0;
?>
<div class="section-title" id="statistik"><i class="ti ti-chart-bar"></i> Besuche</div>

<div class="wl-adm-stats">
  <div><strong><?= (int)$stHeute['visitors'] ?></strong><span>heute</span></div>
  <div><strong><?= (int)$stZeit['visitors'] ?></strong><span>in <?= $stTage ?> Tagen</span></div>
  <div><strong><?= number_format($stSchnitt, 1, ',', '.') ?></strong><span>pro Tag</span></div>
  <div><strong><?= (int)$stGesamt['visitors'] ?></strong><span>seit Beginn</span></div>
</div>

<div class="card">
  <div class="btn-row" style="margin:0 0 .8rem;flex-wrap:wrap">
    <?php foreach ([7 => '7 Tage', 30 => '30 Tage', 90 => '3 Monate', 365 => '1 Jahr'] as $tw => $lbl): ?>
      <a class="btn <?= $stTage === $tw ? '' : 'secondary ' ?>small" href="<?= h($self) ?>?t=statistik&amp;tage=<?= $tw ?>"><?= h($lbl) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($stGesamt['views'] === 0): ?>
    <p class="empty" style="margin:0"><i class="ti ti-chart-bar"></i> Noch nichts gezählt – die Zählung beginnt mit dem nächsten Besuch auf der öffentlichen Seite.</p>
  <?php else:
    /* Balken je Tag. Bewusst als SVG mit Koordinaten statt style-Breiten: dieselbe Zeichnung
       funktioniert auch dort, wo style-Attribute nicht durchkommen (öffentliche CSP), und
       skaliert am Handy mit. */
    $W = 720; $H = 190; $padL = 34; $padB = 26; $padT = 10;
    $plotW = $W - $padL - 8; $plotH = $H - $padT - $padB;
    $n = count($stReihe); $bw = $plotW / max(1, $n);
    $i = 0; $balken = ''; $marken = '';
    foreach ($stReihe as $tag => $z) {
        $hh = $stMax > 0 ? ($z['visitors'] / $stMax) * $plotH : 0;
        $x = $padL + $i * $bw;
        $balken .= '<rect class="wl-st-bar" x="' . round($x + $bw * 0.15, 1) . '" y="' . round($padT + $plotH - $hh, 1)
                 . '" width="' . round($bw * 0.7, 1) . '" height="' . round(max($hh, 1), 1) . '" rx="2">'
                 . '<title>' . h(fmt_date($tag)) . ': ' . (int)$z['visitors'] . ' Besuche, ' . (int)$z['views'] . ' Aufrufe</title></rect>';
        // Beschriftung nur an ein paar Stellen, sonst klebt die Achse zu
        if ($n <= 10 || $i === 0 || $i === $n - 1 || $i === intdiv($n, 2)) {
            $marken .= '<text class="wl-st-ax" x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">'
                     . h(date('d.m.', strtotime($tag))) . '</text>';
        }
        $i++;
    }
    $gitter = '';
    for ($g = 0; $g <= 2; $g++) {
        $gy = $padT + $g / 2 * $plotH;
        $gv = $stMax - $g / 2 * $stMax;
        $gitter .= '<line class="wl-st-grid" x1="' . $padL . '" y1="' . round($gy, 1) . '" x2="' . ($padL + $plotW) . '" y2="' . round($gy, 1) . '"/>'
                 . '<text class="wl-st-ax" x="' . ($padL - 6) . '" y="' . round($gy + 4, 1) . '" text-anchor="end">' . round($gv) . '</text>';
    }
  ?>
    <svg class="wl-st-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img"
         aria-label="Besuche je Tag im gewählten Zeitraum, Höchstwert <?= (int)$stMax ?>">
      <?= $gitter . $balken . $marken ?>
    </svg>
    <p class="small muted" style="margin:.4rem 0 0">
      Im Zeitraum: <strong><?= (int)$stZeit['visitors'] ?></strong> Besuche und
      <strong><?= (int)$stZeit['views'] ?></strong> Seitenaufrufe<?= $stStart !== '' ? ' · gezählt wird seit ' . h(fmt_date($stStart)) : '' ?>.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0;font-size:1.02rem"><i class="ti ti-shield-lock"></i> Was hier gezählt wird – und was nicht</div>
  <p class="small muted" style="margin-top:0"><strong>Ein Besuch</strong> heißt: ein Gerät war an einem Tag da.
    Dafür rechnet die Seite aus Adresse und Browser-Kennung eine Prüfzahl, zusammen mit einem <strong>Zufallswert,
    der jede Nacht ersetzt wird</strong> – danach lässt sich die Prüfzahl zu nichts mehr zurückrechnen, auch von uns nicht.
    Gespeichert bleiben am Ende <strong>zwei Zahlen je Tag</strong>.</p>
  <p class="small muted" style="margin:.4rem 0 0">Es gibt <strong>kein Cookie</strong>, keine gespeicherte IP-Adresse, keine
    Wiedererkennung über den Tag hinaus, keinen Verlauf, kein Gerät, keinen Standort und keinen fremden Dienst.
    Bots und Suchmaschinen zählen nicht mit – gefragt sind echte Besucher:innen.
    Wer an mehreren Tagen kommt, zählt an jedem Tag einmal; die Summe ist deshalb <em>Besuche</em>, nicht <em>Personen</em>.</p>
</div>

<?php endif; ?>

<?php if ($tab === 'einstellungen'): ?>

<!-- ================= Einstellungen ================= -->
<div class="section-title" id="einstellungen"><i class="ti ti-settings"></i> Einstellungen</div>
<div class="card">
  <?php /* EIN Formular, ZWEI Absende-Knöpfe (action steckt im jeweiligen Knopf), damit
           „Speichern" und „Basis-Adresse übernehmen" in einer Reihe stehen. */ ?>
  <form method="post">
    <?= csrf_field() ?>

    <label>Betriebszustand</label>
    <?php /* Karten statt Aufklapp-Menü (derselbe Baustein wie die Frage-Art bei Umfragen): Es sind
             genau drei Möglichkeiten, und die Folgen jeder einzelnen muss man lesen können, ohne
             sie erst aufzuklappen. Die Symbole tragen dabei die Aussage – offen, angehalten, zu. */ ?>
    <div class="wahlkarten" role="radiogroup" aria-label="Betriebszustand">
      <?php /* Jede Karte nennt BEIDE Folgen: was Besuchende sehen und was Gruppen können. Nur so
               steht der Unterschied zwischen den mittleren und den äußeren Zuständen da – ein
               Stichwort wie „keine neuen" lässt offen, was denn nun zu ist. */ ?>
      <?php $zustaende = [
          'on'   => ['ti-player-play',  'Läuft',         'Seite offen, Gruppen können eintragen'],
          'soft' => ['ti-player-pause', 'Aufnahmestopp', 'Seite bleibt sichtbar, aber niemand kann Neues einreichen'],
          'off'  => ['ti-lock',         'Geschlossen',   'Die Seite ist für alle zu, auch für Besuchende'],
          'pre'  => ['ti-rocket',       'Vorabstart',    'Draußen steht „bald geht es los", Gruppen tragen schon ein'],
      ]; ?>
      <?php foreach ($zustaende as $zk => [$zIc, $zName, $zKurz]): ?>
        <label class="wahlkarte">
          <input type="radio" name="mode" value="<?= h($zk) ?>"<?= wl_mode() === $zk ? ' checked' : '' ?>>
          <i class="ti <?= h($zIc) ?>"></i><strong><?= h($zName) ?></strong><span><?= h($zKurz) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <?php /* Der Starttermin gehört zum Vorabstart und steht deshalb direkt darunter. Er bleibt
             auch in den anderen Zuständen sichtbar: Wer den Start plant, trägt das Datum ein,
             BEVOR er umschaltet – ein Feld, das erst nach dem Umschalten auftaucht, zwingt zu
             einer Seite mit Datum „irgendwann". Leer ist erlaubt: dann ohne Datum. */ ?>
    <div class="field-row" style="margin-top:.7rem">
      <div style="flex:0 1 260px">
        <label for="launch_at">Starttermin (für den Vorabstart)</label>
        <input type="text" class="fp-date" name="launch_at" id="launch_at" placeholder="Datum wählen"
               value="<?= h(wl_launch_at()) ?>">
      </div>
      <div style="flex:0 1 180px">
        <label for="launch_time">Uhrzeit (optional)</label>
        <input type="text" class="fp-time" name="launch_time" id="launch_time" placeholder="Uhrzeit wählen"
               value="<?= h(wl_launch_time()) ?>">
      </div>
    </div>
    <p class="small muted" style="margin:.25rem 0 0">Stehen auf der Vorab-Seite, dazu der Countdown.
      Ohne Datum bleibt es beim Satz allein. Mit Uhrzeit zählt die Seite am letzten Tag Stunden und
      zuletzt Minuten herunter statt „heute" zu sagen.</p>

    <?php /* Demo-Modus: getrennt vom Betriebszustand, weil er etwas anderes regelt – der Zustand
             sagt, was GEHT, der Demo-Modus nur, was DASTEHT. Beides ist kombinierbar. */ ?>
    <label class="wl-sw" style="margin-top:.6rem">
      <input type="checkbox" name="demo" value="1"<?= wl_demo() ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Demo-Modus</strong>
        <em>Auf der Startseite und über jeder Veranstaltung steht dann, dass die Seite noch im
          Aufbau und noch nicht öffentlich ist. Sonst ändert sich nichts: Alles bleibt bedienbar,
          und Einträge, Anmeldungen und Mails laufen normal weiter.</em></span>
    </label>
    <?php /* Eigener Wortlaut direkt am Schalter statt im Texte-Register: Der Satz gehört zu
             dieser einen Entscheidung und wird mit ihr zusammen gelesen. Leer heißt hier
             ausdrücklich NICHT „nichts anzeigen" – sonst wäre der Demo-Modus stumm, sobald
             jemand das Feld leert – sondern „nimm den Standardsatz". */ ?>
    <label for="demo_text" style="margin-top:.6rem">Wortlaut des Hinweises</label>
    <textarea name="demo_text" id="demo_text" rows="2"
              placeholder="<?= h(wl_t('demo_text')) ?>"><?= h(wl_setting('demo_text', '')) ?></textarea>
    <p class="small muted" style="margin:.25rem 0 0">Leer lassen für den Standardsatz oben.
      Vorangestellt wird immer ein fettes <strong>Demo-Ansicht:</strong> (englisch
      <strong>Preview:</strong>). Ein eigener Satz gilt in beiden Sprachen – wie alles,
      was ihr selbst schreibt.</p>

    <div class="field-row" style="margin-top:.8rem">
      <div><label for="base_url">Basis-Adresse</label>
        <input type="text" inputmode="url" name="base_url" id="base_url" value="<?= h(wl_setting('base_url', '')) ?>" placeholder="beispiel.de/planer">
        <p class="small muted" style="margin:.25rem 0 0">Ohne sie gibt es keine Zugangs-Mails und keine
          Linkvorschau. „Aus der App übernehmen" unten füllt sie automatisch.</p></div>
      <div><label for="kontakt_mail">Kontakt-Adresse</label>
        <input type="email" name="kontakt_mail" id="kontakt_mail" value="<?= h(wl_setting('kontakt_mail', '')) ?>" placeholder="<?= h('was.laeuft@' . org_domain()) ?>">
        <p class="small muted" style="margin:.25rem 0 0">Steht im Seitenfuß und dort, wo Gruppen nach
          einem Zugang fragen.</p></div>
    </div>

    <div class="field-row">
      <div><label for="imprint_url">Impressum</label>
        <input type="text" inputmode="url" name="imprint_url" id="imprint_url" value="<?= h(wl_setting('imprint_url', '')) ?>" placeholder="beispiel.de/impressum"></div>
      <div><label for="privacy_url">Datenschutzerklärung</label>
        <input type="text" inputmode="url" name="privacy_url" id="privacy_url" value="<?= h(wl_setting('privacy_url', '')) ?>" placeholder="beispiel.de/datenschutz"></div>
      <div style="flex:0 1 200px"><label for="keep_days">Aufbewahrung (Tage)</label>
        <input type="number" name="keep_days" id="keep_days" min="7" max="365" value="<?= (int)wl_setting('keep_days', (string)WL_KEEP_DAYS) ?>">
        <p class="small muted" style="margin:.25rem 0 0">So lange bleiben vorbei Veranstaltungen
          gespeichert, danach löscht der Cron sie samt Bildern.</p></div>
    </div>

    <?php /* Mails an die Veranstalter: dasselbe Bedien-Muster wie bei Umfragen,
             externen Events und Terminplaner – Absender-Adresse, Absender-Name, Tagesdeckel,
             Schalter, Testmail. BESONDERHEIT: Anders als in den anderen Bereichen darf
             hier die eigene was.läuft-Adresse der ABSENDER sein, nicht nur die Antwort-Adresse.
             Die Zugangs-/Passwort-Mails hängen nicht am Schalter, ohne die käme niemand rein. */ ?>
    <?php /* Englische Kurzfassung: bewusst ein SCHALTER und keine Selbstverständlichkeit –
             ob die Gruppen das pflegen und ob ihr halbe Zweisprachigkeit wollt, entscheidet
             ihr. Aus bedeutet: Die Felder tauchen im Einreich-Formular gar nicht erst auf,
             und schon Eingetragenes bleibt unsichtbar (es wird auch nicht mitgespeichert). */ ?>
    <label style="margin-top:.8rem">Sprache</label>
    <label class="wl-sw" style="margin-top:.35rem">
      <input type="checkbox" name="en_felder" value="1"<?= wl_setting('en_felder', '0') === '1' ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Englische Kurzfassung zulassen</strong>
        <em>Gruppen können dann freiwillig einen englischen Titel und eine englische Zeile
          eintragen. Wer die Seite auf Englisch aufruft, sieht diese zuerst; alles andere
          bleibt auf Deutsch. Ohne Eintrag ändert sich nichts.</em></span>
    </label>

    <label style="margin-top:.8rem">Mails an die Veranstalter</label>
    <label class="wl-sw" style="margin-top:.35rem">
      <input type="checkbox" name="bescheid_mails" value="1"<?= wl_setting('bescheid_mails', '1') === '1' ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Bescheid-Mails verschicken</strong>
        <em>Bei Freigabe, Ablehnung (mit Begründung) und übernommenen oder verworfenen
          Änderungen geht automatisch eine Mail an die Gruppe. Zugangs- und Passwort-Mails
          gehen immer – die hängen nicht an diesem Schalter.</em></span>
    </label>
    <div class="field-row" style="margin-top:.6rem">
      <div><label for="from_email">Absender-Adresse</label>
        <input type="email" name="from_email" id="from_email" value="<?= h(wl_setting('from_email', '')) ?>" placeholder="<?= h('was.laeuft@' . org_domain()) ?>">
        <p class="small muted" style="margin:.25rem 0 0">Von hier gehen die Veranstalter-Mails
          raus – die was.läuft-Adresse ist dafür freigegeben. Leer = das gemeinsame Postfach
          <strong><?= h(mail_pool_absender()) ?></strong>. Antworten gehen an die
          Kontakt-Adresse oben.</p></div>
      <div><label for="from_name">Absender-Name</label>
        <input type="text" name="from_name" id="from_name" value="<?= h(wl_setting('from_name', 'was.läuft')) ?>"></div>
      <div style="flex:0 1 200px"><label for="cap_day">Tagesdeckel (Mails)</label>
        <input type="number" name="cap_day" id="cap_day" min="10" max="3000" value="<?= wl_mail_cap() ?>">
        <p class="small muted" style="margin:.25rem 0 0">Anteil von was.läuft am gemeinsamen
          Mail-Topf. Heute noch <strong><?= (int)mail_pool_budget('waslaeuft', wl_mail_cap()) ?></strong> übrig.</p></div>
    </div>

    <label style="margin-top:.8rem">Wer betreut das Portal?</label>
    <p class="small muted" style="margin:.2rem 0 .45rem">Vorsitz und Admin immer. Zusätzlich:</p>
    <?php /* Der Unterschied zwischen Zugriff und Zuständigkeit ist hier der Punkt: Vorsitz und
             Admin kommen immer rein, bekommen aber KEINE Mitteilung, solange sie nicht selbst
             eingetragen sind. Genau so gewollt. */ ?>
    <?= zust_picker_html('waslaeuft') ?>
    <p class="small" style="margin:.5rem 0 .45rem"><i class="ti ti-bell"></i>
      <strong>Mitteilungen gehen nur an die hier Eingetragenen</strong> – nicht an alle, die
      Zugriff haben. Sobald eine Einreichung wartet, eine Änderung an einem veröffentlichten
      Beitrag zur Freigabe liegt oder ein Beitrag gemeldet wurde, bekommen sie eine Nachricht
      aufs Dashboard und, wenn sie Push eingeschaltet haben, aufs Gerät (gebündelt, höchstens
      alle 15 Minuten eine). Ein-/ausschalten lässt sich das je Person unter
      <?= app_place('erinnerungen') ?> („was.läuft: Neues zu tun").
      <?php $zN = count(wl_zustaendige_ids()); ?>
      <br><em>Gerade erreicht das <?= $zN === 0 ? 'niemanden' : ($zN === 1 ? '1 Mitglied' : $zN . ' Mitglieder') ?>
        (<?= h(zust_text('waslaeuft')) ?>).</em></p>

    <div class="btn-row" style="margin-top:1rem">
      <button class="btn" type="submit" name="action" value="settings"><i class="ti ti-device-floppy"></i> Speichern</button>
      <button class="btn secondary" type="submit" name="action" value="settings_from_app"><i class="ti ti-download"></i> Basis-Adresse &amp; Absender aus der App übernehmen</button>
    </div>
  </form>
  <?php /* Eigenes Mini-Formular NEBEN dem großen: Der Test soll nichts speichern – und er
           läuft bewusst am Bescheid-Schalter vorbei, damit er auch bei „aus" beweist, ob
           der Versand technisch funktioniert. */ ?>
  <form method="post" style="margin-top:.6rem"><?= csrf_field() ?>
    <input type="hidden" name="action" value="test_mail">
    <button class="btn secondary small" type="submit"><i class="ti ti-mail-fast"></i> Testmail an mich</button>
  </form>
</div>

<?php endif; ?>

<?php page_footer(); ?>
