/* ============================================================================
   QTA · PROTOKOLL — sjellja e panelit.
   Vetëm shtresa e ndërfaqes; asnjë endpoint nuk preket.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.documentElement;
  var THEME_KEY = 'qta_theme';
  var DENSITY_KEY = 'qta_density';

  /* ------------------------------------------------------------- 1. Pamja */
  function resolveTheme() {
    var stored = localStorage.getItem(THEME_KEY);
    if (stored === 'dark' || stored === 'light') return stored;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function paintThemeButtons(theme) {
    var archive = theme === 'dark';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('title', archive ? 'Fleta origjinale' : 'Kopja e arkivit');
      btn.setAttribute('aria-label', archive ? 'Kalo te fleta origjinale' : 'Kalo te kopja e arkivit');
      var icon = btn.querySelector('i');
      if (icon) icon.className = archive ? 'bi bi-sun' : 'bi bi-circle-half';
      var label = btn.querySelector('[data-theme-label]');
      if (label) label.textContent = archive ? 'Fleta origjinale' : 'Kopja e arkivit';
    });
  }

  function setTheme(theme) {
    root.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);
    paintThemeButtons(theme);
  }

  setTheme(resolveTheme());

  /* --------------------------------------------------------- 2. Densiteti */
  function setDensity(mode) {
    document.body.classList.toggle('dense', mode === 'dense');
    localStorage.setItem(DENSITY_KEY, mode);
    document.querySelectorAll('[data-density-toggle]').forEach(function (btn) {
      var dense = mode === 'dense';
      btn.setAttribute('aria-pressed', dense ? 'true' : 'false');
      btn.setAttribute('title', dense ? 'Rreshta të gjerë' : 'Rreshta të ngjeshur');
    });
  }

  setDensity(localStorage.getItem(DENSITY_KEY) === 'dense' ? 'dense' : 'normal');

  /* ------------------------------------------------------------ 3. Klikimet */
  document.addEventListener('click', function (event) {
    var themeBtn = event.target.closest('[data-theme-toggle]');
    if (themeBtn) {
      event.preventDefault();
      setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
      return;
    }

    var densityBtn = event.target.closest('[data-density-toggle]');
    if (densityBtn) {
      event.preventDefault();
      setDensity(document.body.classList.contains('dense') ? 'normal' : 'dense');
      return;
    }

    var topBtn = event.target.closest('[data-back-top]');
    if (topBtn) {
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    /* Menuja kryesore në celular */
    var navBtn = event.target.closest('[data-nav-toggle]');
    if (navBtn) {
      event.preventDefault();
      var nav = document.getElementById('appNav');
      if (nav) {
        var open = nav.classList.toggle('is-open');
        navBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      return;
    }

    /* Nënmenutë: në celular hapen me klikim, në desktop me hover/fokus. */
    var subBtn = event.target.closest('[data-sub-toggle]');
    if (subBtn && window.matchMedia('(max-width: 1099.98px)').matches) {
      event.preventDefault();
      var sub = document.getElementById(subBtn.getAttribute('aria-controls'));
      if (sub) {
        var shown = sub.style.display === 'block';
        sub.style.display = shown ? 'none' : 'block';
        subBtn.setAttribute('aria-expanded', shown ? 'false' : 'true');
      }
    }
  });

  /* -------------------------------------------------- 4. Shkurtore tastiere */
  document.addEventListener('keydown', function (event) {
    var tag = (event.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable;

    if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      var find = document.querySelector('[data-app-search] input, .app-find input');
      if (find) {
        event.preventDefault();
        find.focus();
        find.select();
      }
      return;
    }

    if (event.key === 'Escape') {
      var nav = document.getElementById('appNav');
      if (nav && nav.classList.contains('is-open')) {
        nav.classList.remove('is-open');
        var t = document.querySelector('[data-nav-toggle]');
        if (t) { t.setAttribute('aria-expanded', 'false'); t.focus(); }
      }
      if (event.target.matches('[data-table-filter]')) {
        event.target.value = '';
        event.target.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }
  });

  /* ---------------------------------------- 5. Filtrim i shpejtë i tabelës */
  document.querySelectorAll('[data-table-filter]').forEach(function (input) {
    var sel = input.getAttribute('data-table-filter');
    var host = input.closest('.leaf, .card, form, body');
    var table = sel ? document.querySelector(sel) : (host ? host.querySelector('table') : null);
    if (!table) return;

    var counter = document.querySelector(input.getAttribute('data-filter-count') || '');

    input.addEventListener('input', function () {
      var needle = input.value.trim().toLowerCase();
      var shown = 0;
      var rows = table.tBodies.length ? table.tBodies[0].rows : [];

      Array.prototype.forEach.call(rows, function (row) {
        if (row.hasAttribute('data-filter-skip')) return;
        var hit = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
        row.hidden = !hit;
        if (hit) shown++;
      });

      if (counter) counter.textContent = String(shown);
    });
  });

  /* ------------------------------------------------- 6. Renditja e kolonave */
  /* Gjenerike: çdo tabelë me [data-sortable] e merr. Lloji i kolonës thuhet
     te th[data-sort] — "text", "num", "date". Tabelat me trupa të shumtë
     (p.sh. grupet, ku çdo grup është një <tbody>) renditen si blloqe, që
     rreshti i fëmijëve të mos ndahet kurrë nga i prindit. */
  function cellKey(row, index, kind) {
    var cell = row.cells[index];
    if (!cell) return kind === 'text' ? '' : 0;
    var raw = (cell.textContent || '').trim();

    if (kind === 'num') {
      var n = parseFloat(raw.replace(/[^0-9.,-]/g, '').replace(/\.(?=\d{3})/g, '').replace(',', '.'));
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
    var groups = table.tBodies.length > 1
      ? Array.prototype.slice.call(table.tBodies)
      : Array.prototype.slice.call(table.tBodies[0] ? table.tBodies[0].rows : []);

    var keyed = groups.map(function (node) {
      var row = node.tagName === 'TBODY' ? node.rows[0] : node;
      return { node: node, key: cellKey(row, index, kind) };
    });

    keyed.sort(function (a, b) {
      if (a.key < b.key) return -dir;
      if (a.key > b.key) return dir;
      return 0;
    });

    if (table.tBodies.length > 1) {
      keyed.forEach(function (k) { table.appendChild(k.node); });
    } else {
      var body = table.tBodies[0];
      keyed.forEach(function (k) { body.appendChild(k.node); });
    }
  }

  document.querySelectorAll('table[data-sortable]').forEach(function (table) {
    var head = table.tHead;
    if (!head) return;

    Array.prototype.forEach.call(head.rows[0].cells, function (th, index) {
      var kind = th.getAttribute('data-sort');
      if (!kind || kind === 'none') return;

      th.setAttribute('role', 'button');
      th.setAttribute('tabindex', '0');

      var activate = function () {
        var asc = !th.classList.contains('is-asc');

        Array.prototype.forEach.call(head.rows[0].cells, function (other) {
          other.classList.remove('is-asc', 'is-desc');
          other.removeAttribute('aria-sort');
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

  /* ------------------------------------------------------ 6. Kthimi në krye */
  var toTop = document.querySelector('[data-back-top]');
  if (toTop) {
    var onScroll = function () { toTop.classList.toggle('is-on', window.scrollY > 420); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ------------------------------------------- 7. Mbyll menunë pas klikimit */
  document.querySelectorAll('.app-nav a.app-nav-link').forEach(function (link) {
    link.addEventListener('click', function () {
      var nav = document.getElementById('appNav');
      if (nav) nav.classList.remove('is-open');
    });
  });

  /* --------------------------------------------------------- 8. Tooltip-et */
  if (window.bootstrap && window.bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new window.bootstrap.Tooltip(el);
    });
  }

  /* ------------------------------------------------------------ 9. Njoftimet */
  window.qtaToast = function (message, variant, title) {
    variant = variant || 'info';

    var area = document.querySelector('[data-toast-area]');
    if (!area) {
      area = document.createElement('div');
      area.className = 'toast-container position-fixed bottom-0 start-0 p-3';
      area.setAttribute('data-toast-area', '');
      document.body.appendChild(area);
    }

    var titles = { success: 'Regjistruar', danger: 'E papranuar', warning: 'Kujdes', info: 'Shënim' };

    var el = document.createElement('div');
    el.className = 'toast qta-toast toast-' + variant;
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML =
      '<div class="toast-header"><strong class="me-auto"></strong>' +
      '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button></div>' +
      '<div class="toast-body"></div>';
    el.querySelector('strong').textContent = title || titles[variant] || titles.info;
    el.querySelector('.toast-body').textContent = message;
    area.appendChild(el);

    if (window.bootstrap) {
      var t = new window.bootstrap.Toast(el, { delay: 3600 });
      t.show();
      el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    }
  };
})();
