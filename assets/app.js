/* AStA Eventplaner – eigene Dialoge statt Browser-Pop-ups */
(function () {
  function ensure() {
    var o = document.getElementById('asta-modal');
    if (o) return o;
    o = document.createElement('div');
    o.id = 'asta-modal';
    o.className = 'modal-overlay';
    o.hidden = true;
    o.innerHTML = '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="asta-modal-title">'
      + '<h3 class="modal-title" id="asta-modal-title"></h3>'
      + '<p class="modal-msg"></p><div class="modal-actions"></div></div>';
    document.body.appendChild(o);
    o.addEventListener('click', function (e) { if (e.target === o) close(); });
    document.addEventListener('keydown', function (e) {
      if (!o.hidden && e.key === 'Escape') close();
    });
    return o;
  }
  function close() { var o = document.getElementById('asta-modal'); if (o) o.hidden = true; }

  // Öffnet ein Modal. opts = {title, message, icon, buttons:[{label, class, onClick}],
  //                           list:[{label, sub, href, icon}],   – vertikale Auswahl-Liste (z. B. Sitzungen)
  //                           input:{label, placeholder}}        – Textfeld; Wert kommt als Argument in onClick
  function astaConfirm(opts) {
    var o = ensure();
    var t = o.querySelector('.modal-title');
    t.innerHTML = '';
    if (opts.icon) { var i = document.createElement('i'); i.className = 'ti ' + opts.icon; t.appendChild(i); }
    t.appendChild(document.createTextNode(' ' + (opts.title || 'Bist du sicher?')));
    o.querySelector('.modal-msg').textContent = opts.message || '';
    var oldList = o.querySelector('.modal-list');
    if (oldList) oldList.remove();
    var oldInput = o.querySelector('.modal-input-wrap');
    if (oldInput) oldInput.remove();
    var inputEl = null;
    if (opts.input) {
      var iw = document.createElement('div');
      iw.className = 'modal-input-wrap';
      if (opts.input.label) { var lb = document.createElement('label'); lb.className = 'modal-input-label'; lb.textContent = opts.input.label; iw.appendChild(lb); }
      inputEl = document.createElement('textarea');
      inputEl.className = 'modal-input';
      inputEl.rows = 2;
      inputEl.placeholder = opts.input.placeholder || '';
      inputEl.addEventListener('input', function () { inputEl.classList.remove('input-missing'); });
      iw.appendChild(inputEl);
      o.querySelector('.modal-msg').after(iw);
    }
    var oldCheck = o.querySelector('.modal-check-wrap');
    if (oldCheck) oldCheck.remove();
    var checkEl = null;
    if (opts.check) { // optionale Zusatz-Checkbox im Dialog (z. B. „auch in Teams löschen")
      var cw = document.createElement('label');
      cw.className = 'modal-check-wrap small';
      checkEl = document.createElement('input');
      checkEl.type = 'checkbox';
      if (opts.check.checked) checkEl.checked = true;
      cw.appendChild(checkEl);
      cw.appendChild(document.createTextNode(' ' + (opts.check.label || '')));
      o.querySelector('.modal-msg').after(cw);
    }
    if (opts.list && opts.list.length) {
      var wrap = document.createElement('div');
      wrap.className = 'modal-list';
      opts.list.forEach(function (it) {
        var a = document.createElement('a');
        a.className = 'modal-item';
        a.href = it.href || '#';
        var ic = document.createElement('i'); ic.className = 'ti ' + (it.icon || 'ti-chevron-right') + ' mi-ic'; a.appendChild(ic);
        var tx = document.createElement('span'); tx.className = 'mi-text';
        var main = document.createElement('span'); main.className = 'mi-main'; main.textContent = it.label || ''; tx.appendChild(main);
        if (it.sub) { var sub = document.createElement('span'); sub.className = 'mi-sub'; sub.textContent = it.sub; tx.appendChild(sub); }
        a.appendChild(tx);
        var go = document.createElement('i'); go.className = 'ti ti-arrow-right mi-go'; a.appendChild(go);
        a.addEventListener('click', function () { close(); if (it.onClick) { it.onClick(); return false; } });
        wrap.appendChild(a);
      });
      o.querySelector('.modal-msg').after(wrap);
    }
    var act = o.querySelector('.modal-actions');
    act.innerHTML = '';
    (opts.buttons || []).forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn ' + (b.class || 'secondary');
      btn.textContent = b.label;
      btn.addEventListener('click', function () {
        var v = inputEl ? inputEl.value : undefined;
        if (b.requireInput && inputEl && inputEl.value.trim() === '') { // Pflichtfeld: leer -> Modal bleibt offen
          inputEl.classList.add('input-missing');
          inputEl.focus();
          return;
        }
        close(); if (b.onClick) b.onClick(v, checkEl ? checkEl.checked : undefined);
      });
      act.appendChild(btn);
    });
    o.hidden = false;
    var first = act.querySelector('button');
    if (first) first.focus();
  }
  window.astaConfirm = astaConfirm;

  // Design: System / Hell / Dunkel (Klick wechselt durch)
  function effectiveTheme(mode) {
    if (mode === 'system') return (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    return mode;
  }
  // Skin-Registry: kommt aus lib.php (app_skins) über das Theme-Bootstrap-Script (window.ASTA_SKINS).
  // Je Skin: base (dark|light – worauf der Skin aufsetzt), tc (theme-color), on (freigeschaltet).
  function SKINS() { return window.ASTA_SKINS || {}; }
  // Browser-/PWA-Statusleiste (meta theme-color) ans aktive Theme angleichen
  function syncThemeColor() {
    var h = document.documentElement, sk = h.getAttribute('data-skin'), reg = SKINS();
    var col = (sk && reg[sk]) ? reg[sk].tc
            : (h.getAttribute('data-theme') === 'dark' ? '#111518' : '#ffffff');
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.setAttribute('content', col);
  }
  function applyThemeMode(mode) {
    var h = document.documentElement, sk = SKINS()[mode];
    h.setAttribute('data-theme-mode', mode);
    if (sk) {
      h.setAttribute('data-theme', sk.base); h.setAttribute('data-skin', mode);
    } else {
      h.removeAttribute('data-skin');
      h.setAttribute('data-theme', effectiveTheme(mode));
    }
    syncThemeColor();
    try { localStorage.setItem('asta-theme-mode', mode); localStorage.removeItem('asta-theme'); } catch (e) {}
  }
  window.astaSetTheme = applyThemeMode; // für „Ausprobieren" eines Skin-Toasts
  function royalUnlocked() {
    var b = document.querySelector('.bulb');
    return !!(b && b.getAttribute('data-royal') === '1');
  }
  // Client-ausgelöstes Achievement melden (z. B. Flashbang) und den Toast direkt zeigen.
  function fireAch(code) {
    if (!window.ASTA_ACH_EQUIP || !window.ASTA_CSRF) return;
    var body = new FormData();
    body.append('action', 'fire'); body.append('code', code); body.append('csrf', window.ASTA_CSRF);
    fetch(window.ASTA_ACH_EQUIP, { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.unlocks && d.unlocks.length && window.astaShowAch) window.astaShowAch(d.unlocks); })
      .catch(function () {});
  }
  function themeOrder() {
    var order = ['system', 'light', 'dark'], reg = SKINS();
    Object.keys(reg).forEach(function (k) { if (reg[k].on) order.push(k); }); // freigeschaltete Skins in Registry-Reihenfolge
    return order;
  }
  // „Pretty in Pink": Diadem + rosa Farbe hat der Server bestätigt (ASTA_PINK_READY);
  // sobald dazu der Pink-Skin aktiv ist, ist das Rosa-Outfit komplett → Achievement feuern.
  function checkPinkDream() {
    if (!window.ASTA_PINK_READY) return;
    if (document.documentElement.getAttribute('data-skin') !== 'pink') return;
    window.ASTA_PINK_READY = 0;
    fireAch('pinkdream');
  }
  function pickTheme(mode) {
    var cur = document.documentElement.getAttribute('data-theme-mode') || 'system';
    applyThemeMode(mode);
    checkPinkDream();
    // Flashbang: nachts (0–4 Uhr) aktiv in den Hellmodus geschaltet, obwohl vorher nicht hell.
    if (mode === 'light' && cur !== 'light' && new Date().getHours() < 4) fireAch('flashbang');
    // Chamäleon: alle verfügbaren Designs (inkl. Auto) in einer Browser-Sitzung durchprobiert.
    try {
      var seen = JSON.parse(sessionStorage.getItem('asta-themes-seen') || '[]');
      if (seen.indexOf(mode) < 0) { seen.push(mode); sessionStorage.setItem('asta-themes-seen', JSON.stringify(seen)); }
      var all = themeOrder().every(function (m) { return seen.indexOf(m) >= 0; });
      if (all && !sessionStorage.getItem('asta-chameleon')) { sessionStorage.setItem('asta-chameleon', '1'); fireAch('chameleon'); }
    } catch (e) {}
    try { localStorage.setItem('asta-bulb-seen', '1'); localStorage.setItem('asta-royal-seen', '1'); } catch (e) {}
    Array.prototype.forEach.call(document.querySelectorAll('.bulb'), function (b) { b.classList.remove('bulb-hint', 'royal-hint'); });
  }
  window.astaPickTheme = function (mode) { pickTheme(mode); closeThemeMenus(); }; // Direktwahl aus dem Menü
  // Beim Laden prüfen: Pink-Skin war evtl. schon aktiv, als Diadem/Farbe angelegt wurden
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', checkPinkDream);
  else checkPinkDream();
  window.astaCycleTheme = function () {
    var order = themeOrder();
    var cur = document.documentElement.getAttribute('data-theme-mode') || 'system';
    var idx = order.indexOf(cur); if (idx < 0) idx = 0;
    pickTheme(order[(idx + 1) % order.length]);
  };
  // Einmalige Auto-Skin-Anwendung (Sommerkönig-Unlock / Skin-Geschenk): Skin genau einmal aktivieren,
  // danach dem Server melden (push.php → skin_applied), damit das Pending-Feld geleert wird und es nicht erneut greift.
  (function () {
    var sk = window.ASTA_AUTO_SKIN;
    if (!sk || !SKINS()[sk]) return;
    if (document.documentElement.getAttribute('data-skin') !== sk) applyThemeMode(sk);
    var url = (window.ASTA_ACH_EQUIP || 'achievements.php').replace(/achievements\.php$/, '') + 'push.php';
    var body = new FormData();
    body.append('action', 'skin_applied'); body.append('csrf', window.ASTA_CSRF || '');
    fetch(url, { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch' } }).catch(function () {});
  })();
  function closeThemeMenus() {
    Array.prototype.forEach.call(document.querySelectorAll('.theme-menu, .ns-theme-menu'), function (m) { m.hidden = true; });
    Array.prototype.forEach.call(document.querySelectorAll('.bulb[aria-haspopup], .ns-theme[aria-haspopup]'), function (b) { b.setAttribute('aria-expanded', 'false'); });
  }
  // Mobile („Mehr"-Sheet): Direktwahl-Menü nach OBEN auf/zu (statt Durchschalten). Items nutzen astaPickTheme.
  window.astaNsTheme = function (btn) {
    var wrap = btn.closest ? btn.closest('.ns-theme-wrap') : null;
    var menu = wrap && wrap.querySelector('.ns-theme-menu');
    if (!menu) { astaCycleTheme(); return; }
    var wasOpen = !menu.hidden;
    closeThemeMenus();
    if (!wasOpen) {
      var curMode = document.documentElement.getAttribute('data-theme-mode') || 'system';
      Array.prototype.forEach.call(menu.querySelectorAll('.tm-item'), function (it) { it.classList.toggle('active', it.getAttribute('data-mode') === curMode); });
      // Höhe auf den Platz ÜBER dem Knopf begrenzen, sonst schneidet das Sheet (overflow:auto) oben ab → intern scrollen
      var panel = wrap.closest ? wrap.closest('.navsheet-panel') : null;
      if (panel) {
        var avail = btn.getBoundingClientRect().top - panel.getBoundingClientRect().top - 12;
        menu.style.maxHeight = Math.max(140, avail) + 'px';
      }
      menu.hidden = false;
      var act = menu.querySelector('.tm-item.active'); // aktiven Eintrag in den Sichtbereich holen
      if (act && act.scrollIntoView) act.scrollIntoView({ block: 'nearest' });
      btn.setAttribute('aria-expanded', 'true');
    }
  };
  // Glühbirne: ab 4 Modi Direktwahl-Menü auf/zu, sonst klassisch durchschalten.
  window.astaThemeBulb = function (btn) {
    var wrap = btn.closest ? btn.closest('.theme-switch') : null;
    var menu = wrap && wrap.querySelector('.theme-menu');
    if (!menu) { astaCycleTheme(); return; }
    var wasOpen = !menu.hidden;
    closeThemeMenus();
    if (!wasOpen) {
      var curMode = document.documentElement.getAttribute('data-theme-mode') || 'system';
      Array.prototype.forEach.call(menu.querySelectorAll('.tm-item'), function (it) { it.classList.toggle('active', it.getAttribute('data-mode') === curMode); });
      menu.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
    }
  };
  document.addEventListener('click', function (e) { if (!(e.target.closest && e.target.closest('.theme-switch, .ns-theme-wrap'))) closeThemeMenus(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeThemeMenus(); });

  // Kleine Aufmerksamkeits-Animation der Glühbirne (nur bis zur ersten Nutzung)
  window.astaBulbHint = function () {
    try { if (localStorage.getItem('asta-bulb-seen')) return; } catch (e) {}
    var b = document.querySelector('.bulb');
    if (!b) return;
    b.classList.add('bulb-hint');
    setTimeout(function () { b.classList.remove('bulb-hint'); }, 4000);
  };

  // „Royal freigeschaltet"-Plop: einmalig, sobald jemand Spitzenklasse hat (goldene Variante)
  window.astaRoyalHint = function () {
    if (!royalUnlocked()) return;
    try { if (localStorage.getItem('asta-royal-seen')) return; } catch (e) {}
    var b = document.querySelector('.bulb');
    if (!b) return;
    b.classList.add('bulb-hint', 'royal-hint');
    setTimeout(function () { b.classList.remove('bulb-hint', 'royal-hint'); }, 4500);
  };
  // „System": Änderungen der OS-Einstellung live übernehmen
  try {
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if ((document.documentElement.getAttribute('data-theme-mode') || 'system') === 'system') {
        document.documentElement.setAttribute('data-theme', effectiveTheme('system'));
        syncThemeColor();
      }
    });
  } catch (e) {}

  // Kauf-Dialog des Belohnungs-Lockers: EIN Klick auf die Kachel, dann die Wahl des Zahlwegs.
  // Die Ecken-Marken sind reine HINWEISE, kein anklickbares 👑: Ein Fehlgriff dort verbrauchte
  // sofort die Royal-Beschlagnahme, die es nur alle drei Monate gibt. Props wie Krone fragen
  // im zweiten Schritt noch einmal nach.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement)) return;
    var roh = f.getAttribute('data-kauf');
    if (!roh) return;
    e.preventDefault();
    var d;
    try { d = JSON.parse(roh); } catch (err) { f.submit(); return; }
    function absenden(weg) { // f.submit() löst kein submit-Event aus -> keine Schleife
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'pay'; h.value = weg;
      f.appendChild(h);
      f.submit();
    }
    var ast = (d.preis || 0).toLocaleString('de-DE');
    var knoepfe = [{ label: 'Abbrechen', class: 'secondary' }];
    if (d.astOk) {
      knoepfe.push({ label: 'Für ' + ast + ' AsT kaufen', onClick: function () { absenden('ast'); } });
    }
    if (d.propsOk) {
      knoepfe.push({
        label: d.propsPreis + ' Props einlösen 🙌',
        onClick: function () {
          astaConfirm({
            title: 'Props einlösen?',
            icon: 'ti-heart-handshake',
            message: 'Props sind der Dank anderer für deine Arbeit – ' + d.propsPreis + ' davon kannst du gegen ein '
              + 'beliebiges Stück eintauschen, egal was es in AsT kostet. Die eingelösten Props sind danach weg; '
              + 'in deinem Profil siehst du weiterhin, wie viele du insgesamt bekommen hast. Du hast gerade '
              + d.props + '.',
            buttons: [
              { label: 'Doch nicht', class: 'secondary' },
              { label: d.propsPreis + ' Props einlösen', onClick: function () { absenden('props'); } }
            ]
          });
        }
      });
    }
    if (d.royal) {
      knoepfe.push({
        label: 'Beschlagnahmen 👑',
        onClick: function () {
          astaConfirm({
            title: 'Im Namen der Krone?',
            icon: 'ti-crown',
            message: 'Als Royal darfst du dir alle drei Monate EIN Stück einfach nehmen – ohne AsT, ohne Props. '
              + 'Danach ist das Privileg drei Monate lang aufgebraucht, auch wenn du es für etwas Kleines '
              + 'benutzt hast. Das Stück bleibt dir übrigens, selbst wenn die Krone später weiterwandert.',
            buttons: [
              { label: 'Lieber aufheben', class: 'secondary' },
              { label: 'Beschlagnahmen', onClick: function () { absenden('royal'); } }
            ]
          });
        }
      });
    }
    astaConfirm({
      title: d.label,
      icon: 'ti-shopping-cart',
      message: knoepfe.length > 1
        ? 'Wie möchtest du das bezahlen?'
        : 'Dafür fehlen dir noch ' + Math.max(0, (d.preis || 0) - (d.ast || 0)).toLocaleString('de-DE')
          + ' AsT. Dein Depot wächst mit jedem Tausch in der Börse – oder du sammelst ' + d.propsPreis + ' Props.',
      buttons: knoepfe
    });
  }, true);

  // Formulare mit data-confirm="…" -> eigenes Bestätigungs-Modal
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement)) return;
    var msg = f.getAttribute('data-confirm');
    if (!msg) return;
    e.preventDefault();
    var danger = f.hasAttribute('data-confirm-danger');
    // Optionale Checkbox im Dialog: data-confirm-check="Label" (+ data-confirm-check-name,
    // Standard "confirm_check") -> angehakt wird ein Hidden-Feld mit Wert 1 mitgeschickt
    var checkLabel = f.getAttribute('data-confirm-check');
    astaConfirm({
      title: f.getAttribute('data-confirm-title') || 'Bist du sicher?',
      icon: danger ? 'ti-alert-triangle' : 'ti-help-circle',
      message: msg,
      check: checkLabel ? { label: checkLabel } : undefined,
      buttons: [
        { label: f.getAttribute('data-confirm-cancel') || 'Abbrechen', class: 'secondary' },
        {
          label: f.getAttribute('data-confirm-ok') || 'Bestätigen',
          class: danger ? 'danger' : '',
          onClick: function (v, checked) { // f.submit() löst kein submit-Event aus -> keine Schleife
            if (checkLabel && checked) {
              var h = document.createElement('input');
              h.type = 'hidden';
              h.name = f.getAttribute('data-confirm-check-name') || 'confirm_check';
              h.value = '1';
              f.appendChild(h);
            }
            f.submit();
          }
        }
      ]
    });
  }, true);

  // Frisch Spitzenklasse geworden -> einmalig den Royal-Modus aktivieren, damit man ihn gleich sieht.
  // Danach gilt wieder die freie Wahl (kein erneutes Erzwingen, bis Spitzenklasse verloren+neu erreicht).
  if (window.ASTA_ROYAL_AUTO && royalUnlocked()) {
    if ((document.documentElement.getAttribute('data-theme-mode') || 'system') !== 'royal') applyThemeMode('royal');
    try { localStorage.setItem('asta-royal-seen', '1'); } catch (e) {} // Plop nicht zusätzlich nötig
  } else if (window.ASTA_ROYAL_HINT) { astaRoyalHint(); } else if (window.ASTA_BULB_HINT) { astaBulbHint(); }
})();

