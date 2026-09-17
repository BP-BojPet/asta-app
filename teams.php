<?php
/**
 * „In Teams öffnen": stellt sicher, dass die Datei in der Teams-Bibliothek liegt
 * (einmaliger Upload, danach nur noch Nachschlagen) und leitet dorthin weiter –
 * dort öffnet Office im Web (bzw. die Teams-App), wo alle Team-Mitglieder ohne
 * eigene Office-Lizenz gemeinsam ansehen und bearbeiten können.
 *
 * Läuft über dieselbe Weiche wie nextcloud.php: liegt die Datei bereits in der
 * Nextcloud, führt auch dieser Aufruf dorthin – eine Datei bekommt nie zwei Spiegel.
 * Rechte: mirror_open_permitted() (Bereichs-Rollen, Protokollant:in, Ersteller:in).
 */
require __DIR__ . '/lib.php';
db();
require_login();
$me = current_member();

$kind = (string)($_GET['kind'] ?? '');
$refId = (int)($_GET['id'] ?? 0);
if (!isset(mirror_kinds()[$kind]) || $refId <= 0) { http_response_code(404); exit('Unbekannter Bereich.'); }
if (!mirror_open_permitted($kind, $refId, $me)) {
    http_response_code(403);
    exit('Keine Berechtigung, diese Datei in der Ablage zu öffnen.');
}

$err = null;
$res = mirror_ensure($kind, $refId, $me ? (int)$me['id'] : 0, $err);
$url = $res ? ($res['store'] === 'nextcloud' ? nc_file_open_url($res['row']) : teams_file_open_url($res['row'])) : '';
if ($url === '') {
    // Zur Herkunftsseite zurück (nur lokale Pfade), sonst Dashboard
    flash('Öffnen in der Ablage fehlgeschlagen' . ($err ? ': ' . $err : '.'), 'error');
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $home = base_url() ?: '';
    redirect($home !== '' && str_starts_with($ref, $home) ? $ref : 'dashboard.php');
}
redirect($url); // Teams-Deep-Link (App) bzw. Nextcloud-Link; Fallback: webUrl im Browser
