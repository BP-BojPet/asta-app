<?php
require __DIR__ . '/lib.php';
db();
require_admin(); // Verwaltung: nur Vorsitz & Admin (Set-and-forget-Konfiguration; Sekki-Aufgaben stehen in der Sekki-Kachel)

/**
 * REITER (wie in der was.läuft-Verwaltung): Abläufe und die drei Anbindungen liegen in
 * eigenen Reitern statt auf einer langen Rolle – man kommt fast immer wegen genau einer
 * davon. Die Abschnitte selbst sind NICHT verschoben, nur in ihren Reiter gehüllt
 * (Umbau-Regel).
 */
$tabs = [
    'auto'       => ['label' => 'Automationen', 'icon' => 'ti-robot'],
    'sharepoint' => ['label' => 'SharePoint',   'icon' => 'ti-brand-office'],
    'olat'       => ['label' => 'OLAT',         'icon' => 'ti-school'],
    'nextcloud'  => ['label' => 'Nextcloud',    'icon' => 'ti-cloud'],
];
$tab = (string)($_GET['t'] ?? '');
// Alter Link mit ?tech=1 landet auf der ersten Anbindung statt im Nichts.
if ($tab === '' && isset($_GET['tech'])) $tab = 'sharepoint';
if (!isset($tabs[$tab])) $tab = 'auto';

$testResult = null;
$probedDrives = null;
$olatTest = null;
$olatList = null;
$ncTest = null;      // Ergebnis von „Verbindung testen" (Nextcloud)
$ncList = null;      // Ordnerinhalt für den Basisordner-Browser
$ncCapsErr = null;   // Fehler beim Abfragen der Fähigkeiten (Office-Erkennung)
$autoRunResult = null;
$cronRunResult = null; // Ergebnis von „Cron jetzt ausführen" (beide Automations-Teile)
$browseSlot = ''; $browseEntries = null; $browsePath = ''; $browseErr = null; // generischer Ordner-Browser (je „Slot")