/* Sticky Topbar: Schatten erst, sobald gescrollt wurde */
(function () {
  var tb = document.querySelector('.topbar');
  if (!tb) return;
  function update() { tb.classList.toggle('is-scrolled', window.scrollY > 4); }
  window.addEventListener('scroll', update, { passive: true });
  update();
})();

/* Toasts (Flash-Meldungen): Fortschrittsbalken, Auto-Ausblendung, Schließen-Knopf.
   window.astaToast(msg, type) erzeugt zusätzlich Toasts aus JS (z. B. nach fetch-Saves). */
(function () {
  function initToast(t, box) {
    var ttl = parseInt(t.getAttribute('data-ttl'), 10) || 5500;
    var bar = t.querySelector('.toast-bar');
    if (bar) bar.style.animation = 'toast-bar ' + ttl + 'ms linear forwards';
    var gone = false, timer = setTimeout(hide, ttl);
    function hide() {
      if (gone) return; gone = true;
      clearTimeout(timer);
      t.classList.add('toast-out');
      setTimeout(function () {
        t.remove();
        if (!box.querySelector('.toast')) box.remove();
      }, 240);
    }
    var x = t.querySelector('.toast-x');
    if (x) x.addEventListener('click', hide);
    // Zeigefinger drauf = lesen wollen: Auto-Ausblendung pausieren
    t.addEventListener('mouseenter', function () {
      clearTimeout(timer);
      if (bar) bar.style.animationPlayState = 'paused';
    });
    t.addEventListener('mouseleave', function () {
      if (gone) return;
      if (bar) bar.style.animationPlayState = 'running';
      timer = setTimeout(hide, 2200);
    });
  }
  var box = document.querySelector('.toasts');
  if (box) Array.prototype.forEach.call(box.querySelectorAll('.toast'), function (t) { initToast(t, box); });

  window.astaToast = function (msg, type) {
    var b = document.querySelector('.toasts');
    if (!b) {
      b = document.createElement('div');
      b.className = 'toasts';
      b.setAttribute('aria-live', 'polite');
      document.body.appendChild(b);
    }
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'info');
    t.setAttribute('data-ttl', type === 'error' ? 9000 : 3500);
    t.innerHTML = '<i class="ti ' + ({ success: 'ti-circle-check', error: 'ti-alert-circle' }[type] || 'ti-info-circle') + '"></i>'
      + '<div class="toast-msg"></div>'
      + '<button type="button" class="toast-x" aria-label="Meldung schließen"><i class="ti ti-x"></i></button>'
      + '<span class="toast-bar"></span>';
    t.querySelector('.toast-msg').textContent = msg;
    b.appendChild(t);
    initToast(t, b);
  };

  // Fertig gebauten Toast-Knoten einhängen (für den eigenen Achievement-Toast mit Aktion).
  window.astaToastNode = function (node, ttl) {
    var b = document.querySelector('.toasts');
    if (!b) { b = document.createElement('div'); b.className = 'toasts'; b.setAttribute('aria-live', 'polite'); document.body.appendChild(b); }
    if (ttl) node.setAttribute('data-ttl', String(ttl));
    b.appendChild(node);
    initToast(node, b);
  };
})();

/* Achievement-Freischaltungen: eigener Toast mit „Ausprobieren"-Aktion.
   Server reicht window.ASTA_ACH_UNLOCKS ein (page_header). Royal/auto-Belohnungen sind
   schon serverseitig angewandt und zeigen keinen Button. */
(function () {
  // Skins, die per astaSetTheme direkt anwendbar sind: alle aus der Registry außer Royal (das aktiviert sich selbst)
  function skinApplicable(key) { var reg = window.ASTA_SKINS || {}; return key !== 'royal' && !!reg[key]; }
  // Global, damit auch client-ausgelöste Achievements (fireAch) den Toast sofort zeigen können.
  window.astaShowAch = function (arr) {
    if (!window.astaToastNode || !arr || !arr.length) return;
    arr.forEach(function (a, i) { setTimeout(function () { showAch(a); }, i * 350); });
  };
  if (window.ASTA_ACH_UNLOCKS && window.ASTA_ACH_UNLOCKS.length) window.astaShowAch(window.ASTA_ACH_UNLOCKS);

  function showAch(a) {
    var t = document.createElement('div');
    t.className = 'toast toast-ach';
    var ic = document.createElement('span');
    ic.className = 'toast-ach-ic';
    if (a.color) ic.style.background = a.color;
    ic.innerHTML = '<i class="ti ' + (a.icon || 'ti-trophy') + '"></i>';
    var body = document.createElement('div');
    body.className = 'toast-ach-body';
    var kicker = document.createElement('div'); kicker.className = 'toast-ach-kicker'; kicker.textContent = 'Achievement freigeschaltet';
    var title = document.createElement('div'); title.className = 'toast-ach-title'; title.textContent = a.title || '';
    body.appendChild(kicker); body.appendChild(title);
    if (a.auto) {
      var ra = document.createElement('div'); ra.className = 'toast-ach-reward';
      if (a.reward_type === 'skin') {                 // Auto-Skin (z. B. Hochsommer beim Sommerkönig)
        ra.innerHTML = '<i class="ti ti-palette"></i> ';
        ra.appendChild(document.createTextNode((a.reward_label || 'App-Design') + ' – automatisch aktiviert'));
      } else if (a.svg) {                             // Auto-Schmuck (z. B. Krone bei der Spitzenklasse)
        var pv2 = document.createElement('span'); pv2.className = 'toast-ach-acc'; pv2.innerHTML = a.svg;
        ra.appendChild(pv2);
        ra.appendChild(document.createTextNode(' ' + (a.reward_label || 'Belohnung') + ' aktiviert'));
      } else {
        ra.innerHTML = '<i class="ti ti-crown"></i> ';
        ra.appendChild(document.createTextNode('Krone & Royal-Design aktiviert'));
      }
      body.appendChild(ra);
    } else if (a.reward_key && a.svg) {
      var rd = document.createElement('div'); rd.className = 'toast-ach-reward';
      var pv = document.createElement('span'); pv.className = 'toast-ach-acc'; pv.innerHTML = a.svg;
      rd.appendChild(pv);
      rd.appendChild(document.createTextNode(' ' + (a.reward_label || 'Belohnung')));
      body.appendChild(rd);
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'btn small toast-ach-try';
      btn.innerHTML = '<i class="ti ti-sparkles"></i> Ausprobieren';
      btn.addEventListener('click', function () { equip(a, btn); });
      body.appendChild(btn);
    } else if (a.reward_type === 'skin' && a.reward_label) {
      var rs = document.createElement('div'); rs.className = 'toast-ach-reward';
      rs.innerHTML = '<i class="ti ti-palette"></i> ';
      rs.appendChild(document.createTextNode(a.reward_label));
      body.appendChild(rs);
      if (skinApplicable(a.reward_key) && window.astaSetTheme) {   // direkt anwendbarer Skin (z. B. TrueBlack)
        var sb = document.createElement('button');
        sb.type = 'button'; sb.className = 'btn small toast-ach-try';
        sb.innerHTML = '<i class="ti ti-sparkles"></i> Ausprobieren';
        sb.addEventListener('click', function () { window.astaSetTheme(a.reward_key); sb.innerHTML = '<i class="ti ti-check"></i> Aktiviert'; sb.classList.add('done'); });
        body.appendChild(sb);
      }
    } else if (a.reward_label) {
      var rl = document.createElement('div'); rl.className = 'toast-ach-reward';
      rl.innerHTML = '<i class="ti ti-gift"></i> ';
      rl.appendChild(document.createTextNode(a.reward_label));
      body.appendChild(rl);
    }
    var x = document.createElement('button'); x.type = 'button'; x.className = 'toast-x'; x.setAttribute('aria-label', 'Schließen');
    x.innerHTML = '<i class="ti ti-x"></i>';
    var bar = document.createElement('span'); bar.className = 'toast-bar';
    t.appendChild(ic); t.appendChild(body); t.appendChild(x); t.appendChild(bar);
    window.astaToastNode(t, a.auto ? 7000 : 12000);
  }

  function equip(a, btn) {
    btn.disabled = true;
    var body = new FormData();
    body.append('action', 'equip_deco');
    body.append('deco', a.reward_key);
    body.append('csrf', window.ASTA_CSRF || '');
    fetch(window.ASTA_ACH_EQUIP || 'achievements.php', { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        btn.innerHTML = '<i class="ti ti-check"></i> Ausgerüstet';
        btn.classList.add('done');
        var av = document.querySelector('.hero .avatar');   // Live am Hero-Avatar zeigen (falls sichtbar)
        if (av) {
          // Slots sind kombinierbar: nur das Stück derselben Trage-Position ersetzen, andere bleiben an
          var old = av.querySelector('.avatar-acc.acc-' + (a.slot || 'head')); if (old) old.remove();
          var d = document.createElement('span'); d.className = 'avatar-acc acc-' + (a.slot || 'head') + ' acc-k-' + (a.reward_key || ''); d.title = 'Avatar-Schmuck'; d.innerHTML = a.svg;
          av.appendChild(d);
          if ((a.slot || 'head') === 'head') { var c = av.querySelector('.avatar-crown'); if (c) c.style.display = 'none'; } // Band-Krone weicht der Kopfbedeckung
        }
      })
      .catch(function () { btn.disabled = false; if (window.astaToast) astaToast('Konnte nicht ausrüsten.', 'error'); });
  }
})();

/* Streak-Flammen-Animation: brennt einmal auf, wenn beim Öffnen der App eine neue
   Flamme dazugekommen ist. Server reicht window.ASTA_STREAK_GAIN + die Flammen-SVG
   (window.ASTA_FLAME_SVG, gleiche Quelle wie im Hero) ein – Session-Einmal-Wert.
   Beim Meilenstein (Stufenwechsel: 3/7/30/50) erst "+1" in der alten Stufe, dann
   Druckwelle und die Flamme verwandelt sich in die neue, heißere Stufe. */
(function () {
  var n = parseInt(window.ASTA_STREAK_GAIN, 10) || 0;
  if (n <= 0) return;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var FLAME = window.ASTA_FLAME_SVG || '<i class="ti ti-flame-filled" style="font-size:88px;color:var(--fl1,#ff7a1f)"></i>';
  var AURA = window.ASTA_FLAME_AURA || '';
  // Zwilling von streak_tier() in lib.php – Schwellen 3/7/30/50/100. Wer dort eine ändert,
  // ändert sie hier mit; der Selbsttest vergleicht beide Listen.
  function tier(c) { return c < 3 ? 0 : (c < 7 ? 1 : (c < 30 ? 2 : (c < 50 ? 3 : (c < 100 ? 4 : 5)))); } // brennt ab 3 Tagen
  // Meilenstein-Sprüche passend zum gewählten Streak-Stil ('' Flamme | heart | bolt | star | coffee | flower | rakete | diamant | regenbogen | mond | schein)
  var STYLE = window.ASTA_FLAME_STYLE || '';
  // Stufe 5 (100 Tage) ist der Moment, in dem der Stil aus seiner Kachel heraustritt – die
  // Sprüche sagen genau das, und das Overlay bekommt dazu die Aura (siehe unten).
  var MILES = {
    'heart':  { 1: 'Das Herz ist entflammt!', 2: 'Das Herz schlägt heißer!', 3: 'Das Herz lodert!',    4: 'Unaufhaltsam!',      5: 'Herzflimmern!' },
    'bolt':   { 1: 'Der Blitz zuckt!',        2: 'Hochspannung!',            3: 'Es kracht gewaltig!', 4: 'Unaufhaltsam!',      5: 'Alles unter Strom!' },
    'star':   { 1: 'Der Stern funkelt!',      2: 'Er strahlt heller!',       3: 'Sternstunde!',        4: 'Unaufhaltsam!',      5: 'Ein ganzes Sternbild!' },
    'coffee': { 1: 'Der Kaffee dampft!',      2: 'Noch ein Espresso!',       3: 'Vollkoffeiniert!',    4: 'Unaufhaltsam!',      5: 'Der ganze Laden dampft!' },
    'flower': { 1: 'Es keimt!',               2: 'Die Knospe wird dick!',    3: 'Sie blüht auf!',      4: 'In voller Blüte!',   5: 'Alles blüht!' },
    'rakete': { 1: 'Zündung!',                2: 'Abheben!',                 3: 'Volle Schubkraft!',   4: 'Ab zum Mond!',       5: 'Raus aus der Umlaufbahn!' },
    'diamant':{ 1: 'Der Rohstein glitzert!',  2: 'Frisch geschliffen!',      3: 'Er funkelt!',         4: 'Lupenrein!',         5: 'Ein Solitär!' },
    'regenbogen': { 1: 'Der erste Streifen!', 2: 'Es wird bunter!',          3: 'Alle Farben da!',     4: 'Er leuchtet!',       5: 'Ein doppelter Regenbogen!' },
    'mond':   { 1: 'Eine schmale Sichel!',    2: 'Halbmond!',                3: 'Er wird runder!',     4: 'Fast voll!',         5: 'Vollmond mit Hof!' },
    'schein': { 1: 'Der Schein glimmt!',      2: 'Er wird golden!',          3: 'Die Strahlen kommen!', 4: 'Es strahlt!',       5: 'Heiliger Schein!' }
  };
  var MILE = MILES[STYLE] || { 1: 'Die Flamme ist entzündet!', 2: 'Die Flamme brennt heißer!', 3: 'Die Flamme lodert!', 4: 'Unaufhaltsam!', 5: 'Weißglut!' };
  function show() {
    var tNew = tier(n), tOld = tier(n - 1);
    var milestone = tNew > tOld && !reduce; // bei reduced-motion direkt die Endstufe zeigen
    var wrap = document.createElement('div');
    wrap.className = 'streak-pop st' + (milestone ? tOld : tNew) + (reduce ? ' calm' : '') + (STYLE ? ' ' + STYLE : '');
    wrap.setAttribute('aria-hidden', 'true');
    var embers = '';
    if (!reduce && tNew >= 1) for (var i = 0; i < 10; i++) {
      embers += '<span class="sp-ember" style="--dx:' + (Math.random() * 150 - 75).toFixed(0) + 'px;--dur:' + (1.1 + Math.random()).toFixed(2) + 's;--del:' + (0.5 + Math.random() * 1.1).toFixed(2) + 's"></span>';
    }
    wrap.innerHTML = '<div class="sp-stage">' + embers + FLAME + '<span class="sp-ring"></span><div class="sp-plus">+1</div></div>'
      + '<div class="sp-count">' + n + '</div><div class="sp-label">' + (n === 1 ? 'Tag' : 'Tage') + ' Streak</div>'
      + (milestone ? '<div class="sp-mile">' + MILE[tNew] + '</div>' : '');
    document.body.appendChild(wrap);
    // Ab Stufe 5 strahlt der Stil aus seinem Feld heraus – hier ist das Feld das ganze Overlay
    // (Markup kommt fertig vom Server, damit Aura und Hero garantiert gleich aussehen).
    function auraOn() {
      if (!AURA || reduce) return;
      wrap.classList.add('aura-feld', 'aura-' + (STYLE || 'flame'));
      wrap.insertAdjacentHTML('afterbegin', AURA);
    }
    var ttl = reduce ? 1700 : 2600;
    if (milestone) {
      ttl = 4300; // erst "+1" wirken lassen, dann bei ~1.6s die Verwandlung zünden
      setTimeout(function () {
        wrap.classList.remove('st' + tOld);
        wrap.classList.add('st' + tNew, 'evolve');
        if (tNew >= 5) auraOn();
      }, 1600);
    } else if (tNew >= 5) {
      auraOn();
    }
    setTimeout(function () { wrap.classList.add('out'); }, ttl);
    setTimeout(function () { wrap.remove(); }, ttl + 600);
    // Ist im selben Aufschlag auch das Props-Kontingent wieder da, kommt es direkt danach dran
    if (window.astaPropsPop) setTimeout(window.astaPropsPop, ttl + 500);
  }
  // kurz warten, bis die Seite steht – die Flamme soll auf fertigem Layout zünden
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(show, 300); });
  else setTimeout(show, 300);
})();

/* Props-Kontingent wieder aufgefüllt (neuer Monat): kleine Geste im selben Overlay-Stil.
   Läuft NACH dem Streak-Splash, wenn beides zusammenfällt (der Normalfall am Monatsanfang),
   sonst allein – sonst ginge die Nachricht verloren, wenn der Streak an dem Tag ruht. */
