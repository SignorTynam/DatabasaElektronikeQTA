/* ============================================================================
   QTA App Shell — sjellja e përbashkët e faqeve të brendshme.
   Nuk prek asnjë endpoint; vetëm shtresa e ndërfaqes.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.documentElement;
  var THEME_KEY = 'qta_theme';
  var DENSITY_KEY = 'qta_density';

  /* ------------------------------------------------------------ 1. Tema */
  function resolveTheme() {
    var stored = localStorage.getItem(THEME_KEY);
    if (stored === 'dark' || stored === 'light') return stored;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function paintThemeToggles(theme) {
    var isDark = theme === 'dark';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-label', isDark ? 'Aktivizo modalitetin e çelët' : 'Aktivizo modalitetin e errët');
      btn.setAttribute('title', isDark ? 'Modalitet i çelët' : 'Modalitet i errët');
      var icon = btn.querySelector('i');
      if (icon) icon.className = isDark ? 'bi bi-sun' : 'bi bi-moon-stars';
      var label = btn.querySelector('[data-theme-label]');
      if (label) label.textContent = isDark ? 'Modalitet i çelët' : 'Modalitet i errët';
    });
  }

  function setTheme(theme) {
    root.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);
    paintThemeToggles(theme);
  }

  setTheme(resolveTheme());

  /* -------------------------------------------------- 2. Densiteti tabelave */
  function setDensity(mode) {
    document.body.classList.toggle('density-compact', mode === 'compact');
    localStorage.setItem(DENSITY_KEY, mode);
    document.querySelectorAll('[data-density-toggle]').forEach(function (btn) {
      var compact = mode === 'compact';
      btn.setAttribute('aria-pressed', compact ? 'true' : 'false');
      btn.setAttribute('title', compact ? 'Densitet i gjerë' : 'Densitet kompakt');
      var icon = btn.querySelector('i');
      if (icon) icon.className = compact ? 'bi bi-arrows-expand' : 'bi bi-arrows-collapse';
    });
  }

  setDensity(localStorage.getItem(DENSITY_KEY) === 'compact' ? 'compact' : 'cozy');

  /* ------------------------------------------------------ 3. Klikimet globale */
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
      setDensity(document.body.classList.contains('density-compact') ? 'cozy' : 'compact');
      return;
    }

    var topBtn = event.target.closest('[data-back-top]');
    if (topBtn) {
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  });

  /* ---------------------------------------------------- 4. Shkurtore tastiere */
  document.addEventListener('keydown', function (event) {
    var tag = (event.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable;

    // "/" fokuson kërkimin e faqes
    if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      var search = document.querySelector('[data-app-search] input, .app-search input');
      if (search) {
        event.preventDefault();
        search.focus();
        search.select();
      }
      return;
    }

    // Escape pastron kërkimin e shpejtë të tabelës
    if (event.key === 'Escape' && event.target.matches('[data-table-filter]')) {
      event.target.value = '';
      event.target.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });

  /* ------------------------------------------ 5. Filtrim i shpejtë i tabelës */
  document.querySelectorAll('[data-table-filter]').forEach(function (input) {
    var targetSel = input.getAttribute('data-table-filter');
    var table = targetSel ? document.querySelector(targetSel) : input.closest('.card, .table-shell, form, body').querySelector('table');
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

  /* ------------------------------------------------- 6. Kthimi në krye */
  var backTop = document.querySelector('[data-back-top]');
  if (backTop) {
    var onScroll = function () {
      backTop.classList.toggle('is-visible', window.scrollY > 420);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ----------------------------------------- 7. Mbyll menunë mobile pas klikimit */
  document.querySelectorAll('.app-nav .nav-link:not(.dropdown-toggle), .app-nav .dropdown-item').forEach(function (link) {
    link.addEventListener('click', function () {
      var open = document.querySelector('.app-nav .navbar-collapse.show');
      if (open && window.bootstrap) {
        window.bootstrap.Collapse.getOrCreateInstance(open).hide();
      }
    });
  });

  /* --------------------------------------------- 8. Tooltip-et deklarative */
  if (window.bootstrap && window.bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new window.bootstrap.Tooltip(el);
    });
  }

  /* ------------------------------------------------------------ 9. Toast-et */
  window.qtaToast = function (message, variant, title) {
    variant = variant || 'info';
    var area = document.querySelector('[data-toast-area]');
    if (!area) {
      area = document.createElement('div');
      area.className = 'toast-container position-fixed bottom-0 start-0 p-3';
      area.style.zIndex = 'var(--qta-z-toast, 1090)';
      area.setAttribute('data-toast-area', '');
      document.body.appendChild(area);
    }

    var icons = {
      success: 'bi-check-circle',
      danger: 'bi-exclamation-octagon',
      warning: 'bi-exclamation-triangle',
      info: 'bi-info-circle'
    };
    var titles = {
      success: 'Sukses',
      danger: 'Gabim',
      warning: 'Kujdes',
      info: 'Njoftim'
    };

    var el = document.createElement('div');
    el.className = 'toast qta-toast toast-' + variant;
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML =
      '<div class="toast-header">' +
        '<i class="bi ' + (icons[variant] || icons.info) + ' me-2"></i>' +
        '<strong class="me-auto">' + (title || titles[variant] || titles.info) + '</strong>' +
        '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button>' +
      '</div>' +
      '<div class="toast-body"></div>';
    el.querySelector('.toast-body').textContent = message;
    area.appendChild(el);

    if (window.bootstrap) {
      var t = new window.bootstrap.Toast(el, { delay: 3600 });
      t.show();
      el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    }
  };
})();
