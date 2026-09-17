/* was.läuft: „Erinnere mich" + Abos (Web Push) – das Gegenstück zu veranstaltungen/push.php.
 *
 * Wird mit Nonce eingebunden (strenge CSP). Alles hängt an Knöpfen mit der Klasse .wl-push-btn:
 *   data-art="remind"  data-item="123"            → Erinnerung für einen Beitrag
 *   data-art="follow"  data-org="5" data-cat=""   → Abo (0/'' = alle)
 * Zustand „an" = Klasse .on am Knopf; die Beschriftung wechselt über zwei Spans (.pa/.pb),
 * die das Stylesheet je nach .on ein-/ausblendet – kein Text-Umschreiben nötig.
 *
 * Auf der Abo-Seite (abos.php) baut das Skript zusätzlich die Listen der eingerichteten
 * Erinnerungen und Abos aus dem Serverzustand (#wl-abos mit data-cats als Wörterbuch).
 *
 * iPhone-Besonderheit: Im Safari-Tab gibt es kein Web Push – erst in der installierten App
 * („Zum Home-Bildschirm"). Solche Meldungen erscheinen als eigener Dialog (info() unten) –
 * ein Kasten am Seitenende stünde unterhalb des Blickfelds.
 */
(function () {
  'use strict';

  var kann = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  var boot = null;   // {pub, csrf} aus push.php?was=boot
  var endpoint = ''; // Push-Adresse dieses Geräts, sobald bekannt

  /* Meldung als eigener Dialog mitten im Blick – ein Kasten am Seitenende ist auf den
     meisten Geräten schlicht unsichtbar. Der Dialog wird beim
     ersten Bedarf gebaut – kein Extra-Markup auf den Seiten nötig. mitApp=true blendet
     einen echten Knopf zur App-Seite ein (Anleitung + FAQ, auch für den Fall
     „Desktop-Browser kann/will kein Push"). */
  function info(text, mitApp) {
    var d = document.getElementById('wl-push-dialog');
    if (!d) {
      d = document.createElement('dialog');
      d.className = 'wl-dialog wl-push-dialog';
      d.id = 'wl-push-dialog';
      d.innerHTML =
        '<h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"' +
        ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M10 5.2a2 2 0 1 1 4 0 7 7 0 0 1 4 6.3v2.6a3.5 3.5 0 0 0 1.8 3H4.2a3.5 3.5 0 0 0 1.8-3v-2.6a7 7 0 0 1 4-6.3z"/>' +
        '<path d="M9.5 17.5v.5a2.5 2.5 0 0 0 5 0v-.5"/></svg>' +
        'Mitteilungen</h3><p></p><div class="wl-btns">' +
        '<button class="wl-btn" type="button">Schließen</button>' +
        '<a class="wl-btn p" href="app.php">So geht’s: die App</a></div>';
      d.querySelector('button').addEventListener('click', function () { d.close(); });
      document.body.appendChild(d);
    }
    d.querySelector('p').textContent = text;
    d.querySelector('a').hidden = !mitApp;
    if (d.showModal) d.showModal(); else alert(text);
  }

  function istIos() { return /iPhone|iPad|iPod/.test(navigator.userAgent); }

  function schluessel(b64) {
    var roh = atob((b64 + '==='.slice((b64.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
    var arr = new Uint8Array(roh.length);
    for (var i = 0; i < roh.length; i++) arr[i] = roh.charCodeAt(i);
    return arr;
  }

  async function holeBoot() {
    if (boot) return boot;
    var r = await fetch('push.php?was=boot');
    boot = await r.json();
    return boot;
  }

  async function frage(was, daten) {
    var b = await holeBoot();
    var form = new URLSearchParams();
    form.set('was', was);
    form.set('csrf', b.csrf);
    form.set('endpoint', endpoint);
    Object.keys(daten || {}).forEach(function (k) { form.set(k, String(daten[k])); });
    var r = await fetch('push.php', { method: 'POST', body: form });
    return r.json();
  }

  /* Bestehendes Geräte-Abo holen ($neu = bei Bedarf anlegen, fragt dann nach Erlaubnis). */
  async function geraet(neu) {
    var reg = await navigator.serviceWorker.ready;
    var sub = await reg.pushManager.getSubscription();
    if (!sub && neu) {
      var b = await holeBoot();
      if (!b.pub) throw new Error('Push steht auf dem Server nicht bereit.');
      var erlaubt = await Notification.requestPermission();
      if (erlaubt !== 'granted') {
        var fehler = new Error('Mitteilungen sind für diese Seite gerade blockiert – in den Browser-Einstellungen erlauben, dann klappt es.');
        fehler.mitApp = true;   // die App-Seite erklärt auch diesen Fall (FAQ)
        throw fehler;
      }
      sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: schluessel(b.pub) });
      var j = sub.toJSON();
      var form = new URLSearchParams();
      form.set('was', 'sub');
      form.set('endpoint', sub.endpoint);
      form.set('p256dh', j.keys.p256dh);
      form.set('auth', j.keys.auth);
      await fetch('push.php', { method: 'POST', body: form });
    }
    if (sub) endpoint = sub.endpoint;
    return sub;
  }

  function markiere(btn, an) {
    btn.classList.toggle('on', an);
    btn.setAttribute('aria-pressed', an ? 'true' : 'false');
  }

  // Die Bestätigung „wann klingelt es" als POP-OUT am Knopf statt als Dialog – nichts, das
  // man wegklicken muss: eine kleine Sprechblase über dem Knopf, die nach ein paar Sekunden
  // von selbst geht. position: fixed statt absolute,
  // damit kein Ahnen-Element sie beschneidet; sie lebt zu kurz, als dass Scrollen stört.
  function blase(btn, text) {
    var alt = document.querySelector('.wl-push-pop');
    if (alt) alt.remove();
    var b = document.createElement('div');
    b.className = 'wl-push-pop';
    b.setAttribute('role', 'status');
    b.textContent = text;
    document.body.appendChild(b);
    var r = btn.getBoundingClientRect();
    b.style.left = (r.left + r.width / 2) + 'px';
    b.style.top = (r.top - 10) + 'px';
    setTimeout(function () { b.classList.add('weg'); }, 4200);
    setTimeout(function () { b.remove(); }, 4900);
  }

  // „2026-09-10 18:30" → „am Mi., 10.9. um 18:30 Uhr" (heute/morgen sagen wir beim Namen).
  function wann(ra) {
    var d = new Date(ra.replace(' ', 'T'));
    if (isNaN(d)) return 'rechtzeitig vorher';
    var uhr = ra.slice(11, 16);
    var heute = new Date(); heute.setHours(0, 0, 0, 0);
    var tag = new Date(d); tag.setHours(0, 0, 0, 0);
    var diff = Math.round((tag - heute) / 86400000);
    if (diff === 0) return 'heute um ' + uhr + ' Uhr';
    if (diff === 1) return 'morgen um ' + uhr + ' Uhr';
    var wd = ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'][d.getDay()];
    return 'am ' + wd + ', ' + d.getDate() + '.' + (d.getMonth() + 1) + '. um ' + uhr + ' Uhr';
  }

  async function klick(btn) {
    if (!kann) {
      // Kein Web Push in diesem Browser: am iPhone normal (erst die installierte App
      // kann es), an manchen Desktop-Browsern auch – beide Wege erklärt die App-Seite.
      info(istIos()
        ? 'Auf dem iPhone gibt es Mitteilungen erst in der installierten App: Teilen-Symbol → „Zum Home-Bildschirm", dann hier noch einmal tippen.'
        : 'Dieser Browser unterstützt keine Mitteilungen – am Handy klappt es mit der installierten App zuverlässig.', true);
      return;
    }
    btn.disabled = true;
    try {
      await geraet(true);
      var an = !btn.classList.contains('on');
      var antwort;
      if (btn.dataset.art === 'remind') {
        antwort = await frage('remind', { item: btn.dataset.item, an: an ? '1' : '0' });
      } else {
        antwort = await frage('follow', { org: btn.dataset.org || '0', cat: btn.dataset.cat || '', an: an ? '1' : '0' });
      }
      if (antwort.ok) {
        markiere(btn, an);
        // Beim Einschalten sagen, WANN es klingelt – der Zeitpunkt kommt
        // fertig berechnet vom Server (2 Std. vor Beginn, ohne Uhrzeit am Tag um 9).
        if (btn.dataset.art === 'remind' && an && antwort.remind_at) {
          blase(btn, 'Wir erinnern dich ' + wann(antwort.remind_at) + '.');
        }
      }
      else info(antwort.msg || 'Das hat gerade nicht geklappt – bitte später noch einmal.');
    } catch (e) {
      info(e && e.message ? e.message : 'Das hat gerade nicht geklappt – bitte später noch einmal.', !!(e && e.mitApp));
    } finally {
      btn.disabled = false;
    }
  }

  /* ---- Abo-Seite: Listen aus dem Serverzustand bauen ---- */

  function zeile(text, weg) {
    var li = document.createElement('li');
    var span = document.createElement('span');
    span.textContent = text;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'wl-abo-weg';
    btn.textContent = 'Entfernen';
    btn.addEventListener('click', weg);
    li.appendChild(span);
    li.appendChild(btn);
    return li;
  }

  function baueListen(state) {
    var wrap = document.getElementById('wl-abos');
    if (!wrap) return;
    var cats = {};
    try { cats = JSON.parse(wrap.dataset.cats || '{}'); } catch (_) {}
    var er = document.getElementById('wl-erinnerungen');
    var ab = document.getElementById('wl-follows');
    er.textContent = '';
    ab.textContent = '';
    (state.reminders || []).forEach(function (r) {
      er.appendChild(zeile(r.title + ' — erinnert ' + r.remind_at.slice(11, 16) + ' Uhr am ' + r.remind_at.slice(8, 10) + '.' + r.remind_at.slice(5, 7) + '.', async function () {
        await frage('remind', { item: r.item_id, an: '0' });
        lade();
      }));
    });
    (state.follows || []).forEach(function (f) {
      var wer = f.org_id > 0 ? (f.org_name || 'Veranstalter #' + f.org_id) : 'Alle Veranstalter';
      var was = f.cat ? (cats[f.cat] || f.cat) : 'alles';
      ab.appendChild(zeile(wer + ' · ' + was, async function () {
        await frage('follow', { org: f.org_id, cat: f.cat, an: '0' });
        lade();
      }));
    });
    var leer = document.getElementById('wl-abos-leer');
    if (leer) leer.hidden = (state.reminders || []).length > 0 || (state.follows || []).length > 0;
  }

  async function lade() {
    if (!kann) return;
    try {
      var sub = await geraet(false);
      if (!sub) return;
      var state = await frage('state', {});
      if (!state.ok) return;
      document.querySelectorAll('.wl-push-btn').forEach(function (btn) {
        var an;
        if (btn.dataset.art === 'remind') {
          an = (state.reminders || []).some(function (r) { return String(r.item_id) === btn.dataset.item; });
        } else {
          an = (state.follows || []).some(function (f) {
            return String(f.org_id) === (btn.dataset.org || '0') && String(f.cat) === (btn.dataset.cat || '');
          });
        }
        markiere(btn, an);
      });
      baueListen(state);
    } catch (_) { /* Ohne Zustand bleiben die Knöpfe einfach im Grundzustand */ }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.wl-push-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { klick(btn); });
    });
    var aus = document.getElementById('wl-abo-aus');
    if (aus) {
      aus.addEventListener('click', async function () {
        if (!kann) return;
        if (!confirm('Wirklich alle Erinnerungen und Abos auf diesem Gerät abschalten?')) return;
        aus.disabled = true;
        try {
          var sub = await geraet(false);
          if (sub) {
            await frage('weg', {});
            await sub.unsubscribe();
            endpoint = '';
          }
          lade();
          var wrap = document.getElementById('wl-abos');
          if (wrap) baueListen({ reminders: [], follows: [] });
        } catch (_) {} finally { aus.disabled = false; }
      });
    }
    var neu = document.getElementById('wl-abo-neu');
    if (neu) {
      neu.addEventListener('click', async function () {
        if (!kann) { klick(neu); return; }
        neu.disabled = true;
        try {
          await geraet(true);
          var org = document.getElementById('wl-abo-org').value;
          var cat = document.getElementById('wl-abo-cat').value;
          var antwort = await frage('follow', { org: org, cat: cat, an: '1' });
          if (!antwort.ok) info(antwort.msg || 'Das hat gerade nicht geklappt.');
          lade();
        } catch (e) {
          info(e && e.message ? e.message : 'Das hat gerade nicht geklappt.', !!(e && e.mitApp));
        } finally { neu.disabled = false; }
      });
    }
    lade();
  });
})();