(function () {
  if (!window.ASTA_PROPS_RESET) return;
  var left = parseInt(window.ASTA_PROPS_LEFT, 10) || 0;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var done = false;
  function pop() {
    if (done) return;
    done = true;
    var wrap = document.createElement('div');
    wrap.className = 'props-pop' + (reduce ? ' calm' : '');
    wrap.setAttribute('aria-hidden', 'true');
    var hands = '';
    if (!reduce) for (var i = 0; i < 6; i++) {
      hands += '<span class="pp-spark" style="--dx:' + (Math.random() * 140 - 70).toFixed(0) + 'px;--d:'
        + (Math.random() * 0.5).toFixed(2) + 's"></span>';
    }
    wrap.innerHTML = '<div class="pp-stage">' + hands + '<div class="pp-hands">🙌</div></div>'
      + '<div class="pp-title">Props sind wieder verfügbar</div>'
      + '<div class="pp-sub">' + (left === 1 ? 'Du hast 1 Prop' : 'Du hast ' + left + ' Props') + ' für diesen Monat</div>';
    document.body.appendChild(wrap);
    var ttl = reduce ? 1700 : 2600;
    setTimeout(function () { wrap.classList.add('out'); }, ttl);
    setTimeout(function () { wrap.remove(); }, ttl + 600);
  }
  window.astaPropsPop = pop;
  // Kein Streak-Splash an diesem Tag? Dann selbst zünden.
  if (!(parseInt(window.ASTA_STREAK_GAIN, 10) > 0)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(pop, 400); });
    else setTimeout(pop, 400);
  }
})();

/* Einstellungs-Formulare ohne Seiten-Reload (data-ajax): POST per fetch mit
   X-Requested-With, der Server antwortet 204, die UI kippt lokal. Schlägt der
   fetch fehl, wird klassisch abgeschickt (Fallback ohne Funktionsverlust). */
(function () {
  function applyLocal(f) {
    var action = (f.querySelector('input[name="action"]') || {}).value || '';
    if (action === 'set_pref') {                 // Mail-/Push-Chip kippen
      var chip = f.querySelector('.chan-chip');
      var val = f.querySelector('input[name="value"]');
      if (!chip || !val) return;
      var nowOn = val.value === '1';             // dieser Wert wurde gerade gespeichert
      chip.classList.toggle('on', nowOn);
      var icons = chip.querySelectorAll('i.ti');
      if (icons.length) icons[icons.length - 1].className = 'ti ' + (nowOn ? 'ti-check' : 'ti-x');
      val.value = nowOn ? '0' : '1';             // nächster Klick schaltet zurück
    } else if (action === 'toggle_reminder') {   // Master An/Aus + Chips ein-/ausblenden
      var btn = f.querySelector('.belltoggle');
      if (!btn) return;
      var on = !btn.classList.contains('on');
      btn.classList.toggle('on', on);
      btn.innerHTML = '<i class="ti ' + (on ? 'ti-bell-ringing' : 'ti-bell-off') + '"></i> ' + (on ? 'An' : 'Aus');
      var item = f.closest('.chan-item');
      var chips = item ? item.querySelector('.chan-chips') : null;
      if (chips) chips.hidden = !on;
      // Einsatz-Master: Einschalten setzt serverseitig Vorlauf 1 Tag -> Select angleichen
      var which = (f.querySelector('input[name="which"]') || {}).value;
      if (which === 'shift' && on && item) {
        var sel = item.querySelector('.timing-form select');
        if (sel) sel.value = '1';
      }
    } // set_timing/set_lead: das Select zeigt den neuen Wert bereits selbst
  }
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement) || !f.hasAttribute('data-ajax')) return;
    e.preventDefault();
    fetch(f.getAttribute('action') || location.pathname, {
      method: 'POST',
      body: new FormData(f),
      headers: { 'X-Requested-With': 'fetch' }
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      applyLocal(f);
      if (window.astaToast) astaToast('Gespeichert.', 'success');
    }).catch(function () {
      f.removeAttribute('data-ajax');            // Fallback: klassischer Submit mit Reload
      f.submit();
    });
  }, true);
})();

/* Score-Ringe: conic-gradient füllt sich beim Laden bis zum Zielwert (data-ring, 0–100) */
(function () {
  var rings = document.querySelectorAll('.score-ring[data-ring]');
  if (!rings.length) return;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  Array.prototype.forEach.call(rings, function (r) {
    var target = Math.max(0, Math.min(100, parseFloat(r.getAttribute('data-ring')) || 0));
    if (reduce) { r.style.setProperty('--rp', String(target)); return; }
    var t0 = null;
    function step(ts) {
      if (!t0) t0 = ts;
      var k = Math.min(1, (ts - t0) / 900);
      k = 1 - Math.pow(1 - k, 3); // ease-out
      r.style.setProperty('--rp', (target * k).toFixed(2));
      if (k < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  });
})();

/* Kennzahlen-Kacheln: Zahlen zählen beim Laden hoch (nur reine Zahlen, deutsches Komma ok) */
(function () {
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  Array.prototype.forEach.call(document.querySelectorAll('.tile .num'), function (el) {
    var m = /^(\d+)(?:,(\d))?$/.exec((el.textContent || '').trim());
    if (!m) return;
    var target = parseFloat(m[1] + (m[2] ? '.' + m[2] : ''));
    if (!target) return;
    var dec = !!m[2], t0 = null;
    function fmt(v) { return dec ? v.toFixed(1).replace('.', ',') : String(Math.round(v)); }
    function step(ts) {
      if (!t0) t0 = ts;
      var k = Math.min(1, (ts - t0) / 350);
      k = 1 - Math.pow(1 - k, 3);
      el.textContent = fmt(target * k);
      if (k < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  });
})();

/* Eigener Datums-Selektor (.adp): leichter Kalender-Popover ohne Fremdbibliothek.
   Markup: <div class="adp"><input class="adp-in" readonly><input type="hidden" name="…"></div>
   Funktioniert auch für dynamisch ergänzte Felder: window.astaDatePicker(wrapper). */
(function () {
  var MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
  var TAGE = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function de(d) { return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear(); }

  function attach(wrap) {
    if (!wrap || wrap.dataset.adp) return;
    wrap.dataset.adp = '1';
    var input = wrap.querySelector('.adp-in');
    var hidden = wrap.querySelector('input[type=hidden]');
    if (!input || !hidden) return;
    if (hidden.value) { var v0 = new Date(hidden.value + 'T12:00:00'); if (!isNaN(v0)) input.value = de(v0); }
    var pop = null, view = null;

    function close() { if (pop) { pop.remove(); pop = null; document.removeEventListener('click', onDoc, true); document.removeEventListener('keydown', onKey, true); } }
    function onDoc(e) { if (pop && !wrap.contains(e.target)) close(); }
    function onKey(e) { if (e.key === 'Escape') { close(); input.focus(); } }

    function render() {
      var sel = hidden.value ? new Date(hidden.value + 'T12:00:00') : null;
      var today = new Date();
      var y = view.getFullYear(), m = view.getMonth();
      var first = new Date(y, m, 1);
      var lead = (first.getDay() + 6) % 7;              // Mo=0 … So=6
      var days = new Date(y, m + 1, 0).getDate();
      var html = '<div class="adp-head">'
        + '<button type="button" class="adp-nav" data-d="-1" aria-label="Voriger Monat"><i class="ti ti-chevron-left"></i></button>'
        + '<strong>' + MONATE[m] + ' ' + y + '</strong>'
        + '<button type="button" class="adp-nav" data-d="1" aria-label="Nächster Monat"><i class="ti ti-chevron-right"></i></button></div>'
        + '<div class="adp-grid">';
      TAGE.forEach(function (t) { html += '<span class="adp-dow">' + t + '</span>'; });
      for (var i = 0; i < lead; i++) html += '<span></span>';
      for (var d = 1; d <= days; d++) {
        var cur = new Date(y, m, d);
        var cls = 'adp-day';
        if (today.getFullYear() === y && today.getMonth() === m && today.getDate() === d) cls += ' is-today';
        if (sel && sel.getFullYear() === y && sel.getMonth() === m && sel.getDate() === d) cls += ' is-sel';
        html += '<button type="button" class="' + cls + '" data-iso="' + iso(cur) + '">' + d + '</button>';
      }
      html += '</div><div class="adp-foot"><button type="button" class="adp-today">Heute</button>'
        + (hidden.value ? '<button type="button" class="adp-clear">Leeren</button>' : '') + '</div>';
      pop.innerHTML = html;
      pop.querySelectorAll('.adp-nav').forEach(function (b) {
        b.addEventListener('click', function () { view = new Date(view.getFullYear(), view.getMonth() + parseInt(b.dataset.d, 10), 1); render(); });
      });
      pop.querySelectorAll('.adp-day').forEach(function (b) {
        b.addEventListener('click', function () { set(b.dataset.iso); close(); });
      });
      pop.querySelector('.adp-today').addEventListener('click', function () { set(iso(new Date())); close(); });
      var clr = pop.querySelector('.adp-clear');
      if (clr) clr.addEventListener('click', function () { hidden.value = ''; input.value = ''; close(); });
    }
    function set(v) {
      hidden.value = v;
      input.value = de(new Date(v + 'T12:00:00'));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function open() {
      if (pop) { close(); return; }
      view = hidden.value ? new Date(hidden.value + 'T12:00:00') : new Date();
      view = new Date(view.getFullYear(), view.getMonth(), 1);
      pop = document.createElement('div');
      pop.className = 'adp-pop';
      wrap.appendChild(pop);
      render();
      document.addEventListener('click', onDoc, true);
      document.addEventListener('keydown', onKey, true);
    }
    input.addEventListener('click', open);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
  }

  window.astaDatePicker = attach;
  Array.prototype.forEach.call(document.querySelectorAll('.adp'), attach);
})();

/* PWA-Nutzung stempeln: läuft die App gerade installiert (Standalone-Modus), einmal pro Tag
   an den Server melden – die Aktivitätsstatistik zeigt dann, wer die PWA wirklich nutzt. */
(function () {
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  if (!standalone || !window.ASTA_CSRF) return;
  var today = new Date().toISOString().slice(0, 10);
  try { if (localStorage.getItem('asta-pwa-ping') === today) return; } catch (e) {}
  // Pfad aus dem bekannten Endpoint ableiten, damit der Ping auch aus Unterseiten (admin/) trifft
  var url = (window.ASTA_ACH_EQUIP || 'achievements.php').replace(/achievements\.php$/, '') + 'push.php';
  var body = new FormData();
  body.append('action', 'pwa_ping'); body.append('csrf', window.ASTA_CSRF);
  fetch(url, { method: 'POST', body: body })
    .then(function (r) { if (r.ok) { try { localStorage.setItem('asta-pwa-ping', today); } catch (e) {} } })
    .catch(function () {});
})();

/* „Sommer verschenken": Auswahl auf das Kontingent (data-max) begrenzen –
   ist das Maximum erreicht, lassen sich weitere Kästchen nicht mehr anhaken. */
(function () {
  var box = document.querySelector('.gift-people[data-max]');
  if (!box) return;
  var max = parseInt(box.getAttribute('data-max'), 10) || 1;
  var msgs = document.getElementById('gift-messages'); // je ausgewählter Person ein eigenes Nachrichtenfeld
  function syncMessages() {
    if (!msgs) return;
    box.querySelectorAll('input[type=checkbox]').forEach(function (c) {
      var id = c.value, row = msgs.querySelector('[data-for="' + id + '"]');
      if (c.checked && !row) {
        row = document.createElement('div'); row.className = 'gift-msg-row'; row.setAttribute('data-for', id);
        var lbl = document.createElement('label'); lbl.className = 'gift-msg-label';
        lbl.textContent = 'Deine Nachricht an ' + (c.getAttribute('data-name') || 'diese Person');
        var ta = document.createElement('textarea');
        ta.name = 'msg[' + id + ']'; ta.rows = 2; ta.maxLength = 500; ta.className = 'input';
        ta.placeholder = 'z. B. wofür du dich bedankst …';
        row.appendChild(lbl); row.appendChild(ta); msgs.appendChild(row);
      } else if (!c.checked && row) {
        row.remove();
      }
    });
  }
  function sync() {
    var checked = box.querySelectorAll('input[type=checkbox]:checked').length;
    box.querySelectorAll('input[type=checkbox]').forEach(function (c) {
      c.disabled = !c.checked && checked >= max;
      c.closest('.gift-pick').classList.toggle('is-off', c.disabled);
    });
    syncMessages();
  }
  box.addEventListener('change', sync); sync();
})();

/* Web Push: gemeinsame Aktivierung, Schalter (erinnerungen.php) und
   Erst-Abfrage beim ersten Start der installierten App (Dashboard). */
(function () {
  function supported() {
    return ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
  }
  function b64ToU8(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4);
    var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }
  function post(action, sub, csrf) {
    return fetch('push.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ csrf: csrf || '', action: action, subscription: JSON.stringify(sub) })
    });
  }
  function fail(msg) {
    if (window.astaConfirm) {
      astaConfirm({ title: 'Push-Benachrichtigungen', icon: 'ti-bell-x', message: msg, buttons: [{ label: 'Ok' }] });
    }
  }
  // Aktivierung – MUSS aus einer Nutzer-Geste (Klick) heraus laufen, sonst blockt iOS.
  function enable(vapid, csrf) {
    return Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') {
        var e = new Error('Der Browser hat die Benachrichtigungs-Berechtigung nicht erteilt. Auf dem iPhone geht Push nur aus der installierten App (Home-Bildschirm); im Browser ggf. die Website-Einstellungen prüfen.');
        e.denied = true; throw e;
      }
      return navigator.serviceWorker.ready;
    }).then(function (reg) {
      return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToU8(vapid) });
    }).then(function (sub) {
      return post('subscribe', sub, csrf).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) { sub.unsubscribe(); throw new Error('Server hat das Abo abgelehnt.'); }
        return true;
      });
    });
  }

  // --- Schalter auf erinnerungen.php ---
  var btn = document.getElementById('push-toggle');
  if (btn && supported() && btn.getAttribute('data-vapid')) {
    btn.hidden = false; // Browser kann Push -> Schalter zeigen, „nicht unterstützt"-Hinweis weg
    var unsupported = document.getElementById('push-unsupported');
    if (unsupported) unsupported.hidden = true;
    var on = false, busy = false;
    var paint = function () {
      btn.classList.toggle('on', on);
      var ic = btn.querySelector('.ti');
      if (ic) ic.className = 'ti ' + (on ? 'ti-bell-ringing' : 'ti-bell-off');
    };
    // Klick auf eine Push-Mitteilung: Auf iOS kann der Service Worker das Fenster nicht selbst
  // umlenken (kein navigate(), openWindow() fokussiert nur) – dann schickt er uns die Ziel-Adresse
  // und wir springen hier hin. Nur eigene Adressen, damit nichts Fremdes untergeschoben werden kann.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (ev) {
      var d = ev.data || {};
      if (!d || d.type !== 'navigate' || typeof d.url !== 'string') return;
      try {
        var u = new URL(d.url, location.href);
        if (u.origin !== location.origin) return;
        if (u.href !== location.href) location.href = u.href;
      } catch (e) {}
    });
  }

  navigator.serviceWorker.ready
      .then(function (reg) { return reg.pushManager.getSubscription(); })
      .then(function (sub) { on = !!sub; paint(); })
      .catch(function () {});
    btn.addEventListener('click', function () {
      if (busy) return;
      busy = true;
      var done = function () { busy = false; };
      if (on) { // abschalten: erst Server-Eintrag weg, dann Browser-Abo kündigen
        navigator.serviceWorker.ready.then(function (reg) {
          return reg.pushManager.getSubscription();
        }).then(function (sub) {
          if (!sub) return null;
          return post('unsubscribe', sub, btn.getAttribute('data-csrf')).then(function () { return sub.unsubscribe(); });
        }).then(function () { on = false; paint(); }).catch(function (e) {
          fail('Push konnte nicht abgeschaltet werden: ' + (e && e.message ? e.message : e));
        }).then(done);
      } else {
        enable(btn.getAttribute('data-vapid'), btn.getAttribute('data-csrf')).then(function () {
          on = true; paint();
        }).catch(function (e) {
          fail(e && e.denied ? e.message : 'Push konnte nicht aktiviert werden: ' + (e && e.message ? e.message : e));
        }).then(done);
      }
    });
  }

  // --- Erst-Abfrage: einmalig beim Start der installierten App (Dashboard setzt ASTA_PUSH_PROMPT) ---
  var cfg = window.ASTA_PUSH_PROMPT;
  if (!cfg || !cfg.vapid || !supported() || !window.astaConfirm) return;
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  if (!standalone) return;                       // nur in der installierten App fragen
  if (Notification.permission !== 'default') return; // schon erlaubt/abgelehnt -> nichts fragen
  try { if (localStorage.getItem('asta-push-asked')) return; } catch (e) {}
  navigator.serviceWorker.ready
    .then(function (reg) { return reg.pushManager.getSubscription(); })
    .then(function (sub) {
      if (sub) return; // hat schon ein Abo
      var asked = function () { try { localStorage.setItem('asta-push-asked', '1'); } catch (e) {} };
      setTimeout(function () {
        astaConfirm({
          title: 'Benachrichtigungen aktivieren?',
          icon: 'ti-bell-ringing',
          message: 'Bekomme Nachrichten und Erinnerungen direkt aufs Gerät – wie von einer richtigen App. Du kannst das jederzeit unter „Erinnerungen & Mitteilungen" ändern.',
          buttons: [
            { label: 'Später', class: 'secondary', onClick: asked },
            { label: 'Aktivieren', onClick: function () {
                asked();
                enable(cfg.vapid, cfg.csrf).catch(function (e) {
                  fail(e && e.denied ? e.message : 'Push konnte nicht aktiviert werden: ' + (e && e.message ? e.message : e));
                });
            } }
          ]
        });
      }, 900); // kurz ankommen lassen, dann fragen
    }).catch(function () {});
})();

/* PWA-Installation: Karte im Dashboard (Android = Button, iOS = Schritte) */
(function () {
  var card = document.getElementById('install-card');
  if (!card) return;
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  if (standalone) { card.hidden = true; return; }           // schon installiert -> Karte weg

  // Android/Chrome/Edge: echtes Installations-Prompt abfangen und Button zeigen
  var deferred = null;
  var go = card.querySelector('.install-go');
  var hint = card.querySelector('.install-go-hint');
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferred = e;
    if (go) go.hidden = false;
    if (hint) hint.hidden = true;   // Button da -> Menü-Hinweis nicht nötig
  });
  if (go) go.addEventListener('click', function () {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.then(function () { deferred = null; go.hidden = true; });
  });
})();

