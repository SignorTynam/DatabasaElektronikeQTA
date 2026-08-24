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
