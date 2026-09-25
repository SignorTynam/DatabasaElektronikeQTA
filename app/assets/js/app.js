/* ============================================================================
   THEMELI — sjellja e panelit (faqet pas hyrjes).
   Vetëm shtresa e ndërfaqes: asnjë endpoint, leje apo rregull biznesi nuk
   ndryshon këtu.
     1. Pamja (e çelët · sipas pajisjes · e errët)
     2. Menuja anësore dhe sirtari në celular
     3. Kthimi në krye, ankorat, tooltip-et
     4. Filtrimi i menjëhershëm i tabelave
     5. Renditja e kolonave
     6. Njoftimet (toast) dhe dialogu i konfirmimit
     7. Kopjo, shfaq fjalëkalimin, gjendja "po punon"
     8. Kërkimi në regjistër (Ctrl K)
   ========================================================================= */
(function () {
  'use strict';

  var root = document.documentElement;
  var THEME_KEY = 'qta_theme';
  var SIDEBAR_KEY = 'qta_sidebar';

  function store(key, value) {
    try { localStorage.setItem(key, value); } catch (e) { /* ruajtja e bllokuar */ }
  }
  function read(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ------------------------------------------------------------- 1. Pamja */
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function currentMode() {
    var mode = read(THEME_KEY);
    return mode === 'light' || mode === 'dark' ? mode : 'system';
  }

  function applyTheme(mode) {
    var dark = mode === 'dark' || (mode === 'system' && media && media.matches);
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-theme-mode', mode);
    document.querySelectorAll('[data-theme-set]').forEach(function (item) {
      item.setAttribute('aria-checked', item.getAttribute('data-theme-set') === mode ? 'true' : 'false');
    });
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-label', dark ? 'Kalo në pamjen e çelët' : 'Kalo në pamjen e errët');
      btn.setAttribute('title', dark ? 'Pamja e çelët' : 'Pamja e errët');
      var icon = btn.querySelector('i');
      if (icon) icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
    });
  }

  function setTheme(mode) {
    store(THEME_KEY, mode);
    applyTheme(mode);
  }

  applyTheme(currentMode());
  if (media && media.addEventListener) {
    media.addEventListener('change', function () {
      if (currentMode() === 'system') applyTheme('system');
    });
  }

  /* ---------------------------------------- 2. Menuja anësore dhe sirtari */
  var sidebar = document.querySelector('[data-sidebar]');
  var backdrop = document.querySelector('.sidebar-backdrop');
  var drawerTrigger = null;
  var desktop = window.matchMedia('(min-width: 992px)');

  function paintRailButtons() {
    var rail = root.getAttribute('data-sidebar') === 'rail';
    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', rail ? 'true' : 'false');
      btn.setAttribute('aria-label', rail ? 'Zgjero menunë' : 'Ngushto menunë');
      btn.setAttribute('title', rail ? 'Zgjero menunë' : 'Ngushto menunë');
    });
  }

  function toggleRail() {
    var rail = root.getAttribute('data-sidebar') === 'rail';
    if (rail) root.removeAttribute('data-sidebar'); else root.setAttribute('data-sidebar', 'rail');
    store(SIDEBAR_KEY, rail ? 'expanded' : 'collapsed');
    paintRailButtons();
  }
  paintRailButtons();

  function focusables(container) {
    return Array.prototype.filter.call(
      container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
      function (el) { return el.offsetParent !== null; }
    );
  }

  function setInert(on) {
    ['.topbar', 'main', '.app-footer', '.back-top'].forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        if (on) el.setAttribute('inert', ''); else el.removeAttribute('inert');
      });
    });
  }

  function openDrawer(trigger) {
    if (!sidebar || desktop.matches) return;
    drawerTrigger = trigger || document.querySelector('[data-drawer-open]');
    sidebar.classList.add('is-open');
    sidebar.setAttribute('role', 'dialog');
    sidebar.setAttribute('aria-modal', 'true');
    if (backdrop) {
      backdrop.hidden = false;
      requestAnimationFrame(function () { backdrop.classList.add('is-on'); });
    }
    document.querySelectorAll('[data-drawer-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
    document.body.style.overflow = 'hidden';
    setInert(true);
    var first = sidebar.querySelector('.nav-item.is-active') || focusables(sidebar)[0];
    if (first) setTimeout(function () { first.focus(); }, 60);
  }

  function closeDrawer(restoreFocus) {
    if (!sidebar || !sidebar.classList.contains('is-open')) return;
    sidebar.classList.remove('is-open');
    sidebar.removeAttribute('role');
    sidebar.removeAttribute('aria-modal');
    if (backdrop) {
      backdrop.classList.remove('is-on');
      setTimeout(function () { backdrop.hidden = true; }, 200);
    }
    document.querySelectorAll('[data-drawer-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    document.body.style.overflow = '';
    setInert(false);
    if (restoreFocus !== false && drawerTrigger) drawerTrigger.focus();
  }

  if (desktop.addEventListener) {
    desktop.addEventListener('change', function (e) { if (e.matches) closeDrawer(false); });
  }

  /* ------------------------------------------ 3. Klikimet e përgjithshme */
  document.addEventListener('click', function (event) {
    var t = event.target;
    if (!t.closest) return;

    var themeSet = t.closest('[data-theme-set]');
    if (themeSet) { setTheme(themeSet.getAttribute('data-theme-set')); return; }

    var themeToggle = t.closest('[data-theme-toggle]');
    if (themeToggle) {
      event.preventDefault();
      setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
      return;
    }

    if (t.closest('[data-sidebar-toggle]')) { event.preventDefault(); toggleRail(); return; }

    var opener = t.closest('[data-drawer-open]');
    if (opener) { event.preventDefault(); openDrawer(opener); return; }

    if (t.closest('[data-drawer-close]')) { event.preventDefault(); closeDrawer(); return; }

    if (sidebar && sidebar.classList.contains('is-open') && t.closest('.sidebar a.nav-item')) {
      closeDrawer(false);
    }

    if (t.closest('[data-back-top]')) {
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    /* Menuja publike në celular */
    var mast = t.closest('[data-mast-toggle]');
    if (mast) {
      event.preventDefault();
      var nav = document.getElementById(mast.getAttribute('aria-controls') || 'mastNav');
      if (nav) {
        var open = nav.classList.toggle('is-open');
        mast.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      return;
    }
    var mastOpen = document.querySelector('.masthead-nav.is-open');
    if (mastOpen && (t.closest('.masthead-nav a') || !t.closest('.masthead'))) {
      mastOpen.classList.remove('is-open');
      var mb = document.querySelector('[data-mast-toggle]');
      if (mb) mb.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    var nav = document.querySelector('.masthead-nav.is-open');
    if (!nav) return;
    nav.classList.remove('is-open');
    var btn = document.querySelector('[data-mast-toggle]');
    if (btn) { btn.setAttribute('aria-expanded', 'false'); btn.focus(); }
  });

  document.addEventListener('keydown', function (event) {
    if (!sidebar || !sidebar.classList.contains('is-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); closeDrawer(); return; }
    if (event.key === 'Tab') {
      var items = focusables(sidebar);
      if (!items.length) return;
      var first = items[0], last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });

  var toTop = document.querySelector('[data-back-top]');
  if (toTop) {
    var onScroll = function () { toTop.classList.toggle('is-on', window.scrollY > 480); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  if (window.bootstrap && window.bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new window.bootstrap.Tooltip(el);
    });
  }

  /* --------------------------------------- 4. Filtrimi i menjëhershëm */
  /* Mban parasysh tabelat me trupa të shumtë (grupet), numëron sa mbeten
     dhe pranon filtra të shpejtë me një klik. */
  document.querySelectorAll('[data-tfilter]').forEach(function (box) {
    var input = box.querySelector('[data-table-filter]');
    var count = box.querySelector('[data-tfilter-count]');
    var clear = box.querySelector('[data-tfilter-clear]');
    var chips = box.querySelectorAll('[data-tfilter-chip]');
    if (!input) return;

    var sel = input.getAttribute('data-table-filter');
    var table = sel ? document.querySelector(sel) : document.querySelector('main table');
    if (!table) return;

    var multi = table.tBodies.length > 1;
    var units = multi
      ? Array.prototype.slice.call(table.tBodies)
      : Array.prototype.slice.call(table.tBodies[0] ? table.tBodies[0].rows : []);
    var total = units.length;
    var noun = box.getAttribute('data-tfilter-noun') || 'rreshta';

    /* Teksti i dukshëm i rreshtit. Opsionet e listave rënëse nuk llogariten
       (përndryshe çdo rresht me listë grupesh do të përputhej me çdo modul);
       merret vetëm vlera e zgjedhur. Llogaritet sa herë, se teksti ndryshon. */
    function textOf(unit) {
      var out = '';
      var walker = document.createTreeWalker(unit, NodeFilter.SHOW_TEXT, {
        acceptNode: function (n) {
          return n.parentElement && n.parentElement.closest('select, script, template') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
        }
      });
      while (walker.nextNode()) out += ' ' + walker.currentNode.nodeValue;
      Array.prototype.forEach.call(unit.querySelectorAll('select'), function (s) {
        var o = s.options[s.selectedIndex];
        if (o && o.value !== '') out += ' ' + o.textContent;
      });
      return out.replace(/\s+/g, ' ').toLowerCase();
    }

    function apply() {
      var needle = input.value.trim().toLowerCase();
      var shown = 0;
      units.forEach(function (u) {
        var hit = needle === '' || textOf(u).indexOf(needle) !== -1;
        if (multi) u.style.display = hit ? '' : 'none'; else u.hidden = !hit;
        if (hit) shown++;
      });
      box.classList.toggle('is-on', needle !== '');
      if (count) {
        count.textContent = needle === '' ? total + ' ' + noun : shown + ' nga ' + total + ' ' + noun;
        count.classList.toggle('is-narrowed', needle !== '');
      }
    }

    var timer = null;
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(apply, 90); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); input.value = ''; apply(); }
    });
    if (clear) clear.addEventListener('click', function () {
      input.value = '';
      Array.prototype.forEach.call(chips, function (c) { c.classList.remove('is-on'); c.setAttribute('aria-pressed', 'false'); });
      apply();
      input.focus();
    });
    Array.prototype.forEach.call(chips, function (chip) {
      chip.setAttribute('aria-pressed', 'false');
      chip.addEventListener('click', function () {
        var on = chip.classList.contains('is-on');
        Array.prototype.forEach.call(chips, function (c) { c.classList.remove('is-on'); c.setAttribute('aria-pressed', 'false'); });
        if (on) {
          input.value = '';
        } else {
          chip.classList.add('is-on');
          chip.setAttribute('aria-pressed', 'true');
          input.value = chip.getAttribute('data-tfilter-chip') || '';
        }
        apply();
      });
    });
    apply();
  });

  /* ------------------------------------------------- 5. Renditja e kolonave */
  function cellKey(row, index, kind) {
    var cell = row.cells[index];
    if (!cell) return kind === 'text' ? '' : 0;
    var raw = (cell.getAttribute('data-sort-value') || cell.textContent || '').trim();
    if (kind === 'num') {
      var n = parseFloat(raw.replace(/[^0-9.,-]/g, '').replace(/\.(?=\d{3})/g, '').replace(',', '.'));
      return isNaN(n) ? -Infinity : n;
    }
    if (kind === 'date') {
      var m = raw.match(/(\d{2})[-.\/](\d{2})[-.\/](\d{4})/);
      if (m) return new Date(+m[3], +m[2] - 1, +m[1]).getTime();
      var d = Date.parse(raw);
      return isNaN(d) ? -Infinity : d;
    }
    return raw.toLowerCase();
  }

  function sortTable(table, index, kind, dir) {
    var multi = table.tBodies.length > 1;
    var groups = multi
      ? Array.prototype.slice.call(table.tBodies)
      : Array.prototype.slice.call(table.tBodies[0] ? table.tBodies[0].rows : []);
    var keyed = groups.map(function (node) {
      var row = node.tagName === 'TBODY' ? node.rows[0] : node;
      return { node: node, key: cellKey(row, index, kind) };
    });
    keyed.sort(function (a, b) {
      if (typeof a.key === 'string' && typeof b.key === 'string') return a.key.localeCompare(b.key, 'sq') * dir;
      if (a.key < b.key) return -dir;
      if (a.key > b.key) return dir;
      return 0;
    });
    var parent = multi ? table : table.tBodies[0];
    keyed.forEach(function (k) { parent.appendChild(k.node); });
  }

  document.querySelectorAll('table[data-sortable]').forEach(function (table) {
    var head = table.tHead;
    if (!head) return;
    Array.prototype.forEach.call(head.rows[0].cells, function (th, index) {
      var kind = th.getAttribute('data-sort');
      if (!kind || kind === 'none') return;
      th.setAttribute('tabindex', '0');
      th.setAttribute('aria-sort', 'none');
      th.setAttribute('title', 'Rendit sipas kësaj kolone');
      var activate = function () {
        var asc = !th.classList.contains('is-asc');
        Array.prototype.forEach.call(head.rows[0].cells, function (other) {
          other.classList.remove('is-asc', 'is-desc');
          if (other.hasAttribute('aria-sort')) other.setAttribute('aria-sort', 'none');
        });
        th.classList.add(asc ? 'is-asc' : 'is-desc');
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        sortTable(table, index, kind, asc ? 1 : -1);
      };
      th.addEventListener('click', activate);
      th.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); activate(); }
      });
    });
  });

  /* ------------------------------------ 6. Njoftimet dhe konfirmimi */
  var toastTitles = { success: 'U krye', danger: 'Nuk u krye', warning: 'Kujdes', info: 'Për dijeni', primary: 'Për dijeni' };
  var toastIcons = { success: 'bi-check-circle-fill', danger: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill', primary: 'bi-info-circle-fill' };

  window.qtaToast = function (message, variant, title, options) {
    variant = variant || 'info';
    options = options || {};
    var area = document.querySelector('[data-toast-area]');
    if (!area) {
      area = document.createElement('div');
      area.className = 'toast-container position-fixed bottom-0 end-0 p-3';
      area.setAttribute('data-toast-area', '');
      document.body.appendChild(area);
    }
    var el = document.createElement('div');
    el.className = 'toast toast-' + variant;
    el.setAttribute('role', variant === 'danger' ? 'alert' : 'status');
    el.setAttribute('aria-live', variant === 'danger' ? 'assertive' : 'polite');
    el.setAttribute('aria-atomic', 'true');
    el.innerHTML =
      '<div class="toast-header"><i class="bi ' + (toastIcons[variant] || toastIcons.info) + ' me-2" aria-hidden="true"></i>' +
      '<strong class="me-auto"></strong>' +
      '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll njoftimin"></button></div>' +
      '<div class="toast-body"></div>';
    el.querySelector('strong').textContent = title || toastTitles[variant] || toastTitles.info;
    el.querySelector('.toast-body').textContent = message;
    area.appendChild(el);
    if (window.bootstrap) {
      var toast = new window.bootstrap.Toast(el, { autohide: options.autohide !== false, delay: options.delay || 4200 });
      toast.show();
      el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    }
    return el;
  };

  /* Pajtueshmëri: faqet e vjetra thërrasin notify(type, message, opts). */
  if (typeof window.notify !== 'function') {
    window.notify = function (type, message, opts) {
      return window.qtaToast(message, type, opts && opts.title, opts);
    };
  }

  /* Dialog konfirmimi i aksesueshëm — zëvendëson confirm() të shfletuesit.
     qtaConfirm({title, message, confirm, cancel, danger}) → Promise<boolean> */
  window.qtaConfirm = function (opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!window.bootstrap || !window.bootstrap.Modal) {
        resolve(window.confirm(opts.message || opts.title || 'Je i sigurt?'));
        return;
      }
      var id = 'qtaConfirm' + Date.now();
      var danger = opts.danger !== false;
      /* Pas përgjigjes, fokusi kthehet te kontrolli që e hapi pyetjen. */
      var returnTo = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
      var wrap = document.createElement('div');
      wrap.innerHTML =
        '<div class="modal fade" id="' + id + '" tabindex="-1" aria-labelledby="' + id + 'T" aria-describedby="' + id + 'D">' +
          '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
            '<div class="modal-body pt-4">' +
              '<span class="confirm-icon' + (danger ? ' is-danger' : '') + '"><i class="bi ' + (danger ? 'bi-exclamation-octagon' : 'bi-question-circle') + '" aria-hidden="true"></i></span>' +
              '<h2 class="modal-title mb-2" id="' + id + 'T">' + esc(opts.title || 'Je i sigurt?') + '</h2>' +
              '<p class="text-muted mb-0" id="' + id + 'D">' + esc(opts.message || '') + '</p>' +
            '</div>' +
            '<div class="modal-footer">' +
              '<button type="button" class="btn btn-secondary" data-qta-cancel>' + esc(opts.cancel || 'Anulo') + '</button>' +
              '<button type="button" class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" data-qta-ok>' + esc(opts.confirm || 'Po, vazhdo') + '</button>' +
            '</div>' +
          '</div></div>' +
        '</div>';
      var modalEl = wrap.firstChild;
      document.body.appendChild(modalEl);
      var modal = new window.bootstrap.Modal(modalEl);
      var answered = false;
      modalEl.querySelector('[data-qta-ok]').addEventListener('click', function () { answered = true; modal.hide(); resolve(true); });
      modalEl.querySelector('[data-qta-cancel]').addEventListener('click', function () { modal.hide(); });
      modalEl.addEventListener('shown.bs.modal', function () { modalEl.querySelector('[data-qta-cancel]').focus(); });
      modalEl.addEventListener('hidden.bs.modal', function () {
        if (!answered) resolve(false);
        modal.dispose();
        modalEl.remove();
        if (returnTo && document.contains(returnTo) && typeof returnTo.focus === 'function') {
          try { returnTo.focus({ preventScroll: true }); } catch (e) { /* kontrolli s'merr dot fokus */ }
        }
      });
      modal.show();
    });
  };

  /* Formularët/lidhjet me data-confirm pyesin para se të vazhdojnë. */
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.hasAttribute || !form.hasAttribute('data-confirm') || form.dataset.confirmed === '1') return;
    event.preventDefault();
    var submitter = event.submitter || null;
    window.qtaConfirm({
      title: form.getAttribute('data-confirm-title') || 'Je i sigurt?',
      message: form.getAttribute('data-confirm'),
      confirm: form.getAttribute('data-confirm-ok') || 'Po, vazhdo',
      danger: form.getAttribute('data-confirm-danger') !== '0'
    }).then(function (ok) {
      if (!ok) return;
      form.dataset.confirmed = '1';
      if (form.requestSubmit) form.requestSubmit(submitter || undefined); else form.submit();
    });
  }, true);

  document.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a[data-confirm]') : null;
    if (!link) return;
    event.preventDefault();
    window.qtaConfirm({
      title: link.getAttribute('data-confirm-title') || 'Je i sigurt?',
      message: link.getAttribute('data-confirm'),
      confirm: link.getAttribute('data-confirm-ok') || 'Po, vazhdo',
      danger: link.getAttribute('data-confirm-danger') !== '0'
    }).then(function (ok) { if (ok) window.location.href = link.href; });
  });

  /* -------------------------------------- 7. Kopjo, fjalëkalimi, "po punon" */
  document.addEventListener('click', function (event) {
    var copyBtn = event.target.closest ? event.target.closest('[data-copy]') : null;
    if (copyBtn) {
      var text = copyBtn.getAttribute('data-copy') || '';
      var done = function () { window.qtaToast(copyBtn.getAttribute('data-copy-message') || 'U kopjua.', 'success'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { window.qtaToast('Shfletuesi nuk lejoi kopjimin.', 'warning'); });
      }
      return;
    }
    var pw = event.target.closest ? event.target.closest('[data-password-toggle]') : null;
    if (pw) {
      var input = document.querySelector(pw.getAttribute('data-password-toggle'));
      if (!input) return;
      var show = input.getAttribute('type') === 'password';
      input.setAttribute('type', show ? 'text' : 'password');
      pw.setAttribute('aria-pressed', show ? 'true' : 'false');
      pw.setAttribute('aria-label', show ? 'Fshih fjalëkalimin' : 'Shfaq fjalëkalimin');
      var icon = pw.querySelector('i');
      if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    }
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches || !form.matches('form[data-loading]') || event.defaultPrevented) return;
    var btn = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
    if (btn) { btn.classList.add('is-loading'); btn.setAttribute('aria-busy', 'true'); }
  });

  /* Kodet QR: <div data-qr="URL" data-qr-size="200">. Libraria (qrcodejs)
     ngarkohet vetëm nga faqet që e kanë nevojë, prandaj presim DOMContentLoaded. */
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.QRCode) return;
    document.querySelectorAll('[data-qr]').forEach(function (el) {
      var size = parseInt(el.getAttribute('data-qr-size') || '200', 10);
      el.innerHTML = '';
      new window.QRCode(el, {
        text: el.getAttribute('data-qr'),
        width: size,
        height: size,
        colorDark: '#1f1e1b',
        colorLight: '#ffffff',
        correctLevel: window.QRCode.CorrectLevel.M
      });
      el.removeAttribute('title');
      var img = el.querySelector('img');
      if (img) img.setAttribute('alt', el.getAttribute('data-qr-alt') || 'Kodi QR i verifikimit');
    });
  });

  /* Dialog që hapet vetë kur faqja vjen nga një lidhje, p.sh. "Krijo grup" →
     groups.php?create=1. <div class="modal" data-open-on-load="create">.
     Parametri hiqet nga adresa, që rifreskimi të mos e rihapë dialogun. */
  document.addEventListener('DOMContentLoaded', function () {
    var modal = document.querySelector('.modal[data-open-on-load]');
    if (!modal || !window.bootstrap) return;
    window.bootstrap.Modal.getOrCreateInstance(modal).show();
    var param = modal.getAttribute('data-open-on-load');
    if (param && window.history && window.history.replaceState) {
      try {
        var url = new URL(window.location.href);
        url.searchParams.delete(param);
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
      } catch (e) { /* adresa mbetet siç është */ }
    }
  });

  /* ------------------------------------------ 8. Kërkimi në regjistër */
  /* Hapet me Ctrl+K, "/" ose butonin "Kërko…". Serveri vendos kufijtë e rolit;
     ndërfaqja vetëm paraqet aftësitë e lejuara. Fushat e kërkimit brenda
     faqeve mbeten fusha të zakonshme — nuk rrëmbehen më nga paleta. */
  (function () {
    var veil, input, body, types, summary, filtersPanel, filterToggle;
    var sortSelect, statusSelect, periodSelect, matchSelect, limitSelect;
    var timer = null, seq = 0, controller = null, lastFocus = null;
    var active = -1, flat = [], only = '';
    var sort = 'relevance', status = 'any', period = 'any', matchMode = 'contains', limit = '6';
    var available = [];

    function mark(text, needle) {
      var raw = String(text == null ? '' : text);
      var at = raw.toLocaleLowerCase().indexOf(String(needle || '').toLocaleLowerCase());
      if (at < 0 || !needle) return esc(raw);
      return esc(raw.slice(0, at)) + '<mark>' + esc(raw.slice(at, at + needle.length)) + '</mark>' + esc(raw.slice(at + needle.length));
    }

    function build() {
      veil = document.createElement('div');
      veil.className = 'pal-veil';
      veil.innerHTML =
        '<div class="pal" role="dialog" aria-modal="true" aria-labelledby="palTitle">' +
          '<h2 class="visually-hidden" id="palTitle">Kërko në regjistër</h2>' +
          '<div class="pal-head">' +
            '<i class="bi bi-search" aria-hidden="true"></i>' +
            '<input type="text" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="true" aria-controls="palResults" ' +
                   'aria-autocomplete="list" placeholder="Shkruaj emër, AMZË, telefon, grup ose modul…" aria-label="Kërko në regjistër">' +
            '<button class="pal-close" type="button" aria-label="Mbyll kërkimin">Esc</button>' +
          '</div>' +
          '<div class="pal-types" role="group" aria-label="Kërko vetëm te">' +
            '<button class="pal-type is-on" type="button" data-type="" aria-pressed="true">Të gjitha</button>' +
            '<button class="pal-type" type="button" data-type="student" aria-pressed="false">Kursantë</button>' +
            '<button class="pal-type" type="button" data-type="group" aria-pressed="false">Grupe</button>' +
            '<button class="pal-type" type="button" data-type="course" aria-pressed="false">Module</button>' +
            '<button class="pal-type" type="button" data-type="agency" aria-pressed="false">Agjenci</button>' +
            '<button class="pal-type" type="button" data-type="user" aria-pressed="false">Llogari</button>' +
            '<button class="pal-type" type="button" data-type="audit" aria-pressed="false">Historik</button>' +
          '</div>' +
          '<div class="pal-tools">' +
            '<span class="pal-summary" data-pal-summary aria-live="polite">Gati për kërkim</span>' +
            '<label class="pal-sort"><span>Rendit</span>' +
              '<select data-pal-sort aria-label="Rendit rezultatet">' +
                '<option value="relevance">Më të përafërtat</option>' +
                '<option value="az">A → Z</option><option value="za">Z → A</option>' +
                '<option value="newest">Më të rejat</option><option value="oldest">Më të vjetrat</option>' +
              '</select>' +
            '</label>' +
            '<button class="pal-filter-toggle" type="button" data-pal-filter-toggle aria-expanded="false" aria-controls="palFilters">' +
              '<i class="bi bi-sliders2" aria-hidden="true"></i> Më shumë opsione <span class="pal-filter-count">0</span>' +
            '</button>' +
          '</div>' +
          '<div class="pal-filters" id="palFilters" data-pal-filters hidden>' +
            '<label><span>Gjendja</span><select data-pal-status>' +
              '<option value="any">Çdo gjendje</option><option value="active">Aktive / në vazhdim</option>' +
              '<option value="closed">Të mbyllura</option><option value="ungrouped">Pa grup</option>' +
            '</select></label>' +
            '<label><span>Periudha</span><select data-pal-period>' +
              '<option value="any">Çdo periudhë</option><option value="30">30 ditët e fundit</option>' +
              '<option value="90">90 ditët e fundit</option><option value="365">12 muajt e fundit</option>' +
            '</select></label>' +
            '<label><span>Si të krahasoj</span><select data-pal-match>' +
              '<option value="contains">Përmban fjalën</option><option value="prefix">Fillon me</option>' +
              '<option value="exact">Saktësisht e njëjtë</option>' +
            '</select></label>' +
            '<label><span>Sa rezultate</span><select data-pal-limit>' +
              '<option value="6">6 për lloj</option><option value="10">10 për lloj</option><option value="15">15 për lloj</option>' +
            '</select></label>' +
            '<button class="pal-reset" type="button" data-pal-reset><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Rikthe opsionet</button>' +
          '</div>' +
          '<div class="pal-body" id="palResults" role="listbox" aria-label="Rezultatet"></div>' +
          '<div class="pal-foot" aria-hidden="true">' +
            '<span><kbd>↑</kbd><kbd>↓</kbd> lëviz</span>' +
            '<span><kbd>Enter</kbd> hap</span>' +
            '<span><kbd>Tab</kbd> ndrysho llojin</span>' +
            '<span><kbd>Esc</kbd> mbyll</span>' +
          '</div>' +
        '</div>';
      document.body.appendChild(veil);

      input = veil.querySelector('.pal-head input');
      body = veil.querySelector('.pal-body');
      types = veil.querySelectorAll('.pal-type');
      summary = veil.querySelector('[data-pal-summary]');
      filtersPanel = veil.querySelector('[data-pal-filters]');
      filterToggle = veil.querySelector('[data-pal-filter-toggle]');
      sortSelect = veil.querySelector('[data-pal-sort]');
      statusSelect = veil.querySelector('[data-pal-status]');
      periodSelect = veil.querySelector('[data-pal-period]');
      matchSelect = veil.querySelector('[data-pal-match]');
      limitSelect = veil.querySelector('[data-pal-limit]');

      veil.addEventListener('mousedown', function (event) { if (event.target === veil) close(); });
      veil.querySelector('.pal-close').addEventListener('click', close);
      input.addEventListener('input', function () { schedule(); });

      Array.prototype.forEach.call(types, function (button) {
        button.addEventListener('click', function () {
          setType(button.getAttribute('data-type') || '');
          input.focus();
          schedule(0);
        });
      });

      sortSelect.addEventListener('change', function () { sort = sortSelect.value; schedule(0); });
      statusSelect.addEventListener('change', function () { status = statusSelect.value; filtersChanged(); });
      periodSelect.addEventListener('change', function () { period = periodSelect.value; filtersChanged(); });
      matchSelect.addEventListener('change', function () { matchMode = matchSelect.value; filtersChanged(); });
      limitSelect.addEventListener('change', function () { limit = limitSelect.value; filtersChanged(); });

      filterToggle.addEventListener('click', function () {
        var expanded = filterToggle.getAttribute('aria-expanded') === 'true';
        filterToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        filtersPanel.hidden = expanded;
        if (!expanded) statusSelect.focus();
      });
      veil.querySelector('[data-pal-reset]').addEventListener('click', function () {
        status = 'any'; period = 'any'; matchMode = 'contains'; limit = '6'; sort = 'relevance';
        syncControls(); updateFilterCount(); input.focus(); schedule(0);
      });

      body.addEventListener('mousemove', function (event) {
        var item = event.target.closest ? event.target.closest('.pal-item') : null;
        if (item) { active = flat.indexOf(item); paint(); }
      });
      body.addEventListener('click', function (event) {
        var item = event.target.closest ? event.target.closest('.pal-item') : null;
        if (item) remember(input.value.trim());
      });
    }

    function syncControls() {
      sortSelect.value = sort;
      statusSelect.value = status;
      periodSelect.value = period;
      matchSelect.value = matchMode;
      limitSelect.value = limit;
    }

    function filtersChanged() { updateFilterCount(); schedule(0); }

    function updateFilterCount() {
      var count = 0;
      if (status !== 'any') count++;
      if (period !== 'any') count++;
      if (matchMode !== 'contains') count++;
      if (limit !== '6') count++;
      filterToggle.querySelector('.pal-filter-count').textContent = String(count);
      filterToggle.classList.toggle('has-filters', count > 0);
    }

    function contextType() {
      var page = (window.location.pathname.split('/').pop() || '').toLowerCase();
      if (/^groups/.test(page)) return 'group';
      if (page === 'courses.php') return 'course';
      if (page === 'agencies.php') return 'agency';
      if (page === 'users.php' || page === 'editors.php') return 'user';
      if (/^logs/.test(page)) return 'audit';
      return '';
    }

    function typesFromPage() {
      var owner = document.querySelector('[data-search-types]');
      var raw = owner ? owner.getAttribute('data-search-types') : '';
      return raw ? raw.split(',').filter(Boolean) : [];
    }

    function applyCapabilities(list) {
      if (list && list.length) available = list.slice();
      Array.prototype.forEach.call(types, function (button) {
        var value = button.getAttribute('data-type') || '';
        button.hidden = !(value === '' || !available.length || available.indexOf(value) >= 0);
      });
      if (only && available.length && available.indexOf(only) < 0) setType('');
    }

    function setType(value) {
      only = value || '';
      Array.prototype.forEach.call(types, function (button) {
        var selected = (button.getAttribute('data-type') || '') === only;
        button.classList.toggle('is-on', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
    }

    function open(seed) {
      if (!veil) build();
      lastFocus = document.activeElement;
      available = typesFromPage();
      applyCapabilities(available);
      var ctx = contextType();
      setType(available.indexOf(ctx) >= 0 ? ctx : '');
      sort = 'relevance'; status = 'any'; period = 'any'; matchMode = 'contains'; limit = '6';
      syncControls(); updateFilterCount();
      filtersPanel.hidden = true;
      filterToggle.setAttribute('aria-expanded', 'false');
      input.value = seed || '';
      flat = []; active = -1;
      veil.classList.add('is-open');
      document.documentElement.style.overflow = 'hidden';
      renderStart();
      input.focus();
      input.select();
      if (input.value.trim().length >= 2) schedule(0);
    }

    function close() {
      if (!veil || !veil.classList.contains('is-open')) return;
      clearTimeout(timer);
      if (controller) controller.abort();
      veil.classList.remove('is-open');
      document.documentElement.style.overflow = '';
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function schedule(delay) {
      clearTimeout(timer);
      timer = setTimeout(run, delay === 0 ? 0 : 200);
    }

    function recent() {
      try { return JSON.parse(localStorage.getItem('qta_recent_searches') || '[]').slice(0, 6); }
      catch (error) { return []; }
    }

    function remember(term) {
      if (!term || term.length < 2) return;
      var list = recent().filter(function (item) { return item.toLocaleLowerCase() !== term.toLocaleLowerCase(); });
      list.unshift(term);
      store('qta_recent_searches', JSON.stringify(list.slice(0, 6)));
    }

    function renderStart() {
      var items = recent();
      summary.textContent = 'Gati për kërkim';
      input.removeAttribute('aria-activedescendant');
      if (!items.length) {
        body.innerHTML = '<div class="pal-welcome"><i class="bi bi-search" aria-hidden="true"></i>' +
          '<b>Kërko në të gjithë regjistrin</b><span>Shkruaj të paktën dy shkronja: emër, atësi, numër amze, telefon, kod moduli ose numër grupi.</span></div>';
        return;
      }
      body.innerHTML = '<div class="pal-recent"><span class="pal-recent-label">Kërkimet e fundit</span>' +
        items.map(function (item) {
          return '<button type="button" data-pal-recent="' + esc(item) + '"><i class="bi bi-clock-history" aria-hidden="true"></i>' + esc(item) + '</button>';
        }).join('') + '</div>';
      body.querySelectorAll('[data-pal-recent]').forEach(function (button) {
        button.addEventListener('click', function () {
          input.value = button.getAttribute('data-pal-recent') || '';
          input.focus();
          schedule(0);
        });
      });
    }

    function renderLoading() {
      body.setAttribute('aria-busy', 'true');
      body.innerHTML = '<div class="pal-loading" aria-hidden="true"><span></span><span></span><span></span></div>';
      summary.textContent = 'Po kërkoj…';
    }

    function run() {
      var q = input.value.trim();
      if (q.length < 2) { flat = []; active = -1; renderStart(); return; }

      var mine = ++seq;
      if (controller) controller.abort();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      renderLoading();

      var params = new URLSearchParams({ q: q, sort: sort, status: status, period: period, match: matchMode, limit: limit });
      if (only) params.set('type', only);

      fetch('app/actions/search_advanced.php?' + params.toString(), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
      })
        .then(function (response) {
          return response.json().then(function (json) {
            if (!response.ok && !json.error) json.error = 'Kërkimi nuk u krye.';
            return json;
          });
        })
        .then(function (json) {
          if (mine !== seq) return;
          body.removeAttribute('aria-busy');
          if (!json.ok) {
            body.innerHTML = '<div class="pal-message is-error"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><b>' +
              esc(json.error || 'Kërkimi nuk u krye.') + '</b><span>Provo sërish pas pak ose rifresko faqen.</span></div>';
            summary.textContent = 'Kërkimi nuk u krye'; flat = []; active = -1; return;
          }
          applyCapabilities(json.capabilities || []);
          updateTypeCounts(json.counts || {});
          if (!json.total) {
            body.innerHTML = '<div class="pal-message"><i class="bi bi-search" aria-hidden="true"></i><b>Nuk u gjet asgjë</b>' +
              '<span>Asnjë përputhje për “' + esc(q) + '”. Kontrollo drejtshkrimin ose provo vetëm një pjesë të emrit.</span></div>';
            summary.textContent = 'Asnjë rezultat';
            flat = []; active = -1; return;
          }
          var html = '', n = 0;
          (json.groups || []).forEach(function (group) {
            html += '<div class="pal-group-label" role="presentation"><span>' + esc(group.label) + '</span><b>' + group.items.length + '</b></div>';
            group.items.forEach(function (item) {
              var state = /^(active|closed|ungrouped|neutral|insert|update|delete)$/.test(item.status || '') ? item.status : 'neutral';
              html += '<a class="pal-item" id="palItem' + (n++) + '" role="option" aria-selected="false" href="' + esc(item.href) + '">' +
                        '<span class="pal-item-icon"><i class="bi ' + esc(item.icon || 'bi-dot') + '" aria-hidden="true"></i></span>' +
                        '<span class="pal-item-main">' +
                          '<span class="pal-item-title">' + mark(item.title, q) + '</span>' +
                          (item.meta ? '<span class="pal-item-meta">' + esc(item.meta) + '</span>' : '') +
                        '</span>' +
                        '<span class="pal-item-side">' +
                          (item.status_label ? '<span class="pal-status is-' + state + '">' + esc(item.status_label) + '</span>' : '') +
                          (item.code ? '<span class="pal-item-code">' + mark(item.code, q) + '</span>' : '') +
                        '</span>' +
                        '<i class="bi bi-arrow-right pal-item-open" aria-hidden="true"></i>' +
                      '</a>';
            });
          });
          body.innerHTML = html;
          flat = Array.prototype.slice.call(body.querySelectorAll('.pal-item'));
          active = flat.length ? 0 : -1;
          summary.textContent = json.total + (json.total === 1 ? ' rezultat' : ' rezultate');
          paint();
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') return;
          if (mine !== seq) return;
          body.removeAttribute('aria-busy');
          body.innerHTML = '<div class="pal-message is-error"><i class="bi bi-wifi-off" aria-hidden="true"></i><b>Nuk u lidh me serverin</b><span>Kontrollo internetin dhe provo sërish.</span></div>';
          summary.textContent = 'Pa lidhje'; flat = []; active = -1;
        });
    }

    function updateTypeCounts(counts) {
      Array.prototype.forEach.call(types, function (button) {
        var old = button.querySelector('.pal-type-count');
        if (old) old.remove();
        var value = button.getAttribute('data-type') || '';
        if (value && Object.prototype.hasOwnProperty.call(counts, value)) {
          var badge = document.createElement('span');
          badge.className = 'pal-type-count';
          badge.textContent = counts[value];
          button.appendChild(badge);
        }
      });
    }

    function paint() {
      flat.forEach(function (element, index) {
        var selected = index === active;
        element.classList.toggle('is-active', selected);
        element.setAttribute('aria-selected', selected ? 'true' : 'false');
      });
      if (active >= 0 && flat[active]) {
        input.setAttribute('aria-activedescendant', flat[active].id);
        flat[active].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function step(delta) {
      if (!flat.length) return;
      active = (active + delta + flat.length) % flat.length;
      paint();
    }

    function cycleType(delta) {
      var list = Array.prototype.slice.call(types).filter(function (button) { return !button.hidden; });
      if (!list.length) return;
      var index = 0;
      list.forEach(function (button, at) { if (button.classList.contains('is-on')) index = at; });
      list[(index + delta + list.length) % list.length].click();
    }

    document.addEventListener('keydown', function (event) {
      var tag = (event.target.tagName || '').toLowerCase();
      var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable;
      var allowed = !!document.querySelector('[data-open-palette]');

      if (allowed && (event.ctrlKey || event.metaKey) && (event.key === 'k' || event.key === 'K')) {
        event.preventDefault();
        open(typing && event.target.value && !(veil && veil.contains(event.target)) ? event.target.value : '');
        return;
      }
      if (allowed && event.key === '/' && !typing) { event.preventDefault(); open(''); return; }
      if (!veil || !veil.classList.contains('is-open')) return;

      if (event.key === 'Escape') { event.preventDefault(); close(); }
      else if (event.key === 'ArrowDown') { event.preventDefault(); step(1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); step(-1); }
      else if (event.key === 'Tab' && event.target === input) { event.preventDefault(); cycleType(event.shiftKey ? -1 : 1); }
      else if (event.key === 'Enter' && event.target === input && active >= 0 && flat[active]) {
        event.preventDefault();
        remember(input.value.trim());
        window.location.href = flat[active].getAttribute('href');
      }
    }, true);

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest ? event.target.closest('[data-open-palette]') : null;
      if (trigger) { event.preventDefault(); closeDrawer(false); open(''); }
    });
  })();
})();
