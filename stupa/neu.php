<?php
/** „Neu im StuPa-Bereich" gesehen – gemeldet vom Splash (app.js), nur mit StuPa-Anmeldung. */
require __DIR__ . '/stupa-lib.php';
header('Content-Type: application/json; charset=utf-8');
$u = stupa_current();
if (!$u || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); echo json_encode(['ok' => false]); exit; }
check_csrf();
if (($_POST['action'] ?? '') === 'news_seen') stupa_neuigkeiten_gesehen((int)$u['id'], ($_POST['geraet'] ?? '') === 'mobil' ? 'mobil' : 'desktop');
echo json_encode(['ok' => true]);