/* Hamburger-Menü: Navigation auf mittleren Breiten (Tablet/schmales Fenster) ein-/ausklappen */
(function () {
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('mainnav');
  if (!toggle || !nav) return;
  var icon = toggle.querySelector('i');
  toggle.addEventListener('click', function () {
    var open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
    if (icon) icon.className = open ? 'ti ti-x' : 'ti ti-menu-2';
  });
})();

/* App-Öffnungen zählen (Achievement-Serie „Stammgast"/„Praktisch eingezogen"/„Touch Grass"):
   Es geht nur um Spaß, also zählt JEDES echte Öffnen – auch im Sekundentakt und nach Force-Close.
   Zeit-, Storage- und Referrer-Ansätze taugen dafür nicht: iOS stellt sessionStorage UND den
   Referrer der letzten Seite nach einem Neustart wieder her. Verlässlich ist nur ein einziger
   Unterschied: pagehide feuert bei NAVIGATION in der App, aber NICHT beim Force-Close (der
   Prozess stirbt). Also:
   – pagehide → Zeitstempel in localStorage („gerade normal weiternavigiert").
   – Seite geladen und der Stempel ist >3 s alt (oder fehlt) → ÖFFNUNG (Navigation wäre frisch).
   – visibilitychange → sichtbar → ÖFFNUNG (Zurück aus dem Hintergrund, egal wie schnell).
   – pageshow mit persisted (Seite aus dem Speicher wiederhergestellt) → ÖFFNUNG.
   Alle Pfade laufen durch dieselbe Navigations-Sperre (navRecent in fire) – der Server
   dedupliziert bewusst nicht, er liefert nur frische Unlocks als Toast zurück. */
(function () {
  if (!window.ASTA_CSRF) return; // nur für Eingeloggte
  var KEY = 'asta-nav-at', PEND = 'asta-open-pending';
  var url = (window.ASTA_ACH_EQUIP || 'achievements.php').replace(/achievements\.php$/, '') + 'push.php';
  // Öffnungen landen erst in einer LOKALEN Warteschlange und werden dann gemeldet – friert iOS
  // den Request beim schnellen Wieder-Schließen ein (Hauptursache verlorener Zählungen), wird
  // der Rest einfach beim nächsten Öffnen nachgemeldet. fetch mit keepalive überlebt zusätzlich
  // das Wegwischen während des Sendens.
  function pending() { try { return Math.max(0, parseInt(localStorage.getItem(PEND) || '0', 10)); } catch (e) { return 0; } }
  function setPending(n) { try { localStorage.setItem(PEND, String(Math.max(0, n))); } catch (e) {} }
  var flushing = false;
  function flush() {
    var n = pending();
    if (!n || flushing) return;
    flushing = true;
    var body = new FormData();
    body.append('action', 'app_open'); body.append('n', String(n)); body.append('csrf', window.ASTA_CSRF);
    fetch(url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) setPending(pending() - n); // nur das Gemeldete abziehen (neue Öffnungen bleiben)
        if (d && d.unlocks && d.unlocks.length && window.astaShowAch) window.astaShowAch(d.unlocks);
      })
      .catch(function () {}) // Fehler: Warteschlange bleibt, nächster Versuch beim nächsten Öffnen
      .finally(function () { flushing = false; });
  }
  // Frischer pagehide-Stempel = wir sind mitten in einer Navigation INNERHALB der App. Muss in
  // JEDEM Zähl-Pfad geprüft werden, nicht nur beim Laden: Safari feuert beim Laden jeder Seite
  // zusätzlich ein focus-Event, und die Zurück-Taste stellt Seiten aus dem Seiten-Speicher wieder
  // her (pageshow persisted) – beides sind Navigations-Artefakte, keine Öffnungen. Der Stempel
  // bleibt deshalb liegen (nicht löschen – die Artefakte kommen NACH dem Lade-Check).
  function navRecent() {
    try { return Date.now() - parseInt(localStorage.getItem(KEY) || '0', 10) <= 3000; } catch (e) { return false; }
  }
  // Doppel-Events derselben Öffnung (Laden + Sichtbar-Werden + Fokus) fängt ein 0,7-s-Puffer;
  // ansonsten zählt JEDES Sichtbar-/Fokus-Werden – iOS liefert je nach Situation nur eins davon.
  var lastFire = 0;
  function fire() {
    if (navRecent()) return; // Seitenwechsel in der App – keine Öffnung
    var now = Date.now();
    if (now - lastFire < 700) return;
    lastFire = now;
    setPending(pending() + 1);
    flush();
  }
  try {
    if (!navRecent()) fire(); else flush(); // Seitenwechsel: nicht zählen, aber Liegengebliebenes melden
  } catch (e) {}
  window.addEventListener('pagehide', function () {
    try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
  });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) fire(); // sichtbar geworden = Öffnung, egal wie schnell hintereinander
  });
  window.addEventListener('focus', fire); // iOS-Resume feuert manchmal nur focus, nicht visibilitychange
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) fire(); // aus dem Seiten-Speicher wiederhergestellt (iOS-Resume)
  });
  // Letzte Verteidigungslinie: iOS friert die Seite bei schnellen App-Wechseln oft OHNE jedes
  // Event ein und taut sie ebenso stumm wieder auf. Aber: eingefrorene Timer stehen still.
  // Ein Sekunden-Ticker, dessen letzter Schlag plötzlich >2,5 s her ist, beweist die Lücke →
  // die App war zwischendurch zu/im Hintergrund → das Auftauen ist eine Öffnung.
  var lastTick = Date.now();
  setInterval(function () {
    var now = Date.now();
    if (now - lastTick > 2500) fire();
    lastTick = now;
  }, 1000);
})();

/* Namens-Dropdown in der Titelleiste: bei Klick außerhalb oder Escape schließen */
(function () {
  var dd = document.querySelector('.nav-user');
  if (!dd) return;
  document.addEventListener('click', function (e) { if (dd.open && !dd.contains(e.target)) dd.open = false; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') dd.open = false; });
})();

/* Bottom-Tab-Bar „Mehr": Bottom-Sheet mit den restlichen Menüpunkten */
(function () {
  var more = document.querySelector('.tabbar .tab-more');
  var sheet = document.getElementById('navsheet');
  if (!more || !sheet) return;
  var isOpen = false, hideTimer = null;
  function setOpen(open) {
    isOpen = open;
    clearTimeout(hideTimer);
    if (open) {
      sheet.hidden = false;
      // zwei Frames warten, damit die transform-Transition wirklich anläuft
      requestAnimationFrame(function () { requestAnimationFrame(function () { sheet.classList.add('open'); }); });
    } else {
      sheet.classList.remove('open');
      hideTimer = setTimeout(function () { sheet.hidden = true; }, 260);
    }
    more.setAttribute('aria-expanded', open ? 'true' : 'false');
    more.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
    var icon = more.querySelector('i');
    if (icon) icon.className = open ? 'ti ti-x' : 'ti ti-menu-2';
    var lbl = more.querySelector('span');
    if (lbl) lbl.textContent = open ? 'Schließen' : 'Mehr';
  }
  more.addEventListener('click', function () { setOpen(!isOpen); });
  sheet.addEventListener('click', function (e) {
    if (e.target.classList && e.target.classList.contains('navsheet-backdrop')) setOpen(false);
  });
  var x = sheet.querySelector('.navsheet-x');
  if (x) x.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen) setOpen(false); });
})();

/* „Mehr anzeigen": lange Abschnitte (.clamp) einklappen, Knopf nur wenn nötig.

   Gemessen wird bei JEDER Gelegenheit, bei der sich die Höhe ändern kann – einmal beim Start
   genügt nicht: Zu dem Zeitpunkt stehen die Schriften oft noch nicht, der Browser baut den Text
   mit der Ersatzschrift, und der Abschnitt ist kurz zu hoch. Der Knopf stünde dann da, ohne
   etwas zu tun. Die Schwelle liegt bewusst über ein paar Pixeln: Für vier Pixel lohnt kein Knopf. */
(function () {
  document.querySelectorAll('.clamp').forEach(function (c) {
    var btn = c.nextElementSibling;
    if (!btn || !btn.classList.contains('clamp-toggle')) return;

    function pruefen() {
      // Ist der Abschnitt aufgeklappt, wird der Knopf zum Zuklappen gebraucht – dann ist
      // scrollHeight gleich clientHeight und die Messung würde ihn fälschlich ausblenden.
      if (c.classList.contains('is-open')) return;
      var mehr = c.scrollHeight - c.clientHeight > 16;   // erst ab einer ganzen Zeile
      c.classList.toggle('is-clamped', mehr);
      btn.hidden = !mehr;
    }

    btn.addEventListener('click', function () {
      var open = c.classList.toggle('is-open');
      btn.innerHTML = open
        ? '<i class="ti ti-chevron-up"></i> Weniger anzeigen'
        : '<i class="ti ti-chevron-down"></i> Mehr anzeigen';
      if (!open) pruefen();
    });

    pruefen();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(pruefen);
    window.addEventListener('load', pruefen);           // Bilder im Abschnitt
    var t;
    window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(pruefen, 150); });
  });
})();

/* Tagesordnungs-Editor: Live-Zeilen mit Einrücken (1.1) statt nur Textbox */
(function () {
  document.querySelectorAll('.agenda-editor').forEach(function (box) {
    var ta = box.querySelector('textarea.ag-data');
    if (!ta) return;
    ta.hidden = true; // progressive enhancement – ohne JS bleibt die Textbox nutzbar

    var rowsEl = document.createElement('div'); rowsEl.className = 'ag-rows';
    var bar = document.createElement('div'); bar.className = 'btn-row'; bar.style.gap = '.4rem';
    var add = document.createElement('button');
    add.type = 'button'; add.className = 'btn secondary small';
    add.innerHTML = '<i class="ti ti-plus"></i> Punkt hinzufügen';
    var addInt = document.createElement('button');
    addInt.type = 'button'; addInt.className = 'btn secondary small';
    addInt.innerHTML = '<i class="ti ti-lock"></i> Interner Teil';
    bar.appendChild(add); bar.appendChild(addInt);
    box.appendChild(rowsEl); box.appendChild(bar);

    function parse(text) {
      var m = [], haveMain = false, hasDiv = false;
      (text || '').split(/\r\n|\r|\n/).forEach(function (l) {
        var trimmed = l.trim();
        if (trimmed === '') return;
        if (trimmed.toUpperCase() === '[INTERN]') { if (!hasDiv) { m.push({ divider: true }); hasDiv = true; } return; }
        var sub = /^(\t| {2,}|[-•]\s)/.test(l);
        var t = l.replace(/^(\t+| +|[-•]\s)/, '').trim();
        if (t === '') return;
        var time = '';
        var pipe = t.indexOf(' | ');
        if (pipe !== -1) { time = t.slice(pipe + 3).trim(); t = t.slice(0, pipe).trim(); }
        if (t === '') return;
        var isSub = sub && haveMain;
        if (!isSub) haveMain = true;
        m.push({ text: t, sub: isSub, time: time });
      });
      return m;
    }
    var model = parse(ta.value);
    if (!model.length) model = [{ text: '', sub: false, time: '' }];

    function hasDivider() { return model.some(function (x) { return x.divider; }); }
    function meta() {
      var maj = 0, min = 0, haveMain = false, internal = false, res = [];
      model.forEach(function (it) {
        if (it.divider) { internal = true; res.push({ divider: true }); return; }
        var isSub = it.sub && haveMain;
        if (isSub) { min++; res.push({ num: maj + '.' + min, internal: internal, sub: true }); }
        else { haveMain = true; maj++; min = 0; res.push({ num: 'TOP ' + maj, internal: internal, sub: false }); }
      });
      return res;
    }
    function serialize() {
      var out = [], haveMain = false;
      model.forEach(function (it) {
        if (it.divider) { out.push('[INTERN]'); return; }
        var t = (it.text || '').trim();
        if (t === '') return;
        var isSub = it.sub && haveMain;
        if (!isSub) haveMain = true;
        var time = (it.time || '').trim();
        out.push((isSub ? '\t' : '') + t + (time ? ' | ' + time : ''));
      });
      return out.join('\n');
    }
    function sync() { ta.value = serialize(); }
    function refresh() {
      var mt = meta();
      rowsEl.querySelectorAll('.ag-row').forEach(function (r) {
        var i = +r.getAttribute('data-idx'); var m = mt[i] || {};
        var s = r.querySelector('.ag-num'); if (s) s.textContent = m.num || '';
        r.classList.toggle('is-sub', !!m.sub);
        r.classList.toggle('is-internal', !!m.internal);
      });
      addInt.disabled = hasDivider();
    }
    function focusModel(idx) { var r = rowsEl.querySelector('.ag-row[data-idx="' + idx + '"]'); if (r) { var inp = r.querySelector('input'); if (inp) inp.focus(); } }
    function btn(cls, title, icon) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'ag-btn ' + cls; b.title = title;
      b.innerHTML = '<i class="ti ' + icon + '"></i>'; return b;
    }
    function swap(i, j) { var t = model[i]; model[i] = model[j]; model[j] = t; render(); sync(); }
    function render() {
      rowsEl.innerHTML = '';
      var mt = meta();
      model.forEach(function (it, idx) {
        if (it.divider) {
          var dv = document.createElement('div'); dv.className = 'ag-divrow';
          var lbl = document.createElement('span'); lbl.className = 'ag-divlabel'; lbl.innerHTML = '<i class="ti ti-lock"></i> Interner Teil ab hier';
          dv.appendChild(lbl);
          var u0 = btn('', 'Nach oben', 'ti-chevron-up'); u0.disabled = idx === 0;
          u0.addEventListener('click', function () { swap(idx, idx - 1); });
          var d0 = btn('', 'Nach unten', 'ti-chevron-down'); d0.disabled = idx === model.length - 1;
          d0.addEventListener('click', function () { swap(idx, idx + 1); });
          var x0 = btn('ag-del', 'Trenner entfernen', 'ti-trash');
          x0.addEventListener('click', function () { model.splice(idx, 1); render(); sync(); });
          dv.appendChild(u0); dv.appendChild(d0); dv.appendChild(x0);
          rowsEl.appendChild(dv);
          return;
        }
        var m = mt[idx];
        var row = document.createElement('div'); row.className = 'ag-row' + (m.sub ? ' is-sub' : '') + (m.internal ? ' is-internal' : '');
        row.setAttribute('data-idx', idx);
        var num = document.createElement('span'); num.className = 'ag-num'; num.textContent = m.num; row.appendChild(num);

        var ind = btn('', it.sub ? 'Ausrücken' : 'Einrücken (Unterpunkt)', it.sub ? 'ti-arrow-bar-left' : 'ti-arrow-bar-right');
        ind.addEventListener('click', function () { model[idx].sub = !model[idx].sub; render(); sync(); focusModel(idx); });
        row.appendChild(ind);

        var inp = document.createElement('input'); inp.type = 'text'; inp.value = it.text; inp.placeholder = 'Tagesordnungspunkt …';
        inp.addEventListener('input', function () { model[idx].text = inp.value; refresh(); sync(); });
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); model.splice(idx + 1, 0, { text: '', sub: model[idx].sub, time: '' }); render(); sync(); focusModel(idx + 1); }
        });
        row.appendChild(inp);

        var tm = document.createElement('input'); tm.type = 'text'; tm.className = 'ag-time-input'; tm.value = it.time || ''; tm.placeholder = 'Zeit'; tm.title = 'Ungefähre Zeitangabe (optional)';
        tm.addEventListener('input', function () { model[idx].time = tm.value; sync(); });
        row.appendChild(tm);

        var up = btn('', 'Nach oben', 'ti-chevron-up'); up.disabled = idx === 0;
        up.addEventListener('click', function () { swap(idx, idx - 1); focusModel(idx - 1); });
        row.appendChild(up);
        var dn = btn('', 'Nach unten', 'ti-chevron-down'); dn.disabled = idx === model.length - 1;
        dn.addEventListener('click', function () { swap(idx, idx + 1); focusModel(idx + 1); });
        row.appendChild(dn);

        var del = btn('ag-del', 'Entfernen', 'ti-trash');
        del.addEventListener('click', function () { model.splice(idx, 1); if (!model.length) model.push({ text: '', sub: false, time: '' }); render(); sync(); });
        row.appendChild(del);

        rowsEl.appendChild(row);
      });
      addInt.disabled = hasDivider();
    }
    add.addEventListener('click', function () { model.push({ text: '', sub: false, time: '' }); render(); sync(); focusModel(model.length - 1); });
    addInt.addEventListener('click', function () { if (!hasDivider()) { model.push({ divider: true }); render(); sync(); } });

    render(); sync();
  });
})();

/*
 * Scroll-Position erhalten + frische Inhalte bei Rückkehr in die App.
 *
 * 1) Scroll-Merker: Jede Aktion (Formular-Submit, Button-Klick – auch die
 *    astaConfirm-Flows, die programmatisch f.submit() aufrufen) merkt sich die
 *    Scroll-Position. Landet man kurz danach per POST→Redirect oder Reload
 *    wieder auf derselben Seite, wird sie wiederhergestellt – die Seite
 *    springt also nicht mehr nach oben, wenn man z. B. Chips durchklickt.
 * 2) Auto-Refresh: iOS-Standalone-PWAs und der bfcache zeigen beim Wiederöffnen
 *    den eingefrorenen Stand. Nach >60 s Abwesenheit (bzw. bfcache-Rückkehr auf
 *    eine >60 s alte Seite) laden wir neu – nur ohne ungespeicherte Eingaben,
 *    und dank Scroll-Merker ohne Sprung nach oben.
 */
(function () {
  var KEY = 'asta-scroll:' + location.pathname + location.search;
  function remember() {
    try { sessionStorage.setItem(KEY, window.scrollY + '|' + Date.now()); } catch (e) {}
  }
  // Formular-Submits (Event feuert nicht bei programmatischem f.submit(), daher
  // zusätzlich Klicks auf Buttons – deckt astaConfirm-Modals & Co. mit ab)
  document.addEventListener('submit', remember, true);
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('button, .btn') : null;
    if (b) remember();
  }, true);
  // Wiederherstellen: nur auf derselben Seite, nur frisch (<30 s), nie gegen einen Anker
  (function () {
    var v = null;
    try { v = sessionStorage.getItem(KEY); if (v !== null) sessionStorage.removeItem(KEY); } catch (e) {}
    if (!v || location.hash) return;
    var parts = v.split('|');
    var y = parseInt(parts[0], 10) || 0;
    var t = parseInt(parts[1], 10) || 0;
    if (y > 0 && Date.now() - t < 30000) window.scrollTo(0, y);
  })();

  var loadedAt = Date.now(), hiddenAt = 0, dirty = false;
  function freshReload() { remember(); location.reload(); }
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) dirty = true;
  }, true);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { hiddenAt = Date.now(); }
    else if (hiddenAt && !dirty && Date.now() - hiddenAt > 60000) { hiddenAt = 0; freshReload(); }
  });
  window.addEventListener('pageshow', function (e) {
    // bfcache-Rückkehr: nur neu laden, wenn der Stand wirklich alt ist –
    // schnelles Vor/Zurück bleibt dadurch flott statt jedes Mal neu zu laden
    if (e.persisted && !dirty && Date.now() - loadedAt > 60000) freshReload();
  });
})();

