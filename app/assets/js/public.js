/* ============================================================================
   QTA · PROTOKOLL — sjellja e faqeve publike.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.documentElement;
  var THEME_KEY = 'qta_theme';

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

  /* --------------------------------------------------------- 2. Klikimet */
  document.addEventListener('click', function (event) {
    var themeBtn = event.target.closest('[data-theme-toggle]');
    if (themeBtn) {
      event.preventDefault();
      setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
      return;
    }

    var mast = event.target.closest('[data-mast-toggle]');
    if (mast) {
      event.preventDefault();
      var nav = document.getElementById('mastNav');
      if (nav) {
        var open = nav.classList.toggle('is-open');
        mast.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      return;
    }

    var top = event.target.closest('[data-back-top]');
    if (top) {
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    /* Mbyll menunë kur klikohet një lidhje brenda saj. */
    if (event.target.closest('.masthead-nav a')) {
      var open2 = document.querySelector('.masthead-nav.is-open');
      if (open2) open2.classList.remove('is-open');
    }
  });

  /* ------------------------------------------------------- 3. Ankorat */
  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (event) {
      var target = anchor.getAttribute('href');
      if (!target || target === '#') return;
      var el = document.querySelector(target);
      if (!el) return;
      event.preventDefault();
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  /* ------------------------------------------------------ 4. Kthimi lart */
  var toTop = document.querySelector('[data-back-top]');
  if (toTop) {
    var onScroll = function () { toTop.classList.toggle('is-on', window.scrollY > 420); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* -------------------------------------------------------- 5. Njoftimet */
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
    el.className = 'toast toast-' + variant;
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
