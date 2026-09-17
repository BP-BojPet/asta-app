/* Service Worker von was.läuft – macht die Seite installierbar und hält sie offen benutzbar.
 *
 * Grundsatz: Termine MÜSSEN frisch sein. Seiten kommen deshalb immer erst aus dem Netz; der
 * Vorrat springt nur ein, wenn es keins gibt – dann die zuletzt gesehene Fassung der Seite,
 * andernfalls die Offline-Seite. Beiwerk (Stylesheet, Bilder, Schriften) darf aus dem Vorrat
 * kommen und frischt sich im Hintergrund auf – das Stylesheet trägt ohnehin seine Änderungszeit
 * in der Adresse (wl_css_url()), eine neue Fassung ist also automatisch eine neue Adresse.
 *
 * Bei JEDER Änderung an dieser Datei oder an offline.html die VERSION hochzählen – erst der
 * neue Name lässt den Browser die alten Vorräte wegräumen (siehe activate).
 */
'use strict';

const VERSION = 3;
const FEST = 'wl-fest-v' + VERSION;   // beim Installieren befüllt, fester Bestand
const LAUF = 'wl-lauf-v' + VERSION;   // wächst im Betrieb, wird gestutzt
const OFFLINE = 'offline.html';
const LAUF_MAX = 160;                 // Obergrenze, sonst sammeln sich die Veranstaltungsfotos

/* Kern-Bilder der Oberfläche gehören in den FESTEN Bestand (V3): Die installierte App hat
   ihren eigenen, anfangs leeren Speicher, und das FIFO-Stutzen des Lauf-Vorrats spült mit
   der Zeit ausgerechnet die ältesten Einträge raus – also genau diese Dauerbrenner. Beides
   zusammen lässt auf der Über-Seite sonst das AStA-Logo unter der Wisch-Animation fehlen:
   flatterndes Netz plus leerer Vorrat, und das <img> bleibt leer. */
const KERN = ['../brand.php?b=logo', '../brand.php?b=logo-verlauf', '../assets/wl-icon-192.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(FEST)
      .then(function (c) { return c.addAll([OFFLINE].concat(KERN)); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys()
      .then(function (namen) {
        return Promise.all(namen
          .filter(function (n) { return n !== FEST && n !== LAUF; })
          .map(function (n) { return caches.delete(n); }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

/* Älteste Einträge raus, bis die Grenze wieder stimmt (keys() liefert Einfüge-Reihenfolge). */
async function stutzen(cache) {
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - LAUF_MAX; i++) await cache.delete(keys[i]);
}

self.addEventListener('fetch', function (e) {
  const req = e.request;
  if (req.method !== 'GET') return;                              // Formulare gehen immer durch
  if (new URL(req.url).origin !== self.location.origin) return;  // Fremdes fasst der SW nicht an

  if (req.mode === 'navigate') {
    e.respondWith((async function () {
      try {
        const antwort = await fetch(req);
        if (antwort.ok) {
          const cache = await caches.open(LAUF);
          cache.put(req, antwort.clone()).then(function () { return stutzen(cache); });
        }
        return antwort;
      } catch (_) {
        const alt = await caches.match(req);
        return alt || caches.match(OFFLINE);
      }
    })());
    return;
  }

  e.respondWith((async function () {
    const cache = await caches.open(LAUF);
    // Lauf-Vorrat zuerst, dann der feste Bestand – der überlebt auch das Stutzen.
    const drin = (await cache.match(req)) || (await caches.match(req, { cacheName: FEST }));
    if (drin) {
      // Sofort liefern, im Hintergrund auffrischen – schlägt das fehl, war der Vorrat gut genug.
      e.waitUntil(fetch(req).then(function (antwort) {
        if (antwort.ok) {
          return cache.put(req, antwort.clone()).then(function () { return stutzen(cache); });
        }
      }).catch(function () {}));
      return drin;
    }
    try {
      const antwort = await fetch(req);
      if (antwort.ok) {
        cache.put(req, antwort.clone()).then(function () { return stutzen(cache); });
      }
      return antwort;
    } catch (fehler) {
      // Netz weg und nichts im Lauf-Vorrat: letzter Blick in ALLE Bestände, erst dann
      // scheitern lassen wie ohne Service Worker.
      const alt = await caches.match(req);
      if (alt) return alt;
      throw fehler;
    }
  })());
});

/* ---- Push-Mitteilungen („Erinnere mich" + Abos, siehe push.php) ------------------------------ */

self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) {}
  e.waitUntil(self.registration.showNotification(d.title || 'was.läuft', {
    body: d.body || '',
    icon: new URL('../assets/wl-icon-192.png', self.registration.scope).href,
    data: { url: d.url || self.registration.scope },
    tag: d.url || undefined,   // dieselbe Adresse ersetzt sich statt sich zu stapeln
    lang: 'de'
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var ziel = (e.notification.data && e.notification.data.url) || self.registration.scope;
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (liste) {
    for (var i = 0; i < liste.length; i++) {
      if (liste[i].url === ziel && 'focus' in liste[i]) return liste[i].focus();
    }
    return clients.openWindow(ziel);
  }));
});

/* Der Push-Dienst hat die Adresse des Geräts gewechselt: neu anmelden und dem Server melden,
   sonst liefen Erinnerungen und Abos still ins Leere. Läuft ohne Sitzung – push.php nimmt
   `sub` deshalb ohne Prüfmarke an. */
self.addEventListener('pushsubscriptionchange', function (e) {
  e.waitUntil((async function () {
    var alt = e.oldSubscription;
    if (!alt || !alt.options) return;
    var neu = await self.registration.pushManager.subscribe(alt.options);
    var j = neu.toJSON();
    var daten = new URLSearchParams();
    daten.set('was', 'sub');
    daten.set('endpoint', neu.endpoint);
    daten.set('p256dh', j.keys.p256dh);
    daten.set('auth', j.keys.auth);
    await fetch(new URL('push.php', self.registration.scope).href, { method: 'POST', body: daten });
  })());
});