/* iOS-App (Standalone): Office-Dateien über das Teilen-Blatt öffnen.
   Das In-App-Browser-Fenster (WKWebView) der installierten App kann DOCX/XLSX/PPTX
   nicht darstellen – es bleibt eine weiße Seite (Text und PDF gehen, Word nicht).
   Deshalb: Tap auf einen Office-Datei-Link → Datei per fetch holen und dem
   iOS-Teilen-Blatt geben („In Word öffnen", „In Dateien sichern" …) – die App
   bleibt dabei einfach offen. PDFs/Bilder behalten ihre Fenster-Vorschau.
   Kann das Gerät nicht teilen oder schlägt es fehl: target=_blank. */
(function () {
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  var ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && 'ontouchend' in document);
  if (!standalone || !ios || !navigator.canShare) return;
  var OFFICE = /officedocument|ms-?word|ms-?excel|ms-?powerpoint/i;
  // Endpunkte, die IMMER Word liefern; download.php nur, wenn der Link-Text nach Office-Datei aussieht
  function officeLink(a) {
    var href = a.getAttribute('href') || '';
    if (/[?&](dl|protokoll|protokoll_intern|download)=/.test(href)) return true;
    if (/(^|\/)download\.php\?/.test(href)) return /\.(docx?|xlsx?|pptx?)\s*$/i.test(a.textContent || '');
    return false;
  }
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[target="_blank"]') : null;
    if (!a || !officeLink(a)) return;
    e.preventDefault();
    fetch(a.href, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('http ' + r.status);
      var cd = r.headers.get('Content-Disposition') || '';
      var m = /filename\*=UTF-8''([^;]+)/i.exec(cd) || /filename="([^"]+)"/.exec(cd);
      var name = 'datei';
      try { name = m ? decodeURIComponent(m[1].trim()) : name; } catch (err) { if (m) name = m[1]; }
      var type = (r.headers.get('Content-Type') || '').split(';')[0].trim() || 'application/octet-stream';
      return r.blob().then(function (b) { return { file: new File([b], name, { type: type }), type: type }; });
    }).then(function (got) {
      // Kein Office (z. B. PDF-Anhang über download.php)? Dann normale Fenster-Vorschau.
      if (!OFFICE.test(got.type)) { window.open(a.href, '_blank'); return; }
      var data = { files: [got.file] };
      if (!navigator.canShare(data)) { window.open(a.href, '_blank'); return; }
      return navigator.share(data).catch(function (err) {
        if (!err || err.name === 'AbortError') return;   // Teilen-Blatt zugeklappt → nichts tun
        window.open(a.href, '_blank');                    // z. B. NotAllowedError → Fallback
      });
    }).catch(function () { window.open(a.href, '_blank'); });
  });
})();
/* Onboarding-Rundgang (geführte, SEITENÜBERGREIFENDE Tour). Erklärt das Dashboard ausführlich und führt
   dann durch alle Bereiche – in Kapiteln, die man einzeln überspringen kann. KEIN Auto-Springen: bei einem
   Seitenwechsel wird der passende Menü-Punkt hervorgehoben und der Nutzer klickt ihn SELBST. Dropdown-Ziele
   (Mein Profil, Wichtige Infos) laufen zweistufig: erst den Namen hervorheben, nach dem Öffnen die Option.
   Fortschritt in sessionStorage → läuft über Seitenwechsel weiter. Seiten/Rollen aus PHP
   (ASTA_TOUR_PAGES/ASTA_TOUR_FLAGS). Reine Vanilla-JS. */
