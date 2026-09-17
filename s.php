<?php
/*
 * Öffentlicher Teilen-/Vorschau-Endpunkt.
 *
 * Liefert für einen geteilten Link (Event/Sitzung/Get-Together) eine schöne Linkvorschau
 * (Open-Graph-/Twitter-Meta) – die Vorschau-Crawler von Teams, WhatsApp, Signal & Co. holen
 * die Seite OHNE Login, deshalb braucht es diesen öffentlichen Endpunkt. Menschen werden,
 * sofern eingeloggt, sofort zum echten Inhalt durchgereicht; sonst sehen sie eine schlichte
 * Karte mit „In der AStA-App ansehen" (Login). BEWUSST autark (kein App-Shell, eigenes CSS).
 */
require __DIR__ . '/lib.php';
db();

$type = (string)($_GET['t'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$pv   = share_preview($type, $id);

// Eingeloggte (inkl. „Angemeldet bleiben") direkt zum echten Inhalt schicken.
if ($pv) {
    if (!is_logged_in()) try_remember();
    if (is_logged_in()) redirect($pv['target']);
}

$site   = APP_NAME;
$title  = $pv ? $pv['title'] : 'AStA-App';
$desc   = $pv ? $pv['desc']  : 'Interner Termin- und Eventplaner des ' . org_name_kurz() . '.';
$emoji  = $pv['emoji'] ?? '';
$target = $pv ? $pv['target'] : 'login.php';
$img    = app_url(brand_url('icon-512'));
$canon  = app_url('s.php?t=' . rawurlencode($type) . '&id=' . $id);

http_response_code($pv ? 200 : 404);
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($site) ?></title>
<meta name="description" content="<?= h($desc) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h($site) ?>">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:image" content="<?= h($img) ?>">
<meta property="og:url" content="<?= h($canon) ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($desc) ?>">
<meta name="twitter:image" content="<?= h($img) ?>">
<meta name="theme-color" content="#0E5C73">
<link rel="icon" type="image/png" href="<?= h(app_url(brand_url('logo-header'))) ?>">
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #16242a; background: #eef3f4; }
.card { background: #fff; border-radius: 16px; box-shadow: 0 8px 30px rgba(14,92,115,.14); padding: 2rem 1.9rem; max-width: 440px; width: 100%; text-align: center; }
.card img.logo { height: 46px; width: auto; margin-bottom: 1rem; }
.kicker { color: #5f7178; font-size: .9rem; margin: 0 0 .4rem; }
h1 { margin: 0 0 .5rem; font-size: 1.45rem; color: #0a4456; }
.desc { color: #41535a; margin: 0 0 1.4rem; line-height: 1.5; }
.btn { display: inline-block; background: #0E5C73; color: #fff; text-decoration: none; font-weight: 600;
  padding: .7rem 1.3rem; border-radius: 10px; }
.btn:hover { background: #0a4456; }
.hint { color: #5f7178; font-size: .82rem; margin: .9rem 0 0; }
</style>
</head>
<body>
  <div class="card">
    <img class="logo" src="<?= h(app_url(brand_url('logo-header'))) ?>" alt="<?= h(org_name_kurz()) ?>">
    <?php if ($pv): ?>
      <p class="kicker"><?= $emoji ? $emoji . ' ' : '' ?>Geteilt aus der AStA-App</p>
      <h1><?= h($title) ?></h1>
      <?php if ($desc !== ''): ?><p class="desc"><?= h($desc) ?></p><?php endif; ?>
      <a class="btn" href="<?= h($target) ?>">In der AStA-App ansehen</a>
      <p class="hint">Dafür meldest du dich kurz mit deiner AStA-Mail an.</p>
    <?php else: ?>
      <h1>Nicht gefunden</h1>
      <p class="desc">Dieser geteilte Link ist nicht (mehr) gültig.</p>
      <a class="btn" href="<?= h(app_url('login.php')) ?>">Zur AStA-App</a>
    <?php endif; ?>
  </div>
</body>
</html>
