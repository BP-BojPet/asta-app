/*
 * Minimaler Service Worker für die AStA-App.
 *
 * Zweck: NUR die „Installierbarkeit" auf Android/Chrome erfüllen (Chrome verlangt
 * einen Service Worker mit fetch-Handler, damit der Installieren-Button erscheint).
 *
 * Bewusst KEIN Caching/Offline – jede Anfrage geht normal ans Netz, damit nie
 * veraltete Inhalte ausgeliefert werden.
 */
self.addEventListener('install', function () {
  self.skipWaiting();
});
self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});
self.addEventListener('fetch', function () {
  // absichtlich leer: Browser holt alles normal aus dem Netz (kein Cache)
});

/* Web Push: Nachricht anzeigen (Payload = JSON {title, body, url} vom Server) */
self.addEventListener('push', function (event) {
  var d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) {}
  event.waitUntil(self.registration.showNotification(d.title || 'AStA-App', {
    body: d.body || '',
    icon: 'brand.php?b=icon-180',
    badge: 'brand.php?b=icon-180',
    data: { url: d.url || './dashboard.php' }
  }));
});

/* Klick auf die Benachrichtigung: zur ZIEL-URL navigieren (vorhandenes App-Fenster wiederverwenden, sonst neu öffnen).
 * Wichtig: WindowClient.navigate() weist bei nicht vom SW kontrollierten Fenstern ab – der Fehler MUSS abgefangen
 * werden, sonst wird das Fenster nur fokussiert und bleibt auf der alten Seite (dann „landet man nicht am Ziel"). */
self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || './dashboard.php';
  var target = new URL(url, self.registration.scope);
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    var client = null;
    for (var i = 0; i < list.length; i++) { if ('focus' in list[i]) { client = list[i]; break; } }
    if (!client) return self.clients.openWindow(target.href);
    // Steht das Fenster schon exakt auf dem Ziel (inkl. #Anker)? Dann nur fokussieren.
    if (client.url === target.href) return client.focus();
    // iOS kennt WindowClient.navigate() nicht, und openWindow() fokussiert dort nur das
    // vorhandene Fenster OHNE die Ziel-Adresse zu laden – man landet auf dem Dashboard.
    // Deshalb zusätzlich die Seite selbst bitten zu navigieren (app.js hört darauf).
    try { client.postMessage({ type: 'navigate', url: target.href }); } catch (e) {}
    var go = ('navigate' in client)
      ? client.navigate(target.href).then(function (c) { return c || client; }).catch(function () { return null; })
      : Promise.resolve(null);
    return go.then(function (c) {
      if (c) return c.focus();
      // navigate() abgewiesen (unkontrolliertes Fenster) → neues Fenster am Ziel öffnen
      return self.clients.openWindow(target.href);
    });
  }));
});