(function () {
  if (!window.ASTA_CSRF) return;
  var PAGES = window.ASTA_TOUR_PAGES || { dashboard: 'dashboard.php' };
  var FLAGS = window.ASTA_TOUR_FLAGS || {};
  var BASE = window.ASTA_BASE || '';
  var CATS = ['Dashboard', 'Kalender & Events', 'Sitzungen', 'Abstimmungen', 'Mitglieder & Profil', 'Erfolge, Streak & Aussehen', 'Auslagen', 'Deine Scores', 'Verwaltung', 'Infos & Abschluss'];
  var NAVSEL = {
    dashboard: 'a[href$="dashboard.php"]', kalender: 'a[href$="index.php"]:not([href*="admin"])', events: 'a[href$="events.php"]',
    sitzungen: 'a[href$="report.php"], a[href$="admin/meetings.php"]', umlauf: 'a[href$="umlauf.php"]',
    mitglieder: 'a[href$="mitglieder.php"]', profil: 'a[href$="profil.php"]', erinnerungen: 'a[href$="erinnerungen.php"]',
    achievements: 'a[href$="achievements.php"]', basisscore: 'a[href$="basisscore.php"]', score: 'a[href$="score.php"]:not([href$="basisscore.php"])',
    finanzen: 'a[href$="finanzen.php"]', verwaltung: 'a[href$="admin/index.php"]', adminmembers: 'a[href$="members.php"]', info: 'a[href$="info.php"]',
    wegweiser: 'a[href$="wegweiser.php"]:not([href*="admin"])'
  };
  var NAVLABEL = {
    dashboard: 'Dashboard', kalender: 'Kalender', events: 'Events', sitzungen: 'Sitzungen', umlauf: 'Abstimmungen',
    mitglieder: 'Mitglieder', profil: 'Mein Profil', erinnerungen: 'Erinnerungen-Kachel', achievements: 'Achievements',
    basisscore: 'Basis', score: 'Eventscore', finanzen: 'Auslagen', verwaltung: 'Verwaltung', adminmembers: 'Mitglieder', info: 'Wichtige Infos & Anleitung',
    wegweiser: 'Wegweiser'
  };
  // Ist ein Nav-Ziel gerade versteckt (Handy: im „Mehr"-Sheet; Desktop: im Namens-Dropdown), muss der Nutzer
  // erst dieses Menü öffnen. Reihenfolge: Desktop-Dropdown, dann Handy-„Mehr"-Button (der jeweils Sichtbare gewinnt).
  var OPENERS = ['.nav-user', '.tab-more'];
  // content {c,page,sel?,flag?,title,html} · nav {c,to,open?,title,html} · schlicht {c,title,html}
  var STEPS = [
    // ---- Kapitel 0: Dashboard ----
    { c: 0, title: 'Willkommen an Bord! 👋', html: 'Schön, dass du dabei bist! Ich zeige dir ausführlich das <strong>Dashboard</strong> und führe dich dann durch alle Bereiche. Der Rundgang ist in <strong>Kapitel</strong> unterteilt – uninteressante überspringst du einzeln, und über „Wichtige Infos" kannst du jederzeit alles wiederholen. <em>Los geht’s!</em>' },
    { c: 0, page: 'dashboard', sel: '.hero', title: 'Dein Dashboard', html: 'Hier landest du nach dem Login. Oben deine <strong>Begrüßung</strong> und dein <strong>Avatar</strong> (den schmückst du dir später mit Erfolgen). Rechts sitzen deine Kennzahlen.' },
    { c: 0, page: 'dashboard', sel: '.hero-streak', title: 'Deine Streak 🔥', html: 'Jeder Tag mit App-Besuch gibt eine <strong>Flamme</strong>. Bleib dran – erst nach 5 Tagen Pause reißt die Serie. Ein Tipp führt zu deinen Erfolgen.' },
    { c: 0, page: 'dashboard', sel: '.hero-scoreband', title: 'Deine zwei Scores', html: 'Der <strong>Basis-Score</strong> misst die Grundpflichten (0 = einwandfrei), der <strong>Eventscore</strong> dein Engagement bei Events. Auf beide tippst du hier direkt – wir schauen sie uns später noch genauer an.' },
    { c: 0, page: 'dashboard', sel: '.tiles', title: 'Schnell-Überblick', html: 'Diese Kacheln zeigen auf einen Blick, was ansteht: <strong>offene Aufgaben</strong>, kommende Events und Sitzungen. Ein Tipp führt direkt hin.' },
    { c: 0, page: 'dashboard', sel: '.tiles a[href="#offen"]', title: 'Deine Aufgaben', html: 'Was von dir gebraucht wird, sammelt sich hier: Berichte, Abstimmungen, Pronomen eintragen … Weiter unten stehen sie ausführlich, dazu <strong>Nachrichten & Hinweise</strong> und die <strong>nächsten Termine</strong>.' },
    { c: 0, page: 'dashboard', sel: '#ical-card', title: 'Kalender-Abo per QR 📆', html: 'Mit „<strong>Mein Kalender-Abo</strong>" holst du dir <strong>alle AStA-Termine in deinen eigenen Kalender</strong>: <strong>QR-Code scannen</strong> (iPhone) oder den <strong>Link kopieren</strong> (Android/Outlook). Neue Termine erscheinen dann automatisch auf deinem Handy.' },
    { c: 0, page: 'dashboard', sel: '#db-absence', title: 'Wer ist gerade weg? 🌴', html: 'Auf dem Dashboard siehst du <strong>sofort, wer gerade oder demnächst abwesend</strong> ist – praktisch, bevor du jemanden für eine Schicht einplanst. Deine <strong>eigene</strong> Abwesenheit trägst du über „Abwesenheit" im Menü ein.' },
    { c: 0, page: 'dashboard', sel: '#install-card', title: 'App installieren 📱', html: 'Leg dir die AStA-App <strong>wie eine echte App aufs Gerät</strong> (Vollbild, eigenes Icon, direkter Zugriff). Hier steht Schritt für Schritt wie – und nur so gibt es auf dem iPhone <strong>echte Push-Benachrichtigungen</strong>. Sehr empfohlen!' },
    { c: 0, page: 'dashboard', title: 'Und der Rest', html: 'Weiter unten findest du außerdem deine <strong>Nachrichten & Hinweise</strong>, die offenen Aufgaben im Detail und die <strong>kommenden Termine</strong>. Jetzt gleich noch eine wichtige Kachel …' },
    { c: 0, page: 'dashboard', sel: '.tiles a[href$="erinnerungen.php"]', title: 'Benachrichtigungen 🔔', html: 'Über die <strong>Erinnerungen-Kachel</strong> stellst du ein, <strong>worüber</strong> und <strong>wie</strong> du benachrichtigt wirst. Klick sie gleich an – schauen wir sie uns direkt an.' },
    { c: 0, to: 'erinnerungen', title: 'Rein in die Erinnerungen', html: 'Tippen wir auf die <strong>Erinnerungen-Kachel</strong>.' },
    { c: 0, page: 'erinnerungen', sel: 'h1, .section-title', title: 'Mitteilungs-Einstellungen', html: 'Hier stellst du <strong>pro Typ</strong> (Schichten, Sitzungen, Abstimmungen, Infos …) genau ein: ob <strong>E-Mail</strong> und/oder <strong>Push</strong>, mit eigenem Vorlauf. Bei den <strong>Pflicht-Erinnerungen</strong> darfst du die Mail abschalten, sobald du <strong>Push eingerichtet</strong> hast – fällt der Push weg, kommt die Mail automatisch zurück. Auch die optionale <strong>Inaktivitäts-Erinnerung</strong> aktivierst du hier.' },
    // ---- Kapitel 1: Kalender & Events ----
    { c: 1, to: 'kalender', title: 'Weiter zum Kalender 📅', html: 'Schauen wir uns die Bereiche an – los mit dem <strong>Kalender</strong>.' },
    { c: 1, page: 'kalender', sel: 'h1, .section-title', title: 'Der Kalender', html: 'Hier stehen <strong>alle Termine</strong>: Events, Sitzungen und mehr. Du kannst <strong>StuPa-Termine ein-/ausblenden</strong> und über die Knöpfe oben <strong>freie Zeitfenster</strong> finden oder einen Terminfinder starten. (Dein Kalender-Abo mit QR liegt auf dem Dashboard.)' },
    { c: 1, to: 'events', title: 'Weiter zu Events', html: 'Als Nächstes die <strong>Events</strong>.' },
    { c: 1, page: 'events', sel: 'h1, .section-title', title: 'Events & Schichten', html: 'Hier siehst du, wo Helfer:innen gebraucht werden. Du meldest dich für <strong>Schichten</strong> an, kannst welche in die <strong>Schichtbörse</strong> geben und sagst bei <strong>Get-Togethers</strong> zu (dort auch, wer was mitbringt).' },
    // ---- Kapitel 2: Sitzungen ----
    { c: 1, page: 'events', sel: '#externe, .section-title', flag: 'extern', title: 'Externe Events \uD83C\uDF9F\uFE0F', html: 'Unter <strong>Externe Events</strong> legt ihr <strong>öffentliche Anmeldungen</strong> an – Kneipentour, Fahrten, Workshops. Formular selbst zusammenstellen, Warteliste, und am Ende teilt die App die Leute in <strong>Gruppen</strong> ein: Freundeskreise bleiben zusammen, die Größen gleichen sich aus.' },
    { c: 2, to: 'sitzungen', title: 'Weiter zu den Sitzungen', html: 'Schauen wir uns die <strong>Sitzungen</strong> an.' },
    { c: 2, page: 'sitzungen', sel: 'h1, .section-title', title: 'Sitzungen & Berichte', html: 'Hier findest du die <strong>Tagesordnung</strong>, kannst eigene <strong>TOPs einreichen</strong>, die Protokolle lesen und dich bei Verhinderung <strong>abmelden</strong> (das steht dann sauber im Protokoll).' },
    { c: 2, page: 'sitzungen', flag: 'referat', sel: 'h1, .section-title', title: 'Dein Referats-Bericht', html: 'Weil du ein <strong>Referat</strong> hast: Vor berichtspflichtigen Sitzungen gibst du hier deinen kurzen <strong>Bericht</strong> ab. Vergisst du ihn, gibt es einen Minuspunkt im Basis-Score – also lieber dranbleiben.' },
    { c: 2, page: 'sitzungen', flag: 'sekretariat', sel: 'h1, .section-title', title: 'Fürs Sekretariat', html: 'Als <strong>Sekretariat</strong> legst du hier die Tagesordnung an, lädst per Mail an den Verteiler ein, pflegst Teams-Link und Ort und arbeitest das <strong>Protokoll</strong> ab (inkl. Abstimmung & Veröffentlichung).' },
    // ---- Kapitel 3: Abstimmungen ----
    { c: 3, to: 'umlauf', title: 'Weiter zu den Abstimmungen', html: 'Nun die <strong>Abstimmungen & Umläufe</strong>.' },
    { c: 3, page: 'umlauf', sel: 'h1, .section-title', title: 'Abstimmungen & Umläufe', html: 'Beschlüsse zwischen den Sitzungen laufen als <strong>Umlaufverfahren</strong> (verbindlich, Ja/Nein/Enthaltung) oder als lockere <strong>Abstimmung</strong>. Bei verbindlichen ist Mitmachen <strong>Pflicht – auch aus dem Urlaub</strong> (sonst Minuspunkte). Starten darf hier jede:r.' },
    // ---- Kapitel 4: Mitglieder & Profil ----
    { c: 4, to: 'mitglieder', title: 'Weiter zu den Mitgliedern', html: 'Weiter zur <strong>Mitgliederliste</strong>.' },
    { c: 4, page: 'mitglieder', sel: 'h1, .section-title', title: 'Wer ist wer', html: 'Hier siehst du, wer im AStA ist und wer wofür zuständig ist. Ein Klick auf einen <strong>Namen</strong> öffnet dessen Profil – mit Referatsbeschreibung, „Über mich" und einer <strong>Pinnwand</strong>, auf der ihr euch nette Sachen hinterlassen könnt.' },
    { c: 4, to: 'profil', open: '.nav-user', title: 'Dein eigenes Profil', html: 'Jetzt <strong>dein</strong> Profil – das erreichst du über <strong>deinen Namen oben rechts</strong>.' },
    { c: 4, page: 'profil', sel: 'h1, .section-title', title: 'Dein Profil pflegen', html: 'Trag hier deine <strong>Pronomen</strong> ein (wichtig für die Redeliste!), schreib ein paar Worte <strong>über dich</strong> und – wenn du magst – deinen <strong>Geburtstag</strong> (nur Tag & Monat). Unter den Textfeldern läuft eine <strong>Vorschau deiner Kachel</strong> aus der Mitgliederliste mit – du siehst beim Tippen, wie sie bei den anderen ankommt.' },
    { c: 4, page: 'profil', sel: '#telefon, .section-title', title: 'Telefonnummer? Freiwillig 📞', html: 'Ganz unten kannst du – rein freiwillig – eine <strong>Telefonnummer</strong> hinterlegen. Sie steht <strong>nirgends offen im Profil</strong>: Andere sehen nur einen Knopf und den Satz, den du dazuschreibst („nur im Notfall", „bitte kein WhatsApp" …). Erst wer dem im Dialog zustimmt, bekommt die Nummer zu sehen. Magst du gar keine angeben? Dann nimm den Knopf <strong>„Keine Nummer hinterlegen"</strong> – das ist eine vollwertige Antwort, steht so im Profil und nimmt den Hinweis vom Dashboard.' },
    // ---- Kapitel 5: Erfolge, Streak & Aussehen ----
    { c: 5, to: 'achievements', title: 'Weiter zu den Erfolgen', html: 'Jetzt der Spaß-Teil: <strong>Erfolge</strong>.' },
    { c: 5, page: 'achievements', sel: 'h1, .section-title', title: 'Erfolge & Avatar 🏆', html: 'Nebenbei sammelst du <strong>Erfolge</strong> und schaltest damit <strong>Avatar-Schmuck</strong>, Farben, <strong>Streak-Stile</strong> (Flamme, Herz, Blitz, Stern, Kaffee, Blume) und sogar App-Designs frei. Alles rein zum Spaß – auf Einteilung oder Score hat es <strong>keinen</strong> Einfluss. Schauen wir, wie du dich damit ausstattest.' },
    { c: 5, page: 'achievements', clickTo: 'a[href$="#locker"]', point: 'Drück auf <strong>„Anpassen"</strong> – dann springst du direkt zu deiner Ausstattung.', title: 'Alles anpassen', html: 'Oben gibt es den Knopf <strong>„Anpassen"</strong>. Er bringt dich direkt zum <strong>Ausrüst-Bereich</strong>.' },
    { c: 5, page: 'achievements', sel: '#locker .deco-locker', title: 'Ausrüsten & anlegen', html: 'Hier legst du an, was du freigeschaltet hast: <strong>Avatar-Schmuck antippen = anlegen</strong>, nochmal antippen = wieder ab (mehrere Teile gleichzeitig möglich). Darunter wählst du deine <strong>Farb-Palette</strong> und deinen <strong>Streak-Stil</strong>.' },
    { c: 5, page: 'achievements', sel: '.skin-locker', title: 'App-Designs (Skins) 🎨', html: 'Manche Erfolge schalten ganze <strong>App-Designs (Skins)</strong> frei – die erscheinen hier. <strong>Umschalten</strong> tust du sie über die <strong>💡 Glühbirne</strong> oben (bzw. im „Mehr"-Menü „Design"). Dort wechselst du auch zwischen Hell/Dunkel/System.' },
    // ---- Kapitel 6: Auslagen (Belegblätter – für alle) ----
    { c: 6, to: 'finanzen', title: 'Weiter zu den Auslagen', html: 'Hast du etwas für den AStA ausgelegt? Das rechnest du unter <strong>Auslagen</strong> ab.' },
    { c: 6, page: 'finanzen', sel: 'h1, .section-title', title: 'Auslagen abrechnen 🧾', html: 'Trag die <strong>Positionen</strong> ein und häng zu <strong>jeder</strong> die Quittung an – <strong>ohne Beleg keine Erstattung</strong>, dann muss Finanzen nachfragen und es dauert länger. Die App baut daraus ein fertig ausgefülltes <strong>Belegblatt (.docx)</strong>, das du herunterladen kannst. Die Finanzen kümmern sich dann um die Erstattung; Rückfragen laufen direkt hier als Nachricht.' },
    { c: 6, page: 'finanzen', flag: 'finanzen', sel: '#fin-open, .section-title', title: 'Für die Finanzen-Rolle', html: 'Weil du die <strong>Finanzen</strong> machst: Hier sammeln sich alle <strong>offenen Belegblätter</strong>. Du lädst die fertige DOCX herunter, schreibst bei Bedarf zurück und markierst am Ende <strong>„erledigt"</strong> – die einreichende Person wird automatisch informiert.' },
    // ---- Kapitel 7: Deine Scores (über das Dashboard, kein Gespringe) ----
    { c: 7, to: 'dashboard', title: 'Deine Scores', html: 'Deine Scores erreichst du <strong>immer über das Dashboard</strong>. Gehen wir kurz zurück.' },
    { c: 7, to: 'basisscore', title: 'Basis-Score ansehen', html: 'Auf dem Dashboard: <strong>klick auf „Basis"</strong> in der Score-Leiste.' },
    { c: 7, page: 'basisscore', sel: 'h1, .section-title', title: 'Basis-Score', html: 'Er misst nur die <strong>niederschwelligsten Grundpflichten</strong> (App öffnen, Berichte, an Abstimmungen teilnehmen …). <strong>0 ist einwandfrei</strong>, es gibt nur Minuspunkte. Hier siehst du genau, wo etwas fehlt.' },
    { c: 7, to: 'dashboard', title: 'Und der Eventscore', html: 'Genauso für den <strong>Eventscore</strong> – wieder kurz aufs Dashboard.' },
    { c: 7, to: 'score', title: 'Eventscore ansehen', html: 'Jetzt <strong>klick auf „Eventscore"</strong> in der Score-Leiste.' },
    { c: 7, page: 'score', sel: 'h1, .section-title', title: 'Eventscore', html: 'Der <strong>Eventscore</strong> belohnt dein Engagement bei Events und fließt sanft in die Schicht-Einteilung ein. Auch hier siehst du, wie er zustande kommt.' },
    // ---- Kapitel 8: Verwaltung (nur Vorsitz/Admin) ----
    { c: 8, to: 'verwaltung', title: 'Weiter zur Verwaltung', html: 'Als Admin/Vorsitz hast du die <strong>Verwaltung</strong> – schauen wir sie uns an.' },
    { c: 8, page: 'verwaltung', sel: '.event-cards', title: 'Die Verwaltung', html: 'Das Herz der Organisation: hier steuerst du <strong>Mitglieder, Events, Sitzungen, Nachrichten, Scores</strong> und mehr – jede Kachel ist ein Bereich.<br><br>📱 <strong>Tipp fürs Handy:</strong> Vieles hier (Tabellen, Mitgliederliste, Einstellungen) ist am <strong>Rechner</strong> deutlich angenehmer. Am Handy geht’s, aber dreh es am besten ins <strong>Querformat</strong>.' },
    { c: 8, to: 'adminmembers', title: 'Rein in die Mitgliederverwaltung', html: 'Die wichtigste Kachel zuerst: <strong>„Mitglieder"</strong>. Klick sie an.' },
    { c: 8, page: 'adminmembers', sel: 'h1, .section-title', title: 'Mitglieder verwalten', html: 'Hier <strong>legst du Mitglieder an</strong>, vergibst <strong>Rollen & Referate</strong>, erzeugst persönliche <strong>Login-Links</strong> und setzt Leute bei Weggang <strong>inaktiv</strong>. Weiter unten pflegst du die <strong>Referate</strong> (für die Berichtspflicht), ganz am Ende liegen die <strong>StuPa-Zugänge</strong>.' },
    { c: 8, to: 'verwaltung', title: 'Zurück zur Verwaltung', html: 'Zurück in die <strong>Verwaltung</strong> – die weiteren Basics zeige ich dir direkt an den Kacheln.' },
    { c: 8, page: 'verwaltung', sel: '.event-cards a[href$="score.php"]:not([href$="basisscore.php"])', title: 'Scores', html: 'Über <strong>„Scores"</strong> siehst du Event- und Basis-Score aller Mitglieder, kannst <strong>unentschuldigtes Fehlen nachtragen</strong> und behältst den Überblick, wer wie aktiv ist.' },
    { c: 8, page: 'verwaltung', sel: '.event-cards a[href$="vorlagen.php"]', title: 'Word-Vorlagen', html: 'Unter <strong>„Word-Vorlagen"</strong> hinterlegst du die <strong>Protokoll-, Berichte- und Belegblatt-Vorlage</strong>. Die App füllt darin automatisch <strong>Platzhalter</strong> (Datum, Namen, Beträge …) – so bleibt euer Layout überall gleich.' },
    { c: 8, page: 'verwaltung', sel: '.event-cards a[href$="uploads.php"]', title: 'Uploads & Automationen', html: 'Unter <strong>„Uploads und Automationen"</strong> hängen die <strong>Teams-/OLAT-Ablage</strong>, das automatische <strong>Berichte-Dok</strong> und der <strong>Protokoll-Workflow</strong> – inkl. der <strong>Cron</strong>-Anbindung, die alles zeitgesteuert laufen lässt.' },
    { c: 8, page: 'verwaltung', sel: '.event-cards a[href$="errorlog.php"]', title: 'Diagnose & Fehler-Log', html: 'Läuft etwas nicht rund, hilft <strong>„Diagnose & Fehler-Log"</strong>: ein <strong>Selbsttest</strong> prüft die App durch, Zustell-Tests probieren E-Mail/Push, und aufgezeichnete Fehler stehen sauber protokolliert bereit.' },
    { c: 8, page: 'verwaltung', sel: '.event-cards a[href$="umfragen.php"], a[href$="umfragen.php"]', flag: 'admin', title: 'Umfragen 📊', html: 'Unter <strong>Umfragen</strong> legst du Befragungen an, die <strong>außerhalb der App</strong> stattfinden – für die ganze Hochschule. Wer abstimmt, bestätigt seine RPTU-Adresse per Mail; <strong>Stimme und Adresse werden getrennt gespeichert</strong>, auch ihr könnt nicht sehen, wer wie abgestimmt hat. Für <strong>Wahlen</strong> ist das ausdrücklich nicht gedacht.' },
    { c: 8, page: 'verwaltung', sel: '.event-cards a[href$="terminplaner.php"], a[href$="terminplaner.php"]', flag: 'admin', title: 'Terminplaner 🗓️', html: 'Der <strong>Terminplaner</strong> ist – wie die externe Redeliste – ein Angebot, das der AStA <strong>kostenfrei für alle</strong> betreibt (nicht nur für Leute an der Hochschule): gemeinsamen Termin finden, <strong>ohne Konto</strong>. Ihr legt dort nichts an; wer einen Termin sucht, macht das selbst und verwaltet ihn über einen geheimen Link. Diese Seite ist die <strong>Aufsicht</strong>: Einstellungen, Notausschalter und die Möglichkeit, etwas zu löschen – <strong>fremde Antworten seht ihr bewusst nicht</strong>.' },
    { c: 8, page: 'verwaltung', sel: '#einstellungen', title: 'E-Mail & Einstellungen', html: 'Weiter unten auf der Verwaltungs-Startseite stehen <strong>E-Mail-Absender, Basis-URL und Fristen</strong> – und das <strong>gemeinsame Mail-Konto</strong>: Alle Bereiche teilen sich das Tageskontingent des Hosters und rechnen sich gegenseitig an, was schon rausging. Die <strong>Reserve</strong> bleibt für Login-Links stehen.' },
    { c: 8, page: 'verwaltung', sel: '#cron', title: 'Cron-Jobs ⏰', html: 'Direkt darunter die <strong>Cron-Jobs</strong>: die Adressen, die im <strong>Mittwald-Kundencenter</strong> als zeitgesteuerter Aufruf hinterlegt werden. Ohne sie gehen Erinnerungen, wartende Mails und das Aufräumen <strong>nicht</strong> von selbst raus. Die <strong>Diagnose</strong> meldet es, wenn einer davon nicht läuft.' },
    { c: 8, page: 'verwaltung', sel: 'a[href$="reset.php"], #einstellungen', title: 'Gefahrenzone ⚠️', html: 'Und ganz unten die <strong>Gefahrenzone</strong>: gezielt löschen, was weg soll – einzeln je Datenart. Die <strong>öffentlichen Bereiche</strong> (Pat:innenprogramm, Umfragen, externe Events, Terminplaner) haben dort einen eigenen Abschnitt, weil sie in eigenen Datenbanken liegen. Gelöscht wird <strong>unwiderruflich</strong>.' },
    // ---- Kapitel 9: Infos, Feedback & Abschluss ----
    { c: 9, to: 'info', title: 'Fast fertig: Wichtige Infos', html: 'Zum Schluss die <strong>Wichtigen Infos & Anleitung</strong> – auch über <strong>deinen Namen oben rechts</strong>.' },
    { c: 9, page: 'info', sel: 'h1, .section-title', title: 'Infos, Dokumente & Wiederholen', html: 'Hier liegen <strong>Anleitungen und Dokumente</strong> (Geschäftsordnung, How-tos …) – und ganz oben der Knopf, um <strong>diesen Rundgang jederzeit zu wiederholen</strong>.' },
    { c: 9, page: 'info', sel: 'button.foot-link, .foot-link', title: 'Feedback 💬', html: 'Ganz unten im Fuß der Seite: <strong>„Feedback"</strong> – für <strong>Lob, Ideen, Kritik oder Fragen</strong>. Deine Rückmeldung landet nicht in einem Postfach, sondern als Eintrag beim Technik-Referat: Du siehst unter <strong>„Feedback"</strong>, was daraus wird, und bekommst eine Mitteilung, sobald jemand antwortet.' },
    { c: 9, page: 'info', sel: 'a[href$="bug.php"]', title: 'Fehler melden 🐞', html: 'Und direkt daneben <strong>„Fehler melden"</strong>: Ist etwas kaputt oder komisch? Melde es hier. Wird dein gemeldeter Bug behoben, gibt es sogar ein Achievement dafür.' },
    { c: 9, to: 'wegweiser', title: 'Der Wegweiser 🧭', html: 'Im selben Menü direkt unter den Infos: der <strong>Wegweiser</strong>. Dort steht, <strong>wo was liegt und wer wofür zuständig ist</strong> – unsere eigenen Dienste, die Anlaufstellen an der Uni und die Menschen außerhalb.' },
    { c: 9, page: 'wegweiser', sel: '#wwSearch, h1', title: 'Einfach tippen', html: 'Statt zu scrollen: <strong>ins Suchfeld tippen</strong>. Gesucht wird über Namen, Stichworte und Adressen – auch mit mehreren Wörtern in beliebiger Reihenfolge.' },
    { c: 9, page: 'wegweiser', sel: '#meine, .section-title', flag: '!admin', title: 'Selbst etwas eintragen', html: 'Fehlt etwas? Ganz unten unter <strong>„Deine Einträge"</strong> trägst du es selbst ein – zum Beispiel die Anlaufstellen, mit denen dein Referat regelmäßig zu tun hat. <strong>Deine eigenen Einträge kannst du jederzeit ändern und löschen</strong>, fremde nicht; das darf nur der Vorsitz.' },
    { c: 9, title: 'Geschafft! 🎉', html: 'Das war der komplette Rundgang. Am wichtigsten: <strong>schau täglich kurz rein</strong>, dann verpasst du nichts. Wiederholen kannst du alles jederzeit unter „Wichtige Infos & Anleitung". Viel Spaß im AStA! 💚' }
  ];

  var SKEY = 'asta-tour';
  var idx = 0, ov, spot, tip, curEl = null, active = false, curFwd = null;
  /* Auswahl NACHEINANDER versuchen, nicht als ein Komma-Ausdruck: querySelector('a, .b') liefert
     das im Dokument ZUERST stehende Treffer-Element, nicht den Treffer des ersten Ausdrucks. Ein
     grober Auffang-Ausdruck hinten hat damit den genauen vorne überstimmt – so zeigte der Rundgang
     bei „Umfragen" auf das ganze Kachelraster und bei „Cron" auf die erste Überschrift der Seite.
     Sichtbarkeit zählt mit: Ein ausgeblendeter genauer Treffer soll den Auffang nicht blockieren. */
  /* Am Komma trennen, aber nur außerhalb von Klammern und Anführungszeichen: ein künftiges
     „:is(a, b)" oder „[data-x='a,b']" darf nicht mittendrin zerrissen werden. */
  function teilen(sel) {
    var out = [], akt = '', tiefe = 0, quote = '';
    for (var i = 0; i < sel.length; i++) {
      var ch = sel.charAt(i);
      if (quote) { if (ch === quote && sel.charAt(i - 1) !== '\\') quote = ''; }
      else if (ch === '"' || ch === "'") quote = ch;
      else if (ch === '(' || ch === '[') tiefe++;
      else if (ch === ')' || ch === ']') tiefe--;
      else if (ch === ',' && tiefe === 0) { out.push(akt); akt = ''; continue; }
      akt += ch;
    }
    out.push(akt);
    return out;
  }

  function q(sel) {
    if (!sel) return null;
    var teile = teilen(String(sel));
    var ersterTreffer = null;
    for (var i = 0; i < teile.length; i++) {
      var t = teile[i].trim();
      if (!t) continue;
      try {
        var el = document.querySelector(t);
        if (el) { if (shown(el)) return el; if (!ersterTreffer) ersterTreffer = el; }
      } catch (e) { /* ungültiger Ausdruck – nächsten versuchen */ }
    }
    return ersterTreffer;
  }
  // Inhalt eines GESCHLOSSENEN <details> (Namens-Dropdown) wird von modernen Browsern zwar ausgelegt,
  // ist für den Nutzer aber unsichtbar → als nicht sichtbar behandeln (das Dropdown muss erst geöffnet werden).
  function shown(el) { if (!el) return false; var p = el.parentElement; if (p && p.closest && p.closest('details:not([open])')) return false; var r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && el.offsetParent !== null; }
  function visSel(sel) { var els; try { els = document.querySelectorAll(sel); } catch (e) { return null; } for (var i = 0; i < els.length; i++) if (shown(els[i])) return els[i]; return null; }
  // Nav-Ziel: den ECHTEN Menü-Link finden (in Topbar/Tabbar/Sheet/Dropdown), nicht z. B. das Marken-Logo
  // oder Fließtext-Links; Fallback ohne Scope für Ziele ohne Menü-Eintrag (z. B. Score-Leiste auf dem Dashboard).
  function navVis(key) { var base = NAVSEL[key]; if (!base) return null; var sc = ['#mainnav ', '.tabbar ', '.navsheet ', '.nav-user-menu ', '.hero-scoreband ', '.tiles ', '.event-cards ']; for (var s = 0; s < sc.length; s++) { var el = visSel(sc[s] + base); if (el) return el; } return null; }
  function avail(i) {
    var s = STEPS[i]; if (!s) return false;
    // Flags dürfen negiert werden ('!admin' = nur für alle ANDEREN) – manche Schritte erklären
    // etwas, das es für Vorsitz/Admin gar nicht gibt.
    if (s.flag) { var neg = s.flag.charAt(0) === '!', fk = neg ? s.flag.slice(1) : s.flag; if (!!FLAGS[fk] === neg) return false; }
    if (s.to) return !!PAGES[s.to];
    if (s.page) return !!PAGES[s.page];
    return true;
  }
  function availList() { var a = []; for (var i = 0; i < STEPS.length; i++) if (avail(i)) a.push(i); return a; }
  function pushUrl() { return (window.ASTA_ACH_EQUIP || 'achievements.php').replace(/achievements\.php$/, '') + 'push.php'; }
  function curKey() {
    var path = location.pathname.replace(/\/+$/, ''), segs = path.split('/'),
        base = segs[segs.length - 1], two = segs.slice(-2).join('/'), best = null, bl = -1;
    for (var k in PAGES) { var u = PAGES[k]; if ((u === base || u === two) && u.length > bl) { best = k; bl = u.length; } }
    return best;
  }
  function save(i) { try { sessionStorage.setItem(SKEY, String(i)); } catch (e) {} }
  function clearSave() { try { sessionStorage.removeItem(SKEY); } catch (e) {} }
  // Server-Signal „Rundgang läuft": info.php darf den geführten Besuch NICHT als gelesen markieren.
  function tourCookie(on) { try { document.cookie = 'asta_tour=' + (on ? '1' : '') + ';path=/;max-age=' + (on ? 1800 : 0) + ';SameSite=Lax'; } catch (e) {} }

  function ensure() {
    if (ov) return;
    ov = document.createElement('div'); ov.className = 'tour-ov';
    spot = document.createElement('div'); spot.className = 'tour-spot';
    tip = document.createElement('div'); tip.className = 'tour-tip'; tip.setAttribute('role', 'dialog'); tip.setAttribute('aria-modal', 'true');
    ov.appendChild(spot); ov.appendChild(tip);
    document.body.appendChild(ov);
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);
    document.addEventListener('keydown', onKey);
  }
  function onKey(e) {
    if (!active) return;
    if (e.key === 'Escape') finish(false);
    else if (e.key === 'ArrowRight') { if (curFwd) curFwd(); }
    else if (e.key === 'ArrowLeft') prev();
  }
  function placeTip(r) {
    tip.classList.remove('tour-tip--center');
    var tw = tip.offsetWidth, th = tip.offsetHeight, m = 12, vw = window.innerWidth, vh = window.innerHeight;
    var top = r.bottom + m; if (top + th > vh - 8) top = r.top - th - m; if (top < 8) top = 8;
    var left = r.left + r.width / 2 - tw / 2; if (left < 8) left = 8; if (left + tw > vw - 8) left = vw - 8 - tw;
    tip.style.top = top + 'px'; tip.style.left = left + 'px';
  }
  function reposition() {
    if (!active) return;
    if (curEl && shown(curEl)) {
      var r = curEl.getBoundingClientRect(), pad = 8;
      spot.style.top = (r.top - pad) + 'px'; spot.style.left = (r.left - pad) + 'px';
      spot.style.width = (r.width + pad * 2) + 'px'; spot.style.height = (r.height + pad * 2) + 'px';
      placeTip(r);
    } else {
      spot.style.width = '0px'; spot.style.height = '0px'; spot.style.top = '50%'; spot.style.left = '50%';
      tip.classList.add('tour-tip--center'); tip.style.top = ''; tip.style.left = '';
    }
  }
  function show(i) { idx = i; save(i); render(); }
  function next() { for (var i = idx + 1; i < STEPS.length; i++) if (avail(i)) { show(i); return; } finish(true); }
  function prev() { for (var i = idx - 1; i >= 0; i--) if (avail(i)) { show(i); return; } }
  function skipCat() { var c = STEPS[idx].c; for (var i = idx + 1; i < STEPS.length; i++) if (avail(i) && STEPS[i].c > c) { show(i); return; } finish(false); }
  function navGo(s) { save(idx); location.assign(BASE + PAGES[s.to]); } // Fallback (Link versteckt): selbst hinnavigieren
  function reopen() { setTimeout(function () { if (active) render(); }, 70); } // nach dem Öffnen des Dropdowns neu vermessen
  function clickAdvance() { setTimeout(function () { if (active) next(); }, 90); } // In-Page-Klick (z. B. „Anpassen") → nächster Schritt

  function render() {
    ensure(); active = true; ov.classList.add('on'); tourCookie(true);
    var s = STEPS[idx], isNav = !!s.to, isClick = !isNav && !!s.clickTo, navEl = null, pass = false, opener = false, openerKind = '';
    if (isNav) {
      navEl = navVis(s.to); // echter Menü-Link, wenn direkt sichtbar (Desktop-Topbar / Tab-Leiste / Score-Leiste)
      if (!navEl) { for (var oi = 0; oi < OPENERS.length; oi++) { var op = visSel(OPENERS[oi]); if (op) { navEl = op; opener = true; openerKind = OPENERS[oi]; op.addEventListener('click', reopen, { once: true }); break; } } }
      pass = !!navEl; curEl = navEl;
    } else if (isClick) { // In-Page-Button (z. B. „Anpassen“): Nutzer klickt selbst, Klick schiebt die Tour weiter
      curEl = q(s.clickTo); if (curEl && !shown(curEl)) curEl = null;
      if (curEl) { pass = true; curEl.addEventListener('click', clickAdvance, { once: true }); }
    } else { curEl = s.sel ? q(s.sel) : null; if (curEl && !shown(curEl)) curEl = null; }
    ov.classList.toggle('tour-ov--pass', pass);

    var av = availList(), pos = av.indexOf(idx), last = pos === av.length - 1, first = pos === 0;
    var moreCat = false; for (var j = idx + 1; j < STEPS.length; j++) if (avail(j) && STEPS[j].c > s.c) { moreCat = true; break; }
    var label = isNav ? (NAVLABEL[s.to] || s.to) : '';
    var point = '';
    if (isNav && navEl) {
      if (opener) point = openerKind === '.tab-more'
        ? '<p class="tour-point">👉 Tippe unten auf <strong>„Mehr"</strong> – dann öffnet sich das Menü mit „' + label + '".</p>'
        : '<p class="tour-point">👉 Klick auf <strong>deinen Namen</strong> oben rechts – dann klappt das Menü auf.</p>';
      else point = '<p class="tour-point">👉 Klick jetzt selbst auf <strong>„' + label + '"</strong>.</p>';
    } else if (isClick && curEl) { point = '<p class="tour-point">👉 ' + (s.point || 'Klick jetzt selbst.') + '</p>'; }
    var body = s.html + point;
    // Vorwärts-Knopf: Nav mit sichtbarem Link → KEIN Knopf (Nutzer klickt selbst); Nav ohne Link → „Weiter" (Fallback); sonst Weiter/Fertig
    var fwdLabel = '', fwd = null;
    if (isNav && navEl) { fwd = null; }
    else if (isNav) { fwd = function () { navGo(s); }; fwdLabel = 'Weiter zu „' + label + '"'; }
    else if (isClick && curEl) { fwd = null; } // kein Knopf – Nutzer klickt den Button selbst
    else if (last) { fwd = function () { finish(true); }; fwdLabel = 'Fertig'; }
    else { fwd = next; fwdLabel = 'Weiter'; }
    curFwd = fwd || (isClick && curEl ? next : null); // Tastatur-Escape für Klick-Schritte, aber kein sichtbarer Knopf

    var h = '<div class="tour-kick"><span>Kapitel ' + (s.c + 1) + '/' + CATS.length + ' · ' + CATS[s.c] + '</span>'
      + (moreCat ? '<button type="button" class="tour-skipcat">Kapitel überspringen ›</button>' : '') + '</div>'
      + '<h3 class="tour-title">' + s.title + '</h3><div class="tour-text">' + body + '</div>'
      + '<div class="tour-actions"><button type="button" class="tour-skip">Tour beenden</button>'
      + '<span class="tour-progress">' + (pos + 1) + ' / ' + av.length + '</span>'
      + (first ? '' : '<button type="button" class="tour-prev">Zurück</button>')
      + (fwd ? '<button type="button" class="tour-next btn">' + fwdLabel + '</button>' : '') + '</div>';
    tip.innerHTML = h;
    tip.querySelector('.tour-skip').onclick = function () { finish(false); };
    var nx = tip.querySelector('.tour-next'); if (nx) nx.onclick = fwd;
    var pv = tip.querySelector('.tour-prev'); if (pv) pv.onclick = prev;
    var sc = tip.querySelector('.tour-skipcat'); if (sc) sc.onclick = skipCat;
    if (curEl && !isNav) { try { curEl.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { try { curEl.scrollIntoView(); } catch (e2) {} } }
    reposition();
    if (isNav) { setTimeout(reposition, 120); setTimeout(reposition, 320); setTimeout(reposition, 560); } // Sheet/Dropdown-Animation nachvermessen
    else setTimeout(reposition, 320);
  }
  function finish(done) {
    if (!active && !ov) return;
    active = false; clearSave(); tourCookie(false); if (ov) ov.classList.remove('on');
    try { var b = new FormData(); b.append('action', 'tour_done'); b.append('done', done ? '1' : '0'); b.append('csrf', window.ASTA_CSRF); fetch(pushUrl(), { method: 'POST', body: b, credentials: 'same-origin', keepalive: true }).catch(function () {}); } catch (e) {}
    setTimeout(function () { if (ov && ov.parentNode) ov.parentNode.removeChild(ov); ov = null; }, 250);
    if (/[?&]tour=1\b/.test(location.search) && history.replaceState) {
      var s = location.search.replace(/([?&])tour=1\b&?/, '$1').replace(/[?&]$/, '');
      history.replaceState(null, '', location.pathname + s + location.hash);
    }
  }
  window.astaStartTour = function () { clearSave(); var a = availList(); if (a.length) show(a[0]); };

  (function boot() {
    var fresh = /[?&]tour=1\b/.test(location.search), auto = !!window.ASTA_TOUR_AUTO, saved = null;
    try { saved = sessionStorage.getItem(SKEY); } catch (e) {}
    if (!fresh && !auto && saved === null) return;
    // Ein gespeicherter Fortschritt hat IMMER Vorrang vor dem Auto-Start: solange die Tour läuft, ist
    // `member_onboarded` noch false → das Dashboard setzt bei JEDEM Besuch ASTA_TOUR_AUTO=1. Ohne diese
    // Bedingung würde ein Zwischen-Sprung aufs Dashboard (z. B. Kapitel „Deine Scores") die Tour neu starten.
    var restart = fresh || (auto && saved === null);
    function begin() {
      var startI;
      if (restart) { clearSave(); var a = availList(); startI = a.length ? a[0] : 0; }
      else {
        startI = parseInt(saved, 10) || 0; if (!avail(startI)) { var a2 = availList(); startI = a2.length ? a2[0] : 0; }
        if (STEPS[startI] && STEPS[startI].to && STEPS[startI].to === curKey()) {
          for (var i = startI + 1; i < STEPS.length; i++) if (avail(i)) { startI = i; break; }
        }
        if (STEPS[startI] && STEPS[startI].page && STEPS[startI].page !== curKey()) {
          for (var j = 0; j < STEPS.length; j++) if (avail(j) && STEPS[j].page === curKey()) { startI = j; break; }
        }
      }
      var wait = restart && (parseInt(window.ASTA_STREAK_GAIN, 10) || 0) > 0 ? 3200 : 350;
      setTimeout(function () { show(startI); }, wait);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', begin); else begin();
  })();
})();

/* -------------------------------------------------------------------------
 * Streak-Pausen-Hinweis ein-/ausklappen.
 * Das ✕ im Hinweis lässt ein Symbol (Schneeflocke bzw. Flamme)
 * fliegen: auf großen Bildschirmen in die Titelleiste neben den Design-Umschalter, auf dem
 * Handy oben rechts in die Begrüßungs-Karte (dort ist die Titelleiste in der installierten App
 * ausgeblendet). Ein Klick auf das Symbol holt den Hinweis zurück.
 * Gemerkt wird der Zustand im Browser – mit der Kennung der laufenden Pause, damit
 * eine NEUE vorlesungsfreie Zeit den Hinweis wieder von selbst zeigt.
 * ------------------------------------------------------------------------- */
(function () {
  var KEY = 'asta-lb-tuck';
  var html = document.documentElement;
  var alle = document.querySelectorAll('.lb-chip');
  if (!alle.length) return;                // keine Pause aktiv → nichts zu tun
  var lbKey = alle[0].getAttribute('data-lb-key') || '';
  var calm = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Es gibt den Knopf zweimal (Titelleiste und Hero) – welcher gilt, entscheidet dieselbe
     Grenze wie im CSS. Wichtig: In der installierten App ist die Titelleiste auf dem Handy
     ausgeblendet, ein Flug dorthin würde also im Nichts endet. */
  function chipNow() {
    var hero = document.querySelector('.lb-chip-hero');
    var bar  = document.querySelector('.lb-chip-bar');
    var schmal = window.matchMedia && matchMedia('(max-width: 720px)').matches;
    if (hero && schmal) return hero;
    return bar || hero;
  }

  function banner() { return document.getElementById('lbBanner'); }
  function store(on) { try { on ? localStorage.setItem(KEY, lbKey) : localStorage.removeItem(KEY); } catch (e) {} }

  /* Maße eines per CSS ausgeblendeten Elements – kurz sichtbar (aber unsichtbar) messen. */
  function rectOf(el, show) {
    if (!el) return null;
    if (!show) return el.getBoundingClientRect();
    var prev = el.getAttribute('style') || '';
    el.style.display = 'inline-flex'; el.style.visibility = 'hidden';
    var r = el.getBoundingClientRect();
    el.setAttribute('style', prev);
    return r;
  }

  /* Symbol von A nach B fliegen lassen; ruft done() am Ende auf (auch ohne Animation). */
  function fly(from, to, iconClass, spin, done) {
    if (calm || !from || !to || !from.width || !to.width) { done(); return; }
    var g = document.createElement('span');
    g.className = 'lb-ghost';
    g.innerHTML = '<i class="ti ' + iconClass + '"></i>';
    g.style.left = from.left + 'px';
    g.style.top = from.top + 'px';
    g.style.fontSize = Math.max(from.height, 18) + 'px';
    document.body.appendChild(g);
    var dx = to.left + to.width / 2 - (from.left + from.width / 2);
    var dy = to.top + to.height / 2 - (from.top + from.height / 2);
    var scale = Math.max(0.45, Math.min(1, to.height / Math.max(from.height, 1)));
    var anim = g.animate(
      [{ transform: 'none', opacity: 1 },
       { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + scale + ') rotate(' + spin + 'deg)', opacity: .85 }],
      { duration: 560, easing: 'cubic-bezier(.35,.05,.2,1)' });
    // Wettlauf zwischen Animations-Ende und Zeitschalter, und was zuerst kommt, gewinnt EINMAL:
    // in einem versteckten Tab (anderer Tab, Handy gesperrt) laufen Web-Animationen gar nicht,
    // ihr `finished` löst nie aus – ohne dieses Netz bliebe der Hinweis stehen.
    var over = false;
    var fin = function () {
      if (over) return;
      over = true;
      if (g.parentNode) g.parentNode.removeChild(g);
      done();
    };
    if (anim && anim.finished && anim.finished.then) anim.finished.then(fin, fin);
    setTimeout(fin, 640);
  }

  /* Hinweis wegklicken: Symbol fliegt in die Leiste. */
  document.addEventListener('click', function (e) {
    var x = e.target.closest ? e.target.closest('.lb-x') : null;
    if (!x) return;
    var b = banner(); if (!b) return;
    var chip = chipNow(); if (!chip) return;
    var mark = b.querySelector('.lb-mark');
    var from = rectOf(mark || b, false);
    var to = rectOf(chip, true);
    var icon = (mark && mark.className.match(/ti-[a-z0-9-]+/) || ['ti-snowflake'])[0];
    b.classList.add('lb-out');
    fly(from, to, icon, 380, function () {
      b.classList.remove('lb-out');
      html.setAttribute('data-lb-tuck', '1');
      store(true);
      chip.classList.remove('lb-pop');
      void chip.offsetWidth;                 // Reflow, damit die Animation auch beim 2. Mal läuft
      chip.classList.add('lb-pop');
    });
  });

  /* Symbol anklicken: Hinweis zurückholen – auf anderen Seiten geht es dafür aufs Dashboard.
     Delegiert, damit beide Sitzplätze (Titelleiste und Hero) denselben Weg nehmen. */
  document.addEventListener('click', function (e) {
    var chip = e.target.closest ? e.target.closest('.lb-chip') : null;
    if (!chip) return;
    var b = banner();
    if (!b) { store(false); location.href = chip.getAttribute('data-lb-home') || 'dashboard.php'; return; }
    var from = rectOf(chip, false);
    html.removeAttribute('data-lb-tuck');    // Hinweis ist wieder da, das Symbol verschwindet
    store(false);
    var mark = b.querySelector('.lb-mark');
    var to = rectOf(mark || b, false);
    var icon = (mark && mark.className.match(/ti-[a-z0-9-]+/) || ['ti-snowflake'])[0];
    b.classList.add('lb-in');
    setTimeout(function () { b.classList.remove('lb-in'); }, 400);
    fly(from, to, icon, -380, function () {});
  });
})();

/* Wegweiser: Sofort-Suche über alle Kacheln (wegweiser.php). Rein clientseitig – das Verzeichnis
   ist überschaubar, ein Server-Rundgang pro Tastendruck wäre Verschwendung. Gefiltert wird über
   data-ww (Name, Notiz, Adresse, Rubrik – kleingeschrieben vom Server geliefert); eine Rubrik
   ohne Treffer verschwindet mitsamt Überschrift. */
(function () {
  var feld = document.getElementById('wwSearch');
  if (!feld) return;
  var items = Array.prototype.slice.call(document.querySelectorAll('.ww-item'));
  var gruppen = Array.prototype.slice.call(document.querySelectorAll('.ww-group'));
  var leer = document.querySelector('.ww-empty');
  var x = document.querySelector('.ww-clear');

  function norm(s) { return (s || '').toLowerCase().trim(); }

  function filtern() {
    var q = norm(feld.value);
    if (x) x.hidden = q === '';
    var treffer = 0;
    items.forEach(function (it) {
      // Mehrere Wörter: alle müssen vorkommen, Reihenfolge egal („studi werk")
      var hay = it.getAttribute('data-ww') || '';
      var ok = q === '' || q.split(/\s+/).every(function (w) { return hay.indexOf(w) !== -1; });
      it.hidden = !ok;
      if (ok) treffer++;
    });
    gruppen.forEach(function (g) {
      g.hidden = !g.querySelector('.ww-item:not([hidden])');
    });
    if (leer) leer.hidden = treffer > 0;
    // Nichts gefunden? Die Lupe kippt einmal ratlos zur Seite. Die Klasse wird beim Wechsel
    // zurück auf 0 Treffer neu gesetzt, sonst liefe die Animation nur ein einziges Mal.
    var bar = feld.closest('.ww-searchbar');
    if (bar) {
      var ratlos = q !== '' && treffer === 0;
      if (ratlos && !bar.classList.contains('ww-leer')) {
        bar.classList.remove('ww-leer');
        void bar.offsetWidth;                // Reflow erzwingen
        bar.classList.add('ww-leer');
      } else if (!ratlos) {
        bar.classList.remove('ww-leer');
      }
    }
  }

  feld.addEventListener('input', filtern);
  feld.addEventListener('keydown', function (e) { if (e.key === 'Escape') { feld.value = ''; filtern(); } });
  if (x) x.addEventListener('click', function () { feld.value = ''; filtern(); feld.focus(); });
})();

/* Kleine Feier-Momente („Puster"): Ein Element ploppt kurz auf und pustet ein, zwei Symbole
   nach oben links und rechts weg – wie das Herzchen im Pat:innenprogramm.

   Der übliche Weg ist der KLICK-Weg: Ein Knopf mit data-puste spielt den Effekt sofort an sich
   selbst ab und schickt sein Formular im Hintergrund los; sobald die Antwort da ist, lädt die
   Seite ganz normal neu. Damit sitzt der Effekt dort, wo geklickt wurde, er ist vor dem Neuaufbau
   zu Ende – und weil die Seite danach komplett neu kommt, stimmt hinterher jede Zahl von selbst.
   Ohne JavaScript bleibt es ein ganz gewöhnliches Formular.

   Daneben gibt es den SERVER-Weg (window.ASTA_PUSTE): für Formulare, die man nicht in den
   Hintergrund schieben will – beim Belegblatt etwa hängen Datei-Uploads dran, und ohne die
   Fortschrittsanzeige des Browsers sähe ein langer Upload aus wie ein Absturz. */
(function () {
  var REG = {
    zusage:  { ic: 'ti-check',           n: 2, farbe: 'p-gruen'  },
    aufgabe: { ic: 'ti-check',           n: 2, farbe: 'p-gruen'  },
    beleg:   { ic: 'ti-receipt-2',       n: 2, farbe: 'p-petrol' },
    stimme:  { ic: '',                   n: 0, farbe: ''         },  // schnappt nur ein
    tausch:  { ic: 'ti-arrows-exchange', n: 2, farbe: 'p-petrol' }
  };
  // Wer weniger Bewegung eingestellt hat, bekommt gar nichts – nicht bloß langsamer.
  function ruhig() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

  /* Effekt an einem Element abspielen. Öffentlich als window.astaPuste, damit auch die
     Test-Knöpfe in der Diagnose denselben Weg nehmen. */
  function pusteAn(el, moment) {
    var cfg = REG[moment];
    if (!el || !cfg || ruhig()) return;
    el.classList.add('puste-pop');
    setTimeout(function () { el.classList.remove('puste-pop'); }, 800);
    if (!cfg.n || !cfg.ic) return;
    var r = el.getBoundingClientRect();
    for (var i = 0; i < cfg.n; i++) {
      (function (i) {
        var t = document.createElement('i');
        t.className = 'ti ' + cfg.ic + ' puste-teil ' + (i % 2 ? 'puste-rechts' : 'puste-links') + (cfg.farbe ? ' ' + cfg.farbe : '');
        t.style.left = (r.left + r.width / 2) + 'px';
        // Bei hohen Karten nicht aus der Mitte starten, sondern knapp unter der Oberkante
        t.style.top = (r.top + Math.min(r.height / 2, 30)) + 'px';
        t.style.animationDelay = (i * 110) + 'ms';
        document.body.appendChild(t);
        setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 1800 + i * 110);
      })(i);
    }
  }
  window.astaPuste = pusteAn;

  /* Nach dem Absenden dorthin gehen, wo der Server hinzeigt. Zeigt er auf dieselbe Seite
     (der Normalfall), muss es ein echtes Neuladen sein – ein bloßes Setzen der Adresse würde
     bei gleicher Seite nur zum Anker springen und den alten Stand stehen lassen. */
  function gehZu(url) {
    var a = (url || '').split('#')[0], b = location.href.split('#')[0];
    if (a === '' || a === b) {
      var hash = (url || '').split('#')[1] || '';
      if (hash) location.hash = hash;
      location.reload();
    } else {
      location.href = url;
    }
  }

  document.addEventListener('click', function (e) {
    var knopf = e.target.closest ? e.target.closest('[data-puste]') : null;
    if (!knopf || knopf.disabled) return;
    var moment = knopf.getAttribute('data-puste');
    if (!REG[moment]) return;
    var form = knopf.form || (knopf.closest ? knopf.closest('form') : null);
    if (!form) { pusteAn(knopf, moment); return; }   // Vorführ-Knopf ohne Formular (Diagnose)
    // Formulare mit Rückfrage laufen über den Bestätigungsdialog – da gehört kein Effekt hin.
    if (form.hasAttribute('data-confirm') || !window.fetch || !window.FormData) return;
    e.preventDefault();
    pusteAn(knopf, moment);
    var daten = new FormData(form);
    // Der gedrückte Knopf zählt bei FormData NICHT mit – bei „Dabei/Vielleicht/Nicht" hängt aber
    // genau daran, was gemeint war.
    if (knopf.name) daten.append(knopf.name, knopf.value);
    knopf.disabled = true;
    fetch(form.getAttribute('action') || location.href, {
      method: 'POST', body: daten, credentials: 'same-origin'
    }).then(function (r) {
      gehZu(r.url);
    }).catch(function () {
      // Netz weg oder Server stumm: zurück auf den ganz normalen Weg, damit nichts verloren geht.
      knopf.disabled = false;
      form.submit();
    });
  });

  // Server-Weg: einmal pro Seitenaufruf, am markierten Ziel (.puste-ziel).
  (function () {
    var moment = window.ASTA_PUSTE || '';
    if (!moment || !REG[moment] || ruhig()) return;
    function los() {
      var ziel = document.querySelector('.puste-ziel');
      if (ziel) pusteAn(ziel, moment);
    }
    // Kurz warten: Die Seite springt nach der Weiterleitung erst noch zum Anker.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(los, 260); });
    else setTimeout(los, 260);
  })();
})();

