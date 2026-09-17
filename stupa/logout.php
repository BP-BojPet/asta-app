<?php
/** Abmelden: nur die StuPa-Session verwerfen – Mitglieder-Sessions bleiben unberührt. */
require __DIR__ . '/stupa-lib.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)($p['secure'] ?? false), (bool)($p['httponly'] ?? true));
}
session_destroy();
header('Location: login.php');