// Ordner-Slots für den gemeinsamen Browser: Slot → Einstellungs-Schlüssel + Sprung-Anker.
$FOLDER_SLOTS = [
    'report'          => ['key' => 'auto_reportdoc_folder',      'anchor' => '#auto-reportdoc'],
    'protocol_teams'  => ['key' => 'protocol_teams_folder',       'anchor' => '#protokoll-workflow'], // öffentliches Protokoll
    'protocol_intern' => ['key' => 'protocol_teams_intern_folder','anchor' => '#protokoll-workflow'], // internes Protokoll
    'protocol_olat'   => ['key' => 'protocol_olat_folder',        'anchor' => '#protokoll-workflow'], // veröffentlichtes PDF (nur öffentlich)
    'expense'         => ['key' => 'nc_expense_folder',           'anchor' => '#belege'],              // Belegblatt-Archiv in der Nextcloud
];
// Backend eines Slots: fest (Protokoll) oder abhängig von der gewählten Ziel-Ablage (Berichte-Dok).
$folder_backend = function (string $slot): string {
    if ($slot === 'protocol_olat') return 'olat';
    if ($slot === 'expense') return 'nextcloud';
    if ($slot === 'report') {
        $t = (string)setting_get('auto_reportdoc_target', 'sharepoint');
        if ($t === 'olat' || $t === 'nextcloud') return $t;
    }
    return 'teams';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $tenant = trim((string)($_POST['tenant_id'] ?? ''));
        $client = trim((string)($_POST['client_id'] ?? ''));
        $secret = (string)($_POST['client_secret'] ?? '');
        $host   = trim((string)($_POST['site_hostname'] ?? ''));
        $path   = trim((string)($_POST['site_path'] ?? ''));
        $secExp = trim((string)($_POST['secret_expires'] ?? ''));

        $oldTenant = (string)setting_get('graph_tenant_id', '');
        $oldClient = (string)setting_get('graph_client_id', '');
        $oldHost   = (string)setting_get('graph_site_hostname', '');
        $oldPath   = (string)setting_get('graph_site_path', '');

        setting_set('graph_tenant_id', $tenant);
        setting_set('graph_client_id', $client);
        setting_set('graph_site_hostname', $host);
        setting_set('graph_site_path', $path);
        // Secret nur überschreiben, wenn ein neuer Wert eingegeben wurde
        if ($secret !== '') setting_set('graph_client_secret', $secret);
        // Ablaufdatum (für die Warnung) – normalisieren auf YYYY-MM-DD
        if ($secExp !== '') {
            $ts = strtotime($secExp);
            setting_set('graph_secret_expires', $ts ? date('Y-m-d', $ts) : $secExp);
        } else {
            setting_set('graph_secret_expires', '');
        }

        // Caches verwerfen, wenn sich relevante Werte geändert haben
        if ($tenant !== $oldTenant || $client !== $oldClient || $secret !== '') {
            setting_set('graph_token_cache', '');
        }
        if ($host !== $oldHost || $path !== $oldPath) {
            setting_set('graph_site_id', '');
            setting_set('graph_drive_id', '');
            setting_set('graph_drive_name', '');
        }
        flash('Konfiguration gespeichert.', 'success');
        redirect('uploads.php?t=sharepoint#config');
    }

    if ($action === 'test') {
        $testResult = graph_connection_test();
        $probedDrives = $testResult['drives'];
        // weiter unten rendern (kein Redirect, damit das Ergebnis sichtbar bleibt)
    }

    if ($action === 'set_drive') {
        $drives = graph_site_drives($err);
        $pick = (string)($_POST['drive_id'] ?? '');
        $name = '';
        foreach (($drives ?? []) as $d) if ($d['id'] === $pick) $name = $d['name'];
        if ($pick !== '' && $name !== '') {
            setting_set('graph_drive_id', $pick);
            setting_set('graph_drive_name', $name);
            flash('Dokumentbibliothek „' . $name . '" ausgewählt.', 'success');
        } else {
            flash('Bibliothek konnte nicht gesetzt werden' . ($err ? ': ' . $err : '.'), 'error');
        }
        redirect('uploads.php?t=sharepoint#library');
    }

    if ($action === 'upload_test') {
        $driveId = (string)setting_get('graph_drive_id', '');
        $folder  = trim((string)($_POST['folder'] ?? ''));
        if ($driveId === '') {
            flash('Erst eine Dokumentbibliothek auswählen.', 'error');
            redirect('uploads.php?t=sharepoint#upload');
        }
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Keine Datei empfangen (oder zu groß).', 'error');
            redirect('uploads.php?t=sharepoint#upload');
        }
        $tmp  = $_FILES['file']['tmp_name'];
        $name = (string)($_FILES['file']['name'] ?? 'datei');
        $name = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $name); // Dateiname säubern
        $err = null;
        $item = graph_upload_file($driveId, $folder, $name, $tmp, $err);
        if ($item) {
            flash('Hochgeladen: „' . ($item['name'] ?? $name) . '" in eure SharePoint-Ablage.', 'success');
        } else {
            flash('Upload fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
        }
        redirect('uploads.php?t=sharepoint#upload');
    }

    if ($action === 'save_olat') {
        $url    = trim((string)($_POST['olat_url'] ?? ''));
        $ouser  = trim((string)($_POST['olat_user'] ?? ''));
        $opass  = (string)($_POST['olat_password'] ?? '');
        $obase  = trim((string)($_POST['olat_base_folder'] ?? ''));
        setting_set('olat_webdav_url', $url);
        setting_set('olat_user', $ouser);
        setting_set('olat_base_folder', trim(str_replace('\\', '/', $obase), '/'));
        // Passwort nur überschreiben, wenn neu eingegeben
        if ($opass !== '') setting_set('olat_password', $opass);
        flash('OLAT-Konfiguration gespeichert.', 'success');
        redirect('uploads.php?t=olat#olat-config');
    }

    if ($action === 'test_olat') {
        $olatTest = olat_test_connection();
        if ($olatTest['ok']) { $e = null; $olatList = olat_list_folder((string)setting_get('olat_base_folder', ''), $e); }
        // ohne Redirect rendern
    }

    if ($action === 'olat_cd') {
        // In einen Ordner wechseln bzw. eine Ebene hoch – neuer Basisordner wird gesetzt
        $to = trim(str_replace('\\', '/', (string)($_POST['to'] ?? '')), '/');
        setting_set('olat_base_folder', $to);
        $olatTest = olat_test_connection();
        if ($olatTest['ok']) { $e = null; $olatList = olat_list_folder($to, $e); }
        // ohne Redirect rendern
    }

    if ($action === 'upload_olat_test') {
        if (!olat_configured()) {
            flash('OLAT ist noch nicht vollständig konfiguriert.', 'error');
            redirect('uploads.php?t=olat#olat-upload');
        }
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Keine Datei empfangen (oder zu groß).', 'error');
            redirect('uploads.php?t=olat#olat-upload');
        }
        $base   = trim((string)setting_get('olat_base_folder', ''), '/');
        $sub    = trim((string)($_POST['folder'] ?? ''));
        $folder = trim(($base !== '' ? $base . '/' : '') . $sub, '/');
        $tmp    = $_FILES['file']['tmp_name'];
        $name   = (string)($_FILES['file']['name'] ?? 'datei');
        $name   = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $name);
        $err = null;
        if (olat_upload_file($folder, $name, $tmp, $err)) {
            flash('Hochgeladen: „' . $name . '" nach OLAT.', 'success');
        } else {
            flash('OLAT-Upload fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
        }
        redirect('uploads.php?t=olat#olat-upload');
    }

    if ($action === 'save_nc') {
        $ncUrlIn = nc_normalize_base_url((string)($_POST['nc_url'] ?? ''));
        $nuser   = trim((string)($_POST['nc_user'] ?? ''));
        $npass   = (string)($_POST['nc_password'] ?? '');
        $nbase   = trim((string)($_POST['nc_open_folder'] ?? ''));
        $before  = nc_base_url() . '|' . (string)setting_get('nc_user', '');
        setting_set('nc_base_url', $ncUrlIn);
        setting_set('nc_user', $nuser);
        setting_set('nc_open_folder', trim(str_replace('\\', '/', $nbase), '/'));
        // Passwort nur überschreiben, wenn neu eingegeben
        if ($npass !== '') setting_set('nc_password', $npass);
        // Andere Cloud oder anderes Konto → gemerkte Fähigkeiten sind wertlos
        if ($before !== $ncUrlIn . '|' . $nuser) setting_set('nc_caps_cache', '');
        flash('Nextcloud-Konfiguration gespeichert.', 'success');
        redirect('uploads.php?t=nextcloud#nc-config');
    }

    if ($action === 'test_nc') {
        $ncTest = nc_test_connection();
        if ($ncTest['ok']) {
            $e = null;
            $ncList = dav_list_folder('nextcloud', nc_base_folder(), $e);
            nc_capabilities(true, $ncCapsErr); // Office-Server frisch erkennen
        }
        // ohne Redirect rendern
    }

    if ($action === 'nc_cd') {
        // In einen Ordner wechseln bzw. eine Ebene hoch – die aktuelle Ebene IST der Basisordner
        $to = trim(str_replace('\\', '/', (string)($_POST['to'] ?? '')), '/');
        setting_set('nc_open_folder', $to);
        $ncTest = nc_test_connection();
        if ($ncTest['ok']) { $e = null; $ncList = dav_list_folder('nextcloud', $to, $e); }
        // ohne Redirect rendern
    }

    if ($action === 'upload_nc_test') {
        if (!nc_configured()) {
            flash('Nextcloud ist noch nicht vollständig eingerichtet.', 'error');
            redirect('uploads.php?t=nextcloud#nc-upload');
        }
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Keine Datei empfangen (oder zu groß).', 'error');
            redirect('uploads.php?t=nextcloud#nc-upload');
        }
        $base   = nc_base_folder();
        $sub    = trim(str_replace('\\', '/', (string)($_POST['folder'] ?? '')), '/');
        $folder = trim(($base !== '' ? $base . '/' : '') . $sub, '/');
        $name   = preg_replace('/[\\\\\/:*?"<>|]+/', '_', (string)($_FILES['file']['name'] ?? 'datei'));
        $bytes  = file_get_contents($_FILES['file']['tmp_name']);
        $err = null;
        if ($bytes !== false && dav_put('nextcloud', trim($folder . '/' . $name, '/'), $bytes, $err)) {
            flash('Hochgeladen: „' . $name . '" in die Nextcloud.', 'success');
        } else {
            flash('Nextcloud-Upload fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
        }
        redirect('uploads.php?t=nextcloud#nc-upload');
    }

    if ($action === 'save_mirror_backend') {
        $want = ($_POST['backend'] ?? '') === 'nextcloud' ? 'nextcloud' : 'teams';
        setting_set('mirror_backend', $want);
        // mirror_backend() kann die Wahl überstimmen, wenn die Ablage nicht eingerichtet ist –
        // dann soll die Meldung sagen, was TATSÄCHLICH gilt, nicht was angeklickt wurde.
        $real = mirror_backend();
        flash($real === $want
            ? 'Neue Doks gehen ab jetzt nach ' . mirror_label($real) . '.'
            : mirror_label($want) . ' ist nicht eingerichtet – es bleibt bei ' . mirror_label($real) . '.',
            $real === $want ? 'success' : 'error');
        redirect('uploads.php#ablage');
    }

    if ($action === 'save_expense_nc') {
        setting_set('nc_expense_enabled', ($_POST['enabled'] ?? '') === '1' ? '1' : '0');
        flash('Belegblatt-Archiv gespeichert.', 'success');
        redirect('uploads.php#belege');
    }

    if ($action === 'save_auto_reportdoc') {
        setting_set('auto_reportdoc_enabled', ($_POST['enabled'] ?? '') === '1' ? '1' : '0');
        $arT = trim((string)($_POST['trigger_time'] ?? '18:00'));
        setting_set('auto_reportdoc_time', preg_match('/^\d{1,2}:\d{2}$/', $arT) ? $arT : '18:00');
        $arTargetIn = (string)($_POST['target'] ?? '');
        setting_set('auto_reportdoc_target', in_array($arTargetIn, ['olat', 'nextcloud'], true) ? $arTargetIn : 'sharepoint');
        setting_set('auto_reportdoc_filename', trim((string)($_POST['filename'] ?? '')) ?: 'Berichte_{sitzung}_{datum}');
        setting_set('auto_reportdoc_require_reports', ($_POST['require_reports'] ?? '') === '1' ? '1' : '0');
        setting_set('auto_reportdoc_year_subfolder', ($_POST['year_subfolder'] ?? '') === '1' ? '1' : '0');
        flash('Automation gespeichert.', 'success');
        redirect('uploads.php#auto-reportdoc');
    }

    if ($action === 'save_protocol') { // Jahres-Unterteilung an/aus
        setting_set('protocol_year_subfolder', ($_POST['year_subfolder'] ?? '') === '1' ? '1' : '0');
        flash('Protokoll-Ablage gespeichert.', 'success');
        redirect('uploads.php#protokoll-workflow');
    }
    if ($action === 'folder_set') { // gewählten Ordner aus dem Browser übernehmen (generisch, je Slot)
        $slot = (string)($_POST['slot'] ?? '');
        if (isset($FOLDER_SLOTS[$slot])) {
            setting_set($FOLDER_SLOTS[$slot]['key'], trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/'));
            flash('Ordner gesetzt.', 'success');
            redirect('uploads.php' . $FOLDER_SLOTS[$slot]['anchor']);
        }
        redirect('uploads.php');
    }
    if ($action === 'folder_ls') { // Ordner durchsuchen (inline gerendert, kein Redirect)
        $slot = (string)($_POST['slot'] ?? '');
        if (isset($FOLDER_SLOTS[$slot])) {
            $browseSlot = $slot;
            $browsePath = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
            $be = $folder_backend($slot);
            if ($be === 'olat' || $be === 'nextcloud') {
                // Beide sprechen WebDAV – nur die Wurzel unterscheidet sich.
                $davBase = $be === 'olat' ? trim((string)setting_get('olat_base_folder', ''), '/') : nc_base_folder();
                $davFull = trim(($davBase !== '' ? $davBase . '/' : '') . $browsePath, '/');
                $davList = dav_list_folder($be, $davFull, $browseErr);
                // Nur Ordnernamen (Strings) – der Picker erwartet eine flache Namensliste wie bei Teams.
                $browseEntries = $davList === null ? null : array_values(array_map(fn($e) => (string)$e['name'], array_filter($davList, fn($e) => !empty($e['dir']))));
            } else {
                $browseEntries = graph_list_folders((string)setting_get('graph_drive_id', ''), $browsePath, $browseErr);
            }
        }
    }

    if ($action === 'run_auto_reportdoc') {
        $mid = (int)($_POST['meeting_id'] ?? 0);
        $autoRunResult = run_report_doc_automation(['force' => true, 'meeting_id' => $mid ?: null]);
        // inline rendern, damit das Protokoll sichtbar bleibt
    }

    if ($action === 'run_cron_now') {
        // Führt exakt aus, was der geplante Cron tut – beide Teile, ohne Force (echter Probelauf).
        $cronRunResult = [
            'report'   => run_report_doc_automation(),
            'attached' => protocol_sync_pending_votes(),
        ];
    }
}

