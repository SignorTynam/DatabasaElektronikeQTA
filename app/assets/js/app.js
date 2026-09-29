/* ============================================================================
   THEMELI — sjellja e panelit (faqet pas hyrjes).
   Vetëm shtresa e ndërfaqes: asnjë endpoint, leje apo rregull biznesi nuk
   ndryshon këtu.
     1. Pamja (e çelët · sipas pajisjes · e errët)
     2. Menuja anësore dhe sirtari në celular
     3. Kthimi në krye, ankorat, këshillat (data-tip), kyçi i ndryshimeve
     4. Listat e gjalla: kërkimi dhe filtrat pa ringarkim (form[data-live-filter])
     5. Renditja e kolonave, përmbajtja e re (qta:content), redaktimi në vend (qtaEditable)
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

  /* ------------------------------------------------ Këshillat (tooltip) */
  /* data-tip="…" te butonat me ikonë: këshilla del me mi ose me tastierë.
     Krijohet kur duhet (me delegim), që të punojë edhe për rreshtat e rinj
     të listave. Emri i aksesueshëm mbetet gjithnjë te aria-label. */
  var hoverless = window.matchMedia ? window.matchMedia('(hover: none)') : null;
  function tipInstance(el) {
    if (!window.bootstrap || !window.bootstrap.Tooltip) return null;
    return window.bootstrap.Tooltip.getOrCreateInstance(el, {
      title: el.getAttribute('data-tip'),
      placement: el.getAttribute('data-tip-placement') || 'bottom',
      trigger: 'hover focus',
      container: 'body'
    });
  }
  ['mouseover', 'focusin'].forEach(function (type) {
    document.addEventListener(type, function (event) {
      var el = event.target.closest ? event.target.closest('[data-tip]') : null;
      if (!el || el._qtaTip) return;
      if (type === 'mouseover' && hoverless && hoverless.matches) return;
      if (type === 'focusin' && el.matches && !el.matches(':focus-visible')) return;
      el._qtaTip = tipInstance(el);
      if (el._qtaTip) el._qtaTip.show();
    });
  });
  function dropTips(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('[data-tip]'), function (el) {
      if (el._qtaTip) { try { el._qtaTip.dispose(); } catch (e) { /* tashmë e hequr */ } el._qtaTip = null; }
    });
  }
  if (window.bootstrap && window.bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new window.bootstrap.Tooltip(el);
    });
  }

  /* Kyçi i ndryshimeve ruan filtrat e listës që janë në adresë tani
     (lista mund të jetë filtruar pa ringarkuar faqen). */
  document.addEventListener('click', function (event) {
    var a = event.target.closest ? event.target.closest('a[data-edit-toggle]') : null;
    if (!a || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
    try {
      var url = new URL(window.location.href);
      url.searchParams.delete('add');
      url.searchParams.delete('create');
      url.searchParams.set('edit', a.getAttribute('data-edit-toggle'));
      event.preventDefault();
      window.location.href = url.pathname + url.search + url.hash;
    } catch (e) { /* lidhja e zakonshme */ }
  });

  /* -------------------------------------------------- 4. Listat e gjalla */
  /* Një formular form[data-live-filter] për listë (partials/list_toolbar.php).
     Ndërsa shkruan, faqja kërkon po atë adresë me filtrat e rinj dhe zëvendëson
     vetëm zonat [data-live-region]: kërkimi mbulon gjithë listën (serveri
     filtron), jo vetëm rreshtat që shihen. Kërkesa e vjetër ndërpritet,
     përgjigjet e vonuara injorohen, rezultatet e vjetra mbeten gjatë ngarkimit.
     Adresa ndjek filtrat: rifreskimi dhe "prapa/përpara" japin të njëjtën listë.
     Pa JavaScript formulari dërgohet si GET i zakonshëm. */
  var reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

  window.qtaLive = (function () {
    var form = document.querySelector('form[data-live-filter]');
    if (!form || !window.fetch || !window.DOMParser || !window.URLSearchParams || !window.FormData) return null;

    var input = form.querySelector('[data-lf-input]');
    var clearBtn = form.querySelector('[data-lf-clear]');
    var announceEl = form.querySelector('[data-lf-announce]');
    var errorEl = form.querySelector('[data-lf-error]');
    var more = form.querySelector('[data-lf-more]');
    var moreCount = form.querySelector('[data-lf-more-count]');
    var pagePath = new URL(form.getAttribute('action') || window.location.pathname, window.location.href).pathname;
    var timer = null, staleTimer = null, seq = 0, controller = null;

    function regions() { return Array.prototype.slice.call(document.querySelectorAll('[data-live-region]')); }

    function params(extra) {
      var usp = new URLSearchParams();
      new FormData(form).forEach(function (value, key) {
        var v = String(value).replace(/\s+/g, ' ').trim();
        if (v !== '' && key !== 'page') usp.set(key, v);
      });
      Object.keys(extra || {}).forEach(function (key) {
        var v = extra[key];
        if (v === '' || v == null || (key === 'page' && parseInt(v, 10) <= 1)) usp.delete(key); else usp.set(key, String(v));
      });
      return usp;
    }

    function urlOf(usp) {
      var s = usp.toString();
      return pagePath + (s ? '?' + s : '');
    }

    function syncControls() {
      if (clearBtn && input) clearBtn.hidden = input.value === '';
      if (more && moreCount) {
        var n = 0;
        Array.prototype.forEach.call(more.querySelectorAll('input[name], select[name]'), function (f) {
          if (String(f.value).trim() !== '') n++;
        });
        moreCount.hidden = n === 0;
        if (moreCount.firstChild) moreCount.firstChild.nodeValue = String(n);
      }
    }

    function setBusy(on) {
      form.classList.toggle('is-busy', on);
      clearTimeout(staleTimer);
      regions().forEach(function (r) {
        if (on) r.setAttribute('aria-busy', 'true'); else { r.removeAttribute('aria-busy'); r.classList.remove('is-stale'); }
      });
      /* Zbehja vetëm kur përgjigjja vonon: pa dridhje te përgjigjet e shpejta. */
      if (on) staleTimer = setTimeout(function () {
        regions().forEach(function (r) { if (!form.contains(r)) r.classList.add('is-stale'); });
      }, 160);
    }

    function showError(on) {
      if (errorEl) errorEl.hidden = !on;
      if (on && announceEl) announceEl.textContent = 'Lista nuk u përditësua. Kontrollo lidhjen dhe provo sërish.';
    }

    function announce(text) {
      if (!announceEl || !text) return;
      announceEl.textContent = '';
      setTimeout(function () { announceEl.textContent = text; }, 80);
    }

    function swap(doc, opts) {
      var current = regions();
      var pairs = [];
      for (var i = 0; i < current.length; i++) {
        var name = current[i].getAttribute('data-live-region');
        var fresh = doc.querySelector('[data-live-region="' + name + '"]');
        if (!fresh) return false;
        pairs.push([current[i], fresh]);
      }
      if (!pairs.length) return false;

      var active = document.activeElement;
      var fromRegion = active && active !== document.body && active.closest ? active.closest('[data-live-region]') : null;
      var focusKey = fromRegion ? active.getAttribute('data-focus-key') : null;

      pairs.forEach(function (pair) {
        var el = pair[0], fresh = pair[1];
        dropTips(el);
        el.innerHTML = fresh.innerHTML;
        var said = fresh.getAttribute('data-live-announce');
        if (said !== null) el.setAttribute('data-live-announce', said);
        if (!form.contains(el) && !(reduceMotion && reduceMotion.matches)) {
          el.classList.remove('is-fresh');
          void el.offsetWidth;
          el.classList.add('is-fresh');
        }
        document.dispatchEvent(new CustomEvent('qta:content', { detail: { root: el } }));
      });

      /* Fokusi nuk humbet: kthehet te i njëjti çip, ose te titulli i listës. */
      var target = null;
      if (focusKey) {
        target = Array.prototype.find.call(document.querySelectorAll('[data-focus-key]'), function (el) {
          return el.getAttribute('data-focus-key') === focusKey;
        }) || null;
      }
      /* Pas një veprimi (refresh), fokusi që ra te faqja kthehet te titulli i listës. */
      var lost = !active || active === document.body || !document.contains(active);
      if ((fromRegion && !target) || (opts && opts.focusResults) || (opts && opts.refocus && lost)) {
        target = target || document.querySelector('[data-live-focus]');
      }
      if (target) {
        try { target.focus({ preventScroll: true }); } catch (e) { /* pa fokus */ }
        if (opts && opts.focusResults && target.getBoundingClientRect().top < 0) {
          target.scrollIntoView({ block: 'start', behavior: reduceMotion && reduceMotion.matches ? 'auto' : 'smooth' });
        }
      }

      var saidEl = document.querySelector('[data-live-announce]');
      if (saidEl) announce(saidEl.getAttribute('data-live-announce'));
      syncControls();
      return true;
    }

    function load(url, opts) {
      opts = opts || {};
      var mine = ++seq;
      if (controller) controller.abort();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      setBusy(true);
      fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html', 'X-QTA-Live': '1' },
        signal: controller ? controller.signal : undefined
      })
        .then(function (res) {
          /* Seanca ka mbaruar ose faqja çoi gjetiu: hap faqen e plotë. */
          var got = new URL(res.url || url, window.location.href);
          if (got.pathname !== pagePath) { window.location.href = got.href; return null; }
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.text();
        })
        .then(function (html) {
          if (html == null || mine !== seq) return;
          var doc = new DOMParser().parseFromString(html, 'text/html');
          if (!swap(doc, opts)) { window.location.href = url; return; }
          setBusy(false);
          showError(false);
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          if (mine !== seq) return;
          setBusy(false);
          showError(true);
        });
    }

    function go(opts) {
      opts = opts || {};
      clearTimeout(timer);
      var url = urlOf(params(opts.page ? { page: opts.page } : null));
      if (url !== window.location.pathname + window.location.search) {
        try { window.history[opts.push ? 'pushState' : 'replaceState']({ qtaLive: true }, '', url + window.location.hash); } catch (e) { /* adresa mbetet */ }
      }
      load(url, opts);
    }

    /* Fushat e formularit sipas adresës (pas "prapa/përpara"). */
    function fromUrl() {
      var usp = new URLSearchParams(window.location.search);
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.type === 'submit' || el.type === 'button') return;
        var v = usp.get(el.name) || '';
        if (el.hasAttribute('data-dmy') && /^\d{4}-\d{2}-\d{2}$/.test(v)) v = v.slice(8, 10) + '.' + v.slice(5, 7) + '.' + v.slice(0, 4);
        el.value = v;
      });
      syncControls();
    }

    if (input) {
      input.addEventListener('input', function () {
        syncControls();
        clearTimeout(timer);
        timer = setTimeout(function () { go({ push: false }); }, 220);
      });
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && input.value !== '') {
          event.preventDefault();
          event.stopPropagation();
          input.value = '';
          go({ push: true });
        }
      });
    }
    if (clearBtn && input) clearBtn.addEventListener('click', function () {
      input.value = '';
      input.focus();
      go({ push: true });
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      go({ push: true });
    });

    form.addEventListener('click', function (event) {
      var t = event.target;
      if (!t.closest) return;
      var chip = t.closest('[data-lf-chip]');
      if (chip) {
        event.preventDefault();
        var field = form.querySelector('[data-lf-status-field]');
        if (field) field.value = chip.getAttribute('data-lf-chip');
        /* Gjendja e re shihet menjëherë; numrat vijnë nga serveri. */
        Array.prototype.forEach.call(form.querySelectorAll('[data-lf-chip]'), function (c) {
          var on = c === chip;
          c.classList.toggle('is-on', on);
          c.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        go({ push: true });
        return;
      }
      var remove = t.closest('[data-lf-remove]');
      if (remove) {
        event.preventDefault();
        var f = form.elements.namedItem(remove.getAttribute('data-lf-remove'));
        if (f) f.value = '';
        go({ push: true });
        return;
      }
      var preset = t.closest('[data-lf-preset]');
      if (preset) {
        event.preventDefault();
        var set = {};
        try { set = JSON.parse(preset.getAttribute('data-lf-preset') || '{}'); } catch (e) { set = {}; }
        Object.keys(set).forEach(function (name) { var el = form.elements.namedItem(name); if (el) el.value = set[name]; });
        go({ push: true });
        return;
      }
      if (t.closest('[data-lf-more-reset]')) {
        event.preventDefault();
        if (more) Array.prototype.forEach.call(more.querySelectorAll('input[name], select[name]'), function (el) { el.value = ''; });
        go({ push: true });
        return;
      }
      if (t.closest('[data-lf-retry]')) {
        event.preventDefault();
        load(window.location.pathname + window.location.search, {});
      }
    });

    /* Filtrat e rrallë vlejnë sapo ndryshojnë (edhe data e zgjedhur në kalendar). */
    form.addEventListener('change', function (event) {
      if (event.target === input || !event.target.name) return;
      if (more && more.contains(event.target)) go({ push: true });
    });

    /* Faqosja brenda listës. */
    document.addEventListener('click', function (event) {
      var a = event.target.closest ? event.target.closest('a[data-live-page]') : null;
      if (!a || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
      event.preventDefault();
      go({ push: true, page: a.getAttribute('data-live-page'), focusResults: true });
    });

    /* "Filtra" mbyllet me Esc ose me klik jashtë (jo kur zgjidhet data në kalendar). */
    if (more) {
      document.addEventListener('click', function (event) {
        if (!more.open || more.contains(event.target)) return;
        if (event.target.closest && event.target.closest('.modal')) return;
        more.open = false;
      });
      more.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !more.open) return;
        event.stopPropagation();
        more.open = false;
        var summary = more.querySelector('summary');
        if (summary) summary.focus();
      });
    }

    window.addEventListener('popstate', function () {
      if (window.location.pathname !== pagePath) return;
      fromUrl();
      load(window.location.pathname + window.location.search, {});
    });

    syncControls();

    return {
      /* Rifresko listën me filtrat e tanishëm (p.sh. pas caktimit në grup). */
      refresh: function () { load(window.location.pathname + window.location.search, { refocus: true }); },
      form: form
    };
  })();

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

  /* Tabelat e renditshme; thirret sërish për tabelat që vijnë nga lista e gjallë. */
  function enhanceSortable(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('table[data-sortable]:not([data-sort-ready])'), function (table) {
      var head = table.tHead;
      if (!head) return;
      table.setAttribute('data-sort-ready', '');
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
  }
  enhanceSortable(document);

  /* Përmbajtje e re në faqe (lista e gjallë): renditja dhe kalendari punojnë edhe atje. */
  document.addEventListener('qta:content', function (event) {
    var root = event.detail && event.detail.root;
    if (!root) return;
    enhanceSortable(root);
    if (window.qtaDatePicker && window.qtaDatePicker.enhance) window.qtaDatePicker.enhance(root);
  });

  /* Redaktimi në vend me delegim: punon edhe te rreshtat që vijnë pas filtrimit.
     qtaEditable(selector, commit): Enter ruan, Esc kthen vlerën, ngjitja merr
     vetëm tekst; kur fusha lë fokusin, commit(el, vlera e mëparshme) e ruan. */
  window.qtaEditable = function (selector, commit) {
    function target(node) {
      var el = node && node.closest ? node.closest(selector) : null;
      return el && el.isContentEditable ? el : null;
    }
    document.addEventListener('focusin', function (event) {
      var el = target(event.target);
      if (el) el.dataset.prev = el.textContent;
    });
    document.addEventListener('keydown', function (event) {
      var el = target(event.target);
      if (!el) return;
      if (event.key === 'Enter') { event.preventDefault(); el.blur(); }
      else if (event.key === 'Escape') {
        /* Esc anulon vetëm redaktimin — nuk mbyll dialogun ku ndodhet qeliza. */
        event.preventDefault();
        event.stopPropagation();
        if (el.dataset.prev != null) el.textContent = el.dataset.prev;
        el.dataset.cancel = '1';
        el.blur();
      }
    });
    document.addEventListener('paste', function (event) {
      var el = target(event.target);
      if (!el) return;
      event.preventDefault();
      var text = (event.clipboardData || window.clipboardData).getData('text/plain') || '';
      document.execCommand('insertText', false, text.replace(/\s+/g, ' ').trim());
    });
    document.addEventListener('focusout', function (event) {
      var el = target(event.target);
      if (!el) return;
      if (el.dataset.cancel === '1') { delete el.dataset.cancel; return; }
      commit(el, el.dataset.prev != null ? el.dataset.prev : el.textContent);
    });
  };

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
    /* I njëjti mesazh që përsëritet (p.sh. disa ruajtje radhazi) nuk grumbullohet:
       njoftimi ekzistues rinis kohën dhe shfaq numrin e herëve. */
    var same = Array.prototype.find.call(area.querySelectorAll('.toast.show[data-toast-key]'), function (t) {
      return t.getAttribute('data-toast-key') === variant + '|' + message;
    });
    if (same && window.bootstrap) {
      var times = (parseInt(same.getAttribute('data-toast-times') || '1', 10) || 1) + 1;
      same.setAttribute('data-toast-times', String(times));
      same.querySelector('.toast-body').textContent = message + ' (' + times + ')';
      var inst = window.bootstrap.Toast.getOrCreateInstance(same);
      inst.show();
      return same;
    }
    var el = document.createElement('div');
    el.setAttribute('data-toast-key', variant + '|' + message);
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
     qtaConfirm({title, message, points, confirm, cancel, danger, icon}) → Promise<boolean>
     cancel: false = vetëm një buton (njoftim që kërkon vëmendje);
     icon: p.sh. 'bi-exclamation-triangle' për një problem që duhet rregulluar;
     points: listë e shkurtër fjalish (çfarë ndodh / çfarë mbetet), e treguar si listë. */
  window.qtaConfirm = function (opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!window.bootstrap || !window.bootstrap.Modal) {
        if (opts.cancel === false) { window.alert(opts.message || opts.title || ''); resolve(true); return; }
        resolve(window.confirm(opts.message || opts.title || 'Je i sigurt?'));
        return;
      }
      var id = 'qtaConfirm' + Date.now();
      var danger = opts.danger !== false;
      var single = opts.cancel === false;
      var icon = opts.icon || (danger ? 'bi-exclamation-octagon' : 'bi-question-circle');
      /* Pas përgjigjes, fokusi kthehet te kontrolli që e hapi pyetjen. */
      var returnTo = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
      /* Pyetja mund të dalë mbi një dialog tjetër që mbetet i hapur (p.sh. një grup). */
      var under = document.querySelector('.modal.show');
      var wrap = document.createElement('div');
      wrap.innerHTML =
        '<div class="modal fade" id="' + id + '" tabindex="-1" aria-labelledby="' + id + 'T" aria-describedby="' + id + 'D">' +
          '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
            '<div class="modal-body pt-4">' +
              '<span class="confirm-icon' + (danger ? ' is-danger' : '') + '"><i class="bi ' + esc(icon) + '" aria-hidden="true"></i></span>' +
              '<h2 class="modal-title mb-2" id="' + id + 'T">' + esc(opts.title || 'Je i sigurt?') + '</h2>' +
              '<div id="' + id + 'D"><p class="text-muted mb-0">' + esc(opts.message || '') + '</p>' +
              (opts.points && opts.points.length
                ? '<ul class="confirm-points">' + opts.points.map(function (p) {
                    return '<li><i class="bi bi-check2" aria-hidden="true"></i><span>' + esc(p) + '</span></li>';
                  }).join('') + '</ul>'
                : '') + '</div>' +
            '</div>' +
            '<div class="modal-footer">' +
              (single ? '' : '<button type="button" class="btn btn-secondary" data-qta-cancel>' + esc(opts.cancel || 'Anulo') + '</button>') +
              '<button type="button" class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" data-qta-ok>' + esc(opts.confirm || 'Po, vazhdo') + '</button>' +
            '</div>' +
          '</div></div>' +
        '</div>';
      var modalEl = wrap.firstChild;
      if (under) modalEl.classList.add('is-stacked');
      document.body.appendChild(modalEl);
      var modal = new window.bootstrap.Modal(modalEl);
      var answered = false;
      modalEl.querySelector('[data-qta-ok]').addEventListener('click', function () { answered = true; modal.hide(); resolve(true); });
      var cancelBtn = modalEl.querySelector('[data-qta-cancel]');
      if (cancelBtn) cancelBtn.addEventListener('click', function () { modal.hide(); });
      modalEl.addEventListener('shown.bs.modal', function () { (cancelBtn || modalEl.querySelector('[data-qta-ok]')).focus(); });
      modalEl.addEventListener('hidden.bs.modal', function () {
        /* Faqja e çoi vetë fokusin gjetiu ndërsa dialogu mbyllej (p.sh. te fusha
           e temës tjetër pas fshirjes): fokusi mbetet aty, nuk kthehet mbrapsht. */
        var kept = document.activeElement;
        if (!kept || kept === document.body || modalEl.contains(kept)) kept = null;
        if (!answered) resolve(false);
        modal.dispose();
        modalEl.remove();
        /* Dialogu poshtë është ende i hapur: faqja mbetet e bllokuar dhe
           tastiera mbetet brenda tij. */
        if (under && under.classList.contains('show')) {
          document.body.classList.add('modal-open');
          var below = window.bootstrap.Modal.getInstance(under);
          if (below && below._focustrap) {
            try { below._focustrap.deactivate(); below._focustrap.activate(); } catch (e) { /* vazhdon pa kurth fokusi */ }
          }
        }
        if (kept) {
          /* Kurthi i dialogut poshtë mund ta ketë marrë fokusin: i kthehet elementit të zgjedhur. */
          if (document.activeElement !== kept && document.contains(kept)) {
            try { kept.focus({ preventScroll: true }); } catch (e) { /* mbetet ku është */ }
          }
        } else if (returnTo && document.contains(returnTo) && typeof returnTo.focus === 'function') {
          try { returnTo.focus({ preventScroll: true }); } catch (e) { /* kontrolli s'merr dot fokus */ }
        } else if (returnTo && !document.querySelector('.modal.show')) {
          /* Butoni u hoq ndërkohë (p.sh. rreshti doli nga lista pas ruajtjes):
             fokusi shkon te titulli i listës, jo në fillim të faqes. */
          var fallback = document.querySelector('[data-live-focus]') || document.getElementById('main');
          if (fallback) { try { fallback.focus({ preventScroll: true }); } catch (e) { /* pa fokus */ } }
        }
      });
      modal.show();
      if (under) {
        var drops = document.querySelectorAll('.modal-backdrop');
        if (drops.length) drops[drops.length - 1].classList.add('is-stacked');
      }
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
      /* Pa HTTPS shfletuesi nuk e jep clipboard-in; atëherë kopjojmë me mënyrën e vjetër. */
      var fallback = function () {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        if (ok) done(); else window.qtaToast('Shfletuesi nuk lejoi kopjimin. Përzgjidhe tekstin dhe shtyp Ctrl+C.', 'warning');
      };
      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, fallback);
      } else {
        fallback();
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

  /* Datat: <input data-dmy> formatohet si dd.mm.vvvv ndërsa shkruhet. */
  document.addEventListener('input', function (event) {
    var el = event.target;
    if (!el || !el.matches || !el.matches('input[data-dmy]')) return;
    var digits = el.value.replace(/\D/g, '').slice(0, 8);
    var out = digits.slice(0, 2);
    if (digits.length > 2) out += '.' + digits.slice(2, 4);
    if (digits.length > 4) out += '.' + digits.slice(4, 8);
    if (el.value !== out) el.value = out;
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches || !form.matches('form[data-loading]') || event.defaultPrevented) return;
    var btn = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
    if (btn) { btn.classList.add('is-loading'); btn.setAttribute('aria-busy', 'true'); }
  });

  /* Kodet QR: <div data-qr="URL" data-qr-size="200">. Libraria (qrcodejs)
     ngarkohet vetëm nga faqet që e kanë nevojë, prandaj presim DOMContentLoaded. */
  function renderQr(el) {
    if (!window.QRCode || !el) return false;
    var text = el.getAttribute('data-qr');
    el.innerHTML = '';
    if (!text) return false;
    var size = parseInt(el.getAttribute('data-qr-size') || '200', 10);
    new window.QRCode(el, {
      text: text,
      width: size,
      height: size,
      colorDark: '#1f1e1b',
      colorLight: '#ffffff',
      correctLevel: window.QRCode.CorrectLevel.M
    });
    el.removeAttribute('title');
    var img = el.querySelector('img');
    if (img) img.setAttribute('alt', el.getAttribute('data-qr-alt') || 'Kodi QR i verifikimit');
    return true;
  }
  /* Faqet që ndryshojnë kodin (p.sh. "Krijo kodin QR") e rivizatojnë me këtë. */
  window.qtaRenderQr = renderQr;

  /* Kodi QR si PNG për printim, me kufi të bardhë (skanerat e kërkojnë). */
  window.qtaQrPng = function (el, margin) {
    var source = el && el.querySelector('canvas');
    if (!source) return '';
    margin = typeof margin === 'number' ? margin : 24;
    var out = document.createElement('canvas');
    out.width = source.width + margin * 2;
    out.height = source.height + margin * 2;
    var ctx = out.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, out.width, out.height);
    ctx.drawImage(source, margin, margin);
    return out.toDataURL('image/png');
  };

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-qr]').forEach(renderQr);
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

  /* Dialog i hapur nga një dialog tjetër (p.sh. "Ndrysho kursantët" brenda një
     grupi): Bootstrap e mbyll të parin dhe hap të dytin. Kur i dyti mbyllet pa
     ruajtje, kthehemi te i pari, te butoni që e hapi. Kur ruhet dhe faqja
     ringarkohet, i pari rihapet, që puna të vazhdojë aty ku ishte. */
  (function () {
    var REOPEN_KEY = 'qtaReopenModal';
    function showModal(el) {
      if (el && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(el).show();
    }

    document.addEventListener('show.bs.modal', function (ev) {
      var el = ev.target;
      var trigger = ev.relatedTarget;
      if (!trigger || !trigger.closest) return; /* hapur nga kodi: kthimi i mëparshëm mbetet */
      var origin = trigger.closest('.modal');
      if (origin && origin !== el && origin.id) {
        el.setAttribute('data-return-to', origin.id);
        el._qtaReturnTrigger = trigger;
      } else {
        el.removeAttribute('data-return-to');
        el._qtaReturnTrigger = null;
      }
    });

    document.addEventListener('hidden.bs.modal', function (ev) {
      var el = ev.target;
      var back = el.getAttribute('data-return-to');
      if (!back) return;
      /* Një dialog tjetër e zuri vendin (p.sh. parashikimi i ndarjes): kthimi pret. */
      if (document.querySelector('.modal.show')) return;
      el.removeAttribute('data-return-to');
      var origin = document.getElementById(back);
      var trigger = el._qtaReturnTrigger;
      el._qtaReturnTrigger = null;
      if (!origin) return;
      if (trigger) {
        origin.addEventListener('shown.bs.modal', function () {
          if (document.contains(trigger)) {
            try { trigger.focus({ preventScroll: true }); } catch (e) { /* mbetet te dialogu */ }
          }
        }, { once: true });
      }
      showModal(origin);
    });

    /* Dialog i hapur nga kodi me butonin si relatedTarget: kur mbyllet pa u
       ruajtur, fokusi kthehet te butoni që e hapi, si te data-bs-toggle.
       Nëse faqja e ka çuar fokusin gjetiu (p.sh. te rreshti i ri), nuk preket. */
    document.addEventListener('show.bs.modal', function (ev) {
      var t = ev.relatedTarget;
      ev.target._qtaOpener = t && t.focus && t.closest && !t.closest('.modal') ? t : null;
    });
    document.addEventListener('hidden.bs.modal', function (ev) {
      var el = ev.target;
      var opener = el._qtaOpener;
      el._qtaOpener = null;
      if (!opener) return;
      setTimeout(function () {
        var active = document.activeElement;
        if (document.querySelector('.modal.show')) return;
        if (active && active !== document.body && !el.contains(active)) return;
        if (!document.contains(opener) || !opener.getClientRects().length) return;
        try { opener.focus({ preventScroll: true }); } catch (e) { /* mbetet ku është */ }
      }, 30);
    });

    window.qtaReopenAfterReload = function (el) {
      var host = el && el.closest ? el.closest('.modal[data-return-to]') : null;
      if (!host) return;
      try { sessionStorage.setItem(REOPEN_KEY, host.getAttribute('data-return-to')); } catch (e) { /* pa kujtesë */ }
    };

    /* Ruajtja me formular të zakonshëm: faqja ringarkohet pas saj. */
    document.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (ev.defaultPrevented || !form || form.getAttribute('target') === '_blank') return;
      window.qtaReopenAfterReload(form);
    });

    document.addEventListener('DOMContentLoaded', function () {
      var id = null;
      try { id = sessionStorage.getItem(REOPEN_KEY); sessionStorage.removeItem(REOPEN_KEY); } catch (e) { id = null; }
      if (id && !document.querySelector('.modal[data-open-on-load]')) showModal(document.getElementById(id));
    });
  })();

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
                   'aria-autocomplete="list" placeholder="Shkruaj emër, AMZË, telefon, grup ose kurs…" aria-label="Kërko në regjistër">' +
            '<button class="pal-close" type="button" aria-label="Mbyll kërkimin">Esc</button>' +
          '</div>' +
          '<div class="pal-types" role="group" aria-label="Kërko vetëm te">' +
            '<button class="pal-type is-on" type="button" data-type="" aria-pressed="true">Të gjitha</button>' +
            '<button class="pal-type" type="button" data-type="student" aria-pressed="false">Kursantë</button>' +
            '<button class="pal-type" type="button" data-type="group" aria-pressed="false">Grupe</button>' +
            '<button class="pal-type" type="button" data-type="course" aria-pressed="false">Kurse</button>' +
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
      if (/^groups|^lesson_group|^calendar/.test(page)) return 'group';
      if (page === 'courses.php' || page === 'course.php') return 'course';
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
          '<b>Kërko në të gjithë regjistrin</b><span>Shkruaj të paktën dy shkronja: emër, atësi, numër amze, telefon, kod ose emër kursi (edhe një modul ose temë), numër grupi.</span></div>';
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
