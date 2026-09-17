<?php
// Web-Push-Abos an-/abmelden (wird von app.js per fetch aufgerufen).
// Nur für eingeloggte Mitglieder; CSRF-geschützt wie alle Formulare.
require __DIR__ . '/lib.php';
db();
require_login();

header('Content-Type: application/json; charset=utf-8');
$me = current_member();
if (!$me) { echo json_encode(['ok' => false, 'error' => 'Kein persönliches Konto (Technik-Login).']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
check_csrf();

$action = $_POST['action'] ?? '';
$sub = json_decode((string)($_POST['subscription'] ?? ''), true);
$endpoint = (string)($sub['endpoint'] ?? '');

if ($action === 'subscribe') {
    $ok = push_subscription_save(
        (int)$me['id'],
        $endpoint,
        (string)($sub['keys']['p256dh'] ?? ''),
        (string)($sub['keys']['auth'] ?? '')
    );
    echo json_encode(['ok' => $ok]);
    exit;
}
if ($action === 'unsubscribe') {
    if ($endpoint !== '') push_subscription_delete($endpoint, (int)$me['id']); // nur eigene Abos
    echo json_encode(['ok' => true]);
    exit;
}
if ($action === 'app_open') {
    // App geöffnet – zählt für die Öffnungs-Serie; n = gesammelte Öffnungen aus der Client-Warteschlange
    member_count_app_open((int)$me['id'], (int)($_POST['n'] ?? 1));
    // Frisch freigeschaltete Achievements direkt als Toast zurückgeben (wie der fire-Handler)
    echo json_encode(['ok' => true, 'unlocks' => array_values(take_ach_unlocks())]);
    exit;
}
if ($action === 'pwa_ping') {
    // App läuft gerade im installierten (Standalone-)Modus – Tagesstempel für die Aktivitätsstatistik
    try { db()->prepare('UPDATE members SET pwa_last_seen = ? WHERE id = ?')->execute([date('Y-m-d'), (int)$me['id']]); }
    catch (\Throwable $e) {}
    echo json_encode(['ok' => true]);
    exit;
}
if ($action === 'tour_done') {
    // Onboarding-Rundgang gelaufen → nicht mehr automatisch starten. done=1 (voll durchgeklickt) räumt
    // zusätzlich das freiwillige Dashboard-Angebot weg; bei Abbruch bleibt es stehen.
    member_mark_onboarded((int)$me['id']);
    if (($_POST['done'] ?? '') === '1') {
        member_clear_tour_task((int)$me['id']);
        // NUR hier: bis zum letzten Schritt geklickt. Das ist der einzige Ort, an dem sich
        // „durchgelaufen" von „abgebrochen" oder „kenne mich schon aus" unterscheiden lässt –
        // und damit die einzige ehrliche Grundlage für das Achievement.
        member_mark_tour_done((int)$me['id']);
        achievements_evaluate((int)$me['id']);
    }
    echo json_encode(['ok' => true]);
    exit;
}
if ($action === 'skin_applied') {
    // Der einmalig vorgemerkte Auto-Skin wurde vom Client angewandt → Pending-Feld leeren.
    member_clear_pending_skin((int)$me['id']);
    echo json_encode(['ok' => true]);
    exit;
}
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
