<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_login();

$id = (int)($_GET['id'] ?? 0);
$event = $id ? event_get($id) : null;
if (!$event || !can_manage_event($event)) {
    flash('Diesen Arbeitsplan kannst du nicht einsehen.', 'error');
    redirect('events.php');
}

// Kopfzeilen-Daten (Orte/Zeitraum). Die Schichten-Darstellung kommt aus plan_print_html().
$slots = slots_grouped(event_slots($id));
$locs = [];
foreach ($slots as $s) { $l = trim((string)$s['location']); if ($l !== '' && !in_array($l, $locs, true)) $locs[] = $l; }
$range = fmt_event_range($event['starts_at'] ?? null, $event['ends_at'] ?? null);

/*
 * Autarke Seite (kein gemeinsames Stylesheet, keine Webfont, kein app.js/SW/flatpickr).
 *
 * GEDRUCKT wird NICHT die sichtbare Seite, sondern ein frisch per JavaScript erzeugtes
 * iframe-Dokument (siehe printPlan()). Grund: In Safari führt der allererste window.print()
 * auf dem ursprünglich aufgerufenen Dokument dazu, dass die Seite komplett leer wird (auch
 * die PDF-Ausgabe). Nach einem Neuladen tritt das nicht mehr auf – ein dynamisch
 * geschriebenes iframe-Dokument verhält sich wie „frisch geladen" und druckt sauber, und
 * die sichtbare Seite geht nie in den Druckmodus (kann also nicht leer werden).
 */
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Arbeitsplan – <?= h($event['title']) ?> · <?= h(APP_NAME) ?></title>
<style id="planCSS">
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #16242a; line-height: 1.45; background: #f4f6f7; }
main { max-width: 1040px; margin: 0 auto; padding: 1.4rem 1.2rem 3rem; }
.planbar { display: flex; gap: .6rem; flex-wrap: wrap; margin-bottom: 1.4rem; }
.btn { display: inline-block; padding: .55rem .95rem; border-radius: 9px; border: 1px solid #0E5C73; background: #0E5C73; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
.btn.secondary { background: #fff; color: #0E5C73; }
.plan-print { background: #fff; border-radius: 12px; padding: 1.5rem 1.6rem; box-shadow: 0 1px 3px rgba(14,92,115,.1); }
.plan-print h1 { margin: 0 0 .2rem; font-size: 1.5rem; }
.plan-sub { color: #5f7178; margin: 0 0 1.2rem; }
.plan-print h2 { font-size: 1.1rem; color: #0a4456; margin: 1.2rem 0 .5rem; }
.plan-foot { margin-top: 1.4rem; color: #5f7178; font-size: .8rem; }
.muted { color: #5f7178; }
.small { font-size: .85em; }

table.plan-table { width: 100%; border-collapse: collapse; }
.plan-table th, .plan-table td { text-align: left; vertical-align: top; padding: .4rem .55rem; border-bottom: 1px solid #dde3e5; }
.plan-table thead th { font-size: .78rem; color: #5f7178; text-transform: uppercase; letter-spacing: .03em; }
.ps-time { font-weight: 700; }
.ps-loc { color: #5f7178; }
.ps-loc::before { content: "· "; }
.ps-loc .ti { display: none; }
.ps-day td { background: #e3eef1; color: #0a4456; font-weight: 700; }

/* Druck: Querformat, kompakt, randlos. */
@page { size: A4 landscape; margin: 11mm; }
@media print {
  body { background: #fff; color: #000; }
  .planbar { display: none; }
  main { max-width: none; margin: 0; padding: 0; }
  .plan-print { box-shadow: none; border-radius: 0; padding: 0; }
  .plan-print h1 { font-size: 16pt; }
  .plan-print h2 { font-size: 12pt; color: #000; margin: 8pt 0 4pt; }
  .plan-foot { border-top: 1px solid #ccc; padding-top: 5pt; }

  .plan-table { table-layout: fixed; font-size: 9pt; border: 1px solid #999; }
  .plan-table th, .plan-table td { border: 1px solid #bbb; padding: 2.5pt 5pt; overflow-wrap: anywhere; word-break: break-word; }
  .plan-table thead th { color: #000; text-transform: none; letter-spacing: 0; border-bottom: 1.5px solid #888; }
  .plan-shifts th.ps-c1, .plan-shifts td.ps-c1 { width: 25%; }
  .ps-loc { color: #333; }
  .ps-day td { background: transparent; color: #000; font-size: 10pt; border-top: 1.5px solid #888; }
}
</style>
</head>
<body>
<main>
  <div class="planbar">
    <a class="btn secondary" href="events.php?edit=<?= $id ?>">‹ Zurück zur Verwaltung</a>
    <button class="btn" type="button" onclick="printPlan()">Drucken / Als PDF speichern</button>
  </div>

  <div class="plan-print" id="planPrint">
    <h1>Arbeitsplan: <?= h($event['title']) ?></h1>
    <p class="plan-sub">
      <?php if ($range): ?><?= h($range) ?><?php endif; ?>
      <?php if ($locs): ?> · <?= h(implode(' · ', $locs)) ?><?php endif; ?>
      <?php if (!empty($event['important'])): ?> · <strong>extrem wichtig</strong><?php endif; ?>
    </p>

    <?= plan_print_html($event, false) ?>

    <p class="plan-foot">Stand: <?= date('d.m.Y H:i') ?> · <?= h(org_name_kurz()) ?></p>
  </div>
</main>

<script>
function printPlan() {
  // Inhalt + CSS in ein frisches iframe-Dokument schreiben und DIESES drucken –
  // so geht die sichtbare Seite nie in den Druckmodus und kann nicht leer werden.
  var css = document.getElementById('planCSS').textContent;
  var content = document.getElementById('planPrint').outerHTML;
  var old = document.getElementById('printFrame');
  if (old) old.remove();
  var f = document.createElement('iframe');
  f.id = 'printFrame';
  f.setAttribute('aria-hidden', 'true');
  f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
  document.body.appendChild(f);
  var doc = f.contentWindow.document;
  doc.open();
  doc.write('<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Arbeitsplan</title><style>' + css + '</style></head><body>' + content + '</body></html>');
  doc.close();
  var done = false;
  var go = function () {
    if (done) return; done = true;
    try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {}
    setTimeout(function () { f.remove(); }, 3000);
  };
  // readyState abfragen + onload + Sicherheits-Timeout (Safari feuert load nicht immer)
  if (doc.readyState === 'complete') setTimeout(go, 60);
  else { f.onload = go; setTimeout(go, 400); }
}
</script>
</body>
</html>