$tenant   = (string)setting_get('graph_tenant_id', '');
$client   = (string)setting_get('graph_client_id', '');
$hasSecret = trim((string)setting_get('graph_client_secret', '')) !== '';
$host     = (string)setting_get('graph_site_hostname', '');
$path     = (string)setting_get('graph_site_path', '');
$driveId  = (string)setting_get('graph_drive_id', '');
$driveName = (string)setting_get('graph_drive_name', '');
$secExpVal = (string)setting_get('graph_secret_expires', '');
$secDays   = graph_secret_days_left();
$configured = graph_configured();

$olatUrl   = (string)setting_get('olat_webdav_url', '');
$olatUser  = (string)setting_get('olat_user', '');
$olatHasPw = trim((string)setting_get('olat_password', '')) !== '';
$olatBase  = (string)setting_get('olat_base_folder', '');
$olatReady = olat_configured();

$ncUrl      = nc_base_url();
$ncUser     = (string)setting_get('nc_user', '');
$ncHasPw    = trim((string)setting_get('nc_password', '')) !== '';
$ncFolder   = nc_base_folder();
$ncReady    = nc_configured();
$ncEditor   = $ncReady ? nc_editor_label() : '';
$mirrorNow  = mirror_backend();                                   // gilt TATSÄCHLICH für neue Doks
$mirrorWish = (string)setting_get('mirror_backend', '');           // angeklickt (kann überstimmt sein)

// Technik-Bereich offen halten, sobald eine Einrichtungs-Aktion lief (Test/Browse/Speichern)
// ($techOpen ist mit den Reitern entfallen – wohin ein Test führt, sagt jetzt sein Redirect.)

// --- Automation „Berichte-Dok" ---
$arEnabled = auto_reportdoc_enabled();
$arTime    = (string)setting_get('auto_reportdoc_time', '18:00');
$arTarget  = setting_get('auto_reportdoc_target', 'sharepoint') === 'olat' ? 'olat' : 'sharepoint';
$arFolder  = (string)setting_get('auto_reportdoc_folder', '');
$arPattern = (string)setting_get('auto_reportdoc_filename', 'Berichte_{sitzung}_{datum}');
$arRequire = setting_get('auto_reportdoc_require_reports', '1') === '1';
$arYearSub = report_year_subfolder();
$arLast    = automation_last('reportdoc');
$reportMeetings = db()->query("SELECT * FROM meetings WHERE needs_report=1 AND draft=0 AND cancelled=0 ORDER BY starts_at DESC LIMIT 30")->fetchAll();

// --- Protokoll-Workflow: Ablage-Ordner ---
$protoTeamsFolder  = (string)setting_get('protocol_teams_folder', '');
$protoInternFolder = (string)setting_get('protocol_teams_intern_folder', '');
$protoOlatFolder   = (string)setting_get('protocol_olat_folder', '');
$protoYearSub     = protocol_year_subfolder();
$protoDriveSet    = trim((string)setting_get('graph_drive_id', '')) !== '';

