/*
 * Doppel-Submit-Schutz für ALLE Formulare – App und öffentliche Bereiche: Ein zweimal
 * gedrückter Abschicken-Knopf legt sonst denselben Beitrag doppelt an.
 *
 * Was passiert: Nach dem ersten Abschicken eines POST-Formulars werden alle seine
 * Abschick-Knöpfe sichtbar gesperrt (ausgegraut, Warte-Cursor) und weitere Abschick-
 * Versuche verworfen – der Klick „reagiert" damit sofort sichtbar, und ein zweiter
 * Klick richtet nichts mehr an.
 *
 * Drei Fallen, die dieses Skript kennt:
 *  1. Die Knöpfe dürfen erst NACH der Formular-Serialisierung deaktiviert werden
 *     (setTimeout 0): Ein im submit-Ereignis deaktivierter Knopf fällt aus den
 *     gesendeten Daten – bei Formularen mit zwei Abschick-Knöpfen (name/value am
 *     Knopf) käme sonst die falsche bzw. gar keine Aktion an.
 *  2. e.defaultPrevented respektieren: Bestätigungs-Dialoge (data-confirm der App,
 *     die Lösch-/Absage-Dialoge von was.läuft, data-ajax) fangen das erste submit
 *     selbst ab – das ist KEIN Absenden und darf nicht sperren.
 *  3. Zurück-Navigation aus dem bfcache stellt die Seite samt gesperrter Knöpfe
 *     wieder her (pageshow mit persisted) – dann alles wieder freigeben. Dazu ein
 *     8-Sekunden-Netz, falls der Versand hängt oder abgebrochen wird.
 *
 * Gesperrt wird über die CSSOM (style.opacity/cursor), nicht über style-Attribute im
 * Markup – das erlauben auch die strengen CSPs der öffentlichen Bereiche. Entsperrt
 * werden nur Knöpfe, die dieses Skript selbst gesperrt hat (data-dk-gesperrt) –
 * serverseitig deaktivierte Knöpfe bleiben deaktiviert.
 */
(function () {
  'use strict';

  function knoepfe(f) {
    return f.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
  }

  function frei(f) {
    f.removeAttribute('data-dk-sendet');
    f.querySelectorAll('[data-dk-gesperrt]').forEach(function (b) {
      b.disabled = false;
      b.style.opacity = '';
      b.style.cursor = '';
      b.removeAttribute('aria-busy');
      b.removeAttribute('data-dk-gesperrt');
    });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement)) return;
    if (String(f.method).toLowerCase() !== 'post') return;
    if (e.defaultPrevented) return;
    if (f.hasAttribute('data-dk-sendet')) { e.preventDefault(); return; }
    f.setAttribute('data-dk-sendet', '1');
    var gedrueckt = e.submitter || null;
    setTimeout(function () {   // erst nach der Serialisierung – siehe Falle 1 oben
      knoepfe(f).forEach(function (b) {
        if (b.disabled) return;   // war schon aus – nicht als unseren merken
        b.disabled = true;
        b.style.opacity = '.55';
        b.style.cursor = 'wait';
        b.setAttribute('data-dk-gesperrt', '1');
      });
      if (gedrueckt) gedrueckt.setAttribute('aria-busy', 'true');
    }, 0);
    setTimeout(function () { frei(f); }, 8000);   // Netz hängt / Abbruch: nicht ewig festsitzen
  });

  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    document.querySelectorAll('form[data-dk-sendet]').forEach(frei);
  });
})();
