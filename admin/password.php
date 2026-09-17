<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $old = (string)($_POST['old'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify($old, (string)setting_get('admin_password_hash'))) {
        flash('Aktuelles Passwort ist falsch.', 'error');
    } elseif (strlen($new) < 6) {
        flash('Neues Passwort muss mindestens 6 Zeichen haben.', 'error');
    } else {
        setting_set('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
        // Der Vorsitz kennt jetzt nur noch das ALTE Passwort → Kontrolle sofort wieder fällig,
        // damit das neue nicht unbemerkt nur beim Technik-Referat liegt.
        admin_pw_check_reset();
        flash('Passwort geändert. Der Vorsitz wird beim nächsten Dashboard-Besuch gebeten, es zu bestätigen – gib es also weiter.', 'success');
    }
}
redirect('index.php');