// Cron-URL (täglicher Lauf, z. B. 18:01) – Key bei Bedarf erzeugen
if (setting_get('cron_key') === null) setting_set('cron_key', bin2hex(random_bytes(12)));
$baseGuess = ((($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/'));
$autoCronUrl = (base_url() ?: $baseGuess) . '/cron_automations.php?key=' . rawurlencode((string)setting_get('cron_key', ''));

page_header('Uploads und Automationen', true);
?>
<p class="small"><a href="admin/index.php">‹ Verwaltung</a></p>
<div class="events-toolbar">
  <h1><i class="ti ti-cloud-upload" style="color:var(--petrol)"></i> Uploads und Automationen</h1>
</div>

<?= graph_secret_notice_html() ?>

<nav class="wl-adm-tabs" aria-label="Bereiche">
  <?php foreach ($tabs as $tk => $td): ?>
    <a<?= $tk === $tab ? ' class="on" aria-current="page"' : '' ?> href="uploads.php?t=<?= h($tk) ?>">
      <i class="ti <?= h($td['icon']) ?>"></i> <?= h($td['label']) ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'auto'): ?>
<div class="section-title" id="automationen"><i class="ti ti-robot"></i> Automationen &amp; Abläufe</div>
<p class="help" style="margin-top:-.3rem">Was die App – teils automatisch, teils auf Knopfdruck – in eure <strong>Teams-/SharePoint-</strong> und <strong>OLAT-Ablage</strong> schiebt. Ziel-Ordner klickst du dir überall aus den vorhandenen Ordnern zusammen (kein Pfad-Tippen); fehlende Ordner werden bei Bedarf automatisch angelegt.</p>

<?php
// Gemeinsamer Ordner-Browser für alle Ziel-Ordner (Slot-basiert): zeigt entweder den „Ordner auswählen"-Knopf
// oder – wenn dieser Slot gerade durchsucht wird – den Browser (Ebene hoch · Unterordner · „Diesen Ordner verwenden").
$renderPicker = function (string $slot, string $current, string $rootLabel, bool $available, string $unavailHtml)
    use (&$browseSlot, $browseEntries, $browsePath, $browseErr) {
    if (!$available) { echo $unavailHtml; return; }
    if ($browseSlot === $slot && $browseEntries !== null):
        $cur = $browsePath;
        $parent = $cur !== '' ? trim(implode('/', array_slice(explode('/', $cur), 0, -1)), '/') : '';
        ?>
        <div class="olat-browser">
          <div class="olat-loc"><i class="ti ti-folder-open"></i> <strong><?= $cur !== '' ? h($cur) : h($rootLabel) ?></strong></div>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin:.5rem 0">
            <?php if ($cur !== ''): ?>
              <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="folder_ls"><input type="hidden" name="slot" value="<?= h($slot) ?>"><input type="hidden" name="path" value="<?= h($parent) ?>"><button class="btn small secondary" type="submit"><i class="ti ti-arrow-up"></i> Ebene hoch</button></form>
            <?php endif; ?>
            <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="folder_set"><input type="hidden" name="slot" value="<?= h($slot) ?>"><input type="hidden" name="path" value="<?= h($cur) ?>"><button class="btn small" type="submit"><i class="ti ti-check"></i> Diesen Ordner verwenden</button></form>
          </div>
          <?php if (!$browseEntries): ?>
            <p class="muted small" style="margin:0">Keine Unterordner hier.</p>
          <?php else: ?>
            <ul class="olat-entries">
              <?php foreach ($browseEntries as $nm): ?>
                <li><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="folder_ls"><input type="hidden" name="slot" value="<?= h($slot) ?>"><input type="hidden" name="path" value="<?= h(($cur !== '' ? $cur . '/' : '') . $nm) ?>"><button class="linklike" type="submit"><i class="ti ti-folder"></i> <?= h($nm) ?></button></form></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <?php
    else:
        if ($browseSlot === $slot && $browseErr) echo '<p class="up-test bad" style="margin:.2rem 0"><i class="ti ti-alert-triangle"></i> ' . h($browseErr) . '</p>';
        ?>
        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="folder_ls"><input type="hidden" name="slot" value="<?= h($slot) ?>"><input type="hidden" name="path" value="<?= h($current) ?>"><button class="btn secondary" type="submit"><i class="ti ti-folder-search"></i> Ordner auswählen</button></form>
        <?php
    endif;
};

// Berichte-Dok: Ziel-Ordner-Backend hängt von der gewählten Ablage ab.
$reportOlat    = $arTarget === 'olat';
$reportNc      = $arTarget === 'nextcloud';
$reportAvail   = $reportOlat ? $olatReady : ($reportNc ? $ncReady : ($driveId !== ''));
$reportRoot    = $reportOlat ? 'OLAT-Basisordner' : ($reportNc ? 'Nextcloud-Basisordner' : 'Wurzel der Bibliothek');
$reportIcon    = $reportOlat ? 'ti-school' : ($reportNc ? 'ti-brand-nextcloud' : 'ti-brand-office');
$reportStore   = $reportOlat ? 'OLAT' : ($reportNc ? 'Nextcloud' : 'Teams/SharePoint');
$reportUnavail = $reportOlat
    ? '<p class="help">Bitte zuerst unter <a href="uploads.php?t=olat#olat-config">Technik → OLAT</a> die Verbindung einrichten – danach lässt sich der Ordner anklicken.</p>'
    : ($reportNc
        ? '<p class="help">Bitte zuerst unter <a href="uploads.php?t=nextcloud#nc-config">Technik → Nextcloud</a> die Verbindung einrichten – danach lässt sich der Ordner anklicken.</p>'
        : '<p class="help">Bitte zuerst unter <a href="uploads.php?t=sharepoint#library">Technik → Dokumentbibliothek</a> eine Bibliothek auswählen – danach lässt sich der Ordner anklicken.</p>');
?>

<div class="card" id="auto-reportdoc">
  <div class="auto-head">
    <h2 style="margin:0"><i class="ti ti-file-text"></i> Berichte-Dok → Ablage</h2>
    <span class="auto-badge <?= $arEnabled ? 'on' : 'off' ?>"><?= $arEnabled ? 'Aktiv' : 'Inaktiv' ?></span>
  </div>
  <p class="help">Erzeugt <strong>am Sitzungstag ab <?= h($arTime) ?> Uhr</strong> automatisch die Berichte-Dok (.docx) einer berichtspflichtigen Sitzung und lädt sie in die gewählte Ablage. Pro Sitzung genau einmal; waren zur Auslösezeit noch keine Berichte da, holen die Läufe der <strong>nächsten 3 Tage</strong> es nach.</p>

  <?php if ($arLast): ?>
    <p class="up-test <?= $arLast['status'] !== 'error' ? 'ok' : 'bad' ?>">
      <i class="ti <?= $arLast['status'] !== 'error' ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i>
      Letzter Lauf <?= h(date('d.m.Y H:i', (int)strtotime((string)$arLast['created_at']))) ?>: <?= h((string)$arLast['detail']) ?>
    </p>
  <?php endif; ?>

  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_auto_reportdoc">
    <label class="wl-sw slim"><input type="checkbox" name="enabled" value="1"<?= $arEnabled ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Automation aktiviert</strong></span></label>
    <div class="field-row" style="margin-top:.5rem">
      <div>
        <label for="trigger_time">Erzeugen am Sitzungstag ab</label>
        <input type="time" name="trigger_time" id="trigger_time" value="<?= h($arTime) ?>">
      </div>
      <div>
        <label for="target">Ziel-Ablage</label>
        <select name="target" id="target">
          <option value="sharepoint"<?= $arTarget === 'sharepoint' ? ' selected' : '' ?>>Microsoft Teams / SharePoint</option>
          <option value="olat"<?= $arTarget === 'olat' ? ' selected' : '' ?>>OLAT</option>
          <option value="nextcloud"<?= $arTarget === 'nextcloud' ? ' selected' : '' ?>>Nextcloud<?= $ncReady ? '' : ' – nicht eingerichtet' ?></option>
        </select>
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="filename">Dateiname-Muster</label>
        <input type="text" name="filename" id="filename" value="<?= h($arPattern) ?>" placeholder="Berichte_{sitzung}_{datum}" autocomplete="off">
      </div>
      <div>
        <label class="wl-sw slim" style="margin-top:1.7rem"><input type="checkbox" name="require_reports" value="1"<?= $arRequire ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Nur, wenn mind. ein Bericht eingetragen ist</strong></span></label>
      </div>
    </div>
    <p class="help">Platzhalter: <code>{sitzung}</code>, <code>{datum}</code>, <code>{datumzeit}</code> · Endung <code>.docx</code> automatisch.</p>
    <label class="wl-sw slim"><input type="checkbox" name="year_subfolder" value="1"<?= $arYearSub ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Automatisch nach Jahr unterteilen</strong>
        <span>Ablage in <code>…/JAHR/</code> (Jahr aus dem Sitzungsdatum).</span></span></label>
    <div class="btn-row" style="margin-top:.6rem">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Automation speichern</button>
    </div>
  </form>

  <hr class="auto-sep">
  <h3 style="margin:.2rem 0 .3rem"><i class="ti <?= h($reportIcon) ?>"></i> Zielordner <span class="small muted" style="font-weight:400">· <?= h($reportStore) ?></span></h3>
  <p class="up-test<?= $arFolder !== '' ? ' ok' : '' ?>" style="margin:.2rem 0 .5rem">
    <i class="ti ti-folder"></i> Aktuell: <strong><?= $arFolder !== '' ? h($arFolder) : h($reportAvail || $reportOlat || $reportNc ? 'direkt im ' . $reportRoot : 'Wurzel der Bibliothek') ?></strong><?= $arYearSub ? ' <span class="muted">/ ' . date('Y') . ' (automatisch)</span>' : '' ?>
  </p>
  <?php $renderPicker('report', $arFolder, $reportRoot, $reportAvail, $reportUnavail); ?>

  <hr class="auto-sep">

  <div>
    <h3 style="margin:.2rem 0 .4rem"><i class="ti ti-player-play"></i> Jetzt testen</h3>
    <p class="help">Erzeugt das Dok sofort für die gewählte Sitzung und lädt es hoch – unabhängig vom Zeitfenster (zum Ausprobieren). Zählt <strong>nicht</strong> als erledigt: Der automatische Lauf lädt am Sitzungstag trotzdem hoch (gleicher Dateiname, wird überschrieben).</p>
    <?php if (!$reportMeetings): ?>
      <p class="muted">Keine berichtspflichtige Sitzung vorhanden.</p>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="run_auto_reportdoc">
        <div class="field-row" style="align-items:flex-end">
          <div style="flex:1">
            <label for="meeting_id">Sitzung</label>
            <select name="meeting_id" id="meeting_id">
              <?php foreach ($reportMeetings as $rm): ?>
                <option value="<?= (int)$rm['id'] ?>"><?= h(meeting_label($rm)) ?> · <?= h(fmt_date(substr((string)$rm['starts_at'], 0, 10))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn secondary" type="submit"><i class="ti ti-cloud-upload"></i> Erzeugen &amp; hochladen</button>
        </div>
      </form>
      <?php if ($autoRunResult !== null): ?>
        <div class="auto-runlog">
          <p class="up-test <?= $autoRunResult['count'] > 0 ? 'ok' : 'bad' ?>" style="margin:.6rem 0 .3rem">
            <i class="ti <?= $autoRunResult['count'] > 0 ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i> <?= (int)$autoRunResult['count'] ?> Datei(en) hochgeladen.
          </p>
          <ul class="auto-lines">
            <?php foreach ($autoRunResult['lines'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<div class="card" id="protokoll-workflow">
  <div class="auto-head">
    <h2 style="margin:0"><i class="ti ti-notebook"></i> Protokoll-Workflow → Teams &amp; OLAT</h2>
  </div>
  <p class="help">Steuert, wohin fertige Protokolle abgelegt werden. Die:der gewählte Protokollant:in lädt <strong>öffentliches</strong> und <strong>internes</strong> Protokoll (.docx) hoch – beide gehen direkt in <strong>Teams/SharePoint</strong> (gewählte Bibliothek unter <a href="uploads.php?t=sharepoint#library">Technik</a>), aber in <strong>getrennte Ordner</strong>. Das <strong>öffentliche</strong> wird als Abstimmungsgegenstand in die nächste Sitzung gehängt und nach der Annahme als <strong>PDF</strong> nach OLAT veröffentlicht; das <strong>interne</strong> bleibt ausschließlich in Teams (nie OLAT). Ordner werden bei Bedarf automatisch angelegt.</p>

  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_protocol">
    <label class="wl-sw slim"><input type="checkbox" name="year_subfolder" value="1"<?= $protoYearSub ? ' checked' : '' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Automatisch nach Jahr unterteilen</strong>
        <span>Ablage in <code>…/JAHR/</code> (z. B. <code>Protokolle/<?= date('Y') ?></code>).</span></span></label>
    <div class="btn-row" style="margin-top:.5rem"><button class="btn small" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button></div>
  </form>

  <hr class="auto-sep">
  <h3 style="margin:.2rem 0 .3rem"><i class="ti ti-world"></i> Teams-Ordner für <strong>öffentliche</strong> Protokolle</h3>
  <p class="up-test<?= $protoTeamsFolder !== '' ? ' ok' : '' ?>" style="margin:.2rem 0 .5rem">
    <i class="ti ti-folder"></i> Aktuell: <strong><?= $protoTeamsFolder !== '' ? h($protoTeamsFolder) : 'Wurzel der Bibliothek' ?></strong><?= $protoYearSub ? ' <span class="muted">/ ' . date('Y') . ' (automatisch)</span>' : '' ?>
  </p>
  <?php $renderPicker('protocol_teams', $protoTeamsFolder, 'Wurzel der Bibliothek', $protoDriveSet,
      '<p class="help">Bitte zuerst unter <a href="uploads.php?t=sharepoint#library">Technik → Dokumentbibliothek</a> eine Bibliothek auswählen – danach lässt sich der Ordner anklicken.</p>'); ?>

  <hr class="auto-sep">
  <h3 style="margin:.2rem 0 .3rem"><i class="ti ti-lock"></i> Teams-Ordner für <strong>interne</strong> Protokolle <span class="small muted" style="font-weight:400">· wird nie nach OLAT veröffentlicht</span></h3>
  <p class="up-test<?= $protoInternFolder !== '' ? ' ok' : '' ?>" style="margin:.2rem 0 .5rem">
    <i class="ti ti-folder"></i> Aktuell: <strong><?= $protoInternFolder !== '' ? h($protoInternFolder) : 'Wurzel der Bibliothek' ?></strong><?= $protoYearSub ? ' <span class="muted">/ ' . date('Y') . ' (automatisch)</span>' : '' ?>
  </p>
  <?php $renderPicker('protocol_intern', $protoInternFolder, 'Wurzel der Bibliothek', $protoDriveSet,
      '<p class="help">Bitte zuerst unter <a href="uploads.php?t=sharepoint#library">Technik → Dokumentbibliothek</a> eine Bibliothek auswählen – danach lässt sich der Ordner anklicken.</p>'); ?>

  <hr class="auto-sep">
  <h3 style="margin:.2rem 0 .3rem"><i class="ti ti-school"></i> OLAT-Ordner für veröffentlichte PDFs <span class="small muted" style="font-weight:400">· nur öffentliche Protokolle</span></h3>
  <p class="up-test<?= $protoOlatFolder !== '' ? ' ok' : '' ?>" style="margin:.2rem 0 .5rem">
    <i class="ti ti-folder"></i> Aktuell: <strong><?= $protoOlatFolder !== '' ? h($protoOlatFolder) : 'direkt im OLAT-Basisordner' ?></strong><?= $protoYearSub ? ' <span class="muted">/ ' . date('Y') . ' (automatisch)</span>' : '' ?>
    <span class="muted small">· relativ zum OLAT-Basisordner<?= trim((string)$olatBase) !== '' ? ' (' . h((string)$olatBase) . ')' : '' ?></span>
  </p>
  <?php $renderPicker('protocol_olat', $protoOlatFolder, 'OLAT-Basisordner', $olatReady,
      '<p class="help">Bitte zuerst unter <a href="uploads.php?t=olat#olat-config">Technik → OLAT</a> die Verbindung einrichten – danach lässt sich der Ordner anklicken.</p>'); ?>
</div>

<div class="card" id="automations-cron">
  <div class="auto-head"><h2 style="margin:0"><i class="ti ti-clock-bolt" style="color:var(--petrol)"></i> Automatischer Lauf (Cron)</h2></div>
  <p class="help">Ein <strong>täglicher</strong> Aufruf (am besten kurz nach der Auslösezeit, z. B. <strong>18:01</strong>) erledigt beide Abläufe oben: er erzeugt die fällige <strong>Berichte-Dok</strong> und gleicht die <strong>Protokoll-Genehmigungen</strong> mit dem Sitzungskalender ab – anlegen, wenn eine Folgesitzung dazugekommen ist, umhängen, wenn die bisherige Zielsitzung abgesagt, gelöscht oder verschoben wurde. Im <strong>Mittwald-Kundencenter → Cronjobs</strong> einen täglichen Job auf diese URL legen:</p>
  <p><code class="auto-cron"><?= h($autoCronUrl) ?></code></p>
  <p class="help">Ein häufigerer Takt schadet nicht (alles ist idempotent), ist aber nicht nötig. Dies ist <strong>einer von mehreren</strong> Läufen – die vollständige Liste mit allen Adressen steht in der <a href="admin/index.php#cron">Verwaltung unter „Cron-Jobs einrichten"</a>.</p>
  <?php $cronLastAuto = cron_last('automations'); $cronAge = $cronLastAuto ? time() - (int)strtotime($cronLastAuto) : null; ?>
  <p class="up-test <?= $cronAge !== null && $cronAge <= 26 * 3600 ? 'ok' : 'bad' ?>" style="margin:.4rem 0 0">
    <i class="ti <?= $cronAge !== null && $cronAge <= 26 * 3600 ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i>
    <?php if ($cronLastAuto): ?>
      Letzter automatischer Lauf: <?= h(date('d.m.Y H:i', (int)strtotime($cronLastAuto))) ?> Uhr<?= $cronAge > 26 * 3600 ? ' – der Cron läuft offenbar nicht mehr, bitte im Mittwald-Kundencenter prüfen.' : '.' ?>
    <?php else: ?>
      Der Cron ist <strong>noch nie gelaufen</strong> – bitte im Mittwald-Kundencenter → Cronjobs die URL oben als täglichen Job anlegen.
    <?php endif; ?>
  </p>

  <hr class="auto-sep">
  <h3 style="margin:.2rem 0 .3rem"><i class="ti ti-player-play"></i> Cron-Probelauf</h3>
  <p class="help">Führt genau das aus, was der geplante Cron tut – <strong>beide</strong> Teile: fällige Berichte-Dok erzeugen &amp; hochladen und wartende Protokoll-Abstimmungen nachziehen. Verarbeitet nur, was <em>gerade wirklich fällig</em> ist. (Zum Ausprobieren einer einzelnen Berichte-Dok unabhängig vom Zeitfenster gibt es „Jetzt testen" in der Berichte-Karte oben.)</p>
  <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="run_cron_now"><button class="btn secondary" type="submit"><i class="ti ti-refresh"></i> Cron jetzt ausführen</button></form>
  <?php if ($cronRunResult !== null): $rep = $cronRunResult['report']; $any = $rep['count'] > 0 || $cronRunResult['attached'] > 0; ?>
    <div class="auto-runlog">
      <p class="up-test <?= $any ? 'ok' : '' ?>" style="margin:.6rem 0 .3rem">
        <i class="ti <?= $any ? 'ti-circle-check' : 'ti-info-circle' ?>"></i> Berichte-Dok: <?= (int)$rep['count'] ?> hochgeladen · Protokoll-Abstimmungen nachgezogen: <?= (int)$cronRunResult['attached'] ?>
      </p>
      <ul class="auto-lines">
        <?php foreach ($rep['lines'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>

<div class="card" id="ablage">
  <div class="auto-head"><h2 style="margin:0"><i class="ti ti-arrow-fork" style="color:var(--petrol)"></i> Ablage für gemeinsames Bearbeiten</h2></div>
  <p class="help">Wohin die App Doks kopiert, wenn jemand auf <strong>„In … öffnen"</strong> klickt – bei Info-Doks,
    Sitzungs- und Abstimmungs-Anhängen und Protokoll-Entwürfen.</p>
  <p class="help"><strong>Wichtig:</strong> Eine Datei liegt immer nur in <em>einer</em> Ablage. Was schon in Teams liegt,
    bleibt in Teams und behält seinen Teams-Knopf – sonst gäbe es zwei bearbeitbare Fassungen derselben Datei und
    niemand wüsste, welche gilt. Die Umstellung wirkt also nur auf <strong>neue</strong> Doks.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_mirror_backend">
    <label for="mirror_backend">Neue Doks gehen nach</label>
    <select name="backend" id="mirror_backend">
      <option value="teams"<?= $mirrorNow === 'teams' ? ' selected' : '' ?><?= $configured ? '' : ' disabled' ?>>
        Microsoft Teams / SharePoint<?= $configured ? '' : ' – nicht eingerichtet' ?>
      </option>
      <option value="nextcloud"<?= $mirrorNow === 'nextcloud' ? ' selected' : '' ?><?= $ncReady ? '' : ' disabled' ?>>
        Nextcloud<?= !$ncReady ? ' – nicht eingerichtet' : ($ncEditor !== '' ? ' – bearbeiten mit ' . $ncEditor : ' – ohne Office-Server, Doks werden nur abgelegt') ?>
      </option>
    </select>
    <?php if ($ncReady && $ncEditor === ''): ?>
      <p class="help"><i class="ti ti-info-circle"></i> In der Nextcloud wurde <strong>kein Office-Server</strong>
        (Collabora oder OnlyOffice) gefunden. Doks lassen sich dann ablegen und herunterladen, aber
        <strong>nicht im Browser gemeinsam bearbeiten</strong>.</p>
    <?php endif; ?>
    <?php if (!$configured && !$ncReady): ?>
      <p class="help">Noch ist <strong>keine</strong> Ablage eingerichtet – siehe unten unter <a href="uploads.php?t=sharepoint">Technik / Einrichtung</a>.</p>
    <?php elseif ($mirrorWish !== '' && $mirrorWish !== $mirrorNow): ?>
      <p class="up-test bad"><i class="ti ti-alert-triangle"></i> Gewählt war <?= h(mirror_label($mirrorWish)) ?>,
        eingerichtet ist aber nur <?= h(mirror_label($mirrorNow)) ?> – es gilt <?= h(mirror_label($mirrorNow)) ?>.</p>
    <?php endif; ?>
    <div class="btn-row">
      <button class="btn" type="submit"<?= ($configured || $ncReady) ? '' : ' disabled' ?>><i class="ti ti-device-floppy"></i> Ablage übernehmen</button>
    </div>
  </form>
  <?php
    // Was liegt derzeit wo? Zeigt beim Umzug, wie viel Altbestand noch in Teams hängt.
    $cntT = (int)db()->query('SELECT COUNT(*) FROM teams_files')->fetchColumn();
    $cntN = (int)db()->query('SELECT COUNT(*) FROM nc_files')->fetchColumn();
  ?>
  <p class="help" style="margin-bottom:0"><i class="ti ti-files"></i> Aktuell gespiegelt:
    <strong><?= $cntT ?></strong> Dok(s) in Teams, <strong><?= $cntN ?></strong> in der Nextcloud.</p>
</div>

<?php
// --- Belegblatt-Archiv (Nextcloud) ---
$expOn     = setting_get('nc_expense_enabled', '0') === '1';
$expFolder = expense_nc_base();
$expReady  = nc_configured();
$expPdfOk  = graph_configured() && trim((string)setting_get('graph_drive_id', '')) !== '';
?>
<div class="card" id="belege">
  <div class="auto-head"><h2 style="margin:0"><i class="ti ti-receipt" style="color:var(--petrol)"></i> Belegblätter → Nextcloud</h2></div>
  <p class="help">Jedes eingereichte Belegblatt wird als <strong>PDF</strong> in der Nextcloud abgelegt –
    unter <code>&lt;Ordner&gt;/&lt;Jahr&gt;/VORNAME-DATUM.pdf</code>, die angehefteten Belege als eigene Dateien daneben.
    Bei jeder Änderung wird die Datei <strong>ersetzt</strong>. Wird ein Belegblatt in der App gelöscht,
    <strong>bleibt</strong> die Datei in der Nextcloud – sie ist das Archiv.</p>
  <p class="help"><i class="ti ti-lock"></i> <strong>Auf einem Belegblatt stehen Anschrift und IBAN.</strong>
    Dieser Ordner ist bewusst <em>getrennt</em> vom Ordner der App-Doks: Teile ihn in der Nextcloud
    <strong>nur mit dem Finanzen-Team</strong>, nicht mit allen Mitgliedern.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_expense_nc">
    <label class="wl-sw slim"><input type="checkbox" name="enabled" value="1"<?= $expOn ? ' checked' : '' ?><?= $expReady ? '' : ' disabled' ?>>
      <span class="wl-sw-track" aria-hidden="true"></span>
      <span class="wl-sw-txt"><strong>Belegblätter automatisch in der Nextcloud ablegen</strong></span></label>
    <?php if (!$expReady): ?>
      <p class="help">Dafür muss zuerst die <a href="uploads.php?t=nextcloud#nc-config">Nextcloud eingerichtet</a> sein.</p>
    <?php elseif (!$expPdfOk): ?>
      <p class="up-test bad"><i class="ti ti-alert-triangle"></i> Für die <strong>PDF-Wandlung</strong> wird die
        Teams-Anbindung samt gewählter Dokumentbibliothek gebraucht – die App schickt das Belegblatt kurz dorthin,
        lässt es von Microsoft Graph wandeln und löscht die Zwischendatei sofort wieder
        (sie landet im SharePoint-Papierkorb). Ohne das bleibt die Ablage stumm.</p>
    <?php endif; ?>
    <div class="btn-row"><button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Speichern</button></div>
  </form>
  <hr class="auto-sep">
  <h3 style="margin:.2rem 0 .3rem"><i class="ti ti-brand-nextcloud"></i> Zielordner <span class="small muted" style="font-weight:400">· Nextcloud, Jahr kommt automatisch dazu</span></h3>
  <p class="up-test<?= $expFolder !== '' ? ' ok' : '' ?>" style="margin:.2rem 0 .5rem">
    <i class="ti ti-folder"></i> Aktuell: <strong><?= $expFolder !== '' ? h($expFolder) : 'Wurzel des Kontos' ?></strong>
    <span class="muted">/ <?= date('Y') ?> (automatisch)</span>
  </p>
  <?php $renderPicker('expense', $expFolder, 'Wurzel des Kontos', $expReady,
      '<p class="help">Bitte zuerst unter <a href="uploads.php?t=nextcloud#nc-config">Technik → Nextcloud</a> die Verbindung einrichten – danach lässt sich der Ordner anklicken.</p>'); ?>
</div>

<?php endif; /* Ende Reiter „Automationen" */ ?>

<?php /* Jede der drei Anbindungen ist ein eigener Reiter – der Inhalt steht unverändert
         darin (Umbau-Regel: Abschnitte nicht verschieben). */ ?>
<?php if ($tab === 'sharepoint'): ?>
<div class="section-title" style="margin-top:1rem"><i class="ti ti-brand-office"></i> Microsoft SharePoint / OneDrive</div>

<div class="card" id="status">
  <h2 style="margin-top:0"><i class="ti ti-plug-connected"></i> Status der SharePoint-Anbindung</h2>
  <ul class="up-status">
    <li><?= $configured ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> Zugangsdaten (Tenant, Client, Secret)</li>
    <li><?= ($host !== '') ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> SharePoint-Site hinterlegt<?= $host !== '' ? ' <span class="muted">(' . h($host) . h($path !== '' ? '/' . ltrim($path, '/') : '') . ')</span>' : '' ?></li>
    <li><?= ($driveId !== '') ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> Dokumentbibliothek gewählt<?= $driveName !== '' ? ' <span class="muted">(' . h($driveName) . ')</span>' : '' ?></li>
    <li>
      <?php if ($secExpVal === ''): ?>
        <i class="ti ti-circle-dashed"></i> Secret-Ablauf <span class="muted">(nicht hinterlegt – für die Ablauf-Warnung unten eintragen)</span>
      <?php elseif ($secDays !== null && $secDays < 0): ?>
        <i class="ti ti-circle-x bad"></i> Secret <span class="bad">abgelaufen</span> <span class="muted">(am <?= h(date('d.m.Y', (int)strtotime($secExpVal))) ?>)</span>
      <?php elseif ($secDays !== null && $secDays <= 30): ?>
        <i class="ti ti-alert-triangle" style="color:var(--amber)"></i> Secret läuft bald ab <span class="muted">(am <?= h(date('d.m.Y', (int)strtotime($secExpVal))) ?>, in <?= (int)$secDays ?> Tagen)</span>
      <?php else: ?>
        <i class="ti ti-circle-check ok"></i> Secret gültig bis <span class="muted"><?= h(date('d.m.Y', (int)strtotime($secExpVal))) ?><?= $secDays !== null ? ' (' . (int)$secDays . ' Tage)' : '' ?></span>
      <?php endif; ?>
    </li>
  </ul>
  <?php if ($testResult !== null): ?>
    <p class="up-test <?= $testResult['ok'] ? 'ok' : 'bad' ?>">
      <i class="ti <?= $testResult['ok'] ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i> <?= h($testResult['msg']) ?>
    </p>
  <?php endif; ?>
  <form method="post" style="margin-top:.6rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="test">
    <button class="btn secondary" type="submit"<?= $configured ? '' : ' disabled' ?>><i class="ti ti-refresh"></i> Verbindung testen</button>
  </form>
</div>

<div class="card" id="config">
  <h2 style="margin-top:0"><i class="ti ti-key"></i> Zugangsdaten (Microsoft Graph)</h2>
  <p class="help">App-only-Anbindung über eine Entra-ID-App-Registrierung mit Application-Permission <code>Sites.Selected</code> bzw. <code>Sites.ReadWrite.All</code> und erteiltem Admin-Consent. Diese Werte sind sensibel und nur für Sekretariat/Vorsitz/Admin sichtbar.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_config">
    <div class="field-row">
      <div>
        <label for="tenant_id">Verzeichnis-ID (Tenant ID)</label>
        <input type="text" name="tenant_id" id="tenant_id" value="<?= h($tenant) ?>" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off">
      </div>
      <div>
        <label for="client_id">Anwendungs-ID (Client ID)</label>
        <input type="text" name="client_id" id="client_id" value="<?= h($client) ?>" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off">
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="client_secret">Client-Secret (Wert)<?= $hasSecret ? ' <span class="muted">– gespeichert, zum Ändern neu eingeben</span>' : '' ?></label>
        <input type="password" name="client_secret" id="client_secret" value="" placeholder="<?= $hasSecret ? '••••••••••••••••' : 'Secret-Wert aus der App-Registrierung' ?>" autocomplete="new-password">
      </div>
      <div>
        <label for="secret_expires">Secret gültig bis</label>
        <input type="text" class="fp-date" name="secret_expires" id="secret_expires" placeholder="Datum wählen" value="<?= h($secExpVal) ?>">
      </div>
    </div>
    <p class="help">Das „Gültig bis"-Datum des Secrets aus der App-Registrierung eintragen (bei 24 Monaten = heute + 2 Jahre). Admin und Vorsitz werden ab 30 Tagen vorher und nach Ablauf gewarnt.</p>
    <div class="field-row">
      <div>
        <label for="site_hostname">SharePoint-Host</label>
        <input type="text" name="site_hostname" id="site_hostname" value="<?= h($host) ?>" placeholder="euertenant.sharepoint.com" autocomplete="off">
      </div>
      <div>
        <label for="site_path">Site-Pfad</label>
        <input type="text" name="site_path" id="site_path" value="<?= h($path) ?>" placeholder="/sites/AStA" autocomplete="off">
      </div>
    </div>
    <p class="help">Der Host ist <code>name.sharepoint.com</code>, der Pfad steht in der Adresse eurer Team-Site (z. B. <code>/sites/AStA</code>). Für die Stamm-Site den Pfad leer lassen.</p>
    <div class="btn-row">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Konfiguration speichern</button>
    </div>
  </form>
</div>

<?php if ($probedDrives !== null && $probedDrives): ?>
<div class="card" id="library">
  <h2 style="margin-top:0"><i class="ti ti-folders"></i> Dokumentbibliothek wählen</h2>
  <p class="help">In diese Bibliothek werden Dateien hochgeladen. Üblich ist „Dokumente" / „Documents".</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="set_drive">
    <div class="field-row" style="align-items:flex-end">
      <div style="flex:1">
        <label for="drive_id">Bibliothek</label>
        <select name="drive_id" id="drive_id">
          <?php foreach ($probedDrives as $d): ?>
            <option value="<?= h($d['id']) ?>"<?= $d['id'] === $driveId ? ' selected' : '' ?>><?= h($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" type="submit"><i class="ti ti-check"></i> Übernehmen</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" id="upload">
  <h2 style="margin-top:0"><i class="ti ti-upload"></i> Test-Upload</h2>
  <?php if ($driveId === ''): ?>
    <p class="help">Bitte zuerst oben die Verbindung testen und eine Dokumentbibliothek auswählen.</p>
  <?php else: ?>
    <p class="help">Lädt eine Datei direkt in die Bibliothek <strong><?= h($driveName) ?></strong>. Der Zielordner wird bei Bedarf angelegt.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload_test">
      <label for="folder">Zielordner (optional, z. B. <code>Protokolle/2026</code>)</label>
      <input type="text" name="folder" id="folder" value="" placeholder="leer = Wurzel der Bibliothek" autocomplete="off">
      <label for="file" style="margin-top:.6rem">Datei</label>
      <input type="file" name="file" id="file" required>
      <div class="btn-row" style="margin-top:.6rem">
        <button class="btn" type="submit"><i class="ti ti-cloud-upload"></i> Hochladen</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($tab === 'olat'): ?>
<div class="section-title" id="olat"><i class="ti ti-school"></i> OLAT (OpenOLAT)</div>

<div class="card" id="olat-status">
  <h2 style="margin-top:0"><i class="ti ti-plug-connected"></i> Status der OLAT-Anbindung</h2>
  <ul class="up-status">
    <li><?= ($olatUrl !== '') ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> WebDAV-URL hinterlegt<?= $olatUrl !== '' ? ' <span class="muted">(' . h($olatUrl) . ')</span>' : '' ?></li>
    <li><?= ($olatUser !== '') ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> Benutzername<?= $olatUser !== '' ? ' <span class="muted">(' . h($olatUser) . ')</span>' : '' ?></li>
    <li><?= $olatHasPw ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> WebDAV-Passwort gespeichert</li>
    <li><?= ($olatBase !== '') ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-dashed"></i>' ?> Basisordner <span class="muted">(<?= $olatBase !== '' ? h($olatBase) : 'Wurzel' ?>)</span></li>
  </ul>
  <?php if ($olatTest !== null): ?>
    <p class="up-test <?= $olatTest['ok'] ? 'ok' : 'bad' ?>">
      <i class="ti <?= $olatTest['ok'] ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i> <?= h($olatTest['msg']) ?>
    </p>
  <?php endif; ?>
  <?php if ($olatList !== null): ?>
    <?php
      $curBase = (string)setting_get('olat_base_folder', '');
      $parent = $curBase !== '' ? trim(implode('/', array_slice(explode('/', $curBase), 0, -1)), '/') : '';
    ?>
    <div class="olat-browser">
      <div class="olat-loc"><i class="ti ti-folder-open"></i> <strong><?= $curBase !== '' ? h($curBase) : 'WebDAV-Wurzel' ?></strong></div>
      <?php if ($curBase !== ''): ?>
        <form method="post" class="olat-cd">
          <?= csrf_field() ?><input type="hidden" name="action" value="olat_cd"><input type="hidden" name="to" value="<?= h($parent) ?>">
          <button class="btn small secondary" type="submit"><i class="ti ti-arrow-up"></i> Ebene hoch</button>
        </form>
      <?php endif; ?>
      <?php if (!$olatList): ?>
        <p class="muted" style="margin:.4rem 0 0">Dieser Ordner ist leer (oder enthält keine Unterordner).</p>
      <?php else: ?>
        <ul class="olat-entries">
          <?php foreach ($olatList as $en): ?>
            <li>
              <?php if ($en['dir']): ?>
                <form method="post" class="olat-cd">
                  <?= csrf_field() ?><input type="hidden" name="action" value="olat_cd"><input type="hidden" name="to" value="<?= h(($curBase !== '' ? $curBase . '/' : '') . $en['name']) ?>">
                  <button class="linklike" type="submit"><i class="ti ti-folder"></i> <?= h($en['name']) ?></button>
                </form>
              <?php else: ?>
                <span class="olat-file"><i class="ti ti-file"></i> <?= h($en['name']) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="help" style="margin:.5rem 0 0">Klick dich in den gewünschten Ordner – die aktuelle Ebene ist automatisch dein <strong>Basisordner</strong>. Typischer Weg: <code>coursefolders</code> → euer Kurstitel.</p>
    </div>
  <?php endif; ?>
  <form method="post" style="margin-top:.6rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="test_olat">
    <button class="btn secondary" type="submit"<?= $olatReady ? '' : ' disabled' ?>><i class="ti ti-refresh"></i> Verbindung testen</button>
  </form>
</div>

<div class="card" id="olat-config">
  <h2 style="margin-top:0"><i class="ti ti-key"></i> Zugangsdaten (OLAT WebDAV)</h2>
  <p class="help">OpenOLAT stellt Ordner per WebDAV bereit. Für den Zugriff braucht ihr meist ein <strong>separates WebDAV-Passwort</strong>, das in OLAT unter <em>Einstellungen → WebDAV/Passwörter</em> gesetzt wird (nicht zwingend das normale Login-Passwort).</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_olat">
    <label for="olat_url">WebDAV-URL</label>
    <input type="text" name="olat_url" id="olat_url" value="<?= h($olatUrl) ?>" placeholder="https://olat.vcrp.de/webdav/" autocomplete="off">
    <div class="field-row">
      <div>
        <label for="olat_user">Benutzername</label>
        <input type="text" name="olat_user" id="olat_user" value="<?= h($olatUser) ?>" placeholder="OLAT-Benutzername" autocomplete="off">
      </div>
      <div>
        <label for="olat_password">WebDAV-Passwort<?= $olatHasPw ? ' <span class="muted">– gespeichert, zum Ändern neu eingeben</span>' : '' ?></label>
        <input type="password" name="olat_password" id="olat_password" value="" placeholder="<?= $olatHasPw ? '••••••••••••••••' : 'WebDAV-Passwort' ?>" autocomplete="new-password">
      </div>
    </div>
    <label for="olat_base_folder">Basisordner (optional, relativ zur WebDAV-Wurzel)</label>
    <input type="text" name="olat_base_folder" id="olat_base_folder" value="<?= h($olatBase) ?>" placeholder="z. B. coursefolders/AStA oder home" autocomplete="off">
    <p class="help">Typische WebDAV-Ordner in OpenOLAT: <code>home</code> (persönlicher Ordner), <code>coursefolders/…</code> (Kursablagen) oder <code>groupfolders/…</code> (Gruppenordner). Leer = WebDAV-Wurzel.</p>
    <div class="btn-row">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> OLAT-Konfiguration speichern</button>
    </div>
  </form>
</div>

<div class="card" id="olat-upload">
  <h2 style="margin-top:0"><i class="ti ti-upload"></i> Test-Upload (OLAT)</h2>
  <?php if (!$olatReady): ?>
    <p class="help">Bitte zuerst die OLAT-Zugangsdaten speichern und die Verbindung testen.</p>
  <?php else: ?>
    <p class="help">Lädt eine Datei per WebDAV nach OLAT<?= $olatBase !== '' ? ' (Basisordner <strong>' . h($olatBase) . '</strong>)' : '' ?>. Unterordner werden bei Bedarf angelegt.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload_olat_test">
      <label for="olat_folder">Unterordner (optional, z. B. <code>Protokolle/2026</code>)</label>
      <input type="text" name="folder" id="olat_folder" value="" placeholder="leer = direkt in den Basisordner" autocomplete="off">
      <label for="olat_file" style="margin-top:.6rem">Datei</label>
      <input type="file" name="file" id="olat_file" required>
      <div class="btn-row" style="margin-top:.6rem">
        <button class="btn" type="submit"><i class="ti ti-cloud-upload"></i> Hochladen</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($tab === 'nextcloud'): ?>
<div class="section-title" id="nc"><i class="ti ti-brand-nextcloud"></i> Nextcloud</div>

<div class="card" id="nc-status">
  <h2 style="margin-top:0"><i class="ti ti-plug-connected"></i> Status der Nextcloud-Anbindung</h2>
  <ul class="up-status">
    <li><?= ($ncUrl !== '') ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> Adresse hinterlegt<?= $ncUrl !== '' ? ' <span class="muted">(' . h($ncUrl) . ')</span>' : '' ?></li>
    <li><?= ($ncUser !== '') ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> Benutzername<?= $ncUser !== '' ? ' <span class="muted">(' . h($ncUser) . ')</span>' : '' ?></li>
    <li><?= $ncHasPw ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-x bad"></i>' ?> App-Passwort gespeichert</li>
    <li><?= ($ncFolder !== '') ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-dashed"></i>' ?> Basisordner <span class="muted">(<?= $ncFolder !== '' ? h($ncFolder) : 'Wurzel des Kontos' ?>)</span></li>
    <li><?= $ncEditor !== '' ? '<i class="ti ti-circle-check ok"></i>' : '<i class="ti ti-circle-dashed"></i>' ?>
        Office-Server <span class="muted">(<?= $ncEditor !== '' ? h($ncEditor) : 'keiner erkannt – nur ablegen, kein gemeinsames Bearbeiten' ?>)</span></li>
  </ul>
  <?php if ($ncTest !== null): ?>
    <p class="up-test <?= $ncTest['ok'] ? 'ok' : 'bad' ?>">
      <i class="ti <?= $ncTest['ok'] ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i> <?= h($ncTest['msg']) ?>
    </p>
  <?php endif; ?>
  <?php if ($ncCapsErr): ?>
    <p class="up-test bad"><i class="ti ti-alert-triangle"></i> Fähigkeiten nicht abrufbar: <?= h($ncCapsErr) ?></p>
  <?php endif; ?>
  <?php if ($ncList !== null): ?>
    <?php $ncParent = $ncFolder !== '' ? trim(implode('/', array_slice(explode('/', $ncFolder), 0, -1)), '/') : ''; ?>
    <div class="olat-browser">
      <div class="olat-loc"><i class="ti ti-folder-open"></i> <strong><?= $ncFolder !== '' ? h($ncFolder) : 'Wurzel des Kontos' ?></strong></div>
      <?php if ($ncFolder !== ''): ?>
        <form method="post" class="olat-cd">
          <?= csrf_field() ?><input type="hidden" name="action" value="nc_cd"><input type="hidden" name="to" value="<?= h($ncParent) ?>">
          <button class="btn small secondary" type="submit"><i class="ti ti-arrow-up"></i> Ebene hoch</button>
        </form>
      <?php endif; ?>
      <?php if (!$ncList): ?>
        <p class="muted" style="margin:.4rem 0 0">Dieser Ordner ist leer (oder enthält keine Unterordner).</p>
      <?php else: ?>
        <ul class="olat-entries">
          <?php foreach ($ncList as $en): ?>
            <li>
              <?php if ($en['dir']): ?>
                <form method="post" class="olat-cd">
                  <?= csrf_field() ?><input type="hidden" name="action" value="nc_cd"><input type="hidden" name="to" value="<?= h(($ncFolder !== '' ? $ncFolder . '/' : '') . $en['name']) ?>">
                  <button class="linklike" type="submit"><i class="ti ti-folder"></i> <?= h($en['name']) ?></button>
                </form>
              <?php else: ?>
                <span class="olat-file"><i class="ti ti-file"></i> <?= h($en['name']) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="help" style="margin:.5rem 0 0">Klick dich in den gewünschten Ordner – die aktuelle Ebene ist automatisch dein <strong>Basisordner</strong>. Darunter legt die App je Bereich einen Unterordner an (<code>Infos</code>, <code>Sitzungs-Anhänge</code>, <code>Abstimmungen</code>, <code>Protokoll-Entwürfe</code>).</p>
    </div>
  <?php endif; ?>
  <form method="post" style="margin-top:.6rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="test_nc">
    <button class="btn secondary" type="submit"<?= $ncReady ? '' : ' disabled' ?>><i class="ti ti-refresh"></i> Verbindung testen</button>
  </form>
</div>

<div class="card" id="nc-config">
  <h2 style="margin-top:0"><i class="ti ti-key"></i> Zugangsdaten (Nextcloud)</h2>
  <p class="help">Bitte ein <strong>App-Passwort</strong> verwenden, nicht das normale Kennwort: in der Nextcloud unter
    <em>Einstellungen → Sicherheit → „Neues App-Passwort erstellen"</em>. Das lässt sich einzeln widerrufen, funktioniert
    auch bei aktiver Zwei-Faktor-Anmeldung, und das echte Kennwort liegt nirgends in der App.</p>
  <p class="help">Das Konto sollte ein <strong>eigenes Konto für die App</strong> sein (z. B. <code>asta-app</code>), auf
    dessen Ordner die Mitglieder Zugriff haben – die Doks liegen dort und werden von dort geöffnet.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_nc">
    <label for="nc_url">Adresse der Nextcloud</label>
    <input type="text" name="nc_url" id="nc_url" value="<?= h($ncUrl) ?>" placeholder="<?= h('https://cloud.' . org_domain()) ?>" autocomplete="off">
    <p class="help">Nur die Adresse – den WebDAV-Pfad hängt die App selbst an. Ein versehentlich mitkopierter Pfad
      (<code>/index.php/apps/files/…</code>) wird automatisch abgeschnitten.</p>
    <div class="field-row">
      <div>
        <label for="nc_user">Benutzername</label>
        <input type="text" name="nc_user" id="nc_user" value="<?= h($ncUser) ?>" placeholder="z. B. asta-app" autocomplete="off">
      </div>
      <div>
        <label for="nc_password">App-Passwort<?= $ncHasPw ? ' <span class="muted">– gespeichert, zum Ändern neu eingeben</span>' : '' ?></label>
        <input type="password" name="nc_password" id="nc_password" value="" placeholder="<?= $ncHasPw ? '••••••••••••••••' : 'App-Passwort aus der Nextcloud' ?>" autocomplete="new-password">
      </div>
    </div>
    <label for="nc_open_folder">Basisordner für App-Doks</label>
    <input type="text" name="nc_open_folder" id="nc_open_folder" value="<?= h($ncFolder) ?>" placeholder="AStA-App" autocomplete="off">
    <p class="help">Leer = direkt in die Wurzel des Kontos. Fehlende Ordner legt die App beim ersten Dok an.</p>
    <div class="btn-row">
      <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Nextcloud-Konfiguration speichern</button>
    </div>
  </form>
</div>

<div class="card" id="nc-upload">
  <h2 style="margin-top:0"><i class="ti ti-upload"></i> Test-Upload (Nextcloud)</h2>
  <?php if (!$ncReady): ?>
    <p class="help">Bitte zuerst die Zugangsdaten speichern und die Verbindung testen.</p>
  <?php else: ?>
    <p class="help">Lädt eine Datei per WebDAV in die Nextcloud<?= $ncFolder !== '' ? ' (Basisordner <strong>' . h($ncFolder) . '</strong>)' : '' ?>. Unterordner werden bei Bedarf angelegt.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload_nc_test">
      <label for="nc_folder">Unterordner (optional, z. B. <code>Test/2026</code>)</label>
      <input type="text" name="folder" id="nc_folder" value="" placeholder="leer = direkt in den Basisordner" autocomplete="off">
      <label for="nc_file" style="margin-top:.6rem">Datei</label>
      <input type="file" name="file" id="nc_file" required>
      <div class="btn-row" style="margin-top:.6rem">
        <button class="btn" type="submit"><i class="ti ti-cloud-upload"></i> Hochladen</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php
page_footer();