/* Telefonnummer im Profil: Sie steht bewusst NICHT im Quelltext. Der Knopf zeigt erst die Bitte
   der Person („nur im Notfall", „kein WhatsApp" …); wer zusagt, holt die Nummer per Anfrage nach.
   Danach steht sie als Wähl-Link da, damit man vom Handy direkt anrufen kann. */
(function () {
  /* Steht IMMER im Dialog, zusätzlich zur persönlichen Bitte – die Hausregel gilt unabhängig
     davon, was die einzelne Person aufschreibt. (.modal-msg hat white-space: pre-line, der
     Absatzumbruch kommt also an.) */
  var HAUSREGEL = 'Grundregel: Telefonnummern in dieser App sind ausschließlich AStA-intern. '
                + 'Gib sie nicht weiter – nicht an Externe, nicht in Gruppen oder Chats, und nicht für andere Zwecke als den hier genannten.';

  document.addEventListener('click', function (e) {
    var knopf = e.target.closest ? e.target.closest('.profil-tel[data-tel-id]') : null;
    if (!knopf) return;
    var name = knopf.getAttribute('data-tel-name') || 'dieser Person';
    var bitte = knopf.getAttribute('data-tel-note') || '';
    astaConfirm({
      title: 'Telefonnummer von ' + name,
      icon: 'ti-phone',
      message: (bitte ? bitte + '\n\n' : '') + HAUSREGEL,
      buttons: [
        { label: 'Abbrechen', class: 'secondary' },
        { label: 'Verstanden – anzeigen', class: '', onClick: function () { holen(knopf); } }
      ]
    });
  });

  function holen(knopf) {
    var daten = new FormData();
    daten.append('action', 'phone_reveal');
    daten.append('csrf', window.ASTA_CSRF || '');
    // ASTA_BASE, weil derselbe Knopf auch in der Diagnose (Unterordner admin/) stehen kann
    fetch((window.ASTA_BASE || '') + 'profil.php?id=' + encodeURIComponent(knopf.getAttribute('data-tel-id')), {
      method: 'POST', body: daten, credentials: 'same-origin', headers: { 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.ok || !d.phone) { zeigeFehler(knopf); return; }
      var a = document.createElement('a');
      a.className = 'btn secondary small profil-tel puste-pop';
      a.href = 'tel:' + (d.dial || d.phone);
      a.innerHTML = '<i class="ti ti-phone"></i> ';
      a.appendChild(document.createTextNode(d.phone));
      knopf.replaceWith(a);
    }).catch(function () { zeigeFehler(knopf); });
  }

  function zeigeFehler(knopf) {
    knopf.disabled = true;
    knopf.innerHTML = '<i class="ti ti-alert-triangle"></i> ';
    knopf.appendChild(document.createTextNode('Nummer nicht abrufbar'));
  }
})();

