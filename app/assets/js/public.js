(function () {
  'use strict';

  var root = document.documentElement;

  function currentTheme() {
    var stored = localStorage.getItem('qta_theme');
    if (stored === 'dark' || stored === 'light') {
      return stored;
    }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function updateThemeButtons(theme) {
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      var isDark = theme === 'dark';
      button.setAttribute('aria-label', isDark ? 'Aktivizo modalitetin e çelët' : 'Aktivizo modalitetin e errët');
      button.innerHTML = isDark
        ? '<i class="bi bi-sun"></i><span>Modalitet i çelët</span>'
        : '<i class="bi bi-moon-stars"></i><span>Modalitet i errët</span>';
    });
  }

  function setTheme(theme) {
    root.setAttribute('data-theme', theme);
    localStorage.setItem('qta_theme', theme);
    updateThemeButtons(theme);
  }

  setTheme(currentTheme());

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-theme-toggle]');
    if (!toggle) {
      return;
    }
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    setTheme(next);
  });

  document.querySelectorAll('.qta-navbar .nav-link, .qta-navbar .btn').forEach(function (link) {
    link.addEventListener('click', function () {
      var collapse = document.querySelector('#qtaPublicNav.show');
      if (collapse && window.bootstrap) {
        window.bootstrap.Collapse.getOrCreateInstance(collapse).hide();
      }
    });
  });

  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (event) {
      var target = anchor.getAttribute('href');
      if (!target || target === '#') {
        return;
      }
      var el = document.querySelector(target);
      if (!el) {
        return;
      }
      event.preventDefault();
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  var backTop = document.querySelector('[data-back-top]');
  if (backTop) {
    var onScroll = function () {
      backTop.classList.toggle('is-visible', window.scrollY > 360);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    backTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    onScroll();
  }

  if (window.AOS) {
    window.AOS.init({
      duration: 620,
      easing: 'ease-out-cubic',
      once: true,
      disable: function () {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches || window.innerWidth < 1200;
      }
    });
  }

  if (window.Swiper) {
    document.querySelectorAll('.qta-swiper').forEach(function (el) {
      new window.Swiper(el, {
        slidesPerView: 1,
        spaceBetween: 18,
        loop: true,
        pagination: {
          el: el.querySelector('.swiper-pagination'),
          clickable: true
        },
        navigation: {
          nextEl: el.querySelector('.swiper-button-next'),
          prevEl: el.querySelector('.swiper-button-prev')
        },
        breakpoints: {
          768: { slidesPerView: 2 },
          1200: { slidesPerView: 3 }
        }
      });
    });
  }

  window.qtaToast = function (message, variant) {
    variant = variant || 'primary';
    var wrap = document.querySelector('[data-toast-area]');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
      wrap.style.zIndex = '1090';
      wrap.setAttribute('data-toast-area', '');
      document.body.appendChild(wrap);
    }

    var toast = document.createElement('div');
    toast.className = 'toast align-items-center text-bg-' + variant + ' border-0';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.innerHTML = '<div class="d-flex"><div class="toast-body">' + message + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Mbyll"></button></div>';
    wrap.appendChild(toast);

    var instance = window.bootstrap ? new window.bootstrap.Toast(toast, { delay: 3200 }) : null;
    if (instance) {
      instance.show();
      toast.addEventListener('hidden.bs.toast', function () { toast.remove(); });
    }
  };
})();
