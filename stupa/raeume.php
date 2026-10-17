<?php
/**
 * Raumbelegung für das StuPa-Präsidium – dieselbe Ansicht wie in der App (raum_wochenansicht),
 * aber unter der eigenen StuPa-Anmeldung. Das Präsidium sieht und bucht Räume und löscht eigene
 * Buchungen; Räume und feste Zeiten pflegen kann es nicht (das bleibt der AStA-Verwaltung).
 * Weiter reicht der Zugang nicht: Mitglieder, Sitzungen, Events gibt es in diesem Bereich nicht.
 */
require __DIR__ . '/stupa-lib.php';
$user = stupa_require_login();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if ($r = raum_post_verarbeiten('stupa', $uid, false)) flash($r[0], $r[1]);
    header('Location: raeume.php?w=' . raum_woche_nach_post());
    exit;
}

stupa_header('Raumbelegung', $user);
?>
<div class="section-title" style="margin-top:0"><i class="ti ti-door"></i> Raumbelegung</div>
<p class="small muted" style="margin:-.3rem 0 .8rem">Wann welcher Raum belegt ist – und frei buchen, was frei ist. Eine Bestätigung kommt per Mail.</p>
<?= raum_wochenansicht(['woche' => raum_woche_nach_post(), 'self' => 'raeume.php', 'seite' => 'stupa', 'von_id' => $uid, 'verwalter' => false, 'fp' => false]) ?>
<?php
stupa_footer();