/* Ablage-Picker: Dateien aus Teams/Nextcloud anhängen, ohne sie erst herunterzuladen.
   Eigener Dialog statt der allgemeinen Auswahl-Liste: Ein Ordner kann hundert Einträge haben,
   und dafür braucht es einen festen Kopf (Ablage-Umschalter + Pfad) über einem Bereich, der
   scrollt. Die Auswahl reist als picked_store[]/picked_ref[] mit dem Formular mit. */
(function () {
  var box = null;      // das Feld im Formular, zu dem der offene Dialog gehört

  function fmtGroesse(b) {
    if (!b) return '';
    if (b < 1024) return b + ' B';
    if (b < 1024 * 1024) return Math.round(b / 1024) + ' KB';
    return (b / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB';
  }

  function ensure() {
    var o = document.getElementById('asta-pick');
    if (o) return o;
    o = document.createElement('div');
    o.id = 'asta-pick';
    o.className = 'modal-overlay';
    o.hidden = true;
    o.innerHTML =
        '<div class="modal pick-modal" role="dialog" aria-modal="true" aria-labelledby="pick-title">'
      + '<h3 class="modal-title" id="pick-title"><i class="ti ti-folder-search"></i> Aus der Ablage wählen</h3>'
      + '<div class="pick-stores"></div>'
      + '<nav class="pick-crumbs" aria-label="Ordnerpfad"></nav>'
      + '<div class="pick-body"></div>'
      + '<p class="pick-status small muted"></p>'
      + '<div class="modal-actions">'
      + '<button type="button" class="btn" data-pick-close>Fertig</button>'
      + '</div></div>';
    document.body.appendChild(o);
    o.addEventListener('click', function (e) {
      if (e.target === o || (e.target.closest && e.target.closest('[data-pick-close]'))) zu();
    });
    document.addEventListener('keydown', function (e) {
      if (!o.hidden && e.key === 'Escape') zu();
    });
    return o;
  }
  function zu() { var o = document.getElementById('asta-pick'); if (o) o.hidden = true; }

  // --- Auswahl: versteckte Felder im Formular, dazu ein Chip zum Wiederwegnehmen ---------
  function schluessel(s, r) { return s + ' ' + r; }
  function gewaehlt(s, r) {
    if (!box) return null;
    var treffer = null;
    box.querySelectorAll('.ablage-chip').forEach(function (c) {
      if (c.getAttribute('data-key') === schluessel(s, r)) treffer = c;
    });
    return treffer;
  }
  // Gibt zurück, ob die Datei JETZT gewählt ist (zum Setzen des Hakens in der Zeile).
  function waehle(s, r, name, label) {
    var da = gewaehlt(s, r);
    if (da) { da.remove(); return false; }
    var chip = document.createElement('span');
    chip.className = 'ablage-chip';
    chip.setAttribute('data-key', schluessel(s, r));
    var ic = document.createElement('i'); ic.className = 'ti ti-file-check'; chip.appendChild(ic);
    chip.appendChild(document.createTextNode(' ' + name + ' '));
    var q = document.createElement('span'); q.className = 'small muted'; q.textContent = '(' + label + ')';
    chip.appendChild(q);
    var fs = document.createElement('input'); fs.type = 'hidden'; fs.name = 'picked_store[]'; fs.value = s;
    var fr = document.createElement('input'); fr.type = 'hidden'; fr.name = 'picked_ref[]';   fr.value = r;
    chip.appendChild(fs); chip.appendChild(fr);
    var x = document.createElement('button');
    x.type = 'button'; x.className = 'ablage-chip-x'; x.title = 'Doch nicht anhängen';
    x.innerHTML = '<i class="ti ti-x"></i>';
    x.addEventListener('click', function () {
      chip.remove();
      var o = document.getElementById('asta-pick');   // offener Dialog: Haken wieder wegnehmen
      if (o && !o.hidden) o.querySelectorAll('.pick-row').forEach(function (z) {
        if (z.getAttribute('data-key') === schluessel(s, r)) z.classList.remove('is-on');
      });
    });
    chip.appendChild(x);
    box.querySelector('.ablage-picked').appendChild(chip);
    return true;
  }

  // --- Kopfzeile: Ablagen-Umschalter und Pfad --------------------------------------------
  function kopf(o, d) {
    var us = o.querySelector('.pick-stores');
    us.innerHTML = '';
    var namen = Object.keys(d.stores || {});
    us.hidden = namen.length < 2;   // nur eine Ablage eingerichtet: kein Umschalter nötig
    namen.forEach(function (s) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'pick-store' + (s === d.store ? ' is-on' : '');
      b.textContent = d.stores[s];
      b.addEventListener('click', function () { if (s !== d.store) lade(s, ''); });
      us.appendChild(b);
    });

    var cr = o.querySelector('.pick-crumbs');
    cr.innerHTML = '';
    var teile = (d.path || '') === '' ? [] : d.path.split('/');
    var kette = [{ t: d.label || 'Hauptordner', p: '' }];
    var lauf = '';
    teile.forEach(function (t) { lauf = lauf ? lauf + '/' + t : t; kette.push({ t: t, p: lauf }); });
    kette.forEach(function (k, i) {
      if (i) {
        var sep = document.createElement('span');
        sep.className = 'pick-sep'; sep.textContent = '/';
        cr.appendChild(sep);
      }
      if (i === kette.length - 1) {
        var hier = document.createElement('span');
        hier.className = 'pick-here'; hier.textContent = k.t;
        cr.appendChild(hier);
      } else {
        var a = document.createElement('button');
        a.type = 'button'; a.className = 'pick-up'; a.textContent = k.t;
        a.addEventListener('click', function () { lade(d.store, k.p); });
        cr.appendChild(a);
      }
    });
  }

  function zeile(e, d) {
    var r = document.createElement('button');
    r.type = 'button';
    r.className = 'pick-row' + (e.dir ? ' is-dir' : '');
    r.setAttribute('data-key', schluessel(d.store, e.ref));
    if (!e.dir && gewaehlt(d.store, e.ref)) r.classList.add('is-on');
    var ic = document.createElement('i');
    ic.className = 'ti ' + (e.dir ? 'ti-folder' : 'ti-file') + ' pick-ic';
    r.appendChild(ic);
    var tx = document.createElement('span'); tx.className = 'pick-name'; tx.textContent = e.name;
    r.appendChild(tx);
    var gr = document.createElement('span'); gr.className = 'pick-size';
    gr.textContent = e.dir ? '' : fmtGroesse(e.size);
    r.appendChild(gr);
    var go = document.createElement('i');
    go.className = 'ti ' + (e.dir ? 'ti-chevron-right' : 'ti-check') + ' pick-go';
    r.appendChild(go);
    r.addEventListener('click', function () {
      if (e.dir) { lade(d.store, e.ref); return; }
      // Datei: an- oder abwählen – der Dialog bleibt offen, damit man mehrere nehmen kann
      r.classList.toggle('is-on', waehle(d.store, e.ref, e.name, d.label));
    });
    return r;
  }

  // Zwei der Formulare (Umlauf/Abstimmung anlegen) stecken in einem echten <dialog>. Ein solches
  // liegt im Top-Layer und damit über ALLEM, was z-index hergibt – ein Overlay am <body> wäre
  // unsichtbar dahinter. Also hängt sich der Picker in den offenen Dialog hinein, dann gehört er
  // zum selben Top-Layer. (Gleiche Klasse Falle wie flatpickr, das im <dialog> static:true braucht.)
  function einhaengen(o) {
    var offen = document.querySelector('dialog[open]');
    var ziel = offen || document.body;
    if (o.parentNode !== ziel) ziel.appendChild(o);
  }

  function lade(s, p) {
    var o = ensure();
    einhaengen(o);
    o.hidden = false;
    var body = o.querySelector('.pick-body');
    var stat = o.querySelector('.pick-status');
    stat.className = 'pick-status small muted';
    stat.textContent = 'lädt …';
    var kind = box.getAttribute('data-kind') || '';
    var base = box.getAttribute('data-base') || (window.ASTA_BASE || '');
    var url = base + 'ablage.php?kind=' + encodeURIComponent(kind)
            + '&store=' + encodeURIComponent(s || '') + '&path=' + encodeURIComponent(p || '');
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) {
          body.innerHTML = '';
          stat.className = 'pick-status small pick-fehler';
          stat.textContent = (d && d.error) ? d.error : 'Die Ablage antwortet gerade nicht.';
          return;
        }
        kopf(o, d);
        body.innerHTML = '';
        body.scrollTop = 0;
        var ordner = 0, dateien = 0;
        (d.entries || []).forEach(function (e) {
          if (e.dir) { ordner++; } else { dateien++; }
          body.appendChild(zeile(e, d));
        });
        stat.className = 'pick-status small muted';
        stat.textContent = (d.entries && d.entries.length)
          ? ordner + ' Ordner · ' + dateien + ' anhängbare Datei' + (dateien === 1 ? '' : 'en')
          : 'Hier liegt nichts, was sich anhängen lässt.';
      })
      .catch(function () {
        body.innerHTML = '';
        stat.className = 'pick-status small pick-fehler';
        stat.textContent = 'Die Verbindung hat nicht geklappt. Später noch einmal versuchen.';
      });
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.ablage-pick-btn') : null;
    if (!btn) return;
    ev.preventDefault();
    box = btn.closest('.ablage-pick');
    if (box) lade('', '');
  });
})();

/* Zuständigkeits-Umschalter (Referate | Personen, siehe zust_picker_html in lib.php):
   zeigt beim Klick sofort die passende Liste. Rein zur Vorschau – gespeichert wird erst beim
   Absenden, und der Server entscheidet ohnehin allein anhand des gewählten Modus. Ohne dieses
   Skript bleibt die gespeicherte Liste stehen; dann schaltet man in zwei Schritten. */
(function () {
  document.addEventListener('change', function (e) {
    var r = e.target;
    if (!r || r.name !== 'zust_modus') return;
    var box = r.closest('.zust-wahl');
    if (!box) return;
    var personen = r.value === 'personen';
    var ref = box.querySelector('.zust-ref'), per = box.querySelector('.zust-pers');
    if (ref) ref.hidden = personen;
    if (per) per.hidden = !personen;
  });
})();
